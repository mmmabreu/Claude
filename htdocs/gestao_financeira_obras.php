<?php
session_start();
require_once 'db.php';

// REGRA DE OURO: Validação de sessão e funções centralizadas
if (file_exists('functions.php')) { 
    require_once 'functions.php'; 
} else { 
    function registrarLog($pdo, $acao, $modulo) { return true; } 
}

if (!isset($_SESSION['user_id'])) { header("Location: index.php"); exit; }

$perfil_user = $_SESSION['perfil'] ?? '';
$is_admin = in_array($perfil_user, ['admin', 'administrativo']);
if (!$is_admin) { header("Location: index.php?erro=sem_permissao"); exit; }

$primeiro_nome = explode(' ', trim($_SESSION['nome'] ?? 'Utilizador'))[0];

// --- AJAX ENDPOINTS ---
if (isset($_GET['ajax_update'])) {
    header('Content-Type: application/json');
    $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
    $campo = $_POST['campo'] ?? '';
    $valor = $_POST['valor'] ?? '';

    try {
        if ($_GET['ajax_update'] === 'despesa' && $id) {
            $stmt = $pdo->prepare("UPDATE obra_despesas SET $campo = ? WHERE id = ?");
            $stmt->execute([$valor, $id]);
        } elseif ($_GET['ajax_update'] === 'obra' && $id) {
            $stmt = $pdo->prepare("UPDATE obras SET $campo = ? WHERE id = ?");
            $stmt->execute([$valor, $id]);
        }
        echo json_encode(['status' => 'success']);
    } catch (Exception $e) { echo json_encode(['status' => 'error', 'message' => $e->getMessage()]); }
    exit;
}

// Função auxiliar para formatação
function formatNumberToPT($num, $dec = 2) {
    if ($num === null || $num === '') return '';
    return number_format((float)$num, $dec, ',', ' ');
}

$obra_id = filter_input(INPUT_GET, 'id_obra', FILTER_VALIDATE_INT);

// Busca todas as obras
$obras_lista = [];
try {
    $obras_lista = $pdo->query("SELECT id, referencia_obra, nome, estado FROM obras ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    die("Erro ao carregar obras: " . $e->getMessage());
}

$dados_obra = null;
$despesas_agrupadas = [];
$total_gasto = 0;
$total_gasto_mo = 0;
$total_gasto_mat = 0;

$tipos_mo_desloc_ref = ['deslocação', 'deslocacao', 'mão de obra', 'mao de obra', 'mão de obra extra', 'mao de obra extra', 'refeição', 'refeicao'];

if ($obra_id) {
    try {
        // Dados da Obra + Orçamento (Agora incluímos também os valores isolados de MO e Material do orçamento)
        $stmt = $pdo->prepare("SELECT o.*, 
                                      orc.valor_total_liquido as v_adjudicado, 
                                      orc.valor_custo_real as c_previsto,
                                      orc.mao_obra as c_mo_previsto,
                                      orc.valor_total_custo as c_mat_previsto
                               FROM obras o 
                               LEFT JOIN orcamentos orc ON o.id_orcamento = orc.id 
                               WHERE o.id = ?");
        $stmt->execute([$obra_id]);
        $dados_obra = $stmt->fetch(PDO::FETCH_ASSOC);

        // Despesas
        $stmt_d = $pdo->prepare("SELECT * FROM obra_despesas WHERE obra_id = ? ORDER BY tipo_despesa ASC, data_despesa DESC, id DESC");
        $stmt_d->execute([$obra_id]);
        
        while ($d = $stmt_d->fetch(PDO::FETCH_ASSOC)) {
            $tipo = trim($d['tipo_despesa']) ?: 'Geral / Não Especificado';
            $tipo_lower = mb_strtolower($tipo, 'UTF-8');
            $valor = (float)$d['valor'];
            
            // Agrupar na tabela
            if (!isset($despesas_agrupadas[$tipo])) {
                $despesas_agrupadas[$tipo] = ['total' => 0, 'itens' => []];
            }
            $despesas_agrupadas[$tipo]['itens'][] = $d;
            $despesas_agrupadas[$tipo]['total'] += $valor;
            
            // Separar para a Análise de Desvios
            if (in_array($tipo_lower, $tipos_mo_desloc_ref)) {
                $total_gasto_mo += $valor;
            } else {
                $total_gasto_mat += $valor;
            }
            
            $total_gasto += $valor;
        }
    } catch (Exception $e) {
        $mensagem_erro = "Erro ao carregar dados: " . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="pt-PT">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestão Financeira - Gestão MAB</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        * { font-family: system-ui, -apple-system, sans-serif; box-sizing: border-box; }
        body { background-color: #f7f9fc; margin: 0; color: #333; font-size: 14px; padding-bottom: 50px; }
        input, select, textarea, button { font-family: inherit; font-size: 14px; outline: none; }
        
        .container { max-width: 1300px; margin: 20px auto; padding: 0 20px; }
        .topbar { background: #fff; padding: 10px 40px; box-shadow: 0 2px 10px rgba(0,0,0,0.05); display: flex; justify-content: space-between; align-items: center; position: sticky; top:0; z-index:100;}
        .card { background: #fff; border-radius: 12px; padding: 20px; border: 1px solid #e1e5ea; margin-bottom: 20px; box-shadow: 0 2px 5px rgba(0,0,0,0.02); }
        
        .grid-3 { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 20px; margin-bottom:20px;}
        .grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom:20px;}
        
        h2 { font-size: 14px; text-transform: uppercase; color: #27ae60; border-bottom: 2px solid #f1f2f6; padding-bottom: 10px; margin-top: 0; display: flex; align-items: center; gap: 8px; }
        
        .kpi-box { text-align: center; padding: 15px; border-radius: 8px; background: #f8f9fa; border:1px solid #eee; }
        .kpi-label { font-size: 11px; font-weight: 700; color: #7f8c8d; text-transform: uppercase; }
        .kpi-value { font-size: 20px; font-weight: 800; display: block; margin-top: 5px; }
        
        /* Barras de Progresso para Análise de Desvios */
        .progress-container { background: #f1f2f6; border-radius: 8px; height: 12px; width: 100%; overflow: hidden; margin-top: 15px; position: relative;}
        .progress-bar { height: 100%; border-radius: 8px; transition: width 0.5s ease; }
        .progress-bar.bg-green { background: #2ecc71; }
        .progress-bar.bg-yellow { background: #f1c40f; }
        .progress-bar.bg-red { background: #e74c3c; }
        .desvio-info { display: flex; justify-content: space-between; font-size: 13px; font-weight: 600; margin-top: 8px; color: #576574; }
        
        /* Tabela Expansível */
        .table-responsive { width: 100%; overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; min-width: 800px; }
        th { padding: 12px; background: #f1f2f6; color: #576574; font-size: 11px; text-transform: uppercase; text-align: left; border-bottom: 2px solid #e1e5ea; }
        td { padding: 10px 12px; vertical-align: middle; color: #2c3e50; }
        
        .group-header td { background-color: #fdfdfd; border-bottom: 2px solid #e1e5ea; border-top: 1px solid #e1e5ea; cursor: pointer; transition: 0.2s;}
        .group-header:hover td { background-color: #f1f2f6; }
        .group-title { font-weight: 800; color: #27ae60; text-transform: uppercase; display: flex; align-items: center; gap: 10px;}
        .group-count { font-size: 11px; background: #e8f5e9; color: #2ecc71; padding: 2px 8px; border-radius: 12px; border: 1px solid #c8e6c9;}
        .icon-toggle { width: 24px; height: 24px; display: inline-flex; align-items: center; justify-content: center; background: #e1e5ea; border-radius: 4px; color: #576574; font-size: 12px; transition: 0.2s;}
        .group-header:hover .icon-toggle { background: #34495e; color: #fff;}
        .group-total { font-weight: 800; color: #c0392b; font-size: 15px; }

        .detail-row td { border-bottom: 1px dashed #f1f2f6; background-color: #fff; padding-left: 20px;}
        .detail-row:hover td { background-color: #fafafa; }
        .detail-row:last-child td { border-bottom: none; }
        
        .text-center { text-align: center !important; }
        .text-right { text-align: right !important; }
        .badge-origem { font-size: 9px; padding: 2px 4px; border-radius: 3px; background: #e1e5ea; color: #7f8c8d; margin-left: 5px; text-transform: uppercase;}
        
        tfoot td { font-weight: 800; font-size: 16px; color: #2c3e50; border-top: 3px solid #bdc3c7; background: #fdfdfd; padding-top: 20px;}
        
        /* Formulários nas Tabelas */
        .btn-pago { padding: 4px 10px; border-radius: 4px; border: 1px solid #ddd; cursor: pointer; font-size: 11px; font-weight: bold; transition: 0.2s; width: 100px; display:inline-flex; align-items:center; justify-content:center; gap:5px;}
        .pago-sim { background: #eafaf1; color: #27ae60; border-color: #2ecc71; }
        .pago-nao { background: #fef5e7; color: #d68910; border-color: #f39c12; }
        .pago-sim:hover { background: #d5f5e3; }
        .pago-nao:hover { background: #fae5d3; }
        
        input[type="date"], input[type="number"] { padding: 6px; border: 1px solid #dcdde1; border-radius: 4px; font-size: 12px; color: #2c3e50; width: 120px;}
        input:focus { border-color: #3498db; }
        
        .form-group { margin-bottom: 10px; }
        .form-group label { display:block; font-size:11px; font-weight:700; color:#576574; margin-bottom:5px; text-transform:uppercase;}
        
        @media (max-width: 768px) { 
            .grid-3 { grid-template-columns: 1fr; } 
            .grid-2 { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>

    <header class="topbar">
        <div class="topbar-left"><img src="mab.png" height="35"></div>
        <div class="topbar-right">
            <a href="gestao.php" style="text-decoration:none; color:#576574; font-weight:bold; font-size:15px;"><i class="fa-solid fa-arrow-left"></i> Voltar</a>
        </div>
    </header>

    <div class="container">
        
        <?php if (isset($mensagem_erro)): ?>
            <div style="background: #fdeded; color: #c0392b; padding: 15px; border-radius: 8px; margin-bottom: 20px; text-align: center; font-weight: bold; border: 1px solid #f9cccc;">
                <i class="fa-solid fa-triangle-exclamation"></i> <?= htmlspecialchars($mensagem_erro) ?>
            </div>
        <?php endif; ?>

        <div class="card">
            <h2 style="border:none; padding:0; margin-bottom:10px;"><i class="fa-solid fa-helmet-safety"></i> Seleção de Obra</h2>
            <select style="width:100%; padding:12px; font-size:15px; border-radius:8px; border:1px solid #bdc3c7; outline:none; background:#f8f9fa;" onchange="location.href='?id_obra='+this.value">
                <option value="">-- Escolha uma obra para análise financeira --</option>
                <?php foreach ($obras_lista as $o): ?>
                    <option value="<?= $o['id'] ?>" <?= ($obra_id == $o['id']) ? 'selected' : '' ?>>REF: <?= $o['referencia_obra'] ?> - <?= $o['nome'] ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <?php if ($dados_obra): ?>
            
            <div class="grid-3">
                <div class="card">
                    <h2><i class="fa-solid fa-chart-pie"></i> Balanço Geral</h2>
                    <canvas id="chartBalanço" style="max-height: 200px;"></canvas>
                </div>

                <div class="card">
                    <h2><i class="fa-solid fa-file-invoice"></i> Faturação ao Cliente</h2>
                    <div style="display:grid; gap:10px;">
                        <div class="form-group">
                            <label>Valor Faturado (€)</label>
                            <input type="number" step="0.01" class="update-obra" data-campo="valor_faturado" value="<?= $dados_obra['valor_faturado'] ?>" style="width:100%; padding:8px;">
                        </div>
                        <div class="form-group">
                            <label>Data Faturação</label>
                            <input type="date" class="update-obra" data-campo="data_faturacao" value="<?= $dados_obra['data_faturacao'] ?>" style="width:100%; padding:8px;">
                        </div>
                        <div style="display:flex; gap:10px; align-items:flex-end;">
                            <div class="form-group" style="flex:1;">
                                <label>Estado Recebimento</label>
                                <button class="btn-pago <?= $dados_obra['pago_cliente'] ? 'pago-sim' : 'pago-nao' ?>" style="width:100%; padding:8px;" onclick="toggleStatus(this, 'obra', <?= $dados_obra['id'] ?>, 'pago_cliente')">
                                    <?= $dados_obra['pago_cliente'] ? '<i class="fa-solid fa-check-double"></i> RECEBIDO' : '<i class="fa-regular fa-clock"></i> A RECEBER' ?>
                                </button>
                            </div>
                            <div class="form-group" style="flex:1;">
                                <label>Data Recebimento</label>
                                <input type="date" class="update-obra" data-campo="data_recebimento" value="<?= $dados_obra['data_recebimento'] ?>" style="width:100%; padding:8px;">
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card">
                    <h2><i class="fa-solid fa-gauge-high"></i> KPIs de Rentabilidade</h2>
                    <div style="display:grid; grid-template-columns: 1fr 1fr; gap:10px;">
                        <div class="kpi-box">
                            <span class="kpi-label">Orc. Adjudicado</span>
                            <span class="kpi-value" style="color:#3498db;"><?= formatNumberToPT($dados_obra['v_adjudicado']) ?>€</span>
                        </div>
                        <div class="kpi-box">
                            <span class="kpi-label">Custo Orçamentado</span>
                            <span class="kpi-value" style="color:#7f8c8d;"><?= formatNumberToPT($dados_obra['c_previsto']) ?>€</span>
                        </div>
                        <div class="kpi-box">
                            <span class="kpi-label">Total Gasto (Real)</span>
                            <span class="kpi-value" style="color:#e67e22;"><?= formatNumberToPT($total_gasto) ?>€</span>
                        </div>
                        <div class="kpi-box" style="background:#eafaf1; border-color:#2ecc71;">
                            <span class="kpi-label">Margem Real</span>
                            <?php $margem = $dados_obra['v_adjudicado'] - $total_gasto; ?>
                            <span class="kpi-value" style="color:<?= $margem >= 0 ? '#27ae60' : '#e74c3c' ?>;"><?= formatNumberToPT($margem) ?>€</span>
                        </div>
                    </div>
                </div>
            </div>

            <div class="grid-2">
                
                <div class="card">
                    <h2 style="color:#34495e; border-bottom:none; margin-bottom:0;"><i class="fa-solid fa-users-gear"></i> Mão de Obra e Deslocações</h2>
                    <?php 
                        $mo_previsto = (float)$dados_obra['c_mo_previsto'];
                        $perc_mo = $mo_previsto > 0 ? ($total_gasto_mo / $mo_previsto) * 100 : ($total_gasto_mo > 0 ? 100 : 0);
                        $bg_class_mo = $perc_mo > 100 ? 'bg-red' : ($perc_mo > 80 ? 'bg-yellow' : 'bg-green');
                        $desvio_mo = $mo_previsto - $total_gasto_mo;
                    ?>
                    <div style="display:flex; justify-content:space-between; margin-top:15px;">
                        <div><span style="color:#7f8c8d; font-size:12px;">Orçamentado:</span><br><strong style="font-size:18px;"><?= formatNumberToPT($mo_previsto) ?> €</strong></div>
                        <div class="text-right"><span style="color:#7f8c8d; font-size:12px;">Gasto Real:</span><br><strong style="font-size:18px; color:#e67e22;"><?= formatNumberToPT($total_gasto_mo) ?> €</strong></div>
                    </div>
                    
                    <div class="progress-container">
                        <div class="progress-bar <?= $bg_class_mo ?>" style="width: <?= min($perc_mo, 100) ?>%;"></div>
                    </div>
                    
                    <div class="desvio-info">
                        <span><?= formatNumberToPT($perc_mo, 1) ?>% Consumido</span>
                        <span style="color: <?= $desvio_mo >= 0 ? '#27ae60' : '#e74c3c' ?>;">
                            Desvio: <?= $desvio_mo >= 0 ? '+' : '' ?><?= formatNumberToPT($desvio_mo) ?> €
                        </span>
                    </div>
                </div>

                <div class="card">
                    <h2 style="color:#34495e; border-bottom:none; margin-bottom:0;"><i class="fa-solid fa-boxes-stacked"></i> Materiais e Subcontratos</h2>
                    <?php 
                        $mat_previsto = (float)$dados_obra['c_mat_previsto'];
                        $perc_mat = $mat_previsto > 0 ? ($total_gasto_mat / $mat_previsto) * 100 : ($total_gasto_mat > 0 ? 100 : 0);
                        $bg_class_mat = $perc_mat > 100 ? 'bg-red' : ($perc_mat > 80 ? 'bg-yellow' : 'bg-green');
                        $desvio_mat = $mat_previsto - $total_gasto_mat;
                    ?>
                    <div style="display:flex; justify-content:space-between; margin-top:15px;">
                        <div><span style="color:#7f8c8d; font-size:12px;">Orçamentado:</span><br><strong style="font-size:18px;"><?= formatNumberToPT($mat_previsto) ?> €</strong></div>
                        <div class="text-right"><span style="color:#7f8c8d; font-size:12px;">Gasto Real:</span><br><strong style="font-size:18px; color:#e67e22;"><?= formatNumberToPT($total_gasto_mat) ?> €</strong></div>
                    </div>
                    
                    <div class="progress-container">
                        <div class="progress-bar <?= $bg_class_mat ?>" style="width: <?= min($perc_mat, 100) ?>%;"></div>
                    </div>
                    
                    <div class="desvio-info">
                        <span><?= formatNumberToPT($perc_mat, 1) ?>% Consumido</span>
                        <span style="color: <?= $desvio_mat >= 0 ? '#27ae60' : '#e74c3c' ?>;">
                            Desvio: <?= $desvio_mat >= 0 ? '+' : '' ?><?= formatNumberToPT($desvio_mat) ?> €
                        </span>
                    </div>
                </div>

            </div>

            <div class="card">
                <h2 style="display: flex; justify-content: space-between; align-items: center;">
                    <div><i class="fa-solid fa-list-check"></i> Detalhe de Custos Agrupados</div>
                    <button type="button" onclick="expandirTudo()" style="background:none; border:none; color:#3498db; cursor:pointer; font-weight:600; font-size:12px; display:flex; gap:5px; align-items:center;">
                        <i class="fa-solid fa-up-down"></i> Expandir/Encolher Tudo
                    </button>
                </h2>
                
                <?php if (empty($despesas_agrupadas)): ?>
                    <div style="text-align: center; padding: 40px; color: #95a5a6;">
                        <i class="fa-solid fa-folder-open" style="font-size:40px; color:#dcdde1; margin-bottom:15px;"></i>
                        <h3>Sem despesas registadas</h3>
                        <p>Ainda não foram adicionadas despesas materiais ou subcontratações a esta obra.</p>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table>
                            <thead>
                                <tr>
                                    <th width="40"></th>
                                    <th width="100">Data</th>
                                    <th>Descrição / Fornecedor</th>
                                    <th width="80" class="text-center">Qtd.</th>
                                    <th width="120" class="text-center">Estado Pago</th>
                                    <th width="140" class="text-center">Data Pagam.</th>
                                    <th width="130" class="text-right">Valor Total (€)</th>
                                </tr>
                            </thead>
                            
                            <?php $group_idx = 0; ?>
                            <?php foreach ($despesas_agrupadas as $tipo => $grupo): $group_idx++; ?>
                                
                                <tbody class="group-header" onclick="toggleGroup(<?= $group_idx ?>)">
                                    <tr>
                                        <td class="text-center">
                                            <span class="icon-toggle" id="icon-<?= $group_idx ?>"><i class="fa-solid fa-plus"></i></span>
                                        </td>
                                        <td colspan="5">
                                            <div class="group-title">
                                                <?= htmlspecialchars($tipo) ?> 
                                                <span class="group-count"><?= count($grupo['itens']) ?> registo(s)</span>
                                            </div>
                                        </td>
                                        <td class="text-right group-total">
                                            <?= formatNumberToPT($grupo['total'], 2) ?> €
                                        </td>
                                    </tr>
                                </tbody>
                                
                                <tbody class="group-details" id="details-<?= $group_idx ?>" style="display: none;">
                                    <?php foreach ($grupo['itens'] as $item): ?>
                                        <?php 
                                            // Identificar se a despesa atual está isenta de tracking de pagamentos
                                            $tipo_lower = mb_strtolower(trim($item['tipo_despesa']), 'UTF-8');
                                            $is_isento_pagamento = in_array($tipo_lower, $tipos_mo_desloc_ref);
                                        ?>
                                        <tr class="detail-row">
                                            <td></td>
                                            <td style="color:#7f8c8d; font-size:13px;"><i class="fa-regular fa-calendar"></i> <?= date('d/m/Y', strtotime($item['data_despesa'])) ?></td>
                                            <td>
                                                <?= htmlspecialchars($item['descricao']) ?>
                                                <?php if (!empty($item['origem'])): ?>
                                                    <span class="badge-origem"><?= htmlspecialchars($item['origem']) ?></span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-center" style="color:#576574; font-weight:bold;"><?= formatNumberToPT($item['quantidade'], 2) ?></td>
                                            
                                            <td class="text-center">
                                                <?php if ($is_isento_pagamento): ?>
                                                    <span style="color:#bdc3c7; font-size:11px; font-weight:bold;">N/A</span>
                                                <?php else: ?>
                                                    <button class="btn-pago <?= $item['pago'] ? 'pago-sim' : 'pago-nao' ?>" 
                                                            onclick="toggleStatus(this, 'despesa', <?= $item['id'] ?>, 'pago')">
                                                        <?= $item['pago'] ? '<i class="fa-solid fa-check"></i> PAGO' : '<i class="fa-solid fa-clock"></i> PEND.' ?>
                                                    </button>
                                                <?php endif; ?>
                                            </td>
                                            
                                            <td class="text-center">
                                                <?php if ($is_isento_pagamento): ?>
                                                    <span style="color:#bdc3c7; font-size:11px;">-</span>
                                                <?php else: ?>
                                                    <input type="date" class="update-despesa" data-id="<?= $item['id'] ?>" data-campo="data_pagamento" value="<?= $item['data_pagamento'] ?>">
                                                <?php endif; ?>
                                            </td>
                                            
                                            <td class="text-right" style="color:#e74c3c; font-weight:600;"><?= formatNumberToPT($item['valor'], 2) ?> €</td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                                
                            <?php endforeach; ?>

                            <tfoot>
                                <tr>
                                    <td colspan="6" class="text-right">Custo Total de Despesas Registadas:</td>
                                    <td class="text-right" style="color: #c0392b; font-size: 18px;"><?= formatNumberToPT($total_gasto, 2) ?> €</td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
            
        <?php elseif (!isset($mensagem_erro)): ?>
            <div style="text-align:center; background: #fff; border-radius: 12px; border: 1px dashed #dcdde1; padding: 60px 20px;">
                <i class="fa-solid fa-hand-pointer" style="color:#bdc3c7; font-size:40px; margin-bottom:15px;"></i>
                <h3 style="color:#7f8c8d; margin-top:0;">Selecione uma obra acima</h3>
                <p style="color:#95a5a6;">Escolha um projeto no menu para visualizar as despesas e a rentabilidade financeira associada agrupada por tipo.</p>
            </div>
        <?php endif; ?>

    </div>

    <script>
        // --- FUNÇÕES DE EXPANSÃO DA TABELA ---
        let allExpanded = false;

        function toggleGroup(id) {
            const details = document.getElementById('details-' + id);
            const icon = document.getElementById('icon-' + id).querySelector('i');
            
            if (details.style.display === 'none' || details.style.display === '') {
                details.style.display = 'table-row-group';
                icon.classList.replace('fa-plus', 'fa-minus');
            } else {
                details.style.display = 'none';
                icon.classList.replace('fa-minus', 'fa-plus');
            }
        }

        function expandirTudo() {
            allExpanded = !allExpanded;
            const detailsList = document.querySelectorAll('.group-details');
            const icons = document.querySelectorAll('.icon-toggle i');

            detailsList.forEach(details => {
                details.style.display = allExpanded ? 'table-row-group' : 'none';
            });

            icons.forEach(icon => {
                if (allExpanded) {
                    icon.classList.replace('fa-plus', 'fa-minus');
                } else {
                    icon.classList.replace('fa-minus', 'fa-plus');
                }
            });
        }

        // --- GRÁFICO DE BARRAS ---
        <?php if ($dados_obra): ?>
        new Chart(document.getElementById('chartBalanço'), {
            type: 'bar',
            data: {
                labels: ['Orçamentado', 'Gasto Real', 'Faturado Cliente'],
                datasets: [{
                    label: 'Valores em €',
                    data: [
                        <?= (float)$dados_obra['c_previsto'] ?>, 
                        <?= $total_gasto ?>, 
                        <?= (float)$dados_obra['valor_faturado'] ?>
                    ],
                    backgroundColor: ['#95a5a6', '#e67e22', '#3498db'],
                    borderRadius: 4
                }]
            },
            options: { 
                responsive: true, 
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: { y: { beginAtZero: true } }
            }
        });
        <?php endif; ?>

        // --- AJAX PARA ATUALIZAÇÃO AUTOMÁTICA DOS CAMPOS ---
        document.querySelectorAll('.update-obra, .update-despesa').forEach(el => {
            el.addEventListener('change', async function() {
                const tipo = this.classList.contains('update-obra') ? 'obra' : 'despesa';
                const id = tipo === 'obra' ? <?= $obra_id ?: 0 ?> : this.dataset.id;
                const campo = this.dataset.campo;
                const valor = this.value;

                const fd = new FormData();
                fd.append('id', id);
                fd.append('campo', campo);
                fd.append('valor', valor);

                try {
                    const res = await fetch('?ajax_update=' + tipo, { method: 'POST', body: fd });
                    const json = await res.json();
                    
                    if (json.status !== 'success') {
                        alert('Erro ao gravar os dados: ' + json.message);
                    } else if (tipo === 'obra' && campo === 'valor_faturado') {
                        // Se atualizou o valor faturado, faz reload para o gráfico atualizar
                        location.reload(); 
                    } else {
                        // Faz piscar o campo a verde para dar feedback visual de sucesso
                        this.style.backgroundColor = '#eafaf1';
                        setTimeout(() => this.style.backgroundColor = '#fff', 500);
                    }
                } catch(e) {
                    alert('Erro de comunicação.');
                }
            });
        });

        // --- AJAX PARA BOTÕES DE ESTADO (PAGO/PENDENTE) ---
        async function toggleStatus(btn, tipo, id, campo) {
            const isPago = btn.classList.contains('pago-sim');
            const novoValor = isPago ? 0 : 1;
            
            const fd = new FormData();
            fd.append('id', id);
            fd.append('campo', campo);
            fd.append('valor', novoValor);

            try {
                // Modifica visualmente na hora (Optimistic UI)
                if (novoValor === 1) {
                    btn.classList.remove('pago-nao');
                    btn.classList.add('pago-sim');
                    btn.innerHTML = (tipo === 'obra') ? '<i class="fa-solid fa-check-double"></i> RECEBIDO' : '<i class="fa-solid fa-check"></i> PAGO';
                } else {
                    btn.classList.remove('pago-sim');
                    btn.classList.add('pago-nao');
                    btn.innerHTML = (tipo === 'obra') ? '<i class="fa-regular fa-clock"></i> A RECEBER' : '<i class="fa-solid fa-clock"></i> PEND.';
                }

                const res = await fetch('?ajax_update=' + tipo, { method: 'POST', body: fd });
                const json = await res.json();
                
                if (json.status !== 'success') {
                    alert('Erro na gravação.');
                    location.reload(); // Reverte a UI em caso de erro
                }
            } catch(e) {
                alert('Erro de comunicação.');
                location.reload();
            }
        }
    </script>
</body>
</html>