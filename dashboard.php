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
      AND created_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')
      AND created_at < DATE_FORMAT(CURDATE(), '%Y-%m-01') + INTERVAL 1 MONTH
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
      AND v.created_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')
      AND v.created_at < DATE_FORMAT(CURDATE(), '%Y-%m-01') + INTERVAL 1 MONTH
")->fetchColumn();

$lucroMes = $faturamentoMes - $custoMes;
$margemMes = $faturamentoMes > 0 ? ($lucroMes / $faturamentoMes) * 100 : 0;
$ticketMedioMes = $vendasMes > 0 ? $faturamentoMes / $vendasMes : 0;

/*
 * Despesas do mês e resultado real.
 *
 * O lucro acima é só margem de produto. Sem descontar as despesas, esta
 * tela mostraria um número e o relatório outro para o mesmo mês — e a
 * diferença é justamente o que decide se o mês fechou no azul.
 */
$despesasMes = (float) $conn->query("
    SELECT COALESCE(SUM(valor), 0)
    FROM despesas
    WHERE data_despesa >= DATE_FORMAT(CURDATE(), '%Y-%m-01')
      AND data_despesa < DATE_FORMAT(CURDATE(), '%Y-%m-01') + INTERVAL 1 MONTH
")->fetchColumn();

$resultadoMes = $lucroMes - $despesasMes;

// Hoje
$hoje = $conn->query("
    SELECT COUNT(*) AS vendas, COALESCE(SUM(total), 0) AS faturamento
    FROM vendas
    WHERE status = 'ativa' AND created_at >= CURDATE() AND created_at < CURDATE() + INTERVAL 1 DAY
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
 * CABEÇALHO
 *
 * Data por extenso montada à mão: strftime, que faria isso pelo locale,
 * está depreciado desde o PHP 8.1, e o locale pt_BR costuma não existir em
 * hospedagem compartilhada.
 */
$diasSemana = ['domingo', 'segunda-feira', 'terça-feira', 'quarta-feira',
               'quinta-feira', 'sexta-feira', 'sábado'];
$mesesAbrev = ['', 'jan', 'fev', 'mar', 'abr', 'mai', 'jun',
               'jul', 'ago', 'set', 'out', 'nov', 'dez'];

$dataExtenso = $diasSemana[(int) date('w')] . ', ' . date('d')
    . ' de ' . $mesesAbrev[(int) date('n')] . '. · ' . date('H:i');

$horaAtual = (int) date('G');
$saudacao = $horaAtual < 12 ? 'Bom dia' : ($horaAtual < 18 ? 'Boa tarde' : 'Boa noite');

// O sistema só guarda o usuário de login; na falta de um nome próprio,
// ele mesmo vira a saudação
$nomeUsuario = ucfirst($_SESSION['usuario'] ?? '');

if ($vendasHoje === 0) {
    $resumoDoDia = 'Nenhuma venda registrada hoje ainda.';
} else {
    $resumoDoDia = $vendasHoje . ($vendasHoje === 1 ? ' venda' : ' vendas')
        . ' hoje · R$ ' . number_format($receitaHoje, 2, ',', '.') . ' faturados.';
}

/*
 * COMPARATIVO DOS ÚLTIMOS MESES
 *
 * O número do mês corrente sozinho não diz se o negócio está melhorando.
 * Traz faturamento, lucro e despesas de cada um dos últimos seis meses,
 * para a leitura ser de tendência e não de foto.
 */
$comparativo = [];

for ($i = 5; $i >= 0; $i--) {
    $referencia = date('Y-m-01', strtotime("-$i month"));
    $primeiroDia = date('Y-m-01', strtotime($referencia));
    $ultimoDia = date('Y-m-t', strtotime($referencia));

    $stmtMes = $conn->prepare("
        SELECT COUNT(*) AS vendas, COALESCE(SUM(total), 0) AS faturamento
        FROM vendas
        WHERE status = 'ativa' AND created_at >= :inicio AND created_at < DATE_ADD(:fim, INTERVAL 1 DAY)
    ");
    $stmtMes->execute([':inicio' => $primeiroDia, ':fim' => $ultimoDia]);
    $dadosMes = $stmtMes->fetch(PDO::FETCH_ASSOC);

    $stmtCustoMes = $conn->prepare("
        SELECT COALESCE(SUM(vp.quantidade * vp.custo_unitario), 0)
        FROM vendas_produtos vp
        JOIN vendas v ON v.id = vp.venda_id
        WHERE v.status = 'ativa' AND v.created_at >= :inicio AND v.created_at < DATE_ADD(:fim, INTERVAL 1 DAY)
    ");
    $stmtCustoMes->execute([':inicio' => $primeiroDia, ':fim' => $ultimoDia]);
    $custoDoMes = (float) $stmtCustoMes->fetchColumn();

    $stmtDespesaMes = $conn->prepare("
        SELECT COALESCE(SUM(valor), 0)
        FROM despesas
        WHERE data_despesa BETWEEN :inicio AND :fim
    ");
    $stmtDespesaMes->execute([':inicio' => $primeiroDia, ':fim' => $ultimoDia]);
    $despesaDoMes = (float) $stmtDespesaMes->fetchColumn();

    $faturamentoDoMes = (float) $dadosMes['faturamento'];
    $lucroDoMes = $faturamentoDoMes - $custoDoMes;

    $comparativo[] = [
        'rotulo' => $meses[(int) date('n', strtotime($referencia))],
        'ano' => date('Y', strtotime($referencia)),
        'faturamento' => $faturamentoDoMes,
        'lucro' => $lucroDoMes,
        'despesas' => $despesaDoMes,
        'resultado' => $lucroDoMes - $despesaDoMes,
        'atual' => $i === 0,
    ];
}

// Escala das barras: o maior faturamento do período vira 100%
$maiorFaturamentoMes = max(array_column($comparativo, 'faturamento')) ?: 1;

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

<?php require __DIR__ . '/includes/aviso_comprovante.php'; ?>

<!-- Linha Cards -->
<div class="titulo-com-acao saudacao">
    <div>
        <p class="saudacao-data"><?= htmlspecialchars($dataExtenso) ?></p>
        <h2><?= htmlspecialchars($saudacao) ?>, <?= htmlspecialchars($nomeUsuario) ?>.</h2>
        <p class="saudacao-resumo"><?= htmlspecialchars($resumoDoDia) ?></p>
    </div>

    <div class="saudacao-acoes">
        <button type="button" class="btn btn-primary" onclick="abrirVendaRapida()">
            🛒 Nova venda
        </button>

        <!-- Recarrega mantendo o período escolhido no gráfico -->
        <a href="?periodo=<?= $periodo ?>" class="btn btn-secondary">
            ⟳ Atualizar
        </a>
    </div>
</div>

<?php
/*
 * O vendedor vê só o dia: os números do mês, o lucro, o resultado, o
 * gráfico e o comparativo são do administrador (includes/permissoes.php).
 */
?>
<?php if (ehAdmin()): ?>
    <p class="periodo-atual">Indicadores de <strong><?= $nomeMes ?></strong></p>
<?php endif; ?>

<div class="cards-grid">

    <!--
      Os ids abaixo são o que o atualizarCards() do funcoes.js procura depois
      de uma venda rápida. Os valores de dinheiro já saem daqui com "R$"
      porque o JS os reescreve com Intl.NumberFormat, que também traz o
      símbolo — assim o card não muda de formato entre o carregamento da
      página e a atualização.
    -->

    <?php if (ehAdmin()): ?>
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
        <h3>📈 Lucro das vendas</h3>
        <span id="cardLucroMes">R$ <?= number_format($lucroMes, 2, ',', '.') ?></span>
        <small class="comparativo">
            margem de <span id="cardMargemMes"><?= number_format($margemMes, 1, ',', '.') ?></span>%
        </small>
    </div>

    <?php /* Mesmo cálculo do Relatorios.php — as duas telas precisam dizer o mesmo */ ?>
    <div class="card indicador">
        <h3>🧮 Resultado do mês</h3>
        <span class="<?= $resultadoMes < 0 ? 'margem-negativa' : '' ?>">
            R$ <?= number_format($resultadoMes, 2, ',', '.') ?>
        </span>
        <small class="comparativo">
            <?php if ($despesasMes > 0): ?>
                após R$ <?= number_format($despesasMes, 2, ',', '.') ?> de <a href="/pages/Despesas.php">despesas</a>
            <?php else: ?>
                <a href="/pages/Despesas.php">nenhuma despesa lançada</a>
            <?php endif; ?>
        </small>
    </div>
    <?php endif; ?>

    <div class="card indicador">
        <h3>📅 Hoje</h3>
        <span id="cardReceitaHoje">R$ <?= number_format($receitaHoje, 2, ',', '.') ?></span>
        <small class="comparativo">
            <span id="cardVendasHoje"><?= $vendasHoje ?></span> venda(s)
        </small>
    </div>

</div>

<?php if (ehAdmin()): ?>
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


<!-- COMPARATIVO DOS ÚLTIMOS MESES -->
<div class="card" style="margin-top:25px;">
    <div class="grafico-topo">
        <h3>📆 Últimos meses</h3>
        <a href="/pages/Relatorios.php" class="ver-todas">Ver relatórios</a>
    </div>

    <table class="tabela-comparativo">
        <thead>
            <tr>
                <th>Mês</th>
                <th>Faturamento</th>
                <th>Lucro das vendas</th>
                <th>Despesas</th>
                <th>Resultado</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($comparativo as $m): ?>
                <tr class="<?= $m['atual'] ? 'mes-atual' : '' ?>">
                    <td>
                        <?= htmlspecialchars($m['rotulo']) ?>
                        <small style="color: var(--text-gray);">/<?= htmlspecialchars($m['ano']) ?></small>
                    </td>

                    <td>
                        R$ <?= number_format($m['faturamento'], 2, ',', '.') ?>
                        <?php /* Barra proporcional: compara os meses de relance */ ?>
                        <span class="barra-mes">
                            <span style="width: <?= round(($m['faturamento'] / $maiorFaturamentoMes) * 100) ?>%"></span>
                        </span>
                    </td>

                    <td>R$ <?= number_format($m['lucro'], 2, ',', '.') ?></td>
                    <td>R$ <?= number_format($m['despesas'], 2, ',', '.') ?></td>

                    <td class="<?= $m['resultado'] < 0 ? 'margem-negativa' : '' ?>">
                        <strong>R$ <?= number_format($m['resultado'], 2, ',', '.') ?></strong>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php endif; /* fim do bloco só do administrador: ticket, gráfico e comparativo */ ?>

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
                        <?php if (ehAdmin()): ?>
                            <a href="/pages/EntradaEstoque.php?produto=<?= (int) $p['id'] ?>" class="btn btn-secondary btn-sm">
                                Repor
                            </a>
                        <?php endif; ?>
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
                                <?= htmlspecialchars(nomeFormaPagamento($v['forma_pagamento'])) ?> ·
                                <a href="/pages/Comprovante.php?id=<?= (int) $v['id'] ?>">comprovante</a>
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

                <input type="text" id="buscaProdutoRapida" class="input busca-produto"
                    placeholder="🔎 Digite para filtrar..." autocomplete="off">

                <select name="produto_id" id="produto" class="input">
                    <?php
                    /*
                     * Produto sem estoque continua na lista, desabilitado e no
                     * fim: escondido, a pessoa procuraria e acharia que o
                     * cadastro sumiu; habilitado, só descobriria o problema
                     * depois de preencher tudo e tentar vender.
                     */
                    $produtos = $conn->query("
                        SELECT id, nome, preco, quantidade
                        FROM produtos
                        ORDER BY quantidade <= 0, nome
                    ");
                    foreach ($produtos as $p):
                        $semEstoque = $p['quantidade'] <= 0;
                    ?>
                        <option
                            value="<?= (int) $p['id'] ?>"
                            data-preco="<?= htmlspecialchars($p['preco']) ?>"
                            data-estoque="<?= (int) $p['quantidade'] ?>"
                            <?= $semEstoque ? 'disabled' : '' ?>>
                            <?= htmlspecialchars($p['nome']) ?>
                            <?= $semEstoque ? '— sem estoque' : '(Estoque: ' . (int) $p['quantidade'] . ')' ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <small class="contador-produtos" id="contadorProdutosRapida"></small>
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

            <!--
              Mesmos campos espelhados do carrinho (reais e percentual, só o de
              reais é enviado). O data-subtotal é escrito pelo atualizarValores(),
              porque aqui o subtotal muda com o produto e a quantidade.
            -->
            <div class="form-group desconto-campos" data-subtotal="0">
                <label>Desconto <small style="color: var(--text-gray);">(opcional)</small></label>

                <div class="desconto-linha">
                    <span class="desconto-prefixo">R$</span>
                    <input type="number" step="0.01" min="0"
                        name="desconto" id="descontoValor" placeholder="0,00">

                    <span class="desconto-prefixo">ou</span>
                    <input type="number" step="0.1" min="0" max="100"
                        id="descontoPercentual" placeholder="0">
                    <span class="desconto-prefixo">%</span>
                </div>
            </div>

            <div class="total-box">
                Total:
                <strong><span id="totalVenda">R$ 0,00</span></strong>
                <small class="desconto-resumo" id="totalBruto"></small>
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
