<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../Conexao.php';
require_once __DIR__ . '/../includes/configuracao.php';

// O tratamento do POST precisa vir antes do header.php: ele termina em
// header("Location: ...") e nenhuma saída pode ter sido impressa ainda.

if ($_POST) {

    $nome = $_POST['nome'];
    $preco = $_POST['preco'];
    $quantidade = $_POST['quantidade'];

    $sql = "INSERT INTO produtos (nome, preco, quantidade) VALUES (?, ?, ?)";
    $stmt = $conn->prepare($sql);
    $stmt->execute([$nome, $preco, $quantidade]);

    $_SESSION['toast'] = [
        "type" => "success",
        "message" => "Produto cadastrado com sucesso!"
    ];

    header("Location: CadastrarProdutos.php");
    exit;
}

require_once __DIR__ . '/../includes/header.php';
?>

<h2>Cadastrar Produto</h2>

<div class="card">

    <form method="POST">

        <div class="form-group">
            <label>Nome</label>
            <input type="text" name="nome" required>
        </div>

        <div class="form-group">
            <label>Preço</label>
            <input type="number" step="0.01" name="preco" required>
        </div>

        <div class="form-group">
            <label>Quantidade</label>
            <input type="number" name="quantidade" required>
        </div>

        <button type="submit" class="btn btn-success">
            Cadastrar Produto
        </button>

    </form>

</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>