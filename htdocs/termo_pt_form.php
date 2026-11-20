<?php
// FORÇAR EXIBIÇÃO DE ERROS BÁSICOS
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

try {
    session_start();
    require_once 'db.php';

    if (!isset($_SESSION['user_id'])) { header("Location: index.php"); exit; }

    // ----------------------------------------------------------------------
    // [AJAX] LÓGICA DE CRIAÇÃO E EDIÇÃO RÁPIDA (CLIENTE E PT) SEM RECARREGAR
    // ----------------------------------------------------------------------
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action'])) {
        header('Content-Type: application/json');
        
        if ($_POST['ajax_action'] === 'novo_cliente') {
            $nome = trim($_POST['nome_cliente']);
            $nif = trim($_POST['nif_cliente']);
            $morada = trim($_POST['morada_cliente']);
            
            $stmt = $pdo->prepare("INSERT INTO clientes (nome, nif, morada) VALUES (?, ?, ?)");
            $stmt->execute([$nome, $nif, $morada]);
            echo json_encode(['success' => true, 'id' => $pdo->lastInsertId(), 'nome' => $nome]);
            exit;
        }
        
        if ($_POST['ajax_action'] === 'novo_pt') {
            $id_cliente = (int)$_POST['id_cliente_pt'];
            $nome = trim($_POST['nome_pt']);
            $numero_pt = trim($_POST['numero_unico_pt']);
            $morada = trim($_POST['morada_pt']);
            
            $stmt = $pdo->prepare("INSERT INTO postos_transformacao (id_cliente, nome, numero_pt, morada) VALUES (?, ?, ?, ?)");
            $stmt->execute([$id_cliente, $nome, $numero_pt, $morada]);
            echo json_encode(['success' => true, 'id' => $pdo->lastInsertId(), 'nome' => $nome, 'numero_pt' => $numero_pt, 'id_cliente' => $id_cliente]);
            exit;
        }

        if ($_POST['ajax_action'] === 'get_pt_info') {
            $stmt = $pdo->prepare("SELECT id, nome, numero_pt, morada FROM postos_transformacao WHERE id = ?");
            $stmt->execute([(int)$_POST['id_pt']]);
            echo json_encode($stmt->fetch(PDO::FETCH_ASSOC));
            exit;
        }

        if ($_POST['ajax_action'] === 'editar_pt') {
            $id = (int)$_POST['id_pt_edit'];
            $nome = trim($_POST['nome_pt_edit']);
            $numero_pt = trim($_POST['numero_unico_pt_edit']);
            $morada = trim($_POST['morada_pt_edit']);
            
            $stmt = $pdo->prepare("UPDATE postos_transformacao SET nome = ?, numero_pt = ?, morada = ? WHERE id = ?");
            $stmt->execute([$nome, $numero_pt, $morada, $id]);
            echo json_encode(['success' => true, 'id' => $id, 'nome' => $nome, 'numero_pt' => $numero_pt]);
            exit;
        }
        
        if ($_POST['ajax_action'] === 'get_pts') {
            $id_cliente = (int)$_POST['id_cliente'];
            $stmt = $pdo->prepare("SELECT id, nome, numero_pt FROM postos_transformacao WHERE id_cliente = ? ORDER BY nome ASC");
            $stmt->execute([$id_cliente]);
            echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
            exit;
        }
    }
    // ----------------------------------------------------------------------

    if (file_exists('functions.php')) { require_once 'functions.php'; } 
    else { 
        function registrarLog($pdo, $acao, $modulo) { return true; } 
        function gerar_csrf_token() { return ''; }
        function validar_csrf_token($t) { return true; }
    }

    $perfil_user = $_SESSION['perfil'] ?? '';
    $is_admin = in_array($perfil_user, ['admin', 'administrativo']);

    $stmt_perm = $pdo->prepare("SELECT modulo FROM permissoes WHERE grupo = :perfil AND pode_aceder = 1");
    $stmt_perm->execute(['perfil' => $perfil_user]);
    $meus_acessos = $stmt_perm->fetchAll(PDO::FETCH_COLUMN);

    if (!$is_admin && !in_array('termos', $meus_acessos) && !in_array('termos_pt', $meus_acessos)) {
        header("Location: index.php?erro=sem_permissao"); exit;
    }

    $nome_completo = $_SESSION['nome'] ?? 'Utilizador';
    $primeiro_nome = explode(' ', trim($nome_completo))[0];
    $csrf_token = gerar_csrf_token();

    $mensagem = ''; $tipo_mensagem = '';
    
    // VERIFICAR MODO DE ABERTURA
    $id_editar = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
    $id_duplicar = filter_input(INPUT_GET, 'duplicar', FILTER_VALIDATE_INT);
    
    $id_termo = $id_editar ?? $id_duplicar ?? null;

    // --- LÓGICA DE GRAVAÇÃO (POST DO RELATÓRIO) ---
    // AQUI ESTAVA O ERRO! Agora deteta os dois botões corretamente.
    if ($_SERVER['REQUEST_METHOD'] == 'POST' && (isset($_POST['btn_guardar_pt']) || isset($_POST['imprimir_pdf']))) {
        if (!validar_csrf_token($_POST['csrf_token'] ?? '')) { die("CSRF Inválido."); }

        $id_post = filter_input(INPUT_POST, 'id_termo', FILTER_VALIDATE_INT);
        $id_cliente = $_POST['id_cliente'] ?? null;
        $id_pt = !empty($_POST['id_pt']) ? $_POST['id_pt'] : null;
        
        $medicoes = [
            'm_1_1_1' => $_POST['m_1_1_1'] ?? '', 'm_1_1_2' => $_POST['m_1_1_2'] ?? '',
            'm_1_1_3' => $_POST['m_1_1_3'] ?? '', 'm_1_1_4' => $_POST['m_1_1_4'] ?? '',
            'm_1_1_5' => $_POST['m_1_1_5'] ?? '', 'm_1_1_6' => $_POST['m_1_1_6'] ?? '',
            'm_2_1_1' => $_POST['m_2_1_1'] ?? '', 'm_2_1_2' => $_POST['m_2_1_2'] ?? '',
            'm_2_1_3' => $_POST['m_2_1_3'] ?? '', 'm_2_1_4' => $_POST['m_2_1_4'] ?? '',
            'm_2_1_5' => $_POST['m_2_1_5'] ?? ''
        ];
        
        $deficiencias = [
            'v_1_2_1' => $_POST['v_1_2_1'] ?? 'nao', 'v_1_2_2' => $_POST['v_1_2_2'] ?? 'nao',
            'v_1_2_3' => $_POST['v_1_2_3'] ?? 'nao', 'v_1_2_4' => $_POST['v_1_2_4'] ?? 'nao',
            'v_1_2_5' => $_POST['v_1_2_5'] ?? 'nao', 'v_1_2_6' => $_POST['v_1_2_6'] ?? 'nao',
            'v_2_2_1' => $_POST['v_2_2_1'] ?? 'nao', 'v_2_2_2' => $_POST['v_2_2_2'] ?? 'nao',
            'v_2_2_3' => $_POST['v_2_2_3'] ?? 'nao', 'v_2_2_4' => $_POST['v_2_2_4'] ?? 'nao',
            'v_2_2_5' => $_POST['v_2_2_5'] ?? 'nao', 'v_2_2_6_1' => $_POST['v_2_2_6_1'] ?? 'nao',
            'v_2_2_6_2' => $_POST['v_2_2_6_2'] ?? 'nao', 'v_2_2_6_3' => $_POST['v_2_2_6_3'] ?? 'nao'
        ];
        
        $observacoes = [
            'obs_1_2_7' => $_POST['obs_1_2_7'] ?? '',
            'obs_2_2_7' => $_POST['obs_2_2_7'] ?? '', 'obs_2_2_8' => $_POST['obs_2_2_8'] ?? '',
            'obs_2_2_9' => $_POST['obs_2_2_9'] ?? '', 'obs_2_2_10' => $_POST['obs_2_2_10'] ?? '',
            'obs_2_2_11' => $_POST['obs_2_2_11'] ?? '', 'obs_2_2_12' => $_POST['obs_2_2_12'] ?? '',
            'obs_2_2_13' => $_POST['obs_2_2_13'] ?? '', 'obs_3' => $_POST['obs_3'] ?? '',
            'obs_4' => $_POST['obs_4'] ?? '', 'obs_5' => $_POST['obs_5'] ?? ''
        ];

        // --- GESTÃO DE FOTOGRAFIAS ---
        $fotos_guardadas = [];
        if ($id_post && !isset($_GET['duplicar'])) {
            $stmt_f = $pdo->prepare("SELECT fotos_json FROM termos_pt WHERE id = ?");
            $stmt_f->execute([$id_post]);
            $fotos_guardadas = json_decode($stmt_f->fetchColumn() ?: '[]', true) ?: [];
        }

        if (!empty($_POST['remover_fotos'])) {
            foreach ($_POST['remover_fotos'] as $foto_apagar) {
                if (in_array($foto_apagar, $fotos_guardadas)) {
                    @unlink('imagens/' . $foto_apagar);
                    $fotos_guardadas = array_diff($fotos_guardadas, [$foto_apagar]);
                }
            }
            $fotos_guardadas = array_values($fotos_guardadas); 
        }

        if (!empty($_FILES['fotos']['name'][0])) {
            if (!is_dir('imagens')) { mkdir('imagens', 0777, true); }
            
            foreach ($_FILES['fotos']['tmp_name'] as $key => $tmp_name) {
                if ($_FILES['fotos']['error'][$key] === 0) {
                    $ext = strtolower(pathinfo($_FILES['fotos']['name'][$key], PATHINFO_EXTENSION));
                    if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp'])) {
                        $novo_nome = 'pt_' . uniqid() . '_' . rand(100,999) . '.jpg';
                        $caminho = 'imagens/' . $novo_nome;

                        $info = getimagesize($tmp_name);
                        if ($info) {
                            if ($info[2] == IMAGETYPE_JPEG) $img = imagecreatefromjpeg($tmp_name);
                            elseif ($info[2] == IMAGETYPE_PNG) $img = imagecreatefrompng($tmp_name);
                            elseif ($info[2] == IMAGETYPE_WEBP) $img = imagecreatefromwebp($tmp_name);
                            else continue;

                            $max_w = 1200;
                            $w = $info[0]; $h = $info[1];
                            if ($w > $max_w) {
                                $new_w = $max_w;
                                $new_h = floor(($h / $w) * $max_w);
                            } else {
                                $new_w = $w; $new_h = $h;
                            }

                            $nova_img = imagecreatetruecolor($new_w, $new_h);
                            $bg = imagecolorallocate($nova_img, 255, 255, 255);
                            imagefill($nova_img, 0, 0, $bg);
                            
                            imagecopyresampled($nova_img, $img, 0, 0, 0, 0, $new_w, $new_h, $w, $h);
                            imagejpeg($nova_img, $caminho, 65); 
                            imagedestroy($img); imagedestroy($nova_img);

                            $fotos_guardadas[] = $novo_nome;
                        }
                    }
                }
            }
        }
        
        $json_fotos_final = json_encode($fotos_guardadas);

        // PREPARAR ARRAY DE GRAVAÇÃO NA BD
        $dados = [
            $id_cliente, $id_pt, $_POST['data_inspecao'], $_POST['periodo_inicio'], $_POST['periodo_fim'], 
            $_POST['condicao_geral'], $_POST['dl_aplicavel'], $_POST['sistema_protecao'],
            json_encode($medicoes), json_encode($deficiencias), json_encode($observacoes), $json_fotos_final,
            $_POST['tecnico_nome'], $_POST['tecnico_n_inscricao'], date('Y-m-d')
        ];

        try {
            if ($id_post && !isset($_GET['duplicar'])) {
                $sql = "UPDATE termos_pt SET id_cliente=?, id_pt=?, data_inspecao=?, periodo_inicio=?, periodo_fim=?, condicao_geral=?, dl_aplicavel=?, sistema_protecao=?, medicoes_json=?, deficiencias_json=?, observacoes_json=?, fotos_json=?, tecnico_nome=?, tecnico_n_inscricao=?, data_emissao=? WHERE id=?";
                $dados[] = $id_post;
                $pdo->prepare($sql)->execute($dados);
                $id_final = $id_post;
            } else {
                $stmt_num = $pdo->query("SELECT MAX(numero_relatorio) FROM termos_pt");
                $prox_num = ($stmt_num->fetchColumn() ?: 0) + 1;
                
                $sql = "INSERT INTO termos_pt (numero_relatorio, id_cliente, id_pt, data_inspecao, periodo_inicio, periodo_fim, condicao_geral, dl_aplicavel, sistema_protecao, medicoes_json, deficiencias_json, observacoes_json, fotos_json, tecnico_nome, tecnico_n_inscricao, data_emissao) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                array_unshift($dados, $prox_num); 
                $pdo->prepare($sql)->execute($dados);
                $id_final = $pdo->lastInsertId();
            }
            
            registrarLog($pdo, "Gravou Relatório PT #" . $id_final, "Termos PT");
            
            if (isset($_POST['imprimir_pdf'])) {
                header("Location: termo_pt_pdf.php?id=" . $id_final);
            } else {
                header("Location: termos_pt.php?sucesso=1");
            }
            exit;
        } catch (Exception $e) {
            $mensagem = "Erro: " . $e->getMessage(); $tipo_mensagem = "erro";
        }
    }

    // --- CARREGAR DADOS SE FOR EDIÇÃO OU DUPLICAÇÃO ---
    $registo = [];
    $med = []; $def = []; $obs = []; $fotos_view = [];
    $numero_relatorio_display = "NOVO (Auto-Gerado)";
    
    if ($id_termo) {
        $stmt_r = $pdo->prepare("SELECT * FROM termos_pt WHERE id = ?");
        $stmt_r->execute([$id_termo]);
        $registo = $stmt_r->fetch(PDO::FETCH_ASSOC);
        if ($registo) {
            $med = json_decode($registo['medicoes_json'] ?? '{}', true);
            $def = json_decode($registo['deficiencias_json'] ?? '{}', true);
            $obs = json_decode($registo['observacoes_json'] ?? '{}', true);
            $fotos_view = json_decode($registo['fotos_json'] ?? '[]', true) ?: [];
            
            if (isset($_GET['duplicar'])) {
                $registo['id'] = '';
                $registo['data_inspecao'] = date('Y-m-d');
                $registo['periodo_inicio'] = date('Y-m-d', strtotime('-1 year'));
                $registo['periodo_fim'] = date('Y-m-d');
                $fotos_view = []; // No duplicar não copia fotos
                $titulo_pagina = "Novo Relatório PT (Duplicado)";
            } else {
                $titulo_pagina = "Editar Relatório PT";
                $numero_relatorio_display = str_pad($registo['numero_relatorio'], 5, "0", STR_PAD_LEFT);
            }
        }
    } else {
        $titulo_pagina = "Novo Relatório PT";
        $registo = [
            'data_inspecao' => date('Y-m-d'),
            'periodo_inicio' => date('Y-m-d', strtotime('-1 year')),
            'periodo_fim' => date('Y-m-d'),
            'condicao_geral' => 'OK',
            'dl_aplicavel' => 'DL n.º 96/2017',
            'sistema_protecao' => 'TN',
            'tecnico_nome' => 'Mário Abreu',
            'tecnico_n_inscricao' => '2183'
        ];
    }

    $clientes = $pdo->query("SELECT id, nome, nif FROM clientes ORDER BY nome ASC")->fetchAll();
    
    $pts_do_cliente = [];
    if (!empty($registo['id_cliente'])) {
        $s_pt = $pdo->prepare("SELECT id, nome, numero_pt FROM postos_transformacao WHERE id_cliente = ? ORDER BY nome ASC");
        $s_pt->execute([$registo['id_cliente']]);
        $pts_do_cliente = $s_pt->fetchAll(PDO::FETCH_ASSOC);
    }

} catch (Throwable $e) {
    die("<div style='padding:20px; color:red;'>Erro: " . $e->getMessage() . "</div>");
}
?>
<!DOCTYPE html>
<html lang="pt-PT">
<head>
    <meta charset="UTF-8">
    <title><?= $titulo_pagina ?> - MAB</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { font-family: system-ui, sans-serif; background: #f7f9fc; margin: 0; font-size: 13px; padding-bottom: 80px; }
        * { box-sizing: border-box; }
        
        .topbar { background: #fff; padding: 15px 40px; box-shadow: 0 4px 20px rgba(0,0,0,0.05); display: flex; justify-content: space-between; align-items: center; position: sticky; top: 0; z-index: 100;}
        .topbar-left .logo { height: 40px; }
        .btn-back { color: #576574; text-decoration: none; font-weight: 500; display: flex; align-items: center; gap: 6px; font-size: 15px; transition: 0.2s;}
        .btn-back:hover { color: #34495e; } 
        
        .container { max-width: 1200px; margin: 30px auto; padding: 0 20px; }
        .card { background: #fff; padding: 25px; border-radius: 12px; border: 1px solid #e1e5ea; margin-bottom: 25px; box-shadow: 0 4px 15px rgba(0,0,0,0.02); }
        .card-title { color: #2c3e50; font-size: 16px; margin: 0 0 20px; padding-bottom: 10px; border-bottom: 2px solid #f1f2f6; display: flex; align-items: center; gap: 10px;}
        
        .grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
        .grid-3 { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 20px; }
        .grid-4 { display: grid; grid-template-columns: 1fr 1fr 1fr 1fr; gap: 20px; }
        .form-group { margin-bottom: 15px; }
        .form-full { grid-column: 1 / -1; }
        
        label { display: block; font-weight: 700; color: #576574; font-size: 11px; text-transform: uppercase; margin-bottom: 6px; }
        input[type="text"], input[type="date"], select, textarea { width: 100%; padding: 10px 12px; border: 1px solid #dcdde1; border-radius: 6px; font-family: inherit; font-size: 13px; transition: 0.2s; }
        input[type="text"]:focus, select:focus, textarea:focus { border-color: #3498db; outline: none; box-shadow: 0 0 0 3px rgba(52,152,219,0.1); }
        
        /* Grupo de Input com Botão Adicionar/Editar */
        .input-btn-group { display: flex; gap: 8px; }
        .input-btn-group select { flex: 1; }
        .btn-add { background: #34495e; color: #fff; border: none; border-radius: 6px; width: 42px; cursor: pointer; transition: 0.2s; display: flex; justify-content: center; align-items: center; font-size: 16px; }
        .btn-add:hover { background: #2c3e50; }
        .btn-edit { background: #f39c12; color: #fff; border: none; border-radius: 6px; width: 42px; cursor: pointer; transition: 0.2s; display: flex; justify-content: center; align-items: center; font-size: 16px; }
        .btn-edit:hover { background: #e67e22; }

        .radio-group { display: flex; gap: 20px; align-items: center; height: 40px; }
        .radio-label { display: flex; align-items: center; gap: 6px; cursor: pointer; font-size: 13px; font-weight: 600; color: #2c3e50; }
        
        table { width: 100%; border-collapse: collapse; margin-bottom: 15px; }
        th { background: #f8f9fa; padding: 10px; text-align: left; font-size: 11px; color: #7f8c8d; text-transform: uppercase; border: 1px solid #e1e5ea; }
        td { padding: 10px; border: 1px solid #e1e5ea; vertical-align: middle; }
        .td-medicao { width: 150px; }
        .td-unidade { width: 60px; text-align: center; background: #f8f9fa; font-weight: bold; color: #576574;}
        
        .floating-bar { position: fixed; bottom: 0; left: 0; right: 0; background: #fff; padding: 15px 40px; box-shadow: 0 -5px 20px rgba(0,0,0,0.05); display: flex; justify-content: flex-end; gap: 15px; z-index: 100; border-top: 1px solid #e1e5ea;}
        .btn { padding: 12px 25px; border-radius: 8px; font-weight: bold; cursor: pointer; border: none; font-size: 14px; transition: 0.2s; display: inline-flex; align-items: center; gap: 8px;}
        .btn-cancel { background: #f1f2f6; color: #576574; text-decoration: none; }
        .btn-cancel:hover { background: #e1e5ea; }
        .btn-save { background: #34495e; color: #fff; }
        .btn-save:hover { background: #2c3e50; }
        .btn-pdf { background: #d35400; color: #fff; }
        .btn-pdf:hover { background: #e67e22; }

        /* FOTOGRAFIAS */
        .foto-container { display:flex; gap:15px; flex-wrap:wrap; margin-top:15px;}
        .foto-box { position:relative; width:120px; height:120px; border:2px solid #ecf0f1; border-radius:8px; overflow:hidden;}
        .foto-box img { width:100%; height:100%; object-fit:cover; }
        .foto-delete { position:absolute; bottom:0; left:0; right:0; background:rgba(231,76,60,0.9); color:#fff; font-size:11px; text-align:center; padding:6px; cursor:pointer; font-weight:bold;}

        /* MODAIS AJAX */
        .modal { display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.6); z-index:10000; align-items:center; justify-content:center; backdrop-filter: blur(2px); }
        .modal-content { background:#fff; padding:30px; border-radius:15px; width:90%; max-width:500px; box-shadow: 0 20px 50px rgba(0,0,0,0.2); }
        .modal-btns { display: flex; gap: 10px; margin-top: 20px;}
        .modal-btns button { flex: 1; padding: 12px; border: none; border-radius: 8px; font-weight: 600; cursor: pointer; transition:0.2s;}
    </style>
</head>
<body>
    <header class="topbar">
        <div class="topbar-left"><img src="mab.png" alt="MAB" class="logo"></div>
        <div class="topbar-right">
            <a href="termos_pt.php" class="btn-back"><i class="fa-solid fa-arrow-left"></i> Voltar à Lista</a>
        </div>
    </header>

    <form method="POST" action="" id="formRelatorio" enctype="multipart/form-data">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
        <input type="hidden" name="id_termo" value="<?= htmlspecialchars($registo['id'] ?? '') ?>">

        <div class="container">
            <?php if ($mensagem): ?>
                <div style="background:#eafaf1; color:#27ae60; padding:15px; border-radius:8px; border:1px solid #d5f5e3; font-weight:bold; margin-bottom:20px; text-align:center;">
                    <?= htmlspecialchars($mensagem) ?>
                </div>
            <?php endif; ?>

            <div class="card">
                <div style="display:flex; justify-content:space-between; align-items:center; border-bottom: 2px solid #f1f2f6; padding-bottom: 10px; margin-bottom: 20px;">
                    <h2 class="card-title" style="border:none; margin:0; padding:0;"><i class="fa-solid fa-info-circle"></i> Identificação e Período</h2>
                    <span style="background:#eaeff5; color:#34495e; padding:6px 12px; border-radius:6px; font-weight:bold; font-size:12px; border:1px solid #d4deea;">Relatório Nº: <?= $numero_relatorio_display ?></span>
                </div>
                
                <div class="form-group">
                    <label>Condição Geral da Instalação</label>
                    <div class="radio-group">
                        <label class="radio-label"><input type="radio" name="condicao_geral" value="OK" <?= ($registo['condicao_geral'] ?? 'OK') == 'OK' ? 'checked' : '' ?>> Boas condições de segurança</label>
                        <label class="radio-label" style="color:#c0392b;"><input type="radio" name="condicao_geral" value="NOK" <?= ($registo['condicao_geral'] ?? '') == 'NOK' ? 'checked' : '' ?>> Condições deficientes</label>
                        <label class="radio-label" style="color:#d35400;"><input type="radio" name="condicao_geral" value="DESISTE" <?= ($registo['condicao_geral'] ?? '') == 'DESISTE' ? 'checked' : '' ?>> Desistência de responsabilidade</label>
                    </div>
                </div>

                <div class="grid-2">
                    <div class="form-group">
                        <label>Cliente / Entidade</label>
                        <div class="input-btn-group">
                            <select name="id_cliente" id="select_cliente" onchange="carregarPTs(this.value)" required>
                                <option value="">Selecione...</option>
                                <?php foreach($clientes as $c): ?>
                                    <option value="<?= $c['id'] ?>" <?= ($registo['id_cliente'] ?? '') == $c['id'] ? 'selected' : '' ?>><?= htmlspecialchars($c['nome']) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button type="button" class="btn-add" onclick="abrirModalCliente()" title="Criar Novo Cliente"><i class="fa-solid fa-plus"></i></button>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label>Posto de Transformação</label>
                        <div class="input-btn-group">
                            <select name="id_pt" id="select_pt" required>
                                <option value="">Selecione o Cliente primeiro...</option>
                                <?php foreach($pts_do_cliente as $pt): ?>
                                    <option value="<?= $pt['id'] ?>" <?= ($registo['id_pt'] ?? '') == $pt['id'] ? 'selected' : '' ?>><?= htmlspecialchars($pt['nome']) ?> (Nº <?= htmlspecialchars($pt['numero_pt']) ?>)</option>
                                <?php endforeach; ?>
                            </select>
                            <button type="button" class="btn-edit" onclick="abrirModalEditarPT()" title="Editar dados do PT selecionado"><i class="fa-solid fa-pen"></i></button>
                            <button type="button" class="btn-add" onclick="abrirModalPT()" title="Criar Novo PT para o Cliente"><i class="fa-solid fa-plus"></i></button>
                        </div>
                    </div>
                </div>

                <div class="grid-4">
                    <div class="form-group">
                        <label>Data de Inspeção</label>
                        <input type="date" name="data_inspecao" value="<?= htmlspecialchars($registo['data_inspecao'] ?? '') ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Período (Início)</label>
                        <input type="date" name="periodo_inicio" value="<?= htmlspecialchars($registo['periodo_inicio'] ?? '') ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Período (Fim)</label>
                        <input type="date" name="periodo_fim" value="<?= htmlspecialchars($registo['periodo_fim'] ?? '') ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Regulamento (DL Aplicável)</label>
                        <input type="text" name="dl_aplicavel" value="<?= htmlspecialchars($registo['dl_aplicavel'] ?? 'DL n.º 96/2017') ?>">
                    </div>
                </div>
            </div>

            <div class="card">
                <h2 class="card-title"><i class="fa-solid fa-camera"></i> Registo Fotográfico (Opcional)</h2>
                <div class="form-group">
                    <label>Adicionar Fotografias (Pode usar a Câmara do Telemóvel / Tablet)</label>
                    <input type="file" name="fotos[]" multiple accept="image/*" capture="environment" style="background:#f8f9fa; padding:15px; border:2px dashed #bdc3c7; text-align:center; font-size:15px;">
                    <small style="color:#7f8c8d; display:block; margin-top:5px;">Elas serão comprimidas de forma inteligente para não ocuparem espaço no servidor.</small>
                </div>
                
                <?php if(!empty($fotos_view)): ?>
                <div class="foto-container">
                    <?php foreach($fotos_view as $f): ?>
                        <div class="foto-box">
                            <img src="imagens/<?= htmlspecialchars($f) ?>">
                            <label class="foto-delete">
                                <input type="checkbox" name="remover_fotos[]" value="<?= htmlspecialchars($f) ?>"> Apagar
                            </label>
                        </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>

            <div class="card">
                <h2 class="card-title"><i class="fa-solid fa-bolt"></i> 1.1 Ensaios e Medições (Subestações e PT)</h2>
                <table>
                    <thead><tr><th>Descrição do Ensaio / Medição</th><th class="td-medicao">Valor</th><th class="td-unidade">Un.</th></tr></thead>
                    <tbody>
                        <tr>
                            <td>1.1.1 Resistência da terra de protecção</td>
                            <td><input type="text" name="m_1_1_1" value="<?= htmlspecialchars($med['m_1_1_1'] ?? '') ?>" placeholder="Ex: 17,00"></td>
                            <td class="td-unidade">Ω</td>
                        </tr>
                        <tr>
                            <td>1.1.2 Resistência da terra de serviço</td>
                            <td><input type="text" name="m_1_1_2" value="<?= htmlspecialchars($med['m_1_1_2'] ?? '') ?>" placeholder="Ex: 6,00"></td>
                            <td class="td-unidade">Ω</td>
                        </tr>
                        <tr>
                            <td>1.1.3 Resistência de isolamento da instalação de BT</td>
                            <td><input type="text" name="m_1_1_3" value="<?= htmlspecialchars($med['m_1_1_3'] ?? '') ?>" placeholder="Ex: 77,80"></td>
                            <td class="td-unidade">MΩ</td>
                        </tr>
                        <tr>
                            <td>1.1.4 Acidez e rigidez dos óleos (Dieléctricos)</td>
                            <td><input type="text" name="m_1_1_4" value="<?= htmlspecialchars($med['m_1_1_4'] ?? '') ?>" placeholder="Ex: OK"></td>
                            <td class="td-unidade">-</td>
                        </tr>
                        <tr>
                            <td>1.1.5 Factor de potência (cos φ)</td>
                            <td><input type="text" name="m_1_1_5" value="<?= htmlspecialchars($med['m_1_1_5'] ?? '') ?>" placeholder="Ex: 0,98"></td>
                            <td class="td-unidade">cos φ</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div class="card">
                <h2 class="card-title"><i class="fa-solid fa-magnifying-glass"></i> 1.2 Verificações (Subestações e PT)</h2>
                <div style="margin-bottom: 15px; display:flex; gap:15px; align-items:center;">
                    <label style="margin:0;">Sistema de Protecção Pessoas (1.2.7):</label>
                    <select name="sistema_protecao" style="width:150px; padding:6px;">
                        <option value="TT" <?= ($registo['sistema_protecao'] ?? '') == 'TT' ? 'selected' : '' ?>>TT</option>
                        <option value="TN" <?= ($registo['sistema_protecao'] ?? 'TN') == 'TN' ? 'selected' : '' ?>>TN</option>
                        <option value="IT" <?= ($registo['sistema_protecao'] ?? '') == 'IT' ? 'selected' : '' ?>>IT</option>
                    </select>
                </div>
                
                <table>
                    <thead><tr><th>Item a Verificar</th><th style="width:120px; text-align:center;">Não Detetou</th><th style="width:120px; text-align:center;">Detetou Deficiências</th></tr></thead>
                    <tbody>
                        <?php 
                        $itens_v1 = [
                            'v_1_2_1' => '1.2.1 Nível do óleo nos transformadores e disjuntores AT',
                            'v_1_2_2' => '1.2.2 Estado dos contactos dos disjuntores e câmaras de corte',
                            'v_1_2_3' => '1.2.3 Circuitos de terra e estado de conservação dos eléctrodos',
                            'v_1_2_4' => '1.2.4 Estado de conservação dos dispositivos de manobra (varas, tapetes...)',
                            'v_1_2_5' => '1.2.5 Carga do transformador e temperatura do óleo',
                            'v_1_2_6' => '1.2.6 Estado de funcionamento dos dispositivos de protecção e alarme'
                        ];
                        foreach($itens_v1 as $id => $label): 
                            $val = $def[$id] ?? 'nao';
                        ?>
                        <tr>
                            <td><?= $label ?></td>
                            <td style="text-align:center; background:#eafaf1;"><input type="radio" name="<?= $id ?>" value="nao" <?= $val=='nao' ? 'checked' : '' ?>></td>
                            <td style="text-align:center; background:#fdeded;"><input type="radio" name="<?= $id ?>" value="sim" <?= $val=='sim' ? 'checked' : '' ?>></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="card">
                <h2 class="card-title"><i class="fa-solid fa-plug"></i> 2.1 Ensaios e Medições (Instalações de Utilização)</h2>
                <table>
                    <thead><tr><th>Descrição do Ensaio / Medição</th><th class="td-medicao">Valor</th><th class="td-unidade">Un.</th></tr></thead>
                    <tbody>
                        <tr>
                            <td>2.1.1 Resistência da terra de protecção</td>
                            <td><input type="text" name="m_2_1_1" value="<?= htmlspecialchars($med['m_2_1_1'] ?? '') ?>" placeholder="Ex: 17,00"></td>
                            <td class="td-unidade">Ω</td>
                        </tr>
                        <tr>
                            <td>2.1.2 Impedância do circuito de defeito</td>
                            <td><input type="text" name="m_2_1_2" value="<?= htmlspecialchars($med['m_2_1_2'] ?? '') ?>" placeholder="Ex: 2,70"></td>
                            <td class="td-unidade">Ω</td>
                        </tr>
                        <tr>
                            <td>2.1.3 Resistência de isolamento</td>
                            <td><input type="text" name="m_2_1_3" value="<?= htmlspecialchars($med['m_2_1_3'] ?? '') ?>" placeholder="Ex: 80,00"></td>
                            <td class="td-unidade">MΩ</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div class="card">
                <h2 class="card-title"><i class="fa-solid fa-check-double"></i> 2.2 Verificações (Instalações de Utilização e Emergência)</h2>
                <table>
                    <thead><tr><th>Item a Verificar</th><th style="width:120px; text-align:center;">Não Detetou</th><th style="width:120px; text-align:center;">Detetou Deficiências</th></tr></thead>
                    <tbody>
                        <?php 
                        $itens_v2 = [
                            'v_2_2_1' => '2.2.1 Aparelhos de protecção contra sobreintensidades',
                            'v_2_2_2' => '2.2.2 Eficácia das protecções contra contactos indirectos',
                            'v_2_2_3' => '2.2.3 Aquecimento e estado do isolamento dos condutores e cabos',
                            'v_2_2_4' => '2.2.4 Estado dos aparelhos de corte e comando',
                            'v_2_2_5' => '2.2.5 Estado dos aparelhos de utilização',
                            'v_2_2_6_1' => '2.2.6.1 Condições de arranque das fontes de alimentação de emergência',
                            'v_2_2_6_2' => '2.2.6.2 Estado das baterias (electrólito)',
                            'v_2_2_6_3' => '2.2.6.3 Estado de funcionamento dos blocos autónomos'
                        ];
                        foreach($itens_v2 as $id => $label): 
                            $val = $def[$id] ?? 'nao';
                        ?>
                        <tr>
                            <td><?= $label ?></td>
                            <td style="text-align:center; background:#eafaf1;"><input type="radio" name="<?= $id ?>" value="nao" <?= $val=='nao' ? 'checked' : '' ?>></td>
                            <td style="text-align:center; background:#fdeded;"><input type="radio" name="<?= $id ?>" value="sim" <?= $val=='sim' ? 'checked' : '' ?>></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="card">
                <h2 class="card-title"><i class="fa-solid fa-align-left"></i> Observações, Anomalias e Relações com Proprietário</h2>
                
                <div class="form-group">
                    <label>2.2.7 Métodos de trabalho suscetíveis de provocar contactos diretos</label>
                    <textarea name="obs_2_2_7" rows="2"><?= htmlspecialchars($obs['obs_2_2_7'] ?? '') ?></textarea>
                </div>
                <div class="form-group">
                    <label>2.2.8 Incorrecções na execução de trabalhos nas instalações</label>
                    <textarea name="obs_2_2_8" rows="2"><?= htmlspecialchars($obs['obs_2_2_8'] ?? '') ?></textarea>
                </div>
                <div class="form-group">
                    <label>2.2.11 Utilizações diferentes das previstas / 2.2.12 Redimensionamento da instalação</label>
                    <textarea name="obs_2_2_11" rows="3"><?= htmlspecialchars($obs['obs_2_2_11'] ?? '') ?></textarea>
                </div>
                <div class="form-group">
                    <label>4. Modificações e Ampliações não consultadas</label>
                    <textarea name="obs_4" rows="2"><?= htmlspecialchars($obs['obs_4'] ?? '') ?></textarea>
                </div>
                <div class="form-group">
                    <label>5. Relações com o Proprietário (Medidas comunicadas pendentes)</label>
                    <textarea name="obs_5" rows="3"><?= htmlspecialchars($obs['obs_5'] ?? '') ?></textarea>
                </div>
                
                <div class="grid-2" style="margin-top:20px; padding-top:20px; border-top:1px solid #eee;">
                    <div class="form-group">
                        <label>Nome do Técnico Responsável</label>
                        <input type="text" name="tecnico_nome" value="<?= htmlspecialchars($registo['tecnico_nome'] ?? $primeiro_nome) ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Nº Inscrição (Alvará)</label>
                        <input type="text" name="tecnico_n_inscricao" value="<?= htmlspecialchars($registo['tecnico_n_inscricao'] ?? '56678') ?>" required>
                    </div>
                </div>
            </div>

        </div>

        <div class="floating-bar">
            <a href="termos_pt.php" class="btn btn-cancel">Cancelar</a>
            <button type="submit" name="btn_guardar_pt" class="btn btn-save"><i class="fa-solid fa-floppy-disk"></i> Gravar Relatório</button>
            <button type="submit" name="imprimir_pdf" class="btn btn-pdf"><i class="fa-solid fa-file-pdf"></i> Gravar & Imprimir</button>
        </div>
    </form>

    <div class="modal" id="modalNovoCliente">
        <div class="modal-content">
            <h2 style="margin-top:0; color:#2c3e50;"><i class="fa-solid fa-user-plus"></i> Criar Novo Cliente</h2>
            <form id="formNovoCliente" onsubmit="gravarCliente(event)">
                <div class="form-group">
                    <label>Nome do Cliente / Entidade</label>
                    <input type="text" name="nome_cliente" required>
                </div>
                <div class="form-group">
                    <label>NIF</label>
                    <input type="text" name="nif_cliente">
                </div>
                <div class="form-group">
                    <label>Morada Sede</label>
                    <input type="text" name="morada_cliente">
                </div>
                <div class="modal-btns">
                    <button type="button" class="btn-cancelar" onclick="document.getElementById('modalNovoCliente').style.display='none'">Cancelar</button>
                    <button type="submit" style="background:#34495e; color:#fff;">Criar e Usar</button>
                </div>
            </form>
        </div>
    </div>

    <div class="modal" id="modalNovoPT">
        <div class="modal-content">
            <h2 style="margin-top:0; color:#2c3e50;"><i class="fa-solid fa-industry"></i> Adicionar Posto de Transformação</h2>
            <p style="font-size:12px; color:#7f8c8d;">Este PT ficará associado ao cliente selecionado.</p>
            <form id="formNovoPT" onsubmit="gravarPT(event)">
                <input type="hidden" name="id_cliente_pt" id="hidden_id_cliente_pt">
                <div class="form-group">
                    <label>Nome Descritivo do PT (Ex: PT Principal Fábrica)</label>
                    <input type="text" name="nome_pt" required>
                </div>
                <div class="form-group">
                    <label>Numerador Único do PT (Ex: Q.AI nº 190255)</label>
                    <input type="text" name="numero_unico_pt" required>
                </div>
                <div class="form-group">
                    <label>Morada exata da Instalação do PT</label>
                    <input type="text" name="morada_pt">
                </div>
                <div class="modal-btns">
                    <button type="button" class="btn-cancelar" onclick="document.getElementById('modalNovoPT').style.display='none'">Cancelar</button>
                    <button type="submit" style="background:#34495e; color:#fff;">Criar e Usar</button>
                </div>
            </form>
        </div>
    </div>

    <div class="modal" id="modalEditarPT">
        <div class="modal-content">
            <h2 style="margin-top:0; color:#e67e22;"><i class="fa-solid fa-pen"></i> Editar Posto de Transformação</h2>
            <form id="formEditarPT" onsubmit="gravarEdicaoPT(event)">
                <input type="hidden" name="id_pt_edit" id="id_pt_edit">
                <div class="form-group">
                    <label>Nome Descritivo do PT</label>
                    <input type="text" name="nome_pt_edit" id="nome_pt_edit" required>
                </div>
                <div class="form-group">
                    <label>Numerador Único do PT</label>
                    <input type="text" name="numero_unico_pt_edit" id="numero_unico_pt_edit" required>
                </div>
                <div class="form-group">
                    <label>Morada exata da Instalação</label>
                    <input type="text" name="morada_pt_edit" id="morada_pt_edit">
                </div>
                <div class="modal-btns">
                    <button type="button" class="btn-cancelar" onclick="document.getElementById('modalEditarPT').style.display='none'">Cancelar</button>
                    <button type="submit" style="background:#e67e22; color:#fff;">Gravar Alterações</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // 1. ABRIR MODAL NOVO CLIENTE
        function abrirModalCliente() {
            document.getElementById('modalNovoCliente').style.display = 'flex';
        }

        function gravarCliente(e) {
            e.preventDefault();
            let fd = new FormData(document.getElementById('formNovoCliente'));
            fd.append('ajax_action', 'novo_cliente');
            
            fetch('', { method: 'POST', body: fd }).then(res => res.json()).then(data => {
                if(data.success) {
                    let select = document.getElementById('select_cliente');
                    let opt = new Option(data.nome, data.id);
                    select.add(opt);
                    select.value = data.id;
                    document.getElementById('modalNovoCliente').style.display = 'none';
                    document.getElementById('formNovoCliente').reset();
                    carregarPTs(data.id);
                }
            });
        }

        // 2. ABRIR MODAL NOVO PT
        function abrirModalPT() {
            let idCli = document.getElementById('select_cliente').value;
            if (!idCli) { alert("Por favor, selecione um Cliente na lista primeiro."); return; }
            document.getElementById('hidden_id_cliente_pt').value = idCli;
            document.getElementById('modalNovoPT').style.display = 'flex';
        }

        function gravarPT(e) {
            e.preventDefault();
            let fd = new FormData(document.getElementById('formNovoPT'));
            fd.append('ajax_action', 'novo_pt');
            
            fetch('', { method: 'POST', body: fd }).then(res => res.json()).then(data => {
                if(data.success) {
                    document.getElementById('modalNovoPT').style.display = 'none';
                    document.getElementById('formNovoPT').reset();
                    carregarPTs(data.id_cliente, data.id);
                }
            });
        }

        // 3. ABRIR MODAL EDITAR PT EXISTENTE
        function abrirModalEditarPT() {
            let idPT = document.getElementById('select_pt').value;
            if (!idPT) { alert("Selecione um Posto de Transformação na lista para o poder editar."); return; }
            
            let fd = new FormData();
            fd.append('ajax_action', 'get_pt_info');
            fd.append('id_pt', idPT);
            
            fetch('', { method: 'POST', body: fd }).then(res => res.json()).then(data => {
                if(data) {
                    document.getElementById('id_pt_edit').value = data.id;
                    document.getElementById('nome_pt_edit').value = data.nome;
                    document.getElementById('numero_unico_pt_edit').value = data.numero_pt;
                    document.getElementById('morada_pt_edit').value = data.morada;
                    document.getElementById('modalEditarPT').style.display = 'flex';
                }
            });
        }

        function gravarEdicaoPT(e) {
            e.preventDefault();
            let fd = new FormData(document.getElementById('formEditarPT'));
            fd.append('ajax_action', 'editar_pt');
            
            fetch('', { method: 'POST', body: fd }).then(res => res.json()).then(data => {
                if(data.success) {
                    document.getElementById('modalEditarPT').style.display = 'none';
                    let sel = document.getElementById('select_pt');
                    sel.options[sel.selectedIndex].text = data.nome + ' (Nº ' + data.numero_pt + ')';
                }
            });
        }

        // 4. CARREGAR PTs
        function carregarPTs(idCliente, idParaSelecionar = null) {
            const ptSelect = document.getElementById('select_pt');
            ptSelect.innerHTML = '<option value="">A carregar...</option>';
            if(!idCliente) { ptSelect.innerHTML = '<option value="">Selecione o Cliente primeiro...</option>'; return; }

            let fd = new FormData();
            fd.append('ajax_action', 'get_pts');
            fd.append('id_cliente', idCliente);
            
            fetch('', { method: 'POST', body: fd }).then(res => res.json()).then(data => {
                ptSelect.innerHTML = '<option value="">Selecione...</option>';
                data.forEach(pt => {
                    let text = pt.nome + (pt.numero_pt ? ' (Nº ' + pt.numero_pt + ')' : '');
                    let opt = new Option(text, pt.id);
                    ptSelect.add(opt);
                });
                if(idParaSelecionar) { ptSelect.value = idParaSelecionar; }
            });
        }
    </script>
</body>
</html>