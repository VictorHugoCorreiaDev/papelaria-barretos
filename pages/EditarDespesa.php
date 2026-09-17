<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/validacao.php';
require_once __DIR__ . '/../includes/pagamento.php';
require_once __DIR__ . '/../includes/despesa.php';
require_once __DIR__ . '/../Conexao.php';
require_once __DIR__ . '/../includes/configuracao.php';

$id = (int) ($_GET['id'] ?? 0);

// O tratamento do POST vem antes do header.php: termina em redirect

if ($_POST) {

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

        header('Location: EditarDespesa.php?id=' . $id);
        exit;
    }

    $conn->prepare("
        UPDATE despesas
        SET descricao = ?, categoria = ?, valor = ?, data_despesa = ?,
            forma_pagamento = ?, observacao = ?
        WHERE id = ?
    ")->execute([$descricao, $categoria, $valor, $data, $formaPagamento, $observacao, $id]);

    $_SESSION['toast'] = [
        'type' => 'success',
        'message' => 'Despesa atualizada com sucesso!'
    ];

    header('Location: Despesas.php');
    exit;
}

$stmt = $conn->prepare("SELECT * FROM despesas WHERE id = ?");
$stmt->execute([$id]);
$despesa = $stmt->fetch(PDO::FETCH_ASSOC);

// Id inexistente volta para a listagem, em vez de abrir formulário vazio
if (!$despesa) {
    $_SESSION['toast'] = ['type' => 'error', 'message' => 'Despesa não encontrada.'];
    header('Location: Despesas.php');
    exit;
}

require_once __DIR__ . '/../includes/header.php';
?>

<h2>Editar Despesa</h2>

<div class="card">
    <form method="POST" class="form-despesa">

        <div class="form-group">
            <label>Descrição</label>
            <input type="text" name="descricao" maxlength="255" required
                value="<?= htmlspecialchars($despesa['descricao']) ?>">
        </div>

        <div class="despesa-linha">
            <div class="form-group">
                <label>Categoria</label>
                <select name="categoria">
                    <?php foreach (categoriasDespesa() as $chave => $rotulo): ?>
                        <option value="<?= htmlspecialchars($chave) ?>"
                            <?= $chave === $despesa['categoria'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($rotulo) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label>Valor</label>
                <input type="number" step="0.01" min="0.01" name="valor" required
                    value="<?= htmlspecialchars($despesa['valor']) ?>">
            </div>

            <div class="form-group">
                <label>Data</label>
                <input type="date" name="data_despesa" required
                    value="<?= htmlspecialchars($despesa['data_despesa']) ?>">
            </div>

            <div class="form-group">
                <label>Forma de pagamento</label>
                <select name="forma_pagamento">
                    <option value="">Não informada</option>
                    <?php foreach (formasPagamento() as $chave => $rotulo): ?>
                        <option value="<?= htmlspecialchars($chave) ?>"
                            <?= $chave === $despesa['forma_pagamento'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($rotulo) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div class="form-group">
            <label>Observação <small style="color: var(--text-gray);">(opcional)</small></label>
            <input type="text" name="observacao" maxlength="500"
                value="<?= htmlspecialchars((string) $despesa['observacao']) ?>">
        </div>

        <button type="submit" class="btn btn-success">Salvar</button>
        <a href="Despesas.php" class="btn btn-secondary">Cancelar</a>

    </form>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
