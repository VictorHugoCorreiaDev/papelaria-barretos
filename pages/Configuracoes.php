<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../Conexao.php';
require_once __DIR__ . '/../includes/configuracao.php';
require_once __DIR__ . '/../includes/header.php';

/*
 * Backup dos dados.
 *
 * A hospedagem é gratuita e não oferece garantia nenhuma: se a conta cair,
 * o banco vai junto. Baixar estes arquivos de tempos em tempos é o único
 * resguardo que existe hoje.
 */

$tabelas = [
    'produtos'        => ['rotulo' => 'Produtos',        'descricao' => 'Cadastro com preço, custo e estoque atual'],
    'vendas'          => ['rotulo' => 'Vendas',          'descricao' => 'Todas as vendas, com cliente, desconto e situação'],
    'vendas_produtos' => ['rotulo' => 'Itens das vendas', 'descricao' => 'O que foi vendido em cada venda, com preço e custo da época'],
    'despesas'        => ['rotulo' => 'Despesas',        'descricao' => 'Gastos lançados, por categoria'],
];

// Contagem de registros, para o backup mostrar o que está sendo levado
foreach ($tabelas as $nome => $dados) {
    $tabelas[$nome]['registros'] = (int) $conn->query("SELECT COUNT(*) FROM `$nome`")->fetchColumn();
}
?>

<h2>Configurações</h2>

<div class="card">
    <h3>💾 Backup dos dados</h3>

    <p style="color: var(--text-gray); margin-bottom: 18px;">
        Baixe os arquivos e guarde fora do servidor — no computador da loja, em um
        pendrive ou na nuvem. A hospedagem é gratuita e não garante a recuperação
        dos dados: se a conta for suspensa, este é o único resguardo.
        Os arquivos abrem direto no Excel.
    </p>

    <ul class="lista-painel">
        <?php foreach ($tabelas as $nome => $dados): ?>
            <li>
                <span class="lista-nome">
                    <?= htmlspecialchars($dados['rotulo']) ?>
                    <small><?= htmlspecialchars($dados['descricao']) ?></small>
                </span>

                <span class="lista-hora"><?= $dados['registros'] ?> registro(s)</span>

                <a href="Backup.php?tabela=<?= urlencode($nome) ?>" class="btn btn-secondary btn-sm">
                    ⬇ Baixar CSV
                </a>
            </li>
        <?php endforeach; ?>
    </ul>
</div>

<div class="card" style="margin-top:25px;">
    <h3>ℹ️ Sobre o sistema</h3>

    <ul class="lista-painel">
        <li>
            <span class="lista-nome">
                Fuso horário
                <small>Horário de Brasília, aplicado ao PHP e ao banco</small>
            </span>
            <strong class="lista-valor"><?= date('d/m/Y H:i') ?></strong>
        </li>
        <li>
            <span class="lista-nome">
                Usuário conectado
                <small>Sessão atual</small>
            </span>
            <strong class="lista-valor"><?= htmlspecialchars($_SESSION['usuario'] ?? '') ?></strong>
        </li>
    </ul>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
