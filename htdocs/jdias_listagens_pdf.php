<?php
// REGRA DE OURO: Limpar qualquer espaço em branco antes do PDF
ob_start();
session_start();

if (!isset($_SESSION['user_id'])) { 
    ob_end_clean();
    die("Acesso negado."); 
}

require_once 'db.php';
require_once('fpdf/fpdf.php');

$tipo = $_GET['tipo'] ?? 'Ativo';
$acerto = $_GET['acerto'] ?? '';

// --- CRUZAR DADOS DAS 3 TABELAS VIA SQL ---
// Padronizamos as colunas com NULL ou '-' onde não existem na tabela de origem para o UNION funcionar
$sql = "
    SELECT * FROM (
        SELECT numero, 'FSERV' as modulo, 'FSERV' as tipo_display, data_registo, seccao, pdct, '-' as nome_cliente, fserv_anterior as fserv_ant, valor, valor_corrigido, valor_certo, estado, observacoes, id_acerto 
        FROM jdias_fserv
        
        UNION ALL
        
        SELECT numero, 'FAT' as modulo, tipo as tipo_display, data_registo, seccao, NULL as pdct, '-' as nome_cliente, NULL as fserv_ant, valor, NULL as valor_corrigido, '-' as valor_certo, estado, observacoes, id_acerto 
        FROM jdias_fat
        
        UNION ALL
        
        SELECT numero, 'Acertos' as modulo, tipo as tipo_display, data_registo, NULL as seccao, NULL as pdct, nome_cliente, NULL as fserv_ant, valor, NULL as valor_corrigido, '-' as valor_certo, estado, observacoes, id_acerto 
        FROM jdias_fatfav
    ) as uniao
";

// Filtrar
if ($tipo === 'Acertado') {
    $sql .= " WHERE estado = 'Acertado' AND id_acerto = :acerto";
} else {
    $sql .= " WHERE estado = :tipo";
}

// Ordenar (Tipo ASC, Data DESC, Numero DESC)
$sql .= " ORDER BY tipo_display ASC, data_registo DESC, numero DESC";

try {
    $stmt = $pdo->prepare($sql);
    if ($tipo === 'Acertado') {
        $stmt->execute(['acerto' => $acerto]);
    } else {
        $stmt->execute(['tipo' => $tipo]);
    }
    
    $registos = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!$registos) { 
        ob_end_clean();
        die("Não existem registos para o filtro selecionado."); 
    }

} catch (Exception $e) {
    ob_end_clean();
    die("Erro na BD: " . htmlspecialchars($e->getMessage()));
}

function pt($texto) {
    $texto = (string)($texto ?? '');
    return mb_convert_encoding($texto, 'windows-1252', 'UTF-8');
}

// Determinar o Título do PDF
$tituloDoc = "LISTAGEM GERAL - " . strtoupper($tipo);
if ($tipo === 'Acertado') {
    $tituloDoc = "LISTAGEM DE DOCUMENTOS - ACERTO Nº " . $acerto;
}

class PDF extends FPDF {
    public $titulo_doc;
    
    function Header() {
        $this->SetY(15);
        $this->SetFont('Arial', 'B', 14); 
        $this->SetTextColor(50, 50, 50); 
        $this->Cell(0, 8, pt($this->titulo_doc), 0, 1, 'C');
        
        $this->SetFont('Arial', '', 9);
        $this->SetTextColor(100, 100, 100);
        $this->Cell(0, 5, pt('Data de Emissão: ' . date('d/m/Y H:i')), 0, 1, 'C');
        
        $this->Ln(5); 
        $y = $this->GetY(); 
        $this->SetDrawColor(180, 180, 180); 
        $this->SetLineWidth(0.4);
        $this->Line(10, $y, 287, $y); 
        $this->Ln(5);
    }
    
    function Footer() {
        $this->SetY(-15); 
        $this->SetDrawColor(180, 180, 180); 
        $this->SetLineWidth(0.2);
        $this->Line(10, $this->GetY(), 287, $this->GetY()); 
        
        $this->Ln(2); 
        $this->SetFont('Arial', '', 8);
        $this->SetTextColor(120, 120, 120);
        $this->Cell(0, 4, pt('Documento Gerado Eletronicamente'), 0, 0, 'L');
        
        $this->SetXY(10, $this->GetY()); 
        $this->Cell(0, 4, pt('Página ') . $this->PageNo() . '/{nb}', 0, 1, 'R');
    }
}

$pdf = new PDF('L', 'mm', 'A4');
$pdf->titulo_doc = $tituloDoc;
$pdf->AliasNbPages(); 
$pdf->AddPage(); 
$pdf->SetAutoPageBreak(true, 20);

// LARGURA DAS COLUNAS (Total = 277mm)
// Modulo(15), Tipo(16), Num(14), Data(18), Sec(10), PDCT(12), Cliente(35), F.Ant(12), Base(18), Corr(18), Certo(12), Est(15), Obs(82)
$w = [15, 16, 14, 18, 10, 12, 35, 12, 18, 18, 12, 15, 82];

// CABEÇALHO DA TABELA
$draw_header = function() use ($pdf, $w) {
    $pdf->SetFont('Arial', 'B', 8); 
    $pdf->SetFillColor(240, 240, 240); 
    $pdf->SetDrawColor(200, 200, 200); 
    $pdf->SetLineWidth(0.2);
    
    $pdf->Cell($w[0], 8, pt('Modulo'), 1, 0, 'C', true);
    $pdf->Cell($w[1], 8, pt('Tipo'), 1, 0, 'C', true);
    $pdf->Cell($w[2], 8, pt('Num'), 1, 0, 'C', true);
    $pdf->Cell($w[3], 8, pt('Data'), 1, 0, 'C', true);
    $pdf->Cell($w[4], 8, pt('Sec'), 1, 0, 'C', true);
    $pdf->Cell($w[5], 8, pt('PDCT'), 1, 0, 'C', true);
    $pdf->Cell($w[6], 8, pt('Cliente'), 1, 0, 'C', true);
    $pdf->Cell($w[7], 8, pt('F.Ant'), 1, 0, 'C', true);
    $pdf->Cell($w[8], 8, pt('V.Base'), 1, 0, 'C', true);
    $pdf->Cell($w[9], 8, pt('V.Corrig.'), 1, 0, 'C', true);
    $pdf->Cell($w[10], 8, pt('Certo'), 1, 0, 'C', true);
    $pdf->Cell($w[11], 8, pt('Estado'), 1, 0, 'C', true);
    $pdf->Cell($w[12], 8, pt('Observacoes'), 1, 1, 'C', true);
};

$draw_header(); 

$total_base = 0;
$total_corrigido = 0;

$pdf->SetFont('Arial', '', 8);
$pdf->SetTextColor(30, 30, 30);

// DESENHAR LINHAS DE DADOS
foreach ($registos as $row) {
    if ($pdf->GetY() > 180) { 
        $pdf->AddPage(); 
        $draw_header(); 
        $pdf->SetFont('Arial', '', 8); 
        $pdf->SetTextColor(30, 30, 30);
    }
    
    $valBase = (float)$row['valor'];
    $valCor = ($row['valor_corrigido'] !== null && $row['valor_corrigido'] !== '') ? (float)$row['valor_corrigido'] : null;
    
    $total_base += $valBase;
    if ($valCor !== null) { $total_corrigido += $valCor; }
    
    $data_show = !empty($row['data_registo']) ? date('d/m/Y', strtotime($row['data_registo'])) : '-';
    $sec_show = !empty($row['seccao']) ? $row['seccao'] : '-';
    $pdct_show = !empty($row['pdct']) ? $row['pdct'] : '-';
    $cli_show = mb_strimwidth(pt($row['nome_cliente'] ?? '-'), 0, 25, '...');
    $fserv_ant_show = !empty($row['fserv_ant']) ? $row['fserv_ant'] : '-';
    $v_certo_show = !empty($row['valor_certo']) ? $row['valor_certo'] : '-';
    $obs_show = mb_strimwidth(pt($row['observacoes'] ?? ''), 0, 65, '...'); 

    $pdf->Cell($w[0], 7, pt($row['modulo']), 1, 0, 'C');
    $pdf->Cell($w[1], 7, pt($row['tipo_display']), 1, 0, 'C');
    $pdf->Cell($w[2], 7, pt($row['numero']), 1, 0, 'C');
    $pdf->Cell($w[3], 7, pt($data_show), 1, 0, 'C');
    $pdf->Cell($w[4], 7, pt($sec_show), 1, 0, 'C');
    $pdf->Cell($w[5], 7, pt($pdct_show), 1, 0, 'C');
    $pdf->Cell($w[6], 7, $cli_show, 1, 0, 'C');
    $pdf->Cell($w[7], 7, pt($fserv_ant_show), 1, 0, 'C');
    
    $pdf->SetFont('Arial', 'B', 8);
    $pdf->Cell($w[8], 7, number_format($valBase, 2, ',', '.') . ' ' . chr(128), 1, 0, 'C');
    $pdf->SetFont('Arial', '', 8);
    
    $valCorTxt = ($valCor !== null) ? number_format($valCor, 2, ',', '.') . ' ' . chr(128) : '-';
    $pdf->Cell($w[9], 7, $valCorTxt, 1, 0, 'C');
    
    $pdf->Cell($w[10], 7, pt($v_certo_show), 1, 0, 'C');
    $pdf->Cell($w[11], 7, pt($row['estado']), 1, 0, 'C');
    $pdf->Cell($w[12], 7, $obs_show, 1, 1, 'L');
}

// RODAPÉ DOS TOTAIS
$pdf->Ln(2);
$pdf->SetFont('Arial', 'B', 10);
$pdf->SetFillColor(240, 240, 240);

// O texto ocupa as colunas 0 a 7
$colWidthText = array_sum(array_slice($w, 0, 8));
$pdf->Cell($colWidthText, 8, pt('TOTAIS DA LISTAGEM:'), 1, 0, 'R', true);

$pdf->SetTextColor(41, 128, 185); 
$pdf->Cell($w[8], 8, number_format($total_base, 2, ',', '.') . ' ' . chr(128), 1, 0, 'C', true);

$pdf->SetTextColor(200, 50, 50); 
$pdf->Cell($w[9], 8, number_format($total_corrigido, 2, ',', '.') . ' ' . chr(128), 1, 0, 'C', true);

$colWidthRest = array_sum(array_slice($w, 10));
$pdf->Cell($colWidthRest, 8, '', 1, 1, 'L', true);

ob_end_clean();
$pdf->Output('I', 'Listagem_' . $tipo . '_' . date('Ymd_His') . '.pdf');
?>