<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../Conexao.php';
require_once __DIR__ . '/../includes/configuracao.php';

/*
 * Desfaz uma entrada de estoque lançada por engano: apaga o registro e tira
 * do saldo as unidades que ela tinha colocado.
 *
 * Se parte dessas unidades já foi vendida, o saldo não cobre a retirada e a
 * operação é recusada — senão o estoque ficaria negativo. A mesma baixa
 * condicional das vendas (WHERE quantidade >= ?) faz essa conferência.
 *
 * O custo do produto não volta ao que era: o sistema não guarda o custo
 * anterior a cada entrada. Se for o caso, corrija pela edição do produto.
 */

exigirCsrf('EntradaEstoque.php');

$id = (int) ($_POST['id'] ?? 0);

$stmt = $conn->prepare("SELECT * FROM entradas_estoque WHERE id = ?");
$stmt->execute([$id]);
$entrada = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$entrada) {
    $_SESSION['toast'] = ['type' => 'error', 'message' => 'Entrada não encontrada.'];
    header('Location: EntradaEstoque.php');
    exit;
}

try {
    $conn->beginTransaction();

    $baixa = $conn->prepare("
        UPDATE produtos
        SET quantidade = quantidade - ?
        WHERE id = ? AND quantidade >= ?
    ");
    $baixa->execute([$entrada['quantidade'], $entrada['produto_id'], $entrada['quantidade']]);

    // Produto que já não existe não tem saldo a corrigir; o registro sai mesmo assim
    $stmtExiste = $conn->prepare("SELECT COUNT(*) FROM produtos WHERE id = ?");
    $stmtExiste->execute([$entrada['produto_id']]);
    $produtoExiste = (int) $stmtExiste->fetchColumn() > 0;

    if ($baixa->rowCount() === 0 && $produtoExiste) {
        throw new RuntimeException('saldo');
    }

    $conn->prepare("DELETE FROM entradas_estoque WHERE id = ?")->execute([$id]);

    $conn->commit();

    $_SESSION['toast'] = [
        'type' => 'success',
        'message' => 'Entrada desfeita: ' . (int) $entrada['quantidade'] . ' unidade(s) saíram do estoque.'
    ];
} catch (Exception $e) {
    $conn->rollBack();

    $_SESSION['toast'] = [
        'type' => 'error',
        'message' => $e->getMessage() === 'saldo'
            ? 'Não dá para desfazer: parte dessas unidades já saiu do estoque. Ajuste a quantidade pela edição do produto.'
            : 'Não foi possível desfazer a entrada.'
    ];
}

header('Location: EntradaEstoque.php');
exit;
