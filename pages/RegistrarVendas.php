<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/pagamento.php';
require_once __DIR__ . '/../includes/validacao.php';
require_once __DIR__ . '/../Conexao.php';
require_once __DIR__ . '/../includes/configuracao.php';

// Todos os blocos que alteram estado abaixo terminam em
// header("Location: ...") — por isso o header.php só é incluído no fim,
// depois que nenhum redirecionamento é mais possível.

if (!isset($_SESSION['carrinho'])) {
    $_SESSION['carrinho'] = [];
}

/*ADICIONAR PRODUTO AO CARRINHO */
if (isset($_POST['adicionar'])) {

    $produto_id = (int) ($_POST['produto_id'] ?? 0);
    $quantidade = quantidadeInteira($_POST['quantidade'] ?? null, 1);

    // Zero ou negativo passava na checagem de estoque (10 >= -5 é verdade) e
    // uma quantidade negativa chegava a somar ao estoque na finalização
    if ($quantidade === null) {
        $_SESSION['toast'] = [
            'type' => 'error',
            'message' => 'Informe uma quantidade de pelo menos 1 unidade.'
        ];
        header("Location: RegistrarVendas.php");
        exit;
    }

    $stmt = $conn->prepare("SELECT * FROM produtos WHERE id = ?");
    $stmt->execute([$produto_id]);
    $produto = $stmt->fetch();

    if (!$produto) {
        $_SESSION['toast'] = [
            'type' => 'error',
            'message' => 'Produto não encontrado.'
        ];
        header("Location: RegistrarVendas.php");
        exit;
    }

    /*
     * Conta o que já está no carrinho deste mesmo produto.
     *
     * Sem isso, cada adição era conferida sozinha contra o estoque: com 3
     * unidades disponíveis dava para adicionar 3, e depois mais 3, porque
     * as duas checagens passavam. O estoque terminava negativo.
     */
    $jaNoCarrinho = 0;
    $posicaoExistente = null;

    foreach ($_SESSION['carrinho'] as $indice => $item) {
        if ((int) $item['produto_id'] === $produto['id']) {
            $jaNoCarrinho += (int) $item['quantidade'];
            $posicaoExistente = $indice;
        }
    }

    if ($produto['quantidade'] < $jaNoCarrinho + $quantidade) {
        $_SESSION['toast'] = [
            'type' => 'error',
            'message' => $jaNoCarrinho > 0
                ? "Estoque insuficiente! Restam {$produto['quantidade']} unidade(s) e o carrinho já tem {$jaNoCarrinho}."
                : "Estoque insuficiente! Restam {$produto['quantidade']} unidade(s)."
        ];
        header("Location: RegistrarVendas.php");
        exit;
    }

    // Mesmo produto soma na linha que já existe, em vez de repetir no carrinho
    if ($posicaoExistente !== null) {
        $_SESSION['carrinho'][$posicaoExistente]['quantidade'] += $quantidade;
    } else {
        $_SESSION['carrinho'][] = [
            'produto_id' => $produto['id'],
            'nome' => $produto['nome'],
            'preco' => $produto['preco'],
            'custo' => $produto['custo'],
            'quantidade' => $quantidade
        ];
    }

    $_SESSION['toast'] = [
        'type' => 'success',
        'message' => 'Produto adicionado ao carrinho!'
    ];

    header("Location: RegistrarVendas.php");
    exit;
}


/* REMOVER ITEM DO CARRINHO */

if (isset($_GET['remover'])) {
    $index = (int) $_GET['remover'];

    unset($_SESSION['carrinho'][$index]);
    $_SESSION['carrinho'] = array_values($_SESSION['carrinho']);

    // Redireciona para tirar o ?remover= da URL — sem isso, atualizar a
    // página remove outro item do carrinho
    header("Location: RegistrarVendas.php");
    exit;
}


/* FINALIZAR VENDA */

if (isset($_POST['finalizar'])) {

    if (empty($_SESSION['carrinho'])) {
        $_SESSION['toast'] = [
            'type' => 'warning',
            'message' => 'Carrinho vazio!'
        ];
        header("Location: RegistrarVendas.php");
        exit;
    } else {

        try {
            $conn->beginTransaction();

            $subtotal = 0;

            foreach ($_SESSION['carrinho'] as $item) {
                $subtotal += $item['preco'] * $item['quantidade'];
            }

            /*
             * O desconto vem do formulário em reais — o campo de percentual
             * é só uma comodidade do navegador, que converte para reais
             * antes de enviar. Recalcular aqui a partir do subtotal impede
             * que um valor montado fora da tela zere ou inverta a venda.
             */
            $desconto = valorMonetario($_POST['desconto'] ?? 0) ?? 0;
            $desconto = min($desconto, $subtotal);

            $totalVenda = $subtotal - $desconto;

            // Criar venda
            $cliente = trim($_POST['cliente'] ?? '');
            $formaPagamento = formaPagamentoValida($_POST['forma_pagamento'] ?? '');

            $stmt = $conn->prepare("INSERT INTO vendas (total, desconto, cliente, forma_pagamento) VALUES (?, ?, ?, ?)");
            $stmt->execute([$totalVenda, $desconto, $cliente, $formaPagamento]);

            $venda_id = $conn->lastInsertId();

            // Registrar itens
            foreach ($_SESSION['carrinho'] as $item) {

                $conn->prepare("INSERT INTO vendas_produtos
                    (venda_id, produto_id, quantidade, preco_unitario, custo_unitario)
                    VALUES (?, ?, ?, ?, ?)")
                    ->execute([
                        $venda_id,
                        $item['produto_id'],
                        $item['quantidade'],
                        $item['preco'],
                        // Carrinhos abertos antes desta mudança não têm custo
                        $item['custo'] ?? 0
                    ]);

                /*
                 * O WHERE quantidade >= ? faz a checagem e a baixa no mesmo
                 * comando: se o estoque não cobrir, nenhuma linha é afetada e
                 * a venda inteira volta atrás.
                 *
                 * É o que protege a janela entre montar o carrinho e fechar a
                 * venda — nesse intervalo uma venda rápida pode ter consumido
                 * o estoque, e a validação feita na adição já estaria velha.
                 */
                $baixa = $conn->prepare("UPDATE produtos
                    SET quantidade = quantidade - ?
                    WHERE id = ? AND quantidade >= ?");

                $baixa->execute([
                    $item['quantidade'],
                    $item['produto_id'],
                    $item['quantidade']
                ]);

                if ($baixa->rowCount() === 0) {
                    throw new Exception(
                        'Estoque insuficiente para ' . $item['nome'] . '.'
                    );
                }
            }

            $conn->commit();
            $_SESSION['carrinho'] = [];
            $_SESSION['toast'] = [
                'type' => 'success',
                'message' => 'Venda finalizada com sucesso!'
            ];
            header("Location: RegistrarVendas.php");
            exit;
        } catch (Exception $e) {
            $conn->rollBack();

            /*
             * A mensagem do estoque insuficiente diz qual produto travou a
             * venda; qualquer outra falha é interna e não ajuda o operador.
             * O carrinho é mantido para ele ajustar e tentar de novo.
             */
            $_SESSION['toast'] = [
                'type' => 'error',
                'message' => str_starts_with($e->getMessage(), 'Estoque insuficiente')
                    ? $e->getMessage() . ' A venda não foi registrada.'
                    : 'Erro ao finalizar venda!'
            ];

            header("Location: RegistrarVendas.php");
            exit;
        }
    }
}


// Buscar produtos para o select
$produtos = $conn->query("SELECT * FROM produtos")->fetchAll();

require_once __DIR__ . '/../includes/header.php';
?>

<h2>Registrar Venda</h2>




<!-- CARD FORM -->
<div class="card">

    <form method="POST">

        <div class="form-group">
            <label>Produto</label>

            <input type="text" id="buscaProdutoCarrinho" class="busca-produto"
                placeholder="🔎 Digite para filtrar..." autocomplete="off">

            <select name="produto_id" id="produtoCarrinho" required>
                <option value="">Selecione</option>
                <?php foreach ($produtos as $p): ?>
                    <option value="<?= (int) $p['id'] ?>"
                        data-estoque="<?= (int) $p['quantidade'] ?>">
                        <?= htmlspecialchars($p['nome']) ?> (Estoque: <?= (int) $p['quantidade'] ?>)
                    </option>
                <?php endforeach; ?>
            </select>

            <small class="contador-produtos" id="contadorProdutosCarrinho"></small>
        </div>

        <div class="form-group">
            <label>Quantidade</label>
            <input type="number" id="quantidade" name="quantidade" min="1" required>
        </div>

        <button type="submit" name="adicionar" class="btn btn-primary">
            Adicionar ao Carrinho
        </button>

    </form>

</div>


<!-- CARRINHO -->
<div class="card" style="margin-top: 25px;">

    <h3>Carrinho</h3>

    <?php if (!empty($_SESSION['carrinho'])): ?>

        <table>
            <thead>
                <tr>
                    <th>Produto</th>
                    <th>Qtde</th>
                    <th>Preço</th>
                    <th>Subtotal</th>
                    <th>Ação</th>
                </tr>
            </thead>

            <tbody>
                <?php
                $total = 0;
                foreach ($_SESSION['carrinho'] as $index => $item):
                    $subtotal = $item['preco'] * $item['quantidade'];
                    $total += $subtotal;
                ?>
                    <tr>
                        <td><?= htmlspecialchars($item['nome']) ?></td>
                        <td><?= (int) $item['quantidade'] ?></td>
                        <td>R$ <?= number_format($item['preco'], 2, ',', '.') ?></td>
                        <td>R$ <?= number_format($subtotal, 2, ',', '.') ?></td>
                        <td>
                            <a href="?remover=<?= $index ?>"
                                class="btn btn-danger btn-sm">
                                Remover
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <div class="total-box">
            Total: <strong>R$ <?= number_format($total, 2, ',', '.') ?></strong>
        </div>

        <form method="POST" class="form-finalizar">

            <div class="form-group">
                <label>Cliente <small style="color: var(--text-gray);">(opcional)</small></label>
                <input type="text" name="cliente" maxlength="120" placeholder="Cliente avulso">
            </div>

            <div class="form-group">
                <label>Forma de pagamento</label>
                <select name="forma_pagamento">
                    <option value="">Não informada</option>
                    <?php foreach (formasPagamento() as $chave => $rotulo): ?>
                        <option value="<?= htmlspecialchars($chave) ?>"><?= htmlspecialchars($rotulo) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!--
              Os dois campos de desconto são espelhos: preencher um calcula o
              outro. Só o valor em reais é enviado; o percentual existe porque
              é assim que boa parte da negociação acontece no balcão.
              O subtotal vai no data- para o JS não precisar ler texto formatado.
            -->
            <div class="form-group desconto-campos" data-subtotal="<?= $total ?>">
                <label>Desconto <small style="color: var(--text-gray);">(opcional)</small></label>

                <div class="desconto-linha">
                    <span class="desconto-prefixo">R$</span>
                    <input type="number" step="0.01" min="0" max="<?= $total ?>"
                        name="desconto" id="descontoValor" placeholder="0,00">

                    <span class="desconto-prefixo">ou</span>
                    <input type="number" step="0.1" min="0" max="100"
                        id="descontoPercentual" placeholder="0">
                    <span class="desconto-prefixo">%</span>
                </div>

                <small class="desconto-resumo" id="descontoResumo"></small>
            </div>

            <button type="submit" name="finalizar" class="btn btn-success">
                Finalizar Venda
            </button>
        </form>

    <?php else: ?>
        <p style="color: var(--text-gray);">Carrinho vazio.</p>
    <?php endif; ?>

</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
