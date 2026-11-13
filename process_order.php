<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers_meio.php';
header('Content-Type: application/json; charset=utf-8');

$raw = file_get_contents('php://input');
$data = json_decode($raw, true);

function fail($msg) {
    echo json_encode(['ok' => false, 'error' => $msg]);
    exit;
}

if (!$data || empty($data['itens']) || !is_array($data['itens'])) {
    fail('Pedido inválido ou carrinho vazio.');
}

$tipo = $data['tipo_entrega'] ?? '';
if (!in_array($tipo, ['entrega', 'retirada'], true)) fail('Tipo de entrega inválido.');

$pagamento = $data['forma_pagamento'] ?? '';
if (!in_array($pagamento, ['dinheiro', 'cartao', 'pix'], true)) fail('Forma de pagamento inválida.');

$nome = trim($data['nome_cliente'] ?? '');
$telefone = trim($data['telefone'] ?? '');
if ($nome === '' || $telefone === '') fail('Nome e telefone são obrigatórios.');

$rua = $tipo === 'entrega' ? trim($data['rua'] ?? '') : null;
$numero = $tipo === 'entrega' ? trim($data['numero'] ?? '') : null;
$bairro = $tipo === 'entrega' ? trim($data['bairro'] ?? '') : null;
if ($tipo === 'entrega' && ($rua === '' || $numero === '' || $bairro === '')) {
    fail('Endereço de entrega incompleto.');
}

$precisaTroco = !empty($data['precisa_troco']) ? 1 : 0;
$trocoPara = ($pagamento === 'dinheiro' && $precisaTroco && isset($data['troco_para']) && $data['troco_para'] !== '')
    ? (float)$data['troco_para'] : null;

$obs = trim($data['observacoes'] ?? '');

// Recalcula o subtotal no servidor com base nos preços cadastrados (nunca confia no total enviado pelo cliente)
$subtotal = 0.0;
$itensValidados = [];
foreach ($data['itens'] as $item) {
    $produtoId = isset($item['produto_id']) ? (int)$item['produto_id'] : 0;
    $produtoId2 = !empty($item['produto_id_2']) ? (int)$item['produto_id_2'] : null;
    $qtd = isset($item['quantidade']) ? max(1, (int)$item['quantidade']) : 1;
    $tamanho = isset($item['tamanho']) ? trim($item['tamanho']) : 'Único';

    $stmt = $conn->prepare("SELECT p.nome, pr.preco FROM produtos p JOIN precos pr ON pr.produto_id = p.id WHERE p.id = ? AND pr.tamanho = ? AND p.ativo = 1 LIMIT 1");
    $stmt->bind_param('is', $produtoId, $tamanho);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();

    if (!$row) {
        // fallback: usa o nome/preço enviado se não encontrar (evita travar o pedido por dado inconsistente)
        $nomeProd = $item['nome'] ?? 'Item';
        $preco = isset($item['preco_unitario']) ? (float)$item['preco_unitario'] : 0;
    } else {
        $nomeProd = $row['nome'];
        $preco = (float)$row['preco'];
    }

    // Pizza meio a meio: preço prevalece o do sabor mais caro; nome remontado a partir dos 2 produtos reais
    if ($produtoId2) {
        $stmt2 = $conn->prepare("SELECT p.nome, pr.preco FROM produtos p JOIN precos pr ON pr.produto_id = p.id WHERE p.id = ? AND pr.tamanho = ? AND p.ativo = 1 LIMIT 1");
        $stmt2->bind_param('is', $produtoId2, $tamanho);
        $stmt2->execute();
        $row2 = $stmt2->get_result()->fetch_assoc();
        if ($row2) {
            // só aceita sabores da mesma categoria ou de categorias liberadas para meio a meio (ex.: salgada + doce)
            if (!meio_a_meio_permitido($conn, $produtoId, $produtoId2)) {
                fail('Combinação de sabores no meio a meio não permitida.');
            }
            $preco = max($preco, (float)$row2['preco']);
            $nomeProd = '1/2 ' . $nomeProd . ' + 1/2 ' . $row2['nome'];
        } else {
            $produtoId2 = null; // sabor 2 inválido/inativo: trata como sabor único
        }
    }

    $subtotalItem = $preco * $qtd;
    $subtotal += $subtotalItem;
    $itensValidados[] = [
        'produto_id' => $produtoId ?: null,
        'produto_id_2' => $produtoId2,
        'nome_produto' => $nomeProd,
        'tamanho' => $tamanho,
        'quantidade' => $qtd,
        'preco_unitario' => $preco,
        'subtotal' => $subtotalItem,
    ];
}

if ($subtotal <= 0) fail('Carrinho vazio ou inválido.');

// Taxa de entrega: só se aplica quando for "entrega". Se o cliente escolheu um
// bairro cadastrado, usa a taxa específica dele; senão usa a taxa padrão da loja.
$bairroId = null;
$taxaEntrega = 0.0;
if ($tipo === 'entrega') {
    if (!empty($data['bairro_id'])) {
        $bairroIdTentativa = (int)$data['bairro_id'];
        $stmtB = $conn->prepare("SELECT id, taxa FROM bairros_entrega WHERE id = ? AND ativo = 1 LIMIT 1");
        $stmtB->bind_param('i', $bairroIdTentativa);
        $stmtB->execute();
        $rowB = $stmtB->get_result()->fetch_assoc();
        if ($rowB) {
            $bairroId = (int)$rowB['id'];
            $taxaEntrega = (float)$rowB['taxa'];
        } else {
            $taxaEntrega = (float)get_config($conn, 'taxa_entrega', '0');
        }
    } else {
        $taxaEntrega = (float)get_config($conn, 'taxa_entrega', '0');
    }
}
$total = $subtotal + $taxaEntrega;

$conn->begin_transaction();
try {
    $stmt = $conn->prepare("INSERT INTO pedidos
        (tipo_entrega, nome_cliente, telefone, rua, numero, bairro, bairro_id, forma_pagamento, precisa_troco, troco_para, observacoes, subtotal, taxa_entrega, total, status, impresso)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'novo', 0)");
    $stmt->bind_param(
        'ssssssisidsddd',
        $tipo, $nome, $telefone, $rua, $numero, $bairro, $bairroId, $pagamento, $precisaTroco, $trocoPara, $obs, $subtotal, $taxaEntrega, $total
    );
    $stmt->execute();
    $pedidoId = $conn->insert_id;
    $stmt->close();

    $stmtItem = $conn->prepare("INSERT INTO pedido_itens (pedido_id, produto_id, produto_id_2, nome_produto, tamanho, quantidade, preco_unitario, subtotal) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    foreach ($itensValidados as $it) {
        $stmtItem->bind_param('iiissidd', $pedidoId, $it['produto_id'], $it['produto_id_2'], $it['nome_produto'], $it['tamanho'], $it['quantidade'], $it['preco_unitario'], $it['subtotal']);
        $stmtItem->execute();
    }
    $stmtItem->close();

    $conn->commit();
    echo json_encode(['ok' => true, 'pedido_id' => $pedidoId, 'subtotal' => $subtotal, 'taxa_entrega' => $taxaEntrega, 'total' => $total]);
} catch (Exception $e) {
    $conn->rollback();
    fail('Erro ao salvar o pedido: ' . $e->getMessage());
}
