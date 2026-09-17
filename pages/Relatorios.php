<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/validacao.php';
require_once __DIR__ . '/../includes/pagamento.php';
require_once __DIR__ . '/../includes/despesa.php';
require_once __DIR__ . '/../Conexao.php';
require_once __DIR__ . '/../includes/configuracao.php';
require_once __DIR__ . '/../includes/header.php';

/* FILTRO DE DATAS*/

/*
 * As datas vêm da query string e são reimpressas nos inputs e nos links
 * de paginação. Validar o formato corta o problema na raiz: o que não for
 * uma data Y-m-d real vira a data de hoje.
 */
$dataInicio = dataValida($_GET['inicio'] ?? null, date('Y-m-d'));
$dataFim = dataValida($_GET['fim'] ?? null, date('Y-m-d'));

/* RESUMO (APENAS VENDAS ATIVAS) */

$sqlResumo = "
    SELECT
        COUNT(*) as total_vendas,
        COALESCE(SUM(total), 0) as faturamento
    FROM vendas
    WHERE status = 'ativa'
    AND DATE(created_at) BETWEEN :inicio AND :fim
";

$stmt = $conn->prepare($sqlResumo);
$stmt->execute([
    ':inicio' => $dataInicio,
    ':fim' => $dataFim
]);

$resumo = $stmt->fetch();

$totalVendas = (int) ($resumo['total_vendas'] ?? 0);
$faturamento = (float) ($resumo['faturamento'] ?? 0);

/*
 * O custo vem dos itens, com o custo_unitario congelado na venda — a
 * tabela de vendas guarda só o total faturado. Mesmo recorte do resumo
 * acima, senão lucro e faturamento falariam de conjuntos diferentes.
 */
$stmtCusto = $conn->prepare("
    SELECT COALESCE(SUM(vp.quantidade * vp.custo_unitario), 0)
    FROM vendas_produtos vp
    JOIN vendas v ON v.id = vp.venda_id
    WHERE v.status = 'ativa'
      AND DATE(v.created_at) BETWEEN :inicio AND :fim
");
$stmtCusto->execute([':inicio' => $dataInicio, ':fim' => $dataFim]);
$custo = (float) $stmtCusto->fetchColumn();

/* Descontos concedidos no período, para o card de faturamento dar contexto */
$stmtDesconto = $conn->prepare("
    SELECT COALESCE(SUM(desconto), 0)
    FROM vendas
    WHERE status = 'ativa'
      AND DATE(created_at) BETWEEN :inicio AND :fim
");
$stmtDesconto->execute([':inicio' => $dataInicio, ':fim' => $dataFim]);
$descontos = (float) $stmtDesconto->fetchColumn();

$lucro = $faturamento - $custo;
$margem = $faturamento > 0 ? ($lucro / $faturamento) * 100 : 0;
$ticketMedio = $totalVendas > 0 ? $faturamento / $totalVendas : 0;

/*
 * ENTRADAS POR FORMA DE PAGAMENTO
 *
 * Mesmo recorte do resumo. Vendas anteriores ao registro da forma de
 * pagamento têm o campo vazio e caem em "Não informada" pelo
 * nomeFormaPagamento().
 */
$stmtPagamentos = $conn->prepare("
    SELECT forma_pagamento, COUNT(*) AS vendas, COALESCE(SUM(total), 0) AS valor
    FROM vendas
    WHERE status = 'ativa'
      AND DATE(created_at) BETWEEN :inicio AND :fim
    GROUP BY forma_pagamento
    ORDER BY valor DESC
");
$stmtPagamentos->execute([':inicio' => $dataInicio, ':fim' => $dataFim]);
$pagamentos = $stmtPagamentos->fetchAll(PDO::FETCH_ASSOC);

/*
 * DESPESAS DO PERÍODO
 *
 * O lucro das vendas é só margem de produto: não desconta aluguel, energia
 * nem fornecedores. Trazendo as despesas, o relatório mostra o resultado
 * real — quanto sobrou de fato.
 */
$stmtDespesas = $conn->prepare("
    SELECT COALESCE(SUM(valor), 0)
    FROM despesas
    WHERE data_despesa BETWEEN :inicio AND :fim
");
$stmtDespesas->execute([':inicio' => $dataInicio, ':fim' => $dataFim]);
$totalDespesasPeriodo = (float) $stmtDespesas->fetchColumn();

$resultado = $lucro - $totalDespesasPeriodo;

$stmtDespesasCategoria = $conn->prepare("
    SELECT categoria, COALESCE(SUM(valor), 0) AS total
    FROM despesas
    WHERE data_despesa BETWEEN :inicio AND :fim
    GROUP BY categoria
    ORDER BY total DESC
    LIMIT 5
");
$stmtDespesasCategoria->execute([':inicio' => $dataInicio, ':fim' => $dataFim]);
$despesasPorCategoria = $stmtDespesasCategoria->fetchAll(PDO::FETCH_ASSOC);

/*
 * PRODUTOS MAIS VENDIDOS
 *
 * Ordena por unidades saídas, não por faturamento: para reposição o que
 * importa é o giro. O faturamento e o lucro de cada item aparecem ao lado
 * para dar o contexto.
 */
$stmtRanking = $conn->prepare("
    SELECT p.nome,
           SUM(vp.quantidade) AS unidades,
           SUM(vp.quantidade * vp.preco_unitario) AS faturado,
           SUM(vp.quantidade * (vp.preco_unitario - vp.custo_unitario)) AS lucrado
    FROM vendas_produtos vp
    JOIN vendas v ON v.id = vp.venda_id
    JOIN produtos p ON p.id = vp.produto_id
    WHERE v.status = 'ativa'
      AND DATE(v.created_at) BETWEEN :inicio AND :fim
    GROUP BY vp.produto_id, p.nome
    ORDER BY unidades DESC, faturado DESC
    LIMIT 10
");
$stmtRanking->execute([':inicio' => $dataInicio, ':fim' => $dataFim]);
$maisVendidos = $stmtRanking->fetchAll(PDO::FETCH_ASSOC);

/*PAGINAÇÃO */

$limit = 10;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$page = max($page, 1);
$offset = ($page - 1) * $limit;

/* TOTAL PARA PAGINAÇÃO */
$stmtTotal = $conn->prepare("
    SELECT COUNT(*)
    FROM vendas
    WHERE DATE(created_at) BETWEEN :inicio AND :fim
");
$stmtTotal->execute([
    ':inicio' => $dataInicio,
    ':fim' => $dataFim
]);

$totalRegistros = $stmtTotal->fetchColumn();
$totalPaginas = ceil($totalRegistros / $limit);

/* CORRIGIR PÁGINA */
if ($page > $totalPaginas && $totalPaginas > 0) {
    $page = $totalPaginas;
    $offset = ($page - 1) * $limit;
}

/* LISTAGEM PAGINADA */
/*
 * A listagem inclui as canceladas de propósito — a coluna Status as
 * identifica e é útil ver o que foi cancelado no período. Por isso o
 * contador de registros aqui pode ser maior que o card de vendas, que
 * conta só as ativas.
 */
$sqlLista = "
    SELECT v.*,
        COALESCE((
            SELECT SUM(vp.quantidade * vp.custo_unitario)
            FROM vendas_produtos vp
            WHERE vp.venda_id = v.id
        ), 0) AS custo
    FROM vendas v
    WHERE DATE(v.created_at) BETWEEN :inicio AND :fim
    ORDER BY v.created_at DESC
    LIMIT :limit OFFSET :offset
";

$stmtLista = $conn->prepare($sqlLista);
$stmtLista->bindValue(':inicio', $dataInicio);
$stmtLista->bindValue(':fim', $dataFim);
$stmtLista->bindValue(':limit', $limit, PDO::PARAM_INT);
$stmtLista->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmtLista->execute();

$vendas = $stmtLista->fetchAll();

/* INTERVALO EXIBIDO */
if ($totalRegistros > 0) {
    $inicioReg = $offset + 1;
    $fimReg = min($offset + $limit, $totalRegistros);
} else {
    $inicioReg = 0;
    $fimReg = 0;
}
?>

<h2>Relatórios</h2>

<!-- FILTRO -->
<div class="card">
    <form method="GET" style="display:flex; gap:20px; align-items:flex-end; flex-wrap:wrap;">

        <div class="form-group" style="margin:0;">
            <label>Data Início</label>
            <input type="date" name="inicio" value="<?= htmlspecialchars($dataInicio) ?>">
        </div>

        <div class="form-group" style="margin:0;">
            <label>Data Fim</label>
            <input type="date" name="fim" value="<?= htmlspecialchars($dataFim) ?>">
        </div>

        <div>
            <button type="submit" class="btn btn-primary">
                Filtrar
            </button>
        </div>

        <div>
            <!--
              formaction em vez de link: assim a exportação recebe as datas
              que estão nos campos agora. Um <a> levaria o período do último
              carregamento, exportando o intervalo errado para quem troca as
              datas e clica direto em Exportar.
            -->
            <button type="submit" formaction="ExportarRelatorio.php" class="btn btn-secondary">
                ⬇ Exportar CSV
            </button>
        </div>

    </form>
</div>

<!-- CARDS -->
<div class="cards-grid" style="margin-top:25px;">

    <div class="card indicador">
        <h3>🛒 Vendas</h3>
        <span><?= $totalVendas ?></span>
        <small class="comparativo">ticket médio de R$ <?= number_format($ticketMedio, 2, ',', '.') ?></small>
    </div>

    <div class="card indicador">
        <h3>💰 Faturamento</h3>
        <span>R$ <?= number_format($faturamento, 2, ',', '.') ?></span>
        <small class="comparativo">
            custo de R$ <?= number_format($custo, 2, ',', '.') ?>
            <?php if ($descontos > 0): ?>
                · R$ <?= number_format($descontos, 2, ',', '.') ?> em descontos
            <?php endif; ?>
        </small>
    </div>

    <div class="card indicador">
        <h3>📈 Lucro das vendas</h3>
        <span>R$ <?= number_format($lucro, 2, ',', '.') ?></span>
        <small class="comparativo">margem de <?= number_format($margem, 1, ',', '.') ?>%</small>
    </div>

    <div class="card indicador">
        <h3>💸 Despesas</h3>
        <span>R$ <?= number_format($totalDespesasPeriodo, 2, ',', '.') ?></span>
        <small class="comparativo"><a href="Despesas.php">lançar ou consultar</a></small>
    </div>

    <?php /* O número que importa: lucro das vendas menos as despesas do período */ ?>
    <div class="card indicador">
        <h3>🧮 Resultado do período</h3>
        <span class="<?= $resultado < 0 ? 'margem-negativa' : '' ?>">
            R$ <?= number_format($resultado, 2, ',', '.') ?>
        </span>
        <small class="comparativo">
            <?= $resultado < 0 ? 'as despesas superaram o lucro das vendas' : 'lucro das vendas menos as despesas' ?>
        </small>
    </div>

</div>

<p class="periodo-atual" style="margin-top:15px;">
    Os indicadores consideram apenas vendas <strong>ativas</strong>; a tabela abaixo lista também as canceladas do período.
</p>

<!-- FORMAS DE PAGAMENTO E PRODUTOS MAIS VENDIDOS -->
<div class="painel-duplo">

    <div class="card">
        <h3>💳 Entradas por forma de pagamento</h3>

        <?php if (empty($pagamentos)): ?>
            <p style="color: var(--text-gray);">Nenhuma venda no período.</p>
        <?php else: ?>
            <ul class="lista-painel">
                <?php foreach ($pagamentos as $fp):
                    // Participação de cada meio no total do período
                    $fatia = $faturamento > 0 ? ($fp['valor'] / $faturamento) * 100 : 0;
                ?>
                    <li>
                        <span class="lista-nome">
                            <?= htmlspecialchars(nomeFormaPagamento($fp['forma_pagamento'])) ?>
                            <small><?= (int) $fp['vendas'] ?> venda(s) · <?= number_format($fatia, 1, ',', '.') ?>% do total</small>
                        </span>
                        <strong class="lista-valor">R$ <?= number_format($fp['valor'], 2, ',', '.') ?></strong>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>

    <div class="card">
        <h3>🏆 Produtos mais vendidos</h3>

        <?php if (empty($maisVendidos)): ?>
            <p style="color: var(--text-gray);">Nenhuma venda no período.</p>
        <?php else: ?>
            <ul class="lista-painel">
                <?php foreach ($maisVendidos as $posicao => $item): ?>
                    <li>
                        <span class="lista-hora"><?= $posicao + 1 ?>º</span>
                        <span class="lista-nome">
                            <?= htmlspecialchars($item['nome']) ?>
                            <small>
                                <?= (int) $item['unidades'] ?> unidade(s) ·
                                R$ <?= number_format($item['lucrado'], 2, ',', '.') ?> de lucro
                            </small>
                        </span>
                        <strong class="lista-valor">R$ <?= number_format($item['faturado'], 2, ',', '.') ?></strong>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>

</div>

<!-- TABELA -->
<div class="card" style="margin-top:25px;">

    <h3 style="margin-bottom:15px;">📋 Detalhamento</h3>

    <div class="info-paginacao">
        <?php if ($totalRegistros > 0): ?>
            Mostrando <strong><?= $inicioReg ?>–<?= $fimReg ?></strong>
            de <strong><?= $totalRegistros ?></strong> registros
        <?php else: ?>
            Nenhuma venda encontrada no período.
        <?php endif; ?>
    </div>

    <table>
        <thead>
            <tr>
                <th>ID</th>
                <th>Total</th>
                <th>Lucro</th>
                <th>Data</th>
                <th>Status</th>
            </tr>
        </thead>

        <tbody>

            <?php if (empty($vendas)): ?>
                <tr>
                    <td colspan="5" style="text-align:center; padding:30px; color: var(--text-gray);">
                        Nenhuma venda encontrada no período.
                    </td>
                </tr>
            <?php else: ?>

                <?php foreach ($vendas as $v): ?>
                    <?php $lucroVenda = $v['total'] - $v['custo']; ?>
                    <tr>
                        <td><?= (int) $v['id'] ?></td>
                        <td>R$ <?= number_format($v['total'], 2, ',', '.') ?></td>
                        <td>
                            <?php if ($v['status'] === 'ativa'): ?>
                                R$ <?= number_format($lucroVenda, 2, ',', '.') ?>
                            <?php else: ?>
                                <span style="color: var(--text-gray);">—</span>
                            <?php endif; ?>
                        </td>
                        <td><?= date('d/m/Y H:i', strtotime($v['created_at'])) ?></td>
                        <td>
                            <?php if ($v['status'] === 'ativa'): ?>
                                <span class="badge badge-ativa">Ativa</span>
                            <?php else: ?>
                                <span class="badge badge-cancelado">Cancelada</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>

            <?php endif; ?>

        </tbody>
    </table>

    <!-- PAGINAÇÃO -->
    <?php if ($totalPaginas > 1): ?>
        <div class="paginacao">

            <?php if ($page > 1): ?>
                <a href="?inicio=<?= urlencode($dataInicio) ?>&fim=<?= urlencode($dataFim) ?>&page=<?= $page - 1 ?>" class="pag-btn">«</a>
            <?php endif; ?>

            <?php for ($i = 1; $i <= $totalPaginas; $i++): ?>
                <a href="?inicio=<?= urlencode($dataInicio) ?>&fim=<?= urlencode($dataFim) ?>&page=<?= $i ?>"
                    class="pag-btn <?= $i == $page ? 'active' : '' ?>">
                    <?= $i ?>
                </a>
            <?php endfor; ?>

            <?php if ($page < $totalPaginas): ?>
                <a href="?inicio=<?= urlencode($dataInicio) ?>&fim=<?= urlencode($dataFim) ?>&page=<?= $page + 1 ?>" class="pag-btn">»</a>
            <?php endif; ?>

        </div>
    <?php endif; ?>

</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>