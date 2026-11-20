<?php
session_start();
require_once 'db.php';
require_once 'functions.php';

if (!isset($_SESSION['user_id'])) { header("Location: index.php"); exit; }

$perfil_user = $_SESSION['perfil'] ?? '';
$is_admin = in_array($perfil_user, ['admin', 'administrativo']);

$stmt_perm = $pdo->prepare("SELECT modulo FROM permissoes WHERE grupo = :perfil AND pode_aceder = 1");
$stmt_perm->execute(['perfil' => $perfil_user]);
$meus_acessos = $stmt_perm->fetchAll(PDO::FETCH_COLUMN);

if (!$is_admin && !in_array('termos_intrusao', $meus_acessos) && !in_array('termos', $meus_acessos)) {
    header("Location: index.php?erro=sem_permissao"); exit;
}

$nome_completo = $_SESSION['nome'] ?? 'Utilizador';
$primeiro_nome = explode(' ', trim($nome_completo))[0];

// FAILSAFE: Garantir que a coluna 'numero' existe na tabela de equipamentos
try {
    $pdo->exec("ALTER TABLE termos_intrusao_equipamentos ADD COLUMN numero VARCHAR(50) AFTER id_termo");
} catch(Exception $e) {}

// FAILSAFE: Localização da central e, nos equipamentos, Marca e Nº de Série separados
try { $pdo->exec("ALTER TABLE termos_intrusao ADD COLUMN localizacao_central VARCHAR(255) NULL AFTER ref_central"); } catch(Exception $e) {}
try { $pdo->exec("ALTER TABLE termos_intrusao ADD COLUMN numero_serie VARCHAR(100) NULL AFTER ref_central"); } catch(Exception $e) {}
try { $pdo->exec("ALTER TABLE termos_intrusao_equipamentos ADD COLUMN marca VARCHAR(100) NULL AFTER equipamento"); } catch(Exception $e) {}
try { $pdo->exec("ALTER TABLE termos_intrusao_equipamentos ADD COLUMN numero_serie VARCHAR(100) NULL AFTER modelo"); } catch(Exception $e) {}

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

$mensagem = ''; 
$tipo_mensagem = '';

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$modo_editar = !empty($id);

// Modificado para selecionar também a morada e a cidade/concelho
$clientes = $pdo->query("SELECT id, nome, morada, cidade FROM clientes WHERE ativo = 1 ORDER BY nome ASC")->fetchAll(PDO::FETCH_ASSOC);

// 1. CARREGAR DADOS
$equipamentos_tabela = [];

if ($modo_editar) {
    $stmt = $pdo->prepare("SELECT * FROM termos_intrusao WHERE id = ?");
    $stmt->execute([$id]);
    $doc = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$doc) { header("Location: termos_intrusao.php"); exit; }
    $titulo_pagina = "Editar Alarme de Intrusão #" . $doc['referencia_termo'];

    // Carregar equipamentos associados
    $stmt_eq = $pdo->prepare("SELECT * FROM termos_intrusao_equipamentos WHERE id_termo = ? ORDER BY id ASC");
    $stmt_eq->execute([$id]);
    $equipamentos_tabela = $stmt_eq->fetchAll(PDO::FETCH_ASSOC);

} else {
    $ano_atual_curto = date('y');
    $stmt_ref = $pdo->query("SELECT valor FROM configuracoes WHERE chave = 'ultimo_numero_projeto'");
    $ultima_ref = $stmt_ref->fetchColumn();
    if ($ultima_ref && substr($ultima_ref, 0, 2) === $ano_atual_curto) {
        $referencia_gerada = (string)((int)$ultima_ref + 1);
    } else {
        $referencia_gerada = $ano_atual_curto . "0100";
    }
    $titulo_pagina = "Nova Instalação de Intrusão";
    $doc = ['referencia_termo' => $referencia_gerada, 'data_emissao' => date('Y-m-d')];
}

// 2. PROCESSAR GRAVAÇÃO
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
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

        if ($modo_editar) {
            $sql = "UPDATE termos_intrusao SET 
                    data_emissao=?, id_cliente=?, nome_obra=?, morada_obra=?, 
                    marca_central=?, ref_central=?, numero_serie=?, localizacao_central=?
                    WHERE id=?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                $_POST['data_emissao'], $id_cliente_final, $_POST['nome_obra'], $_POST['morada_obra'],
                $_POST['marca_central'], $_POST['ref_central'], trim($_POST['numero_serie'] ?? ''), trim($_POST['localizacao_central'] ?? ''), $id
            ]);
            $id_atual = $id;
        } else {
            $sql = "INSERT INTO termos_intrusao (
                        referencia_termo, data_emissao, id_cliente, nome_obra, morada_obra, 
                        marca_central, ref_central, numero_serie, localizacao_central
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                $_POST['referencia_termo'], $_POST['data_emissao'], $id_cliente_final, $_POST['nome_obra'], $_POST['morada_obra'],
                $_POST['marca_central'], $_POST['ref_central'], trim($_POST['numero_serie'] ?? ''), trim($_POST['localizacao_central'] ?? '')
            ]);
            $id_atual = $pdo->lastInsertId();
            
            // Atualizar sequência global apenas se for um novo registo
            $stmt_seq = $pdo->prepare("INSERT INTO configuracoes (chave, valor) VALUES ('ultimo_numero_projeto', ?) ON DUPLICATE KEY UPDATE valor = ?");
            $stmt_seq->execute([$_POST['referencia_termo'], $_POST['referencia_termo']]);
        }

        // Processar Linhas de Equipamento
        $pdo->prepare("DELETE FROM termos_intrusao_equipamentos WHERE id_termo = ?")->execute([$id_atual]);
        if (isset($_POST['equipamento']) && is_array($_POST['equipamento'])) {
            $stmt_eq = $pdo->prepare("INSERT INTO termos_intrusao_equipamentos (id_termo, numero, equipamento, marca, modelo, numero_serie, localizacao) VALUES (?, ?, ?, ?, ?, ?, ?)");
            for ($i = 0; $i < count($_POST['equipamento']); $i++) {
                $num = trim($_POST['numero_eq'][$i] ?? '');
                $eq = trim($_POST['equipamento'][$i] ?? '');
                $marca = trim($_POST['marca_eq'][$i] ?? '');
                $mod = trim($_POST['modelo'][$i] ?? '');
                $serie = trim($_POST['serie_eq'][$i] ?? '');
                $loc = trim($_POST['localizacao'][$i] ?? '');
                
                if ($num !== '' || $eq !== '' || $marca !== '' || $mod !== '' || $serie !== '' || $loc !== '') {
                    $stmt_eq->execute([$id_atual, $num, $eq, $marca, $mod, $serie, $loc]);
                }
            }
        }

        $pdo->commit();
        header("Location: termos_intrusao.php?sucesso=1");
        exit;
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $mensagem = $e->getMessage();
        $tipo_mensagem = "erro";
    }
}
?>
<!DOCTYPE html>
<html lang="pt-PT">
<head>
    <meta charset="UTF-8">
    <title><?= $titulo_pagina ?> - MAB</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { font-family: system-ui, sans-serif; background: #f7f9fc; margin: 0; color: #333; padding-bottom: 90px; }
        * { box-sizing: border-box; }
        .topbar { background-color: #fff; padding: 10px 40px; box-shadow: 0 2px 15px rgba(0,0,0,0.05); display: flex; justify-content: space-between; align-items: center; position: sticky; top: 0; z-index: 100; }
        .topbar-left .logo { height: 35px; }
        .topbar-right { display: flex; align-items: center; gap: 20px; }
        .btn-back { color: #576574; text-decoration: none; font-size: 16px; font-weight: 600; display: flex; align-items: center; gap: 8px; transition: color 0.2s; }
        .btn-back:hover { color: #34495e; }
        
        .container { max-width: 1200px; margin: 20px auto; padding: 0 20px; }
        .page-header { margin-bottom: 25px; border-bottom: 1px solid #e1e5ea; padding-bottom: 15px; }
        .page-title { margin: 0; color: #2c3e50; font-size: 22px; font-weight: 800; display: flex; align-items: center; gap: 10px; }
        .card { background: #fff; padding: 20px; border-radius: 12px; border: 1px solid #e1e5ea; box-shadow: 0 2px 10px rgba(0,0,0,0.03); margin-bottom: 20px; }
        h2 { font-size: 14px; color: #34495e; margin: 0 0 15px 0; border-bottom: 1px solid #f1f2f6; padding-bottom: 8px; text-transform: uppercase; display:flex; gap:8px; align-items:center;}
        
        .form-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 15px; }
        .form-group { display: flex; flex-direction: column; }
        .form-group.full { grid-column: 1 / -1; }
        
        label { font-weight: 600; margin-bottom: 5px; font-size: 11px; color: #576574; text-transform: uppercase; }
        input, select, textarea { padding: 10px 12px; border: 1px solid #dcdde1; border-radius: 6px; font-size: 14px; outline: none; transition: 0.2s; background: #fff; font-family: inherit;}
        input:focus, select:focus, textarea:focus { border-color: #34495e; box-shadow: 0 0 0 3px rgba(52, 73, 94, 0.1); }
        
        /* Tabela Dinâmica de Equipamentos */
        .items-table { width: 100%; border-collapse: collapse; margin-top: 5px; }
        .items-table th { padding: 8px; background: #f8f9fa; color: #576574; font-size: 11px; font-weight: 600; text-transform: uppercase; text-align: left; border-bottom: 2px solid #dee2e6; }
        .items-table td { padding: 4px; border-bottom: 1px solid #f1f2f6; vertical-align: top; }
        .items-table input { width: 100%; box-sizing: border-box; }
        
        /* Alteração de Botões para Lado a Lado */
        .col-del { width: 85px; text-align: center; white-space: nowrap; } 
        .btn-row-action { background: none; border: none; cursor: pointer; font-size: 15px; padding: 4px; border-radius: 4px; transition: 0.2s; display: inline-flex; align-items: center; justify-content: center;}
        .btn-del-row { color: #e74c3c; }
        .btn-del-row:hover { background: #fdeded; }
        .btn-add-row { color: #2ecc71; margin-right: 2px;}
        .btn-add-row:hover { background: #e8f5e9; }

        /* Controles de Movimentação e Checkbox */
        .chk-wrapper { display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 4px; padding: 2px 0; }
        .chk-select-row { width: 15px; height: 15px; accent-color: #3498db; cursor: pointer; margin:0; }
        .move-controls { display: none; flex-direction: column; cursor: pointer; color: #cbd5e1; font-size: 13px; gap: 2px; align-items: center; }
        .move-controls i:hover { color: #3498db; }
        
        .floating-action-bar { position: fixed; bottom: 20px; right: 20px; background: #fff; padding: 15px; border-radius: 12px; box-shadow: 0 5px 20px rgba(0,0,0,0.15); z-index: 1000; border: 1px solid #e1e5ea; }
        .btn-save { background: #34495e; color: #fff; padding: 12px 30px; border-radius: 8px; border: none; font-weight: 700; font-size: 14px; cursor: pointer; transition: 0.2s; display:flex; align-items:center; gap:8px;}
        .btn-save:hover { background: #2c3e50; }

        /* Aviso de sirene exterior (comunicação à PSP/GNR) */
        .alerta-sirene { display: none; background: #fff4e5; border: 1px solid #f39c12; border-left: 5px solid #e67e22; color: #7a4a0a; padding: 12px 15px; border-radius: 8px; margin-bottom: 12px; font-size: 13px; line-height: 1.5; }
        .alerta-sirene ul { margin: 6px 0 0 0; padding-left: 20px; }
        .row-sirene-ext td { background: #fff7ed; }

        .alert { padding: 15px; border-radius: 8px; margin-bottom: 20px; text-align: center; font-weight: 600; background: #fdeded; color: #c0392b; border: 1px solid #f9cccc; }

        @media (max-width: 768px) {
            .topbar { padding: 10px 15px; }
            .btn-back span { display: none; }
            .page-header { margin-bottom: 15px; padding-bottom: 10px; }
            .page-title { font-size: 18px; }
            .card { padding: 15px; margin-bottom: 15px; }
            .items-table { display: block; overflow-x: auto; white-space: nowrap; }
            .floating-action-bar { left: 15px; right: 15px; bottom: 15px; padding: 10px; display: flex; justify-content: center;}
            .btn-save { width: 100%; justify-content: center; padding: 15px; font-size: 15px;}
        }
    </style>
</head>
<body>

    <datalist id="lista_equip_intrusao">
        <option value="Central de Intrusão">
        <option value="Teclado">
        <option value="Detetor de Movimento">
        <option value="Detetor Dupla Tecnologia">
        <option value="Detetor Magnético">
        <option value="Sirene Exterior">
        <option value="Sirene Interior">
        <option value="Módulo de Expansão">
        <option value="Módulo de Comunicação IP/GSM">
        <option value="Bateria">
        <option value="Fonte de Alimentação">
    </datalist>

    <header class="topbar">
        <div class="topbar-left"><a href="index.php"><img src="mab.png" alt="MAB" class="logo"></a></div>
        <div class="topbar-right">
            <a href="termos_intrusao.php" class="btn-back" title="Voltar à Listagem"><i class="fa-solid fa-arrow-left"></i> <span>Voltar</span></a>
        </div>
    </header>

    <div class="container">
        <?php if ($mensagem): ?><div class="alert"><?= htmlspecialchars($mensagem) ?></div><?php endif; ?>

        <div class="page-header">
            <h1 class="page-title"><i class="fa-solid fa-shield-halved"></i> <?= $titulo_pagina ?></h1>
        </div>

        <form method="POST" id="formIntrusao">
            <div class="card">
                <h2><i class="fa-solid fa-building"></i> Cliente e Obra</h2>
                <div class="form-grid">
                    <div class="form-group"><label>Referência Nº</label><input type="text" name="referencia_termo" value="<?= htmlspecialchars($doc['referencia_termo']) ?>" readonly style="background:#fdfdfd; font-weight:bold; color:#34495e;"></div>
                    <div class="form-group"><label>Data</label><input type="date" name="data_emissao" value="<?= htmlspecialchars($doc['data_emissao']) ?>" required></div>
                    <div class="form-group">
                        <label>Cliente *</label>
                        <select name="id_cliente" id="select_cliente" required onchange="aoMudarCliente(this.value)">
                            <option value="">-- Selecione --</option>
                            <option value="novo" style="font-weight:bold; color:#f39c12;">+ Criar Novo Cliente</option>
                            <?php foreach($clientes as $c): ?>
                                <option value="<?= $c['id'] ?>" data-morada="<?= htmlspecialchars($c['morada'] ?? '') ?>" data-cidade="<?= htmlspecialchars($c['cidade'] ?? '') ?>" <?= (($doc['id_cliente'] ?? '') == $c['id']) ? 'selected' : '' ?>><?= htmlspecialchars($c['nome']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group"><label>Obra / Local *</label><input type="text" name="nome_obra" value="<?= htmlspecialchars($doc['nome_obra'] ?? '') ?>" required></div>
                    <div class="form-group full"><label>Morada da Instalação</label><input type="text" name="morada_obra" value="<?= htmlspecialchars($doc['morada_obra'] ?? '') ?>"></div>

                    <div id="form_novo_cliente" class="form-group full" style="display:none; background: #fffde7; border: 1px dashed #f39c12; padding: 15px; border-radius: 8px;">
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

            <div class="card">
                <h2><i class="fa-solid fa-microchip"></i> Informação da Central Base</h2>
                <div class="form-grid">
                    <div class="form-group"><label>Marca da Central</label><input type="text" name="marca_central" value="<?= htmlspecialchars($doc['marca_central'] ?? '') ?>" placeholder="Ex: Bosch, DSC, Paradox..."></div>
                    <div class="form-group"><label>Referência / Modelo da Central</label><input type="text" name="ref_central" value="<?= htmlspecialchars($doc['ref_central'] ?? '') ?>"></div>
                    <div class="form-group"><label>Nº de Série da Central</label><input type="text" name="numero_serie" value="<?= htmlspecialchars($doc['numero_serie'] ?? '') ?>" placeholder="Nº de série"></div>
                    <div class="form-group"><label>Localização da Central</label><input type="text" name="localizacao_central" value="<?= htmlspecialchars($doc['localizacao_central'] ?? '') ?>" placeholder="Ex: Arrumos, Garagem, Hall de Entrada..."></div>
                </div>
            </div>

            <div class="card">
                <h2>
                    <span><i class="fa-solid fa-list-check"></i> Equipamentos Instalados</span>
                    <button type="button" onclick="limparTabela()" style="background:none; border:none; color:#e74c3c; font-size:12px; cursor:pointer; font-weight:bold; margin-left:auto;"><i class="fa-solid fa-eraser"></i> Limpar Tabela</button>
                </h2>
                
                <div id="alertaSirene" class="alerta-sirene"></div>

                <table class="items-table" id="tabelaEquip">
                    <thead>
                        <tr>
                            <th style="width:40px; text-align:center;"><input type="checkbox" onchange="toggleAllRows('tabelaEquip', this)"></th>
                            <th width="6%">Nº</th>
                            <th width="19%">Equipamento / Tipo</th>
                            <th width="14%">Marca</th>
                            <th width="16%">Modelo</th>
                            <th width="15%">Nº de Série</th>
                            <th width="22%">Localização (Zona)</th>
                            <th class="col-del">Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                        <!-- INSERÇÃO VIA JS -->
                    </tbody>
                    <tfoot>
                        <tr>
                            <td colspan="8" style="border:none; padding-top:10px;">
                                <button type="button" style="background:#576574; color:#fff; border:none; padding:8px 15px; border-radius:6px; cursor:pointer; font-size:12px; font-weight:bold;" onclick="addRow()"><i class="fa-solid fa-plus"></i> Adicionar Equipamento</button>
                            </td>
                        </tr>
                    </tfoot>
                </table>
            </div>

            <div class="floating-action-bar">
                <button type="submit" class="btn-save"><i class="fa-solid fa-floppy-disk"></i> GRAVAR FICHA DE INTRUSÃO</button>
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

        function aoMudarCliente(val) {
            toggleNovoCliente(val);
            
            if(val !== 'novo' && val !== '') {
                const select = document.getElementById('select_cliente');
                const selectedOption = select.options[select.selectedIndex];
                
                const morada = selectedOption.getAttribute('data-morada') || '';
                const cidade = selectedOption.getAttribute('data-cidade') || '';
                const moradaInput = document.querySelector('input[name="morada_obra"]');
                
                let moradaCompleta = morada.trim();
                if (cidade.trim() !== '') {
                    moradaCompleta += (moradaCompleta !== '' ? ', ' : '') + cidade.trim();
                }
                
                if (moradaCompleta !== '') {
                    moradaInput.value = moradaCompleta;
                }
            }
        }

        // ==========================================
        // GESTÃO DA TABELA DINÂMICA DE EQUIPAMENTOS 
        // ==========================================
        function esc(v) {
            return String(v === undefined || v === null ? '' : v)
                .replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
        }

        function getRowHTML(data = {}) {
            return `
                <td class="col-chk align-center">
                    <div class="chk-wrapper">
                        <input type="checkbox" class="chk-select-row" onchange="updateMoveArrows('tabelaEquip')">
                        <div class="move-controls">
                            <i class="fa-solid fa-chevron-up" onclick="moveSelectedRows('tabelaEquip', 'up')"></i>
                            <i class="fa-solid fa-chevron-down" onclick="moveSelectedRows('tabelaEquip', 'down')"></i>
                            <i class="fa-solid fa-copy" onclick="duplicateSelectedRows('tabelaEquip')"></i>
                        </div>
                    </div>
                </td>
                <td><input type="text" name="numero_eq[]" value="${esc(data.numero)}" placeholder="Ex: 1"></td>
                <td><input type="text" name="equipamento[]" value="${esc(data.equipamento)}" placeholder="Ex: Detetor de Movimento" list="lista_equip_intrusao"></td>
                <td><input type="text" name="marca_eq[]" value="${esc(data.marca)}" placeholder="Ex: Jablotron"></td>
                <td><input type="text" name="modelo[]" value="${esc(data.modelo)}" placeholder="Referência do fabricante"></td>
                <td><input type="text" name="serie_eq[]" value="${esc(data.numero_serie)}" placeholder="Nº de série"></td>
                <td><input type="text" name="localizacao[]" value="${esc(data.localizacao)}" placeholder="Ex: Sala, Hall de Entrada..."></td>
                <td class="col-del">
                    <div style="display: flex; flex-wrap: nowrap; justify-content: center; align-items: center; gap: 4px;">
                        <button type="button" class="btn-row-action btn-add-row" onclick="insertRowAfter(this)"><i class="fa-solid fa-plus"></i></button>
                        <button type="button" class="btn-row-action btn-del-row" onclick="this.closest('tr').remove(); atualizarAlertaSirene();"><i class="fa-solid fa-trash-can"></i></button>
                    </div>
                </td>
            `;
        }

        function addRow(data = null) {
            const tbody = document.querySelector('#tabelaEquip tbody');
            let rowData = {};
            
            if (data) {
                rowData = data;
            } else {
                // Lógica Inteligente de Preenchimento Automático
                const rows = tbody.querySelectorAll('tr');
                if (rows.length > 0) {
                    const lastRow = rows[rows.length - 1];
                    let lastNumStr = lastRow.querySelector('input[name="numero_eq[]"]').value;
                    let lastNum = parseInt(lastNumStr) || 0;
                    
                    rowData.numero = lastNum + 1;
                    rowData.equipamento = lastRow.querySelector('input[name="equipamento[]"]').value;
                    rowData.marca = lastRow.querySelector('input[name="marca_eq[]"]').value;
                    rowData.modelo = lastRow.querySelector('input[name="modelo[]"]').value;
                } else {
                    rowData.numero = 1;
                }
            }

            const row = tbody.insertRow();
            row.innerHTML = getRowHTML(rowData);
            atualizarAlertaSirene();
        }

        function insertRowAfter(btn) {
            const currentRow = btn.closest('tr');
            const tbody = currentRow.parentNode;
            
            // Incrementa o número da linha em que clicamos no '+' e copia os equipamentos
            let lastNumStr = currentRow.querySelector('input[name="numero_eq[]"]').value;
            let lastNum = parseInt(lastNumStr) || 0;
            
            let rowData = {
                numero: lastNum + 1,
                equipamento: currentRow.querySelector('input[name="equipamento[]"]').value,
                marca: currentRow.querySelector('input[name="marca_eq[]"]').value,
                modelo: currentRow.querySelector('input[name="modelo[]"]').value
            };

            const newRow = tbody.insertRow(currentRow.sectionRowIndex + 1);
            newRow.innerHTML = getRowHTML(rowData);
            atualizarAlertaSirene();
        }

        function limparTabela() {
            if (confirm("Tem a certeza que deseja limpar todas as linhas de equipamento?")) {
                document.querySelector('#tabelaEquip tbody').innerHTML = '';
                addRow(); // Insere a linha 1 vazia
            }
        }

        // ==========================================
        // MOVIMENTAÇÃO E DUPLICAÇÃO DE LINHAS
        // ==========================================
        function updateMoveArrows(tableId) {
            document.querySelectorAll('#' + tableId + ' .move-controls').forEach(el => el.style.display = 'none');
            const checkedBoxes = document.querySelectorAll('#' + tableId + ' .chk-select-row:checked');
            if (checkedBoxes.length > 0) {
                checkedBoxes.forEach(chk => {
                    const controls = chk.closest('.chk-wrapper').querySelector('.move-controls');
                    if (controls) controls.style.display = 'flex';
                });
            }
        }

        function toggleAllRows(tableId, master) {
            document.querySelectorAll('#' + tableId + ' .chk-select-row').forEach(chk => chk.checked = master.checked);
            updateMoveArrows(tableId);
        }

        function moveSelectedRows(tableId, dir) {
            const tbody = document.querySelector('#' + tableId + ' tbody');
            const rows = Array.from(tbody.querySelectorAll('tr'));
            if (dir === 'up') {
                for (let i = 1; i < rows.length; i++) {
                    if (rows[i].querySelector('.chk-select-row').checked && !rows[i - 1].querySelector('.chk-select-row').checked) {
                        tbody.insertBefore(rows[i], rows[i - 1]); break;
                    }
                }
            } else {
                for (let i = rows.length - 2; i >= 0; i--) {
                    if (rows[i].querySelector('.chk-select-row').checked && !rows[i + 1].querySelector('.chk-select-row').checked) {
                        tbody.insertBefore(rows[i + 1], rows[i]); break;
                    }
                }
            }
            updateMoveArrows(tableId);
        }

        function duplicateSelectedRows(tableId) {
            const tbody = document.querySelector('#' + tableId + ' tbody');
            const rows = Array.from(tbody.querySelectorAll('tr'));
            
            for (let i = rows.length - 1; i >= 0; i--) {
                const row = rows[i];
                if (row.querySelector('.chk-select-row').checked) {
                    let data = {
                        numero: row.querySelector('[name="numero_eq[]"]').value,
                        equipamento: row.querySelector('[name="equipamento[]"]').value,
                        marca: row.querySelector('[name="marca_eq[]"]').value,
                        modelo: row.querySelector('[name="modelo[]"]').value,
                        numero_serie: '',
                        localizacao: row.querySelector('[name="localizacao[]"]').value
                    };
                    const newRow = tbody.insertRow(row.sectionRowIndex + 1);
                    newRow.innerHTML = getRowHTML(data);
                }
            }
            updateMoveArrows(tableId);
            atualizarAlertaSirene();
        }

        // ==========================================
        // AVISO: SIRENE EXTERIOR (comunicar à PSP/GNR)
        // ==========================================
        function normTxt(t) {
            return String(t || '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase();
        }

        function atualizarAlertaSirene() {
            const box = document.getElementById('alertaSirene');
            if (!box) return;
            const itens = [];

            document.querySelectorAll('#tabelaEquip tbody tr').forEach(tr => {
                const tipo = tr.querySelector('[name="equipamento[]"]').value;
                const t = normTxt(tipo);
                const eExterior = t.includes('sirene') && t.includes('exterior');
                tr.classList.toggle('row-sirene-ext', eExterior);
                if (eExterior) {
                    const v = n => tr.querySelector('[name="' + n + '[]"]').value.trim();
                    const partes = [
                        (v('marca_eq') + ' ' + v('modelo')).trim() || 'marca/modelo por indicar',
                        'Nº de série: ' + (v('serie_eq') || 'por indicar'),
                        'Localização: ' + (v('localizacao') || 'por indicar')
                    ];
                    itens.push(partes.join(' — '));
                }
            });

            box.innerHTML = '';
            if (itens.length === 0) { box.style.display = 'none'; return; }

            const titulo = document.createElement('strong');
            titulo.innerHTML = '<i class="fa-solid fa-triangle-exclamation"></i> Sirene exterior instalada: tem de ser comunicada à PSP/GNR.';
            box.appendChild(titulo);
            const ul = document.createElement('ul');
            itens.forEach(txt => { const li = document.createElement('li'); li.textContent = txt; ul.appendChild(li); });
            box.appendChild(ul);
            box.style.display = 'block';
        }

        // CARREGAR DADOS AO INICIAR A PÁGINA
        document.addEventListener('DOMContentLoaded', () => {
            const equipData = <?= json_encode($equipamentos_tabela) ?>;
            if (equipData && equipData.length > 0) {
                equipData.forEach(item => addRow(item));
            } else {
                addRow({numero: 1}); // Inicia automaticamente no número 1
            }

            // Atualiza o aviso da sirene exterior sempre que se escreve na tabela
            document.querySelector('#tabelaEquip tbody').addEventListener('input', atualizarAlertaSirene);
            atualizarAlertaSirene();
        });
    </script>
</body>
</html>