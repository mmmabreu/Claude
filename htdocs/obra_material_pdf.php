<?php
// MATERIAL A APROVISIONAR — por obra (A4 horizontal)
// Fonte: material da FAME da obra. Uma tabela com Especialidade, Designação, Fabricante, Referência, Un., Qtd.,
// Qtd. fornecida, Diferença (Qtd. - fornecida), Nº documento e Data do documento,
// por ordem de especialidade e, dentro de cada uma, por ordem alfabética.
// O fornecimento é preenchido em obra_material.php (tabela obras_material_fornecido).
// Se a FAME ainda não tiver linhas: material do orçamento (fabricante / referência da memória, se houver). Sem preços.
ob_start();
session_start();
if (!isset($_SESSION['user_id'])) { ob_end_clean(); die("Acesso negado."); }

require_once 'db.php';
require_once 'obra_fame_comum.php';
require_once('fpdf/fpdf.php');

if (!fameTemAcesso($pdo)) { ob_end_clean(); die("Acesso negado."); }

$id_obra = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$id_obra) { ob_end_clean(); die("Erro: obra inválida."); }

try {
    $obra = fameObra($pdo, $id_obra);
    if (!$obra) { ob_end_clean(); die("Erro: obra não encontrada."); }
    $orc = fameOrcamentoDaObra($pdo, $obra);
    fameCriarTabelas($pdo);
    $materiais = fameMaterialAprovisionar($pdo, $id_obra, $orc['id'] ?? 0);
    // Fornecimentos (preenchidos na página do material a aprovisionar)
    $fornecido = [];
    try {
        $st_f = $pdo->prepare("SELECT * FROM obras_material_fornecido WHERE id_obra = ?");
        $st_f->execute([$id_obra]);
        foreach ($st_f->fetchAll(PDO::FETCH_ASSOC) as $r) $fornecido[$r['chave']] = $r;
    } catch (Exception $e2) {}   // tabela ainda não criada: sem fornecimentos
} catch (Exception $e) {
    ob_end_clean(); die("Erro na BD: " . htmlspecialchars($e->getMessage()));
}

function pt($t) { return mb_convert_encoding((string)($t ?? ''), 'windows-1252', 'UTF-8'); }
// Mesma chave que obra_material.php (designação|unidade|fabricante|referência)
if (!function_exists('chaveFornecido')) {
    function chaveFornecido($m) {
        return mb_substr(mb_strtolower(fameChave($m['designacao'], $m['unidade']) . '|' . trim((string)($m['fabricante'] ?? '')) . '|' . trim((string)($m['referencia'] ?? '')), 'UTF-8'), 0, 191);
    }
}

$ref = $obra['referencia_obra'] ?: ($orc['numero_orcamento'] ?? (string)$id_obra);

class PDF extends FPDF {
    public $titulo_doc;
    public $ref_doc;
    function NbLines($w, $txt) {
        $cw = &$this->CurrentFont['cw']; if ($w == 0) $w = $this->w - $this->rMargin - $this->x;
        $wmax = ($w - 2 * $this->cMargin) * 1000 / $this->FontSize;
        $s = str_replace("\r", '', $txt); $nb = strlen($s);
        if ($nb > 0 && $s[$nb - 1] == "\n") $nb--;
        $i = 0; $j = 0; $l = 0; $nl = 1; $sep = -1;
        while ($i < $nb) {
            $c = $s[$i];
            if ($c == "\n") { $i++; $sep = -1; $j = $i; $l = 0; $nl++; continue; }
            if ($c == ' ') $sep = $i;
            $l += $cw[$c];
            if ($l > $wmax) { if ($sep == -1) { if ($i == $j) $i++; } else $i = $sep + 1; $sep = -1; $j = $i; $l = 0; $nl++; } else $i++;
        }
        return $nl;
    }
    function Header() {
        if (file_exists('mab.png')) $this->Image('mab.png', 10, 10, 20);
        $this->SetY(12); $this->SetFont('Arial', 'B', 13); $this->SetTextColor(39, 174, 96);
        $this->Cell(0, 8, pt($this->titulo_doc), 0, 1, 'R');
        $this->SetFont('Arial', '', 8); $this->SetTextColor(120, 120, 120);
        $this->Cell(0, 4, pt('Documento interno · impresso em ' . date('d/m/Y H:i')), 0, 1, 'R');
        $this->Ln(6); $y = $this->GetY(); $this->SetDrawColor(39, 174, 96); $this->SetLineWidth(0.5);
        $this->Line(10, $y, 287, $y); $this->Ln(4);
        $this->SetTextColor(0, 0, 0);
    }
    function Footer() {
        $this->SetY(-15); $this->SetDrawColor(39, 174, 96); $this->SetLineWidth(0.5);
        $this->Line(10, $this->GetY(), 287, $this->GetY()); $this->Ln(2); $this->SetFont('Arial', '', 8);
        $this->Cell(0, 4, pt('Material a aprovisionar · Obra Nº ' . $this->ref_doc), 0, 0, 'L');
        $this->SetX(10); $this->Cell(0, 4, pt('Página ') . $this->PageNo() . '/{nb}', 0, 1, 'R');
    }
}

$pdf = new PDF('L', 'mm', 'A4');
$pdf->titulo_doc = 'MATERIAL A APROVISIONAR Nº ' . $ref;
$pdf->ref_doc = $ref;
$pdf->AliasNbPages();
$pdf->SetAutoPageBreak(false);
$pdf->AddPage();

// --- DADOS DA OBRA ---
$pdf->SetFont('Arial', 'B', 9); $pdf->Cell(18, 5, pt('Cliente:'), 0, 0);
$pdf->SetFont('Arial', '', 9);  $pdf->Cell(197, 5, pt($obra['cliente_nome'] ?? '-'), 0, 0);
$pdf->SetFont('Arial', 'B', 9); $pdf->Cell(30, 5, pt('Estado da obra:'), 0, 0, 'R');
$pdf->SetFont('Arial', '', 9);  $pdf->Cell(32, 5, pt($obra['estado'] ?? '-'), 0, 1, 'R');
$pdf->SetFont('Arial', 'B', 9); $pdf->Cell(18, 5, pt('Obra:'), 0, 0);
$pdf->SetFont('Arial', '', 9);  $pdf->Cell(197, 5, pt($obra['nome'] ?? '-'), 0, 0);
$pdf->SetFont('Arial', 'B', 9); $pdf->Cell(30, 5, pt('Orçamento:'), 0, 0, 'R');
$pdf->SetFont('Arial', '', 9);  $pdf->Cell(32, 5, pt($orc['numero_orcamento'] ?? '-'), 0, 1, 'R');
$pdf->SetFont('Arial', 'B', 9); $pdf->Cell(18, 5, pt('Morada:'), 0, 0);
$pdf->SetFont('Arial', '', 9);  $pdf->Cell(259, 5, pt(trim(preg_replace('/\s+/', ' ', (string)($obra['morada'] ?? ''))) ?: '-'), 0, 1);
$pdf->Ln(4);

// --- TABELA (uma só, por ordem de especialidade) ---
// ☐ | Especialidade | Designação | Fabricante | Referência | Un. | Qtd. | Qtd. forn. | Diferença | Nº documento | Data doc.  = 277
$w = [7, 27, 70, 28, 32, 10, 14, 16, 16, 37, 20];
$cab = function () use ($pdf, $w) {
    $pdf->SetFont('Arial', 'B', 7.5); $pdf->SetFillColor(245, 245, 245); $pdf->SetDrawColor(200, 200, 200); $pdf->SetLineWidth(0.2);
    $tit = ['', 'Especialidade', 'Designação', 'Fabricante', 'Referência', 'Un.', 'Qtd.', 'Qtd. forn.', 'Diferença¹', 'Nº documento', 'Data doc.'];
    foreach ($tit as $i => $t) {
        if ($i >= 7) $pdf->SetFillColor(232, 242, 251);   // parte do fornecimento
        $pdf->Cell($w[$i], 7, pt($t), 'T,B', $i === count($tit) - 1 ? 1 : 0, in_array($i, [1, 2, 3, 4, 9]) ? 'L' : 'C', true);
    }
};

$n_artigos = 0; $n_ok = 0; $n_parc = 0; $n_mais = 0;
if (empty($materiais)) {
    $pdf->SetFont('Arial', '', 10); $pdf->SetTextColor(120, 120, 120);
    $pdf->MultiCell(0, 6, pt('Sem material: a FAME da obra não tem linhas e o orçamento não tem linhas de material no Detalhe Interno.'), 0, 'C');
} else {
    // Resumo por especialidade
    $resumo = [];
    foreach ($materiais as $esp => $lista) $resumo[] = $esp . ' (' . count($lista) . ')';
    $pdf->SetFont('Arial', '', 8); $pdf->SetTextColor(90, 90, 90);
    $pdf->MultiCell(0, 4.5, pt('Especialidades: ' . implode(' · ', $resumo)), 0, 'L');
    $pdf->SetTextColor(0, 0, 0);
    $pdf->Ln(2);

    $cab();
    $lh = 4.3;
    $n = 0;
    foreach ($materiais as $esp => $lista) {
        $primeira = true;
        foreach ($lista as $m) {
            $n++; $n_artigos++;
            $f = $fornecido[chaveFornecido($m)] ?? [];
            $qf = (isset($f['qtd_fornecida']) && $f['qtd_fornecida'] !== null) ? (float)$f['qtd_fornecida'] : null;
            $q = (float)$m['quantidade'];
            $dif = $q - (float)($qf ?? 0);                 // > 0 em falta; < 0 fornecido a mais
            $completo = ($qf !== null && $qf > 0 && $dif <= 0.0001);
            if ($completo) { $n_ok++; if ($dif < -0.0001) $n_mais++; } elseif ($qf !== null && $qf > 0) $n_parc++;

            $pdf->SetFont('Arial', '', 8.5);
            $desig = pt($m['designacao']);
            $fab = pt($m['fabricante'] ?? '');
            $ref = pt($m['referencia'] ?? '');
            $doc = pt($f['num_documento'] ?? '');
            $t_esp = pt($m['especialidade'] ?? $esp);   // "Elétrico + ITED" quando a linha é das duas
            $nl = max(1, $pdf->NbLines($w[2], $desig), $pdf->NbLines($w[3], $fab), $pdf->NbLines($w[4], $ref), $pdf->NbLines($w[9], $doc));
            $pdf->SetFont('Arial', 'B', 7.5);
            $nl = max($nl, $pdf->NbLines($w[1], $t_esp));
            $h = $lh * $nl + 3;
            $nova_pag = false;
            if ($pdf->GetY() + $h > 190) { $pdf->AddPage(); $cab(); $nova_pag = true; }

            $x = 10; $y = $pdf->GetY();
            if ($primeira && $n > 1 && !$nova_pag) {   // separação entre especialidades
                $pdf->SetDrawColor(39, 174, 96); $pdf->SetLineWidth(0.4); $pdf->Line(10, $y, 287, $y); $pdf->SetLineWidth(0.2);
            }
            if ($n % 2 == 0) { $pdf->SetFillColor(250, 250, 250); $pdf->Rect($x, $y + 0.2, 277, $h - 0.2, 'F'); }
            $xf = $x + array_sum(array_slice($w, 0, 7));
            $pdf->SetFillColor(244, 249, 254); $pdf->Rect($xf, $y + 0.2, 277 - ($xf - $x), $h - 0.2, 'F');
            // Quadrado "aprovisionado": já marcado (X) quando foi fornecido tudo
            $bx = $x + 1.5; $by = $y + ($h - 4) / 2;
            $pdf->SetDrawColor(150, 150, 150); $pdf->Rect($bx, $by, 4, 4);
            if ($completo) { $pdf->SetDrawColor(39, 174, 96); $pdf->SetLineWidth(0.4); $pdf->Line($bx + 0.8, $by + 0.8, $bx + 3.2, $by + 3.2); $pdf->Line($bx + 3.2, $by + 0.8, $bx + 0.8, $by + 3.2); $pdf->SetLineWidth(0.2); }

            $cx = $x + $w[0];
            // Especialidade: forte na 1.ª linha do grupo (e no topo de cada página), clara nas seguintes
            $pdf->SetFont('Arial', 'B', 7.5);
            if ($primeira || $nova_pag) $pdf->SetTextColor(22, 160, 133); else $pdf->SetTextColor(170, 170, 170);
            $pdf->SetXY($cx, $y + 1.5); $pdf->MultiCell($w[1], $lh, $t_esp, 0, 'L'); $cx += $w[1];
            $pdf->SetTextColor(0, 0, 0);

            $pdf->SetFont('Arial', '', 8.5);
            $pdf->SetXY($cx, $y + 1.5); $pdf->MultiCell($w[2], $lh, $desig, 0, 'L'); $cx += $w[2];
            $pdf->SetXY($cx, $y + 1.5); $pdf->MultiCell($w[3], $lh, $fab !== '' ? $fab : '-', 0, 'L'); $cx += $w[3];
            $pdf->SetXY($cx, $y + 1.5); $pdf->MultiCell($w[4], $lh, $ref !== '' ? $ref : '-', 0, 'L'); $cx += $w[4];
            $pdf->SetXY($cx, $y + 1.5); $pdf->Cell($w[5], $lh, pt($m['unidade']), 0, 0, 'C'); $cx += $w[5];
            $pdf->SetFont('Arial', 'B', 9.5);
            $pdf->SetXY($cx, $y + 1.5); $pdf->Cell($w[6], $lh, fameQtd($q), 0, 0, 'C'); $cx += $w[6];

            // Fornecimento
            $pdf->SetFont('Arial', 'B', 9);
            $pdf->SetXY($cx, $y + 1.5); $pdf->Cell($w[7], $lh, $qf !== null ? fameQtd($qf) : '-', 0, 0, 'C'); $cx += $w[7];
            // Diferença: verde = 0 (completo), laranja = em falta, azul = a mais
            if (abs($dif) < 0.0001)      { $pdf->SetTextColor(39, 174, 96);  $txt_dif = '0'; }
            elseif ($dif > 0)           { $pdf->SetTextColor(211, 84, 0);   $txt_dif = fameQtd($dif); }
            else                        { $pdf->SetTextColor(41, 128, 185); $txt_dif = '+' . fameQtd(-$dif); }
            $pdf->SetXY($cx, $y + 1.5); $pdf->Cell($w[8], $lh, $txt_dif, 0, 0, 'C'); $cx += $w[8];
            $pdf->SetTextColor(0, 0, 0);
            $pdf->SetFont('Arial', '', 8);
            $pdf->SetXY($cx, $y + 1.5); $pdf->MultiCell($w[9], $lh, $doc !== '' ? $doc : '-', 0, 'L'); $cx += $w[9];
            $pdf->SetXY($cx, $y + 1.5); $pdf->Cell($w[10], $lh, !empty($f['data_documento']) ? date('d/m/Y', strtotime($f['data_documento'])) : '-', 0, 0, 'C');

            $pdf->SetY($y + $h);
            $pdf->SetDrawColor(225, 225, 225); $pdf->Line(10, $pdf->GetY(), 287, $pdf->GetY());
            $primeira = false;
        }
    }

    if ($pdf->GetY() + 34 > 195) $pdf->AddPage();
    $pdf->Ln(1.5);
    $pdf->SetFont('Arial', 'I', 7.5); $pdf->SetTextColor(90, 90, 90);
    $pdf->Cell(0, 4, pt('¹ Diferença = Qtd. a aprovisionar - Qtd. fornecida:  0 = fornecido por completo · valor a laranja = em falta · "+" a azul = fornecido a mais.'), 0, 1, 'L');
    $pdf->SetTextColor(0, 0, 0);
    $pdf->Ln(2);
    $pdf->SetFont('Arial', '', 9);
    $n_falta = $n_artigos - $n_ok - $n_parc;
    $pdf->Cell(0, 6, pt($n_artigos . ' artigo(s) a aprovisionar  ·  ' . $n_ok . ' fornecido(s) por completo' . ($n_mais ? ' (' . $n_mais . ' a mais)' : '') . '  ·  ' . $n_parc . ' parcial(ais)  ·  ' . $n_falta . ' por fornecer'), 0, 1, 'L');

    // Assinaturas
    $pdf->Ln(8);
    $pdf->SetFont('Arial', '', 8); $pdf->SetDrawColor(150, 150, 150);
    $yS = $pdf->GetY();
    $pdf->Line(10, $yS + 8, 110, $yS + 8);  $pdf->SetXY(10, $yS + 9);  $pdf->Cell(100, 4, pt('Encomendado por / data'), 0, 0, 'C');
    $pdf->Line(187, $yS + 8, 287, $yS + 8); $pdf->SetXY(187, $yS + 9); $pdf->Cell(100, 4, pt('Recebido em obra por / data'), 0, 0, 'C');
}

ob_end_clean();
$pdf->Output('I', 'Material_Obra_' . preg_replace('/[^A-Za-z0-9_-]/', '_', $ref) . '.pdf');
