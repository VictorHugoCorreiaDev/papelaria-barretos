<?php

/*
 * Menu lateral. A classe `.active` já existia no CSS mas nunca era aplicada —
 * aqui ela é ligada comparando a rota aberta com o href de cada item.
 *
 * Telas que não têm entrada própria no menu (editar/excluir produto, cancelar
 * venda) herdam o destaque da tela de origem, senão o usuário perde a
 * referência de onde está no meio do fluxo.
 */

$rotaAtual = $_SERVER['SCRIPT_NAME'] ?? '';

$rotaAtiva = [
    '/index.php'                 => '/dashboard.php',
    '/pages/EditarProdutos.php'  => '/pages/Estoque.php',
    '/pages/ExcluirProdutos.php' => '/pages/Estoque.php',
    '/pages/CancelarVenda.php'   => '/pages/ListarVendas.php',
][$rotaAtual] ?? $rotaAtual;

$itensMenu = [
    '/dashboard.php'               => 'Home',
    '/pages/RegistrarVendas.php'   => 'Registrar Venda',
    '/pages/CadastrarProdutos.php' => 'Cadastrar Produto',
    '/pages/Estoque.php'           => 'Estoque',
    '/pages/ListarVendas.php'      => 'Vendas',
    '/pages/Relatorios.php'        => 'Relatórios',
];
?>
<div class="layout">
    <!-- SIDEBAR -->
    <aside class="sidebar">
        <div class="logo">
            <img src="/assets/img/logo.webp" alt="Bazar e Papelaria Barretos">
        </div>

        <nav>
            <?php foreach ($itensMenu as $href => $rotulo): ?>
                <?php $ativo = ($href === $rotaAtiva); ?>
                <a href="<?= $href ?>"<?= $ativo ? ' class="active" aria-current="page"' : '' ?>><?= $rotulo ?></a>
            <?php endforeach; ?>
            <hr>
            <a href="/logout.php" class="logout">Sair</a>
        </nav>
    </aside>
