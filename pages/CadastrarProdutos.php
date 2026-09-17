<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/validacao.php';
require_once __DIR__ . '/../Conexao.php';
require_once __DIR__ . '/../includes/configuracao.php';

// O tratamento do POST precisa vir antes do header.php: ele termina em
// header("Location: ...") e nenhuma saída pode ter sido impressa ainda.

if ($_POST) {

    $nome = trim($_POST['nome'] ?? '');

    /*
     * O min="0" dos campos vale só no navegador: um POST montado fora da
     * tela gravava preço e custo negativos sem obstáculo nenhum.
     */
    $preco = valorMonetario($_POST['preco'] ?? null);
    $custo = valorMonetario($_POST['custo'] ?? 0);
    $quantidade = quantidadeInteira($_POST['quantidade'] ?? null, 0);

    if ($nome === '' || $preco === null || $custo === null || $quantidade === null) {
        $_SESSION['toast'] = [
            'type' => 'error',
            'message' => 'Preencha o nome e use valores não negativos em preço, custo e quantidade.'
        ];

        header("Location: CadastrarProdutos.php");
        exit;
    }

    $sql = "INSERT INTO produtos (nome, preco, custo, quantidade) VALUES (?, ?, ?, ?)";
    $stmt = $conn->prepare($sql);
    $stmt->execute([$nome, $preco, $custo, $quantidade]);

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
            <label>Preço de venda</label>
            <input type="number" step="0.01" min="0" name="preco" required>
        </div>

        <div class="form-group">
            <label>Custo de compra</label>
            <input type="number" step="0.01" min="0" name="custo" value="0.00" required>
            <small style="color: var(--text-gray);">
                Quanto você paga pelo produto. Deixando zerado, o lucro deste
                item aparece igual ao faturamento no dashboard e nos relatórios.
            </small>
        </div>

        <div class="form-group">
            <label>Quantidade</label>
            <input type="number" min="0" name="quantidade" required>
        </div>

        <button type="submit" class="btn btn-success">
            Cadastrar Produto
        </button>

    </form>

</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>