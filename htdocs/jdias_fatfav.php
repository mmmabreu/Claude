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
        $mensagem = "Registos guardados com sucesso!";
        $tipo_mensagem = "sucesso";
    }

    // --- 1. CRIAÇÃO AUTOMÁTICA DA TABELA NA BASE DE DADOS ---
    try {
        $sql_create = "CREATE TABLE IF NOT EXISTS jdias_fatfav (
            numero INT PRIMARY KEY,
            tipo VARCHAR(20) NOT NULL DEFAULT 'Abertura',
            data_registo DATE NOT NULL,
            nome_cliente VARCHAR(255),
            valor DECIMAL(12,2) DEFAULT 0.00,
            estado VARCHAR(20) DEFAULT 'Ativo',
            observacoes TEXT,
            id_acerto INT DEFAULT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
        $pdo->exec($sql_create);

        $col_check_id_acerto = $pdo->query("SHOW COLUMNS FROM jdias_fatfav LIKE 'id_acerto'");
        if ($col_check_id_acerto->rowCount() == 0) {
            $pdo->exec("ALTER TABLE jdias_fatfav ADD COLUMN id_acerto INT DEFAULT NULL");
        }
    } catch (Exception $e) {
        // Ignora caso a tabela já exista
    }

    // Saldos de abertura gerados por Acertos: [numero do registo => nº do acerto]
    // (ficam bloqueados — só desaparecem revertendo o acerto)
    $aberturas_acerto = [];
    try {
        $st_ab = $pdo->query("SHOW TABLES LIKE 'jdias_acertos'");
        if ($st_ab && $st_ab->rowCount() > 0) {
            foreach ($pdo->query("SELECT numero, abertura_gerada_num FROM jdias_acertos WHERE abertura_gerada_num IS NOT NULL")->fetchAll(PDO::FETCH_ASSOC) as $a) {
                $aberturas_acerto[(int)$a['abertura_gerada_num']] = (int)$a['numero'];
            }
        }
    } catch (Exception $e) {}

    // --- 2. LÓGICA DE GRAVAÇÃO (POST) ---
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        try {
            $pdo->beginTransaction();

            // Lidar com linhas eliminadas no ecrã (nunca apaga Acertados nem saldos de abertura de acertos)
            $del_json = $_POST['deleted_numeros'] ?? '[]';
            $deleted = json_decode($del_json, true);
            if (!empty($deleted) && is_array($deleted)) {
                $deleted = array_values(array_filter(array_map('intval', $deleted), fn($n) => $n > 0 && !isset($aberturas_acerto[$n])));
                if (!empty($deleted)) {
                    $placeholders = implode(',', array_fill(0, count($deleted), '?'));
                    $stmt_del = $pdo->prepare("DELETE FROM jdias_fatfav WHERE numero IN ($placeholders) AND estado != 'Acertado'");
                    $stmt_del->execute($deleted);
                }
            }

            // Inserir ou Atualizar Linhas
            if (isset($_POST['numero']) && is_array($_POST['numero'])) {
                $stmt_upsert = $pdo->prepare("
                    INSERT INTO jdias_fatfav (numero, tipo, data_registo, nome_cliente, valor, estado, observacoes)
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE
                    tipo=VALUES(tipo), data_registo=VALUES(data_registo), nome_cliente=VALUES(nome_cliente),
                    valor=VALUES(valor), estado=VALUES(estado), observacoes=VALUES(observacoes)
                ");

                for ($i = 0; $i < count($_POST['numero']); $i++) {
                    $numero = intval($_POST['numero'][$i]);
                    if ($numero <= 0) continue;

                    $tipo = $_POST['tipo'][$i] ?? 'Abertura';
                    $data_registo = !empty($_POST['data_registo'][$i]) ? $_POST['data_registo'][$i] : date('Y-m-d');
                    $nome_cliente = trim($_POST['nome_cliente'][$i] ?? '');

                    // Parse do valor com lógica de sinal forçada pelo backend por segurança
                    $valor_str = str_replace(['.', ','], ['', '.'], $_POST['valor'][$i]);
                    $valor = floatval($valor_str);

                    if ($tipo === 'Fatura' || $tipo === 'Devolução') {
                        $valor = -abs($valor); // Força sempre negativo para Fatura ou Devolução
                        if (empty($nome_cliente)) $nome_cliente = 'Cliente não identificado';
                    }

                    $estado = $_POST['estado'][$i] ?? 'Ativo';
                    if (!in_array($estado, ['Ativo', 'Inativo'])) $estado = 'Ativo';
                    $observacoes = trim($_POST['observacoes'][$i] ?? '');

                    // Saldo de abertura de um acerto: tipo e valor nunca mudam
                    if (isset($aberturas_acerto[$numero])) {
                        $tipo = 'Abertura';
                        $atual = $pdo->prepare("SELECT valor FROM jdias_fatfav WHERE numero = ?");
                        $atual->execute([$numero]);
                        $v_atual = $atual->fetchColumn();
                        if ($v_atual !== false) $valor = (float)$v_atual;
                    }

                    $stmt_upsert->execute([$numero, $tipo, $data_registo, $nome_cliente, $valor, $estado, $observacoes]);
                }
            }

            $pdo->commit();
            header("Location: jdias_fatfav.php?editado=1");
            exit;

        } catch (PDOException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            if ($e->getCode() == 23000) {
                $mensagem_erro = "Erro: Foi detetado um 'Número' duplicado. Cada registo tem de ter um número único.";
            } else {
                $mensagem_erro = "Erro na gravação: " . $e->getMessage();
            }
        }
    }

    // --- 3. CARREGAR REGISTOS EXISTENTES (Apenas não acertados, pela data do documento) ---
    $stmt = $pdo->query("SELECT * FROM jdias_fatfav WHERE estado != 'Acertado' ORDER BY data_registo ASC, numero ASC");
    $registos = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Contar registos ocultos (Acertados)
    $stmt_ocultos = $pdo->query("SELECT COUNT(*) FROM jdias_fatfav WHERE estado = 'Acertado'");
    $registos_ocultos = $stmt_ocultos->fetchColumn();

} catch (Throwable $e) {
    die("Erro Fatal: " . htmlspecialchars($e->getMessage()));
}
?>
<!DOCTYPE html>
<html lang="pt-PT">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Abertura + Faturas Cliente - J. Dias</title>
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

        /* ===== GRELHA DE EDIÇÃO (tipo Excel) ===== */
        body { padding-bottom: 130px; }
        .container { max-width: 1500px; }
        .card-grelha { padding: 0; overflow: hidden; }
        .tabela-wrap { overflow-x: auto; }
        .table-auto { width: 100%; border-collapse: collapse; table-layout: fixed; }
        .table-auto th { background: #f8f9fb; padding: 12px 6px; border-bottom: 1px solid var(--borda); border-right: 1px solid #f1f3f6; font-size: 10.5px; color: #576574; text-transform: uppercase; letter-spacing: 0.3px; text-align: center; }
        .table-auto td { border-bottom: 1px solid #f1f3f6; border-right: 1px solid #f5f6f8; padding: 0; }
        .table-auto tbody tr:hover td { background: #fcfcfd; }
        .table-auto input, .table-auto select { border: none; width: 100%; padding: 11px 6px; text-align: center; background: transparent; font-size: 12.5px; font-family: inherit; color: var(--texto); transition: all 0.2s; border-radius: 0; }
        .table-auto input:focus, .table-auto select:focus { background: #fff; box-shadow: inset 0 0 0 2px var(--cor-grelha, var(--laranja)); outline: none; }
        .table-auto select { cursor: pointer; }
        .table-auto .inp-numero { font-weight: 700; background: #fafbfc; }
        .readonly-text { color: #7f8c8d !important; font-weight: 700; background-color: #f8f9fa !important; }
        .bloqueado { pointer-events: none; }
        .table-auto tfoot td { background: #fafbfc; padding: 12px 8px; font-weight: 800; border-top: 2px solid var(--borda); }
        .td-total-label { text-align: right; color: #576574; }
        .td-total { text-align: center; font-size: 14px; }
        .col-acoes { text-align: center; white-space: nowrap; }

        .btn-row-action { background: none; border: none; cursor: pointer; font-size: 14px; padding: 7px; border-radius: 8px; transition: all 0.2s; margin: 0 1px; }
        .btn-del-row { color: #e74c3c; } .btn-del-row:hover { background: var(--vermelho-claro); }
        .btn-add-row { color: var(--verde); } .btn-add-row:hover { background: var(--verde-claro); }
        .btn-dup-row { color: #3498db; } .btn-dup-row:hover { background: var(--azul-claro); }

        .barra-grelha { display: flex; gap: 10px; flex-wrap: wrap; padding: 16px; border-top: 1px solid var(--borda); }
        .btn-linha { background: var(--cor-grelha, var(--laranja)); color: #fff; border: none; padding: 10px 18px; border-radius: 10px; cursor: pointer; display: inline-flex; align-items: center; gap: 8px; font-size: 13px; font-weight: 700; transition: filter 0.2s; }
        .btn-linha:hover { filter: brightness(0.92); }
        .btn-linha.verde { background: var(--verde); }

        /* Barra flutuante (totais + imprimir + gravar) */
        .barra-flutuante { position: fixed; bottom: 20px; right: 20px; background: #fff; padding: 12px 14px 12px 22px; border-radius: 16px; box-shadow: 0 10px 30px rgba(0,0,0,0.14); display: flex; align-items: center; gap: 14px; z-index: 1000; border: 1px solid var(--borda); }
        .bf-totais { display: flex; gap: 18px; margin-right: 6px; }
        .bf-total { text-align: right; }
        .bf-total + .bf-total { padding-left: 18px; border-left: 2px solid var(--borda); }
        .bf-total small { font-size: 10px; font-weight: 700; color: #576574; display: block; text-transform: uppercase; letter-spacing: 0.3px; }
        .bf-total b { font-size: 18px; font-weight: 800; }
        .btn-save { background: var(--cor-grelha, var(--laranja)); color: #fff; padding: 12px 20px; border-radius: 10px; border: none; font-weight: 800; cursor: pointer; font-size: 13.5px; display: inline-flex; align-items: center; gap: 8px; text-decoration: none; transition: filter 0.2s; white-space: nowrap; }
        .btn-save:hover { filter: brightness(0.92); }
        .btn-save.cinza { background: #576574; }

        /* Tablet: a grelha desliza na horizontal */
        @media (max-width: 1024px) {
            .table-auto { min-width: 980px; }
            .barra-flutuante { left: 20px; justify-content: space-between; }
        }

        /* Telemóvel: cada linha passa a ser um cartão */
        @media (max-width: 768px) {
            body { padding-bottom: 150px; }
            .card-grelha { background: transparent; border: none; box-shadow: none; overflow: visible; }
            .tabela-wrap { overflow: visible; }
            .table-auto { min-width: 0; table-layout: auto; }
            .table-auto, .table-auto tbody, .table-auto tr, .table-auto td { display: block; width: 100%; }
            .table-auto thead, .table-auto tfoot { display: none; }
            .table-auto tbody tr { background: #fff; border: 1px solid var(--borda); border-radius: 14px; margin-bottom: 12px; padding: 12px; display: grid; grid-template-columns: 1fr 1fr; gap: 8px 10px; box-shadow: 0 2px 6px rgba(0,0,0,0.03); }
            .table-auto tbody tr:hover td { background: transparent; }
            .table-auto td { border: none; padding: 0; }
            .table-auto td::before { content: attr(data-label); display: block; font-size: 10px; font-weight: 700; color: var(--suave); text-transform: uppercase; letter-spacing: 0.3px; margin-bottom: 3px; }
            .table-auto input, .table-auto select { border: 1px solid #dcdde1; border-radius: 9px; padding: 10px; font-size: 16px; text-align: left; background: #fff; }
            .table-auto td.col-larga { grid-column: 1 / -1; }
            .table-auto td.col-acoes { grid-column: 1 / -1; display: flex; justify-content: flex-end; gap: 4px; border-top: 1px dashed var(--borda); padding-top: 8px; }
            .table-auto td.col-acoes::before { display: none; }
            .btn-row-action { font-size: 16px; padding: 9px 11px; background: #f8f9fb; }
            .barra-grelha { border: none; padding: 4px 0 0; }
            .barra-grelha .btn-linha { flex: 1; justify-content: center; padding: 13px; }

            .barra-flutuante { left: 10px; right: 10px; bottom: 10px; padding: 10px; flex-wrap: wrap; gap: 8px; border-radius: 14px; }
            .bf-totais { width: 100%; justify-content: space-around; margin: 0; }
            .bf-total { text-align: center; }
            .bf-total b { font-size: 16px; }
            .barra-flutuante .btn-save { flex: 1; justify-content: center; padding: 12px 10px; }
        }
        @media (max-width: 400px) {
            .btn-save .txt { display: none; }
        }

        :root { --cor-grelha: #8e44ad; }
        .container { max-width: 1300px; }
        /* Cores Tipos */
        .bg-abertura { font-weight: bold; color: var(--roxo) !important; }
        .bg-fatura { font-weight: bold; color: var(--vermelho) !important; }
        .bg-devolucao { font-weight: bold; color: var(--laranja) !important; }
        .total-neg { color: var(--vermelho) !important; }
        /* Input obrigatório visualmente */
        .input-req { box-shadow: inset 0 -2px 0 #e74c3c !important; }
        /* Saldo de abertura gerado por um acerto (bloqueado) */
        tr.linha-abertura td { background: #fef9e7; }
        .tag-abertura { display: inline-block; background: #f9e79f; color: #7d6608; font-size: 9.5px; font-weight: 800; padding: 2px 7px; border-radius: 10px; text-transform: uppercase; margin: 2px 0 4px; }
        @media (max-width: 768px) {
            .table-auto tbody tr.linha-abertura { background: #fffbea; border-color: #f9e79f; }
            tr.linha-abertura td { background: transparent; }
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

        <?php if ($mensagem): ?>
            <div class="alert <?= $tipo_mensagem === 'sucesso' ? 'alert-sucesso' : 'alert-erro' ?>"><i class="fa-solid fa-circle-check"></i> <?= htmlspecialchars($mensagem, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>
        <?php if ($mensagem_erro): ?>
            <div class="alert alert-erro"><i class="fa-solid fa-triangle-exclamation"></i> <?= htmlspecialchars($mensagem_erro, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>

        <div class="page-head">
            <div>
                <div class="eyebrow">J. Dias</div>
                <h1 class="page-title">Abertura + Faturas Cliente</h1>
                <div class="page-sub">Faturas Cliente e Devoluções ficam sempre com valor negativo e precisam do nome do cliente.</div>
            </div>
            <?php if ($registos_ocultos > 0): ?>
                <a href="jdias_arquivo.php?tipo=fatfav" class="chip" title="Ver no Arquivo"><i class="fa-solid fa-eye-slash"></i> <?= $registos_ocultos ?> acertado(s) no Arquivo</a>
            <?php endif; ?>
        </div>

        <form method="POST" id="fatfavForm" onsubmit="return validarFormulario()">
            <input type="hidden" name="deleted_numeros" id="deleted_numeros" value="[]">

            <div class="card card-grelha">
                <div class="tabela-wrap">
                <table class="table-auto">
                    <thead>
                        <tr>
                            <th style="width: 10%;">Número *</th>
                            <th style="width: 12%;">Tipo *</th>
                            <th style="width: 12%;">Data *</th>
                            <th style="width: 19%;">Nome Cliente</th>
                            <th style="width: 12%;">Valor (€) *</th>
                            <th style="width: 10%;">Estado</th>
                            <th style="width: 16%;">Observações</th>
                            <th style="width: 9%;">Ações</th>
                        </tr>
                    </thead>
                    <tbody id="tabela_fatfav">
                        <?php foreach ($registos as $row):
                            $n_acerto_ab = $aberturas_acerto[(int)$row['numero']] ?? null;
                        ?>
                        <tr class="<?= $n_acerto_ab ? 'linha-abertura' : '' ?>" <?= $n_acerto_ab ? 'data-acerto="' . $n_acerto_ab . '"' : '' ?>>
                            <td data-label="Número *">
                                <input type="number" name="numero[]" class="inp-numero <?= $n_acerto_ab ? 'readonly-text' : '' ?>" value="<?= htmlspecialchars($row['numero']) ?>" required <?= $n_acerto_ab ? 'readonly' : '' ?>>
                            </td>
                            <td data-label="Tipo *">
                                <?php if ($n_acerto_ab): ?><span class="tag-abertura" title="Gerado pelo Acerto nº <?= $n_acerto_ab ?>">Saldo Acerto nº <?= $n_acerto_ab ?></span><?php endif; ?>
                                <select name="tipo[]" class="sel-tipo <?= $n_acerto_ab ? 'bloqueado' : '' ?>" onchange="avaliarTipo(this)" <?= $n_acerto_ab ? 'tabindex="-1"' : '' ?>>
                                    <option value="Abertura" <?= ($row['tipo'] == 'Abertura') ? 'selected' : '' ?>>Abertura</option>
                                    <option value="Fatura" <?= ($row['tipo'] == 'Fatura') ? 'selected' : '' ?>>Fatura</option>
                                    <option value="Devolução" <?= ($row['tipo'] == 'Devolução') ? 'selected' : '' ?>>Devolução</option>
                                </select>
                            </td>
                            <td data-label="Data *"><input type="date" name="data_registo[]" value="<?= htmlspecialchars($row['data_registo']) ?>" required></td>
                            <td data-label="Nome Cliente"><input type="text" name="nome_cliente[]" class="inp-cliente" value="<?= htmlspecialchars($row['nome_cliente'] ?? '') ?>"></td>
                            <td data-label="Valor (€) *"><input type="text" name="valor[]" class="inp-valor readonly-text" value="<?= number_format($row['valor'], 2, ',', '') ?>" readonly></td>
                            <td data-label="Estado">
                                <select name="estado[]">
                                    <option value="Ativo" <?= $row['estado'] == 'Ativo' ? 'selected' : '' ?>>Ativo</option>
                                    <option value="Inativo" <?= $row['estado'] == 'Inativo' ? 'selected' : '' ?>>Inativo</option>
                                </select>
                            </td>
                            <td data-label="Observações" class="col-larga"><input type="text" name="observacoes[]" value="<?= htmlspecialchars($row['observacoes'] ?? '') ?>" placeholder="..."></td>
                            <td class="col-acoes">
                                <button type="button" class="btn-row-action btn-add-row" onclick="insertRowAfter(this)" title="Nova Linha Abaixo"><i class="fa-solid fa-plus"></i></button>
                                <button type="button" class="btn-row-action btn-dup-row" onclick="duplicateRow(this)" title="Duplicar Linha"><i class="fa-solid fa-copy"></i></button>
                                <button type="button" class="btn-row-action btn-del-row" onclick="removeRow(this)" title="Apagar Linha"><i class="fa-solid fa-trash-can"></i></button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr>
                            <td colspan="4" class="td-total-label">SALDO GLOBAL:</td>
                            <td class="td-total" id="soma_valor" style="color: var(--azul);">0,00 €</td>
                            <td colspan="3"></td>
                        </tr>
                    </tfoot>
                </table>
                </div>

                <div class="barra-grelha">
                    <button type="button" onclick="addLinha()" class="btn-linha" title="Adicionar no Final"><i class="fa-solid fa-plus"></i> Adicionar Registo</button>
                </div>
            </div>

            <div class="barra-flutuante">
                <div class="bf-totais">
                    <div class="bf-total"><small>Saldo Atual</small><b id="float_soma_valor" style="color: var(--azul);">0,00 €</b></div>
                </div>
                <a href="jdias_fatfav_pdf.php" target="_blank" class="btn-save cinza" title="Imprimir PDF"><i class="fa-solid fa-print"></i> <span class="txt">Imprimir</span></a>
                <button type="submit" class="btn-save" onclick="guardarScrollY()"><i class="fa-solid fa-floppy-disk"></i> <span class="txt">Gravar</span></button>
            </div>
        </form>
    </div>

    <script>
        // Array global para guardar as linhas eliminadas - IMPORTANTE PARA APAGAR LINHAS
        let deletedItems = [];

        // Guarda a posição do scroll
        function guardarScrollY() {
            try { sessionStorage.setItem('fatfavScrollY', window.scrollY); } catch (e) {}
        }

        window.addEventListener('DOMContentLoaded', () => {
            try {
                const scrollSalvo = sessionStorage.getItem('fatfavScrollY');
                if (scrollSalvo !== null) {
                    window.scrollTo(0, parseInt(scrollSalvo));
                    sessionStorage.removeItem('fatfavScrollY');
                }
            } catch (e) {}

            // Sem registos: começa com uma linha vazia
            if (!document.querySelector('#tabela_fatfav tr')) addLinha();

            // Inicializar cores e regras do Tipo
            document.querySelectorAll('.sel-tipo').forEach(el => avaliarTipo(el));
            calcularTotais();
        });

        // Formatação Numérica Ponto/Vírgula mantendo o sinal Negativo
        function strToNum(s) {
            if (!s) return 0;
            let str = s.toString().trim();
            let isNegative = str.indexOf('-') === 0;

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
            str = str.replace(/[^\d\.]/g, '');
            let val = parseFloat(str) || 0;
            return isNegative ? -Math.abs(val) : Math.abs(val);
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
            avaliarSinal(el); // Força sinal antes de formatar
            let val = strToNum(el.value);
            if (val === 0) {
                el.value = "0,00";
            } else {
                el.value = numToStr(val);
            }
            calcularTotais();
        }

        function avaliarTipo(selectElement) {
            const tr = selectElement.closest('tr');
            const tipo = selectElement.value;
            const inputCliente = tr.querySelector('.inp-cliente');
            const inputValor = tr.querySelector('.inp-valor');
            const bloqueado = selectElement.classList.contains('bloqueado');

            // Estética
            let classe = 'bg-abertura';
            if (tipo === 'Fatura') classe = 'bg-fatura';
            else if (tipo === 'Devolução') classe = 'bg-devolucao';
            selectElement.className = 'sel-tipo ' + classe + (bloqueado ? ' bloqueado' : '');

            // Regra Nome Cliente Obrigatório para Fatura e Devolução
            if (tipo === 'Fatura' || tipo === 'Devolução') {
                inputCliente.classList.add('input-req');
                inputCliente.placeholder = "Obrigatório...";
            } else {
                inputCliente.classList.remove('input-req');
                inputCliente.placeholder = "Opcional p/ Abertura";
            }

            avaliarSinal(inputValor);
            calcularTotais();
        }

        function avaliarSinal(inputValor) {
            const tr = inputValor.closest('tr');
            const tipo = tr.querySelector('.sel-tipo').value;
            let val = strToNum(inputValor.value);

            if (tipo === 'Fatura' || tipo === 'Devolução') {
                val = -Math.abs(val);
                if (val === 0) val = "-"; // Permite que a pessoa escreva se estiver a começar
            }
            // Para abertura, permite qualquer sinal.

            if (document.activeElement === inputValor && inputValor.value !== '-') {
                 let cursorPosition = inputValor.selectionStart;

                 // Adiciona '-' se era fatura/devolução e não tem
                 if ((tipo === 'Fatura' || tipo === 'Devolução') && !inputValor.value.includes('-') && strToNum(inputValor.value) !== 0) {
                     inputValor.value = '-' + inputValor.value;
                     cursorPosition++;
                 }

                 inputValor.setSelectionRange(cursorPosition, cursorPosition);
            } else if (document.activeElement !== inputValor) {
                 inputValor.value = (typeof val === 'number') ? numToStr(val) : '0,00';
            }

            calcularTotais();
        }

        function validarFormulario() {
            let formValido = true;
            document.querySelectorAll('#tabela_fatfav tr').forEach(tr => {
                const tipo = tr.querySelector('.sel-tipo').value;
                const cliente = tr.querySelector('.inp-cliente');

                if ((tipo === 'Fatura' || tipo === 'Devolução') && cliente.value.trim() === '') {
                    cliente.style.outline = "2px solid #e74c3c";
                    formValido = false;
                } else {
                    cliente.style.outline = "";
                }
            });

            if (!formValido) {
                alert("Por favor, preencha o Nome de Cliente em todos os registos do tipo Fatura ou Devolução.");
            }
            return formValido;
        }

        // CÁLCULO DE TOTAIS
        function calcularTotais() {
            let totalValor = 0;

            document.querySelectorAll('#tabela_fatfav tr').forEach(tr => {
                let valInput = tr.querySelector('.inp-valor');
                if (valInput) {
                    totalValor += strToNum(valInput.value);
                }
            });

            const strVal = numToStr(totalValor) + ' €';
            ['soma_valor', 'float_soma_valor'].forEach(id => {
                const el = document.getElementById(id);
                if (!el) return;
                el.innerText = strVal;
                el.classList.toggle('total-neg', totalValor < 0);
            });
        }

        function esc(v) { return String(v ?? '').replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;'); }

        function getRowHTML(data = {}) {
            let num = data.numero || '';
            let t = data.tipo || 'Abertura';
            let dt = data.data_registo || '';
            let cli = data.nome_cliente || '';
            let val = data.valor || '0,00';
            let est = data.estado || 'Ativo';
            let obs = data.observacoes || '';

            return `
                <td data-label="Número *"><input type="number" name="numero[]" class="inp-numero" value="${esc(num)}" required></td>
                <td data-label="Tipo *">
                    <select name="tipo[]" class="sel-tipo" onchange="avaliarTipo(this)">
                        <option value="Abertura" ${t == 'Abertura' ? 'selected' : ''}>Abertura</option>
                        <option value="Fatura" ${t == 'Fatura' ? 'selected' : ''}>Fatura</option>
                        <option value="Devolução" ${t == 'Devolução' ? 'selected' : ''}>Devolução</option>
                    </select>
                </td>
                <td data-label="Data *"><input type="date" name="data_registo[]" value="${esc(dt)}" required></td>
                <td data-label="Nome Cliente"><input type="text" name="nome_cliente[]" class="inp-cliente" value="${esc(cli)}" placeholder="Opcional p/ Abertura"></td>
                <td data-label="Valor (€) *"><input type="text" name="valor[]" class="inp-valor" value="${esc(val)}" inputmode="decimal" oninput="forceComma(this); avaliarSinal(this);" onblur="formatField(this)" required></td>
                <td data-label="Estado">
                    <select name="estado[]">
                        <option value="Ativo" ${est == 'Ativo' ? 'selected' : ''}>Ativo</option>
                        <option value="Inativo" ${est == 'Inativo' ? 'selected' : ''}>Inativo</option>
                    </select>
                </td>
                <td data-label="Observações" class="col-larga"><input type="text" name="observacoes[]" value="${esc(obs)}" placeholder="..."></td>
                <td class="col-acoes">
                    <button type="button" class="btn-row-action btn-add-row" onclick="insertRowAfter(this)" title="Nova Linha Abaixo"><i class="fa-solid fa-plus"></i></button>
                    <button type="button" class="btn-row-action btn-dup-row" onclick="duplicateRow(this)" title="Duplicar Linha"><i class="fa-solid fa-copy"></i></button>
                    <button type="button" class="btn-row-action btn-del-row" onclick="removeRow(this)" title="Apagar Linha"><i class="fa-solid fa-trash-can"></i></button>
                </td>
            `;
        }

        function addLinha() {
            const tbody = document.getElementById('tabela_fatfav');
            const tr = document.createElement('tr');
            tr.innerHTML = getRowHTML();
            tbody.appendChild(tr);
            avaliarTipo(tr.querySelector('.sel-tipo'));
        }

        function insertRowAfter(btn) {
            const currentRow = btn.closest('tr');
            const newRow = document.createElement('tr');
            newRow.innerHTML = getRowHTML();
            currentRow.parentNode.insertBefore(newRow, currentRow.nextSibling);
            avaliarTipo(newRow.querySelector('.sel-tipo'));
        }

        function duplicateRow(btn) {
            const currentRow = btn.closest('tr');
            const newRow = document.createElement('tr');

            // Um saldo de abertura de acerto é duplicado como Abertura manual normal
            newRow.innerHTML = getRowHTML({
                tipo: currentRow.querySelector('select[name="tipo[]"]').value,
                data_registo: currentRow.querySelector('input[name="data_registo[]"]').value,
                nome_cliente: currentRow.querySelector('input[name="nome_cliente[]"]').value,
                valor: currentRow.querySelector('input[name="valor[]"]').value,
                estado: currentRow.querySelector('select[name="estado[]"]').value,
                observacoes: currentRow.querySelector('input[name="observacoes[]"]').value
            });

            currentRow.parentNode.insertBefore(newRow, currentRow.nextSibling);
            avaliarTipo(newRow.querySelector('.sel-tipo'));
            calcularTotais();
        }

        function removeRow(btn) {
            const tr = btn.closest('tr');
            const num = tr.querySelector('.inp-numero').value;

            // Saldo de abertura gerado por um acerto: só sai revertendo o acerto
            if (tr.dataset.acerto) {
                alert("Este registo é o saldo de abertura gerado pelo Acerto nº " + tr.dataset.acerto + ".\n\nPara o remover, reverta esse acerto na página Acertos.");
                return;
            }

            if (num !== '') {
                if (confirm("Tem a certeza que deseja apagar o registo Número " + num + "?")) {
                    deletedItems.push(num);
                    document.getElementById('deleted_numeros').value = JSON.stringify(deletedItems);
                    tr.remove();
                    calcularTotais();
                }
            } else {
                tr.remove();
                calcularTotais();
            }
        }
    </script>
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
