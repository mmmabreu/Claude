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

$perfil_user = $_SESSION['perfil'] ?? '';
$is_admin = in_array($perfil_user, ['admin', 'administrativo']);

// REGRA DE OURO 2: Acesso Restrito pela Matriz
$stmt_perm = $pdo->prepare("SELECT modulo FROM permissoes WHERE grupo = :perfil AND pode_aceder = 1");
$stmt_perm->execute(['perfil' => $perfil_user]);
$meus_acessos = $stmt_perm->fetchAll(PDO::FETCH_COLUMN);

if (!$is_admin && !in_array('termos', $meus_acessos) && !in_array('termos_eletrico', $meus_acessos)) {
    header("Location: index.php?erro=sem_permissao");
    exit;
}

// REGRA DE OURO 3: Obter apenas o primeiro nome
$nome_completo = $_SESSION['nome'] ?? 'Utilizador';
$primeiro_nome = explode(' ', trim($nome_completo))[0];

function validarNIF($nif) {
    $nif = preg_replace('/[^0-9]/', '', $nif);
    if (strlen($nif) != 9) return false;
    $ignoreList = ['000000000', '111111111', '222222222', '333333333', '444444444', '555555555', '666666666', '777777777', '888888888', '999999999'];
    if (in_array($nif, $ignoreList)) return false;
    $added = $nif[0]*9 + $nif[1]*8 + $nif[2]*7 + $nif[3]*6 + $nif[4]*5 + $nif[5]*4 + $nif[6]*3 + $nif[7]*2;
    $checkDigit = 11 - ($added % 11);
    if ($checkDigit >= 10) $checkDigit = 0;
    return $checkDigit == $nif[8];
}

// Deteção do Modo: Criar vs Editar
$id_termo = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$modo_editar = !empty($id_termo);

$erro_msg = '';
$termo_db = [];
$instalacoes_db = [];

// Buscar Listas Base
$clientes = $pdo->query("SELECT id, nome, nif FROM clientes WHERE ativo = 1 ORDER BY nome ASC")->fetchAll(PDO::FETCH_ASSOC);
$obras = $pdo->query("SELECT id, id_cliente, nome, morada, referencia_obra FROM obras WHERE estado != 'Concluída' ORDER BY nome ASC")->fetchAll(PDO::FETCH_ASSOC);

if ($modo_editar) {
    $stmt = $pdo->prepare("SELECT * FROM termos_eletrico WHERE id = ?");
    $stmt->execute([$id_termo]);
    $termo_db = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$termo_db) {
        header("Location: termos_eletrico.php");
        exit;
    }
    
    $stmt_inst = $pdo->prepare("SELECT * FROM termos_eletrico_instalacoes WHERE id_termo = ? ORDER BY id ASC");
    $stmt_inst->execute([$id_termo]);
    $instalacoes_db = $stmt_inst->fetchAll(PDO::FETCH_ASSOC);
    
    $titulo_pagina = "Editar Registo: " . ($termo_db['referencia_termo'] ?: "ID $id_termo");
    $referencia_termo_gerada = $termo_db['referencia_termo'];
} else {
    $titulo_pagina = "Novo Registo Elétrico";
    
    // --- LÓGICA DO NUMERADOR GLOBAL (YYXXXX) ---
    $ano_atual_curto = date('y'); 
    
    $stmt_ref = $pdo->query("SELECT valor FROM configuracoes WHERE chave = 'ultimo_numero_projeto'");
    $ultima_ref = $stmt_ref->fetchColumn();
    
    if ($ultima_ref && substr($ultima_ref, 0, 2) === $ano_atual_curto) {
        $referencia_termo_gerada = (string)((int)$ultima_ref + 1);
        if ((int)$referencia_termo_gerada < (int)($ano_atual_curto . "0100")) {
            $referencia_termo_gerada = $ano_atual_curto . "0100";
        }
    } else {
        $referencia_termo_gerada = $ano_atual_curto . "0100";
    }

    // --- LÓGICA DE IMPORTAÇÃO DA SIMBIOSE ---
    $import_obra_id = filter_input(INPUT_GET, 'import_obra', FILTER_VALIDATE_INT);

    if ($import_obra_id) {
        $stmt_o = $pdo->prepare("SELECT * FROM obras WHERE id = ?");
        $stmt_o->execute([$import_obra_id]);
        if ($o = $stmt_o->fetch(PDO::FETCH_ASSOC)) {
            $referencia_termo_gerada = !empty($o['referencia_obra']) ? $o['referencia_obra'] : $referencia_termo_gerada;
            $_POST['id_cliente'] = $o['id_cliente'];
            $_POST['id_obra'] = $o['id'];
            $_POST['morada'] = $o['morada'];
            
            $erro_msg = "Dados importados da Obra. O Termo partilhará a referência " . htmlspecialchars($referencia_termo_gerada) . ".";
            // Vamos usar o estilo de sucesso para a mensagem de importação
            $msg_style = "alert-sucesso"; 
        }
    } else {
        $msg_style = "alert-erro";
    }
}

// --- PROCESSAR GRAVAÇÃO (POST) ---
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    try {
        $pdo->beginTransaction();

        $id_cliente = $_POST['id_cliente'];
        if ($id_cliente === 'novo') {
            $nif = trim($_POST['novo_cliente_nif'] ?? '');
            if (!empty($nif) && !validarNIF($nif)) throw new Exception("O NIF introduzido é inválido.");
            $sql_cli = "INSERT INTO clientes (nome, nif, telefone, email, morada, codigo_postal, cidade, ativo) VALUES (?, ?, ?, ?, ?, ?, ?, 1)";
            $pdo->prepare($sql_cli)->execute([ $_POST['novo_cliente_nome'], !empty($nif) ? $nif : null, $_POST['novo_cliente_telefone'] ?: null, $_POST['novo_cliente_email'] ?: null, $_POST['novo_cliente_morada'] ?: null, $_POST['novo_cliente_cp'] ?: null, $_POST['novo_cliente_cidade'] ?: null ]);
            $id_cliente = $pdo->lastInsertId();
            registrarLog($pdo, "Criou Novo Cliente via Termo Elétrico (ID: $id_cliente)", "Clientes");
        }

        $referencia_post = $_POST['referencia_termo'] ?? '';
        $id_obra = $_POST['id_obra'];

        if ($id_obra === 'nova' || $_POST['id_cliente'] === 'novo') {
            $sql_obra = "INSERT INTO obras (id_cliente, nome, referencia_obra, morada, data_inicio, estado) VALUES (?, ?, ?, ?, ?, 'Em Preparação')";
            $pdo->prepare($sql_obra)->execute([ $id_cliente, $_POST['nova_obra_nome'], $referencia_post, !empty($_POST['nova_obra_morada']) ? $_POST['nova_obra_morada'] : $_POST['morada'], date('Y-m-d') ]);
            $id_obra = $pdo->lastInsertId();
            registrarLog($pdo, "Criou Nova Obra via Termo Elétrico (ID: $id_obra)", "Obras");
        }

        $id_post = filter_input(INPUT_POST, 'id_termo_post', FILTER_VALIDATE_INT);

        if ($id_post) {
            // UPDATE
            $sql = "UPDATE termos_eletrico SET 
                    id_obra=?, id_cliente=?, data_emissao=?, morada=?, freguesia=?, concelho=?, distrito=?, coordenadas=?,
                    tipo_estabelecimento=?, descricao_imovel=?, classificacao_instalacao=?, instalacao_nova_existente=?, com_projeto=?, referencia_termo=?
                    WHERE id=?";
            $pdo->prepare($sql)->execute([
                $id_obra, $id_cliente, $_POST['data_emissao'], $_POST['morada'], $_POST['freguesia'], $_POST['concelho'], $_POST['distrito'], $_POST['coordenadas'],
                $_POST['tipo_estabelecimento'], $_POST['descricao_imovel'], $_POST['classificacao_instalacao'], $_POST['instalacao_nova_existente'], $_POST['com_projeto'], $referencia_post, $id_post
            ]);
            
            // Limpar instalações antigas para substituir pelas novas do formulário
            $pdo->prepare("DELETE FROM termos_eletrico_instalacoes WHERE id_termo = ?")->execute([$id_post]);
            $id_termo_final = $id_post;
            
            registrarLog($pdo, "Editou Termo Elétrico ($referencia_post)", "Termos Elétricos");

        } else {
            // INSERT
            $sql = "INSERT INTO termos_eletrico (
                id_obra, id_cliente, data_emissao, morada, freguesia, concelho, distrito, coordenadas,
                tipo_estabelecimento, descricao_imovel, classificacao_instalacao, instalacao_nova_existente, com_projeto, dados_json, referencia_termo
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, '{}', ?)";
            
            $pdo->prepare($sql)->execute([
                $id_obra, $id_cliente, $_POST['data_emissao'], $_POST['morada'], $_POST['freguesia'], $_POST['concelho'], $_POST['distrito'], $_POST['coordenadas'],
                $_POST['tipo_estabelecimento'], $_POST['descricao_imovel'], $_POST['classificacao_instalacao'], $_POST['instalacao_nova_existente'], $_POST['com_projeto'], $referencia_post
            ]);
            $id_termo_final = $pdo->lastInsertId();
            
            // Grava nas configurações o último número APENAS se for o mais alto!
            $stmt_curr = $pdo->query("SELECT valor FROM configuracoes WHERE chave = 'ultimo_numero_projeto'");
            $curr_val = (int)$stmt_curr->fetchColumn();
            
            if ((int)$referencia_post > $curr_val) {
                $stmt_seq = $pdo->prepare("INSERT INTO configuracoes (chave, valor) VALUES ('ultimo_numero_projeto', ?) ON DUPLICATE KEY UPDATE valor = ?");
                $stmt_seq->execute([$referencia_post, $referencia_post]);
            }

            registrarLog($pdo, "Criou Novo Termo Elétrico ($referencia_post)", "Termos Elétricos");
        }

        // GRAVAR AS VÁRIAS INSTALAÇÕES ELÉTRICAS
        if (!empty($_POST['instalacoes'])) {
            $sql_inst = "INSERT INTO termos_eletrico_instalacoes (id_termo, tipo_instalacao, entrada_imovel, ramal, nip, cpe, andar, fracao, tipo_utilizacao, entrada_fases, potencia, fator_simultaneidade, potencia_alimentar) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            $stmt_inst = $pdo->prepare($sql_inst);
            foreach ($_POST['instalacoes'] as $i) {
                $stmt_inst->execute([
                    $id_termo_final, $i['tipo_instalacao'], $i['entrada_imovel'], $i['ramal'], $i['nip'], $i['cpe'], $i['andar'], $i['fracao'],
                    $i['tipo_utilizacao'], $i['entrada_fases'], $i['potencia'], $i['fator_simultaneidade'], $i['potencia_alimentar']
                ]);
            }
        }

        $pdo->commit();
        header("Location: termos_eletrico.php?sucesso=1"); 
        exit;
    } catch(Exception $e) { 
        $pdo->rollBack(); 
        $erro_msg = "Erro ao guardar: " . $e->getMessage(); 
        $msg_style = "alert-erro";
        // Preservar dados preenchidos em caso de erro
        $termo_db = $_POST;
        $instalacoes_db = $_POST['instalacoes'] ?? [];
    }
}

$lista_utilizacao = ["Adega", "Antena de comunicações", "Armário via pública", "Armazém", "Armazém Frigorífico", "Arrumes", "Associação/Clube/Coletividade", "Banco", "Cemitério", "Centro de Saúde", "Clínica", "Condomínios fechados", "Dependência militar", "Edifício de administração pública", "Edifício escolar", "Edifício religioso", "Escritório", "Estação de regulação e medida de gás", "Estação de tratamento de águas residuais", "Estação Elevatória", "Estufa", "Fonte/iluminação decorativa", "Garagem", "Gerador Segurança", "Gerador Socorro", "Habitação", "Habitação para animais", "Herdade/Instalação Agrícola", "Hospital", "Hotel", "Indústria", "Infantário", "Instalação Coletiva", "Instalação de produção animal", "Lar", "Local Comercial", "Marina", "MUPI", "Oficina", "Outros (caracterizar brevemente a instalação)", "Parque de campismo", "Piscina", "Ponto de carregamento de veículos elétricos", "Ponto de Entrega de Águas", "Posto de abastecimento de combustível", "Quartel militar", "Recinto de espetáculos", "Recinto desportivo", "Recinto motor", "Reservatório de água", "Restaurante", "Semáforos", "Serviço Público", "Serviços comuns", "Turismo rural/de habitação"];
sort($lista_utilizacao); 

// Fallbacks para pré-preenchimento
$val_ref = $termo_db['referencia_termo'] ?? $referencia_termo_gerada;
$val_data = $termo_db['data_emissao'] ?? date('Y-m-d');
$val_id_cliente = $_POST['id_cliente'] ?? $termo_db['id_cliente'] ?? '';
$val_id_obra = $_POST['id_obra'] ?? $termo_db['id_obra'] ?? '';
$val_morada = $_POST['morada'] ?? $termo_db['morada'] ?? '';
$val_freg = $termo_db['freguesia'] ?? '';
$val_conc = $termo_db['concelho'] ?? '';
$val_dist = $termo_db['distrito'] ?? '';
$val_coord = $termo_db['coordenadas'] ?? '';
$val_tipo_est = $termo_db['tipo_estabelecimento'] ?? 'Edifícios de habitação';
$val_desc_imovel = $termo_db['descricao_imovel'] ?? 'Unifamiliar';
$val_class = $termo_db['classificacao_instalacao'] ?? 'Locais de habitação';
$val_estado_inst = $termo_db['instalacao_nova_existente'] ?? 'Nova';
$val_proj = $termo_db['com_projeto'] ?? 'Não';
?>
<!DOCTYPE html>
<html lang="pt-PT">
<head>
    <meta charset="UTF-8">
    <title><?= htmlspecialchars($titulo_pagina) ?> - Gestão MAB</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { font-family: system-ui, sans-serif; background: #f7f9fc; margin: 0; color: #333; font-size: 13px; padding-bottom: 100px; }
        
        .topbar { background: #fff; padding: 10px 40px; box-shadow: 0 2px 15px rgba(0,0,0,0.05); display: flex; justify-content: space-between; align-items: center; position: sticky; top: 0; z-index: 100; }
        .topbar-left .logo { height: 35px; }
        .topbar-center { display: flex; align-items: center; gap: 20px; }
        .nav-icon { color: #95a5a6; font-size: 20px; text-decoration: none; transition: 0.2s; padding: 5px; border-radius: 6px; }
        .nav-icon:hover { background: #f1f2f6; }
        .nav-icon.active, .nav-icon.active:hover { color: #f39c12; }
        
        .topbar-right { display: flex; align-items: center; gap: 20px; }
        .btn-back { color: #576574; text-decoration: none; font-size: 18px; transition: color 0.2s; }
        .btn-back:hover { color: #f39c12; }
        
        .user-menu { position: relative; }
        .user-btn { background: #fdfdfd; border: 1px solid #f1f2f6; border-radius: 30px; font-size: 14px; font-weight: 600; color: #2c3e50; cursor: pointer; display: flex; align-items: center; gap: 10px; padding: 6px 15px 6px 6px; transition: 0.2s; }
        .user-btn:hover { background: #fff; box-shadow: 0 4px 10px rgba(0,0,0,0.05); }
        .user-avatar { background: #e74c3c; color: white; width: 28px; height: 28px; border-radius: 50%; display: flex; justify-content: center; align-items: center; font-size: 14px; border: 2px solid #fadbd8; }
        
        .dropdown-content { display: none; position: absolute; right: 0; top: 100%; background: #fff; min-width: 180px; box-shadow: 0 10px 30px rgba(0,0,0,0.1); border-radius: 8px; border: 1px solid #f1f2f6; overflow: hidden; margin-top: 5px; z-index: 200; }
        .user-menu:hover .dropdown-content { display: block; }
        .dropdown-content a { color: #576574; padding: 12px 20px; text-decoration: none; display: block; font-size: 14px; }
        .dropdown-content a:hover { background: #fdf2f2; color: #f39c12; }
        
        .container { max-width: 1200px; margin: 30px auto; padding: 0 20px; }
        .page-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px; flex-wrap: wrap; gap: 15px; }
        .page-title { margin: 0; color: #2c3e50; font-size: 24px; font-weight: 800; display: flex; align-items: center; gap: 10px; }
        
        .btn-acao-header { background: #fdfdfd; color: #576574; border: 1px solid #e1e5ea; padding: 8px 15px; border-radius: 30px; text-decoration: none; font-weight: 700; font-size: 13px; display: inline-flex; align-items: center; gap: 6px; transition: 0.2s; cursor: pointer; }
        .btn-acao-header-import { color: #9b59b6; border-color: #ebdef0; background: #fdfaef; }
        .btn-acao-header-import:hover { background: #f5eef8; color: #8e44ad; border-color: #9b59b6;}

        .card { background: #fff; padding: 25px; border-radius: 12px; border: 1px solid #e1e5ea; margin-bottom: 25px; box-shadow: 0 4px 15px rgba(0,0,0,0.02); position: relative; }
        .card h2 { font-size: 15px; color: #f39c12; border-bottom: 2px solid #fdfaf2; padding-bottom: 10px; margin-top: 0; margin-bottom: 20px; text-transform: uppercase; display: flex; align-items: center; gap: 10px; }
        
        .form-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; }
        .form-group { display: flex; flex-direction: column; gap: 5px; }
        label { font-weight: 700; font-size: 11px; color: #576574; text-transform: uppercase; }
        input, select { padding: 10px; border: 1px solid #dcdde1; border-radius: 8px; font-size: 13px; outline: none; background: #fff; font-family: inherit;}
        input:focus, select:focus { border-color: #f39c12; box-shadow: 0 0 0 3px rgba(243, 156, 18, 0.1); }
        input[readonly] { background: #f0fff4 !important; color: #2ecc71 !important; border-color: #a3e4d7 !important; font-weight: 800 !important; cursor: not-allowed; }

        .box-fast { display: none; background: #fdfaf2; padding: 20px; border-radius: 8px; border: 1px dashed #f39c12; margin-top: 15px; }
        .box-fast h4 { margin: 0 0 15px 0; color: #f39c12; font-size: 13px; display: flex; align-items: center; gap: 8px; }

        .btn-add-inst { background: #34495e; color: #fff; padding: 12px 20px; border-radius: 8px; border: none; font-weight: 700; cursor: pointer; display: block; margin: 0 auto 30px auto; transition: 0.2s; font-family: inherit;}
        .btn-add-inst:hover { background: #2c3e50; transform: scale(1.02); }

        .floating-action { position: fixed; bottom: 25px; right: 25px; z-index: 100; }
        .btn-save { background: #f39c12; color: #fff; padding: 15px 45px; border-radius: 10px; border: none; font-weight: 700; cursor: pointer; box-shadow: 0 5px 15px rgba(243,156,18,0.3); transition: 0.2s; font-size: 14px; text-transform: uppercase; font-family: inherit;}
        .btn-save:hover { filter: brightness(0.9); transform: translateY(-2px); }
        
        .alert { padding: 15px; border-radius: 8px; margin-bottom: 20px; font-weight: 600; text-align: center;}
        .alert-erro { color: #e74c3c; background: #fdeaea; border: 1px solid #f9cccc; }
        .alert-sucesso { color: #9b59b6; background: #f5eef8; border: 1px solid #ebdef0; }
        .nif-invalido { border-color: #e74c3c !important; background: #fdeaea !important; }
        .btn-remover { position: absolute; top: 20px; right: 25px; background: none; border: none; color: #e74c3c; cursor: pointer; font-weight: bold; font-size: 12px; }

        /* Modais */
        .modal { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.6); z-index: 1001; justify-content: center; align-items: center; padding: 20px;}
        .modal.show { display: flex; }
        .modal-content { background: #fff; padding: 30px; border-radius: 12px; width: 100%; max-width: 550px; text-align: center; box-shadow: 0 20px 50px rgba(0,0,0,0.2); }
        .btn-primary { background: #9b59b6; color: #fff; padding: 12px 24px; border-radius: 8px; text-decoration: none; font-weight: 600; font-size: 14px; border: none; cursor: pointer; transition: 0.2s; }
        .btn-primary:hover { filter: brightness(0.9); }
        .close-btn { color: #95a5a6; font-size: 22px; cursor: pointer; font-weight: bold; background: none; border: none; padding: 0; line-height: 1; transition: 0.2s; }
        .close-btn:hover { color: #333; }

        @media (max-width: 768px) {
            .topbar { padding: 10px 15px; }
            .topbar-center { display: none; }
            .page-header { flex-direction: column; align-items: flex-start; gap: 15px;}
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
            <?php if ($is_admin || in_array('obras', $meus_acessos)): ?><a href="obras.php" class="nav-icon" title="Obras"><i class="fa-solid fa-helmet-safety"></i></a><?php endif; ?>
            <?php if ($is_admin || in_array('relatorios', $meus_acessos)): ?><a href="relatorios.php" class="nav-icon" title="Relatórios de Obra"><i class="fa-solid fa-clipboard-list"></i></a><?php endif; ?>
            <?php if ($is_admin || in_array('orcamentos', $meus_acessos)): ?><a href="orcamentos.php" class="nav-icon" title="Orçamentos"><i class="fa-solid fa-file-invoice"></i></a><?php endif; ?>
            <?php if ($is_admin || in_array('termos', $meus_acessos) || in_array('termos_eletrico', $meus_acessos)): ?><a href="termos.php" class="nav-icon active" title="Termos"><i class="fa-solid fa-file-signature"></i></a><?php endif; ?>
            <?php if ($is_admin || in_array('equipa', $meus_acessos)): ?><a href="utilizadores.php" class="nav-icon" title="Equipa"><i class="fa-solid fa-id-card-clip"></i></a><?php endif; ?>
            <?php if ($is_admin || in_array('configuracoes', $meus_acessos)): ?><a href="configuracoes.php" class="nav-icon" title="Configurações"><i class="fa-solid fa-gear"></i></a><?php endif; ?>
        </div>
        <div class="topbar-right">
            <a href="termos_eletrico.php" class="btn-back" title="Voltar"><i class="fa-solid fa-arrow-left"></i></a>
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
        <?php if ($erro_msg): ?>
            <div class="alert <?= $msg_style ?>"><i class="fa-solid fa-circle-exclamation"></i> <?= $erro_msg ?></div>
        <?php endif; ?>

        <div class="page-header">
            <h1 class="page-title"><i class="fa-solid fa-bolt" style="color:#f39c12"></i> <?= htmlspecialchars($titulo_pagina) ?></h1>
            <div class="header-actions">
                <?php if (!$modo_editar): ?>
                    <button type="button" class="btn-acao-header btn-acao-header-import" onclick="abrirModalImportar()"><i class="fa-solid fa-link"></i> Herdar Referência da Obra</button>
                <?php endif; ?>
            </div>
        </div>

        <form method="POST" id="formEletrico" onsubmit="return validarSubmissao()">
            
            <?php if ($modo_editar): ?>
                <input type="hidden" name="id_termo_post" value="<?= $id_termo ?>">
            <?php endif; ?>
            
            <div class="card">
                <h2><i class="fa-solid fa-user-tie"></i> 1. Cliente e Obra</h2>
                <div class="form-grid">
                    
                    <div class="form-group" style="grid-column: span 1;">
                        <label>Referência Interna *</label>
                        <input type="text" name="referencia_termo" value="<?= htmlspecialchars($val_ref) ?>" readonly>
                    </div>
                    
                    <div class="form-group" style="grid-column: span 1;">
                        <label>Data de Registo</label>
                        <input type="date" name="data_emissao" value="<?= htmlspecialchars($val_data) ?>" required>
                    </div>

                    <div class="form-group" style="grid-column: span 2;">
                        <label>Cliente</label>
                        <select name="id_cliente" id="sel_cli" onchange="atualizarInterfaceCliente()" required>
                            <option value="">-- Selecione --</option>
                            <option value="novo" style="font-weight:bold; color:#f39c12;">+ CRIAR NOVO CLIENTE</option>
                            <?php foreach($clientes as $c): ?>
                                <option value="<?= $c['id'] ?>" <?= ($val_id_cliente == $c['id']) ? 'selected' : '' ?>><?= htmlspecialchars($c['nome']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group" style="grid-column: span 4;">
                        <label>Obra</label>
                        <select name="id_obra" id="sel_obra" onchange="aoSelecionarObra()" required>
                            <option value="">-- Escolha o Cliente --</option>
                            </select>
                    </div>
                </div>

                <div id="box_novo_cliente" class="box-fast">
                    <h4><i class="fa-solid fa-user-plus"></i> Ficha do Novo Cliente</h4>
                    <div class="form-grid">
                        <div class="form-group" style="grid-column: span 2;"><label>Nome *</label><input type="text" name="novo_cliente_nome" id="novo_cliente_nome"></div>
                        <div class="form-group"><label>NIF</label><input type="text" name="novo_cliente_nif" id="nif_input" maxlength="9" onblur="verificarNIF(this)"></div>
                        <div class="form-group"><label>Telefone</label><input type="text" name="novo_cliente_telefone"></div>
                        <div class="form-group"><label>Email</label><input type="email" name="novo_cliente_email"></div>
                        <div class="form-group" style="grid-column: span 2;"><label>Morada</label><input type="text" name="novo_cliente_morada"></div>
                        <div class="form-group"><label>CP</label><input type="text" name="novo_cliente_cp" placeholder="0000-000"></div>
                        <div class="form-group"><label>Cidade (Localidade)</label><input type="text" name="novo_cliente_cidade"></div>
                    </div>
                </div>

                <div id="box_nova_obra" class="box-fast">
                    <h4><i class="fa-solid fa-building-circle-check"></i> Dados da Nova Obra</h4>
                    <div class="form-grid">
                        <div class="form-group" style="grid-column: span 2;"><label>Nome da Obra *</label><input type="text" name="nova_obra_nome" id="nova_obra_nome"></div>
                        <div class="form-group" style="grid-column: span 2;"><label>Morada Física da Obra</label><input type="text" name="nova_obra_morada"></div>
                    </div>
                </div>
            </div>

            <div class="card">
                <h2><i class="fa-solid fa-map-location-dot"></i> 2. Localização do Imóvel</h2>
                <div class="form-grid">
                    <div class="form-group" style="grid-column: span 2;"><label>Morada Completa</label><input type="text" name="morada" id="morada_instalacao" value="<?= htmlspecialchars($val_morada) ?>" required></div>
                    <div class="form-group"><label>Freguesia</label><input type="text" name="freguesia" value="<?= htmlspecialchars($val_freg) ?>"></div>
                    <div class="form-group"><label>Concelho</label><input type="text" name="concelho" value="<?= htmlspecialchars($val_conc) ?>"></div>
                    <div class="form-group"><label>Distrito</label><input type="text" name="distrito" value="<?= htmlspecialchars($val_dist) ?>"></div>
                    <div class="form-group"><label>Coordenadas GPS</label><input type="text" name="coordenadas" placeholder="Lat, Long" value="<?= htmlspecialchars($val_coord) ?>"></div>
                </div>
            </div>

            <div class="card">
                <h2><i class="fa-solid fa-building"></i> 3. Caracterização do Imóvel</h2>
                <div class="form-grid">
                    <div class="form-group">
                        <label>Tipo de Estabelecimento</label>
                        <select name="tipo_estabelecimento">
                            <?php $ops_est = ['Edifícios de habitação', 'Edifícios de usos comerciais', 'Estabelecimentos recebendo público', 'Estabelecimentos industriais', 'Estabelecimentos agro-pecuários', 'Edifícios pré-fabricados', 'Caravanas, parques de campismo e instalações análogas', 'Estaleiros, feiras, exposições e outras instalações temporárias', 'Marinas e portos de recreio'];
                            foreach($ops_est as $op) { echo '<option value="'.$op.'" '.($val_tipo_est==$op?'selected':'').'>'.$op.'</option>'; } ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Descrição do Imóvel</label>
                        <select name="descricao_imovel">
                            <?php $ops_desc = ['Unifamiliar', 'coletivo', 'Condominio Fechado', 'Eventual', 'Iluminação pública', 'obras', 'outros', 'P.Trasf.Dist. PTD'];
                            foreach($ops_desc as $op) { echo '<option value="'.$op.'" '.($val_desc_imovel==$op?'selected':'').'>'.$op.'</option>'; } ?>
                        </select>
                    </div>
                    <div class="form-group" style="grid-column: span 2;">
                        <label>Classificação das Instalações</label>
                        <select name="classificacao_instalacao">
                            <?php $ops_clas = ['Locais de habitação', 'Edifícios do tipo administrativo', 'Edifícios escolares', 'Edifícios hospitalares', 'Empreendimentos turísticos e similares', 'Estabelecimentos agrícolas ou pecuários', 'Estabelecimentos comerciais', 'Estabelecimentos de culto', 'Estabelecimentos Industriais', 'Estabelecimentos recebendo público', 'Locais afetos a serviços técnicos'];
                            foreach($ops_clas as $op) { echo '<option value="'.$op.'" '.($val_class==$op?'selected':'').'>'.$op.'</option>'; } ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Estado da Instalação</label>
                        <select name="instalacao_nova_existente">
                            <option value="Nova" <?= $val_estado_inst=='Nova'?'selected':'' ?>>Nova</option>
                            <option value="Existente" <?= $val_estado_inst=='Existente'?'selected':'' ?>>Existente</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Projeto Eletrotécnico?</label>
                        <select name="com_projeto">
                            <option value="Não" <?= $val_proj=='Não'?'selected':'' ?>>Não</option>
                            <option value="Sim" <?= $val_proj=='Sim'?'selected':'' ?>>Sim</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Ramais</label>
                        <input type="text" id="ramais_display" value="1" readonly style="background:#fff3cd; color:#d35400; font-weight:bold; border-color:#ffe69c;">
                    </div>
                </div>
            </div>

            <div id="instalacoes_container">
                <?php 
                $num_inst = empty($instalacoes_db) ? 1 : count($instalacoes_db);
                for($i = 0; $i < $num_inst; $i++): 
                    $inst = $instalacoes_db[$i] ?? [];
                ?>
                <div class="card instalacao-item" data-index="<?= $i ?>">
                    <h2>
                        <i class="fa-solid fa-plug"></i> 4. Instalação Elétrica
                        <?php if ($i > 0): ?>
                            <button type="button" class="btn-remover" onclick="removerInstalacao(this)"><i class="fa-solid fa-trash"></i> Remover</button>
                        <?php endif; ?>
                    </h2>
                    <div class="form-grid">
                        <div class="form-group">
                            <label>Tipo Instalação</label>
                            <select name="instalacoes[<?= $i ?>][tipo_instalacao]">
                                <option value="A" <?= ($inst['tipo_instalacao']??'')=='A'?'selected':'' ?>>A</option>
                                <option value="B" <?= ($inst['tipo_instalacao']??'')=='B'?'selected':'' ?>>B</option>
                                <option value="C" <?= ($inst['tipo_instalacao']??'C')=='C'?'selected':'' ?>>C</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Entrada Imóvel</label>
                            <select name="instalacoes[<?= $i ?>][entrada_imovel]">
                                <option value="E1" <?= ($inst['entrada_imovel']??'E1')=='E1'?'selected':'' ?>>E1</option>
                                <option value="E2" <?= ($inst['entrada_imovel']??'')=='E2'?'selected':'' ?>>E2</option>
                                <option value="E3" <?= ($inst['entrada_imovel']??'')=='E3'?'selected':'' ?>>E3</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Ramal</label>
                            <select name="instalacoes[<?= $i ?>][ramal]" class="select-ramal" onchange="atualizarRamaisDisplay()">
                                <option value="1" <?= ($inst['ramal']??'1')=='1'?'selected':'' ?>>1</option>
                                <option value="2" <?= ($inst['ramal']??'')=='2'?'selected':'' ?>>2</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>NIP</label>
                            <input type="text" name="instalacoes[<?= $i ?>][nip]" value="<?= htmlspecialchars($inst['nip']??'') ?>">
                        </div>
                        <div class="form-group">
                            <label>CPE</label>
                            <input type="text" name="instalacoes[<?= $i ?>][cpe]" value="<?= htmlspecialchars($inst['cpe']??'') ?>">
                        </div>
                        <div class="form-group">
                            <label>Andar</label>
                            <input type="text" name="instalacoes[<?= $i ?>][andar]" value="<?= htmlspecialchars($inst['andar']??'') ?>">
                        </div>
                        <div class="form-group">
                            <label>Fração</label>
                            <input type="text" name="instalacoes[<?= $i ?>][fracao]" value="<?= htmlspecialchars($inst['fracao']??'') ?>">
                        </div>
                        
                        <div class="form-group" style="grid-column: span 2;">
                            <label>Tipo Utilização Habitual / Individual</label>
                            <select name="instalacoes[<?= $i ?>][tipo_utilizacao]">
                                <?php foreach($lista_utilizacao as $u) { 
                                    $sel = (($inst['tipo_utilizacao'] ?? 'Habitação') === $u) ? 'selected' : ''; 
                                    echo "<option value=\"$u\" $sel>$u</option>"; 
                                } ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Fases</label>
                            <select name="instalacoes[<?= $i ?>][entrada_fases]">
                                <option value="Monofásica" <?= ($inst['entrada_fases']??'')=='Monofásica'?'selected':'' ?>>Monofásica</option>
                                <option value="Trifásica" <?= ($inst['entrada_fases']??'Trifásica')=='Trifásica'?'selected':'' ?>>Trifásica</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Potência (kVA)</label>
                            <input type="number" step="0.01" name="instalacoes[<?= $i ?>][potencia]" class="pot" value="<?= htmlspecialchars($inst['potencia']??'10.35') ?>" oninput="calcularLinha(this)">
                        </div>
                        <div class="form-group">
                            <label>Fator Simul.</label>
                            <input type="number" step="0.01" name="instalacoes[<?= $i ?>][fator_simultaneidade]" class="fat" value="<?= htmlspecialchars($inst['fator_simultaneidade']??'1') ?>" oninput="calcularLinha(this)">
                        </div>
                        <div class="form-group">
                            <label>Pot. a Alimentar</label>
                            <input type="text" name="instalacoes[<?= $i ?>][potencia_alimentar]" class="total" value="<?= htmlspecialchars($inst['potencia_alimentar']??'') ?>" readonly style="background:#f1f2f6; font-weight:bold;">
                        </div>
                    </div>
                </div>
                <?php endfor; ?>
            </div>

            <button type="button" class="btn-add-inst" onclick="adicionarInstalacao()"><i class="fa-solid fa-plus"></i> ADICIONAR INSTALAÇÃO EXTRA</button>

            <div class="floating-action">
                <button type="submit" class="btn-save"><i class="fa-solid fa-floppy-disk" style="margin-right:8px;"></i> GUARDAR REGISTO</button>
            </div>
        </form>
    </div>

    <?php if (!$modo_editar): ?>
    <div class="modal" id="modalImportar">
        <div class="modal-content" style="max-width: 550px;">
            <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #f1f2f6; padding-bottom: 15px; margin-bottom: 20px;">
                <h3 style="margin: 0; color: #9b59b6; font-size: 18px;"><i class="fa-solid fa-link"></i> Herdar Referência</h3>
                <button type="button" class="close-btn" onclick="fecharModalImportar()">&times;</button>
            </div>

            <div style="text-align: left;">
                <label style="font-weight: 600; color: #2c3e50; display: block; margin-bottom: 8px;">A partir de Obra Existente</label>
                <div style="display: flex; gap: 10px;">
                    <select id="select_import_obra" style="flex: 1; padding: 10px; border-radius: 6px; border: 1px solid #dcdde1; outline:none;">
                        <option value="">-- Selecione uma Obra --</option>
                        <?php foreach($obras as $o): ?>
                            <option value="<?= $o['id'] ?>">REF: <?= $o['referencia_obra'] ?> - <?= htmlspecialchars($o['nome']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button type="button" class="btn-primary" style="width: auto; background: #9b59b6;" onclick="executarImportacao('obra')">Importar</button>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <script>
        let indexInstalacao = <?= $num_inst ?>;
        const obrasData = <?= json_encode($obras, JSON_UNESCAPED_UNICODE) ?>;
        const id_obra_pre_selecionada = '<?= $val_id_obra ?>';

        function abrirModalImportar() { document.getElementById('modalImportar').classList.add('show'); }
        function fecharModalImportar() { document.getElementById('modalImportar').classList.remove('show'); }
        function executarImportacao(tipo) {
            let id = document.getElementById('select_import_' + tipo).value;
            if(!id) return alert("Selecione uma obra da lista antes de importar.");
            window.location.href = window.location.pathname + '?import_' + tipo + '=' + id;
        }

        window.onclick = function(e) {
            if (e.target.classList.contains('modal')) fecharModalImportar();
        }

        function adicionarInstalacao() {
            const container = document.getElementById('instalacoes_container');
            const original = container.querySelector('.instalacao-item'); // Pega no primeiro para usar como molde
            const clone = original.cloneNode(true);
            
            clone.dataset.index = indexInstalacao;

            clone.querySelectorAll('input, select').forEach(el => {
                if(el.name) el.name = el.name.replace(/\[\d+\]/, '[' + indexInstalacao + ']');
                if(el.type === 'text' && !el.readOnly) el.value = '';
            });
            
            const h2 = clone.querySelector('h2');
            h2.innerHTML = '<i class="fa-solid fa-plug"></i> 4. Instalação Elétrica <button type="button" class="btn-remover" onclick="removerInstalacao(this)"><i class="fa-solid fa-trash"></i> Remover</button>';
            
            container.appendChild(clone);
            indexInstalacao++;
            calcularLinha(clone.querySelector('.pot'));
            atualizarRamaisDisplay();
        }

        function removerInstalacao(btn) {
            btn.closest('.instalacao-item').remove();
            atualizarRamaisDisplay();
        }

        function calcularLinha(el) {
            const card = el.closest('.instalacao-item');
            const p = parseFloat(card.querySelector('.pot').value) || 0;
            const f = parseFloat(card.querySelector('.fat').value) || 0;
            card.querySelector('.total').value = (p * f).toFixed(2) + " kVA";
        }

        function atualizarRamaisDisplay() {
            const selects = Array.from(document.querySelectorAll('.select-ramal'));
            const valores = [...new Set(selects.map(s => s.value))]; 
            document.getElementById('ramais_display').value = valores.join(' / ');
        }

        function jsValidarNIF(nif) { 
            nif = nif.replace(/\D/g, ''); 
            if (nif.length !== 9) return false; 
            if (['000000000', '111111111', '222222222', '333333333', '444444444', '555555555', '666666666', '777777777', '888888888', '999999999'].includes(nif)) return false; 
            let total = nif[0]*9 + nif[1]*8 + nif[2]*7 + nif[3]*6 + nif[4]*5 + nif[5]*4 + nif[6]*3 + nif[7]*2; 
            let modulo11 = total % 11; 
            let comparador = modulo11 < 2 ? 0 : 11 - modulo11; 
            return nif[8] == comparador; 
        }

        function verificarNIF(input) { 
            if(input.value !== "" && !jsValidarNIF(input.value)) { 
                input.classList.add('nif-invalido'); 
                alert("Contribuinte inválido!"); 
            } else { 
                input.classList.remove('nif-invalido'); 
            } 
        }

        function validarSubmissao() { 
            const id = document.getElementById('sel_cli').value; 
            if(id === 'novo') { 
                const nif = document.getElementById('nif_input').value; 
                if(nif !== "" && !jsValidarNIF(nif)) { 
                    alert("Por favor, introduza um Contribuinte válido."); 
                    return false; 
                } 
            } 
            return true; 
        }

        function atualizarInterfaceCliente() { 
            const id = document.getElementById('sel_cli').value; 
            const selectObra = document.getElementById('sel_obra'); 
            
            document.getElementById('box_novo_cliente').style.display = (id === 'novo') ? 'block' : 'none'; 
            
            if (id === 'novo') { 
                selectObra.innerHTML = '<option value="nova">+ CRIAR NOVA OBRA</option>'; 
            } else if (id) { 
                selectObra.innerHTML = '<option value="">-- Selecione --</option>'; 
                obrasData.filter(o => o.id_cliente == id).forEach(o => {
                    let opt = new Option(o.nome, o.id);
                    if (o.id == id_obra_pre_selecionada) opt.selected = true;
                    selectObra.add(opt);
                }); 
                selectObra.add(new Option('+ CRIAR NOVA OBRA', 'nova')); 
            } else { 
                selectObra.innerHTML = '<option value="">-- Escolha o Cliente --</option>'; 
            } 
            aoSelecionarObra(); 
        }

        function aoSelecionarObra() { 
            const id = document.getElementById('sel_obra').value; 
            document.getElementById('box_nova_obra').style.display = (id === 'nova') ? 'block' : 'none'; 
            if (id && id !== 'nova') { 
                const obra = obrasData.find(o => o.id == id); 
                if (obra && obra.morada && document.getElementById('morada_instalacao').value === '') {
                    document.getElementById('morada_instalacao').value = obra.morada; 
                }
            } 
        }

        window.onload = function() {
            atualizarInterfaceCliente();
            document.querySelectorAll('.pot').forEach(el => calcularLinha(el));
            atualizarRamaisDisplay();
        };
    </script>
</body>
</html>