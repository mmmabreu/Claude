<?php
// =====================================================================
// OBRAS — lista de obras
// - Cada obra é um cartão com os atalhos identificados (Planeamento, Material, FAME, Autos…).
// - Filtros num clique por estado, pesquisa (nome, referência, morada, cliente) e cliente.
// - Agrupamento por cliente opcional (grupos recolhíveis). As escolhas ficam lembradas neste navegador.
// - Nunca perder dados: antes de apagar, a obra e o planeamento ficam guardados em obras_arquivo.
// =====================================================================
session_start();
require_once 'db.php';
require_once 'obra_fame_comum.php';

// REGRA DE OURO 1: Validação de sessão
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}

if (file_exists('functions.php')) {
    require_once 'functions.php';
}
if (!function_exists('registrarLog')) { function registrarLog($pdo, $acao, $modulo) { return true; } }
if (!function_exists('gerar_csrf_token')) {
    function gerar_csrf_token() { if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); return $_SESSION['csrf_token']; }
    function validar_csrf_token($t) { return !empty($_SESSION['csrf_token']) && !empty($t) && hash_equals($_SESSION['csrf_token'], $t); }
}

$perfil_user = $_SESSION['perfil'] ?? '';
$user_id_atual = $_SESSION['user_id'];
$is_admin = in_array($perfil_user, ['admin', 'administrativo']);

// REGRA DE OURO 2: Acesso restrito pela matriz de permissões
$stmt_perm = $pdo->prepare("SELECT modulo FROM permissoes WHERE grupo = :perfil AND pode_aceder = 1");
$stmt_perm->execute(['perfil' => $perfil_user]);
$meus_acessos = $stmt_perm->fetchAll(PDO::FETCH_COLUMN);

if (!$is_admin && !in_array('obras', $meus_acessos)) {
    header("Location: index.php?erro=sem_permissao");
    exit;
}

// REGRA DE OURO 3: Primeiro nome no menu
$nome_completo = $_SESSION['nome'] ?? 'Utilizador';
$primeiro_nome = explode(' ', trim($nome_completo))[0];

$mensagem = '';
$tipo_mensagem = '';

if (isset($_GET['editado'])) {
    $mensagem = "Operação realizada com sucesso!";
    $tipo_mensagem = "sucesso";
}

// --- SINCRONIZAÇÃO DO PLANEAMENTO (copia as linhas do orçamento na primeira abertura) ---
if (isset($_GET['sync_plan'])) {
    // REGRA DE OURO 5: ID validado como número inteiro
    $o_id = filter_input(INPUT_GET, 'obra_id', FILTER_VALIDATE_INT);
    if (!$o_id) { header("Location: obras.php"); exit; }

    $stmt_chk_orc = $pdo->prepare("SELECT id_orcamento FROM obras WHERE id = ?");
    $stmt_chk_orc->execute([$o_id]);
    $id_orc = $stmt_chk_orc->fetchColumn();

    if ($id_orc) {
        $stmt_check_linhas = $pdo->prepare("SELECT COUNT(*) FROM planeamento_linhas WHERE obra_id = ?");
        $stmt_check_linhas->execute([$o_id]);
        if ((int)$stmt_check_linhas->fetchColumn() === 0) {
            $stmt_get_orc_linhas = $pdo->prepare("SELECT * FROM planeamento_linhas WHERE orcamento_id = ?");
            $stmt_get_orc_linhas->execute([$id_orc]);
            $linhas_orc = $stmt_get_orc_linhas->fetchAll(PDO::FETCH_ASSOC);
            if ($linhas_orc) {
                $stmt_ins_linha = $pdo->prepare("INSERT INTO planeamento_linhas (obra_id, numero_fase, nome_fase, numero_dia, numero_homens, tipo_equipa, descricao_tarefa, tempo_tarefa, concluida) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                foreach ($linhas_orc as $l) {
                    $stmt_ins_linha->execute([
                        $o_id, $l['numero_fase'], $l['nome_fase'], $l['numero_dia'],
                        $l['numero_homens'], $l['tipo_equipa'], $l['descricao_tarefa'],
                        $l['tempo_tarefa'], $l['concluida']
                    ]);
                }
            }
        }
    }
    header("Location: planeamento.php?obra_id=" . $o_id);
    exit;
}

// --- ARQUIVO (nunca perder dados): cópia da obra e do planeamento antes de apagar ---
// Criada fora de qualquer transação (CREATE TABLE faz commit implícito no MySQL).
function criarTabelaArquivoObras($pdo) {
    $pdo->exec("CREATE TABLE IF NOT EXISTS obras_arquivo (
        id INT AUTO_INCREMENT PRIMARY KEY,
        id_obra INT NOT NULL,
        acao VARCHAR(20) NOT NULL DEFAULT 'apagada',
        dados_json LONGTEXT NOT NULL,
        planeamento_json LONGTEXT NULL,
        arquivado_por INT NULL,
        arquivado_em DATETIME NOT NULL,
        INDEX (id_obra)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

// --- APAGAR OBRA (REGRA DE OURO 7 + password de segurança) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['btn_confirmar_apagar'])) {
    $id_obra = filter_input(INPUT_POST, 'id_obra_apagar', FILTER_VALIDATE_INT);
    $pass_introduzida = (string)($_POST['pass_seguranca'] ?? '');

    $stmt_pass = $pdo->prepare("SELECT valor FROM configuracoes WHERE chave = 'password_apagar' LIMIT 1");
    $stmt_pass->execute();
    $pass_config = (string)$stmt_pass->fetchColumn();

    if (!validar_csrf_token($_POST['csrf_token'] ?? '')) {
        $mensagem = "A sessão expirou ou o pedido não é válido. Atualize a página e tente de novo.";
        $tipo_mensagem = "erro";
    } elseif (!$is_admin) {
        $mensagem = "Sem permissão para apagar obras.";
        $tipo_mensagem = "erro";
    } elseif (!$id_obra) {
        $mensagem = "Obra inválida.";
        $tipo_mensagem = "erro";
    } elseif ($pass_introduzida === '' || $pass_config === '' || !hash_equals($pass_config, $pass_introduzida)) {
        $mensagem = "Password de segurança incorreta.";
        $tipo_mensagem = "erro";
    } else {
        try {
            criarTabelaArquivoObras($pdo);
            $st = $pdo->prepare("SELECT * FROM obras WHERE id = ?");
            $st->execute([$id_obra]);
            $obra_apagar = $st->fetch(PDO::FETCH_ASSOC);
            if (!$obra_apagar) throw new Exception("A obra já não existe.");
            $st = $pdo->prepare("SELECT * FROM planeamento_linhas WHERE obra_id = ?");
            $st->execute([$id_obra]);
            $plan = $st->fetchAll(PDO::FETCH_ASSOC);
            $jf = JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE;

            $pdo->beginTransaction();
            $pdo->prepare("INSERT INTO obras_arquivo (id_obra, acao, dados_json, planeamento_json, arquivado_por, arquivado_em) VALUES (?, 'apagada', ?, ?, ?, NOW())")
                ->execute([$id_obra, json_encode($obra_apagar, $jf), json_encode($plan, $jf), (int)$user_id_atual]);
            $pdo->prepare("DELETE FROM planeamento_linhas WHERE obra_id = ?")->execute([$id_obra]);
            $pdo->prepare("DELETE FROM obras WHERE id = ?")->execute([$id_obra]);
            $pdo->commit();

            registrarLog($pdo, "Apagou Obra ID: $id_obra (cópia em obras_arquivo)", "Obras");
            $mensagem = "Obra removida. Fica guardada uma cópia no arquivo.";
            $tipo_mensagem = "sucesso";
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('obras.php (apagar): ' . $e->getMessage());
            $mensagem = "Não foi possível apagar a obra (nada foi alterado).";
            $tipo_mensagem = "erro";
        }
    }
}

// --- BUSCAR OBRAS ---
// n_fame / n_fame_ok: linhas da FAME gravadas / completas (fabricante + referência + ficha técnica)
fameCriarTabelas($pdo);
$query = "SELECT o.*, c.nome as nome_cliente,
                 (SELECT COUNT(*) FROM obras_fame f WHERE f.id_obra = o.id AND f.incluir = 1) AS n_fame,
                 (SELECT COUNT(*) FROM obras_fame f WHERE f.id_obra = o.id AND f.incluir = 1 AND f.fabricante <> '' AND f.referencia <> '' AND f.ficha_tecnica <> '') AS n_fame_ok
          FROM obras o LEFT JOIN clientes c ON o.id_cliente = c.id
          ORDER BY (c.nome IS NULL) ASC, c.nome ASC,
                   CASE WHEN o.estado LIKE 'Em curso%' THEN 1 WHEN o.estado = 'Planeamento' THEN 2 WHEN o.estado = 'Suspensa' THEN 3 WHEN o.estado LIKE 'Conclu%' THEN 4 ELSE 5 END ASC,
                   o.id DESC";
$obras_raw = $pdo->query($query)->fetchAll(PDO::FETCH_ASSOC);

// Estado normalizado (o formulário grava "Em Curso"; registos antigos podem ter "Em curso")
function estadoChave($estado) {
    $e = (string)$estado;
    if (preg_match('/conclu|terminad|fechad/i', $e)) return 'concluida';
    if (preg_match('/cancel|anulad|arquiv/i', $e)) return 'concluida';
    if (preg_match('/suspens/i', $e)) return 'suspensa';
    if (preg_match('/planea|prepar/i', $e)) return 'planeamento';
    return 'curso';
}
$estados_info = [
    'curso'       => ['Em curso',    'fa-person-digging'],
    'planeamento' => ['Planeamento', 'fa-calendar-days'],
    'suspensa'    => ['Suspensas',   'fa-circle-pause'],
    'concluida'   => ['Concluídas',  'fa-circle-check'],
];
$contagens = ['ativas' => 0, 'curso' => 0, 'planeamento' => 0, 'suspensa' => 0, 'concluida' => 0, 'todas' => 0];

$obras_por_cliente = [];
foreach ($obras_raw as $o) {
    $o['_estado'] = estadoChave($o['estado']);
    $contagens[$o['_estado']]++;
    $contagens['todas']++;
    if ($o['_estado'] !== 'concluida') $contagens['ativas']++;
    $cliente = $o['nome_cliente'] ?: 'Sem cliente associado';
    $obras_por_cliente[$cliente][] = $o;
}
$lista_clientes = array_keys($obras_por_cliente);
$csrf = gerar_csrf_token();
?>
<!DOCTYPE html>
<html lang="pt-PT">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Obras - Gestão MAB</title>
    <!-- REGRA DE OURO 8: ícone MAB no separador -->
    <link rel="icon" type="image/png" href="/mab.png">
    <link rel="apple-touch-icon" href="/mab.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { font-family: system-ui, -apple-system, sans-serif; box-sizing: border-box; }
        body { background-color: #f7f9fc; margin: 0; color: #333; font-size: 14px; padding-bottom: 50px; }

        .topbar { background: rgba(255,255,255,0.94); backdrop-filter: blur(8px); -webkit-backdrop-filter: blur(8px); padding: 10px 40px; box-shadow: 0 1px 0 #e8ecf1; display: flex; justify-content: space-between; align-items: center; position: sticky; top: 0; z-index: 100; }
        .topbar-left a { display: block; }
        .topbar-left .logo { height: 35px; display: block; }
        .topbar-right { display: flex; align-items: center; gap: 12px; }
        .btn-voltar { width: 40px; height: 40px; border-radius: 50%; background: #fff; border: 1px solid #e8ecf1; color: #576574; display: inline-flex; align-items: center; justify-content: center; text-decoration: none; font-size: 16px; transition: all 0.2s ease; box-shadow: 0 2px 6px rgba(0,0,0,0.04); flex-shrink: 0; }
        .btn-voltar:hover { background: #eef2f6; color: #34495e; border-color: #d5dce4; transform: translateX(-2px); }
        .user-menu { position: relative; }
        .user-btn { background: #fdfdfd; border: 1px solid #f1f2f6; border-radius: 30px; font-size: 14px; font-weight: 600; color: #2c3e50; cursor: pointer; display: flex; align-items: center; gap: 10px; padding: 6px 15px 6px 6px; }
        .user-avatar { background: #e74c3c; color: white; width: 28px; height: 28px; border-radius: 50%; display: flex; justify-content: center; align-items: center; font-size: 14px; border: 2px solid #fadbd8; }
        .dropdown-content { display: none; position: absolute; right: 0; top: 100%; background: #fff; min-width: 180px; box-shadow: 0 10px 30px rgba(0,0,0,0.1); border-radius: 8px; border: 1px solid #f1f2f6; overflow: hidden; margin-top: 5px; z-index: 200; }
        .user-menu.aberto .dropdown-content { display: block; }
        @media (hover: hover) { .user-menu:hover .dropdown-content { display: block; } }
        .dropdown-content a { color: #576574; padding: 12px 20px; text-decoration: none; display: block; font-size: 14px; }
        .dropdown-content a:hover { background: #f0fff4; color: #2ecc71; }

        .container { max-width: 1240px; margin: 20px auto; padding: 0 16px; }
        .alerta { padding: 14px; border-radius: 10px; margin-bottom: 18px; font-weight: 600; text-align: center; }
        .alerta.sucesso { background: #e8f5e9; color: #27ae60; border: 1px solid #c8e6c9; }
        .alerta.erro { background: #fdeded; color: #e74c3c; border: 1px solid #fadbd8; }

        /* Cabeçalho da página */
        .header-box { display: flex; justify-content: space-between; align-items: center; margin-bottom: 18px; flex-wrap: wrap; gap: 12px; }
        h1 { margin: 0; color: #2c3e50; font-size: 22px; font-weight: 800; }
        h1 small { display: block; font-size: 13px; color: #7f8c8d; font-weight: 600; margin-top: 3px; }
        .header-actions { display: flex; gap: 10px; flex-wrap: wrap; }
        .btn-novo { background: #2ecc71; color: #fff; border: 1px solid #2ecc71; padding: 9px 16px; border-radius: 30px; text-decoration: none; font-weight: 700; font-size: 13px; display: inline-flex; align-items: center; gap: 7px; transition: 0.2s; }
        .btn-novo:hover { background: #27ae60; border-color: #27ae60; }
        .btn-secundario { background: #fff; color: #34495e; border: 1px solid #d5dce4; padding: 9px 16px; border-radius: 30px; text-decoration: none; font-weight: 700; font-size: 13px; display: inline-flex; align-items: center; gap: 7px; transition: 0.2s; }
        .btn-secundario:hover { border-color: #34495e; }

        /* Filtros por estado (um clique) */
        .estados { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 12px; }
        .chip { border: 1px solid #e1e5ea; background: #fff; color: #576574; border-radius: 30px; padding: 8px 14px; font-weight: 700; font-size: 13px; cursor: pointer; display: inline-flex; align-items: center; gap: 7px; transition: 0.15s; }
        .chip .n { background: #f1f2f6; color: #576574; border-radius: 20px; padding: 1px 8px; font-size: 12px; }
        .chip:hover { border-color: #2ecc71; }
        .chip.ativo { background: #2c3e50; border-color: #2c3e50; color: #fff; }
        .chip.ativo .n { background: rgba(255,255,255,0.18); color: #fff; }

        /* Barra de pesquisa */
        .ferramentas { background: #fff; border: 1px solid #e1e5ea; border-radius: 12px; padding: 10px; display: flex; gap: 10px; align-items: center; flex-wrap: wrap; margin-bottom: 18px; box-shadow: 0 2px 10px rgba(0,0,0,0.02); }
        .pesquisa { flex: 1 1 260px; position: relative; }
        .pesquisa i { position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: #95a5a6; }
        .pesquisa input { width: 100%; padding: 10px 12px 10px 36px; border: 1px solid #dcdde1; border-radius: 8px; font-size: 14px; outline: none; }
        .ferramentas select { flex: 0 1 260px; padding: 10px 12px; border: 1px solid #dcdde1; border-radius: 8px; font-size: 14px; color: #2c3e50; background: #fff; outline: none; min-width: 0; }
        .pesquisa input:focus, .ferramentas select:focus { border-color: #2ecc71; box-shadow: 0 0 0 3px rgba(46,204,113,0.1); }
        .interruptor { display: inline-flex; align-items: center; gap: 8px; font-weight: 600; color: #576574; font-size: 13px; cursor: pointer; user-select: none; white-space: nowrap; padding: 0 4px; }
        .interruptor input { width: 16px; height: 16px; accent-color: #2ecc71; }
        #contagem_obras { font-size: 13px; color: #7f8c8d; font-weight: 600; margin-left: auto; white-space: nowrap; }

        /* Grupos por cliente */
        .grupo { margin-bottom: 18px; }
        .grupo > summary { list-style: none; cursor: pointer; display: flex; align-items: center; gap: 10px; padding: 8px 4px; font-weight: 800; color: #2c3e50; font-size: 14px; text-transform: uppercase; letter-spacing: .02em; }
        .grupo > summary::-webkit-details-marker { display: none; }
        .grupo > summary .seta { transition: transform 0.2s; color: #95a5a6; font-size: 12px; width: 14px; }
        .grupo[open] > summary .seta { transform: rotate(90deg); }
        .grupo > summary .conta { margin-left: auto; font-size: 12px; color: #7f8c8d; font-weight: 600; text-transform: none; }

        /* Cartões das obras */
        .grelha { display: grid; grid-template-columns: repeat(auto-fill, minmax(330px, 1fr)); gap: 14px; }
        .obra { background: #fff; border: 1px solid #e1e5ea; border-left: 4px solid #2ecc71; border-radius: 12px; padding: 14px 16px 12px; display: flex; flex-direction: column; gap: 10px; box-shadow: 0 2px 8px rgba(0,0,0,0.03); transition: box-shadow 0.2s; }
        .obra:hover { box-shadow: 0 6px 20px rgba(0,0,0,0.07); }
        .obra.e-planeamento { border-left-color: #e67e22; }
        .obra.e-suspensa { border-left-color: #95a5a6; }
        .obra.e-concluida { border-left-color: #bdc3c7; opacity: 0.85; }

        .obra-topo { display: flex; justify-content: space-between; align-items: center; gap: 8px; }
        .ref { font-size: 12px; font-weight: 700; color: #27ae60; background: #e8f5e9; padding: 3px 8px; border-radius: 5px; }
        .ref.vazia { color: #95a5a6; background: #f1f2f6; }
        .badge { padding: 4px 9px; border-radius: 20px; font-size: 11px; font-weight: 700; text-transform: uppercase; white-space: nowrap; }
        .b-curso { background: #e8f5e9; color: #27ae60; }
        .b-planeamento { background: #fff3e0; color: #e67e22; }
        .b-suspensa { background: #f1f2f6; color: #7f8c8d; }
        .b-concluida { background: #f1f2f6; color: #95a5a6; }

        .obra-nome { margin: 0; font-size: 16px; font-weight: 800; color: #2c3e50; line-height: 1.3; }
        .obra-nome a { color: inherit; text-decoration: none; }
        .obra-nome a:hover { color: #27ae60; }
        .obra-info { display: flex; flex-direction: column; gap: 4px; font-size: 13px; color: #7f8c8d; }
        .obra-info i { width: 16px; text-align: center; color: #b2bec3; margin-right: 4px; }
        .obra-info a { color: #7f8c8d; text-decoration: none; }
        .obra-info a:hover { color: #2980b9; text-decoration: underline; }
        .cliente-linha { display: none; }
        #grelha_plana .cliente-linha { display: block; }

        .fame-prog { display: flex; align-items: center; gap: 8px; font-size: 12px; color: #7f8c8d; font-weight: 600; }
        .fame-prog .barra { flex: 1; height: 6px; background: #f1f2f6; border-radius: 6px; overflow: hidden; }
        .fame-prog .barra span { display: block; height: 100%; background: #f39c12; }
        .fame-prog.ok .barra span { background: #27ae60; }

        .atalhos { display: grid; grid-template-columns: repeat(4, 1fr); gap: 6px; border-top: 1px solid #f1f2f6; padding-top: 10px; }
        .atalho { display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 4px; padding: 8px 4px; border-radius: 8px; text-decoration: none; font-size: 11.5px; font-weight: 700; color: #576574; background: #f7f9fc; transition: 0.15s; text-align: center; min-height: 54px; }
        .atalho i { font-size: 16px; }
        .atalho:hover { background: #eef2f6; color: #2c3e50; }
        .a-plan i { color: #9b59b6; }
        .a-mat i { color: #16a085; }
        .a-fame i { color: #d35400; }
        .a-autos i { color: #2980b9; }
        .a-comp i { color: #27ae60; }
        .a-desp i { color: #c0392b; }
        .a-res i { color: #34495e; }

        .obra-rodape { display: flex; justify-content: flex-end; gap: 6px; }
        .btn-peq { border: 1px solid #e1e5ea; background: #fff; color: #576574; border-radius: 8px; padding: 6px 10px; font-size: 12px; font-weight: 700; cursor: pointer; text-decoration: none; display: inline-flex; align-items: center; gap: 6px; }
        .btn-peq:hover { border-color: #95a5a6; color: #2c3e50; }
        .btn-peq.apagar { color: #e74c3c; }
        .btn-peq.apagar:hover { border-color: #e74c3c; background: #fdeded; }

        .vazio { text-align: center; padding: 60px 20px; color: #95a5a6; font-size: 15px; background: #fff; border: 1px dashed #dcdde1; border-radius: 12px; }
        .vazio i { font-size: 32px; margin-bottom: 12px; display: block; color: #bdc3c7; }

        .modal { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.6); z-index: 1001; justify-content: center; align-items: center; padding: 20px; }
        .modal.show { display: flex; }
        .modal-content { background: #fff; padding: 28px; border-radius: 12px; width: 100%; max-width: 400px; text-align: center; }
        .modal-content input[type="password"] { width: 100%; padding: 12px; border: 1px solid #dcdde1; border-radius: 8px; margin: 15px 0; outline: none; }
        .modal-content input[type="password"]:focus { border-color: #e74c3c; }
        .modal-btns { display: flex; gap: 10px; }
        .modal-btns button { flex: 1; padding: 12px; border: none; border-radius: 8px; font-weight: 600; cursor: pointer; }
        .btn-cancelar { background: #f1f2f6; color: #576574; }
        .btn-confirmar { background: #e74c3c; color: #fff; }

        @media (max-width: 768px) {
            .topbar { padding: 10px 15px; }
            .btn-voltar { width: 38px; height: 38px; }
            .user-nome { display: none; }
            .header-actions { width: 100%; }
            .header-actions a { flex: 1; justify-content: center; }
            .estados { flex-wrap: nowrap; overflow-x: auto; padding-bottom: 4px; -webkit-overflow-scrolling: touch; }
            .chip { flex-shrink: 0; }
            .ferramentas select { flex: 1 1 100%; }
            #contagem_obras { margin-left: 0; }
            .grelha { grid-template-columns: 1fr; }
        }
        @media (max-width: 380px) {
            .atalhos { grid-template-columns: repeat(2, 1fr); }
        }
    </style>
</head>
<body>

    <header class="topbar">
        <!-- Logótipo leva sempre a index.php -->
        <div class="topbar-left"><a href="index.php" title="Página inicial"><img src="mab.png" alt="MAB" class="logo"></a></div>
        <div class="topbar-right">
            <a href="index.php" class="btn-voltar" title="Voltar" aria-label="Voltar"><i class="fa-solid fa-arrow-left"></i></a>
            <div class="user-menu">
                <button type="button" class="user-btn">
                    <div class="user-avatar"><i class="fa-solid fa-user"></i></div>
                    <span class="user-nome"><?= htmlspecialchars($primeiro_nome) ?></span>
                    <i class="fa-solid fa-caret-down" style="font-size: 12px; color: #95a5a6;"></i>
                </button>
                <div class="dropdown-content">
                    <a href="perfil.php"><i class="fa-solid fa-id-badge" style="width:20px;"></i> Perfil</a>
                    <a href="index.php?logout=1" style="color: #e74c3c; border-top: 1px solid #f1f2f6;"><i class="fa-solid fa-right-from-bracket" style="width:20px;"></i> Sair</a>
                </div>
            </div>
        </div>
    </header>

    <div class="container">
        <?php if ($mensagem): ?>
            <div class="alerta <?= $tipo_mensagem === 'sucesso' ? 'sucesso' : 'erro' ?>">
                <i class="fa-solid <?= $tipo_mensagem === 'erro' ? 'fa-triangle-exclamation' : 'fa-check-circle' ?>"></i>
                <?= htmlspecialchars($mensagem, ENT_QUOTES, 'UTF-8') ?>
            </div>
        <?php endif; ?>

        <div class="header-box">
            <h1><i class="fa-solid fa-helmet-safety" style="color: #2ecc71; margin-right: 8px;"></i>Obras
                <small><?= (int)$contagens['ativas'] ?> ativa(s) · <?= (int)$contagens['todas'] ?> no total</small>
            </h1>
            <div class="header-actions">
                <a href="autos.php" class="btn-secundario"><i class="fa-solid fa-file-invoice-dollar"></i> Autos de medição</a>
                <a href="obra_form.php" class="btn-novo"><i class="fa-solid fa-plus"></i> Nova obra</a>
            </div>
        </div>

        <!-- FILTRO POR ESTADO -->
        <div class="estados" role="tablist" aria-label="Filtrar por estado">
            <button type="button" class="chip" data-estado="ativas"><i class="fa-solid fa-bolt"></i> Ativas <span class="n"><?= (int)$contagens['ativas'] ?></span></button>
            <?php foreach ($estados_info as $k => $inf): ?>
                <button type="button" class="chip" data-estado="<?= $k ?>"><i class="fa-solid <?= $inf[1] ?>"></i> <?= htmlspecialchars($inf[0]) ?> <span class="n"><?= (int)$contagens[$k] ?></span></button>
            <?php endforeach; ?>
            <button type="button" class="chip" data-estado="todas"><i class="fa-solid fa-layer-group"></i> Todas <span class="n"><?= (int)$contagens['todas'] ?></span></button>
        </div>

        <!-- PESQUISA, CLIENTE E AGRUPAMENTO -->
        <div class="ferramentas">
            <div class="pesquisa">
                <i class="fa-solid fa-magnifying-glass"></i>
                <input type="search" id="pesquisa" placeholder="Pesquisar obra, referência, morada ou cliente…" autocomplete="off">
            </div>
            <select id="filtro_cliente" aria-label="Cliente">
                <option value="">Todos os clientes</option>
                <?php foreach ($lista_clientes as $cli_nome): ?>
                    <option value="<?= htmlspecialchars($cli_nome) ?>"><?= htmlspecialchars($cli_nome) ?></option>
                <?php endforeach; ?>
            </select>
            <label class="interruptor"><input type="checkbox" id="agrupar" checked> Agrupar por cliente</label>
            <span id="contagem_obras"></span>
        </div>

        <div id="lista">
            <?php foreach ($obras_por_cliente as $cliente => $obras_cli): ?>
                <details class="grupo" open data-cliente="<?= htmlspecialchars($cliente) ?>">
                    <summary>
                        <i class="fa-solid fa-chevron-right seta"></i>
                        <i class="fa-solid fa-user" style="color:#95a5a6;"></i> <?= htmlspecialchars($cliente) ?>
                        <span class="conta"></span>
                    </summary>
                    <div class="grelha">
                    <?php foreach ($obras_cli as $o):
                        $id = (int)$o['id'];
                        $ek = $o['_estado'];
                        $n_fame = (int)$o['n_fame'];
                        $n_fame_ok = (int)$o['n_fame_ok'];
                        $morada = trim((string)($o['morada'] ?? ''));
                        $km = (float)($o['km'] ?? 0);
                        $texto_pesquisa = mb_strtolower(implode(' ', [$o['nome'], $o['referencia_obra'] ?? '', $morada, $cliente, $o['estado']]), 'UTF-8');
                    ?>
                        <article class="obra e-<?= $ek ?>" data-estado="<?= $ek ?>" data-texto="<?= htmlspecialchars($texto_pesquisa) ?>">
                            <div class="obra-topo">
                                <?php if (!empty($o['referencia_obra'])): ?>
                                    <span class="ref"><?= htmlspecialchars($o['referencia_obra']) ?></span>
                                <?php else: ?>
                                    <span class="ref vazia">Sem referência</span>
                                <?php endif; ?>
                                <span class="badge b-<?= $ek ?>"><?= htmlspecialchars($o['estado'] ?: 'Sem estado') ?></span>
                            </div>

                            <h3 class="obra-nome"><a href="obra_form.php?id=<?= $id ?>" title="Abrir a ficha da obra"><?= htmlspecialchars($o['nome']) ?></a></h3>

                            <div class="obra-info">
                                <span class="cliente-linha"><i class="fa-solid fa-user"></i><?= htmlspecialchars($cliente) ?></span>
                                <?php if ($morada !== ''): ?>
                                    <a href="https://www.google.com/maps/search/?api=1&query=<?= rawurlencode($morada) ?>" target="_blank" rel="noopener" title="Abrir no mapa"><i class="fa-solid fa-location-dot"></i><?= htmlspecialchars($morada) ?></a>
                                <?php else: ?>
                                    <span><i class="fa-solid fa-location-dot"></i>Sem morada</span>
                                <?php endif; ?>
                                <?php if ($km > 0): ?>
                                    <span><i class="fa-solid fa-road"></i><?= number_format($km, 1, ',', ' ') ?> km</span>
                                <?php endif; ?>
                            </div>

                            <?php if ($n_fame > 0): ?>
                                <div class="fame-prog <?= $n_fame_ok === $n_fame ? 'ok' : '' ?>" title="Linhas da FAME com fabricante, referência e ficha técnica">
                                    <span>FAME <?= $n_fame_ok ?>/<?= $n_fame ?></span>
                                    <div class="barra"><span style="width: <?= (int)round($n_fame_ok * 100 / $n_fame) ?>%"></span></div>
                                </div>
                            <?php endif; ?>

                            <nav class="atalhos" aria-label="Atalhos da obra">
                                <a class="atalho a-plan" href="obras.php?sync_plan=1&amp;obra_id=<?= $id ?>"><i class="fa-solid fa-list-check"></i>Planeamento</a>
                                <a class="atalho a-mat" href="obra_material.php?id=<?= $id ?>" title="Material a aprovisionar"><i class="fa-solid fa-boxes-stacked"></i>Material</a>
                                <a class="atalho a-fame" href="obra_fame.php?id=<?= $id ?>" title="Ficha de Aprovação de Material e Equipamento"><i class="fa-solid fa-clipboard-check"></i>FAME</a>
                                <a class="atalho a-autos" href="autos.php?obra_id=<?= $id ?>" title="Autos de medição"><i class="fa-solid fa-file-invoice"></i>Autos</a>
                                <?php if ($is_admin): ?>
                                    <a class="atalho a-comp" href="obra_compras.php?id_obra=<?= $id ?>" title="Compras (faturas / encomendas)"><i class="fa-solid fa-cart-shopping"></i>Compras</a>
                                    <a class="atalho a-desp" href="obra_despesas.php?id=<?= $id ?>" title="Despesas da obra"><i class="fa-solid fa-receipt"></i>Despesas</a>
                                    <a class="atalho a-res" href="gestao.php?id_obra=<?= $id ?>" title="Resultado da obra (Gestão)"><i class="fa-solid fa-chart-line"></i>Resultado</a>
                                <?php else: ?>
                                    <a class="atalho a-desp" href="obra_despesas.php?id=<?= $id ?>" title="Despesas da obra"><i class="fa-solid fa-receipt"></i>Despesas</a>
                                <?php endif; ?>
                            </nav>

                            <div class="obra-rodape">
                                <a class="btn-peq" href="obra_form.php?id=<?= $id ?>"><i class="fa-solid fa-pen-to-square"></i> Editar</a>
                                <?php if ($is_admin): ?>
                                    <button type="button" class="btn-peq apagar" data-id="<?= $id ?>" data-nome="<?= htmlspecialchars($o['nome']) ?>"><i class="fa-solid fa-trash-can"></i> Apagar</button>
                                <?php endif; ?>
                            </div>
                        </article>
                    <?php endforeach; ?>
                    </div>
                </details>
            <?php endforeach; ?>

            <div class="grelha" id="grelha_plana" style="display:none;"></div>

            <div class="vazio" id="vazio" style="display:none;">
                <i class="fa-solid fa-folder-open"></i>
                <span id="vazio_txt">Sem obras para mostrar.</span>
            </div>
        </div>
    </div>

    <div class="modal" id="modalApagar">
        <div class="modal-content">
            <h3 style="color:#e74c3c; margin-top:0;"><i class="fa-solid fa-triangle-exclamation"></i> Apagar obra</h3>
            <p>Deseja apagar a obra:<br><strong id="txt_modal" style="color: #2c3e50; font-size: 16px; margin-top: 10px; display: inline-block;"></strong>?</p>
            <p style="font-size: 12px; color: #7f8c8d; line-height: 1.4; margin-bottom: 10px;">A obra e o planeamento saem da lista, mas fica guardada uma cópia no arquivo.</p>
            <form method="POST" id="formObraApagar">
                <?php /* REGRA DE OURO 7 */ ?><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                <input type="hidden" name="id_obra_apagar" id="id_obra_m">
                <input type="password" name="pass_seguranca" required placeholder="Password de segurança" autocomplete="off">
                <div class="modal-btns">
                    <button type="button" class="btn-cancelar" onclick="fecharModal()">Cancelar</button>
                    <button type="submit" name="btn_confirmar_apagar" value="1" class="btn-confirmar">Apagar</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // --- FILTROS: estado (chips), pesquisa, cliente e agrupamento. Ficam lembrados neste navegador. ---
        const LS = 'mab_obras_filtros';
        const nomesEstado = { ativas: 'ativas', curso: 'em curso', planeamento: 'em planeamento', suspensa: 'suspensas', concluida: 'concluídas', todas: '' };
        let estado = 'ativas';
        const elPesq = document.getElementById('pesquisa');
        const elCli = document.getElementById('filtro_cliente');
        const elAgr = document.getElementById('agrupar');
        const lista = document.getElementById('lista');

        try {
            const g = JSON.parse(localStorage.getItem(LS) || '{}');
            if (g.estado && nomesEstado[g.estado] !== undefined) estado = g.estado;
            if (typeof g.agrupar === 'boolean') elAgr.checked = g.agrupar;
            if (g.cliente && [...elCli.options].some(o => o.value === g.cliente)) elCli.value = g.cliente;
        } catch (e) {}

        // Cada cartão lembra-se da grelha do seu cliente (para poder voltar ao agrupar)
        const grupoDe = new Map(), cartoesDe = new Map();
        document.querySelectorAll('.grupo').forEach(g => {
            const grelha = g.querySelector('.grelha');
            const cartoes = [...grelha.querySelectorAll('.obra')];
            cartoesDe.set(g, cartoes);
            cartoes.forEach(o => grupoDe.set(o, grelha));
        });

        function semAcentos(s) { return s.toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, ''); }

        function filtrar() {
            const termos = semAcentos(elPesq.value.trim()).split(/\s+/).filter(Boolean);
            const cli = elCli.value;
            const agrupar = elAgr.checked;
            let total = 0, clientes = 0;

            document.querySelectorAll('.chip').forEach(c => c.classList.toggle('ativo', c.dataset.estado === estado));

            document.querySelectorAll('.grupo').forEach(g => {
                const grupoOk = !cli || g.dataset.cliente === cli;
                let n = 0;
                cartoesDe.get(g).forEach(o => {
                    const e = o.dataset.estado;
                    const estadoOk = estado === 'todas' || (estado === 'ativas' ? e !== 'concluida' : e === estado);
                    const txt = semAcentos(o.dataset.texto);
                    const mostra = grupoOk && estadoOk && termos.every(t => txt.includes(t));
                    o.style.display = mostra ? '' : 'none';
                    if (mostra) n++;
                });
                g.style.display = n > 0 ? '' : 'none';
                g.querySelector('.conta').textContent = n + (n === 1 ? ' obra' : ' obras');
                total += n; if (n > 0) clientes++;
            });

            // Sem agrupar: todos os cartões numa só grelha contínua (voltam ao grupo ao reagrupar)
            const plana = document.getElementById('grelha_plana');
            if (!agrupar) {
                document.querySelectorAll('.grupo .obra').forEach(o => plana.appendChild(o));
                document.querySelectorAll('.grupo').forEach(g => g.style.display = 'none');
                plana.style.display = total > 0 ? '' : 'none';
            } else {
                plana.querySelectorAll('.obra').forEach(o => grupoDe.get(o).appendChild(o));
                plana.style.display = 'none';
            }

            const vazio = document.getElementById('vazio');
            vazio.style.display = total === 0 ? '' : 'none';
            document.getElementById('vazio_txt').textContent = (termos.length || cli)
                ? 'Nenhuma obra corresponde à pesquisa.'
                : (estado === 'todas' ? 'Ainda não há obras.' : 'Não há obras ' + nomesEstado[estado] + '. Experimente "Todas".');
            document.getElementById('contagem_obras').textContent =
                total + (total === 1 ? ' obra' : ' obras') + (clientes > 1 ? ' · ' + clientes + ' clientes' : '');

            try { localStorage.setItem(LS, JSON.stringify({ estado, agrupar, cliente: cli })); } catch (e) {}
        }

        document.querySelectorAll('.chip').forEach(c => c.addEventListener('click', () => { estado = c.dataset.estado; filtrar(); }));
        elPesq.addEventListener('input', filtrar);
        elCli.addEventListener('change', filtrar);
        elAgr.addEventListener('change', filtrar);
        filtrar();

        // --- APAGAR (password de segurança) ---
        document.querySelectorAll('.btn-peq.apagar').forEach(b => b.addEventListener('click', () => abrirModal(b.dataset.id, b.dataset.nome)));
        function abrirModal(id, nome) {
            document.getElementById('id_obra_m').value = id;
            document.getElementById('txt_modal').textContent = nome;
            document.getElementById('modalApagar').classList.add('show');
            const p = document.querySelector('#modalApagar input[type="password"]');
            p.value = ''; p.focus();
        }
        function fecharModal() { document.getElementById('modalApagar').classList.remove('show'); }
        document.getElementById('modalApagar').addEventListener('click', e => { if (e.target.id === 'modalApagar') fecharModal(); });
        document.addEventListener('keydown', e => { if (e.key === 'Escape') fecharModal(); });

        // Menu do utilizador (toque em ecrãs táteis)
        (function () {
            const menu = document.querySelector('.user-menu'); if (!menu) return;
            const btn = menu.querySelector('.user-btn');
            btn.addEventListener('click', function (e) { e.stopPropagation(); menu.classList.toggle('aberto'); });
            document.addEventListener('click', function () { menu.classList.remove('aberto'); });
        })();
    </script>
</body>
</html>
