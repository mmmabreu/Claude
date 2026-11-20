<?php
// LIGAR EXIBIÇÃO DE ERROS PARA PREVENIR O ERROR 500 (TELA BRANCA)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

ob_start();
session_start();
require_once 'db.php';

if (!isset($_SESSION['user_id'])) {
    ob_end_clean();
    die("Acesso negado.");
}

require_once('fpdf/fpdf.php');

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$id) { ob_end_clean(); die("ID de termo não especificado."); }

// --- CONFIGURAÇÃO DA EMPRESA ---
$NOME_EMPRESA = "m.Abreu - Instalações Técnicas, Unipessoal, Lda.";
$NIPC_EMPRESA = "514414120"; 
$REGISTO_PREVIO_EMPRESA = "4944"; 

// Função auxiliar: devolve o primeiro campo preenchido de uma lista de nomes possíveis
function pegar($arr, $chaves, $default = '') {
    foreach ($chaves as $k) {
        if (isset($arr[$k]) && trim((string)$arr[$k]) !== '') {
            return trim((string)$arr[$k]);
        }
    }
    return $default;
}

// --- BUSCAR DADOS DO TERMO E OBRA ---
try {
    $stmt = $pdo->prepare("
        SELECT t.*, c.nome as nome_cliente 
        FROM termos_intrusao t
        LEFT JOIN clientes c ON t.id_cliente = c.id
        WHERE t.id = ?
    ");
    $stmt->execute([$id]);
    $termo = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$termo) { ob_end_clean(); die("Termo de Intrusão não encontrado na base de dados."); }

    // Equipamentos da instalação (tabela filha)
    $stmt_eq = $pdo->prepare("SELECT * FROM termos_intrusao_equipamentos WHERE id_termo = ? ORDER BY id ASC");
    $stmt_eq->execute([$id]);
    $equipamentos = $stmt_eq->fetchAll(PDO::FETCH_ASSOC);

} catch (Exception $e) {
    ob_end_clean();
    die("Erro na Base de Dados: " . $e->getMessage());
}

function pt($texto) {
    return mb_convert_encoding($texto ?? '', 'windows-1252', 'UTF-8');
}

class PDF_PSP extends FPDF {
    function Header() {
        // LOGOTIPO MAB (Esquerda)
        if (file_exists('mab.png')) {
            $this->Image('mab.png', 20, 12, 25);
        }
        
        // LOGOTIPO PSP (Direita)
        if (file_exists('psp1.png')) {
            $this->Image('psp1.png', 170, 10, 20);
        }
        
        $this->Ln(15);
        
        // TÍTULO DA DECLARAÇÃO
        $this->SetFont('Arial', 'B', 12);
        $this->Cell(0, 6, pt("DECLARAÇÃO DE INSTALAÇÃO"), 0, 1, 'C');
        $this->Ln(6);
    }
}

// Posição fixa do rodapé oficial (sempre colado ao fundo da última página)
$RODAPE_Y = 261; // traço + nota ¹ + logótipo PSP terminam perto da margem inferior

$pdf = new PDF_PSP('P', 'mm', 'A4');
$pdf->SetMargins(20, 20, 20);
$pdf->AddPage();
$pdf->SetAutoPageBreak(true, 15);

// --- CORPO DO TEXTO ---
$pdf->SetFont('Arial', '', 10);

$data_emissao = !empty($termo['data_emissao']) ? date('d/m/Y', strtotime($termo['data_emissao'])) : date('d/m/Y');

$morada_obra = pegar($termo, ['morada_obra', 'morada', 'localizacao', 'local_obra']);
if ($morada_obra === '') {
    $morada_obra = '__________________________________________________';
}

$tecnico = 'Mário Abreu';

$texto_declaracao = "A entidade $NOME_EMPRESA, titular do NIPC $NIPC_EMPRESA, detentora do Certificado de Registo Prévio N.º $REGISTO_PREVIO_EMPRESA, emitido pelo Departamento de Segurança Privada da Direção Nacional da Polícia de Segurança Pública,\n\nDeclara na data de $data_emissao em $morada_obra que instalou o sistema de alarme de intrusão em consonância com as normas técnicas aplicáveis CLC/TS 50131-7, sob a responsabilidade do técnico responsável $tecnico, atesta que os serviços abaixo indicados foram prestados em conformidade com a legislação e as normas técnicas aplicáveis.";

$pdf->MultiCell(0, 5.5, pt($texto_declaracao), 0, 'J');
$pdf->Ln(3);

// --- LISTA DE EQUIPAMENTOS ---
$pdf->SetFont('Arial', 'B', 10); 
$pdf->Cell(0, 6, pt("Serviços prestados e Equipamentos instalados:"), 0, 1, 'L');
$pdf->SetFont('Arial', '', 10); 

// Construir as linhas e agrupar as que são exatamente iguais (ex.: 5x Detetor de Movimento)
$linhas = []; // texto => quantidade (mantém a ordem da primeira ocorrência)

// Central de Intrusão
$marca_central  = pegar($termo, ['marca_central']);
$modelo_central = pegar($termo, ['modelo_central', 'ref_central']);
if ($marca_central !== '' || $modelo_central !== '') {
    $serie_central = pegar($termo, ['numero_serie', 'serie_central', 'numero_serie_central'], '-');
    $linha_central = "Instalação de Central de Intrusão: " . trim($marca_central . " " . $modelo_central) . " (Nº Série: " . $serie_central . ")";
    $linhas[$linha_central] = ($linhas[$linha_central] ?? 0) + 1;
}

// Restantes equipamentos
foreach ($equipamentos as $eq) {
    $tipo   = pegar($eq, ['tipo', 'tipo_equipamento', 'descricao', 'designacao', 'equipamento']);
    $marca  = pegar($eq, ['marca']);
    $modelo = pegar($eq, ['modelo']);
    $serie  = pegar($eq, ['serie', 'numero_serie', 'n_serie', 'nr_serie'], '-');
    $qtd    = (int) pegar($eq, ['quantidade', 'qtd'], '1');
    if ($qtd < 1) $qtd = 1;

    // Ignora se não tiver tipo, marca nem modelo preenchidos
    if ($tipo === '' && $marca === '' && $modelo === '') continue;

    $rotulo = ($tipo !== '') ? $tipo : 'Equipamento de Intrusão';

    $linha_eq = "Instalação de " . $rotulo . ": " . trim($marca . " " . $modelo) . " (Nº Série: " . $serie . ")";
    $linhas[$linha_eq] = ($linhas[$linha_eq] ?? 0) + $qtd;
}

if (empty($linhas)) {
    // Se o instalador não tiver preenchido nenhum equipamento no formulário
    for ($i = 0; $i < 4; $i++) {
        $pdf->Cell(0, 8, '________________________________________________________________________________________', 0, 1, 'L');
    }
} else {
    foreach ($linhas as $texto => $qtd) {
        $prefixo = ($qtd > 1) ? $qtd . "x " : '';
        $pdf->MultiCell(0, 5.5, pt("- " . $prefixo . $texto), 0, 'L');
    }
}

$pdf->Ln(3);

// --- CONTROLO DE QUEBRA DE PÁGINA ---
// O bloco "textos + data + assinaturas" ocupa cerca de 92 mm e tem de terminar
// antes do rodapé fixo. Se não couber, passa inteiro para a página seguinte
// (o rodapé fica, na mesma, no fundo dessa última página).
$altura_bloco_assinaturas = 92;
if ($pdf->GetY() + $altura_bloco_assinaturas > $RODAPE_Y - 4) {
    $pdf->AddPage();
}

// --- TEXTOS DA DECLARAÇÃO ---
$pdf->SetFont('Arial', '', 10);

$pdf->Cell(0, 6, pt("O original do presente documento, foi entregue ao cliente."), 0, 1, 'L');
$pdf->Ln(2);

$pdf->MultiCell(0, 5.5, pt("Desta declaração de instalação faz parte integrante a lista dos materiais e equipamentos de segurança instalados e respetivos certificados."), 0, 'J');
$pdf->Ln(2);

$pdf->Cell(0, 6, pt("O original do presente documento, foi entregue ao proprietário do espaço."), 0, 1, 'L');

$pdf->Ln(3);

// --- LOCALIDADE E DATA ---
$pdf->Cell(0, 6, pt("Braga, " . $data_emissao), 0, 1, 'C');

$pdf->Ln(3);

// --- ÁREA DE ASSINATURAS ---
$yAssinaturas = $pdf->GetY();

// Entidade Instaladora (Esquerda)
$pdf->SetXY(20, $yAssinaturas);
$pdf->Cell(85, 5, pt('Entidade instaladora'), 0, 0, 'C');

// Técnico Responsável (Direita)
$pdf->SetXY(105, $yAssinaturas);
$pdf->Cell(85, 5, pt('O técnico responsável'), 0, 1, 'C');

// Linhas de Assinatura
$pdf->Ln(10);
$yLinhas = $pdf->GetY();
$pdf->Line(30, $yLinhas, 90, $yLinhas);
$pdf->Line(115, $yLinhas, 175, $yLinhas);

// Texto Azul [Assinatura cfr...]
$pdf->Ln(2);
$pdf->SetTextColor(41, 128, 185);
$pdf->SetFont('Arial', '', 9);
$pdf->SetXY(20, $pdf->GetY());
$pdf->Cell(85, 4, pt('[Assinatura cfr. documento de identificação]'), 0, 0, 'C');
$pdf->SetXY(105, $pdf->GetY());
$pdf->Cell(85, 4, pt('[Assinatura cfr. documento de identificação]'), 0, 1, 'C');

// Proprietário (Centro)
$pdf->SetTextColor(0, 0, 0);
$pdf->Ln(8);
$pdf->SetFont('Arial', '', 10);
$pdf->Cell(0, 5, pt('O Proprietário/Usufrutuário¹'), 0, 1, 'C');

// Linha de Assinatura Central
$pdf->Ln(10);
$yLinhasProp = $pdf->GetY();
$pdf->Line(75, $yLinhasProp, 135, $yLinhasProp);

// Texto Azul [Assinatura]
$pdf->Ln(2);
$pdf->SetTextColor(41, 128, 185); 
$pdf->SetFont('Arial', '', 9);
$pdf->Cell(0, 4, pt('[Assinatura]'), 0, 1, 'C');

// --- RODAPÉ OFICIAL (FIXO NO FUNDO DA ÚLTIMA PÁGINA, COMO NO MODELO DA PSP) ---
$pdf->SetAutoPageBreak(false); // evita que o rodapé crie uma página nova
$pdf->SetTextColor(0, 0, 0);
$pdf->SetDrawColor(0, 0, 0);

// Traço curto de separação da nota de rodapé
$pdf->Line(20, $RODAPE_Y, 70, $RODAPE_Y);

// Nota de rodapé ¹
$pdf->SetXY(20, $RODAPE_Y + 2);
$pdf->SetFont('Arial', 'B', 9);
$pdf->Cell(0, 5, pt('¹ A entidade instaladora procedeu à entrega do manual do sistema e do livro do registo do sistema'), 0, 1, 'L');

// Logótipo da Polícia de Segurança Pública, centrado
if (file_exists('psp2.png')) {
    // Página A4 = 210 mm; imagem com 45 mm de largura => x = (210 - 45) / 2 = 82.5
    $pdf->Image('psp2.png', 82.5, $RODAPE_Y + 9, 45);
}

ob_end_clean();
$pdf->Output('I', 'Declaracao_Instalacao_Intrusao_PSP_' . ($termo['referencia_termo'] ?? 'ND') . '.pdf');
?>
