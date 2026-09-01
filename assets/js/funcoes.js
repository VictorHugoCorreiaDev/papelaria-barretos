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

                // 🔥 Atualizar CARDS
                atualizarCards(data.cards);

                form.reset();
                atualizarValores();

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