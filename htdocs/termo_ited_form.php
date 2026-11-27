<?php
// =====================================================================
// TERMO ITED — formulário (novo / editar)
// 1) Obra e cliente · 2) Edifício · 3) Projeto ITED · 4) Instalador (técnico ITED) · 5) Texto do termo
// 6) Ensaios (equipamento, norma) · 7) Pontos RJ45 (à mão, gerados em série ou importados de CSV do certificador)
// Ao alterar um termo, a versão anterior (com os pontos) fica guardada em termos_ited_arquivo.
// =====================================================================
session_start();
require_once 'db.php';
require_once 'functions.php';
require_once 'termos_ited_comum.php';

// REGRA DE OURO 1: Validação de sessão
if (!isset($_SESSION['user_id'])) { header("Location: index.php"); exit; }
// REGRA DE OURO 2: Acesso restrito pela matriz de permissões
if (!itedTemAcesso($pdo)) { header("Location: index.php?erro=sem_permissao"); exit; }
// REGRA DE OURO 3: Primeiro nome no menu
$primeiro_nome = explode(' ', trim($_SESSION['nome'] ?? 'Utilizador'))[0];

itedCriarTabelas($pdo);
$cfg = itedConfig($pdo);

// REGRA DE OURO 5: ID validado como número inteiro
$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT) ?: 0;
$termo = $id ? itedTermo($pdo, $id) : null;
if ($id && !$termo) { header("Location: termos_ited.php"); exit; }

$mensagem = ''; $tipo_mensagem = '';
$d = fn($v) => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$v) ? $v : null;
$txt = fn($k, $max) => mb_substr(trim((string)($_POST[$k] ?? '')), 0, $max);

// ---------- GRAVAR ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // REGRA DE OURO 7: token CSRF
        if (!validar_csrf_token($_POST['csrf_token'] ?? '')) throw new Exception('A sessão expirou ou o pedido não é válido. Atualize a página e grave de novo.');
        $id_obra = filter_input(INPUT_POST, 'id_obra', FILTER_VALIDATE_INT) ?: null;
        $id_cliente = null;
        if ($id_obra) { $st = $pdo->prepare("SELECT id_cliente FROM obras WHERE id = ?"); $st->execute([$id_obra]); $id_cliente = $st->fetchColumn() ?: null; }
        $edificio = $txt('edificio', 255);
        if (!$id_obra && $edificio === '') throw new Exception('Escolha a obra ou indique o edifício.');
        $estado = in_array($_POST['estado'] ?? '', ['Rascunho', 'Emitido'], true) ? $_POST['estado'] : 'Rascunho';
        $n_fogos = filter_input(INPUT_POST, 'n_fogos', FILTER_VALIDATE_INT);
        $campos = [
            'id_obra' => $id_obra, 'id_cliente' => $id_cliente, 'data_emissao' => $d($_POST['data_emissao'] ?? '') ?: date('Y-m-d'), 'estado' => $estado,
            'tipo_edificio' => $txt('tipo_edificio', 40), 'edificio' => $edificio, 'morada' => $txt('morada', 255), 'localidade' => $txt('localidade', 150),
            'n_fogos' => $n_fogos === false ? null : $n_fogos,
            'tem_projeto' => !empty($_POST['tem_projeto']) ? 1 : 0,
            'projetista_nome' => !empty($_POST['tem_projeto']) ? $txt('projetista_nome', 150) : '', 'projetista_inscricao' => !empty($_POST['tem_projeto']) ? $txt('projetista_inscricao', 60) : '',
            'projeto_data' => !empty($_POST['tem_projeto']) ? $d($_POST['projeto_data'] ?? '') : null,
            'tecnico_nome' => $txt('tecnico_nome', 150), 'tecnico_inscricao' => $txt('tecnico_inscricao', 60), 'entidade_registo' => $txt('entidade_registo', 60),
            'assinatura' => !empty($_POST['assinatura']) ? 1 : 0, 'texto_termo' => trim((string)($_POST['texto_termo'] ?? '')) ?: null,
            'equip_teste' => $txt('equip_teste', 150), 'equip_serie' => $txt('equip_serie', 80), 'equip_calibracao' => $d($_POST['equip_calibracao'] ?? ''),
            'norma_teste' => $txt('norma_teste', 150), 'data_ensaios' => $d($_POST['data_ensaios'] ?? ''), 'observacoes' => trim((string)($_POST['observacoes'] ?? '')) ?: null,
        ];
        // Pontos RJ45
        $pontos = json_decode((string)($_POST['pontos_json'] ?? '[]'), true);
        if (!is_array($pontos)) $pontos = [];
        if (count($pontos) > 2000) throw new Exception('Demasiados pontos (máximo 2000).');
        $num = function ($v) { $v = trim(str_replace(',', '.', (string)$v)); return $v === '' || !is_numeric($v) ? null : round((float)$v, 2); };
        $limpos = [];
        foreach ($pontos as $p) {
            $ident = mb_substr(trim((string)($p['identificacao'] ?? '')), 0, 60);
            if ($ident === '') continue;
            $limpos[] = [$ident, mb_substr(trim((string)($p['localizacao'] ?? '')), 0, 150), mb_substr(trim((string)($p['painel_porta'] ?? '')), 0, 60),
                         mb_substr(trim((string)($p['categoria'] ?? '')), 0, 20), mb_substr(trim((string)($p['configuracao'] ?? '')), 0, 30),
                         $num($p['comprimento'] ?? ''), ($p['mapa'] ?? 'OK') === 'NOK' ? 'NOK' : 'OK', $num($p['margem_next'] ?? ''),
                         ($p['resultado'] ?? 'PASSA') === 'FALHA' ? 'FALHA' : 'PASSA', mb_substr(trim((string)($p['observacoes'] ?? '')), 0, 255)];
        }

        $pdo->beginTransaction();
        if ($id) {
            itedArquivar($pdo, $id, 'editado');   // nunca perder dados: a versão anterior fica guardada
            $sets = implode(', ', array_map(fn($k) => "$k = ?", array_keys($campos)));
            $pdo->prepare("UPDATE termos_ited SET $sets, atualizado_em = NOW() WHERE id = ?")->execute(array_merge(array_values($campos), [$id]));
            $pdo->prepare("DELETE FROM termos_ited_pontos WHERE id_termo = ?")->execute([$id]);
        } else {
            $cols = implode(', ', array_keys($campos));
            $pdo->prepare("INSERT INTO termos_ited ($cols, criado_por, criado_em, atualizado_em) VALUES (" . implode(', ', array_fill(0, count($campos), '?')) . ", ?, NOW(), NOW())")
                ->execute(array_merge(array_values($campos), [$_SESSION['user_id']]));
            $id = (int)$pdo->lastInsertId();
        }
        $ins = $pdo->prepare("INSERT INTO termos_ited_pontos (id_termo, ordem, identificacao, localizacao, painel_porta, categoria, configuracao, comprimento, mapa, margem_next, resultado, observacoes)
                              VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        foreach ($limpos as $i => $p) $ins->execute(array_merge([$id, $i + 1], $p));
        $pdo->commit();
        // Técnico e equipamento de ensaio ficam para os termos seguintes
        itedGuardarConfig($pdo, ['ited_tecnico_nome' => $campos['tecnico_nome'], 'ited_tecnico_inscricao' => $campos['tecnico_inscricao'],
                                 'ited_entidade_registo' => $campos['entidade_registo'], 'ited_norma_teste' => $campos['norma_teste']]);
        registrarLog($pdo, "Gravou termo ITED #$id (" . count($limpos) . " pontos RJ45)", "Termos ITED");
        header("Location: termo_ited_form.php?id=$id&gravado=1"); exit;
    } catch (Exception $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ($ex instanceof PDOException) { error_log('termo_ited_form.php: ' . $ex->getMessage()); $mensagem = 'Não foi possível gravar. Nada foi alterado.'; }
        else $mensagem = $ex->getMessage();
        $tipo_mensagem = 'erro';
        $termo = array_merge($termo ?: [], $campos ?? [], ['id' => $id]);
    }
}
if (isset($_GET['gravado'])) { $mensagem = 'Termo ITED guardado.'; $tipo_mensagem = 'sucesso'; }

// ---------- DADOS PARA O FORMULÁRIO ----------
$obras = [];
try {
    $obras = $pdo->query("SELECT o.id, o.nome, o.referencia_obra, o.morada, c.nome AS cliente_nome
                          FROM obras o LEFT JOIN clientes c ON c.id = o.id_cliente ORDER BY o.id DESC")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}
$t = $termo ?: [
    'id_obra' => filter_input(INPUT_GET, 'id_obra', FILTER_VALIDATE_INT) ?: '', 'data_emissao' => date('Y-m-d'), 'estado' => 'Rascunho',
    'tipo_edificio' => 'Residencial', 'edificio' => '', 'morada' => '', 'localidade' => '', 'n_fogos' => '',
    'tem_projeto' => 1, 'projetista_nome' => '', 'projetista_inscricao' => '', 'projeto_data' => '',
    'tecnico_nome' => $cfg['ited_tecnico_nome'], 'tecnico_inscricao' => $cfg['ited_tecnico_inscricao'], 'entidade_registo' => $cfg['ited_entidade_registo'],
    'assinatura' => 1, 'texto_termo' => '', 'equip_teste' => '', 'equip_serie' => '',
    'equip_calibracao' => '', 'norma_teste' => $cfg['ited_norma_teste'], 'data_ensaios' => date('Y-m-d'), 'observacoes' => '',
];
// Equipamento de ensaio: sempre pré-preenchido com o certificador da empresa (nos termos antigos, se estiver vazio)
foreach ($ITED_EQUIPAMENTO as $k => $v) if (trim((string)($t[$k] ?? '')) === '') $t[$k] = $v;
$pontos = ($id && empty($_POST)) ? itedPontos($pdo, $id) : (isset($limpos) ? array_map(fn($p) => array_combine(['identificacao', 'localizacao', 'painel_porta', 'categoria', 'configuracao', 'comprimento', 'mapa', 'margem_next', 'resultado', 'observacoes'], $p), $limpos) : []);
$e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$JSON = JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP;
?>
<!DOCTYPE html>
<html lang="pt-PT">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $id ? 'Editar' : 'Novo' ?> Termo ITED - Gestão MAB</title>
    <?php /* REGRA DE OURO 8: ícone MAB no separador */ ?>
    <link rel="icon" type="image/png" href="/mab.png">
    <link rel="apple-touch-icon" href="/mab.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { box-sizing: border-box; font-family: system-ui, -apple-system, sans-serif; }
        body { background: #f7f9fc; margin: 0; color: #2c3e50; font-size: 14px; padding-bottom: 110px; }
        input, select, textarea, button { font-family: inherit; font-size: 14px; }
        .topbar { background: #fff; padding: 10px 40px; box-shadow: 0 1px 0 #e8ecf1; display: flex; justify-content: space-between; align-items: center; position: sticky; top: 0; z-index: 100; }
        .topbar .logo { height: 35px; display: block; }
        .topbar-right { display: flex; align-items: center; gap: 12px; }
        .btn-voltar { width: 40px; height: 40px; border-radius: 50%; background: #fff; border: 1px solid #e8ecf1; color: #576574; display: inline-flex; align-items: center; justify-content: center; text-decoration: none; font-size: 16px; box-shadow: 0 2px 6px rgba(0,0,0,0.04); }
        .btn-voltar:hover { background: #eaf2fb; color: #1f618d; }
        .user-menu { position: relative; }
        .user-btn { background: #fff; border: 1px solid #e8ecf1; border-radius: 30px; font-weight: 600; color: #2c3e50; cursor: pointer; display: flex; align-items: center; gap: 10px; padding: 5px 14px 5px 5px; }
        .user-avatar { background: #e74c3c; color: #fff; width: 28px; height: 28px; border-radius: 50%; display: flex; justify-content: center; align-items: center; font-size: 13px; }
        .dropdown-content { display: none; position: absolute; right: 0; top: 100%; background: #fff; min-width: 180px; box-shadow: 0 12px 30px rgba(0,0,0,0.1); border-radius: 10px; border: 1px solid #e8ecf1; overflow: hidden; margin-top: 6px; z-index: 200; }
        .user-menu:hover .dropdown-content, .user-menu.aberto .dropdown-content { display: block; }
        .dropdown-content a { color: #576574; padding: 12px 20px; text-decoration: none; display: block; }
        .container { max-width: 1300px; margin: 22px auto; padding: 0 15px; }
        h1 { margin: 0 0 18px; font-size: 22px; font-weight: 800; display: flex; align-items: center; gap: 10px; }
        h1 i { color: #1f618d; }
        .alert { padding: 13px 15px; border-radius: 8px; margin-bottom: 16px; font-weight: 600; }
        .alert-sucesso { background: #e8f5e9; color: #1e8449; border: 1px solid #c8e6c9; }
        .alert-erro { background: #fdeded; color: #c0392b; border: 1px solid #fadbd8; }
        .card { background: #fff; border: 1px solid #e1e5ea; border-radius: 12px; padding: 20px; margin-bottom: 18px; }
        .card h2 { margin: 0 0 16px; font-size: 14px; color: #1f618d; text-transform: uppercase; display: flex; align-items: center; gap: 8px; border-bottom: 2px solid #eaf2fb; padding-bottom: 10px; flex-wrap: wrap; }
        .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 14px; }
        .fg { display: flex; flex-direction: column; }
        .fg.full { grid-column: 1 / -1; }
        .fg label { font-size: 11px; font-weight: 700; color: #576574; text-transform: uppercase; margin-bottom: 6px; }
        .fg input, .fg select, .fg textarea { padding: 10px 12px; border: 1px solid #dcdde1; border-radius: 8px; background: #fff; outline: none; }
        .fg input:focus, .fg select:focus, .fg textarea:focus { border-color: #1f618d; box-shadow: 0 0 0 3px rgba(31,97,141,0.1); }
        .fg textarea { min-height: 150px; resize: vertical; line-height: 1.5; }
        .ajuda { font-size: 12px; color: #7f8c8d; margin-top: 5px; }
        .chk { display: flex; align-items: center; gap: 8px; font-weight: 600; font-size: 13px; color: #34495e; margin-top: 4px; }
        .btn { border: none; border-radius: 8px; padding: 9px 14px; font-weight: 700; cursor: pointer; display: inline-flex; align-items: center; gap: 7px; font-size: 13px; text-decoration: none; }
        .btn-azul { background: #1f618d; color: #fff; } .btn-azul:hover { background: #1a5276; }
        .btn-sec { background: #fff; color: #34495e; border: 1px solid #cfd6de; } .btn-sec:hover { background: #f1f2f6; }
        .barra-pontos { display: flex; gap: 8px; flex-wrap: wrap; margin-left: auto; text-transform: none; }
        .resumo-pontos { font-size: 13px; color: #576574; margin-bottom: 10px; }
        .resumo-pontos b { color: #2c3e50; } .resumo-pontos .f { color: #c0392b; } .resumo-pontos .ok { color: #1e8449; }
        .table-responsive { width: 100%; overflow-x: auto; }
        table.pontos { width: 100%; border-collapse: collapse; min-width: 1100px; }
        table.pontos th { padding: 8px 6px; text-align: left; font-size: 10px; text-transform: uppercase; color: #95a5a6; border-bottom: 1px solid #e1e5ea; background: #fafbfc; white-space: nowrap; }
        table.pontos td { padding: 4px 4px; border-bottom: 1px solid #f2f3f5; }
        table.pontos input, table.pontos select { width: 100%; padding: 7px 8px; border: 1px solid #e1e5ea; border-radius: 6px; font-size: 13px; background: #fff; }
        table.pontos .n { text-align: center; color: #95a5a6; font-weight: 700; width: 34px; }
        tr.falha td { background: #fdf2f2; }
        .sel-res { font-weight: 800; }
        .acao-l { display: flex; gap: 4px; }
        .btn-l { width: 30px; height: 30px; border-radius: 6px; border: none; background: #f1f2f6; color: #576574; cursor: pointer; }
        .btn-l.del { color: #c0392b; background: #fdeded; }
        .rodape { position: fixed; left: 0; right: 0; bottom: 0; background: rgba(255,255,255,0.97); border-top: 1px solid #e1e5ea; padding: 12px 20px; display: flex; justify-content: flex-end; gap: 10px; z-index: 90; flex-wrap: wrap; }
        .rodape .btn { padding: 12px 20px; font-size: 14px; }
        .modal { display: none; position: fixed; inset: 0; background: rgba(0,0,0,.55); z-index: 1000; align-items: center; justify-content: center; }
        .modal.show { display: flex; }
        .modal-box { background: #fff; border-radius: 12px; padding: 22px; width: 94%; max-width: 520px; max-height: 92vh; overflow-y: auto; }
        .modal-box h3 { margin-top: 0; color: #1f618d; }
        @media (max-width: 768px) {
            .topbar { padding: 10px 15px; } .user-nome { display: none; }
            .card { padding: 15px; }
            .barra-pontos { margin-left: 0; width: 100%; } .barra-pontos .btn { flex: 1; justify-content: center; }
            /* Tabela dos pontos passa a cartões */
            table.pontos { min-width: 0; }
            table.pontos thead { display: none; }
            table.pontos, table.pontos tbody, table.pontos tr, table.pontos td { display: block; width: 100%; }
            table.pontos tr { border: 1px solid #e1e5ea; border-radius: 10px; margin-bottom: 10px; padding: 6px 0; background: #fff; display: grid; grid-template-columns: 1fr 1fr; }
            table.pontos td { border: none; padding: 3px 10px; }
            table.pontos td:nth-child(1), table.pontos td:nth-child(3), table.pontos td:nth-child(11), table.pontos td:nth-child(12) { grid-column: 1 / -1; }
            table.pontos td[data-label]::before { content: attr(data-label); display: block; font-size: 10px; font-weight: 700; color: #95a5a6; text-transform: uppercase; margin-bottom: 2px; }
            table.pontos .n { text-align: left; width: auto; }
            .rodape .btn { flex: 1; justify-content: center; }
        }
    </style>
</head>
<body>
    <header class="topbar">
        <div><a href="index.php" title="Página inicial"><img src="mab.png" alt="MAB" class="logo"></a></div>
        <div class="topbar-right">
            <a href="termos_ited.php" class="btn-voltar" title="Voltar" aria-label="Voltar"><i class="fa-solid fa-arrow-left"></i></a>
            <div class="user-menu">
                <button type="button" class="user-btn"><div class="user-avatar"><i class="fa-solid fa-user"></i></div><span class="user-nome"><?= $e($primeiro_nome) ?></span></button>
                <div class="dropdown-content">
                    <a href="perfil.php"><i class="fa-solid fa-id-badge" style="width:20px;"></i> Perfil</a>
                    <a href="index.php?logout=1" style="color:#e74c3c; border-top:1px solid #f1f2f6;"><i class="fa-solid fa-right-from-bracket" style="width:20px;"></i> Sair</a>
                </div>
            </div>
        </div>
    </header>

    <div class="container">
        <h1><i class="fa-solid fa-network-wired"></i> <?= $id ? 'Editar' : 'Novo' ?> termo ITED</h1>
        <?php if ($mensagem): ?><div class="alert alert-<?= $tipo_mensagem === 'sucesso' ? 'sucesso' : 'erro' ?>"><?= $e($mensagem) ?></div><?php endif; ?>

        <form method="POST" id="formIted" action="termo_ited_form.php<?= $id ? '?id=' . (int)$id : '' ?>">
            <?php /* REGRA DE OURO 7 */ ?><input type="hidden" name="csrf_token" value="<?= $e(gerar_csrf_token()) ?>">
            <input type="hidden" name="pontos_json" id="pontos_json">

            <div class="card">
                <h2><i class="fa-solid fa-building"></i> 1. Obra e edifício</h2>
                <div class="grid">
                    <div class="fg full"><label>Obra</label>
                        <select name="id_obra" id="id_obra" onchange="obraEscolhida()">
                            <option value="">— Sem obra (preencher o edifício à mão) —</option>
                            <?php foreach ($obras as $o): ?><option value="<?= (int)$o['id'] ?>" <?= (string)$t['id_obra'] === (string)$o['id'] ? 'selected' : '' ?>><?= $e(($o['referencia_obra'] ? '[' . $o['referencia_obra'] . '] ' : '') . $o['nome'] . ($o['cliente_nome'] ? ' — ' . $o['cliente_nome'] : '')) ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div class="fg"><label>Designação do edifício</label><input type="text" name="edificio" id="edificio" maxlength="255" value="<?= $e($t['edificio']) ?>" placeholder="ex.: Moradia T3 / Edifício Sol"></div>
                    <div class="fg"><label>Tipo de edifício</label>
                        <select name="tipo_edificio"><?php foreach ($ITED_TIPOS_EDIFICIO as $tp): ?><option <?= $t['tipo_edificio'] === $tp ? 'selected' : '' ?>><?= $e($tp) ?></option><?php endforeach; ?></select>
                    </div>
                    <div class="fg"><label>Nº de fogos / frações</label><input type="number" name="n_fogos" min="0" value="<?= $e($t['n_fogos']) ?>"></div>
                    <div class="fg"><label>Morada</label><input type="text" name="morada" id="morada" maxlength="255" value="<?= $e($t['morada']) ?>"></div>
                    <div class="fg"><label>Localidade / concelho</label><input type="text" name="localidade" maxlength="150" value="<?= $e($t['localidade']) ?>"></div>
                    <div class="fg"><label>Data de emissão</label><input type="date" name="data_emissao" value="<?= $e($t['data_emissao']) ?>"></div>
                    <div class="fg"><label>Estado</label>
                        <select name="estado"><?php foreach (['Rascunho', 'Emitido'] as $es): ?><option <?= $t['estado'] === $es ? 'selected' : '' ?>><?= $es ?></option><?php endforeach; ?></select>
                    </div>
                </div>
            </div>

            <div class="card">
                <h2><i class="fa-solid fa-compass-drafting"></i> 2. Projeto ITED
                    <label class="chk barra-pontos" style="text-transform:none;"><input type="checkbox" name="tem_projeto" id="tem_projeto" value="1" <?= !isset($t['tem_projeto']) || (int)$t['tem_projeto'] === 1 ? 'checked' : '' ?> onchange="mostrarProjeto()"> Existe projeto ITED</label></h2>
                <p class="ajuda" id="semProjeto" style="display:none; margin:0;">Sem projeto ITED: o termo não refere projeto e a identificação indica "Sem projeto ITED".</p>
                <div class="grid" id="camposProjeto">
                    <div class="fg"><label>Projetista</label><input type="text" name="projetista_nome" maxlength="150" value="<?= $e($t['projetista_nome']) ?>"></div>
                    <div class="fg"><label>Nº de inscrição do projetista</label><input type="text" name="projetista_inscricao" maxlength="60" value="<?= $e($t['projetista_inscricao']) ?>"></div>
                    <div class="fg"><label>Data do projeto</label><input type="date" name="projeto_data" value="<?= $e($t['projeto_data']) ?>"></div>
                </div>
            </div>

            <div class="card">
                <h2><i class="fa-solid fa-user-gear"></i> 3. Instalador ITED</h2>
                <div class="grid">
                    <div class="fg"><label>Técnico ITED</label><input type="text" name="tecnico_nome" maxlength="150" value="<?= $e($t['tecnico_nome']) ?>"></div>
                    <div class="fg"><label>Nº de inscrição na ANACOM (técnico ITED)</label><input type="text" name="tecnico_inscricao" maxlength="60" value="<?= $e($t['tecnico_inscricao']) ?>"></div>
                    <div class="fg"><label>Nº de registo da entidade instaladora</label><input type="text" name="entidade_registo" maxlength="60" value="<?= $e($t['entidade_registo']) ?>"></div>
                    <div class="fg full"><label class="chk"><input type="checkbox" name="assinatura" value="1" <?= !empty($t['assinatura']) ? 'checked' : '' ?>> Inserir a assinatura digitalizada (assina.png) no termo e no relatório</label></div>
                </div>
                <p class="ajuda">O técnico, as inscrições e o equipamento de ensaio ficam guardados e preenchem os termos seguintes.</p>
            </div>

            <div class="card">
                <h2><i class="fa-solid fa-file-signature"></i> 4. Texto do termo de responsabilidade
                    <span class="barra-pontos"><button type="button" class="btn btn-sec" onclick="usarTextoPadrao()"><i class="fa-solid fa-rotate"></i> Usar texto padrão</button></span></h2>
                <div class="fg full">
                    <textarea name="texto_termo" id="texto_termo" placeholder="Deixe vazio para usar o texto padrão (gerado com os dados acima)."><?= $e($t['texto_termo']) ?></textarea>
                    <div class="ajuda">Vazio = texto padrão, gerado no PDF com os dados atuais. "Usar texto padrão" copia-o para aqui, para o poder alterar.</div>
                </div>
            </div>

            <div class="card">
                <h2><i class="fa-solid fa-gauge-high"></i> 5. Ensaios</h2>
                <div class="grid">
                    <div class="fg"><label>Equipamento de ensaio (marca / modelo)</label><input type="text" name="equip_teste" maxlength="150" value="<?= $e($t['equip_teste']) ?>" placeholder="ex.: Fluke DSX-5000"></div>
                    <div class="fg"><label>Nº de série</label><input type="text" name="equip_serie" maxlength="80" value="<?= $e($t['equip_serie']) ?>"></div>
                    <div class="fg"><label>Última calibração</label><input type="date" name="equip_calibracao" value="<?= $e($t['equip_calibracao']) ?>"></div>
                    <div class="fg"><label>Data dos ensaios</label><input type="date" name="data_ensaios" value="<?= $e($t['data_ensaios']) ?>"></div>
                    <div class="fg full"><label>Norma / limite de ensaio</label><input type="text" name="norma_teste" maxlength="150" value="<?= $e($t['norma_teste']) ?>"></div>
                </div>
            </div>

            <div class="card">
                <h2><i class="fa-solid fa-ethernet"></i> 6. Pontos RJ45 ensaiados
                    <span class="barra-pontos">
                        <button type="button" class="btn btn-sec" onclick="addPonto()"><i class="fa-solid fa-plus"></i> Linha</button>
                        <button type="button" class="btn btn-sec" onclick="abrirGerar()"><i class="fa-solid fa-list-ol"></i> Gerar em série</button>
                        <button type="button" class="btn btn-sec" onclick="document.getElementById('csvPontos').click()"><i class="fa-solid fa-file-csv"></i> Importar CSV</button>
                        <input type="file" id="csvPontos" accept=".csv,.txt" style="display:none;" onchange="importarCSV(this)">
                    </span></h2>
                <div class="resumo-pontos" id="resumoPontos"></div>
                <div class="table-responsive">
                    <table class="pontos">
                        <thead><tr><th>Nº</th><th>Identificação</th><th>Localização</th><th>Painel / porta</th><th>Categoria</th><th>Configuração</th><th>Compr. (m)</th><th>Mapa</th><th>Margem NEXT (dB)</th><th>Resultado</th><th>Observações</th><th></th></tr></thead>
                        <tbody id="corpoPontos"></tbody>
                    </table>
                </div>
                <p class="ajuda">CSV do certificador (ex.: exportação do LinkWare) ou feito à mão: o importador reconhece colunas como <em>Cable ID / Identificação, Local, Porta, Categoria, Length / Comprimento, Wiremap / Mapa, NEXT Margin / Margem, Result / Resultado</em>.</p>
            </div>

            <div class="card">
                <h2><i class="fa-solid fa-note-sticky"></i> 7. Observações</h2>
                <div class="fg full"><textarea name="observacoes" style="min-height:80px;"><?= $e($t['observacoes']) ?></textarea></div>
            </div>

            <div class="rodape">
                <?php if ($id): ?>
                    <a class="btn btn-sec" href="termo_ited_pdf.php?id=<?= (int)$id ?>&amp;doc=termo" target="_blank"><i class="fa-solid fa-file-signature"></i> Termo</a>
                    <a class="btn btn-sec" href="termo_ited_pdf.php?id=<?= (int)$id ?>&amp;doc=relatorio" target="_blank"><i class="fa-solid fa-file-lines"></i> Relatório</a>
                <?php endif; ?>
                <button type="submit" class="btn btn-azul"><i class="fa-solid fa-floppy-disk"></i> Gravar</button>
            </div>
        </form>
    </div>

    <!-- Gerar pontos em série -->
    <div class="modal" id="modalGerar">
        <div class="modal-box">
            <h3><i class="fa-solid fa-list-ol"></i> Gerar pontos em série</h3>
            <div class="grid">
                <div class="fg"><label>Prefixo</label><input type="text" id="g_prefixo" value="TT" maxlength="20"></div>
                <div class="fg"><label>Começar em</label><input type="number" id="g_inicio" value="1" min="0"></div>
                <div class="fg"><label>Quantidade</label><input type="number" id="g_qtd" value="10" min="1" max="500"></div>
                <div class="fg"><label>Algarismos</label><input type="number" id="g_dig" value="2" min="1" max="4"></div>
                <div class="fg"><label>Localização</label><input type="text" id="g_local" placeholder="ex.: Sala"></div>
                <div class="fg"><label>Painel</label><input type="text" id="g_painel" placeholder="ex.: ATI-P1 (porta = nº)"></div>
                <div class="fg"><label>Categoria</label><select id="g_cat"><?php foreach ($ITED_CATEGORIAS as $c): ?><option><?= $e($c) ?></option><?php endforeach; ?></select></div>
                <div class="fg"><label>Configuração</label><select id="g_conf"><?php foreach ($ITED_CONFIGURACOES as $c): ?><option><?= $e($c) ?></option><?php endforeach; ?></select></div>
            </div>
            <p class="ajuda">Ex.: prefixo TT, começar em 1, 2 algarismos → TT01, TT02… A porta do painel fica igual ao número.</p>
            <div style="display:flex; gap:10px; justify-content:flex-end;">
                <button type="button" class="btn btn-sec" onclick="document.getElementById('modalGerar').classList.remove('show')">Cancelar</button>
                <button type="button" class="btn btn-azul" onclick="gerarPontos()"><i class="fa-solid fa-check"></i> Gerar</button>
            </div>
        </div>
    </div>

    <script>
    const OBRAS = <?= json_encode(array_map(fn($o) => ['id' => (int)$o['id'], 'nome' => $o['nome'], 'morada' => (string)$o['morada']], $obras), $JSON) ?>;
    const PONTOS = <?= json_encode(array_values($pontos), $JSON) ?>;
    const CATEGORIAS = <?= json_encode($ITED_CATEGORIAS, $JSON) ?>;
    const CONFIGS = <?= json_encode($ITED_CONFIGURACOES, $JSON) ?>;
    const CAT_PADRAO = <?= json_encode(stripos($cfg['ited_norma_teste'], '6A') !== false ? 'Cat. 6A' : 'Cat. 6') ?>;
    const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

    // ---------- Obra ----------
    function obraEscolhida() {
        const o = OBRAS.find(x => x.id === parseInt(document.getElementById('id_obra').value || 0, 10));
        if (!o) return;
        const ed = document.getElementById('edificio'), mo = document.getElementById('morada');
        if (!ed.value.trim()) ed.value = o.nome;
        if (!mo.value.trim()) mo.value = o.morada;
    }

    // ---------- Projeto ITED (opcional) ----------
    function mostrarProjeto() {
        const tem = document.getElementById('tem_projeto').checked;
        document.getElementById('camposProjeto').style.display = tem ? '' : 'none';
        document.getElementById('semProjeto').style.display = tem ? 'none' : 'block';
    }
    mostrarProjeto();

    // ---------- Texto padrão (igual ao gerado no PDF) ----------
    function usarTextoPadrao() {
        const v = n => (document.querySelector('[name="' + n + '"]').value || '').trim();
        const ta = document.getElementById('texto_termo');
        if (ta.value.trim() && !confirm('Substituir o texto escrito pelo texto padrão?')) return;
        const obra = OBRAS.find(x => x.id === parseInt(document.getElementById('id_obra').value || 0, 10));
        const edif = v('edificio') || (obra ? obra.nome : '');
        const morada = v('morada') + (v('localidade') ? ', ' + v('localidade') : '');
        const temProj = document.getElementById('tem_projeto').checked;
        const proj = temProj && v('projetista_nome') ? ' da autoria de ' + v('projetista_nome') + (v('projetista_inscricao') ? ' (inscrição n.º ' + v('projetista_inscricao') + ')' : '') : '';
        ta.value = 'Eu, ' + (v('tecnico_nome') || '_______________') + ', técnico ITED' + (v('tecnico_inscricao') ? ' inscrito na ANACOM com o n.º ' + v('tecnico_inscricao') : '')
            + ', ao serviço da entidade instaladora m. Abreu – Instalações Técnicas, Lda.' + (v('entidade_registo') ? ' (registo n.º ' + v('entidade_registo') + ')' : '')
            + ', declaro, para os devidos efeitos, que a instalação de infraestruturas de telecomunicações em edifícios (ITED)'
            + (edif ? ' do edifício «' + edif + '»' : '') + (morada ? ', sito em ' + morada : '')
            + ', foi executada de acordo com ' + (temProj ? 'o projeto técnico' + proj + ' e com ' : '') + 'as regras técnicas do Manual ITED (4.ª edição),'
            + ' em cumprimento do Decreto-Lei n.º 123/2009, de 21 de maio, na sua redação atual.'
            + '\n\nDeclaro ainda que foram realizados os ensaios de funcionalidade previstos no Manual ITED, cujos resultados constam do'
            + ' relatório de ensaios que acompanha este termo, e que a instalação se encontra em condições de ser utilizada.';
    }

    // ---------- Pontos RJ45 ----------
    const numPT = v => (v === null || v === undefined || v === '' || isNaN(+String(v).replace(',', '.'))) ? (v ?? '') : String(+String(v).replace(',', '.')).replace('.', ',');
    const opts = (lista, sel) => lista.map(x => '<option' + (x === sel ? ' selected' : '') + '>' + esc(x) + '</option>').join('');
    function addPonto(d = {}, depois = null) {
        const tb = document.getElementById('corpoPontos');
        const tr = document.createElement('tr');
        const cat = d.categoria || CAT_PADRAO, conf = d.configuracao || CONFIGS[0];
        tr.innerHTML = '<td class="n" data-label="Nº"></td>'
            + '<td data-label="Identificação"><input class="p-id" maxlength="60" value="' + esc(d.identificacao) + '" placeholder="TT01"></td>'
            + '<td data-label="Localização"><input class="p-loc" maxlength="150" value="' + esc(d.localizacao) + '"></td>'
            + '<td data-label="Painel / porta"><input class="p-painel" maxlength="60" value="' + esc(d.painel_porta) + '"></td>'
            + '<td data-label="Categoria"><select class="p-cat">' + opts(CATEGORIAS.includes(cat) ? CATEGORIAS : CATEGORIAS.concat([cat]), cat) + '</select></td>'
            + '<td data-label="Configuração"><select class="p-conf">' + opts(CONFIGS.includes(conf) ? CONFIGS : CONFIGS.concat([conf]), conf) + '</select></td>'
            + '<td data-label="Comprimento (m)"><input class="p-comp" inputmode="decimal" value="' + esc(numPT(d.comprimento)) + '"></td>'
            + '<td data-label="Mapa de cablagem"><select class="p-mapa">' + opts(['OK', 'NOK'], d.mapa || 'OK') + '</select></td>'
            + '<td data-label="Margem NEXT (dB)"><input class="p-next" inputmode="decimal" value="' + esc(numPT(d.margem_next)) + '"></td>'
            + '<td data-label="Resultado"><select class="p-res sel-res">' + opts(['PASSA', 'FALHA'], d.resultado || 'PASSA') + '</select></td>'
            + '<td data-label="Observações"><input class="p-obs" maxlength="255" value="' + esc(d.observacoes) + '"></td>'
            + '<td><div class="acao-l"><button type="button" class="btn-l" title="Duplicar (número seguinte)" onclick="duplicar(this)"><i class="fa-solid fa-copy"></i></button>'
            + '<button type="button" class="btn-l del" title="Retirar" onclick="this.closest(\'tr\').remove(); atualizar();"><i class="fa-solid fa-trash-can"></i></button></div></td>';
        if (depois) depois.after(tr); else tb.appendChild(tr);
        tr.querySelectorAll('select, input').forEach(el => el.addEventListener('change', atualizar));
        atualizar();
        return tr;
    }
    // TT09 → TT10 (mantém os zeros à esquerda)
    function seguinte(txt) {
        const m = String(txt || '').match(/^(.*?)(\d+)(\D*)$/);
        if (!m) return txt;
        const n = String(parseInt(m[2], 10) + 1).padStart(m[2].length, '0');
        return m[1] + n + m[3];
    }
    function linha(tr) {
        const q = s => tr.querySelector(s).value.trim();
        return { identificacao: q('.p-id'), localizacao: q('.p-loc'), painel_porta: q('.p-painel'), categoria: q('.p-cat'), configuracao: q('.p-conf'),
                 comprimento: q('.p-comp').replace(',', '.'), mapa: q('.p-mapa'), margem_next: q('.p-next').replace(',', '.'), resultado: q('.p-res'), observacoes: q('.p-obs') };
    }
    function duplicar(btn) {
        const tr = btn.closest('tr'), d = linha(tr);
        d.identificacao = seguinte(d.identificacao); d.painel_porta = seguinte(d.painel_porta);
        d.comprimento = ''; d.margem_next = ''; d.observacoes = ''; d.mapa = 'OK'; d.resultado = 'PASSA';
        addPonto(d, tr).querySelector('.p-id').focus();
    }
    function atualizar() {
        const rows = Array.from(document.querySelectorAll('#corpoPontos tr'));
        let falha = 0, comp = [];
        rows.forEach((tr, i) => {
            tr.querySelector('.n').textContent = i + 1;
            const l = linha(tr);
            const f = l.resultado === 'FALHA' || l.mapa === 'NOK';
            tr.classList.toggle('falha', f);
            const r = tr.querySelector('.p-res'); r.style.color = l.resultado === 'FALHA' ? '#c0392b' : '#1e8449';
            if (l.identificacao) { if (f) falha++; if (l.comprimento !== '' && !isNaN(+l.comprimento)) comp.push(+l.comprimento); }
        });
        const n = rows.filter(tr => tr.querySelector('.p-id').value.trim()).length;
        document.getElementById('resumoPontos').innerHTML = n
            ? '<b>' + n + '</b> ponto(s) · ' + (falha ? '<span class="f"><b>' + falha + '</b> com falha</span>' : '<span class="ok"><i class="fa-solid fa-check"></i> todos passam</span>')
              + (comp.length ? ' · comprimento máx. <b>' + Math.max(...comp).toFixed(1).replace('.', ',') + ' m</b>' : '')
            : 'Ainda não há pontos. Use <b>Gerar em série</b>, <b>Importar CSV</b> ou <b>Linha</b>.';
    }
    function abrirGerar() { document.getElementById('modalGerar').classList.add('show'); }
    function gerarPontos() {
        const g = id => document.getElementById(id).value.trim();
        const ini = parseInt(g('g_inicio') || 1, 10), qtd = Math.min(500, parseInt(g('g_qtd') || 0, 10)), dig = parseInt(g('g_dig') || 2, 10);
        if (!qtd) return;
        // Linhas vazias no fim são substituídas
        document.querySelectorAll('#corpoPontos tr').forEach(tr => { if (!tr.querySelector('.p-id').value.trim()) tr.remove(); });
        for (let i = 0; i < qtd; i++) {
            const num = String(ini + i).padStart(dig, '0');
            addPonto({ identificacao: g('g_prefixo') + num, localizacao: g('g_local'), painel_porta: g('g_painel') ? g('g_painel') + '/' + num : '', categoria: g('g_cat'), configuracao: g('g_conf') });
        }
        document.getElementById('modalGerar').classList.remove('show');
    }

    // ---------- Importar CSV (certificador ou feito à mão) ----------
    function importarCSV(input) {
        const f = input.files[0]; input.value = ''; if (!f) return;
        const r = new FileReader();
        r.onload = ev => {
            let txt = new TextDecoder('utf-8').decode(ev.target.result);
            if (txt.includes('�')) txt = new TextDecoder('windows-1252').decode(ev.target.result);
            const linhas = txt.replace(/^﻿/, '').split(/\r?\n/).filter(l => l.trim());
            if (linhas.length < 2) { alert('O ficheiro não tem linhas.'); return; }
            const sep = [';', '\t', ','].sort((a, b) => linhas[0].split(b).length - linhas[0].split(a).length)[0];
            const parte = l => { const out = []; let cur = '', q = false; for (const ch of l) { if (ch === '"') q = !q; else if (ch === sep && !q) { out.push(cur); cur = ''; } else cur += ch; } out.push(cur); return out.map(x => x.trim()); };
            const norm = s => s.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().replace(/[^a-z0-9]/g, '');
            const cab = parte(linhas[0]).map(norm);
            const col = nomes => cab.findIndex(c => nomes.some(n => c === n || c.startsWith(n)));
            const C = { id: col(['cableid', 'idcabo', 'identificacao', 'tomada', 'ponto', 'id', 'name', 'nome']), loc: col(['local', 'localizacao', 'location', 'divisao']),
                        painel: col(['painel', 'porta', 'port', 'patch']), cat: col(['categoria', 'category', 'cat']), conf: col(['configuracao', 'testtype', 'tipoensaio', 'linktype']),
                        comp: col(['length', 'comprimento', 'compr']), mapa: col(['wiremap', 'mapa']), next: col(['nextmargin', 'worstnext', 'margemnext', 'margem', 'next']),
                        res: col(['result', 'resultado', 'summary', 'status', 'passfail']), obs: col(['obs', 'observacoes', 'comment', 'notas']) };
            if (C.id < 0) { alert('Não encontrei a coluna da identificação do ponto (ex.: "Cable ID" ou "Identificação").'); return; }
            const num = v => { const m = String(v || '').replace(',', '.').match(/-?\d+(\.\d+)?/); return m ? m[0] : ''; };
            const passa = v => !/fail|falha|nok|reprov/i.test(String(v || ''));
            document.querySelectorAll('#corpoPontos tr').forEach(tr => { if (!tr.querySelector('.p-id').value.trim()) tr.remove(); });
            let n = 0;
            linhas.slice(1).forEach(l => {
                const v = parte(l), g = i => (i >= 0 ? v[i] || '' : '');
                if (!g(C.id)) return;
                let cat = g(C.cat); const mc = cat.match(/(5e|6a|6|7a|7|8)/i); cat = mc ? 'Cat. ' + mc[1].toUpperCase().replace('E', 'e') : '';
                const conf = /channel|canal/i.test(g(C.conf)) ? 'Canal' : (g(C.conf) ? 'Ligação permanente' : '');
                addPonto({ identificacao: g(C.id), localizacao: g(C.loc), painel_porta: g(C.painel), categoria: cat, configuracao: conf,
                           comprimento: num(g(C.comp)), mapa: C.mapa >= 0 ? (passa(g(C.mapa)) ? 'OK' : 'NOK') : 'OK', margem_next: num(g(C.next)),
                           resultado: C.res >= 0 ? (passa(g(C.res)) ? 'PASSA' : 'FALHA') : 'PASSA', observacoes: g(C.obs) });
                n++;
            });
            alert(n + ' ponto(s) importado(s). Confirme os dados antes de gravar.');
        };
        r.readAsArrayBuffer(f);
    }

    document.getElementById('formIted').addEventListener('submit', ev => {
        const rows = Array.from(document.querySelectorAll('#corpoPontos tr')).map(linha).filter(l => l.identificacao);
        // Identificações repetidas (ex.: o mesmo ponto gerado e importado do certificador)
        const vistos = {}, rep = [];
        rows.forEach(r => { const k = r.identificacao.toLowerCase(); if (vistos[k]) rep.push(r.identificacao); vistos[k] = 1; });
        if (rep.length && !confirm('Há identificações repetidas: ' + [...new Set(rep)].join(', ') + '.\nGravar mesmo assim?')) { ev.preventDefault(); return; }
        document.getElementById('pontos_json').value = JSON.stringify(rows);
    });

    (function () {
        PONTOS.forEach(p => addPonto(p));
        if (!PONTOS.length) atualizar();
        const menu = document.querySelector('.user-menu');
        menu.querySelector('.user-btn').addEventListener('click', e => { e.stopPropagation(); menu.classList.toggle('aberto'); });
        document.addEventListener('click', () => menu.classList.remove('aberto'));
    })();
    </script>
</body>
</html>
