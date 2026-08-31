<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../Conexao.php';
require_once __DIR__ . '/../includes/header.php';

/*FILTRO DE STATUS */

$status = $_GET['status'] ?? 'ativa';

$where = "";
$params = [];

if ($status === 'ativa') {
    $where = "WHERE v.status = :status";
    $params[':status'] = 'ativa';
} elseif ($status === 'cancelada') {
    $where = "WHERE v.status = :status";
    $params[':status'] = 'cancelada';
} else {
    $status = 'todas';
}

/* PAGINAÇÃO */

$limit = 10;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$page = max($page, 1);
$offset = ($page - 1) * $limit;

/* TOTAL REGISTROS */
$stmtTotal = $conn->prepare("
    SELECT COUNT(*) 
    FROM vendas v
    $where
");
$stmtTotal->execute($params);
$totalRegistros = $stmtTotal->fetchColumn();
$totalPaginas = ceil($totalRegistros / $limit);

/* CORRIGIR PÁGINA SE FOR MAIOR QUE TOTAL */
if ($page > $totalPaginas && $totalPaginas > 0) {
    $page = $totalPaginas;
    $offset = ($page - 1) * $limit;
}

/* BUSCAR VENDAS */

$sql = "
    SELECT v.id, v.total, v.created_at, v.status
    FROM vendas v
    $where
    ORDER BY v.created_at DESC
    LIMIT :limit OFFSET :offset
";

$stmt = $conn->prepare($sql);

foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}

$stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);

$stmt->execute();
$vendas = $stmt->fetchAll();

/*CONTADORES */

$countAtivas = $conn->query("
    SELECT COUNT(*) FROM vendas WHERE status = 'ativa'
")->fetchColumn();

$countCanceladas = $conn->query("
    SELECT COUNT(*) FROM vendas WHERE status = 'cancelada'
")->fetchColumn();

$countTodas = $conn->query("
    SELECT COUNT(*) FROM vendas
")->fetchColumn();

/* INTERVALO EXIBIDO */
if ($totalRegistros > 0) {
    $inicio = $offset + 1;
    $fim = min($offset + $limit, $totalRegistros);
} else {
    $inicio = 0;
    $fim = 0;
}
?>

<h2>Vendas</h2>

<!-- FILTROS -->
<div style="margin-bottom:20px; display:flex; gap:10px; flex-wrap:wrap;">

    <a href="?status=ativa"
        class="btn btn-sm <?= $status == 'ativa' ? 'btn-primary' : 'btn-secondary' ?>">
        Ativas (<?= $countAtivas ?>)
    </a>

    <a href="?status=cancelada"
        class="btn btn-sm <?= $status == 'cancelada' ? 'btn-primary' : 'btn-secondary' ?>">
        Canceladas (<?= $countCanceladas ?>)
    </a>

    <a href="?status=todas"
        class="btn btn-sm <?= $status == 'todas' ? 'btn-primary' : 'btn-secondary' ?>">
        Todas (<?= $countTodas ?>)
    </a>

</div>

<div class="card">

    <!-- INFO PAGINAÇÃO -->
    <div class="info-paginacao">
        <?php if ($totalRegistros > 0): ?>
            Mostrando <strong><?= $inicio ?>–<?= $fim ?></strong>
            de <strong><?= $totalRegistros ?></strong> vendas
        <?php else: ?>
            Nenhuma venda encontrada.
        <?php endif; ?>
    </div>

    <table>
        <thead>
            <tr>
                <th>ID</th>
                <th>Total</th>
                <th>Data</th>
                <th>Ações</th>
            </tr>
        </thead>

        <tbody>
            <?php if (!empty($vendas)): ?>
                <?php foreach ($vendas as $v): ?>
                    <tr>
                        <td><?= (int) $v['id'] ?></td>

                        <td>
                            R$ <?= number_format($v['total'], 2, ',', '.') ?>
                        </td>

                        <td>
                            <?= date('d/m/Y H:i', strtotime($v['created_at'])) ?>
                        </td>

                        <td>
                            <button class="btn btn-secondary btn-sm"
                                onclick="verItens(<?= (int) $v['id'] ?>)">
                                Ver Itens
                            </button>

                            <?php if ($v['status'] === 'ativa'): ?>

                                <a href="CancelarVenda.php?id=<?= (int) $v['id'] ?>"
                                    onclick="return confirm('Tem certeza que deseja cancelar esta venda?')"
                                    class="btn btn-danger btn-sm">
                                    Cancelar
                                </a>

                                <span class="badge badge-ativa">
                                    Ativa
                                </span>

                            <?php else: ?>

                                <span class="badge badge-cancelado">
                                    Cancelada
                                </span>

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
                <a href="?status=<?= urlencode($status) ?>&page=<?= $page - 1 ?>" class="pag-btn">«</a>
            <?php endif; ?>

            <?php for ($i = 1; $i <= $totalPaginas; $i++): ?>
                <a href="?status=<?= urlencode($status) ?>&page=<?= $i ?>"
                    class="pag-btn <?= $i == $page ? 'active' : '' ?>">
                    <?= $i ?>
                </a>
            <?php endfor; ?>

            <?php if ($page < $totalPaginas): ?>
                <a href="?status=<?= urlencode($status) ?>&page=<?= $page + 1 ?>" class="pag-btn">»</a>
            <?php endif; ?>

        </div>
    <?php endif; ?>

</div>

<!-- MODAL -->
<div id="modalItens" class="modal">
    <div class="modal-content">
        <h3>Itens da Venda</h3>
        <div id="conteudoItens"></div>
        <div class="modal-footer">
            <button class="btn btn-secondary btn-sm"
                onclick="fecharModal()">
                Fechar
            </button>
        </div>
    </div>
</div>


<br>
<a href="ListarVendas.php" class="btn btn-secondary">
    ← Voltar
</a>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>