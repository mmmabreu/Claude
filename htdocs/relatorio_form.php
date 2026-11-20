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

// REGRA DE OURO 2: Acesso Restrito pela Matriz
$stmt_perm = $pdo->prepare("SELECT modulo FROM permissoes WHERE grupo = :perfil AND pode_aceder = 1");
$stmt_perm->execute(['perfil' => $_SESSION['perfil']]);
$meus_acessos = $stmt_perm->fetchAll(PDO::FETCH_COLUMN);

if ($_SESSION['perfil'] !== 'admin' && !in_array('relatorios', $meus_acessos)) {
    header("Location: index.php?erro=sem_permissao");
    exit;
}

$mensagem = '';
$tipo_mensagem = '';

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$modo_editar = !empty($id);

// Lógica de Obra pré-selecionada via URL
$id_obra_get = filter_input(INPUT_GET, 'id_obra', FILTER_VALIDATE_INT);
$nome_obra_get = null;
$nome_cliente_get = null;

if (!$modo_editar && $id_obra_get) {
    $stmt_obra = $pdo->prepare("SELECT o.nome, c.nome AS cliente_nome FROM obras o LEFT JOIN clientes c ON c.id = o.id_cliente WHERE o.id = ?");
    $stmt_obra->execute([$id_obra_get]);
    if ($row_obra = $stmt_obra->fetch(PDO::FETCH_ASSOC)) {
        $nome_obra_get = $row_obra['nome'];
        $nome_cliente_get = $row_obra['cliente_nome'];
    }
}

if (isset($_GET['editado']) && $_GET['editado'] == 1) {
    $mensagem = "Relatório de Obra guardado com sucesso!";
    $tipo_mensagem = "sucesso";
}

// Obras com o respetivo cliente (1º escolhe-se o cliente, depois a obra desse cliente)
$sql_obras = "SELECT o.id, o.nome, o.referencia_obra, o.id_cliente, c.nome AS cliente_nome
              FROM obras o LEFT JOIN clientes c ON c.id = o.id_cliente
              WHERE o.estado != 'Concluída' AND o.estado != 'Cancelada'
              ORDER BY o.nome ASC";
if ($modo_editar) {
    $sql_obras = "SELECT o.id, o.nome, o.referencia_obra, o.id_cliente, c.nome AS cliente_nome
                  FROM obras o LEFT JOIN clientes c ON c.id = o.id_cliente
                  ORDER BY o.nome ASC";
}
$obras = $pdo->query($sql_obras)->fetchAll(PDO::FETCH_ASSOC);

// Clientes que têm obras na lista (ordem alfabética)
$clientes_obras = [];
foreach ($obras as $o) {
    $cid = (int)($o['id_cliente'] ?? 0);
    if (!isset($clientes_obras[$cid])) {
        $clientes_obras[$cid] = trim((string)($o['cliente_nome'] ?? '')) !== '' ? $o['cliente_nome'] : 'Sem cliente';
    }
}
uasort($clientes_obras, function ($a, $b) { return strcasecmp($a, $b); });

$is_admin = in_array($_SESSION['perfil'] ?? '', ['admin', 'administrativo']);
$colaboradores = [];

if ($is_admin) {
    $colaboradores = $pdo->query("SELECT id, nome FROM utilizadores WHERE perfil IN ('admin', 'funcionario', 'administrativo') AND ativo = 1 ORDER BY nome ASC")->fetchAll(PDO::FETCH_ASSOC);
}

$materiais_db = [];
$faturas_db = [];
$relatorio = [];

if ($modo_editar) {
    $stmt = $pdo->prepare("SELECT * FROM relatorios_obra WHERE id = ?");
    $stmt->execute([$id]);
    $relatorio = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$relatorio) {
        header("Location: relatorios.php");
        exit;
    }

    $materiais_db = json_decode($relatorio['materiais'] ?? '[]', true);
    $faturas_db = json_decode($relatorio['faturas'] ?? '[]', true);
    $titulo_pagina = "Editar Relatório #" . $relatorio['id'];
} else {
    $titulo_pagina = "Novo Relatório de Obra";
}

// LÓGICA DO COLABORADOR BLOQUEADO
$id_colab_bloqueado = $_SESSION['user_id'];
$nome_colab_bloqueado = $_SESSION['nome'];

if ($modo_editar && !$is_admin) {
    $id_colab_bloqueado = $relatorio['colaborador_1'] ?? $_SESSION['user_id'];
    $stmt_nome = $pdo->prepare("SELECT nome FROM utilizadores WHERE id = ?");
    $stmt_nome->execute([$id_colab_bloqueado]);
    $nome_colab_bloqueado = $stmt_nome->fetchColumn() ?: $_SESSION['nome'];
}

// ==========================================
// PROCESSAR GRAVAÇÃO E VERIFICAR CHOQUES
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $h1_ent = $_POST['h1_entrada'];
    $h1_sai = $_POST['h1_saida'];
    $h2_ent = (!empty($_POST['has_h2']) && !empty($_POST['h2_entrada'])) ? $_POST['h2_entrada'] : null;
    $h2_sai = (!empty($_POST['has_h2']) && !empty($_POST['h2_saida'])) ? $_POST['h2_saida'] : null;

    $data_alvo = $_POST['data_relatorio'];
    $colab_alvo = $is_admin ? $_POST['colaborador_1'] : $_POST['colaborador_seguro'];

    function checkOverlap($s1, $e1, $s2, $e2) {
        if (!$s1 || !$e1 || !$s2 || !$e2) return false;
        return (strtotime($s1) < strtotime($e2) && strtotime($e1) > strtotime($s2));
    }

    $conflitos = [];

    // 1. Verificar noutros Relatórios de Obra
    $sql_rel = "SELECT id, h1_entrada, h1_saida, h2_entrada, h2_saida FROM relatorios_obra WHERE colaborador_1 = ? AND data_relatorio = ?";
    $params_rel = [$colab_alvo, $data_alvo];
    if ($modo_editar) { $sql_rel .= " AND id != ?"; $params_rel[] = $id; }

    $stmt_chk = $pdo->prepare($sql_rel);
    $stmt_chk->execute($params_rel);
    foreach ($stmt_chk->fetchAll() as $r_chk) {
        if (checkOverlap($h1_ent, $h1_sai, $r_chk['h1_entrada'], $r_chk['h1_saida'])) $conflitos[] = "Relatório #" . $r_chk['id'];
        if (checkOverlap($h1_ent, $h1_sai, $r_chk['h2_entrada'], $r_chk['h2_saida'])) $conflitos[] = "Relatório #" . $r_chk['id'];
        if (checkOverlap($h2_ent, $h2_sai, $r_chk['h1_entrada'], $r_chk['h1_saida'])) $conflitos[] = "Relatório #" . $r_chk['id'];
        if (checkOverlap($h2_ent, $h2_sai, $r_chk['h2_entrada'], $r_chk['h2_saida'])) $conflitos[] = "Relatório #" . $r_chk['id'];
    }

    // 2. Verificar em Serviços
    $stmt_srv = $pdo->prepare("SELECT id, h1_entrada, h1_saida, h2_entrada, h2_saida FROM servicos WHERE (colaborador_1 = ? OR colaborador_2 = ?) AND data_servico = ?");
    $stmt_srv->execute([$colab_alvo, $colab_alvo, $data_alvo]);
    foreach ($stmt_srv->fetchAll() as $s_chk) {
        if (checkOverlap($h1_ent, $h1_sai, $s_chk['h1_entrada'], $s_chk['h1_saida'])) $conflitos[] = "Serviço #" . $s_chk['id'];
        if (checkOverlap($h1_ent, $h1_sai, $s_chk['h2_entrada'], $s_chk['h2_saida'])) $conflitos[] = "Serviço #" . $s_chk['id'];
        if (checkOverlap($h2_ent, $h2_sai, $s_chk['h1_entrada'], $s_chk['h1_saida'])) $conflitos[] = "Serviço #" . $s_chk['id'];
        if (checkOverlap($h2_ent, $h2_sai, $s_chk['h2_entrada'], $s_chk['h2_saida'])) $conflitos[] = "Serviço #" . $s_chk['id'];
    }

    // 3. Verificar em Visitas
    $stmt_vis = $pdo->prepare("SELECT id, hora_visita FROM visitas WHERE colaborador_1 = ? AND data_visita = ?");
    $stmt_vis->execute([$colab_alvo, $data_alvo]);
    foreach ($stmt_vis->fetchAll() as $v_chk) {
        $hora_ini_vis = $v_chk['hora_visita'];
        $hora_fim_vis = date('H:i', strtotime($hora_ini_vis) + 3600); // +1 hora
        if (checkOverlap($h1_ent, $h1_sai, $hora_ini_vis, $hora_fim_vis)) $conflitos[] = "Visita #" . $v_chk['id'];
        if (checkOverlap($h2_ent, $h2_sai, $hora_ini_vis, $hora_fim_vis)) $conflitos[] = "Visita #" . $v_chk['id'];
    }

    // SE HOUVER CONFLITO -> MANTÉM OS DADOS
    if (count($conflitos) > 0) {
        $mensagem = "Erro: Já tem um horário sobreposto registado nesse período (" . implode(", ", array_unique($conflitos)) . ").";
        $tipo_mensagem = "erro";

        $relatorio['id_obra'] = $_POST['id_obra'];
        $relatorio['data_relatorio'] = $_POST['data_relatorio'];
        $relatorio['colaborador_1'] = $colab_alvo;
        $relatorio['h1_entrada'] = $h1_ent;
        $relatorio['h1_saida'] = $h1_sai;
        $relatorio['h2_entrada'] = $h2_ent;
        $relatorio['h2_saida'] = $h2_sai;
        $relatorio['trabalho_realizado'] = $_POST['trabalho_realizado'] ?? '';
        $relatorio['trabalho_extra'] = isset($_POST['trabalho_extra']) ? 1 : 0;
        $relatorio['foi_sozinho'] = isset($_POST['foi_sozinho']) ? 1 : 0;
        $relatorio['custo_refeicao_1'] = $_POST['custo_refeicao_1'] ?? '';

        $materiais_db = [];
        if(isset($_POST['mat_desc'])){
            foreach($_POST['mat_desc'] as $k => $v){
                if(trim($v) !== '') $materiais_db[] = ['desc' => trim($v), 'qtd' => trim($_POST['mat_qtd'][$k])];
            }
        }
        $faturas_db = [];
        if(isset($_POST['fat_forn'])){
            foreach($_POST['fat_forn'] as $k => $v){
                if(trim($v) !== '') $faturas_db[] = ['forn' => trim($v), 'num' => trim($_POST['fat_num'][$k])];
            }
        }

    } else {
        // SEM CONFLITOS -> GRAVAR NA BASE DE DADOS
        try {
            $pdo->beginTransaction();

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

            $total_horas_decimal = 0;
            if (!empty($h1_ent) && !empty($h1_sai)) {
                $t1 = (strtotime($h1_sai) - strtotime($h1_ent)) / 3600;
                if ($t1 > 0) $total_horas_decimal += $t1;
            }
            if (!empty($h2_ent) && !empty($h2_sai)) {
                $t2 = (strtotime($h2_sai) - strtotime($h2_ent)) / 3600;
                if ($t2 > 0) $total_horas_decimal += $t2;
            }

            $trabalho_extra = (!empty($_POST['trabalho_extra'])) ? 1 : 0;
            $foi_sozinho = (!empty($_POST['foi_sozinho'])) ? 1 : 0;
            $has_refeicao = (!empty($_POST['has_refeicao'])) ? true : false;
            $custo_ref_1 = ($has_refeicao && !empty($_POST['custo_refeicao_1'])) ? str_replace(',', '.', $_POST['custo_refeicao_1']) : null;

            $dados = [
                $_POST['id_obra'], $_POST['data_relatorio'], $colab_alvo, $h1_ent, $h1_sai, $h2_ent, $h2_sai,
                $total_horas_decimal, trim($_POST['trabalho_realizado']),
                json_encode($materiais, JSON_UNESCAPED_UNICODE), json_encode($faturas, JSON_UNESCAPED_UNICODE),
                $custo_ref_1, $trabalho_extra, $foi_sozinho
            ];

            if ($modo_editar) {
                $dados[] = $id;
                $sql_upd = "UPDATE relatorios_obra SET id_obra=?, data_relatorio=?, colaborador_1=?, h1_entrada=?, h1_saida=?, h2_entrada=?, h2_saida=?, total_horas=?, trabalho_realizado=?, materiais=?, faturas=?, custo_refeicao_1=?, trabalho_extra=?, foi_sozinho=? WHERE id=?";
                $pdo->prepare($sql_upd)->execute($dados);
                $relatorio_id_atual = $id;
            } else {
                $sql_ins = "INSERT INTO relatorios_obra (id_obra, data_relatorio, colaborador_1, h1_entrada, h1_saida, h2_entrada, h2_saida, total_horas, trabalho_realizado, materiais, faturas, custo_refeicao_1, trabalho_extra, foi_sozinho) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)";
                $pdo->prepare($sql_ins)->execute($dados);
                $relatorio_id_atual = $pdo->lastInsertId();
            }

            // ==============================================================
            // MAGIA: AUTOMATIZAÇÃO DE CUSTOS E RATEIO DE KM NA TABELA DESPESAS
            // ==============================================================

            // 1. Buscar Configurações e KM da Obra
            $stmt_conf = $pdo->query("SELECT chave, valor FROM configuracoes WHERE chave IN ('valor_hora', 'preco_km')");
            $configs = $stmt_conf->fetchAll(PDO::FETCH_KEY_PAIR);
            $valor_hora_global = (float)str_replace(',', '.', $configs['valor_hora'] ?? 0);
            $preco_km_global = (float)str_replace(',', '.', $configs['preco_km'] ?? 0);

            $stmt_ob = $pdo->prepare("SELECT km FROM obras WHERE id = ?");
            $stmt_ob->execute([$_POST['id_obra']]);
            $km_obra = (float)str_replace(',', '.', $stmt_ob->fetchColumn() ?? 0);

            // 2. Apagar Despesas Antigas deste Dia (Geradas por Relatórios)
            $pdo->prepare("DELETE FROM obra_despesas WHERE obra_id = ? AND data_despesa = ? AND origem = 'Relatório'")->execute([$_POST['id_obra'], $_POST['data_relatorio']]);

            // 3. Puxar TODOS os relatórios deste dia para esta obra
            $stmt_rels = $pdo->prepare("SELECT id, total_horas, custo_refeicao_1, foi_sozinho FROM relatorios_obra WHERE id_obra = ? AND data_relatorio = ?");
            $stmt_rels->execute([$_POST['id_obra'], $_POST['data_relatorio']]);
            $rels_do_dia = $stmt_rels->fetchAll(PDO::FETCH_ASSOC);

            // 4. Calcular o Rateio de KM para quem NÃO foi sozinho
            $count_shared = 0;
            foreach ($rels_do_dia as $r) { if ($r['foi_sozinho'] == 0) $count_shared++; }

            $km_atribuido_shared = 0;
            if ($count_shared > 0) {
                $vans_shared = ceil($count_shared / 3); // 3 Pessoas por Carrinha
                $km_atribuido_shared = ($vans_shared * $km_obra) / $count_shared; // Divisão justa para não prejudicar ninguém
            }

            // 5. Injetar as Novas Despesas Atualizadas e Cachear no Relatório
            $stmt_ins_desp = $pdo->prepare("INSERT INTO obra_despesas (obra_id, tipo_despesa, data_despesa, descricao, quantidade, valor, origem, id_relatorio) VALUES (?, ?, ?, ?, ?, ?, 'Relatório', ?)");
            $stmt_upd_rel = $pdo->prepare("UPDATE relatorios_obra SET km_percorridos=?, valor_km=?, valor_refeicao=?, valor_hora=? WHERE id=?");

            foreach ($rels_do_dia as $r) {
                // Cálculos
                $val_mo = $r['total_horas'] * $valor_hora_global;
                $val_ref = (float)$r['custo_refeicao_1'];
                $meus_kms = ($r['foi_sozinho'] == 1) ? $km_obra : $km_atribuido_shared;
                $val_desloc = $meus_kms * $preco_km_global;

                // Injetar em Despesas
                if ($val_mo > 0) $stmt_ins_desp->execute([$_POST['id_obra'], 'Mão de Obra', $_POST['data_relatorio'], 'Horas Trabalhadas', $r['total_horas'], $val_mo, $r['id']]);
                if ($val_ref > 0) $stmt_ins_desp->execute([$_POST['id_obra'], 'Refeição', $_POST['data_relatorio'], 'Refeição Diária', 1, $val_ref, $r['id']]);
                if ($val_desloc > 0) $stmt_ins_desp->execute([$_POST['id_obra'], 'Deslocação', $_POST['data_relatorio'], 'Rateio de Viagem', $meus_kms, $val_desloc, $r['id']]);

                // Atualizar o próprio relatório para Histórico (REMOVIDO O importado_financeiro=1 PARA EVITAR ERRO)
                $stmt_upd_rel->execute([$meus_kms, $val_desloc, $val_ref, $valor_hora_global, $r['id']]);
            }

            $pdo->commit();
            registrarLog($pdo, ($modo_editar ? "Editou" : "Criou") . " Relatório de Obra #" . $relatorio_id_atual, "Relatórios");

            $redirect = $_POST['redirect_action'] ?? 'voltar';
            if ($redirect === 'imprimir') {
                header("Location: relatorio_form.php?id=$relatorio_id_atual&acao=imprimir");
            } else {
                header("Location: relatorios.php?editado=1");
            }
            exit;

        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $mensagem = $e->getMessage(); $tipo_mensagem = "erro";
        }
    }
}

// Obra e cliente já escolhidos (ao editar, ou ao voltar com erro)
$id_obra_atual = (int)($_POST['id_obra'] ?? ($relatorio['id_obra'] ?? 0));
$id_cliente_atual = '';
foreach ($obras as $o) {
    if ((int)$o['id'] === $id_obra_atual) { $id_cliente_atual = (string)(int)($o['id_cliente'] ?? 0); break; }
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
        .btn-back:hover { color: #16a085; }

        .user-menu { position: relative; }
        .user-btn { background: #fdfdfd; border: 1px solid #f1f2f6; border-radius: 30px; font-size: 14px; font-weight: 600; color: #2c3e50; cursor: pointer; display: flex; align-items: center; gap: 10px; padding: 6px 15px 6px 6px; transition: 0.2s; }
        .user-btn:hover { background: #fff; box-shadow: 0 4px 10px rgba(0,0,0,0.05); }
        .user-avatar { background: #e74c3c; color: white; width: 28px; height: 28px; border-radius: 50%; display: flex; justify-content: center; align-items: center; font-size: 14px; border: 2px solid #fadbd8; }

        .dropdown-content { display: none; position: absolute; right: 0; top: 100%; background: #fff; min-width: 180px; box-shadow: 0 10px 30px rgba(0,0,0,0.1); border-radius: 8px; border: 1px solid #f1f2f6; overflow: hidden; margin-top: 5px; z-index: 200; }
        .user-menu:hover .dropdown-content { display: block; }
        .dropdown-content a { color: #576574; padding: 10px 20px; text-decoration: none; display: block; font-size: 14px; }
        .dropdown-content a:hover { background: #fdf2f2; color: #16a085; }

        .container { max-width: 900px; margin: 15px auto; padding: 0 15px; }

        .header-box { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
        .header-box h1 { margin: 0; font-size: 18px; color: #2c3e50; }

        .btn-print { background: #fff; color: #3498db; border: 1px solid #dcdde1; padding: 8px 15px; border-radius: 8px; text-decoration: none; font-weight: 600; display: inline-flex; align-items: center; gap: 8px; transition: 0.2s; cursor: pointer; }
        .btn-print:hover { border-color: #3498db; background: #f0f8ff; }

        .card { background: #fff; padding: 25px; border-radius: 12px; border: 1px solid #e1e5ea; box-shadow: 0 2px 10px rgba(0,0,0,0.03); margin-bottom: 15px; }
        h2 { font-size: 14px; color: #16a085; margin: 0 0 15px 0; border-bottom: 1px solid #f1f2f6; padding-bottom: 8px; text-transform: uppercase; display: flex; align-items: center; gap: 8px; }

        .form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 15px; }
        .form-group { display: flex; flex-direction: column; margin-bottom: 15px; position: relative; }
        label { font-weight: 600; margin-bottom: 6px; font-size: 11px; color: #576574; text-transform: uppercase; }
        input, select, textarea { padding: 12px; border: 1px solid #dcdde1; border-radius: 8px; transition: 0.2s; background: #fff; width: 100%; }
        input:focus, select:focus, textarea:focus { border-color: #16a085; }
        select:disabled { background-color: #f1f2f6; color: #95a5a6; cursor: not-allowed; }

        .switch-container { display: flex; align-items: center; gap: 10px; margin-bottom: 15px; }
        .switch { position: relative; display: inline-block; width: 36px; height: 20px; flex-shrink: 0; }
        .switch input { opacity: 0; width: 0; height: 0; }
        .slider { position: absolute; cursor: pointer; top: 0; left: 0; right: 0; bottom: 0; background-color: #dcdde1; transition: .3s; border-radius: 20px; }
        .slider:before { position: absolute; content: ""; height: 14px; width: 14px; left: 3px; bottom: 3px; background-color: white; transition: .3s; border-radius: 50%; box-shadow: 0 2px 4px rgba(0,0,0,0.2); }
        input:checked + .slider { background-color: #16a085; }
        input:checked + .slider:before { transform: translateX(16px); }
        .switch-label { font-weight: 700; font-size: 12px; color: #2c3e50; text-transform: uppercase; cursor: pointer; }

        .hidden-section { display: none; margin-bottom: 15px; padding: 15px; background: #fdfdfd; border-radius: 8px; border: 1px dashed #dcdde1; }

        .box-total-horas { background: #e8f8f5; border: 1px solid #d1f2eb; padding: 15px; border-radius: 8px; display: flex; flex-direction: column; justify-content: center; align-items: center; height: 100%; min-height: 80px; }
        .box-total-horas label { color: #117964; margin-bottom: 5px; }
        .valor-horas { font-size: 22px; font-weight: 800; color: #16a085; line-height: 1; }

        .dyn-row { display: grid; grid-template-columns: 1fr 90px 40px; gap: 10px; margin-bottom: 10px; align-items: center; }
        .dyn-row-fat { grid-template-columns: 1fr 1fr 40px; }
        .btn-del { color: #e74c3c; background: none; border: none; cursor: pointer; font-size: 18px; padding: 5px; }
        .btn-add { background: #576574; color: #fff; border: none; padding: 8px 15px; border-radius: 6px; cursor: pointer; font-size: 14px; transition: 0.2s; display: inline-flex; align-items: center; justify-content: center; }
        .btn-add:hover { background: #2c3e50; }

        .floating-action-bar { position: fixed; bottom: 20px; right: 20px; left: 20px; max-width: 900px; margin: 0 auto; background: #fff; padding: 15px 25px; border-radius: 12px; box-shadow: 0 -5px 20px rgba(0,0,0,0.15); display: flex; justify-content: flex-end; border: 1px solid #e1e5ea; z-index: 1000; }
        .btn-save { background: #16a085; color: #fff; padding: 12px 30px; border-radius: 8px; border: none; font-weight: 700; font-size: 14px; text-transform: uppercase; cursor: pointer; transition: 0.2s; display: flex; align-items: center; gap: 8px; }
        .btn-save:hover { background: #117964; }

        .alert { padding: 15px; border-radius: 8px; margin-bottom: 15px; text-align: center; font-weight: 600; }
        .alert-erro { background: #fdeded; color: #c0392b; border: 1px solid #f9cccc; }
        .alert-sucesso { background: #e8f5e9; color: #2e7d32; border: 1px solid #c8e6c9; }

        /* Modais */
        .modal { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.6); z-index: 1001; justify-content: center; align-items: center; padding: 20px; }
        .modal.show { display: flex; }
        .modal-content { background: #fff; padding: 30px; border-radius: 12px; width: 100%; max-width: 400px; text-align: center; }
        .modal-btns { display: flex; flex-direction: column; gap: 10px; margin-top: 20px; }

        .btn-primary { background: #16a085; color: #fff; padding: 14px; border-radius: 8px; text-decoration: none; font-weight: 600; font-size: 15px; border: none; cursor: pointer; }
        .btn-secondary { background: #f1f2f6; color: #576574; padding: 14px; border-radius: 8px; text-decoration: none; font-weight: 600; font-size: 15px; border: none; cursor: pointer; }

        .modal-print-content { max-width: 450px; text-align: left; padding: 20px 25px; }
        .modal-print-header { display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #f1f2f6; padding-bottom: 15px; margin-bottom: 15px; }
        .modal-print-header h3 { margin: 0; color: #16a085; font-size: 16px; display: flex; align-items: center; gap: 8px; }
        .close-btn { color: #95a5a6; font-size: 22px; cursor: pointer; font-weight: bold; background: none; border: none; padding: 0; line-height: 1; transition: 0.2s; }
        .close-btn:hover { color: #333; }
        .modal-subtitle { color: #576574; font-size: 14px; font-weight: 700; margin-bottom: 15px; text-align: left; }

        .btn-pdf-option { display: flex; align-items: center; gap: 15px; width: 100%; padding: 15px; background: #fdfdfd; border: 1px solid #e1e5ea; border-radius: 8px; cursor: pointer; text-align: left; transition: all 0.2s; margin-bottom: 10px; font-family: inherit; text-decoration: none;}
        .btn-pdf-option:hover { background: #f9f9f9; border-color: #16a085; box-shadow: 0 2px 5px rgba(0,0,0,0.02); }
        .btn-pdf-option i { color: #16a085; font-size: 22px; }
        .btn-pdf-option span { color: #2c3e50; font-weight: 600; font-size: 14px; }

        @media (max-width: 768px) {
            .topbar { padding: 10px 15px; }
            .topbar-center { display: none; }
            .form-grid { grid-template-columns: 1fr; gap: 15px; }
            .card { padding: 15px; }
            .box-total-horas { min-height: auto; margin-top: 10px; }
        }
    </style>
</head>
<body>

    <header class="topbar">
        <div class="topbar-left"><a href="index.php"><img src="mab.png" alt="MAB" class="logo"></a></div>
        <div class="topbar-center">
            <a href="index.php" class="nav-icon" title="Início"><i class="fa-solid fa-house"></i></a>
            <?php if ($is_admin || in_array('clientes', $meus_acessos)): ?><a href="clientes.php" class="nav-icon i-clientes" title="Clientes"><i class="fa-solid fa-users"></i></a><?php endif; ?>
            <?php if ($is_admin || in_array('visitas', $meus_acessos)): ?><a href="visitas.php" class="nav-icon i-visitas" title="Visitas"><i class="fa-solid fa-calendar-check"></i></a><?php endif; ?>
            <?php if ($is_admin || in_array('servicos', $meus_acessos)): ?><a href="servicos.php" class="nav-icon i-servicos" title="Serviços"><i class="fa-solid fa-screwdriver-wrench"></i></a><?php endif; ?>
            <?php if ($is_admin || in_array('obras', $meus_acessos)): ?><a href="obras.php" class="nav-icon i-obras" title="Obras"><i class="fa-solid fa-helmet-safety"></i></a><?php endif; ?>
            <a href="relatorios.php" class="nav-icon i-relatorios active" title="Relatórios de Obra"><i class="fa-solid fa-clipboard-list"></i></a>
            <?php if ($is_admin || in_array('orcamentos', $meus_acessos)): ?><a href="orcamentos.php" class="nav-icon i-orcamentos" title="Orçamentos"><i class="fa-solid fa-file-invoice"></i></a><?php endif; ?>
            <?php if ($is_admin || in_array('termos', $meus_acessos)): ?><a href="termos.php" class="nav-icon i-termos" title="Termos"><i class="fa-solid fa-file-signature"></i></a><?php endif; ?>
            <?php if ($is_admin || in_array('equipa', $meus_acessos)): ?><a href="utilizadores.php" class="nav-icon i-equipa" title="Equipa"><i class="fa-solid fa-id-card-clip"></i></a><?php endif; ?>
            <?php if ($is_admin || in_array('configuracoes', $meus_acessos)): ?><a href="configuracoes.php" class="nav-icon i-config" title="Configurações"><i class="fa-solid fa-gear"></i></a><?php endif; ?>
        </div>
        <div class="topbar-right">
            <a href="relatorios.php" class="btn-back" title="Voltar"><i class="fa-solid fa-arrow-left"></i></a>
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
            <div class="alert <?= $tipo_mensagem === 'sucesso' ? 'alert-sucesso' : 'alert-erro' ?>">
                <?= $tipo_mensagem === 'erro' ? '<i class="fa-solid fa-triangle-exclamation"></i>' : '<i class="fa-solid fa-check-circle"></i>' ?>
                <?= htmlspecialchars($mensagem, ENT_QUOTES, 'UTF-8') ?>
            </div>
        <?php endif; ?>

        <div class="header-box">
            <h1><?= htmlspecialchars($titulo_pagina) ?></h1>
            <?php if ($modo_editar && $tipo_mensagem !== 'erro'): ?>
                <button type="button" class="btn-print" onclick="abrirModalImprimir()"><i class="fa-solid fa-print"></i> Imprimir</button>
            <?php endif; ?>
        </div>

        <form method="POST" id="formRelatorio">
            <input type="hidden" name="redirect_action" id="redirect_action" value="voltar">

            <div class="card">
                <h2><i class="fa-solid fa-helmet-safety"></i> Cliente, Obra e Equipa</h2>

                <?php if (!$modo_editar && !empty($id_obra_get) && !empty($nome_obra_get)): ?>
                    <!-- Obra já definida pelo link: cliente e obra só para consulta -->
                    <div class="form-grid">
                        <div class="form-group">
                            <label>Cliente</label>
                            <input type="text" value="<?= htmlspecialchars($nome_cliente_get ?: 'Sem cliente') ?>" disabled style="background-color: #f1f2f6; color: #7f8c8d; font-weight: 600; cursor: not-allowed;">
                        </div>
                        <div class="form-group">
                            <label>Obra *</label>
                            <input type="text" value="<?= htmlspecialchars($nome_obra_get) ?>" disabled style="background-color: #f1f2f6; color: #7f8c8d; font-weight: 600; cursor: not-allowed;">
                            <input type="hidden" name="id_obra" value="<?= htmlspecialchars($id_obra_get) ?>">
                        </div>
                    </div>
                <?php else: ?>
                    <!-- 1º escolhe-se o cliente, depois a obra desse cliente -->
                    <div class="form-grid">
                        <div class="form-group">
                            <label>1. Cliente *</label>
                            <select id="sel_cliente" required onchange="filtrarObras()">
                                <option value="">-- Selecione o Cliente --</option>
                                <?php foreach ($clientes_obras as $cid => $cnome): ?>
                                    <option value="<?= (int)$cid ?>" <?= ($id_cliente_atual !== '' && (int)$id_cliente_atual === (int)$cid) ? 'selected' : '' ?>><?= htmlspecialchars($cnome) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>2. Obra *</label>
                            <select name="id_obra" id="sel_obra" required>
                                <option value="">-- Selecione primeiro o Cliente --</option>
                                <?php foreach($obras as $o): ?>
                                    <option value="<?= $o['id'] ?>" data-cliente="<?= (int)($o['id_cliente'] ?? 0) ?>" <?= ($id_obra_atual === (int)$o['id']) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($o['nome']) ?> <?= !empty($o['referencia_obra']) ? " (Ref: " . htmlspecialchars($o['referencia_obra']) . ")" : "" ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                <?php endif; ?>

                <div class="form-grid" style="align-items: center;">
                    <div class="form-group" style="margin-bottom:0;">
                        <label>Colaborador Responsável *</label>
                        <?php if ($is_admin): ?>
                            <select name="colaborador_1" required>
                                <option value="">-- Selecione --</option>
                                <?php foreach($colaboradores as $c): ?>
                                    <option value="<?= $c['id'] ?>" <?= ((isset($relatorio['colaborador_1']) && $relatorio['colaborador_1'] == $c['id']) || (isset($_POST['colaborador_1']) && $_POST['colaborador_1'] == $c['id']) || (!isset($relatorio['colaborador_1']) && !isset($_POST['colaborador_1']) && $_SESSION['user_id'] == $c['id'])) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($c['nome']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        <?php else: ?>
                            <input type="text" value="<?= htmlspecialchars($nome_colab_bloqueado) ?>" disabled style="background-color: #f1f2f6; color: #7f8c8d; font-weight: 600; cursor: not-allowed;">
                            <input type="hidden" name="colaborador_seguro" value="<?= htmlspecialchars($id_colab_bloqueado) ?>">
                        <?php endif; ?>
                    </div>

                    <div class="switch-container" style="margin:0; padding-top:15px; justify-content: flex-end;">
                        <label class="switch">
                            <input type="checkbox" name="foi_sozinho" id="chk_sozinho" value="1" <?= (!empty($relatorio['foi_sozinho'])) ? 'checked' : '' ?>>
                            <span class="slider" style="background-color: #dcdde1;"></span>
                        </label>
                        <label class="switch-label" for="chk_sozinho" style="color: #2c3e50;">Fui sozinho na viatura</label>
                    </div>
                </div>
            </div>

            <div class="card">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px; border-bottom: 1px solid #f1f2f6; padding-bottom: 8px;">
                    <h2 style="border-bottom: none; margin: 0; padding: 0;"><i class="fa-solid fa-clock"></i> Registo Diário</h2>

                    <div class="switch-container" style="margin:0;">
                        <label class="switch">
                            <input type="checkbox" name="trabalho_extra" id="chk_extra" value="1" <?= (!empty($relatorio['trabalho_extra'])) ? 'checked' : '' ?>>
                            <span class="slider" style="background-color: #dcdde1;"></span>
                        </label>
                        <label class="switch-label" for="chk_extra" style="color: #e67e22;">Trabalho Extra</label>
                    </div>
                </div>

                <div class="form-grid" style="grid-template-columns: 2fr 1fr;">
                    <div>
                        <div class="form-group">
                            <label>Data *</label>
                            <input type="date" name="data_relatorio" value="<?= htmlspecialchars($relatorio['data_relatorio'] ?? date('Y-m-d')) ?>" required>
                        </div>

                        <div class="form-grid">
                            <div class="form-group"><label>Entrada (1º Horário) *</label><input type="time" name="h1_entrada" id="h1_entrada" value="<?= htmlspecialchars($relatorio['h1_entrada'] ?? '') ?>" required></div>
                            <div class="form-group"><label>Saída (1º Horário) *</label><input type="time" name="h1_saida" id="h1_saida" value="<?= htmlspecialchars($relatorio['h1_saida'] ?? '') ?>" required></div>
                        </div>

                        <div class="switch-container" style="margin-bottom: 10px;">
                            <label class="switch">
                                <input type="checkbox" name="has_h2" id="chk_h2" value="1" onchange="toggleSec('h2', this)" <?= (!empty($relatorio['h2_entrada'])) ? 'checked' : '' ?>>
                                <span class="slider"></span>
                            </label>
                            <label class="switch-label" for="chk_h2">+ 2º Horário?</label>
                        </div>

                        <div id="sec_h2" class="hidden-section" style="<?= (!empty($relatorio['h2_entrada'])) ? 'display:block;' : '' ?>">
                            <div class="form-grid">
                                <div class="form-group" style="margin-bottom:0;"><label>Entrada (2º Horário) *</label><input type="time" name="h2_entrada" id="h2_entrada" value="<?= htmlspecialchars($relatorio['h2_entrada'] ?? '') ?>"></div>
                                <div class="form-group" style="margin-bottom:0;"><label>Saída (2º Horário) *</label><input type="time" name="h2_saida" id="h2_saida" value="<?= htmlspecialchars($relatorio['h2_saida'] ?? '') ?>"></div>
                            </div>
                        </div>
                    </div>

                    <div class="box-total-horas">
                        <label>Total de Horas</label>
                        <div id="display_total_horas" class="valor-horas">0h 00m</div>
                    </div>
                </div>

                <div class="switch-container" style="margin-top: 15px;">
                    <label class="switch">
                        <input type="checkbox" name="has_refeicao" id="chk_refeicao" value="1" onchange="toggleSec('refeicao', this)" <?= (!empty($relatorio['custo_refeicao_1'])) ? 'checked' : '' ?>>
                        <span class="slider"></span>
                    </label>
                    <label class="switch-label" for="chk_refeicao">+ Refeição?</label>
                </div>

                <div id="sec_refeicao" class="hidden-section" style="<?= (!empty($relatorio['custo_refeicao_1'])) ? 'display:block;' : '' ?>">
                    <div class="form-group" style="margin-bottom:0; max-width: 50%;">
                        <label>Custo Refeição *</label>
                        <input type="text" name="custo_refeicao_1" id="custo_refeicao_1" value="<?= htmlspecialchars($relatorio['custo_refeicao_1'] ?? '') ?>" placeholder="0,00" onblur="formatCusto(this)">
                    </div>
                </div>
            </div>

            <div class="card">
                <h2><i class="fa-solid fa-clipboard-check"></i> Trabalho Realizado</h2>
                <div class="form-group" style="margin-bottom:0;">
                    <textarea name="trabalho_realizado" rows="5" required placeholder="Descreva as tarefas concluídas neste dia de obra..."><?= htmlspecialchars($relatorio['trabalho_realizado'] ?? '') ?></textarea>
                </div>
            </div>

            <div class="card">
                <h2><i class="fa-solid fa-box"></i> Materiais Aplicados na Obra</h2>
                <div id="wrap_mat">
                    <?php if(!empty($materiais_db)): foreach($materiais_db as $m): ?>
                        <div class="dyn-row">
                            <input type="text" name="mat_desc[]" value="<?= htmlspecialchars($m['desc']) ?>">
                            <input type="number" step="0.01" name="mat_qtd[]" value="<?= htmlspecialchars($m['qtd']) ?>">
                            <button type="button" class="btn-del" onclick="this.parentElement.remove()"><i class="fa-solid fa-trash-can"></i></button>
                        </div>
                    <?php endforeach; endif; ?>
                </div>
                <button type="button" class="btn-add" onclick="addLinha('mat')"><i class="fa-solid fa-plus"></i></button>
            </div>

            <div class="card" style="margin-bottom:40px;">
                <h2><i class="fa-solid fa-receipt"></i> Despesas Externas / Faturas</h2>
                <div id="wrap_fat">
                    <?php if(!empty($faturas_db)): foreach($faturas_db as $f): ?>
                        <div class="dyn-row dyn-row-fat">
                            <input type="text" name="fat_forn[]" value="<?= htmlspecialchars($f['forn']) ?>">
                            <input type="text" name="fat_num[]" value="<?= htmlspecialchars($f['num']) ?>">
                            <button type="button" class="btn-del" onclick="this.parentElement.remove()"><i class="fa-solid fa-trash-can"></i></button>
                        </div>
                    <?php endforeach; endif; ?>
                </div>
                <button type="button" class="btn-add" onclick="addLinha('fat')"><i class="fa-solid fa-plus"></i></button>
            </div>

            <div class="floating-action-bar">
                <button type="submit" class="btn-save"><i class="fa-solid fa-floppy-disk"></i> GUARDAR RELATÓRIO</button>
            </div>
        </form>
    </div>

    <div class="modal" id="modalRedirect">
        <div class="modal-content">
            <h3 style="color:#2c3e50;"><i class="fa-solid fa-circle-check" style="color:#2ecc71;"></i> Relatório Guardado</h3>
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

            <div class="modal-subtitle">Relatório Nº <span id="display_id_print"><?= htmlspecialchars($id ?? '') ?></span></div>

            <button type="button" class="btn-pdf-option" onclick="gerarPDF('cliente')">
                <i class="fa-solid fa-file-pdf"></i>
                <span>Relatório Cliente (Resumo de Tempos)</span>
            </button>

            <?php if ($is_admin): ?>
            <button type="button" class="btn-pdf-option" onclick="gerarPDF('completa')">
                <i class="fa-solid fa-file-pdf"></i>
                <span>Relatório Gestão (Com Custos)</span>
            </button>
            <?php endif; ?>
        </div>
    </div>

    <script>
        const isPostSavePrint = <?= (isset($_GET['acao']) && $_GET['acao'] === 'imprimir') ? 'true' : 'false' ?>;

        // --- 1º Cliente → 2º Obra: a lista de obras mostra só as do cliente escolhido ---
        const selCliente = document.getElementById('sel_cliente');
        const selObra = document.getElementById('sel_obra');
        const todasObras = selObra ? Array.from(selObra.querySelectorAll('option[data-cliente]')) : [];
        function filtrarObras() {
            if (!selCliente || !selObra) return;
            const cli = selCliente.value;
            const obraAtual = selObra.value;
            todasObras.forEach(op => op.remove());
            const primeira = selObra.querySelector('option[value=""]');
            if (!cli) {
                primeira.textContent = '-- Selecione primeiro o Cliente --';
                selObra.value = '';
                selObra.disabled = true;
                return;
            }
            const doCliente = todasObras.filter(op => op.dataset.cliente === cli);
            doCliente.forEach(op => selObra.appendChild(op));
            primeira.textContent = doCliente.length ? '-- Selecione a Obra --' : '-- Este cliente não tem obras --';
            selObra.disabled = false;
            // mantém a obra escolhida se for deste cliente; se o cliente só tiver uma obra, escolhe-a logo
            if (doCliente.some(op => op.value === obraAtual)) selObra.value = obraAtual;
            else if (doCliente.length === 1) selObra.value = doCliente[0].value;
            else selObra.value = '';
        }

        function abrirModalImprimir() {
            document.getElementById('modalImprimir').classList.add('show');
        }

        function fecharModalImprimir() {
            document.getElementById('modalImprimir').classList.remove('show');
            if (isPostSavePrint) {
                window.location.href = 'relatorios.php';
            }
        }

        function gerarPDF(tipo) {
            const id = <?= $id ?? 0 ?>;
            if(id > 0) {
                window.open(`relatorio_pdf.php?id=${id}&tipo=${tipo}`, '_blank');
            }
            fecharModalImprimir();
        }

        function toggleSec(type, checkbox) {
            const s = document.getElementById('sec_' + type);
            if (!checkbox.checked) {
                s.style.display = 'none';
                s.querySelectorAll('input').forEach(el => el.removeAttribute('required'));
                if(type === 'h2') calcularHoras();
            } else {
                s.style.display = 'block';
                s.querySelectorAll('input').forEach(el => el.setAttribute('required', 'required'));
                if(type === 'h2') calcularHoras();
            }
        }

        function calcularHoras() {
            let totalDecimal = 0;

            const h1e = document.getElementById('h1_entrada').value;
            const h1s = document.getElementById('h1_saida').value;
            if (h1e && h1s) {
                let d1e = new Date("1970-01-01T" + h1e + "Z");
                let d1s = new Date("1970-01-01T" + h1s + "Z");
                let diff = (d1s - d1e) / (1000 * 60 * 60);
                if(diff > 0) totalDecimal += diff;
            }

            if (document.getElementById('chk_h2').checked) {
                const h2e = document.getElementById('h2_entrada').value;
                const h2s = document.getElementById('h2_saida').value;
                if (h2e && h2s) {
                    let d2e = new Date("1970-01-01T" + h2e + "Z");
                    let d2s = new Date("1970-01-01T" + h2s + "Z");
                    let diff2 = (d2s - d2e) / (1000 * 60 * 60);
                    if(diff2 > 0) totalDecimal += diff2;
                }
            }

            let horas = Math.floor(totalDecimal);
            let minutos = Math.round((totalDecimal - horas) * 60);

            document.getElementById('display_total_horas').innerText = horas + 'h ' + minutos.toString().padStart(2, '0') + 'm';
        }

        document.getElementById('h1_entrada').addEventListener('change', calcularHoras);
        document.getElementById('h1_saida').addEventListener('change', calcularHoras);
        document.getElementById('h2_entrada').addEventListener('change', calcularHoras);
        document.getElementById('h2_saida').addEventListener('change', calcularHoras);

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

        let isRedirectChosen = false;
        const form = document.getElementById('formRelatorio');

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
            filtrarObras();
            calcularHoras();
            if (isPostSavePrint) abrirModalImprimir();
        };
    </script>
</body>
</html>
