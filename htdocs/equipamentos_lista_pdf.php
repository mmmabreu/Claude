<?php
// LISTAGEM DE EQUIPAMENTO GERAL DA EMPRESA (PDF A4 horizontal)
// equipamentos_lista_pdf.php?loc=todos | empresa | obra | emprestado  (o separador escolhido em equipamentos.php)
// Em "todos" a lista sai agrupada por localização (Na empresa / Em obra / Emprestado).
// "Data acontec." = data do último movimento (quando foi para onde está agora), do histórico equipamentos_movimentos.
ob_start();
session_start();
if (!isset($_SESSION['user_id'])) { ob_end_clean(); die("Acesso negado."); }

require_once 'db.php';
require_once('fpdf/fpdf.php');

// Só administradores / administrativos (o equipamento geral só aparece para eles)
$perfil_user = $_SESSION['perfil'] ?? '';
if (!in_array($perfil_user, ['admin', 'administrativo'])) { ob_end_clean(); die("Acesso negado."); }

$LOCAIS = ['empresa' => 'Na empresa', 'obra' => 'Em obra', 'emprestado' => 'Emprestado'];
$loc = $_GET['loc'] ?? 'todos';
if ($loc !== 'todos' && !isset($LOCAIS[$loc])) $loc = 'todos';

// Data do acontecimento: último movimento do histórico (se a tabela existir)
$col_evento = "NULL AS data_evento";
try {
    $pdo->query("SELECT 1 FROM equipamentos_movimentos LIMIT 1");
    $col_evento = "(SELECT m.data_evento FROM equipamentos_movimentos m WHERE m.id_equipamento = e.id ORDER BY m.data_evento DESC, m.id DESC LIMIT 1) AS data_evento";
} catch (Exception $e) {}

try {
    $sql = "SELECT e.*, $col_evento, c.nome AS nome_cliente, o.nome AS nome_obra, o.referencia_obra AS ref_obra, col.nome AS nome_colab_levou
            FROM equipamentos e
            LEFT JOIN clientes c ON e.id_cliente = c.id
            LEFT JOIN obras o ON e.id_obra = o.id
            LEFT JOIN utilizadores col ON e.id_colaborador_levou = col.id
            WHERE e.tipo = 'geral'";
    $params = [];
    if ($loc === 'empresa') { $sql .= " AND (e.localizacao = 'empresa' OR e.localizacao IS NULL OR e.localizacao = '')"; }
    elseif ($loc !== 'todos') { $sql .= " AND e.localizacao = ?"; $params[] = $loc; }
    $sql .= " ORDER BY e.descricao ASC, e.data_registo DESC";
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $lista = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    ob_end_clean(); die("Erro na BD: " . htmlspecialchars($e->getMessage()));
}

// Agrupar por localização (pela ordem Na empresa / Em obra / Emprestado)
$grupos = [];
foreach ($LOCAIS as $k => $v) $grupos[$k] = [];
foreach ($lista as $e) {
    $l = $e['localizacao'] ?: 'empresa';
    if (!isset($grupos[$l])) $l = 'empresa';
    $grupos[$l][] = $e;
}
$grupos = array_filter($grupos);

function pt($t) { return mb_convert_encoding((string)($t ?? ''), 'windows-1252', 'UTF-8'); }

// Texto da localização (onde está e quem levou)
function detalheLocal($e) {
    $l = $e['localizacao'] ?: 'empresa';
    if ($l === 'obra') {
        $t = trim(($e['nome_cliente'] ?? '') . (!empty($e['nome_obra']) ? ' · ' . $e['nome_obra'] : '') . (!empty($e['ref_obra']) ? ' (' . $e['ref_obra'] . ')' : ''), ' ·');
        if (!empty($e['nome_colab_levou'])) $t .= ($t !== '' ? "\n" : '') . 'Levou: ' . $e['nome_colab_levou'];
        return $t;
    }
    if ($l === 'emprestado') return !empty($e['nome_cliente']) ? $e['nome_cliente'] : (string)($e['emprestado_nome'] ?? '');
    return '';
}

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
        $this->Cell(0, 7, pt('LISTAGEM DE EQUIPAMENTO DA EMPRESA'), 0, 1, 'R');
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
$pdf->titulo = $loc === 'todos' ? 'Todos os equipamentos' : $LOCAIS[$loc];
$pdf->AliasNbPages();
$pdf->SetAutoPageBreak(false);
$pdf->AddPage();

// Resumo
$pdf->SetFont('Arial', '', 9);
$resumo = count($lista) . ' equipamento(s)';
if ($loc === 'todos') {
    $partes = [];
    foreach ($LOCAIS as $k => $v) $partes[] = $v . ': ' . count($grupos[$k] ?? []);
    $resumo .= '  ·  ' . implode('  ·  ', $partes);
}
$pdf->Cell(0, 5, pt($resumo), 0, 1, 'L');
$pdf->Ln(2);

// Colunas: Nº | Data compra | Descrição | Marca / Modelo | Nº série | Fornecedor | Localização | Data acontec. | Observações  = 277
$w   = [9, 20, 50, 34, 32, 30, 46, 20, 36];
$tit = ['Nº', 'Data compra', 'Descrição / Máquina', 'Marca / Modelo', 'Nº de série', 'Fornecedor', 'Localização', 'Data acontec.', 'Observações'];
$cab = function () use ($pdf, $w, $tit) {
    $pdf->SetFont('Arial', 'B', 7.5); $pdf->SetFillColor(44, 62, 80); $pdf->SetTextColor(255, 255, 255); $pdf->SetDrawColor(44, 62, 80);
    foreach ($tit as $i => $t) $pdf->Cell($w[$i], 7, pt($t), 1, 0, in_array($i, [0, 1, 7]) ? 'C' : 'L', true);
    $pdf->Ln();
    $pdf->SetTextColor(0, 0, 0); $pdf->SetDrawColor(210, 214, 220);
};
$secao = function ($titulo, $n) use ($pdf) {
    $pdf->SetFont('Arial', 'B', 9.5); $pdf->SetFillColor(74, 105, 189); $pdf->SetTextColor(255, 255, 255);
    $pdf->Cell(0, 7, pt('  ' . mb_strtoupper($titulo, 'UTF-8') . '  (' . $n . ')'), 0, 1, 'L', true);
    $pdf->SetTextColor(0, 0, 0);
};

if (!$lista) {
    $pdf->SetFont('Arial', '', 11); $pdf->SetTextColor(120, 120, 120);
    $pdf->Cell(0, 20, pt('Nenhum equipamento nesta localização.'), 0, 1, 'C');
} else {
    $lh = 4.2; $n = 0;
    foreach ($grupos as $k => $itens) {
        if ($pdf->GetY() + 24 > 190) $pdf->AddPage();
        if ($loc === 'todos') { $pdf->Ln(1); $secao($LOCAIS[$k], count($itens)); }
        $cab();
        $par = 0;
        foreach ($itens as $e) {
            $n++; $par++;
            $local = $LOCAIS[$e['localizacao'] ?: 'empresa'] ?? 'Na empresa';
            $det = detalheLocal($e);
            $cols = [
                2 => pt($e['descricao']), 3 => pt($e['marca']), 4 => pt($e['numero_serie']), 5 => pt($e['fornecedor']),
                6 => pt($local . ($det !== '' ? "\n" . $det : '')), 8 => pt($e['observacoes']),
            ];
            $pdf->SetFont('Arial', '', 8);
            $nl = 1; foreach ($cols as $i => $t) $nl = max($nl, $pdf->NbLines($w[$i], $t));
            $h = max(7, $nl * $lh + 2.5);
            if ($pdf->GetY() + $h > 190) {
                $pdf->AddPage();
                if ($loc === 'todos') $secao($LOCAIS[$k] . ' (continuação)', count($itens));
                $cab(); $pdf->SetFont('Arial', '', 8);
            }
            $x = 10; $y = $pdf->GetY();
            if ($par % 2 == 0) { $pdf->SetFillColor(248, 249, 252); $pdf->Rect($x, $y, 277, $h, 'F'); }
            $cx = $x; foreach ($w as $wi) { $pdf->Rect($cx, $y, $wi, $h); $cx += $wi; }

            $cx = $x;
            $pdf->SetXY($cx, $y); $pdf->Cell($w[0], $h, $n, 0, 0, 'C'); $cx += $w[0];
            $pdf->SetXY($cx, $y); $pdf->Cell($w[1], $h, !empty($e['data_registo']) ? date('d/m/Y', strtotime($e['data_registo'])) : '', 0, 0, 'C'); $cx += $w[1];
            foreach ([2, 3, 4, 5, 6] as $i) {
                $pdf->SetFont('Arial', $i === 2 ? 'B' : '', 8);
                $pdf->SetXY($cx, $y + 1.2); $pdf->MultiCell($w[$i], $lh, $cols[$i], 0, 'L');
                $cx += $w[$i];
            }
            // Data do acontecimento (último movimento); sem histórico fica vazia
            $pdf->SetFont('Arial', 'B', 8);
            $pdf->SetXY($cx, $y); $pdf->Cell($w[7], $h, !empty($e['data_evento']) ? date('d/m/Y', strtotime($e['data_evento'])) : '-', 0, 0, 'C'); $cx += $w[7];
            $pdf->SetFont('Arial', '', 7.5);
            $pdf->SetXY($cx, $y + 1.2); $pdf->MultiCell($w[8], $lh, $cols[8], 0, 'L');
            $pdf->SetY($y + $h);
        }
        $pdf->Ln(3);
    }
}

ob_end_clean();
$pdf->Output('I', 'Equipamentos_' . ($loc === 'todos' ? 'Todos' : ucfirst($loc)) . '_' . date('Ymd') . '.pdf');
