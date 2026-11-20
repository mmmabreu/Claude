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
$f_tipo   = $_GET['tipo'] ?? 'todos';            // todos | fserv | fat | fatfav
$f_acerto = (int)($_GET['acerto'] ?? 0);         // 0 = todos os acertos
$f_texto  = trim($_GET['q'] ?? '');
if (!in_array($f_tipo, ['todos', 'fserv', 'fat', 'fatfav'])) $f_tipo = 'todos';

// --- LISTA DE ACERTOS ---
$acertos = [];
try {
    if (tabelaExiste($pdo, 'jdias_acertos')) {
        foreach ($pdo->query("SELECT numero, data_registo FROM jdias_acertos ORDER BY numero DESC")->fetchAll(PDO::FETCH_ASSOC) as $a) {
            $acertos[(int)$a['numero']] = $a;
        }
    }
} catch (Exception $e) {}

// --- DOCUMENTOS ACERTADOS (ocultos nas outras páginas) ---
$fontes = [
    'fserv'  => ['tabela' => 'jdias_fserv',
                 'sql' => "SELECT numero, id_acerto, seccao, 'FSERV' AS tipo, data_registo AS data, valor, valor_corrigido, pdct, NULL AS nome_cliente, observacoes FROM jdias_fserv WHERE estado = 'Acertado'"],
    'fat'    => ['tabela' => 'jdias_fat',
                 'sql' => "SELECT numero, id_acerto, seccao, IFNULL(tipo,'FAT') AS tipo, data_registo AS data, valor, NULL AS valor_corrigido, NULL AS pdct, NULL AS nome_cliente, observacoes FROM jdias_fat WHERE estado = 'Acertado'"],
    'fatfav' => ['tabela' => 'jdias_fatfav',
                 'sql' => "SELECT numero, id_acerto, NULL AS seccao, tipo, data_registo AS data, valor, NULL AS valor_corrigido, NULL AS pdct, nome_cliente, observacoes FROM jdias_fatfav WHERE estado = 'Acertado'"],
];

$linhas = [];
$totais = ['fserv' => [0, 0], 'fat' => [0, 0], 'fatfav' => [0, 0]]; // [valor, qtd]
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
                $alvo = mb_strtolower($r['tipo'] . ' ' . $r['numero'] . ' ' . ($r['pdct'] ?? '') . ' ' . ($r['nome_cliente'] ?? '') . ' ' . ($r['observacoes'] ?? ''));
                if (mb_strpos($alvo, mb_strtolower($f_texto)) === false) continue;
            }
            $r['grupo'] = $chave;
            $linhas[] = $r;
            // FSERV conta o V. Corrigido (igual ao acerto)
            $v = ($chave === 'fserv') ? (float)$r['valor_corrigido'] : (float)$r['valor'];
            $totais[$chave][0] += $v;
            $totais[$chave][1]++;
        }
    }
} catch (Exception $e) {
    $erro = $e->getMessage();
}

// Ordenar: acerto mais recente primeiro, depois por grupo e data do documento
usort($linhas, function ($a, $b) {
    $na = (int)$a['id_acerto'];
    $nb = (int)$b['id_acerto'];
    if ($na != $nb) return $nb <=> $na;
    $ordem = ['fserv' => 1, 'fat' => 2, 'fatfav' => 3];
    if ($a['grupo'] !== $b['grupo']) return $ordem[$a['grupo']] <=> $ordem[$b['grupo']];
    $c = strcmp((string)$a['data'], (string)$b['data']);
    return $c !== 0 ? $c : ((int)$a['numero'] <=> (int)$b['numero']);
});

function urlFiltro($alterar) {
    $p = array_merge(['tipo' => $_GET['tipo'] ?? 'todos', 'acerto' => $_GET['acerto'] ?? '', 'q' => $_GET['q'] ?? ''], $alterar);
    $p = array_filter($p, fn($v) => $v !== '' && $v !== null && $v !== 'todos' && $v !== '0' && $v !== 0);
    return 'jdias_arquivo.php' . ($p ? '?' . http_build_query($p) : '');
}
?>
<!DOCTYPE html>
<html lang="pt-PT">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>J. Dias - Arquivo (Acertados) - MAB</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* ===== BASE COMUM (regras do projeto: logo -> início, sem ícones de navegação, voltar só com símbolo, responsivo) ===== */
        :root {
            --laranja: #e67e22; --laranja-esc: #d35400; --laranja-claro: #fdf2e9;
            --verde: #27ae60; --verde-claro: #eafaf1;
            --vermelho: #c0392b; --vermelho-claro: #fdedec;
            --azul: #2980b9; --azul-claro: #ebf5fb;
            --roxo: #8e44ad; --roxo-claro: #f5eef8;
            --texto: #2c3e50; --suave: #7f8c8d; --borda: #e8ecf1; --fundo: #f4f6fa;
        }
        * { font-family: system-ui, -apple-system, "Segoe UI", sans-serif; box-sizing: border-box; }
        body { background: var(--fundo); margin: 0; color: var(--texto); font-size: 14px; padding-bottom: 60px; }

        /* Cabeçalho */
        .topbar { background: rgba(255,255,255,0.94); backdrop-filter: blur(8px); -webkit-backdrop-filter: blur(8px); padding: 10px 40px; box-shadow: 0 1px 0 var(--borda); display: flex; justify-content: space-between; align-items: center; position: sticky; top: 0; z-index: 100; }
        .topbar-left a { display: block; }
        .topbar-left .logo { height: 35px; display: block; }
        .topbar-right { display: flex; align-items: center; gap: 12px; }
        .btn-voltar { width: 40px; height: 40px; border-radius: 50%; background: #fff; border: 1px solid var(--borda); color: #576574; display: inline-flex; align-items: center; justify-content: center; text-decoration: none; font-size: 16px; transition: all 0.2s ease; box-shadow: 0 2px 6px rgba(0,0,0,0.04); flex-shrink: 0; }
        .btn-voltar:hover { background: var(--laranja-claro); color: var(--laranja); border-color: #f5cba7; transform: translateX(-2px); }
        .btn-voltar:active { transform: scale(0.94); }
        .user-menu { position: relative; }
        .user-btn { background: #fff; border: 1px solid var(--borda); border-radius: 30px; font-size: 14px; font-weight: 600; color: var(--texto); cursor: pointer; display: flex; align-items: center; gap: 10px; padding: 5px 14px 5px 5px; transition: 0.2s; }
        .user-btn:hover { box-shadow: 0 4px 12px rgba(0,0,0,0.06); }
        .user-avatar { background: var(--laranja); color: #fff; width: 28px; height: 28px; border-radius: 50%; display: flex; justify-content: center; align-items: center; font-size: 13px; }
        .dropdown-content { display: none; position: absolute; right: 0; top: 100%; background: #fff; min-width: 180px; box-shadow: 0 12px 30px rgba(0,0,0,0.1); border-radius: 10px; border: 1px solid var(--borda); overflow: hidden; margin-top: 6px; z-index: 200; }
        .user-menu:hover .dropdown-content, .user-menu.aberto .dropdown-content { display: block; }
        .dropdown-content a { color: #576574; padding: 12px 20px; text-decoration: none; display: block; font-size: 14px; }
        .dropdown-content a:hover { background: var(--laranja-claro); color: var(--laranja); }

        /* Página */
        .container { max-width: 1100px; margin: 32px auto; padding: 0 20px; }
        .page-head { display: flex; justify-content: space-between; align-items: flex-end; gap: 15px; flex-wrap: wrap; margin-bottom: 22px; }
        .eyebrow { font-size: 12px; font-weight: 700; letter-spacing: 1.5px; text-transform: uppercase; color: var(--laranja); margin-bottom: 4px; }
        .page-title { margin: 0; font-size: 26px; font-weight: 800; letter-spacing: -0.5px; }
        .page-sub { color: var(--suave); margin-top: 4px; }
        .chip { background: #fff; border: 1px solid var(--borda); border-radius: 30px; padding: 8px 14px; font-size: 12.5px; color: #576574; display: inline-flex; align-items: center; gap: 8px; text-decoration: none; transition: 0.2s; font-weight: 600; }
        a.chip:hover { border-color: #f5cba7; color: var(--laranja-esc); }
        .chip i { color: var(--laranja); }

        .alert { padding: 14px 16px; border-radius: 10px; margin-bottom: 18px; font-weight: 600; display: flex; gap: 10px; align-items: flex-start; }
        .alert-erro { background: var(--vermelho-claro); color: var(--vermelho); border: 1px solid #f5b7b1; }
        .alert-sucesso { background: var(--verde-claro); color: #1e8449; border: 1px solid #abebc6; }
        .alert-aviso { background: #fef5e7; color: #9a6400; border: 1px solid #f9e79f; }

        .card { background: #fff; border-radius: 16px; border: 1px solid var(--borda); box-shadow: 0 2px 6px rgba(0,0,0,0.02); padding: 22px; margin-bottom: 24px; }
        .card h2 { margin: 0 0 16px; font-size: 16px; font-weight: 800; }

        @media (max-width: 1024px) {
            .topbar { padding: 10px 24px; }
            .container { margin: 24px auto; }
        }
        @media (max-width: 768px) {
            .topbar { padding: 8px 15px; }
            .topbar-left .logo { height: 30px; }
            .btn-voltar { width: 38px; height: 38px; }
            .container { margin: 18px auto; padding: 0 12px; }
            .page-title { font-size: 22px; }
            .page-sub { font-size: 13px; }
            .card { padding: 16px; border-radius: 14px; }
        }
        @media (max-width: 560px) {
            .user-nome { display: none; }
            .user-btn { padding: 4px; gap: 4px; }
            .user-btn .fa-caret-down { display: none; }
            .page-head .chip { width: 100%; justify-content: center; }
        }

        .container { max-width: 1200px; }

        /* Separadores por tipo */
        .tabs { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 16px; }
        .tab { text-decoration: none; color: #576574; background: #fff; border: 1px solid var(--borda); padding: 8px 14px; border-radius: 30px; font-weight: 600; font-size: 13px; display: flex; align-items: center; gap: 8px; transition: 0.2s; }
        .tab:hover { border-color: #f5cba7; color: var(--laranja-esc); }
        .tab.ativo { background: var(--texto); color: #fff; border-color: var(--texto); }
        .tab .n { background: rgba(255,255,255,0.2); padding: 1px 8px; border-radius: 20px; font-size: 11px; }

        /* Filtros */
        .filtros { background: #fff; border: 1px solid var(--borda); border-radius: 14px; padding: 14px; display: flex; gap: 10px; flex-wrap: wrap; align-items: center; margin-bottom: 18px; }
        .filtros select, .filtros input { border: 1px solid #dcdde1; border-radius: 8px; padding: 9px 12px; font-size: 14px; background: #fff; color: var(--texto); font-family: inherit; }
        .filtros input { flex: 1; min-width: 200px; }
        .filtros button, .btn-limpar { background: var(--laranja); color: #fff; border: none; border-radius: 8px; padding: 10px 16px; font-weight: 700; cursor: pointer; text-decoration: none; font-size: 13px; display: inline-flex; align-items: center; gap: 6px; }
        .btn-limpar { background: #f1f2f6; color: #576574; }
        .btn-print { background: var(--laranja-claro); color: var(--laranja-esc); border: 1px solid #f5cba7; }

        /* Resumo */
        .resumo { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 14px; margin-bottom: 18px; }
        .res-card { background: #fff; border: 1px solid var(--borda); border-radius: 14px; padding: 14px 16px; border-left: 4px solid var(--c); }
        .res-card small { color: var(--suave); font-weight: 600; display: block; }
        .res-card b { font-size: 19px; color: var(--c); }
        .res-card b.neg { color: var(--vermelho); }
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
        .b-fserv { background: var(--laranja-claro); color: var(--laranja-esc); }
        .b-fat { background: var(--azul-claro); color: var(--azul); }
        .b-ncre { background: var(--vermelho-claro); color: var(--vermelho); }
        .b-abertura { background: var(--roxo-claro); color: var(--roxo); }
        .b-cliente { background: #fdedec; color: #c0392b; }
        .b-devolucao { background: #fef5e7; color: #b9770e; }
        .num { text-align: right; white-space: nowrap; font-weight: 700; }
        .neg { color: var(--vermelho); }
        .obs { color: var(--suave); font-size: 12.5px; max-width: 300px; }
        .vazio { text-align: center; color: var(--suave); padding: 40px; }

        @media (max-width: 1024px) {
            .obs { max-width: 200px; }
            th, td { padding: 9px 10px; }
            th.col-info, td.col-info { display: none; }
            table { min-width: 0; }
        }

        /* Telemóvel: tabela passa a cartões */
        @media (max-width: 768px) {
            .tabs { flex-wrap: nowrap; overflow-x: auto; padding-bottom: 4px; -webkit-overflow-scrolling: touch; scrollbar-width: none; }
            .tabs::-webkit-scrollbar { display: none; }
            .tab { flex-shrink: 0; }

            .filtros { flex-direction: column; align-items: stretch; padding: 12px; }
            .filtros select, .filtros input { width: 100%; min-width: 0; font-size: 16px; }
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
            td:empty { display: none; }
            td.col-info:not(:empty) { display: flex; }
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
    </style>
</head>
<body>

    <header class="topbar">
        <div class="topbar-left"><a href="index.php" title="Página inicial"><img src="mab.png" alt="MAB" class="logo"></a></div>
        <div class="topbar-right">
            <a href="jdias.php" class="btn-voltar" title="Voltar" aria-label="Voltar"><i class="fa-solid fa-arrow-left"></i></a>
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

        <div class="page-head">
            <div>
                <div class="eyebrow">J. Dias · Arquivo</div>
                <h1 class="page-title">Documentos acertados</h1>
                <div class="page-sub">Documentos que já entraram num acerto e por isso deixaram de aparecer nas listagens. Apenas consulta.</div>
            </div>
        </div>

        <?php if ($erro): ?>
            <div class="alert alert-erro"><i class="fa-solid fa-triangle-exclamation"></i> Erro: <?= htmlspecialchars($erro) ?></div>
        <?php endif; ?>

        <div class="tabs">
            <?php
            $tabs = ['todos' => ['Todos', 'fa-layer-group'], 'fserv' => ['Folhas de Serviço', 'fa-file-signature'], 'fat' => ['Faturas', 'fa-file-invoice-dollar'], 'fatfav' => ['Abertura + Clientes', 'fa-file-circle-check']];
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
                    <option value="<?= $id ?>" <?= $f_acerto === $id ? 'selected' : '' ?>>Acerto nº <?= $id ?> — <?= dataPT($a['data_registo']) ?></option>
                <?php endforeach; ?>
            </select>
            <input type="text" name="q" value="<?= htmlspecialchars($f_texto) ?>" placeholder="Procurar número, PDCT, cliente ou observações...">
            <button type="submit"><i class="fa-solid fa-magnifying-glass"></i> Procurar</button>
            <?php if ($f_acerto || $f_texto !== '' || $f_tipo !== 'todos'): ?>
                <a href="jdias_arquivo.php" class="btn-limpar"><i class="fa-solid fa-xmark"></i> Limpar</a>
            <?php endif; ?>
            <?php if ($f_acerto && isset($acertos[$f_acerto])): ?>
                <a href="jdias_acerto_pdf.php?id=<?= $f_acerto ?>" target="_blank" class="btn-limpar btn-print"><i class="fa-solid fa-print"></i> Imprimir acerto</a>
            <?php endif; ?>
        </form>

        <div class="resumo">
            <?php
            $cartoes = [
                'fserv'  => ['Folhas de Serviço (V. Corrigido)', '#e67e22', 'reg.'],
                'fat'    => ['Faturas', '#2980b9', 'doc.'],
                'fatfav' => ['Abertura + Faturas Cliente', '#8e44ad', 'reg.'],
            ];
            foreach ($cartoes as $k => [$rot, $cor, $un]):
                if ($f_tipo !== 'todos' && $f_tipo !== $k) continue; ?>
                <div class="res-card" style="--c: <?= $cor ?>;"><small><?= $rot ?></small><b class="<?= $totais[$k][0] < 0 ? 'neg' : '' ?>"><?= formataValor($totais[$k][0]) ?></b> <span><?= $totais[$k][1] ?> <?= $un ?></span></div>
            <?php endforeach; ?>
        </div>

        <div class="tabela-wrap">
            <table>
                <thead>
                    <tr>
                        <th class="col-tipo">Tipo</th>
                        <th>Data</th>
                        <th>Número</th>
                        <th class="col-info">PDCT / Cliente</th>
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
                                        <?= $a ? 'Acerto nº ' . $ida . ' de ' . dataPT($a['data_registo']) : 'Sem acerto associado' ?>
                                        <?php if ($a): ?>
                                            <a href="jdias_acerto_pdf.php?id=<?= $ida ?>" target="_blank"><i class="fa-solid fa-print"></i> Imprimir</a>
                                            <?php if (!$f_acerto): ?><a href="<?= htmlspecialchars(urlFiltro(['acerto' => $ida])) ?>"><i class="fa-solid fa-filter"></i> Só este</a><?php endif; ?>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endif;
                            $badges = ['FSERV' => 'b-fserv', 'FAT' => 'b-fat', 'NCRE' => 'b-ncre', 'Abertura' => 'b-abertura', 'Fatura' => 'b-cliente', 'Devolução' => 'b-devolucao'];
                            $badge = $badges[$r['tipo']] ?? 'b-fat';
                            $rotulo = $r['grupo'] === 'fatfav' && $r['tipo'] === 'Fatura' ? 'Fatura Cliente' : $r['tipo'];
                            $info = $r['grupo'] === 'fserv' ? ($r['pdct'] ? 'PDCT ' . $r['pdct'] : '') : ($r['nome_cliente'] ?? '');
                            $v = (float)$r['valor'];
                            ?>
                            <tr>
                                <td class="col-tipo"><span class="badge <?= $badge ?>"><?= htmlspecialchars($rotulo) ?></span></td>
                                <td data-label="Data"><?= dataPT($r['data']) ?></td>
                                <td data-label="Número"><b><?= htmlspecialchars($r['numero']) ?></b><?= $r['seccao'] ? ' <span style="color:#95a5a6; font-size:12px;">sec. ' . htmlspecialchars($r['seccao']) . '</span>' : '' ?></td>
                                <td class="col-info" data-label="<?= $r['grupo'] === 'fserv' ? 'PDCT' : 'Cliente' ?>"><?= htmlspecialchars($info) ?></td>
                                <td data-label="Valor" class="num <?= $v < 0 ? 'neg' : '' ?>"><?= formataValor($v) ?></td>
                                <td data-label="V. Corrigido" class="num <?= (float)$r['valor_corrigido'] < 0 ? 'neg' : '' ?>"><?= $r['grupo'] === 'fserv' && $r['valor_corrigido'] !== null ? formataValor($r['valor_corrigido']) : '' ?></td>
                                <td data-label="Observações" class="obs"><?= htmlspecialchars($r['observacoes'] ?? '') ?></td>
                            </tr>
                        <?php endforeach;
                    endif; ?>
                </tbody>
            </table>
        </div>

    </div>

    <script>
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
