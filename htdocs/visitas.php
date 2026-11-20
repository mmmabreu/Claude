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
$user_id_atual = $_SESSION['user_id'];
$is_admin = in_array($perfil_user, ['admin', 'administrativo']);

// REGRA DE OURO 2: Acesso Restrito pela Matriz (Proteção de URL direta)
$stmt_perm = $pdo->prepare("SELECT modulo FROM permissoes WHERE grupo = :perfil AND pode_aceder = 1");
$stmt_perm->execute(['perfil' => $perfil_user]);
$meus_acessos = $stmt_perm->fetchAll(PDO::FETCH_COLUMN);

if (!$is_admin && !in_array('visitas', $meus_acessos)) {
    header("Location: index.php?erro=sem_permissao");
    exit;
}

// REGRA DE OURO: Obter apenas o primeiro nome para o Menu
$nome_completo = $_SESSION['nome'] ?? 'Utilizador';
$primeiro_nome = explode(' ', trim($nome_completo))[0];

$mensagem = '';
$tipo_mensagem = '';

if (isset($_GET['editado'])) {
    $mensagem = "Operação realizada com sucesso!";
    $tipo_mensagem = "sucesso";
}

// =====================================================================
// CHAMADAS RECEBIDAS (contactos de possíveis clientes registados pelo escritório)
// Qualquer utilizador com acesso às Visitas regista (Data, Nome, Morada, Contacto, Descrição).
// Admin / administrativo convertem em visita (visita_form.php?contacto=ID, já com os dados preenchidos)
// ou arquivam. Tabela visitas_contactos: estado Nova / Convertida / Arquivada.
// =====================================================================
if (!function_exists('contactosCriarTabela')) {
    function contactosCriarTabela($pdo) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS visitas_contactos (
            id INT AUTO_INCREMENT PRIMARY KEY,
            data_contacto DATE NULL,
            nome VARCHAR(150) NOT NULL,
            morada VARCHAR(255) NULL,
            contacto VARCHAR(120) NULL,
            descricao TEXT NULL,
            estado VARCHAR(20) NOT NULL DEFAULT 'Nova',
            id_visita INT NULL,
            registado_por INT NULL,
            criado_em DATETIME NULL,
            tratado_por INT NULL,
            tratado_em DATETIME NULL,
            INDEX (estado)
        ) DEFAULT CHARSET=utf8mb4");
    }
}
try { contactosCriarTabela($pdo); } catch (Exception $e) {}
$tab_ativa = in_array($_GET['tab'] ?? '', ['pendentes', 'chamadas', 'processadas'], true) ? $_GET['tab'] : 'pendentes';

// Registar / editar chamada
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['gravar_chamada'])) {
    $id_ch   = filter_input(INPUT_POST, 'id_chamada', FILTER_VALIDATE_INT);
    $data_ch = !empty($_POST['ch_data']) ? $_POST['ch_data'] : date('Y-m-d');
    $nome_ch = mb_substr(trim($_POST['ch_nome'] ?? ''), 0, 150);
    $mor_ch  = mb_substr(trim($_POST['ch_morada'] ?? ''), 0, 255);
    $cont_ch = mb_substr(trim($_POST['ch_contacto'] ?? ''), 0, 120);
    $desc_ch = trim($_POST['ch_descricao'] ?? '');
    $tab_ativa = 'chamadas';
    if ($nome_ch === '' || $cont_ch === '') {
        $mensagem = "Indique pelo menos o nome e o contacto."; $tipo_mensagem = "erro";
    } else {
        try {
            if ($id_ch) {
                // Quem registou pode corrigir enquanto a chamada não foi tratada; admin / administrativo sempre
                $st = $pdo->prepare("SELECT registado_por, estado FROM visitas_contactos WHERE id = ?");
                $st->execute([$id_ch]);
                $atual = $st->fetch(PDO::FETCH_ASSOC);
                if (!$atual || (!$is_admin && ((int)$atual['registado_por'] !== (int)$user_id_atual || $atual['estado'] !== 'Nova'))) throw new Exception("Sem permissão para alterar esta chamada.");
                $pdo->prepare("UPDATE visitas_contactos SET data_contacto = ?, nome = ?, morada = ?, contacto = ?, descricao = ? WHERE id = ?")
                    ->execute([$data_ch, $nome_ch, $mor_ch, $cont_ch, $desc_ch, $id_ch]);
                registrarLog($pdo, "Editou chamada recebida #$id_ch ($nome_ch)", "Visitas");
            } else {
                $pdo->prepare("INSERT INTO visitas_contactos (data_contacto, nome, morada, contacto, descricao, estado, registado_por, criado_em) VALUES (?, ?, ?, ?, ?, 'Nova', ?, NOW())")
                    ->execute([$data_ch, $nome_ch, $mor_ch, $cont_ch, $desc_ch, $user_id_atual]);
                registrarLog($pdo, "Registou chamada recebida ($nome_ch)", "Visitas");
            }
            header("Location: visitas.php?tab=chamadas&chamada=" . ($id_ch ? 'editada' : 'registada')); exit;
        } catch (Exception $e) {
            $mensagem = "Erro ao gravar a chamada: " . $e->getMessage(); $tipo_mensagem = "erro";
        }
    }
}
// Arquivar / reabrir (admin / administrativo)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['estado_chamada']) && $is_admin) {
    $id_ch = filter_input(INPUT_POST, 'id_chamada', FILTER_VALIDATE_INT);
    $novo = $_POST['estado_chamada'] === 'Arquivada' ? 'Arquivada' : 'Nova';
    if ($id_ch) {
        $pdo->prepare("UPDATE visitas_contactos SET estado = ?, tratado_por = ?, tratado_em = NOW() WHERE id = ? AND estado <> 'Convertida'")
            ->execute([$novo, $user_id_atual, $id_ch]);
        registrarLog($pdo, ($novo === 'Arquivada' ? 'Arquivou' : 'Reabriu') . " chamada recebida #$id_ch", "Visitas");
    }
    header("Location: visitas.php?tab=chamadas"); exit;
}
// Apagar chamada (admin / administrativo, com password de segurança)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['apagar_chamada_id'])) {
    $tab_ativa = 'chamadas';
    $stmt_pass = $pdo->prepare("SELECT valor FROM configuracoes WHERE chave = 'password_apagar' LIMIT 1");
    $stmt_pass->execute();
    $pass_config = $stmt_pass->fetchColumn();
    if (!$is_admin) {
        $mensagem = "Sem permissão para apagar chamadas."; $tipo_mensagem = "erro";
    } elseif (($_POST['pass_seguranca'] ?? '') === $pass_config && !empty($pass_config)) {
        $id_ch = (int)$_POST['apagar_chamada_id'];
        $pdo->prepare("DELETE FROM visitas_contactos WHERE id = ?")->execute([$id_ch]);
        registrarLog($pdo, "Apagou chamada recebida #$id_ch", "Visitas");
        $mensagem = "Chamada apagada."; $tipo_mensagem = "sucesso";
    } else {
        $mensagem = "Password de segurança incorreta!"; $tipo_mensagem = "erro";
    }
}
if (isset($_GET['chamada'])) {
    $mensagem = $_GET['chamada'] === 'editada' ? "Chamada atualizada." : "Chamada registada. Fica em «Chamadas recebidas» até ser convertida em visita.";
    $tipo_mensagem = "sucesso";
}
if (isset($_GET['convertida'])) { $mensagem = "Chamada convertida em visita."; $tipo_mensagem = "sucesso"; }

// LÓGICA DE APAGAR REGISTO
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['apagar_id'])) {
    $id_apagar = (int)$_POST['apagar_id'];
    $pass_introduzida = $_POST['pass_seguranca'] ?? '';

    $stmt_pass = $pdo->prepare("SELECT valor FROM configuracoes WHERE chave = 'password_apagar' LIMIT 1");
    $stmt_pass->execute();
    $pass_config = $stmt_pass->fetchColumn();

    if ($pass_introduzida === $pass_config && !empty($pass_config)) {
        try {
            $stmt_del = $pdo->prepare("DELETE FROM visitas WHERE id = ?");
            $stmt_del->execute([$id_apagar]);
            registrarLog($pdo, "Apagou Visita #$id_apagar", "Visitas");
            $mensagem = "Registo apagado com sucesso!";
            $tipo_mensagem = "sucesso";
        } catch (Exception $e) {
            $mensagem = "Erro ao apagar registo.";
            $tipo_mensagem = "erro";
        }
    } else {
        $mensagem = "Password de segurança incorreta!";
        $tipo_mensagem = "erro";
    }
}

// Levantamento de necessidades (áreas de cada visita comercial)
require_once 'levantamento_def.php';
try { levCriarTabela($pdo); } catch (Exception $e) {}

// --- BUSCAR VISITAS ---
$sql_base = "SELECT v.id, v.data_visita, v.hora_visita, v.assunto, v.estado, v.referencia_visita,
               v.id_obra, v.id_orcamento,
               c.nome AS cliente_nome,
               u.nome AS colab_nome,
               ob.nome AS nome_obra, ob.referencia_obra,
               orc.numero_orcamento,
               (SELECT GROUP_CONCAT(l.area ORDER BY l.ordem SEPARATOR ',') FROM visitas_levantamento l WHERE l.id_visita = v.id) AS lev_areas
        FROM visitas v
        LEFT JOIN clientes c ON v.id_cliente = c.id
        LEFT JOIN utilizadores u ON v.colaborador_1 = u.id
        LEFT JOIN obras ob ON v.id_obra = ob.id
        LEFT JOIN orcamentos orc ON v.id_orcamento = orc.id ";

// Só a parte comercial: visitas comerciais / angariação e acompanhamento de orçamentos.
// Os acompanhamentos e reuniões de obra (com id_obra) estão nos Relatórios de Obra — os dados não mudam de tabela.
$where_cond = " AND v.id_obra IS NULL ";
$params = [];

if (!$is_admin) {
    $where_cond .= " AND v.colaborador_1 = :user_id ";
    $params['user_id'] = $user_id_atual;
}

// ORDENAÇÃO POR TIPO DE VISITA (mesma ordem do formulário) E DEPOIS DATA DECRESCENTE
// 1 = Visita Comercial | 2 = Acompanhamento Orçamento (as de obra já não aparecem aqui)
// (Feita em PHP com a mesma lógica da função gerarContextoVisita)
function ordemTipoVisita($v) {
    if (!empty($v['id_obra'])) {
        return (stripos($v['assunto'] ?? '', 'Reunião') !== false) ? 4 : 3;
    }
    if (!empty($v['id_orcamento'])) return 2;
    return 1;
}

function ordenarVisitas(array &$lista) {
    usort($lista, function ($a, $b) {
        $ta = ordemTipoVisita($a);
        $tb = ordemTipoVisita($b);
        if ($ta != $tb) return ($ta < $tb) ? -1 : 1;                           // Tipo (crescente)
        $da = ($a['data_visita'] ?? '') . ' ' . ($a['hora_visita'] ?? '');
        $db = ($b['data_visita'] ?? '') . ' ' . ($b['hora_visita'] ?? '');
        return strcmp($db, $da);                                              // Data/Hora (decrescente)
    });
}

// 1. VISITAS PENDENTES (NÃO PROCESSADAS) - Lista normal corrida
$sql_pendentes = $sql_base . " WHERE v.estado = 'Não processada' " . $where_cond . " ORDER BY v.data_visita DESC, v.hora_visita DESC";
$stmt_pend = $pdo->prepare($sql_pendentes);
$stmt_pend->execute($params);
$visitas_pendentes = $stmt_pend->fetchAll(PDO::FETCH_ASSOC);
ordenarVisitas($visitas_pendentes);

// 2. VISITAS HISTÓRICO (PROCESSADAS)
$sql_processadas = $sql_base . " WHERE v.estado = 'Processada' " . $where_cond . " ORDER BY v.data_visita DESC, v.hora_visita DESC";
$stmt_proc = $pdo->prepare($sql_processadas);
$stmt_proc->execute($params);
$visitas_processadas_raw = $stmt_proc->fetchAll(PDO::FETCH_ASSOC);
ordenarVisitas($visitas_processadas_raw);

// Agrupar Histórico por Cliente
$visitas_processadas_agrupadas = [];
foreach ($visitas_processadas_raw as $v) {
    $cliente = $v['cliente_nome'] ?? 'Cliente Removido';
    if (!isset($visitas_processadas_agrupadas[$cliente])) {
        $visitas_processadas_agrupadas[$cliente] = [];
    }
    $visitas_processadas_agrupadas[$cliente][] = $v;
}
ksort($visitas_processadas_agrupadas);

// --- CHAMADAS RECEBIDAS ---
// Novas primeiro (mais antigas no topo, para não ficarem esquecidas); depois as tratadas dos últimos 90 dias
$chamadas = [];
try {
    $chamadas = $pdo->query("SELECT ch.*, u.nome AS registado_nome, t.nome AS tratado_nome, v.referencia_visita
                             FROM visitas_contactos ch
                             LEFT JOIN utilizadores u ON u.id = ch.registado_por
                             LEFT JOIN utilizadores t ON t.id = ch.tratado_por
                             LEFT JOIN visitas v ON v.id = ch.id_visita
                             WHERE ch.estado = 'Nova' OR COALESCE(ch.tratado_em, ch.criado_em) >= DATE_SUB(NOW(), INTERVAL 90 DAY)
                             ORDER BY (ch.estado = 'Nova') DESC,
                                      CASE WHEN ch.estado = 'Nova' THEN ch.data_contacto END ASC,
                                      CASE WHEN ch.estado = 'Nova' THEN ch.id END ASC,
                                      ch.data_contacto DESC, ch.id DESC")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}
$n_chamadas_novas = count(array_filter($chamadas, fn($c) => $c['estado'] === 'Nova'));

// FUNÇÃO AUXILIAR PARA GERAR O CONTEXTO VISUAL
function gerarContextoVisita($v) {
    if (!empty($v['id_obra'])) {
        if (stripos($v['assunto'], 'Reunião') !== false) {
            return '<span class="context-badge" style="background:#fff3e0; color:#e67e22; border-color:#ffe0b2;"><i class="fa-solid fa-people-group"></i> Reunião de Obra</span><br><span style="font-size:11px; color:#7f8c8d;">Obra: [' . htmlspecialchars($v['referencia_obra']) . '] ' . htmlspecialchars($v['nome_obra']) . '</span>';
        } else {
            return '<span class="context-badge" style="background:#e8f5e9; color:#27ae60; border-color:#c8e6c9;"><i class="fa-solid fa-helmet-safety"></i> Acompanhamento Obra</span><br><span style="font-size:11px; color:#7f8c8d;">Obra: [' . htmlspecialchars($v['referencia_obra']) . '] ' . htmlspecialchars($v['nome_obra']) . '</span>';
        }
    } elseif (!empty($v['id_orcamento'])) {
        return '<span class="context-badge" style="background:#e3f2fd; color:#2980b9; border-color:#bbdefb;"><i class="fa-solid fa-file-invoice"></i> Acompanhamento Orçamento</span><br><span style="font-size:11px; color:#7f8c8d;">Orç: [' . htmlspecialchars($v['numero_orcamento']) . ']</span>';
    } else {
        global $LEV_AREAS, $LEV_GRUPOS;
        $html = '<span class="context-badge" style="background:#fdf2f2; color:#e84393; border-color:#fadbd8;"><i class="fa-solid fa-handshake"></i> Visita Comercial</span>';
        if (!empty($v['lev_areas'])) {
            $html .= '<div class="lev-tags">';
            foreach (explode(',', $v['lev_areas']) as $ak) {
                if (!isset($LEV_AREAS[$ak])) continue;
                $cor = '#7f8c8d';
                foreach ($LEV_GRUPOS as $g) { if (in_array($ak, $g['areas'], true)) { $cor = $g['cor']; break; } }
                $html .= '<span class="lev-tag" style="color:' . $cor . '; border-color:' . $cor . '55;"><i class="fa-solid ' . $LEV_AREAS[$ak]['icone'] . '"></i> ' . htmlspecialchars($LEV_AREAS[$ak]['nome']) . '</span>';
            }
            $html .= '</div>';
        }
        return $html;
    }
}
?>
<!DOCTYPE html>
<html lang="pt-PT">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Visitas Comerciais - Gestão MAB</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { font-family: system-ui, -apple-system, sans-serif; box-sizing: border-box; }
        body { background-color: #f7f9fc; margin: 0; color: #333; font-size: 14px; padding-bottom: 50px; }

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
        .dropdown-content a:hover { background: #fdf2f2; color: #e84393; }

        .container { max-width: 1200px; margin: 20px auto; padding: 0 15px; }

        /* HEADER E BOTÃO NOVO MINIMALISTA (ROSA) */
        .header-box { display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px; flex-wrap: wrap; gap: 15px; }
        h1 { margin: 0; color: #2c3e50; font-size: 22px; font-weight: 800; }

        .btn-novo { background: #e84393; color: #fff; border: 1px solid #e84393; padding: 10px 20px; border-radius: 8px; text-decoration: none; font-weight: 700; font-size: 14px; display: inline-flex; align-items: center; gap: 8px; transition: 0.2s; }
        .btn-novo:hover { background: #d81b60; border-color: #d81b60; }

        .alert { padding: 15px; border-radius: 8px; margin-bottom: 20px; text-align: center; font-weight: 600; }
        .alert-erro { background: #fdeded; color: #e84393; border: 1px solid #f9cccc; }
        .alert-sucesso { background: #e8f5e9; color: #2e7d32; border: 1px solid #c8e6c9; }

        /* TABS (SEPARADORES) */
        .tabs { display: flex; gap: 10px; margin-bottom: 20px; border-bottom: 2px solid #e1e5ea; padding-bottom: 0px; }
        .tab-btn { background: transparent; color: #7f8c8d; font-weight: 700; font-size: 15px; padding: 12px 20px; border: none; cursor: pointer; border-bottom: 3px solid transparent; margin-bottom: -2px; transition: 0.2s; display: flex; align-items: center; gap: 8px; }
        .tab-btn:hover { color: #2c3e50; }
        .tab-btn.active { color: #e84393; border-bottom-color: #e84393; }

        .tab-content { display: none; animation: fadeIn 0.3s ease; }
        .tab-content.active { display: block; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(5px); } to { opacity: 1; transform: translateY(0); } }

        /* Tabela */
        .card { background: #fff; border-radius: 12px; border: 1px solid #e1e5ea; box-shadow: 0 2px 10px rgba(0,0,0,0.02); overflow: hidden; margin-bottom: 40px; }
        .table-responsive { width: 100%; overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; min-width: 800px; }
        th { background: #f8f9fa; padding: 12px 15px; text-align: left; font-size: 11px; text-transform: uppercase; color: #576574; border-bottom: 2px solid #e1e5ea; }
        td { padding: 12px 15px; border-bottom: 1px solid #f1f2f6; color: #2c3e50; vertical-align: top; }
        tr:hover { background: #fcfcfc; }

        .acoes { display: flex; gap: 8px; }
        .btn-print-action { color: #3498db; background: #f0f8ff; padding: 8px; border: none; border-radius: 6px; cursor: pointer; transition: 0.2s; display: inline-flex; align-items: center; justify-content: center; width: 32px; height: 32px; font-size: 15px; text-decoration: none; }
        .btn-print-action:hover { background: #d6eaf8; color: #2980b9; }
        .btn-edit { color: #576574; background: #f1f2f6; padding: 8px; border-radius: 6px; text-decoration: none; transition: 0.2s; display: inline-flex; align-items: center; justify-content: center; width: 32px; height: 32px; }
        .btn-edit:hover { background: #e1e5ea; color: #2c3e50; }
        .btn-delete { color: #e74c3c; background: #fdeded; padding: 8px; border: none; border-radius: 6px; cursor: pointer; transition: 0.2s; display: inline-flex; align-items: center; justify-content: center; width: 32px; height: 32px; }
        .btn-delete:hover { background: #fadbd8; color: #c0392b; }

        .truncate { max-width: 250px; white-space: normal; display: inline-block; }
        .col-mobile { display: none; }
        .lev-tags { display: flex; flex-wrap: wrap; gap: 4px; margin-top: 4px; }
        .lev-tag { font-size: 10px; font-weight: 700; border: 1px solid; border-radius: 12px; padding: 2px 7px; background: #fff; white-space: nowrap; }
        .context-badge { display: inline-block; font-size: 11px; font-weight: 700; padding: 3px 8px; border-radius: 4px; margin-top: 5px; margin-bottom: 2px; border: 1px solid transparent; text-transform: uppercase; }

        /* Chamadas recebidas */
        .header-acoes { display: flex; gap: 10px; flex-wrap: wrap; }
        .btn-chamada { background: #fff; color: #e84393; border: 1px solid #e84393; padding: 10px 18px; border-radius: 8px; font-weight: 700; font-size: 14px; display: inline-flex; align-items: center; gap: 8px; cursor: pointer; font-family: inherit; transition: 0.2s; }
        .btn-chamada:hover { background: #fdf2f2; }
        .tab-count { background: #e84393; color: white; padding: 2px 8px; border-radius: 20px; font-size: 11px; margin-left: 5px; }
        .tab-count.cinza { background: #bdc3c7; }
        .ch-nome { font-weight: 700; color: #2c3e50; font-size: 14px; }
        .ch-meta { font-size: 11px; color: #95a5a6; margin-top: 3px; }
        .ch-contacto a { color: #2980b9; text-decoration: none; font-weight: 600; }
        .ch-desc { white-space: pre-line; font-size: 13px; color: #576574; max-width: 380px; }
        .ch-estado { display: inline-block; font-size: 10px; font-weight: 800; padding: 3px 8px; border-radius: 4px; text-transform: uppercase; }
        .ch-estado.nova { background: #fdf2f2; color: #e84393; }
        .ch-estado.convertida { background: #e8f5e9; color: #27ae60; }
        .ch-estado.arquivada { background: #f1f2f6; color: #7f8c8d; }
        tr.ch-tratada td { color: #95a5a6; }
        tr.ch-tratada .ch-nome { color: #7f8c8d; }
        .btn-converter { color: #fff; background: #e84393; padding: 0 12px; border: none; border-radius: 6px; cursor: pointer; display: inline-flex; align-items: center; gap: 6px; height: 32px; font-size: 12px; font-weight: 700; text-decoration: none; white-space: nowrap; font-family: inherit; }
        .btn-converter:hover { background: #d81b60; }
        button.btn-edit { border: none; cursor: pointer; }
        .btn-arquivar { color: #7f8c8d; background: #f1f2f6; padding: 8px; border: none; border-radius: 6px; cursor: pointer; display: inline-flex; align-items: center; justify-content: center; width: 32px; height: 32px; }
        .btn-arquivar:hover { background: #e1e5ea; color: #2c3e50; }
        .form-ch { text-align: left; }
        .form-ch label { display: block; font-size: 11px; font-weight: 700; color: #576574; text-transform: uppercase; margin: 12px 0 5px; }
        .form-ch input, .form-ch textarea { width: 100%; padding: 11px 12px; border: 1px solid #dcdde1; border-radius: 8px; font-size: 14px; font-family: inherit; outline: none; }
        .form-ch input:focus, .form-ch textarea:focus { border-color: #e84393; box-shadow: 0 0 0 3px rgba(232,67,147,0.1); }
        .form-ch textarea { resize: vertical; min-height: 90px; }
        .form-ch .linha2 { display: grid; grid-template-columns: 150px 1fr; gap: 12px; }
        .modal-content.largo { max-width: 520px; max-height: 94vh; overflow-y: auto; }

        /* Estilo Agrupamento Cliente */
        .group-header { background-color: #f1f2f6; }
        .group-header:hover { background-color: #f1f2f6; }
        .group-header td { padding: 10px 15px; color: #2c3e50; font-weight: 800; font-size: 13px; text-transform: uppercase; border-bottom: 2px solid #dcdde1; }

        /* Modal Apagar (Cores Visitas) */
        .modal { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.6); z-index: 1001; justify-content: center; align-items: center; padding: 20px; }
        .modal.show { display: flex; }
        .modal-content { background: #fff; padding: 30px; border-radius: 12px; width: 100%; max-width: 400px; text-align: center; }

        .modal-content input[type="password"] { width: 100%; padding: 12px; border: 1px solid #dcdde1; border-radius: 8px; margin: 15px 0; outline: none; }
        .modal-content input[type="password"]:focus { border-color: #e84393; }
        .modal-btns { display: flex; gap: 10px; }
        .modal-btns button { flex: 1; padding: 12px; border: none; border-radius: 8px; font-weight: 600; cursor: pointer; }
        .btn-cancelar { background: #f1f2f6; color: #576574; }
        .btn-confirmar { background: #e84393; color: #fff; }

        /* REGRA DE OURO MOBILE */
        @media (max-width: 1024px) { .topbar { padding: 10px 24px; } }
        @media (max-width: 768px) {
            .topbar { padding: 8px 15px; }
            .topbar-left .logo { height: 30px; }
            .btn-voltar { width: 38px; height: 38px; }

            .header-box { flex-direction: row; justify-content: space-between; border-bottom: none; margin-bottom: 10px; }
            .tabs { flex-direction: column; gap: 5px; border-bottom: none; }
            .tab-btn { border-radius: 8px; border-bottom: none; background: #f8f9fa; }
            .tab-btn.active { background: #fdf2f2; color: #e84393; }

            .card { background: transparent; border: none; box-shadow: none; }
            table, thead, tbody, th, td, tr { display: block; }
            table { min-width: 0; }
            thead tr { position: absolute; top: -9999px; left: -9999px; }
            tr { background: #fff; margin-bottom: 15px; border-radius: 12px; border: 1px solid #e1e5ea; box-shadow: 0 4px 10px rgba(0,0,0,0.03); padding: 5px 0; }
            td { border: none; border-bottom: 1px solid #f1f2f6; position: relative; padding: 12px 15px 12px 35%; text-align: right; min-height: 40px; display: flex; justify-content: flex-end; align-items: center; }
            td::before { content: attr(data-label); position: absolute; left: 15px; width: 30%; text-align: left; font-weight: 700; color: #576574; font-size: 11px; text-transform: uppercase; }
            td:last-child { border-bottom: none; }

            .col-pc { display: none; }
            .col-mobile { display: block; }

            .mobile-data { font-size: 11px; color: #7f8c8d; margin-bottom: 2px; font-weight: normal; }
            .truncate { max-width: 100%; white-space: normal; text-align: right; }
            .ch-desc { max-width: 100%; text-align: right; }

            /* Ajustes Mobile para o Cabeçalho do Grupo (Histórico) */
            tr.group-header { background: #f1f2f6; padding: 10px; text-align: center; border-radius: 8px; margin-bottom: 10px; }
            tr.group-header td { display: block; text-align: center !important; padding: 5px !important; border: none; justify-content: center; }
            tr.group-header td::before { display: none; }
            tr.group-header span { display: block; margin-top: 5px; float: none !important; }
        }
        @media (max-width: 560px) {
            .user-nome { display: none; }
            .user-btn { padding: 5px 10px 5px 5px; }
            .header-box { flex-direction: column; align-items: stretch; }
            .btn-novo { justify-content: center; }
            .header-acoes { flex-direction: column; }
            .btn-chamada { justify-content: center; }
            .form-ch .linha2 { grid-template-columns: 1fr; gap: 0; }
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
            <div class="alert <?= $tipo_mensagem === 'sucesso' ? 'alert-sucesso' : 'alert-erro' ?>"><?= htmlspecialchars($mensagem, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>

        <div class="header-box">
            <h1>Visitas Comerciais e Orçamentos</h1>
            <div class="header-acoes">
                <button type="button" class="btn-chamada" onclick="abrirChamada()"><i class="fa-solid fa-phone-volume"></i> Registar Chamada</button>
                <a href="visita_form.php" class="btn-novo"><i class="fa-solid fa-plus"></i> Novo Registo</a>
            </div>
        </div>
        <div class="tabs">
            <button class="tab-btn<?= $tab_ativa === 'pendentes' ? ' active' : '' ?>" onclick="switchTab('pendentes', this)"><i class="fa-regular fa-clock"></i> Por Processar <span class="tab-count"><?= count($visitas_pendentes) ?></span></button>
            <button class="tab-btn<?= $tab_ativa === 'chamadas' ? ' active' : '' ?>" onclick="switchTab('chamadas', this)"><i class="fa-solid fa-phone-volume"></i> Chamadas Recebidas <span class="tab-count<?= $n_chamadas_novas ? '' : ' cinza' ?>"><?= $n_chamadas_novas ?></span></button>
            <button class="tab-btn<?= $tab_ativa === 'processadas' ? ' active' : '' ?>" onclick="switchTab('processadas', this)"><i class="fa-solid fa-check-double"></i> Histórico (Fechadas)</button>
        </div>

        <!-- TAB: PENDENTES -->
        <div id="tab-pendentes" class="tab-content<?= $tab_ativa === 'pendentes' ? ' active' : '' ?>">
            <div class="card">
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th class="col-pc" style="width: 140px;">Data / Hora</th>
                                <th>Cliente / Contexto</th>
                                <th class="col-colab">Colaborador</th>
                                <th class="col-pc">Assunto</th>
                                <th style="width: 120px; text-align: center;">Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($visitas_pendentes) > 0): ?>
                                <?php foreach ($visitas_pendentes as $v): ?>
                                    <?php
                                        $data_formatada = date('d/m/Y', strtotime($v['data_visita']));
                                        $hora_formatada = date('H:i', strtotime($v['hora_visita']));
                                        $datetime_txt = $data_formatada . ' às ' . $hora_formatada;
                                    ?>
                                    <tr>
                                        <td class="col-pc" data-label="Data" style="font-weight:600; color: #e84393;"><?= $datetime_txt ?></td>

                                        <td data-label="Cliente">
                                            <div class="col-mobile mobile-data"><?= $datetime_txt ?></div>
                                            <span style="font-weight: 700; color:#2c3e50; font-size:14px;"><?= htmlspecialchars($v['cliente_nome'] ?? 'Cliente Removido') ?></span>
                                            <br>
                                            <?= gerarContextoVisita($v) ?>
                                        </td>

                                        <td class="col-colab" data-label="Colab."><?= htmlspecialchars($v['colab_nome'] ?? 'N/D') ?></td>
                                        <td class="col-pc" data-label="Assunto"><span class="truncate" title="<?= htmlspecialchars($v['assunto']) ?>"><?= htmlspecialchars($v['assunto']) ?></span></td>

                                        <td data-label="Ações">
                                            <div class="acoes" style="justify-content: flex-end;">
                                                <a href="visita_pdf.php?id=<?= $v['id'] ?>" target="_blank" class="btn-print-action" title="Imprimir Relatório"><i class="fa-solid fa-print"></i></a>
                                                <a href="visita_form.php?id=<?= $v['id'] ?>" class="btn-edit" title="Editar / Fechar"><i class="fa-solid fa-pen-to-square"></i></a>
                                                <button type="button" class="btn-delete" title="Apagar" onclick="abrirModalApagar(<?= $v['id'] ?>)"><i class="fa-solid fa-trash-can"></i></button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="5" style="text-align: center; padding: 40px; color: #7f8c8d; display:table-cell;">Nenhuma visita pendente de processamento. Bom trabalho!</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- TAB: CHAMADAS RECEBIDAS -->
        <div id="tab-chamadas" class="tab-content<?= $tab_ativa === 'chamadas' ? ' active' : '' ?>">
            <div class="card">
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th class="col-pc" style="width: 110px;">Data</th>
                                <th>Nome / Morada</th>
                                <th style="width: 170px;">Contacto</th>
                                <th>Descrição</th>
                                <th style="width: 110px;">Estado</th>
                                <th style="width: 230px; text-align: center;">Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($chamadas): ?>
                                <?php foreach ($chamadas as $ch):
                                    $data_ch = !empty($ch['data_contacto']) ? date('d/m/Y', strtotime($ch['data_contacto'])) : '-';
                                    $tel = preg_replace('/[^0-9+]/', '', (string)$ch['contacto']);
                                    $eh_email = strpos((string)$ch['contacto'], '@') !== false;
                                    $pode_editar_ch = $is_admin || ((int)$ch['registado_por'] === (int)$user_id_atual && $ch['estado'] === 'Nova');
                                    $classe_estado = ['Nova' => 'nova', 'Convertida' => 'convertida', 'Arquivada' => 'arquivada'][$ch['estado']] ?? 'nova';
                                ?>
                                <tr class="<?= $ch['estado'] !== 'Nova' ? 'ch-tratada' : '' ?>">
                                    <td class="col-pc" data-label="Data" style="font-weight:600; color:#e84393;"><?= $data_ch ?></td>
                                    <td data-label="Nome">
                                        <div class="ch-bloco">
                                        <div class="col-mobile mobile-data"><?= $data_ch ?></div>
                                        <span class="ch-nome"><?= htmlspecialchars($ch['nome']) ?></span>
                                        <?php if (!empty($ch['morada'])): ?><div style="font-size:12px; color:#7f8c8d; margin-top:2px;"><i class="fa-solid fa-location-dot" style="font-size:10px;"></i> <?= htmlspecialchars($ch['morada']) ?></div><?php endif; ?>
                                        <div class="ch-meta">Registado por <?= htmlspecialchars($ch['registado_nome'] ?? '-') ?><?= !empty($ch['criado_em']) ? ' · ' . date('d/m/Y H:i', strtotime($ch['criado_em'])) : '' ?></div>
                                        </div>
                                    </td>
                                    <td data-label="Contacto" class="ch-contacto">
                                        <?php if ($eh_email): ?><a href="mailto:<?= htmlspecialchars($ch['contacto']) ?>"><?= htmlspecialchars($ch['contacto']) ?></a>
                                        <?php elseif (strlen($tel) >= 9): ?><a href="tel:<?= htmlspecialchars($tel) ?>"><i class="fa-solid fa-phone" style="font-size:11px;"></i> <?= htmlspecialchars($ch['contacto']) ?></a>
                                        <?php else: ?><?= htmlspecialchars($ch['contacto']) ?><?php endif; ?>
                                    </td>
                                    <td data-label="Descrição"><div class="ch-desc"><?= htmlspecialchars($ch['descricao'] ?? '') ?></div></td>
                                    <td data-label="Estado">
                                        <div class="ch-bloco"><span class="ch-estado <?= $classe_estado ?>"><?= htmlspecialchars($ch['estado']) ?></span>
                                        <?php if ($ch['estado'] === 'Convertida' && !empty($ch['id_visita'])): ?><div class="ch-meta">Visita <?= htmlspecialchars($ch['referencia_visita'] ?? ('#' . $ch['id_visita'])) ?></div>
                                        <?php elseif ($ch['estado'] !== 'Nova' && !empty($ch['tratado_nome'])): ?><div class="ch-meta"><?= htmlspecialchars($ch['tratado_nome']) ?></div><?php endif; ?>
                                        </div>
                                    </td>
                                    <td data-label="Ações">
                                        <div class="acoes" style="justify-content: flex-end;">
                                            <?php if ($is_admin && $ch['estado'] === 'Nova'): ?>
                                                <a href="visita_form.php?contacto=<?= (int)$ch['id'] ?>" class="btn-converter" title="Converter em visita (abre a visita já preenchida)"><i class="fa-solid fa-arrow-right-to-bracket"></i> Converter</a>
                                                <form method="POST" style="display:inline;"><input type="hidden" name="id_chamada" value="<?= (int)$ch['id'] ?>"><button type="submit" name="estado_chamada" value="Arquivada" class="btn-arquivar" title="Arquivar (sem interesse / resolvida por telefone)"><i class="fa-solid fa-box-archive"></i></button></form>
                                            <?php elseif ($is_admin && $ch['estado'] === 'Arquivada'): ?>
                                                <form method="POST" style="display:inline;"><input type="hidden" name="id_chamada" value="<?= (int)$ch['id'] ?>"><button type="submit" name="estado_chamada" value="Nova" class="btn-arquivar" title="Reabrir"><i class="fa-solid fa-rotate-left"></i></button></form>
                                            <?php elseif ($ch['estado'] === 'Convertida' && !empty($ch['id_visita'])): ?>
                                                <a href="visita_form.php?id=<?= (int)$ch['id_visita'] ?>" class="btn-edit" title="Abrir a visita"><i class="fa-solid fa-eye"></i></a>
                                            <?php endif; ?>
                                            <?php if ($pode_editar_ch): ?>
                                                <button type="button" class="btn-edit" title="Editar" onclick='abrirChamada(<?= json_encode(['id' => (int)$ch['id'], 'data' => $ch['data_contacto'], 'nome' => $ch['nome'], 'morada' => $ch['morada'], 'contacto' => $ch['contacto'], 'descricao' => $ch['descricao']], JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_UNESCAPED_UNICODE) ?>)'><i class="fa-solid fa-pen-to-square"></i></button>
                                            <?php endif; ?>
                                            <?php if ($is_admin): ?>
                                                <button type="button" class="btn-delete" title="Apagar" onclick="abrirModalApagarChamada(<?= (int)$ch['id'] ?>)"><i class="fa-solid fa-trash-can"></i></button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="6" style="text-align: center; padding: 40px; color: #7f8c8d; display:table-cell;">Sem chamadas registadas. Use «Registar Chamada» quando um possível cliente ligar.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- TAB: HISTÓRICO (AGRUPADAS POR CLIENTE) -->
        <div id="tab-processadas" class="tab-content<?= $tab_ativa === 'processadas' ? ' active' : '' ?>">
            <div class="card">
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th class="col-pc" style="width: 140px;">Data / Hora</th>
                                <th>Contexto da Visita</th>
                                <th class="col-colab">Colaborador</th>
                                <th class="col-pc">Assunto</th>
                                <th style="width: 120px; text-align: center;">Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($visitas_processadas_agrupadas) > 0): ?>
                                <?php foreach ($visitas_processadas_agrupadas as $cliente => $visitas_cliente): ?>

                                    <!-- LINHA DE AGRUPAMENTO (CLIENTE) -->
                                    <tr class="group-header">
                                        <td colspan="5">
                                            <i class="fa-solid fa-building" style="color: #95a5a6; margin-right: 8px;"></i> <?= htmlspecialchars($cliente) ?>
                                            <span style="float: right; font-size: 11px; color: #7f8c8d; font-weight: 600; padding-top:2px;"><?= count($visitas_cliente) ?> registo(s)</span>
                                        </td>
                                    </tr>

                                    <!-- LISTAGEM DAS VISITAS DESSE CLIENTE -->
                                    <?php foreach ($visitas_cliente as $v): ?>
                                        <?php
                                            $data_formatada = date('d/m/Y', strtotime($v['data_visita']));
                                            $hora_formatada = date('H:i', strtotime($v['hora_visita']));
                                            $datetime_txt = $data_formatada . ' às ' . $hora_formatada;
                                        ?>
                                        <tr>
                                            <td class="col-pc" data-label="Data" style="font-weight:600; color: #7f8c8d;"><?= $datetime_txt ?></td>

                                            <td data-label="Contexto">
                                                <div class="col-mobile mobile-data"><?= $datetime_txt ?></div>
                                                <?= gerarContextoVisita($v) ?>
                                            </td>

                                            <td class="col-colab" data-label="Colab."><?= htmlspecialchars($v['colab_nome'] ?? 'N/D') ?></td>
                                            <td class="col-pc" data-label="Assunto"><span class="truncate" title="<?= htmlspecialchars($v['assunto']) ?>"><?= htmlspecialchars($v['assunto']) ?></span></td>

                                            <td data-label="Ações">
                                                <div class="acoes" style="justify-content: flex-end;">
                                                    <a href="visita_pdf.php?id=<?= $v['id'] ?>" target="_blank" class="btn-print-action" title="Imprimir Relatório"><i class="fa-solid fa-print"></i></a>
                                                    <a href="visita_form.php?id=<?= $v['id'] ?>" class="btn-edit" title="Ver / Editar"><i class="fa-solid fa-eye"></i></a>
                                                    <button type="button" class="btn-delete" title="Apagar" onclick="abrirModalApagar(<?= $v['id'] ?>)"><i class="fa-solid fa-trash-can"></i></button>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="5" style="text-align: center; padding: 40px; color: #7f8c8d;">Nenhum registo no histórico.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

    </div>

    <div class="modal" id="modalApagar">
        <div class="modal-content">
            <h3 style="color:#e84393; margin-top:0;"><i class="fa-solid fa-triangle-exclamation"></i> Apagar Registo</h3>
            <p>Tem a certeza que deseja apagar o registo selecionado?</p>

            <form method="POST">
                <input type="hidden" name="apagar_id" id="input_apagar_id">
                <input type="password" name="pass_seguranca" placeholder="Insira a Password de Segurança" required>

                <div class="modal-btns">
                    <button type="button" class="btn-cancelar" onclick="fecharModalApagar()">Cancelar</button>
                    <button type="submit" class="btn-confirmar">Apagar</button>
                </div>
            </form>
        </div>
    </div>

    <!-- JANELA: REGISTAR / EDITAR CHAMADA -->
    <div class="modal" id="modalChamada">
        <div class="modal-content largo">
            <h3 style="color:#e84393; margin:0 0 4px;" id="ch_titulo"><i class="fa-solid fa-phone-volume"></i> Registar Chamada</h3>
            <p style="font-size:12px; color:#7f8c8d; margin:0;">Possível cliente que ligou. Depois é convertido em visita.</p>
            <form method="POST" class="form-ch" autocomplete="off">
                <input type="hidden" name="gravar_chamada" value="1">
                <input type="hidden" name="id_chamada" id="ch_id" value="">
                <div class="linha2">
                    <div><label>Data *</label><input type="date" name="ch_data" id="ch_data" value="<?= date('Y-m-d') ?>" required></div>
                    <div><label>Nome *</label><input type="text" name="ch_nome" id="ch_nome" maxlength="150" required placeholder="Nome de quem ligou / empresa"></div>
                </div>
                <label>Morada</label>
                <input type="text" name="ch_morada" id="ch_morada" maxlength="255" placeholder="Morada do local / localidade">
                <label>Contacto *</label>
                <input type="text" name="ch_contacto" id="ch_contacto" maxlength="120" required placeholder="Telefone ou email" inputmode="text">
                <label>Descrição</label>
                <textarea name="ch_descricao" id="ch_descricao" rows="4" placeholder="O que pretende, melhor horário para ligar, como conheceu a empresa..."></textarea>
                <div class="modal-btns" style="margin-top:18px;">
                    <button type="button" class="btn-cancelar" onclick="fecharModal('modalChamada')">Cancelar</button>
                    <button type="submit" class="btn-confirmar"><i class="fa-solid fa-floppy-disk"></i> Gravar</button>
                </div>
            </form>
        </div>
    </div>

    <div class="modal" id="modalApagarChamada">
        <div class="modal-content">
            <h3 style="color:#e84393; margin-top:0;"><i class="fa-solid fa-triangle-exclamation"></i> Apagar Chamada</h3>
            <p>Tem a certeza que deseja apagar esta chamada?</p>
            <form method="POST">
                <input type="hidden" name="apagar_chamada_id" id="input_apagar_chamada_id">
                <input type="password" name="pass_seguranca" placeholder="Insira a Password de Segurança" required>
                <div class="modal-btns">
                    <button type="button" class="btn-cancelar" onclick="fecharModal('modalApagarChamada')">Cancelar</button>
                    <button type="submit" class="btn-confirmar">Apagar</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        (function () {
            const menu = document.querySelector('.user-menu');
            const btn = menu ? menu.querySelector('.user-btn') : null;
            if (!btn) return;
            btn.addEventListener('click', function (e) { e.stopPropagation(); menu.classList.toggle('aberto'); });
            document.addEventListener('click', function () { menu.classList.remove('aberto'); });
        })();

        function switchTab(tabId, btn) {
            document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
            document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));

            btn.classList.add('active');
            document.getElementById('tab-' + tabId).classList.add('active');
        }

        function abrirModalApagar(id) {
            document.getElementById('input_apagar_id').value = id;
            document.getElementById('modalApagar').classList.add('show');
        }
        function fecharModalApagar() {
            document.getElementById('modalApagar').classList.remove('show');
        }

        // ---------- Chamadas recebidas ----------
        function abrirChamada(c) {
            c = c || {};
            document.getElementById('ch_titulo').innerHTML = '<i class="fa-solid fa-phone-volume"></i> ' + (c.id ? 'Editar Chamada' : 'Registar Chamada');
            document.getElementById('ch_id').value = c.id || '';
            document.getElementById('ch_data').value = c.data || '<?= date('Y-m-d') ?>';
            document.getElementById('ch_nome').value = c.nome || '';
            document.getElementById('ch_morada').value = c.morada || '';
            document.getElementById('ch_contacto').value = c.contacto || '';
            document.getElementById('ch_descricao').value = c.descricao || '';
            document.getElementById('modalChamada').classList.add('show');
            setTimeout(function () { document.getElementById('ch_nome').focus(); }, 50);
        }
        function abrirModalApagarChamada(id) {
            document.getElementById('input_apagar_chamada_id').value = id;
            document.getElementById('modalApagarChamada').classList.add('show');
        }
        function fecharModal(id) { document.getElementById(id).classList.remove('show'); }

        window.onclick = function(e) {
            if (e.target.id === 'modalApagar') fecharModalApagar();
            if (e.target.id === 'modalChamada') fecharModal('modalChamada');
            if (e.target.id === 'modalApagarChamada') fecharModal('modalApagarChamada');
        };
    </script>
</body>
</html>