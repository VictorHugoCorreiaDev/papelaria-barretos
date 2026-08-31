<?php
session_start();

// Limpa os dados antes de destruir, para que a sessão não sobreviva
// em nenhum ponto da requisição
$_SESSION = [];
session_destroy();

header("Location: /login.php");
exit;
