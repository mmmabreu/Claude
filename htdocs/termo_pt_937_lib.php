<?php
/**
 * termo_pt_937_lib.php
 * Funções e mapa de posições do impresso INCM "Relatório-tipo do técnico responsável
 * pela exploração de instalações eléctricas" — Modelo n.º 937 (4 páginas A4).
 *
 * Usado por: termo_pt_937.php (carregar impresso / rever campos) e termo_pt_937_pdf.php (imprimir).
 *
 * Todas as coordenadas estão em pontos (pt) a partir do canto superior esquerdo da página A4
 * (595,28 x 841,89 pt) e foram medidas no impresso original da INCM.
 *   - segmentos de linha: [x_inicio, x_fim, y_da_linha]
 *   - caixas:             [x_inicio, x_fim, y_topo, y_base]
 */

if (!function_exists('m937_migrar')) {

// ---------------------------------------------------------------------------------
// Base de dados: coluna nova (não mexe em nada do que já existe)
// ---------------------------------------------------------------------------------
function m937_migrar($pdo) {
    try {
        $c = $pdo->query("SHOW COLUMNS FROM termos_pt LIKE 'modelo937_json'")->fetch();
        if (!$c) $pdo->exec("ALTER TABLE termos_pt ADD COLUMN modelo937_json MEDIUMTEXT NULL");
    } catch (Exception $e) {}
}

// ---------------------------------------------------------------------------------
// Itens com o padrão  "(6) ____ tendo detectado (7) ____ deficiências ____"
// s6 = espaço da nota (6) | s7 = espaço da nota (7) | def = linhas das deficiências
// ---------------------------------------------------------------------------------
function m937_itens() {
    return [
        '1_2_1' => ['p'=>1, 'n'=>'1.2.1', 'txt'=>'Nível do óleo nos transformadores e disjuntores de alta tensão',
            's6'=>[[382.5,567,692]], 's7'=>[[193.5,345,706.5]], 'def'=>[[397.5,567,706.5],[119,567,722],[119,567,738]]],
        '1_2_2' => ['p'=>2, 'n'=>'1.2.2', 'txt'=>'Estado dos contactos dos disjuntores e das câmaras de corte dos interruptores',
            's6'=>[[450.5,567,33.5]], 's7'=>[[189.5,328,49.5]], 'def'=>[[377.5,567,49.5],[114,567,66],[114,567,82]]],
        '1_2_3' => ['p'=>2, 'n'=>'1.2.3', 'txt'=>'Circuitos de terra e estado de conservação dos eléctrodos e condutores enterrados',
            's6'=>[[493.5,567,98]], 's7'=>[[189.5,326.5,114.5]], 'def'=>[[376.5,567,114.5],[114,567,130.5],[114,567,147]]],
        '1_2_4' => ['p'=>2, 'n'=>'1.2.4', 'txt'=>'Estado de conservação dos dispositivos de manobra (varas, estrados, tapetes, luvas…)',
            's6'=>[[204.5,329.5,179.5]], 's7'=>[[410.5,567,179.5]], 'def'=>[[158,567,195.5],[114,567,212]]],
        '1_2_5' => ['p'=>2, 'n'=>'1.2.5', 'txt'=>'Carga do transformador e temperatura do óleo nos períodos de maior carga',
            's6'=>[[446,562,228],[114,209,244]], 's7'=>[[292,514.5,244]], 'def'=>[[114,567,260.5],[114,567,276.5]]],
        '1_2_6' => ['p'=>2, 'n'=>'1.2.6', 'txt'=>'Estado de funcionamento dos dispositivos de protecção e alarme',
            's6'=>[[381,567,293]], 's7'=>[[177.5,334,309]], 'def'=>[[383.5,567,309],[100.5,567,325.5],[100.5,567,341.5]]],
        '2_2_1' => ['p'=>2, 'n'=>'2.2.1', 'txt'=>'Aparelhos de protecção contra sobreintensidades',
            's6'=>[[320.5,401.5,641.5]], 's7'=>[[482,567,641.5]], 'def'=>[[146.5,567,658],[101,567,674],[101,567,690.5]]],
        '2_2_2' => ['p'=>2, 'n'=>'2.2.2', 'txt'=>'Eficácia das protecções contra contactos indirectos',
            's6'=>[[324.5,485,706.5]], 's7'=>[[101,224,722.5]], 'def'=>[[274,567,722.5],[101,567,739],[101,567,756.5]]],
        '2_2_3' => ['p'=>3, 'n'=>'2.2.3', 'txt'=>'Aquecimento e estado do isolamento dos condutores e dos cabos',
            's6'=>[[383,489.5,33.5]], 's7'=>[[106,217,49.5]], 'def'=>[[267.5,567,49.5],[106,567,66],[106,567,82]]],
        '2_2_4' => ['p'=>3, 'n'=>'2.2.4', 'txt'=>'Estado dos aparelhos de corte e de comando',
            's6'=>[[310.5,398,98.5]], 's7'=>[[480.5,567,98.5]], 'def'=>[[153,567,114.5],[106,567,131],[106,567,147]]],
        '2_2_5' => ['p'=>3, 'n'=>'2.2.5', 'txt'=>'Estado dos aparelhos de utilização',
            's6'=>[[267,403.5,163.5]], 's7'=>[[485,567,163.5]], 'def'=>[[153,567,179.5],[106,567,196],[106,567,212.5]]],
        '2_2_6_1' => ['p'=>3, 'n'=>'2.2.6.1', 'txt'=>'Condições de arranque das fontes de alimentação das instalações de emergência',
            's6'=>[[483,567,253],[137,216.5,269]], 's7'=>[[296,434.5,269]], 'def'=>[[485,567,269],[137,567,285.5],[137,567,301.5]]],
        '2_2_6_2' => ['p'=>3, 'n'=>'2.2.6.2', 'txt'=>'Estado das baterias, nomeadamente o seu electrólito',
            's6'=>[[366.5,541,318]], 's7'=>[[187,326.5,334]], 'def'=>[[376,567,334],[137,567,350.5],[137,567,366.5]]],
        '2_2_6_3' => ['p'=>3, 'n'=>'2.2.6.3', 'txt'=>'Estado de funcionamento dos blocos autónomos',
            's6'=>[[350.5,485,383]], 's7'=>[[137,290.5,399.5]], 'def'=>[[343,567,399.5],[137,567,415.5],[137,567,431.5]]],
    ];
}

// ---------------------------------------------------------------------------------
// Campos de texto livre (escritos ao longo das linhas do impresso)
// ---------------------------------------------------------------------------------
function m937_textos() {
    $l = function($x, $ys) { $r = []; foreach ($ys as $y) $r[] = [$x, 567, $y]; return $r; };
    return [
        'm_1_1_4'   => ['p'=>1, 'n'=>'1.1.4', 'txt'=>'Acidez e rigidez dos óleos ou outros dieléctricos', 'seg'=>[[463.5,567,563],[107,567,579]]],
        'm_1_1_6'   => ['p'=>1, 'n'=>'1.1.6', 'txt'=>'Outros ensaios e medições', 'seg'=>[[214,567,610.5],[107,567,626]]],
        'obs_1_2_7' => ['p'=>2, 'n'=>'1.2.7', 'txt'=>'Outras verificações', 'seg'=>array_merge([[176.5,567,357.5]], $l(101, [374,390,406.5]))],
        'm_2_1_4'   => ['p'=>2, 'n'=>'2.1.4', 'txt'=>'Protecções contra contactos indirectos', 'seg'=>$l(100.5, [531,547])],
        'm_2_1_5'   => ['p'=>2, 'n'=>'2.1.5', 'txt'=>'Outros ensaios e medições', 'seg'=>array_merge([[208.5,567,563.5]], $l(101, [579.5,595.5]))],
        'obs_2_2_7' => ['p'=>3, 'n'=>'2.2.7', 'txt'=>'Métodos de trabalho susceptíveis de provocar contactos directos', 'seg'=>array_merge([[241.5,567,464.5]], $l(119, [480.5,497]))],
        'obs_2_2_8' => ['p'=>3, 'n'=>'2.2.8', 'txt'=>'Incorrecções quanto à execução de trabalhos nas instalações', 'seg'=>array_merge([[470,567,513]], $l(119, [529.5,545.5]))],
        'obs_2_2_9' => ['p'=>3, 'n'=>'2.2.9', 'txt'=>'Inexistência de materiais de reserva ou acessórios indispensáveis', 'seg'=>array_merge([[480.5,567,562]], $l(119, [578]))],
        'obs_2_2_10'=> ['p'=>3, 'n'=>'2.2.10', 'txt'=>'Existência de instruções de primeiros socorros nos pontos da instalação', 'seg'=>array_merge([[450.5,567,594.5]], $l(119, [611,627]))],
        'obs_2_2_11'=> ['p'=>3, 'n'=>'2.2.11', 'txt'=>'Alterações por utilizações diferentes das inicialmente previstas', 'seg'=>array_merge([[452.5,567,659.5]], $l(119, [676,692]))],
        'obs_2_2_12'=> ['p'=>3, 'n'=>'2.2.12', 'txt'=>'Necessidade de redimensionar a instalação', 'seg'=>array_merge([[250.5,567,724.5]], $l(119, [741,756.5]))],
        'obs_2_2_13'=> ['p'=>4, 'n'=>'2.2.13', 'txt'=>'Outros factos', 'seg'=>array_merge([[160,567,33.5]], $l(105, [49.5,65.5,82,98,114,130.5,146.5]))],
        'obs_3'     => ['p'=>4, 'n'=>'3', 'txt'=>'Outras instalações', 'seg'=>$l(55, [180.5,196.5,212.5,229,245,261,277.5,293.5,309.5])],
        'obs_4'     => ['p'=>4, 'n'=>'4', 'txt'=>'Modificações e ampliações para as quais não fui consultado', 'seg'=>array_merge([[449.5,567,343.5]], $l(55, [359.5,376,392,408]))],
        'obs_5'     => ['p'=>4, 'n'=>'5', 'txt'=>'Relações com o proprietário (medidas comunicadas e não concretizadas)', 'seg'=>array_merge([[115.5,567,472.5]], $l(55, [487.5,503.5,520,536,552,568.5]))],
    ];
}

// ---------------------------------------------------------------------------------
// Valores em caixa (alinhados à direita, antes da unidade impressa)
// ---------------------------------------------------------------------------------
function m937_caixas() {
    return [
        'm_1_1_1' => ['p'=>1, 'n'=>'1.1.1', 'txt'=>'Resistência da terra de protecção', 'un'=>'Ω',  'box'=>[465,535.5,505.5,515.5]],
        'm_1_1_2' => ['p'=>1, 'n'=>'1.1.2', 'txt'=>'Resistência da terra de serviço',   'un'=>'Ω',  'box'=>[465,535.5,521,531]],
        'm_1_1_3' => ['p'=>1, 'n'=>'1.1.3', 'txt'=>'Resistência de isolamento da instalação de BT', 'un'=>'MΩ', 'box'=>[465,535.5,537,547]],
        'm_1_1_5' => ['p'=>1, 'n'=>'1.1.5', 'txt'=>'Factor de potência (cos φ)',         'un'=>'',   'box'=>[500,535.5,584.5,594.5]],
        'm_2_1_1' => ['p'=>2, 'n'=>'2.1.1', 'txt'=>'Resistência da terra de protecção', 'un'=>'Ω',  'box'=>[464,535.5,455.5,465.5]],
        'm_2_1_2' => ['p'=>2, 'n'=>'2.1.2', 'txt'=>'Impedância do circuito de defeito', 'un'=>'Ω',  'box'=>[464,535.5,471.5,482]],
        'm_2_1_3' => ['p'=>2, 'n'=>'2.1.3', 'txt'=>'Resistência de isolamento',         'un'=>'MΩ', 'box'=>[464,535.5,488,498.5]],
    ];
}

// ---------------------------------------------------------------------------------
// Cabeçalho, identificação e fecho
// ---------------------------------------------------------------------------------
function m937_fixos() {
    return [
        // Página 1 — cabeçalho (normalmente já vem escrito no impresso comprado à INCM)
        'cond_boas'        => [553, 562, 133.5, 142],
        'cond_deficientes' => [553, 562, 149.5, 157.5],
        'cond_desistencia' => [553, 562, 165, 173.5],
        'periodo_ini'      => [[286, 415.5, 195]],
        'periodo_fim'      => [[428, 567, 195]],
        'ref1'             => [[59.5, 567, 230.5]],
        'ref2'             => [[60, 567, 262], [28.5, 567, 278]],
        'ref3'             => [[60, 567, 293.5], [28.5, 567, 309.5]],
        'ref4'             => [[60, 567, 325.5], [28.5, 567, 341]],
        'inscricao'        => [[223, 392, 357]],
        // Página 1 — inspecções efectuadas
        'dl'               => [[186, 567, 418.5], [29, 204, 435.5]],
        'dias'             => [[347.5, 567, 435.5], [29, 400.5, 451]],
        // Página 2 — sistema de protecção de pessoas
        'sis_TT'           => [244.5, 254, 430, 438.5],
        'sis_TN'           => [283, 292, 430, 438.5],
        'sis_IT'           => [321.5, 331, 430, 438.5],
        // Página 4 — fecho
        'anexos'           => [58, 130.5, 590],
        'data_dia'         => [49, 75.5, 606],
        'data_mes'         => [80, 107, 606],
        'data_ano'         => [111, 138, 606],
        'assinatura'       => [298, 567, 638.5],
    ];
}

// ---------------------------------------------------------------------------------
// Utilitários
// ---------------------------------------------------------------------------------
function m937_data_extenso($ymd) {
    if (!$ymd || $ymd === '0000-00-00') return '';
    $t = strtotime($ymd); if (!$t) return '';
    $meses = ['janeiro','fevereiro','março','abril','maio','junho','julho','agosto','setembro','outubro','novembro','dezembro'];
    return (int)date('j', $t) . ' de ' . $meses[(int)date('n', $t) - 1] . ' de ' . date('Y', $t);
}
function m937_dmy($ymd) {
    if (!$ymd || $ymd === '0000-00-00') return '';
    $t = strtotime($ymd); return $t ? date('d/m/Y', $t) : '';
}
function m937_num($v) {
    $v = trim((string)$v);
    if ($v !== '' && preg_match('/^-?\d+(\.\d+)?$/', $v)) $v = str_replace('.', ',', $v);
    return $v;
}
function m937_juntar(array $partes, $sep = ', ') {
    $r = [];
    foreach ($partes as $p) { $p = trim((string)$p); if ($p !== '') $r[] = $p; }
    return implode($sep, $r);
}

// ---------------------------------------------------------------------------------
// Assinatura do técnico responsável (imagem guardada em imagens/, referida em configuracoes)
// ---------------------------------------------------------------------------------
function m937_assinatura_get($pdo) {
    try {
        $st = $pdo->prepare("SELECT valor FROM configuracoes WHERE chave = 'assinatura_m937' LIMIT 1");
        $st->execute();
        $v = basename((string)$st->fetchColumn());
        if ($v !== '' && is_file('imagens/' . $v)) return 'imagens/' . $v;
    } catch (Exception $e) {}
    // Assinatura já existente no servidor (raiz do site): assina.png
    foreach (['assina.png', 'imagens/assina.png', 'assinatura.png', 'imagens/assinatura.png', 'assina.jpg', 'assinatura.jpg'] as $f) {
        if (!is_file($f)) continue;
        // Cópia com o fundo branco transparente (feita uma vez; refeita se o original mudar)
        $copia = 'imagens/assina_m937_v2.png';
        if (is_file($copia) && filemtime($copia) >= filemtime($f)) return $copia;
        if (m937_processar_assinatura($f, $copia)) return $copia;
        return $f;
    }
    return '';
}

// Recorta as margens e converte o papel branco em transparente, com contornos suaves
// (a tinta mantém a cor e as bordas ficam semitransparentes -> o traço fica limpo, sem serrilhado)
function m937_processar_assinatura($origem, $destino) {
    if (!function_exists('imagecreatetruecolor')) return false;
    $info = @getimagesize($origem);
    if (!$info) return false;
    if ($info[2] === IMAGETYPE_PNG) $src = @imagecreatefrompng($origem);
    elseif ($info[2] === IMAGETYPE_JPEG) $src = @imagecreatefromjpeg($origem);
    else return false;
    if (!$src) return false;
    $w = imagesx($src); $h = imagesy($src);

    // 1) Achatar sobre branco (um PNG já transparente fica com fundo branco, como o papel)
    $base = imagecreatetruecolor($w, $h);
    imagefill($base, 0, 0, imagecolorallocate($base, 255, 255, 255));
    imagealphablending($base, true);
    imagecopy($base, $src, 0, 0, 0, 0, $w, $h);
    imagedestroy($src);

    // 2) Tom do papel (claro) e da tinta (escuro)
    $hist = array_fill(0, 256, 0); $n = 0;
    $passo = max(1, (int)floor(max($w, $h) / 800));
    for ($y = 0; $y < $h; $y += $passo) for ($x = 0; $x < $w; $x += $passo) {
        $rgb = imagecolorat($base, $x, $y);
        $l = (int)((($rgb >> 16) & 255) * 0.299 + (($rgb >> 8) & 255) * 0.587 + ($rgb & 255) * 0.114);
        $hist[$l]++; $n++;
    }
    $acum = 0; $papel = 255; $tinta = 0;
    for ($l = 255; $l >= 0; $l--) { $acum += $hist[$l]; if ($acum >= $n * 0.40) { $papel = $l; break; } }
    $acum = 0;
    for ($l = 0; $l <= 255; $l++) { $acum += $hist[$l]; if ($acum >= max(1, $n * 0.01)) { $tinta = $l; break; } }
    if ($papel - $tinta < 40) { imagedestroy($base); return false; }   // sem contraste: não há assinatura
    $limiar = $papel - ($papel - $tinta) * 0.12;                       // abaixo disto é traço

    // 3) Recortar as margens
    $minX = $w; $minY = $h; $maxX = -1; $maxY = -1;
    $sr = 0; $sg = 0; $sb = 0; $sn = 0;
    for ($y = 0; $y < $h; $y++) for ($x = 0; $x < $w; $x++) {
        $rgb = imagecolorat($base, $x, $y);
        $r = ($rgb >> 16) & 255; $g = ($rgb >> 8) & 255; $b = $rgb & 255;
        $l = $r * 0.299 + $g * 0.587 + $b * 0.114;
        if ($l < $limiar) {
            if ($x < $minX) $minX = $x; if ($x > $maxX) $maxX = $x;
            if ($y < $minY) $minY = $y; if ($y > $maxY) $maxY = $y;
            if ($l < $tinta + ($papel - $tinta) * 0.35) { $sr += $r; $sg += $g; $sb += $b; $sn++; }
        }
    }
    if ($maxX < 0) { imagedestroy($base); return false; }
    // Cor da tinta = média dos pixels mais escuros (azul fica azul, preto fica preto)
    $ir = $sn ? (int)($sr / $sn) : 20; $ig = $sn ? (int)($sg / $sn) : 20; $ib = $sn ? (int)($sb / $sn) : 20;
    $mg = 4;
    $minX = max(0, $minX - $mg); $minY = max(0, $minY - $mg);
    $maxX = min($w - 1, $maxX + $mg); $maxY = min($h - 1, $maxY + $mg);
    $cw = $maxX - $minX + 1; $ch = $maxY - $minY + 1;

    // 4) Redimensionar para ~1000 px de largura (ampliar uma imagem pequena suaviza o traço no PDF)
    $fz = min(4, 1000 / $cw);
    $nw = max(1, (int)round($cw * $fz)); $nh = max(1, (int)round($ch * $fz));
    $tmp = imagecreatetruecolor($nw, $nh);
    imagecopyresampled($tmp, $base, 0, 0, $minX, $minY, $nw, $nh, $cw, $ch);
    imagedestroy($base);

    // 5) Opacidade proporcional à "escuridão" de cada pixel
    $dst = imagecreatetruecolor($nw, $nh);
    imagealphablending($dst, false); imagesavealpha($dst, true);
    $cores = [];
    for ($y = 0; $y < $nh; $y++) for ($x = 0; $x < $nw; $x++) {
        $rgb = imagecolorat($tmp, $x, $y);
        $l = (($rgb >> 16) & 255) * 0.299 + (($rgb >> 8) & 255) * 0.587 + ($rgb & 255) * 0.114;
        $t = ($papel - $l) / max(1, ($papel - $tinta) * 0.85);          // 0 = papel, 1 = tinta cheia
        $t = $t <= 0 ? 0 : ($t >= 1 ? 1 : $t);
        $a = 127 - (int)round(127 * pow($t, 0.75));
        if (!isset($cores[$a])) $cores[$a] = imagecolorallocatealpha($dst, $ir, $ig, $ib, $a);
        imagesetpixel($dst, $x, $y, $cores[$a]);
    }
    imagedestroy($tmp);
    if (!is_dir(dirname($destino))) @mkdir(dirname($destino), 0755, true);
    $ok = @imagepng($dst, $destino, 6);
    imagedestroy($dst);
    return $ok;
}
function m937_assinatura_set($pdo, $nome) {
    $st = $pdo->prepare("SELECT COUNT(*) FROM configuracoes WHERE chave = 'assinatura_m937'");
    $st->execute();
    if ((int)$st->fetchColumn() > 0) $pdo->prepare("UPDATE configuracoes SET valor = ? WHERE chave = 'assinatura_m937'")->execute([$nome]);
    else $pdo->prepare("INSERT INTO configuracoes (chave, valor) VALUES ('assinatura_m937', ?)")->execute([$nome]);
}

// Carrega o termo + cliente + PT (as colunas do cliente / PT entram com prefixo)
function m937_carregar_termo($pdo, $id) {
    $st = $pdo->prepare("SELECT * FROM termos_pt WHERE id = ?");
    $st->execute([$id]);
    $t = $st->fetch(PDO::FETCH_ASSOC);
    if (!$t) return null;
    $t['_cliente'] = [];
    $t['_pt'] = [];
    try {
        if (!empty($t['id_cliente'])) {
            $s = $pdo->prepare("SELECT * FROM clientes WHERE id = ?"); $s->execute([$t['id_cliente']]);
            $t['_cliente'] = $s->fetch(PDO::FETCH_ASSOC) ?: [];
        }
    } catch (Exception $e) {}
    try {
        if (!empty($t['id_pt'])) {
            $s = $pdo->prepare("SELECT * FROM postos_transformacao WHERE id = ?"); $s->execute([$t['id_pt']]);
            $t['_pt'] = $s->fetch(PDO::FETCH_ASSOC) ?: [];
        }
    } catch (Exception $e) {}
    return $t;
}

// Último Modelo 937 preenchido (para reaproveitar entidade (1), técnico (4) e n.º de inscrição)
function m937_ultimo($pdo, $id_atual) {
    try {
        $st = $pdo->prepare("SELECT modelo937_json FROM termos_pt WHERE id <> ? AND modelo937_json IS NOT NULL AND modelo937_json <> '' ORDER BY id DESC LIMIT 10");
        $st->execute([$id_atual]);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $j) {
            $d = json_decode((string)$j, true);
            if (!empty($d['campos']['ref4'])) return $d['campos'];
        }
    } catch (Exception $e) {}
    return [];
}

// Procura o texto descritivo de uma deficiência, seja qual for o nome usado no formulário
function m937_texto_def(array $def, array $obs, $k) {
    foreach (["d_$k", "def_$k", "txt_$k", "desc_$k", "v_{$k}_txt", "v_{$k}_desc", "v_{$k}_obs", "obs_$k", "{$k}_txt"] as $c) {
        if (isset($def[$c]) && trim((string)$def[$c]) !== '') return trim((string)$def[$c]);
        if (isset($obs[$c]) && trim((string)$obs[$c]) !== '') return trim((string)$obs[$c]);
    }
    return '';
}

// ---------------------------------------------------------------------------------
// Valores por defeito, tirados do que foi preenchido no termo_pt_form.php
// ---------------------------------------------------------------------------------
function m937_defaults(array $t, array $ultimo = []) {
    $med = json_decode((string)($t['medicoes_json'] ?? ''), true) ?: [];
    $def = json_decode((string)($t['deficiencias_json'] ?? ''), true) ?: [];
    $obs = json_decode((string)($t['observacoes_json'] ?? ''), true) ?: [];
    $cli = $t['_cliente'] ?? [];
    $ptr = $t['_pt'] ?? [];

    $cg = strtolower((string)($t['condicao_geral'] ?? ''));
    $cond = 'boas';
    if (strpos($cg, 'desist') !== false) $cond = 'desistencia';
    elseif (strpos($cg, 'defic') !== false || strpos($cg, 'nok') !== false) $cond = 'deficientes';

    $morada_cli = m937_juntar([$cli['morada'] ?? '', m937_juntar([$cli['codigo_postal'] ?? ($cli['cp'] ?? ''), $cli['localidade'] ?? ''], ' ')]);
    $nome_pt = trim((string)($ptr['nome'] ?? ''));
    $num_pt  = trim((string)($ptr['numero_pt'] ?? ''));
    $ref3 = 'Posto de Transformação' . ($nome_pt !== '' ? ' ' . $nome_pt : '') . ($num_pt !== '' ? ' n.º ' . $num_pt : '');
    if (!empty($ptr['morada'])) $ref3 .= ', ' . trim($ptr['morada']);
    if ($nome_pt === '' && $num_pt === '' && !empty($t['referencia_pt'])) $ref3 = 'Posto de Transformação ' . $t['referencia_pt'];

    $tec = trim((string)($t['tecnico_nome'] ?? ''));
    $ref4 = $tec;
    if (!empty($ultimo['ref4']) && ($tec === '' || mb_stripos($ultimo['ref4'], $tec) === 0)) $ref4 = $ultimo['ref4'];

    $data_fecho = !empty($t['data_emissao']) ? $t['data_emissao'] : ($t['data_inspecao'] ?? date('Y-m-d'));
    $tf = strtotime((string)$data_fecho) ?: time();

    $c = [
        'n_impresso'  => '',
        'cond'        => $cond,
        'periodo_ini' => m937_dmy($t['periodo_inicio'] ?? ''),
        'periodo_fim' => m937_dmy($t['periodo_fim'] ?? ''),
        'ref1'        => $ultimo['ref1'] ?? 'DGEG - Direção Geral de Energia e Geologia',
        'ref2'        => m937_juntar([$cli['nome'] ?? '', $morada_cli]),
        'ref3'        => $ref3,
        'ref4'        => $ref4,
        // N.º de inscrição na DGEG do técnico (o tecnico_n_inscricao do termo é o alvará da empresa)
        'inscricao'   => $ultimo['inscricao'] ?? '56678',
        'dl'          => trim((string)($t['dl_aplicavel'] ?? '')) ?: 'DL n.º 96/2017',
        'dias'        => m937_data_extenso($t['data_inspecao'] ?? ''),
        'sistema'     => strtoupper(trim((string)($t['sistema_protecao'] ?? ''))),
        'anexos'      => '',
        'data_dia'    => date('d', $tf),
        'data_mes'    => date('m', $tf),
        'data_ano'    => date('Y', $tf),
    ];
    foreach (m937_caixas() as $k => $x) $c[$k] = m937_num($med[$k] ?? '');
    foreach (m937_textos() as $k => $x) {
        if (strpos($k, 'm_') === 0) $c[$k] = trim((string)($med[$k] ?? ''));
        else $c[$k] = trim((string)($obs[$k] ?? ''));
    }
    foreach (m937_itens() as $k => $x) {
        $v = $def['v_' . $k] ?? '';
        $v = is_string($v) ? strtolower(trim($v)) : $v;
        $txt = m937_texto_def($def, $obs, $k);
        if ($v === 'nao' || $v === 'não') { $c['e_' . $k] = 'nao'; }
        elseif ($v === 'sim') { $c['e_' . $k] = 'sim'; }
        elseif (is_string($v) && $v !== '') { $c['e_' . $k] = 'sim'; if ($txt === '') $txt = $def['v_' . $k]; }
        else { $c['e_' . $k] = ''; }
        $c['d_' . $k] = $txt;
    }
    return $c;
}

// Junta o que foi guardado no Modelo 937 com os valores do termo (o guardado manda)
function m937_campos(array $t, array $m937, array $ultimo = []) {
    $c = m937_defaults($t, $ultimo);
    foreach (($m937['campos'] ?? []) as $k => $v) {
        if (array_key_exists($k, $c)) $c[$k] = is_string($v) ? $v : (string)$v;
    }
    return $c;
}

} // fim function_exists
