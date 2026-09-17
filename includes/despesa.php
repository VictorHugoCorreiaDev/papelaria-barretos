<?php

/*
 * Categorias de despesa.
 *
 * Lista fixa em vez de texto livre para que o relatório consiga agrupar:
 * digitado à mão, "Energia", "energia" e "Luz" viram três categorias
 * diferentes e o total por categoria perde o sentido.
 *
 * A chave é o que vai para o banco; o valor é o rótulo exibido.
 * Funções globais: inclua com require_once.
 */

function categoriasDespesa()
{
    return [
        'aluguel'      => 'Aluguel',
        'energia'      => 'Energia elétrica',
        'agua'         => 'Água',
        'internet'     => 'Internet e telefone',
        'fornecedores' => 'Fornecedores e mercadoria',
        'salarios'     => 'Salários e encargos',
        'impostos'     => 'Impostos e taxas',
        'manutencao'   => 'Manutenção e reparos',
        'marketing'    => 'Marketing e divulgação',
        'outros'       => 'Outros',
    ];
}

/**
 * Só aceita uma categoria conhecida; qualquer outra coisa vira "outros",
 * para o lançamento não se perder nem sumir dos agrupamentos.
 */
function categoriaDespesaValida($valor)
{
    return array_key_exists($valor, categoriasDespesa()) ? $valor : 'outros';
}

/**
 * Rótulo para exibição. Lançamentos antigos, feitos fora do sistema, podem
 * ter categoria livre — nesse caso mostra o que está gravado.
 */
function nomeCategoriaDespesa($valor)
{
    return categoriasDespesa()[$valor] ?? ($valor !== '' ? $valor : 'Outros');
}
