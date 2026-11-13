<?php
/**
 * ARQUIVO DE CONFIGURAÇÃO - PIZZARIA Top Pizzaria
 * Edite os dados abaixo com as credenciais do banco de dados
 * criado no painel da Hostinger (hPanel > Bancos de Dados MySQL).
 */

// ----- CREDENCIAIS DO BANCO DE DADOS (edite aqui) -----
define('DB_HOST', 'localhost');
define('DB_NAME', 'u428290568_Topizzaria');   // troque pelo nome real do seu banco
define('DB_USER', 'u428290568_Topizzaria');   // troque pelo usuário real
define('DB_PASS', 'Topizzaria123');        // troque pela senha real

// ----- FUSO HORÁRIO -----
date_default_timezone_set('America/Recife');

// ----- CONEXÃO -----
mysqli_report(MYSQLI_REPORT_OFF);
$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($conn->connect_error) {
    http_response_code(500);
    die('Erro de conexão com o banco de dados. Verifique config.php. (' . $conn->connect_error . ')');
}
$conn->set_charset('utf8mb4');
$conn->query("SET time_zone = '-03:00'"); // mantém o horário do banco igual ao de Recife (evita pedidos "somem" do dia no dashboard perto da meia-noite)

/**
 * Busca uma configuração da tabela `configuracoes`
 */
function get_config($conn, $chave, $default = '') {
    $stmt = $conn->prepare("SELECT valor FROM configuracoes WHERE chave = ? LIMIT 1");
    $stmt->bind_param('s', $chave);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($row = $res->fetch_assoc()) {
        return $row['valor'];
    }
    return $default;
}

function set_config($conn, $chave, $valor) {
    $stmt = $conn->prepare("INSERT INTO configuracoes (chave, valor) VALUES (?, ?) ON DUPLICATE KEY UPDATE valor = ?");
    $stmt->bind_param('sss', $chave, $valor, $valor);
    return $stmt->execute();
}
