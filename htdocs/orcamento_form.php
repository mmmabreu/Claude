<?php
// LIGAR EXIBIÇÃO DE ERROS PARA DETETAR O PROBLEMA DO ERROR 500
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

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
    if (!function_exists('registrarLog')) {
        function registrarLog($pdo, $acao, $modulo) { return true; }
    }
}

$perfil_user = $_SESSION['perfil'] ?? '';
$is_admin = in_array($perfil_user, ['admin', 'administrativo']);

// REGRA DE OURO 2: Acesso Restrito pela Matriz
$stmt_perm = $pdo->prepare("SELECT modulo FROM permissoes WHERE grupo = :perfil AND pode_aceder = 1");
$stmt_perm->execute(['perfil' => $perfil_user]);
$meus_acessos = $stmt_perm->fetchAll(PDO::FETCH_COLUMN);

if (!$is_admin && !in_array('orcamentos', $meus_acessos)) {
    header("Location: index.php?erro=sem_permissao");
    exit;
}

$nome_completo = $_SESSION['nome'] ?? 'Utilizador';
$primeiro_nome = explode(' ', trim($nome_completo))[0];

// Função segura para conversão JSON (evita quebras no JavaScript)
if (!function_exists('safe_json_encode')) {
    function safe_json_encode($data) {
        return json_encode($data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE) ?: '[]';
    }
}

// --- ATUALIZAR TABELA AUTOMATICAMENTE (Failsafe 100% Seguro) ---
$tem_tipo_linha = false;
try {
    $pdo->exec("ALTER TABLE orcamento_itens ADD COLUMN tipo_linha VARCHAR(20) DEFAULT 'Interno' AFTER orcamento_id");
} catch(Exception $e) {}

try {
    $col_check = $pdo->query("SHOW COLUMNS FROM orcamento_itens LIKE 'tipo_linha'");
    if ($col_check !== false && $col_check->rowCount() > 0) {
        $tem_tipo_linha = true;
    }
} catch (Exception $e) {}

// Ligação orçamento → vistoria de instalação de origem
try {
    if (!$pdo->query("SHOW COLUMNS FROM orcamentos LIKE 'id_vistoria'")->fetch()) {
        $pdo->exec("ALTER TABLE orcamentos ADD COLUMN id_vistoria INT NULL DEFAULT NULL");
    }
} catch (Exception $e) {}

// ORÇAMENTOS COMPLEMENTARES: ligação ao orçamento adjudicado de origem (ex.: 260035-C1 → 260035)
try {
    if (!$pdo->query("SHOW COLUMNS FROM orcamentos LIKE 'id_orcamento_pai'")->fetch()) {
        $pdo->exec("ALTER TABLE orcamentos ADD COLUMN id_orcamento_pai INT NULL DEFAULT NULL");
    }
    // o número passa a poder ter sufixo (-C1, -C2…): garantir que a coluna é de texto
    $col_num = $pdo->query("SHOW COLUMNS FROM orcamentos LIKE 'numero_orcamento'")->fetch(PDO::FETCH_ASSOC);
    if ($col_num && preg_match('/int|decimal/i', $col_num['Type'])) {
        $pdo->exec("ALTER TABLE orcamentos MODIFY COLUMN numero_orcamento VARCHAR(30)");
    }
} catch (Exception $e) {}

// Próximo número de complementar para um orçamento de origem: 260035-C1, 260035-C2, …
if (!function_exists('proximoNumeroComplementar')) {
    function proximoNumeroComplementar($pdo, $orc_pai) {
        $base = (string)$orc_pai['numero_orcamento'];
        $max = 0;
        $st = $pdo->prepare("SELECT numero_orcamento FROM orcamentos WHERE id_orcamento_pai = ? OR numero_orcamento LIKE ?");
        $st->execute([$orc_pai['id'], $base . '-C%']);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $n) {
            if (preg_match('/-C(\d+)$/i', (string)$n, $m)) $max = max($max, (int)$m[1]);
        }
        return $base . '-C' . ($max + 1);
    }
}

// O NIF de consumidor final (999999990) pode repetir-se: se a coluna nif da tabela clientes for única,
// a regra passa para uma coluna auxiliar (nif_unico) que é igual ao NIF, exceto para 999999990 e vazio (fica NULL).
// Todos os outros NIFs continuam sem poder repetir. Feito uma só vez.
if (!function_exists('permitirNifConsumidorFinal')) {
    function permitirNifConsumidorFinal($pdo) {
        try {
            $idx = $pdo->query("SHOW INDEX FROM clientes WHERE Non_unique = 0 AND Key_name <> 'PRIMARY'")->fetchAll(PDO::FETCH_ASSOC);
            $por_nome = [];
            foreach ($idx as $i) $por_nome[$i['Key_name']][] = $i['Column_name'];
            foreach ($por_nome as $nome => $cols) {
                if ($cols === ['nif']) {
                    if (!$pdo->query("SHOW COLUMNS FROM clientes LIKE 'nif_unico'")->fetch()) {
                        $pdo->exec("ALTER TABLE clientes ADD COLUMN nif_unico VARCHAR(20) AS (IF(nif = '999999990' OR nif = '', NULL, nif)) STORED");
                    }
                    $pdo->exec("ALTER TABLE clientes ADD UNIQUE KEY uk_nif_unico (nif_unico)");
                    $pdo->exec("ALTER TABLE clientes DROP INDEX `" . str_replace('`', '', $nome) . "`");
                }
            }
        } catch (Exception $e) { /* se não conseguir, o erro aparece ao gravar o cliente */ }
    }
}

// Proteção contra duplicação de funções
if (!function_exists('validarNIF')) {
    function validarNIF($nif) {
        if (empty(trim($nif))) return true;
        if ($nif === '999999990') return true;
        if (!preg_match("/^[0-9]{9}$/", $nif)) return false;
        $primeiros_digitos = ['1', '2', '3', '4', '5', '6', '7', '8', '9'];
        if (!in_array($nif[0], $primeiros_digitos)) return false;
        $soma = 0;
        for ($i = 0; $i < 8; $i++) { $soma += $nif[$i] * (9 - $i); }
        $resto = $soma % 11;
        $digito_controlo = ($resto < 2) ? 0 : 11 - $resto;
        return $digito_controlo == $nif[8];
    }
}

if (!function_exists('limparNumero')) {
    function limparNumero($num) {
        if (empty(trim((string)$num))) return 0;
        $num = str_replace(['.', ' '], '', (string)$num);
        $num = str_replace(',', '.', $num);
        return floatval($num);
    }
}

// Obter o Valor Hora/Homem
// Morada da sede (ponto de partida para calcular a distância à obra). Pode mudar-se em configuracoes → morada_sede
$morada_sede = 'Rua Damião de Góis, 205, 4700-028 Braga';
try {
    $st_sede = $pdo->prepare("SELECT valor FROM configuracoes WHERE chave = 'morada_sede' LIMIT 1");
    $st_sede->execute();
    $v_sede = trim((string)$st_sede->fetchColumn());
    if ($v_sede !== '') $morada_sede = $v_sede;
} catch (Exception $e) {}

$valor_hora_homem = 0;
try {
    $stmt_hora = $pdo->prepare("SELECT valor FROM configuracoes WHERE chave = 'valor_hora' LIMIT 1");
    if ($stmt_hora->execute()) {
        $valor_hora_homem_str = $stmt_hora->fetchColumn();
        $valor_hora_homem = $valor_hora_homem_str ? limparNumero($valor_hora_homem_str) : 0;
    }
} catch (Exception $e) {}

// Ligação de cada linha ao artigo da tabela de preços (para marca / ficha técnica na FAME)
try {
    if (!$pdo->query("SHOW COLUMNS FROM orcamento_itens LIKE 'id_artigo_tabela'")->fetch())
        $pdo->exec("ALTER TABLE orcamento_itens ADD COLUMN id_artigo_tabela INT NULL");
    // Composição da linha (vários artigos da tabela de preços numa só linha do orçamento)
    if (!$pdo->query("SHOW COLUMNS FROM orcamento_itens LIKE 'composicao_json'")->fetch())
        $pdo->exec("ALTER TABLE orcamento_itens ADD COLUMN composicao_json TEXT NULL");
} catch (Exception $e) {}

// --- CARREGAR TABELA DE PREÇOS ---
$tabela_precos_js = [];
try {
    $stmt_tp = $pdo->query("SELECT id, referencia, descricao, preco_custo, preco_com_mo, unidade, tempo_mao_obra, n_colaboradores, margem_bloqueada FROM tabela_precos");
    if ($stmt_tp) {
        $tabela_precos_js = $stmt_tp->fetchAll(PDO::FETCH_ASSOC);
    }
    // Marca (e referência do fabricante, se o artigo não tiver referência) para a regra da pesquisa de material
    try {
        $marcas_tp = [];
        foreach ($pdo->query("SELECT t.id, COALESCE(NULLIF(t.marca, ''), f.marca, '') AS marca, COALESCE(f.referencia, '') AS ref_fab
                              FROM tabela_precos t LEFT JOIN fichas_tecnicas f ON f.id = t.id_ficha_tecnica")->fetchAll(PDO::FETCH_ASSOC) as $m) $marcas_tp[(int)$m['id']] = $m;
        foreach ($tabela_precos_js as &$tp) {
            $tp['marca'] = trim((string)($marcas_tp[(int)$tp['id']]['marca'] ?? ''));
            if (trim((string)$tp['referencia']) === '') $tp['referencia'] = trim((string)($marcas_tp[(int)$tp['id']]['ref_fab'] ?? ''));
        }
        unset($tp);
    } catch (Exception $e) {}   // sem marca / biblioteca de fichas: fica só descrição | referência
} catch (Exception $e) {}

// Fichas técnicas dos artigos da tabela de preços (ícone na linha do orçamento): [id_artigo => [u => link, n => nome]]
$fichas_artigos_js = [];
try {
    $st_fa = $pdo->query("SELECT t.id, f.marca, f.modelo, f.ficheiro FROM tabela_precos t JOIN fichas_tecnicas f ON f.id = t.id_ficha_tecnica WHERE f.ficheiro <> ''");
    foreach ($st_fa->fetchAll(PDO::FETCH_ASSOC) as $fa) {
        $fichas_artigos_js[(int)$fa['id']] = ['u' => 'fichas_tecnicas/' . rawurlencode(basename($fa['ficheiro'])), 'n' => trim($fa['marca'] . ' ' . $fa['modelo'])];
    }
} catch (Exception $e) {}   // ainda não há biblioteca de fichas

$mensagem = '';
$tipo_mensagem = '';

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$modo_editar = !empty($id);

$itens_para_tabela = [];
$obs_para_tabela = [];
$inclui_para_tabela = [];
$nao_inclui_para_tabela = [];

if (isset($_GET['editado']) && $_GET['editado'] == 1) {
    $mensagem = "Orçamento guardado com sucesso!";
    $tipo_mensagem = "sucesso";
}

$clientes = [];
try {
    $stmt_clientes = $pdo->query("SELECT id, nome, morada, codigo_postal, cidade FROM clientes WHERE ativo = 1 ORDER BY nome ASC");
    if ($stmt_clientes) {
        $clientes = $stmt_clientes->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Exception $e) {}

// 1. PREPARAR DADOS INICIAIS
if ($modo_editar) {
    $stmt = $pdo->prepare("SELECT * FROM orcamentos WHERE id = ?");
    $stmt->execute([$id]);
    $orcamento = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$orcamento) {
        header("Location: orcamentos.php");
        exit;
    }
    $titulo_pagina = "Editar Orçamento #" . $orcamento['numero_orcamento'];
} else {
    $ano_atual_curto = date('y');
    $numero_orcamento_gerado = $ano_atual_curto . "0100";

    try {
        $stmt_ref = $pdo->query("SELECT valor FROM configuracoes WHERE chave = 'ultimo_numero_projeto'");
        if ($stmt_ref) {
            $ultima_ref = $stmt_ref->fetchColumn();
            if ($ultima_ref && substr($ultima_ref, 0, 2) === $ano_atual_curto) {
                $numero_orcamento_gerado = (string)((int)$ultima_ref + 1);
                if ((int)$numero_orcamento_gerado < (int)($ano_atual_curto . "0100")) {
                    $numero_orcamento_gerado = $ano_atual_curto . "0100";
                }
            }
        }
    } catch (Exception $e) {}

    $import_vistoria_auto = filter_input(INPUT_GET, 'import_vistoria', FILTER_VALIDATE_INT) ?: 0;

    // NOVO ORÇAMENTO COMPLEMENTAR a um orçamento adjudicado (trabalhos pedidos durante a obra)
    $complementar_de = filter_input(INPUT_GET, 'complementar_de', FILTER_VALIDATE_INT)
                    ?: filter_input(INPUT_POST, 'id_orcamento_pai', FILTER_VALIDATE_INT);
    $orc_pai_novo = null;
    if ($complementar_de) {
        try {
            $st_p = $pdo->prepare("SELECT * FROM orcamentos WHERE id = ?");
            $st_p->execute([$complementar_de]);
            $orc_pai_novo = $st_p->fetch(PDO::FETCH_ASSOC) ?: null;
            // um complementar de um complementar fica sempre ligado ao orçamento de origem
            if ($orc_pai_novo && !empty($orc_pai_novo['id_orcamento_pai'])) {
                $st_p->execute([$orc_pai_novo['id_orcamento_pai']]);
                $orc_pai_novo = $st_p->fetch(PDO::FETCH_ASSOC) ?: $orc_pai_novo;
            }
        } catch (Exception $e) { $orc_pai_novo = null; }
        if ($orc_pai_novo) {
            $numero_orcamento_gerado = proximoNumeroComplementar($pdo, $orc_pai_novo);
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                $_POST['id_cliente'] = $orc_pai_novo['id_cliente'];
                $_POST['nome_obra'] = $orc_pai_novo['nome_obra'];
                $_POST['morada_obra'] = $orc_pai_novo['morada_obra'];
                $_POST['contacto_obra'] = $orc_pai_novo['contacto_obra'];
                $_POST['referencia_cliente'] = $orc_pai_novo['referencia_cliente'];
                $_POST['km'] = ((float)$orc_pai_novo['km'] > 0) ? number_format((float)$orc_pai_novo['km'], 2, ',', '') : '';
                $_POST['taxa_iva_pai'] = $orc_pai_novo['taxa_iva'];
                $mensagem = "Novo orçamento complementar ao Nº " . $orc_pai_novo['numero_orcamento'] . ". Introduza os novos trabalhos; quando o cliente aprovar, passe-o a Adjudicado e as linhas entram nos próximos autos da obra.";
                $tipo_mensagem = "info";
            }
        }
    }
    $import_visita_id = filter_input(INPUT_GET, 'import_visita', FILTER_VALIDATE_INT);
    $import_obra_id = filter_input(INPUT_GET, 'import_obra', FILTER_VALIDATE_INT);

    if ($import_visita_id) {
        try {
            $stmt_v = $pdo->prepare("SELECT v.*, c.id as cid FROM visitas v LEFT JOIN clientes c ON v.id_cliente = c.id WHERE v.id = ?");
            $stmt_v->execute([$import_visita_id]);
            if ($v = $stmt_v->fetch(PDO::FETCH_ASSOC)) {
                $numero_orcamento_gerado = !empty($v['referencia_visita']) ? $v['referencia_visita'] : $numero_orcamento_gerado;
                $_POST['id_cliente'] = $v['cid'];
                $_POST['nome_obra'] = $v['assunto'];
                $mensagem = "Dados importados da Visita Técnica. O orçamento irá partilhar a referência " . htmlspecialchars($numero_orcamento_gerado) . ".";
                $tipo_mensagem = "sucesso";

                // Levantamento de necessidades → linhas do Detalhe Interno (só as áreas marcadas "Fazer orçamento"; se nenhuma estiver marcada, todas)
                if (file_exists(__DIR__ . '/levantamento_def.php')) {
                    require_once __DIR__ . '/levantamento_def.php';
                    $lev = levCarregar($pdo, $import_visita_id);
                    $marcadas = array_filter($lev, fn($x) => !empty($x['fazer_orcamento']));
                    if ($marcadas) $lev = $marcadas;
                    $n_linhas = 0; $areas_imp = [];
                    foreach ($LEV_GRUPOS as $g) {
                        foreach ($g['areas'] as $ak) {
                            if (!isset($lev[$ak], $LEV_AREAS[$ak])) continue;
                            $linhas_area = levParaOrcamento($ak, $lev[$ak]);
                            if (!$linhas_area) continue;
                            // Título da área (sem unidade nem quantidade, como os títulos do orçamento)
                            $itens_para_tabela[] = ['tipo_linha' => 'Interno', 'designacao' => mb_strtoupper($LEV_AREAS[$ak]['nome'], 'UTF-8'), 'unidade' => '', 'quantidade' => ''];
                            foreach ($linhas_area as $la) {
                                $itens_para_tabela[] = ['tipo_linha' => 'Interno', 'designacao' => $la['designacao'], 'unidade' => $la['unidade'],
                                                        'quantidade' => number_format((float)$la['quantidade'], 2, ',', '')];
                                $n_linhas++;
                            }
                            $areas_imp[] = $LEV_AREAS[$ak]['nome'];
                        }
                    }
                    if ($n_linhas > 0) {
                        $mensagem .= " Importadas " . $n_linhas . " linhas do levantamento (" . htmlspecialchars(implode(', ', $areas_imp)) . ") para o Detalhe Interno. Falta escolher os artigos e os preços.";
                    }
                }
            }
        } catch (Exception $e) {}
    } elseif ($import_obra_id) {
        try {
            $stmt_o = $pdo->prepare("SELECT o.*, c.id as cid FROM obras o LEFT JOIN clientes c ON o.id_cliente = c.id WHERE o.id = ?");
            $stmt_o->execute([$import_obra_id]);
            if ($o = $stmt_o->fetch(PDO::FETCH_ASSOC)) {
                $numero_orcamento_gerado = !empty($o['referencia_obra']) ? $o['referencia_obra'] : $numero_orcamento_gerado;
                $_POST['id_cliente'] = $o['cid'];
                $_POST['nome_obra'] = $o['nome'];
                $_POST['morada_obra'] = $o['morada'];
                $_POST['km'] = (isset($o['km']) && (float)$o['km'] > 0) ? number_format((float)$o['km'], 2, ',', '') : '';
                $mensagem = "Dados importados da Obra. O orçamento irá partilhar a referência " . htmlspecialchars($numero_orcamento_gerado) . ".";
                $tipo_mensagem = "sucesso";
            }
        } catch (Exception $e) {}
    }

    $titulo_pagina = !empty($orc_pai_novo) ? "Novo Orçamento Complementar" : "Novo Orçamento";
}

$visitas_import = [];
$obras_import = [];
if (!$modo_editar) {
    try {
        $v_query = $pdo->query("SELECT v.id, v.id_cliente, v.referencia_visita, v.assunto, v.data_visita, c.nome as cliente_nome FROM visitas v LEFT JOIN clientes c ON v.id_cliente = c.id WHERE v.referencia_visita IS NOT NULL AND v.referencia_visita != '' ORDER BY v.data_visita DESC LIMIT 500");
        if ($v_query) $visitas_import = $v_query->fetchAll(PDO::FETCH_ASSOC);

        $o_query = $pdo->query("SELECT o.id, o.id_cliente, o.referencia_obra, o.nome, c.nome as cliente_nome FROM obras o LEFT JOIN clientes c ON o.id_cliente = c.id WHERE o.referencia_obra IS NOT NULL AND o.referencia_obra != '' ORDER BY o.id DESC LIMIT 500");
        if ($o_query) $obras_import = $o_query->fetchAll(PDO::FETCH_ASSOC);
    } catch(Exception $e) {}
}

// --- VISTORIAS DE INSTALAÇÃO PARA IMPORTAR (com as alterações a efetuar) ---
$vistorias_import = [];
try {
    $sql_vi = "SELECT vi.id, vi.id_cliente, vi.id_obra, vi.data_vistoria, vi.numero_pisos,
                      COALESCE(NULLIF(vi.referencia, ''), o.referencia_obra) AS referencia,
                      o.nome AS obra_nome, o.morada AS obra_morada, c.nome AS cliente_nome
               FROM vistorias_instalacao vi
               LEFT JOIN obras o ON vi.id_obra = o.id
               LEFT JOIN clientes c ON vi.id_cliente = c.id
               ORDER BY vi.data_vistoria DESC, vi.id DESC LIMIT 300";
    $rows_vi = $pdo->query($sql_vi)->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // Coluna 'referencia' ainda não criada (vistoria_instalacao_form.php ainda não foi aberto)
    try {
        $rows_vi = $pdo->query("SELECT vi.id, vi.id_cliente, vi.id_obra, vi.data_vistoria, vi.numero_pisos, o.referencia_obra AS referencia,
                                       o.nome AS obra_nome, o.morada AS obra_morada, c.nome AS cliente_nome
                                FROM vistorias_instalacao vi LEFT JOIN obras o ON vi.id_obra = o.id LEFT JOIN clientes c ON vi.id_cliente = c.id
                                ORDER BY vi.data_vistoria DESC, vi.id DESC LIMIT 300")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e2) { $rows_vi = []; }
}
if (!empty($rows_vi)) {
    $ids_vi = array_map('intval', array_column($rows_vi, 'id'));
    $in_vi = implode(',', $ids_vi);
    $pisos_vi = []; $alt_vi = []; $obs_vi = [];
    try {
        foreach ($pdo->query("SELECT id_vistoria, numero_piso, nome_piso FROM vistorias_instalacao_pisos WHERE id_vistoria IN ($in_vi) ORDER BY numero_piso ASC, id ASC") as $r) {
            $pisos_vi[$r['id_vistoria']][(int)$r['numero_piso']] = $r['nome_piso'];
        }
    } catch (Exception $e) {}
    try {
        foreach ($pdo->query("SELECT id_vistoria, referencia_piso, descricao FROM vistorias_instalacao_alteracoes WHERE id_vistoria IN ($in_vi) ORDER BY id ASC") as $r) {
            $alt_vi[$r['id_vistoria']][] = $r;
        }
    } catch (Exception $e) {}
    try {
        foreach ($pdo->query("SELECT id_vistoria, descricao FROM vistorias_instalacao_observacoes WHERE id_vistoria IN ($in_vi) ORDER BY id ASC") as $r) {
            $obs_vi[$r['id_vistoria']][] = $r['descricao'];
        }
    } catch (Exception $e) {}

    foreach ($rows_vi as $r) {
        $vid = (int)$r['id'];
        $linhas = [];
        foreach ($alt_vi[$vid] ?? [] as $a) {
            $ref = (string)($a['referencia_piso'] ?? '');
            if ($ref === 'entrada') {
                $local = 'Entrada';
            } elseif ($ref !== '' && (int)$ref > 0) {
                $nome_p = $pisos_vi[$vid][(int)$ref] ?? '';
                $local = 'Piso ' . (int)$ref . ($nome_p ? ' - ' . $nome_p : '');
            } else {
                $local = 'Geral';
            }
            $linhas[] = ['local' => $local, 'descricao' => trim((string)$a['descricao'])];
        }
        $vistorias_import[] = [
            'id' => $vid,
            'id_cliente' => $r['id_cliente'],
            'referencia' => (string)($r['referencia'] ?? ''),
            'data' => !empty($r['data_vistoria']) ? date('d/m/Y', strtotime($r['data_vistoria'])) : '',
            'obra_nome' => (string)($r['obra_nome'] ?? ''),
            'obra_morada' => (string)($r['obra_morada'] ?? ''),
            'cliente_nome' => (string)($r['cliente_nome'] ?? ''),
            'alteracoes' => $linhas,
            'observacoes' => $obs_vi[$vid] ?? []
        ];
    }
}

// 2. PROCESSAR GRAVAÇÃO (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // NIF de consumidor final (999999990): pode repetir-se em vários clientes.
        // (antes da transação: alterar a tabela termina qualquer transação aberta)
        if (($_POST['id_cliente'] ?? '') === 'novo' && trim($_POST['novo_cliente_nif'] ?? '') === '999999990') permitirNifConsumidorFinal($pdo);

        $pdo->beginTransaction();

        $pass_introduzida = $_POST['pass_seguranca'] ?? '';
        $stmt_pass = $pdo->prepare("SELECT valor FROM configuracoes WHERE chave = 'password_apagar' LIMIT 1");
        $stmt_pass->execute();
        $pass_config = $stmt_pass->fetchColumn();

        $id_cliente_final = $_POST['id_cliente'] ?? '';
        if ($id_cliente_final === 'novo') {
            $nif_cliente = trim($_POST['novo_cliente_nif'] ?? '');
            if (!validarNIF($nif_cliente)) throw new Exception("O NIF do novo cliente é inválido.");

            $stmt_cli_novo = $pdo->prepare("INSERT INTO clientes (nome, nif, telefone, email, morada, codigo_postal, cidade, ativo) VALUES (?, ?, ?, ?, ?, ?, ?, 1)");
            try {
            $stmt_cli_novo->execute([
                trim($_POST['novo_cliente_nome'] ?? ''), $nif_cliente, trim($_POST['novo_cliente_telefone'] ?? ''),
                trim($_POST['novo_cliente_email'] ?? ''), trim($_POST['novo_cliente_morada'] ?? ''),
                trim($_POST['novo_cliente_cp'] ?? ''), trim($_POST['novo_cliente_cidade'] ?? '')
            ]);
            } catch (PDOException $e_cli) {
                if ($e_cli->getCode() == '23000') throw new Exception("Já existe um cliente com o NIF $nif_cliente. Escolha-o na lista de clientes" . ($nif_cliente === '999999990' ? " (não foi possível permitir NIFs de consumidor final repetidos nesta base de dados)." : "."));
                throw $e_cli;
            }
            $id_cliente_final = $pdo->lastInsertId();
            registrarLog($pdo, "Criou Novo Cliente via Orçamento (Cliente ID: $id_cliente_final)", "Clientes");
        }

        $taxa_iva_valor = floatval($_POST['taxa_iva'] ?? 0);
        $mao_obra_grav = limparNumero($_POST['valor_mao_obra'] ?? '0');
        $km_grav = limparNumero($_POST['km'] ?? '0');

        $soma_custo_artigos = 0;
        $soma_venda_artigos_interno = 0;
        $soma_venda_artigos_comercial = 0;

        $itens_post = json_decode($_POST['itens_json'] ?? '[]', true);
        if (!is_array($itens_post)) $itens_post = [];

        $tem_comercial_post = false;

        foreach ($itens_post as $item) {
            if (($item['tipo_linha'] ?? 'Interno') === 'Comercial' && trim($item['designacao'] ?? '') !== '') {
                $tem_comercial_post = true;
            }
        }

        if (!empty($itens_post)) {
            foreach ($itens_post as $item) {
                $desig = $item['designacao'] ?? '';
                $art = $item['artigo'] ?? '';
                $q_str = $item['quantidade'] ?? '';
                $tipo_l = $item['tipo_linha'] ?? 'Interno';

                if (trim($desig) !== '' || trim($art) !== '' || trim($q_str) !== '') {
                    $q = limparNumero($q_str);
                    $pc = limparNumero($item['preco_unitario_custo'] ?? '0');
                    $pv = limparNumero($item['preco_unitario_venda'] ?? '0');

                    if ($tipo_l === 'Interno') {
                        if ($q > 0 && $pv < $pc) {
                            if ($pass_introduzida !== $pass_config || empty($pass_introduzida)) {
                                throw new Exception("O preço de venda é inferior ao custo no artigo: " . ($desig ?: 'Sem nome') . ". É necessária a password de segurança para forçar a gravação.");
                            }
                        }
                        $soma_custo_artigos += round($q * $pc, 2);
                        $soma_venda_artigos_interno += round($q * $pv, 2);
                    } else {
                        $soma_venda_artigos_comercial += round($q * $pv, 2);
                    }
                }
            }
        }

        $soma_venda_artigos = $tem_comercial_post ? $soma_venda_artigos_comercial : $soma_venda_artigos_interno;

        $valor_custo_real = $soma_custo_artigos + $mao_obra_grav;
        $valor_iva_final = round($soma_venda_artigos * ($taxa_iva_valor / 100), 2);
        $total_com_iva_final = $soma_venda_artigos + $valor_iva_final;

        $estado_novo = $_POST['estado'] ?? 'Rascunho';
        $estado_original = $_POST['estado_original'] ?? '';
        // Orçamento complementar? (fica ligado à obra do orçamento de origem — não cria nem apaga obras)
        $id_pai_grav = $modo_editar ? (int)($orcamento['id_orcamento_pai'] ?? 0) : (int)($orc_pai_novo['id'] ?? 0);
        $e_complementar = $id_pai_grav > 0;
        $data_limite = empty($_POST['data_limite_entrega']) ? null : $_POST['data_limite_entrega'];

        if ($modo_editar) {
            if ($estado_original === 'Adjudicado' && $estado_novo !== 'Adjudicado') {
                if ($pass_introduzida !== $pass_config || empty($pass_introduzida)) {
                    throw new Exception("Password de segurança incorreta para reverter o estado Adjudicado.");
                }
                if (!$e_complementar) {
                    $stmt_del_obra = $pdo->prepare("DELETE FROM obras WHERE referencia_obra = ?");
                    $stmt_del_obra->execute([$_POST['numero_orcamento']]);
                    registrarLog($pdo, "Apagou Obra via Orçamento (Orç. ID: $id)", "Obras");
                } else {
                    registrarLog($pdo, "Reverteu a adjudicação do Orçamento Complementar #" . $_POST['numero_orcamento'], "Orçamentos");
                }
            }

            if ($estado_original !== 'Adjudicado' && $estado_novo === 'Adjudicado' && !$e_complementar) {
                $check_obra = $pdo->prepare("SELECT id FROM obras WHERE referencia_obra = ?");
                $check_obra->execute([$_POST['numero_orcamento']]);
                if (!$check_obra->fetch()) {
                    $stmt_cli = $pdo->prepare("SELECT nome FROM clientes WHERE id = ?");
                    $stmt_cli->execute([$id_cliente_final]);
                    $cliente_nome = $stmt_cli->fetchColumn() ?: 'Obra';

                    $sql_ins_obra = "INSERT INTO obras (nome, id_cliente, referencia_obra, morada, km, data_inicio, estado)
                                     VALUES (?, ?, ?, ?, ?, ?, 'Em Curso')";
                    $stmt_ins_obra = $pdo->prepare($sql_ins_obra);
                    $stmt_ins_obra->execute([
                        $cliente_nome, $id_cliente_final, $_POST['numero_orcamento'], $_POST['morada_obra'], $km_grav, date('Y-m-d')
                    ]);
                    registrarLog($pdo, "Criou Obra Automática via Orçamento (Orç. ID: $id)", "Obras");
                }
            }
        }

        // --- GRAVAR ORÇAMENTO ---
        if ($modo_editar) {
            $sql_upd = "UPDATE orcamentos SET
                        data = ?, data_validade = ?, data_limite_entrega = ?, id_cliente = ?, taxa_iva = ?,
                        nome_obra = ?, morada_obra = ?, km = ?, contacto_obra = ?, referencia_cliente = ?,
                        mao_obra = ?, valor_total_custo = ?, valor_custo_real = ?,
                        valor_total_liquido = ?, valor_iva = ?, valor_total_com_iva = ?, estado = ?
                        WHERE id = ?";

            $stmt_upd = $pdo->prepare($sql_upd);
            $stmt_upd->execute([
                $_POST['data'] ?? null, $_POST['data_validade_final'] ?? null, $data_limite, $id_cliente_final, $taxa_iva_valor,
                $_POST['nome_obra'] ?? null, $_POST['morada_obra'] ?? null, $km_grav, $_POST['contacto_obra'] ?? null, $_POST['referencia_cliente'] ?? null,
                $mao_obra_grav, $soma_custo_artigos, $valor_custo_real, $soma_venda_artigos, $valor_iva_final, $total_com_iva_final,
                $estado_novo, $id
            ]);
            $orcamento_id_atual = $id;
        } else {
            $sql_ins = "INSERT INTO orcamentos (
                            numero_orcamento, data, data_validade, data_limite_entrega, id_cliente, taxa_iva,
                            nome_obra, morada_obra, km, contacto_obra, referencia_cliente,
                            mao_obra, valor_total_custo, valor_custo_real,
                            valor_total_liquido, valor_iva, valor_total_com_iva, estado
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

            $stmt_ins = $pdo->prepare($sql_ins);
            $stmt_ins->execute([
                $_POST['numero_orcamento'], $_POST['data'], $_POST['data_validade_final'], $data_limite, $id_cliente_final, $taxa_iva_valor,
                $_POST['nome_obra'], $_POST['morada_obra'], $km_grav, $_POST['contacto_obra'], $_POST['referencia_cliente'],
                $mao_obra_grav, $soma_custo_artigos, $valor_custo_real,
                $soma_venda_artigos, $valor_iva_final, $total_com_iva_final, $estado_novo
            ]);
            $orcamento_id_atual = $pdo->lastInsertId();

            $numero_orcam_inserido = $_POST['numero_orcamento'];
            $stmt_curr = $pdo->query("SELECT valor FROM configuracoes WHERE chave = 'ultimo_numero_projeto'");
            $curr_val = $stmt_curr->fetchColumn();

            if (!$e_complementar && ctype_digit((string)$numero_orcam_inserido) && (int)$numero_orcam_inserido > (int)$curr_val) {
                $stmt_seq = $pdo->prepare("INSERT INTO configuracoes (chave, valor) VALUES ('ultimo_numero_projeto', ?) ON DUPLICATE KEY UPDATE valor = ?");
                $stmt_seq->execute([$numero_orcam_inserido, $numero_orcam_inserido]);
            }

            if ($e_complementar) {
                $pdo->prepare("UPDATE orcamentos SET id_orcamento_pai = ? WHERE id = ?")->execute([$id_pai_grav, $orcamento_id_atual]);
                registrarLog($pdo, "Criou Orçamento Complementar #" . $_POST['numero_orcamento'] . " (ao Orç. ID: $id_pai_grav)", "Orçamentos");
            }

            if ($estado_novo === 'Adjudicado' && !$e_complementar) {
                $stmt_cli = $pdo->prepare("SELECT nome FROM clientes WHERE id = ?");
                $stmt_cli->execute([$id_cliente_final]);
                $cliente_nome = $stmt_cli->fetchColumn() ?: 'Obra';

                $sql_ins_obra = "INSERT INTO obras (nome, id_cliente, referencia_obra, morada, km, data_inicio, estado)
                                 VALUES (?, ?, ?, ?, ?, ?, 'Em Curso')";
                $stmt_ins_obra = $pdo->prepare($sql_ins_obra);
                $stmt_ins_obra->execute([
                    $cliente_nome, $id_cliente_final, $_POST['numero_orcamento'], $_POST['morada_obra'], $km_grav, date('Y-m-d')
                ]);
                registrarLog($pdo, "Criou Obra Automática via Novo Orçamento (Orç. ID: $orcamento_id_atual)", "Obras");
            }
        }

        if ($e_complementar && $estado_novo === 'Adjudicado' && $estado_original !== 'Adjudicado') {
            registrarLog($pdo, "Adjudicou Orçamento Complementar #" . $_POST['numero_orcamento'] . " (entra nos próximos autos da obra)", "Orçamentos");
        }

        // --- LIGAÇÃO À VISTORIA DE ORIGEM ---
        $id_vistoria_origem = filter_input(INPUT_POST, 'id_vistoria_origem', FILTER_VALIDATE_INT);
        if ($id_vistoria_origem) {
            try { $pdo->prepare("UPDATE orcamentos SET id_vistoria = ? WHERE id = ?")->execute([$id_vistoria_origem, $orcamento_id_atual]); } catch (Exception $e_v) {}
        }

        // --- GRAVAR ARTIGOS (Failsafe BD Seguro) ---
        $pdo->prepare("DELETE FROM orcamento_itens WHERE orcamento_id = ?")->execute([$orcamento_id_atual]);

        if (!empty($itens_post)) {
            if ($tem_tipo_linha) {
                $sql_item = "INSERT INTO orcamento_itens (orcamento_id, tipo_linha, artigo, designacao, unidade, quantidade, preco_unitario_custo, tempo_mao_obra, valor_custo, preco_unitario_venda, valor_venda, margem_bloqueada) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            } else {
                $sql_item = "INSERT INTO orcamento_itens (orcamento_id, artigo, designacao, unidade, quantidade, preco_unitario_custo, tempo_mao_obra, valor_custo, preco_unitario_venda, valor_venda, margem_bloqueada) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            }

            $stmt_item = $pdo->prepare($sql_item);

            foreach ($itens_post as $item) {
                $desig = $item['designacao'] ?? '';
                $art = $item['artigo'] ?? '';
                $q_str = $item['quantidade'] ?? '';
                $tipo_l = $item['tipo_linha'] ?? 'Interno';

                if (trim($desig) !== '' || trim($art) !== '' || trim($q_str) !== '') {
                    $qtd = limparNumero($q_str);
                    $p_custo = limparNumero($item['preco_unitario_custo'] ?? '0');
                    $t_mo = limparNumero($item['tempo_mao_obra'] ?? '0');
                    $p_venda = limparNumero($item['preco_unitario_venda'] ?? '0');
                    $m_bloq = isset($item['margem_bloqueada']) ? (int)$item['margem_bloqueada'] : 0;

                    $v_c = round($qtd * $p_custo, 2);
                    $v_v = round($qtd * $p_venda, 2);

                    if ($tem_tipo_linha) {
                        $stmt_item->execute([ $orcamento_id_atual, $tipo_l, substr($art, 0, 12), trim($desig), substr($item['unidade'] ?? '', 0, 3), $qtd, $p_custo, $t_mo, $v_c, $p_venda, $v_v, $m_bloq ]);
                    } else {
                        $stmt_item->execute([ $orcamento_id_atual, substr($art, 0, 12), trim($desig), substr($item['unidade'] ?? '', 0, 3), $qtd, $p_custo, $t_mo, $v_c, $p_venda, $v_v, $m_bloq ]);
                    }
                    // Artigo da tabela de preços escolhido nesta linha
                    $id_art_tab = (int)($item['id_artigo_tabela'] ?? 0);
                    $id_linha_nova = $pdo->lastInsertId();
                    if ($id_art_tab > 0) {
                        try { $pdo->prepare("UPDATE orcamento_itens SET id_artigo_tabela = ? WHERE id = ?")->execute([$id_art_tab, $id_linha_nova]); } catch (Exception $e) {}
                    }
                    // Composição (vários artigos da tabela nesta linha): guarda a lista limpa
                    $comp = json_decode((string)($item['composicao_json'] ?? ''), true);
                    if (is_array($comp) && $comp) {
                        $comp_ok = [];
                        // Os valores vêm do browser como números (214.4); limparNumero() trata o "." como separador
                        // de milhares e transformava 214.4 em 2144 — números ficam como estão, texto passa por limparNumero()
                        $num_c = function ($v) { return (is_int($v) || is_float($v)) ? (float)$v : limparNumero($v); };
                        foreach ($comp as $c) {
                            $q_c = $num_c($c['q'] ?? 0);
                            if ($q_c <= 0 || trim((string)($c['desc'] ?? '')) === '') continue;
                            $comp_ok[] = ['id' => (int)($c['id'] ?? 0), 'ref' => mb_substr(trim((string)($c['ref'] ?? '')), 0, 50),
                                          'desc' => mb_substr(trim((string)$c['desc']), 0, 255), 'un' => mb_substr(trim((string)($c['un'] ?? '')), 0, 10),
                                          'q' => $q_c, 'pc' => $num_c($c['pc'] ?? 0), 't' => $num_c($c['t'] ?? 0), 'pv' => $num_c($c['pv'] ?? 0)];
                        }
                        if ($comp_ok) {
                            try { $pdo->prepare("UPDATE orcamento_itens SET composicao_json = ? WHERE id = ?")->execute([json_encode($comp_ok, JSON_UNESCAPED_UNICODE), $id_linha_nova]); } catch (Exception $e) {}
                        }
                    }
                }
            }
        }

        $pdo->prepare("DELETE FROM orcamento_observacoes WHERE orcamento_id = ?")->execute([$orcamento_id_atual]);
        if (isset($_POST['obs_linha']) && is_array($_POST['obs_linha'])) {
            $stmt_obs = $pdo->prepare("INSERT INTO orcamento_observacoes (orcamento_id, observacao) VALUES (?, ?)");
            foreach ($_POST['obs_linha'] as $obs_val) {
                if (trim($obs_val) !== '') $stmt_obs->execute([$orcamento_id_atual, trim($obs_val)]);
            }
        }

        $pdo->prepare("DELETE FROM orcamento_inclui WHERE orcamento_id = ?")->execute([$orcamento_id_atual]);
        if (isset($_POST['inclui_linha']) && is_array($_POST['inclui_linha'])) {
            $stmt_inc = $pdo->prepare("INSERT INTO orcamento_inclui (orcamento_id, texto) VALUES (?, ?)");
            foreach ($_POST['inclui_linha'] as $val) {
                if (trim($val) !== '') $stmt_inc->execute([$orcamento_id_atual, trim($val)]);
            }
        }

        $pdo->prepare("DELETE FROM orcamento_nao_inclui WHERE orcamento_id = ?")->execute([$orcamento_id_atual]);
        if (isset($_POST['nao_inclui_linha']) && is_array($_POST['nao_inclui_linha'])) {
            $stmt_ninc = $pdo->prepare("INSERT INTO orcamento_nao_inclui (orcamento_id, texto) VALUES (?, ?)");
            foreach ($_POST['nao_inclui_linha'] as $val) {
                if (trim($val) !== '') $stmt_ninc->execute([$orcamento_id_atual, trim($val)]);
            }
        }

        $pdo->commit();
        registrarLog($pdo, ($modo_editar ? "Editou" : "Criou") . " Orçamento #" . $_POST['numero_orcamento'], "Orçamentos");

        $redirect = $_POST['redirect_action'] ?? 'voltar';
        if ($redirect === 'continuar') {
            header("Location: orcamento_form.php?id=$orcamento_id_atual&editado=1");
        } else {
            header("Location: orcamentos.php?editado=1");
        }
        exit;

    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $mensagem = $e->getMessage();
        $tipo_mensagem = "erro";

        if (($_POST['id_cliente'] ?? '') === 'novo') { $manter_novo_cliente_aberto = true; }

        $itens_para_tabela = json_decode($_POST['itens_json'] ?? '[]', true);
        if (!is_array($itens_para_tabela)) $itens_para_tabela = [];

        $obs_para_tabela = $_POST['obs_linha'] ?? [];
        $inclui_para_tabela = $_POST['inclui_linha'] ?? [];
        $nao_inclui_para_tabela = $_POST['nao_inclui_linha'] ?? [];
    }
}

// 3. CARREGAMENTO INICIAL
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    if ($modo_editar) {
        $stmt_itens = $pdo->prepare("SELECT * FROM orcamento_itens WHERE orcamento_id = ? ORDER BY id ASC");
        $stmt_itens->execute([$id]);
        $itens_raw = $stmt_itens->fetchAll(PDO::FETCH_ASSOC);

        foreach ($itens_raw as $item) {
            $q_val = (float)($item['quantidade'] ?? 0);
            $item['quantidade'] = ($q_val == 0) ? '' : number_format($q_val, 2, ',', '');

            $pc_val = (float)($item['preco_unitario_custo'] ?? 0);
            $item['preco_unitario_custo'] = ($pc_val == 0) ? '' : number_format($pc_val, 2, ',', '');

            $tmo_val = (float)($item['tempo_mao_obra'] ?? 0);
            $item['tempo_mao_obra'] = ($tmo_val == 0) ? '' : number_format($tmo_val, 2, ',', '');

            $pv_val = (float)($item['preco_unitario_venda'] ?? 0);
            $item['preco_unitario_venda'] = ($pv_val == 0) ? '' : number_format($pv_val, 2, ',', '');

            $itens_para_tabela[] = $item;
        }

        $stmt_obs = $pdo->prepare("SELECT observacao FROM orcamento_observacoes WHERE orcamento_id = ? ORDER BY id ASC");
        $stmt_obs->execute([$id]);
        $obs_para_tabela = $stmt_obs->fetchAll(PDO::FETCH_COLUMN);

        try {
            $stmt_inc = $pdo->prepare("SELECT texto FROM orcamento_inclui WHERE orcamento_id = ? ORDER BY id ASC");
            $stmt_inc->execute([$id]);
            $inclui_para_tabela = $stmt_inc->fetchAll(PDO::FETCH_COLUMN);
        } catch(Exception $e) {}

        try {
            $stmt_ninc = $pdo->prepare("SELECT texto FROM orcamento_nao_inclui WHERE orcamento_id = ? ORDER BY id ASC");
            $stmt_ninc->execute([$id]);
            $nao_inclui_para_tabela = $stmt_ninc->fetchAll(PDO::FETCH_COLUMN);
        } catch(Exception $e) {}

        $_POST['numero_orcamento'] = $orcamento['numero_orcamento'] ?? '';
        $_POST['data'] = $orcamento['data'] ?? '';
        $_POST['data_validade_final'] = $orcamento['data_validade'] ?? '';
        $_POST['data_limite_entrega'] = $orcamento['data_limite_entrega'] ?? '';
        $_POST['id_cliente'] = $orcamento['id_cliente'] ?? '';
        $_POST['taxa_iva'] = $orcamento['taxa_iva'] ?? '23';
        $_POST['nome_obra'] = $orcamento['nome_obra'] ?? '';
        $_POST['morada_obra'] = $orcamento['morada_obra'] ?? '';
        $_POST['km'] = (isset($orcamento['km']) && (float)$orcamento['km'] > 0) ? number_format((float)$orcamento['km'], 2, ',', '') : '';
        $_POST['contacto_obra'] = $orcamento['contacto_obra'] ?? '';
        $_POST['referencia_cliente'] = $orcamento['referencia_cliente'] ?? '';
        $_POST['estado'] = $orcamento['estado'] ?? '';

        $_POST['valor_mao_obra'] = number_format((float)($orcamento['mao_obra'] ?? 0), 2, ',', '');
    } else {
        $_POST['numero_orcamento'] = $numero_orcamento_gerado ?? '';
        $_POST['data'] = date('Y-m-d');
        $_POST['data_validade_final'] = date('Y-m-d', strtotime('+30 days'));
        $_POST['data_limite_entrega'] = '';
        $_POST['taxa_iva'] = 23;
        $_POST['estado'] = 'Rascunho';
        $_POST['km'] = $_POST['km'] ?? '';
        $_POST['valor_mao_obra'] = '0,00';
    }
}

// --- ORÇAMENTOS COMPLEMENTARES (selo e lista no cabeçalho) ---
$orc_pai_info = null;            // se este orçamento é complementar: o de origem
$complementares_lista = [];      // se este orçamento é de origem: os seus complementares
try {
    $id_pai_atual = $modo_editar ? (int)($orcamento['id_orcamento_pai'] ?? 0) : (int)($orc_pai_novo['id'] ?? 0);
    if ($id_pai_atual) {
        $st = $pdo->prepare("SELECT id, numero_orcamento, estado FROM orcamentos WHERE id = ?");
        $st->execute([$id_pai_atual]);
        $orc_pai_info = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    } elseif ($modo_editar) {
        $st = $pdo->prepare("SELECT id, numero_orcamento, estado, valor_total_liquido FROM orcamentos WHERE id_orcamento_pai = ? ORDER BY id ASC");
        $st->execute([$id]);
        $complementares_lista = $st->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Exception $e) {}
$e_complementar_atual = !empty($orc_pai_info);
$pode_criar_complementar = $modo_editar && !$e_complementar_atual && (($orcamento['estado'] ?? '') === 'Adjudicado');

// Vistoria associada (para o campo oculto e o selo no cabeçalho)
$id_vistoria_atual = (int)($_POST['id_vistoria_origem'] ?? ($orcamento['id_vistoria'] ?? 0));
$ref_vistoria_atual = '';
foreach ($vistorias_import as $vimp) {
    if ($vimp['id'] === $id_vistoria_atual) { $ref_vistoria_atual = $vimp['referencia'] ?: ('#' . $vimp['id']); break; }
}
$import_vistoria_auto = $import_vistoria_auto ?? 0;

// Separar itens para os renders JavaScript de cada Tab
$itens_interno_js = [];
$itens_comercial_js = [];
foreach($itens_para_tabela as $it) {
    if (($it['tipo_linha'] ?? 'Interno') === 'Comercial') {
        $itens_comercial_js[] = $it;
    } else {
        $itens_interno_js[] = $it;
    }
}

$iva_atual = isset($_POST['taxa_iva']) ? (float)$_POST['taxa_iva'] : (isset($_POST['taxa_iva_pai']) ? (float)$_POST['taxa_iva_pai'] : 23);
$obs_finais = isset($_POST['obs_linha']) ? $_POST['obs_linha'] : $obs_para_tabela;
$inclui_finais = isset($_POST['inclui_linha']) ? $_POST['inclui_linha'] : $inclui_para_tabela;
$nao_inclui_finais = isset($_POST['nao_inclui_linha']) ? $_POST['nao_inclui_linha'] : $nao_inclui_para_tabela;
?>
<!DOCTYPE html>
<html lang="pt-PT">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title><?= htmlspecialchars($titulo_pagina ?? 'Orçamento') ?> - Gestão MAB</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #f39c12;
            --primary-hover: #e67e22;
            --dark: #1e293b;
            --gray: #64748b;
            --light-bg: #f8fafc;
            --border-color: #e2e8f0;
            --green: #10b981;
            --red: #ef4444;
        }

        * { font-family: system-ui, -apple-system, sans-serif; box-sizing: border-box; }
        body { background-color: #f1f5f9; margin: 0; color: var(--dark); font-size: 14px; padding-bottom: 90px; }
        input, select, textarea, button { font-family: inherit; font-size: 14px; outline: none; }

        /* TOPBAR (regras do site: logo → início, sem atalhos, voltar redondo) */
        .topbar { background-color: rgba(255,255,255,0.95); backdrop-filter: blur(8px); -webkit-backdrop-filter: blur(8px); padding: 10px 40px; box-shadow: 0 1px 0 #e8ecf1; display: flex; justify-content: space-between; align-items: center; position: sticky; top: 0; z-index: 1000; }
        .topbar-left a { display: block; }
        .topbar-left .logo { height: 35px; display: block; }
        .topbar-right { display: flex; align-items: center; gap: 12px; }
        .btn-voltar { width: 40px; height: 40px; border-radius: 50%; background: #fff; border: 1px solid #e8ecf1; color: #576574; display: inline-flex; align-items: center; justify-content: center; text-decoration: none; font-size: 16px; transition: all 0.2s ease; box-shadow: 0 2px 6px rgba(0,0,0,0.04); flex-shrink: 0; }
        .btn-voltar:hover { background: #eef2f6; color: #34495e; border-color: #d5dce4; transform: translateX(-2px); }
        .btn-voltar:active { transform: scale(0.94); }

        .user-menu { position: relative; }
        .user-btn { background: #fff; border: 1px solid var(--border-color); border-radius: 30px; font-size: 14px; font-weight: 600; color: var(--dark); cursor: pointer; display: flex; align-items: center; gap: 10px; padding: 5px 14px 5px 5px; transition: all 0.2s ease; }
        .user-btn:hover { box-shadow: 0 4px 12px rgba(0,0,0,0.05); border-color: #cbd5e1; }
        .user-avatar { background: var(--red); color: white; width: 28px; height: 28px; border-radius: 50%; display: flex; justify-content: center; align-items: center; font-size: 13px; }

        .dropdown-content { display: none; position: absolute; right: 0; top: 100%; background: #fff; min-width: 180px; box-shadow: 0 10px 25px rgba(0,0,0,0.1); border-radius: 12px; border: 1px solid var(--border-color); overflow: hidden; margin-top: 8px; z-index: 200; }
        .user-menu:hover .dropdown-content, .user-menu.aberto .dropdown-content { display: block; animation: fadeIn 0.2s ease; }
        .dropdown-content a { color: var(--gray); padding: 12px 20px; text-decoration: none; display: flex; align-items: center; gap: 10px; font-size: 14px; font-weight: 500; transition: background 0.2s;}
        .dropdown-content a:hover { background: var(--light-bg); color: var(--primary); }

        @keyframes fadeIn { from { opacity: 0; transform: translateY(-5px); } to { opacity: 1; transform: translateY(0); } }

        .container { max-width: 1400px; margin: 25px auto; padding: 0 20px; }

        .page-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px; border-bottom: 2px solid var(--border-color); padding-bottom: 15px; flex-wrap: wrap; gap: 15px; }
        .page-title { margin: 0; color: var(--dark); font-size: 24px; font-weight: 800; display: flex; align-items: center; gap: 12px; }
        .page-title i { color: var(--primary); }
        .header-actions { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; }

        .btn-acao-header { background: #fff; color: var(--gray); border: 1px solid var(--border-color); padding: 9px 18px; border-radius: 30px; text-decoration: none; font-weight: 600; font-size: 13px; display: inline-flex; align-items: center; gap: 8px; transition: all 0.2s; cursor: pointer; box-shadow: 0 1px 2px rgba(0,0,0,0.02);}
        .btn-acao-header:hover { background: var(--light-bg); color: var(--dark); border-color: #cbd5e1; box-shadow: 0 2px 6px rgba(0,0,0,0.04);}
        .btn-acao-header-import { color: #8b5cf6; border-color: #ddd6fe; background: #f5f3ff; }
        .btn-acao-header-import:hover { background: #ede9fe; color: #7c3aed; border-color: #c4b5fd;}
        .btn-acao-header-vistoria { color: #2471a3; border-color: #d4e9f7; background: #eaf4fc; }
        .btn-acao-header-vistoria:hover { background: #d6eaf8; color: #1b4f72; border-color: #aed6f1; }
        .selo-vistoria { display: inline-flex; align-items: center; gap: 6px; background: #eaf4fc; color: #2471a3; border: 1px solid #d4e9f7; border-radius: 8px; padding: 4px 10px; font-size: 12px; font-weight: 700; text-decoration: none; }
        .selo-vistoria:hover { background: #d6eaf8; }
        .selo-complementar { display: inline-flex; align-items: center; gap: 6px; background: #fef5e7; color: #b9770e; border: 1px solid #f9e0b5; border-radius: 8px; padding: 4px 10px; font-size: 12px; font-weight: 700; text-decoration: none; }
        .selo-complementar:hover { background: #fdebd0; }
        .btn-acao-header-comp { background: #fef5e7 !important; color: #b9770e !important; border-color: #f9e0b5 !important; }
        .comp-lista { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; background: #fffbf3; border: 1px dashed #f5cba7; border-radius: 10px; padding: 10px 14px; margin-bottom: 16px; font-size: 13px; }
        .comp-lista .comp-lbl { font-weight: 800; color: #b9770e; margin-right: 4px; }
        .comp-chip { display: inline-flex; align-items: center; gap: 6px; background: #fff; border: 1px solid #f5cba7; border-radius: 20px; padding: 4px 12px; color: #2c3e50; text-decoration: none; font-weight: 700; }
        .comp-chip small { font-weight: 600; color: #7f8c8d; }
        .comp-chip .est-adj { color: #27ae60; }

        /* MODAL IMPORTAR VISTORIA */
        .vi-sel { width: 100%; padding: 12px; border-radius: 8px; border: 1px solid #cbd5e1; background: #fff; }
        .vi-info { margin-top: 12px; padding: 10px 12px; background: #f8fafc; border: 1px solid var(--border-color); border-radius: 8px; font-size: 12px; color: var(--gray); text-align: left; line-height: 1.5; }
        .vi-lista { margin-top: 12px; max-height: 300px; overflow-y: auto; border: 1px solid var(--border-color); border-radius: 10px; text-align: left; }
        .vi-grupo { padding: 8px 12px; background: #eaf4fc; color: #2471a3; font-size: 11px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.4px; display: flex; align-items: center; gap: 8px; position: sticky; top: 0; }
        #modalVistoria label { text-transform: none; letter-spacing: 0; margin-bottom: 0; font-weight: 500; color: var(--dark); font-size: 13px; }
        .vi-linha { display: flex; gap: 10px; align-items: flex-start; padding: 9px 12px; border-top: 1px solid #f1f5f9; font-size: 13px; cursor: pointer; }
        .vi-linha:hover { background: #f8fafc; }
        .vi-linha input, .vi-grupo input { width: 16px; height: 16px; margin: 1px 0 0; accent-color: #2980b9; flex-shrink: 0; }
        .vi-opcoes { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 14px; text-align: left; }
        .vi-opcao { flex: 1 1 140px; display: flex; align-items: center; gap: 8px; padding: 10px 12px; border: 1px solid var(--border-color); border-radius: 8px; font-size: 13px; cursor: pointer; }
        .vi-opcao input { accent-color: #2980b9; margin: 0; }
        .vi-opcao:has(input:checked) { border-color: #2980b9; background: #eaf4fc; color: #1b4f72; font-weight: 600; }
        .vi-vazio { padding: 20px; text-align: center; color: var(--gray); font-size: 13px; }
        .btn-importar-vi { width: 100%; margin-top: 16px; background: #2980b9; color: #fff; border: none; padding: 14px; border-radius: 10px; font-weight: 700; font-size: 14px; cursor: pointer; display: inline-flex; align-items: center; justify-content: center; gap: 8px; }
        .btn-importar-vi:hover { background: #2471a3; }
        .btn-importar-vi:disabled { background: #cbd5e1; cursor: not-allowed; }

        /* SEPARADORES TABS */
        .tabs { display: flex; gap: 10px; margin-bottom: 15px; border-bottom: 2px solid var(--border-color); }
        .prev-print { display: inline-flex; gap: 8px; align-items: center; margin-left: 10px; flex-wrap: wrap; vertical-align: middle; }
        .prev-print select { padding: 10px; border: 1px solid var(--border-color); border-radius: 8px; font-family: inherit; font-size: 13px; background: #fff; }
        @media (max-width: 768px) { .prev-print { display: flex; margin: 10px 0 0; } .prev-print select, .prev-print .btn-add { flex: 1; } }
        .tab-btn { background: transparent; color: var(--gray); font-weight: 700; font-size: 14px; padding: 12px 20px; border: none; cursor: pointer; border-bottom: 3px solid transparent; margin-bottom: -2px; transition: 0.2s; display: flex; align-items: center; gap: 8px; }
        .tab-btn:hover { color: var(--dark); }
        .tab-btn.active { color: var(--primary); border-bottom-color: var(--primary); }
        .budget-tab-content { display: none; }
        .budget-tab-content.active { display: block; }

        .card { background: #fff; padding: 25px; border-radius: 16px; border: 1px solid #e2e8f0; box-shadow: 0 4px 15px rgba(0,0,0,0.03), 0 1px 3px rgba(0,0,0,0.02); margin-bottom: 25px; }
        h2 { font-size: 16px; color: var(--primary); margin: 0 0 18px 0; border-bottom: 2px solid var(--light-bg); padding-bottom: 10px; text-transform: uppercase; display: flex; justify-content: space-between; align-items: center; font-weight: 800;}

        .form-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 16px; }
        .form-group { display: flex; flex-direction: column; }
        label { font-weight: 700; margin-bottom: 6px; font-size: 11px; color: var(--gray); text-transform: uppercase; letter-spacing: 0.3px;}
        input, select, textarea { padding: 10px 14px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 14px; transition: all 0.2s ease; box-shadow: inset 0 1px 2px rgba(0,0,0,0.02); background: #fff;}
        input:focus, select:focus, textarea:focus { border-color: var(--primary); box-shadow: 0 0 0 3px rgba(243, 156, 18, 0.15); outline: none; }

        .table-responsive { overflow-x: auto; border: 1px solid var(--border-color); border-radius: 12px; }
        .items-table { width: 100%; border-collapse: separate; border-spacing: 0; table-layout: fixed; min-width: 1000px;}
        .items-table thead th { position: sticky; top: 0; z-index: 10; background: var(--light-bg); padding: 12px 8px; color: var(--gray); font-size: 11px; font-weight: 700; text-transform: uppercase; text-align: center; border-bottom: 2px solid var(--border-color); box-shadow: 0 2px 4px rgba(0,0,0,0.02);}

        .items-table td { padding: 2px 4px; border-bottom: 1px solid var(--border-color); position: relative; background: #fff; vertical-align: middle; }

        .col-chk { width: 50px; text-align: center; }
        .col-artigo { width: 11.6ch; }
        .col-lupa { width: 45px; text-align: center; }
        .col-designacao { width: 30%; }
        #tabelaItensInterno td.col-designacao { position: relative; }
        .ficha-ico { position: absolute; right: 6px; top: 8px; color: #c0392b; font-size: 14px; text-decoration: none; background: rgba(255,255,255,.9); border-radius: 4px; padding: 1px 3px; line-height: 1; z-index: 2; }
        .ficha-ico:hover { color: #922b21; }
        #tabelaItensInterno tr.com-ficha .desig-input { padding-right: 26px; }
        /* Composição da linha (vários artigos) */
        .btn-comp { position: absolute; right: 6px; bottom: 6px; border: 1px dashed #cbd5e1; background: #fff; color: #94a3b8; border-radius: 6px; font-size: 11px; padding: 2px 5px; cursor: pointer; line-height: 1.2; display: inline-flex; gap: 3px; align-items: center; opacity: .55; z-index: 2; }
        .btn-comp:hover { opacity: 1; color: #8b5cf6; border-color: #8b5cf6; }
        tr.com-comp .btn-comp { opacity: 1; background: #8b5cf6; color: #fff; border: 1px solid #8b5cf6; }
        #tabelaItensInterno tr.com-comp .desig-input { padding-bottom: 20px; }
        .comp-n { font-weight: 800; }
        .modal-comp { display: none; position: fixed; inset: 0; background: rgba(15,23,42,.6); z-index: 2500; align-items: flex-start; justify-content: center; padding: 20px 12px; overflow-y: auto; }
        .modal-comp.show { display: flex; }
        .comp-box { background: #fff; border-radius: 14px; width: 100%; max-width: 1050px; padding: 20px; box-shadow: 0 25px 50px -12px rgba(0,0,0,.25); }
        .comp-topo { display: flex; justify-content: space-between; align-items: flex-start; gap: 10px; border-bottom: 1px solid #e2e8f0; padding-bottom: 10px; margin-bottom: 12px; }
        .comp-topo h3 { margin: 0; font-size: 17px; color: #8b5cf6; }
        .comp-topo small { display: block; color: #64748b; font-size: 12px; margin-top: 4px; }
        .comp-pesq { position: relative; margin-bottom: 10px; }
        .comp-pesq input { width: 100%; padding: 12px; border: 1px solid #cbd5e1; border-radius: 10px; font-size: 14px; box-sizing: border-box; }
        .comp-sug { position: absolute; left: 0; right: 0; top: 100%; background: #fff; border: 1px solid #e2e8f0; border-radius: 10px; box-shadow: 0 10px 25px rgba(0,0,0,.1); max-height: 280px; overflow-y: auto; z-index: 5; display: none; }
        .comp-sug div { padding: 9px 12px; border-bottom: 1px solid #f1f5f9; cursor: pointer; font-size: 13px; }
        .comp-sug div:hover { background: #f5f3ff; }
        .comp-sug b { color: #8b5cf6; }
        .comp-tab { width: 100%; border-collapse: collapse; font-size: 13px; }
        .comp-tab th { background: #f8fafc; padding: 8px; font-size: 11px; text-transform: uppercase; color: #64748b; text-align: left; border-bottom: 1px solid #e2e8f0; }
        .comp-tab td { padding: 6px 8px; border-bottom: 1px solid #f1f5f9; vertical-align: middle; }
        .comp-tab input { width: 100%; padding: 7px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 13px; text-align: right; box-sizing: border-box; }
        .comp-tab .c-num { width: 90px; }
        .comp-tab .c-tot { text-align: right; font-weight: 700; white-space: nowrap; }
        .comp-tab tfoot td { font-weight: 800; background: #faf5ff; }
        .comp-vazio { text-align: center; color: #94a3b8; padding: 18px; }
        .comp-btns { display: flex; gap: 10px; justify-content: flex-end; margin-top: 14px; flex-wrap: wrap; }
        .comp-btns button { padding: 11px 18px; border-radius: 9px; border: none; font-weight: 700; cursor: pointer; font-size: 14px; }
        @media (max-width: 768px) {
            .comp-tab thead { display: none; }
            .comp-tab, .comp-tab tbody, .comp-tab tr, .comp-tab td { display: block; width: 100%; }
            .comp-tab tr { border: 1px solid #e2e8f0; border-radius: 10px; padding: 8px; margin-bottom: 8px; }
            .comp-tab td { border: none; padding: 3px 0; }
            .comp-tab td[data-l]::before { content: attr(data-l); display: block; font-size: 10px; color: #94a3b8; text-transform: uppercase; font-weight: 700; }
            .comp-tab .c-num { width: 100%; }
            .comp-tab tfoot tr { background: #faf5ff; }
        }
        .col-unidade { width: 7.5ch; }
        .col-qtd { width: 12ch; }
        .col-valor { width: 14ch; }
        .col-del { width: 75px; text-align: center; }

        .items-table input[type="text"], .items-table textarea, .list-row textarea { width: 100%; box-sizing: border-box; padding: 3px 8px; font-size: 14px; line-height: 1.3; border: 1px solid transparent; border-radius: 6px; background: transparent; transition: all 0.2s ease;}
        .items-table input[type="text"]:hover, .items-table textarea:hover { border-color: #e2e8f0; background: #f8fafc; }
        .items-table input[type="text"]:focus, .items-table textarea:focus { border-color: var(--primary); background: #fff; box-shadow: 0 0 0 3px rgba(243, 156, 18, 0.1); }

        .items-table textarea, .list-row textarea { resize: none; min-height: 24px; overflow-y: hidden; font-family: inherit; margin: 0; display: block; }

        .btn-lupa { background: var(--light-bg); border: 1px solid var(--border-color); border-radius: 6px; cursor: pointer; padding: 3px 8px; color: var(--gray); transition: all 0.2s; }
        .btn-lupa:hover { background: #3b82f6; color: #fff; border-color: #3b82f6; box-shadow: 0 2px 5px rgba(59, 130, 246, 0.3);}

        .preco-autocomplete-list { position: absolute; top: calc(100% - 4px); left: 50%; transform: translateX(-50%); width: 400px; background: #fff; border: 1px solid var(--border-color); border-radius: 12px; box-shadow: 0 10px 30px rgba(0,0,0,0.1); z-index: 1000; max-height: 300px; overflow-y: auto; display: none; text-align: left;}
        .preco-autocomplete-item { padding: 12px 15px; cursor: pointer; border-bottom: 1px solid var(--light-bg); font-size: 13px; color: var(--dark); line-height: 1.4; transition: background 0.2s;}
        .preco-autocomplete-item:hover { background-color: #fffbeb; }
        .preco-autocomplete-item strong { color: var(--primary); display: block; margin-top: 4px; font-size: 14px;}

        .chk-wrapper { display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 4px; height: 100%; padding: 2px 0; }
        .chk-select-row, .chk-select-list { width: 18px; height: 18px; accent-color: var(--primary); cursor: pointer; }
        .move-controls, .move-controls-list { display: none; flex-direction: column; cursor: pointer; color: #cbd5e1; font-size: 14px; gap: 4px; transition: color 0.2s; align-items: center; }
        .move-controls i:hover, .move-controls-list i:hover { color: var(--primary); }

        .align-left { text-align: left !important; } .align-center { text-align: center !important; } .align-right { text-align: right !important; }

        .btn-add { background: var(--primary); color: #fff; border: none; padding: 8px 16px; border-radius: 8px; cursor: pointer; font-size: 13px; font-weight: 600; margin-top: 10px; transition: all 0.2s; display:inline-flex; align-items:center; gap:6px; box-shadow: 0 2px 5px rgba(243, 156, 18, 0.3);}
        .btn-add:hover { background: var(--primary-hover); transform: translateY(-1px); box-shadow: 0 4px 8px rgba(243, 156, 18, 0.4);}
        .btn-clear { background: var(--light-bg); color: var(--gray); border: 1px solid var(--border-color); padding: 8px 16px; border-radius: 8px; cursor: pointer; font-size: 13px; font-weight: 600; margin-top: 10px; margin-left: 10px; transition: all 0.2s; display:inline-flex; align-items:center; gap:6px;}
        .btn-clear:hover { background: #f1f5f9; color: var(--red); border-color: #fecaca;}

        .btn-row-action { background: none; border: none; cursor: pointer; font-size: 15px; padding: 3px; border-radius: 6px; transition: all 0.2s; display: inline-flex; align-items: center; justify-content: center;}
        .btn-del-row { color: #f87171; }
        .btn-del-row:hover { background: #fef2f2; color: #dc2626; }
        .btn-add-row { color: #34d399; margin-right: 4px;}
        .btn-add-row:hover { background: #ecfdf5; color: #059669;}

        .factor-grid { display: flex; flex-wrap: wrap; gap: 20px; margin-bottom: 25px; justify-content: center; align-items: stretch; }
        .dash-card { background: #fff; border: 1px solid var(--border-color); border-radius: 12px; padding: 15px 20px; display: flex; flex-direction: column; justify-content: center; min-width: 160px; box-shadow: 0 2px 8px rgba(0,0,0,0.02); position: relative; overflow: hidden;}
        .dash-card::before { content: ''; position: absolute; left: 0; top: 0; bottom: 0; width: 4px; border-radius: 4px 0 0 4px;}
        .dash-card label { margin: 0 0 5px 0; font-size: 10px; color: var(--gray); display: flex; align-items: center; gap: 6px; }

        .dash-input-group { display: flex; gap: 8px; align-items: center; margin-top: 5px;}
        .dash-input-group input { width: 70px; text-align: center; padding: 8px; }
        .btn-calc { background: #3b82f6; color: #fff; border: none; padding: 8px 12px; border-radius: 6px; cursor: pointer; font-size: 12px; font-weight: 600; transition: 0.2s;}
        .btn-calc:hover { background: #2563eb; }
        .dash-value { font-size: 18px; font-weight: 800; color: var(--dark); margin-top: 2px;}

        .dc-arred::before { background: #0ea5e9; }
        .btn-calc:disabled { background: #e2e8f0 !important; color: #94a3b8; cursor: not-allowed; }
        .pv.pv-arredondado { box-shadow: inset 0 0 0 1px #7dd3fc !important; }
        .dc-mo::before { background: #3b82f6; } .dc-mo .dash-value { color: #3b82f6; }
        .dc-custo::before { background: #64748b; }
        .dc-sub::before { background: #10b981; } .dc-sub .dash-value { color: #10b981; }

        /* Botão temporário: aparece com 2 ou mais linhas escolhidas */
        .btn-renumerar { display: none; position: fixed; left: 25px; bottom: 25px; z-index: 1001; border-radius: 30px; overflow: hidden; box-shadow: 0 8px 24px rgba(139,92,246,0.35); }
        .btn-renumerar.show { display: inline-flex; }
        .btn-renumerar button { border: none; padding: 13px 18px; font-weight: 800; font-size: 14px; cursor: pointer; font-family: inherit; display: inline-flex; align-items: center; gap: 8px; white-space: nowrap; }
        .btn-renumerar .b-renum { background: #8b5cf6; color: #fff; }
        .btn-renumerar .b-renum:hover { background: #7c3aed; }
        .btn-renumerar .b-renum small { font-weight: 600; opacity: .85; }
        .btn-renumerar .b-desel .so-pc { margin-left: -4px; }
        .btn-renumerar .b-desel { background: #fff; color: #6d28d9; border-left: 1px solid #ddd6fe; }
        .btn-renumerar .b-desel:hover { background: #f5f3ff; }
        .floating-action-bar { position: fixed; bottom: 25px; right: 25px; background: rgba(255, 255, 255, 0.85); backdrop-filter: blur(12px); -webkit-backdrop-filter: blur(12px); padding: 15px 30px; border-radius: 16px; box-shadow: 0 10px 40px rgba(0,0,0,0.1); display: flex; align-items: center; gap: 25px; z-index: 1000; border: 1px solid rgba(255,255,255,0.5); }
        .floating-totals-wrapper { display: flex; align-items: center; gap: 20px; }
        .floating-total { display: flex; flex-direction: column; align-items: flex-end; }
        .floating-total-label { font-size: 12px; font-weight: 700; color: var(--gray); text-transform: uppercase; letter-spacing: 0.5px;}
        .floating-total-value { font-size: 22px; font-weight: 800; color: var(--primary); line-height: 1.2;}
        .btn-save { background: var(--primary); color: #fff; padding: 14px 28px; border-radius: 12px; border: none; font-weight: 700; font-size: 15px; cursor: pointer; transition: all 0.2s; box-shadow: 0 4px 15px rgba(243, 156, 18, 0.3); display: flex; align-items: center; gap: 8px;}
        .btn-save:hover { background: var(--primary-hover); transform: translateY(-2px); box-shadow: 0 6px 20px rgba(243, 156, 18, 0.4); }

        .alert { padding: 16px; border-radius: 12px; margin-bottom: 20px; text-align: center; font-weight: 600; font-size: 14px; box-shadow: 0 2px 10px rgba(0,0,0,0.02);}
        .alert-erro { background: #fef2f2; color: #dc2626; border: 1px solid #fecaca; }
        .alert-sucesso { background: #ecfdf5; color: #059669; border: 1px solid #a7f3d0; }
        .alert-info { background: #fef5e7; color: #9c640c; border: 1px solid #f9e0b5; }

        .bg-equal { background-color: #f8fafc !important; }
        .bg-profit { background-color: #f0fdf4 !important; }
        .bg-pc-filled { background-color: #fefce8 !important; }
        .bg-pc-empty { background-color: #f8fafc !important; }
        .bg-missing { background-color: #fef2f2 !important; box-shadow: inset 0 0 0 1px #fca5a5 !important;}

        .modal { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(15, 23, 42, 0.6); z-index: 2000; justify-content: center; align-items: center; padding: 20px; backdrop-filter: blur(3px);}
        .modal.show { display: flex; animation: modalFadeIn 0.2s ease; }
        @keyframes modalFadeIn { from { opacity: 0; } to { opacity: 1; } }
        .modal-content { background: #fff; padding: 35px; border-radius: 16px; width: 100%; max-width: 420px; text-align: center; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.25); border: 1px solid #e2e8f0; position: relative;}
        .modal-content input[type="password"], .modal-content input[type="text"] { width: 100%; padding: 14px; margin: 15px 0; border: 1px solid #cbd5e1; border-radius: 10px; box-sizing: border-box; font-size: 15px; }
        .close-btn { color: #94a3b8; font-size: 24px; cursor: pointer; font-weight: bold; background: none; border: none; padding: 0; line-height: 1; position: absolute; right: 20px; top: 20px;}

        .list-row { display: flex; gap: 10px; align-items: flex-start; background: #fff; padding: 3px 10px; border-radius: 8px; border: 1px solid var(--border-color); margin-bottom: 6px; box-shadow: 0 1px 2px rgba(0,0,0,0.01);}
        .list-row textarea { flex: 1; border: none; box-shadow: none; padding: 3px 0; background: transparent; resize: none; overflow: hidden; min-height: 24px; line-height: 1.3; outline: none; font-family: inherit; font-size: 14px; color: var(--dark); margin: 0;}
        .chk-wrapper-list { padding-top: 10px; }

        @media (max-width: 900px) {
            .form-grid { grid-template-columns: 1fr 1fr; }
        }
        @media (max-width: 1024px) {
            .topbar { padding: 10px 24px; }
        }
        @media (max-width: 768px) {
            .topbar { padding: 8px 15px; }
            .topbar-left .logo { height: 30px; }
            .btn-voltar { width: 38px; height: 38px; }
            .container { padding: 0 15px; }
            .page-title { font-size: 20px; flex-wrap: wrap; }
            .header-actions { width: 100%; }
            .header-actions > * { flex: 1 1 auto; justify-content: center; }
            .form-grid > * { grid-column: 1 / -1 !important; }
            .card h2 { flex-wrap: wrap; gap: 10px; }
            .tabs { overflow-x: auto; }
            .tab-btn { white-space: nowrap; padding: 12px 12px; }
            .modal-content { padding: 25px 18px; }
            .page-header { flex-direction: column; align-items: flex-start; gap: 15px; }
            .form-grid { grid-template-columns: 1fr; }
            .factor-grid { justify-content: flex-start; overflow-x: auto; flex-wrap: nowrap; padding-bottom: 10px;}
            .dash-card { min-width: 140px; }
            .btn-renumerar { left: 50%; transform: translateX(-50%); bottom: auto; top: 76px; max-width: calc(100% - 32px); }
            .btn-renumerar button { padding: 11px 14px; font-size: 13px; }
            .btn-renumerar .b-renum small, .btn-renumerar .so-pc { display: none; }
            .floating-action-bar { left: 20px; right: 20px; bottom: 20px; flex-direction: column; gap: 15px; align-items: stretch; text-align: center;}
            .floating-totals-wrapper { flex-direction: row; justify-content: center; width: 100%; border-bottom: 1px solid var(--border-color); padding-bottom: 10px; }
            .floating-total { align-items: center; border-right: none !important; padding-right: 0 !important; margin-right: 0 !important; }
            .btn-save { justify-content: center; }
        }
        @media (max-width: 560px) {
            .user-nome { display: none; }
            .user-btn { padding: 5px 10px 5px 5px; }
            .floating-total-value { font-size: 18px; }
        }
            .fator-destino { font-weight: 800; color: #8b5cf6; text-transform: none; }
        .lbl-com-btn { display: flex; align-items: center; justify-content: space-between; gap: 8px; }
        .btn-morada-cliente { background: #eaf4fc; color: #2471a3; border: 1px solid #d4e9f7; border-radius: 6px; padding: 2px 8px; font-size: 10px; font-weight: 700; cursor: pointer; text-transform: none; }
        .btn-morada-cliente:hover { background: #d6eaf8; }
        .km-info { display: block; margin-top: 4px; font-size: 11px; color: #7f8c8d; line-height: 1.35; }
        .km-info.erro { color: #c0392b; }
        .modal-km { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.55); z-index: 3000; align-items: center; justify-content: center; padding: 16px; }
        .modal-km.show { display: flex; }
        .modal-km-box { background: #fff; border-radius: 12px; width: 100%; max-width: 820px; max-height: 94vh; overflow: auto; padding: 18px; box-shadow: 0 10px 40px rgba(0,0,0,0.25); }
        .modal-km-topo { display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px; }
        .modal-km-topo h3 { margin: 0; font-size: 16px; color: #2c3e50; display: flex; gap: 8px; align-items: center; }
        .modal-km-x { background: none; border: none; font-size: 26px; color: #7f8c8d; cursor: pointer; line-height: 1; }
        .km-morada { font-size: 13px; color: #576574; margin-bottom: 4px; }
        .km-aviso { font-size: 12px; color: #b9770e; font-weight: 600; min-height: 4px; margin-bottom: 6px; }
        #kmMapa { height: 360px; border-radius: 10px; border: 1px solid #e1e5ea; }
        .km-estado { font-size: 12px; color: #7f8c8d; margin: 8px 0; }
        .km-estado.erro { color: #c0392b; font-weight: 600; }
        #kmOpcoes { display: flex; flex-direction: column; gap: 6px; }
        .km-opcao { display: flex; align-items: center; gap: 10px; border: 1px solid #e1e5ea; border-radius: 8px; padding: 9px 12px; cursor: pointer; font-size: 13px; text-transform: none; font-weight: 500; color: #2c3e50; margin: 0; }
        .km-opcao input { width: auto; margin: 0; }
        .km-opcao span { flex: 1; }
        .km-opcao strong { font-size: 15px; color: #2c3e50; white-space: nowrap; }
        .km-opcao.ativa { border-color: #b32a2a; background: #fdf2f2; }
        .modal-km-botoes { display: flex; justify-content: flex-end; flex-wrap: wrap; gap: 10px; margin-top: 12px; }
        .km-btn-sec { background: #f1f2f6; color: #576574; border: none; border-radius: 8px; padding: 10px 16px; font-weight: 700; cursor: pointer; text-decoration: none; font-size: 13px; display: inline-flex; align-items: center; gap: 6px; }
        .km-btn-ok { background: #b32a2a; color: #fff; border: none; border-radius: 8px; padding: 10px 18px; font-weight: 700; cursor: pointer; font-size: 13px; display: inline-flex; align-items: center; gap: 6px; }
        @media (max-width: 560px) { #kmMapa { height: 260px; } .km-opcao { flex-wrap: wrap; } }
</style>
</head>
<body>

    <header class="topbar">
        <div class="topbar-left"><a href="index.php" title="Página inicial"><img src="mab.png" alt="MAB" class="logo"></a></div>
        <div class="topbar-right">
            <a href="orcamentos.php" class="btn-voltar" title="Voltar" aria-label="Voltar"><i class="fa-solid fa-arrow-left"></i></a>
            <div class="user-menu">
                <button type="button" class="user-btn">
                    <div class="user-avatar"><i class="fa-solid fa-user"></i></div>
                    <span class="user-nome"><?= htmlspecialchars($primeiro_nome) ?></span>
                    <i class="fa-solid fa-caret-down" style="font-size: 12px; color: #94a3b8;"></i>
                </button>
                <div class="dropdown-content">
                    <a href="perfil.php"><i class="fa-solid fa-id-badge" style="width:20px; color:var(--primary)"></i> Perfil</a>
                    <a href="index.php?logout=1" style="color: var(--red); border-top: 1px solid var(--light-bg);"><i class="fa-solid fa-right-from-bracket" style="width:20px;"></i> Sair</a>
                </div>
            </div>
        </div>
    </header>

    <div class="container">
        <?php if ($mensagem): ?>
            <div class="alert <?= $tipo_mensagem === 'sucesso' ? 'alert-sucesso' : ($tipo_mensagem === 'info' ? 'alert-info' : 'alert-erro') ?>">
                <?= htmlspecialchars($mensagem, ENT_QUOTES, 'UTF-8') ?>
            </div>
        <?php endif; ?>

        <div class="page-header">
            <h1 class="page-title">
                <i class="fa-solid fa-file-invoice-dollar"></i> <?= htmlspecialchars($titulo_pagina ?? 'Orçamento') ?>
                <a href="<?= $id_vistoria_atual ? 'vistoria_instalacao_form.php?id=' . $id_vistoria_atual : '#' ?>" class="selo-vistoria" id="selo_vistoria" style="<?= $id_vistoria_atual ? '' : 'display:none;' ?>" title="Abrir a vistoria de origem">
                    <i class="fa-solid fa-plug-circle-check"></i> <span id="selo_vistoria_txt">Vistoria Nº <?= htmlspecialchars($ref_vistoria_atual) ?></span>
                </a>
                <?php if ($e_complementar_atual): ?>
                    <a href="orcamento_form.php?id=<?= (int)$orc_pai_info['id'] ?>" class="selo-complementar" title="Abrir o orçamento de origem">
                        <i class="fa-solid fa-code-branch"></i> Complementar ao Orçamento Nº <?= htmlspecialchars($orc_pai_info['numero_orcamento']) ?>
                    </a>
                <?php endif; ?>
            </h1>
            <div class="header-actions">
                <?php if (!$modo_editar && !$e_complementar_atual): ?>
                    <button type="button" class="btn-acao-header btn-acao-header-import" onclick="abrirModalImportar()"><i class="fa-solid fa-link"></i> Herdar Visita / Obra</button>
                <?php endif; ?>
                <?php if ($pode_criar_complementar): ?>
                    <a href="orcamento_form.php?complementar_de=<?= (int)$id ?>" class="btn-acao-header btn-acao-header-comp" title="Novo orçamento com trabalhos complementares a este (adjudicado), para enviar ao cliente"><i class="fa-solid fa-code-branch"></i> Criar Orçamento Complementar</a>
                <?php endif; ?>
                <button type="button" class="btn-acao-header btn-acao-header-vistoria" onclick="abrirModalVistoria()"><i class="fa-solid fa-plug-circle-check"></i> Importar Vistoria</button>
                <a href="tabela_precos.php" class="btn-acao-header" title="Aceder à Tabela de Preços de Artigos"><i class="fa-solid fa-list-ol"></i> Tabela de Preços</a>
            </div>
        </div>

        <?php if (!empty($complementares_lista)): ?>
            <div class="comp-lista">
                <span class="comp-lbl"><i class="fa-solid fa-code-branch"></i> Orçamentos complementares:</span>
                <?php foreach ($complementares_lista as $cp): ?>
                    <a class="comp-chip" href="orcamento_form.php?id=<?= (int)$cp['id'] ?>">
                        <?= htmlspecialchars($cp['numero_orcamento']) ?>
                        <small class="<?= $cp['estado'] === 'Adjudicado' ? 'est-adj' : '' ?>"><?= htmlspecialchars($cp['estado'] ?: 'Rascunho') ?></small>
                        <small><?= number_format((float)$cp['valor_total_liquido'], 2, ',', '.') ?> €</small>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <form method="POST" id="orcForm">
            <input type="hidden" name="id_orcamento_pai" value="<?= $e_complementar_atual && !$modo_editar ? (int)$orc_pai_info['id'] : '' ?>">
            <input type="hidden" name="estado_original" id="estado_original" value="<?= htmlspecialchars($_POST['estado'] ?? '') ?>">
            <input type="hidden" name="pass_seguranca" id="pass_seguranca_hidden" value="">
            <input type="hidden" name="itens_json" id="itens_json" value="">
            <input type="hidden" name="redirect_action" id="redirect_action" value="voltar">
            <input type="hidden" name="id_vistoria_origem" id="id_vistoria_origem" value="<?= $id_vistoria_atual ?: '' ?>">

            <div class="card">
                <h2><i class="fa-solid fa-circle-info"></i> Informação Geral</h2>
                <div class="form-grid">
                    <div class="form-group"><label>Nº Orçamento (Referência)</label><input type="text" name="numero_orcamento" value="<?= htmlspecialchars($_POST['numero_orcamento'] ?? '') ?>" readonly style="background:#fef2f2; color:#ef4444; border-color:#fecaca; font-weight:800;"></div>
                    <div class="form-group"><label>Data Emissão</label><input type="date" name="data" id="data_emissao" value="<?= htmlspecialchars($_POST['data'] ?? '') ?>" onchange="updateValidade()" required></div>
                    <div class="form-group"><label>Validade</label><select id="prazo_select" name="prazo_select" onchange="updateValidade()" required><option value="15" <?= (isset($_POST['prazo_select']) && $_POST['prazo_select'] == 15) ? 'selected' : '' ?>>15 Dias</option><option value="30" <?= (!isset($_POST['prazo_select']) || $_POST['prazo_select'] == 30) ? 'selected' : 'selected' ?>>30 Dias</option><option value="60" <?= (isset($_POST['prazo_select']) && $_POST['prazo_select'] == 60) ? 'selected' : '' ?>>60 Dias</option></select></div>
                    <div class="form-group"><label>Prazo de Validade</label><input type="date" name="data_validade_final" id="data_validade_final" value="<?= htmlspecialchars($_POST['data_validade_final'] ?? '') ?>" readonly style="background:var(--light-bg); color:var(--gray);" required></div>

                    <div class="form-group" style="grid-column: span 2;">
                        <label>Cliente</label>
                        <select name="id_cliente" required onchange="toggleNovoCliente(this.value)">
                            <option value="">-- Selecione --</option>
                            <option value="novo" style="font-weight:800; color:var(--primary);" <?= (isset($_POST['id_cliente']) && $_POST['id_cliente'] === 'novo') ? 'selected' : '' ?>>+ Criar Novo Cliente</option>
                            <?php foreach($clientes as $c): ?>
                                <option value="<?= $c['id'] ?>" <?= ((isset($_POST['id_cliente']) && $_POST['id_cliente'] == $c['id']) || (isset($id_cliente_final) && $id_cliente_final == $c['id'])) ? 'selected' : '' ?>><?= htmlspecialchars($c['nome'] ?? '') ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Taxa IVA (%)</label>
                        <select name="taxa_iva" id="taxa_iva" onchange="calcularTudo()">
                            <option value="0" <?= ($iva_atual == 0) ? 'selected' : '' ?>>0% (Autoliq.)</option>
                            <option value="6" <?= ($iva_atual == 6) ? 'selected' : '' ?>>6%</option>
                            <option value="13" <?= ($iva_atual == 13) ? 'selected' : '' ?>>13%</option>
                            <option value="23" <?= ($iva_atual == 23) ? 'selected' : '' ?>>23%</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Data Limite Entrega <small style="color:#94a3b8; font-weight:normal; text-transform:none;">(Opcional)</small></label>
                        <input type="date" name="data_limite_entrega" value="<?= htmlspecialchars($_POST['data_limite_entrega'] ?? '') ?>">
                    </div>

                    <div id="form_novo_cliente" style="display:<?= ((isset($_POST['id_cliente']) && $_POST['id_cliente'] === 'novo') || isset($manter_novo_cliente_aberto)) ? 'block' : 'none' ?>; grid-column: 1 / -1; background: #fffbeb; border: 1px dashed #f59e0b; padding: 20px; border-radius: 12px; margin-top: 5px; margin-bottom: 5px;">
                        <h3 style="color:#d97706; margin:0 0 15px 0; font-size:14px; display:flex; align-items:center; gap:8px;"><i class="fa-solid fa-user-plus"></i> Ficha do Novo Cliente</h3>
                        <div class="form-grid" style="grid-template-columns: 2fr 1fr 1fr 1.5fr;">
                            <div class="form-group"><label>NOME *</label><input type="text" name="novo_cliente_nome" id="novo_cliente_nome" value="<?= htmlspecialchars($_POST['novo_cliente_nome'] ?? '') ?>"></div>
                            <div class="form-group"><label>NIF</label><input type="text" name="novo_cliente_nif" value="<?= htmlspecialchars($_POST['novo_cliente_nif'] ?? '') ?>"></div>
                            <div class="form-group"><label>TELEFONE</label><input type="text" name="novo_cliente_telefone" value="<?= htmlspecialchars($_POST['novo_cliente_telefone'] ?? '') ?>"></div>
                            <div class="form-group"><label>EMAIL</label><input type="email" name="novo_cliente_email" value="<?= htmlspecialchars($_POST['novo_cliente_email'] ?? '') ?>"></div>
                        </div>
                        <div class="form-grid" style="grid-template-columns: 2fr 1fr 2.5fr; margin-top:16px;">
                            <div class="form-group"><label>MORADA</label><input type="text" name="novo_cliente_morada" value="<?= htmlspecialchars($_POST['novo_cliente_morada'] ?? '') ?>"></div>
                            <div class="form-group"><label>CÓDIGO POSTAL</label><input type="text" name="novo_cliente_cp" placeholder="0000-000" value="<?= htmlspecialchars($_POST['novo_cliente_cp'] ?? '') ?>"></div>
                            <div class="form-group"><label>LOCALIDADE</label><input type="text" name="novo_cliente_cidade" value="<?= htmlspecialchars($_POST['novo_cliente_cidade'] ?? '') ?>"></div>
                        </div>
                    </div>

                    <div class="form-group" style="grid-column: span 2;"><label>Obra / Assunto</label><input type="text" name="nome_obra" value="<?= htmlspecialchars($_POST['nome_obra'] ?? '') ?>" required></div>
                    <div class="form-group"><label>Contacto</label><input type="text" name="contacto_obra" value="<?= htmlspecialchars($_POST['contacto_obra'] ?? '') ?>"></div>
                    <div class="form-group"><label>Referência Orçamento</label><input type="text" name="referencia_cliente" value="<?= htmlspecialchars($_POST['referencia_cliente'] ?? '') ?>"></div>

                    <div class="form-group" style="grid-column: span 2;"><label class="lbl-com-btn">Morada da Obra <button type="button" class="btn-morada-cliente" onclick="usarMoradaCliente()" title="Copiar para aqui a morada do cliente escolhido"><i class="fa-solid fa-location-dot"></i> Usar morada do cliente</button></label><textarea name="morada_obra" rows="1" required style="resize:none; height:42px;" oninput="autoAjustarTextarea(this)"><?= htmlspecialchars($_POST['morada_obra'] ?? '') ?></textarea></div>
                    <div class="form-group"><label class="lbl-com-btn"><span>Distância (KM) <small style="color:#94a3b8; font-weight:normal; text-transform:none;">Ida e volta</small></span> <button type="button" class="btn-morada-cliente" id="btn_calc_km" onclick="calcularDistanciaObra()" title="Calcular por estrada: sede → morada da obra → sede"><i class="fa-solid fa-route"></i> Calcular</button></label><input type="text" name="km" id="campo_km" value="<?= htmlspecialchars($_POST['km'] ?? '') ?>" placeholder="Ex: 50" oninput="forceComma(this)" onblur="formatField(this)"><small id="km_info" class="km-info"></small></div>
                    <div class="form-group">
                        <label>Estado</label>
                        <select name="estado" id="estado_select" required style="font-weight:600; color:var(--dark);">
                            <option value="Rascunho" <?= (!isset($_POST['estado']) || $_POST['estado'] == 'Rascunho') ? 'selected' : '' ?>>Rascunho</option>
                            <option value="Enviado" <?= (isset($_POST['estado']) && $_POST['estado'] == 'Enviado') ? 'selected' : '' ?>>Enviado</option>
                            <option value="Adjudicado" <?= (isset($_POST['estado']) && $_POST['estado'] == 'Adjudicado') ? 'selected' : '' ?>>Adjudicado</option>
                            <option value="Recusado" <?= (isset($_POST['estado']) && $_POST['estado'] == 'Recusado') ? 'selected' : '' ?>>Recusado</option>
                            <option value="Congelado" <?= (isset($_POST['estado']) && $_POST['estado'] == 'Congelado') ? 'selected' : '' ?>>Congelado</option>
                        </select>
                    </div>
                </div>
            </div>

            <div class="card" style="padding-bottom:10px;">
                <h2>
                    <span><i class="fa-solid fa-layer-group"></i> Estrutura do Orçamento</span>
                    <div style="font-weight: normal; font-size:12px; display:inline-flex; align-items:center; gap:10px;">
                        <input type="file" id="csv_file" accept=".csv" style="display:none" onchange="importarCSV(this)">
                        <button type="button" class="btn-clear" style="margin:0; box-shadow:0 1px 2px rgba(0,0,0,0.02);" onclick="document.getElementById('csv_file').click()" title="Importa as linhas para o separador aberto (Detalhe Interno ou Resumo Comercial)">
                            <i class="fa-solid fa-file-csv" style="color:#10b981;"></i> Importar CSV
                        </button>
                        <button type="button" class="btn-clear" style="margin:0; box-shadow:0 1px 2px rgba(0,0,0,0.02);" onclick="exportarCSV()" title="Exporta as linhas do separador aberto (Detalhe Interno ou Resumo Comercial)">
                            <i class="fa-solid fa-download" style="color:#3b82f6;"></i> Exportar CSV
                        </button>
                        <?php if (!$modo_editar): ?>
                        <button type="button" class="btn-clear" style="margin:0;" onclick="limparTudoCompleto()">
                            <i class="fa-solid fa-trash-can" style="color:var(--red);"></i> Limpar Tabelas
                        </button>
                        <?php endif; ?>
                    </div>
                </h2>

                <div class="tabs">
                    <button type="button" class="tab-btn active" onclick="switchBudgetTab('interno', this)">
                        <i class="fa-solid fa-calculator"></i> Detalhe Interno (Estudo de Custos)
                    </button>
                    <button type="button" class="tab-btn" onclick="switchBudgetTab('comercial', this)">
                        <i class="fa-solid fa-eye"></i> Resumo Comercial (Para o Cliente)
                    </button>
                </div>

                <div class="factor-grid">
                    <div class="dash-card">
                        <label><i class="fa-solid fa-calculator" style="color:var(--gray)"></i> Aplicar Margem Venda (x) <span id="fator_destino" class="fator-destino">· Detalhe Interno</span></label>
                        <div class="dash-input-group">
                            <input type="text" id="fator_custo" placeholder="1,30" oninput="forceComma(this)">
                            <button type="button" class="btn-calc" id="btn_fator_custo" onclick="aplicarFator('custo')" title="Aplicar fator sobre Preço Custo">Custo</button>
                            <button type="button" class="btn-calc" style="background:#8b5cf6;" onclick="aplicarFator('venda')" title="Aplicar fator sobre PVP">PVP</button>
                        </div>
                    </div>
                    <div class="dash-card dc-arred">
                        <label><i class="fa-solid fa-wand-magic-sparkles" style="color:#0ea5e9"></i> Arredondar Preço Venda (0 / 5)</label>
                        <div class="dash-input-group">
                            <button type="button" class="btn-calc" style="background:#0ea5e9;" onclick="arredondarPrecosVenda()" title="Arredonda a 2ª casa decimal para cima: 1-4 → 5 · 6-9 → 0 (sobe a 1ª casa)"><i class="fa-solid fa-arrow-up-wide-short"></i> Arredondar</button>
                            <button type="button" class="btn-calc" id="btn_repor_pv" style="background:#94a3b8;" onclick="reporPrecosVenda()" disabled title="Repor os preços de venda anteriores ao arredondamento"><i class="fa-solid fa-rotate-left"></i> Repor</button>
                        </div>
                    </div>
                    <div class="dash-card dc-mo">
                        <label><i class="fa-solid fa-stopwatch"></i> Tempo Total Mão de Obra</label>
                        <input type="hidden" name="valor_mao_obra" id="valor_mao_obra" value="<?= htmlspecialchars($_POST['valor_mao_obra'] ?? '0,00') ?>">
                        <span class="dash-value" id="display_mao_obra_tempo">0h 00m</span>
                    </div>
                    <div class="dash-card dc-custo">
                        <label><i class="fa-solid fa-box"></i> Custo Material</label>
                        <span class="dash-value" id="res_total_custo_final">0,00 €</span>
                    </div>
                    <div class="dash-card dc-sub">
                        <label><i class="fa-solid fa-sack-dollar"></i> Sub-Total Venda Final</label>
                        <span class="dash-value" id="display_subtotal_venda_topo">0,00 €</span>
                    </div>
                </div>

                <div id="tab_interno" class="budget-tab-content active">
                    <div class="table-responsive">
                        <table class="items-table" id="tabelaItensInterno">
                            <thead>
                                <tr>
                                    <th class="col-chk align-center"><input type="checkbox" onclick="toggleAllRows('interno', this)" style="width: 14px; height: 14px; cursor: pointer;"></th>
                                    <th class="col-artigo align-left">Ref / Artigo</th>
                                    <th class="col-lupa"></th>
                                    <th class="col-designacao align-left">Designação do Artigo/Serviço</th>
                                    <th class="col-unidade align-center">Un.</th>
                                    <th class="col-qtd align-center">Qtd.</th>
                                    <th class="col-valor align-center">Pr. Custo</th>
                                    <th class="col-valor align-center"><i class="fa-regular fa-clock"></i> Tempo MO</th>
                                    <th class="col-valor align-center">Total Custo</th>
                                    <th class="col-valor align-center">Pr. Venda</th>
                                    <th class="col-valor align-right">Total Venda</th>
                                    <th class="col-del">Ações</th>
                                </tr>
                            </thead>
                            <tbody></tbody>
                        </table>
                    </div>
                    <button type="button" class="btn-add" onclick="addRowNumerada('interno')"><i class="fa-solid fa-plus"></i> Adicionar Linha Interna</button>
                </div>

                <div id="tab_comercial" class="budget-tab-content">
                    <div class="table-responsive">
                        <table class="items-table" id="tabelaItensComercial">
                            <thead>
                                <tr>
                                    <th class="col-chk align-center"><input type="checkbox" onclick="toggleAllRows('comercial', this)" style="width: 14px; height: 14px; cursor: pointer;"></th>
                                    <th class="col-artigo align-left">Ref / Artigo</th>
                                    <th class="col-designacao align-left">Descrição Comercial para o Cliente</th>
                                    <th class="col-unidade align-center">Un.</th>
                                    <th class="col-qtd align-center">Qtd.</th>
                                    <th class="col-valor align-center">Pr. Venda</th>
                                    <th class="col-valor align-right">Total Venda</th>
                                    <th class="col-del">Ações</th>
                                </tr>
                            </thead>
                            <tbody></tbody>
                        </table>
                    </div>
                    <button type="button" class="btn-add" style="background:#34495e;" onclick="addRowNumerada('comercial')"><i class="fa-solid fa-plus"></i> Adicionar Linha Comercial</button>
                    <button type="button" class="btn-add" style="background:#8b5cf6; margin-left:10px;" onclick="importarLinhasInternas()"><i class="fa-solid fa-file-import"></i> Importar Linhas do Detalhe Interno</button>
                    <div class="prev-print">
                        <select id="prev_tipo" title="Formato do documento">
                            <option value="detalhe">Valores por Linha</option>
                            <option value="detalhe_vg">Valores por Linha VG</option>
                            <option value="capitulo">Total por Capítulo</option>
                            <option value="global">Só Valor Global</option>
                            <option value="limpo">Sem Preços</option>
                        </select>
                        <button type="button" class="btn-add" style="background:#16a085; margin:0;" onclick="imprimirDoEcra()" title="Gera o PDF com os valores que estão agora no ecrã, sem gravar"><i class="fa-solid fa-print"></i> Imprimir (valores do ecrã)</button>
                    </div>
                </div>

                <div style="margin-top: 20px; border-top: 1px solid var(--border-color); padding-top: 15px;">
                    <table style="float: right; min-width: 350px;">
                        <tr><td class="align-right" style="font-weight: 700; color: var(--gray);">SUBTOTAL</td><td class="align-right" style="font-weight: 800; font-size: 15px;" id="res_total_venda">0,00 €</td></tr>
                        <tr><td class="align-right" style="font-weight: 700; color: var(--gray);" id="label_iva">IVA (23%)</td><td class="align-right" style="font-weight: 800; font-size: 15px;" id="res_valor_iva">0,00 €</td></tr>
                        <tr><td class="align-right" style="font-weight: 800; color: var(--primary);">TOTAL ORÇAMENTO</td><td class="align-right" style="font-weight: 800; font-size: 20px; color: var(--primary);" id="res_total_com_iva">0,00 €</td></tr>
                    </table>
                    <div style="clear: both;"></div>
                </div>
            </div>

            <div class="form-grid" style="grid-template-columns: 1fr 1fr; gap: 25px; margin-bottom: 25px;">
                <div class="card" style="margin-bottom:0;">
                    <h2><span><i class="fa-solid fa-comment-dots"></i> Observações</span></h2>
                    <div id="obsContainer" class="list-container" style="display: flex; flex-direction: column;"></div>
                    <button type="button" class="btn-add" style="background:var(--gray); font-size:11px; padding:6px 12px;" onclick="addListRow('obsContainer', 'obs_linha')"><i class="fa-solid fa-plus"></i></button>
                </div>

                <div style="display:flex; flex-direction:column; gap:25px;">
                    <div class="card" style="margin-bottom:0;">
                        <h2><span style="color:#10b981;"><i class="fa-solid fa-check-double"></i> A Proposta Inclui</span></h2>
                        <div id="incluiContainer" class="list-container" style="display: flex; flex-direction: column;"></div>
                        <button type="button" class="btn-add" style="background:#10b981; font-size:11px; padding:6px 12px;" onclick="addListRow('incluiContainer', 'inclui_linha')"><i class="fa-solid fa-plus"></i></button>
                    </div>

                    <div class="card" style="margin-bottom:0;">
                        <h2><span style="color:#ef4444;"><i class="fa-solid fa-ban"></i> A Proposta Não Inclui</span></h2>
                        <div id="naoIncluiContainer" class="list-container" style="display: flex; flex-direction: column;"></div>
                        <button type="button" class="btn-add" style="background:#ef4444; font-size:11px; padding:6px 12px;" onclick="addListRow('naoIncluiContainer', 'nao_inclui_linha')"><i class="fa-solid fa-plus"></i></button>
                    </div>
                </div>
            </div>

            <div class="btn-renumerar" id="btnRenumerar">
                <button type="button" class="b-renum" onclick="renumerarSelecionadas()" title="Numera as linhas escolhidas por ordem, a partir do número da 1ª"><i class="fa-solid fa-arrow-down-1-9"></i> Renumerar <small id="btnRenumerarInfo"></small></button>
                <button type="button" class="b-desel" onclick="desselecionarTodas()" title="Tira o visto a todas as linhas"><i class="fa-solid fa-xmark"></i> Desselecionar <span class="so-pc">todas</span></button>
            </div>
            <div class="floating-action-bar">
                <div class="floating-totals-wrapper">
                    <div class="floating-total" style="padding-right: 20px; border-right: 2px solid var(--border-color);">
                        <span class="floating-total-label" style="color:var(--gray);">ST Interno (Venda)</span>
                        <span class="floating-total-value" id="floating_subtotal_interno" style="font-size: 18px; color: var(--gray);">0,00 €</span>
                    </div>
                    <div class="floating-total">
                        <span class="floating-total-label">ST Comercial</span>
                        <span class="floating-total-value" id="floating_subtotal_comercial">0,00 €</span>
                    </div>
                </div>
                <button type="submit" class="btn-save"><i class="fa-solid fa-floppy-disk"></i> GRAVAR ORÇAMENTO</button>
            </div>
        </form>
    </div>

    <?php if (!$modo_editar): ?>
    <div class="modal" id="modalImportar">
        <div class="modal-content" style="max-width: 550px;">
            <button type="button" class="close-btn" onclick="fecharModalImportar()">&times;</button>
            <h3 style="margin: 0 0 15px 0; color: #8b5cf6; font-size: 20px; text-align:left;"><i class="fa-solid fa-link"></i> Herdar Referência</h3>
            <div id="aviso_filtro_import" style="margin-bottom: 20px; font-size: 13px; padding: 10px; background: #f8fafc; border: 1px solid #cbd5e1; border-radius: 8px; text-align: left;"></div>
            <div style="text-align: left; margin-bottom: 25px;">
                <label style="font-weight: 700; color: var(--dark); display: block; margin-bottom: 8px;">A partir de Visita Técnica</label>
                <div style="display: flex; gap: 10px;">
                    <select id="select_import_visita" style="flex: 1; padding: 12px; border-radius: 8px; border: 1px solid #cbd5e1; background:#fff;">
                        <option value="">-- Selecione uma Visita --</option>
                        <?php foreach($visitas_import as $v): ?>
                            <option value="<?= $v['id'] ?>" data-cliente="<?= $v['id_cliente'] ?>">REF: <?= htmlspecialchars($v['referencia_visita'] ?? '') ?> - <?= htmlspecialchars(($v['cliente_nome'] ?? '') . ' (' . ($v['assunto'] ?? '') . ')') ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button type="button" class="btn-primary" style="width: auto; background: #8b5cf6;" onclick="executarImportacao('visita')">Importar</button>
                </div>
            </div>
            <div style="text-align: left; border-top: 1px dashed #cbd5e1; padding-top: 20px;">
                <label style="font-weight: 700; color: var(--dark); display: block; margin-bottom: 8px;">A partir de Obra Existente</label>
                <div style="display: flex; gap: 10px;">
                    <select id="select_import_obra" style="flex: 1; padding: 12px; border-radius: 8px; border: 1px solid #cbd5e1; background:#fff;">
                        <option value="">-- Selecione uma Obra --</option>
                        <?php foreach($obras_import as $o): ?>
                            <option value="<?= $o['id'] ?>" data-cliente="<?= $o['id_cliente'] ?>">REF: <?= htmlspecialchars($o['referencia_obra'] ?? '') ?> - <?= htmlspecialchars(($o['cliente_nome'] ?? '') . ' (' . ($o['nome'] ?? '') . ')') ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button type="button" class="btn-primary" style="width: auto; background: #8b5cf6;" onclick="executarImportacao('obra')">Importar</button>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <div class="modal" id="modalVistoria">
        <div class="modal-content" style="max-width: 620px; text-align:left;">
            <button type="button" class="close-btn" onclick="fecharModalVistoria()">&times;</button>
            <h3 style="margin: 0 0 6px 0; color: #2980b9; font-size: 20px;"><i class="fa-solid fa-plug-circle-check"></i> Importar Vistoria de Instalação</h3>
            <p style="margin:0 0 15px; font-size:13px; color:var(--gray);">As <strong>Alterações a Efetuar</strong> passam a linhas do orçamento, agrupadas por local. Depois só falta pôr artigos e preços.</p>
            <div id="aviso_filtro_vistoria" style="margin-bottom: 12px; font-size: 13px; padding: 10px; background: #f8fafc; border: 1px solid #cbd5e1; border-radius: 8px;"></div>

            <select id="select_vistoria" class="vi-sel" onchange="renderVistoriaSelecionada()">
                <option value="">-- Selecione uma Vistoria --</option>
            </select>
            <div id="vi_detalhe" style="display:none;">
                <div class="vi-info" id="vi_info"></div>
                <div class="vi-lista" id="vi_lista"></div>
                <div class="vi-opcoes">
                    <label class="vi-opcao"><input type="radio" name="vi_destino" value="interno" checked> Detalhe Interno</label>
                    <label class="vi-opcao"><input type="radio" name="vi_destino" value="comercial"> Resumo Comercial</label>
                    <label class="vi-opcao"><input type="radio" name="vi_destino" value="ambos"> Ambos</label>
                </div>
                <div class="vi-opcoes">
                    <label class="vi-opcao" id="vi_opcao_obs"><input type="checkbox" id="vi_importar_obs" checked> Copiar as observações da vistoria</label>
                </div>
                <?php if (!$modo_editar): ?>
                <p style="margin:12px 0 0; font-size:12px; color:var(--gray);"><i class="fa-solid fa-link"></i> O cliente, a obra, a morada e o <strong>Nº do orçamento</strong> passam a ser os da vistoria (número único partilhado).</p>
                <?php endif; ?>
            </div>
            <button type="button" class="btn-importar-vi" id="btn_importar_vi" onclick="executarImportacaoVistoria()" disabled><i class="fa-solid fa-file-import"></i> Importar Linhas</button>
        </div>
    </div>

    <!-- COMPOSIÇÃO DA LINHA: vários artigos da tabela de preços (mini orçamento) -->
    <div class="modal-comp" id="modalComp" onclick="if (event.target === this) fecharComposicao()">
        <div class="comp-box">
            <div class="comp-topo">
                <div><h3><i class="fa-solid fa-layer-group"></i> Artigos desta linha</h3><small id="compLinha"></small></div>
                <button type="button" class="close-btn" style="position:static;" onclick="fecharComposicao()">&times;</button>
            </div>
            <div id="compAviso" style="display:none; background:#fef3c7; color:#92400e; border:1px solid #fde68a; border-radius:8px; padding:9px 12px; font-size:13px; margin-bottom:10px;"><i class="fa-solid fa-triangle-exclamation"></i> Esta composição foi gravada com o erro das casas decimais: os preços / tempos voltaram aos da tabela de preços. Confirme também as <strong>quantidades com decimais</strong> e clique em <strong>Aplicar à linha</strong>.</div>
            <div class="comp-pesq">
                <input type="text" id="compPesquisa" placeholder="Acrescentar artigo da tabela de preços (ex.: interruptor, cabo xv 3g2,5)…" oninput="compSugestoes()" onfocus="compSugestoes()" onblur="setTimeout(() => document.getElementById('compSug').style.display = 'none', 200)" autocomplete="off">
                <div class="comp-sug" id="compSug"></div>
            </div>
            <div style="overflow-x:auto;">
                <table class="comp-tab">
                    <thead><tr><th>Artigo</th><th>Un.</th><th class="c-num">Qtd. p/ 1 <span class="comp-un-linha">un.</span></th><th class="c-num">P. Custo</th><th class="c-num">Tempo (min)</th><th class="c-num">P. Venda</th><th style="text-align:right;">Custo</th><th style="text-align:right;">Venda</th><th></th></tr></thead>
                    <tbody id="compCorpo"></tbody>
                    <tfoot><tr><td colspan="6" style="text-align:right;">Total por 1 <span class="comp-un-linha">un.</span> da linha (tempo: <span id="compTotT">0</span> min)</td><td class="c-tot" id="compTotC">0,00 €</td><td class="c-tot" id="compTotV">0,00 €</td><td></td></tr></tfoot>
                </table>
            </div>
            <p style="font-size:12px; color:#64748b; margin:10px 0 0;">As quantidades são para <strong>1 unidade da linha</strong> do orçamento. Ao aplicar, o preço de custo, o tempo e o preço de venda da linha passam a ser estes totais (podes acertá-los depois à mão). Na FAME, cada artigo entra como uma linha própria, com a sua ficha técnica.</p>
            <div class="comp-btns">
                <button type="button" style="background:#fee2e2; color:#b91c1c; margin-right:auto;" onclick="limparComposicao()"><i class="fa-solid fa-eraser"></i> Retirar composição</button>
                <button type="button" style="background:#f1f5f9; color:#475569;" onclick="fecharComposicao()">Cancelar</button>
                <button type="button" style="background:#8b5cf6; color:#fff;" onclick="aplicarComposicao()"><i class="fa-solid fa-check"></i> Aplicar à linha</button>
            </div>
        </div>
    </div>

    <div class="modal" id="modalPesquisaPrecos">
        <div class="modal-content" style="max-width: 600px; padding: 25px;">
            <button type="button" class="close-btn" onclick="fecharModalPesquisaPrecos()">&times;</button>
            <h3 style="margin: 0 0 15px 0; color: #3b82f6; font-size: 18px; text-align:left;"><i class="fa-solid fa-search"></i> Pesquisa de Artigo</h3>
            <input type="text" id="inputFiltroPrecos" placeholder="Pesquisar na Tabela de Preços (ex: tubo vd 16)..." onkeyup="filtrarListaPrecos()" style="background:#f8fafc; padding:15px; border-color:#94a3b8; font-size:16px;">
            <div id="listaPrecosContainer" style="max-height: 300px; overflow-y: auto; text-align: left; margin-top: 10px; border: 1px solid var(--border-color); border-radius: 8px; display:none;"></div>
        </div>
    </div>

    <div class="modal" id="modalDesadjudicar">
        <div class="modal-content">
            <h3 style="color:var(--red); margin-top:0;"><i class="fa-solid fa-triangle-exclamation"></i> Alteração de Estado</h3>
            <p style="color:var(--gray); margin-bottom:20px;"><?php if ($e_complementar_atual): ?>Ao remover o estado "Adjudicado" deste orçamento complementar, as linhas deixam de entrar nos próximos autos (os autos já emitidos não são alterados). A obra não é apagada.<?php else: ?>Ao remover o estado "Adjudicado", a Obra associada a este orçamento será <strong>apagada</strong> permanentemente do sistema.<?php endif; ?><br><br>Insira a password de segurança para confirmar esta ação:</p>
            <input type="password" id="modal_pass" placeholder="Password de Segurança">
            <div style="display:flex; gap:10px; margin-top:10px;">
                <button type="button" onclick="fecharModalDes()" class="btn-clear" style="flex:1; justify-content:center; margin:0;">Cancelar</button>
                <button type="button" onclick="confirmarDesadjudicar()" class="btn-primary" style="flex:1; background:var(--red);">Confirmar</button>
            </div>
        </div>
    </div>

    <div class="modal" id="modalPrecoInferior">
        <div class="modal-content">
            <h3 style="color:var(--red); margin-top:0;"><i class="fa-solid fa-shield-halved"></i> Aviso de Margem Negativa</h3>
            <p style="color:var(--gray); margin-bottom:20px;">Existem artigos na tabela onde o <strong>Preço de Venda é inferior ao Preço de Custo</strong>.<br><br>Para forçar a gravação e assumir este prejuízo, insira a password de segurança:</p>
            <input type="password" id="modal_pass_preco" placeholder="Password de Segurança">
            <div style="display:flex; gap:10px; margin-top:10px;">
                <button type="button" onclick="fecharModalPreco()" class="btn-clear" style="flex:1; justify-content:center; margin:0;">Rever Preços</button>
                <button type="button" onclick="confirmarPrecoInferior()" class="btn-primary" style="flex:1; background:var(--red);">Forçar Gravação</button>
            </div>
        </div>
    </div>

    <div class="modal" id="modalRedirect">
        <div class="modal-content">
            <h3 style="color:#10b981; margin-top:0;"><i class="fa-solid fa-circle-check"></i> Orçamento Guardado!</h3>
            <p style="color:var(--gray); margin-bottom:25px;">A operação foi concluída com sucesso. O que deseja fazer a seguir?</p>
            <div style="display:flex; flex-direction:column; gap:10px;">
                <button type="button" onclick="confirmarRedirect('continuar')" class="btn-clear" style="width:100%; justify-content:center; margin:0; padding:14px; color:var(--dark);">Continuar a editar este orçamento</button>
                <button type="button" onclick="confirmarRedirect('voltar')" class="btn-primary" style="width:100%; padding:14px;">Voltar à listagem principal</button>
            </div>
        </div>
    </div>

    <script>
        // --- DISTÂNCIA SEDE → OBRA (ida e volta), por estrada ---
        // Localiza as moradas no OpenStreetMap (Nominatim) e calcula o percurso de carro (OSRM). Resultado × 2.
        const MORADA_SEDE = <?= safe_json_encode($morada_sede) ?>;
        const sleep = ms => new Promise(r => setTimeout(r, ms));
        async function geolocalizar(morada) {
            const limpa = morada.replace(/\s+/g, ' ').trim();
            const cp = (limpa.match(/\b\d{4}-\d{3}\b/) || [])[0];
            // tentativas: morada completa → rua + código postal + localidade → só código postal → só a localidade
            const tentativas = [limpa];
            const rua = (limpa.split(',')[0] || '').replace(/\s+\d+[a-z]?$/i, '').trim();
            const localidade = cp ? limpa.slice(limpa.indexOf(cp) + cp.length).replace(/^[\s,-]+/, '').trim()
                                  : (limpa.split(',').pop() || '').trim();
            const simples = [rua, cp, localidade].filter(x => x).join(', ');
            if (simples && simples !== limpa) tentativas.push(simples);
            if (cp) tentativas.push(cp + (localidade ? ' ' + localidade : ''));
            if (localidade && localidade !== limpa) tentativas.push(localidade);
            for (const q of tentativas) {
                if (!q) continue;
                const url = 'https://nominatim.openstreetmap.org/search?format=json&limit=1&countrycodes=pt&q=' + encodeURIComponent(q);
                const r = await fetch(url, { headers: { 'Accept-Language': 'pt-PT' } });
                if (r.ok) {
                    const d = await r.json();
                    if (d && d.length) return { lat: parseFloat(d[0].lat), lon: parseFloat(d[0].lon), nome: d[0].display_name, aprox: q !== limpa };
                }
                await sleep(1100);   // regra do Nominatim: no máximo 1 pedido por segundo
            }
            return null;
        }
        // ---------- Janela do mapa: confirmar o ponto da obra e escolher o percurso ----------
        // O ponto da obra (marcador vermelho) pode ser arrastado para o sítio exato; o percurso é recalculado.
        // Ida = percurso escolhido (sede → obra); volta = percurso real de regresso (obra → sede), que pode ser diferente.
        let kmMapa = null, kmMarcadorObra = null, kmLinhas = [], kmOrigem = null, kmRotas = [], kmVolta = 0, kmEscolhida = 0;
        function carregarLeaflet() {
            if (window.L) return Promise.resolve();
            return new Promise((ok, falha) => {
                const css = document.createElement('link');
                css.rel = 'stylesheet'; css.href = 'https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.css';
                document.head.appendChild(css);
                const js = document.createElement('script');
                js.src = 'https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.js';
                js.onload = () => ok(); js.onerror = () => falha(new Error('Não foi possível carregar o mapa.'));
                document.head.appendChild(js);
            });
        }
        // As coordenadas da sede ficam guardadas no browser (não é preciso procurá-las sempre)
        async function pontoSede() {
            try {
                const g = JSON.parse(localStorage.getItem('mab_sede_coord') || 'null');
                if (g && g.morada === MORADA_SEDE && g.lat && g.lon) return g;
            } catch (e) {}
            const p = await geolocalizar(MORADA_SEDE);
            if (p) { try { localStorage.setItem('mab_sede_coord', JSON.stringify({ morada: MORADA_SEDE, lat: p.lat, lon: p.lon })); } catch (e) {} }
            return p;
        }
        async function rotaOSRM(a, b, alternativas) {
            const url = 'https://router.project-osrm.org/route/v1/driving/' + a.lon + ',' + a.lat + ';' + b.lon + ',' + b.lat +
                        '?overview=full&geometries=geojson' + (alternativas ? '&alternatives=3' : '');
            const r = await fetch(url);
            const d = r.ok ? await r.json() : null;
            if (!d || d.code !== 'Ok' || !d.routes || !d.routes.length) throw new Error('Não foi possível calcular o percurso.');
            return d.routes;
        }
        const fmtKm = v => v.toLocaleString('pt-PT', { minimumFractionDigits: 1, maximumFractionDigits: 1 });
        const fmtTempo = s => { const m = Math.round(s / 60); return m >= 60 ? Math.floor(m / 60) + 'h ' + String(m % 60).padStart(2, '0') + 'm' : m + ' min'; };

        async function atualizarRotas() {
            const est = document.getElementById('kmEstado');
            est.className = 'km-estado'; est.textContent = 'A calcular os percursos…';
            document.getElementById('kmOpcoes').innerHTML = '';
            const destino = kmMarcadorObra.getLatLng();
            const b = { lat: destino.lat, lon: destino.lng };
            try {
                kmRotas = await rotaOSRM(kmOrigem, b, true);
                const volta = await rotaOSRM(b, kmOrigem, false);
                kmVolta = volta[0].distance / 1000;
            } catch (e) {
                est.className = 'km-estado erro'; est.textContent = e.message || 'Sem ligação ao serviço de mapas.'; return;
            }
            // por omissão: o percurso mais curto (é o que mais se aproxima do "recomendado" do ViaMichelin / Google)
            kmEscolhida = kmRotas.reduce((m, r, i) => r.distance < kmRotas[m].distance ? i : m, 0);
            desenharRotas();
            est.textContent = 'Arraste o marcador vermelho para o local exato da obra, se for preciso.';
        }
        function desenharRotas() {
            kmLinhas.forEach(l => kmMapa.removeLayer(l)); kmLinhas = [];
            kmRotas.forEach((r, i) => {
                const pts = r.geometry.coordinates.map(c => [c[1], c[0]]);
                const l = L.polyline(pts, { color: i === kmEscolhida ? '#b32a2a' : '#95a5a6', weight: i === kmEscolhida ? 6 : 4, opacity: i === kmEscolhida ? 0.9 : 0.6 }).addTo(kmMapa);
                l.on('click', () => { kmEscolhida = i; desenharRotas(); });
                kmLinhas.push(l);
            });
            kmLinhas[kmEscolhida].bringToFront();
            kmMapa.fitBounds(kmLinhas[kmEscolhida].getBounds(), { padding: [30, 30] });
            const box = document.getElementById('kmOpcoes');
            box.innerHTML = kmRotas.map((r, i) => {
                const ida = r.distance / 1000, total = ida + kmVolta;
                return '<label class="km-opcao' + (i === kmEscolhida ? ' ativa' : '') + '"><input type="radio" name="km_rota" ' + (i === kmEscolhida ? 'checked' : '') + ' onchange="kmEscolhida=' + i + ';desenharRotas()">' +
                       '<span><b>Percurso ' + (i + 1) + '</b> · ida ' + fmtKm(ida) + ' km (' + fmtTempo(r.duration) + ') + volta ' + fmtKm(kmVolta) + ' km</span>' +
                       '<strong>' + fmtKm(total) + ' km</strong></label>';
            }).join('');
            const d = kmMarcadorObra.getLatLng();
            document.getElementById('kmGoogle').href = 'https://www.google.com/maps/dir/?api=1&travelmode=driving&origin=' + kmOrigem.lat + ',' + kmOrigem.lon + '&destination=' + d.lat + ',' + d.lng;
        }
        function fecharMapaKm() { document.getElementById('modalKm').classList.remove('show'); }
        function aplicarKmMapa() {
            if (!kmRotas.length) return;
            const total = Math.round((kmRotas[kmEscolhida].distance / 1000 + kmVolta) * 10) / 10;
            const txt = total.toLocaleString('pt-PT', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
            document.getElementById('campo_km').value = txt;
            document.getElementById('km_info').className = 'km-info';
            document.getElementById('km_info').textContent = 'Ida ' + fmtKm(kmRotas[kmEscolhida].distance / 1000) + ' km + volta ' + fmtKm(kmVolta) + ' km = ' + txt + ' km (por estrada, OpenStreetMap)';
            fecharMapaKm();
            if (typeof calcularTudo === 'function') calcularTudo();
        }

        async function calcularDistanciaObra() {
            const campoMorada = document.querySelector('textarea[name="morada_obra"]');
            const info = document.getElementById('km_info');
            const btn = document.getElementById('btn_calc_km');
            const morada = (campoMorada ? campoMorada.value : '').trim();
            info.className = 'km-info';
            if (!morada) { info.className = 'km-info erro'; info.textContent = 'Preencha primeiro a Morada da Obra.'; return; }
            btn.disabled = true; btn.style.opacity = .6;
            info.textContent = 'A localizar as moradas…';
            try {
                await carregarLeaflet();
                kmOrigem = await pontoSede();
                if (!kmOrigem) throw new Error('Não foi possível localizar a morada da sede.');
                await sleep(1100);
                let destino = await geolocalizar(morada);
                document.getElementById('modalKm').classList.add('show');
                document.getElementById('kmMorada').textContent = morada;
                if (!kmMapa) {
                    kmMapa = L.map('kmMapa');
                    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19, attribution: '© OpenStreetMap' }).addTo(kmMapa);
                    kmMapa.on('click', e => { kmMarcadorObra.setLatLng(e.latlng); atualizarRotas(); });   // clicar no mapa também muda o ponto da obra
                }
                setTimeout(() => kmMapa.invalidateSize(), 50);
                kmLinhas.forEach(l => kmMapa.removeLayer(l)); kmLinhas = [];
                if (kmMarcadorObra) kmMapa.removeLayer(kmMarcadorObra);
                if (window._kmSede) kmMapa.removeLayer(window._kmSede);
                window._kmSede = L.circleMarker([kmOrigem.lat, kmOrigem.lon], { radius: 8, color: '#2c3e50', fillColor: '#2c3e50', fillOpacity: 1 }).addTo(kmMapa).bindTooltip('Sede');
                const aviso = document.getElementById('kmAviso');
                if (!destino) {
                    destino = { lat: kmOrigem.lat, lon: kmOrigem.lon, aprox: true };
                    aviso.textContent = 'Não foi possível localizar a morada da obra: arraste o marcador vermelho (ou clique no mapa) no local da obra.';
                } else {
                    aviso.textContent = destino.aprox ? 'Morada localizada de forma aproximada (' + destino.nome.split(',').slice(0, 3).join(',') + ') — confirme o marcador vermelho.' : '';
                }
                kmMarcadorObra = L.marker([destino.lat, destino.lon], { draggable: true, title: 'Obra (arraste para o local exato)' }).addTo(kmMapa);
                kmMarcadorObra.on('dragend', atualizarRotas);
                kmMapa.setView([destino.lat, destino.lon], 13);
                info.textContent = '';
                await atualizarRotas();
            } catch (e) {
                info.className = 'km-info erro';
                info.textContent = (e && e.message && !/fetch/i.test(e.message)) ? e.message : 'Sem ligação ao serviço de mapas. Tente novamente ou escreva os km à mão.';
            } finally {
                btn.disabled = false; btn.style.opacity = 1;
            }
        }

        // Moradas dos clientes (botão "Usar morada do cliente" na Morada da Obra)
        const MORADAS_CLIENTES = <?= safe_json_encode(array_reduce($clientes, function ($acc, $c) {
            $acc[(string)$c['id']] = trim(implode(' ', array_filter([trim((string)($c['morada'] ?? '')), trim(trim((string)($c['codigo_postal'] ?? '')) . ' ' . trim((string)($c['cidade'] ?? '')))], 'strlen')));
            return $acc;
        }, [])) ?>;
        function usarMoradaCliente() {
            const sel = document.querySelector('select[name="id_cliente"]');
            const campo = document.querySelector('textarea[name="morada_obra"]');
            if (!sel || !campo) return;
            let morada = '';
            if (sel.value === 'novo') {
                // cliente novo: usa a morada escrita na ficha do novo cliente
                const v = n => ((document.querySelector('[name="' + n + '"]') || {}).value || '').trim();
                morada = [v('novo_cliente_morada'), (v('novo_cliente_cp') + ' ' + v('novo_cliente_cidade')).trim()].filter(x => x).join(' ');
            } else if (sel.value) {
                morada = MORADAS_CLIENTES[sel.value] || '';
            } else {
                alert('Escolha primeiro o cliente.'); return;
            }
            if (!morada) { alert('Este cliente não tem morada registada.'); return; }
            if (campo.value.trim() !== '' && campo.value.trim() !== morada && !confirm('Substituir a morada da obra atual pela morada do cliente?\n\n' + morada)) return;
            campo.value = morada;
            if (typeof autoAjustarTextarea === 'function') autoAjustarTextarea(campo);
        }

        const VALOR_HORA_HOMEM = <?= json_encode((float)$valor_hora_homem) ?>;
        const tabelaPrecosBD = <?= json_encode($tabela_precos_js) ?>;
        const isModoEditar = <?= $modo_editar ? 'true' : 'false' ?>;
        let isRedirectChosen = false;
        const vistoriasImport = <?= safe_json_encode($vistorias_import) ?>;
        const importVistoriaAuto = <?= (int)$import_vistoria_auto ?>;
        let linhaAtivaPreco = null;

        function parseCSVRow(rowStr) {
            let delimiter = ';';
            if (rowStr.indexOf(';') === -1 && rowStr.indexOf(',') !== -1) {
                delimiter = ',';
            }

            let cols = [];
            let cur = '';
            let inQuotes = false;
            for (let i = 0; i < rowStr.length; i++) {
                let char = rowStr[i];
                if (inQuotes) {
                    if (char === '"') {
                        if (i + 1 < rowStr.length && rowStr[i+1] === '"') {
                            cur += '"';
                            i++;
                        } else {
                            inQuotes = false;
                        }
                    } else {
                        cur += char;
                    }
                } else {
                    if (char === '"') {
                        inQuotes = true;
                    } else if (char === delimiter) {
                        cols.push(cur);
                        cur = '';
                    } else {
                        cur += char;
                    }
                }
            }
            cols.push(cur);
            return cols;
        }

        function importarCSV(input) {
            const file = input.files[0];
            if (!file) return;

            const reader = new FileReader();
            reader.onload = function(e) {
                let text = e.target.result;

                if (text.includes('')) {
                    const readerFallback = new FileReader();
                    readerFallback.onload = function(eFallback) {
                        processarCSV(eFallback.target.result, input);
                    };
                    readerFallback.readAsText(file, 'windows-1252');
                } else {
                    processarCSV(text, input);
                }
            };
            reader.readAsText(file, 'UTF-8');
        }

        function processarCSV(text, input) {
            const rows = text.split(/\r?\n/);
            if (rows.length <= 1) {
                alert("O ficheiro está vazio ou mal formatado.");
                input.value = '';
                return;
            }

            const sample = rows[0] + (rows[1] || '');
            const delimiter = (sample.split(';').length > sample.split(',').length) ? ';' : ',';
            const isMabFormat = rows[0].toLowerCase().includes('tipo linha');

            // As linhas entram no separador aberto (Detalhe Interno ou Resumo Comercial)
            const sepDestino = separadorOrcamentoAtivo();
            const nomeSep = sepDestino === 'comercial' ? 'Resumo Comercial' : 'Detalhe Interno';
            if (confirm("As linhas vão ser importadas para o " + nomeSep + ".\n\nDeseja substituir as linhas atuais do " + nomeSep + "? (OK = Substituir | Cancelar = Adicionar ao final)")) {
                document.querySelector(sepDestino === 'comercial' ? '#tabelaItensComercial tbody' : '#tabelaItensInterno tbody').innerHTML = '';
            }

            let importCount = 0;
            for (let i = 0; i < rows.length; i++) {
                let rowText = rows[i].trim();
                if (!rowText) continue;

                let cols = rowText.split(delimiter).map(c => c.replace(/^"|"$/g, '').trim());

                if (isMabFormat) {
                    if (i === 0) continue;
                    if (cols.length < 3) continue;

                    const tipoLinha = sepDestino === 'comercial' ? 'Comercial' : 'Interno';
                    const data = {
                        tipo_linha: tipoLinha,
                        artigo: cols[1] || '',
                        designacao: cols[2] || '',
                        unidade: cols[3] || '',
                        quantidade: cols[4] || '1',
                        preco_unitario_custo: cols[5] || '0',
                        tempo_mao_obra: cols[6] || '0',
                        preco_unitario_venda: cols[7] || '0'
                    };

                    if (tipoLinha === 'Interno') addRow('interno', data);
                    else addRow('comercial', data);
                    importCount++;

                } else {
                    if (cols.length < 3 || cols[1] === '') continue;
                    if (cols[0].toLowerCase().includes('item') || cols[0].toLowerCase().includes('ref')) continue;

                    let data = {
                        tipo_linha: sepDestino === 'comercial' ? 'Comercial' : 'Interno',
                        artigo: cols[0] || '',
                        designacao: cols[1] || '',
                        unidade: cols[2] || '',
                        quantidade: cols[3] || '1',
                        preco_unitario_custo: '0',
                        tempo_mao_obra: '0',
                        preco_unitario_venda: '0'
                    };

                    addRow(sepDestino, data);
                    importCount++;
                }
            }

            if (importCount > 0) {
                calcularTudo();
                alert(importCount + " artigo(s) importado(s) para o " + nomeSep + " com sucesso!");
            } else {
                alert("Não foram encontrados artigos válidos para importar. Verifique se o ficheiro tem dados nas colunas.");
            }

            input.value = '';
        }

        function forceComma(el) {
            if (el.value.indexOf('.') !== -1) {
                let cursorPosition = el.selectionStart;
                el.value = el.value.replace(/\./g, ',');
                el.setSelectionRange(cursorPosition, cursorPosition);
            }
        }

        function escapeHTML(str) {
            if (typeof str !== 'string') return '';
            return str.replace(/&/g, '&amp;')
                      .replace(/</g, '&lt;')
                      .replace(/>/g, '&gt;')
                      .replace(/"/g, '&quot;')
                      .replace(/'/g, '&#039;');
        }

        function autoAjustarTextarea(el) {
            el.style.height = '1px';
            el.style.height = el.scrollHeight + 'px';
        }

        function switchBudgetTab(tabId, btn) {
            document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
            document.querySelectorAll('.budget-tab-content').forEach(c => c.classList.remove('active'));

            btn.classList.add('active');
            document.getElementById('tab_' + tabId).classList.add('active');
            atualizarDestinoFator();

            document.querySelectorAll('.desig-input').forEach(el => autoAjustarTextarea(el));
        }

        function toggleNovoCliente(val) {
            document.getElementById('form_novo_cliente').style.display = (val === 'novo') ? 'block' : 'none';
        }

        function abrirModalImportar() {
            const clienteSelect = document.querySelector('select[name="id_cliente"]');
            const clienteId = clienteSelect ? clienteSelect.value : '';

            filtrarOpcoesImportacao('select_import_visita', clienteId);
            filtrarOpcoesImportacao('select_import_obra', clienteId);

            const avisoEl = document.getElementById('aviso_filtro_import');
            if (avisoEl) {
                if (clienteId && clienteId !== 'novo') {
                    const nomeCliente = clienteSelect.options[clienteSelect.selectedIndex].text;
                    avisoEl.innerHTML = `<i class="fa-solid fa-filter" style="color:#8b5cf6;"></i> A mostrar apenas registos de: <strong>${escapeHTML(nomeCliente)}</strong> (<a href="javascript:void(0)" onclick="limparFiltroImportacao()" style="color:#3b82f6; text-decoration:underline; font-weight:600;">Mostrar todos</a>)`;
                } else {
                    avisoEl.innerHTML = `<i class="fa-solid fa-circle-info" style="color:#94a3b8;"></i> A mostrar todos os registos (selecione um cliente no formulário principal para filtrar).`;
                }
            }

            document.getElementById('modalImportar').classList.add('show');
        }

        function filtrarOpcoesImportacao(selectId, clienteId) {
            const select = document.getElementById(selectId);
            if (!select) return;
            select.value = "";

            Array.from(select.options).forEach((opt, idx) => {
                if (idx === 0) return;
                const optCliente = opt.getAttribute('data-cliente');
                if (!clienteId || clienteId === 'novo' || optCliente === clienteId) {
                    opt.style.display = '';
                    opt.hidden = false;
                } else {
                    opt.style.display = 'none';
                    opt.hidden = true;
                }
            });
        }

        function limparFiltroImportacao() {
            filtrarOpcoesImportacao('select_import_visita', null);
            filtrarOpcoesImportacao('select_import_obra', null);
            const avisoEl = document.getElementById('aviso_filtro_import');
            if (avisoEl) {
                avisoEl.innerHTML = `<i class="fa-solid fa-unlock" style="color:#10b981;"></i> Filtro removido. A mostrar todos os registos de todos os clientes.`;
            }
        }

        function fecharModalImportar() {
            document.getElementById('modalImportar').classList.remove('show');
        }

        function executarImportacao(tipo) {
            const selectId = tipo === 'visita' ? 'select_import_visita' : 'select_import_obra';
            const selectEl = document.getElementById(selectId);
            const id = selectEl.value;

            if (!id) {
                alert("Por favor, selecione uma " + (tipo === 'visita' ? 'Visita' : 'Obra') + " válida para importar.");
                return;
            }

            window.location.href = 'orcamento_form.php?import_' + tipo + '=' + id;
        }

        // ===================== IMPORTAR VISTORIA DE INSTALAÇÃO =====================
        function abrirModalVistoria(idPre) {
            const sel = document.getElementById('select_vistoria');
            const clienteSelect = document.querySelector('select[name="id_cliente"]');
            const clienteId = clienteSelect ? clienteSelect.value : '';
            preencherSelectVistorias(clienteId && clienteId !== 'novo' ? clienteId : null);

            const aviso = document.getElementById('aviso_filtro_vistoria');
            if (clienteId && clienteId !== 'novo') {
                const nomeCliente = clienteSelect.options[clienteSelect.selectedIndex].text;
                aviso.innerHTML = `<i class="fa-solid fa-filter" style="color:#2980b9;"></i> A mostrar vistorias de: <strong>${escapeHTML(nomeCliente)}</strong> (<a href="javascript:void(0)" onclick="preencherSelectVistorias(null); this.parentNode.innerHTML='<i class=&quot;fa-solid fa-unlock&quot; style=&quot;color:#10b981;&quot;></i> A mostrar todas as vistorias.';" style="color:#3b82f6; text-decoration:underline; font-weight:600;">Mostrar todas</a>)`;
            } else {
                aviso.innerHTML = `<i class="fa-solid fa-circle-info" style="color:#94a3b8;"></i> A mostrar todas as vistorias (escolha um cliente no formulário para filtrar).`;
            }

            if (idPre) {
                if (!sel.querySelector('option[value="' + idPre + '"]')) preencherSelectVistorias(null);
                sel.value = String(idPre);
            }
            renderVistoriaSelecionada();
            document.getElementById('modalVistoria').classList.add('show');
        }

        function fecharModalVistoria() { document.getElementById('modalVistoria').classList.remove('show'); }

        function preencherSelectVistorias(clienteId) {
            const sel = document.getElementById('select_vistoria');
            const atual = sel.value;
            sel.innerHTML = '<option value="">-- Selecione uma Vistoria --</option>';
            const lista = vistoriasImport.filter(v => !clienteId || String(v.id_cliente) === String(clienteId));
            if (lista.length === 0) {
                sel.innerHTML = '<option value="">-- Sem vistorias para este cliente --</option>';
            }
            lista.forEach(v => {
                const n = v.alteracoes.length;
                const txt = 'Nº ' + (v.referencia || ('#' + v.id)) + ' · ' + v.data + ' · ' + (v.cliente_nome || '') + (v.obra_nome ? ' (' + v.obra_nome + ')' : '') + ' — ' + n + ' alteraç' + (n === 1 ? 'ão' : 'ões');
                sel.add(new Option(txt, v.id));
            });
            if (atual && sel.querySelector('option[value="' + atual + '"]')) sel.value = atual;
            renderVistoriaSelecionada();
        }

        function getVistoria(id) { return vistoriasImport.find(v => String(v.id) === String(id)); }

        function renderVistoriaSelecionada() {
            const id = document.getElementById('select_vistoria').value;
            const det = document.getElementById('vi_detalhe');
            const btn = document.getElementById('btn_importar_vi');
            const v = getVistoria(id);
            if (!v) { det.style.display = 'none'; btn.disabled = true; return; }
            det.style.display = 'block';

            document.getElementById('vi_info').innerHTML =
                '<strong style="color:var(--dark);">Vistoria Nº ' + escapeHTML(v.referencia || ('#' + v.id)) + '</strong> · ' + escapeHTML(v.data) + '<br>' +
                '<i class="fa-solid fa-user-tie"></i> ' + escapeHTML(v.cliente_nome || '-') +
                (v.obra_nome ? ' &nbsp;·&nbsp; <i class="fa-solid fa-building"></i> ' + escapeHTML(v.obra_nome) : '') +
                (v.obra_morada ? '<br><i class="fa-solid fa-location-dot"></i> ' + escapeHTML(v.obra_morada) : '');

            const lista = document.getElementById('vi_lista');
            if (v.alteracoes.length === 0) {
                lista.innerHTML = '<div class="vi-vazio"><i class="fa-solid fa-circle-check" style="color:#10b981;"></i> Esta vistoria não tem alterações a efetuar.</div>';
            } else {
                // Agrupar por local mantendo a ordem
                const grupos = [];
                v.alteracoes.forEach((a, i) => {
                    let g = grupos.find(x => x.local === a.local);
                    if (!g) { g = { local: a.local, itens: [] }; grupos.push(g); }
                    g.itens.push({ i: i, descricao: a.descricao });
                });
                let html = '';
                grupos.forEach((g, gi) => {
                    html += '<div class="vi-grupo"><input type="checkbox" checked onclick="marcarGrupoVi(this, ' + gi + ')"><i class="fa-solid fa-location-dot"></i> ' + escapeHTML(g.local) + ' (' + g.itens.length + ')</div>';
                    g.itens.forEach(it => {
                        html += '<label class="vi-linha"><input type="checkbox" class="vi-chk" data-grupo="' + gi + '" value="' + it.i + '" checked onchange="atualizarBotaoVi()"><span>' + escapeHTML(it.descricao) + '</span></label>';
                    });
                });
                lista.innerHTML = html;
            }
            document.getElementById('vi_opcao_obs').style.display = v.observacoes.length ? '' : 'none';
            atualizarBotaoVi();
        }

        function marcarGrupoVi(chk, gi) {
            document.querySelectorAll('.vi-chk[data-grupo="' + gi + '"]').forEach(c => c.checked = chk.checked);
            atualizarBotaoVi();
        }

        function atualizarBotaoVi() {
            const v = getVistoria(document.getElementById('select_vistoria').value);
            const n = document.querySelectorAll('.vi-chk:checked').length;
            const btn = document.getElementById('btn_importar_vi');
            btn.disabled = !v;
            if (!v) return;
            btn.innerHTML = '<i class="fa-solid fa-file-import"></i> ' + (n > 0 ? 'Importar ' + n + ' linha' + (n === 1 ? '' : 's') : (isModoEditar ? 'Associar vistoria (sem linhas)' : 'Importar dados da vistoria'));
        }

        function tabelaSoTemLinhaVazia(tipo) {
            const rows = document.querySelectorAll((tipo === 'interno' ? '#tabelaItensInterno' : '#tabelaItensComercial') + ' tbody tr');
            if (rows.length !== 1) return false;
            const r = rows[0];
            return ['.artigo-input', '.desig-input', '.p_custo', '.pv'].every(sel => {
                const el = r.querySelector(sel);
                return !el || el.type === 'hidden' || el.value.trim() === '' || el.value.trim() === '0';
            });
        }

        function adicionarLinhasVistoria(tipo, grupos) {
            const tbodySel = tipo === 'interno' ? '#tabelaItensInterno tbody' : '#tabelaItensComercial tbody';
            if (tabelaSoTemLinhaVazia(tipo)) document.querySelector(tbodySel).innerHTML = '';
            grupos.forEach(g => {
                // Linha de cabeçalho com o local (sem unidade/quantidade, como os títulos do orçamento)
                addRow(tipo, { designacao: g.local.toUpperCase() });
                const rows = document.querySelectorAll(tbodySel + ' tr');
                const cab = rows[rows.length - 1];
                cab.querySelector('.unidade-input').value = '';
                cab.querySelector('.qty').value = '';
                calcular(cab.querySelector('.qty'));
                // Uma linha por alteração
                g.itens.forEach(desc => addRow(tipo, { designacao: desc, unidade: 'vg', quantidade: '1,00' }));
            });
            document.querySelectorAll(tbodySel + ' .desig-input').forEach(ta => autoAjustarTextarea(ta));
        }

        function executarImportacaoVistoria() {
            const v = getVistoria(document.getElementById('select_vistoria').value);
            if (!v) return;

            // Linhas selecionadas, agrupadas por local
            const grupos = [];
            document.querySelectorAll('.vi-chk:checked').forEach(c => {
                const a = v.alteracoes[parseInt(c.value)];
                if (!a) return;
                let g = grupos.find(x => x.local === a.local);
                if (!g) { g = { local: a.local, itens: [] }; grupos.push(g); }
                g.itens.push(a.descricao);
            });

            // Num orçamento novo, herda cliente, obra, morada e o número partilhado
            if (!isModoEditar) {
                if (v.referencia) document.querySelector('input[name="numero_orcamento"]').value = v.referencia;
                const cli = document.querySelector('select[name="id_cliente"]');
                if (cli && v.id_cliente && cli.querySelector('option[value="' + v.id_cliente + '"]')) { cli.value = String(v.id_cliente); toggleNovoCliente(cli.value); }
                const nomeObra = document.querySelector('input[name="nome_obra"]');
                if (nomeObra && v.obra_nome && nomeObra.value.trim() === '') nomeObra.value = v.obra_nome;
                const morada = document.querySelector('textarea[name="morada_obra"]');
                if (morada && v.obra_morada && morada.value.trim() === '') { morada.value = v.obra_morada; autoAjustarTextarea(morada); }
            }

            const destino = (document.querySelector('input[name="vi_destino"]:checked') || {}).value || 'interno';
            if (grupos.length) {
                if (destino === 'interno' || destino === 'ambos') adicionarLinhasVistoria('interno', grupos);
                if (destino === 'comercial' || destino === 'ambos') adicionarLinhasVistoria('comercial', grupos);
            }

            // Observações da vistoria → Observações do orçamento
            const chkObs = document.getElementById('vi_importar_obs');
            if (chkObs && chkObs.checked && v.observacoes.length) {
                const cont = document.getElementById('obsContainer');
                const linhas = cont.querySelectorAll('.list-row textarea');
                if (linhas.length === 1 && linhas[0].value.trim() === '') cont.innerHTML = '';
                v.observacoes.forEach(o => addListRow('obsContainer', 'obs_linha', o));
            }

            // Guardar a ligação e mostrar o selo
            document.getElementById('id_vistoria_origem').value = v.id;
            const selo = document.getElementById('selo_vistoria');
            selo.href = 'vistoria_instalacao_form.php?id=' + v.id;
            document.getElementById('selo_vistoria_txt').textContent = 'Vistoria Nº ' + (v.referencia || ('#' + v.id));
            selo.style.display = '';

            calcularTudo();
            fecharModalVistoria();
            if (destino === 'comercial') {
                const btnTab = document.querySelectorAll('.tab-btn')[1];
                if (btnTab) switchBudgetTab('comercial', btnTab);
            }
            const total = grupos.reduce((s, g) => s + g.itens.length, 0);
            if (total > 0) {
                setTimeout(() => alert(total + ' linha(s) importada(s) da Vistoria Nº ' + (v.referencia || v.id) + '. Falta preencher artigos e preços.'), 50);
            }
        }

        // Exporta só as linhas do separador aberto (Detalhe Interno ou Resumo Comercial)
        function exportarCSV() {
            const sep = separadorOrcamentoAtivo();
            const q = v => String(v == null ? '' : v).replace(/"/g, '""');
            let csvContent = "\uFEFF";
            csvContent += "Tipo Linha;Artigo;Designação;Unidade;Quantidade;Preço Custo;Tempo MO;Preço Venda\n";
            let n = 0;
            const tabela = sep === 'comercial' ? '#tabelaItensComercial' : '#tabelaItensInterno';
            document.querySelectorAll(tabela + ' tbody tr').forEach(row => {
                const val = sel => { const el = row.querySelector(sel); return el ? el.value : ''; };
                const artigo = q(val('.artigo-input')), designacao = q(val('.desig-input'));
                if (designacao === '' && artigo === '') return;
                const unidade = q(val('.unidade-input')), qtd = q(val('.qty')), pVenda = q(val('.pv'));
                if (sep === 'comercial') {
                    csvContent += `"Comercial";"${artigo}";"${designacao}";"${unidade}";"${qtd}";"0";"0";"${pVenda}"\n`;
                } else {
                    csvContent += `"Interno";"${artigo}";"${designacao}";"${unidade}";"${qtd}";"${q(val('.p_custo'))}";"${q(val('.t_mo'))}";"${pVenda}"\n`;
                }
                n++;
            });
            if (n === 0) { alert('O separador ' + (sep === 'comercial' ? 'Resumo Comercial' : 'Detalhe Interno') + ' não tem linhas para exportar.'); return; }
            const sufixo = sep === 'comercial' ? 'Comercial' : 'Interno';
            let numOrc = document.querySelector('input[name="numero_orcamento"]').value || 'Novo';
            let blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
            let link = document.createElement("a");
            if (link.download !== undefined) {
                let url = URL.createObjectURL(blob);
                link.setAttribute("href", url);
                link.setAttribute("download", `Artigos_Orcamento_${numOrc}_${sufixo}.csv`);
                document.body.appendChild(link);
                link.click();
                document.body.removeChild(link);
            }
        }

        function importarLinhasInternas() {
            const linhasInternas = document.querySelectorAll('#tabelaItensInterno tbody tr');
            let copiadas = 0;

            linhasInternas.forEach(row => {
                const art = row.querySelector('.artigo-input').value.trim();
                const desig = row.querySelector('.desig-input').value.trim();
                const un = row.querySelector('.unidade-input').value.trim();
                const qty = row.querySelector('.qty').value.trim();
                const pv = row.querySelector('.pv').value.trim();

                if (desig !== '' || art !== '') {
                    addRow('comercial', {
                        artigo: art,
                        designacao: desig,
                        unidade: un,
                        quantidade: qty,
                        preco_unitario_venda: pv
                    });
                    copiadas++;
                }
            });

            if (copiadas > 0) {
                calcularTudo();
                alert(copiadas + " linha(s) importada(s) com sucesso para o Resumo Comercial!");
            } else {
                alert("Não existem linhas preenchidas no Detalhe Interno para poder herdar.");
            }
        }

        // Regra da pesquisa de material: cada artigo aparece como "descrição | referência do fabricante | marca"
        function rotuloMaterial(item) { return [item.descricao, item.referencia, item.marca].map(x => String(x || '').trim()).filter(Boolean).join(' | '); }
        function pontuarArtigo(item, pesquisaOriginal) {
            let score = 0; const textoBusca = pesquisaOriginal.trim().toLowerCase(); if (!textoBusca) return 0;
            const itemDesc = (item.descricao || '').toLowerCase(); const itemRef = (item.referencia || '').toLowerCase();
            const fullText = itemRef + " " + itemDesc + " " + String(item.marca || '').toLowerCase(); const primeiraPalavra = textoBusca.split(' ')[0];
            const tokens = textoBusca.match(/[a-zà-ÿ0-9]+/g) || [];
            if (itemDesc.startsWith(textoBusca) || itemRef.startsWith(textoBusca)) { score += 1000; }
            else if (fullText.includes(textoBusca)) { score += 500; }
            if (primeiraPalavra && itemDesc.startsWith(primeiraPalavra)) { score += 200; }
            let tokensEncontrados = 0;
            tokens.forEach(token => { if (token.length >= 2 && fullText.includes(token)) { tokensEncontrados++; score += 10; } });
            if (tokens.length > 0 && tokensEncontrados === tokens.length) { score += 100; }
            return score;
        }

        function mostrarPrecos(pcInput) {
            const row = pcInput.closest('tr'); const desigInput = row.querySelector('.desig-input'); if (!desigInput) return;
            const pesquisa = desigInput.value; const list = row.querySelector('.preco-autocomplete-list');
            if (pesquisa.trim().length < 2) { list.style.display = 'none'; return; }
            let scoredItems = tabelaPrecosBD.map(item => { return { item: item, score: pontuarArtigo(item, pesquisa) }; });
            let matches = scoredItems.filter(x => x.score > 0).sort((a, b) => b.score - a.score).map(x => x.item);
            if (matches.length > 0) {
                list.innerHTML = '<div style="padding:8px 15px; background:var(--light-bg); font-size:11px; font-weight:700; color:var(--gray); border-bottom:1px solid var(--border-color); text-transform:uppercase;">Sugestões Inteligentes</div>';
                matches.slice(0, 8).forEach(item => {
                    const div = document.createElement('div'); div.className = 'preco-autocomplete-item';
                    const pCustoFormatado = formatNumberToPT(item.preco_custo); const pVendaFormatado = item.preco_com_mo ? formatNumberToPT(item.preco_com_mo) : '0,00';
                    div.innerHTML = `<div>${escapeHTML(rotuloMaterial(item))}</div><strong>Custo: ${pCustoFormatado} € <span style="color:#94a3b8;font-weight:normal;font-size:11px;">| Venda: ${pVendaFormatado} €</span></strong>`;
                    div.onmousedown = function(e) {
                        e.preventDefault(); pcInput.value = pCustoFormatado;
                        const pvInput = row.querySelector('.pv'); if (pvInput) pvInput.value = pVendaFormatado;
                        const mbInput = row.querySelector('.margem-bloqueada-input'); if (mbInput) mbInput.value = item.margem_bloqueada || 0;
                        const tMoInput = row.querySelector('.t_mo');
                        if (tMoInput) {
                            let totalMo = (parseFloat(item.tempo_mao_obra) || 0) * (parseInt(item.n_colaboradores) || 1);
                            tMoInput.value = totalMo > 0 ? formatNumberToPT(totalMo) : '';
                        }
                        // Liga a linha ao artigo da tabela (marca / ficha técnica na FAME)
                        const idArt = row.querySelector('.id-art-tab'); if (idArt) idArt.value = item.id || '';
                        marcarFicha(row);
                        // O Ref/Artigo da linha nunca é alterado ao escolher um preço
                        if (!isModoEditar) {
                            if (row.querySelector('.unidade-input')) row.querySelector('.unidade-input').value = item.unidade;
                        }
                        calcular(pcInput); list.style.display = 'none';
                    };
                    list.appendChild(div);
                });
                list.style.display = 'block';
            } else { list.style.display = 'none'; }
        }

        function esconderPrecos(pcInput) { setTimeout(() => { const list = pcInput.closest('tr').querySelector('.preco-autocomplete-list'); if(list) list.style.display = 'none'; }, 150); }
        function abrirModalPesquisaPrecos(btn) { linhaAtivaPreco = btn.closest('tr'); const desigOriginal = linhaAtivaPreco.querySelector('.desig-input').value; const inputPesquisa = document.getElementById('inputFiltroPrecos'); inputPesquisa.value = desigOriginal; document.getElementById('modalPesquisaPrecos').classList.add('show'); filtrarListaPrecos(); setTimeout(() => inputPesquisa.focus(), 100); }
        function fecharModalPesquisaPrecos() { document.getElementById('modalPesquisaPrecos').classList.remove('show'); linhaAtivaPreco = null; }
        function filtrarListaPrecos() { const pesquisa = document.getElementById('inputFiltroPrecos').value; if (pesquisa.trim() === '') { renderListaPrecos(tabelaPrecosBD.slice(0, 50)); return; } let scoredItems = tabelaPrecosBD.map(item => { return { item: item, score: pontuarArtigo(item, pesquisa) }; }); let filtrados = scoredItems.filter(x => x.score > 0).sort((a, b) => b.score - a.score).map(x => x.item); renderListaPrecos(filtrados.slice(0, 50)); }

        function renderListaPrecos(lista) {
            const container = document.getElementById('listaPrecosContainer'); container.innerHTML = ''; container.style.display = 'block';
            if(lista.length === 0) { container.innerHTML = '<div style="padding:25px; text-align:center; color:var(--gray);">Nenhum artigo encontrado.</div>'; return; }
            lista.forEach(item => {
                const div = document.createElement('div'); div.className = 'preco-autocomplete-item';
                div.innerHTML = `<div style="display:flex; justify-content:space-between; width:100%;"><div>${escapeHTML(rotuloMaterial(item))}</div><div style="font-weight:800; color:var(--primary); font-size:15px;">${formatNumberToPT(item.preco_custo)} €</div></div>`;
                div.onclick = function() { selecionarPrecoTabela(item); }; container.appendChild(div);
            });
        }

        function selecionarPrecoTabela(item) {
            if(linhaAtivaPreco) {
                // O Ref/Artigo da linha nunca é alterado ao escolher um preço
                if (linhaAtivaPreco.querySelector('.id-art-tab')) linhaAtivaPreco.querySelector('.id-art-tab').value = item.id || '';
                marcarFicha(linhaAtivaPreco);
                if (linhaAtivaPreco.querySelector('.desig-input') && !isModoEditar) linhaAtivaPreco.querySelector('.desig-input').value = item.descricao;
                if (linhaAtivaPreco.querySelector('.unidade-input') && !isModoEditar) linhaAtivaPreco.querySelector('.unidade-input').value = item.unidade;
                if (linhaAtivaPreco.querySelector('.p_custo')) linhaAtivaPreco.querySelector('.p_custo').value = formatNumberToPT(item.preco_custo);
                if (linhaAtivaPreco.querySelector('.pv') && item.preco_com_mo) linhaAtivaPreco.querySelector('.pv').value = formatNumberToPT(item.preco_com_mo);
                if (linhaAtivaPreco.querySelector('.t_mo')) {
                    let totalMo = (parseFloat(item.tempo_mao_obra) || 0) * (parseInt(item.n_colaboradores) || 1);
                    linhaAtivaPreco.querySelector('.t_mo').value = totalMo > 0 ? formatNumberToPT(totalMo) : '';
                }
                calcular(linhaAtivaPreco.querySelector('.qty'));
            }
            fecharModalPesquisaPrecos();
        }

        function updateMoveArrows(tipo) {
            const tableId = tipo === 'interno' ? '#tabelaItensInterno' : '#tabelaItensComercial';
            document.querySelectorAll(tableId + ' .move-controls').forEach(el => el.style.display = 'none');
            const checkedBoxes = document.querySelectorAll(tableId + ' .chk-select-row:checked');
            if (checkedBoxes.length > 0) {
                checkedBoxes.forEach(chk => {
                    const controls = chk.closest('.chk-wrapper').querySelector('.move-controls');
                    if (controls) controls.style.display = 'flex';
                });
            }
            // Botão Renumerar: só com 2 ou mais linhas escolhidas nesta tabela
            const btn = document.getElementById('btnRenumerar');
            if (btn) {
                if (checkedBoxes.length >= 2) {
                    btn.dataset.tipo = tipo;
                    document.getElementById('btnRenumerarInfo').textContent = '(' + checkedBoxes.length + ' linhas)';
                    btn.classList.add('show');
                } else if (!btn.dataset.tipo || btn.dataset.tipo === tipo) {
                    btn.classList.remove('show');
                }
            }
        }

        // Tira o visto a todas as linhas da tabela (e à caixa do cabeçalho)
        function desselecionarTodas() {
            const tipo = document.getElementById('btnRenumerar').dataset.tipo || 'interno';
            const tableId = tipo === 'interno' ? '#tabelaItensInterno' : '#tabelaItensComercial';
            document.querySelectorAll(tableId + ' .chk-select-row').forEach(c => c.checked = false);
            const cab = document.querySelector(tableId + ' thead input[type=checkbox]'); if (cab) cab.checked = false;
            updateMoveArrows(tipo);
        }

        // Renumera o Ref/Artigo das linhas escolhidas, por ordem, a partir do número da 1ª escolhida.
        // Mantém o que está antes e depois do último número e os zeros à esquerda:
        //   "1.1" → 1.2, 1.3 …   "2.08" → 2.09, 2.10 …   "A-5" → A-6 …   1ª vazia → 1, 2, 3 …
        function renumerarSelecionadas() {
            const btn = document.getElementById('btnRenumerar');
            const tipo = btn.dataset.tipo || 'interno';
            const tableId = tipo === 'interno' ? '#tabelaItensInterno' : '#tabelaItensComercial';
            const linhas = Array.from(document.querySelectorAll(tableId + ' tbody tr')).filter(r => { const c = r.querySelector('.chk-select-row'); return c && c.checked; });
            if (linhas.length < 2) return;
            const primeiro = (linhas[0].querySelector('.artigo-input').value || '').trim();
            let prefixo = '', numero = 1, sufixo = '', largura = 1;
            const p = partirArtigo(primeiro);
            if (p) { prefixo = p.prefixo; numero = p.numero; sufixo = p.sufixo; largura = p.largura; }
            else if (primeiro !== '') { prefixo = primeiro + '.'; numero = 1; }
            const exemplo = [0, 1, 2].slice(0, Math.min(3, linhas.length)).map(k => prefixo + String(numero + k).padStart(largura, '0') + sufixo).join(', ');
            if (!confirm('Renumerar ' + linhas.length + ' linhas: ' + exemplo + (linhas.length > 3 ? ', …' : '') + ' ?')) return;
            linhas.forEach((r, k) => {
                const inp = r.querySelector('.artigo-input');
                const novo = prefixo + String(numero + k).padStart(largura, '0') + sufixo;
                inp.value = novo.slice(0, parseInt(inp.getAttribute('maxlength') || '12', 10));
                r.style.transition = 'background .6s'; r.style.background = '#ede9fe';
                setTimeout(() => { r.style.background = ''; }, 700);
            });
        }

        function moveSelectedRows(tipo, dir) {
            const tableId = tipo === 'interno' ? '#tabelaItensInterno' : '#tabelaItensComercial';
            const tbody = document.querySelector(tableId + ' tbody'); const rows = Array.from(tbody.querySelectorAll('tr'));
            // Todas as linhas escolhidas andam uma posição (em bloco, mantendo a ordem entre elas)
            const marcada = r => r.querySelector('.chk-select-row').checked;
            if (dir === 'up') {
                if (rows.length && marcada(rows[0])) { updateMoveArrows(tipo); return; }   // já está no topo
                for (let i = 1; i < rows.length; i++) {
                    if (marcada(rows[i]) && !marcada(rows[i - 1])) {
                        tbody.insertBefore(rows[i], rows[i - 1]);
                        [rows[i - 1], rows[i]] = [rows[i], rows[i - 1]];
                    }
                }
            } else {
                if (rows.length && marcada(rows[rows.length - 1])) { updateMoveArrows(tipo); return; }   // já está no fim
                for (let i = rows.length - 2; i >= 0; i--) {
                    if (marcada(rows[i]) && !marcada(rows[i + 1])) {
                        tbody.insertBefore(rows[i + 1], rows[i]);
                        [rows[i], rows[i + 1]] = [rows[i + 1], rows[i]];
                    }
                }
            }
            updateMoveArrows(tipo);
        }

        function duplicateSelectedRows(tipo) {
            const tableId = tipo === 'interno' ? '#tabelaItensInterno' : '#tabelaItensComercial';
            const tbody = document.querySelector(tableId + ' tbody');
            const rows = Array.from(tbody.querySelectorAll('tr'));

            for (let i = rows.length - 1; i >= 0; i--) {
                const row = rows[i];
                if (row.querySelector('.chk-select-row').checked) {
                    let data = {
                        artigo: row.querySelector('.artigo-input') ? row.querySelector('.artigo-input').value : '',
                        designacao: row.querySelector('.desig-input') ? row.querySelector('.desig-input').value : '',
                        unidade: row.querySelector('.unidade-input') ? row.querySelector('.unidade-input').value : '',
                        quantidade: row.querySelector('.qty') ? row.querySelector('.qty').value : '',
                        preco_unitario_custo: row.querySelector('.p_custo') ? row.querySelector('.p_custo').value : '',
                        tempo_mao_obra: row.querySelector('.t_mo') ? row.querySelector('.t_mo').value : '',
                        preco_unitario_venda: row.querySelector('.pv') ? row.querySelector('.pv').value : '',
                        margem_bloqueada: row.querySelector('.margem-bloqueada-input') ? row.querySelector('.margem-bloqueada-input').value : '0',
                        id_artigo_tabela: row.querySelector('.id-art-tab') ? row.querySelector('.id-art-tab').value : '',
                        composicao_json: row.querySelector('.comp-json') ? row.querySelector('.comp-json').value : ''
                    };
                    const newRow = tbody.insertRow(row.sectionRowIndex + 1);
                    newRow.innerHTML = getRowHTML(tipo, data);
                    marcarFicha(newRow);
                    const ta = newRow.querySelector('.desig-input');
                    if(ta && data.designacao) { autoAjustarTextarea(ta); }
                    if(data.quantidade) calcular(newRow.querySelector('.qty'));
                }
            }
            updateMoveArrows(tipo);
        }

        function toggleAllRows(tipo, master) {
            const tableId = tipo === 'interno' ? '#tabelaItensInterno' : '#tabelaItensComercial';
            document.querySelectorAll(tableId + ' .chk-select-row').forEach(chk => chk.checked = master.checked);
            updateMoveArrows(tipo);
        }

        function getRowHTML(tipo, data = {}) {
            let safeArtigo = escapeHTML(data.artigo || '');
            let safeDesignacao = escapeHTML(data.designacao || '');
            let safeUnidade = escapeHTML(data.unidade || '');
            let safeQtd = escapeHTML(data.quantidade || '');
            // "vg" e "1,00" só como sugestão numa linha NOVA do Resumo Comercial; linhas gravadas ficam como estão
            const linhaNova = !data || Object.keys(data).length === 0;
            let safePC = escapeHTML(data.preco_unitario_custo || '');
            let safeTMO = escapeHTML(data.tempo_mao_obra || '');
            let safePV = escapeHTML(data.preco_unitario_venda || '');
            let safeMB = escapeHTML(data.margem_bloqueada || '0');
            let safeIdArt = escapeHTML(String(parseInt(data.id_artigo_tabela || 0, 10) || ''));
            let safeComp = escapeHTML(typeof data.composicao_json === 'string' ? data.composicao_json : (data.composicao_json ? JSON.stringify(data.composicao_json) : ''));

            if (tipo === 'interno') {
                let lupaHtml = `<td class="col-lupa"><button type="button" class="btn-lupa" tabindex="-1" onclick="abrirModalPesquisaPrecos(this)"><i class="fa-solid fa-search"></i></button></td>`;
                let isLocked = safeMB == 1;
                let lockIcon = isLocked ? '<i class="fa-solid fa-lock icon-lock-tabela" style="color:var(--red); font-size:11px; position:absolute; top:6px; right:6px; pointer-events:none; background:#fff; border-radius:50%;" title="Margem Bloqueada (Tabela de Preços)"></i>' : '';

                return `
                    <td class="col-chk align-center">
                        <div class="chk-wrapper">
                            <input type="checkbox" class="chk-select-row" onchange="updateMoveArrows('interno')">
                            <div class="move-controls" style="display:none;">
                                <i class="fa-solid fa-chevron-up" onclick="moveSelectedRows('interno', 'up')"></i>
                                <i class="fa-solid fa-chevron-down" onclick="moveSelectedRows('interno', 'down')"></i>
                                <i class="fa-solid fa-copy" onclick="duplicateSelectedRows('interno')"></i>
                            </div>
                        </div>
                    </td>
                    <td class="col-artigo"><input type="text" name="artigo[]" maxlength="12" class="artigo-input align-left" value="${safeArtigo}"></td>
                    ${lupaHtml}
                    <td class="col-designacao"><textarea name="designacao[]" class="desig-input align-left" rows="1" oninput="autoAjustarTextarea(this)">${safeDesignacao}</textarea><a class="ficha-ico" href="#" target="_blank" style="display:none;" title="Ficha técnica"><i class="fa-solid fa-file-pdf"></i></a><button type="button" class="btn-comp" onclick="abrirComposicao(this)" title="Vários artigos da tabela de preços nesta linha"><i class="fa-solid fa-layer-group"></i><span class="comp-n"></span></button></td>
                    <td class="col-unidade"><input type="text" name="unidade[]" maxlength="4" class="unidade-input align-center" value="${safeUnidade}" oninput="calcular(this)"></td>
                    <td class="col-qtd"><input type="text" name="quantidade[]" class="qty align-center" oninput="forceComma(this); calcular(this)" onblur="formatField(this)" value="${safeQtd}" style="font-weight:bold;"></td>
                    <td class="col-valor"><input type="text" name="preco_unitario_custo[]" class="p_custo align-center" onfocus="mostrarPrecos(this)" oninput="forceComma(this); syncVenda(this); calcular(this); mostrarPrecos(this);" onblur="esconderPrecos(this); formatField(this)" value="${safePC}" autocomplete="off"><div class="preco-autocomplete-list"></div></td>
                    <td class="col-valor"><input type="text" name="tempo_mao_obra[]" class="t_mo align-center" oninput="forceComma(this); calcular(this)" onblur="formatField(this)" value="${safeTMO}" style="color:#3b82f6;"></td>
                    <td class="col-valor"><input type="text" class="v_custo align-center" readonly style="background:transparent; border:none; font-weight:700; color:var(--gray);"></td>
                    <td class="col-valor" style="position:relative;"><input type="hidden" name="margem_bloqueada[]" class="margem-bloqueada-input" value="${safeMB}"><input type="text" name="preco_unitario_venda[]" class="pv align-center" oninput="forceComma(this); calcular(this);" onblur="formatField(this)" value="${safePV}" style="font-weight:bold;">${lockIcon}</td>
                    <td class="col-valor"><input type="text" class="total_v align-right" readonly style="background:transparent; border:none; font-weight:800; color:var(--dark); font-size:15px;"></td>
                    <td class="col-del"><button type="button" class="btn-row-action btn-add-row" onclick="insertRowAfter('interno', this)"><i class="fa-solid fa-plus"></i></button><button type="button" class="btn-row-action btn-del-row" onclick="this.closest('tr').remove(); calcularTudo();"><i class="fa-solid fa-trash-can"></i></button></td>
                    <input type="hidden" name="tipo_linha_real[]" class="tipo-linha-indicator" value="Interno">
                    <input type="hidden" class="id-art-tab" value="${safeIdArt}">
                    <input type="hidden" class="comp-json" value="${safeComp}">
                `;
            } else {
                return `
                    <td class="col-chk align-center">
                        <div class="chk-wrapper">
                            <input type="checkbox" class="chk-select-row" onchange="updateMoveArrows('comercial')">
                            <div class="move-controls" style="display:none;">
                                <i class="fa-solid fa-chevron-up" onclick="moveSelectedRows('comercial', 'up')"></i>
                                <i class="fa-solid fa-chevron-down" onclick="moveSelectedRows('comercial', 'down')"></i>
                                <i class="fa-solid fa-copy" onclick="duplicateSelectedRows('comercial')"></i>
                            </div>
                        </div>
                    </td>
                    <td class="col-artigo"><input type="text" name="artigo[]" maxlength="12" class="artigo-input align-left" value="${safeArtigo}"></td>
                    <td class="col-designacao"><textarea name="designacao[]" class="desig-input align-left" rows="1" oninput="autoAjustarTextarea(this)">${safeDesignacao}</textarea></td>
                    <td class="col-unidade"><input type="text" name="unidade[]" maxlength="4" class="unidade-input align-center" value="${linhaNova ? 'vg' : safeUnidade}" oninput="calcular(this)"></td>
                    <td class="col-qtd"><input type="text" name="quantidade[]" class="qty align-center" oninput="forceComma(this); calcular(this)" onblur="formatField(this)" value="${linhaNova ? '1,00' : safeQtd}" style="font-weight:bold;"></td>
                    <td class="col-valor"><input type="text" name="preco_unitario_venda[]" class="pv align-center" oninput="forceComma(this); calcular(this);" onblur="formatField(this)" value="${safePV}" style="font-weight:bold;"></td>
                    <td class="col-valor"><input type="text" class="total_v align-right" readonly style="background:transparent; border:none; font-weight:800; color:var(--dark); font-size:15px;"></td>
                    <td class="col-del"><button type="button" class="btn-row-action btn-add-row" onclick="insertRowAfter('comercial', this)"><i class="fa-solid fa-plus"></i></button><button type="button" class="btn-row-action btn-del-row" onclick="this.closest('tr').remove(); calcularTudo();"><i class="fa-solid fa-trash-can"></i></button></td>
                    <input type="hidden" name="preco_unitario_custo[]" class="p_custo" value="0">
                    <input type="hidden" name="tempo_mao_obra[]" class="t_mo" value="0">
                    <input type="hidden" name="margem_bloqueada[]" class="margem-bloqueada-input" value="0">
                    <input type="hidden" name="tipo_linha_real[]" class="tipo-linha-indicator" value="Comercial">
                `;
            }
        }

        // ---------- COMPOSIÇÃO DA LINHA (vários artigos da tabela de preços) ----------
        let compLinha = null;      // <tr> do Detalhe Interno que está a ser composta
        let compItens = [];        // [{id, ref, desc, un, q, pc, t, pv}]
        function lerComposicao(row) {
            const h = row ? row.querySelector('.comp-json') : null;
            if (!h || !h.value) return [];
            try { const a = JSON.parse(h.value); return Array.isArray(a) ? a : []; } catch (e) { return []; }
        }
        function marcarComposicao(row) {
            if (!row) return;
            const n = lerComposicao(row).length, b = row.querySelector('.btn-comp');
            row.classList.toggle('com-comp', n > 0);
            if (b) { b.querySelector('.comp-n').textContent = n > 0 ? n : ''; b.title = n > 0 ? n + ' artigo(s) da tabela nesta linha — clique para ver / alterar' : 'Vários artigos da tabela de preços nesta linha'; }
        }
        const numPT = v => formatNumberToPT(v);
        function abrirComposicao(btn) {
            compLinha = btn.closest('tr');
            compItens = lerComposicao(compLinha).map(c => Object.assign({}, c));
            corrigirComposicaoAntiga(compItens);
            // Linha já ligada a um artigo (preço escolhido antes) e sem composição: esse artigo entra como 1.º
            if (!compItens.length) {
                const idA = parseInt((compLinha.querySelector('.id-art-tab') || {}).value || '0', 10);
                const a = idA ? tabelaPrecosBD.find(x => parseInt(x.id, 10) === idA) : null;
                if (a) compItens.push(itemDaTabela(a));
            }
            const desig = (compLinha.querySelector('.desig-input') || {}).value || '';
            const un = ((compLinha.querySelector('.unidade-input') || {}).value || 'un').trim() || 'un';
            document.getElementById('compLinha').textContent = (desig ? desig.substring(0, 140) : 'Linha sem descrição') + ' · Qtd. da linha: ' + (((compLinha.querySelector('.qty') || {}).value) || '—') + ' ' + un;
            document.querySelectorAll('.comp-un-linha').forEach(s => s.textContent = un);
            document.getElementById('compPesquisa').value = '';
            desenharComposicao();
            document.getElementById('modalComp').classList.add('show');
            setTimeout(() => document.getElementById('compPesquisa').focus(), 80);
        }
        // Composições gravadas antes da correção de 16/11/2026: o "." das casas decimais desaparecia ao gravar
        // (214,40 → 21440). Se o valor gravado for o da tabela de preços "sem o ponto", volta ao valor da tabela.
        function corrigirComposicaoAntiga(lista) {
            let corrigidos = 0;
            const semPonto = v => { const n = parseFloat(v); return isNaN(n) ? NaN : parseFloat(String(n).replace('.', '')); };
            lista.forEach(c => {
                const a = c.id ? tabelaPrecosBD.find(x => parseInt(x.id, 10) === parseInt(c.id, 10)) : null;
                if (!a) return;
                const t = itemDaTabela(a);
                ['pc', 't', 'pv'].forEach(k => {
                    const certo = t[k], gravado = parseFloat(c[k]);
                    if (certo && Math.abs(certo % 1) > 0 && gravado !== certo && Math.abs(gravado - semPonto(certo)) < 0.0001) { c[k] = certo; corrigidos++; }
                });
            });
            const av = document.getElementById('compAviso');
            if (av) av.style.display = corrigidos ? 'block' : 'none';
            return corrigidos;
        }
        function fecharComposicao() { document.getElementById('modalComp').classList.remove('show'); compLinha = null; }
        function itemDaTabela(a) {
            const t = (parseFloat(a.tempo_mao_obra) || 0) * (parseInt(a.n_colaboradores, 10) || 1);
            return { id: parseInt(a.id, 10) || 0, ref: a.referencia || '', desc: a.descricao || '', un: a.unidade || '', q: 1,
                     pc: parseFloat(a.preco_custo) || 0, t: t, pv: parseFloat(a.preco_com_mo) || 0 };
        }
        function compSugestoes() {
            const q = document.getElementById('compPesquisa').value, box = document.getElementById('compSug');
            if (q.trim().length < 2) { box.style.display = 'none'; return; }
            const res = tabelaPrecosBD.map(item => ({ item, score: pontuarArtigo(item, q) })).filter(x => x.score > 0).sort((a, b) => b.score - a.score).slice(0, 10);
            if (!res.length) { box.innerHTML = '<div style="color:#94a3b8; cursor:default;">Nenhum artigo encontrado.</div>'; box.style.display = 'block'; return; }
            box.innerHTML = '';
            res.forEach(({ item }) => {
                const d = document.createElement('div');
                d.innerHTML = escapeHTML(rotuloMaterial(item)) + ' <span style="color:#94a3b8;">· ' + numPT(item.preco_custo) + ' € custo · ' + numPT(item.preco_com_mo || 0) + ' € venda</span>';
                d.onmousedown = e => { e.preventDefault(); compItens.push(itemDaTabela(item)); document.getElementById('compPesquisa').value = ''; box.style.display = 'none'; desenharComposicao(true); };
                box.appendChild(d);
            });
            box.style.display = 'block';
        }
        function desenharComposicao(focarUltimo) {
            const corpo = document.getElementById('compCorpo');
            if (!compItens.length) { corpo.innerHTML = '<tr><td colspan="9" class="comp-vazio">Procura acima os artigos da tabela de preços que fazem esta linha.</td></tr>'; totaisComposicao(); return; }
            corpo.innerHTML = compItens.map((c, i) =>
                '<tr data-i="' + i + '">' +
                '<td data-l="Artigo"><b>' + escapeHTML(c.ref) + '</b> ' + escapeHTML(c.desc) + (fichasArtigos[c.id] ? ' <a href="' + escapeHTML(fichasArtigos[c.id].u) + '" target="_blank" title="Ficha técnica" style="color:#c0392b;"><i class="fa-solid fa-file-pdf"></i></a>' : '') + '</td>' +
                '<td data-l="Un.">' + escapeHTML(c.un) + '</td>' +
                '<td class="c-num" data-l="Qtd. p/ 1 un. da linha"><input type="text" inputmode="decimal" value="' + numPT(c.q) + '" oninput="forceComma(this); compMudou(' + i + ', \'q\', this.value)"></td>' +
                '<td class="c-num" data-l="P. Custo"><input type="text" inputmode="decimal" value="' + numPT(c.pc) + '" oninput="forceComma(this); compMudou(' + i + ', \'pc\', this.value)"></td>' +
                '<td class="c-num" data-l="Tempo (min)"><input type="text" inputmode="decimal" value="' + numPT(c.t) + '" oninput="forceComma(this); compMudou(' + i + ', \'t\', this.value)"></td>' +
                '<td class="c-num" data-l="P. Venda"><input type="text" inputmode="decimal" value="' + numPT(c.pv) + '" oninput="forceComma(this); compMudou(' + i + ', \'pv\', this.value)"></td>' +
                '<td class="c-tot" data-l="Custo" id="compC' + i + '"></td><td class="c-tot" data-l="Venda" id="compV' + i + '"></td>' +
                '<td><button type="button" class="btn-row-action btn-del-row" onclick="compItens.splice(' + i + ', 1); desenharComposicao();" title="Retirar"><i class="fa-solid fa-trash-can"></i></button></td></tr>'
            ).join('');
            totaisComposicao();
            if (focarUltimo) { const ins = corpo.querySelectorAll('tr:last-child input'); if (ins[0]) { ins[0].focus(); ins[0].select(); } }
        }
        function compMudou(i, campo, v) { if (compItens[i]) { compItens[i][campo] = strToNum(v); totaisComposicao(); } }
        function totaisComposicao() {
            let tc = 0, tv = 0, tt = 0;
            compItens.forEach((c, i) => {
                const vc = (c.q || 0) * (c.pc || 0), vv = (c.q || 0) * (c.pv || 0);
                tc += vc; tv += vv; tt += (c.q || 0) * (c.t || 0);
                const ec = document.getElementById('compC' + i), ev = document.getElementById('compV' + i);
                if (ec) ec.textContent = numPT(vc) + ' €'; if (ev) ev.textContent = numPT(vv) + ' €';
            });
            document.getElementById('compTotC').textContent = numPT(tc) + ' €';
            document.getElementById('compTotV').textContent = numPT(tv) + ' €';
            document.getElementById('compTotT').textContent = numPT(tt);
            return { tc, tv, tt };
        }
        function aplicarComposicao() {
            if (!compLinha) return;
            const itens = compItens.filter(c => (c.q || 0) > 0);
            if (!itens.length) { if (confirm('Não há artigos com quantidade. Retirar a composição desta linha?')) limparComposicao(); return; }
            const { tc, tv, tt } = totaisComposicao();
            const r4 = v => Math.round(v * 10000) / 10000;
            compLinha.querySelector('.comp-json').value = JSON.stringify(itens.map(c => ({ id: c.id, ref: c.ref, desc: c.desc, un: c.un, q: r4(c.q), pc: r4(c.pc), t: r4(c.t), pv: r4(c.pv) })));
            const pc = compLinha.querySelector('.p_custo'), pv = compLinha.querySelector('.pv'), tmo = compLinha.querySelector('.t_mo');
            if (pc) pc.value = numPT(tc);
            if (tmo) tmo.value = tt > 0 ? numPT(tt) : '';
            if (pv) pv.value = numPT(tv);
            // Com vários artigos, a linha deixa de estar ligada a um só (a marca / ficha vêm de cada artigo)
            const idA = compLinha.querySelector('.id-art-tab'); if (idA) idA.value = itens.length === 1 ? (itens[0].id || '') : '';
            const qty = compLinha.querySelector('.qty'); if (qty && !qty.value.trim()) qty.value = '1,00';
            marcarFicha(compLinha);
            calcular(pc || pv);
            fecharComposicao();
        }
        function limparComposicao() {
            if (!compLinha) return;
            compLinha.querySelector('.comp-json').value = '';
            marcarComposicao(compLinha);
            fecharComposicao();
        }

        // Ícone da ficha técnica na linha (artigo da tabela de preços com ficha na biblioteca)
        const fichasArtigos = <?= json_encode((object)$fichas_artigos_js, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS) ?>;
        function marcarFicha(row) {
            if (!row) return;
            const ico = row.querySelector('.ficha-ico'), idInp = row.querySelector('.id-art-tab');
            if (!ico || !idInp) return;
            const f = fichasArtigos[parseInt(idInp.value || '0', 10)];
            if (f) { ico.href = f.u; ico.title = 'Ficha técnica: ' + f.n; ico.style.display = ''; }
            else { ico.style.display = 'none'; }
            row.classList.toggle('com-ficha', !!f);
            marcarComposicao(row);
        }

        function addRow(tipo, data = {}) {
            const tbody = document.querySelector(tipo === 'interno' ? '#tabelaItensInterno tbody' : '#tabelaItensComercial tbody');
            const row = tbody.insertRow(); row.innerHTML = getRowHTML(tipo, data);
            marcarFicha(row);
            const ta = row.querySelector('.desig-input');
            if(ta && data.designacao) { autoAjustarTextarea(ta); }
            if(data.quantidade) calcular(row.querySelector('.qty'));
        }

        // ---------- Numeração automática do Ref/Artigo ----------
        // Separa "2.08" em prefixo "2.", número 8 (com 2 algarismos) e sufixo ""; null se não tiver número
        function partirArtigo(v) {
            const m = String(v || '').trim().match(/^(.*?)(\d+)(\D*)$/);
            return m ? { prefixo: m[1], numero: parseInt(m[2], 10), sufixo: m[3], largura: m[2].length } : null;
        }
        function montarArtigo(p, n) { return p.prefixo + String(n).padStart(p.largura, '0') + p.sufixo; }
        // Número seguinte ao da linha indicada (ou da linha numerada mais próxima acima); '' se não houver
        function artigoSeguinte(row) {
            let r = row;
            while (r) {
                const inp = r.querySelector('.artigo-input');
                const p = inp ? partirArtigo(inp.value) : null;
                if (p) return montarArtigo(p, p.numero + 1);
                r = r.previousElementSibling;
            }
            return '';
        }
        function definirArtigo(row, valor) {
            const inp = row && row.querySelector('.artigo-input');
            if (inp && valor) inp.value = valor.slice(0, parseInt(inp.getAttribute('maxlength') || '12', 10));
        }

        // "+" numa linha: nova linha logo a seguir, com o número seguinte
        function insertRowAfter(tipo, btn) {
            const currentRow = btn.closest('tr'); const tbody = currentRow.parentNode;
            const newRow = tbody.insertRow(currentRow.rowIndex); newRow.innerHTML = getRowHTML(tipo);
            definirArtigo(newRow, artigoSeguinte(currentRow));
        }
        // Botão "Adicionar Linha" no fim da tabela: com o número seguinte ao da última linha numerada
        function addRowNumerada(tipo) {
            const tbody = document.querySelector(tipo === 'interno' ? '#tabelaItensInterno tbody' : '#tabelaItensComercial tbody');
            const ultima = tbody.lastElementChild;
            const valor = ultima ? artigoSeguinte(ultima) : '';
            addRow(tipo);
            definirArtigo(tbody.lastElementChild, valor);
        }

        function limparTudoCompleto() {
            if (confirm("Deseja esvaziar ambas as tabelas (Interna e Comercial)?")) {
                document.querySelector('#tabelaItensInterno tbody').innerHTML = '';
                document.querySelector('#tabelaItensComercial tbody').innerHTML = '';
                addRow('interno'); addRow('comercial'); calcularTudo();
            }
        }

        function addListRow(containerId, inputName, val = '') {
            const div = document.createElement('div'); div.className = 'list-row';
            div.innerHTML = `
                <div style="width:30px; display:flex; align-items:flex-start; justify-content:center; padding-top:6px;"><input type="checkbox" class="chk-select-list"></div>
                <textarea name="${inputName}[]" oninput="autoAjustarTextarea(this)">${escapeHTML(val)}</textarea>
                <div style="display:flex; gap:2px; padding-top:4px;"><button type="button" class="btn-row-action btn-del-row" onclick="this.closest('.list-row').remove()"><i class="fa-solid fa-trash-can"></i></button></div>
            `;
            document.getElementById(containerId).appendChild(div);
            const ta = div.querySelector('textarea');
            if(ta) { autoAjustarTextarea(ta); }
        }

        function calcular(el) {
            const row = el.closest('tr');
            if (!row) return;
            const pcInput = row.querySelector('.p_custo'); const pvInput = row.querySelector('.pv');
            const vCustoInput = row.querySelector('.v_custo'); const totalVInput = row.querySelector('.total_v');
            const unInput = row.querySelector('.unidade-input'); const qInput = row.querySelector('.qty');
            if (!qInput || !pvInput || !totalVInput) return;

            const indicator = row.querySelector('.tipo-linha-indicator');
            const isComercial = indicator && indicator.value === 'Comercial';

            // Regra 1: Cabeçalho do Comercial sem "0,00"
            if (isComercial && (!unInput || unInput.value.trim() === '') && (!qInput || qInput.value.trim() === '')) {
                pvInput.value = '';
                totalVInput.value = '';
                calcularTotalMaoDeObra();
                calcularTudo();
                return;
            }

            // Regra 2: Cabeçalho do Detalhe Interno sem "0,00"
            if (!isComercial &&
                (!unInput || unInput.value.trim() === '') &&
                (!qInput || qInput.value.trim() === '') &&
                (!pcInput || pcInput.value.trim() === '')) {
                const tMoInput = row.querySelector('.t_mo');
                if (tMoInput) tMoInput.value = '';
                if (vCustoInput) vCustoInput.value = '';
                pvInput.value = '';
                totalVInput.value = '';
                calcularTotalMaoDeObra();
                calcularTudo();
                return;
            }

            const pv = strToNum(pvInput.value); const q = strToNum(qInput.value);

            if (pcInput && vCustoInput) {
                const pc = strToNum(pcInput.value);
                vCustoInput.value = formatNumberToPT(q * pc);
                if (q > 0) {
                    pcInput.classList.toggle('bg-pc-filled', pc > 0);
                    pcInput.classList.toggle('bg-pc-empty', pc === 0);
                }
                pvInput.classList.remove('bg-profit', 'bg-equal', 'bg-missing');
                if(q > 0 && pv > pc) pvInput.classList.add('bg-profit');
                else if(q > 0 && pv === pc && pv > 0) pvInput.classList.add('bg-equal');
            }
            totalVInput.value = formatNumberToPT(q * pv);

            calcularTotalMaoDeObra();
            calcularTudo();
        }

        function syncVenda(el) {
            // Sincroniza automaticamente custo para venda se não houver venda
            const row = el.closest('tr');
            if (row) {
                const pvInput = row.querySelector('.pv');
                if (pvInput && pvInput.value.trim() === '') {
                    pvInput.value = el.value;
                }
            }
        }

        function formatHorasJS(horasDec) {
            horasDec = parseFloat(horasDec) || 0;
            let h = Math.floor(horasDec);
            let m = Math.round((horasDec - h) * 60);
            if (m === 60) { h++; m = 0; }
            return h + 'h ' + String(m).padStart(2, '0') + 'm';
        }

        function calcularTotalMaoDeObra() {
            let sMoMinutos = 0;
            document.querySelectorAll('#tabelaItensInterno tbody tr').forEach(r => {
                let qInput = r.querySelector('.qty'); let tMoInput = r.querySelector('.t_mo');
                if(!qInput || !tMoInput) return;
                let qty = strToNum(qInput.value);
                let tMo = strToNum(tMoInput.value);
                if (qty > 0 && tMo > 0) { sMoMinutos += (qty * tMo); }
            });
            let totalValorMo = (sMoMinutos / 60) * VALOR_HORA_HOMEM;
            document.getElementById('valor_mao_obra').value = formatNumberToPT(totalValorMo);
            document.getElementById('display_mao_obra_tempo').innerText = formatHorasJS(sMoMinutos / 60);
        }

        function calcularTudo() {
            let sC = 0, sV_interno = 0;
            document.querySelectorAll('#tabelaItensInterno tbody tr').forEach(r => {
                let qInput = r.querySelector('.qty'); let pcInput = r.querySelector('.p_custo'); let pvInput = r.querySelector('.pv');
                if(!qInput || !pcInput || !pvInput) return;
                sC += strToNum(qInput.value) * strToNum(pcInput.value);
                sV_interno += strToNum(qInput.value) * strToNum(pvInput.value);
            });

            let sV_comercial = 0; let temComercial = false;
            document.querySelectorAll('#tabelaItensComercial tbody tr').forEach(r => {
                let desigInput = r.querySelector('.desig-input'); let qInput = r.querySelector('.qty'); let pvInput = r.querySelector('.pv');
                if(!desigInput || !qInput || !pvInput) return;
                let desig = desigInput.value.trim();
                if (desig !== '') {
                    temComercial = true;
                    sV_comercial += strToNum(qInput.value) * strToNum(pvInput.value);
                }
            });

            let sV = temComercial ? sV_comercial : sV_interno;

            const tI = strToNum(document.getElementById('taxa_iva').value);
            const vI = sV * (tI/100);

            document.getElementById('res_total_custo_final').innerText = formatNumberToPT(sC) + " €";
            document.getElementById('display_subtotal_venda_topo').innerText = formatNumberToPT(sV) + " €";

            document.getElementById('res_total_venda').innerText = formatNumberToPT(sV) + " €";
            document.getElementById('res_valor_iva').innerText = formatNumberToPT(vI) + " €";
            document.getElementById('res_total_com_iva').innerText = formatNumberToPT(sV + vI) + " €";
            document.getElementById('label_iva').innerText = `IVA (${tI}%):`;

            let elInterno = document.getElementById('floating_subtotal_interno');
            if(elInterno) elInterno.innerText = formatNumberToPT(sV_interno) + " €";

            let elComercial = document.getElementById('floating_subtotal_comercial');
            if(elComercial) elComercial.innerText = formatNumberToPT(sV_comercial) + " €";
        }

        // Separador ativo do orçamento: o fator aplica-se só às linhas desse separador
        function separadorOrcamentoAtivo() {
            const c = document.getElementById('tab_comercial');
            return (c && c.classList.contains('active')) ? 'comercial' : 'interno';
        }
        function atualizarDestinoFator() {
            const sep = separadorOrcamentoAtivo();
            const lbl = document.getElementById('fator_destino');
            if (lbl) lbl.textContent = sep === 'comercial' ? '· Resumo Comercial' : '· Detalhe Interno';
            const bc = document.getElementById('btn_fator_custo');
            if (bc) {
                bc.disabled = (sep === 'comercial');            // no Resumo Comercial não há preço de custo
                bc.style.opacity = bc.disabled ? 0.45 : 1;
                bc.title = bc.disabled ? 'No Resumo Comercial não há preço de custo — use PVP' : 'Aplicar fator sobre Preço Custo';
            }
        }

        function aplicarFator(t) {
            const f = strToNum(document.getElementById('fator_custo').value); if (f <= 0) return;
            const sep = separadorOrcamentoAtivo();
            if (sep === 'comercial' && t === 'custo') { alert('No Resumo Comercial não há preço de custo. Use o botão PVP.'); return; }
            const tabela = sep === 'comercial' ? '#tabelaItensComercial' : '#tabelaItensInterno';
            document.querySelectorAll(tabela + ' tbody tr').forEach(r => {
                let mbInput = r.querySelector('.margem-bloqueada-input');
                if (mbInput && mbInput.value === '1') return;

                let pcInput = r.querySelector('.p_custo'); let pvInput = r.querySelector('.pv');
                if(!pcInput || !pvInput) return;

                const pc = strToNum(pcInput.value);
                const pv = strToNum(pvInput.value);
                if (t === 'custo' && pc > 0) pvInput.value = formatNumberToPT(pc * f);
                else if (t === 'venda' && pv > 0) pvInput.value = formatNumberToPT(pv * f);
                calcular(pvInput);
            });
        }

        // ===================== ARREDONDAR PREÇO DE VENDA (0 / 5) =====================
        // 2ª casa decimal arredondada para cima: 0 e 5 ficam; 1-4 → 5; 6-9 → 0 e sobe a 1ª casa.
        // Ex: 12,31 → 12,35 · 12,36 → 12,40 · 12,99 → 13,00
        function arredondar05(valor) {
            const cent = Math.round(valor * 100);        // trabalhar em cêntimos evita erros de vírgula flutuante
            return Math.ceil(cent / 5) * 5 / 100;
        }

        function linhasPrecoVenda() {
            const linhas = [];
            document.querySelectorAll('#tabelaItensInterno tbody tr, #tabelaItensComercial tbody tr').forEach(row => {
                const pv = row.querySelector('.pv');
                if (!pv || pv.value.trim() === '') return;                       // títulos e linhas sem preço
                const mb = row.querySelector('.margem-bloqueada-input');
                if (mb && mb.value === '1') return;                               // margem bloqueada (Tabela de Preços)
                linhas.push(pv);
            });
            return linhas;
        }

        function arredondarPrecosVenda() {
            let alterados = 0;
            linhasPrecoVenda().forEach(pv => {
                const atual = strToNum(pv.value);
                if (atual <= 0) return;
                const novo = arredondar05(atual);
                // Guarda o valor original só na 1ª vez (arredondar de novo não perde o original)
                if (pv.dataset.pvOriginal === undefined) pv.dataset.pvOriginal = pv.value;
                if (novo !== atual) alterados++;
                pv.value = formatNumberToPT(novo);
                pv.dataset.pvArredondado = pv.value;
                pv.classList.add('pv-arredondado');
                calcular(pv);
            });
            atualizarBotaoRepor();
            alert(alterados > 0 ? alterados + ' preço(s) de venda arredondado(s).' : 'Os preços de venda já estavam arredondados a 0 ou 5.');
        }

        function reporPrecosVenda() {
            let repostos = 0, ignorados = 0;
            document.querySelectorAll('.pv').forEach(pv => {
                if (pv.dataset.pvOriginal === undefined) return;
                // Se o preço foi mexido à mão depois de arredondar, mantém o valor escrito
                if (pv.value === pv.dataset.pvArredondado) {
                    if (pv.value !== pv.dataset.pvOriginal) repostos++;
                    pv.value = pv.dataset.pvOriginal;
                } else {
                    ignorados++;
                }
                delete pv.dataset.pvOriginal;
                delete pv.dataset.pvArredondado;
                pv.classList.remove('pv-arredondado');
                calcular(pv);
            });
            atualizarBotaoRepor();
            alert(repostos + ' preço(s) de venda reposto(s).' + (ignorados ? '\n' + ignorados + ' alterado(s) à mão depois do arredondamento ficaram como estão.' : ''));
        }

        function atualizarBotaoRepor() {
            const btn = document.getElementById('btn_repor_pv');
            if (btn) btn.disabled = !document.querySelector('.pv[data-pv-original]');
        }

        function prepararEnvio() {
            let items = [];
            document.querySelectorAll('#tabelaItensInterno tbody tr').forEach(row => {
                let desigInput = row.querySelector('.desig-input');
                let artInput = row.querySelector('.artigo-input');
                if (!desigInput || !artInput) return;

                let desig = desigInput.value;
                if(trim(desig) !== '' || trim(artInput.value) !== '') {
                    items.push({
                        tipo_linha: 'Interno',
                        artigo: artInput.value,
                        designacao: desig,
                        unidade: (row.querySelector('.unidade-input') || {}).value || '',
                        quantidade: (row.querySelector('.qty') || {}).value || '',
                        preco_unitario_custo: (row.querySelector('.p_custo') || {}).value || '0',
                        tempo_mao_obra: (row.querySelector('.t_mo') || {}).value || '0',
                        preco_unitario_venda: (row.querySelector('.pv') || {}).value || '0',
                        margem_bloqueada: (row.querySelector('.margem-bloqueada-input') || {}).value || '0',
                        id_artigo_tabela: (row.querySelector('.id-art-tab') || {}).value || '',
                        composicao_json: (row.querySelector('.comp-json') || {}).value || ''
                    });
                }
            });
            document.querySelectorAll('#tabelaItensComercial tbody tr').forEach(row => {
                let desigInput = row.querySelector('.desig-input');
                let artInput = row.querySelector('.artigo-input');
                if (!desigInput || !artInput) return;

                let desig = desigInput.value;
                if(trim(desig) !== '' || trim(artInput.value) !== '') {
                    items.push({
                        tipo_linha: 'Comercial',
                        artigo: artInput.value,
                        designacao: desig,
                        unidade: (row.querySelector('.unidade-input') || {}).value || '',
                        quantidade: (row.querySelector('.qty') || {}).value || '',
                        preco_unitario_custo: '0',
                        tempo_mao_obra: '0',
                        preco_unitario_venda: (row.querySelector('.pv') || {}).value || '0',
                        margem_bloqueada: '0'
                    });
                }
            });
            document.getElementById('itens_json').value = JSON.stringify(items);
        }

        // Imprimir o orçamento com os valores que estão no ecrã (sem gravar): envia tudo para o orcamento_pdf.php
        function imprimirDoEcra() {
            prepararEnvio();
            const tipo = document.getElementById('prev_tipo').value;
            const idOrc = <?= (int)($id ?? 0) ?>;
            const f = document.createElement('form');
            f.method = 'POST';
            f.action = 'orcamento_pdf.php?tipo=' + encodeURIComponent(tipo) + (idOrc ? '&id=' + idOrc : '');
            f.target = '_blank';
            f.style.display = 'none';
            const junta = (nome, valor) => { const i = document.createElement('input'); i.type = 'hidden'; i.name = nome; i.value = valor; f.appendChild(i); };
            const fd = new FormData(document.getElementById('orcForm'));
            for (const [k, v] of fd.entries()) {
                if (typeof v !== 'string') continue;                       // ficheiros não interessam
                if (k === 'itens_json') junta('preview_itens_json', v);
                else if (!/pass/i.test(k)) junta(k, v);                     // nunca enviar passwords
            }
            document.body.appendChild(f);
            f.submit();
            setTimeout(() => f.remove(), 1000);
        }

        function trim(str) { return str ? str.toString().trim() : ''; }
        function strToNum(s) { if (!s) return 0; return parseFloat(s.toString().replace(/\./g, '').replace(/\s/g, '').replace(',', '.')) || 0; }
        function formatNumberToPT(num) { if (num === null || num === undefined) return ''; return parseFloat(num).toFixed(2).replace('.', ','); }

        function formatField(el) {
            if(el.value !== "") {
                let val = strToNum(el.value);

                if (el.classList.contains('pv')) {
                    const row = el.closest('tr');
                    if (row) {
                        const indicator = row.querySelector('.tipo-linha-indicator');
                        const isComercial = indicator && indicator.value === 'Comercial';
                        const unInput = row.querySelector('.unidade-input');
                        const qInput = row.querySelector('.qty');
                        if (isComercial && (!unInput || unInput.value.trim() === '') && (!qInput || qInput.value.trim() === '')) {
                            el.value = "";
                            return;
                        }
                    }
                }

                if (val === 0 && el.classList.contains('qty')) {
                    el.value = "";
                } else {
                    el.value = formatNumberToPT(val);
                }
            }
        }

        const orcForm = document.getElementById('orcForm');
        orcForm.addEventListener('submit', function(e) {
            const estadoOrig = document.getElementById('estado_original').value;
            const estadoNovo = document.getElementById('estado_select').value;
            const passHidden = document.getElementById('pass_seguranca_hidden').value;

            if (isModoEditar && estadoOrig === 'Adjudicado' && estadoNovo !== 'Adjudicado') {
                if (!passHidden) { e.preventDefault(); document.getElementById('modalDesadjudicar').classList.add('show'); return; }
            }

            let hasPrecoInferior = false;
            document.querySelectorAll('#tabelaItensInterno tbody tr').forEach(row => {
                let pcInput = row.querySelector('.p_custo'); let pvInput = row.querySelector('.pv'); let qInput = row.querySelector('.qty');
                if(!pcInput || !pvInput || !qInput) return;
                let pc = strToNum(pcInput.value); let pv = strToNum(pvInput.value); let q = strToNum(qInput.value);
                if (q > 0 && pv < pc) { hasPrecoInferior = true; }
            });

            if (hasPrecoInferior && !passHidden) { e.preventDefault(); document.getElementById('modalPrecoInferior').classList.add('show'); return; }
            if (!isRedirectChosen) { e.preventDefault(); document.getElementById('modalRedirect').classList.add('show'); return; }
            prepararEnvio();
        });

        function fecharModalDes() { document.getElementById('modalDesadjudicar').classList.remove('show'); document.getElementById('modal_pass').value = ''; }
        function confirmarDesadjudicar() { const pass = document.getElementById('modal_pass').value; if (pass.trim() === '') { alert("Introduza a password de segurança."); return; } document.getElementById('pass_seguranca_hidden').value = pass; fecharModalDes(); orcForm.requestSubmit(); }

        function fecharModalPreco() { document.getElementById('modalPrecoInferior').classList.remove('show'); document.getElementById('modal_pass_preco').value = ''; }
        function confirmarPrecoInferior() { const pass = document.getElementById('modal_pass_preco').value; if (pass.trim() === '') { alert("Introduza a password de segurança."); return; } document.getElementById('pass_seguranca_hidden').value = pass; fecharModalPreco(); orcForm.requestSubmit(); }

        function fecharModalRedirect() { document.getElementById('modalRedirect').classList.remove('show'); }
        function confirmarRedirect(escolha) {
            document.getElementById('redirect_action').value = escolha; isRedirectChosen = true; fecharModalRedirect(); prepararEnvio();
            sessionStorage.setItem('orcamentoScrollY', window.scrollY);
            // Lembrar o separador aberto (Detalhe Interno / Resumo Comercial) para voltar ao mesmo sítio depois de gravar
            const tabAtivo = document.querySelector('.budget-tab-content.active');
            if (escolha === 'continuar') sessionStorage.setItem('orcamentoTab', tabAtivo && tabAtivo.id === 'tab_comercial' ? 'comercial' : 'interno');
            else sessionStorage.removeItem('orcamentoTab');
            orcForm.submit();
        }

        function updateValidade() {
            const dataEm = new Date(document.getElementById('data_emissao').value);
            const prazo = parseInt(document.getElementById('prazo_select').value);
            if (!isNaN(dataEm.getTime())) { dataEm.setDate(dataEm.getDate() + prazo); document.getElementById('data_validade_final').value = dataEm.toISOString().split('T')[0]; }
        }

        window.onclick = function(e) { if (e.target.classList.contains('modal')) { e.target.classList.remove('show'); } }

        document.addEventListener('DOMContentLoaded', () => {
            const itensInternoDB = <?= safe_json_encode($itens_interno_js) ?>;
            if (itensInternoDB && itensInternoDB.length > 0) {
                itensInternoDB.forEach(it => addRow('interno', it));
            } else {
                addRow('interno');
            }

            const itensComercialDB = <?= safe_json_encode($itens_comercial_js) ?>;
            if (itensComercialDB && itensComercialDB.length > 0) {
                itensComercialDB.forEach(it => addRow('comercial', it));
            } else {
                addRow('comercial');
            }

            const obsArray = <?= safe_json_encode($obs_finais) ?>;
            if (obsArray && obsArray.length > 0) { obsArray.forEach(v => addListRow('obsContainer', 'obs_linha', v)); } else { addListRow('obsContainer', 'obs_linha'); }

            const incluiArray = <?= safe_json_encode($inclui_finais) ?>;
            if (incluiArray && incluiArray.length > 0) { incluiArray.forEach(v => addListRow('incluiContainer', 'inclui_linha', v)); } else { addListRow('incluiContainer', 'inclui_linha'); }

            const naoIncluiArray = <?= safe_json_encode($nao_inclui_finais) ?>;
            if (naoIncluiArray && naoIncluiArray.length > 0) { naoIncluiArray.forEach(v => addListRow('naoIncluiContainer', 'nao_inclui_linha', v)); } else { addListRow('naoIncluiContainer', 'nao_inclui_linha'); }

            document.querySelectorAll('.desig-input, .list-row textarea').forEach(ta => {
                autoAjustarTextarea(ta);
            });

            calcularTudo();

            // Vindo do botão "Criar Orçamento" da vistoria: abre logo a importação
            if (importVistoriaAuto && getVistoria(importVistoriaAuto)) {
                abrirModalVistoria(importVistoriaAuto);
            }

            // Menu do utilizador: em ecrãs táteis abre com toque
            const menuU = document.querySelector('.user-menu');
            const btnU = menuU ? menuU.querySelector('.user-btn') : null;
            if (btnU) {
                btnU.addEventListener('click', function (e) { e.stopPropagation(); menuU.classList.toggle('aberto'); });
                document.addEventListener('click', function () { menuU.classList.remove('aberto'); });
            }

            // Depois de gravar: reabrir o mesmo separador e voltar à mesma posição da página
            const tabSalvo = sessionStorage.getItem('orcamentoTab');
            if (tabSalvo === 'comercial') {
                const btnCom = document.querySelectorAll('.tab-btn')[1];
                if (btnCom) switchBudgetTab('comercial', btnCom);
            }
            sessionStorage.removeItem('orcamentoTab');
            const scrollSalvo = sessionStorage.getItem('orcamentoScrollY');
            if (scrollSalvo !== null) {
                sessionStorage.removeItem('orcamentoScrollY');
                const y = parseInt(scrollSalvo, 10);
                window.scrollTo(0, y);
                setTimeout(() => window.scrollTo(0, y), 150);   // de novo, depois de as caixas de texto acertarem a altura
            }
        });
    </script>
    <!-- MAPA: DISTÂNCIA SEDE → OBRA -->
    <div class="modal-km" id="modalKm" onclick="if (event.target === this) fecharMapaKm()">
        <div class="modal-km-box">
            <div class="modal-km-topo">
                <h3><i class="fa-solid fa-route"></i> Distância sede → obra (ida e volta)</h3>
                <button type="button" class="modal-km-x" onclick="fecharMapaKm()" title="Fechar">&times;</button>
            </div>
            <div class="km-morada"><i class="fa-solid fa-location-dot"></i> <span id="kmMorada"></span></div>
            <div class="km-aviso" id="kmAviso"></div>
            <div id="kmMapa"></div>
            <div class="km-estado" id="kmEstado"></div>
            <div id="kmOpcoes"></div>
            <div class="modal-km-botoes">
                <a id="kmGoogle" href="#" target="_blank" class="km-btn-sec"><i class="fa-solid fa-map"></i> Comparar no Google Maps</a>
                <button type="button" class="km-btn-sec" onclick="fecharMapaKm()">Cancelar</button>
                <button type="button" class="km-btn-ok" onclick="aplicarKmMapa()"><i class="fa-solid fa-check"></i> Usar estes km</button>
            </div>
        </div>
    </div>
</body>
</html>