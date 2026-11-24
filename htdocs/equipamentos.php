<?php
session_start();
require_once 'db.php';

// REGRA DE OURO 1: Validação de sessão
if (!isset($_SESSION['user_id'])) { 
    header("Location: index.php"); 
    exit; 
}

require_once 'functions.php';

// REGRA DE OURO 7: token CSRF em todos os formulários (validado antes de gravar seja o que for)
$csrf_falhou = ($_SERVER['REQUEST_METHOD'] === 'POST' && !validar_csrf_token($_POST['csrf_token'] ?? ''));

// NUNCA PERDER DADOS: antes de alterar ou apagar um equipamento, guarda uma cópia completa
// (com o histórico de movimentos) em equipamentos_arquivo. Se não conseguir arquivar, não altera.
// (a tabela é criada fora das transações: no MySQL, CREATE TABLE fecha a transação aberta)
function criarTabelaArquivoEquipamentos($pdo) {
    $pdo->exec("CREATE TABLE IF NOT EXISTS equipamentos_arquivo (
        id INT AUTO_INCREMENT PRIMARY KEY,
        id_equipamento INT NOT NULL,
        acao VARCHAR(20) NOT NULL,
        dados_json MEDIUMTEXT NOT NULL,
        movimentos_json MEDIUMTEXT NULL,
        arquivado_por INT NULL,
        arquivado_em DATETIME NOT NULL,
        KEY idx_equipamento (id_equipamento)
    ) DEFAULT CHARSET=utf8mb4");
}
function arquivarEquipamento($pdo, $id_equipamento, $acao, $id_user) {
    $st = $pdo->prepare("SELECT * FROM equipamentos WHERE id = ?");
    $st->execute([$id_equipamento]);
    $dados = $st->fetch(PDO::FETCH_ASSOC);
    if (!$dados) return;
    $movs = null;
    try {
        $st_m = $pdo->prepare("SELECT * FROM equipamentos_movimentos WHERE id_equipamento = ? ORDER BY id ASC");
        $st_m->execute([$id_equipamento]);
        $movs = $st_m->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) { $movs = null; }
    $pdo->prepare("INSERT INTO equipamentos_arquivo (id_equipamento, acao, dados_json, movimentos_json, arquivado_por, arquivado_em) VALUES (?, ?, ?, ?, ?, NOW())")
        ->execute([$id_equipamento, $acao, safe_json_encode($dados), $movs === null ? null : safe_json_encode($movs), $id_user]);
}

// Regista uma linha de movimento/localização do equipamento geral
if (!function_exists('registarMovimento')) {
    function registarMovimento($pdo, $id_equipamento, $data_evento, $localizacao, $id_cliente, $id_obra, $id_colab, $emp_nome, $observacoes, $id_user) {
        $sql = "INSERT INTO equipamentos_movimentos (id_equipamento, data_evento, localizacao, id_cliente, id_obra, id_colaborador_levou, emprestado_nome, observacoes, id_utilizador_registo) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$id_equipamento, $data_evento, $localizacao, $id_cliente, $id_obra, $id_colab, $emp_nome, $observacoes, $id_user]);
    }
}

$perfil_user = $_SESSION['perfil'] ?? '';
$is_admin = in_array($perfil_user, ['admin', 'administrativo']);
if ($is_admin) {
    try { criarTabelaArquivoEquipamentos($pdo); } catch (Exception $e) { error_log('equipamentos.php (arquivo): ' . $e->getMessage()); }
}

// REGRA DE OURO 2: Acesso Restrito pela Matriz
$stmt_perm = $pdo->prepare("SELECT modulo FROM permissoes WHERE grupo = :perfil AND pode_aceder = 1");
$stmt_perm->execute(['perfil' => $perfil_user]);
$meus_acessos = $stmt_perm->fetchAll(PDO::FETCH_COLUMN);

if (!$is_admin && !in_array('equipamentos', $meus_acessos)) {
    header("Location: index.php?erro=sem_permissao");
    exit;
}

// REGRA DE OURO 3: Obter apenas o primeiro nome para o Menu
$nome_completo = $_SESSION['nome'] ?? 'Utilizador';
$primeiro_nome = explode(' ', trim($nome_completo))[0];
$id_utilizador_logado = $_SESSION['user_id'];

$mensagem = ''; 
$tipo_mensagem = '';

// Buscar Colaboradores para a Dropdown
$colaboradores = [];
$clientes = [];
$obras = [];
$tem_historico = false;
if ($is_admin) {
    $colaboradores = $pdo->query("SELECT id, nome FROM utilizadores WHERE ativo = 1 ORDER BY nome ASC")->fetchAll(PDO::FETCH_ASSOC);
    $clientes = $pdo->query("SELECT id, nome FROM clientes WHERE ativo = 1 ORDER BY nome ASC")->fetchAll(PDO::FETCH_ASSOC);
    $obras = $pdo->query("SELECT id, id_cliente, nome, referencia_obra, estado FROM obras ORDER BY nome ASC")->fetchAll(PDO::FETCH_ASSOC);

    // Verificar se a tabela de histórico existe (desliga a funcionalidade até ser criada)
    try {
        $pdo->query("SELECT 1 FROM equipamentos_movimentos LIMIT 1");
        $tem_historico = true;
    } catch (Exception $e) {
        $tem_historico = false;
    }
}

// --- PROCESSAR NOVO EQUIPAMENTO INDIVIDUAL ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$csrf_falhou && isset($_POST['btn_gravar_individual'])) {
    $data_registo = $_POST['data_registo'];
    $id_utilizador = $_POST['id_utilizador'];
    $descricao = trim($_POST['descricao']);
    $marca = trim($_POST['marca']);
    $numero_serie = trim($_POST['numero_serie']);
    $estado = $_POST['estado'];
    $observacoes = trim($_POST['observacoes']);

    try {
        $sql = "INSERT INTO equipamentos (tipo, data_registo, id_utilizador, descricao, marca, numero_serie, estado, observacoes) VALUES ('individual', ?, ?, ?, ?, ?, ?, ?)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$data_registo, $id_utilizador, $descricao, $marca, $numero_serie, $estado, $observacoes]);
        registrarLog($pdo, "Atribuiu equipamento ($descricao) a colaborador", "Equipamentos");
        $mensagem = "Equipamento atribuído com sucesso!"; $tipo_mensagem = "sucesso";
    } catch (Exception $e) {
        error_log('equipamentos.php: ' . $e->getMessage()); $mensagem = "Não foi possível gravar. Tente de novo; se o erro continuar, avise o administrador."; $tipo_mensagem = "erro";
    }
}

// --- PROCESSAR EDIÇÃO DE EQUIPAMENTO INDIVIDUAL ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$csrf_falhou && isset($_POST['btn_editar_individual'])) {
    $id_editar = filter_input(INPUT_POST, 'id_editar', FILTER_VALIDATE_INT);
    $data_registo = $_POST['data_registo'];
    $id_utilizador = $_POST['id_utilizador'];
    $descricao = trim($_POST['descricao']);
    $marca = trim($_POST['marca']);
    $numero_serie = trim($_POST['numero_serie']);
    $estado = $_POST['estado'];
    $observacoes = trim($_POST['observacoes']);

    if (!$is_admin) {
        $mensagem = "Erro: Apenas administradores podem editar."; $tipo_mensagem = "erro";
    } else {
        try {
            arquivarEquipamento($pdo, $id_editar, 'editado', $id_utilizador_logado);
            $sql = "UPDATE equipamentos SET data_registo = ?, id_utilizador = ?, descricao = ?, marca = ?, numero_serie = ?, estado = ?, observacoes = ? WHERE id = ? AND tipo = 'individual'";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$data_registo, $id_utilizador, $descricao, $marca, $numero_serie, $estado, $observacoes, $id_editar]);
            registrarLog($pdo, "Editou equipamento individual ($descricao)", "Equipamentos");
            $mensagem = "Equipamento atualizado com sucesso!"; $tipo_mensagem = "sucesso";
        } catch (Exception $e) {
            error_log('equipamentos.php: ' . $e->getMessage()); $mensagem = "Não foi possível gravar. Tente de novo; se o erro continuar, avise o administrador."; $tipo_mensagem = "erro";
        }
    }
}

// --- PROCESSAR NOVO EQUIPAMENTO GERAL ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$csrf_falhou && isset($_POST['btn_gravar_geral'])) {
    $data_registo = $_POST['data_registo'];
    $descricao = trim($_POST['descricao']);
    $marca = trim($_POST['marca']);
    $numero_serie = trim($_POST['numero_serie']);
    $fornecedor = trim($_POST['fornecedor']);
    $observacoes = trim($_POST['observacoes']);

    // --- Localização do equipamento geral ---
    $localizacao = $_POST['localizacao'] ?? 'empresa';
    $loc_id_cliente = null;
    $loc_id_obra = null;
    $loc_id_colab = null;
    $loc_emp_nome = null;

    if ($localizacao === 'obra') {
        $loc_id_cliente = filter_input(INPUT_POST, 'id_cliente', FILTER_VALIDATE_INT) ?: null;
        $loc_id_obra = filter_input(INPUT_POST, 'id_obra', FILTER_VALIDATE_INT) ?: null;
        $loc_id_colab = filter_input(INPUT_POST, 'id_colaborador_levou', FILTER_VALIDATE_INT) ?: null;
    } elseif ($localizacao === 'emprestado') {
        $loc_id_cliente = filter_input(INPUT_POST, 'id_cliente', FILTER_VALIDATE_INT) ?: null;
        $loc_emp_nome = trim($_POST['emprestado_nome'] ?? '');
        if ($loc_emp_nome === '') $loc_emp_nome = null;
    }

    // --- Validação no servidor ---
    $erro_loc = '';
    if ($localizacao === 'obra' && (!$loc_id_cliente || !$loc_id_obra || !$loc_id_colab)) {
        $erro_loc = "Para a localização 'Em obra' tem de indicar o cliente, a obra e o colaborador que levou.";
    } elseif ($localizacao === 'emprestado' && !$loc_id_cliente && empty($loc_emp_nome)) {
        $erro_loc = "Para a localização 'Emprestado' indique o cliente ou o nome de quem levou.";
    }

    if ($erro_loc) {
        $mensagem = "Erro: " . $erro_loc; $tipo_mensagem = "erro";
    } else {
        try {
            $sql = "INSERT INTO equipamentos (tipo, data_registo, descricao, marca, numero_serie, fornecedor, observacoes, localizacao, id_cliente, id_obra, id_colaborador_levou, emprestado_nome) VALUES ('geral', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$data_registo, $descricao, $marca, $numero_serie, $fornecedor, $observacoes, $localizacao, $loc_id_cliente, $loc_id_obra, $loc_id_colab, $loc_emp_nome]);
            $novo_id = $pdo->lastInsertId();
            registrarLog($pdo, "Adicionou equipamento geral ($descricao)", "Equipamentos");

            // 1º movimento (localização inicial) — usa a data de compra como data do acontecimento
            if ($tem_historico) {
                registarMovimento($pdo, $novo_id, $data_registo, $localizacao, $loc_id_cliente, $loc_id_obra, $loc_id_colab, $loc_emp_nome, $observacoes, $id_utilizador_logado);
            }

            $mensagem = "Equipamento geral guardado com sucesso!"; $tipo_mensagem = "sucesso";
        } catch (Exception $e) {
            error_log('equipamentos.php: ' . $e->getMessage()); $mensagem = "Não foi possível gravar. Tente de novo; se o erro continuar, avise o administrador."; $tipo_mensagem = "erro";
        }
    }
}

// --- PROCESSAR EDICAO DE EQUIPAMENTO GERAL ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$csrf_falhou && isset($_POST['btn_editar_geral'])) {
    $id_editar = filter_input(INPUT_POST, 'id_editar', FILTER_VALIDATE_INT);
    $data_registo = $_POST['data_registo'];
    $descricao = trim($_POST['descricao']);
    $marca = trim($_POST['marca']);
    $numero_serie = trim($_POST['numero_serie']);
    $fornecedor = trim($_POST['fornecedor']);
    $observacoes = trim($_POST['observacoes']);

    // Data do acontecimento (usada só se a localização mudar)
    $data_evento = !empty($_POST['data_evento']) ? $_POST['data_evento'] : date('Y-m-d');

    // --- Localização do equipamento geral ---
    $localizacao = $_POST['localizacao'] ?? 'empresa';
    $loc_id_cliente = null;
    $loc_id_obra = null;
    $loc_id_colab = null;
    $loc_emp_nome = null;

    if ($localizacao === 'obra') {
        $loc_id_cliente = filter_input(INPUT_POST, 'id_cliente', FILTER_VALIDATE_INT) ?: null;
        $loc_id_obra = filter_input(INPUT_POST, 'id_obra', FILTER_VALIDATE_INT) ?: null;
        $loc_id_colab = filter_input(INPUT_POST, 'id_colaborador_levou', FILTER_VALIDATE_INT) ?: null;
    } elseif ($localizacao === 'emprestado') {
        $loc_id_cliente = filter_input(INPUT_POST, 'id_cliente', FILTER_VALIDATE_INT) ?: null;
        $loc_emp_nome = trim($_POST['emprestado_nome'] ?? '');
        if ($loc_emp_nome === '') $loc_emp_nome = null;
    }

    if (!$is_admin) {
        $mensagem = "Erro: Apenas administradores podem editar."; $tipo_mensagem = "erro";
    } elseif ($localizacao === 'obra' && (!$loc_id_cliente || !$loc_id_obra || !$loc_id_colab)) {
        $mensagem = "Erro: Para a localização 'Em obra' tem de indicar o cliente, a obra e o colaborador que levou."; $tipo_mensagem = "erro";
    } elseif ($localizacao === 'emprestado' && !$loc_id_cliente && empty($loc_emp_nome)) {
        $mensagem = "Erro: Para a localização 'Emprestado' indique o cliente ou o nome de quem levou."; $tipo_mensagem = "erro";
    } else {
        try {
            // Estado atual antes de atualizar (para detetar mudança de localização)
            $atual = null;
            if ($tem_historico) {
                $stmt_cur = $pdo->prepare("SELECT localizacao, id_cliente, id_obra, id_colaborador_levou, emprestado_nome FROM equipamentos WHERE id = ? AND tipo = 'geral'");
                $stmt_cur->execute([$id_editar]);
                $atual = $stmt_cur->fetch(PDO::FETCH_ASSOC);
            }

            arquivarEquipamento($pdo, $id_editar, 'editado', $id_utilizador_logado);
            $sql = "UPDATE equipamentos SET data_registo = ?, descricao = ?, marca = ?, numero_serie = ?, fornecedor = ?, observacoes = ?, localizacao = ?, id_cliente = ?, id_obra = ?, id_colaborador_levou = ?, emprestado_nome = ? WHERE id = ? AND tipo = 'geral'";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$data_registo, $descricao, $marca, $numero_serie, $fornecedor, $observacoes, $localizacao, $loc_id_cliente, $loc_id_obra, $loc_id_colab, $loc_emp_nome, $id_editar]);
            registrarLog($pdo, "Editou equipamento geral ($descricao)", "Equipamentos");

            // Regista movimento apenas se a localização mudou
            if ($tem_historico) {
                $mudou = !$atual ? true : (
                    ($atual['localizacao'] ?? 'empresa') !== $localizacao
                    || (int)($atual['id_cliente'] ?? 0) !== (int)($loc_id_cliente ?? 0)
                    || (int)($atual['id_obra'] ?? 0) !== (int)($loc_id_obra ?? 0)
                    || (int)($atual['id_colaborador_levou'] ?? 0) !== (int)($loc_id_colab ?? 0)
                    || trim((string)($atual['emprestado_nome'] ?? '')) !== trim((string)($loc_emp_nome ?? ''))
                );
                if ($mudou) {
                    registarMovimento($pdo, $id_editar, $data_evento, $localizacao, $loc_id_cliente, $loc_id_obra, $loc_id_colab, $loc_emp_nome, $observacoes, $id_utilizador_logado);
                }
            }

            $mensagem = "Equipamento geral atualizado com sucesso!"; $tipo_mensagem = "sucesso";
        } catch (Exception $e) {
            error_log('equipamentos.php: ' . $e->getMessage()); $mensagem = "Não foi possível gravar. Tente de novo; se o erro continuar, avise o administrador."; $tipo_mensagem = "erro";
        }
    }
}

// --- APAGAR EQUIPAMENTO ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$csrf_falhou && isset($_POST['btn_confirmar_apagar'])) {
    if (!$is_admin) {
        $mensagem = "Erro: Apenas administradores podem apagar."; $tipo_mensagem = "erro";
    } else {
        $id_apagar = filter_input(INPUT_POST, 'id_apagar', FILTER_VALIDATE_INT);
        $pass_introduzida = $_POST['pass_seguranca'] ?? '';

        $stmt_pass = $pdo->prepare("SELECT valor FROM configuracoes WHERE chave = 'password_apagar' LIMIT 1"); 
        $stmt_pass->execute(); 
        $pass_config = $stmt_pass->fetchColumn();

        if (empty($pass_introduzida) || !hash_equals((string)$pass_config, (string)$pass_introduzida)) { 
            $mensagem = "Password de segurança incorreta."; $tipo_mensagem = "erro"; 
        } else {
            try {
                $pdo->beginTransaction();
                arquivarEquipamento($pdo, $id_apagar, 'apagado', $id_utilizador_logado);
                if ($tem_historico) {
                    $pdo->prepare("DELETE FROM equipamentos_movimentos WHERE id_equipamento = ?")->execute([$id_apagar]);
                }
                $pdo->prepare("DELETE FROM equipamentos WHERE id = ?")->execute([$id_apagar]);
                $pdo->commit();
                registrarLog($pdo, "Apagou equipamento (ID: $id_apagar)", "Equipamentos");
                $mensagem = "Equipamento removido com sucesso."; $tipo_mensagem = "sucesso"; 
            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                error_log('equipamentos.php (apagar): ' . $e->getMessage());
                $mensagem = "Não foi possível apagar o equipamento. Nada foi alterado."; $tipo_mensagem = "erro"; 
            }
        }
    }
}

if ($csrf_falhou) { $mensagem = "A sessão expirou ou o pedido não é válido. Atualize a página e tente de novo."; $tipo_mensagem = "erro"; }

// --- BUSCAR DADOS INDIVIDUAIS ---
$params_ind = [];
$sql_ind = "SELECT e.*, u.nome as nome_colaborador FROM equipamentos e LEFT JOIN utilizadores u ON e.id_utilizador = u.id WHERE e.tipo = 'individual'";
if (!$is_admin) {
    $sql_ind .= " AND e.id_utilizador = ?";
    $params_ind[] = $id_utilizador_logado;
}
$sql_ind .= " ORDER BY e.data_registo DESC";
$stmt_ind = $pdo->prepare($sql_ind);
$stmt_ind->execute($params_ind);
$eq_individuais = $stmt_ind->fetchAll(PDO::FETCH_ASSOC);

// Colaboradores com equipamento entregue (para escolher o que imprimir), por ordem alfabética
$colabs_com_eq = [];
foreach ($eq_individuais as $e_c) {
    if (!empty($e_c['id_utilizador'])) $colabs_com_eq[(int)$e_c['id_utilizador']] = $e_c['nome_colaborador'] ?? ('#' . $e_c['id_utilizador']);
}
$ordem_nome = fn($t) => strtr(mb_strtolower((string)$t, 'UTF-8'), ['á'=>'a','à'=>'a','â'=>'a','ã'=>'a','é'=>'e','ê'=>'e','í'=>'i','ó'=>'o','ô'=>'o','õ'=>'o','ú'=>'u','ç'=>'c']);
uasort($colabs_com_eq, fn($a, $b) => strnatcmp($ordem_nome($a), $ordem_nome($b)));

// --- BUSCAR DADOS GERAIS (Apenas se for admin/administrativo) ---
$eq_gerais = [];
$movs_js = [];
if ($is_admin) {
    $sql_geral = "SELECT e.*, c.nome AS nome_cliente, o.nome AS nome_obra, o.referencia_obra AS ref_obra, col.nome AS nome_colab_levou
                  FROM equipamentos e
                  LEFT JOIN clientes c ON e.id_cliente = c.id
                  LEFT JOIN obras o ON e.id_obra = o.id
                  LEFT JOIN utilizadores col ON e.id_colaborador_levou = col.id
                  WHERE e.tipo = 'geral'
                  ORDER BY e.data_registo DESC";
    $eq_gerais = $pdo->query($sql_geral)->fetchAll(PDO::FETCH_ASSOC);

    // --- BUSCAR HISTÓRICO DE MOVIMENTOS ---
    if ($tem_historico && !empty($eq_gerais)) {
        $ids = array_column($eq_gerais, 'id');
        $marcadores = implode(',', array_fill(0, count($ids), '?'));
        $sql_mov = "SELECT m.*, c.nome AS nome_cliente, o.nome AS nome_obra, o.referencia_obra AS ref_obra, col.nome AS nome_colab, u.nome AS nome_registo
                    FROM equipamentos_movimentos m
                    LEFT JOIN clientes c ON m.id_cliente = c.id
                    LEFT JOIN obras o ON m.id_obra = o.id
                    LEFT JOIN utilizadores col ON m.id_colaborador_levou = col.id
                    LEFT JOIN utilizadores u ON m.id_utilizador_registo = u.id
                    WHERE m.id_equipamento IN ($marcadores)
                    ORDER BY m.data_evento DESC, m.id DESC";
        $stmt_mov = $pdo->prepare($sql_mov);
        $stmt_mov->execute($ids);
        $movimentos = $stmt_mov->fetchAll(PDO::FETCH_ASSOC);

        foreach ($movimentos as $m) {
            $loc = $m['localizacao'];
            if ($loc === 'obra') { $loc_lbl = 'Em obra'; }
            elseif ($loc === 'emprestado') { $loc_lbl = 'Emprestado'; }
            else { $loc_lbl = 'Na empresa'; }

            $detalhe = '';
            if ($loc === 'obra') {
                $detalhe = trim(($m['nome_cliente'] ?? '')
                    . (!empty($m['nome_obra']) ? ' · ' . $m['nome_obra'] : '')
                    . (!empty($m['ref_obra']) ? ' (' . $m['ref_obra'] . ')' : ''));
                if (!empty($m['nome_colab'])) $detalhe .= ($detalhe ? ' — ' : '') . 'Levou: ' . $m['nome_colab'];
            } elseif ($loc === 'emprestado') {
                $detalhe = !empty($m['nome_cliente']) ? $m['nome_cliente'] : ($m['emprestado_nome'] ?? '');
            }

            $movs_js[(int)$m['id_equipamento']][] = [
                'data'    => date('d/m/Y', strtotime($m['data_evento'])),
                'loc'     => $loc,
                'loc_lbl' => $loc_lbl,
                'detalhe' => $detalhe,
                'obs'     => $m['observacoes'] ?? '',
                'registo' => $m['nome_registo'] ?? ''
            ];
        }
    }
}
// Aba a mostrar após submit (volta ao separador onde o utilizador estava)
$aba_ativa = (($_POST['aba'] ?? '') === 'geral' && $is_admin) ? 'geral' : 'individual';
?>
<!DOCTYPE html>
<html lang="pt-PT">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Equipamentos - Gestão MAB</title>
    <?php /* REGRA DE OURO 8: ícone MAB no separador */ ?>
    <link rel="icon" type="image/png" href="/mab.png">
    <link rel="apple-touch-icon" href="/mab.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { font-family: system-ui, -apple-system, sans-serif; background-color: #f7f9fc; margin: 0; color: #333; padding-bottom: 50px; }
        * { box-sizing: border-box; }
        
        /* TOPBAR MINIMALISTA */
        .topbar { background-color: #fff; padding: 15px 40px; box-shadow: 0 4px 20px rgba(0,0,0,0.05); display: flex; justify-content: space-between; align-items: center; position: sticky; top: 0; z-index: 100; }
        .topbar-left .logo { height: 40px; }
        .topbar-center { display: flex; align-items: center; gap: 20px; flex: 1;}
        .topbar-right { display: flex; align-items: center; gap: 30px; }
        
        .btn-voltar { width: 40px; height: 40px; border-radius: 50%; background: #fff; border: 1px solid #e8ecf1; color: #576574; display: inline-flex; align-items: center; justify-content: center; text-decoration: none; font-size: 16px; transition: all 0.2s ease; box-shadow: 0 2px 6px rgba(0,0,0,0.04); flex-shrink: 0; }
        .btn-voltar:hover { background: #eef2f6; color: #34495e; border-color: #d5dce4; transform: translateX(-2px); }
        .btn-voltar:active { transform: scale(0.94); }
        
        .user-menu { position: relative; display: inline-block;}
        .user-btn { background: none; border: none; font-size: 15px; font-weight: 600; color: #2c3e50; cursor: pointer; display: flex; align-items: center; gap: 8px; }
        .user-avatar { background: #e74c3c; color: white; width: 28px; height: 28px; border-radius: 50%; display: flex; justify-content: center; align-items: center; font-size: 14px; border: 2px solid #fadbd8; }
        
        .dropdown-content { display: none; position: absolute; right: 0; top: 100%; margin-top: 10px; background: #fff; min-width: 180px; box-shadow: 0 10px 30px rgba(0,0,0,0.1); border-radius: 8px; overflow: hidden; border: 1px solid #f1f2f6; z-index:200;}
        .dropdown-content a { color: #576574; padding: 12px 20px; text-decoration: none; display: block; font-size: 14px; }
        .dropdown-content a:hover { background: #fdf2f2; color: #4a69bd; }
        .user-menu:hover .dropdown-content, .user-menu.aberto .dropdown-content { display: block; }

        .container { max-width: 1300px; margin: 30px auto; padding: 0 15px; }
        .page-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px; flex-wrap: wrap; gap: 15px; }
        .page-title { margin: 0; color: #2c3e50; font-size: 24px; font-weight: 800; display: flex; align-items: center; gap: 10px; }
        .page-title i { color: #4a69bd; }

        .alert { padding: 15px; border-radius: 8px; margin-bottom: 20px; text-align: center; font-weight: 600; }
        .alert-erro { background: #fdeded; color: #e74c3c; border: 1px solid #f9cccc; }
        .alert-sucesso { background: #eef2fa; color: #4a69bd; border: 1px solid #d9e2f3; }

        /* TABS */
        .tabs { display: flex; gap: 10px; margin-bottom: 25px; border-bottom: 2px solid #e1e5ea; padding-bottom: 10px; overflow-x: auto; }
        .tab-btn { background: transparent; color: #7f8c8d; border: none; padding: 10px 20px; font-weight: 700; font-size: 14px; cursor: pointer; transition: 0.2s; border-radius: 8px; white-space: nowrap;}
        .tab-btn:hover { color: #4a69bd; background: #eef2fa; }
        .tab-btn.active { background: #4a69bd; color: #fff; box-shadow: 0 4px 10px rgba(74, 105, 189, 0.2); }
        .tab-content { display: none; animation: fadeIn 0.3s; }
        .tab-content.active { display: block; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }

        /* CARDS & FORMS */
        .card { background: #fff; padding: 25px; border-radius: 12px; border: 1px solid #e1e5ea; box-shadow: 0 4px 15px rgba(0,0,0,0.02); margin-bottom: 20px; }
        .card-header { margin: 0 0 20px 0; padding-bottom: 15px; border-bottom: 1px solid #f1f2f6; font-size: 16px; font-weight: 700; color: #2c3e50; display: flex; justify-content: space-between; align-items: center; }
        
        .btn-novo { background: #4a69bd; color: #fff; border: none; padding: 8px 15px; border-radius: 6px; font-weight: 600; font-size: 13px; cursor: pointer; transition: 0.2s; display: inline-flex; align-items: center; gap: 6px; }
        .btn-novo:hover { background: #3c559c; }

        /* FILTRO RÁPIDO POR LOCALIZAÇÃO */
        .loc-filtros { display: flex; gap: 8px; margin-bottom: 15px; flex-wrap: wrap; }
        .loc-chip { background: #f1f2f6; color: #576574; border: 1px solid #e1e5ea; padding: 6px 14px; border-radius: 20px; font-size: 12px; font-weight: 700; cursor: pointer; transition: 0.2s; }
        .loc-chip:hover { background: #e8edf7; color: #4a69bd; }
        .loc-chip.active { background: #4a69bd; color: #fff; border-color: #4a69bd; }
        .loc-print { margin-left: auto; background: #fff; color: #34495e; border: 1px solid #cfd6de; padding: 6px 14px; border-radius: 20px; font-size: 12px; font-weight: 700; text-decoration: none; display: inline-flex; align-items: center; gap: 6px; transition: 0.2s; }
        .loc-print:hover { background: #34495e; color: #fff; border-color: #34495e; }
        .ind-acoes { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; justify-content: flex-end; }
        .ind-print { margin-left: 0; }
        /* Seleção de entregas para imprimir num só auto */
        .c-sel { width: 42px; text-align: center; }
        .c-sel input { width: 18px; height: 18px; cursor: pointer; accent-color: #4a69bd; vertical-align: middle; }
        tr.selecionado td { background: #f3f6fd; }
        .btn-sel-print { background: #4a69bd; color: #fff; border-color: #4a69bd; cursor: pointer; font-family: inherit; }
        .btn-sel-print:hover { background: #3c559c; border-color: #3c559c; }
        .btn-sel-print[disabled] { background: #fff; color: #95a5a6; border-color: #e1e5ea; cursor: default; }
        .sel-aviso { width: 100%; font-size: 12px; color: #7f8c8d; font-weight: 500; text-align: right; }
        .ind-sel { padding: 6px 10px; border: 1px solid #cfd6de; border-radius: 20px; font-size: 12px; font-weight: 600; color: #34495e; background: #fff; font-family: inherit; max-width: 220px; }
        @media (max-width: 768px) { .card-header { flex-wrap: wrap; gap: 10px; } .ind-acoes { width: 100%; justify-content: stretch; } .ind-acoes > * { flex: 1; justify-content: center; max-width: none; } }
        @media (max-width: 560px) { .loc-print { margin-left: 0; width: 100%; justify-content: center; padding: 9px 14px; } }

        /* TABELA */
        .table-responsive { width: 100%; overflow-x: auto; border-radius: 8px; border: 1px solid #e1e5ea; }
        table { width: 100%; border-collapse: collapse; min-width: 900px; background: #fff; }
        th { padding: 15px; background: #f8f9fa; color: #576574; font-size: 11px; text-transform: uppercase; text-align: left; border-bottom: 2px solid #e1e5ea; }
        td { padding: 15px; border-bottom: 1px solid #f1f2f6; font-size: 14px; color: #2c3e50; vertical-align: middle; }
        tr:hover { background: #fdfcfb; }
        
        .text-center { text-align: center !important; }
        
        .badge-estado { padding: 4px 10px; border-radius: 6px; font-weight: 700; font-size: 11px; text-transform: uppercase;}
        .estado-novo { background: #e8f5e9; color: #2ecc71; border: 1px solid #c8e6c9;}
        .estado-bom { background: #eef2fa; color: #4a69bd; border: 1px solid #d9e2f3;}
        .estado-usado { background: #fef5e7; color: #f39c12; border: 1px solid #fdebd0;}
        .estado-avariado { background: #fdeded; color: #e74c3c; border: 1px solid #f9cccc;}

        /* BOTÕES DE AÇÃO NA TABELA */
        .acoes-coluna { display: flex; gap: 5px; justify-content: center; align-items: center; }
        .btn-tab-acao { color: #576574; background: #f1f2f6; padding: 8px; border-radius: 6px; text-decoration: none; transition: 0.2s; display: inline-flex; width: 32px; height: 32px; align-items: center; justify-content: center; font-size:15px; border:none; cursor:pointer;}
        .btn-tab-acao:hover { background: #e1e5ea; color: #2c3e50; }
        .btn-imprimir { color: #3498db; background: #f0f8ff; }
        .btn-imprimir:hover { color: #2980b9; background: #d6eaf8; }
        .btn-hist { color: #8e44ad; background: #f5eefb; }
        .btn-hist:hover { color: #6c3483; background: #ebddf7; }
        .btn-editar { color: #7f8c8d; background: #f4f6f7; }
        .btn-editar:hover { color: #2c3e50; background: #e1e5ea; }
        .btn-duplicar { color: #8e44ad; background: #f5eef8; }
        .btn-duplicar:hover { color: #6c3483; background: #ebdef0; }
        .btn-del { color: #e74c3c; background: #fdeded; }
        .btn-del:hover { background: #fadbd8; color: #c0392b; }

        /* MODAIS */
        .modal { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.6); z-index: 1001; justify-content: center; align-items: center; backdrop-filter: blur(2px); }
        .modal.show { display: flex; }
        .modal-content { background: #fff; padding: 30px; border-radius: 12px; width: 90%; max-width: 500px; box-shadow: 0 20px 50px rgba(0,0,0,0.2); max-height: 90vh; overflow-y: auto;}
        
        .modal-header { display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #f1f2f6; padding-bottom: 15px; margin-bottom: 20px; }
        .modal-header h3 { margin: 0; color: #4a69bd; font-size: 18px; display: flex; align-items: center; gap: 8px; }
        .close-btn { color: #95a5a6; font-size: 22px; cursor: pointer; background: none; border: none; padding: 0; transition: 0.2s; }
        .close-btn:hover { color: #333; }

        .form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 15px; }
        .form-group { display: flex; flex-direction: column; }
        .form-group.full { grid-column: 1 / -1; }
        .form-group label { font-weight: 700; font-size: 11px; color: #576574; text-transform: uppercase; margin-bottom: 8px; }
        .form-group input, .form-group select, .form-group textarea { padding: 12px 15px; border: 1px solid #dcdde1; border-radius: 8px; font-size: 14px; outline: none; transition: border-color 0.2s; font-family: inherit; background: #fff; }
        .form-group input:focus, .form-group select:focus, .form-group textarea:focus { border-color: #4a69bd; box-shadow: 0 0 0 3px rgba(74, 105, 189, 0.1); }

        .modal-btns { display: flex; gap: 10px; margin-top: 25px; }
        .modal-btns button { flex: 1; padding: 12px; border: none; border-radius: 8px; font-weight: 600; cursor: pointer; }
        .btn-cancelar { background: #f1f2f6; color: #576574; transition: 0.2s; }
        .btn-cancelar:hover { background: #e1e5ea; }
        .btn-confirmar { background: #4a69bd; color: #fff; transition: 0.2s; }
        .btn-confirmar:hover { filter: brightness(0.9); }
        .btn-danger { background: #e74c3c; color: #fff; transition: 0.2s; }
        .btn-danger:hover { background: #c0392b; }

        /* HISTÓRICO (TIMELINE) */
        .hist-timeline { display: flex; flex-direction: column; }
        .hist-item { display: flex; gap: 15px; padding: 12px 0; border-bottom: 1px solid #f1f2f6; }
        .hist-item:last-child { border-bottom: none; }
        .hist-data { min-width: 78px; font-weight: 700; font-size: 12px; color: #576574; padding-top: 2px; }
        .hist-conteudo { flex: 1; }
        .hist-detalhe { font-size: 13px; color: #2c3e50; margin-top: 6px; }
        .hist-obs { font-size: 12px; color: #7f8c8d; margin-top: 4px; font-style: italic; }
        .hist-registo { font-size: 11px; color: #95a5a6; margin-top: 4px; }

        @media (max-width: 768px) {
            .topbar { padding: 12px 15px; }
            .topbar-right { gap: 12px; }
            .btn-voltar { width: 38px; height: 38px; }
            .user-nome { display: none; }
            .form-grid { grid-template-columns: 1fr; }
            .card { padding: 15px; }
            .sel-aviso { text-align: left; }
            /* Tabelas passam a cartões */
            .table-responsive { border: none; overflow: visible; }
            table { min-width: 0; background: transparent; }
            thead { display: none; }
            table, tbody, tr, td { display: block; width: 100%; }
            tbody tr { background: #fff; border: 1px solid #e1e5ea; border-radius: 12px; margin-bottom: 12px; padding: 10px 0; position: relative; }
            td, td.text-center { border: none; padding: 4px 15px; text-align: left !important; }
            tr.selecionado { background: #f3f6fd; border-color: #4a69bd; }
            tr.selecionado td { background: transparent; }
            .ind-acoes > .sel-aviso { flex: 1 1 100%; }
            td[data-label]::before { content: attr(data-label) ": "; font-size: 11px; font-weight: 700; color: #95a5a6; text-transform: uppercase; }
            td.c-titulo { font-size: 15px; padding-right: 55px; }
            td.c-sel { position: absolute; top: 8px; right: 8px; width: auto; padding: 6px; }
            td.c-sel input { width: 22px; height: 22px; }
            td.c-acoes { padding-top: 10px; }
            .acoes-coluna { justify-content: flex-start; }
            td:empty { display: none; }
        }
    </style>
</head>
<body>

    <header class="topbar">
        <div class="topbar-left"><a href="index.php"><img src="mab.png" alt="MAB" class="logo"></a></div>
        <div class="topbar-center"></div> <div class="topbar-right">
            <a href="index.php" class="btn-voltar" title="Voltar" aria-label="Voltar"><i class="fa-solid fa-arrow-left"></i></a>
            <div class="user-menu">
                <button type="button" class="user-btn">
                    <div class="user-avatar"><i class="fa-solid fa-user"></i></div>
                    <span class="user-nome"><?= htmlspecialchars($primeiro_nome) ?></span> 
                    <i class="fa-solid fa-chevron-down" style="font-size: 10px;"></i>
                </button>
                <div class="dropdown-content">
                    <a href="perfil.php">O meu perfil</a>
                    <?php if ($is_admin): ?>
                        <a href="configuracoes.php">Configurações</a>
                    <?php endif; ?>
                    <a href="index.php?logout=1" style="color: #e74c3c; border-top: 1px solid #f1f2f6;">Sair</a>
                </div>
            </div>
        </div>
    </header>

    <div class="container">
        <?php if ($mensagem): ?><div class="alert alert-<?= htmlspecialchars($tipo_mensagem) ?>"><i class="fa-solid fa-circle-info"></i> <?= htmlspecialchars($mensagem) ?></div><?php endif; ?>

        <div class="page-header">
            <h1 class="page-title"><i class="fa-solid fa-toolbox"></i> Equipamentos</h1>
        </div>

        <div class="tabs">
            <button class="tab-btn <?= $aba_ativa === 'individual' ? 'active' : '' ?>" onclick="openTab(event, 'individual')"><i class="fa-solid fa-user-gear"></i> <?= $is_admin ? 'Equipamento por Colaborador' : 'O Meu Equipamento' ?></button>
            <?php if ($is_admin): ?>
                <button class="tab-btn <?= $aba_ativa === 'geral' ? 'active' : '' ?>" onclick="openTab(event, 'geral')"><i class="fa-solid fa-boxes-stacked"></i> Equipamento Geral da Empresa</button>
            <?php endif; ?>
        </div>

        <div id="individual" class="tab-content <?= $aba_ativa === 'individual' ? 'active' : '' ?>">
            <div class="card">
                <div class="card-header">
                    <span><i class="fa-solid fa-list"></i> Lista de Equipamentos Distribuídos</span>
                    <div class="ind-acoes">
                        <?php if (!empty($eq_individuais)): ?>
                            <?php if ($is_admin && count($colabs_com_eq) > 1): ?>
                                <select id="ind_colab_print" class="ind-sel" onchange="atualizarImprimirInd()" title="Colaborador a imprimir">
                                    <option value="">Todos os colaboradores</option>
                                    <?php foreach ($colabs_com_eq as $id_c => $nome_c): ?><option value="<?= $id_c ?>"><?= htmlspecialchars($nome_c) ?></option><?php endforeach; ?>
                                </select>
                            <?php endif; ?>
                            <button type="button" id="btnImprimirSel" class="loc-print ind-print btn-sel-print" onclick="imprimirSelecionados()" disabled title="Marque na lista as entregas a imprimir num só auto de entrega"><i class="fa-solid fa-file-signature"></i> <span>Auto de entrega (<span id="nSel">0</span>)</span></button>
                            <a id="btnImprimirInd" href="equipamentos_colaboradores_pdf.php" target="_blank" class="loc-print ind-print" title="Imprimir o equipamento entregue, por colaborador (ordem alfabética) e por data de entrega"><i class="fa-solid fa-print"></i> Imprimir</a>
                        <?php endif; ?>
                        <?php if ($is_admin): ?>
                            <button type="button" class="btn-novo" onclick="abrirModalNovoInd()"><i class="fa-solid fa-plus"></i> Atribuir Novo</button>
                        <?php endif; ?>
                        <?php if (!empty($eq_individuais)): ?>
                            <div class="sel-aviso" id="selAviso"><i class="fa-regular fa-square-check"></i> Marque as entregas para as imprimir num só auto de entrega (uma folha por colaborador).</div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th class="c-sel"><input type="checkbox" id="selTodos" title="Marcar / desmarcar todos" onclick="selecionarTodos(this)"></th>
                                <th style="width:100px;">Entrega</th>
                                <?php if ($is_admin): ?><th>Colaborador</th><?php endif; ?>
                                <th>Descrição do Equipamento</th>
                                <th>Marca / Modelo</th>
                                <th>Nº de Série</th>
                                <th class="text-center">Estado</th>
                                <th>Observações</th>
                                <th class="text-center" style="width:150px;">Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($eq_individuais)): ?>
                                <tr><td colspan="<?= $is_admin ? 9 : 8 ?>" class="text-center" style="padding: 40px; color: #7f8c8d;">Nenhum equipamento registado.</td></tr>
                            <?php else: ?>
                                <?php foreach ($eq_individuais as $e): 
                                    $classe_estado = 'estado-bom';
                                    if ($e['estado'] === 'Novo') $classe_estado = 'estado-novo';
                                    if ($e['estado'] === 'Usado') $classe_estado = 'estado-usado';
                                    if ($e['estado'] === 'Avariado') $classe_estado = 'estado-avariado';
                                ?>
                                <tr>
                                    <td class="c-sel"><input type="checkbox" class="sel-entrega" value="<?= (int)$e['id'] ?>" data-colab="<?= (int)$e['id_utilizador'] ?>" aria-label="Selecionar <?= htmlspecialchars($e['descricao']) ?>" onchange="atualizarSelecao()"></td>
                                    <td data-label="Entrega"><?= date('d/m/Y', strtotime($e['data_registo'])) ?></td>
                                    <?php if ($is_admin): ?><td data-label="Colaborador"><strong style="color:#2c3e50;"><?= htmlspecialchars($e['nome_colaborador'] ?? 'N/D') ?></strong></td><?php endif; ?>
                                    <td class="c-titulo" style="font-weight:600; color:#4a69bd;"><?= htmlspecialchars($e['descricao']) ?></td>
                                    <td data-label="Marca / Modelo"><?= htmlspecialchars($e['marca']) ?></td>
                                    <td data-label="Nº de Série"><?= htmlspecialchars($e['numero_serie']) ?></td>
                                    <td data-label="Estado" class="text-center"><span class="badge-estado <?= $classe_estado ?>"><?= htmlspecialchars($e['estado']) ?></span></td>
                                    <td style="font-size:12px; color:#7f8c8d;"><?= htmlspecialchars($e['observacoes']) ?></td>
                                    
                                    <td class="text-center c-acoes">
                                        <div class="acoes-coluna">
                                            <a href="equipamento_pdf.php?id=<?= $e['id'] ?>" target="_blank" class="btn-tab-acao btn-imprimir" title="Imprimir Auto de Entrega"><i class="fa-solid fa-print"></i></a>
                                            <?php if ($is_admin): ?>
                                                <button type="button" class="btn-tab-acao btn-editar" 
                                                    data-id="<?= $e['id'] ?>"
                                                    data-id_utilizador="<?= $e['id_utilizador'] ?>"
                                                    data-data="<?= $e['data_registo'] ?>"
                                                    data-desc="<?= htmlspecialchars($e['descricao']) ?>"
                                                    data-marca="<?= htmlspecialchars($e['marca']) ?>"
                                                    data-serie="<?= htmlspecialchars($e['numero_serie']) ?>"
                                                    data-estado="<?= htmlspecialchars($e['estado']) ?>"
                                                    data-obs="<?= htmlspecialchars($e['observacoes']) ?>"
                                                    onclick="abrirModalEditarInd(this)">
                                                    <i class="fa-solid fa-pen-to-square"></i>
                                                </button>
                                                <button type="button" class="btn-tab-acao btn-duplicar" title="Duplicar (atribuir um equipamento igual, ao mesmo ou a outro colaborador)"
                                                    data-id_utilizador="<?= $e['id_utilizador'] ?>"
                                                    data-desc="<?= htmlspecialchars($e['descricao']) ?>"
                                                    data-marca="<?= htmlspecialchars($e['marca']) ?>"
                                                    data-estado="<?= htmlspecialchars($e['estado']) ?>"
                                                    data-obs="<?= htmlspecialchars($e['observacoes']) ?>"
                                                    onclick="abrirModalDuplicarInd(this)">
                                                    <i class="fa-regular fa-copy"></i>
                                                </button>
                                                <button type="button" class="btn-tab-acao btn-del" onclick="abrirModalApagar(<?= $e['id'] ?>)"><i class="fa-solid fa-trash-can"></i></button>
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
        <div id="geral" class="tab-content <?= $aba_ativa === 'geral' ? 'active' : '' ?>">
            <div class="card">
                <div class="card-header">
                    <span><i class="fa-solid fa-boxes-stacked"></i> Máquinas e Equipamentos da Empresa</span>
                    <button type="button" class="btn-novo" onclick="abrirModal('modalGeral')"><i class="fa-solid fa-plus"></i> Registar Novo</button>
                </div>

                <?php if (!empty($eq_gerais)): ?>
                <div class="loc-filtros">
                    <button type="button" class="loc-chip active" onclick="filtrarLista(this,'todos')">Todos</button>
                    <button type="button" class="loc-chip" onclick="filtrarLista(this,'empresa')">Na empresa</button>
                    <button type="button" class="loc-chip" onclick="filtrarLista(this,'obra')">Em obra</button>
                    <button type="button" class="loc-chip" onclick="filtrarLista(this,'emprestado')">Emprestado</button>
                    <a id="btnImprimirLista" href="equipamentos_lista_pdf.php?loc=todos" target="_blank" class="loc-print" title="Imprimir a listagem do separador escolhido"><i class="fa-solid fa-print"></i> Imprimir <span id="btnImprimirLista_txt">todos</span></a>
                </div>
                <?php endif; ?>
                
                <div class="table-responsive">
                    <table id="tabela-geral">
                        <thead>
                            <tr>
                                <th style="width:100px;">Data Compra</th>
                                <th>Descrição / Máquina</th>
                                <th>Marca / Modelo</th>
                                <th>Nº de Série</th>
                                <th>Fornecedor</th>
                                <th>Localização</th>
                                <th>Observações</th>
                                <th class="text-center" style="width:160px;">Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($eq_gerais)): ?>
                                <tr><td colspan="8" class="text-center" style="padding: 40px; color: #7f8c8d;">Nenhum equipamento geral registado.</td></tr>
                            <?php else: ?>
                                <?php foreach ($eq_gerais as $e): 
                                    $loc = $e['localizacao'] ?? 'empresa';
                                    if ($loc === 'obra') {
                                        $loc_label = 'Em Obra'; $loc_class = 'estado-usado';
                                    } elseif ($loc === 'emprestado') {
                                        $loc_label = 'Emprestado'; $loc_class = 'estado-avariado';
                                    } else {
                                        $loc_label = 'Na Empresa'; $loc_class = 'estado-novo';
                                    }
                                ?>
                                <tr data-loc="<?= htmlspecialchars($loc) ?>">
                                    <td data-label="Data Compra"><?= date('d/m/Y', strtotime($e['data_registo'])) ?></td>
                                    <td class="c-titulo" style="font-weight:600; color:#4a69bd;"><?= htmlspecialchars($e['descricao']) ?></td>
                                    <td data-label="Marca / Modelo"><?= htmlspecialchars($e['marca']) ?></td>
                                    <td data-label="Nº de Série"><?= htmlspecialchars($e['numero_serie']) ?></td>
                                    <td data-label="Fornecedor"><?= htmlspecialchars($e['fornecedor']) ?></td>
                                    <td data-label="Localização">
                                        <span class="badge-estado <?= $loc_class ?>"><?= $loc_label ?></span>
                                        <?php if (!empty($movs_js[(int)$e['id']][0]['data'])): ?>
                                            <div style="font-size:11px; color:#4a69bd; margin-top:5px; font-weight:600;"><i class="fa-regular fa-calendar"></i> desde <?= htmlspecialchars($movs_js[(int)$e['id']][0]['data']) ?></div>
                                        <?php endif; ?>
                                        <?php if ($loc === 'obra'): ?>
                                            <div style="font-size:11px; color:#7f8c8d; margin-top:5px;">
                                                <?= htmlspecialchars($e['nome_cliente'] ?? '') ?><?= !empty($e['nome_obra']) ? ' · ' . htmlspecialchars($e['nome_obra']) : '' ?><?= !empty($e['ref_obra']) ? ' (' . htmlspecialchars($e['ref_obra']) . ')' : '' ?>
                                                <?php if (!empty($e['nome_colab_levou'])): ?><br>Levou: <strong><?= htmlspecialchars($e['nome_colab_levou']) ?></strong><?php endif; ?>
                                            </div>
                                        <?php elseif ($loc === 'emprestado'): ?>
                                            <div style="font-size:11px; color:#7f8c8d; margin-top:5px;">
                                                <?= htmlspecialchars(!empty($e['nome_cliente']) ? $e['nome_cliente'] : ($e['emprestado_nome'] ?? '')) ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td style="font-size:12px; color:#7f8c8d;"><?= htmlspecialchars($e['observacoes']) ?></td>
                                    <td class="text-center c-acoes">
                                        <div class="acoes-coluna">
                                            <button type="button" class="btn-tab-acao btn-hist" title="Histórico de movimentos"
                                                data-id="<?= $e['id'] ?>" data-desc="<?= htmlspecialchars($e['descricao']) ?>"
                                                onclick="abrirHistorico(this)">
                                                <i class="fa-solid fa-clock-rotate-left"></i>
                                            </button>
                                            <a href="equipamento_pdf.php?id=<?= $e['id'] ?>" target="_blank" class="btn-tab-acao btn-imprimir" title="Imprimir Ficha"><i class="fa-solid fa-print"></i></a>
                                            <button type="button" class="btn-tab-acao btn-editar" 
                                                data-id="<?= $e['id'] ?>"
                                                data-data="<?= $e['data_registo'] ?>"
                                                data-desc="<?= htmlspecialchars($e['descricao']) ?>"
                                                data-marca="<?= htmlspecialchars($e['marca']) ?>"
                                                data-serie="<?= htmlspecialchars($e['numero_serie']) ?>"
                                                data-forn="<?= htmlspecialchars($e['fornecedor']) ?>"
                                                data-obs="<?= htmlspecialchars($e['observacoes']) ?>"
                                                data-localizacao="<?= htmlspecialchars($loc) ?>"
                                                data-cliente="<?= htmlspecialchars($e['id_cliente'] ?? '') ?>"
                                                data-obra="<?= htmlspecialchars($e['id_obra'] ?? '') ?>"
                                                data-colab="<?= htmlspecialchars($e['id_colaborador_levou'] ?? '') ?>"
                                                data-empnome="<?= htmlspecialchars($e['emprestado_nome'] ?? '') ?>"
                                                onclick="abrirModalEditarGeral(this)">
                                                <i class="fa-solid fa-pen-to-square"></i>
                                            </button>
                                            <button type="button" class="btn-tab-acao btn-del" onclick="abrirModalApagar(<?= $e['id'] ?>)"><i class="fa-solid fa-trash-can"></i></button>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                <tr id="geral-sem-filtro" style="display:none;"><td colspan="8" class="text-center" style="padding: 40px; color: #7f8c8d;">Nenhum equipamento nesta localização.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php endif; ?>

    </div>

    <?php if ($is_admin): ?>
    <div class="modal" id="modalInd">
        <div class="modal-content">
            <div class="modal-header">
                <h3 id="modalInd_titulo"><i class="fa-solid fa-user-gear"></i> Atribuir Equipamento</h3>
                <button type="button" class="close-btn" onclick="fecharModais()">&times;</button>
            </div>
            <form method="POST" id="formInd">
                <?php /* REGRA DE OURO 7 */ ?><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(gerar_csrf_token()) ?>">
                <div class="form-grid">
                    <div class="form-group full">
                        <label>Colaborador *</label>
                        <select name="id_utilizador" id="novo_colab_ind" required>
                            <option value="">-- Selecione --</option>
                            <?php foreach($colaboradores as $c): ?>
                                <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['nome']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label>Data de Entrega *</label>
                        <input type="date" name="data_registo" id="novo_data_ind" value="<?= date('Y-m-d') ?>" required>
                    </div>

                    <div class="form-group">
                        <label>Estado *</label>
                        <select name="estado" id="novo_est_ind" required>
                            <option value="Novo">Novo</option>
                            <option value="Bom" selected>Em Bom Estado</option>
                            <option value="Usado">Usado / Desgastado</option>
                            <option value="Avariado">Avariado</option>
                        </select>
                    </div>

                    <div class="form-group full">
                        <label>Descrição do Equipamento *</label>
                        <input type="text" name="descricao" id="novo_desc_ind" required placeholder="Ex: Berbequim, Rebarbadora, Computador...">
                    </div>

                    <div class="form-group">
                        <label>Marca / Modelo</label>
                        <input type="text" name="marca" id="novo_marca_ind" placeholder="Ex: Bosch, Makita...">
                    </div>

                    <div class="form-group">
                        <label>Número de Série</label>
                        <input type="text" name="numero_serie" id="novo_serie_ind" placeholder="Ex: SN-123456789">
                    </div>

                    <div class="form-group full">
                        <label>Observações</label>
                        <textarea name="observacoes" id="novo_obs_ind" rows="2" placeholder="Falta de carregador, riscos, etc..."></textarea>
                    </div>
                </div>
                
                <div class="modal-btns">
                    <button type="button" onclick="fecharModais()" class="btn-cancelar">Cancelar</button>
                    <input type="hidden" name="aba" value="individual">
                    <button type="submit" name="btn_gravar_individual" class="btn-confirmar">Gravar Atribuição</button>
                </div>
            </form>
        </div>
    </div>

    <div class="modal" id="modalEditarInd">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fa-solid fa-pen-to-square"></i> Editar Equipamento do Colaborador</h3>
                <button type="button" class="close-btn" onclick="fecharModais()">&times;</button>
            </div>
            <form method="POST">
                <?php /* REGRA DE OURO 7 */ ?><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(gerar_csrf_token()) ?>">
                <input type="hidden" name="id_editar" id="edit_id_ind">
                <div class="form-grid">
                    <div class="form-group full">
                        <label>Colaborador *</label>
                        <select name="id_utilizador" id="edit_colab_ind" required>
                            <option value="">-- Selecione --</option>
                            <?php foreach($colaboradores as $c): ?>
                                <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['nome']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label>Data de Entrega *</label>
                        <input type="date" name="data_registo" id="edit_data_ind" required>
                    </div>

                    <div class="form-group">
                        <label>Estado *</label>
                        <select name="estado" id="edit_est_ind" required>
                            <option value="Novo">Novo</option>
                            <option value="Bom">Em Bom Estado</option>
                            <option value="Usado">Usado / Desgastado</option>
                            <option value="Avariado">Avariado</option>
                        </select>
                    </div>

                    <div class="form-group full">
                        <label>Descrição do Equipamento *</label>
                        <input type="text" name="descricao" id="edit_desc_ind" required>
                    </div>

                    <div class="form-group">
                        <label>Marca / Modelo</label>
                        <input type="text" name="marca" id="edit_marca_ind">
                    </div>

                    <div class="form-group">
                        <label>Número de Série</label>
                        <input type="text" name="numero_serie" id="edit_serie_ind">
                    </div>

                    <div class="form-group full">
                        <label>Observações</label>
                        <textarea name="observacoes" id="edit_obs_ind" rows="2"></textarea>
                    </div>
                </div>
                
                <div class="modal-btns">
                    <button type="button" onclick="fecharModais()" class="btn-cancelar">Cancelar</button>
                    <input type="hidden" name="aba" value="individual">
                    <button type="submit" name="btn_editar_individual" class="btn-confirmar">Atualizar Atribuição</button>
                </div>
            </form>
        </div>
    </div>

    <div class="modal" id="modalGeral">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fa-solid fa-boxes-stacked"></i> Registar Equipamento Geral</h3>
                <button type="button" class="close-btn" onclick="fecharModais()">&times;</button>
            </div>
            <form method="POST">
                <?php /* REGRA DE OURO 7 */ ?><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(gerar_csrf_token()) ?>">
                <div class="form-grid">
                    <div class="form-group full">
                        <label>Descrição da Máquina / Equipamento *</label>
                        <input type="text" name="descricao" required placeholder="Ex: Betoneira, Andaime...">
                    </div>

                    <div class="form-group">
                        <label>Data de Compra *</label>
                        <input type="date" name="data_registo" value="<?= date('Y-m-d') ?>" required>
                    </div>

                    <div class="form-group">
                        <label>Fornecedor</label>
                        <input type="text" name="fornecedor" placeholder="Ex: Leroy Merlin">
                    </div>

                    <div class="form-group">
                        <label>Marca / Modelo</label>
                        <input type="text" name="marca" placeholder="Ex: Hilti">
                    </div>

                    <div class="form-group">
                        <label>Número de Série</label>
                        <input type="text" name="numero_serie">
                    </div>

                    <div class="form-group full">
                        <label>Localização *</label>
                        <select name="localizacao" id="ng_loc" onchange="toggleLocal('ng')">
                            <option value="empresa" selected>Na empresa</option>
                            <option value="obra">Na obra</option>
                            <option value="emprestado">Emprestado</option>
                        </select>
                    </div>

                    <div class="form-group full" id="ng_grpCliente" style="display:none;">
                        <label>Cliente *</label>
                        <select name="id_cliente" id="ng_cliente" onchange="filtrarObras('ng')">
                            <option value="">-- Selecione o cliente --</option>
                            <?php foreach($clientes as $c): ?>
                                <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['nome']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group full" id="ng_grpObra" style="display:none;">
                        <label>Obra *</label>
                        <select name="id_obra" id="ng_obra">
                            <option value="">-- Selecione primeiro o cliente --</option>
                        </select>
                    </div>

                    <div class="form-group full" id="ng_grpColab" style="display:none;">
                        <label>Colaborador que levou *</label>
                        <select name="id_colaborador_levou" id="ng_colab">
                            <option value="">-- Selecione --</option>
                            <?php foreach($colaboradores as $c): ?>
                                <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['nome']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group full" id="ng_grpNome" style="display:none;">
                        <label>Nome de quem levou emprestado</label>
                        <input type="text" name="emprestado_nome" id="ng_nome" placeholder="Deixe vazio se escolheu um cliente acima">
                    </div>

                    <div class="form-group full">
                        <label>Observações</label>
                        <textarea name="observacoes" rows="2" placeholder="Onde está guardado? Quem tem a chave?"></textarea>
                    </div>
                </div>
                
                <div class="modal-btns">
                    <button type="button" onclick="fecharModais()" class="btn-cancelar">Cancelar</button>
                    <input type="hidden" name="aba" value="geral">
                    <button type="submit" name="btn_gravar_geral" class="btn-confirmar">Gravar Equipamento</button>
                </div>
            </form>
        </div>
    </div>

    <div class="modal" id="modalEditarGeral">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fa-solid fa-pen-to-square"></i> Editar Equipamento Geral</h3>
                <button type="button" class="close-btn" onclick="fecharModais()">&times;</button>
            </div>
            <form method="POST">
                <?php /* REGRA DE OURO 7 */ ?><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(gerar_csrf_token()) ?>">
                <input type="hidden" name="id_editar" id="edit_id_geral">
                
                <div class="form-grid">
                    <div class="form-group full">
                        <label>Descrição da Máquina / Equipamento *</label>
                        <input type="text" name="descricao" id="edit_desc_geral" required>
                    </div>

                    <div class="form-group">
                        <label>Data de Compra *</label>
                        <input type="date" name="data_registo" id="edit_data_geral" required>
                    </div>

                    <div class="form-group">
                        <label>Fornecedor</label>
                        <input type="text" name="fornecedor" id="edit_forn_geral">
                    </div>

                    <div class="form-group">
                        <label>Marca / Modelo</label>
                        <input type="text" name="marca" id="edit_marca_geral">
                    </div>

                    <div class="form-group">
                        <label>Número de Série</label>
                        <input type="text" name="numero_serie" id="edit_serie_geral">
                    </div>

                    <div class="form-group full">
                        <label>Localização *</label>
                        <select name="localizacao" id="eg_loc" onchange="toggleLocal('eg')">
                            <option value="empresa">Na empresa</option>
                            <option value="obra">Na obra</option>
                            <option value="emprestado">Emprestado</option>
                        </select>
                    </div>

                    <div class="form-group full">
                        <label>Data do acontecimento</label>
                        <input type="date" name="data_evento" id="eg_data_evento">
                        <small style="color:#95a5a6; font-size:11px; margin-top:6px;">Data em que o equipamento mudou de localização (só usada se alterar a localização).</small>
                    </div>

                    <div class="form-group full" id="eg_grpCliente" style="display:none;">
                        <label>Cliente *</label>
                        <select name="id_cliente" id="eg_cliente" onchange="filtrarObras('eg')">
                            <option value="">-- Selecione o cliente --</option>
                            <?php foreach($clientes as $c): ?>
                                <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['nome']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group full" id="eg_grpObra" style="display:none;">
                        <label>Obra *</label>
                        <select name="id_obra" id="eg_obra">
                            <option value="">-- Selecione primeiro o cliente --</option>
                        </select>
                    </div>

                    <div class="form-group full" id="eg_grpColab" style="display:none;">
                        <label>Colaborador que levou *</label>
                        <select name="id_colaborador_levou" id="eg_colab">
                            <option value="">-- Selecione --</option>
                            <?php foreach($colaboradores as $c): ?>
                                <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['nome']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group full" id="eg_grpNome" style="display:none;">
                        <label>Nome de quem levou emprestado</label>
                        <input type="text" name="emprestado_nome" id="eg_nome" placeholder="Deixe vazio se escolheu um cliente acima">
                    </div>

                    <div class="form-group full">
                        <label>Observações</label>
                        <textarea name="observacoes" id="edit_obs_geral" rows="2"></textarea>
                    </div>
                </div>
                
                <div class="modal-btns">
                    <button type="button" onclick="fecharModais()" class="btn-cancelar">Cancelar</button>
                    <input type="hidden" name="aba" value="geral">
                    <button type="submit" name="btn_editar_geral" class="btn-confirmar">Atualizar Equipamento</button>
                </div>
            </form>
        </div>
    </div>

    <div class="modal" id="modalHistorico">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fa-solid fa-clock-rotate-left"></i> Histórico de Movimentos</h3>
                <button type="button" class="close-btn" onclick="fecharModais()">&times;</button>
            </div>
            <p id="hist_titulo" style="margin-top:0; font-weight:600; color:#2c3e50;"></p>
            <div id="hist_corpo"></div>
            <div class="modal-btns">
                <button type="button" onclick="fecharModais()" class="btn-cancelar">Fechar</button>
            </div>
        </div>
    </div>

    <div class="modal" id="modalApagar">
        <div class="modal-content" style="max-width: 400px; text-align: center;">
            <div class="modal-header" style="border-bottom:none; justify-content:center; margin-bottom:10px;">
                <h3 style="color:#e74c3c;"><i class="fa-solid fa-triangle-exclamation"></i> Apagar Registo</h3>
            </div>
            <p style="margin-top:0;">Deseja apagar definitivamente este equipamento?</p>
            <form method="POST">
                <?php /* REGRA DE OURO 7 */ ?><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(gerar_csrf_token()) ?>">
                <input type="hidden" name="id_apagar" id="id_apagar_input">
                <input type="password" name="pass_seguranca" required placeholder="Password de Segurança" style="width:100%; padding:12px; border:1px solid #dcdde1; border-radius:8px; margin: 15px 0;">
                <input type="hidden" name="aba" id="apagar_aba" value="individual">
                <div class="modal-btns">
                    <button type="button" onclick="fecharModais()" class="btn-cancelar">Cancelar</button>
                    <button type="submit" name="btn_confirmar_apagar" class="btn-danger">Apagar</button>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <script>
        // Lista de obras (id, id_cliente, label, ativa) para filtrar por cliente no browser.
        // "ativa" = obra em Planeamento ou Em Curso. As inativas só aparecem na edição (se já estavam guardadas).
        const OBRAS = <?= json_encode(array_map(function($o){
            $label = $o['nome'];
            if (!empty($o['referencia_obra'])) $label .= ' (' . $o['referencia_obra'] . ')';
            $ativa = in_array($o['estado'], ['Planeamento', 'Em Curso']);
            if (!$ativa) $label .= ' — ' . $o['estado'];
            return ['id' => (int)$o['id'], 'id_cliente' => (int)$o['id_cliente'], 'label' => $label, 'ativa' => $ativa];
        }, $obras), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>;

        // Histórico de movimentos por equipamento { id_equipamento: [ {data, loc, loc_lbl, detalhe, obs, registo}, ... ] }
        const MOVIMENTOS = <?= json_encode((object)$movs_js, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>;

        function escapeHtml(s) {
            return String(s).replace(/[&<>"']/g, function(c){
                return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];
            });
        }

        function openTab(evt, tabName) {
            var i, tabcontent, tablinks;
            tabcontent = document.getElementsByClassName("tab-content");
            for (i = 0; i < tabcontent.length; i++) { tabcontent[i].style.display = "none"; tabcontent[i].classList.remove("active"); }
            tablinks = document.getElementsByClassName("tab-btn");
            for (i = 0; i < tablinks.length; i++) { tablinks[i].classList.remove("active"); }
            document.getElementById(tabName).style.display = "block";
            document.getElementById(tabName).classList.add("active");
            evt.currentTarget.classList.add("active");
        }

        function abrirModal(idModal) {
            document.getElementById(idModal).classList.add('show');
        }

        function abrirModalApagar(id) {
            document.getElementById('id_apagar_input').value = id;
            var abaEl = document.getElementById('apagar_aba');
            if (abaEl) {
                var abaAtiva = document.querySelector('.tab-content.active');
                abaEl.value = (abaAtiva && abaAtiva.id === 'geral') ? 'geral' : 'individual';
            }
            abrirModal('modalApagar');
            document.querySelector('#modalApagar input[name="pass_seguranca"]').value = '';
            document.querySelector('#modalApagar input[name="pass_seguranca"]').focus();
        }

        // (Equipamento por Colaborador) Imprimir todos ou só o colaborador escolhido
        function atualizarImprimirInd() {
            var sel = document.getElementById('ind_colab_print'), a = document.getElementById('btnImprimirInd');
            if (!a) return;
            a.href = 'equipamentos_colaboradores_pdf.php' + (sel && sel.value ? '?colab=' + sel.value : '');
        }

        // Auto de entrega com várias entregas: marca as linhas e imprime tudo num só documento
        function atualizarSelecao() {
            var marcados = document.querySelectorAll('.sel-entrega:checked');
            var n = marcados.length;
            document.getElementById('nSel').textContent = n;
            document.getElementById('btnImprimirSel').disabled = (n === 0);
            document.querySelectorAll('.sel-entrega').forEach(function (c) { c.closest('tr').classList.toggle('selecionado', c.checked); });
            var colabs = {}; marcados.forEach(function (c) { colabs[c.dataset.colab] = 1; });
            var nc = Object.keys(colabs).length, aviso = document.getElementById('selAviso');
            if (aviso) aviso.innerHTML = n === 0
                ? '<i class="fa-regular fa-square-check"></i> Marque as entregas para as imprimir num só auto de entrega (uma folha por colaborador).'
                : '<i class="fa-solid fa-square-check"></i> ' + n + ' entrega(s) marcada(s)' + (nc > 1 ? ' de ' + nc + ' colaboradores: sai uma folha para cada um.' : ' — sai numa só folha.');
            var todos = document.getElementById('selTodos'), total = document.querySelectorAll('.sel-entrega').length;
            if (todos) { todos.checked = (n > 0 && n === total); todos.indeterminate = (n > 0 && n < total); }
        }
        function selecionarTodos(chk) {
            document.querySelectorAll('.sel-entrega').forEach(function (c) { c.checked = chk.checked; });
            atualizarSelecao();
        }
        function imprimirSelecionados() {
            var ids = Array.prototype.map.call(document.querySelectorAll('.sel-entrega:checked'), function (c) { return c.value; });
            if (!ids.length) return;
            window.open('equipamento_pdf.php?ids=' + ids.join(','), '_blank');
        }

        // Menu do utilizador (toque em ecrãs táteis)
        (function () {
            var menu = document.querySelector('.user-menu'); if (!menu) return;
            menu.querySelector('.user-btn').addEventListener('click', function (e) { e.stopPropagation(); menu.classList.toggle('aberto'); });
            document.addEventListener('click', function () { menu.classList.remove('aberto'); });
        })();

        // Filtro rápido da lista do Equipamento Geral por localização
        function filtrarLista(btn, loc) {
            document.querySelectorAll('.loc-chip').forEach(function(c){ c.classList.remove('active'); });
            btn.classList.add('active');
            var visiveis = 0;
            document.querySelectorAll('#tabela-geral tbody tr[data-loc]').forEach(function(tr){
                var mostrar = (loc === 'todos' || tr.getAttribute('data-loc') === loc);
                tr.style.display = mostrar ? '' : 'none';
                if (mostrar) visiveis++;
            });
            var semFiltro = document.getElementById('geral-sem-filtro');
            if (semFiltro) semFiltro.style.display = (visiveis === 0) ? '' : 'none';
            // O botão Imprimir imprime o separador escolhido
            var bi = document.getElementById('btnImprimirLista');
            if (bi) {
                bi.href = 'equipamentos_lista_pdf.php?loc=' + encodeURIComponent(loc);
                document.getElementById('btnImprimirLista_txt').textContent = btn.textContent.trim().toLowerCase();
            }
        }

        // Abre o histórico de movimentos de um equipamento
        function abrirHistorico(btn) {
            var id = btn.getAttribute('data-id');
            var desc = btn.getAttribute('data-desc') || '';
            document.getElementById('hist_titulo').textContent = desc;
            var lista = (MOVIMENTOS[id] || []);
            var corpo = document.getElementById('hist_corpo');
            if (!lista.length) {
                corpo.innerHTML = '<p style="color:#7f8c8d; text-align:center; padding:20px 0;">Sem movimentos registados.</p>';
            } else {
                var html = '<div class="hist-timeline">';
                lista.forEach(function(m){
                    var cls = (m.loc === 'obra') ? 'estado-usado' : ((m.loc === 'emprestado') ? 'estado-avariado' : 'estado-novo');
                    html += '<div class="hist-item">'
                          + '<div class="hist-data">' + escapeHtml(m.data) + '</div>'
                          + '<div class="hist-conteudo">'
                          + '<span class="badge-estado ' + cls + '">' + escapeHtml(m.loc_lbl) + '</span>'
                          + (m.detalhe ? '<div class="hist-detalhe">' + escapeHtml(m.detalhe) + '</div>' : '')
                          + (m.obs ? '<div class="hist-obs">' + escapeHtml(m.obs) + '</div>' : '')
                          + (m.registo ? '<div class="hist-registo">Registado por ' + escapeHtml(m.registo) + '</div>' : '')
                          + '</div></div>';
                });
                html += '</div>';
                corpo.innerHTML = html;
            }
            abrirModal('modalHistorico');
        }

        // Mostra/esconde os campos de localização conforme a opção escolhida
        function toggleLocal(p) {
            var loc = document.getElementById(p + '_loc').value;
            var grpCliente = document.getElementById(p + '_grpCliente');
            var grpObra = document.getElementById(p + '_grpObra');
            var grpColab = document.getElementById(p + '_grpColab');
            var grpNome = document.getElementById(p + '_grpNome');
            var cliente = document.getElementById(p + '_cliente');
            var obra = document.getElementById(p + '_obra');
            var colab = document.getElementById(p + '_colab');

            var showCliente = (loc === 'obra' || loc === 'emprestado');
            var showObra = (loc === 'obra');
            var showColab = (loc === 'obra');
            var showNome = (loc === 'emprestado');

            grpCliente.style.display = showCliente ? '' : 'none';
            grpObra.style.display = showObra ? '' : 'none';
            grpColab.style.display = showColab ? '' : 'none';
            grpNome.style.display = showNome ? '' : 'none';

            // Obrigatórios apenas quando visíveis (no caso 'emprestado' o cliente é opcional)
            cliente.required = (loc === 'obra');
            obra.required = showObra;
            colab.required = showColab;
        }

        // Preenche a dropdown de obras com as obras do cliente selecionado.
        // Mostra só as obras ativas, mas mantém a obra já guardada (na edição) mesmo que esteja concluída/suspensa.
        function filtrarObras(p, selectedObraId) {
            var clienteVal = document.getElementById(p + '_cliente').value;
            var obraSel = document.getElementById(p + '_obra');
            obraSel.innerHTML = '<option value="">-- Selecione a obra --</option>';
            OBRAS.filter(function(o){
                if (String(o.id_cliente) !== String(clienteVal)) return false;
                if (o.ativa) return true;
                if (selectedObraId && String(o.id) === String(selectedObraId)) return true;
                return false;
            }).forEach(function(o){
                var opt = document.createElement('option');
                opt.value = o.id;
                opt.textContent = o.label;
                if (selectedObraId && String(o.id) === String(selectedObraId)) opt.selected = true;
                obraSel.appendChild(opt);
            });
        }

        // Atribuir novo: formulário limpo
        function abrirModalNovoInd() {
            const f = document.getElementById('formInd');
            if (f) f.reset();
            document.getElementById('novo_data_ind').value = new Date().toISOString().slice(0, 10);
            document.getElementById('modalInd_titulo').innerHTML = '<i class="fa-solid fa-user-gear"></i> Atribuir Equipamento';
            abrirModal('modalInd');
        }

        // Duplicar: abre o "Atribuir" já preenchido (pode mudar o colaborador); data = hoje e nº de série em branco
        function abrirModalDuplicarInd(btn) {
            const f = document.getElementById('formInd');
            if (f) f.reset();
            document.getElementById('novo_colab_ind').value = btn.getAttribute('data-id_utilizador') || '';
            document.getElementById('novo_data_ind').value = new Date().toISOString().slice(0, 10);
            document.getElementById('novo_est_ind').value = btn.getAttribute('data-estado') || 'Bom';
            document.getElementById('novo_desc_ind').value = btn.getAttribute('data-desc') || '';
            document.getElementById('novo_marca_ind').value = btn.getAttribute('data-marca') || '';
            document.getElementById('novo_serie_ind').value = '';
            document.getElementById('novo_obs_ind').value = btn.getAttribute('data-obs') || '';
            document.getElementById('modalInd_titulo').innerHTML = '<i class="fa-regular fa-copy"></i> Duplicar Equipamento';
            abrirModal('modalInd');
            setTimeout(() => document.getElementById('novo_colab_ind').focus(), 50);
        }

        function abrirModalEditarInd(btn) {
            document.getElementById('edit_id_ind').value = btn.getAttribute('data-id');
            document.getElementById('edit_colab_ind').value = btn.getAttribute('data-id_utilizador');
            document.getElementById('edit_data_ind').value = btn.getAttribute('data-data');
            document.getElementById('edit_desc_ind').value = btn.getAttribute('data-desc');
            document.getElementById('edit_marca_ind').value = btn.getAttribute('data-marca');
            document.getElementById('edit_serie_ind').value = btn.getAttribute('data-serie');
            document.getElementById('edit_est_ind').value = btn.getAttribute('data-estado');
            document.getElementById('edit_obs_ind').value = btn.getAttribute('data-obs');
            
            abrirModal('modalEditarInd');
        }

        function abrirModalEditarGeral(btn) {
            document.getElementById('edit_id_geral').value = btn.getAttribute('data-id');
            document.getElementById('edit_data_geral').value = btn.getAttribute('data-data');
            document.getElementById('edit_desc_geral').value = btn.getAttribute('data-desc');
            document.getElementById('edit_marca_geral').value = btn.getAttribute('data-marca');
            document.getElementById('edit_serie_geral').value = btn.getAttribute('data-serie');
            document.getElementById('edit_forn_geral').value = btn.getAttribute('data-forn');
            document.getElementById('edit_obs_geral').value = btn.getAttribute('data-obs');

            // Data do acontecimento por defeito = hoje
            document.getElementById('eg_data_evento').value = new Date().toISOString().slice(0, 10);

            // Localização
            var loc = btn.getAttribute('data-localizacao') || 'empresa';
            document.getElementById('eg_loc').value = loc;
            toggleLocal('eg');

            // Cliente (definir antes de filtrar as obras)
            document.getElementById('eg_cliente').value = btn.getAttribute('data-cliente') || '';
            // Obras filtradas pelo cliente + selecionar a obra guardada (mesmo que inativa)
            filtrarObras('eg', btn.getAttribute('data-obra') || '');
            // Colaborador que levou
            document.getElementById('eg_colab').value = btn.getAttribute('data-colab') || '';
            // Nome de quem levou emprestado
            document.getElementById('eg_nome').value = btn.getAttribute('data-empnome') || '';

            abrirModal('modalEditarGeral');
        }

        function fecharModais() { 
            document.querySelectorAll('.modal').forEach(m => m.classList.remove('show'));
        }
    </script>
</body>
</html>
