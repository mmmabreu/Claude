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

if (!$is_admin && !in_array('clientes', $meus_acessos)) {
    header("Location: index.php?erro=sem_permissao");
    exit;
}

// REGRA DE OURO 3: 1º Nome no Avatar
$nome_completo = $_SESSION['nome'] ?? 'Utilizador';
$primeiro_nome = explode(' ', trim($nome_completo))[0];

$mensagem = '';
$tipo_mensagem = '';

if (isset($_GET['sucesso']) && $_GET['sucesso'] == 'apagado') {
    $mensagem = "Cliente removido com sucesso.";
    $tipo_mensagem = "sucesso";
}

// --- LÓGICA PARA APAGAR CLIENTE ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['btn_confirmar_apagar'])) {
    
    if (!$is_admin) {
        $mensagem = "Erro: Não tem permissão para apagar clientes.";
        $tipo_mensagem = "erro";
    } else {
        $id_cliente = filter_input(INPUT_POST, 'id_cliente_apagar', FILTER_VALIDATE_INT);
        $pass_introduzida = $_POST['pass_seguranca'] ?? '';

        $stmt_pass = $pdo->prepare("SELECT valor FROM configuracoes WHERE chave = 'password_apagar' LIMIT 1");
        $stmt_pass->execute();
        $pass_config = $stmt_pass->fetchColumn();

        if ($pass_introduzida !== $pass_config || empty($pass_introduzida)) {
            $mensagem = "Password de segurança incorreta. Operação cancelada.";
            $tipo_mensagem = "erro";
        } else {
            // Verificar se o cliente está referenciado (Ex: orçamentos ou obras)
            $tabelas_verificar = ['orcamentos', 'obras'];
            $referenciado = false;

            foreach ($tabelas_verificar as $tabela) {
                try {
                    $stmt_ref = $pdo->prepare("SELECT COUNT(*) FROM $tabela WHERE id_cliente = :id");
                    $stmt_ref->execute(['id' => $id_cliente]);
                    if ($stmt_ref->fetchColumn() > 0) {
                        $referenciado = true;
                        break;
                    }
                } catch (PDOException $e) {}
            }

            if ($referenciado) {
                $mensagem = "Não é possível apagar este cliente pois possui orçamentos ou obras associadas.";
                $tipo_mensagem = "erro";
            } else {
                try {
                    $stmt_del = $pdo->prepare("DELETE FROM clientes WHERE id = :id");
                    $stmt_del->execute(['id' => $id_cliente]);
                    registrarLog($pdo, "Apagou cliente ID: $id_cliente", "Clientes");
                    header("Location: clientes.php?sucesso=apagado");
                    exit;
                } catch (Exception $e) {
                    $mensagem = "Erro ao apagar: " . $e->getMessage();
                    $tipo_mensagem = "erro";
                }
            }
        }
    }
}

// Buscar apenas os clientes ATIVOS, separados por tipo
$stmt_obras = $pdo->query("SELECT * FROM clientes WHERE ativo = 1 AND tipo_cliente = 'Obra' ORDER BY id ASC");
$clientes_obra = $stmt_obras->fetchAll(PDO::FETCH_ASSOC);

$stmt_pontual = $pdo->query("SELECT * FROM clientes WHERE ativo = 1 AND tipo_cliente = 'Pontual' ORDER BY id ASC");
$clientes_pontual = $stmt_pontual->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="pt-PT">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Clientes - Gestão MAB</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { font-family: system-ui, -apple-system, sans-serif; background-color: #f7f9fc; margin: 0; color: #333; padding-bottom: 50px; }
        * { box-sizing: border-box; }
        
        /* TOPBAR MINIMALISTA REGRA DE OURO */
        .topbar { background-color: #fff; padding: 15px 40px; box-shadow: 0 4px 20px rgba(0,0,0,0.05); display: flex; justify-content: space-between; align-items: center; position: sticky; top: 0; z-index: 100; }
        .topbar-left .logo { height: 40px; }
        .topbar-center { display: flex; align-items: center; gap: 20px; flex: 1;}
        .topbar-right { display: flex; align-items: center; gap: 30px; }
        
        .btn-back { color: #576574; text-decoration: none; font-weight: 500; display: flex; align-items: center; gap: 6px; font-size: 15px;}
        .btn-back:hover { color: #3498db; } 
        
        .user-menu { position: relative; display: inline-block;}
        .user-btn { background: none; border: none; font-size: 15px; font-weight: 600; color: #2c3e50; cursor: pointer; display: flex; align-items: center; gap: 8px; }
        .user-avatar { background: #e74c3c; color: white; width: 28px; height: 28px; border-radius: 50%; display: flex; justify-content: center; align-items: center; font-size: 14px; border: 2px solid #fadbd8; }
        
        .dropdown-content { display: none; position: absolute; right: 0; top: 100%; margin-top: 10px; background: #fff; min-width: 180px; box-shadow: 0 10px 30px rgba(0,0,0,0.1); border-radius: 8px; overflow: hidden; border: 1px solid #f1f2f6; z-index:200;}
        .dropdown-content a { color: #576574; padding: 12px 20px; text-decoration: none; display: block; font-size: 14px; }
        .dropdown-content a:hover { background: #fdf2f2; color: #3498db; }
        .user-menu:hover .dropdown-content { display: block; }

        .container { max-width: 1300px; margin: 30px auto; padding: 0 15px; }
        
        .page-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px; flex-wrap: wrap; gap: 15px; }
        .page-title { margin: 0; color: #2c3e50; font-size: 24px; font-weight: 800; display: flex; align-items: center; gap: 10px; }
        .page-title i { color: #3498db; }
        
        .btn-primary { background: #3498db; color: #fff; padding: 12px 24px; border-radius: 8px; text-decoration: none; font-weight: 700; cursor: pointer; border: none; display: inline-flex; align-items: center; gap: 8px; transition: 0.2s;}
        .btn-primary:hover { background: #2980b9; transform: translateY(-2px); }

        .alert { padding: 15px; border-radius: 8px; margin-bottom: 20px; text-align: center; font-weight: 600; }
        .alert-erro { background: #fdeded; color: #e74c3c; border: 1px solid #f9cccc; }
        .alert-sucesso { background: #ebf5fb; color: #3498db; border: 1px solid #d6eaf8; }

        /* TABS (SEPARADORES) */
        .tabs { display: flex; gap: 10px; margin-bottom: 20px; border-bottom: 2px solid #e1e5ea; padding-bottom: 0px; }
        .tab-btn { background: transparent; color: #7f8c8d; font-weight: 700; font-size: 15px; padding: 12px 20px; border: none; cursor: pointer; border-bottom: 3px solid transparent; margin-bottom: -2px; transition: 0.2s; display: flex; align-items: center; gap: 8px; }
        .tab-btn:hover { color: #2c3e50; }
        .tab-btn.active { color: #3498db; border-bottom-color: #3498db; }

        .tab-content { display: none; animation: fadeIn 0.3s ease; }
        .tab-content.active { display: block; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(5px); } to { opacity: 1; transform: translateY(0); } }

        .card { background: #fff; border-radius: 12px; box-shadow: 0 4px 15px rgba(0,0,0,0.03); border: 1px solid #e1e5ea; overflow: hidden; }
        
        table { width: 100%; border-collapse: collapse; }
        th { padding: 15px 20px; background: #f8f9fa; color: #576574; font-size: 11px; text-transform: uppercase; text-align: left; border-bottom: 2px solid #e1e5ea; }
        td { padding: 15px 20px; border-bottom: 1px solid #f1f2f6; font-size: 14px; color: #2c3e50; vertical-align: middle; }
        tr:hover { background: #fdfcfb; }
        
        .acoes-coluna { display: flex; gap: 8px; }
        .btn-icon { color: #576574; background: #f1f2f6; padding: 8px; border-radius: 6px; text-decoration: none; transition: 0.2s; display: inline-flex; width: 32px; height: 32px; align-items: center; justify-content: center; font-size:15px; border:none; cursor:pointer;}
        .btn-edit:hover { background: #e1e5ea; color: #3498db; }
        .btn-del { color: #e74c3c; background: #fdeded; }
        .btn-del:hover { background: #fadbd8; color: #c0392b; }

        /* MODAIS */
        .modal { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.6); z-index: 1001; justify-content: center; align-items: center; backdrop-filter: blur(2px); }
        .modal-content { background: #fff; padding: 30px; border-radius: 12px; width: 90%; max-width: 400px; text-align: center; box-shadow: 0 20px 50px rgba(0,0,0,0.2); }
        .modal-content h3 { color: #e74c3c; margin-top: 0; margin-bottom: 15px; display: flex; align-items: center; justify-content: center; gap: 8px;}
        .modal-content input[type="password"] { width: 100%; padding: 12px; margin: 20px 0; border: 1px solid #dcdde1; border-radius: 6px; font-size: 15px; outline: none; transition: 0.2s;}
        .modal-content input[type="password"]:focus { border-color: #e74c3c; }
        
        .modal-btns { display: flex; gap: 10px; margin-top: 15px; }
        .modal-btns button { flex: 1; padding: 12px; border: none; border-radius: 8px; font-weight: 600; cursor: pointer; transition: 0.2s; }
        .btn-cancel { background: #f1f2f6; color: #576574; }
        .btn-cancel:hover { background: #e1e5ea; }
        .btn-danger { background: #e74c3c; color: #fff; }
        .btn-danger:hover { background: #c0392b; }

        @media (max-width: 768px) {
            .topbar { padding: 15px 20px; }
            .topbar-center { display: none; }
            .btn-back-text { display: none; }
            .tabs { flex-direction: column; gap: 5px; border-bottom: none; }
            .tab-btn { border-radius: 8px; border-bottom: none; background: #f8f9fa; }
            .tab-btn.active { background: #ebf5fb; color: #3498db; }
            .card { background: transparent; border: none; box-shadow: none; }
            table, thead, tbody, th, td, tr { display: block; }
            thead tr { position: absolute; top: -9999px; left: -9999px; }
            tr { background: #fff; margin-bottom: 15px; border-radius: 12px; border: 1px solid #e1e5ea; box-shadow: 0 4px 10px rgba(0,0,0,0.03); padding: 5px 0; }
            td { border: none; border-bottom: 1px solid #f1f2f6; position: relative; padding: 12px 15px 12px 40%; text-align: right; min-height: 24px; display: flex; justify-content: flex-end; align-items: center; }
            td::before { content: attr(data-label); position: absolute; left: 15px; width: 35%; text-align: left; font-weight: 700; color: #576574; font-size: 11px; text-transform: uppercase; }
            td:last-child { border-bottom: none; }
        }
    </style>
</head>
<body>

    <header class="topbar">
        <div class="topbar-left"><a href="index.php"><img src="mab.png" alt="MAB" class="logo"></a></div>
        <div class="topbar-center">
            </div>
        <div class="topbar-right">
            <a href="index.php" class="btn-back" title="Voltar"><i class="fa-solid fa-arrow-left"></i> <span class="btn-back-text">Voltar</span></a>
            <div class="user-menu">
                <button class="user-btn">
                    <div class="user-avatar"><i class="fa-solid fa-user"></i></div>
                    <?= htmlspecialchars($primeiro_nome) ?> 
                    <i class="fa-solid fa-chevron-down" style="font-size: 10px;"></i>
                </button>
                <div class="dropdown-content">
                    <a href="perfil.php"><i class="fa-solid fa-id-badge" style="width:20px;"></i> O meu perfil</a>
                    <?php if ($is_admin): ?>
                        <a href="configuracoes.php"><i class="fa-solid fa-gear" style="width:20px;"></i> Configurações</a>
                    <?php endif; ?>
                    <a href="index.php?logout=1" style="color: #e74c3c; border-top: 1px solid #f1f2f6;"><i class="fa-solid fa-right-from-bracket" style="width:20px;"></i> Sair</a>
                </div>
            </div>
        </div>
    </header>

    <div class="container">
        <?php if ($mensagem): ?>
            <div class="alert alert-<?= $tipo_mensagem ?>"><?= $mensagem ?></div>
        <?php endif; ?>

        <div class="page-header">
            <h1 class="page-title"><i class="fa-solid fa-users"></i> Gestão de Clientes</h1>
            <?php if ($is_admin): ?>
                <a href="cliente_form.php" class="btn-primary"><i class="fa-solid fa-plus"></i> Novo Cliente</a>
            <?php endif; ?>
        </div>

        <div class="tabs">
            <button class="tab-btn active" onclick="switchTab('obras', this)"><i class="fa-solid fa-helmet-safety"></i> Clientes de Obra</button>
            <button class="tab-btn" onclick="switchTab('pontuais', this)"><i class="fa-solid fa-bolt"></i> Clientes Pontuais</button>
        </div>

        <div id="tab-obras" class="tab-content active">
            <div class="card">
                <table>
                    <thead>
                        <tr>
                            <th width="10%">Nº</th>
                            <th>Nome / Entidade</th>
                            <th>NIF</th>
                            <th>Contacto</th>
                            <th width="15%">Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($clientes_obra) > 0): ?>
                            <?php foreach ($clientes_obra as $c): ?>
                                <tr>
                                    <td data-label="Nº" style="color: #7f8c8d; font-weight: 600;"><?= $c['id'] ?></td>
                                    <td data-label="Nome" style="font-weight: 700; color: #3498db;"><?= htmlspecialchars($c['nome']) ?></td>
                                    <td data-label="NIF"><?= htmlspecialchars($c['nif'] ?: 'N/D') ?></td>
                                    <td data-label="Contacto"><?= htmlspecialchars($c['telefone'] ?: '-') ?></td>
                                    <td data-label="Ações">
                                        <div class="acoes-coluna">
                                            <a href="cliente_form.php?id=<?= $c['id'] ?>" class="btn-icon btn-edit" title="Editar Ficha"><i class="fa-solid fa-pen-to-square"></i></a>
                                            
                                            <?php if ($is_admin): ?>
                                                <button type="button" class="btn-icon btn-del" title="Apagar" onclick="abrirModalApagar(<?= $c['id'] ?>, '<?= htmlspecialchars(addslashes($c['nome'])) ?>')"><i class="fa-solid fa-trash-can"></i></button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="5" style="text-align: center; padding: 40px; color:#7f8c8d;">Não existem Clientes de Obra ativos registados.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div id="tab-pontuais" class="tab-content">
            <div class="card">
                <table>
                    <thead>
                        <tr>
                            <th width="10%">Nº</th>
                            <th>Nome / Entidade</th>
                            <th>NIF</th>
                            <th>Contacto</th>
                            <th width="15%">Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($clientes_pontual) > 0): ?>
                            <?php foreach ($clientes_pontual as $c): ?>
                                <tr>
                                    <td data-label="Nº" style="color: #7f8c8d; font-weight: 600;"><?= $c['id'] ?></td>
                                    <td data-label="Nome" style="font-weight: 700; color: #3498db;"><?= htmlspecialchars($c['nome']) ?></td>
                                    <td data-label="NIF"><?= htmlspecialchars($c['nif'] ?: 'N/D') ?></td>
                                    <td data-label="Contacto"><?= htmlspecialchars($c['telefone'] ?: '-') ?></td>
                                    <td data-label="Ações">
                                        <div class="acoes-coluna">
                                            <a href="cliente_form.php?id=<?= $c['id'] ?>" class="btn-icon btn-edit" title="Editar Ficha"><i class="fa-solid fa-pen-to-square"></i></a>
                                            
                                            <?php if ($is_admin): ?>
                                                <button type="button" class="btn-icon btn-del" title="Apagar" onclick="abrirModalApagar(<?= $c['id'] ?>, '<?= htmlspecialchars(addslashes($c['nome'])) ?>')"><i class="fa-solid fa-trash-can"></i></button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="5" style="text-align: center; padding: 40px; color:#7f8c8d;">Não existem Clientes Pontuais ativos registados.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        
    </div>

    <?php if ($is_admin): ?>
    <div class="modal" id="modalApagar">
        <div class="modal-content">
            <h3><i class="fa-solid fa-triangle-exclamation"></i> Confirmar Eliminação</h3>
            <p style="color:#2c3e50; font-size:14px; margin-bottom: 20px;">Deseja apagar definitivamente o cliente:<br><strong id="nome_cliente_modal" style="color:#3498db; font-size:16px;"></strong>?</p>
            <form method="POST">
                <input type="hidden" name="id_cliente_apagar" id="id_cliente_apagar">
                <input type="password" name="pass_seguranca" required placeholder="Password de Segurança">
                <div class="modal-btns">
                    <button type="button" class="btn-cancel" onclick="fecharModais()">Cancelar</button>
                    <button type="submit" name="btn_confirmar_apagar" class="btn-danger">Apagar Cliente</button>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <script>
        function switchTab(tabId, btn) {
            // Remove a classe active de todos os botões e conteúdos
            document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
            document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
            
            // Adiciona a classe active ao botão clicado e ao conteúdo correspondente
            btn.classList.add('active');
            document.getElementById('tab-' + tabId).classList.add('active');
        }

        function abrirModalApagar(id, nome) {
            document.getElementById('id_cliente_apagar').value = id;
            document.getElementById('nome_cliente_modal').innerText = nome;
            document.getElementById('modalApagar').style.display = 'flex';
            document.querySelector('#modalApagar input[name="pass_seguranca"]').value = '';
            document.querySelector('#modalApagar input[name="pass_seguranca"]').focus();
        }
        
        function fecharModais() {
            document.querySelectorAll('.modal').forEach(m => m.style.display = 'none');
        }
        
        window.onclick = function(e) { 
            if(e.target.classList.contains('modal')) fecharModais(); 
        }
    </script>
</body>
</html>