<?php

/*
 * Validade da sessão contra o banco.
 *
 * Só ter $_SESSION['usuario'] não basta: um usuário excluído continuaria
 * entrando até fechar o navegador, e depois de uma troca de senha a
 * sessão aberta com a senha antiga seguiria valendo. Por isso as duas
 * guardas (auth.php e auth_ajax.php) conferem, a cada acesso, se o
 * usuário ainda existe e se a senha é a mesma do momento do login.
 *
 * A senha não fica na sessão: fica uma "marca", o hash SHA-256 do hash
 * bcrypt. Ela muda sempre que a senha muda, e não serve para nada fora
 * daqui.
 */

function marcaDaSenha($hashSenha)
{
    return hash('sha256', (string) $hashSenha);
}

/**
 * Grava na sessão a marca da senha atual. Chamado no login e quando a
 * própria pessoa troca a senha, para ela não ser desconectada.
 */
function marcarSessao($hashSenha)
{
    $_SESSION['senha_marca'] = marcaDaSenha($hashSenha);
}

/**
 * true se o usuário da sessão ainda existe e a senha não mudou.
 */
function sessaoContinuaValida(PDO $conn)
{
    $stmt = $conn->prepare("SELECT senha FROM usuarios WHERE usuario = ?");
    $stmt->execute([$_SESSION['usuario'] ?? '']);
    $hash = $stmt->fetchColumn();

    if ($hash === false) {
        return false;
    }

    // Sessões abertas antes desta verificação existir não têm a marca:
    // recebem a atual, em vez de derrubar quem já estava trabalhando
    if (!isset($_SESSION['senha_marca'])) {
        marcarSessao($hash);
        return true;
    }

    return hash_equals($_SESSION['senha_marca'], marcaDaSenha($hash));
}

function encerrarSessao()
{
    $_SESSION = [];
    session_destroy();
}
