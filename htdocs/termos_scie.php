<?php
session_start();
require_once 'db.php';

// REGRA DE OURO: Funções centralizadas
if (file_exists('functions.php')) { 
    require_once 'functions.php'; 
} else { 
    function registrarLog($pdo, $acao, $modulo) { return true; } 
    function gerar_csrf_token() { return ''; }
    function validar_csrf_token($t) { return true; }
}

if (!isset($_SESSION['user_id'])) { header("Location: index.php"); exit; }

// --- PERMISSÕES ---
$stmt_perm = $pdo->prepare("SELECT modulo FROM permissoes WHERE grupo = :perfil AND pode_aceder = 1");
$stmt_perm->execute(['perfil' => $_SESSION['perfil']]);
$meus_acessos = $stmt_perm->fetchAll(PDO::FETCH_COLUMN);
$is_admin = ($_SESSION['perfil'] === 'admin');

if (!$is_admin && !in_array('termos', $meus_acessos)) {
    header("Location: index.php?erro=sem_permissao"); exit;
}

$nome_completo = $_SESSION['nome'] ?? 'Utilizador';
$primeiro_nome = explode(' ', trim($nome_completo))[0];

// 🛡️ REGRA DE OURO (SEGURANÇA): GERAR TOKEN CSRF
$csrf_token = gerar_csrf_token();

$mensagem = '';
$tipo_mensagem = '';

if (isset($_GET['sucesso'])) {
    if ($_GET['sucesso'] == 'apagado') {
        $mensagem = "Documento SCIE removido com sucesso.";
        $tipo_mensagem = "sucesso";
    } elseif ($_GET['sucesso'] == 'duplicado') {
        $mensagem = "Documento SCIE e respetivos itens duplicados com sucesso! A data foi atualizada para hoje.";
        $tipo_mensagem = "sucesso";
    }
}

// --- LÓGICA DE APAGAR (COM PASSWORD E CSRF) ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['btn_confirmar_apagar'])) {
    if (!validar_csrf_token($_POST['csrf_token'] ?? '')) {
        die("Tentativa bloqueada por segurança (CSRF Inválido). Volte atrás e recarregue a página.");
    }

    if (!$is_admin) {
        $mensagem = "Erro: Apenas administradores podem apagar documentos.";
        $tipo_mensagem = "erro";
    } else {
        $id_apagar = filter_input(INPUT_POST, 'id_apagar', FILTER_VALIDATE_INT);
        $pass_introduzida = $_POST['pass_seguranca'] ?? '';

        $stmt_pass = $pdo->prepare("SELECT valor FROM configuracoes WHERE chave = 'password_apagar' LIMIT 1");
        $stmt_pass->execute();
        $pass_config = $stmt_pass->fetchColumn();

        if ($pass_introduzida !== $pass_config || empty($pass_introduzida)) {
            $mensagem = "Password de segurança incorreta. Operação cancelada.";
            $tipo_mensagem = "erro";
        } else {
            try {
                $pdo->beginTransaction();
                
                // 1. Apagar primeiro os itens para não deixar lixo na BD
                $pdo->prepare("DELETE FROM termos_scie_items WHERE id_termo = ?")->execute([$id_apagar]);
                
                // 2. Apagar o Termo SCIE
                $pdo->prepare("DELETE FROM termos_scie WHERE id = ?")->execute([$id_apagar]);
                
                $pdo->commit();
                
                registrarLog($pdo, "Apagou Documento SCIE (ID: $id_apagar)", "SCIE");
                header("Location: termos_scie.php?sucesso=apagado"); 
                exit;
            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $mensagem = "Erro ao apagar documento: " . htmlspecialchars($e->getMessage());
                $tipo_mensagem = "erro";
            }
        }
    }
}

// --- LÓGICA DE DUPLICAR (CASCATA: TERMO + ITENS) ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['btn_confirmar_duplicar'])) {
    if (!validar_csrf_token($_POST['csrf_token'] ?? '')) {
        die("Tentativa bloqueada por segurança (CSRF Inválido). Volte atrás e recarregue a página.");
    }

    $id_dup = filter_input(INPUT_POST, 'id_duplicar', FILTER_VALIDATE_INT);
    
    try {
        $pdo->beginTransaction();
        
        // 1. DUPLICAR O TERMO PRINCIPAL
        $stmt = $pdo->prepare("SELECT * FROM termos_scie WHERE id = ?");
        $stmt->execute([$id_dup]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($row) {
            $id_antigo = $row['id'];
            unset($row['id']); // Remove o ID original
            $row['data_emissao'] = date('Y-m-d'); // Atualiza a data para hoje
            
            $columns = array_keys($row);
            $placeholders = array_fill(0, count($columns), '?');
            
            $sql_insert = "INSERT INTO termos_scie (" . implode(', ', $columns) . ") VALUES (" . implode(', ', $placeholders) . ")";
            $stmt_insert = $pdo->prepare($sql_insert);
            $stmt_insert->execute(array_values($row));
            
            $novo_id = $pdo->lastInsertId(); // O ID do novo Termo duplicado
            
            // 2. DUPLICAR OS ITENS ASSOCIADOS
            $stmt_eq = $pdo->prepare("SELECT * FROM termos_scie_items WHERE id_termo = ? ORDER BY id ASC");
            $stmt_eq->execute([$id_antigo]);
            $itens = $stmt_eq->fetchAll(PDO::FETCH_ASSOC);
            
            if (!empty($itens)) {
                // Pegar as colunas do primeiro item, ignorando o 'id' auto_increment
                $eq_columns = array_keys($itens[0]);
                $eq_columns = array_filter($eq_columns, function($c) { return $c !== 'id'; });
                
                $eq_placeholders = array_fill(0, count($eq_columns), '?');
                $sql_eq_insert = "INSERT INTO termos_scie_items (" . implode(', ', $eq_columns) . ") VALUES (" . implode(', ', $eq_placeholders) . ")";
                $stmt_eq_insert = $pdo->prepare($sql_eq_insert);
                
                foreach ($itens as $eq) {
                    $eq['id_termo'] = $novo_id; // Vincula ao novo documento
                    
                    $valores = [];
                    foreach ($eq_columns as $col) {
                        $valores[] = $eq[$col];
                    }
                    $stmt_eq_insert->execute($valores);
                }
            }
            
            $pdo->commit();
            registrarLog($pdo, "Duplicou Documento SCIE (Origem: $id_antigo -> Novo: $novo_id)", "SCIE");
            header("Location: termos_scie.php?sucesso=duplicado"); 
            exit;
        } else {
            throw new Exception("Registo original não encontrado.");
        }
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $mensagem = "Erro ao duplicar documento: " . htmlspecialchars($e->getMessage());
        $tipo_mensagem = "erro";
    }
}

// --- LÓGICA DO CALENDÁRIO (MÊS/ANO) ---
$mes_atual = isset($_GET['mes']) ? (int)$_GET['mes'] : (int)date('m');
$ano_atual = isset($_GET['ano']) ? (int)$_GET['ano'] : (int)date('Y');

$data_ref_calendario = sprintf("%04d-%02d-01", $ano_atual, $mes_atual);
$mes_anterior = date('m', strtotime('-1 month', strtotime($data_ref_calendario)));
$ano_anterior = date('Y', strtotime('-1 month', strtotime($data_ref_calendario)));
$mes_seguinte = date('m', strtotime('+1 month', strtotime($data_ref_calendario)));
$ano_seguinte = date('Y', strtotime('+1 month', strtotime($data_ref_calendario)));

$meses_pt = [1=>'Janeiro', 2=>'Fevereiro', 3=>'Março', 4=>'Abril', 5=>'Maio', 6=>'Junho', 7=>'Julho', 8=>'Agosto', 9=>'Setembro', 10=>'Outubro', 11=>'Novembro', 12=>'Dezembro'];
$nome_mes_atual = $meses_pt[$mes_atual];

// --- FILTRO E QUERY PRINCIPAL ---
$filtro_tipo = $_GET['tipo'] ?? '';
$sql = "SELECT t.*, o.nome as nome_obra, c.nome as nome_cliente, o.estado as estado_obra 
        FROM termos_scie t
        JOIN obras o ON t.id_obra = o.id
        JOIN clientes c ON o.id_cliente = c.id";

if ($filtro_tipo) { $sql .= " WHERE t.tipo_documento = :tipo"; }

// ALTERAÇÃO DE ORDENAÇÃO: 1º Cliente, 2º Especialidade, 3º Data Emissão DESC
$sql .= " ORDER BY c.nome ASC, t.tipo_documento ASC, t.data_emissao DESC, t.id DESC";

$stmt = $pdo->prepare($sql);
if ($filtro_tipo) { $stmt->bindParam(':tipo', $filtro_tipo); }
$stmt->execute();
$termos_query = $stmt->fetchAll();

// --- LÓGICA DE VALIDADE (+1 ANO) E SUBSTITUIÇÃO ---
$latest_map = [];
$agendamentos_calendario = [];

foreach ($termos_query as $t) {
    $chave_unica = $t['id_obra'] . '_' . $t['tipo_documento'];
    
    if (!isset($latest_map[$chave_unica])) {
        $latest_map[$chave_unica] = $t['id'];
        
        $data_validade = date('Y-m-d', strtotime('+1 year', strtotime($t['data_emissao'])));
        
        if (!isset($agendamentos_calendario[$data_validade])) {
            $agendamentos_calendario[$data_validade] = [];
        }
        $agendamentos_calendario[$data_validade][] = [
            'obra' => $t['nome_obra'],
            'tipo' => $t['tipo_documento'],
            'id_termo' => $t['id']
        ];
    }
}

// ALTERAÇÃO DE AGRUPAMENTO: Agrupar por Cliente em vez de Obra
$termos_por_cliente = [];
foreach ($termos_query as $t) {
    $nome_cliente = $t['nome_cliente'];
    
    if (!isset($termos_por_cliente[$nome_cliente])) {
        $termos_por_cliente[$nome_cliente] = [
            'id_cliente' => $t['id_cliente'],
            'termos' => []
        ];
    }
    
    $termos_por_cliente[$nome_cliente]['termos'][] = $t;
}

$tipos_documentos = [
    'portas_fogo' => ['label' => 'Portas Fogo', 'icon' => 'fa-door-closed', 'color' => '#e67e22'],
    'compartimentacao' => ['label' => 'Sistemas Compart.', 'icon' => 'fa-cubes', 'color' => '#d35400'],
    'sadi' => ['label' => 'SADI', 'icon' => 'fa-fire-flame-curved', 'color' => '#e74c3c'],
    'deteccao_gas' => ['label' => 'Deteção Gás', 'icon' => 'fa-mask-ventilator', 'color' => '#16a085'],
    'extincao_agua' => ['label' => 'Extinção Água', 'icon' => 'fa-droplet', 'color' => '#3498db'],
    'sinalizacao' => ['label' => 'Sinalização', 'icon' => 'fa-signs-post', 'color' => '#2ecc71'],
    'iluminacao_emergencia' => ['label' => 'Ilum. Emergência', 'icon' => 'fa-lightbulb', 'color' => '#f1c40f']
];

function getCorValidade($data_limite) {
    $hoje = new DateTime();
    $limite = new DateTime($data_limite);
    $diff = $hoje->diff($limite)->format("%r%a"); 
    if ($diff < 0) return '#c0392b'; 
    if ($diff <= 30) return '#e67e22'; 
    return '#27ae60'; 
}
?>
<!DOCTYPE html>
<html lang="pt-PT">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Termos SCIE - Gestão MAB</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { font-family: system-ui, sans-serif; background: #f7f9fc; margin: 0; font-size: 13px; padding-bottom: 50px; }
        * { box-sizing: border-box; }
        
        .container { max-width: 1400px; margin: 30px auto; padding: 0 20px; }
        
        .page-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px; }
        .page-title { margin: 0; color: #2c3e50; font-size: 24px; font-weight: 800; display: flex; align-items: center; gap: 10px; }
        
        .btn-primary { background: #b32a2a; color: #fff; padding: 12px 20px; border-radius: 8px; border: none; cursor: pointer; font-weight: bold; text-decoration: none; display: inline-flex; align-items: center; gap: 8px; transition: 0.2s; }
        .btn-primary:hover { background: #902222; }

        .alert { padding: 15px; border-radius: 8px; margin-bottom: 20px; text-align: center; font-weight: 600; }
        .alert-erro { background: #fdeded; color: #e74c3c; border: 1px solid #f9cccc; }
        .alert-sucesso { background: #fdf2f2; color: #b32a2a; border: 1px solid #fadbd8; }
        
        .header-actions { display: flex; gap: 8px; margin-bottom: 25px; flex-wrap: wrap; }
        .btn-filter { background: #fff; border: 1px solid #dcdde1; padding: 10px 15px; border-radius: 8px; text-decoration: none; color: #576574; font-weight: 600; font-size: 12px; transition: 0.2s; }
        .btn-filter:hover, .btn-filter.active { border-color: #b32a2a; color: #b32a2a; background: #fdf2f2; }

        .card-obra { background: #fff; border-radius: 12px; border: 1px solid #e1e5ea; margin-bottom: 30px; overflow: hidden; box-shadow: 0 4px 15px rgba(0,0,0,0.03); }
        .obra-head { background: #fdf2f2; padding: 15px 20px; border-bottom: 2px solid #b32a2a; display: flex; justify-content: space-between; align-items: center; }
        
        table { width: 100%; border-collapse: collapse; }
        th { text-align: left; padding: 12px 15px; background: #f8f9fa; font-size: 10px; text-transform: uppercase; color: #7f8c8d; border-bottom: 1px solid #eee; }
        td { padding: 12px 15px; border-bottom: 1px solid #f1f2f6; vertical-align: middle; }
        .text-center { text-align: center; }
        
        .acoes-container { display: flex; justify-content: center; align-items: center; gap: 15px; }
        .btn-icon { font-size: 18px; text-decoration: none; color: #576574; transition: 0.2s; display: inline-flex; align-items: center; justify-content: center; background: none; border: none; cursor: pointer; padding: 0;}
        .btn-icon:hover { transform: scale(1.1); color: #b32a2a; }
        
        .btn-dup { color: #3498db; }
        .btn-dup:hover { color: #2980b9 !important; }

        .badge-tipo { font-weight: bold; color: #2c3e50; display: inline-flex; align-items: center; gap: 8px; }
        .badge-int { font-size: 11px; color: #7f8c8d; display: block; margin-top: 2px; }

        .validade-badge { padding: 4px 8px; border-radius: 6px; font-size: 11px; font-weight: bold; display: inline-block; }
        .validade-old { color: #bdc3c7; font-weight: normal; font-style: italic; }

        /* CALENDÁRIO MENSAL */
        .calendar-card { background: #fff; border-radius: 12px; border: 1px solid #e1e5ea; margin-top: 40px; margin-bottom: 30px; box-shadow: 0 4px 15px rgba(0,0,0,0.03); overflow: hidden; }
        .calendar-header { background: #2c3e50; color: #fff; padding: 10px 15px; display: flex; justify-content: space-between; align-items: center; }
        .calendar-header h3 { margin: 0; font-size: 14px; display: flex; align-items: center; gap: 8px; }
        .calendar-nav a { color: #fff; text-decoration: none; background: rgba(255,255,255,0.2); padding: 4px 10px; border-radius: 6px; font-weight: bold; font-size: 11px; transition: 0.2s; }
        .calendar-nav a:hover { background: rgba(255,255,255,0.4); }
        .calendar-grid { display: grid; grid-template-columns: repeat(7, 1fr); background: #f1f2f6; gap: 1px; border-top: 1px solid #e1e5ea; }
        .day-name { background: #f8f9fa; padding: 6px; text-align: center; font-weight: bold; font-size: 10px; color: #576574; text-transform: uppercase; }
        .day-cell { background: #fff; min-height: 60px; padding: 5px; position: relative; }
        .day-cell.empty { background: #fafafa; }
        .day-cell.today { background: #fef9f9; box-shadow: inset 0 0 0 2px #b32a2a; }
        .day-number { font-weight: bold; color: #7f8c8d; font-size: 12px; margin-bottom: 3px; display: block; text-align: right; }
        .day-cell.today .day-number { color: #b32a2a; }
        .event-badge { display: block; font-size: 9px; color: #fff; padding: 2px 4px; border-radius: 4px; margin-bottom: 2px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; text-decoration: none; transition: 0.2s; }
        .event-badge:hover { filter: brightness(0.9); transform: scale(1.02); }

        /* MODAIS À PROVA DE BALA */
        .modal { display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.6); z-index:10000; align-items:center; justify-content:center; backdrop-filter: blur(2px); }
        .modal-content { background:#fff; padding:30px; border-radius:15px; width:90%; max-width:700px; text-align:center; box-shadow: 0 20px 50px rgba(0,0,0,0.2); }
        .grid-tipos { display:grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap:12px; margin:25px 0; }
        .type-opt { background:#f8f9fa; padding:20px; border-radius:10px; border:1px solid #dcdde1; text-decoration:none; color:#333; font-weight:bold; display:flex; flex-direction:column; align-items:center; gap:10px; transition: 0.2s; }
        .type-opt i { font-size: 24px; }
        .type-opt:hover { border-color:#b32a2a; color:#b32a2a; background:#fdf2f2; transform: translateY(-3px); }
        
        .modal-content input[type="password"] { width: 100%; padding: 12px; border: 1px solid #dcdde1; border-radius: 8px; margin: 15px 0; outline: none; transition: 0.2s;}
        .modal-content input[type="password"]:focus { border-color: #e74c3c; }

        @media (max-width: 768px) {
            .page-header { flex-direction: column; gap: 15px; align-items: flex-start; }
            .header-actions { justify-content: center; }
        }
    </style>
</head>
<body>

    <!-- REGRA DE OURO: CABEÇALHO CENTRALIZADO -->
    <?php include 'header.php'; ?>

    <div class="container">
        
        <?php if ($mensagem): ?>
            <div class="alert alert-<?= $tipo_mensagem ?>"><?= $mensagem ?></div>
        <?php endif; ?>

        <div class="page-header">
            <h1 class="page-title"><i class="fa-solid fa-fire-extinguisher" style="color:#b32a2a;"></i> SCIE</h1>
            <button type="button" onclick="abrirModalNovo()" class="btn-primary"><i class="fa-solid fa-plus-circle"></i> Novo Registo SCIE</button>
        </div>

        <div class="header-actions">
            <a href="termos_scie.php" class="btn-filter <?= !$filtro_tipo ? 'active' : '' ?>">Todos os Documentos</a>
            <?php foreach($tipos_documentos as $k => $v): ?>
                <a href="?tipo=<?= $k ?>" class="btn-filter <?= $filtro_tipo == $k ? 'active' : '' ?>">
                    <i class="fa-solid <?= $v['icon'] ?>" style="color:<?= $v['color'] ?>; margin-right:5px;"></i> <?= $v['label'] ?>
                </a>
            <?php endforeach; ?>
        </div>

        <?php if(!empty($termos_por_cliente)): foreach($termos_por_cliente as $nome_cliente => $d): ?>
            <div class="card-obra">
                <div class="obra-head">
                    <div>
                        <strong style="font-size:16px; color:#b32a2a;"><i class="fa-solid fa-user-tie"></i> <?= htmlspecialchars($nome_cliente) ?></strong><br>
                    </div>
                </div>
                <table>
                    <thead>
                        <tr>
                            <th width="180">Obra Associada</th>
                            <th width="120">Data Inicial</th>
                            <th>Especialidade</th>
                            <th width="150" class="text-center">Próx. Manutenção</th>
                            <th width="200" class="text-center">Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($d['termos'] as $t): 
                            $info_tipo = $tipos_documentos[$t['tipo_documento']] ?? ['label' => $t['tipo_documento'], 'icon' => 'fa-file'];
                            $chave_unica = $t['id_obra'] . '_' . $t['tipo_documento'];
                            
                            $is_latest = ($latest_map[$chave_unica] == $t['id']);
                            $data_validade = date('d/m/Y', strtotime('+1 year', strtotime($t['data_emissao'])));
                            $data_validade_iso = date('Y-m-d', strtotime('+1 year', strtotime($t['data_emissao'])));
                            
                            $cor_badge = getCorValidade($data_validade_iso);
                        ?>
                        <tr>
                            <td><strong style="color:#2c3e50; font-size:12px;"><?= htmlspecialchars($t['nome_obra']) ?></strong></td>
                            <td><i class="fa-regular fa-calendar-check" style="color:#bdc3c7;"></i> <?= date('d/m/Y', strtotime($t['data_emissao'])) ?></td>
                            <td>
                                <span class="badge-tipo"><i class="fa-solid <?= $info_tipo['icon'] ?>" style="color:<?= $info_tipo['color'] ?? '#333' ?>; width:20px;"></i> <?= $info_tipo['label'] ?></span>
                                <span class="badge-int"><?= $t['tipo_intervencao'] == 'instalacao' ? 'Instalação' : 'Manutenção Preventiva' ?></span>
                            </td>
                            <td class="text-center">
                                <?php if ($is_latest): ?>
                                    <span class="validade-badge" style="background: <?= $cor_badge ?>15; color: <?= $cor_badge ?>; border: 1px solid <?= $cor_badge ?>30;">
                                        <i class="fa-regular fa-clock"></i> <?= $data_validade ?>
                                    </span>
                                <?php else: ?>
                                    <span class="validade-old" title="Foi emitido um documento mais recente para esta especialidade."><i class="fa-solid fa-minus"></i> Substituído</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="acoes-container">
                                    <a href="termo_scie_pdf.php?id=<?= $t['id'] ?>&doc=termo" target="_blank" class="btn-icon" title="Imprimir Termo de Responsabilidade" style="color:#b32a2a;"><i class="fa-solid fa-file-pdf"></i></a>
                                    <a href="termo_scie_pdf.php?id=<?= $t['id'] ?>&doc=relatorio" target="_blank" class="btn-icon" title="Imprimir Relatório de Intervenção" style="color:#8e44ad;"><i class="fa-solid fa-file-pdf"></i></a>
                                    
                                    <a href="termo_scie_form.php?id=<?= $t['id'] ?>" class="btn-icon" title="Editar"><i class="fa-solid fa-pen-to-square"></i></a>
                                    
                                    <!-- BOTÃO DUPLICAR -->
                                    <button type="button" onclick="abrirModalDuplicar(<?= $t['id'] ?>)" class="btn-icon btn-dup" title="Duplicar"><i class="fa-solid fa-copy"></i></button>

                                    <?php if($is_admin): ?>
                                        <button type="button" onclick="abrirModalApagar(<?= $t['id'] ?>)" class="btn-icon" style="color:#b32a2a;" title="Eliminar"><i class="fa-solid fa-trash-can"></i></button>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endforeach; else: ?>
            <div style="text-align:center; padding:50px; background:#fff; border-radius:12px; border:1px solid #ddd;">
                <i class="fa-solid fa-folder-open" style="font-size:40px; color:#ccc; margin-bottom:10px;"></i>
                <p style="color:#7f8c8d;">Nenhum documento encontrado com estes critérios.</p>
            </div>
        <?php endif; ?>

        <?php if ($filtro_tipo == ''): ?>
        <div class="calendar-card">
            <div class="calendar-header">
                <h3><i class="fa-regular fa-calendar-days"></i> Previsão de Manutenções: <?= $nome_mes_atual ?> <?= $ano_atual ?></h3>
                <div class="calendar-nav">
                    <a href="?mes=<?= $mes_anterior ?>&ano=<?= $ano_anterior ?>"><i class="fa-solid fa-chevron-left"></i> Anterior</a>
                    <a href="?mes=<?= date('m') ?>&ano=<?= date('Y') ?>">Mês Atual</a>
                    <a href="?mes=<?= $mes_seguinte ?>&ano=<?= $ano_seguinte ?>">Seguinte <i class="fa-solid fa-chevron-right"></i></a>
                </div>
            </div>
            
            <div class="calendar-grid">
                <div class="day-name">Seg</div><div class="day-name">Ter</div><div class="day-name">Qua</div><div class="day-name">Qui</div><div class="day-name">Sex</div><div class="day-name">Sáb</div><div class="day-name">Dom</div>
                
                <?php
                $primeiro_dia_mes = date('N', strtotime("$ano_atual-$mes_atual-01"));
                $total_dias_mes = date('t', strtotime("$ano_atual-$mes_atual-01"));
                $hoje_str = date('Y-m-d');
                
                for ($i = 1; $i < $primeiro_dia_mes; $i++) {
                    echo '<div class="day-cell empty"></div>';
                }
                
                for ($dia = 1; $dia <= $total_dias_mes; $dia++) {
                    $data_loop = sprintf("%04d-%02d-%02d", $ano_atual, $mes_atual, $dia);
                    $classe_hoje = ($data_loop === $hoje_str) ? 'today' : '';
                    
                    echo '<div class="day-cell ' . $classe_hoje . '">';
                    echo '<span class="day-number">' . $dia . '</span>';
                    
                    if (isset($agendamentos_calendario[$data_loop])) {
                        foreach ($agendamentos_calendario[$data_loop] as $evento) {
                            $cor_evento = $tipos_documentos[$evento['tipo']]['color'] ?? '#333';
                            $icone_evento = $tipos_documentos[$evento['tipo']]['icon'] ?? 'fa-wrench';
                            echo '<a href="termo_scie_pdf.php?id='.$evento['id_termo'].'&doc=relatorio" target="_blank" class="event-badge" style="background-color:'.$cor_evento.'" title="'.$evento['obra'].' - '.$tipos_documentos[$evento['tipo']]['label'].'">';
                            echo '<i class="fa-solid '.$icone_evento.'"></i> ' . htmlspecialchars($evento['obra']);
                            echo '</a>';
                        }
                    }
                    
                    echo '</div>';
                }
                
                $resto = ($primeiro_dia_mes - 1 + $total_dias_mes) % 7;
                if ($resto > 0) {
                    for ($i = $resto; $i < 7; $i++) {
                        echo '<div class="day-cell empty"></div>';
                    }
                }
                ?>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- MODAL NOVO -->
    <div class="modal" id="modalNovo">
        <div class="modal-content" style="max-width: 700px;">
            <h2 style="color:#b32a2a; margin-top:0; margin-bottom:5px;">Nova Intervenção SCIE</h2>
            <p style="color:#7f8c8d; margin-bottom:20px;">Selecione a especialidade para o novo documento:</p>
            <div class="grid-tipos">
                <?php foreach($tipos_documentos as $k => $v): ?>
                    <a href="javascript:void(0)" onclick="criar('<?= $k ?>')" class="type-opt">
                        <i class="fa-solid <?= $v['icon'] ?>" style="color:<?= $v['color'] ?>"></i>
                        <?= $v['label'] ?>
                    </a>
                <?php endforeach; ?>
            </div>
            <button type="button" onclick="fecharModais()" style="background:#eee; color:#576574; border:none; padding:12px 30px; border-radius:8px; cursor:pointer; font-weight:bold; margin-top:15px; transition:0.2s;">Cancelar</button>
        </div>
    </div>

    <!-- MODAL DUPLICAR -->
    <div class="modal" id="modalDuplicar">
        <div class="modal-content" style="max-width: 400px; padding: 30px; text-align: center;">
            <h3 style="color:#3498db; margin-top:0; margin-bottom: 15px;"><i class="fa-solid fa-copy"></i> Confirmar Duplicação</h3>
            <p style="color:#2c3e50; font-size:14px; margin-bottom: 20px;">Deseja criar uma cópia exata deste documento com todos os equipamentos?<br><small style="color:#7f8c8d;">A data de emissão será atualizada para o dia de hoje.</small></p>
            
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                <input type="hidden" name="id_duplicar" id="id_duplicar_input">
                
                <div style="display:flex; gap:10px;">
                    <button type="button" onclick="fecharModais()" style="flex:1; background:#f1f2f6; color:#576574; border:none; padding:12px; border-radius:8px; cursor:pointer; font-weight:600; transition:0.2s;">Cancelar</button>
                    <button type="submit" name="btn_confirmar_duplicar" style="flex:1; background:#3498db; color:#fff; border:none; padding:12px; border-radius:8px; cursor:pointer; font-weight:600; transition:0.2s;">Duplicar</button>
                </div>
            </form>
        </div>
    </div>

    <!-- MODAL APAGAR -->
    <?php if ($is_admin): ?>
    <div class="modal" id="modalApagar">
        <div class="modal-content" style="max-width: 400px; padding: 30px;">
            <h3 style="color:#e74c3c; margin-top:0; margin-bottom: 15px;"><i class="fa-solid fa-triangle-exclamation"></i> Confirmar Eliminação</h3>
            <p style="color:#2c3e50; font-size:14px; margin-bottom: 20px;">Tem a certeza que deseja apagar este documento SCIE e respetivos itens definitivamente?</p>
            
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                <input type="hidden" name="id_apagar" id="id_apagar_input">
                <input type="password" name="pass_seguranca" required placeholder="Password de Segurança">
                
                <div style="display:flex; gap:10px;">
                    <button type="button" onclick="fecharModais()" style="flex:1; background:#f1f2f6; color:#576574; border:none; padding:12px; border-radius:8px; cursor:pointer; font-weight:600; transition:0.2s;">Cancelar</button>
                    <button type="submit" name="btn_confirmar_apagar" style="flex:1; background:#e74c3c; color:#fff; border:none; padding:12px; border-radius:8px; cursor:pointer; font-weight:600; transition:0.2s;">Apagar</button>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <script>
        let currentObra = null;
        
        function abrirModalNovo(id) { 
            currentObra = (typeof id === 'number') ? id : null;
            document.getElementById('modalNovo').style.display = 'flex'; 
        }

        // Função de Duplicação
        function abrirModalDuplicar(id) {
            document.getElementById('id_duplicar_input').value = id;
            document.getElementById('modalDuplicar').style.display = 'flex'; 
        }

        function abrirModalApagar(id) {
            document.getElementById('id_apagar_input').value = id;
            document.getElementById('modalApagar').style.display = 'flex'; 
            document.querySelector('#modalApagar input[name="pass_seguranca"]').value = '';
            document.querySelector('#modalApagar input[name="pass_seguranca"]').focus();
        }

        function fecharModais() { 
            document.querySelectorAll('.modal').forEach(m => m.style.display = 'none');
        }

        function criar(tipo) { 
            let url = 'termo_scie_form.php?tipo=' + tipo;
            if(currentObra) url += '&obra_id=' + currentObra;
            window.location.href = url;
        }

        // Clicar fora para fechar os modais
        window.onclick = function(e) { 
            if(e.target.classList.contains('modal')) fecharModais(); 
        }
    </script>
</body>
</html>