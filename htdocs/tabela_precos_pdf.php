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

// Função Pro para Limpeza Numérica
function limparNumero($num) {
    if (empty(trim((string)$num))) return 0;
    $num = str_replace(['.', ' '], '', (string)$num);
    $num = str_replace(',', '.', $num);
    return floatval($num);
}

// Obter o Valor Hora/Homem das Configurações
try {
    $stmt_hora = $pdo->prepare("SELECT valor FROM configuracoes WHERE chave = 'valor_hora' LIMIT 1");
    $stmt_hora->execute();
    $valor_hora_homem_str = $stmt_hora->fetchColumn();
    $valor_hora_homem = $valor_hora_homem_str ? limparNumero($valor_hora_homem_str) : 0;

    // Buscar Artigos
    $stmt = $pdo->query("SELECT * FROM tabela_precos ORDER BY referencia ASC");
    $artigos = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    ob_end_clean();
    die("Erro na BD: " . htmlspecialchars($e->getMessage()));
}

// Função de encoding SUPER segura para FPDF
function pt($texto) {
    $texto = (string)($texto ?? '');
    return mb_convert_encoding($texto, 'windows-1252', 'UTF-8');
}

// --- CLASSE PDF CUSTOMIZADA EM MODO PAISAGEM ---
class PDF extends FPDF {
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
        $this->SetFont('Arial', 'B', 15);
        $this->SetTextColor(44, 62, 80); 
        $this->Cell(0, 8, pt('TABELA DE PREÇOS GERAL'), 0, 1, 'R');
        
        $this->SetFont('Arial', '', 9);
        $this->SetTextColor(127, 140, 141);
        $this->Cell(0, 5, pt('Data de Emissão: ' . date('d/m/Y')), 0, 1, 'R');

        $this->Ln(8);
        $y = $this->GetY();
        $this->SetDrawColor(52, 152, 219); 
        $this->SetLineWidth(0.5);
        $this->Line(10, $y, 287, $y);
        $this->Ln(5);
    }

    function Footer() {
        $this->SetY(-15);
        $this->SetDrawColor(52, 152, 219); 
        $this->SetLineWidth(0.5);
        $this->Line(10, $this->GetY(), 287, $this->GetY());
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

// INICIALIZAR PDF EM MODO PAISAGEM ('L' = Landscape)
$pdf = new PDF('L', 'mm', 'A4');
$pdf->AliasNbPages();
$pdf->AddPage();
$pdf->SetAutoPageBreak(false); 

if (empty($artigos)) {
    $pdf->SetFont('Arial', 'I', 11);
    $pdf->SetTextColor(100, 100, 100);
    $pdf->Cell(0, 20, pt('Nenhum artigo encontrado na tabela de preços.'), 0, 1, 'C');
} else {
    // LARGURAS DAS COLUNAS (Soma total = 277mm para ocupar a folha A4 deitada)
    $w = [30, 95, 12, 20, 15, 22, 18, 15, 22, 28]; 
    // 0:Ref | 1:Desc | 2:Un | 3:Custo Base | 4:Margem | 5:Preço Mat | 6:Minutos | 7:Colabs | 8:Valor MO | 9:Preço Final
    
    $draw_header = function() use ($pdf, $w) {
        $pdf->SetFont('Arial', 'B', 8);
        $pdf->SetFillColor(240, 243, 244);
        $pdf->SetTextColor(44, 62, 80);
        $pdf->SetDrawColor(189, 195, 199); 
        $pdf->SetLineWidth(0.2);
        
        $pdf->Cell($w[0], 8, pt('REFERÊNCIA'), 'T,B', 0, 'L', true); 
        $pdf->Cell($w[1], 8, pt('DESCRIÇÃO DO ARTIGO'), 'T,B', 0, 'L', true);
        $pdf->Cell($w[2], 8, pt('UN'), 'T,B', 0, 'C', true);
        $pdf->Cell($w[3], 8, pt('CUSTO B.'), 'T,B', 0, 'R', true);
        $pdf->Cell($w[4], 8, pt('MARGEM'), 'T,B', 0, 'C', true);
        $pdf->Cell($w[5], 8, pt('PREÇO MAT.'), 'T,B', 0, 'R', true);
        $pdf->Cell($w[6], 8, pt('TEMPO(m)'), 'T,B', 0, 'C', true);
        $pdf->Cell($w[7], 8, pt('COLAB.'), 'T,B', 0, 'C', true);
        $pdf->Cell($w[8], 8, pt('VALOR M.O.'), 'T,B', 0, 'R', true);
        $pdf->Cell($w[9], 8, pt('PREÇO FINAL'), 'T,B', 1, 'R', true);
    };

    $draw_header();
    
    foreach ($artigos as $art) {
        // MATEMÁTICA DA TABELA
        $perc = (int)($art['percentagem_lucro'] ?? 0);
        $custo_base = (float)($art['preco_custo'] ?? 0);
        $tempo_minutos = (float)($art['tempo_mao_obra'] ?? 0);
        $numero_homens = (int)($art['n_colaboradores'] ?? 1);
        
        $preco_material_calculado = $custo_base + ($custo_base * ($perc / 100));
        $valor_mo_calculado = ($tempo_minutos / 60) * $numero_homens * $valor_hora_homem;
        $preco_total_com_mo_calculado = ceil(($preco_material_calculado + $valor_mo_calculado) * 10) / 10;

        // TEXTOS
        $ref_txt = pt(trim($art['referencia']));
        $desc_txt = pt(trim($art['descricao']));
        
        // CALCULAR ALTURA DA LINHA
        $lines_desc = $pdf->NbLines($w[1], $desc_txt);
        $lines_ref = $pdf->NbLines($w[0], $ref_txt);
        $max_lines = max(1, $lines_desc, $lines_ref);
        
        $h = 5 * $max_lines;
        if ($h < 7) $h = 7; 
        
        // QUEBRA DE PÁGINA
        if ($pdf->GetY() + $h > 185) { // 185mm é o limite no rodapé em Landscape
            $pdf->AddPage();
            $draw_header();
        }
        
        $x = $pdf->GetX(); $y = $pdf->GetY();
        
        $pdf->SetFont('Arial', '', 8);
        $pdf->SetTextColor(0, 0, 0);
        
        // 1. Ref (MultiCell)
        $pdf->SetXY($x, $y + max(0, ($h - ($lines_ref * 5)) / 2));
        $pdf->SetFont('Arial', 'B', 8);
        $pdf->MultiCell($w[0], 5, $ref_txt, 0, 'L');
        
        // 2. Desc (MultiCell)
        $pdf->SetXY($x + $w[0], $y + max(0, ($h - ($lines_desc * 5)) / 2));
        $pdf->SetFont('Arial', '', 8);
        $pdf->MultiCell($w[1], 5, $desc_txt, 0, 'L');
        
        // 3. Unid
        $pdf->SetXY($x + $w[0] + $w[1], $y);
        $pdf->Cell($w[2], $h, pt($art['unidade']), 0, 0, 'C');
        
        // 4. Custo Base
        $pdf->SetXY($x + $w[0] + $w[1] + $w[2], $y);
        $pdf->Cell($w[3], $h, number_format($custo_base, 2, ',', ' ') . ' ' . chr(128), 0, 0, 'R'); // chr(128) é o símbolo do Euro no windows-1252
        
        // 5. Margem
        $pdf->SetXY($x + $w[0] + $w[1] + $w[2] + $w[3], $y);
        $pdf->SetTextColor(214, 137, 16);
        $pdf->Cell($w[4], $h, $perc > 0 ? '+' . $perc . '%' : '-', 0, 0, 'C');
        
        // 6. Preço Material
        $pdf->SetXY($x + $w[0] + $w[1] + $w[2] + $w[3] + $w[4], $y);
        $pdf->SetTextColor(39, 174, 96);
        $pdf->Cell($w[5], $h, number_format($preco_material_calculado, 2, ',', '.') . ' ' . chr(128), 0, 0, 'R');
        
        // 7. Tempo
        $pdf->SetXY($x + $w[0] + $w[1] + $w[2] + $w[3] + $w[4] + $w[5], $y);
        $pdf->SetTextColor(0, 0, 0);
        $pdf->Cell($w[6], $h, number_format($tempo_minutos, 1, ',', ''), 0, 0, 'C');
        
        // 8. Colab
        $pdf->SetXY($x + $w[0] + $w[1] + $w[2] + $w[3] + $w[4] + $w[5] + $w[6], $y);
        $pdf->Cell($w[7], $h, $numero_homens, 0, 0, 'C');
        
        // 9. Valor MO
        $pdf->SetXY($x + $w[0] + $w[1] + $w[2] + $w[3] + $w[4] + $w[5] + $w[6] + $w[7], $y);
        $pdf->SetTextColor(41, 128, 185);
        $pdf->Cell($w[8], $h, number_format($valor_mo_calculado, 2, ',', '.') . ' ' . chr(128), 0, 0, 'R');
        
        // 10. Preço Final
        $pdf->SetXY($x + $w[0] + $w[1] + $w[2] + $w[3] + $w[4] + $w[5] + $w[6] + $w[7] + $w[8], $y);
        $pdf->SetFont('Arial', 'B', 9);
        $pdf->SetTextColor(243, 156, 18);
        $pdf->Cell($w[9], $h, number_format($preco_total_com_mo_calculado, 2, ',', '.') . ' ' . chr(128), 0, 0, 'R');
        
        // Linha divisória
        $pdf->SetY($y + $h);
        $pdf->SetDrawColor(230, 230, 230); 
        $pdf->Line(10, $pdf->GetY(), 287, $pdf->GetY());
    }
}

ob_end_clean();
$pdf->Output('I', 'Tabela_de_Precos_MAB.pdf');
?>