<?php

/*
 * Ponto de entrada. O auth.php redireciona para o login quando não há
 * sessão ativa; chegando até aqui, o usuário está autenticado.
 */

require_once __DIR__ . '/includes/auth.php';

header('Location: /dashboard.php');
exit;
