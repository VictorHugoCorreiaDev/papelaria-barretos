<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../Conexao.php';
require_once __DIR__ . '/../includes/configuracao.php';
require_once __DIR__ . '/../includes/sessao.php';

/*
 * Gestão de usuários: criar, trocar a própria senha, redefinir a de outra
 * pessoa e excluir.
 *
 * Fica DENTRO do sistema, e não como "criar conta" na tela de login: o
 * site é público, e cadastro aberto deixaria qualquer pessoa na internet
 * ver faturamento, lucro e clientes. Só quem já entrou cria novos acessos.
 *
 * Todas as ações exigem o token CSRF, inclusive criar usuário: um
 * formulário forjado em outro site, aberto por quem está logado, criaria
 * um acesso para o atacante.
 */

const SENHA_MINIMA = 8;

$voltar = 'Usuarios.php';

function voltarCom($tipo, $mensagem)
{
    global $voltar;
    $_SESSION['toast'] = ['type' => $tipo, 'message' => $mensagem];
    header('Location: ' . $voltar);
    exit;
}

/*
 * Nome de usuário: letras sem acento, números, ponto, hífen e sublinhado.
 * Sem espaço nem acento porque é digitado no login, muitas vezes no
 * celular, e "joão" e "joao" virarem dois usuários diferentes só confunde.
 */
function nomeUsuarioValido($nome)
{
    return preg_match('/^[a-z0-9._-]{3,50}$/', $nome) === 1;
}

// O sistema nunca pode ficar sem administrador: ninguém mais conseguiria
// criar usuários, ver o financeiro ou desfazer o erro
function totalAdmins(PDO $conn)
{
    return (int) $conn->query("SELECT COUNT(*) FROM usuarios WHERE perfil = 'admin'")->fetchColumn();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    exigirCsrf($voltar);

    $acao = $_POST['acao'] ?? '';
    $senha = (string) ($_POST['senha'] ?? '');
    $confirmacao = (string) ($_POST['confirmacao'] ?? '');

    // Regras de senha comuns a criar, trocar e redefinir
    $problemaSenha = null;
    if (in_array($acao, ['criar', 'trocar', 'redefinir'], true)) {
        if (mb_strlen($senha) < SENHA_MINIMA) {
            $problemaSenha = 'A senha precisa ter pelo menos ' . SENHA_MINIMA . ' caracteres.';
        } elseif ($senha !== $confirmacao) {
            $problemaSenha = 'A confirmação não é igual à senha.';
        }
    }

    /* CRIAR */
    if ($acao === 'criar') {
        // Minúsculas: o login compara o texto exato, e "Maria" e "maria"
        // seriam dois usuários para a mesma pessoa
        $nome = mb_strtolower(trim((string) ($_POST['usuario'] ?? '')));

        if (!nomeUsuarioValido($nome)) {
            voltarCom('error', 'Use de 3 a 50 caracteres no nome de usuário: letras sem acento, números, ponto, hífen ou sublinhado.');
        }
        if ($problemaSenha) {
            voltarCom('error', $problemaSenha);
        }

        $perfil = perfilValido($_POST['perfil'] ?? null);
        if ($perfil === null) {
            voltarCom('error', 'Escolha o perfil do usuário.');
        }

        try {
            $conn->prepare("INSERT INTO usuarios (usuario, senha, perfil) VALUES (?, ?, ?)")
                ->execute([$nome, password_hash($senha, PASSWORD_DEFAULT), $perfil]);
        } catch (PDOException $e) {
            // 23000: violação do índice único de usuario
            if ($e->getCode() === '23000') {
                voltarCom('error', "Já existe um usuário chamado \"$nome\".");
            }
            throw $e;
        }

        voltarCom('success', "Usuário \"$nome\" criado como " . nomePerfil($perfil) . ". Passe a senha para a pessoa por um canal seguro.");
    }

    /* TROCAR A PRÓPRIA SENHA — pede a atual, como qualquer sistema */
    if ($acao === 'trocar') {
        $stmt = $conn->prepare("SELECT senha FROM usuarios WHERE usuario = ?");
        $stmt->execute([$_SESSION['usuario']]);
        $hashAtual = $stmt->fetchColumn();

        if (!$hashAtual || !password_verify((string) ($_POST['senha_atual'] ?? ''), $hashAtual)) {
            voltarCom('error', 'A senha atual não confere.');
        }
        if ($problemaSenha) {
            voltarCom('error', $problemaSenha);
        }

        $novoHash = password_hash($senha, PASSWORD_DEFAULT);
        $conn->prepare("UPDATE usuarios SET senha = ? WHERE usuario = ?")
            ->execute([$novoHash, $_SESSION['usuario']]);

        // Atualiza a marca desta sessão para ela continuar válida; as outras
        // sessões abertas com a senha antiga caem no próximo acesso
        marcarSessao($novoHash);

        voltarCom('success', 'Sua senha foi alterada. Outros aparelhos conectados com a senha antiga vão pedir login de novo.');
    }

    /* REDEFINIR A SENHA DE OUTRA PESSOA — para quando ela esquece */
    if ($acao === 'redefinir') {
        $id = (int) ($_POST['id'] ?? 0);

        $stmt = $conn->prepare("SELECT usuario FROM usuarios WHERE id = ?");
        $stmt->execute([$id]);
        $alvo = $stmt->fetchColumn();

        if ($alvo === false) {
            voltarCom('error', 'Usuário não encontrado.');
        }
        if ($alvo === $_SESSION['usuario']) {
            voltarCom('error', 'Para a sua própria senha, use "Trocar minha senha", que pede a senha atual.');
        }
        if ($problemaSenha) {
            voltarCom('error', $problemaSenha);
        }

        $conn->prepare("UPDATE usuarios SET senha = ? WHERE id = ?")
            ->execute([password_hash($senha, PASSWORD_DEFAULT), $id]);

        voltarCom('success', "Senha de \"$alvo\" redefinida. Se a pessoa estava conectada, vai precisar entrar de novo.");
    }

    /* EXCLUIR */
    if ($acao === 'excluir') {
        $id = (int) ($_POST['id'] ?? 0);

        $stmt = $conn->prepare("SELECT usuario FROM usuarios WHERE id = ?");
        $stmt->execute([$id]);
        $alvo = $stmt->fetchColumn();

        if ($alvo === false) {
            voltarCom('error', 'Usuário não encontrado.');
        }

        // Excluir a si mesmo deixaria a pessoa trancada fora no meio da tela
        if ($alvo === $_SESSION['usuario']) {
            voltarCom('error', 'Você não pode excluir o próprio usuário.');
        }

        // Quem está nesta tela é admin e não pode se excluir, então sempre
        // sobra um; a checagem fica como garantia se essa regra mudar
        $stmtPerfil = $conn->prepare("SELECT perfil FROM usuarios WHERE id = ?");
        $stmtPerfil->execute([$id]);
        if ($stmtPerfil->fetchColumn() === 'admin' && totalAdmins($conn) <= 1) {
            voltarCom('error', 'O sistema precisa de pelo menos um administrador.');
        }

        $conn->prepare("DELETE FROM usuarios WHERE id = ?")->execute([$id]);

        voltarCom('success', "Usuário \"$alvo\" excluído. Se estava conectado, perdeu o acesso na hora.");
    }

    /* MUDAR O PERFIL — vale no próximo clique da pessoa, sem novo login */
    if ($acao === 'perfil') {
        $id = (int) ($_POST['id'] ?? 0);
        $perfil = perfilValido($_POST['perfil'] ?? null);

        $stmt = $conn->prepare("SELECT usuario, perfil FROM usuarios WHERE id = ?");
        $stmt->execute([$id]);
        $alvo = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$alvo || $perfil === null) {
            voltarCom('error', 'Usuário ou perfil inválido.');
        }

        // Rebaixar a si mesmo tiraria a pessoa desta tela no meio da ação
        if ($alvo['usuario'] === $_SESSION['usuario']) {
            voltarCom('error', 'Você não pode mudar o próprio perfil.');
        }

        if ($alvo['perfil'] === 'admin' && $perfil !== 'admin' && totalAdmins($conn) <= 1) {
            voltarCom('error', 'O sistema precisa de pelo menos um administrador.');
        }

        $conn->prepare("UPDATE usuarios SET perfil = ? WHERE id = ?")->execute([$perfil, $id]);

        voltarCom('success', "\"{$alvo['usuario']}\" agora é " . nomePerfil($perfil) . '.');
    }

    voltarCom('error', 'Ação desconhecida.');
}

$usuarios = $conn->query("SELECT id, usuario, perfil FROM usuarios ORDER BY perfil = 'admin' DESC, usuario")->fetchAll(PDO::FETCH_ASSOC);

require_once __DIR__ . '/../includes/header.php';
?>

<h2>Usuários</h2>

<p class="periodo-atual">
    Quem pode entrar no sistema. O <strong>Vendedor</strong> registra vendas, consulta o
    estoque e imprime comprovantes, mas não vê custo nem lucro e não cancela vendas.
    O <strong>Administrador</strong> vê e faz tudo, inclusive esta tela.
</p>

<!-- LISTA -->
<div class="card">
    <h3>👥 Usuários com acesso</h3>

    <ul class="lista-painel">
        <?php foreach ($usuarios as $u):
            $ehVoce = $u['usuario'] === $_SESSION['usuario'];
        ?>
            <li>
                <span class="lista-nome">
                    <?= htmlspecialchars($u['usuario']) ?>
                    <small><?= htmlspecialchars(nomePerfil($u['perfil'])) ?><?= $ehVoce ? ' · você' : '' ?></small>
                </span>

                <?php if (!$ehVoce): ?>
                    <?php $outroPerfil = $u['perfil'] === 'admin' ? 'vendedor' : 'admin'; ?>
                    <form method="POST" class="form-inline"
                        onsubmit="return confirm(<?= htmlspecialchars(json_encode('Tornar ' . $u['usuario'] . ' ' . nomePerfil($outroPerfil) . '? Vale no próximo clique da pessoa.')) ?>)">
                        <?= campoCsrf() ?>
                        <input type="hidden" name="acao" value="perfil">
                        <input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
                        <input type="hidden" name="perfil" value="<?= $outroPerfil ?>">
                        <button type="submit" class="btn btn-secondary btn-sm">
                            Tornar <?= htmlspecialchars(nomePerfil($outroPerfil)) ?>
                        </button>
                    </form>

                    <form method="POST" class="form-inline"
                        onsubmit="return confirm(<?= htmlspecialchars(json_encode('Excluir o usuário ' . $u['usuario'] . '? Ele perde o acesso na hora.')) ?>)">
                        <?= campoCsrf() ?>
                        <input type="hidden" name="acao" value="excluir">
                        <input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
                        <button type="submit" class="btn btn-danger btn-sm">Excluir</button>
                    </form>
                <?php endif; ?>
            </li>
        <?php endforeach; ?>
    </ul>
</div>

<div class="usuarios-grade">

    <!-- CRIAR -->
    <div class="card">
        <h3>➕ Novo usuário</h3>

        <form method="POST" autocomplete="off">
            <?= campoCsrf() ?>
            <input type="hidden" name="acao" value="criar">

            <div class="form-group">
                <label for="novoUsuario">Nome de usuário</label>
                <input type="text" id="novoUsuario" name="usuario" required
                    minlength="3" maxlength="50" pattern="[a-zA-Z0-9._\-]{3,50}"
                    title="Letras sem acento, números, ponto, hífen ou sublinhado"
                    placeholder="ex.: maria" autocapitalize="none" spellcheck="false">
                <small class="custo-atual">Sem espaço nem acento. É o que a pessoa digita para entrar.</small>
            </div>

            <div class="form-group">
                <label for="novoPerfil">Perfil</label>
                <select id="novoPerfil" name="perfil" required>
                    <?php foreach (perfis() as $chave => $rotulo): ?>
                        <option value="<?= htmlspecialchars($chave) ?>"><?= htmlspecialchars($rotulo) ?></option>
                    <?php endforeach; ?>
                </select>
                <small class="custo-atual">Vendedor vende e consulta; Administrador vê tudo, inclusive lucro.</small>
            </div>

            <div class="form-group">
                <label for="novaSenha">Senha</label>
                <input type="password" id="novaSenha" name="senha" required
                    minlength="<?= SENHA_MINIMA ?>" autocomplete="new-password">
                <small class="custo-atual">Pelo menos <?= SENHA_MINIMA ?> caracteres.</small>
            </div>

            <div class="form-group">
                <label for="novaConfirmacao">Confirme a senha</label>
                <input type="password" id="novaConfirmacao" name="confirmacao" required
                    minlength="<?= SENHA_MINIMA ?>" autocomplete="new-password">
            </div>

            <button type="submit" class="btn btn-success">Criar usuário</button>
        </form>
    </div>

    <!-- TROCAR A PRÓPRIA SENHA -->
    <div class="card">
        <h3>🔑 Trocar minha senha</h3>

        <form method="POST">
            <?= campoCsrf() ?>
            <input type="hidden" name="acao" value="trocar">

            <div class="form-group">
                <label for="senhaAtual">Senha atual</label>
                <input type="password" id="senhaAtual" name="senha_atual" required autocomplete="current-password">
            </div>

            <div class="form-group">
                <label for="minhaSenha">Nova senha</label>
                <input type="password" id="minhaSenha" name="senha" required
                    minlength="<?= SENHA_MINIMA ?>" autocomplete="new-password">
            </div>

            <div class="form-group">
                <label for="minhaConfirmacao">Confirme a nova senha</label>
                <input type="password" id="minhaConfirmacao" name="confirmacao" required
                    minlength="<?= SENHA_MINIMA ?>" autocomplete="new-password">
            </div>

            <button type="submit" class="btn btn-primary">Trocar senha</button>
        </form>
    </div>

    <?php if (count($usuarios) > 1): ?>
        <!-- REDEFINIR A DE OUTRA PESSOA -->
        <div class="card">
            <h3>♻️ Redefinir senha de outro usuário</h3>

            <form method="POST" autocomplete="off">
                <?= campoCsrf() ?>
                <input type="hidden" name="acao" value="redefinir">

                <div class="form-group">
                    <label for="redefinirQuem">Usuário</label>
                    <select id="redefinirQuem" name="id" required>
                        <option value="">Selecione</option>
                        <?php foreach ($usuarios as $u): ?>
                            <?php if ($u['usuario'] !== $_SESSION['usuario']): ?>
                                <option value="<?= (int) $u['id'] ?>"><?= htmlspecialchars($u['usuario']) ?></option>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </select>
                    <small class="custo-atual">Para quando a pessoa esquecer a senha.</small>
                </div>

                <div class="form-group">
                    <label for="redefinirSenha">Nova senha</label>
                    <input type="password" id="redefinirSenha" name="senha" required
                        minlength="<?= SENHA_MINIMA ?>" autocomplete="new-password">
                </div>

                <div class="form-group">
                    <label for="redefinirConfirmacao">Confirme a nova senha</label>
                    <input type="password" id="redefinirConfirmacao" name="confirmacao" required
                        minlength="<?= SENHA_MINIMA ?>" autocomplete="new-password">
                </div>

                <button type="submit" class="btn btn-secondary">Redefinir senha</button>
            </form>
        </div>
    <?php endif; ?>

</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
