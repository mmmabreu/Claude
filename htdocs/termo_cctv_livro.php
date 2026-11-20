<?php
// FORÇAR EXIBIÇÃO DE ERROS BÁSICOS
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

try {
    session_start();
    require_once 'db.php';

    if (!isset($_SESSION['user_id'])) { header("Location: index.php"); exit; }

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

    if (!$is_admin && !in_array('termos', $meus_acessos) && !in_array('termos_cctv', $meus_acessos)) {
        header("Location: index.php?erro=sem_permissao"); exit;
    }

    $nome_completo = $_SESSION['nome'] ?? 'Utilizador';
    $primeiro_nome = explode(' ', trim($nome_completo))[0];
    $csrf_token = gerar_csrf_token();

    // 1. CARREGAR DADOS DA INSTALAÇÃO (TERMO)
    $id_termo = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
    if (!$id_termo) { header("Location: termos_cctv.php"); exit; }

    $stmt_termo = $pdo->prepare("SELECT t.*, c.nome as nome_cliente 
                                 FROM termos_cctv t 
                                 LEFT JOIN clientes c ON t.id_cliente = c.id 
                                 WHERE t.id = ?");
    $stmt_termo->execute([$id_termo]);
    $termo = $stmt_termo->fetch(PDO::FETCH_ASSOC);

    if (!$termo) { header("Location: termos_cctv.php"); exit; }

    $mensagem = ''; $tipo_mensagem = '';

    // 2. LÓGICA: GRAVAR NOVA INTERVENÇÃO OU EDITAR
    if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['btn_guardar_intervencao'])) {
        if (!validar_csrf_token($_POST['csrf_token'] ?? '')) { die("CSRF Inválido."); }

        $id_interv = filter_input(INPUT_POST, 'id_intervencao', FILTER_VALIDATE_INT);
        
        $dados = [
            $_POST['data_intervencao'],
            $_POST['hora_intervencao'],
            $_POST['tipo_intervencao'],
            $_POST['modo_intervencao'],
            $_POST['alvara_responsavel'] ?? '',
            $_POST['causa'] ?? '',
            $_POST['trabalhos_executados'] ?? '',
            $_POST['num_relatorio'] ?? '',
            $_POST['nome_tecnico'] ?? ''
        ];

        try {
            if ($id_interv) {
                // Atualizar
                $sql_upd = "UPDATE cctv_intervencoes SET data_intervencao=?, hora_intervencao=?, tipo_intervencao=?, modo_intervencao=?, alvara_responsavel=?, causa=?, trabalhos_executados=?, num_relatorio=?, nome_tecnico=? WHERE id=? AND id_termo_cctv=?";
                $dados[] = $id_interv;
                $dados[] = $id_termo;
                $pdo->prepare($sql_upd)->execute($dados);
                $mensagem = "Linha atualizada com sucesso no Livro de Registos.";
            } else {
                // Inserir (Novo ou Duplicado)
                $sql_ins = "INSERT INTO cctv_intervencoes (data_intervencao, hora_intervencao, tipo_intervencao, modo_intervencao, alvara_responsavel, causa, trabalhos_executados, num_relatorio, nome_tecnico, id_termo_cctv) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                $dados[] = $id_termo;
                $pdo->prepare($sql_ins)->execute($dados);
                $mensagem = "Nova linha acrescentada ao Livro de Registos.";
            }
            $tipo_mensagem = "sucesso";
            registrarLog($pdo, "Editou Livro CCTV (ID Termo: $id_termo)", "Termos CCTV");
        } catch(Exception $e) {
            $mensagem = "Erro ao guardar: " . $e->getMessage(); $tipo_mensagem = "erro";
        }
    }

    // 3. LÓGICA: APAGAR INTERVENÇÃO
    if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['btn_apagar_intervencao'])) {
        if (!validar_csrf_token($_POST['csrf_token'] ?? '')) { die("CSRF Inválido."); }
        $id_apagar = filter_input(INPUT_POST, 'id_apagar', FILTER_VALIDATE_INT);
        
        try {
            $pdo->prepare("DELETE FROM cctv_intervencoes WHERE id=? AND id_termo_cctv=?")->execute([$id_apagar, $id_termo]);
            $mensagem = "Linha removida do Livro de Registos."; $tipo_mensagem = "sucesso";
        } catch(Exception $e) {
            $mensagem = "Erro ao apagar: " . $e->getMessage(); $tipo_mensagem = "erro";
        }
    }

    // 4. CARREGAR TODAS AS INTERVENÇÕES (O LIVRO COMPLETO)
    $stmt_interv = $pdo->prepare("SELECT * FROM cctv_intervencoes WHERE id_termo_cctv = ? ORDER BY data_intervencao DESC, hora_intervencao DESC");
    $stmt_interv->execute([$id_termo]);
    $intervencoes = $stmt_interv->fetchAll(PDO::FETCH_ASSOC);

} catch (Throwable $e) {
    die("<div style='margin:50px auto; max-width:800px; padding:25px; background:#fff3f3; color:#d63031; border:2px solid #ff7675; border-radius:8px;'><h3>⚠️ Erro Fatal</h3>" . $e->getMessage() . "</div>");
}
?>
<!DOCTYPE html>
<html lang="pt-PT">
<head>
    <meta charset="UTF-8">
    <title>Livro de Registos CCTV - MAB</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { font-family: system-ui, sans-serif; background: #f7f9fc; margin: 0; font-size: 13px; padding-bottom: 50px; }
        * { box-sizing: border-box; }
        
        .topbar { background-color: #fff; padding: 15px 40px; box-shadow: 0 4px 20px rgba(0,0,0,0.05); display: flex; justify-content: space-between; align-items: center; position: sticky; top: 0; z-index: 100; border-bottom: 3px solid #34495e;}
        .topbar-left .logo { height: 40px; }
        
        .topbar-right { display: flex; align-items: center; gap: 20px; }
        .btn-voltar { width: 38px; height: 38px; border-radius: 50%; background: #f1f2f6; color: #576574; display: inline-flex; align-items: center; justify-content: center; text-decoration: none; font-size: 16px; transition: 0.2s; }
        .btn-voltar:hover { background: #34495e; color: #fff; }
        
        .user-menu { position: relative; display: inline-block;}
        .user-btn { background: none; border: none; font-size: 15px; font-weight: 600; color: #2c3e50; cursor: pointer; display: flex; align-items: center; gap: 8px; }
        .user-avatar { background: #e74c3c; color: white; width: 28px; height: 28px; border-radius: 50%; display: flex; justify-content: center; align-items: center; font-size: 14px; border: 2px solid #fadbd8; }
        .dropdown-content { display: none; position: absolute; right: 0; top: 100%; margin-top: 10px; background: #fff; min-width: 180px; box-shadow: 0 10px 30px rgba(0,0,0,0.1); border-radius: 8px; overflow: hidden; border: 1px solid #f1f2f6; z-index:200;}
        .dropdown-content a { color: #576574; padding: 12px 20px; text-decoration: none; display: block; font-size: 14px; }
        .dropdown-content a:hover { background: #fdf2f2; color: #b32a2a; }
        .user-menu.aberto .dropdown-content { display: block; }

        .container { max-width: 1400px; margin: 30px auto; padding: 0 20px; }
        
        .header-actions { display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px; flex-wrap: wrap; gap:15px;}
        .page-title { margin: 0; color: #2c3e50; font-size: 22px; font-weight: 800; display: flex; align-items: center; gap: 10px; }
        
        .btn-primary { background: #34495e; color: #fff; padding: 10px 18px; border-radius: 8px; border: none; font-weight: bold; cursor: pointer; text-decoration:none; display:inline-flex; align-items:center; gap:8px; transition:0.2s;}
        .btn-primary:hover { background: #2c3e50; }
        .btn-pdf { background: #d35400; }
        .btn-pdf:hover { background: #e67e22; }

        .info-card { background: #fff; border-radius: 12px; padding: 20px; border: 1px solid #e1e5ea; display: flex; gap: 40px; margin-bottom: 25px; box-shadow: 0 4px 15px rgba(0,0,0,0.03); flex-wrap: wrap;}
        .info-item { display: flex; flex-direction: column; gap: 4px; }
        .info-label { font-size: 10px; text-transform: uppercase; color: #7f8c8d; font-weight: 700; }
        .info-value { font-size: 15px; color: #2c3e50; font-weight: 600; }

        table { width: 100%; border-collapse: collapse; background:#fff; border-radius:10px; overflow:hidden; box-shadow: 0 4px 15px rgba(0,0,0,0.03); border:1px solid #e1e5ea;}
        th { text-align: left; padding: 15px; background: #f8f9fa; font-size: 11px; text-transform: uppercase; color: #7f8c8d; border-bottom: 1px solid #eee; }
        td { padding: 15px; border-bottom: 1px solid #f1f2f6; vertical-align: middle; }
        tbody tr:hover { background-color: #fcfcfc; }
        .tipo-badge { padding:4px 8px; background:#e8f4f8; color:#2980b9; border-radius:4px; font-size:11px; font-weight:bold; }
        
        .btn-acao { background: #f1f2f6; border: none; cursor: pointer; font-size: 14px; color: #576574; width: 30px; height: 30px; border-radius: 6px; display: inline-flex; align-items: center; justify-content: center; transition: 0.2s; margin-right:4px;}
        .btn-acao:hover { background: #e1e5ea; color: #2c3e50; }
        .btn-duplicar { color: #27ae60; background: #eafaf1; }
        .btn-duplicar:hover { background: #d5f5e3; color: #1e8449; }
        .btn-del { color: #e74c3c; background: #fdeded; }
        .btn-del:hover { background: #fadbd8; color: #c0392b; }

        .alert { padding: 15px; border-radius: 8px; margin-bottom: 20px; text-align: center; font-weight: 600; }
        .alert-erro { background: #fdeded; color: #c0392b; border: 1px solid #f9cccc; }
        .alert-sucesso { background: #eafaf1; color: #27ae60; border: 1px solid #d5f5e3; }

        .modal { display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.6); z-index:10000; align-items:center; justify-content:center; padding: 20px;}
        .modal-content { background:#fff; padding:30px; border-radius:12px; width:100%; max-width:600px; max-height:90vh; overflow-y:auto;}
        .form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom: 15px;}
        .form-full { grid-column: span 2; }
        label { font-size: 11px; font-weight: 700; color: #576574; text-transform: uppercase; margin-bottom: 5px; display: block;}
        input, select, textarea { width: 100%; padding: 10px; border: 1px solid #dcdde1; border-radius: 6px; font-family: inherit;}
        .modal-btns { display: flex; gap: 10px; margin-top: 20px; }
        .modal-btns button { flex:1; padding:12px; border:none; border-radius:8px; font-weight:bold; cursor:pointer;}

        @media (max-width: 768px) {
            .topbar { padding: 15px 20px; }
            .topbar-right { gap: 12px; }
            .container { padding: 0 12px; }
            .info-card { gap: 15px; }
            /* Tabela de intervenções passa a cartões */
            table, tbody, tr, td { display: block; width: 100%; }
            table { background: transparent; border: none; box-shadow: none; }
            thead { display: none; }
            tbody tr { background: #fff; border: 1px solid #e1e5ea; border-radius: 12px; margin-bottom: 12px; padding: 8px 0; }
            td { border: none !important; padding: 6px 14px !important; }
            td[data-label]::before { content: attr(data-label); display: block; font-size: 10px; text-transform: uppercase; color: #95a5a6; font-weight: 700; margin-bottom: 2px; }
            td.td-acoes { text-align: left !important; border-top: 1px solid #f1f2f6 !important; margin-top: 4px; padding-top: 10px !important; }
            .form-grid { grid-template-columns: 1fr; }
            .form-full { grid-column: span 1; }
        }
    </style>
</head>
<body>
    <header class="topbar">
        <div class="topbar-left"><a href="index.php"><img src="mab.png" alt="MAB" class="logo"></a></div>
        <div class="topbar-right">
            <a href="termos_cctv.php" class="btn-voltar" title="Voltar"><i class="fa-solid fa-arrow-left"></i></a>
            <div class="user-menu">
                <button type="button" class="user-btn" onclick="event.stopPropagation(); this.parentNode.classList.toggle('aberto');">
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

        <div class="header-actions">
            <h1 class="page-title"><i class="fa-solid fa-book" style="color:#d35400;"></i> Livro de Registos LRS</h1>
            <div style="display:flex; gap:10px;">
                <button onclick="abrirModal()" class="btn-primary"><i class="fa-solid fa-plus"></i> Nova Intervenção</button>
                <a href="termo_cctv_livro_pdf.php?id=<?= $id_termo ?>" target="_blank" class="btn-primary btn-pdf"><i class="fa-solid fa-file-pdf"></i> Emitir Livro Completo</a>
            </div>
        </div>

        <div class="info-card">
            <div class="info-item">
                <span class="info-label">Referência</span>
                <span class="info-value"><?= htmlspecialchars($termo['referencia_termo'] ?: 'N/D') ?></span>
            </div>
            <div class="info-item">
                <span class="info-label">Cliente / Entidade</span>
                <span class="info-value"><?= htmlspecialchars($termo['nome_cliente'] ?: 'N/D') ?></span>
            </div>
            <div class="info-item">
                <span class="info-label">Obra / Localização</span>
                <span class="info-value"><?= htmlspecialchars($termo['nome_obra'] ?? 'N/D') ?></span>
            </div>
            <div class="info-item">
                <span class="info-label">Sistema Gravador</span>
                <span class="info-value"><?= htmlspecialchars(($termo['marca_gravador'] ?? '') . ' ' . ($termo['modelo_gravador'] ?? '')) ?></span>
            </div>
        </div>

        <table>
            <thead>
                <tr>
                    <th width="120">Data e Hora</th>
                    <th>Tipo</th>
                    <th>Causa da Intervenção</th>
                    <th>Trabalhos Executados</th>
                    <th>Técnico</th>
                    <th width="120" style="text-align:center;">Ações</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($intervencoes)): ?>
                    <tr><td colspan="6" style="text-align:center; padding:40px; color:#7f8c8d;">O Livro de Registos está vazio. Adicione a primeira intervenção.</td></tr>
                <?php else: foreach($intervencoes as $inv): ?>
                    <tr>
                        <td data-label="Data e hora">
                            <strong><?= date('d/m/Y', strtotime($inv['data_intervencao'])) ?></strong><br>
                            <span style="color:#7f8c8d; font-size:12px;"><i class="fa-regular fa-clock"></i> <?= date('H:i', strtotime($inv['hora_intervencao'])) ?></span>
                        </td>
                        <td data-label="Tipo">
                            <span class="tipo-badge"><?= htmlspecialchars($inv['tipo_intervencao']) ?></span><br>
                            <span style="font-size:11px; color:#7f8c8d;"><?= htmlspecialchars($inv['modo_intervencao']) ?></span>
                        </td>
                        <td data-label="Causa"><?= nl2br(htmlspecialchars($inv['causa'])) ?></td>
                        <td data-label="Trabalhos"><?= nl2br(htmlspecialchars($inv['trabalhos_executados'])) ?></td>
                        <td data-label="Técnico"><?= htmlspecialchars($inv['nome_tecnico']) ?></td>
                        <td class="td-acoes" style="text-align:center; white-space:nowrap;">
                            <button type="button" onclick='duplicarModal(<?= json_encode($inv, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)' class="btn-acao btn-duplicar" title="Duplicar para novo ano"><i class="fa-solid fa-copy"></i></button>
                            <button type="button" onclick='editarModal(<?= json_encode($inv, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)' class="btn-acao" title="Editar"><i class="fa-solid fa-pen"></i></button>
                            <?php if($is_admin): ?>
                            <button type="button" onclick="confirmarApagar(<?= $inv['id'] ?>)" class="btn-acao btn-del" title="Apagar"><i class="fa-solid fa-trash"></i></button>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>

    <div class="modal" id="modalForm">
        <div class="modal-content">
            <h2 id="modalTitle" style="margin-top:0; color:#2c3e50; border-bottom:1px solid #eee; padding-bottom:10px;">Nova Intervenção</h2>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                <input type="hidden" name="id_intervencao" id="id_intervencao" value="">
                
                <div class="form-grid">
                    <div>
                        <label>Data</label>
                        <input type="date" name="data_intervencao" id="data_intervencao" value="<?= date('Y-m-d') ?>" required>
                    </div>
                    <div>
                        <label>Hora</label>
                        <input type="time" name="hora_intervencao" id="hora_intervencao" value="<?= date('H:i') ?>" required>
                    </div>
                    
                    <div>
                        <label>Tipo de Intervenção</label>
                        <select name="tipo_intervencao" id="tipo_intervencao" required>
                            <option value="Manutenção (M)">Manutenção (M)</option>
                            <option value="Assistência Técnica (AT)">Assistência Técnica (AT)</option>
                            <option value="Alteração (A)">Alteração (A)</option>
                            <option value="Reparação (R)">Reparação (R)</option>
                            <option value="Falso Alarme (FA)">Falso Alarme (FA)</option>
                            <option value="Outra (O)">Outra (O)</option>
                        </select>
                    </div>
                    <div>
                        <label>Modo</label>
                        <select name="modo_intervencao" id="modo_intervencao" required>
                            <option value="Presencial">Presencial</option>
                            <option value="Remota">Remota</option>
                        </select>
                    </div>

                    <div class="form-full">
                        <label>ID do Responsável pela intervenção (Alvará C/Reg. Prévio)</label>
                        <input type="text" name="alvara_responsavel" id="alvara_responsavel">
                    </div>

                    <div class="form-full">
                        <label>Causa da Intervenção</label>
                        <textarea name="causa" id="causa" rows="2" placeholder="Ex: Manutenção preventiva anual"></textarea>
                    </div>

                    <div class="form-full">
                        <label>Trabalhos Executados</label>
                        <textarea name="trabalhos_executados" id="trabalhos_executados" rows="3" placeholder="Ex: Teste funcionais; Verificação de configurações"></textarea>
                    </div>

                    <div>
                        <label>Nº Relatório Técnico (opcional)</label>
                        <input type="text" name="num_relatorio" id="num_relatorio">
                    </div>
                    <div>
                        <label>Nome Técnico Executante</label>
                        <input type="text" name="nome_tecnico" id="nome_tecnico" required value="<?= htmlspecialchars($primeiro_nome) ?>">
                    </div>
                </div>

                <div class="modal-btns">
                    <button type="button" onclick="document.getElementById('modalForm').style.display='none'" style="background:#f1f2f6; color:#576574;">Cancelar</button>
                    <button type="submit" name="btn_guardar_intervencao" style="background:#34495e; color:#fff;">Gravar no Livro</button>
                </div>
            </form>
        </div>
    </div>

    <div class="modal" id="modalApagar">
        <div class="modal-content" style="max-width: 400px; text-align:center;">
            <h3 style="color:#e74c3c;"><i class="fa-solid fa-triangle-exclamation"></i> Apagar Linha</h3>
            <p>Tem a certeza que deseja eliminar esta linha do livro de registos?</p>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                <input type="hidden" name="id_apagar" id="id_apagar_input">
                <div class="modal-btns">
                    <button type="button" onclick="document.getElementById('modalApagar').style.display='none'" style="background:#f1f2f6; color:#576574;">Cancelar</button>
                    <button type="submit" name="btn_apagar_intervencao" style="background:#e74c3c; color:#fff;">Eliminar</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function abrirModal() {
            document.getElementById('modalTitle').innerText = 'Nova Intervenção';
            document.getElementById('id_intervencao').value = '';
            document.getElementById('data_intervencao').value = '<?= date('Y-m-d') ?>';
            document.getElementById('hora_intervencao').value = '<?= date('H:i') ?>';
            document.getElementById('causa').value = '';
            document.getElementById('trabalhos_executados').value = '';
            document.getElementById('num_relatorio').value = '';
            document.getElementById('alvara_responsavel').value = '';
            document.getElementById('nome_tecnico').value = '<?= htmlspecialchars($primeiro_nome) ?>';
            document.getElementById('modalForm').style.display = 'flex';
        }

        function editarModal(inv) {
            document.getElementById('modalTitle').innerText = 'Editar Intervenção';
            document.getElementById('id_intervencao').value = inv.id;
            document.getElementById('data_intervencao').value = inv.data_intervencao;
            document.getElementById('hora_intervencao').value = inv.hora_intervencao;
            document.getElementById('tipo_intervencao').value = inv.tipo_intervencao;
            document.getElementById('modo_intervencao').value = inv.modo_intervencao;
            document.getElementById('alvara_responsavel').value = inv.alvara_responsavel;
            document.getElementById('causa').value = inv.causa;
            document.getElementById('trabalhos_executados').value = inv.trabalhos_executados;
            document.getElementById('num_relatorio').value = inv.num_relatorio;
            document.getElementById('nome_tecnico').value = inv.nome_tecnico;
            document.getElementById('modalForm').style.display = 'flex';
        }

        function duplicarModal(inv) {
            document.getElementById('modalTitle').innerText = 'Nova Intervenção (Duplicar anterior)';
            document.getElementById('id_intervencao').value = ''; // Fica vazio para forçar uma nova linha
            document.getElementById('data_intervencao').value = '<?= date('Y-m-d') ?>'; // Atualiza para hoje
            document.getElementById('hora_intervencao').value = '<?= date('H:i') ?>'; // Atualiza para a hora de agora
            
            // Copia o resto dos dados idênticos
            document.getElementById('tipo_intervencao').value = inv.tipo_intervencao;
            document.getElementById('modo_intervencao').value = inv.modo_intervencao;
            document.getElementById('alvara_responsavel').value = inv.alvara_responsavel;
            document.getElementById('causa').value = inv.causa;
            document.getElementById('trabalhos_executados').value = inv.trabalhos_executados;
            document.getElementById('num_relatorio').value = inv.num_relatorio;
            document.getElementById('nome_tecnico').value = '<?= htmlspecialchars($primeiro_nome) ?>';
            
            document.getElementById('modalForm').style.display = 'flex';
        }

        function confirmarApagar(id) {
            document.getElementById('id_apagar_input').value = id;
            document.getElementById('modalApagar').style.display = 'flex';
        }

        window.onclick = function(e) { 
            if(e.target.classList.contains('modal')) { e.target.style.display = 'none'; } 
            document.querySelectorAll('.user-menu.aberto').forEach(function(m){ if(!m.contains(e.target)) m.classList.remove('aberto'); });
        };
    </script>
</body>
</html>