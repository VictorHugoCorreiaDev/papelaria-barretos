<?php

/*
 * Modelo de configuração do banco.
 *
 * Copie este arquivo para Conexao.php e preencha com os dados locais:
 *
 *     cp Conexao.exemplo.php Conexao.php
 *
 * O Conexao.php é ignorado pelo Git de propósito — ele guarda a senha e
 * não deve ir para o repositório. Este modelo existe para documentar quais
 * valores o sistema espera.
 *
 * As tabelas que o código pressupõe estão descritas no CLAUDE.md.
 */

$host = "localhost";
$db = "nome_do_banco";
$user = "usuario";
$pass = "senha";

try {
    $conn = new PDO(
        "mysql:host=$host;dbname=$db;charset=utf8",
        $user,
        $pass
    );
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Erro na conexão: " . $e->getMessage());
}
