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

// Validação rigorosa do ID e do Tipo
$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$tipo = $_GET['tipo'] ?? 'cliente'; // Por defeito é a versão limpa do cliente

if (!$id) { 
    ob_end_clean();
    die("Erro: ID de serviço inválido."); 
}

// Verificar se o utilizador pode ver a versão completa (Apenas Admin/Administrativo)
$perfil = $_SESSION['perfil'] ?? '';
$pode_ver_custos = in_array($perfil, ['admin', 'administrativo']);
$is_admin_view = ($tipo === 'completa' && $pode_ver_custos);

// --- BUSCAR DADOS DO SERVIÇO ---
try {
    $sql = "SELECT s.*, 
                   c.nome AS c_nome, c.nif AS c_nif, c.morada AS c_morada_faturacao, c.codigo_postal AS c_cp_faturacao,
                   u1.nome AS colab1_nome, u2.nome AS colab2_nome
            FROM servicos s
            LEFT JOIN clientes c ON s.id_cliente = c.id
            LEFT JOIN utilizadores u1 ON s.colaborador_1 = u1.id
            LEFT JOIN utilizadores u2 ON s.colaborador_2 = u2.id
            WHERE s.id = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$id]);
    $servico = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$servico) { 
        ob_end_clean();
        die("Erro: Serviço não encontrado."); 
    }

    $materiais = json_decode($servico['materiais'] ?? '[]', true);
    $faturas = json_decode($servico['faturas'] ?? '[]', true);

} catch (Exception $e) {
    ob_end_clean();
    die("Erro na BD: " . htmlspecialchars($e->getMessage()));
}

// CÁLCULO DE HORAS
$total_minutos = 0;
if (!empty($servico['h1_entrada']) && !empty($servico['h1_saida'])) {
    $t1 = strtotime($servico['h1_saida']) - strtotime($servico['h1_entrada']);
    if ($t1 > 0) $total_minutos += $t1 / 60;
}
if (!empty($servico['h2_entrada']) && !empty($servico['h2_saida'])) {
    $t2 = strtotime($servico['h2_saida']) - strtotime($servico['h2_entrada']);
    if ($t2 > 0) $total_minutos += $t2 / 60;
}
$horas = floor($total_minutos / 60);
$minutos = $total_minutos % 60;

$txt_h1 = date('H:i', strtotime($servico['h1_entrada'])) . 'h > ' . date('H:i', strtotime($servico['h1_saida'])) . 'h';
$txt_h2 = '';
if (!empty($servico['h2_entrada'])) {
    $txt_h2 = "\n" . date('H:i', strtotime($servico['h2_entrada'])) . 'h > ' . date('H:i', strtotime($servico['h2_saida'])) . 'h';
}

$tecnicos = $servico['colab1_nome'];
if (!empty($servico['colab2_nome'])) {
    $tecnicos .= "\n" . $servico['colab2_nome'];
}

// Função de encoding para FPDF
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
                if($sep == -1) { if($i == $j) $i++; }
                else $i = $sep + 1;
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
        $this->SetFont('Arial', '', 10);
        $this->SetTextColor(87, 101, 116); 
        $this->Cell(0, 5, pt('Serviço #' . $_GET['id'] . ' | Data: ' . date('d/m/Y')), 0, 1, 'R');
        $this->Ln(8);
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
        $linha2 = pt("Rua Damião de Góis, 205, Braga | Email: marioabreu@mabreu.pt | Tel: 912 854 950");
        $y_footer = $this->GetY();
        $this->Cell(0, 4, $linha1, 0, 0, 'C');
        $this->SetXY(10, $y_footer);
        $this->Cell(0, 4, pt('Página ') . $this->PageNo() . '/{nb}', 0, 1, 'R');
        $this->Cell(0, 4, $linha2, 0, 1, 'C');
    }
}

// INICIALIZAR PDF
$pdf = new PDF();
$tipo_str = ($is_admin_view) ? ' (Gestão)' : '';
$pdf->titulo_doc = 'FOLHA DE SERVIÇO' . $tipo_str;
$pdf->AliasNbPages();
$pdf->AddPage();
$pdf->SetAutoPageBreak(true, 20); 

$y_inicial = $pdf->GetY();

// --- LADO ESQUERDO: INFORMAÇÃO DO CLIENTE ---
$pdf->SetFont('Arial', 'B', 9);
$pdf->SetTextColor(179, 42, 42);
$pdf->Cell(90, 6, pt('CLIENTE'), 'B', 1, 'L');
$pdf->Ln(2);

$pdf->SetFont('Arial', 'B', 7);
$pdf->SetTextColor(149, 165, 166);
$pdf->Cell(90, 4, pt('NOME'), 0, 1, 'L');
$pdf->SetFont('Arial', '', 10);
$pdf->SetTextColor(44, 62, 80);
$pdf->MultiCell(90, 5, pt($servico['c_nome']), 0, 'L');
$pdf->Ln(2);

$pdf->SetFont('Arial', 'B', 7);
$pdf->SetTextColor(149, 165, 166);
$pdf->Cell(90, 4, pt('NIF'), 0, 1, 'L');
$pdf->SetFont('Arial', '', 10);
$pdf->SetTextColor(44, 62, 80);
$pdf->Cell(90, 5, pt($servico['c_nif'] ?: '---'), 0, 1, 'L');
$pdf->Ln(2);

$pdf->SetFont('Arial', 'B', 7);
$pdf->SetTextColor(149, 165, 166);
$pdf->Cell(90, 4, pt('MORADA'), 0, 1, 'L');
$pdf->SetFont('Arial', '', 10);
$pdf->SetTextColor(44, 62, 80);
$pdf->MultiCell(90, 5, pt($servico['morada'] . "\n" . $servico['codigo_postal']), 0, 'L');

$y_esquerda = $pdf->GetY();

// --- LADO DIREITO: EQUIPA E TEMPO ---
$pdf->SetXY(110, $y_inicial);
$pdf->SetFont('Arial', 'B', 9);
$pdf->SetTextColor(179, 42, 42);
$pdf->Cell(90, 6, pt('EQUIPA E TEMPO'), 'B', 1, 'L');

$pdf->SetXY(110, $pdf->GetY() + 2);
$pdf->SetFont('Arial', 'B', 7);
$pdf->SetTextColor(149, 165, 166);
$pdf->Cell(90, 4, pt('TÉCNICOS'), 0, 1, 'L');
$pdf->SetXY(110, $pdf->GetY());
$pdf->SetFont('Arial', '', 10);
$pdf->SetTextColor(44, 62, 80);
$pdf->MultiCell(90, 5, pt($tecnicos), 0, 'L');

$pdf->SetXY(110, $pdf->GetY() + 4);
$y_horarios = $pdf->GetY();

// Horários
$pdf->SetFont('Arial', 'B', 7);
$pdf->SetTextColor(149, 165, 166);
$pdf->Cell(45, 4, pt('HORÁRIOS REGISTADOS'), 0, 1, 'L');
$pdf->SetXY(110, $pdf->GetY());
$pdf->SetFont('Arial', '', 10);
$pdf->SetTextColor(44, 62, 80);
$pdf->MultiCell(45, 5, pt($txt_h1 . $txt_h2), 0, 'L');

// Caixa de Total de Horas
$pdf->SetDrawColor(249, 204, 204); 
$pdf->SetFillColor(253, 242, 242); 
$pdf->RoundedRect(165, $y_horarios, 35, 18, 2, 'DF');

$pdf->SetXY(165, $y_horarios + 2);
$pdf->SetFont('Arial', 'B', 7);
$pdf->SetTextColor(149, 165, 166);
$pdf->Cell(35, 4, pt('TOTAL HORAS'), 0, 1, 'C');

$pdf->SetXY(165, $pdf->GetY());
$pdf->SetFont('Arial', 'B', 14);
$pdf->SetTextColor(179, 42, 42);
$pdf->Cell(35, 8, pt($horas . 'h ' . str_pad($minutos, 2, '0', STR_PAD_LEFT) . 'm'), 0, 1, 'C');

$y_direita = $pdf->GetY();

// --- DESCRIÇÃO DOS TRABALHOS ---
$pdf->SetY(max($y_esquerda, $y_direita) + 10);
$pdf->SetFont('Arial', 'B', 9);
$pdf->SetTextColor(179, 42, 42);
$pdf->Cell(0, 6, pt('DESCRIÇÃO DOS TRABALHOS REALIZADOS'), 'B', 1, 'L');
$pdf->Ln(3);

$trab = $servico['trabalho_realizado'];
$pdf->SetFont('Arial', '', 10);
$pdf->SetTextColor(44, 62, 80);
$pdf->SetDrawColor(225, 229, 234);

$x = $pdf->GetX(); $y = $pdf->GetY();
$nLines = max(3, $pdf->NbLines(186, pt($trab))); 
$h = $nLines * 5 + 6;

$pdf->RoundedRect($x, $y, 190, $h, 2, 'D');
$pdf->SetXY($x + 2, $y + 3);
$pdf->MultiCell(186, 5, pt($trab), 0, 'L');
$pdf->SetY($y + $h + 10); // Espaçamento após a caixa

// --- MATERIAIS UTILIZADOS (Visível em ambas as versões) ---
if (!empty($materiais)) {
    $pdf->SetFont('Arial', 'B', 9);
    $pdf->SetTextColor(179, 42, 42);
    $pdf->Cell(0, 6, pt('MATERIAIS UTILIZADOS'), 'B', 1, 'L');
    $pdf->Ln(3);

    $pdf->SetFont('Arial', 'B', 8);
    $pdf->SetTextColor(44, 62, 80);
    $pdf->SetFillColor(241, 242, 246);
    $pdf->SetDrawColor(220, 220, 220);
    
    // Cabeçalho da Tabela
    $pdf->Cell(160, 6, pt('Descrição'), 'B,T', 0, 'L', true);
    $pdf->Cell(30, 6, pt('Quantidade'), 'B,T', 1, 'C', true);

    // Linhas da Tabela
    $pdf->SetFont('Arial', '', 9);
    foreach ($materiais as $m) {
        $pdf->Cell(160, 6, pt($m['desc']), 'B', 0, 'L');
        $pdf->Cell(30, 6, pt($m['qtd']), 'B', 1, 'C');
    }
    $pdf->Ln(5);
}

// ==============================================================
// BLOCO DE ADMINISTRAÇÃO (SÓ SE O UTILIZADOR TIVER PERMISSÃO E ESCOLHER COMPLETA)
// ==============================================================
if ($is_admin_view) {
    
    // Linha separadora Tracejada
    $pdf->Ln(5);
    $pdf->SetDrawColor(189, 195, 199);
    $pdf->SetLineWidth(0.4);
    $x_dash = 10;
    while ($x_dash < 200) {
        $pdf->Line($x_dash, $pdf->GetY(), $x_dash + 2, $pdf->GetY());
        $x_dash += 4;
    }
    $pdf->Ln(5);

    $pdf->SetFont('Arial', 'B', 8);
    $pdf->SetTextColor(230, 126, 34); 
    $pdf->Cell(0, 6, pt('INFORMAÇÃO INTERNA (APENAS ADMINISTRAÇÃO)'), 0, 1, 'C');
    $pdf->Ln(5);

    $pdf->SetDrawColor(220, 220, 220);
    $pdf->SetLineWidth(0.2);

    // FATURAS
    if (!empty($faturas)) {
        $pdf->SetFont('Arial', 'B', 8);
        $pdf->SetTextColor(44, 62, 80);
        $pdf->Cell(0, 6, pt('FATURAS DE FORNECEDORES'), 'B', 1, 'L');
        
        $pdf->SetFillColor(241, 242, 246);
        $pdf->Cell(140, 6, pt('Fornecedor'), 'B', 0, 'L', true);
        $pdf->Cell(50, 6, pt('Nº Fatura'), 'B', 1, 'L', true);
        
        $pdf->SetFont('Arial', '', 9);
        foreach ($faturas as $f) {
            $pdf->Cell(140, 6, pt($f['forn']), 'B', 0, 'L');
            $pdf->Cell(50, 6, pt($f['num']), 'B', 1, 'L');
        }
        $pdf->Ln(5);
    }

    // RESUMO ESTADOS E CUSTOS
    $pdf->SetFont('Arial', 'B', 8);
    $pdf->SetTextColor(44, 62, 80);
    $pdf->Cell(0, 6, pt('ESTADOS E CUSTOS EXTRA'), 'B', 1, 'L');
    $pdf->SetFont('Arial', '', 9);

    if ($servico['em_garantia']) {
        $pdf->SetTextColor(211, 84, 0);
        $pdf->Cell(0, 6, pt('• Serviço realizado ao abrigo de Garantia.'), 0, 1, 'L');
    }

    $pdf->SetTextColor(39, 174, 96);
    if ($servico['faturado']) {
        $data_fat = date('d/m/Y', strtotime($servico['fatura_data']));
        $pdf->Cell(0, 6, pt('• Faturado ao Cliente: ' . $servico['fatura_num'] . ' em ' . $data_fat), 0, 1, 'L');
    } else {
        $pdf->SetTextColor(127, 140, 141);
        $pdf->Cell(0, 6, pt('• Serviço ainda não faturado ao cliente.'), 0, 1, 'L');
    }

    if ($servico['custo_refeicao_1'] || $servico['custo_refeicao_2']) {
        $pdf->SetTextColor(41, 128, 185);
        $ref_txt = '• Custos de Refeição: ';
        if ($servico['custo_refeicao_1']) $ref_txt .= number_format($servico['custo_refeicao_1'], 2, ',', '.') . chr(128) . ' (Téc 1)   ';
        if ($servico['custo_refeicao_2']) $ref_txt .= number_format($servico['custo_refeicao_2'], 2, ',', '.') . chr(128) . ' (Téc 2)';
        $pdf->Cell(0, 6, pt($ref_txt), 0, 1, 'L');
    }
}

ob_end_clean();
$sufixo = ($is_admin_view) ? 'Completa' : 'Cliente';
$pdf->Output('I', 'Servico_' . $servico['id'] . '_' . $sufixo . '.pdf');
?>