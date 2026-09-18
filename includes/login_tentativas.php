<?php

/*
 * Limite de tentativas na tela de login.
 *
 * Sem ele, a tela aceita quantas senhas o atacante quiser testar, na
 * velocidade que a rede permitir — e com uma senha curta isso termina em
 * minutos. O bcrypt do password_verify() torna cada tentativa cara, mas
 * caro não é o mesmo que impossível.
 *
 * A contagem fica no banco, não na sessão: quem está adivinhando senha
 * descartaria o cookie a cada tentativa e zeraria o contador.
 *
 * O bloqueio é por IP, não por usuário. Bloquear o usuário deixaria
 * qualquer pessoa trancar a dona da loja para fora da própria papelaria,
 * só errando a senha dela cinco vezes.
 */

const LOGIN_MAX_TENTATIVAS = 5;
const LOGIN_JANELA_MINUTOS = 15;

/**
 * IP de quem está pedindo. Usa apenas o REMOTE_ADDR: os cabeçalhos de
 * proxy (X-Forwarded-For e afins) são escritos pelo próprio cliente e
 * anulariam o limite, bastando mandar um valor diferente a cada tentativa.
 */
function ipDaRequisicao()
{
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

/**
 * Quantos minutos ainda faltam para liberar, ou 0 se não há bloqueio.
 */
function minutosDeBloqueio(PDO $conn)
{
    $stmt = $conn->prepare("
        SELECT COUNT(*) AS falhas, MIN(created_at) AS primeira
        FROM tentativas_login
        WHERE ip = ? AND created_at > (NOW() - INTERVAL " . LOGIN_JANELA_MINUTOS . " MINUTE)
    ");
    $stmt->execute([ipDaRequisicao()]);
    $linha = $stmt->fetch(PDO::FETCH_ASSOC);

    if ((int) $linha['falhas'] < LOGIN_MAX_TENTATIVAS) {
        return 0;
    }

    // A janela corre a partir da tentativa mais antiga ainda contada
    $liberaEm = strtotime($linha['primeira']) + LOGIN_JANELA_MINUTOS * 60;
    $faltam = (int) ceil(($liberaEm - time()) / 60);

    return max($faltam, 1);
}

/**
 * Registra uma falha e aproveita para descartar o que já é história.
 * A limpeza aqui evita depender de tarefa agendada, que a hospedagem
 * compartilhada não oferece.
 */
function registrarFalhaDeLogin(PDO $conn, $usuario)
{
    $conn->prepare("INSERT INTO tentativas_login (usuario, ip) VALUES (?, ?)")
        ->execute([mb_substr((string) $usuario, 0, 100), ipDaRequisicao()]);

    $conn->exec("DELETE FROM tentativas_login WHERE created_at < (NOW() - INTERVAL 1 DAY)");
}

/**
 * Login deu certo: o IP volta a ter as tentativas cheias.
 */
function limparTentativasDeLogin(PDO $conn)
{
    $conn->prepare("DELETE FROM tentativas_login WHERE ip = ?")
        ->execute([ipDaRequisicao()]);
}
