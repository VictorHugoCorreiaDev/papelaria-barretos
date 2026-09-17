<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/validacao.php';
require_once __DIR__ . '/../includes/pagamento.php';
require_once __DIR__ . '/../includes/despesa.php';
require_once __DIR__ . '/../Conexao.php';
require_once __DIR__ . '/../includes/configuracao.php';

/*
 * Lançamento e listagem numa tela só: uma despesa é um registro curto e
 * frequente, e mandar o usuário para outra página a cada lançamento seria
 * atrito sem ganho. O tratamento do POST vem antes do header.php porque
 * termina em redirect.
 */

if (isset($_POST['lancar'])) {

    $descricao = trim($_POST['descricao'] ?? '');
    $categoria = categoriaDespesaValida($_POST['categoria'] ?? '');
    $valor = valorMonetario($_POST['valor'] ?? null);
    $data = dataValida($_POST['data_despesa'] ?? null, '');
    $formaPagamento = formaPagamentoValida($_POST['forma_pagamento'] ?? '');
    $observacao = trim($_POST['observacao'] ?? '');

    if ($descricao === '' || $valor === null || $valor <= 0 || $data === '') {
        $_SESSION['toast'] = [
            'type' => 'error',
            'message' => 'Informe descrição, um valor maior que zero e a data da despesa.'
        ];

        header('Location: Despesas.php');
        exit;
    }

    $conn->prepare("
        INSERT INTO despesas (descricao, categoria, valor, data_despesa, forma_pagamento, observacao)
        VALUES (?, ?, ?, ?, ?, ?)
    ")->execute([$descricao, $categoria, $valor, $data, $formaPagamento, $observacao]);

    $_SESSION['toast'] = [
        'type' => 'success',
        'message' => 'Despesa lançada com sucesso!'
    ];

    header('Location: Despesas.php');
    exit;
}

/* FILTRO DE PERÍODO — por padrão, o mês corrente */

$dataInicio = dataValida($_GET['inicio'] ?? null, date('Y-m-01'));
$dataFim = dataValida($_GET['fim'] ?? null, date('Y-m-t'));

/* RESUMO DO PERÍODO */

$stmtResumo = $conn->prepare("
    SELECT COUNT(*) AS lancamentos, COALESCE(SUM(valor), 0) AS total
    FROM despesas
    WHERE data_despesa BETWEEN :inicio AND :fim
");
$stmtResumo->execute([':inicio' => $dataInicio, ':fim' => $dataFim]);
$resumo = $stmtResumo->fetch(PDO::FETCH_ASSOC);

$totalDespesas = (float) $resumo['total'];
$quantidadeDespesas = (int) $resumo['lancamentos'];

/* TOTAL POR CATEGORIA — é o que mostra onde o dinheiro está indo */

$stmtCategorias = $conn->prepare("
    SELECT categoria, COUNT(*) AS lancamentos, COALESCE(SUM(valor), 0) AS total
    FROM despesas
    WHERE data_despesa BETWEEN :inicio AND :fim
    GROUP BY categoria
    ORDER BY total DESC
");
$stmtCategorias->execute([':inicio' => $dataInicio, ':fim' => $dataFim]);
$porCategoria = $stmtCategorias->fetchAll(PDO::FETCH_ASSOC);

/* LISTAGEM */

$stmtLista = $conn->prepare("
    SELECT *
    FROM despesas
    WHERE data_despesa BETWEEN :inicio AND :fim
    ORDER BY data_despesa DESC, id DESC
");
$stmtLista->execute([':inicio' => $dataInicio, ':fim' => $dataFim]);
$despesas = $stmtLista->fetchAll(PDO::FETCH_ASSOC);

require_once __DIR__ . '/../includes/header.php';
?>

<h2>Despesas</h2>

<p class="periodo-atual">
    Gastos do negócio — aluguel, energia, fornecedores e o que mais sair do caixa.
    São eles que separam a margem das vendas do <strong>resultado real</strong> do mês.
</p>

<!-- LANÇAMENTO -->
<div class="card">
    <h3>➕ Lançar despesa</h3>

    <form method="POST" class="form-despesa">

        <div class="form-group">
            <label>Descrição</label>
            <input type="text" name="descricao" maxlength="255" required
                placeholder="Ex.: Conta de luz de setembro">
        </div>

        <div class="despesa-linha">
            <div class="form-group">
                <label>Categoria</label>
                <select name="categoria">
                    <?php foreach (categoriasDespesa() as $chave => $rotulo): ?>
                        <option value="<?= htmlspecialchars($chave) ?>"
                            <?= $chave === 'outros' ? 'selected' : '' ?>>
                            <?= htmlspecialchars($rotulo) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label>Valor</label>
                <input type="number" step="0.01" min="0.01" name="valor" required placeholder="0,00">
            </div>

            <div class="form-group">
                <label>Data</label>
                <input type="date" name="data_despesa" value="<?= date('Y-m-d') ?>" required>
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
        </div>

        <div class="form-group">
            <label>Observação <small style="color: var(--text-gray);">(opcional)</small></label>
            <input type="text" name="observacao" maxlength="500"
                placeholder="Número da nota, vencimento, o que ajudar depois">
        </div>

        <button type="submit" name="lancar" class="btn btn-success">
            Lançar despesa
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
        <h3>💸 Total no período</h3>
        <span>R$ <?= number_format($totalDespesas, 2, ',', '.') ?></span>
        <small class="comparativo"><?= $quantidadeDespesas ?> lançamento(s)</small>
    </div>

    <?php
    // As três maiores categorias ao lado do total: é a leitura que interessa
    foreach (array_slice($porCategoria, 0, 3) as $cat):
        $fatia = $totalDespesas > 0 ? ($cat['total'] / $totalDespesas) * 100 : 0;
    ?>
        <div class="card indicador">
            <h3><?= htmlspecialchars(nomeCategoriaDespesa($cat['categoria'])) ?></h3>
            <span>R$ <?= number_format($cat['total'], 2, ',', '.') ?></span>
            <small class="comparativo"><?= number_format($fatia, 1, ',', '.') ?>% do total</small>
        </div>
    <?php endforeach; ?>

</div>

<!-- LISTAGEM -->
<div class="card" style="margin-top:25px;">

    <h3>📋 Lançamentos do período</h3>

    <?php if (empty($despesas)): ?>
        <p style="color: var(--text-gray);">Nenhuma despesa lançada neste período.</p>
    <?php else: ?>
        <table>
            <thead>
                <tr>
                    <th>Data</th>
                    <th>Descrição</th>
                    <th>Categoria</th>
                    <th>Pagamento</th>
                    <th>Valor</th>
                    <th>Ações</th>
                </tr>
            </thead>

            <tbody>
                <?php foreach ($despesas as $d): ?>
                    <tr>
                        <td><?= date('d/m/Y', strtotime($d['data_despesa'])) ?></td>

                        <td>
                            <?= htmlspecialchars($d['descricao']) ?>
                            <?php if (trim((string) $d['observacao']) !== ''): ?>
                                <small style="display:block; color: var(--text-gray);">
                                    <?= htmlspecialchars($d['observacao']) ?>
                                </small>
                            <?php endif; ?>
                        </td>

                        <td><?= htmlspecialchars(nomeCategoriaDespesa($d['categoria'])) ?></td>
                        <td><?= htmlspecialchars(nomeFormaPagamento($d['forma_pagamento'])) ?></td>
                        <td>R$ <?= number_format($d['valor'], 2, ',', '.') ?></td>

                        <td>
                            <a href="ExcluirDespesa.php?id=<?= (int) $d['id'] ?>"
                                onclick="return confirm('Deseja excluir esta despesa?')"
                                class="btn btn-danger btn-sm">
                                Excluir
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
