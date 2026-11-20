<?php
session_start();
require_once 'db.php';

if (!isset($_SESSION['user_id'])) { header("Location: index.php"); exit; }

$perfil_user = $_SESSION['perfil'] ?? '';
$is_admin = ($perfil_user === 'admin');

$stmt_perm = $pdo->prepare("SELECT modulo FROM permissoes WHERE grupo = :perfil AND pode_aceder = 1");
$stmt_perm->execute(['perfil' => $perfil_user]);
$meus_acessos = $stmt_perm->fetchAll(PDO::FETCH_COLUMN);

if (!$is_admin && !in_array('termos', $meus_acessos)) {
    header("Location: index.php?erro=sem_permissao"); exit;
}

$nome_completo = $_SESSION['nome'] ?? 'Utilizador';
$primeiro_nome = explode(' ', trim($nome_completo))[0];

// Lógica de apagar (mesmo padrão dos outros)
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['btn_confirmar_apagar'])) {
    if ($is_admin) {
        $id_apagar = filter_input(INPUT_POST, 'id_apagar', FILTER_VALIDATE_INT);
        $pass_introduzida = $_POST['pass_seguranca'] ?? '';
        $stmt_pass = $pdo->prepare("SELECT valor FROM configuracoes WHERE chave = 'password_apagar' LIMIT 1");
        $stmt_pass->execute();
        $pass_config = $stmt_pass->fetchColumn();

        if ($pass_introduzida === $pass_config) {
            $pdo->prepare("DELETE FROM termos_analise WHERE id = ?")->execute([$id_apagar]);
            header("Location: termos_analise.php?sucesso=1"); exit;
        }
    }
}

// Query de listagem com LEFT JOIN para mostrar sempre, mesmo com falhas de ID
$sql = "SELECT t.*, o.nome as nome_obra, c.nome as nome_cliente 
        FROM termos_analise t
        LEFT JOIN obras o ON t.id_obra = o.id
        LEFT JOIN clientes c ON t.id_cliente = c.id
        ORDER BY t.data_vistoria DESC";
$termos = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="pt-PT">
<head>
    <meta charset="UTF-8">
    <title>Análise Técnica - Gestão MAB</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { font-family: system-ui, sans-serif; background: #f7f9fc; margin: 0; font-size: 13px; }
        .topbar { background: #fff; padding: 15px 40px; box-shadow: 0 4px 20px rgba(0,0,0,0.05); display: flex; justify-content: space-between; align-items: center; position: sticky; top: 0; z-index: 100; }
        .topbar-left .logo { height: 40px; }
        .topbar-right { display: flex; align-items: center; gap: 30px; }
        .user-menu { font-weight: 600; display: flex; align-items: center; gap: 8px; color: #2c3e50; }
        .btn-back { color: #576574; text-decoration: none; font-size: 18px; }
        .container { max-width: 1200px; margin: 30px auto; padding: 0 20px; }
        .page-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px; }
        .page-title { margin: 0; color: #2c3e50; font-size: 24px; font-weight: 800; }
        .btn-primary { background: #4a69bd; color: #fff; padding: 12px 20px; border-radius: 8px; border: none; cursor: pointer; font-weight: bold; text-decoration: none; display: inline-flex; align-items: center; gap: 8px; }
        .card { background: #fff; border-radius: 12px; border: 1px solid #e1e5ea; overflow: hidden; box-shadow: 0 4px 15px rgba(0,0,0,0.03); }
        table { width: 100%; border-collapse: collapse; }
        th { text-align: left; padding: 12px 15px; background: #f8f9fa; font-size: 10px; text-transform: uppercase; color: #7f8c8d; }
        td { padding: 12px 15px; border-bottom: 1px solid #f1f2f6; }
        .acoes { display: flex; gap: 10px; }
        .btn-icon { color: #576574; text-decoration: none; font-size: 16px; }
        .btn-icon:hover { color: #4a69bd; }
        .modal { display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.6); z-index:1000; align-items:center; justify-content:center; backdrop-filter: blur(2px); }
        .modal-content { background:#fff; padding:30px; border-radius:15px; width:90%; max-width:400px; text-align:center; }
    </style>
</head>
<body>
    <header class="topbar">
        <div class="topbar-left"><a href="index.php"><img src="mab.png" class="logo"></a></div>
        <div class="topbar-right">
            <a href="termos.php" class="btn-back"><i class="fa-solid fa-arrow-left"></i></a>
            <div class="user-menu"><?= htmlspecialchars($primeiro_nome) ?> <i class="fa-solid fa-chevron-down" style="font-size: 10px;"></i></div>
        </div>
    </header>

    <div class="container">
        <div class="page-header">
            <h1 class="page-title"><i class="fa-solid fa-laptop-medical" style="color:#4a69bd;"></i> Análise Técnica de Equipamentos</h1>
            <a href="termo_analise_form.php" class="btn-primary"><i class="fa-solid fa-plus-circle"></i> Novo Relatório</a>
        </div>

        <div class="card">
            <table>
                <thead>
                    <tr>
                        <th>Data</th>
                        <th>Obra / Cliente</th>
                        <th>Equipamento</th>
                        <th>Sinistro/Chamado</th>
                        <th class="text-center">Ações</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($termos)): ?>
                        <tr><td colspan="5" style="text-align: center; padding: 30px; color:#7f8c8d;">Nenhum relatório encontrado.</td></tr>
                    <?php else: ?>
                        <?php foreach($termos as $t): ?>
                        <tr>
                            <td><?= date('d/m/Y', strtotime($t['data_vistoria'])) ?></td>
                            <td>
                                <strong><?= htmlspecialchars($t['nome_obra'] ?: 'Obra não definida (Erro ID:0)') ?></strong><br>
                                <small><?= htmlspecialchars($t['nome_cliente'] ?: 'Cliente não definido') ?></small>
                            </td>
                            <td><?= htmlspecialchars($t['equipamento_resumo']) ?></td>
                            <td><?= htmlspecialchars($t['numero_chamado'] ?: '-') ?></td>
                            <td class="text-center">
                                <div class="acoes">
                                    <a href="termo_analise_pdf.php?id=<?= $t['id'] ?>" target="_blank" class="btn-icon" title="PDF"><i class="fa-solid fa-file-pdf"></i></a>
                                    <a href="termo_analise_form.php?id=<?= $t['id'] ?>" class="btn-icon" title="Editar"><i class="fa-solid fa-pen-to-square"></i></a>
                                    <?php if($is_admin): ?>
                                        <a href="#" onclick="abrirModalApagar(<?= $t['id'] ?>)" class="btn-icon" style="color:#e74c3c;"><i class="fa-solid fa-trash-can"></i></a>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</body>
</html>