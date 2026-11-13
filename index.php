<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers_meio.php';
$temFatias = garantir_coluna_fatias($conn);

$nome_loja  = get_config($conn, 'nome_loja', 'Top Pizzaria');
$loja_aberta = get_config($conn, 'loja_aberta', '1') === '1';
$chave_pix  = get_config($conn, 'chave_pix', '');
$endereco_retirada = get_config($conn, 'endereco_retirada', '');
$maps_link  = get_config($conn, 'maps_link_retirada', '#');
$whatsapp   = get_config($conn, 'telefone_whatsapp', '');
$taxa_entrega = (float)get_config($conn, 'taxa_entrega', '0');
$tempo_entrega_min = (int)get_config($conn, 'tempo_entrega_min', '30');
$tempo_entrega_max = (int)get_config($conn, 'tempo_entrega_max', '50');

// Busca categorias ativas
$categorias = [];
$colunasCat = coluna_existe($conn, 'categorias', 'meio_a_meio') ? 'id, nome, meio_a_meio' : 'id, nome';
$res = $conn->query("SELECT $colunasCat FROM categorias WHERE ativo = 1 ORDER BY ordem ASC, id ASC");
while ($row = $res->fetch_assoc()) {
    $row['meio_a_meio'] = isset($row['meio_a_meio']) ? (int)$row['meio_a_meio'] : 0;
    $categorias[] = $row;
}

// Busca produtos ativos + preços, agrupados por categoria
$produtos_por_categoria = [];
$sql = "SELECT p.id, p.categoria_id, p.nome, p.descricao, p.imagem, p.tem_tamanhos
        FROM produtos p WHERE p.ativo = 1 ORDER BY p.categoria_id, p.ordem ASC, p.id ASC";
$res = $conn->query($sql);
$produtos = [];
while ($row = $res->fetch_assoc()) {
    $row['tem_tamanhos'] = (int)$row['tem_tamanhos']; // garante 0/1 numérico (evita "0" truthy no JS)
    $row['precos'] = [];
    $produtos[$row['id']] = $row;
    $produtos_por_categoria[$row['categoria_id']][] = $row['id'];
}
// Fatias padrão por tamanho (Configurações do admin); cada produto pode sobrescrever.
$fatiasPadrao = [
    'P' => (int)get_config($conn, 'fatias_P', '0'),
    'M' => (int)get_config($conn, 'fatias_M', '0'),
    'G' => (int)get_config($conn, 'fatias_G', '0'),
];
$colFatias = $temFatias ? ', fatias' : '';
$res2 = $conn->query("SELECT produto_id, tamanho, preco$colFatias FROM precos ORDER BY id ASC");
while ($r = $res2->fetch_assoc()) {
    if (isset($produtos[$r['produto_id']])) {
        $fatias = isset($r['fatias']) ? (int)$r['fatias'] : 0;
        if ($fatias <= 0 && isset($fatiasPadrao[$r['tamanho']])) $fatias = $fatiasPadrao[$r['tamanho']];
        $produtos[$r['produto_id']]['precos'][] = [
            'tamanho' => $r['tamanho'],
            'preco'   => (float)$r['preco'],
            'fatias'  => $fatias > 0 ? $fatias : null,
        ];
    }
}

$menu_json = json_encode([
    'categorias' => $categorias,
    'produtos' => array_values($produtos),
    'porCategoria' => $produtos_por_categoria,
], JSON_UNESCAPED_UNICODE);

$bairros = [];
$resBairros = $conn->query("SELECT id, nome, taxa FROM bairros_entrega WHERE ativo = 1 ORDER BY ordem ASC, id ASC");
while ($row = $resBairros->fetch_assoc()) {
    $row['taxa'] = (float)$row['taxa'];
    $bairros[] = $row;
}
$bairros_json = json_encode($bairros, JSON_UNESCAPED_UNICODE);

$config_json = json_encode([
    'nomeLoja' => $nome_loja,
    'lojaAberta' => $loja_aberta,
    'chavePix' => $chave_pix,
    'enderecoRetirada' => $endereco_retirada,
    'mapsLink' => $maps_link,
    'whatsapp' => $whatsapp,
    'taxaEntrega' => $taxa_entrega,
    'tempoEntregaMin' => $tempo_entrega_min,
    'tempoEntregaMax' => $tempo_entrega_max,
], JSON_UNESCAPED_UNICODE);
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">
<title><?= htmlspecialchars($nome_loja) ?> | Cardápio Delivery</title>
<meta name="theme-color" content="#0e2a1c">
<link rel="icon" href="images/logo.png">
<link rel="stylesheet" href="css/style.css?v=<?= @filemtime(__DIR__ . '/css/style.css') ?: time() ?>">
</head>
<body>

<header class="topbar">
  <div class="topbar__row">
    <img src="images/logo.png" alt="<?= htmlspecialchars($nome_loja) ?>" class="topbar__logo">
    <div>
      <div class="topbar__name display"><?= htmlspecialchars($nome_loja) ?></div>
      <div class="topbar__tag">Cardápio digital · Peça pelo app</div>
    </div>
    <span class="topbar__status <?= $loja_aberta ? 'status--aberto' : 'status--fechado' ?>">
      <?= $loja_aberta ? '● Aberto' : '● Fechado' ?>
    </span>
  </div>
  <?php if ($tempo_entrega_min > 0): ?>
  <div class="topbar__eta" id="topbarEta">
    ⏱ Entrega em <?= $tempo_entrega_min ?><?= $tempo_entrega_max > $tempo_entrega_min ? '–' . $tempo_entrega_max : '' ?> min
  </div>
  <?php endif; ?>
</header>

<nav class="cat-nav" id="catNav"></nav>

<main id="menuContainer"></main>

<div class="footer-note">
  <?= htmlspecialchars($nome_loja) ?> · Cardápio atualizado automaticamente pela administração
</div>

<!-- Barra flutuante do carrinho -->
<button class="cart-bar hide" id="cartBar">
  <span class="cart-bar__count" id="cartCount">0</span>
  <span class="cart-bar__label">Ver carrinho</span>
  <span class="cart-bar__total" id="cartBarTotal">R$ 0,00</span>
</button>

<div class="overlay" id="overlay"></div>

<!-- Drawer: escolha de tamanho -->
<div class="drawer" id="sizeDrawer">
  <div class="drawer__handle"></div>
  <div class="drawer__head">
    <div class="drawer__title" id="sizeTitle">Escolha o tamanho</div>
    <button class="drawer__close" data-close="sizeDrawer">&times;</button>
  </div>
  <div class="drawer__body">
    <div class="size-modal" id="sizeOptions"></div>
  </div>
  <div class="drawer__foot">
    <button class="btn-primary" id="btnConfirmSize">Adicionar ao carrinho</button>
  </div>
</div>

<!-- Drawer: carrinho -->
<div class="drawer" id="cartDrawer">
  <div class="drawer__handle"></div>
  <div class="drawer__head">
    <div class="drawer__title">Seu carrinho</div>
    <button class="drawer__close" data-close="cartDrawer">&times;</button>
  </div>
  <div class="drawer__body" id="cartBody"></div>
  <div class="drawer__foot" id="cartFoot"></div>
</div>

<!-- Drawer: checkout -->
<div class="drawer" id="checkoutDrawer">
  <div class="drawer__handle"></div>
  <div class="drawer__head">
    <div class="drawer__title" id="checkoutTitle">Finalizar pedido</div>
    <button class="drawer__close" data-close="checkoutDrawer">&times;</button>
  </div>
  <div class="drawer__body" id="checkoutBody"></div>
  <div class="drawer__foot" id="checkoutFoot"></div>
</div>

<div class="toast" id="toast"></div>

<script>
  window.MENU_DATA = <?= $menu_json ?>;
  window.LOJA_CONFIG = <?= $config_json ?>;
  window.BAIRROS_ENTREGA = <?= $bairros_json ?>;
</script>
<script src="js/app.js?v=<?= @filemtime(__DIR__ . '/js/app.js') ?: time() ?>"></script>
</body>
</html>
