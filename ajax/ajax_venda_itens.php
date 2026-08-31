<?php
require_once __DIR__ . '/../includes/auth_ajax.php';
require_once __DIR__ . '/../Conexao.php';

$id = (int) ($_GET['id'] ?? 0);

$sql = "
SELECT p.nome, vp.quantidade, vp.preco_unitario
FROM vendas_produtos vp
JOIN produtos p ON p.id = vp.produto_id
WHERE vp.venda_id = ?
";

$stmt = $conn->prepare($sql);
$stmt->execute([$id]);

if ($stmt->rowCount() == 0) {
  echo "Nenhum item encontrado.";
  exit;
}

echo "<table style='width:100%; border-collapse: collapse;'>";

echo "<tr>
        <th>Produto</th>
        <th>Qtd</th>
        <th>Preço</th>
      </tr>";

$totalVenda = 0;

while ($item = $stmt->fetch()) {

  $subtotal = $item['quantidade'] * $item['preco_unitario'];
  $totalVenda += $subtotal;

  // O nome vem do banco e é injetado via innerHTML pelo verItens()
  $nome = htmlspecialchars($item['nome']);

  echo "<tr>
        <td>{$nome}</td>
        <td>" . (int) $item['quantidade'] . "</td>
        <td>R$ " . number_format($item['preco_unitario'], 2, ',', '.') . "</td>
    </tr>";
}

echo "</table>";
echo "<h3>Total: R$ " . number_format($totalVenda, 2, ',', '.') . "</h3>";  