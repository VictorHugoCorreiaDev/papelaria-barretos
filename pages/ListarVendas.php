<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/pagamento.php';
require_once __DIR__ . '/../Conexao.php';
require_once __DIR__ . '/../includes/configuracao.php';
require_once __DIR__ . '/../includes/header.php';

/* FILTRO DE SITUAÇÃO */

$status = $_GET['status'] ?? 'ativa';

$where = "";
$params = [];

if ($status === 'ativa' || $status === 'cancelada') {
    $where = "WHERE v.status = :status";
    $params[':status'] = $status;
} else {
    $status = 'todas';
}

/*
 * PAGINAÇÃO POR DIA
 *
 * A lista é agrupada por data e cada grupo fecha com o total do dia, então
 * a paginação conta DIAS, não vendas. Paginar por venda partiria um dia
 * entre duas páginas e o fechamento mostraria um total parcial, que é pior
 * que não mostrar nada.
 */
$diasPorPagina = 7;
$page = isset($_GET['page']) ? (int) $_GET['page'] : 1;
$page = max($page, 1);

$stmtTotalDias = $conn->prepare("
    SELECT COUNT(DISTINCT DATE(v.created_at))
    FROM vendas v
    $where
");
$stmtTotalDias->execute($params);
$totalDias = (int) $stmtTotalDias->fetchColumn();
$totalPaginas = (int) ceil($totalDias / $diasPorPagina);

if ($page > $totalPaginas && $totalPaginas > 0) {
    $page = $totalPaginas;
}

$offset = ($page - 1) * $diasPorPagina;

/* Os dias desta página */
$sqlDias = "
    SELECT DATE(v.created_at) AS dia
    FROM vendas v
    $where
    GROUP BY DATE(v.created_at)
    ORDER BY dia DESC
    LIMIT :limit OFFSET :offset
";

$stmtDias = $conn->prepare($sqlDias);
foreach ($params as $chave => $valor) {
    $stmtDias->bindValue($chave, $valor);
}
$stmtDias->bindValue(':limit', $diasPorPagina, PDO::PARAM_INT);
$stmtDias->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmtDias->execute();
$dias = $stmtDias->fetchAll(PDO::FETCH_COLUMN);

/*
 * As vendas dos dias desta página, numa consulta só.
 *
 * Os dias vêm da consulta acima, no formato Y-m-d do próprio MySQL, então
 * são valores seguros para montar o IN — ainda assim vão por placeholder,
 * para não abrir exceção ao padrão do resto do projeto.
 */
$vendas = [];
$itensPorVenda = [];

if (!empty($dias)) {
    $marcadores = implode(',', array_fill(0, count($dias), '?'));

    $sqlVendas = "
        SELECT v.id, v.total, v.desconto, v.cliente, v.forma_pagamento, v.created_at, v.status,
               DATE(v.created_at) AS dia
        FROM vendas v
        WHERE DATE(v.created_at) IN ($marcadores)
    ";

    $valores = $dias;

    if ($status !== 'todas') {
        $sqlVendas .= " AND v.status = ?";
        $valores[] = $status;
    }

    $sqlVendas .= " ORDER BY v.created_at DESC";

    $stmtVendas = $conn->prepare($sqlVendas);
    $stmtVendas->execute($valores);
    $vendas = $stmtVendas->fetchAll(PDO::FETCH_ASSOC);

    /*
     * Itens das vendas listadas, também numa consulta só — buscar item a
     * item dentro do laço de exibição faria uma consulta por venda.
     */
    if (!empty($vendas)) {
        $ids = array_column($vendas, 'id');
        $marcadoresIds = implode(',', array_fill(0, count($ids), '?'));

        $stmtItens = $conn->prepare("
            SELECT vp.venda_id, p.nome, vp.quantidade, vp.custo_unitario
            FROM vendas_produtos vp
            JOIN produtos p ON p.id = vp.produto_id
            WHERE vp.venda_id IN ($marcadoresIds)
            ORDER BY vp.id
        ");
        $stmtItens->execute($ids);

        foreach ($stmtItens->fetchAll(PDO::FETCH_ASSOC) as $item) {
            $itensPorVenda[$item['venda_id']][] = $item;
        }
    }
}

/*
 * Agrupa por dia e calcula o fechamento.
 *
 * O lucro do dia usa o custo_unitario congelado na venda e considera só as
 * vendas ativas — uma venda cancelada não faturou nem lucrou, ainda que
 * apareça na lista quando o filtro é "canceladas" ou "todas".
 */
$porDia = [];

foreach ($vendas as $venda) {
    $dia = $venda['dia'];

    if (!isset($porDia[$dia])) {
        $porDia[$dia] = [
            'vendas' => [],
            'quantidadeAtivas' => 0,
            'quantidadeCanceladas' => 0,
            'faturamento' => 0.0,
            'lucro' => 0.0,
        ];
    }

    $itens = $itensPorVenda[$venda['id']] ?? [];

    $custo = 0.0;
    $unidades = 0;
    foreach ($itens as $item) {
        $custo += $item['quantidade'] * $item['custo_unitario'];
        $unidades += (int) $item['quantidade'];
    }

    $venda['itens'] = $itens;
    $venda['unidadesExtras'] = max($unidades - 1, 0);
    $venda['lucro'] = $venda['total'] - $custo;

    $porDia[$dia]['vendas'][] = $venda;

    if ($venda['status'] === 'ativa') {
        $porDia[$dia]['quantidadeAtivas']++;
        $porDia[$dia]['faturamento'] += (float) $venda['total'];
        $porDia[$dia]['lucro'] += $venda['lucro'];
    } else {
        $porDia[$dia]['quantidadeCanceladas']++;
    }
}

/* CONTADORES DOS FILTROS */

$countAtivas = (int) $conn->query("SELECT COUNT(*) FROM vendas WHERE status = 'ativa'")->fetchColumn();
$countCanceladas = (int) $conn->query("SELECT COUNT(*) FROM vendas WHERE status = 'cancelada'")->fetchColumn();
$countTodas = (int) $conn->query("SELECT COUNT(*) FROM vendas")->fetchColumn();

/* Data por extenso, montada à mão pelo mesmo motivo do dashboard:
   strftime está depreciado e o locale pt_BR não existe na hospedagem */
$diasSemana = ['domingo', 'segunda-feira', 'terça-feira', 'quarta-feira',
               'quinta-feira', 'sexta-feira', 'sábado'];
$mesesNome = ['', 'janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho',
              'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro'];

function dataPorExtenso($dia, $diasSemana, $mesesNome)
{
    $t = strtotime($dia);
    return $diasSemana[(int) date('w', $t)] . ', ' . date('d', $t)
        . ' de ' . $mesesNome[(int) date('n', $t)];
}
?>

<h2>Vendas</h2>

<!-- FILTROS -->
<div class="filtros-vendas">

    <a href="?status=ativa"
        class="btn btn-sm <?= $status == 'ativa' ? 'btn-primary' : 'btn-secondary' ?>">
        Ativas (<?= $countAtivas ?>)
    </a>

    <a href="?status=cancelada"
        class="btn btn-sm <?= $status == 'cancelada' ? 'btn-primary' : 'btn-secondary' ?>">
        Canceladas (<?= $countCanceladas ?>)
    </a>

    <a href="?status=todas"
        class="btn btn-sm <?= $status == 'todas' ? 'btn-primary' : 'btn-secondary' ?>">
        Todas (<?= $countTodas ?>)
    </a>

</div>

<?php if (empty($porDia)): ?>

    <div class="card">
        <p style="color: var(--text-gray);">Nenhuma venda encontrada.</p>
    </div>

<?php else: ?>

    <p class="periodo-atual">
        Mostrando <strong><?= count($porDia) ?></strong>
        de <strong><?= $totalDias ?></strong> dia(s) com venda
    </p>

    <?php foreach ($porDia as $dia => $grupo): ?>

        <div class="card dia-vendas">

            <!-- CABEÇALHO DO DIA -->
            <div class="dia-cabecalho">
                <span class="dia-titulo"><?= htmlspecialchars(dataPorExtenso($dia, $diasSemana, $mesesNome)) ?></span>

                <span class="dia-resumo">
                    <?php if ($grupo['quantidadeAtivas'] > 0): ?>
                        <?= $grupo['quantidadeAtivas'] ?> venda<?= $grupo['quantidadeAtivas'] == 1 ? '' : 's' ?>
                        · R$ <?= number_format($grupo['faturamento'], 2, ',', '.') ?>
                        · <span class="dia-lucro">R$ <?= number_format($grupo['lucro'], 2, ',', '.') ?> de lucro</span>
                    <?php endif; ?>

                    <?php
                    /*
                     * Sem esta condição, o filtro "canceladas" exibiria
                     * "0 vendas · R$ 0,00 · R$ 0,00 de lucro" acima de uma
                     * lista cheia — correto, porque cancelada não fatura,
                     * mas confuso de ler.
                     */
                    if ($grupo['quantidadeCanceladas'] > 0):
                    ?>
                        <?= $grupo['quantidadeAtivas'] > 0 ? '·' : '' ?>
                        <span class="dia-canceladas">
                            <?= $grupo['quantidadeCanceladas'] ?> cancelada<?= $grupo['quantidadeCanceladas'] == 1 ? '' : 's' ?>
                        </span>
                    <?php endif; ?>
                </span>
            </div>

            <!-- VENDAS DO DIA -->
            <ul class="lista-vendas">
                <?php foreach ($grupo['vendas'] as $v): ?>
                    <li class="<?= $v['status'] === 'ativa' ? '' : 'venda-cancelada' ?>">

                        <span class="venda-hora"><?= date('H:i', strtotime($v['created_at'])) ?></span>

                        <span class="venda-codigo">#<?= (int) $v['id'] ?></span>

                        <span class="venda-cliente">
                            <?= htmlspecialchars(nomeCliente($v['cliente'])) ?>
                        </span>

                        <span class="venda-pagamento">
                            <?= htmlspecialchars(nomeFormaPagamento($v['forma_pagamento'])) ?>
                            <?php if ($v['desconto'] > 0): ?>
                                <!-- O total já é líquido; o desconto aparece como contexto -->
                                <small class="venda-desconto">
                                    −R$ <?= number_format($v['desconto'], 2, ',', '.') ?>
                                </small>
                            <?php endif; ?>
                        </span>

                        <span class="venda-itens">
                            <?php if (empty($v['itens'])): ?>
                                <span style="color: var(--text-gray);">sem itens</span>
                            <?php else: ?>
                                <?= htmlspecialchars($v['itens'][0]['nome']) ?><?php if ($v['unidadesExtras'] > 0): ?>
                                    · +<?= $v['unidadesExtras'] ?>
                                <?php endif; ?>
                            <?php endif; ?>
                        </span>

                        <span class="venda-situacao">
                            <?php if ($v['status'] === 'ativa'): ?>
                                <span class="badge badge-ativa">Ativa</span>
                            <?php else: ?>
                                <span class="badge badge-cancelado">Cancelada</span>
                            <?php endif; ?>
                        </span>

                        <strong class="venda-valor">R$ <?= number_format($v['total'], 2, ',', '.') ?></strong>

                        <span class="venda-acoes">
                            <button class="btn btn-secondary btn-sm" onclick="verItens(<?= (int) $v['id'] ?>)">
                                Ver Itens
                            </button>

                            <?php if ($v['status'] === 'ativa'): ?>
                                <form method="POST" action="CancelarVenda.php" class="form-inline"
                                    onsubmit="return confirm('Tem certeza que deseja cancelar esta venda?')">
                                    <?= campoCsrf() ?>
                                    <input type="hidden" name="id" value="<?= (int) $v['id'] ?>">
                                    <button type="submit" class="btn btn-danger btn-sm">Cancelar</button>
                                </form>
                            <?php endif; ?>
                        </span>

                    </li>
                <?php endforeach; ?>
            </ul>

            <!-- FECHAMENTO -->
            <div class="dia-fechamento">
                <span>Fechamento do dia</span>
                <?php if ($grupo['quantidadeAtivas'] > 0): ?>
                    <span>Vendas <strong>R$ <?= number_format($grupo['faturamento'], 2, ',', '.') ?></strong></span>
                <?php else: ?>
                    <span>Nenhuma venda faturada</span>
                <?php endif; ?>
            </div>

        </div>

    <?php endforeach; ?>

    <!-- PAGINAÇÃO -->
    <?php if ($totalPaginas > 1): ?>
        <div class="paginacao">

            <?php if ($page > 1): ?>
                <a href="?status=<?= urlencode($status) ?>&page=<?= $page - 1 ?>" class="pag-btn">«</a>
            <?php endif; ?>

            <?php for ($i = 1; $i <= $totalPaginas; $i++): ?>
                <a href="?status=<?= urlencode($status) ?>&page=<?= $i ?>"
                    class="pag-btn <?= $i == $page ? 'active' : '' ?>">
                    <?= $i ?>
                </a>
            <?php endfor; ?>

            <?php if ($page < $totalPaginas): ?>
                <a href="?status=<?= urlencode($status) ?>&page=<?= $page + 1 ?>" class="pag-btn">»</a>
            <?php endif; ?>

        </div>
    <?php endif; ?>

<?php endif; ?>

<!-- MODAL -->
<div id="modalItens" class="modal">
    <div class="modal-content">
        <h3>Itens da Venda</h3>
        <div id="conteudoItens"></div>
        <div class="modal-footer">
            <button class="btn btn-secondary btn-sm" onclick="fecharModal()">
                Fechar
            </button>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
