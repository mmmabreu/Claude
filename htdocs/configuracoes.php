<?php
session_start();
require_once 'db.php';
require_once 'acessos.php'; // Registo central de módulos (Matriz de Acessos automática)
// REGRA DE OURO 1: Validação de sessão
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}
$perfil_user = $_SESSION['perfil'] ?? '';
$is_admin = ($perfil_user === 'admin');
$is_adminvo = ($perfil_user === 'administrativo');
// REGRA DE OURO 2: Acesso Restrito (Admin e Administrativo)
if (!$is_admin && !$is_adminvo) {
    header("Location: index.php?erro=sem_permissao");
    exit;
}
// Buscar permissões dinâmicas
$stmt_perm = $pdo->prepare("SELECT modulo FROM permissoes WHERE grupo = :perfil AND pode_aceder = 1");
$stmt_perm->execute(['perfil' => $perfil_user]);
$meus_acessos = $stmt_perm->fetchAll(PDO::FETCH_COLUMN);
// REGRA DE OURO 3: 1º Nome no Avatar
$nome_completo = $_SESSION['nome'] ?? 'Utilizador';
$primeiro_nome = explode(' ', trim($nome_completo))[0];
$mensagem = ''; $tipo_mensagem = '';
// --- LÓGICA DE BACKUP DA BASE DE DADOS ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['btn_fazer_backup'])) {

    // Obter todas as tabelas
    $tables = [];
    $stmt = $pdo->query("SHOW TABLES");
    while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
        $tables[] = $row[0];
    }
    $sql_dump = "-- ==========================================\n";
    $sql_dump .= "-- Backup da Base de Dados - Gestão MAB\n";
    $sql_dump .= "-- Gerado em: " . date('d/m/Y H:i:s') . "\n";
    $sql_dump .= "-- ==========================================\n\n";
    $sql_dump .= "SET FOREIGN_KEY_CHECKS=0;\n\n";
    foreach ($tables as $table) {
        // Estrutura da Tabela
        $stmt = $pdo->query("SHOW CREATE TABLE `$table`");
        $row = $stmt->fetch(PDO::FETCH_NUM);
        $sql_dump .= "DROP TABLE IF EXISTS `$table`;\n";
        $sql_dump .= $row[1] . ";\n\n";
        // Dados da Tabela
        $stmt = $pdo->query("SELECT * FROM `$table`");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (count($rows) > 0) {
            $sql_dump .= "INSERT INTO `$table` (";
            $keys = array_keys($rows[0]);
            $keys_quoted = array_map(function($k) { return "`$k`"; }, $keys);
            $sql_dump .= implode(", ", $keys_quoted) . ") VALUES \n";

            $values_list = [];
            foreach ($rows as $r) {
                $row_vals = [];
                foreach ($r as $val) {
                    if (is_null($val)) {
                        $row_vals[] = "NULL";
                    } else {
                        // O PDO faz o quote e escape da string automaticamente e de forma segura
                        $row_vals[] = $pdo->quote($val);
                    }
                }
                $values_list[] = "(" . implode(", ", $row_vals) . ")";
            }
            $sql_dump .= implode(",\n", $values_list) . ";\n\n";
        }
    }
    $sql_dump .= "SET FOREIGN_KEY_CHECKS=1;\n";
    // Forçar o Download do Ficheiro SQL
    $filename = 'backup_mab_' . date('Y_m_d_His') . '.sql';
    header('Content-Type: application/sql');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($sql_dump));
    echo $sql_dump;
    exit;
}
// --- LÓGICA DE GRAVAÇÃO ---
if ($_SERVER['REQUEST_METHOD'] == 'POST') {

    // 1. Gravar Configurações Gerais (Só Admin)
    if (isset($_POST['btn_gravar_geral']) && $is_admin) {
        foreach ($_POST['config'] as $chave => $valor) {
            $stmt = $pdo->prepare("INSERT INTO configuracoes (chave, valor) VALUES (:chave, :valor) ON DUPLICATE KEY UPDATE valor = :valor");
            $stmt->execute(['chave' => $chave, 'valor' => $valor]);
        }
        $mensagem = "Configurações gerais atualizadas."; $tipo_mensagem = "sucesso";
    }
    // 2. Gravar Matriz de Acessos (Só Admin) — os módulos vêm da tabela modulos_sistema
    if (isset($_POST['btn_gravar_acessos']) && $is_admin) {
        acessosCriarTabela($pdo);
        $grupos = ['admin', 'administrativo', 'funcionario', 'externo'];

        // 2a. Nome / Pai / Ignorar de cada módulo (editados na própria matriz)
        if (isset($_POST['mod']) && is_array($_POST['mod'])) {
            $chaves_validas = $pdo->query("SELECT chave FROM modulos_sistema")->fetchAll(PDO::FETCH_COLUMN);
            $upd = $pdo->prepare("UPDATE modulos_sistema SET nome = :n, pai = :p, ativo = :a WHERE chave = :c");
            foreach ($_POST['mod'] as $chave => $dados) {
                if (!in_array($chave, $chaves_validas, true)) continue;
                $nome = trim($dados['nome'] ?? '');
                $pai = trim($dados['pai'] ?? '');
                if ($pai === $chave || !in_array($pai, $chaves_validas, true)) $pai = null;
                $ativo = !empty($dados['ignorar']) && $chave !== 'configuracoes' ? 0 : 1;
                if ($nome === '') $nome = acessosNomeAmigavel($chave);
                $upd->execute(['n' => $nome, 'p' => $pai, 'a' => $ativo, 'c' => $chave]);
            }
        }

        // 2b. Permissões de todos os módulos ativos
        $modulos = $pdo->query("SELECT chave FROM modulos_sistema WHERE ativo = 1")->fetchAll(PDO::FETCH_COLUMN);
        $stmt = $pdo->prepare("INSERT INTO permissoes (grupo, modulo, pode_aceder) VALUES (:g, :m, :p) ON DUPLICATE KEY UPDATE pode_aceder = :p");
        foreach ($grupos as $g) {
            foreach ($modulos as $m) {
                $pode = isset($_POST['perm'][$g][$m]) ? 1 : 0;
                if ($g === 'admin' && $m === 'configuracoes') $pode = 1; // o Admin nunca perde o acesso às Configurações
                $stmt->execute(['g' => $g, 'm' => $m, 'p' => $pode]);
            }
        }
        // Os módulos deixam de estar marcados como "Novo"
        $pdo->exec("UPDATE modulos_sistema SET novo = 0 WHERE novo = 1");
        $mensagem = "Matriz de acessos atualizada com sucesso."; $tipo_mensagem = "sucesso";
    }
    // 2c. Adicionar um módulo à mão (ex.: um formulário que a auto-deteção ignora)
    if (isset($_POST['btn_add_modulo']) && $is_admin) {
        $nova_chave = preg_replace('/[^a-z0-9_]/', '', strtolower(str_replace(['.php', '-', ' '], ['', '_', '_'], trim($_POST['nova_chave'] ?? ''))));
        if ($nova_chave !== '') {
            $ficheiro_novo = file_exists(__DIR__ . '/' . $nova_chave . '.php') ? $nova_chave . '.php' : null;
            registarModulo($pdo, $nova_chave, trim($_POST['novo_nome'] ?? '') ?: null, trim($_POST['novo_pai'] ?? '') ?: null, $ficheiro_novo);
            $mensagem = "Módulo adicionado à matriz. Defina as permissões e grave."; $tipo_mensagem = "sucesso";
        }
    }
    // 2d. Voltar a mostrar um módulo ignorado
    if (isset($_POST['btn_repor_modulo']) && $is_admin) {
        $pdo->prepare("UPDATE modulos_sistema SET ativo = 1, novo = 1 WHERE chave = ?")->execute([$_POST['btn_repor_modulo']]);
        $mensagem = "Módulo reposto na matriz."; $tipo_mensagem = "sucesso";
    }
    // 3. Gravar Matrículas da Frota (Só Admin)
    if (isset($_POST['btn_gravar_frota']) && $is_admin) {
        $frota_gravar = [];
        if (isset($_POST['matricula']) && is_array($_POST['matricula'])) {
            foreach ($_POST['matricula'] as $k => $m) {
                $m_clean = strtoupper(trim($m));
                $d_clean = trim($_POST['descricao_viatura'][$k] ?? '');
                // O campo 'ativa[]' é um input hidden (não uma checkbox) que acompanha
                // sempre cada linha, por isso o índice $k mantém-se sempre alinhado,
                // mesmo que se desmarque a checkbox "Ativa" de uma viatura no meio da lista.
                $ativa = isset($_POST['ativa'][$k]) && $_POST['ativa'][$k] == '1';
                if ($m_clean !== '') {
                    $frota_gravar[] = ['matricula' => $m_clean, 'descricao' => $d_clean, 'ativa' => $ativa];
                }
            }
        }
        $json_frota = json_encode($frota_gravar, JSON_UNESCAPED_UNICODE);
        $stmt = $pdo->prepare("INSERT INTO configuracoes (chave, valor) VALUES ('frota_matriculas', :valor) ON DUPLICATE KEY UPDATE valor = :valor");
        $stmt->execute(['valor' => $json_frota]);
        $mensagem = "Lista de viaturas atualizada com sucesso."; $tipo_mensagem = "sucesso";
    }

    // 4. Gravar Feriados, Pontes e Fechos (Só Admin)
    //    - 'dias_feriados'      = TODOS os dias em que a empresa está fechada (feriados + pontes).
    //                             Continua a ser a lista usada pelos mapas (não são dias úteis de trabalho).
    //    - 'dias_ponte_ferias'  = as pontes que a empresa marca e que CONTAM COMO FÉRIAS de todos os
    //                             funcionários (descontam no direito a férias de cada ficha).
    if (isset($_POST['btn_gravar_feriados']) && $is_admin) {
        $todos = []; $pontes = [];
        $datas = $_POST['feriados'] ?? [];
        $tipos = $_POST['feriados_tipo'] ?? [];
        if (is_array($datas)) {
            foreach ($datas as $k => $d) {
                $data_clean = trim($d);
                if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $data_clean)) continue;
                $todos[] = $data_clean;
                if (($tipos[$k] ?? '') === 'ponte') $pontes[] = $data_clean;
            }
        }
        // Remover datas repetidas e ordenar
        $todos = array_values(array_unique($todos));  sort($todos);
        $pontes = array_values(array_unique($pontes)); sort($pontes);

        $stmt = $pdo->prepare("INSERT INTO configuracoes (chave, valor) VALUES (:chave, :valor) ON DUPLICATE KEY UPDATE valor = :valor");
        $stmt->execute(['chave' => 'dias_feriados', 'valor' => json_encode($todos)]);
        $stmt->execute(['chave' => 'dias_ponte_ferias', 'valor' => json_encode($pontes)]);
        $mensagem = "Feriados, pontes e dias de fecho atualizados com sucesso."; $tipo_mensagem = "sucesso";
    }
}
// --- MATRIZ DE ACESSOS AUTOMÁTICA ---
// Sempre que as Configurações abrem, procura páginas .php novas na pasta do site
// e módulos registados pelas próprias páginas (exigirAcesso). Os novos aparecem
// na matriz com a etiqueta "Novo" e com acesso apenas para o Admin.
$novos_detetados = 0;
$modulos_lista = []; $modulos_ignorados = [];
if ($is_admin) {
    try {
        $novos_detetados = sincronizarModulos($pdo, __DIR__);
        $todos_mod = $pdo->query("SELECT * FROM modulos_sistema ORDER BY ordem ASC, nome ASC")->fetchAll(PDO::FETCH_ASSOC);
        $ativos = [];
        foreach ($todos_mod as $mod) {
            if ((int)$mod['ativo'] === 1) $ativos[$mod['chave']] = $mod; else $modulos_ignorados[] = $mod;
        }
        // Hierarquia: cada módulo principal seguido dos seus sub-módulos
        $filhos = [];
        foreach ($ativos as $c => $mod) {
            if (!empty($mod['pai']) && isset($ativos[$mod['pai']]) && empty($ativos[$mod['pai']]['pai'])) $filhos[$mod['pai']][] = $mod;
        }
        foreach ($ativos as $c => $mod) {
            if (!empty($mod['pai']) && isset($ativos[$mod['pai']]) && empty($ativos[$mod['pai']]['pai'])) continue;
            $mod['nivel'] = 0; $modulos_lista[] = $mod;
            foreach ($filhos[$c] ?? [] as $f) { $f['nivel'] = 1; $modulos_lista[] = $f; }
        }
    } catch (Exception $e) {
        $mensagem = "Não foi possível sincronizar os módulos: " . $e->getMessage(); $tipo_mensagem = "erro";
    }
}
$total_novos = count(array_filter($modulos_lista, fn($m) => (int)$m['novo'] === 1));
// Possíveis "pais" para o seletor (só módulos de 1º nível)
$opcoes_pai = array_filter($modulos_lista, fn($m) => $m['nivel'] === 0);

// Procurar valores atuais
$configs = $pdo->query("SELECT chave, valor FROM configuracoes")->fetchAll(PDO::FETCH_KEY_PAIR);
$permissoes_db = $pdo->query("SELECT grupo, modulo, pode_aceder FROM permissoes")->fetchAll(PDO::FETCH_ASSOC);
$perm = [];
foreach ($permissoes_db as $p) { $perm[$p['grupo']][$p['modulo']] = $p['pode_aceder']; }
// Decodificar Frota
$frota = json_decode($configs['frota_matriculas'] ?? '[]', true);
if (!is_array($frota)) $frota = [];
// Decodificar Feriados e Pontes
$feriados = json_decode($configs['dias_feriados'] ?? '[]', true);
if (!is_array($feriados)) $feriados = [];
$pontes_ferias = json_decode($configs['dias_ponte_ferias'] ?? '[]', true);
if (!is_array($pontes_ferias)) $pontes_ferias = [];
// Pontes gravadas que (por algum motivo) não estejam na lista geral também aparecem
$feriados = array_values(array_unique(array_merge($feriados, $pontes_ferias)));
// Ordenar datas por ordem cronológica para ser mais fácil de ler no ecrã
sort($feriados);

// Resumo por ano: nº de feriados e nº de pontes que contam como férias (só dias úteis)
$resumo_fechos = [];
$dias_semana_pt = ['', 'Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb', 'Dom'];
foreach ($feriados as $d) {
    $ano = substr($d, 0, 4);
    if (!isset($resumo_fechos[$ano])) $resumo_fechos[$ano] = ['feriados' => 0, 'pontes' => 0];
    if (in_array($d, $pontes_ferias)) {
        if ((int)date('N', strtotime($d)) <= 5) $resumo_fechos[$ano]['pontes']++;
    } else {
        $resumo_fechos[$ano]['feriados']++;
    }
}
krsort($resumo_fechos);
?>
<!DOCTYPE html>
<html lang="pt-PT">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Configurações - Gestão MAB</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { font-family: system-ui, -apple-system, sans-serif; box-sizing: border-box; }
        body { background-color: #f7f9fc; margin: 0; color: #333; font-size: 14px; padding-bottom: 50px; }
        input, select, textarea, button { font-family: inherit; font-size: 14px; outline: none; }

        /* CABEÇALHO (regras do projeto: logo -> início, sem ícones de navegação, voltar só com símbolo) */
        .topbar { background: rgba(255,255,255,0.94); backdrop-filter: blur(8px); -webkit-backdrop-filter: blur(8px); padding: 10px 40px; box-shadow: 0 1px 0 #e8ecf1; display: flex; justify-content: space-between; align-items: center; position: sticky; top: 0; z-index: 100; }
        .topbar-left a { display: block; }
        .topbar-left .logo { height: 35px; display: block; }
        .topbar-right { display: flex; align-items: center; gap: 12px; }
        .btn-voltar { width: 40px; height: 40px; border-radius: 50%; background: #fff; border: 1px solid #e8ecf1; color: #576574; display: inline-flex; align-items: center; justify-content: center; text-decoration: none; font-size: 16px; transition: all 0.2s ease; box-shadow: 0 2px 6px rgba(0,0,0,0.04); flex-shrink: 0; }
        .btn-voltar:hover { background: #eef2f6; color: #2c3e50; border-color: #d5dce4; transform: translateX(-2px); }
        .btn-voltar:active { transform: scale(0.94); }

        .user-menu { position: relative; }
        .user-btn { background: #fff; border: 1px solid #e8ecf1; border-radius: 30px; font-size: 14px; font-weight: 600; color: #2c3e50; cursor: pointer; display: flex; align-items: center; gap: 10px; padding: 5px 14px 5px 5px; transition: 0.2s; }
        .user-btn:hover { box-shadow: 0 4px 12px rgba(0,0,0,0.06); }
        .user-avatar { background: #e74c3c; color: white; width: 28px; height: 28px; border-radius: 50%; display: flex; justify-content: center; align-items: center; font-size: 13px; }

        .dropdown-content { display: none; position: absolute; right: 0; top: 100%; background: #fff; min-width: 180px; box-shadow: 0 12px 30px rgba(0,0,0,0.1); border-radius: 10px; border: 1px solid #e8ecf1; overflow: hidden; margin-top: 6px; z-index: 200; }
        .user-menu:hover .dropdown-content, .user-menu.aberto .dropdown-content { display: block; }
        .dropdown-content a { color: #576574; padding: 12px 20px; text-decoration: none; display: block; font-size: 14px; }
        .dropdown-content a:hover { background: #f4f6f8; color: #2c3e50; }

        .container { max-width: 1000px; margin: 40px auto; padding: 0 20px; }
        .alert { padding: 15px; border-radius: 8px; margin-bottom: 20px; text-align: center; font-weight: 600; }
        .alert-sucesso { background: #e8f5e9; color: #2e7d32; border: 1px solid #c8e6c9; }
        .tabs { display: flex; gap: 10px; margin-bottom: 25px; flex-wrap: wrap; }
        .tab-btn { background: #fff; color: #576574; border: 1px solid #dcdde1; padding: 12px 20px; border-radius: 8px; text-decoration: none; font-weight: 600; display: inline-flex; align-items: center; gap: 8px; transition: all 0.2s; cursor: pointer; font-size: 14px; }
        .tab-btn:hover, .tab-btn.active { border-color: #2c3e50; color: #2c3e50; background: #fdfdfd; }

        .tab-content { display: none; background: #fff; padding: 30px; border-radius: 12px; border: 1px solid #e1e5ea; box-shadow: 0 4px 15px rgba(0,0,0,0.04); }
        .tab-content.active { display: block; }
        .form-group { margin-bottom: 20px; display: flex; flex-direction: column; }
        label { font-weight: 600; margin-bottom: 8px; font-size: 14px; color: #2c3e50;}
        input { padding: 12px; border: 1px solid #dcdde1; border-radius: 8px; font-size: 15px; transition: border-color 0.2s;}
        input:focus { border-color: #2c3e50;}
        .table-responsive { width: 100%; overflow-x: auto; }
        .table-acessos { width: 100%; border-collapse: collapse; margin-top: 10px; min-width: 600px; }
        .table-acessos th, .table-acessos td { padding: 15px; border: 1px solid #f1f2f6; text-align: center; }
        .table-acessos th { background: #f8f9fa; font-size: 12px; text-transform: uppercase; color: #576574; border-bottom: 2px solid #e1e5ea; }
        .table-acessos td:first-child { text-align: left; font-weight: 600; background: #fcfcfc; color: #2c3e50; }
        .table-acessos tr.linha-nova td { background: #fffbea; }
        .table-acessos tr.linha-nova td:first-child { background: #fff6d6; }
        .table-acessos tr.sub td:first-child { padding-left: 38px; font-weight: 500; color: #576574; font-size: 13px; }
        .mod-nome { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
        .mod-ficheiro { font-size: 11px; color: #95a5a6; font-weight: 400; font-family: ui-monospace, monospace; }
        .tag-novo { background: #f1c40f; color: #5d4a00; font-size: 10px; font-weight: 800; padding: 2px 7px; border-radius: 10px; text-transform: uppercase; }
        .tag-falta { background: #fdedec; color: #c0392b; font-size: 10px; font-weight: 700; padding: 2px 7px; border-radius: 10px; }
        .btn-editar-mod { background: none; border: none; color: #b2bec3; cursor: pointer; padding: 2px 4px; font-size: 13px; margin-left: auto; }
        .btn-editar-mod:hover { color: #2c3e50; }
        .edit-mod { display: none; margin-top: 10px; gap: 8px; flex-wrap: wrap; align-items: center; font-weight: 400; }
        .edit-mod.aberto { display: flex; }
        .edit-mod input[type="text"], .edit-mod select { padding: 7px 9px; border: 1px solid #dcdde1; border-radius: 6px; font-size: 13px; }
        .edit-mod input[type="text"] { flex: 1; min-width: 150px; }
        .edit-mod label { font-size: 12px; margin: 0; font-weight: 600; color: #c0392b; display: flex; align-items: center; gap: 4px; cursor: pointer; }
        .aviso-novos { background: #fffbea; border: 1px solid #f9e79f; color: #7d6608; padding: 12px 15px; border-radius: 8px; margin-bottom: 15px; font-size: 13px; }
        .barra-matriz { display: flex; gap: 10px; flex-wrap: wrap; align-items: center; margin-bottom: 12px; }
        .barra-matriz input[type="search"] { flex: 1; min-width: 200px; padding: 9px 12px; font-size: 14px; }
        .btn-col { background: #f1f2f6; color: #576574; border: 1px solid #dcdde1; border-radius: 6px; padding: 3px 7px; font-size: 10px; cursor: pointer; margin-top: 4px; }
        .btn-col:hover { background: #e1e5ea; }
        .caixa-extra { margin-top: 25px; border-top: 1px dashed #dcdde1; padding-top: 20px; }
        .caixa-extra summary { cursor: pointer; font-weight: 700; color: #2c3e50; margin-bottom: 10px; }
        .form-add-mod { display: grid; grid-template-columns: 1fr 1.4fr 1fr auto; gap: 10px; align-items: end; margin-top: 12px; }
        .form-add-mod input, .form-add-mod select { padding: 10px; border: 1px solid #dcdde1; border-radius: 8px; font-size: 14px; }
        .form-add-mod label { font-size: 12px; margin-bottom: 4px; }
        .lista-ignorados { display: flex; flex-direction: column; gap: 6px; margin-top: 10px; }
        .lista-ignorados div { display: flex; justify-content: space-between; align-items: center; background: #f8f9fa; padding: 8px 12px; border-radius: 6px; font-size: 13px; }
        .btn-repor { background: #fff; border: 1px solid #dcdde1; border-radius: 6px; padding: 5px 10px; cursor: pointer; font-size: 12px; font-weight: 600; color: #27ae60; }
        .alert-erro { background: #fdedec; color: #c0392b; border: 1px solid #f5b7b1; }
        .btn-save { background: #2c3e50; color: #fff; padding: 12px 30px; border-radius: 8px; border: none; font-weight: 600; cursor: pointer; margin-top: 10px; transition: background 0.3s; display:inline-flex; align-items:center; gap:8px;}
        .btn-save:hover { background: #1a252f; }
        /* Estilos de inputs e linhas dinâmicas */
        .dyn-row { display: grid; gap: 15px; margin-bottom: 10px; align-items: center; }
        .btn-del { color: #e74c3c; background: none; border: none; cursor: pointer; font-size: 18px; padding: 5px; transition: 0.2s; }
        .btn-del:hover { color: #c0392b; transform: scale(1.1); }
        .btn-add { background: #d35400; color: #fff; border: none; padding: 10px 20px; border-radius: 8px; font-weight: 600; cursor: pointer; display: inline-flex; align-items: center; gap: 8px; transition: 0.2s; margin-bottom: 20px;}
        .btn-add:hover { background: #ba4a00; }
        /* Linha de viatura (Frota): matrícula + descrição + "Ativa" + apagar */
        .dyn-row-frota { grid-template-columns: 1fr 2fr 80px 40px; }
        .dyn-row-frota.linha-inativa { opacity: 0.5; }
        .col-ativa { display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 2px; }
        .col-ativa input[type="checkbox"] { width: 20px; height: 20px; accent-color: #27ae60; cursor: pointer; margin: 0; }
        .col-ativa span { font-size: 10px; color: #95a5a6; text-transform: uppercase; font-weight: 700; }
        .dyn-row-frota-header { font-size: 11px; color: #95a5a6; text-transform: uppercase; font-weight: 700; letter-spacing: 0.3px; margin-bottom: 8px; }

        /* Feriados e Pontes */
        .dyn-row-feriado { grid-template-columns: 170px 1fr 60px 40px; padding: 6px 8px; border-radius: 10px; border: 1px solid transparent; }
        .dyn-row-feriado select { padding: 11px 12px; border: 1px solid #dcdde1; border-radius: 8px; background: #fff; font-weight: 600; color: #b9770e; }
        .dyn-row-feriado.ponte { background: #eafaf1; border-color: #abebc6; }
        .dyn-row-feriado.ponte select { color: #1e8449; }
        .dia-semana { font-size: 12px; font-weight: 700; color: #95a5a6; text-align: center; }
        .dia-semana.fds { color: #c0392b; }
        .legenda-fechos { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 18px; }
        .legenda-fechos > div { border-radius: 10px; padding: 12px 14px; font-size: 13px; line-height: 1.45; }
        .leg-feriado { background: #fef9e7; border: 1px solid #f9e79f; color: #7d6608; }
        .leg-ponte { background: #eafaf1; border: 1px solid #abebc6; color: #1e8449; }
        .resumo-fechos { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 16px; }
        .resumo-fechos span { background: #f4f6f8; border: 1px solid #e1e5ea; border-radius: 20px; padding: 5px 12px; font-size: 12px; color: #576574; }
        .resumo-fechos b { color: #2c3e50; }
        @media (max-width: 1024px) { .topbar { padding: 10px 24px; } }
        @media (max-width: 560px) { .user-nome, .user-btn .fa-caret-down { display: none; } .user-btn { padding: 4px; } }
        @media (max-width: 768px) {
            .topbar { padding: 8px 15px; }
            .topbar-left .logo { height: 30px; }
            .btn-voltar { width: 38px; height: 38px; }
            .container { margin: 20px auto; padding: 0 12px; }
            .tab-content { padding: 18px; }
            .legenda-fechos { grid-template-columns: 1fr; }
            .dyn-row-feriado { grid-template-columns: 1fr 60px 40px !important; }
            .dyn-row-feriado select { grid-column: 1 / -1; grid-row: 2; }
            .tabs { width: 100%; }
            .tab-btn { flex: 1; justify-content: center; }
            .dyn-row { grid-template-columns: 1fr 40px !important; }
            .dyn-row input:nth-child(2) { grid-column: span 2; }
            .dyn-row-frota { grid-template-columns: 1fr 70px 40px !important; row-gap: 8px; }
            .dyn-row-frota input[name="matricula[]"] { grid-column: 1 / -1; }
            .dyn-row-frota-header { display: none; }
            .form-add-mod { grid-template-columns: 1fr; }
            .table-acessos th, .table-acessos td { padding: 10px 8px; }
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
        <h1 style="color:#2c3e50; margin-bottom: 25px; font-weight:800;">Configurações do Sistema</h1>

        <?php if ($mensagem): ?><div class="alert <?= $tipo_mensagem === 'erro' ? 'alert-erro' : 'alert-sucesso' ?>"><i class="fa-solid <?= $tipo_mensagem === 'erro' ? 'fa-triangle-exclamation' : 'fa-check-circle' ?>"></i> <?= htmlspecialchars($mensagem) ?></div><?php endif; ?>
        <div class="tabs">
            <?php if ($is_admin): ?>
                <button class="tab-btn active" onclick="openTab(event, 'geral')"><i class="fa-solid fa-sliders"></i> Geral e Valores</button>
                <button class="tab-btn" onclick="openTab(event, 'feriados')"><i class="fa-solid fa-calendar-xmark"></i> Feriados e Fecho</button>
                <button class="tab-btn" onclick="openTab(event, 'frota')"><i class="fa-solid fa-truck-pickup"></i> Frota</button>
                <button class="tab-btn" onclick="openTab(event, 'acessos')"><i class="fa-solid fa-shield-halved"></i> Matriz de Acessos<?php if ($total_novos > 0): ?> <span class="tag-novo"><?= $total_novos ?> novo<?= $total_novos > 1 ? 's' : '' ?></span><?php endif; ?></button>
            <?php endif; ?>
            <button class="tab-btn <?= (!$is_admin) ? 'active' : '' ?>" onclick="openTab(event, 'backup')"><i class="fa-solid fa-database"></i> Backup</button>
        </div>
        <?php if ($is_admin): ?>
        <div id="geral" class="tab-content active">
            <form method="POST" id="formConfigGeral">
                <div class="form-group">
                    <label>Último Número de Projeto (Obra/Orçamento)</label>
                    <input type="text" name="config[ultimo_numero_projeto]" value="<?= htmlspecialchars($configs['ultimo_numero_projeto'] ?? '') ?>" placeholder="Deixe em branco para auto-gerar">
                </div>
                <div class="form-group">
                    <label>Password de Segurança (para apagar registos)</label>
                    <input type="text" name="config[password_apagar]" value="<?= htmlspecialchars($configs['password_apagar'] ?? 'mab2026') ?>">
                </div>
                <div class="form-group">
                    <label>Valor Hora Padrão / Mão de Obra (€)</label>
                    <input type="number" step="0.10" name="config[valor_hora]" value="<?= htmlspecialchars($configs['valor_hora'] ?? '15.00') ?>">
                </div>
                <div class="form-group">
                    <label>Custo por KM (€)</label>
                    <input type="number" step="0.01" name="config[preco_km]" value="<?= htmlspecialchars($configs['preco_km'] ?? '0.40') ?>">
                </div>
                <div class="form-group">
                    <label>Valor da Refeição / Almoço (€)</label>
                    <input type="number" step="0.10" name="config[valor_almoco]" value="<?= htmlspecialchars($configs['valor_almoco'] ?? '7.50') ?>">
                </div>
                <button type="submit" name="btn_gravar_geral" class="btn-save"><i class="fa-solid fa-floppy-disk"></i> Gravar Alterações</button>
            </form>
        </div>
        <div id="feriados" class="tab-content">
            <form method="POST" id="formConfigFeriados">
                <p style="font-size: 14px; color: #7f8c8d; margin-top:0; margin-bottom: 15px;"><i class="fa-solid fa-circle-info"></i> Marque os dias em que a empresa está encerrada. Nenhum destes dias conta como dia útil de trabalho (7h diárias) no mapa mensal.</p>

                <div class="legenda-fechos">
                    <div class="leg-feriado"><strong><i class="fa-solid fa-flag"></i> Feriado / Fecho</strong><br>Feriados nacionais e municipais. Não contam como férias.</div>
                    <div class="leg-ponte"><strong><i class="fa-solid fa-umbrella-beach"></i> Ponte (conta como férias)</strong><br>Marcada pela empresa: desconta 1 dia de férias a <strong>todos os funcionários</strong> e aparece na ficha de cada um.</div>
                </div>

                <?php if (!empty($resumo_fechos)): ?>
                    <div class="resumo-fechos">
                        <?php foreach ($resumo_fechos as $ano => $r): ?>
                            <span><b><?= $ano ?>:</b> <?= $r['feriados'] ?> feriado(s) · <b style="color:#1e8449;"><?= $r['pontes'] ?> ponte(s)</b> a descontar nas férias</span>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <div id="wrap_feriados" style="max-width: 560px;">
                    <?php
                    $linhas_fechos = !empty($feriados) ? $feriados : [''];
                    foreach ($linhas_fechos as $data):
                        $e_ponte = $data !== '' && in_array($data, $pontes_ferias);
                        $n_dia = $data !== '' ? (int)date('N', strtotime($data)) : 0;
                    ?>
                        <div class="dyn-row dyn-row-feriado<?= $e_ponte ? ' ponte' : '' ?>">
                            <input type="date" name="feriados[]" value="<?= htmlspecialchars($data) ?>" required onchange="atualizarLinhaFeriado(this)">
                            <select name="feriados_tipo[]" onchange="atualizarLinhaFeriado(this)">
                                <option value="feriado" <?= !$e_ponte ? 'selected' : '' ?>>Feriado (não é dia de férias)</option>
                                <option value="ponte" <?= $e_ponte ? 'selected' : '' ?>>Ponte (é dia de férias)</option>
                            </select>
                            <span class="dia-semana<?= $n_dia >= 6 ? ' fds' : '' ?>"><?= $n_dia ? $dias_semana_pt[$n_dia] : '' ?></span>
                            <button type="button" class="btn-del" onclick="this.parentElement.remove()" title="Remover"><i class="fa-solid fa-trash-can"></i></button>
                        </div>
                    <?php endforeach; ?>
                </div>
                <div style="display:flex; gap:10px; flex-wrap:wrap;">
                    <button type="button" class="btn-add" onclick="addFeriado('feriado')" style="background:#f39c12;"><i class="fa-solid fa-flag"></i> Adicionar Feriado</button>
                    <button type="button" class="btn-add" onclick="addFeriado('ponte')" style="background:#27ae60;"><i class="fa-solid fa-umbrella-beach"></i> Adicionar Ponte (férias)</button>
                </div>
                <button type="submit" name="btn_gravar_feriados" class="btn-save"><i class="fa-solid fa-floppy-disk"></i> Gravar Calendário de Fechos</button>
            </form>
        </div>
        <div id="frota" class="tab-content">
            <form method="POST" id="formConfigFrota">
                <p style="font-size: 14px; color: #7f8c8d; margin-top:0; margin-bottom: 20px;"><i class="fa-solid fa-circle-info"></i> Defina aqui as matrículas da frota para controlo de quilómetros e consumos mensais. Desmarque "Ativa" para uma viatura emprestada, avariada ou temporariamente fora de uso — fica guardada na lista, mas deixa de aparecer para seleção.</p>

                <div class="dyn-row dyn-row-frota dyn-row-frota-header">
                    <span>Matrícula</span>
                    <span>Marca / Modelo</span>
                    <span style="text-align:center;">Ativa</span>
                    <span></span>
                </div>
                <div id="wrap_frota">
                    <?php if (!empty($frota)): foreach($frota as $v):
                        $v_ativa = !isset($v['ativa']) || $v['ativa']; // por omissão, viaturas antigas sem esta chave contam como ativas
                    ?>
                        <div class="dyn-row dyn-row-frota<?= $v_ativa ? '' : ' linha-inativa' ?>">
                            <input type="text" name="matricula[]" value="<?= htmlspecialchars($v['matricula']) ?>" placeholder="XX-XX-XX" style="text-transform: uppercase;">
                            <input type="text" name="descricao_viatura[]" value="<?= htmlspecialchars($v['descricao']) ?>" placeholder="Marca / Modelo (Opcional)">
                            <label class="col-ativa">
                                <input type="checkbox" <?= $v_ativa ? 'checked' : '' ?> onchange="toggleAtivaViatura(this)">
                                <input type="hidden" name="ativa[]" value="<?= $v_ativa ? '1' : '0' ?>">
                            </label>
                            <button type="button" class="btn-del" onclick="this.parentElement.remove()"><i class="fa-solid fa-trash-can"></i></button>
                        </div>
                    <?php endforeach; else: ?>
                        <div class="dyn-row dyn-row-frota">
                            <input type="text" name="matricula[]" placeholder="XX-XX-XX" style="text-transform: uppercase;">
                            <input type="text" name="descricao_viatura[]" placeholder="Marca / Modelo (Opcional)">
                            <label class="col-ativa">
                                <input type="checkbox" checked onchange="toggleAtivaViatura(this)">
                                <input type="hidden" name="ativa[]" value="1">
                            </label>
                            <button type="button" class="btn-del" onclick="this.parentElement.remove()"><i class="fa-solid fa-trash-can"></i></button>
                        </div>
                    <?php endif; ?>
                </div>
                <button type="button" class="btn-add" onclick="addViatura()"><i class="fa-solid fa-plus"></i> Adicionar Viatura</button>
                <br>
                <button type="submit" name="btn_gravar_frota" class="btn-save"><i class="fa-solid fa-floppy-disk"></i> Gravar Frota</button>
            </form>
        </div>
        <div id="acessos" class="tab-content">
            <form method="POST" id="formConfigAcessos">
                <p style="font-size: 14px; color: #7f8c8d; margin-top:0; margin-bottom: 15px;"><i class="fa-solid fa-circle-info"></i> Defina as permissões de visualização para cada grupo de utilizadores. <strong>A lista atualiza-se sozinha:</strong> as páginas novas do site aparecem aqui automaticamente (só com acesso para o Admin) até definir quem mais as pode ver.</p>

                <?php if ($total_novos > 0): ?>
                    <div class="aviso-novos"><i class="fa-solid fa-wand-magic-sparkles"></i> <strong><?= $total_novos ?> módulo(s) novo(s)</strong> detetado(s) (a amarelo). Confirme o nome com <i class="fa-solid fa-pen"></i>, marque os acessos e carregue em <strong>Gravar Permissões</strong>. Se não for uma página de menu, escolha <strong>Ignorar</strong>.</div>
                <?php endif; ?>

                <div class="barra-matriz">
                    <input type="search" id="filtroMatriz" placeholder="Filtrar módulos..." oninput="filtrarMatriz(this.value)">
                    <span style="font-size: 12px; color: #95a5a6;"><?= count($modulos_lista) ?> módulos</span>
                </div>

                <div class="table-responsive">
                    <table class="table-acessos" id="tabelaMatriz">
                        <thead>
                            <tr>
                                <th style="text-align:left;">Módulo</th>
                                <?php
                                $grupos = ['admin' => 'Admin', 'administrativo' => 'Administrativo', 'funcionario' => 'Funcionário', 'externo' => 'Externo'];
                                foreach ($grupos as $g => $rotulo): ?>
                                    <th><?= $rotulo ?><br><button type="button" class="btn-col" onclick="marcarColuna('<?= $g ?>')" title="Marcar / desmarcar toda a coluna">todos</button></th>
                                <?php endforeach; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($modulos_lista as $mod):
                                $key = $mod['chave'];
                                $e_novo = (int)$mod['novo'] === 1;
                                $em_falta = !empty($mod['ficheiro']) && $mod['origem'] !== 'base' && !file_exists(__DIR__ . '/' . $mod['ficheiro']);
                                $classes = trim(($mod['nivel'] ? 'sub ' : '') . ($e_novo ? 'linha-nova' : ''));
                                $chave_html = htmlspecialchars($key);
                            ?>
                                <tr class="<?= $classes ?>" data-procura="<?= htmlspecialchars(mb_strtolower($mod['nome'] . ' ' . $key . ' ' . $mod['ficheiro'])) ?>">
                                    <td>
                                        <div class="mod-nome">
                                            <?php if ($mod['nivel']): ?>
                                                <span style="color:#b2bec3;">↳</span>
                                            <?php else: ?>
                                                <i class="fa-solid <?= htmlspecialchars($mod['icone'] ?: 'fa-file-lines') ?>" style="color:<?= htmlspecialchars($mod['cor'] ?: '#7f8c8d') ?>; width:20px;"></i>
                                            <?php endif; ?>
                                            <?= $mod['nivel'] ? htmlspecialchars($mod['nome']) : '<strong>' . htmlspecialchars($mod['nome']) . '</strong>' ?>
                                            <?php if ($e_novo): ?><span class="tag-novo">Novo</span><?php endif; ?>
                                            <?php if ($em_falta): ?><span class="tag-falta" title="O ficheiro já não existe no servidor">ficheiro em falta</span><?php endif; ?>
                                            <?php if (!empty($mod['ficheiro'])): ?><span class="mod-ficheiro"><?= htmlspecialchars($mod['ficheiro']) ?></span><?php endif; ?>
                                            <button type="button" class="btn-editar-mod" onclick="this.closest('td').querySelector('.edit-mod').classList.toggle('aberto')" title="Editar nome / grupo"><i class="fa-solid fa-pen"></i></button>
                                        </div>
                                        <div class="edit-mod">
                                            <input type="text" name="mod[<?= $chave_html ?>][nome]" value="<?= htmlspecialchars($mod['nome']) ?>" placeholder="Nome a mostrar">
                                            <select name="mod[<?= $chave_html ?>][pai]" title="Sub-módulo de...">
                                                <option value="">— Módulo principal —</option>
                                                <?php foreach ($opcoes_pai as $op): if ($op['chave'] === $key) continue; ?>
                                                    <option value="<?= htmlspecialchars($op['chave']) ?>" <?= $mod['pai'] === $op['chave'] ? 'selected' : '' ?>>↳ de <?= htmlspecialchars($op['nome']) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                            <?php if ($key !== 'configuracoes'): ?>
                                                <label><input type="checkbox" name="mod[<?= $chave_html ?>][ignorar]" value="1"> Ignorar</label>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <?php foreach ($grupos as $g => $rotulo):
                                        $bloqueado = ($g == 'admin' && $key == 'configuracoes'); ?>
                                        <td>
                                            <input type="checkbox" class="chk-<?= $g ?>" name="perm[<?= $g ?>][<?= $chave_html ?>]" value="1"
                                            style="width: 18px; height: 18px; accent-color: #2c3e50; cursor: pointer;"
                                            <?= ($bloqueado || (isset($perm[$g][$key]) && $perm[$g][$key] == 1)) ? 'checked' : '' ?>
                                            <?= $bloqueado ? 'onclick="return false;" title="O Admin tem sempre acesso às Configurações"' : '' ?>>
                                        </td>
                                    <?php endforeach; ?>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <button type="submit" name="btn_gravar_acessos" class="btn-save"><i class="fa-solid fa-floppy-disk"></i> Gravar Permissões</button>
            </form>

            <div class="caixa-extra">
                <details>
                    <summary><i class="fa-solid fa-plus"></i> Adicionar módulo manualmente</summary>
                    <p style="font-size: 13px; color: #7f8c8d; margin: 0;">Para páginas que a deteção automática ignora (ex.: ficheiros terminados em <code>_form</code> ou <code>_pdf</code>). Indique o nome do ficheiro sem <code>.php</code>.</p>
                    <form method="POST" class="form-add-mod">
                        <div class="form-group" style="margin:0;"><label>Ficheiro / chave</label><input type="text" name="nova_chave" placeholder="ex.: vistoria_quadros_form" required></div>
                        <div class="form-group" style="margin:0;"><label>Nome a mostrar</label><input type="text" name="novo_nome" placeholder="ex.: Vistoria de Quadros"></div>
                        <div class="form-group" style="margin:0;"><label>Sub-módulo de</label>
                            <select name="novo_pai">
                                <option value="">— Módulo principal —</option>
                                <?php foreach ($opcoes_pai as $op): ?><option value="<?= htmlspecialchars($op['chave']) ?>"><?= htmlspecialchars($op['nome']) ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <button type="submit" name="btn_add_modulo" class="btn-save" style="margin:0;"><i class="fa-solid fa-plus"></i> Adicionar</button>
                    </form>
                </details>

                <?php if (!empty($modulos_ignorados)): ?>
                <details style="margin-top: 15px;">
                    <summary><i class="fa-solid fa-eye-slash"></i> Páginas ignoradas (<?= count($modulos_ignorados) ?>)</summary>
                    <form method="POST" class="lista-ignorados">
                        <?php foreach ($modulos_ignorados as $ig): ?>
                            <div>
                                <span><strong><?= htmlspecialchars($ig['nome']) ?></strong> <span class="mod-ficheiro"><?= htmlspecialchars($ig['ficheiro'] ?: $ig['chave']) ?></span></span>
                                <button type="submit" name="btn_repor_modulo" value="<?= htmlspecialchars($ig['chave']) ?>" class="btn-repor"><i class="fa-solid fa-rotate-left"></i> Repor</button>
                            </div>
                        <?php endforeach; ?>
                    </form>
                </details>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
        <div id="backup" class="tab-content <?= (!$is_admin) ? 'active' : '' ?>">
            <form method="POST">
                <p style="font-size: 14px; color: #7f8c8d; margin-top:0; margin-bottom: 20px;"><i class="fa-solid fa-circle-info"></i> O backup gera um ficheiro .sql com toda a estrutura e dados atuais das tabelas do sistema. Guarde este ficheiro num local seguro.</p>

                <div style="background: #fdfaf2; border: 1px solid #fae5d3; padding: 30px; border-radius: 12px; text-align: center; max-width: 600px; margin: 0 auto;">
                    <i class="fa-solid fa-server" style="font-size: 50px; color: #f39c12; margin-bottom: 15px;"></i>
                    <h3 style="color: #d35400; margin: 0 0 10px 0; font-size: 20px;">Backup Completo da Base de Dados</h3>
                    <p style="color: #7f8c8d; font-size: 14px; margin-bottom: 25px;">Inclui: Clientes, Obras, Orçamentos, Relatórios, Tabelas de Preços, Permissões e Histórico.</p>

                    <button type="submit" name="btn_fazer_backup" class="btn-save" style="background: #f39c12; color: #fff; font-size: 16px; padding: 15px 30px;"><i class="fa-solid fa-download"></i> Gerar e Descarregar Backup (.sql)</button>
                </div>
            </form>
        </div>
    </div>
    <script>
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
        // Script para adicionar nova linha de viatura
        function addViatura() {
            const w = document.getElementById('wrap_frota');
            const d = document.createElement('div');
            d.className = 'dyn-row dyn-row-frota';
            d.innerHTML = `
                <input type="text" name="matricula[]" placeholder="XX-XX-XX" style="text-transform: uppercase;">
                <input type="text" name="descricao_viatura[]" placeholder="Marca / Modelo (Opcional)">
                <label class="col-ativa">
                    <input type="checkbox" checked onchange="toggleAtivaViatura(this)">
                    <input type="hidden" name="ativa[]" value="1">
                </label>
                <button type="button" class="btn-del" onclick="this.parentElement.remove()"><i class="fa-solid fa-trash-can"></i></button>
            `;
            w.appendChild(d);
        }
        // Liga a checkbox "Ativa" (visual) ao input hidden "ativa[]" que é o que
        // realmente vai no POST, e esbate a linha quando a viatura fica inativa
        function toggleAtivaViatura(chk) {
            chk.nextElementSibling.value = chk.checked ? '1' : '0';
            chk.closest('.dyn-row-frota').classList.toggle('linha-inativa', !chk.checked);
        }
        // Adicionar novo Feriado ou Ponte
        function addFeriado(tipo) {
            const w = document.getElementById('wrap_feriados');
            const d = document.createElement('div');
            d.className = 'dyn-row dyn-row-feriado' + (tipo === 'ponte' ? ' ponte' : '');
            d.innerHTML = `
                <input type="date" name="feriados[]" required onchange="atualizarLinhaFeriado(this)">
                <select name="feriados_tipo[]" onchange="atualizarLinhaFeriado(this)">
                    <option value="feriado" ${tipo !== 'ponte' ? 'selected' : ''}>Feriado (não é dia de férias)</option>
                    <option value="ponte" ${tipo === 'ponte' ? 'selected' : ''}>Ponte (é dia de férias)</option>
                </select>
                <span class="dia-semana"></span>
                <button type="button" class="btn-del" onclick="this.parentElement.remove()" title="Remover"><i class="fa-solid fa-trash-can"></i></button>
            `;
            w.appendChild(d);
            d.querySelector('input').focus();
        }
        // Cor da linha (ponte a verde) e dia da semana
        function atualizarLinhaFeriado(el) {
            const row = el.closest('.dyn-row-feriado');
            const tipo = row.querySelector('select').value;
            const data = row.querySelector('input[type=date]').value;
            row.classList.toggle('ponte', tipo === 'ponte');
            const span = row.querySelector('.dia-semana');
            if (data) {
                const p = data.split('-');
                const dow = new Date(+p[0], +p[1] - 1, +p[2]).getDay();
                span.innerText = ['Dom', 'Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb'][dow];
                span.classList.toggle('fds', dow === 0 || dow === 6);
            } else {
                span.innerText = '';
            }
        }
        // Matriz: filtrar linhas pelo nome / ficheiro
        function filtrarMatriz(txt) {
            txt = (txt || '').toLowerCase().trim();
            document.querySelectorAll('#tabelaMatriz tbody tr').forEach(function (tr) {
                tr.style.display = (!txt || tr.dataset.procura.indexOf(txt) !== -1) ? '' : 'none';
            });
        }
        // Matriz: marcar / desmarcar uma coluna inteira (só as linhas visíveis)
        function marcarColuna(grupo) {
            const chks = Array.from(document.querySelectorAll('#tabelaMatriz .chk-' + grupo))
                .filter(c => c.closest('tr').style.display !== 'none' && !c.hasAttribute('onclick'));
            const marcar = chks.some(c => !c.checked);
            chks.forEach(c => c.checked = marcar);
        }
        // Abrir diretamente no separador da matriz quando há módulos novos ou após gravar
        <?php if ($is_admin && (isset($_POST['btn_gravar_acessos']) || isset($_POST['btn_add_modulo']) || isset($_POST['btn_repor_modulo']))): ?>
        document.addEventListener('DOMContentLoaded', function () {
            const btn = Array.from(document.querySelectorAll('.tab-btn')).find(b => b.getAttribute('onclick').indexOf("'acessos'") !== -1);
            if (btn) btn.click();
        });
        <?php endif; ?>
        document.getElementById('formConfigGeral')?.addEventListener('keydown', function(event) {
            if (event.key === 'Enter') { event.preventDefault(); }
        });
        document.getElementById('formConfigFrota')?.addEventListener('keydown', function(event) {
            if (event.key === 'Enter') { event.preventDefault(); }
        });
        document.getElementById('formConfigAcessos')?.addEventListener('keydown', function(event) {
            if (event.key === 'Enter') { event.preventDefault(); }
        });
        document.getElementById('formConfigFeriados')?.addEventListener('keydown', function(event) {
            if (event.key === 'Enter') { event.preventDefault(); }
        });
        // Depois de gravar os feriados, reabre esse separador
        <?php if ($is_admin && isset($_POST['btn_gravar_feriados'])): ?>
        document.addEventListener('DOMContentLoaded', function () {
            const btn = Array.from(document.querySelectorAll('.tab-btn')).find(b => b.getAttribute('onclick').indexOf("'feriados'") !== -1);
            if (btn) btn.click();
        });
        <?php endif; ?>
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