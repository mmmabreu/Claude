<?php
// REGRA DE OURO: Limpar qualquer espaço em branco ou lixo antes de gerar o PDF
ob_start();
session_start();
require_once 'db.php';

// REGRA DE OURO: Validação de sessão
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}

require_once 'functions.php';

// NUNCA PERDER DADOS: as fotos retiradas de uma visita não são apagadas; passam para imagens/arquivo/
function arquivarFotoVisita($nome) {
    $nome = basename((string)$nome);
    if ($nome === '' || $nome === '.' || $nome === '..') return;
    $origem = 'imagens/' . $nome;
    if (!is_file($origem)) return;
    if (!is_dir('imagens/arquivo')) @mkdir('imagens/arquivo', 0755, true);
    if (!@rename($origem, 'imagens/arquivo/' . date('Ymd_His') . '_' . $nome)) error_log('visita_form.php: não foi possível arquivar a foto ' . $nome);
}

// Motivo de uma fotografia não ter sido recebida pelo servidor
function erroUploadFoto($codigo, $nome) {
    $nome = basename((string)$nome);
    if ($codigo === UPLOAD_ERR_INI_SIZE || $codigo === UPLOAD_ERR_FORM_SIZE) return "\"$nome\" é maior do que o servidor aceita (" . ini_get('upload_max_filesize') . ").";
    if ($codigo === UPLOAD_ERR_PARTIAL) return "\"$nome\" chegou incompleta (a ligação falhou a meio).";
    if ($codigo === UPLOAD_ERR_NO_FILE) return '';
    return "\"$nome\" não foi recebida (erro $codigo).";
}
$avisos_fotos = [];

// REGRA DE OURO: Acesso (Visitas Comerciais ou Relatórios de Obra)
$perfil_user = $_SESSION['perfil'] ?? '';
$is_admin = in_array($perfil_user, ['admin', 'administrativo']);
$stmt_perm = $pdo->prepare("SELECT modulo FROM permissoes WHERE grupo = :perfil AND pode_aceder = 1");
$stmt_perm->execute(['perfil' => $perfil_user]);
$meus_acessos = $stmt_perm->fetchAll(PDO::FETCH_COLUMN);
if (!$is_admin && !in_array('visitas', $meus_acessos) && !in_array('relatorios', $meus_acessos)) {
    header("Location: index.php?erro=sem_permissao");
    exit;
}
$nome_completo = $_SESSION['nome'] ?? 'Utilizador';
$primeiro_nome = explode(' ', trim($nome_completo))[0];

// LEVANTAMENTO DE NECESSIDADES (áreas e perguntas)
require_once 'levantamento_def.php';
try { levCriarTabela($pdo); } catch (Exception $e) {}

// --- ATUALIZAR TABELA AUTOMATICAMENTE (Se necessário) ---
try {
    $check_obra =$pdo->query("SHOW COLUMNS FROM visitas LIKE 'id_obra'");
    if ($check_obra !== false && $check_obra->rowCount() == 0) {$pdo->exec("ALTER TABLE visitas ADD COLUMN id_obra INT DEFAULT NULL AFTER id_cliente");
    }

    $check_orcamento =$pdo->query("SHOW COLUMNS FROM visitas LIKE 'id_orcamento'");
    if ($check_orcamento !== false && $check_orcamento->rowCount() == 0) {$pdo->exec("ALTER TABLE visitas ADD COLUMN id_orcamento INT DEFAULT NULL AFTER id_obra");
    }

    $check_estado =$pdo->query("SHOW COLUMNS FROM visitas LIKE 'estado'");
    if ($check_estado !== false && $check_estado->rowCount() == 0) {$pdo->exec("ALTER TABLE visitas ADD COLUMN estado VARCHAR(50) DEFAULT 'Não processada' AFTER notas");
    }

    $check_fotos =$pdo->query("SHOW COLUMNS FROM visitas LIKE 'fotos'");
    if ($check_fotos !== false && $check_fotos->rowCount() == 0) {$pdo->exec("ALTER TABLE visitas ADD COLUMN fotos TEXT DEFAULT NULL");
    }

    // NOVO: Hora de Saída
    $check_saida =$pdo->query("SHOW COLUMNS FROM visitas LIKE 'hora_saida'");
    if ($check_saida !== false && $check_saida->rowCount() == 0) {$pdo->exec("ALTER TABLE visitas ADD COLUMN hora_saida TIME DEFAULT NULL AFTER hora_visita");
    }
} catch (Exception $e) {}

$mensagem = '';$tipo_mensagem = '';

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$modo_editar = !empty($id);

if (isset($_GET['editado']) && $_GET['editado'] == 1) {$mensagem = "Registo guardado com sucesso!";
    $tipo_mensagem = "sucesso";
}

// --- CARREGAR LISTAS E FILTROS DINÂMICOS DE CLIENTES ---
$colaboradores =$pdo->query("SELECT id, nome FROM utilizadores WHERE perfil IN ('admin', 'funcionario') AND ativo = 1 ORDER BY nome ASC")->fetchAll(PDO::FETCH_ASSOC);

// 1. Clientes Gerais (Visitas Comerciais)
$clientes_geral =$pdo->query("SELECT id, nome, morada, codigo_postal, cidade FROM clientes WHERE ativo = 1 ORDER BY nome ASC")->fetchAll(PDO::FETCH_ASSOC);

// 2. Clientes apenas com Obras (Planeamento ou Em Curso) - CORRIGIDO PARA OS ESTADOS REAIS DA BD
$clientes_obras =$pdo->query("
    SELECT DISTINCT c.id, c.nome, c.morada, c.codigo_postal, c.cidade
    FROM clientes c
    INNER JOIN obras o ON c.id = o.id_cliente
    WHERE c.ativo = 1 AND o.estado IN ('Planeamento', 'Em Curso')
    ORDER BY c.nome ASC
")->fetchAll(PDO::FETCH_ASSOC);

// 3. Clientes apenas com Orçamentos (Rascunho ou Enviado) - CORRIGIDO PARA OS ESTADOS REAIS DA BD
$clientes_orcamentos =$pdo->query("
    SELECT DISTINCT c.id, c.nome, c.morada, c.codigo_postal, c.cidade
    FROM clientes c
    INNER JOIN orcamentos o ON c.id = o.id_cliente
    WHERE c.ativo = 1 AND o.estado IN ('Rascunho', 'Enviado')
    ORDER BY c.nome ASC
")->fetchAll(PDO::FETCH_ASSOC);


$contexto_default = 'cliente';$nome_cliente_bd = '';
$cliente_bd_info = null; // Dados completos do cliente gravado (inclui morada)

if ($modo_editar) {
    $stmt =$pdo->prepare("SELECT * FROM visitas WHERE id = ?");
    $stmt->execute([$id]);
    $visita =$stmt->fetch(PDO::FETCH_ASSOC);

    if (!$visita) { header("Location: visitas.php"); exit; }
    $titulo_pagina = "Editar Registo";
    $referencia_base_js =$visita['referencia_visita'];

    if (!empty($visita['id_obra'])) {
        if (stripos($visita['assunto'], 'Reunião') !== false) {
            $contexto_default = 'reuniao';
        } else {
            $contexto_default = 'obra';
        }
    } elseif (!empty($visita['id_orcamento'])) {$contexto_default = 'orcamento';
    }
    $grupo = !empty($visita['id_obra']) ? 'obra' : 'comercial';
    $lev_db = levCarregar($pdo, $id);

    // Buscar o nome do cliente atual para garantir que não desaparece da dropdown
    if (!empty($visita['id_cliente'])) {
        $stmt_nc =$pdo->prepare("SELECT id, nome, morada, codigo_postal, cidade FROM clientes WHERE id = ?");
        $stmt_nc->execute([$visita['id_cliente']]);
        $cliente_bd_info =$stmt_nc->fetch(PDO::FETCH_ASSOC) ?: null;
        $nome_cliente_bd = $cliente_bd_info['nome'] ?? '';
    }

} else {
    $titulo_pagina = "Novo Registo / Visita";

    // --- LÓGICA DO NUMERADOR GLOBAL ---
    $ano_atual_curto = date('y');
    $stmt_ref =$pdo->query("SELECT valor FROM configuracoes WHERE chave = 'ultimo_numero_projeto'");
    $ultima_ref =$stmt_ref->fetchColumn();

    if ($ultima_ref && substr($ultima_ref, 0, 2) ===$ano_atual_curto) {
        $referencia_gerada = (string)((int)$ultima_ref + 1);
        if ((int)$referencia_gerada < (int)($ano_atual_curto . "0100")) {
            $referencia_gerada =$ano_atual_curto . "0100";
        }
    } else {
        $referencia_gerada =$ano_atual_curto . "0100";
    }

    $visita = ['referencia_visita' =>$referencia_gerada, 'estado' => 'Não processada'];
    $referencia_base_js =$referencia_gerada;

    // Vindo dos Relatórios de Obra: ?tipo=obra|reuniao (&id_obra=X)
    $tipo_get = $_GET['tipo'] ?? '';
    if (in_array($tipo_get, ['obra', 'reuniao'], true)) {
        $contexto_default = $tipo_get;
        $grupo = 'obra';
        $titulo_pagina = ($tipo_get === 'reuniao') ? 'Nova Reunião de Obra' : 'Novo Acompanhamento de Obra';
        $id_obra_get = filter_input(INPUT_GET, 'id_obra', FILTER_VALIDATE_INT);
        if ($id_obra_get) {
            $st_ob = $pdo->prepare("SELECT o.id, o.id_cliente, o.referencia_obra FROM obras o WHERE o.id = ?");
            $st_ob->execute([$id_obra_get]);
            if ($ob = $st_ob->fetch(PDO::FETCH_ASSOC)) {
                $visita['id_obra'] = $ob['id'];
                $visita['id_cliente'] = $ob['id_cliente'];
                if (!empty($ob['referencia_obra'])) $visita['referencia_visita'] = $ob['referencia_obra'];
                $stmt_nc = $pdo->prepare("SELECT id, nome, morada, codigo_postal, cidade FROM clientes WHERE id = ?");
                $stmt_nc->execute([$ob['id_cliente']]);
                $cliente_bd_info = $stmt_nc->fetch(PDO::FETCH_ASSOC) ?: null;
                $nome_cliente_bd = $cliente_bd_info['nome'] ?? '';
            }
        }
    } else {
        $grupo = 'comercial';
    }

    // Converter uma chamada recebida (visitas.php → separador "Chamadas Recebidas"): ?contacto=ID
    // O cliente é procurado pelo contacto (telefone / email) ou pelo nome; se não existir, abre "Criar novo cliente"
    // já com o nome, a morada e o contacto. A descrição da chamada vai para as notas.
    $id_contacto_get = filter_input(INPUT_GET, 'contacto', FILTER_VALIDATE_INT);
    if ($id_contacto_get && $grupo === 'comercial') {
        try {
            $st_ch = $pdo->prepare("SELECT ch.*, u.nome AS registado_nome FROM visitas_contactos ch LEFT JOIN utilizadores u ON u.id = ch.registado_por WHERE ch.id = ? AND ch.estado = 'Nova'");
            $st_ch->execute([$id_contacto_get]);
            $chamada = $st_ch->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (Exception $e) { $chamada = null; }
        if ($chamada) {
            $titulo_pagina = 'Converter Chamada em Visita';
            $contexto_default = 'cliente';
            $visita['assunto'] = 'Pedido de contacto telefónico';
            $visita['notas'] = "Chamada recebida em " . date('d/m/Y', strtotime($chamada['data_contacto'] ?: 'now'))
                             . (!empty($chamada['registado_nome']) ? " (registada por " . $chamada['registado_nome'] . ")" : '')
                             . "\nContacto: " . $chamada['contacto']
                             . (!empty($chamada['morada']) ? "\nMorada: " . $chamada['morada'] : '')
                             . (trim((string)$chamada['descricao']) !== '' ? "\n\n" . trim($chamada['descricao']) : '');
            // Procurar cliente já existente
            $contacto_ch = trim((string)$chamada['contacto']);
            $tel_ch = preg_replace('/[^0-9]/', '', $contacto_ch);
            if (strlen($tel_ch) > 9) $tel_ch = substr($tel_ch, -9);   // tira o indicativo (+351 / 00351)
            $cli_enc = null;
            try {
                if (strpos($contacto_ch, '@') !== false) {
                    $st_c = $pdo->prepare("SELECT id, nome, morada, codigo_postal, cidade FROM clientes WHERE ativo = 1 AND LOWER(email) = LOWER(?) LIMIT 1");
                    $st_c->execute([$contacto_ch]);
                    $cli_enc = $st_c->fetch(PDO::FETCH_ASSOC) ?: null;
                } elseif (strlen($tel_ch) === 9) {
                    $st_c = $pdo->prepare("SELECT id, nome, morada, codigo_postal, cidade FROM clientes WHERE ativo = 1 AND RIGHT(REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(telefone, ''), ' ', ''), '-', ''), '.', ''), '+', ''), 9) = ? LIMIT 1");
                    $st_c->execute([$tel_ch]);
                    $cli_enc = $st_c->fetch(PDO::FETCH_ASSOC) ?: null;
                }
                if (!$cli_enc) {
                    $st_c = $pdo->prepare("SELECT id, nome, morada, codigo_postal, cidade FROM clientes WHERE ativo = 1 AND LOWER(TRIM(nome)) = LOWER(TRIM(?)) LIMIT 1");
                    $st_c->execute([$chamada['nome']]);
                    $cli_enc = $st_c->fetch(PDO::FETCH_ASSOC) ?: null;
                }
            } catch (Exception $e) {}
            if ($cli_enc) {
                $visita['id_cliente'] = $cli_enc['id'];
                $cliente_bd_info = $cli_enc;
                $nome_cliente_bd = $cli_enc['nome'];
            }
        }
    }
}
$chamada = $chamada ?? null;
$cliente_chamada_encontrado = !empty($chamada) && !empty($visita['id_cliente']);

$lev_db = $lev_db ?? [];

// Acompanhamentos e reuniões de obra: só para utilizadores do tipo admin
if ($grupo === 'obra' && $perfil_user !== 'admin') {
    header("Location: " . ($modo_editar ? 'visitas.php' : 'relatorios.php') . "?erro=sem_permissao");
    exit;
}

// O pedido foi maior do que o servidor aceita (normalmente: demasiadas fotografias grandes de uma vez).
// O PHP descarta tudo e o $_POST chega vazio.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($_POST) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
    $mensagem = "Nada foi gravado: as fotografias, todas juntas, excedem o máximo que o servidor aceita (" . ini_get('post_max_size') . "). Grave com menos fotografias de cada vez.";
    $tipo_mensagem = "erro";
}

// GRAVAÇÃO (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST)) {
    try {
        // REGRA DE OURO 7: token CSRF
        if (!validar_csrf_token($_POST['csrf_token'] ?? '')) throw new Exception("A sessão expirou ou o pedido não é válido. Atualize a página e grave de novo.");

        $pdo->beginTransaction();

        $contexto = $_POST['contexto_visita'];$id_cliente = null;
        $id_obra = null;
        $id_orcamento = null;
        $referencia_post = trim($_POST['referencia_visita'] ?? '');
        $estado_post =$_POST['estado'] ?? 'Não processada';

        $id_cliente = !empty($_POST['id_cliente']) ?$_POST['id_cliente'] : null;

        // Criar Cliente Novo (se selecionado)
        if ($id_cliente === 'novo') {
            $stmt_cli_novo =$pdo->prepare("INSERT INTO clientes (nome, nif, telefone, email, morada, codigo_postal, cidade, ativo) VALUES (?, ?, ?, ?, ?, ?, ?, 1)");
            $stmt_cli_novo->execute([
                trim($_POST['nc_nome']), trim($_POST['nc_nif'] ?? ''), trim($_POST['nc_telefone'] ?? ''),
                trim($_POST['nc_email'] ?? ''), trim($_POST['nc_morada'] ?? ''), trim($_POST['nc_cp'] ?? ''), trim($_POST['nc_cidade'] ?? '')
            ]);
            $id_cliente =$pdo->lastInsertId();
            registrarLog($pdo, "Criou Novo Cliente via Visita (Cliente ID: $id_cliente)", "Clientes");
        }

        if (!$id_cliente) throw new Exception("É obrigatório associar a um cliente.");

        // Atribuir IDs baseados no contexto
        if (in_array($contexto, ['obra', 'reuniao'])) {$id_obra = !empty($_POST['id_obra_dinamica']) ?$_POST['id_obra_dinamica'] : null;
            if ($id_obra) {
                $stmt_cli_obra =$pdo->prepare("SELECT referencia_obra FROM obras WHERE id = ?");
                $stmt_cli_obra->execute([$id_obra]);
                $ref =$stmt_cli_obra->fetchColumn();
                if ($ref) $referencia_post =$ref;
            }
        } elseif ($contexto === 'orcamento') {$id_orcamento = !empty($_POST['id_orcamento_dinamico']) ?$_POST['id_orcamento_dinamico'] : null;
            if ($id_orcamento) {
                $stmt_cli_orc =$pdo->prepare("SELECT numero_orcamento FROM orcamentos WHERE id = ?");
                $stmt_cli_orc->execute([$id_orcamento]);
                $ref =$stmt_cli_orc->fetchColumn();
                if ($ref) $referencia_post =$ref;
            }
        }

        // Reunião de obra: o assunto tem de conter "Reunião" (é assim que o tipo é reconhecido nas listagens e no PDF)
        if ($contexto === 'reuniao' && stripos($_POST['assunto'] ?? '', 'Reunião') === false) {
            $_POST['assunto'] = 'Reunião de Obra - ' . trim($_POST['assunto'] ?? '');
        }
        $grupo = in_array($contexto, ['obra', 'reuniao']) ? 'obra' : 'comercial';
        if ($grupo === 'obra' && $perfil_user !== 'admin') throw new Exception("Sem permissão para registos de obra.");

        // --- GESTÃO DE FOTOS ---
        $fotos_atuais = [];
        if ($modo_editar && !empty($visita['fotos'])) {
            $fotos_atuais = json_decode($visita['fotos'], true) ?: [];
        }

        // Só se retiram fotos que pertencem a esta visita (o nome vem do browser: nunca se usa um caminho)
        $fotos_a_remover = json_decode($_POST['fotos_remover'] ?? '[]', true);
        $fotos_retiradas = [];
        if (is_array($fotos_a_remover) && $fotos_a_remover) {
            foreach ($fotos_a_remover as $rm_foto) {
                $rm_foto = basename((string)$rm_foto);
                if (in_array($rm_foto, $fotos_atuais, true)) $fotos_retiradas[] = $rm_foto;
            }
            $fotos_atuais = array_values(array_diff($fotos_atuais, $fotos_retiradas));
        }

        $novas_fotos = [];
        if (!empty($_FILES['fotos']['name']) && is_array($_FILES['fotos']['name'])) {
            $upload_dir = 'imagens/';
            if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);

            foreach ($_FILES['fotos']['name'] as $k => $name) {
                $erro_up = $_FILES['fotos']['error'][$k] ?? UPLOAD_ERR_NO_FILE;
                if ($erro_up !== UPLOAD_ERR_OK) {
                    if ($msg = erroUploadFoto($erro_up, $name)) $avisos_fotos[] = $msg;
                    continue;
                }
                $tmp = $_FILES['fotos']['tmp_name'][$k];
                $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
                $tipo_real = @getimagesize($tmp);
                if (!in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp']) || !$tipo_real) {
                    $avisos_fotos[] = "\"" . basename($name) . "\" não é uma imagem aceite (JPG, PNG ou WEBP).";
                    continue;
                }
                $novo_nome = uniqid('visita_', true) . '.' . $ext;
                $novo_nome = str_replace('.', '_', pathinfo($novo_nome, PATHINFO_FILENAME)) . '.' . $ext;
                if (move_uploaded_file($tmp, $upload_dir . $novo_nome)) {
                    $novas_fotos[] = $novo_nome;
                } else {
                    $avisos_fotos[] = "\"" . basename($name) . "\" não pôde ser guardada no servidor.";
                }
            }
        }

        $todas_fotos_json = json_encode(array_merge($fotos_atuais,$novas_fotos));

        // Hora de Saída é opcional (a visita pode ainda estar a decorrer)
        $hora_saida_post = !empty($_POST['hora_saida']) ? $_POST['hora_saida'] : null;

        $dados = [$id_cliente,
            $id_obra,$id_orcamento,
            $referencia_post,$_POST['colaborador_1'],
            $_POST['data_visita'],$_POST['hora_visita'],
            $hora_saida_post,
            trim($_POST['assunto']),
            trim($_POST['notas']),
            $estado_post,$todas_fotos_json
        ];

        if ($modo_editar) {
            $dados[] =$id;
            $sql_upd = "UPDATE visitas SET id_cliente=?, id_obra=?, id_orcamento=?, referencia_visita=?, colaborador_1=?, data_visita=?, hora_visita=?, hora_saida=?, assunto=?, notas=?, estado=?, fotos=? WHERE id=?";
            $pdo->prepare($sql_upd)->execute($dados);
            $visita_id_atual =$id;
        } else {
            $sql_ins = "INSERT INTO visitas (id_cliente, id_obra, id_orcamento, referencia_visita, colaborador_1, data_visita, hora_visita, hora_saida, assunto, notas, estado, fotos) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)";
            $pdo->prepare($sql_ins)->execute($dados);
            $visita_id_atual =$pdo->lastInsertId();

            if ($contexto === 'cliente') {
                $stmt_seq =$pdo->prepare("INSERT INTO configuracoes (chave, valor) VALUES ('ultimo_numero_projeto', ?) ON DUPLICATE KEY UPDATE valor = ?");
                $stmt_seq->execute([$referencia_post,$referencia_post]);
            }
        }

        // --- LEVANTAMENTO DE NECESSIDADES (só na Visita Comercial) ---
        if ($contexto === 'cliente' && !empty($_POST['lev_ativo'])) {
            $lev_post = $_POST['lev'] ?? [];
            $lev_antigo = levCarregar($pdo, $visita_id_atual);
            $pdo->prepare("DELETE FROM visitas_levantamento WHERE id_visita = ?")->execute([$visita_id_atual]);
            $st_lev = $pdo->prepare("INSERT INTO visitas_levantamento (id_visita, area, ordem, dados, prioridade, orcamento_cliente, fazer_orcamento, notas, fotos) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $ordem_lev = 0;
            $fotos_a_apagar = [];
            foreach ($LEV_AREAS as $area_k => $area_def) {
                $fotos_antigas = $lev_antigo[$area_k]['fotos'] ?? [];
                if (empty($lev_post[$area_k]['ativo'])) {
                    // Área desmarcada: as fotos dela deixam de ser usadas
                    $fotos_a_apagar = array_merge($fotos_a_apagar, $fotos_antigas);
                    continue;
                }
                $a = $lev_post[$area_k];
                $dados_area = [];
                foreach ($area_def['campos'] as $ck => $cdef) {
                    if (!levCampoAtivo($cdef, $a['dados'] ?? [])) continue; // campo escondido (ex: pré-instalação num sistema via rádio)
                    $val = $a['dados'][$ck] ?? '';
                    if ($cdef[1] === 'multi') {
                        $val = array_values(array_intersect((array)$val, $cdef[2]));
                        if ($val) $dados_area[$ck] = $val;
                    } else {
                        $val = trim((string)$val);
                        if ($cdef[1] === 'sel' && !in_array($val, $cdef[2], true)) $val = '';
                        if ($cdef[1] === 'sn' && !in_array($val, ['Sim', 'Não'], true)) $val = '';
                        if ($cdef[1] === 'num') $val = ($val === '') ? '' : (string)max(0, (int)$val);
                        if ($val !== '') $dados_area[$ck] = $val;
                    }
                }
                // Linhas da área (trabalhos, câmaras, portas, equipamentos...)
                if (!empty($area_def['linhas'])) {
                    $linhas_area = levLimparLinhas($area_def['linhas'], $a['linhas'] ?? []);
                    if ($linhas_area) $dados_area['_linhas'] = $linhas_area;
                }

                // Fotos: as que ficaram + as novas
                $fotos_manter = array_values(array_intersect((array)($a['fotos_manter'] ?? []), $fotos_antigas));
                $fotos_a_apagar = array_merge($fotos_a_apagar, array_diff($fotos_antigas, $fotos_manter));
                if (!empty($_FILES['lev_fotos']['name'][$area_k])) {
                    if (!is_dir('imagens/')) mkdir('imagens/', 0777, true);
                    foreach ($_FILES['lev_fotos']['name'][$area_k] as $fi => $fname) {
                        $erro_up = $_FILES['lev_fotos']['error'][$area_k][$fi] ?? UPLOAD_ERR_NO_FILE;
                        if ($erro_up !== UPLOAD_ERR_OK) { if ($msg = erroUploadFoto($erro_up, $fname)) $avisos_fotos[] = $msg; continue; }
                        $ext = strtolower(pathinfo($fname, PATHINFO_EXTENSION));
                        if (!in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'])) { $avisos_fotos[] = "\"" . basename($fname) . "\" não é uma imagem aceite (JPG, PNG ou WEBP)."; continue; }
                        $novo = str_replace('.', '_', uniqid('lev_' . $area_k . '_', true)) . '.' . $ext;
                        if (move_uploaded_file($_FILES['lev_fotos']['tmp_name'][$area_k][$fi], 'imagens/' . $novo)) $fotos_manter[] = $novo;
                    }
                }
                $prioridade = in_array($a['prioridade'] ?? '', $LEV_PRIORIDADES, true) ? $a['prioridade'] : null;
                $st_lev->execute([
                    $visita_id_atual, $area_k, ++$ordem_lev,
                    json_encode($dados_area, JSON_UNESCAPED_UNICODE),
                    $prioridade,
                    trim($a['orcamento_cliente'] ?? '') ?: null,
                    !empty($a['fazer_orcamento']) ? 1 : 0,
                    trim($a['notas'] ?? '') ?: null,
                    json_encode(array_values($fotos_manter))
                ]);
            }
            foreach (array_unique($fotos_a_apagar) as $fa) arquivarFotoVisita($fa);
        }

        // Visita criada a partir de uma chamada recebida: a chamada fica "Convertida" e ligada à visita
        $id_contacto_post = filter_input(INPUT_POST, 'id_contacto', FILTER_VALIDATE_INT);
        if (!$modo_editar && $id_contacto_post) {
            try {
                $pdo->prepare("UPDATE visitas_contactos SET estado = 'Convertida', id_visita = ?, tratado_por = ?, tratado_em = NOW() WHERE id = ? AND estado = 'Nova'")
                    ->execute([$visita_id_atual, $_SESSION['user_id'], $id_contacto_post]);
            } catch (Exception $e) {}
        }

        $pdo->commit();
        // Fotos retiradas: só depois de gravado (se a gravação falhar, continuam onde estavam)
        foreach ($fotos_retiradas as $fr) arquivarFotoVisita($fr);
        registrarLog($pdo, ($modo_editar ? "Editou" : "Criou") . " Registo #" . $visita_id_atual, "Visitas");
        if ($avisos_fotos) $_SESSION['avisos_fotos_visita'] = $avisos_fotos;

        $redirect =$_POST['redirect_action'] ?? 'voltar';

        if ($redirect === 'imprimir') {
            header("Location: visita_form.php?id=$visita_id_atual&acao=imprimir");
        } elseif ($redirect === 'ficar') {
            header("Location: visita_form.php?id=$visita_id_atual&editado=1");
        } else {
            header("Location: " . ($grupo === 'obra' ? 'relatorios.php?tab=acomp&editado=1' : 'visitas.php?editado=1'));
        }
        exit;

    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ($e instanceof PDOException) {
            error_log('visita_form.php: ' . $e->getMessage());
            $mensagem = "Não foi possível gravar. Tente de novo; se o erro continuar, avise o administrador.";
        } else {
            $mensagem = "Erro na gravação: " . $e->getMessage();
        }
        $tipo_mensagem = "erro";
    }
}
?>
<!DOCTYPE html>
<html lang="pt-PT">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php /* REGRA DE OURO 8: ícone MAB no separador */ ?>
    <link rel="icon" type="image/png" href="/mab.png">
    <link rel="apple-touch-icon" href="/mab.png">
    <title><?= $titulo_pagina ?> - Gestão MAB</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { font-family: system-ui, -apple-system, sans-serif; box-sizing: border-box; -webkit-tap-highlight-color: transparent; }
        body { background-color: #f7f9fc; margin: 0; color: #333; font-size: 14px; padding-bottom: 100px; }
        input, select, textarea, button { font-family: inherit; font-size: 14px; outline: none; }

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

        .container { max-width: 1000px; margin: 15px auto; padding: 0 15px; }

        .header-box { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
        .header-box h1 { margin: 0; font-size: 18px; color: #2c3e50; }
        .btn-print { background: #fff; color: #3498db; border: 1px solid #dcdde1; padding: 8px 15px; border-radius: 8px; text-decoration: none; font-weight: 600; display: inline-flex; align-items: center; gap: 8px; transition: 0.2s; cursor: pointer; }
        .btn-print:hover { border-color: #3498db; background: #f0f8ff; }

        .card { background: #fff; padding: 25px; border-radius: 12px; border: 1px solid #e1e5ea; box-shadow: 0 2px 10px rgba(0,0,0,0.03); margin-bottom: 15px; }
        h2 { font-size: 14px; color: #e84393; margin: 0 0 15px 0; border-bottom: 1px solid #f1f2f6; padding-bottom: 8px; text-transform: uppercase; display: flex; align-items: center; gap: 8px; }

        .form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 15px; }
        .form-group { display: flex; flex-direction: column; margin-bottom: 15px; position: relative; }
        .form-group.full { grid-column: 1 / -1; }
        label { font-weight: 600; margin-bottom: 6px; font-size: 11px; color: #576574; text-transform: uppercase; }
        input, select, textarea { padding: 12px; border: 1px solid #dcdde1; border-radius: 8px; transition: 0.2s; background: #fff; width: 100%; }
        input:focus, select:focus, textarea:focus { border-color: #e84393; }

        .hidden-section { display: none; margin-bottom: 15px; padding: 15px; background: #fdfdfd; border-radius: 8px; border: 1px dashed #dcdde1; }

        .input-btn-group { display: flex; gap: 8px; }
        .btn-add { background: #e84393; color: #fff; border: none; border-radius: 8px; width: 45px; cursor: pointer; transition: 0.2s; display: flex; justify-content: center; align-items: center; font-size: 16px; }
        .btn-add:hover { background: #d81b60; }

        /* GESTÃO DE FOTOS */
        .foto-upload-area { border: 2px dashed #dcdde1; border-radius: 8px; padding: 20px; text-align: center; background: #f8f9fa; cursor: pointer; transition: 0.2s; }
        .foto-upload-area:hover { border-color: #e84393; background: #fdf2f2; }
        .foto-upload-area i { font-size: 30px; color: #95a5a6; margin-bottom: 10px; display: block; }

        .fotos-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(120px, 1fr)); gap: 15px; margin-top: 15px; }
        .foto-item { position: relative; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 5px rgba(0,0,0,0.1); aspect-ratio: 1; background: #eee; }
        .foto-item img { width: 100%; height: 100%; object-fit: cover; }
        .btn-rm-foto { position: absolute; top: 5px; right: 5px; background: rgba(231, 76, 60, 0.9); color: white; border: none; border-radius: 50%; width: 25px; height: 25px; cursor: pointer; display: flex; align-items: center; justify-content: center; font-size: 12px; transition: 0.2s; }
        .btn-rm-foto:hover { background: #c0392b; transform: scale(1.1); }

        .floating-action-bar { position: fixed; bottom: 20px; right: 20px; left: 20px; max-width: 1000px; margin: 0 auto; background: #fff; padding: 15px 25px; border-radius: 12px; box-shadow: 0 -5px 20px rgba(0,0,0,0.15); display: flex; justify-content: flex-end; border: 1px solid #e1e5ea; z-index: 1000; }
        .btn-save { background: #e84393; color: #fff; padding: 12px 30px; border-radius: 8px; border: none; font-weight: 700; font-size: 14px; text-transform: uppercase; cursor: pointer; transition: 0.2s; display: flex; align-items: center; gap: 8px; }
        .btn-save:hover { background: #d81b60; }

        .alert { padding: 15px; border-radius: 8px; margin-bottom: 15px; text-align: center; font-weight: 600; }
        .alert-erro { background: #fdeded; color: #e84393; border: 1px solid #f9cccc; }
        .alert-sucesso { background: #e8f5e9; color: #2e7d32; border: 1px solid #c8e6c9; }

        .modal { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.6); z-index: 1001; justify-content: center; align-items: center; padding: 20px; }
        .modal.show { display: flex; }
        .modal-content { background: #fff; padding: 30px; border-radius: 12px; width: 100%; max-width: 400px; text-align: center; }
        .modal-btns { display: flex; flex-direction: column; gap: 10px; margin-top: 20px; }
        .btn-primary { background: #e84393; color: #fff; padding: 14px; border-radius: 8px; text-decoration: none; font-weight: 600; font-size: 15px; border: none; cursor: pointer; }
        .btn-secondary { background: #f1f2f6; color: #576574; padding: 14px; border-radius: 8px; text-decoration: none; font-weight: 600; font-size: 15px; border: none; cursor: pointer; }

        .form-grid > * { min-width: 0; }

        /* LEVANTAMENTO DE NECESSIDADES */
        .lev-intro { font-size: 12px; color: #7f8c8d; margin: -5px 0 15px; }
        .lev-grupos { display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px; margin-bottom: 10px; }
        .lev-grupo { border: 1px solid #e1e5ea; border-radius: 10px; padding: 12px; background: #fcfdfe; }
        .lev-grupo-tit { font-size: 11px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.4px; margin-bottom: 10px; display: flex; align-items: center; gap: 6px; }
        .lev-chips-areas { display: flex; flex-wrap: wrap; gap: 6px; }
        .lev-chip-area { border: 1px solid #dcdde1; background: #fff; color: #2c3e50; border-radius: 20px; padding: 8px 12px; font-size: 13px; font-weight: 600; cursor: pointer; display: inline-flex; align-items: center; gap: 6px; font-family: inherit; }
        .lev-chip-area.on { color: #fff; border-color: transparent; }
        .lev-area { display: none; border: 1px solid #e1e5ea; border-left: 4px solid; border-radius: 10px; margin-top: 14px; overflow: hidden; }
        .lev-area.on { display: block; }
        .lev-area-head { padding: 12px 15px; font-weight: 800; font-size: 14px; display: flex; align-items: center; gap: 8px; background: #f8f9fa; }
        .lev-area-head small { margin-left: auto; font-weight: 600; font-size: 11px; color: #95a5a6; }
        .lev-area-body { padding: 15px; }
        .lev-campos { display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px 15px; }
        .lev-campos > * { min-width: 0; }
        .lev-campo { display: flex; flex-direction: column; gap: 5px; }
        .lev-campo.lev-multi { grid-column: 1 / -1; }
        .lev-campo > label { margin: 0; }
        .lev-seg { display: inline-flex; border: 1px solid #dcdde1; border-radius: 8px; overflow: hidden; width: fit-content; }
        .lev-seg label { margin: 0; padding: 10px 18px; font-size: 13px; font-weight: 700; text-transform: none; cursor: pointer; background: #fff; border-right: 1px solid #dcdde1; color: #576574; }
        .lev-seg label:last-child { border-right: none; }
        .lev-seg input { display: none; }
        .lev-seg label:has(input[value="Sim"]:checked) { background: #27ae60; color: #fff; }
        .lev-seg label:has(input[value="Não"]:checked) { background: #95a5a6; color: #fff; }
        .lev-num { display: flex; align-items: stretch; width: fit-content; }
        .lev-num button { width: 42px; border: 1px solid #dcdde1; background: #f8f9fa; font-size: 18px; font-weight: 700; cursor: pointer; color: #2c3e50; }
        .lev-num button:first-child { border-radius: 8px 0 0 8px; }
        .lev-num button:last-child { border-radius: 0 8px 8px 0; }
        .lev-num input { width: 70px; text-align: center; border-radius: 0; border-left: none; border-right: none; -moz-appearance: textfield; }
        .lev-num input::-webkit-outer-spin-button, .lev-num input::-webkit-inner-spin-button { -webkit-appearance: none; margin: 0; }
        .lev-chips { display: flex; flex-wrap: wrap; gap: 6px; }
        .lev-chips label { margin: 0; text-transform: none; font-size: 13px; font-weight: 600; color: #2c3e50; border: 1px solid #dcdde1; border-radius: 20px; padding: 7px 12px; cursor: pointer; display: inline-flex; align-items: center; gap: 6px; background: #fff; }
        .lev-chips input { width: auto; margin: 0; }
        .lev-chips label:has(input:checked) { background: #fdf2f2; border-color: #e84393; color: #c2185b; }
        .lev-campos:empty { display: none; }
        .lev-campo.lev-escondido { display: none; }
        .lev-sugestao { display: none; margin-top: 8px; border-radius: 8px; padding: 9px 12px; font-size: 12px; font-weight: 600; line-height: 1.4; }
        .lev-sugestao.ok { display: block; background: #e8f8f5; border: 1px solid #a3e4d7; color: #117a65; }
        .lev-estimativa { display: none; margin-top: 8px; border-radius: 8px; padding: 9px 12px; font-size: 12px; font-weight: 600; line-height: 1.5; background: #eaf2fb; border: 1px solid #aed1f0; color: #1f5f8b; }
        .lev-estimativa.on { display: block; }
        .lev-sugestao.falta { display: block; background: #fff8e1; border: 1px solid #ffe082; color: #8a6d00; }
        .lev-obrig { color: #e74c3c; }
        .lev-campo.lev-falta select, .lev-campo.lev-falta .lev-seg { border-color: #e74c3c; box-shadow: 0 0 0 3px rgba(231,76,60,0.15); }
        .lev-aviso { display: none; margin-top: 6px; background: #fff8e1; border: 1px solid #ffe082; color: #8a6d00; border-radius: 8px; padding: 8px 10px; font-size: 12px; font-weight: 600; line-height: 1.4; }
        .lev-campo.lev-com-aviso .lev-aviso { display: block; }
        .lev-campo.lev-com-aviso { grid-column: span 2; }
        @media (max-width: 560px) { .lev-campo.lev-com-aviso { grid-column: auto; } }
        .lev-linhas { margin-top: 15px; }
        .lev-linhas-tit { font-size: 11px; font-weight: 800; text-transform: uppercase; color: #2c3e50; letter-spacing: 0.4px; margin-bottom: 8px; }
        .lev-linhas-cont { color: #95a5a6; font-weight: 600; }
        .lev-linha { display: flex; gap: 8px; align-items: flex-end; padding: 8px; border: 1px solid #eef1f4; border-radius: 8px; margin-bottom: 6px; background: #fcfdfe; }
        .lev-cel { display: flex; flex-direction: column; gap: 3px; flex: 1 1 0; min-width: 0; }
        .lev-cel label { margin: 0; font-size: 10px; }
        .lev-cel select, .lev-cel input { padding: 9px; }
        .lev-cel-num { flex: 0 0 80px; }
        .lev-linha:not(:first-child) .lev-cel label { display: none; }
        .lev-linha-btns { display: flex; gap: 4px; flex-shrink: 0; }
        .lev-linha-btns button { width: 36px; height: 38px; border-radius: 8px; border: 1px solid #dcdde1; background: #fff; cursor: pointer; font-size: 13px; }
        /* Linhas com muitas colunas: partem em duas linhas e cada campo mostra o rótulo */
        .lev-linhas.larga .lev-linha { flex-wrap: wrap; align-items: flex-end; }
        .lev-linhas.larga .lev-cel { flex: 1 1 150px; }
        .lev-linhas.larga .lev-cel-num { flex: 0 1 110px; }
        .lev-linhas.larga .lev-linha .lev-cel label { display: block; }
        .lev-linhas.larga .lev-linha-btns { margin-left: auto; }
        .lb-add { color: #27ae60; } .lb-dup { color: #2980b9; } .lb-del { color: #e74c3c; }
        .lev-linha-btns button:hover { background: #f4f6f8; }
        .lev-linha-primeira { display: none; border: 1px dashed #bdc3c7; background: #fafbfc; color: #576574; border-radius: 8px; padding: 9px 14px; font-weight: 700; font-size: 12px; cursor: pointer; font-family: inherit; }
        .lev-linhas-lista:empty + template + .lev-linha-primeira { display: inline-flex; align-items: center; gap: 6px; }
        @media (max-width: 768px) {
            .lev-linha { flex-wrap: wrap; }
            .lev-cel { flex: 1 1 45%; }
            .lev-cel-num { flex: 0 0 90px; }
            .lev-linha .lev-cel label { display: block !important; }
            .lev-linha-btns { width: 100%; justify-content: flex-end; }
        }
        .lev-comuns { display: grid; grid-template-columns: 1fr 1fr auto; gap: 12px 15px; margin-top: 15px; padding-top: 15px; border-top: 1px dashed #e1e5ea; align-items: end; }
        .lev-comuns > * { min-width: 0; }
        .lev-fazer { display: flex; align-items: center; gap: 8px; text-transform: none; font-size: 13px; font-weight: 700; color: #2c3e50; padding: 11px 14px; border: 1px solid #dcdde1; border-radius: 8px; cursor: pointer; margin: 0; white-space: nowrap; }
        .lev-fazer input { width: auto; accent-color: #e84393; }
        .lev-fotos { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 8px; }
        .lev-foto { position: relative; width: 80px; height: 80px; border-radius: 8px; overflow: hidden; background: #eee; }
        .lev-foto img { width: 100%; height: 100%; object-fit: cover; }
        .lev-foto button { position: absolute; top: 3px; right: 3px; width: 22px; height: 22px; border-radius: 50%; border: none; background: rgba(231,76,60,0.9); color: #fff; cursor: pointer; font-size: 11px; }
        .lev-btn-foto { display: inline-flex; align-items: center; gap: 6px; border: 1px dashed #bdc3c7; border-radius: 8px; padding: 9px 14px; font-size: 12px; font-weight: 700; color: #576574; cursor: pointer; background: #fafbfc; text-transform: none; margin: 0; }
        @media (max-width: 900px) {
            .lev-grupos { grid-template-columns: 1fr; }
            .lev-campos { grid-template-columns: 1fr 1fr; }
        }
        @media (max-width: 560px) {
            .lev-campos { grid-template-columns: 1fr; }
            .lev-comuns { grid-template-columns: 1fr; }
            .lev-area-body { padding: 12px; }
        }
        @media (max-width: 1024px) { .topbar { padding: 10px 24px; } }
        @media (max-width: 768px) {
            .form-grid { grid-template-columns: 1fr; gap: 0; }
            .topbar { padding: 8px 15px; }
            .topbar-left .logo { height: 30px; }
            .btn-voltar { width: 38px; height: 38px; }
            .card { padding: 18px 15px; }
            .floating-action-bar { left: 15px; right: 15px; bottom: 15px; padding: 10px; }
            .btn-save { width: 100%; justify-content: center; }
        }
        @media (max-width: 560px) {
            .user-nome { display: none; }
            .user-btn { padding: 5px 10px 5px 5px; }
        }
    </style>
</head>
<body>

    <header class="topbar">
        <div class="topbar-left"><a href="index.php" title="Página inicial"><img src="mab.png" alt="MAB" class="logo"></a></div>
        <div class="topbar-right">
            <a href="<?= $grupo === 'obra' ? 'relatorios.php?tab=acomp' : 'visitas.php' ?>" class="btn-voltar" title="Voltar" aria-label="Voltar"><i class="fa-solid fa-arrow-left"></i></a>
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
        <?php $avisos_fotos_ver = $_SESSION['avisos_fotos_visita'] ?? []; unset($_SESSION['avisos_fotos_visita']);
        if ($avisos_fotos_ver): ?>
            <div class="alert alert-erro"><i class="fa-solid fa-triangle-exclamation"></i> Algumas fotografias não foram guardadas:<br><?= implode('<br>', array_map(fn($a) => htmlspecialchars($a, ENT_QUOTES, 'UTF-8'), $avisos_fotos_ver)) ?></div>
        <?php endif; ?>
        <?php if ($mensagem): ?>
            <div class="alert <?= $tipo_mensagem === 'sucesso' ? 'alert-sucesso' : 'alert-erro' ?>"><?= htmlspecialchars($mensagem, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>

        <div class="header-box">
            <h1><?= htmlspecialchars($titulo_pagina) ?></h1>
            <?php if ($modo_editar): ?>
                <button type="button" class="btn-print" onclick="window.open('visita_pdf.php?id=<?= $id ?>', '_blank')"><i class="fa-solid fa-print"></i> Imprimir</button>
            <?php endif; ?>
        </div>

        <form method="POST" id="formVisita" enctype="multipart/form-data">
            <input type="hidden" name="redirect_action" id="redirect_action" value="voltar">
            <?php /* REGRA DE OURO 7 */ ?><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(gerar_csrf_token()) ?>">
            <input type="hidden" name="fotos_remover" id="fotos_remover" value="[]">
            <?php if (!empty($chamada)): ?>
            <input type="hidden" name="id_contacto" value="<?= (int)$chamada['id'] ?>">
            <div class="alert" style="background:#fdf2f2; color:#ad1457; border:1px solid #fadbd8; text-align:left;">
                <i class="fa-solid fa-phone-volume"></i> A converter a chamada de <b><?= htmlspecialchars($chamada['nome']) ?></b> (<?= htmlspecialchars($chamada['contacto']) ?>).
                <?= $cliente_chamada_encontrado ? 'Já existe na ficha de clientes: <b>' . htmlspecialchars($nome_cliente_bd) . '</b> (pode escolher outro).' : 'Não existe na ficha de clientes: os dados vão para «Criar novo cliente» (confirme antes de gravar).' ?>
                Ao gravar, a chamada passa a «Convertida».
            </div>
            <?php endif; ?>

            <div class="card">
                <h2><i class="fa-solid fa-network-wired"></i> Tipo de Registo</h2>

                <div class="form-group" style="margin-bottom: 25px;">
                    <select name="contexto_visita" id="contexto_visita" onchange="alterarContexto()" style="background:#fdfdfd; font-weight:bold; color:#2c3e50; font-size:16px;">
                        <?php if ($grupo === 'comercial'): ?>
                        <option value="cliente" <?= $contexto_default === 'cliente' ? 'selected' : '' ?>>Visita Comercial / Angariação</option>
                        <option value="orcamento" <?= $contexto_default === 'orcamento' ? 'selected' : '' ?>>Acompanhamento de Orçamento</option>
                        <?php else: ?>
                        <option value="obra" <?= $contexto_default === 'obra' ? 'selected' : '' ?>>Acompanhamento de Obra</option>
                        <option value="reuniao" <?= $contexto_default === 'reuniao' ? 'selected' : '' ?>>Reunião de Obra</option>
                        <?php endif; ?>
                    </select>
                </div>

                <div class="form-grid">
                    <div class="form-group">
                        <label>Cliente Associado *</label>
                        <select name="id_cliente" id="id_cliente" onchange="carregarDocumentos()" required>
                            <option value="">-- Selecione o Cliente --</option>
                            <!-- Esta lista é substituída pelo Javascript consoante o contexto -->
                        </select>
                    </div>

                    <div class="form-group" id="bloco_doc_dinamico" style="display:none;">
                        <label id="label_doc_dinamico">Documento</label>
                        <select name="id_obra_dinamica" id="select_doc_dinamico" onchange="updateReferenciaDinamica()">
                            <option value="">-- Selecione o Cliente Primeiro --</option>
                        </select>
                        <small style="color:#7f8c8d; font-size:10px; margin-top:4px;" id="aviso_loading"></small>
                    </div>
                </div>

                <!-- Morada do cliente selecionado (só leitura) -->
                <div id="info_morada_cliente" style="display:none; margin-bottom:15px; padding:12px 15px; background:#f8f9fa; border:1px solid #e1e5ea; border-radius:8px; color:#2c3e50;">
                    <i class="fa-solid fa-location-dot" style="color:#e84393; margin-right:6px;"></i>
                    <span style="font-weight:600; font-size:11px; color:#576574; text-transform:uppercase; margin-right:6px;">Morada:</span>
                    <span id="texto_morada_cliente"></span>
                </div>

                <!-- Secção escondida para criação de novo cliente -->
                <div id="section_novo_cliente" class="hidden-section">
                    <h3 style="font-size:13px; color:#e84393; margin-top:0;"><i class="fa-solid fa-user-plus"></i> Dados do Novo Cliente</h3>
                    <div class="form-group"><label>Nome Completo *</label><input type="text" name="nc_nome" id="nc_nome"></div>
                    <div class="form-grid">
                        <div class="form-group"><label>NIF</label><input type="text" name="nc_nif"></div>
                        <div class="form-group"><label>Telefone</label><input type="text" name="nc_telefone"></div>
                    </div>
                    <div class="form-group"><label>Email</label><input type="email" name="nc_email"></div>

                    <!-- Botão de GPS Integrado na Morada do Novo Cliente -->
                    <div class="form-group full">
                        <label>Morada (Opcional)</label>
                        <div class="input-btn-group">
                            <input type="text" name="nc_morada" id="nc_morada">
                            <button type="button" class="btn-add" onclick="obterLocalizacaoGPSNovoCliente()" title="Obter localização atual via GPS" style="background:#2980b9;"><i class="fa-solid fa-location-crosshairs"></i></button>
                        </div>
                    </div>

                    <div class="form-grid">
                        <div class="form-group"><label>Cód. Postal</label><input type="text" name="nc_cp" id="nc_cp" placeholder="0000-000"></div>
                        <div class="form-group"><label>Cidade</label><input type="text" name="nc_cidade" id="nc_cidade"></div>
                    </div>
                </div>
            </div>

            <div class="card">
                <h2><i class="fa-solid fa-calendar-check"></i> Detalhes Base</h2>

                <div class="form-grid">
                    <div class="form-group">
                        <label>Referência Interna</label>
                        <input type="text" name="referencia_visita" id="input_referencia" value="<?= htmlspecialchars($visita['referencia_visita'] ?? '') ?>" readonly style="color: #e84393; background: #fdf2f2; border-color: #fadbd8; font-weight: bold;">
                    </div>

                    <div class="form-group">
                        <label>Estado do Processo</label>
                        <select name="estado" style="font-weight: bold; color: #2c3e50; border-color: #bdc3c7;">
                            <option value="Não processada" <?= (isset($visita) && $visita['estado'] === 'Não processada') ? 'selected' : '' ?>>Pendente / Em Aberto</option>
                            <option value="Processada" <?= (isset($visita) && $visita['estado'] === 'Processada') ? 'selected' : '' ?>>Processada / Resolvido</option>
                        </select>
                    </div>
                </div>

                <div class="form-grid">
                    <div class="form-group">
                        <label>Colaborador Responsável *</label>
                        <select name="colaborador_1" required>
                            <option value="">-- Selecione --</option>
                            <?php foreach($colaboradores as $c): ?>
                                <option value="<?= $c['id'] ?>" <?= (($visita['colaborador_1'] ?? ($modo_editar ? '' : ($_SESSION['user_id'] ?? ''))) == $c['id']) ? 'selected' : '' ?>><?= htmlspecialchars($c['nome']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Assunto Principal *</label>
                        <input type="text" name="assunto" id="input_assunto" value="<?= htmlspecialchars($visita['assunto'] ?? '') ?>" placeholder="Ex: Apresentação de Proposta, Vistoria Técnica..." required>
                    </div>
                </div>

                <div class="form-grid">
                    <div class="form-group">
                        <label>Data *</label>
                        <input type="date" name="data_visita" value="<?= $visita['data_visita'] ?? date('Y-m-d') ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Hora de Entrada *</label>
                        <input type="time" name="hora_visita" id="input_hora_entrada" value="<?= isset($visita['hora_visita']) ? substr($visita['hora_visita'], 0, 5) : date('H:i') ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Hora de Saída</label>
                        <input type="time" name="hora_saida" id="input_hora_saida" value="<?= !empty($visita['hora_saida']) ? substr($visita['hora_saida'], 0, 5) : '' ?>">
                    </div>
                </div>
            </div>

            <div class="card">
                <h2><i class="fa-solid fa-pen-clip"></i> Notas ou Resumo de Reunião</h2>
                <div class="form-group" style="margin-bottom:0;">
                    <textarea name="notas" rows="6" placeholder="Aponte os detalhes importantes discutidos com o cliente, decisões de obra ou requisitos técnicos..."><?= htmlspecialchars($visita['notas'] ?? '') ?></textarea>
                </div>
            </div>

            <!-- LEVANTAMENTO DE NECESSIDADES (Visita Comercial) -->
            <div class="card" id="card_levantamento" style="display:none;">
                <h2><i class="fa-solid fa-list-check"></i> Levantamento de Necessidades</h2>
                <p class="lev-intro">Toque nas áreas que interessam ao cliente. Só as escolhidas ficam no registo e no PDF.</p>
                <input type="hidden" name="lev_ativo" id="lev_ativo" value="0">
                <div class="lev-grupos">
                    <?php foreach ($LEV_GRUPOS as $gk => $g): ?>
                        <div class="lev-grupo">
                            <div class="lev-grupo-tit" style="color:<?= $g['cor'] ?>;"><i class="fa-solid <?= $g['icone'] ?>"></i> <?= htmlspecialchars($g['nome']) ?></div>
                            <div class="lev-chips-areas">
                                <?php foreach ($g['areas'] as $ak): $ad = $LEV_AREAS[$ak]; $on = isset($lev_db[$ak]); ?>
                                    <button type="button" class="lev-chip-area<?= $on ? ' on' : '' ?>" data-area="<?= $ak ?>" data-cor="<?= $g['cor'] ?>" style="<?= $on ? 'background:' . $g['cor'] . ';' : '' ?>" onclick="levToggleArea(this)"><i class="fa-solid <?= $ad['icone'] ?>"></i> <?= htmlspecialchars($ad['nome']) ?></button>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <?php foreach ($LEV_GRUPOS as $gk => $g): foreach ($g['areas'] as $ak): $ad = $LEV_AREAS[$ak]; $ld = $lev_db[$ak] ?? null; $dados_a = $ld['dados'] ?? []; ?>
                    <div class="lev-area<?= $ld ? ' on' : '' ?>" id="lev_area_<?= $ak ?>" style="border-left-color:<?= $g['cor'] ?>;">
                        <input type="hidden" name="lev[<?= $ak ?>][ativo]" class="lev-ativo-area" value="<?= $ld ? '1' : '' ?>">
                        <div class="lev-area-head" style="color:<?= $g['cor'] ?>;"><i class="fa-solid <?= $ad['icone'] ?>"></i> <?= htmlspecialchars($ad['nome']) ?> <small><?= htmlspecialchars($g['nome']) ?></small></div>
                        <div class="lev-area-body">
                            <div class="lev-campos">
                                <?php foreach ($ad['campos'] as $ck => $cdef) echo levCampoHTML($ak, $ck, $cdef, $ld ? ($dados_a[$ck] ?? '') : null); ?>
                            </div>
                            <?= levLinhasHTML($ak, $ad, $dados_a['_linhas'] ?? []) ?>
                            <?php if (!empty($ad['sugestao_detetores'])): $sg = $ad['sugestao_detetores']; ?>
                                <div class="lev-sugestao" data-campo-area="<?= htmlspecialchars($sg['campo_area']) ?>" data-m2="<?= (int)$sg['m2_por_detetor'] ?>" data-tipo="<?= htmlspecialchars($sg['tipo_contem']) ?>"></div>
                            <?php endif; ?>
                            <?php if (!empty($ad['estimativa_cabos'])): ?>
                                <div class="lev-estimativa" data-tipo="<?= htmlspecialchars($ad['estimativa_cabos']) ?>"></div>
                            <?php endif; ?>
                            <div class="lev-comuns">
                                <div class="lev-campo">
                                    <label>Prioridade do cliente</label>
                                    <select name="lev[<?= $ak ?>][prioridade]">
                                        <option value="">-</option>
                                        <?php foreach ($LEV_PRIORIDADES as $pr): ?><option <?= ($ld['prioridade'] ?? '') === $pr ? 'selected' : '' ?>><?= $pr ?></option><?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="lev-campo">
                                    <label>Orçamento que o cliente tem em mente</label>
                                    <input type="text" name="lev[<?= $ak ?>][orcamento_cliente]" value="<?= htmlspecialchars($ld['orcamento_cliente'] ?? '') ?>" placeholder="Ex: até 2.000 €">
                                </div>
                                <label class="lev-fazer"><input type="checkbox" name="lev[<?= $ak ?>][fazer_orcamento]" value="1" <?= (!$ld || !empty($ld['fazer_orcamento'])) ? 'checked' : '' ?>> Fazer orçamento</label>
                                <div class="lev-campo" style="grid-column:1/-1;">
                                    <label>Notas</label>
                                    <textarea name="lev[<?= $ak ?>][notas]" rows="2"><?= htmlspecialchars($ld['notas'] ?? '') ?></textarea>
                                </div>
                                <div class="lev-campo" style="grid-column:1/-1;">
                                    <label class="lev-btn-foto"><i class="fa-solid fa-camera"></i> Fotos desta área<input type="file" name="lev_fotos[<?= $ak ?>][]" accept="image/*" multiple style="display:none;" onchange="levPreview(this)"></label>
                                    <div class="lev-fotos">
                                        <?php foreach (($ld['fotos'] ?? []) as $fo): ?>
                                            <div class="lev-foto">
                                                <img src="imagens/<?= htmlspecialchars($fo) ?>" alt="" onclick="window.open(this.src,'_blank')" style="cursor:zoom-in;">
                                                <input type="hidden" name="lev[<?= $ak ?>][fotos_manter][]" value="<?= htmlspecialchars($fo) ?>">
                                                <button type="button" onclick="if(confirm('Remover esta fotografia?')) this.parentNode.remove();" title="Remover"><i class="fa-solid fa-xmark"></i></button>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; endforeach; ?>
            </div>

            <div class="card" style="margin-bottom: 40px;">
                <h2><i class="fa-solid fa-camera"></i> Registo Fotográfico</h2>

                <div class="foto-upload-area" onclick="document.getElementById('input_fotos').click()">
                    <i class="fa-solid fa-cloud-arrow-up"></i>
                    <span style="font-weight:600; color:#2c3e50;">Clique para anexar fotografias ou esquemas</span>
                    <div style="font-size:11px; color:#7f8c8d; margin-top:5px;">Formatos aceites: JPG, PNG, WEBP · pode juntar fotografias várias vezes antes de gravar</div>
                </div>
                <input type="file" name="fotos[]" id="input_fotos" multiple accept="image/*" style="display:none;" onchange="previewNovasFotos(this)">

                <div class="fotos-grid" id="galeria_fotos">
                    <?php
                        $fotos_salvas = isset($visita['fotos']) ? json_decode($visita['fotos'], true) : [];
                        if(!empty($fotos_salvas) && is_array($fotos_salvas)):
                            foreach($fotos_salvas as $f):
                                $f = basename((string)$f);
                                $path = 'imagens/' . rawurlencode($f);
                    ?>
                        <div class="foto-item" id="foto_<?= md5($f) ?>">
                            <img src="<?= htmlspecialchars($path) ?>" alt="Anexo" onclick="window.open(this.src, '_blank')" style="cursor:zoom-in;">
                            <button type="button" class="btn-rm-foto" data-foto="<?= htmlspecialchars($f) ?>" onclick="marcarFotoRemocao(this.dataset.foto, 'foto_<?= md5($f) ?>')"><i class="fa-solid fa-xmark"></i></button>
                        </div>
                    <?php
                            endforeach;
                        endif;
                    ?>
                </div>
            </div>

            <div class="floating-action-bar">
                <button type="submit" class="btn-save"><i class="fa-solid fa-floppy-disk"></i> GUARDAR REGISTO</button>
            </div>
        </form>
    </div>

    <div class="modal" id="modalRedirect">
        <div class="modal-content">
            <h3 style="color:#2c3e50;"><i class="fa-solid fa-circle-check" style="color:#2ecc71;"></i> Registo Guardado</h3>
            <p>O que deseja fazer agora?</p>
            <div class="modal-btns">
                <button type="button" onclick="confirmarRedirect('ficar')" class="btn-secondary"><i class="fa-solid fa-pen-to-square"></i> Ficar na visita</button>
                <button type="button" onclick="confirmarRedirect('voltar')" class="btn-secondary"><i class="fa-solid fa-arrow-left"></i> Voltar à listagem</button>
                <button type="button" onclick="confirmarRedirect('imprimir')" class="btn-primary"><i class="fa-solid fa-print"></i> Imprimir Registo</button>
            </div>
        </div>
    </div>

    <script>
        const isPostSavePrint = <?= (isset($_GET['acao']) && $_GET['acao'] === 'imprimir') ? 'true' : 'false' ?>;
        const defaultRefVisita = "<?= htmlspecialchars($referencia_base_js, ENT_QUOTES, 'UTF-8') ?>";
        const modoEditar = <?= $modo_editar ? 'true' : 'false' ?>;

        // --- HORA DE ENTRADA = HORA DO DISPOSITIVO (computador / tablet / telemóvel) ---
        // Só em registos novos; na edição mantém-se a hora gravada.
        if (!modoEditar) {
            const agora = new Date();
            const hh = String(agora.getHours()).padStart(2, '0');
            const mm = String(agora.getMinutes()).padStart(2, '0');
            document.getElementById('input_hora_entrada').value = `${hh}:${mm}`;
        }

        // PHP Passa para JS as listas divididas e pré-seleções
        const dbIdCliente = <?= isset($visita['id_cliente']) ? ($visita['id_cliente'] ?: 'null') : 'null' ?>;
        const dbNomeCliente = <?= json_encode($nome_cliente_bd) ?>;
        const dbClienteInfo = <?= json_encode($cliente_bd_info) ?>;
        const dbIdObra = <?= isset($visita['id_obra']) ? ($visita['id_obra'] ?: 'null') : 'null' ?>;
        const dbIdOrcamento = <?= isset($visita['id_orcamento']) ? ($visita['id_orcamento'] ?: 'null') : 'null' ?>;

        const clientesGeral = <?= json_encode($clientes_geral) ?>;
        const clientesObras = <?= json_encode($clientes_obras) ?>;
        const clientesOrcamentos = <?= json_encode($clientes_orcamentos) ?>;

        // Atualiza a dropdown de Clientes de acordo com o contexto selecionado
        function atualizarDropdownClientes(ctx) {
            const selCliente = document.getElementById('id_cliente');
            const valAtual = selCliente.value || dbIdCliente;

            selCliente.innerHTML = '<option value="">-- Selecione o Cliente --</option>';
            selCliente.innerHTML += '<option value="novo" style="font-weight:bold; color:#e84393;">+ CRIAR NOVO CLIENTE</option>';

            let lista = [...clientesGeral];
            if (ctx === 'orcamento') lista = [...clientesOrcamentos];
            else if (ctx === 'obra' || ctx === 'reuniao') lista = [...clientesObras];

            // Se for edição, forçamos o cliente na lista (caso tenha fechado o orç. ou obra entretanto)
            if (dbIdCliente && !lista.find(c => c.id == dbIdCliente)) {
                lista.push(dbClienteInfo ? dbClienteInfo : {id: dbIdCliente, nome: dbNomeCliente});
            }

            lista.forEach(c => {
                const opt = document.createElement('option');
                opt.value = c.id;
                opt.text = c.nome;
                if (c.id == valAtual) opt.selected = true;
                selCliente.appendChild(opt);
            });
        }

        function alterarContexto() {
            const ctx = document.getElementById('contexto_visita').value;
            const blocoDoc = document.getElementById('bloco_doc_dinamico');
            const labelDoc = document.getElementById('label_doc_dinamico');
            const selectDoc = document.getElementById('select_doc_dinamico');
            const inputAssunto = document.getElementById('input_assunto');

            atualizarDropdownClientes(ctx);
            mostrarMoradaCliente(); // Mostra a morada logo ao abrir (inclui Visita Comercial em modo editar)

            // Levantamento de necessidades só na Visita Comercial
            const cardLev = document.getElementById('card_levantamento');
            if (cardLev) {
                cardLev.style.display = (ctx === 'cliente') ? 'block' : 'none';
                document.getElementById('lev_ativo').value = (ctx === 'cliente') ? '1' : '0';
            }

            if (ctx === 'cliente') {
                blocoDoc.style.display = 'none';
                selectDoc.name = 'unused_dinamico';
                selectDoc.required = false;
                document.getElementById('input_referencia').value = defaultRefVisita;
            } else {
                blocoDoc.style.display = 'block';
                selectDoc.required = true;

                if (ctx === 'orcamento') {
                    labelDoc.innerText = 'Orçamento a Acompanhar *';
                    selectDoc.name = 'id_orcamento_dinamico';
                } else if (ctx === 'obra') {
                    labelDoc.innerText = 'Obra em Execução *';
                    selectDoc.name = 'id_obra_dinamica';
                } else if (ctx === 'reuniao') {
                    labelDoc.innerText = 'Reunião Referente à Obra *';
                    selectDoc.name = 'id_obra_dinamica';
                    if(!modoEditar && inputAssunto.value === '') inputAssunto.value = 'Reunião de Obra';
                }

                carregarDocumentos();
            }
        }

        // --- MOSTRAR MORADA DO CLIENTE SELECIONADO ---
        function mostrarMoradaCliente() {
            const clienteVal = document.getElementById('id_cliente').value;
            const caixa = document.getElementById('info_morada_cliente');
            const texto = document.getElementById('texto_morada_cliente');

            if (!clienteVal || clienteVal === 'novo') { caixa.style.display = 'none'; return; }

            const todos = [...clientesGeral, ...clientesObras, ...clientesOrcamentos];
            if (dbClienteInfo) todos.push(dbClienteInfo);
            const cli = todos.find(c => c.id == clienteVal);

            const cpCidade = cli ? [cli.codigo_postal, cli.cidade].filter(x => x && x.trim() !== '').join(' ') : '';
            const partes = cli ? [cli.morada, cpCidade].filter(x => x && x.trim() !== '') : [];

            texto.innerText = partes.length > 0 ? partes.join(', ') : 'Sem morada registada na ficha do cliente.';
            caixa.style.display = 'block';
        }

        function carregarDocumentos() {
            const clienteVal = document.getElementById('id_cliente').value;
            const ctx = document.getElementById('contexto_visita').value;
            mostrarMoradaCliente();
            const selectDoc = document.getElementById('select_doc_dinamico');
            const aviso = document.getElementById('aviso_loading');

            if (clienteVal === 'novo') {
                document.getElementById('section_novo_cliente').style.display = 'block';
                document.getElementById('nc_nome').required = true;
            } else {
                document.getElementById('section_novo_cliente').style.display = 'none';
                document.getElementById('nc_nome').required = false;
            }

            if (ctx === 'cliente' || !clienteVal || clienteVal === 'novo') {
                selectDoc.innerHTML = '<option value="">-- Selecione o Cliente Primeiro --</option>';
                return;
            }

            const tipoBusca = (ctx === 'orcamento') ? 'orcamento' : 'obra';
            const docAtual = (tipoBusca === 'obra') ? dbIdObra : dbIdOrcamento;

            aviso.innerText = "A carregar dados...";

            fetch(`ajax_get_documentos_cliente.php?id_cliente=${clienteVal}&tipo=${tipoBusca}&id_doc_atual=${docAtual}`)
                .then(response => response.json())
                .then(data => {
                    selectDoc.innerHTML = '<option value="" data-ref="">-- Escolha da lista --</option>';
                    if(data.length === 0) {
                        selectDoc.innerHTML = `<option value="">Nenhum(a) ${tipoBusca} ativo para este cliente</option>`;
                    } else {
                        data.forEach(item => {
                            const option = document.createElement('option');
                            option.value = item.id;
                            option.setAttribute('data-ref', item.ref);
                            option.text = `[${item.ref}] ${item.descricao}`;

                            if (tipoBusca === 'obra' && item.id == dbIdObra) option.selected = true;
                            if (tipoBusca === 'orcamento' && item.id == dbIdOrcamento) option.selected = true;

                            selectDoc.appendChild(option);
                        });
                        updateReferenciaDinamica();
                    }
                    aviso.innerText = "";
                })
                .catch(error => {
                    console.error('Erro no AJAX:', error);
                    aviso.innerText = "Erro ao comunicar com o servidor.";
                });
        }

        function updateReferenciaDinamica() {
            const selectDoc = document.getElementById('select_doc_dinamico');
            const inputRef = document.getElementById('input_referencia');

            if (selectDoc.selectedIndex > 0) {
                const ref = selectDoc.options[selectDoc.selectedIndex].getAttribute('data-ref');
                if (ref) inputRef.value = ref;
            } else {
                inputRef.value = defaultRefVisita;
            }
        }

        // --- SISTEMA GPS (OBTER LOCALIZAÇÃO PARA NOVO CLIENTE) ---
        function obterLocalizacaoGPSNovoCliente() {
            const inputMorada = document.getElementById('nc_morada');
            const inputCP = document.getElementById('nc_cp');
            const inputCidade = document.getElementById('nc_cidade');

            if (!navigator.geolocation) {
                alert("O seu navegador não suporta geolocalização.");
                return;
            }

            inputMorada.value = "A obter localização...";

            navigator.geolocation.getCurrentPosition(
                (position) => {
                    const lat = position.coords.latitude;
                    const lon = position.coords.longitude;

                    fetch(`https://nominatim.openstreetmap.org/reverse?format=jsonv2&lat=${lat}&lon=${lon}`)
                        .then(response => response.json())
                        .then(data => {
                            if (data && data.address) {
                                // Tratar Morada
                                let moradaFormatada = [];
                                if (data.address.road) moradaFormatada.push(data.address.road);
                                if (data.address.house_number) moradaFormatada.push(data.address.house_number);
                                inputMorada.value = moradaFormatada.join(', ') || data.display_name.split(',')[0];

                                // Tratar Código Postal
                                if (data.address.postcode) {
                                    inputCP.value = data.address.postcode;
                                }

                                // Tratar Cidade/Localidade
                                let localidade = data.address.city || data.address.town || data.address.village || data.address.municipality;
                                if (localidade) {
                                    inputCidade.value = localidade;
                                }
                            } else {
                                inputMorada.value = `${lat}, ${lon}`;
                            }
                        })
                        .catch(error => {
                            console.error("Erro na conversão GPS:", error);
                            inputMorada.value = `${lat}, ${lon}`;
                        });
                },
                (error) => {
                    console.error("Erro de GPS:", error);
                    alert("Não foi possível obter a localização. Verifique as permissões de localização do seu navegador.");
                    inputMorada.value = "";
                },
                { enableHighAccuracy: true, timeout: 15000, maximumAge: 0 }
            );
        }

        // --- LEVANTAMENTO DE NECESSIDADES ---
        function levToggleArea(chip) {
            const area = chip.dataset.area;
            const painel = document.getElementById('lev_area_' + area);
            const ativo = painel.querySelector('.lev-ativo-area');
            const ligar = !chip.classList.contains('on');
            if (!ligar) {
                const temDados = Array.from(painel.querySelectorAll('input:not([type=hidden]):not([type=file]), select, textarea')).filter(el => !(el.closest('.lev-linha') && el.type === 'number')).some(el =>
                    (el.type === 'checkbox' || el.type === 'radio') ? (el.checked && el.name.indexOf('fazer_orcamento') === -1) : el.value.trim() !== '') || painel.querySelector('.lev-foto');
                if (temDados && !confirm('Retirar esta área? O que preencheu nela (e as fotos) deixa de ficar gravado.')) return;
            }
            chip.classList.toggle('on', ligar);
            chip.style.background = ligar ? chip.dataset.cor : '';
            painel.classList.toggle('on', ligar);
            ativo.value = ligar ? '1' : '';
            if (ligar) painel.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }
        // Linhas da área: nova / duplicar / apagar
        let levSeqLinha = 0;
        function levNomearLinha(linha, area) {
            const i = levSeqLinha++;
            linha.querySelectorAll('[data-nome]').forEach(el => el.name = 'lev[' + area + '][linhas][' + i + '][' + el.dataset.nome + ']');
        }
        function levContarLinhas(bloco) {
            const n = bloco.querySelectorAll('.lev-linhas-lista .lev-linha').length;
            bloco.querySelector('.lev-linhas-cont').textContent = n ? '(' + n + ')' : '';
        }
        function levLinhaNova(btn, primeira) {
            const bloco = btn.closest('.lev-linhas');
            const nova = bloco.querySelector('.lev-linha-modelo').content.firstElementChild.cloneNode(true);
            levNomearLinha(nova, bloco.dataset.area);
            const lista = bloco.querySelector('.lev-linhas-lista');
            if (primeira) lista.appendChild(nova); else btn.closest('.lev-linha').after(nova);
            levContarLinhas(bloco);
            const f = nova.querySelector('select, input'); if (f) f.focus();
        }
        function levLinhaDuplicar(btn) {
            const bloco = btn.closest('.lev-linhas');
            const orig = btn.closest('.lev-linha');
            const copia = orig.cloneNode(true);
            orig.querySelectorAll('select').forEach((s, k) => { copia.querySelectorAll('select')[k].value = s.value; });
            levNomearLinha(copia, bloco.dataset.area);
            orig.after(copia);
            levContarLinhas(bloco);
        }
        function levLinhaApagar(btn) {
            const bloco = btn.closest('.lev-linhas');
            btn.closest('.lev-linha').remove();
            levContarLinhas(bloco);
        }
        document.querySelectorAll('.lev-linhas').forEach(bloco => {
            bloco.querySelectorAll('.lev-linhas-lista .lev-linha').forEach(l => levNomearLinha(l, bloco.dataset.area));
            // Área ainda sem linhas: começa com uma linha vazia
            if (!bloco.querySelector('.lev-linhas-lista .lev-linha')) {
                const nova = bloco.querySelector('.lev-linha-modelo').content.firstElementChild.cloneNode(true);
                levNomearLinha(nova, bloco.dataset.area);
                bloco.querySelector('.lev-linhas-lista').appendChild(nova);
            }
            levContarLinhas(bloco);
        });

        // Regras dos campos: mostrar só quando..., obrigatório, aviso
        function levValorCampo(painel, campo) {
            const wrap = painel.querySelector('.lev-campo[data-campo="' + campo + '"]');
            if (!wrap) return '';
            const sel = wrap.querySelector('select'); if (sel) return sel.value;
            const r = wrap.querySelector('input[type=radio]:checked'); if (r) return r.value;
            const t = wrap.querySelector('input[type=text], input[type=number]'); return t ? t.value.trim() : '';
        }
        function levAvaliarRegras(painel) {
            painel.querySelectorAll('.lev-campo[data-mostrar-campo]').forEach(w => {
                const valores = JSON.parse(w.dataset.mostrarValores || '[]');
                const visivel = valores.includes(levValorCampo(painel, w.dataset.mostrarCampo));
                w.classList.toggle('lev-escondido', !visivel);
            });
            levAvaliarAvisos(painel);
            painel.querySelectorAll('.lev-campo.lev-falta').forEach(w => { if (levValorCampo(painel, w.dataset.campo) !== '') w.classList.remove('lev-falta'); });
            levSugestao(painel);
            levEstimativa(painel);
        }
        // Aviso quando o campo tem o valor indicado, ou '>N' quando o número passa de N
        function levAvaliarAvisos(painel) {
            painel.querySelectorAll('.lev-campo[data-aviso-valor]').forEach(w => {
                const alvo = w.dataset.avisoValor, v = levValorCampo(painel, w.dataset.campo);
                const ativo = alvo.charAt(0) === '>' ? (v !== '' && parseFloat(v.replace(',', '.')) > parseFloat(alvo.slice(1))) : v === alvo;
                w.classList.toggle('lev-com-aviso', ativo);
            });
        }
        // Estimativas de cabo (mesmos cálculos do PDF, em levantamento_def.php)
        function levEstimativa(painel) {
            const box = painel.querySelector('.lev-estimativa');
            if (!box) return;
            if (box.dataset.tipo === 'redes') return levEstimativaRedes(painel, box);
            const n = k => Math.max(0, parseFloat(String(levValorCampo(painel, k)).replace(',', '.')) || 0);
            const coletivo = ['Edifício coletivo (habitação)', 'Edifício comercial / escritórios', 'Misto (habitação + comércio)'].includes(levValorCampo(painel, 'edificio'));
            const fogos = coletivo ? Math.max(1, n('fogos')) : 1;
            const tomRj = fogos * n('divisoes') * n('rj45_divisao'), tomCx = fogos * n('divisoes') * n('coaxial_divisao');
            const media = n('dist_media'), coluna = coletivo ? fogos * n('dist_ate_ati') : 0, entrada = n('dist_entrada');
            box.classList.remove('on');
            if (media <= 0 && coluna <= 0 && entrada <= 0) return;
            const folga = m => Math.ceil(m * 1.1 / 5) * 5;
            const pares = folga(tomRj * media + coluna + entrada), coax = folga(tomCx * media + coluna + entrada);
            const fibra = levValorCampo(painel, 'fibra') === 'Não' ? 0 : folga(coluna + entrada);
            const cat = levValorCampo(painel, 'categoria_cabo');
            box.innerHTML = '<i class="fa-solid fa-ruler-horizontal"></i> Estimativa de cabo: '
                + 'pares' + (cat && cat !== 'A definir' ? ' ' + cat : '') + ' ~' + pares + ' m (' + tomRj + ' tomadas RJ45) · '
                + 'coaxial ~' + coax + ' m (' + tomCx + ' tomadas)' + (fibra > 0 ? ' · fibra ótica ~' + fibra + ' m' : '')
                + '<br><small style="font-weight:400;">Inclui 10% de folga. Valor indicativo para o orçamento.</small>';
            box.classList.add('on');
        }
        function levEstimativaRedes(painel, box) {
            const num = v => Math.max(0, parseFloat(String(v).replace(',', '.')) || 0);
            const n = k => num(levValorCampo(painel, k));
            const linhas = [...painel.querySelectorAll('.lev-linhas-lista .lev-linha')].filter(l => [...l.querySelectorAll('[data-nome]')].some(i => i.value.trim() !== ''));
            const pontos = Math.floor(n('pontos')), media = n('dist_media');
            const nAp = Math.max(Math.floor(n('aps_wifi')), linhas.length), total = pontos + nAp;
            box.classList.remove('on');
            if (total <= 0) return;
            let somaAp = 0, apLonge = 0;
            linhas.forEach(l => {
                const v = (l.querySelector('[data-nome=distancia]') || {}).value || '';
                const d = v.trim() !== '' ? num(v) : media; somaAp += d; if (d > 90) apLonge++;
            });
            somaAp += (nAp - linhas.length) * media;
            const metros = pontos * media + somaAp, partes = [];
            const cat = levValorCampo(painel, 'categoria');
            if (metros > 0) { const m = Math.ceil(metros * 1.1 / 5) * 5, cx = Math.ceil(m / 305); partes.push('cabo' + (cat && cat !== 'A definir' ? ' ' + cat : '') + ' ~' + m + ' m (' + cx + ' caixa' + (cx > 1 ? 's' : '') + ' de 305 m)'); }
            const preciso = Math.ceil(total * 1.2); let nSw = 1, sw = '';
            for (const t of [8, 16, 24, 48]) { if (preciso <= t) { sw = '1 switch de ' + t + ' portas'; break; } }
            if (!sw) { nSw = Math.ceil(preciso / 48); sw = nSw + ' switches de 48 portas'; }
            partes.push(total + ' portas (' + pontos + ' pontos + ' + nAp + ' AP): ' + sw + (nAp > 0 ? ', ' + nAp + ' com PoE' : ''));
            const paineis = Math.ceil(total / 24);
            partes.push(paineis + (paineis > 1 ? ' painéis' : ' painel') + ' de 24 portas');
            if (levValorCampo(painel, 'bastidor') !== 'Não') {
                const u = paineis * 2 + nSw + 4; let b = 'mais de 42U';
                for (const t of [6, 9, 12, 15, 18, 24, 32, 42]) { if (u <= t) { b = t + 'U'; break; } }
                partes.push('bastidor sugerido: ' + b);
            }
            if (levValorCampo(painel, 'fibra_backbone') === 'Sim' && n('backbone_m') > 0) partes.push('fibra entre bastidores ~' + Math.ceil(n('backbone_m') * 1.1 / 5) * 5 + ' m');
            box.innerHTML = '<i class="fa-solid fa-calculator"></i> Estimativa: ' + partes.join(' · ')
                + (apLonge ? '<br><span style="color:#b9770e;">⚠ ' + apLonge + ' AP a mais de 90 m do bastidor: precisa de bastidor secundário.</span>' : '')
                + '<br><small style="font-weight:400;">Switch com 20% de portas de reserva e 10% de folga no cabo. Valores indicativos para o orçamento.</small>';
            box.classList.add('on');
        }
        // Sugestão do nº mínimo de detetores a partir da área (não impede de gravar)
        function levSugestao(painel) {
            const box = painel.querySelector('.lev-sugestao');
            if (!box) return;
            const area = parseFloat(String(levValorCampo(painel, box.dataset.campoArea)).replace(/\./g, '').replace(',', '.')) || 0;
            const m2 = parseInt(box.dataset.m2) || 200;
            box.className = 'lev-sugestao';
            if (area <= 0) return;
            const minimo = Math.ceil(area / m2);
            let atual = 0;
            painel.querySelectorAll('.lev-linhas-lista .lev-linha').forEach(l => {
                const tipo = (l.querySelector('[data-nome=tipo]') || {}).value || '';
                if (tipo.indexOf(box.dataset.tipo) === 0) atual += parseInt((l.querySelector('[data-nome=qtd]') || {}).value) || 0;
            });
            const base = 'Sugestão: mínimo ' + minimo + ' detetor' + (minimo > 1 ? 'es' : '') + ' para ' + area.toLocaleString('pt-PT') + ' m² (1 por cada ' + m2 + ' m²). Na tabela: ' + atual + '.';
            box.textContent = (atual >= minimo ? '✔ ' : '⚠ ') + base;
            box.classList.add(atual >= minimo ? 'ok' : 'falta');
        }
        document.querySelectorAll('.lev-area').forEach(painel => {
            painel.addEventListener('change', () => levAvaliarRegras(painel));
            painel.addEventListener('input', () => { levSugestao(painel); levAvaliarAvisos(painel); levEstimativa(painel); });
            painel.addEventListener('click', e => { if (e.target.closest('.lev-linha-btns, .lev-linha-primeira')) setTimeout(() => { levSugestao(painel); levEstimativa(painel); }, 0); });
            painel.addEventListener('click', e => { if (e.target.matches('.lev-seg input')) setTimeout(() => levAvaliarRegras(painel), 0); });
            levAvaliarRegras(painel);
        });
        // Campos obrigatórios em falta (só nas áreas escolhidas e quando o campo está visível)
        function levCamposEmFalta() {
            const card = document.getElementById('card_levantamento');
            if (!card || card.style.display === 'none') return null;
            let primeiro = null;
            document.querySelectorAll('.lev-area.on .lev-campo[data-obrigatorio]:not(.lev-escondido)').forEach(w => {
                if (levValorCampo(w.closest('.lev-area'), w.dataset.campo) === '') {
                    w.classList.add('lev-falta');
                    if (!primeiro) primeiro = w;
                }
            });
            return primeiro;
        }

        function levNum(btn, d) {
            const inp = btn.parentNode.querySelector('input');
            inp.value = Math.max(0, (parseInt(inp.value) || 0) + d);
            inp.dispatchEvent(new Event('change', { bubbles: true }));
        }
        function levDesmarcar(radio) {
            // Tocar de novo no Sim/Não já escolhido limpa a resposta
            if (radio.dataset.era === '1') { radio.checked = false; radio.dataset.era = ''; }
            else { document.querySelectorAll('input[name="' + radio.name + '"]').forEach(r => r.dataset.era = ''); radio.dataset.era = '1'; }
        }
        document.querySelectorAll('.lev-seg input:checked').forEach(r => r.dataset.era = '1');
        // ---------- FOTOGRAFIAS NOVAS ----------
        // Cada escolha de fotografias JUNTA-SE às anteriores (antes, a 2.ª escolha substituía a 1.ª e só ia uma).
        // As fotografias grandes do telemóvel são reduzidas no browser (máx. 1920 px, já endireitadas) antes de
        // serem enviadas: o envio é mais rápido e não passa o limite de tamanho do servidor.
        const FOTO_MAX_PX = 1920, FOTO_QUALIDADE = 0.85;
        let fotosEmPreparacao = 0;
        function estadoPreparacao(delta) {
            fotosEmPreparacao += delta;
            document.querySelectorAll('.btn-save').forEach(b => {
                if (!b.dataset.txt) b.dataset.txt = b.innerHTML;
                b.disabled = fotosEmPreparacao > 0;
                b.innerHTML = fotosEmPreparacao > 0 ? '<i class="fa-solid fa-spinner fa-spin"></i> A PREPARAR FOTOGRAFIAS...' : b.dataset.txt;
            });
        }
        async function reduzirFoto(file) {
            if (!/^image\/(jpeg|png|webp)$/i.test(file.type) || typeof createImageBitmap !== 'function') return file;
            try {
                const bmp = await createImageBitmap(file, { imageOrientation: 'from-image' });
                const f = Math.min(1, FOTO_MAX_PX / Math.max(bmp.width, bmp.height));
                if (f === 1 && file.size < 1.5 * 1024 * 1024) { bmp.close && bmp.close(); return file; }
                const c = document.createElement('canvas');
                c.width = Math.round(bmp.width * f); c.height = Math.round(bmp.height * f);
                const ctx = c.getContext('2d');
                ctx.fillStyle = '#fff'; ctx.fillRect(0, 0, c.width, c.height);
                ctx.drawImage(bmp, 0, 0, c.width, c.height);
                bmp.close && bmp.close();
                const blob = await new Promise(r => c.toBlob(r, 'image/jpeg', FOTO_QUALIDADE));
                if (!blob || blob.size >= file.size) return file;
                return new File([blob], file.name.replace(/\.[^.]+$/, '') + '.jpg', { type: 'image/jpeg', lastModified: Date.now() });
            } catch (e) { return file; }
        }
        // Junta os ficheiros escolhidos agora aos já escolhidos neste campo e mostra-os com um "x" para retirar
        async function juntarFotos(input, galeria, classe) {
            if (!input._fotos) input._fotos = [];
            const escolhidas = Array.from(input.files || []).filter(f => f.type.startsWith('image/'));
            estadoPreparacao(1);
            try {
                for (const f of escolhidas) input._fotos.push(await reduzirFoto(f));
            } finally {
                estadoPreparacao(-1);
            }
            atualizarFotosInput(input, galeria, classe);
        }
        function atualizarFotosInput(input, galeria, classe) {
            if (typeof DataTransfer === 'function') {
                const dt = new DataTransfer();
                input._fotos.forEach(f => dt.items.add(f));
                input.files = dt.files;
            }
            galeria.querySelectorAll('.' + classe).forEach(el => el.remove());
            input._fotos.forEach((f, i) => {
                const url = URL.createObjectURL(f);
                const div = document.createElement('div');
                div.className = (classe === 'lev-foto-nova' ? 'lev-foto nova ' : 'foto-item foto-preview-nova ') + classe;
                div.style.position = 'relative';
                div.innerHTML = '<img src="' + url + '" style="opacity:0.75;">'
                    + '<div style="position:absolute; bottom:0; left:0; background:rgba(0,0,0,0.5); width:100%; color:#fff; font-size:10px; text-align:center; padding:2px;">Por guardar</div>'
                    + '<button type="button" class="btn-rm-foto" title="Retirar" style="position:absolute; top:4px; right:4px;"><i class="fa-solid fa-xmark"></i></button>';
                div.querySelector('button').addEventListener('click', ev => {
                    ev.stopPropagation();
                    input._fotos.splice(i, 1);
                    atualizarFotosInput(input, galeria, classe);
                });
                galeria.appendChild(div);
            });
        }
        function levPreview(input) {
            juntarFotos(input, input.closest('.lev-campo').querySelector('.lev-fotos'), 'lev-foto-nova');
        }

        // --- FOTOGRAFIAS ---
        let fotosParaRemover = [];
        function marcarFotoRemocao(nomeFicheiro, divId) {
            if(confirm("Pretende apagar esta fotografia?")) {
                fotosParaRemover.push(nomeFicheiro);
                document.getElementById('fotos_remover').value = JSON.stringify(fotosParaRemover);
                document.getElementById(divId).style.display = 'none';
            }
        }

        function previewNovasFotos(input) {
            juntarFotos(input, document.getElementById('galeria_fotos'), 'foto-nova');
        }

        let isRedirectChosen = false;
        const form = document.getElementById('formVisita');
        form.addEventListener('submit', function(e) {
            if (fotosEmPreparacao > 0) { e.preventDefault(); alert('Aguarde: as fotografias ainda estão a ser preparadas.'); return; }
            const falta = levCamposEmFalta();
            if (falta) {
                e.preventDefault();
                const head = falta.closest('.lev-area').querySelector('.lev-area-head');
                const area = Array.from(head.childNodes).filter(n => n.nodeType === 3).map(n => n.textContent).join('').trim();
                alert('Falta preencher "' + falta.querySelector('label').textContent.replace('*', '').trim() + '" (' + area + ').');
                falta.scrollIntoView({ behavior: 'smooth', block: 'center' });
                return;
            }
            if (!isRedirectChosen) {
                e.preventDefault();
                document.getElementById('modalRedirect').classList.add('show');
            }
        });

        function confirmarRedirect(escolha) {
            document.getElementById('redirect_action').value = escolha;
            isRedirectChosen = true;
            document.getElementById('modalRedirect').classList.remove('show');
            form.submit();
        }

        (function () {
            const menu = document.querySelector('.user-menu');
            const btn = menu ? menu.querySelector('.user-btn') : null;
            if (!btn) return;
            btn.addEventListener('click', function (e) { e.stopPropagation(); menu.classList.toggle('aberto'); });
            document.addEventListener('click', function () { menu.classList.remove('aberto'); });
        })();

        window.onload = function() {
            alterarContexto();
            <?php if (!empty($chamada) && !$cliente_chamada_encontrado):
                $ct = trim((string)$chamada['contacto']);
                $pre = ['nome' => $chamada['nome'], 'morada' => (string)$chamada['morada'],
                        'telefone' => strpos($ct, '@') === false ? $ct : '', 'email' => strpos($ct, '@') !== false ? $ct : ''];
            ?>
            // Chamada de um cliente que ainda não existe: abre "Criar novo cliente" já preenchido
            (function (d) {
                const sel = document.getElementById('id_cliente');
                sel.value = 'novo';
                carregarDocumentos();
                const f = (n, v) => { const el = document.querySelector('[name="' + n + '"]'); if (el && v) el.value = v; };
                f('nc_nome', d.nome); f('nc_morada', d.morada); f('nc_telefone', d.telefone); f('nc_email', d.email);
            })(<?= json_encode($pre, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>);
            <?php endif; ?>

            if (isPostSavePrint) {
                const id = <?= $id ?? 0 ?>;
                if(id > 0) window.open(`visita_pdf.php?id=${id}`, '_blank');
                window.location.href = '<?= $grupo === 'obra' ? 'relatorios.php?tab=acomp' : 'visitas.php' ?>';
            }
        };
    </script>
</body>
</html>