<?php
session_start();
require_once 'db.php';

// REGRA DE OURO 1: Validação de sessão
if (!isset($_SESSION['user_id'])) { header("Location: index.php"); exit; }

if (file_exists('functions.php')) {
    require_once 'functions.php';
} else {
    function registrarLog($pdo, $acao, $modulo) { return true; }
}

$perfil_user = $_SESSION['perfil'] ?? '';
$is_admin = in_array($perfil_user, ['admin', 'administrativo']);

// REGRA DE OURO 2: Acesso Restrito
$stmt_perm = $pdo->prepare("SELECT modulo FROM permissoes WHERE grupo = :perfil AND pode_aceder = 1");
$stmt_perm->execute(['perfil' => $perfil_user]);
$meus_acessos = $stmt_perm->fetchAll(PDO::FETCH_COLUMN);

if (!$is_admin && !in_array('termos', $meus_acessos)) {
    header("Location: index.php?erro=sem_permissao"); exit;
}

// REGRA DE OURO 3: 1º Nome
$nome_completo = $_SESSION['nome'] ?? 'Utilizador';
$primeiro_nome = explode(' ', trim($nome_completo))[0];

// --- FOTOS (out/2026): coluna "fotos" com a lista (JSON) dos ficheiros guardados na pasta imagens/ ---
try {
    $col_fotos = $pdo->query("SHOW COLUMNS FROM termos_analise LIKE 'fotos'")->fetch();
    if (!$col_fotos) $pdo->exec("ALTER TABLE termos_analise ADD COLUMN fotos TEXT NULL");
} catch (Exception $e) {}

// Guarda uma foto enviada na pasta imagens/: reduzida (máx. 1600 px) e gravada em JPG, para o PDF não ficar pesado
// e para funcionar com qualquer formato (o FPDF só lê JPG / PNG / GIF). Devolve o nome do ficheiro ou null.
function guardarFotoAnalise($tmp, $nome_original) {
    $ext = strtolower(pathinfo($nome_original, PATHINFO_EXTENSION));
    if (!in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'heic', 'heif'], true)) return null;
    if (!is_dir('imagens/')) @mkdir('imagens/', 0755, true);
    $base = 'analise_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4));
    $info = @getimagesize($tmp);
    if ($info && function_exists('imagecreatetruecolor')) {
        $origem = null;
        if ($info[2] === IMAGETYPE_JPEG) $origem = @imagecreatefromjpeg($tmp);
        elseif ($info[2] === IMAGETYPE_PNG) $origem = @imagecreatefrompng($tmp);
        elseif ($info[2] === IMAGETYPE_GIF) $origem = @imagecreatefromgif($tmp);
        elseif (defined('IMAGETYPE_WEBP') && $info[2] === IMAGETYPE_WEBP && function_exists('imagecreatefromwebp')) $origem = @imagecreatefromwebp($tmp);
        if ($origem) {
            // Rodar conforme a orientação gravada pelo telemóvel (EXIF)
            if ($info[2] === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
                $exif = @exif_read_data($tmp);
                $o = (int)($exif['Orientation'] ?? 1);
                if ($o === 3) $origem = imagerotate($origem, 180, 0);
                elseif ($o === 6) $origem = imagerotate($origem, -90, 0);
                elseif ($o === 8) $origem = imagerotate($origem, 90, 0);
            }
            $w = imagesx($origem); $h = imagesy($origem);
            $max = 1600;
            $f = min(1, $max / max($w, $h));
            $nw = (int)round($w * $f); $nh = (int)round($h * $f);
            $nova = imagecreatetruecolor($nw, $nh);
            imagefill($nova, 0, 0, imagecolorallocate($nova, 255, 255, 255));   // fundo branco (PNG transparente)
            imagecopyresampled($nova, $origem, 0, 0, 0, 0, $nw, $nh, $w, $h);
            $nome = $base . '.jpg';
            $ok = imagejpeg($nova, 'imagens/' . $nome, 82);
            imagedestroy($nova); imagedestroy($origem);
            if ($ok) return $nome;
        }
    }
    // Sem GD (ou formato que o GD não lê): guarda tal como veio, se for JPG / PNG
    if (in_array($ext, ['jpg', 'jpeg', 'png'], true)) {
        $nome = $base . '.' . ($ext === 'jpeg' ? 'jpg' : $ext);
        if (move_uploaded_file($tmp, 'imagens/' . $nome)) return $nome;
    }
    return null;
}

// Identificar Modo
$id_edit = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$modo_editar = !empty($id_edit);

// --- AJAX: AUTO-PREENCHIMENTO DE TERMO EXISTENTE ---
if (isset($_GET['ajax_get_termo'])) {
    header('Content-Type: application/json');
    $id_obra_ajax = filter_input(INPUT_GET, 'id_obra', FILTER_VALIDATE_INT);
    $stmt = $pdo->prepare("SELECT * FROM termos_analise WHERE id_obra = ? ORDER BY id DESC LIMIT 1");
    $stmt->execute([$id_obra_ajax]);
    $termo = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($termo) echo json_encode(['status' => 'success', 'data' => $termo]);
    else echo json_encode(['status' => 'empty']);
    exit;
}

// --- LÓGICA DO NUMERADOR GLOBAL (Regra de Ouro) ---
$ano = date('y');
$stmt_ref_config = $pdo->prepare("SELECT valor FROM configuracoes WHERE chave = 'ultimo_numero_projeto' LIMIT 1");
$stmt_ref_config->execute();
$ultima_ref_config = $stmt_ref_config->fetchColumn();

if ($ultima_ref_config && substr($ultima_ref_config, 0, 2) === $ano) {
    $referencia_termo_gerada = (string)((int)$ultima_ref_config + 1);
} else {
    $referencia_termo_gerada = $ano . "0100";
}

// --- CARREGAR DADOS ---
$dados = ['id_cliente'=>'','id_obra'=>'','referencia_termo'=>$referencia_termo_gerada,'numero_chamado'=>'','data_vistoria'=>date('Y-m-d'),'tecnico'=>$_SESSION['nome'], 'reparacao_possivel' => 'Sim'];
$equipamentos = [];
$fotos = [];
if ($modo_editar) {
    $stmt = $pdo->prepare("SELECT * FROM termos_analise WHERE id = ?");
    $stmt->execute([$id_edit]);
    $dados = $stmt->fetch(PDO::FETCH_ASSOC);
    $equipamentos = json_decode($dados['equipamentos_json'], true) ?: [];
    $fotos = json_decode((string)($dados['fotos'] ?? ''), true) ?: [];
}

// --- PROCESSAMENTO AJAX PARA GRAVAÇÃO ---
if (isset($_GET['ajax_save'])) {
    header('Content-Type: application/json');
    try {
        $pdo->beginTransaction();

        $id_cliente = $_POST['id_cliente'];
        if ($id_cliente === 'novo') {
            $sql_cli = "INSERT INTO clientes (nome, nif, ativo) VALUES (?, ?, 1)";
            $pdo->prepare($sql_cli)->execute([$_POST['novo_cliente_nome'], $_POST['novo_cliente_nif']]);
            $id_cliente = $pdo->lastInsertId();
        }

        $referencia_final = $_POST['referencia_termo'];
        $id_obra = $_POST['id_obra'];

        if ($id_obra === 'nova' || $_POST['id_cliente'] === 'novo') {
            $sql_obra = "INSERT INTO obras (id_cliente, nome, referencia_obra, estado) VALUES (?, ?, ?, 'Em Curso')";
            $pdo->prepare($sql_obra)->execute([$id_cliente, $_POST['nova_obra_nome'], $referencia_final]);
            $id_obra = $pdo->lastInsertId();

            // Atualizar o numerador global nas configurações
            $pdo->prepare("UPDATE configuracoes SET valor = ? WHERE chave = 'ultimo_numero_projeto'")->execute([$referencia_final]);
        }

        // --- EQUIPAMENTOS (numerados pela ordem) + FOTOS DE CADA EQUIPAMENTO ---
        // Cada linha traz as fotos que ficaram (fotos_existentes, JSON) e as novas chegam em $_FILES['fotos_eq'][chave linha][]
        $fotos_eq_antigas = [];
        foreach ($equipamentos as $eq_old) foreach (($eq_old['fotos'] ?? []) as $fo) $fotos_eq_antigas[] = basename((string)$fo);
        $equipas_finais = [];
        $fotos_eq_mantidas = [];
        foreach (($_POST['equipas'] ?? []) as $chave_eq => $eq) {
            $eq = is_array($eq) ? $eq : [];
            $lista = [];
            foreach ((json_decode((string)($eq['fotos_existentes'] ?? '[]'), true) ?: []) as $fo) {
                $fo = basename((string)$fo);
                if (in_array($fo, $fotos_eq_antigas, true)) { $lista[] = $fo; $fotos_eq_mantidas[] = $fo; }
            }
            if (!empty($_FILES['fotos_eq']['name'][$chave_eq]) && is_array($_FILES['fotos_eq']['name'][$chave_eq])) {
                foreach ($_FILES['fotos_eq']['name'][$chave_eq] as $k => $nome_f) {
                    if (($_FILES['fotos_eq']['error'][$chave_eq][$k] ?? 1) !== UPLOAD_ERR_OK) continue;
                    $novo = guardarFotoAnalise($_FILES['fotos_eq']['tmp_name'][$chave_eq][$k], $nome_f);
                    if ($novo) $lista[] = $novo;
                }
            }
            unset($eq['fotos_existentes']);
            $eq['fotos'] = $lista;
            $equipas_finais[] = $eq;
        }
        // apagar do disco as fotos de equipamentos que foram retiradas
        foreach (array_diff($fotos_eq_antigas, $fotos_eq_mantidas) as $fo) {
            if (is_file('imagens/' . $fo)) @unlink('imagens/' . $fo);
        }
        $equipas_json = json_encode($equipas_finais);

        $resumo_eq = $equipas_finais[0]['descricao'] ?? 'Equipamento';
        $reparacao = $_POST['reparacao_possivel'] ?? 'Sim';

        // --- FOTOS: as que ficaram + as novas ---
        $fotos_finais = $fotos;   // as que já estavam gravadas (em modo editar)
        $remover = json_decode($_POST['fotos_remover'] ?? '[]', true) ?: [];
        foreach ($remover as $rm) {
            $rm = basename((string)$rm);
            if (in_array($rm, $fotos_finais, true)) {
                if (is_file('imagens/' . $rm)) @unlink('imagens/' . $rm);
                $fotos_finais = array_values(array_diff($fotos_finais, [$rm]));
            }
        }
        if (!empty($_FILES['fotos']['name'][0])) {
            foreach ($_FILES['fotos']['name'] as $k => $nome_f) {
                if (($_FILES['fotos']['error'][$k] ?? 1) !== UPLOAD_ERR_OK) continue;
                $novo = guardarFotoAnalise($_FILES['fotos']['tmp_name'][$k], $nome_f);
                if ($novo) $fotos_finais[] = $novo;
            }
        }
        $fotos_json = json_encode(array_values($fotos_finais));

        if ($modo_editar) {
            $sql = "UPDATE termos_analise SET id_obra=?, id_cliente=?, referencia_termo=?, numero_chamado=?, data_vistoria=?, tecnico=?, sintomas=?, constatacoes=?, causa=?, reparacao_possivel=?, conclusao_tecnica=?, observacoes=?, pecas_lista=?, mao_obra=?, total_orcamento=?, equipamento_resumo=?, equipamentos_json=?, fotos=? WHERE id=?";
            $pdo->prepare($sql)->execute([
                $id_obra, $id_cliente, $referencia_final, $_POST['numero_chamado'], $_POST['data_vistoria'], $_POST['tecnico'], $_POST['sintomas'], $_POST['constatacoes'], $_POST['causa'], $reparacao, $_POST['conclusao_tecnica'], $_POST['observacoes'], $_POST['pecas_lista'], $_POST['mao_obra'], $_POST['total_orcamento'], $resumo_eq, $equipas_json, $fotos_json, $id_edit
            ]);
        } else {
            $sql = "INSERT INTO termos_analise (id_obra, id_cliente, referencia_termo, numero_chamado, data_vistoria, tecnico, sintomas, constatacoes, causa, reparacao_possivel, conclusao_tecnica, observacoes, pecas_lista, mao_obra, total_orcamento, equipamento_resumo, equipamentos_json, fotos) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)";
            $pdo->prepare($sql)->execute([
                $id_obra, $id_cliente, $referencia_final, $_POST['numero_chamado'], $_POST['data_vistoria'], $_POST['tecnico'], $_POST['sintomas'], $_POST['constatacoes'], $_POST['causa'], $reparacao, $_POST['conclusao_tecnica'], $_POST['observacoes'], $_POST['pecas_lista'], $_POST['mao_obra'], $_POST['total_orcamento'], $resumo_eq, $equipas_json, $fotos_json
            ]);
        }

        $pdo->commit();
        echo json_encode(['status' => 'success']);
    } catch(Exception $e) { if ($pdo->inTransaction()) $pdo->rollBack(); echo json_encode(['status' => 'error', 'message' => $e->getMessage()]); }
    exit;
}

$clientes = $pdo->query("SELECT id, nome, nif FROM clientes WHERE ativo = 1 ORDER BY nome ASC")->fetchAll(PDO::FETCH_ASSOC);
$obras = $pdo->query("SELECT id, id_cliente, nome, referencia_obra FROM obras WHERE estado != 'Concluída' ORDER BY nome ASC")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="pt-PT">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Relatório Técnico - MAB</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { font-family: system-ui, sans-serif; background: #f7f9fc; margin: 0; color: #333; font-size: 13px; padding-bottom: 100px; }
        * { box-sizing: border-box; }
        .topbar { background: #fff; padding: 10px 40px; box-shadow: 0 2px 15px rgba(0,0,0,0.05); display: flex; justify-content: space-between; align-items: center; position: sticky; top: 0; z-index: 100; }
        .topbar-left .logo { height: 35px; }
        .topbar-right { display: flex; align-items: center; gap: 20px; }
        .btn-voltar { width: 40px; height: 40px; border-radius: 50%; background: #fff; border: 1px solid #e8ecf1; color: #576574; display: inline-flex; align-items: center; justify-content: center; text-decoration: none; font-size: 16px; transition: all 0.2s ease; box-shadow: 0 2px 6px rgba(0,0,0,0.04); }
        .btn-voltar:hover { background: #eef2f6; color: #34495e; transform: translateX(-2px); }
        .user-menu { display: flex; align-items: center; gap: 10px; font-weight: 600; color: #2c3e50; }
        .user-avatar { background: #e74c3c; color: white; width: 28px; height: 28px; border-radius: 50%; display: flex; justify-content: center; align-items: center; font-size: 14px; }

        .container { max-width: 1000px; margin: 30px auto; padding: 0 20px; }
        .card { background: #fff; padding: 25px; border-radius: 12px; border: 1px solid #e1e5ea; margin-bottom: 25px; box-shadow: 0 4px 15px rgba(0,0,0,0.02); }
        .card h2 { font-size: 14px; color: #4a69bd; border-bottom: 1px solid #f1f2f6; padding-bottom: 10px; text-transform: uppercase; margin-top: 0; margin-bottom: 20px;}
        .form-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; }
        .form-group { display: flex; flex-direction: column; gap: 5px; }
        .form-group.full { grid-column: 1 / -1; }
        label { font-weight: 700; font-size: 11px; color: #576574; text-transform: uppercase; }
        input, select, textarea { padding: 10px; border: 1px solid #dcdde1; border-radius: 8px; font-size: 13px; outline: none; background: #fff; font-family: inherit;}
        input:focus, select:focus { border-color: #4a69bd; box-shadow: 0 0 0 3px rgba(74, 105, 189, 0.1); }
        input[readonly] { background: #f8f9fa; color: #2c3e50; font-weight: 800; cursor: not-allowed; border-color: #dcdde1; }

        .box-fast { display: none; background: #fdfaf2; padding: 20px; border-radius: 8px; border: 1px dashed #f39c12; margin-top: 15px; }
        .floating-action { position: fixed; bottom: 25px; right: 25px; z-index: 100; }
        .btn-save { background: #4a69bd; color: #fff; padding: 15px 45px; border-radius: 10px; border: none; font-weight: 700; cursor: pointer; box-shadow: 0 5px 15px rgba(74,105,189,0.3); text-transform: uppercase; transition: 0.2s; }
        .btn-save:hover { filter: brightness(0.9); transform: translateY(-2px); }
        .btn-save:disabled { opacity: .7; cursor: wait; }

        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th { background: #f8f9fa; padding: 10px; font-size: 11px; text-align: left; border-bottom: 2px solid #dee2e6; color: #576574;}
        td { padding: 8px; border-bottom: 1px solid #f1f2f6; }

        /* Registo fotográfico */
        .fotos-acoes { display: flex; gap: 10px; flex-wrap: wrap; margin-bottom: 15px; }
        .btn-foto { display: inline-flex; align-items: center; gap: 8px; background: #eef2fa; color: #4a69bd; border: 1px dashed #4a69bd; padding: 12px 18px; border-radius: 10px; font-weight: 700; cursor: pointer; font-size: 13px; font-family: inherit; }
        .btn-foto:hover { background: #dfe7f7; }
        /* Equipamentos numerados + fotos por equipamento */
        .eq-num { font-weight: 800; color: #4a69bd; text-align: center; font-size: 14px; }
        .eq-foto-btns { display: flex; gap: 6px; flex-wrap: wrap; }
        .btn-foto-mini { display: inline-flex; align-items: center; gap: 5px; background: #eef2fa; color: #4a69bd; border: 1px dashed #4a69bd; padding: 7px 10px; border-radius: 8px; font-weight: 700; cursor: pointer; font-size: 12px; font-family: inherit; white-space: nowrap; }
        .btn-foto-mini:hover { background: #dfe7f7; }
        .eq-foto-conta { font-size: 11px; font-weight: 700; color: #7f8c8d; align-self: center; }
        .btn-eq-del { background: none; border: none; color: #e74c3c; cursor: pointer; font-size: 15px; }
        .eq-fotos-grelha { display: flex; flex-direction: column; gap: 12px; margin-top: 15px; }
        .eq-fotos-bloco { border: 1px solid #e1e5ea; border-radius: 10px; padding: 10px; }
        .eq-fotos-bloco h4 { margin: 0 0 8px; font-size: 13px; color: #2c3e50; }
        .eq-fotos-bloco .fotos-grelha { grid-template-columns: repeat(auto-fill, minmax(120px, 1fr)); }
        .fotos-grelha { display: grid; grid-template-columns: repeat(auto-fill, minmax(140px, 1fr)); gap: 12px; }
        .foto-item { position: relative; border-radius: 10px; overflow: hidden; border: 1px solid #e1e5ea; background: #f8f9fa; aspect-ratio: 4 / 3; }
        .foto-item img { width: 100%; height: 100%; object-fit: cover; display: block; cursor: zoom-in; }
        .foto-item .foto-del { position: absolute; top: 6px; right: 6px; width: 30px; height: 30px; border-radius: 50%; border: none; background: rgba(231,76,60,0.92); color: #fff; cursor: pointer; font-size: 13px; display: flex; align-items: center; justify-content: center; }
        .foto-item .foto-nova { position: absolute; left: 6px; bottom: 6px; background: rgba(74,105,189,0.92); color: #fff; font-size: 10px; font-weight: 800; padding: 2px 7px; border-radius: 10px; text-transform: uppercase; }
        .fotos-vazio { color: #95a5a6; font-size: 13px; padding: 10px 0; }
        .foto-zoom { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.85); z-index: 1000; align-items: center; justify-content: center; padding: 20px; }
        .foto-zoom.show { display: flex; }
        .foto-zoom img { max-width: 100%; max-height: 100%; border-radius: 8px; }

        @media (max-width: 768px) {
            .topbar { padding: 10px 15px; }
            .container { margin: 20px auto; padding: 0 12px; }
            .card { padding: 18px; }
            .floating-action { left: 12px; right: 12px; bottom: 12px; }
            .btn-save { width: 100%; padding: 15px; }
            /* Tabela dos equipamentos passa a cartões */
            #tabEq thead { display: none; }
            #tabEq, #tabEq tbody, #tabEq tr, #tabEq td { display: block; width: 100% !important; }
            #tabEq tr { border: 1px solid #e1e5ea; border-radius: 10px; padding: 8px; margin-bottom: 10px; }
            #tabEq td { border: none; padding: 4px 0; }
            #tabEq td.eq-num { text-align: left; }
            #tabEq td::before { content: attr(data-label); display: block; font-size: 10px; font-weight: 700; color: #95a5a6; text-transform: uppercase; margin-bottom: 3px; }
            .fotos-grelha { grid-template-columns: repeat(2, 1fr); }
        }
    </style>
</head>
<body>
    <header class="topbar">
        <div class="topbar-left"><a href="index.php" title="Página inicial"><img src="mab.png" class="logo" alt="MAB"></a></div>
        <div class="topbar-right">
            <a href="termos_analise.php" class="btn-voltar" title="Voltar" aria-label="Voltar"><i class="fa-solid fa-arrow-left"></i></a>
            <div class="user-menu">
                <div class="user-avatar"><i class="fa-solid fa-user"></i></div>
                <?= htmlspecialchars($primeiro_nome) ?>
            </div>
        </div>
    </header>

    <div class="container">
        <h1 style="color:#2c3e50; font-weight:800; margin-bottom:25px;"><i class="fa-solid fa-laptop-medical" style="color:#4a69bd;"></i> <?= $modo_editar ? 'Editar Relatório' : 'Novo Relatório de Análise' ?></h1>

        <form id="formAnalise" onsubmit="event.preventDefault(); salvar();" enctype="multipart/form-data">
            <input type="hidden" name="fotos_remover" id="fotos_remover" value="[]">
            <div class="card">
                <h2>1. Identificação e Numerador</h2>
                <div class="form-grid">
                    <div class="form-group">
                        <label>Referência Interna (ID Unico)</label>
                        <input type="text" name="referencia_termo" value="<?= htmlspecialchars($dados['referencia_termo']) ?>" readonly>
                    </div>
                    <div class="form-group">
                        <label>Data da Vistoria *</label>
                        <input type="date" name="data_vistoria" value="<?= htmlspecialchars($dados['data_vistoria']) ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Número Chamado/Sinistro</label>
                        <input type="text" name="numero_chamado" value="<?= htmlspecialchars($dados['numero_chamado']) ?>" placeholder="Ex: SIN-2026-001">
                    </div>
                    <div class="form-group">
                        <label>Técnico Responsável *</label>
                        <input type="text" name="tecnico" value="<?= htmlspecialchars($dados['tecnico']) ?>" required>
                    </div>

                    <div class="form-group full">
                        <label>Cliente *</label>
                        <select name="id_cliente" id="sel_cli" onchange="atualizarInterfaceCliente()" required>
                            <option value="">-- Selecione o Cliente --</option>
                            <option value="novo" style="color:#f39c12; font-weight:bold;">+ CRIAR NOVO CLIENTE</option>
                            <?php foreach($clientes as $c): ?>
                                <option value="<?= $c['id'] ?>" <?= $dados['id_cliente']==$c['id']?'selected':'' ?>><?= htmlspecialchars($c['nome']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group full">
                        <label>Obra / Projeto *</label>
                        <select name="id_obra" id="sel_obra" onchange="aoSelecionarObra()" required>
                            <option value="">-- Primeiro escolha o Cliente --</option>
                        </select>
                    </div>
                </div>

                <div id="box_novo_cliente" class="box-fast">
                    <h4 style="margin:0 0 15px 0; color:#d35400;">Ficha de Novo Cliente</h4>
                    <div class="form-grid">
                        <div class="form-group" style="grid-column: span 2;"><label>Nome Cliente</label><input type="text" name="novo_cliente_nome"></div>
                        <div class="form-group"><label>NIF</label><input type="text" name="novo_cliente_nif"></div>
                    </div>
                </div>

                <div id="box_nova_obra" class="box-fast">
                    <h4 style="margin:0 0 15px 0; color:#d35400;">Dados de Nova Obra</h4>
                    <div class="form-grid">
                        <div class="form-group" style="grid-column: span 2;"><label>Nome da Obra / Localização</label><input type="text" name="nova_obra_nome"></div>
                        <div class="form-group"><label>Referência a Atribuir</label><input type="text" name="nova_obra_ref_fixa" value="<?= $referencia_termo_gerada ?>" readonly></div>
                    </div>
                </div>
            </div>

            <div class="card">
                <h2>2. Especificações do Equipamento</h2>
                <table id="tabEq">
                    <thead><tr><th width="50">Nº</th><th width="34%">Descrição</th><th width="16%">Marca</th><th width="16%">Modelo</th><th width="14%">S/N</th><th>Fotos</th><th width="40"></th></tr></thead>
                    <tbody id="corpoEq"></tbody>
                </table>
                <button type="button" onclick="addEq()" style="margin-top:15px; cursor:pointer; background:none; border:none; color:#4a69bd; font-weight:bold; display:flex; align-items:center; gap:5px;"><i class="fa-solid fa-circle-plus"></i> Adicionar outro aparelho</button>
                <div class="eq-fotos-grelha" id="eqFotosGrelha"></div>
            </div>

            <div class="card">
                <h2>3. Constatações e Diagnóstico</h2>
                <div class="form-group full"><label>Sintomas Relatados</label><textarea name="sintomas" rows="2"><?= htmlspecialchars($dados['sintomas']??'') ?></textarea></div><br>
                <div class="form-group full"><label>Constatações Técnicas</label><textarea name="constatacoes" rows="3"><?= htmlspecialchars($dados['constatacoes']??'') ?></textarea></div>

                <div class="form-grid" style="margin-top:15px;">
                    <div class="form-group"><label>Causa Provável</label><input type="text" name="causa" value="<?= htmlspecialchars($dados['causa']??'') ?>"></div>
                    <div class="form-group">
                        <label style="color:#d35400;">Reparação Possível / Viável?</label>
                        <select name="reparacao_possivel" id="sel_reparacao" onchange="toggleReparacao()" style="font-weight:bold;">
                            <option value="Sim" <?= ($dados['reparacao_possivel'] ?? 'Sim') == 'Sim' ? 'selected' : '' ?>>Sim, é possível reparar</option>
                            <option value="Não" <?= ($dados['reparacao_possivel'] ?? '') == 'Não' ? 'selected' : '' ?>>Não, reparo inviável / dano total</option>
                        </select>
                    </div>
                </div>
                <div class="form-group full" style="margin-top:15px;"><label>Observações</label><textarea name="observacoes" rows="2"><?= htmlspecialchars($dados['observacoes']??'') ?></textarea></div>
            </div>

            <!-- REGISTO FOTOGRÁFICO (fotos guardadas na pasta imagens/) -->
            <div class="card">
                <h2><i class="fa-solid fa-camera"></i> Registo Fotográfico</h2>
                <div class="fotos-acoes">
                    <button type="button" class="btn-foto" onclick="document.getElementById('inp_camera').click()"><i class="fa-solid fa-camera"></i> Tirar foto</button>
                    <button type="button" class="btn-foto" onclick="document.getElementById('inp_galeria').click()"><i class="fa-solid fa-images"></i> Escolher fotos</button>
                    <!-- Câmara (telemóvel) e galeria: as fotos escolhidas juntam-se todas -->
                    <input type="file" id="inp_camera" accept="image/*" capture="environment" style="display:none;" onchange="juntarFotos(this)">
                    <input type="file" id="inp_galeria" accept="image/*" multiple style="display:none;" onchange="juntarFotos(this)">
                </div>
                <div class="fotos-grelha" id="fotosGrelha">
                    <?php foreach ($fotos as $f): ?>
                        <div class="foto-item" data-existente="<?= htmlspecialchars($f) ?>">
                            <img src="imagens/<?= htmlspecialchars(rawurlencode($f)) ?>" alt="" onclick="verFoto(this.src)">
                            <button type="button" class="foto-del" title="Retirar foto" onclick="retirarFotoExistente(this)"><i class="fa-solid fa-xmark"></i></button>
                        </div>
                    <?php endforeach; ?>
                </div>
                <div class="fotos-vazio" id="fotosVazio" style="<?= $fotos ? 'display:none;' : '' ?>">Sem fotos. As fotos saem no PDF do relatório, na secção "Registo Fotográfico".</div>
            </div>

            <div class="card" id="card_orcamento">
                <h2>4. Orçamento de Reparo</h2>
                <div class="form-grid">
                    <div class="form-group full"><label>Peças Necessárias</label><textarea name="pecas_lista" rows="2"><?= htmlspecialchars($dados['pecas_lista']??'') ?></textarea></div>
                    <div class="form-group"><label>Mão de Obra (€)</label><input type="number" step="0.01" name="mao_obra" id="v_mo" value="<?= $dados['mao_obra']??'0' ?>"></div>
                    <div class="form-group"><label>Valor Total do Reparo (€)</label><input type="number" step="0.01" name="total_orcamento" id="v_total" value="<?= $dados['total_orcamento']??'0' ?>" style="background:#eef2fa;"></div>
                </div>
            </div>

            <div class="card" id="card_conclusao" style="display:none; border-left: 5px solid #e74c3c;">
                <h2 style="color:#e74c3c;">4. Conclusão Técnica (Inviabilidade)</h2>
                <div class="form-group full">
                    <label>Justificação Detalhada</label>
                    <textarea name="conclusao_tecnica" rows="3"><?= htmlspecialchars($dados['conclusao_tecnica']??'') ?></textarea>
                </div>
            </div>

            <div class="floating-action">
                <button type="submit" class="btn-save" id="btnSave"><i class="fa-solid fa-floppy-disk"></i> Guardar Relatório</button>
            </div>
        </form>
    </div>

    <div class="foto-zoom" id="fotoZoom" onclick="this.classList.remove('show')"><img src="" alt=""></div>

    <script>
        const obrasData = <?= json_encode($obras) ?>;
        let eqCount = 0;
        const escAttr = t => String(t ?? '').replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;');

        // ---------- Equipamentos (numerados) com fotos próprias ----------
        const fotosEqNovas = {};      // chave da linha → [File] ainda não gravados
        const fotosEqExist = {};      // chave da linha → [nome do ficheiro] já gravados
        function addEq(data = {}) {
            const k = eqCount;
            fotosEqNovas[k] = [];
            fotosEqExist[k] = Array.isArray(data.fotos) ? data.fotos.slice() : [];
            const row = `<tr data-eq="${k}">
                <td data-label="Nº" class="eq-num"></td>
                <td data-label="Descrição"><input type="text" name="equipas[${k}][descricao]" value="${escAttr(data.descricao)}" style="width:100%" required oninput="renderFotosEq()"></td>
                <td data-label="Marca"><input type="text" name="equipas[${k}][marca]" value="${escAttr(data.marca)}" style="width:100%"></td>
                <td data-label="Modelo"><input type="text" name="equipas[${k}][modelo]" value="${escAttr(data.modelo)}" style="width:100%"></td>
                <td data-label="S/N"><input type="text" name="equipas[${k}][sn]" value="${escAttr(data.sn)}" style="width:100%"></td>
                <td data-label="Fotos">
                    <input type="hidden" name="equipas[${k}][fotos_existentes]" class="eq-fotos-exist" value="">
                    <div class="eq-foto-btns">
                        <button type="button" class="btn-foto-mini" onclick="document.getElementById('eqcam_${k}').click()" title="Tirar foto"><i class="fa-solid fa-camera"></i></button>
                        <button type="button" class="btn-foto-mini" onclick="document.getElementById('eqgal_${k}').click()" title="Escolher imagens"><i class="fa-solid fa-images"></i></button>
                        <span class="eq-foto-conta" id="eqconta_${k}"></span>
                    </div>
                    <input type="file" id="eqcam_${k}" accept="image/*" capture="environment" style="display:none;" onchange="juntarFotosEq(${k}, this)">
                    <input type="file" id="eqgal_${k}" accept="image/*" multiple style="display:none;" onchange="juntarFotosEq(${k}, this)">
                </td>
                <td><button type="button" class="btn-eq-del" title="Retirar este aparelho" onclick="retirarEq(${k})"><i class="fa-solid fa-trash-can"></i></button></td>
            </tr>`;
            document.getElementById('corpoEq').insertAdjacentHTML('beforeend', row);
            eqCount++;
            renumerarEq();
        }
        function renumerarEq() {
            document.querySelectorAll('#corpoEq tr').forEach((tr, i) => {
                const c = tr.querySelector('.eq-num');
                if (c) c.textContent = String(i + 1).padStart(2, '0');
                const k = tr.dataset.eq;
                const h = tr.querySelector('.eq-fotos-exist');
                if (h) h.value = JSON.stringify(fotosEqExist[k] || []);
            });
            renderFotosEq();
        }
        function retirarEq(k) {
            const linhas = document.querySelectorAll('#corpoEq tr');
            if (linhas.length <= 1) { alert('Tem de haver pelo menos um aparelho.'); return; }
            const tr = document.querySelector('#corpoEq tr[data-eq="' + k + '"]');
            const nFotos = (fotosEqExist[k] || []).length + (fotosEqNovas[k] || []).length;
            if (!confirm('Retirar este aparelho' + (nFotos ? ' e as suas ' + nFotos + ' foto(s)' : '') + '?')) return;
            if (tr) tr.remove();
            delete fotosEqNovas[k]; delete fotosEqExist[k];
            renumerarEq();
        }
        function juntarFotosEq(k, inp) {
            [...inp.files].forEach(f => {
                if (!f.type.startsWith('image/') && !/\.(heic|heif)$/i.test(f.name)) return;
                f._url = URL.createObjectURL(f);
                fotosEqNovas[k].push(f);
            });
            inp.value = '';
            renderFotosEq();
        }
        // Mostra, por baixo da tabela, as fotos de cada aparelho (com o nº da linha)
        function renderFotosEq() {
            const grelha = document.getElementById('eqFotosGrelha');
            if (!grelha) return;
            grelha.innerHTML = '';
            document.querySelectorAll('#corpoEq tr').forEach((tr, i) => {
                const k = tr.dataset.eq;
                const exist = fotosEqExist[k] || [], novas = fotosEqNovas[k] || [];
                const conta = document.getElementById('eqconta_' + k);
                if (conta) conta.textContent = (exist.length + novas.length) ? (exist.length + novas.length) + ' foto(s)' : '';
                if (!exist.length && !novas.length) return;
                const desc = (tr.querySelector('input[name$="[descricao]"]') || {}).value || '';
                const bloco = document.createElement('div');
                bloco.className = 'eq-fotos-bloco';
                bloco.innerHTML = '<h4>Aparelho ' + String(i + 1).padStart(2, '0') + (desc ? ' — ' + escAttr(desc) : '') + '</h4><div class="fotos-grelha"></div>';
                const g = bloco.querySelector('.fotos-grelha');
                exist.forEach(nome => {
                    const d = document.createElement('div');
                    d.className = 'foto-item';
                    d.innerHTML = '<img src="imagens/' + encodeURIComponent(nome) + '" alt="" onclick="verFoto(this.src)"><button type="button" class="foto-del" title="Retirar foto"><i class="fa-solid fa-xmark"></i></button>';
                    d.querySelector('.foto-del').onclick = () => { fotosEqExist[k] = fotosEqExist[k].filter(x => x !== nome); renumerarEq(); };
                    g.appendChild(d);
                });
                novas.forEach(f => {
                    const d = document.createElement('div');
                    d.className = 'foto-item';
                    d.innerHTML = '<img src="' + f._url + '" alt="" onclick="verFoto(this.src)"><span class="foto-nova">Nova</span><button type="button" class="foto-del" title="Retirar foto"><i class="fa-solid fa-xmark"></i></button>';
                    d.querySelector('.foto-del').onclick = () => { fotosEqNovas[k] = fotosEqNovas[k].filter(x => x !== f); URL.revokeObjectURL(f._url); renderFotosEq(); };
                    g.appendChild(d);
                });
                grelha.appendChild(bloco);
            });
        }

        // ---------- Fotos ----------
        let fotosNovas = [];        // ficheiros escolhidos ainda não gravados
        const fotosRemover = [];    // fotos já gravadas que se retiram ao gravar
        function atualizarVazio() {
            document.getElementById('fotosVazio').style.display = document.querySelectorAll('#fotosGrelha .foto-item').length ? 'none' : '';
        }
        function juntarFotos(inp) {
            [...inp.files].forEach(f => {
                if (!f.type.startsWith('image/') && !/\.(heic|heif)$/i.test(f.name)) return;
                fotosNovas.push(f);
                const div = document.createElement('div');
                div.className = 'foto-item';
                div.dataset.nova = '1';
                const url = URL.createObjectURL(f);
                div.innerHTML = '<img src="' + url + '" alt="" onclick="verFoto(this.src)"><span class="foto-nova">Nova</span>'
                              + '<button type="button" class="foto-del" title="Retirar foto"><i class="fa-solid fa-xmark"></i></button>';
                div.querySelector('.foto-del').addEventListener('click', () => {
                    fotosNovas = fotosNovas.filter(x => x !== f);
                    URL.revokeObjectURL(url); div.remove(); atualizarVazio();
                });
                document.getElementById('fotosGrelha').appendChild(div);
            });
            inp.value = '';
            atualizarVazio();
        }
        function retirarFotoExistente(btn) {
            const div = btn.closest('.foto-item');
            fotosRemover.push(div.dataset.existente);
            document.getElementById('fotos_remover').value = JSON.stringify(fotosRemover);
            div.remove(); atualizarVazio();
        }
        // Reduz a foto no próprio telemóvel/computador antes de enviar (máx. 1600 px, JPG): envio mais rápido
        // e sem ultrapassar o limite de tamanho do alojamento. Se não conseguir, envia a original.
        function reduzirFoto(f) {
            return new Promise(resolve => {
                const url = URL.createObjectURL(f);
                const img = new Image();
                img.onload = () => {
                    const max = 1600, fator = Math.min(1, max / Math.max(img.naturalWidth, img.naturalHeight));
                    const cv = document.createElement('canvas');
                    cv.width = Math.round(img.naturalWidth * fator); cv.height = Math.round(img.naturalHeight * fator);
                    const cx = cv.getContext('2d');
                    cx.fillStyle = '#fff'; cx.fillRect(0, 0, cv.width, cv.height);
                    cx.drawImage(img, 0, 0, cv.width, cv.height);
                    URL.revokeObjectURL(url);
                    cv.toBlob(b => resolve(b ? new File([b], f.name.replace(/\.[^.]+$/, '') + '.jpg', { type: 'image/jpeg' }) : f), 'image/jpeg', 0.85);
                };
                img.onerror = () => { URL.revokeObjectURL(url); resolve(f); };
                img.src = url;
            });
        }
        function verFoto(src) {
            const z = document.getElementById('fotoZoom');
            z.querySelector('img').src = src;
            z.classList.add('show');
        }

        function toggleReparacao() {
            const val = document.getElementById('sel_reparacao').value;
            document.getElementById('card_orcamento').style.display = (val === 'Sim') ? 'block' : 'none';
            document.getElementById('card_conclusao').style.display = (val === 'Não') ? 'block' : 'none';
        }

        function atualizarInterfaceCliente() {
            const id = document.getElementById('sel_cli').value;
            document.getElementById('box_novo_cliente').style.display = (id==='novo')?'block':'none';
            const selObra = document.getElementById('sel_obra');
            selObra.innerHTML = '<option value="">-- Selecione a Obra --</option>';
            if(id==='novo') selObra.innerHTML += '<option value="nova" selected>+ NOVA OBRA AUTOMÁTICA</option>';
            else if(id) {
                obrasData.filter(o => o.id_cliente == id).forEach(o => selObra.add(new Option(o.nome, o.id)));
                selObra.innerHTML += '<option value="nova">+ CRIAR NOVA OBRA PARA ESTE CLIENTE</option>';
            }
            aoSelecionarObra();
        }

        function aoSelecionarObra() {
            const id_obra = document.getElementById('sel_obra').value;
            document.getElementById('box_nova_obra').style.display = (id_obra === 'nova') ? 'block' : 'none';

            if (id_obra && id_obra !== 'nova' && !<?= $modo_editar ? 'true' : 'false' ?>) {
                fetch('?ajax_get_termo=1&id_obra=' + id_obra)
                .then(res => res.json())
                .then(json => {
                    if (json.status === 'success') {
                        if(confirm('Já existe um Relatório para esta Obra. Deseja importar os dados técnicos para este novo registo?')) {
                            const d = json.data;
                            document.querySelector('[name="sintomas"]').value = d.sintomas || '';
                            document.querySelector('[name="constatacoes"]').value = d.constatacoes || '';
                            document.querySelector('[name="causa"]').value = d.causa || '';
                            document.querySelector('[name="reparacao_possivel"]').value = d.reparacao_possivel || 'Sim';
                            document.querySelector('[name="pecas_lista"]').value = d.pecas_lista || '';
                            document.querySelector('[name="mao_obra"]').value = d.mao_obra || '0';
                            document.querySelector('[name="total_orcamento"]').value = d.total_orcamento || '0';
                            toggleReparacao();
                            if (d.equipamentos_json) {
                                try {
                                    const eqs = JSON.parse(d.equipamentos_json);
                                    document.getElementById('corpoEq').innerHTML = '';
                                    eqCount = 0;
                                    eqs.forEach(e => { const c = Object.assign({}, e); delete c.fotos; addEq(c); });
                                } catch(e) {}
                            }
                        }
                    }
                });
            }
        }

        async function salvar() {
            const form = document.getElementById('formAnalise');
            if(!form.checkValidity()) { form.reportValidity(); return; }
            const fd = new FormData(form);
            const btn = document.getElementById('btnSave');
            btn.disabled = true;
            const nFotosTotal = fotosNovas.length + Object.values(fotosEqNovas).reduce((a, l) => a + l.length, 0);
            btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> A gravar' + (nFotosTotal ? ' (' + nFotosTotal + ' foto(s))…' : '…');
            renumerarEq();
            const fd2 = new FormData(form);   // já com a lista atualizada das fotos de cada aparelho
            for (const [ch, v] of fd2.entries()) if (/\[fotos_existentes\]$/.test(ch)) fd.set(ch, v);
            for (const f of fotosNovas) { const r = await reduzirFoto(f); fd.append('fotos[]', r, r.name); }
            for (const k of Object.keys(fotosEqNovas)) {
                for (const f of fotosEqNovas[k]) { const r = await reduzirFoto(f); fd.append('fotos_eq[' + k + '][]', r, r.name); }
            }
            try {
                const res = await fetch('?ajax_save=1<?= $modo_editar?"&id=$id_edit":"" ?>', { method: 'POST', body: fd });
                const txt = await res.text();
                let json;
                try { json = JSON.parse(txt); } catch (e) { throw new Error(txt.slice(0, 300) || 'Resposta inválida do servidor (as fotos podem ser demasiado grandes).'); }
                if(json.status === 'success') { window.location.href = 'termos_analise.php'; return; }
                alert('Erro ao gravar: ' + json.message);
            } catch (e) {
                alert('Erro ao gravar: ' + e.message);
            }
            btn.disabled = false;
            btn.innerHTML = '<i class="fa-solid fa-floppy-disk"></i> Guardar Relatório';
        }

        window.onload = () => {
            const eqIniciais = <?= json_encode($equipamentos) ?>;
            if(eqIniciais.length > 0) eqIniciais.forEach(e => addEq(e));
            else addEq();
            atualizarInterfaceCliente();
            toggleReparacao();
            if('<?= $dados['id_obra'] ?>') document.getElementById('sel_obra').value = '<?= $dados['id_obra'] ?>';
        }
    </script>
</body>
</html>
