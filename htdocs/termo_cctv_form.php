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

if (!$is_admin && !in_array('termos_cctv', $meus_acessos) && !in_array('termos', $meus_acessos)) {
    header("Location: index.php?erro=sem_permissao"); exit;
}

$nome_completo = $_SESSION['nome'] ?? 'Utilizador';
$primeiro_nome = explode(' ', trim($nome_completo))[0];

// FAILSAFE: Garantir que a coluna 'camaras_json' existe na tabela
try {
    $pdo->exec("ALTER TABLE termos_cctv ADD COLUMN IF NOT EXISTS camaras_json LONGTEXT AFTER observacoes");
} catch(Exception $e) {}
// FAILSAFE: código de acesso da aplicação de visualização (ex.: código de verificação Hik-Connect / DMSS)
try {
    $pdo->exec("ALTER TABLE termos_cctv ADD COLUMN IF NOT EXISTS codigo_acesso_app VARCHAR(80) NULL AFTER programa_visualizacao");
} catch(Exception $e) {}

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

// Modificado para selecionar morada e cidade
$clientes = $pdo->query("SELECT id, nome, morada, cidade FROM clientes WHERE ativo = 1 ORDER BY nome ASC")->fetchAll(PDO::FETCH_ASSOC);

$camaras_view = [];

// 1. CARREGAR DADOS
if ($modo_editar) {
    $stmt = $pdo->prepare("SELECT * FROM termos_cctv WHERE id = ?");
    $stmt->execute([$id]);
    $doc = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$doc) { header("Location: termos_cctv.php"); exit; }
    $titulo_pagina = "Editar Instalação CCTV #" . $doc['referencia_termo'];
    
    // Carregar json de câmaras se existir
    if (array_key_exists('camaras_json', $doc)) {
        $camaras_view = json_decode($doc['camaras_json'] ?? '[]', true) ?: [];
    }
} else {
    $ano_atual_curto = date('y');
    $stmt_ref = $pdo->query("SELECT valor FROM configuracoes WHERE chave = 'ultimo_numero_projeto'");
    $ultima_ref = $stmt_ref->fetchColumn();
    if ($ultima_ref && substr($ultima_ref, 0, 2) === $ano_atual_curto) {
        $referencia_gerada = (string)((int)$ultima_ref + 1);
    } else {
        $referencia_gerada = $ano_atual_curto . "0100";
    }
    $titulo_pagina = "Nova Instalação CCTV";
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

        // Processar matriz de câmaras
        $camaras_arr = [];
        if (isset($_POST['camara_numero']) && is_array($_POST['camara_numero'])) {
            for ($i = 0; $i < count($_POST['camara_numero']); $i++) {
                $num = trim($_POST['camara_numero'][$i] ?? '');
                $marca = trim($_POST['camara_marca'][$i] ?? '');
                $mod = trim($_POST['camara_modelo'][$i] ?? '');
                $serie = trim($_POST['camara_serie'][$i] ?? '');
                $ip = trim($_POST['camara_ip'][$i] ?? '');
                $user = trim($_POST['camara_user'][$i] ?? '');
                $pass = trim($_POST['camara_pass'][$i] ?? '');
                
                if ($num !== '' || $marca !== '' || $ip !== '') {
                    $camaras_arr[] = [
                        'numero' => $num, 'marca' => $marca, 'modelo' => $mod,
                        'serie' => $serie, 'ip' => $ip, 'user' => $user, 'pass' => $pass
                    ];
                }
            }
        }
        $camaras_json_gravar = json_encode($camaras_arr);

        if ($modo_editar) {
            $sql = "UPDATE termos_cctv SET 
                    data_emissao=?, id_cliente=?, nome_obra=?, morada_obra=?, 
                    marca_gravador=?, modelo_gravador=?, numero_serie=?, programa_visualizacao=?, codigo_acesso_app=?, 
                    ip_local=?, gateway=?, dominio=?, user_gravador=?, pass_gravador=?, anydesk_id=?, codigo_acesso_remoto=?, 
                    porta_int_http=?, porta_int_rtsp=?, porta_int_sdk=?, porta_int_tcp=?, porta_int_udp=?, porta_int_https=?, 
                    porta_ext_http=?, porta_ext_rtsp=?, porta_ext_sdk=?, porta_ext_tcp=?, porta_ext_udp=?, porta_ext_https=?, 
                    operador_net=?, user_net=?, pass_net=?, observacoes=?, camaras_json=? 
                    WHERE id=?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                $_POST['data_emissao'], $id_cliente_final, $_POST['nome_obra'], $_POST['morada_obra'],
                $_POST['marca_gravador'], $_POST['modelo_gravador'], $_POST['numero_serie'], $_POST['programa_visualizacao'], trim($_POST['codigo_acesso_app'] ?? ''),
                $_POST['ip_local'], $_POST['gateway'], $_POST['dominio'], $_POST['user_gravador'], $_POST['pass_gravador'], $_POST['anydesk_id'], $_POST['codigo_acesso_remoto'],
                $_POST['porta_int_http'], $_POST['porta_int_rtsp'], $_POST['porta_int_sdk'], $_POST['porta_int_tcp'], $_POST['porta_int_udp'], $_POST['porta_int_https'],
                $_POST['porta_ext_http'], $_POST['porta_ext_rtsp'], $_POST['porta_ext_sdk'], $_POST['porta_ext_tcp'], $_POST['porta_ext_udp'], $_POST['porta_ext_https'],
                $_POST['operador_net'], $_POST['user_net'], $_POST['pass_net'], $_POST['observacoes'], $camaras_json_gravar, $id
            ]);
        } else {
            $sql = "INSERT INTO termos_cctv (
                        referencia_termo, data_emissao, id_cliente, nome_obra, morada_obra, 
                        marca_gravador, modelo_gravador, numero_serie, programa_visualizacao, codigo_acesso_app, 
                        ip_local, gateway, dominio, user_gravador, pass_gravador, anydesk_id, codigo_acesso_remoto, 
                        porta_int_http, porta_int_rtsp, porta_int_sdk, porta_int_tcp, porta_int_udp, porta_int_https, 
                        porta_ext_http, porta_ext_rtsp, porta_ext_sdk, porta_ext_tcp, porta_ext_udp, porta_ext_https, 
                        operador_net, user_net, pass_net, observacoes, camaras_json
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                $_POST['referencia_termo'], $_POST['data_emissao'], $id_cliente_final, $_POST['nome_obra'], $_POST['morada_obra'],
                $_POST['marca_gravador'], $_POST['modelo_gravador'], $_POST['numero_serie'], $_POST['programa_visualizacao'], trim($_POST['codigo_acesso_app'] ?? ''),
                $_POST['ip_local'], $_POST['gateway'], $_POST['dominio'], $_POST['user_gravador'], $_POST['pass_gravador'], $_POST['anydesk_id'], $_POST['codigo_acesso_remoto'],
                $_POST['porta_int_http'], $_POST['porta_int_rtsp'], $_POST['porta_int_sdk'], $_POST['porta_int_tcp'], $_POST['porta_int_udp'], $_POST['porta_int_https'],
                $_POST['porta_ext_http'], $_POST['porta_ext_rtsp'], $_POST['porta_ext_sdk'], $_POST['porta_ext_tcp'], $_POST['porta_ext_udp'], $_POST['porta_ext_https'],
                $_POST['operador_net'], $_POST['user_net'], $_POST['pass_net'], $_POST['observacoes'], $camaras_json_gravar
            ]);
            
            $stmt_seq = $pdo->prepare("INSERT INTO configuracoes (chave, valor) VALUES ('ultimo_numero_projeto', ?) ON DUPLICATE KEY UPDATE valor = ?");
            $stmt_seq->execute([$_POST['referencia_termo'], $_POST['referencia_termo']]);
        }

        $pdo->commit();
        header("Location: termos_cctv.php?sucesso=1");
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
        
        /* GRELHAS FLUÍDAS */
        .form-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 15px; }
        .form-grid-sm { display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 10px; }
        
        .form-group { display: flex; flex-direction: column; }
        .form-group.full { grid-column: 1 / -1; }
        
        label { font-weight: 600; margin-bottom: 5px; font-size: 11px; color: #576574; text-transform: uppercase; }
        input, select, textarea { padding: 10px 12px; border: 1px solid #dcdde1; border-radius: 6px; font-size: 14px; outline: none; transition: 0.2s; background: #fff; font-family: inherit;}
        input:focus, select:focus, textarea:focus { border-color: #34495e; box-shadow: 0 0 0 3px rgba(52, 73, 94, 0.1); }
        
        .input-btn-group { display: flex; gap: 8px; }
        .input-btn-group input, .input-btn-group select { flex: 1; }
        .btn-add { background: #34495e; color: #fff; border: none; border-radius: 6px; width: 42px; cursor: pointer; transition: 0.2s; display: flex; justify-content: center; align-items: center; font-size: 16px; }
        .btn-add:hover { background: #2c3e50; }

        /* Tabela Dinâmica de Câmaras */
        .table-responsive { overflow-x: auto; }
        .items-table { width: 100%; border-collapse: collapse; margin-top: 5px; min-width: 900px; }
        .items-table th { padding: 8px; background: #f8f9fa; color: #576574; font-size: 11px; font-weight: 600; text-transform: uppercase; text-align: left; border-bottom: 2px solid #dee2e6; }
        .items-table td { padding: 4px; border-bottom: 1px solid #f1f2f6; vertical-align: top; }
        .items-table input { width: 100%; box-sizing: border-box; padding: 8px; font-size: 13px;}
        
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

        .alert { padding: 15px; border-radius: 8px; margin-bottom: 20px; text-align: center; font-weight: 600; background: #fdeded; color: #c0392b; border: 1px solid #f9cccc; }

        @media (max-width: 768px) {
            .topbar { padding: 10px 15px; }
            .btn-back span { display: none; }
            .page-header { margin-bottom: 15px; padding-bottom: 10px; }
            .page-title { font-size: 18px; }
            .card { padding: 15px; margin-bottom: 15px; }
            .floating-action-bar { left: 15px; right: 15px; bottom: 15px; padding: 10px; display: flex; justify-content: center;}
            .btn-save { width: 100%; justify-content: center; padding: 15px; font-size: 15px;}
        }
    </style>
</head>
<body>
    <header class="topbar">
        <div class="topbar-left"><a href="index.php"><img src="mab.png" alt="MAB" class="logo"></a></div>
        <div class="topbar-right">
            <a href="termos_cctv.php" class="btn-back" title="Voltar à Listagem"><i class="fa-solid fa-arrow-left"></i> <span>Voltar</span></a>
        </div>
    </header>

    <div class="container">
        <?php if ($mensagem): ?><div class="alert"><?= htmlspecialchars($mensagem) ?></div><?php endif; ?>

        <div class="page-header">
            <h1 class="page-title"><i class="fa-solid fa-video"></i> <?= $titulo_pagina ?></h1>
        </div>

        <form method="POST" id="formCCTV">
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
                    
                    <div class="form-group full">
                        <label>Morada da Instalação</label>
                        <div class="input-btn-group">
                            <input type="text" name="morada_obra" value="<?= htmlspecialchars($doc['morada_obra'] ?? '') ?>">
                            <button type="button" class="btn-add" onclick="obterLocalizacaoGPS()" title="Obter localização atual via GPS" style="background:#2980b9;"><i class="fa-solid fa-location-crosshairs"></i></button>
                        </div>
                    </div>

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
                <h2><i class="fa-solid fa-server"></i> Gravador</h2>
                <div class="form-grid">
                    <div class="form-group"><label>Marca</label><input type="text" name="marca_gravador" value="<?= htmlspecialchars($doc['marca_gravador'] ?? '') ?>"></div>
                    <div class="form-group"><label>Modelo</label><input type="text" name="modelo_gravador" value="<?= htmlspecialchars($doc['modelo_gravador'] ?? '') ?>"></div>
                    <div class="form-group"><label>Nº de Série</label><input type="text" name="numero_serie" value="<?= htmlspecialchars($doc['numero_serie'] ?? '') ?>"></div>
                    <div class="form-group"><label>Programa Visualização</label><input type="text" name="programa_visualizacao" value="<?= htmlspecialchars($doc['programa_visualizacao'] ?? '') ?>" placeholder="Ex: Hik-Connect, DMSS..."></div>
                    <div class="form-group"><label>Código Acesso</label><input type="text" name="codigo_acesso_app" value="<?= htmlspecialchars($doc['codigo_acesso_app'] ?? '') ?>" placeholder="Código de verificação / acesso da App" autocomplete="off"></div>
                </div>
            </div>

            <!-- MATRIZ DE CÂMARAS -->
            <div class="card">
                <h2>
                    <span><i class="fa-solid fa-video"></i> Câmaras Instaladas</span>
                    <button type="button" onclick="limparTabelaCamaras()" style="background:none; border:none; color:#e74c3c; font-size:12px; cursor:pointer; font-weight:bold; margin-left:auto;"><i class="fa-solid fa-eraser"></i> Limpar Tabela</button>
                </h2>
                <div class="table-responsive">
                    <table class="items-table" id="tabelaCamaras">
                        <thead>
                            <tr>
                                <th style="width:30px; text-align:center;"><input type="checkbox" onchange="toggleAllRows('tabelaCamaras', this)"></th>
                                <th style="width:6%;">Nº</th>
                                <th style="width:15%;">Marca</th>
                                <th style="width:20%;">Modelo</th>
                                <th style="width:15%;">Nº Série</th>
                                <th style="width:15%;">IP / Porta</th>
                                <th style="width:12%;">User</th>
                                <th style="width:12%;">Pass</th>
                                <th class="col-del">Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <!-- Inserção via JS -->
                        </tbody>
                        <tfoot>
                            <tr>
                                <td colspan="9" style="border:none; padding-top:10px;">
                                    <button type="button" style="background:#576574; color:#fff; border:none; padding:8px 15px; border-radius:6px; cursor:pointer; font-size:12px; font-weight:bold;" onclick="addCamaraRow()"><i class="fa-solid fa-plus"></i> Adicionar Câmara</button>
                                </td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>

            <div class="card">
                <h2><i class="fa-solid fa-network-wired"></i> Rede Local e Acesso</h2>
                <div class="form-grid-sm">
                    <div class="form-group"><label>IP Local</label><input type="text" name="ip_local" value="<?= htmlspecialchars($doc['ip_local'] ?? '') ?>" placeholder="192.168.1.xxx"></div>
                    <div class="form-group"><label>Gateway</label><input type="text" name="gateway" value="<?= htmlspecialchars($doc['gateway'] ?? '') ?>" placeholder="192.168.1.xxx"></div>
                    <div class="form-group"><label>Domínio / Cloud</label><input type="text" name="dominio" value="<?= htmlspecialchars($doc['dominio'] ?? '') ?>"></div>
                    <div class="form-group"><label>User Gravador</label><input type="text" name="user_gravador" value="<?= htmlspecialchars($doc['user_gravador'] ?? '') ?>" placeholder="admin"></div>
                    <div class="form-group"><label>Pass Gravador</label><input type="text" name="pass_gravador" value="<?= htmlspecialchars($doc['pass_gravador'] ?? '') ?>"></div>
                    <div class="form-group"><label>Nº AnyDesk</label><input type="text" name="anydesk_id" value="<?= htmlspecialchars($doc['anydesk_id'] ?? '') ?>" style="background:#eef2fa; font-weight:bold; color:#2980b9;"></div>
                    <div class="form-group"><label>Acesso Remoto</label><input type="text" name="codigo_acesso_remoto" value="<?= htmlspecialchars($doc['codigo_acesso_remoto'] ?? '') ?>"></div>
                </div>
            </div>

            <div class="card">
                <h2><i class="fa-solid fa-door-open"></i> Encaminhamento de Portas (Port Forwarding)</h2>
                
                <h3 style="font-size:12px; color:#7f8c8d; margin-bottom:10px; border-bottom:1px dashed #dcdde1; padding-bottom:5px;">Portas Internas</h3>
                <div class="form-grid-sm">
                    <div class="form-group"><label>HTTP</label><input type="text" name="porta_int_http" value="<?= htmlspecialchars($doc['porta_int_http'] ?? '') ?>" placeholder="80"></div>
                    <div class="form-group"><label>RTSP</label><input type="text" name="porta_int_rtsp" value="<?= htmlspecialchars($doc['porta_int_rtsp'] ?? '') ?>" placeholder="554"></div>
                    <div class="form-group"><label>SDK / Server</label><input type="text" name="porta_int_sdk" value="<?= htmlspecialchars($doc['porta_int_sdk'] ?? '') ?>" placeholder="8000"></div>
                    <div class="form-group"><label>TCP</label><input type="text" name="porta_int_tcp" value="<?= htmlspecialchars($doc['porta_int_tcp'] ?? '') ?>" placeholder="37777"></div>
                    <div class="form-group"><label>UDP</label><input type="text" name="porta_int_udp" value="<?= htmlspecialchars($doc['porta_int_udp'] ?? '') ?>" placeholder="37778"></div>
                    <div class="form-group"><label>HTTPS</label><input type="text" name="porta_int_https" value="<?= htmlspecialchars($doc['porta_int_https'] ?? '') ?>" placeholder="443"></div>
                </div>

                <h3 style="font-size:12px; color:#7f8c8d; margin-bottom:10px; margin-top:20px; border-bottom:1px dashed #dcdde1; padding-bottom:5px;">Portas Externas</h3>
                <div class="form-grid-sm">
                    <div class="form-group"><label>HTTP</label><input type="text" name="porta_ext_http" value="<?= htmlspecialchars($doc['porta_ext_http'] ?? '') ?>"></div>
                    <div class="form-group"><label>RTSP</label><input type="text" name="porta_ext_rtsp" value="<?= htmlspecialchars($doc['porta_ext_rtsp'] ?? '') ?>"></div>
                    <div class="form-group"><label>SDK / Server</label><input type="text" name="porta_ext_sdk" value="<?= htmlspecialchars($doc['porta_ext_sdk'] ?? '') ?>"></div>
                    <div class="form-group"><label>TCP</label><input type="text" name="porta_ext_tcp" value="<?= htmlspecialchars($doc['porta_ext_tcp'] ?? '') ?>"></div>
                    <div class="form-group"><label>UDP</label><input type="text" name="porta_ext_udp" value="<?= htmlspecialchars($doc['porta_ext_udp'] ?? '') ?>"></div>
                    <div class="form-group"><label>HTTPS</label><input type="text" name="porta_ext_https" value="<?= htmlspecialchars($doc['porta_ext_https'] ?? '') ?>"></div>
                </div>
            </div>

            <div class="card">
                <h2><i class="fa-solid fa-wifi"></i> Dados do Router / Operador de Internet</h2>
                <div class="form-grid">
                    <div class="form-group"><label>Operador (ISP)</label><input type="text" name="operador_net" value="<?= htmlspecialchars($doc['operador_net'] ?? '') ?>" placeholder="Ex: MEO, NOS, Vodafone..."></div>
                    <div class="form-group"><label>User Router</label><input type="text" name="user_net" value="<?= htmlspecialchars($doc['user_net'] ?? '') ?>" placeholder="meo"></div>
                    <div class="form-group"><label>Password Router</label><input type="text" name="pass_net" value="<?= htmlspecialchars($doc['pass_net'] ?? '') ?>"></div>
                </div>
            </div>

            <div class="card">
                <h2><i class="fa-solid fa-comment-dots"></i> Observações Finais</h2>
                <div class="form-group full">
                    <textarea name="observacoes" rows="4" style="width:100%;"><?= htmlspecialchars($doc['observacoes'] ?? '') ?></textarea>
                </div>
            </div>

            <div class="floating-action-bar">
                <button type="submit" class="btn-save"><i class="fa-solid fa-floppy-disk"></i> GRAVAR FICHA CCTV</button>
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

        // --- SISTEMA GPS (OBTER LOCALIZAÇÃO) ---
        function obterLocalizacaoGPS() {
            const inputMorada = document.querySelector('input[name="morada_obra"]');
            
            if (!navigator.geolocation) {
                alert("O seu navegador não suporta geolocalização.");
                return;
            }

            inputMorada.value = "A obter localização GPS...";

            navigator.geolocation.getCurrentPosition(
                (position) => {
                    const lat = position.coords.latitude;
                    const lon = position.coords.longitude;
                    
                    // Utiliza API gratuita do OpenStreetMap para Reverse Geocoding
                    fetch(`https://nominatim.openstreetmap.org/reverse?format=jsonv2&lat=${lat}&lon=${lon}`)
                        .then(response => response.json())
                        .then(data => {
                            if (data && data.address) {
                                let moradaFormatada = [];
                                if (data.address.road) moradaFormatada.push(data.address.road);
                                if (data.address.house_number) moradaFormatada.push(data.address.house_number);
                                if (data.address.postcode) moradaFormatada.push(data.address.postcode);
                                
                                let localidade = data.address.city || data.address.town || data.address.village;
                                if (localidade) moradaFormatada.push(localidade);
                                
                                inputMorada.value = moradaFormatada.join(', ') || data.display_name;
                            } else {
                                inputMorada.value = `${lat}, ${lon}`;
                            }
                        })
                        .catch(error => {
                            console.error("Erro na conversão GPS:", error);
                            inputMorada.value = `${lat}, ${lon}`; // Em caso de erro na API, guarda as coordenadas
                        });
                },
                (error) => {
                    console.error("Erro de GPS:", error);
                    alert("Não foi possível obter a localização. Verifique as permissões de localização do seu navegador.");
                    inputMorada.value = "";
                },
                { enableHighAccuracy: true, timeout: 15000, maximumAge: 0 }
            );
        }

        // ==========================================
        // GESTÃO DA TABELA DINÂMICA DE CÂMARAS
        // ==========================================
        function escapeHTML(str) {
            if (str === null || str === undefined) return '';
            return String(str).replace(/[&<>'"]/g, tag => ({
                '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#39;', '"': '&quot;'
            }[tag]));
        }

        function getCamaraRowHTML(data = {}) {
            return `
                <td class="col-chk align-center">
                    <div class="chk-wrapper">
                        <input type="checkbox" class="chk-select-row" onchange="updateMoveArrows('tabelaCamaras')">
                        <div class="move-controls">
                            <i class="fa-solid fa-chevron-up" onclick="moveSelectedRows('tabelaCamaras', 'up')"></i>
                            <i class="fa-solid fa-chevron-down" onclick="moveSelectedRows('tabelaCamaras', 'down')"></i>
                            <i class="fa-solid fa-copy" onclick="duplicateSelectedRows('tabelaCamaras')"></i>
                        </div>
                    </div>
                </td>
                <td><input type="text" name="camara_numero[]" value="${escapeHTML(data.numero)}" placeholder="1"></td>
                <td><input type="text" name="camara_marca[]" value="${escapeHTML(data.marca)}" placeholder="Marca"></td>
                <td><input type="text" name="camara_modelo[]" value="${escapeHTML(data.modelo)}" placeholder="Modelo"></td>
                <td><input type="text" name="camara_serie[]" value="${escapeHTML(data.serie)}" placeholder="Série"></td>
                <td><input type="text" name="camara_ip[]" value="${escapeHTML(data.ip)}" placeholder="192.168.1.X"></td>
                <td><input type="text" name="camara_user[]" value="${escapeHTML(data.user)}" placeholder="admin"></td>
                <td><input type="text" name="camara_pass[]" value="${escapeHTML(data.pass)}" placeholder="pass"></td>
                <td class="col-del">
                    <div style="display: flex; flex-wrap: nowrap; justify-content: center; align-items: center; gap: 4px;">
                        <button type="button" class="btn-row-action btn-add-row" onclick="insertCamaraRowAfter(this)"><i class="fa-solid fa-plus"></i></button>
                        <button type="button" class="btn-row-action btn-del-row" onclick="this.closest('tr').remove()"><i class="fa-solid fa-trash-can"></i></button>
                    </div>
                </td>
            `;
        }

        function addCamaraRow(data = null) {
            const tbody = document.querySelector('#tabelaCamaras tbody');
            let rowData = {};
            
            if (data) {
                rowData = data;
            } else {
                // Lógica de Preenchimento Automático (Nº, Marca, User, Pass)
                const rows = tbody.querySelectorAll('tr');
                if (rows.length > 0) {
                    const lastRow = rows[rows.length - 1];
                    let lastNumStr = lastRow.querySelector('input[name="camara_numero[]"]').value;
                    let lastNum = parseInt(lastNumStr) || 0;
                    
                    rowData.numero = lastNum + 1;
                    rowData.marca = lastRow.querySelector('input[name="camara_marca[]"]').value;
                    rowData.user = lastRow.querySelector('input[name="camara_user[]"]').value;
                    rowData.pass = lastRow.querySelector('input[name="camara_pass[]"]').value;
                } else {
                    rowData.numero = 1;
                    rowData.user = 'admin'; // Defeito comum
                }
            }

            const row = tbody.insertRow();
            row.innerHTML = getCamaraRowHTML(rowData);
        }

        function insertCamaraRowAfter(btn) {
            const currentRow = btn.closest('tr');
            const tbody = currentRow.parentNode;
            
            let lastNumStr = currentRow.querySelector('input[name="camara_numero[]"]').value;
            let lastNum = parseInt(lastNumStr) || 0;
            
            let rowData = {
                numero: lastNum + 1,
                marca: currentRow.querySelector('input[name="camara_marca[]"]').value,
                user: currentRow.querySelector('input[name="camara_user[]"]').value,
                pass: currentRow.querySelector('input[name="camara_pass[]"]').value
            };

            const newRow = tbody.insertRow(currentRow.sectionRowIndex + 1);
            newRow.innerHTML = getCamaraRowHTML(rowData);
        }

        function limparTabelaCamaras() {
            if (confirm("Tem a certeza que deseja limpar todas as linhas das câmaras?")) {
                document.querySelector('#tabelaCamaras tbody').innerHTML = '';
                addCamaraRow(); 
            }
        }

        // ==========================================
        // MOVIMENTAÇÃO E DUPLICAÇÃO
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
                        numero: row.querySelector('[name="camara_numero[]"]').value,
                        marca: row.querySelector('[name="camara_marca[]"]').value,
                        modelo: row.querySelector('[name="camara_modelo[]"]').value,
                        serie: row.querySelector('[name="camara_serie[]"]').value,
                        ip: row.querySelector('[name="camara_ip[]"]').value,
                        user: row.querySelector('[name="camara_user[]"]').value,
                        pass: row.querySelector('[name="camara_pass[]"]').value
                    };
                    const newRow = tbody.insertRow(row.sectionRowIndex + 1);
                    newRow.innerHTML = getCamaraRowHTML(data);
                }
            }
            updateMoveArrows(tableId);
        }

        // CARREGAR DADOS AO INICIAR A PÁGINA
        document.addEventListener('DOMContentLoaded', () => {
            const camarasData = <?= json_encode($camaras_view) ?>;
            if (camarasData && camarasData.length > 0) {
                camarasData.forEach(item => addCamaraRow(item));
            } else {
                addCamaraRow(); 
            }
        });
    </script>
</body>
</html>