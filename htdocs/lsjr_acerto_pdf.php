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

$id_acerto = (int)($_GET['id'] ?? 0);
if ($id_acerto <= 0) {
    ob_end_clean();
    die("Acerto inválido.");
}

function tabelaExiste($pdo, $tabela) {
    try {
        return (bool)$pdo->query("SHOW TABLES LIKE " . $pdo->quote($tabela))->fetchColumn();
    } catch (Exception $e) {
        return false;
    }
}

// --- BUSCAR O ACERTO E OS SEUS DOCUMENTOS ---
try {
    $st = $pdo->prepare("SELECT * FROM lsjr_acertos WHERE id = ?");
    $st->execute([$id_acerto]);
    $acerto = $st->fetch(PDO::FETCH_ASSOC);
    if (!$acerto) {
        ob_end_clean();
        die("Acerto não encontrado.");
    }

    $docs_mab = [];
    $docs_lsjr = [];
    $docs_pdct = [];

    if (tabelaExiste($pdo, 'lsjr_fatmab')) {
        $st = $pdo->prepare("SELECT * FROM lsjr_fatmab WHERE id_acerto = ? AND estado = 'Acertado'
                             ORDER BY (data_emissao IS NULL), data_emissao ASC, tipo_doc ASC, numero_documento ASC, id ASC");
        $st->execute([$id_acerto]);
        $docs_mab = $st->fetchAll(PDO::FETCH_ASSOC);
    }
    if (tabelaExiste($pdo, 'lsjr_fatfav')) {
        $st = $pdo->prepare("SELECT * FROM lsjr_fatfav WHERE id_acerto = ? AND estado = 'Acertado'
                             ORDER BY (data_emissao IS NULL), data_emissao ASC, tipo_doc ASC, numero_documento ASC, id ASC");
        $st->execute([$id_acerto]);
        $docs_lsjr = $st->fetchAll(PDO::FETCH_ASSOC);
    }
    if (tabelaExiste($pdo, 'lsjr_pdct')) {
        $st = $pdo->prepare("SELECT * FROM lsjr_pdct WHERE id_acerto = ? AND estado = 'Acertado'
                             ORDER BY (data_registo IS NULL), data_registo ASC, pdct ASC, id ASC");
        $st->execute([$id_acerto]);
        $docs_pdct = $st->fetchAll(PDO::FETCH_ASSOC);
    }

    // Linha de saldo de abertura gerada por este acerto (no acerto seguinte)
    $abertura = null;
    if (!empty($acerto['abertura_id']) && !empty($acerto['abertura_lado'])) {
        $tabela_ab = $acerto['abertura_lado'] === 'MAB' ? 'lsjr_fatmab' : 'lsjr_fatfav';
        if (tabelaExiste($pdo, $tabela_ab)) {
            $st = $pdo->prepare("SELECT * FROM $tabela_ab WHERE id = ?");
            $st->execute([$acerto['abertura_id']]);
            $abertura = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        }
    }
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
    // Larguras das colunas dos documentos (A4 horizontal: 277 mm úteis)
    public $larg = [12, 18, 45, 25, 24, 24, 30, 99];
    public $cab = ['Secção', 'Doc.', 'Nº Documento', 'Nº Externo', 'Emissão', 'Vencimento', 'Valor', 'Observações'];
    // Larguras das colunas dos PDCT
    public $larg_pdct = [12, 24, 45, 30, 30, 18, 118];
    public $cab_pdct = ['Secção', 'Data', 'PDCT', 'Valor', 'V. Corrigido', 'V. Certo', 'Observações'];

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
        $this->SetTextColor(0, 0, 0);
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

    // Corta o texto para caber na largura da célula (acrescenta "...")
    function Cortar($texto, $largura) {
        $texto = pt($texto);
        if ($this->GetStringWidth($texto) <= $largura - 2) return $texto;
        while ($texto !== '' && $this->GetStringWidth($texto . '...') > $largura - 2) {
            $texto = substr($texto, 0, -1);
        }
        return $texto . '...';
    }

    // Garante espaço na página (o PageBreakTrigger do FPDF é protegido)
    function Espaco($altura) {
        if ($this->GetY() + $altura > $this->PageBreakTrigger) { $this->AddPage(); }
    }

    function CorValor($v) {
        if ($v < 0) $this->SetTextColor(192, 57, 43);
        else $this->SetTextColor(0, 0, 0);
    }

    // Título de secção (com cor própria)
    function TituloSeccao($texto, $cor, $info = '') {
        if ($this->GetY() + 22 > $this->PageBreakTrigger) { $this->AddPage(); }
        $this->SetFont('Arial', 'B', 11);
        $this->SetTextColor($cor[0], $cor[1], $cor[2]);
        $this->Cell(0, 7, pt($texto), 0, 0, 'L');
        if ($info !== '') {
            $this->SetX(10);
            $this->SetFont('Arial', 'I', 8);
            $this->SetTextColor(120, 120, 120);
            $this->Cell(0, 7, pt($info), 0, 0, 'R');
        }
        $this->Ln(7);
        $this->SetDrawColor($cor[0], $cor[1], $cor[2]);
        $this->SetLineWidth(0.4);
        $this->Line(10, $this->GetY(), 287, $this->GetY());
        $this->Ln(1.5);
        $this->SetTextColor(0, 0, 0);
    }

    function CabecalhoTabela($cab, $larg, $col_dir) {
        $this->SetFont('Arial', 'B', 8);
        $this->SetFillColor(241, 242, 246);
        $this->SetTextColor(87, 101, 116);
        $this->SetDrawColor(220, 220, 220);
        $this->SetLineWidth(0.2);
        $ultimo = count($cab) - 1;
        foreach ($cab as $i => $c) {
            $al = in_array($i, $col_dir) ? 'R' : (($i === 2 || $i === $ultimo) ? 'L' : 'C');
            $this->Cell($larg[$i], 7, pt($c), 1, 0, $al, true);
        }
        $this->Ln();
        $this->SetTextColor(0, 0, 0);
    }

    function LinhaDoc($r, $fundo) {
        if ($this->GetY() + 6 > $this->PageBreakTrigger) {
            $this->AddPage();
            $this->CabecalhoTabela($this->cab, $this->larg, [6]);
        }
        $v = (float)$r['valor_original'];
        $abert = (($r['origem'] ?? '') === 'Abertura');

        $this->SetFillColor($abert ? 254 : 250, $abert ? 249 : 251, $abert ? 231 : 252);
        $fundo = $fundo || $abert;
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
        $this->CorValor($v);
        $this->Cell($this->larg[6], 6, pt(eur($v)), 1, 0, 'R', $fundo);
        $this->SetTextColor(0, 0, 0);
        $obs = $r['observacoes'] ?? '';
        if ($abert && $obs === '') $obs = 'Saldo de abertura';
        $this->Cell($this->larg[7], 6, $this->Cortar($obs, $this->larg[7]), 1, 1, 'L', $fundo);
    }

    function LinhaPdct($r, $fundo) {
        if ($this->GetY() + 6 > $this->PageBreakTrigger) {
            $this->AddPage();
            $this->CabecalhoTabela($this->cab_pdct, $this->larg_pdct, [3, 4]);
        }
        $l = $this->larg_pdct;
        $this->SetFillColor(250, 251, 252);
        $this->SetFont('Arial', '', 8);
        $this->SetTextColor(0, 0, 0);
        $this->Cell($l[0], 6, pt($r['seccao']), 1, 0, 'C', $fundo);
        $this->Cell($l[1], 6, pt(dataPT($r['data_registo'])), 1, 0, 'C', $fundo);
        $this->SetFont('Arial', 'B', 8);
        $this->Cell($l[2], 6, $this->Cortar($r['pdct'] ?? '', $l[2]), 1, 0, 'L', $fundo);
        $this->SetFont('Arial', '', 8);
        $this->CorValor((float)$r['valor']);
        $this->Cell($l[3], 6, pt(eur($r['valor'])), 1, 0, 'R', $fundo);
        $this->CorValor((float)$r['valor_corrigido']);
        $this->Cell($l[4], 6, $r['valor_corrigido'] === null ? '' : pt(eur($r['valor_corrigido'])), 1, 0, 'R', $fundo);
        $this->SetTextColor(0, 0, 0);
        $vc = (($r['valor_certo'] ?? '') === 'Sim') ? 'Sim' : 'Não';
        $this->Cell($l[5], 6, pt($vc), 1, 0, 'C', $fundo);
        $this->Cell($l[6], 6, $this->Cortar($r['observacoes'] ?? '', $l[6]), 1, 1, 'L', $fundo);
    }

    function LinhaSubtotal($rotulo, $valor, $larg_antes, $larg_valor, $larg_depois) {
        if ($this->GetY() + 7 > $this->PageBreakTrigger) { $this->AddPage(); }
        $this->SetFont('Arial', 'B', 9);
        $this->SetFillColor(241, 242, 246);
        $this->SetTextColor(0, 0, 0);
        $this->Cell($larg_antes, 7, pt($rotulo), 1, 0, 'R', true);
        $this->CorValor($valor);
        $this->Cell($larg_valor, 7, pt(eur($valor)), 1, 0, 'R', true);
        $this->SetTextColor(0, 0, 0);
        $this->Cell($larg_depois, 7, '', 1, 1, 'L', true);
    }
}

// --- TOTAIS (calculados a partir dos documentos; o histórico guarda os valores do momento do acerto) ---
$soma_mab = 0;  foreach ($docs_mab as $r)  $soma_mab  += (float)$r['valor_original'];
$soma_lsjr = 0; foreach ($docs_lsjr as $r) $soma_lsjr += (float)$r['valor_original'];
$soma_pdct = 0; $soma_pdct_orig = 0;
foreach ($docs_pdct as $r) { $soma_pdct += (float)$r['valor_corrigido']; $soma_pdct_orig += (float)$r['valor']; }

$total_mab   = (float)$acerto['total_favor_mab'];
$total_lsjr  = (float)$acerto['total_favor_lsjr'];
$total_pdct  = (float)$acerto['total_pdct'];
$total_acert = (float)$acerto['total_acerto'];

// --- GERAR PDF ---
$pdf = new PDF('L', 'mm', 'A4');
$pdf->titulo_doc = 'LSJR - ACERTO Nº ' . (int)$acerto['numero'];
$pdf->subtitulo_doc = 'Data do acerto: ' . dataPT($acerto['data_acerto'])
    . (!empty($acerto['criado_por']) ? ' | Efetuado por: ' . $acerto['criado_por'] : '')
    . (!empty($acerto['criado_em']) ? ' em ' . date('d/m/Y H:i', strtotime($acerto['criado_em'])) : '');
$pdf->SetMargins(10, 10, 10);
$pdf->AliasNbPages();
$pdf->SetAutoPageBreak(true, 15);
$pdf->AddPage();

$w = $pdf->larg;
$antes_valor = $w[0] + $w[1] + $w[2] + $w[3] + $w[4] + $w[5];

// ===== 1. A FAVOR DA m.ABREU =====
$pdf->TituloSeccao('A FAVOR DA m.ABREU', [39, 174, 96], count($docs_mab) . ' documento(s)');
if (empty($docs_mab)) {
    $pdf->SetFont('Arial', 'I', 9);
    $pdf->SetTextColor(120, 120, 120);
    $pdf->Cell(0, 7, pt('Sem documentos a favor da m.Abreu neste acerto.'), 0, 1, 'L');
} else {
    $pdf->CabecalhoTabela($pdf->cab, $w, [6]);
    $fundo = false;
    foreach ($docs_mab as $r) { $pdf->LinhaDoc($r, $fundo); $fundo = !$fundo; }
    $pdf->LinhaSubtotal('TOTAL A FAVOR DA m.ABREU', $soma_mab, $antes_valor, $w[6], $w[7]);
}
$pdf->Ln(6);

// ===== 2. A FAVOR DA LSJR =====
$pdf->TituloSeccao('A FAVOR DA LSJR', [192, 57, 43], count($docs_lsjr) . ' documento(s)');
if (empty($docs_lsjr)) {
    $pdf->SetFont('Arial', 'I', 9);
    $pdf->SetTextColor(120, 120, 120);
    $pdf->Cell(0, 7, pt('Sem documentos a favor da LSJR neste acerto.'), 0, 1, 'L');
} else {
    $pdf->CabecalhoTabela($pdf->cab, $w, [6]);
    $fundo = false;
    foreach ($docs_lsjr as $r) { $pdf->LinhaDoc($r, $fundo); $fundo = !$fundo; }
    $pdf->LinhaSubtotal('TOTAL A FAVOR DA LSJR', $soma_lsjr, $antes_valor, $w[6], $w[7]);
}
$pdf->Ln(6);

// ===== 3. PDCT (INFORMATIVO) =====
$pdf->TituloSeccao('PEDIDOS DE COTAÇÃO (PDCT) - INFORMATIVO', [127, 140, 141], count($docs_pdct) . ' registo(s) | Não entram no total do acerto');
if (empty($docs_pdct)) {
    $pdf->SetFont('Arial', 'I', 9);
    $pdf->SetTextColor(120, 120, 120);
    $pdf->Cell(0, 7, pt('Sem PDCT neste acerto.'), 0, 1, 'L');
} else {
    $lp = $pdf->larg_pdct;
    $pdf->CabecalhoTabela($pdf->cab_pdct, $lp, [3, 4]);
    $fundo = false;
    foreach ($docs_pdct as $r) { $pdf->LinhaPdct($r, $fundo); $fundo = !$fundo; }
    $pdf->Espaco(7);
    $pdf->SetFont('Arial', 'B', 9);
    $pdf->SetFillColor(241, 242, 246);
    $pdf->Cell($lp[0] + $lp[1] + $lp[2], 7, pt('TOTAL PDCT (informativo)'), 1, 0, 'R', true);
    $pdf->CorValor($soma_pdct_orig);
    $pdf->Cell($lp[3], 7, pt(eur($soma_pdct_orig)), 1, 0, 'R', true);
    $pdf->CorValor($soma_pdct);
    $pdf->Cell($lp[4], 7, pt(eur($soma_pdct)), 1, 0, 'R', true);
    $pdf->SetTextColor(0, 0, 0);
    $pdf->Cell($lp[5] + $lp[6], 7, '', 1, 1, 'L', true);
}
$pdf->Ln(6);

// ===== 4. RESUMO DO ACERTO =====
$taxa_iva = 0.23; // Os valores dos documentos já incluem IVA
$sem_iva = fn($v) => round($v / (1 + $taxa_iva), 2);

$pdf->Espaco(62);
$pdf->TituloSeccao('RESUMO DO ACERTO', [230, 126, 34]);
$y_resumo = $pdf->GetY();

$lr = [70, 30, 30, 30];
$larg_resumo = array_sum($lr);
$xResumo = 287 - $larg_resumo;
$pdf->SetXY($xResumo, $y_resumo);
$pdf->SetFont('Arial', 'B', 9);
$pdf->SetFillColor(241, 242, 246);
$pdf->Cell($lr[0], 7, pt(' RESUMO'), 1, 0, 'L', true);
$pdf->Cell($lr[1], 7, pt('Sem IVA'), 1, 0, 'R', true);
$pdf->Cell($lr[2], 7, pt('IVA (' . round($taxa_iva * 100) . '%)'), 1, 0, 'R', true);
$pdf->Cell($lr[3], 7, pt('Com IVA'), 1, 1, 'R', true);

$linhas = [
    ['A favor da m.Abreu (' . (int)$acerto['n_favor_mab'] . ')', $total_mab, [39, 174, 96], false],
    ['A favor da LSJR (' . (int)$acerto['n_favor_lsjr'] . ')', -$total_lsjr, [192, 57, 43], false],
    ['TOTAL ACERTADO', $total_acert, $total_acert < 0 ? [192, 57, 43] : [44, 62, 80], true],
];
foreach ($linhas as $lin) {
    [$desc, $com, $cor, $destaque] = $lin;
    $sem = $sem_iva($com);
    $iva = round($com - $sem, 2);
    $pdf->SetX($xResumo);
    $pdf->SetFont('Arial', $destaque ? 'B' : '', $destaque ? 10 : 9);
    $pdf->SetTextColor(0, 0, 0);
    $pdf->SetFillColor(253, 242, 233);
    $pdf->Cell($lr[0], 7, pt(' ' . $desc), 1, 0, 'L', $destaque);
    $pdf->SetTextColor($cor[0], $cor[1], $cor[2]);
    $pdf->Cell($lr[1], 7, pt(eur($sem) . ' '), 1, 0, 'R', $destaque);
    $pdf->Cell($lr[2], 7, pt(eur($iva) . ' '), 1, 0, 'R', $destaque);
    $pdf->Cell($lr[3], 7, pt(eur($com) . ' '), 1, 1, 'R', $destaque);
}
$pdf->SetTextColor(0, 0, 0);
$pdf->SetX($xResumo);
$pdf->SetFont('Arial', 'I', 8);
$pdf->SetTextColor(120, 120, 120);
$pdf->Cell($larg_resumo, 5, pt('Os valores incluem IVA a ' . round($taxa_iva * 100) . '%. Sem IVA = Com IVA / ' . number_format(1 + $taxa_iva, 2, ',', '') . '. PDCT apenas informativo: ' . eur($total_pdct) . ' (' . (int)$acerto['n_pdct'] . ' reg.).'), 0, 1, 'L');
$y_fim_resumo = $pdf->GetY();

// Caixa do resultado / saldo de abertura (lado esquerdo)
$larg_caixa = $xResumo - 10 - 8;
$pdf->SetXY(10, $y_resumo);
if ($acerto['abertura_lado'] === 'MAB') {
    $cor = [39, 174, 96]; $fundo = [234, 250, 241];
    $titulo = 'Saldo de abertura a favor da m.Abreu';
} elseif ($acerto['abertura_lado'] === 'LSJR') {
    $cor = [192, 57, 43]; $fundo = [253, 237, 236];
    $titulo = 'Saldo de abertura a favor da LSJR';
} else {
    $cor = [44, 62, 80]; $fundo = [241, 242, 246];
    $titulo = 'Contas acertadas (sem saldo de abertura)';
}
$pdf->SetFillColor($fundo[0], $fundo[1], $fundo[2]);
$pdf->SetDrawColor($cor[0], $cor[1], $cor[2]);
$pdf->SetLineWidth(0.4);
$pdf->Rect(10, $y_resumo, $larg_caixa, 28, 'DF');
$pdf->SetXY(14, $y_resumo + 3);
$pdf->SetFont('Arial', 'B', 11);
$pdf->SetTextColor($cor[0], $cor[1], $cor[2]);
$pdf->Cell($larg_caixa - 8, 6, pt($titulo), 0, 1, 'L');
$pdf->SetX(14);
$pdf->SetFont('Arial', 'B', 16);
$pdf->Cell($larg_caixa - 8, 9, pt(eur(abs($total_acert))), 0, 1, 'L');
$pdf->SetX(14);
$pdf->SetFont('Arial', '', 8);
$pdf->SetTextColor(87, 101, 116);
if ($acerto['abertura_lado']) {
    $ref = $abertura
        ? ($abertura['tipo_doc'] . ' ' . $abertura['numero_documento'] . ' de ' . dataPT($abertura['data_emissao']) . ' (' . ($acerto['abertura_lado'] === 'MAB' ? 'A favor MAB' : 'A favor de LSJR') . ', estado: ' . $abertura['estado'] . ')')
        : 'Documento de abertura já não existe.';
    $pdf->Cell($larg_caixa - 8, 5, $pdf->Cortar('Lançado como: ' . $ref, $larg_caixa - 8), 0, 1, 'L');
} else {
    $pdf->Cell($larg_caixa - 8, 5, pt('Total a favor da m.Abreu igual ao total a favor da LSJR.'), 0, 1, 'L');
}

// Aviso se os documentos atuais não batem com o histórico (ex.: documentos alterados depois)
$pdf->SetY(max($y_fim_resumo, $y_resumo + 30) + 2);
if (abs($soma_mab - $total_mab) >= 0.005 || abs($soma_lsjr - $total_lsjr) >= 0.005) {
    $pdf->SetFont('Arial', 'I', 8);
    $pdf->SetTextColor(192, 57, 43);
    $pdf->Cell(0, 5, pt('Atenção: a soma dos documentos listados difere dos totais registados no momento do acerto (os totais do resumo são os do acerto).'), 0, 1, 'L');
}

// Assinaturas
$pdf->Espaco(25);
$pdf->Ln(12);
$y_ass = $pdf->GetY();
$pdf->SetDrawColor(150, 150, 150);
$pdf->SetLineWidth(0.2);
$pdf->Line(30, $y_ass, 120, $y_ass);
$pdf->Line(177, $y_ass, 267, $y_ass);
$pdf->SetFont('Arial', '', 8);
$pdf->SetTextColor(87, 101, 116);
$pdf->SetXY(30, $y_ass + 1);
$pdf->Cell(90, 5, pt('m. Abreu - Instalações Técnicas, Lda.'), 0, 0, 'C');
$pdf->SetXY(177, $y_ass + 1);
$pdf->Cell(90, 5, pt('LSJR'), 0, 0, 'C');

ob_end_clean();
$pdf->Output('I', 'LSJR_Acerto_' . (int)$acerto['numero'] . '_' . date('Ymd', strtotime($acerto['data_acerto'])) . '.pdf');
?>
