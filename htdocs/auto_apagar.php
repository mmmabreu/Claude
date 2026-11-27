<?php
// =====================================================================
// APAGAR AUTO DE MEDIÇÃO
// - Só o administrador, só o último auto de cada obra, com a password de segurança (verificada aqui, no servidor).
// - NUNCA PERDER DADOS: antes de apagar, o auto e todas as suas linhas ficam guardados em autos_arquivo.
// - Só aceita pedidos enviados pelo formulário de autos.php (POST com token CSRF): um link não apaga nada.
// =====================================================================
session_start();
require_once 'db.php';
require_once 'functions.php';

// REGRA DE OURO 1: Validação de sessão
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}

// Segurança: Apenas admins podem apagar
if (($_SESSION['perfil'] ?? '') !== 'admin') {
    header("Location: autos.php?erro=sem_permissao");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: autos.php");
    exit;
}

// REGRA DE OURO 7: token CSRF
if (!validar_csrf_token($_POST['csrf_token'] ?? '')) {
    header("Location: autos.php?erro=sessao");
    exit;
}

// Password de segurança (a mesma de autos.php e auto_form.php)
$stmt_pass = $pdo->prepare("SELECT valor FROM configuracoes WHERE chave = 'password_apagar' LIMIT 1");
$stmt_pass->execute();
$pass_seguranca_sistema = (string)$stmt_pass->fetchColumn();
if ($pass_seguranca_sistema === '') {
    $pass_seguranca_sistema = 'mab2026';
}
if (!hash_equals($pass_seguranca_sistema, (string)($_POST['pass_seguranca'] ?? ''))) {
    header("Location: autos.php?erro=password");
    exit;
}

// REGRA DE OURO 5: ID validado como número inteiro
$id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
if (!$id) {
    header("Location: autos.php");
    exit;
}

// 1. Verificar se este auto é o ÚLTIMO da obra (Regra de Integridade)
$stmt_check = $pdo->prepare("SELECT * FROM autos WHERE id = ?");
$stmt_check->execute([$id]);
$auto = $stmt_check->fetch(PDO::FETCH_ASSOC);
if (!$auto) {
    header("Location: autos.php");
    exit;
}
$stmt_max = $pdo->prepare("SELECT MAX(numero_auto) FROM autos WHERE id_obra = ?");
$stmt_max->execute([$auto['id_obra']]);
if ($auto['numero_auto'] != $stmt_max->fetchColumn()) {
    header("Location: autos.php?erro=nao_ultimo");
    exit;
}

try {
    // Tabela do arquivo (criada fora da transação: no MySQL, CREATE TABLE fecha a transação aberta)
    $pdo->exec("CREATE TABLE IF NOT EXISTS autos_arquivo (
        id INT AUTO_INCREMENT PRIMARY KEY,
        id_auto INT NOT NULL,
        id_obra INT NULL,
        numero_auto INT NULL,
        dados_json MEDIUMTEXT NOT NULL,
        itens_json MEDIUMTEXT NOT NULL,
        arquivado_por INT NULL,
        arquivado_em DATETIME NOT NULL,
        KEY idx_obra (id_obra)
    ) DEFAULT CHARSET=utf8mb4");

    $st_it = $pdo->prepare("SELECT * FROM auto_itens WHERE id_auto = ? ORDER BY id ASC");
    $st_it->execute([$id]);
    $itens = $st_it->fetchAll(PDO::FETCH_ASSOC);

    $pdo->beginTransaction();
    $pdo->prepare("INSERT INTO autos_arquivo (id_auto, id_obra, numero_auto, dados_json, itens_json, arquivado_por, arquivado_em) VALUES (?, ?, ?, ?, ?, ?, NOW())")
        ->execute([$id, $auto['id_obra'], $auto['numero_auto'], safe_json_encode($auto), safe_json_encode($itens), $_SESSION['user_id']]);
    // Apaga os itens primeiro por causa da integridade referencial
    $pdo->prepare("DELETE FROM auto_itens WHERE id_auto = ?")->execute([$id]);
    $pdo->prepare("DELETE FROM autos WHERE id = ?")->execute([$id]);
    $pdo->commit();
    registrarLog($pdo, "Apagou o auto nº " . $auto['numero_auto'] . " (ID: $id) da obra ID " . $auto['id_obra'] . " — cópia em autos_arquivo", "Obras");
    header("Location: autos.php?sucesso=1");
    exit;
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('auto_apagar.php: ' . $e->getMessage());
    header("Location: autos.php?erro=db");
    exit;
}
