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
$user_id_atual = $_SESSION['user_id'];
$is_admin = in_array($perfil_user, ['admin', 'administrativo']);

// REGRA DE OURO 2: Acesso Restrito pela Matriz (Proteção de URL direta)
$stmt_perm = $pdo->prepare("SELECT modulo FROM permissoes WHERE grupo = :perfil AND pode_aceder = 1");
$stmt_perm->execute(['perfil' => $perfil_user]);
$meus_acessos = $stmt_perm->fetchAll(PDO::FETCH_COLUMN);

if (!$is_admin && !in_array('servicos', $meus_acessos)) {
    header("Location: index.php?erro=sem_permissao");
    exit;
}

// REGRA DE OURO 3: Obter apenas o primeiro nome para o Menu
$nome_completo = $_SESSION['nome'] ?? 'Utilizador';
$primeiro_nome = explode(' ', trim($nome_completo))[0];

$mensagem = '';
$tipo_mensagem = '';

if (isset($_GET['editado'])) {
    $mensagem = "Operação realizada com sucesso!";
    $tipo_mensagem = "sucesso";
}

// LÓGICA DE APAGAR REGISTO COM PASSWORD DE SEGURANÇA
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['apagar_id'])) {
    $id_apagar = (int)$_POST['apagar_id'];
    $pass_introduzida = $_POST['pass_seguranca'] ?? '';

    $stmt_pass = $pdo->prepare("SELECT valor FROM configuracoes WHERE chave = 'password_apagar' LIMIT 1");
    $stmt_pass->execute();
    $pass_config = $stmt_pass->fetchColumn();

    if ($pass_introduzida === $pass_config && !empty($pass_config)) {
        try {
            $stmt_del = $pdo->prepare("DELETE FROM servicos WHERE id = ?");
            $stmt_del->execute([$id_apagar]);
            registrarLog($pdo, "Apagou Serviço #$id_apagar", "Serviços");
            $mensagem = "Serviço apagado com sucesso!";
            $tipo_mensagem = "sucesso";
        } catch (Exception $e) {
            $mensagem = "Erro ao apagar serviço.";
            $tipo_mensagem = "erro";
        }
    } else {
        $mensagem = "Password de segurança incorreta!";
        $tipo_mensagem = "erro";
    }
}

// GERAR LISTA DE MESES (Atual + 12 anteriores)
$meses_nomes = [1=>'Janeiro', 2=>'Fevereiro', 3=>'Março', 4=>'Abril', 5=>'Maio', 6=>'Junho', 7=>'Julho', 8=>'Agosto', 9=>'Setembro', 10=>'Outubro', 11=>'Novembro', 12=>'Dezembro'];
$lista_meses = [];
for ($i = 0; $i <= 12; $i++) {
    $time = strtotime("-$i months", strtotime(date('Y-m-01')));
    $val = date('Y-m', $time);
    $label = $meses_nomes[(int)date('m', $time)] . ' ' . date('Y', $time);
    $lista_meses[$val] = $label;
}

// FILTRAGEM INTELIGENTE
$mes_selecionado = $_GET['mes_filtro'] ?? date('Y-m'); // Mês atual por defeito
$params = [];
$where = "1=1";

if ($mes_selecionado !== 'todos') {
    $data_inicio_sql = $mes_selecionado . '-01';
    $data_fim_sql = date('Y-m-t', strtotime($data_inicio_sql));
    
    $where .= " AND s.data_servico >= :data_inicio AND s.data_servico <= :data_fim";
    $params['data_inicio'] = $data_inicio_sql;
    $params['data_fim'] = $data_fim_sql;
}

// SE NÃO FOR ADMIN, MOSTRA SÓ OS SERVIÇOS ONDE PARTICIPOU
if (!$is_admin) {
    $where .= " AND (s.colaborador_1 = :user_id OR s.colaborador_2 = :user_id)";
    $params['user_id'] = $user_id_atual;
}

// BUSCAR SERVIÇOS
$sql = "SELECT s.id, s.data_servico, s.trabalho_realizado, s.em_garantia, s.faturado, s.fatura_num, 
               c.nome AS cliente_nome, u1.nome AS colab1_nome, u2.nome AS colab2_nome
        FROM servicos s 
        LEFT JOIN clientes c ON s.id_cliente = c.id 
        LEFT JOIN utilizadores u1 ON s.colaborador_1 = u1.id 
        LEFT JOIN utilizadores u2 ON s.colaborador_2 = u2.id
        WHERE $where
        ORDER BY s.data_servico DESC, s.id DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$todos_servicos = $stmt->fetchAll(PDO::FETCH_ASSOC);

// SEPARAR POR CATEGORIAS
$srv_nao_faturados = [];
$srv_faturados = [];
$srv_garantia = [];

foreach ($todos_servicos as $s) {
    if ($s['em_garantia'] == 1) {
        $srv_garantia[] = $s;
    } elseif ($s['faturado'] == 1) {
        $srv_faturados[] = $s;
    } else {
        $srv_nao_faturados[] = $s;
    }
}

// Função auxiliar para renderizar a tabela
function renderizarTabela($servicos_lista, $titulo, $cor_titulo) {
    if (count($servicos_lista) === 0) return ''; 
    
    $html = "<div class='tabela-section'>";
    $html .= "<h2 style='color: {$cor_titulo}; border-bottom: 2px solid {$cor_titulo}80;' class='section-title'>{$titulo}</h2>";
    $html .= "<div class='card'><div class='table-responsive'><table>";
    $html .= "<thead><tr>
                <th class='col-pc' style='width: 100px;'>Data</th>
                <th>Cliente</th>
                <th class='col-colab'>Colaborador</th>
                <th class='col-pc'>Trabalho Realizado</th>
                <th class='col-pc' style='width: 130px;'>Info</th>
                <th style='width: 140px; text-align: center;'>Ações</th>
              </tr></thead><tbody>";
    
    foreach ($servicos_lista as $s) {
        $data_formatada = date('d/m/Y', strtotime($s['data_servico']));
        $cliente = htmlspecialchars($s['cliente_nome'] ?? 'Cliente Removido');
        
        $colab = htmlspecialchars($s['colab1_nome'] ?? 'N/D');
        if (!empty($s['colab2_nome'])) $colab .= "<br><span style='font-size:11px; color:#7f8c8d;'>" . htmlspecialchars($s['colab2_nome']) . "</span>";
        
        $trabalho = "<span class='truncate' title='" . htmlspecialchars($s['trabalho_realizado']) . "'>" . htmlspecialchars($s['trabalho_realizado']) . "</span>";
        
        $info = "<div class='badge-container'>";
        if (!empty($s['em_garantia'])) $info .= "<span class='badge badge-garantia'><i class='fa-solid fa-shield-halved'></i> Garantia</span>";
        if (!empty($s['faturado'])) $info .= "<span class='badge badge-fatura'><i class='fa-solid fa-file-invoice-dollar'></i> " . htmlspecialchars($s['fatura_num'] ?: 'Faturado') . "</span>";
        if (empty($s['em_garantia']) && empty($s['faturado'])) $info .= "<span style='color: #bdc3c7; font-size: 12px; font-style: italic;'>---</span>";
        $info .= "</div>";

        $acoes = "<div class='acoes' style='justify-content: center;'>
                    <button type='button' class='btn-print-action' title='Imprimir PDF' onclick='abrirModalImprimir({$s['id']})'><i class='fa-solid fa-print'></i></button>
                    <a href='servico_form.php?id={$s['id']}' class='btn-edit' title='Editar'><i class='fa-solid fa-pen-to-square'></i></a>
                    <button type='button' class='btn-delete' title='Apagar' onclick='abrirModalApagar({$s['id']})'><i class='fa-solid fa-trash-can'></i></button>
                  </div>";

        $html .= "<tr>";
        $html .= "<td class='col-pc' style='font-weight:600;'>{$data_formatada}</td>";
        $html .= "<td style='font-weight: 600;'><div class='col-mobile mobile-data'>{$data_formatada}</div>{$cliente}</td>";
        $html .= "<td class='col-colab'>{$colab}</td>";
        $html .= "<td class='col-pc'>{$trabalho}</td>";
        $html .= "<td class='col-pc'>{$info}</td>";
        $html .= "<td>{$acoes}</td>";
        $html .= "</tr>";
    }
    
    $html .= "</tbody></table></div></div></div>";
    return $html;
}
?>
<!DOCTYPE html>
<html lang="pt-PT">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Lista de Serviços - Gestão MAB</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { font-family: system-ui, -apple-system, sans-serif; box-sizing: border-box; }
        body { background-color: #f7f9fc; margin: 0; color: #333; font-size: 14px; padding-bottom: 50px; }
        
        .topbar { background-color: #fff; padding: 10px 40px; box-shadow: 0 2px 15px rgba(0,0,0,0.05); display: flex; justify-content: space-between; align-items: center; position: sticky; top: 0; z-index: 100; }
        .topbar-left .logo { height: 35px; }
        .topbar-center { display: flex; align-items: center; gap: 20px; }
        .nav-icon { color: #95a5a6; font-size: 20px; text-decoration: none; transition: 0.2s; padding: 5px; border-radius: 6px; }
        .nav-icon:hover { background: #f1f2f6; }
        
        .nav-icon.i-clientes.active, .nav-icon.i-clientes:hover { color: #3498db; }
        .nav-icon.i-visitas.active, .nav-icon.i-visitas:hover { color: #e84393; }
        .nav-icon.i-servicos.active, .nav-icon.i-servicos:hover { color: #b32a2a; } /* Vermelho Serviços */
        .nav-icon.i-obras.active, .nav-icon.i-obras:hover { color: #2ecc71; }
        .nav-icon.i-relatorios.active, .nav-icon.i-relatorios:hover { color: #16a085; }
        .nav-icon.i-orcamentos.active, .nav-icon.i-orcamentos:hover { color: #f39c12; }
        .nav-icon.i-termos.active, .nav-icon.i-termos:hover { color: #9b59b6; }
        .nav-icon.i-equipa.active, .nav-icon.i-equipa:hover { color: #7f8c8d; }
        .nav-icon.i-config.active, .nav-icon.i-config:hover { color: #2c3e50; }
        
        .topbar-right { display: flex; align-items: center; gap: 20px; }
        .btn-back { color: #576574; text-decoration: none; font-size: 20px; transition: color 0.2s; }
        .btn-back:hover { color: #b32a2a; }
        
        .user-menu { position: relative; }
        .user-btn { background: #fdfdfd; border: 1px solid #f1f2f6; border-radius: 30px; font-size: 14px; font-weight: 600; color: #2c3e50; cursor: pointer; display: flex; align-items: center; gap: 10px; padding: 6px 15px 6px 6px; transition: 0.2s; }
        .user-btn:hover { background: #fff; box-shadow: 0 4px 10px rgba(0,0,0,0.05); }
        .user-avatar { background: #e74c3c; color: white; width: 28px; height: 28px; border-radius: 50%; display: flex; justify-content: center; align-items: center; font-size: 14px; border: 2px solid #fadbd8; }
        
        .dropdown-content { display: none; position: absolute; right: 0; top: 100%; background: #fff; min-width: 180px; box-shadow: 0 10px 30px rgba(0,0,0,0.1); border-radius: 8px; border: 1px solid #f1f2f6; overflow: hidden; margin-top: 5px; z-index: 200;}
        .user-menu:hover .dropdown-content { display: block; }
        .dropdown-content a { color: #576574; padding: 12px 20px; text-decoration: none; display: block; font-size: 14px; }
        .dropdown-content a:hover { background: #fdf2f2; color: #b32a2a; }
        
        .container { max-width: 1300px; margin: 20px auto; padding: 0 15px; }
        
        .header-box { display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px; flex-wrap: wrap; gap: 15px; border-bottom: 1px solid #e1e5ea; padding-bottom: 15px; }
        h1 { margin: 0; color: #2c3e50; font-size: 22px; font-weight: 800; }
        
        .btn-novo { background: #fff; color: #b32a2a; border: 1px solid #b32a2a; padding: 8px 15px; border-radius: 30px; text-decoration: none; font-weight: 700; font-size: 13px; display: inline-flex; align-items: center; gap: 6px; transition: 0.2s; }
        .btn-novo:hover { background: #b32a2a; color: #fff; }

        /* Estilos do Filtro */
        .filter-box { background: #fff; padding: 20px; border-radius: 12px; border: 1px solid #e1e5ea; box-shadow: 0 2px 10px rgba(0,0,0,0.02); margin-bottom: 30px; }
        .filter-group { display: flex; flex-direction: column; flex: 1; min-width: 200px; }
        .filter-group label { font-size: 11px; font-weight: bold; color: #576574; text-transform: uppercase; margin-bottom: 5px; }
        .filter-group select { padding: 10px; border-radius: 8px; border: 1px solid #dcdde1; outline: none; font-family: inherit; font-size: 14px; background: #fff; height: 41px; cursor: pointer; transition: 0.2s; }
        .filter-group select:focus { border-color: #b32a2a; }
        
        .alert { padding: 15px; border-radius: 8px; margin-bottom: 20px; text-align: center; font-weight: 600; }
        .alert-erro { background: #fdeded; color: #b32a2a; border: 1px solid #f9cccc; }
        .alert-sucesso { background: #e8f5e9; color: #2e7d32; border: 1px solid #c8e6c9; }

        .tabela-section { margin-bottom: 40px; }
        .section-title { font-size: 16px; text-transform: uppercase; padding-bottom: 5px; margin-bottom: 15px; margin-top: 0; }
        
        .card { background: #fff; border-radius: 12px; border: 1px solid #e1e5ea; box-shadow: 0 2px 10px rgba(0,0,0,0.02); overflow: hidden; }
        .table-responsive { width: 100%; overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; min-width: 850px; }
        th { background: #f8f9fa; padding: 12px 15px; text-align: left; font-size: 11px; text-transform: uppercase; color: #576574; border-bottom: 2px solid #e1e5ea; }
        td { padding: 12px 15px; border-bottom: 1px solid #f1f2f6; color: #2c3e50; vertical-align: middle; }
        tr:hover { background: #fcfcfc; }
        
        .badge-container { display: flex; flex-direction: column; gap: 5px; align-items: flex-start; }
        .badge { display: inline-flex; align-items: center; gap: 5px; padding: 4px 8px; border-radius: 6px; font-size: 11px; font-weight: 700; text-transform: uppercase; }
        .badge-garantia { background: #fff3e0; color: #d35400; border: 1px solid #ffe0b2; }
        .badge-fatura { background: #e8f5e9; color: #27ae60; border: 1px solid #c8e6c9; }

        .acoes { display: flex; gap: 8px; }
        .btn-print-action { color: #3498db; background: #f0f8ff; padding: 8px; border: none; border-radius: 6px; cursor: pointer; transition: 0.2s; display: inline-flex; align-items: center; justify-content: center; width: 32px; height: 32px; font-size: 15px; text-decoration: none; }
        .btn-print-action:hover { background: #d6eaf8; color: #2980b9; }
        .btn-edit { color: #576574; background: #f1f2f6; padding: 8px; border-radius: 6px; text-decoration: none; transition: 0.2s; display: inline-flex; align-items: center; justify-content: center; width: 32px; height: 32px; }
        .btn-edit:hover { background: #e1e5ea; color: #2c3e50; }
        .btn-delete { color: #e74c3c; background: #fdeded; padding: 8px; border: none; border-radius: 6px; cursor: pointer; transition: 0.2s; display: inline-flex; align-items: center; justify-content: center; width: 32px; height: 32px; }
        .btn-delete:hover { background: #fadbd8; color: #c0392b; }

        .truncate { max-width: 250px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; display: inline-block; }
        .col-mobile { display: none; }

        /* Modais */
        .modal { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.6); z-index: 1001; justify-content: center; align-items: center; padding: 20px; }
        .modal.show { display: flex; }
        .modal-content { background: #fff; padding: 30px; border-radius: 12px; width: 100%; max-width: 400px; text-align: center; }
        
        .modal-content input[type="password"] { width: 100%; padding: 12px; border: 1px solid #dcdde1; border-radius: 8px; margin: 15px 0; outline: none; }
        .modal-content input[type="password"]:focus { border-color: #b32a2a; }
        .modal-btns { display: flex; gap: 10px; }
        .modal-btns button { flex: 1; padding: 12px; border: none; border-radius: 8px; font-weight: 600; cursor: pointer; }
        .btn-cancelar { background: #f1f2f6; color: #576574; }
        .btn-confirmar { background: #b32a2a; color: #fff; }

        .modal-print-content { max-width: 450px; text-align: left; padding: 20px 25px; }
        .modal-print-header { display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #f1f2f6; padding-bottom: 15px; margin-bottom: 15px; }
        .modal-print-header h3 { margin: 0; color: #b32a2a; font-size: 16px; display: flex; align-items: center; gap: 8px; }
        .close-btn { color: #95a5a6; font-size: 22px; cursor: pointer; font-weight: bold; background: none; border: none; padding: 0; line-height: 1; transition: 0.2s; }
        .close-btn:hover { color: #333; }
        .modal-subtitle { color: #576574; font-size: 14px; font-weight: 700; margin-bottom: 15px; text-align: left; }
        
        .btn-pdf-option { display: flex; align-items: center; gap: 15px; width: 100%; padding: 15px; background: #fdfdfd; border: 1px solid #e1e5ea; border-radius: 8px; cursor: pointer; text-align: left; transition: all 0.2s; margin-bottom: 10px; font-family: inherit; }
        .btn-pdf-option:hover { background: #f9f9f9; border-color: #b32a2a; box-shadow: 0 2px 5px rgba(0,0,0,0.02); }
        .btn-pdf-option i { color: #b32a2a; font-size: 22px; }
        .btn-pdf-option span { color: #2c3e50; font-weight: 600; font-size: 14px; }

        @media (max-width: 768px) {
            .topbar { padding: 10px 15px; }
            .topbar-center { display: none; }
            
            .header-box { flex-direction: row; justify-content: space-between; border-bottom: none; margin-bottom: 10px; }
            .btn-novo { padding: 6px 12px; font-size: 12px; }
            
            table { min-width: 100%; }
            .col-pc { display: none; }
            .col-mobile { display: block; }
            
            .mobile-data { font-size: 11px; color: #7f8c8d; margin-bottom: 2px; font-weight: normal; }
            td { padding: 12px 10px; }
            .col-colab { font-size: 12px; }
        }
    </style>
</head>
<body>

    <header class="topbar">
        <div class="topbar-left"><a href="index.php"><img src="mab.png" alt="MAB" class="logo"></a></div>
        <div class="topbar-center">
            <a href="index.php" class="nav-icon" title="Início"><i class="fa-solid fa-house"></i></a>
            <?php if ($is_admin || in_array('clientes', $meus_acessos)): ?><a href="clientes.php" class="nav-icon i-clientes" title="Clientes"><i class="fa-solid fa-users"></i></a><?php endif; ?>
            <?php if ($is_admin || in_array('visitas', $meus_acessos)): ?><a href="visitas.php" class="nav-icon i-visitas" title="Visitas"><i class="fa-solid fa-calendar-check"></i></a><?php endif; ?>
            <?php if ($is_admin || in_array('servicos', $meus_acessos)): ?><a href="servicos.php" class="nav-icon i-servicos active" title="Serviços"><i class="fa-solid fa-screwdriver-wrench"></i></a><?php endif; ?>
            <?php if ($is_admin || in_array('obras', $meus_acessos)): ?><a href="obras.php" class="nav-icon i-obras" title="Obras"><i class="fa-solid fa-helmet-safety"></i></a><?php endif; ?>
            <?php if ($is_admin || in_array('relatorios', $meus_acessos)): ?><a href="relatorios.php" class="nav-icon i-relatorios" title="Relatórios de Obra"><i class="fa-solid fa-clipboard-list"></i></a><?php endif; ?>
            <?php if ($is_admin || in_array('orcamentos', $meus_acessos)): ?><a href="orcamentos.php" class="nav-icon i-orcamentos" title="Orçamentos"><i class="fa-solid fa-file-invoice"></i></a><?php endif; ?>
            <?php if ($is_admin || in_array('termos', $meus_acessos)): ?><a href="termos.php" class="nav-icon i-termos" title="Termos"><i class="fa-solid fa-file-signature"></i></a><?php endif; ?>
            <?php if ($is_admin || in_array('equipa', $meus_acessos)): ?><a href="utilizadores.php" class="nav-icon i-equipa" title="Equipa"><i class="fa-solid fa-id-card-clip"></i></a><?php endif; ?>
            <?php if ($is_admin || in_array('configuracoes', $meus_acessos)): ?><a href="configuracoes.php" class="nav-icon i-config" title="Configurações"><i class="fa-solid fa-gear"></i></a><?php endif; ?>
        </div>
        <div class="topbar-right">
            <a href="index.php" class="btn-back" title="Voltar"><i class="fa-solid fa-arrow-left"></i></a>
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
            <div class="alert <?= $tipo_mensagem === 'sucesso' ? 'alert-sucesso' : 'alert-erro' ?>"><?= htmlspecialchars($mensagem, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>

        <div class="header-box">
            <h1>Serviços</h1>
            <a href="servico_form.php" class="btn-novo"><i class="fa-solid fa-plus"></i> Novo</a>
        </div>

        <div class="filter-box">
            <form method="GET" id="formFiltros">
                <div style="display: flex; gap: 15px; align-items: flex-end; flex-wrap: wrap;">
                    <div class="filter-group">
                        <label>Mês / Período</label>
                        <select name="mes_filtro" onchange="document.getElementById('formFiltros').submit()">
                            <option value="todos" <?= $mes_selecionado === 'todos' ? 'selected' : '' ?>>-- Listar Todos --</option>
                            <?php foreach($lista_meses as $val => $label): ?>
                                <option value="<?= $val ?>" <?= $mes_selecionado === $val ? 'selected' : '' ?>><?= $label ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </form>
        </div>

        <?php if (count($todos_servicos) === 0): ?>
            <div class="card" style="text-align: center; padding: 40px; color: #7f8c8d;">Nenhum serviço registado para o período selecionado.</div>
        <?php else: ?>
            
            <?= renderizarTabela($srv_nao_faturados, 'Realizados', '#b32a2a') ?>
            <?= renderizarTabela($srv_faturados, 'Faturados', '#27ae60') ?>
            <?= renderizarTabela($srv_garantia, 'Em Garantia', '#e67e22') ?>

        <?php endif; ?>
    </div>

    <div class="modal" id="modalImprimir">
        <div class="modal-content modal-print-content">
            <div class="modal-print-header">
                <h3><i class="fa-solid fa-print"></i> Gerar Documento</h3>
                <button type="button" class="close-btn" onclick="fecharModalImprimir()">&times;</button>
            </div>
            <div class="modal-subtitle">Serviço Nº <span id="display_id_print"></span></div>
            <input type="hidden" id="input_print_id">
            
            <button type="button" class="btn-pdf-option" onclick="gerarPDF('cliente')">
                <i class="fa-solid fa-file-pdf"></i>
                <span>Serviço Cliente (Resumo)</span>
            </button>
            <?php if ($is_admin): ?>
            <button type="button" class="btn-pdf-option" onclick="gerarPDF('completa')">
                <i class="fa-solid fa-file-pdf"></i>
                <span>Serviço Gestão (Completo)</span>
            </button>
            <?php endif; ?>
        </div>
    </div>

    <div class="modal" id="modalApagar">
        <div class="modal-content">
            <h3 style="color:#b32a2a; margin-top:0;"><i class="fa-solid fa-triangle-exclamation"></i> Apagar Serviço</h3>
            <p>Tem a certeza que deseja apagar o serviço <strong>#<span id="display_id_apagar"></span></strong>?</p>
            <form method="POST">
                <input type="hidden" name="apagar_id" id="input_apagar_id">
                <input type="password" name="pass_seguranca" placeholder="Insira a Password de Segurança" required>
                <div class="modal-btns">
                    <button type="button" class="btn-cancelar" onclick="fecharModalApagar()">Cancelar</button>
                    <button type="submit" class="btn-confirmar">Apagar</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function abrirModalApagar(id) {
            document.getElementById('display_id_apagar').innerText = id;
            document.getElementById('input_apagar_id').value = id;
            document.getElementById('modalApagar').classList.add('show');
        }
        function fecharModalApagar() {
            document.getElementById('modalApagar').classList.remove('show');
        }
        function abrirModalImprimir(id) {
            document.getElementById('display_id_print').innerText = id;
            document.getElementById('input_print_id').value = id;
            document.getElementById('modalImprimir').classList.add('show');
        }
        function fecharModalImprimir() {
            document.getElementById('modalImprimir').classList.remove('show');
        }
        function gerarPDF(tipo) {
            const id = document.getElementById('input_print_id').value;
            window.open(`servico_pdf.php?id=${id}&tipo=${tipo}`, '_blank');
            fecharModalImprimir();
        }

        window.onclick = function(e) {
            if (e.target.id === 'modalApagar') fecharModalApagar();
            else if (e.target.id === 'modalImprimir') fecharModalImprimir();
        }
    </script>
</body>
</html>