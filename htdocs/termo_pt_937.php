<?php
/**
 * termo_pt_937.php?id=XX
 * Relatório-tipo INCM — Modelo 937
 *   1) Carregar o impresso comprado à INCM (PDF com código de barras) — o browser converte cada página
 *      numa imagem de alta resolução e envia-a para a pasta imagens/ (o PDF original também fica guardado).
 *   2) Rever os campos (vêm do termo_pt_form.php; o que alterares aqui fica guardado só para o Modelo 937).
 *   3) Imprimir: o impresso fica de fundo e os dados são escritos por cima (termo_pt_937_pdf.php).
 */
ini_set('display_errors', 1);
error_reporting(E_ALL);

session_start();
require_once 'db.php';
require_once 'termo_pt_937_lib.php';

if (!isset($_SESSION['user_id'])) { header("Location: index.php"); exit; }

if (file_exists('functions.php')) { require_once 'functions.php'; }
if (!function_exists('registrarLog')) { function registrarLog($pdo, $acao, $modulo) { return true; } }
if (!function_exists('gerar_csrf_token')) { function gerar_csrf_token() { return ''; } }
if (!function_exists('validar_csrf_token')) { function validar_csrf_token($t) { return true; } }

$perfil_user = $_SESSION['perfil'] ?? '';
$is_admin = in_array($perfil_user, ['admin', 'administrativo']);
$stmt_perm = $pdo->prepare("SELECT modulo FROM permissoes WHERE grupo = :perfil AND pode_aceder = 1");
$stmt_perm->execute(['perfil' => $perfil_user]);
$meus_acessos = $stmt_perm->fetchAll(PDO::FETCH_COLUMN);
if (!$is_admin && !in_array('termos', $meus_acessos) && !in_array('termos_pt', $meus_acessos)) {
    header("Location: index.php?erro=sem_permissao"); exit;
}

$nome_completo = $_SESSION['nome'] ?? 'Utilizador';
$primeiro_nome = explode(' ', trim($nome_completo))[0];
$csrf_token = gerar_csrf_token();

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$id) { header("Location: termos_pt.php"); exit; }

m937_migrar($pdo);
$t = m937_carregar_termo($pdo, $id);
if (!$t) { header("Location: termos_pt.php"); exit; }

$m937   = json_decode((string)($t['modelo937_json'] ?? ''), true) ?: [];
$ultimo = m937_ultimo($pdo, $id);

function m937_gravar($pdo, $id, array $m937) {
    $st = $pdo->prepare("UPDATE termos_pt SET modelo937_json = ? WHERE id = ?");
    return $st->execute([json_encode($m937, JSON_UNESCAPED_UNICODE), $id]);
}
function responder_json($arr) { header('Content-Type: application/json; charset=utf-8'); echo json_encode($arr, JSON_UNESCAPED_UNICODE); exit; }

// =====================================================================================
// POST
// =====================================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao = $_POST['acao'] ?? '';
    $ajax = !empty($_POST['ajax']);
    if (!validar_csrf_token($_POST['csrf_token'] ?? '')) {
        if ($ajax) responder_json(['ok' => false, 'erro' => 'Sessão expirada (CSRF). Recarrega a página.']);
        die("CSRF Inválido.");
    }

    // --- 1) Carregar o impresso INCM (páginas em JPG geradas no browser + PDF original) ---
    if ($acao === 'carregar') {
        if (!is_dir('imagens/')) @mkdir('imagens/', 0755, true);
        $base = 'm937_' . $id . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(3));
        $paginas = [];
        $fx = $_FILES['paginas'] ?? null;
        if (!$fx || !is_array($fx['name'])) responder_json(['ok' => false, 'erro' => 'Não chegaram páginas ao servidor.']);
        $n = count($fx['name']);
        if ($n < 1 || $n > 8) responder_json(['ok' => false, 'erro' => 'O impresso deve ter entre 1 e 8 páginas (o Modelo 937 tem 4).']);
        for ($i = 0; $i < $n; $i++) {
            if ($fx['error'][$i] !== UPLOAD_ERR_OK) responder_json(['ok' => false, 'erro' => 'Erro no envio da página ' . ($i + 1) . ' (código ' . $fx['error'][$i] . '). Pode ser o limite de tamanho do servidor.']);
            $info = @getimagesize($fx['tmp_name'][$i]);
            if (!$info || $info[2] !== IMAGETYPE_JPEG) responder_json(['ok' => false, 'erro' => 'A página ' . ($i + 1) . ' não é uma imagem JPG válida.']);
            $nome = $base . '_p' . ($i + 1) . '.jpg';
            if (!move_uploaded_file($fx['tmp_name'][$i], 'imagens/' . $nome)) responder_json(['ok' => false, 'erro' => 'Não foi possível gravar a página ' . ($i + 1) . ' na pasta imagens/.']);
            $paginas[] = $nome;
        }
        // PDF original (fica guardado para consulta / reimpressão)
        $original = '';
        if (!empty($_FILES['original']['tmp_name']) && $_FILES['original']['error'] === UPLOAD_ERR_OK) {
            $cab = (string)@file_get_contents($_FILES['original']['tmp_name'], false, null, 0, 5);
            if ($cab === '%PDF-') {
                $original = $base . '.pdf';
                if (!move_uploaded_file($_FILES['original']['tmp_name'], 'imagens/' . $original)) $original = '';
            }
        }
        // Nunca perder dados: o impresso anterior fica no histórico (os ficheiros não são apagados)
        if (!empty($m937['paginas'])) {
            $m937['historico'] = $m937['historico'] ?? [];
            $m937['historico'][] = [
                'paginas' => $m937['paginas'], 'original' => $m937['original'] ?? '',
                'nome_original' => $m937['nome_original'] ?? '', 'carregado_em' => $m937['carregado_em'] ?? '',
                'n_impresso' => $m937['campos']['n_impresso'] ?? '',
            ];
        }
        $m937['paginas'] = $paginas;
        $m937['original'] = $original;
        $m937['nome_original'] = mb_substr(trim((string)($_POST['nome_original'] ?? '')), 0, 200);
        $m937['carregado_em'] = date('Y-m-d H:i:s');
        $m937['carregado_por'] = $_SESSION['nome'] ?? '';
        $m937['campos'] = $m937['campos'] ?? [];
        $nimp = trim((string)($_POST['n_impresso'] ?? ''));
        $m937['campos']['n_impresso'] = $nimp;
        if (!isset($m937['opcoes'])) $m937['opcoes'] = ['cabecalho' => 0, 'dx' => 0, 'dy' => 0];
        m937_gravar($pdo, $id, $m937);
        registrarLog($pdo, "Carregou impresso INCM Modelo 937 no relatório PT (" . ($t['referencia_pt'] ?: $id) . ")", "Termos PT");
        responder_json(['ok' => true, 'paginas' => $paginas]);
    }

    // --- Assinatura do técnico (fica guardada para todos os Modelos 937) ---
    if ($acao === 'assinatura') {
        $f = $_FILES['assinatura'] ?? null;
        if (!$f || $f['error'] !== UPLOAD_ERR_OK) responder_json(['ok' => false, 'erro' => 'Não chegou a imagem da assinatura.']);
        $info = @getimagesize($f['tmp_name']);
        if (!$info || !in_array($info[2], [IMAGETYPE_PNG, IMAGETYPE_JPEG], true)) responder_json(['ok' => false, 'erro' => 'A assinatura tem de ser uma imagem PNG ou JPG.']);
        if (!is_dir('imagens/')) @mkdir('imagens/', 0755, true);
        $nome = 'assinatura_m937_' . date('Ymd_His') . '_' . bin2hex(random_bytes(3)) . '.png';
        // Recorta, suaviza e torna o papel transparente (mesma rotina do assina.png)
        $ok = m937_processar_assinatura($f['tmp_name'], 'imagens/' . $nome);
        if (!$ok) {   // sem GD: guarda tal como veio
            $nome = preg_replace('/\.png$/', $info[2] === IMAGETYPE_PNG ? '.png' : '.jpg', $nome);
            $ok = move_uploaded_file($f['tmp_name'], 'imagens/' . $nome);
        }
        if (!$ok) responder_json(['ok' => false, 'erro' => 'Não foi possível gravar a assinatura na pasta imagens/.']);
        m937_assinatura_set($pdo, $nome);   // a anterior continua na pasta imagens/ (não é apagada)
        registrarLog($pdo, "Atualizou a assinatura do técnico (Modelo 937)", "Termos PT");
        responder_json(['ok' => true, 'ficheiro' => $nome]);
    }

    // --- 2) Guardar campos / opções ---
    if ($acao === 'guardar' || $acao === 'repor') {
        $def = m937_defaults($t, $ultimo);
        $guardados = [];
        if ($acao === 'guardar') {
            $post = $_POST['campos'] ?? [];
            foreach ($def as $k => $v) {
                if (!array_key_exists($k, $post)) continue;
                $nv = str_replace("\r", '', trim((string)$post[$k]));
                // Só guarda o que é diferente do termo -> o resto continua a acompanhar o termo_pt_form.php
                if ($nv !== (string)$v || $k === 'n_impresso') $guardados[$k] = $nv;
            }
        } else {
            $guardados['n_impresso'] = $m937['campos']['n_impresso'] ?? '';
        }
        $m937['campos'] = $guardados;
        $num = function($v) { $v = str_replace(',', '.', trim((string)$v)); return is_numeric($v) ? max(-20, min(20, (float)$v)) : 0; };
        $m937['opcoes'] = [
            'cabecalho' => !empty($_POST['op_cabecalho']) ? 1 : 0,
            // Só desliga a assinatura se a caixa existia no formulário e foi desmarcada
            'assinar' => isset($_POST['op_assinatura_presente']) ? (!empty($_POST['op_assinatura']) ? 1 : 0) : 1,
            'dx' => $num($_POST['op_dx'] ?? 0),
            'dy' => $num($_POST['op_dy'] ?? 0),
        ];
        m937_gravar($pdo, $id, $m937);
        if ($ajax) responder_json(['ok' => true]);
        header("Location: termo_pt_937.php?id=$id&ok=" . ($acao === 'repor' ? 'reposto' : 'guardado')); exit;
    }
    if ($ajax) responder_json(['ok' => false, 'erro' => 'Ação desconhecida.']);
}

// =====================================================================================
// DADOS PARA O ECRÃ
// =====================================================================================
$campos = m937_campos($t, $m937, $ultimo);
$defs   = m937_defaults($t, $ultimo);
$opc    = $m937['opcoes'] ?? ['cabecalho' => 0, 'dx' => 0, 'dy' => 0];
$com_assinatura = !isset($opc['assinar']) || !empty($opc['assinar']);
$assinatura_atual = m937_assinatura_get($pdo);
$paginas_ok = [];
foreach (($m937['paginas'] ?? []) as $f) if (is_file('imagens/' . basename($f))) $paginas_ok[] = basename($f);
$tem_impresso = count($paginas_ok) > 0;
$itens = m937_itens(); $textos = m937_textos(); $caixas = m937_caixas();

$msg = '';
if (($_GET['ok'] ?? '') === 'guardado') $msg = 'Dados do Modelo 937 guardados.';
if (($_GET['ok'] ?? '') === 'reposto') $msg = 'Campos repostos com os dados do relatório.';

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function alterado($k) { global $campos, $defs; return isset($defs[$k]) && (string)$campos[$k] !== (string)$defs[$k] && $k !== 'n_impresso'; }

function campo_input($k, $label, $classe = '', $ph = '') {
    global $campos;
    $al = alterado($k) ? ' <span class="tag-alt" title="Alterado só no Modelo 937">editado</span>' : '';
    echo '<div class="form-group ' . $classe . '"><label>' . $label . $al . '</label>'
       . '<input type="text" name="campos[' . h($k) . ']" value="' . h($campos[$k] ?? '') . '" placeholder="' . h($ph) . '"></div>';
}
function campo_texto($k, $label, $linhas = 3) {
    global $campos;
    $al = alterado($k) ? ' <span class="tag-alt" title="Alterado só no Modelo 937">editado</span>' : '';
    echo '<div class="form-group full"><label>' . $label . $al . '</label>'
       . '<textarea name="campos[' . h($k) . ']" rows="' . (int)$linhas . '">' . h($campos[$k] ?? '') . '</textarea></div>';
}
function campo_caixa($k) {
    global $caixas;
    $c = $caixas[$k];
    campo_input($k, $c['n'] . ' – ' . h($c['txt']) . ($c['un'] ? ' (' . h($c['un']) . ')' : ''), 'medida');
}
function campo_item($k) {
    global $itens, $campos;
    $c = $itens[$k];
    $e = $campos['e_' . $k] ?? '';
    $al = (alterado('e_' . $k) || alterado('d_' . $k)) ? ' <span class="tag-alt">editado</span>' : '';
    echo '<div class="item937">'
       . '<div class="item937-tit"><strong>' . h($c['n']) . '</strong> ' . h($c['txt']) . $al . '</div>'
       . '<div class="item937-linha">'
       . '<select name="campos[e_' . h($k) . ']" onchange="mostrarDef(this)">'
       . '<option value=""' . ($e === '' ? ' selected' : '') . '>— Não preencher —</option>'
       . '<option value="nao"' . ($e === 'nao' ? ' selected' : '') . '>Sem deficiências  → «não … quaisquer»</option>'
       . '<option value="sim"' . ($e === 'sim' ? ' selected' : '') . '>Com deficiências  → «as seguintes»</option>'
       . '</select></div>'
       . '<textarea class="def937" name="campos[d_' . h($k) . ']" rows="2" placeholder="Descrever as deficiências detetadas" style="' . ($e === 'sim' ? '' : 'display:none;') . '">' . h($campos['d_' . $k] ?? '') . '</textarea>'
       . '</div>';
}
?>
<!DOCTYPE html>
<html lang="pt-PT">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Modelo 937 INCM - MAB</title>
    <link rel="icon" type="image/png" href="mab.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { font-family: system-ui, sans-serif; background: #f7f9fc; margin: 0; color: #333; font-size: 13px; padding-bottom: 110px; }
        * { box-sizing: border-box; }
        .topbar { background: #fff; padding: 10px 40px; box-shadow: 0 2px 15px rgba(0,0,0,0.05); display: flex; justify-content: space-between; align-items: center; position: sticky; top: 0; z-index: 100; border-bottom: 3px solid #34495e; }
        .topbar-left .logo { height: 38px; display: block; }
        .topbar-right { display: flex; align-items: center; gap: 18px; }
        .btn-voltar { width: 40px; height: 40px; border-radius: 50%; background: #fff; border: 1px solid #e8ecf1; color: #576574; display: inline-flex; align-items: center; justify-content: center; text-decoration: none; font-size: 16px; transition: all 0.2s ease; box-shadow: 0 2px 6px rgba(0,0,0,0.04); }
        .btn-voltar:hover { background: #eef2f6; color: #34495e; transform: translateX(-2px); }
        .user-menu { position: relative; }
        .user-btn { background: none; border: none; font-size: 14px; font-weight: 600; color: #2c3e50; cursor: pointer; display: flex; align-items: center; gap: 8px; font-family: inherit; padding: 4px; }
        .user-avatar { background: #e74c3c; color: #fff; width: 28px; height: 28px; border-radius: 50%; display: flex; justify-content: center; align-items: center; font-size: 13px; border: 2px solid #fadbd8; }
        .dropdown-content { display: none; position: absolute; right: 0; top: 100%; margin-top: 8px; background: #fff; min-width: 180px; box-shadow: 0 10px 30px rgba(0,0,0,0.1); border-radius: 8px; overflow: hidden; border: 1px solid #f1f2f6; z-index: 200; }
        .user-menu.aberto .dropdown-content { display: block; }
        .dropdown-content a { color: #576574; padding: 12px 20px; text-decoration: none; display: block; font-size: 14px; }
        .dropdown-content a:hover { background: #fdf2f2; color: #b32a2a; }

        .container { max-width: 1000px; margin: 25px auto; padding: 0 20px; }
        h1 { color: #2c3e50; font-weight: 800; font-size: 21px; margin: 0 0 6px; display: flex; align-items: center; gap: 10px; }
        .sub { color: #7f8c8d; margin: 0 0 20px; }
        .card { background: #fff; padding: 22px; border-radius: 12px; border: 1px solid #e1e5ea; margin-bottom: 20px; box-shadow: 0 4px 15px rgba(0,0,0,0.02); }
        .card h2 { font-size: 13px; color: #34495e; border-bottom: 1px solid #f1f2f6; padding-bottom: 10px; text-transform: uppercase; margin: 0 0 16px; display: flex; align-items: center; gap: 8px; }
        .card h3 { font-size: 12px; color: #4a69bd; text-transform: uppercase; margin: 20px 0 10px; }
        .form-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 14px; }
        .form-group { display: flex; flex-direction: column; gap: 5px; }
        .form-group.full { grid-column: 1 / -1; }
        label { font-weight: 700; font-size: 11px; color: #576574; text-transform: uppercase; }
        input, select, textarea { padding: 10px; border: 1px solid #dcdde1; border-radius: 8px; font-size: 13px; outline: none; background: #fff; font-family: inherit; width: 100%; }
        textarea { resize: vertical; }
        input:focus, select:focus, textarea:focus { border-color: #4a69bd; box-shadow: 0 0 0 3px rgba(74,105,189,0.1); }
        .tag-alt { background: #fef5e7; color: #d35400; border-radius: 10px; padding: 1px 7px; font-size: 9px; font-weight: 800; text-transform: uppercase; margin-left: 4px; }

        .alert { padding: 13px 15px; border-radius: 8px; margin-bottom: 18px; font-weight: 600; }
        .alert-ok { background: #eafaf1; color: #27ae60; border: 1px solid #d5f5e3; }
        .alert-info { background: #eef4fb; color: #2c5d8f; border: 1px solid #d6e6f5; font-weight: 500; line-height: 1.5; }
        .alert-erro { background: #fdeded; color: #c0392b; border: 1px solid #f9cccc; }

        .zona-upload { display: block; text-transform: none; font-size: 13px; font-weight: 500; border: 2px dashed #b8c4d6; border-radius: 12px; padding: 22px; text-align: center; background: #fafbfd; cursor: pointer; transition: .2s; }
        .zona-upload:hover, .zona-upload.drag { border-color: #4a69bd; background: #f0f4fb; }
        .zona-upload i { font-size: 30px; color: #4a69bd; }
        .zona-upload p { margin: 8px 0 0; color: #576574; }
        .progresso { display: none; margin-top: 14px; }
        .barra { height: 10px; background: #eef1f5; border-radius: 10px; overflow: hidden; }
        .barra div { height: 100%; width: 0; background: #4a69bd; transition: width .2s; }
        .progresso small { display: block; margin-top: 6px; color: #576574; }
        .paginas { display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px; margin-top: 16px; }
        .paginas a { display: block; border: 1px solid #e1e5ea; border-radius: 8px; overflow: hidden; background: #fff; position: relative; }
        .paginas img { width: 100%; display: block; aspect-ratio: 595 / 842; object-fit: cover; }
        .paginas span { position: absolute; left: 6px; bottom: 6px; background: rgba(52,73,94,.9); color: #fff; font-size: 10px; font-weight: 800; padding: 2px 8px; border-radius: 10px; }
        .estado-impresso { display: flex; flex-wrap: wrap; gap: 10px 22px; align-items: center; margin-bottom: 14px; color: #2c3e50; }
        .estado-impresso .ok { color: #27ae60; font-weight: 700; }
        .estado-impresso .falta { color: #d35400; font-weight: 700; }
        .lnk { color: #4a69bd; font-weight: 700; text-decoration: none; }

        .item937 { border: 1px solid #edf0f4; border-radius: 10px; padding: 12px; margin-bottom: 10px; background: #fcfdfe; }
        .item937-tit { margin-bottom: 8px; color: #2c3e50; }
        .item937-linha select { max-width: 380px; }
        .def937 { margin-top: 8px; }

        .assin-zona { display: flex; gap: 16px; align-items: center; flex-wrap: wrap; margin-top: 12px; }
        .assin-prev { width: 260px; max-width: 100%; height: 90px; border: 1px dashed #b8c4d6; border-radius: 10px; display: flex; align-items: center; justify-content: center; background: repeating-linear-gradient(45deg, #fafbfd, #fafbfd 8px, #f2f4f8 8px, #f2f4f8 16px); overflow: hidden; }
        .assin-prev img { max-width: 92%; max-height: 80px; }
        .assin-prev span { color: #95a5a6; font-size: 12px; }
        .chk { display: flex; gap: 10px; align-items: flex-start; font-weight: 600; text-transform: none; font-size: 13px; color: #2c3e50; cursor: pointer; }
        .chk input { width: auto; margin-top: 2px; }

        .floating-action { position: fixed; bottom: 20px; right: 20px; z-index: 100; display: flex; gap: 10px; flex-wrap: wrap; justify-content: flex-end; }
        .btn { border: none; border-radius: 10px; padding: 14px 22px; font-weight: 700; cursor: pointer; font-family: inherit; font-size: 13px; display: inline-flex; align-items: center; gap: 8px; box-shadow: 0 5px 15px rgba(0,0,0,0.12); text-decoration: none; }
        .btn-guardar { background: #4a69bd; color: #fff; }
        .btn-imprimir { background: #27ae60; color: #fff; }
        .btn-dados { background: #fff; color: #34495e; border: 1px solid #dcdde1; }
        .btn-sec { background: #f1f2f6; color: #576574; box-shadow: none; padding: 10px 16px; }
        .btn:disabled { opacity: .6; cursor: wait; }

        @media (max-width: 768px) {
            .topbar { padding: 10px 15px; }
            .container { margin: 18px auto; padding: 0 12px; }
            .card { padding: 16px; }
            .paginas { grid-template-columns: repeat(2, 1fr); }
            .floating-action { left: 12px; right: 12px; bottom: 12px; }
            .floating-action .btn { flex: 1; justify-content: center; padding: 13px 10px; }
        }
    </style>
</head>
<body>
    <header class="topbar">
        <div class="topbar-left"><a href="index.php" title="Página inicial"><img src="mab.png" class="logo" alt="MAB"></a></div>
        <div class="topbar-right">
            <a href="termos_pt.php" class="btn-voltar" title="Voltar" aria-label="Voltar"><i class="fa-solid fa-arrow-left"></i></a>
            <div class="user-menu" id="userMenu">
                <button type="button" class="user-btn" onclick="event.stopPropagation(); document.getElementById('userMenu').classList.toggle('aberto');">
                    <div class="user-avatar"><i class="fa-solid fa-user"></i></div>
                    <?= h($primeiro_nome) ?> <i class="fa-solid fa-chevron-down" style="font-size:10px;"></i>
                </button>
                <div class="dropdown-content">
                    <a href="perfil.php"><i class="fa-solid fa-id-badge"></i> O meu perfil</a>
                    <a href="index.php?logout=1" style="color:#e74c3c;"><i class="fa-solid fa-right-from-bracket"></i> Sair</a>
                </div>
            </div>
        </div>
    </header>

    <div class="container">
        <h1><i class="fa-solid fa-file-contract" style="color:#d35400;"></i> Relatório-tipo INCM · Modelo 937</h1>
        <p class="sub">
            PT <strong><?= h($t['referencia_pt'] ?: '—') ?></strong>
            · <?= h($t['_cliente']['nome'] ?? '') ?>
            · Inspeção <?= h(m937_dmy($t['data_inspecao'] ?? '')) ?>
            · <a class="lnk" href="termo_pt_form.php?id=<?= (int)$id ?>"><i class="fa-solid fa-pen-to-square"></i> Editar relatório</a>
        </p>

        <?php if ($msg): ?><div class="alert alert-ok"><i class="fa-solid fa-check"></i> <?= h($msg) ?></div><?php endif; ?>
        <div id="msgJs"></div>

        <!-- ============ 1. IMPRESSO INCM ============ -->
        <div class="card">
            <h2><i class="fa-solid fa-barcode"></i> 1. Impresso comprado à INCM</h2>
            <div class="estado-impresso">
                <?php if ($tem_impresso): ?>
                    <span class="ok"><i class="fa-solid fa-circle-check"></i> Impresso carregado (<?= count($paginas_ok) ?> págs.)</span>
                    <?php if (!empty($m937['nome_original'])): ?><span><i class="fa-regular fa-file-pdf"></i> <?= h($m937['nome_original']) ?></span><?php endif; ?>
                    <span><i class="fa-regular fa-clock"></i> <?= h(date('d/m/Y H:i', strtotime($m937['carregado_em'] ?? 'now'))) ?></span>
                    <?php if (!empty($m937['original']) && is_file('imagens/' . basename($m937['original']))): ?>
                        <a class="lnk" href="imagens/<?= h(rawurlencode(basename($m937['original']))) ?>" target="_blank"><i class="fa-solid fa-up-right-from-square"></i> Ver PDF original</a>
                    <?php endif; ?>
                <?php else: ?>
                    <span class="falta"><i class="fa-solid fa-triangle-exclamation"></i> Ainda não carregaste o impresso deste relatório.</span>
                <?php endif; ?>
            </div>

            <div class="form-grid" style="margin-bottom:14px;">
                <div class="form-group">
                    <label>N.º do impresso (código de barras)</label>
                    <input type="text" id="n_impresso" form="form937" name="campos[n_impresso]" value="<?= h($campos['n_impresso'] ?? '') ?>" placeholder="ex.: 0000031089">
                </div>
            </div>

            <label class="zona-upload" id="zonaUpload" for="ficheiro937">
                <i class="fa-solid fa-cloud-arrow-up"></i>
                <p><strong><?= $tem_impresso ? 'Substituir o impresso' : 'Carregar o impresso INCM' ?></strong><br>PDF descarregado da INCM (ou fotografias/digitalizações das 4 páginas, por ordem)</p>
            </label>
            <input type="file" id="ficheiro937" accept="application/pdf,.pdf,image/jpeg,image/png" multiple style="display:none;">
            <div class="progresso" id="progresso"><div class="barra"><div id="barra"></div></div><small id="progTxt">A preparar…</small></div>

            <?php if ($tem_impresso): ?>
                <div class="paginas">
                    <?php foreach ($paginas_ok as $i => $f): ?>
                        <a href="imagens/<?= h(rawurlencode($f)) ?>" target="_blank"><img src="imagens/<?= h(rawurlencode($f)) ?>" alt="Página <?= $i + 1 ?>" loading="lazy"><span>Pág. <?= $i + 1 ?></span></a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            <?php if (!empty($m937['historico'])): ?>
                <p style="margin:12px 0 0; color:#95a5a6; font-size:12px;"><i class="fa-solid fa-box-archive"></i> <?= count($m937['historico']) ?> impresso(s) anterior(es) guardado(s) no histórico (não foram apagados).</p>
            <?php endif; ?>
        </div>

        <form method="POST" id="form937">
            <input type="hidden" name="csrf_token" value="<?= h($csrf_token) ?>">
            <input type="hidden" name="acao" value="guardar" id="acao937">

            <!-- ============ 2. OPÇÕES ============ -->
            <div class="card">
                <h2><i class="fa-solid fa-sliders"></i> 2. Opções de impressão</h2>
                <label class="chk"><input type="checkbox" name="op_cabecalho" value="1" <?= !empty($opc['cabecalho']) ? 'checked' : '' ?> onchange="document.getElementById('blocoCab').style.display=this.checked?'':'none'">
                    <span>Preencher também o cabeçalho (condição, período, referências (1) a (4) e n.º de inscrição).<br>
                    <small style="color:#7f8c8d; font-weight:500;">Deixa desligado quando o impresso comprado à INCM já traz estes dados escritos — evita escrever por cima.</small></span></label>
                <div class="form-grid" style="margin-top:14px;">
                    <div class="form-group"><label>Afinar posição horizontal (mm)</label><input type="text" name="op_dx" value="<?= h($opc['dx'] ?? 0) ?>" inputmode="decimal" placeholder="0"></div>
                    <div class="form-group"><label>Afinar posição vertical (mm)</label><input type="text" name="op_dy" value="<?= h($opc['dy'] ?? 0) ?>" inputmode="decimal" placeholder="0"></div>
                </div>
                <h3><i class="fa-solid fa-signature"></i> Assinatura do técnico responsável</h3>
                <input type="hidden" name="op_assinatura_presente" value="1">
                <label class="chk"><input type="checkbox" name="op_assinatura" value="1" <?= $com_assinatura ? 'checked' : '' ?>>
                    <span>Colocar a minha assinatura na linha «O Técnico Responsável» (página 4 e anexos)</span></label>
                <div class="assin-zona">
                    <div class="assin-prev" id="assinPrev">
                        <?php if ($assinatura_atual): ?>
                            <img src="<?= h($assinatura_atual) ?>?v=<?= @filemtime($assinatura_atual) ?>" alt="Assinatura">
                        <?php else: ?>
                            <span>Ainda sem assinatura</span>
                        <?php endif; ?>
                    </div>
                    <div>
                        <label class="btn btn-sec" for="fichAssin" style="cursor:pointer;"><i class="fa-solid fa-upload"></i> <?= $assinatura_atual ? 'Substituir assinatura' : 'Carregar assinatura' ?></label>
                        <input type="file" id="fichAssin" accept="image/png,image/jpeg" style="display:none;">
                        <p style="color:#7f8c8d; font-size:12px; margin:8px 0 0;">Fotografia ou digitalização da assinatura em papel branco (PNG ou JPG). As margens são recortadas e o fundo branco fica transparente. Fica guardada para todos os Modelos 937.</p>
                    </div>
                </div>
                <p style="color:#7f8c8d; font-size:12px; margin:14px 0 0;">A afinação só é precisa para imprimir «só dados» por cima do impresso em papel (valores positivos: para a direita / para baixo).</p>
            </div>

            <!-- ============ 3. CAMPOS ============ -->
            <div class="card">
                <h2><i class="fa-solid fa-list-check"></i> 3. Dados a escrever no impresso</h2>
                <div class="alert alert-info"><i class="fa-solid fa-circle-info"></i> Os campos vêm do relatório do PT. Se alterares algum aqui, a alteração fica só no Modelo 937 (marcada como <span class="tag-alt">editado</span>); os restantes continuam a acompanhar o relatório.</div>

                <div id="blocoCab" style="<?= !empty($opc['cabecalho']) ? '' : 'display:none;' ?>">
                    <h3>Cabeçalho e referências</h3>
                    <div class="form-grid">
                        <div class="form-group"><label>Condição da instalação</label>
                            <select name="campos[cond]">
                                <option value="boas" <?= $campos['cond'] === 'boas' ? 'selected' : '' ?>>Boas condições de segurança</option>
                                <option value="deficientes" <?= $campos['cond'] === 'deficientes' ? 'selected' : '' ?>>Condições deficientes</option>
                                <option value="desistencia" <?= $campos['cond'] === 'desistencia' ? 'selected' : '' ?>>Desistência da responsabilidade</option>
                            </select></div>
                        <?php campo_input('periodo_ini', 'Período – início', '', 'dd/mm/aaaa'); ?>
                        <?php campo_input('periodo_fim', 'Período – fim', '', 'dd/mm/aaaa'); ?>
                        <?php campo_input('inscricao', 'N.º inscrição DGEG'); ?>
                        <?php campo_texto('ref1', '(1) Entidade a quem é enviado / referência do processo', 1); ?>
                        <?php campo_texto('ref2', '(2) Entidade e localização da instalação', 2); ?>
                        <?php campo_texto('ref3', '(3) Descrição sumária da instalação', 2); ?>
                        <?php campo_texto('ref4', '(4) Nome e morada do técnico responsável', 2); ?>
                    </div>
                </div>

                <h3>Inspeções efetuadas</h3>
                <div class="form-grid">
                    <?php campo_input('dl', '(5) Disposição legal'); ?>
                    <?php campo_input('dias', 'Inspecionei a instalação nos dias', '', 'ex.: 7 de fevereiro de 2025'); ?>
                </div>

                <h3>1.1 – PT: Ensaios e medições</h3>
                <div class="form-grid">
                    <?php campo_caixa('m_1_1_1'); campo_caixa('m_1_1_2'); campo_caixa('m_1_1_3'); campo_caixa('m_1_1_5'); ?>
                    <?php campo_texto('m_1_1_4', '1.1.4 – ' . h($textos['m_1_1_4']['txt']), 2); ?>
                    <?php campo_texto('m_1_1_6', '1.1.6 – ' . h($textos['m_1_1_6']['txt']), 2); ?>
                </div>

                <h3>1.2 – PT: Verificações</h3>
                <?php foreach (['1_2_1','1_2_2','1_2_3','1_2_4','1_2_5','1_2_6'] as $k) campo_item($k); ?>
                <div class="form-grid"><?php campo_texto('obs_1_2_7', '1.2.7 – Outras verificações', 3); ?></div>

                <h3>2 – Instalações de utilização</h3>
                <div class="form-grid">
                    <div class="form-group"><label>Sistema de proteção de pessoas</label>
                        <select name="campos[sistema]">
                            <option value="" <?= $campos['sistema'] === '' ? 'selected' : '' ?>>— Não assinalar —</option>
                            <?php foreach (['TT','TN','IT'] as $s): ?><option value="<?= $s ?>" <?= $campos['sistema'] === $s ? 'selected' : '' ?>><?= $s ?></option><?php endforeach; ?>
                        </select></div>
                    <?php campo_caixa('m_2_1_1'); campo_caixa('m_2_1_2'); campo_caixa('m_2_1_3'); ?>
                    <?php campo_texto('m_2_1_4', '2.1.4 – ' . h($textos['m_2_1_4']['txt']), 2); ?>
                    <?php campo_texto('m_2_1_5', '2.1.5 – ' . h($textos['m_2_1_5']['txt']), 2); ?>
                </div>

                <h3>2.2 – Verificações</h3>
                <?php foreach (['2_2_1','2_2_2','2_2_3','2_2_4','2_2_5','2_2_6_1','2_2_6_2','2_2_6_3'] as $k) campo_item($k); ?>
                <div class="form-grid">
                    <?php foreach (['obs_2_2_7','obs_2_2_8','obs_2_2_9','obs_2_2_10','obs_2_2_11','obs_2_2_12','obs_2_2_13'] as $k) campo_texto($k, $textos[$k]['n'] . ' – ' . h($textos[$k]['txt']), 2); ?>
                </div>

                <h3>3, 4 e 5</h3>
                <div class="form-grid">
                    <?php campo_texto('obs_3', '3 – Outras instalações', 3); ?>
                    <?php campo_texto('obs_4', '4 – Modificações e ampliações para as quais não fui consultado', 3); ?>
                    <?php campo_texto('obs_5', '5 – Relações com o proprietário', 3); ?>
                </div>

                <h3>Fecho</h3>
                <div class="form-grid">
                    <?php campo_input('anexos', 'Anexos (exemplares)', '', 'vazio = 0 / automático'); ?>
                    <?php campo_input('data_dia', 'Data – dia'); ?>
                    <?php campo_input('data_mes', 'Data – mês'); ?>
                    <?php campo_input('data_ano', 'Data – ano'); ?>
                </div>
                <p style="color:#7f8c8d; font-size:12px; margin:12px 0 0;">Se algum texto não couber nas linhas do impresso, o resto vai automaticamente para uma página de anexo (nota final 2 do Modelo 937) e o n.º de anexos é somado.</p>

                <div style="margin-top:18px;">
                    <button type="button" class="btn btn-sec" onclick="reporCampos()"><i class="fa-solid fa-rotate-left"></i> Repor todos os campos a partir do relatório</button>
                </div>
            </div>
        </form>
    </div>

    <div class="floating-action">
        <button type="button" class="btn btn-guardar" id="btnGuardar" onclick="guardar(null)"><i class="fa-solid fa-floppy-disk"></i> Guardar</button>
        <button type="button" class="btn btn-dados" onclick="guardar('0')" title="Só os dados, para imprimir por cima do impresso em papel"><i class="fa-solid fa-align-left"></i> Só dados</button>
        <button type="button" class="btn btn-imprimir" onclick="guardar('1')" <?= $tem_impresso ? '' : 'title="Carrega primeiro o impresso INCM"' ?>><i class="fa-solid fa-print"></i> Imprimir Modelo 937</button>
    </div>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js"></script>
    <script>
    const ID_TERMO = <?= (int)$id ?>;
    const CSRF = <?= json_encode($csrf_token) ?>;
    const TEM_IMPRESSO = <?= $tem_impresso ? 'true' : 'false' ?>;
    const DPI = 200;                 // resolução das páginas (o código de barras fica nítido)
    const A4 = [1654, 2339];         // A4 a 200 dpi (px)

    if (window.pdfjsLib) pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';

    document.addEventListener('click', () => document.getElementById('userMenu').classList.remove('aberto'));

    function aviso(txt, tipo) {
        document.getElementById('msgJs').innerHTML = txt ? '<div class="alert alert-' + (tipo || 'erro') + '">' + txt + '</div>' : '';
        if (txt) window.scrollTo({ top: 0, behavior: 'smooth' });
    }
    function mostrarDef(sel) {
        const ta = sel.closest('.item937').querySelector('.def937');
        ta.style.display = sel.value === 'sim' ? '' : 'none';
        if (sel.value === 'sim') ta.focus();
    }
    function progresso(p, txt) {
        document.getElementById('progresso').style.display = 'block';
        document.getElementById('barra').style.width = Math.round(p) + '%';
        document.getElementById('progTxt').textContent = txt;
    }

    // ---------- Upload ----------
    const zona = document.getElementById('zonaUpload');
    const inp = document.getElementById('ficheiro937');
    inp.addEventListener('change', () => { if (inp.files.length) processar(Array.from(inp.files)); inp.value = ''; });
    ['dragover', 'dragenter'].forEach(ev => zona.addEventListener(ev, e => { e.preventDefault(); zona.classList.add('drag'); }));
    ['dragleave', 'drop'].forEach(ev => zona.addEventListener(ev, e => { e.preventDefault(); zona.classList.remove('drag'); }));
    zona.addEventListener('drop', e => { if (e.dataTransfer.files.length) processar(Array.from(e.dataTransfer.files)); });

    function canvasParaBlob(cv) { return new Promise(r => cv.toBlob(b => r(b), 'image/jpeg', 0.88)); }

    // Coloca a imagem numa folha A4 branca (mantém proporções)
    function paraA4(origem, w, h) {
        const cv = document.createElement('canvas');
        cv.width = A4[0]; cv.height = A4[1];
        const cx = cv.getContext('2d');
        cx.fillStyle = '#fff'; cx.fillRect(0, 0, cv.width, cv.height);
        const f = Math.min(cv.width / w, cv.height / h);
        const nw = w * f, nh = h * f;
        cx.drawImage(origem, (cv.width - nw) / 2, (cv.height - nh) / 2, nw, nh);
        return cv;
    }

    async function processar(ficheiros) {
        aviso('');
        if (TEM_IMPRESSO && !confirm('Este relatório já tem um impresso carregado. Substituir? (o anterior fica guardado no histórico)')) return;
        const pdfs = ficheiros.filter(f => f.type === 'application/pdf' || /\.pdf$/i.test(f.name));
        const imgs = ficheiros.filter(f => /^image\//.test(f.type)).sort((a, b) => a.name.localeCompare(b.name, 'pt', { numeric: true }));
        const blobs = [];
        let original = null, nomeOriginal = '';
        try {
            if (pdfs.length) {
                if (!window.pdfjsLib) throw new Error('Não foi possível carregar o leitor de PDF (pdf.js). Verifica a ligação à internet.');
                original = pdfs[0]; nomeOriginal = original.name;
                progresso(3, 'A abrir o PDF…');
                const doc = await pdfjsLib.getDocument({ data: await original.arrayBuffer() }).promise;
                const n = Math.min(doc.numPages, 8);
                for (let i = 1; i <= n; i++) {
                    progresso(5 + (i - 1) / n * 60, 'A converter a página ' + i + ' de ' + n + '…');
                    const pg = await doc.getPage(i);
                    const vp = pg.getViewport({ scale: DPI / 72 });
                    const cv = document.createElement('canvas');
                    cv.width = Math.round(vp.width); cv.height = Math.round(vp.height);
                    const cx = cv.getContext('2d');
                    cx.fillStyle = '#fff'; cx.fillRect(0, 0, cv.width, cv.height);
                    await pg.render({ canvasContext: cx, viewport: vp }).promise;
                    const final = (Math.abs(cv.width / cv.height - A4[0] / A4[1]) < 0.01) ? cv : paraA4(cv, cv.width, cv.height);
                    blobs.push(await canvasParaBlob(final));
                }
                if (doc.numPages !== 4) aviso('Atenção: o PDF tem ' + doc.numPages + ' páginas (o Modelo 937 tem 4). Confirma se é o ficheiro certo.', 'info');
            } else if (imgs.length) {
                nomeOriginal = imgs.map(f => f.name).join(', ');
                for (let i = 0; i < Math.min(imgs.length, 8); i++) {
                    progresso(5 + i / imgs.length * 60, 'A preparar a imagem ' + (i + 1) + '…');
                    const bmp = await createImageBitmap(imgs[i], { imageOrientation: 'from-image' });
                    blobs.push(await canvasParaBlob(paraA4(bmp, bmp.width, bmp.height)));
                }
            } else {
                throw new Error('Escolhe o PDF da INCM (ou imagens JPG/PNG das páginas).');
            }

            // N.º do impresso: tenta tirar do nome do ficheiro (ex.: "...mod_937-_0000031089.pdf")
            const campoN = document.getElementById('n_impresso');
            const m = nomeOriginal.match(/(\d{10})/);
            if (m && !campoN.value) campoN.value = m[1];

            const fd = new FormData();
            fd.append('acao', 'carregar'); fd.append('ajax', '1'); fd.append('csrf_token', CSRF);
            fd.append('nome_original', nomeOriginal);
            fd.append('n_impresso', campoN.value);
            blobs.forEach((b, i) => fd.append('paginas[]', b, 'pagina' + (i + 1) + '.jpg'));
            if (original) fd.append('original', original, original.name);

            await new Promise((resolve, reject) => {
                const xhr = new XMLHttpRequest();
                xhr.open('POST', 'termo_pt_937.php?id=' + ID_TERMO);
                xhr.upload.onprogress = e => { if (e.lengthComputable) progresso(65 + e.loaded / e.total * 33, 'A enviar para o servidor… ' + Math.round(e.loaded / e.total * 100) + '%'); };
                xhr.onload = () => {
                    let r = null; try { r = JSON.parse(xhr.responseText); } catch (e) {}
                    if (r && r.ok) resolve(); else reject(new Error(r && r.erro ? r.erro : 'Resposta inesperada do servidor (' + xhr.status + ').'));
                };
                xhr.onerror = () => reject(new Error('Falha de ligação ao enviar.'));
                xhr.send(fd);
            });
            progresso(100, 'Impresso carregado!');
            location.href = 'termo_pt_937.php?id=' + ID_TERMO + '&ok=guardado';
        } catch (e) {
            document.getElementById('progresso').style.display = 'none';
            aviso('<i class="fa-solid fa-triangle-exclamation"></i> ' + e.message);
        }
    }

    // ---------- Assinatura ----------
    document.getElementById('fichAssin').addEventListener('change', async function () {
        const f = this.files[0]; this.value = '';
        if (!f) return;
        aviso('');
        try {
            // Reduz no browser (fotos do telemóvel são enormes) mantendo PNG/JPG
            const bmp = await createImageBitmap(f, { imageOrientation: 'from-image' });
            const esc = Math.min(1, 1600 / Math.max(bmp.width, bmp.height));
            const cv = document.createElement('canvas');
            cv.width = Math.round(bmp.width * esc); cv.height = Math.round(bmp.height * esc);
            const cx = cv.getContext('2d'); cx.fillStyle = '#fff'; cx.fillRect(0, 0, cv.width, cv.height);
            cx.drawImage(bmp, 0, 0, cv.width, cv.height);
            const blob = await new Promise(r => cv.toBlob(r, 'image/png'));
            const fd = new FormData();
            fd.append('acao', 'assinatura'); fd.append('ajax', '1'); fd.append('csrf_token', CSRF);
            fd.append('assinatura', blob, 'assinatura.png');
            const r = await fetch('termo_pt_937.php?id=' + ID_TERMO, { method: 'POST', body: fd }).then(x => x.json());
            if (!r.ok) throw new Error(r.erro || 'Erro ao gravar a assinatura.');
            document.getElementById('assinPrev').innerHTML = '<img src="imagens/' + encodeURIComponent(r.ficheiro) + '?v=' + Date.now() + '" alt="Assinatura">';
            aviso('<i class="fa-solid fa-check"></i> Assinatura guardada.', 'ok');
        } catch (e) {
            aviso('<i class="fa-solid fa-triangle-exclamation"></i> ' + e.message);
        }
    });

    // ---------- Guardar / imprimir ----------
    async function guardar(imprimir) {
        if (imprimir === '1' && !TEM_IMPRESSO) { aviso('Carrega primeiro o impresso INCM (ponto 1). Se quiseres imprimir por cima do impresso em papel, usa «Só dados».', 'info'); return; }
        const janela = imprimir !== null ? window.open('', '_blank') : null;   // abrir já (evita bloqueio de pop-ups)
        const btn = document.getElementById('btnGuardar'); btn.disabled = true;
        const fd = new FormData(document.getElementById('form937'));
        fd.set('acao', 'guardar'); fd.set('ajax', '1');
        fd.set('campos[n_impresso]', document.getElementById('n_impresso').value);
        try {
            const r = await fetch('termo_pt_937.php?id=' + ID_TERMO, { method: 'POST', body: fd }).then(x => x.json());
            if (!r.ok) throw new Error(r.erro || 'Erro ao guardar.');
            if (janela) janela.location = 'termo_pt_937_pdf.php?id=' + ID_TERMO + '&fundo=' + imprimir + '&t=' + Date.now();
            else location.href = 'termo_pt_937.php?id=' + ID_TERMO + '&ok=guardado';
        } catch (e) {
            if (janela) janela.close();
            aviso('<i class="fa-solid fa-triangle-exclamation"></i> ' + e.message);
        } finally { btn.disabled = false; }
    }

    function reporCampos() {
        if (!confirm('Repor todos os campos com os dados atuais do relatório do PT? As alterações feitas só no Modelo 937 perdem-se (o impresso carregado mantém-se).')) return;
        document.getElementById('acao937').value = 'repor';
        document.getElementById('form937').submit();
    }
    </script>
</body>
</html>
