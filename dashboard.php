<?php
require_once __DIR__ . '/includes/auth.php';
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

?>

<!-- Linha Cards -->
<h2>Dashboard</h2>

<p class="periodo-atual">Indicadores de <strong><?= $nomeMes ?></strong></p>

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


<!--Linha Registrar Vendas -->
<div class="card venda-box">

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
            <input type="number"
                id="quantidade"
                name="quantidade"
                class="input"
                min="1">
        </div>

        <div class="total-box">
            Total:
            <strong><span id="totalVenda">R$ 0,00</span></strong>
        </div>

        <button type="submit" class="btn btn-primary btn-full">
            Registrar Venda
        </button>

    </form>

</div>


</div>

<?php include 'includes/footer.php'; ?>