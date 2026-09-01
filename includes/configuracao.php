<?php

/*
 * Fuso horário da aplicação.
 *
 * A hospedagem roda com o PHP em America/New_York e o MySQL no fuso do
 * Pacífico, então tanto o CURRENT_TIMESTAMP das tabelas quanto o date()
 * das telas saíam horas atrasados em relação ao horário de Brasília.
 *
 * Precisa ser incluído logo DEPOIS do Conexao.php, porque ajusta também
 * a sessão do MySQL — é ela que define o valor gravado em created_at.
 */

date_default_timezone_set('America/Sao_Paulo');

if (isset($conn) && $conn instanceof PDO) {
    /*
     * Offset fixo em vez do nome "America/Sao_Paulo": os nomes dependem
     * das tabelas de fuso do MySQL, que costumam estar vazias em
     * hospedagem compartilhada. O Brasil não adota horário de verão desde
     * 2019, então -03:00 vale o ano inteiro.
     */
    $conn->exec("SET time_zone = '-03:00'");
}
