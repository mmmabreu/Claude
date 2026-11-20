<?php
session_start();
require_once 'db.php';

// Validação de sessão
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

// Buscar Permissões
$stmt_perm = $pdo->prepare("SELECT modulo FROM permissoes WHERE grupo = :perfil AND pode_aceder = 1");
$stmt_perm->execute(['perfil' => $perfil_user]);
$meus_acessos = $stmt_perm->fetchAll(PDO::FETCH_COLUMN);

if (!$is_admin && !in_array('termos', $meus_acessos) && !in_array('termos_knx', $meus_acessos)) {
    header("Location: index.php?erro=sem_permissao");
    exit;
}

$nome_completo = $_SESSION['nome'] ?? 'Utilizador';
$primeiro_nome = explode(' ', trim($nome_completo))[0];

$mensagem = '';
$tipo_mensagem = '';

// --- APAGAR REGISTO ---
if (isset($_GET['apagar']) && is_numeric($_GET['apagar'])) {
    try {
        $id_apagar = (int)$_GET['apagar'];
        
        $stmt_info = $pdo->prepare("SELECT numero_relatorio FROM termos_knx WHERE id = ?");
        $stmt_info->execute([$id_apagar]);
        $num_relatorio = $stmt_info->fetchColumn();

        if ($num_relatorio) {
            $stmt_del = $pdo->prepare("DELETE FROM termos_knx WHERE id = ?");
            $stmt_del->execute([$id_apagar]);
            registrarLog($pdo, "Apagou Registo KNX #" . $num_relatorio, "Termos KNX");
            
            $mensagem = "Registo apagado com sucesso!";
            $tipo_mensagem = "sucesso";
        }
    } catch (Exception $e) {
        $mensagem = "Erro ao apagar: " . $e->getMessage();
        $tipo_mensagem = "erro";
    }
}

if (isset($_GET['sucesso']) && $_GET['sucesso'] == 1) {
    $mensagem = "Registo KNX guardado com sucesso!";
    $tipo_mensagem = "sucesso";
}

// --- BUSCAR DADOS DA TABELA ---
try {
    $sql = "SELECT t.*, c.nome as cliente_nome 
            FROM termos_knx t 
            LEFT JOIN clientes c ON t.id_cliente = c.id 
            ORDER BY t.numero_relatorio DESC, t.id DESC";
    $termos = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $termos = [];
    $mensagem = "Erro ao carregar dados: " . $e->getMessage();
    $tipo_mensagem = "erro";
}

?>
<!DOCTYPE html>
<html lang="pt-PT">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Termos KNX - Gestão MAB</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { font-family: system-ui, -apple-system, sans-serif; background: #f7f9fc; margin: 0; color: #2c3e50; font-size: 14px; padding-bottom: 50px;}
        * { box-sizing: border-box; }
        
        .topbar { background-color: #fff; padding: 10px 40px; box-shadow: 0 2px 15px rgba(0,0,0,0.05); display: flex; justify-content: space-between; align-items: center; position: sticky; top: 0; z-index: 100; }
        .topbar-left .logo { height: 35px; }
        .topbar-center { display: flex; align-items: center; gap: 20px; }
        .nav-icon { color: #95a5a6; font-size: 20px; text-decoration: none; transition: 0.2s; padding: 5px; border-radius: 6px; }
        .nav-icon:hover { background: #f1f2f6; color: #34495e; }
        .nav-icon.active { color: #9b59b6; }

        .topbar-right { display: flex; align-items: center; gap: 20px; }
        .btn-back { color: #576574; text-decoration: none; font-size: 20px; transition: color 0.2s; }
        .btn-back:hover { color: #9b59b6; }
        
        .user-menu { position: relative; }
        .user-btn { background: #fdfdfd; border: 1px solid #f1f2f6; border-radius: 30px; font-size: 14px; font-weight: 600; color: #2c3e50; cursor: pointer; display: flex; align-items: center; gap: 10px; padding: 6px 15px 6px 6px; transition: 0.2s; }
        .user-avatar { background: #e74c3c; color: white; width: 28px; height: 28px; border-radius: 50%; display: flex; justify-content: center; align-items: center; font-size: 14px; border: 2px solid #fadbd8; }
        
        .container { max-width: 1200px; margin: 30px auto; padding: 0 20px; }
        
        .page-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px; flex-wrap: wrap; gap: 15px; }
        .page-title { margin: 0; font-size: 24px; font-weight: 800; display: flex; align-items: center; gap: 10px; }
        .page-title i { color: #9b59b6; }
        
        .header-actions { display: flex; gap: 15px; align-items: center; }
        .search-box { position: relative; }
        .search-box input { padding: 10px 15px 10px 35px; border: 1px solid #dcdde1; border-radius: 30px; font-size: 13px; width: 250px; outline: none; transition: 0.2s; }
        .search-box input:focus { border-color: #9b59b6; box-shadow: 0 0 0 3px rgba(155,89,182,0.1); }
        .search-box i { position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: #7f8c8d; }
        
        .btn-novo { background: #34495e; color: #fff; padding: 10px 20px; border-radius: 30px; text-decoration: none; font-weight: 600; font-size: 13px; display: inline-flex; align-items: center; gap: 8px; transition: 0.2s; box-shadow: 0 2px 5px rgba(0,0,0,0.1);}
        .btn-novo:hover { background: #2c3e50; transform: translateY(-1px); box-shadow: 0 4px 8px rgba(0,0,0,0.15);}

        .table-container { background: #fff; border-radius: 12px; padding: 10px; box-shadow: 0 4px 15px rgba(0,0,0,0.02); border: 1px solid #e1e5ea; overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; min-width: 800px; }
        th { background: #f8f9fa; padding: 15px; text-align: left; font-size: 11px; color: #7f8c8d; text-transform: uppercase; border-bottom: 2px solid #e1e5ea; white-space: nowrap;}
        td { padding: 15px; border-bottom: 1px solid #f1f2f6; vertical-align: middle; color: #34495e; font-size: 13px; }
        tr:hover td { background-color: #fdfdfd; }
        tr:last-child td { border-bottom: none; }
        
        .ref-badge { background: #f3e5f5; color: #8e44ad; padding: 4px 8px; border-radius: 6px; font-weight: bold; font-size: 12px; border: 1px solid #e1bee7;}
        
        .actions { display: flex; gap: 6px; justify-content: flex-end;}
        .btn-acao { background: #f1f2f6; color: #576574; border: none; width: 32px; height: 32px; border-radius: 6px; cursor: pointer; text-decoration: none; display: inline-flex; align-items: center; justify-content: center; transition: 0.2s; font-size: 14px;}
        .btn-acao:hover { background: #e1e5ea; color: #2c3e50; }
        .btn-acao.pdf { color: #d35400; background: #fdf2e9; }
        .btn-acao.pdf:hover { background: #fadbd8; color: #c0392b; }
        .btn-acao.del { color: #e74c3c; background: #fff0f0; }
        .btn-acao.del:hover { background: #fadbd8; color: #c0392b; }

        .alert { padding: 15px; border-radius: 8px; margin-bottom: 20px; font-weight: bold; text-align: center; }
        .alert-sucesso { background: #eafaf1; color: #27ae60; border: 1px solid #d5f5e3; }
        .alert-erro { background: #fdeded; color: #e74c3c; border: 1px solid #fadbd8; }
        
        .empty-state { text-align: center; padding: 50px 20px; color: #7f8c8d; }
        .empty-state i { font-size: 40px; color: #bdc3c7; margin-bottom: 15px; }

        @media (max-width: 768px) {
            .topbar { padding: 10px 15px; }
            .topbar-center { display: none; }
            .page-header { flex-direction: column; align-items: flex-start; }
            .header-actions { width: 100%; justify-content: space-between; }
            .search-box input { width: 100%; }
        }
    </style>
</head>
<body>
    <header class="topbar">
        <div class="topbar-left"><a href="index.php"><img src="mab.png" alt="MAB" class="logo"></a></div>
        <div class="topbar-center">
            <a href="index.php" class="nav-icon" title="Início"><i class="fa-solid fa-house"></i></a>
            <?php if ($is_admin || in_array('clientes', $meus_acessos)): ?><a href="clientes.php" class="nav-icon i-clientes"><i class="fa-solid fa-users"></i></a><?php endif; ?>
            <?php if ($is_admin || in_array('visitas', $meus_acessos)): ?><a href="visitas.php" class="nav-icon i-visitas"><i class="fa-solid fa-calendar-check"></i></a><?php endif; ?>
            <?php if ($is_admin || in_array('servicos', $meus_acessos)): ?><a href="servicos.php" class="nav-icon i-servicos"><i class="fa-solid fa-screwdriver-wrench"></i></a><?php endif; ?>
            <?php if ($is_admin || in_array('obras', $meus_acessos)): ?><a href="obras.php" class="nav-icon i-obras"><i class="fa-solid fa-helmet-safety"></i></a><?php endif; ?>
            <?php if ($is_admin || in_array('orcamentos', $meus_acessos)): ?><a href="orcamentos.php" class="nav-icon i-orcamentos"><i class="fa-solid fa-file-invoice"></i></a><?php endif; ?>
            <?php if ($is_admin || in_array('termos', $meus_acessos)): ?><a href="termos.php" class="nav-icon i-termos active"><i class="fa-solid fa-file-signature"></i></a><?php endif; ?>
        </div>
        <div class="topbar-right">
            <a href="termos.php" class="btn-back" title="Voltar aos Termos"><i class="fa-solid fa-arrow-left"></i></a>
            <div class="user-menu">
                <button class="user-btn">
                    <div class="user-avatar"><i class="fa-solid fa-user"></i></div>
                    <?= htmlspecialchars($primeiro_nome) ?>
                </button>
            </div>
        </div>
    </header>

    <div class="container">
        
        <?php if ($mensagem): ?>
            <div class="alert <?= $tipo_mensagem === 'sucesso' ? 'alert-sucesso' : 'alert-erro' ?>">
                <?= htmlspecialchars($mensagem) ?>
            </div>
        <?php endif; ?>

        <div class="page-header">
            <h1 class="page-title"><i class="fa-solid fa-microchip"></i> Registos de Domótica (KNX)</h1>
            <div class="header-actions">
                <div class="search-box">
                    <i class="fa-solid fa-search"></i>
                    <input type="text" id="searchBox" placeholder="Pesquisar relatórios..." onkeyup="pesquisar()">
                </div>
                <a href="termos_knx_form.php" class="btn-novo"><i class="fa-solid fa-plus"></i> Novo Registo</a>
            </div>
        </div>

        <div class="table-container">
            <?php if (count($termos) > 0): ?>
                <table>
                    <thead>
                        <tr>
                            <th>Ref. / Nº</th>
                            <th>Data</th>
                            <th>Cliente / Entidade</th>
                            <th>Técnico Responsável</th>
                            <th style="text-align: right;">Ações</th>
                        </tr>
                    </thead>
                    <tbody id="tabelaDados">
                        <?php foreach ($termos as $t): 
                            $num_formatado = str_pad($t['numero_relatorio'], 5, "0", STR_PAD_LEFT);
                            $data_formatada = date('d/m/Y', strtotime($t['data_inspecao']));
                        ?>
                            <tr>
                                <td><span class="ref-badge">#<?= htmlspecialchars($num_formatado) ?></span></td>
                                <td><strong><?= $data_formatada ?></strong></td>
                                <td><?= htmlspecialchars($t['cliente_nome'] ?: 'Sem Cliente') ?></td>
                                <td><?= htmlspecialchars($t['tecnico_nome']) ?></td>
                                <td>
                                    <div class="actions">
                                        <a href="termos_knx_pdf.php?id=<?= $t['id'] ?>" target="_blank" class="btn-acao pdf" title="Ver / Imprimir PDF"><i class="fa-solid fa-file-pdf"></i></a>
                                        <a href="termos_knx_form.php?id=<?= $t['id'] ?>" class="btn-acao" title="Editar Registo"><i class="fa-solid fa-pen"></i></a>
                                        <a href="termos_knx_form.php?id=<?= $t['id'] ?>&duplicar=1" class="btn-acao" title="Duplicar Registo"><i class="fa-solid fa-copy"></i></a>
                                        <button type="button" class="btn-acao del" onclick="confirmarApagar(<?= $t['id'] ?>, '<?= $num_formatado ?>')" title="Apagar Registo"><i class="fa-solid fa-trash-can"></i></button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <div class="empty-state">
                    <i class="fa-solid fa-folder-open"></i>
                    <h3>Ainda não existem registos KNX</h3>
                    <p>Clique no botão "Novo Registo" para criar o primeiro termo de domótica.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
        function pesquisar() {
            let input = document.getElementById('searchBox').value.toLowerCase();
            let rows = document.querySelectorAll('#tabelaDados tr');
            
            rows.forEach(row => {
                let text = row.innerText.toLowerCase();
                row.style.display = text.includes(input) ? '' : 'none';
            });
        }

        function confirmarApagar(id, ref) {
            if (confirm(`Tem a certeza absoluta que deseja APAGAR o relatório KNX #${ref}?\nEsta ação é irreversível e não poderá ser desfeita.`)) {
                window.location.href = `termos_knx.php?apagar=${id}`;
            }
        }
    </script>
</body>
</html>