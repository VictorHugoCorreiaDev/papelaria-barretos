<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
?>
<!DOCTYPE html>
<html lang="pt-BR">

<head>
    <meta charset="UTF-8">
    <meta name="robots" content="noindex, nofollow">
    <title>Bazar e Papelaria Barretos</title>

    <?php
    /*
     * O tema é aplicado aqui, antes de qualquer CSS, e não no funcoes.js
     * do rodapé: se esperasse o fim da página, a tela apareceria clara por
     * um instante antes de escurecer, e esse pisca é bem visível.
     *
     * A escolha fica no localStorage do navegador. Sem escolha registrada,
     * segue a preferência do sistema operacional.
     */
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
                // localStorage bloqueado (janela anônima, cookies desativados):
                // segue no tema claro, que é o padrão do CSS
            }
        })();
    </script>

    <?php
    /*
     * A hospedagem manda o navegador guardar CSS e JS por 30 dias
     * (Cache-Control: max-age=2592000). Sem o parâmetro de versão abaixo,
     * uma alteração de estilo só chegaria ao usuário depois desse prazo ou
     * de um Ctrl+Shift+R — a página nova aparecia com a folha antiga.
     *
     * O filemtime muda a cada publicação do arquivo, o que muda a URL e
     * força o download da versão nova.
     */
    $versaoCss = @filemtime(__DIR__ . '/../assets/css/style.css') ?: '1';
    ?>
    <link rel="stylesheet" href="/assets/css/style.css?v=<?= $versaoCss ?>">
    <script>
        const BASE_URL = "";
    </script>

</head>

<body>

    <body>

        <?php if (isset($_SESSION['toast'])): ?>
            <div class="toast toast-<?= htmlspecialchars($_SESSION['toast']['type']) ?> show">
                <?= htmlspecialchars($_SESSION['toast']['message']) ?>
            </div>

            <script>
                setTimeout(() => {
                    const toast = document.querySelector('.toast');
                    if (toast) {
                        toast.classList.remove('show');
                    }
                }, 3000);
            </script>

            <?php unset($_SESSION['toast']); ?>
        <?php endif; ?>

        <?php include __DIR__ . '/sidebar.php'; ?>

        <div class="main">

            <header class="topbar">

                <div class="topbar-user">
                    <!--
                      Os dois ícones ficam no HTML e o CSS mostra um ou outro
                      conforme o data-theme. Assim o JS só troca o atributo no
                      <html>, sem precisar reescrever o conteúdo do botão — e o
                      ícone certo já aparece no primeiro render, sem pisca.

                      O ícone mostra o que o clique VAI fazer: lua no tema
                      claro, sol no escuro.
                    -->
                    <button type="button" id="alternarTema" class="btn-tema"
                        onclick="alternarTema()" aria-label="Alternar tema"
                        title="Alternar tema">

                        <svg class="icone-lua" width="18" height="18" viewBox="0 0 24 24"
                            fill="none" stroke="currentColor" stroke-width="1.7"
                            stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z" />
                        </svg>

                        <svg class="icone-sol" width="18" height="18" viewBox="0 0 24 24"
                            fill="none" stroke="currentColor" stroke-width="1.7"
                            stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <circle cx="12" cy="12" r="4" />
                            <path d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M6.34 17.66l-1.41 1.41M19.07 4.93l-1.41 1.41" />
                        </svg>
                    </button>

                    <span>👤 <?= htmlspecialchars($_SESSION['usuario'] ?? '') ?><?php if (function_exists('nomePerfil')): ?> <small class="perfil-rotulo">· <?= htmlspecialchars(nomePerfil($_SESSION['perfil'] ?? '')) ?></small><?php endif; ?></span>

                </div>
            </header>

            <div class="content">