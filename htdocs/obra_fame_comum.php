<?php
// =====================================================================
// OBRAS — FAME (Ficha de Aprovação de Material e Equipamento) e MATERIAL A APROVISIONAR
// Usado por: obras.php, obra_fame.php (FAME da obra), obra_fame_pdf.php, obra_material.php e obra_material_pdf.php
//
// - Cada obra tem uma lista FAME. Cada linha tem uma ou várias especialidades (Elétrico, ITED, Segurança, Deteção de
//   incêndios ou outra), escolhidas linha a linha. O PDF sai uma FAME por especialidade (a linha sai em cada uma).
// - As linhas vêm do orçamento (Detalhe Interno: TODAS as linhas com quantidade, somadas por designação + unidade;
//   sem Detalhe Interno usa as linhas comerciais) e/ou são acrescentadas à mão. Podem ser apagadas e mudadas de posição (uma ou várias).
// - O material a aprovisionar é o material da FAME (por ordem de especialidade, com fabricante e referência).
//   Sem FAME, é o material do orçamento.
// - Fabricante / referência / ficha técnica ficam gravados na linha e na memória geral (fame_catalogo, pela designação),
//   para preencher sozinho nas obras seguintes.
// - A designação das linhas do orçamento pode ser corrigida na FAME (a linha continua ligada ao orçamento pela chave).
//   A correção fica na memória (chave = designação do orçamento → designação corrigida) e é usada ao importar/repor.
// - A Ref / Artigo do orçamento vem com as linhas importadas (coluna "artigo"; vários artigos juntos por vírgula).
// =====================================================================

// Especialidades de origem (as escritas em "Outra" ficam gravadas em configuracoes → fame_especialidades_extra)
$FAME_ESPECIALIDADES = ['Elétrico', 'ITED', 'Segurança', 'Deteção de incêndios'];

// Ao importar do orçamento: especialidade sugerida pelo capítulo (título) do orçamento onde está o material.
// Expressões aplicadas ao título em MAIÚSCULAS e sem acentos.
$FAME_PALAVRAS = [
    'Elétrico'             => '\\b(ELETRIC|DOMOTIC|ILUMINA|QUADRO|FOTOVOLT|CARREGADOR|TOMADAS)',
    'ITED'                 => '\\b(ITED|REDES?|TELECOMUNICAC|FIBRA|ESTRUTURAD)',
    'Segurança'            => '\\b(INTRUS|ACESSOS?|CCTV|VIDEO|ALARME|SEGURANC)',
    'Deteção de incêndios' => '\\b(SADI|INCENDIO|EXTINTOR|CORTA|SCIE|CO|MONOXIDO|DESENFUMAGEM)\\b',
];

// Sigla para o número do documento
function fameSigla($esp) {
    $fixas = ['Elétrico' => 'ELE', 'ITED' => 'ITED', 'Segurança' => 'SEG', 'Deteção de incêndios' => 'SCI', 'Geral' => 'GER'];
    if (isset($fixas[$esp])) return $fixas[$esp];
    $s = strtoupper(preg_replace('/[^A-Za-z]/', '', fameSemAcentos($esp)));
    return substr($s, 0, 3) ?: 'FAM';
}

function fameColunaExiste($pdo, $tabela, $coluna) {
    try { return (bool)$pdo->query("SHOW COLUMNS FROM `$tabela` LIKE " . $pdo->quote($coluna))->fetch(); }
    catch (Exception $e) { return false; }
}

function fameCriarTabelas($pdo) {
    // Memória geral (uma linha por designação de material)
    $pdo->exec("CREATE TABLE IF NOT EXISTS fame_catalogo (
        id INT AUTO_INCREMENT PRIMARY KEY,
        chave VARCHAR(191) NOT NULL,
        designacao VARCHAR(255) NULL,
        fabricante VARCHAR(120) NULL,
        referencia VARCHAR(120) NULL,
        ficha_tecnica VARCHAR(255) NULL,
        atualizado_em DATETIME NULL,
        UNIQUE KEY uk_chave (chave)
    ) DEFAULT CHARSET=utf8mb4");

    // FAMEs (uma por especialidade e obra)
    $pdo->exec("CREATE TABLE IF NOT EXISTS obras_fames (
        id INT AUTO_INCREMENT PRIMARY KEY,
        id_obra INT NOT NULL,
        especialidade VARCHAR(80) NOT NULL,
        revisao INT NOT NULL DEFAULT 0,
        data DATE NULL,
        destinatario VARCHAR(150) NULL,
        observacoes TEXT NULL,
        criado_em DATETIME NULL,
        atualizado_por INT NULL,
        atualizado_em DATETIME NULL,
        INDEX (id_obra)
    ) DEFAULT CHARSET=utf8mb4");

    // Linhas das FAMEs
    $pdo->exec("CREATE TABLE IF NOT EXISTS obras_fame (
        id INT AUTO_INCREMENT PRIMARY KEY,
        id_obra INT NOT NULL,
        id_fame INT NULL,
        ordem INT NOT NULL DEFAULT 0,
        chave VARCHAR(191) NULL,
        designacao VARCHAR(255) NULL,
        unidade VARCHAR(10) NULL,
        quantidade DECIMAL(12,2) NULL,
        fabricante VARCHAR(120) NULL,
        referencia VARCHAR(120) NULL,
        ficha_tecnica VARCHAR(255) NULL,
        incluir TINYINT(1) NOT NULL DEFAULT 1,
        manual TINYINT(1) NOT NULL DEFAULT 0,
        INDEX (id_obra), INDEX (id_fame)
    ) DEFAULT CHARSET=utf8mb4");
    if (!fameColunaExiste($pdo, 'obras_fame', 'id_fame')) {
        $pdo->exec("ALTER TABLE obras_fame ADD COLUMN id_fame INT NULL AFTER id_obra, ADD INDEX (id_fame)");
    }
    $nova_coluna_esp = false;
    if (!fameColunaExiste($pdo, 'obras_fame', 'especialidade')) {
        $pdo->exec("ALTER TABLE obras_fame ADD COLUMN especialidade VARCHAR(80) NULL AFTER quantidade");
        $nova_coluna_esp = true;
    }
    // Várias especialidades por linha (out/2026): "Elétrico;ITED" → a coluna passa a ter 255 caracteres
    try {
        $tipo = $pdo->query("SHOW COLUMNS FROM obras_fame LIKE 'especialidade'")->fetch(PDO::FETCH_ASSOC);
        if ($tipo && preg_match('/varchar\((\d+)\)/i', $tipo['Type'], $mm) && (int)$mm[1] < 255) {
            $pdo->exec("ALTER TABLE obras_fame MODIFY especialidade VARCHAR(255) NULL");
        }
    } catch (Exception $e) {}
    // Alternativa (out/2026): linha com material proposto em alternativa ao especificado
    if (!fameColunaExiste($pdo, 'obras_fame', 'alternativa')) {
        $pdo->exec("ALTER TABLE obras_fame ADD COLUMN alternativa TINYINT(1) NOT NULL DEFAULT 0 AFTER manual");
    }
    // Ref / Artigo do orçamento (out/2026)
    if (!fameColunaExiste($pdo, 'obras_fame', 'artigo')) {
        $pdo->exec("ALTER TABLE obras_fame ADD COLUMN artigo VARCHAR(120) NULL AFTER chave");
    }

    // Ficha técnica da biblioteca (nov/2026): ligação à tabela fichas_tecnicas
    if (!fameColunaExiste($pdo, 'obras_fame', 'id_ficha_tecnica')) {
        $pdo->exec("ALTER TABLE obras_fame ADD COLUMN id_ficha_tecnica INT NULL AFTER ficha_tecnica");
    }
    if (!fameColunaExiste($pdo, 'fame_catalogo', 'id_ficha_tecnica')) {
        $pdo->exec("ALTER TABLE fame_catalogo ADD COLUMN id_ficha_tecnica INT NULL AFTER ficha_tecnica");
    }

    // Migração da 1ª versão (uma FAME por obra, sem especialidade): passa a ser a FAME "Geral" da obra
    $soltas = $pdo->query("SELECT DISTINCT id_obra FROM obras_fame WHERE id_fame IS NULL")->fetchAll(PDO::FETCH_COLUMN);
    if ($soltas) {
        $info_antiga = [];
        try {
            foreach ($pdo->query("SELECT * FROM obras_fame_info")->fetchAll(PDO::FETCH_ASSOC) as $r) $info_antiga[$r['id_obra']] = $r;
        } catch (Exception $e) {}
        $ins = $pdo->prepare("INSERT INTO obras_fames (id_obra, especialidade, revisao, data, destinatario, observacoes, criado_em) VALUES (?, 'Geral', ?, ?, ?, ?, NOW())");
        $upd = $pdo->prepare("UPDATE obras_fame SET id_fame = ? WHERE id_obra = ? AND id_fame IS NULL");
        foreach ($soltas as $id_obra) {
            $i = $info_antiga[$id_obra] ?? [];
            $ins->execute([$id_obra, (int)($i['revisao'] ?? 0), $i['data'] ?? date('Y-m-d'), $i['destinatario'] ?? '', $i['observacoes'] ?? '']);
            $upd->execute([$pdo->lastInsertId(), $id_obra]);
        }
    }

    // Migração da 2ª versão (uma FAME por especialidade): a especialidade passa para cada linha
    // e as FAMEs da mesma obra juntam-se numa só (a mais antiga), pela mesma ordem.
    if ($nova_coluna_esp) {
        $pdo->exec("UPDATE obras_fame l JOIN obras_fames f ON f.id = l.id_fame SET l.especialidade = f.especialidade
                    WHERE (l.especialidade IS NULL OR l.especialidade = '') AND f.especialidade <> 'Geral'");
    }
    $repetidas = $pdo->query("SELECT id_obra, MIN(id) AS principal FROM obras_fames GROUP BY id_obra HAVING COUNT(*) > 1")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($repetidas as $r) {
        $ordem = 0;
        $st = $pdo->prepare("SELECT l.id FROM obras_fame l JOIN obras_fames f ON f.id = l.id_fame WHERE f.id_obra = ? ORDER BY f.id, l.ordem, l.id");
        $st->execute([$r['id_obra']]);
        $upd = $pdo->prepare("UPDATE obras_fame SET id_fame = ?, ordem = ? WHERE id = ?");
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $id_l) $upd->execute([$r['principal'], ++$ordem, $id_l]);
        $pdo->prepare("DELETE FROM obras_fames WHERE id_obra = ? AND id <> ?")->execute([$r['id_obra'], $r['principal']]);
    }
}

// ---------- Textos e chaves ----------
function fameSemAcentos($t) {
    return strtr((string)$t, ['á'=>'a','à'=>'a','â'=>'a','ã'=>'a','ä'=>'a','é'=>'e','è'=>'e','ê'=>'e','ë'=>'e','í'=>'i','ì'=>'i','î'=>'i','ï'=>'i','ó'=>'o','ò'=>'o','ô'=>'o','õ'=>'o','ö'=>'o','ú'=>'u','ù'=>'u','û'=>'u','ü'=>'u','ç'=>'c','ñ'=>'n',
                              'Á'=>'A','À'=>'A','Â'=>'A','Ã'=>'A','Ä'=>'A','É'=>'E','È'=>'E','Ê'=>'E','Ë'=>'E','Í'=>'I','Ì'=>'I','Î'=>'I','Ï'=>'I','Ó'=>'O','Ò'=>'O','Ô'=>'O','Õ'=>'O','Ö'=>'O','Ú'=>'U','Ù'=>'U','Û'=>'U','Ü'=>'U','Ç'=>'C','Ñ'=>'N']);
}
function fameOrdenacao($t) { return fameSemAcentos(mb_strtolower((string)$t, 'UTF-8')); }
function fameNormTitulo($t) { return strtoupper(fameSemAcentos(mb_strtoupper((string)$t, 'UTF-8'))); }

// Chave para juntar materiais iguais (sem distinguir maiúsculas, espaços a mais)
function fameChave($designacao, $unidade = '') {
    $d = trim(preg_replace('/\s+/', ' ', (string)$designacao));
    return mb_substr(mb_strtolower($d . '|' . trim((string)$unidade), 'UTF-8'), 0, 191);
}
// ---------- Várias especialidades por linha ----------
// Na BD ficam juntas por ";" (ex.: "Elétrico;ITED"); no ecrã e nos PDF aparecem como "Elétrico + ITED".
function fameEspsLista($txt) {
    $res = [];
    foreach (preg_split('/\s*;\s*/', (string)$txt) as $x) {
        $x = trim($x);
        if ($x === '') continue;
        $dup = false;
        foreach ($res as $r) if (mb_strtolower($r, 'UTF-8') === mb_strtolower($x, 'UTF-8')) { $dup = true; break; }
        if (!$dup) $res[] = mb_substr($x, 0, 80);
    }
    return $res;
}
function fameEspsTexto($lista) {
    $t = implode(';', fameEspsLista(implode(';', (array)$lista)));
    return mb_substr($t, 0, 255);
}
function fameEspsMostrar($txt, $vazio = '') {
    $l = fameEspsLista($txt);
    return $l ? implode(' + ', $l) : $vazio;
}
// Chave da memória geral (só a designação)
function fameChaveCatalogo($designacao) {
    return mb_substr(mb_strtolower(trim(preg_replace('/\s+/', ' ', (string)$designacao)), 'UTF-8'), 0, 191);
}

// Quantidade escrita pelo utilizador ("1.234,5" / "12,5" / "12.5") → número
function fameNum($v) {
    $v = trim((string)$v);
    if (strpos($v, ',') !== false) $v = str_replace(',', '.', str_replace('.', '', $v));
    return max(0, (float)$v);
}
function fameQtd($v) {
    $v = (float)$v;
    return (floor($v) == $v) ? number_format($v, 0, ',', '.') : number_format($v, 2, ',', '.');
}

// ---------- Especialidades ----------
function fameEspecialidades($pdo) {
    global $FAME_ESPECIALIDADES;
    $lista = $FAME_ESPECIALIDADES;
    try {
        $st = $pdo->prepare("SELECT valor FROM configuracoes WHERE chave = 'fame_especialidades_extra' LIMIT 1");
        $st->execute();
        $extra = json_decode((string)$st->fetchColumn(), true);
        if (is_array($extra)) foreach ($extra as $x) if (trim($x) !== '') $lista[] = $x;
    } catch (Exception $e) {}
    return $lista;
}
// Grava uma especialidade nova (escrita em "Outra") para aparecer nas próximas vezes
function fameJuntarEspecialidade($pdo, $esp) {
    $esp = trim((string)$esp);
    if ($esp === '') return;
    foreach (fameEspecialidades($pdo) as $x) if (mb_strtolower($x, 'UTF-8') === mb_strtolower($esp, 'UTF-8')) return;
    global $FAME_ESPECIALIDADES;
    $extra = array_values(array_diff(fameEspecialidades($pdo), $FAME_ESPECIALIDADES));
    $extra[] = $esp;
    $json = json_encode($extra, JSON_UNESCAPED_UNICODE);
    $pdo->prepare("INSERT INTO configuracoes (chave, valor) VALUES ('fame_especialidades_extra', ?) ON DUPLICATE KEY UPDATE valor = ?")->execute([$json, $json]);
}

// ---------- Obra / orçamento ----------
function fameObra($pdo, $id_obra) {
    $st = $pdo->prepare("SELECT o.*, c.nome AS cliente_nome FROM obras o LEFT JOIN clientes c ON o.id_cliente = c.id WHERE o.id = ?");
    $st->execute([$id_obra]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

// Orçamento da obra: pela ligação id_orcamento; se não houver, pelo número partilhado (referência da obra = nº do orçamento)
function fameOrcamentoDaObra($pdo, $obra) {
    try {
        if (!empty($obra['id_orcamento'])) {
            $st = $pdo->prepare("SELECT * FROM orcamentos WHERE id = ?");
            $st->execute([(int)$obra['id_orcamento']]);
            if ($o = $st->fetch(PDO::FETCH_ASSOC)) return $o;
        }
        if (!empty($obra['referencia_obra'])) {
            $st = $pdo->prepare("SELECT * FROM orcamentos WHERE numero_orcamento = ? ORDER BY (estado = 'Adjudicado') DESC, id DESC LIMIT 1");
            $st->execute([$obra['referencia_obra']]);
            if ($o = $st->fetch(PDO::FETCH_ASSOC)) return $o;
        }
    } catch (Exception $e) {}
    return null;
}

// Linhas do orçamento, somadas por designação + unidade (o mesmo material em vários capítulos entra uma vez só,
// com a quantidade total e todas as Ref. / Art.). Cada material guarda os capítulos onde está e as especialidades
// sugeridas por esses capítulos (ex.: tubo no Elétrico e no ITED → "Elétrico;ITED").
// Cada linha guarda o capítulo (último título antes dela) e a posição, para importar pela ordem do orçamento.
// Por defeito vêm TODAS as linhas com quantidade (material, mão de obra, linhas sem preço de custo...):
// na FAME apaga-se o que não interessa. Linhas sem quantidade = títulos (capítulos).
// Usa o Detalhe Interno; se o orçamento não tiver linhas no Detalhe Interno (ex.: orçamento só comercial / pela
// global), usa as linhas comerciais.
// $alfabetica = true → ordem alfabética (listas de compras); false → ordem do orçamento.
// $so_material = true → só material (com preço de custo), como dantes — usado no material a aprovisionar sem FAME.
function fameMateriaisOrcamento($pdo, $id_orcamento, $alfabetica = true, $so_material = false) {
    $materiais = [];
    if (!$id_orcamento) return $materiais;
    $st = $pdo->prepare("SELECT * FROM orcamento_itens WHERE orcamento_id = ? ORDER BY id ASC");
    $st->execute([$id_orcamento]);
    $todas = array_values(array_filter($st->fetchAll(PDO::FETCH_ASSOC), fn($l) => empty($l['excluida'])));   // linhas excluídas do orçamento não entram na FAME
    $tem_interno = false;
    foreach ($todas as $it) {
        if (($it['tipo_linha'] ?? 'Interno') !== 'Comercial' && (float)($it['quantidade'] ?? 0) > 0) { $tem_interno = true; break; }
    }
    $capitulo = ''; $pos = 0;
    foreach ($todas as $it) {
        $comercial = (($it['tipo_linha'] ?? 'Interno') === 'Comercial');
        if ($tem_interno ? $comercial : !$comercial) continue;
        $desig = trim(preg_replace('/\s+/', ' ', (string)($it['designacao'] ?? '')));
        if ($desig === '') continue;
        $qtd = (float)($it['quantidade'] ?? 0);
        $pc  = (float)($it['preco_unitario_custo'] ?? 0);
        if ($qtd <= 0) { $capitulo = $desig; continue; }   // linha de título
        // Linha com vários artigos da tabela de preços (composição): entra cada artigo, com a quantidade
        // da linha × quantidade do artigo por unidade, e a sua marca / ficha técnica
        $comp = json_decode((string)($it['composicao_json'] ?? ''), true);
        if (is_array($comp) && $comp) {
            foreach ($comp as $c) {
                $d_c = trim(preg_replace('/\s+/', ' ', (string)($c['desc'] ?? '')));
                $q_c = (float)($c['q'] ?? 0) * $qtd;
                if ($d_c === '' || $q_c <= 0) continue;
                if ($so_material && (float)($c['pc'] ?? 0) <= 0) continue;
                fameJuntarMaterial($materiais, $d_c, trim((string)($c['un'] ?? '')), $q_c, $capitulo, $pos, (string)($it['artigo'] ?? ''), (int)($c['id'] ?? 0));
            }
            continue;
        }
        if ($so_material && $pc <= 0) continue;            // mão de obra / sem custo
        $unid = trim((string)($it['unidade'] ?? ''));
        $k = fameChave($desig, $unid);
        if (!isset($materiais[$k])) $materiais[$k] = ['chave' => $k, 'designacao' => $desig, 'unidade' => $unid, 'quantidade' => 0, 'capitulo' => $capitulo,
                                                      'capitulos' => [], 'esps' => [], 'pos' => ++$pos, 'artigos' => [], 'artigo' => '', 'id_artigo' => 0];
        // Artigo da tabela de preços escolhido no orçamento (marca + ficha técnica)
        if (!$materiais[$k]['id_artigo'] && !empty($it['id_artigo_tabela'])) $materiais[$k]['id_artigo'] = (int)$it['id_artigo_tabela'];
        $materiais[$k]['quantidade'] += $qtd;
        if (!in_array($capitulo, $materiais[$k]['capitulos'], true)) $materiais[$k]['capitulos'][] = $capitulo;
        $esp_cap = fameEspecialidadeDoCapitulo($capitulo);
        if ($esp_cap !== '' && !in_array($esp_cap, $materiais[$k]['esps'], true)) $materiais[$k]['esps'][] = $esp_cap;
        // Ref / Artigo do orçamento (a mesma designação em várias linhas junta os artigos: "1.1, 2.3")
        $art = trim((string)($it['artigo'] ?? ''));
        if ($art !== '' && !in_array($art, $materiais[$k]['artigos'], true)) {
            $materiais[$k]['artigos'][] = $art;
            $materiais[$k]['artigo'] = mb_substr(implode(', ', $materiais[$k]['artigos']), 0, 120);
        }
    }
    if ($alfabetica) fameOrdenarAlfabetica($materiais);
    return $materiais;
}

// Junta um material à lista (somado por designação + unidade), com capítulo, especialidade sugerida e Ref. / Art.
function fameJuntarMaterial(&$materiais, $desig, $unid, $qtd, $capitulo, &$pos, $art, $id_artigo = 0) {
    $k = fameChave($desig, $unid);
    if (!isset($materiais[$k])) $materiais[$k] = ['chave' => $k, 'designacao' => $desig, 'unidade' => $unid, 'quantidade' => 0, 'capitulo' => $capitulo,
                                                  'capitulos' => [], 'esps' => [], 'pos' => ++$pos, 'artigos' => [], 'artigo' => '', 'id_artigo' => 0];
    if (!$materiais[$k]['id_artigo'] && $id_artigo) $materiais[$k]['id_artigo'] = (int)$id_artigo;
    $materiais[$k]['quantidade'] += $qtd;
    if (!in_array($capitulo, $materiais[$k]['capitulos'], true)) $materiais[$k]['capitulos'][] = $capitulo;
    $esp_cap = fameEspecialidadeDoCapitulo($capitulo);
    if ($esp_cap !== '' && !in_array($esp_cap, $materiais[$k]['esps'], true)) $materiais[$k]['esps'][] = $esp_cap;
    $art = trim((string)$art);
    if ($art !== '' && !in_array($art, $materiais[$k]['artigos'], true)) {
        $materiais[$k]['artigos'][] = $art;
        $materiais[$k]['artigo'] = mb_substr(implode(', ', $materiais[$k]['artigos']), 0, 120);
    }
}

function fameOrdenarAlfabetica(&$lista) {
    uasort($lista, function ($a, $b) {
        $r = strnatcmp(fameOrdenacao($a['designacao']), fameOrdenacao($b['designacao']));
        return $r !== 0 ? $r : strcmp((string)$a['unidade'], (string)$b['unidade']);
    });
}

// Designação a usar para um material do orçamento: a corrigida na memória (se houver), senão a do orçamento
function fameDesignacaoMemoria($designacao, $cat) {
    $m = trim(preg_replace('/\s+/', ' ', (string)($cat['designacao'] ?? '')));
    return $m !== '' ? $m : $designacao;
}

// Memória geral: [chave => linha]
function fameCatalogo($pdo) {
    $res = [];
    try {
        foreach ($pdo->query("SELECT * FROM fame_catalogo ORDER BY designacao ASC")->fetchAll(PDO::FETCH_ASSOC) as $r) $res[$r['chave']] = $r;
    } catch (Exception $e) {}
    return $res;
}

// ---------- FAME da obra ----------
// A FAME da obra (cabeçalho: revisão, data, destinatário, observações). Cria-a se ainda não existir.
function fameDaObra($pdo, $id_obra, $criar = true) {
    $st = $pdo->prepare("SELECT * FROM obras_fames WHERE id_obra = ? ORDER BY id ASC LIMIT 1");
    $st->execute([$id_obra]);
    $f = $st->fetch(PDO::FETCH_ASSOC);
    if ($f || !$criar) return $f ?: null;
    $pdo->prepare("INSERT INTO obras_fames (id_obra, especialidade, revisao, data, destinatario, observacoes, criado_em) VALUES (?, '', 0, CURDATE(), '', '', NOW())")->execute([$id_obra]);
    return fameDaObra($pdo, $id_obra, false);
}
function fameLinhasDaFame($pdo, $id_fame) {
    $st = $pdo->prepare("SELECT * FROM obras_fame WHERE id_fame = ? ORDER BY ordem ASC, id ASC");
    $st->execute([$id_fame]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}
// Linhas da FAME agrupadas por especialidade (pela ordem da lista); sem especialidade → 'Geral'.
// Uma linha com várias especialidades entra em cada uma delas (sai em cada FAME).
function fameLinhasPorEspecialidade($linhas, $so_imprimir = false) {
    $g = [];
    foreach ($linhas as $l) {
        if ($so_imprimir && !$l['incluir']) continue;
        $esps = fameEspsLista($l['especialidade'] ?? '') ?: ['Geral'];
        foreach ($esps as $esp) $g[$esp][] = $l;
    }
    return $g;
}

// Material do orçamento que ainda não está na FAME da obra (ordem do orçamento)
function fameDisponiveis($pdo, $id_obra, $id_orcamento) {
    $materiais = fameMateriaisOrcamento($pdo, $id_orcamento, false);
    if (!$materiais) return [];
    fameAtualizarChaves($pdo, $id_obra, $materiais);
    $st = $pdo->prepare("SELECT chave FROM obras_fame WHERE id_obra = ? AND manual = 0 AND id_fame IS NOT NULL");
    $st->execute([$id_obra]);
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $k) unset($materiais[$k]);
    return $materiais;
}

// Linhas da FAME com chave "designação|unidade|especialidade" (versão em que o mesmo material ficava separado por
// especialidade): voltam à chave "designação|unidade". Se o mesmo material estava em várias linhas, juntam-se numa
// só (a primeira), com as quantidades somadas, todas as especialidades e todas as Ref. / Art.
// $materiais = fameMateriaisOrcamento(..., false) (ou null para os ir buscar)
function fameAtualizarChaves($pdo, $id_obra, $materiais = null, $id_orcamento = 0) {
    if ($materiais === null) $materiais = fameMateriaisOrcamento($pdo, $id_orcamento, false);
    if (!$materiais) return 0;
    $st = $pdo->prepare("SELECT id, chave, especialidade, quantidade, artigo FROM obras_fame WHERE id_obra = ? AND manual = 0 ORDER BY ordem ASC, id ASC");
    $st->execute([$id_obra]);
    $linhas = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!$linhas) return 0;
    $guarda = [];   // chave nova => linha que fica
    foreach ($linhas as $l) if (isset($materiais[$l['chave']])) $guarda[$l['chave']] = $l;
    $upd = $pdo->prepare("UPDATE obras_fame SET chave = ?, quantidade = ?, especialidade = ?, artigo = ? WHERE id = ?");
    $del = $pdo->prepare("DELETE FROM obras_fame WHERE id = ?");
    $n = 0;
    foreach ($linhas as $l) {
        $ch = (string)$l['chave'];
        if (isset($materiais[$ch]) || substr_count($ch, '|') < 2) continue;
        $nova = substr($ch, 0, strrpos($ch, '|'));
        if (!isset($materiais[$nova])) continue;
        if (!isset($guarda[$nova])) {
            $upd->execute([$nova, $l['quantidade'], fameEspsTexto(fameEspsLista($l['especialidade'])), $l['artigo'], $l['id']]);
            $l['chave'] = $nova;
            $guarda[$nova] = $l;
        } else {
            $g = &$guarda[$nova];
            $g['quantidade'] = (float)$g['quantidade'] + (float)$l['quantidade'];
            $g['especialidade'] = fameEspsTexto(array_merge(fameEspsLista($g['especialidade']), fameEspsLista($l['especialidade'])));
            $arts = array_filter(array_map('trim', explode(',', (string)$g['artigo'] . ',' . (string)$l['artigo'])), 'strlen');
            $g['artigo'] = mb_substr(implode(', ', array_values(array_unique($arts))), 0, 120);
            $upd->execute([$nova, $g['quantidade'], $g['especialidade'], $g['artigo'], $g['id']]);
            $del->execute([$l['id']]);
            unset($g);
        }
        $n++;
    }
    return $n;
}

// Capítulos da janela "Importar do orçamento" (pela mesma ordem com que a janela os mostra): [nº => capítulo]
function fameGruposEscolha($disponiveis) {
    $caps = [];
    foreach ($disponiveis as $m) { $c = $m['capitulo'] !== '' ? $m['capitulo'] : 'Sem capítulo'; if (!in_array($c, $caps, true)) $caps[] = $c; }
    $res = []; $gi = 0;
    foreach ($caps as $c) $res[++$gi] = $c;
    return $res;
}

// Especialidade sugerida pelo capítulo do orçamento ('' se nenhuma corresponder)
function fameEspecialidadeDoCapitulo($capitulo) {
    global $FAME_PALAVRAS;
    $n = fameNormTitulo($capitulo);
    foreach ($FAME_PALAVRAS as $esp => $re) if (preg_match('/' . $re . '/', $n)) return $esp;
    return '';
}

// Junta material do orçamento à FAME, já com o que estiver na memória geral.
// $escolhidos = ['g|chave', ...] (g = nº do capítulo na janela); $esp_grupos = [g => especialidade]
// Cada linha fica com as especialidades de todos os capítulos onde o material está: a escolhida na janela para
// cada capítulo (se o capítulo lá aparece) ou a sugerida pelo título do capítulo.
function fameImportar($pdo, $id_obra, $id_fame, $id_orcamento, $escolhidos, $esp_grupos = []) {
    $quero = [];
    foreach ((array)$escolhidos as $v) {
        $p = explode('|', (string)$v, 2);
        if (count($p) === 2) $quero[$p[1]] = 1;
    }
    if (!$quero) return 0;
    $disp = fameDisponiveis($pdo, $id_obra, $id_orcamento);
    $esp_cap = [];   // capítulo => especialidade escolhida na janela
    foreach (fameGruposEscolha($disp) as $gi => $cap) if (array_key_exists($gi, (array)$esp_grupos)) $esp_cap[$cap] = trim((string)$esp_grupos[$gi]);
    $catalogo = fameCatalogo($pdo);
    $bib = fameBiblioteca($pdo);
    $arts = fameDadosArtigos($pdo, $disp);
    $ordem = (int)$pdo->query("SELECT COALESCE(MAX(ordem), 0) FROM obras_fame WHERE id_fame = " . (int)$id_fame)->fetchColumn();
    $ins = $pdo->prepare("INSERT INTO obras_fame (id_obra, id_fame, ordem, chave, artigo, designacao, unidade, quantidade, especialidade, fabricante, referencia, ficha_tecnica, id_ficha_tecnica, incluir, manual)
                          VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, 0)");
    $n = 0;
    foreach ($disp as $k => $m) {
        if (!array_key_exists($k, $quero)) continue;
        $cat = $catalogo[fameChaveCatalogo($m['designacao'])] ?? [];
        $lista = [];
        foreach (($m['capitulos'] ?: [$m['capitulo']]) as $cap) {
            $c = $cap !== '' ? $cap : 'Sem capítulo';
            $x = array_key_exists($c, $esp_cap) ? $esp_cap[$c] : fameEspecialidadeDoCapitulo($cap);
            if ($x !== '') $lista[] = $x;
        }
        $esp = fameEspsTexto($lista);
        foreach (fameEspsLista($esp) as $x) fameJuntarEspecialidade($pdo, $x);
        [$fab, $ref, $fic, $id_fic] = fameValoresMaterial($m, $cat, $arts, $bib);
        $ins->execute([$id_obra, $id_fame, ++$ordem, $k, $m['artigo'], fameDesignacaoMemoria($m['designacao'], $cat), $m['unidade'], $m['quantidade'], $esp,
                       $fab, $ref, $fic, $id_fic ?: null]);
        $n++;
    }
    return $n;
}

// Repõe as linhas do orçamento original: apaga as linhas do orçamento (alteradas, apagadas ou mudadas de sítio)
// e volta a pôr todo o material do orçamento, pela ordem do orçamento, com as quantidades do orçamento e a
// especialidade sugerida pelo capítulo. Fabricante / referência / ficha técnica vêm da memória geral.
// $manter_manuais = true → as linhas acrescentadas à mão ficam (no fim da lista); false → também são apagadas.
function fameReporOrcamento($pdo, $id_obra, $id_fame, $id_orcamento, $manter_manuais = true) {
    $materiais = fameMateriaisOrcamento($pdo, $id_orcamento, false);
    $catalogo = fameCatalogo($pdo);
    $bib = fameBiblioteca($pdo);
    $arts = fameDadosArtigos($pdo, $materiais);
    $pdo->prepare("DELETE FROM obras_fame WHERE id_fame = ?" . ($manter_manuais ? " AND manual = 0" : ""))->execute([$id_fame]);
    $ins = $pdo->prepare("INSERT INTO obras_fame (id_obra, id_fame, ordem, chave, artigo, designacao, unidade, quantidade, especialidade, fabricante, referencia, ficha_tecnica, id_ficha_tecnica, incluir, manual)
                          VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, 0)");
    $ordem = 0;
    foreach ($materiais as $k => $m) {
        $cat = $catalogo[fameChaveCatalogo($m['designacao'])] ?? [];
        [$fab, $ref, $fic, $id_fic] = fameValoresMaterial($m, $cat, $arts, $bib);
        $ins->execute([$id_obra, $id_fame, ++$ordem, $k, $m['artigo'], fameDesignacaoMemoria($m['designacao'], $cat), $m['unidade'], $m['quantidade'], fameEspsTexto($m['esps']),
                       $fab, $ref, $fic, $id_fic ?: null]);
    }
    // Linhas acrescentadas à mão passam para o fim, pela ordem que tinham
    if ($manter_manuais) {
        $st = $pdo->prepare("SELECT id FROM obras_fame WHERE id_fame = ? AND manual = 1 ORDER BY ordem ASC, id ASC");
        $st->execute([$id_fame]);
        $upd = $pdo->prepare("UPDATE obras_fame SET ordem = ? WHERE id = ?");
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $id_l) $upd->execute([++$ordem, $id_l]);
    }
    return count($materiais);
}

// HTML da lista de material do orçamento para escolher (agrupada por capítulo do orçamento)
// Cada capítulo traz a especialidade sugerida (pode ser mudada antes de importar)
function fameEscolherHTML($disponiveis, $especialidades = []) {
    $e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    if (!$disponiveis) return '<div class="esc-vazio"><i class="fa-solid fa-circle-check"></i> Todo o material do orçamento já está na FAME.</div>';
    $grupos = [];
    foreach ($disponiveis as $m) $grupos[$m['capitulo'] !== '' ? $m['capitulo'] : 'Sem capítulo'][] = $m;
    $h = '<div class="esc-barra"><span class="esc-contagem"><b>0</b> selecionado(s)</span>'
       . '<span><button type="button" class="esc-link" onclick="escTodos(this, true)">Todos</button> · <button type="button" class="esc-link" onclick="escTodos(this, false)">Nenhum</button></span></div>';
    $h .= '<div class="esc-lista">';
    $gi = 0;
    foreach ($grupos as $cap => $itens) {
        $gi++;
        $sug = fameEspecialidadeDoCapitulo($cap);
        $sel = '<select name="esp_grupo[' . $gi . ']" class="esc-esp" title="Especialidade das linhas deste capítulo"><option value="">Sem especialidade</option>';
        foreach ($especialidades as $esp) $sel .= '<option' . ($esp === $sug ? ' selected' : '') . '>' . $e($esp) . '</option>';
        $sel .= '</select>';
        $h .= '<div class="esc-grupo" data-norm="' . $e(fameNormTitulo($cap)) . '">'
            . '<div class="esc-cap-linha"><label class="esc-cap"><input type="checkbox" class="esc-chk-grupo" onchange="escGrupo(this)"> ' . $e($cap) . ' <small>(' . count($itens) . ')</small></label>' . $sel . '</div>';
        foreach ($itens as $m) {
            $outras = array_values(array_filter($m['esps'] ?? [], fn($x) => $x !== $sug));
            $h .= '<label class="esc-item"' . ($outras ? ' title="Também noutros capítulos: ' . $e(implode(', ', $outras)) . '"' : '') . '><input type="checkbox" name="importar[]" value="' . $gi . '|' . $e($m['chave']) . '" class="esc-chk" onchange="escContar(this)">'
                . '<span class="esc-desig">' . ($m['artigo'] !== '' ? '<b class="esc-art">' . $e($m['artigo']) . '</b> ' : '') . $e($m['designacao']) . (count($m['capitulos'] ?? []) > 1 ? ' <small class="esc-tb">+ ' . $e(implode(' + ', $outras ?: ['outro capítulo'])) . '</small>' : '') . '</span><span class="esc-qtd">' . fameQtd($m['quantidade']) . ' ' . $e($m['unidade']) . '</span></label>';
        }
        $h .= '</div>';
    }
    return $h . '</div>';
}

// CSS + JavaScript da lista de escolha (incluído nas páginas que a usam)
function fameEscolherRecursos() {
    ob_start(); ?>
    <style>
        .esc-barra { display: flex; justify-content: space-between; align-items: center; font-size: 12px; color: #576574; margin: 4px 0 8px; gap: 10px; }
        .esc-link { background: none; border: none; color: #2980b9; font-weight: 700; cursor: pointer; padding: 0; font-family: inherit; font-size: 12px; }
        .esc-lista { position: relative; max-height: 46vh; overflow-y: auto; border: 1px solid #e1e5ea; border-radius: 10px; padding: 4px 10px; background: #fcfdfe; }
        .esc-grupo { padding: 6px 0; border-bottom: 1px solid #f1f2f6; }
        .esc-grupo:last-child { border-bottom: none; }
        .esc-cap-linha { display: flex; align-items: center; justify-content: space-between; gap: 10px; flex-wrap: wrap; }
        .esc-cap { display: flex; align-items: center; gap: 8px; font-weight: 800; font-size: 12px; color: #2c3e50; text-transform: uppercase; padding: 4px 0; cursor: pointer; }
        .esc-esp { width: auto !important; padding: 5px 8px !important; font-size: 12px !important; border: 1px solid #dcdde1; border-radius: 6px; color: #2c3e50; background: #fff; }
        .esc-cap small { color: #95a5a6; font-weight: 600; }
        .esc-item { display: flex; align-items: center; gap: 8px; padding: 5px 0 5px 22px; font-size: 13px; cursor: pointer; }
        .esc-desig { flex: 1; color: #2c3e50; line-height: 1.3; }
        .esc-art { color: #8e44ad; font-weight: 800; margin-right: 4px; }
        .esc-tb { color: #16a085; font-weight: 700; font-size: 11px; white-space: nowrap; }
        .esc-qtd { color: #7f8c8d; font-weight: 700; white-space: nowrap; font-size: 12px; }
        .esc-vazio { text-align: center; color: #27ae60; padding: 16px; font-weight: 600; font-size: 13px; }
        .esc-lista input[type=checkbox] { width: 16px; height: 16px; flex-shrink: 0; accent-color: #2ecc71; }
    </style>
    <script>
        function escCaixa(el) { return el.closest('.esc-caixa'); }
        function escContar(el) {
            const cx = escCaixa(el); if (!cx) return;
            cx.querySelectorAll('.esc-grupo').forEach(g => {
                const itens = g.querySelectorAll('.esc-chk'), marc = g.querySelectorAll('.esc-chk:checked').length;
                const cg = g.querySelector('.esc-chk-grupo');
                cg.checked = marc === itens.length && marc > 0; cg.indeterminate = marc > 0 && marc < itens.length;
            });
            const n = cx.querySelectorAll('.esc-chk:checked').length;
            const c = cx.querySelector('.esc-contagem b'); if (c) c.textContent = n;
            cx.dispatchEvent(new CustomEvent('esc-mudou', { detail: n, bubbles: true }));
        }
        function escGrupo(chk) { chk.closest('.esc-grupo').querySelectorAll('.esc-chk').forEach(c => c.checked = chk.checked); escContar(chk); }
        function escTodos(btn, v) { escCaixa(btn).querySelectorAll('.esc-chk').forEach(c => c.checked = v); escContar(btn); }
    </script>
    <?php return ob_get_clean();
}

// ---------- Material a aprovisionar ----------
// [especialidade => [materiais somados por designação + unidade + fabricante + referência, por ordem alfabética]]
// As especialidades saem pela ordem da lista de especialidades (Elétrico, ITED, Segurança, Deteção de incêndios,
// depois as acrescentadas em "Outra"), as restantes por ordem alfabética e "Geral" no fim.
// Cada material traz: designacao, unidade, quantidade, especialidade, fabricante, referencia.
// Sem linhas na FAME → ['Orçamento' => material do orçamento] (fabricante / referência da memória, se houver)
function fameMaterialAprovisionar($pdo, $id_obra, $id_orcamento) {
    $fame = fameDaObra($pdo, $id_obra, false);
    $linhas = $fame ? fameLinhasDaFame($pdo, $fame['id']) : [];
    if (!$linhas) {
        $m0 = fameMateriaisOrcamento($pdo, $id_orcamento, true, true);
        if (!$m0) return [];
        $m = [];   // lista de compras: o mesmo material de várias especialidades soma-se
        foreach ($m0 as $x) {
            $k2 = fameChave($x['designacao'], $x['unidade']);
            if (!isset($m[$k2])) { $m[$k2] = $x; $m[$k2]['quantidade'] = 0; }
            $m[$k2]['quantidade'] += $x['quantidade'];
        }
        $catalogo = fameCatalogo($pdo);
        foreach ($m as &$x) {
            $cat = $catalogo[fameChaveCatalogo($x['designacao'])] ?? [];
            $x['designacao']    = fameDesignacaoMemoria($x['designacao'], $cat);
            $x['especialidade'] = 'Orçamento';
            $x['fabricante']    = (string)($cat['fabricante'] ?? '');
            $x['referencia']    = (string)($cat['referencia'] ?? '');
        }
        unset($x);
        fameOrdenarAlfabetica($m);
        return ['Orçamento' => $m];
    }
    // Cada linha conta UMA vez (não se encomenda a dobrar): fica na 1.ª especialidade e mostra todas ("Elétrico + ITED")
    $res = [];
    foreach ($linhas as $l) {
        $desig = trim(preg_replace('/\s+/', ' ', (string)$l['designacao']));
        if ($desig === '' || (float)$l['quantidade'] <= 0) continue;
        $esps = fameEspsLista($l['especialidade'] ?? '') ?: ['Geral'];
        $esp = $esps[0];
        $mostrar = implode(' + ', $esps);
        $fab = trim((string)($l['fabricante'] ?? ''));
        $ref = trim((string)($l['referencia'] ?? ''));
        $k = fameChave($desig, $l['unidade']) . '|' . mb_strtolower($fab . '|' . $ref . '|' . $mostrar, 'UTF-8');
        if (!isset($res[$esp][$k])) $res[$esp][$k] = ['designacao' => $desig, 'unidade' => (string)$l['unidade'], 'quantidade' => 0,
                                                     'especialidade' => $mostrar, 'fabricante' => $fab, 'referencia' => $ref];
        $res[$esp][$k]['quantidade'] += (float)$l['quantidade'];
    }
    foreach ($res as &$lista) fameOrdenarAlfabetica($lista);
    unset($lista);

    // Ordem das especialidades
    $ordem = [];
    foreach (fameEspecialidades($pdo) as $i => $x) $ordem[fameOrdenacao($x)] = $i;
    uksort($res, function ($a, $b) use ($ordem) {
        $ga = ($a === 'Geral') ? 2 : (isset($ordem[fameOrdenacao($a)]) ? 0 : 1);
        $gb = ($b === 'Geral') ? 2 : (isset($ordem[fameOrdenacao($b)]) ? 0 : 1);
        if ($ga !== $gb) return $ga - $gb;
        if ($ga === 0) return $ordem[fameOrdenacao($a)] - $ordem[fameOrdenacao($b)];
        return strnatcmp(fameOrdenacao($a), fameOrdenacao($b));
    });
    return $res;
}

// ---------- Fichas técnicas (biblioteca fichas_tecnicas/, ver fichas_tecnicas.php) ----------
// Nome que aparece na FAME (regra da pesquisa de material): "Modelo | Referência do fabricante | Marca"
function fameFichaNome($f) {
    $p = array_filter([trim((string)($f['modelo'] ?? '')), trim((string)($f['referencia'] ?? '')), trim((string)($f['marca'] ?? ''))], 'strlen');
    return preg_replace('/\s+/', ' ', implode(' | ', $p));
}
// Nome no formato antigo ("Marca Modelo"): as FAME já gravadas guardam este texto e continuam ligadas
function fameFichaNomeAntigo($f) { return trim(preg_replace('/\s+/', ' ', (string)($f['marca'] ?? '') . ' ' . (string)($f['modelo'] ?? ''))); }

// Biblioteca: ['id' => [id => ficha + nome + url], 'nome' => [nome em minúsculas => id] (só as ativas)]
function fameBiblioteca($pdo) {
    static $cache = null;
    if ($cache !== null) return $cache;
    $cache = ['id' => [], 'nome' => []];
    try {
        foreach ($pdo->query("SELECT * FROM fichas_tecnicas ORDER BY marca ASC, modelo ASC")->fetchAll(PDO::FETCH_ASSOC) as $f) {
            $f['nome'] = fameFichaNome($f);
            $f['nome_antigo'] = fameFichaNomeAntigo($f);
            $f['url'] = 'fichas_tecnicas/' . rawurlencode(basename((string)$f['ficheiro']));
            $f['existe'] = $f['ficheiro'] !== '' && is_file('fichas_tecnicas/' . basename((string)$f['ficheiro']));
            $cache['id'][(int)$f['id']] = $f;
            if ((int)$f['ativo'] === 1) {
                $cache['nome'][mb_strtolower($f['nome'], 'UTF-8')] = (int)$f['id'];
                $k_ant = mb_strtolower($f['nome_antigo'], 'UTF-8');
                if (!isset($cache['nome'][$k_ant])) $cache['nome'][$k_ant] = (int)$f['id'];
            }
        }
    } catch (Exception $e) {}   // ainda não há biblioteca
    return $cache;
}

// Ficha de uma linha: pela ligação gravada; senão pelo texto escrito (igual ao nome de uma ficha da biblioteca)
function fameResolverFicha($bib, $id, $texto) {
    $id = (int)$id;
    if ($id && isset($bib['id'][$id])) {
        // se o texto foi mudado para outra coisa, deixa de estar ligada
        $t = mb_strtolower(trim((string)$texto), 'UTF-8');
        if ($t === '' || $t === mb_strtolower($bib['id'][$id]['nome'], 'UTF-8') || $t === mb_strtolower($bib['id'][$id]['nome_antigo'] ?? '', 'UTF-8')) return $id;
    }
    $t = mb_strtolower(trim(preg_replace('/\s+/', ' ', (string)$texto)), 'UTF-8');
    return ($t !== '' && isset($bib['nome'][$t])) ? $bib['nome'][$t] : 0;
}

// Marca e ficha dos artigos da tabela de preços usados no orçamento: [id_artigo => ['marca' =>, 'id_ficha' =>]]
function fameDadosArtigos($pdo, $materiais) {
    $ids = [];
    foreach ($materiais as $m) if (!empty($m['id_artigo'])) $ids[(int)$m['id_artigo']] = 1;
    if (!$ids) return [];
    $res = [];
    try {
        $lista = implode(',', array_map('intval', array_keys($ids)));
        foreach ($pdo->query("SELECT id, marca, id_ficha_tecnica FROM tabela_precos WHERE id IN ($lista)")->fetchAll(PDO::FETCH_ASSOC) as $r)
            $res[(int)$r['id']] = ['marca' => trim((string)($r['marca'] ?? '')), 'id_ficha' => (int)($r['id_ficha_tecnica'] ?? 0)];
    } catch (Exception $e) {}
    return $res;
}

// Fabricante / referência / ficha técnica de um material do orçamento.
// A ficha do artigo da tabela de preços tem prioridade; fabricante e referência vêm da memória e, se faltarem, da ficha.
function fameValoresMaterial($m, $cat, $arts, $bib) {
    $fab = (string)($cat['fabricante'] ?? ''); $ref = (string)($cat['referencia'] ?? '');
    $fic = (string)($cat['ficha_tecnica'] ?? ''); $id_fic = (int)($cat['id_ficha_tecnica'] ?? 0);
    $a = $arts[(int)($m['id_artigo'] ?? 0)] ?? null;
    if ($a) {
        $f = $a['id_ficha'] ? ($bib['id'][$a['id_ficha']] ?? null) : null;
        if ($f) {
            $id_fic = (int)$f['id']; $fic = $f['nome'];
            if (trim($fab) === '') $fab = (string)$f['marca'];
            if (trim($ref) === '') $ref = (string)$f['referencia'];
        }
        if (trim($fab) === '' && $a['marca'] !== '') $fab = $a['marca'];
    }
    if (!$id_fic) $id_fic = fameResolverFicha($bib, 0, $fic);
    return [mb_substr($fab, 0, 120), mb_substr($ref, 0, 120), mb_substr($fic, 0, 255), $id_fic];
}

// Anexos (fichas técnicas) das linhas que saem no PDF, pela ordem em que aparecem: [id_ficha => nº do anexo]
function fameAnexos($grupos, $bib) {
    $anexos = [];
    foreach ($grupos as $linhas_s) foreach ($linhas_s as $l) {
        $id = fameResolverFicha($bib, $l['id_ficha_tecnica'] ?? 0, $l['ficha_tecnica'] ?? '');
        if ($id && !empty($bib['id'][$id]['existe']) && !isset($anexos[$id])) $anexos[$id] = count($anexos) + 1;
    }
    return $anexos;
}

// Quem pode usar (as mesmas permissões das Obras)
function fameTemAcesso($pdo) {
    $perfil = $_SESSION['perfil'] ?? '';
    if (in_array($perfil, ['admin', 'administrativo'])) return true;
    $st = $pdo->prepare("SELECT modulo FROM permissoes WHERE grupo = :perfil AND pode_aceder = 1");
    $st->execute(['perfil' => $perfil]);
    return in_array('obras', $st->fetchAll(PDO::FETCH_COLUMN));
}
