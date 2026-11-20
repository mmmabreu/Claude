<?php
session_start();
require_once 'db.php';

// REGRA DE OURO 1: Validação de sessão
if (!isset($_SESSION['user_id'])) { 
    header("Location: index.php"); 
    exit; 
}

$perfil_user = $_SESSION['perfil'] ?? '';
$user_id_atual = $_SESSION['user_id'];

// REGRA DE OURO 2: Validação de Matriz de Acesso
$stmt_perm = $pdo->prepare("SELECT modulo FROM permissoes WHERE grupo = :perfil AND pode_aceder = 1");
$stmt_perm->execute(['perfil' => $perfil_user]);
$meus_acessos = $stmt_perm->fetchAll(PDO::FETCH_COLUMN);
$is_admin = in_array($perfil_user, ['admin', 'administrativo']);
// Acompanhamentos e reuniões de obra: só para utilizadores do tipo admin
$ve_acomp = ($perfil_user === 'admin');

if (!$is_admin && !in_array('relatorios', $meus_acessos)) {
    header("Location: index.php?erro=sem_permissao");
    exit;
}

// REGRA DE OURO: Obter apenas o primeiro nome para o Menu
$nome_completo = $_SESSION['nome'] ?? 'Utilizador';
$primeiro_nome = explode(' ', trim($nome_completo))[0];

if (file_exists('functions.php')) { 
    require_once 'functions.php'; 
} else { 
    function registrarLog($pdo, $acao, $modulo) { return true; } 
}

$mensagem = '';
$tipo_mensagem = '';

if (isset($_GET['editado'])) {
    $mensagem = "Operação realizada com sucesso!";
    $tipo_mensagem = "sucesso";
}

// LÓGICA DE APAGAR REGISTO
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['apagar_id'])) {
    $id_apagar = (int)$_POST['apagar_id'];
    $pass_introduzida = $_POST['pass_seguranca'] ?? '';

    $stmt_pass = $pdo->prepare("SELECT valor FROM configuracoes WHERE chave = 'password_apagar' LIMIT 1");
    $stmt_pass->execute();
    $pass_config = $stmt_pass->fetchColumn();

    if ($pass_introduzida === $pass_config && !empty($pass_config)) {
        try {
            $stmt_del = $pdo->prepare("DELETE FROM relatorios_obra WHERE id = ?");
            $stmt_del->execute([$id_apagar]);
            registrarLog($pdo, "Apagou Relatório #$id_apagar", "Relatórios de Obra");
            $mensagem = "Relatório apagado com sucesso!";
            $tipo_mensagem = "sucesso";
        } catch (Exception $e) {
            $mensagem = "Erro ao apagar relatório.";
            $tipo_mensagem = "erro";
        }
    } else {
        $mensagem = "Password de segurança incorreta!";
        $tipo_mensagem = "erro";
    }
}

// LÓGICA DE APAGAR ACOMPANHAMENTO / REUNIÃO DE OBRA (registos da tabela visitas)
if ($ve_acomp && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['apagar_visita_id'])) {
    $id_apagar = (int)$_POST['apagar_visita_id'];
    $pass_introduzida = $_POST['pass_seguranca'] ?? '';
    $stmt_pass = $pdo->prepare("SELECT valor FROM configuracoes WHERE chave = 'password_apagar' LIMIT 1");
    $stmt_pass->execute();
    $pass_config = $stmt_pass->fetchColumn();
    if ($pass_introduzida === $pass_config && !empty($pass_config)) {
        try {
            $pdo->prepare("DELETE FROM visitas WHERE id = ? AND id_obra IS NOT NULL")->execute([$id_apagar]);
            registrarLog($pdo, "Apagou Acompanhamento/Reunião de Obra #$id_apagar", "Relatórios de Obra");
            $mensagem = "Registo apagado com sucesso!";
            $tipo_mensagem = "sucesso";
        } catch (Exception $e) {
            $mensagem = "Erro ao apagar registo."; $tipo_mensagem = "erro";
        }
    } else {
        $mensagem = "Password de segurança incorreta!"; $tipo_mensagem = "erro";
    }
}

// LÓGICA DO DROPDOWN DE OBRAS
$params_obras = [];
if ($is_admin) {
    $sql_obras_filtro = "SELECT id, nome FROM obras ORDER BY nome ASC";
} else {
    $sql_obras_filtro = "SELECT DISTINCT o.id, o.nome
                         FROM obras o
                         WHERE o.id IN (SELECT r.id_obra FROM relatorios_obra r WHERE r.colaborador_1 = :user_id)
                         ORDER BY o.nome ASC";
    $params_obras['user_id'] = $user_id_atual;
}

$stmt_obras = $pdo->prepare($sql_obras_filtro);
$stmt_obras->execute($params_obras);
$obras_filtro = $stmt_obras->fetchAll(PDO::FETCH_ASSOC);

// GERAR LISTA DE MESES (Atual + 12 anteriores)
$meses_nomes = [1=>'Janeiro', 2=>'Fevereiro', 3=>'Março', 4=>'Abril', 5=>'Maio', 6=>'Junho', 7=>'Julho', 8=>'Agosto', 9=>'Setembro', 10=>'Outubro', 11=>'Novembro', 12=>'Dezembro'];
$lista_meses = [];
for ($i = 0; $i <= 12; $i++) {
    $time = strtotime("-$i months", strtotime(date('Y-m-01')));
    $val = date('Y-m', $time);
    $label = $meses_nomes[(int)date('m', $time)] . ' ' . date('Y', $time);
    $lista_meses[$val] = $label;
}

// FILTRAGEM INTELIGENTE E BUSCA DE RELATÓRIOS
$filtro_obra = $_GET['filtro_obra'] ?? '';
$mes_selecionado = $_GET['mes_filtro'] ?? date('Y-m'); // Mês atual por defeito

$params = [];
$where = "1=1";

if ($mes_selecionado !== 'todos') {
    $data_inicio_sql = $mes_selecionado . '-01';
    $data_fim_sql = date('Y-m-t', strtotime($data_inicio_sql));
    
    $where .= " AND r.data_relatorio >= :data_inicio AND r.data_relatorio <= :data_fim";
    $params['data_inicio'] = $data_inicio_sql;
    $params['data_fim'] = $data_fim_sql;
}

if (!empty($filtro_obra)) {
    $where .= " AND r.id_obra = :id_obra";
    $params['id_obra'] = $filtro_obra;
}

if (!$is_admin) {
    $where .= " AND r.colaborador_1 = :user_id";
    $params['user_id'] = $user_id_atual;
}

// ORDENAÇÃO
$sql = "SELECT r.id, r.id_obra, r.data_relatorio, r.trabalho_realizado, r.trabalho_extra, r.total_horas, r.custo_refeicao_1,
               o.nome AS obra_nome,
               u.nome AS colab_nome 
        FROM relatorios_obra r 
        LEFT JOIN obras o ON r.id_obra = o.id 
        LEFT JOIN utilizadores u ON r.colaborador_1 = u.id 
        WHERE $where
        ORDER BY o.nome ASC, r.trabalho_extra ASC, r.data_relatorio DESC, r.id DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$relatorios = $stmt->fetchAll(PDO::FETCH_ASSOC);

// AGRUPAR RESULTADOS POR OBRA
$obras_agrupadas = [];
foreach ($relatorios as $r) {
    $id_obra = $r['id_obra'];
    $nome_obra = $r['obra_nome'] ?? 'Obra Removida';
    
    if (!isset($obras_agrupadas[$id_obra])) {
        $obras_agrupadas[$id_obra] = [
            'nome' => $nome_obra,
            'lista' => [],
            'total_horas_obra' => 0
        ];
    }
    $obras_agrupadas[$id_obra]['lista'][] = $r;
    $obras_agrupadas[$id_obra]['total_horas_obra'] += (float)$r['total_horas'];
}

// --- ACOMPANHAMENTOS E REUNIÕES DE OBRA (continuam na tabela 'visitas': a Gestão de Obra usa-os nos custos) ---
$params_v = [];
$where_v = "v.id_obra IS NOT NULL";
if ($mes_selecionado !== 'todos') {
    $where_v .= " AND v.data_visita >= :data_inicio AND v.data_visita <= :data_fim";
    $params_v['data_inicio'] = $mes_selecionado . '-01';
    $params_v['data_fim'] = date('Y-m-t', strtotime($mes_selecionado . '-01'));
}
if (!empty($filtro_obra)) {
    $where_v .= " AND v.id_obra = :id_obra";
    $params_v['id_obra'] = $filtro_obra;
}
if (!$is_admin) {
    $where_v .= " AND v.colaborador_1 = :user_id";
    $params_v['user_id'] = $user_id_atual;
}
$acompanhamentos = [];
if ($ve_acomp) try {
    $stmt_v = $pdo->prepare("SELECT v.id, v.id_obra, v.data_visita, v.hora_visita, v.hora_saida, v.assunto, v.notas, v.estado,
                                    o.nome AS obra_nome, o.referencia_obra, u.nome AS colab_nome
                             FROM visitas v
                             LEFT JOIN obras o ON v.id_obra = o.id
                             LEFT JOIN utilizadores u ON v.colaborador_1 = u.id
                             WHERE $where_v
                             ORDER BY o.nome ASC, v.data_visita DESC, v.hora_visita DESC");
    $stmt_v->execute($params_v);
    $acompanhamentos = $stmt_v->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

$acomp_agrupados = [];
foreach ($acompanhamentos as $v) {
    $ido = $v['id_obra'];
    if (!isset($acomp_agrupados[$ido])) $acomp_agrupados[$ido] = ['nome' => $v['obra_nome'] ?? 'Obra Removida', 'ref' => $v['referencia_obra'] ?? '', 'lista' => []];
    $acomp_agrupados[$ido]['lista'][] = $v;
}

function eReuniao($v) { return stripos($v['assunto'] ?? '', 'Reunião') !== false; }

function renderizarAcompanhamentos($dados, $id_obra) {
    $titulo = htmlspecialchars(($dados['ref'] ? '[' . $dados['ref'] . '] ' : '') . $dados['nome']);
    $html = "<div class='tabela-section'>";
    $html .= "<div class='obra-head' style='border-bottom-color:#2980b980;'>";
    $html .= "<h2 class='section-title' style='color:#2471a3;'><i class='fa-solid fa-helmet-safety'></i> {$titulo}</h2>";
    $html .= "<div class='obra-head-btns'>";
    $html .= "<a href='visita_form.php?tipo=obra&id_obra={$id_obra}' class='btn-add-inline' style='background:#27ae60;'><i class='fa-solid fa-plus'></i> Acompanhamento</a>";
    $html .= "<a href='visita_form.php?tipo=reuniao&id_obra={$id_obra}' class='btn-add-inline' style='background:#e67e22;'><i class='fa-solid fa-plus'></i> Reunião</a>";
    $html .= "</div></div>";
    $html .= "<div class='card'><div class='table-responsive'><table class='tabela-cartoes'>";
    $html .= "<thead><tr><th style='width:110px;'>Data</th><th style='width:170px;'>Tipo</th><th>Assunto</th><th style='width:140px;'>Colaborador</th><th style='width:110px; text-align:center;'>Horário</th><th style='width:120px; text-align:center;'>Ações</th></tr></thead><tbody>";
    foreach ($dados['lista'] as $v) {
        $data = date('d/m/Y', strtotime($v['data_visita']));
        $tipo = eReuniao($v)
            ? "<span class='badge' style='background:#fff3e0; color:#e67e22; border:1px solid #ffe0b2;'><i class='fa-solid fa-people-group'></i> Reunião</span>"
            : "<span class='badge' style='background:#e8f5e9; color:#27ae60; border:1px solid #c8e6c9;'><i class='fa-solid fa-helmet-safety'></i> Acompanhamento</span>";
        if (($v['estado'] ?? '') === 'Não processada') $tipo .= " <span class='badge' style='background:#fdf2f2; color:#e84393; border:1px solid #fadbd8;'>Pendente</span>";
        $h_in = !empty($v['hora_visita']) ? date('H:i', strtotime($v['hora_visita'])) : '--:--';
        $h_out = !empty($v['hora_saida']) ? date('H:i', strtotime($v['hora_saida'])) : '--:--';
        $assunto = htmlspecialchars($v['assunto'] ?? '');
        $html .= "<tr>";
        $html .= "<td data-label='Data' style='font-weight:600;'>{$data}</td>";
        $html .= "<td data-label='Tipo'><div class='badge-container' style='flex-direction:row; flex-wrap:wrap;'>{$tipo}</div></td>";
        $html .= "<td data-label='Assunto'><span class='truncate' title='{$assunto}'>{$assunto}</span></td>";
        $html .= "<td data-label='Colaborador'>" . htmlspecialchars($v['colab_nome'] ?? 'N/D') . "</td>";
        $html .= "<td data-label='Horário' style='text-align:center; white-space:nowrap; font-weight:700; color:#2471a3;'>{$h_in} - {$h_out}</td>";
        $html .= "<td class='td-acoes'><div class='acoes' style='justify-content:center;'>
                    <a href='visita_pdf.php?id={$v['id']}' target='_blank' class='btn-print-action' title='Imprimir'><i class='fa-solid fa-print'></i></a>
                    <a href='visita_form.php?id={$v['id']}' class='btn-edit' title='Editar'><i class='fa-solid fa-pen-to-square'></i></a>
                    <button type='button' class='btn-delete' title='Apagar' onclick='abrirModalApagarVisita({$v['id']})'><i class='fa-solid fa-trash-can'></i></button>
                  </div></td>";
        $html .= "</tr>";
    }
    $html .= "</tbody></table></div></div></div>";
    return $html;
}

function renderizarTabelaObra($lista, $titulo_obra, $id_obra, $cor_titulo, $total_horas_obra) {
    if (count($lista) === 0) return ''; 

    $html = "<div class='tabela-section'>";
    
    $html .= "<div style='display:flex; justify-content:space-between; align-items:flex-end; border-bottom: 2px solid {$cor_titulo}80; margin-bottom: 15px; padding-bottom:5px;'>";
    $html .= "<div>";
    $html .= "<h2 style='color: {$cor_titulo}; border:none; margin:0; padding-bottom:0;' class='section-title'><i class='fa-solid fa-helmet-safety'></i> {$titulo_obra}</h2>";
    $html .= "</div>";
    $html .= "<a href='relatorio_form.php?id_obra={$id_obra}' style='background:{$cor_titulo}; color:#fff; padding:6px 12px; border-radius:6px; text-decoration:none; font-size:12px; font-weight:bold; transition:0.2s;' class='btn-add-inline'><i class='fa-solid fa-plus'></i> Novo Registo</a>";
    $html .= "</div>";
    
    $html .= "<div class='card'><div class='table-responsive'><table>";
    $html .= "<thead><tr>
                <th class='col-pc' style='width: 90px;'>Data</th>
                <th style='width: 140px;'>Colaborador</th>
                <th style='width: 110px; text-align: center;'>Horas</th>
                <th class='col-pc'>Trabalho Realizado</th>
                <th class='col-pc' style='width: 100px;'>Info / Tipo</th>
                <th style='width: 120px; text-align: center;'>Ações</th>
              </tr></thead><tbody>";
    
    foreach ($lista as $r) {
        $data_formatada = date('d/m/Y', strtotime($r['data_relatorio']));
        $colab = htmlspecialchars($r['colab_nome'] ?? 'N/D');
        $trabalho = "<span class='truncate' title='" . htmlspecialchars($r['trabalho_realizado']) . "'>" . htmlspecialchars($r['trabalho_realizado']) . "</span>";
        
        $horas_dec = (float)($r['total_horas'] ?? 0);
        $h = floor($horas_dec);
        $m = round(($horas_dec - $h) * 60);
        $horas_txt = $h . 'h ' . str_pad($m, 2, '0', STR_PAD_LEFT) . 'm';

        $info = "<div class='badge-container' style='gap:4px;'>";
        if (!empty($r['trabalho_extra'])) {
            $info .= "<span class='badge' style='background:#fdebd0; color:#e67e22; border: 1px solid #fad7a1;'><i class='fa-solid fa-star'></i> Extra</span>";
        } else {
            $info .= "<span style='color: #bdc3c7; font-size: 12px; font-style: italic;'>Normal</span>";
        }
        
        $refeicao = (float)($r['custo_refeicao_1'] ?? 0);
        if ($refeicao > 0) {
            $info .= "<span class='badge' style='background:#e8f8f5; color:#16a085; border: 1px solid #a3e4d7;'><i class='fa-solid fa-utensils'></i> " . number_format($refeicao, 2, ',', '.') . " &euro;</span>";
        }
        $info .= "</div>";

        $acoes = "<div class='acoes' style='justify-content: center;'>
                    <button type='button' class='btn-print-action' title='Imprimir PDF' onclick='abrirModalImprimir({$r['id']})'><i class='fa-solid fa-print'></i></button>
                    <a href='relatorio_form.php?id={$r['id']}' class='btn-edit' title='Editar'><i class='fa-solid fa-pen-to-square'></i></a>
                    <button type='button' class='btn-delete' title='Apagar' onclick='abrirModalApagar({$r['id']})'><i class='fa-solid fa-trash-can'></i></button>
                  </div>";

        $html .= "<tr>";
        $html .= "<td class='col-pc' style='font-weight:600;'>{$data_formatada}</td>";
        $html .= "<td style='font-weight: 600;'><div class='col-mobile mobile-data'>{$data_formatada}</div>{$colab}</td>";
        $html .= "<td style='text-align: center; font-weight: 800; color: {$cor_titulo}; white-space: nowrap;'>{$horas_txt}</td>";
        $html .= "<td class='col-pc'>{$trabalho}</td>";
        $html .= "<td class='col-pc'>{$info}</td>";
        $html .= "<td>{$acoes}</td>";
        $html .= "</tr>";
    }
    
    $html .= "</tbody></table></div></div></div>";
    return $html;
}
?>
<!DOCTYPE html>
<html lang="pt-PT">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Relatórios de Obra - Gestão MAB</title>
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
        .user-btn { background: #fff; border: 1px solid #e8ecf1; border-radius: 30px; font-size: 14px; font-weight: 600; color: #2c3e50; cursor: pointer; display: flex; align-items: center; gap: 10px; padding: 5px 14px 5px 5px; transition: 0.2s; font-family: inherit; }
        .user-btn:hover { box-shadow: 0 4px 12px rgba(0,0,0,0.06); }
        .user-avatar { background: #e74c3c; color: #fff; width: 28px; height: 28px; border-radius: 50%; display: flex; justify-content: center; align-items: center; font-size: 13px; }
        .dropdown-content { display: none; position: absolute; right: 0; top: 100%; background: #fff; min-width: 180px; box-shadow: 0 12px 30px rgba(0,0,0,0.1); border-radius: 10px; border: 1px solid #e8ecf1; overflow: hidden; margin-top: 6px; z-index: 200; }
        .user-menu:hover .dropdown-content, .user-menu.aberto .dropdown-content { display: block; }
        .dropdown-content a { color: #576574; padding: 12px 20px; text-decoration: none; display: block; font-size: 14px; }
        .dropdown-content a:hover { background: #f4f6f8; color: #2c3e50; }

        /* Separadores Relatórios / Acompanhamentos */
        .tabs { display: flex; gap: 8px; margin-bottom: 20px; border-bottom: 2px solid #e1e5ea; }
        .tab-btn { background: transparent; color: #7f8c8d; font-weight: 700; font-size: 14px; padding: 12px 18px; border: none; cursor: pointer; border-bottom: 3px solid transparent; margin-bottom: -2px; display: inline-flex; align-items: center; gap: 8px; font-family: inherit; }
        .tab-btn:hover { color: #2c3e50; }
        .tab-btn.active { color: #16a085; border-bottom-color: #16a085; }
        .tab-btn .cont { background: #e8f8f5; color: #117a65; border-radius: 20px; padding: 1px 8px; font-size: 11px; }
        .tab-content { display: none; }
        .tab-content.active { display: block; }
        .header-btns { display: flex; gap: 8px; flex-wrap: wrap; }
        .obra-head { display: flex; justify-content: space-between; align-items: flex-end; border-bottom: 2px solid; margin-bottom: 15px; padding-bottom: 6px; gap: 10px; flex-wrap: wrap; }
        .obra-head-btns { display: flex; gap: 6px; flex-wrap: wrap; }
        .btn-add-inline { color: #fff; padding: 6px 12px; border-radius: 6px; text-decoration: none; font-size: 12px; font-weight: bold; display: inline-flex; align-items: center; gap: 5px; }
        .aviso-gestao { font-size: 12px; color: #7f8c8d; margin: -8px 0 18px; display: flex; gap: 6px; align-items: center; }

        .container { max-width: 1200px; margin: 20px auto; padding: 0 15px; }
        
        .header-box { display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px; flex-wrap: wrap; gap: 15px; border-bottom: 1px solid #e1e5ea; padding-bottom: 15px; }
        h1 { margin: 0; color: #2c3e50; font-size: 22px; font-weight: 800; }
        
        .btn-novo { background: #fff; color: #16a085; border: 1px solid #16a085; padding: 8px 15px; border-radius: 30px; text-decoration: none; font-weight: 700; font-size: 13px; display: inline-flex; align-items: center; gap: 6px; transition: 0.2s; }
        .btn-novo:hover { background: #16a085; color: #fff; }
        .btn-add-inline:hover { opacity: 0.8; }

        .filter-box { background: #fff; padding: 20px; border-radius: 12px; border: 1px solid #e1e5ea; box-shadow: 0 2px 10px rgba(0,0,0,0.02); margin-bottom: 30px; }
        .filter-form { display: flex; flex-direction: column; gap: 15px; width: 100%; }
        .filter-row { display: flex; gap: 15px; width: 100%; flex-wrap: wrap; }
        
        .filter-group { display: flex; flex-direction: column; flex: 1; min-width: 150px; }
        .filter-group label { font-size: 11px; font-weight: bold; color: #576574; text-transform: uppercase; margin-bottom: 5px; }
        .filter-group select { padding: 10px; border-radius: 8px; border: 1px solid #dcdde1; outline: none; font-family: inherit; font-size: 14px; background: #fff; height: 41px; }
        .filter-group select:focus { border-color: #16a085; }
        
        .filter-actions { display: flex; gap: 10px; align-items: flex-end; }
        .btn-limpar { background: #f1f2f6; color: #576574; text-decoration: none; padding: 10px 20px; border-radius: 8px; font-weight: bold; display: inline-flex; align-items: center; height: 41px; transition: 0.2s; }
        .btn-limpar:hover { background: #e1e5ea; color: #2c3e50; }
        
        .alert { padding: 15px; border-radius: 8px; margin-bottom: 20px; text-align: center; font-weight: 600; }
        .alert-erro { background: #fdeded; color: #16a085; border: 1px solid #f9cccc; }
        .alert-sucesso { background: #e8f5e9; color: #2e7d32; border: 1px solid #c8e6c9; }

        .tabela-section { margin-bottom: 40px; }
        .section-title { font-size: 16px; padding-bottom: 5px; margin-bottom: 0; margin-top: 0; display: flex; align-items: center; gap: 10px; }
        
        .card { background: #fff; border-radius: 12px; border: 1px solid #e1e5ea; box-shadow: 0 2px 10px rgba(0,0,0,0.02); overflow: hidden; }
        .table-responsive { width: 100%; overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; min-width: 800px; }
        th { background: #f8f9fa; padding: 12px 15px; text-align: left; font-size: 11px; text-transform: uppercase; color: #576574; border-bottom: 2px solid #e1e5ea; }
        td { padding: 12px 15px; border-bottom: 1px solid #f1f2f6; color: #2c3e50; vertical-align: middle; }
        tr:hover { background: #fcfcfc; }
        
        .badge-container { display: flex; flex-direction: column; gap: 5px; align-items: flex-start; }
        .badge { display: inline-flex; align-items: center; gap: 5px; padding: 4px 8px; border-radius: 6px; font-size: 11px; font-weight: 700; text-transform: uppercase; }

        .acoes { display: flex; gap: 8px; }
        .btn-print-action { color: #3498db; background: #f0f8ff; padding: 8px; border: none; border-radius: 6px; cursor: pointer; transition: 0.2s; display: inline-flex; align-items: center; justify-content: center; width: 32px; height: 32px; font-size: 15px; text-decoration: none; }
        .btn-print-action:hover { background: #d6eaf8; color: #2980b9; }
        .btn-edit { color: #576574; background: #f1f2f6; padding: 8px; border-radius: 6px; text-decoration: none; transition: 0.2s; display: inline-flex; align-items: center; justify-content: center; width: 32px; height: 32px; }
        .btn-edit:hover { background: #e1e5ea; color: #2c3e50; }
        .btn-delete { color: #e74c3c; background: #fdeded; padding: 8px; border: none; border-radius: 6px; cursor: pointer; transition: 0.2s; display: inline-flex; align-items: center; justify-content: center; width: 32px; height: 32px; }
        .btn-delete:hover { background: #fadbd8; color: #c0392b; }

        .truncate { max-width: 250px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; display: inline-block; }
        .col-mobile { display: none; }

        .modal { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.6); z-index: 1001; justify-content: center; align-items: center; padding: 20px; }
        .modal.show { display: flex; }
        .modal-content { background: #fff; padding: 30px; border-radius: 12px; width: 100%; max-width: 400px; text-align: center; }
        
        .modal-content input[type="password"] { width: 100%; padding: 12px; border: 1px solid #dcdde1; border-radius: 8px; margin: 15px 0; outline: none; }
        .modal-content input[type="password"]:focus { border-color: #16a085; }
        .modal-btns { display: flex; gap: 10px; }
        .modal-btns button { flex: 1; padding: 12px; border: none; border-radius: 8px; font-weight: 600; cursor: pointer; }
        .btn-cancelar { background: #f1f2f6; color: #576574; }
        .btn-confirmar { background: #16a085; color: #fff; }

        .modal-print-content { max-width: 450px; text-align: left; padding: 20px 25px; }
        .modal-print-header { display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #f1f2f6; padding-bottom: 15px; margin-bottom: 15px; }
        .modal-print-header h3 { margin: 0; color: #16a085; font-size: 16px; display: flex; align-items: center; gap: 8px; }
        .close-btn { color: #95a5a6; font-size: 22px; cursor: pointer; font-weight: bold; background: none; border: none; padding: 0; line-height: 1; transition: 0.2s; }
        .close-btn:hover { color: #333; }
        .modal-subtitle { color: #576574; font-size: 14px; font-weight: 700; margin-bottom: 15px; text-align: left; }
        
        .btn-pdf-option { display: flex; align-items: center; gap: 15px; width: 100%; padding: 15px; background: #fdfdfd; border: 1px solid #e1e5ea; border-radius: 8px; cursor: pointer; text-align: left; transition: all 0.2s; margin-bottom: 10px; font-family: inherit; }
        .btn-pdf-option:hover { background: #f9f9f9; border-color: #16a085; box-shadow: 0 2px 5px rgba(0,0,0,0.02); }
        .btn-pdf-option i { color: #16a085; font-size: 22px; }
        .btn-pdf-option span { color: #2c3e50; font-weight: 600; font-size: 14px; }

 @media (max-width: 1024px) { .topbar { padding: 10px 24px; } }
        @media (max-width: 768px) {
            .topbar { padding: 8px 15px; }
            .topbar-left .logo { height: 30px; }
            .btn-voltar { width: 38px; height: 38px; }
            .tabs { overflow-x: auto; }
            .tab-btn { white-space: nowrap; padding: 10px 12px; font-size: 13px; }

            /* Acompanhamentos em cartões */
            .tabela-cartoes, .tabela-cartoes thead, .tabela-cartoes tbody, .tabela-cartoes tr, .tabela-cartoes td { display: block; width: 100%; }
            .tabela-cartoes { min-width: 0 !important; }
            .tabela-cartoes thead { display: none; }
            .tabela-cartoes tr { border-bottom: 1px solid #eef1f4; padding: 8px 14px; }
            .tabela-cartoes td { border: none; padding: 5px 0; display: flex; justify-content: space-between; align-items: center; gap: 12px; text-align: right !important; }
            .tabela-cartoes td::before { content: attr(data-label); font-size: 10px; font-weight: 700; text-transform: uppercase; color: #95a5a6; text-align: left; flex-shrink: 0; }
            .tabela-cartoes td.td-acoes { justify-content: flex-end; }
            .tabela-cartoes td.td-acoes::before { display: none; }
            .tabela-cartoes .badge-container { justify-content: flex-end; }
            .tabela-cartoes .truncate { max-width: 60vw; white-space: normal; text-align: right; }
            
            .header-box { flex-direction: row; justify-content: space-between; border-bottom: none; margin-bottom: 10px; }
            .btn-novo { padding: 6px 12px; font-size: 12px; }
            
            .filter-form { gap: 10px; }
            .filter-group { width: calc(50% - 5px); }
            .filter-group select { width: 100%; }
            
            table { min-width: 100%; }
            .col-pc { display: none; }
            .col-mobile { display: block; }
            
            .mobile-data { font-size: 11px; color: #7f8c8d; margin-bottom: 2px; font-weight: normal; }
            td { padding: 12px 10px; }
        }
        @media (max-width: 560px) {
            .user-nome { display: none; }
            .user-btn { padding: 5px 10px 5px 5px; }
            .header-box { flex-direction: column; align-items: stretch; }
            .header-btns { width: 100%; }
            .header-btns a { flex: 1; justify-content: center; text-align: center; }
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
            <div class="alert <?= $tipo_mensagem === 'sucesso' ? 'alert-sucesso' : 'alert-erro' ?>"><?= htmlspecialchars($mensagem, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>

        <div class="header-box">
            <h1>Relatórios de Obra</h1>
            <div class="header-btns">
                <a href="relatorio_form.php" class="btn-novo"><i class="fa-solid fa-plus"></i> Relatório Diário</a>
                <?php if ($ve_acomp): ?>
                <a href="visita_form.php?tipo=obra" class="btn-novo" style="color:#27ae60; border-color:#27ae60;"><i class="fa-solid fa-plus"></i> Acompanhamento</a>
                <a href="visita_form.php?tipo=reuniao" class="btn-novo" style="color:#e67e22; border-color:#e67e22;"><i class="fa-solid fa-plus"></i> Reunião de Obra</a>
                <?php endif; ?>
            </div>
        </div>

        <div class="filter-box">
            <form method="GET" class="filter-form" id="formFiltros">
                <div class="filter-row">
                    <div class="filter-group" style="flex: 1; min-width: 150px;">
                        <label>Mês / Período</label>
                        <select name="mes_filtro" onchange="document.getElementById('formFiltros').submit()">
                            <option value="todos" <?= $mes_selecionado === 'todos' ? 'selected' : '' ?>>-- Listar Todos --</option>
                            <?php foreach($lista_meses as $val => $label): ?>
                                <option value="<?= $val ?>" <?= $mes_selecionado === $val ? 'selected' : '' ?>><?= $label ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="filter-group" style="flex: 2; min-width: 250px;">
                        <label>Filtrar por Obra</label>
                        <select name="filtro_obra" onchange="document.getElementById('formFiltros').submit()">
                            <option value="">-- Todas as Obras --</option>
                            <?php foreach($obras_filtro as $ob_f): ?>
                                <option value="<?= $ob_f['id'] ?>" <?= ($filtro_obra == $ob_f['id']) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($ob_f['nome']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="filter-actions">
                        <a href="relatorios.php" class="btn-limpar" title="Limpar Filtros"><i class="fa-solid fa-eraser"></i></a>
                    </div>
                </div>
            </form>
        </div>

        <?php if ($ve_acomp): ?>
        <div class="tabs">
            <button type="button" class="tab-btn active" onclick="mudarTab('diarios', this)"><i class="fa-solid fa-clipboard-list"></i> Relatórios Diários <span class="cont"><?= count($relatorios) ?></span></button>
            <button type="button" class="tab-btn" onclick="mudarTab('acomp', this)"><i class="fa-solid fa-people-group"></i> Acompanhamentos e Reuniões <span class="cont"><?= count($acompanhamentos) ?></span></button>
        </div>

        <div id="tab-acomp" class="tab-content">
            <p class="aviso-gestao"><i class="fa-solid fa-circle-info"></i> Estes registos continuam a contar na Gestão de Obra (deslocação + mão de obra).</p>
            <?php if (empty($acomp_agrupados)): ?>
                <div class="card" style="text-align: center; padding: 40px; color: #7f8c8d;">Nenhum acompanhamento ou reunião para estes critérios.</div>
            <?php else: foreach ($acomp_agrupados as $id_obra_a => $dados_a) { echo renderizarAcompanhamentos($dados_a, $id_obra_a); } endif; ?>
        </div>
        <?php endif; ?>

        <div id="tab-diarios" class="tab-content active">
        <?php 
        if (count($obras_agrupadas) === 0): ?>
            <div class="card" style="text-align: center; padding: 40px; color: #7f8c8d;">Nenhum relatório encontrado para estes critérios.</div>
        <?php else:
            foreach ($obras_agrupadas as $id_obra => $dados_obra) {
                echo renderizarTabelaObra($dados_obra['lista'], $dados_obra['nome'], $id_obra, '#16a085', $dados_obra['total_horas_obra']);
            }
        endif; 
        ?>
        </div>
    </div>

    <div class="modal" id="modalImprimir">
        <div class="modal-content modal-print-content">
            <div class="modal-print-header">
                <h3><i class="fa-solid fa-print"></i> Gerar Documento</h3>
                <button type="button" class="close-btn" onclick="fecharModalImprimir()">&times;</button>
            </div>
            
            <div class="modal-subtitle">Relatório Nº <span id="display_id_print"></span></div>
            
            <input type="hidden" id="input_print_id">
            
            <button type="button" class="btn-pdf-option" onclick="gerarPDF('cliente')">
                <i class="fa-solid fa-file-pdf"></i>
                <span>Relatório Cliente (Resumo)</span>
            </button>
            
            <?php if ($is_admin): ?>
            <button type="button" class="btn-pdf-option" onclick="gerarPDF('completa')">
                <i class="fa-solid fa-file-pdf"></i>
                <span>Relatório Gestão (Com Custos)</span>
            </button>
            <?php endif; ?>
        </div>
    </div>

    <div class="modal" id="modalApagar">
        <div class="modal-content">
            <h3 style="color:#16a085; margin-top:0;"><i class="fa-solid fa-triangle-exclamation"></i> Apagar Relatório</h3>
            <p>Tem a certeza que deseja apagar o registo selecionado?</p>
            
            <form method="POST">
                <input type="hidden" name="apagar_id" id="input_apagar_id">
                <input type="password" name="pass_seguranca" placeholder="Insira a Password de Segurança" required>
                
                <div class="modal-btns">
                    <button type="button" class="btn-cancelar" onclick="fecharModalApagar()">Cancelar</button>
                    <button type="submit" class="btn-confirmar">Apagar</button>
                </div>
            </form>
        </div>
    </div>

    <div class="modal" id="modalApagarVisita">
        <div class="modal-content">
            <h3 style="color:#e74c3c; margin-top:0;"><i class="fa-solid fa-triangle-exclamation"></i> Apagar Acompanhamento / Reunião</h3>
            <p>Tem a certeza? O registo deixa também de contar na Gestão de Obra.</p>
            <form method="POST">
                <input type="hidden" name="apagar_visita_id" id="input_apagar_visita_id">
                <input type="password" name="pass_seguranca" placeholder="Insira a Password de Segurança" required>
                <div class="modal-btns">
                    <button type="button" class="btn-cancelar" onclick="document.getElementById('modalApagarVisita').classList.remove('show')">Cancelar</button>
                    <button type="submit" class="btn-confirmar" style="background:#e74c3c;">Apagar</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function mudarTab(id, btn) {
            document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
            document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
            btn.classList.add('active');
            document.getElementById('tab-' + id).classList.add('active');
            try { sessionStorage.setItem('relatorios_tab', id); } catch (e) {}
        }
        (function () {
            let t = new URLSearchParams(location.search).get('tab');
            try { t = t || sessionStorage.getItem('relatorios_tab'); } catch (e) {}
            if (t === 'acomp' && document.getElementById('tab-acomp')) mudarTab('acomp', document.querySelectorAll('.tab-btn')[1]);
        })();
        function abrirModalApagarVisita(id) {
            document.getElementById('input_apagar_visita_id').value = id;
            document.getElementById('modalApagarVisita').classList.add('show');
        }
        (function () {
            const menu = document.querySelector('.user-menu');
            const btn = menu ? menu.querySelector('.user-btn') : null;
            if (!btn) return;
            btn.addEventListener('click', function (e) { e.stopPropagation(); menu.classList.toggle('aberto'); });
            document.addEventListener('click', function () { menu.classList.remove('aberto'); });
        })();

        function abrirModalApagar(id) {
            document.getElementById('input_apagar_id').value = id;
            document.getElementById('modalApagar').classList.add('show');
        }
        function fecharModalApagar() {
            document.getElementById('modalApagar').classList.remove('show');
        }

        function abrirModalImprimir(id) {
            document.getElementById('display_id_print').innerText = id;
            document.getElementById('input_print_id').value = id;
            document.getElementById('modalImprimir').classList.add('show');
        }
        function fecharModalImprimir() {
            document.getElementById('modalImprimir').classList.remove('show');
        }
        function gerarPDF(tipo) {
            const id = document.getElementById('input_print_id').value;
            window.open(`relatorio_pdf.php?id=${id}&tipo=${tipo}`, '_blank');
            fecharModalImprimir();
        }

        window.onclick = function(e) {
            if (e.target.id === 'modalApagar') fecharModalApagar();
            if (e.target.id === 'modalImprimir') fecharModalImprimir();
            if (e.target.id === 'modalApagarVisita') e.target.classList.remove('show');
        }
    </script>
</body>
</html>