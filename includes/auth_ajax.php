<?php

/*
 * Proteção de endpoints AJAX.
 *
 * Responde 401 com JSON em vez de redirecionar para o login: o fetch()
 * seguiria o redirect silenciosamente e o JavaScript acabaria injetando
 * a tela de login dentro do modal ou tentando parsear HTML como JSON.
 * O tratamento do 401 fica em assets/js/funcoes.js.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['usuario'])) {

    http_response_code(401);
    header('Content-Type: application/json; charset=utf-8');

    echo json_encode([
        'status' => 'erro',
        'mensagem' => 'Sessão expirada. Faça login novamente.'
    ]);

    exit;
}
