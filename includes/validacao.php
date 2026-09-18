<?php

/*
 * Validações reaproveitáveis entre páginas.
 *
 * Funções aqui são globais, então este arquivo deve ser incluído com
 * require_once — incluí-lo duas vezes na mesma requisição seria um erro
 * fatal de redeclaração.
 */

/**
 * Valor em dinheiro não negativo, ou null se o que veio não serve.
 *
 * O `min="0"` dos formulários vale só no navegador: um POST montado fora
 * da tela gravava preço e custo negativos sem nenhum obstáculo.
 *
 * Aceita vírgula como separador decimal, porque é o que o teclado
 * brasileiro produz naturalmente.
 */
function valorMonetario($valor)
{
    if (!is_scalar($valor)) {
        return null;
    }

    $limpo = str_replace(',', '.', trim((string) $valor));

    if ($limpo === '' || !is_numeric($limpo)) {
        return null;
    }

    $numero = (float) $limpo;

    return $numero >= 0 ? round($numero, 2) : null;
}

/**
 * Quantidade inteira dentro de um mínimo, ou null se inválida.
 *
 * O mínimo é parâmetro porque estoque pode ser zero (produto esgotado),
 * mas quantidade vendida não: vender zero ou menos não faz sentido, e
 * quantidade negativa chegava a AUMENTAR o estoque ao finalizar a venda.
 *
 * Fração é recusada, não arredondada: com is_numeric + (int), "2.5" virava
 * 2 sem aviso, e a pessoa vendia ou lançava uma quantidade diferente da
 * que digitou.
 */
function quantidadeInteira($valor, $minimo = 0)
{
    if (!is_scalar($valor)) {
        return null;
    }

    // Só dígitos (com sinal opcional). Zero à esquerda ("05") é aceito, porque
    // é como algumas pessoas digitam; o limite de 9 dígitos evita estouro
    $texto = trim((string) $valor);

    if (!preg_match('/^[+-]?\d{1,9}$/', $texto)) {
        return null;
    }

    $numero = (int) $texto;

    return $numero >= $minimo ? $numero : null;
}

/**
 * Aceita apenas uma data no formato Y-m-d que exista de fato no calendário;
 * qualquer outra coisa cai no padrão informado.
 *
 * Usada nos filtros que vêm da query string e reaparecem no HTML.
 */
function dataValida($valor, $padrao)
{
    if (!is_string($valor)) {
        return $padrao;
    }

    $data = DateTime::createFromFormat('Y-m-d', $valor);

    return ($data && $data->format('Y-m-d') === $valor)
        ? $valor
        : $padrao;
}
