<?php
// FORÇAR EXIBIÇÃO DE ERROS BÁSICOS
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

try {
    session_start();
    require_once 'db.php';

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

    $mensagem = '';
    $tipo_mensagem = '';
    $mensagem_erro = null;

    if (isset($_GET['editado']) && $_GET['editado'] == 1) {
        $mensagem = "Documentos guardados com sucesso!";
        $tipo_mensagem = "sucesso";
    }
    // Aviso de documentos repetidos que a gravação recusou
    $aviso_repetidos = $_SESSION['lsjr_fatmab_repetidos'] ?? [];
    unset($_SESSION['lsjr_fatmab_repetidos']);

    // Converte "1 234,56" / "1234.56" / 1234.56 em número
    function parseValorPT($v) {
        $v = trim(str_replace(["\xc2\xa0", ' ', '€'], '', (string)$v));
        if ($v === '') return 0.0;
        if (strpos($v, ',') !== false) { $v = str_replace('.', '', $v); $v = str_replace(',', '.', $v); }
        return (float)$v;
    }

    // --- 1. CRIAÇÃO AUTOMÁTICA DA TABELA NA BASE DE DADOS ---
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS lsjr_fatmab (
            id INT AUTO_INCREMENT PRIMARY KEY,
            seccao VARCHAR(10) DEFAULT '1',
            tipo_doc VARCHAR(20) DEFAULT 'FAT',
            numero_documento VARCHAR(30) DEFAULT NULL,
            numero_externo VARCHAR(30) DEFAULT NULL,
            data_emissao DATE DEFAULT NULL,
            data_vencimento DATE DEFAULT NULL,
            valor_original DECIMAL(12,2) DEFAULT 0.00,
            valor_pendente DECIMAL(12,2) DEFAULT 0.00,
            estado VARCHAR(20) DEFAULT 'Ativo',
            origem VARCHAR(20) DEFAULT 'Manual',
            observacoes TEXT,
            id_acerto INT DEFAULT NULL,
            UNIQUE KEY uk_documento (tipo_doc, seccao, numero_documento)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
    } catch (Exception $e) {
        // Ignora caso a tabela já exista
    }

    // --- 1b. MIGRAÇÃO (uma única vez): Faturas e Notas de Crédito que tinham sido gravadas
    //         na página dos PDCT passam para aqui, onde passam a pertencer
    $mensagem_migracao = '';
    try {
        $tem_pdct = $pdo->query("SHOW TABLES LIKE 'lsjr_pdct'");
        if ($tem_pdct && $tem_pdct->rowCount() > 0) {
            $a_mover = $pdo->query("SELECT * FROM lsjr_pdct WHERE pdct REGEXP '^(F|N/?C)[A-Za-z/.]*[ 0-9]'")->fetchAll(PDO::FETCH_ASSOC);
            if (!empty($a_mover)) {
                $pdo->beginTransaction();
                $stmt_mig = $pdo->prepare("INSERT IGNORE INTO lsjr_fatmab (seccao, tipo_doc, numero_documento, numero_externo, data_emissao, data_vencimento, valor_original, valor_pendente, estado, origem, observacoes, id_acerto)
                                           VALUES (?, ?, ?, '', ?, NULL, ?, ?, ?, 'Importado', ?, ?)");
                $stmt_del_pdct = $pdo->prepare("DELETE FROM lsjr_pdct WHERE pdct = ?");
                foreach ($a_mover as $mv) {
                    $ref = trim($mv['pdct']);
                    $tipo_mv = strtoupper(strtok($ref, ' '));
                    $stmt_mig->execute([
                        (string)$mv['seccao'], $tipo_mv, $ref, $mv['data_registo'],
                        $mv['valor'], $mv['valor'], $mv['estado'], $mv['observacoes'] ?? '', $mv['id_acerto'] ?? null
                    ]);
                    $stmt_del_pdct->execute([$mv['pdct']]);
                }
                $pdo->commit();
                $mensagem_migracao = count($a_mover) . " fatura(s)/nota(s) de crédito que estavam nos PDCT foram movidas para esta página.";
            }
        }
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
    }

    // --- 2. LÓGICA DE GRAVAÇÃO (POST) ---
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        try {
            $pdo->beginTransaction();

            // Linhas apagadas no ecrã (nunca apaga registos já Acertados)
            $deleted = json_decode($_POST['deleted_ids'] ?? '[]', true);
            if (is_array($deleted)) {
                $deleted = array_values(array_filter(array_map('intval', $deleted), fn($x) => $x > 0));
                if (!empty($deleted)) {
                    $placeholders = implode(',', array_fill(0, count($deleted), '?'));
                    $pdo->prepare("DELETE FROM lsjr_fatmab WHERE id IN ($placeholders) AND estado != 'Acertado'")->execute($deleted);
                }
            }

            $repetidos_gravacao = [];
            $stmt_existe = $pdo->prepare("SELECT COUNT(*) FROM lsjr_fatmab WHERE UPPER(tipo_doc) = UPPER(?) AND UPPER(seccao) = UPPER(?) AND numero_documento = ?");
            $stmt_ins = $pdo->prepare("INSERT INTO lsjr_fatmab (seccao, tipo_doc, numero_documento, numero_externo, data_emissao, data_vencimento, valor_original, valor_pendente, estado, origem, observacoes)
                                       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt_upd = $pdo->prepare("UPDATE lsjr_fatmab SET seccao=?, tipo_doc=?, numero_documento=?, numero_externo=?, data_emissao=?, data_vencimento=?, valor_original=?, valor_pendente=?, estado=?, origem=?, observacoes=?
                                       WHERE id=? AND estado != 'Acertado'");

            $n = isset($_POST['row_id']) && is_array($_POST['row_id']) ? count($_POST['row_id']) : 0;
            for ($i = 0; $i < $n; $i++) {
                $id_linha   = (int)($_POST['row_id'][$i] ?? 0);
                $seccao     = trim($_POST['seccao'][$i] ?? '1');
                $tipo_doc   = trim($_POST['tipo_doc'][$i] ?? '');
                $num_doc    = trim($_POST['numero_documento'][$i] ?? '');
                $num_ext    = trim($_POST['numero_externo'][$i] ?? '');
                $dt_emissao = !empty($_POST['data_emissao'][$i]) ? $_POST['data_emissao'][$i] : null;
                $dt_venc    = !empty($_POST['data_vencimento'][$i]) ? $_POST['data_vencimento'][$i] : null;
                $v_original = parseValorPT($_POST['valor_original'][$i] ?? '0');
                $v_pendente = parseValorPT($_POST['valor_pendente'][$i] ?? '0');
                $estado     = in_array($_POST['estado'][$i] ?? '', ['Ativo', 'Inativo']) ? $_POST['estado'][$i] : 'Ativo';
                // Origem: Importado (ficheiro), Abertura (saldo gerado por um Acerto) ou Manual
                $origem     = in_array($_POST['origem'][$i] ?? '', ['Importado', 'Abertura']) ? $_POST['origem'][$i] : 'Manual';
                $obs        = trim($_POST['observacoes'][$i] ?? '');

                // Ignora linhas completamente vazias
                if ($num_doc === '' && $num_ext === '' && $v_original == 0 && $v_pendente == 0 && $obs === '' && !$dt_emissao) continue;

                if ($tipo_doc === '') $tipo_doc = 'FAT';
                if ($seccao === '') $seccao = '1';
                $num_doc_db = ($num_doc !== '') ? $num_doc : null; // vários manuais sem nº não chocam entre si

                $dados = [$seccao, $tipo_doc, $num_doc_db, $num_ext, $dt_emissao, $dt_venc, $v_original, $v_pendente, $estado, $origem, $obs];
                if ($id_linha > 0) {
                    $dados[] = $id_linha;
                    $stmt_upd->execute($dados);
                } else {
                    // SALVAGUARDA: se este documento já existe (mesmo que Acertado), não o volta a criar
                    if ($num_doc_db !== null) {
                        $stmt_existe->execute([$tipo_doc, $seccao, $num_doc_db]);
                        if ($stmt_existe->fetchColumn()) { $repetidos_gravacao[] = (stripos($num_doc_db, $tipo_doc) === 0) ? $num_doc_db : "$tipo_doc $num_doc_db"; continue; }
                    }
                    $stmt_ins->execute($dados);
                }
            }

            $pdo->commit();
            if (!empty($repetidos_gravacao)) { $_SESSION['lsjr_fatmab_repetidos'] = array_values(array_unique($repetidos_gravacao)); }
            header("Location: lsjr_fatmab.php?editado=1");
            exit;

        } catch (PDOException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            if ($e->getCode() == 23000) {
                $mensagem_erro = "Erro: Existe um documento repetido (mesmo Doc., Secção e Nº Documento). Corrija a linha repetida e volte a gravar.";
            } else {
                $mensagem_erro = "Erro na gravação: " . $e->getMessage();
            }
        }
    }

    // --- 3. CARREGAR REGISTOS EXISTENTES (Apenas não acertados) ---
    $registos = $pdo->query("SELECT * FROM lsjr_fatmab WHERE estado != 'Acertado' ORDER BY (data_emissao IS NULL), data_emissao ASC, tipo_doc ASC, numero_documento ASC, id ASC")->fetchAll(PDO::FETCH_ASSOC);
    $registos_ocultos = $pdo->query("SELECT COUNT(*) FROM lsjr_fatmab WHERE estado = 'Acertado'")->fetchColumn();

    // Todos os documentos já gravados (chave TIPO|SECÇÃO|Nº -> estado), incluindo os Acertados, para a importação não os duplicar
    $docs_gravados = [];
    foreach ($pdo->query("SELECT tipo_doc, seccao, numero_documento, estado FROM lsjr_fatmab WHERE numero_documento IS NOT NULL AND numero_documento != ''")->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $docs_gravados[mb_strtoupper(trim($r['tipo_doc']) . '|' . trim($r['seccao']) . '|' . trim($r['numero_documento']), 'UTF-8')] = $r['estado'];
    }

} catch (Throwable $e) {
    die("Erro Fatal: " . htmlspecialchars($e->getMessage()));
}
?>
<!DOCTYPE html>
<html lang="pt-PT">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>A favor MAB - LSJR</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Leitura de ficheiros Excel (.xls / .xlsx) e CSV no browser -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
    <style>
        * { font-family: system-ui, -apple-system, sans-serif; box-sizing: border-box; }
        body { background-color: #f7f9fc; margin: 0; color: #333; font-size: 13px; padding-bottom: 100px; }

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

        .container { max-width: 1600px; margin: 30px auto; padding: 0 20px; }
        .page-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; gap: 15px; flex-wrap: wrap; }
        .page-title { margin: 0; color: #2c3e50; font-size: 24px; font-weight: 800; display: flex; align-items: center; gap: 10px; }
        .page-title i { color: #e67e22; }

        .card { background: #fff; border-radius: 12px; box-shadow: 0 4px 15px rgba(0,0,0,0.04); border: 1px solid #e1e5ea; overflow-x: auto; margin-bottom: 30px; padding: 25px;}

        /* TABELA TIPO EXCEL */
        .table-auto { width: 100%; border-collapse: collapse; table-layout: fixed; min-width: 1100px; }
        .table-auto th { background: #f8f9fa; padding: 12px 4px; border: 1px solid #eee; font-size: 10px; color: #576574; text-transform: uppercase; text-align: center; }
        .table-auto td { border: 1px solid #f1f2f6; padding: 0; }
        .table-auto input, .table-auto select { border: none; width: 100%; padding: 10px 4px; text-align: center; background: transparent; font-size: 12px; font-family: inherit; transition: all 0.2s; border-radius: 0; }
        .table-auto input:focus, .table-auto select:focus { background: #fff; box-shadow: inset 0 0 0 2px #e67e22; outline: none; }
        .table-auto select { cursor: pointer; appearance: auto; -webkit-appearance: auto; }
        .table-auto .inp-numdoc { font-weight: bold; color: #2c3e50; background: #fafafa; }
        .readonly-text { color: #7f8c8d; font-weight: 700; background-color: #f8f9fa !important; }

        /* Etiqueta de origem (Importado / Manual) */
        .tag-origem { display: block; font-size: 9px; font-weight: 700; text-transform: uppercase; text-align: center; padding: 1px 0 3px; }
        .tag-importado { color: #27ae60; }
        .tag-manual { color: #95a5a6; }
        .tag-abertura { color: #d35400; }
        tr.linha-abertura td { background: #fff8e8; }
        .linha-vencida td { background: #fffaf3; }

        .btn-add-line { background: #e67e22; color: #fff; border: none; padding: 10px 20px; border-radius: 8px; cursor: pointer; display: inline-flex; align-items: center; gap: 8px; font-size: 13px; font-weight: 600; margin-top: 15px; transition: background 0.2s; }
        .btn-add-line:hover { background: #d35400; }
        .btn-import-csv { background: #27ae60; color: #fff; }
        .btn-import-csv:hover { background: #219653; }

        .btn-row-action { background: none; border: none; cursor: pointer; font-size: 14px; padding: 6px; border-radius: 6px; transition: all 0.2s; margin: 0 1px; }
        .btn-del-row { color: #e74c3c; } .btn-del-row:hover { background: #fdeded; }
        .btn-add-row { color: #27ae60; } .btn-add-row:hover { background: #eafaf1; }
        .btn-dup-row { color: #3498db; } .btn-dup-row:hover { background: #ebf5fb; }

        .floating-action-bar { position: fixed; bottom: 20px; right: 20px; background: #fff; padding: 15px 25px; border-radius: 12px; box-shadow: 0 5px 20px rgba(0,0,0,0.15); display: flex; align-items: center; gap: 15px; z-index: 1000; border: 1px solid #e1e5ea; justify-content: space-between; }
        .btn-save { background: #e67e22; color: #fff; padding: 12px 25px; border-radius: 8px; border: none; font-weight: 700; cursor: pointer; transition: background 0.2s; font-size: 14px; display: flex; align-items: center; gap: 8px; }
        .btn-save:hover { background: #d35400; }

        .alert { padding: 15px; border-radius: 8px; margin-bottom: 20px; text-align: center; font-weight: 600; }
        .alert-erro { background: #fdeded; color: #c0392b; border: 1px solid #f9cccc; }
        .alert-sucesso { background: #e8f5e9; color: #27ae60; border: 1px solid #c8e6c9; }

        @media (max-width: 768px) {
            .topbar { padding: 15px 20px; }
            .btn-back span { display: none; }
            .floating-action-bar { flex-direction: column; right: 10px; left: 10px; gap: 10px; }
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

        <?php if ($mensagem): ?>
            <div class="alert <?= $tipo_mensagem === 'sucesso' ? 'alert-sucesso' : 'alert-erro' ?>"><?= htmlspecialchars($mensagem, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>

        <?php if ($mensagem_migracao): ?>
            <div class="alert alert-sucesso"><i class="fa-solid fa-right-left"></i> <?= htmlspecialchars($mensagem_migracao, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>

        <?php if (!empty($aviso_repetidos)): ?>
            <div class="alert alert-erro" style="background:#fff8e1; color:#8a6d00; border-color:#f5d76e;">
                <i class="fa-solid fa-triangle-exclamation"></i> <?= count($aviso_repetidos) ?> documento(s) já existia(m) e não foi(ram) gravado(s) de novo:
                <?= htmlspecialchars(implode(', ', $aviso_repetidos), ENT_QUOTES, 'UTF-8') ?>
            </div>
        <?php endif; ?>

        <?php if ($mensagem_erro): ?>
            <div class="alert alert-erro"><i class="fa-solid fa-triangle-exclamation"></i> <?= htmlspecialchars($mensagem_erro, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>

        <div class="page-header">
            <h1 class="page-title"><i class="fa-solid fa-file-invoice"></i> A favor MAB (Faturas e Notas de Crédito)</h1>
            <?php if($registos_ocultos > 0): ?>
                <div style="background: #fdf2e9; color: #e67e22; padding: 8px 15px; border-radius: 6px; font-size: 12px; font-weight: bold; border: 1px solid #f5cba7;">
                    <i class="fa-solid fa-eye-slash"></i> <?= $registos_ocultos ?> registo(s) Acertado(s) oculto(s)
                </div>
            <?php endif; ?>
        </div>

        <form method="POST" id="fatmabForm">
            <input type="hidden" name="deleted_ids" id="deleted_ids" value="[]">

            <div class="card">
                <table class="table-auto">
                    <thead>
                        <tr>
                            <th style="width: 5%;">Secção</th>
                            <th style="width: 7%;">Doc.</th>
                            <th style="width: 8%;">Nº Documento</th>
                            <th style="width: 8%;">Nº Externo</th>
                            <th style="width: 10%;">Emissão</th>
                            <th style="width: 10%;">Vencimento</th>
                            <th style="width: 9%;">Original (€)</th>
                            <th style="width: 9%;">Pendente (€)</th>
                            <th style="width: 8%;">Estado</th>
                            <th style="width: 16%;">Observações</th>
                            <th style="width: 10%;">Ações</th>
                        </tr>
                    </thead>
                    <tbody id="tabela_fatmab"></tbody>
                    <tfoot>
                        <tr>
                            <td colspan="6" style="text-align: right; font-weight: 800; color: #576574; padding-right: 15px;">TOTAIS:</td>
                            <td style="font-weight: 800; color: #2c3e50; text-align: center; font-size: 14px; background: #fdfdfd;" id="soma_original">0,00 €</td>
                            <td style="font-weight: 800; color: #e67e22; text-align: center; font-size: 14px; background: #fdfdfd;" id="soma_pendente">0,00 €</td>
                            <td colspan="3"></td>
                        </tr>
                    </tfoot>
                </table>

                <div style="display: flex; gap: 10px; margin-top: 15px; flex-wrap: wrap;">
                    <button type="button" onclick="addLinha()" class="btn-add-line" title="Adicionar no Final"><i class="fa-solid fa-plus"></i> Adicionar Linha Manual</button>
                    <button type="button" class="btn-add-line btn-import-csv" onclick="document.getElementById('file_pendentes').click()">
                        <i class="fa-solid fa-file-excel"></i> Importar Pendentes / Folhas de Serviço (XLS / CSV)
                    </button>
                    <input type="file" id="file_pendentes" accept=".xls,.xlsx,.csv" style="display:none" onchange="importarPendentes(this)">
                </div>
            </div>

            <div class="floating-action-bar">
                <div style="display: flex; gap: 20px; margin-right: auto;">
                    <div style="text-align: right;">
                        <span style="font-size: 10px; font-weight: bold; color: #576574; display: block; text-transform: uppercase;">Total Original</span>
                        <span id="float_soma_original" style="font-size: 18px; font-weight: 800; color: #2c3e50;">0,00 €</span>
                    </div>
                    <div style="text-align: right; padding-left: 20px; border-left: 2px solid #e1e5ea;">
                        <span style="font-size: 10px; font-weight: bold; color: #576574; display: block; text-transform: uppercase;">Total Pendente</span>
                        <span id="float_soma_pendente" style="font-size: 18px; font-weight: 800; color: #e67e22;">0,00 €</span>
                    </div>
                </div>
                <button type="button" class="btn-save" style="background:#576574;" onclick="window.open('lsjr_fatmab_pdf.php', '_blank')"><i class="fa-solid fa-print"></i> IMPRIMIR PDF</button>
                <button type="submit" class="btn-save" onclick="guardarScrollY()"><i class="fa-solid fa-floppy-disk"></i> GRAVAR ALTERAÇÕES</button>
            </div>
        </form>
    </div>

    <script>
        // Documentos já gravados (TIPO|SECÇÃO|Nº -> estado), incluindo os Acertados
        const DOCS_GRAVADOS = <?= json_encode((object)$docs_gravados, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        const REGISTOS_BD = <?= json_encode($registos, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?: '[]' ?>;
        let deletedIds = [];
        const ID_TABELA = 'tabela_fatmab';

        // Guarda a posição do scroll
        function guardarScrollY() { sessionStorage.setItem('lsjrFatmabScrollY', window.scrollY); }

        // ---------- FORMATAÇÃO NUMÉRICA (igual às outras páginas) ----------
        function strToNum(s) {
            if (s === null || s === undefined || s === '') return 0;
            if (typeof s === 'number') return s;
            let str = s.toString().replace(/[\s €]/g, '').trim();
            let hasComma = str.indexOf(',') !== -1;
            let hasDot = str.indexOf('.') !== -1;
            if (hasComma && hasDot) {
                if (str.lastIndexOf(',') > str.lastIndexOf('.')) { str = str.replace(/\./g, '').replace(',', '.'); }
                else { str = str.replace(/,/g, ''); }
            } else if (hasComma) {
                str = str.replace(/,/g, '.');
            }
            str = str.replace(/[^\d\.-]/g, '');
            return parseFloat(str) || 0;
        }
        function numToStr(n) { return Number(n || 0).toLocaleString('pt-PT', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
        function forceComma(el) {
            if (el.value.indexOf('.') !== -1) {
                let pos = el.selectionStart;
                el.value = el.value.replace(/\./g, ',');
                el.setSelectionRange(pos, pos);
            }
        }
        function formatField(el) { el.value = numToStr(strToNum(el.value)); calcularTotais(); }
        function escapeHTML(str) {
            str = (str === null || str === undefined) ? '' : String(str);
            return str.replace(/[&<>'"]/g, t => ({ '&':'&amp;', '<':'&lt;', '>':'&gt;', "'":'&#39;', '"':'&quot;' }[t]));
        }

        // ---------- TOTAIS ----------
        function calcularTotais() {
            let tOrig = 0, tPend = 0;
            document.querySelectorAll('#tabela_fatmab tr').forEach(tr => {
                const o = tr.querySelector('.inp-original'); const p = tr.querySelector('.inp-pendente');
                if (o) tOrig += strToNum(o.value);
                if (p) tPend += strToNum(p.value);
            });
            const sO = numToStr(tOrig) + ' €', sP = numToStr(tPend) + ' €';
            document.getElementById('soma_original').innerText = sO;
            document.getElementById('soma_pendente').innerText = sP;
            document.getElementById('float_soma_original').innerText = sO;
            document.getElementById('float_soma_pendente').innerText = sP;
        }

        // ---------- LINHAS ----------
        function getRowHTML(d = {}) {
            const id = d.id || '';
            const sec = String(d.seccao || '2');
            const tipo = d.tipo_doc || 'FAT';
            const est = d.estado || 'Ativo';
            const origem = ['Importado', 'Abertura'].includes(d.origem) ? d.origem : 'Manual';
            // o valor vindo do ficheiro ou de um Acerto (saldo de abertura) não se altera à mão
            const bloqueado = origem !== 'Manual';
            const vOrig = (d.valor_original !== undefined && d.valor_original !== null && d.valor_original !== '') ? numToStr(strToNum(d.valor_original)) : '0,00';
            const vPend = (d.valor_pendente !== undefined && d.valor_pendente !== null && d.valor_pendente !== '') ? numToStr(strToNum(d.valor_pendente)) : '0,00';
            const secOpts = ['1', '2', 'M'].map(s => `<option value="${s}" ${sec === s ? 'selected' : ''}>${s}</option>`).join('');

            return `
                <td>
                    <input type="hidden" name="row_id[]" value="${escapeHTML(id)}">
                    <input type="hidden" name="origem[]" value="${origem}">
                    <select name="seccao[]">${secOpts}</select>
                </td>
                <td><input type="text" name="tipo_doc[]" value="${escapeHTML(tipo)}" list="lista_tipos_doc"></td>
                <td><input type="text" name="numero_documento[]" class="inp-numdoc" value="${escapeHTML(d.numero_documento)}"></td>
                <td><input type="text" name="numero_externo[]" value="${escapeHTML(d.numero_externo)}"></td>
                <td><input type="date" name="data_emissao[]" value="${escapeHTML(d.data_emissao)}"></td>
                <td><input type="date" name="data_vencimento[]" value="${escapeHTML(d.data_vencimento)}"></td>
                <td><input type="text" name="valor_original[]" class="inp-original ${bloqueado ? 'readonly-text' : ''}" value="${vOrig}" ${bloqueado ? 'readonly' : 'oninput="forceComma(this); calcularTotais();" onblur="formatField(this)"'}></td>
                <td><input type="text" name="valor_pendente[]" class="inp-pendente" value="${vPend}" oninput="forceComma(this); calcularTotais();" onblur="formatField(this)"></td>
                <td>
                    <select name="estado[]">
                        <option value="Ativo" ${est === 'Ativo' ? 'selected' : ''}>Ativo</option>
                        <option value="Inativo" ${est === 'Inativo' ? 'selected' : ''}>Inativo</option>
                    </select>
                </td>
                <td><input type="text" name="observacoes[]" value="${escapeHTML(d.observacoes)}" placeholder="..."></td>
                <td style="text-align:center;">
                    <button type="button" class="btn-row-action btn-add-row" onclick="insertRowAfter(this)" title="Nova Linha Abaixo"><i class="fa-solid fa-plus"></i></button>
                    <button type="button" class="btn-row-action btn-dup-row" onclick="duplicateRow(this)" title="Duplicar Linha"><i class="fa-solid fa-copy"></i></button>
                    <button type="button" class="btn-row-action btn-del-row" onclick="removeRow(this)" title="Apagar Linha"><i class="fa-solid fa-trash-can"></i></button>
                    <span class="tag-origem ${origem === 'Importado' ? 'tag-importado' : (origem === 'Abertura' ? 'tag-abertura' : 'tag-manual')}">${origem === 'Abertura' ? 'Saldo Abertura' : origem}</span>
                </td>`;
        }

        function criarLinha(d = {}) {
            const tr = document.createElement('tr');
            tr.innerHTML = getRowHTML(d);
            if (d.origem === 'Abertura') tr.classList.add('linha-abertura');
            return tr;
        }
        function addLinha(d = {}) {
            document.getElementById('tabela_fatmab').appendChild(criarLinha(d));
            calcularTotais();
        }
        function insertRowAfter(btn) {
            const atual = btn.closest('tr');
            atual.parentNode.insertBefore(criarLinha(), atual.nextSibling);
        }
        // Duplica a linha como linha MANUAL nova (sem o Nº Documento, para não repetir o documento)
        function duplicateRow(btn) {
            const atual = btn.closest('tr');
            const v = n => { const el = atual.querySelector(`[name="${n}[]"]`); return el ? el.value : ''; };
            const nova = criarLinha({
                seccao: v('seccao'), tipo_doc: v('tipo_doc'), numero_documento: '', numero_externo: v('numero_externo'),
                data_emissao: v('data_emissao'), data_vencimento: v('data_vencimento'),
                valor_original: v('valor_original'), valor_pendente: v('valor_pendente'),
                estado: v('estado'), observacoes: v('observacoes'), origem: 'Manual'
            });
            atual.parentNode.insertBefore(nova, atual.nextSibling);
            nova.querySelector('[name="numero_documento[]"]').focus();
            calcularTotais();
        }
        function removeRow(btn) {
            const tr = btn.closest('tr');
            const id = tr.querySelector('[name="row_id[]"]').value;
            const numDoc = tr.querySelector('[name="numero_documento[]"]').value;
            if (id !== '') {
                const eAbertura = tr.querySelector('[name="origem[]"]').value === 'Abertura';
                const msg = eAbertura
                    ? 'ATENÇÃO: esta linha é o SALDO DE ABERTURA gerado por um Acerto.\nPara o anular, o correto é "Reverter" o acerto na página Acertos.\n\nApagar mesmo assim?'
                    : 'Tem a certeza que deseja apagar este documento' + (numDoc ? ' (Nº ' + numDoc + ')' : '') + '?';
                if (!confirm(msg)) return;
                deletedIds.push(id);
                document.getElementById('deleted_ids').value = JSON.stringify(deletedIds);
            }
            tr.remove();
            calcularTotais();
        }

        // ==========================================
        // IMPORTAÇÃO DA "LISTAGEM DE PENDENTES" (XLS, XLSX ou CSV)
        // Colunas usadas: Secção, Doc., Nº. Documento, Nº. Externo, Emissão, Vencimento, Original, Pendente
        // Funciona com o XLS (cabeçalho numa linha à parte) e com o CSV do relatório
        // (cabeçalho e dados na MESMA linha). Cabeçalhos, "Entidade:", totais e rodapé são ignorados.
        // ==========================================
        function parseDate(dateStr) {
            if (!dateStr) return '';
            dateStr = String(dateStr).trim();
            if (/^\d{4}-\d{2}-\d{2}$/.test(dateStr)) return dateStr;
            const p = dateStr.split(/[\/\-\.]/);
            if (p.length === 3 && p[2].length === 4) return `${p[2]}-${p[1].padStart(2, '0')}-${p[0].padStart(2, '0')}`;
            return '';
        }
        function dataExcelParaISO(v) {
            if (v === null || v === undefined || v === '') return '';
            if (v instanceof Date && !isNaN(v)) return `${v.getFullYear()}-${String(v.getMonth() + 1).padStart(2, '0')}-${String(v.getDate()).padStart(2, '0')}`;
            if (typeof v === 'number') {
                const d = new Date(Math.round((v - 25569) * 86400000));
                return `${d.getUTCFullYear()}-${String(d.getUTCMonth() + 1).padStart(2, '0')}-${String(d.getUTCDate()).padStart(2, '0')}`;
            }
            return parseDate(v);
        }
        // Lê XLS/XLSX diretamente; o CSV é lido como texto (UTF-8 ou Windows-1252) e SEM conversão
        // automática de datas, para "06/10/2026" não ser confundido com 10 de junho
        function lerFolha(file) {
            return new Promise((resolve, reject) => {
                if (typeof XLSX === 'undefined') { reject(new Error('Biblioteca de leitura de Excel não carregou. Verifique a ligação à internet.')); return; }
                const ehCsv = /\.(csv|txt)$/i.test(file.name || '');
                const reader = new FileReader();
                reader.onload = e => {
                    try {
                        const bytes = new Uint8Array(e.target.result);
                        let wb;
                        if (ehCsv) {
                            let texto;
                            try { texto = new TextDecoder('utf-8', { fatal: true }).decode(bytes); }
                            catch (errUtf) { texto = new TextDecoder('windows-1252').decode(bytes); }
                            wb = XLSX.read(texto.replace(/^﻿/, ''), { type: 'string', raw: true });
                        } else {
                            wb = XLSX.read(bytes, { type: 'array', codepage: 1252 });
                        }
                        resolve(XLSX.utils.sheet_to_json(wb.Sheets[wb.SheetNames[0]], { header: 1, raw: true, defval: '' }));
                    } catch (err) { reject(err); }
                };
                reader.onerror = () => reject(new Error('Não foi possível ler o ficheiro.'));
                reader.readAsArrayBuffer(file);
            });
        }

        const normTexto = t => String(t ?? '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '').trim();
        const chaveRotulo = t => normTexto(t).replace(/[^a-z]/g, ''); // "Nº. Documento" -> "ndocumento"
        const ROTULOS_PENDENTES = ['seccao', 'doc', 'ndocumento', 'nexterno', 'emissao', 'vencimento', 'original', 'pendente'];

        // Devolve a lista de documentos da listagem, ou null se o ficheiro não for uma "Listagem de Pendentes"
        function interpretarListagemPendentes(linhas) {
            let cabecalho = null;
            let reconhecido = false;
            const docs = [];
            const ehTipo = v => /^[A-Za-z][A-Za-z\/\.0-9]*$/.test(String(v ?? '').trim());
            const ehSeccao = v => /^(1|2|M)$/i.test(String(v ?? '').trim());

            linhas.forEach(cols => {
                const rot = {};
                cols.forEach((c, idx) => { const k = chaveRotulo(c); if (ROTULOS_PENDENTES.includes(k) && rot[k] === undefined) rot[k] = idx; });
                const temCabecalho = rot.doc !== undefined && rot.ndocumento !== undefined && rot.original !== undefined;
                if (temCabecalho) reconhecido = true;

                let base = null, desvio = 0;
                if (temCabecalho) {
                    // CSV: rótulos e dados na mesma linha -> os dados vêm depois do último rótulo
                    const ultimoRotulo = Math.max(...Object.values(rot));
                    for (let j = ultimoRotulo + 1; j < cols.length - 1; j++) {
                        if (ehTipo(cols[j]) && /\d/.test(String(cols[j + 1] ?? '')) && ehSeccao(cols[j - 1])) {
                            base = rot; desvio = j - rot.doc; break;
                        }
                    }
                    if (!base) { cabecalho = rot; return; } // linha só com o cabeçalho (XLS)
                } else if (cabecalho) {
                    base = cabecalho; desvio = 0;
                } else {
                    return;
                }

                const val = k => (base[k] !== undefined) ? cols[base[k] + desvio] : '';
                const tipo = String(val('doc') ?? '').trim();
                const num = String(val('ndocumento') ?? '').trim();
                const temValor = String(val('original') ?? '').trim() !== '' || String(val('pendente') ?? '').trim() !== '';
                if (!tipo || !ehTipo(tipo) || !/\d/.test(num) || !temValor) return;

                docs.push({
                    seccao: String(val('seccao') ?? '').trim() || '1',
                    tipo_doc: tipo,
                    numero_documento: num,
                    numero_externo: String(val('nexterno') ?? '').trim(),
                    data_emissao: dataExcelParaISO(val('emissao')),
                    data_vencimento: dataExcelParaISO(val('vencimento')),
                    valor_original: strToNum(val('original')),
                    valor_pendente: strToNum(val('pendente'))
                });
            });
            return reconhecido ? docs : null;
        }

        // ==========================================
        // EXTRACTO DE ORÇAMENTOS / ENCOMENDAS POR DOCUMENTO (Folhas de Serviço FSERV)
        // Colunas: Sec. | Documento | Data Doc. | Entidade | Mercadoria | Descontos | Outros+IEC | Impostos | Acertos | Total Doc. | Retenções
        // No CSV do relatório os rótulos e os dados vêm na mesma linha e NÃO pela mesma ordem,
        // por isso os dados são lidos a partir da posição do Documento.
        // O documento vem com o tipo repetido ("FSERV  FSERV 118126/23") e fica "FSERV 118126/23".
        // ==========================================
        const REGEX_DOC_EXTRACTO = /^([A-Za-z][A-Za-z\/\.]*)\s+(?:\1\s+)?([A-Za-z0-9\-\.]*)\s*\/\s*(\d+)$/;
        function limparDocumento(txt) {
            const t = String(txt ?? '').trim().replace(/\s+/g, ' ');
            const m = t.match(REGEX_DOC_EXTRACTO);
            return m ? { ref: `${m[1].toUpperCase()} ${m[2]}/${m[3]}`.replace(' /', '/'), tipo: m[1].toUpperCase() } : null;
        }
        function interpretarExtractoDocumentos(linhas) {
            let reconhecido = false;
            let cabecalho = null; // XLS: cabeçalho numa linha à parte
            const docs = [];
            linhas.forEach(cols => {
                const rot = cols.map(normTexto);
                const iRotDoc = rot.findIndex(c => c === 'documento');
                const iRotTotal = rot.findIndex(c => c.startsWith('total doc'));
                const temRotulos = iRotDoc !== -1 && iRotTotal !== -1;
                if (temRotulos) reconhecido = true;

                const iDoc = cols.findIndex(c => limparDocumento(c) !== null);
                if (iDoc === -1) {
                    if (temRotulos) cabecalho = {
                        sec: rot.findIndex(c => c === 'sec.' || c === 'sec' || c === 'seccao'),
                        doc: iRotDoc,
                        data: rot.findIndex(c => c.startsWith('data doc')),
                        total: iRotTotal
                    };
                    return;
                }
                const d = limparDocumento(cols[iDoc]);
                let sec, data, total;
                if (temRotulos || !cabecalho) {
                    // CSV: Sec. antes do Documento; depois: Data, Entidade, Mercadoria, Descontos, Outros, Impostos, Acertos, TOTAL, Retenções
                    sec = cols[iDoc - 1];
                    data = cols[iDoc + 1];
                    total = cols[iDoc + 8];
                } else {
                    sec = cabecalho.sec !== -1 ? cols[cabecalho.sec] : '';
                    data = cabecalho.data !== -1 ? cols[cabecalho.data] : '';
                    total = cols[cabecalho.total];
                }
                docs.push({
                    seccao: String(sec ?? '').trim() || '1',
                    tipo_doc: d.tipo,
                    numero_documento: d.ref,
                    numero_externo: '',
                    data_emissao: dataExcelParaISO(data),
                    data_vencimento: '',
                    valor_original: strToNum(total),
                    valor_pendente: strToNum(total)
                });
            });
            return reconhecido ? docs : null;
        }

        function importarPendentes(input) {
            const file = input.files[0];
            input.value = '';
            if (!file) return;

            lerFolha(file).then(linhas => {
                // Confirma que é a listagem certa para esta página (Pendentes de Clientes)
                const textoFicheiro = normTexto(linhas.slice(0, 40).map(l => l.join(' ')).join(' '));
                if (textoFicheiro.includes('pendentes de fornecedores')) {
                    alert('Este ficheiro é uma "Listagem de Pendentes de Fornecedores".\n\nImporte-o na página "A favor de LSJR" do módulo LSJR.');
                    return;
                }
                // Tipos de ficheiro aceites nesta página:
                //  1) Listagem de Pendentes de Clientes (Faturas, Notas de Crédito...)
                //  2) Extracto de Orçamentos / Encomendas por Documento (Folhas de Serviço FSERV realizadas pela MAB)
                let docs = interpretarListagemPendentes(linhas);
                if (docs === null) docs = interpretarExtractoDocumentos(linhas);
                if (docs === null) {
                    alert('Ficheiro não reconhecido.\n\nUse a "Listagem de Pendentes de Clientes" ou o "Extracto de Orçamentos/Encomendas por Documento" (Folhas de Serviço).');
                    return;
                }
                // Os PDCT (Pedidos de Cotação) têm página própria
                const pdctIgnorados = docs.filter(d => String(d.tipo_doc).toUpperCase() === 'PDCT');
                if (pdctIgnorados.length > 0) {
                    docs = docs.filter(d => String(d.tipo_doc).toUpperCase() !== 'PDCT');
                    alert(`${pdctIgnorados.length} PDCT (Pedidos de Cotação) não foram importados aqui.\n\nImporte-os na página "Pedidos de Cotação (PDCT)" do módulo LSJR.`);
                    if (docs.length === 0) return;
                }

                // Documentos já presentes na grelha
                const chave = (t, s, n) => `${String(t).trim()}|${String(s).trim()}|${String(n).trim()}`.toUpperCase();
                const naGrelha = new Set();
                document.querySelectorAll('#' + ID_TABELA + ' tr').forEach(tr => {
                    const n = tr.querySelector('[name="numero_documento[]"]').value.trim();
                    if (n !== '') naGrelha.add(chave(tr.querySelector('[name="tipo_doc[]"]').value, tr.querySelector('[name="seccao[]"]').value, n));
                });

                const tbody = document.getElementById(ID_TABELA);
                let importados = 0;
                const listaRepetidos = [];
                const porTipo = {};

                docs.forEach(d => {
                    const k = chave(d.tipo_doc, d.seccao, d.numero_documento);
                    const nome = d.numero_documento.toUpperCase().startsWith(d.tipo_doc.toUpperCase()) ? d.numero_documento : `${d.tipo_doc} ${d.numero_documento}`;
                    // SALVAGUARDA: não duplicar documentos que já estão na grelha ou já foram gravados (mesmo Acertados)
                    if (naGrelha.has(k)) { listaRepetidos.push(nome + ' (já está na listagem)'); return; }
                    if (Object.prototype.hasOwnProperty.call(DOCS_GRAVADOS, k)) {
                        listaRepetidos.push(nome + (DOCS_GRAVADOS[k] === 'Acertado' ? ' (já gravado e Acertado)' : ' (já gravado)'));
                        return;
                    }

                    // Notas de Crédito (NC, NCRE, N/C...) abatem: ficam sempre com valor negativo
                    if (/^N\/?C/i.test(d.tipo_doc)) {
                        if (d.valor_original > 0) d.valor_original = -d.valor_original;
                        if (d.valor_pendente > 0) d.valor_pendente = -d.valor_pendente;
                    }

                    // Remove a linha vazia inicial, se for a única
                    const atuais = tbody.querySelectorAll('tr');
                    if (atuais.length === 1 && atuais[0].querySelector('[name="row_id[]"]').value === '' &&
                        atuais[0].querySelector('[name="numero_documento[]"]').value.trim() === '' &&
                        strToNum(atuais[0].querySelector('.inp-original').value) === 0) {
                        atuais[0].remove();
                    }

                    tbody.appendChild(criarLinha(Object.assign({}, d, { estado: 'Ativo', origem: 'Importado' })));
                    naGrelha.add(k);
                    porTipo[d.tipo_doc.toUpperCase()] = (porTipo[d.tipo_doc.toUpperCase()] || 0) + 1;
                    importados++;
                });

                calcularTotais();
                if (listaRepetidos.length > 0) {
                    alert(`Atenção: ${listaRepetidos.length} documento(s) do ficheiro já existe(m) e NÃO foi(ram) importado(s) para não duplicar:\n\n` +
                          listaRepetidos.slice(0, 25).join('\n') + (listaRepetidos.length > 25 ? `\n... e mais ${listaRepetidos.length - 25}` : ''));
                }
                if (importados > 0) {
                    const resumo = Object.keys(porTipo).map(t => `${t}: ${porTipo[t]}`).join(', ');
                    alert(`${importados} documento(s) importado(s) com sucesso!\n(${resumo})` +
                          `\n\nConfirme os dados na grelha e não se esqueça de clicar em "Gravar Alterações" no fundo da página.`);
                } else if (listaRepetidos.length === 0) {
                    alert('Não foram encontrados documentos neste ficheiro.');
                }
            }).catch(err => alert('Erro ao ler o ficheiro: ' + err.message));
        }

        // ---------- ARRANQUE ----------
        window.addEventListener('DOMContentLoaded', () => {
            if (Array.isArray(REGISTOS_BD) && REGISTOS_BD.length > 0) {
                REGISTOS_BD.forEach(r => document.getElementById('tabela_fatmab').appendChild(criarLinha(r)));
            } else {
                addLinha(); // linha vazia inicial
            }
            calcularTotais();

            const scrollSalvo = sessionStorage.getItem('lsjrFatmabScrollY');
            if (scrollSalvo !== null) { window.scrollTo(0, parseInt(scrollSalvo)); sessionStorage.removeItem('lsjrFatmabScrollY'); }
        });
    </script>

    <!-- Sugestões para a coluna Doc. -->
    <datalist id="lista_tipos_doc">
        <option value="FSERV"><option value="FAT"><option value="FAT2"><option value="FTA"><option value="FTAD"><option value="NCRE"><option value="NCRE2"><option value="NCD"><option value="NDEB">
    </datalist>
</body>
</html>
