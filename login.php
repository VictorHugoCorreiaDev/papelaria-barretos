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
    <main class="login-painel">

        <h1 class="login-titulo">Bem-vindo de volta.</h1>

        <?php
        /*
         * O nome da loja precisa estar em TEXTO na tela, não só na imagem da
         * logo: uma tela só com usuário e senha num subdomínio gratuito é o
         * formato que o Google procura ao caçar páginas falsas de login (foi
         * o que levou ao aviso de "site perigoso"). Não tire o nome daqui.
         */
        ?>
        <p class="login-subtitulo">
            Acesse o painel de vendas e estoque da <strong>Bazar e Papelaria Barretos</strong>.
            Acesso restrito à equipe.
        </p>

        <?php if ($erro): ?>
            <div class="login-erro" role="alert">
                <?= htmlspecialchars($erro) ?>
            </div>
        <?php endif; ?>

        <form method="POST" class="login-form">

            <div class="login-campo">
                <label for="usuarioLogin">Usuário</label>
                <div class="login-input">
                    <svg viewBox="0 0 24 24" aria-hidden="true">
                        <circle cx="12" cy="8" r="4" />
                        <path d="M4 20c0-4 3.6-6 8-6s8 2 8 6" />
                    </svg>
                    <input
                        type="text"
                        id="usuarioLogin"
                        name="usuario"
                        autocomplete="username"
                        autocapitalize="none"
                        spellcheck="false"
                        value="<?= htmlspecialchars($usuario ?? '') ?>"
                        required
                        autofocus>
                </div>
            </div>

            <div class="login-campo">
                <div class="login-rotulo-linha">
                    <label for="senhaLogin">Sua senha</label>

                    <?php
                    // Não há recuperação por e-mail (o sistema não guarda e-mail):
                    // o caminho real é um administrador redefinir a senha
                    ?>
                    <button type="button" class="login-esqueceu"
                        aria-expanded="false" aria-controls="ajudaSenha"
                        onclick="var a = document.getElementById('ajudaSenha'); a.hidden = !a.hidden; this.setAttribute('aria-expanded', String(!a.hidden));">
                        Esqueceu?
                    </button>
                </div>

                <div class="login-input">
                    <svg viewBox="0 0 24 24" aria-hidden="true">
                        <rect x="5" y="11" width="14" height="10" rx="2" />
                        <path d="M8 11V8a4 4 0 0 1 8 0v3" />
                    </svg>
                    <input
                        type="password"
                        id="senhaLogin"
                        name="senha"
                        autocomplete="current-password"
                        required>
                </div>

                <p class="login-ajuda" id="ajudaSenha" hidden>
                    Peça a um administrador da loja para redefinir sua senha em
                    <strong>Configurações → Usuários</strong>.
                </p>
            </div>

            <button type="submit" class="btn btn-primary login-botao">
                Entrar
            </button>
        </form>

        <?php
        // Sem "criar conta": o site é público, e cadastro aberto deixaria
        // qualquer pessoa ver o financeiro da loja (veja pages/Usuarios.php)
        ?>
        <p class="login-rodape">
            Ainda não possui acesso? <strong>Fale com o administrador da loja.</strong>
        </p>

    </main>
</body>


</html>