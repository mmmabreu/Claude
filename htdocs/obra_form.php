<?php
session_start();
require_once 'db.php';

// REGRA DE OURO 1: Validação de sessão
if (!isset($_SESSION['user_id'])) { 
    header("Location: index.php"); 
    exit; 
}

if (file_exists('functions.php')) { 
    require_once 'functions.php'; 
} else { 
    function registrarLog($pdo, $acao, $modulo) { return true; } 
}

// --- CORREÇÃO AUTOMÁTICA DA BASE DE DADOS ---
try {
    $check_col = $pdo->query("SHOW COLUMNS FROM obras LIKE 'data_alteracao'");
    if ($check_col->rowCount() == 0) {
        $pdo->exec("ALTER TABLE obras ADD COLUMN data_alteracao DATETIME DEFAULT NULL AFTER estado");
    }
} catch (Exception $e) {}

$perfil_user = $_SESSION['perfil'] ?? '';
$is_admin = in_array($perfil_user, ['admin', 'administrativo']);

$stmt_perm = $pdo->prepare("SELECT modulo FROM permissoes WHERE grupo = :perfil AND pode_aceder = 1");
$stmt_perm->execute(['perfil' => $perfil_user]);
$meus_acessos = $stmt_perm->fetchAll(PDO::FETCH_COLUMN);

if (!$is_admin && !in_array('obras', $meus_acessos)) {
    header("Location: index.php?erro=sem_permissao");
    exit;
}

$nome_completo = $_SESSION['nome'] ?? 'Utilizador';
$primeiro_nome = explode(' ', trim($nome_completo))[0];

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$modo_editar = !empty($id);
$mensagem = ''; $tipo_mensagem = '';

// Buscar Clientes com morada
$clientes = $pdo->query("SELECT id, nome, morada, cidade, codigo_postal FROM clientes WHERE ativo = 1 ORDER BY nome ASC")->fetchAll(PDO::FETCH_ASSOC);

// 1. PREPARAR DADOS INICIAIS
if ($modo_editar) {
    $stmt = $pdo->prepare("SELECT * FROM obras WHERE id = ?");
    $stmt->execute([$id]);
    $obra = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$obra) { header("Location: obras.php"); exit; }
    $titulo_pagina = "Editar Obra #" . $id;
} else {
    // --- LÓGICA DO NUMERADOR GLOBAL (Arranque no 260100) ---
    $ano_atual_curto = date('y'); 
    
    $stmt_ref = $pdo->query("SELECT valor FROM configuracoes WHERE chave = 'ultimo_numero_projeto'");
    $ultima_ref = $stmt_ref->fetchColumn();
    
    if ($ultima_ref && substr($ultima_ref, 0, 2) === $ano_atual_curto) {
        $referencia_gerada = (string)((int)$ultima_ref + 1);
        if ((int)$referencia_gerada < (int)($ano_atual_curto . "0100")) {
            $referencia_gerada = $ano_atual_curto . "0100";
        }
    } else {
        $referencia_gerada = $ano_atual_curto . "0100";
    }

    $titulo_pagina = "Nova Obra";
    $obra = [
        'estado' => 'Em Curso', 
        'referencia_obra' => $referencia_gerada,
        'id_cliente' => '',
        'nome' => '',
        'morada' => '',
        'km' => ''
    ];
}

// --- PROCESSAR GRAVAÇÃO ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id_cliente     = $_POST['id_cliente'];
    $nome           = trim($_POST['nome']);
    $referencia     = trim($_POST['referencia_obra']); 
    $morada         = trim($_POST['morada']);
    $km             = !empty($_POST['km']) ? str_replace(',', '.', $_POST['km']) : 0;
    $estado         = $_POST['estado'];

    try {
        if ($modo_editar) {
            $sql = "UPDATE obras SET id_cliente=?, nome=?, referencia_obra=?, morada=?, km=?, estado=?, data_alteracao=NOW() WHERE id=?";
            $pdo->prepare($sql)->execute([$id_cliente, $nome, $referencia, $morada, $km, $estado, $id]);
            registrarLog($pdo, "Editou Obra #$id: $nome", "Obras");
        } else {
            $sql = "INSERT INTO obras (id_cliente, nome, referencia_obra, morada, km, estado, data_alteracao) VALUES (?,?,?,?,?,?,NOW())";
            $pdo->prepare($sql)->execute([$id_cliente, $nome, $referencia, $morada, $km, $estado]);
            $novo_id = $pdo->lastInsertId();

            // Atualiza Numerador Global
            try {
                $stmt_curr = $pdo->query("SELECT valor FROM configuracoes WHERE chave = 'ultimo_numero_projeto'");
                $curr_val = (int)$stmt_curr->fetchColumn();
                if ((int)$referencia > $curr_val) {
                    $stmt_seq = $pdo->prepare("INSERT INTO configuracoes (chave, valor) VALUES ('ultimo_numero_projeto', ?) ON DUPLICATE KEY UPDATE valor = ?");
                    $stmt_seq->execute([$referencia, $referencia]);
                }
            } catch (Exception $ex) { }
            registrarLog($pdo, "Criou Nova Obra #$novo_id: $nome", "Obras");
        }
        header("Location: obras.php?editado=1");
        exit;
    } catch (Exception $e) {
        $mensagem = "Erro SQL ao gravar: " . $e->getMessage(); 
        $tipo_mensagem = "erro";
        $obra = $_POST;
    }
}
?>
<!DOCTYPE html>
<html lang="pt-PT">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title><?= $titulo_pagina ?> - Gestão MAB</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { font-family: system-ui, -apple-system, sans-serif; box-sizing: border-box; }
        body { background-color: #f7f9fc; margin: 0; padding-bottom: 100px; color: #333; }
        
        .topbar { background-color: #fff; padding: 10px 40px; box-shadow: 0 2px 15px rgba(0,0,0,0.05); display: flex; justify-content: space-between; align-items: center; position: sticky; top: 0; z-index: 100; }
        .topbar-left .logo { height: 35px; }
        .topbar-center { display: flex; align-items: center; gap: 20px; }
        .nav-icon { color: #95a5a6; font-size: 20px; text-decoration: none; transition: 0.2s; padding: 5px; border-radius: 6px; }
        .nav-icon:hover { color: #2ecc71; }
        .nav-icon.active { color: #2ecc71; }
        
        .topbar-right { display: flex; align-items: center; gap: 20px; }
        .btn-back { color: #576574; text-decoration: none; font-size: 20px; transition: color 0.2s; }
        .btn-back:hover { color: #2ecc71; }
        
        .user-menu { position: relative; }
        .user-btn { background: #fdfdfd; border: 1px solid #f1f2f6; border-radius: 30px; font-size: 14px; font-weight: 600; color: #2c3e50; cursor: pointer; display: flex; align-items: center; gap: 10px; padding: 6px 15px 6px 6px; }
        .user-avatar { background: #e74c3c; color: white; width: 28px; height: 28px; border-radius: 50%; display: flex; justify-content: center; align-items: center; font-size: 14px; border: 2px solid #fadbd8; }
        .dropdown-content { display: none; position: absolute; right: 0; top: 100%; background: #fff; min-width: 180px; box-shadow: 0 10px 30px rgba(0,0,0,0.1); border-radius: 8px; border: 1px solid #f1f2f6; overflow: hidden; margin-top: 5px; z-index: 200; }
        .user-menu:hover .dropdown-content { display: block; }
        .dropdown-content a { color: #576574; padding: 12px 20px; text-decoration: none; display: block; font-size: 14px; }
        .dropdown-content a:hover { background: #f0fff4; color: #2ecc71; }

        .container { max-width: 900px; margin: 30px auto; padding: 0 15px; }
        
        .page-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px; flex-wrap: wrap; gap: 15px; }
        .page-title { margin: 0; color: #2c3e50; font-size: 24px; font-weight: 800; display: flex; align-items: center; gap: 10px; }
        
        .btn-acao-header { background: #fdfdfd; color: #576574; border: 1px solid #e1e5ea; padding: 8px 15px; border-radius: 30px; text-decoration: none; font-weight: 700; font-size: 13px; display: inline-flex; align-items: center; gap: 6px; transition: 0.2s; cursor: pointer; }
        .btn-acao-header:hover { background: #f1f2f6; color: #2c3e50; }
        .btn-acao-header-import { color: #9b59b6; border-color: #ebdef0; background: #fdfaef; }
        .btn-acao-header-import:hover { background: #f5eef8; color: #8e44ad; border-color: #9b59b6;}

        .card { background: #fff; padding: 25px; border-radius: 12px; border: 1px solid #e1e5ea; box-shadow: 0 2px 10px rgba(0,0,0,0.03); margin-bottom: 20px; }
        h2 { font-size: 14px; color: #2ecc71; margin: 0 0 20px 0; border-bottom: 1px solid #f1f2f6; padding-bottom: 8px; text-transform: uppercase; display: flex; align-items: center; }
        
        .form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
        .form-group { display: flex; flex-direction: column; margin-bottom: 15px; }
        .form-group.full { grid-column: 1 / -1; }
        label { font-weight: 600; margin-bottom: 6px; font-size: 11px; color: #576574; text-transform: uppercase; }
        input, select, textarea { padding: 12px; border: 1px solid #dcdde1; border-radius: 8px; transition: 0.2s; background: #fff; width: 100%; font-size: 14px; }
        input:focus, select:focus { border-color: #2ecc71; outline: none; }
        
        /* Campo Bloqueado */
        input[readonly] { background: #f0fff4 !important; color: #2ecc71 !important; border-color: #a3e4d7 !important; font-weight: 800 !important; cursor: not-allowed; }

        .heranca-box { background: #fdfaef; border: 1px dashed #f1c40f; padding: 15px; border-radius: 8px; margin-bottom: 15px; display: <?= $modo_editar ? 'none' : 'block' ?>; }
        .heranca-title { font-size: 12px; color: #d35400; font-weight: bold; margin-bottom: 10px; text-transform: uppercase; }
        .btn-acao { background: #3498db; color: white; padding: 8px 12px; border-radius: 6px; border: none; font-weight: bold; cursor: pointer; transition: 0.2s; font-size: 12px; display: inline-block; text-align: center; }
        .btn-acao:hover { background: #2980b9; }

        .floating-action-bar { position: fixed; bottom: 20px; right: 20px; left: 20px; max-width: 900px; margin: 0 auto; background: #fff; padding: 15px 25px; border-radius: 12px; box-shadow: 0 -5px 20px rgba(0,0,0,0.1); display: flex; justify-content: flex-end; z-index: 1000; border: 1px solid #e1e5ea; }
        .btn-save { background: #2ecc71; color: #fff; padding: 12px 30px; border-radius: 8px; border: none; font-weight: 700; cursor: pointer; display: flex; align-items: center; gap: 8px; transition: background 0.2s; }
        .btn-save:hover { background: #27ae60; }
        
        .alert { padding: 15px; border-radius: 8px; margin-bottom: 15px; text-align: center; font-weight: 600; }
        .alert-erro { background: #fdeded; color: #c0392b; border: 1px solid #f9cccc; }
        .alert-sucesso { background: #e8f5e9; color: #2e7d32; border: 1px solid #c8e6c9; }

        .modal { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.6); z-index: 1001; justify-content: center; align-items: center; padding: 20px;}
        .modal.show { display: flex; }
        .modal-content { background: #fff; padding: 30px; border-radius: 12px; width: 100%; max-width: 550px; text-align: center; box-shadow: 0 20px 50px rgba(0,0,0,0.2); }
        .btn-primary { background: #9b59b6; color: #fff; padding: 12px 24px; border-radius: 8px; text-decoration: none; font-weight: 600; font-size: 14px; border: none; cursor: pointer; transition: 0.2s; }
        .close-btn { color: #95a5a6; font-size: 22px; cursor: pointer; font-weight: bold; background: none; border: none; padding: 0; line-height: 1; }

        @media (max-width: 768px) {
            .topbar-center { display: none; }
            .form-grid { grid-template-columns: 1fr; gap: 0; }
        }
    </style>
</head>
<body>

    <header class="topbar">
        <div class="topbar-left"><a href="index.php"><img src="mab.png" alt="MAB" class="logo"></a></div>
        <div class="topbar-center">
            <a href="index.php" class="nav-icon" title="Início"><i class="fa-solid fa-house"></i></a>
            <?php if ($is_admin || in_array('clientes', $meus_acessos)): ?><a href="clientes.php" class="nav-icon" title="Clientes"><i class="fa-solid fa-users"></i></a><?php endif; ?>
            <?php if ($is_admin || in_array('visitas', $meus_acessos)): ?><a href="visitas.php" class="nav-icon" title="Visitas"><i class="fa-solid fa-calendar-check"></i></a><?php endif; ?>
            <?php if ($is_admin || in_array('servicos', $meus_acessos)): ?><a href="servicos.php" class="nav-icon" title="Serviços"><i class="fa-solid fa-screwdriver-wrench"></i></a><?php endif; ?>
            <a href="obras.php" class="nav-icon active" title="Obras"><i class="fa-solid fa-helmet-safety"></i></a>
            <?php if ($is_admin || in_array('relatorios', $meus_acessos)): ?><a href="relatorios.php" class="nav-icon" title="Relatórios"><i class="fa-solid fa-clipboard-list"></i></a><?php endif; ?>
            <?php if ($is_admin || in_array('orcamentos', $meus_acessos)): ?><a href="orcamentos.php" class="nav-icon" title="Orçamentos"><i class="fa-solid fa-file-invoice"></i></a><?php endif; ?>
        </div>
        <div class="topbar-right">
            <a href="obras.php" class="btn-back" title="Voltar"><i class="fa-solid fa-arrow-left"></i></a>
            <div class="user-menu">
                <button class="user-btn">
                    <div class="user-avatar"><i class="fa-solid fa-user"></i></div>
                    <?= htmlspecialchars($primeiro_nome) ?>
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
            <div class="alert <?= $tipo_mensagem === 'sucesso' ? 'alert-sucesso' : 'alert-erro' ?>">
                <?= htmlspecialchars($mensagem, ENT_QUOTES, 'UTF-8') ?>
            </div>
        <?php endif; ?>

        <div class="page-header">
            <h1 class="page-title"><?= $titulo_pagina ?></h1>
        </div>

        <form method="POST">
            <div class="card">
                <h2>
                    <div style="display:flex; align-items:center; gap:8px;">
                        <i class="fa-solid fa-circle-info"></i> Identificação da Obra
                    </div>
                </h2>
                
                <div class="form-group full">
                    <label>Cliente Associado *</label>
                    <select name="id_cliente" id="id_cliente" required onchange="clienteAlterado()">
                        <option value="">-- Selecione Primeiro o Cliente --</option>
                        <?php foreach($clientes as $c): ?>
                            <option value="<?= $c['id'] ?>" 
                                    data-morada="<?= htmlspecialchars($c['morada'] ?? '') ?>"
                                    data-cidade="<?= htmlspecialchars($c['cidade'] ?? '') ?>"
                                    data-cp="<?= htmlspecialchars($c['codigo_postal'] ?? '') ?>"
                                    <?= (isset($obra['id_cliente']) && $obra['id_cliente'] == $c['id']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($c['nome']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <?php if (!$modo_editar): ?>
                <!-- BLOCO INTELIGENTE DE HERANÇA (Só aparece depois de escolher cliente) -->
                <div class="heranca-box" id="bloco_heranca" style="display:none;">
                    <div class="heranca-title"><i class="fa-solid fa-link"></i> Herdar Referência e Dados (Opcional)</div>
                    <div class="form-grid">
                        <div class="form-group" style="margin-bottom:0;">
                            <label>De Visita Técnica</label>
                            <div style="display:flex; gap:10px;">
                                <select id="import_visita" style="flex:1;">
                                    <option value="">A carregar...</option>
                                </select>
                                <button type="button" class="btn-acao" onclick="aplicarHeranca('visita')">Importar</button>
                            </div>
                        </div>
                        <div class="form-group" style="margin-bottom:0;">
                            <label>De Orçamento</label>
                            <div style="display:flex; gap:10px;">
                                <select id="import_orcamento" style="flex:1;">
                                    <option value="">A carregar...</option>
                                </select>
                                <button type="button" class="btn-acao" onclick="aplicarHeranca('orcamento')">Importar</button>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <div class="form-grid">
                    <div class="form-group">
                        <label>Referência Interna (Projeto) - BLOQUEADO</label>
                        <input type="text" name="referencia_obra" id="referencia_obra" value="<?= htmlspecialchars($obra['referencia_obra'] ?? '') ?>" readonly>
                    </div>
                    
                    <div class="form-group">
                        <label>Estado Atual da Obra</label>
                        <select name="estado">
                            <option value="Planeamento" <?= (isset($obra['estado']) && $obra['estado'] == 'Planeamento') ? 'selected' : '' ?>>Planeamento</option>
                            <option value="Em Curso" <?= (isset($obra['estado']) && strcasecmp($obra['estado'], 'Em Curso') == 0) ? 'selected' : '' ?>>Em Curso</option>
                            <option value="Concluída" <?= (isset($obra['estado']) && $obra['estado'] == 'Concluída') ? 'selected' : '' ?>>Concluída</option>
                            <option value="Suspensa" <?= (isset($obra['estado']) && $obra['estado'] == 'Suspensa') ? 'selected' : '' ?>>Suspensa</option>
                        </select>
                    </div>
                </div>

                <div class="form-group full">
                    <label>Nome da Obra / Designação *</label>
                    <input type="text" name="nome" id="nome_obra" value="<?= htmlspecialchars($obra['nome'] ?? '') ?>" required placeholder="Ex: Remodelação de Apartamento X">
                </div>
            </div>

            <div class="card" style="margin-bottom: 50px;">
                <h2>
                    <div style="display:flex; justify-content:space-between; width:100%; align-items:center;">
                        <span><i class="fa-solid fa-location-dot"></i> Localização e Logística</span>
                        <?php if (!$modo_editar): ?>
                            <button type="button" class="btn-acao" style="background:#8e44ad; font-size:10px; margin-left:auto;" onclick="perguntarMoradaCliente()">Usar Morada do Cliente</button>
                        <?php endif; ?>
                    </div>
                </h2>
                
                <div class="form-group full">
                    <label>Morada Completa</label>
                    <input type="text" name="morada" id="morada_obra" value="<?= htmlspecialchars($obra['morada'] ?? '') ?>" placeholder="Rua, Número, Código Postal, Localidade">
                </div>
                
                <div class="form-group full">
                    <label>Distância da Sede (KM Ida e Volta) - <span style="font-weight:normal;color:#95a5a6;">Calculado Auto se herdar ou usar morada cliente</span></label>
                    <input type="text" name="km" id="km_obra" value="<?= htmlspecialchars($obra['km'] ?? '') ?>" placeholder="0.00">
                </div>
            </div>

            <div class="floating-action-bar">
                <button type="submit" class="btn-save">
                    <i class="fa-solid fa-floppy-disk"></i> GUARDAR OBRA
                </button>
            </div>
        </form>
    </div>

    <script>
        const REF_GERADA = "<?= htmlspecialchars($referencia_gerada ?? '', ENT_QUOTES, 'UTF-8') ?>";
        const MORADA_SEDE = "Rua Damião de Góis 213, 4700-028 Braga, Portugal";

        function clienteAlterado() {
            const selCliente = document.getElementById('id_cliente');
            const blHeranca = document.getElementById('bloco_heranca');
            
            if (!blHeranca) return; // Se estiver em modo edição, isto não existe.

            if (selCliente.selectedIndex === 0 || selCliente.value === "") {
                blHeranca.style.display = 'none';
                document.getElementById('referencia_obra').value = REF_GERADA;
                document.getElementById('nome_obra').value = "";
                return;
            }

            blHeranca.style.display = 'block';
            const id_cli = selCliente.value;

            // Carregar Visitas do Cliente (Com protecao de erro)
            const selV = document.getElementById('import_visita');
            selV.innerHTML = '<option value="">A carregar...</option>';
            fetch(`ajax_get_visitas_cliente.php?id_cliente=${id_cli}`)
                .then(r => r.json())
                .then(data => {
                    selV.innerHTML = '<option value="">-- Selecione uma Visita --</option>';
                    if(data.length === 0) selV.innerHTML = '<option value="">(Nenhuma Visita)</option>';
                    data.forEach(v => {
                        let opt = document.createElement('option');
                        opt.value = v.id;
                        opt.setAttribute('data-ref', v.ref);
                        opt.setAttribute('data-desc', v.descricao);
                        opt.text = `[${v.ref}] ${v.descricao}`;
                        selV.appendChild(opt);
                    });
                }).catch(err => {
                    console.error(err);
                    selV.innerHTML = '<option value="">Erro ao carregar Visitas</option>';
                });

            // Carregar Orçamentos do Cliente (Com protecao de erro)
            const selO = document.getElementById('import_orcamento');
            selO.innerHTML = '<option value="">A carregar...</option>';
            fetch(`ajax_get_documentos_cliente.php?id_cliente=${id_cli}&tipo=orcamento`)
                .then(r => r.json())
                .then(data => {
                    selO.innerHTML = '<option value="">-- Selecione um Orçamento --</option>';
                    if(data.length === 0) selO.innerHTML = '<option value="">(Nenhum Orçamento)</option>';
                    data.forEach(o => {
                        let opt = document.createElement('option');
                        opt.value = o.id;
                        opt.setAttribute('data-ref', o.ref);
                        opt.setAttribute('data-desc', o.descricao);
                        opt.text = `[${o.ref}] ${o.descricao}`;
                        selO.appendChild(opt);
                    });
                }).catch(err => {
                    console.error(err);
                    selO.innerHTML = '<option value="">Erro ao carregar Orçamentos</option>';
                });
        }

        // Função para calcular distância
        function calcularKMIdaeVolta(moradaDestino) {
            const inputKm = document.getElementById('km_obra');
            inputKm.value = "A calcular...";

            // Coordenadas Fixas da Sede (Rua Damião de Góis 213, Braga)
            const sedeLat = 41.5434;
            const sedeLon = -8.4116;

            fetch(`https://nominatim.openstreetmap.org/search?format=json&q=${encodeURIComponent(moradaDestino)}`)
                .then(r => r.json())
                .then(dados => {
                    if(dados && dados.length > 0) {
                        const destLat = parseFloat(dados[0].lat);
                        const destLon = parseFloat(dados[0].lon);

                        const R = 6371; 
                        const dLat = (destLat - sedeLat) * Math.PI / 180;
                        const dLon = (destLon - sedeLon) * Math.PI / 180;
                        const a = Math.sin(dLat/2) * Math.sin(dLat/2) +
                                  Math.cos(sedeLat * Math.PI / 180) * Math.cos(destLat * Math.PI / 180) *
                                  Math.sin(dLon/2) * Math.sin(dLon/2);
                        const c = 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1-a));
                        const distLinhaReta = R * c;

                        const kmIdaVolta = (distLinhaReta * 1.3) * 2;
                        inputKm.value = kmIdaVolta.toFixed(2);
                    } else {
                        inputKm.value = "";
                        alert("Não foi possível localizar essa morada no mapa para cálculo de KM.");
                    }
                })
                .catch(() => { inputKm.value = ""; });
        }

        function aplicarHeranca(tipo) {
            const sel = document.getElementById('import_' + tipo);
            if(sel.selectedIndex <= 0) return alert("Selecione um registo válido da lista.");

            const opt = sel.options[sel.selectedIndex];
            document.getElementById('referencia_obra').value = opt.getAttribute('data-ref');
            document.getElementById('nome_obra').value = opt.getAttribute('data-desc');

            perguntarMoradaCliente();
        }

        function perguntarMoradaCliente() {
            const selCliente = document.getElementById('id_cliente');
            if (selCliente.selectedIndex <= 0) return;

            const opt = selCliente.options[selCliente.selectedIndex];
            const morada = opt.getAttribute('data-morada') || '';
            const cidade = opt.getAttribute('data-cidade') || '';
            const cp = opt.getAttribute('data-cp') || '';

            let moradaCompleta = [];
            if(morada) moradaCompleta.push(morada);
            if(cp) moradaCompleta.push(cp);
            if(cidade) moradaCompleta.push(cidade);

            let txtMorada = moradaCompleta.join(', ');

            if (txtMorada === '') {
                alert("A ficha deste cliente não tem morada preenchida.");
                return;
            }

            if (confirm(`A Obra vai realizar-se na morada da ficha do Cliente?\n\n${txtMorada}`)) {
                document.getElementById('morada_obra').value = txtMorada;
                calcularKMIdaeVolta(txtMorada);
            }
        }
    </script>
</body>
</html>