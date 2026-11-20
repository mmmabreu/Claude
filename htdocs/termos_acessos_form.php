<?php
session_start();
require_once 'db.php';

if (file_exists('functions.php')) {
    require_once 'functions.php';
}
if (!function_exists('registrarLog')) { function registrarLog($pdo, $acao, $modulo) { return true; } }

require_once 'termos_acessos_comum.php';

// REGRA DE OURO 1: Sessão
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}

// REGRA DE OURO 2: Acesso pela matriz
if (!caTemAcesso($pdo)) {
    header("Location: index.php?erro=sem_permissao");
    exit;
}

$nome_completo = $_SESSION['nome'] ?? 'Utilizador';
$primeiro_nome = explode(' ', trim($nome_completo))[0];

try { caCriarTabelas($pdo); } catch (Exception $e) { /* continua */ }

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$modo_editar = !empty($id);
$erro_msg = '';

$reg = [];
$ctrls_db = [];

if ($modo_editar) {
    $st = $pdo->prepare("SELECT * FROM termos_acessos WHERE id = ?");
    $st->execute([$id]);
    $reg = $st->fetch(PDO::FETCH_ASSOC);
    if (!$reg) { header("Location: termos_acessos.php"); exit; }

    $st = $pdo->prepare("SELECT * FROM termos_acessos_controladoras WHERE id_termo = ? ORDER BY ordem ASC, id ASC");
    $st->execute([$id]);
    $ctrls_db = $st->fetchAll(PDO::FETCH_ASSOC);
    $st = $pdo->prepare("SELECT * FROM termos_acessos_portas WHERE id_termo = ? ORDER BY numero ASC, id ASC");
    $st->execute([$id]);
    $portas_todas = $st->fetchAll(PDO::FETCH_ASSOC);
    foreach ($ctrls_db as &$c) {
        $c['portas'] = array_values(array_filter($portas_todas, fn($p) => (int)$p['id_controladora'] === (int)$c['id']));
    }
    unset($c);
}

// Técnicos = funcionários ativos
$tecnicos = [];
try {
    $tecnicos = $pdo->query("SELECT id, nome FROM utilizadores WHERE ativo = 1 AND perfil IN ('admin', 'funcionario') ORDER BY nome ASC")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    try { $tecnicos = $pdo->query("SELECT id, nome FROM utilizadores ORDER BY nome ASC")->fetchAll(PDO::FETCH_ASSOC); } catch (Exception $e2) {}
}
$tecnicos_por_id = array_column($tecnicos, 'nome', 'id');

// --- GRAVAR ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $pdo->beginTransaction();

        $id_cliente = filter_input(INPUT_POST, 'id_cliente', FILTER_VALIDATE_INT) ?: null;
        if (!$id_cliente) throw new Exception("Escolha o cliente.");
        $id_obra = filter_input(INPUT_POST, 'id_obra', FILTER_VALIDATE_INT) ?: null;

        // Número único partilhado: o da obra; sem obra, mantém o atual ou gera um novo
        if ($id_obra) {
            $st = $pdo->prepare("SELECT referencia_obra FROM obras WHERE id = ?");
            $st->execute([$id_obra]);
            $referencia = trim((string)$st->fetchColumn());
            if ($referencia === '') {
                $referencia = gerarNumeroProjeto($pdo);
                $pdo->prepare("UPDATE obras SET referencia_obra = ? WHERE id = ?")->execute([$referencia, $id_obra]);
                registrarLog($pdo, "Atribuiu Nº $referencia à obra (ID: $id_obra) via Controlo de Acessos", "Obras");
            }
        } elseif ($modo_editar && !empty($reg['referencia'])) {
            $referencia = $reg['referencia'];
        } else {
            $referencia = gerarNumeroProjeto($pdo);
        }

        $sn = function ($k) { $v = trim((string)($_POST[$k] ?? '')); return $v === '' ? null : $v; };
        $data_ou_null = function ($k) { $v = $_POST[$k] ?? ''; return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : null; };

        $id_tecnico = filter_input(INPUT_POST, 'id_tecnico', FILTER_VALIDATE_INT) ?: null;
        $tecnico_nome = $id_tecnico ? ($tecnicos_por_id[$id_tecnico] ?? null) : null;

        // Software: se for novo ("Outro"), fica gravado para as próximas escolhas
        $software = $sn('software');
        if ($software === '__outro__') $software = null;
        if ($software) caJuntarExtra($pdo, 'ca_softwares_extra', $software, $CA_SOFTWARES_BASE);

        $testes = [];
        foreach ($CA_TESTES as $k => $lbl) {
            $tv = $_POST['testes'][$k] ?? '';
            $testes[$k] = in_array($tv, ['Sim', 'Não', 'N.A.'], true) ? $tv : '';
        }

        $campos = [
            'referencia' => $referencia,
            'id_cliente' => $id_cliente,
            'id_obra' => $id_obra,
            'local_instalacao' => $sn('local_instalacao'),
            'gps' => $sn('gps'),
            'data_instalacao' => $data_ou_null('data_instalacao') ?: date('Y-m-d'),
            'id_tecnico' => $id_tecnico,
            'tecnico' => $tecnico_nome,
            'software' => $software,
            'servidor' => $sn('servidor'),
            'software_utilizador' => $sn('software_utilizador'),
            'software_password' => $sn('software_password'),
            'acesso_remoto' => $sn('acesso_remoto'),
            'testes' => json_encode($testes, JSON_UNESCAPED_UNICODE),
            'entregue_a' => $sn('entregue_a'),
            'data_entrega' => $data_ou_null('data_entrega'),
            'observacoes' => $sn('observacoes'),
        ];

        if ($modo_editar) {
            $sets = implode(', ', array_map(fn($c) => "$c = ?", array_keys($campos)));
            $pdo->prepare("UPDATE termos_acessos SET $sets, atualizado_em = NOW() WHERE id = ?")
                ->execute(array_merge(array_values($campos), [$id]));
            $id_final = $id;
            registrarLog($pdo, "Editou Registo de Controlo de Acessos Nº $referencia", "Termos Controlo de Acessos");
        } else {
            $cols = array_keys($campos);
            $vals = array_values($campos);
            $cols[] = 'criado_por'; $vals[] = $_SESSION['user_id'];
            $pdo->prepare("INSERT INTO termos_acessos (" . implode(', ', $cols) . ", criado_em) VALUES (" . implode(', ', array_fill(0, count($cols), '?')) . ", NOW())")
                ->execute($vals);
            $id_final = $pdo->lastInsertId();
            registrarLog($pdo, "Criou Registo de Controlo de Acessos Nº $referencia", "Termos Controlo de Acessos");
        }

        // Controladoras e portas (substituídas pelas do formulário)
        $pdo->prepare("DELETE FROM termos_acessos_portas WHERE id_termo = ?")->execute([$id_final]);
        $pdo->prepare("DELETE FROM termos_acessos_controladoras WHERE id_termo = ?")->execute([$id_final]);

        $st_c = $pdo->prepare("INSERT INTO termos_acessos_controladoras (id_termo, ordem, marca, modelo, num_portas, num_serie, firmware, localizacao, alimentacao, bateria, comunicacao, ip, mascara, gateway, dns, porta_tcp, mac, endereco_rs485, password_comunicacao) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $st_p = $pdo->prepare("INSERT INTO termos_acessos_portas (id_termo, id_controladora, numero, nome, leitor_entrada, leitor_saida, credenciais, fechadura, modo_fechadura, botao_saida, sensor_porta, quebra_vidros, tempo_abertura, observacoes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

        $ordem = 0;
        foreach (array_values($_POST['ctrl'] ?? []) as $c) {
            $ordem++;
            $t = function ($k) use ($c) { $v = trim((string)($c[$k] ?? '')); return $v === '' ? null : $v; };
            $marca = $t('marca');
            if ($marca === '__outro__') $marca = null;
            if ($marca) caJuntarExtra($pdo, 'ca_marcas_extra', $marca, $CA_MARCAS_BASE);
            $np = max(1, min(16, (int)($c['num_portas'] ?? 1)));
            $st_c->execute([
                $id_final, $ordem, $marca, $t('modelo'), $np, $t('num_serie'), $t('firmware'), $t('localizacao'), $t('alimentacao'), $t('bateria'),
                in_array($c['comunicacao'] ?? '', $CA_COMUNICACAO, true) ? $c['comunicacao'] : null,
                $t('ip'), $t('mascara'), $t('gateway'), $t('dns'), $t('porta_tcp'), $t('mac'), $t('endereco_rs485'), $t('password_comunicacao')
            ]);
            $id_ctrl = $pdo->lastInsertId();

            $portas = array_values($c['portas'] ?? []);
            for ($i = 0; $i < $np; $i++) {
                $p = $portas[$i] ?? [];
                $tp = function ($k) use ($p) { $v = trim((string)($p[$k] ?? '')); return $v === '' ? null : $v; };
                $sim_nao = function ($k) use ($p) { $v = $p[$k] ?? ''; return in_array($v, ['Sim', 'Não'], true) ? $v : null; };
                $creds = array_values(array_intersect((array)($p['credenciais'] ?? []), $CA_CREDENCIAIS));
                $st_p->execute([
                    $id_final, $id_ctrl, $i + 1, $tp('nome'), $tp('leitor_entrada'), $tp('leitor_saida'),
                    $creds ? implode(', ', $creds) : null,
                    in_array($p['fechadura'] ?? '', $CA_FECHADURAS, true) ? $p['fechadura'] : null,
                    in_array($p['modo_fechadura'] ?? '', $CA_MODOS, true) ? $p['modo_fechadura'] : null,
                    $sim_nao('botao_saida'), $sim_nao('sensor_porta'), $sim_nao('quebra_vidros'),
                    $tp('tempo_abertura'), $tp('observacoes')
                ]);
            }
        }
        if ($ordem === 0) throw new Exception("Adicione pelo menos uma controladora.");

        $pdo->commit();
        header("Location: termos_acessos_form.php?id=$id_final&gravado=1");
        exit;
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $erro_msg = "Erro ao gravar: " . $e->getMessage();
        $reg = array_merge($reg, $_POST);
        $reg['testes'] = json_encode($_POST['testes'] ?? []);
        $ctrls_db = [];
        foreach (array_values($_POST['ctrl'] ?? []) as $c) {
            $c['portas'] = array_map(function ($p) { $p['credenciais'] = implode(', ', (array)($p['credenciais'] ?? [])); return $p; }, array_values($c['portas'] ?? []));
            $ctrls_db[] = $c;
        }
    }
}

// --- LISTAS ---
$id_cli_atual = (int)($reg['id_cliente'] ?? 0);
$id_obra_atual = (int)($reg['id_obra'] ?? 0);
$st = $pdo->prepare("SELECT id, nome, morada, codigo_postal, cidade FROM clientes WHERE ativo = 1 OR id = ? ORDER BY nome ASC");
$st->execute([$id_cli_atual]);
$clientes = $st->fetchAll(PDO::FETCH_ASSOC);
$st = $pdo->prepare("SELECT id, id_cliente, nome, morada, referencia_obra FROM obras WHERE estado != 'Concluída' OR id = ? ORDER BY nome ASC");
$st->execute([$id_obra_atual]);
$obras = $st->fetchAll(PDO::FETCH_ASSOC);

$clientes_js = [];
foreach ($clientes as $c) {
    $clientes_js[$c['id']] = trim(implode(', ', array_filter([trim((string)$c['morada']), trim(trim((string)$c['codigo_postal']) . ' ' . trim((string)$c['cidade']))])));
}

$softwares = array_merge($CA_SOFTWARES_BASE, caListaExtra($pdo, 'ca_softwares_extra'));
$marcas = array_merge($CA_MARCAS_BASE, caListaExtra($pdo, 'ca_marcas_extra'));

// Modelos já usados (sugestões), juntos com os conhecidos
$modelos_sug = [];
foreach ($CA_MODELOS_CONHECIDOS as $mc => $lista) { foreach ($lista as $m => $np) $modelos_sug[$mc][$m] = $np; }
try {
    foreach ($pdo->query("SELECT marca, modelo, MAX(num_portas) AS np FROM termos_acessos_controladoras WHERE modelo IS NOT NULL AND modelo <> '' GROUP BY marca, modelo") as $row) {
        $mc = $row['marca'] ?: '';
        if (!isset($modelos_sug[$mc][$row['modelo']])) $modelos_sug[$mc][$row['modelo']] = (int)$row['np'];
    }
} catch (Exception $e) {}

// --- VALORES PARA O FORMULÁRIO ---
$v = function ($k, $def = '') use ($reg) { return isset($reg[$k]) && $reg[$k] !== null ? $reg[$k] : $def; };
$testes_val = json_decode((string)$v('testes', '{}'), true) ?: [];
$referencia_atual = (string)$v('referencia', '');
$titulo_pagina = $modo_editar ? 'Registo de Controlo de Acessos' : 'Novo Registo de Controlo de Acessos';
$gravado = $modo_editar && isset($_GET['gravado']);
$id_tecnico_val = $v('id_tecnico', $modo_editar ? '' : ($_SESSION['user_id'] ?? ''));
$software_val = (string)$v('software');
if ($software_val !== '' && !in_array($software_val, $softwares, true)) $softwares[] = $software_val;

// Controladoras para o JavaScript
$ctrls_js = [];
foreach ($ctrls_db as $c) {
    $portas = [];
    foreach (($c['portas'] ?? []) as $p) {
        $portas[] = [
            'nome' => $p['nome'] ?? '', 'leitor_entrada' => $p['leitor_entrada'] ?? '', 'leitor_saida' => $p['leitor_saida'] ?? '',
            'credenciais' => array_values(array_filter(array_map('trim', explode(',', (string)($p['credenciais'] ?? ''))))),
            'fechadura' => $p['fechadura'] ?? '', 'modo_fechadura' => $p['modo_fechadura'] ?? '',
            'botao_saida' => $p['botao_saida'] ?? '', 'sensor_porta' => $p['sensor_porta'] ?? '', 'quebra_vidros' => $p['quebra_vidros'] ?? '',
            'tempo_abertura' => $p['tempo_abertura'] ?? '', 'observacoes' => $p['observacoes'] ?? ''
        ];
    }
    $ctrl = [];
    foreach (['marca', 'modelo', 'num_portas', 'num_serie', 'firmware', 'localizacao', 'alimentacao', 'bateria', 'comunicacao', 'ip', 'mascara', 'gateway', 'dns', 'porta_tcp', 'mac', 'endereco_rs485', 'password_comunicacao'] as $k) {
        $ctrl[$k] = (string)($c[$k] ?? '');
    }
    $ctrl['portas'] = $portas;
    $ctrls_js[] = $ctrl;
}
function sel($a, $b) { return (string)$a === (string)$b ? 'selected' : ''; }
$JS = JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP;
?>
<!DOCTYPE html>
<html lang="pt-PT">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($titulo_pagina) ?> - Gestão MAB</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { box-sizing: border-box; }
        body { font-family: system-ui, -apple-system, sans-serif; background: #f7f9fc; margin: 0; color: #333; font-size: 13px; padding-bottom: 110px; }

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

        .container { max-width: 1200px; margin: 30px auto; padding: 0 20px; }
        .page-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 22px; flex-wrap: wrap; gap: 15px; }
        .page-title { margin: 0; color: #2c3e50; font-size: 24px; font-weight: 800; display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
        .page-title > i { color: #16a085; }
        .ref-badge { display: inline-flex; align-items: center; gap: 6px; background: #e8f8f5; color: #117a65; border: 1px solid #d1f2eb; border-radius: 8px; padding: 5px 12px; font-size: 14px; font-weight: 800; }
        .ref-badge.pendente { background: #f4f6f8; color: #7f8c8d; border-color: #e1e5ea; font-weight: 600; font-size: 12px; }
        .header-acoes { display: flex; gap: 10px; flex-wrap: wrap; }
        .btn-imprimir { background: #fff; color: #2c3e50; border: 1px solid #dcdde1; padding: 11px 18px; border-radius: 10px; font-weight: 700; text-decoration: none; display: inline-flex; align-items: center; gap: 8px; }
        .btn-imprimir:hover { background: #f1f2f6; }
        .btn-imprimir.interno { color: #8e44ad; border-color: #ebdef0; background: #fbf7fc; }

        .card { background: #fff; padding: 24px; border-radius: 12px; border: 1px solid #e1e5ea; margin-bottom: 22px; box-shadow: 0 4px 15px rgba(0,0,0,0.02); }
        .card > h2 { font-size: 15px; color: #16a085; border-bottom: 2px solid #e8f8f5; padding-bottom: 10px; margin: 0 0 18px; text-transform: uppercase; display: flex; align-items: center; gap: 10px; }
        .card > h2 small { margin-left: auto; text-transform: none; color: #95a5a6; font-weight: 600; font-size: 12px; }

        .form-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 15px; }
        .form-grid > * { min-width: 0; }
        .span-2 { grid-column: span 2; }
        .span-4 { grid-column: 1 / -1; }
        .form-group { display: flex; flex-direction: column; gap: 5px; }
        label { font-weight: 700; font-size: 11px; color: #576574; text-transform: uppercase; }
        input, select, textarea { width: 100%; padding: 10px; border: 1px solid #dcdde1; border-radius: 8px; font-size: 13px; outline: none; background: #fff; font-family: inherit; }
        textarea { resize: vertical; min-height: 70px; }
        input:focus, select:focus, textarea:focus { border-color: #16a085; box-shadow: 0 0 0 3px rgba(22,160,133,0.12); }
        .mono { font-family: ui-monospace, Menlo, Consolas, monospace; }
        .campo-btn { position: relative; }
        .campo-btn input { padding-right: 42px; }
        .campo-btn button { position: absolute; right: 5px; top: 50%; transform: translateY(-50%); background: none; border: none; color: #95a5a6; cursor: pointer; padding: 6px 8px; border-radius: 6px; font-size: 14px; }
        .campo-btn button:hover { background: #f1f2f6; color: #16a085; }
        .btn-gps.a-procurar i { animation: rodar 1s linear infinite; }
        @keyframes rodar { to { transform: rotate(360deg); } }
        .nota-campo { font-size: 11px; color: #7f8c8d; margin-top: 2px; }
        .aviso-pass { font-size: 11px; color: #8e44ad; margin-top: 10px; display: flex; gap: 6px; align-items: center; }

        /* Controladoras */
        .ctrl-card { border: 1px solid #d1f2eb; border-radius: 12px; margin-bottom: 18px; overflow: hidden; background: #fff; }
        .ctrl-head { background: #e8f8f5; padding: 12px 16px; display: flex; align-items: center; gap: 10px; }
        .ctrl-head .num { background: #16a085; color: #fff; width: 26px; height: 26px; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; font-weight: 800; font-size: 12px; flex-shrink: 0; }
        .ctrl-head .titulo { font-weight: 800; color: #117a65; font-size: 14px; flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .ctrl-head button { background: #fff; border: 1px solid #f5c6cb; color: #e74c3c; border-radius: 8px; padding: 6px 10px; cursor: pointer; font-family: inherit; font-weight: 700; font-size: 12px; }
        .ctrl-body { padding: 18px; }
        .sub-titulo { font-size: 11px; font-weight: 800; color: #2c3e50; text-transform: uppercase; letter-spacing: 0.4px; margin: 18px 0 10px; display: flex; align-items: center; gap: 8px; }
        .sub-titulo:first-child { margin-top: 0; }
        .sub-titulo::after { content: ''; flex: 1; height: 1px; background: #eef1f4; }
        .btn-add-ctrl { width: 100%; background: #f4fbf9; color: #117a65; border: 2px dashed #a3e4d7; padding: 14px; border-radius: 12px; font-weight: 800; cursor: pointer; font-family: inherit; font-size: 13px; }
        .btn-add-ctrl:hover { background: #e8f8f5; }

        .porta-card { border: 1px solid #e1e5ea; border-radius: 10px; padding: 16px; margin-bottom: 12px; background: #fcfdfd; }
        .porta-card h3 { margin: 0 0 12px; font-size: 12px; color: #2c3e50; display: flex; align-items: center; gap: 8px; }
        .porta-card h3 .num { background: #5d6d7e; color: #fff; min-width: 22px; height: 22px; border-radius: 11px; display: inline-flex; align-items: center; justify-content: center; font-size: 11px; padding: 0 6px; }
        .creds { display: flex; flex-wrap: wrap; gap: 8px; }
        .creds label { text-transform: none; font-weight: 600; font-size: 12px; color: #2c3e50; display: inline-flex; align-items: center; gap: 6px; border: 1px solid #dcdde1; border-radius: 20px; padding: 6px 12px; cursor: pointer; background: #fff; }
        .creds input { width: auto; margin: 0; accent-color: #16a085; }
        .creds label:has(input:checked) { background: #e8f8f5; border-color: #16a085; color: #117a65; }

        .testes { width: 100%; border-collapse: collapse; }
        .testes td { padding: 9px 6px; border-bottom: 1px solid #f1f2f6; }
        .testes td:first-child { font-weight: 600; color: #2c3e50; }
        .seg { display: inline-flex; border: 1px solid #dcdde1; border-radius: 8px; overflow: hidden; }
        .seg label { text-transform: none; font-size: 12px; font-weight: 700; color: #576574; padding: 7px 12px; cursor: pointer; border-right: 1px solid #dcdde1; background: #fff; margin: 0; }
        .seg label:last-child { border-right: none; }
        .seg input { display: none; }
        .seg label:has(input[value="Sim"]:checked) { background: #27ae60; color: #fff; }
        .seg label:has(input[value="Não"]:checked) { background: #e74c3c; color: #fff; }
        .seg label:has(input[value="N.A."]:checked) { background: #95a5a6; color: #fff; }
        .btn-todos { background: #e8f8f5; color: #117a65; border: 1px dashed #16a085; padding: 8px 14px; border-radius: 8px; font-weight: 700; cursor: pointer; font-family: inherit; font-size: 12px; margin-bottom: 10px; }

        .floating-action { position: fixed; bottom: 25px; right: 25px; z-index: 100; }
        .btn-save { background: #16a085; color: #fff; padding: 15px 45px; border-radius: 10px; border: none; font-weight: 800; cursor: pointer; box-shadow: 0 5px 15px rgba(22,160,133,0.3); font-size: 14px; font-family: inherit; }
        .btn-save:hover { background: #138d75; }

        .alert { padding: 14px; border-radius: 10px; margin-bottom: 20px; text-align: center; font-weight: 600; }
        .alert-erro { background: #fdeded; color: #c0392b; border: 1px solid #f9cccc; }

        .modal { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.55); z-index: 1000; align-items: center; justify-content: center; padding: 20px; }
        .modal.show { display: flex; }
        .modal-content { background: #fff; padding: 28px; border-radius: 15px; width: 100%; max-width: 420px; text-align: center; box-shadow: 0 20px 50px rgba(0,0,0,0.2); }
        .modal-content h3 { margin-top: 0; }
        .modal-content input[type="text"] { margin: 14px 0 4px; font-size: 15px; padding: 12px; }
        .modal-content .opcoes { display: flex; flex-direction: column; gap: 10px; margin-top: 18px; }
        .modal-content .opcoes a, .modal-content .opcoes button { padding: 13px; border-radius: 10px; font-weight: 700; text-decoration: none; border: 1px solid #dcdde1; background: #fff; color: #2c3e50; cursor: pointer; font-family: inherit; font-size: 14px; }
        .modal-content .opcoes .principal { background: #16a085; color: #fff; border-color: #16a085; }
        .modal-btns { display: flex; gap: 10px; margin-top: 14px; }
        .modal-btns button { flex: 1; padding: 12px; border-radius: 10px; font-weight: 700; border: 1px solid #dcdde1; background: #fff; cursor: pointer; font-family: inherit; font-size: 14px; }
        .modal-btns .principal { background: #16a085; color: #fff; border-color: #16a085; }

        @media (max-width: 1024px) {
            .topbar { padding: 10px 24px; }
            .form-grid { grid-template-columns: repeat(2, 1fr); }
        }
        @media (max-width: 768px) {
            .topbar { padding: 8px 15px; }
            .topbar-left .logo { height: 30px; }
            .btn-voltar { width: 38px; height: 38px; }
            .container { margin: 20px auto; padding: 0 15px; }
            .page-title { font-size: 20px; }
            .header-acoes { width: 100%; }
            .header-acoes a { flex: 1; justify-content: center; }
            .card { padding: 18px 14px; }
            .ctrl-body { padding: 14px 12px; }
            .porta-card { padding: 12px; }
            .testes tr { display: block; padding: 10px 0; border-bottom: 1px solid #f1f2f6; }
            .testes td { display: block; border: none; padding: 3px 0; text-align: left !important; }
            .floating-action { left: 15px; right: 15px; bottom: 15px; }
            .btn-save { width: 100%; }
        }
        @media (max-width: 560px) {
            .form-grid { grid-template-columns: 1fr; }
            .span-2 { grid-column: auto; }
            .user-nome { display: none; }
            .user-btn { padding: 5px 10px 5px 5px; }
            .header-acoes { flex-direction: column; }
            .modal-btns { flex-direction: column-reverse; }
        }
    </style>
</head>
<body>

    <header class="topbar">
        <div class="topbar-left"><a href="index.php" title="Página inicial"><img src="mab.png" alt="MAB" class="logo"></a></div>
        <div class="topbar-right">
            <a href="termos_acessos.php" class="btn-voltar" title="Voltar" aria-label="Voltar"><i class="fa-solid fa-arrow-left"></i></a>
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
        <?php if ($erro_msg): ?>
            <div class="alert alert-erro"><i class="fa-solid fa-circle-exclamation"></i> <?= htmlspecialchars($erro_msg) ?></div>
        <?php endif; ?>

        <div class="page-header">
            <h1 class="page-title">
                <i class="fa-solid fa-door-closed"></i> <?= htmlspecialchars($titulo_pagina) ?>
                <?php if ($referencia_atual !== ''): ?>
                    <span class="ref-badge" id="ref_badge"><i class="fa-solid fa-hashtag"></i> <?= htmlspecialchars($referencia_atual) ?></span>
                <?php else: ?>
                    <span class="ref-badge pendente" id="ref_badge">Nº atribuído ao gravar</span>
                <?php endif; ?>
            </h1>
            <?php if ($modo_editar): ?>
                <div class="header-acoes">
                    <a href="termos_acessos_pdf.php?id=<?= (int)$id ?>&tipo=cliente" target="_blank" class="btn-imprimir"><i class="fa-solid fa-print"></i> Imprimir (Cliente)</a>
                    <a href="termos_acessos_pdf.php?id=<?= (int)$id ?>&tipo=interno" target="_blank" class="btn-imprimir interno"><i class="fa-solid fa-key"></i> Imprimir (Interno)</a>
                </div>
            <?php endif; ?>
        </div>

        <form method="POST" id="formAcessos">

            <!-- 1. CLIENTE E LOCAL -->
            <div class="card">
                <h2><i class="fa-solid fa-user-tie"></i> 1. Cliente e Local</h2>
                <div class="form-grid">
                    <div class="form-group">
                        <label>Data da Instalação</label>
                        <input type="date" name="data_instalacao" value="<?= htmlspecialchars($v('data_instalacao', date('Y-m-d'))) ?>" required>
                    </div>
                    <div class="form-group span-2">
                        <label>Cliente *</label>
                        <select name="id_cliente" id="sel_cli" onchange="aoEscolherCliente()" required>
                            <option value="">-- Selecione --</option>
                            <?php foreach ($clientes as $c): ?>
                                <option value="<?= (int)$c['id'] ?>" <?= sel($v('id_cliente'), $c['id']) ?>><?= htmlspecialchars($c['nome']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Técnico</label>
                        <select name="id_tecnico">
                            <option value="">-- Selecione --</option>
                            <?php foreach ($tecnicos as $t): ?>
                                <option value="<?= (int)$t['id'] ?>" <?= sel($id_tecnico_val, $t['id']) ?>><?= htmlspecialchars($t['nome']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group span-2">
                        <label>Obra <small style="text-transform:none; font-weight:500; color:#95a5a6;">(dá o número)</small></label>
                        <select name="id_obra" id="sel_obra" onchange="atualizarBadge()">
                            <option value="">-- Sem obra (gera número novo) --</option>
                        </select>
                    </div>
                    <div class="form-group span-2">
                        <label>Local da Instalação</label>
                        <div class="campo-btn">
                            <input type="text" name="local_instalacao" id="local_instalacao" value="<?= htmlspecialchars($v('local_instalacao')) ?>" placeholder="Por defeito, a morada do cliente">
                            <button type="button" class="btn-gps" id="btn_gps" onclick="obterMoradaGPS()" title="Obter a morada pelo GPS"><i class="fa-solid fa-location-crosshairs"></i></button>
                        </div>
                        <input type="hidden" name="gps" id="gps" value="<?= htmlspecialchars($v('gps')) ?>">
                        <div class="nota-campo" id="nota_gps"><?= $v('gps') ? '<i class="fa-solid fa-location-dot"></i> GPS: ' . htmlspecialchars($v('gps')) : '' ?></div>
                    </div>
                </div>
            </div>

            <!-- 2. CONTROLADORAS -->
            <div class="card">
                <h2><i class="fa-solid fa-microchip"></i> 2. Controladoras <small id="resumo_ctrl"></small></h2>
                <div id="ctrls_container"></div>
                <button type="button" class="btn-add-ctrl" onclick="adicionarControladora()"><i class="fa-solid fa-plus"></i> Adicionar Controladora</button>
            </div>

            <!-- 3. SOFTWARE -->
            <div class="card">
                <h2><i class="fa-solid fa-display"></i> 3. Software e Acesso Remoto</h2>
                <div class="form-grid">
                    <div class="form-group">
                        <label>Software</label>
                        <select name="software" id="sel_software" onchange="aoMudarSoftware(this)">
                            <option value="">-- Selecione --</option>
                            <?php foreach ($softwares as $o): ?><option value="<?= htmlspecialchars($o) ?>" <?= sel($software_val, $o) ?>><?= htmlspecialchars($o) ?></option><?php endforeach; ?>
                            <option value="__outro__">+ Outro…</option>
                        </select>
                    </div>
                    <div class="form-group span-2"><label>Servidor / PC do Software</label><input type="text" name="servidor" value="<?= htmlspecialchars($v('servidor')) ?>" placeholder="Nome ou IP do PC, endereço web…"></div>
                    <div class="form-group"><label>Utilizador do Software</label><input type="text" name="software_utilizador" value="<?= htmlspecialchars($v('software_utilizador')) ?>" autocomplete="off"></div>
                    <div class="form-group">
                        <label>Password do Software</label>
                        <div class="campo-btn"><input type="password" name="software_password" value="<?= htmlspecialchars($v('software_password')) ?>" autocomplete="new-password"><button type="button" onclick="verPass(this)" title="Mostrar"><i class="fa-solid fa-eye"></i></button></div>
                    </div>
                    <div class="form-group span-2"><label>Acesso Remoto</label><textarea name="acesso_remoto" rows="2" placeholder="Ex: VPN, port forwarding, AnyDesk…"><?= htmlspecialchars($v('acesso_remoto')) ?></textarea></div>
                </div>
                <div class="aviso-pass"><i class="fa-solid fa-lock"></i> As passwords só saem na impressão <strong>Interna</strong>. A impressão para o cliente não as mostra.</div>
            </div>

            <!-- 4. TESTES E ENTREGA -->
            <div class="card">
                <h2><i class="fa-solid fa-list-check"></i> 4. Testes e Entrega</h2>
                <button type="button" class="btn-todos" onclick="marcarTodosSim()"><i class="fa-solid fa-check-double"></i> Marcar todos como Sim</button>
                <table class="testes">
                    <?php foreach ($CA_TESTES as $k => $lbl): $tv = $testes_val[$k] ?? ''; ?>
                        <tr>
                            <td><?= htmlspecialchars($lbl) ?></td>
                            <td style="text-align:right;">
                                <div class="seg">
                                    <?php foreach (['Sim', 'Não', 'N.A.'] as $op): ?>
                                        <label><input type="radio" name="testes[<?= $k ?>]" value="<?= $op ?>" <?= $tv === $op ? 'checked' : '' ?>><?= $op ?></label>
                                    <?php endforeach; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </table>
                <div class="form-grid" style="margin-top:18px;">
                    <div class="form-group span-2"><label>Entregue a (nome do cliente / responsável)</label><input type="text" name="entregue_a" value="<?= htmlspecialchars($v('entregue_a')) ?>"></div>
                    <div class="form-group"><label>Data de Entrega</label><input type="date" name="data_entrega" value="<?= htmlspecialchars($v('data_entrega')) ?>"></div>
                </div>
            </div>

            <!-- 5. OBSERVAÇÕES -->
            <div class="card">
                <h2><i class="fa-solid fa-note-sticky"></i> 5. Observações</h2>
                <textarea name="observacoes" rows="4"><?= htmlspecialchars($v('observacoes')) ?></textarea>
            </div>

            <div class="floating-action">
                <button type="submit" class="btn-save"><i class="fa-solid fa-floppy-disk" style="margin-right:8px;"></i> GRAVAR</button>
            </div>
        </form>
    </div>

    <!-- Janela para escrever um valor novo (software / marca) -->
    <div class="modal" id="modalNovo">
        <div class="modal-content">
            <h3 style="color:#16a085;" id="novo_titulo">Novo</h3>
            <p style="color:#7f8c8d; margin:0; font-size:12px;">Fica gravado na lista para as próximas vezes.</p>
            <input type="text" id="novo_valor" maxlength="60" onkeydown="if(event.key==='Enter'){event.preventDefault();confirmarNovo();}">
            <div class="modal-btns">
                <button type="button" onclick="cancelarNovo()">Cancelar</button>
                <button type="button" class="principal" onclick="confirmarNovo()">Adicionar</button>
            </div>
        </div>
    </div>

    <?php if ($gravado): ?>
    <div class="modal show" id="modalGravado">
        <div class="modal-content">
            <h3 style="color:#16a085;"><i class="fa-solid fa-circle-check"></i> Registo gravado</h3>
            <p style="color:#576574; margin:0;">Nº <?= htmlspecialchars($referencia_atual) ?></p>
            <div class="opcoes">
                <a href="termos_acessos.php" class="principal">Voltar à lista</a>
                <a href="termos_acessos_pdf.php?id=<?= (int)$id ?>&tipo=cliente" target="_blank">Imprimir para o cliente</a>
                <button type="button" onclick="document.getElementById('modalGravado').classList.remove('show')">Continuar nesta página</button>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <datalist id="dl_modelos"></datalist>

    <script>
        const OBRAS = <?= json_encode($obras, $JS) ?>;
        const MORADAS_CLI = <?= json_encode($clientes_js, $JS) ?>;
        const OBRA_ATUAL = <?= json_encode((string)$v('id_obra')) ?>;
        const REF_INICIAL = <?= json_encode($referencia_atual) ?>;
        const CTRLS_DB = <?= json_encode($ctrls_js, $JS) ?>;
        const MARCAS = <?= json_encode(array_values($marcas), $JS) ?>;
        const MODELOS = <?= json_encode($modelos_sug, $JS) ?>;
        const COMUNICACAO = <?= json_encode($CA_COMUNICACAO, $JS) ?>;
        const FECHADURAS = <?= json_encode($CA_FECHADURAS, $JS) ?>;
        const MODOS = <?= json_encode($CA_MODOS, $JS) ?>;
        const CREDENCIAIS = <?= json_encode($CA_CREDENCIAIS, $JS) ?>;

        let seqCtrl = 0;
        function esc(s) { return String(s ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;'); }
        function opts(lista, atual, vazio) {
            let h = vazio ? '<option value="">-- Selecione --</option>' : '';
            let encontrou = false;
            lista.forEach(o => { const s = (o === atual); if (s) encontrou = true; h += '<option value="' + esc(o) + '"' + (s ? ' selected' : '') + '>' + esc(o) + '</option>'; });
            if (atual && !encontrou) h += '<option value="' + esc(atual) + '" selected>' + esc(atual) + '</option>';
            return h;
        }
        function simNao(nome, atual) {
            return '<select name="' + nome + '"><option value="">-</option><option' + (atual === 'Sim' ? ' selected' : '') + '>Sim</option><option' + (atual === 'Não' ? ' selected' : '') + '>Não</option></select>';
        }
        function campo(lbl, nome, valor, extra, cls) {
            return '<div class="form-group ' + (cls || '') + '"><label>' + lbl + '</label><input type="text" name="' + nome + '" value="' + esc(valor) + '" ' + (extra || '') + '></div>';
        }

        // ================= CONTROLADORAS =================
        function ctrlHTML(k, d) {
            d = d || {};
            const n = 'ctrl[' + k + ']';
            return '<div class="ctrl-card" data-k="' + k + '">' +
                '<div class="ctrl-head"><span class="num"></span><span class="titulo"></span>' +
                    '<button type="button" onclick="removerControladora(this)"><i class="fa-solid fa-trash-can"></i> Remover</button></div>' +
                '<div class="ctrl-body">' +
                    '<div class="sub-titulo"><i class="fa-solid fa-microchip"></i> Equipamento</div>' +
                    '<div class="form-grid">' +
                        '<div class="form-group"><label>Marca</label><select name="' + n + '[marca]" class="sel-marca" onchange="aoMudarMarca(this)">' + opts(MARCAS, d.marca || 'ZKTeco', false) + '<option value="__outro__">+ Outra…</option></select></div>' +
                        '<div class="form-group"><label>Modelo</label><input type="text" name="' + n + '[modelo]" class="inp-modelo" list="dl_modelos" value="' + esc(d.modelo) + '" onfocus="prepararModelos(this)" oninput="aoMudarModelo(this)" placeholder="Ex: C3-400"></div>' +
                        '<div class="form-group"><label>Nº de Portas</label><input type="number" min="1" max="16" name="' + n + '[num_portas]" class="inp-portas" value="' + esc(d.num_portas || 2) + '" oninput="ajustarPortas(this.closest(\'.ctrl-card\'))"></div>' +
                        campo('Nº de Série', n + '[num_serie]', d.num_serie, 'class="mono"') +
                        campo('Firmware', n + '[firmware]', d.firmware, 'class="mono"') +
                        campo('Localização', n + '[localizacao]', d.localizacao, 'placeholder="Ex: Bastidor, sala técnica"', 'span-2') +
                        campo('Alimentação', n + '[alimentacao]', d.alimentacao, 'placeholder="Ex: Fonte 12V 3A"') +
                        campo('Bateria', n + '[bateria]', d.bateria, 'placeholder="Ex: 12V 7Ah"') +
                    '</div>' +
                    '<div class="sub-titulo"><i class="fa-solid fa-network-wired"></i> Rede</div>' +
                    '<div class="form-grid">' +
                        '<div class="form-group"><label>Comunicação</label><select name="' + n + '[comunicacao]">' + opts(COMUNICACAO, d.comunicacao || 'TCP/IP', false) + '</select></div>' +
                        campo('Endereço IP', n + '[ip]', d.ip, 'class="mono inp-ip" placeholder="192.168.1.201" oninput="atualizarTitulos()"') +
                        campo('Máscara', n + '[mascara]', d.mascara || '255.255.255.0', 'class="mono"') +
                        campo('Gateway', n + '[gateway]', d.gateway, 'class="mono"') +
                        campo('DNS', n + '[dns]', d.dns, 'class="mono"') +
                        campo('Porta TCP', n + '[porta_tcp]', d.porta_tcp || '4370', 'class="mono"') +
                        campo('MAC', n + '[mac]', d.mac, 'class="mono"') +
                        campo('Endereço RS485', n + '[endereco_rs485]', d.endereco_rs485, 'class="mono"') +
                        '<div class="form-group"><label>Password de Comunicação</label><div class="campo-btn"><input type="password" name="' + n + '[password_comunicacao]" class="mono" value="' + esc(d.password_comunicacao) + '" autocomplete="new-password"><button type="button" onclick="verPass(this)" title="Mostrar"><i class="fa-solid fa-eye"></i></button></div></div>' +
                    '</div>' +
                    '<div class="sub-titulo"><i class="fa-solid fa-door-open"></i> Portas</div>' +
                    '<div class="portas"></div>' +
                '</div>' +
            '</div>';
        }

        function portaHTML(k, i, d) {
            d = d || {};
            const n = 'ctrl[' + k + '][portas][' + i + ']';
            let creds = '';
            CREDENCIAIS.forEach(c => {
                const chk = (d.credenciais || []).includes(c) ? ' checked' : '';
                creds += '<label><input type="checkbox" name="' + n + '[credenciais][]" value="' + esc(c) + '"' + chk + '> ' + esc(c) + '</label>';
            });
            return '<div class="porta-card">' +
                '<h3><span class="num">' + (i + 1) + '</span> Porta ' + (i + 1) + '</h3>' +
                '<div class="form-grid">' +
                    campo('Nome / Local da Porta', n + '[nome]', d.nome, 'placeholder="Ex: Entrada principal"', 'span-2') +
                    campo('Leitor de Entrada', n + '[leitor_entrada]', d.leitor_entrada, 'placeholder="Ex: KR600E"') +
                    campo('Leitor de Saída', n + '[leitor_saida]', d.leitor_saida, 'placeholder="Vazio = botão de saída"') +
                    '<div class="form-group span-4"><label>Credenciais</label><div class="creds">' + creds + '</div></div>' +
                    '<div class="form-group"><label>Fechadura</label><select name="' + n + '[fechadura]">' + opts(FECHADURAS, d.fechadura, true) + '</select></div>' +
                    '<div class="form-group"><label>Modo da Fechadura</label><select name="' + n + '[modo_fechadura]">' + opts(MODOS, d.modo_fechadura, true) + '</select></div>' +
                    '<div class="form-group"><label>Tempo de Abertura (s)</label><input type="number" min="0" max="255" name="' + n + '[tempo_abertura]" value="' + esc(d.tempo_abertura || '5') + '"></div>' +
                    '<div class="form-group"><label>Botão de Saída</label>' + simNao(n + '[botao_saida]', d.botao_saida) + '</div>' +
                    '<div class="form-group"><label>Sensor de Porta</label>' + simNao(n + '[sensor_porta]', d.sensor_porta) + '</div>' +
                    '<div class="form-group"><label>Quebra-vidros / Emergência</label>' + simNao(n + '[quebra_vidros]', d.quebra_vidros) + '</div>' +
                    campo('Observações da Porta', n + '[observacoes]', d.observacoes, '', 'span-2') +
                '</div>' +
            '</div>';
        }

        function adicionarControladora(d) {
            const k = seqCtrl++;
            const cont = document.getElementById('ctrls_container');
            cont.insertAdjacentHTML('beforeend', ctrlHTML(k, d));
            const card = cont.lastElementChild;
            card._portasDB = (d && d.portas) ? d.portas : [];
            ajustarPortas(card);
            atualizarTitulos();
            if (!d) card.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }

        function removerControladora(btn) {
            const cards = document.querySelectorAll('.ctrl-card');
            if (cards.length <= 1) { alert('O registo precisa de pelo menos uma controladora.'); return; }
            if (!confirm('Remover esta controladora e as suas portas?')) return;
            btn.closest('.ctrl-card').remove();
            atualizarTitulos();
        }

        function ajustarPortas(card) {
            const k = card.dataset.k;
            const cont = card.querySelector('.portas');
            const n = Math.max(1, Math.min(16, parseInt(card.querySelector('.inp-portas').value) || 1));
            const atuais = cont.querySelectorAll('.porta-card');
            for (let i = atuais.length; i < n; i++) cont.insertAdjacentHTML('beforeend', portaHTML(k, i, card._portasDB[i]));
            for (let i = atuais.length - 1; i >= n; i--) atuais[i].remove();
            atualizarTitulos();
        }

        function atualizarTitulos() {
            const cards = document.querySelectorAll('.ctrl-card');
            let totalPortas = 0;
            cards.forEach((card, idx) => {
                const marca = card.querySelector('.sel-marca').value;
                const modelo = card.querySelector('.inp-modelo').value.trim();
                const ip = card.querySelector('.inp-ip').value.trim();
                const np = card.querySelectorAll('.porta-card').length;
                totalPortas += np;
                card.querySelector('.num').textContent = idx + 1;
                card.querySelector('.titulo').textContent = 'Controladora ' + (idx + 1) + ' · ' + [marca !== '__outro__' ? marca : '', modelo].filter(Boolean).join(' ') + (ip ? ' · ' + ip : '') + ' · ' + np + ' porta' + (np > 1 ? 's' : '');
            });
            document.getElementById('resumo_ctrl').textContent = cards.length + ' controladora' + (cards.length > 1 ? 's' : '') + ' · ' + totalPortas + ' porta' + (totalPortas !== 1 ? 's' : '');
        }

        function prepararModelos(inp) {
            const marca = inp.closest('.ctrl-card').querySelector('.sel-marca').value;
            const lista = MODELOS[marca] || {};
            document.getElementById('dl_modelos').innerHTML = Object.keys(lista).map(m => '<option value="' + esc(m) + '">' + (lista[m] ? lista[m] + ' porta' + (lista[m] > 1 ? 's' : '') : '') + '</option>').join('');
        }
        function aoMudarModelo(inp) {
            const card = inp.closest('.ctrl-card');
            const marca = card.querySelector('.sel-marca').value;
            const np = (MODELOS[marca] || {})[inp.value.trim()];
            if (np) { card.querySelector('.inp-portas').value = np; ajustarPortas(card); }
            atualizarTitulos();
        }

        // ================= "OUTRO" → JANELA =================
        let novoSelect = null, novoAnterior = '';
        function abrirNovo(select, titulo) {
            novoSelect = select;
            document.getElementById('novo_titulo').textContent = titulo;
            document.getElementById('novo_valor').value = '';
            document.getElementById('modalNovo').classList.add('show');
            setTimeout(() => document.getElementById('novo_valor').focus(), 50);
        }
        function confirmarNovo() {
            const val = document.getElementById('novo_valor').value.trim();
            if (!val) { document.getElementById('novo_valor').focus(); return; }
            // Se já existir na lista, só seleciona
            let opt = Array.from(novoSelect.options).find(o => o.value.toLowerCase() === val.toLowerCase());
            if (!opt) {
                opt = new Option(val, val);
                novoSelect.insertBefore(opt, novoSelect.querySelector('option[value="__outro__"]'));
                if (novoSelect.classList.contains('sel-marca')) {
                    MARCAS.push(val);
                    // Disponível também nas outras controladoras
                    document.querySelectorAll('.sel-marca').forEach(s => { if (s !== novoSelect && !Array.from(s.options).some(o => o.value === val)) s.insertBefore(new Option(val, val), s.querySelector('option[value="__outro__"]')); });
                }
            }
            novoSelect.value = opt.value;
            document.getElementById('modalNovo').classList.remove('show');
            atualizarTitulos();
            novoSelect = null;
        }
        function cancelarNovo() {
            if (novoSelect) novoSelect.value = novoAnterior;
            document.getElementById('modalNovo').classList.remove('show');
            novoSelect = null;
        }
        function aoMudarSoftware(sel) {
            if (sel.value === '__outro__') { novoAnterior = sel.dataset.anterior || ''; abrirNovo(sel, 'Novo software'); }
            else sel.dataset.anterior = sel.value;
        }
        function aoMudarMarca(sel) {
            if (sel.value === '__outro__') { novoAnterior = sel.dataset.anterior || 'ZKTeco'; abrirNovo(sel, 'Nova marca'); }
            else { sel.dataset.anterior = sel.value; atualizarTitulos(); }
        }

        // ================= CLIENTE / OBRA / LOCAL =================
        let localAuto = '';
        function aoEscolherCliente() {
            const cli = document.getElementById('sel_cli').value;
            const local = document.getElementById('local_instalacao');
            // Morada do cliente por defeito (só se o campo estiver vazio ou ainda com a morada automática)
            if (local.value.trim() === '' || local.value === localAuto) {
                localAuto = MORADAS_CLI[cli] || '';
                local.value = localAuto;
            }
            filtrarObras();
        }
        function filtrarObras() {
            const cli = document.getElementById('sel_cli').value;
            const sel = document.getElementById('sel_obra');
            const atual = sel.value || OBRA_ATUAL;
            sel.innerHTML = '<option value="">-- Sem obra (gera número novo) --</option>';
            OBRAS.filter(o => !cli || String(o.id_cliente) === String(cli)).forEach(o => {
                const opt = new Option((o.referencia_obra ? o.referencia_obra + ' – ' : '') + o.nome, o.id);
                if (String(o.id) === String(atual)) opt.selected = true;
                sel.add(opt);
            });
            atualizarBadge();
        }
        function atualizarBadge() {
            const b = document.getElementById('ref_badge');
            const o = OBRAS.find(x => String(x.id) === document.getElementById('sel_obra').value);
            if (o && o.referencia_obra) { b.className = 'ref-badge'; b.innerHTML = '<i class="fa-solid fa-hashtag"></i> ' + esc(o.referencia_obra); }
            else if (!o && REF_INICIAL) { b.className = 'ref-badge'; b.innerHTML = '<i class="fa-solid fa-hashtag"></i> ' + esc(REF_INICIAL); }
            else { b.className = 'ref-badge pendente'; b.textContent = 'Nº atribuído ao gravar'; }
        }

        // GPS → morada (OpenStreetMap)
        function obterMoradaGPS() {
            const btn = document.getElementById('btn_gps');
            const nota = document.getElementById('nota_gps');
            if (!navigator.geolocation) { alert('Este dispositivo não permite obter a localização.'); return; }
            if (!window.isSecureContext) { alert('O GPS só funciona com o site aberto em https://'); return; }
            btn.classList.add('a-procurar');
            nota.textContent = 'A obter a localização…';
            navigator.geolocation.getCurrentPosition(async pos => {
                const lat = pos.coords.latitude.toFixed(6), lon = pos.coords.longitude.toFixed(6);
                document.getElementById('gps').value = lat + ', ' + lon;
                let morada = '';
                try {
                    const r = await fetch('https://nominatim.openstreetmap.org/reverse?format=jsonv2&addressdetails=1&accept-language=pt&lat=' + lat + '&lon=' + lon);
                    const j = await r.json();
                    const a = j.address || {};
                    const rua = [a.road || a.pedestrian || a.footway || '', a.house_number || ''].filter(Boolean).join(', ');
                    const localidade = a.city || a.town || a.village || a.suburb || a.municipality || '';
                    morada = [rua, [a.postcode || '', localidade].filter(Boolean).join(' ')].filter(Boolean).join(', ') || j.display_name || '';
                } catch (e) { /* sem internet: fica só a coordenada */ }
                const local = document.getElementById('local_instalacao');
                local.value = morada || (lat + ', ' + lon);
                localAuto = '';
                nota.innerHTML = '<i class="fa-solid fa-location-dot"></i> GPS: ' + lat + ', ' + lon + (pos.coords.accuracy ? ' (±' + Math.round(pos.coords.accuracy) + ' m)' : '');
                btn.classList.remove('a-procurar');
            }, err => {
                btn.classList.remove('a-procurar');
                nota.textContent = '';
                alert(err.code === 1 ? 'A permissão de localização foi recusada.' : 'Não foi possível obter a localização.');
            }, { enableHighAccuracy: true, timeout: 15000, maximumAge: 0 });
        }

        // ================= OUTROS =================
        function verPass(btn) {
            const inp = btn.parentNode.querySelector('input');
            const ver = inp.type === 'password';
            inp.type = ver ? 'text' : 'password';
            btn.innerHTML = ver ? '<i class="fa-solid fa-eye-slash"></i>' : '<i class="fa-solid fa-eye"></i>';
        }
        function marcarTodosSim() { document.querySelectorAll('.testes input[value="Sim"]').forEach(r => r.checked = true); }

        (function () {
            const menu = document.querySelector('.user-menu');
            const btn = menu ? menu.querySelector('.user-btn') : null;
            if (!btn) return;
            btn.addEventListener('click', function (e) { e.stopPropagation(); menu.classList.toggle('aberto'); });
            document.addEventListener('click', function () { menu.classList.remove('aberto'); });
        })();

        <?php if ($gravado): ?>history.replaceState(null, '', 'termos_acessos_form.php?id=<?= (int)$id ?>');<?php endif; ?>

        // Arranque
        document.getElementById('sel_software').dataset.anterior = document.getElementById('sel_software').value;
        if (CTRLS_DB.length) CTRLS_DB.forEach(c => adicionarControladora(c)); else adicionarControladora({ marca: 'ZKTeco', num_portas: 2, portas: [] });
        document.querySelectorAll('.sel-marca').forEach(s => s.dataset.anterior = s.value);
        filtrarObras();
        if (!<?= $modo_editar ? 'true' : 'false' ?> && document.getElementById('sel_cli').value) aoEscolherCliente();
        window.scrollTo(0, 0);
    </script>
</body>
</html>
