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
if (file_exists('termo_pt_937_lib.php')) require_once 'termo_pt_937_lib.php';   // assinatura (mesma do Modelo 937)

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$id) { 
    ob_end_clean();
    die("Erro: ID do Relatório PT inválido."); 
}

// --- BUSCAR DADOS DO PT E JUNÇÕES ---
try {
    $sql = "SELECT t.*, 
                   c.nome as cliente_nome, c.nif as cliente_nif, c.morada as cliente_morada,
                   pt.nome as pt_nome, pt.morada as pt_morada
            FROM termos_pt t
            LEFT JOIN clientes c ON t.id_cliente = c.id
            LEFT JOIN postos_transformacao pt ON t.id_pt = pt.id
            WHERE t.id = ?";
            
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$id]);
    $doc = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$doc) { 
        ob_end_clean();
        die("Erro: Registo PT não encontrado."); 
    }
} catch (Exception $e) {
    ob_end_clean();
    die("Erro na BD: " . htmlspecialchars($e->getMessage()));
}

// Descodificar os JSONs guardados
$med = json_decode($doc['medicoes_json'] ?? '{}', true);
$def = json_decode($doc['deficiencias_json'] ?? '{}', true);
$obs = json_decode($doc['observacoes_json'] ?? '{}', true);
$fotos = json_decode($doc['fotos_json'] ?? '[]', true);

// Condição Geral para texto
$condicao_texto = "Boas condições de segurança";
if ($doc['condicao_geral'] == 'NOK') $condicao_texto = "Condições deficientes";
if ($doc['condicao_geral'] == 'DESISTE') $condicao_texto = "Desistência de responsabilidade";

// Função de encoding para FPDF
function pt($texto) {
    $texto = (string)($texto ?? '');
    return mb_convert_encoding($texto, 'windows-1252', 'UTF-8');
}

// Otimização da imagem do logotipo/fotos
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
            $logo_bottom_y = 10 + 16; 
        }
        
        $this->SetY(12);
        $this->SetFont('Arial', 'B', 14);
        $this->SetTextColor(44, 62, 80); 
        $this->Cell(0, 8, pt($this->titulo_doc), 0, 1, 'R');
        
        // Linha Dinâmica Azul Eletricidade
        $y_text = $this->GetY();
        $y_line = max($y_text, $logo_bottom_y) + 3;
        
        $this->SetDrawColor(41, 128, 185); // Cor Azul
        $this->SetLineWidth(0.6);
        $this->Line(10, $y_line, 200, $y_line);
        
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
        $this->Ln(3);
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

    function DrawMedicao($label, $val, $unit) {
        $this->SetFont('Arial', '', 9);
        $this->Cell(120, 6, pt($label), 'L,B', 0);
        $this->SetFont('Arial', 'B', 9);
        $this->Cell(50, 6, pt($val !== '' ? $val : '-'), 'B', 0, 'R');
        $this->SetFont('Arial', '', 8);
        $this->Cell(20, 6, pt($unit), 'R,B', 1, 'L');
    }

    function DrawVerificacao($label, $hasDefect) {
        $this->SetFont('Arial', '', 8.5);
        $this->Cell(140, 6, pt($label), 'L,B', 0);
        $this->SetFont('Arial', 'B', 8.5);
        if ($hasDefect == 'sim') {
            $this->SetTextColor(192, 57, 43); // Vermelho
            $this->Cell(50, 6, pt('Deficiências Detetadas'), 'R,B', 1, 'R');
        } else {
            $this->SetTextColor(39, 174, 96); // Verde
            $this->Cell(50, 6, pt('Sem Deficiências'), 'R,B', 1, 'R');
        }
        $this->SetTextColor(0, 0, 0);
    }
}

// --- ASSINATURA DO TÉCNICO (mesma imagem do Modelo 937: fundo transparente e traço suave) ---
$assinatura = '';
if (function_exists('m937_assinatura_get')) $assinatura = m937_assinatura_get($pdo);
if (!$assinatura && is_file('assina.png')) $assinatura = 'assina.png';

// N.º de inscrição do técnico na DGEG (configurável em configuracoes: chave 'tecnico_inscricao_dgeg')
$inscricao_dgeg = '56678';
try {
    $st_dgeg = $pdo->prepare("SELECT valor FROM configuracoes WHERE chave = 'tecnico_inscricao_dgeg' LIMIT 1");
    $st_dgeg->execute();
    $v_dgeg = trim((string)$st_dgeg->fetchColumn());
    if ($v_dgeg !== '') $inscricao_dgeg = $v_dgeg;
} catch (Exception $e) {}

// N.º de inscrição da empresa (m.Abreu) na DGEG (configurável em configuracoes: chave 'empresa_inscricao_dgeg')
$inscricao_empresa = '1280';
try {
    $st_emp = $pdo->prepare("SELECT valor FROM configuracoes WHERE chave = 'empresa_inscricao_dgeg' LIMIT 1");
    $st_emp->execute();
    $v_emp = trim((string)$st_emp->fetchColumn());
    if ($v_emp !== '') $inscricao_empresa = $v_emp;
} catch (Exception $e) {}

// PREPARAÇÃO DE VARIÁVEIS
$data_inspecao = date('d/m/Y', strtotime($doc['data_inspecao']));
$periodo = date('m/Y', strtotime($doc['periodo_inicio'])) . ' a ' . date('m/Y', strtotime($doc['periodo_fim']));

// INICIALIZAR PDF
$pdf = new PDF('P', 'mm', 'A4');
$pdf->titulo_doc = 'RELATÓRIO DE EXPLORAÇÃO PT #' . str_pad($doc['numero_relatorio'], 5, "0", STR_PAD_LEFT);
$pdf->SetMargins(10, 35, 10);
$pdf->AliasNbPages();
$pdf->AddPage();
$pdf->SetAutoPageBreak(true, 20);

// --- 1. IDENTIFICAÇÃO ---
$pdf->DrawBoxTitle('IDENTIFICAÇÃO E PERÍODO');
$pdf->DrawRow('Cliente:', $doc['cliente_nome'], 'NIF:', $doc['cliente_nif'] ?: 'N/D');
$pdf->DrawRow('Posto Transf.:', $doc['pt_nome'] ?: 'N/D', 'Ref. PT:', $doc['referencia_pt'] ?: 'N/D');
$pdf->DrawRow('Morada Inst.:', $doc['pt_morada'] ?: $doc['cliente_morada'] ?: 'N/D');
$pdf->DrawRow('Inspecionado:', $data_inspecao, 'Período:', $periodo);
$pdf->DrawRow('Condição:', $condicao_texto, 'Regulamento:', $doc['dl_aplicavel']);


// --- 2. ENSAIOS E MEDIÇÕES (PT) ---
$pdf->DrawBoxTitle('1.1 ENSAIOS E MEDIÇÕES (Subestações e PT)');
$pdf->DrawMedicao('1.1.1 Resistência da terra de protecção', $med['m_1_1_1'] ?? '', 'Ohm');
$pdf->DrawMedicao('1.1.2 Resistência da terra de serviço', $med['m_1_1_2'] ?? '', 'Ohm');
$pdf->DrawMedicao('1.1.3 Resistência de isolamento da instalação de BT', $med['m_1_1_3'] ?? '', 'MOhm');
$pdf->DrawMedicao('1.1.4 Acidez e rigidez dos óleos ou dieléctricos', $med['m_1_1_4'] ?? '', '');
$pdf->DrawMedicao('1.1.5 Factor de potência (cos f)', $med['m_1_1_5'] ?? '', 'cos f');


// --- 3. VERIFICAÇÕES (PT) ---
$pdf->DrawBoxTitle('1.2 VERIFICAÇÕES (Subestações e PT)');
$pdf->DrawRow('Sistema Protecção:', $doc['sistema_protecao'] ?? 'TN'); // Info extra
$pdf->DrawVerificacao('1.2.1 Nível do óleo nos transformadores e disjuntores AT', $def['v_1_2_1'] ?? 'nao');
$pdf->DrawVerificacao('1.2.2 Estado dos contactos dos disjuntores e câmaras de corte', $def['v_1_2_2'] ?? 'nao');
$pdf->DrawVerificacao('1.2.3 Circuitos de terra e conservação dos eléctrodos', $def['v_1_2_3'] ?? 'nao');
$pdf->DrawVerificacao('1.2.4 Estado dos dispositivos de manobra (varas, tapetes)', $def['v_1_2_4'] ?? 'nao');
$pdf->DrawVerificacao('1.2.5 Carga do transformador e temperatura do óleo', $def['v_1_2_5'] ?? 'nao');
$pdf->DrawVerificacao('1.2.6 Estado de funcionamento dos dispositivos de protecção', $def['v_1_2_6'] ?? 'nao');


// --- 4. ENSAIOS UTILIZAÇÃO ---
$pdf->DrawBoxTitle('2.1 ENSAIOS E MEDIÇÕES (Instalações de Utilização)');
$pdf->DrawMedicao('2.1.1 Resistência da terra de protecção', $med['m_2_1_1'] ?? '', 'Ohm');
$pdf->DrawMedicao('2.1.2 Impedância do circuito de defeito', $med['m_2_1_2'] ?? '', 'Ohm');
$pdf->DrawMedicao('2.1.3 Resistência de isolamento', $med['m_2_1_3'] ?? '', 'MOhm');


// --- 5. VERIFICAÇÕES UTILIZAÇÃO ---
$pdf->DrawBoxTitle('2.2 VERIFICAÇÕES (Instalações de Utilização e Emergência)');
$pdf->DrawVerificacao('2.2.1 Aparelhos de protecção contra sobreintensidades', $def['v_2_2_1'] ?? 'nao');
$pdf->DrawVerificacao('2.2.2 Eficácia das protecções contra contactos indirectos', $def['v_2_2_2'] ?? 'nao');
$pdf->DrawVerificacao('2.2.3 Aquecimento e isolamento dos condutores e cabos', $def['v_2_2_3'] ?? 'nao');
$pdf->DrawVerificacao('2.2.4 Estado dos aparelhos de corte e comando', $def['v_2_2_4'] ?? 'nao');
$pdf->DrawVerificacao('2.2.5 Estado dos aparelhos de utilização', $def['v_2_2_5'] ?? 'nao');
$pdf->DrawVerificacao('2.2.6.1 Condições de arranque das fontes de emergência', $def['v_2_2_6_1'] ?? 'nao');
$pdf->DrawVerificacao('2.2.6.2 Estado das baterias (electrólito)', $def['v_2_2_6_2'] ?? 'nao');
$pdf->DrawVerificacao('2.2.6.3 Estado de funcionamento dos blocos autónomos', $def['v_2_2_6_3'] ?? 'nao');


// --- 6. OBSERVAÇÕES E NOTAS ---
$observacoes_filtro = [
    '2.2.7 Métodos suscetíveis de provocar contactos diretos' => $obs['obs_2_2_7'] ?? '',
    '2.2.8 Incorrecções na execução de trabalhos' => $obs['obs_2_2_8'] ?? '',
    '2.2.11/12 Utilizações diferentes / Redimensionamento' => $obs['obs_2_2_11'] ?? '',
    '4. Modificações e Ampliações não consultadas' => $obs['obs_4'] ?? '',
    '5. Relações com o Proprietário / Medidas Pendentes' => $obs['obs_5'] ?? ''
];

$has_obs = false;
foreach ($observacoes_filtro as $texto) { if (!empty(trim($texto))) $has_obs = true; }

if ($has_obs) {
    // Adiciona quebra se estiver no limite do rodapé
    if ($pdf->GetY() > 230) { $pdf->AddPage(); }
    
    $pdf->DrawBoxTitle('ANOMALIAS, OBSERVAÇÕES E NOTAS');
    $pdf->Ln(2);
    foreach ($observacoes_filtro as $titulo => $texto) {
        if (!empty(trim($texto))) {
            $pdf->SetFont('Arial', 'B', 9);
            $pdf->SetTextColor(44, 62, 80);
            $pdf->Cell(0, 6, pt($titulo), 0, 1, 'L');
            $pdf->SetFont('Arial', '', 9);
            $pdf->SetTextColor(0, 0, 0);
            $pdf->MultiCell(0, 5, pt($texto), 0, 'J');
            $pdf->Ln(3);
        }
    }
}


// --- 7. REGISTO FOTOGRÁFICO ---
if (!empty($fotos) && is_array($fotos)) {
    if ($pdf->GetY() > 220) { $pdf->AddPage(); }
    
    $pdf->DrawBoxTitle('REGISTO FOTOGRÁFICO ANEXADO');
    $pdf->Ln(4);
    
    $x_start = 10; $x = $x_start; $y = $pdf->GetY();
    $w_img = 60; // 3 fotos por linha
    $margin_right = 5;
    $row_max_h = 0;
    
    foreach ($fotos as $f) {
        $path = 'imagens/' . $f;
        if (file_exists($path)) {
            $info = @getimagesize($path);
            if ($info) {
                $largura_orig = $info[0];
                $altura_orig = $info[1];
                $h_img = floor(($altura_orig / $largura_orig) * $w_img); 
                
                if ($y + $h_img > 270) {
                    $pdf->AddPage();
                    $y = $pdf->GetY();
                    $x = $x_start;
                    $row_max_h = 0;
                }
                
                $pdf->Image($path, $x, $y, $w_img, 0); 
                if ($h_img > $row_max_h) { $row_max_h = $h_img; }
                
                $x += $w_img + $margin_right;
                if ($x + $w_img > 200) {
                    $x = $x_start;
                    $y += $row_max_h + 5; 
                    $row_max_h = 0;
                }
            }
        }
    }
    // Avança o Y do PDF para baixo das fotos para imprimir a assinatura depois
    $pdf->SetY($y + $row_max_h + 10);
}


// --- 8. ASSINATURA TÉCNICO ---
$pdf->Ln(10);
if ($pdf->GetY() > 228) { $pdf->AddPage(); $pdf->Ln(10); }

$y_assinaturas = $pdf->GetY();

$pdf->SetFont('Arial', 'B', 9);
$pdf->SetXY(60, $y_assinaturas);
$pdf->Cell(90, 5, pt('O Técnico Responsável pela Exploração'), 0, 1, 'C');

$y_linha_ass = $pdf->GetY() + 15;
$pdf->Line(60, $y_linha_ass, 150, $y_linha_ass);

// Assinatura por cima da linha (centrada, assente na linha como à mão)
if ($assinatura && is_file($assinatura)) {
    $inf_ass = @getimagesize($assinatura);
    if ($inf_ass && in_array($inf_ass[2], [IMAGETYPE_PNG, IMAGETYPE_JPEG], true)) {
        $f_ass = min(62 / $inf_ass[0], 15 / $inf_ass[1]);          // máx. 62 x 15 mm
        $w_ass = $inf_ass[0] * $f_ass; $h_ass = $inf_ass[1] * $f_ass;
        try {
            $pdf->Image($assinatura, 105 - $w_ass / 2, $y_linha_ass - $h_ass * 0.85, $w_ass, $h_ass, $inf_ass[2] === IMAGETYPE_PNG ? 'PNG' : 'JPG');
        } catch (Throwable $e) {}
    }
}

$pdf->SetXY(60, $pdf->GetY() + 18);
$pdf->SetFont('Arial', 'B', 9);
$pdf->Cell(90, 5, pt($doc['tecnico_nome'] ?? 'Mário Abreu'), 0, 1, 'C');

$pdf->SetXY(60, $pdf->GetY());
$pdf->SetFont('Arial', '', 8);
$pdf->Cell(90, 5, pt('Inscrição DGEG n.º ' . $inscricao_dgeg), 0, 1, 'C');

$pdf->SetXY(60, $pdf->GetY());
$pdf->Cell(90, 5, pt('m.Abreu - Instalações Técnicas: inscrição DGEG n.º ' . $inscricao_empresa), 0, 0, 'C');

ob_end_clean();
$nome_cliente = preg_replace('/[^A-Za-z0-9_-]/', '_', $doc['cliente_nome']);
$filename = 'PT_' . $doc['numero_relatorio'] . '_' . $nome_cliente . '.pdf';
$pdf->Output('I', pt($filename));
?>
