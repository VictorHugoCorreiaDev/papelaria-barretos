<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/pagamento.php';
require_once __DIR__ . '/Conexao.php';
require_once __DIR__ . '/includes/configuracao.php';
require_once __DIR__ . '/includes/header.php';

/*
 * Os indicadores olham o MÊS CORRENTE, não o acumulado histórico: o que
 * interessa no dia a dia é como o mês está indo. Só vendas 'ativa' entram
 * — o mesmo filtro precisa valer no ajax_venda_rapida.php, senão os cards
 * divergem depois de uma venda rápida.
 */

// Faturamento e quantidade do mês, direto da tabela de vendas
$mes = $conn->query("
    SELECT COUNT(*) AS vendas, COALESCE(SUM(total), 0) AS faturamento
    FROM vendas
    WHERE status = 'ativa'
      AND YEAR(created_at) = YEAR(CURDATE())
      AND MONTH(created_at) = MONTH(CURDATE())
")->fetch(PDO::FETCH_ASSOC);

$vendasMes = (int) $mes['vendas'];
$faturamentoMes = (float) $mes['faturamento'];

/*
 * O custo precisa vir dos itens, não da venda: 'total' não guarda custo.
 * Usa o custo_unitario congelado na época da venda, pelo mesmo motivo do
 * preco_unitario — mudar o custo de um produto não pode reescrever o
 * lucro de vendas passadas.
 */
$custoMes = (float) $conn->query("
    SELECT COALESCE(SUM(vp.quantidade * vp.custo_unitario), 0)
    FROM vendas_produtos vp
    JOIN vendas v ON v.id = vp.venda_id
    WHERE v.status = 'ativa'
      AND YEAR(v.created_at) = YEAR(CURDATE())
      AND MONTH(v.created_at) = MONTH(CURDATE())
")->fetchColumn();

$lucroMes = $faturamentoMes - $custoMes;
$margemMes = $faturamentoMes > 0 ? ($lucroMes / $faturamentoMes) * 100 : 0;
$ticketMedioMes = $vendasMes > 0 ? $faturamentoMes / $vendasMes : 0;

// Hoje
$hoje = $conn->query("
    SELECT COUNT(*) AS vendas, COALESCE(SUM(total), 0) AS faturamento
    FROM vendas
    WHERE status = 'ativa' AND DATE(created_at) = CURDATE()
")->fetch(PDO::FETCH_ASSOC);

$vendasHoje = (int) $hoje['vendas'];
$receitaHoje = (float) $hoje['faturamento'];

// Mês anterior, para a comparação
$faturamentoMesAnterior = (float) $conn->query("
    SELECT COALESCE(SUM(total), 0)
    FROM vendas
    WHERE status = 'ativa'
      AND created_at >= DATE_FORMAT(CURDATE() - INTERVAL 1 MONTH, '%Y-%m-01')
      AND created_at <  DATE_FORMAT(CURDATE(), '%Y-%m-01')
")->fetchColumn();

$variacao = $faturamentoMesAnterior > 0
    ? (($faturamentoMes - $faturamentoMesAnterior) / $faturamentoMesAnterior) * 100
    : null;

// Nome do mês em português, para o título da seção
$meses = ['', 'janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho',
          'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro'];
$nomeMes = $meses[(int) date('n')] . ' de ' . date('Y');

/*
 * GRÁFICO DE FATURAMENTO
 *
 * O período vem da query string e só aceita os valores dos botões — vira
 * LIMIT de dias numa consulta, então não pode ser texto livre.
 */
$periodosGrafico = [7, 14, 30, 90];
$periodo = (int) ($_GET['periodo'] ?? 14);
if (!in_array($periodo, $periodosGrafico, true)) {
    $periodo = 14;
}

$stmtGrafico = $conn->prepare("
    SELECT DATE(created_at) AS dia, COALESCE(SUM(total), 0) AS faturamento
    FROM vendas
    WHERE status = 'ativa'
      AND created_at >= CURDATE() - INTERVAL :dias DAY
    GROUP BY DATE(created_at)
");
$stmtGrafico->bindValue(':dias', $periodo - 1, PDO::PARAM_INT);
$stmtGrafico->execute();

// Indexa por dia para preencher com zero as datas sem venda — sem isso o
// gráfico "pularia" os dias parados e distorceria a leitura
$porDia = [];
foreach ($stmtGrafico->fetchAll(PDO::FETCH_ASSOC) as $l) {
    $porDia[$l['dia']] = (float) $l['faturamento'];
}

$serie = [];
for ($i = $periodo - 1; $i >= 0; $i--) {
    $dia = date('Y-m-d', strtotime("-$i day"));
    $serie[] = ['dia' => $dia, 'valor' => $porDia[$dia] ?? 0.0];
}

$totalPeriodo = array_sum(array_column($serie, 'valor'));
$maiorValor = max(array_column($serie, 'valor')) ?: 1;

/*
 * RUPTURA DE ESTOQUE
 */
$estoqueMinimo = 10;

$stmtBaixo = $conn->prepare("
    SELECT id, nome, quantidade
    FROM produtos
    WHERE quantidade <= :minimo
    ORDER BY quantidade ASC, nome ASC
    LIMIT 8
");
$stmtBaixo->bindValue(':minimo', $estoqueMinimo, PDO::PARAM_INT);
$stmtBaixo->execute();
$estoqueBaixo = $stmtBaixo->fetchAll(PDO::FETCH_ASSOC);

$totalBaixo = (int) $conn->query("
    SELECT COUNT(*) FROM produtos WHERE quantidade <= $estoqueMinimo
")->fetchColumn();

/*
 * ÚLTIMAS VENDAS
 */
$ultimasVendas = $conn->query("
    SELECT v.id, v.total, v.cliente, v.forma_pagamento, v.created_at,
        (SELECT COUNT(*) FROM vendas_produtos vp WHERE vp.venda_id = v.id) AS itens
    FROM vendas v
    WHERE v.status = 'ativa'
    ORDER BY v.created_at DESC
    LIMIT 5
")->fetchAll(PDO::FETCH_ASSOC);

?>

<!-- Linha Cards -->
<div class="titulo-com-acao">
    <div>
        <h2>Dashboard</h2>
        <p class="periodo-atual">Indicadores de <strong><?= $nomeMes ?></strong></p>
    </div>

    <button type="button" class="btn btn-primary" onclick="abrirVendaRapida()">
        🛒 Nova venda
    </button>
</div>

<div class="cards-grid">

    <!--
      Os ids abaixo são o que o atualizarCards() do funcoes.js procura depois
      de uma venda rápida. Os valores de dinheiro já saem daqui com "R$"
      porque o JS os reescreve com Intl.NumberFormat, que também traz o
      símbolo — assim o card não muda de formato entre o carregamento da
      página e a atualização.
    -->

    <div class="card indicador">
        <h3>🧾 Vendas no mês</h3>
        <span id="cardVendasMes"><?= $vendasMes ?></span>
    </div>

    <div class="card indicador">
        <h3>💰 Faturamento do mês</h3>
        <span id="cardFaturamentoMes">R$ <?= number_format($faturamentoMes, 2, ',', '.') ?></span>

        <?php if ($variacao !== null): ?>
            <small class="comparativo <?= $variacao >= 0 ? 'positivo' : 'negativo' ?>">
                <?= $variacao >= 0 ? '▲' : '▼' ?>
                <?= number_format(abs($variacao), 1, ',', '.') ?>% vs. mês anterior
            </small>
        <?php endif; ?>
    </div>

    <div class="card indicador">
        <h3>📈 Lucro do mês</h3>
        <span id="cardLucroMes">R$ <?= number_format($lucroMes, 2, ',', '.') ?></span>
        <small class="comparativo">
            margem de <span id="cardMargemMes"><?= number_format($margemMes, 1, ',', '.') ?></span>%
        </small>
    </div>

    <div class="card indicador">
        <h3>📅 Hoje</h3>
        <span id="cardReceitaHoje">R$ <?= number_format($receitaHoje, 2, ',', '.') ?></span>
        <small class="comparativo">
            <span id="cardVendasHoje"><?= $vendasHoje ?></span> venda(s)
        </small>
    </div>

</div>

<p class="periodo-atual">
    Ticket médio do mês: <strong>R$ <span id="cardTicketMedioMes"><?= number_format($ticketMedioMes, 2, ',', '.') ?></span></strong>
</p>


<!-- GRÁFICO DE FATURAMENTO -->
<div class="card">

    <div class="grafico-topo">
        <h3>📊 Faturamento · últimos <?= $periodo ?> dias</h3>

        <div class="grafico-periodos">
            <?php foreach ($periodosGrafico as $p): ?>
                <a href="?periodo=<?= $p ?>" class="pag-btn <?= $p === $periodo ? 'active' : '' ?>">
                    <?= $p ?>d
                </a>
            <?php endforeach; ?>
        </div>
    </div>

    <p class="grafico-total">R$ <?= number_format($totalPeriodo, 2, ',', '.') ?></p>

    <?php
    /*
     * Gráfico em SVG gerado aqui mesmo: o projeto não usa bibliotecas
     * externas, e uma linha com pontos não justifica a primeira. O viewBox
     * faz o desenho acompanhar a largura do card.
     */
    $largura = 800;
    $altura = 180;
    $margemBaixo = 28;
    $passo = count($serie) > 1 ? $largura / (count($serie) - 1) : 0;

    $pontos = [];
    foreach ($serie as $i => $ponto) {
        $x = round($i * $passo, 2);
        $y = round(($altura - $margemBaixo) * (1 - $ponto['valor'] / $maiorValor), 2);
        $pontos[] = ['x' => $x, 'y' => $y, 'dado' => $ponto];
    }

    $linhaPontos = [];
    foreach ($pontos as $p) {
        $linhaPontos[] = $p['x'] . ',' . $p['y'];
    }
    ?>

    <svg class="grafico" viewBox="0 0 <?= $largura ?> <?= $altura ?>" preserveAspectRatio="none" role="img"
        aria-label="Faturamento diário dos últimos <?= $periodo ?> dias">

        <?php for ($l = 0; $l <= 3; $l++): $y = ($altura - $margemBaixo) * $l / 3; ?>
            <line x1="0" y1="<?= $y ?>" x2="<?= $largura ?>" y2="<?= $y ?>"
                stroke="var(--border-color)" stroke-width="1" stroke-dasharray="3 4" />
        <?php endfor; ?>

        <polyline fill="none" stroke="var(--primary)" stroke-width="2"
            stroke-linejoin="round" stroke-linecap="round"
            points="<?= implode(' ', $linhaPontos) ?>" />

        <?php foreach ($pontos as $p): ?>
            <circle cx="<?= $p['x'] ?>" cy="<?= $p['y'] ?>" r="3" fill="var(--primary)">
                <title><?= date('d/m', strtotime($p['dado']['dia'])) ?>: R$ <?= number_format($p['dado']['valor'], 2, ',', '.') ?></title>
            </circle>
        <?php endforeach; ?>
    </svg>

    <div class="grafico-datas">
        <span><?= date('d/m', strtotime($serie[0]['dia'])) ?></span>
        <span>hoje</span>
    </div>
</div>


<!-- RUPTURA DE ESTOQUE E ÚLTIMAS VENDAS -->
<div class="painel-duplo">

    <div class="card">
        <div class="grafico-topo">
            <h3>⚠️ Ruptura de estoque</h3>
            <?php if ($totalBaixo > 0): ?>
                <span class="badge badge-cancelado"><?= $totalBaixo ?> baixo(s)</span>
            <?php endif; ?>
        </div>

        <?php if (empty($estoqueBaixo)): ?>
            <p style="color: var(--text-gray);">Nenhum produto com estoque baixo.</p>
        <?php else: ?>
            <ul class="lista-painel">
                <?php foreach ($estoqueBaixo as $p): ?>
                    <li>
                        <span class="lista-nome"><?= htmlspecialchars($p['nome']) ?></span>
                        <span class="badge <?= (int) $p['quantidade'] <= 0 ? 'badge-cancelado' : 'badge-ativa' ?>">
                            <?= (int) $p['quantidade'] <= 0 ? 'Sem estoque' : 'Restam ' . (int) $p['quantidade'] ?>
                        </span>
                        <a href="/pages/EditarProdutos.php?id=<?= (int) $p['id'] ?>" class="btn btn-secondary btn-sm">
                            Repor
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>

    <div class="card">
        <div class="grafico-topo">
            <h3>🧾 Últimas vendas</h3>
            <a href="/pages/ListarVendas.php" class="ver-todas">Ver todas</a>
        </div>

        <?php if (empty($ultimasVendas)): ?>
            <p style="color: var(--text-gray);">Nenhuma venda registrada ainda.</p>
        <?php else: ?>
            <ul class="lista-painel">
                <?php foreach ($ultimasVendas as $v): ?>
                    <li>
                        <?php
                        // Só a hora para vendas de hoje; nos outros dias a hora
                        // sozinha confunde, porque a lista mistura datas
                        $quando = strtotime($v['created_at']);
                        $ehHoje = date('Y-m-d', $quando) === date('Y-m-d');
                        ?>
                        <span class="lista-hora"><?= $ehHoje ? date('H:i', $quando) : date('d/m', $quando) ?></span>
                        <span class="lista-nome">
                            <?= htmlspecialchars(nomeCliente($v['cliente'])) ?>
                            <small>
                                <?= (int) $v['itens'] ?> item(ns) ·
                                <?= htmlspecialchars(nomeFormaPagamento($v['forma_pagamento'])) ?>
                            </small>
                        </span>
                        <strong class="lista-valor">R$ <?= number_format($v['total'], 2, ',', '.') ?></strong>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>

</div>


<!-- MODAL DE VENDA RÁPIDA -->
<div id="modalVendaRapida" class="modal">
    <div class="modal-content">
        <h3>💰 Registrar Venda Rápida</h3>

        <form id="formVenda" method="POST">

            <div class="form-group">
                <label>Selecione um Produto</label>
                <select name="produto_id" id="produto" class="input">
                    <?php
                    $produtos = $conn->query("SELECT id, nome, preco, quantidade FROM produtos");
                    foreach ($produtos as $p):
                    ?>
                        <option
                            value="<?= (int) $p['id'] ?>"
                            data-preco="<?= htmlspecialchars($p['preco']) ?>"
                            data-estoque="<?= (int) $p['quantidade'] ?>">
                            <?= htmlspecialchars($p['nome']) ?> (Estoque: <?= (int) $p['quantidade'] ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="info-linha">
                Valor Unitário:
                <!-- O atualizarValores() escreve aqui com Intl.NumberFormat, que já
                     traz o "R$". O prefixo fixo duplicava o símbolo. -->
                <strong><span id="valorUnitario">R$ 0,00</span></strong>
            </div>

            <div class="form-group">
                <label>Quantidade</label>
                <input type="number" id="quantidade" name="quantidade" class="input" min="1">
            </div>

            <div class="form-group">
                <label>Cliente <small style="color: var(--text-gray);">(opcional)</small></label>
                <input type="text" name="cliente" id="clienteVenda" class="input"
                    maxlength="120" placeholder="Cliente avulso">
            </div>

            <div class="form-group">
                <label>Forma de pagamento</label>
                <select name="forma_pagamento" id="formaPagamentoVenda" class="input">
                    <option value="">Não informada</option>
                    <?php foreach (formasPagamento() as $chave => $rotulo): ?>
                        <option value="<?= htmlspecialchars($chave) ?>"><?= htmlspecialchars($rotulo) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="total-box">
                Total:
                <strong><span id="totalVenda">R$ 0,00</span></strong>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="fecharVendaRapida()">
                    Cancelar
                </button>
                <button type="submit" class="btn btn-primary">
                    Registrar Venda
                </button>
            </div>

        </form>
    </div>
</div>

<?php include 'includes/footer.php'; ?>
