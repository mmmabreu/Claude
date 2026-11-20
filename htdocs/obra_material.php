<?php
// =====================================================================
// MATERIAL A APROVISIONAR (por obra)
// É o material da FAME da obra: uma tabela com Especialidade, Designação, Fabricante, Referência, Un. e Qtd.,
// por ordem de especialidade (Elétrico, ITED, Segurança, Deteção de incêndios, outras, Geral) e, dentro de cada uma,
// por ordem alfabética (somado por designação + unidade + fabricante + referência).
// Se a FAME ainda não tiver linhas, mostra o material do orçamento.
// Para acrescentar, apagar ou mudar material: na FAME.
// Fornecimento (preenchido aqui): Qtd. fornecida, Nº do documento e Data do documento, por artigo.
// Tabela obras_material_fornecido (id_obra + chave do artigo = designação|unidade|fabricante|referência).
// Histórico: cada alteração ou limpeza de um fornecimento guarda antes o valor anterior em
// obras_material_fornecido_hist (nunca se perdem dados).
// =====================================================================
session_start();
require_once 'db.php';
require_once 'functions.php';
require_once 'obra_fame_comum.php';

// REGRA DE OURO 1: validação de sessão
if (!isset($_SESSION['user_id'])) { header("Location: index.php"); exit; }
// REGRA DE OURO 2: acesso restrito pela matriz de permissões
if (!fameTemAcesso($pdo)) { header("Location: index.php?erro=sem_permissao"); exit; }

// REGRA DE OURO 3: primeiro nome no menu
$nome_completo = $_SESSION['nome'] ?? 'Utilizador';
$primeiro_nome = explode(' ', trim($nome_completo))[0];

fameCriarTabelas($pdo);

// REGRA DE OURO 5: ID validado como número inteiro
$id_obra = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$obra = $id_obra ? fameObra($pdo, $id_obra) : null;
if (!$obra) { header("Location: obras.php"); exit; }

// --- FORNECIMENTOS ---
// chave com 191 caracteres: em alguns servidores MySQL um índice único maior que 767 bytes dá erro
// (foi o que fez desaparecer o formulário na 1.ª versão)
$erro_tabela = '';
try {
$pdo->exec("CREATE TABLE IF NOT EXISTS obras_material_fornecido (
    id INT AUTO_INCREMENT PRIMARY KEY,
    id_obra INT NOT NULL,
    chave VARCHAR(191) NOT NULL,
    designacao VARCHAR(255) NULL,
    unidade VARCHAR(10) NULL,
    qtd_fornecida DECIMAL(12,2) NULL,
    num_documento VARCHAR(80) NULL,
    data_documento DATE NULL,
    atualizado_por INT NULL,
    atualizado_em DATETIME NULL,
    UNIQUE KEY uk_obra_chave (id_obra, chave)
) DEFAULT CHARSET=utf8mb4");
$pdo->exec("CREATE TABLE IF NOT EXISTS obras_material_fornecido_hist (
    id INT AUTO_INCREMENT PRIMARY KEY,
    id_obra INT NOT NULL,
    chave VARCHAR(191) NOT NULL,
    designacao VARCHAR(255) NULL,
    unidade VARCHAR(10) NULL,
    qtd_fornecida DECIMAL(12,2) NULL,
    num_documento VARCHAR(80) NULL,
    data_documento DATE NULL,
    atualizado_por INT NULL,
    atualizado_em DATETIME NULL,
    acao VARCHAR(20) NOT NULL,
    arquivado_por INT NULL,
    arquivado_em DATETIME NOT NULL,
    KEY idx_obra (id_obra)
) DEFAULT CHARSET=utf8mb4");
} catch (Exception $ex_t) {
    error_log('obra_material.php (tabelas): ' . $ex_t->getMessage());
    $erro_tabela = 'erro ao criar as tabelas';
}
// Chave do artigo (não depende da especialidade, para não se perder se a especialidade mudar na FAME)
function chaveFornecido($m) {
    return mb_substr(mb_strtolower(fameChave($m['designacao'], $m['unidade']) . '|' . trim((string)($m['fabricante'] ?? '')) . '|' . trim((string)($m['referencia'] ?? '')), 'UTF-8'), 0, 191);
}
$mensagem = ''; $tipo_mensagem = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['gravar_fornecido'])) {
    // REGRA DE OURO 7: token CSRF
    if (!validar_csrf_token($_POST['csrf_token'] ?? '')) {
        $mensagem = "A sessão expirou ou o pedido não é válido. Atualize a página e grave de novo."; $tipo_mensagem = 'erro';
    } else
    try {
        $pdo->beginTransaction();
        // Valores atuais (para só mexer no que mudou e guardar o anterior no histórico)
        $st_a = $pdo->prepare("SELECT * FROM obras_material_fornecido WHERE id_obra = ?");
        $st_a->execute([$id_obra]);
        $atuais = [];
        foreach ($st_a->fetchAll(PDO::FETCH_ASSOC) as $r) $atuais[$r['chave']] = $r;
        $hist = $pdo->prepare("INSERT INTO obras_material_fornecido_hist (id_obra, chave, designacao, unidade, qtd_fornecida, num_documento, data_documento,
                                   atualizado_por, atualizado_em, acao, arquivado_por, arquivado_em)
                               VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
        $arquivar = function ($r, $acao) use ($hist) {
            $hist->execute([$r['id_obra'], $r['chave'], $r['designacao'], $r['unidade'], $r['qtd_fornecida'], $r['num_documento'], $r['data_documento'],
                            $r['atualizado_por'], $r['atualizado_em'], $acao, $_SESSION['user_id']]);
        };
        $up = $pdo->prepare("INSERT INTO obras_material_fornecido (id_obra, chave, designacao, unidade, qtd_fornecida, num_documento, data_documento, atualizado_por, atualizado_em)
                             VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
                             ON DUPLICATE KEY UPDATE designacao = VALUES(designacao), unidade = VALUES(unidade), qtd_fornecida = VALUES(qtd_fornecida),
                                 num_documento = VALUES(num_documento), data_documento = VALUES(data_documento),
                                 atualizado_por = VALUES(atualizado_por), atualizado_em = NOW()");
        $del = $pdo->prepare("DELETE FROM obras_material_fornecido WHERE id_obra = ? AND chave = ?");
        foreach ((array)($_POST['forn'] ?? []) as $f) {
            $ch = mb_substr((string)($f['chave'] ?? ''), 0, 191);
            if ($ch === '') continue;
            $q   = trim((string)($f['qtd'] ?? ''));
            $doc = mb_substr(trim((string)($f['doc'] ?? '')), 0, 80);
            $dt  = trim((string)($f['data'] ?? ''));
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dt)) $dt = '';
            $ant = $atuais[$ch] ?? null;
            if ($q === '' && $doc === '' && $dt === '') {
                // Campos limpos: o registo anterior vai para o histórico antes de sair da lista
                if ($ant) { $arquivar($ant, 'limpo'); $del->execute([$id_obra, $ch]); }
                continue;
            }
            $qn = $q === '' ? null : fameNum($q);
            if ($ant) {
                $igual = ((string)$ant['num_documento'] === $doc) && ((string)($ant['data_documento'] ?? '') === $dt)
                      && (($ant['qtd_fornecida'] === null && $qn === null) || ($ant['qtd_fornecida'] !== null && $qn !== null && abs((float)$ant['qtd_fornecida'] - $qn) < 0.005));
                if ($igual) continue;              // nada mudou neste artigo
                $arquivar($ant, 'alterado');
            }
            $up->execute([$id_obra, $ch, mb_substr((string)($f['desig'] ?? ''), 0, 255), mb_substr((string)($f['un'] ?? ''), 0, 10),
                          $qn, $doc, $dt === '' ? null : $dt, $_SESSION['user_id']]);
        }
        $pdo->commit();
        if (function_exists('registrarLog')) registrarLog($pdo, "Gravou material fornecido da obra ID: $id_obra", "Obras");
        header("Location: obra_material.php?id=" . $id_obra . "&gravado=1"); exit;
    } catch (Exception $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('obra_material.php (gravar): ' . $ex->getMessage());
        $mensagem = "Não foi possível gravar os fornecimentos. Tente de novo; se o erro continuar, avise o administrador."; $tipo_mensagem = 'erro';
    }
}
if (isset($_GET['gravado'])) { $mensagem = "Fornecimentos gravados."; $tipo_mensagem = 'sucesso'; }

$orc = fameOrcamentoDaObra($pdo, $obra);
$fame = fameDaObra($pdo, $id_obra, false);
$fames = $fame ? fameLinhasDaFame($pdo, $fame['id']) : [];   // linhas da FAME
$grupos = fameMaterialAprovisionar($pdo, $id_obra, $orc['id'] ?? 0);
$disponiveis = $fames ? fameDisponiveis($pdo, $id_obra, $orc['id'] ?? 0) : [];
$n_total = 0; foreach ($grupos as $g) $n_total += count($g);

// Fornecimentos gravados desta obra
$fornecido = [];
try {
    $st_f = $pdo->prepare("SELECT * FROM obras_material_fornecido WHERE id_obra = ?");
    $st_f->execute([$id_obra]);
    foreach ($st_f->fetchAll(PDO::FETCH_ASSOC) as $r) $fornecido[$r['chave']] = $r;
} catch (Exception $ex_f) {
    error_log('obra_material.php (ler): ' . $ex_f->getMessage());
    if ($erro_tabela === '') $erro_tabela = 'erro ao ler';
}
if ($erro_tabela !== '' && $mensagem === '') { $mensagem = "Não foi possível preparar a tabela dos fornecimentos. Avise o administrador."; $tipo_mensagem = 'erro'; }
// Estado de cada artigo: completo / parcial / por fornecer
$n_completos = 0; $n_parciais = 0; $chaves_lista = [];
foreach ($grupos as $lista) foreach ($lista as $m) {
    $ch = chaveFornecido($m); $chaves_lista[$ch] = 1;
    $q = isset($fornecido[$ch]['qtd_fornecida']) ? (float)$fornecido[$ch]['qtd_fornecida'] : 0;
    if ($q > 0 && $q + 0.0001 >= (float)$m['quantidade']) $n_completos++; elseif ($q > 0) $n_parciais++;
}
// Fornecimentos de artigos que já não estão na lista (ex.: designação ou referência mudada na FAME) — não se perdem
$orfaos = array_filter($fornecido, fn($r) => !isset($chaves_lista[$r['chave']]));

$e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$ref_obra = $obra['referencia_obra'] ?: ($orc['numero_orcamento'] ?? (string)$id_obra);
?>
<!DOCTYPE html>
<html lang="pt-PT">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Material a aprovisionar - <?= $e($obra['nome']) ?> - Gestão MAB</title>
    <?php /* REGRA DE OURO 8: ícone MAB no separador */ ?>
    <link rel="icon" type="image/png" href="/mab.png">
    <link rel="apple-touch-icon" href="/mab.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { font-family: system-ui, -apple-system, sans-serif; box-sizing: border-box; }
        body { background-color: #f7f9fc; margin: 0; color: #333; font-size: 14px; padding-bottom: 90px; }

        .topbar { background: rgba(255,255,255,0.94); backdrop-filter: blur(8px); -webkit-backdrop-filter: blur(8px); padding: 10px 40px; box-shadow: 0 1px 0 #e8ecf1; display: flex; justify-content: space-between; align-items: center; position: sticky; top: 0; z-index: 100; }
        .topbar-left a { display: block; }
        .topbar-left .logo { height: 35px; display: block; }
        .topbar-right { display: flex; align-items: center; gap: 12px; }
        .btn-voltar { width: 40px; height: 40px; border-radius: 50%; background: #fff; border: 1px solid #e8ecf1; color: #576574; display: inline-flex; align-items: center; justify-content: center; text-decoration: none; font-size: 16px; transition: all 0.2s ease; box-shadow: 0 2px 6px rgba(0,0,0,0.04); flex-shrink: 0; }
        .btn-voltar:hover { background: #eef2f6; color: #34495e; border-color: #d5dce4; transform: translateX(-2px); }
        .btn-voltar:active { transform: scale(0.94); }
        .user-menu { position: relative; }
        .user-btn { background: #fff; border: 1px solid #e8ecf1; border-radius: 30px; font-size: 14px; font-weight: 600; color: #2c3e50; cursor: pointer; display: flex; align-items: center; gap: 10px; padding: 5px 14px 5px 5px; transition: 0.2s; font-family: inherit; }
        .user-avatar { background: #e74c3c; color: #fff; width: 28px; height: 28px; border-radius: 50%; display: flex; justify-content: center; align-items: center; font-size: 13px; }
        .dropdown-content { display: none; position: absolute; right: 0; top: 100%; background: #fff; min-width: 180px; box-shadow: 0 12px 30px rgba(0,0,0,0.1); border-radius: 10px; border: 1px solid #e8ecf1; overflow: hidden; margin-top: 6px; z-index: 200; }
        .user-menu:hover .dropdown-content, .user-menu.aberto .dropdown-content { display: block; }
        .dropdown-content a { color: #576574; padding: 12px 20px; text-decoration: none; display: block; font-size: 14px; }
        .dropdown-content a:hover { background: #f0fff4; color: #2ecc71; }

        .container { max-width: 1500px; margin: 20px auto; padding: 0 15px; }
        .alert { padding: 13px 15px; border-radius: 8px; margin-bottom: 18px; font-weight: 600; display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
        .alert-aviso { background: #fff8e1; color: #8a6d00; border: 1px solid #ffe082; }
        .alert-info { background: #eaf2fb; color: #1f5f8b; border: 1px solid #aed1f0; }

        .header-box { display: flex; justify-content: space-between; align-items: flex-start; gap: 15px; margin-bottom: 18px; flex-wrap: wrap; }
        h1 { margin: 0; color: #2c3e50; font-size: 22px; font-weight: 800; }
        .sub { color: #7f8c8d; font-size: 13px; margin-top: 6px; line-height: 1.5; }
        .badge-ref { font-size: 11px; color: #2ecc71; font-weight: 700; background: #e8f5e9; padding: 3px 8px; border-radius: 5px; display: inline-block; }
        .header-actions { display: flex; gap: 8px; flex-wrap: wrap; }
        .btn-out { background: #fff; color: #34495e; border: 1px solid #cfd6de; padding: 9px 15px; border-radius: 30px; text-decoration: none; font-weight: 700; font-size: 13px; display: inline-flex; align-items: center; gap: 6px; }
        .btn-out:hover { background: #34495e; color: #fff; border-color: #34495e; }
        .btn-print { background: #34495e; color: #fff; border: 1px solid #34495e; padding: 9px 16px; border-radius: 30px; text-decoration: none; font-weight: 800; font-size: 13px; display: inline-flex; align-items: center; gap: 6px; }

        .card { background: #fff; border-radius: 12px; border: 1px solid #e1e5ea; box-shadow: 0 2px 10px rgba(0,0,0,0.02); margin-bottom: 16px; overflow: hidden; }
        .card-head { display: flex; justify-content: space-between; align-items: center; gap: 10px; padding: 12px 16px; background: #f8f9fa; border-bottom: 1px solid #e1e5ea; flex-wrap: wrap; }
        .resumo { display: flex; gap: 6px; flex-wrap: wrap; }
        .chip { font-size: 12px; font-weight: 700; color: #576574; background: #fff; border: 1px solid #e1e5ea; padding: 3px 10px; border-radius: 12px; text-decoration: none; }
        .chip b { color: #16a085; }
        .chip:hover { border-color: #16a085; }
        .card-head a.alterar { font-size: 12px; font-weight: 700; color: #2980b9; text-decoration: none; white-space: nowrap; }
        table { width: 100%; border-collapse: collapse; }
        th { padding: 9px 12px; text-align: left; font-size: 11px; text-transform: uppercase; color: #95a5a6; border-bottom: 1px solid #e1e5ea; background: #fff; }
        td { padding: 9px 12px; border-bottom: 1px solid #f5f6f8; color: #2c3e50; vertical-align: top; }
        tr.grupo-ini td { border-top: 2px solid #d5e8e1; }
        tbody tr:first-child td { border-top: none; }
        .c-esp { width: 175px; }
        .c-esp .esp { white-space: normal; }
        .esp { display: inline-block; font-size: 11px; font-weight: 800; color: #16a085; background: #e8f8f3; padding: 3px 8px; border-radius: 5px; }
        tr:not(.grupo-ini) .c-esp .esp { opacity: .45; }
        .c-fab { width: 140px; }
        .c-ref { width: 150px; font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; font-size: 13px; }
        .falta-dado { color: #e67e22; font-style: italic; font-size: 12px; font-family: system-ui, sans-serif; }
        .c-un { width: 60px; text-align: center; color: #576574; }
        .c-qtd { width: 70px; text-align: center; font-weight: 800; }
        /* Fornecimento */
        th.c-forn, td.c-forn { background: #f7fbff; }
        .c-fq { width: 100px; } .c-fd { width: 140px; } .c-fdt { width: 150px; }
        .c-forn input { width: 100%; padding: 7px 8px; border: 1px solid #dcdde1; border-radius: 7px; font-size: 13px; font-family: inherit; color: #2c3e50; background: #fff; outline: none; }
        .c-forn input:focus { border-color: #2980b9; box-shadow: 0 0 0 3px rgba(41,128,185,0.1); }
        .c-fq input { text-align: center; font-weight: 800; }
        tr.f-ok td.c-fq input { border-color: #27ae60; color: #1e8449; background: #eafaf1; }
        tr.f-parcial td.c-fq input { border-color: #f0b27a; color: #d35400; background: #fef5e7; }
        .f-falta { display: block; font-size: 10px; font-weight: 700; color: #d35400; text-align: center; margin-top: 2px; }
        .chip-f { font-size: 12px; font-weight: 700; padding: 3px 10px; border-radius: 12px; border: 1px solid; }
        .chip-f.ok { color: #1e8449; border-color: #abebc6; background: #eafaf1; }
        .chip-f.parcial { color: #d35400; border-color: #f5cba7; background: #fef5e7; }
        .chip-f.falta { color: #7f8c8d; border-color: #e1e5ea; background: #fff; }
        .rodape { position: fixed; left: 0; right: 0; bottom: 0; background: rgba(255,255,255,0.97); border-top: 1px solid #e1e5ea; padding: 12px 20px; display: flex; justify-content: flex-end; gap: 10px; z-index: 90; box-shadow: 0 -4px 14px rgba(0,0,0,0.04); }
        .btn-gravar { background: #2980b9; color: #fff; border: none; padding: 12px 22px; border-radius: 10px; font-weight: 800; font-size: 14px; cursor: pointer; font-family: inherit; display: inline-flex; align-items: center; gap: 8px; }
        .alert-sucesso { background: #e8f5e9; color: #1e8449; border: 1px solid #c8e6c9; }
        .alert-erro { background: #fdeded; color: #c0392b; border: 1px solid #fadbd8; }
        .orfaos { background: #fff; border: 1px solid #e1e5ea; border-radius: 12px; padding: 12px 16px; margin-bottom: 16px; font-size: 13px; color: #576574; }
        .orfaos li { line-height: 1.7; }
        .vazio { background: #fff; border: 1px dashed #cfd6de; border-radius: 14px; padding: 40px 20px; text-align: center; color: #7f8c8d; }
        details.falta { background: #fff; border: 1px solid #ffe082; border-radius: 12px; margin-bottom: 16px; }
        details.falta summary { padding: 12px 16px; cursor: pointer; font-weight: 700; color: #8a6d00; }
        details.falta ul { margin: 0; padding: 0 16px 14px 36px; color: #576574; font-size: 13px; line-height: 1.7; }

        @media (max-width: 768px) {
            .topbar { padding: 10px 15px; }
            .btn-voltar { width: 38px; height: 38px; }
            .user-nome { display: none; }
            .header-actions { width: 100%; }
            .header-actions > * { flex: 1; justify-content: center; }
            /* Tabela passa a cartões */
            thead { display: none; }
            table, tbody, tr, td { display: block; width: 100% !important; }
            .card { background: transparent; border: none; box-shadow: none; overflow: visible; }
            .card-head { background: #fff; border: 1px solid #e1e5ea; border-radius: 12px; margin-bottom: 10px; }
            tbody tr { background: #fff; border: 1px solid #e1e5ea; border-radius: 12px; margin-bottom: 10px; padding: 8px 0; }
            tr.grupo-ini td { border-top: none; }
            td { border: none; padding: 3px 14px; text-align: left !important; }
            td.c-desig { font-weight: 700; padding-top: 6px; padding-bottom: 6px; }
            tr:not(.grupo-ini) .c-esp .esp { opacity: 1; }
            td[data-label]::before { content: attr(data-label) ": "; font-size: 11px; font-weight: 700; color: #95a5a6; text-transform: uppercase; font-family: system-ui, sans-serif; }
            td.c-un, td.c-qtd { display: inline-block; width: auto !important; }
            td.c-forn { background: transparent; }
            td.c-forn[data-label]::before { content: attr(data-label); display: block; margin: 4px 0 3px; }
            .f-falta { text-align: left; }
            .rodape { padding: 10px 12px; }
            .rodape button { flex: 1; justify-content: center; }
        }
        @media (max-width: 560px) { h1 { font-size: 19px; } }
    </style>
</head>
<body>

    <header class="topbar">
        <div class="topbar-left"><a href="index.php" title="Página inicial"><img src="mab.png" alt="MAB" class="logo"></a></div>
        <div class="topbar-right">
            <a href="obras.php" class="btn-voltar" title="Voltar" aria-label="Voltar"><i class="fa-solid fa-arrow-left"></i></a>
            <div class="user-menu">
                <button type="button" class="user-btn">
                    <div class="user-avatar"><i class="fa-solid fa-user"></i></div>
                    <span class="user-nome"><?= $e($primeiro_nome) ?></span>
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
        <div class="header-box">
            <div>
                <h1><i class="fa-solid fa-boxes-stacked" style="color:#16a085; margin-right:6px;"></i> Material a aprovisionar</h1>
                <div class="sub">
                    <strong style="color:#2c3e50;"><?= $e($obra['nome']) ?></strong> <span class="badge-ref">REF: <?= $e($ref_obra) ?></span>
                    <?php if (!empty($obra['cliente_nome'])): ?><br><i class="fa-solid fa-user" style="font-size:11px;"></i> <?= $e($obra['cliente_nome']) ?><?php endif; ?>
                    <br><?= $n_total ?> artigo(s)
                </div>
            </div>
            <div class="header-actions">
                <a href="obra_fame.php?id=<?= $id_obra ?>" class="btn-out"><i class="fa-solid fa-clipboard-check"></i> FAME</a>
                <?php if ($grupos): ?><a href="obra_material_pdf.php?id=<?= $id_obra ?>" target="_blank" class="btn-print"><i class="fa-solid fa-print"></i> Imprimir</a><?php endif; ?>
            </div>
        </div>

        <?php if ($mensagem): ?><div class="alert alert-<?= $e($tipo_mensagem) ?>"><?= $e($mensagem) ?></div><?php endif; ?>

        <?php if (!$fames): ?>
            <div class="alert alert-info"><i class="fa-solid fa-circle-info"></i> A FAME desta obra ainda não tem linhas: a lista é o material do orçamento. Quando importar o material para a FAME, a lista passa a ser o da FAME, por especialidade.</div>
        <?php elseif ($disponiveis): ?>
            <details class="falta">
                <summary><i class="fa-solid fa-triangle-exclamation"></i> <?= count($disponiveis) ?> artigo(s) do orçamento ainda não estão na FAME, por isso não aparecem aqui</summary>
                <ul><?php foreach ($disponiveis as $m): ?><li><?= $e($m['designacao']) ?> — <?= fameQtd($m['quantidade']) ?> <?= $e($m['unidade']) ?></li><?php endforeach; ?></ul>
                <div style="padding:0 16px 14px;"><a href="obra_fame.php?id=<?= $id_obra ?>" class="btn-out"><i class="fa-solid fa-file-import"></i> Abrir a FAME para importar</a></div>
            </details>
        <?php endif; ?>

        <?php if (!$grupos): ?>
            <div class="vazio"><i class="fa-solid fa-box-open" style="font-size:28px; display:block; margin-bottom:10px; color:#bdc3c7;"></i>Sem material. Crie uma FAME e acrescente o material.</div>
        <?php endif; ?>

        <?php if ($grupos): ?>
            <?php if ($orfaos): ?>
                <details class="orfaos">
                    <summary><b><?= count($orfaos) ?></b> fornecimento(s) de artigos que já não estão na lista (a designação, fabricante ou referência mudou na FAME)</summary>
                    <ul><?php foreach ($orfaos as $o): ?><li><?= $e($o['designacao'] ?: $o['chave']) ?> — <?= $o['qtd_fornecida'] !== null ? fameQtd($o['qtd_fornecida']) . ' ' . $e($o['unidade']) : '-' ?><?= $o['num_documento'] ? ' · Doc. ' . $e($o['num_documento']) : '' ?><?= $o['data_documento'] ? ' · ' . date('d/m/Y', strtotime($o['data_documento'])) : '' ?></li><?php endforeach; ?></ul>
                </details>
            <?php endif; ?>
            <form method="POST" id="formForn" autocomplete="off">
            <input type="hidden" name="gravar_fornecido" value="1">
            <?php /* REGRA DE OURO 7: token CSRF */ ?>
            <input type="hidden" name="csrf_token" value="<?= $e(gerar_csrf_token()) ?>">
            <div class="card">
                <div class="card-head">
                    <div class="resumo">
                        <?php foreach ($grupos as $esp => $lista): ?><span class="chip"><?= $e($esp) ?> <b><?= count($lista) ?></b></span><?php endforeach; ?>
                        <span class="chip-f ok" title="Quantidade fornecida igual ou superior à necessária"><i class="fa-solid fa-check"></i> <?= $n_completos ?> fornecido(s)</span>
                        <?php if ($n_parciais): ?><span class="chip-f parcial"><?= $n_parciais ?> parcial(ais)</span><?php endif; ?>
                        <span class="chip-f falta"><?= $n_total - $n_completos - $n_parciais ?> por fornecer</span>
                    </div>
                    <?php if ($fames): ?><a class="alterar" href="obra_fame.php?id=<?= $id_obra ?>"><i class="fa-solid fa-pen-to-square"></i> Alterar na FAME</a><?php endif; ?>
                </div>
                <table>
                    <thead><tr><th class="c-esp">Especialidade</th><th>Designação</th><th class="c-fab">Fabricante</th><th class="c-ref">Referência</th><th class="c-un">Un.</th><th class="c-qtd">Qtd.</th>
                        <th class="c-forn c-fq">Qtd. fornecida</th><th class="c-forn c-fd">Nº documento</th><th class="c-forn c-fdt">Data doc.</th></tr></thead>
                    <tbody>
                        <?php $nf = 0; foreach ($grupos as $esp => $lista): $primeira = true; ?>
                            <?php foreach ($lista as $m):
                                $nf++;
                                $ch = chaveFornecido($m);
                                $f = $fornecido[$ch] ?? [];
                                $qf = (isset($f['qtd_fornecida']) && $f['qtd_fornecida'] !== null) ? (float)$f['qtd_fornecida'] : null;
                                $estado_f = ($qf !== null && $qf > 0) ? (($qf + 0.0001 >= (float)$m['quantidade']) ? 'f-ok' : 'f-parcial') : '';
                            ?>
                                <tr class="<?= $primeira ? 'grupo-ini' : '' ?> <?= $estado_f ?>" data-qtd="<?= (float)$m['quantidade'] ?>">
                                    <td class="c-esp"><span class="esp"><?= $e($m['especialidade'] ?? $esp) ?></span></td>
                                    <td class="c-desig"><?= $e($m['designacao']) ?></td>
                                    <td class="c-fab" data-label="Fabricante"><?= ($m['fabricante'] ?? '') !== '' ? $e($m['fabricante']) : '<span class="falta-dado">por preencher</span>' ?></td>
                                    <td class="c-ref" data-label="Referência"><?= ($m['referencia'] ?? '') !== '' ? $e($m['referencia']) : '<span class="falta-dado">por preencher</span>' ?></td>
                                    <td class="c-un" data-label="Un."><?= $e($m['unidade']) ?></td>
                                    <td class="c-qtd" data-label="Qtd."><?= fameQtd($m['quantidade']) ?></td>
                                    <td class="c-forn c-fq" data-label="Qtd. fornecida">
                                        <input type="hidden" name="forn[<?= $nf ?>][chave]" value="<?= $e($ch) ?>">
                                        <input type="hidden" name="forn[<?= $nf ?>][desig]" value="<?= $e($m['designacao']) ?>">
                                        <input type="hidden" name="forn[<?= $nf ?>][un]" value="<?= $e($m['unidade']) ?>">
                                        <input type="text" name="forn[<?= $nf ?>][qtd]" value="<?= $qf !== null ? $e(fameQtd($qf)) : '' ?>" inputmode="decimal" placeholder="0" oninput="mudouForn(this)">
                                        <span class="f-falta"><?= ($estado_f === 'f-parcial') ? 'faltam ' . fameQtd((float)$m['quantidade'] - $qf) : '' ?></span>
                                    </td>
                                    <td class="c-forn c-fd" data-label="Nº documento"><input type="text" name="forn[<?= $nf ?>][doc]" value="<?= $e($f['num_documento'] ?? '') ?>" maxlength="80" placeholder="Fatura / Guia" oninput="alterado = true;"></td>
                                    <td class="c-forn c-fdt" data-label="Data doc."><input type="date" name="forn[<?= $nf ?>][data]" value="<?= $e($f['data_documento'] ?? '') ?>" onchange="alterado = true;"></td>
                                </tr>
                            <?php $primeira = false; endforeach; ?>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="rodape">
                <button type="submit" class="btn-gravar" onclick="alterado = false;"><i class="fa-solid fa-floppy-disk"></i> Gravar fornecimentos</button>
            </div>
            </form>
        <?php endif; ?>
    </div>

    <script>
        // Fornecimento: verde = fornecido por completo, laranja = parcial (mostra quanto falta)
        let alterado = false;
        function numPT(t) { t = String(t).trim().replace(/\s/g, ''); if (t.includes(',')) t = t.replace(/\./g, '').replace(',', '.'); return parseFloat(t); }
        function mudouForn(inp) {
            alterado = true;
            const tr = inp.closest('tr'), q = parseFloat(tr.dataset.qtd) || 0, f = numPT(inp.value);
            tr.classList.remove('f-ok', 'f-parcial');
            let falta = '';
            if (!isNaN(f) && f > 0) {
                if (f + 0.0001 >= q) tr.classList.add('f-ok');
                else { tr.classList.add('f-parcial'); falta = 'faltam ' + String(Math.round((q - f) * 100) / 100).replace('.', ','); }
            }
            tr.querySelector('.f-falta').textContent = falta;
        }
        window.addEventListener('beforeunload', e => { if (alterado) { e.preventDefault(); e.returnValue = ''; } });

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
