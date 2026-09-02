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
                    <span>👤 <?= htmlspecialchars($_SESSION['usuario'] ?? '') ?></span>

                </div>
            </header>

            <div class="content">