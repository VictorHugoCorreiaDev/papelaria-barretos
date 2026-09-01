<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../Conexao.php';
require_once __DIR__ . '/../includes/configuracao.php';

$id = (int) ($_GET['id'] ?? 0);

// O tratamento do POST precisa vir antes do header.php: ele termina em
// header("Location: ...") e nenhuma saída pode ter sido impressa ainda.

if ($_POST) {
    $nome = $_POST['nome'];
    $preco = $_POST['preco'];
    $custo = $_POST['custo'] ?? 0;
    $quantidade = $_POST['quantidade'];

    $stmt = $conn->prepare("UPDATE produtos SET nome=?, preco=?, custo=?, quantidade=? WHERE id=?");
    $stmt->execute([$nome, $preco, $custo, $quantidade, $id]);

    $_SESSION['toast'] = [
        'type' => 'success',
        'message' => 'Produto atualizado com sucesso!'
    ];

    header("Location: Estoque.php");
    exit;
}

$stmt = $conn->prepare("SELECT * FROM produtos WHERE id = ?");
$stmt->execute([$id]);
$produto = $stmt->fetch();

// Id inexistente ou ausente: volta para a listagem em vez de renderizar
// o formulário com campos vazios
if (!$produto) {
    $_SESSION['toast'] = [
        'type' => 'error',
        'message' => 'Produto não encontrado!'
    ];

    header("Location: Estoque.php");
    exit;
}

require_once __DIR__ . '/../includes/header.php';
?>

<h2>Editar Produto</h2>

<div class="card">
    <form method="POST">
        <label>Nome</label><br>
        <input type="text" name="nome" value="<?= htmlspecialchars($produto['nome']) ?>"><br>

        <label>Preço de venda</label><br>
        <input type="number" step="0.01" min="0" name="preco" value="<?= htmlspecialchars($produto['preco']) ?>"><br>

        <label>Custo de compra</label><br>
        <input type="number" step="0.01" min="0" name="custo" value="<?= htmlspecialchars($produto['custo']) ?>"><br>

        <label>Quantidade</label><br>
        <input type="number" name="quantidade" value="<?= (int) $produto['quantidade'] ?>"><br><br>

        <button type="submit" class="btn btn-success">Salvar</button>
    </form>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>