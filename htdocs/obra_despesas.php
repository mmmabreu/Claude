<?php
// --- MODO DE DIAGNÓSTICO TEMPORÁRIO ---
// Isto força o PHP a mostrar o erro real no ecrã em vez da página genérica de erro 500.
// IMPORTANTE: remover estas 3 linhas assim que o problema estiver identificado/resolvido,
// para não ficar a mostrar detalhes técnicos do site a quem o visitar.
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
require_once 'db.php';

// Validação de sessão e permissões
if (!isset($_SESSION['user_id'])) { header("Location: index.php"); exit; }
if (file_exists('functions.php')) { require_once 'functions.php'; } else { function registrarLog($pdo, $acao, $modulo) { return true; } }

// --- CORREÇÃO DE COLUNAS (Sem apagar dados!) ---
try {
    $pdo->exec("ALTER TABLE obra_despesas MODIFY COLUMN tipo_despesa VARCHAR(50) NOT NULL");
    $check_col = $pdo->query("SHOW COLUMNS FROM obra_despesas LIKE 'id_relatorio'");
    if ($check_col !== false && $check_col->rowCount() == 0) {
        $pdo->exec("ALTER TABLE obra_despesas ADD COLUMN id_relatorio INT DEFAULT NULL AFTER origem");
    }
} catch (Throwable $e) {}

$perfil_user = $_SESSION['perfil'] ?? '';
$is_admin = in_array($perfil_user, ['admin', 'administrativo']);

$stmt_perm = $pdo->prepare("SELECT modulo FROM permissoes WHERE grupo = :perfil AND pode_aceder = 1");
$stmt_perm->execute(['perfil' => $perfil_user]);
$meus_acessos = $stmt_perm->fetchAll(PDO::FETCH_COLUMN);

if (!$is_admin && !in_array('obras', $meus_acessos)) {
    header("Location: index.php?erro=sem_permissao");
    exit;
}

$nome_completo = $_SESSION['nome'] ?? 'Utilizador';
$primeiro_nome = explode(' ', trim($nome_completo))[0];

$id_obra = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$id_obra) { header("Location: obras.php"); exit; }

$mensagem = ''; $tipo_mensagem = '';

// Buscar a Obra e o Valor do Orçamento Adjudicado de forma segura
$stmt_obra = $pdo->prepare("
    SELECT o.*,
           c.nome as cliente_nome,
           orc.valor_total_com_iva as valor_orcamentado
    FROM obras o
    LEFT JOIN clientes c ON o.id_cliente = c.id
    LEFT JOIN orcamentos orc ON o.referencia_obra = orc.numero_orcamento
    WHERE o.id = ?
");
$stmt_obra->execute([$id_obra]);
$obra = $stmt_obra->fetch(PDO::FETCH_ASSOC);

if (!$obra) { die("Obra não encontrada."); }

// --- FUNÇÃO PARA CONVERTER HORAS ---
function extrairHorasDecimais($tempo_str) {
    if (is_numeric($tempo_str)) return (float)$tempo_str;
    $tempo_str = strtolower(trim((string)$tempo_str));
    if (empty($tempo_str)) return 0;

    if (strpos($tempo_str, 'h') !== false) {
        $tempo_str = str_replace([' ', 'm', 's'], '', $tempo_str);
        $parts = explode('h', $tempo_str);
        $h = (float)$parts[0];
        $m = isset($parts[1]) && $parts[1] !== '' ? (float)$parts[1] : 0;
        return $h + ($m / 60);
    }

    if (strpos($tempo_str, ':') !== false) {
        $parts = explode(':', $tempo_str);
        $h = (float)$parts[0];
        $m = isset($parts[1]) ? (float)$parts[1] : 0;
        return $h + ($m / 60);
    }
    return (float)str_replace(',', '.', $tempo_str);
}

// --- PROCESSAR NOVA DESPESA MANUAL ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_despesa'])) {
    $tipo = $_POST['tipo_despesa'];
    $data = $_POST['data_despesa'];
    $valor = (float)str_replace(',', '.', $_POST['valor']);
    $descricao = trim($_POST['descricao']);
    $quantidade = !empty($_POST['quantidade']) ? (float)str_replace(',', '.', $_POST['quantidade']) : 0;

    $id_utilizador_despesa = ($tipo === 'Material') ? null : $_SESSION['user_id'];

    try {
        $sql = "INSERT INTO obra_despesas (obra_id, tipo_despesa, data_despesa, descricao, quantidade, valor, origem, id_utilizador) VALUES (?, ?, ?, ?, ?, ?, 'Manual', ?)";
        $pdo->prepare($sql)->execute([$id_obra, $tipo, $data, $descricao, $quantidade, $valor, $id_utilizador_despesa]);
        registrarLog($pdo, "Adicionou despesa manual ($tipo: $valor €) à Obra #$id_obra", "Obras - Financeiro");
        $mensagem = "Despesa registada com sucesso!"; $tipo_mensagem = "sucesso";
    } catch(Throwable $e) {
        $mensagem = "Erro ao registar despesa: " . $e->getMessage(); $tipo_mensagem = "erro";
    }
}

// --- EDITAR DESPESA MANUAL ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_despesa'])) {
    $id_edit = (int)$_POST['id_despesa_edit'];
    $tipo = $_POST['tipo_despesa'];
    $data = $_POST['data_despesa'];
    $valor = (float)str_replace(',', '.', $_POST['valor']);
    $descricao = trim($_POST['descricao']);
    $quantidade = !empty($_POST['quantidade']) ? (float)str_replace(',', '.', $_POST['quantidade']) : 0;

    try {
        $pdo->prepare("UPDATE obra_despesas SET tipo_despesa = ?, data_despesa = ?, descricao = ?, quantidade = ?, valor = ? WHERE id = ? AND origem = 'Manual'")
            ->execute([$tipo, $data, $descricao, $quantidade, $valor, $id_edit]);
        registrarLog($pdo, "Editou despesa manual (ID: $id_edit) na Obra #$id_obra", "Obras - Financeiro");
        $mensagem = "Despesa atualizada com sucesso!"; $tipo_mensagem = "sucesso";
    } catch(Throwable $e) {
        $mensagem = "Erro ao atualizar despesa: " . $e->getMessage(); $tipo_mensagem = "erro";
    }
}

// --- APAGAR DESPESA ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['apagar_despesa_id'])) {
    try {
        $pdo->prepare("DELETE FROM obra_despesas WHERE id = ? AND obra_id = ?")->execute([$_POST['apagar_despesa_id'], $id_obra]);
        registrarLog($pdo, "Apagou despesa (ID: " . $_POST['apagar_despesa_id'] . ") na Obra #$id_obra", "Obras - Financeiro");
        $mensagem = "Despesa removida!"; $tipo_mensagem = "sucesso";
    } catch(Throwable $e) {
        $mensagem = "Erro ao remover."; $tipo_mensagem = "erro";
    }
}

// --- SINCRONIZADOR DE RELATÓRIOS (SÓ EXECUTA QUANDO SE CLICA NO BOTÃO) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['importar_relatorios'])) {
    try {
        $sql_relatorios = "
            SELECT r.*,
                   u1.valor_hora as u1_valor_hora,
                   u2.valor_hora as u2_valor_hora
            FROM relatorios_obra r
            LEFT JOIN utilizadores u1 ON r.colaborador_1 = u1.id
            LEFT JOIN utilizadores u2 ON r.colaborador_2 = u2.id
            WHERE r.id_obra = ?
        ";

        $stmt_rel = $pdo->prepare($sql_relatorios);
        $stmt_rel->execute([$id_obra]);
        $relatorios = $stmt_rel->fetchAll(PDO::FETCH_ASSOC);

        $inseridos = 0;
        $atualizados = 0;
        $removidos = 0;

        $pdo->beginTransaction();

        $stmt_existing = $pdo->prepare("SELECT id, tipo_despesa, id_utilizador, quantidade, valor FROM obra_despesas WHERE id_relatorio = ?");

        foreach ($relatorios as $r) {
            $r_id = $r['id'];
            $data_rel = $r['data_relatorio'];

            $is_extra = ((int)$r['trabalho_extra'] === 1);
            $tipo_mo = $is_extra ? 'Mão de Obra (Extra)' : 'Mão de Obra';
            $desc_mo = $is_extra ? 'Importado do Relatório (Extra)' : 'Importado do Relatório';
            $qtd_h = (float)$r['total_horas'];

            $stmt_existing->execute([$r_id]);
            $existing_expenses = [];
            while($row = $stmt_existing->fetch(PDO::FETCH_ASSOC)) {
                $key = $row['tipo_despesa'] . '_' . $row['id_utilizador'];
                $existing_expenses[$key] = $row;
            }

            $touched_keys = [];

            $handle_upsert = function($tipo, $id_utilizador, $qtd, $val, $desc) use (&$existing_expenses, &$touched_keys, &$inseridos, &$atualizados, $pdo, $id_obra, $data_rel, $r_id) {
                $key = $tipo . '_' . $id_utilizador;
                $touched_keys[] = $key;

                if (isset($existing_expenses[$key])) {
                    $ex = $existing_expenses[$key];
                    if ($ex['quantidade'] != $qtd || $ex['valor'] != $val) {
                        $pdo->prepare("UPDATE obra_despesas SET quantidade=?, valor=?, data_despesa=?, descricao=? WHERE id=?")
                            ->execute([$qtd, $val, $data_rel, $desc, $ex['id']]);
                        $atualizados++;
                    }
                } else {
                    $pdo->prepare("INSERT INTO obra_despesas (obra_id, tipo_despesa, data_despesa, descricao, quantidade, valor, origem, id_relatorio, id_utilizador) VALUES (?, ?, ?, ?, ?, ?, 'Relatório', ?, ?)")
                        ->execute([$id_obra, $tipo, $data_rel, $desc, $qtd, $val, $r_id, $id_utilizador]);
                    $inseridos++;
                }
            };

            // Processar Colab 1
            $c1 = $r['colaborador_1'];
            if ($c1) {
                if ($qtd_h > 0) {
                    $v1 = (float)($r['valor_hora'] > 0 ? $r['valor_hora'] : $r['u1_valor_hora']);
                    $handle_upsert($tipo_mo, $c1, $qtd_h, round($qtd_h * $v1, 2), $desc_mo);
                }
                $ref1 = (float)$r['custo_refeicao_1'];
                if ($ref1 > 0) {
                    $handle_upsert('Refeição', $c1, 1, $ref1, 'Importado do Relatório');
                }
                $km = (float)$r['km_percorridos'];
                $val_km = (float)$r['valor_km'];
                if ($km > 0) {
                    $handle_upsert('Deslocação', $c1, $km, $val_km, 'Importado do Relatório');
                }
            }

            // Processar Colab 2
            $c2 = $r['colaborador_2'];
            if ($c2) {
                if ($qtd_h > 0) {
                    $v2 = (float)$r['u2_valor_hora'];
                    $handle_upsert($tipo_mo, $c2, $qtd_h, round($qtd_h * $v2, 2), $desc_mo);
                }
                $ref2 = (float)$r['custo_refeicao_2'];
                if ($ref2 > 0) {
                    $handle_upsert('Refeição', $c2, 1, $ref2, 'Importado do Relatório');
                }
            }

            foreach ($existing_expenses as $key => $ex) {
                if (!in_array($key, $touched_keys)) {
                    $pdo->prepare("DELETE FROM obra_despesas WHERE id=?")->execute([$ex['id']]);
                    $removidos++;
                }
            }
        }

        $pdo->commit();
        registrarLog($pdo, "Sincronizou relatórios da Obra #$id_obra", "Obras - Financeiro");

        if ($inseridos > 0 || $atualizados > 0 || $removidos > 0) {
            $msg_parts = [];
            if ($inseridos > 0) $msg_parts[] = "$inseridos novos";
            if ($atualizados > 0) $msg_parts[] = "$atualizados atualizados";
            if ($removidos > 0) $msg_parts[] = "$removidos limpos/reajustados";

            $mensagem = "Sincronização Concluída! (" . implode(", ", $msg_parts) . ").";
            $tipo_mensagem = "sucesso";
        } else {
            $mensagem = "As despesas já estão 100% sincronizadas com os relatórios.";
            $tipo_mensagem = "info";
        }
    } catch(Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $mensagem = "Erro na sincronização: " . $e->getMessage(); $tipo_mensagem = "erro";
    }
}

// --- BUSCAR TODAS AS DESPESAS ---
// Ordenado por Data Descendente, Tipo de Despesa Ascendente, e ID Descendente
$despesas = $pdo->prepare("
    SELECT d.*, u.nome as nome_colaborador
    FROM obra_despesas d
    LEFT JOIN utilizadores u ON d.id_utilizador = u.id
    WHERE d.obra_id = ?
    ORDER BY d.data_despesa DESC, d.tipo_despesa ASC, d.id DESC
");
$despesas->execute([$id_obra]);
$lista_despesas = $despesas->fetchAll(PDO::FETCH_ASSOC);

// Somatórios para o Dashboard e AGRUPAMENTO POR COLABORADOR
$total_material = 0; $total_mo = 0; $total_mo_extra = 0; $total_desloc = 0; $total_ref = 0; $total_outras = 0;
$resumo_equipa = [];

foreach ($lista_despesas as $d) {
    if ($d['tipo_despesa'] == 'Material') $total_material += $d['valor'];
    if ($d['tipo_despesa'] == 'Mão de Obra') $total_mo += $d['valor'];
    if ($d['tipo_despesa'] == 'Mão de Obra (Extra)') $total_mo_extra += $d['valor'];
    if ($d['tipo_despesa'] == 'Deslocação') $total_desloc += $d['valor'];
    if ($d['tipo_despesa'] == 'Refeição') $total_ref += $d['valor'];
    if ($d['tipo_despesa'] == 'Outras') $total_outras += $d['valor'];

    $nome = $d['nome_colaborador'] ?: 'Material / Despesas Gerais';
    if (!isset($resumo_equipa[$nome])) {
        $resumo_equipa[$nome] = [
            'horas' => 0, 'custo_mo' => 0,
            'horas_extra' => 0, 'custo_mo_extra' => 0,
            'km' => 0, 'custo_desloc' => 0,
            'refeicoes' => 0, 'custo_ref' => 0,
            'custo_outros' => 0,
            'total' => 0
        ];
    }

    if ($d['tipo_despesa'] == 'Mão de Obra') {
        $resumo_equipa[$nome]['horas'] += (float)$d['quantidade'];
        $resumo_equipa[$nome]['custo_mo'] += (float)$d['valor'];
    } elseif ($d['tipo_despesa'] == 'Mão de Obra (Extra)') {
        $resumo_equipa[$nome]['horas_extra'] += (float)$d['quantidade'];
        $resumo_equipa[$nome]['custo_mo_extra'] += (float)$d['valor'];
    } elseif ($d['tipo_despesa'] == 'Deslocação') {
        $resumo_equipa[$nome]['km'] += (float)$d['quantidade'];
        $resumo_equipa[$nome]['custo_desloc'] += (float)$d['valor'];
    } elseif ($d['tipo_despesa'] == 'Refeição') {
        $resumo_equipa[$nome]['refeicoes'] += (float)$d['quantidade'];
        $resumo_equipa[$nome]['custo_ref'] += (float)$d['valor'];
    } else {
        $resumo_equipa[$nome]['custo_outros'] += (float)$d['valor'];
    }
    $resumo_equipa[$nome]['total'] += (float)$d['valor'];
}

uksort($resumo_equipa, function($key1, $key2) use ($resumo_equipa) {
    if ($key1 === 'Material / Despesas Gerais') return 1;
    if ($key2 === 'Material / Despesas Gerais') return -1;
    return $resumo_equipa[$key2]['total'] <=> $resumo_equipa[$key1]['total'];
});

$custo_total_obra = $total_material + $total_mo + $total_mo_extra + $total_desloc + $total_ref + $total_outras;
$valor_faturado = $obra['valor_orcamentado'] ?? 0;
$margem_lucro = $valor_faturado - $custo_total_obra;
$cor_margem = ($margem_lucro < 0) ? '#e74c3c' : '#2ecc71';
?>
<!DOCTYPE html>
<html lang="pt-PT">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Custos e Despesas - <?= htmlspecialchars($obra['referencia_obra'] ?: 'Obra') ?> - Gestão MAB</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { font-family: system-ui, -apple-system, sans-serif; box-sizing: border-box; }
        body { background-color: #f7f9fc; margin: 0; padding-bottom: 50px; color: #333; }

        .topbar { background: #fff; padding: 15px 40px; box-shadow: 0 4px 20px rgba(0,0,0,0.05); display: flex; justify-content: space-between; align-items: center; position: sticky; top: 0; z-index: 100; }
        .topbar-left .logo { height: 40px; }
        .topbar-center { display: flex; gap: 25px; flex: 1; }
        .topbar-right { display: flex; align-items: center; gap: 30px; }

        .btn-back { color: #576574; text-decoration: none; font-weight: 500; display: flex; align-items: center; gap: 6px; font-size: 15px;}
        .btn-back:hover { color: #e67e22; }

        .user-menu { position: relative; display: inline-block; }
        .user-btn { background: none; border: none; font-size: 15px; font-weight: 600; color: #2c3e50; cursor: pointer; display: flex; align-items: center; gap: 8px; }
        .user-avatar { background: #e74c3c; color: white; width: 28px; height: 28px; border-radius: 50%; display: flex; justify-content: center; align-items: center; font-size: 14px; border: 2px solid #fadbd8; }

        .dropdown-content { display: none; position: absolute; right: 0; top: 100%; margin-top: 10px; background: #fff; min-width: 180px; box-shadow: 0 10px 30px rgba(0,0,0,0.1); border-radius: 8px; overflow: hidden; border: 1px solid #f1f2f6; z-index:200;}
        .dropdown-content a { color: #576574; padding: 12px 20px; text-decoration: none; display: block; font-size: 14px; }
        .dropdown-content a:hover { background: #fdf2f2; color: #e67e22; }
        .user-menu:hover .dropdown-content { display: block; }

        .container { max-width: 1300px; margin: 30px auto; padding: 0 15px; }

        .page-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px; flex-wrap: wrap; gap: 15px; }
        .page-title { margin: 0; color: #2c3e50; font-size: 24px; font-weight: 800; display: flex; align-items: center; gap: 10px; }
        .ref-badge { background: #fdf2e9; color: #e67e22; border: 1px solid #fae5d3; padding: 4px 10px; border-radius: 6px; font-size: 14px; }

        .alert { padding: 15px; border-radius: 8px; margin-bottom: 20px; text-align: center; font-weight: 600; }
        .alert-sucesso { background: #e8f5e9; color: #2e7d32; border: 1px solid #c8e6c9; }
        .alert-erro { background: #fdeded; color: #c0392b; border: 1px solid #f9cccc; }
        .alert-info { background: #e3f2fd; color: #1976d2; border: 1px solid #bbdefb; }

        .dash-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 15px; margin-bottom: 25px; }
        .dash-card { background: #fff; padding: 20px; border-radius: 12px; border: 1px solid #e1e5ea; box-shadow: 0 4px 10px rgba(0,0,0,0.02); text-align: center; }
        .dash-label { font-size: 11px; font-weight: 700; color: #7f8c8d; text-transform: uppercase; margin-bottom: 8px; display: block; }
        .dash-value { font-size: 22px; font-weight: 800; color: #2c3e50; }
        .dash-card.highlight { background: #fdfaf2; border-color: #f39c12; }
        .dash-card.highlight .dash-value { color: #f39c12; font-size: 26px; }

        .card { background: #fff; border-radius: 12px; border: 1px solid #e1e5ea; box-shadow: 0 2px 10px rgba(0,0,0,0.02); margin-bottom: 25px; overflow: hidden;}
        .card-header { background: #f8f9fa; padding: 15px 20px; border-bottom: 1px solid #e1e5ea; font-weight: 700; color: #2c3e50; display: flex; justify-content: space-between; align-items: center; }
        .card-body { padding: 20px; }

        .form-row { display: flex; gap: 15px; align-items: flex-end; flex-wrap: wrap; }
        .form-group { display: flex; flex-direction: column; flex: 1; min-width: 150px; }
        .form-group label { font-weight: 600; margin-bottom: 6px; font-size: 11px; color: #576574; text-transform: uppercase; }
        input, select { padding: 10px 12px; border: 1px solid #dcdde1; border-radius: 8px; font-size: 14px; outline: none; background: #fff;}
        input:focus, select:focus { border-color: #e67e22; }

        .btn-add { background: #e67e22; color: #fff; border: none; padding: 11px 25px; border-radius: 8px; font-weight: 700; cursor: pointer; transition: 0.2s; white-space: nowrap;}
        .btn-add:hover { background: #d35400; }
        .btn-secondary { background: #f1f2f6; color: #576574; border: 1px solid #dcdde1; padding: 11px 15px; border-radius: 8px; font-weight: 600; cursor: pointer; transition: 0.2s; white-space: nowrap; display: none; align-items: center; gap: 5px; }
        .btn-secondary:hover { background: #e1e5ea; }

        /* NOVOS BOTÕES NO HEADER */
        .btn-importar { background: #fdfdfd; color: #3498db; border: 1px solid #d6eaf8; padding: 8px 15px; border-radius: 6px; font-weight: 600; cursor: pointer; font-size: 13px; transition: 0.2s; display:inline-flex; align-items:center; gap:6px; text-decoration:none;}
        .btn-importar:hover { background: #ebf5fb; color:#2980b9;}

        .btn-print { background: #fcf3cf; color: #f39c12; border: 1px solid #f9e79f; padding: 8px 15px; border-radius: 6px; font-weight: 600; cursor: pointer; font-size: 13px; transition: 0.2s; display:inline-flex; align-items:center; gap:6px; text-decoration:none;}
        .btn-print:hover { background: #f9e79f; color:#d68910;}

        table { width: 100%; border-collapse: collapse; }
        th { padding: 12px 15px; background: #fdfaf2; color: #e67e22; font-size: 11px; text-transform: uppercase; text-align: left; border-bottom: 2px solid #f9e79f; }
        td { padding: 12px 15px; border-bottom: 1px solid #f1f2f6; font-size: 14px; color: #2c3e50; vertical-align: middle; }
        tr:hover { background: #fcfcfc; }

        .btn-icon { color: #576574; margin: 0 6px; text-decoration: none; font-size: 16px; cursor: pointer; border:none; background:none; transition: color 0.2s; }
        .btn-icon:hover { color: #e67e22; }
        .btn-del { color: #e74c3c; background: none; border: none; cursor: pointer; font-size: 16px; transition: 0.2s;}
        .btn-del:hover { color: #c0392b; transform: scale(1.1); }

        .origem-badge { font-size: 10px; background: #e1e5ea; padding: 2px 6px; border-radius: 4px; color: #576574; font-weight: bold; text-transform: uppercase; }
        .origem-rel { background: #d6eaf8; color: #2980b9; }

        @media (max-width: 768px) {
            .dash-grid { grid-template-columns: 1fr 1fr; }
            .form-row { flex-direction: column; align-items: stretch; }
            .btn-add { width: 100%; }
            .btn-back-text { display: none; }
            .topbar { padding: 15px 20px; }
            .page-header > div:last-child { display: flex; flex-direction: column; gap: 8px; width: 100%; }
            .page-header > div:last-child a, .page-header > div:last-child button { width: 100%; justify-content: center; }
        }
    </style>
</head>
<body>

    <header class="topbar">
        <div class="topbar-left"><a href="index.php"><img src="mab.png" alt="MAB" class="logo"></a></div>
        <div class="topbar-center"></div>
        <div class="topbar-right">
            <a href="obras.php" class="btn-back" title="Voltar à Listagem"><i class="fa-solid fa-arrow-left"></i> <span class="btn-back-text">Voltar</span></a>
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
                    <a href="index.php?logout=1" style="color: #e74c3c; border-top: 1px solid #eee;">Sair da Sessão</a>
                </div>
            </div>
        </div>
    </header>

    <div class="container">
        <?php if ($mensagem): ?>
            <div class="alert alert-<?= $tipo_mensagem ?>"><?= htmlspecialchars($mensagem) ?></div>
        <?php endif; ?>

        <div class="page-header">
            <div>
                <h1 class="page-title"><i class="fa-solid fa-coins" style="color:#e67e22;"></i> Gestão Financeira da Obra</h1>
                <p style="margin: 5px 0 0 0; color: #7f8c8d; font-weight: 600;">
                    <?= htmlspecialchars($obra['nome']) ?>
                    <?php if ($obra['referencia_obra']): ?>
                        <span class="ref-badge"><i class="fa-solid fa-hashtag"></i> <?= htmlspecialchars($obra['referencia_obra']) ?></span>
                    <?php endif; ?>
                </p>
            </div>

            <div style="display:flex; gap:10px;">
                <a href="obra_despesas_pdf.php?id=<?= $id_obra ?>" target="_blank" class="btn-print">
                    <i class="fa-solid fa-print"></i> Imprimir Relatório
                </a>

                <form method="POST">
                    <button type="submit" name="importar_relatorios" class="btn-importar" onclick="return confirm('O sistema vai procurar relatórios e sincronizar os custos (atualizando valores que tenham sido alterados e adicionando novos). Continuar?');">
                        <i class="fa-solid fa-rotate"></i> Sincronizar Relatórios
                    </button>
                </form>
            </div>
        </div>

        <div class="dash-grid">
            <div class="dash-card">
                <span class="dash-label"><i class="fa-solid fa-boxes-stacked"></i> Materiais</span>
                <span class="dash-value"><?= number_format($total_material, 2, ',', ' ') ?> €</span>
            </div>
            <div class="dash-card">
                <span class="dash-label"><i class="fa-solid fa-people-carry-box"></i> Mão de Obra</span>
                <span class="dash-value"><?= number_format($total_mo, 2, ',', ' ') ?> €</span>
            </div>
            <div class="dash-card">
                <span class="dash-label"><i class="fa-solid fa-user-clock"></i> M.O. Extra</span>
                <span class="dash-value" style="color:#8e44ad;"><?= number_format($total_mo_extra, 2, ',', ' ') ?> €</span>
            </div>
            <div class="dash-card">
                <span class="dash-label"><i class="fa-solid fa-truck-fast"></i> Deslocações</span>
                <span class="dash-value"><?= number_format($total_desloc, 2, ',', ' ') ?> €</span>
            </div>
            <div class="dash-card">
                <span class="dash-label"><i class="fa-solid fa-utensils"></i> Refeições</span>
                <span class="dash-value"><?= number_format($total_ref, 2, ',', ' ') ?> €</span>
            </div>
            <div class="dash-card">
                <span class="dash-label"><i class="fa-solid fa-receipt"></i> Outras</span>
                <span class="dash-value"><?= number_format($total_outras, 2, ',', ' ') ?> €</span>
            </div>
        </div>

        <div class="dash-grid" style="grid-template-columns: 1fr 1fr;">
            <div class="dash-card highlight" style="background:#fff4eb; border-color:#d35400;">
                <span class="dash-label" style="color:#d35400;">Custo Total da Obra</span>
                <span class="dash-value" style="color:#d35400;"><?= number_format($custo_total_obra, 2, ',', ' ') ?> €</span>
            </div>
            <div class="dash-card highlight" style="border-color: <?= $cor_margem ?>; background: <?= $margem_lucro < 0 ? '#fdeaea' : '#eafaf1' ?>;">
                <span class="dash-label" style="color: <?= $cor_margem ?>;">Margem de Lucro (Custo vs Faturado)</span>
                <span class="dash-value" style="color: <?= $cor_margem ?>;"><?= number_format($margem_lucro, 2, ',', ' ') ?> €</span>
                <div style="font-size:10px; color:#7f8c8d; margin-top:5px;">Orçamentado: <?= number_format($valor_faturado, 2, ',', ' ') ?> €</div>
            </div>
        </div>

        <?php if (!empty($resumo_equipa)): ?>
        <div class="card">
            <div class="card-header" style="background: #fdfdfd; border-bottom: 2px solid #f1f2f6;">
                <span><i class="fa-solid fa-users-gear" style="color:#3498db;"></i> Resumo de Custos por Colaborador / Categoria</span>
            </div>
            <div style="overflow-x: auto;">
                <table>
                    <thead>
                        <tr>
                            <th style="background: #f8f9fa; color: #34495e;">Colaborador</th>
                            <th style="background: #f8f9fa; color: #34495e; text-align:center;">Horas</th>
                            <th style="background: #f8f9fa; color: #34495e; text-align:right;">Custo M.O.</th>
                            <th style="background: #f8f9fa; color: #8e44ad; text-align:center;">Horas Extra</th>
                            <th style="background: #f8f9fa; color: #8e44ad; text-align:right;">Custo M.O. Extra</th>
                            <th style="background: #f8f9fa; color: #34495e; text-align:center;">KM's</th>
                            <th style="background: #f8f9fa; color: #34495e; text-align:right;">Custo Desloc.</th>
                            <th style="background: #f8f9fa; color: #34495e; text-align:center;">Refeições</th>
                            <th style="background: #f8f9fa; color: #34495e; text-align:right;">Custo Ref.</th>
                            <th style="background: #f8f9fa; color: #34495e; text-align:right;">Outros (Mat.)</th>
                            <th style="background: #f8f9fa; color: #2c3e50; text-align:right; font-size: 12px;">Total (€)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($resumo_equipa as $nome => $tot): ?>
                            <tr>
                                <td><strong style="color: <?= $nome === 'Material / Despesas Gerais' ? '#7f8c8d' : '#2c3e50' ?>;"><?= htmlspecialchars($nome) ?></strong></td>
                                <td style="text-align:center; color:#7f8c8d;"><?= $tot['horas'] > 0 ? floatval($tot['horas']).'h' : '-' ?></td>
                                <td style="text-align:right;"><?= $tot['custo_mo'] > 0 ? number_format($tot['custo_mo'], 2, ',', ' ').' €' : '-' ?></td>
                                <td style="text-align:center; color:#9b59b6;"><?= $tot['horas_extra'] > 0 ? floatval($tot['horas_extra']).'h' : '-' ?></td>
                                <td style="text-align:right; color:#8e44ad;"><?= $tot['custo_mo_extra'] > 0 ? number_format($tot['custo_mo_extra'], 2, ',', ' ').' €' : '-' ?></td>
                                <td style="text-align:center; color:#7f8c8d;"><?= $tot['km'] > 0 ? floatval($tot['km']).' km' : '-' ?></td>
                                <td style="text-align:right;"><?= $tot['custo_desloc'] > 0 ? number_format($tot['custo_desloc'], 2, ',', ' ').' €' : '-' ?></td>
                                <td style="text-align:center; color:#7f8c8d;"><?= $tot['refeicoes'] > 0 ? floatval($tot['refeicoes']) : '-' ?></td>
                                <td style="text-align:right;"><?= $tot['custo_ref'] > 0 ? number_format($tot['custo_ref'], 2, ',', ' ').' €' : '-' ?></td>
                                <td style="text-align:right;"><?= $tot['custo_outros'] > 0 ? number_format($tot['custo_outros'], 2, ',', ' ').' €' : '-' ?></td>
                                <td style="text-align:right; font-weight:900; color:#e67e22; background: #fffdf9;"><?= number_format($tot['total'], 2, ',', ' ') ?> €</td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <div class="card" id="form_despesa_card">
            <div class="card-header">
                <span id="form_title"><i class="fa-solid fa-plus-circle"></i> Registar Nova Despesa Manual</span>
            </div>
            <div class="card-body">
                <form method="POST" class="form-row" id="formDespesa">
                    <input type="hidden" name="add_despesa" id="add_despesa_flag" value="1">
                    <input type="hidden" name="id_despesa_edit" id="id_despesa_edit" value="">

                    <div class="form-group" style="flex: 0.8;">
                        <label>Tipo</label>
                        <select name="tipo_despesa" id="tipo_despesa" onchange="adaptarFormulario()" required>
                            <option value="Material">Compra Material</option>
                            <option value="Mão de Obra">Mão de Obra (Normal)</option>
                            <option value="Mão de Obra (Extra)">Mão de Obra (Extra)</option>
                            <option value="Deslocação">Deslocação / KM</option>
                            <option value="Refeição">Refeição</option>
                            <option value="Outras">Outras Despesas</option>
                        </select>
                    </div>

                    <div class="form-group" style="flex: 0.8;">
                        <label>Data</label>
                        <input type="date" name="data_despesa" id="data_despesa" value="<?= date('Y-m-d') ?>" required>
                    </div>

                    <div class="form-group" style="flex: 2;" id="box_descricao">
                        <label id="lbl_descricao">Fornecedor / Descrição</label>
                        <input type="text" name="descricao" id="input_descricao" placeholder="Ex: Leroy Merlin" required>
                    </div>

                    <div class="form-group" style="flex: 0.8; display:none;" id="box_quantidade">
                        <label id="lbl_quantidade">Qtd</label>
                        <input type="text" name="quantidade" id="input_quantidade" placeholder="0">
                    </div>

                    <div class="form-group" style="flex: 0.8;">
                        <label>Valor Total (€) *</label>
                        <input type="text" name="valor" id="input_valor" placeholder="0,00" required>
                    </div>

                    <div style="display:flex; gap:10px; align-items:flex-end;">
                        <button type="button" class="btn-secondary" id="btn_cancel_edit" onclick="cancelarEdicao()"><i class="fa-solid fa-xmark"></i> Cancelar</button>
                        <button type="submit" class="btn-add" id="btn_submit_despesa"><i class="fa-solid fa-check"></i> Adicionar</button>
                    </div>
                </form>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <span><i class="fa-solid fa-list-ul"></i> Histórico Detalhado de Despesas</span>
            </div>
            <div style="overflow-x: auto;">
                <table>
                    <thead>
                        <tr>
                            <th style="width: 100px;">Data</th>
                            <th style="width: 120px;">Tipo</th>
                            <th>Descrição / Fornecedor</th>
                            <th style="width: 140px;">Colaborador</th>
                            <th style="width: 100px; text-align:center;">Detalhe</th>
                            <th style="width: 120px; text-align:right;">Valor (€)</th>
                            <th style="width: 80px; text-align:center;">Origem</th>
                            <th style="width: 90px; text-align:center;">Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($lista_despesas)): ?>
                            <tr><td colspan="8" style="text-align:center; padding:30px; color:#7f8c8d;">Ainda não existem despesas registadas nesta obra.</td></tr>
                        <?php else: ?>
                            <?php foreach ($lista_despesas as $d): ?>
                                <tr>
                                    <td><strong><?= date('d/m/Y', strtotime($d['data_despesa'])) ?></strong></td>
                                    <td>
                                        <?php
                                            $icone = 'fa-receipt';
                                            $cor_tipo = '#e67e22';
                                            if ($d['tipo_despesa'] == 'Material') $icone = 'fa-boxes-stacked';
                                            if ($d['tipo_despesa'] == 'Mão de Obra') $icone = 'fa-people-carry-box';
                                            if ($d['tipo_despesa'] == 'Mão de Obra (Extra)') { $icone = 'fa-user-clock'; $cor_tipo = '#8e44ad'; }
                                            if ($d['tipo_despesa'] == 'Deslocação') $icone = 'fa-truck-fast';
                                            if ($d['tipo_despesa'] == 'Refeição') $icone = 'fa-utensils';
                                        ?>
                                        <i class="fa-solid <?= $icone ?>" style="color:<?= $cor_tipo ?>; width:15px;"></i>
                                        <span style="<?= ($d['tipo_despesa'] == 'Mão de Obra (Extra)') ? 'color:#8e44ad; font-weight:600;' : '' ?>">
                                            <?= htmlspecialchars($d['tipo_despesa']) ?>
                                        </span>
                                    </td>
                                    <td><?= htmlspecialchars($d['descricao']) ?></td>
                                    <td style="color:#16a085; font-weight:600; font-size: 13px;">
                                        <?= htmlspecialchars($d['nome_colaborador'] ?? '-') ?>
                                    </td>
                                    <td style="text-align:center; color:#7f8c8d; font-size:12px;">
                                        <?php
                                            if ($d['quantidade'] > 0) {
                                                if (strpos($d['tipo_despesa'], 'Mão de Obra') !== false) echo floatval($d['quantidade']) . ' Horas';
                                                elseif ($d['tipo_despesa'] == 'Deslocação') echo floatval($d['quantidade']) . ' KM';
                                                else echo 'Qtd: ' . floatval($d['quantidade']);
                                            } else {
                                                echo '-';
                                            }
                                        ?>
                                    </td>
                                    <td style="text-align:right; font-weight:bold; color:#2c3e50;"><?= number_format($d['valor'], 2, ',', ' ') ?> €</td>
                                    <td style="text-align:center;">
                                        <span class="origem-badge <?= ($d['origem'] === 'Relatório') ? 'origem-rel' : '' ?>"><?= htmlspecialchars($d['origem']) ?></span>
                                    </td>
                                    <td style="text-align:center;">
                                        <?php if ($d['origem'] === 'Manual'): ?>
                                            <div style="display:flex; gap:5px; justify-content:center; align-items:center;">
                                                <button type="button" class="btn-icon" onclick='editarDespesa(<?= json_encode($d) ?>)' title="Editar Despesa Manual"><i class="fa-solid fa-pen-to-square"></i></button>
                                                <form method="POST" onsubmit="return confirm('Apagar esta despesa definitivamente?');" style="margin:0;">
                                                    <input type="hidden" name="apagar_despesa_id" value="<?= $d['id'] ?>">
                                                    <button type="submit" class="btn-del" title="Apagar Despesa"><i class="fa-solid fa-trash-can"></i></button>
                                                </form>
                                            </div>
                                        <?php else: ?>
                                            <span title="Importado do relatório diário. Para alterar, deves editar o Relatório de Obra." style="color:#bdc3c7; cursor:not-allowed;"><i class="fa-solid fa-lock"></i></span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </div>

    <script>
        function adaptarFormulario() {
            const tipo = document.getElementById('tipo_despesa').value;
            const lblDesc = document.getElementById('lbl_descricao');
            const inpDesc = document.getElementById('input_descricao');
            const boxQtd = document.getElementById('box_quantidade');
            const lblQtd = document.getElementById('lbl_quantidade');

            // Reset
            boxQtd.style.display = 'none';
            inpDesc.required = true;

            if (tipo === 'Material') {
                lblDesc.innerText = 'Fornecedor / Loja *';
                inpDesc.placeholder = 'Ex: Leroy Merlin';
            }
            else if (tipo.includes('Mão de Obra')) {
                lblDesc.innerText = 'Descrição (Opcional)';
                inpDesc.placeholder = 'Ex: Trabalhos extra...';
                inpDesc.required = false;
                boxQtd.style.display = 'flex';
                lblQtd.innerText = 'Horas';
            }
            else if (tipo === 'Deslocação') {
                lblDesc.innerText = 'Origem - Destino *';
                inpDesc.placeholder = 'Ex: Sede - Obra';
                boxQtd.style.display = 'flex';
                lblQtd.innerText = 'KM';
            }
            else if (tipo === 'Refeição') {
                lblDesc.innerText = 'Restaurante / Colaborador *';
                inpDesc.placeholder = 'Ex: Restaurante O Manuel';
            }
            else {
                lblDesc.innerText = 'Descrição da Despesa *';
                inpDesc.placeholder = 'Ex: Portagens';
            }
        }

        // MODO EDIÇÃO
        function editarDespesa(d) {
            document.getElementById('id_despesa_edit').value = d.id;
            document.getElementById('tipo_despesa').value = d.tipo_despesa;

            document.getElementById('data_despesa').value = d.data_despesa;
            document.getElementById('input_descricao').value = d.descricao;
            document.getElementById('input_quantidade').value = d.quantidade > 0 ? parseFloat(d.quantidade).toString().replace('.', ',') : '';
            document.getElementById('input_valor').value = parseFloat(d.valor).toString().replace('.', ',');

            adaptarFormulario();

            document.getElementById('form_title').innerHTML = '<i class="fa-solid fa-pen-to-square"></i> Editar Despesa Manual';
            document.getElementById('btn_submit_despesa').innerHTML = '<i class="fa-solid fa-floppy-disk"></i> Atualizar';
            document.getElementById('add_despesa_flag').name = 'edit_despesa';
            document.getElementById('btn_cancel_edit').style.display = 'inline-flex';

            window.scrollTo({ top: document.getElementById('form_despesa_card').offsetTop - 80, behavior: 'smooth' });
        }

        function cancelarEdicao() {
            document.getElementById('id_despesa_edit').value = '';
            document.getElementById('tipo_despesa').value = 'Material';
            document.getElementById('data_despesa').value = '<?= date('Y-m-d') ?>';
            document.getElementById('input_descricao').value = '';
            document.getElementById('input_quantidade').value = '';
            document.getElementById('input_valor').value = '';

            adaptarFormulario();

            document.getElementById('form_title').innerHTML = '<i class="fa-solid fa-plus-circle"></i> Registar Nova Despesa Manual';
            document.getElementById('btn_submit_despesa').innerHTML = '<i class="fa-solid fa-check"></i> Adicionar';
            document.getElementById('add_despesa_flag').name = 'add_despesa';
            document.getElementById('btn_cancel_edit').style.display = 'none';
        }

        // Formatar valores numéricos em tempo real
        document.querySelectorAll('input[name="valor"], input[name="quantidade"]').forEach(input => {
            input.addEventListener('input', function() {
                if (this.value.indexOf('.') !== -1) {
                    let cursorPosition = this.selectionStart;
                    this.value = this.value.replace(/\./g, ',');
                    this.setSelectionRange(cursorPosition, cursorPosition);
                }
            });
        });

        window.onload = adaptarFormulario;
    </script>
</body>
</html>
