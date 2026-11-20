<?php
// FAME — Ficha de Aprovação de Material e Equipamento (PDF A4 horizontal)
// obra_fame_pdf.php?id=OBRA            → uma FAME por especialidade (cada uma começa numa página nova, com o seu número)
// obra_fame_pdf.php?id=OBRA&esp=ITED   → só essa especialidade
// Só as linhas marcadas para sair no PDF, pela ordem do ecrã. Coluna de parecer para a fiscalização / dono de obra.
ob_start();
session_start();
if (!isset($_SESSION['user_id'])) { ob_end_clean(); die("Acesso negado."); }

require_once 'db.php';
require_once 'obra_fame_comum.php';
require_once('fpdf/fpdf.php');

if (!fameTemAcesso($pdo)) { ob_end_clean(); die("Acesso negado."); }

$id_obra = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
// Links antigos (?fame=ID)
if (!$id_obra && ($id_f = filter_input(INPUT_GET, 'fame', FILTER_VALIDATE_INT))) {
    try { $st = $pdo->prepare("SELECT id_obra FROM obras_fames WHERE id = ?"); $st->execute([$id_f]); $id_obra = (int)$st->fetchColumn(); } catch (Exception $e) {}
}
if (!$id_obra) { ob_end_clean(); die("Erro: obra inválida."); }
$so_esp = trim((string)($_GET['esp'] ?? ''));

try {
    fameCriarTabelas($pdo);
    $obra = fameObra($pdo, $id_obra);
    if (!$obra) { ob_end_clean(); die("Erro: obra não encontrada."); }
    $orc = fameOrcamentoDaObra($pdo, $obra);
    $info = fameDaObra($pdo, $id_obra);
    $grupos = fameLinhasPorEspecialidade(fameLinhasDaFame($pdo, $info['id']), true);
    if ($so_esp !== '') $grupos = isset($grupos[$so_esp]) ? [$so_esp => $grupos[$so_esp]] : [];
} catch (Exception $e) {
    ob_end_clean(); die("Erro na BD: " . htmlspecialchars($e->getMessage()));
}

// Fichas técnicas da biblioteca que vão em anexo (pela ordem em que aparecem): [id_ficha => nº do anexo]
$bib = fameBiblioteca($pdo);
$anexos = fameAnexos($grupos, $bib);

// Lista das fichas em anexo (JSON) — usada pelo obra_fame_dossier.php para juntar tudo num só PDF
if (!empty($_GET['lista_fichas'])) {
    ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    $out = [];
    foreach ($anexos as $id_f => $n) {
        $f = $bib['id'][$id_f];
        $out[] = ['n' => $n, 'nome' => $f['nome'], 'referencia' => (string)$f['referencia'], 'url' => $f['url'],
                  'tipo' => strtolower(pathinfo((string)$f['ficheiro'], PATHINFO_EXTENSION))];
    }
    echo json_encode(['fichas' => $out], JSON_UNESCAPED_UNICODE);
    exit;
}

function pt($t) { return mb_convert_encoding((string)($t ?? ''), 'windows-1252', 'UTF-8'); }

$ref = $obra['referencia_obra'] ?: ($orc['numero_orcamento'] ?? (string)$id_obra);
$rev = (int)$info['revisao'];

class PDF extends FPDF {
    public $num_doc = '';
    public $secao = 0;          // nº da FAME (especialidade) que está a ser escrita
    public $inicio_secao = 1;   // página onde começou
    public $paginas_secao = []; // [secao => nº de páginas]
    public $proxima = null;     // FAME que começa na próxima página (aplicada no Header, depois do rodapé da página anterior)
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
        if ($this->proxima !== null) {
            $this->secao++;
            $this->num_doc = $this->proxima;
            $this->inicio_secao = $this->PageNo();
            $this->proxima = null;
        }
        if (file_exists('mab.png')) $this->Image('mab.png', 10, 9, 18);
        $this->SetY(10); $this->SetFont('Arial', 'B', 13); $this->SetTextColor(44, 62, 80);
        $this->Cell(0, 7, pt('FICHA DE APROVAÇÃO DE MATERIAL E EQUIPAMENTO'), 0, 1, 'R');
        $this->SetFont('Arial', 'B', 10); $this->SetTextColor(39, 174, 96);
        $this->Cell(0, 6, pt($this->num_doc), 0, 1, 'R');
        $this->Ln(3); $y = $this->GetY(); $this->SetDrawColor(39, 174, 96); $this->SetLineWidth(0.5);
        $this->Line(10, $y, 287, $y); $this->Ln(3);
        $this->SetTextColor(0, 0, 0); $this->SetLineWidth(0.2);
    }
    function Footer() {
        $this->SetY(-13); $this->SetDrawColor(200, 200, 200); $this->SetLineWidth(0.2);
        $this->Line(10, $this->GetY(), 287, $this->GetY()); $this->Ln(1.5);
        $this->SetFont('Arial', 'B', 7.5); $this->SetTextColor(100, 100, 100);
        $y = $this->GetY();
        $this->Cell(0, 4, pt($this->num_doc), 0, 0, 'L');
        $this->SetXY(10, $y); $this->Cell(0, 4, pt('m. Abreu - Instalações Técnicas, Lda. | NIF: 514 414 120 | Rua Damião de Góis, 205, Braga | geral@mabreu.pt'), 0, 0, 'C');
        // Páginas contadas por FAME (cada especialidade tem a sua numeração)
        $this->SetXY(10, $y); $this->Cell(0, 4, pt('Página ') . ($this->PageNo() - $this->inicio_secao + 1) . '/{tot' . $this->secao . '}', 0, 0, 'R');
        $this->paginas_secao[$this->secao] = $this->PageNo() - $this->inicio_secao + 1;
    }
    // Começa uma FAME nova (nova especialidade) numa página nova
    function NovaSecao($num_doc) {
        $this->proxima = $num_doc;
        $this->AddPage();
    }
    // Troca os totais de páginas de cada FAME ao gerar o ficheiro (depois do rodapé da última página)
    function _putpages() {   // público de propósito: funciona com FPDF antigo e novo
        for ($p = 1; $p <= $this->page; $p++) {
            foreach ($this->paginas_secao as $s => $n) $this->pages[$p] = str_replace('{tot' . $s . '}', (string)$n, $this->pages[$p]);
        }
        parent::_putpages();
    }
}

$pdf = new PDF('L', 'mm', 'A4');
$pdf->SetAutoPageBreak(false);

// Uma FAME por especialidade
$escreverFame = function ($esp, $linhas_s) use ($pdf, $obra, $info, $ref, $rev, $bib, $anexos) {
    $pdf->NovaSecao('FAME ' . $ref . '-' . fameSigla($esp) . ' - Rev. ' . str_pad((string)$rev, 2, '0', STR_PAD_LEFT));
    // --- IDENTIFICAÇÃO ---
    $campo = function ($rot, $val, $w_rot, $w_val, $ln = 0) use ($pdf) {
        $pdf->SetFont('Arial', 'B', 8.5); $pdf->SetFillColor(245, 247, 250);
        $pdf->Cell($w_rot, 6.5, pt($rot), 1, 0, 'L', true);
        $pdf->SetFont('Arial', '', 8.5);
        $v = pt($val);
        while ($pdf->GetStringWidth($v) > $w_val - 2 && strlen($v) > 3) $v = substr($v, 0, -4) . '...';
        $pdf->Cell($w_val, 6.5, $v, 1, $ln, 'L');
    };
    $pdf->SetDrawColor(205, 210, 216);
    $campo('Obra', $obra['nome'] ?? '-', 36, 142);
    $campo('Nº Obra', $ref, 30, 69, 1);
    $campo('Cliente / Dono de obra', $obra['cliente_nome'] ?? '-', 36, 142);
    $campo('Data', !empty($info['data']) ? date('d/m/Y', strtotime($info['data'])) : date('d/m/Y'), 30, 69, 1);
    $campo('Local', trim(preg_replace('/\s+/', ' ', (string)($obra['morada'] ?? ''))) ?: '-', 36, 142);
    $campo('Revisão', str_pad((string)$rev, 2, '0', STR_PAD_LEFT), 30, 69, 1);
    $campo('Empreiteiro', 'm. Abreu - Instalações Técnicas, Lda.', 36, 142);
    $campo('Para aprovação de', $info['destinatario'] ?: '-', 30, 69, 1);
    $campo('Especialidade', $esp, 36, 142);
    $campo('Nº de artigos', (string)count($linhas_s), 30, 69, 1);
    $pdf->Ln(4);

    // --- TABELA ---
    $w = [9, 16, 62, 10, 11, 14, 33, 34, 50, 38];   // Nº | Ref./Art. | Designação | Alt. | Un. | Qtd. | Fabricante | Referência | Ficha técnica | Parecer  = 277
    $cab = function () use ($pdf, $w) {
        $pdf->SetFont('Arial', 'B', 7.5); $pdf->SetFillColor(44, 62, 80); $pdf->SetTextColor(255, 255, 255); $pdf->SetDrawColor(44, 62, 80);
        $tit = ['Nº', 'Ref./Art.', 'Designação', 'Alt.¹', 'Un.', 'Qtd.', 'Fabricante', 'Referência', 'Ficha técnica', 'Parecer (A / AR / NA)'];
        foreach ($tit as $i => $t) $pdf->Cell($w[$i], 7, pt($t), 1, 0, in_array($i, [1, 2, 6, 7, 8]) ? 'L' : 'C', true);
        $pdf->Ln();
        $pdf->SetTextColor(0, 0, 0); $pdf->SetDrawColor(205, 210, 216);
    };

    if (!$linhas_s) {
        $pdf->SetFont('Arial', '', 10); $pdf->SetTextColor(120, 120, 120);
        $pdf->Cell(0, 12, pt('Sem material nesta especialidade.'), 0, 1, 'C');
    } else {
        $cab();
        $lh = 4.2; $n = 0;
        foreach ($linhas_s as $l) {
            $n++;
            $pdf->SetFont('Arial', '', 8);
            // Ficha da biblioteca: "Anexo N - nome" (a ficha segue no fim do PDF completo)
            $id_fic = fameResolverFicha($bib, $l['id_ficha_tecnica'] ?? 0, $l['ficha_tecnica'] ?? '');
            $txt_fic = (string)$l['ficha_tecnica'];
            if ($id_fic && isset($anexos[$id_fic])) $txt_fic = 'Anexo ' . $anexos[$id_fic] . ' - ' . ($txt_fic !== '' ? $txt_fic : $bib['id'][$id_fic]['nome']);
            $cols = [1 => pt($l['artigo'] ?? ''), 2 => pt($l['designacao']), 6 => pt($l['fabricante']), 7 => pt($l['referencia']), 8 => pt($txt_fic)];
            $nl = 1; foreach ($cols as $i => $t) $nl = max($nl, $pdf->NbLines($w[$i], $t));
            $h = max(8, $nl * $lh + 2.5);
            if ($pdf->GetY() + $h > 190) { $pdf->AddPage(); $cab(); $pdf->SetFont('Arial', '', 8); }

            $x = 10; $y = $pdf->GetY();
            if ($n % 2 == 0) { $pdf->SetFillColor(249, 250, 252); $pdf->Rect($x, $y, 277, $h, 'F'); }
            $cx = $x;
            foreach ($w as $i => $wi) { $pdf->Rect($cx, $y, $wi, $h); $cx += $wi; }

            $cx = $x;
            $pdf->SetXY($cx, $y); $pdf->Cell($w[0], $h, $n, 0, 0, 'C'); $cx += $w[0];
            $pdf->SetXY($cx, $y + 1.2); $pdf->MultiCell($w[1], $lh, $cols[1], 0, 'L'); $cx += $w[1];
            $pdf->SetXY($cx, $y + 1.2); $pdf->MultiCell($w[2], $lh, $cols[2], 0, 'L'); $cx += $w[2];
            // Alternativa: "Sim" a laranja
            if (!empty($l['alternativa'])) {
                $pdf->SetFont('Arial', 'B', 7.5); $pdf->SetTextColor(211, 84, 0);
                $pdf->SetXY($cx, $y); $pdf->Cell($w[3], $h, 'Sim', 0, 0, 'C');
                $pdf->SetTextColor(0, 0, 0); $pdf->SetFont('Arial', '', 8);
            }
            $cx += $w[3];
            $pdf->SetXY($cx, $y); $pdf->Cell($w[4], $h, pt($l['unidade']), 0, 0, 'C'); $cx += $w[4];
            $pdf->SetFont('Arial', 'B', 8);
            $pdf->SetXY($cx, $y); $pdf->Cell($w[5], $h, fameQtd($l['quantidade']), 0, 0, 'C'); $cx += $w[5];
            $pdf->SetFont('Arial', '', 8);
            foreach ([6, 7, 8] as $i) { $pdf->SetXY($cx, $y + 1.2); $pdf->MultiCell($w[$i], $lh, $cols[$i], 0, 'L'); $cx += $w[$i]; }
            // Parecer: três quadrados A / AR / NA
            $pdf->SetFont('Arial', '', 7); $pdf->SetDrawColor(120, 120, 120);
            $bx = $cx + 3; $by = $y + ($h - 3.5) / 2;
            foreach (['A', 'AR', 'NA'] as $op) {
                $pdf->Rect($bx, $by, 3.5, 3.5); $pdf->SetXY($bx + 4, $by - 0.3); $pdf->Cell(7, 4, $op, 0, 0, 'L');
                $bx += 11.5;
            }
            $pdf->SetDrawColor(205, 210, 216);
            $pdf->SetY($y + $h);
        }
    }
};

// --- NO FIM DO DOCUMENTO (uma vez só): legenda, observações, parecer geral e assinaturas ---
$escreverFecho = function ($n_artigos, $n_alt, $n_fames) use ($pdf, $info, $anexos) {
    if ($pdf->GetY() + 12 > 190) $pdf->AddPage();
    $pdf->Ln(1.5);
    $pdf->SetFont('Arial', 'I', 7.5); $pdf->SetTextColor(90, 90, 90);
    $pdf->Cell(0, 4, pt('A = Aprovado    AR = Aprovado com reservas    NA = Não aprovado    ·    ' . $n_artigos . ' artigo(s)' . ($n_fames > 1 ? ' em ' . $n_fames . ' FAMEs' : '') . ($n_alt ? ', dos quais ' . $n_alt . ' em alternativa' : '') . '. As fichas técnicas seguem em anexo' . (count($anexos) ? ' (' . count($anexos) . ' anexo' . (count($anexos) > 1 ? 's' : '') . ').' : '.')), 0, 1, 'L');
    $pdf->Cell(0, 4, pt('¹ Alt. = material proposto em alternativa ao especificado no projeto / caderno de encargos.'), 0, 1, 'L');
    $pdf->SetTextColor(0, 0, 0);

    // --- OBSERVAÇÕES + ASSINATURAS ---
    $obs = trim((string)($info['observacoes'] ?? ''));
    $h_obs = 0;
    if ($obs !== '') { $pdf->SetFont('Arial', '', 8.5); $h_obs = $pdf->NbLines(277, pt($obs)) * 4.5 + 8; }
    if ($pdf->GetY() + $h_obs + 48 > 195) { $pdf->AddPage(); }

    if ($obs !== '') {
        $pdf->Ln(3);
        $pdf->SetFont('Arial', 'B', 8.5); $pdf->Cell(0, 5, pt('Observações'), 0, 1);
        $pdf->SetFont('Arial', '', 8.5); $pdf->MultiCell(277, 4.5, pt($obs), 1, 'L');
    }

    $pdf->Ln(5);
    $pdf->SetFont('Arial', 'B', 8.5); $pdf->Cell(0, 5, pt('Parecer geral e comentários da fiscalização / dono de obra'), 0, 1);
    $pdf->Rect(10, $pdf->GetY(), 277, 14); $pdf->Ln(17);

    $y = $pdf->GetY();
    $blocos = [['Elaborado por (Empreiteiro)', 10], ['Verificado por', 103], ['Aprovado por (Fiscalização / Dono de obra)', 196]];
    foreach ($blocos as [$tit, $bx]) {
        $pdf->SetXY($bx, $y); $pdf->SetFont('Arial', 'B', 8); $pdf->Cell(91, 4, pt($tit), 0, 0, 'L');
        $pdf->SetDrawColor(150, 150, 150); $pdf->Line($bx, $y + 14, $bx + 85, $y + 14);
        $pdf->SetXY($bx, $y + 15); $pdf->SetFont('Arial', '', 7.5); $pdf->Cell(85, 4, pt('Nome / Assinatura                                   Data: ____/____/______'), 0, 0, 'L');
    }
};

if (!$grupos) {
    $pdf->NovaSecao('FAME ' . $ref . ' - Rev. ' . str_pad((string)$rev, 2, '0', STR_PAD_LEFT));
    $pdf->SetFont('Arial', '', 11); $pdf->SetTextColor(120, 120, 120);
    $pdf->Cell(0, 20, pt('Sem linhas na FAME desta obra' . ($so_esp !== '' ? ' para a especialidade ' . $so_esp : '') . '.'), 0, 1, 'C');
} else {
    foreach ($grupos as $esp => $linhas_s) $escreverFame($esp, $linhas_s);
    // Totais do documento: cada linha conta uma vez, mesmo que saia em várias FAMEs
    $unicas = [];
    foreach ($grupos as $linhas_s) foreach ($linhas_s as $l) $unicas[$l['id']] = !empty($l['alternativa']);
    $escreverFecho(count($unicas), count(array_filter($unicas)), count($grupos));
}

ob_end_clean();
$nome = 'FAME_' . preg_replace('/[^A-Za-z0-9_-]/', '_', $ref . ($so_esp !== '' ? '-' . fameSigla($so_esp) : '')) . '_Rev' . $rev . '.pdf';
$pdf->Output('I', $nome);
