<?php

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../Conexao.php';

if (!isset($_GET['id'])) {
    header("Location: Estoque.php");
    exit;
}

$id = (int) $_GET['id'];

// Verifica vínculo
$stmt = $conn->prepare("SELECT COUNT(*) FROM vendas_produtos WHERE produto_id = ?");
$stmt->execute([$id]);
$total = $stmt->fetchColumn();

if ($total > 0) {
    header("Location: Estoque.php?erro=vinculado");
    exit;
}

// Exclui
$stmt = $conn->prepare("DELETE FROM produtos WHERE id = ?");
$stmt->execute([$id]);

header("Location: Estoque.php?sucesso=excluido");
exit;