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
require_once('fpdf/fpdf.php');

// --- BUSCAR OS DOCUMENTOS (os mesmos que aparecem no ecrã: não acertados, por data do documento) ---
try {
    $registos = $pdo->query("SELECT * FROM lsjr_fatfav
                             WHERE estado != 'Acertado'
                             ORDER BY (data_emissao IS NULL), data_emissao ASC, tipo_doc ASC, numero_documento ASC, id ASC")
                    ->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    ob_end_clean();
    die("Erro na BD: " . htmlspecialchars($e->getMessage()));
}

// Função de encoding para FPDF
function pt($texto) {
    return mb_convert_encoding((string)($texto ?? ''), 'windows-1252', 'UTF-8');
}
function eur($v) {
    return number_format((float)$v, 2, ',', '.') . ' €';
}
function dataPT($d) {
    return !empty($d) ? date('d/m/Y', strtotime($d)) : '';
}

// --- CLASSE PDF CUSTOMIZADA ---
class PDF extends FPDF {
    public $titulo_doc;
    public $subtitulo_doc;
    // Larguras das colunas (A4 horizontal: 277 mm úteis)
    public $larg = [12, 16, 42, 22, 22, 22, 30, 18, 93];
    public $cab = ['Secção', 'Doc.', 'Nº Documento', 'Nº Externo', 'Emissão', 'Vencimento', 'Valor', 'Estado', 'Observações'];

    function Header() {
        if (file_exists('mab.png')) {
            $this->Image('mab.png', 10, 8, 14);
        }
        $this->SetY(9);
        $this->SetFont('Arial', 'B', 14);
        $this->SetTextColor(44, 62, 80);
        $this->Cell(0, 7, pt($this->titulo_doc), 0, 1, 'R');
        $this->SetFont('Arial', '', 9);
        $this->SetTextColor(100, 100, 100);
        $this->Cell(0, 5, pt($this->subtitulo_doc), 0, 1, 'R');

        $this->SetDrawColor(230, 126, 34); // Laranja do módulo de faturação
        $this->SetLineWidth(0.6);
        $this->Line(10, 25, 287, 25);
        $this->SetY(29);
        $this->TabelaCabecalho();
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

    function TabelaCabecalho() {
        $this->SetFont('Arial', 'B', 8);
        $this->SetFillColor(241, 242, 246);
        $this->SetTextColor(87, 101, 116);
        $this->SetDrawColor(220, 220, 220);
        $this->SetLineWidth(0.2);
        foreach ($this->cab as $i => $c) {
            $alinhamento = ($i === 6) ? 'R' : (in_array($i, [2, 8]) ? 'L' : 'C');
            $this->Cell($this->larg[$i], 7, pt($c), 1, 0, $alinhamento, true);
        }
        $this->Ln();
        $this->SetTextColor(0, 0, 0);
    }

    // Corta o texto para caber na largura da célula (acrescenta "...")
    function Cortar($texto, $largura) {
        $texto = pt($texto);
        if ($this->GetStringWidth($texto) <= $largura - 2) return $texto;
        while ($texto !== '' && $this->GetStringWidth($texto . '...') > $largura - 2) {
            $texto = substr($texto, 0, -1);
        }
        return $texto . '...';
    }

    function Linha($r, $fundo) {
        if ($this->GetY() + 6 > $this->PageBreakTrigger) { $this->AddPage(); }

        $original = (float)$r['valor_original'];

        $this->SetFillColor(250, 251, 252);
        $this->SetFont('Arial', '', 8);
        $this->SetTextColor(0, 0, 0);
        $this->Cell($this->larg[0], 6, pt($r['seccao']), 1, 0, 'C', $fundo);
        $this->SetFont('Arial', 'B', 8);
        $this->Cell($this->larg[1], 6, $this->Cortar($r['tipo_doc'] ?? '', $this->larg[1]), 1, 0, 'C', $fundo);
        $this->Cell($this->larg[2], 6, $this->Cortar($r['numero_documento'] ?? '', $this->larg[2]), 1, 0, 'L', $fundo);
        $this->SetFont('Arial', '', 8);
        $this->Cell($this->larg[3], 6, $this->Cortar($r['numero_externo'] ?? '', $this->larg[3]), 1, 0, 'C', $fundo);
        $this->Cell($this->larg[4], 6, pt(dataPT($r['data_emissao'])), 1, 0, 'C', $fundo);
        $this->Cell($this->larg[5], 6, pt(dataPT($r['data_vencimento'])), 1, 0, 'C', $fundo);
        // Valores negativos (Notas de Crédito) a vermelho
        $this->SetTextColor($original < 0 ? 192 : 0, $original < 0 ? 57 : 0, $original < 0 ? 43 : 0);
        $this->Cell($this->larg[6], 6, pt(eur($original)), 1, 0, 'R', $fundo);
        $this->SetTextColor(0, 0, 0);
        $this->Cell($this->larg[7], 6, pt($r['estado']), 1, 0, 'C', $fundo);
        $this->Cell($this->larg[8], 6, $this->Cortar($r['observacoes'] ?? '', $this->larg[8]), 1, 1, 'L', $fundo);
    }
}

// --- TOTAIS ---
$total_original = 0;
$por_tipo = []; // Resumo por tipo de documento (V/FAC, V/CRE...)
foreach ($registos as $r) {
    $total_original += (float)$r['valor_original'];
    $t = strtoupper(trim($r['tipo_doc'] ?? '')) ?: '-';
    if (!isset($por_tipo[$t])) $por_tipo[$t] = ['n' => 0, 'original' => 0];
    $por_tipo[$t]['n']++;
    $por_tipo[$t]['original'] += (float)$r['valor_original'];
}
ksort($por_tipo);

// --- GERAR PDF ---
$pdf = new PDF('L', 'mm', 'A4');
$pdf->titulo_doc = 'LSJR - A FAVOR DE LSJR';
$pdf->subtitulo_doc = count($registos) . ' documento(s)';
$pdf->SetMargins(10, 10, 10);
$pdf->AliasNbPages();
$pdf->SetAutoPageBreak(true, 15);
$pdf->AddPage();

if (empty($registos)) {
    $pdf->SetFont('Arial', 'I', 10);
    $pdf->Cell(0, 10, pt('Não existem documentos.'), 0, 1, 'C');
} else {
    $fundo = false;
    foreach ($registos as $r) {
        $pdf->Linha($r, $fundo);
        $fundo = !$fundo;
    }

    // Linha de Totais
    if ($pdf->GetY() + 8 > 195) { $pdf->AddPage(); }
    $w = $pdf->larg;
    $pdf->SetFont('Arial', 'B', 9);
    $pdf->SetFillColor(241, 242, 246);
    $pdf->Cell($w[0] + $w[1] + $w[2] + $w[3] + $w[4] + $w[5], 7, pt('TOTAIS'), 1, 0, 'R', true);
    $pdf->Cell($w[6], 7, pt(eur($total_original)), 1, 0, 'R', true);
    $pdf->Cell($w[7] + $w[8], 7, '', 1, 1, 'L', true);

    // --- RESUMO POR TIPO DE DOCUMENTO (lado esquerdo) e COM / SEM IVA (lado direito) ---
    $taxa_iva = 0.23; // Os valores dos documentos já incluem IVA
    $sem_iva = fn($v) => round($v / (1 + $taxa_iva), 2);

    $altura_resumo = 7 + 7 * max(count($por_tipo) + 1, 3) + 8;
    if ($pdf->GetY() + 5 + $altura_resumo > 195) { $pdf->AddPage(); }
    $pdf->Ln(5);
    $y_resumos = $pdf->GetY();

    // Resumo por tipo
    $lt = [30, 14, 34];
    $pdf->SetXY(10, $y_resumos);
    $pdf->SetFont('Arial', 'B', 9);
    $pdf->SetFillColor(241, 242, 246);
    $pdf->Cell($lt[0], 7, pt(' POR TIPO'), 1, 0, 'L', true);
    $pdf->Cell($lt[1], 7, pt('Qtd.'), 1, 0, 'C', true);
    $pdf->Cell($lt[2], 7, pt('Valor'), 1, 1, 'R', true);
    $pdf->SetFont('Arial', '', 9);
    foreach ($por_tipo as $tipo => $v) {
        $pdf->SetX(10);
        $pdf->Cell($lt[0], 7, pt(' ' . $tipo), 1, 0, 'L');
        $pdf->Cell($lt[1], 7, $v['n'], 1, 0, 'C');
        $pdf->SetTextColor($v['original'] < 0 ? 192 : 0, $v['original'] < 0 ? 57 : 0, $v['original'] < 0 ? 43 : 0);
        $pdf->Cell($lt[2], 7, pt(eur($v['original']) . ' '), 1, 1, 'R');
        $pdf->SetTextColor(0, 0, 0);
    }

    // Com / Sem IVA
    $lr = [52, 30, 30, 30];
    $larg_resumo = array_sum($lr);
    $xResumo = 287 - $larg_resumo;
    $pdf->SetXY($xResumo, $y_resumos);
    $pdf->SetFont('Arial', 'B', 9);
    $pdf->Cell($lr[0], 7, pt(' RESUMO'), 1, 0, 'L', true);
    $pdf->Cell($lr[1], 7, pt('Sem IVA'), 1, 0, 'R', true);
    $pdf->Cell($lr[2], 7, pt('IVA (' . round($taxa_iva * 100) . '%)'), 1, 0, 'R', true);
    $pdf->Cell($lr[3], 7, pt('Com IVA'), 1, 1, 'R', true);
    foreach ([['Total', $total_original, $total_original < 0 ? [192, 57, 43] : [44, 62, 80], true]] as $lin) {
        [$desc, $com, $cor, $destaque] = $lin;
        $sem = $sem_iva($com);
        $iva = round($com - $sem, 2);
        $pdf->SetX($xResumo);
        $pdf->SetFont('Arial', $destaque ? 'B' : '', $destaque ? 10 : 9);
        $pdf->SetTextColor(0, 0, 0);
        $pdf->Cell($lr[0], 7, pt(' ' . $desc), 1, 0, 'L');
        $pdf->SetTextColor($cor[0], $cor[1], $cor[2]);
        $pdf->Cell($lr[1], 7, pt(eur($sem) . ' '), 1, 0, 'R');
        $pdf->Cell($lr[2], 7, pt(eur($iva) . ' '), 1, 0, 'R');
        $pdf->Cell($lr[3], 7, pt(eur($com) . ' '), 1, 1, 'R');
    }
    $pdf->SetX($xResumo);
    $pdf->SetFont('Arial', 'I', 8);
    $pdf->SetTextColor(120, 120, 120);
    $pdf->Cell($larg_resumo, 5, pt('Os valores dos documentos incluem IVA a ' . round($taxa_iva * 100) . '%. Sem IVA = Com IVA / ' . number_format(1 + $taxa_iva, 2, ',', '') . '.'), 0, 1, 'L');
    $pdf->SetTextColor(0, 0, 0);
}

ob_end_clean();
$pdf->Output('I', 'LSJR_A_favor_de_LSJR_' . date('Ymd') . '.pdf');
?>
