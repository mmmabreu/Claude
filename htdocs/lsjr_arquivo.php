<?php
session_start();
require_once 'db.php';

// REGRA DE OURO: Funções centralizadas
if (file_exists('functions.php')) {
    require_once 'functions.php';
} else {
    function registrarLog($pdo, $acao, $modulo) { return true; }
}

// REGRA DE OURO 1: Validação de sessão
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}

$perfil_user = $_SESSION['perfil'] ?? '';
$is_admin = in_array($perfil_user, ['admin', 'administrativo']);

// Acesso Restrito à Gestão Global
if (!$is_admin) {
    header("Location: index.php?erro=sem_permissao");
    exit;
}

$nome_completo = $_SESSION['nome'] ?? 'Utilizador';
$primeiro_nome = explode(' ', trim($nome_completo))[0];

function formataValor($valor) {
    return number_format((float)$valor, 2, ',', '.') . ' €';
}
function dataPT($d) {
    return !empty($d) ? date('d/m/Y', strtotime($d)) : '';
}
function tabelaExiste($pdo, $tabela) {
    try {
        $st = $pdo->query("SHOW TABLES LIKE " . $pdo->quote($tabela));
        return $st && $st->rowCount() > 0;
    } catch (Exception $e) {
        return false;
    }
}

// --- FILTROS ---
$f_tipo   = $_GET['tipo'] ?? 'todos';            // todos | mab | lsjr | pdct
$f_acerto = (int)($_GET['acerto'] ?? 0);         // 0 = todos os acertos
$f_texto  = trim($_GET['q'] ?? '');
if (!in_array($f_tipo, ['todos', 'mab', 'lsjr', 'pdct'])) $f_tipo = 'todos';

// --- LISTA DE ACERTOS (para o filtro e para mostrar nº/data) ---
$acertos = [];
try {
    if (tabelaExiste($pdo, 'lsjr_acertos')) {
        foreach ($pdo->query("SELECT id, numero, data_acerto FROM lsjr_acertos ORDER BY numero DESC, id DESC")->fetchAll(PDO::FETCH_ASSOC) as $a) {
            $acertos[(int)$a['id']] = $a;
        }
    }
} catch (Exception $e) {}

// --- DOCUMENTOS ACERTADOS (ocultos nas outras páginas) ---
$fontes = [
    'mab'  => ['tabela' => 'lsjr_fatmab', 'nome' => 'A favor MAB',
               'sql' => "SELECT id, id_acerto, seccao, tipo_doc, numero_documento AS doc, numero_externo, data_emissao AS data, valor_original AS valor, NULL AS valor_corrigido, origem, observacoes FROM lsjr_fatmab WHERE estado = 'Acertado'"],
    'lsjr' => ['tabela' => 'lsjr_fatfav', 'nome' => 'A favor LSJR',
               'sql' => "SELECT id, id_acerto, seccao, tipo_doc, numero_documento AS doc, numero_externo, data_emissao AS data, valor_original AS valor, NULL AS valor_corrigido, origem, observacoes FROM lsjr_fatfav WHERE estado = 'Acertado'"],
    'pdct' => ['tabela' => 'lsjr_pdct', 'nome' => 'PDCT (info)',
               'sql' => "SELECT id, id_acerto, seccao, 'PDCT' AS tipo_doc, pdct AS doc, NULL AS numero_externo, data_registo AS data, valor, valor_corrigido, 'PDCT' AS origem, observacoes FROM lsjr_pdct WHERE estado = 'Acertado'"],
];

$linhas = [];
$totais = ['mab' => [0, 0], 'lsjr' => [0, 0], 'pdct' => [0, 0]]; // [valor, qtd]
$erro = '';
try {
    foreach ($fontes as $chave => $f) {
        if ($f_tipo !== 'todos' && $f_tipo !== $chave) continue;
        if (!tabelaExiste($pdo, $f['tabela'])) continue;
        $sql = $f['sql'];
        $params = [];
        if ($f_acerto > 0) { $sql .= " AND id_acerto = ?"; $params[] = $f_acerto; }
        $st = $pdo->prepare($sql);
        $st->execute($params);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if ($f_texto !== '') {
                $alvo = mb_strtolower(($r['tipo_doc'] ?? '') . ' ' . ($r['doc'] ?? '') . ' ' . ($r['numero_externo'] ?? '') . ' ' . ($r['observacoes'] ?? ''));
                if (mb_strpos($alvo, mb_strtolower($f_texto)) === false) continue;
            }
            $r['grupo'] = $chave;
            $linhas[] = $r;
            $v = ($chave === 'pdct') ? (float)$r['valor_corrigido'] : (float)$r['valor'];
            $totais[$chave][0] += $v;
            $totais[$chave][1]++;
        }
    }
} catch (Exception $e) {
    $erro = $e->getMessage();
}

// Ordenar: acerto mais recente primeiro, depois por data do documento
usort($linhas, function ($a, $b) use ($acertos) {
    $na = $acertos[(int)$a['id_acerto']]['numero'] ?? 0;
    $nb = $acertos[(int)$b['id_acerto']]['numero'] ?? 0;
    if ($na != $nb) return $nb <=> $na;
    $ordem = ['mab' => 1, 'lsjr' => 2, 'pdct' => 3];
    if ($a['grupo'] !== $b['grupo']) return $ordem[$a['grupo']] <=> $ordem[$b['grupo']];
    return strcmp((string)$a['data'], (string)$b['data']);
});

function urlFiltro($alterar) {
    $p = array_merge(['tipo' => $_GET['tipo'] ?? 'todos', 'acerto' => $_GET['acerto'] ?? '', 'q' => $_GET['q'] ?? ''], $alterar);
    $p = array_filter($p, fn($v) => $v !== '' && $v !== null && $v !== 'todos' && $v !== '0' && $v !== 0);
    return 'lsjr_arquivo.php' . ($p ? '?' . http_build_query($p) : '');
}
?>
<!DOCTYPE html>
<html lang="pt-PT">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>LSJR - Arquivo (Acertados) - MAB</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --laranja: #e67e22; --laranja-esc: #d35400; --laranja-claro: #fdf2e9;
            --verde: #27ae60; --verde-claro: #eafaf1;
            --vermelho: #c0392b; --vermelho-claro: #fdedec;
            --texto: #2c3e50; --suave: #7f8c8d; --borda: #e8ecf1; --fundo: #f4f6fa;
        }
        * { font-family: system-ui, -apple-system, "Segoe UI", sans-serif; box-sizing: border-box; }
        body { background: var(--fundo); margin: 0; color: var(--texto); font-size: 14px; padding-bottom: 60px; }

        .topbar { background: rgba(255,255,255,0.92); backdrop-filter: blur(8px); padding: 10px 40px; box-shadow: 0 1px 0 var(--borda); display: flex; justify-content: space-between; align-items: center; position: sticky; top: 0; z-index: 100; }
        .topbar-left .logo { height: 35px; display: block; }
        .topbar-right { display: flex; align-items: center; gap: 20px; }
        .btn-back { color: #576574; text-decoration: none; font-size: 15px; font-weight: 600; display: flex; align-items: center; gap: 8px; transition: color 0.2s; }
        .btn-back:hover { color: var(--laranja); }
        .user-menu { position: relative; }
        .user-btn { background: #fff; border: 1px solid var(--borda); border-radius: 30px; font-size: 14px; font-weight: 600; color: var(--texto); cursor: pointer; display: flex; align-items: center; gap: 10px; padding: 5px 14px 5px 5px; }
        .user-avatar { background: var(--laranja); color: #fff; width: 28px; height: 28px; border-radius: 50%; display: flex; justify-content: center; align-items: center; font-size: 13px; }
        .dropdown-content { display: none; position: absolute; right: 0; top: 100%; background: #fff; min-width: 180px; box-shadow: 0 12px 30px rgba(0,0,0,0.1); border-radius: 10px; border: 1px solid var(--borda); overflow: hidden; margin-top: 6px; z-index: 200; }
        .user-menu:hover .dropdown-content { display: block; }
        .dropdown-content a { color: #576574; padding: 12px 20px; text-decoration: none; display: block; font-size: 14px; }
        .dropdown-content a:hover { background: var(--laranja-claro); color: var(--laranja); }

        .container { max-width: 1200px; margin: 32px auto; padding: 0 20px; }
        .eyebrow { font-size: 12px; font-weight: 700; letter-spacing: 1.5px; text-transform: uppercase; color: var(--laranja); margin-bottom: 4px; }
        .page-title { margin: 0; font-size: 26px; font-weight: 800; letter-spacing: -0.5px; }
        .page-sub { color: var(--suave); margin: 4px 0 22px; }

        /* Separadores por tipo */
        .tabs { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 16px; }
        .tab { text-decoration: none; color: #576574; background: #fff; border: 1px solid var(--borda); padding: 8px 14px; border-radius: 30px; font-weight: 600; font-size: 13px; display: flex; align-items: center; gap: 8px; transition: 0.2s; }
        .tab:hover { border-color: #f5cba7; color: var(--laranja-esc); }
        .tab.ativo { background: var(--texto); color: #fff; border-color: var(--texto); }
        .tab .n { background: rgba(0,0,0,0.06); padding: 1px 8px; border-radius: 20px; font-size: 11px; }
        .tab.ativo .n { background: rgba(255,255,255,0.2); }

        /* Filtros */
        .filtros { background: #fff; border: 1px solid var(--borda); border-radius: 14px; padding: 14px; display: flex; gap: 10px; flex-wrap: wrap; align-items: center; margin-bottom: 18px; }
        .filtros select, .filtros input { border: 1px solid #dcdde1; border-radius: 8px; padding: 9px 12px; font-size: 14px; background: #fff; color: var(--texto); }
        .filtros input { flex: 1; min-width: 200px; }
        .filtros button, .btn-limpar { background: var(--laranja); color: #fff; border: none; border-radius: 8px; padding: 10px 16px; font-weight: 700; cursor: pointer; text-decoration: none; font-size: 13px; display: inline-flex; align-items: center; gap: 6px; }
        .btn-limpar { background: #f1f2f6; color: #576574; }
        .btn-print { background: var(--laranja-claro); color: var(--laranja-esc); border: 1px solid #f5cba7; }

        /* Resumo */
        .resumo { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 14px; margin-bottom: 18px; }
        .res-card { background: #fff; border: 1px solid var(--borda); border-radius: 14px; padding: 14px 16px; border-left: 4px solid var(--c); }
        .res-card small { color: var(--suave); font-weight: 600; display: block; }
        .res-card b { font-size: 19px; color: var(--c); }
        .res-card span { color: var(--suave); font-size: 12px; }

        /* Tabela */
        .tabela-wrap { background: #fff; border: 1px solid var(--borda); border-radius: 14px; overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; min-width: 850px; }
        th { background: #f8f9fb; color: #576574; font-size: 12px; text-transform: uppercase; letter-spacing: 0.5px; text-align: left; padding: 11px 12px; border-bottom: 1px solid var(--borda); }
        td { padding: 10px 12px; border-bottom: 1px solid #f1f3f6; vertical-align: middle; }
        tr:last-child td { border-bottom: none; }
        tr:hover td { background: #fcfcfd; }
        .grupo-acerto td { background: #fbfcfd; font-weight: 700; color: var(--texto); border-top: 2px solid var(--borda); }
        .grupo-acerto a { color: var(--laranja-esc); text-decoration: none; font-weight: 600; font-size: 12px; margin-left: 10px; }
        .grupo-acerto a:hover { text-decoration: underline; }
        .badge { font-size: 11px; font-weight: 700; padding: 3px 9px; border-radius: 20px; white-space: nowrap; }
        .b-mab { background: var(--verde-claro); color: var(--verde); }
        .b-lsjr { background: var(--vermelho-claro); color: var(--vermelho); }
        .b-pdct { background: #f2f4f4; color: #7f8c8d; }
        .b-abert { background: #fef9e7; color: #b9770e; margin-left: 6px; }
        .num { text-align: right; white-space: nowrap; font-weight: 700; }
        .neg { color: var(--vermelho); }
        .obs { color: var(--suave); font-size: 12.5px; max-width: 320px; }
        .vazio { text-align: center; color: var(--suave); padding: 40px; }

        .user-menu.aberto .dropdown-content { display: block; }

        /* ===== ECRÃS GRANDES (computador) ===== */
        @media (min-width: 1500px) {
            .container { max-width: 1400px; }
        }

        /* ===== TABLET (até 1024 px) ===== */
        @media (max-width: 1024px) {
            .topbar { padding: 10px 24px; }
            .container { margin: 24px auto; }
            .obs { max-width: 200px; }
            th, td { padding: 9px 10px; }
            /* Esconde a coluna Nº Externo para ganhar espaço */
            th.col-ext, td.col-ext { display: none; }
            table { min-width: 0; }
        }

        /* ===== TELEMÓVEL / TABLET VERTICAL (até 768 px): tabela passa a cartões ===== */
        @media (max-width: 768px) {
            .topbar { padding: 8px 15px; }
            .topbar-left .logo { height: 30px; }
            .btn-back span { display: none; }
            .btn-back { font-size: 18px; padding: 6px; }
            .container { margin: 18px auto; padding: 0 12px; }
            .page-title { font-size: 22px; }
            .page-sub { font-size: 13px; margin-bottom: 16px; }

            .tabs { flex-wrap: nowrap; overflow-x: auto; padding-bottom: 4px; -webkit-overflow-scrolling: touch; scrollbar-width: none; }
            .tabs::-webkit-scrollbar { display: none; }
            .tab { flex-shrink: 0; }

            .filtros { flex-direction: column; align-items: stretch; padding: 12px; }
            .filtros select, .filtros input { width: 100%; min-width: 0; font-size: 16px; /* evita zoom no iPhone */ }
            .filtros button, .btn-limpar { justify-content: center; padding: 12px; }

            .resumo { grid-template-columns: 1fr; gap: 10px; }
            .res-card { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 4px 10px; }
            .res-card small { width: 100%; }

            .tabela-wrap { background: transparent; border: none; overflow: visible; }
            table, thead, tbody, tr, td { display: block; width: 100%; }
            thead { display: none; }
            tr:hover td { background: transparent; }
            tbody tr { background: #fff; border: 1px solid var(--borda); border-radius: 12px; margin-bottom: 10px; padding: 10px 14px; }
            td { border: none; padding: 5px 0; display: flex; justify-content: space-between; align-items: center; gap: 12px; text-align: right; }
            td::before { content: attr(data-label); color: var(--suave); font-size: 12px; font-weight: 600; text-align: left; flex-shrink: 0; }
            td:empty, td.vazio-cel { display: none; }
            td.col-ext:not(:empty) { display: flex; }
            td.col-tipo { justify-content: flex-start; padding-bottom: 8px; border-bottom: 1px solid #f1f3f6; margin-bottom: 4px; }
            td.col-tipo::before { display: none; }
            .obs { max-width: none; }
            td.obs { flex-direction: column; align-items: flex-start; text-align: left; gap: 2px; }
            .num { font-size: 15px; }
            tr.grupo-acerto { background: var(--texto); border: none; margin-top: 18px; }
            tr.grupo-acerto td { background: transparent !important; color: #fff; border: none; flex-wrap: wrap; justify-content: flex-start; text-align: left; }
            tr.grupo-acerto td::before { display: none; }
            .grupo-acerto a { color: #f8c471; margin-left: 0; margin-right: 12px; font-size: 13px; }
            .grupo-acerto .fa-scale-balanced { color: #f8c471 !important; }
            tr.linha-vazia { background: transparent; border: none; }
            td.vazio { display: block; text-align: center; }
            td.vazio::before { display: none; }
        }

        @media (max-width: 560px) {
            .user-nome { display: none; }
            .user-btn { padding: 4px; gap: 4px; }
            .user-btn .fa-caret-down { display: none; }
        }
    </style>
</head>
<body>

    <header class="topbar">
        <div class="topbar-left"><a href="index.php"><img src="mab.png" alt="MAB" class="logo"></a></div>
        <div class="topbar-right">
            <a href="lsjr.php" class="btn-back" title="Voltar ao Módulo LSJR"><i class="fa-solid fa-arrow-left"></i> <span>Voltar ao Módulo LSJR</span></a>
            <div class="user-menu">
                <button class="user-btn">
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

        <div class="eyebrow">LSJR · Arquivo</div>
        <h1 class="page-title">Documentos acertados</h1>
        <div class="page-sub">Documentos que já entraram num acerto e por isso deixaram de aparecer nas listagens. Apenas consulta.</div>

        <?php if ($erro): ?>
            <div style="background: var(--vermelho-claro); color: var(--vermelho); padding: 12px; border-radius: 10px; margin-bottom: 15px;">Erro: <?= htmlspecialchars($erro) ?></div>
        <?php endif; ?>

        <div class="tabs">
            <?php
            $tabs = ['todos' => ['Todos', 'fa-layer-group'], 'mab' => ['A favor MAB', 'fa-file-invoice'], 'lsjr' => ['A favor LSJR', 'fa-file-invoice-dollar'], 'pdct' => ['PDCT', 'fa-file-signature']];
            foreach ($tabs as $k => [$rot, $ic]): ?>
                <a href="<?= htmlspecialchars(urlFiltro(['tipo' => $k])) ?>" class="tab <?= $f_tipo === $k ? 'ativo' : '' ?>">
                    <i class="fa-solid <?= $ic ?>"></i> <?= $rot ?>
                    <?php if ($f_tipo === $k): ?><span class="n"><?= count($linhas) ?></span><?php endif; ?>
                </a>
            <?php endforeach; ?>
        </div>

        <form method="GET" class="filtros">
            <input type="hidden" name="tipo" value="<?= htmlspecialchars($f_tipo) ?>">
            <select name="acerto" onchange="this.form.submit()">
                <option value="0">Todos os acertos</option>
                <?php foreach ($acertos as $id => $a): ?>
                    <option value="<?= $id ?>" <?= $f_acerto === $id ? 'selected' : '' ?>>Acerto nº <?= (int)$a['numero'] ?> — <?= dataPT($a['data_acerto']) ?></option>
                <?php endforeach; ?>
            </select>
            <input type="text" name="q" value="<?= htmlspecialchars($f_texto) ?>" placeholder="Procurar nº documento, nº externo ou observações...">
            <button type="submit"><i class="fa-solid fa-magnifying-glass"></i> Procurar</button>
            <?php if ($f_acerto || $f_texto !== '' || $f_tipo !== 'todos'): ?>
                <a href="lsjr_arquivo.php" class="btn-limpar"><i class="fa-solid fa-xmark"></i> Limpar</a>
            <?php endif; ?>
            <?php if ($f_acerto && isset($acertos[$f_acerto])): ?>
                <a href="lsjr_acerto_pdf.php?id=<?= $f_acerto ?>" target="_blank" class="btn-limpar btn-print"><i class="fa-solid fa-print"></i> Imprimir acerto</a>
            <?php endif; ?>
        </form>

        <div class="resumo">
            <?php if ($f_tipo === 'todos' || $f_tipo === 'mab'): ?>
                <div class="res-card" style="--c: var(--verde);"><small>A favor da m.Abreu</small><b><?= formataValor($totais['mab'][0]) ?></b> <span><?= $totais['mab'][1] ?> doc.</span></div>
            <?php endif; ?>
            <?php if ($f_tipo === 'todos' || $f_tipo === 'lsjr'): ?>
                <div class="res-card" style="--c: var(--vermelho);"><small>A favor da LSJR</small><b><?= formataValor($totais['lsjr'][0]) ?></b> <span><?= $totais['lsjr'][1] ?> doc.</span></div>
            <?php endif; ?>
            <?php if ($f_tipo === 'todos' || $f_tipo === 'pdct'): ?>
                <div class="res-card" style="--c: #7f8c8d;"><small>PDCT (V. Corrigido, informativo)</small><b><?= formataValor($totais['pdct'][0]) ?></b> <span><?= $totais['pdct'][1] ?> reg.</span></div>
            <?php endif; ?>
        </div>

        <div class="tabela-wrap">
            <table>
                <thead>
                    <tr>
                        <th class="col-tipo">Tipo</th>
                        <th>Data</th>
                        <th>Documento</th>
                        <th class="col-ext">Nº Externo</th>
                        <th style="text-align:right;">Valor</th>
                        <th style="text-align:right;">V. Corrigido</th>
                        <th>Observações</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($linhas)): ?>
                        <tr class="linha-vazia"><td colspan="7" class="vazio"><i class="fa-solid fa-box-open" style="font-size: 26px; display:block; margin-bottom: 8px;"></i>Não existem documentos acertados com estes filtros.</td></tr>
                    <?php else:
                        $acerto_atual = -1;
                        foreach ($linhas as $r):
                            $ida = (int)$r['id_acerto'];
                            if ($ida !== $acerto_atual):
                                $acerto_atual = $ida;
                                $a = $acertos[$ida] ?? null; ?>
                                <tr class="grupo-acerto">
                                    <td colspan="7">
                                        <i class="fa-solid fa-scale-balanced" style="color: var(--laranja);"></i>
                                        <?= $a ? 'Acerto nº ' . (int)$a['numero'] . ' de ' . dataPT($a['data_acerto']) : 'Sem acerto associado' ?>
                                        <?php if ($a): ?>
                                            <a href="lsjr_acerto_pdf.php?id=<?= $ida ?>" target="_blank"><i class="fa-solid fa-print"></i> Imprimir</a>
                                            <?php if (!$f_acerto): ?><a href="<?= htmlspecialchars(urlFiltro(['acerto' => $ida])) ?>"><i class="fa-solid fa-filter"></i> Só este</a><?php endif; ?>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endif;
                            $badge = ['mab' => ['b-mab', 'A favor MAB'], 'lsjr' => ['b-lsjr', 'A favor LSJR'], 'pdct' => ['b-pdct', 'PDCT']][$r['grupo']];
                            $doc = trim($r['doc'] ?? '');
                            // Juntar o tipo (ex.: V/FAC) só se o nº do documento ainda não o tiver (ex.: "FSERV 118126/23")
                            if ($r['grupo'] !== 'pdct' && !empty($r['tipo_doc']) && stripos($doc, $r['tipo_doc']) !== 0) {
                                $doc = trim($r['tipo_doc'] . ' ' . $doc);
                            }
                            $v = (float)$r['valor'];
                            ?>
                            <tr>
                                <td class="col-tipo">
                                    <span class="badge <?= $badge[0] ?>"><?= $badge[1] ?></span>
                                    <?php if (($r['origem'] ?? '') === 'Abertura'): ?><span class="badge b-abert">Saldo Abertura</span><?php endif; ?>
                                </td>
                                <td data-label="Data"><?= dataPT($r['data']) ?></td>
                                <td data-label="Documento"><b><?= htmlspecialchars($doc) ?></b></td>
                                <td class="col-ext" data-label="Nº Externo"><?= htmlspecialchars($r['numero_externo'] ?? '') ?></td>
                                <td data-label="Valor" class="num <?= $v < 0 ? 'neg' : '' ?>"><?= formataValor($v) ?></td>
                                <td data-label="V. Corrigido" class="num <?= (float)$r['valor_corrigido'] < 0 ? 'neg' : '' ?>"><?= $r['grupo'] === 'pdct' && $r['valor_corrigido'] !== null ? formataValor($r['valor_corrigido']) : '' ?></td>
                                <td data-label="Observações" class="obs"><?= htmlspecialchars($r['observacoes'] ?? '') ?></td>
                            </tr>
                        <?php endforeach;
                    endif; ?>
                </tbody>
            </table>
        </div>

    </div>


    <script>
        // Menu do utilizador: em ecrãs táteis (tablet/telemóvel) abre com toque em vez de "hover"
        (function () {
            const menu = document.querySelector('.user-menu');
            const btn = menu ? menu.querySelector('.user-btn') : null;
            if (!btn) return;
            btn.addEventListener('click', function (e) {
                e.stopPropagation();
                menu.classList.toggle('aberto');
            });
            document.addEventListener('click', function () { menu.classList.remove('aberto'); });
        })();
    </script>
</body>
</html>
