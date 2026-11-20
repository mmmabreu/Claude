<?php
// LISTAGEM DE EQUIPAMENTO ENTREGUE AOS COLABORADORES (PDF A4 horizontal)
// Separador "Equipamento por Colaborador" de equipamentos.php.
// equipamentos_colaboradores_pdf.php            → todos os colaboradores (admin / administrativo)
// equipamentos_colaboradores_pdf.php?colab=ID   → só esse colaborador
// Colaboradores por ordem alfabética; dentro de cada um, equipamentos por data de entrega (mais antiga primeiro).
// Os outros perfis só podem imprimir o seu próprio equipamento.
ob_start();
session_start();
if (!isset($_SESSION['user_id'])) { ob_end_clean(); die("Acesso negado."); }

require_once 'db.php';
require_once('fpdf/fpdf.php');

$perfil_user = $_SESSION['perfil'] ?? '';
$is_admin = in_array($perfil_user, ['admin', 'administrativo']);
$id_logado = (int)$_SESSION['user_id'];

$colab = filter_input(INPUT_GET, 'colab', FILTER_VALIDATE_INT) ?: 0;
if (!$is_admin) $colab = $id_logado;   // só o próprio

try {
    $sql = "SELECT e.*, u.nome AS nome_colaborador
            FROM equipamentos e
            LEFT JOIN utilizadores u ON e.id_utilizador = u.id
            WHERE e.tipo = 'individual'";
    $params = [];
    if ($colab) { $sql .= " AND e.id_utilizador = ?"; $params[] = $colab; }
    $sql .= " ORDER BY (u.nome IS NULL), u.nome ASC, e.id_utilizador ASC, e.data_registo ASC, e.id ASC";
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $lista = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    ob_end_clean(); die("Erro na BD: " . htmlspecialchars($e->getMessage()));
}

// Agrupar por colaborador (já vem por ordem alfabética)
$grupos = [];
foreach ($lista as $e) {
    $k = (int)($e['id_utilizador'] ?? 0);
    if (!isset($grupos[$k])) $grupos[$k] = ['nome' => trim((string)($e['nome_colaborador'] ?? '')) ?: 'Colaborador não identificado', 'itens' => []];
    $grupos[$k]['itens'][] = $e;
}
// Ordem alfabética sem distinguir acentos / maiúsculas (ex.: "Álvaro" junto dos "A")
$semAcentos = function ($t) {
    $t = mb_strtolower((string)$t, 'UTF-8');
    return strtr($t, ['á'=>'a','à'=>'a','â'=>'a','ã'=>'a','é'=>'e','ê'=>'e','í'=>'i','ó'=>'o','ô'=>'o','õ'=>'o','ú'=>'u','ç'=>'c']);
};
uasort($grupos, function ($a, $b) use ($semAcentos) {
    if ($a['nome'] === 'Colaborador não identificado') return 1;
    if ($b['nome'] === 'Colaborador não identificado') return -1;
    return strnatcmp($semAcentos($a['nome']), $semAcentos($b['nome']));
});

function pt($t) { return mb_convert_encoding((string)($t ?? ''), 'windows-1252', 'UTF-8'); }

class PDF extends FPDF {
    public $titulo = '';
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
        if (file_exists('mab.png')) $this->Image('mab.png', 10, 9, 18);
        $this->SetY(10); $this->SetFont('Arial', 'B', 13); $this->SetTextColor(44, 62, 80);
        $this->Cell(0, 7, pt('EQUIPAMENTO ENTREGUE AOS COLABORADORES'), 0, 1, 'R');
        $this->SetFont('Arial', 'B', 10); $this->SetTextColor(74, 105, 189);
        $this->Cell(0, 6, pt($this->titulo), 0, 1, 'R');
        $this->SetFont('Arial', '', 8); $this->SetTextColor(120, 120, 120);
        $this->Cell(0, 4, pt('Impresso em ' . date('d/m/Y H:i')), 0, 1, 'R');
        $this->Ln(2); $y = $this->GetY(); $this->SetDrawColor(74, 105, 189); $this->SetLineWidth(0.5);
        $this->Line(10, $y, 287, $y); $this->Ln(4);
        $this->SetTextColor(0, 0, 0); $this->SetLineWidth(0.2);
    }
    function Footer() {
        $this->SetY(-13); $this->SetDrawColor(200, 200, 200); $this->SetLineWidth(0.2);
        $this->Line(10, $this->GetY(), 287, $this->GetY()); $this->Ln(1.5);
        $this->SetFont('Arial', 'B', 7.5); $this->SetTextColor(100, 100, 100);
        $y = $this->GetY();
        $this->Cell(0, 4, pt('m. Abreu - Instalações Técnicas, Lda. | NIF: 514 414 120 | Rua Damião de Góis, 205, Braga | geral@mabreu.pt'), 0, 0, 'C');
        $this->SetXY(10, $y); $this->Cell(0, 4, pt('Página ') . $this->PageNo() . '/{nb}', 0, 0, 'R');
    }
}

$pdf = new PDF('L', 'mm', 'A4');
$pdf->titulo = ($colab && $grupos) ? reset($grupos)['nome'] : 'Todos os colaboradores';
$pdf->AliasNbPages();
$pdf->SetAutoPageBreak(false);
$pdf->AddPage();

// Resumo
$pdf->SetFont('Arial', '', 9);
$pdf->Cell(0, 5, pt(count($lista) . ' equipamento(s)' . (!$colab ? '  ·  ' . count($grupos) . ' colaborador(es)' : '')), 0, 1, 'L');
$pdf->Ln(2);

// Colunas: Nº | Data entrega | Descrição | Marca / Modelo | Nº de série | Estado | Observações  = 277
$w   = [10, 24, 72, 50, 42, 22, 57];
$tit = ['Nº', 'Data entrega', 'Descrição do equipamento', 'Marca / Modelo', 'Nº de série', 'Estado', 'Observações'];
$cab = function () use ($pdf, $w, $tit) {
    $pdf->SetFont('Arial', 'B', 7.5); $pdf->SetFillColor(44, 62, 80); $pdf->SetTextColor(255, 255, 255); $pdf->SetDrawColor(44, 62, 80);
    foreach ($tit as $i => $t) $pdf->Cell($w[$i], 7, pt($t), 1, 0, in_array($i, [0, 1, 5]) ? 'C' : 'L', true);
    $pdf->Ln();
    $pdf->SetTextColor(0, 0, 0); $pdf->SetDrawColor(210, 214, 220);
};
$secao = function ($titulo, $n) use ($pdf) {
    $pdf->SetFont('Arial', 'B', 9.5); $pdf->SetFillColor(74, 105, 189); $pdf->SetTextColor(255, 255, 255);
    $pdf->Cell(0, 7, pt('  ' . mb_strtoupper($titulo, 'UTF-8') . '  (' . $n . ' equipamento' . ($n == 1 ? '' : 's') . ')'), 0, 1, 'L', true);
    $pdf->SetTextColor(0, 0, 0);
};

if (!$lista) {
    $pdf->SetFont('Arial', '', 11); $pdf->SetTextColor(120, 120, 120);
    $pdf->Cell(0, 20, pt('Nenhum equipamento entregue.'), 0, 1, 'C');
} else {
    $lh = 4.2;
    foreach ($grupos as $g) {
        if ($pdf->GetY() + 24 > 190) $pdf->AddPage();   // título do colaborador nunca fica sozinho no fim da página
        $pdf->Ln(1);
        $secao($g['nome'], count($g['itens']));
        $cab();
        $n = 0;
        foreach ($g['itens'] as $e) {
            $n++;
            $cols = [2 => pt($e['descricao']), 3 => pt($e['marca']), 4 => pt($e['numero_serie']), 6 => pt($e['observacoes'])];
            $pdf->SetFont('Arial', '', 8);
            $nl = 1; foreach ($cols as $i => $t) $nl = max($nl, $pdf->NbLines($w[$i], $t));
            $h = max(7, $nl * $lh + 2.5);
            if ($pdf->GetY() + $h > 190) {
                $pdf->AddPage();
                $secao($g['nome'] . ' (continuação)', count($g['itens']));
                $cab(); $pdf->SetFont('Arial', '', 8);
            }
            $x = 10; $y = $pdf->GetY();
            if ($n % 2 == 0) { $pdf->SetFillColor(248, 249, 252); $pdf->Rect($x, $y, 277, $h, 'F'); }
            $cx = $x; foreach ($w as $wi) { $pdf->Rect($cx, $y, $wi, $h); $cx += $wi; }

            $cx = $x;
            $pdf->SetXY($cx, $y); $pdf->Cell($w[0], $h, $n, 0, 0, 'C'); $cx += $w[0];
            $pdf->SetFont('Arial', 'B', 8);
            $pdf->SetXY($cx, $y); $pdf->Cell($w[1], $h, !empty($e['data_registo']) ? date('d/m/Y', strtotime($e['data_registo'])) : '-', 0, 0, 'C'); $cx += $w[1];
            foreach ([2, 3, 4] as $i) {
                $pdf->SetFont('Arial', $i === 2 ? 'B' : '', 8);
                $pdf->SetXY($cx, $y + 1.2); $pdf->MultiCell($w[$i], $lh, $cols[$i], 0, 'L');
                $cx += $w[$i];
            }
            $pdf->SetFont('Arial', '', 8);
            $pdf->SetXY($cx, $y); $pdf->Cell($w[5], $h, pt($e['estado']), 0, 0, 'C'); $cx += $w[5];
            $pdf->SetFont('Arial', '', 7.5);
            $pdf->SetXY($cx, $y + 1.2); $pdf->MultiCell($w[6], $lh, $cols[6], 0, 'L');
            $pdf->SetY($y + $h);
        }
        $pdf->Ln(3);
    }
}

ob_end_clean();
$nome = ($colab && $grupos) ? preg_replace('/[^A-Za-z0-9_-]/', '_', iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', reset($grupos)['nome'])) : 'Todos';
$pdf->Output('I', 'Equipamento_Colaboradores_' . $nome . '_' . date('Ymd') . '.pdf');
