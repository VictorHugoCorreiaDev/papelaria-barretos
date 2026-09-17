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
    '/pages/ExcluirDespesa.php'  => '/pages/Despesas.php',
    '/pages/EditarDespesa.php'   => '/pages/Despesas.php',
    '/pages/Backup.php'          => '/pages/Configuracoes.php',
][$rotaAtual] ?? $rotaAtual;

$itensMenu = [
    '/dashboard.php'               => 'Home',
    '/pages/RegistrarVendas.php'   => 'Registrar Venda',
    '/pages/CadastrarProdutos.php' => 'Cadastrar Produto',
    '/pages/Estoque.php'           => 'Estoque',
    '/pages/ListarVendas.php'      => 'Vendas',
    '/pages/Despesas.php'          => 'Despesas',
    '/pages/FechamentoCaixa.php'   => 'Fechamento de Caixa',
    '/pages/Relatorios.php'        => 'Relatórios',
    '/pages/Configuracoes.php'     => 'Configurações',
];
?>
<div class="layout">
    <!--
      Abaixo de 900px a sidebar sai do fluxo e vira um painel deslizante.
      Sem este botão ela simplesmente sumia (display:none) e não havia como
      navegar nem sair do sistema pelo celular.
    -->
    <button type="button" class="abrir-menu" onclick="alternarMenu()"
        aria-label="Abrir menu" aria-expanded="false" aria-controls="menuLateral">
        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor"
            stroke-width="1.8" stroke-linecap="round" aria-hidden="true">
            <path d="M3 6h18M3 12h18M3 18h18" />
        </svg>
    </button>

    <!-- Véu que escurece o conteúdo e fecha o menu ao toque -->
    <div class="veu-menu" id="veuMenu" onclick="fecharMenu()" hidden></div>

    <!-- SIDEBAR -->
    <aside class="sidebar" id="menuLateral">
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
