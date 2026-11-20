<?php
session_start();
require_once 'db.php';

// Segurança: Apenas admins podem apagar
if (!isset($_SESSION['user_id']) || $_SESSION['perfil'] !== 'admin') {
    header("Location: autos.php?erro=sem_permissao");
    exit;
}

if (isset($_GET['id'])) {
    $id = (int)$_GET['id'];

    // 1. Verificar se este auto é o ÚLTIMO da obra (Regra de Integridade)
    $stmt_check = $pdo->prepare("SELECT id_obra, numero_auto FROM autos WHERE id = ?");
    $stmt_check->execute([$id]);
    $auto = $stmt_check->fetch();

    if ($auto) {
        $stmt_max = $pdo->prepare("SELECT MAX(numero_auto) FROM autos WHERE id_obra = ?");
        $stmt_max->execute([$auto['id_obra']]);
        $ultimo_num = $stmt_max->fetchColumn();

        // Só executa se for o último número
        if ($auto['numero_auto'] == $ultimo_num) {
            try {
                $pdo->beginTransaction();
                // Apaga os itens primeiro por causa da integridade referencial
                $pdo->prepare("DELETE FROM auto_itens WHERE id_auto = ?")->execute([$id]);
                $pdo->prepare("DELETE FROM autos WHERE id = ?")->execute([$id]);
                $pdo->commit();
                header("Location: autos.php?sucesso=1");
                exit;
            } catch (Exception $e) {
                $pdo->rollBack();
                header("Location: autos.php?erro=db");
                exit;
            }
        }
    }
}

header("Location: autos.php");
exit;