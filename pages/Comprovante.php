<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/pagamento.php';
require_once __DIR__ . '/../Conexao.php';
require_once __DIR__ . '/../includes/configuracao.php';

/*
 * Comprovante de uma venda, para imprimir ou mostrar ao cliente.
 *
 * Página avulsa, sem header.php nem footer.php: o comprovante imita um
 * cupom de papel e não leva sidebar, menu nem tema escuro. Por isso também
 * não aplica o data-theme salvo — fica sempre claro, como o papel.
 *
 * Não é documento fiscal (NFC-e) e diz isso no rodapé: sem o aviso, um
 * cupom com cara de nota poderia ser tomado por uma.
 */

$id = (int) ($_GET['id'] ?? 0);

$stmt = $conn->prepare("SELECT * FROM vendas WHERE id = ?");
$stmt->execute([$id]);
$venda = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$venda) {
    $_SESSION['toast'] = ['type' => 'error', 'message' => 'Venda não encontrada.'];
    header('Location: ListarVendas.php');
    exit;
}

// Preço congelado na venda, não o preço atual do produto
$stmtItens = $conn->prepare("
    SELECT p.nome, vp.quantidade, vp.preco_unitario
    FROM vendas_produtos vp
    JOIN produtos p ON p.id = vp.produto_id
    WHERE vp.venda_id = ?
    ORDER BY vp.id
");
$stmtItens->execute([$id]);
$itens = $stmtItens->fetchAll(PDO::FETCH_ASSOC);

// total é o líquido; o bruto é total + desconto (veja o CLAUDE.md)
$desconto = (float) $venda['desconto'];
$total = (float) $venda['total'];
$subtotal = $total + $desconto;
$cancelada = $venda['status'] !== 'ativa';

$versaoCss = @filemtime(__DIR__ . '/../assets/css/style.css') ?: '1';

// Volta para onde a pessoa estava; sem referência, para a lista de vendas
$voltar = 'ListarVendas.php';
$origem = $_SERVER['HTTP_REFERER'] ?? '';
if ($origem !== '' && parse_url($origem, PHP_URL_HOST) === ($_SERVER['HTTP_HOST'] ?? '')) {
    $voltar = $origem;
}
?>
<!DOCTYPE html>
<html lang="pt-BR">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Comprovante #<?= (int) $venda['id'] ?> — Bazar e Papelaria Barretos</title>
    <link rel="stylesheet" href="/assets/css/style.css?v=<?= $versaoCss ?>">
</head>

<body class="pagina-comprovante">

    <div class="comprovante-acoes">
        <a href="<?= htmlspecialchars($voltar) ?>" class="btn btn-secondary">Voltar</a>
        <button type="button" class="btn btn-primary" onclick="window.print()">Imprimir</button>
    </div>

    <main class="comprovante">

        <header class="comprovante-loja">
            <strong>Bazar e Papelaria Barretos</strong>
            <span>Comprovante de venda</span>
        </header>

        <?php if ($cancelada): ?>
            <p class="comprovante-cancelada">Venda cancelada</p>
        <?php endif; ?>

        <dl class="comprovante-dados">
            <dt>Venda</dt>
            <dd>#<?= (int) $venda['id'] ?></dd>

            <dt>Data</dt>
            <dd><?= date('d/m/Y H:i', strtotime($venda['created_at'])) ?></dd>

            <dt>Cliente</dt>
            <dd><?= htmlspecialchars(nomeCliente($venda['cliente'])) ?></dd>
        </dl>

        <table class="comprovante-itens">
            <thead>
                <tr>
                    <th>Item</th>
                    <th>Valor</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($itens as $item): ?>
                    <tr>
                        <td>
                            <?= htmlspecialchars($item['nome']) ?>
                            <small>
                                <?= (int) $item['quantidade'] ?> ×
                                R$ <?= number_format($item['preco_unitario'], 2, ',', '.') ?>
                            </small>
                        </td>
                        <td>R$ <?= number_format($item['quantidade'] * $item['preco_unitario'], 2, ',', '.') ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <dl class="comprovante-totais">
            <?php if ($desconto > 0): ?>
                <dt>Subtotal</dt>
                <dd>R$ <?= number_format($subtotal, 2, ',', '.') ?></dd>

                <dt>Desconto</dt>
                <dd>− R$ <?= number_format($desconto, 2, ',', '.') ?></dd>
            <?php endif; ?>

            <dt class="comprovante-total">Total</dt>
            <dd class="comprovante-total">R$ <?= number_format($total, 2, ',', '.') ?></dd>

            <dt>Pagamento</dt>
            <dd><?= htmlspecialchars(nomeFormaPagamento($venda['forma_pagamento'])) ?></dd>
        </dl>

        <footer class="comprovante-rodape">
            <p>Obrigado pela preferência!</p>
            <p>Documento sem valor fiscal</p>
        </footer>

    </main>

</body>

</html>
