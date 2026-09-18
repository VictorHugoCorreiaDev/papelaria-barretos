<?php

/*
 * Perfis de acesso.
 *
 * - admin: vê e faz tudo.
 * - vendedor: vende e consulta. Não vê custo, margem nem lucro, não
 *   cancela venda, não mexe em produto nem em estoque, e não abre
 *   despesas, fechamento, relatórios, configurações e usuários.
 *
 * Cancelar venda fica só com o admin de propósito: vender, receber em
 * dinheiro e cancelar depois é o desvio mais comum no balcão.
 *
 * A liberação é por LISTA DE ROTAS PERMITIDAS, e não por lista de
 * bloqueios: uma tela nova nasce restrita ao admin até alguém liberá-la
 * aqui de propósito. Com lista de bloqueios, esquecer de incluir uma tela
 * nova a deixaria aberta para o vendedor.
 *
 * O perfil vem do banco a cada acesso (sessaoContinuaValida, em
 * sessao.php), então uma mudança de perfil vale na hora.
 */

function perfis()
{
    return [
        'vendedor' => 'Vendedor',
        'admin'    => 'Administrador',
    ];
}

function perfilValido($valor)
{
    return is_string($valor) && array_key_exists($valor, perfis()) ? $valor : null;
}

function nomePerfil($perfil)
{
    return perfis()[$perfil] ?? $perfil;
}

// Sem perfil conhecido, vale o de menor acesso
function perfilAtual()
{
    return perfilValido($_SESSION['perfil'] ?? null) ?? 'vendedor';
}

function ehAdmin()
{
    return perfilAtual() === 'admin';
}

/**
 * Rotas que o vendedor pode abrir. Qualquer outra é só do admin.
 */
function rotasDoVendedor()
{
    return [
        '/index.php',
        '/dashboard.php',
        '/pages/RegistrarVendas.php',
        '/pages/Estoque.php',
        '/pages/ListarVendas.php',
        '/pages/Comprovante.php',
        '/ajax/ajax_venda_rapida.php',
        '/ajax/ajax_venda_itens.php',
    ];
}

function podeAcessar($rota)
{
    return ehAdmin() || in_array($rota, rotasDoVendedor(), true);
}
