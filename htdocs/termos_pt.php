<?php
// FORÇAR EXIBIÇÃO DE ERROS
ini_set('display_errors', 1);
error_reporting(E_ALL);

try {
    session_start();
    require_once 'db.php';

    if (!isset($_SESSION['user_id'])) { header("Location: index.php"); exit; }

    if (file_exists('termo_pt_937_lib.php')) { require_once 'termo_pt_937_lib.php'; m937_migrar($pdo); }

    if (file_exists('functions.php')) { require_once 'functions.php'; } 
    else { 
        function registrarLog($pdo, $acao, $modulo) { return true; } 
        function gerar_csrf_token() { return ''; }
        function validar_csrf_token($t) { return true; }
    }

    $perfil_user = $_SESSION['perfil'] ?? '';
    $is_admin = in_array($perfil_user, ['admin', 'administrativo']);

    $stmt_perm = $pdo->prepare("SELECT modulo FROM permissoes WHERE grupo = :perfil AND pode_aceder = 1");
    $stmt_perm->execute(['perfil' => $perfil_user]);
    $meus_acessos = $stmt_perm->fetchAll(PDO::FETCH_COLUMN);

    // Permissão Específica
    if (!$is_admin && !in_array('termos', $meus_acessos) && !in_array('termos_pt', $meus_acessos)) {
        header("Location: index.php?erro=sem_permissao"); exit;
    }

    $nome_completo = $_SESSION['nome'] ?? 'Utilizador';
    $primeiro_nome = explode(' ', trim($nome_completo))[0];
    $csrf_token = gerar_csrf_token();

    $mensagem = ''; $tipo_mensagem = '';
    if (isset($_GET['sucesso'])) {
        $mensagem = $_GET['sucesso'] == 'apagado' ? "Relatório de PT removido com sucesso." : "Operação realizada com sucesso!";
        $tipo_mensagem = "sucesso";
    }

    // --- LÓGICA PARA APAGAR ---
    if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['btn_confirmar_apagar'])) { 
        if (!validar_csrf_token($_POST['csrf_token'] ?? '')) { die("CSRF Inválido."); }
        
        $id_apagar = filter_input(INPUT_POST, 'id_apagar', FILTER_VALIDATE_INT);
        $pass_introduzida = $_POST['pass_seguranca'] ?? '';

        $stmt_pass = $pdo->prepare("SELECT valor FROM configuracoes WHERE chave = 'password_apagar' LIMIT 1"); 
        $stmt_pass->execute(); 
        $pass_config = $stmt_pass->fetchColumn();

        if ($pass_introduzida !== $pass_config || empty($pass_introduzida)) { 
            $mensagem = "Password de segurança incorreta."; $tipo_mensagem = "erro"; 
        } else { 
            try {
                $stmt_ref = $pdo->prepare("SELECT referencia_pt FROM termos_pt WHERE id = ?");
                $stmt_ref->execute([$id_apagar]);
                $ref = $stmt_ref->fetchColumn() ?: $id_apagar;

                $pdo->prepare("DELETE FROM termos_pt WHERE id = ?")->execute([$id_apagar]); 
                registrarLog($pdo, "Apagou Relatório PT ($ref)", "Termos PT"); 
                header("Location: termos_pt.php?sucesso=apagado"); exit; 
            } catch (Exception $e) {
                $mensagem = "Erro ao apagar: " . $e->getMessage(); $tipo_mensagem = "erro"; 
            }
        } 
    }

    // --- CARREGAR DADOS ---
    $sql = "SELECT t.*, c.nome as nome_cliente 
            FROM termos_pt t
            LEFT JOIN clientes c ON t.id_cliente = c.id
            ORDER BY t.data_emissao DESC, t.id DESC";
    $relatorios = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

} catch (Throwable $e) {
    die("<div style='padding:20px; color:red;'>Erro Fatal: " . $e->getMessage() . "</div>");
}
?>
<!DOCTYPE html>
<html lang="pt-PT">
<head>
    <meta charset="UTF-8">
    <title>Postos de Transformação - MAB</title>
    <link rel="icon" type="image/png" href="mab.png">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { font-family: system-ui, sans-serif; background: #f7f9fc; margin: 0; font-size: 13px; padding-bottom: 50px; }
        * { box-sizing: border-box; }
        
        .topbar { background-color: #fff; padding: 15px 40px; box-shadow: 0 4px 20px rgba(0,0,0,0.05); display: flex; justify-content: space-between; align-items: center; position: sticky; top: 0; z-index: 100; border-bottom: 3px solid #34495e;}
        .topbar-left .logo { height: 40px; }
        .topbar-right { display: flex; align-items: center; gap: 18px; }
        .btn-voltar { width: 40px; height: 40px; border-radius: 50%; background: #fff; border: 1px solid #e8ecf1; color: #576574; display: inline-flex; align-items: center; justify-content: center; text-decoration: none; font-size: 16px; transition: all 0.2s ease; box-shadow: 0 2px 6px rgba(0,0,0,0.04); }
        .btn-voltar:hover { background: #eef2f6; color: #34495e; transform: translateX(-2px); }
        
        .user-menu { position: relative; display: inline-block;}
        .user-btn { background: none; border: none; font-size: 15px; font-weight: 600; color: #2c3e50; cursor: pointer; display: flex; align-items: center; gap: 8px; }
        .user-avatar { background: #e74c3c; color: white; width: 28px; height: 28px; border-radius: 50%; display: flex; justify-content: center; align-items: center; font-size: 14px; border: 2px solid #fadbd8; }
        .dropdown-content { display: none; position: absolute; right: 0; top: 100%; margin-top: 10px; background: #fff; min-width: 180px; box-shadow: 0 10px 30px rgba(0,0,0,0.1); border-radius: 8px; overflow: hidden; border: 1px solid #f1f2f6; z-index:200;}
        .dropdown-content a { color: #576574; padding: 12px 20px; text-decoration: none; display: block; font-size: 14px; }
        .dropdown-content a:hover { background: #fdf2f2; color: #b32a2a; }
        .user-menu.aberto .dropdown-content { display: block; }

        .container { max-width: 1400px; margin: 30px auto; padding: 0 20px; }
        .page-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px; }
        .page-title { margin: 0; color: #2c3e50; font-size: 22px; font-weight: 800; display: flex; align-items: center; gap: 10px; }
        
        .btn-primary { background: #34495e; color: #fff; padding: 12px 20px; border-radius: 8px; border: none; cursor: pointer; font-weight: bold; text-decoration: none; display: inline-flex; align-items: center; gap: 8px; transition: 0.2s;}
        .btn-primary:hover { background: #2c3e50; }

        .card { background: #fff; border-radius: 12px; border: 1px solid #e1e5ea; overflow: hidden; box-shadow: 0 4px 15px rgba(0,0,0,0.03); }
        table { width: 100%; border-collapse: collapse; min-width: 900px; }
        th { text-align: left; padding: 15px; background: #f8f9fa; font-size: 11px; text-transform: uppercase; color: #7f8c8d; border-bottom: 1px solid #eee; }
        td { padding: 15px; border-bottom: 1px solid #f1f2f6; vertical-align: middle; }
        tbody tr:hover { background-color: #fcfcfc; }

        .btn-acao { background: #f1f2f6; border: none; cursor: pointer; font-size: 14px; color: #576574; width: 32px; height: 32px; border-radius: 6px; display: inline-flex; align-items: center; justify-content: center; text-decoration: none; margin-right: 4px; transition:0.2s;}
        .btn-acao:hover { background: #e1e5ea; color: #2c3e50; }
        .btn-imprimir { color: #3498db; background: #f0f8ff; }
        .btn-imprimir:hover { color: #2980b9; background: #d6eaf8; }
        .btn-duplicar { color: #27ae60; background: #eafaf1; }
        .btn-duplicar:hover { background: #d5f5e3; color: #1e8449; }
        .btn-937 { color: #d35400; background: #fef5e7; position: relative; }
        .btn-937:hover { background: #fdebd0; color: #ba4a00; }
        .btn-937 .ponto { position: absolute; top: -3px; right: -3px; width: 10px; height: 10px; border-radius: 50%; background: #27ae60; border: 2px solid #fff; }
        .btn-del { color: #e74c3c; background: #fdeded; }
        .btn-del:hover { background: #fadbd8; color: #c0392b; }
        
        .id-badge { font-weight: 700; color: #34495e; background: #eaeff5; padding: 4px 8px; border-radius: 6px; border: 1px solid #d4deea; font-size: 12px; }
        .status-badge { padding: 4px 8px; border-radius: 4px; font-size: 11px; font-weight: bold; }
        .status-ok { background: #eafaf1; color: #27ae60; }
        .status-nok { background: #fdeded; color: #c0392b; }
        .status-desiste { background: #fef5e7; color: #d35400; }

        .alert { padding: 15px; border-radius: 8px; margin-bottom: 20px; text-align: center; font-weight: 600; }
        .alert-sucesso { background: #eafaf1; color: #27ae60; border: 1px solid #d5f5e3; }
        .alert-erro { background: #fdeded; color: #c0392b; border: 1px solid #f9cccc; }

        /* MODAIS */
        .modal { display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.6); z-index:10000; align-items:center; justify-content:center; backdrop-filter: blur(2px); }
        .modal-content { background:#fff; padding:30px; border-radius:15px; width:90%; max-width:400px; text-align:center; box-shadow: 0 20px 50px rgba(0,0,0,0.2); }
        .modal-content input[type="password"] { width: 100%; padding: 12px; border: 1px solid #dcdde1; border-radius: 8px; margin: 15px 0; outline: none; }
        .modal-btns { display: flex; gap: 10px; margin-top: 15px;}
        .modal-btns button { flex: 1; padding: 12px; border: none; border-radius: 8px; font-weight: 600; cursor: pointer; transition:0.2s;}
        .btn-cancelar { background: #f1f2f6; color: #576574; }

        @media (max-width: 768px) {
            .topbar { padding: 10px 15px; }
            .container { margin: 18px auto; padding: 0 12px; }
            .page-header { flex-direction: column; align-items: stretch; gap: 12px; }
            .page-title { font-size: 18px; }
            .btn-primary { justify-content: center; }
        }
    </style>
</head>
<body>

    <header class="topbar">
        <div class="topbar-left"><a href="index.php"><img src="mab.png" alt="MAB" class="logo"></a></div>
        <div class="topbar-right">
            <a href="termos.php" class="btn-voltar" title="Voltar" aria-label="Voltar"><i class="fa-solid fa-arrow-left"></i></a>
            <div class="user-menu" id="userMenu">
                <button type="button" class="user-btn" onclick="event.stopPropagation(); document.getElementById('userMenu').classList.toggle('aberto');">
                    <div class="user-avatar"><i class="fa-solid fa-user"></i></div>
                    <?= htmlspecialchars($primeiro_nome) ?> <i class="fa-solid fa-chevron-down" style="font-size:10px;"></i>
                </button>
                <div class="dropdown-content">
                    <a href="perfil.php"><i class="fa-solid fa-id-badge"></i> O meu perfil</a>
                    <a href="index.php?logout=1" style="color:#e74c3c;"><i class="fa-solid fa-right-from-bracket"></i> Sair</a>
                </div>
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
            <h1 class="page-title"><i class="fa-solid fa-industry" style="color:#34495e;"></i> Relatórios Postos de Transformação</h1>
            <a href="termo_pt_form.php" class="btn-primary"><i class="fa-solid fa-plus-circle"></i> Novo Relatório</a>
        </div>

        <div class="card" style="overflow-x:auto;">
            <table>
                <thead>
                    <tr>
                        <th width="140">Referência PT</th>
                        <th width="100">Data Inspeção</th>
                        <th>Cliente / Entidade</th>
                        <th>Período</th>
                        <th>Condição</th>
                        <th width="220" style="text-align:center;">Ações</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($relatorios)): ?>
                        <tr><td colspan="6" style="text-align:center; padding: 40px; color: #7f8c8d;">Nenhum relatório de Posto de Transformação encontrado.</td></tr>
                    <?php else: ?>
                        <?php foreach($relatorios as $pt): 
                            $badge_class = 'status-ok';
                            $badge_text = 'Boas Condições';
                            if ($pt['condicao_geral'] == 'deficientes') { $badge_class = 'status-nok'; $badge_text = 'Deficiente'; }
                            if ($pt['condicao_geral'] == 'desistencia') { $badge_class = 'status-desiste'; $badge_text = 'Desistência Resp.'; }
                        ?>
                        <tr>
                            <td><span class="id-badge"><?= htmlspecialchars($pt['referencia_pt'] ?: 'S/ Ref') ?></span></td>
                            <td><i class="fa-regular fa-calendar" style="color:#bdc3c7;"></i> <?= $pt['data_inspecao'] ? date('d/m/Y', strtotime($pt['data_inspecao'])) : 'N/D' ?></td>
                            <td><strong style="color:#2c3e50;"><?= htmlspecialchars($pt['nome_cliente'] ?? 'Cliente Eliminado') ?></strong></td>
                            <td style="color:#576574;"><?= $pt['periodo_inicio'] ? date('m/Y', strtotime($pt['periodo_inicio'])) : '' ?> a <?= $pt['periodo_fim'] ? date('m/Y', strtotime($pt['periodo_fim'])) : '' ?></td>
                            <td><span class="status-badge <?= $badge_class ?>"><?= $badge_text ?></span></td>
                            <td style="text-align:center; white-space:nowrap;">
                                <a href="termo_pt_pdf.php?id=<?= $pt['id'] ?>" target="_blank" class="btn-acao btn-imprimir" title="Imprimir PDF"><i class="fa-solid fa-print"></i></a>
                                <?php $tem937 = !empty($pt['modelo937_json']) && !empty((json_decode((string)$pt['modelo937_json'], true) ?: [])['paginas']); ?>
                                <a href="termo_pt_937.php?id=<?= $pt['id'] ?>" class="btn-acao btn-937" title="<?= $tem937 ? 'Modelo 937 INCM (impresso carregado)' : 'Modelo 937 INCM (carregar impresso)' ?>"><i class="fa-solid fa-file-contract"></i><?php if ($tem937): ?><span class="ponto"></span><?php endif; ?></a>
                                <a href="termo_pt_form.php?id=<?= $pt['id'] ?>" class="btn-acao" title="Editar Relatório"><i class="fa-solid fa-pen-to-square"></i></a>
                                <a href="termo_pt_form.php?duplicar=<?= $pt['id'] ?>" class="btn-acao btn-duplicar" title="Duplicar Relatório para Nova Data"><i class="fa-solid fa-copy"></i></a>
                                <?php if($is_admin): ?>
                                    <button type="button" onclick="abrirModalApagar(<?= $pt['id'] ?>, '<?= htmlspecialchars($pt['referencia_pt'] ?: 'Sem Ref') ?>')" class="btn-acao btn-del" title="Eliminar"><i class="fa-solid fa-trash-can"></i></button>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php if ($is_admin): ?>
    <div class="modal" id="modalApagar">
        <div class="modal-content">
            <h3 style="color:#e74c3c; margin-top:0;"><i class="fa-solid fa-triangle-exclamation"></i> Eliminar Relatório PT</h3>
            <p>Deseja apagar o relatório:<br><strong id="txt_modal" style="color: #2c3e50; font-size: 16px; margin-top: 10px; display: inline-block;"></strong>?</p>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                <input type="hidden" name="id_apagar" id="id_apagar">
                <input type="password" name="pass_seguranca" required placeholder="Password de Segurança">
                <div class="modal-btns">
                    <button type="button" class="btn-cancelar" onclick="document.getElementById('modalApagar').style.display='none'">Cancelar</button>
                    <button type="submit" name="btn_confirmar_apagar" style="background:#e74c3c; color:#fff;">Eliminar Definitivamente</button>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <script>
        document.addEventListener('click', () => { const m = document.getElementById('userMenu'); if (m) m.classList.remove('aberto'); });
        function abrirModalApagar(id, ref) {
            document.getElementById('id_apagar').value = id;
            document.getElementById('txt_modal').innerText = ref;
            document.getElementById('modalApagar').style.display = 'flex';
            document.querySelector('#modalApagar input[type="password"]').value = '';
            document.querySelector('#modalApagar input[type="password"]').focus();
        }
    </script>
</body>
</html>