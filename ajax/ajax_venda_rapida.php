<?php
require_once __DIR__ . '/../includes/auth_ajax.php';
require_once __DIR__ . '/../Conexao.php';

header('Content-Type: application/json');

$produto_id = $_POST['produto_id'] ?? null;
$quantidade = (int)($_POST['quantidade'] ?? 0);

if (!$produto_id || $quantidade <= 0) {
    echo json_encode(['status' => 'erro', 'mensagem' => 'Dados inválidos']);
    exit;
}

$stmt = $conn->prepare("SELECT * FROM produtos WHERE id = ?");
$stmt->execute([$produto_id]);
$produto = $stmt->fetch();

if (!$produto || $produto['quantidade'] < $quantidade) {
    echo json_encode(['status' => 'erro', 'mensagem' => 'Estoque insuficiente']);
    exit;
}

$total = $produto['preco'] * $quantidade;

$conn->beginTransaction();

try {

    // Criar venda
    $conn->prepare("INSERT INTO vendas (total) VALUES (?)")
         ->execute([$total]);

    $venda_id = $conn->lastInsertId();

    // Registrar item da venda
    $conn->prepare("INSERT INTO vendas_produtos 
        (venda_id, produto_id, quantidade, preco_unitario)
        VALUES (?, ?, ?, ?)")
    ->execute([$venda_id, $produto_id, $quantidade, $produto['preco']]);

    // Atualizar estoque
    $conn->prepare("UPDATE produtos 
        SET quantidade = quantidade - ? 
        WHERE id = ?")
    ->execute([$quantidade, $produto_id]);

    $conn->commit();

    // ==============================
    // Buscar novo estoque atualizado
    // ==============================
    $stmt = $conn->prepare("SELECT quantidade FROM produtos WHERE id = ?");
    $stmt->execute([$produto_id]);
    $novoEstoque = $stmt->fetchColumn();

    // ==============================
    // Buscar métricas atualizadas
    // ==============================

    // O filtro status = 'ativa' tem que ser o mesmo do dashboard.php. Sem ele
    // as vendas canceladas entravam na conta e o card atualizado por AJAX
    // ficava maior que o número que a página mostra ao recarregar.

    $totalVendas = $conn->query("
        SELECT COUNT(*)
        FROM vendas
        WHERE status = 'ativa'
    ")->fetchColumn();

    $receitaTotal = $conn->query("
        SELECT IFNULL(SUM(total),0)
        FROM vendas
        WHERE status = 'ativa'
    ")->fetchColumn();

    $receitaHoje = $conn->query("
        SELECT IFNULL(SUM(total),0)
        FROM vendas
        WHERE status = 'ativa'
        AND DATE(created_at) = CURDATE()
    ")->fetchColumn();

    $ticketMedio = $totalVendas > 0
        ? $receitaTotal / $totalVendas
        : 0;

    echo json_encode([
        'status' => 'sucesso',
        'mensagem' => 'Venda registrada com sucesso!',
        'novoEstoque' => $novoEstoque,
        'cards' => [
            'vendas' => (int)$totalVendas,
            'receitaTotal' => (float)$receitaTotal,
            'receitaHoje' => (float)$receitaHoje,
            'ticketMedio' => (float)$ticketMedio
        ]
    ]);

} catch (Exception $e) {

    $conn->rollBack();

    echo json_encode([
        'status' => 'erro',
        'mensagem' => 'Erro ao registrar venda'
    ]);
}
