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

$id_obra = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$id_obra) { 
    ob_end_clean();
    die("Erro: ID de obra inválido."); 
}

try {
    $stmt_obra = $pdo->prepare("
        SELECT o.*, 
               c.nome as cliente_nome, 
               orc.valor_total_com_iva as valor_orcamentado 
        FROM obras o 
        LEFT JOIN clientes c ON o.id_cliente = c.id 
        LEFT JOIN orcamentos orc ON o.id_orcamento = orc.id 
        WHERE o.id = ?
    ");
    $stmt_obra->execute([$id_obra]);
    $obra = $stmt_obra->fetch(PDO::FETCH_ASSOC);

    if (!$obra) { 
        ob_end_clean();
        die("Erro: Obra não encontrada."); 
    }

    $despesas_stmt = $pdo->prepare("
        SELECT d.*, u.nome as nome_colaborador 
        FROM obra_despesas d 
        LEFT JOIN utilizadores u ON d.id_utilizador = u.id 
        WHERE d.obra_id = ? 
    ");
    $despesas_stmt->execute([$id_obra]);
    $lista_despesas = $despesas_stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (Exception $e) {
    ob_end_clean();
    die("Erro na BD: " . htmlspecialchars($e->getMessage()));
}

// Calcular totais gerais
$total_material = 0; $total_mo = 0; $total_mo_extra = 0; $total_desloc = 0; $total_ref = 0; $total_outras = 0;

// Agrupar por Colaborador + Tipo para a nova tabela
$resumo_agrupado = [];

foreach ($lista_despesas as $d) {
    // 1. Somatórios Gerais para o Cabeçalho
    if ($d['tipo_despesa'] == 'Material') $total_material += $d['valor'];
    if ($d['tipo_despesa'] == 'Mão de Obra') $total_mo += $d['valor'];
    if ($d['tipo_despesa'] == 'Mão de Obra (Extra)') $total_mo_extra += $d['valor'];
    if ($d['tipo_despesa'] == 'Deslocação') $total_desloc += $d['valor'];
    if ($d['tipo_despesa'] == 'Refeição') $total_ref += $d['valor'];
    if ($d['tipo_despesa'] == 'Outras') $total_outras += $d['valor'];

    // 2. Lógica de Primeiro e Último Nome do Colaborador
    $nome_colab_full = trim($d['nome_colaborador']);
    if (empty($nome_colab_full)) {
        $txt_colab = pt('Despesas Gerais / Materiais');
    } else {
        $parts = explode(' ', $nome_colab_full);
        if (count($parts) > 1) {
            $txt_colab = pt($parts[0] . ' ' . end($parts));
        } else {
            $txt_colab = pt($parts[0]);
        }
    }

    $tipo_original = $d['tipo_despesa'];
    $tipo = pt($tipo_original);
    
    // Chave única para agrupar: Nome + Tipo de Despesa
    $key = $txt_colab . '||' . $tipo;

    if (!isset($resumo_agrupado[$key])) {
        $resumo_agrupado[$key] = [
            'colaborador' => $txt_colab,
            'tipo_original' => $tipo_original,
            'tipo' => $tipo,
            'quantidade' => 0,
            'valor' => 0
        ];
    }
    
    $resumo_agrupado[$key]['quantidade'] += (float)$d['quantidade'];
    $resumo_agrupado[$key]['valor'] += (float)$d['valor'];
}

// Ordenar a tabela: Primeiro Pessoas A-Z, no fim "Despesas Gerais"
uasort($resumo_agrupado, function($a, $b) {
    if ($a['colaborador'] == pt('Despesas Gerais / Materiais')) return 1;
    if ($b['colaborador'] == pt('Despesas Gerais / Materiais')) return -1;
    
    $cmp = strcmp($a['colaborador'], $b['colaborador']);
    if ($cmp === 0) {
        return strcmp($a['tipo'], $b['tipo']);
    }
    return $cmp;
});

$custo_total_obra = $total_material + $total_mo + $total_mo_extra + $total_desloc + $total_ref + $total_outras;
$valor_faturado = $obra['valor_orcamentado'] ?? 0;
$margem_lucro = $valor_faturado - $custo_total_obra;


// Função de encoding para FPDF
function pt($texto) {
    $texto = (string)($texto ?? '');
    return mb_convert_encoding($texto, 'windows-1252', 'UTF-8');
}

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

class PDF extends FPDF {
    public $titulo_doc;
    
    function Header() {
        $logo = otimizarImagem('mab.png');
        if ($logo && file_exists($logo)) {
            $this->Image($logo, 10, 10, 16); 
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
}

$pdf = new PDF('P', 'mm', 'A4');
$pdf->titulo_doc = 'RELATÓRIO FINANCEIRO DE OBRA';
$pdf->AliasNbPages();
$pdf->AddPage();
$pdf->SetAutoPageBreak(true, 20);

// --- IDENTIFICAÇÃO GERAL ---
$pdf->DrawBoxTitle('IDENTIFICAÇÃO DA OBRA');
$pdf->DrawRow('Cliente:', $obra['cliente_nome'], 'Data Extrato:', date('d/m/Y'));
$pdf->DrawRow('Obra:', $obra['nome'], 'Ref. Obra:', $obra['referencia_obra'] ?: 'N/D');

// --- RESUMO FINANCEIRO ---
$pdf->Ln(2);
$pdf->SetFont('Arial', 'B', 9);
$pdf->SetFillColor(253, 242, 233); // Fundo Laranja Claro
$pdf->Cell(0, 7, pt(' RESUMO DE CUSTOS E RENTABILIDADE'), 1, 1, 'L', true);

$pdf->SetFont('Arial', '', 9);
$pdf->Cell(47.5, 6, pt('Materiais:'), 'L,B', 0);
$pdf->Cell(47.5, 6, pt(number_format($total_material, 2, ',', ' ') . ' EUR'), 'R,B', 0, 'R');
$pdf->Cell(47.5, 6, pt('CUSTO TOTAL OBRA:'), 'L,B', 0);
$pdf->SetFont('Arial', 'B', 9);
$pdf->SetTextColor(211, 84, 0); // Laranja Escuro
$pdf->Cell(47.5, 6, pt(number_format($custo_total_obra, 2, ',', ' ') . ' EUR'), 'R,B', 1, 'R');
$pdf->SetTextColor(0, 0, 0); 
$pdf->SetFont('Arial', '', 9);

$pdf->Cell(47.5, 6, pt('Mão de Obra (Normal):'), 'L,B', 0);
$pdf->Cell(47.5, 6, pt(number_format($total_mo, 2, ',', ' ') . ' EUR'), 'R,B', 0, 'R');
$pdf->Cell(47.5, 6, pt('Valor Adjudicado (IVA inc):'), 'L,B', 0);
$pdf->Cell(47.5, 6, pt(number_format($valor_faturado, 2, ',', ' ') . ' EUR'), 'R,B', 1, 'R');

$pdf->Cell(47.5, 6, pt('Mão de Obra (Extra):'), 'L,B', 0);
$pdf->Cell(47.5, 6, pt(number_format($total_mo_extra, 2, ',', ' ') . ' EUR'), 'R,B', 0, 'R');
$pdf->SetFont('Arial', 'B', 9);
$pdf->Cell(47.5, 6, pt('MARGEM (LUCRO):'), 'L,B', 0);
if ($margem_lucro < 0) {
    $pdf->SetTextColor(231, 76, 60); // Vermelho
} else {
    $pdf->SetTextColor(39, 174, 96); // Verde
}
$pdf->Cell(47.5, 6, pt(number_format($margem_lucro, 2, ',', ' ') . ' EUR'), 'R,B', 1, 'R');
$pdf->SetTextColor(0, 0, 0); 
$pdf->SetFont('Arial', '', 9);

$pdf->Cell(47.5, 6, pt('Deslocações + Refeições:'), 'L,B', 0);
$pdf->Cell(47.5, 6, pt(number_format($total_desloc + $total_ref, 2, ',', ' ') . ' EUR'), 'R,B', 0, 'R');
$pdf->Cell(95, 6, pt(''), 'L,R,B', 1);

// --- TABELA RESUMIDA DE DESPESAS POR COLABORADOR ---
$pdf->DrawBoxTitle('RESUMO DE DESPESAS POR COLABORADOR E TIPO');

if (empty($resumo_agrupado)) {
    $pdf->SetFont('Arial', 'I', 9);
    $pdf->Cell(0, 10, pt('Não existem despesas registadas para esta obra.'), 'L,R,B', 1, 'C');
} else {
    // Cabeçalho da Tabela
    $pdf->SetFont('Arial', 'B', 8);
    $pdf->SetFillColor(240, 240, 240);
    
    // Larguras (Total = 190)
    $w = [65, 50, 40, 35]; 
    
    $pdf->Cell($w[0], 7, pt('Colaborador'), '1', 0, 'L', true);
    $pdf->Cell($w[1], 7, pt('Tipo de Despesa'), '1', 0, 'L', true);
    $pdf->Cell($w[2], 7, pt('Total Quantidade'), '1', 0, 'C', true);
    $pdf->Cell($w[3], 7, pt('Valor (EUR)'), '1', 1, 'R', true);

    $pdf->SetFont('Arial', '', 8);
    
    foreach ($resumo_agrupado as $item) {
        
        $txt_detalhe = '-';
        if ($item['quantidade'] > 0) {
            if (strpos($item['tipo_original'], 'Mão de Obra') !== false) {
                $txt_detalhe = (float)$item['quantidade'] . ' Horas';
            } elseif ($item['tipo_original'] == 'Deslocação') {
                $txt_detalhe = (float)$item['quantidade'] . ' KM';
            } else {
                $txt_detalhe = 'Qtd: ' . (float)$item['quantidade'];
            }
        }
        
        $txt_valor = number_format($item['valor'], 2, ',', ' ');
        
        // Se a página for acabar, adiciona uma nova
        if ($pdf->GetY() + 7 > 270) {
            $pdf->AddPage();
            $pdf->SetFont('Arial', 'B', 8);
            $pdf->Cell($w[0], 7, pt('Colaborador'), '1', 0, 'L', true);
            $pdf->Cell($w[1], 7, pt('Tipo de Despesa'), '1', 0, 'L', true);
            $pdf->Cell($w[2], 7, pt('Total Quantidade'), '1', 0, 'C', true);
            $pdf->Cell($w[3], 7, pt('Valor (EUR)'), '1', 1, 'R', true);
            $pdf->SetFont('Arial', '', 8);
        }

        // Desenha a linha de resumo
        // Para "Despesas Gerais", mete o texto em itálico e cinzento para se destacar
        if ($item['colaborador'] == pt('Despesas Gerais / Materiais')) {
            $pdf->SetTextColor(100, 100, 100);
            $pdf->SetFont('Arial', 'I', 8);
        } else {
            $pdf->SetTextColor(0, 0, 0);
            $pdf->SetFont('Arial', '', 8);
        }

        $pdf->Cell($w[0], 7, $item['colaborador'], '1', 0, 'L');
        $pdf->Cell($w[1], 7, $item['tipo'], '1', 0, 'L');
        $pdf->Cell($w[2], 7, pt($txt_detalhe), '1', 0, 'C');
        
        $pdf->SetFont('Arial', 'B', 8);
        $pdf->SetTextColor(0, 0, 0); // O valor volta sempre ao normal (preto)
        $pdf->Cell($w[3], 7, $txt_valor, '1', 1, 'R');
    }
}

ob_end_clean();
$filename = 'Relatorio_Financeiro_' . preg_replace('/[^A-Za-z0-9_-]/', '_', $obra['nome']) . '.pdf';
$pdf->Output('I', pt($filename));
?>