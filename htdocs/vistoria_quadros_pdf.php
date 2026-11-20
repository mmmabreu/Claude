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

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$id) { 
    ob_end_clean();
    die("Erro: ID de vistoria inválido."); 
}

// --- BUSCAR DADOS DA VISTORIA ---
try {
    $sql = "SELECT v.*, c.nome as cliente_nome, c.morada as cliente_morada, 
                   c.codigo_postal as cliente_cp, c.cidade as cliente_cidade, 
                   c.nif as cliente_nif
            FROM vistorias_quadros v
            LEFT JOIN clientes c ON v.id_cliente = c.id
            WHERE v.id = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$id]);
    $doc = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$doc) { 
        ob_end_clean();
        die("Erro: Registo não encontrado."); 
    }

    // Buscar as linhas dos quadros
    $stmt_linhas = $pdo->prepare("SELECT * FROM vistorias_quadros_linhas WHERE id_vistoria = ? ORDER BY id ASC");
    $stmt_linhas->execute([$id]);
    $linhas = $stmt_linhas->fetchAll(PDO::FETCH_ASSOC);

} catch (Exception $e) {
    ob_end_clean();
    die("Erro na BD: " . htmlspecialchars($e->getMessage()));
}

// Função de encoding para FPDF
function pt($texto) {
    $texto = (string)($texto ?? '');
    return mb_convert_encoding($texto, 'windows-1252', 'UTF-8');
}

// Otimização da imagem do logotipo
function otimizarImagem($ficheiro) {
    if (!file_exists($ficheiro)) return false;
    $nome_otimizado = 'min_' . $ficheiro;
    if (file_exists($nome_otimizado)) return $nome_otimizado;
    
    $info = @getimagesize($ficheiro);
    if (!$info) return $ficheiro;
    
    $largura = $info[0]; $altura = $info[1]; $tipo_img = $info[2];
    $max_largura = 350;
    
    if ($largura <= $max_largura) return $ficheiro; 
    
    $nova_largura = $max_largura;
    $nova_altura = floor(($altura / $largura) * $nova_largura);
    $nova_img = imagecreatetruecolor($nova_largura, $nova_altura);
    
    if ($tipo_img == IMAGETYPE_PNG) {
        imagealphablending($nova_img, false); imagesavealpha($nova_img, true);
        $transparente = imagecolorallocatealpha($nova_img, 255, 255, 255, 127);
        imagefilledrectangle($nova_img, 0, 0, $nova_largura, $nova_altura, $transparente);
        $origem = @imagecreatefrompng($ficheiro);
    } elseif ($tipo_img == IMAGETYPE_JPEG) {
        $origem = @imagecreatefromjpeg($ficheiro);
    } else { return $ficheiro; }
    
    if ($origem) {
        if ($tipo_img == IMAGETYPE_PNG) imagealphablending($origem, true);
        imagecopyresampled($nova_img, $origem, 0, 0, 0, 0, $nova_largura, $nova_altura, $largura, $altura);
        if ($tipo_img == IMAGETYPE_PNG) imagepng($nova_img, $nome_otimizado, 9);
        else imagejpeg($nova_img, $nome_otimizado, 80);
        imagedestroy($nova_img); imagedestroy($origem);
        return $nome_otimizado;
    }
    return $ficheiro;
}

// --- CLASSE PDF CUSTOMIZADA ---
class PDF extends FPDF {
    public $titulo_doc;
    
    function Header() {
        $this->SetTopMargin(35);
        
        $logo_bottom_y = 10;
        $logo = otimizarImagem('mab.png');
        if ($logo && file_exists($logo)) {
            $this->Image($logo, 10, 10, 16); 
            $logo_bottom_y = 10 + 16; // Posição Y do topo (10) + Altura do logo (16)
        }
        
        $this->SetY(12);
        $this->SetFont('Arial', 'B', 14);
        $this->SetTextColor(44, 62, 80); 
        $this->Cell(0, 8, pt($this->titulo_doc), 0, 1, 'R');
        
        // Garante que a linha fica sempre abaixo do elemento mais baixo (logo ou texto)
        $y_text = $this->GetY();
        $y_line = max($y_text, $logo_bottom_y) + 3;
        
        $this->SetDrawColor(44, 62, 80); 
        $this->SetLineWidth(0.5);
        $this->Line(10, $y_line, 200, $y_line);
        
        // Afasta o conteúdo da tabela para não colar à linha
        $this->SetY($y_line + 5);
    }

    function Footer() {
        $this->SetY(-15);
        $this->SetDrawColor(200, 200, 200); 
        $this->SetLineWidth(0.2);
        $this->Line(10, $this->GetY(), 200, $this->GetY());
        $this->Ln(2);
        $this->SetFont('Arial', 'B', 8);
        $this->SetTextColor(100, 100, 100);
        $linha1 = pt("m. Abreu - Instalações Técnicas, Lda. | NIF: 514 414 120");
        $linha2 = pt("Rua Damião de Góis, 205, Braga | Email: geral@mabreu.pt");
        $y_footer = $this->GetY();
        $this->Cell(0, 4, $linha1, 0, 0, 'C');
        $this->SetXY(10, $y_footer);
        $this->Cell(0, 4, pt('Página ') . $this->PageNo() . '/{nb}', 0, 1, 'R');
        $this->SetFont('Arial', '', 8);
        $this->Cell(0, 4, $linha2, 0, 1, 'C');
    }

    function DrawBoxTitle($title) {
        $this->Ln(2);
        $this->SetFont('Arial', 'B', 9);
        $this->SetFillColor(241, 242, 246);
        $this->SetDrawColor(200, 200, 200);
        $this->Cell(0, 7, pt(' ' . $title), 1, 1, 'L', true);
        $this->SetFont('Arial', '', 9);
    }

    function DrawRow($label1, $val1, $label2 = '', $val2 = '') {
        $this->SetFont('Arial', 'B', 9);
        $this->Cell(35, 6, pt($label1), 'L,B', 0);
        $this->SetFont('Arial', '', 9);
        if ($label2 !== '') {
            $this->Cell(60, 6, pt($val1), 'B', 0);
            $this->SetFont('Arial', 'B', 9);
            $this->Cell(35, 6, pt($label2), 'L,B', 0);
            $this->SetFont('Arial', '', 9);
            $this->Cell(60, 6, pt($val2), 'R,B', 1);
        } else {
            $this->Cell(155, 6, pt($val1), 'R,B', 1);
        }
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
                if($sep == -1) { if($i == $j) $i++; }
                else $i = $sep + 1;
                $sep = -1; $j = $i; $l = 0; $nl++;
            } else $i++;
        }
        return $nl;
    }
}

// INICIALIZAR PDF
$pdf = new PDF('P', 'mm', 'A4');
$pdf->titulo_doc = 'VISTORIA DE QUADROS ELÉTRICOS #' . $doc['referencia'];
$pdf->SetMargins(10, 35, 10); // Aumentada a margem principal do topo para bater certo com a nova linha
$pdf->AliasNbPages();
$pdf->AddPage();
$pdf->SetAutoPageBreak(true, 20);

// --- IDENTIFICAÇÃO GERAL ---
$morada_cli = trim($doc['cliente_morada'] . ' ' . $doc['cliente_cp'] . ' ' . $doc['cliente_cidade']);

$pdf->DrawBoxTitle('DADOS DO CLIENTE E LOCAL');
$pdf->DrawRow('Cliente:', $doc['cliente_nome'], 'Data Vistoria:', date('d/m/Y', strtotime($doc['data_vistoria'])));
$pdf->DrawRow('NIF:', $doc['cliente_nif'], 'Ref. Interna:', $doc['referencia']);
$pdf->DrawRow('Morada Sede:', $morada_cli);
$pdf->DrawRow('Local Obra:', $doc['nome_obra']);
if (!empty(trim($doc['morada_obra']))) {
    $pdf->DrawRow('Morada Obra:', $doc['morada_obra']);
}

// --- TABELA DE QUADROS ---
$pdf->DrawBoxTitle('QUADROS ELÉTRICOS INSPECIONADOS');

if (empty($linhas)) {
    $pdf->SetFont('Arial', 'I', 9);
    $pdf->Cell(0, 10, pt('Nenhum quadro elétrico registado nesta vistoria.'), 'L,R,B', 1, 'C');
} else {
    // Cabeçalho da Tabela
    $pdf->SetFont('Arial', 'B', 8);
    $pdf->SetFillColor(248, 249, 250);
    
    // Total redistribuído = 190mm
    $w = [15, 28, 18, 18, 18, 24, 13, 56]; 
    
    $pdf->Cell($w[0], 7, pt(' Nº Q.'), 'L,B', 0, 'C', true);
    $pdf->Cell($w[1], 7, pt(' Localização'), 'L,B', 0, 'L', true);
    $pdf->Cell($w[2], 7, pt(' Fases'), 'L,B', 0, 'L', true);
    $pdf->Cell($w[3], 7, pt(' I. Nom.'), 'L,B', 0, 'L', true);
    $pdf->Cell($w[4], 7, pt(' Dif. (mA)'), 'L,B', 0, 'C', true);
    $pdf->Cell($w[5], 7, pt(' Cabo Ent.'), 'L,B', 0, 'L', true);
    $pdf->Cell($w[6], 7, pt(' Estado'), 'L,B', 0, 'C', true);
    $pdf->Cell($w[7], 7, pt(' Observações'), 'L,R,B', 1, 'L', true);

    $pdf->SetFont('Arial', '', 8);
    $lh = 6; // Altura base da linha
    
    foreach ($linhas as $linha) {
        $txt_nq = pt($linha['n_quadro']);
        $txt_loc = pt($linha['localizacao']);
        
        // Fases
        $is_tri = (isset($linha['fases']) && ($linha['fases'] === 'Tri' || $linha['fases'] === 'Trifásico'));
        $txt_fases = $is_tri ? pt('Trifásico') : pt('Monofásico');
        
        $txt_in = pt($linha['corrente_nominal']);
        
        // Diferencial
        $txt_dif = '-';
        if (!empty($linha['protecao_diferencial'])) {
            $txt_dif = pt($linha['protecao_diferencial'] . ' mA');
        }
        
        $txt_cabo = pt($linha['cabo_entrada']);
        $txt_estado = pt($linha['estado']);
        $txt_obs = pt($linha['observacoes']);
        
        // Calcular número de linhas para a célula que precisar de mais quebras
        $n1 = $pdf->NbLines($w[0], $txt_nq);
        $n2 = $pdf->NbLines($w[1], $txt_loc);
        $n3 = $pdf->NbLines($w[2], $txt_fases);
        $n4 = $pdf->NbLines($w[3], $txt_in);
        $n5 = $pdf->NbLines($w[4], $txt_dif);
        $n6 = $pdf->NbLines($w[5], $txt_cabo);
        $n7 = $pdf->NbLines($w[6], $txt_estado);
        $n8 = $pdf->NbLines($w[7], $txt_obs);
        $nLines = max($n1, $n2, $n3, $n4, $n5, $n6, $n7, $n8);
        
        $h = $lh * $nLines;
        
        // Adicionar página se necessário
        if ($pdf->GetY() + $h > 270) {
            $pdf->AddPage();
            // Re-desenhar cabeçalho
            $pdf->SetFont('Arial', 'B', 8);
            $pdf->Cell($w[0], 7, pt(' Nº Q.'), 'L,T,B', 0, 'C', true);
            $pdf->Cell($w[1], 7, pt(' Localização'), 'L,T,B', 0, 'L', true);
            $pdf->Cell($w[2], 7, pt(' Fases'), 'L,T,B', 0, 'L', true);
            $pdf->Cell($w[3], 7, pt(' I. Nom.'), 'L,T,B', 0, 'L', true);
            $pdf->Cell($w[4], 7, pt(' Dif. (mA)'), 'L,T,B', 0, 'C', true);
            $pdf->Cell($w[5], 7, pt(' Cabo Ent.'), 'L,T,B', 0, 'L', true);
            $pdf->Cell($w[6], 7, pt(' Estado'), 'L,T,B', 0, 'C', true);
            $pdf->Cell($w[7], 7, pt(' Observações'), 'L,R,T,B', 1, 'L', true);
            $pdf->SetFont('Arial', '', 8);
        }

        $x = $pdf->GetX(); $y = $pdf->GetY();
        
        $pdf->SetXY($x, $y);
        $pdf->MultiCell($w[0], $lh, $txt_nq, 0, 'C');
        
        $pdf->SetXY($x + $w[0], $y);
        $pdf->MultiCell($w[1], $lh, $txt_loc, 0, 'L');
        
        $pdf->SetXY($x + $w[0] + $w[1], $y);
        $pdf->MultiCell($w[2], $lh, $txt_fases, 0, 'L');

        $pdf->SetXY($x + $w[0] + $w[1] + $w[2], $y);
        $pdf->MultiCell($w[3], $lh, $txt_in, 0, 'L');
        
        $pdf->SetXY($x + $w[0] + $w[1] + $w[2] + $w[3], $y);
        $pdf->MultiCell($w[4], $lh, $txt_dif, 0, 'C');
        
        $pdf->SetXY($x + $w[0] + $w[1] + $w[2] + $w[3] + $w[4], $y);
        $pdf->MultiCell($w[5], $lh, $txt_cabo, 0, 'L');
        
        // Estado (Com Cor Inteligente)
        $pdf->SetXY($x + $w[0] + $w[1] + $w[2] + $w[3] + $w[4] + $w[5], $y);
        $pdf->SetFont('Arial', 'B', 8);
        if ($txt_estado === 'NOK') {
            $pdf->SetTextColor(231, 76, 60); 
        } else {
            $pdf->SetTextColor(39, 174, 96); 
        }
        $pdf->MultiCell($w[6], $lh, $txt_estado, 0, 'C');
        $pdf->SetTextColor(0, 0, 0); // Reset Cor
        $pdf->SetFont('Arial', '', 8); // Reset Font
        
        $pdf->SetXY($x + $w[0] + $w[1] + $w[2] + $w[3] + $w[4] + $w[5] + $w[6], $y);
        $pdf->MultiCell($w[7], $lh, $txt_obs, 0, 'L');

        // Retomar posição Y e desenhar as linhas de divisão da tabela
        $pdf->SetY($y + $h);
        $pdf->SetDrawColor(220, 220, 220);
        
        // 9 Linhas verticais (para formar 8 colunas)
        $pdf->Line($x, $y, $x, $y + $h); 
        $pdf->Line($x + $w[0], $y, $x + $w[0], $y + $h); 
        $pdf->Line($x + $w[0] + $w[1], $y, $x + $w[0] + $w[1], $y + $h); 
        $pdf->Line($x + $w[0] + $w[1] + $w[2], $y, $x + $w[0] + $w[1] + $w[2], $y + $h); 
        $pdf->Line($x + $w[0] + $w[1] + $w[2] + $w[3], $y, $x + $w[0] + $w[1] + $w[2] + $w[3], $y + $h); 
        $pdf->Line($x + $w[0] + $w[1] + $w[2] + $w[3] + $w[4], $y, $x + $w[0] + $w[1] + $w[2] + $w[3] + $w[4], $y + $h); 
        $pdf->Line($x + $w[0] + $w[1] + $w[2] + $w[3] + $w[4] + $w[5], $y, $x + $w[0] + $w[1] + $w[2] + $w[3] + $w[4] + $w[5], $y + $h); 
        $pdf->Line($x + $w[0] + $w[1] + $w[2] + $w[3] + $w[4] + $w[5] + $w[6], $y, $x + $w[0] + $w[1] + $w[2] + $w[3] + $w[4] + $w[5] + $w[6], $y + $h); 
        $pdf->Line(200, $y, 200, $y + $h); 
        
        // Linha horizontal base
        $pdf->Line(10, $pdf->GetY(), 200, $pdf->GetY()); 
    }
}

// --- OBSERVAÇÕES GERAIS ---
if (!empty(trim($doc['observacoes_gerais']))) {
    $pdf->DrawBoxTitle('OBSERVAÇÕES GERAIS DA VISTORIA');
    $pdf->Ln(2);
    $pdf->SetFont('Arial', '', 9);
    $pdf->MultiCell(0, 5, pt(trim($doc['observacoes_gerais'])), 0, 'J');
    $pdf->Ln(4);
}

// --- NOTA FINAL DE CONCLUSÃO E VALIDADE ---
$pdf->Ln(6);
$pdf->SetFont('Arial', 'B', 10);
$pdf->SetTextColor(44, 62, 80); 
$pdf->Cell(0, 6, pt('NOTAS DA VISTORIA E CONCLUSÃO'), 0, 1, 'L');
$pdf->SetTextColor(0, 0, 0); 
$pdf->SetFont('Arial', '', 8);

$texto_conclusao = "Este documento atesta a realização de uma vistoria técnica e visual aos quadros elétricos supracitados, no âmbito da verificação do estado de conservação, dimensionamento e ligações dos mesmos à data da visita.\n\nAs irregularidades (classificadas como NOK) detetadas e descritas nas observações devem ser alvo de intervenção corretiva de forma a garantir a proteção de pessoas e bens, e a conformidade legal e técnica das instalações elétricas do edifício.";

$pdf->MultiCell(0, 4.5, pt($texto_conclusao), 0, 'J');

// Indicação de Validade
$pdf->Ln(4);
$pdf->SetFont('Arial', 'B', 8);
$pdf->Cell(0, 5, pt('Validade do Relatório: 1 (um) ano a contar da data da vistoria.'), 0, 1, 'L');

// --- ASSINATURAS ---
$pdf->Ln(15);
$y_assinaturas = $pdf->GetY();

// Assinatura Cliente
$pdf->SetFont('Arial', 'B', 9);
$pdf->SetXY(20, $y_assinaturas);
$pdf->Cell(70, 5, pt('O Cliente / Responsável'), 0, 1, 'C');
$pdf->Line(20, $pdf->GetY() + 12, 90, $pdf->GetY() + 12);
$pdf->SetXY(20, $pdf->GetY() + 15);
$pdf->SetFont('Arial', '', 8);
$pdf->Cell(70, 5, pt($doc['cliente_nome']), 0, 0, 'C');

// Assinatura Técnico
$pdf->SetFont('Arial', 'B', 9);
$pdf->SetXY(115, $y_assinaturas);
$pdf->Cell(70, 5, pt('A Empresa Instaladora / Técnico'), 0, 1, 'C');
$y_linha_gerencia = $pdf->GetY() + 12;
$pdf->Line(115, $y_linha_gerencia, 185, $y_linha_gerencia);

// ASSINATURA OTIMIZADA (m. Abreu)
$assina = otimizarImagem('assina.png');
if ($assina && file_exists($assina)) {
    $img_width = 35;
    $img_height = 13;
    $x_center = 115 + (70 - $img_width) / 2; 
    $y_img = $y_linha_gerencia - $img_height + 1; 
    $pdf->Image($assina, $x_center, $y_img, $img_width, $img_height);
}

ob_end_clean();
$filename = 'Vistoria_Quadros_' . preg_replace('/[^A-Za-z0-9_-]/', '_', $doc['cliente_nome']) . '_' . $doc['referencia'] . '.pdf';
$pdf->Output('I', pt($filename));
?>