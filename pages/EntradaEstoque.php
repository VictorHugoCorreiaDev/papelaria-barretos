<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/validacao.php';
require_once __DIR__ . '/../Conexao.php';
require_once __DIR__ . '/../includes/configuracao.php';

/*
 * Entrada de estoque: registra cada compra ou reposição e soma ao saldo do
 * produto. Lançamento e histórico na mesma tela, como em Despesas.
 *
 * Compra de mercadoria NÃO é despesa. O custo do produto já é descontado do
 * lucro quando ele é vendido (custo_unitario em vendas_produtos); lançar a
 * compra também como despesa contaria o mesmo dinheiro duas vezes.
 */

if (isset($_POST['registrar'])) {

    $produtoId = (int) ($_POST['produto_id'] ?? 0);
    $quantidade = quantidadeInteira($_POST['quantidade'] ?? null, 1);
    $data = dataValida($_POST['data_entrada'] ?? null, '');
    $fornecedor = mb_substr(trim((string) ($_POST['fornecedor'] ?? '')), 0, 120);
    $observacao = mb_substr(trim((string) ($_POST['observacao'] ?? '')), 0, 255);

    // Custo em branco é "não informado", não zero: zero gravaria no produto
    // um custo que faria o lucro parecer igual ao faturamento
    $custoInformado = trim((string) ($_POST['custo_unitario'] ?? '')) !== '';
    $custo = $custoInformado ? valorMonetario($_POST['custo_unitario']) : null;
    $atualizarCusto = isset($_POST['atualizar_custo']);

    $voltar = 'EntradaEstoque.php';

    if ($produtoId <= 0 || $quantidade === null || $data === '' || ($custoInformado && ($custo === null || $custo <= 0))) {
        $_SESSION['toast'] = [
            'type' => 'error',
            'message' => 'Escolha o produto e informe uma quantidade inteira a partir de 1 e a data. O custo, se informado, precisa ser maior que zero.'
        ];
        header('Location: ' . $voltar);
        exit;
    }

    try {
        $conn->beginTransaction();

        $conn->prepare("
            INSERT INTO entradas_estoque (produto_id, quantidade, custo_unitario, fornecedor, data_entrada, observacao)
            VALUES (?, ?, ?, ?, ?, ?)
        ")->execute([
            $produtoId, $quantidade, $custo,
            $fornecedor !== '' ? $fornecedor : null,
            $data,
            $observacao !== '' ? $observacao : null,
        ]);

        // Soma ao saldo no próprio UPDATE, sem ler antes: uma venda no meio
        // do caminho não se perde
        $soma = $conn->prepare("UPDATE produtos SET quantidade = quantidade + ? WHERE id = ?");
        $soma->execute([$quantidade, $produtoId]);

        if ($soma->rowCount() === 0) {
            throw new Exception('Produto não encontrado');
        }

        /*
         * O custo desta compra passa a ser o custo do produto, se a pessoa
         * deixou marcado. É o preço mais recente, o que melhor representa o
         * que vai custar repor. Vendas já feitas não mudam: guardam o custo
         * da época em vendas_produtos.
         */
        if ($custo !== null && $atualizarCusto) {
            $conn->prepare("UPDATE produtos SET custo = ? WHERE id = ?")
                ->execute([$custo, $produtoId]);
        }

        $conn->commit();

        $_SESSION['toast'] = [
            'type' => 'success',
            'message' => "Entrada de $quantidade unidade(s) registrada."
        ];
    } catch (Exception $e) {
        $conn->rollBack();
        $_SESSION['toast'] = ['type' => 'error', 'message' => 'Não foi possível registrar a entrada.'];
    }

    header('Location: ' . $voltar);
    exit;
}

/* PRODUTOS PARA O SELECT — todos, inclusive os zerados, que são os que mais precisam de entrada */

$produtos = $conn->query("SELECT id, nome, quantidade, custo FROM produtos ORDER BY nome")->fetchAll(PDO::FETCH_ASSOC);

// Vindo do botão "Entrada" do estoque, o produto já chega escolhido
$produtoEscolhido = (int) ($_GET['produto'] ?? 0);

/* FILTRO DE PERÍODO — por padrão, o mês corrente */

$dataInicio = dataValida($_GET['inicio'] ?? null, date('Y-m-01'));
$dataFim = dataValida($_GET['fim'] ?? null, date('Y-m-t'));

if ($dataInicio > $dataFim) {
    [$dataInicio, $dataFim] = [$dataFim, $dataInicio];
}

/* RESUMO DO PERÍODO */

$stmtResumo = $conn->prepare("
    SELECT
        COUNT(*) AS lancamentos,
        COALESCE(SUM(quantidade), 0) AS unidades,
        COALESCE(SUM(quantidade * custo_unitario), 0) AS investido,
        SUM(custo_unitario IS NULL) AS sem_custo
    FROM entradas_estoque
    WHERE data_entrada BETWEEN :inicio AND :fim
");
$stmtResumo->execute([':inicio' => $dataInicio, ':fim' => $dataFim]);
$resumo = $stmtResumo->fetch(PDO::FETCH_ASSOC);

$quantidadeEntradas = (int) $resumo['lancamentos'];
$unidadesEntradas = (int) $resumo['unidades'];
$investido = (float) $resumo['investido'];
$entradasSemCusto = (int) $resumo['sem_custo'];

/* LISTAGEM PAGINADA */

$limit = 15;
$page = isset($_GET['page']) ? (int) $_GET['page'] : 1;
$page = max($page, 1);

$totalPaginas = (int) ceil($quantidadeEntradas / $limit);

if ($page > $totalPaginas && $totalPaginas > 0) {
    $page = $totalPaginas;
}

$offset = ($page - 1) * $limit;

$stmtLista = $conn->prepare("
    SELECT e.*, p.nome
    FROM entradas_estoque e
    LEFT JOIN produtos p ON p.id = e.produto_id
    WHERE e.data_entrada BETWEEN :inicio AND :fim
    ORDER BY e.data_entrada DESC, e.id DESC
    LIMIT :limit OFFSET :offset
");
$stmtLista->bindValue(':inicio', $dataInicio);
$stmtLista->bindValue(':fim', $dataFim);
$stmtLista->bindValue(':limit', $limit, PDO::PARAM_INT);
$stmtLista->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmtLista->execute();
$entradas = $stmtLista->fetchAll(PDO::FETCH_ASSOC);

require_once __DIR__ . '/../includes/header.php';
?>

<h2>Entrada de Estoque</h2>

<p class="periodo-atual">
    Registre aqui cada compra ou reposição: a quantidade é somada ao estoque
    e fica o histórico de quando entrou, de quem foi comprado e por quanto.
</p>

<!-- LANÇAMENTO -->
<div class="card">
    <h3>📦 Registrar entrada</h3>

    <form method="POST" class="form-despesa">

        <div class="form-group">
            <label for="produtoEntrada">Produto</label>

            <input type="text" id="buscaProdutoEntrada" class="busca-produto"
                placeholder="🔎 Digite para filtrar..." autocomplete="off">

            <select name="produto_id" id="produtoEntrada" required>
                <option value="">Selecione</option>
                <?php foreach ($produtos as $p): ?>
                    <option value="<?= (int) $p['id'] ?>"
                        data-custo="<?= htmlspecialchars($p['custo']) ?>"
                        <?= (int) $p['id'] === $produtoEscolhido ? 'selected' : '' ?>>
                        <?= htmlspecialchars($p['nome']) ?> (Estoque: <?= (int) $p['quantidade'] ?>)
                    </option>
                <?php endforeach; ?>
            </select>

            <small class="contador-produtos" id="contadorProdutosEntrada"></small>
        </div>

        <div class="despesa-linha">
            <div class="form-group">
                <label for="quantidadeEntrada">Quantidade</label>
                <input type="number" id="quantidadeEntrada" name="quantidade" min="1" step="1" required>
            </div>

            <div class="form-group">
                <label for="custoEntrada">Custo unitário <small style="color: var(--text-gray);">(opcional)</small></label>
                <input type="number" id="custoEntrada" name="custo_unitario" min="0.01" step="0.01" placeholder="0,00">
            </div>

            <div class="form-group">
                <label for="dataEntrada">Data</label>
                <input type="date" id="dataEntrada" name="data_entrada" value="<?= date('Y-m-d') ?>" required>
            </div>

            <div class="form-group">
                <label for="fornecedorEntrada">Fornecedor <small style="color: var(--text-gray);">(opcional)</small></label>
                <input type="text" id="fornecedorEntrada" name="fornecedor" maxlength="120">
            </div>
        </div>

        <div class="form-group">
            <label class="opcao-marcar">
                <input type="checkbox" name="atualizar_custo" value="1" checked>
                Usar este custo como o custo do produto daqui em diante
            </label>
            <small class="custo-atual" id="custoAtualEntrada"></small>
        </div>

        <div class="form-group">
            <label for="observacaoEntrada">Observação <small style="color: var(--text-gray);">(opcional)</small></label>
            <input type="text" id="observacaoEntrada" name="observacao" maxlength="255"
                placeholder="Número da nota, prazo de pagamento, o que ajudar depois">
        </div>

        <button type="submit" name="registrar" class="btn btn-success">
            Registrar entrada
        </button>

    </form>
</div>

<!-- FILTRO DE PERÍODO -->
<div class="card" style="margin-top:25px;">
    <form method="GET" style="display:flex; gap:20px; align-items:flex-end; flex-wrap:wrap;">

        <div class="form-group" style="margin:0;">
            <label>Data Início</label>
            <input type="date" name="inicio" value="<?= htmlspecialchars($dataInicio) ?>">
        </div>

        <div class="form-group" style="margin:0;">
            <label>Data Fim</label>
            <input type="date" name="fim" value="<?= htmlspecialchars($dataFim) ?>">
        </div>

        <div>
            <button type="submit" class="btn btn-primary">Filtrar</button>
        </div>

    </form>
</div>

<!-- RESUMO -->
<div class="cards-grid" style="margin-top:25px;">

    <div class="card indicador">
        <h3>📦 Unidades que entraram</h3>
        <span><?= $unidadesEntradas ?></span>
        <small class="comparativo"><?= $quantidadeEntradas ?> lançamento(s)</small>
    </div>

    <div class="card indicador">
        <h3>🛒 Investido em mercadoria</h3>
        <span>R$ <?= number_format($investido, 2, ',', '.') ?></span>
        <small class="comparativo">
            <?php if ($entradasSemCusto > 0): ?>
                <?= $entradasSemCusto ?> entrada(s) sem custo informado ficam fora da soma
            <?php else: ?>
                não entra nas despesas: o custo já sai do lucro na venda
            <?php endif; ?>
        </small>
    </div>

</div>

<!-- LISTAGEM -->
<div class="card" style="margin-top:25px;">

    <h3>📋 Entradas do período</h3>

    <?php if (empty($entradas)): ?>
        <p style="color: var(--text-gray);">Nenhuma entrada registrada neste período.</p>
    <?php else: ?>
        <table>
            <thead>
                <tr>
                    <th>Data</th>
                    <th>Produto</th>
                    <th>Quantidade</th>
                    <th>Custo unitário</th>
                    <th>Total</th>
                    <th>Fornecedor</th>
                    <th>Ações</th>
                </tr>
            </thead>

            <tbody>
                <?php foreach ($entradas as $e): ?>
                    <tr>
                        <td><?= date('d/m/Y', strtotime($e['data_entrada'])) ?></td>

                        <td>
                            <?= htmlspecialchars($e['nome'] ?? 'Produto excluído') ?>
                            <?php if (trim((string) $e['observacao']) !== ''): ?>
                                <small style="display:block; color: var(--text-gray);">
                                    <?= htmlspecialchars($e['observacao']) ?>
                                </small>
                            <?php endif; ?>
                        </td>

                        <td><?= (int) $e['quantidade'] ?></td>

                        <?php if ($e['custo_unitario'] === null): ?>
                            <td style="color: var(--text-gray);">—</td>
                            <td style="color: var(--text-gray);">—</td>
                        <?php else: ?>
                            <td>R$ <?= number_format($e['custo_unitario'], 2, ',', '.') ?></td>
                            <td>R$ <?= number_format($e['quantidade'] * $e['custo_unitario'], 2, ',', '.') ?></td>
                        <?php endif; ?>

                        <td><?= htmlspecialchars($e['fornecedor'] ?? '—') ?></td>

                        <td>
                            <!-- Desfazer tira do estoque o que esta entrada colocou -->
                            <form method="POST" action="ExcluirEntrada.php" class="form-inline"
                                onsubmit="return confirm('Desfazer esta entrada? As unidades saem do estoque. O custo do produto não volta ao anterior.')">
                                <?= campoCsrf() ?>
                                <input type="hidden" name="id" value="<?= (int) $e['id'] ?>">
                                <button type="submit" class="btn btn-danger btn-sm">Desfazer</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <?php if ($totalPaginas > 1): ?>
            <div class="paginacao">
                <?php
                // O período viaja nos links, senão a página 2 mostraria outro intervalo
                $filtro = '?inicio=' . urlencode($dataInicio) . '&fim=' . urlencode($dataFim);
                ?>

                <?php if ($page > 1): ?>
                    <a href="<?= $filtro ?>&page=<?= $page - 1 ?>" class="pag-btn">«</a>
                <?php endif; ?>

                <?php for ($i = 1; $i <= $totalPaginas; $i++): ?>
                    <a href="<?= $filtro ?>&page=<?= $i ?>"
                        class="pag-btn <?= $i == $page ? 'active' : '' ?>"><?= $i ?></a>
                <?php endfor; ?>

                <?php if ($page < $totalPaginas): ?>
                    <a href="<?= $filtro ?>&page=<?= $page + 1 ?>" class="pag-btn">»</a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    <?php endif; ?>

</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
