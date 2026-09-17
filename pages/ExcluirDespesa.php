<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../Conexao.php';
require_once __DIR__ . '/../includes/configuracao.php';

/*
 * Despesa é lançamento avulso: não alimenta estoque nem tem outra tabela
 * apontando para ela, então pode ser excluída de fato — diferente de venda,
 * que só é cancelada para preservar o histórico.
 */

exigirCsrf('Despesas.php');

$id = (int) ($_POST['id'] ?? 0);

if ($id <= 0) {
    $_SESSION['toast'] = ['type' => 'error', 'message' => 'Despesa inválida.'];
    header('Location: Despesas.php');
    exit;
}

$stmt = $conn->prepare("DELETE FROM despesas WHERE id = ?");
$stmt->execute([$id]);

$_SESSION['toast'] = $stmt->rowCount() > 0
    ? ['type' => 'success', 'message' => 'Despesa excluída.']
    : ['type' => 'error', 'message' => 'Despesa não encontrada.'];

header('Location: Despesas.php');
exit;
