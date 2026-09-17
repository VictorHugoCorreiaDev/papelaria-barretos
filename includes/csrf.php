<?php

/*
 * Proteção contra requisição forjada de outro site (CSRF).
 *
 * Ações destrutivas passaram a exigir POST com um token da sessão. Antes
 * elas aconteciam por GET: bastava abrir /pages/ExcluirProdutos.php?id=7
 * para o produto sumir. Um link colado por engano, um acelerador de links
 * do navegador ou uma imagem apontando para essa URL numa página qualquer
 * disparariam a ação sem nenhuma confirmação real — o confirm() do
 * JavaScript não protege nada disso.
 *
 * Funções globais: inclua com require_once, depois do auth.php (que é
 * quem garante a sessão iniciada).
 */

/**
 * Token da sessão, criado na primeira chamada e reaproveitado depois.
 * Um token por sessão basta aqui: o objetivo é provar que o pedido saiu
 * de uma página do próprio sistema.
 */
function tokenCsrf()
{
    if (empty($_SESSION['token_csrf'])) {
        $_SESSION['token_csrf'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['token_csrf'];
}

/**
 * Campo pronto para colar dentro de um <form>.
 */
function campoCsrf()
{
    return '<input type="hidden" name="token_csrf" value="'
        . htmlspecialchars(tokenCsrf()) . '">';
}

/**
 * Confere o token recebido. Em caso de falha registra um toast e devolve
 * o usuário à página indicada, sem executar a ação.
 *
 * A comparação usa hash_equals para não vazar informação pelo tempo de
 * resposta.
 */
function exigirCsrf($voltarPara)
{
    $recebido = $_POST['token_csrf'] ?? '';
    $esperado = $_SESSION['token_csrf'] ?? '';

    if ($_SERVER['REQUEST_METHOD'] !== 'POST'
        || $esperado === ''
        || !is_string($recebido)
        || !hash_equals($esperado, $recebido)) {

        $_SESSION['toast'] = [
            'type' => 'error',
            'message' => 'Ação não confirmada. Tente novamente pela tela.'
        ];

        header('Location: ' . $voltarPara);
        exit;
    }
}
