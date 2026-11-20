<?php
// REGRA DE OURO: Limpar qualquer espaço em branco ou lixo antes de gerar o PDF
ob_start();
session_start();

if (!isset($_SESSION['user_id'])) { 
    ob_end_clean();
    die("Acesso negado."); 
}

require_once 'db.php';
require_once('fpdf/fpdf.php');

// Identificar o contexto (pode vir do orçamento ou da obra)
$orcamento_id = filter_input(INPUT_GET, 'orcamento_id', FILTER_VALIDATE_INT);
$obra_id = filter_input(INPUT_GET, 'obra_id', FILTER_VALIDATE_INT);
$is_obra = !empty($obra_id);

if (!$orcamento_id && !$obra_id) {
    ob_end_clean();
    die("Erro: O planeamento precisa de estar associado a um Orçamento ou a uma Obra.");
}

$id_filtro = $orcamento_id ?: $obra_id;
$col_filtro = $orcamento_id ? 'orcamento_id' : 'obra_id';

// --- BUSCAR DADOS DO CABEÇALHO ---
try {
    if ($orcamento_id) {
        $stmt = $pdo->prepare("SELECT o.numero_orcamento as numero, o.nome_obra, o.contacto_obra, o.morada_obra, o.referencia_cliente, c.nome as cliente_nome, c.nif as cliente_nif
                               FROM orcamentos o
                               LEFT JOIN clientes c ON o.id_cliente = c.id
                               WHERE o.id = ?");
        $stmt->execute([$orcamento_id]);
        $pai = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$pai) throw new Exception("Orçamento não encontrado.");
        
        $titulo_contexto = 'PLANEAMENTO DO ORÇAMENTO Nº ' . $pai['numero'];
        
    } else {
        $stmt = $pdo->prepare("SELECT o.nome as nome_obra, o.contacto as contacto_obra, o.morada as morada_obra, o.referencia_obra as referencia_cliente, c.nome as cliente_nome, c.nif as cliente_nif
                               FROM obras o
                               LEFT JOIN clientes c ON o.id_cliente = c.id
                               WHERE o.id = ?");
        $stmt->execute([$obra_id]);
        $pai = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$pai) throw new Exception("Obra não encontrada.");
        
        $titulo_contexto = 'PLANEAMENTO DA OBRA: ' . mb_strtoupper($pai['nome_obra'], 'UTF-8');
    }

    // --- BUSCAR DADOS DO PLANEAMENTO (NOVA LÓGICA DA TABELA ÚNICA planeamento_linhas) ---
    $stmt_linhas = $pdo->prepare("SELECT * FROM planeamento_linhas WHERE $col_filtro = ? ORDER BY numero_fase ASC, numero_dia ASC, id ASC");
    $stmt_linhas->execute([$id_filtro]);
    $planeamento_atual = $stmt_linhas->fetchAll(PDO::FETCH_ASSOC);

} catch (Exception $e) {
    ob_end_clean();
    die("Erro na BD: " . htmlspecialchars($e->getMessage()));
}

// REGRA DE OURO: Função de encoding SUPER segura para FPDF
function pt($texto) {
    $texto = (string)($texto ?? '');
    return mb_convert_encoding($texto, 'windows-1252', 'UTF-8');
}

// --- CLASSE PDF CUSTOMIZADA ---
class PDF extends FPDF {
    public $titulo_doc;
    
    function RoundedRect($x, $y, $w, $h, $r, $style = '') {
        $k = $this->k; $hp = $this->h;
        if($style=='F') $op='f'; elseif($style=='FD' || $style=='DF') $op='B'; else $op='S';
        $MyArc = 4/3 * (sqrt(2) - 1);
        $this->_out(sprintf('%.2F %.2F m',($x+$r)*$k,($hp-$y)*$k ));
        $xc = $x+$w-$r ; $yc = $y+$r;
        $this->_out(sprintf('%.2F %.2F l', $xc*$k,($hp-$y)*$k ));
        $this->_Arc($xc + $r*$MyArc, $yc - $r, $xc + $r, $yc - $r*$MyArc, $xc + $r, $yc);
        $xc = $x+$w-$r ; $yc = $y+$h-$r;
        $this->_out(sprintf('%.2F %.2F l',($x+$w)*$k,($hp-$yc)*$k));
        $this->_Arc($xc + $r, $yc + $r*$MyArc, $xc + $r*$MyArc, $yc + $r, $xc, $yc + $r);
        $xc = $x+$r ; $yc = $y+$h-$r;
        $this->_out(sprintf('%.2F %.2F l',$xc*$k,($hp-($y+$h))*$k));
        $this->_Arc($xc - $r*$MyArc, $yc + $r, $xc - $r, $yc + $r*$MyArc, $xc - $r, $yc);
        $xc = $x+$r ; $yc = $y+$r;
        $this->_out(sprintf('%.2F %.2F l',($x)*$k,($hp-$yc)*$k ));
        $this->_Arc($xc - $r, $yc - $r*$MyArc, $xc - $r*$MyArc, $yc - $r, $xc, $yc - $r);
        $this->_out($op);
    }

    function _Arc($x1, $y1, $x2, $y2, $x3, $y3) {
        $h = $this->h;
        $this->_out(sprintf('%.2F %.2F %.2F %.2F %.2F %.2F c ', $x1*$this->k, ($h-$y1)*$this->k,
            $x2*$this->k, ($h-$y2)*$this->k, $x3*$this->k, ($h-$y3)*$this->k));
    }

    function NbLines($w, $txt) {
        $cw = &$this->CurrentFont['cw'];
        if($w == 0) $w = $this->w - $this->rMargin - $this->x;
        $wmax = ($w - 2 * $this->cMargin) * 1000 / $this->FontSize;
        $s = str_replace("\r", '', $txt);
        $nb = strlen($s);
        if($nb > 0 && $s[$nb-1] == "\n") $nb--;
        $sep = -1; $i = 0; $j = 0; $l = 0; $nl = 1;
        while($i < $nb) {
            $c = $s[$i];
            if($c == "\n") { $i++; $sep = -1; $j = $i; $l = 0; $nl++; continue; }
            if($c == ' ') $sep = $i;
            $l += $cw[$c];
            if($l > $wmax) {
                if($sep == -1) { if($i == $j) $i++; } else $i = $sep + 1;
                $sep = -1; $j = $i; $l = 0; $nl++;
            } else $i++;
        }
        return $nl;
    }
    
    function Header() {
        if (file_exists('mab.png')) {
            $this->Image('mab.png', 10, 10, 17.5);
        }
        $this->SetY(12);
        $this->SetFont('Arial', 'B', 13);
        $this->SetTextColor(179, 42, 42); 
        $this->Cell(0, 8, pt($this->titulo_doc), 0, 1, 'R');
        $this->Ln(10);
        $y = $this->GetY();
        $this->SetDrawColor(179, 42, 42); 
        $this->SetLineWidth(0.5);
        $this->Line(10, $y, 200, $y);
        $this->Ln(5);
    }

    function Footer() {
        $this->SetY(-15);
        $this->SetDrawColor(179, 42, 42); 
        $this->SetLineWidth(0.5);
        $this->Line(10, $this->GetY(), 200, $this->GetY());
        $this->Ln(2);
        $this->SetFont('Arial', '', 8);
        $this->SetTextColor(0, 0, 0);
        $linha1 = pt("m. Abreu - Instalações Técnicas, Lda. | NIF: 514 414 120");
        $linha2 = pt("Rua Damião de Góis, 205, Braga | Email: marioabreu@mabreu.pt | Tel: 914 259 487");
        $y_footer = $this->GetY();
        $this->Cell(0, 4, $linha1, 0, 0, 'C');
        $this->SetXY(10, $y_footer);
        $this->Cell(0, 4, pt('Página ') . $this->PageNo() . '/{nb}', 0, 1, 'R');
        $this->Cell(0, 4, $linha2, 0, 1, 'C');
    }
}

// INICIALIZAR PDF
$pdf = new PDF();
$pdf->titulo_doc = $titulo_contexto;
$pdf->AliasNbPages();
$pdf->AddPage();
$pdf->SetAutoPageBreak(false); 

// --- BLOCO: IDENTIFICAÇÃO ---
$pdf->SetTextColor(0, 0, 0);
$pdf->SetFont('Arial', 'B', 8);
$pdf->Cell(0, 5, pt('CLIENTE'), 0, 1, 'L');

$pdf->SetFont('Arial', 'B', 10);
$pdf->Cell(145, 5, pt($pai['cliente_nome']), 0, 0, 'L');
$pdf->SetFont('Arial', '', 9);
$pdf->Cell(20, 5, pt('Data Impressão:'), 0, 0, 'R');
$pdf->SetFont('Arial', 'B', 9);
$pdf->Cell(25, 5, date('d/m/Y'), 0, 1, 'R');

$pdf->SetFont('Arial', '', 9);
$pdf->Cell(10, 5, pt('NIF: '), 0, 0, 'L');
$pdf->Cell(180, 5, pt($pai['cliente_nif']), 0, 1, 'L');

$pdf->Ln(4);

// QUADRO DA OBRA
$y_box = $pdf->GetY();
$pdf->SetDrawColor(220, 220, 220);
$pdf->RoundedRect(10, $y_box, 190, 14, 2); 
$pdf->Line(135, $y_box + 2, 135, $y_box + 12);
$pdf->SetY($y_box + 2);

$pdf->SetX(12);
$pdf->SetFont('Arial', 'B', 9);
$pdf->Cell(12, 5, pt('Obra: '), 0, 0, 'L');
$pdf->SetFont('Arial', '', 9);
$pdf->Cell(105, 5, pt($pai['nome_obra'] ?? 'N/D'), 0, 0, 'L');

$pdf->SetX(135);
$pdf->SetFont('Arial', 'B', 9);
$pdf->Cell(20, 5, pt('Contacto:'), 0, 0, 'R');
$pdf->SetFont('Arial', '', 9);
$pdf->Cell(33, 5, pt($pai['contacto_obra'] ?? 'N/D'), 0, 1, 'R');

$pdf->SetX(12);
$pdf->SetFont('Arial', 'B', 9);
$pdf->Cell(16, 5, pt('Morada: '), 0, 0, 'L');
$pdf->SetFont('Arial', '', 9);
$pdf->Cell(101, 5, pt($pai['morada_obra'] ?? 'N/D'), 0, 0, 'L');

$pdf->SetX(135);
$pdf->SetFont('Arial', 'B', 9);
$pdf->Cell(25, 5, pt('Ref. Cliente:'), 0, 0, 'R');
$pdf->SetFont('Arial', '', 9);
$pdf->Cell(28, 5, pt($pai['referencia_cliente'] ?? 'N/D'), 0, 1, 'R');

$pdf->Ln(7);

$y = $pdf->GetY();
$pdf->SetDrawColor(179, 42, 42); 
$pdf->SetLineWidth(0.5);
$pdf->Line(10, $y, 200, $y);
$pdf->Ln(5);

// --- TABELA DO PLANEAMENTO (MODO LISTAGEM IGUAL AO ECRÃ) ---
if (empty($planeamento_atual)) {
    $pdf->SetFont('Arial', 'I', 10);
    $pdf->SetTextColor(100, 100, 100);
    $pdf->Cell(0, 20, pt('Nenhum planeamento definido.'), 0, 1, 'C');
} else {
    // Larguras exatas das 8 colunas (Soma = 190mm, a largura disponível na folha)
    $w = [12, 35, 12, 12, 16, 75, 16, 12]; 
    // Indices: 0: Fase, 1: Nome da Fase, 2: Dia, 3: Hom., 4: Equipa, 5: Descrição da Tarefa, 6: Tempo(h), 7: Est.
    
    $draw_header = function() use ($pdf, $w) {
        $pdf->SetFont('Arial', 'B', 7);
        $pdf->SetFillColor(245, 245, 245);
        $pdf->SetDrawColor(200, 200, 200); 
        $pdf->SetLineWidth(0.2);
        
        $pdf->Cell($w[0], 7, pt('Fase'), 'T,B', 0, 'C', true); 
        $pdf->Cell($w[1], 7, pt('Nome da Fase'), 'T,B', 0, 'L', true);
        $pdf->Cell($w[2], 7, pt('Dia'), 'T,B', 0, 'C', true);
        $pdf->Cell($w[3], 7, pt('Hom.'), 'T,B', 0, 'C', true);
        $pdf->Cell($w[4], 7, pt('Equipa'), 'T,B', 0, 'C', true);
        $pdf->Cell($w[5], 7, pt('Descrição da Tarefa'), 'T,B', 0, 'L', true);
        $pdf->Cell($w[6], 7, pt('Tempo'), 'T,B', 0, 'C', true);
        $pdf->Cell($w[7], 7, pt('Concl.'), 'T,B', 1, 'C', true);
    };

    $draw_header();
    
    foreach ($planeamento_atual as $linha) {
        // Obter os textos (convertidos para PT-PT)
        $tarefa_txt = pt(trim($linha['descricao_tarefa']));
        $nome_txt = pt(trim($linha['nome_fase']));
        
        // Calcular quantas linhas de altura o texto vai ocupar para não cortar
        $lines_tarefa = $pdf->NbLines($w[5], $tarefa_txt);
        $lines_nome = $pdf->NbLines($w[1], $nome_txt);
        $max_lines = max(1, $lines_tarefa, $lines_nome);
        
        $h = 5 * $max_lines;
        if ($h < 8) $h = 8; // Altura mínima por decência
        
        // Se passar o limite da folha, quebra página
        if ($pdf->GetY() + $h > 270) {
            $pdf->AddPage();
            $draw_header();
        }
        
        $x = $pdf->GetX(); $y = $pdf->GetY();
        
        $pdf->SetFont('Arial', '', 7);
        $pdf->SetTextColor(0, 0, 0);
        
        // 1. Coluna Fase
        $pdf->SetXY($x, $y);
        $pdf->Cell($w[0], $h, $linha['numero_fase'], 0, 0, 'C');
        
        // 2. Coluna Nome (MultiCell)
        $y_nome = $y + max(0, ($h - ($lines_nome * 5)) / 2); // Centrar verticalmente
        $pdf->SetXY($x + $w[0], $y_nome); 
        $pdf->MultiCell($w[1], 5, $nome_txt, 0, 'L');
        
        // 3. Coluna Dia
        $pdf->SetXY($x + $w[0] + $w[1], $y);
        $pdf->Cell($w[2], $h, $linha['numero_dia'], 0, 0, 'C');
        
        // 4. Coluna Homens
        $pdf->SetXY($x + $w[0] + $w[1] + $w[2], $y);
        $pdf->Cell($w[3], $h, floatval(str_replace(',', '.', $linha['numero_homens'])), 0, 0, 'C');
        
        // 5. Coluna Equipa
        $pdf->SetXY($x + $w[0] + $w[1] + $w[2] + $w[3], $y);
        $pdf->Cell($w[4], $h, pt($linha['tipo_equipa']), 0, 0, 'C');
        
        // 6. Coluna Tarefa (MultiCell)
        $y_tarefa = $y + max(0, ($h - ($lines_tarefa * 5)) / 2); // Centrar verticalmente
        $pdf->SetXY($x + $w[0] + $w[1] + $w[2] + $w[3] + $w[4], $y_tarefa);
        $pdf->MultiCell($w[5], 5, $tarefa_txt, 0, 'L');
        
        // 7. Coluna Tempo
        $pdf->SetXY($x + $w[0] + $w[1] + $w[2] + $w[3] + $w[4] + $w[5], $y);
        $pdf->Cell($w[6], $h, floatval(str_replace(',', '.', $linha['tempo_tarefa'])), 0, 0, 'C');
        
        // 8. Coluna Estado (Concluída)
        $pdf->SetXY($x + $w[0] + $w[1] + $w[2] + $w[3] + $w[4] + $w[5] + $w[6], $y);
        $pdf->SetFont('Arial', 'B', 8);
        if ($linha['concluida'] == 1) {
            $pdf->SetTextColor(39, 174, 96); // Verde
            $pdf->Cell($w[7], $h, '[ X ]', 0, 0, 'C');
        } else {
            $pdf->SetTextColor(231, 76, 60); // Vermelho
            $pdf->Cell($w[7], $h, '[   ]', 0, 0, 'C');
        }
        
        // Restaura fonte para a proxima volta e desenha a linha separadora fina
        $pdf->SetY($y + $h);
        $pdf->SetDrawColor(230, 230, 230); 
        $pdf->Line(10, $pdf->GetY(), 200, $pdf->GetY());
    }
}

ob_end_clean();
$filename = 'Planeamento_' . ($orcamento_id ? 'Orc_' . ($pai['numero'] ?? 'ND') : 'Obra_' . $obra_id) . '.pdf';
$pdf->Output('I', preg_replace('/[^A-Za-z0-9_.-]/', '_', $filename));
?>