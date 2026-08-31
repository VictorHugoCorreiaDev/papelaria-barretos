<?php
session_start();
require 'Conexao.php';

// Já autenticado: não faz sentido mostrar o formulário de novo
if (isset($_SESSION['usuario'])) {
    header('Location: /dashboard.php');
    exit;
}

$erro = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $usuario = $_POST['usuario'] ?? '';
    $senha   = $_POST['senha'] ?? '';

    $stmt = $conn->prepare("SELECT * FROM usuarios WHERE usuario = ?");
    $stmt->execute([$usuario]);
    $user = $stmt->fetch();

    if ($user && password_verify($senha, $user['senha'])) {
        // Novo id de sessão a cada login, contra fixação de sessão
        session_regenerate_id(true);

        $_SESSION['usuario'] = $user['usuario'];
        header("Location: /dashboard.php");
        exit;
    } else {
        $erro = "Usuário ou senha inválidos";
    }
}
?>

<!DOCTYPE html>
<html>

<head>
    <meta charset="UTF-8">
    <title>Login - Papelaria Barretos</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>

<body class="login-page">
    <div class="login-card">
        <img src="/assets/img/logo.webp" alt="Bazar e Papelaria Barretos">

        <h2>Entrar</h2>

        <?php if ($erro): ?>
            <div class="login-erro" role="alert">
                <?= htmlspecialchars($erro) ?>
            </div>
        <?php endif; ?>

        <form method="POST">
            <input
                type="text"
                name="usuario"
                placeholder="Usuário"
                class="input"
                value="<?= htmlspecialchars($usuario ?? '') ?>"
                required>

            <input
                type="password"
                name="senha"
                placeholder="Senha"
                class="input"
                required>

            <button type="submit" class="btn btn-primary">
                Entrar
            </button>
        </form>
    </div>
</body>


</html>