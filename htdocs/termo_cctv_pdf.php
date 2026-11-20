<?php
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
    die("Erro: ID de termo inválido."); 
}

try {
    $sql = "SELECT t.*, c.nome as cliente_nome, c.morada as cliente_morada, 
                   c.codigo_postal as cliente_cp, c.cidade as cliente_cidade, 
                   c.nif as cliente_nif
            FROM termos_cctv t
            LEFT JOIN clientes c ON t.id_cliente = c.id
            WHERE t.id = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$id]);
    $doc = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$doc) { 
        ob_end_clean();
        die("Erro: Registo não encontrado."); 
    }
} catch (Exception $e) {
    ob_end_clean();
    die("Erro na BD: " . htmlspecialchars($e->getMessage()));
}

function pt($texto) {
    return mb_convert_encoding((string)($texto ?? ''), 'windows-1252', 'UTF-8');
}

function otimizarImagem($ficheiro) {
    if (!file_exists($ficheiro)) return false;
    return $ficheiro;
}

class PDF extends FPDF {
    public $titulo_doc;
    
    function Header() {
        if (file_exists('mab.png')) {
            $this->Image('mab.png', 10, 10, 20);
        }
        $this->SetY(12);
        $this->SetFont('Arial', 'B', 14);
        $this->SetTextColor(44, 62, 80); 
        $this->Cell(0, 8, pt($this->titulo_doc), 0, 1, 'R');
        $this->Ln(8);
        $this->SetDrawColor(44, 62, 80); 
        $this->SetLineWidth(0.5);
        $this->Line(10, $this->GetY(), 200, $this->GetY());
        $this->Ln(5);
    }

    function Footer() {
        $this->SetY(-15);
        $this->SetDrawColor(200, 200, 200); 
        $this->Line(10, $this->GetY(), 200, $this->GetY());
        $this->SetFont('Arial', 'B', 8);
        $this->Cell(0, 4, pt("m. Abreu - Instalações Técnicas, Lda."), 0, 1, 'C');
        $this->SetFont('Arial', '', 8);
        $this->Cell(0, 4, pt("Rua Damião de Góis, 205, Braga"), 0, 1, 'C');
    }

    function DrawBoxTitle($title) {
        $this->Ln(4);
        $this->SetFont('Arial', 'B', 9);
        $this->SetFillColor(241, 242, 246);
        $this->Cell(0, 7, pt(' ' . $title), 1, 1, 'L', true);
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
}

$pdf = new PDF('P', 'mm', 'A4');
$pdf->titulo_doc = 'FICHA DE INSTALAÇÃO CCTV #' . $doc['referencia_termo'];
$pdf->AliasNbPages();
$pdf->AddPage();

$morada_cli = trim($doc['cliente_morada'] . ' ' . $doc['cliente_cp'] . ' ' . $doc['cliente_cidade']);

$pdf->DrawBoxTitle('DADOS DO CLIENTE E LOCAL');
$pdf->DrawRow('Cliente:', $doc['cliente_nome']); 
$pdf->DrawRow('Data:', date('d/m/Y', strtotime($doc['data_emissao'])), 'Ref. Interna:', $doc['referencia_termo']);
$pdf->DrawRow('NIF:', $doc['cliente_nif']);
$pdf->DrawRow('Morada Sede:', $morada_cli);
$pdf->DrawRow('Local Obra:', $doc['nome_obra']);
$pdf->DrawRow('Morada Obra:', $doc['morada_obra']);

$pdf->DrawBoxTitle('EQUIPAMENTO DE GRAVAÇÃO (DVR / NVR)');
$pdf->DrawRow('Marca:', $doc['marca_gravador'], 'Modelo:', $doc['modelo_gravador']);
$pdf->DrawRow('Nº de Série:', $doc['numero_serie'], 'Aplicação:', $doc['programa_visualizacao']);
if (trim((string)($doc['codigo_acesso_app'] ?? '')) !== '') $pdf->DrawRow('Código acesso:', $doc['codigo_acesso_app']);

// --- MATRIZ DE CÂMARAS ---
$camaras = json_decode($doc['camaras_json'] ?? '[]', true);
if (!empty($camaras)) {
    $pdf->DrawBoxTitle('CÂMARAS INSTALADAS');
    $pdf->SetFont('Arial', 'B', 8);
    $pdf->SetFillColor(240, 240, 240);
    $w = [8, 25, 30, 25, 30, 32, 40];
    $headers = ['Nº', 'Marca', 'Modelo', 'Série', 'IP', 'User', 'Pass'];
    foreach($headers as $i => $h) $pdf->Cell($w[$i], 7, pt($h), 1, 0, 'L', true);
    $pdf->Ln();
    
    $pdf->SetFont('Arial', '', 7);
    foreach ($camaras as $c) {
        $pdf->Cell($w[0], 6, pt($c['numero']??''), 1);
        $pdf->Cell($w[1], 6, pt($c['marca']??''), 1);
        $pdf->Cell($w[2], 6, pt($c['modelo']??''), 1);
        $pdf->Cell($w[3], 6, pt($c['serie']??''), 1);
        $pdf->Cell($w[4], 6, pt($c['ip']??''), 1);
        $pdf->Cell($w[5], 6, pt($c['user']??''), 1);
        $pdf->Cell($w[6], 6, pt($c['pass']??''), 1, 1);
    }
}

ob_end_clean();
$pdf->Output('I', pt('CCTV_' . $doc['referencia_termo'] . '.pdf'));
?>