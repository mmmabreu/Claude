<?php
// REGRA DE OURO: Limpar qualquer espaço em branco ou lixo antes de gerar o PDF
ob_start();
session_start();

if (!isset($_SESSION['user_id'])) {
    ob_end_clean();
    die("Acesso negado.");
}
$perfil_user = $_SESSION['perfil'] ?? '';
if (!in_array($perfil_user, ['admin', 'administrativo'])) {
    ob_end_clean();
    die("Acesso negado.");
}

require_once 'db.php';
require_once 'ferias_calculo.php';
require_once('fpdf/fpdf.php');

$ano = filter_input(INPUT_GET, 'ano', FILTER_VALIDATE_INT) ?: (int)date('Y');
if ($ano < 2000 || $ano > 2100) $ano = (int)date('Y');
$hoje = date('Y-m-d');

$meses = [1 => 'Janeiro', 2 => 'Fevereiro', 3 => 'Março', 4 => 'Abril', 5 => 'Maio', 6 => 'Junho', 7 => 'Julho', 8 => 'Agosto', 9 => 'Setembro', 10 => 'Outubro', 11 => 'Novembro', 12 => 'Dezembro'];

// --- DADOS ---
try {
    $cfg = feriasConfig($pdo);
    // Trabalhadores ativos (sem Administrador e sem Externos/subempreiteiros)
    $users = $pdo->query("SELECT id, nome, perfil, cargo, dias_ferias, ferias_marcadas FROM utilizadores
                          WHERE ativo = 1 AND perfil NOT IN ('admin', 'externo') ORDER BY nome ASC")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    ob_end_clean();
    die("Erro na BD: " . htmlspecialchars($e->getMessage()));
}

// Iniciais únicas para o calendário geral (ex.: "JS", "PC"; se repetidas acrescenta letra)
function iniciais($nome) {
    $p = preg_split('/\s+/', trim($nome));
    $i = mb_strtoupper(mb_substr($p[0] ?? '', 0, 1) . mb_substr(end($p) ?: '', 0, 1));
    return $i ?: '?';
}

$trab = [];
$usadas = [];
foreach ($users as $u) {
    $f = feriasCalcular($u['ferias_marcadas'], $ano, $cfg, $hoje);
    $direito = (int)($u['dias_ferias'] ?? 22);
    $ini = iniciais($u['nome']);
    $base = $ini; $k = 2;
    while (in_array($ini, $usadas)) { $ini = $base . $k++; }
    $usadas[] = $ini;
    $trab[] = [
        'nome' => $u['nome'], 'cargo' => $u['cargo'] ?? '', 'ini' => $ini, 'direito' => $direito,
        'marcados' => $f['marcados'], 'gozados' => $f['gozados'],
        'faltam_marcar' => $direito - $f['marcados'], 'faltam_gozar' => max(0, $direito - $f['gozados']),
        'pontes' => count($f['pontes']), 'dias' => $f['dias'],
    ];
}

// Quem está de férias em cada dia (para o calendário geral)
$ferias_dia = [];
foreach ($trab as $t) {
    foreach ($t['dias'] as $data => $tipo) {
        if ($tipo === 'ferias') $ferias_dia[$data][] = $t['ini'];
    }
}

function pt($t) { return mb_convert_encoding((string)($t ?? ''), 'windows-1252', 'UTF-8'); }

// =====================================================================
class PDF extends FPDF {
    public $titulo_doc;
    public $subtitulo_doc;

    function Header() {
        if (file_exists('mab.png')) $this->Image('mab.png', 10, 8, 14);
        $this->SetY(9);
        $this->SetFont('Arial', 'B', 14);
        $this->SetTextColor(44, 62, 80);
        $this->Cell(0, 7, pt($this->titulo_doc), 0, 1, 'R');
        $this->SetFont('Arial', '', 9);
        $this->SetTextColor(100, 100, 100);
        $this->Cell(0, 5, pt($this->subtitulo_doc), 0, 1, 'R');
        $this->SetDrawColor(41, 128, 185);
        $this->SetLineWidth(0.6);
        $this->Line(10, 25, 287, 25);
        $this->SetY(28);
    }

    function Footer() {
        $this->SetY(-12);
        $this->SetDrawColor(200, 200, 200);
        $this->SetLineWidth(0.2);
        $this->Line(10, $this->GetY(), 287, $this->GetY());
        $this->Ln(1);
        $this->SetFont('Arial', '', 8);
        $this->SetTextColor(100, 100, 100);
        $this->Cell(0, 4, pt('m. Abreu - Instalações Técnicas, Lda. | Emitido em ' . date('d/m/Y H:i')), 0, 0, 'L');
        $this->Cell(0, 4, pt('Página ') . $this->PageNo() . '/{nb}', 0, 0, 'R');
    }

    function Cortar($texto, $largura) {
        $texto = pt($texto);
        if ($this->GetStringWidth($texto) <= $largura - 2) return $texto;
        while ($texto !== '' && $this->GetStringWidth($texto . '...') > $largura - 2) $texto = substr($texto, 0, -1);
        return $texto . '...';
    }

    // Retângulo com cantos arredondados (só os cantos indicados: 1=sup.esq, 2=sup.dir, 3=inf.dir, 4=inf.esq)
    function RoundedRect($x, $y, $w, $h, $r, $style = '', $cantos = '1234') {
        $k = $this->k; $hp = $this->h;
        $op = ($style == 'F') ? 'f' : (($style == 'FD' || $style == 'DF') ? 'B' : 'S');
        $MyArc = 4 / 3 * (sqrt(2) - 1);
        $this->_out(sprintf('%.2F %.2F m', ($x + $r) * $k, ($hp - $y) * $k));
        $xc = $x + $w - $r; $yc = $y + $r;
        $this->_out(sprintf('%.2F %.2F l', $xc * $k, ($hp - $y) * $k));
        if (strpos($cantos, '2') === false) $this->_out(sprintf('%.2F %.2F l', ($x + $w) * $k, ($hp - $y) * $k));
        else $this->_Arc($xc + $r * $MyArc, $yc - $r, $xc + $r, $yc - $r * $MyArc, $xc + $r, $yc);
        $xc = $x + $w - $r; $yc = $y + $h - $r;
        $this->_out(sprintf('%.2F %.2F l', ($x + $w) * $k, ($hp - $yc) * $k));
        if (strpos($cantos, '3') === false) $this->_out(sprintf('%.2F %.2F l', ($x + $w) * $k, ($hp - ($y + $h)) * $k));
        else $this->_Arc($xc + $r, $yc + $r * $MyArc, $xc + $r * $MyArc, $yc + $r, $xc, $yc + $r);
        $xc = $x + $r; $yc = $y + $h - $r;
        $this->_out(sprintf('%.2F %.2F l', $xc * $k, ($hp - ($y + $h)) * $k));
        if (strpos($cantos, '4') === false) $this->_out(sprintf('%.2F %.2F l', $x * $k, ($hp - ($y + $h)) * $k));
        else $this->_Arc($xc - $r * $MyArc, $yc + $r, $xc - $r, $yc + $r * $MyArc, $xc - $r, $yc);
        $xc = $x + $r; $yc = $y + $r;
        $this->_out(sprintf('%.2F %.2F l', $x * $k, ($hp - $yc) * $k));
        if (strpos($cantos, '1') === false) {
            $this->_out(sprintf('%.2F %.2F l', $x * $k, ($hp - $y) * $k));
            $this->_out(sprintf('%.2F %.2F l', ($x + $r) * $k, ($hp - $y) * $k));
        } else {
            $this->_Arc($xc - $r, $yc - $r * $MyArc, $xc - $r * $MyArc, $yc - $r, $xc, $yc - $r);
        }
        $this->_out($op);
    }

    function _Arc($x1, $y1, $x2, $y2, $x3, $y3) {
        $h = $this->h;
        $this->_out(sprintf('%.2F %.2F %.2F %.2F %.2F %.2F c ', $x1 * $this->k, ($h - $y1) * $this->k,
            $x2 * $this->k, ($h - $y2) * $this->k, $x3 * $this->k, ($h - $y3) * $this->k));
    }

    // ---------------------------------------------------------------
    // Cartão de um mês (estilo calendário anual: semanas, Seg..Dom)
    // $marcar($data) devolve ['fundo'=>[r,g,b], 'texto'=>[r,g,b], 'extra'=>'JS PC'] ou null
    // ---------------------------------------------------------------
    function CartaoMes($x, $y, $w, $ano, $mes, $nome_mes, $marcar) {
        $h_tit = 7.5; $h_cab = 5; $h_lin = 5.8;
        $h_total = $h_tit + $h_cab + 6 * $h_lin + 2;
        $w_sem = 6.5;
        $w_dia = ($w - $w_sem - 1) / 7;

        // Sombra + cartão
        $this->SetFillColor(225, 230, 236);
        $this->RoundedRect($x + 0.6, $y + 0.8, $w, $h_total, 2.2, 'F');
        $this->SetFillColor(255, 255, 255);
        $this->SetDrawColor(214, 221, 229);
        $this->SetLineWidth(0.2);
        $this->RoundedRect($x, $y, $w, $h_total, 2.2, 'DF');

        // Título azul
        $this->SetFillColor(41, 128, 185);
        $this->RoundedRect($x, $y, $w, $h_tit, 2.2, 'F', '12');
        $this->SetFillColor(52, 152, 219);
        $this->Rect($x, $y + $h_tit - 1.6, $w, 1.6, 'F');
        // Selo com o ano
        $this->SetFillColor(255, 255, 255);
        $this->SetDrawColor(41, 128, 185);
        $this->SetLineWidth(0.5);
        $this->Ellipse($x + 4.6, $y + 3.75, 3.6, 3.6, 'DF');
        $this->SetFont('Arial', 'B', 4.6);
        $this->SetTextColor(41, 128, 185);
        $this->SetXY($x + 1, $y + 2.4);
        $this->Cell(7.2, 2.8, $ano, 0, 0, 'C');
        $this->SetFont('Arial', 'B', 9.5);
        $this->SetTextColor(255, 255, 255);
        $this->SetXY($x + 9, $y + 1.3);
        $this->Cell($w - 10, 5, pt($nome_mes . ' ' . $ano), 0, 0, 'C');

        // Cabeçalho Seg..Dom
        $yc = $y + $h_tit + 0.6;
        $nomes = ['Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb', 'Dom'];
        $this->SetFont('Arial', 'B', 6);
        for ($i = 0; $i < 7; $i++) {
            $xd = $x + $w_sem + $i * $w_dia;
            if ($i >= 5) { $this->SetFillColor(240, 242, 245); $this->Rect($xd, $yc, $w_dia, $h_cab, 'F'); }
            $this->SetTextColor(44, 62, 80);
            $this->SetXY($xd, $yc);
            $this->Cell($w_dia, $h_cab, pt($nomes[$i]), 0, 0, 'C');
        }

        // Dias
        $primeiro = mktime(0, 0, 0, $mes, 1, $ano);
        $dias_mes = (int)date('t', $primeiro);
        $col = (int)date('N', $primeiro) - 1; // 0 = segunda
        $linha = 0;
        $y0 = $yc + $h_cab;
        // fundo cinza das colunas de fim de semana
        $this->SetFillColor(244, 246, 248);
        $this->Rect($x + $w_sem + 5 * $w_dia, $y0, 2 * $w_dia, 6 * $h_lin, 'F');

        for ($d = 1; $d <= $dias_mes; $d++) {
            $data = sprintf('%04d-%02d-%02d', $ano, $mes, $d);
            $yl = $y0 + $linha * $h_lin;
            if ($col === 0 || $d === 1) {
                // nº da semana (ISO)
                $this->SetFont('Arial', 'I', 5.5);
                $this->SetTextColor(149, 165, 166);
                $this->SetXY($x + 0.5, $yl);
                $this->Cell($w_sem - 1, $h_lin, (int)date('W', strtotime($data)), 0, 0, 'C');
            }
            $xd = $x + $w_sem + $col * $w_dia;
            $m = $marcar($data);
            if ($m) {
                $this->SetFillColor($m['fundo'][0], $m['fundo'][1], $m['fundo'][2]);
                $this->Rect($xd + 0.35, $yl + 0.35, $w_dia - 0.7, $h_lin - 0.7, 'F');
            }
            $tc = $m['texto'] ?? [44, 62, 80];
            $this->SetTextColor($tc[0], $tc[1], $tc[2]);
            $tem_extra = !empty($m['extra']);
            $this->SetFont('Arial', $m ? 'B' : '', 7);
            $this->SetXY($xd, $yl + ($tem_extra ? 0.3 : 0));
            $this->Cell($w_dia, $tem_extra ? 3.4 : $h_lin, $d, 0, 0, 'C');
            if ($tem_extra) {
                $this->SetFont('Arial', 'B', 3.9);
                $this->SetXY($xd, $yl + 3.3);
                $this->Cell($w_dia, 2.2, $this->Cortar($m['extra'], $w_dia + 1.5), 0, 0, 'C');
            }
            $col++;
            if ($col > 6) { $col = 0; $linha++; }
        }
        // linhas finas entre semanas
        $this->SetDrawColor(235, 238, 242);
        $this->SetLineWidth(0.1);
        for ($l = 1; $l < 6; $l++) $this->Line($x + 1, $y0 + $l * $h_lin, $x + $w - 1, $y0 + $l * $h_lin);
        return $h_total;
    }

    function Ellipse($x, $y, $rx, $ry, $style = 'D') {
        $op = ($style == 'F') ? 'f' : (($style == 'FD' || $style == 'DF') ? 'B' : 'S');
        $lx = 4 / 3 * (M_SQRT2 - 1) * $rx; $ly = 4 / 3 * (M_SQRT2 - 1) * $ry;
        $k = $this->k; $h = $this->h;
        $this->_out(sprintf('%.2F %.2F m %.2F %.2F %.2F %.2F %.2F %.2F c', ($x + $rx) * $k, ($h - $y) * $k, ($x + $rx) * $k, ($h - ($y - $ly)) * $k, ($x + $lx) * $k, ($h - ($y - $ry)) * $k, $x * $k, ($h - ($y - $ry)) * $k));
        $this->_out(sprintf('%.2F %.2F %.2F %.2F %.2F %.2F c', ($x - $lx) * $k, ($h - ($y - $ry)) * $k, ($x - $rx) * $k, ($h - ($y - $ly)) * $k, ($x - $rx) * $k, ($h - $y) * $k));
        $this->_out(sprintf('%.2F %.2F %.2F %.2F %.2F %.2F c', ($x - $rx) * $k, ($h - ($y + $ly)) * $k, ($x - $lx) * $k, ($h - ($y + $ry)) * $k, $x * $k, ($h - ($y + $ry)) * $k));
        $this->_out(sprintf('%.2F %.2F %.2F %.2F %.2F %.2F c %s', ($x + $lx) * $k, ($h - ($y + $ry)) * $k, ($x + $rx) * $k, ($h - ($y + $ly)) * $k, ($x + $rx) * $k, ($h - $y) * $k, $op));
    }

    // Calendário anual 4 x 3
    function CalendarioAnual($ano, $meses, $marcar) {
        $gap_x = 4.5; $gap_y = 2.8;
        $hc = 7.5 + 5 + 6 * 5.8 + 2; // altura de um cartão (igual ao CartaoMes)
        $w = (277 - 3 * $gap_x) / 4;
        $y = $this->GetY() + 1;
        $h = 0;
        foreach ($meses as $m => $nome) {
            $c = ($m - 1) % 4; $l = intdiv($m - 1, 4);
            $h = $this->CartaoMes(10 + $c * ($w + $gap_x), $y + $l * ($hc + $gap_y), $w, $ano, $m, $nome, $marcar);
        }
        $this->SetY($y + 3 * $hc + 2 * $gap_y + 2);
    }

    function Legenda($itens) {
        $x = 10; $y = $this->GetY();
        $this->SetFont('Arial', '', 7.5);
        foreach ($itens as [$c, $txt]) {
            $this->SetFillColor($c[0], $c[1], $c[2]);
            $this->SetDrawColor(200, 200, 200);
            $this->SetLineWidth(0.2);
            $this->Rect($x, $y + 0.6, 4, 3.6, 'DF');
            $this->SetXY($x + 5, $y);
            $this->SetTextColor(87, 101, 116);
            $wt = $this->GetStringWidth(pt($txt)) + 6;
            $this->Cell($wt, 5, pt($txt), 0, 0, 'L');
            $x += 5 + $wt;
        }
        $this->Ln(6);
    }
}

// Cores (iguais ao calendário da imagem: azul claro para feriados)
$COR_FERIADO = [214, 234, 248]; $TXT_FERIADO = [31, 97, 141];
$COR_FERIAS  = [171, 235, 198]; $TXT_FERIAS  = [20, 90, 50];
$COR_PONTE   = [39, 174, 96];   $TXT_PONTE   = [255, 255, 255];

$e_feriado = fn($data) => in_array($data, $cfg['feriados']) && !in_array($data, $cfg['pontes']);
$e_ponte   = fn($data) => in_array($data, $cfg['pontes']);

$pdf = new PDF('L', 'mm', 'A4');
$pdf->SetMargins(10, 10, 10);
$pdf->AliasNbPages();
$pdf->SetAutoPageBreak(true, 15);

// ===== PÁGINA 1: CALENDÁRIO GERAL (todos os trabalhadores) =====
$pdf->titulo_doc = 'MAPA DE FÉRIAS ' . $ano;
$pdf->subtitulo_doc = 'Geral | ' . count($trab) . ' trabalhador(es) | iniciais = quem está de férias';
$pdf->AddPage();
$pdf->CalendarioAnual($ano, $meses, function ($data) use ($ferias_dia, $e_feriado, $e_ponte, $COR_FERIADO, $TXT_FERIADO, $COR_FERIAS, $TXT_FERIAS, $COR_PONTE, $TXT_PONTE) {
    if ($e_ponte($data)) return ['fundo' => $COR_PONTE, 'texto' => $TXT_PONTE, 'extra' => 'PONTE'];
    if (!empty($ferias_dia[$data]) && (int)date('N', strtotime($data)) <= 5 && !$e_feriado($data)) {
        return ['fundo' => $COR_FERIAS, 'texto' => $TXT_FERIAS, 'extra' => implode(' ', $ferias_dia[$data])];
    }
    if ($e_feriado($data)) return ['fundo' => $COR_FERIADO, 'texto' => $TXT_FERIADO];
    return null;
});
$pdf->Legenda([[$COR_FERIADO, 'Feriado'], [$COR_PONTE, 'Ponte da empresa (férias de todos)'], [$COR_FERIAS, 'Férias (iniciais de quem está de férias)']]);
// Lista das iniciais: letra mais pequena (6 pt) para caber na mesma folha
$pdf->SetAutoPageBreak(false);
$pdf->SetY($pdf->GetY() - 1);
$pdf->SetFont('Arial', '', 6);
$pdf->SetTextColor(87, 101, 116);
$pdf->MultiCell(0, 3, pt('Iniciais: ' . implode('   ', array_map(fn($t) => $t['ini'] . ' = ' . $t['nome'], $trab))), 0, 'L');
$pdf->SetAutoPageBreak(true, 15);

// ===== PÁGINA 2: RESUMO POR TRABALHADOR =====
$pdf->titulo_doc = 'MAPA DE FÉRIAS ' . $ano;
$pdf->subtitulo_doc = 'Resumo por trabalhador';
$pdf->AddPage();
$lr = [92, 27, 27, 35, 27, 35, 34];
$cab = ['Trabalhador', 'Direito', 'Marcados', 'Faltam marcar', 'Gozados', 'Faltam gozar', 'Das quais pontes'];
$pdf->SetFont('Arial', 'B', 8.5);
$pdf->SetFillColor(41, 128, 185);
$pdf->SetTextColor(255, 255, 255);
$pdf->SetDrawColor(214, 221, 229);
$pdf->SetLineWidth(0.2);
foreach ($cab as $i => $c) $pdf->Cell($lr[$i], 8, pt($c), 1, 0, $i ? 'C' : 'L', true);
$pdf->Ln();
if (empty($trab)) {
    $pdf->SetFont('Arial', 'I', 9);
    $pdf->SetTextColor(127, 140, 141);
    $pdf->Cell(array_sum($lr), 8, pt('Sem trabalhadores ativos.'), 1, 1, 'C');
}
$fundo = false;
foreach ($trab as $t) {
    $pdf->SetFillColor(246, 249, 252);
    $pdf->SetFont('Arial', 'B', 9);
    $pdf->SetTextColor(44, 62, 80);
    $pdf->Cell($lr[0], 8, $pdf->Cortar(' ' . $t['ini'] . ' - ' . $t['nome'] . ($t['cargo'] ? ' (' . $t['cargo'] . ')' : ''), $lr[0]), 1, 0, 'L', $fundo);
    $pdf->SetFont('Arial', '', 10);
    $pdf->Cell($lr[1], 8, $t['direito'], 1, 0, 'C', $fundo);
    $pdf->Cell($lr[2], 8, $t['marcados'], 1, 0, 'C', $fundo);
    $pdf->SetFont('Arial', 'B', 10);
    if ($t['faltam_marcar'] < 0) { $pdf->SetTextColor(192, 57, 43); $txt = abs($t['faltam_marcar']) . ' em excesso'; }
    else { $pdf->SetTextColor(39, 174, 96); $txt = $t['faltam_marcar']; }
    $pdf->Cell($lr[3], 8, pt($txt), 1, 0, 'C', $fundo);
    $pdf->SetTextColor(41, 128, 185);
    $pdf->Cell($lr[4], 8, $t['gozados'], 1, 0, 'C', $fundo);
    $pdf->SetTextColor(211, 84, 0);
    $pdf->Cell($lr[5], 8, $t['faltam_gozar'], 1, 0, 'C', $fundo);
    $pdf->SetFont('Arial', '', 10);
    $pdf->SetTextColor(87, 101, 116);
    $pdf->Cell($lr[6], 8, $t['pontes'], 1, 1, 'C', $fundo);
    $fundo = !$fundo;
}
$pdf->Ln(2);
$pdf->SetFont('Arial', 'I', 7.5);
$pdf->SetTextColor(127, 140, 141);
$pdf->MultiCell(0, 4, pt('Contam só dias úteis (segunda a sexta, sem feriados). As pontes marcadas pela empresa contam como férias de todos. Gozados = até ' . date('d/m/Y') . '. Nas páginas seguintes está o calendário de cada trabalhador.'), 0, 'L');

// ===== UMA PÁGINA POR TRABALHADOR =====
foreach ($trab as $t) {
    $pdf->titulo_doc = 'MAPA DE FÉRIAS ' . $ano . ' - ' . $t['nome'];
    $faltam = $t['faltam_marcar'] < 0 ? abs($t['faltam_marcar']) . ' em excesso' : $t['faltam_marcar'] . ' por marcar';
    $pdf->subtitulo_doc = 'Direito ' . $t['direito'] . ' | Marcados ' . $t['marcados'] . ' | ' . $faltam . ' | Gozados ' . $t['gozados'] . ' | Faltam gozar ' . $t['faltam_gozar'];
    $pdf->AddPage();
    $dias_t = $t['dias'];
    $pdf->CalendarioAnual($ano, $meses, function ($data) use ($dias_t, $e_feriado, $COR_FERIADO, $TXT_FERIADO, $COR_FERIAS, $TXT_FERIAS, $COR_PONTE, $TXT_PONTE) {
        $tipo = $dias_t[$data] ?? null;
        if ($tipo === 'ponte') return ['fundo' => $COR_PONTE, 'texto' => $TXT_PONTE];
        $util = (int)date('N', strtotime($data)) <= 5 && !$e_feriado($data);
        if ($tipo === 'ferias' && $util) return ['fundo' => $COR_FERIAS, 'texto' => $TXT_FERIAS];
        if ($e_feriado($data)) return ['fundo' => $COR_FERIADO, 'texto' => $TXT_FERIADO];
        return null;
    });
    $pdf->Legenda([[$COR_FERIADO, 'Feriado'], [$COR_FERIAS, 'Férias'], [$COR_PONTE, 'Ponte da empresa (conta como férias)']]);
}

ob_end_clean();
$pdf->Output('I', 'Mapa_Ferias_' . $ano . '.pdf');
?>
