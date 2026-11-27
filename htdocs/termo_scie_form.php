<?php
// =====================================================================
// TERMO SCIE (formulário)
// Cada equipamento pode ser ligado a um artigo da TABELA DE PREÇOS (coluna "Artigo"): ao escolher, preenche a
// descrição, a referência e a marca. Essa ligação é usada no PDF do termo para juntar, no fim, a ficha técnica e o
// certificado de conformidade de cada produto instalado (termo_scie_dossier.php).
// Sem ligação escolhida, ao gravar tenta-se ligar pela referência (se houver um só artigo com essa referência).
// =====================================================================
session_start();
require_once 'db.php';
require_once 'fichas_tecnicas_lib.php';

// 🛡️ GARANTIR QUE A COLUNA NUMERO SUPORTA LETRAS E PONTOS (ex: 55a)
try {
    $pdo->exec("ALTER TABLE termos_scie_items MODIFY COLUMN numero VARCHAR(20)");
} catch (Exception $e) {}

// REGRA DE OURO 1: Validação de sessão
if (!isset($_SESSION['user_id'])) { 
    header("Location: index.php"); 
    exit; 
}

require_once 'functions.php';

// Ligação dos equipamentos aos artigos da tabela de preços (e colunas dos certificados na biblioteca)
try {
    if (!$pdo->query("SHOW COLUMNS FROM termos_scie_items LIKE 'id_artigo_tabela'")->fetch())
        $pdo->exec("ALTER TABLE termos_scie_items ADD COLUMN id_artigo_tabela INT NULL");
} catch (Exception $e) {}
ft_migrar($pdo);

// Artigos da tabela de preços (com indicação de ficha técnica / certificado na biblioteca)
$artigos_tab = [];
try {
    foreach ($pdo->query("SELECT t.id, t.referencia, t.descricao, t.marca, f.ficheiro AS f_fich, f.cert_ficheiro AS f_cert, f.marca AS f_marca
                          FROM tabela_precos t LEFT JOIN fichas_tecnicas f ON f.id = t.id_ficha_tecnica AND f.ativo = 1
                          ORDER BY t.descricao ASC")->fetchAll(PDO::FETCH_ASSOC) as $a) {
        $artigos_tab[(int)$a['id']] = ['id' => (int)$a['id'], 'r' => trim((string)$a['referencia']), 'd' => trim((string)$a['descricao']),
                                      'm' => trim((string)($a['marca'] ?: $a['f_marca'])), 'ft' => (string)$a['f_fich'] !== '' ? 1 : 0, 'ct' => (string)$a['f_cert'] !== '' ? 1 : 0];
    }
} catch (Exception $e) { error_log('termo_scie_form.php (artigos): ' . $e->getMessage()); }

$perfil_user = $_SESSION['perfil'] ?? '';
$is_admin = in_array($perfil_user, ['admin', 'administrativo']);

$stmt_perm = $pdo->prepare("SELECT modulo FROM permissoes WHERE grupo = :perfil AND pode_aceder = 1");
$stmt_perm->execute(['perfil' => $perfil_user]);
$meus_acessos = $stmt_perm->fetchAll(PDO::FETCH_COLUMN);

if (!$is_admin && !in_array('termos', $meus_acessos)) {
    header("Location: index.php?erro=sem_permissao");
    exit;
}

$nome_completo = $_SESSION['nome'] ?? 'Utilizador';
$primeiro_nome = explode(' ', trim($nome_completo))[0];

$mensagem = ''; 
$tipo_mensagem = '';

// --- DEFINIR MODO: CRIAR OU EDITAR ---
$id_edit = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$modo_editar = !empty($id_edit);
$tipo_documento_get = $_GET['tipo'] ?? 'portas_fogo';

$tipos_doc = [
    'portas_fogo' => ['label' => 'Portas e Envidraçados Resistentes ao Fogo', 'icon' => 'fa-door-closed', 'color' => '#e67e22'],
    'compartimentacao' => ['label' => 'Sistemas de Compartimentação', 'icon' => 'fa-cubes', 'color' => '#d35400'],
    'sadi' => ['label' => 'SADI - Deteção de Incêndio', 'icon' => 'fa-fire-flame-curved', 'color' => '#e74c3c'],
    'deteccao_gas' => ['label' => 'Sistemas de Deteção de Gás', 'icon' => 'fa-mask-ventilator', 'color' => '#16a085'],
    'extincao_agua' => ['label' => 'Sistemas de Extinção por Água', 'icon' => 'fa-droplet', 'color' => '#3498db'],
    'sinalizacao' => ['label' => 'Sinalização de Segurança', 'icon' => 'fa-signs-post', 'color' => '#2ecc71'],
    'iluminacao_emergencia' => ['label' => 'Iluminação de Emergência', 'icon' => 'fa-lightbulb', 'color' => '#f1c40f']
];

// --- CARREGAR DADOS SE FOR MODO EDITAR ---
$termo_data = [];
$eqs_iniciais = [];
$dados_json = [];
$central_inicial = [];   // Linha isolada da Central de Incêndio (numero = 'C')
$eqs_tabela = [];        // Restantes equipamentos

if ($modo_editar) {
    $stmt = $pdo->prepare("SELECT * FROM termos_scie WHERE id = ?");
    $stmt->execute([$id_edit]);
    $termo_data = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$termo_data) { die("Registo SCIE não encontrado."); }
    
    $tipo_documento_get = $termo_data['tipo_documento'];
    $dados_json = json_decode($termo_data['dados_json'], true) ?: [];
    
    // ATENÇÃO: Ordenação alterada para "id ASC" para manter a ordem visual exata do ecrã!
    $stmt_items = $pdo->prepare("SELECT * FROM termos_scie_items WHERE id_termo = ? ORDER BY id ASC");
    $stmt_items->execute([$id_edit]);
    $eqs_iniciais = $stmt_items->fetchAll(PDO::FETCH_ASSOC);

    // Separar a Central de Incêndio (número 'C') dos restantes equipamentos
    foreach ($eqs_iniciais as $e) {
        if (trim($e['numero']) === 'C' && empty($central_inicial)) {
            $central_inicial = $e;
        } else {
            $eqs_tabela[] = $e;
        }
    }
} else {
    // Valores por defeito para modo Criar
    $termo_data = [
        'id_obra' => $_GET['obra_id'] ?? '',
        'tipo_intervencao' => 'manutencao',
        'data_emissao' => date('Y-m-d'),
        'estado' => 'Rascunho'
    ];
}

// --- UTILITÁRIOS DE NIF ---
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

// --- LÓGICA DO NUMERADOR VIA TABELA CONFIGURACOES ---
$stmt_ref_config = $pdo->prepare("SELECT valor FROM configuracoes WHERE chave = 'ultimo_numero_projeto' LIMIT 1");
$stmt_ref_config->execute();
$ultima_ref_config = $stmt_ref_config->fetchColumn();

$ano = date('y'); 

if ($ultima_ref_config && is_numeric($ultima_ref_config)) {
    $referencia_gerada = (string)((int)$ultima_ref_config + 1);
} else {
    $referencia_gerada = $ano . "0100";
}

// --- DADOS PARA DATALIST / AUTO-COMPLETE ---
$sugestoes = ['descricao' => [], 'referencia' => [], 'marca' => []];
$all_items = $pdo->query("SELECT descricao, referencia, marca FROM termos_scie_items")->fetchAll(PDO::FETCH_ASSOC);
$cat_estruturado = []; 
$cat_relacoes = [];    

foreach ($all_items as $item) {
    foreach (array_keys($sugestoes) as $campo) {
        $val = trim($item[$campo] ?? '');
        if ($val !== '') $sugestoes[$campo][$val] = true;
    }
    $d = trim($item['descricao']); $r = trim($item['referencia']); $m = trim($item['marca']);
    if($d && $r) $cat_estruturado[$d][$r] = $m;
    if($r) $cat_relacoes[$r] = ['descricao' => $d, 'marca' => $m];
}

// --- PROCESSAMENTO AJAX PARA GRAVAÇÃO (CRIAR/EDITAR) ---
if (isset($_GET['ajax_save'])) {
    header('Content-Type: application/json');
    // REGRA DE OURO 7: token CSRF
    if (!validar_csrf_token($_POST['csrf_token'] ?? '')) { echo json_encode(['status' => 'error', 'message' => 'A sessão expirou. Atualize a página (os dados escritos perdem-se) ou copie-os antes.']); exit; }
    try {
        $pdo->beginTransaction();
        
        $itens = json_decode($_POST['itens_json'], true) ?: [];
        $observacoes = json_decode($_POST['obs_json'] ?? '[]', true) ?: [];
        
        // 1. GERIR NOVO CLIENTE / NOVA OBRA
        $id_cliente = $_POST['id_cliente'] ?? '';
        
        if ($id_cliente === 'novo') {
            $nif = $_POST['novo_cliente_nif'] ?? '';
            if (!empty($nif) && !validarNIF($nif)) { throw new Exception("O NIF introduzido é inválido."); }
            
            $sql_cli = "INSERT INTO clientes (nome, nif, telefone, email, morada, codigo_postal, cidade) VALUES (?, ?, ?, ?, ?, ?, ?)";
            $stmt_cli = $pdo->prepare($sql_cli);
            $stmt_cli->execute([ $_POST['novo_cliente_nome'], !empty($nif) ? $nif : null, $_POST['novo_cliente_telefone'] ?: null, $_POST['novo_cliente_email'] ?: null, $_POST['novo_cliente_morada'] ?: null, $_POST['novo_cliente_cp'] ?: null, $_POST['novo_cliente_cidade'] ?: null ]);
            $id_cliente = $pdo->lastInsertId();
        }

        $id_obra = $_POST['id_obra'] ?? '';
        
        if ($id_obra === 'nova' || $_POST['id_cliente'] === 'novo') {
            $nova_ref_obra = trim($_POST['nova_obra_ref']);
            
            // Criar a Obra
            $sql_obra = "INSERT INTO obras (id_cliente, nome, referencia_obra, morada, data_inicio, estado) VALUES (?, ?, ?, ?, ?, 'Em Curso')";
            $stmt_obra = $pdo->prepare($sql_obra);
            $stmt_obra->execute([ $id_cliente, $_POST['nova_obra_nome'], $nova_ref_obra, $_POST['nova_obra_morada'] ?: null, date('Y-m-d') ]);
            $id_obra = $pdo->lastInsertId();
            
            // Atualizar o 'ultimo_numero_projeto' na tabela de configurações
            $stmt_upd_ref = $pdo->prepare("INSERT INTO configuracoes (chave, valor) VALUES ('ultimo_numero_projeto', ?) ON DUPLICATE KEY UPDATE valor = ?");
            $stmt_upd_ref->execute([$nova_ref_obra, $nova_ref_obra]);
        }

        // Recolher dados do cliente atualizados
        $stmt_c = $pdo->prepare("SELECT nome, nif, telefone FROM clientes WHERE id = ?");
        $stmt_c->execute([$id_cliente]);
        $cli_data = $stmt_c->fetch(PDO::FETCH_ASSOC);
        
        $payload = [
            'id_cliente' => (int)$id_cliente,
            'cliente' => [
                'cliente_nome' => $cli_data['nome'],
                'cliente_nif' => $cli_data['nif'],
                'cliente_telefone' => $cli_data['telefone']
            ],
            'observacoes' => $observacoes
        ];

        // 2. GRAVAR O TERMO (INSERT OU UPDATE)
        if ($modo_editar) {
            $stmt_upd = $pdo->prepare("UPDATE termos_scie SET id_obra = ?, tipo_intervencao = ?, data_emissao = ?, dados_json = ?, estado = ? WHERE id = ?");
            $stmt_upd->execute([(int)$id_obra, $_POST['tipo_intervencao'], $_POST['data_emissao'], json_encode($payload, JSON_UNESCAPED_UNICODE), $_POST['estado'], $id_edit]);
            $termo_id_final = $id_edit;
            registrarLog($pdo, "Editou Termo SCIE (ID: $id_edit)", "Termos SCIE");
        } else {
            $stmt_ins = $pdo->prepare("INSERT INTO termos_scie (id_obra, tipo_documento, tipo_intervencao, data_emissao, dados_json, estado) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt_ins->execute([(int)$id_obra, $_POST['tipo_documento_real'], $_POST['tipo_intervencao'], $_POST['data_emissao'], json_encode($payload, JSON_UNESCAPED_UNICODE), $_POST['estado']]);
            $termo_id_final = $pdo->lastInsertId();
            registrarLog($pdo, "Criou novo Termo SCIE", "Termos SCIE");
        }

        // 3. GRAVAR OS EQUIPAMENTOS SCIE (a Central, se existir, vem primeiro com numero = 'C')
        $pdo->prepare("DELETE FROM termos_scie_items WHERE id_termo = ?")->execute([$termo_id_final]);
        $sql_item = "INSERT INTO termos_scie_items (id_termo, numero, descricao, referencia, marca, n_serie, loop_eq, zona, endereco, localizacao, estado, obs, id_artigo_tabela) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt_item = $pdo->prepare($sql_item);

        // Referência → artigo (só quando há um único artigo com essa referência)
        $por_ref = [];
        foreach ($artigos_tab as $a) {
            $k = strtolower(preg_replace('/[^A-Za-z0-9]/', '', $a['r']));
            if ($k === '') continue;
            $por_ref[$k] = isset($por_ref[$k]) ? 0 : $a['id'];
        }
        foreach ($itens as $eq) {
            $id_art = (int)($eq['id_artigo_tabela'] ?? 0);
            if (!isset($artigos_tab[$id_art])) {
                $k = strtolower(preg_replace('/[^A-Za-z0-9]/', '', (string)($eq['referencia'] ?? '')));
                $id_art = ($k !== '' && !empty($por_ref[$k])) ? $por_ref[$k] : 0;
            }
            $stmt_item->execute([$termo_id_final, $eq['numero'], $eq['descricao'], $eq['referencia'], $eq['marca'], $eq['n_serie'], $eq['loop_eq'], $eq['zona'], $eq['endereco'], $eq['localizacao'], $eq['estado'], $eq['obs'], $id_art ?: null]);
        }
        
        $pdo->commit();
        echo json_encode(['status' => 'success', 'termo_id' => $termo_id_final]);
    } catch(Exception $e) { 
        $pdo->rollBack(); 
        if ($e instanceof PDOException) { error_log('termo_scie_form.php: ' . $e->getMessage()); echo json_encode(['status' => 'error', 'message' => 'Não foi possível gravar. Nada foi alterado.']); }
        else echo json_encode(['status' => 'error', 'message' => $e->getMessage()]); 
    }
    exit;
}

$clientes = $pdo->query("SELECT id, nome, nif, telefone FROM clientes ORDER BY nome ASC")->fetchAll(PDO::FETCH_ASSOC);
$obras = $pdo->query("SELECT id, id_cliente, nome as obra_nome, morada FROM obras ORDER BY nome ASC")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="pt-PT">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $modo_editar ? 'Editar' : 'Novo' ?> Termo SCIE - Gestão MAB</title>
    <?php /* REGRA DE OURO 8: ícone MAB no separador */ ?>
    <link rel="icon" type="image/png" href="/mab.png">
    <link rel="apple-touch-icon" href="/mab.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* PADRÃO VISUAL UNIFORMIZADO PREMIUM (SEM ÍCONES DE MENU) */
        body { font-family: system-ui, sans-serif; background: #f7f9fc; margin: 0; color: #333; font-size: 13px; padding-bottom: 100px; }
        * { box-sizing: border-box; }
        
        .topbar { background: #fff; padding: 15px 40px; box-shadow: 0 4px 20px rgba(0,0,0,0.05); display: flex; justify-content: space-between; align-items: center; position: sticky; top: 0; z-index: 100; }
        .topbar-left .logo { height: 40px; }
        .topbar-center { display: flex; gap: 25px; flex: 1; }
        .topbar-right { display: flex; align-items: center; gap: 30px; }
        
        .btn-voltar { width: 40px; height: 40px; border-radius: 50%; background: #fff; border: 1px solid #e8ecf1; color: #576574; display: inline-flex; align-items: center; justify-content: center; text-decoration: none; font-size: 16px; transition: all 0.2s ease; box-shadow: 0 2px 6px rgba(0,0,0,0.04); flex-shrink: 0; }
        .btn-voltar:hover { background: #fdf2f2; color: #b32a2a; transform: translateX(-2px); }
        .i-art { min-width: 190px; border-color: #d6eaf8 !important; background: #f8fbfe !important; }
        tr.com-artigo .i-art { border-color: #82e0aa !important; background: #f0faf4 !important; }
        .docs-art { display: block; font-size: 10px; margin-top: 2px; color: #95a5a6; white-space: nowrap; }
        .docs-art .ok { color: #1e8449; font-weight: 700; } .docs-art .nao { color: #c0392b; }
        
        .user-menu { position: relative; display: inline-block; }
        .user-btn { background: none; border: none; font-size: 15px; font-weight: 600; color: #2c3e50; cursor: pointer; display: flex; align-items: center; gap: 8px; }
        .user-avatar { background: #e74c3c; color: white; width: 28px; height: 28px; border-radius: 50%; display: flex; justify-content: center; align-items: center; font-size: 14px; border: 2px solid #fadbd8; }
        
        .dropdown-content { display: none; position: absolute; right: 0; top: 100%; margin-top: 10px; background: #fff; min-width: 180px; box-shadow: 0 10px 30px rgba(0,0,0,0.1); border-radius: 8px; overflow: hidden; border: 1px solid #f1f2f6; z-index:200;}
        .dropdown-content a { color: #576574; padding: 12px 20px; text-decoration: none; display: block; font-size: 14px; }
        .dropdown-content a:hover { background: #fdf2f2; color: #b32a2a; }
        .user-menu:hover .dropdown-content, .user-menu.aberto .dropdown-content { display: block; }

        .container { max-width: 1400px; margin: 30px auto; padding: 0 20px; }
        
        .page-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px; }
        .page-title { margin: 0; color: #2c3e50; font-size: 24px; font-weight: 800; display: flex; align-items: center; gap: 10px; }

        .card { background: #fff; padding: 25px; border-radius: 12px; border: 1px solid #e1e5ea; margin-bottom: 25px; box-shadow: 0 4px 15px rgba(0,0,0,0.02); }
        .card h2 { font-size: 15px; color: #b32a2a; border-bottom: 2px solid #fdf2f2; padding-bottom: 10px; margin-top: 0; margin-bottom: 20px; text-transform: uppercase; display: flex; align-items: center; gap: 10px; }
        
        .form-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px; }
        .form-group { display: flex; flex-direction: column; gap: 8px; }
        label { font-weight: 700; font-size: 11px; color: #576574; text-transform: uppercase; }
        input, select { padding: 12px; border: 1px solid #dcdde1; border-radius: 8px; font-size: 13px; outline: none; transition: 0.2s; background: #fff;}
        input:focus, select:focus { border-color: #b32a2a; }
        input[readonly] { background: #f8f9fa; color: #7f8c8d; cursor: not-allowed;}

        .box-fast { display: none; background: #fdfaf2; padding: 20px; border-radius: 8px; border: 1px dashed #f39c12; margin-top: 15px; }
        .box-fast h4 { margin: 0 0 15px 0; color: #f39c12; font-size: 13px; display: flex; align-items: center; gap: 8px; }

        /* LINHA ISOLADA DA CENTRAL DE INCÊNDIO */
        .box-central { display: none; background: #fdf2f2; padding: 15px 20px; border-radius: 8px; border: 1px solid #fadbd8; margin-bottom: 25px; }
        .box-central h4 { margin: 0 0 10px 0; color: #b32a2a; font-size: 13px; display: flex; align-items: center; gap: 8px; text-transform: uppercase; }
        .box-central table { min-width: 900px; background: #fff; }

        .table-responsive { width: 100%; overflow-x: auto; margin-top: 10px; }
        table { width: 100%; border-collapse: collapse; min-width: 1000px; }
        th { background: #f8f9fa; padding: 12px 8px; font-size: 10px; color: #576574; border: 1px solid #eee; text-align: left; text-transform: uppercase; transition: 0.2s; }
        td { border: 1px solid #f1f2f6; padding: 4px; vertical-align: top; transition: 0.2s; }
        table input, table select { border: none; padding: 8px; background: transparent; width: 100%; font-size: 13px; outline: none; box-sizing: border-box; border-radius:4px;}
        table input:focus, table select:focus { background: #fdf2f2; }
        
        .text-center { text-align: center; }
        input.text-center, select.text-center { text-align: center; text-align-last: center; }
        .linha-erro { background-color: #fdeded !important; }
        
        .btn-add { background: #b32a2a; color: #fff; border: none; padding: 10px 15px; border-radius: 8px; cursor: pointer; font-weight: 600; margin-top: 15px; display: inline-flex; align-items: center; gap: 8px; transition: 0.2s; }
        .btn-add:hover { background: #902222; }

        .acoes-coluna { display: flex; gap: 5px; justify-content: center; align-items: center; height: 100%; margin-top: 5px; }
        .btn-dup { color: #2980b9; background: none; border: none; cursor: pointer; font-size: 16px; transition: 0.2s; }
        .btn-dup:hover { color: #1c5980; transform: scale(1.1); }
        .btn-del { color: #b32a2a; background: none; border: none; cursor: pointer; font-size: 16px; transition: 0.2s; }
        .btn-del:hover { color: #902222; transform: scale(1.1); }

        .linha-obs { display: flex; gap: 10px; margin-bottom: 10px; align-items: center; }
        .linha-obs input { flex: 1; border: 1px solid #dcdde1; padding: 10px; border-radius: 8px; }

        .floating-action { position: fixed; bottom: 25px; right: 25px; background: #fff; padding: 15px 25px; border-radius: 12px; box-shadow: 0 5px 25px rgba(0,0,0,0.15); border: 1px solid #e1e5ea; z-index: 100; display:flex; gap:10px;}
        .btn-save { background: #b32a2a; color: #fff; padding: 12px 35px; border-radius: 8px; border: none; font-weight: 700; cursor: pointer; font-size: 14px; display: flex; align-items: center; gap: 10px; transition: 0.2s; text-transform: uppercase;}
        .btn-save:hover { background: #902222; transform: translateY(-2px); }
        
        .btn-csv { background: #fdf2f2; color: #b32a2a; border: 1px solid #fadbd8; padding: 12px 20px; border-radius: 8px; font-weight: 700; cursor: pointer; font-size: 13px; display: flex; align-items: center; gap: 8px; transition: 0.2s; text-transform: uppercase;}
        .btn-csv:hover { background: #fadbd8; }

        .modal-gold { display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.7); z-index:10000; align-items:center; justify-content:center; backdrop-filter: blur(2px); }
        .modal-gold-content { background:#fff; padding:30px; border-radius:15px; text-align:center; max-width:400px; box-shadow: 0 10px 40px rgba(0,0,0,0.3); }
        .modal-gold-content i { font-size: 50px; color: #27ae60; margin-bottom: 20px; }
        .modal-gold-btns { display:flex; gap:10px; justify-content:center; margin-top:25px; }
        .btn-opt { padding:12px 20px; border-radius:8px; border:none; cursor:pointer; font-weight:bold; transition: 0.2s; }
        .btn-opt:hover { filter: brightness(0.9); }

        @media (max-width: 768px) {
            .user-nome { display: none; }
            .floating-action { bottom: 15px; right: 15px; left: 15px; justify-content: center; flex-wrap: wrap;}
            .btn-save { width: 100%; justify-content: center; }
        }
    </style>
</head>
<body>

    <datalist id="dl_desc"><?php foreach(array_keys($sugestoes['descricao']) as $v) echo "<option value='".htmlspecialchars($v)."'>"; ?></datalist>
    <datalist id="dl_dyn_ref"></datalist>
    <datalist id="dl_artigos"></datalist>
    <datalist id="dl_tipo_ilum">
        <option value="Permanente">
        <option value="Não Permanente">
    </datalist>

    <header class="topbar">
        <div class="topbar-left"><a href="index.php"><img src="mab.png" alt="MAB" class="logo"></a></div>
        <div class="topbar-center"></div> <div class="topbar-right">
            <a href="termos_scie.php" class="btn-voltar" title="Voltar" aria-label="Voltar"><i class="fa-solid fa-arrow-left"></i></a>
            <div class="user-menu">
                <button type="button" class="user-btn">
                    <div class="user-avatar"><i class="fa-solid fa-user"></i></div>
                    <span class="user-nome"><?= htmlspecialchars($primeiro_nome) ?></span> 
                    <i class="fa-solid fa-chevron-down" style="font-size: 10px;"></i>
                </button>
                <div class="dropdown-content">
                    <a href="perfil.php">O meu perfil</a>
                    <?php if ($is_admin): ?>
                        <a href="configuracoes.php">Configurações</a>
                    <?php endif; ?>
                    <a href="index.php?logout=1" style="color: #e74c3c; border-top: 1px solid #f1f2f6;"><i class="fa-solid fa-right-from-bracket" style="width:20px;"></i> Sair</a>
                </div>
            </div>
        </div>
    </header>

    <div class="container">
        
        <div class="page-header">
            <h1 class="page-title" style="color: <?= $tipos_doc[$tipo_documento_get]['color'] ?>">
                <i class="fa-solid <?= $tipos_doc[$tipo_documento_get]['icon'] ?>"></i> 
                <?= $modo_editar ? 'Editar' : 'Novo' ?>: <?= $tipos_doc[$tipo_documento_get]['label'] ?>
            </h1>
        </div>

        <form id="formScie" onsubmit="event.preventDefault(); salvarTermo();">
            <input type="hidden" name="tipo_documento_real" value="<?= htmlspecialchars($tipo_documento_get) ?>">
            <?php /* REGRA DE OURO 7 */ ?><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(gerar_csrf_token()) ?>">
            <input type="hidden" name="itens_json" id="itens_json">
            <input type="hidden" name="obs_json" id="obs_json">

            <div class="card">
                <h2><i class="fa-solid fa-user-tie"></i> 1. Cliente e Obra</h2>
                
                <div class="form-grid">
                    <div class="form-group" style="grid-column: span 2;">
                        <label>Cliente *</label>
                        <select name="id_cliente" id="sel_cli" onchange="atualizarInterfaceCliente()" required>
                            <option value="">-- Selecione o Cliente --</option>
                            <option value="novo" style="font-weight:bold; color:#f39c12;">+ CRIAR NOVO CLIENTE</option>
                            <?php foreach($clientes as $c): ?>
                                <option value="<?= $c['id'] ?>" <?= (isset($dados_json['id_cliente']) && $dados_json['id_cliente'] == $c['id']) ? 'selected' : '' ?>><?= htmlspecialchars($c['nome']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group" style="grid-column: span 2;">
                        <label>Obra *</label>
                        <select name="id_obra" id="sel_obra" onchange="aoSelecionarObra()" required>
                            <option value="">-- Escolha primeiro o Cliente --</option>
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
                        <div class="form-group"><label>Referência (Configuração Central)</label><input type="text" name="nova_obra_ref" value="<?= htmlspecialchars($referencia_gerada) ?>" readonly title="Apenas leitura" style="background:#f8f9fa;"></div>
                        <div class="form-group" style="grid-column: span 3;"><label>Morada Física da Obra</label><input type="text" name="nova_obra_morada"></div>
                    </div>
                </div>
                
                <div class="form-grid" style="margin-top:20px;">
                    <div class="form-group">
                        <label>Tipo de Intervenção</label>
                        <select name="tipo_intervencao">
                            <option value="manutencao" <?= ($termo_data['tipo_intervencao']=='manutencao')?'selected':''?>>Manutenção Preventiva</option>
                            <option value="instalacao" <?= ($termo_data['tipo_intervencao']=='instalacao')?'selected':''?>>Instalação</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Data de Emissão / Relatório</label>
                        <input type="date" name="data_emissao" value="<?= $termo_data['data_emissao'] ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Estado do Documento</label>
                        <select name="estado">
                            <option value="Rascunho" <?= ($termo_data['estado']=='Rascunho')?'selected':''?>>Rascunho</option>
                            <option value="Emitido" <?= ($termo_data['estado']=='Emitido')?'selected':''?>>Concluído / Emitido</option>
                        </select>
                    </div>
                </div>
            </div>

            <div class="card">
                <h2><i class="fa-solid fa-list-check"></i> 2. Equipamentos Intervencionados</h2>

                <!-- LINHA ISOLADA: CENTRAL DE INCÊNDIO (apenas SADI) -->
                <div id="box_central" class="box-central">
                    <h4><i class="fa-solid fa-server"></i> Central de Incêndio</h4>
                    <div class="table-responsive" style="margin-top:0;">
                        <table>
                            <thead>
                                <tr>
                                    <th width="200" title="Artigo da tabela de preços (preenche descrição, referência e marca; liga a ficha técnica e o certificado)">Artigo (tabela de preços)</th>
                                    <th width="200">Descrição</th>
                                    <th width="120" class="text-center">Referência</th>
                                    <th width="120" class="text-center">Marca</th>
                                    <th width="120" class="text-center">S/N</th>
                                    <th>Localização</th>
                                    <th width="80" class="text-center">Estado</th>
                                    <th>Observações</th>
                                </tr>
                            </thead>
                            <tbody id="corpo_central"></tbody>
                        </table>
                    </div>
                </div>

                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th width="60" class="text-center">Nº</th>
                                <th width="200" title="Artigo da tabela de preços (preenche descrição, referência e marca; liga a ficha técnica e o certificado)">Artigo (tabela de preços)</th>
                                <th width="200">Descrição</th>
                                <th width="120" class="text-center">Referência</th>
                                <th width="120" class="text-center">Marca</th>
                                
                                <th id="th_sn" width="120" class="text-center">S/N</th>
                                <th id="th_loop" width="50" class="text-center">L</th>
                                <th id="th_zona" width="100" class="text-center">Z</th>
                                <th id="th_end" width="80" class="text-center">End.</th>
                                <th>Localização</th>
                                <th width="80" class="text-center">Estado</th>
                                <th>Observações</th>
                                <th width="65" class="text-center"><i class="fa-solid fa-gears"></i></th>
                            </tr>
                        </thead>
                        <tbody id="corpo"></tbody>
                    </table>
                </div>
                <div style="display:flex; justify-content:flex-start; align-items:center;">
                    <button type="button" class="btn-add" onclick="adicionarLinhaNova()"><i class="fa-solid fa-plus"></i> Nova Linha</button>
                    <button type="button" class="btn-csv" style="margin-top:15px; margin-left:15px; font-size:11px; padding:8px 15px; border-color:#dcdde1; color:#576574; background:#f8f9fa;" onclick="renumerarTudo()"><i class="fa-solid fa-arrow-down-1-9"></i> Renumerar (1, 2, 3...)</button>
                </div>
            </div>

            <div class="card">
                <h2><i class="fa-solid fa-comment-dots"></i> 3. Observações Gerais do Relatório</h2>
                <div id="corpo_obs"></div>
                <button type="button" class="btn-add" onclick="addObs()"><i class="fa-solid fa-plus"></i></button>
            </div>

            <div class="floating-action">
                <input type="file" id="csv_file" accept=".csv" style="display:none" onchange="processarCSV(this)">
                <button type="button" class="btn-csv" onclick="document.getElementById('csv_file').click()"><i class="fa-solid fa-file-csv"></i> Importar CSV</button>
                <button type="submit" class="btn-save"><i class="fa-solid fa-check-double"></i> Guardar SCIE</button>
            </div>
        </form>
    </div>

    <div id="modalSucesso" class="modal-gold">
        <div class="modal-gold-content">
            <i class="fa-solid fa-circle-check"></i>
            <h2>Registo Guardado!</h2>
            <p>O termo SCIE foi atualizado com sucesso na base de dados.</p>
            <div class="modal-gold-btns">
                <button class="btn-opt" style="background:#f1f2f6; color:#333;" onclick="location.reload()">Continuar a Editar</button>
                <button class="btn-opt" style="background:#b32a2a; color:#fff;" onclick="location.href='termos_scie.php'">Voltar à Lista</button>
            </div>
        </div>
    </div>

    <script>
        const catEstruturado = <?= json_encode($cat_estruturado) ?>;
        const relacoesInteligentes = <?= json_encode($cat_relacoes) ?>;
        const eqsIniciais = <?= json_encode($eqs_tabela) ?>;
        const centralInicial = <?= json_encode($central_inicial ?: new stdClass()) ?>;
        const obsIniciais = <?= json_encode($dados_json['observacoes'] ?? []) ?>;
        const obrasData = <?= json_encode($obras, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
        const saved_id_obra = "<?= htmlspecialchars($termo_data['id_obra'] ?? '') ?>";
        const tipoRelatorio = "<?= htmlspecialchars($tipo_documento_get) ?>";

        // ---------- ARTIGOS DA TABELA DE PREÇOS ----------
        const ARTIGOS = <?= json_encode(array_values($artigos_tab), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
        const ART_ID = {}, ART_ROTULO = {};
        (function () {
            const dl = document.getElementById('dl_artigos'), frag = document.createDocumentFragment();
            ARTIGOS.forEach(a => {
                a.l = [a.r, a.d, a.m].filter(Boolean).join(' · ');
                ART_ID[a.id] = a; ART_ROTULO[a.l] = a;
                const o = document.createElement('option'); o.value = a.l; frag.appendChild(o);
            });
            dl.appendChild(frag);
        })();
        function rotuloArtigo(id) { const a = ART_ID[id]; return a ? a.l.replace(/"/g, '&quot;') : ''; }
        // Mostra se o produto tem ficha técnica / certificado na biblioteca
        function marcarArtigo(tr, id) {
            const a = ART_ID[id];
            tr.dataset.art = a ? a.id : '';
            tr.classList.toggle('com-artigo', !!a);
            const sp = tr.querySelector('.docs-art'); if (!sp) return;
            sp.innerHTML = a ? '<span class="' + (a.ft ? 'ok' : 'nao') + '">' + (a.ft ? '✓' : '✗') + ' ficha</span> · <span class="' + (a.ct ? 'ok' : 'nao') + '">' + (a.ct ? '✓' : '✗') + ' certificado</span>' : '';
        }
        // Escolher um artigo preenche descrição, referência e marca
        function escolherArtigo(inp) {
            const tr = inp.closest('tr'), a = ART_ROTULO[inp.value];
            if (!a) { if (inp.value !== '') inp.value = ''; marcarArtigo(tr, 0); return; }
            if (a.d) tr.querySelector('.i-desc').value = a.d;
            tr.querySelector('.i-ref').value = a.r || '';
            if (a.m) tr.querySelector('.i-marca').value = a.m;
            marcarArtigo(tr, a.id);
            inp.title = a.l;
            setTimeout(() => { inp.scrollLeft = 0; }, 0);   // mostra o início (referência) e não o fim do texto
        }

        // Menu do utilizador (toque em ecrãs táteis)
        (function () {
            const menu = document.querySelector('.user-menu'); if (!menu) return;
            menu.querySelector('.user-btn').addEventListener('click', e => { e.stopPropagation(); menu.classList.toggle('aberto'); });
            document.addEventListener('click', () => menu.classList.remove('aberto'));
        })();

        // Tipos de documento que têm linha isolada para a Central de Incêndio
        const tiposComCentral = ['sadi'];

        function init() {
            // Primeiro aplica a estrutura visual da tabela
            aplicarConfiguracaoColunas();

            // Linha isolada da Central de Incêndio
            if (tiposComCentral.includes(tipoRelatorio)) {
                document.getElementById('box_central').style.display = 'block';
                addCentral(centralInicial);
            }

            if(eqsIniciais.length > 0) {
                eqsIniciais.forEach(e => addRow(e, null, true));
            } else {
                addRow({}, null, true);
            }
            atualizarNumeracao();
            
            obsIniciais.forEach(o => addObs(o));
            
            if(document.getElementById('sel_cli').value !== '') {
                atualizarInterfaceCliente();
            }
        }

        // --- LINHA ISOLADA DA CENTRAL DE INCÊNDIO ---
        function addCentral(d = {}) {
            const tbody = document.getElementById('corpo_central');
            tbody.innerHTML = '';
            const tr = document.createElement('tr');
            const color = (d.estado === 'NOK') ? '#c0392b' : '#27ae60';
            tr.innerHTML = `
                <td><input type="text" class="i-art" list="dl_artigos" placeholder="Procurar artigo..." value="${rotuloArtigo(d.id_artigo_tabela)}" onchange="escolherArtigo(this)"><span class="docs-art"></span></td>
                <td><input type="text" class="i-desc" value="${d.descricao||''}" list="dl_desc" placeholder="Ex: Central de Deteção de Incêndio"></td>
                <td><input type="text" class="i-ref text-center" value="${d.referencia||''}" list="dl_dyn_ref" onfocus="atualizarDatalistRef(this)" oninput="autoPreencherSmart(this)"></td>
                <td><input type="text" class="i-marca text-center" value="${d.marca||''}"></td>
                <td><input type="text" class="i-sn text-center" value="${d.n_serie||''}" oninput="this.value=this.value.replace(/\\s+/g,'')"></td>
                <td><input type="text" class="i-loc" value="${d.localizacao||''}"></td>
                <td><select class="i-st text-center" style="font-weight:bold; color:${color}" onchange="this.style.color=(this.value==='NOK')?'#c0392b':'#27ae60'">
                    <option value="OK" ${d.estado!='NOK'?'selected':''}>OK</option>
                    <option value="NOK" ${d.estado==='NOK'?'selected':''}>NOK</option>
                </select></td>
                <td><input type="text" class="i-obs" value="${d.obs||''}"></td>
            `;
            tbody.appendChild(tr);
            marcarArtigo(tr, d.id_artigo_tabela);
        }

        // --- MOTOR CAMALEÃO: CONFIGURAÇÃO DE COLUNAS ---
        function aplicarConfiguracaoColunas() {
            const conf = {
                'sadi': { sn: true, loop: true, end: true, zona: true, labelZona: 'Z' },
                'deteccao_gas': { sn: true, loop: true, end: true, zona: true, labelZona: 'Z' },
                'iluminacao_emergencia': { sn: false, loop: false, end: false, zona: true, labelZona: 'Tipo (Perm/Não Perm)' },
                'portas_fogo': { sn: false, loop: false, end: false, zona: false }, // Escondemos ZONA
                'compartimentacao': { sn: false, loop: false, end: false, zona: false },
                'extincao_agua': { sn: true, loop: false, end: false, zona: true, labelZona: 'Zona' },
                'sinalizacao': { sn: false, loop: false, end: false, zona: false }
            };

            let c = conf[tipoRelatorio] || conf['sadi']; // Default é SADI

            // Esconder Cabeçalhos Dinamicamente
            document.getElementById('th_sn').style.display = c.sn ? 'table-cell' : 'none';
            document.getElementById('th_loop').style.display = c.loop ? 'table-cell' : 'none';
            document.getElementById('th_end').style.display = c.end ? 'table-cell' : 'none';
            
            let thZona = document.getElementById('th_zona');
            thZona.style.display = c.zona ? 'table-cell' : 'none';
            thZona.innerText = c.labelZona || 'Z';

            // Propagar a visibilidade para todas as linhas já desenhadas (se houver)
            document.querySelectorAll('#corpo tr').forEach(tr => setRowVisibility(tr, c));
        }

        function setRowVisibility(tr, c) {
            tr.querySelector('.col-sn').style.display = c.sn ? 'table-cell' : 'none';
            tr.querySelector('.col-loop').style.display = c.loop ? 'table-cell' : 'none';
            tr.querySelector('.col-end').style.display = c.end ? 'table-cell' : 'none';
            tr.querySelector('.col-zona').style.display = c.zona ? 'table-cell' : 'none';
            
            let inputZ = tr.querySelector('.i-z');
            if (tipoRelatorio === 'iluminacao_emergencia') {
                inputZ.setAttribute('list', 'dl_tipo_ilum');
                inputZ.setAttribute('placeholder', 'Ex: Permanente');
            } else {
                inputZ.removeAttribute('list');
                inputZ.removeAttribute('placeholder');
            }
        }
        // ------------------------------------------------

        // --- LÓGICA DE NUMERAÇÃO AUTO-CURATIVA SEQUENCIAL ---
        function atualizarNumeracao() {
            let counter = 1;
            document.querySelectorAll('#corpo tr').forEach((tr) => {
                let numInput = tr.querySelector('.i-n');
                let currentVal = numInput.value.trim();
                
                // Força sempre a sequência ou respeita a tua entrada manual.
                if (currentVal === '' || /^\d+$/.test(currentVal)) {
                    numInput.value = counter;
                    counter++;
                } else {
                    // É um sub-item manual (ex: 12.1). Respeitamos!
                    let baseInt = parseInt(currentVal, 10);
                    if (!isNaN(baseInt) && baseInt >= counter) {
                        counter = baseInt + 1;
                    }
                }
            });
        }

        // --- LÓGICA DE CLIENTE / OBRA ---
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

        function atualizarInterfaceCliente() { 
            const id = document.getElementById('sel_cli').value; 
            const selectObra = document.getElementById('sel_obra'); 
            document.getElementById('box_novo_cliente').style.display = (id === 'novo') ? 'block' : 'none'; 
            
            if (id === 'novo') { 
                selectObra.innerHTML = '<option value="nova">+ CRIAR NOVA OBRA AUTOMATICAMENTE</option>'; 
            } else if (id) { 
                selectObra.innerHTML = '<option value="">-- Selecione --</option>'; 
                obrasData.filter(o => o.id_cliente == id).forEach(o => {
                    let opt = new Option(o.obra_nome, o.id);
                    if(o.id == saved_id_obra) opt.selected = true;
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
        }

        // --- LÓGICA DA TABELA DE EQUIPAMENTOS ---
        function atualizarDatalistRef(inputRef) {
            const tr = inputRef.closest('tr');
            const desc = tr.querySelector('.i-desc').value.trim();
            const dl = document.getElementById('dl_dyn_ref');
            dl.innerHTML = '';
            let lista = (desc && catEstruturado[desc]) ? Object.keys(catEstruturado[desc]) : Object.keys(relacoesInteligentes);
            lista.forEach(r => dl.appendChild(new Option(r)));
        }

        function autoPreencherSmart(inputRef) {
            const ref = inputRef.value.trim();
            if (relacoesInteligentes[ref]) {
                const tr = inputRef.closest('tr');
                tr.querySelector('.i-marca').value = relacoesInteligentes[ref].marca;
                if (!tr.querySelector('.i-desc').value) tr.querySelector('.i-desc').value = relacoesInteligentes[ref].descricao;
            }
        }

        function renumerarTudo() {
            if(confirm("Deseja reescrever todos os números de forma sequencial (1, 2, 3...)?\n\nAtenção: Qualquer número inserido manualmente (ex: 12.1 ou 15A) será substituído e perdido.")) {
                document.querySelectorAll('#corpo tr').forEach((tr, idx) => {
                    tr.querySelector('.i-n').value = idx + 1;
                });
            }
        }

        function adicionarLinhaNova() {
            const tbody = document.getElementById('corpo');
            const lastTr = tbody.querySelector('tr:last-child');
            let nextEnd = "";
            let nextLoc = "";

            if (lastTr) {
                // Cálculo inteligente do Endereço
                let end = lastTr.querySelector('.i-end').value;
                let match = end.match(/(\d+)$/);
                if (match) nextEnd = end.substring(0, match.index) + (parseInt(match[0]) + 1);
                
                nextLoc = lastTr.querySelector('.i-loc').value;
            }

            addRow({ numero: '', endereco: nextEnd, localizacao: nextLoc });
        }

        function addRow(d = {}, insertAfter = null, skipRenumber = false) {
            const tbody = document.getElementById('corpo');
            const tr = document.createElement('tr');
            const color = (d.estado === 'NOK') ? '#c0392b' : '#27ae60';
            
            // ATENÇÃO: O Campo do Número é agora editável. O OnBlur dispara o Auto-Healing!
            tr.innerHTML = `
                <td><input type="text" class="i-n text-center" value="${d.numero||''}" style="font-weight:bold; color:#b32a2a; border: 1px solid transparent; background: transparent; transition:0.2s;" onfocus="this.style.border='1px solid #dcdde1'; this.style.background='#fff'" onblur="this.style.border='1px solid transparent'; this.style.background='transparent'; atualizarNumeracao();" title="Pode editar (Ex: 12.1)"></td>
                <td><input type="text" class="i-art" list="dl_artigos" placeholder="Procurar artigo..." value="${rotuloArtigo(d.id_artigo_tabela)}" onchange="escolherArtigo(this)"><span class="docs-art"></span></td>
                <td><input type="text" class="i-desc" value="${d.descricao||''}" list="dl_desc"></td>
                <td><input type="text" class="i-ref text-center" value="${d.referencia||''}" list="dl_dyn_ref" onfocus="atualizarDatalistRef(this)" oninput="autoPreencherSmart(this)"></td>
                <td><input type="text" class="i-marca text-center" value="${d.marca||''}"></td>
                <td class="col-sn"><input type="text" class="i-sn text-center" value="${d.n_serie||''}" oninput="this.value=this.value.replace(/\\s+/g,'')"></td>
                <td class="col-loop"><input type="text" class="i-l text-center" value="${d.loop_eq||d.loop||''}"></td>
                <td class="col-zona"><input type="text" class="i-z text-center" value="${d.zona||''}"></td>
                <td class="col-end"><input type="text" class="i-end text-center" value="${d.endereco||''}"></td>
                <td><input type="text" class="i-loc" value="${d.localizacao||''}"></td>
                <td><select class="i-st text-center" style="font-weight:bold; color:${color}" onchange="this.style.color=(this.value==='NOK')?'#c0392b':'#27ae60'">
                    <option value="OK" ${d.estado!='NOK'?'selected':''}>OK</option>
                    <option value="NOK" ${d.estado==='NOK'?'selected':''}>NOK</option>
                </select></td>
                <td><input type="text" class="i-obs" value="${d.obs||''}"></td>
                <td><div class="acoes-coluna">
                    <button type="button" class="btn-dup" onclick="duplicarLinha(this)"><i class="fa-solid fa-copy"></i></button>
                    <button type="button" class="btn-del" onclick="this.closest('tr').remove(); atualizarNumeracao();"><i class="fa-solid fa-trash-can"></i></button>
                </div></td>
            `;
            if (insertAfter) insertAfter.insertAdjacentElement('afterend', tr);
            else tbody.appendChild(tr);
            marcarArtigo(tr, d.id_artigo_tabela);
            
            aplicarConfiguracaoColunas();
            if (!skipRenumber) atualizarNumeracao();
        }

        function duplicarLinha(btn) {
            const tr = btn.closest('tr');
            
            // Lógica de endereço sequencial
            let end = tr.querySelector('.i-end').value;
            let match = end.match(/(\d+)$/);
            let nextEnd = match ? end.substring(0, match.index) + (parseInt(match[0]) + 1) : end;

            // Ao duplicar passamos o número como VAZIO. 
            // A função 'atualizarNumeracao()' encarrega-se de o reescrever como 1,2,3,4,5...
            addRow({
                numero: '',
                id_artigo_tabela: tr.dataset.art || '',
                descricao: tr.querySelector('.i-desc').value, 
                referencia: tr.querySelector('.i-ref').value,
                marca: tr.querySelector('.i-marca').value, 
                n_serie: tr.querySelector('.i-sn').value,
                loop_eq: tr.querySelector('.i-l').value,
                zona: tr.querySelector('.i-z').value, 
                endereco: nextEnd, 
                localizacao: tr.querySelector('.i-loc').value,
                estado: tr.querySelector('.i-st').value, 
                obs: tr.querySelector('.i-obs').value
            }, tr);
        }

        function addObs(val = '') {
            const div = document.createElement('div');
            div.className = 'linha-obs';
            div.innerHTML = `<input type="text" class="gen-obs" value="${val}" placeholder="Escreva a observação..."><button type="button" class="btn-del" style="background:none;" onclick="this.parentElement.remove()"><i class="fa-solid fa-circle-xmark"></i></button>`;
            document.getElementById('corpo_obs').appendChild(div);
            if(!val) div.querySelector('input').focus();
        }

        // --- IMPORTAÇÃO CSV OTIMIZADA PARA RENUMERAÇÃO ---
        function processarCSV(input) {
            const file = input.files[0];
            if (!file) return;
            const reader = new FileReader();
            
            reader.onload = function(e) {
                const text = e.target.result;
                const lines = text.split('\n');
                let adicionados = 0;
                
                for (let i = 0; i < lines.length; i++) {
                    let line = lines[i].trim();
                    if (!line) continue;
                    
                    let cols = line.split(';');
                    if(cols.length < 2 && line.includes(',')) { cols = line.split(','); }
                    
                    if (i === 0 && cols[0].toLowerCase().includes('desc')) continue;
                    if (cols.length < 2) continue; 

                    addRow({
                        numero: '', // Deixa vazio para forçar numeração automática no fim
                        descricao: cols[0] ? cols[0].trim().replace(/['"]/g,'') : '',
                        referencia: cols[1] ? cols[1].trim().replace(/['"]/g,'') : '',
                        marca: cols[2] ? cols[2].trim().replace(/['"]/g,'') : '',
                        n_serie: cols[3] ? cols[3].trim().replace(/['"]/g,'') : '',
                        loop_eq: cols[4] ? cols[4].trim().replace(/['"]/g,'') : '',
                        zona: cols[5] ? cols[5].trim().replace(/['"]/g,'') : '',
                        endereco: cols[6] ? cols[6].trim().replace(/['"]/g,'') : '',
                        localizacao: cols[7] ? cols[7].trim().replace(/['"]/g,'') : '',
                        estado: 'OK',
                        obs: cols[8] ? cols[8].trim().replace(/['"]/g,'') : ''
                    }, null, true); // O TRUE impede a renumeracão em massa linha-a-linha
                    adicionados++;
                }
                
                if(adicionados > 0) {
                    atualizarNumeracao(); // Faz a renumeracão apenas 1x no final para poupar memória
                    alert(`Importação concluída! ${adicionados} equipamentos adicionados à tabela.`);
                } else {
                    alert("Atenção: Não foram encontrados dados estruturados corretamente no ficheiro CSV.");
                }
                input.value = ''; 
            };
            reader.readAsText(file, 'ISO-8859-1'); 
        }

        // --- GRAVAR O TERMO (AJAX) ---
        async function salvarTermo() {
            const id_cli = document.getElementById('sel_cli').value;
            if(id_cli === '') { alert("Tem de selecionar um Cliente."); return; }
            if(id_cli === 'novo') { 
                const nif = document.getElementById('nif_input').value; 
                if(nif !== "" && !jsValidarNIF(nif)) { alert("O Contribuinte introduzido não é válido."); return; } 
                if(document.getElementById('novo_cliente_nome').value.trim() === '') { alert("Tem de indicar o Nome do Novo Cliente."); return; }
            }

            const id_obra = document.getElementById('sel_obra').value;
            if(id_obra === '') { alert("Tem de selecionar uma Obra."); return; }
            if(id_obra === 'nova') {
                if(document.getElementById('nova_obra_nome').value.trim() === '') { alert("Tem de indicar o Nome da Nova Obra."); return; }
            }

            const rows = [];
            const snSet = new Set();
            const loopEndSet = new Set();
            let erro = false;

            // CENTRAL DE INCÊNDIO (linha isolada) — gravada em primeiro lugar com número 'C'
            const trCentral = document.querySelector('#corpo_central tr');
            if (trCentral && tiposComCentral.includes(tipoRelatorio)) {
                const descC = trCentral.querySelector('.i-desc').value.trim();
                if (descC !== '') {
                    rows.push({
                        numero: 'C',
                        descricao: descC,
                        referencia: trCentral.querySelector('.i-ref').value.trim(),
                        marca: trCentral.querySelector('.i-marca').value.trim(),
                        n_serie: trCentral.querySelector('.i-sn').value.trim(),
                        loop_eq: '',
                        zona: '',
                        endereco: '',
                        localizacao: trCentral.querySelector('.i-loc').value,
                        estado: trCentral.querySelector('.i-st').value,
                        obs: trCentral.querySelector('.i-obs').value,
                        id_artigo_tabela: trCentral.dataset.art || ''
                    });
                }
            }

            document.querySelectorAll('#corpo tr').forEach((tr, index) => {
                tr.classList.remove('linha-erro');
                const desc = tr.querySelector('.i-desc').value.trim();
                
                if(desc !== '') {
                    const sn = tr.querySelector('.i-sn').value.trim();
                    const ref = tr.querySelector('.i-ref').value.trim();
                    const marca = tr.querySelector('.i-marca').value.trim();
                    const loop = tr.querySelector('.i-l').value.trim();
                    const end = tr.querySelector('.i-end').value.trim();

                    // Regras de validação (Apenas se a coluna SADI ou Deteção Gás estiver ativa)
                    if (tipoRelatorio === 'sadi' || tipoRelatorio === 'deteccao_gas') {
                        if (loop !== '' && end !== '') {
                            const leKey = `${loop}|${end}`.toLowerCase();
                            if (loopEndSet.has(leKey)) {
                                erro = true; tr.classList.add('linha-erro');
                                alert(`Erro na Linha ${index + 1}: O Endereço '${end}' já existe no Loop '${loop}'. Não podem haver endereços repetidos.`);
                            } else { loopEndSet.add(leKey); }
                        }

                        if(sn !== '') {
                            const key = `${ref}|${marca}|${sn}`.toLowerCase();
                            if(snSet.has(key)) {
                                erro = true; tr.classList.add('linha-erro');
                                alert(`Erro na Linha ${index + 1}: O S/N '${sn}' está duplicado para a mesma Referência e Marca.`);
                            } else { snSet.add(key); }
                        }
                    }

                    rows.push({
                        numero: tr.querySelector('.i-n').value, // Preserva exatamente o que está escrito
                        descricao: desc,
                        referencia: ref, marca: marca, n_serie: sn,
                        loop_eq: loop, zona: tr.querySelector('.i-z').value,
                        endereco: end, localizacao: tr.querySelector('.i-loc').value,
                        estado: tr.querySelector('.i-st').value, obs: tr.querySelector('.i-obs').value,
                        id_artigo_tabela: tr.dataset.art || ''
                    });
                }
            });

            if (erro) return;

            const formData = new FormData(document.getElementById('formScie'));
            formData.append('itens_json', JSON.stringify(rows));
            formData.append('obs_json', JSON.stringify(Array.from(document.querySelectorAll('.gen-obs')).map(i => i.value.trim()).filter(v => v !== '')));

            try {
                let currentUrl = window.location.href.split('?')[0];
                let fetchUrl = currentUrl + '?ajax_save=1' + (<?= $modo_editar ? 'true' : 'false' ?> ? '&id=<?= $id_edit ?>' : '');
                
                const resp = await fetch(fetchUrl, { method: 'POST', body: formData });
                const result = await resp.json();
                
                if (result.status === 'success') {
                    window.history.replaceState({}, '', currentUrl + '?id=' + result.termo_id);
                    document.getElementById('modalSucesso').style.display = 'flex';
                } else {
                    alert("Erro ao gravar: " + result.message);
                }
            } catch(e) {
                alert("Erro de comunicação com o servidor.");
            }
        }
        
        window.addEventListener('keydown', e => {
            if(e.key === 'Enter' && e.target.tagName === 'INPUT') {
                e.preventDefault();
                if(e.target.classList.contains('gen-obs')) addObs();
                else if(e.target.closest('#corpo')) adicionarLinhaNova();
            }
        });

        window.onload = init;
    </script>
</body>
</html>
