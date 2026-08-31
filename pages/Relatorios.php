<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../Conexao.php';
require_once __DIR__ . '/../includes/header.php';

/* FILTRO DE DATAS*/

/*
 * As datas vêm da query string e são reimpressas nos inputs e nos links
 * de paginação. Validar o formato aqui corta o problema na raiz: o que
 * não for uma data Y-m-d real vira a data de hoje.
 */
function dataValida($valor, $padrao)
{
    if (!is_string($valor)) {
        return $padrao;
    }

    $data = DateTime::createFromFormat('Y-m-d', $valor);

    return ($data && $data->format('Y-m-d') === $valor)
        ? $valor
        : $padrao;
}

$dataInicio = dataValida($_GET['inicio'] ?? null, date('Y-m-d'));
$dataFim = dataValida($_GET['fim'] ?? null, date('Y-m-d'));

/* RESUMO (APENAS VENDAS ATIVAS) */

$sqlResumo = "
    SELECT 
        COUNT(*) as total_vendas,
        SUM(total) as faturamento
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

$totalVendas = $resumo['total_vendas'] ?? 0;
$faturamento = $resumo['faturamento'] ?? 0;
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
$sqlLista = "
    SELECT *
    FROM vendas
    WHERE DATE(created_at) BETWEEN :inicio AND :fim
    ORDER BY created_at DESC
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

    </form>
</div>

<!-- CARDS -->
<div class="cards-grid" style="margin-top:25px;">

    <div class="card indicador">
        <h3>🛒 Vendas</h3>
        <span><?= $totalVendas ?></span>
    </div>

    <div class="card indicador">
        <h3>💰 Faturamento</h3>
        <span>R$ <?= number_format($faturamento, 2, ',', '.') ?></span>
    </div>

    <div class="card indicador">
        <h3>📈 Ticket Médio</h3>
        <span>R$ <?= number_format($ticketMedio, 2, ',', '.') ?></span>
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
                <th>Data</th>
                <th>Status</th>
            </tr>
        </thead>

        <tbody>

            <?php if (empty($vendas)): ?>
                <tr>
                    <td colspan="4" style="text-align:center; padding:30px; color: var(--text-gray);">
                        Nenhuma venda encontrada no período.
                    </td>
                </tr>
            <?php else: ?>

                <?php foreach ($vendas as $v): ?>
                    <tr>
                        <td><?= (int) $v['id'] ?></td>
                        <td>R$ <?= number_format($v['total'], 2, ',', '.') ?></td>
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