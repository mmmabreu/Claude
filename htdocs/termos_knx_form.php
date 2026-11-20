<?php
// FORÇAR EXIBIÇÃO DE ERROS BÁSICOS
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// ----------------------------------------------------------------------
// AUXILIARES DE JSON (robustos a acentos/carateres inválidos)
// ----------------------------------------------------------------------
// Lê o JSON gravado na BD sem "perder" os dados se houver algum carater inválido
function decodificarJsonSeguro($str) {
    if ($str === null || $str === '') return [];
    $arr = json_decode($str, true, 512, JSON_INVALID_UTF8_SUBSTITUTE);
    if (!is_array($arr)) {
        // Última tentativa: limpar bytes inválidos e voltar a ler
        $limpo = mb_convert_encoding($str, 'UTF-8', 'UTF-8');
        $arr = json_decode($limpo, true);
    }
    return is_array($arr) ? $arr : [];
}
// Escreve dados PHP dentro do <script> sem nunca partir o JavaScript
function jsonParaJs($data) {
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    return $json !== false ? $json : '[]';
}

try {
    session_start();
    require_once 'db.php';
    // REGRA DE OURO 1: Validação de sessão
    if (!isset($_SESSION['user_id'])) { header("Location: index.php"); exit; }
    // ----------------------------------------------------------------------
    // [AJAX] LÓGICA DE CRIAÇÃO RÁPIDA (CLIENTE) SEM RECARREGAR A PÁGINA
    // ----------------------------------------------------------------------
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action'])) {
        header('Content-Type: application/json');

        if ($_POST['ajax_action'] === 'novo_cliente') {
            $nome = trim($_POST['nome_cliente']);
            $nif = trim($_POST['nif_cliente']);
            $morada = trim($_POST['morada_cliente']);

            $stmt = $pdo->prepare("INSERT INTO clientes (nome, nif, morada) VALUES (?, ?, ?)");
            $stmt->execute([$nome, $nif, $morada]);
            echo json_encode(['success' => true, 'id' => $pdo->lastInsertId(), 'nome' => $nome]);
            exit;
        }
    }
    // ----------------------------------------------------------------------
    if (file_exists('functions.php')) { require_once 'functions.php'; }
    else {
        function registrarLog($pdo, $acao, $modulo) { return true; }
        function gerar_csrf_token() { return ''; }
        function validar_csrf_token($t) { return true; }
        function safe_json_encode($data) { return json_encode($data); }
    }
    $perfil_user = $_SESSION['perfil'] ?? '';
    $is_admin = in_array($perfil_user, ['admin', 'administrativo']);
    $stmt_perm = $pdo->prepare("SELECT modulo FROM permissoes WHERE grupo = :perfil AND pode_aceder = 1");
    $stmt_perm->execute(['perfil' => $perfil_user]);
    $meus_acessos = $stmt_perm->fetchAll(PDO::FETCH_COLUMN);
    if (!$is_admin && !in_array('termos', $meus_acessos) && !in_array('termos_knx', $meus_acessos)) {
        header("Location: index.php?erro=sem_permissao"); exit;
    }
    $nome_completo = $_SESSION['nome'] ?? 'Utilizador';
    $primeiro_nome = explode(' ', trim($nome_completo))[0];
    $csrf_token = gerar_csrf_token();
    // FAILSAFE: Criar coluna para os Endereços de Grupo automaticamente se não existir
    try {
        $check_grupos = $pdo->query("SHOW COLUMNS FROM termos_knx LIKE 'grupos_json'");
        if ($check_grupos !== false && $check_grupos->rowCount() == 0) {
            $pdo->exec("ALTER TABLE termos_knx ADD COLUMN grupos_json LONGTEXT AFTER dados_json");
        }
    } catch(Exception $e) {}
    // FAILSAFE: Criar coluna para as Notas automaticamente se não existir
    try {
        $check_notas = $pdo->query("SHOW COLUMNS FROM termos_knx LIKE 'notas'");
        if ($check_notas !== false && $check_notas->rowCount() == 0) {
            $pdo->exec("ALTER TABLE termos_knx ADD COLUMN notas TEXT DEFAULT NULL AFTER grupos_json");
        }
    } catch(Exception $e) {}
    $mensagem = ''; $tipo_mensagem = '';

    // VERIFICAR MODO DE ABERTURA E HERANÇA
    $id_editar = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
    $id_duplicar = filter_input(INPUT_GET, 'duplicar', FILTER_VALIDATE_INT);
    $import_visita_id = filter_input(INPUT_GET, 'import_visita', FILTER_VALIDATE_INT);
    $import_obra_id = filter_input(INPUT_GET, 'import_obra', FILTER_VALIDATE_INT);
    $modo_editar = !empty($id_editar);
    $id_termo = $id_editar ?: ($id_duplicar ?: null);
    // --- LÓGICA DE GRAVAÇÃO (POST DO RELATÓRIO) ---
    if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['btn_guardar'])) {
        if (!validar_csrf_token($_POST['csrf_token'] ?? '')) {
            if (isset($_POST['via_ajax'])) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'error' => 'Sessão expirada (CSRF inválido). Recarregue a página.']);
                exit;
            }
            die("CSRF Inválido.");
        }
        $id_post = filter_input(INPUT_POST, 'id_termo', FILTER_VALIDATE_INT);
        $id_cliente = $_POST['id_cliente'] ?? null;
        $id_v = !empty($_POST['id_visita']) ? $_POST['id_visita'] : null;
        $id_o = !empty($_POST['id_obra']) ? $_POST['id_obra'] : null;
        // 1. Processar a Tabela de Equipamentos (Hardware)
        $equipamentos_knx = [];
        if (isset($_POST['knx_fisico']) && is_array($_POST['knx_fisico'])) {
            for ($i = 0; $i < count($_POST['knx_fisico']); $i++) {
                $fisico = trim($_POST['knx_fisico'][$i] ?? '');
                $marca = trim($_POST['knx_marca'][$i] ?? '');
                $ref = trim($_POST['knx_ref'][$i] ?? '');
                $tipo = trim($_POST['knx_tipo'][$i] ?? '');
                $localizacao = trim($_POST['knx_localizacao'][$i] ?? '');

                if ($fisico !== '' || $marca !== '' || $ref !== '' || $tipo !== '') {
                    $equipamentos_knx[] = [
                        'fisico' => $fisico,
                        'marca' => $marca,
                        'ref' => $ref,
                        'tipo' => $tipo,
                        'localizacao' => $localizacao
                    ];
                }
            }
        }
        $dados_json_gravar = safe_json_encode($equipamentos_knx);
        // 2. Processar a Tabela de Grupos e Associações (Vem do hidden input construído pelo JS)
        $grupos_json_gravar = $_POST['grupos_json_input'] ?? '[]';
        // Descodifica e volta a codificar para garantir sanidade do JSON
        $grupos_arr = json_decode($grupos_json_gravar, true);
        if(!is_array($grupos_arr)) $grupos_arr = [];
        $grupos_json_gravar = safe_json_encode($grupos_arr);
        // 3. Notas (opcional)
        $notas_gravar = trim($_POST['notas'] ?? '');
        if ($notas_gravar === '') $notas_gravar = null;
        $dados = [
            $id_cliente, $id_v, $id_o, $_POST['data_inspecao'],
            $_POST['tecnico_nome'], $_POST['tecnico_n_inscricao'],
            $dados_json_gravar, $grupos_json_gravar, $notas_gravar, date('Y-m-d')
        ];
        try {
            if ($id_post && !isset($_GET['duplicar'])) {
                // UPDATE
                $sql = "UPDATE termos_knx SET id_cliente=?, id_visita=?, id_obra=?, data_inspecao=?, tecnico_nome=?, tecnico_n_inscricao=?, dados_json=?, grupos_json=?, notas=?, data_emissao=? WHERE id=?";
                $dados[] = $id_post;
                $pdo->prepare($sql)->execute($dados);
                $id_final = $id_post;
            } else {
                // NUMERADOR TRANSVERSAL (ultimo_numero_projeto)
                $numero_relatorio = $_POST['numero_relatorio'];

                $sql = "INSERT INTO termos_knx (numero_relatorio, id_cliente, id_visita, id_obra, data_inspecao, tecnico_nome, tecnico_n_inscricao, dados_json, grupos_json, notas, data_emissao) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                array_unshift($dados, $numero_relatorio);

                $pdo->prepare($sql)->execute($dados);
                $id_final = $pdo->lastInsertId();
                $stmt_curr = $pdo->query("SELECT valor FROM configuracoes WHERE chave = 'ultimo_numero_projeto'");
                $curr_val = $stmt_curr->fetchColumn();
                if ((int)$numero_relatorio > (int)$curr_val) {
                    $stmt_seq = $pdo->prepare("INSERT INTO configuracoes (chave, valor) VALUES ('ultimo_numero_projeto', ?) ON DUPLICATE KEY UPDATE valor = ?");
                    $stmt_seq->execute([$numero_relatorio, $numero_relatorio]);
                }
            }

            registrarLog($pdo, "Gravou Relatório KNX #" . $id_final, "Termos KNX");

            if (isset($_POST['imprimir_pdf'])) {
                // Botão "Gravar & Imprimir" continua a navegar diretamente para o PDF
                header("Location: termos_knx_pdf.php?id=" . $id_final);
                exit;
            }
            if (isset($_POST['via_ajax'])) {
                // Gravação normal via AJAX: devolve JSON em vez de redirecionar,
                // para o JS poder perguntar ao utilizador o que fazer a seguir
                header('Content-Type: application/json');
                echo json_encode(['success' => true, 'id' => $id_final]);
                exit;
            }
            // Fallback (sem JS / submissão nativa do formulário)
            header("Location: termos_knx.php?sucesso=1");
            exit;
        } catch (Exception $e) {
            if (isset($_POST['via_ajax'])) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'error' => $e->getMessage()]);
                exit;
            }
            $mensagem = "Erro: " . $e->getMessage(); $tipo_mensagem = "erro";
        }
    }
    // --- CARREGAR DADOS ---
    $registo = [];
    $equipamentos_view = [];
    $grupos_view = [];
    $numero_relatorio_display = "";
    $titulo_pagina = "Termo de Domótica (KNX)";
    if (!$id_termo) {
        $ano_atual_curto = date('y');
        $stmt_ref = $pdo->query("SELECT valor FROM configuracoes WHERE chave = 'ultimo_numero_projeto'");
        $ultima_ref = $stmt_ref->fetchColumn();

        if ($ultima_ref && substr($ultima_ref, 0, 2) === $ano_atual_curto) {
            $numero_relatorio_display = (string)((int)$ultima_ref + 1);
            if ((int)$numero_relatorio_display < (int)($ano_atual_curto . "0100")) {
                $numero_relatorio_display = $ano_atual_curto . "0100";
            }
        } else {
            $numero_relatorio_display = $ano_atual_curto . "0100";
        }
        $titulo_pagina = "Novo Termo de Domótica (KNX)";
        $registo = [
            'data_inspecao' => date('Y-m-d'),
            'tecnico_nome' => 'Mário Abreu',
            'tecnico_n_inscricao' => '2183',
            'id_visita' => '',
            'id_obra' => '',
            'id_cliente' => ''
        ];
        if ($import_visita_id) {
            $stmt_v = $pdo->prepare("SELECT v.*, c.id as cid FROM visitas v LEFT JOIN clientes c ON v.id_cliente = c.id WHERE v.id = ?");
            $stmt_v->execute([$import_visita_id]);
            if ($v = $stmt_v->fetch(PDO::FETCH_ASSOC)) {
                $numero_relatorio_display = !empty($v['referencia_visita']) ? $v['referencia_visita'] : $numero_relatorio_display;
                $registo['id_cliente'] = $v['cid'];
                $registo['id_visita'] = $v['id'];
                $mensagem = "Dados importados da Visita Técnica."; $tipo_mensagem = "sucesso";
            }
        } elseif ($import_obra_id) {
            $stmt_o = $pdo->prepare("SELECT o.*, c.id as cid FROM obras o LEFT JOIN clientes c ON o.id_cliente = c.id WHERE o.id = ?");
            $stmt_o->execute([$import_obra_id]);
            if ($o = $stmt_o->fetch(PDO::FETCH_ASSOC)) {
                $numero_relatorio_display = !empty($o['referencia_obra']) ? $o['referencia_obra'] : $numero_relatorio_display;
                $registo['id_cliente'] = $o['cid'];
                $registo['id_obra'] = $o['id'];
                $mensagem = "Dados importados da Obra."; $tipo_mensagem = "sucesso";
            }
        }
    } else {
        $stmt_r = $pdo->prepare("SELECT * FROM termos_knx WHERE id = ?");
        $stmt_r->execute([$id_termo]);
        $registo = $stmt_r->fetch(PDO::FETCH_ASSOC);

        if ($registo) {
            // CORRIGIDO: leitura robusta do JSON (não perde os dados se houver acentos/carateres estranhos)
            $equipamentos_view = decodificarJsonSeguro($registo['dados_json'] ?? '');

            // Suporte para tabelas legadas ou com a nova coluna
            if (array_key_exists('grupos_json', $registo)) {
                $grupos_view = decodificarJsonSeguro($registo['grupos_json'] ?? '');
            }

            if (isset($_GET['duplicar'])) {
                $registo['id'] = '';
                $registo['data_inspecao'] = date('Y-m-d');
                $titulo_pagina = "Novo Termo KNX (Duplicado)";

                $ano_atual_curto = date('y');
                $stmt_ref = $pdo->query("SELECT valor FROM configuracoes WHERE chave = 'ultimo_numero_projeto'");
                $ultima_ref = $stmt_ref->fetchColumn();
                $numero_relatorio_display = (string)((int)$ultima_ref + 1);
            } else {
                $titulo_pagina = "Editar Termo KNX";
                $numero_relatorio_display = $registo['numero_relatorio'];
            }
        } else {
            $registo = [];
            $mensagem = "Registo não encontrado."; $tipo_mensagem = "erro";
        }
    }
    $clientes = $pdo->query("SELECT id, nome FROM clientes ORDER BY nome ASC")->fetchAll();
    $visitas_import = []; $obras_import = [];
    if (!$modo_editar) {
        $visitas_import = $pdo->query("SELECT v.id, v.id_cliente, v.referencia_visita, v.assunto, c.nome as cliente_nome FROM visitas v LEFT JOIN clientes c ON v.id_cliente = c.id WHERE v.referencia_visita IS NOT NULL AND v.referencia_visita != '' ORDER BY v.data_visita DESC LIMIT 300")->fetchAll(PDO::FETCH_ASSOC);
        $obras_import = $pdo->query("SELECT o.id, o.id_cliente, o.referencia_obra, o.nome, c.nome as cliente_nome FROM obras o LEFT JOIN clientes c ON o.id_cliente = c.id WHERE o.referencia_obra IS NOT NULL AND o.referencia_obra != '' ORDER BY o.id DESC LIMIT 300")->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Throwable $e) {
    // Se o erro acontecer durante um pedido AJAX, devolve JSON em vez de HTML
    // (evita que o fetch() do JS fique "pendurado" sem perceber o que correu mal)
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['via_ajax']) || isset($_POST['ajax_action']))) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        exit;
    }
    die("<div style='padding:20px; color:red;'>Erro: " . htmlspecialchars($e->getMessage()) . "</div>");
}
?>
<!DOCTYPE html>
<html lang="pt-PT">
<head>
    <meta charset="UTF-8">
    <title><?= htmlspecialchars($titulo_pagina) ?> - MAB</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script>
        // DIAGNÓSTICO: se algum erro de JavaScript impedir a página de carregar os dados,
        // mostra-o numa faixa vermelha no topo (em vez de a página ficar "vazia" sem explicação)
        window.addEventListener('error', function(ev) {
            try {
                var msg = (ev && ev.message ? ev.message : 'Erro desconhecido') + (ev && ev.lineno ? ' (linha ' + ev.lineno + ')' : '');
                var mostrar = function() {
                    var d = document.getElementById('jsErroBanner');
                    if (!d) {
                        d = document.createElement('div');
                        d.id = 'jsErroBanner';
                        d.style.cssText = 'background:#fdeded; color:#c0392b; border:1px solid #f5b7b1; padding:12px 15px; margin:15px; border-radius:8px; font-weight:bold; font-family:system-ui, sans-serif; font-size:13px;';
                        document.body.insertBefore(d, document.body.firstChild);
                    }
                    d.textContent = 'Erro de JavaScript: ' + msg;
                };
                if (document.body) mostrar(); else document.addEventListener('DOMContentLoaded', mostrar);
            } catch (e) {}
        });
    </script>
    <style>
        body { font-family: system-ui, sans-serif; background: #f7f9fc; margin: 0; font-size: 13px; padding-bottom: 80px; }
        * { box-sizing: border-box; }

        .topbar { background: #fff; padding: 15px 40px; box-shadow: 0 4px 20px rgba(0,0,0,0.05); display: flex; justify-content: space-between; align-items: center; position: sticky; top: 0; z-index: 100;}
        .topbar-left .logo { height: 40px; }
        .btn-back { color: #576574; text-decoration: none; font-weight: 500; display: flex; align-items: center; gap: 6px; font-size: 15px; transition: 0.2s;}
        .btn-back:hover { color: #34495e; }

        .container { max-width: 1200px; margin: 30px auto; padding: 0 20px; }
        .card { background: #fff; padding: 25px; border-radius: 12px; border: 1px solid #e1e5ea; margin-bottom: 25px; box-shadow: 0 4px 15px rgba(0,0,0,0.02); }
        .card-title { color: #2c3e50; font-size: 16px; margin: 0 0 20px; padding-bottom: 10px; border-bottom: 2px solid #f1f2f6; display: flex; align-items: center; gap: 10px;}

        .page-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px; }
        .page-title { margin: 0; color: #2c3e50; font-size: 24px; font-weight: 800; display: flex; align-items: center; gap: 12px; }

        .grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
        .form-group { margin-bottom: 15px; }

        label { display: block; font-weight: 700; color: #576574; font-size: 11px; text-transform: uppercase; margin-bottom: 6px; }
        input[type="text"], input[type="date"], select { width: 100%; padding: 10px 12px; border: 1px solid #dcdde1; border-radius: 6px; font-family: inherit; font-size: 13px; transition: 0.2s; }
        input[type="text"]:focus, select:focus { border-color: #3498db; outline: none; box-shadow: 0 0 0 3px rgba(52,152,219,0.1); }

        .input-btn-group { display: flex; gap: 8px; }
        .input-btn-group select { flex: 1; }
        .btn-add { background: #34495e; color: #fff; border: none; border-radius: 6px; width: 42px; cursor: pointer; transition: 0.2s; display: flex; justify-content: center; align-items: center; font-size: 16px; }
        .btn-add:hover { background: #2c3e50; }
        .btn-add-text { width:auto; padding:8px 15px; font-size:13px; font-weight:bold; margin-top:10px; }
        .btn-import-csv { background: #eafaf1; border: 1px dashed #82e0aa; color: #196f3d; font-size:13px; padding:8px 15px; border-radius:6px; cursor:pointer; font-weight:bold; margin-top:10px; margin-left:10px; display:inline-flex; align-items:center; gap:6px; transition:0.2s;}
        .btn-import-csv:hover { background:#d4efdf; border-color:#58d68d; }
        .btn-export-csv { background: #eaf2fb; border: 1px dashed #85c1e9; color: #1f618d; font-size:13px; padding:8px 15px; border-radius:6px; cursor:pointer; font-weight:bold; margin-top:10px; margin-left:10px; display:inline-flex; align-items:center; gap:6px; transition:0.2s;}
        .btn-export-csv:hover { background:#d6eaf8; border-color:#5dade2; }
        .csv-formato-info { display:block; margin-top:8px; font-size:11px; color:#95a5a6; }
        /* Linhas de Grupo Principal (0/-/-) e Grupo Intermédio (0/0/-) vindas do ETS */
        tr.grupo-linha-header.grupo-range > td { background: #f4f6f8; }
        tr.grupo-linha-header.grupo-range .grupo-resumo-endereco { color: #8e44ad; }
        .grupo-resumo-dpt { font-size: 10px; color: #2980b9; background: #eaf2fb; border-radius: 10px; padding: 1px 7px; font-weight: normal; }
        .grupo-resumo-dpt:empty { display: none; }
        /* Botão que mostra/esconde uma secção inteira (Hardware, Atribuir Objetos, Endereços de Grupo) */
        .btn-toggle-seccao { background: #fff8e1; border: 1px solid #f5d76e; color: #8a6d00; font-size: 13px; padding: 7px 14px; border-radius: 6px; cursor: pointer; font-weight: bold; display: inline-flex; align-items: center; gap: 8px; transition: 0.2s; }
        .btn-toggle-seccao:hover { background: #fdebc8; border-color: #f1c40f; }
        .btn-toggle-seccao i { color: #f39c12; font-size: 14px; transition: transform 0.2s; }
        /* Contador de registos dentro do botão Mostrar/Esconder */
        .seccao-count { background: #f39c12; color: #fff; border-radius: 20px; padding: 1px 8px; font-size: 11px; }
        .seccao-count:empty { display: none; }

        /* Tabela KNX (Equipamentos e Grupos) */
        .table-responsive { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; min-width: 800px; }
        th { background: #f8f9fa; padding: 10px; text-align: left; font-size: 11px; color: #7f8c8d; text-transform: uppercase; border: 1px solid #e1e5ea; position: relative;}
        td { padding: 4px; border: 1px solid #e1e5ea; vertical-align: middle; position: relative;}
        td input { border: none; border-radius: 4px; padding: 8px; width: 100%; font-size: 13px; }
        td input:focus { border: 1px solid #3498db; box-shadow: none; background: #f8f9fa; }

        .btn-del-linha { color: #e74c3c; background: none; border: none; font-size: 16px; cursor: pointer; width: 100%; text-align: center; }
        .btn-del-linha:hover { color: #c0392b; }
        /* Movimentação de Linhas */
        .chk-wrapper { display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 4px; padding: 2px 0; }
        .chk-select-row { width: 15px; height: 15px; accent-color: #3498db; cursor: pointer; margin:0;}
        .move-controls { display: none; flex-direction: column; cursor: pointer; color: #cbd5e1; font-size: 13px; gap: 2px; align-items: center; }
        .move-controls i:hover { color: #3498db; }
        /* Linha de Grupo: resumo (sempre visível) + detalhe (endereço, função e associações, escondidos até expandir) */
        .grupo-toggle { display: flex; align-items: center; gap: 8px; font-size: 13px; color: #2c3e50; font-weight: bold; cursor: pointer; user-select: none; padding: 8px 2px; flex-wrap: wrap; }
        .grupo-toggle:hover { color: #2980b9; }
        .grupo-toggle-icon { font-size: 11px; color: #95a5a6; transition: transform 0.2s; flex-shrink: 0; }
        .grupo-resumo-endereco { color: #2c3e50; }
        .grupo-resumo-funcao { color: #7f8c8d; font-weight: normal; }
        .grupo-resumo-count { font-size: 11px; color: #95a5a6; font-weight: normal; }
        tr.grupo-linha-detalhe > td { padding-top: 4px; padding-bottom: 16px; background: #fbfbfc; }
        .grupo-campos-row { display: flex; gap: 15px; flex-wrap: wrap; margin-bottom: 12px; }
        .grupo-campo { flex: 1; min-width: 180px; }
        .grupo-campo label { display: block; font-size: 10px; color: #95a5a6; text-transform: uppercase; letter-spacing: 0.3px; margin-bottom: 4px; }
        .assoc-container { display: flex; flex-direction: column; gap: 5px; padding: 4px; }
        .assoc-item { display: flex; gap: 5px; align-items: center; background: #f8f9fa; border: 1px solid #e1e5ea; padding: 2px; border-radius: 4px;}
        .assoc-item input, .assoc-item select { background: transparent; padding: 6px; font-size: 12px; border: 1px solid transparent; border-radius: 4px; }
        .assoc-item input:focus, .assoc-item select:focus { background: #fff; border-color: #3498db; }
        .assoc-item select { cursor: pointer; }
        .btn-assoc-del { background: none; border: none; color: #e74c3c; cursor: pointer; font-size: 14px; padding: 0 5px; }
        .btn-assoc-add { background: #ecf0f1; border: 1px dashed #bdc3c7; color: #7f8c8d; font-size: 11px; padding: 4px 8px; border-radius: 4px; cursor: pointer; font-weight: bold; align-self: flex-start; margin-top: 2px; transition: 0.2s;}
        .btn-assoc-add:hover { background: #dcdde1; color: #2c3e50; }

        .floating-bar { position: fixed; bottom: 0; left: 0; right: 0; background: #fff; padding: 15px 40px; box-shadow: 0 -5px 20px rgba(0,0,0,0.05); display: flex; justify-content: flex-end; gap: 15px; z-index: 100; border-top: 1px solid #e1e5ea;}
        .btn { padding: 12px 25px; border-radius: 8px; font-weight: bold; cursor: pointer; border: none; font-size: 14px; transition: 0.2s; display: inline-flex; align-items: center; gap: 8px;}
        .btn-cancel { background: #f1f2f6; color: #576574; text-decoration: none; }
        .btn-cancel:hover { background: #e1e5ea; }
        .btn-save { background: #34495e; color: #fff; }
        .btn-save:hover { background: #2c3e50; }
        .btn-pdf { background: #d35400; color: #fff; }
        .btn-pdf:hover { background: #e67e22; }
        .btn-acao-header { background: #fff; color: #576574; border: 1px solid #dcdde1; padding: 9px 18px; border-radius: 30px; text-decoration: none; font-weight: 600; font-size: 13px; display: inline-flex; align-items: center; gap: 8px; transition: all 0.2s; cursor: pointer; box-shadow: 0 1px 2px rgba(0,0,0,0.02);}
        .btn-acao-header-import { color: #8b5cf6; border-color: #ddd6fe; background: #f5f3ff; }
        .btn-acao-header-import:hover { background: #ede9fe; color: #7c3aed; border-color: #c4b5fd;}
        .modal { display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.6); z-index:10000; align-items:center; justify-content:center; backdrop-filter: blur(2px); }
        .modal-content { background:#fff; padding:30px; border-radius:15px; width:90%; max-width:500px; box-shadow: 0 20px 50px rgba(0,0,0,0.2); position:relative;}
        .modal-btns { display: flex; gap: 10px; margin-top: 20px;}
        .modal-btns button { flex: 1; padding: 12px; border: none; border-radius: 8px; font-weight: 600; cursor: pointer; transition:0.2s;}
        .close-btn { color: #94a3b8; font-size: 24px; cursor: pointer; font-weight: bold; background: none; border: none; position: absolute; right: 20px; top: 20px;}
    </style>
</head>
<body>
    <header class="topbar">
        <div class="topbar-left"><img src="mab.png" alt="MAB" class="logo"></div>
        <div class="topbar-right">
            <a href="termos_knx.php" class="btn-back"><i class="fa-solid fa-arrow-left"></i> Voltar</a>
        </div>
    </header>
    <div class="container">

        <?php if ($mensagem): ?>
            <div style="<?= $tipo_mensagem === 'erro' ? 'background:#fdeded; color:#c0392b; border:1px solid #f5b7b1;' : 'background:#eafaf1; color:#27ae60; border:1px solid #d5f5e3;' ?> padding:15px; border-radius:8px; font-weight:bold; margin-bottom:20px; text-align:center;">
                <?= htmlspecialchars($mensagem) ?>
            </div>
        <?php endif; ?>
        <div class="page-header">
            <h1 class="page-title"><i class="fa-solid fa-file-signature"></i> <?= htmlspecialchars($titulo_pagina) ?></h1>
            <div class="header-actions">
                <?php if (!$modo_editar): ?>
                    <button type="button" class="btn-acao-header btn-acao-header-import" onclick="abrirModalImportar()"><i class="fa-solid fa-link"></i> Herdar Visita / Obra</button>
                <?php endif; ?>
            </div>
        </div>
        <form method="POST" action="" id="formRelatorio">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
            <input type="hidden" name="id_termo" value="<?= htmlspecialchars($registo['id'] ?? '') ?>">
            <input type="hidden" name="id_visita" value="<?= htmlspecialchars($registo['id_visita'] ?? '') ?>">
            <input type="hidden" name="id_obra" value="<?= htmlspecialchars($registo['id_obra'] ?? '') ?>">
            <!-- Necessário para o botão "Gravar & Imprimir" (submissão nativa do formulário):
                 sem isto, o PHP nunca entra no bloco de gravação e o botão não fazia nada. -->
            <input type="hidden" name="btn_guardar" value="1">
            <input type="hidden" name="grupos_json_input" id="grupos_json_input" value="">
            <div class="card">
                <div class="grid-2">
                    <div class="form-group">
                        <label>Ref. Transversal / Nº Relatório</label>
                        <input type="text" name="numero_relatorio" value="<?= htmlspecialchars($numero_relatorio_display) ?>" required <?= $modo_editar && !isset($_GET['duplicar']) ? 'readonly style="background:#f1f2f6; font-weight:bold;"' : 'style="font-weight:bold; color:#d35400;"' ?>>
                    </div>
                    <div class="form-group">
                        <label>Data de Inspeção/Registo</label>
                        <input type="date" name="data_inspecao" value="<?= htmlspecialchars($registo['data_inspecao'] ?? '') ?>" required>
                    </div>
                </div>
                <div class="form-group">
                    <label>Cliente / Entidade</label>
                    <div class="input-btn-group">
                        <select name="id_cliente" id="select_cliente" required>
                            <option value="">Selecione...</option>
                            <?php foreach($clientes as $c): ?>
                                <option value="<?= $c['id'] ?>" <?= ($registo['id_cliente'] ?? '') == $c['id'] ? 'selected' : '' ?>><?= htmlspecialchars($c['nome']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button type="button" class="btn-add" onclick="abrirModalCliente()" title="Criar Novo Cliente"><i class="fa-solid fa-plus"></i></button>
                    </div>
                </div>
            </div>
            <!-- TABELA TÉCNICA: EQUIPAMENTOS (HARDWARE) -->
            <div class="card">
                <h2 class="card-title" style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:10px;">
                    <span><i class="fa-solid fa-server"></i> Hardware / Módulos Físicos KNX</span>
                    <button type="button" id="btnToggleTabelaEquip" class="btn-toggle-seccao" onclick="toggleTabelaEquip()">
                        <i class="fa-solid fa-caret-down" id="iconToggleTabelaEquip"></i>
                        <span id="labelToggleTabelaEquip">Mostrar Hardware / Módulos Físicos</span>
                        <span class="seccao-count" id="countTabelaEquip"></span>
                    </button>
                </h2>
                <div id="containerTabelaEquip" style="display:none;">
                    <div class="table-responsive">
                        <table id="tabela_equip">
                            <thead>
                                <tr>
                                    <th style="width:40px; text-align:center;"><input type="checkbox" onchange="toggleAllRows('tabela_equip', this)"></th>
                                    <th style="width:15%;">End. Físico</th>
                                    <th style="width:20%;">Marca / Fabricante</th>
                                    <th style="width:20%;">Referência</th>
                                    <th style="width:20%;">Tipo de Equipamento</th>
                                    <th style="width:20%;">Localização</th>
                                    <th style="width:40px;"></th>
                                </tr>
                            </thead>
                            <tbody>
                                <!-- INSERÇÃO VIA JS -->
                            </tbody>
                        </table>
                    </div>
                </div>
                <button type="button" class="btn-add btn-add-text" onclick="mostrarTabelaEquip(); addLinhaEquip();"><i class="fa-solid fa-plus"></i> Adicionar Módulo Físico</button>
            </div>
            <!-- ATRIBUIR OBJETOS A ENDEREÇOS DE GRUPO (a partir do Endereço Físico) -->
            <div class="card">
                <h2 class="card-title" style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:10px;">
                    <span><i class="fa-solid fa-right-left"></i> Atribuir Objetos a Endereços de Grupo</span>
                    <button type="button" id="btnToggleListaFisicosAssociar" class="btn-toggle-seccao" onclick="toggleListaFisicosAssociar()">
                        <i class="fa-solid fa-caret-down" id="iconToggleListaFisicosAssociar"></i>
                        <span id="labelToggleListaFisicosAssociar">Mostrar Atribuição de Objetos</span>
                    </button>
                </h2>
                <p style="color:#95a5a6; font-size:12px; margin:-10px 0 15px;">Escolha um Endereço Físico da lista para lhe associar um Objeto e um Endereço de Grupo (novo ou já existente). A tabela abaixo atualiza-se automaticamente.</p>
                <div id="containerListaFisicosAssociar" style="display:none;">
                    <div id="listaFisicosAssociar" style="display:flex; flex-direction:column; gap:8px;"></div>
                </div>
            </div>
            <!-- TABELA TÉCNICA: ENDEREÇOS DE GRUPO E ASSOCIAÇÕES -->
            <div class="card">
                <h2 class="card-title" style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:10px;">
                    <span><i class="fa-solid fa-network-wired"></i> Endereços de Grupo e Associações de Objetos</span>
                    <button type="button" id="btnToggleTabelaGrupos" class="btn-toggle-seccao" onclick="toggleTabelaGrupos()">
                        <i class="fa-solid fa-caret-down" id="iconToggleTabelaGrupos"></i>
                        <span id="labelToggleTabelaGrupos">Mostrar Endereços de Grupo</span>
                        <span class="seccao-count" id="countTabelaGrupos"></span>
                    </button>
                </h2>
                <div id="containerTabelaGrupos" style="display:none;">
                    <div class="table-responsive">
                        <table id="tabela_grupos">
                            <thead>
                                <tr>
                                    <th style="width:40px; text-align:center;"><input type="checkbox" onchange="toggleAllRows('tabela_grupos', this)"></th>
                                    <th style="width:20%;">End. de Grupo</th>
                                    <th style="width:30%;">Descrição / Função</th>
                                    <th style="width:40%;">Associações (Endereços Físicos + Objeto)</th>
                                    <th style="width:40px;"></th>
                                </tr>
                            </thead>
                            <tbody>
                                <!-- INSERÇÃO VIA JS -->
                            </tbody>
                        </table>
                    </div>
                </div>
                <button type="button" class="btn-add btn-add-text" onclick="mostrarTabelaGrupos(); addLinhaGrupo({});"><i class="fa-solid fa-plus"></i> Adicionar Endereço de Grupo</button>
                <button type="button" class="btn-import-csv" onclick="document.getElementById('inputCsvGrupos').click()" title="Importar do ETS: CSV | 1/1 Nome/Endereço | Ponto e vírgula"><i class="fa-solid fa-file-import"></i> Importar CSV</button>
                <button type="button" class="btn-export-csv" onclick="exportarCsvGruposEts()" title="Exportar para o ETS: CSV | 1/1 Nome/Endereço | Ponto e vírgula"><i class="fa-solid fa-file-export"></i> Exportar CSV</button>
                <span class="csv-formato-info"><i class="fa-solid fa-circle-info"></i> CSV | 1/1 Nome/Endereço | Ponto e vírgula</span>
                <input type="file" id="inputCsvGrupos" accept=".csv" style="display:none;" onchange="handleCsvGruposSelecionado(event)">
            </div>
            <!-- NOTAS / OBSERVAÇÕES -->
            <div class="card">
                <h2 class="card-title"><i class="fa-solid fa-pen-clip"></i> Notas / Observações</h2>
                <textarea name="notas" id="input_notas" rows="6" placeholder="Apontamentos sobre a instalação, programação, pendentes, observações para o cliente..." style="width:100%; padding:10px 12px; border:1px solid #dcdde1; border-radius:6px; font-family:inherit; font-size:13px; resize:vertical;"><?= htmlspecialchars($registo['notas'] ?? '') ?></textarea>
            </div>
            <div class="card">
                <h2 class="card-title"><i class="fa-solid fa-user-tie"></i> Responsabilidade Técnica</h2>
                <div class="grid-2">
                    <div class="form-group">
                        <label>Nome do Técnico Responsável</label>
                        <input type="text" name="tecnico_nome" value="<?= htmlspecialchars($registo['tecnico_nome'] ?? $primeiro_nome) ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Nº Inscrição / Registo</label>
                        <input type="text" name="tecnico_n_inscricao" value="<?= htmlspecialchars($registo['tecnico_n_inscricao'] ?? '2183') ?>" required>
                    </div>
                </div>
            </div>
            <div class="floating-bar">
                <a href="termos_knx.php" class="btn btn-cancel">Cancelar</a>
                <button type="button" id="btnGravarPrincipal" class="btn btn-save" onclick="gravarRegistoAjax()"><i class="fa-solid fa-floppy-disk"></i> Gravar Registo KNX</button>
                <?php if ($id_termo && !isset($_GET['duplicar'])): ?>
                <button type="submit" name="imprimir_pdf" class="btn btn-pdf"><i class="fa-solid fa-file-pdf"></i> Gravar & Imprimir</button>
                <?php endif; ?>
            </div>
        </form>
    </div>
    <!-- Modal Novo Cliente -->
    <div class="modal" id="modalNovoCliente">
        <div class="modal-content">
            <h2 style="margin-top:0; color:#2c3e50;"><i class="fa-solid fa-user-plus"></i> Criar Novo Cliente</h2>
            <form id="formNovoCliente" onsubmit="gravarCliente(event)">
                <div class="form-group">
                    <label>Nome do Cliente / Entidade</label>
                    <input type="text" name="nome_cliente" required>
                </div>
                <div class="form-group">
                    <label>NIF</label>
                    <input type="text" name="nif_cliente">
                </div>
                <div class="form-group">
                    <label>Morada Sede</label>
                    <input type="text" name="morada_cliente">
                </div>
                <div class="modal-btns">
                    <button type="button" onclick="document.getElementById('modalNovoCliente').style.display='none'" style="background:#f1f2f6; color:#576574;">Cancelar</button>
                    <button type="submit" style="background:#34495e; color:#fff;">Criar e Usar</button>
                </div>
            </form>
        </div>
    </div>
    <?php if (!$modo_editar): ?>
    <!-- Modal de Importação -->
    <div class="modal" id="modalImportar">
        <div class="modal-content" style="max-width: 550px;">
            <button type="button" class="close-btn" onclick="document.getElementById('modalImportar').style.display='none'">&times;</button>
            <h3 style="margin: 0 0 15px 0; color: #8b5cf6; font-size: 20px; text-align:left;"><i class="fa-solid fa-link"></i> Herdar Referência</h3>
            <div style="text-align: left; margin-bottom: 25px;">
                <label style="font-weight: 700; color: #2c3e50; display: block; margin-bottom: 8px;">A partir de Visita Técnica</label>
                <div style="display: flex; gap: 10px;">
                    <select id="select_import_visita" style="flex: 1; padding: 12px; border-radius: 8px; border: 1px solid #cbd5e1; background:#fff;">
                        <option value="">-- Selecione uma Visita --</option>
                        <?php foreach($visitas_import as $v): ?>
                            <option value="<?= $v['id'] ?>">REF: <?= htmlspecialchars($v['referencia_visita']) ?> - <?= htmlspecialchars($v['cliente_nome'] ?? '') ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button type="button" style="background: #8b5cf6; color:#fff; border:none; border-radius:8px; padding:0 20px; cursor:pointer;" onclick="executarImportacao('visita')">Importar</button>
                </div>
            </div>
            <div style="text-align: left; border-top: 1px dashed #cbd5e1; padding-top: 20px;">
                <label style="font-weight: 700; color: #2c3e50; display: block; margin-bottom: 8px;">A partir de Obra Existente</label>
                <div style="display: flex; gap: 10px;">
                    <select id="select_import_obra" style="flex: 1; padding: 12px; border-radius: 8px; border: 1px solid #cbd5e1; background:#fff;">
                        <option value="">-- Selecione uma Obra --</option>
                        <?php foreach($obras_import as $o): ?>
                            <option value="<?= $o['id'] ?>">REF: <?= htmlspecialchars($o['referencia_obra']) ?> - <?= htmlspecialchars($o['cliente_nome'] ?? '') ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button type="button" style="background: #8b5cf6; color:#fff; border:none; border-radius:8px; padding:0 20px; cursor:pointer;" onclick="executarImportacao('obra')">Importar</button>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>
    <!-- Modal: Associar Objeto + Endereço de Grupo a um Endereço Físico -->
    <div class="modal" id="modalAssociarObjeto">
        <div class="modal-content" style="max-width:450px;">
            <button type="button" class="close-btn" onclick="fecharModalAssociarObjeto()">&times;</button>
            <h2 style="margin-top:0; color:#2c3e50;"><i class="fa-solid fa-right-left"></i> Associar Endereço de Grupo</h2>
            <p style="color:#576574; margin-top:-8px;">Endereço Físico: <strong id="modalAssocFisicoLabel" style="color:#2c3e50;"></strong></p>
            <div class="form-group">
                <label>Objeto</label>
                <select id="modalAssocObjeto"></select>
            </div>
            <div class="form-group">
                <label>Endereço de Grupo</label>
                <input type="text" id="modalAssocGrupo" list="datalistEnderecosGrupo" placeholder="Escolha da lista ou escreva um novo (ex: 0/0/12)">
                <datalist id="datalistEnderecosGrupo"></datalist>
            </div>
            <div id="modalAssocListaExistente" style="margin:12px 0;"></div>
            <div class="modal-btns">
                <button type="button" onclick="fecharModalAssociarObjeto()" style="background:#f1f2f6; color:#576574;">Fechar</button>
                <button type="button" onclick="confirmarAssociarObjeto()" style="background:#34495e; color:#fff;"><i class="fa-solid fa-plus"></i> Associar</button>
            </div>
        </div>
    </div>
    <!-- Modal Importar CSV (Endereços de Grupo) -->
    <div class="modal" id="modalImportarCsvGrupos">
        <div class="modal-content" style="max-width:420px; text-align:center;">
            <div style="font-size:40px; color:#27ae60; margin-bottom:10px;"><i class="fa-solid fa-file-csv"></i></div>
            <h2 style="margin:0 0 8px; color:#2c3e50;">Importar Endereços de Grupo</h2>
            <p id="msgImportarCsvGrupos" style="color:#576574; margin:0 0 25px;"></p>
            <div class="modal-btns">
                <button type="button" onclick="cancelarImportacaoCsvGrupos()" style="background:#f1f2f6; color:#2c3e50;">Cancelar</button>
                <button type="button" onclick="confirmarImportacaoCsvGrupos()" style="background:#34495e; color:#fff;"><i class="fa-solid fa-file-import"></i> Importar</button>
            </div>
        </div>
    </div>
    <!-- Modal Pós-Gravação -->
    <div class="modal" id="modalPosGravar">
        <div class="modal-content" style="text-align:center; max-width:420px;">
            <div style="font-size:50px; color:#27ae60; margin-bottom:10px;"><i class="fa-solid fa-circle-check"></i></div>
            <h2 style="margin:0 0 8px; color:#2c3e50;">Registo Guardado!</h2>
            <p style="color:#576574; margin:0 0 25px;">O que pretende fazer a seguir?</p>
            <div class="modal-btns">
                <button type="button" onclick="posGravarContinuar()" style="background:#f1f2f6; color:#2c3e50;"><i class="fa-solid fa-pen"></i> Continuar Aqui</button>
                <button type="button" onclick="posGravarMenu()" style="background:#34495e; color:#fff;"><i class="fa-solid fa-house"></i> Voltar ao Menu</button>
            </div>
        </div>
    </div>
    <script>
        // DADOS VINDOS DA BASE DE DADOS (escritos de forma segura, nunca partem o JavaScript)
        const DB_EQUIP = <?= jsonParaJs(array_values($equipamentos_view)) ?>;
        const DB_GRUPOS = <?= jsonParaJs(array_values($grupos_view)) ?>;

        // CLIENTES AJAX
        function abrirModalCliente() { document.getElementById('modalNovoCliente').style.display = 'flex'; }
        function gravarCliente(e) {
            e.preventDefault();
            let fd = new FormData(document.getElementById('formNovoCliente'));
            fd.append('ajax_action', 'novo_cliente');
            fetch('', { method: 'POST', body: fd }).then(res => res.json()).then(data => {
                if(data.success) {
                    let select = document.getElementById('select_cliente');
                    let opt = new Option(data.nome, data.id);
                    select.add(opt); select.value = data.id;
                    document.getElementById('modalNovoCliente').style.display = 'none';
                    document.getElementById('formNovoCliente').reset();
                }
            });
        }
        // IMPORTAÇÃO
        function abrirModalImportar() { document.getElementById('modalImportar').style.display = 'flex'; }
        function executarImportacao(tipo) {
            const selectId = tipo === 'visita' ? 'select_import_visita' : 'select_import_obra';
            const id = document.getElementById(selectId).value;
            if (!id) { alert("Selecione um registo válido."); return; }
            window.location.href = 'termos_knx_form.php?import_' + tipo + '=' + id;
        }
        // ==========================================
        // LÓGICA DAS TABELAS (EQUIPAMENTOS E GRUPOS)
        // ==========================================
        // CORRIGIDO: aceita números, null ou undefined (antes rebentava se o valor não fosse texto)
        function escapeHTML(str) {
            str = (str === null || str === undefined) ? '' : String(str);
            return str.replace(/[&<>'"]/g, tag => ({
                '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#39;', '"': '&quot;'
            }[tag]));
        }
        // 1. Tabela de Equipamentos Físicos
        function addLinhaEquip(data = {}) {
            data = data || {};
            let html = `<tr>
                <td class="col-chk">
                    <div class="chk-wrapper">
                        <input type="checkbox" class="chk-select-row" onchange="updateMoveArrows('tabela_equip')">
                        <div class="move-controls">
                            <i class="fa-solid fa-chevron-up" onclick="moveSelectedRows('tabela_equip', 'up')"></i>
                            <i class="fa-solid fa-chevron-down" onclick="moveSelectedRows('tabela_equip', 'down')"></i>
                            <i class="fa-solid fa-copy" onclick="duplicateSelectedRows('tabela_equip')"></i>
                        </div>
                    </div>
                </td>
                <td><input type="text" name="knx_fisico[]" placeholder="1.1.X" value="${escapeHTML(data.fisico)}"></td>
                <td><input type="text" name="knx_marca[]" placeholder="Ex: Gira" value="${escapeHTML(data.marca)}"></td>
                <td><input type="text" name="knx_ref[]" placeholder="Ex: 216800" value="${escapeHTML(data.ref)}"></td>
                <td><input type="text" name="knx_tipo[]" placeholder="Ex: Atuador 8 canais" value="${escapeHTML(data.tipo)}"></td>
                <td><input type="text" name="knx_localizacao[]" placeholder="Ex: Quadro QG" value="${escapeHTML(data.localizacao)}"></td>
                <td><button type="button" onclick="this.closest('tr').remove()" class="btn-del-linha"><i class="fa-solid fa-circle-xmark"></i></button></td>
            </tr>`;
            document.querySelector('#tabela_equip tbody').insertAdjacentHTML('beforeend', html);
        }
        // 2. Tabela de Grupos e Associações
        // Cada Endereço de Grupo ocupa DUAS linhas <tr>: uma de "resumo" (sempre visível,
        // compacta) e outra de "detalhe" (Endereço, Função e Associações, escondida até
        // se clicar no resumo para expandir). Todas começam sempre minimizadas — há
        // normalmente muitas, e assim não sobrecarregam o ecrã.
        function addLinhaGrupo(data = {}) {
            data = data || {};
            let assocHtml = '';
            if (Array.isArray(data.associacoes) && data.associacoes.length > 0) {
                data.associacoes.forEach(asc => {
                    asc = asc || {};
                    // Compatibilidade com relatórios gravados antes da chave passar de "saida" para "objeto"
                    assocHtml += renderAssocItem(asc.fisico, asc.objeto !== undefined ? asc.objeto : asc.saida);
                });
            } else {
                assocHtml += renderAssocItem('', '');
            }
            let html = `<tr class="grupo-linha-header">
                <td class="col-chk">
                    <div class="chk-wrapper">
                        <input type="checkbox" class="chk-select-row" onchange="updateMoveArrows('tabela_grupos')">
                        <div class="move-controls">
                            <i class="fa-solid fa-chevron-up" onclick="moveSelectedRows('tabela_grupos', 'up')"></i>
                            <i class="fa-solid fa-chevron-down" onclick="moveSelectedRows('tabela_grupos', 'down')"></i>
                            <i class="fa-solid fa-copy" onclick="duplicateSelectedRows('tabela_grupos')"></i>
                        </div>
                    </div>
                </td>
                <td colspan="3">
                    <div class="grupo-toggle" onclick="toggleGrupoLinha(this)">
                        <i class="fa-solid fa-chevron-right grupo-toggle-icon"></i>
                        <span class="grupo-resumo-endereco"></span>
                        <span class="grupo-resumo-funcao"></span>
                        <span class="grupo-resumo-dpt"></span>
                        <span class="grupo-resumo-count"></span>
                    </div>
                </td>
                <td><button type="button" onclick="removerLinhaGrupo(this)" class="btn-del-linha"><i class="fa-solid fa-circle-xmark"></i></button></td>
            </tr>
            <tr class="grupo-linha-detalhe" style="display:none;">
                <td></td>
                <td colspan="3">
                    <div class="grupo-campos-row">
                        <div class="grupo-campo">
                            <label>End. de Grupo</label>
                            <input type="text" class="grupo-endereco" placeholder="0/0/X" value="${escapeHTML(data.endereco)}">
                        </div>
                        <div class="grupo-campo" style="flex:2;">
                            <label>Descrição / Função</label>
                            <input type="text" class="grupo-funcao" placeholder="Luz Teto Sala" value="${escapeHTML(data.funcao)}">
                        </div>
                        <div class="grupo-campo">
                            <label>Tipo de Dados (DPT)</label>
                            <input type="text" class="grupo-dpt" placeholder="Ex: DPST-1-1" value="${escapeHTML(data.dpt)}">
                        </div>
                    </div>
                    <input type="hidden" class="grupo-ets-extra" value="${escapeHTML(JSON.stringify(normalizarExtraEts(data.ets)))}">
                    <div class="assoc-container">${assocHtml}</div>
                    <button type="button" class="btn-assoc-add" onclick="addNovaAssoc(this)"><i class="fa-solid fa-plus"></i> Ligar a Físico/Objeto</button>
                </td>
                <td></td>
            </tr>`;
            document.querySelector('#tabela_grupos tbody').insertAdjacentHTML('beforeend', html);
            if (!aCarregarInicial) { atualizarContadoresAssoc(); }
        }
        // Mostra/esconde uma secção inteira (fica sempre escondida ao abrir a página,
        // para não sobrecarregar o ecrã em registos com muitos endereços/equipamentos)
        function toggleSeccao(idContainer, idIcon, idLabel, textoMostrar, textoEsconder) {
            const escondido = document.getElementById(idContainer).style.display === 'none';
            if (escondido) { mostrarSeccao(idContainer, idIcon, idLabel, textoEsconder); }
            else { esconderSeccao(idContainer, idIcon, idLabel, textoMostrar); }
        }
        function mostrarSeccao(idContainer, idIcon, idLabel, textoEsconder) {
            document.getElementById(idContainer).style.display = 'block';
            document.getElementById(idIcon).className = 'fa-solid fa-caret-up';
            document.getElementById(idLabel).textContent = textoEsconder;
        }
        function esconderSeccao(idContainer, idIcon, idLabel, textoMostrar) {
            document.getElementById(idContainer).style.display = 'none';
            document.getElementById(idIcon).className = 'fa-solid fa-caret-down';
            document.getElementById(idLabel).textContent = textoMostrar;
        }
        function toggleTabelaGrupos() { toggleSeccao('containerTabelaGrupos', 'iconToggleTabelaGrupos', 'labelToggleTabelaGrupos', 'Mostrar Endereços de Grupo', 'Esconder Endereços de Grupo'); }
        function mostrarTabelaGrupos() { mostrarSeccao('containerTabelaGrupos', 'iconToggleTabelaGrupos', 'labelToggleTabelaGrupos', 'Esconder Endereços de Grupo'); }
        function toggleTabelaEquip() { toggleSeccao('containerTabelaEquip', 'iconToggleTabelaEquip', 'labelToggleTabelaEquip', 'Mostrar Hardware / Módulos Físicos', 'Esconder Hardware / Módulos Físicos'); }
        function mostrarTabelaEquip() { mostrarSeccao('containerTabelaEquip', 'iconToggleTabelaEquip', 'labelToggleTabelaEquip', 'Esconder Hardware / Módulos Físicos'); }
        function toggleListaFisicosAssociar() { toggleSeccao('containerListaFisicosAssociar', 'iconToggleListaFisicosAssociar', 'labelToggleListaFisicosAssociar', 'Mostrar Atribuição de Objetos', 'Esconder Atribuição de Objetos'); }
        function mostrarListaFisicosAssociar() { mostrarSeccao('containerListaFisicosAssociar', 'iconToggleListaFisicosAssociar', 'labelToggleListaFisicosAssociar', 'Esconder Atribuição de Objetos'); }

        // NOVO: Mostra no botão "Mostrar ..." quantos registos existem lá dentro
        function atualizarContagensSeccoes() {
            const nEquip = document.querySelectorAll('#tabela_equip tbody tr').length;
            const nGrupos = document.querySelectorAll('#tabela_grupos tbody tr.grupo-linha-header').length;
            const cE = document.getElementById('countTabelaEquip');
            const cG = document.getElementById('countTabelaGrupos');
            const tE = nEquip > 0 ? String(nEquip) : '';
            const tG = nGrupos > 0 ? String(nGrupos) : '';
            if (cE && cE.textContent !== tE) { cE.textContent = tE; }
            if (cG && cG.textContent !== tG) { cG.textContent = tG; }
        }

        // Remove as duas linhas (resumo + detalhe) de um Endereço de Grupo
        function removerLinhaGrupo(btn) {
            const header = btn.closest('tr');
            const detalhe = header.nextElementSibling;
            header.remove();
            if (detalhe && detalhe.classList.contains('grupo-linha-detalhe')) { detalhe.remove(); }
        }
        // Mostra/esconde a linha de detalhe (Endereço, Função e Associações) de um Endereço de Grupo
        function toggleGrupoLinha(toggleEl) {
            const header = toggleEl.closest('tr');
            const detalhe = header.nextElementSibling;
            if (!detalhe) { return; }
            const icon = toggleEl.querySelector('.grupo-toggle-icon');
            const expandido = detalhe.style.display !== 'none';
            detalhe.style.display = expandido ? 'none' : 'table-row';
            if (icon) { icon.style.transform = expandido ? 'rotate(0deg)' : 'rotate(90deg)'; }
        }
        // Atualiza o resumo (Endereço, Função e "N associações") de cada linha de Grupo,
        // lendo os valores da respetiva linha de detalhe (chamado sempre que algo muda)
        function atualizarContadoresAssoc() {
            document.querySelectorAll('#tabela_grupos tbody tr.grupo-linha-header').forEach(header => {
                const detalhe = header.nextElementSibling;
                if (!detalhe) { return; }
                const inputEndereco = detalhe.querySelector('.grupo-endereco');
                const inputFuncao = detalhe.querySelector('.grupo-funcao');
                const endereco = inputEndereco ? inputEndereco.value.trim() : '';
                const funcao = inputFuncao ? inputFuncao.value.trim() : '';
                let preenchidas = 0;
                detalhe.querySelectorAll('.assoc-item').forEach(item => {
                    const f = item.querySelector('.assoc-fisico').value.trim();
                    const o = item.querySelector('.assoc-objeto').value.trim();
                    if (f !== '' || o !== '') { preenchidas++; }
                });
                const textoEndereco = endereco !== '' ? endereco : '(sem Endereço de Grupo)';
                // Grupo Principal (0/-/-) ou Intermédio (0/0/-) do ETS: não leva associações
                const tipoRange = tipoRangeEts(endereco);
                let textoCount = preenchidas === 0 ? 'Sem associações' : (preenchidas === 1 ? '1 associação' : preenchidas + ' associações');
                if (tipoRange === 'principal') { textoCount = 'Grupo principal'; }
                if (tipoRange === 'intermedio') { textoCount = 'Grupo intermédio'; }
                header.classList.toggle('grupo-range', tipoRange !== null);
                const inputDpt = detalhe.querySelector('.grupo-dpt');
                const textoDpt = inputDpt ? inputDpt.value.trim() : '';
                const spanDpt = header.querySelector('.grupo-resumo-dpt');
                if (spanDpt && spanDpt.textContent !== textoDpt) { spanDpt.textContent = textoDpt; }
                const spanEndereco = header.querySelector('.grupo-resumo-endereco');
                const spanFuncao = header.querySelector('.grupo-resumo-funcao');
                const spanCount = header.querySelector('.grupo-resumo-count');
                // Só escreve se o valor realmente mudou: escrever sempre (mesmo repetindo o
                // mesmo valor) dispara o MutationObserver desta tabela outra vez, e isso
                // criava um ciclo infinito (a página ficava presa a "pensar" para sempre).
                if (spanEndereco && spanEndereco.textContent !== textoEndereco) { spanEndereco.textContent = textoEndereco; }
                if (spanFuncao && spanFuncao.textContent !== funcao) { spanFuncao.textContent = funcao; }
                if (spanCount && spanCount.textContent !== textoCount) { spanCount.textContent = textoCount; }
            });
            atualizarContagensSeccoes();
        }
        // OTIMIZADO: o dropdown de Objeto começa só com o valor gravado; a lista completa
        // (0 a 1000) só é criada quando se clica nele — com centenas de endereços isto
        // evita criar centenas de milhares de opções ao abrir a página ou importar o CSV
        function construirOptionsObjetoLeve(valorAtual) {
            valorAtual = (valorAtual === undefined || valorAtual === null) ? '' : String(valorAtual).trim();
            let html = '<option value="">-- Selecione --</option>';
            if (valorAtual !== '') { html += `<option value="${escapeHTML(valorAtual)}" selected>${escapeHTML(valorAtual)}</option>`; }
            return html;
        }
        function carregarOptionsObjetoCompletas(select) {
            if (!select || select.dataset.lazy !== '1') { return; }
            const valor = select.value;
            select.innerHTML = construirOptionsObjeto(valor);
            select.dataset.lazy = '0';
        }
        function renderAssocItem(fisico, objeto) {
            return `<div class="assoc-item">
                <select class="assoc-fisico" style="width:40%;">${construirOptionsFisico(fisico)}</select>
                <select class="assoc-objeto" data-lazy="1" style="width:40%;">${construirOptionsObjetoLeve(objeto)}</select>
                <button type="button" class="btn-assoc-del" onclick="this.closest('.assoc-item').remove()"><i class="fa-solid fa-xmark"></i></button>
            </div>`;
        }
        function addNovaAssoc(btn) {
            btn.previousElementSibling.insertAdjacentHTML('beforeend', renderAssocItem('',''));
        }
        // ==========================================
        // OPÇÕES DAS ASSOCIAÇÕES: Físico (vem da tabela de Equipamentos) e Objeto (0 a 1000)
        // ==========================================
        // Lê os Endereços Físicos já introduzidos na tabela de Hardware, sem repetidos
        function getEnderecosFisicos() {
            const inputs = document.querySelectorAll('#tabela_equip input[name="knx_fisico[]"]');
            let enderecos = [];
            inputs.forEach(inp => {
                const v = inp.value.trim();
                if (v !== '' && !enderecos.includes(v)) { enderecos.push(v); }
            });
            return enderecos;
        }
        // Constrói as <option> do dropdown de Físico, preservando o valor gravado
        // mesmo que esse endereço já não exista na tabela de Equipamentos
        function construirOptionsFisico(valorAtual) {
            valorAtual = (valorAtual === null || valorAtual === undefined) ? '' : String(valorAtual).trim();
            const enderecos = getEnderecosFisicos();
            let html = '<option value="">-- Selecione --</option>';
            let encontrado = false;
            enderecos.forEach(end => {
                const sel = (end === valorAtual) ? ' selected' : '';
                if (end === valorAtual) { encontrado = true; }
                html += `<option value="${escapeHTML(end)}"${sel}>${escapeHTML(end)}</option>`;
            });
            if (valorAtual !== '' && !encontrado) {
                html += `<option value="${escapeHTML(valorAtual)}" selected>${escapeHTML(valorAtual)} (não encontrado)</option>`;
            }
            return html;
        }
        // Constrói as <option> do dropdown de Objeto (0 a 1000), preservando valores antigos fora do intervalo
        // OTIMIZADO: a lista base (1001 opções) é gerada só uma vez e reutilizada — antes era
        // gerada de novo para cada associação, o que tornava a página muito lenta em termos grandes
        let cacheOptionsObjeto = null;
        function construirOptionsObjeto(valorAtual) {
            valorAtual = (valorAtual === undefined || valorAtual === null) ? '' : String(valorAtual).trim();
            if (cacheOptionsObjeto === null) {
                let partes = [];
                for (let i = 0; i <= 1000; i++) { partes.push(`<option value="${i}">${i}</option>`); }
                cacheOptionsObjeto = partes.join('');
            }
            let html = '<option value="">-- Selecione --</option>';
            if (valorAtual === '') { return html + cacheOptionsObjeto; }
            if (/^\d+$/.test(valorAtual) && parseInt(valorAtual, 10) <= 1000) {
                const n = String(parseInt(valorAtual, 10));
                return html + cacheOptionsObjeto.replace(`<option value="${n}">`, `<option value="${n}" selected>`);
            }
            return html + cacheOptionsObjeto + `<option value="${escapeHTML(valorAtual)}" selected>${escapeHTML(valorAtual)}</option>`;
        }
        // Atualiza todos os dropdowns de Físico já existentes na página
        // (chamado sempre que a tabela de Equipamentos muda)
        let ultimaAssinaturaFisicos = null;
        function atualizarSelectsFisico() {
            // Os dropdowns de Físico vivem dentro da tabela de Grupos, que também está a
            // ser vigiada por outro observador. Só reconstrói as opções quando a lista de
            // Endereços Físicos realmente mudou, para não gerar escritas/eventos à toa.
            const assinatura = getEnderecosFisicos().join('|');
            if (assinatura === ultimaAssinaturaFisicos) { return; }
            ultimaAssinaturaFisicos = assinatura;
            document.querySelectorAll('.assoc-fisico').forEach(select => {
                const valorAtual = select.value;
                select.innerHTML = construirOptionsFisico(valorAtual);
            });
        }
        // Sempre que se escreve num campo "End. Físico" da tabela de Equipamentos,
        // ou se adiciona/remove/reordena uma linha, os dropdowns de Físico atualizam-se
        document.querySelector('#tabela_equip tbody').addEventListener('input', function(e) {
            if (e.target.name === 'knx_fisico[]') { atualizarSelectsFisico(); }
        });
        new MutationObserver(function() { atualizarSelectsFisico(); atualizarContagensSeccoes(); })
            .observe(document.querySelector('#tabela_equip tbody'), { childList: true });
        // ==========================================
        // MOVIMENTAÇÃO DE LINHAS (Transversal)
        // ==========================================
        function toggleAllRows(tableId, master) {
            document.querySelectorAll('#' + tableId + ' .chk-select-row').forEach(chk => chk.checked = master.checked);
            updateMoveArrows(tableId);
        }
        function updateMoveArrows(tableId) {
            document.querySelectorAll('#' + tableId + ' .move-controls').forEach(el => el.style.display = 'none');
            const checkedBoxes = document.querySelectorAll('#' + tableId + ' .chk-select-row:checked');
            if (checkedBoxes.length > 0) {
                checkedBoxes.forEach(chk => {
                    const controls = chk.closest('.chk-wrapper').querySelector('.move-controls');
                    if (controls) controls.style.display = 'flex';
                });
            }
        }
        // Na tabela de Grupos, cada Endereço de Grupo é uma "unidade" de DUAS linhas
        // (resumo + detalhe); na tabela de Equipamentos é só UMA linha por unidade.
        // Esta função devolve a lista de unidades, para mover/duplicar sempre o
        // conjunto todo em vez de partir os pares ao meio.
        function obterUnidadesLinhas(tableId) {
            const tbody = document.querySelector('#' + tableId + ' tbody');
            let unidades = [];
            if (tableId === 'tabela_grupos') {
                tbody.querySelectorAll('tr.grupo-linha-header').forEach(tr => {
                    unidades.push([tr, tr.nextElementSibling]);
                });
            } else {
                tbody.querySelectorAll('tr').forEach(tr => unidades.push([tr]));
            }
            return unidades;
        }
        function moveSelectedRows(tableId, dir) {
            const tbody = document.querySelector('#' + tableId + ' tbody');
            const unidades = obterUnidadesLinhas(tableId);
            const estaSelecionada = u => u[0].querySelector('.chk-select-row').checked;
            if (dir === 'up') {
                for (let i = 1; i < unidades.length; i++) {
                    if (estaSelecionada(unidades[i]) && !estaSelecionada(unidades[i - 1])) {
                        unidades[i].forEach(tr => tbody.insertBefore(tr, unidades[i - 1][0]));
                        break;
                    }
                }
            } else {
                for (let i = unidades.length - 2; i >= 0; i--) {
                    if (estaSelecionada(unidades[i]) && !estaSelecionada(unidades[i + 1])) {
                        const seguinte = unidades[i + 1];
                        const referencia = seguinte[seguinte.length - 1].nextSibling;
                        unidades[i].forEach(tr => tbody.insertBefore(tr, referencia));
                        break;
                    }
                }
            }
            updateMoveArrows(tableId);
        }
        function duplicateSelectedRows(tableId) {
            const tbody = document.querySelector('#' + tableId + ' tbody');
            const unidades = obterUnidadesLinhas(tableId);

            for (let i = unidades.length - 1; i >= 0; i--) {
                const unidade = unidades[i];
                const row = unidade[0];
                if (!row.querySelector('.chk-select-row').checked) { continue; }

                if (tableId === 'tabela_equip') {
                    addLinhaEquip({
                        fisico: row.querySelector('[name="knx_fisico[]"]').value,
                        marca: row.querySelector('[name="knx_marca[]"]').value,
                        ref: row.querySelector('[name="knx_ref[]"]').value,
                        tipo: row.querySelector('[name="knx_tipo[]"]').value,
                        localizacao: row.querySelector('[name="knx_localizacao[]"]').value
                    });
                    // O novo elemento vai para o fim, movemo-lo para a seguir ao original
                    const newestRow = tbody.lastElementChild;
                    tbody.insertBefore(newestRow, row.nextSibling);
                } else if (tableId === 'tabela_grupos') {
                    const detalhe = unidade[1];
                    let assocData = [];
                    detalhe.querySelectorAll('.assoc-item').forEach(item => {
                        assocData.push({
                            fisico: item.querySelector('.assoc-fisico').value,
                            objeto: item.querySelector('.assoc-objeto').value
                        });
                    });
                    addLinhaGrupo({
                        endereco: detalhe.querySelector('.grupo-endereco').value,
                        funcao: detalhe.querySelector('.grupo-funcao').value,
                        dpt: detalhe.querySelector('.grupo-dpt') ? detalhe.querySelector('.grupo-dpt').value : '',
                        ets: lerExtraEts(detalhe),
                        associacoes: assocData
                    });
                    // O novo par (resumo + detalhe) vai para o fim; movemo-lo todo para a
                    // seguir ao par original, mantendo a ordem resumo -> detalhe
                    const novoDetalhe = tbody.lastElementChild;
                    const novoHeader = novoDetalhe.previousElementSibling;
                    tbody.insertBefore(novoHeader, detalhe.nextSibling);
                    tbody.insertBefore(novoDetalhe, novoHeader.nextSibling);
                }
            }
            updateMoveArrows(tableId);
        }
        // Serializa a tabela de Grupos/Associações para o campo escondido grupos_json_input.
        // Usada tanto na gravação normal (submit nativo, ex: Gravar & Imprimir)
        // como na gravação por AJAX (Gravar Registo KNX).
        function serializarGrupos() {
            let grupos = [];
            document.querySelectorAll('#tabela_grupos tbody tr.grupo-linha-detalhe').forEach(row => {
                let endereco = row.querySelector('.grupo-endereco').value.trim();
                let funcao = row.querySelector('.grupo-funcao').value.trim();
                let dpt = row.querySelector('.grupo-dpt') ? row.querySelector('.grupo-dpt').value.trim() : '';
                let ets = lerExtraEts(row);

                let assocArray = [];
                row.querySelectorAll('.assoc-item').forEach(item => {
                    let f = item.querySelector('.assoc-fisico').value.trim();
                    let s = item.querySelector('.assoc-objeto').value.trim();
                    if(f !== '' || s !== '') { assocArray.push({ fisico: f, objeto: s }); }
                });
                if (endereco !== '' || funcao !== '' || assocArray.length > 0) {
                    grupos.push({ endereco: endereco, funcao: funcao, dpt: dpt, ets: ets, associacoes: assocArray });
                }
            });
            document.getElementById('grupos_json_input').value = JSON.stringify(grupos);
        }
        // Antes do formulário submeter de forma nativa (ex: Gravar & Imprimir PDF),
        // garantimos que os Grupos vão sempre atualizados
        document.getElementById('formRelatorio').addEventListener('submit', function(e) {
            serializarGrupos();
        });
        // Impede que a tecla Enter num campo de texto dispare o único botão
        // type="submit" que resta no formulário (Gravar & Imprimir)
        document.getElementById('formRelatorio').addEventListener('keydown', function(e) {
            if (e.key === 'Enter' && e.target.tagName === 'INPUT') {
                e.preventDefault();
            }
        });
        // ==========================================
        // ATRIBUIR SAÍDAS A ENDEREÇOS DE GRUPO (a partir do Endereço Físico)
        // Lista os Endereços Físicos já criados; ao escolher um, um modal pergunta
        // a Objeto e o Endereço de Grupo, e a tabela de Grupos atualiza-se sozinha
        // (cria a linha se o Endereço de Grupo ainda não existir, ou acrescenta-lhe
        // a associação se já existir) — é o mesmo dado das Associações de cada
        // Grupo, só que preenchido a partir do lado do Físico.
        // ==========================================
        let fisicoAtualAssociar = '';

        // Devolve as Associações já existentes para um dado Endereço Físico
        // (percorre a tabela de Grupos toda, olhando para as Associações de cada linha)
        function obterAssociacoesDoFisico(fisico) {
            let assocs = [];
            document.querySelectorAll('#tabela_grupos tbody tr.grupo-linha-detalhe').forEach(row => {
                const endereco = row.querySelector('.grupo-endereco').value.trim();
                const funcao = row.querySelector('.grupo-funcao').value.trim();
                row.querySelectorAll('.assoc-item').forEach(item => {
                    const f = item.querySelector('.assoc-fisico').value.trim();
                    const s = item.querySelector('.assoc-objeto').value.trim();
                    if (f === fisico && s !== '') {
                        assocs.push({ endereco: endereco, funcao: funcao, objeto: s });
                    }
                });
            });
            // Ordenado por Objeto (numérico), não pela ordem dos Endereços de Grupo
            assocs.sort((a, b) => (parseInt(a.objeto, 10) || 0) - (parseInt(b.objeto, 10) || 0));
            return assocs;
        }
        // Lista de Endereços Físicos, com um botão "Adicionar Endereço de Grupo" para cada um
        // e, por baixo, as Associações que esse Físico já tem
        // Guarda quais Endereços Físicos estão com a lista de Associações expandida
        // (sobrevive aos re-renders desta lista, que acontecem com frequência)
        let fisicosExpandidos = new Set();
        function renderizarListaFisicosAssociar() {
            const container = document.getElementById('listaFisicosAssociar');
            if (!container) { return; }
            const enderecos = getEnderecosFisicos();
            if (enderecos.length === 0) {
                container.innerHTML = '<div style="color:#95a5a6; font-size:13px; padding:10px 0;">Adicione primeiro Módulos Físicos na tabela de Hardware acima.</div>';
                return;
            }
            container.innerHTML = enderecos.map(end => {
                const assocs = obterAssociacoesDoFisico(end);
                const contagem = assocs.length === 0 ? 'Sem associações' : (assocs.length === 1 ? '1 associação' : assocs.length + ' associações');
                const expandido = assocs.length > 0 && fisicosExpandidos.has(end);
                let assocsHtml = '';
                if (assocs.length > 0) {
                    assocsHtml = `<div class="fisico-assocs" style="display:${expandido ? 'block' : 'none'}; margin-top:8px; padding-left:26px; flex-direction:column; gap:4px;">` +
                        assocs.map(a => `<div style="font-size:11px; color:#7f8c8d;"><i class="fa-solid fa-turn-up" style="transform:rotate(90deg); margin-right:6px; color:#bdc3c7;"></i>Objeto ${escapeHTML(a.objeto)} &rarr; <strong style="color:#576574;">${escapeHTML(a.endereco || '(sem Endereço de Grupo)')}</strong>${a.funcao ? ' — ' + escapeHTML(a.funcao) : ''}</div>`).join('') +
                        '</div>';
                }
                return `
                <div class="fisico-card" data-fisico-card="${escapeHTML(end)}" style="background:#f8f9fa; border:1px solid #e1e5ea; border-radius:6px; padding:8px 12px;">
                    <div style="display:flex; justify-content:space-between; align-items:center;">
                        <div class="fisico-toggle" style="display:flex; align-items:center; gap:8px; ${assocs.length > 0 ? 'cursor:pointer;' : ''}">
                            <i class="fa-solid fa-chevron-right" style="font-size:10px; color:#95a5a6; transition:transform 0.2s; ${assocs.length === 0 ? 'visibility:hidden;' : ''} ${expandido ? 'transform:rotate(90deg);' : ''}"></i>
                            <span style="font-weight:bold; color:#2c3e50;"><i class="fa-solid fa-microchip" style="color:#7f8c8d; margin-right:8px;"></i>${escapeHTML(end)}</span>
                            <span style="font-size:11px; color:#95a5a6;">(${contagem})</span>
                        </div>
                        <button type="button" class="btn-acao-header" data-fisico="${escapeHTML(end)}" style="padding:6px 14px; font-size:12px;"><i class="fa-solid fa-plus"></i> Adicionar Endereço de Grupo</button>
                    </div>
                    ${assocsHtml}
                </div>`;
            }).join('');
        }
        document.getElementById('listaFisicosAssociar').addEventListener('click', function(e) {
            const btn = e.target.closest('button[data-fisico]');
            if (btn) { abrirModalAssociarObjeto(btn.dataset.fisico); return; }
            const toggle = e.target.closest('.fisico-toggle');
            if (toggle) {
                const fisico = toggle.closest('.fisico-card').dataset.fisicoCard;
                if (fisicosExpandidos.has(fisico)) { fisicosExpandidos.delete(fisico); }
                else { fisicosExpandidos.add(fisico); }
                renderizarListaFisicosAssociar();
            }
        });
        // A lista atualiza-se sempre que a tabela de Equipamentos muda (reaproveita
        // o mesmo observador/listener já usado para atualizar os dropdowns de Físico)
        document.querySelector('#tabela_equip tbody').addEventListener('input', function(e) {
            if (e.target.name === 'knx_fisico[]') { renderizarListaFisicosAssociar(); }
        });
        new MutationObserver(function() { if (!aCarregarInicial) renderizarListaFisicosAssociar(); })
            .observe(document.querySelector('#tabela_equip tbody'), { childList: true });

        // Datalist com os Endereços de Grupo já existentes (permite escolher OU escrever um novo)
        function atualizarDatalistGrupos() {
            const datalist = document.getElementById('datalistEnderecosGrupo');
            if (!datalist) { return; }
            let enderecos = [];
            document.querySelectorAll('#tabela_grupos .grupo-endereco').forEach(inp => {
                const v = inp.value.trim();
                // Os Grupos Principal/Intermédio (0/-/-, 0/0/-) não servem para associar objetos
                if (v !== '' && tipoRangeEts(v) === null && !enderecos.includes(v)) { enderecos.push(v); }
            });
            datalist.innerHTML = enderecos.map(e => `<option value="${escapeHTML(e)}">`).join('');
        }
        // Atualiza o datalist de Endereços de Grupo, a lista de Associações por Físico,
        // e os contadores "N associações" de cada linha de Grupo
        function atualizarTudoGrupos() {
            if (aCarregarInicial) { return; }
            atualizarDatalistGrupos();
            renderizarListaFisicosAssociar();
            atualizarContadoresAssoc();
        }
        // "subtree:true" porque as Associações vivem dentro de cada linha (mais um nível
        // abaixo do tbody), e queremos apanhar também essas alterações, não só linhas inteiras
        document.querySelector('#tabela_grupos tbody').addEventListener('input', atualizarTudoGrupos);
        new MutationObserver(atualizarTudoGrupos)
            .observe(document.querySelector('#tabela_grupos tbody'), { childList: true, subtree: true });

        // Procura se o par Físico+Objeto já está associado nalgum Endereço de Grupo.
        // 'ignorarElemento' exclui a própria Associação a ser editada (para não alertar contra si mesma).
        // Devolve o Endereço de Grupo onde encontrou a repetição, ou null se não houver.
        function encontrarAssociacaoDuplicada(fisico, objeto, ignorarElemento) {
            fisico = (fisico || '').trim();
            objeto = (objeto || '').trim();
            if (fisico === '' || objeto === '') { return null; }
            let duplicado = null;
            document.querySelectorAll('#tabela_grupos tbody tr.grupo-linha-detalhe').forEach(row => {
                row.querySelectorAll('.assoc-item').forEach(item => {
                    if (item === ignorarElemento || duplicado) { return; }
                    const f = item.querySelector('.assoc-fisico').value.trim();
                    const s = item.querySelector('.assoc-objeto').value.trim();
                    if (f === fisico && s === objeto) {
                        duplicado = row.querySelector('.grupo-endereco').value.trim() || '(sem Endereço de Grupo definido)';
                    }
                });
            });
            return duplicado;
        }
        // Carrega a lista completa de Objetos (0 a 1000) só quando o dropdown é usado
        ['mousedown', 'focusin', 'touchstart'].forEach(ev => {
            document.getElementById('tabela_grupos').addEventListener(ev, function(e) {
                if (e.target && e.target.classList && e.target.classList.contains('assoc-objeto')) {
                    carregarOptionsObjetoCompletas(e.target);
                }
            }, { passive: true });
        });
        // Alerta em tempo real ao editar diretamente uma Associação na tabela de Grupos
        document.getElementById('tabela_grupos').addEventListener('change', function(e) {
            if (!e.target.classList.contains('assoc-fisico') && !e.target.classList.contains('assoc-objeto')) { return; }
            const item = e.target.closest('.assoc-item');
            const fisico = item.querySelector('.assoc-fisico').value;
            const objeto = item.querySelector('.assoc-objeto').value;
            const duplicado = encontrarAssociacaoDuplicada(fisico, objeto, item);
            if (duplicado) {
                alert('Atenção: o Endereço Físico ' + fisico + ' com o Objeto ' + objeto + ' já está associado ao Endereço de Grupo "' + duplicado + '".\n\nVerifique se é mesmo essa a intenção.');
            }
            atualizarTudoGrupos();
        });

        function abrirModalAssociarObjeto(fisico) {
            fisicoAtualAssociar = fisico;
            document.getElementById('modalAssocFisicoLabel').textContent = fisico;
            document.getElementById('modalAssocObjeto').innerHTML = construirOptionsObjeto('');
            document.getElementById('modalAssocGrupo').value = '';
            atualizarDatalistGrupos();
            renderizarAssocsModalAtual();
            document.getElementById('modalAssociarObjeto').style.display = 'flex';
            document.getElementById('modalAssocObjeto').focus();
        }
        function fecharModalAssociarObjeto() {
            document.getElementById('modalAssociarObjeto').style.display = 'none';
        }
        // Lista, dentro do próprio popup, as Associações que este Endereço Físico já tem
        // (para o utilizador ver o que já introduziu sem ter de fechar o popup)
        function renderizarAssocsModalAtual() {
            const lista = document.getElementById('modalAssocListaExistente');
            if (!lista) { return; }
            const assocs = obterAssociacoesDoFisico(fisicoAtualAssociar);
            if (assocs.length === 0) {
                lista.innerHTML = '<p style="color:#95a5a6; font-size:12px; margin:0;">Ainda sem associações para este Endereço Físico.</p>';
                return;
            }
            lista.innerHTML = '<p style="color:#95a5a6; font-size:11px; margin:0 0 6px; text-transform:uppercase; letter-spacing:0.3px;">Já associado a este Físico:</p>' +
                '<div style="max-height:130px; overflow-y:auto; border:1px solid #f1f2f6; border-radius:6px;">' +
                assocs.map(a => `<div style="font-size:12px; color:#576574; padding:6px 10px; border-bottom:1px solid #f1f2f6;">Objeto <strong style="color:#2c3e50;">${escapeHTML(a.objeto)}</strong> &rarr; <strong style="color:#2c3e50;">${escapeHTML(a.endereco || '(sem Endereço de Grupo)')}</strong>${a.funcao ? ' — ' + escapeHTML(a.funcao) : ''}</div>`).join('') +
                '</div>';
        }
        function confirmarAssociarObjeto() {
            const objeto = document.getElementById('modalAssocObjeto').value;
            const enderecoGrupo = document.getElementById('modalAssocGrupo').value.trim();

            if (!objeto || enderecoGrupo === '') {
                alert('Escolha o Objeto e indique o Endereço de Grupo.');
                return;
            }

            // Alerta se este Físico+Objeto já estiver associado a outro Endereço de Grupo
            const duplicado = encontrarAssociacaoDuplicada(fisicoAtualAssociar, objeto, null);
            if (duplicado) {
                alert('Atenção: o Endereço Físico ' + fisicoAtualAssociar + ' com o Objeto ' + objeto + ' já está associado ao Endereço de Grupo "' + duplicado + '".\n\nA associação vai ser criada na mesma — verifique se é mesmo essa a intenção.');
            }

            // Existe já uma linha com este Endereço de Grupo?
            let detalheEncontrado = null;
            document.querySelectorAll('#tabela_grupos tbody tr.grupo-linha-detalhe').forEach(row => {
                const inp = row.querySelector('.grupo-endereco');
                if (inp && inp.value.trim() === enderecoGrupo) { detalheEncontrado = row; }
            });

            let novoHeader, novoDetalhe;
            if (!detalheEncontrado) {
                // Não existe: cria uma nova linha de Grupo, já com esta associação
                removerLinhaGrupoVaziaInicial();
                addLinhaGrupo({ endereco: enderecoGrupo, funcao: '', associacoes: [{ fisico: fisicoAtualAssociar, objeto: objeto }] });
                const headers = document.querySelectorAll('#tabela_grupos tbody tr.grupo-linha-header');
                novoHeader = headers[headers.length - 1];
                novoDetalhe = novoHeader.nextElementSibling;
            } else {
                // Já existe: acrescenta-lhe a associação (aproveitando a 1ª linha se estiver vazia)
                const container = detalheEncontrado.querySelector('.assoc-container');
                const itensAtuais = container.querySelectorAll('.assoc-item');
                if (itensAtuais.length === 1) {
                    const f = itensAtuais[0].querySelector('.assoc-fisico').value;
                    const s = itensAtuais[0].querySelector('.assoc-objeto').value;
                    if (f === '' && s === '') { itensAtuais[0].remove(); }
                }
                container.insertAdjacentHTML('beforeend', renderAssocItem(fisicoAtualAssociar, objeto));
                atualizarSelectsFisico();
                novoDetalhe = detalheEncontrado;
                novoHeader = novoDetalhe.previousElementSibling;
            }

            // Mostra a lista de Endereços de Grupo (pode estar escondida) e expande a
            // linha para se ver logo a associação acabada de criar/acrescentar
            mostrarTabelaGrupos();
            novoDetalhe.style.display = 'table-row';
            const icone = novoHeader.querySelector('.grupo-toggle-icon');
            if (icone) { icone.style.transform = 'rotate(90deg)'; }
            atualizarTudoGrupos();

            // Não fecha o popup: limpa os campos e atualiza a lista de associações já
            // feitas a este Físico, para se poder associar logo a seguir outro Objeto/Endereço
            document.getElementById('modalAssocObjeto').innerHTML = construirOptionsObjeto('');
            document.getElementById('modalAssocGrupo').value = '';
            atualizarDatalistGrupos();
            renderizarAssocsModalAtual();
            document.getElementById('modalAssocObjeto').focus();

            // Mantém-te junto à Associação recém-criada em vez de ficares no topo:
            // desloca o ecrã até à linha afetada e destaca-a por instantes (só se torna
            // visível depois de se fechar o popup, mas já fica preparado)
            novoHeader.scrollIntoView({ behavior: 'smooth', block: 'center' });
            novoHeader.style.transition = 'background 0.3s';
            novoHeader.style.background = '#eafaf1';
            setTimeout(() => { novoHeader.style.background = ''; }, 1200);
        }
        // ==========================================
        // IMPORTAÇÃO DE CSV: ENDEREÇOS DE GRUPO E DESCRIÇÃO/FUNÇÃO
        // ==========================================
        let registosCsvPendentes = [];

        // Lê o ficheiro como texto, tentando detetar se é UTF-8 (BOM) ou
        // Windows-1252 (o mais comum em CSVs exportados do Excel em PT-PT)
        function lerFicheiroComoTexto(file, callback) {
            const readerBOM = new FileReader();
            readerBOM.onloadend = function() {
                const bytes = new Uint8Array(readerBOM.result);
                const temBOM = bytes.length >= 3 && bytes[0] === 0xEF && bytes[1] === 0xBB && bytes[2] === 0xBF;
                const encoding = temBOM ? 'UTF-8' : 'windows-1252';
                const reader = new FileReader();
                reader.onload = function(e) { callback(e.target.result); };
                reader.readAsText(file, encoding);
            };
            readerBOM.readAsArrayBuffer(file.slice(0, 3));
        }
        // Interpreta uma linha de CSV respeitando campos entre aspas
        function parseCSVLinha(linha, delimitador) {
            let campos = [];
            let atual = '';
            let dentroAspas = false;
            for (let i = 0; i < linha.length; i++) {
                const c = linha[i];
                if (c === '"') {
                    if (dentroAspas && linha[i + 1] === '"') { atual += '"'; i++; }
                    else { dentroAspas = !dentroAspas; }
                } else if (c === delimitador && !dentroAspas) {
                    campos.push(atual); atual = '';
                } else {
                    atual += c;
                }
            }
            campos.push(atual);
            return campos.map(c => c.trim());
        }
        // ==========================================
        // FORMATO ETS: CSV | 1/1 Nome/Endereço | Ponto e vírgula
        // Cada linha: "Nome";"Endereço";"Central";"Unfiltered";"Descrição";"DatapointType";"Security"
        // Endereços "0/-/-" = Grupo Principal, "0/0/-" = Grupo Intermédio
        // ==========================================
        const REGEX_ENDERECO_ETS = /^\d+\/(\d+|-)\/(\d+|-)$/;

        // Devolve 'principal' (0/-/-), 'intermedio' (0/0/-) ou null (endereço normal 0/0/1)
        function tipoRangeEts(endereco) {
            endereco = (endereco || '').trim();
            if (/^\d+\/-\/-$/.test(endereco)) { return 'principal'; }
            if (/^\d+\/\d+\/-$/.test(endereco)) { return 'intermedio'; }
            return null;
        }
        // Campos do ETS que não aparecem no ecrã, mas que se guardam para a exportação sair igual
        function normalizarExtraEts(extra) {
            extra = (extra && typeof extra === 'object') ? extra : {};
            return {
                central: extra.central !== undefined ? String(extra.central) : '',
                unfiltered: extra.unfiltered !== undefined ? String(extra.unfiltered) : '',
                descricao: extra.descricao !== undefined ? String(extra.descricao) : '',
                security: extra.security !== undefined && String(extra.security) !== '' ? String(extra.security) : 'Auto'
            };
        }
        function lerExtraEts(detalhe) {
            const inp = detalhe ? detalhe.querySelector('.grupo-ets-extra') : null;
            if (!inp || !inp.value) { return normalizarExtraEts({}); }
            try { return normalizarExtraEts(JSON.parse(inp.value)); } catch (e) { return normalizarExtraEts({}); }
        }

        // Converte o texto do CSV em linhas (arrays de campos), detetando o delimitador
        function parseCSV(texto) {
            texto = texto.replace(/^﻿/, '').replace(/\r\n/g, '\n').replace(/\r/g, '\n');
            const linhas = texto.split('\n').filter(l => l.trim() !== '');
            if (linhas.length === 0) { return []; }
            // ";" é o delimitador do formato do ETS (e o mais comum no Excel em Portugal)
            const delimitador = (linhas[0].split(';').length >= linhas[0].split(',').length) ? ';' : ',';
            return linhas.map(l => parseCSVLinha(l, delimitador));
        }
        // Interpreta as linhas: formato ETS (Nome;Endereço;...;DPT;Security) ou, por compatibilidade,
        // o formato antigo (Endereço;Função)
        function interpretarCsvGrupos(registos) {
            const linhasEts = registos.filter(r => r.length >= 2 && REGEX_ENDERECO_ETS.test((r[1] || '').trim()));
            if (linhasEts.length > 0) {
                // Formato ETS (a linha de cabeçalho, se existir, é ignorada automaticamente)
                return linhasEts.map(r => ({
                    funcao: (r[0] || '').trim(),
                    endereco: (r[1] || '').trim(),
                    dpt: (r[5] || '').trim(),
                    ets: normalizarExtraEts({
                        central: (r[2] || '').trim(),
                        unfiltered: (r[3] || '').trim(),
                        descricao: (r[4] || '').trim(),
                        security: (r[6] || '').trim()
                    })
                }));
            }
            // Formato antigo: Endereço;Função (com cabeçalho opcional)
            let antigos = registos.slice();
            if (antigos.length > 0) {
                const primeira = antigos[0].join(' ').toLowerCase();
                if (primeira.includes('endere') || primeira.includes('grupo') || primeira.includes('fun') || primeira.includes('descri')) { antigos.shift(); }
            }
            return antigos
                .map(r => ({ endereco: (r[0] || '').trim(), funcao: (r[1] || '').trim(), dpt: '', ets: normalizarExtraEts({}) }))
                .filter(r => r.endereco !== '' || r.funcao !== '');
        }
        // Mapa Endereço de Grupo -> linha de detalhe já existente na tabela
        function mapaGruposExistentes() {
            const mapa = new Map();
            document.querySelectorAll('#tabela_grupos tbody tr.grupo-linha-detalhe').forEach(row => {
                const end = row.querySelector('.grupo-endereco').value.trim();
                if (end !== '' && !mapa.has(end)) { mapa.set(end, row); }
            });
            return mapa;
        }
        // Se só existir uma linha de Grupo completamente vazia (o formulário começa sempre com uma),
        // remove-a antes de inserir os dados importados
        function removerLinhaGrupoVaziaInicial() {
            const headers = document.querySelectorAll('#tabela_grupos tbody tr.grupo-linha-header');
            if (headers.length !== 1) { return; }
            const header = headers[0];
            const detalhe = header.nextElementSibling;
            if (!detalhe) { return; }
            const endereco = detalhe.querySelector('.grupo-endereco').value.trim();
            const funcao = detalhe.querySelector('.grupo-funcao').value.trim();
            let temAssoc = false;
            detalhe.querySelectorAll('.assoc-item').forEach(item => {
                const f = item.querySelector('.assoc-fisico').value;
                const s = item.querySelector('.assoc-objeto').value;
                if (f !== '' || s !== '') { temAssoc = true; }
            });
            if (endereco === '' && funcao === '' && !temAssoc) { header.remove(); detalhe.remove(); }
        }
        function handleCsvGruposSelecionado(event) {
            const file = event.target.files[0];
            event.target.value = ''; // permite voltar a escolher o mesmo ficheiro mais tarde
            if (!file) { return; }

            lerFicheiroComoTexto(file, function(texto) {
                const validos = interpretarCsvGrupos(parseCSV(texto));

                if (validos.length === 0) {
                    alert('Não foi possível encontrar linhas válidas neste ficheiro CSV.\nFormato esperado (exportação do ETS): CSV | 1/1 Nome/Endereço | Ponto e vírgula.');
                    return;
                }
                const existentes = mapaGruposExistentes();
                const nAtualizar = validos.filter(r => existentes.has(r.endereco)).length;
                const nNovos = validos.length - nAtualizar;

                registosCsvPendentes = validos;
                document.getElementById('msgImportarCsvGrupos').textContent =
                    'Foram encontrados ' + validos.length + ' endereço(s) no ficheiro: ' +
                    nNovos + ' novo(s) a acrescentar e ' + nAtualizar + ' já existente(s), que terão o Nome e o DPT atualizados (as associações mantêm-se). Continuar?';
                document.getElementById('modalImportarCsvGrupos').style.display = 'flex';
            });
        }
        function confirmarImportacaoCsvGrupos() {
            removerLinhaGrupoVaziaInicial();
            const existentes = mapaGruposExistentes();

            // Pausa os atualizadores durante a importação (uma única atualização no fim)
            aCarregarInicial = true;
            registosCsvPendentes.forEach(r => {
                const detalhe = existentes.get(r.endereco);
                if (detalhe) {
                    // Já existe: atualiza Nome, DPT e campos ETS, mantendo as associações
                    detalhe.querySelector('.grupo-funcao').value = r.funcao;
                    const inpDpt = detalhe.querySelector('.grupo-dpt');
                    if (inpDpt) { inpDpt.value = r.dpt; }
                    const inpExtra = detalhe.querySelector('.grupo-ets-extra');
                    if (inpExtra) { inpExtra.value = JSON.stringify(normalizarExtraEts(r.ets)); }
                } else {
                    addLinhaGrupo({ endereco: r.endereco, funcao: r.funcao, dpt: r.dpt, ets: r.ets });
                    existentes.set(r.endereco, document.querySelector('#tabela_grupos tbody').lastElementChild);
                }
            });
            aCarregarInicial = false;
            atualizarTudoGrupos();

            registosCsvPendentes = [];
            document.getElementById('modalImportarCsvGrupos').style.display = 'none';
            mostrarTabelaGrupos();
        }

        // ==========================================
        // EXPORTAÇÃO DE CSV PARA O ETS (mesmo formato do ficheiro exportado pelo ETS)
        // CSV | 1/1 Nome/Endereço | Ponto e vírgula — codificação Windows-1252, sem cabeçalho
        // ==========================================
        // Converte formatos antigos ("0/" ou "0/0") para o formato do ETS ("0/-/-" ou "0/0/-")
        function normalizarEnderecoEts(endereco) {
            endereco = (endereco || '').trim();
            let m;
            if ((m = endereco.match(/^(\d+)\/?$/))) { return m[1] + '/-/-'; }
            if ((m = endereco.match(/^(\d+)\/(\d+)\/?$/))) { return m[1] + '/' + m[2] + '/-'; }
            return endereco;
        }
        // Chave numérica para ordenar como o ETS (principal, intermédio, endereço)
        function chaveOrdenacaoEts(endereco) {
            return endereco.split('/').map(p => p === '-' ? -1 : parseInt(p, 10));
        }
        // Codifica texto em Windows-1252 (o mesmo que o ETS usa nos acentos)
        function codificarWindows1252(texto) {
            const especiais = { 0x20AC:0x80, 0x201A:0x82, 0x0192:0x83, 0x201E:0x84, 0x2026:0x85, 0x2020:0x86, 0x2021:0x87, 0x02C6:0x88, 0x2030:0x89, 0x0160:0x8A, 0x2039:0x8B, 0x0152:0x8C, 0x017D:0x8E, 0x2018:0x91, 0x2019:0x92, 0x201C:0x93, 0x201D:0x94, 0x2022:0x95, 0x2013:0x96, 0x2014:0x97, 0x02DC:0x98, 0x2122:0x99, 0x0161:0x9A, 0x203A:0x9B, 0x0153:0x9C, 0x017E:0x9E, 0x0178:0x9F };
            const bytes = new Uint8Array(texto.length);
            for (let i = 0; i < texto.length; i++) {
                const c = texto.charCodeAt(i);
                if (c < 0x80 || (c >= 0xA0 && c <= 0xFF)) { bytes[i] = c; }
                else if (especiais[c] !== undefined) { bytes[i] = especiais[c]; }
                else { bytes[i] = 0x3F; } // '?' para carateres que não existem em Windows-1252
            }
            return bytes;
        }
        function exportarCsvGruposEts() {
            const mapa = new Map();
            let ignorados = 0;

            document.querySelectorAll('#tabela_grupos tbody tr.grupo-linha-detalhe').forEach(row => {
                const endereco = normalizarEnderecoEts(row.querySelector('.grupo-endereco').value);
                if (endereco === '') { return; }
                if (!REGEX_ENDERECO_ETS.test(endereco)) { ignorados++; return; }
                if (mapa.has(endereco)) { return; } // endereços repetidos: fica o primeiro
                mapa.set(endereco, {
                    endereco: endereco,
                    nome: row.querySelector('.grupo-funcao').value.trim(),
                    dpt: row.querySelector('.grupo-dpt') ? row.querySelector('.grupo-dpt').value.trim() : '',
                    ets: lerExtraEts(row)
                });
            });

            if (mapa.size === 0) {
                alert('Não há Endereços de Grupo válidos para exportar.');
                return;
            }

            // Garante que todos os Grupos Principais e Intermédios existem (o ETS precisa deles)
            Array.from(mapa.keys()).forEach(end => {
                const p = end.split('/');
                if (p[2] !== '-') {
                    const intermedio = p[0] + '/' + p[1] + '/-';
                    if (!mapa.has(intermedio)) { mapa.set(intermedio, { endereco: intermedio, nome: 'Grupo intermédio ' + p[0] + '/' + p[1], dpt: '', ets: normalizarExtraEts({}) }); }
                }
                if (p[1] !== '-') {
                    const principal = p[0] + '/-/-';
                    if (!mapa.has(principal)) { mapa.set(principal, { endereco: principal, nome: 'Grupo principal ' + p[0], dpt: '', ets: normalizarExtraEts({}) }); }
                }
            });

            // Ordena como o ETS: principal, depois os seus intermédios, depois os endereços
            const lista = Array.from(mapa.values()).sort((a, b) => {
                const ka = chaveOrdenacaoEts(a.endereco), kb = chaveOrdenacaoEts(b.endereco);
                for (let i = 0; i < 3; i++) { if (ka[i] !== kb[i]) { return ka[i] - kb[i]; } }
                return 0;
            });

            const q = v => '"' + String(v === null || v === undefined ? '' : v).replace(/"/g, '""') + '"';
            const linhas = lista.map(g => {
                const ets = normalizarExtraEts(g.ets);
                return [g.nome, g.endereco, ets.central, ets.unfiltered, ets.descricao, g.dpt, ets.security].map(q).join(';');
            });
            const texto = linhas.join('\r\n') + '\r\n';

            const blob = new Blob([codificarWindows1252(texto)], { type: 'text/csv;charset=windows-1252' });
            const numero = (document.querySelector('input[name="numero_relatorio"]').value || 'KNX').replace(/[^A-Za-z0-9_-]/g, '_');
            const link = document.createElement('a');
            link.href = URL.createObjectURL(blob);
            link.download = 'Enderecos_Grupo_' + numero + '.csv';
            document.body.appendChild(link);
            link.click();
            setTimeout(() => { URL.revokeObjectURL(link.href); link.remove(); }, 1000);

            if (ignorados > 0) {
                alert('Exportação concluída. ' + ignorados + ' linha(s) com Endereço de Grupo inválido foram ignoradas.');
            }
        }
        function cancelarImportacaoCsvGrupos() {
            registosCsvPendentes = [];
            document.getElementById('modalImportarCsvGrupos').style.display = 'none';
        }
        // ==========================================
        // GRAVAÇÃO POR AJAX + MODAL "O QUE FAZER A SEGUIR"
        // ==========================================
        let ultimoIdGravado = null;
        function gravarRegistoAjax() {
            const form = document.getElementById('formRelatorio');
            if (!form.reportValidity()) { return; } // Respeita os campos "required" como antes

            serializarGrupos();

            const btn = document.getElementById('btnGravarPrincipal');
            const textoOriginal = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> A gravar...';

            let fd = new FormData(form);
            fd.append('btn_guardar', '1');
            fd.append('via_ajax', '1');

            fetch('', { method: 'POST', body: fd })
                .then(res => res.json())
                .then(data => {
                    btn.disabled = false;
                    btn.innerHTML = textoOriginal;
                    if (data.success) {
                        ultimoIdGravado = data.id;
                        document.getElementById('modalPosGravar').style.display = 'flex';
                    } else {
                        alert('Erro ao gravar: ' + (data.error || 'Verifique os dados introduzidos.'));
                    }
                })
                .catch(() => {
                    btn.disabled = false;
                    btn.innerHTML = textoOriginal;
                    alert('Erro de comunicação com o servidor. Tente novamente.');
                });
        }
        function posGravarContinuar() {
            document.getElementById('modalPosGravar').style.display = 'none';
            if (ultimoIdGravado) {
                // Recarrega em modo de edição, para que a gravação seguinte seja um UPDATE
                window.location.href = 'termos_knx_form.php?id=' + ultimoIdGravado;
            }
        }
        function posGravarMenu() {
            window.location.href = 'termos_knx.php?sucesso=1';
        }

        // Enquanto se carregam os dados da BD, os "atualizadores" ficam em pausa
        // (fazem uma única atualização no fim, em vez de uma por cada linha — muito mais rápido)
        var aCarregarInicial = true;

        // Carregamento Inicial
        document.addEventListener('DOMContentLoaded', () => {
            // CORRIGIDO: cada linha é carregada isoladamente — se uma estiver estragada,
            // as restantes aparecem na mesma (antes um único erro escondia tudo)
            let errosCarregamento = 0;

            if (Array.isArray(DB_EQUIP) && DB_EQUIP.length > 0) {
                DB_EQUIP.forEach(eq => { try { addLinhaEquip(eq); } catch (e) { errosCarregamento++; console.error('Equipamento inválido:', eq, e); } });
            } else { addLinhaEquip(); }

            // Os dropdowns de Físico das associações precisam da lista de Hardware já carregada
            ultimaAssinaturaFisicos = getEnderecosFisicos().join('|');

            if (Array.isArray(DB_GRUPOS) && DB_GRUPOS.length > 0) {
                DB_GRUPOS.forEach(gr => { try { addLinhaGrupo(gr); } catch (e) { errosCarregamento++; console.error('Endereço de Grupo inválido:', gr, e); } });
            } else { addLinhaGrupo({}); }

            aCarregarInicial = false;

            // Atualização única no fim do carregamento
            atualizarContadoresAssoc();
            renderizarListaFisicosAssociar();
            atualizarDatalistGrupos();
            atualizarContagensSeccoes();

            if (errosCarregamento > 0) {
                alert('Atenção: ' + errosCarregamento + ' linha(s) deste termo não puderam ser carregadas. As restantes estão disponíveis.');
            }

            window.onclick = function(e) {
                if (e.target.classList.contains('modal')) { e.target.style.display = 'none'; }
            }
        });
    </script>
</body>
</html>