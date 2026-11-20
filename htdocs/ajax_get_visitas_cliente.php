<?php
session_start();
require_once 'db.php';

// Garantir que devolve sempre formato JSON limpo
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) { 
    echo json_encode([]); 
    exit; 
}

$id_cliente = filter_input(INPUT_GET, 'id_cliente', FILTER_VALIDATE_INT);
if (!$id_cliente) { 
    echo json_encode([]); 
    exit; 
}

try {
    // Buscar apenas os campos que existem na tabela de visitas
    $stmt = $pdo->prepare("SELECT id, referencia_visita as ref, assunto as descricao FROM visitas WHERE id_cliente = ? ORDER BY data_visita DESC");
    $stmt->execute([$id_cliente]);
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
} catch (Exception $e) {
    // Em caso de erro na BD, devolve array vazio para não encravar o Javascript
    echo json_encode([]);
}
?>