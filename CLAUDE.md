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

Não há arquivo `.sql` de schema, mas o `README.md` traz o DDL das quatro tabelas. As tabelas que o código pressupõe:

- `usuarios(usuario, senha)` — `senha` é um hash bcrypt de `password_hash()`, verificado em `login.php`. Não existe tela de cadastro; usuários precisam ser inseridos manualmente.
- `produtos(id, nome, preco, custo, quantidade, created_at)` — `quantidade` é o estoque corrente; `custo` é o preço de compra, usado para o lucro no dashboard; `created_at` existe na tabela mas nenhuma tela usa.
- `vendas(id, total, desconto, cliente, forma_pagamento, created_at, status)` — `status` é `'ativa'` ou `'cancelada'`; vendas nunca são excluídas, apenas marcadas como canceladas. **`total` é o valor líquido**, o que de fato entrou no caixa: é ele que alimenta faturamento e lucro em todas as telas. O `desconto` fica registrado à parte, para consulta; o valor bruto, quando precisar, é `total + desconto`.
- `vendas_produtos(venda_id, produto_id, quantidade, preco_unitario, custo_unitario)` — congelam preço e custo no momento da venda, de modo que totais e lucros históricos sobrevivem a alterações de preço ou de custo.
- `despesas(id, descricao, categoria, valor, data_despesa, forma_pagamento, observacao, created_at)` — gastos do negócio. Não tem relação com vendas nem com estoque, e por isso pode ser excluída de fato, diferente de venda. As categorias são uma lista fixa em `includes/despesa.php`: texto livre faria "Energia", "energia" e "Luz" virarem três grupos no relatório.
- `tentativas_login(id, usuario, ip, created_at)` — falhas de login recentes, usadas pelo limite de tentativas (veja a seção própria).

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

`pages/ExportarRelatorio.php` é a exceção: devolve um CSV para download, não HTML, então não inclui header nem footer. Qualquer saída antes dos `header()` de `Content-Type`/`Content-Disposition` corromperia o arquivo baixado. A tela de relatórios chega nele por um `<button formaction>`, e não por link, para que a exportação receba as datas que estão nos campos no momento do clique.

`includes/validacao.php` guarda validações reaproveitáveis entre páginas (hoje, a `dataValida()` usada pelo `Relatorios.php`). São funções globais, então inclua sempre com `require_once`.

`includes/configuracao.php` acerta o fuso horário e precisa vir logo **depois** do `Conexao.php` — ele ajusta a sessão do MySQL, então sem a conexão aberta não tem efeito. Todo ponto de entrada que carrega o `Conexao.php` carrega também esse arquivo; ao criar um novo, mantenha o par.

`auth.php` e `header.php` iniciam a sessão com a guarda `session_status() === PHP_SESSION_NONE`, então podem ser incluídos em qualquer ordem sem gerar aviso de sessão já ativa. O `header.php` também consome e limpa o `$_SESSION['toast']`. Páginas novas não precisam chamar `session_start()`.

A `<div class="layout">` é aberta no `sidebar.php` (incluído pelo header) e fechada no `footer.php` — uma `</div>` desbalanceada em uma página quebra o layout visivelmente.

## Autenticação

Toda rota é protegida, e há duas guardas conforme o tipo de resposta:

- `includes/auth.php` — para páginas HTML. Redireciona para `/login.php` quando não há `$_SESSION['usuario']`. Incluído como **primeira** linha de `dashboard.php`, `index.php` e de todos os arquivos em `pages/`.
- `includes/auth_ajax.php` — para os endpoints em `ajax/`. Responde `401` com JSON em vez de redirecionar; um redirect seria seguido silenciosamente pelo `fetch()` e o JavaScript acabaria injetando a tela de login no modal ou tentando parsear HTML como JSON. O tratamento do 401 é a função `sessaoExpirada()` em `funcoes.js`, que avisa e devolve o usuário ao login.

Ao criar uma página ou endpoint novo, inclua a guarda correspondente antes de qualquer outra coisa. Só `login.php` fica fora (senão não haveria como autenticar).

## Limite de tentativas no login

O `includes/login_tentativas.php` bloqueia o login por 15 minutos depois de 5 falhas vindas do mesmo IP. Durante o bloqueio, nem a senha certa entra, senão o limite não serviria de nada. Um login bem-sucedido apaga as falhas daquele IP.

- A contagem fica na tabela `tentativas_login`, e não na sessão: quem tenta adivinhar a senha descartaria o cookie a cada tentativa.
- O bloqueio é **por IP, não por usuário**. Bloquear o usuário deixaria qualquer pessoa trancar a dona da loja para fora do sistema, só errando a senha dela cinco vezes.
- O IP vem só do `REMOTE_ADDR`. Cabeçalhos como `X-Forwarded-For` são escritos pelo próprio cliente, e confiar neles anularia o limite.
- As linhas com mais de um dia são apagadas a cada falha registrada, porque a hospedagem compartilhada não oferece tarefa agendada.

Usuário inexistente e senha errada recebem a mesma mensagem, de propósito.

## Ações destrutivas

Excluir produto, excluir despesa e cancelar venda exigem **POST com o token da sessão**, conferido pelo `exigirCsrf()` de `includes/csrf.php`. Antes eram links GET: abrir `/pages/ExcluirProdutos.php?id=7` bastava para o produto sumir, e o `confirm()` do JavaScript não protege nada disso — ele não roda quando a URL chega por um link colado, por um pré-carregamento do navegador ou por uma `<img>` numa página qualquer.

Na tela, a ação é um `<form method="POST">` com `campoCsrf()`, um `id` escondido e o `confirm()` no `onsubmit`. A classe `.form-inline` deixa o formulário em `display:inline` para ele não quebrar a linha da tabela ao lado do botão "Editar".

Ao criar uma ação que apaga ou reverte alguma coisa, siga o mesmo caminho: `require_once` do `csrf.php`, `exigirCsrf('PaginaDeVolta.php')` logo no início e `$_POST['id']` em vez de `$_GET['id']`. Token inválido ou ausente devolve o usuário à tela com o toast "Ação não confirmada", sem executar nada.

## Convenções entre páginas

**Toasts** — canal de mensagens de feedback. Defina `$_SESSION['toast'] = ['type' => 'success'|'error'|'warning', 'message' => '...']` e redirecione; o header da próxima página exibe e limpa a mensagem. Toasts no cliente (respostas AJAX) passam por `mostrarToast()` em `funcoes.js`, que escreve no `#toast` vindo do footer.

**Post/Redirect/Get** — toda página que altera dados trata o `$_POST`, define um toast e faz `header("Location: ...")` + `exit`, sempre **antes** de incluir o `header.php` (veja a seção acima). Siga esse padrão em vez de renderizar HTML após um POST. Vale também para alterações disparadas por GET: `RegistrarVendas.php?remover=N` redireciona depois de mexer no carrinho, senão um F5 remove outro item.

**Paginação** — `Estoque.php` e `Relatorios.php` repetem o mesmo bloco: `$limit = 10`, `$page`/`$offset` vindos do `$_GET`, uma consulta `COUNT(*)`, `$totalPaginas`, um ajuste quando `$page > $totalPaginas` e então `bindValue(':limit', ..., PDO::PARAM_INT)` (obrigatório — sem isso o prepare emulado colocaria aspas no LIMIT). Os filtros são propagados nos links `.pag-btn`.

O `ListarVendas.php` segue a mesma mecânica, mas **pagina dias, não vendas** (`$diasPorPagina = 7`): a lista é agrupada por data e cada grupo fecha com o total do dia, então paginar por venda partiria um dia entre duas páginas e o fechamento mostraria um total parcial. Ele busca primeiro os dias da página, depois todas as vendas desses dias e os itens de todas elas — três consultas fixas, em vez de uma por venda dentro do laço de exibição.

A tela de vendas também filtra por **período e busca** (cliente, nome de produto ou número da venda, com ou sem `#`). As condições são montadas uma vez, em `$condicoes`, e aplicadas a todas as consultas da tela: se a lista de dias e a de vendas filtrassem de jeitos diferentes, um dia apareceria com vendas que não batem com a busca. Os contadores dos botões Ativas/Canceladas/Todas respeitam período e busca, mas não a própria situação. Com busca ativa, o rodapé do dia vira "Total das vendas encontradas": é a soma de parte do dia, não o fechamento dele. `%` e `_` digitados são escapados antes do `LIKE`.

## Fluxos de estoque e venda

O estoque é alterado em três lugares, todos dentro de `beginTransaction()`/`commit()`/`rollBack()`:

- `pages/RegistrarVendas.php` — carrinho de vários itens mantido em `$_SESSION['carrinho']`; "Finalizar" insere uma linha em `vendas`, uma linha em `vendas_produtos` por item e decrementa cada produto.
- `ajax/ajax_venda_rapida.php` — venda rápida de item único, a partir do formulário do dashboard; faz as mesmas inserções e devolve JSON `{status, mensagem, novoEstoque, cards}`.
- `pages/CancelarVenda.php` — a única reversão: devolve as quantidades a `produtos` e define `status = 'cancelada'`.

`pages/ExcluirProdutos.php` recusa excluir um produto que apareça em `vendas_produtos` (não há FK com cascade) e redireciona com `?erro=vinculado`.

**A baixa de estoque é a própria validação.** Os dois caminhos de venda dão baixa com `UPDATE produtos SET quantidade = quantidade - ? WHERE id = ? AND quantidade >= ?` e conferem o `rowCount()`: zero linhas afetadas significa estoque insuficiente e derruba a transação inteira. Checar antes e atualizar depois deixa uma janela entre as duas coisas — e é por ela que o estoque ficava negativo quando outra venda consumia o saldo no meio do caminho. Não troque por um `UPDATE` sem condição.

No carrinho, a adição valida o **acumulado** (o que já está no carrinho mais o que está entrando), e o mesmo produto soma na linha existente em vez de criar outra. Validar cada adição isolada permitia adicionar 3 unidades duas vezes tendo 3 em estoque.

Quantidades e valores vindos de formulário passam por `quantidadeInteira()` e `valorMonetario()` do `includes/validacao.php`. O `min="0"` do HTML vale só no navegador: sem a validação no servidor, preço e custo negativos eram gravados, e quantidade negativa numa venda *aumentava* o estoque na finalização.

**Vendas canceladas nunca entram em faturamento ou lucro**, em nenhuma tela — nem no fechamento diário do `ListarVendas.php`, nem nos cards do dashboard, nem nos relatórios. Elas aparecem nas listagens (esmaecidas, com badge) para consulta, mas somar uma venda cancelada seria contar dinheiro que não entrou.

## Indicadores do dashboard

Os cards mostram o **mês corrente**, não o acumulado histórico: vendas no mês, faturamento, lucro das vendas (com margem), resultado do mês (lucro menos despesas), hoje e o ticket médio.

O **Resultado do mês** repete o cálculo do `Relatorios.php`. As duas telas precisam dizer o mesmo número para o mesmo período — quando o dashboard mostrava só a margem e o relatório já descontava despesas, uma das duas estava mentindo. São renderizados pelo `dashboard.php` e reescritos pelo `atualizarCards()` do `funcoes.js` depois de cada venda rápida. Três coisas precisam continuar alinhadas:

- Os `<span>` carregam os ids `cardVendasMes`, `cardFaturamentoMes`, `cardLucroMes`, `cardMargemMes`, `cardTicketMedioMes`, `cardReceitaHoje` e `cardVendasHoje` — é por eles que o JS acha os elementos.
- Os valores monetários já saem do servidor com `R$`, porque o JS os reescreve com `Intl.NumberFormat`, que também traz o símbolo. Sem isso o card mudaria de formato entre o carregamento e a atualização.
- O `ajax_venda_rapida.php` repete as **mesmas consultas** do `dashboard.php` — mesmo recorte de mês e o mesmo `status = 'ativa'`. Divergir aí faz o card mostrar um número depois da venda rápida e outro ao recarregar.

O lucro vem de `SUM(quantidade * custo_unitario)` na `vendas_produtos`, não de um campo em `vendas` — a tabela de vendas guarda só o total faturado. Produto cadastrado sem custo entra como custo zero, o que infla o lucro; por isso o campo é obrigatório no formulário.

## Telas de conferência e backup

`pages/FechamentoCaixa.php` junta num lugar só o que o dia produziu: entradas por forma de pagamento, despesas pagas, lucro e resultado. O card **Dinheiro em caixa** é o que se compara com a gaveta — recebido em dinheiro menos despesas pagas em dinheiro; o que entrou por Pix ou cartão não está lá. Tela só de leitura.

`pages/Configuracoes.php` lista as tabelas para backup e `pages/Backup.php` exporta cada uma em CSV. O nome da tabela entra no SQL por interpolação (não dá para parametrizar nome de tabela), então **só passa o que estiver na lista `$tabelasPermitidas`** — e `usuarios` fica fora de propósito, para as senhas não saírem em arquivo.

O comparativo dos últimos seis meses no dashboard roda três consultas por mês. Com seis meses são dezoito consultas leves; se o período crescer, vale trocar por uma consulta agrupada.

## Migrações

`migracoes/` guarda os `.sql` de alteração de schema, numerados na ordem de aplicação. Não há ferramenta de migração: rode o arquivo à mão em cada ambiente. **Aplique em produção antes de publicar o código que usa as colunas novas** — o deploy é automático no push, e código novo contra schema antigo derruba o site.

`vendas.created_at` e `despesas.data_despesa` têm índice (migração `005`), porque quase toda tela filtra por período. **No `WHERE`, compare a coluna direto, nunca `DATE(created_at)`**: envolver a coluna numa função impede o MySQL de usar o índice, e ele volta a ler a tabela inteira. As formas em uso:

- período: `created_at >= :inicio AND created_at < DATE_ADD(:fim, INTERVAL 1 DAY)`
- hoje: `created_at >= CURDATE() AND created_at < CURDATE() + INTERVAL 1 DAY`
- um dia (`FechamentoCaixa.php`): `created_at >= :dia AND created_at < :dia_seguinte`, com `$diaSeguinte` calculado no PHP para não repetir o mesmo placeholder

O resultado é idêntico ao do `DATE()`, porque a comparação acontece no fuso da sessão. No `SELECT` e no `GROUP BY` o `DATE()` continua valendo: ali ele só formata o que já foi filtrado.

## Cache dos arquivos estáticos

A hospedagem responde CSS e JS com `Cache-Control: max-age=2592000` — trinta dias. Por isso o `header.php` e o `footer.php` acrescentam `?v=<filemtime>` às tags: sem esse parâmetro, uma alteração de estilo ou script só chega ao navegador depois do prazo ou de um Ctrl+Shift+R, e a página nova aparece com a folha antiga (layout quebrado, funções JS ausentes).

**Não remova o parâmetro de versão.** E, ao investigar um "não atualizou em produção", verifique o que o navegador está usando, não só o arquivo no servidor: o arquivo publicado pode estar correto enquanto o navegador exibe a versão guardada.

## Desconto na venda

O desconto é único, sobre o total, aplicado na finalização do carrinho (a venda rápida não tem). Os dois campos da tela — reais e percentual — são espelhos ligados pelo `ativarDesconto()` do `funcoes.js`; só o de reais tem `name`, então é o único enviado.

O servidor **recalcula o total a partir do subtotal do carrinho** e limita o desconto a esse subtotal, em vez de confiar no valor recebido: sem isso, um POST montado fora da tela deixaria o total negativo ou inverteria a venda. Valor negativo cai para zero pelo `valorMonetario()`.

## Busca de produto nas telas de venda

O `ativarBuscaProduto()` do `funcoes.js` liga um campo de texto a um `<select>` e filtra as opções conforme se digita, já selecionando a primeira. É usado no modal do dashboard (`buscaProdutoRapida` → `produto`) e no carrinho (`buscaProdutoCarrinho` → `produtoCarrinho`).

O `<select>` continua sendo o campo enviado no formulário: o `atualizarValores()` lê `data-preco` e `data-estoque` da opção escolhida, e o elemento nativo funciona por teclado sem trabalho extra. O filtro guarda uma cópia de todas as opções ao iniciar, porque remove e recria os `<option>` a cada busca — esconder com `display:none` não funciona em todos os navegadores. A comparação ignora acentos, então "lapis" encontra "Lápis".

Produtos com estoque zero ou negativo continuam nos dois `<select>`, mas **desabilitados**, marcados "— sem estoque" e no fim da lista (`ORDER BY quantidade <= 0, nome`). Escondidos, a pessoa procuraria e concluiria que o cadastro sumiu; habilitados, só descobriria o problema depois de preencher a venda. A seleção automática da busca pula as opções desabilitadas, e a venda rápida desabilita na hora a opção cujo estoque acabou de zerar. A recusa de verdade continua no servidor, pela baixa condicional de estoque.

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

## Fuso horário

A hospedagem roda o PHP em `America/New_York` e o MySQL no fuso do Pacífico. O `includes/configuracao.php` corrige os dois: `date_default_timezone_set('America/Sao_Paulo')` no PHP e `SET time_zone = '-03:00'` na sessão do MySQL.

O offset é fixo em vez do nome `America/Sao_Paulo` porque os nomes dependem das tabelas de fuso do MySQL, normalmente vazias em hospedagem compartilhada — ali o `SET` falharia sem avisar. O Brasil não adota horário de verão desde 2019.

`created_at` é `TIMESTAMP`, e não `DATETIME`: o MySQL guarda o valor internamente em UTC e converte para o fuso da sessão na leitura e na gravação. Por isso o instante registrado sempre esteve certo mesmo antes da correção — o que saía errado era só a exibição. Consequência prática: **nunca "conserte" horários com `UPDATE` somando horas**; ajuste o fuso da sessão e todos os registros passam a aparecer certos sozinhos.

## Tema claro e escuro

Os tokens do tema escuro já existiam no `style.css` sob `[data-theme="dark"]`, incluindo ajustes da logo, do seletor de data e da tela de login. O que faltava era ligar: um script no `<head>` do `header.php` e do `login.php` lê a escolha do `localStorage` e aplica o atributo no `<html>`.

Esse script fica **antes da tag do CSS e fora do `funcoes.js`** de propósito: se esperasse o rodapé, a tela apareceria clara por um instante antes de escurecer. O `funcoes.js` cuida só da alternância e do ícone do botão, que dependem da página existir.

Sem escolha salva, vale a preferência do sistema operacional (`prefers-color-scheme`). Todo acesso ao `localStorage` está em `try/catch` — em janela anônima ou com armazenamento bloqueado ele lança exceção, e sem a proteção a página inteira pararia.

## Menu em telas estreitas e impressão

Abaixo de 900px a sidebar sai do fluxo e vira um painel deslizante, aberto pelo botão `.abrir-menu` (`alternarMenu()` no `funcoes.js`). Antes dessa mudança a regra era `display:none` puro: no celular o sistema ficava **sem navegação e sem botão de sair**. O painel fecha no véu, no Esc e ao tocar num item.

A exibição do botão e do véu fica numa media query **no fim do arquivo**, depois das definições base desses elementos. Numa media query colocada antes delas, o `display:none` base venceria por ordem de cascata e o botão nunca apareceria — foi o que aconteceu na primeira tentativa.

O bloco `@media print` força fundo branco e texto preto sobrescrevendo os tokens, inclusive os do tema escuro, e esconde navegação, botões e formulários. Sem ele, imprimir com o tema escuro sai fundo preto: ilegível no papel e um gasto enorme de tinta.

## Estilos

`assets/css/style.css` é a única folha de estilo e começa com um bloco `:root` de design tokens (`--primary`, `--success`, `--danger`, `--bg-*`, `--text-*`, `--radius-*`). Reaproveite as classes de componente existentes — `.card`, `.cards-grid`, `.indicador`, `.btn` combinado com `.btn-primary`/`.btn-secondary`/`.btn-danger`/`.btn-success`/`.btn-sm`, `.badge-ativa`/`.badge-cancelado`, `.paginacao`/`.pag-btn`, `.modal` — em vez de adicionar estilos inline.

## Formatação

Valores monetários são renderizados no servidor com `number_format($v, 2, ',', '.')` precedidos de `R$`, e no cliente com `Intl.NumberFormat('pt-BR', {style:'currency', currency:'BRL'})`. Datas são exibidas com `date('d/m/Y H:i', strtotime($v['created_at']))`.
