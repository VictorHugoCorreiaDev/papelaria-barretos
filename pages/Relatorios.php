<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/validacao.php';
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

$lucro = $faturamento - $custo;
$margem = $faturamento > 0 ? ($lucro / $faturamento) * 100 : 0;
$ticketMedio = $totalVendas > 0 ? $faturamento / $totalVendas : 0;

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
        <small class="comparativo">custo de R$ <?= number_format($custo, 2, ',', '.') ?></small>
    </div>

    <div class="card indicador">
        <h3>📈 Lucro</h3>
        <span>R$ <?= number_format($lucro, 2, ',', '.') ?></span>
        <small class="comparativo">margem de <?= number_format($margem, 1, ',', '.') ?>%</small>
    </div>

</div>

<p class="periodo-atual" style="margin-top:15px;">
    Os indicadores consideram apenas vendas <strong>ativas</strong>; a tabela abaixo lista também as canceladas do período.
</p>

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