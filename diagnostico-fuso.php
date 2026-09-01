<?php
// Arquivo temporário de diagnóstico — removido no commit seguinte.
require_once __DIR__ . '/Conexao.php';
require_once __DIR__ . '/includes/configuracao.php';
header('Content-Type: application/json; charset=utf-8');

$r = $conn->query("SELECT NOW() AS agora_mysql, CURDATE() AS hoje_mysql,
                          @@session.time_zone AS tz_sessao")->fetch(PDO::FETCH_ASSOC);

echo json_encode([
    'php_timezone' => date_default_timezone_get(),
    'php_agora'    => date('Y-m-d H:i:s'),
    'mysql'        => $r,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
