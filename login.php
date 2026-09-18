<?php
session_start();
require 'Conexao.php';
require_once __DIR__ . '/includes/configuracao.php';
require_once __DIR__ . '/includes/login_tentativas.php';
require_once __DIR__ . '/includes/sessao.php';

// Já autenticado: não faz sentido mostrar o formulário de novo
if (isset($_SESSION['usuario'])) {
    header('Location: /dashboard.php');
    exit;
}

$erro = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $usuario = $_POST['usuario'] ?? '';
    $senha   = $_POST['senha'] ?? '';

    // Antes de conferir a senha: este IP ainda tem tentativas?
    $bloqueio = minutosDeBloqueio($conn);

    if ($bloqueio > 0) {
        $erro = "Muitas tentativas sem sucesso. Tente de novo em $bloqueio minuto"
            . ($bloqueio == 1 ? '' : 's') . '.';
    } else {

        $stmt = $conn->prepare("SELECT * FROM usuarios WHERE usuario = ?");
        $stmt->execute([$usuario]);
        $user = $stmt->fetch();

        if ($user && password_verify($senha, $user['senha'])) {
            // Novo id de sessão a cada login, contra fixação de sessão
            session_regenerate_id(true);
            limparTentativasDeLogin($conn);

            $_SESSION['usuario'] = $user['usuario'];
            marcarSessao($user['senha']);
            header("Location: /dashboard.php");
            exit;
        }

        // Mesma mensagem para usuário inexistente e senha errada: dizer qual
        // dos dois falhou entrega metade do login a quem está tentando
        registrarFalhaDeLogin($conn, $usuario);
        $erro = "Usuário ou senha inválidos";
    }
}
?>

<!DOCTYPE html>
<html>

<head>
    <meta charset="UTF-8">
    <title>Login - Papelaria Barretos</title>

    <?php
    // Mesmo script do header.php: a tela de login também respeita o tema
    // escolhido, e aplicá-lo antes do CSS evita o pisca de tela clara
    ?>
    <script>
        (function () {
            try {
                var salvo = localStorage.getItem('tema');

                if (!salvo) {
                    salvo = window.matchMedia('(prefers-color-scheme: dark)').matches
                        ? 'dark'
                        : 'light';
                }

                document.documentElement.setAttribute('data-theme', salvo);
            } catch (e) {
                // Sem localStorage segue o tema claro, padrão do CSS
            }
        })();
    </script>

    <?php
    // O login não passa pelo header.php, então repete aqui o parâmetro de
    // versão — sem ele o cache de trinta dias da hospedagem seguraria a
    // folha antiga nesta tela
    $versaoCss = @filemtime(__DIR__ . '/assets/css/style.css') ?: '1';
    ?>
    <link rel="stylesheet" href="/assets/css/style.css?v=<?= $versaoCss ?>">
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