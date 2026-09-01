<?php
// Temporário: apenas LEITURA, para planejar a correção do histórico.
require_once __DIR__ . '/Conexao.php';
require_once __DIR__ . '/includes/configuracao.php';
header('Content-Type: application/json; charset=utf-8');

/*
 * O created_at foi gravado no fuso do Pacífico, que muda com o horário de
 * verão americano: PST (UTC-8, 5h de diferença para Brasília) até
 * 08/03/2026, PDT (UTC-7, 4h) a partir daí.
 */
$corte = '2026-03-08 02:00:00';

echo json_encode([
    'total'   => (int) $conn->query("SELECT COUNT(*) FROM vendas")->fetchColumn(),
    'periodo' => $conn->query("SELECT MIN(created_at) AS mais_antiga, MAX(created_at) AS mais_recente FROM vendas")
                      ->fetch(PDO::FETCH_ASSOC),
    'faixa_pst_somar_5h' => (int) $conn->query("SELECT COUNT(*) FROM vendas WHERE created_at < '$corte'")->fetchColumn(),
    'faixa_pdt_somar_4h' => (int) $conn->query("SELECT COUNT(*) FROM vendas WHERE created_at >= '$corte'")->fetchColumn(),
    // Vendas que mudariam de DIA ao corrigir (indicam relatórios diários afetados)
    'mudariam_de_dia' => (int) $conn->query("
        SELECT COUNT(*) FROM vendas
        WHERE DATE(created_at) <> DATE(DATE_ADD(created_at, INTERVAL IF(created_at < '$corte', 5, 4) HOUR))
    ")->fetchColumn(),
    'amostra' => $conn->query("SELECT id, created_at,
                                 DATE_ADD(created_at, INTERVAL IF(created_at < '$corte', 5, 4) HOUR) AS corrigido
                               FROM vendas ORDER BY id DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC),
    'backup' => $conn->query("SELECT id, created_at FROM vendas ORDER BY id")->fetchAll(PDO::FETCH_ASSOC),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
