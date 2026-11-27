<?php
// =====================================================================
// COMPRAS DA OBRA (faturas / encomendas a fornecedor importadas linha a linha)
// - Importa o Excel exportado do programa de faturação (ENCOMENDA A FORNECEDOR / FATURA, .xls/.xlsx)
//   ou um CSV com as colunas Designação; Unidade; Quantidade; Preço Custo (o ficheiro é lido no browser).
// - Cada linha é associada a um artigo do ORÇAMENTO ADJUDICADO da obra e/ou da TABELA DE PREÇOS:
//   o sistema sugere (memória → referência → descrição parecida); se não houver sugestão, procura-se à mão.
//   A escolha fica memorizada (compras_memoria) para as compras seguintes.
// - A compra entra no Resultado da Obra (gestao.php) como despesa de Material (origem 'Compra', valor sem IVA).
// - Análise: comprado vs orçamentado por artigo, desvio de preço de custo, horas previstas do material comprado
//   (quantidade × tempo de M.O. do artigo) comparadas com as horas orçamentadas e as horas normais registadas.
// - Tempo de M.O. na tabela de preços e no orçamento: MINUTOS por unidade.
// - Nunca perder dados: uma compra não se apaga, anula-se (com a password de segurança) e fica guardada.
// =====================================================================
session_start();
require_once 'db.php';
require_once 'functions.php';
require_once 'obra_fame_comum.php';

// REGRA DE OURO 1: Validação de sessão
if (!isset($_SESSION['user_id'])) { header("Location: index.php"); exit; }

// REGRA DE OURO 2: Acesso restrito (o mesmo da Gestão Central: admin / administrativo)
$perfil_user = $_SESSION['perfil'] ?? '';
if (!in_array($perfil_user, ['admin', 'administrativo'])) { header("Location: index.php?erro=sem_permissao"); exit; }

// REGRA DE OURO 3: Primeiro nome no menu
$primeiro_nome = explode(' ', trim($_SESSION['nome'] ?? 'Utilizador'))[0];

// REGRA DE OURO 5: ID validado como número inteiro
$id_obra = filter_input(INPUT_GET, 'id_obra', FILTER_VALIDATE_INT);
$obra = $id_obra ? fameObra($pdo, $id_obra) : null;
if (!$obra) { header("Location: gestao.php"); exit; }
$orc = fameOrcamentoDaObra($pdo, $obra);

$e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$fmt = fn($v, $d = 2) => number_format((float)$v, $d, ',', ' ');
function fmtHoras($h) { $h = max(0, (float)$h); $hh = floor($h); $m = round(($h - $hh) * 60); if ($m == 60) { $hh++; $m = 0; } return $hh . 'h ' . str_pad($m, 2, '0', STR_PAD_LEFT) . 'm'; }
function normTxt($t) { return trim(preg_replace('/\s+/', ' ', mb_strtolower(fameSemAcentos((string)$t), 'UTF-8'))); }

// ---------- TABELAS (fora de transações: no MySQL, CREATE/ALTER TABLE fecham a transação) ----------
$erro_tabelas = '';
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS compras (
        id INT AUTO_INCREMENT PRIMARY KEY,
        id_obra INT NOT NULL,
        tipo_doc VARCHAR(60) NULL,
        numero_doc VARCHAR(60) NULL,
        data_doc DATE NULL,
        fornecedor VARCHAR(150) NULL,
        fornecedor_nif VARCHAR(20) NULL,
        total_mercadoria DECIMAL(12,2) NOT NULL DEFAULT 0,
        total_iva DECIMAL(12,2) NOT NULL DEFAULT 0,
        total_documento DECIMAL(12,2) NOT NULL DEFAULT 0,
        ficheiro_nome VARCHAR(255) NULL,
        estado VARCHAR(20) NOT NULL DEFAULT 'Ativa',
        id_despesa INT NULL,
        criado_por INT NULL, criado_em DATETIME NULL,
        anulado_por INT NULL, anulado_em DATETIME NULL,
        KEY idx_obra (id_obra)
    ) DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS compras_linhas (
        id INT AUTO_INCREMENT PRIMARY KEY,
        id_compra INT NOT NULL,
        ordem INT NOT NULL DEFAULT 0,
        designacao VARCHAR(255) NOT NULL,
        unidade VARCHAR(15) NULL,
        quantidade DECIMAL(14,4) NOT NULL DEFAULT 0,
        preco_unit DECIMAL(14,4) NOT NULL DEFAULT 0,
        desconto DECIMAL(6,2) NOT NULL DEFAULT 0,
        valor_liquido DECIMAL(12,2) NOT NULL DEFAULT 0,
        taxa_iva DECIMAL(5,2) NULL,
        assoc_tipo VARCHAR(15) NULL,
        id_artigo_tabela INT NULL,
        orc_designacao VARCHAR(255) NULL,
        orc_unidade VARCHAR(15) NULL,
        fator DECIMAL(14,6) NOT NULL DEFAULT 1,
        tempo_un DECIMAL(12,4) NULL,
        preco_ref DECIMAL(14,4) NULL,
        KEY idx_compra (id_compra)
    ) DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS compras_memoria (
        chave VARCHAR(191) NOT NULL PRIMARY KEY,
        id_artigo_tabela INT NULL,
        orc_designacao VARCHAR(255) NULL,
        orc_unidade VARCHAR(15) NULL,
        fator DECIMAL(14,6) NOT NULL DEFAULT 1,
        atualizado_por INT NULL, atualizado_em DATETIME NULL
    ) DEFAULT CHARSET=utf8mb4");
    if (!fameColunaExiste($pdo, 'obra_despesas', 'id_compra')) $pdo->exec("ALTER TABLE obra_despesas ADD COLUMN id_compra INT NULL");
} catch (Exception $ex) {
    error_log('obra_compras.php (tabelas): ' . $ex->getMessage());
    $erro_tabelas = 'Não foi possível preparar as tabelas das compras. Avise o administrador.';
}

// ---------- ARTIGOS DO ORÇAMENTO ADJUDICADO (somados por designação + unidade) ----------
// Linhas com vários artigos (composição) entram artigo a artigo. Tempo = minutos por unidade.
function artigosOrcamento($pdo, $id_orc) {
    $res = [];
    if (!$id_orc) return $res;
    $st = $pdo->prepare("SELECT * FROM orcamento_itens WHERE orcamento_id = ? ORDER BY id ASC");
    $st->execute([$id_orc]);
    $todas = $st->fetchAll(PDO::FETCH_ASSOC);
    $tem_interno = false;
    foreach ($todas as $it) if (($it['tipo_linha'] ?? 'Interno') !== 'Comercial' && (float)$it['quantidade'] > 0) { $tem_interno = true; break; }
    $junta = function ($desig, $un, $q, $pc, $t, $id_tab) use (&$res) {
        $desig = trim(preg_replace('/\s+/', ' ', (string)$desig));
        if ($desig === '' || $q <= 0) return;
        $k = fameChave($desig, $un);
        if (!isset($res[$k])) $res[$k] = ['chave' => $k, 'designacao' => $desig, 'unidade' => trim((string)$un), 'qtd' => 0, 'valor_custo' => 0, 'minutos' => 0, 'id_tab' => 0];
        $res[$k]['qtd'] += $q;
        $res[$k]['valor_custo'] += $q * $pc;
        $res[$k]['minutos'] += $q * $t;
        if (!$res[$k]['id_tab'] && $id_tab) $res[$k]['id_tab'] = (int)$id_tab;
    };
    foreach ($todas as $it) {
        if ((($it['tipo_linha'] ?? 'Interno') === 'Comercial') === $tem_interno) continue;
        $q = (float)$it['quantidade'];
        if ($q <= 0) continue;
        $comp = json_decode((string)($it['composicao_json'] ?? ''), true);
        if (is_array($comp) && $comp) {
            foreach ($comp as $c) $junta($c['desc'] ?? '', $c['un'] ?? '', (float)($c['q'] ?? 0) * $q, (float)($c['pc'] ?? 0), (float)($c['t'] ?? 0), (int)($c['id'] ?? 0));
            continue;
        }
        $junta($it['designacao'], $it['unidade'], $q, (float)$it['preco_unitario_custo'], (float)$it['tempo_mao_obra'], (int)($it['id_artigo_tabela'] ?? 0));
    }
    foreach ($res as &$a) {   // valores por unidade
        $a['pc'] = $a['qtd'] > 0 ? $a['valor_custo'] / $a['qtd'] : 0;
        $a['t'] = $a['qtd'] > 0 ? $a['minutos'] / $a['qtd'] : 0;
    }
    unset($a);
    return $res;
}
$art_orc = artigosOrcamento($pdo, $orc['id'] ?? 0);

// ---------- TABELA DE PREÇOS (tempo × nº de colaboradores = minutos de mão de obra por unidade) ----------
$art_tab = [];
try {
    foreach ($pdo->query("SELECT id, referencia, descricao, unidade, preco_custo, tempo_mao_obra, n_colaboradores FROM tabela_precos")->fetchAll(PDO::FETCH_ASSOC) as $t) {
        $art_tab[(int)$t['id']] = ['id' => (int)$t['id'], 'ref' => (string)$t['referencia'], 'desc' => (string)$t['descricao'], 'un' => (string)$t['unidade'],
                                  'pc' => (float)$t['preco_custo'], 't' => (float)$t['tempo_mao_obra'] * max(1, (int)$t['n_colaboradores'])];
    }
} catch (Exception $ex) { error_log('obra_compras.php (tabela): ' . $ex->getMessage()); }

// Valores de referência do artigo associado (sempre calculados no servidor)
function valoresArtigo($orc_chave, $id_tab, $art_orc, $art_tab) {
    if ($orc_chave !== '' && isset($art_orc[$orc_chave])) { $a = $art_orc[$orc_chave]; return [$a['designacao'], $a['unidade'], $a['id_tab'] ?: null, $a['t'], $a['pc']]; }
    if ($id_tab && isset($art_tab[$id_tab])) { $a = $art_tab[$id_tab]; return [null, $a['un'], $id_tab, $a['t'], $a['pc']]; }
    return [null, null, null, null, null];
}
function guardarMemoria($pdo, $designacao, $orc_desig, $orc_un, $id_tab, $fator) {
    $pdo->prepare("INSERT INTO compras_memoria (chave, id_artigo_tabela, orc_designacao, orc_unidade, fator, atualizado_por, atualizado_em) VALUES (?, ?, ?, ?, ?, ?, NOW())
                   ON DUPLICATE KEY UPDATE id_artigo_tabela = VALUES(id_artigo_tabela), orc_designacao = VALUES(orc_designacao), orc_unidade = VALUES(orc_unidade),
                   fator = VALUES(fator), atualizado_por = VALUES(atualizado_por), atualizado_em = NOW()")
        ->execute([mb_substr(normTxt($designacao), 0, 191), $id_tab ?: null, $orc_desig, $orc_un, $fator, $_SESSION['user_id']]);
}
// Alvo escolhido no browser: "o:<chave do orçamento>" ou "t:<id da tabela>" (ou vazio = sem associação)
function lerAlvo($alvo, $art_orc, $art_tab) {
    $alvo = (string)$alvo;
    if (strncmp($alvo, 'o:', 2) === 0 && isset($art_orc[substr($alvo, 2)])) return [substr($alvo, 2), 0];
    if (strncmp($alvo, 't:', 2) === 0 && isset($art_tab[(int)substr($alvo, 2)])) return ['', (int)substr($alvo, 2)];
    return ['', 0];
}
function numLimpo($v) { return is_numeric($v) ? (float)$v : (float)str_replace(',', '.', str_replace('.', '', (string)$v)); }

// ---------- AÇÕES ----------
$mensagem = ''; $tipo_mensagem = ''; $erro_duplicado = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$erro_tabelas) {
    $acao = $_POST['acao'] ?? '';
    $ajax = ($acao === 'associar_linha');
    // REGRA DE OURO 7: token CSRF
    if (!validar_csrf_token($_POST['csrf_token'] ?? '')) {
        if ($ajax) { header('Content-Type: application/json; charset=utf-8'); echo json_encode(['ok' => false, 'erro' => 'A sessão expirou. Atualize a página.']); exit; }
        $mensagem = "A sessão expirou ou o pedido não é válido. Atualize a página e tente de novo."; $tipo_mensagem = 'erro';
    }
    // --- Gravar uma compra importada ---
    elseif ($acao === 'gravar_compra') {
        $d = json_decode((string)($_POST['dados'] ?? ''), true);
        $linhas = is_array($d['linhas'] ?? null) ? $d['linhas'] : [];
        $cab = is_array($d['cabecalho'] ?? null) ? $d['cabecalho'] : [];
        $data_doc = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($cab['data'] ?? '')) ? $cab['data'] : date('Y-m-d');
        $tipo_doc = mb_substr(trim((string)($cab['tipo'] ?? '')), 0, 60);
        $num_doc = mb_substr(trim((string)($cab['numero'] ?? '')), 0, 60);
        $fornecedor = mb_substr(trim((string)($cab['fornecedor'] ?? '')), 0, 150);
        $nif = mb_substr(preg_replace('/\D/', '', (string)($cab['nif'] ?? '')), 0, 20);
        try {
            if (!$linhas) throw new Exception("O ficheiro não tem linhas de artigos.");
            if (count($linhas) > 1000) throw new Exception("Demasiadas linhas (máximo 1000).");
            // Mesmo documento já importado (em qualquer obra)?
            if ($num_doc !== '' && empty($_POST['confirmar_duplicado'])) {
                $st_dup = $pdo->prepare("SELECT c.id, c.id_obra, o.nome FROM compras c LEFT JOIN obras o ON o.id = c.id_obra WHERE c.estado = 'Ativa' AND c.numero_doc = ? AND COALESCE(c.fornecedor_nif, '') = ? LIMIT 1");
                $st_dup->execute([$num_doc, $nif]);
                if ($dup = $st_dup->fetch(PDO::FETCH_ASSOC)) {
                    $erro_duplicado = true;
                    throw new Exception("O documento $num_doc já foi importado (obra: " . ($dup['nome'] ?? '#' . $dup['id_obra']) . "). Nada foi gravado. Se for mesmo para importar outra vez, escolha o ficheiro de novo e marque \"Importar mesmo assim\".");
                }
            }
            $tot_merc = 0; $tot_iva = 0; $limpas = [];
            foreach ($linhas as $i => $l) {
                $desig = mb_substr(trim(preg_replace('/\s+/', ' ', (string)($l['desc'] ?? ''))), 0, 255);
                $q = numLimpo($l['qtd'] ?? 0); $p = numLimpo($l['preco'] ?? 0);
                if ($desig === '' || $q == 0) continue;
                $desc_pct = max(0, min(100, numLimpo($l['desconto'] ?? 0)));
                $liq = isset($l['valor']) && $l['valor'] !== '' ? round(numLimpo($l['valor']), 2) : round($q * $p * (1 - $desc_pct / 100), 2);
                $taxa = ($l['taxa'] ?? '') !== '' ? numLimpo($l['taxa']) : null;
                [$orc_k, $id_tab_sel] = lerAlvo($l['alvo'] ?? '', $art_orc, $art_tab);
                [$orc_desig, $orc_un, $id_tab, $t_un, $pc_ref] = valoresArtigo($orc_k, $id_tab_sel, $art_orc, $art_tab);
                $fator = numLimpo($l['fator'] ?? 1); if ($fator <= 0) $fator = 1;
                $assoc = ($orc_desig !== null || $id_tab) ? (in_array($l['origem'] ?? '', ['memoria', 'referencia', 'semelhante', 'manual'], true) ? $l['origem'] : 'manual') : null;
                $limpas[] = [$i + 1, $desig, mb_substr(trim((string)($l['un'] ?? '')), 0, 15), $q, $p, $desc_pct, $liq, $taxa, $assoc, $id_tab ?: null, $orc_desig, $orc_un, $fator, $t_un, $pc_ref];
                $tot_merc += $liq;
                if ($taxa !== null) $tot_iva += $liq * $taxa / 100;
            }
            if (!$limpas) throw new Exception("Nenhuma linha válida (com descrição e quantidade).");
            $tot_merc = round($tot_merc, 2); $tot_iva = round($tot_iva, 2);
            // Se o documento traz os totais e a mercadoria bate com a soma das linhas, usa o IVA do documento
            // (o IVA é calculado sobre o total, não linha a linha: evita diferenças de cêntimos)
            $doc_merc = isset($cab['mercadoria']) && $cab['mercadoria'] !== '' ? numLimpo($cab['mercadoria']) : null;
            $doc_iva = isset($cab['impostos']) && $cab['impostos'] !== '' ? numLimpo($cab['impostos']) : null;
            if ($doc_merc !== null && $doc_iva !== null && abs($doc_merc - $tot_merc) < 0.05) $tot_iva = round($doc_iva, 2);

            $pdo->beginTransaction();
            $pdo->prepare("INSERT INTO compras (id_obra, tipo_doc, numero_doc, data_doc, fornecedor, fornecedor_nif, total_mercadoria, total_iva, total_documento, ficheiro_nome, estado, criado_por, criado_em)
                           VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Ativa', ?, NOW())")
                ->execute([$id_obra, $tipo_doc, $num_doc, $data_doc, $fornecedor, $nif ?: null, $tot_merc, $tot_iva, round($tot_merc + $tot_iva, 2),
                           mb_substr(basename((string)($cab['ficheiro'] ?? '')), 0, 255), $_SESSION['user_id']]);
            $id_compra = (int)$pdo->lastInsertId();
            $ins = $pdo->prepare("INSERT INTO compras_linhas (id_compra, ordem, designacao, unidade, quantidade, preco_unit, desconto, valor_liquido, taxa_iva, assoc_tipo, id_artigo_tabela, orc_designacao, orc_unidade, fator, tempo_un, preco_ref)
                                  VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            foreach ($limpas as $r) {
                $ins->execute(array_merge([$id_compra], $r));
                if ($r[8]) guardarMemoria($pdo, $r[1], $r[10], $r[11], $r[9], $r[12]);   // memoriza a associação
            }
            // Despesa de Material no Resultado da Obra (valor sem IVA)
            $desc_desp = trim(($fornecedor !== '' ? $fornecedor . ' · ' : '') . ($num_doc !== '' ? $num_doc : 'Compra importada'));
            $pdo->prepare("INSERT INTO obra_despesas (obra_id, tipo_despesa, data_despesa, descricao, quantidade, valor, origem, id_utilizador, id_compra) VALUES (?, 'Material', ?, ?, 0, ?, 'Compra', NULL, ?)")
                ->execute([$id_obra, $data_doc, mb_substr($desc_desp, 0, 255), $tot_merc, $id_compra]);
            $pdo->prepare("UPDATE compras SET id_despesa = ? WHERE id = ?")->execute([(int)$pdo->lastInsertId(), $id_compra]);
            $pdo->commit();
            registrarLog($pdo, "Importou a compra $num_doc (" . count($limpas) . " linhas, $tot_merc €) na Obra #$id_obra", "Obras - Financeiro");
            header("Location: obra_compras.php?id_obra=$id_obra&gravado=$id_compra#compra-$id_compra"); exit;
        } catch (Exception $ex) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            if ($ex instanceof PDOException) { error_log('obra_compras.php (gravar): ' . $ex->getMessage()); $mensagem = "Não foi possível gravar a compra. Avise o administrador."; }
            else $mensagem = $ex->getMessage();
            $tipo_mensagem = 'erro';
        }
    }
    // --- Mudar a associação de uma linha já gravada (pedido do browser) ---
    elseif ($acao === 'associar_linha') {
        header('Content-Type: application/json; charset=utf-8');
        $id_l = filter_input(INPUT_POST, 'id_linha', FILTER_VALIDATE_INT);
        try {
            $st = $pdo->prepare("SELECT l.* FROM compras_linhas l JOIN compras c ON c.id = l.id_compra WHERE l.id = ? AND c.id_obra = ?");
            $st->execute([$id_l, $id_obra]);
            $lin = $st->fetch(PDO::FETCH_ASSOC);
            if (!$lin) throw new Exception('Linha não encontrada.');
            [$orc_k, $id_tab_sel] = lerAlvo($_POST['alvo'] ?? '', $art_orc, $art_tab);
            [$orc_desig, $orc_un, $id_tab, $t_un, $pc_ref] = valoresArtigo($orc_k, $id_tab_sel, $art_orc, $art_tab);
            $fator = numLimpo($_POST['fator'] ?? 1); if ($fator <= 0) $fator = 1;
            $assoc = ($orc_desig !== null || $id_tab) ? 'manual' : null;
            $pdo->prepare("UPDATE compras_linhas SET assoc_tipo = ?, id_artigo_tabela = ?, orc_designacao = ?, orc_unidade = ?, fator = ?, tempo_un = ?, preco_ref = ? WHERE id = ?")
                ->execute([$assoc, $id_tab ?: null, $orc_desig, $orc_un, $fator, $t_un, $pc_ref, $id_l]);
            if ($assoc) guardarMemoria($pdo, $lin['designacao'], $orc_desig, $orc_un, $id_tab, $fator);
            echo json_encode(['ok' => true]);
        } catch (Exception $ex) {
            error_log('obra_compras.php (associar): ' . $ex->getMessage());
            echo json_encode(['ok' => false, 'erro' => 'Não foi possível gravar a associação.']);
        }
        exit;
    }
    // --- Anular uma compra (password de segurança; a compra fica guardada como "Anulada") ---
    elseif ($acao === 'anular_compra') {
        $id_c = filter_input(INPUT_POST, 'id_compra', FILTER_VALIDATE_INT);
        $st_p = $pdo->prepare("SELECT valor FROM configuracoes WHERE chave = 'password_apagar' LIMIT 1");
        $st_p->execute();
        $pass = (string)$st_p->fetchColumn();
        if ($pass === '' || !hash_equals($pass, (string)($_POST['pass_seguranca'] ?? ''))) {
            $mensagem = "Password de segurança incorreta. Nada foi alterado."; $tipo_mensagem = 'erro';
        } else {
            try {
                $pdo->beginTransaction();
                $pdo->prepare("UPDATE compras SET estado = 'Anulada', anulado_por = ?, anulado_em = NOW() WHERE id = ? AND id_obra = ? AND estado = 'Ativa'")
                    ->execute([$_SESSION['user_id'], $id_c, $id_obra]);
                // A despesa sai do Resultado da Obra (os dados continuam na compra anulada)
                $pdo->prepare("DELETE FROM obra_despesas WHERE id_compra = ? AND obra_id = ? AND origem = 'Compra'")->execute([$id_c, $id_obra]);
                $pdo->commit();
                registrarLog($pdo, "Anulou a compra ID $id_c na Obra #$id_obra", "Obras - Financeiro");
                header("Location: obra_compras.php?id_obra=$id_obra&anulado=1"); exit;
            } catch (Exception $ex) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                error_log('obra_compras.php (anular): ' . $ex->getMessage());
                $mensagem = "Não foi possível anular a compra."; $tipo_mensagem = 'erro';
            }
        }
    }
}
if (!$mensagem && isset($_GET['gravado'])) { $mensagem = "Compra importada e lançada como despesa de Material da obra."; $tipo_mensagem = 'sucesso'; }
if (!$mensagem && isset($_GET['anulado'])) { $mensagem = "Compra anulada: saiu do Resultado da Obra (os dados continuam guardados)."; $tipo_mensagem = 'sucesso'; }
if ($erro_tabelas) { $mensagem = $erro_tabelas; $tipo_mensagem = 'erro'; }

// ---------- DADOS DA PÁGINA ----------
$compras = []; $linhas_por_compra = [];
$ver_anuladas = !empty($_GET['anuladas']);
try {
    $st = $pdo->prepare("SELECT * FROM compras WHERE id_obra = ?" . ($ver_anuladas ? "" : " AND estado = 'Ativa'") . " ORDER BY data_doc DESC, id DESC");
    $st->execute([$id_obra]);
    $compras = $st->fetchAll(PDO::FETCH_ASSOC);
    if ($compras) {
        $ids = array_column($compras, 'id');
        $st_l = $pdo->prepare("SELECT * FROM compras_linhas WHERE id_compra IN (" . implode(',', array_fill(0, count($ids), '?')) . ") ORDER BY id_compra, ordem, id");
        $st_l->execute($ids);
        foreach ($st_l->fetchAll(PDO::FETCH_ASSOC) as $l) $linhas_por_compra[$l['id_compra']][] = $l;
    }
} catch (Exception $ex) { error_log('obra_compras.php (ler): ' . $ex->getMessage()); }

// Análise (só compras ativas)
$tot_comprado = 0; $horas_material = 0; $n_linhas = 0; $n_assoc = 0; $sem_assoc = []; $fora_orc = [];
$por_artigo = [];   // chave do orçamento => comprado
foreach ($compras as $c) {
    if ($c['estado'] !== 'Ativa') continue;
    $tot_comprado += (float)$c['total_mercadoria'];
    foreach ($linhas_por_compra[$c['id']] ?? [] as $l) {
        $n_linhas++;
        $q_conv = (float)$l['quantidade'] * (float)$l['fator'];
        if ($l['tempo_un'] !== null) $horas_material += $q_conv * (float)$l['tempo_un'] / 60;
        if (!$l['assoc_tipo']) { $sem_assoc[] = $l; continue; }
        $n_assoc++;
        if ($l['orc_designacao'] !== null) {
            $k = fameChave($l['orc_designacao'], $l['orc_unidade']);
            if (!isset($por_artigo[$k])) $por_artigo[$k] = ['qtd' => 0, 'valor' => 0];
            $por_artigo[$k]['qtd'] += $q_conv;
            $por_artigo[$k]['valor'] += (float)$l['valor_liquido'];
        } else {
            $fora_orc[] = $l;
        }
    }
}
$custo_material_orc = 0; $horas_orc = 0;
foreach ($art_orc as $a) { $custo_material_orc += $a['valor_custo']; $horas_orc += $a['minutos'] / 60; }

// Horas normais registadas nos relatórios de obra (as mesmas da Gestão Central)
$horas_normais = 0;
try {
    $st_h = $pdo->prepare("SELECT COALESCE(SUM(od.quantidade), 0) FROM obra_despesas od INNER JOIN relatorios_obra r ON od.id_relatorio = r.id
                           WHERE od.obra_id = ? AND od.tipo_despesa = 'Mão de Obra' AND od.origem = 'Relatório' AND r.trabalho_extra = 0");
    $st_h->execute([$id_obra]);
    $horas_normais = (float)$st_h->fetchColumn();
} catch (Exception $ex) { error_log('obra_compras.php (horas): ' . $ex->getMessage()); }

// Lista para associar (orçamento + tabela) e memória, para o browser
$opcoes = [];
foreach ($art_orc as $k => $a) $opcoes[] = ['v' => 'o:' . $k, 'l' => 'ORÇ · ' . $a['designacao'] . ' [' . $a['unidade'] . ']', 'un' => $a['unidade'], 'id_tab' => $a['id_tab'], 'd' => $a['designacao'], 'ref' => ''];
foreach ($art_tab as $t) $opcoes[] = ['v' => 't:' . $t['id'], 'l' => 'TAB · ' . ($t['ref'] !== '' ? $t['ref'] . ' · ' : '') . $t['desc'] . ' [' . $t['un'] . ']', 'un' => $t['un'], 'id_tab' => $t['id'], 'd' => $t['desc'], 'ref' => $t['ref']];
$memoria = [];
try {
    foreach ($pdo->query("SELECT * FROM compras_memoria")->fetchAll(PDO::FETCH_ASSOC) as $m) {
        $alvo = '';
        if ($m['orc_designacao'] !== null && isset($art_orc[fameChave($m['orc_designacao'], $m['orc_unidade'])])) $alvo = 'o:' . fameChave($m['orc_designacao'], $m['orc_unidade']);
        elseif ($m['id_artigo_tabela'] && isset($art_tab[(int)$m['id_artigo_tabela']])) {
            // artigo da tabela que está no orçamento desta obra → associa ao orçamento
            foreach ($art_orc as $k => $a) if ($a['id_tab'] == $m['id_artigo_tabela']) { $alvo = 'o:' . $k; break; }
            if ($alvo === '') $alvo = 't:' . (int)$m['id_artigo_tabela'];
        }
        if ($alvo !== '') $memoria[$m['chave']] = ['alvo' => $alvo, 'fator' => (float)$m['fator']];
    }
} catch (Exception $ex) {}
$rotulo_alvo = function ($l) use ($art_orc, $art_tab) {
    if ($l['orc_designacao'] !== null) { $k = fameChave($l['orc_designacao'], $l['orc_unidade']); return isset($art_orc[$k]) ? 'ORÇ · ' . $art_orc[$k]['designacao'] . ' [' . $art_orc[$k]['unidade'] . ']' : 'ORÇ · ' . $l['orc_designacao']; }
    if ($l['id_artigo_tabela'] && isset($art_tab[(int)$l['id_artigo_tabela']])) { $t = $art_tab[(int)$l['id_artigo_tabela']]; return 'TAB · ' . ($t['ref'] !== '' ? $t['ref'] . ' · ' : '') . $t['desc'] . ' [' . $t['un'] . ']'; }
    return '';
};
$valor_alvo = function ($l) use ($art_orc) {
    if ($l['orc_designacao'] !== null) { $k = fameChave($l['orc_designacao'], $l['orc_unidade']); if (isset($art_orc[$k])) return 'o:' . $k; }
    if ($l['id_artigo_tabela']) return 't:' . (int)$l['id_artigo_tabela'];
    return '';
};
$ref_obra = $obra['referencia_obra'] ?: ($orc['numero_orcamento'] ?? (string)$id_obra);
$JSON = JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE;
?>
<!DOCTYPE html>
<html lang="pt-PT">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Compras - <?= $e($obra['nome']) ?> - Gestão MAB</title>
    <?php /* REGRA DE OURO 8: ícone MAB no separador */ ?>
    <link rel="icon" type="image/png" href="/mab.png">
    <link rel="apple-touch-icon" href="/mab.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { font-family: system-ui, -apple-system, sans-serif; box-sizing: border-box; }
        body { background: #f7f9fc; margin: 0; color: #333; font-size: 14px; padding-bottom: 60px; }
        input, select, button { font-family: inherit; font-size: 14px; }
        .topbar { background: rgba(255,255,255,0.95); padding: 10px 40px; box-shadow: 0 1px 0 #e8ecf1; display: flex; justify-content: space-between; align-items: center; position: sticky; top: 0; z-index: 100; }
        .topbar .logo { height: 35px; display: block; }
        .topbar-right { display: flex; align-items: center; gap: 12px; }
        .btn-voltar { width: 40px; height: 40px; border-radius: 50%; background: #fff; border: 1px solid #e8ecf1; color: #576574; display: inline-flex; align-items: center; justify-content: center; text-decoration: none; font-size: 16px; box-shadow: 0 2px 6px rgba(0,0,0,0.04); }
        .btn-voltar:hover { background: #eef2f6; color: #34495e; }
        .user-menu { position: relative; }
        .user-btn { background: #fff; border: 1px solid #e8ecf1; border-radius: 30px; font-weight: 600; color: #2c3e50; cursor: pointer; display: flex; align-items: center; gap: 10px; padding: 5px 14px 5px 5px; }
        .user-avatar { background: #e74c3c; color: #fff; width: 28px; height: 28px; border-radius: 50%; display: flex; justify-content: center; align-items: center; font-size: 13px; }
        .dropdown-content { display: none; position: absolute; right: 0; top: 100%; background: #fff; min-width: 180px; box-shadow: 0 12px 30px rgba(0,0,0,0.1); border-radius: 10px; border: 1px solid #e8ecf1; overflow: hidden; margin-top: 6px; z-index: 200; }
        .user-menu:hover .dropdown-content, .user-menu.aberto .dropdown-content { display: block; }
        .dropdown-content a { color: #576574; padding: 12px 20px; text-decoration: none; display: block; }
        .container { max-width: 1400px; margin: 20px auto; padding: 0 15px; }
        h1 { margin: 0; color: #2c3e50; font-size: 22px; font-weight: 800; }
        .sub { color: #7f8c8d; font-size: 13px; margin-top: 6px; }
        .badge-ref { font-size: 11px; color: #27ae60; font-weight: 700; background: #e8f5e9; padding: 3px 8px; border-radius: 5px; }
        .alert { padding: 13px 15px; border-radius: 8px; margin: 15px 0; font-weight: 600; }
        .alert-sucesso { background: #e8f5e9; color: #1e8449; border: 1px solid #c8e6c9; }
        .alert-erro { background: #fdeded; color: #c0392b; border: 1px solid #fadbd8; }
        .alert-info { background: #eaf2fb; color: #1f5f8b; border: 1px solid #aed1f0; }
        .card { background: #fff; border-radius: 12px; border: 1px solid #e1e5ea; box-shadow: 0 2px 10px rgba(0,0,0,0.02); margin-bottom: 18px; padding: 18px; }
        .card h2 { margin: 0 0 14px; font-size: 16px; color: #2c3e50; display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
        .kpis { display: grid; grid-template-columns: repeat(auto-fit, minmax(210px, 1fr)); gap: 12px; margin: 18px 0; }
        .kpi { background: #fff; border: 1px solid #e1e5ea; border-radius: 12px; padding: 14px 16px; }
        .kpi .t { font-size: 11px; font-weight: 700; color: #95a5a6; text-transform: uppercase; }
        .kpi .v { font-size: 20px; font-weight: 800; color: #2c3e50; margin-top: 4px; }
        .kpi .n { font-size: 12px; color: #7f8c8d; margin-top: 4px; }
        .pos { color: #27ae60 !important; } .neg { color: #c0392b !important; } .avi { color: #d35400 !important; }
        .btn { background: #27ae60; color: #fff; border: none; padding: 10px 16px; border-radius: 8px; font-weight: 700; cursor: pointer; display: inline-flex; align-items: center; gap: 8px; text-decoration: none; }
        .btn:hover { filter: brightness(0.95); }
        .btn[disabled] { opacity: .5; cursor: default; }
        .btn-out { background: #fff; color: #34495e; border: 1px solid #cfd6de; }
        .btn-red { background: #fff; color: #c0392b; border: 1px solid #f5b7b1; }
        .zona-ficheiro { display: block; border: 2px dashed #cfd6de; border-radius: 12px; padding: 26px; text-align: center; cursor: pointer; color: #576574; background: #fbfcfd; }
        .zona-ficheiro:hover, .zona-ficheiro.arrastar { border-color: #27ae60; background: #f0faf4; }
        .zona-ficheiro i { font-size: 28px; color: #27ae60; display: block; margin-bottom: 8px; }
        .table-responsive { width: 100%; overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; }
        th { padding: 9px 10px; text-align: left; font-size: 11px; text-transform: uppercase; color: #95a5a6; border-bottom: 1px solid #e1e5ea; background: #fafbfc; white-space: nowrap; }
        td { padding: 8px 10px; border-bottom: 1px solid #f2f3f5; vertical-align: middle; }
        .r { text-align: right; } .c { text-align: center; }
        .assoc-input { width: 100%; min-width: 260px; padding: 7px 9px; border: 1px solid #dcdde1; border-radius: 7px; background: #fff; }
        .fator-input { width: 80px; padding: 7px; border: 1px solid #dcdde1; border-radius: 7px; text-align: center; }
        tr.a-memoria .assoc-input, tr.a-referencia .assoc-input, tr.a-manual .assoc-input { border-color: #27ae60; background: #f0faf4; }
        tr.a-semelhante .assoc-input { border-color: #f0b27a; background: #fef9f3; }
        tr.a-nenhuma .assoc-input { border-color: #f5b7b1; background: #fff; }
        .tag { font-size: 10px; font-weight: 800; padding: 2px 7px; border-radius: 10px; text-transform: uppercase; white-space: nowrap; }
        .tag-memoria, .tag-referencia, .tag-manual { background: #e8f5e9; color: #1e8449; }
        .tag-semelhante { background: #fef5e7; color: #d35400; }
        .tag-nenhuma { background: #fdeded; color: #c0392b; }
        .un-dif { color: #d35400; font-size: 11px; font-weight: 700; display: block; }
        .cab-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); gap: 10px; margin-bottom: 14px; }
        .cab-grid label { font-size: 11px; font-weight: 700; color: #95a5a6; text-transform: uppercase; display: block; margin-bottom: 4px; }
        .cab-grid input { width: 100%; padding: 8px 10px; border: 1px solid #dcdde1; border-radius: 7px; }
        details.compra { border: 1px solid #e1e5ea; border-radius: 10px; margin-bottom: 10px; background: #fff; }
        details.compra > summary { padding: 12px 14px; cursor: pointer; display: flex; gap: 12px; align-items: center; flex-wrap: wrap; list-style: none; }
        details.compra > summary::-webkit-details-marker { display: none; }
        details.compra[open] > summary { border-bottom: 1px solid #e1e5ea; background: #fafbfc; }
        .s-doc { font-weight: 800; color: #2c3e50; } .s-forn { color: #576574; } .s-data { color: #95a5a6; font-size: 12px; }
        .s-tot { margin-left: auto; font-weight: 800; color: #2c3e50; }
        .anulada { opacity: .55; }
        .resumo-imp { display: flex; gap: 14px; flex-wrap: wrap; font-size: 13px; color: #576574; margin: 10px 0; }
        .resumo-imp b { color: #2c3e50; }
        .modal { display: none; position: fixed; inset: 0; background: rgba(0,0,0,.55); z-index: 1000; align-items: center; justify-content: center; }
        .modal.show { display: flex; }
        .modal-box { background: #fff; border-radius: 12px; padding: 22px; width: 92%; max-width: 420px; }
        .modal-box input { width: 100%; padding: 11px; border: 1px solid #dcdde1; border-radius: 8px; margin: 12px 0; }
        @media (max-width: 768px) {
            .topbar { padding: 10px 15px; }
            .user-nome { display: none; }
            /* Tabelas passam a cartões */
            .cartoes thead { display: none; }
            .cartoes, .cartoes tbody, .cartoes tr, .cartoes td { display: block; width: 100%; }
            .cartoes tr { border: 1px solid #e1e5ea; border-radius: 10px; margin-bottom: 10px; padding: 8px 0; background: #fff; }
            .cartoes td { border: none; padding: 4px 12px; text-align: left !important; }
            .cartoes td[data-label]::before { content: attr(data-label) ": "; font-size: 11px; font-weight: 700; color: #95a5a6; text-transform: uppercase; }
            .cartoes td.t-desc { font-weight: 700; }
            .assoc-input { min-width: 0; }
            .s-tot { margin-left: 0; }
        }
    </style>
</head>
<body>
    <header class="topbar">
        <div><a href="index.php" title="Página inicial"><img src="mab.png" alt="MAB" class="logo"></a></div>
        <div class="topbar-right">
            <a href="gestao.php?id_obra=<?= (int)$id_obra ?>" class="btn-voltar" title="Voltar" aria-label="Voltar"><i class="fa-solid fa-arrow-left"></i></a>
            <div class="user-menu">
                <button type="button" class="user-btn"><div class="user-avatar"><i class="fa-solid fa-user"></i></div><span class="user-nome"><?= $e($primeiro_nome) ?></span><i class="fa-solid fa-caret-down" style="font-size:12px; color:#95a5a6;"></i></button>
                <div class="dropdown-content">
                    <a href="perfil.php"><i class="fa-solid fa-id-badge" style="width:20px;"></i> Perfil</a>
                    <a href="index.php?logout=1" style="color:#e74c3c; border-top:1px solid #f1f2f6;"><i class="fa-solid fa-right-from-bracket" style="width:20px;"></i> Sair</a>
                </div>
            </div>
        </div>
    </header>

    <div class="container">
        <h1><i class="fa-solid fa-cart-shopping" style="color:#27ae60; margin-right:6px;"></i> Compras da obra</h1>
        <div class="sub"><strong style="color:#2c3e50;"><?= $e($obra['nome']) ?></strong> <span class="badge-ref">REF: <?= $e($ref_obra) ?></span>
            <?= !empty($obra['cliente_nome']) ? ' · ' . $e($obra['cliente_nome']) : '' ?>
            · <?= $orc ? 'Orçamento ' . $e($orc['numero_orcamento']) . ' (' . count($art_orc) . ' artigos)' : '<span class="avi">sem orçamento ligado: as associações só podem ser à tabela de preços</span>' ?></div>

        <?php if ($mensagem): ?><div class="alert alert-<?= $tipo_mensagem === 'sucesso' ? 'sucesso' : 'erro' ?>"><?= $e($mensagem) ?></div><?php endif; ?>

        <!-- INDICADORES -->
        <?php
            $dif_horas = $horas_normais - $horas_material;
            $pct_horas = $horas_material > 0 ? ($horas_normais / $horas_material - 1) * 100 : null;
        ?>
        <div class="kpis">
            <div class="kpi"><div class="t">Material comprado (s/ IVA)</div><div class="v"><?= $fmt($tot_comprado) ?> €</div>
                <div class="n">Orçamentado (custo): <?= $fmt($custo_material_orc) ?> €<?php if ($custo_material_orc > 0): ?> · <span class="<?= $tot_comprado > $custo_material_orc ? 'neg' : 'pos' ?>"><?= $fmt($tot_comprado / $custo_material_orc * 100, 0) ?>%</span><?php endif; ?></div></div>
            <div class="kpi"><div class="t">Horas previstas do material comprado</div><div class="v"><?= fmtHoras($horas_material) ?></div>
                <div class="n">Qtd comprada × tempo de M.O. do artigo<?= $n_linhas ? ' · ' . $n_assoc . '/' . $n_linhas . ' linhas associadas' : '' ?></div></div>
            <div class="kpi"><div class="t">Horas normais registadas</div><div class="v"><?= fmtHoras($horas_normais) ?></div>
                <div class="n">Orçamentadas na obra: <?= fmtHoras($horas_orc) ?></div></div>
            <div class="kpi"><div class="t">Registadas vs previstas (material)</div>
                <?php if ($pct_horas === null): ?><div class="v" style="font-size:15px; color:#95a5a6;">Sem material associado</div>
                <?php else: ?><div class="v <?= $dif_horas > 0 ? 'neg' : 'pos' ?>"><?= ($dif_horas > 0 ? '+' : '−') . fmtHoras(abs($dif_horas)) ?></div>
                    <div class="n"><?= $dif_horas > 0 ? 'Mais' : 'Menos' ?> <?= $fmt(abs($pct_horas), 0) ?>% de horas do que o previsto para o material comprado</div><?php endif; ?></div>
        </div>
        <?php if ($n_linhas && $n_assoc < $n_linhas): ?><div class="alert alert-info"><i class="fa-solid fa-circle-info"></i> <?= $n_linhas - $n_assoc ?> linha(s) ainda sem artigo associado não contam nas horas nem na comparação com o orçamento. Associe-as em baixo, na compra respetiva.</div><?php endif; ?>

        <!-- IMPORTAR -->
        <div class="card" id="cardImportar">
            <h2><i class="fa-solid fa-file-import" style="color:#27ae60;"></i> Importar fatura / encomenda</h2>
            <label class="zona-ficheiro" id="zonaFicheiro">
                <i class="fa-solid fa-file-excel"></i>
                <strong>Escolha ou arraste o ficheiro</strong>
                <div style="font-size:12px; margin-top:6px;">Excel exportado do programa de faturação (.xls / .xlsx) ou CSV com Designação; Unidade; Quantidade; Preço Custo</div>
                <input type="file" id="inputFicheiro" accept=".xls,.xlsx,.csv" style="display:none;">
            </label>
            <div id="importErro" class="alert alert-erro" style="display:none;"></div>

            <form method="POST" action="obra_compras.php?id_obra=<?= (int)$id_obra ?>" id="formImportar" style="display:none; margin-top:16px;">
                <?php /* REGRA DE OURO 7 */ ?><input type="hidden" name="csrf_token" value="<?= $e(gerar_csrf_token()) ?>">
                <input type="hidden" name="acao" value="gravar_compra">
                <input type="hidden" name="dados" id="dadosImportar">
                <div class="cab-grid">
                    <div><label>Documento</label><input type="text" id="cabTipo" maxlength="60"></div>
                    <div><label>Nº documento</label><input type="text" id="cabNumero" maxlength="60"></div>
                    <div><label>Data</label><input type="date" id="cabData"></div>
                    <div><label>Fornecedor</label><input type="text" id="cabFornecedor" maxlength="150"></div>
                    <div><label>NIF fornecedor</label><input type="text" id="cabNif" maxlength="20"></div>
                </div>
                <div class="resumo-imp" id="resumoImp"></div>
                <div class="table-responsive">
                    <table class="cartoes" id="tabImportar">
                        <thead><tr><th>Descrição na fatura</th><th class="r">Qtd</th><th>Un</th><th class="r">Preço un.</th><th class="r">Valor</th><th>Artigo associado (orçamento / tabela de preços)</th><th class="c">Conversão</th></tr></thead>
                        <tbody></tbody>
                    </table>
                </div>
                <p style="font-size:12px; color:#7f8c8d;"><i class="fa-solid fa-circle-info"></i> Verde: associação certa (memória ou referência). Laranja: sugestão por descrição parecida (confirme). Vermelho: sem sugestão, escreva parte do nome ou da referência para procurar. "Conversão": quantas unidades do artigo associado há em cada unidade da fatura (ex.: 1 km = 1000 m).</p>
                <label style="display:none; margin:10px 0; font-weight:600; color:#c0392b;" id="lblDuplicado"><input type="checkbox" name="confirmar_duplicado" value="1"> Importar mesmo assim (o documento já existe)</label>
                <div style="display:flex; gap:10px; justify-content:flex-end; flex-wrap:wrap;">
                    <button type="button" class="btn btn-out" onclick="cancelarImportacao()"><i class="fa-solid fa-xmark"></i> Cancelar</button>
                    <button type="submit" class="btn" id="btnGravarCompra"><i class="fa-solid fa-floppy-disk"></i> Gravar compra</button>
                </div>
            </form>
        </div>

        <!-- COMPRADO vs ORÇAMENTADO -->
        <?php if ($art_orc && $por_artigo): ?>
        <div class="card">
            <h2><i class="fa-solid fa-scale-balanced" style="color:#2980b9;"></i> Comprado vs orçamentado (artigos associados)</h2>
            <div class="table-responsive">
                <table class="cartoes">
                    <thead><tr><th>Artigo do orçamento</th><th>Un</th><th class="r">Orçamentado</th><th class="r">Comprado</th><th class="r">Diferença</th><th class="r">Preço custo orç.</th><th class="r">Preço médio compra</th><th class="r">Desvio preço</th></tr></thead>
                    <tbody>
                    <?php foreach ($art_orc as $k => $a): if (!isset($por_artigo[$k])) continue;
                        $comp = $por_artigo[$k]; $dif = $comp['qtd'] - $a['qtd'];
                        $pm = $comp['qtd'] > 0 ? $comp['valor'] / $comp['qtd'] : 0;
                        $desv = $a['pc'] > 0 ? ($pm / $a['pc'] - 1) * 100 : null; ?>
                        <tr>
                            <td class="t-desc"><?= $e($a['designacao']) ?></td>
                            <td data-label="Un"><?= $e($a['unidade']) ?></td>
                            <td class="r" data-label="Orçamentado"><?= fameQtd($a['qtd']) ?></td>
                            <td class="r" data-label="Comprado"><?= fameQtd($comp['qtd']) ?></td>
                            <td class="r <?= abs($dif) < 0.005 ? '' : ($dif > 0 ? 'avi' : 'neg') ?>" data-label="Diferença" title="<?= $dif > 0 ? 'Comprado a mais' : ($dif < 0 ? 'Ainda falta comprar' : '') ?>"><?= ($dif > 0 ? '+' : '') . fameQtd($dif) ?></td>
                            <td class="r" data-label="Preço custo orç."><?= $a['pc'] > 0 ? $fmt($a['pc'], 3) . ' €' : '-' ?></td>
                            <td class="r" data-label="Preço médio compra"><?= $fmt($pm, 3) ?> €</td>
                            <td class="r <?= $desv === null ? '' : ($desv > 2 ? 'neg' : ($desv < -2 ? 'pos' : '')) ?>" data-label="Desvio preço"><?= $desv === null ? '-' : (($desv > 0 ? '+' : '') . $fmt($desv, 1) . '%') ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php if ($fora_orc): ?><p style="font-size:12px; color:#7f8c8d; margin-bottom:0;"><i class="fa-solid fa-circle-info"></i> <?= count($fora_orc) ?> linha(s) associadas a artigos da tabela de preços que não estão no orçamento desta obra (contam nas horas, não nesta comparação).</p><?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- COMPRAS GRAVADAS -->
        <div class="card">
            <h2><i class="fa-solid fa-receipt" style="color:#576574;"></i> Compras importadas
                <a href="?id_obra=<?= (int)$id_obra ?><?= $ver_anuladas ? '' : '&amp;anuladas=1' ?>" style="margin-left:auto; font-size:12px; color:#2980b9; text-decoration:none;"><?= $ver_anuladas ? 'Esconder anuladas' : 'Mostrar também as anuladas' ?></a></h2>
            <?php if (!$compras): ?><p style="color:#95a5a6; margin:0;">Ainda não há compras importadas nesta obra.</p><?php endif; ?>
            <?php foreach ($compras as $c): $ls = $linhas_por_compra[$c['id']] ?? []; $na = count(array_filter($ls, fn($l) => $l['assoc_tipo'])); $ativa = $c['estado'] === 'Ativa'; ?>
                <details class="compra<?= $ativa ? '' : ' anulada' ?>" id="compra-<?= (int)$c['id'] ?>" <?= (isset($_GET['gravado']) && (int)$_GET['gravado'] === (int)$c['id']) ? 'open' : '' ?>>
                    <summary>
                        <i class="fa-solid fa-chevron-right" style="color:#95a5a6; font-size:11px;"></i>
                        <span class="s-doc"><?= $e(trim($c['tipo_doc'] . ' ' . $c['numero_doc']) ?: 'Compra #' . $c['id']) ?></span>
                        <span class="s-forn"><?= $e($c['fornecedor'] ?: '-') ?></span>
                        <span class="s-data"><?= $c['data_doc'] ? date('d/m/Y', strtotime($c['data_doc'])) : '' ?></span>
                        <span class="tag <?= $na === count($ls) ? 'tag-memoria' : 'tag-semelhante' ?>"><?= $na ?>/<?= count($ls) ?> associadas</span>
                        <?php if (!$ativa): ?><span class="tag tag-nenhuma">Anulada</span><?php endif; ?>
                        <span class="s-tot"><?= $fmt($c['total_mercadoria']) ?> € <span style="font-weight:400; color:#95a5a6; font-size:12px;">s/ IVA</span></span>
                    </summary>
                    <div style="padding:12px 14px;">
                        <div class="table-responsive">
                            <table class="cartoes">
                                <thead><tr><th>Descrição na fatura</th><th class="r">Qtd</th><th>Un</th><th class="r">Preço un.</th><th class="r">Valor</th><th>Artigo associado</th><th class="c">Conversão</th><th class="r">Horas prev.</th></tr></thead>
                                <tbody>
                                <?php foreach ($ls as $l): $tp = $l['assoc_tipo'] ?: 'nenhuma';
                                    $h = $l['tempo_un'] !== null ? (float)$l['quantidade'] * (float)$l['fator'] * (float)$l['tempo_un'] / 60 : null; ?>
                                    <tr class="a-<?= $e($tp) ?>" data-id="<?= (int)$l['id'] ?>">
                                        <td class="t-desc"><?= $e($l['designacao']) ?></td>
                                        <td class="r" data-label="Qtd"><?= fameQtd($l['quantidade']) ?></td>
                                        <td data-label="Un"><?= $e($l['unidade']) ?></td>
                                        <td class="r" data-label="Preço un."><?= $fmt($l['preco_unit'], 3) ?> €</td>
                                        <td class="r" data-label="Valor"><?= $fmt($l['valor_liquido']) ?> €</td>
                                        <td data-label="Artigo">
                                            <?php if ($ativa): ?>
                                                <input type="text" class="assoc-input" list="listaArtigos" value="<?= $e($rotulo_alvo($l)) ?>" data-alvo="<?= $e($valor_alvo($l)) ?>" placeholder="Procurar no orçamento / tabela de preços..." onchange="associarGravada(this)">
                                            <?php else: ?><?= $e($rotulo_alvo($l) ?: '-') ?><?php endif; ?>
                                        </td>
                                        <td class="c" data-label="Conversão"><?php if ($ativa): ?><input type="text" class="fator-input" inputmode="decimal" value="<?= $e(rtrim(rtrim(number_format((float)$l['fator'], 6, ',', ''), '0'), ',')) ?>" onchange="associarGravada(this.closest('tr').querySelector('.assoc-input'))" title="Unidades do artigo por cada unidade da fatura"><?php else: ?><?= fameQtd($l['fator']) ?><?php endif; ?></td>
                                        <td class="r" data-label="Horas prev."><?= $h === null ? '-' : fmtHoras($h) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <div style="display:flex; justify-content:space-between; align-items:center; gap:10px; flex-wrap:wrap; margin-top:10px; font-size:12px; color:#7f8c8d;">
                            <span>IVA: <?= $fmt($c['total_iva']) ?> € · Total do documento: <?= $fmt($c['total_documento']) ?> €<?= $c['ficheiro_nome'] ? ' · Ficheiro: ' . $e($c['ficheiro_nome']) : '' ?></span>
                            <?php if ($ativa): ?><button type="button" class="btn btn-red" onclick="abrirAnular(<?= (int)$c['id'] ?>, '<?= $e(addslashes(trim($c['tipo_doc'] . ' ' . $c['numero_doc']))) ?>')"><i class="fa-solid fa-ban"></i> Anular compra</button>
                            <?php else: ?><span>Anulada em <?= $c['anulado_em'] ? date('d/m/Y H:i', strtotime($c['anulado_em'])) : '' ?></span><?php endif; ?>
                        </div>
                    </div>
                </details>
            <?php endforeach; ?>
        </div>
    </div>

    <datalist id="listaArtigos"></datalist>

    <div class="modal" id="modalAnular">
        <div class="modal-box">
            <h3 style="margin-top:0; color:#c0392b;"><i class="fa-solid fa-ban"></i> Anular compra</h3>
            <p id="txtAnular" style="color:#576574;"></p>
            <form method="POST" action="obra_compras.php?id_obra=<?= (int)$id_obra ?>">
                <?php /* REGRA DE OURO 7 */ ?><input type="hidden" name="csrf_token" value="<?= $e(gerar_csrf_token()) ?>">
                <input type="hidden" name="acao" value="anular_compra">
                <input type="hidden" name="id_compra" id="anularId">
                <input type="password" name="pass_seguranca" placeholder="Password de segurança" required autocomplete="off">
                <div style="display:flex; gap:10px;">
                    <button type="button" class="btn btn-out" style="flex:1; justify-content:center;" onclick="document.getElementById('modalAnular').classList.remove('show')">Cancelar</button>
                    <button type="submit" class="btn" style="flex:1; justify-content:center; background:#c0392b;">Anular</button>
                </div>
            </form>
        </div>
    </div>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
    <script>
    const OPCOES = <?= json_encode($opcoes, $JSON) ?>;
    const MEMORIA = <?= json_encode((object)$memoria, $JSON) ?>;
    const CSRF = <?= json_encode(gerar_csrf_token()) ?>;
    const ID_OBRA = <?= (int)$id_obra ?>;
    const ERRO_DUPLICADO = <?= $erro_duplicado ? 'true' : 'false' ?>;

    // ---------- utilitários ----------
    const semAcentos = t => String(t || '').normalize('NFD').replace(/[̀-ͯ]/g, '');
    const normTxt = t => semAcentos(t).toLowerCase().replace(/\s+/g, ' ').trim();
    const PALAVRAS_VAZIAS = new Set(['de', 'da', 'do', 'das', 'dos', 'com', 'c', 'para', 'p', 'e', 'a', 'o', 'em', 'un', 'mt', 'ml', 'cx']);
    const tokens = t => normTxt(t).replace(/[^a-z0-9]+/g, ' ').split(' ').filter(x => x && !PALAVRAS_VAZIAS.has(x));
    const soAlnum = t => normTxt(t).replace(/[^a-z0-9]/g, '');
    function num(v) {
        if (typeof v === 'number') return v;
        let s = String(v ?? '').trim().replace(/\s/g, '').replace('€', '');
        if (s === '') return NaN;
        if (s.includes(',')) s = s.replace(/\./g, '').replace(',', '.');
        return parseFloat(s);
    }
    const fmt = (v, d = 2) => (isNaN(v) ? '' : Number(v).toLocaleString('pt-PT', { minimumFractionDigits: d, maximumFractionDigits: d }));
    const fmtQ = v => Number(v).toLocaleString('pt-PT', { maximumFractionDigits: 4 });
    const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

    // Lista de procura (uma só para todas as linhas)
    const PELO_ROTULO = new Map(), PELO_VALOR = new Map();
    (function () {
        const dl = document.getElementById('listaArtigos'), frag = document.createDocumentFragment();
        OPCOES.forEach(o => { PELO_ROTULO.set(o.l, o); PELO_VALOR.set(o.v, o); o._tok = new Set(tokens(o.d + ' ' + o.ref)); o._ref = soAlnum(o.ref); const op = document.createElement('option'); op.value = o.l; frag.appendChild(op); });
        dl.appendChild(frag);
    })();

    // Unidades: conversão por defeito (unidades da fatura → unidades do artigo)
    function unNorm(u) {
        u = normTxt(u).replace(/[^a-z]/g, '');
        if (['m', 'mt', 'mts', 'ml', 'metro', 'metros'].includes(u)) return 'm';
        if (['un', 'und', 'uni', 'unid', 'u', 'pc', 'pcs', 'peca'].includes(u)) return 'un';
        return u;
    }
    function fatorPadrao(unFat, unArt) {
        const a = unNorm(unFat), b = unNorm(unArt);
        if (!b || a === b) return 1;
        if (a === 'km' && b === 'm') return 1000;
        if (a === 'm' && b === 'km') return 0.001;
        if (a === 'ct' && b === 'un') return 100;      // cento
        return 1;
    }

    // ---------- SUGESTÃO DE ASSOCIAÇÃO: memória → referência → descrição parecida ----------
    function sugerir(desc) {
        const m = MEMORIA[normTxt(desc).slice(0, 191)];
        if (m && PELO_VALOR.has(m.alvo)) return { alvo: m.alvo, origem: 'memoria', fator: m.fator };
        // Referência: códigos da descrição (ex.: "21131", "D9000-SR-V2") iguais à referência de um artigo da tabela
        const codigos = (String(desc).match(/[A-Za-z0-9][A-Za-z0-9\-\/.]*\d[A-Za-z0-9\-\/.]*/g) || []).map(soAlnum).filter(c => c.length >= 4);
        if (codigos.length) {
            const porRef = OPCOES.filter(o => o._ref.length >= 4 && codigos.includes(o._ref));
            if (porRef.length) {
                const tab = porRef[0];
                // Se o artigo da tabela está no orçamento desta obra, associa ao orçamento
                const noOrc = OPCOES.find(o => o.v.startsWith('o:') && o.id_tab && o.id_tab === tab.id_tab);
                return { alvo: (noOrc || tab).v, origem: 'referencia' };
            }
        }
        // Descrição parecida (palavras em comum; números e códigos contam a dobrar)
        const td = new Set(tokens(desc)); if (!td.size) return null;
        let melhor = null, mScore = 0;
        for (const o of OPCOES) {
            let inter = 0, uni = 0;
            const peso = x => (/\d/.test(x) ? 2 : 1);
            td.forEach(x => { uni += peso(x); if (o._tok.has(x)) inter += peso(x); });
            o._tok.forEach(x => { if (!td.has(x)) uni += peso(x); });
            let s = uni ? inter / uni : 0;
            if (o.v.startsWith('o:')) s += 0.05;   // a obra tem prioridade
            if (s > mScore) { mScore = s; melhor = o; }
        }
        return (melhor && mScore >= 0.45) ? { alvo: melhor.v, origem: 'semelhante' } : null;
    }

    // ---------- LEITURA DO FICHEIRO ----------
    const zona = document.getElementById('zonaFicheiro'), inputF = document.getElementById('inputFicheiro');
    ['dragover', 'dragenter'].forEach(ev => zona.addEventListener(ev, e => { e.preventDefault(); zona.classList.add('arrastar'); }));
    ['dragleave', 'drop'].forEach(ev => zona.addEventListener(ev, e => { e.preventDefault(); zona.classList.remove('arrastar'); }));
    zona.addEventListener('drop', e => { if (e.dataTransfer.files[0]) lerFicheiro(e.dataTransfer.files[0]); });
    inputF.addEventListener('change', () => { if (inputF.files[0]) lerFicheiro(inputF.files[0]); inputF.value = ''; });

    let nomeFicheiro = '';
    function mostrarErro(t) { const d = document.getElementById('importErro'); d.textContent = t; d.style.display = t ? 'block' : 'none'; }
    function lerFicheiro(f) {
        mostrarErro(''); nomeFicheiro = f.name;
        if (typeof XLSX === 'undefined') { mostrarErro('Não foi possível carregar o leitor de Excel (verifique a ligação à internet).'); return; }
        const r = new FileReader();
        r.onload = ev => {
            try {
                const ehCsv = /\.csv$/i.test(f.name);
                let wb;
                if (ehCsv) {
                    let txt = new TextDecoder('utf-8').decode(ev.target.result);
                    if (txt.includes('�')) txt = new TextDecoder('windows-1252').decode(ev.target.result);
                    wb = XLSX.read(txt, { type: 'string', FS: txt.split('\n')[0].includes(';') ? ';' : ',' });
                } else {
                    wb = XLSX.read(new Uint8Array(ev.target.result), { type: 'array' });
                }
                const linhas = XLSX.utils.sheet_to_json(wb.Sheets[wb.SheetNames[0]], { header: 1, raw: true, defval: '' });
                const doc = interpretar(linhas);
                if (!doc.linhas.length) { mostrarErro('Não encontrei linhas de artigos neste ficheiro (procuro uma linha de títulos com "Descrição"/"Designação" e "Quantidade").'); return; }
                mostrarImportacao(doc);
            } catch (err) { console.error(err); mostrarErro('Não foi possível ler o ficheiro: ' + err.message); }
        };
        r.readAsArrayBuffer(f);
    }

    // Interpreta a folha: cabeçalho do documento + linhas de artigos (o mesmo título repete-se em cada página)
    function interpretar(L) {
        const doc = { tipo: '', numero: '', data: '', fornecedor: '', nif: '', mercadoria: NaN, impostos: NaN, total: NaN, linhas: [] };
        const txt = v => String(v ?? '').trim();
        // Cabeçalho (células com "TÍTULO\nvalor")
        for (const row of L) for (let i = 0; i < row.length; i++) {
            const c = txt(row[i]); if (!c) continue;
            const partes = c.split(/\r?\n/).map(s => s.trim());
            const t0 = normTxt(partes[0]);
            if (partes.length >= 2) {
                if (/^(encomenda|fatura|factura|guia|nota de credito|venda a dinheiro)/.test(t0) && !doc.numero) { doc.tipo = partes[0]; doc.numero = partes[1]; }
                else if (t0.startsWith('data doc') && !doc.data) doc.data = partes[1];
                else if (t0.startsWith('contribuinte') && !doc.nif) doc.nif = partes[1];
                else if (t0.startsWith('exmo') && !doc.fornecedor) doc.fornecedor = partes[1].replace(/^\.+$/, '');
            }
            const nxt = () => { for (let j = i + 1; j < row.length; j++) { const n = num(row[j]); if (!isNaN(n)) return n; } return NaN; };
            if (t0 === 'mercadoria:') doc.mercadoria = nxt();
            if (t0 === 'impostos:') doc.impostos = nxt();
            if (t0 === 'total do documento:') doc.total = nxt();
        }
        if (/^\d{2}[\/-]\d{2}[\/-]\d{4}$/.test(doc.data)) { const p = doc.data.split(/[\/-]/); doc.data = p[2] + '-' + p[1] + '-' + p[0]; }

        // Linhas de artigos: por baixo de cada linha de títulos
        let col = null;
        for (const row of L) {
            const n = row.map(c => normTxt(c));
            const iDesc = n.findIndex(c => c === 'descricao' || c === 'designacao' || c === 'artigo / descricao');
            const iQtd = n.findIndex(c => c === 'quantidade' || c === 'qtd' || c === 'qtd.');
            if (iDesc >= 0 && iQtd >= 0) {
                col = { desc: iDesc, qtd: iQtd,
                        un: n.findIndex(c => c === 'un' || c === 'un.' || c === 'unidade'),
                        preco: n.findIndex(c => c.startsWith('preco')),
                        ref: n.findIndex(c => c === 'artigo' || c === 'referencia' || c === 'ref.' || c === 'ref') };
                continue;
            }
            if (!col) continue;
            const desc = txt(row[col.desc]);
            const q = num(row[col.qtd]);
            if (!desc || isNaN(q) || q === 0) continue;
            if (/^(a transportar|transporte|total)/i.test(desc)) continue;
            const un = col.un >= 0 ? txt(row[col.un]) : '';
            const preco = col.preco >= 0 ? num(row[col.preco]) : NaN;
            // Depois do preço: [Desc.%] Val. Líquido  Taxa  (as colunas do Excel nem sempre batem com os títulos)
            const depois = [];
            for (let j = Math.max(col.preco, col.qtd, col.un) + 1; j < row.length; j++) { const v = num(row[j]); if (!isNaN(v) && txt(row[j]) !== '') depois.push(v); }
            let desconto = 0, valor = NaN, taxa = '';
            const bruto = q * (isNaN(preco) ? 0 : preco);
            if (depois.length >= 3) { desconto = depois[0]; valor = depois[1]; taxa = depois[2]; }
            else if (depois.length === 2) {
                if (Math.abs(depois[0] - bruto) < 0.02 || depois[0] > 30) { valor = depois[0]; taxa = depois[1]; }
                else { desconto = depois[0]; valor = depois[1]; }
            } else if (depois.length === 1) valor = depois[0];
            if (isNaN(valor)) valor = Math.round(bruto * (1 - desconto / 100) * 100) / 100;
            const ref = col.ref >= 0 && col.ref !== col.desc ? txt(row[col.ref]) : '';
            doc.linhas.push({ desc: ref && !desc.includes(ref) ? desc + ' ' + ref : desc, qtd: Math.round(q * 1e6) / 1e6, un, preco: isNaN(preco) ? (q ? valor / q : 0) : preco, desconto, valor, taxa });
        }
        return doc;
    }

    // ---------- ECRÃ DE IMPORTAÇÃO ----------
    let docAtual = null;
    function mostrarImportacao(doc) {
        docAtual = doc;
        document.getElementById('cabTipo').value = doc.tipo;
        document.getElementById('cabNumero').value = doc.numero;
        document.getElementById('cabData').value = /^\d{4}-\d{2}-\d{2}$/.test(doc.data) ? doc.data : new Date().toISOString().slice(0, 10);
        document.getElementById('cabFornecedor').value = doc.fornecedor;
        document.getElementById('cabNif').value = doc.nif;
        const tb = document.querySelector('#tabImportar tbody'); tb.innerHTML = '';
        doc.linhas.forEach((l, i) => {
            const s = sugerir(l.desc);
            const o = s ? PELO_VALOR.get(s.alvo) : null;
            const tr = document.createElement('tr');
            tr.dataset.i = i;
            tr.innerHTML = '<td class="t-desc">' + esc(l.desc) + '</td>'
                + '<td class="r" data-label="Qtd">' + fmtQ(l.qtd) + '</td>'
                + '<td data-label="Un">' + esc(l.un) + '</td>'
                + '<td class="r" data-label="Preço un.">' + fmt(l.preco, 3) + ' €</td>'
                + '<td class="r" data-label="Valor">' + fmt(l.valor) + ' €</td>'
                + '<td data-label="Artigo"><input type="text" class="assoc-input" list="listaArtigos" placeholder="Procurar no orçamento / tabela de preços..."> <span class="tag"></span><span class="un-dif"></span></td>'
                + '<td class="c" data-label="Conversão"><input type="text" class="fator-input" inputmode="decimal" title="Unidades do artigo por cada unidade da fatura"></td>';
            const inp = tr.querySelector('.assoc-input'), fat = tr.querySelector('.fator-input');
            inp.dataset.alvo = o ? o.v : ''; inp.value = o ? o.l : '';
            tr.dataset.origem = s ? s.origem : 'nenhuma';
            fat.value = String(s && s.fator ? s.fator : (o ? fatorPadrao(l.un, o.un) : 1)).replace('.', ',');
            inp.addEventListener('change', () => {
                const ob = PELO_ROTULO.get(inp.value);
                if (ob) { inp.dataset.alvo = ob.v; tr.dataset.origem = 'manual'; fat.value = String(fatorPadrao(l.un, ob.un)).replace('.', ','); }
                else { inp.dataset.alvo = ''; tr.dataset.origem = 'nenhuma'; if (inp.value !== '') inp.value = ''; }
                pintarLinha(tr, l);
            });
            tb.appendChild(tr);
            pintarLinha(tr, l);
        });
        const somaLinhas = doc.linhas.reduce((a, l) => a + l.valor, 0);
        document.getElementById('resumoImp').innerHTML =
            '<span><b>' + doc.linhas.length + '</b> linhas</span><span>Soma das linhas: <b>' + fmt(somaLinhas) + ' €</b></span>'
            + (!isNaN(doc.mercadoria) ? '<span>Mercadoria no documento: <b>' + fmt(doc.mercadoria) + ' €</b>' + (Math.abs(doc.mercadoria - somaLinhas) > 0.05 ? ' <span class="neg">(diferente da soma das linhas: verifique)</span>' : ' <span class="pos"><i class="fa-solid fa-check"></i></span>') + '</span>' : '')
            + (!isNaN(doc.total) ? '<span>Total c/ IVA: <b>' + fmt(doc.total) + ' €</b></span>' : '')
            + '<span id="nAssoc"></span>';
        contarAssoc();
        document.getElementById('formImportar').style.display = 'block';
        document.getElementById('zonaFicheiro').style.display = 'none';
        if (ERRO_DUPLICADO) document.getElementById('lblDuplicado').style.display = 'block';
        document.getElementById('cardImportar').scrollIntoView({ behavior: 'smooth' });
    }
    const NOMES_ORIGEM = { memoria: 'memória', referencia: 'referência', semelhante: 'confirmar', manual: 'escolhido', nenhuma: 'sem artigo' };
    function pintarLinha(tr, l) {
        const o = tr.dataset.origem, tag = tr.querySelector('.tag');
        tr.className = 'a-' + o;
        tag.className = 'tag tag-' + o; tag.textContent = NOMES_ORIGEM[o] || o;
        const ob = PELO_VALOR.get(tr.querySelector('.assoc-input').dataset.alvo);
        tr.querySelector('.un-dif').textContent = (ob && unNorm(ob.un) !== unNorm(l.un)) ? 'Fatura em "' + l.un + '", artigo em "' + ob.un + '": confirme a conversão' : '';
        contarAssoc();
    }
    function contarAssoc() {
        const rows = document.querySelectorAll('#tabImportar tbody tr');
        const ok = Array.from(rows).filter(r => r.querySelector('.assoc-input').dataset.alvo).length;
        const conf = Array.from(rows).filter(r => r.dataset.origem === 'semelhante').length;
        const el = document.getElementById('nAssoc');
        if (el) el.innerHTML = 'Associadas: <b>' + ok + '/' + rows.length + '</b>' + (conf ? ' · <span class="avi">' + conf + ' para confirmar</span>' : '');
    }
    function cancelarImportacao() {
        document.getElementById('formImportar').style.display = 'none';
        document.getElementById('zonaFicheiro').style.display = 'block';
        docAtual = null;
    }
    document.getElementById('formImportar').addEventListener('submit', ev => {
        if (!docAtual) { ev.preventDefault(); return; }
        const linhas = Array.from(document.querySelectorAll('#tabImportar tbody tr')).map(tr => {
            const l = docAtual.linhas[+tr.dataset.i];
            const f = num(tr.querySelector('.fator-input').value);
            return { desc: l.desc, qtd: l.qtd, un: l.un, preco: l.preco, desconto: l.desconto, valor: l.valor, taxa: l.taxa,
                     alvo: tr.querySelector('.assoc-input').dataset.alvo, origem: tr.dataset.origem, fator: isNaN(f) || f <= 0 ? 1 : f };
        });
        document.getElementById('dadosImportar').value = JSON.stringify({
            cabecalho: { tipo: document.getElementById('cabTipo').value, numero: document.getElementById('cabNumero').value, data: document.getElementById('cabData').value,
                         fornecedor: document.getElementById('cabFornecedor').value, nif: document.getElementById('cabNif').value, ficheiro: nomeFicheiro,
                         mercadoria: isNaN(docAtual.mercadoria) ? '' : docAtual.mercadoria, impostos: isNaN(docAtual.impostos) ? '' : docAtual.impostos },
            linhas });
        document.getElementById('btnGravarCompra').disabled = true;
    });

    // ---------- Associação de linhas já gravadas ----------
    async function associarGravada(inp) {
        const tr = inp.closest('tr');
        let alvo = '';
        if (inp.value !== '') { const ob = PELO_ROTULO.get(inp.value); if (!ob) { inp.value = ''; } else alvo = ob.v; }
        const f = num(tr.querySelector('.fator-input').value);
        const fd = new FormData();
        fd.append('acao', 'associar_linha'); fd.append('csrf_token', CSRF); fd.append('id_linha', tr.dataset.id);
        fd.append('alvo', alvo); fd.append('fator', isNaN(f) || f <= 0 ? 1 : f);
        inp.disabled = true;
        try {
            const r = await fetch('obra_compras.php?id_obra=' + ID_OBRA, { method: 'POST', body: fd });
            const j = await r.json();
            if (!j.ok) throw new Error(j.erro || 'erro');
            tr.className = alvo ? 'a-manual' : 'a-nenhuma';
            inp.dataset.alvo = alvo;
            inp.title = 'Gravado — atualize a página para ver as horas e a comparação recalculadas';
        } catch (err) { alert('Não foi possível gravar: ' + err.message); }
        inp.disabled = false;
    }

    function abrirAnular(id, doc) {
        document.getElementById('anularId').value = id;
        document.getElementById('txtAnular').textContent = 'A compra ' + doc + ' sai do Resultado da Obra. Os dados ficam guardados (podem ser vistos em "Mostrar também as anuladas").';
        document.getElementById('modalAnular').classList.add('show');
    }

    // Menu do utilizador (toque em ecrãs táteis)
    (function () {
        const menu = document.querySelector('.user-menu'); if (!menu) return;
        menu.querySelector('.user-btn').addEventListener('click', e => { e.stopPropagation(); menu.classList.toggle('aberto'); });
        document.addEventListener('click', () => menu.classList.remove('aberto'));
    })();
    </script>
</body>
</html>
