<?php
session_start();
require_once 'db.php';

// REGRA DE OURO: Funções centralizadas
if (file_exists('functions.php')) { 
    require_once 'functions.php'; 
} else { 
    function registrarLog($pdo, $acao, $modulo) { return true; } 
    function gerar_csrf_token() { return ''; }
    function validar_csrf_token($t) { return true; }
}

// REGRA DE OURO 1: Validação de sessão
if (!isset($_SESSION['user_id'])) { 
    header("Location: index.php"); 
    exit; 
}

$perfil_user = $_SESSION['perfil'] ?? '';
$is_admin = in_array($perfil_user, ['admin', 'administrativo']);

// REGRA DE OURO 2: Acesso Restrito pela Matriz
$stmt_perm = $pdo->prepare("SELECT modulo FROM permissoes WHERE grupo = :perfil AND pode_aceder = 1");
$stmt_perm->execute(['perfil' => $perfil_user]);
$meus_acessos = $stmt_perm->fetchAll(PDO::FETCH_COLUMN);

if (!$is_admin && !in_array('termos', $meus_acessos) && !in_array('termos_cctv', $meus_acessos)) {
    header("Location: index.php?erro=sem_permissao");
    exit;
}

// 🛡️ REGRA DE OURO (SEGURANÇA): GERAR TOKEN CSRF
$csrf_token = gerar_csrf_token();

$mensagem = ''; 
$tipo_mensagem = '';

if (isset($_GET['sucesso'])) {
    if ($_GET['sucesso'] == 'apagado') {
        $mensagem = "Instalação de CCTV removida com sucesso.";
        $tipo_mensagem = "sucesso";
    } else {
        $mensagem = "Operação realizada com sucesso!";
        $tipo_mensagem = "sucesso";
    }
}

// --- LÓGICA PARA APAGAR TERMO (COM PASSWORD E CSRF) ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['btn_confirmar_apagar'])) { 
    
    if (!validar_csrf_token($_POST['csrf_token'] ?? '')) {
        die("Tentativa bloqueada por segurança (CSRF Inválido). Volte atrás e recarregue a página.");
    }

    if (!$is_admin) { 
        $mensagem = "Erro: Sem permissão para apagar.";  
        $tipo_mensagem = "erro"; 
    } else { 
        $id_termo = filter_input(INPUT_POST, 'id_termo_apagar', FILTER_VALIDATE_INT); 
        $pass_introduzida = $_POST['pass_seguranca'] ?? '';

        $stmt_pass = $pdo->prepare("SELECT valor FROM configuracoes WHERE chave = 'password_apagar' LIMIT 1"); 
        $stmt_pass->execute(); 
        $pass_config = $stmt_pass->fetchColumn();

        if ($pass_introduzida !== $pass_config || empty($pass_introduzida)) { 
            $mensagem = "Password de segurança incorreta.";  
            $tipo_mensagem = "erro"; 
        } else { 
            try {
                $pdo->beginTransaction();

                // Buscar Referencia para o Log
                $stmt_ref = $pdo->prepare("SELECT referencia_termo FROM termos_cctv WHERE id = ?");
                $stmt_ref->execute([$id_termo]);
                $ref_apagar = $stmt_ref->fetchColumn() ?: $id_termo;

                // Apagar as intervenções do livro primeiro para evitar "lixo" (se a tabela existir)
                try { $pdo->prepare("DELETE FROM cctv_intervencoes WHERE id_termo_cctv = ?")->execute([$id_termo]); } catch (Exception $e2) {}
                // Apagar o Termo principal (as câmaras estão no próprio termo, em camaras_json)
                $pdo->prepare("DELETE FROM termos_cctv WHERE id = ?")->execute([$id_termo]); 

                $pdo->commit();
                registrarLog($pdo, "Apagou Termo de CCTV ($ref_apagar)", "Termos CCTV"); 
                header("Location: termos_cctv.php?sucesso=apagado"); 
                exit; 
            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $mensagem = "Erro ao apagar: " . $e->getMessage(); $tipo_mensagem = "erro"; 
            }
        } 
    } 
}

// --- QUERY PRINCIPAL ---
$sql = "SELECT t.id, t.referencia_termo, t.data_emissao, t.nome_obra, t.marca_gravador, t.modelo_gravador, t.camaras_json, c.nome as nome_cliente
        FROM termos_cctv t
        LEFT JOIN clientes c ON t.id_cliente = c.id
        ORDER BY t.data_emissao DESC, t.id DESC";

$stmt = $pdo->query($sql);
$instalacoes = $stmt->fetchAll(PDO::FETCH_ASSOC);

// N.º de câmaras de cada instalação (a partir do JSON)
foreach ($instalacoes as &$inst_c) {
    $cams = json_decode($inst_c['camaras_json'] ?? '', true);
    $n = 0;
    if (is_array($cams)) {
        foreach ($cams as $cm) {
            if (is_array($cm) && (trim($cm['marca'] ?? '') !== '' || trim($cm['modelo'] ?? '') !== '')) $n++;
        }
    }
    $inst_c['n_camaras'] = $n;
}
unset($inst_c);

?>
<!DOCTYPE html>
<html lang="pt-PT">
<head>
    <meta charset="UTF-8">
    <title>Videovigilância CCTV - MAB</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { font-family: system-ui, sans-serif; background: #f7f9fc; margin: 0; font-size: 13px; padding-bottom: 50px; }
        * { box-sizing: border-box; }
        
        .container { max-width: 1400px; margin: 30px auto; padding: 0 20px; }
        .page-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px; }
        .page-title { margin: 0; color: #2c3e50; font-size: 24px; font-weight: 800; display: flex; align-items: center; gap: 10px; }
        
        .btn-primary { background: #34495e; color: #fff; padding: 12px 20px; border-radius: 8px; border: none; cursor: pointer; font-weight: bold; text-decoration: none; display: inline-flex; align-items: center; gap: 8px; transition: 0.2s;}
        .btn-primary:hover { background: #2c3e50; color: #fff;}

        .card { background: #fff; border-radius: 12px; border: 1px solid #e1e5ea; overflow: hidden; box-shadow: 0 4px 15px rgba(0,0,0,0.03); }
        .table-responsive { width: 100%; overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; min-width: 900px; }
        th { text-align: left; padding: 12px 15px; background: #f8f9fa; font-size: 11px; text-transform: uppercase; color: #7f8c8d; border-bottom: 1px solid #eee; }
        td { padding: 12px 15px; border-bottom: 1px solid #f1f2f6; vertical-align: middle; }
        tbody tr:hover { background-color: #fcfcfc; }

        .btn-acao { background: #f1f2f6; border: none; cursor: pointer; font-size: 15px; color: #576574; transition: 0.2s; width: 32px; height: 32px; border-radius: 6px; display: inline-flex; align-items: center; justify-content: center; text-decoration: none; }
        .btn-acao:hover { background: #e1e5ea; color: #2c3e50; }
        .btn-imprimir { color: #3498db; background: #f0f8ff; }
        .btn-imprimir:hover { color: #2980b9; background: #d6eaf8; }
        
        .btn-declaracao { color: #27ae60; background: #eafaf1; }
        .btn-declaracao:hover { color: #1e8449; background: #d5f5e3; }
        
        .btn-manual { color: #2471a3; background: #eaf2f8; }
        .btn-manual:hover { color: #1a5276; background: #d4e6f1; }
        .btn-psp { color: #c0392b; background: #fdedec; }
        .btn-psp:hover { color: #922b21; background: #fadbd8; }
        .btn-livro { color: #d35400; background: #fcf3cf; }
        .btn-livro:hover { color: #e67e22; background: #f9e79f; }
        
        .btn-del { color: #e74c3c; background: #fdeded; }
        .btn-del:hover { background: #fadbd8; color: #c0392b; }
        
        .id-badge { font-weight: 700; color: #34495e; background: #eaeff5; padding: 4px 8px; border-radius: 6px; border: 1px solid #d4deea; font-size: 12px; }

        .alert { padding: 15px; border-radius: 8px; margin-bottom: 20px; text-align: center; font-weight: 600; }
        .alert-erro { background: #fdeded; color: #c0392b; border: 1px solid #f9cccc; }
        .alert-sucesso { background: #eafaf1; color: #27ae60; border: 1px solid #d5f5e3; }

        /* MODAIS */
        .modal { display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.6); z-index:10000; align-items:center; justify-content:center; backdrop-filter: blur(2px); }
        .modal-content { background:#fff; padding:30px; border-radius:15px; width:90%; max-width:500px; text-align:center; box-shadow: 0 20px 50px rgba(0,0,0,0.2); }
        .modal-content input[type="password"] { width: 100%; padding: 12px; border: 1px solid #dcdde1; border-radius: 8px; margin: 15px 0; outline: none; }
        .modal-content input[type="password"]:focus { border-color: #34495e; }

        .modal-btns { display: flex; gap: 10px; margin-top: 15px;}
        .modal-btns button { flex: 1; padding: 12px; border: none; border-radius: 8px; font-weight: 600; cursor: pointer; transition:0.2s;}
        .btn-cancelar { background: #f1f2f6; color: #576574; }
        
        .text-center { text-align: center !important; }
        
        @media (max-width: 1024px) {
            .container { margin: 20px auto; }
        }
        @media (max-width: 768px) {
            .page-header { flex-direction: column; gap: 15px; align-items: flex-start; }
            .page-title { font-size: 20px; }
            /* Tabela passa a cartões */
            table { min-width: 0; }
            thead { display: none; }
            table, tbody, tr, td { display: block; width: 100%; }
            .card { background: transparent; border: none; box-shadow: none; }
            tbody tr { background: #fff; border: 1px solid #e1e5ea; border-radius: 12px; margin-bottom: 12px; padding: 8px 4px; }
            td { border: none; padding: 6px 12px; display: flex; justify-content: space-between; align-items: center; gap: 10px; text-align: right !important; }
            td[data-label]::before { content: attr(data-label); font-size: 11px; text-transform: uppercase; color: #95a5a6; font-weight: 700; text-align: left; }
            td.td-acoes { border-top: 1px solid #f1f2f6; margin-top: 6px; padding-top: 10px; }
            td.td-acoes .acoes { width: 100%; justify-content: flex-start !important; }
            .btn-acao { width: 38px; height: 38px; }
        }
        @media (max-width: 560px) {
            .container { padding: 0 12px; }
            .btn-primary { width: 100%; justify-content: center; }
        }
    </style>
</head>
<body>

    <?php include 'header.php'; ?>

    <div class="container">
        <?php if ($mensagem): ?>
            <div class="alert <?= $tipo_mensagem === 'sucesso' ? 'alert-sucesso' : 'alert-erro' ?>">
                <?= $tipo_mensagem === 'erro' ? '<i class="fa-solid fa-triangle-exclamation"></i>' : '<i class="fa-solid fa-check-circle"></i>' ?> 
                <?= htmlspecialchars($mensagem, ENT_QUOTES, 'UTF-8') ?>
            </div>
        <?php endif; ?>

        <div class="page-header">
            <h1 class="page-title"><i class="fa-solid fa-video" style="color:#34495e;"></i> Videovigilância (CCTV)</h1>
            <a href="termo_cctv_form.php" class="btn-primary"><i class="fa-solid fa-plus-circle"></i> Novo Registo de CCTV</a>
        </div>

        <div class="card">
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th width="120">ID / Referência</th>
                            <th width="100">Data Registo</th>
                            <th>Obra / Localização</th>
                            <th>Cliente</th>
                            <th>Gravador</th>
                            <th width="80" class="text-center">Câmaras</th>
                            <th width="320" class="text-center">Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($instalacoes)): ?>
                            <tr><td colspan="7" class="text-center" style="padding: 40px; color: #7f8c8d;">Nenhum registo de CCTV encontrado.</td></tr>
                        <?php else: ?>
                            <?php foreach($instalacoes as $inst): ?>
                            <tr>
                                <td data-label="Referência"><span class="id-badge"><?= htmlspecialchars($inst['referencia_termo'] ?: 'N/D') ?></span></td>
                                <td data-label="Data"><i class="fa-regular fa-calendar" style="color:#bdc3c7;"></i> <?= date('d/m/Y', strtotime($inst['data_emissao'])) ?></td>
                                <td data-label="Obra"><strong style="color:#2c3e50;"><?= htmlspecialchars($inst['nome_obra'] ?? 'N/D') ?></strong></td>
                                <td data-label="Cliente" style="color:#576574;"><?= htmlspecialchars($inst['nome_cliente'] ?? 'N/D') ?></td>
                                <td data-label="Gravador"><?= htmlspecialchars(trim(($inst['marca_gravador'] ?? '') . ' ' . ($inst['modelo_gravador'] ?? '')) ?: '-') ?></td>
                                <td data-label="Câmaras" class="text-center"><?= (int)$inst['n_camaras'] ?></td>
                                <td class="text-center td-acoes">
                                    <div class="acoes" style="display:flex; justify-content:center; gap:8px; flex-wrap:wrap;">
                                        <a href="termo_cctv_declaracao_pdf.php?id=<?= $inst['id'] ?>" target="_blank" class="btn-acao btn-declaracao" title="Declaração de Instalação (PSP)"><i class="fa-solid fa-certificate"></i></a>
                                        <a href="termo_cctv_livro.php?id=<?= $inst['id'] ?>" class="btn-acao btn-livro" title="Livro de Registos (LRS) - intervenções e PDF"><i class="fa-solid fa-book"></i></a>
                                        <a href="termo_cctv_manual_pdf.php?id=<?= $inst['id'] ?>" target="_blank" class="btn-acao btn-manual" title="Imprimir Manual do Sistema"><i class="fa-solid fa-book-open"></i></a>
                                        <a href="termo_cctv_pdf.php?id=<?= $inst['id'] ?>" target="_blank" class="btn-acao btn-imprimir" title="Imprimir Ficha de Instalação / Auto de Entrega"><i class="fa-solid fa-print"></i></a>
                                        <a href="termo_cctv_form.php?id=<?= $inst['id'] ?>" class="btn-acao" title="Editar Registo"><i class="fa-solid fa-pen-to-square"></i></a>
                                        
                                        <?php if($is_admin): ?>
                                            <button type="button" onclick="abrirModalApagar(<?= $inst['id'] ?>, '<?= addslashes(htmlspecialchars($inst['referencia_termo'])) ?>')" class="btn-acao btn-del" title="Eliminar Definitivamente"><i class="fa-solid fa-trash-can"></i></button>
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
    </div>

    <?php if ($is_admin): ?>
    <div class="modal" id="modalApagar">
        <div class="modal-content">
            <h3 style="color:#e74c3c; margin-top:0;"><i class="fa-solid fa-triangle-exclamation"></i> Eliminar Registo</h3>
            <p>Deseja apagar a instalação de CCTV:<br><strong id="txt_modal" style="color: #2c3e50; font-size: 16px; margin-top: 10px; display: inline-block;"></strong>?</p>
            <p style="font-size: 12px; color: #7f8c8d; line-height: 1.4; margin-bottom: 20px;">Atenção: Ao apagar, as câmaras, os dados técnicos e as intervenções do livro de registos serão permanentemente eliminados.</p>
            
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                <input type="hidden" name="id_termo_apagar" id="id_termo_m">
                <input type="password" name="pass_seguranca" required placeholder="Password de Segurança">
                
                <div class="modal-btns">
                    <button type="button" class="btn-cancelar" onclick="fecharModais()">Cancelar</button>
                    <button type="submit" name="btn_confirmar_apagar" style="background:#e74c3c; color:#fff;">Eliminar Definitivamente</button>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <script>
        function abrirModalApagar(id, ref) {
            document.getElementById('id_termo_m').value = id;
            document.getElementById('txt_modal').innerText = ref;
            document.getElementById('modalApagar').style.display = 'flex';
            document.querySelector('#modalApagar input[type="password"]').value = '';
            document.querySelector('#modalApagar input[type="password"]').focus();
        }

        function fecharModais() { 
            document.querySelectorAll('.modal').forEach(m => m.style.display = 'none');
        }

        // Fechar ao clicar no fundo escuro
        window.onclick = function(e) { 
            if(e.target.classList.contains('modal')) { fecharModais(); } 
        };
    </script>
</body>
</html>
