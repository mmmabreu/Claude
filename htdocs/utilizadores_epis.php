<?php
session_start();
require_once 'db.php';

// Validação de sessão
if (!isset($_SESSION['user_id'])) { header("Location: index.php"); exit; }

$perfil_user = $_SESSION['perfil'] ?? '';
$is_admin = in_array($perfil_user, ['admin', 'administrativo']);
if (!$is_admin) { header("Location: utilizadores.php"); exit; }

$id_user = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$id_user) { header("Location: utilizadores.php"); exit; }

// --- 1. CRIAR NOVA TABELA DE HISTÓRICO ---
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS utilizadores_epis_historico (
        id INT AUTO_INCREMENT PRIMARY KEY,
        id_utilizador INT NOT NULL,
        ref VARCHAR(10),
        designacao VARCHAR(255),
        riscos VARCHAR(50),
        data_entrega DATE NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
} catch (Exception $e) {}

// Buscar o Utilizador
$stmt_u = $pdo->prepare("SELECT nome, perfil FROM utilizadores WHERE id = ?");
$stmt_u->execute([$id_user]);
$trabalhador = $stmt_u->fetch(PDO::FETCH_ASSOC);

if (!$trabalhador) { header("Location: utilizadores.php"); exit; }

// Tabela de EPIs Standard Oficial
$epis_standard = [
    ['ref' => '1', 'des' => 'Capacete de Proteção com suspensão e jugular', 'riscos' => '3, 11'],
    ['ref' => '2', 'des' => 'Calçado de segurança nº40', 'riscos' => '5; 6'],
    ['ref' => '3', 'des' => 'Colete de alta visibilidade', 'riscos' => '17'],
    ['ref' => '4', 'des' => 'Boné de proteção', 'riscos' => '3, 11'],
    ['ref' => '5', 'des' => 'Luvas de proteção', 'riscos' => '5; 12; 14'],
    ['ref' => '6', 'des' => 'Calça de trabalho', 'riscos' => '12; 13; 18'],
    ['ref' => '7', 'des' => 'Sweatshirt', 'riscos' => '12; 13; 18'],
    ['ref' => '8', 'des' => 'T-shirt: 5t', 'riscos' => '12; 13; 18'],
    ['ref' => '9', 'des' => 'Óculos de proteção', 'riscos' => '13'],
    ['ref' => '10', 'des' => 'Máscara de Proteção', 'riscos' => '16'],
    ['ref' => '11', 'des' => 'Protetor auricular', 'riscos' => '19'],
    ['ref' => '12', 'des' => 'Capa impermeável', 'riscos' => '18'],
    ['ref' => '13', 'des' => 'Arnês de segurança', 'riscos' => '1']
];

$mensagem = '';

// --- LÓGICA DE GRAVAÇÃO DO HISTÓRICO ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['btn_gravar_entrega'])) {
        try {
            $pdo->beginTransaction();
            $data_entrega = $_POST['data_entrega'] ?? date('Y-m-d');
            $categoria = $_POST['categoria'] ?? '';
            
            if (!empty($_POST['epis_selecionados'])) {
                $stmt_ins = $pdo->prepare("INSERT INTO utilizadores_epis_historico (id_utilizador, ref, designacao, riscos, data_entrega) VALUES (?, ?, ?, ?, ?)");
                
                foreach ($_POST['epis_selecionados'] as $ref_selecionada) {
                    // Encontrar os dados deste EPI no array standard
                    $epi_info = array_filter($epis_standard, fn($e) => $e['ref'] == $ref_selecionada);
                    $epi_info = reset($epi_info);
                    
                    if ($epi_info) {
                        $stmt_ins->execute([$id_user, $epi_info['ref'], $epi_info['des'], $epi_info['riscos'], $data_entrega]);
                    }
                }
            }
            
            $pdo->commit();
            header("Location: utilizadores_epis.php?id=$id_user&gravado=1&cat=" . urlencode($categoria));
            exit;
        } catch (Exception $e) {
            $pdo->rollBack();
            $mensagem = "Erro ao registar entrega: " . $e->getMessage();
        }
    }
    
    // Apagar um registo do histórico
    if (isset($_POST['btn_apagar_registo'])) {
        $id_apagar = filter_input(INPUT_POST, 'id_apagar', FILTER_VALIDATE_INT);
        if ($id_apagar) {
            $pdo->prepare("DELETE FROM utilizadores_epis_historico WHERE id = ? AND id_utilizador = ?")->execute([$id_apagar, $id_user]);
            header("Location: utilizadores_epis.php?id=$id_user&apagado=1");
            exit;
        }
    }
}

// Carregar Histórico ordenado numericamente pela referência e depois pela data
$stmt_get = $pdo->prepare("SELECT * FROM utilizadores_epis_historico WHERE id_utilizador = ? ORDER BY CAST(ref AS UNSIGNED) ASC, data_entrega DESC");
$stmt_get->execute([$id_user]);
$historico_epis = $stmt_get->fetchAll(PDO::FETCH_ASSOC);

$cat_trabalhador = $_GET['cat'] ?? ($trabalhador['perfil'] == 'funcionario' ? 'Eletricista Oficial' : ucfirst($trabalhador['perfil']));
?>
<!DOCTYPE html>
<html lang="pt-PT">
<head>
    <meta charset="UTF-8">
    <title>Registo de Posse de EPI's</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { font-family: system-ui, sans-serif; background-color: #f7f9fc; margin: 0; padding-bottom: 80px; color: #333; }
        .topbar { background: #fff; padding: 15px 30px; box-shadow: 0 4px 15px rgba(0,0,0,0.05); display: flex; justify-content: space-between; position: sticky; top:0; z-index: 10;}
        .btn-back { color: #576574; text-decoration: none; font-weight: bold; }
        .btn-back:hover { color: #2c3e50; }
        
        .container { max-width: 1000px; margin: 30px auto; padding: 0 15px; }
        .page-title { color: #2c3e50; font-size: 24px; margin-bottom: 20px; font-weight: 800; display:flex; align-items:center; gap:10px;}
        .page-title i { color: #27ae60; }
        
        .grid-layout { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
        
        .card { background: #fff; border-radius: 12px; padding: 25px; box-shadow: 0 4px 15px rgba(0,0,0,0.04); border: 1px solid #e1e5ea; }
        .card h3 { margin-top: 0; color: #34495e; font-size: 16px; border-bottom: 2px solid #f1f2f6; padding-bottom: 10px; margin-bottom: 20px; }
        
        /* Formulário Nova Entrega */
        .form-group { margin-bottom: 15px; }
        .form-group label { display: block; font-weight: bold; color: #576574; font-size: 12px; margin-bottom: 5px; text-transform: uppercase;}
        .form-group input[type="text"], .form-group input[type="date"] { width: 100%; border: 1px solid #dcdde1; padding: 10px; border-radius: 6px; outline: none; font-family: inherit; font-size: 14px;}
        .form-group input:focus { border-color: #27ae60; }
        
        .epi-list { max-height: 400px; overflow-y: auto; border: 1px solid #e1e5ea; border-radius: 6px; padding: 10px; background: #fdfdfd;}
        .epi-item { display: flex; align-items: center; gap: 10px; padding: 8px; border-bottom: 1px solid #f1f2f6; transition: 0.2s; }
        .epi-item:last-child { border-bottom: none; }
        .epi-item:hover { background: #f4f6f7; }
        .epi-item input[type="checkbox"] { transform: scale(1.2); cursor: pointer; }
        .epi-item label { margin: 0; cursor: pointer; font-size: 14px; flex: 1; }
        .epi-item .ref-badge { background: #e1e5ea; padding: 2px 6px; border-radius: 4px; font-size: 11px; font-weight: bold; color: #576574;}
        
        /* Tabela Histórico */
        table { width: 100%; border-collapse: collapse; }
        th { background: #f8f9fa; border-bottom: 2px solid #e1e5ea; padding: 10px; font-size: 11px; color: #576574; text-transform: uppercase; text-align: left; }
        td { border-bottom: 1px solid #f1f2f6; padding: 10px; font-size: 13px; color: #2c3e50; }
        tr:hover td { background: #fcfcfc; }
        
        .btn-del { background: #fdeded; color: #e74c3c; border: none; padding: 6px 10px; border-radius: 4px; cursor: pointer; transition: 0.2s;}
        .btn-del:hover { background: #fadbd8; }

        .btn-bar { position: fixed; bottom: 20px; right: 20px; display: flex; gap: 10px; z-index: 100;}
        .btn { padding: 12px 25px; border-radius: 8px; border: none; font-weight: bold; cursor: pointer; text-decoration: none; color: #fff; font-size: 14px; box-shadow: 0 4px 10px rgba(0,0,0,0.1); display: inline-flex; align-items: center; gap: 8px;}
        .btn-save { background: #27ae60; }
        .btn-save:hover { background: #219653; }
        .btn-print { background: #576574; }
        .btn-print:hover { background: #34495e; }
        
        .alert { padding: 15px; border-radius: 6px; background: #eafaf1; color: #27ae60; border: 1px solid #c8e6c9; margin-bottom: 20px; font-weight: bold; text-align: center; }

        @media (max-width: 768px) {
            .grid-layout { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>

    <div class="topbar">
        <a href="utilizadores.php" class="btn-back"><i class="fa-solid fa-arrow-left"></i> Voltar à Equipa</a>
    </div>

    <div class="container">
        <?php if (isset($_GET['gravado'])): ?><div class="alert">Nova entrega registada com sucesso no histórico!</div><?php endif; ?>
        <?php if (isset($_GET['apagado'])): ?><div class="alert" style="background:#fff3e0; color:#e67e22; border-color:#f5cba7;">Registo apagado do histórico.</div><?php endif; ?>
        <?php if ($mensagem): ?><div class="alert" style="background:#fdeded; color:#c0392b;"><?= $mensagem ?></div><?php endif; ?>

        <h1 class="page-title"><i class="fa-solid fa-helmet-safety"></i> Gestão de EPI's - <?= htmlspecialchars($trabalhador['nome']) ?></h1>

        <form method="POST" id="epiForm">
            <div class="grid-layout">
                
                <!-- ZONA 1: REGISTAR NOVA ENTREGA -->
                <div class="card">
                    <h3><i class="fa-solid fa-plus-circle" style="color:#27ae60;"></i> Registar Nova Entrega</h3>
                    
                    <div class="form-group">
                        <label>Categoria na Declaração</label>
                        <input type="text" name="categoria" id="categoriaInput" value="<?= htmlspecialchars($cat_trabalhador) ?>" required>
                    </div>

                    <div class="form-group">
                        <label>Data de Entrega</label>
                        <input type="date" name="data_entrega" value="<?= date('Y-m-d') ?>" required>
                    </div>

                    <div class="form-group">
                        <label>Selecione os EPI's Entregues nesta data:</label>
                        <div class="epi-list">
                            <?php foreach ($epis_standard as $epi): ?>
                            <div class="epi-item">
                                <input type="checkbox" name="epis_selecionados[]" value="<?= $epi['ref'] ?>" id="epi_<?= $epi['ref'] ?>">
                                <span class="ref-badge"><?= $epi['ref'] ?></span>
                                <label for="epi_<?= $epi['ref'] ?>"><?= htmlspecialchars($epi['des']) ?></label>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <button type="submit" name="btn_gravar_entrega" class="btn btn-save" style="width: 100%; justify-content: center; margin-top:10px;">
                        <i class="fa-solid fa-check"></i> Adicionar ao Histórico
                    </button>
                </div>

                <!-- ZONA 2: HISTÓRICO GERAL -->
                <div class="card">
                    <h3><i class="fa-solid fa-clock-rotate-left" style="color:#3498db;"></i> Histórico de Entregas</h3>
                    <div style="max-height: 520px; overflow-y: auto;">
                        <table>
                            <thead>
                                <tr>
                                    <th style="width: 25%;">Data</th>
                                    <th>EPI Entregue</th>
                                    <th style="width: 15%; text-align:center;">Ação</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($historico_epis)): ?>
                                    <tr><td colspan="3" style="text-align:center; padding:30px; color:#95a5a6;">Sem histórico registado.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($historico_epis as $hist): ?>
                                    <tr>
                                        <td><strong><?= date('d/m/Y', strtotime($hist['data_entrega'])) ?></strong></td>
                                        <td><span style="font-size:11px; color:#7f8c8d;">Refª <?= $hist['ref'] ?>:</span><br><?= htmlspecialchars($hist['designacao']) ?></td>
                                        <td style="text-align:center;">
                                            <button type="button" class="btn-del" title="Apagar Registo" onclick="apagarRegisto(<?= $hist['id'] ?>)"><i class="fa-solid fa-trash-can"></i></button>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

            </div>
        </form>

        <!-- Formulário invisível para apagar -->
        <form method="POST" id="formApagar">
            <input type="hidden" name="id_apagar" id="inputApagar">
            <input type="hidden" name="btn_apagar_registo" value="1">
        </form>

        <!-- BARRA FLUTUANTE APENAS PARA IMPRESSÃO -->
        <div class="btn-bar">
            <button type="button" class="btn btn-print" onclick="abrirPDF()"><i class="fa-solid fa-print"></i> Gerar Declaração Atualizada (PDF)</button>
        </div>
    </div>

    <script>
        function apagarRegisto(id) {
            if (confirm('Deseja apagar este registo do histórico?')) {
                document.getElementById('inputApagar').value = id;
                document.getElementById('formApagar').submit();
            }
        }

        function abrirPDF() {
            let cat = encodeURIComponent(document.getElementById('categoriaInput').value);
            window.open('utilizadores_epis_pdf.php?id=<?= $id_user ?>&cat=' + cat, '_blank');
        }
    </script>
</body>
</html>