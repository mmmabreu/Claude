<?php
session_start();
require_once 'db.php';

// REGRA DE OURO 1: Validação de sessão
if (!isset($_SESSION['user_id'])) { 
    header("Location: index.php"); 
    exit; 
}

if (file_exists('functions.php')) { 
    require_once 'functions.php'; 
} else { 
    function registrarLog($pdo, $acao, $modulo) { return true; } 
}

$perfil_user = $_SESSION['perfil'] ?? '';
$is_admin = in_array($perfil_user, ['admin', 'administrativo']);

// REGRA DE OURO 2: Acesso Restrito pela Matriz (Proteção de URL direta)
$stmt_perm = $pdo->prepare("SELECT modulo FROM permissoes WHERE grupo = :perfil AND pode_aceder = 1");
$stmt_perm->execute(['perfil' => $perfil_user]);
$meus_acessos = $stmt_perm->fetchAll(PDO::FETCH_COLUMN);

// O Planeamento pode vir de Obras ou Orçamentos
if (!$is_admin && !in_array('obras', $meus_acessos) && !in_array('orcamentos', $meus_acessos)) {
    header("Location: index.php?erro=sem_permissao");
    exit;
}

// REGRA DE OURO 3: Obter apenas o primeiro nome para o Menu
$nome_completo = $_SESSION['nome'] ?? 'Utilizador';
$primeiro_nome = explode(' ', trim($nome_completo))[0];

$mensagem = ''; 
$tipo_mensagem = '';

// Identificar o contexto
$orcamento_id = filter_input(INPUT_GET, 'orcamento_id', FILTER_VALIDATE_INT);
$obra_id = filter_input(INPUT_GET, 'obra_id', FILTER_VALIDATE_INT);
$is_obra = !empty($obra_id);

if (!$orcamento_id && !$obra_id) {
    die("Erro: O planeamento precisa de estar associado a um Orçamento ou a uma Obra.");
}

// LÓGICA DE ACTUALIZAÇÃO SILENCIOSA (AJAX) PARA CHECKBOX CONCLUÍDA
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_tarefa_id']) && isset($_POST['ajax_concluida'])) {
    $t_id = (int)$_POST['ajax_tarefa_id'];
    $t_concluida = (int)$_POST['ajax_concluida'];
    
    try {
        $stmt_ajax = $pdo->prepare("UPDATE planeamento_linhas SET concluida = ? WHERE id = ?");
        $stmt_ajax->execute([$t_concluida, $t_id]);
        echo json_encode(['sucesso' => true]);
    } catch(Exception $e) {
        echo json_encode(['sucesso' => false, 'erro' => $e->getMessage()]);
    }
    exit; 
}

// Mensagem de sucesso
if (isset($_GET['editado']) && $_GET['editado'] == 1) {
    $mensagem = "Planeamento guardado com sucesso!";
    $tipo_mensagem = "sucesso";
}

// Buscar Custos de Configuração
$valor_hora = 0;
$custo_por_km = 0;
$valor_almoco = 0;

if (!$is_obra) {
    try {
        $stmt_ch = $pdo->prepare("SELECT valor FROM configuracoes WHERE chave = 'valor_hora' LIMIT 1");
        $stmt_ch->execute();
        $valor_ch = $stmt_ch->fetchColumn();
        if ($valor_ch !== false) $valor_hora = (float)str_replace(',', '.', $valor_ch);
        
        $stmt_ckm = $pdo->prepare("SELECT valor FROM configuracoes WHERE chave = 'preco_km' LIMIT 1");
        $stmt_ckm->execute();
        $valor_ckm = $stmt_ckm->fetchColumn();
        if ($valor_ckm !== false) $custo_por_km = (float)str_replace(',', '.', $valor_ckm);
        
        $stmt_alm = $pdo->prepare("SELECT valor FROM configuracoes WHERE chave = 'valor_almoco' LIMIT 1");
        $stmt_alm->execute();
        $valor_a = $stmt_alm->fetchColumn();
        if ($valor_a !== false) $valor_almoco = (float)str_replace(',', '.', $valor_a);
    } catch (Exception $e) {}
}

// 1. BUSCAR INFORMAÇÃO DO CABEÇALHO
$titulo_contexto = "";
$ref_contexto = "";
$subtitulo_contexto = "";
$distancia_km = 0;
$valor_acessorios = 0;
$cor_contexto = $is_obra ? "#2ecc71" : "#f39c12"; 

if ($orcamento_id) {
    try {
        $stmt = $pdo->prepare("SELECT numero_orcamento, nome_obra, km, valor_acessorios FROM orcamentos WHERE id = ?");
        $stmt->execute([$orcamento_id]);
        $pai = $stmt->fetch(PDO::FETCH_ASSOC);
        $valor_acessorios = (float)str_replace(',', '.', $pai['valor_acessorios'] ?? '0');
    } catch (Exception $e) {
        $stmt = $pdo->prepare("SELECT numero_orcamento, nome_obra, km FROM orcamentos WHERE id = ?");
        $stmt->execute([$orcamento_id]);
        $pai = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    if (!$pai) die("Orçamento não encontrado.");
    $titulo_contexto = "Orçamento";
    $ref_contexto = $pai['numero_orcamento'];
    $subtitulo_contexto = $pai['nome_obra'];
    $distancia_km = (float)str_replace(',', '.', $pai['km'] ?? '0');
    
} else {
    $stmt = $pdo->prepare("SELECT nome, referencia_obra, km FROM obras WHERE id = ?");
    $stmt->execute([$obra_id]);
    $pai = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$pai) die("Obra não encontrada.");
    $titulo_contexto = "Obra";
    $ref_contexto = $pai['referencia_obra'];
    $subtitulo_contexto = $pai['nome'];
    $distancia_km = (float)str_replace(',', '.', $pai['km'] ?? '0');
}

// 2. PROCESSAR GRAVAÇÃO INTEGRAL
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['ajax_tarefa_id'])) {
    try {
        $pdo->beginTransaction();

        $dados_json = $_POST['planeamento_json'] ?? '[]';
        $linhas = json_decode($dados_json, true);
        
        $custo_total_injetar = (float)($_POST['custo_total_input'] ?? 0);
        $acessorios_input = (float)($_POST['acessorios_input'] ?? 0);

        if ($orcamento_id) {
            $pdo->prepare("DELETE FROM planeamento_linhas WHERE orcamento_id = ?")->execute([$orcamento_id]);
        } else {
            $pdo->prepare("DELETE FROM planeamento_linhas WHERE obra_id = ?")->execute([$obra_id]);
        }

        $sql_insert = "INSERT INTO planeamento_linhas (orcamento_id, obra_id, numero_fase, nome_fase, numero_dia, numero_homens, tipo_equipa, descricao_tarefa, tempo_tarefa, concluida) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt_insert = $pdo->prepare($sql_insert);

        if (is_array($linhas)) {
            foreach ($linhas as $L) {
                if (trim($L['descricao_tarefa'] ?? '') !== '') {
                    $stmt_insert->execute([
                        $orcamento_id, $is_obra ? $obra_id : null, 
                        (int)$L['numero_fase'], trim($L['nome_fase']), (int)$L['numero_dia'], 
                        (float)str_replace(',', '.', $L['numero_homens']), 
                        trim($L['tipo_equipa'] ?? 'AMBOS'), trim($L['descricao_tarefa']), 
                        (float)str_replace(',', '.', $L['tempo_tarefa']), 
                        isset($L['concluida']) && $L['concluida'] ? 1 : 0
                    ]);
                }
            }
        }
        
        // Atualiza Financeiro do Orçamento
        if ($orcamento_id && $custo_total_injetar > 0) {
            try {
                $stmt_upd = $pdo->prepare("UPDATE orcamentos SET mao_obra = ?, valor_acessorios = ? WHERE id = ?");
                $stmt_upd->execute([$custo_total_injetar, $acessorios_input, $orcamento_id]);
            } catch(Exception $e) {
                $stmt_upd = $pdo->prepare("UPDATE orcamentos SET mao_obra = ? WHERE id = ?");
                $stmt_upd->execute([$custo_total_injetar, $orcamento_id]);
            }
        }

        $pdo->commit();
        registrarLog($pdo, "Atualizou Planeamento de $titulo_contexto #$ref_contexto", "Planeamento");

        $redirect = $_POST['redirect_action'] ?? 'voltar';
        if ($redirect === 'continuar') {
            $param = $orcamento_id ? "orcamento_id=$orcamento_id" : "obra_id=$obra_id";
            header("Location: planeamento.php?$param&editado=1");
        } else {
            header("Location: " . ($orcamento_id ? "orcamentos.php" : "obras.php") . "?editado=1");
        }
        exit;
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $mensagem = "Erro ao guardar: " . $e->getMessage();
        $tipo_mensagem = "erro";
    }
}

// 3. CARREGAR DADOS EXISTENTES
$coluna_filtro = $orcamento_id ? 'orcamento_id' : 'obra_id';
$id_filtro = $orcamento_id ?: $obra_id;
$stmt_linhas = $pdo->prepare("SELECT * FROM planeamento_linhas WHERE $coluna_filtro = ? ORDER BY numero_fase ASC, numero_dia ASC, id ASC");
$stmt_linhas->execute([$id_filtro]);
$planeamento_atual = $stmt_linhas->fetchAll(PDO::FETCH_ASSOC);

foreach ($planeamento_atual as &$linha) {
    $linha['concluida'] = (int)$linha['concluida'] === 1;
}

$json_planeamento_init = json_encode($planeamento_atual, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE);
?>
<!DOCTYPE html>
<html lang="pt-PT">
<head>
    <meta charset="UTF-8">
    <title>Planeamento - Gestão MAB</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { font-family: system-ui, -apple-system, sans-serif; box-sizing: border-box; }
        body { background-color: #f7f9fc; margin: 0; color: #333; font-size: 14px; padding-bottom: 120px; }
        input, select, textarea, button { font-family: inherit; font-size: 14px; outline: none; }
        
        /* TOPBAR MINIMALISTA REGRA DE OURO */
        .topbar { background-color: #fff; padding: 15px 40px; box-shadow: 0 4px 20px rgba(0,0,0,0.05); display: flex; justify-content: space-between; align-items: center; position: sticky; top: 0; z-index: 100; }
        .topbar-left .logo { height: 40px; }
        .topbar-center { display: flex; gap: 25px; flex: 1; }
        .topbar-right { display: flex; align-items: center; gap: 30px; }
        
        .btn-back { color: #576574; text-decoration: none; font-weight: 500; display: flex; align-items: center; gap: 6px; font-size: 15px;}
        .btn-back:hover { color: <?= $cor_contexto ?>; }
        
        .user-menu { position: relative; display: inline-block; }
        .user-btn { background: none; border: none; font-size: 15px; font-weight: 600; color: #2c3e50; cursor: pointer; display: flex; align-items: center; gap: 8px; }
        .user-avatar { background: #e74c3c; color: white; width: 28px; height: 28px; border-radius: 50%; display: flex; justify-content: center; align-items: center; font-size: 14px; border: 2px solid #fadbd8; }
        
        .dropdown-content { display: none; position: absolute; right: 0; top: 100%; margin-top: 10px; background: #fff; min-width: 180px; box-shadow: 0 10px 30px rgba(0,0,0,0.1); border-radius: 8px; overflow: hidden; border: 1px solid #f1f2f6; z-index:200;}
        .dropdown-content a { color: #576574; padding: 12px 20px; text-decoration: none; display: block; font-size: 14px; }
        .dropdown-content a:hover { background: #fdf2f2; color: <?= $cor_contexto ?>; }
        .user-menu:hover .dropdown-content { display: block; }
        
        .container { max-width: 1300px; margin: 20px auto; padding: 0 15px; }
        
        .header-box { background: #fff; padding: 25px; border-radius: 12px; border: 1px solid #e1e5ea; display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px; border-left: 5px solid <?= $cor_contexto ?>; }
        .header-title { margin: 0; font-size: 20px; color: #2c3e50; }
        .ref-badge { background: <?= $cor_contexto ?>; color: #fff; padding: 4px 12px; border-radius: 6px; font-weight: 800; font-size: 16px; margin-left: 10px; }
        .header-subtitle { color: #7f8c8d; margin-top: 8px; font-size: 14px; font-weight: 600; }
        
        .btn-secondary { background: #fff; color: #576574; border: 1px solid #dcdde1; padding: 10px 20px; border-radius: 8px; text-decoration: none; font-weight: 600; display: inline-flex; align-items: center; gap: 8px; cursor: pointer; transition: all 0.2s; font-family: inherit;}
        .btn-secondary:hover { border-color: <?= $cor_contexto ?>; color: <?= $cor_contexto ?>; background: #fbf7fc; }
        
        .alert { padding: 15px; border-radius: 8px; margin-bottom: 20px; text-align: center; font-weight: 600; }
        .alert-erro { background: #fdeded; color: #e74c3c; border: 1px solid #f9cccc; }
        .alert-sucesso { background: #e8f5e9; color: #2ecc71; border: 1px solid #c8e6c9; }

        /* PAINEL FINANCEIRO DE EXECUÇÃO */
        .financial-panel { background: #fff; border-radius: 12px; border: 1px solid #e1e5ea; border-left: 5px solid #2ecc71; padding: 20px; margin-bottom: 25px; display: flex; gap: 20px; justify-content: space-between; align-items: center; box-shadow: 0 4px 10px rgba(0,0,0,0.02); flex-wrap:wrap;}
        .fin-block { display: flex; flex-direction: column; align-items: center; flex: 1; border-right: 1px solid #f1f2f6; min-width: 120px;}
        .fin-block:last-child { border-right: none; }
        .fin-label { font-size: 11px; font-weight: 600; color: #7f8c8d; text-transform: uppercase; margin-bottom: 8px; text-align: center; }
        .fin-value { font-size: 18px; font-weight: 700; color: #2c3e50; }
        .fin-total { font-size: 22px; font-weight: 800; color: #b32a2a; background: #fdf2f2; padding: 8px 15px; border-radius: 8px; border: 1px solid #fadbd8; }
        .input-fin { width: 80px; text-align: center; font-size: 16px; font-weight: bold; border: 1px solid #dcdde1; border-radius: 6px; padding: 4px; color: #2c3e50; }

        /* MODO TABELA LINHA A LINHA */
        .card { background: #fff; border-radius: 12px; border: 1px solid #e1e5ea; padding: 20px; box-shadow: 0 2px 10px rgba(0,0,0,0.02); margin-bottom: 20px; }
        .card-header-flex { display:flex; justify-content:space-between; align-items:center; margin-bottom: 15px; border-bottom: 1px solid #f1f2f6; padding-bottom: 10px;}
        .card-header-flex h2 { font-size: 16px; color: <?= $cor_contexto ?>; margin: 0; text-transform: uppercase; }
        
        .items-table { width: 100%; border-collapse: collapse; table-layout: fixed; }
        .items-table th { padding: 10px 8px; background: #f8f9fa; color: #576574; font-size: 11px; font-weight: 600; text-transform: uppercase; text-align: center; border-bottom: 2px solid #dee2e6; }
        .items-table td { padding: 6px 4px; border-bottom: 1px solid #f1f2f6; position: relative; vertical-align: middle; }
        
        .col-fase { width: 8ch; } 
        .col-nome { width: 18%; } 
        .col-dia { width: 8ch; } 
        .col-homens { width: 10ch; } 
        .col-equipa { width: 110px; } 
        .col-tarefa { width: 33%; }
        .col-tempo { width: 10ch; }
        .col-concluida { width: 8ch; text-align: center; }
        .col-del { width: 80px; text-align: center; } 
        
        .items-table input[type="text"], .items-table input[type="number"], .items-table select { width: 100%; box-sizing: border-box; padding: 8px; font-size: 13px; outline: none; transition: 0.2s; border: 1px solid #dcdde1; border-radius: 6px;}
        .items-table input[type="text"]:focus, .items-table input[type="number"]:focus, .items-table select:focus { border-color: <?= $cor_contexto ?>; }
        .items-table input[type="checkbox"] { width: 18px; height: 18px; accent-color: #27ae60; cursor: pointer; }

        .row-concluida input[type="text"] { text-decoration: line-through; color: #95a5a6; }
        .row-concluida { background-color: #f4fbf7; }

        .btn-add { background: <?= $cor_contexto ?>; color: #fff; border: none; padding: 8px 15px; border-radius: 6px; cursor: pointer; font-size: 12px; transition: filter 0.2s; display:inline-flex; align-items:center; gap:5px; font-weight:600;}
        .btn-add:hover { filter: brightness(0.9); }
        .btn-clear { background: #e74c3c; color: #fff; border: none; padding: 8px 15px; border-radius: 6px; cursor: pointer; font-size: 12px; transition: 0.2s; display:inline-flex; align-items:center; gap:5px; font-weight:600;}
        .btn-clear:hover { filter: brightness(0.9); }

        .btn-row-action { background: none; border: none; cursor: pointer; font-size: 15px; padding: 4px 6px; border-radius: 4px; transition: 0.2s; display: inline-flex; align-items: center; justify-content: center;}
        .btn-del-row { color: #e74c3c; }
        .btn-del-row:hover { background: #fdeded; }
        .btn-add-row { color: #2ecc71; margin-right: 2px;}
        .btn-add-row:hover { background: #e8f5e9; }

        /* BARRA FLUTUANTE DE GRAVAÇÃO */
        .floating-action-bar { position: fixed; bottom: 20px; right: 20px; left: 20px; max-width: 1300px; margin: 0 auto; background: #fff; padding: 15px 30px; border-radius: 12px; box-shadow: 0 -5px 20px rgba(0,0,0,0.15); display: flex; justify-content: flex-end; align-items: center; border: 1px solid #e1e5ea; z-index: 1000; }
        .btn-save { background: <?= $cor_contexto ?>; color: #fff; padding: 12px 30px; border-radius: 8px; border: none; font-weight: 700; cursor: pointer; font-size: 14px; text-transform: uppercase; transition: 0.2s;}
        .btn-save:hover { filter: brightness(0.9); transform: translateY(-2px);}

        .modal { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.6); z-index: 1001; justify-content: center; align-items: center; backdrop-filter: blur(2px); }
        .modal.show { display: flex; }
        .modal-content { background: #fff; padding: 30px; border-radius: 12px; width: 90%; max-width: 400px; text-align: center; box-shadow: 0 20px 50px rgba(0,0,0,0.2); }
        .btn-primary { background: <?= $cor_contexto ?>; color: #fff; padding: 12px 24px; border-radius: 8px; text-decoration: none; font-weight: 600; font-size: 14px; border: none; cursor: pointer; width: 100%; }

        @media print {
            body { background: #fff; padding: 0; }
            .topbar, .floating-action-bar, .card-header-flex .btn-add, .card-header-flex .btn-clear, .btn-secondary, .alert, .financial-panel, .col-del, .btn-row-action { display: none !important; }
            .container { margin: 0; max-width: 100%; padding: 0; }
            .header-box { border: none; padding: 0; margin-bottom: 20px; box-shadow: none; }
            input, select { border: none !important; background: transparent !important; padding: 0 !important; appearance: none; -webkit-appearance: none;}
        }

        @media (max-width: 768px) {
            .btn-back-text { display: none; }
            .header-box { flex-direction: column; align-items: flex-start; gap: 15px; }
            .financial-panel { flex-direction: column; gap: 15px; }
            .fin-block { border-right: none; border-bottom: 1px solid #f1f2f6; width: 100%; padding-bottom: 10px; }
            .fin-block:last-child { border-bottom: none; }
            .items-table { display: block; overflow-x: auto; white-space: nowrap; }
        }
    </style>
</head>
<body>

<header class="topbar">
    <div class="topbar-left"><a href="index.php"><img src="mab.png" alt="MAB" class="logo"></a></div>
    <div class="topbar-center"></div> <div class="topbar-right">
        <a href="<?= $orcamento_id ? 'orcamentos.php' : 'obras.php' ?>" class="btn-back" title="Voltar"><i class="fa-solid fa-arrow-left"></i> <span class="btn-back-text">Voltar</span></a>
        <div class="user-menu">
            <button class="user-btn">
                <div class="user-avatar"><i class="fa-solid fa-user"></i></div>
                <?= htmlspecialchars($primeiro_nome) ?> 
                <i class="fa-solid fa-caret-down" style="font-size: 12px; color: #95a5a6;"></i>
            </button>
            <div class="dropdown-content">
                <a href="perfil.php"><i class="fa-solid fa-id-badge" style="width:20px;"></i> O meu perfil</a>
                <?php if ($is_admin): ?>
                    <a href="configuracoes.php"><i class="fa-solid fa-gear" style="width:20px;"></i> Configurações</a>
                <?php endif; ?>
                <a href="index.php?logout=1" style="color: #e74c3c; border-top: 1px solid #f1f2f6;"><i class="fa-solid fa-right-from-bracket" style="width:20px;"></i> Sair</a>
            </div>
        </div>
    </div>
</header>

<div class="container">
    <?php if($mensagem): ?> <div class="alert alert-<?= $tipo_mensagem ?>"><?= $mensagem ?></div> <?php endif; ?>

    <div class="header-box">
        <div>
            <h1 class="header-title">Planeamento de Trabalhos <span class="ref-badge">REF: <?= htmlspecialchars($ref_contexto) ?></span></h1>
            <div class="header-subtitle"><?= htmlspecialchars($titulo_contexto) ?> - <?= htmlspecialchars($subtitulo_contexto) ?></div>
        </div>
        <div style="display:flex; gap:15px; align-items:center; flex-wrap: wrap;">
            <button type="button" class="btn-secondary" onclick="window.open('planeamento_pdf.php?<?= $orcamento_id ? 'orcamento_id='.$orcamento_id : 'obra_id='.$obra_id ?>', '_blank')"><i class="fa-solid fa-print"></i> Imprimir Plano</button>
            <i class="fa-solid fa-list-check" style="font-size: 30px; color: <?= $cor_contexto ?>; margin-left:10px;"></i>
        </div>
    </div>

    <?php if (!$is_obra): ?>
    <div class="financial-panel" id="financial_panel">
        <div class="fin-block">
            <span class="fin-label">Mão de Obra</span>
            <span class="fin-value" id="calc_mo">0,00 €</span>
        </div>
        <div class="fin-block">
            <span class="fin-label">Deslocações (<?= number_format($distancia_km, 1, ',', '') ?> km)</span>
            <span class="fin-value" id="calc_desl">0,00 €</span>
        </div>
        <div class="fin-block">
            <span class="fin-label">Refeições</span>
            <span class="fin-value" id="calc_ref">0,00 €</span>
        </div>
        <div class="fin-block">
            <span class="fin-label">Acessórios (Global)</span>
            <input type="number" step="0.01" class="input-fin" id="input_acessorios" value="<?= $valor_acessorios ?>" oninput="calcularTudo()">
        </div>
        <div class="fin-block" style="border:none;">
            <span class="fin-label">Custo Global (P/ Orçamento)</span>
            <span class="fin-total" id="calc_total">0,00 €</span>
        </div>
    </div>
    <?php endif; ?>

    <form method="POST" id="formPlaneamento">
        <input type="hidden" name="planeamento_json" id="planeamento_json">
        <input type="hidden" name="redirect_action" id="redirect_action" value="voltar">
        <input type="hidden" name="custo_total_input" id="hidden_custo_total" value="0">
        <input type="hidden" name="acessorios_input" id="hidden_acessorios" value="0">
        
        <div class="card">
            <div class="card-header-flex">
                <h2><i class="fa-solid fa-layer-group"></i> Linhas de Planeamento</h2>
                <div style="display:flex; gap:8px;">
                    <input type="file" id="csv_file_dias" accept=".csv" style="display:none" onchange="importarCSVDias(this)">
                    <button type="button" class="btn-add" style="background:#576574;" onclick="document.getElementById('csv_file_dias').click()">
                        <i class="fa-solid fa-file-csv"></i> Importar CSV
                    </button>
                    <button type="button" class="btn-clear" onclick="limparTudo()">
                        <i class="fa-solid fa-eraser"></i> Limpar Todas
                    </button>
                </div>
            </div>

            <div class="table-responsive">
                <table class="items-table" id="tabelaLinhas">
                    <thead>
                        <tr>
                            <th class="col-fase">Nº Fase</th>
                            <th class="col-nome align-left">Nome da Fase</th>
                            <th class="col-dia">Nº Dia</th>
                            <th class="col-homens">Nº Homens</th>
                            <th class="col-equipa">Equipa</th>
                            <th class="col-tarefa align-left">Descrição da Tarefa</th>
                            <th class="col-tempo">Tempo (h)</th>
                            <th class="col-concluida">Concluída</th>
                            <th class="col-del">Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                        </tbody>
                    <tfoot>
                        <tr>
                            <td colspan="9" style="border:none; padding-top:15px;">
                                <button type="button" class="btn-add" onclick="addRow()"><i class="fa-solid fa-plus"></i> Adicionar Nova Linha</button>
                            </td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>

        <div class="floating-action-bar">
            <div style="font-weight:600; color:#7f8c8d; font-size:12px; margin-right:auto;">
                <i class="fa-regular fa-clock"></i> Resumo: <span id="resumo_dias" style="color:#2c3e50;">0</span> Dias | <span id="resumo_horas" style="color:#2c3e50;">0</span> Horas
            </div>
            <button type="submit" id="btn_save_planeamento" class="btn-save"><i class="fa-solid fa-floppy-disk" style="margin-right:8px;"></i> GUARDAR ALTERAÇÕES</button>
        </div>
    </form>
</div>

<div class="modal" id="modalRedirect">
    <div class="modal-content">
        <h3 style="color:#2c3e50; margin-top:0;">Planeamento Guardado</h3>
        <p>Após guardar, o que deseja fazer?</p>
        <div style="display:flex; gap:10px; margin-top:20px;">
            <button type="button" onclick="confirmarRedirect('continuar')" style="flex:1; background:#f1f2f6; border:none; padding:12px; border-radius:8px; cursor:pointer; font-weight:600; color:#576574;">Continuar a editar</button>
            <button type="button" onclick="confirmarRedirect('voltar')" class="btn-primary" style="flex:1;">Concluir e Voltar</button>
        </div>
    </div>
</div>

<script>
    const isObra = <?= $is_obra ? 'true' : 'false' ?>;
    const custoHora = <?= json_encode($valor_hora) ?>;
    const custoPorKm = <?= json_encode($custo_por_km) ?>;
    const valorAlmoco = <?= json_encode($valor_almoco) ?>;
    const distanciaKm = <?= json_encode($distancia_km) ?>;
    const corContexto = '<?= $cor_contexto ?>';
    
    let isRedirectChosen = false;

    function getRowHTML(data = {}) {
        let isChecked = data.concluida ? 'checked' : '';
        let rowClass = data.concluida ? 'row-concluida' : '';
        let idDbAttr = data.id ? `data-id="${data.id}"` : '';

        let selEOF = data.tipo_equipa === 'EOF' ? 'selected' : '';
        let selEAJ = data.tipo_equipa === 'EAJ' ? 'selected' : '';
        let selAMBOS = (!data.tipo_equipa || data.tipo_equipa === 'AMBOS') ? 'selected' : '';

        return `
            <td class="col-fase"><input type="number" class="in-fase" value="${data.numero_fase || 1}" oninput="calcularTudo()"></td>
            <td class="col-nome"><input type="text" class="in-nome" value="${data.nome_fase || ''}"></td>
            <td class="col-dia"><input type="number" class="in-dia" value="${data.numero_dia || 1}" oninput="calcularTudo()"></td>
            <td class="col-homens"><input type="number" step="0.5" class="in-homens" value="${data.numero_homens || 1}" oninput="calcularTudo()"></td>
            <td class="col-equipa">
                <select class="in-equipa">
                    <option value="AMBOS" ${selAMBOS}>AMBOS</option>
                    <option value="EOF" ${selEOF}>EOF</option>
                    <option value="EAJ" ${selEAJ}>EAJ</option>
                </select>
            </td>
            <td class="col-tarefa"><input type="text" class="in-tarefa" value="${data.descricao_tarefa || ''}"></td>
            <td class="col-tempo"><input type="number" step="0.5" class="in-tempo" value="${data.tempo_tarefa || 8}" oninput="calcularTudo()"></td>
            <td class="col-concluida align-center">
                <input type="checkbox" class="in-concluida" ${isChecked} onchange="updateAjax(this, ${data.id || 'null'})" ${idDbAttr}>
            </td>
            <td class="col-del align-center">
                <button type="button" class="btn-row-action btn-add-row" onclick="insertRowAfter(this)"><i class="fa-solid fa-plus"></i></button>
                <button type="button" class="btn-row-action btn-del-row" onclick="this.closest('tr').remove(); calcularTudo();"><i class="fa-solid fa-trash-can"></i></button>
            </td>
        `;
    }

    function addRow(data = {}) {
        const tbody = document.querySelector('#tabelaLinhas tbody');
        const row = tbody.insertRow();
        row.className = data.concluida ? 'row-concluida' : '';
        row.innerHTML = getRowHTML(data);
        calcularTudo();
    }

    function insertRowAfter(btn) {
        const currentRow = btn.closest('tr');
        const tbody = currentRow.parentNode;
        const newRow = tbody.insertRow(currentRow.rowIndex);
        
        let nf = currentRow.querySelector('.in-fase').value;
        let nmf = currentRow.querySelector('.in-nome').value;
        let nd = currentRow.querySelector('.in-dia').value;
        let nh = currentRow.querySelector('.in-homens').value;
        let teq = currentRow.querySelector('.in-equipa').value;

        newRow.innerHTML = getRowHTML({ numero_fase: nf, nome_fase: nmf, numero_dia: nd, numero_homens: nh, tipo_equipa: teq });
        calcularTudo();
    }

    function limparTudo() {
        if (confirm("Tem a certeza absoluta que deseja apagar todas as linhas? Esta ação não pode ser desfeita.")) {
            document.querySelector('#tabelaLinhas tbody').innerHTML = '';
            addRow();
        }
    }

    function updateAjax(checkbox, idDb) {
        const row = checkbox.closest('tr');
        if(checkbox.checked) row.classList.add('row-concluida');
        else row.classList.remove('row-concluida');

        if(idDb !== null) {
            let formData = new FormData();
            formData.append('ajax_tarefa_id', idDb);
            formData.append('ajax_concluida', checkbox.checked ? 1 : 0);
            
            fetch('planeamento.php', {
                method: 'POST',
                body: formData
            }).then(r => r.json()).then(data => {
                if(!data.sucesso) alert('Erro ao atualizar: ' + data.erro);
            }).catch(e => console.error(e));
        }
    }

    function importarCSVDias(input) {
        const file = input.files[0];
        if (!file) return;
        const reader = new FileReader();
        reader.onload = function(e) {
            processarCSV(e.target.result);
            input.value = '';
        };
        reader.readAsText(file, 'ISO-8859-1');
    }

    function processarCSV(text) {
        const lines = text.split('\n');
        const tbody = document.querySelector('#tabelaLinhas tbody');
        
        const firstRow = tbody.querySelector('tr');
        let replaceFirst = false;
        if (firstRow && tbody.querySelectorAll('tr').length === 1) {
            if (firstRow.querySelector('.in-tarefa').value.trim() === '') replaceFirst = true;
        }

        if(replaceFirst) tbody.innerHTML = '';

        for (let i = 0; i < lines.length; i++) {
            let line = lines[i].trim();
            if (!line) continue;
            
            let separator = line.includes(';') ? ';' : ',';
            let cols = line.split(new RegExp(separator + '(?=(?:(?:[^"]*"){2})*[^"]*$)'));
            cols = cols.map(c => c.trim().replace(/^["']|["']$/g, ''));

            if (cols.length < 2) continue; 
            if (cols[0].toLowerCase().includes('fase')) continue; 

            let equipaCsv = (cols[6] ? cols[6].toUpperCase() : 'AMBOS');
            if (equipaCsv !== 'EOF' && equipaCsv !== 'EAJ') equipaCsv = 'AMBOS';

            addRow({
                numero_fase: parseInt(cols[0]) || 1,
                nome_fase: cols[1] || '',
                numero_dia: parseInt(cols[2]) || 1,
                numero_homens: parseFloat(cols[3] ? cols[3].replace(',','.') : 1) || 1,
                tipo_equipa: equipaCsv,
                descricao_tarefa: cols[4] || '',
                tempo_tarefa: parseFloat(cols[5] ? cols[5].replace(',','.') : 8) || 8,
                concluida: false
            });
        }
        calcularTudo();
    }

    function calcularTudo() {
        let totalHoras = 0;
        let homensPorDia = {}; 
        let diasUnicos = new Set();
        
        document.querySelectorAll('#tabelaLinhas tbody tr').forEach(row => {
            let dia = row.querySelector('.in-dia').value.trim();
            let homens = parseFloat(row.querySelector('.in-homens').value.replace(',','.')) || 0;
            let tempo = parseFloat(row.querySelector('.in-tempo').value.replace(',','.')) || 0;
            
            if (dia !== '') {
                diasUnicos.add(dia);
                totalHoras += (tempo * homens);
                
                if(!homensPorDia[dia] || homens > homensPorDia[dia]) {
                    homensPorDia[dia] = homens;
                }
            }
        });

        let totalDias = diasUnicos.size;
        let totalHomensMaximos = Object.values(homensPorDia).reduce((a, b) => a + b, 0);
        
        document.getElementById('resumo_dias').innerText = totalDias;
        document.getElementById('resumo_horas').innerText = totalHoras.toFixed(1);
        
        if (!isObra && document.getElementById('financial_panel')) {
            let custoMO = totalHoras * custoHora;
            let custoDesl = totalDias * distanciaKm * custoPorKm;
            let custoRef = totalHomensMaximos * valorAlmoco;
            let valAcessórios = parseFloat(document.getElementById('input_acessorios').value) || 0;
            
            let custoTotalInjetar = custoMO + custoDesl + custoRef + valAcessórios;
            
            document.getElementById('calc_mo').innerText = custoMO.toLocaleString('pt-PT', {minimumFractionDigits: 2}) + ' €';
            document.getElementById('calc_desl').innerText = custoDesl.toLocaleString('pt-PT', {minimumFractionDigits: 2}) + ' €';
            document.getElementById('calc_ref').innerText = custoRef.toLocaleString('pt-PT', {minimumFractionDigits: 2}) + ' €';
            document.getElementById('calc_total').innerText = custoTotalInjetar.toLocaleString('pt-PT', {minimumFractionDigits: 2}) + ' €';
            
            document.getElementById('hidden_custo_total').value = custoTotalInjetar;
            document.getElementById('hidden_acessorios').value = valAcessórios;
        }
    }

    function prepararEnvio() {
        let linhas = [];
        document.querySelectorAll('#tabelaLinhas tbody tr').forEach(row => {
            let tarefa = row.querySelector('.in-tarefa').value.trim();
            if (tarefa !== '') {
                linhas.push({
                    id: row.querySelector('.in-concluida').getAttribute('data-id'),
                    numero_fase: row.querySelector('.in-fase').value,
                    nome_fase: row.querySelector('.in-nome').value,
                    numero_dia: row.querySelector('.in-dia').value,
                    numero_homens: row.querySelector('.in-homens').value,
                    tipo_equipa: row.querySelector('.in-equipa').value,
                    descricao_tarefa: tarefa,
                    tempo_tarefa: row.querySelector('.in-tempo').value,
                    concluida: row.querySelector('.in-concluida').checked
                });
            }
        });
        document.getElementById('planeamento_json').value = JSON.stringify(linhas);
    }

    document.getElementById('formPlaneamento').onsubmit = function(e) {
        if (!isRedirectChosen) {
            e.preventDefault();
            document.getElementById('modalRedirect').classList.add('show');
            return;
        }
        prepararEnvio();
    };

    function confirmarRedirect(escolha) {
        document.getElementById('redirect_action').value = escolha;
        isRedirectChosen = true; 
        document.getElementById('modalRedirect').classList.remove('show');
        prepararEnvio();
        document.getElementById('formPlaneamento').submit(); 
    }

    window.addEventListener('DOMContentLoaded', () => {
        let dbData = <?= $json_planeamento_init ?>;
        
        if(dbData && dbData.length > 0) {
            dbData.forEach(linhaDB => {
                addRow({
                    id: linhaDB.id,
                    numero_fase: linhaDB.numero_fase,
                    nome_fase: linhaDB.nome_fase,
                    numero_dia: linhaDB.numero_dia,
                    numero_homens: linhaDB.numero_homens,
                    tipo_equipa: linhaDB.tipo_equipa || 'AMBOS',
                    descricao_tarefa: linhaDB.descricao_tarefa,
                    tempo_tarefa: linhaDB.tempo_tarefa,
                    concluida: linhaDB.concluida
                });
            });
        }
        
        if(document.querySelector('#tabelaLinhas tbody').children.length === 0) {
            addRow(); 
        }
        
        calcularTudo();
    });
</script>

</body>
</html>