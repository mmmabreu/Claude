<?php
session_start();
require_once 'db.php';
require_once 'functions.php'; // REGRA DE OURO: Funções centralizadas

// REGRA DE OURO: Validação de sessão
if (!isset($_SESSION['user_id'])) { header("Location: index.php"); exit; }

$perfil_user = $_SESSION['perfil'] ?? '';
$is_admin = in_array($perfil_user, ['admin', 'administrativo']);

if (!$is_admin) { header("Location: index.php?erro=sem_permissao"); exit; }

// REGRA DE OURO: 1º Nome no Avatar
$nome_completo = $_SESSION['nome'] ?? 'Utilizador';
$primeiro_nome = explode(' ', trim($nome_completo))[0];

$mensagem = '';
$tipo_mensagem = '';

// 🛡️ GARANTIR QUE A COLUNA FÉRIAS EXISTE (Para não dar erro no Select)
try {
    $check_col = $pdo->query("SHOW COLUMNS FROM utilizadores LIKE 'ferias_marcadas'");
    if ($check_col->rowCount() == 0) {
        $pdo->exec("ALTER TABLE utilizadores ADD COLUMN ferias_marcadas TEXT DEFAULT NULL");
    }
} catch (Exception $e) {}

// --- GESTÃO DE FÉRIAS (PROCESSAR POST) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['id_utilizador_ferias'])) {
    $id_u = (int)$_POST['id_utilizador_ferias'];
    $acao = $_POST['acao_ferias'] ?? '';

    // Buscar as férias atuais
    $stmt_f = $pdo->prepare("SELECT ferias_marcadas FROM utilizadores WHERE id = ?");
    $stmt_f->execute([$id_u]);
    $curr_ferias_json = $stmt_f->fetchColumn();
    $arr_ferias = $curr_ferias_json ? json_decode($curr_ferias_json, true) : [];
    if (!is_array($arr_ferias)) $arr_ferias = [];

    if ($acao === 'add') {
        $inicio = $_POST['data_inicio'] ?? '';
        $dias = (int)($_POST['dias_ferias'] ?? 0);
        
        if (!empty($inicio) && $dias > 0) {
            $arr_ferias[] = ['inicio' => $inicio, 'dias' => $dias];
            $pdo->prepare("UPDATE utilizadores SET ferias_marcadas = ? WHERE id = ?")->execute([json_encode($arr_ferias), $id_u]);
            $mensagem = "Período de férias adicionado com sucesso!";
            $tipo_mensagem = "sucesso";
        } else {
            $mensagem = "Dados de férias inválidos.";
            $tipo_mensagem = "erro";
        }
    } elseif ($acao === 'del') {
        $idx = (int)($_POST['index_feria'] ?? -1);
        if (isset($arr_ferias[$idx])) {
            unset($arr_ferias[$idx]);
            $arr_ferias = array_values($arr_ferias); // Reordenar os índices
            $pdo->prepare("UPDATE utilizadores SET ferias_marcadas = ? WHERE id = ?")->execute([json_encode($arr_ferias), $id_u]);
            $mensagem = "Período de férias removido com sucesso!";
            $tipo_mensagem = "sucesso";
        }
    }
}

$mes_filtro = filter_input(INPUT_GET, 'mes', FILTER_VALIDATE_INT) ?: (int)date('m');
$ano_filtro = filter_input(INPUT_GET, 'ano', FILTER_VALIDATE_INT) ?: (int)date('Y');

$meses = [1 => 'Janeiro', 2 => 'Fevereiro', 3 => 'Março', 4 => 'Abril', 5 => 'Maio', 6 => 'Junho', 7 => 'Julho', 8 => 'Agosto', 9 => 'Setembro', 10 => 'Outubro', 11 => 'Novembro', 12 => 'Dezembro'];

function calcHoras($entrada, $saida) {
    if (empty($entrada) || empty($saida)) return 0;
    $t1 = strtotime($entrada);
    $t2 = strtotime($saida);
    if ($t2 < $t1) return 0;
    return ($t2 - $t1) / 3600;
}

// --- LER FERIADOS E PONTES DAS CONFIGURAÇÕES ---
$stmt_fer = $pdo->prepare("SELECT valor FROM configuracoes WHERE chave = 'dias_feriados' LIMIT 1");
$stmt_fer->execute();
$feriados_json = $stmt_fer->fetchColumn();
$feriados_array = $feriados_json ? json_decode($feriados_json, true) : [];
if (!is_array($feriados_array)) $feriados_array = [];

// Descobrir quantos dias tem o mês selecionado e mapear os fins de semana
$dias_no_mes = cal_days_in_month(CAL_GREGORIAN, $mes_filtro, $ano_filtro);
$fins_de_semana = [];
for($d = 1; $d <= $dias_no_mes; $d++) {
    $data_str = sprintf("%04d-%02d-%02d", $ano_filtro, $mes_filtro, $d);
    $dia_semana = date('N', strtotime($data_str));
    $fins_de_semana[$d] = ($dia_semana >= 6); // 6 = Sábado, 7 = Domingo
}

$erro_db = '';
$funcionarios = [];
$mapa_diario = [];
$totais = ['horas' => 0, 'qtd_almocos' => 0, 'valor_almocos' => 0];

try {
    // 1. Obter todos os utilizadores ativos e as suas FÉRIAS!
    $stmt_users = $pdo->query("SELECT id, nome, perfil, ferias_marcadas FROM utilizadores WHERE ativo = 1 AND nome NOT LIKE '%Isabel%' ORDER BY nome ASC");
    
    while ($row = $stmt_users->fetch(PDO::FETCH_ASSOC)) {
        $funcionarios[$row['id']] = [
            'nome' => $row['nome'], 
            'perfil' => $row['perfil'], 
            'horas' => 0, 
            'qtd_almocos' => 0,
            'valor_almocos' => 0,
            'ferias_json' => $row['ferias_marcadas']
        ];
        
        // --- MOTOR DE FÉRIAS ---
        $ferias_json = $row['ferias_marcadas'];
        $ferias_array = [];
        if (!empty($ferias_json)) {
            $arr = json_decode($ferias_json, true);
            if (is_array($arr)) $ferias_array = $arr;
        }
        
        $dias_de_ferias_deste_mes = [];
        foreach ($ferias_array as $periodo) {
            if (!empty($periodo['inicio']) && !empty($periodo['dias'])) {
                try {
                    $data_inicio = new DateTime($periodo['inicio']);
                    $num_dias = (int)$periodo['dias'];
                    
                    // Varre todos os dias das férias deste bloco
                    for ($i = 0; $i < $num_dias; $i++) {
                        $data_atual = clone $data_inicio;
                        if ($i > 0) $data_atual->modify("+$i days");
                        
                        // O dia das férias calha no mês e ano que estamos a consultar?
                        if ((int)$data_atual->format('m') === $mes_filtro && (int)$data_atual->format('Y') === $ano_filtro) {
                            $dia_feria = (int)$data_atual->format('j');
                            $dias_de_ferias_deste_mes[$dia_feria] = true;
                        }
                    }
                } catch (Exception $e) { /* Ignora se houver lixo no JSON */ }
            }
        }
        
        // Iniciar array do mapa diário já com a tag das Férias
        for($d = 1; $d <= $dias_no_mes; $d++) {
            $is_ferias = isset($dias_de_ferias_deste_mes[$d]);
            $mapa_diario[$row['id']][$d] = [
                'horas' => 0, 
                'almoco' => 0, 
                'is_ferias' => $is_ferias
            ];
        }
    }

    // 2. Processar RELATÓRIOS DE OBRA
    $stmt_rel = $pdo->prepare("SELECT colaborador_1, colaborador_2, h1_entrada, h1_saida, h2_entrada, h2_saida, custo_refeicao_1, custo_refeicao_2, data_relatorio FROM relatorios_obra WHERE MONTH(data_relatorio) = ? AND YEAR(data_relatorio) = ?");
    $stmt_rel->execute([$mes_filtro, $ano_filtro]);
    
    while ($r = $stmt_rel->fetch(PDO::FETCH_ASSOC)) {
        $horas = calcHoras($r['h1_entrada'], $r['h1_saida']) + calcHoras($r['h2_entrada'], $r['h2_saida']);
        $c1 = $r['colaborador_1'];
        $c2 = $r['colaborador_2'];
        $dia_registo = (int)date('j', strtotime($r['data_relatorio']));
        
        $alm1 = (float)($r['custo_refeicao_1'] ?? 0);
        $alm2 = (float)($r['custo_refeicao_2'] ?? 0);
        
        // Só contabiliza se o colaborador existir e NÃO ESTIVER DE FÉRIAS NESSE DIA
        if (!empty($c1) && isset($funcionarios[$c1]) && !$mapa_diario[$c1][$dia_registo]['is_ferias']) {
            $funcionarios[$c1]['horas'] += $horas;
            $mapa_diario[$c1][$dia_registo]['horas'] += $horas;
            if ($alm1 > 0) {
                $funcionarios[$c1]['qtd_almocos']++;
                $funcionarios[$c1]['valor_almocos'] += $alm1;
                $mapa_diario[$c1][$dia_registo]['almoco'] += $alm1;
                $totais['qtd_almocos']++;
                $totais['valor_almocos'] += $alm1;
            }
            $totais['horas'] += $horas;
        }
        
        if (!empty($c2) && isset($funcionarios[$c2]) && !$mapa_diario[$c2][$dia_registo]['is_ferias']) {
            $funcionarios[$c2]['horas'] += $horas;
            $mapa_diario[$c2][$dia_registo]['horas'] += $horas;
            if ($alm2 > 0) {
                $funcionarios[$c2]['qtd_almocos']++;
                $funcionarios[$c2]['valor_almocos'] += $alm2;
                $mapa_diario[$c2][$dia_registo]['almoco'] += $alm2;
                $totais['qtd_almocos']++;
                $totais['valor_almocos'] += $alm2;
            }
            $totais['horas'] += $horas;
        }
    }

    // 3. Processar SERVIÇOS
    $stmt_srv = $pdo->prepare("SELECT colaborador_1, colaborador_2, h1_entrada, h1_saida, h2_entrada, h2_saida, custo_refeicao_1, custo_refeicao_2, data_servico FROM servicos WHERE MONTH(data_servico) = ? AND YEAR(data_servico) = ?");
    $stmt_srv->execute([$mes_filtro, $ano_filtro]);
    
    while ($s = $stmt_srv->fetch(PDO::FETCH_ASSOC)) {
        $horas = calcHoras($s['h1_entrada'], $s['h1_saida']) + calcHoras($s['h2_entrada'], $s['h2_saida']);
        $c1 = $s['colaborador_1'];
        $c2 = $s['colaborador_2'];
        $dia_registo = (int)date('j', strtotime($s['data_servico']));
        
        $alm1 = (float)($s['custo_refeicao_1'] ?? 0);
        $alm2 = (float)($s['custo_refeicao_2'] ?? 0);
        
        if (!empty($c1) && isset($funcionarios[$c1]) && !$mapa_diario[$c1][$dia_registo]['is_ferias']) {
            $funcionarios[$c1]['horas'] += $horas;
            $mapa_diario[$c1][$dia_registo]['horas'] += $horas;
            if ($alm1 > 0) {
                $funcionarios[$c1]['qtd_almocos']++;
                $funcionarios[$c1]['valor_almocos'] += $alm1;
                $mapa_diario[$c1][$dia_registo]['almoco'] += $alm1;
                $totais['qtd_almocos']++;
                $totais['valor_almocos'] += $alm1;
            }
            $totais['horas'] += $horas;
        }
        
        if (!empty($c2) && isset($funcionarios[$c2]) && !$mapa_diario[$c2][$dia_registo]['is_ferias']) {
            $funcionarios[$c2]['horas'] += $horas;
            $mapa_diario[$c2][$dia_registo]['horas'] += $horas;
            if ($alm2 > 0) {
                $funcionarios[$c2]['qtd_almocos']++;
                $funcionarios[$c2]['valor_almocos'] += $alm2;
                $mapa_diario[$c2][$dia_registo]['almoco'] += $alm2;
                $totais['qtd_almocos']++;
                $totais['valor_almocos'] += $alm2;
            }
            $totais['horas'] += $horas;
        }
    }

    // 4. Processar VISITAS
    $stmt_vis = $pdo->prepare("SELECT colaborador_1, data_visita FROM visitas WHERE MONTH(data_visita) = ? AND YEAR(data_visita) = ?");
    $stmt_vis->execute([$mes_filtro, $ano_filtro]);
    
    while ($v = $stmt_vis->fetch(PDO::FETCH_ASSOC)) {
        $c1 = $v['colaborador_1'];
        $dia_registo = (int)date('j', strtotime($v['data_visita']));
        
        if (!empty($c1) && isset($funcionarios[$c1]) && !$mapa_diario[$c1][$dia_registo]['is_ferias']) {
            $funcionarios[$c1]['horas'] += 1;
            $mapa_diario[$c1][$dia_registo]['horas'] += 1;
            $totais['horas'] += 1;
        }
    }

} catch (PDOException $e) {
    $erro_db = "Erro na leitura das bases de dados: " . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="pt-PT">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Análise de Funcionários - Gestão MAB</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { font-family: system-ui, -apple-system, sans-serif; background-color: #f7f9fc; margin: 0; color: #333; padding-bottom: 50px; }
        * { box-sizing: border-box; }
        
        /* CABEÇALHO */
        .topbar { background: rgba(255,255,255,0.94); backdrop-filter: blur(8px); -webkit-backdrop-filter: blur(8px); padding: 10px 40px; box-shadow: 0 1px 0 #e8ecf1; display: flex; justify-content: space-between; align-items: center; position: sticky; top: 0; z-index: 100; }
        .topbar-left a { display: block; }
        .topbar-left .logo { height: 35px; display: block; }
        .topbar-right { display: flex; align-items: center; gap: 12px; }
        .btn-voltar { width: 40px; height: 40px; border-radius: 50%; background: #fff; border: 1px solid #e8ecf1; color: #576574; display: inline-flex; align-items: center; justify-content: center; text-decoration: none; font-size: 16px; transition: all 0.2s ease; box-shadow: 0 2px 6px rgba(0,0,0,0.04); flex-shrink: 0; }
        .btn-voltar:hover { background: #eef2f6; color: #34495e; border-color: #d5dce4; transform: translateX(-2px); }
        .btn-voltar:active { transform: scale(0.94); }
        .user-menu { position: relative; }
        .user-btn { background: #fff; border: 1px solid #e8ecf1; border-radius: 30px; font-size: 14px; font-weight: 600; color: #2c3e50; cursor: pointer; display: flex; align-items: center; gap: 10px; padding: 5px 14px 5px 5px; transition: 0.2s; font-family: inherit; }
        .user-btn:hover { box-shadow: 0 4px 12px rgba(0,0,0,0.06); }
        .user-avatar { background: #e74c3c; color: #fff; width: 28px; height: 28px; border-radius: 50%; display: flex; justify-content: center; align-items: center; font-size: 13px; }
        .dropdown-content { display: none; position: absolute; right: 0; top: 100%; background: #fff; min-width: 180px; box-shadow: 0 12px 30px rgba(0,0,0,0.1); border-radius: 10px; border: 1px solid #e8ecf1; overflow: hidden; margin-top: 6px; z-index: 200; }
        .user-menu:hover .dropdown-content, .user-menu.aberto .dropdown-content { display: block; }
        .dropdown-content a { color: #576574; padding: 12px 20px; text-decoration: none; display: block; font-size: 14px; }
        .dropdown-content a:hover { background: #f4f6f8; color: #2c3e50; }
        @media (max-width: 1024px) { .topbar { padding: 10px 24px; } }
        @media (max-width: 768px) { .topbar { padding: 8px 15px; } .topbar-left .logo { height: 30px; } .btn-voltar { width: 38px; height: 38px; } }
        @media (max-width: 560px) { .user-nome, .user-btn .fa-caret-down { display: none; } .user-btn { padding: 4px; } }

        .container { max-width: 1400px; margin: 30px auto; padding: 0 15px; }
        
        .page-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px; flex-wrap: wrap; gap: 15px; }
        .page-title { margin: 0; color: #2c3e50; font-size: 24px; font-weight: 800; display: flex; align-items: center; gap: 10px; }
        .page-title i { color: #34495e; }
        
        .filter-bar { background: #fff; padding: 20px; border-radius: 12px; border: 1px solid #e1e5ea; box-shadow: 0 4px 15px rgba(0,0,0,0.03); margin-bottom: 25px; display: flex; align-items: flex-end; gap: 15px; flex-wrap: wrap;}
        .form-group { display: flex; flex-direction: column; gap: 5px; }
        .form-group label { font-weight: 700; font-size: 11px; color: #576574; text-transform: uppercase; }
        select, input { padding: 10px 15px; border: 1px solid #dcdde1; border-radius: 8px; font-size: 14px; outline: none; background: #fcfcfc; font-family: inherit;}
        select:focus, input:focus { border-color: #34495e; }
        .form-group select { min-width: 150px; }
        .btn-primary { background: #34495e; color: #fff; padding: 10px 25px; border-radius: 8px; border: none; font-weight: 600; cursor: pointer; font-size: 14px; display: inline-flex; align-items: center; gap: 8px; transition: 0.2s; height: 41px; text-decoration: none;}
        .btn-primary:hover { background: #2c3e50; }
        .acoes-topo { display: flex; gap: 10px; flex-wrap: wrap; }
        @media (max-width: 600px) { .acoes-topo { width: 100%; } .acoes-topo .btn-primary { flex: 1; justify-content: center; } .card-title { flex-direction: column; align-items: flex-start; gap: 8px; } }

        .card { background: #fff; border-radius: 12px; box-shadow: 0 4px 15px rgba(0,0,0,0.03); border: 1px solid #e1e5ea; overflow: hidden; margin-bottom: 30px;}
        .card-title { padding: 15px 20px; border-bottom: 1px solid #e1e5ea; font-weight: 800; color: #2c3e50; font-size: 15px; background: #fdfdfd; display: flex; justify-content: space-between; align-items: center;}
        
        /* Tabela Geral */
        table { width: 100%; border-collapse: collapse; }
        th { padding: 15px 20px; background: #f8f9fa; color: #576574; font-size: 11px; text-transform: uppercase; text-align: left; border-bottom: 2px solid #e1e5ea; }
        td { padding: 15px 20px; border-bottom: 1px solid #f1f2f6; font-size: 14px; color: #2c3e50; vertical-align: middle; }
        tr:hover { background: #fdfcfb; }
        
        /* Tabela Mapa Diário */
        .tabela-mapa-container { width: 100%; overflow-x: auto; }
        .tabela-mapa th, .tabela-mapa td { padding: 10px 5px; text-align: center; border-right: 1px solid #f1f2f6; font-size: 13px;}
        .tabela-mapa th { font-size: 10px; padding: 12px 5px; }
        .td-nome { text-align: left !important; min-width: 180px; position: sticky; left: 0; background: #fff; z-index: 10; border-right: 2px solid #e1e5ea !important; box-shadow: 2px 0 5px rgba(0,0,0,0.02);}
        .th-nome { position: sticky; left: 0; background: #f8f9fa; z-index: 11; border-right: 2px solid #e1e5ea !important;}
        
        .td-fim-semana { background-color: #f8f9fa; }
        .td-dia-vazio { color: #dcdde1; font-size: 12px;}
        .td-dia-cheio { background-color: #fafbfc;} 
        
        /* ESTILO FÉRIAS E FERIADOS ⛱️🚩 */
        .td-dia-ferias { background-color: #eafaf1 !important; color: #27ae60; font-size: 11px;}
        .td-dia-ferias i { font-size: 16px; color: #27ae60;}

        .td-dia-feriado { background-color: #fcf3cf !important; color: #f39c12; font-size: 11px;}
        .td-dia-feriado i { font-size: 16px; color: #f39c12;}
        
        .lbl-horas { color: #2980b9; font-weight: 800; font-size: 13px; line-height: 1.2;}
        .lbl-almoco { color: #d35400; font-weight: 700; font-size: 10px; margin-top: 3px; background: #fdf2e9; padding: 2px 4px; border-radius: 3px; display: inline-block;}

        .text-center { text-align: center !important; }
        .badge-func { font-weight: 700; color: #34495e; display: flex; align-items: center; gap: 10px; }
        .avatar-mini { background: #e1e5ea; color: #576574; width: 30px; height: 30px; border-radius: 50%; display: flex; justify-content: center; align-items: center; font-size: 12px; font-weight: bold; flex-shrink: 0;}
        .val-destaque { font-weight: 800; }
        .val-horas { color: #3498db; }
        .val-almocos { color: #f39c12; }

        .alert { padding: 15px; border-radius: 8px; margin-bottom: 20px; font-size: 14px; text-align: center; font-weight: bold; }
        .alert-warning { background: #fff3cd; color: #856404; border: 1px solid #ffeeba; }
        .alert-sucesso { background: #e8f5e9; color: #2e7d32; border: 1px solid #c8e6c9; }
        .alert-erro { background: #fdeded; color: #c0392b; border: 1px solid #f9cccc; }

        /* Botão PDF e Férias */
        .btn-pdf { color: #e74c3c; background: #fdeded; border: none; padding: 8px 12px; border-radius: 6px; cursor: pointer; transition: 0.2s; text-decoration: none; display: inline-flex; align-items: center; justify-content: center;}
        .btn-pdf:hover { background: #e74c3c; color: white; }
        
        .btn-ferias { background: #eafaf1; color: #27ae60; border: 1px solid #2ecc71; padding: 6px 12px; border-radius: 6px; font-size: 12px; font-weight: bold; cursor: pointer; transition: 0.2s; }
        .btn-ferias:hover { background: #27ae60; color: white; }

        /* Modal Férias */
        .modal { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.6); z-index: 1001; justify-content: center; align-items: center; padding: 20px; }
        .modal.show { display: flex; }
        .modal-content { background: #fff; padding: 30px; border-radius: 12px; width: 100%; max-width: 450px; text-align: left; }
        .modal-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; border-bottom: 1px solid #f1f2f6; padding-bottom: 10px; }
        .modal-header h3 { margin: 0; color: #27ae60; font-size: 18px; }
        .close-btn { background: none; border: none; font-size: 20px; color: #95a5a6; cursor: pointer; }
        .close-btn:hover { color: #e74c3c; }

        .lista-ferias { margin-bottom: 20px; border: 1px solid #e1e5ea; border-radius: 8px; overflow: hidden; }
        .item-ferias { padding: 12px; border-bottom: 1px solid #f1f2f6; display: flex; justify-content: space-between; align-items: center; background: #fafbfc; font-size: 13px;}
        .item-ferias:last-child { border-bottom: none; }
        .btn-rm-feria { color: #e74c3c; background: none; border: none; cursor: pointer; font-size: 14px; }
        
        .add-ferias-box { background: #fdfdfd; padding: 15px; border-radius: 8px; border: 1px dashed #27ae60; display: flex; gap: 10px; align-items: flex-end;}
        .add-ferias-box .form-group { margin: 0; flex: 1; }
        .btn-adicionar { background: #27ae60; color: white; border: none; padding: 10px 15px; border-radius: 8px; font-weight: bold; cursor: pointer; height: 41px; }

    </style>
</head>
<body>

    <!-- CABEÇALHO (regras do projeto: logo -> início, sem ícones de navegação, voltar só com símbolo) -->
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
            <div class="alert alert-<?= $tipo_mensagem ?>"><?= htmlspecialchars($mensagem) ?></div>
        <?php endif; ?>

        <?php if ($erro_db): ?>
            <div class="alert alert-warning"><i class="fa-solid fa-triangle-exclamation"></i> <?= $erro_db ?></div>
        <?php endif; ?>

        <div class="page-header">
            <h1 class="page-title"><i class="fa-solid fa-users-gear"></i> Análise de Funcionários</h1>
            <div class="acoes-topo">
                <a href="mapa_diario_pdf.php?mes=<?= $mes_filtro ?>&ano=<?= $ano_filtro ?>" target="_blank" class="btn-primary" title="Imprimir a Folha de Ponto / Mapa Diário de <?= $meses[$mes_filtro] ?> <?= $ano_filtro ?>"><i class="fa-solid fa-print"></i> Imprimir Mapa Diário</a>
                <a href="imprimir_todos_funcionarios.php?mes=<?= $mes_filtro ?>&ano=<?= $ano_filtro ?>" class="btn-primary" style="background:#27ae60;"><i class="fa-solid fa-file-zipper"></i> Imprimir Todos (ZIP)</a>
            </div>
        </div>

        <form method="GET" class="filter-bar">
            <div class="form-group">
                <label>Mês de Análise</label>
                <select name="mes">
                    <?php foreach($meses as $num => $nome_mes): ?>
                        <option value="<?= $num ?>" <?= $mes_filtro == $num ? 'selected' : '' ?>><?= $nome_mes ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="form-group">
                <label>Ano</label>
                <select name="ano">
                    <?php for($a = date('Y') + 1; $a >= 2023; $a--): ?>
                        <option value="<?= $a ?>" <?= $ano_filtro == $a ? 'selected' : '' ?>><?= $a ?></option>
                    <?php endfor; ?>
                </select>
            </div>

            <button type="submit" class="btn-primary"><i class="fa-solid fa-filter"></i> Filtrar</button>
        </form>

        <div class="card">
            <div class="card-title">
                <div><i class="fa-solid fa-calendar-days" style="color:#34495e; margin-right:8px;"></i> Folha de Ponto / Mapa Diário — <?= $meses[$mes_filtro] ?> <?= $ano_filtro ?></div>
                <div style="font-size: 12px; font-weight: normal; color: #7f8c8d; display:flex; gap: 15px; align-items: center; flex-wrap: wrap;">
                    <span><i class="fa-solid fa-umbrella-beach" style="color: #27ae60;"></i> Férias</span>
                    <span><i class="fa-solid fa-flag" style="color: #f39c12;"></i> Feriado/Fechado</span>
                    <a href="mapa_diario_pdf.php?mes=<?= $mes_filtro ?>&ano=<?= $ano_filtro ?>" target="_blank" class="btn-pdf" title="Imprimir Mapa Diário (PDF)"><i class="fa-solid fa-print"></i>&nbsp;Imprimir</a>
                </div>
            </div>
            <div class="tabela-mapa-container">
                <table class="tabela-mapa">
                    <thead>
                        <tr>
                            <th class="th-nome">Colaborador</th>
                            <?php for($d = 1; $d <= $dias_no_mes; $d++): ?>
                                <th class="<?= $fins_de_semana[$d] ? 'td-fim-semana' : '' ?>"><?= sprintf("%02d", $d) ?></th>
                            <?php endfor; ?>
                            <th style="background:#f1f2f6; font-weight:800; font-size:11px;">Totais</th>
                            <th style="background:#f1f2f6; width: 40px;"><i class="fa-solid fa-print" style="color:#7f8c8d;"></i></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($funcionarios as $id => $f): 
                            $nomes = explode(' ', trim($f['nome']));
                            $iniciais = strtoupper(substr($nomes[0], 0, 1) . (isset($nomes[1]) ? substr($nomes[1], 0, 1) : ''));
                        ?>
                            <tr>
                                <td class="td-nome">
                                    <div class="badge-func" style="gap:5px;">
                                        <div class="avatar-mini" style="width:24px; height:24px; font-size:10px;"><?= $iniciais ?></div>
                                        <span style="font-size:12px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; max-width:140px;" title="<?= htmlspecialchars($f['nome']) ?>"><?= htmlspecialchars($f['nome']) ?></span>
                                    </div>
                                </td>
                                
                                <?php for($d = 1; $d <= $dias_no_mes; $d++): 
                                    $data_str = sprintf("%04d-%02d-%02d", $ano_filtro, $mes_filtro, $d);
                                    $is_feriado = in_array($data_str, $feriados_array);

                                    $dados_dia = $mapa_diario[$id][$d];
                                    $h_dia = $dados_dia['horas'];
                                    $a_dia = $dados_dia['almoco'];
                                    $is_ferias = $dados_dia['is_ferias'];
                                    
                                    $classe_celula = '';
                                    if ($fins_de_semana[$d]) $classe_celula .= ' td-fim-semana';
                                    
                                    if ($is_feriado && !$fins_de_semana[$d]) {
                                        $classe_celula .= ' td-dia-feriado';
                                    } elseif ($is_ferias) {
                                        $classe_celula .= ' td-dia-ferias';
                                    } elseif ($h_dia > 0 || $a_dia > 0) {
                                        $classe_celula .= ' td-dia-cheio';
                                    }
                                ?>
                                    <td class="<?= trim($classe_celula) ?>">
                                        <?php if ($is_feriado && !$fins_de_semana[$d]): ?>
                                            <i class="fa-solid fa-flag" title="Feriado / Ponte"></i>
                                        <?php elseif ($is_ferias): ?>
                                            <i class="fa-solid fa-umbrella-beach" title="Em Férias"></i>
                                        <?php elseif ($h_dia > 0 || $a_dia > 0): ?>
                                            <?php if ($h_dia > 0): ?><div class="lbl-horas"><?= number_format($h_dia, 1, ',', '') ?>h</div><?php endif; ?>
                                            <?php if ($a_dia > 0): ?><div class="lbl-almoco"><?= number_format($a_dia, 2, ',', '') ?>€</div><?php endif; ?>
                                        <?php else: ?>
                                            <div class="td-dia-vazio">-</div>
                                        <?php endif; ?>
                                    </td>
                                <?php endfor; ?>
                                
                                <td style="background:#f8f9fa; min-width: 90px; text-align: right; padding-right: 15px;">
                                    <div style="color: #2980b9; font-weight: 800; font-size:15px; margin-bottom: 4px;"><?= number_format($f['horas'], 1, ',', '') ?>h</div>
                                    <div style="color: #d35400; font-weight: 700; font-size:11px;"><?= number_format($f['valor_almocos'], 2, ',', '') ?>€</div>
                                </td>
                                
                                <td style="background:#f8f9fa; text-align: center;">
                                    <a href="resumo_funcionario_pdf.php?id=<?= $id ?>&mes=<?= $mes_filtro ?>&ano=<?= $ano_filtro ?>" target="_blank" class="btn-pdf" title="Imprimir Folha de Ponto"><i class="fa-solid fa-print"></i></a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card">
            <div class="card-title"><i class="fa-solid fa-list" style="color:#34495e; margin-right:8px;"></i> Resumo Operacional Simplificado</div>
            <table>
                <thead>
                    <tr>
                        <th>Colaborador</th>
                        <th class="text-center">Perfil</th>
                        <th class="text-center">Horas Trabalhadas</th>
                        <th class="text-center">Almoços <small style="color:#a4b0be;">(Qtd / Valor)</small></th>
                        <th class="text-center">Ações</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($funcionarios)): ?>
                        <tr><td colspan="5" style="text-align: center; padding: 40px; color:#7f8c8d;">Nenhum funcionário ativo encontrado.</td></tr>
                    <?php else: ?>
                        <?php foreach ($funcionarios as $id => $f): 
                            $nomes = explode(' ', trim($f['nome']));
                            $iniciais = strtoupper(substr($nomes[0], 0, 1) . (isset($nomes[1]) ? substr($nomes[1], 0, 1) : ''));
                        ?>
                            <tr>
                                <td data-label="Colaborador">
                                    <div class="badge-func">
                                        <div class="avatar-mini"><?= $iniciais ?></div>
                                        <?= htmlspecialchars($f['nome']) ?>
                                    </div>
                                </td>
                                <td data-label="Perfil" class="text-center" style="color: #7f8c8d; font-size: 13px; text-transform: capitalize;"><?= htmlspecialchars($f['perfil']) ?></td>
                                <td data-label="Horas" class="text-center val-destaque val-horas"><?= number_format($f['horas'], 1, ',', '') ?> h</td>
                                <td data-label="Almoços" class="text-center val-destaque val-almocos">
                                    <?= $f['qtd_almocos'] ?> 
                                    <span style="font-size:12px; color:#7f8c8d; font-weight:normal; margin-left:4px;">(<?= number_format($f['valor_almocos'], 2, ',', '') ?>€)</span>
                                </td>
                                <td data-label="Ações" class="text-center">
                                    <button type="button" class="btn-ferias" onclick="abrirModalFerias(<?= $id ?>, '<?= addslashes(htmlspecialchars($f['nome'])) ?>', '<?= htmlspecialchars($f['ferias_json'] ?: '[]', ENT_QUOTES) ?>')">
                                        <i class="fa-solid fa-umbrella-beach"></i> Gerir Férias
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- MODAL GESTÃO DE FÉRIAS -->
    <div class="modal" id="modalFerias">
        <div class="modal-content">
            <div class="modal-header">
                <h3 id="modalFeriasTitle"><i class="fa-solid fa-umbrella-beach"></i> Férias</h3>
                <button type="button" class="close-btn" onclick="fecharModalFerias()"><i class="fa-solid fa-xmark"></i></button>
            </div>
            
            <div class="lista-ferias" id="lista_ferias_content">
                <!-- Preenchido via JS -->
            </div>

            <form method="POST" class="add-ferias-box">
                <input type="hidden" name="id_utilizador_ferias" id="form_id_user_add">
                <input type="hidden" name="acao_ferias" value="add">
                <div class="form-group">
                    <label>Início</label>
                    <input type="date" name="data_inicio" required>
                </div>
                <div class="form-group" style="flex:0.5;">
                    <label>Qtd. Dias</label>
                    <input type="number" name="dias_ferias" min="1" max="60" required placeholder="Ex: 5">
                </div>
                <button type="submit" class="btn-adicionar"><i class="fa-solid fa-plus"></i></button>
            </form>

            <form method="POST" id="form_apagar_feria" style="display:none;">
                <input type="hidden" name="id_utilizador_ferias" id="form_id_user_del">
                <input type="hidden" name="acao_ferias" value="del">
                <input type="hidden" name="index_feria" id="form_index_feria">
            </form>
        </div>
    </div>

    <script>
        function abrirModalFerias(id, nome, jsonFerias) {
            document.getElementById('modalFeriasTitle').innerHTML = `<i class="fa-solid fa-umbrella-beach"></i> Férias de ${nome}`;
            document.getElementById('form_id_user_add').value = id;
            document.getElementById('form_id_user_del').value = id;
            
            const listaDiv = document.getElementById('lista_ferias_content');
            listaDiv.innerHTML = '';

            try {
                const ferias = JSON.parse(jsonFerias);
                if (ferias.length === 0) {
                    listaDiv.innerHTML = '<div style="padding:15px; text-align:center; color:#95a5a6; font-size:12px;">Sem férias marcadas.</div>';
                } else {
                    ferias.forEach((f, index) => {
                        const dataInicio = new Date(f.inicio);
                        const dataFim = new Date(f.inicio);
                        dataFim.setDate(dataFim.getDate() + (f.dias - 1));
                        
                        const strInicio = dataInicio.toLocaleDateString('pt-PT');
                        const strFim = dataFim.toLocaleDateString('pt-PT');

                        listaDiv.innerHTML += `
                            <div class="item-ferias">
                                <div>
                                    <strong>${f.dias} dias</strong> <span style="color:#7f8c8d;">(${strInicio} a ${strFim})</span>
                                </div>
                                <button type="button" class="btn-rm-feria" onclick="apagarFeria(${index})" title="Remover"><i class="fa-solid fa-trash-can"></i></button>
                            </div>
                        `;
                    });
                }
            } catch (e) {
                listaDiv.innerHTML = '<div style="padding:15px; text-align:center; color:#e74c3c; font-size:12px;">Erro ao ler as férias atuais.</div>';
            }

            document.getElementById('modalFerias').classList.add('show');
        }

        function fecharModalFerias() {
            document.getElementById('modalFerias').classList.remove('show');
        }

        function apagarFeria(index) {
            if (confirm('Tem a certeza que quer apagar este período de férias?')) {
                document.getElementById('form_index_feria').value = index;
                document.getElementById('form_apagar_feria').submit();
            }
        }

        window.onclick = function(e) {
            if (e.target.id === 'modalFerias') fecharModalFerias();
        }

        // Menu do utilizador: em ecrãs táteis (tablet/telemóvel) abre com toque
        (function () {
            const menu = document.querySelector('.user-menu');
            const btn = menu ? menu.querySelector('.user-btn') : null;
            if (!btn) return;
            btn.addEventListener('click', function (e) { e.stopPropagation(); menu.classList.toggle('aberto'); });
            document.addEventListener('click', function () { menu.classList.remove('aberto'); });
        })();
    </script>
</body>
</html>