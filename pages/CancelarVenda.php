<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../Conexao.php';

$vendaId = isset($_GET['id']) ? (int) $_GET['id'] : 0;

if ($vendaId <= 0) {
    die("ID inválido.");
}

try {

    $conn->beginTransaction();

    // 1️⃣ Verificar status
    $stmt = $conn->prepare("SELECT status FROM vendas WHERE id = ?");
    $stmt->execute([$vendaId]);
    $venda = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$venda || $venda['status'] !== 'ativa') {
        throw new Exception("Venda inválida ou já cancelada.");
    }

    // 2️⃣ Buscar itens
    $stmt = $conn->prepare("
        SELECT produto_id, quantidade
        FROM vendas_produtos
        WHERE venda_id = ?
    ");
    $stmt->execute([$vendaId]);
    $itens = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($itens as $item) {

        $stmtUpdate = $conn->prepare("
            UPDATE produtos
            SET quantidade = quantidade + ?
            WHERE id = ?
        ");

        $stmtUpdate->execute([
            $item['quantidade'],
            $item['produto_id']
        ]);
    }

    // 3️⃣ Atualizar status
    $stmt = $conn->prepare("
        UPDATE vendas
        SET status = 'cancelada'
        WHERE id = ?
    ");
    $stmt->execute([$vendaId]);

    $conn->commit();

    $_SESSION['toast'] = [
        'type' => 'success',
        'message' => 'Venda cancelada com sucesso!'
    ];

    header("Location: ListarVendas.php");
    exit;
} catch (Exception $e) {

    $conn->rollBack();

    $_SESSION['toast'] = [
        'type' => 'error',
        'message' => 'Erro ao cancelar venda!'
    ];

    header("Location: ListarVendas.php");
    exit;
}

