<?php
// LISTA DE MATERIAL A COMPRAR — só para orçamentos Adjudicados
// Fonte: linhas do Detalhe Interno com quantidade e preço de custo (material).
// Linhas com vários artigos (composição) são separadas nos artigos que as compõem.
// Linhas iguais (mesma designação + unidade) são somadas. Sem preços nem referências.
ob_start();
session_start();

if (!isset($_SESSION['user_id'])) {
    ob_end_clean();
    die("Acesso negado.");
}

require_once 'db.php';
require_once('fpdf/fpdf.php');

$perfil_user = $_SESSION['perfil'] ?? '';
$is_admin = in_array($perfil_user, ['admin', 'administrativo']);

$stmt_perm = $pdo->prepare("SELECT modulo FROM permissoes WHERE grupo = :perfil AND pode_aceder = 1");
$stmt_perm->execute(['perfil' => $perfil_user]);
$meus_acessos = $stmt_perm->fetchAll(PDO::FETCH_COLUMN);

if (!$is_admin && !in_array('orcamentos', $meus_acessos)) {
    ob_end_clean();
    die("Acesso negado.");
}

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$id) {
    ob_end_clean();
    die("Erro: ID de orçamento inválido.");
}

try {
    $stmt = $pdo->prepare("SELECT o.*, c.nome AS cliente_nome FROM orcamentos o LEFT JOIN clientes c ON o.id_cliente = c.id WHERE o.id = ?");
    $stmt->execute([$id]);
    $orc = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$orc) {
        ob_end_clean();
        die("Erro: Orçamento não encontrado.");
    }
    if (($orc['estado'] ?? '') !== 'Adjudicado') {
        ob_end_clean();
        die("A lista de material só está disponível para orçamentos Adjudicados.");
    }

    $stmt_itens = $pdo->prepare("SELECT * FROM orcamento_itens WHERE orcamento_id = ? ORDER BY id ASC");
    $stmt_itens->execute([$id]);
    $itens_raw = $stmt_itens->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    ob_end_clean();
    die("Erro na BD: " . htmlspecialchars($e->getMessage()));
}

// --- AGRUPAR MATERIAL ---
// Linha com vários artigos da tabela de preços (composição): entra cada artigo em separado, com a
// quantidade da linha × quantidade do artigo por unidade (como na FAME e no material a aprovisionar).
$materiais = [];
$juntar = function ($desig, $unid, $qtd) use (&$materiais) {
    $desig = trim(preg_replace('/\s+/', ' ', (string)$desig));
    $unid  = trim((string)$unid);
    if ($desig === '' || $qtd <= 0) return;
    $chave = mb_strtolower($desig . '|' . $unid, 'UTF-8');
    if (!isset($materiais[$chave])) {
        $materiais[$chave] = ['designacao' => $desig, 'unidade' => $unid, 'quantidade' => 0];
    }
    $materiais[$chave]['quantidade'] += $qtd;
};
foreach ($itens_raw as $it) {
    if (($it['tipo_linha'] ?? 'Interno') === 'Comercial') continue;   // só o Detalhe Interno
    $qtd = (float)($it['quantidade'] ?? 0);
    if ($qtd <= 0) continue;                                           // títulos

    $comp = json_decode((string)($it['composicao_json'] ?? ''), true);
    if (is_array($comp) && $comp) {
        foreach ($comp as $c) {
            if ((float)($c['pc'] ?? 0) <= 0) continue;                 // artigo sem preço de custo (mão de obra)
            $juntar($c['desc'] ?? '', $c['un'] ?? '', (float)($c['q'] ?? 0) * $qtd);
        }
        continue;
    }

    if ((float)($it['preco_unitario_custo'] ?? 0) <= 0) continue;     // mão de obra fica de fora
    $juntar($it['designacao'] ?? '', $it['unidade'] ?? '', $qtd);
}

// Ordenar alfabeticamente pela designação (sem distinguir maiúsculas/acentos)
function chaveOrdenacao($t) {
    $t = mb_strtolower($t, 'UTF-8');
    $t = strtr($t, ['á'=>'a','à'=>'a','â'=>'a','ã'=>'a','ä'=>'a','é'=>'e','è'=>'e','ê'=>'e','ë'=>'e','í'=>'i','ì'=>'i','î'=>'i','ï'=>'i','ó'=>'o','ò'=>'o','ô'=>'o','õ'=>'o','ö'=>'o','ú'=>'u','ù'=>'u','û'=>'u','ü'=>'u','ç'=>'c','ñ'=>'n']);
    return $t;
}
uasort($materiais, function ($a, $b) {
    $r = strnatcmp(chaveOrdenacao($a['designacao']), chaveOrdenacao($b['designacao']));
    return $r !== 0 ? $r : strcmp($a['unidade'], $b['unidade']);
});

function pt($texto) {
    return mb_convert_encoding((string)($texto ?? ''), 'windows-1252', 'UTF-8');
}
function qtdFmt($v) {
    $v = (float)$v;
    return (floor($v) == $v) ? number_format($v, 0, ',', '.') : number_format($v, 2, ',', '.');
}

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
        $this->SetY(12); $this->SetFont('Arial', 'B', 13); $this->SetTextColor(179, 42, 42);
        $this->Cell(0, 8, pt($this->titulo_doc), 0, 1, 'R');
        $this->SetFont('Arial', '', 8); $this->SetTextColor(120, 120, 120);
        $this->Cell(0, 4, pt('Documento interno · impresso em ' . date('d/m/Y H:i')), 0, 1, 'R');
        $this->Ln(6); $y = $this->GetY(); $this->SetDrawColor(179, 42, 42); $this->SetLineWidth(0.5);
        $this->Line(10, $y, 200, $y); $this->Ln(4);
        $this->SetTextColor(0, 0, 0);
    }
    function Footer() {
        $this->SetY(-15); $this->SetDrawColor(179, 42, 42); $this->SetLineWidth(0.5);
        $this->Line(10, $this->GetY(), 200, $this->GetY()); $this->Ln(2); $this->SetFont('Arial', '', 8);
        $this->Cell(0, 4, pt('Lista de Material · Orçamento Nº ' . $this->ref_doc), 0, 0, 'L');
        $this->SetX(10); $this->Cell(0, 4, pt('Página ') . $this->PageNo() . '/{nb}', 0, 1, 'R');
    }
}

$pdf = new PDF('P', 'mm', 'A4');
$pdf->titulo_doc = 'LISTA DE MATERIAL Nº ' . $orc['numero_orcamento'];
$pdf->ref_doc = $orc['numero_orcamento'];
$pdf->AliasNbPages();
$pdf->SetAutoPageBreak(false);
$pdf->AddPage();

// --- DADOS DA OBRA ---
$pdf->SetFont('Arial', 'B', 9); $pdf->Cell(18, 5, pt('Cliente:'), 0, 0);
$pdf->SetFont('Arial', '', 9);  $pdf->Cell(110, 5, pt($orc['cliente_nome'] ?? '-'), 0, 0);
$pdf->SetFont('Arial', 'B', 9); $pdf->Cell(30, 5, pt('Data orçamento:'), 0, 0, 'R');
$pdf->SetFont('Arial', '', 9);  $pdf->Cell(32, 5, pt(!empty($orc['data']) ? date('d/m/Y', strtotime($orc['data'])) : '-'), 0, 1, 'R');
$pdf->SetFont('Arial', 'B', 9); $pdf->Cell(18, 5, pt('Obra:'), 0, 0);
$pdf->SetFont('Arial', '', 9);  $pdf->Cell(172, 5, pt($orc['nome_obra'] ?? '-'), 0, 1);
$pdf->SetFont('Arial', 'B', 9); $pdf->Cell(18, 5, pt('Morada:'), 0, 0);
$pdf->SetFont('Arial', '', 9);  $pdf->Cell(172, 5, pt(trim(preg_replace('/\s+/', ' ', (string)($orc['morada_obra'] ?? ''))) ?: '-'), 0, 1);
$pdf->Ln(4);

// --- TABELA ---
$w = [10, 146, 14, 20]; // ✓ | Designação | Un. | Qtd.  = 190
$cab = function () use ($pdf, $w) {
    $pdf->SetFont('Arial', 'B', 8); $pdf->SetFillColor(245, 245, 245); $pdf->SetDrawColor(200, 200, 200); $pdf->SetLineWidth(0.2);
    $pdf->Cell($w[0], 7, '', 'T,B', 0, 'C', true);
    $pdf->Cell($w[1], 7, pt('Designação'), 'T,B', 0, 'L', true);
    $pdf->Cell($w[2], 7, pt('Un.'), 'T,B', 0, 'C', true);
    $pdf->Cell($w[3], 7, pt('Qtd.'), 'T,B', 1, 'C', true);
};

if (empty($materiais)) {
    $pdf->SetFont('Arial', '', 10); $pdf->SetTextColor(120, 120, 120);
    $pdf->MultiCell(0, 6, pt('Este orçamento não tem linhas de material no Detalhe Interno (linhas com quantidade e preço de custo).'), 0, 'C');
} else {
    $cab();
    $lh = 5; $n = 0;
    foreach ($materiais as $m) {
        $n++;
        $pdf->SetFont('Arial', '', 9);
        $desig = pt($m['designacao']);
        $h = $lh * max(1, $pdf->NbLines($w[1], $desig)) + 3;
        if ($pdf->GetY() + $h > 262) { $pdf->AddPage(); $cab(); $pdf->SetFont('Arial', '', 9); }

        $x = 10; $y = $pdf->GetY();
        if ($n % 2 == 0) { $pdf->SetFillColor(250, 250, 250); $pdf->Rect($x, $y, 190, $h, 'F'); }
        // Quadrado para marcar "comprado"
        $pdf->SetDrawColor(150, 150, 150); $pdf->Rect($x + 3, $y + ($h - 4) / 2, 4, 4);
        $pdf->SetXY($x + $w[0], $y + 1.5); $pdf->MultiCell($w[1], $lh, $desig, 0, 'L');
        $cx = $x + $w[0] + $w[1];
        $pdf->SetXY($cx, $y + 1.5); $pdf->Cell($w[2], $lh, pt($m['unidade']), 0, 0, 'C'); $cx += $w[2];
        $pdf->SetFont('Arial', 'B', 10);
        $pdf->SetXY($cx, $y + 1.5); $pdf->Cell($w[3], $lh, qtdFmt($m['quantidade']), 0, 0, 'C');

        $pdf->SetY($y + $h);
        $pdf->SetDrawColor(225, 225, 225); $pdf->Line(10, $pdf->GetY(), 200, $pdf->GetY());
    }

    if ($pdf->GetY() + 30 > 270) $pdf->AddPage();
    $pdf->Ln(3);
    $pdf->SetFont('Arial', '', 9);
    $pdf->Cell(0, 6, pt(count($materiais) . ' artigo(s) a comprar'), 0, 1, 'L');

    // Assinaturas
    $pdf->Ln(10);
    $pdf->SetFont('Arial', '', 8); $pdf->SetDrawColor(150, 150, 150);
    $yS = $pdf->GetY();
    $pdf->Line(10, $yS + 8, 90, $yS + 8);  $pdf->SetXY(10, $yS + 9);  $pdf->Cell(80, 4, pt('Encomendado por / data'), 0, 0, 'C');
    $pdf->Line(120, $yS + 8, 200, $yS + 8); $pdf->SetXY(120, $yS + 9); $pdf->Cell(80, 4, pt('Recebido em obra por / data'), 0, 0, 'C');
}

ob_end_clean();
$pdf->Output('I', 'Lista_Material_' . preg_replace('/[^A-Za-z0-9_-]/', '_', $orc['numero_orcamento']) . '.pdf');
