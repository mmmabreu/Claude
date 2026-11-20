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

if (!$is_admin && !in_array('termos', $meus_acessos) && !in_array('termos_eletrico', $meus_acessos)) {
    header("Location: index.php?erro=sem_permissao");
    exit;
}

$nome_completo = $_SESSION['nome'] ?? 'Utilizador';
$primeiro_nome = explode(' ', trim($nome_completo))[0];

// 🛡️ REGRA DE OURO (SEGURANÇA): GERAR TOKEN CSRF
$csrf_token = gerar_csrf_token();

$mensagem = '';
$tipo_mensagem = '';

if (isset($_GET['sucesso'])) {
    if ($_GET['sucesso'] == 'apagado') {
        $mensagem = "Registo removido com sucesso.";
        $tipo_mensagem = "sucesso";
    } elseif ($_GET['sucesso'] == 'duplicado') {
        $mensagem = "Termo elétrico e respetivas instalações duplicados com sucesso! (Data atualizada)";
        $tipo_mensagem = "sucesso";
    } elseif ($_GET['sucesso'] == 'duplicado_vistoria') {
        $mensagem = "Vistoria de Quadros duplicada com sucesso! (Data atualizada)";
        $tipo_mensagem = "sucesso";
    } else {
        $mensagem = "Operação realizada com sucesso!";
        $tipo_mensagem = "sucesso";
    }
}

// --- LÓGICA PARA APAGAR TERMO NORMAL ---
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
                $stmt_ref = $pdo->prepare("SELECT referencia_termo FROM termos_eletrico WHERE id = ?");
                $stmt_ref->execute([$id_termo]);
                $ref_apagar = $stmt_ref->fetchColumn() ?: $id_termo;

                $pdo->prepare("DELETE FROM termos_eletrico_instalacoes WHERE id_termo = ?")->execute([$id_termo]);
                $pdo->prepare("DELETE FROM termos_eletrico WHERE id = ?")->execute([$id_termo]);
                $pdo->commit();
                registrarLog($pdo, "Apagou Termo Elétrico ($ref_apagar)", "Termos Elétricos");
                header("Location: termos_eletrico.php?sucesso=apagado");
                exit;
            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $mensagem = "Erro ao apagar: " . $e->getMessage(); $tipo_mensagem = "erro";
            }
        }
    }
}

// --- LÓGICA PARA APAGAR VISTORIA DE QUADROS ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['btn_confirmar_apagar_vistoria'])) {
    if (!validar_csrf_token($_POST['csrf_token'] ?? '')) {
        die("Tentativa bloqueada por segurança.");
    }
    if (!$is_admin) {
        $mensagem = "Erro: Sem permissão para apagar."; $tipo_mensagem = "erro";
    } else {
        $id_vistoria = filter_input(INPUT_POST, 'id_vistoria_apagar', FILTER_VALIDATE_INT);
        $pass_introduzida = $_POST['pass_seguranca'] ?? '';
        $stmt_pass = $pdo->prepare("SELECT valor FROM configuracoes WHERE chave = 'password_apagar' LIMIT 1");
        $stmt_pass->execute();
        $pass_config = $stmt_pass->fetchColumn();

        if ($pass_introduzida !== $pass_config || empty($pass_introduzida)) {
            $mensagem = "Password de segurança incorreta."; $tipo_mensagem = "erro";
        } else {
            try {
                $pdo->beginTransaction();
                $pdo->prepare("DELETE FROM vistorias_quadros_linhas WHERE id_vistoria = ?")->execute([$id_vistoria]);
                $pdo->prepare("DELETE FROM vistorias_quadros WHERE id = ?")->execute([$id_vistoria]);
                $pdo->commit();
                registrarLog($pdo, "Apagou Vistoria de Quadros (ID: $id_vistoria)", "Termos Elétricos");
                header("Location: termos_eletrico.php?sucesso=apagado");
                exit;
            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $mensagem = "Erro ao apagar vistoria: " . $e->getMessage(); $tipo_mensagem = "erro";
            }
        }
    }
}

// --- LÓGICA PARA APAGAR VISTORIA DE INSTALAÇÃO ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['btn_confirmar_apagar_vistoria_instalacao'])) {
    if (!validar_csrf_token($_POST['csrf_token'] ?? '')) {
        die("Tentativa bloqueada por segurança.");
    }
    if (!$is_admin) {
        $mensagem = "Erro: Sem permissão para apagar."; $tipo_mensagem = "erro";
    } else {
        $id_vistoria_inst_apagar = filter_input(INPUT_POST, 'id_vistoria_instalacao_apagar', FILTER_VALIDATE_INT);
        $pass_introduzida = $_POST['pass_seguranca'] ?? '';
        $stmt_pass = $pdo->prepare("SELECT valor FROM configuracoes WHERE chave = 'password_apagar' LIMIT 1");
        $stmt_pass->execute();
        $pass_config = $stmt_pass->fetchColumn();

        if ($pass_introduzida !== $pass_config || empty($pass_introduzida)) {
            $mensagem = "Password de segurança incorreta."; $tipo_mensagem = "erro";
        } else {
            try {
                $pdo->beginTransaction();
                $pdo->prepare("DELETE FROM vistorias_instalacao_pisos WHERE id_vistoria = ?")->execute([$id_vistoria_inst_apagar]);
                foreach (['vistorias_instalacao_alteracoes', 'vistorias_instalacao_observacoes'] as $tab_filha) {
                    try { $pdo->prepare("DELETE FROM $tab_filha WHERE id_vistoria = ?")->execute([$id_vistoria_inst_apagar]); } catch (Exception $e_t) { /* tabela pode não existir */ }
                }
                $pdo->prepare("DELETE FROM vistorias_instalacao WHERE id = ?")->execute([$id_vistoria_inst_apagar]);
                $pdo->commit();
                registrarLog($pdo, "Apagou Vistoria de Instalação (ID: $id_vistoria_inst_apagar)", "Termos Elétricos");
                header("Location: termos_eletrico.php?sucesso=apagado");
                exit;
            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $mensagem = "Erro ao apagar vistoria de instalação: " . $e->getMessage(); $tipo_mensagem = "erro";
            }
        }
    }
}

// --- LÓGICA DE DUPLICAR TERMO NORMAL ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['btn_confirmar_duplicar'])) {
    if (!validar_csrf_token($_POST['csrf_token'] ?? '')) {
        die("Tentativa bloqueada por segurança.");
    }
    $id_dup = filter_input(INPUT_POST, 'id_duplicar', FILTER_VALIDATE_INT);
    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare("SELECT * FROM termos_eletrico WHERE id = ?");
        $stmt->execute([$id_dup]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $id_antigo = $row['id'];
            unset($row['id']);
            $row['data_emissao'] = date('Y-m-d');
            $columns = array_keys($row);
            $placeholders = array_fill(0, count($columns), '?');
            $sql_insert = "INSERT INTO termos_eletrico (" . implode(', ', $columns) . ") VALUES (" . implode(', ', $placeholders) . ")";
            $stmt_insert = $pdo->prepare($sql_insert);
            $stmt_insert->execute(array_values($row));
            $novo_id = $pdo->lastInsertId();

            $stmt_inst = $pdo->prepare("SELECT * FROM termos_eletrico_instalacoes WHERE id_termo = ? ORDER BY id ASC");
            $stmt_inst->execute([$id_antigo]);
            $instalacoes = $stmt_inst->fetchAll(PDO::FETCH_ASSOC);
            if (!empty($instalacoes)) {
                $eq_columns = array_filter(array_keys($instalacoes[0]), function($c) { return $c !== 'id'; });
                $eq_placeholders = array_fill(0, count($eq_columns), '?');
                $sql_eq_insert = "INSERT INTO termos_eletrico_instalacoes (" . implode(', ', $eq_columns) . ") VALUES (" . implode(', ', $eq_placeholders) . ")";
                $stmt_eq_insert = $pdo->prepare($sql_eq_insert);
                foreach ($instalacoes as $inst) {
                    $inst['id_termo'] = $novo_id;
                    $valores = [];
                    foreach ($eq_columns as $col) { $valores[] = $inst[$col]; }
                    $stmt_eq_insert->execute($valores);
                }
            }
            $pdo->commit();
            registrarLog($pdo, "Duplicou Termo Elétrico ($id_antigo -> $novo_id)", "Termos Elétricos");
            header("Location: termos_eletrico.php?sucesso=duplicado"); exit;
        }
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $mensagem = "Erro ao duplicar: " . $e->getMessage(); $tipo_mensagem = "erro";
    }
}

// --- LÓGICA DE DUPLICAR VISTORIA DE QUADROS ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['btn_confirmar_duplicar_vistoria'])) {
    if (!validar_csrf_token($_POST['csrf_token'] ?? '')) {
        die("Tentativa bloqueada por segurança.");
    }
    $id_dup_vistoria = filter_input(INPUT_POST, 'id_duplicar_vistoria', FILTER_VALIDATE_INT);
    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare("SELECT * FROM vistorias_quadros WHERE id = ?");
        $stmt->execute([$id_dup_vistoria]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row) {
            $id_antigo = $row['id'];
            unset($row['id']);
            $row['data_vistoria'] = date('Y-m-d'); // Atualiza a data para hoje

            $columns = array_keys($row);
            $placeholders = array_fill(0, count($columns), '?');
            $sql_insert = "INSERT INTO vistorias_quadros (" . implode(', ', $columns) . ") VALUES (" . implode(', ', $placeholders) . ")";
            $stmt_insert = $pdo->prepare($sql_insert);
            $stmt_insert->execute(array_values($row));
            $novo_id = $pdo->lastInsertId();

            // Copiar as linhas da vistoria
            $stmt_linhas = $pdo->prepare("SELECT * FROM vistorias_quadros_linhas WHERE id_vistoria = ? ORDER BY id ASC");
            $stmt_linhas->execute([$id_antigo]);
            $linhas = $stmt_linhas->fetchAll(PDO::FETCH_ASSOC);

            if (!empty($linhas)) {
                $l_columns = array_filter(array_keys($linhas[0]), function($c) { return $c !== 'id'; });
                $l_placeholders = array_fill(0, count($l_columns), '?');
                $sql_l_insert = "INSERT INTO vistorias_quadros_linhas (" . implode(', ', $l_columns) . ") VALUES (" . implode(', ', $l_placeholders) . ")";
                $stmt_l_insert = $pdo->prepare($sql_l_insert);

                foreach ($linhas as $linha) {
                    $linha['id_vistoria'] = $novo_id;
                    $valores = [];
                    foreach ($l_columns as $col) { $valores[] = $linha[$col]; }
                    $stmt_l_insert->execute($valores);
                }
            }
            $pdo->commit();
            registrarLog($pdo, "Duplicou Vistoria de Quadros ($id_antigo -> $novo_id)", "Termos Elétricos");
            header("Location: termos_eletrico.php?sucesso=duplicado_vistoria"); exit;
        }
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $mensagem = "Erro ao duplicar vistoria: " . $e->getMessage(); $tipo_mensagem = "erro";
    }
}

// --- QUERY TERMOS NORMAIS ---
try {
    $sql_termos = "SELECT t.*, o.nome as nome_obra, c.nome as nome_cliente
            FROM termos_eletrico t
            LEFT JOIN obras o ON t.id_obra = o.id
            LEFT JOIN clientes c ON o.id_cliente = c.id
            ORDER BY c.nome ASC, o.nome ASC, t.data_emissao DESC, t.id DESC";
    $termos_query = $pdo->query($sql_termos)->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $termos_query = [];
}

$termos_por_obra = [];
foreach ($termos_query as $t) {
    $nome_obra = $t['nome_obra'] ?: 'Sem Obra Associada';
    if (!isset($termos_por_obra[$nome_obra])) {
        $termos_por_obra[$nome_obra] = ['cliente' => $t['nome_cliente'] ?: 'Entidade Desconhecida', 'termos' => []];
    }
    $termos_por_obra[$nome_obra]['termos'][] = $t;
}

// --- QUERY VISTORIAS (Protegida com Try/Catch) ---
$vistorias_query = [];
try {
    $stmt_vis = $pdo->query("SELECT v.*, c.nome as nome_cliente FROM vistorias_quadros v LEFT JOIN clientes c ON v.id_cliente = c.id ORDER BY c.nome ASC, v.data_vistoria DESC, v.id DESC");
    if ($stmt_vis) {
        $vistorias_query = $stmt_vis->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Exception $e) {
    // Se a tabela ainda não existir, ignora para não bloquear a página!
}

// --- QUERY VISTORIAS DE INSTALAÇÃO (Protegida com Try/Catch) ---
$vistorias_instalacao_query = [];
try {
    $stmt_vis_inst = $pdo->query("SELECT vi.*, o.nome as nome_obra, o.referencia_obra, c.nome as nome_cliente
        FROM vistorias_instalacao vi
        LEFT JOIN obras o ON vi.id_obra = o.id
        LEFT JOIN clientes c ON vi.id_cliente = c.id
        ORDER BY c.nome ASC, vi.data_vistoria DESC, vi.id DESC");
    if ($stmt_vis_inst) {
        $vistorias_instalacao_query = $stmt_vis_inst->fetchAll(PDO::FETCH_ASSOC);
        // Número partilhado: o gravado na vistoria; se faltar, o da obra
        foreach ($vistorias_instalacao_query as &$vi_row) {
            $vi_row['ref_mostrar'] = !empty($vi_row['referencia']) ? $vi_row['referencia'] : ($vi_row['referencia_obra'] ?? '');
        }
        unset($vi_row);
    }
} catch (Exception $e) {
    // Se a tabela ainda não existir/estiver na versão antiga, ignora para não bloquear a página!
}

$tipos_pdf = [
    'termo_execucao' => 'Termo Resp. Execução',
    'termo_execucao_obras' => 'Termo Resp. Execução Obras',
    'termo_exploracao' => 'Termo Resp. Exploração',
    'exploracao_edificios_publico' => 'Termo Exploração - Ed. Público',
    'declaracao_conformidade' => 'Declaração de Conformidade',
    'declaracao_mono_tri' => 'Declaração Mono-Trifásico',
    'declaracao_tri_mono' => 'Declaração Tri-Monofásico',
    'ficha_eletrotecnica' => 'Ficha Eletrotécnica',
    'declaracao_autorizacao' => 'Autorização a Terceiros'
];
?>
<!DOCTYPE html>
<html lang="pt-PT">
<head>
    <meta charset="UTF-8">
    <title>Instalações Elétricas - MAB</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { font-family: system-ui, sans-serif; background: #f7f9fc; margin: 0; font-size: 13px; padding-bottom: 50px; }
        * { box-sizing: border-box; }
        .container { max-width: 1400px; margin: 30px auto; padding: 0 20px; }

        .topbar { background: rgba(255,255,255,0.94); backdrop-filter: blur(8px); -webkit-backdrop-filter: blur(8px); padding: 10px 40px; box-shadow: 0 1px 0 #e8ecf1; display: flex; justify-content: space-between; align-items: center; position: sticky; top: 0; z-index: 100; }
        .topbar-left a { display: block; }
        .topbar-left .logo { height: 35px; display: block; }
        .topbar-right { display: flex; align-items: center; gap: 12px; }
        .btn-voltar { width: 40px; height: 40px; border-radius: 50%; background: #fff; border: 1px solid #e8ecf1; color: #576574; display: inline-flex; align-items: center; justify-content: center; text-decoration: none; font-size: 16px; transition: all 0.2s ease; box-shadow: 0 2px 6px rgba(0,0,0,0.04); flex-shrink: 0; }
        .btn-voltar:hover { background: #eef2f6; color: #34495e; border-color: #d5dce4; transform: translateX(-2px); }
        .btn-voltar:active { transform: scale(0.94); }
        .user-menu { position: relative; }
        .user-btn { background: #fff; border: 1px solid #e8ecf1; border-radius: 30px; font-size: 14px; font-weight: 600; color: #2c3e50; cursor: pointer; display: flex; align-items: center; gap: 10px; padding: 5px 14px 5px 5px; transition: 0.2s; font-family: inherit; }
        .user-btn:hover { box-shadow: 0 4px 12px rgba(0,0,0,0.06); }
        .user-avatar { background: #e74c3c; color: #fff; width: 28px; height: 28px; border-radius: 50%; display: flex; justify-content: center; align-items: center; font-size: 13px; }
        .dropdown-content { display: none; position: absolute; right: 0; top: 100%; background: #fff; min-width: 180px; box-shadow: 0 12px 30px rgba(0,0,0,0.1); border-radius: 10px; border: 1px solid #e8ecf1; overflow: hidden; margin-top: 6px; z-index: 200; }
        .user-menu:hover .dropdown-content, .user-menu.aberto .dropdown-content { display: block; }
        .dropdown-content a { color: #576574; padding: 12px 20px; text-decoration: none; display: block; font-size: 14px; }
        .dropdown-content a:hover { background: #f4f6f8; color: #2c3e50; }
        .page-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px; flex-wrap:wrap; gap:15px;}
        .page-title { margin: 0; color: #2c3e50; font-size: 24px; font-weight: 800; display: flex; align-items: center; gap: 10px; }

        .btn-primary { background: #9b59b6; color: #fff; padding: 12px 20px; border-radius: 8px; border: none; cursor: pointer; font-weight: bold; text-decoration: none; display: inline-flex; align-items: center; gap: 8px; transition: 0.2s;}
        .btn-primary:hover { background: #8e44ad; color: #fff;}
        .btn-secondary { background: #e67e22; color: #fff; padding: 12px 20px; border-radius: 8px; border: none; font-weight: bold; text-decoration: none; display: inline-flex; align-items: center; gap: 8px; transition: 0.2s;}
        .btn-secondary:hover { background: #d35400; }

        .card-obra { background: #fff; border-radius: 12px; border: 1px solid #e1e5ea; margin-bottom: 30px; overflow: hidden; box-shadow: 0 4px 15px rgba(0,0,0,0.03); }
        .obra-head { background: #fdfaf2; padding: 15px 20px; border-bottom: 2px solid #9b59b6; display: flex; justify-content: space-between; align-items: center; }
        .obra-head.vistoria-head { background: #fef5e7; border-bottom: 2px solid #e67e22; }

        table { width: 100%; border-collapse: collapse; }
        th { text-align: left; padding: 12px 15px; background: #f8f9fa; font-size: 11px; text-transform: uppercase; color: #7f8c8d; border-bottom: 1px solid #eee; }
        td { padding: 12px 15px; border-bottom: 1px solid #f1f2f6; vertical-align: middle; }
        tbody tr:hover { background-color: #fcfcfc; }

        .btn-acao { background: #f1f2f6; border: none; cursor: pointer; font-size: 15px; color: #576574; transition: 0.2s; width: 32px; height: 32px; border-radius: 6px; display: inline-flex; align-items: center; justify-content: center; text-decoration: none; }
        .btn-acao:hover { background: #e1e5ea; color: #2c3e50; }
        .btn-imprimir { color: #3498db; background: #f0f8ff; }
        .btn-imprimir:hover { color: #2980b9; background: #d6eaf8; }
        .btn-dup { color: #f39c12; background: #fef5e7; }
        .btn-dup:hover { background: #fdebd0; color: #d68910; }
        .btn-del { color: #e74c3c; background: #fdeded; }
        .btn-del:hover { background: #fadbd8; color: #c0392b; }

        .id-badge { font-weight: 700; color: #9b59b6; background: #f5eef8; padding: 4px 8px; border-radius: 6px; border: 1px solid #ebdef0; font-size: 12px; }
        .id-badge-vistoria { font-weight: 700; color: #e67e22; background: #fdf2e9; padding: 4px 8px; border-radius: 6px; border: 1px solid #fae5d3; font-size: 12px; }

        .alert { padding: 15px; border-radius: 8px; margin-bottom: 20px; text-align: center; font-weight: 600; }
        .alert-erro { background: #fdeded; color: #c0392b; border: 1px solid #f9cccc; }
        .alert-sucesso { background: #f5eef8; color: #8e44ad; border: 1px solid #ebdef0; }

        /* MODAIS À PROVA DE BALA */
        .modal { display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.6); z-index:10000; align-items:center; justify-content:center; backdrop-filter: blur(2px); }
        .modal-content { background:#fff; padding:30px; border-radius:15px; width:90%; max-width:500px; text-align:center; box-shadow: 0 20px 50px rgba(0,0,0,0.2); }
        .modal-content input[type="password"] { width: 100%; padding: 12px; border: 1px solid #dcdde1; border-radius: 8px; margin: 15px 0; outline: none; }

        .modal-btns { display: flex; gap: 10px; margin-top: 15px;}
        .modal-btns button { flex: 1; padding: 12px; border: none; border-radius: 8px; font-weight: 600; cursor: pointer; transition:0.2s;}
        .btn-cancelar { background: #f1f2f6; color: #576574; }
        .grid-pdfs { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 10px; margin-top: 20px; text-align: left; }
        .btn-pdf-opt { display: flex; align-items: center; gap: 12px; padding: 12px; background: #f8f9fa; border: 1px solid #dcdde1; border-radius: 8px; text-decoration: none; color: #2c3e50; font-weight: 600; font-size: 12px; transition: 0.2s; }
        .btn-pdf-opt:hover { background: #f5eef8; border-color: #9b59b6; color: #9b59b6; transform: translateY(-2px); }
        .btn-pdf-opt i { font-size: 18px; color: #e74c3c; }
        .text-center { text-align: center !important; }
        .id-badge-inst { color:#2471a3; background:#eaf4fc; border-color:#d4e9f7; }

        @media (max-width: 1024px) {
            .topbar { padding: 10px 24px; }
            .container { margin: 24px auto; }
        }
        @media (max-width: 768px) {
            .topbar { padding: 8px 15px; }
            .topbar-left .logo { height: 30px; }
            .btn-voltar { width: 38px; height: 38px; }
            .container { margin: 20px auto; padding: 0 15px; }
            .page-title { font-size: 20px; }
            .page-header > div { width: 100%; }
            .page-header > div a { flex: 1 1 100%; justify-content: center; }
            .obra-head { padding: 14px 16px; }

            /* Tabelas passam a cartões */
            table, thead, tbody, tr, td { display: block; width: 100%; }
            thead { display: none; }
            tbody tr { border-bottom: 1px solid #eef1f4; padding: 10px 16px; }
            td { border: none; padding: 5px 0; display: flex; justify-content: space-between; align-items: center; gap: 12px; text-align: right; }
            td::before { content: attr(data-label); font-size: 10px; font-weight: 700; text-transform: uppercase; color: #95a5a6; text-align: left; flex-shrink: 0; }
            td.text-center { text-align: right !important; }
            td.td-acoes { justify-content: flex-end; padding-top: 10px; }
            td.td-acoes::before { display: none; }
        }
        @media (max-width: 560px) {
            .user-nome { display: none; }
            .user-btn { padding: 5px 10px 5px 5px; }
            .modal-content { padding: 22px 18px; }
            .modal-btns { flex-direction: column-reverse; }
        }
    </style>
</head>
<body>

    <header class="topbar">
        <div class="topbar-left"><a href="index.php" title="Página inicial"><img src="mab.png" alt="MAB" class="logo"></a></div>
        <div class="topbar-right">
            <a href="termos.php" class="btn-voltar" title="Voltar" aria-label="Voltar"><i class="fa-solid fa-arrow-left"></i></a>
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
            <h1 class="page-title"><i class="fa-solid fa-bolt" style="color:#9b59b6;"></i> Instalações Elétricas</h1>
            <div style="display:flex; gap:10px; flex-wrap:wrap;">
                <a href="vistoria_quadros_form.php" class="btn-secondary"><i class="fa-solid fa-clipboard-check"></i> Vistoria de Quadros</a>
                <a href="vistoria_instalacao_form.php" class="btn-secondary"><i class="fa-solid fa-plug-circle-check"></i> Vistoria de Instalação</a>
                <a href="termo_eletrico_form.php" class="btn-primary"><i class="fa-solid fa-plus-circle"></i> Novo Termo Elétrico</a>
            </div>
        </div>

        <?php if(!empty($vistorias_query)): ?>
        <div class="card-obra">
            <div class="obra-head vistoria-head">
                <div>
                    <strong style="font-size:16px; color:#d35400;"><i class="fa-solid fa-clipboard-check"></i> Relatórios de Vistoria de Quadros Elétricos</strong><br>
                    <small style="color:#576574;">Listagem de vistorias técnicas registadas</small>
                </div>
            </div>
            <table>
                <thead>
                    <tr>
                        <th width="150">Ref. Documento</th>
                        <th width="120">Data Vistoria</th>
                        <th>Obra / Local</th>
                        <th>Cliente</th>
                        <th width="150" class="text-center">Ações</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($vistorias_query as $v): ?>
                    <tr>
                        <td data-label="Ref."><span class="id-badge-vistoria"><?= htmlspecialchars($v['referencia'] ?: 'N/D') ?></span></td>
                        <td data-label="Data"><span><i class="fa-regular fa-calendar-check" style="color:#bdc3c7;"></i> <?= date('d/m/Y', strtotime($v['data_vistoria'])) ?></span></td>
                        <td data-label="Obra"><strong><?= htmlspecialchars($v['nome_obra'] ?? '') ?></strong></td>
                        <td data-label="Cliente" style="color:#576574;"><?= htmlspecialchars($v['nome_cliente'] ?? '') ?></td>
                        <td class="text-center td-acoes">
                            <div style="display:flex; justify-content:center; gap:8px;">
                                <a href="vistoria_quadros_pdf.php?id=<?= $v['id'] ?>" target="_blank" class="btn-acao btn-imprimir" title="Imprimir Vistoria"><i class="fa-solid fa-print"></i></a>
                                <a href="vistoria_quadros_form.php?id=<?= $v['id'] ?>" class="btn-acao" title="Editar"><i class="fa-solid fa-pen-to-square"></i></a>

                                <button type="button" onclick="abrirModalDuplicarVistoria(<?= $v['id'] ?>)" class="btn-acao btn-dup" title="Duplicar Vistoria"><i class="fa-solid fa-copy"></i></button>

                                <?php if($is_admin): ?>
                                    <button type="button" onclick="abrirModalApagarVistoria(<?= $v['id'] ?>, '<?= addslashes(htmlspecialchars($v['referencia'])) ?>')" class="btn-acao btn-del" title="Eliminar"><i class="fa-solid fa-trash-can"></i></button>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

        <?php if(!empty($vistorias_instalacao_query)): ?>
        <div class="card-obra">
            <div class="obra-head vistoria-head" style="background:#eaf4fc; border-bottom-color:#2980b9;">
                <div>
                    <strong style="font-size:16px; color:#2471a3;"><i class="fa-solid fa-plug-circle-check"></i> Vistorias de Instalação Elétrica</strong><br>
                    <small style="color:#576574;">Listagem de vistorias de instalação registadas</small>
                </div>
            </div>
            <table>
                <thead>
                    <tr>
                        <th width="110">Nº</th>
                        <th width="120">Data Vistoria</th>
                        <th>Obra / Local</th>
                        <th>Cliente</th>
                        <th width="80" class="text-center">Pisos</th>
                        <th width="150" class="text-center">Ações</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($vistorias_instalacao_query as $vi): ?>
                    <tr>
                        <td data-label="Nº"><span class="id-badge-vistoria id-badge-inst"><?= $vi['ref_mostrar'] !== '' ? htmlspecialchars($vi['ref_mostrar']) : '#' . (int)$vi['id'] ?></span></td>
                        <td data-label="Data"><span><i class="fa-regular fa-calendar-check" style="color:#bdc3c7;"></i> <?= date('d/m/Y', strtotime($vi['data_vistoria'])) ?></span></td>
                        <td data-label="Obra"><strong><?= htmlspecialchars($vi['nome_obra'] ?? 'Sem Obra Associada') ?></strong></td>
                        <td data-label="Cliente" style="color:#576574;"><?= htmlspecialchars($vi['nome_cliente'] ?? 'N/D') ?></td>
                        <td data-label="Pisos" class="text-center"><?= (int)$vi['numero_pisos'] ?></td>
                        <td class="text-center td-acoes">
                            <div style="display:flex; justify-content:center; gap:8px;">
                                <a href="vistoria_instalacao_pdf.php?id=<?= (int)$vi['id'] ?>" target="_blank" class="btn-acao btn-imprimir" title="Imprimir Vistoria"><i class="fa-solid fa-print"></i></a>
                                <a href="vistoria_instalacao_form.php?id=<?= (int)$vi['id'] ?>" class="btn-acao" title="Ver / Editar"><i class="fa-solid fa-pen-to-square"></i></a>
                                <?php if($is_admin): ?>
                                    <button type="button" onclick="abrirModalApagarVistoriaInstalacao(<?= (int)$vi['id'] ?>, '<?= htmlspecialchars(addslashes($vi['ref_mostrar'] !== '' ? $vi['ref_mostrar'] : '#' . $vi['id']), ENT_QUOTES) ?>')" class="btn-acao btn-del" title="Eliminar"><i class="fa-solid fa-trash-can"></i></button>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

        <h2 style="font-size:16px; color:#2c3e50; margin-bottom:15px; border-bottom:2px solid #e1e5ea; padding-bottom:10px;"><i class="fa-solid fa-folder-open"></i> Termos de Responsabilidade e Certificados</h2>
        <?php if(empty($termos_por_obra)): ?>
            <div style="text-align:center; padding:50px; background:#fff; border-radius:12px; border:1px solid #ddd;">
                <i class="fa-solid fa-folder-open" style="font-size:40px; color:#ccc; margin-bottom:10px;"></i>
                <p style="color:#7f8c8d;">Nenhum documento encontrado.</p>
            </div>
        <?php else: foreach($termos_por_obra as $nome_obra => $d): ?>
            <div class="card-obra">
                <div class="obra-head">
                    <div>
                        <strong style="font-size:16px; color:#9b59b6;"><?= htmlspecialchars($nome_obra) ?></strong><br>
                        <small style="color:#576574;"><i class="fa-solid fa-user-tie"></i> Entidade: <?= htmlspecialchars($d['cliente']) ?></small>
                    </div>
                </div>
                <table>
                    <thead>
                        <tr>
                            <th width="150">Ref. Documento</th>
                            <th width="120">Data Inicial</th>
                            <th>Observações / Extra</th>
                            <th width="200" class="text-center">Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($d['termos'] as $t): ?>
                        <tr>
                            <td data-label="Ref."><span class="id-badge"><?= htmlspecialchars($t['referencia_termo'] ?: 'N/D') ?></span></td>
                            <td data-label="Data"><span><i class="fa-regular fa-calendar-check" style="color:#bdc3c7;"></i> <?= date('d/m/Y', strtotime($t['data_emissao'])) ?></span></td>
                            <td data-label="Extra" style="color:#7f8c8d; font-size:12px;">
                                <?= ($t['com_projeto'] ?? '') == 'Sim' ? '<i class="fa-solid fa-check" style="color:#27ae60"></i> Com Projeto' : '' ?>
                                <?= !empty($t['instalacao_nova_existente']) ? ' | ' . $t['instalacao_nova_existente'] : '' ?>
                            </td>
                            <td class="text-center td-acoes">
                                <div style="display:flex; justify-content:center; gap:8px;">
                                    <button onclick="abrirImpressao(<?= $t['id'] ?>, '<?= addslashes(htmlspecialchars($t['nome_obra'] ?? 'N/D', ENT_QUOTES)) ?>')" class="btn-acao btn-imprimir" title="Imprimir PDFs"><i class="fa-solid fa-print"></i></button>
                                    <a href="termo_eletrico_form.php?id=<?= $t['id'] ?>" class="btn-acao" title="Editar"><i class="fa-solid fa-pen-to-square"></i></a>
                                    <button type="button" onclick="abrirModalDuplicar(<?= $t['id'] ?>)" class="btn-acao btn-dup" title="Duplicar Registo"><i class="fa-solid fa-copy"></i></button>
                                    <?php if($is_admin): ?>
                                        <button type="button" onclick="abrirModalApagar(<?= $t['id'] ?>, '<?= addslashes(htmlspecialchars($t['referencia_termo'])) ?>')" class="btn-acao btn-del" title="Eliminar"><i class="fa-solid fa-trash-can"></i></button>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endforeach; endif; ?>
    </div>

    <div class="modal" id="modalDuplicar">
        <div class="modal-content">
            <h3 style="color:#f39c12; margin-top:0;"><i class="fa-solid fa-copy"></i> Confirmar Duplicação</h3>
            <p style="color:#2c3e50; margin-bottom: 20px;">Deseja criar uma cópia exata deste documento elétrico e das suas instalações?<br><small style="color:#7f8c8d;">A data de emissão será atualizada para o dia de hoje.</small></p>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                <input type="hidden" name="id_duplicar" id="id_duplicar_input">
                <div class="modal-btns">
                    <button type="button" class="btn-cancelar" onclick="fecharModais()">Cancelar</button>
                    <button type="submit" name="btn_confirmar_duplicar" style="background:#f39c12; color:#fff;">Duplicar Registo</button>
                </div>
            </form>
        </div>
    </div>

    <div class="modal" id="modalDuplicarVistoria">
        <div class="modal-content">
            <h3 style="color:#f39c12; margin-top:0;"><i class="fa-solid fa-copy"></i> Duplicar Vistoria</h3>
            <p style="color:#2c3e50; margin-bottom: 20px;">Deseja criar uma cópia exata desta vistoria e de todas as suas verificações de quadros?<br><small style="color:#7f8c8d;">A data da vistoria será atualizada para o dia de hoje.</small></p>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                <input type="hidden" name="id_duplicar_vistoria" id="id_duplicar_vistoria_input">
                <div class="modal-btns">
                    <button type="button" class="btn-cancelar" onclick="fecharModais()">Cancelar</button>
                    <button type="submit" name="btn_confirmar_duplicar_vistoria" style="background:#f39c12; color:#fff;">Duplicar Vistoria</button>
                </div>
            </form>
        </div>
    </div>

    <?php if ($is_admin): ?>
    <div class="modal" id="modalApagar">
        <div class="modal-content">
            <h3 style="color:#e74c3c; margin-top:0;"><i class="fa-solid fa-triangle-exclamation"></i> Eliminar Registo</h3>
            <p>Deseja apagar o termo de responsabilidade:<br><strong id="txt_modal" style="color: #2c3e50; font-size: 16px; margin-top: 10px; display: inline-block;"></strong>?</p>
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

    <div class="modal" id="modalApagarVistoria">
        <div class="modal-content">
            <h3 style="color:#e74c3c; margin-top:0;"><i class="fa-solid fa-triangle-exclamation"></i> Eliminar Vistoria</h3>
            <p>Deseja apagar a Vistoria de Quadros:<br><strong id="txt_modal_vistoria" style="color: #2c3e50; font-size: 16px; margin-top: 10px; display: inline-block;"></strong>?</p>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                <input type="hidden" name="id_vistoria_apagar" id="id_vistoria_m">
                <input type="password" name="pass_seguranca" required placeholder="Password de Segurança">
                <div class="modal-btns">
                    <button type="button" class="btn-cancelar" onclick="fecharModais()">Cancelar</button>
                    <button type="submit" name="btn_confirmar_apagar_vistoria" style="background:#e74c3c; color:#fff;">Eliminar Definitivamente</button>
                </div>
            </form>
        </div>
    </div>

    <div class="modal" id="modalApagarVistoriaInstalacao">
        <div class="modal-content">
            <h3 style="color:#e74c3c; margin-top:0;"><i class="fa-solid fa-triangle-exclamation"></i> Eliminar Vistoria de Instalação</h3>
            <p>Deseja apagar definitivamente a vistoria de instalação:<br><strong id="txt_modal_vistoria_inst" style="color: #2c3e50; font-size: 16px; margin-top: 10px; display: inline-block;"></strong>?</p>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                <input type="hidden" name="id_vistoria_instalacao_apagar" id="id_vistoria_instalacao_m">
                <input type="password" name="pass_seguranca" required placeholder="Password de Segurança">
                <div class="modal-btns">
                    <button type="button" class="btn-cancelar" onclick="fecharModais()">Cancelar</button>
                    <button type="submit" name="btn_confirmar_apagar_vistoria_instalacao" style="background:#e74c3c; color:#fff;">Eliminar Definitivamente</button>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <div id="modalPrint" class="modal">
        <div class="modal-content" style="max-width: 600px; text-align: left;">
            <div style="display:flex; justify-content:space-between; align-items:center; border-bottom:1px solid #f1f2f6; padding-bottom:15px;">
                <h2 style="margin:0; font-size:18px; color:#3498db;"><i class="fa-solid fa-print"></i> Gerar Documento Elétrico</h2>
                <button onclick="fecharModais()" style="background:none; border:none; font-size:24px; cursor:pointer; color:#95a5a6;">&times;</button>
            </div>
            <p id="print_obra_nome" style="margin:15px 0; font-weight:bold; color:#2c3e50; font-size:14px;"></p>
            <div class="grid-pdfs">
                <?php foreach($tipos_pdf as $key => $label): ?>
                    <a href="#" id="link_<?= $key ?>" target="_blank" class="btn-pdf-opt" onclick="fecharModais()">
                        <i class="fa-solid fa-file-pdf"></i> <span><?= htmlspecialchars($label) ?></span>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <script>
        function abrirModalApagar(id, ref) {
            document.getElementById('id_termo_m').value = id;
            document.getElementById('txt_modal').innerText = ref;
            document.getElementById('modalApagar').style.display = 'flex';
            document.querySelector('#modalApagar input[type="password"]').value = '';
        }

        function abrirModalApagarVistoria(id, ref) {
            document.getElementById('id_vistoria_m').value = id;
            document.getElementById('txt_modal_vistoria').innerText = ref;
            document.getElementById('modalApagarVistoria').style.display = 'flex';
            document.querySelector('#modalApagarVistoria input[type="password"]').value = '';
        }

        function abrirModalApagarVistoriaInstalacao(id, ref) {
            document.getElementById('id_vistoria_instalacao_m').value = id;
            document.getElementById('txt_modal_vistoria_inst').innerText = 'Nº ' + (ref || id);
            document.getElementById('modalApagarVistoriaInstalacao').style.display = 'flex';
            document.querySelector('#modalApagarVistoriaInstalacao input[type="password"]').value = '';
        }

        function abrirModalDuplicar(id) {
            document.getElementById('id_duplicar_input').value = id;
            document.getElementById('modalDuplicar').style.display = 'flex';
        }

        function abrirModalDuplicarVistoria(id) {
            document.getElementById('id_duplicar_vistoria_input').value = id;
            document.getElementById('modalDuplicarVistoria').style.display = 'flex';
        }

        function abrirImpressao(id, obra) {
            document.getElementById('print_obra_nome').innerText = "Obra: " + obra;
            <?php foreach($tipos_pdf as $key => $label): ?>
                document.getElementById('link_<?= $key ?>').href = 'termo_eletrico_pdf.php?id=' + id + '&tipo=<?= $key ?>';
            <?php endforeach; ?>
            document.getElementById('modalPrint').style.display = 'flex';
        }

        function fecharModais() { document.querySelectorAll('.modal').forEach(m => m.style.display = 'none'); }
        window.onclick = function(e) { if(e.target.classList.contains('modal')) { fecharModais(); } }

        // Menu do utilizador: em ecrãs táteis abre com toque
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