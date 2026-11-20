<?php

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

session_start();
require_once 'db.php';

if (!isset($_SESSION['user_id'])) {
    die("Acesso negado.");
}

$id_auto = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$id_auto) {
    die("Auto não especificado.");
}

if (!class_exists('ZipArchive')) {
    die("A extensão PHP 'zip' (ZipArchive) não está ativa no servidor.");
}

// ==========================================================================
// CONFIGURAÇÃO (ajustar se necessário)
// ==========================================================================
// Logótipo: é usado o primeiro ficheiro que existir desta lista (PNG ou JPG).
$LOGO_CANDIDATOS = [
    __DIR__ . '/img/mab.png',
    __DIR__ . '/images/mab.png',
    __DIR__ . '/assets/img>mab.png',
    __DIR__ . '/assets/mab.png',
    __DIR__ . '/mab.png',
    __DIR__ . '/img/mab.jpg',
];
$EMPRESA_NOME   = 'm.Abreu – Instalações Técnicas, Unipessoal, Lda.';
$EMPRESA_RODAPE = 'Rua Damião de Góis, 205 | 4700-028 Braga | NIF: 514414120 | geral@mabreu.pt | +351 912 854 950';

// --- BUSCAR DADOS DO AUTO, OBRA E CLIENTE ---
$stmt_auto = $pdo->prepare("
    SELECT a.*, o.nome as obra_nome, o.referencia_obra, o.morada as obra_morada,
           c.nome as cliente_nome, c.nif as cliente_nif
    FROM autos a
    JOIN obras o ON a.id_obra = o.id
    JOIN clientes c ON o.id_cliente = c.id
    WHERE a.id = ?
");
$stmt_auto->execute([$id_auto]);
$auto = $stmt_auto->fetch(PDO::FETCH_ASSOC);

if (!$auto) {
    die("Auto de medição não encontrado.");
}

$versao_auto = $auto['versao_auto'] ?? 1;
$nome_ficheiro = "Auto_Medicao_" . preg_replace('/[^A-Za-z0-9]/', '_', $auto['obra_nome']) . "_Num_" . $auto['numero_auto'] . "_v" . $versao_auto . ".xlsx";

// --- BUSCAR ITENS ---
$stmt_itens = $pdo->prepare("SELECT * FROM auto_itens WHERE id_auto = ? ORDER BY id ASC");
$stmt_itens->execute([$id_auto]);
$itens = $stmt_itens->fetchAll(PDO::FETCH_ASSOC);

$itens_adj = [];
$itens_sup = [];
foreach ($itens as $it) {
    if ($it['tipo'] === 'Suplementar') {
        $itens_sup[] = $it;
    } else {
        $itens_adj[] = $it;
    }
}

// ==========================================================================
// ESCRITOR XLSX NATIVO (sem bibliotecas externas — só ZipArchive)
// Gera um .xlsx verdadeiro: números reais, formato € português, fórmulas,
// células fundidas, logótipo, linhas fixas e configuração de impressão.
// ==========================================================================

// Índices de estilo (correspondem à ordem de <cellXfs> em estilos_xml())
const S_DEFAULT = 0;  const S_TITULO = 1;   const S_SUBTIT = 2;   const S_LBL_RED = 3;
const S_BOLD = 4;     const S_TXT = 5;      const S_DATA = 6;     const S_SECCAO = 7;
const S_TH = 8;       const S_ART = 9;      const S_ART_CAP = 10; const S_DESC = 11;
const S_DESC_CAP = 12;const S_UN = 13;      const S_UN_CAP = 14;  const S_QTD_IN = 15;
const S_EUR_IN = 16;  const S_QTD_F = 17;   const S_EUR_F = 18;   const S_VAZIO = 19;
const S_VAZIO_CAP = 20; const S_TOT_LBL = 21; const S_TOT_EUR = 22; const S_RES_LBL = 23;
const S_RES_EUR = 24; const S_RESG_LBL = 25; const S_RESG_EUR = 26; const S_ROD_B = 27; const S_PRECO_IN = 31;
const S_ROD = 28;     const S_NOTA = 29;    const S_OBS = 30;

$cells   = [];  // $cells[linha][coluna] = ['t'=>tipo, 'v'=>valor, 's'=>estilo, 'c'=>valor em cache]
$merges  = [];
$heights = [];

function xml_txt($s) {
    $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', (string)$s);
    return htmlspecialchars($s, ENT_QUOTES | ENT_XML1 | ENT_SUBSTITUTE, 'UTF-8');
}
function set_str($ref, $txt, $s = S_TXT) {
    global $cells; [$c, $r] = split_ref($ref);
    $cells[$r][$c] = ['t' => 's', 'v' => (string)$txt, 's' => $s];
}
function set_num($ref, $num, $s) {
    global $cells; [$c, $r] = split_ref($ref);
    $cells[$r][$c] = ['t' => 'n', 'v' => (float)$num, 's' => $s];
}
function set_fml($ref, $formula, $cache, $s) {
    global $cells; [$c, $r] = split_ref($ref);
    $cells[$r][$c] = ['t' => 'f', 'v' => ltrim($formula, '='), 'c' => (float)$cache, 's' => $s];
}
function set_blank($ref, $s) {
    global $cells; [$c, $r] = split_ref($ref);
    if (!isset($cells[$r][$c])) $cells[$r][$c] = ['t' => 'b', 's' => $s];
}
// Funde um intervalo e aplica o estilo a todas as células (para os contornos saírem completos)
function merge($range, $s = null) {
    global $merges; $merges[] = $range;
    if ($s === null) return;
    [$a, $b] = explode(':', $range);
    [$c1, $r1] = split_ref($a); [$c2, $r2] = split_ref($b);
    for ($r = $r1; $r <= $r2; $r++)
        for ($c = col_idx($c1); $c <= col_idx($c2); $c++)
            set_blank(col_letter($c) . $r, $s);
}
function split_ref($ref) {
    preg_match('/^([A-Z]+)(\d+)$/', $ref, $m);
    return [$m[1], (int)$m[2]];
}
function col_idx($l) { $n = 0; foreach (str_split($l) as $ch) $n = $n * 26 + (ord($ch) - 64); return $n; }
function col_letter($n) { $s = ''; while ($n > 0) { $m = ($n - 1) % 26; $s = chr(65 + $m) . $s; $n = intdiv($n - 1, 26); } return $s; }
function excel_date($ymd) {
    $d = new DateTime(date('Y-m-d', strtotime($ymd)));
    return (int)(new DateTime('1899-12-30'))->diff($d)->days;
}
// Altura de linha aproximada para descrições longas (coluna B com ~62 caracteres por linha)
function altura_desc($txt) {
    $linhas = 0;
    foreach (preg_split("/\r\n|\n|\r/", (string)$txt) as $parte) {
        $linhas += max(1, (int)ceil(mb_strlen($parte, 'UTF-8') / 62));
    }
    return $linhas > 1 ? 12 * $linhas : null;
}
function e_capitulo($artigo, $unidade) {
    return trim((string)$unidade) === '' && preg_match('/^\d+(\.\d+)?$/', trim((string)$artigo));
}

// ==========================================================================
// CABEÇALHO
// ==========================================================================
$heights[1] = 24; $heights[2] = 24;
merge('J1:M1', S_TITULO); set_str('J1', 'AUTO DE MEDIÇÃO', S_TITULO);
$subtitulo = 'Nº ' . $auto['numero_auto'] . ' (v' . $versao_auto . ')' . (!empty($auto['mes_referencia']) ? ' - ' . $auto['mes_referencia'] : '');
merge('J2:M2', S_SUBTIT); set_str('J2', $subtitulo, S_SUBTIT);

set_str('A5', 'CLIENTE', S_LBL_RED);
set_str('A6', $auto['cliente_nome'], S_BOLD);
set_str('A7', 'NIF: ' . $auto['cliente_nif'], S_TXT);

set_str('D5', 'OBRA', S_LBL_RED);
set_str('D6', $auto['obra_nome'], S_BOLD);
set_str('D7', 'Referência da Obra: ' . ($auto['referencia_obra'] ?: 'N/D'), S_TXT);
set_str('D8', (string)$auto['obra_morada'], S_TXT);

set_str('K5', 'DETALHES', S_LBL_RED);
set_str('K6', 'Data:', S_BOLD);
if (!empty($auto['data_auto'])) set_num('L6', excel_date($auto['data_auto']), S_DATA);
set_str('K7', 'Referência:', S_BOLD);
set_str('L7', $auto['mes_referencia'] ?: 'N/D', S_TXT);

$row = 10;
$freeze_row = null;
$print_titles = null;

// Totais em PHP (valores em cache nas fórmulas, para aparecerem logo corretos)
$tot_adj_sum = 0; $tot_mes_adj_sum = 0; $tot_acum_adj_sum = 0; $tot_saldo_adj_sum = 0;
$tot_mes_sup_sum = 0; $tot_acum_sup_sum = 0;
$row_totais_adj = 0; $row_totais_sup = 0;

// ==========================================================================
// TRABALHOS ADJUDICADOS
// ==========================================================================
if (count($itens_adj) > 0) {
    set_str("A$row", 'TRABALHOS ADJUDICADOS', S_SECCAO);
    $row++;
    $h1 = $row; $h2 = $row + 1;
    foreach (['A' => 'ARTIGO', 'B' => 'DESCRIÇÃO', 'C' => 'UN.'] as $col => $t) {
        merge("$col$h1:$col$h2", S_TH); set_str("$col$h1", $t, S_TH);
    }
    foreach ([['D', 'F', 'ADJUDICADO'], ['G', 'H', 'MÊS'], ['I', 'K', 'ACUMULADO'], ['L', 'M', 'SALDO']] as [$a, $b, $t]) {
        merge("$a$h1:$b$h1", S_TH); set_str("$a$h1", $t, S_TH);
    }
    $sub = ['D' => 'QTD', 'E' => 'P. UNIT', 'F' => 'TOTAL', 'G' => 'QTD', 'H' => 'TOTAL',
            'I' => 'QTD ANT.', 'J' => 'QTD TOTAL', 'K' => 'TOTAL €', 'L' => 'QTD', 'M' => 'VALOR €'];
    foreach ($sub as $col => $t) set_str("$col$h2", $t, S_TH);
    $print_titles = [$h1, $h2];
    $row = $h2 + 1;
    $freeze_row = $row;
    $start_adj = $row;

    foreach ($itens_adj as $it) {
        $r = $row;
        $qtd_adj = (float)$it['qtd_adjudicada'];
        $cap = e_capitulo($it['artigo'], $it['unidade']);

        set_str("A$r", $it['artigo'], $cap ? S_ART_CAP : S_ART);
        set_str("B$r", $it['designacao'], $cap ? S_DESC_CAP : S_DESC);
        set_str("C$r", $it['unidade'], $cap ? S_UN_CAP : S_UN);
        if ($h = altura_desc($it['designacao'])) $heights[$r] = $h;

        if ($qtd_adj == 0) {
            // Linha-título: só Artigo / Descrição / Unidade
            foreach (range('D', 'M') as $col) set_blank("$col$r", $cap ? S_VAZIO_CAP : S_VAZIO);
        } else {
            $p_un    = (float)$it['preco_unitario'];
            $qtd_mes = (float)$it['qtd_mes'];
            $qtd_ant = (float)$it['qtd_anterior'];

            $total_adj   = round((float)$it['total_adjudicado'], 2);
            $acerto      = totalFoiAcertado($total_adj, $qtd_adj, $p_un);   // Total Adj. acertado à mão
            $qtd_acum    = $qtd_ant + $qtd_mes;
            $completo    = abs($qtd_acum - $qtd_adj) < 0.0005;
            $ja_completo = abs($qtd_ant - $qtd_adj) < 0.0005;
            if ($acerto && $completo) {
                $total_acum = $total_adj;
                $total_mes  = $ja_completo ? 0.0 : round($total_adj - totLinha($qtd_ant, $p_un), 2);
            } else {
                $total_acum = totLinha($qtd_acum, $p_un);
                $total_mes  = totLinha($qtd_mes, $p_un);
            }
            // Acerto de cêntimos do Saldo € (acumulado até este auto / parte nova deste auto)
            $ac_acum  = round((float)($it['acerto_acum'] ?? 0), 2);
            $ac_delta = round($ac_acum - (float)($it['acerto_ant'] ?? 0), 2);
            $total_acum = round($total_acum + $ac_acum, 2);
            $total_mes  = round($total_mes + $ac_delta, 2);
            $f_ac  = $ac_acum  != 0 ? sprintf('%+.2F', $ac_acum)  : '';
            $f_del = $ac_delta != 0 ? sprintf('%+.2F', $ac_delta) : '';
            $saldo_qtd   = $qtd_adj - $qtd_acum;
            $saldo_valor = round($total_adj - $total_acum, 2);   // Saldo € = Total Adj. − Acumulado €

            $tot_adj_sum       += $total_adj;
            $tot_mes_adj_sum   += $total_mes;
            $tot_acum_adj_sum  += $total_acum;
            $tot_saldo_adj_sum += $saldo_valor;

            set_num("D$r", $qtd_adj, S_QTD_IN);
            set_num("E$r", $p_un, S_PRECO_IN);
            if ($acerto) set_num("F$r", $total_adj, S_EUR_F); else set_fml("F$r", "ROUND(D$r*E$r,2)", $total_adj, S_EUR_F);
            if ($qtd_mes != 0) set_num("G$r", $qtd_mes, S_QTD_IN); else set_blank("G$r", S_QTD_IN);
            if ($acerto && $completo && !$ja_completo) set_fml("H$r", "F$r-ROUND(I$r*E$r,2)" . $f_del, $total_mes, S_EUR_F);
            else set_fml("H$r", "ROUND(G$r*E$r,2)" . $f_del, $total_mes, S_EUR_F);
            if ($qtd_ant != 0) set_num("I$r", $qtd_ant, S_QTD_IN); else set_blank("I$r", S_QTD_IN);
            set_fml("J$r", "I$r+G$r", $qtd_acum, S_QTD_F);
            if ($acerto && $completo) set_fml("K$r", "F$r" . $f_ac, $total_acum, S_EUR_F);
            else set_fml("K$r", "ROUND(J$r*E$r,2)" . $f_ac, $total_acum, S_EUR_F);
            set_fml("L$r", "D$r-J$r", $saldo_qtd, S_QTD_F);
            set_fml("M$r", "F$r-K$r", $saldo_valor, S_EUR_F);
        }
        $row++;
    }
    $end_adj = $row - 1;

    $r = $row;
    merge("A$r:E$r", S_TOT_LBL); set_str("A$r", 'TOTAIS', S_TOT_LBL);
    foreach (range('F', 'M') as $col) set_blank("$col$r", S_TOT_EUR);
    set_fml("F$r", "SUM(F$start_adj:F$end_adj)", $tot_adj_sum, S_TOT_EUR);
    set_fml("H$r", "SUM(H$start_adj:H$end_adj)", $tot_mes_adj_sum, S_TOT_EUR);
    set_fml("K$r", "SUM(K$start_adj:K$end_adj)", $tot_acum_adj_sum, S_TOT_EUR);
    set_fml("M$r", "SUM(M$start_adj:M$end_adj)", $tot_saldo_adj_sum, S_TOT_EUR);
    $row_totais_adj = $r;
    $row += 3;
}

// ==========================================================================
// TRABALHOS SUPLEMENTARES
// ==========================================================================
if (count($itens_sup) > 0) {
    set_str("A$row", 'TRABALHOS SUPLEMENTARES', S_SECCAO);
    $row++;
    // Mesmas colunas dos adjudicados (com "SUPLEMENTARES" no lugar de "ADJUDICADO")
    $h1 = $row; $h2 = $row + 1;
    foreach (['A' => 'ARTIGO', 'B' => 'DESCRIÇÃO', 'C' => 'UN.'] as $col => $t) {
        merge("$col$h1:$col$h2", S_TH); set_str("$col$h1", $t, S_TH);
    }
    foreach ([['D', 'F', 'SUPLEMENTARES'], ['G', 'H', 'MÊS'], ['I', 'K', 'ACUMULADO'], ['L', 'M', 'SALDO']] as [$a, $b, $t]) {
        merge("$a$h1:$b$h1", S_TH); set_str("$a$h1", $t, S_TH);
    }
    $sub = ['D' => 'QTD', 'E' => 'P. UNIT', 'F' => 'TOTAL', 'G' => 'QTD', 'H' => 'TOTAL',
            'I' => 'QTD ANT.', 'J' => 'QTD TOTAL', 'K' => 'TOTAL €', 'L' => 'QTD', 'M' => 'VALOR €'];
    foreach ($sub as $col => $t) set_str("$col$h2", $t, S_TH);
    if ($freeze_row === null) { $freeze_row = $h2 + 1; $print_titles = [$h1, $h2]; }
    $row = $h2 + 1;
    $start_sup = $row;
    $tot_sup_sum = 0; $tot_saldo_sup_sum = 0;

    foreach ($itens_sup as $it) {
        $r = $row;
        $qtd_adj = (float)$it['qtd_adjudicada'];
        $p_un    = (float)$it['preco_unitario'];
        $qtd_mes = (float)$it['qtd_mes'];
        $qtd_ant = (float)$it['qtd_anterior'];
        $titulo  = trim((string)$it['unidade']) === '' && $p_un == 0 && $qtd_mes == 0 && $qtd_ant == 0 && $qtd_adj == 0;

        set_str("A$r", $it['artigo'], $titulo ? S_ART_CAP : S_ART);
        set_str("B$r", $it['designacao'], $titulo ? S_DESC_CAP : S_DESC);
        set_str("C$r", $it['unidade'], $titulo ? S_UN_CAP : S_UN);
        if ($h = altura_desc($it['designacao'])) $heights[$r] = $h;

        if ($titulo) {
            foreach (range('D', 'M') as $col) set_blank("$col$r", S_VAZIO_CAP);
        } else {
            $tem_qtd     = $qtd_adj > 0;
            $total_adj   = $tem_qtd ? round((float)$it['total_adjudicado'], 2) : 0.0;
            $acerto      = $tem_qtd && totalFoiAcertado($total_adj, $qtd_adj, $p_un);
            $qtd_acum    = $qtd_ant + $qtd_mes;
            $completo    = $tem_qtd && abs($qtd_acum - $qtd_adj) < 0.0005;
            $ja_completo = $tem_qtd && abs($qtd_ant - $qtd_adj) < 0.0005;
            if ($acerto && $completo) {
                $total_acum = $total_adj;
                $total_mes  = $ja_completo ? 0.0 : round($total_adj - totLinha($qtd_ant, $p_un), 2);
            } else {
                $total_acum = totLinha($qtd_acum, $p_un);
                $total_mes  = totLinha($qtd_mes, $p_un);
            }
            $ac_acum  = round((float)($it['acerto_acum'] ?? 0), 2);
            $ac_delta = round($ac_acum - (float)($it['acerto_ant'] ?? 0), 2);
            $total_acum = round($total_acum + $ac_acum, 2);
            $total_mes  = round($total_mes + $ac_delta, 2);
            $f_ac  = $ac_acum  != 0 ? sprintf('%+.2F', $ac_acum)  : '';
            $f_del = $ac_delta != 0 ? sprintf('%+.2F', $ac_delta) : '';
            $saldo_qtd   = $qtd_adj - $qtd_acum;
            $saldo_valor = round($total_adj - $total_acum, 2);

            $tot_sup_sum      += $total_adj;
            $tot_mes_sup_sum  += $total_mes;
            $tot_acum_sup_sum += $total_acum;
            if ($tem_qtd) $tot_saldo_sup_sum += $saldo_valor;

            if ($tem_qtd) set_num("D$r", $qtd_adj, S_QTD_IN); else set_blank("D$r", S_QTD_IN);
            set_num("E$r", $p_un, S_PRECO_IN);
            if (!$tem_qtd) set_blank("F$r", S_EUR_F);
            elseif ($acerto) set_num("F$r", $total_adj, S_EUR_F);
            else set_fml("F$r", "ROUND(D$r*E$r,2)", $total_adj, S_EUR_F);
            if ($qtd_mes != 0) set_num("G$r", $qtd_mes, S_QTD_IN); else set_blank("G$r", S_QTD_IN);
            if ($acerto && $completo && !$ja_completo) set_fml("H$r", "F$r-ROUND(I$r*E$r,2)" . $f_del, $total_mes, S_EUR_F);
            else set_fml("H$r", "ROUND(G$r*E$r,2)" . $f_del, $total_mes, S_EUR_F);
            if ($qtd_ant != 0) set_num("I$r", $qtd_ant, S_QTD_IN); else set_blank("I$r", S_QTD_IN);
            set_fml("J$r", "I$r+G$r", $qtd_acum, S_QTD_F);
            if ($acerto && $completo) set_fml("K$r", "F$r" . $f_ac, $total_acum, S_EUR_F);
            else set_fml("K$r", "ROUND(J$r*E$r,2)" . $f_ac, $total_acum, S_EUR_F);
            if ($tem_qtd) {
                set_fml("L$r", "D$r-J$r", $saldo_qtd, S_QTD_F);
                set_fml("M$r", "F$r-K$r", $saldo_valor, S_EUR_F);
            } else {
                set_blank("L$r", S_QTD_F); set_blank("M$r", S_EUR_F);
            }
        }
        $row++;
    }
    $end_sup = $row - 1;

    $r = $row;
    merge("A$r:E$r", S_TOT_LBL); set_str("A$r", 'TOTAIS', S_TOT_LBL);
    foreach (range('F', 'M') as $col) set_blank("$col$r", S_TOT_EUR);
    set_fml("F$r", "SUM(F$start_sup:F$end_sup)", $tot_sup_sum, S_TOT_EUR);
    set_fml("H$r", "SUM(H$start_sup:H$end_sup)", $tot_mes_sup_sum, S_TOT_EUR);
    set_fml("K$r", "SUM(K$start_sup:K$end_sup)", $tot_acum_sup_sum, S_TOT_EUR);
    set_fml("M$r", "SUM(M$start_sup:M$end_sup)", $tot_saldo_sup_sum, S_TOT_EUR);
    $row_totais_sup = $r;
    $row += 3;
}

// ==========================================================================
// OBSERVAÇÕES (se preenchidas)
// ==========================================================================
if (!empty($auto['observacoes'])) {
    set_str("A$row", 'OBSERVAÇÕES', S_LBL_RED);
    $row++;
    merge("A$row:M$row", S_OBS);
    set_str("A$row", $auto['observacoes'], S_OBS);
    $n = 0;
    foreach (preg_split("/\r\n|\n|\r/", $auto['observacoes']) as $l) $n += max(1, (int)ceil(mb_strlen($l, 'UTF-8') / 180));
    $heights[$row] = 12 * max(1, $n);
    $row += 3;
}

// ==========================================================================
// RESUMO FINAL
// ==========================================================================
$ref_adj = $row_totais_adj ? "H$row_totais_adj" : "0";
$ref_sup = $row_totais_sup ? "H$row_totais_sup" : "0";

merge("H$row:J$row"); set_str("H$row", 'Total Adjudicado (Auto)', S_RES_LBL);
set_fml("K$row", $ref_adj, $tot_mes_adj_sum, S_RES_EUR);
$r_res_adj = $row; $row++;

merge("H$row:J$row"); set_str("H$row", 'Total Suplementar (Auto)', S_RES_LBL);
set_fml("K$row", $ref_sup, $tot_mes_sup_sum, S_RES_EUR);
$r_res_sup = $row; $row++;

merge("H$row:J$row", S_RESG_LBL); set_str("H$row", 'TOTAL DO AUTO', S_RESG_LBL);
set_fml("K$row", "K$r_res_adj+K$r_res_sup", $tot_mes_adj_sum + $tot_mes_sup_sum, S_RESG_EUR);
$row += 3;

// Rodapé
merge("A$row:M$row"); set_str("A$row", $EMPRESA_NOME, S_ROD_B); $row++;
merge("A$row:M$row"); set_str("A$row", $EMPRESA_RODAPE, S_ROD); $row += 2;
merge("A$row:M$row");
set_str("A$row", 'Nota: células a azul são valores editáveis (quantidades e preços); os totais, acumulados e saldos são calculados automaticamente.', S_NOTA);
$ultima_linha = $row;

// ==========================================================================
// MONTAGEM DO FICHEIRO XLSX
// ==========================================================================
function estilos_xml() {
    $fonts = [
        ['9',  false, '000000'], // 0 normal
        ['9',  true,  '000000'], // 1 negrito
        ['16', true,  'C0272D'], // 2 título vermelho
        ['10', true,  '595959'], // 3 subtítulo
        ['9',  true,  'C0272D'], // 4 rótulo vermelho
        ['11', true,  'C0272D'], // 5 secção / total do auto
        ['8',  true,  '000000'], // 6 cabeçalho tabela
        ['9',  false, '0000FF'], // 7 valores editáveis (azul)
        ['8',  false, '595959'], // 8 rodapé
        ['8',  false, '808080'], // 9 nota
        ['8',  true,  '000000'], // 10 rodapé negrito
    ];
    $f = '';
    foreach ($fonts as [$sz, $b, $c]) $f .= '<font>' . ($b ? '<b/>' : '') . '<sz val="' . $sz . '"/><color rgb="FF' . $c . '"/><name val="Arial"/><family val="2"/></font>';

    // [font, fill, border, numFmt, horizontal, vertical, wrap]
    $xfs = [
        [0, 0, 0, 0,   null,     null,     false], // 0  S_DEFAULT
        [2, 0, 0, 0,   'right',  'center', false], // 1  S_TITULO
        [3, 0, 0, 0,   'right',  'center', false], // 2  S_SUBTIT
        [4, 0, 0, 0,   null,     'center', false], // 3  S_LBL_RED
        [1, 0, 0, 0,   null,     'center', false], // 4  S_BOLD
        [0, 0, 0, 0,   null,     'center', false], // 5  S_TXT
        [0, 0, 0, 166, 'left',   'center', false], // 6  S_DATA
        [5, 0, 0, 0,   null,     'center', false], // 7  S_SECCAO
        [6, 2, 1, 0,   'center', 'center', true ], // 8  S_TH
        [0, 0, 1, 0,   'center', 'top',    false], // 9  S_ART
        [1, 3, 1, 0,   'center', 'top',    false], // 10 S_ART_CAP
        [0, 0, 1, 0,   'left',   'top',    true ], // 11 S_DESC
        [1, 3, 1, 0,   'left',   'top',    true ], // 12 S_DESC_CAP
        [0, 0, 1, 0,   'center', 'top',    false], // 13 S_UN
        [0, 3, 1, 0,   'center', 'top',    false], // 14 S_UN_CAP
        [7, 0, 1, 165, 'right',  'top',    false], // 15 S_QTD_IN
        [7, 0, 1, 164, 'right',  'top',    false], // 16 S_EUR_IN
        [0, 0, 1, 165, 'right',  'top',    false], // 17 S_QTD_F
        [0, 0, 1, 164, 'right',  'top',    false], // 18 S_EUR_F
        [0, 0, 1, 0,   null,     'top',    false], // 19 S_VAZIO
        [0, 3, 1, 0,   null,     'top',    false], // 20 S_VAZIO_CAP
        [1, 2, 1, 0,   'right',  'center', false], // 21 S_TOT_LBL
        [1, 2, 1, 164, 'right',  'center', false], // 22 S_TOT_EUR
        [1, 0, 0, 0,   'right',  'center', false], // 23 S_RES_LBL
        [1, 0, 0, 164, 'right',  'center', false], // 24 S_RES_EUR
        [5, 2, 0, 0,   'right',  'center', false], // 25 S_RESG_LBL
        [5, 2, 0, 164, 'right',  'center', false], // 26 S_RESG_EUR
        [10, 0, 0, 0,  'center', 'center', false], // 27 S_ROD_B
        [8, 0, 0, 0,   'center', 'center', false], // 28 S_ROD
        [9, 0, 0, 0,   'left',   'center', false], // 29 S_NOTA
        [0, 0, 1, 0,   'left',   'top',    true ], // 30 S_OBS
        [7, 0, 1, 167, 'right',  'top',    false], // 31 S_PRECO_IN (preço unitário, 3 casas)
    ];
    $x = '';
    foreach ($xfs as [$fo, $fi, $bo, $nf, $h, $v, $w]) {
        $x .= '<xf numFmtId="' . $nf . '" fontId="' . $fo . '" fillId="' . $fi . '" borderId="' . $bo . '" xfId="0"'
            . ($nf ? ' applyNumberFormat="1"' : '') . ' applyFont="1"' . ($fi ? ' applyFill="1"' : '') . ($bo ? ' applyBorder="1"' : '')
            . ' applyAlignment="1"><alignment' . ($h ? ' horizontal="' . $h . '"' : '') . ($v ? ' vertical="' . $v . '"' : '') . ($w ? ' wrapText="1"' : '') . '/></xf>';
    }
    $bd = '<left style="thin"><color rgb="FFBFBFBF"/></left><right style="thin"><color rgb="FFBFBFBF"/></right><top style="thin"><color rgb="FFBFBFBF"/></top><bottom style="thin"><color rgb="FFBFBFBF"/></bottom><diagonal/>';

    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<numFmts count="4">'
        . '<numFmt numFmtId="164" formatCode="#,##0.00\ [$€-816]"/>'
        . '<numFmt numFmtId="165" formatCode="#,##0.00"/>'
        . '<numFmt numFmtId="166" formatCode="dd/mm/yyyy"/>'
        . '<numFmt numFmtId="167" formatCode="#,##0.000\ [$€-816]"/>'
        . '</numFmts>'
        . '<fonts count="' . count($fonts) . '">' . $f . '</fonts>'
        . '<fills count="4"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill>'
        . '<fill><patternFill patternType="solid"><fgColor rgb="FFE7E6E6"/><bgColor indexed="64"/></patternFill></fill>'
        . '<fill><patternFill patternType="solid"><fgColor rgb="FFF2F2F2"/><bgColor indexed="64"/></patternFill></fill></fills>'
        . '<borders count="2"><border><left/><right/><top/><bottom/><diagonal/></border><border>' . $bd . '</border></borders>'
        . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
        . '<cellXfs count="' . count($xfs) . '">' . $x . '</cellXfs>'
        . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
        . '</styleSheet>';
}

// --- Folha ---
$larguras = ['A' => 11, 'B' => 52, 'C' => 6, 'D' => 10, 'E' => 12, 'F' => 13, 'G' => 10,
             'H' => 13, 'I' => 10, 'J' => 10, 'K' => 13, 'L' => 10, 'M' => 13];
$cols_xml = '';
foreach ($larguras as $c => $w) { $i = col_idx($c); $cols_xml .= '<col min="' . $i . '" max="' . $i . '" width="' . $w . '" customWidth="1"/>'; }

ksort($cells);
$data_xml = '';
$todas_linhas = array_unique(array_merge(array_keys($cells), array_keys($heights)));
sort($todas_linhas);
foreach ($todas_linhas as $r) {
    $attr = isset($heights[$r]) ? ' ht="' . $heights[$r] . '" customHeight="1"' : '';
    $data_xml .= '<row r="' . $r . '"' . $attr . '>';
    if (isset($cells[$r])) {
        uksort($cells[$r], fn($a, $b) => col_idx($a) <=> col_idx($b));
        foreach ($cells[$r] as $c => $cell) {
            $ref = $c . $r; $s = ' s="' . $cell['s'] . '"';
            switch ($cell['t']) {
                case 's':
                    $data_xml .= '<c r="' . $ref . '"' . $s . ' t="inlineStr"><is><t xml:space="preserve">' . xml_txt($cell['v']) . '</t></is></c>';
                    break;
                case 'n':
                    $data_xml .= '<c r="' . $ref . '"' . $s . '><v>' . round($cell['v'], 6) . '</v></c>';
                    break;
                case 'f':
                    $data_xml .= '<c r="' . $ref . '"' . $s . '><f>' . xml_txt($cell['v']) . '</f><v>' . round($cell['c'], 6) . '</v></c>';
                    break;
                default:
                    $data_xml .= '<c r="' . $ref . '"' . $s . '/>';
            }
        }
    }
    $data_xml .= '</row>';
}

$merge_xml = '';
if ($merges) {
    $merge_xml = '<mergeCells count="' . count($merges) . '">';
    foreach ($merges as $m) $merge_xml .= '<mergeCell ref="' . $m . '"/>';
    $merge_xml .= '</mergeCells>';
}

$pane_xml = $freeze_row
    ? '<pane ySplit="' . ($freeze_row - 1) . '" topLeftCell="A' . $freeze_row . '" activePane="bottomLeft" state="frozen"/><selection pane="bottomLeft" activeCell="A' . $freeze_row . '" sqref="A' . $freeze_row . '"/>'
    : '';

// Logótipo
$logo = null;
foreach ($LOGO_CANDIDATOS as $p) {
    if (is_file($p) && ($info = @getimagesize($p)) && in_array($info[2], [IMAGETYPE_PNG, IMAGETYPE_JPEG])) {
        $logo = ['path' => $p, 'w' => $info[0], 'h' => $info[1], 'ext' => $info[2] === IMAGETYPE_PNG ? 'png' : 'jpeg'];
        break;
    }
}

$sheet_xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
    . '<sheetPr><pageSetUpPr fitToPage="1"/></sheetPr>'
    . '<dimension ref="A1:M' . $ultima_linha . '"/>'
    . '<sheetViews><sheetView showGridLines="0" workbookViewId="0">' . $pane_xml . '</sheetView></sheetViews>'
    . '<sheetFormatPr defaultRowHeight="12.75"/>'
    . '<cols>' . $cols_xml . '</cols>'
    . '<sheetData>' . $data_xml . '</sheetData>'
    . $merge_xml
    . '<pageMargins left="0.4" right="0.4" top="0.5" bottom="0.5" header="0.3" footer="0.3"/>'
    . '<pageSetup paperSize="9" orientation="landscape" fitToWidth="1" fitToHeight="0"/>'
    . '<headerFooter><oddFooter>&amp;CPágina &amp;P de &amp;N</oddFooter></headerFooter>'
    . ($logo ? '<drawing r:id="rId1"/>' : '')
    . '</worksheet>';

$nome_folha = 'Auto de Medição ' . $auto['numero_auto'];
$nome_folha = mb_substr(preg_replace('/[\\\\\/\?\*\[\]:]/', '', $nome_folha), 0, 31, 'UTF-8');
$print_titles_xml = $print_titles
    ? '<definedNames><definedName name="_xlnm.Print_Titles" localSheetId="0">\'' . xml_txt(str_replace("'", "''", $nome_folha)) . '\'!$' . $print_titles[0] . ':$' . $print_titles[1] . '</definedName></definedNames>'
    : '';

$workbook_xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
    . '<bookViews><workbookView/></bookViews>'
    . '<sheets><sheet name="' . xml_txt($nome_folha) . '" sheetId="1" r:id="rId1"/></sheets>'
    . $print_titles_xml
    . '<calcPr calcId="191029" fullCalcOnLoad="1"/>'
    . '</workbook>';

$content_types = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
    . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
    . '<Default Extension="xml" ContentType="application/xml"/>'
    . ($logo ? '<Default Extension="' . $logo['ext'] . '" ContentType="image/' . $logo['ext'] . '"/>' : '')
    . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
    . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
    . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
    . ($logo ? '<Override PartName="/xl/drawings/drawing1.xml" ContentType="application/vnd.openxmlformats-officedocument.drawing+xml"/>' : '')
    . '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>'
    . '<Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>'
    . '</Types>';

$rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
    . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
    . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>'
    . '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/>'
    . '</Relationships>';

$wb_rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
    . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
    . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
    . '</Relationships>';

$agora = gmdate('Y-m-d\TH:i:s\Z');
$core = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    . '<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
    . '<dc:title>' . xml_txt('Auto de Medição Nº ' . $auto['numero_auto'] . ' (v' . $versao_auto . ') - ' . $auto['obra_nome']) . '</dc:title>'
    . '<dc:creator>' . xml_txt($EMPRESA_NOME) . '</dc:creator>'
    . '<dcterms:created xsi:type="dcterms:W3CDTF">' . $agora . '</dcterms:created>'
    . '<dcterms:modified xsi:type="dcterms:W3CDTF">' . $agora . '</dcterms:modified>'
    . '</cp:coreProperties>';
$app = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    . '<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties"><Application>Microsoft Excel</Application></Properties>';

$tmp = tempnam(sys_get_temp_dir(), 'auto_xlsx_');
$zip = new ZipArchive();
if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) {
    die("Não foi possível criar o ficheiro Excel.");
}
$zip->addFromString('[Content_Types].xml', $content_types);
$zip->addFromString('_rels/.rels', $rels);
$zip->addFromString('docProps/core.xml', $core);
$zip->addFromString('docProps/app.xml', $app);
$zip->addFromString('xl/workbook.xml', $workbook_xml);
$zip->addFromString('xl/_rels/workbook.xml.rels', $wb_rels);
$zip->addFromString('xl/styles.xml', estilos_xml());
$zip->addFromString('xl/worksheets/sheet1.xml', $sheet_xml);

if ($logo) {
    // Logótipo com 60 px de altura, proporção mantida (1 px = 9525 EMU)
    $alt_px = 60;
    $larg_px = (int)round($logo['w'] * $alt_px / $logo['h']);
    $cx = $larg_px * 9525; $cy = $alt_px * 9525;
    $drawing = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<xdr:wsDr xmlns:xdr="http://schemas.openxmlformats.org/drawingml/2006/spreadsheetDrawing" xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<xdr:oneCellAnchor><xdr:from><xdr:col>0</xdr:col><xdr:colOff>0</xdr:colOff><xdr:row>0</xdr:row><xdr:rowOff>0</xdr:rowOff></xdr:from>'
        . '<xdr:ext cx="' . $cx . '" cy="' . $cy . '"/>'
        . '<xdr:pic><xdr:nvPicPr><xdr:cNvPr id="2" name="Logotipo"/><xdr:cNvPicPr><a:picLocks noChangeAspect="1"/></xdr:cNvPicPr></xdr:nvPicPr>'
        . '<xdr:blipFill><a:blip r:embed="rId1"/><a:stretch><a:fillRect/></a:stretch></xdr:blipFill>'
        . '<xdr:spPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="' . $cx . '" cy="' . $cy . '"/></a:xfrm><a:prstGeom prst="rect"><a:avLst/></a:prstGeom></xdr:spPr></xdr:pic>'
        . '<xdr:clientData/></xdr:oneCellAnchor></xdr:wsDr>';
    $zip->addFromString('xl/drawings/drawing1.xml', $drawing);
    $zip->addFromString('xl/drawings/_rels/drawing1.xml.rels',
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="../media/image1.' . $logo['ext'] . '"/></Relationships>');
    $zip->addFromString('xl/worksheets/_rels/sheet1.xml.rels',
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/drawing" Target="../drawings/drawing1.xml"/></Relationships>');
    $zip->addFile($logo['path'], 'xl/media/image1.' . $logo['ext']);
}
$zip->close();

// --- ENVIAR PARA DOWNLOAD ---
while (ob_get_level() > 0) { ob_end_clean(); }
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $nome_ficheiro . '"');
header('Content-Length: ' . filesize($tmp));
header('Cache-Control: no-cache, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');
readfile($tmp);
@unlink($tmp);
exit;
