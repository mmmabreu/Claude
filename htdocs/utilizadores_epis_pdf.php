<?php
ob_start();
session_start();
require_once 'db.php';

if (!isset($_SESSION['user_id'])) { 
    ob_end_clean();
    die("Acesso negado."); 
}

require_once('fpdf/fpdf.php');

$id_user = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$categoria = $_GET['cat'] ?? '';

if (!$id_user) { ob_end_clean(); die("Trabalhador não especificado."); }

// Buscar Trabalhador
$stmt_u = $pdo->prepare("SELECT nome FROM utilizadores WHERE id = ?");
$stmt_u->execute([$id_user]);
$trabalhador = $stmt_u->fetch(PDO::FETCH_ASSOC);
if (!$trabalhador) { ob_end_clean(); die("Trabalhador não encontrado."); }

// Array Padrão da Folha
$epis_standard = [
    ['ref' => '1', 'des' => 'Capacete de Proteção com suspensão e jugular', 'riscos' => '3, 11'],
    ['ref' => '2', 'des' => 'Calçado de segurança nº40', 'riscos' => '5; 6'],
    ['ref' => '3', 'des' => 'Colete de alta visibilidade', 'riscos' => '17'],
    ['ref' => '4', 'des' => 'Boné de proteção', 'riscos' => '3, 11'],
    ['ref' => '5', 'des' => 'Luvas de proteção', 'riscos' => '5; 12; 14'],
    ['ref' => '6', 'des' => 'Calça de trabalho', 'riscos' => '12; 13; 18'],
    ['ref' => '7', 'des' => 'Sweatshirt', 'riscos' => '12; 13; 18'],
    ['ref' => '8', 'des' => 'T-shirt: 5t', 'riscos' => '12; 13; 18'],
    ['ref' => '9', 'des' => 'Óculos de proteção', 'riscos' => '13'],
    ['ref' => '10', 'des' => 'Máscara de Proteção', 'riscos' => '16'],
    ['ref' => '11', 'des' => 'Protetor auricular', 'riscos' => '19'],
    ['ref' => '12', 'des' => 'Capa impermeável', 'riscos' => '18'],
    ['ref' => '13', 'des' => 'Arnês de segurança', 'riscos' => '1']
];

// EXTRAIR A DATA MAIS RECENTE PARA CADA EPI
$stmt_epi = $pdo->prepare("SELECT ref, MAX(data_entrega) as ultima_data FROM utilizadores_epis_historico WHERE id_utilizador = ? GROUP BY ref");
$stmt_epi->execute([$id_user]);
$datas_historico = $stmt_epi->fetchAll(PDO::FETCH_KEY_PAIR); 

function pt($texto) {
    return mb_convert_encoding($texto, 'windows-1252', 'UTF-8');
}

class PDF_EPI extends FPDF {
    function Header() {
        // LOGOTIPO MAB
        if (file_exists('mab.png')) {
            $this->Image('mab.png', 15, 12, 30);
        } else {
            // Fallback caso a imagem não seja encontrada na pasta
            $this->SetFont('Arial', 'B', 24);
            $this->SetTextColor(80, 80, 80);
            $this->Cell(40, 10, 'MAB', 0, 0, 'L');
        }
        
        // MORADA À DIREITA
        $this->SetY(12);
        $this->SetFont('Arial', '', 8);
        $this->SetTextColor(0, 0, 0);
        // Usa largura 180 (A4 210mm - 15mm esquerda - 15mm direita)
        $this->Cell(180, 4, pt('m.Abreu - Instalações Técnicas, Lda., com sede na'), 0, 1, 'R');
        $this->Cell(180, 4, pt('Rua Damião de Góis nº205, 4700-028 Braga'), 0, 1, 'R');
        
        $this->Ln(12);
        
        // TÍTULO
        $this->SetFont('Arial', 'B', 12);
        $this->Cell(180, 6, pt("REGISTO DE POSSE DE EPI's"), 0, 1, 'C');
        $this->Ln(5);
    }
}

$pdf = new PDF_EPI('P', 'mm', 'A4');
// A correção do alinhamento está aqui: As margens têm obrigatoriamente de ser definidas ANTES de abrir a página
$pdf->SetMargins(15, 15, 15); 
$pdf->AddPage();
$pdf->SetAutoPageBreak(true, 10);

// --- TABELA TRABALHADOR E CATEGORIA ---
$pdf->SetFont('Arial', 'B', 7);
$pdf->SetFillColor(230, 230, 230);
$pdf->Cell(110, 6, pt('NOME DO TRABALHADOR'), 1, 0, 'C', true);
$pdf->Cell(70, 6, pt('CATEGORIA'), 1, 1, 'C', true);

$pdf->SetFont('Arial', 'B', 9);
$pdf->Cell(110, 8, pt($trabalhador['nome']), 1, 0, 'C');
$pdf->Cell(70, 8, pt($categoria), 1, 1, 'C');

// --- TEXTO DECLARAÇÃO ---
$pdf->Ln(2);
$pdf->SetFont('Arial', '', 8);

$texto1 = "Declaro que recebi da empresa m.Abreu - Instalações Técnicas, unipessoal, Lda., os Equipamentos de Proteção Individual em baixo descritos, em perfeito estado de conservação e funcionamento.";
$texto2 = "Comprometo-me a utilizá-los corretamente durante a execução das minhas atividades profissionais, conforme orientação recebida e de acordo com as normas de segurança no trabalho.";
$texto3 = "Comprometo-me ainda a zelar pela conservação dos mesmos e a comunicar qualquer dano ou necessidade de substituição.";

$pdf->MultiCell(180, 5, pt($texto1), 0, 'J');
$pdf->MultiCell(180, 5, pt($texto2), 0, 'J');
$pdf->MultiCell(180, 5, pt($texto3), 0, 'J');

$pdf->Ln(4);

// --- TABELA DOS EPIs ---
$pdf->SetFont('Arial', 'B', 8);
$pdf->Cell(15, 8, pt('Refª'), 1, 0, 'C', true);
$pdf->Cell(75, 8, pt('Designação do EPI'), 1, 0, 'C', true);
$pdf->Cell(25, 8, pt('Riscos (1)'), 1, 0, 'C', true);
$pdf->Cell(65, 8, pt('Em minha posse (2)'), 1, 1, 'C', true);

$pdf->SetFont('Arial', '', 9);

// Percorrer a Tabela Padrão (Os 13 itens da folha física)
foreach ($epis_standard as $epi) {
    
    $ref = $epi['ref'];
    $data_text = '___/___/_____'; // Padrão vazio
    
    if (isset($datas_historico[$ref])) {
        // Se existe no histórico, formata a data mais recente
        $data_text = date('d/m/Y', strtotime($datas_historico[$ref]));
    }
    
    $pdf->Cell(15, 8, pt($epi['ref']), 1, 0, 'C');
    $pdf->Cell(75, 8, pt($epi['des']), 1, 0, 'L');
    $pdf->Cell(25, 8, pt($epi['riscos']), 1, 0, 'C');
    
    // Coluna "Em minha posse"
    $xAtual = $pdf->GetX();
    $yAtual = $pdf->GetY();
    $pdf->Cell(65, 8, '', 1, 0, 'L'); 
    
    $pdf->SetXY($xAtual + 2, $yAtual + 2);
    $pdf->SetFont('Arial', '', 8);
    $pdf->Cell(10, 4, 'Data:', 0, 0);
    
    if ($data_text !== '___/___/_____') { $pdf->SetFont('Arial', 'U', 8); }
    $pdf->Cell(18, 4, $data_text, 0, 0);
    $pdf->SetFont('Arial', '', 8);
    
    $pdf->Cell(8, 4, 'Ass:', 0, 0);
    
    $pdf->SetXY($xAtual + 65, $yAtual); 
    $pdf->SetFont('Arial', '', 9); 
    $pdf->Ln(8);
}

// Preencher linhas vazias extra para ficar igual ao papel
for($i = count($epis_standard); $i < 15; $i++) {
    $pdf->Cell(15, 8, '', 1, 0, 'C');
    $pdf->Cell(75, 8, '', 1, 0, 'L');
    $pdf->Cell(25, 8, '', 1, 0, 'C');
    
    $xAtual = $pdf->GetX();
    $yAtual = $pdf->GetY();
    $pdf->Cell(65, 8, '', 1, 0, 'L'); 
    $pdf->SetXY($xAtual + 2, $yAtual + 2);
    $pdf->SetFont('Arial', '', 8);
    $pdf->Cell(10, 4, 'Data:', 0, 0);
    $pdf->Cell(18, 4, '___/___/_____', 0, 0);
    $pdf->Cell(8, 4, 'Ass:', 0, 0);
    
    $pdf->SetXY($xAtual + 65, $yAtual); 
    $pdf->SetFont('Arial', '', 9); 
    $pdf->Ln(8);
}

// --- LEGENDAS IMEDIATAMENTE ABAIXO DA TABELA ---
$pdf->SetFont('Arial', '', 7);
$pdf->Cell(115, 5, pt('(1) Indicar códigos de acordo com a tabela abaixo'), 0, 0, 'L');
$pdf->Cell(65, 5, pt('(2) Assinatura do trabalhador _______________________'), 0, 1, 'R');

$pdf->Ln(2);

// --- TABELA DE RISCOS (RODAPÉ) ---
$pdf->SetFont('Arial', 'B', 8);
$pdf->Cell(180, 6, pt('RISCOS A PROTEGER'), 1, 1, 'C', true);

$pdf->SetFont('Arial', '', 7);

$col1_x = 15;
$col2_x = 105; 

$riscos_esq = [
    '1 - Quedas em altura', '2 - Quedas ao mesmo nível', '3 - Queda de objectos',
    '4 - Queda por escorregamento', '5 - Objectos pontiagudos ou cortantes',
    '6 - Esmagamento/perfuração do pé', '7 - Torção do pé', '8 - Choque ao nível dos miolos',
    '9 - Choque ao nível do metatarso', '10 - Choque ao nível da perna'
];

$riscos_dir = [
    '11 - Pancadas na cabeça', '12 - Cortes', '13 - Estilhaços / projecção de partículas',
    '14 - Entalamentos', '15 - Electrização / Electrocussão', '16 - Inalação de Partículas e Poeiras',
    '17 - Atropelamento', '18 - Condições climatéricas adversas', '19 - Exposição ao ruido',
    '20 - Definição de novo risco'
];

$startX = $pdf->GetX();
$startY = $pdf->GetY();
$alturaLinha = 4.5;
$totalAltura = count($riscos_esq) * $alturaLinha;

$pdf->Rect($startX, $startY, 180, $totalAltura); 
$pdf->Line($startX + 90, $startY, $startX + 90, $startY + $totalAltura); 

for($i = 0; $i < count($riscos_esq); $i++) {
    $pdf->SetXY($col1_x + 2, $startY + ($i * $alturaLinha));
    $pdf->Cell(85, $alturaLinha, pt($riscos_esq[$i]), 0, 0, 'L');
    
    $pdf->SetXY($col2_x + 2, $startY + ($i * $alturaLinha));
    if($i == 9) $pdf->SetTextColor(150, 150, 150); 
    $pdf->Cell(85, $alturaLinha, pt($riscos_dir[$i]), 0, 0, 'L');
    $pdf->SetTextColor(30, 30, 30);
}

ob_end_clean();
$pdf->Output('I', 'Registo_EPIs_' . str_replace(' ', '_', $trabalhador['nome']) . '.pdf');
?>