<?php
session_start();
require_once 'db.php';

// REGRA DE OURO 1: Validação de sessão
if (!isset($_SESSION['user_id'])) { header("Location: index.php"); exit; }

$perfil_user = $_SESSION['perfil'] ?? '';
$is_admin = in_array($perfil_user, ['admin', 'administrativo']);
$is_funcionario = ($perfil_user === 'funcionario');

$stmt_perm = $pdo->prepare("SELECT modulo FROM permissoes WHERE grupo = :perfil AND pode_aceder = 1");
$stmt_perm->execute(['perfil' => $perfil_user]);
$meus_acessos = $stmt_perm->fetchAll(PDO::FETCH_COLUMN);

// Acesso à listagem de termos (para onde se volta depois de gravar)
$pode_ver_lista = $is_admin || in_array('termos_eletrico', $meus_acessos);

// ALTERADO: Os utilizadores do tipo "funcionario" também podem aceder a este formulário
if (!$is_admin && !$is_funcionario && !in_array('termos_eletrico', $meus_acessos)) {
    header("Location: index.php?erro=sem_permissao"); exit;
}

$url_voltar = $pode_ver_lista ? 'termos_eletrico.php' : 'index.php';

// --- ATUALIZAR TABELAS AUTOMATICAMENTE ---
try {
    // Verifica coluna de fases nas linhas
    $check_fases = $pdo->query("SHOW COLUMNS FROM vistorias_quadros_linhas LIKE 'fases'");
    if ($check_fases !== false && $check_fases->rowCount() == 0) {
        $pdo->exec("ALTER TABLE vistorias_quadros_linhas ADD COLUMN fases VARCHAR(30) DEFAULT 'Mono' AFTER localizacao");
    }

    // Verifica coluna de proteção diferencial
    $check_dif = $pdo->query("SHOW COLUMNS FROM vistorias_quadros_linhas LIKE 'protecao_diferencial'");
    if ($check_dif !== false && $check_dif->rowCount() == 0) {
        $pdo->exec("ALTER TABLE vistorias_quadros_linhas ADD COLUMN protecao_diferencial VARCHAR(20) DEFAULT '' AFTER corrente_nominal");
    }

    // Verifica coluna de observacoes gerais no documento principal
    $check_obs_gerais = $pdo->query("SHOW COLUMNS FROM vistorias_quadros LIKE 'observacoes_gerais'");
    if ($check_obs_gerais !== false && $check_obs_gerais->rowCount() == 0) {
        $pdo->exec("ALTER TABLE vistorias_quadros ADD COLUMN observacoes_gerais TEXT DEFAULT NULL");
    }

    // NOVO: Resistência de terra (um valor único para toda a instalação)
    $check_terra = $pdo->query("SHOW COLUMNS FROM vistorias_quadros LIKE 'resistencia_terra'");
    if ($check_terra !== false && $check_terra->rowCount() == 0) {
        $pdo->exec("ALTER TABLE vistorias_quadros ADD COLUMN resistencia_terra VARCHAR(30) DEFAULT NULL AFTER morada_obra");
    }

    // NOVO: Fotografias de cada quadro (lista de ficheiros em JSON)
    $check_fotos = $pdo->query("SHOW COLUMNS FROM vistorias_quadros_linhas LIKE 'fotos'");
    if ($check_fotos !== false && $check_fotos->rowCount() == 0) {
        $pdo->exec("ALTER TABLE vistorias_quadros_linhas ADD COLUMN fotos TEXT DEFAULT NULL");
    }
} catch (Exception $e) {
    // Ignora para não quebrar a página
}

function validarNIF($nif) {
    if (empty(trim($nif))) return true;
    if ($nif === '999999990') return true;
    if (!preg_match("/^[0-9]{9}$/", $nif)) return false;
    $primeiros_digitos = ['1', '2', '3', '4', '5', '6', '7', '8', '9'];
    if (!in_array($nif[0], $primeiros_digitos)) return false;
    $soma = 0;
    for ($i = 0; $i < 8; $i++) { $soma += $nif[$i] * (9 - $i); }
    $resto = $soma % 11;
    $digito_controlo = ($resto < 2) ? 0 : 11 - $resto;
    return $digito_controlo == $nif[8];
}

// NOVO: Grava uma fotografia enviada em base64 (já comprimida no telemóvel/computador)
// Devolve o nome do ficheiro gravado, ou null se a imagem for inválida
function guardarFotoBase64($dataurl, $pasta = 'imagens/') {
    if (!is_string($dataurl) || !preg_match('#^data:image/(jpeg|png|webp);base64,#', $dataurl, $m)) return null;
    $binario = base64_decode(substr($dataurl, strpos($dataurl, ',') + 1), true);
    if ($binario === false || strlen($binario) === 0 || strlen($binario) > 8 * 1024 * 1024) return null;
    if (@getimagesizefromstring($binario) === false) return null;
    if (!is_dir($pasta)) { @mkdir($pasta, 0777, true); }
    $extensao = ($m[1] === 'jpeg') ? 'jpg' : $m[1];
    $nome = uniqid('vistoria_quadro_') . '_' . time() . '.' . $extensao;
    return (@file_put_contents($pasta . $nome, $binario) !== false) ? $nome : null;
}

$mensagem = ''; $tipo_mensagem = '';
if (isset($_GET['sucesso'])) { $mensagem = "Vistoria gravada com sucesso!"; $tipo_mensagem = "sucesso"; }

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$modo_editar = !empty($id);

$clientes = $pdo->query("SELECT id, nome FROM clientes WHERE ativo = 1 ORDER BY nome ASC")->fetchAll(PDO::FETCH_ASSOC);

// 1. CARREGAR DADOS
$linhas_vistoria = [];
if ($modo_editar) {
    $stmt = $pdo->prepare("SELECT * FROM vistorias_quadros WHERE id = ?");
    $stmt->execute([$id]);
    $doc = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$doc) { header("Location: " . $url_voltar); exit; }
    $titulo_pagina = "Editar Vistoria #" . $doc['referencia'];

    $stmt_linhas = $pdo->prepare("SELECT * FROM vistorias_quadros_linhas WHERE id_vistoria = ? ORDER BY id ASC");
    $stmt_linhas->execute([$id]);
    $linhas_vistoria = $stmt_linhas->fetchAll(PDO::FETCH_ASSOC);
} else {
    $ano_atual_curto = date('y');
    $stmt_ref = $pdo->query("SELECT valor FROM configuracoes WHERE chave = 'ultimo_numero_projeto'");
    $ultima_ref = $stmt_ref->fetchColumn();
    $referencia_gerada = ($ultima_ref && substr($ultima_ref, 0, 2) === $ano_atual_curto) ? (string)((int)$ultima_ref + 1) : $ano_atual_curto . "0100";

    $titulo_pagina = "Nova Vistoria de Quadros";
    $doc = ['referencia' => $referencia_gerada, 'data_vistoria' => date('Y-m-d'), 'observacoes_gerais' => '', 'resistencia_terra' => ''];
}

// Fotografias que já existiam nesta vistoria (para manter as que continuam e apagar as removidas)
$fotos_antigas = [];
foreach ($linhas_vistoria as $lv) {
    $arr = json_decode($lv['fotos'] ?? '[]', true);
    if (is_array($arr)) { foreach ($arr as $f) { $fotos_antigas[] = basename($f); } }
}

// 2. PROCESSAR GRAVAÇÃO
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fotos_novas_gravadas = []; // para limpar ficheiros se a gravação falhar
    try {
        $pdo->beginTransaction();

        $id_cliente_final = $_POST['id_cliente'];
        if ($id_cliente_final === 'novo') {
            $nif_cliente = trim($_POST['novo_cliente_nif'] ?? '');
            if (!validarNIF($nif_cliente)) throw new Exception("O NIF do novo cliente é inválido.");

            $stmt_cli_novo = $pdo->prepare("INSERT INTO clientes (nome, nif, telefone, email, morada, codigo_postal, cidade, ativo) VALUES (?, ?, ?, ?, ?, ?, ?, 1)");
            $stmt_cli_novo->execute([
                trim($_POST['novo_cliente_nome']), $nif_cliente, trim($_POST['novo_cliente_telefone'] ?? ''),
                trim($_POST['novo_cliente_email'] ?? ''), trim($_POST['novo_cliente_morada'] ?? ''),
                trim($_POST['novo_cliente_cp'] ?? ''), trim($_POST['novo_cliente_cidade'] ?? '')
            ]);
            $id_cliente_final = $pdo->lastInsertId();
        }

        $obs_gerais_post = trim($_POST['observacoes_gerais'] ?? '');
        $resistencia_terra_post = trim($_POST['resistencia_terra'] ?? '');
        if ($resistencia_terra_post === '') $resistencia_terra_post = null;

        if ($modo_editar) {
            $stmt = $pdo->prepare("UPDATE vistorias_quadros SET data_vistoria=?, id_cliente=?, nome_obra=?, morada_obra=?, resistencia_terra=?, observacoes_gerais=? WHERE id=?");
            $stmt->execute([$_POST['data_vistoria'], $id_cliente_final, $_POST['nome_obra'], $_POST['morada_obra'], $resistencia_terra_post, $obs_gerais_post, $id]);
            $id_atual = $id;
        } else {
            $stmt = $pdo->prepare("INSERT INTO vistorias_quadros (referencia, data_vistoria, id_cliente, nome_obra, morada_obra, resistencia_terra, observacoes_gerais) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$_POST['referencia'], $_POST['data_vistoria'], $id_cliente_final, $_POST['nome_obra'], $_POST['morada_obra'], $resistencia_terra_post, $obs_gerais_post]);
            $id_atual = $pdo->lastInsertId();

            $pdo->prepare("INSERT INTO configuracoes (chave, valor) VALUES ('ultimo_numero_projeto', ?) ON DUPLICATE KEY UPDATE valor = ?")->execute([$_POST['referencia'], $_POST['referencia']]);
        }

        // Processar Linhas (Quadros) COM OS VALORES DE FASES, DIFERENCIAL E FOTOGRAFIAS
        $fotos_mantidas = [];
        $pdo->prepare("DELETE FROM vistorias_quadros_linhas WHERE id_vistoria = ?")->execute([$id_atual]);
        if (isset($_POST['n_quadro']) && is_array($_POST['n_quadro'])) {
            $stmt_linha = $pdo->prepare("INSERT INTO vistorias_quadros_linhas (id_vistoria, n_quadro, localizacao, fases, corrente_nominal, protecao_diferencial, cabo_entrada, estado, observacoes, fotos) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            for ($i = 0; $i < count($_POST['n_quadro']); $i++) {
                $nq = trim($_POST['n_quadro'][$i]);
                if ($nq === '') continue;

                // Identificador da linha (liga a linha às suas fotografias)
                $uid = $_POST['row_uid'][$i] ?? '';
                if (!preg_match('/^q[0-9a-z_]+$/i', $uid)) $uid = '';

                $fotos_linha = [];
                if ($uid !== '') {
                    // a) Fotografias que já existiam e não foram removidas
                    $existentes = json_decode($_POST['fotos_existentes_' . $uid] ?? '[]', true);
                    if (is_array($existentes)) {
                        foreach ($existentes as $f) {
                            $f = basename((string)$f);
                            if (in_array($f, $fotos_antigas, true)) { $fotos_linha[] = $f; $fotos_mantidas[] = $f; }
                        }
                    }
                    // b) Fotografias novas tiradas/escolhidas agora
                    $novas = $_POST['fotos_novas_' . $uid] ?? [];
                    if (is_array($novas)) {
                        foreach ($novas as $dataurl) {
                            $nome = guardarFotoBase64($dataurl);
                            if ($nome) { $fotos_linha[] = $nome; $fotos_novas_gravadas[] = $nome; }
                        }
                    }
                }

                $stmt_linha->execute([
                    $id_atual,
                    $nq,
                    trim($_POST['localizacao'][$i] ?? ''),
                    trim($_POST['fases'][$i] ?? 'Mono'),
                    trim($_POST['corrente_nominal'][$i] ?? ''),
                    trim($_POST['protecao_diferencial'][$i] ?? ''),
                    trim($_POST['cabo_entrada'][$i] ?? ''),
                    trim($_POST['estado'][$i] ?? 'OK'),
                    trim($_POST['observacoes'][$i] ?? ''),
                    !empty($fotos_linha) ? json_encode(array_values($fotos_linha)) : null
                ]);
            }
        }

        $pdo->commit();

        // Apagar do servidor as fotografias que foram removidas desta vistoria
        foreach (array_diff($fotos_antigas, $fotos_mantidas) as $f_removida) {
            $caminho = 'imagens/' . basename($f_removida);
            if (is_file($caminho)) { @unlink($caminho); }
        }

        // Quem não tem acesso à listagem de termos (ex: funcionário) fica na própria vistoria
        if ($pode_ver_lista) {
            header("Location: termos_eletrico.php?sucesso=1");
        } else {
            header("Location: vistoria_quadros_form.php?id=" . $id_atual . "&sucesso=1");
        }
        exit;
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        // A gravação falhou: apaga as fotografias novas que já tinham sido guardadas
        foreach ($fotos_novas_gravadas as $f) { if (is_file('imagens/' . $f)) { @unlink('imagens/' . $f); } }
        $mensagem = $e->getMessage(); $tipo_mensagem = "erro";
    }
}
?>
<!DOCTYPE html>
<html lang="pt-PT">
<head>
    <meta charset="UTF-8">
    <title><?= htmlspecialchars($titulo_pagina) ?> - MAB</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { font-family: system-ui, sans-serif; background: #f7f9fc; margin: 0; color: #333; padding-bottom: 90px; }
        * { box-sizing: border-box; }
        .topbar { background-color: #fff; padding: 10px 40px; box-shadow: 0 2px 15px rgba(0,0,0,0.05); display: flex; justify-content: space-between; align-items: center; position: sticky; top: 0; z-index: 100; }
        .topbar-left .logo { height: 35px; }
        .btn-back { color: #576574; text-decoration: none; font-size: 16px; font-weight: 600; display: flex; align-items: center; gap: 8px; transition: 0.2s; }
        .btn-back:hover { color: #e67e22; }

        .container { max-width: 1400px; margin: 20px auto; padding: 0 20px; }
        .page-header { margin-bottom: 25px; border-bottom: 1px solid #e1e5ea; padding-bottom: 15px; }
        .page-title { margin: 0; color: #2c3e50; font-size: 22px; font-weight: 800; display: flex; align-items: center; gap: 10px; }
        .card { background: #fff; padding: 20px; border-radius: 12px; border: 1px solid #e1e5ea; box-shadow: 0 2px 10px rgba(0,0,0,0.03); margin-bottom: 20px; }
        h2 { font-size: 14px; color: #e67e22; margin: 0 0 15px 0; border-bottom: 1px solid #f1f2f6; padding-bottom: 8px; text-transform: uppercase; display:flex; gap:8px; align-items:center;}

        .form-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 15px; }
        .form-group { display: flex; flex-direction: column; }
        .form-group.full { grid-column: 1 / -1; }

        label { font-weight: 600; margin-bottom: 5px; font-size: 11px; color: #576574; text-transform: uppercase; }
        input, select, textarea { padding: 10px 12px; border: 1px solid #dcdde1; border-radius: 6px; font-size: 14px; outline: none; transition: 0.2s; background: #fff; font-family: inherit;}
        input:focus, select:focus, textarea:focus { border-color: #e67e22; box-shadow: 0 0 0 3px rgba(230, 126, 34, 0.1); }

        /* Resistência de terra (campo com unidade) */
        .input-unidade { display: flex; align-items: stretch; }
        .input-unidade input { flex: 1; border-top-right-radius: 0; border-bottom-right-radius: 0; font-weight: 700; }
        .input-unidade span { background: #f1f2f6; border: 1px solid #dcdde1; border-left: none; border-radius: 0 6px 6px 0; padding: 0 12px; display: flex; align-items: center; font-weight: 700; color: #576574; }

        .items-table { width: 100%; border-collapse: collapse; margin-top: 5px; }
        .items-table th { padding: 8px; background: #f8f9fa; color: #576574; font-size: 11px; font-weight: 600; text-transform: uppercase; text-align: left; border-bottom: 2px solid #dee2e6; }
        .items-table td { padding: 4px; border-bottom: 1px solid #f1f2f6; vertical-align: top; }
        .items-table input, .items-table select, .items-table textarea { width: 100%; box-sizing: border-box; }

        .items-table textarea { resize: none; overflow: hidden; min-height: 38px; line-height: 1.4; padding-top: 8px; }

        .col-del { width: 70px; text-align: center; }

        .select-estado { font-weight: 600; }
        .select-estado.estado-ok { background-color: #eafaf1; color: #27ae60; border-color: #2ecc71; }
        .select-estado.estado-nok { background-color: #fdeded; color: #c0392b; border-color: #e74c3c; }

        .btn-row-action { background: none; border: none; cursor: pointer; font-size: 14px; padding: 6px; border-radius: 4px; transition: 0.2s; display: inline-flex; align-items: center; justify-content: center;}
        .btn-del-row { color: #e74c3c; }
        .btn-del-row:hover { background: #fdeded; }
        .btn-add-row { color: #2ecc71; margin-right: 2px;}
        .btn-add-row:hover { background: #e8f5e9; }

        /* Fotografias de cada quadro */
        .fotos-quadro { min-width: 150px; }
        .fotos-botoes { display: flex; gap: 6px; }
        .btn-foto { background: #fff8e1; border: 1px solid #f5d76e; color: #8a6d00; border-radius: 6px; padding: 8px 10px; cursor: pointer; font-size: 13px; display: inline-flex; align-items: center; gap: 5px; transition: 0.2s; white-space: nowrap; }
        .btn-foto:hover { background: #fdebc8; border-color: #f1c40f; }
        .btn-foto.galeria { background: #f1f2f6; border-color: #dcdde1; color: #576574; }
        .fotos-thumbs { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 6px; }
        .foto-thumb { position: relative; width: 56px; height: 56px; border-radius: 6px; overflow: hidden; border: 1px solid #e1e5ea; background: #eee; }
        .foto-thumb img { width: 100%; height: 100%; object-fit: cover; cursor: zoom-in; }
        .foto-thumb.nova img { opacity: 0.85; }
        .foto-thumb.a-processar { display: flex; align-items: center; justify-content: center; color: #95a5a6; }
        .foto-thumb .btn-rm-foto { position: absolute; top: 2px; right: 2px; width: 18px; height: 18px; border-radius: 50%; border: none; background: rgba(231,76,60,0.9); color: #fff; font-size: 10px; cursor: pointer; display: flex; align-items: center; justify-content: center; padding: 0; }

        .floating-action-bar { position: fixed; bottom: 20px; right: 20px; background: #fff; padding: 15px; border-radius: 12px; box-shadow: 0 5px 20px rgba(0,0,0,0.15); z-index: 1000; border: 1px solid #e1e5ea; }
        .btn-save { background: #e67e22; color: #fff; padding: 12px 30px; border-radius: 8px; border: none; font-weight: 700; font-size: 14px; cursor: pointer; transition: 0.2s; display:flex; align-items:center; gap:8px;}
        .btn-save:hover { background: #d35400; }

        .alert { padding: 15px; border-radius: 8px; margin-bottom: 20px; text-align: center; font-weight: 600; background: #fdeded; color: #c0392b; border: 1px solid #f9cccc; }
        .alert.alert-sucesso { background: #e8f5e9; color: #2e7d32; border-color: #c8e6c9; }

        @media (max-width: 768px) {
            .topbar { padding: 10px 15px; }
            .btn-back span { display: none; }
            .items-table { display: block; overflow-x: auto; white-space: nowrap; }
            .floating-action-bar { left: 15px; right: 15px; bottom: 15px; padding: 10px; display: flex; justify-content: center;}
            .btn-save { width: 100%; justify-content: center; padding: 15px; font-size: 15px;}
        }
    </style>
</head>
<body>

    <header class="topbar">
        <div class="topbar-left"><img src="mab.png" height="35" alt="MAB"></div>
        <div class="topbar-right">
            <a href="<?= $url_voltar ?>" class="btn-back"><i class="fa-solid fa-arrow-left"></i> <span>Voltar</span></a>
        </div>
    </header>

    <div class="container">
        <?php if ($mensagem): ?><div class="alert <?= $tipo_mensagem === 'sucesso' ? 'alert-sucesso' : '' ?>"><?= htmlspecialchars($mensagem) ?></div><?php endif; ?>

        <div class="page-header">
            <h1 class="page-title"><i class="fa-solid fa-clipboard-check" style="color:#e67e22;"></i> <?= htmlspecialchars($titulo_pagina) ?></h1>
        </div>

        <form method="POST" id="formVistoria">
            <div class="card">
                <h2><i class="fa-solid fa-building"></i> Cliente e Localização</h2>
                <div class="form-grid">
                    <div class="form-group"><label>Referência Nº</label><input type="text" name="referencia" value="<?= htmlspecialchars($doc['referencia']) ?>" readonly style="background:#fdfdfd; font-weight:bold; color:#e67e22;"></div>
                    <div class="form-group"><label>Data da Vistoria</label><input type="date" name="data_vistoria" value="<?= htmlspecialchars($doc['data_vistoria']) ?>" required></div>
                    <div class="form-group">
                        <label>Cliente *</label>
                        <select name="id_cliente" required onchange="toggleNovoCliente(this.value)">
                            <option value="">-- Selecione --</option>
                            <option value="novo" style="font-weight:bold; color:#f39c12;">+ Criar Novo Cliente</option>
                            <?php foreach($clientes as $c): ?>
                                <option value="<?= $c['id'] ?>" <?= (($doc['id_cliente'] ?? '') == $c['id']) ? 'selected' : '' ?>><?= htmlspecialchars($c['nome']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group"><label>Obra / Local *</label><input type="text" name="nome_obra" value="<?= htmlspecialchars($doc['nome_obra'] ?? '') ?>" required></div>
                    <div class="form-group full"><label>Morada da Instalação</label><input type="text" name="morada_obra" value="<?= htmlspecialchars($doc['morada_obra'] ?? '') ?>"></div>

                    <div id="form_novo_cliente" class="form-group full" style="display:none; background: #fffcf5; border: 1px dashed #f39c12; padding: 15px; border-radius: 8px;">
                        <h3 style="color:#f39c12; margin:0 0 10px 0; font-size:13px;"><i class="fa-solid fa-user-plus"></i> Novo Cliente</h3>
                        <div class="form-grid">
                            <div class="form-group"><label>NOME *</label><input type="text" name="novo_cliente_nome" id="novo_cliente_nome"></div>
                            <div class="form-group"><label>NIF</label><input type="text" name="novo_cliente_nif"></div>
                            <div class="form-group"><label>TELEFONE</label><input type="text" name="novo_cliente_telefone"></div>
                            <div class="form-group"><label>EMAIL</label><input type="email" name="novo_cliente_email"></div>
                            <div class="form-group"><label>MORADA</label><input type="text" name="novo_cliente_morada"></div>
                            <div class="form-group"><label>C. POSTAL</label><input type="text" name="novo_cliente_cp" placeholder="0000-000"></div>
                            <div class="form-group"><label>LOCALIDADE</label><input type="text" name="novo_cliente_cidade"></div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- NOVO: Medição geral da instalação -->
            <div class="card">
                <h2><i class="fa-solid fa-gauge-high"></i> Medições da Instalação</h2>
                <div class="form-grid">
                    <div class="form-group">
                        <label>Resistência de Terra (toda a instalação)</label>
                        <div class="input-unidade">
                            <input type="text" name="resistencia_terra" inputmode="decimal" value="<?= htmlspecialchars($doc['resistencia_terra'] ?? '') ?>" placeholder="Ex: 12,5">
                            <span>Ω</span>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card">
                <h2>
                    <span><i class="fa-solid fa-bolt"></i> Quadros Elétricos Inspecionados</span>
                </h2>

                <table class="items-table" id="tabelaQuadros">
                    <thead>
                        <tr>
                            <th width="9%">Nº Quadro</th>
                            <th width="12%">Localização</th>
                            <th width="9%">Fases</th>
                            <th width="8%">I. Nominal</th>
                            <th width="8%">Dif. (mA)</th>
                            <th width="10%">Cabo Entrada</th>
                            <th width="8%">Estado</th>
                            <th width="15%">Observações</th>
                            <th width="14%">Fotografias</th>
                            <th class="col-del">Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                        </tbody>
                    <tfoot>
                        <tr>
                            <td colspan="10" style="border:none; padding-top:10px;">
                                <button type="button" style="background:#576574; color:#fff; border:none; padding:8px 15px; border-radius:6px; cursor:pointer; font-size:12px; font-weight:bold;" onclick="addRow()"><i class="fa-solid fa-plus"></i> Adicionar Quadro</button>
                            </td>
                        </tr>
                    </tfoot>
                </table>
            </div>

            <div class="card" style="margin-bottom: 40px;">
                <h2><i class="fa-solid fa-comment-dots"></i> Observações Gerais da Vistoria</h2>
                <div class="form-group" style="margin-bottom:0;">
                    <textarea name="observacoes_gerais" rows="5" placeholder="Indique aqui notas gerais sobre a vistoria, recomendações globais ou detalhes que não se enquadrem num quadro específico..."><?= htmlspecialchars($doc['observacoes_gerais'] ?? '') ?></textarea>
                </div>
            </div>

            <div class="floating-action-bar">
                <button type="submit" class="btn-save" id="btnGravar"><i class="fa-solid fa-floppy-disk"></i> GRAVAR VISTORIA</button>
            </div>
        </form>
    </div>

    <script>
        function toggleNovoCliente(val) {
            const div = document.getElementById('form_novo_cliente');
            const nomeInput = document.getElementById('novo_cliente_nome');
            div.style.display = (val === 'novo') ? 'block' : 'none';
            if (val === 'novo') nomeInput.setAttribute('required', 'required');
            else nomeInput.removeAttribute('required');
        }

        function changeColorEstado(selectElement) {
            if (selectElement.value === 'OK') {
                selectElement.className = 'select-estado estado-ok';
            } else {
                selectElement.className = 'select-estado estado-nok';
            }
        }

        function autoResize(textarea) {
            textarea.style.height = 'auto';
            textarea.style.height = textarea.scrollHeight + 'px';
        }

        function escapeHTML(str) {
            str = (str === null || str === undefined) ? '' : String(str);
            return str.replace(/[&<>'"]/g, t => ({ '&':'&amp;', '<':'&lt;', '>':'&gt;', "'":'&#39;', '"':'&quot;' }[t]));
        }

        // Identificador único de cada linha (liga a linha às suas fotografias no servidor)
        let contadorLinhas = 0;
        function novoUid() { contadorLinhas++; return 'q' + Date.now().toString(36) + '_' + contadorLinhas; }

        // Lê as fotografias gravadas de uma linha (vêm em JSON da base de dados)
        function lerFotosLinha(data) {
            if (!data || !data.fotos) return [];
            if (Array.isArray(data.fotos)) return data.fotos;
            try { const arr = JSON.parse(data.fotos); return Array.isArray(arr) ? arr : []; } catch (e) { return []; }
        }

        function getRowHTML(data = {}, uid) {
            let selectedOK = (data.estado === 'OK' || !data.estado) ? 'selected' : '';
            let selectedNOK = (data.estado === 'NOK') ? 'selected' : '';
            let classEstado = (data.estado === 'NOK') ? 'estado-nok' : 'estado-ok';

            let isTri = (data.fases === 'Tri' || data.fases === 'Trifásico');
            let selMono = isTri ? '' : 'selected';
            let selTri  = isTri ? 'selected' : '';

            // Opções do Diferencial
            let pDif = data.protecao_diferencial || '';

            // Fotografias já gravadas deste quadro
            const fotos = lerFotosLinha(data);
            const thumbsExistentes = fotos.map(f => `
                <div class="foto-thumb" data-ficheiro="${escapeHTML(f)}">
                    <img src="imagens/${encodeURIComponent(f)}" alt="Foto" onclick="window.open(this.src, '_blank')">
                    <button type="button" class="btn-rm-foto" title="Remover" onclick="removerFotoExistente(this)"><i class="fa-solid fa-xmark"></i></button>
                </div>`).join('');

            return `
                <td>
                    <input type="hidden" name="row_uid[]" value="${uid}">
                    <input type="text" name="n_quadro[]" value="${escapeHTML(data.n_quadro)}" placeholder="Q.E. 01">
                </td>
                <td><input type="text" name="localizacao[]" value="${escapeHTML(data.localizacao)}" placeholder="Ex: C.T."></td>
                <td>
                    <select name="fases[]" style="width:100%; color:#34495e;">
                        <option value="Mono" ${selMono}>Monofásico</option>
                        <option value="Tri" ${selTri}>Trifásico</option>
                    </select>
                </td>
                <td><input type="text" name="corrente_nominal[]" value="${escapeHTML(data.corrente_nominal)}" placeholder="Ex: 63A"></td>
                <td>
                    <select name="protecao_diferencial[]" style="width:100%; color:#34495e;">
                        <option value="" ${pDif==='' ? 'selected':''}>N/D</option>
                        <option value="10" ${pDif==='10' ? 'selected':''}>10 mA</option>
                        <option value="30" ${pDif==='30' ? 'selected':''}>30 mA</option>
                        <option value="100" ${pDif==='100' ? 'selected':''}>100 mA</option>
                        <option value="300" ${pDif==='300' ? 'selected':''}>300 mA</option>
                        <option value="500" ${pDif==='500' ? 'selected':''}>500 mA</option>
                    </select>
                </td>
                <td><input type="text" name="cabo_entrada[]" value="${escapeHTML(data.cabo_entrada)}" placeholder="Ex: XV 5G16"></td>
                <td>
                    <select name="estado[]" class="select-estado ${classEstado}" onchange="changeColorEstado(this)">
                        <option value="OK" ${selectedOK}>OK</option>
                        <option value="NOK" ${selectedNOK}>NOK</option>
                    </select>
                </td>
                <td><textarea name="observacoes[]" rows="1" oninput="autoResize(this)" placeholder="Irregularidades...">${escapeHTML(data.observacoes)}</textarea></td>
                <td class="fotos-quadro">
                    <input type="hidden" class="fotos-existentes" name="fotos_existentes_${uid}" value="${escapeHTML(JSON.stringify(fotos))}">
                    <div class="fotos-botoes">
                        <button type="button" class="btn-foto" title="Tirar fotografia" onclick="this.closest('td').querySelector('.input-camara').click()"><i class="fa-solid fa-camera"></i> Foto</button>
                        <button type="button" class="btn-foto galeria" title="Escolher da galeria" onclick="this.closest('td').querySelector('.input-galeria').click()"><i class="fa-regular fa-images"></i></button>
                    </div>
                    <input type="file" class="input-camara" accept="image/*" capture="environment" style="display:none;" onchange="adicionarFotos(this, '${uid}')">
                    <input type="file" class="input-galeria" accept="image/*" multiple style="display:none;" onchange="adicionarFotos(this, '${uid}')">
                    <div class="fotos-thumbs">${thumbsExistentes}</div>
                </td>
                <td class="col-del">
                    <button type="button" class="btn-row-action btn-add-row" onclick="insertRowAfter(this)"><i class="fa-solid fa-plus"></i></button>
                    <button type="button" class="btn-row-action btn-del-row" onclick="this.closest('tr').remove()"><i class="fa-solid fa-trash-can"></i></button>
                </td>
            `;
        }

        function addRow(data = {}) {
            const tbody = document.querySelector('#tabelaQuadros tbody');
            const row = tbody.insertRow();
            row.innerHTML = getRowHTML(data, novoUid());

            const ta = row.querySelector('textarea');
            if(ta && ta.value) {
                setTimeout(() => autoResize(ta), 50);
            }
        }

        function insertRowAfter(btn) {
            const currentRow = btn.closest('tr');
            const tbody = currentRow.parentNode;
            const newRow = tbody.insertRow(currentRow.rowIndex);
            newRow.innerHTML = getRowHTML({}, novoUid());
        }

        // ==========================================
        // FOTOGRAFIAS DOS QUADROS
        // As fotos são reduzidas no próprio telemóvel/computador (máx. 1600 px, JPEG)
        // antes de serem enviadas, para a gravação ser rápida e não exceder os limites do servidor.
        // ==========================================
        let fotosEmProcessamento = 0;

        function comprimirImagem(file, maxLado = 1600, qualidade = 0.8) {
            return new Promise((resolve, reject) => {
                const url = URL.createObjectURL(file);
                const img = new Image();
                img.onload = () => {
                    let w = img.naturalWidth, h = img.naturalHeight;
                    const escala = Math.min(1, maxLado / Math.max(w, h));
                    w = Math.round(w * escala); h = Math.round(h * escala);
                    const canvas = document.createElement('canvas');
                    canvas.width = w; canvas.height = h;
                    canvas.getContext('2d').drawImage(img, 0, 0, w, h);
                    URL.revokeObjectURL(url);
                    resolve(canvas.toDataURL('image/jpeg', qualidade));
                };
                img.onerror = () => { URL.revokeObjectURL(url); reject(new Error('Imagem inválida')); };
                img.src = url;
            });
        }

        function adicionarFotos(input, uid) {
            const td = input.closest('td');
            const thumbs = td.querySelector('.fotos-thumbs');
            const ficheiros = Array.from(input.files || []).filter(f => f.type.startsWith('image/'));
            input.value = ''; // permite voltar a escolher/tirar outra foto

            ficheiros.forEach(file => {
                const marcador = document.createElement('div');
                marcador.className = 'foto-thumb a-processar';
                marcador.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i>';
                thumbs.appendChild(marcador);
                fotosEmProcessamento++;

                comprimirImagem(file).then(dataUrl => {
                    marcador.className = 'foto-thumb nova';
                    marcador.innerHTML = `
                        <img src="${dataUrl}" alt="Nova foto" title="Por gravar" onclick="window.open(this.src, '_blank')">
                        <button type="button" class="btn-rm-foto" title="Remover" onclick="this.closest('.foto-thumb').remove()"><i class="fa-solid fa-xmark"></i></button>
                        <input type="hidden" name="fotos_novas_${uid}[]">`;
                    marcador.querySelector('input').value = dataUrl;
                }).catch(() => {
                    marcador.remove();
                    alert('Não foi possível ler uma das imagens selecionadas.');
                }).finally(() => { fotosEmProcessamento--; });
            });
        }

        // Remove uma fotografia já gravada (só é apagada do servidor ao gravar a vistoria)
        function removerFotoExistente(btn) {
            if (!confirm('Remover esta fotografia?')) return;
            const thumb = btn.closest('.foto-thumb');
            const td = btn.closest('td');
            const hidden = td.querySelector('.fotos-existentes');
            let lista = [];
            try { lista = JSON.parse(hidden.value || '[]'); } catch (e) { lista = []; }
            lista = lista.filter(f => f !== thumb.dataset.ficheiro);
            hidden.value = JSON.stringify(lista);
            thumb.remove();
        }

        // Não deixa gravar enquanto houver fotografias a ser preparadas
        document.getElementById('formVistoria').addEventListener('submit', function(e) {
            if (fotosEmProcessamento > 0) {
                e.preventDefault();
                alert('Aguarde um momento: ainda há fotografias a ser preparadas.');
                return;
            }
            const btn = document.getElementById('btnGravar');
            btn.disabled = true;
            btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> A GRAVAR...';
        });

        document.addEventListener('DOMContentLoaded', () => {
            const linhasData = <?= json_encode($linhas_vistoria, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?: '[]' ?>;
            if (linhasData && linhasData.length > 0) {
                linhasData.forEach(item => addRow(item));
            } else {
                addRow(); // Uma linha vazia por defeito
            }
        });
    </script>
</body>
</html>