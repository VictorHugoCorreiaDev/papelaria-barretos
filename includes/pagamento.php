<?php

/*
 * Formas de pagamento aceitas.
 *
 * A chave é o que vai para o banco (coluna vendas.forma_pagamento); o
 * valor é o rótulo exibido. Centralizado aqui para que o select das telas
 * de venda e a exibição no dashboard não saiam de sincronia.
 *
 * Funções globais: inclua com require_once.
 */

function formasPagamento()
{
    return [
        'pix'      => 'Pix',
        'dinheiro' => 'Dinheiro',
        'debito'   => 'Cartão de débito',
        'credito'  => 'Cartão de crédito',
    ];
}

/**
 * Só aceita uma forma conhecida; qualquer outra coisa vira string vazia,
 * que a interface mostra como "—".
 */
function formaPagamentoValida($valor)
{
    return array_key_exists($valor, formasPagamento()) ? $valor : '';
}

/**
 * Rótulo para exibição. Vendas antigas, gravadas antes desta coluna
 * existir, têm o campo vazio e caem no traço.
 */
function nomeFormaPagamento($valor)
{
    return formasPagamento()[$valor] ?? '—';
}

/**
 * Vendas sem cliente informado aparecem como avulsas, em vez de um espaço
 * em branco na listagem.
 */
function nomeCliente($valor)
{
    $nome = trim((string) $valor);
    return $nome !== '' ? $nome : 'Cliente avulso';
}
