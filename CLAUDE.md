# CLAUDE.md

Este arquivo fornece orientações ao Claude Code (claude.ai/code) ao trabalhar com o código deste repositório.

## Idioma

Este projeto é integralmente em português do Brasil. Respostas, comentários de código, mensagens de commit e documentação devem ser escritos em pt-BR. Mantenha nomes técnicos existentes (arquivos, classes CSS, colunas do banco) como estão.

## Visão geral

Sistema de vendas e estoque para uma papelaria ("Papelaria Barretos"). PHP 8 puro com PDO/MySQL — sem framework, sem Composer, sem etapa de build, sem suíte de testes. Identificadores, textos de interface e comentários estão todos em português.

## Executando

```bash
php -S localhost:8000
```

Precisa ser servido a partir da raiz do projeto: `includes/sidebar.php`, `includes/header.php` e `assets/js/funcoes.js` usam caminhos absolutos a partir da raiz (`/pages/...`, `/assets/...`, `${BASE_URL}/ajax/...`). Servir de um subdiretório quebra a navegação e o AJAX, a menos que `BASE_URL` (definida inline em `includes/header.php`) aponte para o subcaminho.

Não há linter, executor de testes nem gerenciador de dependências. A verificação é manual, pelo navegador.

## Banco de dados

`Conexao.php` cria o handle PDO `$conn` (host/db/usuário/senha fixos no código, `ERRMODE_EXCEPTION`). O arquivo está listado no `.gitignore`, mas existe na árvore de trabalho — trate-o como configuração local, não como algo a versionar.

Não há arquivo de schema no repositório. As tabelas que o código pressupõe:

- `usuarios(usuario, senha)` — `senha` é um hash bcrypt de `password_hash()`, verificado em `login.php`. Não existe tela de cadastro; usuários precisam ser inseridos manualmente.
- `produtos(id, nome, preco, quantidade)` — `quantidade` é o estoque corrente.
- `vendas(id, total, created_at, status)` — `status` é `'ativa'` ou `'cancelada'`; vendas nunca são excluídas, apenas marcadas como canceladas.
- `vendas_produtos(venda_id, produto_id, quantidade, preco_unitario)` — `preco_unitario` congela o preço no momento da venda, de modo que totais históricos sobrevivem a alterações de preço.

## Estrutura das páginas

Toda página em `pages/` e o `dashboard.php` seguem o mesmo sanduíche:

```php
require_once __DIR__ . '/../includes/auth.php';     // exige login
require_once __DIR__ . '/../Conexao.php';

// ... tratamento de POST/GET que altera estado e termina em redirect ...
// ... consultas que alimentam a tela ...

require_once __DIR__ . '/../includes/header.php';   // abre <html>, sidebar, .layout > .main > .content
// ... o HTML da página ...
require_once __DIR__ . '/../includes/footer.php';   // fecha .content, .main, .layout e carrega funcoes.js
```

**Nada pode ser impresso antes do último redirecionamento possível.** O `header.php` emite HTML já na primeira linha; qualquer `header('Location: ...')` depois disso falha com "headers already sent" — o redirect não acontece e a página vaza pela metade até o `exit`. Por isso tanto o `auth.php` quanto os blocos que tratam POST vêm antes do `header.php`.

Páginas puramente de leitura (`Estoque.php`, `ListarVendas.php`, `Relatorios.php`) não redirecionam e podem incluir o `header.php` logo após o `Conexao.php`.

Toda página precisa terminar incluindo o `footer.php` — é ele que fecha `.content`, `.main` e `.layout`, emite `</body></html>` e carrega o `funcoes.js`.

`includes/validacao.php` guarda validações reaproveitáveis entre páginas (hoje, a `dataValida()` usada pelo `Relatorios.php`). São funções globais, então inclua sempre com `require_once`.

`auth.php` e `header.php` iniciam a sessão com a guarda `session_status() === PHP_SESSION_NONE`, então podem ser incluídos em qualquer ordem sem gerar aviso de sessão já ativa. O `header.php` também consome e limpa o `$_SESSION['toast']`. Páginas novas não precisam chamar `session_start()`.

A `<div class="layout">` é aberta no `sidebar.php` (incluído pelo header) e fechada no `footer.php` — uma `</div>` desbalanceada em uma página quebra o layout visivelmente.

## Autenticação

Toda rota é protegida, e há duas guardas conforme o tipo de resposta:

- `includes/auth.php` — para páginas HTML. Redireciona para `/login.php` quando não há `$_SESSION['usuario']`. Incluído como **primeira** linha de `dashboard.php`, `index.php` e de todos os arquivos em `pages/`.
- `includes/auth_ajax.php` — para os endpoints em `ajax/`. Responde `401` com JSON em vez de redirecionar; um redirect seria seguido silenciosamente pelo `fetch()` e o JavaScript acabaria injetando a tela de login no modal ou tentando parsear HTML como JSON. O tratamento do 401 é a função `sessaoExpirada()` em `funcoes.js`, que avisa e devolve o usuário ao login.

Ao criar uma página ou endpoint novo, inclua a guarda correspondente antes de qualquer outra coisa. Só `login.php` fica fora (senão não haveria como autenticar).

## Convenções entre páginas

**Toasts** — canal de mensagens de feedback. Defina `$_SESSION['toast'] = ['type' => 'success'|'error'|'warning', 'message' => '...']` e redirecione; o header da próxima página exibe e limpa a mensagem. Toasts no cliente (respostas AJAX) passam por `mostrarToast()` em `funcoes.js`, que escreve no `#toast` vindo do footer.

**Post/Redirect/Get** — toda página que altera dados trata o `$_POST`, define um toast e faz `header("Location: ...")` + `exit`, sempre **antes** de incluir o `header.php` (veja a seção acima). Siga esse padrão em vez de renderizar HTML após um POST. Vale também para alterações disparadas por GET: `RegistrarVendas.php?remover=N` redireciona depois de mexer no carrinho, senão um F5 remove outro item.

**Paginação** — `Estoque.php`, `ListarVendas.php` e `Relatorios.php` repetem o mesmo bloco: `$limit = 10`, `$page`/`$offset` vindos do `$_GET`, uma consulta `COUNT(*)`, `$totalPaginas`, um ajuste quando `$page > $totalPaginas` e então `bindValue(':limit', ..., PDO::PARAM_INT)` (obrigatório — sem isso o prepare emulado colocaria aspas no LIMIT). Os filtros são propagados nos links `.pag-btn`.

## Fluxos de estoque e venda

O estoque é alterado em três lugares, todos dentro de `beginTransaction()`/`commit()`/`rollBack()`:

- `pages/RegistrarVendas.php` — carrinho de vários itens mantido em `$_SESSION['carrinho']`; "Finalizar" insere uma linha em `vendas`, uma linha em `vendas_produtos` por item e decrementa cada produto.
- `ajax/ajax_venda_rapida.php` — venda rápida de item único, a partir do formulário do dashboard; faz as mesmas inserções e devolve JSON `{status, mensagem, novoEstoque, cards}`.
- `pages/CancelarVenda.php` — a única reversão: devolve as quantidades a `produtos` e define `status = 'cancelada'`.

`pages/ExcluirProdutos.php` recusa excluir um produto que apareça em `vendas_produtos` (não há FK com cascade) e redireciona com `?erro=vinculado`.

## Indicadores do dashboard

Os quatro cards são renderizados pelo `dashboard.php` e reescritos pelo `atualizarCards()` do `funcoes.js` depois de cada venda rápida. Três coisas precisam continuar alinhadas:

- Os `<span>` carregam os ids `cardVendas`, `cardReceitaTotal`, `cardReceitaHoje` e `cardTicketMedio` — é por eles que o JS acha os elementos.
- Os valores monetários já saem do servidor com `R$`, porque o JS os reescreve com `Intl.NumberFormat`, que também traz o símbolo. Sem isso o card mudaria de formato entre o carregamento e a atualização.
- O `ajax_venda_rapida.php` recalcula os indicadores com o mesmo `status = 'ativa'` que o `dashboard.php` usa. Divergir aí faz vendas canceladas entrarem só na versão atualizada por AJAX.

## Carregamento do JavaScript

O `footer.php` é o único lugar que carrega o `funcoes.js`, e toda página o inclui ao final. Não acrescente uma tag `<script>` própria: o arquivo registra o listener de submit do `#formVenda` no escopo global, então um segundo carregamento faria a venda rápida ser enviada duas vezes.

## Escape de saída

A regra em vigor, aplicada em todas as telas:

- **Strings vindas do banco ou do usuário** → `htmlspecialchars()`. No PHP 8.4 o padrão da função já cobre aspas simples e duplas, então não é preciso passar flags.
- **Ids e quantidades** → cast `(int)`, que é mais preciso que escapar quando o valor deveria ser numérico — inclusive dentro de `href` e do `onclick="verItens(...)"`.
- **Parâmetros repetidos em links** (`busca`, `status`, `inicio`, `fim`) → `urlencode()`.
- **Contadores calculados no próprio script** (`$i`, `$page`, `$totalRegistros`, `$inicio`/`$fim` da paginação) ficam sem tratamento de propósito — não vêm de fora e escapá-los seria só ruído.
- **`number_format()` e `date()`** já produzem saída segura.

`ajax_venda_itens.php` monta HTML por concatenação de strings e o resultado entra via `innerHTML` no modal, então o nome do produto é escapado antes da interpolação.

No `Relatorios.php`, `inicio` e `fim` vêm da query string e reaparecem nos inputs e nos links de paginação. Em vez de só escapar, a função `dataValida()` rejeita o que não for uma data `Y-m-d` real e cai para a data de hoje — o escape na saída fica como defesa em profundidade.

## Estilos

`assets/css/style.css` é a única folha de estilo e começa com um bloco `:root` de design tokens (`--primary`, `--success`, `--danger`, `--bg-*`, `--text-*`, `--radius-*`). Reaproveite as classes de componente existentes — `.card`, `.cards-grid`, `.indicador`, `.btn` combinado com `.btn-primary`/`.btn-secondary`/`.btn-danger`/`.btn-success`/`.btn-sm`, `.badge-ativa`/`.badge-cancelado`, `.paginacao`/`.pag-btn`, `.modal` — em vez de adicionar estilos inline.

## Formatação

Valores monetários são renderizados no servidor com `number_format($v, 2, ',', '.')` precedidos de `R$`, e no cliente com `Intl.NumberFormat('pt-BR', {style:'currency', currency:'BRL'})`. Datas são exibidas com `date('d/m/Y H:i', strtotime($v['created_at']))`.
