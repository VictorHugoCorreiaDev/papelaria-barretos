<?php
// Arquivo temporário de diagnóstico — remover depois de usar.
require_once __DIR__ . '/Conexao.php';
header('Content-Type: application/json; charset=utf-8');

$r = $conn->query("SELECT NOW() AS agora_mysql, @@session.time_zone AS tz_sessao,
                          @@global.time_zone AS tz_global, @@system_time_zone AS tz_sistema")
          ->fetch(PDO::FETCH_ASSOC);

echo json_encode([
    'php_timezone'  => date_default_timezone_get(),
    'php_agora'     => date('Y-m-d H:i:s'),
    'mysql'         => $r,
    'ultima_venda'  => $conn->query("SELECT id, created_at FROM vendas ORDER BY id DESC LIMIT 1")
                            ->fetch(PDO::FETCH_ASSOC),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
