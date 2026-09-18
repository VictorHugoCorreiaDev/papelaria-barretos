<?php

/*
 * Aviso "Venda #N registrada — Imprimir comprovante", exibido uma vez logo
 * depois de fechar uma venda. É o momento em que o cliente ainda está no
 * balcão; depois disso o comprovante continua acessível pela lista de
 * vendas.
 *
 * Quem registra a venda grava $_SESSION['ultima_venda']; este arquivo lê e
 * limpa, para o aviso não reaparecer a cada recarga. Incluir depois do
 * header.php, que é quem garante a sessão iniciada.
 */

$ultimaVenda = (int) ($_SESSION['ultima_venda'] ?? 0);
unset($_SESSION['ultima_venda']);

if ($ultimaVenda > 0): ?>
    <div class="aviso-venda">
        <span>✅ Venda <strong>#<?= $ultimaVenda ?></strong> registrada.</span>
        <a href="/pages/Comprovante.php?id=<?= $ultimaVenda ?>" class="btn btn-secondary btn-sm">
            🧾 Imprimir comprovante
        </a>
    </div>
<?php endif;
