<?php
session_start();
require_once 'db.php';
require_once 'functions.php';

// Validação de sessão
if (!isset($_SESSION['user_id'])) { 
    header("Location: index.php"); 
    exit; 
}

$perfil_user = $_SESSION['perfil'] ?? '';
$is_admin = in_array($perfil_user, ['admin', 'administrativo']);

// Acesso Restrito pela Matriz
$stmt_perm = $pdo->prepare("SELECT modulo FROM permissoes WHERE grupo = :perfil AND pode_aceder = 1");
$stmt_perm->execute(['perfil' => $perfil_user]);
$meus_acessos = $stmt_perm->fetchAll(PDO::FETCH_COLUMN);

if (!$is_admin && !in_array('equipa', $meus_acessos)) {
    header("Location: index.php?erro=sem_permissao");
    exit;
}

$csrf_token = gerar_csrf_token();
$is_super_admin = ($perfil_user === 'admin'); // a ficha completa tem dados salariais: só o Administrador

// REGRA DE OURO: 1º Nome no Avatar
$nome_completo = $_SESSION['nome'] ?? 'Utilizador';
$primeiro_nome = explode(' ', trim($nome_completo))[0];

$mensagem = '';
$tipo_mensagem = '';

if (isset($_GET['editado'])) {
    $mensagem = "Operação realizada com sucesso!";
    $tipo_mensagem = "sucesso";
}

// --- LÓGICA PARA APAGAR UTILIZADOR ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['btn_confirmar_apagar'])) {
    
    if (!validar_csrf_token($_POST['csrf_token'] ?? '')) {
        die("Tentativa bloqueada por segurança (CSRF Inválido). Volte atrás e recarregue a página.");
    }

    $id_user_del = filter_input(INPUT_POST, 'id_item_apagar', FILTER_VALIDATE_INT);
    $pass_introduzida = $_POST['pass_seguranca'] ?? '';

    if ($id_user_del == $_SESSION['user_id']) {
        $mensagem = "Erro: Não pode apagar a sua própria conta enquanto está ligado.";
        $tipo_mensagem = "erro";
    } elseif (!$is_admin) {
        $mensagem = "Erro: Apenas administradores podem apagar utilizadores.";
        $tipo_mensagem = "erro";
    } else {
        $stmt_pass = $pdo->prepare("SELECT valor FROM configuracoes WHERE chave = 'password_apagar' LIMIT 1");
        $stmt_pass->execute();
        $pass_config = $stmt_pass->fetchColumn();

        if ($pass_introduzida !== $pass_config || empty($pass_introduzida)) {
            $mensagem = "Password de segurança incorreta. Operação cancelada.";
            $tipo_mensagem = "erro";
        } else {
            $tabelas_ref = ['orcamentos', 'obras', 'relatorios_obra', 'servicos', 'visitas', 'obra_despesas', 'frota_registos', 'equipamentos'];
            $referenciado = false;
            foreach ($tabelas_ref as $tab) {
                try {
                    $coluna = in_array($tab, ['relatorios_obra', 'servicos', 'visitas']) ? 'colaborador_1' : 'id_utilizador';
                    if ($tab === 'obras' || $tab === 'orcamentos') { $coluna = 'id_utilizador'; }

                    $stmt_ref = $pdo->prepare("SELECT COUNT(*) FROM $tab WHERE $coluna = :id");
                    $stmt_ref->execute(['id' => $id_user_del]);
                    if ($stmt_ref->fetchColumn() > 0) { $referenciado = true; break; }
                } catch (PDOException $e) { }
            }

            if ($referenciado) {
                $mensagem = "Não pode apagar este utilizador. Ele possui histórico de relatórios, orçamentos ou obras associadas. Sugestão: Desative a conta em 'Editar'.";
                $tipo_mensagem = "erro";
            } else {
                try {
                    $stmt_del = $pdo->prepare("DELETE FROM utilizadores WHERE id = :id");
                    $stmt_del->execute(['id' => $id_user_del]);
                    registrarLog($pdo, "Apagou utilizador ID: $id_user_del", "Equipa");
                    $mensagem = "Utilizador removido com sucesso.";
                    $tipo_mensagem = "sucesso";
                } catch (Exception $e) {
                    $mensagem = "Erro ao remover utilizador: " . $e->getMessage();
                    $tipo_mensagem = "erro";
                }
            }
        }
    }
}

// Buscar todos os utilizadores
$users = $pdo->query("SELECT * FROM utilizadores ORDER BY nome ASC")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="pt-PT">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Equipa e Utilizadores - Gestão MAB</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { font-family: system-ui, -apple-system, sans-serif; background-color: #f7f9fc; margin: 0; color: #333; padding-bottom: 50px; }
        * { box-sizing: border-box; }
        
        /* CABEÇALHO (regras do projeto: logo -> início, sem ícones de navegação, voltar só com símbolo) */
        .topbar { background: rgba(255,255,255,0.94); backdrop-filter: blur(8px); -webkit-backdrop-filter: blur(8px); padding: 10px 40px; box-shadow: 0 1px 0 #e8ecf1; display: flex; justify-content: space-between; align-items: center; position: sticky; top: 0; z-index: 100; }
        .topbar-left a { display: block; }
        .topbar-left .logo { height: 35px; display: block; }
        .topbar-right { display: flex; align-items: center; gap: 12px; }
        .btn-voltar { width: 40px; height: 40px; border-radius: 50%; background: #fff; border: 1px solid #e8ecf1; color: #576574; display: inline-flex; align-items: center; justify-content: center; text-decoration: none; font-size: 16px; transition: all 0.2s ease; box-shadow: 0 2px 6px rgba(0,0,0,0.04); flex-shrink: 0; }
        .btn-voltar:hover { background: #eef2f6; color: #2c3e50; border-color: #d5dce4; transform: translateX(-2px); }
        .btn-voltar:active { transform: scale(0.94); }
        .user-menu { position: relative; }
        .user-btn { background: #fff; border: 1px solid #e8ecf1; border-radius: 30px; font-size: 14px; font-weight: 600; color: #2c3e50; cursor: pointer; display: flex; align-items: center; gap: 10px; padding: 5px 14px 5px 5px; transition: 0.2s; font-family: inherit; }
        .user-btn:hover { box-shadow: 0 4px 12px rgba(0,0,0,0.06); }
        .user-avatar { background: #e74c3c; color: #fff; width: 28px; height: 28px; border-radius: 50%; display: flex; justify-content: center; align-items: center; font-size: 13px; }
        .dropdown-content { display: none; position: absolute; right: 0; top: 100%; background: #fff; min-width: 180px; box-shadow: 0 12px 30px rgba(0,0,0,0.1); border-radius: 10px; border: 1px solid #e8ecf1; overflow: hidden; margin-top: 6px; z-index: 200; }
        .user-menu:hover .dropdown-content, .user-menu.aberto .dropdown-content { display: block; }
        .dropdown-content a { color: #576574; padding: 12px 20px; text-decoration: none; display: block; font-size: 14px; }
        .dropdown-content a:hover { background: #f4f6f8; color: #2c3e50; }
        @media (max-width: 1024px) { .topbar { padding: 10px 24px; } }
        @media (max-width: 768px) { .topbar { padding: 8px 15px; } .topbar-left .logo { height: 30px; } .btn-voltar { width: 38px; height: 38px; } }
        @media (max-width: 560px) { .user-nome, .user-btn .fa-caret-down { display: none; } .user-btn { padding: 4px; } }

        /* Botões de impressão */
        .acoes-topo { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; }
        .form-mapa-ferias { display: inline-flex; align-items: center; gap: 0; border: 1px solid #27ae60; border-radius: 30px; overflow: hidden; background: #fff; }
        .form-mapa-ferias select { border: none; padding: 7px 6px 7px 12px; font-size: 13px; font-weight: 700; color: #27ae60; background: transparent; outline: none; font-family: inherit; cursor: pointer; }
        .form-mapa-ferias button { background: #27ae60; color: #fff; border: none; padding: 8px 14px; font-weight: 700; font-size: 13px; cursor: pointer; display: inline-flex; align-items: center; gap: 6px; font-family: inherit; }
        .form-mapa-ferias button:hover { background: #229954; }
        .btn-ficha { color: #2980b9; background: #ebf5fb; }
        .btn-ficha:hover { color: #1f618d; background: #d6eaf8; }

        .container { max-width: 1200px; margin: 30px auto; padding: 0 15px; }
        
        .page-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px; flex-wrap: wrap; gap: 15px; }
        .page-title { margin: 0; color: #2c3e50; font-size: 24px; font-weight: 800; display: flex; align-items: center; gap: 10px; }
        .page-title i { color: #7f8c8d; }
        
        .btn-novo { background: #fff; color: #7f8c8d; border: 1px solid #7f8c8d; padding: 8px 15px; border-radius: 30px; text-decoration: none; font-weight: 700; font-size: 13px; display: inline-flex; align-items: center; gap: 6px; transition: 0.2s; cursor:pointer;}
        .btn-novo:hover { background: #7f8c8d; color: #fff; }

        .alert { padding: 15px; border-radius: 8px; margin-bottom: 20px; text-align: center; font-weight: 600; }
        .alert-erro { background: #fdeded; color: #e74c3c; border: 1px solid #f9cccc; }
        .alert-sucesso { background: #f4f6f7; color: #7f8c8d; border: 1px solid #e1e5ea; }

        .card { background: #fff; border-radius: 12px; border: 1px solid #e1e5ea; box-shadow: 0 2px 10px rgba(0,0,0,0.02); overflow: hidden; }
        .table-responsive { width: 100%; overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; min-width: 900px; }
        th { background: #f8f9fa; padding: 15px 20px; text-align: left; font-size: 11px; text-transform: uppercase; color: #576574; border-bottom: 2px solid #e1e5ea; font-weight: 700; }
        td { padding: 15px 20px; border-bottom: 1px solid #f1f2f6; color: #2c3e50; vertical-align: middle; font-size: 14px;}
        tr:hover { background: #fcfcfc; }
        
        .badge { padding: 4px 10px; border-radius: 20px; font-size: 11px; font-weight: 700; text-transform: uppercase; display: inline-block; }
        .badge-admin { background: #fff3e0; color: #e67e22; border: 1px solid #ffe0b2;}
        .badge-adm { background: #eef2fa; color: #4a69bd; border: 1px solid #d9e2f3;}
        .badge-func { background: #f1f2f6; color: #576574; border: 1px solid #dcdde1;}
        .badge-ext { background: #fdeded; color: #e74c3c; border: 1px solid #f9cccc;}
        
        .status-dot { height: 10px; width: 10px; border-radius: 50%; display: inline-block; margin-right: 6px; }
        .bg-ativo { background-color: #2ecc71; box-shadow: 0 0 5px rgba(46,204,113,0.5); }
        .bg-inativo { background-color: #e74c3c; }

        .acoes { display: flex; gap: 8px; justify-content: center; }
        .btn-tab-acao { color: #576574; background: #f1f2f6; padding: 8px; border-radius: 6px; text-decoration: none; transition: 0.2s; display: inline-flex; width: 32px; height: 32px; align-items: center; justify-content: center; font-size:15px; border:none; cursor:pointer;}
        .btn-tab-acao:hover { background: #e1e5ea; color: #2c3e50; }
        
        .btn-epi { color: #f39c12; background: #fef5e7; }
        .btn-epi:hover { color: #d68910; background: #fdebd0; }

        .btn-editar { color: #7f8c8d; background: #f4f6f7; }
        .btn-editar:hover { color: #2c3e50; background: #e1e5ea; }
        
        .btn-del { color: #e74c3c; background: #fdeded; }
        .btn-del:hover { background: #fadbd8; color: #c0392b; }

        /* MODAIS */
        .modal { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.6); z-index: 1001; justify-content: center; align-items: center; backdrop-filter: blur(2px); }
        .modal.show { display: flex; }
        .modal-content { background: #fff; padding: 30px; border-radius: 12px; width: 90%; max-width: 400px; text-align: center; box-shadow: 0 20px 50px rgba(0,0,0,0.2); }
        
        .modal-header { display: flex; justify-content: center; align-items: center; border-bottom: none; margin-bottom: 10px; }
        .modal-header h3 { margin: 0; color: #e74c3c; font-size: 18px; display: flex; align-items: center; gap: 8px; }
        
        .modal-content input[type="password"] { width: 100%; padding: 12px; border: 1px solid #dcdde1; border-radius: 8px; margin: 15px 0; outline: none; transition: 0.2s;}
        .modal-content input[type="password"]:focus { border-color: #e74c3c; }
        
        .modal-btns { display: flex; gap: 10px; margin-top: 15px;}
        .modal-btns button { flex: 1; padding: 12px; border: none; border-radius: 8px; font-weight: 600; cursor: pointer; transition: 0.2s;}
        .btn-cancelar { background: #f1f2f6; color: #576574; }
        .btn-cancelar:hover { background: #e1e5ea; }
        .btn-confirmar { background: #e74c3c; color: #fff; }
        .btn-confirmar:hover { background: #c0392b; }

        @media (max-width: 768px) {
            .page-header { flex-direction: column; align-items: stretch; }
            .acoes-topo { justify-content: space-between; }
            .btn-novo { padding: 6px 12px; font-size: 12px; }
            .table-responsive { border: none; border-radius: 0; }
            table, thead, tbody, th, td, tr { display: block; }
            thead tr { position: absolute; top: -9999px; left: -9999px; }
            tr { background: #fff; margin-bottom: 15px; border-radius: 12px; border: 1px solid #e1e5ea; padding: 5px 0; box-shadow: 0 4px 10px rgba(0,0,0,0.03); }
            td { border: none; border-bottom: 1px solid #f1f2f6; position: relative; padding: 12px 15px 12px 40%; text-align: right; display: flex; justify-content: flex-end; align-items: center; }
            td::before { content: attr(data-label); position: absolute; left: 15px; width: 35%; text-align: left; font-weight: 600; color: #576574; font-size: 12px; text-transform: uppercase; }
            td:last-child { border-bottom: none; justify-content: center; padding-left: 15px;}
            .acoes { gap: 20px; }
        }
    </style>
</head>
<body>

    <header class="topbar">
        <div class="topbar-left"><a href="index.php" title="Página inicial"><img src="mab.png" alt="MAB" class="logo"></a></div>
        <div class="topbar-right">
            <a href="index.php" class="btn-voltar" title="Voltar" aria-label="Voltar"><i class="fa-solid fa-arrow-left"></i></a>
            <div class="user-menu">
                <button type="button" class="user-btn">
                    <div class="user-avatar"><i class="fa-solid fa-user"></i></div>
                    <span class="user-nome"><?= htmlspecialchars($primeiro_nome) ?></span>
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
                <?= $tipo_mensagem === 'erro' ? '<i class="fa-solid fa-triangle-exclamation"></i>' : '<i class="fa-solid fa-check-circle"></i>' ?> 
                <?= htmlspecialchars($mensagem, ENT_QUOTES, 'UTF-8') ?>
            </div>
        <?php endif; ?>

        <div class="page-header">
            <h1 class="page-title"><i class="fa-solid fa-id-card-clip"></i> Gestão de Equipa</h1>
            <div class="acoes-topo">
                <?php if ($is_admin): ?>
                    <!-- MAPA DE FÉRIAS ANUAL (todos os trabalhadores) -->
                    <form method="GET" action="mapa_ferias_pdf.php" target="_blank" class="form-mapa-ferias" title="Imprimir o mapa de férias anual de todos os trabalhadores">
                        <select name="ano">
                            <?php for ($a = (int)date('Y') + 1; $a >= (int)date('Y') - 3; $a--): ?>
                                <option value="<?= $a ?>" <?= $a === (int)date('Y') ? 'selected' : '' ?>><?= $a ?></option>
                            <?php endfor; ?>
                        </select>
                        <button type="submit"><i class="fa-solid fa-print"></i> Mapa de Férias</button>
                    </form>
                    <a href="utilizador_form.php" class="btn-novo"><i class="fa-solid fa-user-plus"></i> Novo Utilizador</a>
                <?php endif; ?>
            </div>
        </div>

        <div class="card">
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>Nome e Email</th>
                            <th>Utilizador (Acesso)</th>
                            <th>Perfil</th>
                            <th>Custo / Hora</th>
                            <th>Estado</th>
                            <?php if ($is_admin): ?><th style="text-align:center;">Ações</th><?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($users) > 0): ?>
                            <?php foreach ($users as $u): 
                                $p_class = match($u['perfil']) {
                                    'admin' => 'badge-admin', 'administrativo' => 'badge-adm',
                                    'funcionario' => 'badge-func', 'externo' => 'badge-ext', default => ''
                                };
                            ?>
                            <tr>
                                <td data-label="Nome e Email">
                                    <strong style="color: #2c3e50; font-size:15px;"><?= htmlspecialchars($u['nome']) ?></strong><br>
                                    <small style="color: #7f8c8d;"><i class="fa-regular fa-envelope"></i> <?= htmlspecialchars($u['email'] ?: 'Sem email registado') ?></small>
                                </td>
                                <td data-label="Utilizador">
                                    <span style="background:#f1f2f6; padding:4px 8px; border-radius:4px; font-family:monospace; color:#2c3e50;"><?= htmlspecialchars($u['username']) ?></span>
                                </td>
                                <td data-label="Perfil"><span class="badge <?= $p_class ?>"><?= $u['perfil'] ?></span></td>
                                
                                <td data-label="Custo / Hora">
                                    <strong style="color: #d35400; font-size: 15px;"><?= number_format((float)($u['valor_hora'] ?? 0), 2, ',', ' ') ?> €</strong>
                                </td>

                                <td data-label="Estado">
                                    <span class="status-dot <?= $u['ativo'] ? 'bg-ativo' : 'bg-inativo' ?>"></span>
                                    <span style="font-weight:600; font-size:13px; color: <?= $u['ativo'] ? '#2ecc71' : '#e74c3c' ?>;"><?= $u['ativo'] ? 'Ativo' : 'Suspenso' ?></span>
                                </td>
                                
                                <?php if ($is_admin): ?>
                                <td data-label="Ações">
                                    <div class="acoes">
                                        <!-- BOTÃO EPIs QUE ABRE O NOVO FICHEIRO -->
                                        <a href="utilizadores_epis.php?id=<?= $u['id'] ?>" class="btn-tab-acao btn-epi" title="Registo de Posse de EPI's"><i class="fa-solid fa-file-signature"></i></a>
                                        
                                        <?php if ($is_super_admin): ?>
                                            <a href="utilizador_ficha_pdf.php?id=<?= $u['id'] ?>" target="_blank" class="btn-tab-acao btn-ficha" title="Imprimir ficha completa"><i class="fa-solid fa-print"></i></a>
                                        <?php endif; ?>
                                        <a href="utilizador_form.php?id=<?= $u['id'] ?>" class="btn-tab-acao btn-editar" title="Editar"><i class="fa-solid fa-pen-to-square"></i></a>
                                        <?php if ($u['id'] != $_SESSION['user_id']): ?>
                                            <button type="button" class="btn-tab-acao btn-del" title="Apagar" onclick="abrirModalApagar(<?= $u['id'] ?>, '<?= htmlspecialchars($u['nome'], ENT_QUOTES) ?>')"><i class="fa-solid fa-trash-can"></i></button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <?php endif; ?>
                            </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="6" style="text-align: center; padding: 40px; color: #7f8c8d;">Nenhum utilizador registado.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <?php if ($is_admin): ?>
    <div class="modal" id="modalApagar">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fa-solid fa-triangle-exclamation"></i> Confirmar Eliminação</h3>
            </div>
            <p>Deseja apagar o utilizador:<br><strong id="nome_m" style="color: #2c3e50; font-size: 16px; margin-top: 10px; display: inline-block;"></strong>?</p>
            <p style="font-size: 12px; color: #7f8c8d; line-height: 1.4; margin-bottom: 20px;">Atenção: Apenas é possível apagar utilizadores sem histórico associado.</p>
            
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                <input type="hidden" name="id_item_apagar" id="id_m">
                <input type="password" name="pass_seguranca" required placeholder="Password de Segurança">
                
                <div class="modal-btns">
                    <button type="button" class="btn-cancelar" onclick="fecharModal()">Cancelar</button>
                    <button type="submit" name="btn_confirmar_apagar" class="btn-confirmar">Apagar</button>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <script>
        function abrirModalApagar(id, nome) {
            document.getElementById('id_m').value = id;
            document.getElementById('nome_m').innerText = nome;
            document.getElementById('modalApagar').classList.add('show');
            document.querySelector('#modalApagar input[name="pass_seguranca"]').value = '';
            document.querySelector('#modalApagar input[name="pass_seguranca"]').focus();
        }
        function fecharModal() {
            document.getElementById('modalApagar').classList.remove('show');
        }
        window.onclick = function(e) { if(e.target.id == 'modalApagar') fecharModal(); }

        // Menu do utilizador: em ecrãs táteis (tablet/telemóvel) abre com toque
        (function () {
            const menu = document.querySelector('.user-menu');
            const btn = menu ? menu.querySelector('.user-btn') : null;
            if (!btn) return;
            btn.addEventListener('click', function (e) { e.stopPropagation(); menu.classList.toggle('aberto'); });
            document.addEventListener('click', function () { menu.classList.remove('aberto'); });
        })();
    </script>
</body>
</html>