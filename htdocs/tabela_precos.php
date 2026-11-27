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

if (!function_exists('gerar_csrf_token')) {
    function gerar_csrf_token() { if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); return $_SESSION['csrf_token']; }
    function validar_csrf_token($t) { return !empty($_SESSION['csrf_token']) && !empty($t) && hash_equals($_SESSION['csrf_token'], (string)$t); }
}
// REGRA DE OURO 7: todos os pedidos POST (formulários e AJAX) trazem o csrf_token
$csrf_ok = ($_SERVER['REQUEST_METHOD'] !== 'POST') || validar_csrf_token($_POST['csrf_token'] ?? '');
$csrf_msg = 'A sessão expirou ou o pedido não é válido. Atualize a página (F5) e tente de novo.';

// Nunca perder dados: cópia do artigo antes de o alterar, apagar ou de aplicar a margem global.
// (CREATE TABLE fora de transações: no MySQL faz commit implícito.)
function tpCriarArquivo($pdo) {
    $pdo->exec("CREATE TABLE IF NOT EXISTS tabela_precos_arquivo (
        id INT AUTO_INCREMENT PRIMARY KEY,
        id_artigo INT NOT NULL,
        acao VARCHAR(20) NOT NULL,
        dados_json LONGTEXT NOT NULL,
        arquivado_por INT NULL,
        arquivado_em DATETIME NOT NULL,
        INDEX (id_artigo)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}
function tpArquivar($pdo, array $linhas, $acao) {
    if (!$linhas) return;
    $st = $pdo->prepare("INSERT INTO tabela_precos_arquivo (id_artigo, acao, dados_json, arquivado_por, arquivado_em) VALUES (?, ?, ?, ?, NOW())");
    foreach ($linhas as $l) $st->execute([(int)$l['id'], $acao, json_encode($l, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE), (int)($_SESSION['user_id'] ?? 0)]);
}

// Biblioteca de fichas técnicas (fichas_tecnicas.php): marca e ficha técnica de cada artigo
$tem_fichas = file_exists('fichas_tecnicas_lib.php');
if ($tem_fichas) { require_once 'fichas_tecnicas_lib.php'; ft_migrar($pdo); }

$perfil_user = $_SESSION['perfil'] ?? '';
$is_admin = in_array($perfil_user, ['admin', 'administrativo']);

// REGRA DE OURO 2: Acesso Restrito pela Matriz
$stmt_perm = $pdo->prepare("SELECT modulo FROM permissoes WHERE grupo = :perfil AND pode_aceder = 1");
$stmt_perm->execute(['perfil' => $perfil_user]);
$meus_acessos = $stmt_perm->fetchAll(PDO::FETCH_COLUMN);

// Acesso permitido a quem gere orçamentos ou configurações
if (!$is_admin && !in_array('orcamentos', $meus_acessos) && !in_array('configuracoes', $meus_acessos)) {
    header("Location: index.php?erro=sem_permissao");
    exit;
}

// REGRA DE OURO 3: Obter apenas o primeiro nome para o Menu
$nome_completo = $_SESSION['nome'] ?? 'Utilizador';
$primeiro_nome = explode(' ', trim($nome_completo))[0];

// Função Pro para Limpeza Numérica
function limparNumero($num) {
    if (empty(trim((string)$num))) return 0;
    $num = str_replace(['.', ' '], '', (string)$num);
    $num = str_replace(',', '.', $num);
    return floatval($num);
}

// Obter o Valor Hora/Homem
$stmt_hora = $pdo->prepare("SELECT valor FROM configuracoes WHERE chave = 'valor_hora' LIMIT 1");
$stmt_hora->execute();
$valor_hora_homem_str = $stmt_hora->fetchColumn();
$valor_hora_homem = $valor_hora_homem_str ? limparNumero($valor_hora_homem_str) : 0;

// =========================================================================
// ARTIGOS COMPOSTOS: um artigo feito de vários artigos da tabela (ex.: ponto de luz completo).
// composicao_json = [{"id": 12, "q": 1.5}, …] (quantidade de cada artigo para 1 unidade do composto).
// O preço é SEMPRE automático: custo, tempo e preço de venda = soma dos componentes × quantidade.
// Recalcula-se sempre que um componente é gravado e depois da margem global.
// Um composto não entra noutro composto; um artigo que faz parte de um composto não se apaga.
// =========================================================================
function tpMigrarCompostos($pdo) {
    try {
        if (!$pdo->query("SHOW COLUMNS FROM tabela_precos LIKE 'composicao_json'")->fetch())
            $pdo->exec("ALTER TABLE tabela_precos ADD COLUMN composicao_json TEXT NULL");
        return true;
    } catch (Exception $e) { error_log('tabela_precos (compostos): ' . $e->getMessage()); return false; }
}
$tem_compostos = tpMigrarCompostos($pdo);

function tpLerComposicao($json) {
    $a = json_decode((string)$json, true);
    return is_array($a) ? $a : [];
}
// Limpa a lista e calcula os totais a partir dos componentes atuais. Lança exceção se algo não for válido.
function tpCalcularComposto($pdo, array $comp, $id_proprio, $valor_hora) {
    $limpa = [];
    foreach ($comp as $c) {
        $id = (int)($c['id'] ?? 0);
        $q = is_numeric($c['q'] ?? null) ? (float)$c['q'] : limparNumero($c['q'] ?? 0);
        if ($id <= 0 || $q <= 0) continue;
        if ($id_proprio && $id === (int)$id_proprio) throw new Exception('Um artigo não pode fazer parte de si próprio.');
        $limpa[] = ['id' => $id, 'q' => round($q, 4)];
    }
    if (!$limpa) return null;
    $ids = array_values(array_unique(array_column($limpa, 'id')));
    $st = $pdo->prepare("SELECT * FROM tabela_precos WHERE id IN (" . implode(',', array_fill(0, count($ids), '?')) . ")");
    $st->execute($ids);
    $arts = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $a) $arts[(int)$a['id']] = $a;
    $pc = 0; $t = 0; $pmat = 0; $pv = 0;
    foreach ($limpa as $c) {
        $a = $arts[$c['id']] ?? null;
        if (!$a) throw new Exception('Um dos artigos da composição já não existe na tabela de preços.');
        if (tpLerComposicao($a['composicao_json'] ?? '')) throw new Exception('O artigo ' . $a['referencia'] . ' também é composto: um composto não pode entrar noutro.');
        $pc_i = (float)$a['preco_custo'];
        $pc   += $c['q'] * $pc_i;
        $pmat += $c['q'] * ($pc_i * (1 + ((float)$a['percentagem_lucro']) / 100));
        $t    += $c['q'] * (float)$a['tempo_mao_obra'] * max(1, (int)$a['n_colaboradores']);
        $pv   += $c['q'] * (float)$a['preco_com_mo'];
    }
    return [
        'composicao' => $limpa,
        'preco_custo' => round($pc, 3),
        'tempo_mao_obra' => round($t, 2),
        'n_colaboradores' => 1,
        'percentagem_lucro' => $pc > 0 ? (int)round(($pmat / $pc - 1) * 100) : 0,
        'preco_material' => round($pmat, 3),
        'valor_mo' => round(($t / 60) * $valor_hora, 3),
        'preco_com_mo' => round($pv, 2),
    ];
}
// Recalcula os compostos (todos, ou só os que usam o artigo $id_componente). Devolve os compostos alterados.
function tpRecalcularCompostos($pdo, $valor_hora, $id_componente = null) {
    $alterados = [];
    $todos = $pdo->query("SELECT * FROM tabela_precos WHERE composicao_json IS NOT NULL AND composicao_json <> '' AND composicao_json <> '[]'")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($todos as $a) {
        $comp = tpLerComposicao($a['composicao_json']);
        if (!$comp) continue;
        if ($id_componente && !in_array((int)$id_componente, array_map('intval', array_column($comp, 'id')), true)) continue;
        try { $r = tpCalcularComposto($pdo, $comp, $a['id'], $valor_hora); } catch (Exception $e) { continue; }
        if (!$r) continue;
        if (abs($r['preco_custo'] - (float)$a['preco_custo']) < 0.0005 && abs($r['preco_com_mo'] - (float)$a['preco_com_mo']) < 0.005
            && abs($r['tempo_mao_obra'] - (float)$a['tempo_mao_obra']) < 0.005) continue;
        tpCriarArquivo($pdo);
        tpArquivar($pdo, [$a], 'recalculado');
        $pdo->prepare("UPDATE tabela_precos SET preco_custo = ?, percentagem_lucro = ?, margem_bloqueada = 1, preco_material = ?, valor_mo = ?, preco_com_mo = ?, tempo_mao_obra = ?, n_colaboradores = 1, data_alteracao = ? WHERE id = ?")
            ->execute([$r['preco_custo'], $r['percentagem_lucro'], $r['preco_material'], $r['valor_mo'], $r['preco_com_mo'], $r['tempo_mao_obra'], date('Y-m-d H:i:s'), $a['id']]);
        $st = $pdo->prepare("SELECT * FROM tabela_precos WHERE id = ?"); $st->execute([$a['id']]);
        $alterados[] = $st->fetch(PDO::FETCH_ASSOC);
    }
    return $alterados;
}
// Compostos que usam um artigo (para não o deixar apagar)
function tpCompostosQueUsam($pdo, $id) {
    $r = [];
    foreach ($pdo->query("SELECT id, referencia, composicao_json FROM tabela_precos WHERE composicao_json IS NOT NULL AND composicao_json <> ''")->fetchAll(PDO::FETCH_ASSOC) as $a)
        if (in_array((int)$id, array_map('intval', array_column(tpLerComposicao($a['composicao_json']), 'id')), true)) $r[] = $a['referencia'];
    return $r;
}

// =========================================================================
// ENDPOINT AJAX: GRAVAR / EDITAR ARTIGO SEM RECARREGAR A PÁGINA
// =========================================================================
if (isset($_GET['ajax_save_artigo'])) {
    ob_start();   // avisos do PHP não podem estragar a resposta JSON
    header('Content-Type: application/json');
    try {
        if (!$csrf_ok) throw new Exception($csrf_msg);
        $id_artigo = filter_input(INPUT_POST, 'id_artigo', FILTER_VALIDATE_INT);
        $referencia = trim($_POST['referencia'] ?? '');
        $descricao = trim($_POST['descricao'] ?? '');
        $unidade = trim($_POST['unidade'] ?? 'UN');

        // Referência única: se já existir noutro artigo, não grava e devolve esse artigo (a página pergunta o que fazer)
        $st_dup = $pdo->prepare("SELECT id, referencia, descricao FROM tabela_precos WHERE LOWER(TRIM(referencia)) = LOWER(?) AND id <> ? LIMIT 1");
        $st_dup->execute([$referencia, (int)$id_artigo]);
        if ($dup = $st_dup->fetch(PDO::FETCH_ASSOC)) {
            ob_end_clean();
            echo json_encode(['status' => 'duplicada', 'artigo' => $dup, 'message' => 'Já existe um artigo com a referência ' . $dup['referencia'] . '.']);
            exit;
        }
        
        $preco_custo = limparNumero($_POST['preco_custo'] ?? '0');
        $percentagem_lucro = (int)($_POST['percentagem_lucro'] ?? 0);
        $tempo_mao_obra = limparNumero($_POST['tempo_mao_obra'] ?? '0');
        $n_colaboradores = (int)($_POST['n_colaboradores'] ?? 1);
        $margem_bloqueada = (isset($_POST['margem_bloqueada']) && $_POST['margem_bloqueada'] == '1') ? 1 : 0;
        $marca = mb_substr(trim((string)($_POST['marca'] ?? '')), 0, 100);
        $id_ficha = (int)($_POST['id_ficha_tecnica'] ?? 0) ?: null;
        
        $preco_material = $preco_custo + ($preco_custo * ($percentagem_lucro / 100));
        $valor_mo = ($tempo_mao_obra / 60) * $n_colaboradores * $valor_hora_homem;
        $preco_com_mo = round($preco_material + $valor_mo, 2);

        // Artigo composto: os valores vêm sempre da soma dos componentes (o que vier do formulário é ignorado)
        $composto = null;
        if ($tem_compostos && trim((string)($_POST['composicao_json'] ?? '')) !== '') {
            $composto = tpCalcularComposto($pdo, tpLerComposicao($_POST['composicao_json']), $id_artigo, $valor_hora_homem);
            if ($composto) {
                // Um artigo que já faz parte de outro composto não pode passar a ser composto
                if ($id_artigo && ($usado = tpCompostosQueUsam($pdo, $id_artigo)))
                    throw new Exception('Este artigo faz parte do composto ' . implode(', ', $usado) . ': não pode ser também composto.');
                $preco_custo = $composto['preco_custo'];
                $percentagem_lucro = $composto['percentagem_lucro'];
                $tempo_mao_obra = $composto['tempo_mao_obra'];
                $n_colaboradores = 1;
                $margem_bloqueada = 1;   // a margem global não mexe nos compostos
                $preco_material = $composto['preco_material'];
                $valor_mo = $composto['valor_mo'];
                $preco_com_mo = $composto['preco_com_mo'];
            }
        }

        $is_new = false;
        $data_alteracao = date('Y-m-d H:i:s'); // Por defeito (se for novo ou se os preços mudarem)

        if ($id_artigo) {
            // Só atualiza a data se houver mexidas no Preço de Custo ou Tempo de M.O.
            $stmt_old = $pdo->prepare("SELECT preco_custo, tempo_mao_obra, data_alteracao FROM tabela_precos WHERE id = ?");
            $stmt_old->execute([$id_artigo]);
            $old_data = $stmt_old->fetch(PDO::FETCH_ASSOC);
            
            if ($old_data) {
                $old_custo = (float)$old_data['preco_custo'];
                $old_tempo = (float)$old_data['tempo_mao_obra'];
                
                // Se os valores fulcrais não mudarem, preserva-se a data antiga!
                if ($old_custo === $preco_custo && $old_tempo === $tempo_mao_obra) {
                    $data_alteracao = $old_data['data_alteracao']; 
                }
            }

            tpCriarArquivo($pdo);
            $st_arq = $pdo->prepare("SELECT * FROM tabela_precos WHERE id = ?"); $st_arq->execute([$id_artigo]);
            tpArquivar($pdo, $st_arq->fetchAll(PDO::FETCH_ASSOC), 'alterado');

            $sql = "UPDATE tabela_precos SET referencia = ?, descricao = ?, unidade = ?, preco_custo = ?, percentagem_lucro = ?, margem_bloqueada = ?, preco_material = ?, valor_mo = ?, preco_com_mo = ?, tempo_mao_obra = ?, n_colaboradores = ?, data_alteracao = ? WHERE id = ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$referencia, $descricao, $unidade, $preco_custo, $percentagem_lucro, $margem_bloqueada, $preco_material, $valor_mo, $preco_com_mo, $tempo_mao_obra, $n_colaboradores, $data_alteracao, $id_artigo]);
            if ($tem_fichas) $pdo->prepare("UPDATE tabela_precos SET marca = ?, id_ficha_tecnica = ? WHERE id = ?")->execute([$marca, $id_ficha, $id_artigo]);
        } else {
            // É um artigo NOVO
            $sql = "INSERT INTO tabela_precos (referencia, descricao, unidade, preco_custo, percentagem_lucro, margem_bloqueada, preco_material, valor_mo, preco_com_mo, tempo_mao_obra, n_colaboradores, data_alteracao) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$referencia, $descricao, $unidade, $preco_custo, $percentagem_lucro, $margem_bloqueada, $preco_material, $valor_mo, $preco_com_mo, $tempo_mao_obra, $n_colaboradores, $data_alteracao]);
            $id_artigo = $pdo->lastInsertId();
            $is_new = true;
            if ($tem_fichas) $pdo->prepare("UPDATE tabela_precos SET marca = ?, id_ficha_tecnica = ? WHERE id = ?")->execute([$marca, $id_ficha, $id_artigo]);
        }

        $compostos_alterados = [];
        if ($tem_compostos) {
            $pdo->prepare("UPDATE tabela_precos SET composicao_json = ? WHERE id = ?")
                ->execute([$composto ? json_encode($composto['composicao']) : null, $id_artigo]);
            // Os compostos que usam este artigo ficam com o preço novo
            if (!$composto) $compostos_alterados = tpRecalcularCompostos($pdo, $valor_hora_homem, $id_artigo);
        }

        $stmt_fetch = $pdo->prepare("SELECT * FROM tabela_precos WHERE id = ?");
        $stmt_fetch->execute([$id_artigo]);
        $artigo_final = $stmt_fetch->fetch(PDO::FETCH_ASSOC);

        ob_end_clean();
        echo json_encode(['status' => 'success', 'artigo' => $artigo_final, 'is_new' => $is_new, 'compostos' => $compostos_alterados]);
    } catch (Throwable $e) {
        ob_end_clean();
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit;
}
// =========================================================================

// =========================================================================
// ENDPOINT AJAX: CARREGAR UMA FICHA TÉCNICA NOVA A PARTIR DO ARTIGO
// =========================================================================
if (isset($_GET['ajax_nova_ficha'])) {
    ob_start();   // avisos do PHP não podem estragar a resposta JSON
    header('Content-Type: application/json');
    try {
        if (!$csrf_ok) throw new Exception($csrf_msg);
        if (!$tem_fichas) throw new Exception('Falta o ficheiro fichas_tecnicas_lib.php no servidor.');
        $marca  = mb_substr(trim((string)($_POST['marca'] ?? '')), 0, 100);
        $modelo = mb_substr(trim((string)($_POST['modelo'] ?? '')), 0, 150);
        $ref    = mb_substr(trim((string)($_POST['referencia_fab'] ?? '')), 0, 100);
        if ($marca === '' || $modelo === '') throw new Exception('Indica a marca e o modelo do produto.');
        $tem_f = !empty($_FILES['ficheiro']['name']); $tem_c = !empty($_FILES['certificado']['name']);
        if (!$tem_f && !$tem_c) throw new Exception('Escolhe a ficha técnica e/ou o certificado de conformidade.');
        [$ficheiro, $nome_or, $tam] = $tem_f ? ft_guardar_upload($_FILES['ficheiro'], $marca, $modelo) : ['', '', 0];
        [$c_fich, $c_nome, $c_tam] = $tem_c ? ft_guardar_upload($_FILES['certificado'], $marca, $modelo, 'cert_') : ['', '', 0];
        $pdo->prepare("INSERT INTO fichas_tecnicas (marca, modelo, referencia, descricao, ficheiro, nome_original, tamanho, versoes_json, cert_ficheiro, cert_nome_original, cert_tamanho, cert_versoes_json, ativo, carregado_em, carregado_por, atualizado_em)
                       VALUES (?,?,?,'',?,?,?,'[]',?,?,?,'[]',1,NOW(),?,NOW())")->execute([$marca, $modelo, $ref, $ficheiro, $nome_or, $tam, $c_fich, $c_nome, $c_tam, $_SESSION['nome'] ?? '']);
        $novo = (int)$pdo->lastInsertId();
        registrarLog($pdo, "Carregou produto ($marca $modelo)" . ($tem_f ? ' - ficha' : '') . ($tem_c ? ' - certificado' : '') . " a partir da Tabela de Preços", "Fichas Técnicas");
        ob_end_clean();
        echo json_encode(['status' => 'success', 'ficha' => ['id' => $novo, 'marca' => $marca, 'modelo' => $modelo, 'referencia' => $ref,
                                                            'url' => $ficheiro !== '' ? ft_url($ficheiro) : '', 'cert_url' => $c_fich !== '' ? ft_url($c_fich) : '']]);
    } catch (Throwable $e) {
        ob_end_clean();
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit;
}

// =========================================================================
// ENDPOINT AJAX: CARREGAR / SUBSTITUIR O CERTIFICADO DE CONFORMIDADE DE UM PRODUTO DA BIBLIOTECA
// =========================================================================
if (isset($_GET['ajax_certificado'])) {
    ob_start();
    header('Content-Type: application/json');
    try {
        if (!$csrf_ok) throw new Exception($csrf_msg);
        if (!$tem_fichas) throw new Exception('Falta o ficheiro fichas_tecnicas_lib.php no servidor.');
        $id_f = filter_input(INPUT_POST, 'id_ficha', FILTER_VALIDATE_INT);
        $st = $pdo->prepare("SELECT * FROM fichas_tecnicas WHERE id = ?"); $st->execute([$id_f ?: 0]);
        $atual = $st->fetch(PDO::FETCH_ASSOC);
        if (!$atual) throw new Exception('Produto não encontrado na biblioteca.');
        if (empty($_FILES['certificado']['name'])) throw new Exception('Escolhe o ficheiro do certificado.');
        [$c_fich, $c_nome, $c_tam] = ft_guardar_upload($_FILES['certificado'], $atual['marca'], $atual['modelo'], 'cert_');
        // Nunca perder dados: o certificado anterior fica no histórico (o ficheiro não é apagado)
        $versoes = json_decode((string)($atual['cert_versoes_json'] ?? ''), true) ?: [];
        if ($atual['cert_ficheiro'] !== '') $versoes[] = ['ficheiro' => $atual['cert_ficheiro'], 'nome_original' => $atual['cert_nome_original'], 'tamanho' => (int)$atual['cert_tamanho'],
                                                          'numero' => $atual['cert_numero'] ?? '', 'validade' => $atual['cert_validade'] ?? null, 'ate' => date('Y-m-d H:i:s')];
        $pdo->prepare("UPDATE fichas_tecnicas SET cert_ficheiro = ?, cert_nome_original = ?, cert_tamanho = ?, cert_versoes_json = ?, atualizado_em = NOW() WHERE id = ?")
            ->execute([$c_fich, $c_nome, $c_tam, json_encode($versoes, JSON_UNESCAPED_UNICODE), $atual['id']]);
        registrarLog($pdo, "Carregou certificado de conformidade (" . $atual['marca'] . ' ' . $atual['modelo'] . ") a partir da Tabela de Preços", "Fichas Técnicas");
        ob_end_clean();
        echo json_encode(['status' => 'success', 'cert_url' => ft_url($c_fich), 'substituido' => $atual['cert_ficheiro'] !== '']);
    } catch (Throwable $e) {
        ob_end_clean();
        if ($e instanceof PDOException) { error_log('tabela_precos.php (certificado): ' . $e->getMessage()); echo json_encode(['status' => 'error', 'message' => 'Não foi possível gravar o certificado.']); }
        else echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit;
}

$mensagem = ''; 
$tipo_mensagem = '';
$termo_filtro = $_POST['termo_filtro'] ?? '';
$marca_filtro = $_POST['marca_filtro'] ?? '';

$tem_acao = isset($_POST['importar_csv_json']) || isset($_POST['btn_confirmar_apagar']) || isset($_POST['btn_aplicar_margem_global']);
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $tem_acao && !$csrf_ok) {
    $mensagem = $csrf_msg;
    $tipo_mensagem = "erro";
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // 1. IMPORTAR CSV
    if (isset($_POST['importar_csv_json'])) {
        try {
            $itens_csv = json_decode($_POST['importar_csv_json'], true);
            if (!empty($itens_csv)) {
                $pdo->beginTransaction();
                $sql = "INSERT INTO tabela_precos (referencia, descricao, unidade, preco_custo, percentagem_lucro, margem_bloqueada, preco_material, valor_mo, preco_com_mo, tempo_mao_obra, n_colaboradores, data_alteracao) VALUES (?, ?, ?, ?, ?, 0, ?, ?, ?, ?, ?, ?)";
                $stmt = $pdo->prepare($sql);
                $data_alt = date('Y-m-d H:i:s');
                $count = 0;
                // Referências que já existem (não se repetem)
                $refs_existentes = [];
                foreach ($pdo->query("SELECT referencia FROM tabela_precos")->fetchAll(PDO::FETCH_COLUMN) as $r_ex) $refs_existentes[mb_strtolower(trim((string)$r_ex), 'UTF-8')] = 1;
                $ignoradas = [];
                
                foreach ($itens_csv as $item) {
                    $ref = trim($item['referencia'] ?? '');
                    $desc = trim($item['descricao'] ?? '');
                    $ref_k = mb_strtolower($ref, 'UTF-8');
                    if ($ref !== '' && isset($refs_existentes[$ref_k])) { $ignoradas[] = $ref; continue; }
                    
                    if ($ref !== '' && $desc !== '') {
                        $refs_existentes[$ref_k] = 1;
                        $un = trim($item['unidade'] ?? 'UN');
                        $pc = limparNumero($item['preco_custo'] ?? '0');
                        $perc = 0;
                        $tmo = limparNumero($item['tempo_mao_obra'] ?? '0');
                        $ncol = (int)($item['n_colaboradores'] ?? 1);
                        
                        $preco_material = $pc + ($pc * ($perc / 100));
                        $valor_mo = ($tmo / 60) * $ncol * $valor_hora_homem;
                        $preco_com_mo = round($preco_material + $valor_mo, 2); 
                        
                        $stmt->execute([$ref, $desc, $un, $pc, $perc, $preco_material, $valor_mo, $preco_com_mo, $tmo, $ncol, $data_alt]);
                        $count++;
                    }
                }
                $pdo->commit();
                registrarLog($pdo, "Importou $count artigos via CSV para a Tabela de Preços", "Tabela de Preços");
                $mensagem = "$count artigos importados e adicionados à tabela com sucesso!"
                          . ($ignoradas ? ' ' . count($ignoradas) . ' linha(s) não importada(s) porque a referência já existe: ' . implode(', ', array_slice($ignoradas, 0, 15)) . (count($ignoradas) > 15 ? '…' : '') . '.' : '');
                $tipo_mensagem = "sucesso";
            }
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $mensagem = "Erro na importação: " . htmlspecialchars($e->getMessage());
            $tipo_mensagem = "erro";
        }
    }

    // 2. APAGAR ARTIGO
    if (isset($_POST['btn_confirmar_apagar'])) {
        if (!$is_admin) { 
            $mensagem = "Erro: Sem permissão.";  
            $tipo_mensagem = "erro"; 
        } else {
            $id_apagar = filter_input(INPUT_POST, 'id_artigo_apagar', FILTER_VALIDATE_INT); 
            $pass_introduzida = $_POST['pass_seguranca'] ?? '';

            $stmt_pass = $pdo->prepare("SELECT valor FROM configuracoes WHERE chave = 'password_apagar' LIMIT 1"); 
            $stmt_pass->execute(); 
            $pass_config = $stmt_pass->fetchColumn();

            if ($pass_introduzida === '' || (string)$pass_config === '' || !hash_equals((string)$pass_config, (string)$pass_introduzida)) { 
                $mensagem = "Password de segurança incorreta.";  
                $tipo_mensagem = "erro"; 
            } else {
                try {
                    if ($tem_compostos && ($usado = tpCompostosQueUsam($pdo, (int)$id_apagar)))
                        throw new Exception('este artigo faz parte do(s) composto(s) ' . implode(', ', $usado) . '. Retire-o primeiro desse(s) composto(s).');
                    tpCriarArquivo($pdo);
                    $st_arq = $pdo->prepare("SELECT * FROM tabela_precos WHERE id = ?"); $st_arq->execute([$id_apagar ?: 0]);
                    $pdo->beginTransaction();
                    tpArquivar($pdo, $st_arq->fetchAll(PDO::FETCH_ASSOC), 'apagado');
                    $stmt_del = $pdo->prepare("DELETE FROM tabela_precos WHERE id = ?");
                    $stmt_del->execute([$id_apagar]);
                    $pdo->commit();
                    registrarLog($pdo, "Apagou artigo da Tabela de Preços (ID: $id_apagar)", "Tabela de Preços");
                    
                    $mensagem = "Artigo removido. Fica guardada uma cópia no arquivo.";  
                    $tipo_mensagem = "sucesso"; 
                } catch (Exception $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    $mensagem = "Erro ao apagar artigo: " . htmlspecialchars($e->getMessage());  
                    $tipo_mensagem = "erro"; 
                }
            }
        }
    }

    // 3. APLICAR MARGEM GLOBAL
    if (isset($_POST['btn_aplicar_margem_global'])) {
        if (!$is_admin) {
            $mensagem = "Erro: Sem permissão.";
            $tipo_mensagem = "erro";
        } else {
            $margem_global = (int)$_POST['margem_global_val'];
            $data_alteracao = date('Y-m-d H:i:s');
            
            try {
                tpCriarArquivo($pdo);
                $pdo->beginTransaction();
                tpArquivar($pdo, $pdo->query("SELECT * FROM tabela_precos WHERE margem_bloqueada = 0")->fetchAll(PDO::FETCH_ASSOC), 'margem_global');
                // IGNORA OS ARTIGOS ONDE margem_bloqueada = 1
                $sql = "UPDATE tabela_precos SET 
                        percentagem_lucro = :m1,
                        preco_material = preco_custo + (preco_custo * (:m2 / 100)),
                        valor_mo = (tempo_mao_obra / 60) * n_colaboradores * :vh1,
                        preco_com_mo = ROUND(preco_custo + (preco_custo * (:m3 / 100)) + ((tempo_mao_obra / 60) * n_colaboradores * :vh2), 2),
                        data_alteracao = :dt
                        WHERE margem_bloqueada = 0";
                        
                $stmt = $pdo->prepare($sql);
                $stmt->execute([
                    'm1' => $margem_global,
                    'm2' => $margem_global,
                    'm3' => $margem_global,
                    'vh1' => $valor_hora_homem,
                    'vh2' => $valor_hora_homem,
                    'dt' => $data_alteracao
                ]);
                
                $pdo->commit();
                if ($tem_compostos) tpRecalcularCompostos($pdo, $valor_hora_homem);
                registrarLog($pdo, "Aplicou Margem Global de $margem_global% na Tabela de Preços", "Tabela de Preços");
                $mensagem = "Margem aplicada com sucesso aos artigos não bloqueados!";
                $tipo_mensagem = "sucesso";
            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $mensagem = "Erro ao aplicar margem global: " . htmlspecialchars($e->getMessage());
                $tipo_mensagem = "erro";
            }
        }
    }
}

// --- BUSCAR DADOS DA TABELA ---
try {
    $stmt = $pdo->query("SELECT * FROM tabela_precos ORDER BY descricao ASC");
    $artigos = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    die("Erro ao carregar a Tabela de Preços: " . htmlspecialchars($e->getMessage()));
}

// Filtro de marca: marcas dos artigos da tabela, com o número de artigos de cada uma
$marcas_filtro = []; $n_sem_marca = 0;
foreach ($artigos as $a) {
    $m = trim((string)($a['marca'] ?? ''));
    if ($m === '') { $n_sem_marca++; continue; }
    $marcas_filtro[$m] = ($marcas_filtro[$m] ?? 0) + 1;
}
uksort($marcas_filtro, 'strnatcasecmp');

// Referências que já estão repetidas na tabela (de antes desta regra)
$refs_contagem = [];
foreach ($artigos as $a) { $k = mb_strtolower(trim((string)$a['referencia']), 'UTF-8'); if ($k !== '') $refs_contagem[$k] = ($refs_contagem[$k] ?? 0) + 1; }
$refs_repetidas = array_keys(array_filter($refs_contagem, fn($n) => $n > 1));

// Fichas técnicas da biblioteca e marcas já usadas
$fichas_bib = [];
$marcas_lista = [];
if ($tem_fichas) {
    try {
        foreach ($pdo->query("SELECT id, marca, modelo, referencia, ficheiro, cert_ficheiro, ativo FROM fichas_tecnicas ORDER BY marca ASC, modelo ASC")->fetchAll(PDO::FETCH_ASSOC) as $f) {
            $fichas_bib[(int)$f['id']] = ['id' => (int)$f['id'], 'marca' => $f['marca'], 'modelo' => $f['modelo'], 'referencia' => $f['referencia'],
                                         'url' => $f['ficheiro'] !== '' ? ft_url($f['ficheiro']) : '',
                                         'cert_url' => (string)$f['cert_ficheiro'] !== '' ? ft_url($f['cert_ficheiro']) : '', 'ativo' => (int)$f['ativo']];
            if ($f['marca'] !== '') $marcas_lista[$f['marca']] = 1;
        }
    } catch (Exception $e) {}
}
foreach ($artigos as $a) if (!empty($a['marca'])) $marcas_lista[$a['marca']] = 1;
$marcas_lista = array_keys($marcas_lista);
natcasesort($marcas_lista);
?>
<!DOCTYPE html>
<html lang="pt-PT">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Tabela de Preços - Gestão MAB</title>
    <!-- REGRA DE OURO 8: ícone MAB no separador -->
    <link rel="icon" type="image/png" href="/mab.png">
    <link rel="apple-touch-icon" href="/mab.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { font-family: system-ui, -apple-system, sans-serif; background-color: #f7f9fc; margin: 0; color: #333; padding-bottom: 50px; }
        * { box-sizing: border-box; }
        
        .topbar { background-color: #fff; padding: 15px 40px; box-shadow: 0 4px 20px rgba(0,0,0,0.05); display: flex; justify-content: space-between; align-items: center; position: sticky; top: 0; z-index: 100; }
        .topbar-left .logo { height: 40px; }
        .topbar-center { display: flex; gap: 25px; flex: 1; }
        .topbar-right { display: flex; align-items: center; gap: 30px; }
        
        .btn-voltar { width: 40px; height: 40px; border-radius: 50%; background: #fff; border: 1px solid #e8ecf1; color: #576574; display: inline-flex; align-items: center; justify-content: center; text-decoration: none; font-size: 16px; transition: all 0.2s ease; box-shadow: 0 2px 6px rgba(0,0,0,0.04); }
        .btn-voltar:hover { background: #eef2f6; color: #34495e; transform: translateX(-2px); }
        
        .user-menu { position: relative; display: inline-block; }
        .user-btn { background: none; border: none; font-size: 15px; font-weight: 600; color: #2c3e50; cursor: pointer; display: flex; align-items: center; gap: 8px; }
        .user-avatar { background: #e74c3c; color: white; width: 28px; height: 28px; border-radius: 50%; display: flex; justify-content: center; align-items: center; font-size: 14px; border: 2px solid #fadbd8; }
        
        .dropdown-content { display: none; position: absolute; right: 0; top: 100%; margin-top: 10px; background: #fff; min-width: 180px; box-shadow: 0 10px 30px rgba(0,0,0,0.1); border-radius: 8px; overflow: hidden; border: 1px solid #f1f2f6; z-index:200;}
        .dropdown-content a { color: #576574; padding: 12px 20px; text-decoration: none; display: block; font-size: 14px; }
        .dropdown-content a:hover { background: #fdf2f2; color: #3498db; }
        .user-menu.aberto .dropdown-content { display: block; }

        .container { max-width: 1400px; margin: 20px auto; padding: 0 15px; }
        
        .page-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px; border-bottom: 1px solid #e1e5ea; padding-bottom: 15px; flex-wrap: wrap; gap: 15px; }
        .page-title { margin: 0; color: #2c3e50; font-size: 22px; font-weight: 800; display: flex; align-items: center; gap: 10px; }
        .page-title i { color: #3498db; }
        
        .btn-novo { background: #fff; color: #3498db; border: 1px solid #3498db; padding: 8px 15px; border-radius: 30px; text-decoration: none; font-weight: 700; font-size: 13px; display: inline-flex; align-items: center; gap: 6px; transition: 0.2s; cursor:pointer;}
        .btn-novo:hover { background: #3498db; color: #fff; }
        .btn-novo-csv { background: #fff; color: #27ae60; border: 1px solid #27ae60; padding: 8px 15px; border-radius: 30px; text-decoration: none; font-weight: 700; font-size: 13px; display: inline-flex; align-items: center; gap: 6px; transition: 0.2s; cursor:pointer;}
        .btn-novo-csv:hover { background: #27ae60; color: #fff; }

        .tools-bar { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px; margin-bottom: 15px; }
        .search-container { position: relative; max-width: 350px; width: 100%; }
        .search-container i { position: absolute; left: 15px; top: 50%; transform: translateY(-50%); color: #95a5a6; }
        .search-input { width: 100%; padding: 10px 15px 10px 40px; border: 1px solid #dcdde1; border-radius: 30px; font-size: 14px; outline: none; transition: 0.2s; font-family: inherit; }
        .search-input:focus { border-color: #3498db; box-shadow: 0 0 0 3px rgba(52, 152, 219, 0.1); }
        
        .comp-tag { display: inline-block; margin-top: 4px; background: #f3e8ff; color: #7c3aed; font-size: 11px; font-weight: 700; padding: 2px 8px; border-radius: 5px; white-space: nowrap; }
        .comp-tp { border: 1px dashed #d8b4fe; background: #fbf8ff; border-radius: 10px; padding: 10px 12px; }
        .comp-tp-chk { display: flex !important; align-items: center; gap: 8px; cursor: pointer; color: #6d28d9 !important; font-weight: 700; margin: 0 !important; }
        .comp-tp-chk span { font-weight: 400; color: #7f8c8d; }
        .comp-tp-chk input { width: 16px; height: 16px; accent-color: #8b5cf6; }
        .comp-tp-pesq { position: relative; margin: 10px 0 8px; }
        .comp-tp-pesq input { width: 100%; }
        .comp-tp-sug { display: none; position: absolute; left: 0; right: 0; top: 100%; background: #fff; border: 1px solid #dcdde1; border-radius: 8px; box-shadow: 0 8px 24px rgba(0,0,0,0.1); z-index: 50; max-height: 240px; overflow-y: auto; }
        .comp-tp-sug div { padding: 8px 12px; cursor: pointer; font-size: 13px; border-bottom: 1px solid #f1f2f6; }
        .comp-tp-sug div:hover { background: #f5f3ff; }
        .comp-tp-sug small { color: #95a5a6; }
        .comp-tp-tab { width: 100%; min-width: 0 !important; border-collapse: collapse; font-size: 13px; background: #fff; border-radius: 8px; }
        .comp-tp-tab th { font-size: 10px; text-transform: uppercase; color: #7f8c8d; text-align: left; padding: 6px 8px; border-bottom: 1px solid #e9d5ff; background: transparent; position: static; }
        .comp-tp-tab td { padding: 6px 8px; border-bottom: 1px solid #f1f2f6; vertical-align: middle; }
        .comp-tp-tab .n { text-align: right; white-space: nowrap; }
        .comp-tp-tab td.n input { width: 70px; text-align: right; padding: 6px; }
        .comp-tp-tab tfoot td { font-weight: 700; color: #6d28d9; border-bottom: none; }
        .comp-tp-tab .del { background: none; border: none; color: #e74c3c; cursor: pointer; padding: 4px 6px; }
        .comp-tp-vazio { color: #95a5a6; text-align: center; padding: 12px !important; }
        .campo-auto { background: #f5f3ff !important; color: #6d28d9; }
        .ordem-tel { display: none; }
        th.ordenavel { cursor: pointer; user-select: none; white-space: nowrap; }
        th.ordenavel:hover { color: #2c3e50; }
        th.ordenavel i { font-size: 10px; color: #bdc3c7; margin-left: 3px; }
        th.ordenavel.ativo { color: #2980b9; }
        th.ordenavel.ativo i { color: #2980b9; }
        .header-acoes { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; }
        .marca-container { position: relative; width: 240px; max-width: 100%; }
        .marca-container i { position: absolute; left: 15px; top: 50%; transform: translateY(-50%); color: #95a5a6; pointer-events: none; }
        .marca-container select { appearance: none; -webkit-appearance: none; background: #fff url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='6'%3E%3Cpath d='M0 0l5 6 5-6z' fill='%2395a5a6'/%3E%3C/svg%3E") no-repeat right 15px center; padding-right: 32px; cursor: pointer; }
        .marca-container select.ativo { border-color: #16a085; color: #117864; font-weight: 700; }
        .contagem-filtro { font-size: 13px; color: #7f8c8d; font-weight: 600; margin-right: auto; }
        .margem-global-form { display: flex; align-items: center; gap: 10px; background: #fff; padding: 6px 15px; border-radius: 30px; border: 1px solid #e1e5ea; }
        .margem-global-form label { margin: 0; font-size: 11px; font-weight: 700; color: #576574; text-transform: uppercase; }
        .margem-global-form input { width: 70px; padding: 6px; border: 1px solid #dcdde1; border-radius: 6px; text-align: center; font-size: 14px; outline: none; }
        .margem-global-form input:focus { border-color: #f39c12; }
        .btn-margem { background: #f39c12; color: #fff; border: none; padding: 7px 15px; border-radius: 20px; font-weight: 600; font-size: 12px; cursor: pointer; transition: 0.2s; display: inline-flex; align-items: center; gap: 5px; }
        .btn-margem:hover { background: #e67e22; }

        .table-container { background: #fff; border-radius: 12px; border: 1px solid #e1e5ea; box-shadow: 0 2px 10px rgba(0,0,0,0.02); overflow: hidden; }
        .table-responsive { width: 100%; overflow-x: auto; height: 65vh; }
        table { width: 100%; border-collapse: collapse; min-width: 1100px; }
        /* Tabela de preços compacta: cabe a 100% num ecrã de portátil (sem barra de deslocamento) */
        #tabelaPrecos { min-width: 0; }
        #tabelaPrecos th { padding: 10px 6px; font-size: 10.5px; line-height: 1.25; }
        #tabelaPrecos th:first-child, #tabelaPrecos td:first-child { padding-left: 12px; }
        #tabelaPrecos td { padding: 9px 6px; font-size: 13px; }
        #tabelaPrecos td:nth-child(n+4):nth-child(-n+12) { white-space: nowrap; }
        #tabelaPrecos td:nth-child(1) { word-break: break-word; }
        #tabelaPrecos .data-info { font-size: 11px; }
        #tabelaPrecos .acoes { gap: 4px; flex-wrap: nowrap; }
        #tabelaPrecos .btn-tab-acao { width: 28px; height: 28px; padding: 0; font-size: 13px; }
        th { padding: 12px 15px; background: #f8f9fa; color: #576574; font-size: 11px; text-transform: uppercase; text-align: left; border-bottom: 2px solid #e1e5ea; font-weight: 600; position: sticky; top: 0; z-index: 10;}
        td { padding: 12px 15px; border-bottom: 1px solid #f1f2f6; font-size: 14px; color: #2c3e50; vertical-align: middle; }
        tbody tr.linha-artigo:hover { background-color: #fcfcfc; }

        .text-center { text-align: center !important; }
        .text-right { text-align: right !important; }
        
        .ref-bold { font-weight: 700; color: #2c3e50; }
        .data-info { color: #95a5a6; font-size: 12px; }
        .valor-total { font-weight: 800; color: #f39c12; }
        .valor-mo { color: #2980b9; font-weight: 600; }
        .valor-mat { color: #27ae60; font-weight: 600; }
        .badge-percent { background: #fef5e7; color: #d68910; padding: 2px 6px; border-radius: 4px; font-size: 11px; font-weight: bold; }
        
        .alert { padding: 15px; border-radius: 8px; margin-bottom: 20px; text-align: center; font-weight: 600; }
        .alert-erro { background: #fdeded; color: #e74c3c; border: 1px solid #f9cccc; }
        .alert-sucesso { background: #e8f5e9; color: #2ecc71; border: 1px solid #c8e6c9; }

        .acoes { display: flex; gap: 8px; justify-content: center; }
        .btn-tab-acao { color: #576574; background: #f1f2f6; padding: 8px; border-radius: 6px; text-decoration: none; transition: 0.2s; display: inline-flex; width: 32px; height: 32px; align-items: center; justify-content: center; font-size:15px; border:none; cursor:pointer;}
        .btn-tab-acao:hover { background: #e1e5ea; color: #2c3e50; }
        .btn-editar { color: #f39c12; background: #fef5e7; }
        .btn-editar:hover { color: #d68910; background: #fdebd0; }
        .btn-duplicar { color: #16a085; background: #e8f8f5; }
        .btn-duplicar:hover { color: #117a65; background: #d1f2eb; }
        .btn-del { color: #e74c3c; background: #fdeded; }
        .btn-del:hover { background: #fadbd8; color: #c0392b; }

        /* MODAIS */
        .modal { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.6); z-index: 1001; justify-content: center; align-items: center; backdrop-filter: blur(2px); }
        .modal.show { display: flex; }
        .modal-content { background: #fff; padding: 30px; border-radius: 12px; width: 90%; max-width: 600px; box-shadow: 0 20px 50px rgba(0,0,0,0.2); }
        
        .modal-header { display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #f1f2f6; padding-bottom: 15px; margin-bottom: 20px; }
        .modal-header h3 { margin: 0; color: #3498db; font-size: 18px; display: flex; align-items: center; gap: 8px; }
        .close-btn { color: #95a5a6; font-size: 22px; cursor: pointer; font-weight: bold; background: none; border: none; padding: 0; line-height: 1; transition: 0.2s; }
        .close-btn:hover { color: #333; }

        .form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 15px; }
        .form-group { display: flex; flex-direction: column; }
        .form-group.full { grid-column: span 2; }
        .form-group label { font-weight: 600; font-size: 11px; color: #576574; text-transform: uppercase; margin-bottom: 6px; }
        .form-group input, .form-group select { padding: 10px 12px; border: 1px solid #dcdde1; border-radius: 8px; font-size: 14px; outline: none; transition: 0.2s; font-family: inherit;}
        .form-group input:focus, .form-group select:focus { border-color: #3498db; }
        
        .btn-primary { background: #3498db; color: #fff; padding: 12px 24px; border-radius: 8px; text-decoration: none; font-weight: 600; font-size: 14px; border: none; cursor: pointer; width: 100%; transition: 0.2s;}
        .btn-primary:hover { background: #2980b9;}
        .btn-danger { background: #e74c3c; }
        .btn-danger:hover { background: #c0392b; }

        /* MARCA / FICHA TÉCNICA */
        .marca-tag { background: #e8f6f3; color: #117864; font-weight: 700; padding: 2px 7px; border-radius: 5px; font-size: 11px; white-space: nowrap; display: inline-block; }
        .ficha-link { color: #c0392b; font-size: 15px; text-decoration: none; margin-left: 4px; }
        .sem-ficha { color: #d5d8dc; font-size: 13px; margin-left: 4px; }
        .ficha-linha { display: flex; gap: 8px; align-items: center; }
        .ficha-linha input { flex: 1; min-width: 0; }
        .btn-mini { background: #f1f2f6; border: 1px solid #dcdde1; color: #34495e; border-radius: 8px; padding: 9px 11px; cursor: pointer; font-size: 13px; text-decoration: none; display: inline-flex; align-items: center; gap: 6px; white-space: nowrap; font-family: inherit; }
        .btn-mini:hover { background: #e8ecf1; }
        .btn-mini.verde { background: #e8f6f3; border-color: #a3e4d7; color: #117864; }
        .nova-ficha { display: none; margin-top: 10px; padding: 12px; border: 1px dashed #16a085; border-radius: 10px; background: #f6fcfa; }
        .nova-ficha .linha2 { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 10px; }
        .nova-ficha input { padding: 9px 10px; border: 1px solid #dcdde1; border-radius: 8px; font-size: 13px; width: 100%; font-family: inherit; }
        .ficha-info { font-size: 11px; color: #7f8c8d; margin-top: 4px; min-height: 14px; }
        .ficha-link.cert-link { color: #2471a3; }
        .cert-linha { display: none; margin-top: 8px; padding: 9px 11px; border: 1px solid #d6eaf8; background: #f8fbfe; border-radius: 8px; font-size: 12px; color: #2c3e50; align-items: center; gap: 8px; flex-wrap: wrap; }
        .cert-linha a.ver { color: #2471a3; font-weight: 700; text-decoration: none; }
        .cert-linha .sem { color: #95a5a6; font-style: italic; }
        .btn-mini.azul { background: #eaf2fb; border-color: #aed6f1; color: #1f618d; }
        .nova-ficha label.lbl-doc { display: block; font-size: 11px; font-weight: 700; color: #576574; text-transform: uppercase; margin: 8px 0 4px; }
        .modal-content { max-height: 92vh; overflow-y: auto; }

        /* TOAST DE SUCESSO AJAX */
        #toast { visibility: hidden; min-width: 250px; background-color: #27ae60; color: #fff; text-align: center; border-radius: 8px; padding: 15px; position: fixed; z-index: 2000; left: 50%; bottom: 30px; transform: translateX(-50%); font-weight: bold; font-size: 15px; box-shadow: 0 4px 15px rgba(0,0,0,0.2); opacity: 0; transition: opacity 0.3s, bottom 0.3s; }
        #toast.show { visibility: visible; opacity: 1; bottom: 50px; }

        @media (max-width: 768px) {
            .topbar-center { display: none; }
            .topbar { padding: 15px 20px; }
            .nova-ficha .linha2 { grid-template-columns: 1fr; }
            .form-grid { grid-template-columns: 1fr; }
            .form-group.full { grid-column: span 1; }
            .tools-bar { flex-direction: column; align-items: stretch; }
            .search-container, .marca-container { max-width: none; width: 100%; }
            .ordem-tel { display: block; padding-left: 15px; }
            /* Telemóvel: botões do topo em grelha e cada artigo passa a cartão */
            .header-acoes { display: grid !important; grid-template-columns: 1fr 1fr; gap: 8px; width: 100%; }
            .header-acoes > a, .header-acoes > button { justify-content: center; text-align: center; width: 100%; }
            .table-container { background: transparent; border: none; box-shadow: none; overflow: visible; }
            .table-responsive { height: auto; overflow: visible; }
            #tabelaPrecos { min-width: 0; }
            #tabelaPrecos thead { display: none; }
            #tabelaPrecos, #tabelaPrecos tbody { display: block; width: 100%; }
            #tabelaPrecos tr.linha-artigo { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 8px 10px; background: #fff; border: 1px solid #e1e5ea; border-radius: 12px; padding: 12px; margin-bottom: 10px; box-shadow: 0 2px 8px rgba(0,0,0,0.03); }
            #tabelaPrecos tr.linha-artigo > td { display: block; padding: 0; border: none; text-align: left !important; font-size: 13px; }
            #tabelaPrecos tr.linha-artigo > td:nth-child(1) { grid-column: 1 / 3; grid-row: 1; font-size: 14px; align-self: center; }
            #tabelaPrecos tr.linha-artigo > td:nth-child(13) { grid-column: 3; grid-row: 1; }
            #tabelaPrecos tr.linha-artigo > td:nth-child(13) .acoes { justify-content: flex-end; }
            #tabelaPrecos tr.linha-artigo > td:nth-child(2) { grid-column: 1 / -1; font-size: 14px; color: #2c3e50; }
            #tabelaPrecos tr.linha-artigo > td:nth-child(3) { grid-column: 1 / -1; }
            #tabelaPrecos tr.linha-artigo > td:nth-child(n+4):nth-child(-n+12) { border-top: 1px solid #f1f2f6; padding-top: 6px; }
            #tabelaPrecos tr.linha-artigo > td:nth-child(n+4):nth-child(-n+12)::before { display: block; font-size: 10px; font-weight: 700; text-transform: uppercase; color: #95a5a6; margin-bottom: 2px; }
            #tabelaPrecos tr.linha-artigo > td:nth-child(4)::before { content: "Unid."; }
            #tabelaPrecos tr.linha-artigo > td:nth-child(5)::before { content: "Custo base"; }
            #tabelaPrecos tr.linha-artigo > td:nth-child(6)::before { content: "Margem"; }
            #tabelaPrecos tr.linha-artigo > td:nth-child(7)::before { content: "Preço mat."; }
            #tabelaPrecos tr.linha-artigo > td:nth-child(8)::before { content: "Tempo (min)"; }
            #tabelaPrecos tr.linha-artigo > td:nth-child(9)::before { content: "Nº colab."; }
            #tabelaPrecos tr.linha-artigo > td:nth-child(10)::before { content: "Valor M.O."; }
            #tabelaPrecos tr.linha-artigo > td:nth-child(11)::before { content: "Preço c/ M.O."; color: #f39c12; }
            #tabelaPrecos tr.linha-artigo > td:nth-child(12)::before { content: "Última alt."; }
            #tabelaPrecos tr:not(.linha-artigo) { display: block; }
            #tabelaPrecos tr:not(.linha-artigo) > td { display: block; background: #fff; border-radius: 12px; }
            .margem-global-form { justify-content: space-between; }
        }
    </style>
</head>
<body>

    <header class="topbar">
        <div class="topbar-left"><a href="index.php"><img src="mab.png" alt="MAB" class="logo"></a></div>
        <div class="topbar-center"></div>
        <div class="topbar-right">
            <a href="index.php" class="btn-voltar" title="Voltar" aria-label="Voltar"><i class="fa-solid fa-arrow-left"></i></a>
            <div class="user-menu" id="userMenu">
                <button type="button" class="user-btn" onclick="event.stopPropagation(); document.getElementById('userMenu').classList.toggle('aberto');">
                    <div class="user-avatar"><i class="fa-solid fa-user"></i></div>
                    <?= htmlspecialchars($primeiro_nome) ?> 
                    <i class="fa-solid fa-chevron-down" style="font-size: 10px;"></i>
                </button>
                <div class="dropdown-content">
                    <a href="perfil.php"><i class="fa-solid fa-id-badge" style="width:20px;"></i> O meu perfil</a>
                    <?php if ($is_admin): ?>
                        <a href="configuracoes.php"><i class="fa-solid fa-gear" style="width:20px;"></i> Configurações</a>
                    <?php endif; ?>
                    <a href="index.php?logout=1" style="color: #e74c3c; border-top: 1px solid #f1f2f6;"><i class="fa-solid fa-right-from-bracket" style="width:20px;"></i> Sair</a>
                </div>
            </div>
        </div>
    </header>

    <div class="container">
        <?php if ($mensagem): ?><div class="alert alert-<?= htmlspecialchars($tipo_mensagem, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($mensagem, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>

        <?php if ($refs_repetidas): ?>
            <div class="alert alert-erro" style="text-align:left;">
                <i class="fa-solid fa-triangle-exclamation"></i> Há <?= count($refs_repetidas) ?> referência(s) repetida(s) na tabela:
                <?php foreach (array_slice($refs_repetidas, 0, 20) as $rr): ?>
                    <a href="#" onclick="document.getElementById('filtroReferencia').value = this.dataset.r; filtrarTabela(); return false;" data-r="<?= htmlspecialchars($rr, ENT_QUOTES, 'UTF-8') ?>" style="color:#c0392b; font-weight:800; margin-left:6px;"><?= htmlspecialchars(mb_strtoupper($rr, 'UTF-8'), ENT_QUOTES, 'UTF-8') ?></a>
                <?php endforeach; ?>
                <span style="font-weight:500;"> — clica para ver e corrige a referência de um deles.</span>
            </div>
        <?php endif; ?>

        <div class="page-header">
            <h1 class="page-title"><i class="fa-solid fa-list-ol"></i> Tabela de Preços</h1>
            
            <div class="header-acoes">
                <?php if ($tem_fichas): ?>
                <a href="fichas_tecnicas.php" class="btn-novo" style="color: #117864; border-color: #a3e4d7; background: #f6fcfa;">
                    <i class="fa-solid fa-book-open"></i> Fichas Técnicas
                </a>
                <?php endif; ?>
                <a href="tabela_precos_pdf.php" target="_blank" class="btn-novo" style="color: #576574; border-color: #dcdde1; background: #fdfdfd;">
                    <i class="fa-solid fa-print"></i> Imprimir PDF
                </a>
                <input type="file" id="csv_file" accept=".csv" style="display:none" onchange="processarFicheiroCSV(this)">
                <button type="button" class="btn-novo-csv" onclick="document.getElementById('csv_file').click()">
                    <i class="fa-solid fa-file-csv"></i> Importar CSV
                </button>
                <button type="button" class="btn-novo" onclick="abrirModalForm()">
                    <i class="fa-solid fa-plus-circle"></i> Novo Artigo
                </button>
            </div>
        </div>
        
        <form id="csvForm" method="POST" style="display:none;">
            <input type="hidden" name="importar_csv_json" id="csv_data_input">
            <?php /* REGRA DE OURO 7 */ ?><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(gerar_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
        </form>

        <div class="tools-bar">
            <div class="search-container">
                <i class="fa-solid fa-search"></i>
                <input type="text" id="filtroReferencia" class="search-input" onkeyup="filtrarTabela()" placeholder="Pesquisar por Referência, Descrição ou Marca..." value="<?= htmlspecialchars($termo_filtro, ENT_QUOTES, 'UTF-8') ?>">
            </div>

            <div class="marca-container">
                <i class="fa-solid fa-tag"></i>
                <select id="filtroMarca" class="search-input" onchange="filtrarTabela()" aria-label="Filtrar por marca">
                    <option value="">Todas as marcas</option>
                    <?php foreach ($marcas_filtro as $m => $n): ?>
                        <option value="<?= htmlspecialchars($m, ENT_QUOTES, 'UTF-8') ?>"<?= $marca_filtro === $m ? ' selected' : '' ?>><?= htmlspecialchars($m, ENT_QUOTES, 'UTF-8') ?> (<?= (int)$n ?>)</option>
                    <?php endforeach; ?>
                    <?php if ($n_sem_marca > 0): ?>
                        <option value="__sem__"<?= $marca_filtro === '__sem__' ? ' selected' : '' ?>>Sem marca (<?= (int)$n_sem_marca ?>)</option>
                    <?php endif; ?>
                </select>
            </div>
            <select id="ordemTel" class="search-input ordem-tel" onchange="const v = this.value.split(':'); ordemAtual = { campo: v[0], dir: parseInt(v[1], 10) }; ordenarPor(v[0], true);" aria-label="Ordenar">
                <option value="desc:1">Ordenar: descrição A → Z</option>
                <option value="desc:-1">Ordenar: descrição Z → A</option>
                <option value="ref:1">Ordenar: referência A → Z</option>
                <option value="ref:-1">Ordenar: referência Z → A</option>
            </select>
            <span id="contagemFiltro" class="contagem-filtro"></span>

            <?php if ($is_admin): ?>
            <form method="POST" class="margem-global-form" onsubmit="return confirm('Tem a certeza que deseja aplicar esta margem a TODOS os artigos (exceto os bloqueados)? Esta ação vai recalcular todos os preços.')">
                <input type="hidden" name="termo_filtro" class="termo_filtro_hidden" value="<?= htmlspecialchars($termo_filtro, ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="marca_filtro" class="marca_filtro_hidden" value="<?= htmlspecialchars($marca_filtro, ENT_QUOTES, 'UTF-8') ?>">
                <?php /* REGRA DE OURO 7 */ ?><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(gerar_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
                <label><i class="fa-solid fa-bolt" style="color:#f39c12; margin-right:4px;"></i> Margem Global:</label>
                <input type="number" name="margem_global_val" min="0" max="1000" placeholder="Ex: 20" required>
                <span style="font-weight:bold; color:#576574;">%</span>
                <button type="submit" name="btn_aplicar_margem_global" class="btn-margem">
                    Aplicar a Todos
                </button>
            </form>
            <?php endif; ?>
        </div>

        <div class="table-container">
            <div class="table-responsive">
                <table id="tabelaPrecos">
                    <thead>
                        <tr>
                            <th style="width: 95px;" class="ordenavel" data-ordem="ref" onclick="ordenarPor('ref')" title="Ordenar por referência">Referência <i class="fa-solid fa-sort"></i></th>
                            <th class="ordenavel" data-ordem="desc" onclick="ordenarPor('desc')" title="Ordenar por descrição">Descrição do Artigo <i class="fa-solid fa-sort"></i></th>
                            <th style="width: 95px;">Marca / Ficha</th>
                            <th class="text-center" style="width: 46px;">Unid.</th>
                            <th class="text-right" style="width: 78px;">Custo Base</th>
                            <th class="text-center" style="width: 58px;">Margem</th>
                            <th class="text-right" style="width: 78px;">Preço Mat.</th>
                            <th class="text-right" style="width: 62px;">Tempo (min)</th>
                            <th class="text-center" style="width: 46px;">Nº Colab.</th>
                            <th class="text-right" style="width: 72px;">Valor M.O.</th>
                            <th class="text-right" style="width: 84px; color:#f39c12;">Preço C/ M.O.</th>
                            <th class="text-center" style="width: 76px;">Última Alt.</th>
                            <th class="text-center" style="width: 104px;">Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($artigos)): ?>
                            <?php foreach ($artigos as $index => $art): 
                                $perc = (int)($art['percentagem_lucro'] ?? 0);
                                $custo_base = (float)($art['preco_custo'] ?? 0);
                                $tempo_minutos = (float)($art['tempo_mao_obra'] ?? 0);
                                $numero_homens = (int)($art['n_colaboradores'] ?? 1);
                                
                                $preco_material_calculado = $custo_base + ($custo_base * ($perc / 100));
                                $valor_mo_calculado = ($tempo_minutos / 60) * $numero_homens * $valor_hora_homem;
                                $preco_total_com_mo_calculado = round($preco_material_calculado + $valor_mo_calculado, 2);
                                
                                $data_formatada = (!empty($art['data_alteracao']) && $art['data_alteracao'] != '0000-00-00 00:00:00') ? date('d/m/Y', strtotime($art['data_alteracao'])) : '-';
                                
                                // ICON DE BLOQUEIO NA TABELA
                                $is_locked = ($art['margem_bloqueada'] ?? 0) == 1;
                                $lock_icon = $is_locked ? ' <i class="fa-solid fa-lock" style="color:#e74c3c; font-size:10px; margin-left:3px;" title="Margem Bloqueada"></i>' : '';
                            ?>
                                <tr class="linha-artigo" data-i="<?= (int)$index ?>" data-marca="<?= htmlspecialchars(trim((string)($art['marca'] ?? '')), ENT_QUOTES, 'UTF-8') ?>">
                                    <td class="ref-bold"><?= htmlspecialchars($art['referencia'], ENT_QUOTES, 'UTF-8') ?></td>
                                    <td><?= nl2br(htmlspecialchars($art['descricao'], ENT_QUOTES, 'UTF-8')) ?><?php
                                        $n_comp = count(tpLerComposicao($art['composicao_json'] ?? ''));
                                        if ($n_comp) echo '<br><span class="comp-tag" title="Artigo composto: preço = soma dos artigos que o compõem"><i class="fa-solid fa-layer-group"></i> Composto · ' . $n_comp . ' artigo' . ($n_comp > 1 ? 's' : '') . '</span>';
                                    ?></td>
                                    <td class="td-marca"><?php
                                        $f_art = $fichas_bib[(int)($art['id_ficha_tecnica'] ?? 0)] ?? null;
                                        if (!empty($art['marca'])) echo '<span class="marca-tag">' . htmlspecialchars($art['marca'], ENT_QUOTES, 'UTF-8') . '</span>';
                                        if ($f_art && $f_art['url'] !== '') echo '<a class="ficha-link" href="' . htmlspecialchars($f_art['url'], ENT_QUOTES, 'UTF-8') . '" target="_blank" title="Ficha técnica: ' . htmlspecialchars($f_art['marca'] . ' ' . $f_art['modelo'], ENT_QUOTES, 'UTF-8') . '"><i class="fa-solid fa-file-pdf"></i></a>';
                                        elseif ($tem_fichas) echo '<span class="sem-ficha" title="Sem ficha técnica"><i class="fa-regular fa-file"></i></span>';
                                        if ($f_art && $f_art['cert_url'] !== '') echo '<a class="ficha-link cert-link" href="' . htmlspecialchars($f_art['cert_url'], ENT_QUOTES, 'UTF-8') . '" target="_blank" title="Certificado de conformidade: ' . htmlspecialchars($f_art['marca'] . ' ' . $f_art['modelo'], ENT_QUOTES, 'UTF-8') . '"><i class="fa-solid fa-certificate"></i></a>';
                                    ?></td>
                                    <td class="text-center"><?= htmlspecialchars($art['unidade'], ENT_QUOTES, 'UTF-8') ?></td>
                                    <td class="text-right" style="white-space: nowrap;"><?= number_format($custo_base, 2, ',', ' ') ?> €</td>
                                    <td class="text-center">
                                        <?= $perc > 0 ? '<span class="badge-percent">+'.$perc.'%</span>' : '-' ?>
                                        <?= $lock_icon ?>
                                    </td>
                                    <td class="text-right valor-mat"><?= number_format($preco_material_calculado, 2, ',', '.') ?> €</td>
                                    <td class="text-right" style="white-space: nowrap;"><?= number_format($tempo_minutos, 2, ',', ' ') ?></td>
                                    <td class="text-center"><?= $numero_homens ?></td>
                                    <td class="text-right valor-mo"><?= number_format($valor_mo_calculado, 2, ',', '.') ?> €</td>
                                    <td class="text-right valor-total" title="Hora/Homem: <?= number_format($valor_hora_homem, 2, ',', '.') ?>€"><?= number_format($preco_total_com_mo_calculado, 2, ',', '.') ?> €</td>
                                    <td class="text-center data-info dt-alt"><?= $data_formatada ?></td>
                                    <td class="text-center">
                                        <div class="acoes">
                                            <button type="button" class="btn-tab-acao btn-duplicar" title="Duplicar Artigo" 
                                                onclick="duplicarArtigo(<?= $index ?>)">
                                                <i class="fa-solid fa-copy"></i>
                                            </button>
                                            <button type="button" class="btn-tab-acao btn-editar" title="Editar Artigo" 
                                                onclick="editarArtigo(<?= $index ?>)">
                                                <i class="fa-solid fa-pen-to-square"></i>
                                            </button>
                                            <?php if ($is_admin): ?>
                                            <button type="button" class="btn-tab-acao btn-del" title="Apagar Artigo" 
                                                onclick="abrirModalApagar(<?= (int)$art['id'] ?>, '<?= htmlspecialchars($art['referencia'], ENT_QUOTES, 'UTF-8') ?>')">
                                                <i class="fa-solid fa-trash-can"></i>
                                            </button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr id="msgVazia"><td colspan="13" class="text-center" style="padding:40px; color:#7f8c8d;">Nenhum artigo encontrado na tabela de preços.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div id="toast"><i class="fa-solid fa-check-circle"></i> Artigo guardado com sucesso!</div>

    <div class="modal" id="modalForm">
        <div class="modal-content">
            <div class="modal-header">
                <h3 id="modal_form_title"><i class="fa-solid fa-box"></i> Adicionar Artigo</h3>
                <button type="button" class="close-btn" onclick="fecharEAtualizar()">&times;</button>
            </div>
            
            <form id="formArtigo" onsubmit="event.preventDefault(); salvarArtigoAjax();">
                <input type="hidden" name="termo_filtro" class="termo_filtro_hidden" value="<?= htmlspecialchars($termo_filtro, ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="marca_filtro" class="marca_filtro_hidden" value="<?= htmlspecialchars($marca_filtro, ENT_QUOTES, 'UTF-8') ?>">
                <?php /* REGRA DE OURO 7 */ ?><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(gerar_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="id_artigo" id="form_id_artigo" value="">
                <input type="hidden" name="margem_bloqueada" id="form_margem_bloqueada" value="0">
                
                <div class="form-grid" style="text-align: left;">
                    <div class="form-group full">
                        <label>Referência *</label>
                        <input type="text" name="referencia" id="form_referencia" required maxlength="50" placeholder="Ex: ART-001">
                    </div>
                    
                    <div class="form-group full">
                        <label>Descrição *</label>
                        <input type="text" name="descricao" id="form_descricao" required maxlength="255" placeholder="Descrição completa do artigo">
                    </div>

                    <div class="form-group">
                        <label>Unidade *</label>
                        <input type="text" name="unidade" id="form_unidade" required maxlength="10" placeholder="Ex: UN, M, M2, H">
                    </div>

                    <div class="form-group">
                        <?php if ($tem_fichas): ?>
                        <label>Marca</label>
                        <input type="text" name="marca" id="form_marca" maxlength="100" list="dl_marcas_tp" placeholder="Ex: Legrand">
                        <?php endif; ?>
                    </div>

                    <?php if ($tem_fichas): ?>
                    <div class="form-group full">
                        <label>Ficha Técnica / Certificado de conformidade</label>
                        <input type="hidden" name="id_ficha_tecnica" id="form_id_ficha" value="">
                        <div class="ficha-linha">
                            <input type="text" id="form_ficha_txt" list="dl_fichas_tp" placeholder="Escolher da biblioteca (escreve o modelo, a referência ou a marca)…" oninput="fichaEscrita(this)" autocomplete="off">
                            <a href="#" target="_blank" class="btn-mini" id="btnVerFicha" style="display:none;" title="Abrir a ficha"><i class="fa-solid fa-file-pdf" style="color:#c0392b;"></i></a>
                            <button type="button" class="btn-mini verde" onclick="toggleNovaFicha()" title="Carregar um produto novo (ficha técnica e/ou certificado) para a biblioteca"><i class="fa-solid fa-cloud-arrow-up"></i> Nova</button>
                        </div>
                        <div class="ficha-info" id="fichaInfo"></div>
                        <!-- Certificado de conformidade do produto escolhido -->
                        <div class="cert-linha" id="certLinha">
                            <i class="fa-solid fa-certificate" style="color:#2471a3;"></i>
                            <span id="certEstado"></span>
                            <input type="file" id="cert_ficheiro" accept="application/pdf,.pdf,image/jpeg,image/png" style="display:none;" onchange="enviarCertificado(this)">
                            <button type="button" class="btn-mini azul" id="btnCert" onclick="document.getElementById('cert_ficheiro').click()" style="margin-left:auto;"><i class="fa-solid fa-cloud-arrow-up"></i> <span>Carregar certificado</span></button>
                        </div>
                        <div class="nova-ficha" id="boxNovaFicha">
                            <div class="linha2">
                                <input type="text" id="nf_modelo" maxlength="150" placeholder="Modelo (ex.: Niloé Step tomada Schuko)">
                                <input type="text" id="nf_ref" maxlength="100" placeholder="Referência do fabricante (opcional)">
                            </div>
                            <label class="lbl-doc">Ficha técnica (opcional)</label>
                            <input type="file" id="nf_ficheiro" accept="application/pdf,.pdf,image/jpeg,image/png">
                            <label class="lbl-doc">Certificado de conformidade (opcional)</label>
                            <input type="file" id="nf_certificado" accept="application/pdf,.pdf,image/jpeg,image/png">
                            <div style="display:flex; gap:8px; justify-content:flex-end; margin-top:10px;">
                                <button type="button" class="btn-mini" onclick="toggleNovaFicha(false)">Cancelar</button>
                                <button type="button" class="btn-mini verde" id="btnEnviarFicha" onclick="enviarNovaFicha()"><i class="fa-solid fa-cloud-arrow-up"></i> Carregar e ligar a este artigo</button>
                            </div>
                            <div class="ficha-info">A marca é a do campo «Marca» acima. Carregue a ficha técnica e/ou o certificado (pelo menos um). Ficam na biblioteca e podem ser usados noutros artigos.</div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <?php if ($tem_compostos): ?>
                    <!-- ARTIGO COMPOSTO: vários artigos da tabela (preço = soma automática) -->
                    <div class="form-group full comp-tp">
                        <label class="comp-tp-chk"><input type="checkbox" id="compTPAtivo" onchange="compAtivar(this.checked)"> <i class="fa-solid fa-layer-group"></i> Artigo composto <span>(feito de vários artigos desta tabela)</span></label>
                        <input type="hidden" name="composicao_json" id="form_composicao_json" value="">
                        <div id="compTPEditor" style="display:none;">
                            <div class="comp-tp-pesq">
                                <input type="text" id="compTPPesq" placeholder="Acrescentar artigo (descrição, referência ou marca)…" oninput="compSugerir()" onfocus="compSugerir()" onblur="setTimeout(() => document.getElementById('compTPSug').style.display = 'none', 200)" autocomplete="off">
                                <div class="comp-tp-sug" id="compTPSug"></div>
                            </div>
                            <table class="comp-tp-tab">
                                <thead><tr><th>Artigo</th><th class="n">Qtd.</th><th class="n">Custo</th><th class="n">Venda</th><th></th></tr></thead>
                                <tbody id="compTPCorpo"></tbody>
                                <tfoot><tr><td>Total por 1 <span id="compTPUn">UN</span> (tempo: <span id="compTPTempo">0</span> min)</td><td></td><td class="n" id="compTPCusto">0,00 €</td><td class="n" id="compTPVenda">0,00 €</td><td></td></tr></tfoot>
                            </table>
                            <div class="ficha-info">O custo, o tempo e o preço de venda são sempre a soma destes artigos e atualizam-se sozinhos quando o preço de um deles muda. No orçamento, ao escolher este artigo, a linha fica com estes artigos em «Artigos desta linha».</div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <div class="form-group">
                        <label>Custo Base (€) *</label>
                        <input type="text" name="preco_custo" id="form_preco_custo" required oninput="forceComma(this); calcPrecoFinalModal();" placeholder="0,00">
                    </div>
                    
                    <div class="form-group" style="display:flex; flex-direction:row; gap:10px;">
                        <div style="flex:1;">
                            <label>Margem (%) 
                                <i class="fa-solid fa-unlock" id="icon_lock_margem" style="cursor:pointer; color:#95a5a6; margin-left:5px;" onclick="toggleLockMargem()" title="Bloquear/Desbloquear Margem"></i>
                            </label>
                            <input type="number" name="percentagem_lucro" id="form_percentagem_lucro" min="0" max="1000" value="0" oninput="calcPrecoFinalModal();">
                        </div>
                        <div style="flex:1;">
                            <label>Preço Mat. Final</label>
                            <input type="text" id="form_preco_material" readonly style="background:#f8f9fa; font-weight:bold; color:#27ae60; font-size:14px; width:100%; border-color:transparent;">
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Tempo M.O. (Minutos)</label>
                        <input type="text" name="tempo_mao_obra" id="form_tempo_mao_obra" oninput="forceComma(this); calcPrecoFinalModal();" placeholder="Ex: 90">
                    </div>

                    <div class="form-group">
                        <label>Nº Colaboradores</label>
                        <input type="number" name="n_colaboradores" id="form_colaboradores" min="1" value="1" oninput="calcPrecoFinalModal();">
                    </div>
                    
                    <div class="form-group full" style="margin-top: 10px; padding-top: 10px; border-top: 1px dashed #dcdde1; display:flex; flex-direction:row; gap:15px;">
                        <div style="flex:1;">
                            <label>Valor Mão de Obra (€) <span style="font-weight:normal; color:#7f8c8d;">(Auto)</span></label>
                            <input type="text" id="form_valor_mo" readonly style="background:#f8f9fa; font-weight:bold; color:#2980b9; font-size:14px; width:100%;">
                        </div>
                        <div style="flex:1;">
                            <label>Preço Final c/ M.O. (€) <span style="font-weight:normal; color:#7f8c8d;">(Mat. + M.O.)</span></label>
                            <input type="text" id="form_preco_final_mo" readonly style="background:#fcf3cf; font-weight:bold; color:#f39c12; font-size:16px; width:100%;">
                        </div>
                    </div>
                </div>

                <div style="display:flex; justify-content:space-between; align-items:center; margin-top:25px; gap: 10px; flex-wrap:wrap;">
                    <div style="display:flex; gap:5px; align-items:center;" id="box_navegacao">
                        <button type="button" id="btn_prev_artigo" class="btn-primary" style="background:#95a5a6; padding:12px 15px; width:auto;" title="Gravar e ir para o Artigo Anterior" onclick="if (vizinhoNoEcra(-1) >= 0) salvarEIrPara(vizinhoNoEcra(-1))"><i class="fa-solid fa-arrow-left"></i></button>
                        <button type="button" id="btn_next_artigo" class="btn-primary" style="background:#95a5a6; padding:12px 15px; width:auto;" title="Gravar e ir para o Artigo Seguinte" onclick="if (vizinhoNoEcra(1) >= 0) salvarEIrPara(vizinhoNoEcra(1))"><i class="fa-solid fa-arrow-right"></i></button>
                        
                        <button type="button" id="btn_dup_modal" class="btn-primary" style="background:#16a085; padding:12px 15px; width:auto; margin-left: 10px;" title="Duplicar este Artigo" onclick="duplicarNoModal()"><i class="fa-solid fa-copy"></i> Duplicar</button>
                    </div>
                    <div style="display:flex; gap:10px; flex:1; justify-content:flex-end;">
                        <button type="button" onclick="fecharEAtualizar()" style="background:#f1f2f6; border:none; padding:12px 24px; border-radius:8px; cursor:pointer; font-weight:600; color:#576574;">Sair</button>
                        <button type="submit" class="btn-primary" style="width:auto; padding:12px 30px;">Gravar</button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <?php if ($tem_fichas): ?>
    <datalist id="dl_marcas_tp"><?php foreach ($marcas_lista as $m) echo '<option value="' . htmlspecialchars($m, ENT_QUOTES, 'UTF-8') . '">'; ?></datalist>
    <datalist id="dl_fichas_tp"></datalist>
    <?php endif; ?>

    <!-- REFERÊNCIA REPETIDA -->
    <div class="modal" id="modalRefRepetida" style="z-index:1100;">
        <div class="modal-content" style="max-width: 460px;">
            <div class="modal-header">
                <h3 style="color:#e67e22;"><i class="fa-solid fa-triangle-exclamation"></i> Referência já existe</h3>
                <button type="button" class="close-btn" onclick="fecharRefRepetida()">&times;</button>
            </div>
            <p style="margin-top:0;">Já existe um artigo com a referência <strong id="rr_ref"></strong>:</p>
            <p style="background:#f8f9fa; border:1px solid #e1e5ea; border-radius:8px; padding:10px 12px; margin:0 0 16px;" id="rr_desc"></p>
            <p style="margin:0 0 14px; color:#576574;">O que pretende fazer?</p>
            <div style="display:flex; flex-direction:column; gap:8px;">
                <button type="button" class="btn-primary" onclick="refRepetidaEditar()"><i class="fa-solid fa-pen-to-square"></i> Editar o artigo existente</button>
                <button type="button" class="btn-primary" style="background:#16a085;" onclick="refRepetidaDuplicar()"><i class="fa-solid fa-copy"></i> Duplicar com uma referência nova (<span id="rr_sugestao"></span>)</button>
                <button type="button" onclick="fecharRefRepetida(true)" style="background:#f1f2f6; border:none; padding:12px; border-radius:8px; cursor:pointer; font-weight:600; color:#576574;">Voltar e mudar a referência</button>
            </div>
        </div>
    </div>

    <div class="modal" id="modalApagar">
        <div class="modal-content" style="max-width: 400px; text-align: center;">
            <div class="modal-header" style="border-bottom:none; margin-bottom:0; justify-content:center;">
                <h3 style="color:#e74c3c;"><i class="fa-solid fa-triangle-exclamation"></i> Confirmar Eliminação</h3>
            </div>
            <p>Deseja apagar o artigo <strong id="txt_modal_apagar"></strong> da tabela de preços?<br><span style="font-size:12px; color:#7f8c8d;">Fica guardada uma cópia no arquivo.</span></p>
            <form method="POST">
                <input type="hidden" name="termo_filtro" class="termo_filtro_hidden" value="<?= htmlspecialchars($termo_filtro, ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="marca_filtro" class="marca_filtro_hidden" value="<?= htmlspecialchars($marca_filtro, ENT_QUOTES, 'UTF-8') ?>">
                <?php /* REGRA DE OURO 7 */ ?><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(gerar_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="id_artigo_apagar" id="id_artigo_apagar_input">
                <input type="password" name="pass_seguranca" required placeholder="Password de Segurança" style="width:100%; padding:12px; border:1px solid #ddd; border-radius:8px; margin: 15px 0;">
                <div style="display:flex; gap:10px; margin-top:15px;">
                    <button type="button" onclick="fecharModais()" style="flex:1; background:#f1f2f6; border:none; padding:12px; border-radius:8px; cursor:pointer; font-weight:600; color:#576574;">Cancelar</button>
                    <button type="submit" name="btn_confirmar_apagar" class="btn-primary btn-danger" style="flex:1;">Apagar</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        const VALOR_HORA_HOMEM = <?= json_encode($valor_hora_homem) ?>;
        let artigosBD = <?= json_encode($artigos) ?>; 
        let currentIndexModal = -1;
        let houveramAlteracoes = false; 
        let isMargemLocked = false;

        // --- PEDIDOS AO SERVIDOR (AJAX) ---
        // O alojamento (InfinityFree) às vezes responde com uma página de verificação de segurança em vez dos dados.
        // Nesse caso: abre a página numa moldura invisível (o browser faz a verificação) e repete o pedido uma vez.
        function verificarServidor() {
            return new Promise(resolve => {
                const fr = document.createElement('iframe');
                fr.style.display = 'none';
                let feito = false;
                const fim = () => { if (feito) return; feito = true; setTimeout(() => { fr.remove(); resolve(); }, 300); };
                let cargas = 0;
                fr.onload = () => { if (++cargas >= 2) fim(); };   // 1.ª carga = verificação, 2.ª = página
                setTimeout(fim, 6000);
                fr.src = window.location.pathname + '?verificacao=' + Date.now();
                document.body.appendChild(fr);
            });
        }
        const CSRF_TOKEN = <?= json_encode(gerar_csrf_token()) ?>;
        async function pedirJSON(url, fd, repetir = true) {
            if (fd && !fd.has('csrf_token')) fd.append('csrf_token', CSRF_TOKEN);   // REGRA DE OURO 7
            const res = await fetch(url, { method: 'POST', body: fd, credentials: 'same-origin' });
            const txt = await res.text();
            try { return JSON.parse(txt); }
            catch (e) {
                if (repetir && /aes\.js|<html><body>/i.test(txt)) { await verificarServidor(); return pedirJSON(url, fd, false); }
                const resumo = txt.replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim().substring(0, 200);
                throw new Error('O servidor não respondeu como esperado (código ' + res.status + ')' + (resumo ? ': «' + resumo + '»' : '') + '. Recarrega a página (F5) e tenta de novo.');
            }
        }

        // --- FICHAS TÉCNICAS ---
        const TEM_FICHAS = <?= $tem_fichas ? 'true' : 'false' ?>;
        let fichasBib = <?= json_encode(array_values($fichas_bib), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS) ?>;
        // Texto que aparece na lista: "Marca Modelo · Ref." (único)
        // Regra da pesquisa de material: descrição (modelo) | referência do fabricante | marca
        function nomeFicha(f) { return [f.modelo, f.referencia, f.marca].map(x => (x || '').trim()).filter(Boolean).join(' | '); }
        function etiquetasFichas() {
            const vistos = {}, mapa = {};
            fichasBib.forEach(f => {
                let t = nomeFicha(f);
                if (vistos[t.toLowerCase()]) t += ' #' + f.id;
                vistos[t.toLowerCase()] = 1;
                mapa[t.toLowerCase()] = f; f._et = t;
            });
            return mapa;
        }
        let mapaFichas = {};
        function desenharListaFichas() {
            if (!TEM_FICHAS) return;
            mapaFichas = etiquetasFichas();
            document.getElementById('dl_fichas_tp').innerHTML = fichasBib.filter(f => f.ativo).map(f => '<option value="' + f._et.replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;') + '">').join('');
        }
        function mostrarFicha(f) {
            const btn = document.getElementById('btnVerFicha'), info = document.getElementById('fichaInfo');
            if (f) {
                if (f.url) { btn.href = f.url; btn.style.display = ''; } else btn.style.display = 'none';
                info.innerHTML = '<i class="fa-solid fa-link" style="color:#16a085;"></i> Ligado ao produto da biblioteca' + (f.ativo ? '' : ' (arquivado)') + (f.url ? '' : ' (sem ficha técnica)') + '.';
            }
            else { btn.style.display = 'none'; info.textContent = document.getElementById('form_ficha_txt').value.trim() ? 'Não está na biblioteca — escolhe uma opção da lista ou carrega uma nova.' : ''; }
            mostrarCertificado(f);
        }
        // Certificado de conformidade do produto ligado: abrir, carregar ou substituir
        function mostrarCertificado(f) {
            const box = document.getElementById('certLinha'); if (!box) return;
            box.style.display = f ? 'flex' : 'none';
            if (!f) return;
            document.getElementById('certEstado').innerHTML = f.cert_url
                ? '<a class="ver" href="' + f.cert_url.replace(/"/g, '&quot;') + '" target="_blank">Certificado de conformidade</a>'
                : '<span class="sem">Este produto ainda não tem certificado de conformidade.</span>';
            document.querySelector('#btnCert span').textContent = f.cert_url ? 'Substituir' : 'Carregar certificado';
        }
        async function enviarCertificado(inp) {
            const fich = inp.files[0]; inp.value = '';
            const id = parseInt(document.getElementById('form_id_ficha').value || 0, 10);
            const f = fichasBib.find(x => x.id === id);
            if (!fich || !f) return;
            if (fich.size > 10 * 1024 * 1024) { alert('O ficheiro tem mais de 10 MB.'); return; }
            if (f.cert_url && !confirm('Substituir o certificado deste produto? O atual fica guardado no histórico.')) return;
            const fd = new FormData(); fd.append('id_ficha', id); fd.append('certificado', fich);
            const btn = document.getElementById('btnCert'); btn.disabled = true;
            try {
                const json = await pedirJSON('?ajax_certificado=1', fd);
                if (json.status !== 'success') throw new Error(json.message || 'Erro ao carregar.');
                f.cert_url = json.cert_url;
                mostrarCertificado(f);
                mostrarToast(json.substituido ? 'Certificado substituído (o anterior ficou no histórico).' : 'Certificado carregado.');
            } catch (e) { alert(e.message); }
            finally { btn.disabled = false; }
        }
        function fichaEscrita(inp) {
            const f = mapaFichas[inp.value.trim().toLowerCase()];
            document.getElementById('form_id_ficha').value = f ? f.id : '';
            if (f) {
                const marca = document.getElementById('form_marca');
                if (marca.value.trim() === '' && f.marca) marca.value = f.marca;
            }
            mostrarFicha(f);
        }
        function preencherFicha(artigo) {
            if (!TEM_FICHAS) return;
            document.getElementById('form_marca').value = (artigo && artigo.marca) || '';
            const id = artigo ? parseInt(artigo.id_ficha_tecnica || 0, 10) : 0;
            const f = fichasBib.find(x => x.id === id);
            document.getElementById('form_id_ficha').value = f ? f.id : '';
            document.getElementById('form_ficha_txt').value = f ? f._et : '';
            toggleNovaFicha(false);
            mostrarFicha(f);
        }
        function toggleNovaFicha(abrir) {
            const box = document.getElementById('boxNovaFicha');
            const ab = abrir === undefined ? box.style.display !== 'block' : abrir;
            box.style.display = ab ? 'block' : 'none';
            if (ab) {
                const mod = document.getElementById('nf_modelo');
                if (!mod.value) mod.value = document.getElementById('form_descricao').value.substring(0, 150);
                document.getElementById('form_marca').focus();
            } else {
                document.getElementById('nf_modelo').value = ''; document.getElementById('nf_ref').value = ''; document.getElementById('nf_ficheiro').value = ''; document.getElementById('nf_certificado').value = '';
            }
        }
        async function enviarNovaFicha() {
            const marca = document.getElementById('form_marca').value.trim();
            const modelo = document.getElementById('nf_modelo').value.trim();
            const fich = document.getElementById('nf_ficheiro').files[0];
            const cert = document.getElementById('nf_certificado').files[0];
            if (!marca) { alert('Preenche primeiro a Marca do artigo.'); document.getElementById('form_marca').focus(); return; }
            if (!modelo) { alert('Indica o modelo do produto.'); return; }
            if (!fich && !cert) { alert('Escolhe a ficha técnica e/ou o certificado de conformidade.'); return; }
            if ((fich && fich.size > 10 * 1024 * 1024) || (cert && cert.size > 10 * 1024 * 1024)) { alert('Cada ficheiro tem de ter no máximo 10 MB.'); return; }
            const fd = new FormData();
            fd.append('marca', marca); fd.append('modelo', modelo); fd.append('referencia_fab', document.getElementById('nf_ref').value.trim());
            if (fich) fd.append('ficheiro', fich);
            if (cert) fd.append('certificado', cert);
            const btn = document.getElementById('btnEnviarFicha'); btn.disabled = true;
            try {
                const json = await pedirJSON('?ajax_nova_ficha=1', fd);
                if (json.status !== 'success') throw new Error(json.message || 'Erro ao carregar.');
                const f = Object.assign({ ativo: 1 }, json.ficha);
                fichasBib.push(f);
                desenharListaFichas();
                document.getElementById('form_id_ficha').value = f.id;
                document.getElementById('form_ficha_txt').value = f._et;
                toggleNovaFicha(false);
                mostrarFicha(f);
                mostrarToast('Produto carregado. Grava o artigo para o ligar.');
            } catch (e) { alert(e.message); }
            finally { btn.disabled = false; }
        }
        document.addEventListener('DOMContentLoaded', desenharListaFichas);
        document.addEventListener('click', () => { const m = document.getElementById('userMenu'); if (m) m.classList.remove('aberto'); });

        // --- SISTEMA DE BLOQUEIO DE MARGEM ---
        function toggleLockMargem(forcarEstado = null) {
            if (forcarEstado !== null) {
                isMargemLocked = forcarEstado;
            } else {
                isMargemLocked = !isMargemLocked;
            }
            
            const input = document.getElementById('form_percentagem_lucro');
            const icon = document.getElementById('icon_lock_margem');
            const hiddenInput = document.getElementById('form_margem_bloqueada');
            
            if (isMargemLocked) {
                input.readOnly = true;
                input.style.backgroundColor = '#f1f2f6';
                icon.className = 'fa-solid fa-lock';
                icon.style.color = '#e74c3c';
                hiddenInput.value = "1";
            } else {
                input.readOnly = false;
                input.style.backgroundColor = '#fff';
                icon.className = 'fa-solid fa-unlock';
                icon.style.color = '#95a5a6';
                hiddenInput.value = "0";
            }
        }

        // --- SISTEMA AJAX PARA GRAVAR ARTIGOS SEM SAIR ---
        async function salvarArtigoAjax(callbackAfterSave = null) {
            const form = document.getElementById('formArtigo');
            if(!form.checkValidity()) {
                form.reportValidity();
                return;
            }
            
            // Referência repetida? (também é verificado no servidor)
            const refDig = document.getElementById('form_referencia').value;
            const idAtual = document.getElementById('form_id_artigo').value;
            const iDup = indiceRefRepetida(refDig, idAtual);
            if (iDup >= 0) { abrirRefRepetida(iDup); return; }

            const fd = new FormData(form);
            try {
                const json = await pedirJSON('?ajax_save_artigo=1', fd);
                
                if (json.status === 'duplicada') {
                    let i2 = artigosBD.findIndex(a => String(a.id) === String(json.artigo.id));
                    if (i2 < 0) { artigosBD.push(json.artigo); i2 = artigosBD.length - 1; }
                    abrirRefRepetida(i2);
                    return;
                }
                if (json.status === 'success') {
                    houveramAlteracoes = true;
                    mostrarToast("Artigo guardado com sucesso!");
                    
                    if (json.is_new) {
                        artigosBD.push(json.artigo);
                        currentIndexModal = artigosBD.length - 1; 
                        document.getElementById('form_id_artigo').value = json.artigo.id;
                    } else {
                        artigosBD[currentIndexModal] = json.artigo;
                    }
                    
                    (json.compostos || []).forEach(c => { const k = artigosBD.findIndex(a => String(a.id) === String(c.id)); if (k >= 0) artigosBD[k] = c; });
                    if ((json.compostos || []).length) mostrarToast('Artigo guardado. ' + json.compostos.length + ' artigo(s) composto(s) atualizado(s).');
                    atualizarBotoesNavegacao();

                    if (callbackAfterSave) {
                        callbackAfterSave();
                    }
                } else {
                    alert('Erro ao gravar: ' + json.message);
                }
            } catch(e) {
                alert('Erro ao gravar o artigo: ' + e.message);
            }
        }

        function salvarEIrPara(index) {
            const form = document.getElementById('formArtigo');
            if(!form.checkValidity()) {
                form.reportValidity();
                return;
            }
            salvarArtigoAjax(() => {
                editarArtigo(index);
            });
        }

        function fecharEAtualizar() {
            fecharModais();
            if (houveramAlteracoes) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.action = window.location.pathname;

                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'termo_filtro';
                input.value = document.getElementById("filtroReferencia").value;

                form.appendChild(input);
                const inMarca = document.createElement('input');
                inMarca.type = 'hidden'; inMarca.name = 'marca_filtro';
                inMarca.value = document.getElementById('filtroMarca').value;
                form.appendChild(inMarca);
                const inCsrf = document.createElement('input');
                inCsrf.type = 'hidden'; inCsrf.name = 'csrf_token'; inCsrf.value = CSRF_TOKEN;
                form.appendChild(inCsrf);
                document.body.appendChild(form);
                form.submit();
            }
        }

        function mostrarToast(msg) {
            let toast = document.getElementById("toast");
            toast.innerHTML = `<i class="fa-solid fa-check-circle"></i> ${msg}`;
            toast.className = "show";
            setTimeout(function(){ toast.className = toast.className.replace("show", ""); }, 2500);
        }

        // --- GESTÃO DE NAVEGAÇÃO E DUPLICAÇÃO ---
        // --- ORDENAR A TABELA (referência ou descrição; 2.º clique inverte). Fica lembrado neste navegador. ---
        const LS_ORDEM = 'mab_tabela_precos_ordem';
        let ordemAtual = { campo: 'desc', dir: 1 };
        const colPT = new Intl.Collator('pt', { sensitivity: 'base', numeric: true });
        function ordenarPor(campo, manterDir) {
            if (!manterDir) ordemAtual = { campo, dir: ordemAtual.campo === campo ? -ordemAtual.dir : 1 };
            const tbody = document.querySelector('#tabelaPrecos tbody');
            const linhas = [...tbody.querySelectorAll('tr.linha-artigo')];
            const chave = tr => { const a = artigosBD[parseInt(tr.dataset.i, 10)] || {}; return String((ordemAtual.campo === 'ref' ? a.referencia : a.descricao) || '').trim(); };
            linhas.sort((x, y) => {
                const kx = chave(x), ky = chave(y);
                if (!kx !== !ky) return kx ? -1 : 1;   // vazios no fim
                return ordemAtual.dir * (colPT.compare(kx, ky) || colPT.compare(String((artigosBD[x.dataset.i] || {}).referencia || ''), String((artigosBD[y.dataset.i] || {}).referencia || '')));
            });
            const msg = document.getElementById('msgVaziaFiltro');
            linhas.forEach(tr => tbody.insertBefore(tr, msg && msg.parentNode === tbody ? msg : null));
            document.querySelectorAll('th.ordenavel').forEach(th => {
                const ativo = th.dataset.ordem === ordemAtual.campo;
                th.classList.toggle('ativo', ativo);
                th.querySelector('i').className = 'fa-solid ' + (ativo ? (ordemAtual.dir > 0 ? 'fa-sort-up' : 'fa-sort-down') : 'fa-sort');
            });
            const sel = document.getElementById('ordemTel'); if (sel) sel.value = ordemAtual.campo + ':' + ordemAtual.dir;
            try { localStorage.setItem(LS_ORDEM, JSON.stringify(ordemAtual)); } catch (e) {}
        }
        window.addEventListener('DOMContentLoaded', () => {
            try { const o = JSON.parse(localStorage.getItem(LS_ORDEM) || 'null'); if (o && (o.campo === 'ref' || o.campo === 'desc')) ordemAtual = { campo: o.campo, dir: o.dir === -1 ? -1 : 1 }; } catch (e) {}
            ordenarPor(ordemAtual.campo, true);
        });
        // Índice (em artigosBD) do artigo anterior / seguinte na ordem do ecrã; -1 se não houver
        function vizinhoNoEcra(delta) {
            const ordem = [...document.querySelectorAll('#tabelaPrecos tr.linha-artigo')].filter(tr => tr.style.display !== 'none').map(tr => parseInt(tr.dataset.i, 10));
            const pos = ordem.indexOf(currentIndexModal);
            if (pos < 0) { const n = currentIndexModal + delta; return (n >= 0 && n < artigosBD.length) ? n : -1; }
            const v = ordem[pos + delta];
            return v === undefined ? -1 : v;
        }

        function atualizarBotoesNavegacao() {
            const btnPrev = document.getElementById('btn_prev_artigo');
            const btnNext = document.getElementById('btn_next_artigo');
            const btnDup = document.getElementById('btn_dup_modal');
            
            if (currentIndexModal === -1 || artigosBD.length === 0) {
                btnPrev.style.display = 'none';
                btnNext.style.display = 'none';
                btnDup.style.display = 'none';
                return;
            }
            
            btnPrev.style.display = 'inline-flex';
            btnNext.style.display = 'inline-flex';
            btnDup.style.display = 'inline-flex';
            
            // Anterior / seguinte pela ordem (e filtro) que está no ecrã
            const ant = vizinhoNoEcra(-1), seg = vizinhoNoEcra(1);
            btnPrev.style.visibility = ant < 0 ? 'hidden' : 'visible';
            btnPrev.onclick = function() { if (vizinhoNoEcra(-1) >= 0) salvarEIrPara(vizinhoNoEcra(-1)); };
            btnNext.style.visibility = seg < 0 ? 'hidden' : 'visible';
            btnNext.onclick = function() { if (vizinhoNoEcra(1) >= 0) salvarEIrPara(vizinhoNoEcra(1)); };
        }

        // ---------- REFERÊNCIAS ÚNICAS ----------
        const normRef = r => String(r || '').trim().toLowerCase();
        function indiceRefRepetida(ref, idAtual) {
            const k = normRef(ref);
            if (!k) return -1;
            return artigosBD.findIndex(a => normRef(a.referencia) === k && String(a.id) !== String(idAtual || ''));
        }
        // Próxima referência livre: YD-604 → YD-604-2 → YD-604-3 …  (ART-009 → ART-010)
        function referenciaLivre(ref) {
            ref = String(ref || '').trim();
            const m = ref.match(/^(.*?)(\d+)$/);
            let cand;
            if (m && /[-_.\/ ]$/.test(m[1])) {
                let n = parseInt(m[2], 10);
                do { n++; cand = m[1] + String(n).padStart(m[2].length, '0'); } while (indiceRefRepetida(cand, '') >= 0);
                return cand;
            }
            let n = 2;
            do { cand = ref + '-' + n; n++; } while (indiceRefRepetida(cand, '') >= 0);
            return cand;
        }
        let rrIndice = -1;
        function abrirRefRepetida(i) {
            rrIndice = i;
            const a = artigosBD[i];
            document.getElementById('rr_ref').textContent = a.referencia;
            document.getElementById('rr_desc').textContent = a.descricao || '';
            document.getElementById('rr_sugestao').textContent = referenciaLivre(document.getElementById('form_referencia').value);
            document.getElementById('modalRefRepetida').classList.add('show');
        }
        function fecharRefRepetida(focar) {
            document.getElementById('modalRefRepetida').classList.remove('show');
            if (focar) { const r = document.getElementById('form_referencia'); r.focus(); r.select(); }
        }
        function refRepetidaEditar() {
            fecharRefRepetida();
            if (!confirm('Abrir o artigo existente? O que escreveu neste formulário não fica gravado.')) { fecharRefRepetida(true); return; }
            editarArtigo(rrIndice);
        }
        function refRepetidaDuplicar() {
            // Mantém o que está no formulário como artigo NOVO, com uma referência livre
            document.getElementById('form_referencia').value = document.getElementById('rr_sugestao').textContent;
            document.getElementById('form_id_artigo').value = '';
            document.getElementById('modal_form_title').innerHTML = '<i class="fa-solid fa-copy"></i> Duplicar Artigo';
            currentIndexModal = -1;
            atualizarBotoesNavegacao();
            fecharRefRepetida(true);
            mostrarToast('Referência alterada para ' + document.getElementById('form_referencia').value + '. Confirme e clique em Gravar.');
        }

        function duplicarNoModal() {
            document.getElementById('form_id_artigo').value = '';
            document.getElementById('form_referencia').value = referenciaLivre(document.getElementById('form_referencia').value);
            document.getElementById('modal_form_title').innerHTML = '<i class="fa-solid fa-copy"></i> Duplicar Artigo';
            
            // A referência passa para a próxima livre (não pode haver referências repetidas)

            currentIndexModal = -1;
            atualizarBotoesNavegacao();
            
            mostrarToast("Modo Duplicação ativado. Faça as alterações e clique em Gravar.");
        }

        // --- FILTRO: pesquisa (referência, descrição, marca) + marca ---
        function filtrarTabela() {
            const input = document.getElementById("filtroReferencia");
            const selMarca = document.getElementById("filtroMarca");
            const filter = input.value.toUpperCase();
            const marca = selMarca.value;
            selMarca.classList.toggle('ativo', marca !== '');

            document.querySelectorAll('.termo_filtro_hidden').forEach(el => el.value = input.value);
            document.querySelectorAll('.marca_filtro_hidden').forEach(el => el.value = marca);

            const table = document.getElementById("tabelaPrecos");
            const tr = table.getElementsByClassName("linha-artigo");
            let countVisible = 0;

            for (let i = 0; i < tr.length; i++) {
                const tdRef = tr[i].getElementsByTagName("td")[0];
                const tdDesc = tr[i].getElementsByTagName("td")[1];
                const tdMarca = tr[i].querySelector('.td-marca');
                if (!tdRef || !tdDesc) continue;

                const mLinha = tr[i].dataset.marca || '';
                const marcaOk = marca === '' || (marca === '__sem__' ? mLinha === '' : mLinha === marca);
                const txt = ((tdRef.textContent || '') + ' ' + (tdDesc.textContent || '') + ' ' + (tdMarca ? tdMarca.textContent : '')).toUpperCase();
                const mostra = marcaOk && (filter === '' || txt.indexOf(filter) > -1);
                tr[i].style.display = mostra ? '' : 'none';
                if (mostra) countVisible++;
            }

            const filtrado = filter.trim() !== '' || marca !== '';
            document.getElementById('contagemFiltro').textContent = filtrado ? countVisible + ' de ' + tr.length + ' artigo(s)' : '';

            let msgVazia = document.getElementById('msgVaziaFiltro');
            if (countVisible === 0 && tr.length > 0) {
                if (!msgVazia) {
                    const tbody = table.querySelector('tbody');
                    tbody.insertAdjacentHTML('beforeend', '<tr id="msgVaziaFiltro"><td colspan="13" class="text-center" style="padding:40px; color:#7f8c8d;">Nenhum artigo encontrado para esta pesquisa.</td></tr>');
                } else {
                    msgVazia.style.display = '';
                }
            } else if (msgVazia) {
                msgVazia.style.display = 'none';
            }
        }

        window.addEventListener('DOMContentLoaded', () => {
            if (document.getElementById('filtroReferencia').value.trim() !== '' || document.getElementById('filtroMarca').value !== '') {
                filtrarTabela();
            }
        });

        // --- IMPORTAÇÃO CSV ---
        function processarFicheiroCSV(input) {
            const file = input.files[0];
            if (!file) return;
            const reader = new FileReader();
            
            reader.onload = function(e) {
                const text = e.target.result;
                const lines = text.split('\n');
                let items = [];
                
                for (let i = 0; i < lines.length; i++) {
                    let line = lines[i].trim();
                    if (!line) continue;
                    
                    let cols = line.split(';');
                    if(cols.length < 2 && line.includes(',')) { cols = line.split(','); }
                    if (cols.length < 2) continue; 
                    
                    if (i === 0 && cols[0].toLowerCase().includes('ref')) continue;

                    items.push({
                        referencia: cols[0] ? cols[0].trim().replace(/['"]/g,'') : '',
                        descricao: cols[1] ? cols[1].trim().replace(/['"]/g,'') : '',
                        unidade: cols[2] ? cols[2].trim().replace(/['"]/g,'') : 'UN',
                        preco_custo: cols[3] ? cols[3].trim().replace(/['"]/g,'') : '0',
                        tempo_mao_obra: cols[4] ? cols[4].trim().replace(/['"]/g,'') : '0',
                        n_colaboradores: cols[5] ? parseInt(cols[5].trim().replace(/['"]/g,'')) || 1 : 1
                    });
                }
                
                if(items.length > 0) {
                    document.getElementById('csv_data_input').value = JSON.stringify(items);
                    document.getElementById('csvForm').submit();
                } else {
                    alert("Atenção: Não foram encontrados dados estruturados corretamente no ficheiro CSV.");
                }
                input.value = '';
            };
            reader.readAsText(file, 'ISO-8859-1'); 
        }

        // --- AUXILIARES ---
        function forceComma(el) {
            if (el.value.indexOf('.') !== -1) {
                let cursorPosition = el.selectionStart;
                el.value = el.value.replace(/\./g, ',');
                el.setSelectionRange(cursorPosition, cursorPosition);
            }
        }

        function strToNumLocal(s) { 
            if (!s) return 0;
            let str = s.toString().replace(/\./g, '').replace(/\s/g, '').replace(',', '.');
            return parseFloat(str) || 0; 
        }

        function formatNumberToPT(num, dec = 2) {
            if (num === null || num === undefined) return '';
            return parseFloat(num).toFixed(dec).replace('.', ',');
        }

        // --- ARTIGO COMPOSTO (preço = soma automática dos componentes) ---
        let compTP = [];   // [{id, q}]
        const temCompostos = !!document.getElementById('compTPAtivo');
        const artPorId = id => artigosBD.find(a => parseInt(a.id, 10) === parseInt(id, 10));
        function compDe(a) { try { const c = JSON.parse((a && a.composicao_json) || '[]'); return Array.isArray(c) ? c : []; } catch (e) { return []; } }
        // Regra da pesquisa de material: descrição | referência | marca
        function rotuloTP(a) { return [a.descricao, a.referencia, a.marca].map(x => String(x || '').trim()).filter(Boolean).join(' | '); }
        function escTP(t) { return String(t == null ? '' : t).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c])); }
        function compCarregar(artigo, manter) {
            if (!temCompostos) return;
            compTP = compDe(artigo).map(c => ({ id: parseInt(c.id, 10), q: parseFloat(c.q) || 0 }));
            document.getElementById('compTPAtivo').checked = compTP.length > 0;
            compAtivar(compTP.length > 0, true);
        }
        function compAtivar(on, semConfirmar) {
            if (!temCompostos) return;
            if (!on && compTP.length && !semConfirmar && !confirm('Deixar de ser composto? Os artigos da composição saem e o preço passa a ser escrito à mão.')) {
                document.getElementById('compTPAtivo').checked = true; return;
            }
            if (!on) compTP = [];
            document.getElementById('compTPEditor').style.display = on ? '' : 'none';
            ['form_preco_custo', 'form_percentagem_lucro', 'form_tempo_mao_obra', 'form_colaboradores'].forEach(id => {
                const el = document.getElementById(id); el.readOnly = on; el.classList.toggle('campo-auto', on);
            });
            if (!on && document.getElementById('form_margem_bloqueada').value === '1') document.getElementById('form_percentagem_lucro').readOnly = true;
            document.getElementById('icon_lock_margem').style.display = on ? 'none' : '';
            const fichaBox = document.getElementById('form_ficha_txt'); if (fichaBox) fichaBox.closest('.form-group').style.display = on ? 'none' : '';
            compDesenhar();
        }
        function compSugerir() {
            const q = document.getElementById('compTPPesq').value.trim().toLowerCase(), box = document.getElementById('compTPSug');
            if (q.length < 2) { box.style.display = 'none'; return; }
            const idProprio = parseInt(document.getElementById('form_id_artigo').value || '0', 10);
            const termos = q.split(/\s+/);
            const res = artigosBD.filter(a => parseInt(a.id, 10) !== idProprio && !compDe(a).length)
                .filter(a => { const t = rotuloTP(a).toLowerCase(); return termos.every(x => t.includes(x)); }).slice(0, 12);
            box.innerHTML = res.length ? '' : '<div style="cursor:default; color:#95a5a6;">Nenhum artigo encontrado (os compostos não entram noutro composto).</div>';
            res.forEach(a => {
                const d = document.createElement('div');
                d.innerHTML = escTP(rotuloTP(a)) + ' <small>· ' + formatNumberToPT(a.preco_custo) + ' € custo · ' + formatNumberToPT(a.preco_com_mo || 0) + ' € venda</small>';
                d.onmousedown = e => { e.preventDefault(); compTP.push({ id: parseInt(a.id, 10), q: 1 }); document.getElementById('compTPPesq').value = ''; box.style.display = 'none'; compDesenhar(true); };
                box.appendChild(d);
            });
            box.style.display = 'block';
        }
        function compDesenhar(focarUltimo) {
            if (!temCompostos) return;
            const corpo = document.getElementById('compTPCorpo');
            document.getElementById('compTPUn').textContent = document.getElementById('form_unidade').value || 'UN';
            if (!compTP.length) corpo.innerHTML = '<tr><td colspan="5" class="comp-tp-vazio">Procure acima os artigos que fazem este composto.</td></tr>';
            else corpo.innerHTML = compTP.map((c, i) => {
                const a = artPorId(c.id);
                return '<tr><td>' + (a ? escTP(rotuloTP(a)) + ' <small style="color:#95a5a6;">(' + escTP(a.unidade || '') + ')</small>' : '<span style="color:#e74c3c;">Artigo já não existe</span>') + '</td>' +
                    '<td class="n"><input type="text" inputmode="decimal" value="' + formatNumberToPT(c.q, c.q % 1 ? 3 : 2) + '" oninput="forceComma(this); compTP[' + i + '].q = strToNumLocal(this.value); compTotais();"></td>' +
                    '<td class="n" id="compTPc' + i + '"></td><td class="n" id="compTPv' + i + '"></td>' +
                    '<td><button type="button" class="del" title="Retirar" onclick="compTP.splice(' + i + ', 1); compDesenhar();"><i class="fa-solid fa-trash-can"></i></button></td></tr>';
            }).join('');
            compTotais();
            if (focarUltimo) { const ins = corpo.querySelectorAll('tr:last-child input'); if (ins[0]) { ins[0].focus(); ins[0].select(); } }
        }
        function compTotais() {
            if (!temCompostos) return null;
            let pc = 0, pmat = 0, t = 0, pv = 0;
            compTP.forEach((c, i) => {
                const a = artPorId(c.id); if (!a) return;
                const pci = parseFloat(a.preco_custo) || 0;
                const vc = c.q * pci, vv = c.q * (parseFloat(a.preco_com_mo) || 0);
                pc += vc; pv += vv;
                pmat += c.q * pci * (1 + (parseFloat(a.percentagem_lucro) || 0) / 100);
                t += c.q * (parseFloat(a.tempo_mao_obra) || 0) * (parseInt(a.n_colaboradores, 10) || 1);
                const ec = document.getElementById('compTPc' + i), ev = document.getElementById('compTPv' + i);
                if (ec) ec.textContent = formatNumberToPT(vc) + ' €'; if (ev) ev.textContent = formatNumberToPT(vv) + ' €';
            });
            document.getElementById('compTPCusto').textContent = formatNumberToPT(pc) + ' €';
            document.getElementById('compTPVenda').textContent = formatNumberToPT(pv) + ' €';
            document.getElementById('compTPTempo').textContent = formatNumberToPT(t);
            const ativo = document.getElementById('compTPAtivo').checked;
            document.getElementById('form_composicao_json').value = ativo && compTP.length ? JSON.stringify(compTP.filter(c => c.q > 0)) : '';
            if (ativo) {
                // Os campos de preço mostram a soma (no servidor é sempre recalculada)
                document.getElementById('form_preco_custo').value = formatNumberToPT(pc);
                document.getElementById('form_percentagem_lucro').value = pc > 0 ? Math.round((pmat / pc - 1) * 100) : 0;
                document.getElementById('form_tempo_mao_obra').value = formatNumberToPT(t);
                document.getElementById('form_colaboradores').value = 1;
                document.getElementById('form_preco_material').value = formatNumberToPT(pmat) + ' €';
                document.getElementById('form_valor_mo').value = formatNumberToPT((t / 60) * VALOR_HORA_HOMEM) + ' €';
                document.getElementById('form_preco_final_mo').value = formatNumberToPT(pv) + ' €';
            }
            return { pc, pmat, t, pv };
        }

        // --- CÁLCULO MODAL ---
        function calcPrecoFinalModal() {
            if (temCompostos && document.getElementById('compTPAtivo').checked) { compTotais(); return; }
            let pc = strToNumLocal(document.getElementById('form_preco_custo').value);
            let perc = parseInt(document.getElementById('form_percentagem_lucro').value) || 0;
            
            let tmo = strToNumLocal(document.getElementById('form_tempo_mao_obra').value);
            let ncol = parseInt(document.getElementById('form_colaboradores').value) || 1;
            
            let precoMat = pc + (pc * (perc / 100));
            document.getElementById('form_preco_material').value = formatNumberToPT(precoMat) + ' €';

            let vmo = (tmo / 60) * ncol * VALOR_HORA_HOMEM;
            document.getElementById('form_valor_mo').value = formatNumberToPT(vmo) + ' €';
            
            let final = precoMat + vmo; 
            document.getElementById('form_preco_final_mo').value = formatNumberToPT(final, 2) + ' €';
        }

        // --- MODAIS CRUD ---
        function abrirModalForm() {
            currentIndexModal = -1; // Novo artigo
            toggleLockMargem(false);
            
            document.getElementById('modal_form_title').innerHTML = '<i class="fa-solid fa-box"></i> Adicionar Artigo';
            document.getElementById('form_id_artigo').value = '';
            document.getElementById('form_referencia').value = '';
            document.getElementById('form_descricao').value = '';
            document.getElementById('form_unidade').value = 'UN';
            document.getElementById('form_preco_custo').value = '';
            document.getElementById('form_percentagem_lucro').value = '0';
            document.getElementById('form_tempo_mao_obra').value = '';
            document.getElementById('form_colaboradores').value = '1';
            preencherFicha(null);
            compCarregar(null);
            
            calcPrecoFinalModal();
            atualizarBotoesNavegacao();
            document.getElementById('modalForm').classList.add('show');
        }

        function editarArtigo(index) {
            currentIndexModal = index;
            let artigo = artigosBD[index];
            
            // Repor estado do bloqueio do artigo
            toggleLockMargem(artigo.margem_bloqueada == 1);
            
            document.getElementById('modal_form_title').innerHTML = '<i class="fa-solid fa-pen-to-square"></i> Editar Artigo';
            document.getElementById('form_id_artigo').value = artigo.id || '';
            document.getElementById('form_referencia').value = artigo.referencia || '';
            document.getElementById('form_descricao').value = artigo.descricao || '';
            document.getElementById('form_unidade').value = artigo.unidade || '';
            document.getElementById('form_preco_custo').value = formatNumberToPT(artigo.preco_custo || 0);
            document.getElementById('form_percentagem_lucro').value = artigo.percentagem_lucro || '0';
            document.getElementById('form_tempo_mao_obra').value = formatNumberToPT(artigo.tempo_mao_obra || 0);
            document.getElementById('form_colaboradores').value = artigo.n_colaboradores || 1;
            preencherFicha(artigo);
            compCarregar(artigo);
            
            calcPrecoFinalModal();
            atualizarBotoesNavegacao();
            document.getElementById('modalForm').classList.add('show');
        }

        function duplicarArtigo(index) {
            currentIndexModal = -1; // Duplicar atua como Novo (esconde a navegação)
            let artigo = artigosBD[index];
            
            // Copia o estado do cadeado do artigo original para a cópia
            toggleLockMargem(artigo.margem_bloqueada == 1);
            
            document.getElementById('modal_form_title').innerHTML = '<i class="fa-solid fa-copy"></i> Duplicar Artigo';
            document.getElementById('form_id_artigo').value = ''; 
            document.getElementById('form_referencia').value = referenciaLivre(artigo.referencia || ''); 
            document.getElementById('form_descricao').value = artigo.descricao || '';
            document.getElementById('form_unidade').value = artigo.unidade || '';
            document.getElementById('form_preco_custo').value = formatNumberToPT(artigo.preco_custo || 0);
            document.getElementById('form_percentagem_lucro').value = artigo.percentagem_lucro || '0';
            document.getElementById('form_tempo_mao_obra').value = formatNumberToPT(artigo.tempo_mao_obra || 0);
            document.getElementById('form_colaboradores').value = artigo.n_colaboradores || 1;
            preencherFicha(artigo);
            compCarregar(artigo);
            
            calcPrecoFinalModal(); 
            atualizarBotoesNavegacao();
            document.getElementById('modalForm').classList.add('show');
        }

        function abrirModalApagar(id, ref) {
            document.getElementById('id_artigo_apagar_input').value = id;
            document.getElementById('txt_modal_apagar').innerText = ref;
            document.getElementById('modalApagar').classList.add('show');
            document.querySelector('#modalApagar input[name="pass_seguranca"]').value = '';
            document.querySelector('#modalApagar input[name="pass_seguranca"]').focus();
        }

        function fecharModais() { 
            document.getElementById('modalForm').classList.remove('show'); 
            document.getElementById('modalApagar').classList.remove('show'); 
        }
    </script>
</body>
</html>
