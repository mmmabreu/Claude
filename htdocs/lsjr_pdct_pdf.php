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
    $registos = $pdo->query("SELECT * FROM lsjr_pdct
                             WHERE estado != 'Acertado'
                             ORDER BY (data_registo IS NULL), data_registo ASC, pdct ASC, numero ASC, id ASC")
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

// --- CLASSE PDF CUSTOMIZADA ---
class PDF extends FPDF {
    public $titulo_doc;
    public $subtitulo_doc;
    // Larguras das colunas (A4 horizontal: 277 mm úteis)
    public $larg = [18, 14, 22, 50, 28, 28, 26, 18, 20, 53];
    public $cab = ['Número', 'Secção', 'Data', 'Ref.', 'Valor', 'V. Corrigido', 'Diferença', 'V. Certo', 'Estado', 'Observações'];

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
            $alinhamento = in_array($i, [4, 5, 6]) ? 'R' : (in_array($i, [3, 9]) ? 'L' : 'C');
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

        $valor = (float)$r['valor'];
        $corrigido = ($r['valor_corrigido'] !== null) ? (float)$r['valor_corrigido'] : null;
        $data = !empty($r['data_registo']) ? date('d/m/Y', strtotime($r['data_registo'])) : '';
        $certo = (($r['valor_certo'] ?? 'Nao') === 'Sim') ? 'Sim' : 'Não';

        $this->SetFillColor(250, 251, 252);
        $this->SetFont('Arial', 'B', 8);
        $this->Cell($this->larg[0], 6, pt($r['numero'] ?? ''), 1, 0, 'C', $fundo);
        $this->SetFont('Arial', '', 8);
        $this->Cell($this->larg[1], 6, pt($r['seccao']), 1, 0, 'C', $fundo);
        $this->Cell($this->larg[2], 6, pt($data), 1, 0, 'C', $fundo);
        $this->Cell($this->larg[3], 6, $this->Cortar($r['pdct'] ?? '', $this->larg[3]), 1, 0, 'L', $fundo);
        $this->SetTextColor($valor < 0 ? 192 : 0, $valor < 0 ? 57 : 0, $valor < 0 ? 43 : 0);
        $this->Cell($this->larg[4], 6, pt(eur($valor)), 1, 0, 'R', $fundo);
        $this->SetTextColor(211, 84, 0);
        $this->Cell($this->larg[5], 6, pt($corrigido !== null ? eur($corrigido) : ''), 1, 0, 'R', $fundo);
        // Diferença = Valor - V. Corrigido (só quando há V. Corrigido)
        if ($corrigido !== null) {
            $dif = round($valor - $corrigido, 2);
            if (abs($dif) < 0.005) { $this->SetTextColor(39, 174, 96); } else { $this->SetTextColor(192, 57, 43); }
            $this->Cell($this->larg[6], 6, pt(eur($dif)), 1, 0, 'R', $fundo);
        } else {
            $this->SetTextColor(150, 150, 150);
            $this->Cell($this->larg[6], 6, '-', 1, 0, 'C', $fundo);
        }
        // V. Certo a verde/vermelho
        if ($certo === 'Sim') { $this->SetTextColor(39, 174, 96); } else { $this->SetTextColor(192, 57, 43); }
        $this->SetFont('Arial', 'B', 8);
        $this->Cell($this->larg[7], 6, pt($certo), 1, 0, 'C', $fundo);
        $this->SetFont('Arial', '', 8);
        $this->SetTextColor(0, 0, 0);
        $this->Cell($this->larg[8], 6, pt($r['estado']), 1, 0, 'C', $fundo);
        $this->Cell($this->larg[9], 6, $this->Cortar($r['observacoes'] ?? '', $this->larg[9]), 1, 1, 'L', $fundo);
    }
}

// --- TOTAIS ---
$total_valor = 0;
$total_corrigido = 0;
$total_dif_linhas = 0;      // soma das diferenças das linhas que têm V. Corrigido
$n_sem_corrigido = 0;
$n_por_conferir = 0;
foreach ($registos as $r) {
    $total_valor += (float)$r['valor'];
    $total_corrigido += (float)($r['valor_corrigido'] ?? 0);
    if ($r['valor_corrigido'] !== null) { $total_dif_linhas += (float)$r['valor'] - (float)$r['valor_corrigido']; } else { $n_sem_corrigido++; }
    if (($r['valor_certo'] ?? 'Nao') !== 'Sim') $n_por_conferir++;
}

// --- GERAR PDF ---
$pdf = new PDF('L', 'mm', 'A4');
$pdf->titulo_doc = 'LSJR - PEDIDOS DE COTAÇÃO (PDCT)';
$pdf->subtitulo_doc = count($registos) . ' documento(s) pendente(s)';
$pdf->SetMargins(10, 10, 10);
$pdf->AliasNbPages();
$pdf->SetAutoPageBreak(true, 15);
$pdf->AddPage();

if (empty($registos)) {
    $pdf->SetFont('Arial', 'I', 10);
    $pdf->Cell(0, 10, pt('Não existem documentos pendentes.'), 0, 1, 'C');
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
    $pdf->Cell($w[0] + $w[1] + $w[2] + $w[3], 7, pt('TOTAIS'), 1, 0, 'R', true);
    $pdf->Cell($w[4], 7, pt(eur($total_valor)), 1, 0, 'R', true);
    $pdf->SetTextColor(211, 84, 0);
    $pdf->Cell($w[5], 7, pt(eur($total_corrigido)), 1, 0, 'R', true);
    $total_dif_linhas = round($total_dif_linhas, 2);
    if (abs($total_dif_linhas) < 0.005) { $pdf->SetTextColor(39, 174, 96); } else { $pdf->SetTextColor(192, 57, 43); }
    $pdf->Cell($w[6], 7, pt(eur($total_dif_linhas)), 1, 0, 'R', true);
    $pdf->SetTextColor(0, 0, 0);
    $pdf->Cell($w[7] + $w[8] + $w[9], 7, '', 1, 1, 'L', true);

    // RESUMO: Valor - V. Corrigido, SEM IVA / IVA / COM IVA
    // Os valores dos PDCT (Total Doc.) já incluem IVA -> Sem IVA = Com IVA / (1 + taxa)
    $taxa_iva = 0.23;
    $sem_iva = fn($v) => round($v / (1 + $taxa_iva), 2);
    $dif_total = round($total_valor - $total_corrigido, 2);
    $linhas_resumo = [
        ['Total Valor',                          $total_valor,     [44, 62, 80]],
        ['(-) Total V. Corrigido',               $total_corrigido, [211, 84, 0]],
        ['(=) Diferença (Valor - V. Corrigido)', $dif_total,       abs($dif_total) < 0.005 ? [39, 174, 96] : [192, 57, 43]],
    ];
    $lr = [72, 30, 30, 30]; // larguras: descrição | sem IVA | IVA | com IVA
    $larg_resumo = array_sum($lr);
    $xResumo = 287 - $larg_resumo;

    if ($pdf->GetY() + 34 > 195) { $pdf->AddPage(); }
    $pdf->Ln(5);
    $pdf->SetX($xResumo);
    $pdf->SetFont('Arial', 'B', 9);
    $pdf->SetFillColor(241, 242, 246);
    $pdf->Cell($lr[0], 7, pt(' RESUMO'), 1, 0, 'L', true);
    $pdf->Cell($lr[1], 7, pt('Sem IVA'), 1, 0, 'R', true);
    $pdf->Cell($lr[2], 7, pt('IVA (' . round($taxa_iva * 100) . '%)'), 1, 0, 'R', true);
    $pdf->Cell($lr[3], 7, pt('Com IVA'), 1, 1, 'R', true);

    foreach ($linhas_resumo as $k => $lin) {
        [$desc, $com, $cor] = $lin;
        $sem = $sem_iva($com);
        $iva = round($com - $sem, 2);
        $ultima = ($k === count($linhas_resumo) - 1);
        $pdf->SetX($xResumo);
        $pdf->SetFont('Arial', $ultima ? 'B' : '', $ultima ? 10 : 9);
        $pdf->SetTextColor(0, 0, 0);
        $pdf->Cell($lr[0], 7, pt(' ' . $desc), 1, 0, 'L');
        $pdf->SetTextColor($cor[0], $cor[1], $cor[2]);
        $pdf->Cell($lr[1], 7, pt(eur($sem) . ' '), 1, 0, 'R');
        $pdf->Cell($lr[2], 7, pt(eur($iva) . ' '), 1, 0, 'R');
        $pdf->Cell($lr[3], 7, pt(eur($com) . ' '), 1, 1, 'R');
    }
    $pdf->SetTextColor(120, 120, 120);
    $pdf->SetFont('Arial', 'I', 8);
    $pdf->SetX($xResumo);
    $pdf->Cell($larg_resumo, 5, pt('Os valores dos documentos incluem IVA a ' . round($taxa_iva * 100) . '%. Sem IVA = Com IVA / ' . number_format(1 + $taxa_iva, 2, ',', '') . '.'), 0, 1, 'L');
    $pdf->SetTextColor(0, 0, 0);
    if ($n_sem_corrigido > 0) {
        $pdf->SetX($xResumo);
        $pdf->SetFont('Arial', 'I', 8);
        $pdf->SetTextColor(120, 120, 120);
        $pdf->MultiCell($larg_resumo, 4, pt($n_sem_corrigido . ' documento(s) sem V. Corrigido (contam como 0 no total corrigido; na coluna Diferença aparecem com "-").'), 0, 'L');
        $pdf->SetTextColor(0, 0, 0);
    }

    if ($n_por_conferir > 0) {
        $pdf->Ln(3);
        $pdf->SetFont('Arial', 'B', 9);
        $pdf->SetTextColor(192, 57, 43);
        $pdf->Cell(0, 6, pt('Atenção: ' . $n_por_conferir . ' documento(s) ainda com V. Certo = Não.'), 0, 1, 'L');
        $pdf->SetTextColor(0, 0, 0);
    }
}

ob_end_clean();
$pdf->Output('I', 'LSJR_PDCT_' . date('Ymd') . '.pdf');
?>
