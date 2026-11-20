<?php
session_start();
require_once 'db.php';

if (!isset($_SESSION['user_id'])) { exit; }

$id_cliente = filter_input(INPUT_GET, 'id_cliente', FILTER_VALIDATE_INT);
$tipo = $_GET['tipo'] ?? '';
// Recebemos o ID do documento atual para o forçar a aparecer na lista se estivermos a editar (caso entretanto já tenha sido concluído)
$id_doc_atual = filter_input(INPUT_GET, 'id_doc_atual', FILTER_VALIDATE_INT);

if (!$id_cliente || !in_array($tipo, ['obra', 'orcamento'])) {
    echo json_encode([]); exit;
}

if ($tipo === 'obra') {
    // CORRIGIDO: Apenas obras 'Planeamento' ou 'Em Curso' (Reflete a BD real)
    $sql = "SELECT id, referencia_obra as ref, nome as descricao FROM obras WHERE id_cliente = ? AND (estado IN ('Planeamento', 'Em Curso') OR id = ?) ORDER BY id DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$id_cliente, $id_doc_atual]);
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
} else {
    // CORRIGIDO: Apenas orçamentos 'Rascunho' ou 'Enviado' (Reflete a BD real)
    $sql = "SELECT id, numero_orcamento as ref, nome_obra as descricao FROM orcamentos WHERE id_cliente = ? AND (estado IN ('Rascunho', 'Enviado') OR id = ?) ORDER BY id DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$id_cliente, $id_doc_atual]);
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
}
?>