<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/validacao.php';
require_once __DIR__ . '/../Conexao.php';
require_once __DIR__ . '/../includes/configuracao.php';

/*
 * Exporta em CSV o mesmo período que o Relatorios.php está mostrando.
 *
 * Não inclui o header.php: a resposta é um arquivo para download, não uma
 * página. Qualquer saída antes dos cabeçalhos abaixo corromperia o arquivo.
 */

$dataInicio = dataValida($_GET['inicio'] ?? null, date('Y-m-d'));
$dataFim = dataValida($_GET['fim'] ?? null, date('Y-m-d'));

$sql = "
    SELECT v.id, v.total, v.created_at, v.status,
        COALESCE((
            SELECT SUM(vp.quantidade * vp.custo_unitario)
            FROM vendas_produtos vp
            WHERE vp.venda_id = v.id
        ), 0) AS custo
    FROM vendas v
    WHERE DATE(v.created_at) BETWEEN :inicio AND :fim
    ORDER BY v.created_at
";

$stmt = $conn->prepare($sql);
$stmt->execute([':inicio' => $dataInicio, ':fim' => $dataFim]);
$vendas = $stmt->fetchAll(PDO::FETCH_ASSOC);

$nomeArquivo = 'relatorio-' . $dataInicio . '-a-' . $dataFim . '.csv';

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $nomeArquivo . '"');
header('Cache-Control: no-store');

$saida = fopen('php://output', 'w');

// BOM: sem ele o Excel abre o arquivo em ANSI e os acentos saem quebrados
fwrite($saida, "\xEF\xBB\xBF");

/*
 * Ponto e vírgula como separador e vírgula decimal: é o que o Excel em
 * português reconhece sem pedir configuração de importação.
 */
$sep = ';';

fputcsv($saida, ['ID', 'Data', 'Total', 'Custo', 'Lucro', 'Status'], $sep);

$totalFaturado = 0;
$totalCusto = 0;
$vendasAtivas = 0;

foreach ($vendas as $v) {
    $ativa = $v['status'] === 'ativa';
    $lucro = $v['total'] - $v['custo'];

    if ($ativa) {
        $totalFaturado += $v['total'];
        $totalCusto += $v['custo'];
        $vendasAtivas++;
    }

    fputcsv($saida, [
        $v['id'],
        date('d/m/Y H:i', strtotime($v['created_at'])),
        number_format($v['total'], 2, ',', ''),
        // Canceladas não têm lucro a realizar; o custo também não se aplica
        $ativa ? number_format($v['custo'], 2, ',', '') : '',
        $ativa ? number_format($lucro, 2, ',', '') : '',
        $ativa ? 'Ativa' : 'Cancelada',
    ], $sep);
}

// Linha de fechamento, considerando só as ativas — mesmo critério dos cards
fputcsv($saida, [], $sep);
fputcsv($saida, [
    'TOTAL (' . $vendasAtivas . ' venda(s) ativa(s))',
    '',
    number_format($totalFaturado, 2, ',', ''),
    number_format($totalCusto, 2, ',', ''),
    number_format($totalFaturado - $totalCusto, 2, ',', ''),
    '',
], $sep);

fclose($saida);
