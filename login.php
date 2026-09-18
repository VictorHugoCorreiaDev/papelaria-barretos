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
<html lang="pt-BR">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Sistema interno — Bazar e Papelaria Barretos</title>

    <?php
    /*
     * Sistema interno: não precisa aparecer em busca nenhuma. O .htaccess
     * já manda o cabeçalho X-Robots-Tag para todas as páginas; a meta tag
     * repete a instrução caso o servidor não tenha o mod_headers.
     */
    ?>
    <meta name="robots" content="noindex, nofollow">
    <meta name="description" content="Sistema interno de vendas e estoque da Bazar e Papelaria Barretos. Acesso restrito à equipe da loja.">

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

        <?php
        /*
         * Uma tela só com "Entrar", usuário e senha, num subdomínio gratuito,
         * é exatamente o que o Google procura ao caçar páginas falsas de
         * login. Dizer de quem é o sistema e para quem ele serve ajuda a
         * revisão da Navegação Segura e quem chega aqui por engano.
         */
        ?>
        <h2>Sistema interno</h2>
        <p class="login-identificacao">
            Vendas e estoque da <strong>Bazar e Papelaria Barretos</strong>.
            Acesso restrito à equipe da loja.
        </p>

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
                aria-label="Usuário"
                autocomplete="username"
                class="input"
                value="<?= htmlspecialchars($usuario ?? '') ?>"
                required>

            <input
                type="password"
                name="senha"
                placeholder="Senha"
                aria-label="Senha"
                autocomplete="current-password"
                class="input"
                required>

            <button type="submit" class="btn btn-primary">
                Entrar
            </button>
        </form>
    </div>
</body>


</html>