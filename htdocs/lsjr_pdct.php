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
    $aviso_repetidos = $_SESSION['lsjr_pdct_repetidos'] ?? [];
    unset($_SESSION['lsjr_pdct_repetidos']);

    // --- 1. CRIAÇÃO AUTOMÁTICA DA TABELA NA BASE DE DADOS (LSJR - PDCT) ---
    try {
        $sql_create = "CREATE TABLE IF NOT EXISTS lsjr_pdct (
            id INT AUTO_INCREMENT PRIMARY KEY,
            numero INT DEFAULT NULL,
            seccao INT NOT NULL DEFAULT 2,
            data_registo DATE,
            pdct VARCHAR(50),
            valor DECIMAL(12,2) DEFAULT 0.00,
            valor_corrigido DECIMAL(12,2) DEFAULT NULL,
            estado VARCHAR(20) DEFAULT 'Ativo',
            valor_certo VARCHAR(3) DEFAULT 'Nao',
            observacoes TEXT,
            id_acerto INT DEFAULT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
        $pdo->exec($sql_create);
        
        // Assegurar que as colunas novas existem se a tabela for antiga
        $col_check_vc = $pdo->query("SHOW COLUMNS FROM lsjr_pdct LIKE 'valor_certo'");
        if ($col_check_vc->rowCount() == 0) {
            $pdo->exec("ALTER TABLE lsjr_pdct ADD COLUMN valor_certo VARCHAR(3) DEFAULT 'Nao' AFTER estado");
        }
        $col_check_obs = $pdo->query("SHOW COLUMNS FROM lsjr_pdct LIKE 'observacoes'");
        if ($col_check_obs->rowCount() == 0) {
            $pdo->exec("ALTER TABLE lsjr_pdct ADD COLUMN observacoes TEXT AFTER valor_certo");
        }
        $col_check_vc_cor = $pdo->query("SHOW COLUMNS FROM lsjr_pdct LIKE 'valor_corrigido'");
        if ($col_check_vc_cor->rowCount() == 0) {
            $pdo->exec("ALTER TABLE lsjr_pdct ADD COLUMN valor_corrigido DECIMAL(12,2) DEFAULT NULL AFTER valor");
        }
        // Ref. (coluna pdct) guarda o documento completo do extracto (ex: "PDCT 153226/779") -> tem de ser texto
        $col_check_ref = $pdo->query("SHOW COLUMNS FROM lsjr_pdct LIKE 'pdct'");
        $col_ref = $col_check_ref ? $col_check_ref->fetch(PDO::FETCH_ASSOC) : null;
        if ($col_ref && stripos($col_ref['Type'], 'char') === false) {
            $pdo->exec("ALTER TABLE lsjr_pdct MODIFY COLUMN pdct VARCHAR(50) DEFAULT NULL");
        }
        $col_check_id_acerto = $pdo->query("SHOW COLUMNS FROM lsjr_pdct LIKE 'id_acerto'");
        if ($col_check_id_acerto->rowCount() == 0) {
            $pdo->exec("ALTER TABLE lsjr_pdct ADD COLUMN id_acerto INT DEFAULT NULL");
        }
        // NOVO: Tabela antiga (Número como chave) -> passa a ter um id próprio,
        // para poder ter Faturas, Notas de Crédito e PDCT com o mesmo número
        $col_check_id = $pdo->query("SHOW COLUMNS FROM lsjr_pdct LIKE 'id'");
        if ($col_check_id->rowCount() == 0) {
            $pdo->exec("ALTER TABLE lsjr_pdct DROP PRIMARY KEY, ADD COLUMN id INT NOT NULL AUTO_INCREMENT PRIMARY KEY FIRST, MODIFY numero INT DEFAULT NULL");
        }
    } catch (Exception $e) {
        // Ignora caso a tabela já exista
    }

    // --- 2. LÓGICA DE GRAVAÇÃO (POST) ---
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        try {
            $pdo->beginTransaction();

            // Lidar com linhas eliminadas no ecrã (nunca apaga registos já Acertados)
            $deleted = json_decode($_POST['deleted_ids'] ?? '[]', true);
            if (is_array($deleted)) {
                $deleted = array_values(array_filter(array_map('intval', $deleted), fn($x) => $x > 0));
                if (!empty($deleted)) {
                    $placeholders = implode(',', array_fill(0, count($deleted), '?'));
                    $pdo->prepare("DELETE FROM lsjr_pdct WHERE id IN ($placeholders) AND estado != 'Acertado'")->execute($deleted);
                }
            }

            // Inserir ou Atualizar Linhas (identificadas pelo id interno)
            if (isset($_POST['numero']) && is_array($_POST['numero'])) {
                $repetidos_gravacao = [];
                $stmt_existe = $pdo->prepare("SELECT COUNT(*) FROM lsjr_pdct WHERE UPPER(TRIM(pdct)) = UPPER(TRIM(?))");
                $stmt_ins = $pdo->prepare("INSERT INTO lsjr_pdct (numero, seccao, data_registo, pdct, valor, valor_corrigido, estado, valor_certo, observacoes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt_upd = $pdo->prepare("UPDATE lsjr_pdct SET numero=?, seccao=?, data_registo=?, pdct=?, valor=?, valor_corrigido=?, estado=?, valor_certo=?, observacoes=? WHERE id=? AND estado != 'Acertado'");

                for ($i = 0; $i < count($_POST['numero']); $i++) {
                    $id_linha = (int)($_POST['row_id'][$i] ?? 0);
                    $numero = intval($_POST['numero'][$i]);
                    $pdct = trim($_POST['pdct'][$i] ?? '');
                    if ($numero <= 0 && $pdct === '') continue; // Ignora linhas em branco
                    if ($numero <= 0) $numero = null;
                    if ($pdct === '') $pdct = null;

                    $seccao = intval($_POST['seccao'][$i]);
                    $data_registo = !empty($_POST['data_registo'][$i]) ? $_POST['data_registo'][$i] : null;

                    // Parse do Valor Original
                    $valor_str = str_replace(['.', ','], ['', '.'], $_POST['valor'][$i]);
                    $valor = floatval($valor_str);

                    // Parse do Valor Corrigido
                    $valor_cor_str = trim($_POST['valor_corrigido'][$i] ?? '');
                    $valor_corrigido = ($valor_cor_str !== '') ? floatval(str_replace(['.', ','], ['', '.'], $valor_cor_str)) : null;

                    $estado = $_POST['estado'][$i] ?? 'Ativo';
                    $valor_certo = $_POST['valor_certo'][$i] ?? 'Nao';

                    // REGRA: Faturas (FAT, FT, FTA...) e Notas de Crédito (NC, NCRE...) têm sempre V. Certo = Sim
                    // (só os PDCT precisam de ser conferidos)
                    if ($pdct !== null && preg_match('/^(F|N\/?C)[A-Za-z\/\.]*[\s0-9]/i', $pdct)) {
                        $valor_certo = 'Sim';
                        if ($valor_corrigido === null) $valor_corrigido = $valor;
                    }
                    $observacoes = trim($_POST['observacoes'][$i] ?? '');

                    $dados = [$numero, $seccao, $data_registo, $pdct, $valor, $valor_corrigido, $estado, $valor_certo, $observacoes];
                    if ($id_linha > 0) {
                        $dados[] = $id_linha;
                        $stmt_upd->execute($dados);
                    } else {
                        // SALVAGUARDA: se este documento (Ref.) já existe, não o volta a criar
                        if ($pdct !== null) {
                            $stmt_existe->execute([$pdct]);
                            if ($stmt_existe->fetchColumn()) { $repetidos_gravacao[] = $pdct; continue; }
                        }
                        $stmt_ins->execute($dados);
                    }
                }
            }

            $pdo->commit();
            if (!empty($repetidos_gravacao)) { $_SESSION['lsjr_pdct_repetidos'] = array_values(array_unique($repetidos_gravacao)); }
            header("Location: lsjr_pdct.php?editado=1");
            exit;

        } catch (PDOException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            if ($e->getCode() == 23000) {
                $mensagem_erro = "Erro: Foi detetado um registo duplicado.";
            } else {
                $mensagem_erro = "Erro na gravação: " . $e->getMessage();
            }
        }
    }

    // --- 3. CARREGAR REGISTOS EXISTENTES (Apenas não acertados) ---
    $stmt = $pdo->query("SELECT * FROM lsjr_pdct WHERE estado != 'Acertado' ORDER BY (data_registo IS NULL), data_registo ASC, pdct ASC, numero ASC, id ASC");
    $registos = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Contar registos ocultos (Acertados)
    $stmt_ocultos = $pdo->query("SELECT COUNT(*) FROM lsjr_pdct WHERE estado = 'Acertado'");
    $registos_ocultos = $stmt_ocultos->fetchColumn();

    // Todos os documentos já gravados (Ref. -> estado), para a importação não os duplicar
    $refs_gravadas = [];
    foreach ($pdo->query("SELECT pdct, estado FROM lsjr_pdct WHERE pdct IS NOT NULL AND pdct != ''")->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $refs_gravadas[mb_strtoupper(trim($r['pdct']), 'UTF-8')] = $r['estado'];
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
    <title>Pedidos de Cotação (PDCT) - LSJR</title>
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
        .page-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
        .page-title { margin: 0; color: #2c3e50; font-size: 24px; font-weight: 800; display: flex; align-items: center; gap: 10px; }
        .page-title i { color: #e67e22; }
        
        .card { background: #fff; border-radius: 12px; box-shadow: 0 4px 15px rgba(0,0,0,0.04); border: 1px solid #e1e5ea; overflow: hidden; margin-bottom: 30px; padding: 25px;}
        
        /* TABELA TIPO EXCEL */
        .table-auto { width: 100%; border-collapse: collapse; table-layout: fixed; }
        .table-auto th { background: #f8f9fa; padding: 12px 4px; border: 1px solid #eee; font-size: 10px; color: #576574; text-transform: uppercase; text-align: center; }
        .table-auto td { border: 1px solid #f1f2f6; padding: 0; }
        .table-auto input, .table-auto select { border: none; width: 100%; padding: 10px 4px; text-align: center; background: transparent; font-size: 12px; font-family: inherit; transition: all 0.2s; border-radius: 0; }
        .table-auto input:focus, .table-auto select:focus { background: #fff; box-shadow: inset 0 0 0 2px #e67e22; outline: none; }
        .table-auto select { cursor: pointer; appearance: auto; -webkit-appearance: auto; }
        
        .table-auto .inp-numero { font-weight: bold; color: #2c3e50; background: #fafafa; }
        .readonly-text { color: #7f8c8d; font-weight: 700; background-color: #f8f9fa !important; }
        
        /* Cores V. Certo */
        .bg-sim { background-color: #eafaf1 !important; color: #27ae60 !important; font-weight: bold; }
        .bg-nao { background-color: #fdeded !important; color: #c0392b !important; font-weight: bold; }
        /* V. Certo fixo em "Sim" (Faturas e Notas de Crédito) */
        .sel-fixo { pointer-events: none; opacity: 0.85; }
        
        .btn-add-line { background: #e67e22; color: #fff; border: none; padding: 10px 20px; border-radius: 8px; cursor: pointer; display: inline-flex; align-items: center; gap: 8px; font-size: 13px; font-weight: 600; margin-top: 15px; transition: background 0.2s; }
        .btn-add-line:hover { background: #d35400; }
        
        .btn-row-action { background: none; border: none; cursor: pointer; font-size: 14px; padding: 6px; border-radius: 6px; transition: all 0.2s; margin: 0 2px; }
        .btn-del-row { color: #e74c3c; } .btn-del-row:hover { background: #fdeded; }
        .btn-add-row { color: #27ae60; } .btn-add-row:hover { background: #eafaf1; }
        .btn-dup-row { color: #3498db; } .btn-dup-row:hover { background: #ebf5fb; }

        /* Botão "copiar Valor -> V. Corrigido" (em cada linha e no cabeçalho para todas) */
        .cel-corrigido { display: flex; align-items: center; }
        .cel-corrigido input { flex: 1; min-width: 0; }
        .btn-copiar-valor { background: none; border: none; color: #e67e22; cursor: pointer; padding: 6px 6px; font-size: 13px; border-radius: 4px; transition: 0.2s; flex-shrink: 0; }
        .btn-copiar-valor:hover { background: #fdf2e9; color: #d35400; }
        .btn-copiar-todos { background: #fdf2e9; border: 1px solid #f5cba7; color: #d35400; border-radius: 4px; padding: 2px 6px; margin-left: 4px; cursor: pointer; font-size: 10px; font-weight: 700; }
        .btn-copiar-todos:hover { background: #e67e22; color: #fff; }

        .floating-action-bar { position: fixed; bottom: 20px; right: 20px; background: #fff; padding: 15px 25px; border-radius: 12px; box-shadow: 0 5px 20px rgba(0,0,0,0.15); display: flex; align-items: center; gap: 15px; z-index: 1000; border: 1px solid #e1e5ea; justify-content: space-between; }
        .btn-save { background: #e67e22; color: #fff; padding: 12px 25px; border-radius: 8px; border: none; font-weight: 700; cursor: pointer; transition: background 0.2s; font-size: 14px; display: flex; align-items: center; gap: 8px; }
        .btn-save:hover { background: #d35400; }

        .alert { padding: 15px; border-radius: 8px; margin-bottom: 20px; text-align: center; font-weight: 600; }
        .alert-erro { background: #fdeded; color: #c0392b; border: 1px solid #f9cccc; }
        .alert-sucesso { background: #e8f5e9; color: #27ae60; border: 1px solid #c8e6c9; }

        .btn-import-csv { background: #27ae60; color: #fff; }
        .btn-import-csv:hover { background: #219653; }

        @media (max-width: 768px) {
            .topbar { padding: 15px 20px; }
            .btn-back span { display: none; }
            .table-auto th { font-size: 8px; padding: 8px 2px; }
            .table-auto input, .table-auto select { padding: 8px 2px; font-size: 10px; }
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
            <div class="alert <?= $tipo_mensagem === 'sucesso' ? 'alert-sucesso' : 'alert-erro' ?>">
                <?= htmlspecialchars($mensagem, ENT_QUOTES, 'UTF-8') ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($aviso_repetidos)): ?>
            <div class="alert alert-erro" style="background:#fff8e1; color:#8a6d00; border-color:#f5d76e;">
                <i class="fa-solid fa-triangle-exclamation"></i> <?= count($aviso_repetidos) ?> documento(s) já existia(m) e não foi(ram) gravado(s) de novo:
                <?= htmlspecialchars(implode(', ', $aviso_repetidos), ENT_QUOTES, 'UTF-8') ?>
            </div>
        <?php endif; ?>

        <?php if ($mensagem_erro): ?>
            <div class="alert alert-erro">
                <i class="fa-solid fa-triangle-exclamation"></i> <?= htmlspecialchars($mensagem_erro, ENT_QUOTES, 'UTF-8') ?>
            </div>
        <?php endif; ?>

        <div class="page-header">
            <h1 class="page-title"><i class="fa-solid fa-file-signature"></i> Pedidos de Cotação (PDCT)</h1>
            <?php if($registos_ocultos > 0): ?>
                <div style="background: #fdf2e9; color: #e67e22; padding: 8px 15px; border-radius: 6px; font-size: 12px; font-weight: bold; border: 1px solid #f5cba7;">
                    <i class="fa-solid fa-eye-slash"></i> <?= $registos_ocultos ?> registo(s) Acertado(s) oculto(s)
                </div>
            <?php endif; ?>
        </div>

        <form method="POST" id="pdctForm">
            <input type="hidden" name="deleted_ids" id="deleted_ids" value="[]">
            
            <div class="card">
                <table class="table-auto">
                    <thead>
                        <tr>
                            <th style="width: 8%;">Número *</th>
                            <th style="width: 6%;">Secção</th>
                            <th style="width: 10%;">Data</th>
                            <th style="width: 15%;">Ref.</th>
                            <th style="width: 9%;">Valor (€)</th>
                            <th style="width: 12%;">V. Corrigido <button type="button" class="btn-copiar-todos" onclick="copiarValorTodasLinhas()" title="Copiar o Valor para o V. Corrigido em todas as linhas sem V. Corrigido"><i class="fa-solid fa-angles-right"></i> Todos</button></th>
                            <th style="width: 9%;">V. Certo?</th>
                            <th style="width: 9%;">Estado</th>
                            <th style="width: 14%;">Observações</th>
                            <th style="width: 8%;">Ações</th>
                        </tr>
                    </thead>
                    <tbody id="tabela_pdct">
                        <?php if(empty($registos)): ?>
                            <!-- Linha Vazia Inicial -->
                            <tr>
                                <td><input type="hidden" name="row_id[]" value=""><input type="number" name="numero[]" class="inp-numero" required></td>
                                <td>
                                    <select name="seccao[]">
                                        <option value="1">1</option>
                                        <option value="2" selected>2</option>
                                    </select>
                                </td>
                                <td><input type="date" name="data_registo[]"></td>
                                <td><input type="text" name="pdct[]" placeholder="Ex: PDCT 153226/779"></td>
                                <td><input type="text" name="valor[]" class="inp-valor" value="0,00" oninput="forceComma(this); calcularTotais();" onblur="formatField(this)"></td>
                                <td><div class="cel-corrigido"><button type="button" class="btn-copiar-valor" onclick="copiarValorParaCorrigido(this)" title="Copiar o Valor para o V. Corrigido"><i class="fa-solid fa-arrow-right-to-bracket"></i></button><input type="text" name="valor_corrigido[]" class="inp-valor-corrigido" placeholder="0,00" oninput="forceComma(this); atualizarValorCorrigido(this); calcularTotais();" onblur="formatField(this)"></div></td>
                                <td>
                                    <select name="valor_certo[]" class="sel-valor-certo" onchange="mudarValorCerto(this)">
                                        <option value="Sim">Sim</option>
                                        <option value="Nao" selected>Não</option>
                                    </select>
                                </td>
                                <td>
                                    <select name="estado[]">
                                        <option value="Ativo">Ativo</option>
                                        <option value="Inativo">Inativo</option>
                                    </select>
                                </td>
                                <td><input type="text" name="observacoes[]" placeholder="..."></td>
                                <td style="text-align:center;">
                                    <button type="button" class="btn-row-action btn-add-row" onclick="insertRowAfter(this)" title="Nova Linha Abaixo"><i class="fa-solid fa-plus"></i></button>
                                    <button type="button" class="btn-row-action btn-dup-row" onclick="duplicateRow(this)" title="Duplicar Linha"><i class="fa-solid fa-copy"></i></button>
                                    <button type="button" class="btn-row-action btn-del-row" onclick="removeRow(this)" title="Apagar Linha"><i class="fa-solid fa-trash-can"></i></button>
                                </td>
                            </tr>
                        <?php else: ?>
                            <!-- Linhas da Base de Dados -->
                            <?php foreach($registos as $row): 
                                $valCorrigidoShow = isset($row['valor_corrigido']) ? number_format($row['valor_corrigido'], 2, ',', '') : '';
                            ?>
                            <tr>
                                <td><input type="hidden" name="row_id[]" value="<?= (int)$row['id'] ?>"><input type="number" name="numero[]" class="inp-numero" value="<?= htmlspecialchars($row['numero'] ?? '') ?>" required></td>
                                <td>
                                    <select name="seccao[]">
                                        <option value="1" <?= $row['seccao'] == 1 ? 'selected' : '' ?>>1</option>
                                        <option value="2" <?= $row['seccao'] == 2 ? 'selected' : '' ?>>2</option>
                                    </select>
                                </td>
                                <td><input type="date" name="data_registo[]" value="<?= htmlspecialchars($row['data_registo']) ?>"></td>
                                <td><input type="text" name="pdct[]" value="<?= htmlspecialchars($row['pdct'] ?? '') ?>" placeholder="Ex: PDCT 153226/779"></td>
                                <?php $valor_bloqueado = ((float)$row['valor'] != 0); ?>
                                <td><input type="text" name="valor[]" class="inp-valor <?= $valor_bloqueado ? 'readonly-text' : '' ?>" value="<?= number_format($row['valor'], 2, ',', '') ?>" <?= $valor_bloqueado ? 'readonly' : 'oninput="forceComma(this); calcularTotais();" onblur="formatField(this)"' ?>></td>
                                <td><div class="cel-corrigido"><button type="button" class="btn-copiar-valor" onclick="copiarValorParaCorrigido(this)" title="Copiar o Valor para o V. Corrigido"><i class="fa-solid fa-arrow-right-to-bracket"></i></button><input type="text" name="valor_corrigido[]" class="inp-valor-corrigido" value="<?= $valCorrigidoShow ?>" placeholder="0,00" oninput="forceComma(this); atualizarValorCorrigido(this); calcularTotais();" onblur="formatField(this)"></div></td>
                                <td>
                                    <select name="valor_certo[]" class="sel-valor-certo" onchange="mudarValorCerto(this)">
                                        <option value="Sim" <?= ($row['valor_certo'] ?? '') == 'Sim' ? 'selected' : '' ?>>Sim</option>
                                        <option value="Nao" <?= ($row['valor_certo'] ?? 'Nao') == 'Nao' ? 'selected' : '' ?>>Não</option>
                                    </select>
                                </td>
                                <td>
                                    <select name="estado[]">
                                        <option value="Ativo" <?= $row['estado'] == 'Ativo' ? 'selected' : '' ?>>Ativo</option>
                                        <option value="Inativo" <?= $row['estado'] == 'Inativo' ? 'selected' : '' ?>>Inativo</option>
                                    </select>
                                </td>
                                <td><input type="text" name="observacoes[]" value="<?= htmlspecialchars($row['observacoes'] ?? '') ?>" placeholder="..."></td>
                                <td style="text-align:center;">
                                    <button type="button" class="btn-row-action btn-add-row" onclick="insertRowAfter(this)" title="Nova Linha Abaixo"><i class="fa-solid fa-plus"></i></button>
                                    <button type="button" class="btn-row-action btn-dup-row" onclick="duplicateRow(this)" title="Duplicar Linha"><i class="fa-solid fa-copy"></i></button>
                                    <button type="button" class="btn-row-action btn-del-row" onclick="removeRow(this)" title="Apagar Linha"><i class="fa-solid fa-trash-can"></i></button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                    <tfoot>
                        <tr>
                            <td colspan="4" style="text-align: right; font-weight: 800; color: #576574; padding-right: 15px;">TOTAIS:</td>
                            <td style="font-weight: 800; color: #2c3e50; text-align: center; font-size: 14px; background: #fdfdfd;" id="soma_valor">0,00 €</td>
                            <td style="font-weight: 800; color: #e67e22; text-align: center; font-size: 14px; background: #fdfdfd;" id="soma_valor_corrigido">0,00 €</td>
                            <td colspan="4"></td>
                        </tr>
                    </tfoot>
                </table>
                
                <div style="display: flex; gap: 10px; margin-top: 15px;">
                    <button type="button" onclick="addLinha()" class="btn-add-line" title="Adicionar no Final"><i class="fa-solid fa-plus"></i> Adicionar Novo PDCT</button>
                    
                    <button type="button" class="btn-add-line btn-import-csv" onclick="document.getElementById('csv_file').click()">
                        <i class="fa-solid fa-file-excel"></i> Importar Extracto de Vendas (XLS / CSV)
                    </button>
                    <input type="file" id="csv_file" accept=".xls,.xlsx,.csv" style="display:none" onchange="importarExtracto(this)">
                </div>
            </div>

            <div class="floating-action-bar">
                <div style="display: flex; gap: 20px; margin-right: auto;">
                    <div style="text-align: right;">
                        <span style="font-size: 10px; font-weight: bold; color: #576574; display: block; text-transform: uppercase;">Total Valor</span>
                        <span id="float_soma_valor" style="font-size: 18px; font-weight: 800; color: #2c3e50;">0,00 €</span>
                    </div>
                    <div style="text-align: right; padding-left: 20px; border-left: 2px solid #e1e5ea;">
                        <span style="font-size: 10px; font-weight: bold; color: #576574; display: block; text-transform: uppercase;">Total Corrigido</span>
                        <span id="float_soma_corrigido" style="font-size: 18px; font-weight: 800; color: #e67e22;">0,00 €</span>
                    </div>
                </div>
                <button type="button" class="btn-save" style="background:#576574;" onclick="window.open('lsjr_pdct_pdf.php', '_blank')"><i class="fa-solid fa-print"></i> IMPRIMIR PDF</button>
                <button type="submit" class="btn-save" onclick="guardarScrollY()"><i class="fa-solid fa-floppy-disk"></i> GRAVAR ALTERAÇÕES</button>
            </div>
        </form>
    </div>

    <script>
        // Documentos já gravados na base de dados (Ref. em maiúsculas -> estado), incluindo os Acertados
        const REFS_GRAVADAS = <?= json_encode((object)$refs_gravadas, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        // Guarda a posição do scroll
        function guardarScrollY() {
            sessionStorage.setItem('pdctScrollY', window.scrollY);
        }

        window.addEventListener('DOMContentLoaded', () => {
            const scrollSalvo = sessionStorage.getItem('pdctScrollY');
            if (scrollSalvo !== null) {
                window.scrollTo(0, parseInt(scrollSalvo));
                sessionStorage.removeItem('pdctScrollY');
            }
            
            // Inicializar as cores dos select's de Valor Certo e calcular totais
            document.querySelectorAll('.sel-valor-certo').forEach(el => mudarValorCerto(el, true));
            aplicarRegraFaturas();
            calcularTotais();

            // Se a Ref. de uma linha for alterada à mão, volta a aplicar a regra
            document.getElementById('tabela_pdct').addEventListener('change', e => {
                if (e.target.name === 'pdct[]') { aplicarRegraFaturas(e.target.closest('tr')); calcularTotais(); }
            });
        });

        // ==========================================
        // COPIAR VALOR -> V. CORRIGIDO
        // ==========================================
        // Numa linha: copia sempre o Valor para o V. Corrigido (e marca V. Certo = Sim)
        function copiarValorParaCorrigido(btn) {
            const tr = btn.closest('tr');
            const vCor = tr.querySelector('.inp-valor-corrigido');
            vCor.value = numToStr(strToNum(tr.querySelector('.inp-valor').value));
            atualizarValorCorrigido(vCor);
            calcularTotais();
        }
        // Em todas as linhas: só preenche as que ainda não têm V. Corrigido (não estraga correções já feitas)
        function copiarValorTodasLinhas() {
            const linhas = Array.from(document.querySelectorAll('#tabela_pdct tr')).filter(tr => {
                const vCor = tr.querySelector('.inp-valor-corrigido');
                return vCor && (vCor.value.trim() === '' || strToNum(vCor.value) === 0) && strToNum(tr.querySelector('.inp-valor').value) !== 0;
            });
            if (linhas.length === 0) { alert('Todas as linhas já têm V. Corrigido preenchido.'); return; }
            if (!confirm(`Copiar o Valor para o V. Corrigido em ${linhas.length} linha(s) sem V. Corrigido?\n\n(As linhas que já têm V. Corrigido não são alteradas.)`)) return;
            linhas.forEach(tr => {
                const vCor = tr.querySelector('.inp-valor-corrigido');
                vCor.value = numToStr(strToNum(tr.querySelector('.inp-valor').value));
                atualizarValorCorrigido(vCor);
            });
            calcularTotais();
        }

        // ==========================================
        // REGRA: Faturas (FAT, FT, FTA...) e Notas de Crédito (NC, NCRE...) têm sempre V. Certo = "Sim"
        // e o V. Corrigido igual ao Valor (só os PDCT precisam de ser conferidos)
        // ==========================================
        function eFaturaOuNotaCredito(ref) {
            const m = String(ref || '').trim().match(/^([A-Za-z][A-Za-z\/\.]*)[\s0-9]/);
            return !!m && /^(F|N\/?C)/i.test(m[1]);
        }
        function aplicarRegraFaturas(linha = null) {
            const linhas = linha ? [linha] : Array.from(document.querySelectorAll('#tabela_pdct tr'));
            linhas.forEach(tr => {
                const ref = tr.querySelector('input[name="pdct[]"]');
                const sel = tr.querySelector('.sel-valor-certo');
                if (!ref || !sel) return;
                if (eFaturaOuNotaCredito(ref.value)) {
                    sel.value = 'Sim';
                    mudarValorCerto(sel, true);
                    sel.classList.add('sel-fixo');
                    sel.setAttribute('tabindex', '-1');
                    sel.title = 'Faturas e Notas de Crédito têm sempre o valor certo';
                    const vCor = tr.querySelector('.inp-valor-corrigido');
                    if (vCor && (vCor.value.trim() === '' || strToNum(vCor.value) === 0)) {
                        vCor.value = tr.querySelector('.inp-valor').value;
                    }
                } else {
                    sel.classList.remove('sel-fixo');
                    sel.removeAttribute('tabindex');
                    sel.title = '';
                }
            });
        }

        // Formatação Numérica Ponto/Vírgula
        function strToNum(s) {
            if (!s) return 0;
            let str = s.toString().trim();
            let hasComma = str.indexOf(',') !== -1;
            let hasDot = str.indexOf('.') !== -1;
            if (hasComma && hasDot) {
                let lastComma = str.lastIndexOf(',');
                let lastDot = str.lastIndexOf('.');
                if (lastComma > lastDot) {
                    str = str.replace(/\./g, '').replace(',', '.');
                } else {
                    str = str.replace(/,/g, '');
                }
            } else if (hasComma) {
                str = str.replace(/,/g, '.');
            }
            str = str.replace(/[^\d\.-]/g, '');
            return parseFloat(str) || 0;
        }

        function numToStr(n) { return n.toLocaleString('pt-PT', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }

        function forceComma(el) {
            if (el.value.indexOf('.') !== -1) {
                let cursorPosition = el.selectionStart;
                el.value = el.value.replace(/\./g, ',');
                el.setSelectionRange(cursorPosition, cursorPosition);
            }
        }

        function formatField(el) { 
            let val = strToNum(el.value); 
            if (el.value.trim() === '') {
                if (el.classList.contains('inp-valor-corrigido')) {
                    calcularTotais();
                    return;
                }
            }
            
            if (val === 0 && !el.classList.contains('inp-valor-corrigido')) {
                el.value = "0,00";
            } else if (el.value.trim() !== '') {
                el.value = numToStr(val);
            }
            calcularTotais();
        }

        // CÁLCULO DE TOTAIS
        function calcularTotais() {
            let totalValor = 0;
            let totalCorrigido = 0;

            document.querySelectorAll('#tabela_pdct tr').forEach(tr => {
                let valInput = tr.querySelector('.inp-valor');
                let valCorInput = tr.querySelector('.inp-valor-corrigido');
                
                if (valInput) totalValor += strToNum(valInput.value);
                if (valCorInput) totalCorrigido += strToNum(valCorInput.value);
            });

            const strVal = numToStr(totalValor) + ' €';
            const strCor = numToStr(totalCorrigido) + ' €';

            const elSomaValor = document.getElementById('soma_valor');
            const elSomaCorrigido = document.getElementById('soma_valor_corrigido');
            const floatSomaValor = document.getElementById('float_soma_valor');
            const floatSomaCorrigido = document.getElementById('float_soma_corrigido');

            if (elSomaValor) elSomaValor.innerText = strVal;
            if (elSomaCorrigido) elSomaCorrigido.innerText = strCor;
            if (floatSomaValor) floatSomaValor.innerText = strVal;
            if (floatSomaCorrigido) floatSomaCorrigido.innerText = strCor;
        }

        // --- LÓGICA DO VALOR CORRIGIDO E V. CERTO ---
        function mudarValorCerto(selectElement, isInit = false) {
            const tr = selectElement.closest('tr');
            const valCerto = selectElement.value;
            
            if (valCerto === 'Sim') {
                selectElement.className = 'sel-valor-certo bg-sim' + (selectElement.classList.contains('sel-fixo') ? ' sel-fixo' : '');
                if (!isInit) {
                    const valorOriginal = tr.querySelector('.inp-valor').value;
                    const valCorrigidoInput = tr.querySelector('.inp-valor-corrigido');
                    if (valCorrigidoInput.value.trim() === '' || valCorrigidoInput.value === '0,00') {
                        valCorrigidoInput.value = valorOriginal;
                        calcularTotais();
                    }
                }
            } else {
                selectElement.className = 'sel-valor-certo bg-nao';
            }
        }

        function atualizarValorCorrigido(inputElement) {
            const tr = inputElement.closest('tr');
            const selectValorCerto = tr.querySelector('.sel-valor-certo');
            
            if (inputElement.value.trim() !== '') {
                selectValorCerto.value = 'Sim';
                mudarValorCerto(selectValorCerto, true);
            }
        }

        function parseDate(dateStr) {
            if (!dateStr) return '';
            dateStr = dateStr.trim();
            if (dateStr.match(/^\d{4}-\d{2}-\d{2}$/)) return dateStr;
            let parts = dateStr.split(/[\/\-]/);
            if (parts.length === 3) {
                if (parts[2].length === 4) {
                    return `${parts[2]}-${parts[1].padStart(2, '0')}-${parts[0].padStart(2, '0')}`;
                }
            }
            return dateStr;
        }

        // ==========================================
        // IMPORTAÇÃO DO "EXTRACTO DE VENDAS POR DOCUMENTO" (XLS, XLSX ou CSV)
        // Colunas usadas: Secção -> Secção | Documento -> Ref. (e Número) | Data Doc. -> Data | Total Doc. -> Valor
        // As linhas de cabeçalho, "Entidade:", totais e rodapé são ignoradas automaticamente.
        // ==========================================
        function escapeHTML(str) {
            str = (str === null || str === undefined) ? '' : String(str);
            return str.replace(/[&<>'"]/g, t => ({ '&':'&amp;', '<':'&lt;', '>':'&gt;', "'":'&#39;', '"':'&quot;' }[t]));
        }

        // Converte a data do Excel (nº de série, Date ou texto dd/mm/aaaa) para aaaa-mm-dd
        function dataExtractoParaISO(v) {
            if (v === null || v === undefined || v === '') return '';
            if (v instanceof Date && !isNaN(v)) {
                return `${v.getFullYear()}-${String(v.getMonth() + 1).padStart(2, '0')}-${String(v.getDate()).padStart(2, '0')}`;
            }
            if (typeof v === 'number') {
                const d = new Date(Math.round((v - 25569) * 86400000)); // série Excel -> data (UTC)
                return `${d.getUTCFullYear()}-${String(d.getUTCMonth() + 1).padStart(2, '0')}-${String(d.getUTCDate()).padStart(2, '0')}`;
            }
            return parseDate(String(v));
        }

        // Lê o ficheiro (XLS/XLSX/CSV) e devolve as linhas como arrays de valores
        // - Excel: lido diretamente
        // - CSV: lido como texto (deteta UTF-8 ou Windows-1252) e SEM conversão automática de datas/números,
        //   para "06/10/2026" não ser confundido com 10 de junho
        function lerFolhaExtracto(file) {
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
                            texto = texto.replace(/^﻿/, '');
                            wb = XLSX.read(texto, { type: 'string', raw: true });
                        } else {
                            wb = XLSX.read(bytes, { type: 'array', codepage: 1252 });
                        }
                        const folha = wb.Sheets[wb.SheetNames[0]];
                        resolve(XLSX.utils.sheet_to_json(folha, { header: 1, raw: true, defval: '' }));
                    } catch (err) { reject(err); }
                };
                reader.onerror = () => reject(new Error('Não foi possível ler o ficheiro.'));
                reader.readAsArrayBuffer(file);
            });
        }

        // Documento no formato "TIPO SÉRIE/NÚMERO" — ex: "PDCT 153226/779", "FAT 80226/516", "NCRE 84226/26"
        // (exige o tipo em letras no início — ignora o rodapé "1 / 1", datas e totais)
        const REGEX_DOCUMENTO = /^([A-Za-z][A-Za-z\/\.]*)\s*([A-Za-z0-9\-\.]*)\s*\/\s*(\d+)$/;
        const normTexto = t => String(t ?? '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '').trim();
        const chaveRotulo = t => normTexto(t).replace(/[^a-z]/g, ''); // "Nº. Documento" -> "ndocumento"

        // FORMATO 1: "Extracto de Vendas por Documento" (Secção | Documento | Data Doc. | ... | Total Doc.)
        function interpretarExtractoVendas(linhas) {
            let colSec = 0, colDoc = 1, colData = 2, colTotal = 9;
            for (let r = 0; r < Math.min(linhas.length, 10); r++) {
                const cab = linhas[r].map(normTexto);
                const iDoc = cab.findIndex(c => c === 'documento');
                if (iDoc !== -1) {
                    colDoc = iDoc;
                    const iSec = cab.findIndex(c => c === 'seccao');
                    const iData = cab.findIndex(c => c.startsWith('data doc'));
                    const iTotal = cab.findIndex(c => c.startsWith('total doc'));
                    if (iSec !== -1) colSec = iSec;
                    if (iData !== -1) colData = iData;
                    if (iTotal !== -1) colTotal = iTotal;
                    break;
                }
            }
            const docs = [];
            linhas.forEach(cols => {
                const doc = String(cols[colDoc] ?? '').trim();
                if (!REGEX_DOCUMENTO.test(doc)) return;
                docs.push({ doc: doc, seccao: cols[colSec], data: cols[colData], valor: cols[colTotal] });
            });
            return docs;
        }

        // FORMATO 2: "Listagem de Pendentes de Clientes" (Secção | Doc. | Nº. Documento | Nº. Externo | Emissão | Vencimento | Atraso | Original | Pendente ...)
        // Funciona com o CSV do relatório (cabeçalho e dados na MESMA linha) e com o XLS (cabeçalho numa linha à parte)
        function interpretarPendentesClientes(linhas) {
            const ROTULOS = ['seccao', 'doc', 'ndocumento', 'nexterno', 'emissao', 'vencimento', 'original', 'pendente'];
            let cabecalho = null; // posições do cabeçalho quando vem numa linha separada (XLS)
            let encontrouFormato = false;
            const docs = [];

            linhas.forEach(cols => {
                // Rótulos presentes nesta linha
                const rot = {};
                cols.forEach((c, idx) => { const k = chaveRotulo(c); if (ROTULOS.includes(k) && rot[k] === undefined) rot[k] = idx; });
                if (rot.ndocumento !== undefined && rot.original !== undefined) encontrouFormato = true;

                // Célula com o documento (ex: "NCRE 84226/26")
                const iDoc = cols.findIndex(c => REGEX_DOCUMENTO.test(String(c ?? '').trim()));
                if (iDoc === -1) {
                    if (rot.ndocumento !== undefined && rot.original !== undefined) cabecalho = rot;
                    return;
                }

                const base = (rot.ndocumento !== undefined && rot.original !== undefined) ? rot : cabecalho;
                if (!base) return;
                const desvio = iDoc - base.ndocumento; // no CSV os dados vêm N colunas à frente dos rótulos
                const val = k => (base[k] !== undefined) ? cols[base[k] + desvio] : '';

                docs.push({
                    doc: String(cols[iDoc]).trim(),
                    seccao: val('seccao'),
                    data: val('emissao'),
                    valor: val('original')
                });
            });
            return encontrouFormato ? docs : null;
        }

        function importarExtracto(input) {
            const file = input.files[0];
            input.value = '';
            if (!file) return;

            lerFolhaExtracto(file).then(linhas => {
                if (!linhas || linhas.length === 0) { alert('O ficheiro está vazio.'); return; }

                // Esta página é só para PDCT (Pedidos de Cotação).
                // A "Listagem de Pendentes de Clientes" (Faturas e Notas de Crédito) importa-se em "A favor MAB".
                if (interpretarPendentesClientes(linhas) !== null) {
                    alert('Este ficheiro é uma "Listagem de Pendentes de Clientes" (Faturas e Notas de Crédito).\n\nImporte-o na página "A favor MAB" do módulo LSJR.');
                    return;
                }
                const docs = interpretarExtractoVendas(linhas);
                const origemFicheiro = 'Extracto de Vendas por Documento';
                const naoPdct = [];

                // Documentos já presentes na grelha (para não duplicar)
                const refsExistentes = new Set(Array.from(document.querySelectorAll('#tabela_pdct input[name="pdct[]"]')).map(i => i.value.trim().toUpperCase()).filter(v => v !== ''));

                const tbody = document.getElementById('tabela_pdct');
                let importados = 0, repetidos = 0;
                const listaRepetidos = [];
                const porTipo = {};

                docs.forEach(d => {
                    const m = d.doc.match(REGEX_DOCUMENTO);
                    if (!m) return;
                    const tipoDoc = m[1].toUpperCase();
                    // Só PDCT: Faturas/Notas de Crédito pertencem à página "A favor MAB"
                    if (tipoDoc !== 'PDCT') { naoPdct.push(d.doc); return; }
                    const numero = parseInt(m[3], 10);
                    if (!numero) return;
                    // SALVAGUARDA: documento já existe na grelha ou já foi gravado (mesmo que esteja Acertado)
                    const refMai = d.doc.toUpperCase();
                    if (refsExistentes.has(refMai)) {
                        repetidos++; listaRepetidos.push(d.doc + ' (já está na listagem)'); return;
                    }
                    if (Object.prototype.hasOwnProperty.call(REFS_GRAVADAS, refMai)) {
                        repetidos++; listaRepetidos.push(d.doc + (REFS_GRAVADAS[refMai] === 'Acertado' ? ' (já gravado e Acertado)' : ' (já gravado)')); return;
                    }

                    // Valor (vem como número no XLS, ou como texto "-29,57" no CSV)
                    let total = typeof d.valor === 'number' ? d.valor : strToNum(d.valor);
                    // Notas de Crédito (NC, NCRE, N/C...) abatem: ficam sempre com valor negativo
                    if (/^N\/?C/.test(tipoDoc) && total > 0) total = -total;

                    const secRaw = String(d.seccao ?? '').trim();
                    const dataObj = {
                        numero: numero,
                        seccao: secRaw === '1' ? '1' : '2',
                        data_registo: dataExtractoParaISO(d.data),
                        pdct: d.doc,
                        valor: numToStr(total),
                        is_imported: true
                    };

                    // Se na grelha só existir a linha vazia inicial, remove-a
                    const linhasAtuais = tbody.querySelectorAll('tr');
                    if (linhasAtuais.length === 1 && linhasAtuais[0].querySelector('.inp-numero').value.trim() === '' && linhasAtuais[0].querySelector('input[name="pdct[]"]').value.trim() === '') {
                        linhasAtuais[0].remove();
                    }

                    const tr = document.createElement('tr');
                    tr.innerHTML = getRowHTML(dataObj);
                    tbody.appendChild(tr);
                    mudarValorCerto(tr.querySelector('.sel-valor-certo'), true);
                    aplicarRegraFaturas(tr); // Faturas / Notas de Crédito -> V. Certo = Sim
                    refsExistentes.add(d.doc.toUpperCase());
                    porTipo[tipoDoc] = (porTipo[tipoDoc] || 0) + 1;
                    importados++;
                });

                calcularTotais();
                if (naoPdct.length > 0) {
                    alert(`${naoPdct.length} documento(s) do ficheiro não são PDCT e não foram importados aqui (importe-os em "A favor MAB"):\n\n` +
                          naoPdct.slice(0, 25).join('\n') + (naoPdct.length > 25 ? '\n...' : ''));
                }
                if (listaRepetidos.length > 0) {
                    alert(`Atenção: ${listaRepetidos.length} documento(s) do ficheiro já existe(m) e NÃO foi(ram) importado(s) para não duplicar:\n\n` +
                          listaRepetidos.slice(0, 25).join('\n') + (listaRepetidos.length > 25 ? `\n... e mais ${listaRepetidos.length - 25}` : ''));
                }
                if (importados > 0) {
                    const resumo = Object.keys(porTipo).map(t => `${t}: ${porTipo[t]}`).join(', ');
                    alert(`${importados} documento(s) importado(s) com sucesso!` +
                          `\n(${origemFicheiro} — ${resumo})` +
                          (repetidos > 0 ? `\n${repetidos} repetido(s) ignorado(s).` : '') +
                          `\n\nConfirme os dados na grelha e não se esqueça de clicar em "Gravar Alterações" no fundo da página.`);
                } else if (repetidos > 0) {
                    alert(`Todos os ${repetidos} documento(s) do ficheiro já existem. Nada foi importado.`);
                } else {
                    if (naoPdct.length === 0) alert('Não foram encontrados PDCT neste ficheiro.\nUse o "Extracto de Vendas por Documento".');
                }
            }).catch(err => {
                alert('Erro ao ler o ficheiro: ' + err.message);
            });
        }

        let deletedItems = [];

        function getRowHTML(data = {}) {
            let num = data.numero || '';
            let rid = data.id || '';
            let sec = data.seccao || '2';
            let dt = data.data_registo || '';
            let pd = data.pdct || '';
            let val = data.valor || '0,00';
            let vc = data.valor_certo || 'Nao';
            let est = data.estado || 'Ativo';
            let obs = data.observacoes || '';
            let valCor = data.valor_corrigido || '';
            
            let isLocked = data.is_imported ? 'readonly' : '';
            let lockClass = data.is_imported ? 'readonly-text' : '';

            return `
                <td><input type="hidden" name="row_id[]" value="${rid}"><input type="number" name="numero[]" class="inp-numero" value="${num}" required></td>
                <td>
                    <select name="seccao[]">
                        <option value="1" ${sec == '1' ? 'selected' : ''}>1</option>
                        <option value="2" ${sec == '2' ? 'selected' : ''}>2</option>
                    </select>
                </td>
                <td><input type="date" name="data_registo[]" value="${dt}"></td>
                <td><input type="text" name="pdct[]" value="${escapeHTML(pd)}" placeholder="Ex: PDCT 153226/779"></td>
                <td><input type="text" name="valor[]" class="inp-valor ${lockClass}" value="${val}" oninput="forceComma(this); calcularTotais();" onblur="formatField(this)" ${isLocked}></td>
                <td><div class="cel-corrigido"><button type="button" class="btn-copiar-valor" onclick="copiarValorParaCorrigido(this)" title="Copiar o Valor para o V. Corrigido"><i class="fa-solid fa-arrow-right-to-bracket"></i></button><input type="text" name="valor_corrigido[]" class="inp-valor-corrigido" value="${valCor}" placeholder="0,00" oninput="forceComma(this); atualizarValorCorrigido(this); calcularTotais();" onblur="formatField(this)"></div></td>
                <td>
                    <select name="valor_certo[]" class="sel-valor-certo" onchange="mudarValorCerto(this)">
                        <option value="Sim" ${vc == 'Sim' ? 'selected' : ''}>Sim</option>
                        <option value="Nao" ${vc == 'Nao' ? 'selected' : ''}>Não</option>
                    </select>
                </td>
                <td>
                    <select name="estado[]">
                        <option value="Ativo" ${est == 'Ativo' ? 'selected' : ''}>Ativo</option>
                        <option value="Inativo" ${est == 'Inativo' ? 'selected' : ''}>Inativo</option>
                    </select>
                </td>
                <td><input type="text" name="observacoes[]" value="${obs}" placeholder="..."></td>
                <td style="text-align:center;">
                    <button type="button" class="btn-row-action btn-add-row" onclick="insertRowAfter(this)" title="Nova Linha Abaixo"><i class="fa-solid fa-plus"></i></button>
                    <button type="button" class="btn-row-action btn-dup-row" onclick="duplicateRow(this)" title="Duplicar Linha"><i class="fa-solid fa-copy"></i></button>
                    <button type="button" class="btn-row-action btn-del-row" onclick="removeRow(this)" title="Apagar Linha"><i class="fa-solid fa-trash-can"></i></button>
                </td>
            `;
        }

        function addLinha() {
            const tbody = document.getElementById('tabela_pdct');
            const tr = document.createElement('tr');
            tr.innerHTML = getRowHTML();
            tbody.appendChild(tr);
            mudarValorCerto(tr.querySelector('.sel-valor-certo'), true);
        }

        function insertRowAfter(btn) {
            const currentRow = btn.closest('tr');
            const newRow = document.createElement('tr');
            newRow.innerHTML = getRowHTML();
            currentRow.parentNode.insertBefore(newRow, currentRow.nextSibling);
            mudarValorCerto(newRow.querySelector('.sel-valor-certo'), true);
        }

        function duplicateRow(btn) {
            const currentRow = btn.closest('tr');
            const newRow = document.createElement('tr');
            
            let data = {
                seccao: currentRow.querySelector('select[name="seccao[]"]').value,
                data_registo: currentRow.querySelector('input[name="data_registo[]"]').value,
                pdct: currentRow.querySelector('input[name="pdct[]"]').value,
                valor: currentRow.querySelector('input[name="valor[]"]').value,
                valor_certo: currentRow.querySelector('select[name="valor_certo[]"]').value,
                estado: currentRow.querySelector('select[name="estado[]"]').value,
                observacoes: currentRow.querySelector('input[name="observacoes[]"]').value
            };
            
            newRow.innerHTML = getRowHTML(data);
            currentRow.parentNode.insertBefore(newRow, currentRow.nextSibling);
            mudarValorCerto(newRow.querySelector('.sel-valor-certo'), true);
            calcularTotais();
        }

        function removeRow(btn) {
            const tr = btn.closest('tr');
            const id = tr.querySelector('input[name="row_id[]"]').value;
            const ref = tr.querySelector('input[name="pdct[]"]').value || tr.querySelector('.inp-numero').value;

            if (id !== '') {
                if (confirm("Tem a certeza que deseja apagar o registo " + ref + "?")) {
                    deletedItems.push(id);
                    document.getElementById('deleted_ids').value = JSON.stringify(deletedItems);
                    tr.remove();
                    calcularTotais();
                }
            } else {
                tr.remove();
                calcularTotais();
            }
        }
    </script>
</body>
</html>