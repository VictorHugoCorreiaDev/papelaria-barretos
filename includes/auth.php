<?php

/*
 * Proteção de páginas HTML.
 *
 * Deve ser incluído ANTES de qualquer saída — ou seja, antes do header.php.
 * Se algum HTML já tiver sido impresso, o header("Location: ...") abaixo
 * falha com "headers already sent" e a página protegida vaza pela metade.
 *
 * Para endpoints AJAX use includes/auth_ajax.php, que responde 401 em vez
 * de redirecionar.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['usuario'])) {
    header('Location: /login.php');
    exit;
}
