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
    $st = $pdo->query("SHOW TABLES LIKE " . $pdo->quote($tabela));
    return $st && $st->rowCount() > 0;
}

$mensagem = ''; $tipo_mensagem = '';
if (isset($_GET['ok'])) {
    $mapa_msg = ['acerto' => 'Acerto de contas efetuado com sucesso!', 'revertido' => 'Acerto revertido: os documentos voltaram a ficar Ativos.'];
    $mensagem = $mapa_msg[$_GET['ok']] ?? ''; $tipo_mensagem = 'sucesso';
}

// --- 1. TABELA DO HISTÓRICO DE ACERTOS (criada automaticamente) ---
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS lsjr_acertos (
        id INT AUTO_INCREMENT PRIMARY KEY,
        numero INT NOT NULL,
        data_acerto DATE NOT NULL,
        total_pdct DECIMAL(12,2) DEFAULT 0.00,      -- informativo
        n_pdct INT DEFAULT 0,
        total_favor_mab DECIMAL(12,2) DEFAULT 0.00,
        n_favor_mab INT DEFAULT 0,
        total_favor_lsjr DECIMAL(12,2) DEFAULT 0.00,
        n_favor_lsjr INT DEFAULT 0,
        total_acerto DECIMAL(12,2) DEFAULT 0.00,     -- a favor MAB - a favor LSJR
        abertura_lado VARCHAR(10) DEFAULT NULL,      -- 'MAB', 'LSJR' ou NULL (acerto a zero)
        abertura_id INT DEFAULT NULL,                -- id da linha de saldo de abertura criada
        criado_por VARCHAR(100) DEFAULT NULL,
        criado_em DATETIME DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
} catch (Exception $e) {}

// --- 2. AÇÕES ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // 2a. EFETUAR ACERTO DAS CONTAS
    if (isset($_POST['efetuar_acerto'])) {
        try {
            foreach (['lsjr_fatmab', 'lsjr_fatfav'] as $t) {
                if (!tabelaExiste($pdo, $t)) throw new Exception("A tabela $t ainda não existe. Abra primeiro as páginas 'A favor MAB' e 'A favor de LSJR'.");
            }
            $tem_pdct = tabelaExiste($pdo, 'lsjr_pdct');

            $data_acerto = $_POST['data_acerto'] ?? date('Y-m-d');
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $data_acerto)) $data_acerto = date('Y-m-d');

            $pdo->beginTransaction();

            // Totais no momento do acerto (só documentos Ativos)
            $r_mab  = $pdo->query("SELECT COALESCE(SUM(valor_original),0) t, COUNT(*) n FROM lsjr_fatmab WHERE estado = 'Ativo'")->fetch(PDO::FETCH_ASSOC);
            $r_lsjr = $pdo->query("SELECT COALESCE(SUM(valor_original),0) t, COUNT(*) n FROM lsjr_fatfav WHERE estado = 'Ativo'")->fetch(PDO::FETCH_ASSOC);
            $r_pdct = $tem_pdct ? $pdo->query("SELECT COALESCE(SUM(valor_corrigido),0) t, COUNT(*) n FROM lsjr_pdct WHERE estado = 'Ativo'")->fetch(PDO::FETCH_ASSOC) : ['t' => 0, 'n' => 0];

            if ((int)$r_mab['n'] + (int)$r_lsjr['n'] + (int)$r_pdct['n'] === 0) {
                throw new Exception("Não existem documentos Ativos para acertar.");
            }

            $t_mab   = round((float)$r_mab['t'], 2);
            $t_lsjr  = round((float)$r_lsjr['t'], 2);
            $t_acert = round($t_mab - $t_lsjr, 2);
            $numero  = (int)$pdo->query("SELECT COALESCE(MAX(numero),0) + 1 FROM lsjr_acertos")->fetchColumn();

            // Registo no histórico
            $pdo->prepare("INSERT INTO lsjr_acertos (numero, data_acerto, total_pdct, n_pdct, total_favor_mab, n_favor_mab, total_favor_lsjr, n_favor_lsjr, total_acerto, criado_por)
                           VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
                ->execute([$numero, $data_acerto, round((float)$r_pdct['t'], 2), (int)$r_pdct['n'], $t_mab, (int)$r_mab['n'], $t_lsjr, (int)$r_lsjr['n'], $t_acert, $nome_completo]);
            $id_acerto = (int)$pdo->lastInsertId();

            // Todos os documentos Ativos passam a "Acertado" (saem das listagens e ficam ligados a este acerto)
            $pdo->prepare("UPDATE lsjr_fatmab SET estado = 'Acertado', id_acerto = ? WHERE estado = 'Ativo'")->execute([$id_acerto]);
            $pdo->prepare("UPDATE lsjr_fatfav SET estado = 'Acertado', id_acerto = ? WHERE estado = 'Ativo'")->execute([$id_acerto]);
            if ($tem_pdct) {
                $pdo->prepare("UPDATE lsjr_pdct SET estado = 'Acertado', id_acerto = ? WHERE estado = 'Ativo'")->execute([$id_acerto]);
            }

            // Saldo de abertura com a data do acerto, do lado a favor de quem ficou o valor
            $lado = null; $abertura_id = null;
            if (abs($t_acert) >= 0.005) {
                $lado = ($t_acert > 0) ? 'MAB' : 'LSJR';
                $tabela_ab = ($lado === 'MAB') ? 'lsjr_fatmab' : 'lsjr_fatfav';
                $valor_ab = abs($t_acert);
                $pdo->prepare("INSERT INTO $tabela_ab (seccao, tipo_doc, numero_documento, numero_externo, data_emissao, data_vencimento, valor_original, valor_pendente, estado, origem, observacoes)
                               VALUES ('1', 'ABERT', ?, '', ?, NULL, ?, ?, 'Ativo', 'Abertura', ?)")
                    ->execute(['ABERTURA ' . $numero, $data_acerto, $valor_ab, $valor_ab,
                               'Saldo de abertura do Acerto nº ' . $numero . ' de ' . date('d/m/Y', strtotime($data_acerto))]);
                $abertura_id = (int)$pdo->lastInsertId();
            }
            $pdo->prepare("UPDATE lsjr_acertos SET abertura_lado = ?, abertura_id = ? WHERE id = ?")->execute([$lado, $abertura_id, $id_acerto]);

            $pdo->commit();
            registrarLog($pdo, "Efetuou Acerto LSJR nº $numero (" . formataValor($t_acert) . ")", "LSJR - Acertos");
            header("Location: lsjr_acertos.php?ok=acerto");
            exit;
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $mensagem = "Erro ao efetuar o acerto: " . $e->getMessage(); $tipo_mensagem = 'erro';
        }
    }

    // 2b. REVERTER O ÚLTIMO ACERTO
    if (isset($_POST['reverter_id'])) {
        try {
            $id_rev = (int)$_POST['reverter_id'];
            $ultimo = $pdo->query("SELECT * FROM lsjr_acertos ORDER BY numero DESC, id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
            if (!$ultimo || (int)$ultimo['id'] !== $id_rev) throw new Exception("Só é possível reverter o acerto mais recente.");

            $pdo->beginTransaction();
            // Apaga o saldo de abertura criado por este acerto (só se ainda não tiver entrado noutro acerto)
            if (!empty($ultimo['abertura_id']) && !empty($ultimo['abertura_lado'])) {
                $tabela_ab = ($ultimo['abertura_lado'] === 'MAB') ? 'lsjr_fatmab' : 'lsjr_fatfav';
                $pdo->prepare("DELETE FROM $tabela_ab WHERE id = ? AND origem = 'Abertura' AND estado != 'Acertado'")->execute([$ultimo['abertura_id']]);
            }
            // Os documentos voltam a ficar Ativos
            foreach (['lsjr_fatmab', 'lsjr_fatfav', 'lsjr_pdct'] as $t) {
                if (tabelaExiste($pdo, $t)) {
                    $pdo->prepare("UPDATE $t SET estado = 'Ativo', id_acerto = NULL WHERE id_acerto = ? AND estado = 'Acertado'")->execute([$id_rev]);
                }
            }
            $pdo->prepare("DELETE FROM lsjr_acertos WHERE id = ?")->execute([$id_rev]);
            $pdo->commit();
            registrarLog($pdo, "Reverteu Acerto LSJR nº " . $ultimo['numero'], "LSJR - Acertos");
            header("Location: lsjr_acertos.php?ok=revertido");
            exit;
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $mensagem = "Erro ao reverter: " . $e->getMessage(); $tipo_mensagem = 'erro';
        }
    }
}

// --- 3. VALORES PENDENTES (apenas documentos com Estado 'Ativo') ---
// A FAVOR DA MAB  = Faturas / Notas de Crédito / Folhas de Serviço / Saldo de abertura (Valor)
//                   (os PDCT são apenas informativos: aparecem, mas NÃO entram no total)
// A FAVOR DA LSJR = Faturas da LSJR / Saldo de abertura (Valor)
// TOTAL A ACERTAR = A favor MAB - A favor LSJR
$pdct_total = 0;          $pdct_n = 0;          $pdct_por_corrigir = 0;
$fatmab_total = 0;        $fatmab_n = 0;
$fatfav_total = 0;        $fatfav_n = 0;

try {
    if (tabelaExiste($pdo, 'lsjr_pdct')) {
        $r = $pdo->query("SELECT COALESCE(SUM(valor_corrigido), 0) AS total, COUNT(*) AS n,
                                 SUM(CASE WHEN valor_certo != 'Sim' OR valor_corrigido IS NULL THEN 1 ELSE 0 END) AS por_corrigir
                          FROM lsjr_pdct WHERE estado = 'Ativo'")->fetch(PDO::FETCH_ASSOC);
        $pdct_total = (float)$r['total']; $pdct_n = (int)$r['n']; $pdct_por_corrigir = (int)$r['por_corrigir'];
    }
    if (tabelaExiste($pdo, 'lsjr_fatmab')) {
        $r = $pdo->query("SELECT COALESCE(SUM(valor_original), 0) AS total, COUNT(*) AS n
                          FROM lsjr_fatmab WHERE estado = 'Ativo'")->fetch(PDO::FETCH_ASSOC);
        $fatmab_total = (float)$r['total']; $fatmab_n = (int)$r['n'];
    }
    if (tabelaExiste($pdo, 'lsjr_fatfav')) {
        $r = $pdo->query("SELECT COALESCE(SUM(valor_original), 0) AS total, COUNT(*) AS n
                          FROM lsjr_fatfav WHERE estado = 'Ativo'")->fetch(PDO::FETCH_ASSOC);
        $fatfav_total = (float)$r['total']; $fatfav_n = (int)$r['n'];
    }
} catch (Exception $e) {
    // Ignora erros caso as tabelas ainda não existam
}

$total_favor_mab  = round($fatmab_total, 2); // PDCT não entram (informativos)
$total_favor_lsjr = round($fatfav_total, 2);
$total_acertar    = round($total_favor_mab - $total_favor_lsjr, 2);

if ($total_acertar > 0)      { $texto_acerto = 'A LSJR tem a pagar à MAB'; }
elseif ($total_acertar < 0)  { $texto_acerto = 'A MAB tem a pagar à LSJR'; }
else                         { $texto_acerto = 'Contas acertadas'; }

// --- 4. HISTÓRICO DE ACERTOS (com os documentos de cada acerto) ---
$historico = [];
$docs_por_acerto = [];
try {
    $historico = $pdo->query("SELECT * FROM lsjr_acertos ORDER BY numero DESC, id DESC")->fetchAll(PDO::FETCH_ASSOC);
    if (!empty($historico)) {
        $fontes = [
            'lsjr_fatmab' => ['A favor MAB',  "SELECT id_acerto, data_emissao AS data, numero_documento AS doc, valor_original AS valor, origem, observacoes FROM lsjr_fatmab WHERE estado = 'Acertado' AND id_acerto IS NOT NULL"],
            'lsjr_fatfav' => ['A favor LSJR', "SELECT id_acerto, data_emissao AS data, CONCAT(tipo_doc, ' ', IFNULL(numero_documento, '')) AS doc, valor_original AS valor, origem, observacoes FROM lsjr_fatfav WHERE estado = 'Acertado' AND id_acerto IS NOT NULL"],
            'lsjr_pdct'   => ['PDCT (info)',  "SELECT id_acerto, data_registo AS data, pdct AS doc, valor_corrigido AS valor, 'PDCT' AS origem, observacoes FROM lsjr_pdct WHERE estado = 'Acertado' AND id_acerto IS NOT NULL"],
        ];
        foreach ($fontes as $tabela => [$grupo, $sql]) {
            if (!tabelaExiste($pdo, $tabela)) continue;
            foreach ($pdo->query($sql . " ORDER BY data ASC")->fetchAll(PDO::FETCH_ASSOC) as $d) {
                $docs_por_acerto[(int)$d['id_acerto']][$grupo][] = $d;
            }
        }
    }
} catch (Exception $e) {}
$id_ultimo_acerto = !empty($historico) ? (int)$historico[0]['id'] : 0;
?>
<!DOCTYPE html>
<html lang="pt-PT">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Acertos - LSJR</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { font-family: system-ui, -apple-system, sans-serif; box-sizing: border-box; }
        body { background-color: #f7f9fc; margin: 0; color: #333; font-size: 14px; padding-bottom: 50px; }

        .topbar { background-color: #fff; padding: 15px 40px; box-shadow: 0 4px 20px rgba(0,0,0,0.05); display: flex; justify-content: space-between; align-items: center; position: sticky; top: 0; z-index: 100; border-bottom: 3px solid #e67e22;}
        .topbar-left .logo { height: 40px; }
        .topbar-right { display: flex; align-items: center; gap: 30px; }
        .btn-back { color: #576574; text-decoration: none; font-weight: 500; display: flex; align-items: center; gap: 6px; font-size: 15px; transition: 0.2s;}
        .btn-back:hover { color: #e67e22; }

        .user-menu { position: relative; display: inline-block;}
        .user-btn { background: none; border: none; font-size: 15px; font-weight: 600; color: #2c3e50; cursor: pointer; display: flex; align-items: center; gap: 8px; }
        .user-avatar { background: #e74c3c; color: white; width: 28px; height: 28px; border-radius: 50%; display: flex; justify-content: center; align-items: center; font-size: 14px; border: 2px solid #fadbd8; }
        .dropdown-content { display: none; position: absolute; right: 0; top: 100%; margin-top: 10px; background: #fff; min-width: 180px; box-shadow: 0 10px 30px rgba(0,0,0,0.1); border-radius: 8px; overflow: hidden; border: 1px solid #f1f2f6; z-index:200;}
        .dropdown-content a { color: #576574; padding: 12px 20px; text-decoration: none; display: block; font-size: 14px; }
        .dropdown-content a:hover { background: #fdf2f2; color: #e67e22; }
        .user-menu:hover .dropdown-content { display: block; }

        .container { max-width: 1100px; margin: 30px auto; padding: 0 20px; }
        .page-title { margin: 0 0 20px; color: #2c3e50; font-size: 24px; font-weight: 800; display: flex; align-items: center; gap: 10px; }
        .page-title i { color: #e67e22; }

        .alert { padding: 15px; border-radius: 8px; margin-bottom: 20px; text-align: center; font-weight: 600; }
        .alert-erro { background: #fdeded; color: #c0392b; border: 1px solid #f9cccc; }
        .alert-sucesso { background: #e8f5e9; color: #27ae60; border: 1px solid #c8e6c9; }

        .card { background: #fff; border-radius: 14px; border: 1px solid #e1e5ea; box-shadow: 0 4px 15px rgba(0,0,0,0.03); padding: 25px; margin-bottom: 30px; }
        .card h2 { margin: 0 0 20px; font-size: 17px; color: #2c3e50; font-weight: 800; }

        .grelha-totais { display: grid; grid-template-columns: repeat(3, 1fr); gap: 15px; }
        .caixa-total { background: #f8f9fa; border: 1px solid #e1e5ea; border-radius: 12px; padding: 22px 15px; text-align: center; display: flex; flex-direction: column; gap: 8px; }
        .caixa-total .titulo { font-size: 12px; font-weight: 700; text-transform: uppercase; color: #7f8c8d; letter-spacing: 0.3px; }
        .caixa-total .valor { font-size: 24px; font-weight: 800; color: #2c3e50; }
        .caixa-total .detalhe { font-size: 12px; color: #95a5a6; line-height: 1.6; }
        .caixa-total .detalhe a { color: #7f8c8d; text-decoration: none; border-bottom: 1px dotted #bdc3c7; }
        .caixa-total .detalhe a:hover { color: #e67e22; border-color: #e67e22; }

        .caixa-mab .valor { color: #27ae60; }
        .caixa-lsjr .valor { color: #2980b9; }
        .caixa-acertar { background: #fff8e8; border-color: #fbd9a5; }
        .caixa-acertar .titulo { color: #d35400; }
        .caixa-acertar .valor { color: #e67e22; font-size: 28px; }
        .caixa-acertar .quem { font-size: 13px; font-weight: 700; color: #d35400; }

        .info-pdct { font-size: 11px; color: #95a5a6; font-style: italic; border-top: 1px dashed #dcdde1; padding-top: 8px; margin-top: 2px; }
        .info-pdct a { color: #95a5a6; text-decoration: none; border-bottom: 1px dotted #bdc3c7; }
        .info-pdct a:hover { color: #e67e22; border-color: #e67e22; }
        .aviso-corrigir { background: #fdeded; color: #c0392b; border: 1px solid #f5b7b1; padding: 4px 8px; border-radius: 6px; font-size: 11px; font-weight: bold; display: inline-block; margin-top: 4px; }

        /* Botão de acerto */
        .barra-acerto { display: flex; gap: 12px; align-items: stretch; margin-top: 22px; flex-wrap: wrap; }
        .barra-acerto .data-acerto { display: flex; flex-direction: column; gap: 4px; }
        .barra-acerto label { font-size: 11px; font-weight: 700; color: #576574; text-transform: uppercase; }
        .barra-acerto input[type=date] { padding: 12px; border: 1px solid #dcdde1; border-radius: 8px; font-size: 14px; }
        .btn-acerto { flex: 1; min-width: 260px; background: #27ae60; color: #fff; border: none; border-radius: 8px; padding: 16px; font-size: 15px; font-weight: 800; text-transform: uppercase; cursor: pointer; display: flex; align-items: center; justify-content: center; gap: 10px; transition: 0.2s; }
        .btn-acerto:hover { background: #229954; }
        .btn-acerto:disabled { background: #bdc3c7; cursor: not-allowed; }

        /* Histórico */
        .tabela-hist { width: 100%; border-collapse: collapse; }
        .tabela-hist th { background: #f8f9fa; padding: 12px 10px; font-size: 11px; color: #576574; text-transform: uppercase; border: 1px solid #eef0f3; }
        .tabela-hist td { padding: 12px 10px; border: 1px solid #eef0f3; text-align: center; vertical-align: middle; }
        .parcelas { display: flex; justify-content: space-around; gap: 10px; flex-wrap: wrap; }
        .parcela { display: flex; flex-direction: column; font-size: 11px; color: #95a5a6; }
        .parcela b { font-size: 14px; color: #2c3e50; }
        .parcela.info b { color: #95a5a6; font-weight: 600; }
        .total-hist { font-size: 17px; font-weight: 800; color: #e67e22; }
        .sub-hist { font-size: 11px; color: #7f8c8d; }
        .btn-reverter { background: #e74c3c; color: #fff; border: none; border-radius: 6px; padding: 7px 12px; font-weight: 700; cursor: pointer; font-size: 12px; }
        .btn-reverter:hover { background: #c0392b; }
        .btn-ver { background: #f1f2f6; color: #576574; border: 1px solid #dcdde1; border-radius: 6px; padding: 6px 10px; font-weight: 600; cursor: pointer; font-size: 12px; margin-bottom: 5px; }
        .btn-ver:hover { background: #e1e5ea; }
        .btn-imprimir { display: inline-block; text-decoration: none; background: #fdf2e9; color: #d35400; border-color: #f5cba7; }
        .btn-imprimir:hover { background: #fae5d3; }
        tr.detalhe-acerto td { background: #fbfbfc; text-align: left; }
        .lista-docs { display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 15px; }
        .lista-docs h4 { margin: 0 0 6px; font-size: 12px; color: #576574; text-transform: uppercase; }
        .lista-docs table { width: 100%; border-collapse: collapse; font-size: 12px; }
        .lista-docs td { border: none; border-bottom: 1px dashed #eef0f3; padding: 4px 2px; text-align: left; }
        .lista-docs td.v { text-align: right; white-space: nowrap; }

        @media (max-width: 768px) {
            .topbar { padding: 15px 20px; }
            .btn-back span { display: none; }
            .grelha-totais { grid-template-columns: 1fr; }
            .tabela-hist { display: block; overflow-x: auto; }
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
                    <?= htmlspecialchars($primeiro_nome) ?>
                    <i class="fa-solid fa-chevron-down" style="font-size: 10px;"></i>
                </button>
                <div class="dropdown-content">
                    <a href="perfil.php"><i class="fa-solid fa-id-badge" style="width:20px;"></i> O meu perfil</a>
                    <a href="index.php?logout=1" style="color: #e74c3c; border-top: 1px solid #eee;"><i class="fa-solid fa-right-from-bracket" style="width:20px;"></i> Sair</a>
                </div>
            </div>
        </div>
    </header>

    <div class="container">
        <h1 class="page-title"><i class="fa-solid fa-scale-balanced"></i> Gestão de Acertos (LSJR)</h1>

        <?php if ($mensagem): ?>
            <div class="alert <?= $tipo_mensagem === 'sucesso' ? 'alert-sucesso' : 'alert-erro' ?>"><?= htmlspecialchars($mensagem) ?></div>
        <?php endif; ?>

        <div class="card">
            <h2>Valores Pendentes (Estado 'Ativo')</h2>

            <div class="grelha-totais">
                <!-- A FAVOR DA MAB -->
                <div class="caixa-total caixa-mab">
                    <span class="titulo">Total a favor da m.Abreu</span>
                    <span class="valor"><?= formataValor($total_favor_mab) ?></span>
                    <span class="detalhe">
                        <a href="lsjr_fatmab.php">Faturas / NC / FSERV</a>: <?= $fatmab_n ?> registo(s)
                    </span>
                    <span class="info-pdct" title="Valor apenas informativo: não entra no Total a favor da m.Abreu nem no Total a Acertar">
                        <i class="fa-solid fa-circle-info"></i> Informativo: <a href="lsjr_pdct.php">PDCT (V. Corrigido)</a> <?= formataValor($pdct_total) ?> · <?= $pdct_n ?> reg.
                    </span>
                    <?php if ($pdct_por_corrigir > 0): ?>
                        <span><span class="aviso-corrigir"><i class="fa-solid fa-triangle-exclamation"></i> Falta corrigir <?= $pdct_por_corrigir ?> PDCT!</span></span>
                    <?php endif; ?>
                </div>

                <!-- A FAVOR DA LSJR -->
                <div class="caixa-total caixa-lsjr">
                    <span class="titulo">Total a favor da LSJR</span>
                    <span class="valor"><?= formataValor($total_favor_lsjr) ?></span>
                    <span class="detalhe">
                        <a href="lsjr_fatfav.php">Faturas LSJR</a>: <?= $fatfav_n ?> registo(s)
                    </span>
                </div>

                <!-- TOTAL A ACERTAR -->
                <div class="caixa-total caixa-acertar">
                    <span class="titulo">Total a Acertar</span>
                    <span class="valor"><?= formataValor(abs($total_acertar)) ?></span>
                    <span class="quem"><?= htmlspecialchars($texto_acerto) ?></span>
                    <span class="detalhe">(a favor MAB − a favor LSJR)</span>
                </div>
            </div>

            <!-- EFETUAR ACERTO -->
            <form method="POST" class="barra-acerto" onsubmit="return confirmarAcerto();">
                <input type="hidden" name="efetuar_acerto" value="1">
                <div class="data-acerto">
                    <label for="data_acerto">Data do Acerto</label>
                    <input type="date" name="data_acerto" id="data_acerto" value="<?= date('Y-m-d') ?>" required>
                </div>
                <button type="submit" class="btn-acerto" <?= ($fatmab_n + $fatfav_n + $pdct_n) === 0 ? 'disabled' : '' ?>>
                    <i class="fa-solid fa-check-double"></i> Efetuar Acerto das Contas
                </button>
            </form>
        </div>

        <!-- HISTÓRICO -->
        <div class="card">
            <h2>Histórico de Acertos</h2>
            <?php if (empty($historico)): ?>
                <p style="color:#95a5a6; text-align:center; margin:10px 0;">Ainda não foi efetuado nenhum acerto.</p>
            <?php else: ?>
                <table class="tabela-hist">
                    <thead>
                        <tr>
                            <th style="width:6%;">Nº</th>
                            <th style="width:13%;">Data</th>
                            <th>Detalhe das Parcelas</th>
                            <th style="width:20%;">Total / Abertura Gerada</th>
                            <th style="width:14%;">Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($historico as $h):
                            $id_h = (int)$h['id'];
                            $docs = $docs_por_acerto[$id_h] ?? [];
                        ?>
                            <tr>
                                <td><b><?= (int)$h['numero'] ?></b></td>
                                <td><?= date('d/m/Y', strtotime($h['data_acerto'])) ?></td>
                                <td>
                                    <div class="parcelas">
                                        <span class="parcela">A favor MAB (<?= (int)$h['n_favor_mab'] ?>):<b><?= formataValor($h['total_favor_mab']) ?></b></span>
                                        <span class="parcela">A favor LSJR (<?= (int)$h['n_favor_lsjr'] ?>):<b><?= formataValor($h['total_favor_lsjr']) ?></b></span>
                                        <span class="parcela info">PDCT info (<?= (int)$h['n_pdct'] ?>):<b><?= formataValor($h['total_pdct']) ?></b></span>
                                    </div>
                                </td>
                                <td>
                                    <div class="total-hist"><?= formataValor(abs($h['total_acerto'])) ?></div>
                                    <div class="sub-hist">
                                        <?php if ($h['abertura_lado'] === 'MAB'): ?>
                                            Abertura a favor da m.Abreu
                                        <?php elseif ($h['abertura_lado'] === 'LSJR'): ?>
                                            Abertura a favor da LSJR
                                        <?php else: ?>
                                            Contas acertadas (sem abertura)
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td>
                                    <button type="button" class="btn-ver" onclick="verDocumentos(<?= $id_h ?>, this)"><i class="fa-solid fa-list"></i> Documentos</button>
                                    <a href="lsjr_acerto_pdf.php?id=<?= $id_h ?>" target="_blank" class="btn-ver btn-imprimir" title="Imprimir o acerto detalhado (PDF)"><i class="fa-solid fa-print"></i> Imprimir</a>
                                    <?php if ($id_h === $id_ultimo_acerto): ?>
                                        <form method="POST" style="margin:0;" onsubmit="return confirm('Reverter o Acerto nº <?= (int)$h['numero'] ?>?\n\nTodos os documentos deste acerto voltam a ficar Ativos e o saldo de abertura gerado é apagado.');">
                                            <input type="hidden" name="reverter_id" value="<?= $id_h ?>">
                                            <button type="submit" class="btn-reverter"><i class="fa-solid fa-rotate-left"></i> Reverter</button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <tr class="detalhe-acerto" id="detalhe_<?= $id_h ?>" style="display:none;">
                                <td colspan="5">
                                    <?php if (empty($docs)): ?>
                                        <span style="color:#95a5a6;">Sem documentos associados.</span>
                                    <?php else: ?>
                                        <div class="lista-docs">
                                            <?php foreach (['A favor MAB', 'A favor LSJR', 'PDCT (info)'] as $grupo): if (empty($docs[$grupo])) continue; ?>
                                                <div>
                                                    <h4><?= htmlspecialchars($grupo) ?> (<?= count($docs[$grupo]) ?>)</h4>
                                                    <table>
                                                        <?php foreach ($docs[$grupo] as $d): ?>
                                                            <tr>
                                                                <td style="width:70px; color:#95a5a6;"><?= !empty($d['data']) ? date('d/m/Y', strtotime($d['data'])) : '' ?></td>
                                                                <td><?= htmlspecialchars(trim($d['doc'] ?? '') !== '' ? $d['doc'] : ($d['observacoes'] ?? '')) ?><?= $d['origem'] === 'Abertura' ? ' <i style="color:#d35400;">(abertura)</i>' : '' ?></td>
                                                                <td class="v"><?= formataValor($d['valor']) ?></td>
                                                            </tr>
                                                        <?php endforeach; ?>
                                                    </table>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>

    <script>
        const RESUMO_ACERTO = {
            mab: <?= json_encode(formataValor($total_favor_mab)) ?>,
            lsjr: <?= json_encode(formataValor($total_favor_lsjr)) ?>,
            total: <?= json_encode(formataValor(abs($total_acertar))) ?>,
            quem: <?= json_encode($texto_acerto) ?>,
            pdct: <?= (int)$pdct_n ?>
        };
        function confirmarAcerto() {
            const dataEl = document.getElementById('data_acerto');
            const dataPT = dataEl.value ? dataEl.value.split('-').reverse().join('/') : '';
            return confirm(
                'EFETUAR ACERTO DAS CONTAS com data ' + dataPT + '?\n\n' +
                'A favor da m.Abreu: ' + RESUMO_ACERTO.mab + '\n' +
                'A favor da LSJR: ' + RESUMO_ACERTO.lsjr + '\n' +
                'Total a acertar: ' + RESUMO_ACERTO.total + ' (' + RESUMO_ACERTO.quem + ')\n\n' +
                '• Todos os documentos Ativos (incluindo ' + RESUMO_ACERTO.pdct + ' PDCT) passam a "Acertado" e ficam no histórico.\n' +
                '• O valor do acerto é criado como SALDO DE ABERTURA, com a data do acerto, na página de quem fica com o valor a favor.'
            );
        }
        function verDocumentos(id, btn) {
            const linha = document.getElementById('detalhe_' + id);
            const aberta = linha.style.display !== 'none';
            linha.style.display = aberta ? 'none' : 'table-row';
            btn.innerHTML = aberta ? '<i class="fa-solid fa-list"></i> Documentos' : '<i class="fa-solid fa-xmark"></i> Fechar';
        }
    </script>
</body>
</html>
