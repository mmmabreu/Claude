<?php
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
} else { 
    function registrarLog($pdo, $acao, $modulo) { return true; } 
}

$perfil_user = $_SESSION['perfil'] ?? '';
$user_id_atual = $_SESSION['user_id'];
$is_admin = in_array($perfil_user, ['admin', 'administrativo']);

// REGRA DE OURO 2: Acesso Restrito pela Matriz (Proteção de URL direta)
$stmt_perm = $pdo->prepare("SELECT modulo FROM permissoes WHERE grupo = :perfil AND pode_aceder = 1");
$stmt_perm->execute(['perfil' => $perfil_user]);
$meus_acessos = $stmt_perm->fetchAll(PDO::FETCH_COLUMN);

if (!$is_admin && !in_array('obras', $meus_acessos)) {
    header("Location: index.php?erro=sem_permissao");
    exit;
}

// REGRA DE OURO 3: Obter apenas o primeiro nome para o Menu
$nome_completo = $_SESSION['nome'] ?? 'Utilizador';
$primeiro_nome = explode(' ', trim($nome_completo))[0];

$mensagem = ''; 
$tipo_mensagem = '';

if (isset($_GET['editado'])) {
    $mensagem = "Operação realizada com sucesso!";
    $tipo_mensagem = "sucesso";
}

// --- LÓGICA INTELIGENTE DE SINCRONIZAÇÃO DE PLANEAMENTO ---
if (isset($_GET['sync_plan']) && isset($_GET['obra_id'])) {
    $o_id = (int)$_GET['obra_id'];
    
    // Verificar se a obra está ligada a um orçamento
    $stmt_chk_orc = $pdo->prepare("SELECT id_orcamento FROM obras WHERE id = ?");
    $stmt_chk_orc->execute([$o_id]);
    $id_orc = $stmt_chk_orc->fetchColumn();

    if ($id_orc) {
        // Verificar se a obra já tem linhas de planeamento próprias
        $stmt_check_linhas = $pdo->prepare("SELECT COUNT(*) FROM planeamento_linhas WHERE obra_id = ?");
        $stmt_check_linhas->execute([$o_id]);
        $tem_linhas = $stmt_check_linhas->fetchColumn();

        if ($tem_linhas == 0) {
            // Se não tem, COPIA as linhas do Orçamento associado
            $stmt_get_orc_linhas = $pdo->prepare("SELECT * FROM planeamento_linhas WHERE orcamento_id = ?");
            $stmt_get_orc_linhas->execute([$id_orc]);
            $linhas_orc = $stmt_get_orc_linhas->fetchAll(PDO::FETCH_ASSOC);

            if ($linhas_orc) {
                $stmt_ins_linha = $pdo->prepare("INSERT INTO planeamento_linhas (obra_id, numero_fase, nome_fase, numero_dia, numero_homens, tipo_equipa, descricao_tarefa, tempo_tarefa, concluida) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                foreach($linhas_orc as $l) {
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

// --- LÓGICA PARA APAGAR OBRA ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['btn_confirmar_apagar'])) { 
    $id_obra = filter_input(INPUT_POST, 'id_obra_apagar', FILTER_VALIDATE_INT); 
    $pass_introduzida = $_POST['pass_seguranca'] ?? '';

    $stmt_pass = $pdo->prepare("SELECT valor FROM configuracoes WHERE chave = 'password_apagar' LIMIT 1"); 
    $stmt_pass->execute(); 
    $pass_config = $stmt_pass->fetchColumn();

    if ($pass_introduzida !== $pass_config) { 
        $mensagem = "Password de segurança incorreta.";  
        $tipo_mensagem = "erro"; 
    } else { 
        try {
            $pdo->beginTransaction();
            $pdo->prepare("DELETE FROM planeamento_linhas WHERE obra_id = ?")->execute([$id_obra]);
            $pdo->prepare("DELETE FROM obras WHERE id = ?")->execute([$id_obra]); 
            $pdo->commit();

            registrarLog($pdo, "Apagou Obra ID: $id_obra", "Obras"); 
            $mensagem = "Obra removida com sucesso.";  
            $tipo_mensagem = "sucesso"; 
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $mensagem = "Erro ao apagar: " . $e->getMessage(); $tipo_mensagem = "erro"; 
        }
    } 
}

// --- BUSCAR OBRAS (Agrupadas por Cliente) ---
// n_fame / n_fame_ok: linhas da FAME gravadas / completas (fabricante + referência + ficha técnica)
fameCriarTabelas($pdo);
$query = "SELECT o.*, c.nome as nome_cliente,
                 (SELECT COUNT(*) FROM obras_fame f WHERE f.id_obra = o.id AND f.incluir = 1) AS n_fame,
                 (SELECT COUNT(*) FROM obras_fame f WHERE f.id_obra = o.id AND f.incluir = 1 AND f.fabricante <> '' AND f.referencia <> '' AND f.ficha_tecnica <> '') AS n_fame_ok
          FROM obras o LEFT JOIN clientes c ON o.id_cliente = c.id 
          ORDER BY (c.nome IS NULL) ASC, c.nome ASC, CASE o.estado WHEN 'Em curso' THEN 1 WHEN 'Planeamento' THEN 2 WHEN 'Concluída' THEN 3 ELSE 4 END ASC, o.id DESC"; 
$obras_raw = $pdo->query($query)->fetchAll(PDO::FETCH_ASSOC);

$obras_por_cliente = [];
foreach ($obras_raw as $o) {
    $cliente = $o['nome_cliente'] ?: 'Sem Cliente Associado';
    if (!isset($obras_por_cliente[$cliente])) {
        $obras_por_cliente[$cliente] = [];
    }
    $obras_por_cliente[$cliente][] = $o;
}

$lista_clientes = array_keys($obras_por_cliente);
?>
<!DOCTYPE html>
<html lang="pt-PT">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Obras - Gestão MAB</title>
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
        .btn-voltar:active { transform: scale(0.94); }

        .user-menu { position: relative; }
        .user-btn { background: #fdfdfd; border: 1px solid #f1f2f6; border-radius: 30px; font-size: 14px; font-weight: 600; color: #2c3e50; cursor: pointer; display: flex; align-items: center; gap: 10px; padding: 6px 15px 6px 6px; }
        .user-avatar { background: #e74c3c; color: white; width: 28px; height: 28px; border-radius: 50%; display: flex; justify-content: center; align-items: center; font-size: 14px; border: 2px solid #fadbd8; }
        
        .dropdown-content { display: none; position: absolute; right: 0; top: 100%; background: #fff; min-width: 180px; box-shadow: 0 10px 30px rgba(0,0,0,0.1); border-radius: 8px; border: 1px solid #f1f2f6; overflow: hidden; margin-top: 5px; z-index: 200; }
        .user-menu:hover .dropdown-content, .user-menu.aberto .dropdown-content { display: block; }
        .dropdown-content a { color: #576574; padding: 12px 20px; text-decoration: none; display: block; font-size: 14px; }
        .dropdown-content a:hover { background: #f0fff4; color: #2ecc71; }

        .container { max-width: 1200px; margin: 20px auto; padding: 0 15px; }
        .header-box { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 15px; }
        h1 { margin: 0; color: #2c3e50; font-size: 22px; font-weight: 800; }
        
        .header-actions { display: flex; gap: 10px; }
        .btn-novo { background: #fff; color: #2ecc71; border: 1px solid #2ecc71; padding: 8px 15px; border-radius: 30px; text-decoration: none; font-weight: 700; font-size: 13px; display: inline-flex; align-items: center; gap: 6px; transition: 0.2s; }
        .btn-novo:hover { background: #2ecc71; color: #fff; }
        
        .btn-secundary { background: #fff; color: #34495e; border: 1px solid #34495e; padding: 8px 15px; border-radius: 30px; text-decoration: none; font-weight: 700; font-size: 13px; display: inline-flex; align-items: center; gap: 6px; transition: 0.2s; }
        .btn-secundary:hover { background: #34495e; color: #fff; }

        /* BARRA DE FILTROS */
        .filter-box { background: #fff; border-radius: 12px; border: 1px solid #e1e5ea; padding: 15px 20px; margin-bottom: 25px; display: flex; align-items: center; gap: 15px; box-shadow: 0 2px 10px rgba(0,0,0,0.02); flex-wrap: wrap;}
        .filter-box label { font-weight: 700; color: #576574; font-size: 13px; text-transform: uppercase; margin: 0; display:flex; align-items:center; gap:8px;}
        .filter-box select { padding: 10px 15px; border: 1px solid #dcdde1; border-radius: 8px; font-size: 14px; min-width: 300px; outline: none; transition: border-color 0.2s; color: #2c3e50; font-weight: 600; font-family: inherit;}
        .filter-box select:focus { border-color: #2ecc71; box-shadow: 0 0 0 3px rgba(46,204,113,0.1); }

        .card { background: #fff; border-radius: 12px; border: 1px solid #e1e5ea; box-shadow: 0 2px 10px rgba(0,0,0,0.02); overflow: hidden; }
        .table-responsive { width: 100%; overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; min-width: 700px; }
        th { background: #f8f9fa; padding: 12px 15px; text-align: left; font-size: 11px; text-transform: uppercase; color: #576574; border-bottom: 2px solid #e1e5ea; }
        td { padding: 12px 15px; border-bottom: 1px solid #f1f2f6; color: #2c3e50; vertical-align: middle; }
        tr.obra-row:hover { background: #fcfcfb; }
        
        /* Estilo Agrupamento Cliente */
        .group-header { background-color: #f1f2f6; }
        .group-header td { padding: 10px 15px; color: #2c3e50; font-weight: 800; font-size: 13px; text-transform: uppercase; border-bottom: 2px solid #dcdde1; }

        .badge { padding: 4px 8px; border-radius: 6px; font-size: 11px; font-weight: 700; text-transform: uppercase; display: inline-block; }
        .bg-preparacao { background: #fff3e0; color: #e67e22; border: 1px solid #ffe0b2; }
        .bg-curso { background: #e8f5e9; color: #2ecc71; border: 1px solid #c8e6c9; }
        .bg-terminada { background: #f1f2f6; color: #7f8c8d; border: 1px solid #dcdde1; }

        .acoes { display: flex; gap: 8px; justify-content: center; flex-wrap: wrap; }
        .btn-tab-acao { color: #576574; background: #f1f2f6; padding: 8px; border-radius: 6px; text-decoration: none; transition: 0.2s; display: inline-flex; width: 32px; height: 32px; align-items: center; justify-content: center; }
        .btn-tab-acao:hover { background: #e1e5ea; color: #2c3e50; }
        .btn-planeamento { color: #9b59b6; background: #f4ecf8; }
        .btn-planeamento:hover { color: #8e44ad; background: #e8daef; }
        .btn-material { color: #16a085; background: #e8f8f5; }
        .btn-material:hover { color: #117a65; background: #d1f2eb; }
        .btn-fame { color: #d35400; background: #fdf2e9; position: relative; }
        .btn-fame:hover { color: #a04000; background: #fae5d3; }
        .btn-fame .ponto { position: absolute; top: -3px; right: -3px; width: 10px; height: 10px; border-radius: 50%; border: 2px solid #fff; }
        .btn-fame .ponto.ok { background: #27ae60; }
        .btn-fame .ponto.meio { background: #f39c12; }

        .btn-del { color: #e74c3c; background: #fdeded; border: none; cursor: pointer; }
        .btn-del:hover { background: #fadbd8; color: #c0392b; }

        .col-mobile { display: none; }

        .modal { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.6); z-index: 1001; justify-content: center; align-items: center; padding: 20px; }
        .modal.show { display: flex; }
        .modal-content { background: #fff; padding: 30px; border-radius: 12px; width: 100%; max-width: 400px; text-align: center; }
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
            .header-box { flex-direction: column; align-items: flex-start; border-bottom: none; margin-bottom: 10px; }
            .header-actions { flex-direction: column; width: 100%; }
            .btn-novo, .btn-secundary { justify-content: center; padding: 10px; width: 100%; }
            .filter-box select { min-width: 0; width: 100%; }
            .filter-box { flex-direction: column; align-items: stretch; gap: 8px; }
            .col-pc { display: none; }
            .col-mobile { display: block; }

            /* Telemóvel: cada obra passa a cartão */
            .card { background: transparent; border: none; box-shadow: none; }
            table { min-width: 100%; }
            table thead { display: none; }
            table, tbody, tr, td { display: block; width: 100%; }
            tr.group-header { background: none; margin: 14px 0 6px; }
            tr.group-header td { padding: 4px 2px !important; border: none; display: flex; justify-content: space-between; align-items: center; font-size: 12px; }
            tr.group-header span { float: none !important; padding: 0 !important; }
            tr.obra-row { background: #fff; border: 1px solid #e1e5ea; border-radius: 12px; margin-bottom: 10px; padding: 12px; display: grid; grid-template-columns: 1fr auto; gap: 6px 10px; box-shadow: 0 2px 8px rgba(0,0,0,0.03); }
            tr.obra-row td { padding: 0; border: none; }
            tr.obra-row td:nth-child(1) { grid-column: 1 / 2; }
            tr.obra-row td:nth-child(3) { display: none; }                 /* KM */
            tr.obra-row td:nth-child(4) { grid-column: 2 / 3; grid-row: 1; text-align: right !important; }
            tr.obra-row td:nth-child(5) { grid-column: 1 / -1; border-top: 1px solid #f1f2f6; padding-top: 10px; }
            tr.obra-row .acoes { justify-content: flex-start; gap: 8px; }
            tr.obra-row .btn-tab-acao { width: 38px; height: 38px; }
        }
    </style>
</head>
<body>

    <header class="topbar">
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
            <div class="alert <?= $tipo_mensagem === 'sucesso' ? 'alert-sucesso' : 'alert-erro' ?>" style="padding: 15px; border-radius: 8px; margin-bottom: 20px; font-weight: 600; text-align: center; <?= $tipo_mensagem === 'sucesso' ? 'background: #e8f5e9; color: #2ecc71; border: 1px solid #c8e6c9;' : 'background: #fdeded; color: #e74c3c; border: 1px solid #fadbd8;' ?>">
                <?= $tipo_mensagem === 'erro' ? '<i class="fa-solid fa-triangle-exclamation"></i>' : '<i class="fa-solid fa-check-circle"></i>' ?> 
                <?= htmlspecialchars($mensagem, ENT_QUOTES, 'UTF-8') ?>
            </div>
        <?php endif; ?>

        <div class="header-box">
            <h1><i class="fa-solid fa-helmet-safety" style="color: #2ecc71; margin-right: 8px;"></i> Obras</h1>
            <div class="header-actions">
                <a href="autos.php" class="btn-secundary"><i class="fa-solid fa-file-invoice-dollar"></i> Autos de Medição</a>
                <a href="obra_form.php" class="btn-novo"><i class="fa-solid fa-plus"></i> Nova Obra</a>
            </div>
        </div>

        <!-- BARRA DE FILTRO POR CLIENTE -->
        <div class="filter-box">
            <label for="filtro_cliente"><i class="fa-solid fa-filter" style="color: #3498db;"></i> Mostrar:</label>
            <select id="filtro_cliente" onchange="filtrarCliente()">
                <option value="ativas" selected>Obras ativas (todos os clientes)</option>
                <option value="todos">Todas as obras (incluindo concluídas)</option>
                <optgroup label="Por cliente (todas as obras do cliente)">
                <?php foreach($lista_clientes as $cli_nome): ?>
                    <option value="<?= htmlspecialchars($cli_nome) ?>"><?= htmlspecialchars($cli_nome) ?></option>
                <?php endforeach; ?>
                </optgroup>
            </select>
            <span id="contagem_obras" style="font-size:13px; color:#7f8c8d; font-weight:600;"></span>
        </div>

        <div class="card">
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>Obra / Ref</th>
                            <th class="col-pc">Localização</th>
                            <th style="text-align: center; width: 80px;">KM</th>
                            <th style="text-align: center;">Estado</th>
                            <th style="text-align: center; width: 300px;">Ações</th>
                        </tr>
                    </thead>
                    
                    <!-- PLACEHOLDER INICIAL -->
                    <tbody id="placeholder_obras">
                        <tr>
                            <td colspan="5" style="text-align: center; padding: 60px 20px; color: #95a5a6; font-size: 15px;">
                                <i class="fa-solid fa-folder-open" style="font-size: 32px; margin-bottom: 15px; display: block; color: #bdc3c7;"></i>
                                <span id="placeholder_txt">Não há obras ativas. Escolha "Todas as obras" ou um cliente no menu acima.</span>
                            </td>
                        </tr>
                    </tbody>

                    <?php if (count($obras_por_cliente) > 0): ?>
                        <?php foreach ($obras_por_cliente as $cliente => $obras_cli): ?>
                            <!-- UM TBODY POR CLIENTE (Oculto por padrão) -->
                            <tbody class="client-group" data-cliente="<?= htmlspecialchars($cliente) ?>" style="display: none;">
                                <tr class="group-header">
                                    <td colspan="5">
                                        <i class="fa-solid fa-user" style="color: #95a5a6; margin-right: 8px;"></i> <?= htmlspecialchars($cliente) ?>
                                        <span class="conta-grupo" style="float: right; font-size: 11px; color: #7f8c8d; font-weight: 600; padding-top:2px;"><?= count($obras_cli) ?> obra(s)</span>
                                    </td>
                                </tr>

                                <?php foreach ($obras_cli as $o): 
                                    $badge = 'bg-curso';
                                    if (stripos($o['estado'], 'prepar') !== false || stripos($o['estado'], 'planea') !== false) $badge = 'bg-preparacao';
                                    if (stripos($o['estado'], 'terminada') !== false || stripos($o['estado'], 'conclui') !== false) $badge = 'bg-terminada';
                                    // Ativa = não concluída / terminada / cancelada / anulada / arquivada
                                    $inativa = preg_match('/conclu|terminad|cancel|anulad|arquiv|fechad/i', (string)$o['estado']);
                                ?>
                                <tr class="obra-row" data-ativa="<?= $inativa ? '0' : '1' ?>">
                                    <td>
                                        <strong style="color: #2ecc71;"><?= htmlspecialchars($o['nome']) ?></strong>
                                        <?php if (!empty($o['referencia_obra'])): ?>
                                            <br><span style="font-size:11px; color:#2ecc71; font-weight:600; background:#e8f5e9; padding:2px 6px; border-radius:4px; display:inline-block; margin-top:4px;">REF: <?= htmlspecialchars($o['referencia_obra']) ?></span>
                                        <?php endif; ?>
                                        <div class="col-mobile">
                                            <small style="color: #7f8c8d; margin-top: 5px; display:block;"><i class="fa-solid fa-location-dot"></i> <?= htmlspecialchars($o['morada']) ?></small>
                                        </div>
                                    </td>
                                    <td class="col-pc"><small style="color: #7f8c8d;"><i class="fa-solid fa-location-dot"></i> <?= htmlspecialchars($o['morada']) ?></small></td>
                                    <td style="text-align: center; font-weight: 600; color: #576574;">
                                        <?= number_format((float)($o['km'] ?? 0), 2, ',', ' ') ?>
                                    </td>
                                    <td style="text-align: center;"><span class="badge <?= $badge ?>"><?= htmlspecialchars($o['estado']) ?></span></td>
                                    <td>
                                        <div class="acoes">
                                            <a href="obras.php?sync_plan=1&obra_id=<?= $o['id'] ?>" class="btn-tab-acao btn-planeamento" title="Planeamento"><i class="fa-solid fa-list-check"></i></a>

                                            <a href="autos.php?obra_id=<?= $o['id'] ?>" class="btn-tab-acao" style="color: #2980b9; background: #ebf5fb;" title="Autos de Medição"><i class="fa-solid fa-file-invoice"></i></a>

                                            <a href="obra_material.php?id=<?= $o['id'] ?>" class="btn-tab-acao btn-material" title="Material a aprovisionar"><i class="fa-solid fa-boxes-stacked"></i></a>

                                            <a href="obra_fame.php?id=<?= $o['id'] ?>" class="btn-tab-acao btn-fame" title="FAME - Ficha de Aprovação de Material e Equipamento<?= (int)$o['n_fame'] > 0 ? ' (' . (int)$o['n_fame_ok'] . ' de ' . (int)$o['n_fame'] . ' completos)' : '' ?>"><i class="fa-solid fa-clipboard-check"></i><?php if ((int)$o['n_fame'] > 0): ?><span class="ponto <?= (int)$o['n_fame_ok'] === (int)$o['n_fame'] ? 'ok' : 'meio' ?>"></span><?php endif; ?></a>
                                            
                                            <a href="obra_form.php?id=<?= $o['id'] ?>" class="btn-tab-acao" title="Editar"><i class="fa-solid fa-pen-to-square"></i></a>
                                            
                                            <?php if ($is_admin): ?>
                                            <button type="button" onclick="abrirModal(<?= $o['id'] ?>, '<?= addslashes(htmlspecialchars($o['nome'])) ?>')" class="btn-tab-acao btn-del" title="Apagar"><i class="fa-solid fa-trash-can"></i></button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </table>
            </div>
        </div>
    </div>

    <div class="modal" id="modalApagar">
        <div class="modal-content">
            <h3 style="color:#e74c3c; margin-top:0;"><i class="fa-solid fa-triangle-exclamation"></i> Eliminar Obra</h3>
            <p>Deseja apagar a obra:<br><strong id="txt_modal" style="color: #2c3e50; font-size: 16px; margin-top: 10px; display: inline-block;"></strong>?</p>
            <p style="font-size: 12px; color: #7f8c8d; line-height: 1.4; margin-bottom: 20px;">Atenção: Ao apagar, todos os dados associados a esta obra serão permanentemente eliminados.</p>
            
            <form method="POST" id="formObraApagar">
                <input type="hidden" name="id_obra_apagar" id="id_obra_m">
                <input type="password" name="pass_seguranca" required placeholder="Password de Segurança">
                <div class="modal-btns">
                    <button type="button" class="btn-cancelar" onclick="fecharModal()">Cancelar</button>
                    <button type="submit" name="btn_confirmar_apagar" class="btn-confirmar">Apagar</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // --- JAVASCRIPT: LÓGICA DO FILTRO MINIMALISTA ---
        // Ao abrir: obras ativas de todos os clientes, agrupadas por cliente.
        // "Todas as obras": também as concluídas. Um cliente: todas as obras desse cliente.
        function filtrarCliente() {
            const selecionado = document.getElementById('filtro_cliente').value;
            const placeholder = document.getElementById('placeholder_obras');
            let total = 0, clientes = 0;

            document.querySelectorAll('.client-group').forEach(grupo => {
                const nomeCliente = grupo.getAttribute('data-cliente');
                const grupoVisivel = selecionado === 'ativas' || selecionado === 'todos' || nomeCliente === selecionado;
                let n = 0;
                grupo.querySelectorAll('tr.obra-row').forEach(tr => {
                    const mostra = grupoVisivel && (selecionado !== 'ativas' || tr.dataset.ativa === '1');
                    tr.style.display = mostra ? '' : 'none';
                    if (mostra) n++;
                });
                grupo.style.display = n > 0 ? '' : 'none';
                const conta = grupo.querySelector('.conta-grupo');
                if (conta) conta.textContent = n + ' obra(s)' + (selecionado === 'ativas' ? ' ativa(s)' : '');
                total += n; if (n > 0) clientes++;
            });

            placeholder.style.display = total === 0 ? '' : 'none';
            document.getElementById('placeholder_txt').textContent = selecionado === 'ativas'
                ? 'Não há obras ativas. Escolha "Todas as obras" ou um cliente no menu acima.'
                : 'Sem obras para mostrar.';
            document.getElementById('contagem_obras').textContent = total + ' obra(s)' + (clientes > 1 ? ' · ' + clientes + ' clientes' : '');
        }
        filtrarCliente();

        // --- MÉTODOS DO MODAL ---
        function abrirModal(id, nome) {
            document.getElementById('id_obra_m').value = id;
            document.getElementById('txt_modal').innerText = nome;
            document.getElementById('modalApagar').classList.add('show');
            document.querySelector('#modalApagar input[type="password"]').value = '';
            document.querySelector('#modalApagar input[type="password"]').focus();
        }
        function fecharModal() { document.getElementById('modalApagar').classList.remove('show'); }
        window.onclick = function(e) { if(e.target.id == 'modalApagar') fecharModal(); }

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