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
    die("Erro: ID de termo inválido."); 
}

// --- BUSCAR DADOS DO TERMO ---
try {
    $sql = "SELECT t.*, c.nome as cliente_nome, c.morada as cliente_morada, 
                   c.codigo_postal as cliente_cp, c.cidade as cliente_cidade, 
                   c.nif as cliente_nif
            FROM termos_intrusao t
            LEFT JOIN clientes c ON t.id_cliente = c.id
            WHERE t.id = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$id]);
    $doc = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$doc) { 
        ob_end_clean();
        die("Erro: Registo não encontrado."); 
    }

    // Buscar equipamentos (agora incluindo o campo 'numero')
    $stmt_eq = $pdo->prepare("SELECT * FROM termos_intrusao_equipamentos WHERE id_termo = ? ORDER BY id ASC");
    $stmt_eq->execute([$id]);
    $equipamentos = $stmt_eq->fetchAll(PDO::FETCH_ASSOC);

} catch (Exception $e) {
    ob_end_clean();
    die("Erro na BD: " . htmlspecialchars($e->getMessage()));
}

// Função de encoding para FPDF
function pt($texto) {
    $texto = (string)($texto ?? '');
    return mb_convert_encoding($texto, 'windows-1252', 'UTF-8');
}

// --- CLASSE PDF CUSTOMIZADA ---
class PDF extends FPDF {
    public $titulo_doc;
    
    function Header() {
        if (file_exists('mab.png')) {
            $this->Image('mab.png', 10, 10, 16); 
        }
        $this->SetY(12);
        $this->SetFont('Arial', 'B', 14);
        $this->SetTextColor(44, 62, 80); 
        $this->Cell(0, 8, pt($this->titulo_doc), 0, 1, 'R');
        $this->Ln(8);
        $y = $this->GetY();
        $this->SetDrawColor(44, 62, 80); 
        $this->SetLineWidth(0.5);
        $this->Line(10, $y, 200, $y);
        $this->Ln(5);
    }

    function Footer() {
        $this->SetY(-15);
        $this->SetDrawColor(200, 200, 200); 
        $this->Line(10, $this->GetY(), 200, $this->GetY());
        $this->SetFont('Arial', 'B', 8);
        $this->SetTextColor(100, 100, 100);
        $this->Cell(0, 4, pt("m. Abreu - Instalações Técnicas, Lda. | NIF: 514 414 120"), 0, 1, 'C');
        $this->SetFont('Arial', '', 8);
        $this->Cell(0, 4, pt("Rua Damião de Góis, 205, Braga | Email: geral@mabreu.pt"), 0, 1, 'C');
    }

    function DrawBoxTitle($title) {
        $this->Ln(2);
        $this->SetFont('Arial', 'B', 9);
        $this->SetFillColor(241, 242, 246);
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
}

// INICIALIZAR PDF
$pdf = new PDF('P', 'mm', 'A4');
$pdf->titulo_doc = 'FICHA DE ALARME DE INTRUSÃO #' . $doc['referencia_termo'];
$pdf->AliasNbPages();
$pdf->AddPage();

// --- DADOS DO CLIENTE ---
$morada_cli = trim($doc['cliente_morada'] . ' ' . $doc['cliente_cp'] . ' ' . $doc['cliente_cidade']);
$pdf->DrawBoxTitle('DADOS DO CLIENTE E LOCAL');
$pdf->DrawRow('Cliente:', $doc['cliente_nome'], 'Data Instalação:', date('d/m/Y', strtotime($doc['data_emissao'])));
$pdf->DrawRow('NIF:', $doc['cliente_nif'], 'Ref. Interna:', $doc['referencia_termo']);
$pdf->DrawRow('Morada Sede:', $morada_cli);
$pdf->DrawRow('Local Obra:', $doc['nome_obra']);
$pdf->DrawRow('Morada Obra:', $doc['morada_obra']);

// --- CENTRAL BASE ---
$pdf->DrawBoxTitle('INFORMAÇÃO DA CENTRAL BASE');
$pdf->DrawRow('Marca da Central:', $doc['marca_central'], 'Ref. / Modelo:', $doc['ref_central']);

// --- TABELA DE EQUIPAMENTOS (COM COLUNA Nº) ---
$pdf->DrawBoxTitle('LISTA DE EQUIPAMENTOS INSTALADOS');
$pdf->SetFont('Arial', 'B', 8);
$pdf->SetFillColor(240, 240, 240);
// Colunas: Nº(10), Equip(60), Marca(60), Loc(60) = 190mm
$w = [10, 60, 60, 60];
$pdf->Cell($w[0], 7, pt('Nº'), 1, 0, 'C', true);
$pdf->Cell($w[1], 7, pt(' Equipamento / Tipo'), 1, 0, 'L', true);
$pdf->Cell($w[2], 7, pt(' Marca / Modelo'), 1, 0, 'L', true);
$pdf->Cell($w[3], 7, pt(' Localização (Zona)'), 1, 1, 'L', true);

$pdf->SetFont('Arial', '', 8);
foreach ($equipamentos as $eq) {
    $pdf->Cell($w[0], 6, pt($eq['numero']), 1, 0, 'C');
    $pdf->Cell($w[1], 6, pt($eq['equipamento']), 1, 0, 'L');
    $pdf->Cell($w[2], 6, pt($eq['modelo']), 1, 0, 'L');
    $pdf->Cell($w[3], 6, pt($eq['localizacao']), 1, 1, 'L');
}

// --- TERMO RESPONSABILIDADE ---
$pdf->Ln(10);
$pdf->SetFont('Arial', 'B', 10);
$pdf->Cell(0, 6, pt('TERMO DE RESPONSABILIDADE E ENTREGA DO SISTEMA'), 0, 1, 'C');
$pdf->Ln(5);
$pdf->SetFont('Arial', '', 8);
$texto_legal = "Confirmo a receção, em pleno funcionamento, do sistema de intrusão e componentes descritos. Declaro ter recebido códigos de acesso e fui instruído sobre a gestão e confidencialidade dos mesmos. Assumo a responsabilidade por falsos alarmes causados por má utilização. O sistema requer manutenções periódicas não incluídas.";
$pdf->MultiCell(0, 4, pt($texto_legal), 0, 'J');

// --- ASSINATURAS ---
$pdf->Ln(20);
$pdf->Cell(95, 5, pt('O Cliente'), 0, 0, 'C');
$pdf->Cell(95, 5, pt('A Empresa Instaladora'), 0, 1, 'C');
$pdf->Ln(15);
$pdf->Line(20, $pdf->GetY(), 90, $pdf->GetY());
$pdf->Line(120, $pdf->GetY(), 190, $pdf->GetY());

ob_end_clean();
$pdf->Output('I', pt('Intrusao_' . $doc['referencia_termo'] . '.pdf'));
?>