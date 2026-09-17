<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../Conexao.php';
require_once __DIR__ . '/../includes/configuracao.php';

/*
 * Exporta uma tabela inteira em CSV, para backup.
 *
 * A hospedagem é gratuita: se a conta for suspensa ou o banco se perder,
 * todo o histórico vai junto e não há de onde recuperar. Estes arquivos
 * são a cópia que fica com o dono do negócio.
 *
 * Não inclui header nem footer — a resposta é arquivo, não página. A
 * listagem das tabelas fica no Configuracoes.php.
 */

$tabelasPermitidas = ['produtos', 'vendas', 'vendas_produtos', 'despesas'];

$tabela = $_GET['tabela'] ?? '';

/*
 * A tabela entra no SQL por interpolação (não dá para parametrizar nome de
 * tabela), então só um valor da lista acima pode passar daqui.
 */
if (!in_array($tabela, $tabelasPermitidas, true)) {
    $_SESSION['toast'] = ['type' => 'error', 'message' => 'Tabela inválida para exportação.'];
    header('Location: Configuracoes.php');
    exit;
}

$linhas = $conn->query("SELECT * FROM `$tabela` ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);

$nomeArquivo = 'backup-' . $tabela . '-' . date('Y-m-d') . '.csv';

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $nomeArquivo . '"');
header('Cache-Control: no-store');

$saida = fopen('php://output', 'w');

// BOM para o Excel abrir em UTF-8 e não quebrar os acentos
fwrite($saida, "\xEF\xBB\xBF");

$sep = ';';

if (empty($linhas)) {
    fputcsv($saida, ['Nenhum registro nesta tabela'], $sep);
    fclose($saida);
    exit;
}

// Cabeçalho a partir das colunas reais, para o backup acompanhar o schema
fputcsv($saida, array_keys($linhas[0]), $sep);

foreach ($linhas as $linha) {
    fputcsv($saida, array_values($linha), $sep);
}

fclose($saida);
