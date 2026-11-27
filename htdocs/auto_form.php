<?php
// FORÇAR EXIBIÇÃO DE ERROS BÁSICOS
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

try {
    session_start();
    require_once 'db.php';

    if (!isset($_SESSION['user_id'])) { 
        header("Location: index.php"); 
        exit; 
    }

    // Funções de Log
    if (file_exists('functions.php')) { 
        require_once 'functions.php'; 
    } else { 
        function registrarLog($pdo, $acao, $modulo) { return true; } 
    }

    $perfil_user = $_SESSION['perfil'] ?? '';
    $is_admin = in_array($perfil_user, ['admin', 'administrativo']);

    $stmt_perm = $pdo->prepare("SELECT modulo FROM permissoes WHERE grupo = :perfil AND pode_aceder = 1");
    $stmt_perm->execute(['perfil' => $perfil_user]);
    $meus_acessos = $stmt_perm->fetchAll(PDO::FETCH_COLUMN);

    if (!$is_admin && !in_array('obras', $meus_acessos)) {
        header("Location: index.php?erro=sem_permissao");
        exit;
    }

    // REGRA DE OURO: Obter apenas o primeiro nome
    $nome_completo = $_SESSION['nome'] ?? 'Utilizador';
    $primeiro_nome = explode(' ', trim($nome_completo))[0];

    // FUNÇÃO ROBUSTA PARA LER NÚMEROS COM PONTO OU VÍRGULA
    function parseInputNumber($val) {
        if (empty(trim((string)$val))) return 0;
        $val = trim((string)$val);
        if (strpos($val, ',') !== false && strpos($val, '.') !== false) {
            $lastComma = strrpos($val, ',');
            $lastDot = strrpos($val, '.');
            if ($lastComma > $lastDot) {
                $val = str_replace('.', '', $val);
                $val = str_replace(',', '.', $val);
            } else {
                $val = str_replace(',', '', $val);
            }
        } elseif (strpos($val, ',') !== false) {
            $val = str_replace(',', '.', $val);
        }
        return floatval(preg_replace('/[^0-9\.-]/', '', $val));
    }

    // Quantidade com 3 casas — em branco quando é 0 e a linha não tem unidade (títulos / capítulos)
    function fmtQtdLinha($valor, $unidade) {
        $valor = (float)$valor;
        if ($valor == 0 && trim((string)$unidade) === '') return '';
        return number_format($valor, 3, ',', '');
    }

    // Total de linha (Qtd × P. Unit) arredondado ao cêntimo de forma EXATA (meio cêntimo arredonda para cima).
    // O round() do PHP 8.4 sobre o produto em vírgula flutuante falha nos meios cêntimos
    // (ex.: 5 × 0,411 = 2,055 → dava 2,05 em vez de 2,06). Aqui a conta é feita em inteiros.
    if (!function_exists('totLinha')) {
        function totLinha($q, $p) {
            $qi = (int)round((float)$q * 1000);
            $pi = (int)round((float)$p * 1000);
            $prod = $qi * $pi;                          // em milionésimos
            if (!is_int($prod)) return round((float)$q * (float)$p, 2);   // valores enormes (não acontece na prática)
            $neg = $prod < 0;
            $c = intdiv(abs($prod) + 5000, 10000);       // cêntimos
            return ($neg ? -1 : 1) * $c / 100;
        }
    }
    // Total gravado é um "acerto manual"? (diferente do valor exato e do valor antigo com o arredondamento errado)
    if (!function_exists('totalFoiAcertado')) {
        function totalFoiAcertado($t, $q, $p) {
            return abs((float)$t - totLinha($q, $p)) >= 0.005 && abs((float)$t - round((float)$q * (float)$p, 2)) >= 0.005;
        }
    }

    // Valores de uma linha adjudicada. Se o Total Adj. foi acertado à mão (diferente de Qtd × P.Unit
    // arredondado), quando a linha fica completa (acumulado = adjudicado) o acumulado passa a ser
    // exatamente o Total Adj. e o mês absorve a diferença de cêntimos.
    // $ac_ant / $ac_acum: acerto de cêntimos do Saldo € (acumulado até ao auto anterior / até este auto).
    // O Acumulado € leva +$ac_acum e o mês leva a diferença ($ac_acum - $ac_ant).
    function valoresLinhaAdj($q_adj, $prec, $q_ant, $q_mes, $t_adj_manual = null, $ac_ant = 0.0, $ac_acum = 0.0) {
        $t_calc = totLinha($q_adj, $prec);
        $t_adj = ($t_manual_ok = ($t_adj_manual !== null && $t_adj_manual !== '')) ? round((float)$t_adj_manual, 2) : $t_calc;
        $acerto = $t_manual_ok && abs($t_adj - $t_calc) >= 0.005;
        $q_acum = $q_ant + $q_mes;
        $completo = $q_adj > 0 && abs($q_acum - $q_adj) < 0.0005;
        $ja_completo = $q_adj > 0 && abs($q_ant - $q_adj) < 0.0005;   // já estava completa em autos anteriores
        if ($acerto && $completo) {
            $t_acum = $t_adj;
            $t_mes = $ja_completo ? 0.0 : round($t_adj - totLinha($q_ant, $prec), 2);
        } else {
            $t_acum = totLinha($q_acum, $prec);
            $t_mes = totLinha($q_mes, $prec);
        }
        $ac_ant = round((float)$ac_ant, 2);
        $ac_acum = round((float)$ac_acum, 2);
        $t_acum = round($t_acum + $ac_acum, 2);
        $t_mes = round($t_mes + $ac_acum - $ac_ant, 2);
        return ['t_adj' => $t_adj, 't_acum' => $t_acum, 't_mes' => $t_mes, 'q_acum' => $q_acum, 'acerto' => $acerto];
    }

    // --- PASSWORD MESTRA (configurações → password_apagar), a mesma usada em autos.php ---
    $stmt_pass = $pdo->prepare("SELECT valor FROM configuracoes WHERE chave = 'password_apagar' LIMIT 1");
    $stmt_pass->execute();
    $pass_seguranca_sistema = (string)$stmt_pass->fetchColumn();
    if ($pass_seguranca_sistema === '') {
        $pass_seguranca_sistema = 'mab2026';
    }

    // Verificação por AJAX ao desbloquear uma descrição
    if ($_SERVER['REQUEST_METHOD'] == 'POST' && ($_POST['acao'] ?? '') === 'verificar_pw') {
        header('Content-Type: application/json; charset=utf-8');
        $ok = hash_equals($pass_seguranca_sistema, (string)($_POST['pw'] ?? ''));
        echo json_encode(['ok' => $ok]);
        exit;
    }

    // Linhas do orçamento da obra (o mesmo que o orçamento usa para o total:
    // linhas "Comercial" se existirem, senão as "Interno"). Devolve null se a obra não tiver orçamento.
    function orcamentoDaObra($pdo, $id_obra, $tipo_forcado = null) {
        if (empty($id_obra)) return null;
        try {
            $st = $pdo->prepare("SELECT referencia_obra FROM obras WHERE id = ?");
            $st->execute([$id_obra]);
            $ref = $st->fetchColumn();
            if (empty($ref)) return null;
            $st = $pdo->prepare("SELECT * FROM orcamentos WHERE numero_orcamento = ? LIMIT 1");
            $st->execute([$ref]);
            $orc = $st->fetch(PDO::FETCH_ASSOC);
            if (!$orc) return null;
            $st = $pdo->prepare("SELECT * FROM orcamento_itens WHERE orcamento_id = ? ORDER BY id ASC");
            $st->execute([$orc['id']]);
            $todas = array_values(array_filter($st->fetchAll(PDO::FETCH_ASSOC), fn($l) => empty($l['excluida'])));   // linhas excluídas não entram no auto
        } catch (Exception $e) { return null; }

        $tem_comercial = false;
        foreach ($todas as $l) {
            if (($l['tipo_linha'] ?? 'Interno') === 'Comercial' && trim((string)$l['designacao']) !== '') { $tem_comercial = true; break; }
        }
        $tipo = $tipo_forcado ?: ($tem_comercial ? 'Comercial' : 'Interno');

        $linhas = [];
        $total = 0;
        foreach ($todas as $l) {
            if (($l['tipo_linha'] ?? 'Interno') !== $tipo) continue;
            if (trim((string)$l['designacao']) === '' && trim((string)$l['artigo']) === '' && (float)$l['quantidade'] == 0) continue;
            $q = (float)$l['quantidade'];
            $p = (float)$l['preco_unitario_venda'];
            $total += totLinha($q, $p);
            $linhas[] = [
                'artigo' => (string)$l['artigo'], 'designacao' => (string)$l['designacao'], 'unidade' => (string)$l['unidade'],
                'qtd' => $q, 'preco' => $p,
            ];
        }
        return [
            'numero' => (string)$orc['numero_orcamento'],
            'id' => (int)$orc['id'],
            'tipo' => $tipo,
            'total_linhas' => round($total, 2),
            'total_gravado' => isset($orc['valor_total_liquido']) ? (float)$orc['valor_total_liquido'] : null,
            // Total a mostrar: o que está gravado no orçamento (o que o cliente recebeu); se não houver, a soma das linhas
            'total' => (isset($orc['valor_total_liquido']) && (float)$orc['valor_total_liquido'] > 0) ? (float)$orc['valor_total_liquido'] : round($total, 2),
            'linhas' => $linhas,
        ];
    }

    // ORÇAMENTOS COMPLEMENTARES adjudicados da obra que ainda não entraram em nenhum auto.
    // Cada um devolve as suas linhas (Comercial se existirem, senão Interno) para entrarem nos Suplementares.
    function complementaresPendentes($pdo, $id_obra, $id_orc_origem) {
        if (empty($id_obra) || empty($id_orc_origem)) return [];
        try {
            $st = $pdo->prepare("SELECT * FROM orcamentos WHERE id_orcamento_pai = ? AND estado = 'Adjudicado' ORDER BY id ASC");
            $st->execute([$id_orc_origem]);
            $comps = $st->fetchAll(PDO::FETCH_ASSOC);
            if (!$comps) return [];
            $st = $pdo->prepare("SELECT DISTINCT ai.id_orc_comp FROM auto_itens ai JOIN autos a ON a.id = ai.id_auto WHERE a.id_obra = ? AND ai.id_orc_comp IS NOT NULL");
            $st->execute([$id_obra]);
            $ja = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
        } catch (Exception $e) { return []; }
        $res = [];
        foreach ($comps as $c) {
            if (in_array((int)$c['id'], $ja, true)) continue;
            $st = $pdo->prepare("SELECT * FROM orcamento_itens WHERE orcamento_id = ? ORDER BY id ASC");
            $st->execute([$c['id']]);
            $todas = array_values(array_filter($st->fetchAll(PDO::FETCH_ASSOC), fn($l) => empty($l['excluida'])));   // linhas excluídas não entram no auto
            $tem_com = false;
            foreach ($todas as $l) if (($l['tipo_linha'] ?? 'Interno') === 'Comercial' && trim((string)$l['designacao']) !== '') { $tem_com = true; break; }
            $tipo = $tem_com ? 'Comercial' : 'Interno';
            $linhas = []; $total = 0;
            foreach ($todas as $l) {
                if (($l['tipo_linha'] ?? 'Interno') !== $tipo) continue;
                if (trim((string)$l['designacao']) === '' && trim((string)$l['artigo']) === '' && (float)$l['quantidade'] == 0) continue;
                $q = (float)$l['quantidade']; $p = (float)$l['preco_unitario_venda'];
                $total += totLinha($q, $p);
                $linhas[] = ['artigo' => (string)$l['artigo'], 'designacao' => (string)$l['designacao'], 'unidade' => (string)$l['unidade'], 'qtd' => $q, 'preco' => $p];
            }
            $res[] = ['id' => (int)$c['id'], 'numero' => (string)$c['numero_orcamento'], 'total' => round($total, 2), 'linhas' => $linhas];
        }
        return $res;
    }

    $mensagem = ''; 
    $tipo_mensagem = '';
    if (isset($_GET['editado'])) {
        $mensagem = "Auto de medição guardado com sucesso!";
        $tipo_mensagem = "sucesso";
    }

    // --- PREPARAÇÃO E CORREÇÃO AUTOMÁTICA DA BASE DE DADOS ---
    try {
        $col_check = $pdo->query("SHOW COLUMNS FROM auto_itens LIKE 'tipo'");
        if ($col_check->rowCount() == 0) {
            $pdo->exec("ALTER TABLE auto_itens ADD COLUMN tipo VARCHAR(50) DEFAULT 'Adjudicado' AFTER id_auto");
        }
        $col_check_mes = $pdo->query("SHOW COLUMNS FROM autos LIKE 'mes_referencia'");
        if ($col_check_mes->rowCount() == 0) {
            $pdo->exec("ALTER TABLE autos ADD COLUMN mes_referencia VARCHAR(100) DEFAULT NULL AFTER data_auto");
        }
        
        // ADICIONAR COLUNA OBSERVACOES CASO NÃO EXISTA
        $col_check_obs = $pdo->query("SHOW COLUMNS FROM autos LIKE 'observacoes'");
        if ($col_check_obs->rowCount() == 0) {
            $pdo->exec("ALTER TABLE autos ADD COLUMN observacoes TEXT DEFAULT NULL AFTER mes_referencia");
        }
        
        // FORÇAR AS COLUNAS DE QUANTIDADES PARA 3 CASAS DECIMAIS NO MYSQL
        $pdo->exec("ALTER TABLE auto_itens 
                    MODIFY COLUMN qtd_adjudicada DECIMAL(12,3), 
                    MODIFY COLUMN qtd_anterior DECIMAL(12,3), 
                    MODIFY COLUMN qtd_mes DECIMAL(12,3), 
                    MODIFY COLUMN qtd_acumulada DECIMAL(12,3), 
                    MODIFY COLUMN saldo_qtd DECIMAL(12,3)");
    } catch(Exception $e) { }
    // PREÇO UNITÁRIO COM 3 CASAS DECIMAIS (como nas adjudicações dos clientes)
    try {
        $col_pu = $pdo->query("SHOW COLUMNS FROM auto_itens LIKE 'preco_unitario'")->fetch(PDO::FETCH_ASSOC);
        if ($col_pu && stripos($col_pu['Type'], ',3)') === false && stripos($col_pu['Type'], ',4)') === false) {
            $pdo->exec("ALTER TABLE auto_itens MODIFY COLUMN preco_unitario DECIMAL(12,3)");
        }
    } catch(Exception $e) { }

    // LIGAÇÃO DAS LINHAS SUPLEMENTARES AO ORÇAMENTO COMPLEMENTAR DE ONDE VIERAM
    try {
        if ($pdo->query("SHOW COLUMNS FROM auto_itens LIKE 'id_orc_comp'")->rowCount() == 0) {
            $pdo->exec("ALTER TABLE auto_itens ADD COLUMN id_orc_comp INT NULL DEFAULT NULL");
        }
    } catch(Exception $e) { }

    // ACERTO DE CÊNTIMOS DO SALDO € (acumulado até ao auto anterior e até este auto)
    try {
        $pdo->exec("ALTER TABLE auto_itens ADD COLUMN IF NOT EXISTS acerto_ant DECIMAL(12,2) NOT NULL DEFAULT 0");
        $pdo->exec("ALTER TABLE auto_itens ADD COLUMN IF NOT EXISTS acerto_acum DECIMAL(12,2) NOT NULL DEFAULT 0");
    } catch(Exception $e) {
        try {
            if ($pdo->query("SHOW COLUMNS FROM auto_itens LIKE 'acerto_ant'")->rowCount() == 0) $pdo->exec("ALTER TABLE auto_itens ADD COLUMN acerto_ant DECIMAL(12,2) NOT NULL DEFAULT 0");
            if ($pdo->query("SHOW COLUMNS FROM auto_itens LIKE 'acerto_acum'")->rowCount() == 0) $pdo->exec("ALTER TABLE auto_itens ADD COLUMN acerto_acum DECIMAL(12,2) NOT NULL DEFAULT 0");
        } catch(Exception $e2) { }
    }

    // --- GERAR LISTA DE MESES (12 ANTES, 6 DEPOIS) NO FORMATO MM/AA ---
    $opcoes_meses = [];
    for ($i = -12; $i <= 6; $i++) {
        $timestamp = strtotime("$i month");
        $label = date('m/y', $timestamp);
        $opcoes_meses[] = $label;
    }
    $mes_atual_formatado = date('m/y');

    $id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
    $modo_editar = !empty($id);

    // --- 1. LÓGICA DE GRAVAÇÃO (CREATE / UPDATE) ---
    if ($_SERVER['REQUEST_METHOD'] == 'POST' && (isset($_POST['btn_guardar_auto']) || isset($_POST['btn_guardar_imprimir']))) {
        try {
            // --- PROTEÇÃO DAS DESCRIÇÕES ADJUDICADAS E DAS QUANTIDADES ANTERIORES ---
            // Cada linha que já existia leva o seu índice (idx). Se a descrição (adjudicados) ou a Qtd Ant.
            // (adjudicados e suplementares) for diferente da original, só é aceite com a password mestra
            // correta (validada aqui, no servidor).
            $itens_post = json_decode($_POST['itens_json'] ?? '[]', true);
            if (!is_array($itens_post)) $itens_post = [];
            $chave_form = $modo_editar ? 'e' . $id : 'n' . (int)($_POST['id_obra'] ?? 0);
            $orig = $_SESSION['auto_orig'][$chave_form] ?? null;
            if ($orig === null && $modo_editar) {
                $orig = ['adj' => [], 'sup' => []];
                $st_o = $pdo->prepare("SELECT * FROM auto_itens WHERE id_auto = ? ORDER BY id ASC");
                $st_o->execute([$id]);
                foreach ($st_o->fetchAll(PDO::FETCH_ASSOC) as $o) {
                    $lin = ['d' => (string)$o['designacao'], 'a' => (float)$o['qtd_anterior'], 'u' => (string)$o['unidade'], 'q' => (float)$o['qtd_adjudicada'], 'p' => (float)$o['preco_unitario'], 't' => (float)$o['total_adjudicado'],
                            'ka' => (float)($o['acerto_ant'] ?? 0), 'k' => (float)($o['acerto_acum'] ?? 0)];
                    if ($o['tipo'] === 'Suplementar') $orig['sup'][] = $lin; else $orig['adj'][] = $lin;
                }
            }
            $desc_alteradas = 0;
            $ant_alteradas = 0;
            $linhas_trocadas = 0;
            $totais_acertados = 0;
            $saldos_acertados = 0;
            foreach ($itens_post as $item) {
                if (!isset($item['idx']) || $item['idx'] === '' || $item['idx'] === null || !is_array($orig)) continue;
                $grupo = (($item['tipo'] ?? '') === 'Suplementar') ? 'sup' : 'adj';
                $ix = (int)$item['idx'];
                if (!isset($orig[$grupo][$ix])) continue;
                $o = $orig[$grupo][$ix];
                if ($grupo === 'adj' && trim(str_replace("\r", '', (string)$item['designacao'])) !== trim(str_replace("\r", '', $o['d']))) {
                    $desc_alteradas++;
                }
                if (abs(parseInputNumber($item['qtd_ant'] ?? '0') - (float)$o['a']) > 0.0005) {
                    $ant_alteradas++;
                }
                if ($grupo === 'adj' && array_key_exists('u', $o) && (
                        trim((string)($item['unidade'] ?? '')) !== trim($o['u'])
                        || abs(parseInputNumber($item['qtd_adj'] ?? '0') - (float)$o['q']) > 0.0005
                        || abs(parseInputNumber($item['preco_un'] ?? '0') - (float)$o['p']) > 0.0005)) {
                    $linhas_trocadas++;
                }
                $man = trim((string)($item['total_adj_manual'] ?? ''));
                if (array_key_exists('t', $o) && $man !== '' && abs(round((float)$man, 2) - (float)$o['t']) >= 0.005) {
                    $totais_acertados++;
                }
                if (array_key_exists('k', $o) && abs(round((float)($item['ac_acum'] ?? $o['k']), 2) - (float)$o['k']) >= 0.005) {
                    $saldos_acertados++;
                }
            }
            $pw_ok = hash_equals($pass_seguranca_sistema, (string)($_POST['pw_mestra'] ?? ''));
            if (($desc_alteradas + $ant_alteradas + $linhas_trocadas + $totais_acertados + $saldos_acertados) > 0 && !$pw_ok) {
                throw new Exception("Foram alteradas descrições, quantidades, preços, totais ou saldos de linhas existentes sem a password mestra correta. Nada foi gravado.");
            }

            $pdo->beginTransaction();

            $id_obra = $_POST['id_obra'];
            $numero_auto = $_POST['numero_auto'];
            $data_auto = $_POST['data_auto'];
            $mes_referencia = $_POST['mes_referencia'];
            $observacoes = trim($_POST['observacoes'] ?? '');
            
            $total_mes_geral = 0;
            $total_acumulado_geral = 0;

            if ($modo_editar) {
                $sql_auto = "UPDATE autos SET id_obra = ?, numero_auto = ?, data_auto = ?, mes_referencia = ?, observacoes = ? WHERE id = ?";
                $stmt_auto = $pdo->prepare($sql_auto);
                $stmt_auto->execute([$id_obra, $numero_auto, $data_auto, $mes_referencia, $observacoes, $id]);
                $id_auto_novo = $id;
                
                // Limpar itens antigos antes de reescrever
                $pdo->prepare("DELETE FROM auto_itens WHERE id_auto = ?")->execute([$id_auto_novo]);
            } else {
                $sql_auto = "INSERT INTO autos (numero_auto, id_obra, data_auto, mes_referencia, observacoes) VALUES (?, ?, ?, ?, ?)";
                $stmt_auto = $pdo->prepare($sql_auto);
                $stmt_auto->execute([$numero_auto, $id_obra, $data_auto, $mes_referencia, $observacoes]);
                $id_auto_novo = $pdo->lastInsertId();
            }

            if (!empty($itens_post)) {
                $sql_item = "INSERT INTO auto_itens (id_auto, tipo, artigo, designacao, unidade, qtd_adjudicada, preco_unitario, total_adjudicado, qtd_anterior, qtd_mes, qtd_acumulada, total_acumulado, saldo_qtd, acerto_ant, acerto_acum, id_orc_comp) 
                             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                $stmt_item = $pdo->prepare($sql_item);

                foreach ($itens_post as $item) {
                    if (trim($item['designacao']) !== '') {
                        $tipo = $item['tipo'] ?? 'Adjudicado';
                        $artigo = $item['artigo'] ?? '';
                        $desig = $item['designacao'] ?? '';
                        $un = $item['unidade'] ?? '';
                        
                        // Parse robusto dos números com ponto ou vírgula
                        $q_adj = parseInputNumber($item['qtd_adj'] ?? '0');
                        $prec = round(parseInputNumber($item['preco_un'] ?? '0'), 3);
                        $q_ant = parseInputNumber($item['qtd_ant'] ?? '0');
                        $q_mes = parseInputNumber($item['qtd_mes'] ?? '0');
                        
                        // Acerto de cêntimos do saldo: o "anterior" vem do auto anterior (guardado no servidor);
                        // só se aceita o valor enviado pelo browser quando a password mestra foi confirmada.
                        $grp = ($tipo === 'Suplementar') ? 'sup' : 'adj';
                        $ixi = (isset($item['idx']) && $item['idx'] !== '' && $item['idx'] !== null) ? (int)$item['idx'] : null;
                        $o_l = ($ixi !== null && is_array($orig) && isset($orig[$grp][$ixi])) ? $orig[$grp][$ixi] : null;
                        $ac_ant = $pw_ok ? (float)($item['ac_ant'] ?? 0) : (float)($o_l['ka'] ?? 0);
                        $ac_acum = isset($item['ac_acum']) && $item['ac_acum'] !== '' ? (float)$item['ac_acum'] : $ac_ant;
                        $v = valoresLinhaAdj($q_adj, $prec, $q_ant, $q_mes, $item['total_adj_manual'] ?? null, $ac_ant, $ac_acum);
                        $t_adj = $v['t_adj']; $q_acum = $v['q_acum']; $t_acum = $v['t_acum']; $t_mes = $v['t_mes'];
                        $saldo = $q_adj - $q_acum;

                        $stmt_item->execute([
                            $id_auto_novo, $tipo, $artigo, $desig, $un, $q_adj, $prec, $t_adj, $q_ant, $q_mes, $q_acum, $t_acum, $saldo, round($ac_ant, 2), round($ac_acum, 2),
                            (!empty($item['orc_comp']) ? (int)$item['orc_comp'] : null)
                        ]);

                        $total_mes_geral += $t_mes;
                        $total_acumulado_geral += $t_acum;
                    }
                }
            }

            $pdo->prepare("UPDATE autos SET valor_total_mes = ?, valor_total_acumulado = ? WHERE id = ?")
                ->execute([$total_mes_geral, $total_acumulado_geral, $id_auto_novo]);

            $pdo->commit();
            registrarLog($pdo, ($modo_editar ? "Editou" : "Criou") . " Auto Medição #$numero_auto (Obra ID: $id_obra)", "Autos");
            if ($desc_alteradas > 0) {
                registrarLog($pdo, "Alterou $desc_alteradas descrição(ões) adjudicada(s) com password mestra no Auto #$numero_auto (Obra ID: $id_obra)", "Autos");
            }
            if ($ant_alteradas > 0) {
                registrarLog($pdo, "Acertou $ant_alteradas quantidade(s) anterior(es) com password mestra no Auto #$numero_auto (Obra ID: $id_obra)", "Autos");
            }
            if ($saldos_acertados > 0) {
                registrarLog($pdo, "Acertou o Saldo € de $saldos_acertados linha(s) com password mestra no Auto #$numero_auto (Obra ID: $id_obra)", "Autos");
            }
            if ($totais_acertados > 0) {
                registrarLog($pdo, "Acertou o Total Adj. de $totais_acertados linha(s) com password mestra no Auto #$numero_auto (Obra ID: $id_obra)", "Autos");
            }
            if ($linhas_trocadas > 0) {
                registrarLog($pdo, "Corrigiu un./quantidade/preço de $linhas_trocadas linha(s) adjudicada(s) com password mestra no Auto #$numero_auto (Obra ID: $id_obra)", "Autos");
            }
            unset($_SESSION['auto_orig'][$chave_form]);
            
            if (isset($_POST['btn_guardar_imprimir'])) {
                header("Location: auto_imprimir.php?id=" . $id_auto_novo);
            } else {
                header("Location: auto_form.php?id=" . $id_auto_novo . "&editado=1");
            }
            exit;

        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $mensagem = "Erro ao guardar: " . $e->getMessage();
            $tipo_mensagem = "erro";
        }
    }

    // --- 2. LÓGICA DE CARREGAMENTO (GET) ---
    $sql_obras = "SELECT o.id, o.nome, o.referencia_obra, o.morada, c.nome as cliente_nome, c.nif as cliente_nif 
                  FROM obras o 
                  JOIN clientes c ON o.id_cliente = c.id 
                  WHERE o.estado != 'Concluída'
                  ORDER BY o.nome ASC";
    $obras = $pdo->query($sql_obras)->fetchAll(PDO::FETCH_ASSOC);

    $proximo_numero = 1;
    $itens_render_adj = [];
    $itens_render_sup = [];
    $obra_selecionada = null;

    if ($modo_editar) {
        // ESTAMOS A EDITAR UM AUTO EXISTENTE
        $stmt_auto = $pdo->prepare("SELECT * FROM autos WHERE id = ?");
        $stmt_auto->execute([$id]);
        $auto_atual = $stmt_auto->fetch(PDO::FETCH_ASSOC);

        if (!$auto_atual) {
            header("Location: autos.php");
            exit;
        }

        $titulo_pagina = "Editar Auto #" . $auto_atual['numero_auto'];
        $val_id_obra = $auto_atual['id_obra'];
        $val_numero_auto = $auto_atual['numero_auto'];
        $val_data_auto = $auto_atual['data_auto'];
        $val_mes_referencia = $auto_atual['mes_referencia'];
        $val_observacoes = $auto_atual['observacoes'] ?? '';

        // Verificar se é o ÚLTIMO auto desta obra
        $stmt_check_last = $pdo->prepare("SELECT MAX(numero_auto) FROM autos WHERE id_obra = ?");
        $stmt_check_last->execute([$val_id_obra]);
        $ultimo_auto_da_obra = $stmt_check_last->fetchColumn();
        $pode_editar_suplementares = ($val_numero_auto == $ultimo_auto_da_obra);

        $stmt_items = $pdo->prepare("SELECT * FROM auto_itens WHERE id_auto = ? ORDER BY id ASC");
        $stmt_items->execute([$id]);
        $itens_db = $stmt_items->fetchAll(PDO::FETCH_ASSOC);

        foreach ($itens_db as $it) {
            $mapped = [
                'artigo' => $it['artigo'], 
                'designacao' => $it['designacao'], 
                'unidade' => $it['unidade'], 
                'qtd_adj' => $it['qtd_adjudicada'], 
                'preco_un' => $it['preco_unitario'], 
                'qtd_ant' => $it['qtd_anterior'], 
                'qtd_mes' => $it['qtd_mes'],
                'total_adj' => $it['total_adjudicado'],
                'ac_ant' => (float)($it['acerto_ant'] ?? 0),
                'ac_acum' => (float)($it['acerto_acum'] ?? 0),
                'orc_comp' => $it['id_orc_comp'] ?? null
            ];
            if ($it['tipo'] === 'Suplementar') {
                $itens_render_sup[] = $mapped;
            } else {
                $itens_render_adj[] = $mapped;
            }
        }

    } else {
        // ESTAMOS A CRIAR UM NOVO AUTO
        $titulo_pagina = "Novo Auto";
        $val_id_obra = $_GET['obra_id'] ?? '';
        $val_numero_auto = 1;
        $val_data_auto = date('Y-m-d');
        $val_mes_referencia = $mes_atual_formatado;
        $val_observacoes = '';
        $pode_editar_suplementares = true; // Sendo novo, pode sempre editar

        if (!empty($val_id_obra)) {
            foreach($obras as $o) if($o['id'] == $val_id_obra) $obra_selecionada = $o;
            
            $stmt_last_auto = $pdo->prepare("SELECT id FROM autos WHERE id_obra = ? ORDER BY numero_auto DESC LIMIT 1");
            $stmt_last_auto->execute([$val_id_obra]);
            $ultimo_auto_id = $stmt_last_auto->fetchColumn();

            if ($ultimo_auto_id) {
                // JÁ TEM AUTOS: Herdar quantidades acumuladas do último auto
                $stmt_prox = $pdo->prepare("SELECT MAX(numero_auto) FROM autos WHERE id_obra = ?");
                $stmt_prox->execute([$val_id_obra]);
                $val_numero_auto = ($stmt_prox->fetchColumn() ?: 0) + 1;

                $stmt_items = $pdo->prepare("SELECT * FROM auto_itens WHERE id_auto = ? ORDER BY id ASC");
                $stmt_items->execute([$ultimo_auto_id]);
                $itens_anteriores = $stmt_items->fetchAll();

                foreach ($itens_anteriores as $it) {
                    $mapped = [
                        'artigo' => $it['artigo'], 
                        'designacao' => $it['designacao'], 
                        'unidade' => $it['unidade'], 
                        'qtd_adj' => $it['qtd_adjudicada'], 
                        'preco_un' => $it['preco_unitario'], 
                        'qtd_ant' => $it['qtd_acumulada'], 
                        'qtd_mes' => 0,
                        'total_adj' => $it['total_adjudicado'],
                        'ac_ant' => (float)($it['acerto_acum'] ?? 0),    // o acumulado do auto anterior passa a "anterior"
                        'ac_acum' => (float)($it['acerto_acum'] ?? 0),
                        'orc_comp' => $it['id_orc_comp'] ?? null
                    ];
                    if ($it['tipo'] === 'Suplementar') $itens_render_sup[] = $mapped; else $itens_render_adj[] = $mapped;
                }
            } else {
                // NÃO TEM AUTOS: Importar automaticamente do orçamento adjudicado à obra
                // (as mesmas linhas que o orçamento usa para o seu total)
                $tipo_import = in_array($_GET['tipo_import'] ?? '', ['Comercial', 'Interno']) ? $_GET['tipo_import'] : null;
                $orc_import = orcamentoDaObra($pdo, $val_id_obra, $tipo_import);
                if ($orc_import) {
                    foreach ($orc_import['linhas'] as $it) {
                        $itens_render_adj[] = [
                            'artigo' => $it['artigo'], 
                            'designacao' => $it['designacao'], 
                            'unidade' => $it['unidade'], 
                            'qtd_adj' => $it['qtd'], 
                            'preco_un' => $it['preco'], 
                            'qtd_ant' => 0,
                            'qtd_mes' => 0
                        ];
                    }
                }
            }
        }
    }

    // Orçamento da obra, para comparar com os Trabalhos Adjudicados do auto
    $orc_obra = orcamentoDaObra($pdo, $val_id_obra);

    // Orçamentos complementares adjudicados ainda não incluídos nos autos desta obra
    $comp_pendentes = ($orc_obra && $pode_editar_suplementares) ? complementaresPendentes($pdo, $val_id_obra, $orc_obra['id']) : [];

    // Guardar as descrições e quantidades anteriores originais das linhas mostradas (para validar na gravação)
    $chave_form_render = $modo_editar ? 'e' . $id : 'n' . (int)$val_id_obra;
    $mapOrig = function ($it) {
        $t = (isset($it['total_adj']) && totalFoiAcertado($it['total_adj'], $it['qtd_adj'], $it['preco_un'])) ? (float)$it['total_adj'] : totLinha($it['qtd_adj'], $it['preco_un']);
        return ['d' => (string)$it['designacao'], 'a' => (float)$it['qtd_ant'], 'u' => (string)$it['unidade'], 'q' => (float)$it['qtd_adj'], 'p' => (float)$it['preco_un'], 't' => $t,
                'ka' => (float)($it['ac_ant'] ?? 0), 'k' => (float)($it['ac_acum'] ?? 0)];
    };
    $_SESSION['auto_orig'][$chave_form_render] = [
        'adj' => array_map($mapOrig, $itens_render_adj),
        'sup' => array_map($mapOrig, $itens_render_sup),
    ];
    unset($_SESSION['auto_desig_orig']);   // formato antigo

} catch (Throwable $e) {
    die("<div style='margin: 50px auto; max-width: 800px; padding: 25px; background: #fff3f3; color: #d63031; border: 2px solid #ff7675; border-radius: 8px; font-family: sans-serif; box-shadow: 0 4px 15px rgba(0,0,0,0.1);'>
            <h2 style='margin-top:0; border-bottom: 1px solid #ff7675; padding-bottom: 10px;'>⚠️ Erro Fatal Detetado no Formulário</h2>
            <p style='font-size: 16px;'><strong>Mensagem:</strong> " . htmlspecialchars($e->getMessage()) . "</p>
            <p><strong>Ficheiro:</strong> " . $e->getFile() . "</p>
            <p><strong>Linha:</strong> " . $e->getLine() . "</p>
         </div>");
}
?>
<!DOCTYPE html>
<html lang="pt-PT">
<head>
    <meta charset="UTF-8">
    <title><?= $titulo_pagina ?> - Gestão MAB</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { font-family: system-ui, -apple-system, sans-serif; box-sizing: border-box; }
        body { background-color: #f7f9fc; margin: 0; color: #333; font-size: 13px; padding-bottom: 100px; }
        
        /* TOPBAR - REGRA DE OURO */
        .topbar { background-color: #fff; padding: 15px 40px; box-shadow: 0 4px 20px rgba(0,0,0,0.05); display: flex; justify-content: space-between; align-items: center; position: sticky; top: 0; z-index: 100; border-bottom: 3px solid #34495e;}
        .topbar-left .logo { height: 40px; }
        .topbar-center { display: flex; align-items: center; gap: 20px; flex: 1; justify-content: center;}
        .nav-icon { color: #bdc3c7; font-size: 20px; transition: color 0.2s; text-decoration: none; padding: 5px; border-radius: 6px;}
        .nav-icon:hover { background: #f1f2f6; }
        .nav-icon.active { color: #b32a2a; }
        
        .topbar-right { display: flex; align-items: center; gap: 30px; }
        .btn-back { color: #576574; text-decoration: none; font-weight: 500; display: flex; align-items: center; gap: 6px; font-size: 15px; transition: 0.2s;}
        .btn-back:hover { color: #34495e; } 
        
        .user-menu { position: relative; display: inline-block;}
        .user-btn { background: none; border: none; font-size: 15px; font-weight: 600; color: #2c3e50; cursor: pointer; display: flex; align-items: center; gap: 8px; }
        .user-avatar { background: #e74c3c; color: white; width: 28px; height: 28px; border-radius: 50%; display: flex; justify-content: center; align-items: center; font-size: 14px; border: 2px solid #fadbd8; }
        
        .dropdown-content { display: none; position: absolute; right: 0; top: 100%; margin-top: 10px; background: #fff; min-width: 180px; box-shadow: 0 10px 30px rgba(0,0,0,0.1); border-radius: 8px; overflow: hidden; border: 1px solid #f1f2f6; z-index:200;}
        .dropdown-content a { color: #576574; padding: 12px 20px; text-decoration: none; display: block; font-size: 14px; }
        .dropdown-content a:hover { background: #fdf2f2; color: #b32a2a; }
        .user-menu:hover .dropdown-content { display: block; }

        .container { max-width: 1600px; margin: 20px auto; padding: 0 20px; }
        .card { background: #fff; padding: 20px; border-radius: 12px; border: 1px solid #e1e5ea; margin-bottom: 20px; }
        h2 { font-size: 14px; color: #b32a2a; text-transform: uppercase; border-bottom: 1px solid #f1f2f6; padding-bottom: 8px; margin-bottom: 15px; display: flex; align-items: center; gap: 8px; }
        .form-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 15px; }
        label { font-weight: 700; font-size: 10px; color: #576574; text-transform: uppercase; margin-bottom: 5px; display: block; }
        input, select, textarea { padding: 8px; border: 1px solid #dcdde1; border-radius: 6px; width: 100%; font-family: inherit; }
        
        .table-auto { width: 100%; border-collapse: collapse; table-layout: fixed; }
        .table-auto th { background: #f8f9fa; padding: 8px; border: 1px solid #eee; font-size: 9px; color: #576574; text-align: center; }
        .table-auto td { border: 1px solid #f1f2f6; padding: 0; }
        .table-auto input, .table-auto textarea { border: none; width: 100%; padding: 8px; text-align: center; background: transparent; font-size: 12px; transition: all 0.2s; }
        .table-auto input:focus, .table-auto textarea:focus { background: #fff; box-shadow: inset 0 0 0 2px #b32a2a; outline: none; }
        
        .readonly-text { color: #7f8c8d; font-weight: 700; }
        .bg-green { background-color: #e8f5e9 !important; color: #27ae60 !important; font-weight: bold; }
        .bg-yellow { background-color: #fff9c4 !important; color: #d35400 !important; font-weight: bold; }
        .bg-gray { background-color: #f1f2f6 !important; font-weight: bold; }

        /* CABEÇALHO EM DOIS NÍVEIS (igual ao PDF) */
        .table-auto input:not([type=checkbox]) { padding: 7px 3px; font-size: 11.5px; }
        .table-auto .td-ant input { padding-right: 16px !important; }
        .table-auto .btn-lock { right: 1px; padding: 3px 2px; }
        .table-auto th.th-grp { text-transform: uppercase; letter-spacing: .4px; border-bottom: 1px solid #dcdde1; }
        .table-auto th.th-mes { background: #e8f5e9; }
        .table-auto input.total_mes { color: #1e8449; font-weight: 700; }

        /* LINHA DE TOTAIS */
        .tfoot-totais td { background: #f8f9fa; border: 1px solid #eee; padding: 9px 6px; text-align: center; font-weight: 800; font-size: 12px; color: #2c3e50; white-space: nowrap; }
        .tfoot-totais td.tf-lbl { text-align: right; color: #576574; font-size: 11px; letter-spacing: .5px; }
        .tfoot-totais td.tf-mes { background: #e8f5e9; color: #1e8449; }
        .tfoot-totais tr.tf-legenda td { background: #fff; border: none; padding: 2px 6px 0; font-size: 9px; font-weight: 700; color: #95a5a6; text-transform: uppercase; }
        
        /* CONTROLOS TABELA */
        .chk-wrapper { display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 4px; padding: 2px 0; }
        .chk-select-row { width: 15px; height: 15px; accent-color: #b32a2a; cursor: pointer; }
        .move-controls { display: none; flex-direction: column; cursor: pointer; color: #cbd5e1; font-size: 12px; gap: 3px; align-items: center; }
        .move-controls i:hover { color: #b32a2a; }
        
        .btn-add-line { background: #b32a2a; color: #fff; border: none; width: 28px; height: 28px; border-radius: 4px; cursor: pointer; display: flex; align-items: center; justify-content: center; font-size: 14px; margin-top: 15px; transition: background 0.2s; }
        .btn-add-line:hover { background: #902222; }
        .btn-row-action { background: none; border: none; cursor: pointer; font-size: 14px; padding: 3px; border-radius: 4px; transition: all 0.2s; display: inline-flex; align-items: center; justify-content: center;}
        .btn-del-row { color: #f87171; }
        .btn-del-row:hover { background: #fef2f2; color: #dc2626; }
        .btn-add-row { color: #34d399; margin-right: 4px;}
        .btn-add-row:hover { background: #ecfdf5; color: #059669;}

        .floating-action-bar { position: fixed; bottom: 20px; right: 20px; background: #fff; padding: 15px 25px; border-radius: 12px; box-shadow: 0 5px 20px rgba(0,0,0,0.15); display: flex; align-items: center; gap: 15px; z-index: 1000; border: 1px solid #e1e5ea; }
        .btn-save { background: #b32a2a; color: #fff; padding: 12px 25px; border-radius: 8px; border: none; font-weight: 700; cursor: pointer; transition: background 0.2s; }
        .btn-save:hover { background: #902222; }

        .alert { padding: 12px; border-radius: 8px; margin-bottom: 15px; text-align: center; font-weight: 600; }
        .alert-erro { background: #fdeded; color: #c0392b; border: 1px solid #f9cccc; }
        .alert-sucesso { background: #e8f5e9; color: #2e7d32; border: 1px solid #c8e6c9; }

        /* DESCRIÇÃO PROTEGIDA (password mestra) */
        .td-desig { position: relative; }
        .td-desig textarea { padding-right: 26px !important; }
        .btn-lock { position: absolute; top: 4px; right: 3px; background: none; border: none; color: #b2bec3; cursor: pointer; font-size: 11px; padding: 3px 4px; border-radius: 4px; }
        .btn-lock:hover { color: #b32a2a; background: #fdf2f2; }
        .btn-lock.aberto { color: #e67e22; }
        /* COMPARAÇÃO COM O ORÇAMENTO */
        .orc-resumo { display: flex; flex-wrap: wrap; align-items: center; gap: 10px 26px; background: #f8f9fa; border: 1px solid #e1e5ea; border-radius: 8px; padding: 10px 14px; margin-bottom: 12px; }
        .orc-resumo > div { display: flex; flex-direction: column; }
        .orc-lbl { font-size: 10px; font-weight: 700; color: #7f8c8d; text-transform: uppercase; }
        .orc-val { font-size: 15px; font-weight: 800; color: #2c3e50; }
        .orc-val.dif-mal { color: #c0392b; }
        .orc-val.dif-ok { color: #27ae60; }
        .btn-comparar { margin-left: auto; background: #34495e; color: #fff; border: none; border-radius: 8px; padding: 9px 14px; font-weight: 700; cursor: pointer; }
        .btn-comparar:hover { background: #2c3e50; }
        .modal-comp { background: #fff; border-radius: 12px; padding: 20px; width: 100%; max-width: 1200px; max-height: 92vh; display: flex; flex-direction: column; box-shadow: 0 10px 40px rgba(0,0,0,0.25); }
        .comp-topo { display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px; }
        .comp-topo h3 { margin: 0; font-size: 16px; color: #2c3e50; display: flex; gap: 8px; align-items: center; }
        .comp-fechar { background: none; border: none; font-size: 18px; color: #7f8c8d; cursor: pointer; }
        .comp-totais { display: flex; flex-wrap: wrap; gap: 8px 24px; font-size: 13px; margin: 6px 0 8px; }
        .comp-totais b { font-size: 14px; }
        .comp-chk { display: flex; align-items: center; gap: 6px; font-size: 12px; text-transform: none; font-weight: 600; margin: 4px 0 8px; }
        .comp-chk input { width: auto; }
        .comp-tabela-wrap { overflow: auto; border: 1px solid #eee; border-radius: 8px; flex: 1; min-height: 120px; }
        .comp-tabela { width: 100%; border-collapse: collapse; font-size: 12px; }
        .comp-tabela th { position: sticky; top: 0; background: #f1f2f6; padding: 6px; font-size: 10px; color: #576574; text-transform: uppercase; border-bottom: 1px solid #dcdde1; z-index: 1; }
        .comp-tabela th.grp { background: #e5e8ec; }
        .comp-tabela td { padding: 5px 6px; border-bottom: 1px solid #f1f2f6; text-align: right; white-space: nowrap; }
        .comp-tabela td.t-l { text-align: left; white-space: normal; min-width: 220px; }
        .comp-tabela td.sep, .comp-tabela th.sep { border-left: 2px solid #dcdde1; }
        .comp-tabela td.x { background: #fdecea; color: #c0392b; font-weight: 700; }
        .comp-tabela tr.st-novo td { background: #eafaf1; }
        .comp-tabela tr.st-auto td { background: #fef5e7; }
        .comp-tabela .desc-orc { display: block; color: #2980b9; font-size: 11px; margin-top: 2px; }
        .tag { display: inline-block; padding: 2px 7px; border-radius: 10px; font-size: 10px; font-weight: 700; white-space: nowrap; }
        .tag-igual { background: #e8f5e9; color: #27ae60; }
        .tag-dif { background: #fdecea; color: #c0392b; }
        .tag-desc { background: #ebf5fb; color: #2980b9; }
        .tag-novo { background: #d5f5e3; color: #1e8449; }
        .tag-auto { background: #fdebd0; color: #b9770e; }
        .tag-conf { background: #c0392b; color: #fff; }
        .comp-legenda { font-size: 11px; color: #576574; margin-top: 8px; line-height: 2; }
        .comp-msg { padding: 9px 12px; border-radius: 8px; font-size: 12.5px; font-weight: 600; margin-bottom: 6px; }
        .comp-msg.ok { background: #e8f5e9; color: #2e7d32; }
        .comp-msg.alerta { background: #fff4e5; color: #b9770e; }
        .comp-msg.erro { background: #fdecea; color: #c0392b; }

        .comp-pend { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; background: #fef5e7; border: 1px solid #f9e0b5; color: #9c640c; border-radius: 8px; padding: 10px 14px; margin-bottom: 12px; font-weight: 600; font-size: 12.5px; }
        .btn-comp-pend { background: #fff; border: 1px solid #f5cba7; color: #b9770e; border-radius: 16px; padding: 5px 12px; font-weight: 800; cursor: pointer; font-size: 12px; }
        .btn-comp-pend:hover { background: #fdebd0; }
        .btn-comp-pend.feito { background: #e8f5e9; border-color: #a9dfbf; color: #1e8449; cursor: default; }
        .sup-botoes { display: flex; align-items: center; gap: 10px; margin-top: 15px; }
        .sup-botoes .btn-add-line { margin-top: 0; }
        .btn-copiar-adj { background: #fff; color: #34495e; border: 1px solid #cbd5e1; border-radius: 6px; padding: 6px 12px; font-weight: 700; font-size: 12px; cursor: pointer; }
        .btn-copiar-adj:hover { background: #f1f2f6; }
        #copiarTabela td { cursor: pointer; }
        #copiarTabela tr.sel td { background: #fdf2f2; }
        #copiarTabela tr.titulo td { color: #7f8c8d; font-weight: 700; }

        .td-acoes-adj { white-space: nowrap; text-align: center; }
        .btn-trocar { color: #3498db; }
        .btn-trocar:hover { background: #ebf5fb; color: #1f6fa5; }
        tr.linha-trocada td { animation: piscaTroca 1.6s ease; }
        @keyframes piscaTroca { 0%, 60% { background: #d6eaf8; } 100% { background: transparent; } }
        .td-ant { position: relative; }
        .table-auto input.total-acertado { color: #2471a3 !important; text-decoration: underline dotted; }
        .td-ant input { padding-right: 20px !important; }
        .btn-lock-ant { top: 50%; transform: translateY(-50%); }
        .table-auto input.ant-editavel { background: #fffbea !important; color: #333 !important; font-weight: 600 !important; }
        .table-auto textarea.desig-editavel { background: #fffbea !important; color: #333 !important; font-weight: 400 !important; text-align: left; }

        .modal-pw-bg { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.45); z-index: 3000; align-items: center; justify-content: center; padding: 16px; }
        .modal-pw-bg.show { display: flex; }
        .modal-pw { background: #fff; border-radius: 12px; padding: 22px; width: 100%; max-width: 360px; box-shadow: 0 10px 40px rgba(0,0,0,0.25); }
        .modal-pw h3 { margin: 0 0 6px; font-size: 16px; color: #2c3e50; display: flex; align-items: center; gap: 8px; }
        .modal-pw p { margin: 0 0 12px; color: #576574; font-size: 13px; }
        .modal-pw .pw-erro { color: #c0392b; font-size: 12px; font-weight: 600; min-height: 16px; margin-top: 6px; }
        .pw-botoes { display: flex; justify-content: flex-end; gap: 10px; margin-top: 12px; }
        .pw-botoes button { padding: 9px 18px; border-radius: 8px; border: none; font-weight: 700; cursor: pointer; }
        .pw-cancelar { background: #f1f2f6; color: #576574; }
        .pw-ok { background: #b32a2a; color: #fff; }

        @media (max-width: 768px) {
            .btn-comparar { margin-left: 0; width: 100%; }
            .modal-comp { padding: 14px; }
            .topbar { padding: 15px 20px; }
            .topbar-center { display: none; }
            .btn-back-text { display: none; }
        }
    </style>
</head>
<body>

    <header class="topbar">
        <div class="topbar-left"><a href="index.php"><img src="mab.png" alt="MAB" class="logo"></a></div>
        <div class="topbar-center">
            <a href="index.php" class="nav-icon" title="Início"><i class="fa-solid fa-house"></i></a>
            <?php if ($is_admin || in_array('clientes', $meus_acessos)): ?><a href="clientes.php" class="nav-icon" title="Clientes"><i class="fa-solid fa-users"></i></a><?php endif; ?>
            <?php if ($is_admin || in_array('servicos', $meus_acessos)): ?><a href="servicos.php" class="nav-icon" title="Serviços"><i class="fa-solid fa-screwdriver-wrench"></i></a><?php endif; ?>
            <?php if ($is_admin || in_array('obras', $meus_acessos)): ?><a href="obras.php" class="nav-icon" title="Obras"><i class="fa-solid fa-building"></i></a><?php endif; ?>
            <?php if ($is_admin || in_array('orcamentos', $meus_acessos)): ?><a href="orcamentos.php" class="nav-icon" title="Orçamentos"><i class="fa-solid fa-file-invoice"></i></a><?php endif; ?>
            <a href="autos.php" class="nav-icon active" title="Autos de Medição"><i class="fa-solid fa-file-invoice-dollar"></i></a>
            <?php if ($is_admin || in_array('equipa', $meus_acessos)): ?><a href="utilizadores.php" class="nav-icon" title="Equipa"><i class="fa-solid fa-helmet-safety"></i></a><?php endif; ?>
            <?php if ($is_admin || in_array('configuracoes', $meus_acessos)): ?><a href="configuracoes.php" class="nav-icon" title="Configurações"><i class="fa-solid fa-gear"></i></a><?php endif; ?>
        </div>
        <div class="topbar-right">
            <a href="autos.php" onclick="return confirm('Tem a certeza que deseja voltar? As alterações não guardadas serão perdidas.')" class="btn-back" title="Voltar à Gestão"><i class="fa-solid fa-arrow-left"></i> <span class="btn-back-text">Voltar</span></a>
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
                    <a href="index.php?logout=1" style="color: #e74c3c; border-top: 1px solid #eee;"><i class="fa-solid fa-right-from-bracket" style="width:20px;"></i> Sair</a>
                </div>
            </div>
        </div>
    </header>

    <div class="container">
        <?php if ($mensagem): ?>
            <div class="alert <?= $tipo_mensagem === 'sucesso' ? 'alert-sucesso' : 'alert-erro' ?>">
                <?= htmlspecialchars($mensagem, ENT_QUOTES, 'UTF-8') ?>
            </div>
        <?php endif; ?>

        <form method="POST" id="autoForm">
            <input type="hidden" name="itens_json" id="itens_json">
            <input type="hidden" name="pw_mestra" id="pw_mestra" value="">
            <div class="card">
                <h2>Cabeçalho do Auto</h2>
                <div class="form-grid">
                    <div>
                        <label>Obra</label>
                        <select name="id_obra" onchange="location.href='?obra_id='+this.value" <?= $modo_editar ? 'style="pointer-events: none; background: #f1f2f6;" readonly' : 'required' ?>>
                            <option value="">-- Selecione a Obra --</option>
                            <?php foreach($obras as $o): ?>
                                <option value="<?= $o['id'] ?>" <?= ($val_id_obra == $o['id'] ? 'selected':'') ?>><?= htmlspecialchars($o['nome']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div><label>Nº Auto</label><input type="number" name="numero_auto" value="<?= htmlspecialchars($val_numero_auto) ?>" required <?= $modo_editar ? 'readonly style="background: #f1f2f6;"' : '' ?>></div>
                    <div><label>Data</label><input type="date" name="data_auto" value="<?= htmlspecialchars($val_data_auto) ?>" required></div>
                    <div>
                        <label>Mês Referência</label>
                        <select name="mes_referencia" required>
                            <?php foreach($opcoes_meses as $op): ?>
                                <option value="<?= $op ?>" <?= ($op == $val_mes_referencia) ? 'selected' : '' ?>><?= $op ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>

            <div class="card">
                <h2>Trabalhos Adjudicados</h2>
                <?php if ($orc_obra): ?>
                <div class="orc-resumo" id="orcResumo">
                    <div><span class="orc-lbl">Adjudicado no auto</span><span class="orc-val" id="totAdjAuto">—</span></div>
                    <div><span class="orc-lbl">Orçamento <?= htmlspecialchars($orc_obra['numero']) ?></span><span class="orc-val"><?= number_format($orc_obra['total'], 2, ',', ' ') ?> €</span></div>
                    <div><span class="orc-lbl">Diferença</span><span class="orc-val" id="difOrc">—</span></div>
                    <button type="button" class="btn-comparar" onclick="abrirComparacao()"><i class="fa-solid fa-scale-balanced"></i> Comparar com o orçamento</button>
                </div>
                <?php endif; ?>
                <table class="table-auto">
                    <thead>
                        <tr>
                            <th rowspan="2" style="width:70px">Artigo</th>
                            <th rowspan="2" style="width:20%">Descrição</th>
                            <th rowspan="2" style="width:40px">Un.</th>
                            <th colspan="3" class="th-grp">Adjudicado</th>
                            <th colspan="2" class="th-grp th-mes">Mês</th>
                            <th colspan="3" class="th-grp">Acumulado</th>
                            <th colspan="2" class="th-grp">Saldo</th>
                            <th rowspan="2" style="width:64px"></th>
                        </tr>
                        <tr>
                            <th>Qtd</th><th>P. Unit</th><th>Total</th>
                            <th class="th-mes">Qtd</th><th class="th-mes">Total</th>
                            <th>Qtd Ant.</th><th>Qtd Total</th><th>Total €</th>
                            <th>Qtd</th><th>Valor €</th>
                        </tr>
                    </thead>
                    <tbody id="autoCorpoAdj">
                        <?php if($itens_render_adj): foreach($itens_render_adj as $idx_adj => $it): ?>
                        <tr data-ac-ant="<?= number_format((float)($it['ac_ant'] ?? 0), 2, '.', '') ?>" data-ac-acum="<?= number_format((float)($it['ac_acum'] ?? 0), 2, '.', '') ?>">
                            <td><input type="text" class="inp-artigo readonly-text" value="<?= htmlspecialchars($it['artigo']) ?>" readonly></td>
                            <td class="td-desig">
                                <textarea class="inp-desig readonly-text desig-bloq" rows="1" readonly data-idx="<?= $idx_adj ?>"><?= htmlspecialchars($it['designacao']) ?></textarea>
                                <button type="button" class="btn-lock" title="Alterar descrição (requer password mestra)" onclick="pedirDesbloqueio(this)"><i class="fa-solid fa-lock"></i></button>
                            </td>
                            <td><input type="text" class="inp-unidade readonly-text" value="<?= htmlspecialchars($it['unidade']) ?>" readonly></td>
                            <td><input type="text" class="qty qtd_adj readonly-text" value="<?= fmtQtdLinha($it['qtd_adj'], $it['unidade']) ?>" readonly></td>
                            <td class="td-ant">
                                <input type="text" class="qty preco_un readonly-text" value="<?= ((float)$it['preco_un'] != 0 || trim((string)$it['unidade']) !== '') ? number_format($it['preco_un'],3,',','') . ' €' : '' ?>" readonly oninput="calc(this)" onblur="formatField(this)">
                                <button type="button" class="btn-lock btn-lock-ant btn-lock-preco" title="Alterar preço unitário (requer password mestra)" onclick="pedirDesbloqueio(this)"><i class="fa-solid fa-lock"></i></button>
                            </td>
                            <?php
                                $t_calc_r = totLinha((float)$it['qtd_adj'], (float)$it['preco_un']);
                                $t_man_r = (isset($it['total_adj']) && totalFoiAcertado($it['total_adj'], $it['qtd_adj'], $it['preco_un'])) ? number_format((float)$it['total_adj'], 2, '.', '') : '';
                            ?>
                            <td class="td-ant">
                                <input type="text" class="total_adj readonly-text" readonly data-manual="<?= $t_man_r ?>" oninput="totalManualInput(this)" onblur="totalManualBlur(this)">
                                <button type="button" class="btn-lock btn-lock-ant btn-lock-total" title="Acertar Total Adj. (requer password mestra)" onclick="pedirDesbloqueio(this)"><i class="fa-solid fa-lock"></i></button>
                            </td>
                            <td><input type="text" class="qtd_mes" oninput="calc(this)" onblur="verificarExcesso(this); formatField(this)" value="<?= isset($it['qtd_mes']) && $it['qtd_mes'] > 0 ? number_format($it['qtd_mes'],3,',','') : '' ?>"></td><td><input type="text" class="total_mes readonly-text" readonly></td>
                            <td class="td-ant">
                                <input type="text" class="qty qtd_ant readonly-text" value="<?= fmtQtdLinha($it['qtd_ant'], $it['unidade']) ?>" readonly data-idx="<?= $idx_adj ?>" oninput="calc(this)" onblur="formatField(this)">
                                <button type="button" class="btn-lock btn-lock-ant" title="Acertar quantidade anterior (requer password mestra)" onclick="pedirDesbloqueio(this)"><i class="fa-solid fa-lock"></i></button>
                            </td>
                            <td><input type="text" class="qtd_acum readonly-text" readonly></td><td><input type="text" class="total_acum readonly-text" readonly></td>
                            <td><input type="text" class="saldo readonly-text" readonly></td>
                            <td class="td-ant">
                                <input type="text" class="saldo_valor readonly-text" readonly oninput="saldoManualInput(this)" onblur="saldoManualBlur(this)">
                                <button type="button" class="btn-lock btn-lock-ant btn-lock-saldo" title="Acertar Saldo € em cêntimos (requer password mestra)" onclick="pedirDesbloqueio(this)"><i class="fa-solid fa-lock"></i></button>
                            </td>
                            <td class="td-acoes-adj">
                                <button type="button" class="btn-row-action btn-trocar" title="Trocar un./quantidades/preço com a linha de baixo (requer password mestra)" onclick="pedirDesbloqueio(this)"><i class="fa-solid fa-arrows-up-down"></i></button>
                                <button type="button" class="btn-del-line" onclick="this.closest('tr').remove(); calcularTotaisGerais();"><i class="fa-solid fa-trash-can"></i></button>
                            </td>
                        </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                    <tfoot class="tfoot-totais">
                        <tr>
                            <td colspan="5" class="tf-lbl">TOTAIS</td>
                            <td id="tfAdjTotal"></td>
                            <td></td>
                            <td id="tfAdjMes" class="tf-mes"></td>
                            <td id="tfAdjAnt"></td>
                            <td></td>
                            <td id="tfAdjAcum"></td>
                            <td></td>
                            <td id="tfAdjSaldo"></td>
                            <td></td>
                        </tr>
                        <tr class="tf-legenda">
                            <td colspan="5"></td><td>Total €</td><td></td><td>Mês €</td><td>Anterior €</td><td></td><td>Acumulado €</td><td></td><td>Saldo €</td><td></td>
                        </tr>
                    </tfoot>
                </table>
                <button type="button" onclick="addLinhaAdj()" class="btn-add-line" title="Adicionar Linha Adjudicada"><i class="fa-solid fa-plus"></i></button>
            </div>

            <div class="card">
                <h2>Trabalhos Suplementares</h2>
                <?php if (!empty($comp_pendentes)): ?>
                <div class="comp-pend" id="compPendentes">
                    <i class="fa-solid fa-code-branch"></i>
                    <span><?= $modo_editar ? 'Há orçamentos complementares adjudicados que ainda não estão nos autos desta obra:' : 'Foram incluídos nos suplementares os orçamentos complementares adjudicados:' ?></span>
                    <?php foreach ($comp_pendentes as $cp): ?>
                        <button type="button" class="btn-comp-pend" data-comp="<?= (int)$cp['id'] ?>" onclick="importarComplementar(<?= (int)$cp['id'] ?>)">
                            <i class="fa-solid fa-file-import"></i> <?= htmlspecialchars($cp['numero']) ?> <small>(<?= number_format($cp['total'], 2, ',', '.') ?> €)</small>
                        </button>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
                <?php
                    $ro_attr = !$pode_editar_suplementares ? 'readonly' : '';
                    $ro_cls = !$pode_editar_suplementares ? 'readonly-text' : '';
                ?>
                <table class="table-auto">
                    <thead>
                        <tr>
                            <th rowspan="2" style="width:30px;"><input type="checkbox" onclick="toggleAllRows('sup', this)" style="width:12px; height:12px; cursor:pointer;"></th>
                            <th rowspan="2" style="width:70px">Artigo</th>
                            <th rowspan="2" style="width:20%">Descrição</th>
                            <th rowspan="2" style="width:40px">Un.</th>
                            <th colspan="3" class="th-grp">Suplementares</th>
                            <th colspan="2" class="th-grp th-mes">Mês</th>
                            <th colspan="3" class="th-grp">Acumulado</th>
                            <th colspan="2" class="th-grp">Saldo</th>
                            <th rowspan="2" style="width:64px">Ações</th>
                        </tr>
                        <tr>
                            <th>Qtd</th><th>P. Unit</th><th>Total</th>
                            <th class="th-mes">Qtd</th><th class="th-mes">Total</th>
                            <th>Qtd Ant.</th><th>Qtd Total</th><th>Total €</th>
                            <th>Qtd</th><th>Valor €</th>
                        </tr>
                    </thead>
                    <tbody id="autoCorpoSup">
                        <?php if($itens_render_sup): foreach($itens_render_sup as $idx_sup => $it): ?>
                        <?php
                            $t_man_s = (isset($it['total_adj']) && totalFoiAcertado($it['total_adj'], $it['qtd_adj'], $it['preco_un'])) ? number_format((float)$it['total_adj'], 2, '.', '') : '';
                        ?>
                        <tr data-ac-ant="<?= number_format((float)($it['ac_ant'] ?? 0), 2, '.', '') ?>" data-ac-acum="<?= number_format((float)($it['ac_acum'] ?? 0), 2, '.', '') ?>" data-orc-comp="<?= !empty($it['orc_comp']) ? (int)$it['orc_comp'] : '' ?>">
                            <td>
                                <div class="chk-wrapper">
                                    <input type="checkbox" class="chk-select-row" onchange="updateMoveArrows('sup')">
                                    <div class="move-controls" style="display:none;">
                                        <i class="fa-solid fa-chevron-up" onclick="moveSelectedRows('sup', 'up')"></i>
                                        <i class="fa-solid fa-chevron-down" onclick="moveSelectedRows('sup', 'down')"></i>
                                        <i class="fa-solid fa-copy" onclick="duplicateSelectedRows('sup')"></i>
                                    </div>
                                </div>
                            </td>
                            <td><input type="text" class="inp-artigo <?= $ro_cls ?>" value="<?= htmlspecialchars($it['artigo']) ?>" <?= $ro_attr ?>></td>
                            <td><textarea class="inp-desig <?= $ro_cls ?>" rows="1" <?= $ro_attr ?> oninput="ajustarTextareaRow(this)"><?= htmlspecialchars($it['designacao']) ?></textarea></td>
                            <td><input type="text" class="inp-unidade <?= $ro_cls ?>" value="<?= htmlspecialchars($it['unidade']) ?>" <?= $ro_attr ?>></td>
                            <td><input type="text" class="qty qtd_adj <?= $ro_cls ?>" oninput="calc(this, true)" onblur="formatField(this)" value="<?= (float)$it['qtd_adj'] != 0 ? number_format($it['qtd_adj'],3,',','') : '' ?>" <?= $ro_attr ?>></td>
                            <td><input type="text" class="qty preco_un <?= $ro_cls ?>" oninput="calc(this, true);" onblur="formatField(this)" value="<?= (float)$it['preco_un'] != 0 ? number_format($it['preco_un'],3,',','') . ' €' : '' ?>" <?= $ro_attr ?>></td>
                            <td class="td-ant">
                                <input type="text" class="total_adj readonly-text" readonly data-manual="<?= $t_man_s ?>" oninput="totalManualInput(this)" onblur="totalManualBlur(this)">
                                <button type="button" class="btn-lock btn-lock-ant btn-lock-total" title="Acertar Total Adj. (requer password mestra)" onclick="pedirDesbloqueio(this)"><i class="fa-solid fa-lock"></i></button>
                            </td>
                            <td><input type="text" class="qtd_mes" oninput="calc(this, true)" onblur="formatField(this)" value="<?= isset($it['qtd_mes']) && $it['qtd_mes'] > 0 ? number_format($it['qtd_mes'],3,',','') : '' ?>"></td><td><input type="text" class="total_mes readonly-text" readonly></td>
                            <td class="td-ant">
                                <input type="text" class="qty qtd_ant readonly-text" value="<?= fmtQtdLinha($it['qtd_ant'], $it['unidade']) ?>" readonly data-idx="<?= $idx_sup ?>" oninput="calc(this, true)" onblur="formatField(this)">
                                <button type="button" class="btn-lock btn-lock-ant" title="Acertar quantidade anterior (requer password mestra)" onclick="pedirDesbloqueio(this)"><i class="fa-solid fa-lock"></i></button>
                            </td>
                            <td><input type="text" class="qtd_acum readonly-text" readonly></td><td><input type="text" class="total_acum readonly-text" readonly></td>
                            <td><input type="text" class="saldo readonly-text" readonly></td>
                            <td class="td-ant">
                                <input type="text" class="saldo_valor readonly-text" readonly oninput="saldoManualInput(this)" onblur="saldoManualBlur(this)">
                                <button type="button" class="btn-lock btn-lock-ant btn-lock-saldo" title="Acertar Saldo € em cêntimos (requer password mestra)" onclick="pedirDesbloqueio(this)"><i class="fa-solid fa-lock"></i></button>
                            </td>
                            <td class="td-acoes-adj">
                                <?php if($pode_editar_suplementares): ?>
                                    <button type="button" class="btn-row-action btn-add-row" title="Inserir linha abaixo" onclick="insertRowAfterSup(this)"><i class="fa-solid fa-plus"></i></button>
                                <?php endif; ?>
                                <button type="button" class="btn-row-action btn-del-row" onclick="this.closest('tr').remove(); calcularTotaisGerais();"><i class="fa-solid fa-trash-can"></i></button>
                            </td>
                        </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                    <tfoot class="tfoot-totais">
                        <tr>
                            <td colspan="6" class="tf-lbl">TOTAIS</td>
                            <td id="tfSupTotal"></td>
                            <td></td>
                            <td id="tfSupMes" class="tf-mes"></td>
                            <td id="tfSupAnt"></td>
                            <td></td>
                            <td id="tfSupAcum"></td>
                            <td></td>
                            <td id="tfSupSaldo"></td>
                            <td></td>
                        </tr>
                        <tr class="tf-legenda">
                            <td colspan="6"></td><td>Total €</td><td></td><td>Mês €</td><td>Anterior €</td><td></td><td>Acumulado €</td><td></td><td>Saldo €</td><td></td>
                        </tr>
                    </tfoot>
                </table>
                <?php if($pode_editar_suplementares): ?>
                    <div class="sup-botoes">
                        <button type="button" onclick="addLinhaSup()" class="btn-add-line" title="Adicionar Linha Suplementar"><i class="fa-solid fa-plus"></i></button>
                        <button type="button" onclick="abrirCopiarAdj()" class="btn-copiar-adj" title="Copiar linhas dos Trabalhos Adjudicados"><i class="fa-solid fa-copy"></i> Copiar dos adjudicados</button>
                    </div>
                <?php endif; ?>
            </div>

            <!-- NOVA SECÇÃO OBSERVAÇÕES -->
            <div class="card" style="margin-bottom: 90px;">
                <h2><i class="fa-solid fa-comment-dots"></i> Observações / Notas</h2>
                <div class="form-group" style="margin-bottom:0;">
                    <textarea name="observacoes" rows="4" style="resize:vertical;" placeholder="Notas ou observações adicionais relativas a este auto de medição..."><?= htmlspecialchars($val_observacoes ?? '') ?></textarea>
                </div>
            </div>

            <div class="floating-action-bar">
                <div style="text-align:right">
                    <span style="font-size:10px; font-weight:bold; color:#576574; display:block">TOTAL DO MÊS</span>
                    <span id="dock_total" style="font-size:20px; font-weight:800; color:#b32a2a">0,00 €</span>
                </div>
                <button type="submit" name="btn_guardar_auto" class="btn-save" onclick="guardarScrollY()">Gravar</button>
                <button type="submit" name="btn_guardar_imprimir" class="btn-save" style="background:#576574" onclick="guardarScrollY()">Gravar e Imprimir</button>
            </div>
        </form>
    </div>

    <!-- MODAL COPIAR LINHAS DOS ADJUDICADOS PARA OS SUPLEMENTARES -->
    <div class="modal-pw-bg" id="modalCopiar">
        <div class="modal-comp" style="max-width:900px">
            <div class="comp-topo">
                <h3><i class="fa-solid fa-copy" style="color:#b32a2a"></i> Copiar linhas dos Trabalhos Adjudicados</h3>
                <button type="button" class="comp-fechar" onclick="fecharCopiarAdj()" title="Fechar"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <p style="margin:0 0 8px;color:#576574;font-size:12.5px">Escolha as linhas a copiar. São copiados o artigo, a descrição, a unidade e o preço unitário; as quantidades ficam em branco para preencher.
                Se tiver linhas suplementares selecionadas, as cópias entram a seguir à última selecionada; senão, no fim.</p>
            <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:8px">
                <input type="text" id="copiarFiltro" placeholder="Procurar por artigo ou descrição…" oninput="renderCopiarAdj()" style="flex:1;min-width:200px">
                <label class="comp-chk" style="margin:0"><input type="checkbox" id="copiarTitulos" onchange="renderCopiarAdj()"> Mostrar também títulos</label>
                <label class="comp-chk" style="margin:0"><input type="checkbox" id="copiarQtd"> Copiar também a Qtd Adj.</label>
            </div>
            <div class="comp-tabela-wrap"><table class="comp-tabela" id="copiarTabela"></table></div>
            <div class="pw-botoes">
                <span id="copiarConta" style="margin-right:auto;align-self:center;font-size:12px;color:#576574"></span>
                <button type="button" class="pw-cancelar" onclick="fecharCopiarAdj()">Cancelar</button>
                <button type="button" class="pw-ok" onclick="copiarAdjSelecionadas()"><i class="fa-solid fa-copy"></i> Copiar para suplementares</button>
            </div>
        </div>
    </div>

    <!-- MODAL COMPARAÇÃO AUTO × ORÇAMENTO -->
    <div class="modal-pw-bg" id="modalComp">
        <div class="modal-comp">
            <div class="comp-topo">
                <h3><i class="fa-solid fa-scale-balanced" style="color:#b32a2a"></i> Trabalhos adjudicados × Orçamento</h3>
                <button type="button" class="comp-fechar" onclick="fecharComparacao()" title="Fechar"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <div id="compAviso"></div>
            <div class="comp-totais" id="compTotais"></div>
            <label class="comp-chk"><input type="checkbox" id="compMostrarIguais" onchange="renderComparacao()"> Mostrar também as linhas iguais</label>
            <label class="comp-chk"><input type="checkbox" id="compPrecos" onchange="renderComparacao()"> Comparar e aplicar também os preços unitários <span style="font-weight:400;color:#7f8c8d">(desligado: mantêm-se os preços adjudicados do auto)</span></label>
            <div class="comp-tabela-wrap"><table class="comp-tabela" id="compTabela"></table></div>
            <div class="comp-legenda">
                <span class="tag tag-dif">Diferente</span> un., quantidade ou preço diferentes &nbsp;
                <span class="tag tag-desc">Descrição</span> só a descrição é diferente &nbsp;
                <span class="tag tag-novo">Só no orçamento</span> falta no auto &nbsp;
                <span class="tag tag-auto">Só no auto</span> não existe no orçamento
            </div>
            <div class="pw-botoes">
                <button type="button" class="pw-cancelar" onclick="fecharComparacao()">Fechar</button>
                <button type="button" class="pw-ok" id="btnAplicarOrc" onclick="pedirAplicarOrcamento()"><i class="fa-solid fa-file-import"></i> Aplicar orçamento ao auto</button>
            </div>
        </div>
    </div>

    <!-- MODAL PASSWORD MESTRA (alterar descrição adjudicada) -->
    <div class="modal-pw-bg" id="modalPw">
        <div class="modal-pw">
            <h3><i class="fa-solid fa-lock" style="color:#b32a2a"></i> <span id="pwTitulo">Alterar descrição</span></h3>
            <p id="pwTexto">Para alterar a descrição de um trabalho adjudicado introduza a password mestra:</p>
            <input type="password" id="inputPwMestra" placeholder="Password" autocomplete="new-password">
            <div class="pw-erro" id="pwErro"></div>
            <div class="pw-botoes">
                <button type="button" class="pw-cancelar" onclick="fecharModalPw()">Cancelar</button>
                <button type="button" class="pw-ok" id="btnPwOk" onclick="confirmarPw()">Desbloquear</button>
            </div>
        </div>
    </div>

    <script>
        // --- DESBLOQUEIO DA DESCRIÇÃO COM PASSWORD MESTRA ---
        let pwMestraValida = '';
        let acaoPendente = null;

        // Pede a password mestra (uma vez por página) e depois executa a ação
        function exigirPassword(titulo, texto, acao) {
            if (pwMestraValida) { acao(); return; }
            acaoPendente = acao;
            document.getElementById('pwTitulo').textContent = titulo;
            document.getElementById('pwTexto').textContent = texto;
            document.getElementById('pwErro').textContent = '';
            const inp = document.getElementById('inputPwMestra');
            inp.value = '';
            document.getElementById('modalPw').classList.add('show');
            setTimeout(() => inp.focus(), 50);
        }

        // Troca Un. / Qtd Adj. / P. Unit / Qtd Mês / Qtd Ant. entre esta linha e a de baixo
        // (para corrigir valores que ficaram na linha errada, ex.: no título em vez do artigo)
        function trocarComLinhaAbaixo(btn) {
            const row = btn.closest('tr');
            const next = row.nextElementSibling;
            if (!next) { alert('Não existe linha abaixo desta.'); return; }
            ['.inp-unidade', '.qtd_adj', '.preco_un', '.qtd_mes', '.qtd_ant'].forEach(sel => {
                const a = row.querySelector(sel), b = next.querySelector(sel);
                if (!a || !b) return;
                const t = a.value; a.value = b.value; b.value = t;
            });
            const ta = row.querySelector('.total_adj'), tb = next.querySelector('.total_adj');
            if (ta && tb) { const m = ta.dataset.manual || ''; ta.dataset.manual = tb.dataset.manual || ''; tb.dataset.manual = m; }
            ['acAnt', 'acAcum'].forEach(k => { const m = row.dataset[k]; row.dataset[k] = next.dataset[k] || '0'; next.dataset[k] = m || '0'; });
            [row, next].forEach(r => {
                const pu = r.querySelector('.preco_un');
                const v = strToNum(pu.value);
                pu.value = v ? numToStrPreco(v) + ' €' : '';
                ['.qtd_adj', '.qtd_mes', '.qtd_ant'].forEach(sel => {
                    const el = r.querySelector(sel);
                    const n = strToNum(el.value);
                    el.value = n ? numToStrQtd(n) : '';
                });
                const s = r.querySelector('.saldo');
                if (s) { s.className = 'saldo readonly-text'; s.style.color = ''; }
                calc(r.querySelector('.qtd_mes'), false);
                r.classList.remove('linha-trocada'); void r.offsetWidth; r.classList.add('linha-trocada');
            });
        }

        function desbloquearDescricao(btn) {
            if (btn.classList.contains('btn-trocar')) { trocarComLinhaAbaixo(btn); return; }
            if (btn.classList.contains('btn-lock-ant')) {
                const ePreco = btn.classList.contains('btn-lock-preco');
                const eTotal = btn.classList.contains('btn-lock-total');
                const eSaldo = btn.classList.contains('btn-lock-saldo');
                const inp = btn.closest('td').querySelector(eSaldo ? '.saldo_valor' : (eTotal ? '.total_adj' : (ePreco ? '.preco_un' : '.qtd_ant')));
                inp.removeAttribute('readonly');
                inp.classList.remove('readonly-text');
                inp.classList.add('ant-editavel');
                btn.classList.add('aberto');
                btn.innerHTML = '<i class="fa-solid fa-lock-open"></i>';
                btn.title = eSaldo ? 'Saldo € desbloqueado (apague para voltar ao valor calculado)' : eTotal ? 'Total Adj. desbloqueado (apague para voltar ao valor calculado)' : (ePreco ? 'Preço unitário desbloqueado' : 'Quantidade anterior desbloqueada');
                inp.focus();
                inp.select();
                return;
            }
            const ta = btn.closest('td').querySelector('.inp-desig');
            ta.removeAttribute('readonly');
            ta.classList.remove('readonly-text', 'desig-bloq');
            ta.classList.add('desig-editavel');
            ta.addEventListener('input', function () { ajustarTextareaRow(this); });
            btn.classList.add('aberto');
            btn.innerHTML = '<i class="fa-solid fa-lock-open"></i>';
            btn.title = 'Descrição desbloqueada';
            ta.focus();
            ta.setSelectionRange(ta.value.length, ta.value.length);
        }

        function pedirDesbloqueio(btn) {
            if (btn.classList.contains('aberto')) { btn.closest('td').querySelector('textarea, input').focus(); return; }
            const eAnt = btn.classList.contains('btn-lock-ant');
            const eTroca = btn.classList.contains('btn-trocar');
            if (btn.classList.contains('btn-lock-saldo')) {
                exigirPassword('Acertar Saldo €',
                    'Para acertar o Saldo € desta linha (ex.: mais ou menos 1 ou 2 cêntimos) introduza a password mestra. A diferença entra no valor do mês deste auto:',
                    () => desbloquearDescricao(btn));
                return;
            }
            if (btn.classList.contains('btn-lock-total')) {
                exigirPassword('Acertar Total Adj.',
                    'Para acertar o Total Adj. desta linha ao valor do cliente (ex.: mais ou menos 1 cêntimo) introduza a password mestra:',
                    () => desbloquearDescricao(btn));
                return;
            }
            if (btn.classList.contains('btn-lock-preco')) {
                exigirPassword('Alterar preço unitário',
                    'Para alterar o preço unitário adjudicado (até 3 casas decimais) introduza a password mestra:',
                    () => desbloquearDescricao(btn));
                return;
            }
            exigirPassword(
                eTroca ? 'Trocar com a linha de baixo' : (eAnt ? 'Acertar quantidade anterior' : 'Alterar descrição'),
                eTroca ? 'A unidade, quantidades e preço desta linha vão trocar com os da linha de baixo. Introduza a password mestra:'
                       : (eAnt ? 'Para acertar a quantidade anterior desta linha introduza a password mestra:'
                               : 'Para alterar a descrição de um trabalho adjudicado introduza a password mestra:'),
                () => desbloquearDescricao(btn)
            );
        }

        function fecharModalPw() {
            document.getElementById('modalPw').classList.remove('show');
            acaoPendente = null;
        }

        function confirmarPw() {
            const pw = document.getElementById('inputPwMestra').value;
            const erro = document.getElementById('pwErro');
            if (!pw) { erro.textContent = 'Introduza a password.'; return; }
            const okBtn = document.getElementById('btnPwOk');
            okBtn.disabled = true;
            const fd = new FormData();
            fd.append('acao', 'verificar_pw');
            fd.append('pw', pw);
            fetch(location.href, { method: 'POST', body: fd, credentials: 'same-origin' })
                .then(r => r.json())
                .then(res => {
                    okBtn.disabled = false;
                    if (res.ok) {
                        pwMestraValida = pw;
                        document.getElementById('pw_mestra').value = pw;
                        const acao = acaoPendente;
                        fecharModalPw();
                        if (acao) acao();
                    } else {
                        erro.textContent = 'Password incorreta.';
                        document.getElementById('inputPwMestra').select();
                    }
                })
                .catch(() => { okBtn.disabled = false; erro.textContent = 'Não foi possível verificar a password. Tente novamente.'; });
        }

        document.getElementById('inputPwMestra').addEventListener('keydown', function (e) {
            if (e.key === 'Enter') { e.preventDefault(); confirmarPw(); }
            if (e.key === 'Escape') fecharModalPw();
        });
        document.getElementById('modalPw').addEventListener('click', function (e) { if (e.target === this) fecharModalPw(); });

        // Guarda a posição de scroll para não saltar para cima ao gravar
        function guardarScrollY() {
            sessionStorage.setItem('autoScrollY', window.scrollY);
        }

        // MOTOR ROBUSTO: Lida com Pontos OU Virgulas
        function strToNum(s) {
            if (!s) return 0;
            let str = s.toString().trim();
            let hasComma = str.indexOf(',') !== -1;
            let hasDot = str.indexOf('.') !== -1;
            if (hasComma && hasDot) {
                let lastComma = str.lastIndexOf(',');
                let lastDot = str.lastIndexOf('.');
                if (lastComma > lastDot) {
                    str = str.replace(/\./g, '').replace(',', '.');
                } else {
                    str = str.replace(/,/g, '');
                }
            } else if (hasComma) {
                str = str.replace(/,/g, '.');
            }
            str = str.replace(/[^\d\.-]/g, '');
            return parseFloat(str) || 0;
        }
        
        function numToStr(n) { return n.toLocaleString('pt-PT', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
        // Preço unitário com 3 casas decimais
        function numToStrPreco(n) { return n.toLocaleString('pt-PT', { minimumFractionDigits: 3, maximumFractionDigits: 3 }); }
        // Total de linha arredondado ao cêntimo (igual ao que é gravado)
        // Conta feita em inteiros (milésimos × milésimos) para não falhar nos meios cêntimos
        // (ex.: 5 × 0,411 = 2,055 → 2,06), igual a totLinha() no servidor.
        function totLinha(q, p) {
            if (!q || !p) return 0;
            let prod = BigInt(Math.round(q * 1000)) * BigInt(Math.round(p * 1000));   // milionésimos
            const neg = prod < 0n;
            if (neg) prod = -prod;
            const cent = (prod + 5000n) / 10000n;
            return (neg ? -1 : 1) * Number(cent) / 100;
        }
        function numToStrQtd(n) { return n.toLocaleString('pt-PT', { minimumFractionDigits: 3, maximumFractionDigits: 3 }); }

        function formatField(el) { 
            let val = strToNum(el.value); 
            if (val === 0) {
                el.value = "";
            } else {
                const isQtd = el.classList.contains('qtd_mes') || el.classList.contains('qtd_adj') || el.classList.contains('qtd_ant') || el.classList.contains('qtd_acum') || el.classList.contains('saldo');
                const isPreco = el.classList.contains('preco_un');
                el.value = isQtd ? numToStrQtd(val) : (isPreco ? numToStrPreco(val) + (el.closest('#autoCorpoAdj, #autoCorpoSup') ? ' €' : '') : numToStr(val));
            }
        }

        // Valores de uma linha adjudicada (igual a valoresLinhaAdj() no servidor)
        function valoresAdj(row) {
            const cent = x => Math.round((x + Number.EPSILON) * 100) / 100;
            const qAdj = strToNum(row.querySelector('.qtd_adj').value);
            const pUn = strToNum(row.querySelector('.preco_un').value);
            const qAnt = strToNum(row.querySelector('.qtd_ant').value);
            const qMes = strToNum(row.querySelector('.qtd_mes').value);
            const tCalc = totLinha(qAdj, pUn);
            const man = (row.querySelector('.total_adj').dataset.manual || '').trim();
            const tAdj = man !== '' ? cent(parseFloat(man)) : tCalc;
            const acerto = man !== '' && Math.abs(tAdj - tCalc) >= 0.005;
            const qAcum = qAnt + qMes;
            const completo = qAdj > 0 && Math.abs(qAcum - qAdj) < 0.0005;
            const jaCompleto = qAdj > 0 && Math.abs(qAnt - qAdj) < 0.0005;
            let tAcum, tMes;
            if (acerto && completo) { tAcum = tAdj; tMes = jaCompleto ? 0 : cent(tAdj - totLinha(qAnt, pUn)); }
            else { tAcum = totLinha(qAcum, pUn); tMes = totLinha(qMes, pUn); }
            // Acerto de cêntimos do Saldo € (acumulado): o mês leva só a parte nova deste auto
            const acAnt = cent(parseFloat(row.dataset.acAnt || '0') || 0);
            const acAcum = (row.dataset.acAcum === undefined || row.dataset.acAcum === '') ? acAnt : cent(parseFloat(row.dataset.acAcum) || 0);
            const tAcumBase = tAcum;
            tAcum = cent(tAcum + acAcum);
            tMes = cent(tMes + acAcum - acAnt);
            // Saldo € = Total Adj. − Acumulado € (assim bate sempre ao cêntimo com as colunas)
            const saldoV = cent(tAdj - tAcum);
            return { qAdj, pUn, qAnt, qMes, qAcum, tCalc, tAdj, acerto, tAcum, tMes, saldoV, acAnt, acAcum, tAcumBase };
        }

        // Total Adj. acertado à mão (cadeado)
        function totalManualInput(el) {
            const v = el.value.trim();
            el.dataset.manual = v === '' ? '' : String(strToNum(v));
            calc(el, false);
        }
        function totalManualBlur(el) {
            if (el.readOnly) return;
            calc(el, false);
            const v = valoresAdj(el.closest('tr'));
            el.value = v.tAdj ? numToStr(v.tAdj) + ' €' : '';
        }

        // Saldo € acertado à mão (cadeado): guarda a diferença como acerto do Acumulado €
        function saldoManualInput(el) {
            const row = el.closest('tr');
            const v0 = valoresAdj(row);
            const txt = el.value.trim();
            if (txt === '') {
                row.dataset.acAcum = String(v0.acAnt);          // volta ao calculado (sem acerto neste auto)
            } else {
                const desejado = strToNum(txt);
                const ac = Math.round(((v0.tAdj - desejado) - v0.tAcumBase) * 100) / 100;
                row.dataset.acAcum = String(ac);
            }
            calc(el, !!row.closest('#autoCorpoSup'));
        }
        function saldoManualBlur(el) {
            if (el.readOnly) return;
            const row = el.closest('tr');
            calc(el, !!row.closest('#autoCorpoSup'));
            const v = valoresAdj(row);
            el.value = numToStr(v.saldoV) + ' €';
        }

        function calc(el, isSup = false) {
            const row = el.closest('tr');
            if(!row) return;
            isSup = isSup || !!row.closest('#autoCorpoSup');

            const v = valoresAdj(row);
            const qAdj = v.qAdj;
            const qAcum = v.qAcum;
            const saldo = qAdj - qAcum;
            const temAdj = qAdj !== 0;

            const tEl = row.querySelector('.total_adj');
            if (document.activeElement !== tEl || tEl.readOnly) tEl.value = (v.tAdj === 0) ? "" : numToStr(v.tAdj) + " €";
            tEl.classList.toggle('total-acertado', v.acerto);
            tEl.title = v.acerto ? 'Total acertado (calculado: ' + numToStr(v.tCalc) + ' €)' : '';

            row.querySelector('.qtd_acum').value = (qAcum === 0 && !temAdj) ? "" : numToStrQtd(qAcum);
            const tmEl = row.querySelector('.total_mes');
            if (tmEl) tmEl.value = Math.abs(v.tMes) < 0.005 ? "" : numToStr(v.tMes) + " €";
            const taEl = row.querySelector('.total_acum');
            if (taEl) taEl.value = Math.abs(v.tAcum) < 0.005 ? "" : numToStr(v.tAcum) + " €";
            row.querySelector('.saldo').value = !temAdj ? "" : numToStrQtd(saldo);

            const svEl = row.querySelector('.saldo_valor');
            if (document.activeElement !== svEl || svEl.readOnly) svEl.value = !temAdj ? "" : numToStr(v.saldoV) + " €";
            const temAcerto = Math.abs(v.acAcum) >= 0.005;
            svEl.classList.toggle('total-acertado', temAcerto);
            svEl.title = temAcerto ? 'Saldo acertado: ' + (v.acAcum > 0 ? '−' : '+') + numToStr(Math.abs(v.acAcum)) + ' € no acumulado' +
                (Math.abs(v.acAcum - v.acAnt) >= 0.005 ? ' (' + (v.acAcum - v.acAnt > 0 ? '+' : '−') + numToStr(Math.abs(v.acAcum - v.acAnt)) + ' € no mês deste auto)' : '') : '';

            const sEl = row.querySelector('.saldo');
            sEl.className = 'saldo readonly-text';
            sEl.style.color = '';
            if (qAdj > 0) {
                if (Math.abs(saldo) < 0.0005) sEl.classList.add('bg-green');
                else if (Math.abs(saldo - qAdj) < 0.0005) sEl.classList.add('bg-gray');
                else if (saldo > 0) sEl.classList.add('bg-yellow');
                else sEl.style.color = 'red';
            }

            if (!isSup) {
                // Adjudicados: linhas sem quantidade adjudicada são títulos
                const visibility = temAdj ? 'visible' : 'hidden';
                ['.preco_un', '.total_adj', '.qtd_mes', '.total_mes', '.qtd_ant', '.qtd_acum', '.total_acum', '.saldo', '.saldo_valor'].forEach(sel => {
                    let field = row.querySelector(sel);
                    if(field) field.style.visibility = visibility;
                });
            }
            calcularTotaisGerais();
        }

        function verificarExcesso(el) {
            const row = el.closest('tr');
            const qAdj = strToNum(row.querySelector('.qtd_adj').value);
            const qAnt = strToNum(row.querySelector('.qtd_ant').value);
            let qMes = strToNum(el.value);
            if (qAdj > 0 && (qAnt + qMes) > qAdj) {
                const qExcesso = (qAnt + qMes) - qAdj;
                qMes = qAdj - qAnt;
                el.value = qMes > 0 ? numToStrQtd(qMes) : "";
                calc(el, false);
                adicionarSuplementar(row.querySelector('.inp-artigo').value, row.querySelector('.inp-desig').value, row.querySelector('.inp-unidade').value, strToNum(row.querySelector('.preco_un').value), qExcesso);
            }
        }

        function adicionarSuplementar(art, des, un, preco, qExc) {
            let existe = false;
            document.querySelectorAll('#autoCorpoSup tr').forEach(row => {
                if(row.querySelector('.inp-desig').value === des) {
                    let atual = strToNum(row.querySelector('.qtd_mes').value);
                    row.querySelector('.qtd_mes').value = numToStrQtd(atual + qExc);
                    calc(row.querySelector('.qtd_mes'), true);
                    existe = true;
                }
            });
            if(!existe) addLinhaSup({ artigo: art, designacao: des, unidade: un, preco_un: preco, qtd_mes: qExc });
        }

        function getSupRowHTML(data = {}) {
            const e = t => escHTML(t);
            const safeQtd = data.qtd_mes ? numToStrQtd(data.qtd_mes) : '';
            const safeAdj = data.qtd_adj ? numToStrQtd(data.qtd_adj) : '';
            const safePreco = data.preco_un ? numToStrPreco(data.preco_un) + ' €' : '';
            return `
                <td>
                    <div class="chk-wrapper">
                        <input type="checkbox" class="chk-select-row" onchange="updateMoveArrows('sup')">
                        <div class="move-controls" style="display:none;">
                            <i class="fa-solid fa-chevron-up" onclick="moveSelectedRows('sup', 'up')"></i>
                            <i class="fa-solid fa-chevron-down" onclick="moveSelectedRows('sup', 'down')"></i>
                            <i class="fa-solid fa-copy" onclick="duplicateSelectedRows('sup')"></i>
                        </div>
                    </div>
                </td>
                <td><input type="text" class="inp-artigo" value="${e(data.artigo)}"></td>
                <td><textarea class="inp-desig" rows="1" oninput="ajustarTextareaRow(this)">${e(data.designacao)}</textarea></td>
                <td><input type="text" class="inp-unidade" value="${e(data.unidade)}"></td>
                <td><input type="text" class="qty qtd_adj" oninput="calc(this, true)" onblur="formatField(this)" value="${safeAdj}"></td>
                <td><input type="text" class="qty preco_un" oninput="calc(this, true);" onblur="formatField(this)" value="${safePreco}"></td>
                <td><input type="text" class="total_adj readonly-text" readonly data-manual=""></td>
                <td><input type="text" class="qtd_mes" oninput="calc(this, true)" onblur="formatField(this)" value="${safeQtd}"></td><td><input type="text" class="total_mes readonly-text" readonly></td>
                <td><input type="text" class="qty qtd_ant readonly-text" value="${data.qtd_ant ? numToStrQtd(data.qtd_ant) : ''}" readonly></td>
                <td><input type="text" class="qtd_acum readonly-text" readonly></td><td><input type="text" class="total_acum readonly-text" readonly></td>
                <td><input type="text" class="saldo readonly-text" readonly></td>
                <td><input type="text" class="saldo_valor readonly-text" readonly></td>
                <td class="td-acoes-adj">
                    <button type="button" class="btn-row-action btn-add-row" title="Inserir linha abaixo" onclick="insertRowAfterSup(this)"><i class="fa-solid fa-plus"></i></button>
                    <button type="button" class="btn-row-action btn-del-row" onclick="this.closest('tr').remove(); calcularTotaisGerais();"><i class="fa-solid fa-trash-can"></i></button>
                </td>
            `;
        }

        function escHTML(t) {
            return String(t == null ? '' : t).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
        }

        // Linha adjudicada nova (sem idx — é uma linha acrescentada, não precisa de password)
        function novaLinhaAdj(d = {}) {
            const tr = document.createElement('tr');
            tr.innerHTML = `<td><input type="text" class="inp-artigo" value="${escHTML(d.artigo)}"></td><td><textarea class="inp-desig" rows="1">${escHTML(d.designacao)}</textarea></td><td><input type="text" class="inp-unidade" value="${escHTML(d.unidade)}"></td><td><input type="text" class="qty qtd_adj" oninput="calc(this)" onblur="formatField(this)" value="${d.qtd ? numToStrQtd(d.qtd) : ''}"></td><td><input type="text" class="qty preco_un" oninput="calc(this)" onblur="formatField(this)" value="${d.preco ? numToStrPreco(d.preco) : ''}"></td><td><input type="text" class="total_adj readonly-text" readonly></td><td><input type="text" class="qtd_mes" oninput="calc(this)" onblur="verificarExcesso(this); formatField(this)"></td><td><input type="text" class="total_mes readonly-text" readonly></td><td><input type="text" class="qty qtd_ant" onblur="formatField(this)"></td><td><input type="text" class="qtd_acum readonly-text" readonly></td><td><input type="text" class="total_acum readonly-text" readonly></td><td><input type="text" class="saldo readonly-text" readonly></td><td><input type="text" class="saldo_valor readonly-text" readonly></td><td><button type="button" class="btn-del-line" onclick="this.closest('tr').remove(); calcularTotaisGerais();"><i class="fa-solid fa-trash-can"></i></button></td>`;
            return tr;
        }

        function addLinhaAdj() {
            const corpo = document.getElementById('autoCorpoAdj');
            const tr = document.createElement('tr');
            tr.innerHTML = `<td><input type="text" class="inp-artigo"></td><td><textarea class="inp-desig" rows="1"></textarea></td><td><input type="text" class="inp-unidade"></td><td><input type="text" class="qty qtd_adj" oninput="calc(this)" onblur="formatField(this)"></td><td><input type="text" class="qty preco_un" oninput="calc(this)"></td><td><input type="text" class="total_adj readonly-text" readonly></td><td><input type="text" class="qtd_mes" oninput="calc(this)" onblur="verificarExcesso(this); formatField(this)"></td><td><input type="text" class="total_mes readonly-text" readonly></td><td><input type="text" class="qty qtd_ant" onblur="formatField(this)"></td><td><input type="text" class="qtd_acum readonly-text" readonly></td><td><input type="text" class="total_acum readonly-text" readonly></td><td><input type="text" class="saldo readonly-text" readonly></td><td><input type="text" class="saldo_valor readonly-text" readonly></td><td><button type="button" class="btn-del-line" onclick="this.closest('tr').remove(); calcularTotaisGerais();"><i class="fa-solid fa-trash-can"></i></button></td>`;
            corpo.appendChild(tr);
            ajustarTextareaRow(tr.querySelector('.inp-desig'));
        }

        // --- COPIAR LINHAS DOS ADJUDICADOS PARA OS SUPLEMENTARES ---
        let copiarSel = new Set();
        function linhasAdjParaCopiar() {
            return Array.from(document.querySelectorAll('#autoCorpoAdj tr')).map((row, i) => ({
                i,
                artigo: row.querySelector('.inp-artigo').value,
                designacao: row.querySelector('.inp-desig').value,
                unidade: row.querySelector('.inp-unidade').value,
                qtd: strToNum(row.querySelector('.qtd_adj').value),
                preco: strToNum(row.querySelector('.preco_un').value)
            }));
        }
        function abrirCopiarAdj() {
            copiarSel = new Set();
            document.getElementById('copiarFiltro').value = '';
            renderCopiarAdj();
            document.getElementById('modalCopiar').classList.add('show');
            setTimeout(() => document.getElementById('copiarFiltro').focus(), 50);
        }
        function fecharCopiarAdj() { document.getElementById('modalCopiar').classList.remove('show'); }
        function renderCopiarAdj() {
            const filtro = normTxt(document.getElementById('copiarFiltro').value);
            const titulos = document.getElementById('copiarTitulos').checked;
            const linhas = linhasAdjParaCopiar().filter(l => {
                const eTitulo = l.qtd === 0 && l.preco === 0 && l.unidade.trim() === '';
                if (eTitulo && !titulos) return false;
                if (filtro && !normTxt(l.artigo + ' ' + l.designacao).includes(filtro)) return false;
                return true;
            });
            const todasSel = linhas.length > 0 && linhas.every(l => copiarSel.has(l.i));
            let h = `<thead><tr><th style="width:30px"><input type="checkbox" ${todasSel ? 'checked' : ''} onclick="copiarMarcarTodas(this.checked)"></th><th>Artigo</th><th style="text-align:left">Descrição</th><th>Un.</th><th>Qtd Adj.</th><th>P. Unit</th></tr></thead><tbody>`;
            linhas.forEach(l => {
                const eTitulo = l.qtd === 0 && l.preco === 0 && l.unidade.trim() === '';
                const sel = copiarSel.has(l.i);
                h += `<tr class="${sel ? 'sel' : ''} ${eTitulo ? 'titulo' : ''}" onclick="copiarAlternar(${l.i})">
                        <td style="text-align:center"><input type="checkbox" ${sel ? 'checked' : ''} onclick="event.stopPropagation(); copiarAlternar(${l.i})"></td>
                        <td style="text-align:center">${escHTML(l.artigo)}</td><td class="t-l">${escHTML(l.designacao)}</td>
                        <td style="text-align:center">${escHTML(l.unidade)}</td><td>${l.qtd ? numToStrQtd(l.qtd) : ''}</td><td>${l.preco ? numToStrPreco(l.preco) + ' €' : ''}</td></tr>`;
            });
            if (!linhas.length) h += `<tr><td colspan="6" style="text-align:center;padding:18px;color:#7f8c8d">Nenhuma linha encontrada.</td></tr>`;
            document.getElementById('copiarTabela').innerHTML = h + '</tbody>';
            document.getElementById('copiarConta').textContent = copiarSel.size ? copiarSel.size + ' linha(s) selecionada(s)' : '';
        }
        window.copiarAlternar = function (i) { copiarSel.has(i) ? copiarSel.delete(i) : copiarSel.add(i); renderCopiarAdj(); };
        window.copiarMarcarTodas = function (on) {
            const filtro = normTxt(document.getElementById('copiarFiltro').value);
            const titulos = document.getElementById('copiarTitulos').checked;
            linhasAdjParaCopiar().forEach(l => {
                const eTitulo = l.qtd === 0 && l.preco === 0 && l.unidade.trim() === '';
                if (eTitulo && !titulos) return;
                if (filtro && !normTxt(l.artigo + ' ' + l.designacao).includes(filtro)) return;
                on ? copiarSel.add(l.i) : copiarSel.delete(l.i);
            });
            renderCopiarAdj();
        };
        function copiarAdjSelecionadas() {
            if (!copiarSel.size) { alert('Escolha pelo menos uma linha.'); return; }
            const comQtd = document.getElementById('copiarQtd').checked;
            const corpo = document.getElementById('autoCorpoSup');
            const selSup = corpo.querySelectorAll('.chk-select-row:checked');
            let ref = selSup.length ? selSup[selSup.length - 1].closest('tr') : null;
            const novas = [];
            linhasAdjParaCopiar().filter(l => copiarSel.has(l.i)).forEach(l => {
                const tr = document.createElement('tr');
                tr.innerHTML = getSupRowHTML({ artigo: l.artigo, designacao: l.designacao, unidade: l.unidade, preco_un: l.preco, qtd_adj: comQtd ? l.qtd : 0 });
                if (ref) { ref.after(tr); ref = tr; } else corpo.appendChild(tr);
                novas.push(tr);
            });
            novas.forEach(tr => { calc(tr.querySelector('.qtd_mes'), true); ajustarTextareaRow(tr.querySelector('.inp-desig')); tr.classList.add('linha-trocada'); });
            fecharCopiarAdj();
            if (novas[0]) novas[0].scrollIntoView({ behavior: 'smooth', block: 'center' });
        }

        function addLinhaSup(data = null) {
            const corpo = document.getElementById('autoCorpoSup');
            const tr = document.createElement('tr');
            tr.innerHTML = getSupRowHTML(data || {});
            corpo.appendChild(tr);
            if(data) calc(tr.querySelector('.qtd_mes'), true);
            ajustarTextareaRow(tr.querySelector('.inp-desig'));
        }

        function insertRowAfterSup(btn) {
            const currentRow = btn.closest('tr');
            const tbody = currentRow.parentNode;
            const newRow = tbody.insertRow(currentRow.sectionRowIndex + 1);
            newRow.innerHTML = getSupRowHTML();
            calc(newRow.querySelector('.qtd_mes'), true);
            ajustarTextareaRow(newRow.querySelector('.inp-desig'));
        }

        // --- FUNÇÕES DE DUPLICAR E MOVER ---
        function toggleAllRows(tipo, master) {
            const tbodyId = tipo === 'sup' ? '#autoCorpoSup' : '#autoCorpoAdj';
            document.querySelectorAll(tbodyId + ' .chk-select-row').forEach(chk => chk.checked = master.checked);
            updateMoveArrows(tipo);
        }

        function updateMoveArrows(tipo) {
            const tbodyId = tipo === 'sup' ? '#autoCorpoSup' : '#autoCorpoAdj';
            document.querySelectorAll(tbodyId + ' .move-controls').forEach(el => el.style.display = 'none');
            const checkedBoxes = document.querySelectorAll(tbodyId + ' .chk-select-row:checked');
            if (checkedBoxes.length > 0) {
                checkedBoxes.forEach(chk => {
                    const controls = chk.closest('.chk-wrapper').querySelector('.move-controls');
                    if (controls) controls.style.display = 'flex';
                });
            }
        }

        function moveSelectedRows(tipo, dir) {
            const tbodyId = tipo === 'sup' ? '#autoCorpoSup' : '#autoCorpoAdj';
            const tbody = document.querySelector(tbodyId); 
            const rows = Array.from(tbody.querySelectorAll('tr'));
            
            if (dir === 'up') {
                for (let i = 1; i < rows.length; i++) {
                    if (rows[i].querySelector('.chk-select-row').checked && !rows[i - 1].querySelector('.chk-select-row').checked) {
                        tbody.insertBefore(rows[i], rows[i - 1]); 
                        break;
                    }
                }
            } else {
                for (let i = rows.length - 2; i >= 0; i--) {
                    if (rows[i].querySelector('.chk-select-row').checked && !rows[i + 1].querySelector('.chk-select-row').checked) {
                        tbody.insertBefore(rows[i + 1], rows[i]); 
                        break;
                    }
                }
            }
            updateMoveArrows(tipo);
        }

        function duplicateSelectedRows(tipo) {
            const tbodyId = tipo === 'sup' ? '#autoCorpoSup' : '#autoCorpoAdj';
            const tbody = document.querySelector(tbodyId);
            const rows = Array.from(tbody.querySelectorAll('tr'));
            
            for (let i = rows.length - 1; i >= 0; i--) {
                const row = rows[i];
                if (row.querySelector('.chk-select-row').checked) {
                    let data = {
                        artigo: row.querySelector('.inp-artigo') ? row.querySelector('.inp-artigo').value : '',
                        designacao: row.querySelector('.inp-desig') ? row.querySelector('.inp-desig').value : '',
                        unidade: row.querySelector('.inp-unidade') ? row.querySelector('.inp-unidade').value : '',
                        qtd_adj: strToNum(row.querySelector('.qtd_adj') ? row.querySelector('.qtd_adj').value : ''),
                        qtd_mes: strToNum(row.querySelector('.qtd_mes') ? row.querySelector('.qtd_mes').value : ''),
                        preco_un: strToNum(row.querySelector('.preco_un') ? row.querySelector('.preco_un').value : '')
                    };
                    const newRow = tbody.insertRow(row.sectionRowIndex + 1);
                    newRow.innerHTML = getSupRowHTML(data);
                    const ta = newRow.querySelector('.inp-desig');
                    if(ta) { ajustarTextareaRow(ta); }
                    calc(newRow.querySelector('.qtd_mes'), true);
                }
            }
            updateMoveArrows(tipo);
        }

        // Linha de TOTAIS no fim de cada tabela
        function atualizarTotaisTabelas() {
            const cent = x => Math.round((x + Number.EPSILON) * 100) / 100;
            const fmt = v => numToStr(cent(v)) + ' €';
            const set = (id, v) => { const el = document.getElementById(id); if (el) el.textContent = fmt(v); };
            const somar = sel => {
                let t = 0, m = 0, a = 0, sd = 0;
                document.querySelectorAll(sel + ' tr').forEach(row => {
                    const v = valoresAdj(row);
                    t += v.tAdj; m += v.tMes; a += v.tAcum;
                    if (v.qAdj !== 0) sd += v.saldoV;      // o saldo só conta nas linhas com quantidade adjudicada
                });
                return { t, m, a, sd };
            };
            const A = somar('#autoCorpoAdj');
            set('tfAdjTotal', A.t); set('tfAdjMes', A.m); set('tfAdjAnt', A.a - A.m); set('tfAdjAcum', A.a); set('tfAdjSaldo', A.sd);
            const S = somar('#autoCorpoSup');
            set('tfSupTotal', S.t); set('tfSupMes', S.m); set('tfSupAnt', S.a - S.m); set('tfSupAcum', S.a); set('tfSupSaldo', S.sd);
        }

        function calcularTotaisGerais() {
            let total = 0;
            document.querySelectorAll('#autoCorpoAdj tr, #autoCorpoSup tr').forEach(row => { total += valoresAdj(row).tMes; });
            document.getElementById('dock_total').innerText = numToStr(total) + " €";
            atualizarTotaisTabelas();
            atualizarResumoOrcamento();
        }

        // =====================================================================
        // COMPARAÇÃO DOS TRABALHOS ADJUDICADOS COM O ORÇAMENTO DA OBRA
        // =====================================================================
        const ORC = <?= json_encode($orc_obra ?: null, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        const r2 = n => Math.round((n + Number.EPSILON) * 100) / 100;
        const normTxt = t => String(t || '').replace(/\s+/g, ' ').trim().toLowerCase();

        function totalAdjAuto() {
            let t = 0;
            document.querySelectorAll('#autoCorpoAdj tr').forEach(row => {
                t += valoresAdj(row).tAdj;
            });
            return r2(t);
        }

        function atualizarResumoOrcamento() {
            if (!ORC) return;
            const tA = totalAdjAuto();
            const dif = r2(tA - ORC.total);
            document.getElementById('totAdjAuto').textContent = numToStr(tA) + ' €';
            const el = document.getElementById('difOrc');
            el.textContent = (dif > 0 ? '+' : '') + numToStr(dif) + ' €';
            el.className = 'orc-val ' + (Math.abs(dif) < 0.01 ? 'dif-ok' : 'dif-mal');
        }

        // Chave de ligação: artigo (ou descrição, se não tiver artigo) + nº de ocorrência
        function chaveLinha(artigo, desig, contagem) {
            const base = normTxt(artigo) !== '' ? 'a:' + normTxt(artigo) : 'd:' + normTxt(desig);
            contagem[base] = (contagem[base] || 0) + 1;
            return base + '#' + contagem[base];
        }

        function lerLinhasAuto() {
            const cont = {};
            return Array.from(document.querySelectorAll('#autoCorpoAdj tr')).map(row => {
                const l = {
                    row,
                    artigo: row.querySelector('.inp-artigo').value,
                    designacao: row.querySelector('.inp-desig').value,
                    unidade: row.querySelector('.inp-unidade').value.trim(),
                    qtd: strToNum(row.querySelector('.qtd_adj').value),
                    preco: strToNum(row.querySelector('.preco_un').value),
                    ant: strToNum(row.querySelector('.qtd_ant').value),
                    mes: strToNum(row.querySelector('.qtd_mes').value),
                    tAdj: valoresAdj(row).tAdj
                };
                l.chave = chaveLinha(l.artigo, l.designacao, cont);
                return l;
            });
        }

        function compararComOrcamento() {
            const auto = lerLinhasAuto();
            const porChave = {};
            auto.forEach(l => porChave[l.chave] = l);
            const usadas = new Set();
            const cont = {};
            const res = [];
            ORC.linhas.forEach(o => {
                const ch = chaveLinha(o.artigo, o.designacao, cont);
                const a = porChave[ch];
                if (!a) { res.push({ estado: 'novo', o, a: null }); return; }
                usadas.add(ch);
                const difUn = normTxt(a.unidade) !== normTxt(o.unidade);
                const difQ = Math.abs(a.qtd - o.qtd) > 0.0005;
                const difP = document.getElementById('compPrecos').checked && Math.abs(a.preco - o.preco) > 0.0005;
                const difD = normTxt(a.designacao) !== normTxt(o.designacao);
                const executado = (a.ant !== 0 || a.mes !== 0);
                const conflito = (difUn || difQ || difP) && o.qtd === 0 && executado;
                const estado = (difUn || difQ || difP) ? 'dif' : (difD ? 'desc' : 'igual');
                res.push({ estado, o, a, difUn, difQ, difP, difD, conflito });
            });
            auto.forEach(a => {
                if (usadas.has(a.chave)) return;
                res.push({ estado: 'auto', o: null, a, executado: (a.ant !== 0 || a.mes !== 0) });
            });
            return res;
        }

        function renderComparacao() {
            const res = compararComOrcamento();
            const mostrarIguais = document.getElementById('compMostrarIguais').checked;
            const tA = totalAdjAuto();
            const dif = r2(tA - ORC.total);
            const nDif = res.filter(r => r.estado !== 'igual').length;

            let tot = `<span>Adjudicado no auto: <b>${numToStr(tA)} €</b></span>
                       <span>Orçamento ${escHTML(ORC.numero)} (linhas ${escHTML(ORC.tipo)}): <b>${numToStr(ORC.total)} €</b></span>
                       <span>Diferença: <b style="color:${Math.abs(dif) < 0.01 ? '#27ae60' : '#c0392b'}">${(dif > 0 ? '+' : '') + numToStr(dif)} €</b></span>
                       <span>Linhas com diferenças: <b>${nDif}</b></span>`;
            document.getElementById('compTotais').innerHTML = tot;

            const q = n => n ? numToStrQtd(n) : '';
            const e = n => n ? numToStr(n) : '';
            const pr = n => n ? numToStrPreco(n) : '';
            let h = `<thead><tr><th rowspan="2">Artigo</th><th rowspan="2" style="text-align:left">Descrição</th>
                     <th colspan="4" class="grp sep">No auto</th><th colspan="4" class="grp sep">No orçamento</th>
                     <th rowspan="2" class="sep">Dif. €</th><th rowspan="2">Situação</th></tr>
                     <tr><th class="sep">Un.</th><th>Qtd</th><th>P. Unit</th><th>Total</th><th class="sep">Un.</th><th>Qtd</th><th>P. Unit</th><th>Total</th></tr></thead><tbody>`;
            let linhas = 0;
            res.forEach(r => {
                if (r.estado === 'igual' && !mostrarIguais) return;
                linhas++;
                const a = r.a, o = r.o;
                const tAuto = a ? a.tAdj : 0;
                const tOrc = o ? totLinha(o.qtd, o.preco) : 0;
                const d = r2(tAuto - tOrc);
                const tag = {
                    igual: '<span class="tag tag-igual">Igual</span>',
                    dif: '<span class="tag tag-dif">Diferente</span>',
                    desc: '<span class="tag tag-desc">Descrição</span>',
                    novo: '<span class="tag tag-novo">Só no orçamento</span>',
                    auto: '<span class="tag tag-auto">Só no auto</span>'
                }[r.estado];
                let extra = '';
                if (r.conflito) extra = '<br><span class="tag tag-conf" title="No orçamento esta linha não tem quantidade, mas no auto já tem quantidades medidas. Mova-as primeiro com o botão ↕.">Tem medições — usar ↕</span>';
                if (r.estado === 'auto' && r.executado) extra = '<br><span class="tag tag-conf" title="Esta linha não existe no orçamento mas já tem quantidades medidas — fica no auto.">Tem medições — mantém-se</span>';
                const desc = escHTML((a ? a.designacao : o.designacao)) + (r.difD ? `<span class="desc-orc">Orç.: ${escHTML(o.designacao)}</span>` : '');
                h += `<tr class="st-${r.estado}">
                    <td>${escHTML(a ? a.artigo : o.artigo)}</td><td class="t-l">${desc}</td>
                    <td class="sep ${r.difUn ? 'x' : ''}">${a ? escHTML(a.unidade) : ''}</td><td class="${r.difQ ? 'x' : ''}">${a ? q(a.qtd) : ''}</td><td class="${r.difP ? 'x' : ''}">${a ? pr(a.preco) : ''}</td><td>${a ? e(tAuto) : ''}</td>
                    <td class="sep ${r.difUn ? 'x' : ''}">${o ? escHTML(o.unidade) : ''}</td><td class="${r.difQ ? 'x' : ''}">${o ? q(o.qtd) : ''}</td><td class="${r.difP ? 'x' : ''}">${o ? pr(o.preco) : ''}</td><td>${o ? e(tOrc) : ''}</td>
                    <td class="sep" style="color:${Math.abs(d) < 0.01 ? '#7f8c8d' : (d > 0 ? '#2980b9' : '#c0392b')};font-weight:700">${Math.abs(d) < 0.01 ? '' : (d > 0 ? '+' : '') + numToStr(d)}</td>
                    <td style="text-align:center">${tag}${extra}</td></tr>`;
            });
            if (!linhas) h += `<tr><td colspan="12" style="text-align:center;padding:20px;color:#27ae60;font-weight:700">✔ Os trabalhos adjudicados do auto estão iguais ao orçamento.</td></tr>`;
            h += '</tbody>';
            document.getElementById('compTabela').innerHTML = h;
            document.getElementById('btnAplicarOrc').disabled = (nDif === 0);
            document.getElementById('btnAplicarOrc').style.opacity = (nDif === 0) ? 0.5 : 1;
        }

        function abrirComparacao() {
            document.getElementById('compAviso').innerHTML = '';
            renderComparacao();
            document.getElementById('modalComp').classList.add('show');
        }
        function fecharComparacao() { document.getElementById('modalComp').classList.remove('show'); }

        function pedirAplicarOrcamento() {
            exigirPassword('Aplicar orçamento ao auto',
                'A unidade, quantidade adjudicada e descrição (e o preço, se a opção estiver ligada) dos trabalhos adjudicados vão passar a ser iguais aos do orçamento. As quantidades medidas mantêm-se. Introduza a password mestra:',
                aplicarOrcamento);
        }

        function aplicarOrcamento() {
            const res = compararComOrcamento();
            const corpo = document.getElementById('autoCorpoAdj');
            const autoOrig = lerLinhasAuto();
            let nAtual = 0, nNovas = 0, nRemov = 0, nConf = 0, nMantidas = 0;
            const novaOrdem = [];
            const linhaAuto = new Map();   // tr -> resultado
            res.forEach(r => {
                if (r.o) {
                    if (r.a) {
                        const row = r.a.row;
                        if (r.conflito) { nConf++; novaOrdem.push(row); return; }
                        if (r.estado !== 'igual') {
                            const ta = row.querySelector('.inp-desig');
                            ta.value = r.o.designacao;
                            row.querySelector('.inp-unidade').value = r.o.unidade;
                            row.querySelector('.qtd_adj').value = r.o.qtd ? numToStrQtd(r.o.qtd) : '';
                            if (r.difP || !r.a.preco) row.querySelector('.preco_un').value = r.o.preco ? numToStrPreco(r.o.preco) + ' €' : '';
                            if (r.difQ || r.difP) { const tm = row.querySelector('.total_adj'); if (tm) tm.dataset.manual = ''; }
                            const s = row.querySelector('.saldo'); if (s) { s.className = 'saldo readonly-text'; s.style.color = ''; }
                            ajustarTextareaRow(ta);
                            nAtual++;
                        }
                        novaOrdem.push(row);
                    } else {
                        const tr = novaLinhaAdj(r.o);
                        novaOrdem.push(tr);
                        nNovas++;
                    }
                }
            });
            // Linhas que só existem no auto: com medições mantêm-se (a seguir à linha que as antecedia); sem medições saem
            res.filter(r => r.estado === 'auto').forEach(r => {
                if (r.executado) {
                    const pos = autoOrig.findIndex(l => l.row === r.a.row);
                    let ins = 0;
                    for (let i = pos - 1; i >= 0; i--) {
                        const k = novaOrdem.indexOf(autoOrig[i].row);
                        if (k !== -1) { ins = k + 1; break; }
                    }
                    novaOrdem.splice(ins, 0, r.a.row);
                    nMantidas++;
                } else {
                    nRemov++;
                }
            });
            corpo.innerHTML = '';
            novaOrdem.forEach(tr => corpo.appendChild(tr));
            corpo.querySelectorAll('tr').forEach(tr => {
                ajustarTextareaRow(tr.querySelector('.inp-desig'));
                calc(tr.querySelector('.qtd_mes'), false);
            });
            calcularTotaisGerais();

            let msg = `<div class="comp-msg ok">✔ Orçamento aplicado: ${nAtual} linha(s) atualizada(s), ${nNovas} nova(s), ${nRemov} removida(s). Confira e carregue em <b>Gravar</b> para ficar guardado.</div>`;
            if (nConf) msg += `<div class="comp-msg alerta">⚠ ${nConf} linha(s) não foram alteradas porque já têm quantidades medidas e no orçamento são títulos. Mova as quantidades com o botão ↕ e volte a comparar.</div>`;
            if (nMantidas) msg += `<div class="comp-msg alerta">⚠ ${nMantidas} linha(s) que não existem no orçamento mantiveram-se porque já têm quantidades medidas.</div>`;
            document.getElementById('compAviso').innerHTML = msg;
            renderComparacao();
        }

        function ajustarTextareaRow(textarea) {
            if(!textarea) return;
            textarea.style.height = 'auto';
            textarea.style.height = (textarea.scrollHeight) + 'px';
            textarea.addEventListener('input', function() {
                this.style.height = 'auto';
                this.style.height = (this.scrollHeight) + 'px';
            });
        }

        window.addEventListener('keydown', function(e) { if (e.key === 'Enter') { if (e.target.nodeName === 'INPUT') e.preventDefault(); } }, true);

        document.getElementById('autoForm').onsubmit = function() {
            let items = [];
            document.querySelectorAll('#autoCorpoAdj tr').forEach(row => {
                const desigEl = row.querySelector('.inp-desig');
                items.push({ tipo: 'Adjudicado', idx: (desigEl.dataset.idx !== undefined ? desigEl.dataset.idx : null), artigo: row.querySelector('.inp-artigo').value, designacao: desigEl.value, unidade: row.querySelector('.inp-unidade').value, qtd_adj: row.querySelector('.qtd_adj').value, preco_un: row.querySelector('.preco_un').value, qtd_mes: row.querySelector('.qtd_mes').value, qtd_ant: row.querySelector('.qtd_ant').value, total_adj_manual: (row.querySelector('.total_adj').dataset.manual || ''), ac_ant: row.dataset.acAnt || '0', ac_acum: (row.dataset.acAcum !== undefined && row.dataset.acAcum !== '' ? row.dataset.acAcum : (row.dataset.acAnt || '0')), orc_comp: row.dataset.orcComp || '' });
            });
            document.querySelectorAll('#autoCorpoSup tr').forEach(row => {
                const antSup = row.querySelector('.qtd_ant');
                items.push({ tipo: 'Suplementar', idx: (antSup.dataset.idx !== undefined ? antSup.dataset.idx : null), artigo: row.querySelector('.inp-artigo').value, designacao: row.querySelector('.inp-desig').value, unidade: row.querySelector('.inp-unidade').value, qtd_adj: row.querySelector('.qtd_adj').value, preco_un: row.querySelector('.preco_un').value, qtd_mes: row.querySelector('.qtd_mes').value, qtd_ant: row.querySelector('.qtd_ant').value, total_adj_manual: (row.querySelector('.total_adj').dataset.manual || ''), ac_ant: row.dataset.acAnt || '0', ac_acum: (row.dataset.acAcum !== undefined && row.dataset.acAcum !== '' ? row.dataset.acAcum : (row.dataset.acAnt || '0')), orc_comp: row.dataset.orcComp || '' });
            });
            document.getElementById('itens_json').value = JSON.stringify(items);
        };

        // --- ORÇAMENTOS COMPLEMENTARES ADJUDICADOS → TRABALHOS SUPLEMENTARES ---
        const COMP_PENDENTES = <?= json_encode($comp_pendentes ?? [], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        function importarComplementar(id) {
            const c = COMP_PENDENTES.find(x => x.id === id);
            if (!c) return;
            const corpo = document.getElementById('autoCorpoSup');
            if (corpo.querySelector('tr[data-orc-comp="' + id + '"]')) return;   // já importado
            const novas = [];
            const add = d => {
                const tr = document.createElement('tr');
                tr.innerHTML = getSupRowHTML(d);
                tr.dataset.orcComp = String(id);
                corpo.appendChild(tr);
                novas.push(tr);
            };
            add({ artigo: '', designacao: 'ORÇAMENTO COMPLEMENTAR Nº ' + c.numero });
            c.linhas.forEach(l => add({ artigo: l.artigo, designacao: l.designacao, unidade: l.unidade, qtd_adj: l.qtd, preco_un: l.preco }));
            novas.forEach(tr => { calc(tr.querySelector('.qtd_mes'), true); ajustarTextareaRow(tr.querySelector('.inp-desig')); });
            const b = document.querySelector('.btn-comp-pend[data-comp="' + id + '"]');
            if (b) { b.classList.add('feito'); b.innerHTML = '<i class="fa-solid fa-check"></i> ' + escHTML(c.numero) + ' incluído — falta gravar'; b.onclick = null; }
        }

        window.addEventListener('DOMContentLoaded', () => {
            // Auto novo: os complementares adjudicados pendentes entram logo nos suplementares
            <?php if (!$modo_editar): ?>
            COMP_PENDENTES.forEach(c => importarComplementar(c.id));
            <?php endif; ?>
            document.querySelectorAll('#autoCorpoAdj tr .qtd_mes').forEach(el => calc(el, false));
            document.querySelectorAll('#autoCorpoSup tr .qtd_mes').forEach(el => calc(el, true));
            document.querySelectorAll('.inp-desig').forEach(el => ajustarTextareaRow(el));
            atualizarResumoOrcamento();

            // RESTAURAR SCROLL E LIMPAR
            const scrollSalvo = sessionStorage.getItem('autoScrollY');
            if (scrollSalvo !== null) {
                window.scrollTo(0, parseInt(scrollSalvo));
                sessionStorage.removeItem('autoScrollY');
            }
        });
    </script>
</body>
</html>