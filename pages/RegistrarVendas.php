<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../Conexao.php';
require_once __DIR__ . '/../includes/configuracao.php';

// Todos os blocos que alteram estado abaixo terminam em
// header("Location: ...") — por isso o header.php só é incluído no fim,
// depois que nenhum redirecionamento é mais possível.

if (!isset($_SESSION['carrinho'])) {
    $_SESSION['carrinho'] = [];
}

/*ADICIONAR PRODUTO AO CARRINHO */
if (isset($_POST['adicionar'])) {

    $produto_id = $_POST['produto_id'];
    $quantidade = (int)$_POST['quantidade'];

    $stmt = $conn->prepare("SELECT * FROM produtos WHERE id = ?");
    $stmt->execute([$produto_id]);
    $produto = $stmt->fetch();

    if ($produto && $produto['quantidade'] >= $quantidade) {

        $_SESSION['carrinho'][] = [
            'produto_id' => $produto['id'],
            'nome' => $produto['nome'],
            'preco' => $produto['preco'],
            'quantidade' => $quantidade
        ];

        $_SESSION['toast'] = [
            'type' => 'success',
            'message' => 'Produto adicionado ao carrinho!'
        ];

        header("Location: RegistrarVendas.php");
        exit;
    } else {
        $_SESSION['toast'] = [
            'type' => 'error',
            'message' => 'Estoque insuficiente!'
        ];
        header("Location: RegistrarVendas.php");
        exit;
    }
}


/* REMOVER ITEM DO CARRINHO */

if (isset($_GET['remover'])) {
    $index = (int) $_GET['remover'];

    unset($_SESSION['carrinho'][$index]);
    $_SESSION['carrinho'] = array_values($_SESSION['carrinho']);

    // Redireciona para tirar o ?remover= da URL — sem isso, atualizar a
    // página remove outro item do carrinho
    header("Location: RegistrarVendas.php");
    exit;
}


/* FINALIZAR VENDA */

if (isset($_POST['finalizar'])) {

    if (empty($_SESSION['carrinho'])) {
        $_SESSION['toast'] = [
            'type' => 'warning',
            'message' => 'Carrinho vazio!'
        ];
        header("Location: RegistrarVendas.php");
        exit;
    } else {

        try {
            $conn->beginTransaction();

            $totalVenda = 0;

            foreach ($_SESSION['carrinho'] as $item) {
                $totalVenda += $item['preco'] * $item['quantidade'];
            }

            // Criar venda
            $stmt = $conn->prepare("INSERT INTO vendas (total) VALUES (?)");
            $stmt->execute([$totalVenda]);

            $venda_id = $conn->lastInsertId();

            // Registrar itens
            foreach ($_SESSION['carrinho'] as $item) {

                $conn->prepare("INSERT INTO vendas_produtos 
                    (venda_id, produto_id, quantidade, preco_unitario)
                    VALUES (?, ?, ?, ?)")
                    ->execute([
                        $venda_id,
                        $item['produto_id'],
                        $item['quantidade'],
                        $item['preco']
                    ]);

                $conn->prepare("UPDATE produtos 
                    SET quantidade = quantidade - ? 
                    WHERE id = ?")
                    ->execute([
                        $item['quantidade'],
                        $item['produto_id']
                    ]);
            }

            $conn->commit();
            $_SESSION['carrinho'] = [];
            $_SESSION['toast'] = [
                'type' => 'success',
                'message' => 'Venda finalizada com sucesso!'
            ];
            header("Location: RegistrarVendas.php");
            exit;
        } catch (Exception $e) {
            $conn->rollBack();
            $_SESSION['toast'] = [
                'type' => 'error',
                'message' => 'Erro ao finalizar venda!'
            ];
            header("Location: RegistrarVendas.php");
            exit;
        }
    }
}


// Buscar produtos para o select
$produtos = $conn->query("SELECT * FROM produtos")->fetchAll();

require_once __DIR__ . '/../includes/header.php';
?>

<h2>Registrar Venda</h2>




<!-- CARD FORM -->
<div class="card">

    <form method="POST">

        <div class="form-group">
            <label>Produto</label>
            <select name="produto_id" required>
                <option value="">Selecione</option>
                <?php foreach ($produtos as $p): ?>
                    <option value="<?= (int) $p['id'] ?>">
                        <?= htmlspecialchars($p['nome']) ?> (Estoque: <?= (int) $p['quantidade'] ?>)
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="form-group">
            <label>Quantidade</label>
            <input type="number" name="quantidade" min="1" required>
        </div>

        <button type="submit" name="adicionar" class="btn btn-primary">
            Adicionar ao Carrinho
        </button>

    </form>

</div>


<!-- CARRINHO -->
<div class="card" style="margin-top: 25px;">

    <h3>Carrinho</h3>

    <?php if (!empty($_SESSION['carrinho'])): ?>

        <table>
            <thead>
                <tr>
                    <th>Produto</th>
                    <th>Qtde</th>
                    <th>Preço</th>
                    <th>Subtotal</th>
                    <th>Ação</th>
                </tr>
            </thead>

            <tbody>
                <?php
                $total = 0;
                foreach ($_SESSION['carrinho'] as $index => $item):
                    $subtotal = $item['preco'] * $item['quantidade'];
                    $total += $subtotal;
                ?>
                    <tr>
                        <td><?= htmlspecialchars($item['nome']) ?></td>
                        <td><?= (int) $item['quantidade'] ?></td>
                        <td>R$ <?= number_format($item['preco'], 2, ',', '.') ?></td>
                        <td>R$ <?= number_format($subtotal, 2, ',', '.') ?></td>
                        <td>
                            <a href="?remover=<?= $index ?>"
                                class="btn btn-danger btn-sm">
                                Remover
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <div class="total-box">
            Total: <strong>R$ <?= number_format($total, 2, ',', '.') ?></strong>
        </div>

        <form method="POST">
            <button type="submit" name="finalizar" class="btn btn-success">
                Finalizar Venda
            </button>
        </form>

    <?php else: ?>
        <p style="color: var(--text-gray);">Carrinho vazio.</p>
    <?php endif; ?>

</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
