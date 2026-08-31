<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../Conexao.php';
require_once __DIR__ . '/../includes/header.php';

$busca = $_GET['busca'] ?? '';

$limit = 10;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$page = max($page, 1);

$offset = ($page - 1) * $limit;

$params = [];
$where = "";

if ($busca) {
    $where = "WHERE nome LIKE :busca";
    $params[':busca'] = "%$busca%";
}

/* TOTAL REGISTROS */
$stmtTotal = $conn->prepare("SELECT COUNT(*) FROM produtos $where");
$stmtTotal->execute($params);
$totalRegistros = $stmtTotal->fetchColumn();

$totalPaginas = ceil($totalRegistros / $limit);

/* CORRIGIR PÁGINA SE PASSAR DO LIMITE */
if ($page > $totalPaginas && $totalPaginas > 0) {
    $page = $totalPaginas;
    $offset = ($page - 1) * $limit;
}

/* BUSCAR PRODUTOS */
$sql = "SELECT * FROM produtos
        $where
        ORDER BY id DESC
        LIMIT :limit OFFSET :offset";

$stmt = $conn->prepare($sql);

foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}

$stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);

$stmt->execute();
$produtos = $stmt->fetchAll();

/* CALCULAR INTERVALO EXIBIDO */
if ($totalRegistros > 0) {
    $inicio = $offset + 1;
    $fim = min($offset + $limit, $totalRegistros);
} else {
    $inicio = 0;
    $fim = 0;
}
?>

<h2>Estoque</h2>

<div class="card">

    <!-- BUSCA -->
    <form method="GET" style="margin-bottom:20px; display:flex; gap:10px;">
        <input
            type="text"
            name="busca"
            placeholder="Buscar produto..."
            value="<?= htmlspecialchars($busca) ?>"
            style="max-width:300px;">
        <button type="submit" class="btn btn-secondary">
            Buscar
        </button>
    </form>

    <!-- INFO PAGINAÇÃO -->
    <div class="info-paginacao">
        <?php if ($totalRegistros > 0): ?>
            Mostrando <strong><?= $inicio ?>–<?= $fim ?></strong>
            de <strong><?= $totalRegistros ?></strong> produtos
        <?php else: ?>
            Nenhum produto encontrado.
        <?php endif; ?>
    </div>

    <!-- TABELA -->
    <table>
        <thead>
            <tr>
                <th>Produto</th>
                <th>Preço</th>
                <th>Quantidade</th>
                <th>Ações</th>
            </tr>
        </thead>

        <tbody>
            <?php if (!empty($produtos)): ?>
                <?php foreach ($produtos as $p): ?>
                    <tr>
                        <td><?= htmlspecialchars($p['nome']) ?></td>
                        <td>R$ <?= number_format($p['preco'], 2, ',', '.') ?></td>

                        <td>
                            <?php if ($p['quantidade'] < 0): ?>
                                <span class="badge badge-cancelado">
                                    <?= (int) $p['quantidade'] ?>
                                </span>
                            <?php else: ?>
                                <?= (int) $p['quantidade'] ?>
                            <?php endif; ?>
                        </td>

                        <td>
                            <a href="EditarProdutos.php?id=<?= (int) $p['id'] ?>"
                                class="btn btn-primary btn-sm">
                                Editar
                            </a>

                            <a href="ExcluirProdutos.php?id=<?= (int) $p['id'] ?>"
                                onclick="return confirm('Deseja excluir este produto?')"
                                class="btn btn-danger btn-sm">
                                Excluir
                            </a>
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
                <a href="?busca=<?= urlencode($busca) ?>&page=<?= $page - 1 ?>" class="pag-btn">«</a>
            <?php endif; ?>

            <?php for ($i = 1; $i <= $totalPaginas; $i++): ?>
                <a href="?busca=<?= urlencode($busca) ?>&page=<?= $i ?>"
                    class="pag-btn <?= $i == $page ? 'active' : '' ?>">
                    <?= $i ?>
                </a>
            <?php endfor; ?>

            <?php if ($page < $totalPaginas): ?>
                <a href="?busca=<?= urlencode($busca) ?>&page=<?= $page + 1 ?>" class="pag-btn">»</a>
            <?php endif; ?>

        </div>
    <?php endif; ?>

</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>