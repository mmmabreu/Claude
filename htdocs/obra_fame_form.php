<?php
// A FAME passou a ser uma por obra (obra_fame.php?id=OBRA), com a especialidade escolhida linha a linha.
// Este ficheiro só reencaminha links antigos (obra_fame_form.php?fame=ID).
session_start();
require_once 'db.php';
require_once 'obra_fame_comum.php';
if (!isset($_SESSION['user_id'])) { header("Location: index.php"); exit; }
$id_obra = 0;
$id_fame = filter_input(INPUT_GET, 'fame', FILTER_VALIDATE_INT);
if ($id_fame) {
    try {
        fameCriarTabelas($pdo);
        $st = $pdo->prepare("SELECT id_obra FROM obras_fames WHERE id = ?");
        $st->execute([$id_fame]);
        $id_obra = (int)$st->fetchColumn();
        if (!$id_obra) {
            $st = $pdo->prepare("SELECT id_obra FROM obras_fame WHERE id_fame = ? LIMIT 1");
            $st->execute([$id_fame]);
            $id_obra = (int)$st->fetchColumn();
        }
    } catch (Exception $e) {}
}
header("Location: " . ($id_obra ? "obra_fame.php?id=" . $id_obra : "obras.php"));
exit;
