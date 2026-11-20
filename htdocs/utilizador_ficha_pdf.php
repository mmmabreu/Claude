<?php
// REGRA DE OURO: Limpar qualquer espaço em branco ou lixo antes de gerar o PDF
ob_start();
session_start();

if (!isset($_SESSION['user_id'])) {
    ob_end_clean();
    die("Acesso negado.");
}
// Tem dados salariais: só o Administrador (igual à ficha utilizador_form.php)
if (($_SESSION['perfil'] ?? '') !== 'admin') {
    ob_end_clean();
    die("Acesso negado.");
}

require_once 'db.php';
require_once 'ferias_calculo.php';
require_once('fpdf/fpdf.php');

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$ano = filter_input(INPUT_GET, 'ano', FILTER_VALIDATE_INT) ?: (int)date('Y');
$hoje = date('Y-m-d');

try {
    $st = $pdo->prepare("SELECT * FROM utilizadores WHERE id = ?");
    $st->execute([$id]);
    $u = $st->fetch(PDO::FETCH_ASSOC);
    if (!$u) { ob_end_clean(); die("Colaborador não encontrado."); }
    $cfg = feriasConfig($pdo);
} catch (Exception $e) {
    ob_end_clean();
    die("Erro na BD: " . htmlspecialchars($e->getMessage()));
}

$f = feriasCalcular($u['ferias_marcadas'] ?? '[]', $ano, $cfg, $hoje);
$direito = (int)($u['dias_ferias'] ?? 22);

$perfis = ['admin' => 'Administrador (Acesso Total)', 'administrativo' => 'Administrativo', 'funcionario' => 'Funcionário', 'externo' => 'Externo (Parceiro / Subempreiteiro)'];
$meses = [1 => 'Jan', 2 => 'Fev', 3 => 'Mar', 4 => 'Abr', 5 => 'Mai', 6 => 'Jun', 7 => 'Jul', 8 => 'Ago', 9 => 'Set', 10 => 'Out', 11 => 'Nov', 12 => 'Dez'];

function pt($t) { return mb_convert_encoding((string)($t ?? ''), 'windows-1252', 'UTF-8'); }
function eur($v) { return number_format((float)$v, 2, ',', '.') . ' €'; } // texto UTF-8: passa sempre por pt()
function dataPT($d) { return !empty($d) ? date('d/m/Y', strtotime($d)) : '-'; }

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
        $this->SetDrawColor(52, 73, 94);
        $this->SetLineWidth(0.6);
        $this->Line(10, 25, 200, 25);
        $this->SetY(30);
    }

    function Footer() {
        $this->SetY(-12);
        $this->SetDrawColor(200, 200, 200);
        $this->SetLineWidth(0.2);
        $this->Line(10, $this->GetY(), 200, $this->GetY());
        $this->Ln(1);
        $this->SetFont('Arial', '', 8);
        $this->SetTextColor(100, 100, 100);
        $this->Cell(0, 4, pt('m. Abreu - Instalações Técnicas, Lda. | Documento confidencial | Emitido em ' . date('d/m/Y H:i')), 0, 0, 'L');
        $this->Cell(0, 4, pt('Página ') . $this->PageNo() . '/{nb}', 0, 0, 'R');
    }

    function Espaco($h) {
        if ($this->GetY() + $h > $this->PageBreakTrigger) $this->AddPage();
    }

    function Seccao($titulo, $cor) {
        $this->Espaco(20);
        $this->SetFont('Arial', 'B', 10.5);
        $this->SetTextColor($cor[0], $cor[1], $cor[2]);
        $this->Cell(0, 7, pt($titulo), 0, 1, 'L');
        $this->SetDrawColor($cor[0], $cor[1], $cor[2]);
        $this->SetLineWidth(0.4);
        $this->Line(10, $this->GetY(), 200, $this->GetY());
        $this->Ln(2.5);
    }

    // Grelha de campos: [[rótulo, valor], ...] em 2 colunas
    function Campos($campos) {
        $larg = 95; $h = 11;
        foreach (array_chunk($campos, 2) as $par) {
            $this->Espaco($h);
            $y = $this->GetY();
            foreach ($par as $i => [$rot, $val]) {
                $x = 10 + $i * $larg;
                $this->SetFillColor(248, 249, 251);
                $this->SetDrawColor(225, 229, 234);
                $this->SetLineWidth(0.2);
                $this->Rect($x, $y, $larg - 2, $h - 1.5, 'DF');
                $this->SetXY($x + 2.5, $y + 1.2);
                $this->SetFont('Arial', 'B', 6.5);
                $this->SetTextColor(127, 140, 141);
                $this->Cell($larg - 6, 3.5, pt(mb_strtoupper($rot)), 0, 0, 'L');
                $this->SetXY($x + 2.5, $y + 4.6);
                $this->SetFont('Arial', '', 9.5);
                $this->SetTextColor(44, 62, 80);
                $this->Cell($larg - 6, 4.5, pt($val), 0, 0, 'L');
            }
            $this->SetY($y + $h);
        }
    }
}

$pdf = new PDF('P', 'mm', 'A4');
$pdf->titulo_doc = 'FICHA DE COLABORADOR';
$pdf->subtitulo_doc = $u['nome'];
$pdf->SetMargins(10, 10, 10);
$pdf->AliasNbPages();
$pdf->SetAutoPageBreak(true, 15);
$pdf->AddPage();

// Estado da conta
$ativo = (int)($u['ativo'] ?? 1) === 1;
$pdf->SetFont('Arial', 'B', 9);
$pdf->SetFillColor($ativo ? 234 : 253, $ativo ? 250 : 237, $ativo ? 241 : 236);
$pdf->SetTextColor($ativo ? 30 : 192, $ativo ? 132 : 57, $ativo ? 73 : 43);
$pdf->Cell(0, 7, pt($ativo ? '  Conta ativa no sistema' : '  Conta suspensa / inativa'), 0, 1, 'L', true);
$pdf->Ln(3);

// ===== IDENTIFICAÇÃO =====
$pdf->Seccao('IDENTIFICAÇÃO E ACESSOS', [52, 73, 94]);
$pdf->Campos([
    ['Nome completo', $u['nome'] ?? ''],
    ['NIF', $u['nif'] ?? ''],
    ['Telemóvel / Telefone', $u['telefone'] ?? ''],
    ['Email', ($u['email'] ?? '') ?: '-'],
    ['Cargo / Função', ($u['cargo'] ?? '') ?: '-'],
    ['Perfil de acesso', $perfis[$u['perfil'] ?? ''] ?? ($u['perfil'] ?? '')],
    ['Utilizador (login)', $u['username'] ?? ''],
    ['Data de registo', !empty($u['data_registo']) ? dataPT($u['data_registo']) : '-'],
]);
$pdf->Ln(3);

// ===== ZONA FINANCEIRA =====
$pdf->Seccao('ZONA FINANCEIRA', [211, 84, 0]);
$pdf->Campos([
    ['Ordenado base', eur($u['ordenado_base'] ?? 0)],
    ['Meses pagos / ano', (int)($u['meses_ano'] ?? 14)],
    ['Subsídio de alimentação (diário)', eur($u['sub_alimentacao'] ?? 0)],
    ['Ajudas de custo mensais', eur($u['ajudas_custo'] ?? 0)],
    ['Seguro de acidentes de trabalho', number_format((float)($u['seguro_acidentes'] ?? 0), 2, ',', '') . ' %'],
    ['Direito a férias (dias/ano)', $direito],
]);
$pdf->Espaco(16);
$y = $pdf->GetY();
$pdf->SetFillColor(255, 250, 240);
$pdf->SetDrawColor(230, 126, 34);
$pdf->SetLineWidth(0.4);
$pdf->Rect(10, $y, 188, 13, 'DF');
$pdf->SetXY(14, $y + 2);
$pdf->SetFont('Arial', 'B', 8);
$pdf->SetTextColor(211, 84, 0);
$pdf->Cell(110, 4, pt('CUSTO REAL À HORA PARA A EMPRESA'), 0, 0, 'L');
$pdf->SetXY(14, $y + 6.5);
$pdf->SetFont('Arial', '', 7);
$pdf->SetTextColor(127, 140, 141);
$pdf->Cell(120, 4, pt('Inclui TSU (23,75%), seguro, subsídio de alimentação e ajudas; desconta férias e feriados.'), 0, 0, 'L');
$pdf->SetXY(130, $y + 3);
$pdf->SetFont('Arial', 'B', 16);
$pdf->SetTextColor(211, 84, 0);
$pdf->Cell(64, 8, pt(eur($u['valor_hora'] ?? 0) . ' / h'), 0, 0, 'R');
$pdf->SetY($y + 17);

// ===== FÉRIAS =====
$pdf->Seccao('FÉRIAS ' . $ano, [39, 174, 96]);
$caixas = [
    ['Direito', $direito, [44, 62, 80], [248, 249, 251]],
    ['Marcados', $f['marcados'], [44, 62, 80], [248, 249, 251]],
    [($direito - $f['marcados']) < 0 ? 'Em excesso' : 'Faltam marcar', abs($direito - $f['marcados']), ($direito - $f['marcados']) < 0 ? [192, 57, 43] : [39, 174, 96], ($direito - $f['marcados']) < 0 ? [253, 237, 236] : [234, 250, 241]],
    ['Gozados', $f['gozados'], [41, 128, 185], [235, 245, 251]],
    ['Faltam gozar', max(0, $direito - $f['gozados']), [211, 84, 0], [254, 245, 231]],
];
$pdf->Espaco(16);
$y = $pdf->GetY();
foreach ($caixas as $i => [$rot, $val, $cor, $fundo]) {
    $x = 10 + $i * 38;
    $pdf->SetFillColor($fundo[0], $fundo[1], $fundo[2]);
    $pdf->SetDrawColor(225, 229, 234);
    $pdf->SetLineWidth(0.2);
    $pdf->Rect($x, $y, 36, 14, 'DF');
    $pdf->SetXY($x, $y + 1.5);
    $pdf->SetFont('Arial', 'B', 6.5);
    $pdf->SetTextColor(127, 140, 141);
    $pdf->Cell(36, 3.5, pt(mb_strtoupper($rot)), 0, 0, 'C');
    $pdf->SetXY($x, $y + 5.5);
    $pdf->SetFont('Arial', 'B', 14);
    $pdf->SetTextColor($cor[0], $cor[1], $cor[2]);
    $pdf->Cell(36, 7, $val, 0, 0, 'C');
}
$pdf->SetY($y + 16);
$pdf->SetFont('Arial', 'I', 7);
$pdf->SetTextColor(127, 140, 141);
$pdf->Cell(0, 4, pt('Contam só dias úteis (segunda a sexta, sem feriados). As pontes da empresa contam como férias. Gozados = até ' . date('d/m/Y') . '.'), 0, 1, 'L');
$pdf->Ln(2);

// Períodos marcados
$lp = [30, 30, 30, 30, 68];
$pdf->Espaco(16);
$pdf->SetFont('Arial', 'B', 8);
$pdf->SetFillColor(241, 242, 246);
$pdf->SetTextColor(87, 101, 116);
$pdf->SetDrawColor(220, 220, 220);
foreach (['Início', 'Fim', 'Dias seguidos', 'Dias úteis', 'Estado'] as $i => $c) $pdf->Cell($lp[$i], 7, pt($c), 1, 0, 'C', true);
$pdf->Ln();
$linhas = [];
foreach ($f['periodos'] as $p) $linhas[] = [$p['inicio'], dataPT($p['inicio']), dataPT($p['fim']), $p['dias'], $p['uteis'], $p['estado']];
foreach ($f['pontes'] as $pd) $linhas[] = [$pd, dataPT($pd), dataPT($pd), 1, 1, ($pd <= $hoje ? 'Gozado' : 'Por gozar') . ' (ponte da empresa)'];
usort($linhas, fn($a, $b) => strcmp($a[0], $b[0]));
if (empty($linhas)) {
    $pdf->SetFont('Arial', 'I', 8.5);
    $pdf->SetTextColor(127, 140, 141);
    $pdf->Cell(array_sum($lp), 7, pt('Sem férias marcadas em ' . $ano . '.'), 1, 1, 'C');
}
$fundo = false;
foreach ($linhas as $l) {
    $pdf->Espaco(6.5);
    $pdf->SetFillColor(250, 251, 252);
    $pdf->SetFont('Arial', '', 8.5);
    $pdf->SetTextColor(44, 62, 80);
    $pdf->Cell($lp[0], 6.5, $l[1], 1, 0, 'C', $fundo);
    $pdf->Cell($lp[1], 6.5, $l[2], 1, 0, 'C', $fundo);
    $pdf->Cell($lp[2], 6.5, $l[3], 1, 0, 'C', $fundo);
    $pdf->SetFont('Arial', 'B', 8.5);
    $pdf->SetTextColor(39, 174, 96);
    $pdf->Cell($lp[3], 6.5, $l[4], 1, 0, 'C', $fundo);
    $pdf->SetFont('Arial', '', 8.5);
    $pdf->SetTextColor(87, 101, 116);
    $pdf->Cell($lp[4], 6.5, pt($l[5]), 1, 1, 'C', $fundo);
    $fundo = !$fundo;
}
$pdf->Ln(4);

// Calendário do ano (12 meses x 31 dias)
$w_mes = 14; $w_dia = (190 - $w_mes) / 31; $h = 4.6;
$pdf->Espaco(12 * $h + 10);
$y = $pdf->GetY();
$pdf->SetFont('Arial', 'B', 5.5);
$pdf->SetTextColor(127, 140, 141);
for ($d = 1; $d <= 31; $d++) {
    $pdf->SetXY(10 + $w_mes + ($d - 1) * $w_dia, $y);
    $pdf->Cell($w_dia, 3.5, $d, 0, 0, 'C');
}
$y += 4;
$pdf->SetDrawColor(215, 219, 224);
$pdf->SetLineWidth(0.1);
foreach ($meses as $m => $nm) {
    $pdf->SetXY(10, $y);
    $pdf->SetFont('Arial', 'B', 7);
    $pdf->SetTextColor(87, 101, 116);
    $pdf->Cell($w_mes, $h, pt($nm), 0, 0, 'L');
    $dm = cal_days_in_month(CAL_GREGORIAN, $m, $ano);
    for ($d = 1; $d <= 31; $d++) {
        $x = 10 + $w_mes + ($d - 1) * $w_dia;
        if ($d > $dm) { $pdf->SetFillColor(255, 255, 255); $pdf->Rect($x, $y, $w_dia, $h, 'D'); continue; }
        $data = sprintf('%04d-%02d-%02d', $ano, $m, $d);
        $n = (int)date('N', strtotime($data));
        $e_fer = in_array($data, $cfg['feriados']) && !in_array($data, $cfg['pontes']);
        $marca = $f['dias'][$data] ?? null;
        if ($marca === 'ponte') $c = [39, 174, 96];
        elseif ($marca === 'ferias') $c = ($n >= 6 || $e_fer) ? [213, 245, 227] : [130, 224, 170];
        elseif ($e_fer && $n <= 5) $c = [252, 243, 207];
        elseif ($n >= 6) $c = [236, 239, 243];
        else $c = [255, 255, 255];
        $pdf->SetFillColor($c[0], $c[1], $c[2]);
        $pdf->Rect($x, $y, $w_dia, $h, 'DF');
    }
    $y += $h;
}
$pdf->SetY($y + 2);
$x = 10;
$pdf->SetFont('Arial', '', 7);
foreach ([[[130, 224, 170], 'Férias'], [[39, 174, 96], 'Ponte da empresa'], [[252, 243, 207], 'Feriado'], [[236, 239, 243], 'Fim de semana']] as [$c, $txt]) {
    $pdf->SetFillColor($c[0], $c[1], $c[2]);
    $pdf->SetDrawColor(200, 200, 200);
    $pdf->Rect($x, $pdf->GetY() + 0.6, 3.5, 3.5, 'DF');
    $pdf->SetXY($x + 4.5, $pdf->GetY());
    $pdf->SetTextColor(87, 101, 116);
    $pdf->Cell(36, 4.5, pt($txt), 0, 0, 'L');
    $x += 42;
}
$pdf->Ln(6);

// Assinaturas
$pdf->Espaco(24);
$pdf->Ln(12);
$y = $pdf->GetY();
$pdf->SetDrawColor(150, 150, 150);
$pdf->SetLineWidth(0.2);
$pdf->Line(15, $y, 95, $y);
$pdf->Line(115, $y, 195, $y);
$pdf->SetFont('Arial', '', 8);
$pdf->SetTextColor(87, 101, 116);
$pdf->SetXY(15, $y + 1);
$pdf->Cell(80, 5, pt('O Colaborador'), 0, 0, 'C');
$pdf->SetXY(115, $y + 1);
$pdf->Cell(80, 5, pt('m. Abreu - Instalações Técnicas, Lda.'), 0, 0, 'C');

ob_end_clean();
$pdf->Output('I', 'Ficha_' . preg_replace('/[^A-Za-z0-9]+/', '_', iconv('UTF-8', 'ASCII//TRANSLIT', $u['nome']) ?: 'Colaborador') . '.pdf');
?>
