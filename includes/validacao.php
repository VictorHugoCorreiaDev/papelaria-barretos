<?php

/*
 * Validações reaproveitáveis entre páginas.
 *
 * Funções aqui são globais, então este arquivo deve ser incluído com
 * require_once — incluí-lo duas vezes na mesma requisição seria um erro
 * fatal de redeclaração.
 */

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
