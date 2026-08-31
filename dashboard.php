<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/Conexao.php';
require_once __DIR__ . '/includes/header.php';

// Total de vendas (ativas)
$stmt = $conn->query("
    SELECT COUNT(*) 
    FROM vendas 
    WHERE status = 'ativa'
");
$totalVendas = $stmt->fetchColumn();

// Receita total (ativas)
$stmt = $conn->query("
    SELECT COALESCE(SUM(total), 0)
    FROM vendas
    WHERE status = 'ativa'
");
$totalReceita = $stmt->fetchColumn();

// Receita hoje (ativas)
$stmt = $conn->query("
    SELECT COALESCE(SUM(total), 0)
    FROM vendas
    WHERE status = 'ativa'
    AND DATE(created_at) = CURDATE()
");
$receitaHoje = $stmt->fetchColumn();

// Ticket médio
$ticketMedio = $totalVendas > 0
    ? $totalReceita / $totalVendas
    : 0;

?>

<!-- Linha Cards -->
<h2>Dashboard</h2>

<div class="cards-grid">

    <!--
      Os ids abaixo são o que o atualizarCards() do funcoes.js procura depois
      de uma venda rápida. Sem eles a atualização via AJAX não fazia nada.
      Os valores de dinheiro já saem daqui com "R$" porque o JS os reescreve
      com Intl.NumberFormat, que também traz o símbolo — assim o card não muda
      de formato entre o carregamento da página e a atualização.
    -->

    <div class="card indicador">
        <h3>📄 Vendas</h3>
        <span id="cardVendas"><?= $totalVendas ?></span>
    </div>

    <div class="card indicador">
        <h3>💰 Receita Total</h3>
        <span id="cardReceitaTotal">R$ <?= number_format($totalReceita, 2, ',', '.') ?></span>
    </div>

    <div class="card indicador">
        <h3>💵 Ticket Médio</h3>
        <span id="cardTicketMedio">R$ <?= number_format($ticketMedio, 2, ',', '.') ?></span>
    </div>

    <div class="card indicador">
        <h3>📅 Receita Hoje</h3>
        <span id="cardReceitaHoje">R$ <?= number_format($receitaHoje ?? 0, 2, ',', '.') ?></span>
    </div>

</div>


<!--Linha Registrar Vendas -->
<div class="card venda-box">

    <h3>💰 Registrar Venda Rápida</h3>

    <form id="formVenda" method="POST">

        <div class="form-group">
            <label>Selecione um Produto</label>
            <select name="produto_id" id="produto" class="input">
                <?php
                $produtos = $conn->query("SELECT id, nome, preco, quantidade FROM produtos");
                foreach ($produtos as $p):
                ?>
                    <option
                        value="<?= (int) $p['id'] ?>"
                        data-preco="<?= htmlspecialchars($p['preco']) ?>"
                        data-estoque="<?= (int) $p['quantidade'] ?>">
                        <?= htmlspecialchars($p['nome']) ?> (Estoque: <?= (int) $p['quantidade'] ?>)
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="info-linha">
            Valor Unitário:
            <!-- O atualizarValores() escreve aqui com Intl.NumberFormat, que já
                 traz o "R$". O prefixo fixo duplicava o símbolo. -->
            <strong><span id="valorUnitario">R$ 0,00</span></strong>
        </div>

        <div class="form-group">
            <label>Quantidade</label>
            <input type="number"
                id="quantidade"
                name="quantidade"
                class="input"
                min="1">
        </div>

        <div class="total-box">
            Total:
            <strong><span id="totalVenda">R$ 0,00</span></strong>
        </div>

        <button type="submit" class="btn btn-primary btn-full">
            Registrar Venda
        </button>

    </form>

</div>


</div>

<?php include 'includes/footer.php'; ?>