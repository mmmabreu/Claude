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

// Mensagem de sucesso
if (isset($_GET['editado']) && $_GET['editado'] == 1) {
    $mensagem = "Planeamento guardado com sucesso!";
    $tipo_mensagem = "sucesso";
}

// Buscar Custos de Configuração
$custo_hora_mao_obra = 0;
$custo_por_km = 0;
$valor_almoco = 0;

if (!$is_obra) {
    try {
        $stmt_ch = $pdo->prepare("SELECT valor FROM configuracoes WHERE chave = 'custo_hora_mao_obra' LIMIT 1");
        $stmt_ch->execute();
        $valor_ch = $stmt_ch->fetchColumn();
        if ($valor_ch !== false) $custo_hora_mao_obra = (float)str_replace(',', '.', $valor_ch);
        
        $stmt_ckm = $pdo->prepare("SELECT valor FROM configuracoes WHERE chave = 'custo_km' LIMIT 1");
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
$subtitulo_contexto = "";
$distancia_km = 0;
$valor_acessorios = 0;
$cor_contexto = $is_obra ? "#2ecc71" : "#f39c12"; // Verde Obras, Laranja Orçamentos

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
    $titulo_contexto = "Orçamento #" . $pai['numero_orcamento'];
    $subtitulo_contexto = $pai['nome_obra'];
    $distancia_km = (float)str_replace(',', '.', $pai['km'] ?? '0');
    
} else {
    $stmt = $pdo->prepare("SELECT nome, referencia_obra, km FROM obras WHERE id = ?");
    $stmt->execute([$obra_id]);
    $pai = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$pai) die("Obra não encontrada.");
    $titulo_contexto = "Obra: " . $pai['nome'];
    $subtitulo_contexto = $pai['referencia_obra'];
    $distancia_km = (float)str_replace(',', '.', $pai['km'] ?? '0');
}

// 2. PROCESSAR GRAVAÇÃO (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $pdo->beginTransaction();

        $dados_json = $_POST['planeamento_json'] ?? '[]';
        $dias = json_decode($dados_json, true);
        
        $custo_total_injetar = (float)($_POST['custo_total_input'] ?? 0);
        $acessorios_input = (float)($_POST['acessorios_input'] ?? 0);

        // Limpeza Total dos dias antigos e tarefas
        if ($orcamento_id) {
            $pdo->prepare("DELETE t FROM planeamento_tarefas t INNER JOIN planeamento_dias d ON t.planeamento_dia_id = d.id WHERE d.orcamento_id = ?")->execute([$orcamento_id]);
            $pdo->prepare("DELETE FROM planeamento_dias WHERE orcamento_id = ?")->execute([$orcamento_id]);
        } else {
            $pdo->prepare("DELETE t FROM planeamento_tarefas t INNER JOIN planeamento_dias d ON t.planeamento_dia_id = d.id WHERE d.obra_id = ?")->execute([$obra_id]);
            $pdo->prepare("DELETE FROM planeamento_dias WHERE obra_id = ?")->execute([$obra_id]);
        }

        try {
            $check = $pdo->query("SELECT concluida FROM planeamento_tarefas LIMIT 1");
            $sql_tarefa = "INSERT INTO planeamento_tarefas (planeamento_dia_id, descricao_tarefa, concluida) VALUES (?, ?, ?)";
            $has_concluida = true;
        } catch(Exception $e) {
            $sql_tarefa = "INSERT INTO planeamento_tarefas (planeamento_dia_id, descricao_tarefa) VALUES (?, ?)";
            $has_concluida = false;
        }

        $sql_dia = "INSERT INTO planeamento_dias (orcamento_id, obra_id, numero_fase, nome_fase, numero_dia, numero_homens) VALUES (?, ?, ?, ?, ?, ?)";
        $stmt_dia = $pdo->prepare($sql_dia);
        $stmt_tarefa = $pdo->prepare($sql_tarefa);

        foreach ($dias as $dia) {
            $n_fase = (int)($dia['numero_fase'] ?? 1);
            $nome_fase = trim($dia['nome_fase'] ?? 'Fase');
            $n_dia = (int)($dia['numero_dia'] ?? 1);
            $n_homens = (float)str_replace(',', '.', $dia['numero_homens'] ?? 1);

            $stmt_dia->execute([$orcamento_id, $obra_id, $n_fase, $nome_fase, $n_dia, $n_homens]);
            $novo_dia_id = $pdo->lastInsertId();

            if (isset($dia['tarefas']) && is_array($dia['tarefas'])) {
                foreach ($dia['tarefas'] as $tarefa) {
                    $texto_tarefa = trim($tarefa['descricao'] ?? '');
                    $concluida = isset($tarefa['concluida']) && $tarefa['concluida'] ? 1 : 0;
                    
                    if ($texto_tarefa !== '') {
                        if ($has_concluida) {
                            $stmt_tarefa->execute([$novo_dia_id, $texto_tarefa, $concluida]);
                        } else {
                            $stmt_tarefa->execute([$novo_dia_id, $texto_tarefa]);
                        }
                    }
                }
            }
        }
        
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
        registrarLog($pdo, "Atualizou Planeamento de " . ($orcamento_id ? "Orçamento #$orcamento_id" : "Obra #$obra_id"), "Planeamento");

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
        $mensagem = "Erro ao guardar planeamento: " . $e->getMessage();
        $tipo_mensagem = "erro";
    }
}

// 3. CARREGAR DADOS EXISTENTES
$planeamento_atual = [];
$coluna_filtro = $orcamento_id ? 'orcamento_id' : 'obra_id';
$id_filtro = $orcamento_id ?: $obra_id;

$stmt_dias = $pdo->prepare("SELECT * FROM planeamento_dias WHERE $coluna_filtro = ? ORDER BY numero_fase ASC, numero_dia ASC");
$stmt_dias->execute([$id_filtro]);
$dias_db = $stmt_dias->fetchAll(PDO::FETCH_ASSOC);

if ($dias_db) {
    $dias_ids = array_column($dias_db, 'id');
    $in = str_repeat('?,', count($dias_ids) - 1) . '?';
    
    $stmt_tarefas = $pdo->prepare("SELECT * FROM planeamento_tarefas WHERE planeamento_dia_id IN ($in) ORDER BY id ASC");
    $stmt_tarefas->execute($dias_ids);
    $tarefas_db = $stmt_tarefas->fetchAll(PDO::FETCH_ASSOC);

    $tarefas_agrupadas = [];
    foreach ($tarefas_db as $t) {
        $tarefas_agrupadas[$t['planeamento_dia_id']][] = [
            'descricao' => $t['descricao_tarefa'],
            'concluida' => isset($t['concluida']) ? (int)$t['concluida'] === 1 : false
        ];
    }

    foreach ($dias_db as $d) {
        $planeamento_atual[] = [
            'numero_fase' => $d['numero_fase'],
            'nome_fase' => $d['nome_fase'],
            'numero_dia' => $d['numero_dia'],
            'numero_homens' => $d['numero_homens'],
            'tarefas' => $tarefas_agrupadas[$d['id']] ?? []
        ];
    }
}

$json_planeamento_init = json_encode($planeamento_atual, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE);
$json_custo_hora = json_encode($custo_hora_mao_obra);
$json_custo_km = json_encode($custo_por_km);
$json_distancia = json_encode($distancia_km);
$json_valor_almoco = json_encode($valor_almoco);
$json_valor_acessorios = json_encode($valor_acessorios);
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
        
        .topbar { background-color: #fff; padding: 10px 40px; box-shadow: 0 2px 15px rgba(0,0,0,0.05); display: flex; justify-content: space-between; align-items: center; position: sticky; top: 0; z-index: 100; }
        .topbar-left .logo { height: 35px; }
        .topbar-right { display: flex; align-items: center; gap: 20px; }
        .btn-back { color: #576574; text-decoration: none; font-size: 18px; transition: color 0.2s; }
        .btn-back:hover { color: <?= $cor_contexto ?>; }
        
        .user-menu { position: relative; }
        .user-btn { background: #fdfdfd; border: 1px solid #f1f2f6; border-radius: 30px; font-size: 14px; font-weight: 600; color: #2c3e50; cursor: pointer; display: flex; align-items: center; gap: 10px; padding: 6px 15px 6px 6px; transition: 0.2s; }
        .user-btn:hover { background: #fff; box-shadow: 0 4px 10px rgba(0,0,0,0.05); }
        .user-avatar { background: #e74c3c; color: white; width: 28px; height: 28px; border-radius: 50%; display: flex; justify-content: center; align-items: center; font-size: 14px; border: 2px solid #fadbd8; }
        
        .dropdown-content { display: none; position: absolute; right: 0; top: 100%; background: #fff; min-width: 180px; box-shadow: 0 10px 30px rgba(0,0,0,0.1); border-radius: 8px; overflow: hidden; margin-top: 5px; z-index: 200;}
        .user-menu:hover .dropdown-content { display: block; }
        .dropdown-content a { color: #576574; padding: 12px 20px; text-decoration: none; display: block; font-size: 14px; }
        .dropdown-content a:hover { background: #fdf2f2; color: <?= $cor_contexto ?>; }
        
        .container { max-width: 1100px; margin: 20px auto; padding: 0 15px; }
        
        .header-box { background: #fff; padding: 25px; border-radius: 12px; border: 1px solid #e1e5ea; display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px; border-left: 5px solid <?= $cor_contexto ?>; }
        .header-title { margin: 0; font-size: 20px; color: #2c3e50; }
        .header-subtitle { color: #7f8c8d; margin-top: 5px; font-size: 14px; }
        
        .btn-secondary { background: #fff; color: #576574; border: 1px solid #dcdde1; padding: 10px 20px; border-radius: 8px; text-decoration: none; font-weight: 600; display: inline-flex; align-items: center; gap: 8px; cursor: pointer; transition: all 0.2s; }
        .btn-secondary:hover { border-color: <?= $cor_contexto ?>; color: <?= $cor_contexto ?>; background: #fbf7fc; }
        
        .btn-print { color: #3498db !important; border-color: #dcdde1; }
        .btn-print:hover { border-color: #3498db !important; background: #f0f8ff !important; }

        .alert { padding: 15px; border-radius: 8px; margin-bottom: 20px; text-align: center; font-weight: 600; }
        .alert-erro { background: #fdeded; color: #e74c3c; border: 1px solid #f9cccc; }
        .alert-sucesso { background: #e8f5e9; color: #2ecc71; border: 1px solid #c8e6c9; }

        /* VISUALIZAÇÃO EM LISTA (TABELA) */
        .card { background: #fff; border-radius: 12px; border: 1px solid #e1e5ea; box-shadow: 0 2px 10px rgba(0,0,0,0.02); overflow: hidden; margin-bottom: 20px; }
        .table-responsive { width: 100%; overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; min-width: 800px; }
        th { background: #f8f9fa; padding: 14px 15px; text-align: left; font-size: 11px; text-transform: uppercase; color: #576574; border-bottom: 2px solid #e1e5ea; }
        td { padding: 15px; border-bottom: 1px solid #f1f2f6; color: #2c3e50; vertical-align: top; }
        tr.tr-planeamento:hover { background: #fcfcfc; }
        
        /* LINHA DE SEPARADOR DE FASE ( Mara: para não repetir Fase 1, Fase 2...) */
        tr.phase-header-row td { 
            background-color: <?= $cor_contexto ?>10; 
            color: <?= $cor_contexto ?>; 
            font-weight: 700; 
            text-transform: uppercase; 
            font-size: 12px; 
            padding: 10px 15px; 
            border-bottom: 1px solid <?= $cor_contexto ?>20; 
            vertical-align: middle;
        }

        .badge-equipa { background: #f1f2f6; color: #576574; padding: 4px 10px; border-radius: 20px; font-size: 12px; font-weight: 600; display: inline-flex; align-items: center; gap: 5px; }
        .badge-status { font-weight: 700; font-size: 11px; padding: 4px 8px; border-radius: 6px; display: inline-block; text-transform: uppercase; }
        
        .task-list { list-style: none; padding: 0; margin: 0; display: flex; flex-direction: column; gap: 8px; }
        .task-list li { display: flex; align-items: flex-start; gap: 8px; font-size: 14px; line-height: 1.4; }
        .task-list i { margin-top: 3px; }
        .text-strike { text-decoration: line-through; color: #95a5a6; }

        /* MODO EDIÇÃO */
        .dia-card { background: #fff; border-radius: 12px; border: 1px solid #e1e5ea; padding: 25px; margin-bottom: 20px; box-shadow: 0 4px 10px rgba(0,0,0,0.02); position: relative; }
        .dia-header { display: flex; gap: 15px; border-bottom: 1px solid #f1f2f6; padding-bottom: 20px; margin-bottom: 20px; }
        .form-group { display: flex; flex-direction: column; }
        .form-group label { font-weight: 600; font-size: 11px; color: #576574; text-transform: uppercase; margin-bottom: 6px; }
        .form-group input { padding: 10px 12px; border: 1px solid #dcdde1; border-radius: 8px; transition: 0.2s; font-size: 14px; }
        .form-group input:focus { border-color: <?= $cor_contexto ?>; }
        
        .tarefas-container { display: flex; flex-direction: column; gap: 10px; }
        .tarefa-row { display: flex; gap: 10px; align-items: center; background: #fdfdfd; border: 1px solid #e1e5ea; padding: 8px 12px; border-radius: 8px; transition: 0.2s; }
        .tarefa-row input[type="text"] { flex: 1; padding: 8px; border: none; background: transparent; outline: none; font-size: 14px; }
        .check-concluida { width: 18px; height: 18px; cursor: pointer; accent-color: #27ae60; }
        .is-concluida input[type="text"] { text-decoration: line-through; color: #95a5a6; }
        .is-concluida { background: #f4fbf7; border-color: #d5eedd; }

        .btn { padding: 10px 20px; border-radius: 8px; border: none; font-weight: 600; cursor: pointer; transition: 0.2s; display: inline-flex; align-items: center; gap: 8px; font-size: 14px; }
        .btn-add-dia { background: #f1f2f6; color: #576574; width: 100%; justify-content: center; padding: 15px; border: 1px dashed #bdc3c7; }
        .btn-add-dia:hover { background: <?= $cor_contexto ?>10; color: <?= $cor_contexto ?>; border-color: <?= $cor_contexto ?>; }
        .btn-add-tarefa { background: #f1f2f6; color: #576574; font-size: 12px; align-self: flex-start; padding: 8px 15px; }
        .btn-add-tarefa:hover { background: #e1e5ea; }
        
        .btn-delete { color: #e74c3c; background: none; border: none; cursor: pointer; font-size: 16px; padding: 5px; }
        .btn-delete:hover { color: #c0392b; transform: scale(1.1); }
        .btn-delete-dia { position: absolute; top: 20px; right: 20px; background: #fdeded; border-radius: 50%; width: 32px; height: 32px; display: flex; align-items: center; justify-content: center; }

        /* PAINEL FINANCEIRO DE EXECUÇÃO */
        .financial-panel { background: #fff; border-radius: 12px; border: 1px solid #e1e5ea; border-left: 5px solid #2ecc71; padding: 20px; margin-bottom: 25px; display: flex; gap: 20px; justify-content: space-between; align-items: center; box-shadow: 0 4px 10px rgba(0,0,0,0.02); }
        .fin-block { display: flex; flex-direction: column; align-items: center; flex: 1; border-right: 1px solid #f1f2f6; }
        .fin-block:last-child { border-right: none; }
        .fin-label { font-size: 11px; font-weight: 600; color: #7f8c8d; text-transform: uppercase; margin-bottom: 8px; text-align: center; }
        .fin-value { font-size: 18px; font-weight: 700; color: #2c3e50; }
        .fin-total { font-size: 22px; font-weight: 800; color: #b32a2a; background: #fdf2f2; padding: 8px 15px; border-radius: 8px; border: 1px solid #fadbd8; }
        .input-fin { width: 80px; text-align: center; font-size: 16px; font-weight: bold; border: 1px solid #dcdde1; border-radius: 6px; padding: 4px; color: #2c3e50; }

        /* BARRA FLUTUANTE DE GRAVAÇÃO */
        .floating-action-bar { position: fixed; bottom: 20px; right: 20px; left: 20px; max-width: 1000px; margin: 0 auto; background: #fff; padding: 15px 30px; border-radius: 12px; box-shadow: 0 -5px 20px rgba(0,0,0,0.15); display: flex; justify-content: flex-end; align-items: center; border: 1px solid #e1e5ea; z-index: 1000; }
        .btn-save { background: #2c3e50; color: #fff; padding: 12px 30px; border-radius: 8px; border: none; font-weight: 700; cursor: pointer; font-size: 14px; text-transform: uppercase; transition: 0.2s;}
        .btn-save:hover { background: #1a252f; }

        .modal { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.6); z-index: 1001; justify-content: center; align-items: center; backdrop-filter: blur(2px); }
        .modal.show { display: flex; }
        .modal-content { background: #fff; padding: 30px; border-radius: 12px; width: 90%; max-width: 400px; text-align: center; box-shadow: 0 20px 50px rgba(0,0,0,0.2); }
        .btn-primary { background: <?= $cor_contexto ?>; color: #fff; padding: 12px 24px; border-radius: 8px; text-decoration: none; font-weight: 600; font-size: 14px; border: none; cursor: pointer; width: 100%; }

        @media print {
            body { background: #fff; padding: 0; }
            .topbar, .floating-action-bar, .btn-add-dia, #btn_edit_mode, #btn_view_mode, .btn-print, .alert, .financial-panel { display: none !important; }
            .container { margin: 0; max-width: 100%; padding: 0; }
            .header-box { border: none; padding: 0; margin-bottom: 20px; box-shadow: none; }
        }

        @media (max-width: 768px) {
            .topbar-center { display: none; }
            .header-box { flex-direction: column; align-items: flex-start; gap: 15px; }
            .financial-panel { flex-direction: column; gap: 15px; }
            .fin-block { border-right: none; border-bottom: 1px solid #f1f2f6; width: 100%; padding-bottom: 10px; }
            .fin-block:last-child { border-bottom: none; }
            .col-pc { display: none; }
        }
    </style>
</head>
<body>

<header class="topbar">
    <div class="topbar-left"><a href="index.php"><img src="mab.png" alt="MAB" class="logo"></a></div>
    <div class="topbar-right">
        <a href="<?= $orcamento_id ? 'orcamentos.php' : 'obras.php' ?>" class="btn-back" title="Voltar"><i class="fa-solid fa-arrow-left"></i></a>
        <div class="user-menu">
            <button class="user-btn">
                <div class="user-avatar"><i class="fa-solid fa-user"></i></div>
                <?= htmlspecialchars($primeiro_nome) ?> <i class="fa-solid fa-chevron-down" style="font-size: 12px; color: #95a5a6;"></i>
            </button>
            <div class="dropdown-content">
                <a href="perfil.php"><i class="fa-solid fa-id-badge" style="width:20px;"></i> Perfil</a>
                <a href="index.php?logout=1" style="color: #e74c3c; border-top: 1px solid #f1f2f6;"><i class="fa-solid fa-right-from-bracket" style="width:20px;"></i> Sair</a>
            </div>
        </div>
    </div>
</header>

<div class="container">
    <?php if($mensagem): ?> <div class="alert alert-<?= $tipo_mensagem ?>"><?= $mensagem ?></div> <?php endif; ?>

    <div class="header-box">
        <div>
            <h1 class="header-title">Planeamento de Trabalhos</h1>
            <div class="header-subtitle"><?= htmlspecialchars($titulo_contexto) ?> - <?= htmlspecialchars($subtitulo_contexto) ?></div>
        </div>
        <div style="display:flex; gap:15px; align-items:center; flex-wrap: wrap;">
            <button type="button" class="btn-secondary btn-print" onclick="abrirImpressao()"><i class="fa-solid fa-print"></i> Imprimir</button>
            
            <button type="button" id="btn_edit_mode" class="btn-secondary" onclick="toggleMode('edit')" style="display:none;"><i class="fa-solid fa-pen"></i> Editar Planeamento</button>
            <button type="button" id="btn_view_mode" class="btn-secondary" onclick="toggleMode('view')" style="display:none;"><i class="fa-solid fa-list-ul"></i> Ver em Lista</button>
            <i class="fa-solid fa-list-check" style="font-size: 30px; color: <?= $cor_contexto ?>; margin-left:10px;"></i>
        </div>
    </div>

    <?php if (!$is_obra): ?>
    <div class="financial-panel" id="financial_panel" style="display: none;">
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
            <input type="number" step="0.01" class="input-fin" id="input_acessorios" value="<?= $valor_acessorios ?>" oninput="updateResumo()">
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
        
        <div id="dias_container"></div>

        <button type="button" id="btn_add_dia" class="btn btn-add-dia" onclick="adicionarDia()"><i class="fa-solid fa-plus"></i> Adicionar Bloco de Trabalho</button>

        <div class="floating-action-bar" id="floating_bar">
            <div style="font-weight:600; color:#7f8c8d; font-size:12px; margin-right:auto;">
                <i class="fa-regular fa-clock"></i> Resumo: <span id="resumo_dias" style="color:#2c3e50;">0</span> Dias | <span id="resumo_horas" style="color:#2c3e50;">0</span> Horas
            </div>
            <button type="submit" id="btn_save_planeamento" class="btn-save"><i class="fa-solid fa-floppy-disk"></i> GUARDAR ALTERAÇÕES</button>
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
    const custoHora = <?= $json_custo_hora ?>;
    const custoPorKm = <?= $json_custo_km ?>;
    const valorAlmoco = <?= $json_valor_almoco ?>;
    const distanciaKm = <?= $json_distancia ?>;
    const corContexto = '<?= $cor_contexto ?>';
    
    let planeamento = <?= $json_planeamento_init ?: '[]' ?>;
    let currentMode = planeamento.length > 0 ? 'view' : 'edit';
    let isRedirectChosen = false;

    if (planeamento.length === 0) {
        planeamento.push(criarNovoDia());
    }

    function toggleMode(mode) {
        currentMode = mode;
        render();
    }

    function abrirImpressao() {
        const urlParams = new URLSearchParams(window.location.search);
        const orc_id = urlParams.get('orcamento_id');
        const obra_id = urlParams.get('obra_id');
        
        let url = 'planeamento_pdf.php?';
        if (orc_id) url += 'orcamento_id=' + orc_id;
        if (obra_id) url += 'obra_id=' + obra_id;
        
        window.open(url, '_blank');
    }

    function criarNovoDia() {
        return {
            numero_fase: 1,
            nome_fase: '',
            numero_dia: 1,
            numero_homens: 1,
            tarefas: [{ descricao: '', concluida: false }]
        };
    }

    function updateResumo() {
        let totalDias = planeamento.length;
        let totalHomensDia = 0;
        
        planeamento.forEach(dia => {
            totalHomensDia += (parseFloat(dia.numero_homens) || 0);
        });

        let totalHoras = totalHomensDia * 8; 
        
        document.getElementById('resumo_dias').innerText = totalDias;
        document.getElementById('resumo_horas').innerText = totalHoras;
        
        if (!isObra && document.getElementById('financial_panel')) {
            let custoMO = totalHoras * custoHora;
            let custoDesl = totalDias * distanciaKm * custoPorKm;
            let custoRef = totalHomensDia * valorAlmoco;
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

    function render() {
        if (currentMode === 'view') {
            document.getElementById('btn_edit_mode').style.display = 'inline-flex';
            document.getElementById('btn_view_mode').style.display = 'none';
            document.getElementById('btn_add_dia').style.display = 'none';
            document.getElementById('floating_bar').style.display = 'none';
            if (!isObra && document.getElementById('financial_panel')) document.getElementById('financial_panel').style.display = 'none';
            renderViewModeTabela();
        } else {
            document.getElementById('btn_edit_mode').style.display = 'none';
            document.getElementById('btn_view_mode').style.display = planeamento.length > 0 ? 'inline-flex' : 'none';
            document.getElementById('btn_add_dia').style.display = 'flex';
            document.getElementById('floating_bar').style.display = 'flex';
            if (!isObra && document.getElementById('financial_panel')) document.getElementById('financial_panel').style.display = 'flex';
            renderEditMode();
        }
        updateResumo();
    }

    // REGRA DE OURO: Visualização em Lista Mara (Sem repetir Fase X repetidamente)
    function renderViewModeTabela() {
        const container = document.getElementById('dias_container');
        if (planeamento.length === 0) {
            container.innerHTML = '<div class="card" style="text-align:center; padding: 40px; color:#7f8c8d;">Sem planeamento definido.</div>';
            return;
        }

        let HTML = `
        <div class="card">
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th style="width: 80px; text-align: center;">Dia</th>
                            <th style="width: 120px; text-align: center;">Equipa</th>
                            <th>Trabalhos a Realizar</th>
                            <th style="width: 120px; text-align: center;">Estado</th>
                        </tr>
                    </thead>
                    <tbody>
        `;

        let currentFase = null; // Variável de controlo para agrupar fases

        planeamento.forEach((dia) => {
            
            // LÓGICA MARA: Verificar se a fase mudou para inserir o separador
            if (dia.numero_fase !== currentFase) {
                let safeFase = dia.nome_fase ? '- ' + String(dia.nome_fase).replace(/</g, '&lt;') : '';
                HTML += `
                    <tr class="phase-header-row">
                        <td colspan="4">Fase ${dia.numero_fase} ${safeFase}</td>
                    </tr>
                `;
                currentFase = dia.numero_fase; // Atualizar a fase atual
            }

            // Gerar lista de tarefas
            let totalT = 0; let concluidasT = 0;
            let tarefasHTML = '<ul class="task-list">';
            let hasTasks = false;

            dia.tarefas.forEach(t => {
                let safeDesc = t.descricao ? String(t.descricao).replace(/</g, '&lt;') : '';
                if(safeDesc === '') return;
                hasTasks = true;
                totalT++;
                if(t.concluida) concluidasT++;

                let icon = t.concluida 
                    ? '<i class="fa-solid fa-circle-check" style="color:#27ae60; font-size:14px;"></i>' 
                    : '<i class="fa-regular fa-circle" style="color:#bdc3c7; font-size:14px;"></i>';
                let textClass = t.concluida ? 'text-strike' : '';
                
                tarefasHTML += `<li class="${textClass}">${icon} <span>${safeDesc}</span></li>`;
            });
            tarefasHTML += '</ul>';

            if(!hasTasks) tarefasHTML = `<span style="color:#95a5a6; font-style:italic;">Sem tarefas descritas.</span>`;

            // Badge de Estado (% Conclusão)
            let estadoBadge = '';
            if (totalT === 0) {
                estadoBadge = `<span class="badge-status" style="background:#f1f2f6; color:#576574;">Pendente</span>`;
            } else if (concluidasT === 0) {
                estadoBadge = `<span class="badge-status" style="background:#fdeded; color:#e74c3c;">0%</span>`;
            } else if (concluidasT === totalT) {
                estadoBadge = `<span class="badge-status" style="background:#e8f5e9; color:#27ae60;"><i class="fa-solid fa-check"></i> 100%</span>`;
            } else {
                let perc = Math.round((concluidasT / totalT) * 100);
                estadoBadge = `<span class="badge-status" style="background:#fcf3cf; color:#f39c12;">${perc}%</span>`;
            }

            // Linha normal de dados (sem a coluna da fase)
            HTML += `
                <tr class="tr-planeamento">
                    <td style="text-align: center; font-size: 16px; font-weight: 800; color: ${corContexto}; padding-top:20px;">${dia.numero_dia}</td>
                    <td style="text-align: center; padding-top:20px;"><span class="badge-equipa"><i class="fa-solid fa-users"></i> ${dia.numero_homens}</span></td>
                    <td style="padding-top:20px;">${tarefasHTML}</td>
                    <td style="text-align: center; padding-top:20px;">${estadoBadge}</td>
                </tr>
            `;
        });

        HTML += `</tbody></table></div></div>`;
        container.innerHTML = HTML;
    }

    function renderEditMode() {
        const container = document.getElementById('dias_container');
        container.innerHTML = '';
        
        planeamento.forEach((dia, dIndex) => {
            let HTML = `
                <div class="dia-card">
                    <button type="button" class="btn-delete btn-delete-dia" onclick="removerDia(${dIndex})" title="Remover Dia"><i class="fa-solid fa-xmark"></i></button>
                    
                    <div class="dia-header">
                        <div class="form-group" style="width: 80px;">
                            <label>Nº Fase</label>
                            <input type="number" value="${dia.numero_fase}" oninput="updateDia(${dIndex}, 'numero_fase', this.value)">
                        </div>
                        <div class="form-group" style="flex: 1;">
                            <label>Nome da Fase / Zona</label>
                            <input type="text" value="${dia.nome_fase}" placeholder="Ex: Pichelaria Piso 1" oninput="updateDia(${dIndex}, 'nome_fase', this.value)">
                        </div>
                        <div class="form-group" style="width: 80px;">
                            <label>Nº Dia</label>
                            <input type="number" value="${dia.numero_dia}" oninput="updateDia(${dIndex}, 'numero_dia', this.value)">
                        </div>
                        <div class="form-group" style="width: 100px;">
                            <label>Nº Homens</label>
                            <input type="number" step="0.5" value="${dia.numero_homens}" oninput="updateDia(${dIndex}, 'numero_homens', this.value)">
                        </div>
                    </div>

                    <div class="form-group">
                        <label><i class="fa-solid fa-list-check"></i> Trabalhos a Realizar neste Dia</label>
                        <div class="tarefas-container">
            `;

            dia.tarefas.forEach((tarefa, tIndex) => {
                let safeDesc = tarefa.descricao ? String(tarefa.descricao).replace(/"/g, '&quot;') : '';
                let isChecked = tarefa.concluida ? 'checked' : '';
                let classConcluida = tarefa.concluida ? 'is-concluida' : '';
                
                let checkboxHTML = isObra ? 
                    `<input type="checkbox" class="check-concluida" ${isChecked} onchange="updateTarefaStatus(${dIndex}, ${tIndex}, this.checked)" title="Marcar como concluída">` 
                    : `<i class="fa-solid fa-caret-right" style="color:#dcdde1; padding: 0 5px;"></i>`;

                HTML += `
                    <div class="tarefa-row ${classConcluida}">
                        ${checkboxHTML}
                        <input type="text" value="${safeDesc}" placeholder="Descrição da tarefa..." oninput="updateTarefaDesc(${dIndex}, ${tIndex}, this.value)">
                        <button type="button" class="btn-delete" onclick="removerTarefa(${dIndex}, ${tIndex})"><i class="fa-solid fa-trash"></i></button>
                    </div>
                `;
            });

            HTML += `
                        </div>
                        <button type="button" class="btn btn-add-tarefa" onclick="adicionarTarefa(${dIndex})"><i class="fa-solid fa-plus"></i> Adicionar Nova Linha</button>
                    </div>
                </div>
            `;
            container.insertAdjacentHTML('beforeend', HTML);
        });
    }

    function adicionarDia() {
        let novoDia = criarNovoDia();
        if (planeamento.length > 0) {
            let ultimoDia = planeamento[planeamento.length - 1];
            novoDia.numero_fase = ultimoDia.numero_fase;
            novoDia.nome_fase = ultimoDia.nome_fase;
            novoDia.numero_dia = (parseInt(ultimoDia.numero_dia) || 0) + 1;
            novoDia.numero_homens = ultimoDia.numero_homens;
        }
        planeamento.push(novoDia);
        render();
    }

    function removerDia(dIndex) {
        if (confirm('Tem a certeza que deseja remover este bloco de planeamento?')) {
            planeamento.splice(dIndex, 1);
            render();
        }
    }

    function adicionarTarefa(dIndex) {
        planeamento[dIndex].tarefas.push({ descricao: '', concluida: false });
        render();
    }

    function removerTarefa(dIndex, tIndex) {
        planeamento[dIndex].tarefas.splice(tIndex, 1);
        render();
    }

    function updateDia(dIndex, field, value) {
        planeamento[dIndex][field] = value;
        if (field === 'numero_homens') updateResumo();
    }

    function updateTarefaDesc(dIndex, tIndex, value) {
        planeamento[dIndex].tarefas[tIndex].descricao = value;
    }
    
    function updateTarefaStatus(dIndex, tIndex, isChecked) {
        planeamento[dIndex].tarefas[tIndex].concluida = isChecked;
        render(); 
    }

    // REGRA DE OURO: Intercetar Guardar e Abrir Modal
    document.getElementById('formPlaneamento').onsubmit = function(e) {
        if (!isRedirectChosen) {
            e.preventDefault();
            document.getElementById('modalRedirect').classList.add('show');
            return;
        }
        document.getElementById('planeamento_json').value = JSON.stringify(planeamento);
    };

    function confirmarRedirect(escolha) {
        document.getElementById('redirect_action').value = escolha;
        isRedirectChosen = true; 
        document.getElementById('modalRedirect').classList.remove('show');
        
        document.getElementById('planeamento_json').value = JSON.stringify(planeamento);
        document.getElementById('formPlaneamento').submit(); 
    }

    window.onclick = function(e) { 
        if(e.target.classList.contains('modal')) { 
            e.target.classList.remove('show'); 
        } 
    };

    window.onload = render;
</script>

</body>
</html>