const produtoSelect = document.getElementById('produto');
const quantidadeInput = document.getElementById('quantidade');
const valorUnitarioSpan = document.getElementById('valorUnitario');
const totalVendaSpan = document.getElementById('totalVenda');
const botao = document.querySelector('#formVenda button[type="submit"]');

const formatoBRL = new Intl.NumberFormat('pt-BR', {
    style: 'currency',
    currency: 'BRL'
});

function atualizarValores() {
    if (!produtoSelect) return;

    const selectedOption = produtoSelect.selectedOptions[0];

    const preco = parseFloat(
        selectedOption?.dataset.preco
    ) || 0;

    const estoque = parseInt(
        selectedOption?.dataset.estoque
    ) || 0;

    const quantidade = parseInt(quantidadeInput?.value) || 0;

    if (valorUnitarioSpan)
        valorUnitarioSpan.textContent = formatoBRL.format(preco);

    if (totalVendaSpan)
        totalVendaSpan.textContent = formatoBRL.format(preco * quantidade);

    // 🔒 Desabilitar botão se quantidade inválida
    if (botao)
        botao.disabled = quantidade <= 0 || quantidade > estoque;
}

// Reescreve os indicadores do dashboard depois de uma venda rápida.
// Os ids e o formato precisam bater com o que o dashboard.php renderiza.
function atualizarCards(cards) {

    if (!cards) return;

    const formatoBRL = new Intl.NumberFormat('pt-BR', {
        style: 'currency',
        currency: 'BRL'
    });

    const escreve = (id, valor) => {
        const el = document.getElementById(id);
        if (el) el.textContent = valor;
    };

    escreve('cardVendasMes', cards.vendasMes);
    escreve('cardFaturamentoMes', formatoBRL.format(cards.faturamentoMes));
    escreve('cardLucroMes', formatoBRL.format(cards.lucroMes));
    escreve('cardMargemMes', cards.margemMes.toFixed(1).replace('.', ','));
    escreve('cardTicketMedioMes', formatoBRL.format(cards.ticketMedioMes).replace('R$', '').trim());
    escreve('cardReceitaHoje', formatoBRL.format(cards.receitaHoje));
    escreve('cardVendasHoje', cards.vendasHoje);
}

produtoSelect?.addEventListener('change', atualizarValores);
quantidadeInput?.addEventListener('input', atualizarValores);
window.addEventListener('load', atualizarValores);

// Busca de produto nas duas telas de venda; cada uma tem seus próprios ids
// e a função ignora em silêncio a que não existir na página atual
window.addEventListener('DOMContentLoaded', function () {
    // Só acerta o ícone: o tema já foi aplicado lá no <head>
    aplicarTema(temaAtual());

    ativarBuscaProduto('buscaProdutoRapida', 'produto', 'contadorProdutosRapida');
    ativarBuscaProduto('buscaProdutoCarrinho', 'produtoCarrinho', 'contadorProdutosCarrinho');
    ativarDesconto();
});

const form = document.getElementById('formVenda');

form?.addEventListener('submit', function (e) {
    e.preventDefault();

    const formData = new FormData(form);

    botao.disabled = true;

    fetch(`${BASE_URL}/ajax/ajax_venda_rapida.php`, { // 🔥 ALTERADO AQUI
        method: 'POST',
        body: formData
    })
        .then(res => {
            if (res.status === 401) {
                sessaoExpirada();
                return null;
            }
            return res.json();
        })
        .then(data => {

            if (!data) return;

            if (data.status === "sucesso") {

                mostrarToast(data.mensagem);

                // 🔥 Atualizar estoque
                const selectedOption = produtoSelect.selectedOptions[0];

                selectedOption.dataset.estoque = data.novoEstoque;

                selectedOption.textContent =
                    selectedOption.textContent.replace(
                        /Estoque:\s*\d+/,
                        "Estoque: " + data.novoEstoque
                    );

                // Vendeu a última unidade: a opção fica como as que já vêm
                // zeradas do servidor, desabilitada
                if (data.novoEstoque <= 0) {
                    selectedOption.disabled = true;
                    selectedOption.textContent = selectedOption.textContent
                        .replace(/\(Estoque:\s*-?\d+\)/, "— sem estoque");
                }

                // 🔥 Atualizar CARDS
                atualizarCards(data.cards);

                form.reset();
                atualizarValores();

                // No dashboard o formulário vive num modal: fecha depois de
                // registrar e recarrega para o gráfico e as listas
                // acompanharem a venda que acabou de entrar
                if (document.getElementById('modalVendaRapida')) {
                    fecharVendaRapida();
                    setTimeout(() => window.location.reload(), 1200);
                }

            } else {
                mostrarToast("🔴 " + data.mensagem);
            }

            botao.disabled = false;
        })
        .catch(err => {
            console.error(err);
            mostrarToast("Erro na comunicação com o servidor.");
            botao.disabled = false;
        });
});

function mostrarToast(mensagem) {
    const toast = document.getElementById('toast');
    if (!toast) return;

    toast.textContent = mensagem;
    toast.classList.add('show');

    setTimeout(() => {
        toast.classList.remove('show');
    }, 3000);
}

function verItens(idVenda) {
    fetch(`${BASE_URL}/ajax/ajax_venda_itens.php?id=${idVenda}`)
        .then(response => {
            if (response.status === 401) {
                sessaoExpirada();
                return null;
            }
            return response.text();
        })
        .then(data => {
            if (data === null) return;

            document.getElementById("conteudoItens").innerHTML = data;
            document.getElementById("modalItens").style.display = "flex";
        })
        .catch(err => console.error(err));
}

// Sessão caiu no meio de uma chamada AJAX: avisa e devolve ao login
function sessaoExpirada() {
    mostrarToast("Sessão expirada. Redirecionando para o login...");

    setTimeout(() => {
        window.location.href = `${BASE_URL}/login.php`;
    }, 1500);
}

function fecharModal() {
    document.getElementById("modalItens").style.display = "none";
}

// ===== Tema claro e escuro =====

/*
 * O tema em si é aplicado no <head>, antes do CSS carregar, para a tela não
 * piscar claro antes de escurecer. Aqui ficam só a alternância e o ícone do
 * botão, que dependem da página já existir.
 */

function temaAtual() {
    return document.documentElement.getAttribute('data-theme') || 'light';
}

function aplicarTema(tema) {
    document.documentElement.setAttribute('data-theme', tema);

    try {
        localStorage.setItem('tema', tema);
    } catch (e) {
        // Sem localStorage a escolha vale só para esta página; melhor isso
        // do que o botão não responder
    }

    // A troca do ícone é do CSS, por [data-theme]; aqui só o texto de apoio
    const botao = document.getElementById('alternarTema');

    if (botao) {
        const rotulo = tema === 'dark' ? 'Mudar para tema claro' : 'Mudar para tema escuro';
        botao.title = rotulo;
        botao.setAttribute('aria-label', rotulo);
    }
}

function alternarTema() {
    aplicarTema(temaAtual() === 'dark' ? 'light' : 'dark');
}

// ===== Menu lateral em telas estreitas =====

/*
 * Abaixo de 900px a sidebar vira um painel que desliza sobre o conteúdo.
 * Em telas largas nada disso aparece: o botão e o véu ficam escondidos pelo
 * CSS e o menu segue fixo como sempre.
 */

function menuAberto() {
    return document.body.classList.contains('menu-aberto');
}

function abrirMenu() {
    const veu = document.getElementById('veuMenu');
    const botao = document.querySelector('.abrir-menu');

    document.body.classList.add('menu-aberto');
    if (veu) veu.hidden = false;
    if (botao) botao.setAttribute('aria-expanded', 'true');
}

function fecharMenu() {
    const veu = document.getElementById('veuMenu');
    const botao = document.querySelector('.abrir-menu');

    document.body.classList.remove('menu-aberto');
    if (veu) veu.hidden = true;
    if (botao) botao.setAttribute('aria-expanded', 'false');
}

function alternarMenu() {
    menuAberto() ? fecharMenu() : abrirMenu();
}

window.addEventListener('DOMContentLoaded', function () {
    // Tocar num item de menu deve levar à página e fechar o painel
    document.querySelectorAll('.sidebar nav a').forEach(function (link) {
        link.addEventListener('click', fecharMenu);
    });
});

// Esc fecha o menu, como já faz com os modais
document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') fecharMenu();
});

// Ao alargar a janela o menu volta a ser fixo; deixar a classe ligada
// manteria o véu por cima do conteúdo
window.addEventListener('resize', function () {
    if (window.innerWidth > 900 && menuAberto()) fecharMenu();
});

// ===== Busca de produto nos selects de venda =====

/*
 * Com mais de cem produtos cadastrados, achar um item no <select> exige
 * rolar a lista inteira a cada venda. O campo de busca filtra as opções
 * enquanto se digita e já deixa a primeira selecionada, para o caso comum
 * de o primeiro resultado ser o certo.
 *
 * O <select> continua existindo porque o resto do JS depende dele (o
 * atualizarValores lê data-preco e data-estoque da opção escolhida) e
 * porque ele funciona por teclado sem nenhum trabalho extra.
 */

// "lápis" e "lapis" precisam encontrar o mesmo produto
function semAcento(texto) {
    return texto
        .normalize('NFD')
        .replace(/[̀-ͯ]/g, '')
        .toLowerCase();
}

function ativarBuscaProduto(idCampo, idSelect, idContador) {
    const campo = document.getElementById(idCampo);
    const select = document.getElementById(idSelect);
    const contador = idContador ? document.getElementById(idContador) : null;

    if (!campo || !select) return;

    // Guarda a lista completa: o filtro remove opções do select, então sem
    // essa cópia não haveria como trazer de volta ao apagar a busca
    const todasOpcoes = [...select.options].map(o => o.cloneNode(true));

    // Um "Selecione" sem valor, quando existe, fica fixo no topo
    const temPlaceholder = todasOpcoes.length > 0 && todasOpcoes[0].value === '';
    const placeholder = temPlaceholder ? todasOpcoes[0] : null;
    const produtos = temPlaceholder ? todasOpcoes.slice(1) : todasOpcoes;

    function filtrar() {
        const termo = semAcento(campo.value.trim());

        const encontrados = termo === ''
            ? produtos
            : produtos.filter(o => semAcento(o.textContent).includes(termo));

        select.innerHTML = '';

        if (placeholder) {
            select.appendChild(placeholder.cloneNode(true));
        }

        encontrados.forEach(o => select.appendChild(o.cloneNode(true)));

        // Com busca em andamento, já seleciona o primeiro produto disponível
        // para o valor unitário aparecer sem mais cliques. Os sem estoque
        // vêm desabilitados e não podem ser a escolha automática.
        if (termo !== '') {
            const primeiro = [...select.options].find(o => o.value !== '' && !o.disabled);
            if (primeiro) {
                select.value = primeiro.value;
            }
        }

        if (contador) {
            if (termo === '') {
                contador.textContent = produtos.length + ' produto(s)';
            } else if (encontrados.length === 0) {
                contador.textContent = 'Nenhum produto encontrado';
            } else {
                contador.textContent = encontrados.length + ' de ' + produtos.length + ' produto(s)';
            }
        }

        // Avisa quem depende da seleção (valor unitário, total, botão)
        select.dispatchEvent(new Event('change'));
    }

    campo.addEventListener('input', filtrar);

    // Enter no campo de busca vai para a quantidade, não envia o formulário
    campo.addEventListener('keydown', function (e) {
        if (e.key !== 'Enter') return;

        e.preventDefault();
        document.getElementById('quantidade')?.focus();
    });

    filtrar();
}

// ===== Desconto na finalização da venda =====

/*
 * Os campos de reais e de percentual são duas formas de dizer a mesma
 * coisa: preencher um recalcula o outro. Só o campo em reais tem `name`,
 * então é o único que vai para o servidor — que por sua vez recalcula o
 * total a partir do subtotal, sem confiar no que veio do navegador.
 */
function ativarDesconto() {
    const bloco = document.querySelector('.desconto-campos');
    if (!bloco) return;

    const subtotal = parseFloat(bloco.dataset.subtotal) || 0;
    const campoValor = document.getElementById('descontoValor');
    const campoPercentual = document.getElementById('descontoPercentual');
    const resumo = document.getElementById('descontoResumo');

    if (!campoValor || !campoPercentual) return;

    const formatoBRL = new Intl.NumberFormat('pt-BR', {
        style: 'currency',
        currency: 'BRL'
    });

    function mostrarResumo(desconto) {
        if (!resumo) return;

        if (desconto <= 0) {
            resumo.textContent = '';
            return;
        }

        resumo.textContent = 'Total a pagar: ' + formatoBRL.format(subtotal - desconto);
    }

    campoValor.addEventListener('input', function () {
        let valor = parseFloat(campoValor.value) || 0;

        // Desconto maior que a venda deixaria o total negativo
        if (valor > subtotal) {
            valor = subtotal;
            campoValor.value = valor.toFixed(2);
        }

        campoPercentual.value = subtotal > 0 && valor > 0
            ? ((valor / subtotal) * 100).toFixed(1)
            : '';

        mostrarResumo(valor);
    });

    campoPercentual.addEventListener('input', function () {
        let percentual = parseFloat(campoPercentual.value) || 0;

        if (percentual > 100) {
            percentual = 100;
            campoPercentual.value = '100';
        }

        const valor = subtotal * (percentual / 100);

        campoValor.value = valor > 0 ? valor.toFixed(2) : '';
        mostrarResumo(valor);
    });
}

// ===== Venda rápida em modal (dashboard) =====

function abrirVendaRapida() {
    const modal = document.getElementById('modalVendaRapida');
    if (!modal) return;

    modal.style.display = 'flex';

    // Foco no campo de quantidade: o produto já vem selecionado
    document.getElementById('quantidade')?.focus();
}

function fecharVendaRapida() {
    const modal = document.getElementById('modalVendaRapida');
    if (modal) modal.style.display = 'none';
}

// Esc fecha os modais abertos
document.addEventListener('keydown', function (e) {
    if (e.key !== 'Escape') return;

    fecharVendaRapida();
    if (document.getElementById('modalItens')) fecharModal();
});