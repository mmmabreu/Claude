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
function tabelaExiste($pdo, $tabela) {
    try {
        $st = $pdo->query("SHOW TABLES LIKE " . $pdo->quote($tabela));
        return $st && $st->rowCount() > 0;
    } catch (Exception $e) {
        return false;
    }
}

// --- TOTAIS (apenas documentos Ativos, iguais aos da página de Acertos) ---
$pdct_total = 0;   $pdct_n = 0;   $pdct_por_corrigir = 0;
$fatmab_total = 0; $fatmab_n = 0;
$fatfav_total = 0; $fatfav_n = 0;
$n_acertados = 0;
$ultimo_acerto = null;

try {
    if (tabelaExiste($pdo, 'lsjr_pdct')) {
        $r = $pdo->query("SELECT COALESCE(SUM(valor_corrigido), 0) AS total, COUNT(*) AS n,
                                 SUM(CASE WHEN valor_certo != 'Sim' OR valor_corrigido IS NULL THEN 1 ELSE 0 END) AS por_corrigir
                          FROM lsjr_pdct WHERE estado = 'Ativo'")->fetch(PDO::FETCH_ASSOC);
        $pdct_total = (float)$r['total']; $pdct_n = (int)$r['n']; $pdct_por_corrigir = (int)$r['por_corrigir'];
        $n_acertados += (int)$pdo->query("SELECT COUNT(*) FROM lsjr_pdct WHERE estado = 'Acertado'")->fetchColumn();
    }
    if (tabelaExiste($pdo, 'lsjr_fatmab')) {
        $r = $pdo->query("SELECT COALESCE(SUM(valor_original), 0) AS total, COUNT(*) AS n
                          FROM lsjr_fatmab WHERE estado = 'Ativo'")->fetch(PDO::FETCH_ASSOC);
        $fatmab_total = (float)$r['total']; $fatmab_n = (int)$r['n'];
        $n_acertados += (int)$pdo->query("SELECT COUNT(*) FROM lsjr_fatmab WHERE estado = 'Acertado'")->fetchColumn();
    }
    if (tabelaExiste($pdo, 'lsjr_fatfav')) {
        $r = $pdo->query("SELECT COALESCE(SUM(valor_original), 0) AS total, COUNT(*) AS n
                          FROM lsjr_fatfav WHERE estado = 'Ativo'")->fetch(PDO::FETCH_ASSOC);
        $fatfav_total = (float)$r['total']; $fatfav_n = (int)$r['n'];
        $n_acertados += (int)$pdo->query("SELECT COUNT(*) FROM lsjr_fatfav WHERE estado = 'Acertado'")->fetchColumn();
    }
    if (tabelaExiste($pdo, 'lsjr_acertos')) {
        $ultimo_acerto = $pdo->query("SELECT * FROM lsjr_acertos ORDER BY numero DESC, id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC) ?: null;
    }
} catch (Exception $e) {
    // Ignora erros caso as tabelas ainda não existam
}

$total_acertar = round($fatmab_total - $fatfav_total, 2);
if ($total_acertar > 0)     { $texto_acerto = 'A LSJR tem a pagar à m.Abreu'; $classe_saldo = 'pos'; }
elseif ($total_acertar < 0) { $texto_acerto = 'A m.Abreu tem a pagar à LSJR'; $classe_saldo = 'neg'; }
else                        { $texto_acerto = 'Contas acertadas';             $classe_saldo = 'zero'; }

// Barra de proporção MAB vs LSJR
$soma_abs = abs($fatmab_total) + abs($fatfav_total);
$perc_mab = $soma_abs > 0 ? round(abs($fatmab_total) / $soma_abs * 100) : 50;
?>
<!DOCTYPE html>
<html lang="pt-PT">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>LSJR (Gestão) - MAB</title>
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

        /* Topbar */
        .topbar { background: rgba(255,255,255,0.92); backdrop-filter: blur(8px); padding: 10px 40px; box-shadow: 0 1px 0 var(--borda); display: flex; justify-content: space-between; align-items: center; position: sticky; top: 0; z-index: 100; }
        .topbar-left .logo { height: 35px; display: block; }
        .topbar-right { display: flex; align-items: center; gap: 20px; }
        .btn-back { color: #576574; text-decoration: none; font-size: 15px; font-weight: 600; display: flex; align-items: center; gap: 8px; transition: color 0.2s; }
        .btn-back:hover { color: var(--laranja); }
        .user-menu { position: relative; }
        .user-btn { background: #fff; border: 1px solid var(--borda); border-radius: 30px; font-size: 14px; font-weight: 600; color: var(--texto); cursor: pointer; display: flex; align-items: center; gap: 10px; padding: 5px 14px 5px 5px; transition: 0.2s; }
        .user-btn:hover { box-shadow: 0 4px 12px rgba(0,0,0,0.06); }
        .user-avatar { background: var(--laranja); color: #fff; width: 28px; height: 28px; border-radius: 50%; display: flex; justify-content: center; align-items: center; font-size: 13px; }
        .dropdown-content { display: none; position: absolute; right: 0; top: 100%; background: #fff; min-width: 180px; box-shadow: 0 12px 30px rgba(0,0,0,0.1); border-radius: 10px; border: 1px solid var(--borda); overflow: hidden; margin-top: 6px; z-index: 200; }
        .user-menu:hover .dropdown-content { display: block; }
        .dropdown-content a { color: #576574; padding: 12px 20px; text-decoration: none; display: block; font-size: 14px; }
        .dropdown-content a:hover { background: var(--laranja-claro); color: var(--laranja); }

        .container { max-width: 1100px; margin: 32px auto; padding: 0 20px; }

        /* Cabeçalho da página */
        .page-header { display: flex; justify-content: space-between; align-items: flex-end; gap: 15px; margin-bottom: 24px; flex-wrap: wrap; }
        .eyebrow { font-size: 12px; font-weight: 700; letter-spacing: 1.5px; text-transform: uppercase; color: var(--laranja); margin-bottom: 4px; }
        .page-title { margin: 0; font-size: 28px; font-weight: 800; letter-spacing: -0.5px; }
        .page-sub { color: var(--suave); margin-top: 4px; }
        .chip-ultimo { background: #fff; border: 1px solid var(--borda); border-radius: 30px; padding: 8px 14px; font-size: 13px; color: #576574; display: flex; align-items: center; gap: 8px; text-decoration: none; transition: 0.2s; }
        .chip-ultimo:hover { border-color: #f5cba7; color: var(--laranja-esc); }
        .chip-ultimo i { color: var(--laranja); }

        /* Painel do saldo */
        .hero { background: linear-gradient(135deg, #2c3e50 0%, #34495e 60%, #3d566e 100%); border-radius: 18px; padding: 28px 30px; color: #fff; display: grid; grid-template-columns: 1.2fr 1fr; gap: 30px; box-shadow: 0 15px 35px rgba(44,62,80,0.18); position: relative; overflow: hidden; margin-bottom: 28px; }
        .hero::after { content: ''; position: absolute; right: -60px; top: -60px; width: 220px; height: 220px; border-radius: 50%; background: radial-gradient(circle, rgba(230,126,34,0.35), transparent 70%); pointer-events: none; }
        .hero-label { font-size: 12px; text-transform: uppercase; letter-spacing: 1.5px; opacity: 0.7; font-weight: 700; }
        .hero-valor { font-size: 40px; font-weight: 800; letter-spacing: -1px; margin: 6px 0 4px; }
        .hero-valor.pos { color: #58d68d; }
        .hero-valor.neg { color: #f1948a; }
        .hero-valor.zero { color: #fff; }
        .hero-texto { font-size: 14px; opacity: 0.85; display: flex; align-items: center; gap: 8px; }
        .hero-btn { margin-top: 18px; display: inline-flex; align-items: center; gap: 8px; background: var(--laranja); color: #fff; text-decoration: none; font-weight: 700; padding: 10px 18px; border-radius: 10px; transition: 0.2s; }
        .hero-btn:hover { background: var(--laranja-esc); transform: translateY(-1px); }

        .hero-dir { display: flex; flex-direction: column; justify-content: center; gap: 14px; position: relative; z-index: 1; }
        .linha-lado { display: flex; justify-content: space-between; align-items: baseline; }
        .linha-lado span { opacity: 0.8; display: flex; align-items: center; gap: 8px; }
        .linha-lado b { font-size: 18px; }
        .ponto { width: 10px; height: 10px; border-radius: 50%; display: inline-block; }
        .ponto.mab { background: #58d68d; } .ponto.lsjr { background: #f1948a; }
        .barra { height: 10px; border-radius: 10px; background: #f1948a; overflow: hidden; }
        .barra div { height: 100%; background: #58d68d; border-radius: 10px 0 0 10px; }
        .info-pdct { font-size: 12px; opacity: 0.7; border-top: 1px dashed rgba(255,255,255,0.25); padding-top: 10px; }

        /* Grelha de módulos */
        .seccao-titulo { font-size: 13px; font-weight: 800; color: var(--suave); text-transform: uppercase; letter-spacing: 1.2px; margin: 0 0 14px; }
        .menu-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(240px, 1fr)); gap: 18px; }
        .menu-card { --cor: var(--laranja); --cor-claro: var(--laranja-claro); background: #fff; border-radius: 16px; border: 1px solid var(--borda); padding: 22px; text-decoration: none; color: var(--texto); display: flex; flex-direction: column; gap: 18px; transition: all 0.2s ease; box-shadow: 0 2px 6px rgba(0,0,0,0.02); position: relative; }
        .menu-card:hover { transform: translateY(-4px); box-shadow: 0 14px 30px rgba(0,0,0,0.07); border-color: transparent; }
        .menu-card.verde { --cor: var(--verde); --cor-claro: var(--verde-claro); }
        .menu-card.vermelho { --cor: var(--vermelho); --cor-claro: var(--vermelho-claro); }
        .menu-card.cinza { --cor: #7f8c8d; --cor-claro: #f2f4f4; }
        .menu-card.azul { --cor: #2c3e50; --cor-claro: #ebedef; }
        .card-top { display: flex; justify-content: space-between; align-items: flex-start; }
        .menu-icon { background: var(--cor-claro); color: var(--cor); width: 48px; height: 48px; border-radius: 14px; display: flex; justify-content: center; align-items: center; font-size: 20px; }
        .seta { color: #cfd6de; transition: 0.2s; }
        .menu-card:hover .seta { color: var(--cor); transform: translateX(3px); }
        .menu-card h3 { margin: 0; font-size: 16px; font-weight: 800; }
        .menu-card p { margin: 4px 0 0; color: var(--suave); font-size: 12.5px; }
        .card-rodape { display: flex; justify-content: space-between; align-items: center; border-top: 1px solid #f1f3f6; padding-top: 14px; margin-top: auto; }
        .card-valor { font-size: 19px; font-weight: 800; color: var(--cor); }
        .card-qtd { background: var(--cor-claro); color: var(--cor); font-weight: 700; font-size: 12px; padding: 4px 10px; border-radius: 20px; }
        .tag-aviso { position: absolute; top: 14px; right: 44px; background: #fef5e7; color: #b9770e; border: 1px solid #f9e79f; font-size: 11px; font-weight: 700; padding: 3px 8px; border-radius: 20px; }

        .user-menu.aberto .dropdown-content { display: block; }

        /* ===== ECRÃS GRANDES (computador) ===== */
        @media (min-width: 1400px) {
            .container { max-width: 1280px; }
            .menu-grid { grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); }
        }

        /* ===== TABLET (até 1024 px) ===== */
        @media (max-width: 1024px) {
            .topbar { padding: 10px 24px; }
            .container { margin: 24px auto; }
            .hero { grid-template-columns: 1fr 1fr; gap: 22px; padding: 24px; }
            .hero-valor { font-size: 34px; }
            .menu-grid { grid-template-columns: repeat(2, 1fr); gap: 16px; }
            .menu-grid.tres { grid-template-columns: repeat(3, 1fr); }
            .menu-grid.tres .menu-card p { min-height: 32px; }
        }

        /* ===== TABLET VERTICAL / TELEMÓVEL GRANDE (até 768 px) ===== */
        @media (max-width: 768px) {
            .topbar { padding: 8px 15px; }
            .topbar-left .logo { height: 30px; }
            .btn-back span { display: none; }
            .btn-back { font-size: 18px; padding: 6px; }
            .container { margin: 18px auto; padding: 0 15px; }
            .page-header { flex-direction: column; align-items: flex-start; margin-bottom: 18px; }
            .page-title { font-size: 24px; }
            .chip-ultimo { width: 100%; justify-content: center; }
            .hero { grid-template-columns: 1fr; padding: 22px; border-radius: 16px; gap: 20px; }
            .hero-valor { font-size: 32px; }
            .hero-btn { width: 100%; justify-content: center; padding: 12px; }
            .hero::after { width: 160px; height: 160px; }
        }

        @media (max-width: 680px) {
            .menu-grid.tres { grid-template-columns: 1fr; }
            .menu-grid.tres .menu-card p { min-height: 0; }
        }

        /* ===== TELEMÓVEL (até 560 px) ===== */
        @media (max-width: 560px) {
            .user-nome { display: none; }
            .user-btn { padding: 4px; gap: 4px; }
            .user-btn .fa-caret-down { display: none; }
            .page-title { font-size: 22px; }
            .page-sub { font-size: 13px; }
            .hero { padding: 18px; }
            .hero-valor { font-size: 30px; }
            .linha-lado b { font-size: 16px; }
            .menu-grid { grid-template-columns: 1fr; gap: 12px; }
            /* Cartões compactos em linha (ícone + texto + valor) */
            .menu-card { padding: 16px; gap: 12px; display: grid; grid-template-columns: 44px 1fr; grid-template-areas: "icone texto" "rodape rodape"; align-items: center; }
            .card-top { grid-area: icone; }
            .card-top .seta { display: none; }
            .menu-card > div:not(.card-top):not(.card-rodape) { grid-area: texto; }
            .card-rodape { grid-area: rodape; padding-top: 10px; }
            .menu-icon { width: 44px; height: 44px; font-size: 18px; border-radius: 12px; }
            .menu-card:hover { transform: none; }
            .tag-aviso { top: 10px; right: 12px; }
            .card-valor { font-size: 17px; }
            .seccao-titulo { font-size: 12px; }
        }
    </style>
</head>
<body>

    <header class="topbar">
        <div class="topbar-left"><a href="index.php"><img src="mab.png" alt="MAB" class="logo"></a></div>
        <div class="topbar-right">
            <a href="gestao.php" class="btn-back" title="Voltar à Gestão Central"><i class="fa-solid fa-arrow-left"></i> <span>Voltar à Gestão Central</span></a>
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

        <div class="page-header">
            <div>
                <div class="eyebrow">Faturação</div>
                <h1 class="page-title">Módulo LSJR</h1>
                <div class="page-sub">Conta corrente entre a m.Abreu e a LSJR</div>
            </div>
            <?php if ($ultimo_acerto): ?>
                <a href="lsjr_acerto_pdf.php?id=<?= (int)$ultimo_acerto['id'] ?>" target="_blank" class="chip-ultimo" title="Imprimir o último acerto">
                    <i class="fa-solid fa-clock-rotate-left"></i>
                    Último acerto: nº <?= (int)$ultimo_acerto['numero'] ?> de <?= date('d/m/Y', strtotime($ultimo_acerto['data_acerto'])) ?>
                    <i class="fa-solid fa-print"></i>
                </a>
            <?php endif; ?>
        </div>

        <!-- PAINEL DO SALDO ATUAL -->
        <div class="hero">
            <div style="position: relative; z-index: 1;">
                <div class="hero-label">Total a acertar</div>
                <div class="hero-valor <?= $classe_saldo ?>"><?= formataValor(abs($total_acertar)) ?></div>
                <div class="hero-texto">
                    <i class="fa-solid <?= $classe_saldo === 'pos' ? 'fa-arrow-trend-up' : ($classe_saldo === 'neg' ? 'fa-arrow-trend-down' : 'fa-circle-check') ?>"></i>
                    <?= $texto_acerto ?>
                </div>
                <a href="lsjr_acertos.php" class="hero-btn"><i class="fa-solid fa-scale-balanced"></i> Ir para Acertos</a>
            </div>
            <div class="hero-dir">
                <div class="linha-lado"><span><i class="ponto mab"></i> A favor da m.Abreu</span><b><?= formataValor($fatmab_total) ?></b></div>
                <div class="barra"><div style="width: <?= $perc_mab ?>%;"></div></div>
                <div class="linha-lado"><span><i class="ponto lsjr"></i> A favor da LSJR</span><b><?= formataValor($fatfav_total) ?></b></div>
                <div class="info-pdct">
                    <i class="fa-solid fa-circle-info"></i> PDCT (informativo): <?= formataValor($pdct_total) ?> · <?= $pdct_n ?> reg.
                    <?php if ($pdct_por_corrigir > 0): ?> · <b style="color:#f8c471;">falta corrigir <?= $pdct_por_corrigir ?></b><?php endif; ?>
                </div>
            </div>
        </div>

        <h2 class="seccao-titulo">Documentos em aberto</h2>
        <div class="menu-grid tres">

            <a href="lsjr_pdct.php" class="menu-card cinza">
                <?php if ($pdct_por_corrigir > 0): ?><span class="tag-aviso"><?= $pdct_por_corrigir ?> por corrigir</span><?php endif; ?>
                <div class="card-top">
                    <div class="menu-icon"><i class="fa-solid fa-file-signature"></i></div>
                    <i class="fa-solid fa-arrow-right seta"></i>
                </div>
                <div>
                    <h3>Pedidos de Cotação</h3>
                    <p>PDCT · valores informativos</p>
                </div>
                <div class="card-rodape">
                    <span class="card-valor"><?= formataValor($pdct_total) ?></span>
                    <span class="card-qtd"><?= $pdct_n ?> reg.</span>
                </div>
            </a>

            <a href="lsjr_fatmab.php" class="menu-card verde">
                <div class="card-top">
                    <div class="menu-icon"><i class="fa-solid fa-file-invoice"></i></div>
                    <i class="fa-solid fa-arrow-right seta"></i>
                </div>
                <div>
                    <h3>A favor da m.Abreu</h3>
                    <p>Faturas, notas de crédito e folhas de serviço</p>
                </div>
                <div class="card-rodape">
                    <span class="card-valor"><?= formataValor($fatmab_total) ?></span>
                    <span class="card-qtd"><?= $fatmab_n ?> doc.</span>
                </div>
            </a>

            <a href="lsjr_fatfav.php" class="menu-card vermelho">
                <div class="card-top">
                    <div class="menu-icon"><i class="fa-solid fa-file-invoice-dollar"></i></div>
                    <i class="fa-solid fa-arrow-right seta"></i>
                </div>
                <div>
                    <h3>A favor da LSJR</h3>
                    <p>Faturas da LSJR à m.Abreu</p>
                </div>
                <div class="card-rodape">
                    <span class="card-valor"><?= formataValor($fatfav_total) ?></span>
                    <span class="card-qtd"><?= $fatfav_n ?> doc.</span>
                </div>
            </a>

        </div>

        <h2 class="seccao-titulo" style="margin-top: 30px;">Acertos e arquivo</h2>
        <div class="menu-grid">

            <a href="lsjr_acertos.php" class="menu-card">
                <div class="card-top">
                    <div class="menu-icon"><i class="fa-solid fa-scale-balanced"></i></div>
                    <i class="fa-solid fa-arrow-right seta"></i>
                </div>
                <div>
                    <h3>Acertos</h3>
                    <p>Efetuar acerto, histórico e impressão</p>
                </div>
                <div class="card-rodape">
                    <span class="card-valor"><?= formataValor(abs($total_acertar)) ?></span>
                    <span class="card-qtd"><?= $ultimo_acerto ? 'Último nº ' . (int)$ultimo_acerto['numero'] : 'Sem acertos' ?></span>
                </div>
            </a>

            <a href="lsjr_arquivo.php" class="menu-card azul">
                <div class="card-top">
                    <div class="menu-icon"><i class="fa-solid fa-box-archive"></i></div>
                    <i class="fa-solid fa-arrow-right seta"></i>
                </div>
                <div>
                    <h3>Arquivo (Acertados)</h3>
                    <p>PDCT, A favor MAB e A favor LSJR já acertados</p>
                </div>
                <div class="card-rodape">
                    <span class="card-valor" style="font-size: 15px;">Consultar</span>
                    <span class="card-qtd"><?= $n_acertados ?> reg.</span>
                </div>
            </a>

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
