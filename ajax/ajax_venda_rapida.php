<?php
require_once __DIR__ . '/../includes/auth_ajax.php';
require_once __DIR__ . '/../Conexao.php';
require_once __DIR__ . '/../includes/configuracao.php';

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
        (venda_id, produto_id, quantidade, preco_unitario, custo_unitario)
        VALUES (?, ?, ?, ?, ?)")
    ->execute([$venda_id, $produto_id, $quantidade, $produto['preco'], $produto['custo']]);

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

    /*
     * Estas consultas precisam ser as MESMAS do dashboard.php — mesmo
     * recorte de mês e mesmo filtro status = 'ativa'. Divergir aqui faz o
     * card mostrar um número depois da venda rápida e outro ao recarregar
     * a página.
     */

    $mes = $conn->query("
        SELECT COUNT(*) AS vendas, COALESCE(SUM(total), 0) AS faturamento
        FROM vendas
        WHERE status = 'ativa'
          AND YEAR(created_at) = YEAR(CURDATE())
          AND MONTH(created_at) = MONTH(CURDATE())
    ")->fetch(PDO::FETCH_ASSOC);

    $vendasMes = (int) $mes['vendas'];
    $faturamentoMes = (float) $mes['faturamento'];

    $custoMes = (float) $conn->query("
        SELECT COALESCE(SUM(vp.quantidade * vp.custo_unitario), 0)
        FROM vendas_produtos vp
        JOIN vendas v ON v.id = vp.venda_id
        WHERE v.status = 'ativa'
          AND YEAR(v.created_at) = YEAR(CURDATE())
          AND MONTH(v.created_at) = MONTH(CURDATE())
    ")->fetchColumn();

    $lucroMes = $faturamentoMes - $custoMes;
    $margemMes = $faturamentoMes > 0 ? ($lucroMes / $faturamentoMes) * 100 : 0;
    $ticketMedioMes = $vendasMes > 0 ? $faturamentoMes / $vendasMes : 0;

    $hoje = $conn->query("
        SELECT COUNT(*) AS vendas, COALESCE(SUM(total), 0) AS faturamento
        FROM vendas
        WHERE status = 'ativa' AND DATE(created_at) = CURDATE()
    ")->fetch(PDO::FETCH_ASSOC);

    echo json_encode([
        'status' => 'sucesso',
        'mensagem' => 'Venda registrada com sucesso!',
        'novoEstoque' => $novoEstoque,
        'cards' => [
            'vendasMes' => $vendasMes,
            'faturamentoMes' => $faturamentoMes,
            'lucroMes' => $lucroMes,
            'margemMes' => $margemMes,
            'ticketMedioMes' => $ticketMedioMes,
            'vendasHoje' => (int) $hoje['vendas'],
            'receitaHoje' => (float) $hoje['faturamento']
        ]
    ]);

} catch (Exception $e) {

    $conn->rollBack();

    echo json_encode([
        'status' => 'erro',
        'mensagem' => 'Erro ao registrar venda'
    ]);
}
