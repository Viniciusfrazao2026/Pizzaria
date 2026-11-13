<?php
/**
 * Funções de apoio para o "meio a meio" entre categorias (ex.: pizza salgada + pizza doce).
 * Fica em arquivo separado para NÃO mexer no config.php (credenciais do banco).
 */

function coluna_existe($conn, $tabela, $coluna) {
    $t = $conn->real_escape_string($tabela);
    $c = $conn->real_escape_string($coluna);
    $r = $conn->query("SHOW COLUMNS FROM `$t` LIKE '$c'");
    return $r && $r->num_rows > 0;
}

/**
 * Cria a coluna categorias.meio_a_meio se ainda não existir.
 * Retorna true se a coluna existe ao final.
 */
function garantir_coluna_meio_a_meio($conn) {
    if (!coluna_existe($conn, 'categorias', 'meio_a_meio')) {
        $conn->query("ALTER TABLE categorias ADD COLUMN meio_a_meio TINYINT(1) NOT NULL DEFAULT 0");
    }
    return coluna_existe($conn, 'categorias', 'meio_a_meio');
}

/**
 * Valida a combinação de dois sabores no meio a meio:
 * vale se forem da mesma categoria OU se as duas categorias estiverem
 * marcadas com "permite meio a meio" no painel admin.
 */
function meio_a_meio_permitido($conn, $id1, $id2) {
    $stmt = $conn->prepare("SELECT id, categoria_id FROM produtos WHERE id IN (?, ?)");
    $stmt->bind_param('ii', $id1, $id2);
    $stmt->execute();
    $cats = [];
    $res = $stmt->get_result();
    while ($r = $res->fetch_assoc()) { $cats[(int)$r['id']] = (int)$r['categoria_id']; }
    if (!isset($cats[$id1]) || !isset($cats[$id2])) return false;
    if ($cats[$id1] === $cats[$id2]) return true;

    if (!coluna_existe($conn, 'categorias', 'meio_a_meio')) return false;
    $c1 = $cats[$id1]; $c2 = $cats[$id2];
    $stmt = $conn->prepare("SELECT COUNT(*) AS n FROM categorias WHERE id IN (?, ?) AND meio_a_meio = 1");
    $stmt->bind_param('ii', $c1, $c2);
    $stmt->execute();
    $n = (int)$stmt->get_result()->fetch_assoc()['n'];
    return $n === 2;
}

/**
 * Fatias por tamanho (P/M/G).
 * Cria a coluna precos.fatias se ainda não existir (não precisa rodar SQL manualmente).
 */
function garantir_coluna_fatias($conn) {
    if (!coluna_existe($conn, 'precos', 'fatias')) {
        $conn->query("ALTER TABLE precos ADD COLUMN fatias INT NULL DEFAULT NULL");
    }
    return coluna_existe($conn, 'precos', 'fatias');
}
