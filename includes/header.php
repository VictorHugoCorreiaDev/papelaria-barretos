<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
?>
<!DOCTYPE html>
<html>

<head>
    <meta charset="UTF-8">
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
                      O rótulo e o título são preenchidos pelo JS conforme o
                      tema ativo — no HTML não dá para saber qual está valendo,
                      já que a decisão acontece no navegador.
                    -->
                    <button type="button" id="alternarTema" class="btn-tema"
                        onclick="alternarTema()" title="Alternar tema">
                        <span id="iconeTema">🌙</span>
                    </button>

                    <span>👤 <?= htmlspecialchars($_SESSION['usuario'] ?? '') ?></span>

                </div>
            </header>

            <div class="content">