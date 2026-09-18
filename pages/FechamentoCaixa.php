<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/validacao.php';
require_once __DIR__ . '/../includes/pagamento.php';
require_once __DIR__ . '/../includes/despesa.php';
require_once __DIR__ . '/../Conexao.php';
require_once __DIR__ . '/../includes/configuracao.php';
require_once __DIR__ . '/../includes/header.php';

/*
 * Conferência do caixa de um dia.
 *
 * Os dados já existiam espalhados entre vendas e relatórios; o que faltava
 * era a tela que junta tudo do dia num lugar só, para comparar com o
 * dinheiro na gaveta antes de fechar.
 *
 * Tela só de leitura — não grava nada.
 */

$dia = dataValida($_GET['dia'] ?? null, date('Y-m-d'));

// Limite superior dos filtros por dia: comparar created_at direto, e não
// DATE(created_at), é o que deixa o MySQL usar o índice (migração 005)
$diaSeguinte = date('Y-m-d', strtotime($dia . ' +1 day'));

/* VENDAS DO DIA, POR FORMA DE PAGAMENTO */

$stmtFormas = $conn->prepare("
    SELECT forma_pagamento, COUNT(*) AS vendas, COALESCE(SUM(total), 0) AS valor
    FROM vendas
    WHERE status = 'ativa' AND created_at >= :dia AND created_at < :dia_seguinte
    GROUP BY forma_pagamento
    ORDER BY valor DESC
");
$stmtFormas->execute([':dia' => $dia, ':dia_seguinte' => $diaSeguinte]);
$formas = $stmtFormas->fetchAll(PDO::FETCH_ASSOC);

$totalRecebido = 0.0;
$totalVendas = 0;
$recebidoEmDinheiro = 0.0;

foreach ($formas as $f) {
    $totalRecebido += (float) $f['valor'];
    $totalVendas += (int) $f['vendas'];

    // O caixa físico só tem o que entrou em dinheiro; o resto cai na conta
    if ($f['forma_pagamento'] === 'dinheiro') {
        $recebidoEmDinheiro = (float) $f['valor'];
    }
}

/* DESCONTOS E CANCELAMENTOS DO DIA */

$resumoDia = $conn->prepare("
    SELECT COALESCE(SUM(desconto), 0) AS descontos,
           SUM(CASE WHEN status = 'cancelada' THEN 1 ELSE 0 END) AS canceladas
    FROM vendas
    WHERE created_at >= :dia AND created_at < :dia_seguinte
");
$resumoDia->execute([':dia' => $dia, ':dia_seguinte' => $diaSeguinte]);
$resumo = $resumoDia->fetch(PDO::FETCH_ASSOC);

/* CUSTO E LUCRO DO DIA */

$stmtCusto = $conn->prepare("
    SELECT COALESCE(SUM(vp.quantidade * vp.custo_unitario), 0)
    FROM vendas_produtos vp
    JOIN vendas v ON v.id = vp.venda_id
    WHERE v.status = 'ativa' AND v.created_at >= :dia AND v.created_at < :dia_seguinte
");
$stmtCusto->execute([':dia' => $dia, ':dia_seguinte' => $diaSeguinte]);
$custoDia = (float) $stmtCusto->fetchColumn();

$lucroDia = $totalRecebido - $custoDia;

/* DESPESAS PAGAS NO DIA — saem do caixa e precisam entrar na conferência */

$stmtDespesas = $conn->prepare("
    SELECT descricao, categoria, valor, forma_pagamento
    FROM despesas
    WHERE data_despesa = :dia
    ORDER BY valor DESC
");
$stmtDespesas->execute([':dia' => $dia]);
$despesasDia = $stmtDespesas->fetchAll(PDO::FETCH_ASSOC);

$totalDespesasDia = 0.0;
$despesasEmDinheiro = 0.0;

foreach ($despesasDia as $d) {
    $totalDespesasDia += (float) $d['valor'];

    if ($d['forma_pagamento'] === 'dinheiro') {
        $despesasEmDinheiro += (float) $d['valor'];
    }
}

// O que deveria estar na gaveta: entradas em dinheiro menos saídas em dinheiro
$saldoEsperadoEmDinheiro = $recebidoEmDinheiro - $despesasEmDinheiro;

$resultadoDia = $lucroDia - $totalDespesasDia;

/* Navegação entre dias */
$diaAnterior = date('Y-m-d', strtotime($dia . ' -1 day'));
$diaSeguinte = date('Y-m-d', strtotime($dia . ' +1 day'));
$ehHoje = $dia === date('Y-m-d');

$diasSemana = ['domingo', 'segunda-feira', 'terça-feira', 'quarta-feira',
               'quinta-feira', 'sexta-feira', 'sábado'];
$mesesNome = ['', 'janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho',
              'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro'];

$t = strtotime($dia);
$dataExtenso = $diasSemana[(int) date('w', $t)] . ', ' . date('d', $t)
    . ' de ' . $mesesNome[(int) date('n', $t)] . ' de ' . date('Y', $t);
?>

<div class="titulo-com-acao">
    <div>
        <h2>Fechamento de caixa</h2>
        <p class="periodo-atual"><?= htmlspecialchars($dataExtenso) ?></p>
    </div>

    <div class="saudacao-acoes">
        <a href="?dia=<?= urlencode($diaAnterior) ?>" class="btn btn-secondary">← Dia anterior</a>

        <?php if (!$ehHoje): ?>
            <a href="?dia=<?= urlencode($diaSeguinte) ?>" class="btn btn-secondary">Dia seguinte →</a>
            <a href="?" class="btn btn-primary">Hoje</a>
        <?php endif; ?>
    </div>
</div>

<!-- ESCOLHA DE DATA -->
<div class="card">
    <form method="GET" style="display:flex; gap:15px; align-items:flex-end; flex-wrap:wrap;">
        <div class="form-group" style="margin:0;">
            <label>Conferir outro dia</label>
            <input type="date" name="dia" value="<?= htmlspecialchars($dia) ?>">
        </div>
        <div><button type="submit" class="btn btn-primary">Ver</button></div>
    </form>
</div>

<!-- RESUMO -->
<div class="cards-grid" style="margin-top:25px;">

    <div class="card indicador">
        <h3>💰 Recebido no dia</h3>
        <span>R$ <?= number_format($totalRecebido, 2, ',', '.') ?></span>
        <small class="comparativo"><?= $totalVendas ?> venda(s)</small>
    </div>

    <div class="card indicador">
        <h3>💵 Dinheiro em caixa</h3>
        <span class="<?= $saldoEsperadoEmDinheiro < 0 ? 'margem-negativa' : '' ?>">
            R$ <?= number_format($saldoEsperadoEmDinheiro, 2, ',', '.') ?>
        </span>
        <small class="comparativo">
            <?php if ($despesasEmDinheiro > 0): ?>
                R$ <?= number_format($recebidoEmDinheiro, 2, ',', '.') ?> recebidos
                − R$ <?= number_format($despesasEmDinheiro, 2, ',', '.') ?> pagos
            <?php else: ?>
                é o que deve estar na gaveta
            <?php endif; ?>
        </small>
    </div>

    <div class="card indicador">
        <h3>📈 Lucro das vendas</h3>
        <span>R$ <?= number_format($lucroDia, 2, ',', '.') ?></span>
        <small class="comparativo">custo de R$ <?= number_format($custoDia, 2, ',', '.') ?></small>
    </div>

    <div class="card indicador">
        <h3>🧮 Resultado do dia</h3>
        <span class="<?= $resultadoDia < 0 ? 'margem-negativa' : '' ?>">
            R$ <?= number_format($resultadoDia, 2, ',', '.') ?>
        </span>
        <small class="comparativo">
            após R$ <?= number_format($totalDespesasDia, 2, ',', '.') ?> de despesas
        </small>
    </div>

</div>

<?php if ($resumo['descontos'] > 0 || $resumo['canceladas'] > 0): ?>
    <p class="periodo-atual" style="margin-top:15px;">
        <?php if ($resumo['descontos'] > 0): ?>
            Foram concedidos <strong>R$ <?= number_format($resumo['descontos'], 2, ',', '.') ?></strong> em descontos.
        <?php endif; ?>
        <?php if ($resumo['canceladas'] > 0): ?>
            <strong><?= (int) $resumo['canceladas'] ?></strong> venda(s) cancelada(s) neste dia — não entram nos valores acima.
        <?php endif; ?>
    </p>
<?php endif; ?>

<!-- ENTRADAS E SAÍDAS -->
<div class="painel-duplo">

    <div class="card">
        <h3>💳 Entradas por forma de pagamento</h3>

        <?php if (empty($formas)): ?>
            <p style="color: var(--text-gray);">Nenhuma venda neste dia.</p>
        <?php else: ?>
            <ul class="lista-painel">
                <?php foreach ($formas as $f): ?>
                    <li>
                        <span class="lista-nome">
                            <?= htmlspecialchars(nomeFormaPagamento($f['forma_pagamento'])) ?>
                            <small><?= (int) $f['vendas'] ?> venda(s)</small>
                        </span>
                        <strong class="lista-valor">R$ <?= number_format($f['valor'], 2, ',', '.') ?></strong>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>

    <div class="card">
        <div class="grafico-topo">
            <h3>💸 Saídas do dia</h3>
            <a href="Despesas.php" class="ver-todas">Lançar despesa</a>
        </div>

        <?php if (empty($despesasDia)): ?>
            <p style="color: var(--text-gray);">Nenhuma despesa lançada neste dia.</p>
        <?php else: ?>
            <ul class="lista-painel">
                <?php foreach ($despesasDia as $d): ?>
                    <li>
                        <span class="lista-nome">
                            <?= htmlspecialchars($d['descricao']) ?>
                            <small>
                                <?= htmlspecialchars(nomeCategoriaDespesa($d['categoria'])) ?> ·
                                <?= htmlspecialchars(nomeFormaPagamento($d['forma_pagamento'])) ?>
                            </small>
                        </span>
                        <strong class="lista-valor">R$ <?= number_format($d['valor'], 2, ',', '.') ?></strong>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>

</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
