<?php
session_start();
require_once 'db.php';

// REGRA DE OURO: Validação de sessão
if (!isset($_SESSION['user_id'])) { 
    header("Location: index.php"); 
    exit; 
}

if (file_exists('functions.php')) { 
    require_once 'functions.php'; 
} else { 
    function registrarLog($pdo, $acao, $modulo) { return true; } 
}

$mensagem = ''; 
$tipo_mensagem = '';

// Deteção do modo: Criar vs Editar
$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$modo_editar = !empty($id);

if (isset($_GET['editado']) && $_GET['editado'] == 1) {
    $mensagem = "Serviço guardado com sucesso!";
    $tipo_mensagem = "sucesso";
}

$clientes = $pdo->query("SELECT id, nome FROM clientes WHERE ativo = 1 ORDER BY nome ASC")->fetchAll(PDO::FETCH_ASSOC);
$colaboradores = $pdo->query("SELECT id, nome FROM utilizadores WHERE perfil IN ('admin', 'funcionario') AND ativo = 1 ORDER BY nome ASC")->fetchAll(PDO::FETCH_ASSOC);

// Verificar permissões para o modal de impressão
$is_admin = in_array($_SESSION['perfil'] ?? '', ['admin', 'administrativo']);

// 1. PREPARAR DADOS INICIAIS
$materiais_db = [];
$faturas_db = [];

if ($modo_editar) {
    $stmt = $pdo->prepare("SELECT * FROM servicos WHERE id = ?");
    $stmt->execute([$id]);
    $servico = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$servico) {
        header("Location: servicos.php");
        exit;
    }
    
    $materiais_db = json_decode($servico['materiais'] ?? '[]', true);
    $faturas_db = json_decode($servico['faturas'] ?? '[]', true);
    $titulo_pagina = "Editar Serviço #" . $servico['id'];
} else {
    $titulo_pagina = "Novo Serviço";
}

// LÓGICA DO COLABORADOR 1 BLOQUEADO (Segurança)
$id_colab_bloqueado = $_SESSION['user_id'];
$nome_colab_bloqueado = $_SESSION['nome'];

if ($modo_editar) {
    // Se estiver a editar, mantém o dono original (para que um Admin não roube a autoria ao editar)
    $id_colab_bloqueado = $servico['colaborador_1'];
    $stmt_nome = $pdo->prepare("SELECT nome FROM utilizadores WHERE id = ?");
    $stmt_nome->execute([$id_colab_bloqueado]);
    $nome_colab_bloqueado = $stmt_nome->fetchColumn() ?: $_SESSION['nome'];
}

// 2. PROCESSAR GRAVAÇÃO (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $pdo->beginTransaction();

        $id_cliente = $_POST['id_cliente'];
        if ($id_cliente === 'novo') {
            $stmt_cli_novo = $pdo->prepare("INSERT INTO clientes (nome, nif, telefone, email, morada, codigo_postal, cidade, ativo) VALUES (?, ?, ?, ?, ?, ?, ?, 1)");
            $stmt_cli_novo->execute([
                trim($_POST['nc_nome']), trim($_POST['nc_nif'] ?? ''), trim($_POST['nc_telefone'] ?? ''),
                trim($_POST['nc_email'] ?? ''), trim($_POST['nc_morada'] ?? ''), trim($_POST['nc_cp'] ?? ''), trim($_POST['nc_cidade'] ?? '')
            ]);
            $id_cliente = $pdo->lastInsertId();
            registrarLog($pdo, "Criou Novo Cliente via Serviço (Cliente ID: $id_cliente)", "Clientes");
        }

        $materiais = [];
        if(isset($_POST['mat_desc'])){
            foreach($_POST['mat_desc'] as $k => $v){
                if(trim($v) !== '') $materiais[] = ['desc' => trim($v), 'qtd' => trim($_POST['mat_qtd'][$k])];
            }
        }
        $faturas = [];
        if(isset($_POST['fat_forn'])){
            foreach($_POST['fat_forn'] as $k => $v){
                if(trim($v) !== '') $faturas[] = ['forn' => trim($v), 'num' => trim($_POST['fat_num'][$k])];
            }
        }

        $colab2 = (!empty($_POST['has_colab2']) && !empty($_POST['colaborador_2'])) ? $_POST['colaborador_2'] : null;
        $h2_ent = (!empty($_POST['has_h2']) && !empty($_POST['h2_entrada'])) ? $_POST['h2_entrada'] : null;
        $h2_sai = (!empty($_POST['has_h2']) && !empty($_POST['h2_saida'])) ? $_POST['h2_saida'] : null;
        $garantia = (!empty($_POST['has_garantia'])) ? 1 : 0;
        
        $has_refeicao = (!empty($_POST['has_refeicao'])) ? true : false;
        $custo_ref_1 = ($has_refeicao && !empty($_POST['custo_refeicao_1'])) ? str_replace(',', '.', $_POST['custo_refeicao_1']) : null;
        $custo_ref_2 = ($has_refeicao && $colab2 && !empty($_POST['custo_refeicao_2'])) ? str_replace(',', '.', $_POST['custo_refeicao_2']) : null;

        $faturado = (!empty($_POST['has_faturacao'])) ? 1 : 0;
        $fatura_num = ($faturado && !empty($_POST['fatura_num'])) ? trim($_POST['fatura_num']) : null;
        $fatura_data = ($faturado && !empty($_POST['fatura_data'])) ? $_POST['fatura_data'] : null;

        if ($modo_editar) {
            $dados = [
                $id_cliente, $_POST['colaborador_1'], $colab2, $_POST['data_servico'], $_POST['h1_entrada'], $_POST['h1_saida'], 
                $h2_ent, $h2_sai, trim($_POST['morada']), trim($_POST['codigo_postal']), trim($_POST['trabalho_realizado']),
                json_encode($materiais, JSON_UNESCAPED_UNICODE), json_encode($faturas, JSON_UNESCAPED_UNICODE),
                $garantia, $custo_ref_1, $custo_ref_2, $faturado, $fatura_num, $fatura_data, $id
            ];
            $sql_upd = "UPDATE servicos SET id_cliente=?, colaborador_1=?, colaborador_2=?, data_servico=?, h1_entrada=?, h1_saida=?, h2_entrada=?, h2_saida=?, morada=?, codigo_postal=?, trabalho_realizado=?, materiais=?, faturas=?, em_garantia=?, custo_refeicao_1=?, custo_refeicao_2=?, faturado=?, fatura_num=?, fatura_data=? WHERE id=?";
            $pdo->prepare($sql_upd)->execute($dados);
            $servico_id_atual = $id;
        } else {
            $dados = [
                $id_cliente, $_POST['colaborador_1'], $colab2, $_POST['data_servico'], $_POST['h1_entrada'], $_POST['h1_saida'], 
                $h2_ent, $h2_sai, trim($_POST['morada']), trim($_POST['codigo_postal']), trim($_POST['trabalho_realizado']),
                json_encode($materiais, JSON_UNESCAPED_UNICODE), json_encode($faturas, JSON_UNESCAPED_UNICODE),
                $garantia, $custo_ref_1, $custo_ref_2, 0, null, null
            ];
            $sql_ins = "INSERT INTO servicos (id_cliente, colaborador_1, colaborador_2, data_servico, h1_entrada, h1_saida, h2_entrada, h2_saida, morada, codigo_postal, trabalho_realizado, materiais, faturas, em_garantia, custo_refeicao_1, custo_refeicao_2, faturado, fatura_num, fatura_data) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)";
            $pdo->prepare($sql_ins)->execute($dados);
            $servico_id_atual = $pdo->lastInsertId();
        }

        $pdo->commit();
        registrarLog($pdo, ($modo_editar ? "Editou" : "Criou") . " Serviço #" . $servico_id_atual, "Serviços");

        $redirect = $_POST['redirect_action'] ?? 'voltar';
        
        if ($redirect === 'imprimir') {
            header("Location: servico_form.php?id=$servico_id_atual&acao=imprimir");
        } else {
            header("Location: servicos.php?editado=1");
        }
        exit;
        
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $mensagem = $e->getMessage(); $tipo_mensagem = "erro";
    }
}
?>
<!DOCTYPE html>
<html lang="pt-PT">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title><?= $titulo_pagina ?> - Gestão MAB</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { font-family: system-ui, -apple-system, sans-serif; box-sizing: border-box; -webkit-tap-highlight-color: transparent; }
        body { background-color: #f7f9fc; margin: 0; color: #333; font-size: 14px; padding-bottom: 100px; }
        input, select, textarea, button { font-family: inherit; font-size: 14px; outline: none; }
        
        /* TOPBAR UNIFORMIZADA E ÍCONES */
        .topbar { background-color: #fff; padding: 10px 40px; box-shadow: 0 2px 15px rgba(0,0,0,0.05); display: flex; justify-content: space-between; align-items: center; position: sticky; top: 0; z-index: 100; }
        .topbar-left .logo { height: 35px; }
        .topbar-center { display: flex; align-items: center; gap: 20px; }
        .nav-icon { color: #95a5a6; font-size: 20px; text-decoration: none; transition: 0.2s; padding: 5px; border-radius: 6px; }
        .nav-icon:hover { background: #f1f2f6; }
        
        .nav-icon.i-clientes.active, .nav-icon.i-clientes:hover { color: #3498db; }
        .nav-icon.i-visitas.active, .nav-icon.i-visitas:hover { color: #e84393; }
        .nav-icon.i-servicos.active, .nav-icon.i-servicos:hover { color: #b32a2a; }
        .nav-icon.i-obras.active, .nav-icon.i-obras:hover { color: #2ecc71; }
        .nav-icon.i-relatorios.active, .nav-icon.i-relatorios:hover { color: #16a085; }
        .nav-icon.i-orcamentos.active, .nav-icon.i-orcamentos:hover { color: #f39c12; }
        .nav-icon.i-termos.active, .nav-icon.i-termos:hover { color: #9b59b6; }
        .nav-icon.i-equipa.active, .nav-icon.i-equipa:hover { color: #7f8c8d; }
        .nav-icon.i-config.active, .nav-icon.i-config:hover { color: #2c3e50; }
        
        .topbar-right { display: flex; align-items: center; gap: 20px; }
        .btn-back { color: #576574; text-decoration: none; font-size: 20px; transition: color 0.2s; }
        .btn-back:hover { color: #b32a2a; }
        
        .user-menu { position: relative; }
        .user-btn { background: #fdfdfd; border: 1px solid #f1f2f6; border-radius: 30px; font-size: 14px; font-weight: 600; color: #2c3e50; cursor: pointer; display: flex; align-items: center; gap: 10px; padding: 6px 15px 6px 6px; transition: 0.2s; }
        .user-btn:hover { background: #fff; box-shadow: 0 4px 10px rgba(0,0,0,0.05); }
        .user-avatar { background: #e74c3c; color: white; width: 28px; height: 28px; border-radius: 50%; display: flex; justify-content: center; align-items: center; font-size: 14px; border: 2px solid #fadbd8; }
        
        .dropdown-content { display: none; position: absolute; right: 0; top: 100%; background: #fff; min-width: 180px; box-shadow: 0 10px 30px rgba(0,0,0,0.1); border-radius: 8px; overflow: hidden; margin-top: 5px; z-index: 200;}
        .user-menu:hover .dropdown-content { display: block; }
        .dropdown-content a { color: #576574; padding: 12px 20px; text-decoration: none; display: block; font-size: 14px; }
        .dropdown-content a:hover { background: #fdf2f2; color: #b32a2a; }
        
        .container { max-width: 900px; margin: 15px auto; padding: 0 15px; }
        
        .header-box { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
        .header-box h1 { margin: 0; font-size: 18px; color: #2c3e50; }
        
        .btn-print { background: #fff; color: #3498db; border: 1px solid #dcdde1; padding: 8px 15px; border-radius: 8px; text-decoration: none; font-weight: 600; display: inline-flex; align-items: center; gap: 8px; transition: 0.2s; cursor: pointer; }
        .btn-print:hover { border-color: #3498db; background: #f0f8ff; }

        .card { background: #fff; padding: 25px; border-radius: 12px; border: 1px solid #e1e5ea; box-shadow: 0 2px 10px rgba(0,0,0,0.03); margin-bottom: 15px; }
        h2 { font-size: 14px; color: #b32a2a; margin: 0 0 15px 0; border-bottom: 1px solid #f1f2f6; padding-bottom: 8px; text-transform: uppercase; display: flex; align-items: center; gap: 8px; }
        
        .form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 15px; }
        .form-group { display: flex; flex-direction: column; margin-bottom: 15px; position: relative; }
        label { font-weight: 600; margin-bottom: 6px; font-size: 11px; color: #576574; text-transform: uppercase; }
        input, select, textarea { padding: 12px; border: 1px solid #dcdde1; border-radius: 8px; transition: 0.2s; background: #fff; width: 100%; }
        input:focus, select:focus, textarea:focus { border-color: #b32a2a; }
        
        .switch-container { display: flex; align-items: center; gap: 10px; margin-bottom: 15px; }
        .switch { position: relative; display: inline-block; width: 36px; height: 20px; flex-shrink: 0; }
        .switch input { opacity: 0; width: 0; height: 0; }
        .slider { position: absolute; cursor: pointer; top: 0; left: 0; right: 0; bottom: 0; background-color: #dcdde1; transition: .3s; border-radius: 20px; }
        .slider:before { position: absolute; content: ""; height: 14px; width: 14px; left: 3px; bottom: 3px; background-color: white; transition: .3s; border-radius: 50%; box-shadow: 0 2px 4px rgba(0,0,0,0.2); }
        input:checked + .slider { background-color: #b32a2a; }
        input:checked + .slider:before { transform: translateX(16px); }
        .switch-label { font-weight: 700; font-size: 12px; color: #2c3e50; text-transform: uppercase; cursor: pointer; }

        .hidden-section { display: none; margin-bottom: 15px; padding: 15px; background: #fdfdfd; border-radius: 8px; border: 1px dashed #dcdde1; }
        .btn-gps { position: absolute; right: 5px; top: 25px; background: #b32a2a; color: #fff; border: none; padding: 8px 15px; border-radius: 6px; font-size: 12px; cursor: pointer; z-index: 5; }

        .dyn-row { display: grid; grid-template-columns: 1fr 90px 40px; gap: 10px; margin-bottom: 10px; align-items: center; }
        .dyn-row-fat { grid-template-columns: 1fr 1fr 40px; }
        .btn-del { color: #e74c3c; background: none; border: none; cursor: pointer; font-size: 18px; padding: 5px; }
        .btn-add { background: #576574; color: #fff; border: none; padding: 8px 15px; border-radius: 6px; cursor: pointer; font-size: 14px; transition: 0.2s; display: inline-flex; align-items: center; justify-content: center; }
        .btn-add:hover { background: #2c3e50; }

        .floating-action-bar { position: fixed; bottom: 20px; right: 20px; left: 20px; max-width: 900px; margin: 0 auto; background: #fff; padding: 15px 25px; border-radius: 12px; box-shadow: 0 -5px 20px rgba(0,0,0,0.15); display: flex; justify-content: flex-end; border: 1px solid #e1e5ea; z-index: 1000; }
        .btn-save { background: #b32a2a; color: #fff; padding: 12px 30px; border-radius: 8px; border: none; font-weight: 700; font-size: 14px; text-transform: uppercase; cursor: pointer; transition: 0.2s; display: flex; align-items: center; gap: 8px; }
        .btn-save:hover { background: #902222; }

        .alert { padding: 15px; border-radius: 8px; margin-bottom: 15px; text-align: center; font-weight: 600; }
        .alert-erro { background: #fdeded; color: #b32a2a; border: 1px solid #f9cccc; }
        .alert-sucesso { background: #e8f5e9; color: #2e7d32; border: 1px solid #c8e6c9; }

        /* Modais */
        .modal { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.6); z-index: 1001; justify-content: center; align-items: center; padding: 20px; }
        .modal.show { display: flex; }
        .modal-content { background: #fff; padding: 30px; border-radius: 12px; width: 100%; max-width: 400px; text-align: center; }
        .modal-btns { display: flex; flex-direction: column; gap: 10px; margin-top: 20px; }
        
        .btn-primary { background: #3498db; color: #fff; padding: 14px; border-radius: 8px; text-decoration: none; font-weight: 600; font-size: 15px; border: none; cursor: pointer; }
        .btn-secondary { background: #f1f2f6; color: #576574; padding: 14px; border-radius: 8px; text-decoration: none; font-weight: 600; font-size: 15px; border: none; cursor: pointer; }

        /* Modal Impressão Uniformizado */
        .modal-print-content { max-width: 450px; text-align: left; padding: 20px 25px; }
        .modal-print-header { display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #f1f2f6; padding-bottom: 15px; margin-bottom: 15px; }
        .modal-print-header h3 { margin: 0; color: #b32a2a; font-size: 16px; display: flex; align-items: center; gap: 8px; }
        .close-btn { color: #95a5a6; font-size: 22px; cursor: pointer; font-weight: bold; background: none; border: none; padding: 0; line-height: 1; transition: 0.2s; }
        .close-btn:hover { color: #333; }
        .modal-subtitle { color: #576574; font-size: 14px; font-weight: 700; margin-bottom: 15px; text-align: left; }
        
        .btn-pdf-option { display: flex; align-items: center; gap: 15px; width: 100%; padding: 15px; background: #fdfdfd; border: 1px solid #e1e5ea; border-radius: 8px; cursor: pointer; text-align: left; transition: all 0.2s; margin-bottom: 10px; font-family: inherit; }
        .btn-pdf-option:hover { background: #f9f9f9; border-color: #b32a2a; box-shadow: 0 2px 5px rgba(0,0,0,0.02); }
        .btn-pdf-option i { color: #b32a2a; font-size: 22px; }
        .btn-pdf-option span { color: #2c3e50; font-weight: 600; font-size: 14px; }

        @media (max-width: 768px) {
            .topbar { padding: 10px 15px; }
            .topbar-center { display: none; } /* Regra de Ouro Mobile: Sem ícones centrais */
            .form-grid { grid-template-columns: 1fr; gap: 0; }
            .card { padding: 15px; }
        }
    </style>
</head>
<body>

    <header class="topbar">
        <div class="topbar-left"><a href="index.php"><img src="mab.png" alt="MAB" class="logo"></a></div>
        <div class="topbar-center">
            <a href="index.php" class="nav-icon" title="Início"><i class="fa-solid fa-house"></i></a>
            <a href="clientes.php" class="nav-icon i-clientes" title="Clientes"><i class="fa-solid fa-users"></i></a>
            <a href="visitas.php" class="nav-icon i-visitas" title="Visitas"><i class="fa-solid fa-calendar-check"></i></a>
            <a href="servicos.php" class="nav-icon i-servicos active" title="Serviços"><i class="fa-solid fa-screwdriver-wrench"></i></a>
            <a href="obras.php" class="nav-icon i-obras" title="Obras"><i class="fa-solid fa-helmet-safety"></i></a>
            <a href="relatorios.php" class="nav-icon i-relatorios" title="Relatórios de Obra"><i class="fa-solid fa-clipboard-list"></i></a>
            <a href="orcamentos.php" class="nav-icon i-orcamentos" title="Orçamentos"><i class="fa-solid fa-file-invoice"></i></a>
            <a href="termos.php" class="nav-icon i-termos" title="Termos"><i class="fa-solid fa-file-signature"></i></a>
            <a href="utilizadores.php" class="nav-icon i-equipa" title="Equipa"><i class="fa-solid fa-id-card-clip"></i></a>
            <a href="configuracoes.php" class="nav-icon i-config" title="Configurações"><i class="fa-solid fa-gear"></i></a>
        </div>
        <div class="topbar-right">
            <a href="servicos.php" class="btn-back" title="Voltar"><i class="fa-solid fa-arrow-left"></i></a>
            <div class="user-menu">
                <button class="user-btn">
                    <div class="user-avatar"><i class="fa-solid fa-user"></i></div>
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
            <div class="alert <?= $tipo_mensagem === 'sucesso' ? 'alert-sucesso' : 'alert-erro' ?>"><?= htmlspecialchars($mensagem, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>
        
        <div class="header-box">
            <h1><?= htmlspecialchars($titulo_pagina) ?></h1>
            <?php if ($modo_editar): ?>
                <button type="button" class="btn-print" onclick="abrirModalImprimir()"><i class="fa-solid fa-print"></i> Imprimir</button>
            <?php endif; ?>
        </div>

        <form method="POST" id="formServico">
            <input type="hidden" name="redirect_action" id="redirect_action" value="voltar">

            <div class="card">
                <h2><i class="fa-solid fa-user"></i> Cliente</h2>
                <div class="form-group">
                    <select name="id_cliente" id="id_cliente" required onchange="toggleNovoCliente(this.value)">
                        <option value="">-- Selecione --</option>
                        <option value="novo" style="font-weight:bold; color:#e67e22;">+ CRIAR NOVO CLIENTE</option>
                        <?php foreach($clientes as $c): ?>
                            <option value="<?= $c['id'] ?>" <?= (isset($servico) && $servico['id_cliente'] == $c['id']) ? 'selected' : '' ?>><?= htmlspecialchars($c['nome']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div id="section_novo_cliente" class="hidden-section">
                    <div class="form-group"><label>Nome Completo *</label><input type="text" name="nc_nome" id="nc_nome"></div>
                    <div class="form-grid">
                        <div class="form-group"><label>NIF</label><input type="text" name="nc_nif"></div>
                        <div class="form-group"><label>Telefone</label><input type="text" name="nc_telefone"></div>
                    </div>
                    <div class="form-group"><label>Email</label><input type="email" name="nc_email"></div>
                    <div class="form-group"><label>Morada Faturação</label><input type="text" name="nc_morada"></div>
                    <div class="form-grid">
                        <div class="form-group"><label>Cód. Postal</label><input type="text" name="nc_cp" placeholder="0000-000"></div>
                        <div class="form-group"><label>Cidade</label><input type="text" name="nc_cidade"></div>
                    </div>
                </div>
            </div>

            <div class="card">
                <h2><i class="fa-solid fa-users"></i> Equipa</h2>
                
                <div class="form-group">
                    <label>Colaborador Principal *</label>
                    <input type="text" value="<?= htmlspecialchars($nome_colab_bloqueado) ?>" disabled style="background-color: #f1f2f6; color: #7f8c8d; font-weight: 600; cursor: not-allowed;">
                    <input type="hidden" name="colaborador_1" id="colaborador_1" value="<?= htmlspecialchars($id_colab_bloqueado) ?>">
                </div>

                <div class="switch-container">
                    <label class="switch">
                        <input type="checkbox" name="has_colab2" id="chk_colab2" value="1" onchange="toggleSec('colab2', this)" <?= !empty($servico['colaborador_2']) ? 'checked' : '' ?>>
                        <span class="slider"></span>
                    </label>
                    <label class="switch-label" for="chk_colab2">+ 2º Colaborador?</label>
                </div>

                <div id="sec_colab2" class="hidden-section" style="<?= !empty($servico['colaborador_2']) ? 'display:block;' : '' ?>">
                    <div class="form-group" style="margin-bottom:0;">
                        <label>2º Colaborador *</label>
                        <select name="colaborador_2" id="colaborador_2">
                            <option value="">-- Selecione --</option>
                            <?php foreach($colaboradores as $c): ?>
                                <option value="<?= $c['id'] ?>" <?= (isset($servico) && $servico['colaborador_2'] == $c['id']) ? 'selected' : '' ?>><?= htmlspecialchars($c['nome']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>

            <div class="card">
                <h2><i class="fa-solid fa-clock"></i> Local e Horários</h2>
                <div class="form-grid">
                    <div class="form-group">
                        <label>Data do Serviço *</label>
                        <input type="date" name="data_servico" value="<?= $servico['data_servico'] ?? date('Y-m-d') ?>" required>
                    </div>
                    <div class="form-group" style="justify-content: flex-end; padding-bottom:10px;">
                        <div class="switch-container" style="margin:0;">
                            <label class="switch">
                                <input type="checkbox" name="has_garantia" id="chk_garantia" value="1" <?= !empty($servico['em_garantia']) ? 'checked' : '' ?>>
                                <span class="slider"></span>
                            </label>
                            <label class="switch-label" for="chk_garantia">Garantia</label>
                        </div>
                    </div>
                </div>
                
                <div class="form-grid">
                    <div class="form-group"><label>Entrada (1º Horário) *</label><input type="time" name="h1_entrada" id="h1_entrada" value="<?= $servico['h1_entrada'] ?? '' ?>" required></div>
                    <div class="form-group"><label>Saída (1º Horário) *</label><input type="time" name="h1_saida" id="h1_saida" value="<?= $servico['h1_saida'] ?? '' ?>" required></div>
                </div>

                <div class="switch-container">
                    <label class="switch">
                        <input type="checkbox" name="has_h2" id="chk_h2" value="1" onchange="toggleSec('h2', this)" <?= !empty($servico['h2_entrada']) ? 'checked' : '' ?>>
                        <span class="slider"></span>
                    </label>
                    <label class="switch-label" for="chk_h2">+ 2º Horário?</label>
                </div>

                <div id="sec_h2" class="hidden-section" style="<?= !empty($servico['h2_entrada']) ? 'display:block;' : '' ?>">
                    <div class="form-grid">
                        <div class="form-group" style="margin-bottom:0;"><label>Entrada (2º Horário) *</label><input type="time" name="h2_entrada" id="h2_entrada" value="<?= $servico['h2_entrada'] ?? '' ?>"></div>
                        <div class="form-group" style="margin-bottom:0;"><label>Saída (2º Horário) *</label><input type="time" name="h2_saida" id="h2_saida" value="<?= $servico['h2_saida'] ?? '' ?>"></div>
                    </div>
                </div>

                <div class="switch-container">
                    <label class="switch">
                        <input type="checkbox" name="has_refeicao" id="chk_refeicao" value="1" onchange="toggleSec('refeicao', this)" <?= !empty($servico['custo_refeicao_1']) ? 'checked' : '' ?>>
                        <span class="slider"></span>
                    </label>
                    <label class="switch-label" for="chk_refeicao">+ Refeição?</label>
                </div>
                
                <div id="sec_refeicao" class="hidden-section" style="<?= !empty($servico['custo_refeicao_1']) ? 'display:block;' : '' ?>">
                    <div class="form-grid">
                        <div id="group_ref1" class="form-group" style="margin-bottom:0;">
                            <label>Custo Refeição (Colaborador 1) *</label>
                            <input type="text" name="custo_refeicao_1" id="custo_refeicao_1" value="<?= htmlspecialchars($_POST['custo_refeicao_1'] ?? $servico['custo_refeicao_1'] ?? '') ?>" placeholder="0,00" onblur="formatCusto(this)">
                        </div>
                        <div id="group_ref2" class="form-group" style="display:<?= !empty($servico['colaborador_2']) ? 'block' : 'none' ?>; margin-bottom:0;">
                            <label>Custo Refeição (Colaborador 2)</label>
                            <input type="text" name="custo_refeicao_2" id="custo_refeicao_2" value="<?= htmlspecialchars($_POST['custo_refeicao_2'] ?? $servico['custo_refeicao_2'] ?? '') ?>" placeholder="0,00" onblur="formatCusto(this)">
                        </div>
                    </div>
                </div>

                <div class="form-group" style="margin-top: 15px;">
                    <label>Morada do Local *</label>
                    <input type="text" name="morada" id="gps_morada" value="<?= htmlspecialchars($servico['morada'] ?? '') ?>" required>
                    <button type="button" class="btn-gps" onclick="getGPS(this)"><i class="fa-solid fa-crosshairs"></i> GPS</button>
                </div>
                <div class="form-group" style="margin-bottom:0;">
                    <label>Código Postal *</label>
                    <input type="text" name="codigo_postal" id="gps_cp" value="<?= htmlspecialchars($servico['codigo_postal'] ?? '') ?>" required placeholder="0000-000">
                </div>
            </div>

            <div class="card">
                <h2><i class="fa-solid fa-screwdriver-wrench"></i> Trabalho Realizado</h2>
                <div class="form-group" style="margin-bottom:0;">
                    <textarea name="trabalho_realizado" rows="5" required placeholder="Descreva os trabalhos executados..."><?= htmlspecialchars($servico['trabalho_realizado'] ?? '') ?></textarea>
                </div>
            </div>

            <div class="card">
                <h2><i class="fa-solid fa-box"></i> Materiais Utilizados</h2>
                <div id="wrap_mat">
                    <?php if($modo_editar && !empty($materiais_db)): foreach($materiais_db as $m): ?>
                        <div class="dyn-row">
                            <input type="text" name="mat_desc[]" value="<?= htmlspecialchars($m['desc']) ?>">
                            <input type="number" step="0.01" name="mat_qtd[]" value="<?= htmlspecialchars($m['qtd']) ?>">
                            <button type="button" class="btn-del" onclick="this.parentElement.remove()"><i class="fa-solid fa-trash-can"></i></button>
                        </div>
                    <?php endforeach; endif; ?>
                </div>
                <button type="button" class="btn-add" onclick="addLinha('mat')"><i class="fa-solid fa-plus"></i></button>
            </div>

            <div class="card">
                <h2><i class="fa-solid fa-receipt"></i> Faturas Fornecedor / Despesas</h2>
                <div id="wrap_fat">
                    <?php if($modo_editar && !empty($faturas_db)): foreach($faturas_db as $f): ?>
                        <div class="dyn-row dyn-row-fat">
                            <input type="text" name="fat_forn[]" value="<?= htmlspecialchars($f['forn']) ?>">
                            <input type="text" name="fat_num[]" value="<?= htmlspecialchars($f['num']) ?>">
                            <button type="button" class="btn-del" onclick="this.parentElement.remove()"><i class="fa-solid fa-trash-can"></i></button>
                        </div>
                    <?php endforeach; endif; ?>
                </div>
                <button type="button" class="btn-add" onclick="addLinha('fat')"><i class="fa-solid fa-plus"></i></button>
            </div>
            
            <?php if ($modo_editar): ?>
            <div class="card" style="border-left: 4px solid #f39c12; margin-bottom:40px;">
                <h2><i class="fa-solid fa-file-invoice-dollar"></i> Faturação a Cliente</h2>
                
                <div class="switch-container" style="margin-bottom:0;">
                    <label class="switch">
                        <input type="checkbox" name="has_faturacao" id="chk_faturacao" value="1" onchange="toggleSec('faturacao', this)" <?= !empty($servico['faturado']) ? 'checked' : '' ?>>
                        <span class="slider"></span>
                    </label>
                    <label class="switch-label" for="chk_faturacao" style="color:#f39c12;">Já está faturado?</label>
                </div>

                <div id="sec_faturacao" class="hidden-section" style="<?= !empty($servico['faturado']) ? 'display:block; margin-top:15px;' : 'margin-top:15px;' ?>">
                    <div class="form-grid">
                        <div class="form-group" style="margin-bottom:0;">
                            <label>Número da Fatura *</label>
                            <input type="text" name="fatura_num" id="fatura_num" value="<?= htmlspecialchars($servico['fatura_num'] ?? '') ?>" placeholder="Ex: FA 2026/01">
                        </div>
                        <div class="form-group" style="margin-bottom:0;">
                            <label>Data da Fatura *</label>
                            <input type="date" name="fatura_data" id="fatura_data" value="<?= htmlspecialchars($servico['fatura_data'] ?? '') ?>">
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <div class="floating-action-bar">
                <button type="submit" class="btn-save"><i class="fa-solid fa-floppy-disk"></i> GUARDAR SERVIÇO</button>
            </div>
        </form>
    </div>

    <div class="modal" id="modalRedirect">
        <div class="modal-content">
            <h3 style="color:#2c3e50;"><i class="fa-solid fa-circle-check" style="color:#2ecc71;"></i> Serviço Guardado</h3>
            <p>O que deseja fazer agora?</p>
            <div class="modal-btns">
                <button type="button" onclick="confirmarRedirect('voltar')" class="btn-secondary"><i class="fa-solid fa-arrow-left"></i> Voltar à listagem</button>
                <button type="button" onclick="confirmarRedirect('imprimir')" class="btn-primary"><i class="fa-solid fa-print"></i> Imprimir</button>
            </div>
        </div>
    </div>

    <div class="modal" id="modalImprimir">
        <div class="modal-content modal-print-content">
            <div class="modal-print-header">
                <h3><i class="fa-solid fa-print"></i> Gerar Documento</h3>
                <button type="button" class="close-btn" onclick="fecharModalImprimir()">&times;</button>
            </div>
            
            <div class="modal-subtitle">Serviço Nº <span id="display_id_print"><?= htmlspecialchars($id ?? '') ?></span></div>
            
            <button type="button" class="btn-pdf-option" onclick="gerarPDF('cliente')">
                <i class="fa-solid fa-file-pdf"></i>
                <span>Serviço Cliente (Resumo)</span>
            </button>
            
            <?php if ($is_admin): ?>
            <button type="button" class="btn-pdf-option" onclick="gerarPDF('completa')">
                <i class="fa-solid fa-file-pdf"></i>
                <span>Serviço Gestão (Completo)</span>
            </button>
            <?php endif; ?>
        </div>
    </div>

    <script>
        const isPostSavePrint = <?= (isset($_GET['acao']) && $_GET['acao'] === 'imprimir') ? 'true' : 'false' ?>;

        function abrirModalImprimir() {
            document.getElementById('modalImprimir').classList.add('show');
        }

        function fecharModalImprimir() {
            document.getElementById('modalImprimir').classList.remove('show');
            if (isPostSavePrint) {
                window.location.href = 'servicos.php';
            }
        }

        function gerarPDF(tipo) {
            const id = <?= $id ?? 0 ?>;
            if(id > 0) {
                window.open(`servico_pdf.php?id=${id}&tipo=${tipo}`, '_blank');
            }
            fecharModalImprimir(); 
        }

        let isRedirectChosen = false;

        function toggleNovoCliente(val) {
            const div = document.getElementById('section_novo_cliente');
            const nomeInput = document.getElementById('nc_nome');
            if (val === 'novo') {
                div.style.display = 'block';
                nomeInput.setAttribute('required', 'required');
            } else {
                div.style.display = 'none';
                nomeInput.removeAttribute('required');
            }
        }

        function toggleSec(type, checkbox) {
            const s = document.getElementById('sec_' + type);
            
            if (!checkbox.checked) {
                s.style.display = 'none'; 
                if(type !== 'refeicao' && type !== 'faturacao') s.querySelectorAll('input, select').forEach(el => el.removeAttribute('required'));
                if(type === 'refeicao') document.getElementById('custo_refeicao_1').removeAttribute('required');
                if(type === 'faturacao') { document.getElementById('fatura_num').removeAttribute('required'); document.getElementById('fatura_data').removeAttribute('required'); }
            } else {
                s.style.display = 'block'; 
                if(type !== 'refeicao' && type !== 'faturacao') s.querySelectorAll('input, select').forEach(el => el.setAttribute('required', 'required'));
                if(type === 'refeicao') document.getElementById('custo_refeicao_1').setAttribute('required', 'required');
                if(type === 'faturacao') { document.getElementById('fatura_num').setAttribute('required', 'required'); document.getElementById('fatura_data').setAttribute('required', 'required'); }
                if(type === 'colab2') updateColab2();
            }
        }

        function updateColab2() {
            const c1 = document.getElementById('colaborador_1').value;
            const s2 = document.getElementById('colaborador_2');
            const ref2 = document.getElementById('group_ref2');
            
            if(!s2) return;
            for(let o of s2.options) {
                o.style.display = (o.value === c1 && c1 !== "") ? 'none' : 'block';
            }
            
            const sectionColab2 = document.getElementById('sec_colab2');
            if (sectionColab2.style.display === 'block' && c1 !== "") {
                ref2.style.display = 'block';
            } else {
                ref2.style.display = 'none';
            }

            if(s2.value === c1) s2.value = "";
        }

        function getGPS(btn) {
            if(!navigator.geolocation) return alert("GPS não suportado no seu dispositivo.");
            btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i>';
            
            navigator.geolocation.getCurrentPosition(function(pos) {
                fetch(`https://nominatim.openstreetmap.org/reverse?format=json&lat=${pos.coords.latitude}&lon=${pos.coords.longitude}`)
                .then(res => res.json())
                .then(data => {
                    const addr = data.address;
                    document.getElementById('gps_morada').value = (addr.road || '') + ' ' + (addr.house_number || '') + ', ' + (addr.suburb || addr.city || addr.town || '');
                    document.getElementById('gps_cp').value = addr.postcode || '';
                    btn.innerHTML = '<i class="fa-solid fa-crosshairs"></i> GPS';
                }).catch(() => { alert("Erro ao contactar serviço de mapas."); btn.innerHTML = '<i class="fa-solid fa-crosshairs"></i> GPS'; });
            }, function() {
                alert("Por favor, ative a localização no seu telemóvel/browser."); btn.innerHTML = '<i class="fa-solid fa-crosshairs"></i> GPS';
            });
        }

        function addLinha(tipo) {
            const w = document.getElementById('wrap_' + tipo);
            const d = document.createElement('div');
            if (tipo === 'mat') {
                d.className = 'dyn-row';
                d.innerHTML = `<input type="text" name="mat_desc[]" placeholder="Descrição"><input type="number" step="0.01" name="mat_qtd[]" placeholder="Qtd"><button type="button" class="btn-del" onclick="this.parentElement.remove()"><i class="fa-solid fa-trash-can"></i></button>`;
            } else {
                d.className = 'dyn-row dyn-row-fat';
                d.innerHTML = `<input type="text" name="fat_forn[]" placeholder="Fornecedor"><input type="text" name="fat_num[]" placeholder="Nº Fatura/Talão"><button type="button" class="btn-del" onclick="this.parentElement.remove()"><i class="fa-solid fa-trash-can"></i></button>`;
            }
            w.appendChild(d);
        }

        function formatCusto(el) {
            if(el.value !== "") {
                let val = parseFloat(el.value.replace(',', '.')) || 0;
                el.value = val.toFixed(2).replace('.', ',');
            }
        }

        const form = document.getElementById('formServico');
        form.addEventListener('submit', function(e) {
            if (!isRedirectChosen) {
                e.preventDefault();
                
                const h1i = document.getElementById('h1_entrada').value;
                const h1s = document.getElementById('h1_saida').value;
                if(h1i && h1s && h1i >= h1s) return alert("Erro: A Saída (1) tem de ser superior à Entrada (1).");

                if (document.getElementById('chk_h2').checked) {
                    const h2i = document.getElementById('h2_entrada').value;
                    const h2s = document.getElementById('h2_saida').value;
                    if(h1s && h2i && h1s >= h2i) return alert("Erro: A Entrada (2) tem de ser posterior à Saída (1).");
                    if(h2i && h2s && h2i >= h2s) return alert("Erro: A Saída (2) tem de ser superior à Entrada (2).");
                }
                
                document.getElementById('modalRedirect').classList.add('show');
            }
        });

        function confirmarRedirect(escolha) {
            document.getElementById('redirect_action').value = escolha;
            isRedirectChosen = true; 
            document.getElementById('modalRedirect').classList.remove('show');
            form.submit(); 
        }

        window.onload = function() {
            updateColab2();
            if (isPostSavePrint) {
                abrirModalImprimir();
            }
        };
    </script>
</body>
</html>