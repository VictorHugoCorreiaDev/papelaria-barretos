<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../Conexao.php';
require_once __DIR__ . '/../includes/configuracao.php';
require_once __DIR__ . '/../includes/header.php';

$busca = $_GET['busca'] ?? '';

$limit = 10;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$page = max($page, 1);

$offset = ($page - 1) * $limit;

/*
 * Filtro de produtos sem custo: com mais de cem itens cadastrados, sem um
 * atalho não há como saber quais ainda faltam preencher — e produto sem
 * custo entra no lucro como se fosse margem integral.
 */
$semCusto = isset($_GET['semcusto']) && $_GET['semcusto'] === '1';

/*
 * Filtro de estoque negativo: saldo abaixo de zero é sempre erro de
 * registro — venda lançada com quantidade maior que a disponível, o que a
 * validação de estoque passou a impedir. Os saldos anteriores a essa
 * correção continuam errados e precisam de conferência física.
 */
$negativo = isset($_GET['negativo']) && $_GET['negativo'] === '1';

$params = [];
$condicoes = [];

if ($busca) {
    $condicoes[] = "nome LIKE :busca";
    $params[':busca'] = "%$busca%";
}

if ($semCusto) {
    $condicoes[] = "custo <= 0";
}

if ($negativo) {
    $condicoes[] = "quantidade < 0";
}

$where = $condicoes ? 'WHERE ' . implode(' AND ', $condicoes) : '';

// Contagens totais, independentes dos filtros aplicados na tela
$totalSemCusto = (int) $conn->query("SELECT COUNT(*) FROM produtos WHERE custo <= 0")->fetchColumn();
$totalNegativo = (int) $conn->query("SELECT COUNT(*) FROM produtos WHERE quantidade < 0")->fetchColumn();

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

<?php if ($totalNegativo > 0): ?>
    <div class="aviso-custo aviso-erro">
        <strong><?= $totalNegativo ?> produto<?= $totalNegativo == 1 ? '' : 's' ?> com estoque negativo.</strong>
        Saldo abaixo de zero é resto de vendas registradas antes da validação de
        estoque — o sistema não permite mais que isso aconteça, mas os saldos
        antigos continuam errados. Confira a quantidade física e corrija pela
        edição do produto.

        <?php if (!$negativo): ?>
            <a href="?negativo=1">Ver só esses produtos</a>
        <?php else: ?>
            <a href="?">Ver todos os produtos</a>
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php if ($totalSemCusto > 0): ?>
    <div class="aviso-custo">
        <strong><?= $totalSemCusto ?> produto<?= $totalSemCusto == 1 ? '' : 's' ?> sem custo de compra.</strong>
        Enquanto o custo estiver zerado, o lucro no dashboard e nos relatórios
        aparece igual ao faturamento. E o custo é gravado na venda no momento em
        que ela acontece — preencher depois não corrige vendas já registradas.

        <?php if (!$semCusto): ?>
            <a href="?semcusto=1">Ver só esses produtos</a>
        <?php else: ?>
            <a href="?">Ver todos os produtos</a>
        <?php endif; ?>
    </div>
<?php endif; ?>

<div class="card">

    <!-- BUSCA -->
    <form method="GET" style="margin-bottom:20px; display:flex; gap:10px; flex-wrap:wrap;">
        <input
            type="text"
            name="busca"
            placeholder="Buscar produto..."
            value="<?= htmlspecialchars($busca) ?>"
            style="max-width:300px;">

        <?php if ($semCusto): ?>
            <!-- Mantém o filtro ao buscar dentro dele -->
            <input type="hidden" name="semcusto" value="1">
        <?php endif; ?>

        <?php if ($negativo): ?>
            <input type="hidden" name="negativo" value="1">
        <?php endif; ?>

        <button type="submit" class="btn btn-secondary">
            Buscar
        </button>

        <?php if ($busca || $semCusto || $negativo): ?>
            <a href="?" class="btn btn-secondary">Limpar filtros</a>
        <?php endif; ?>
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
                <th>Custo de compra</th>
                <th>Preço de venda</th>
                <th>Margem</th>
                <th>Quantidade</th>
                <th>Ações</th>
            </tr>
        </thead>

        <tbody>
            <?php if (!empty($produtos)): ?>
                <?php foreach ($produtos as $p):
                    $temCusto = $p['custo'] > 0;
                    // Margem sobre o preço de venda: quanto de cada real vendido
                    // sobra depois de pagar a mercadoria
                    $margem = $temCusto && $p['preco'] > 0
                        ? (($p['preco'] - $p['custo']) / $p['preco']) * 100
                        : null;
                ?>
                    <tr>
                        <td><?= htmlspecialchars($p['nome']) ?></td>

                        <td>
                            <?php if ($temCusto): ?>
                                R$ <?= number_format($p['custo'], 2, ',', '.') ?>
                            <?php else: ?>
                                <span class="badge badge-cancelado">Sem custo</span>
                            <?php endif; ?>
                        </td>

                        <td>R$ <?= number_format($p['preco'], 2, ',', '.') ?></td>

                        <td>
                            <?php if ($margem === null): ?>
                                <span style="color: var(--text-gray);">—</span>
                            <?php else: ?>
                                <span class="<?= $margem < 0 ? 'margem-negativa' : '' ?>">
                                    <?= number_format($margem, 1, ',', '.') ?>%
                                </span>
                            <?php endif; ?>
                        </td>

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

                            <a href="EntradaEstoque.php?produto=<?= (int) $p['id'] ?>"
                                class="btn btn-secondary btn-sm">
                                Entrada
                            </a>

                            <!-- Formulário, não link: excluir por GET acontecia só de abrir a URL -->
                            <form method="POST" action="ExcluirProdutos.php" class="form-inline"
                                onsubmit="return confirm('Deseja excluir este produto?')">
                                <?= campoCsrf() ?>
                                <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
                                <button type="submit" class="btn btn-danger btn-sm">Excluir</button>
                            </form>
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
                <a href="?busca=<?= urlencode($busca) ?><?= $semCusto ? "&semcusto=1" : "" ?><?= $negativo ? "&negativo=1" : "" ?>&page=<?= $page - 1 ?>" class="pag-btn">«</a>
            <?php endif; ?>

            <?php for ($i = 1; $i <= $totalPaginas; $i++): ?>
                <a href="?busca=<?= urlencode($busca) ?><?= $semCusto ? "&semcusto=1" : "" ?><?= $negativo ? "&negativo=1" : "" ?>&page=<?= $i ?>"
                    class="pag-btn <?= $i == $page ? 'active' : '' ?>">
                    <?= $i ?>
                </a>
            <?php endfor; ?>

            <?php if ($page < $totalPaginas): ?>
                <a href="?busca=<?= urlencode($busca) ?><?= $semCusto ? "&semcusto=1" : "" ?><?= $negativo ? "&negativo=1" : "" ?>&page=<?= $page + 1 ?>" class="pag-btn">»</a>
            <?php endif; ?>

        </div>
    <?php endif; ?>

</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>