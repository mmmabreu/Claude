<?php
// =====================================================================
// AUTO DE ENTREGA DE EQUIPAMENTO / FICHA DE EQUIPAMENTO GERAL
// - equipamento_pdf.php?id=12        → um equipamento (auto de entrega ou ficha)
// - equipamento_pdf.php?ids=12,15,18 → vários equipamentos individuais num só auto de entrega
//   (uma folha por colaborador, com todos os equipamentos dele numa tabela)
// =====================================================================
// REGRA DE OURO 4: Limpar buffer para evitar falhas de PDF
ob_start();
session_start();
require_once 'db.php';
require_once('fpdf/fpdf.php');

// REGRA DE OURO 1: Validação de sessão
if (!isset($_SESSION['user_id'])) { 
    ob_end_clean(); 
    die("Acesso negado."); 
}

$id_utilizador_logado = $_SESSION['user_id'];
$perfil_user = $_SESSION['perfil'] ?? '';
$is_admin = in_array($perfil_user, ['admin', 'administrativo']);

// REGRA DE OURO 2: Acesso restrito pela matriz de permissões
$stmt_perm = $pdo->prepare("SELECT modulo FROM permissoes WHERE grupo = :perfil AND pode_aceder = 1");
$stmt_perm->execute(['perfil' => $perfil_user]);
if (!$is_admin && !in_array('equipamentos', $stmt_perm->fetchAll(PDO::FETCH_COLUMN))) {
    ob_end_clean();
    die("Acesso negado.");
}

// REGRA DE OURO 5: IDs validados como números inteiros
$ids = [];
if (isset($_GET['ids'])) {
    foreach (explode(',', (string)$_GET['ids']) as $x) {
        $v = filter_var(trim($x), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($v) $ids[$v] = $v;
    }
} else {
    $v = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($v) $ids[$v] = $v;
}
$ids = array_values($ids);
if (!$ids || count($ids) > 200) {
    ob_end_clean();
    die("Equipamento não especificado.");
}
$varios = count($ids) > 1;
$id_equipamento = $ids[0];

// --- LER DADOS DO EQUIPAMENTO E DO UTILIZADOR (um só equipamento) ---
if (!$varios) {
$sql = "SELECT e.*, 
               u.nome as nome_colaborador,
               c.nome as nome_cliente,
               o.nome as nome_obra,
               o.referencia_obra as ref_obra,
               col.nome as nome_colab_levou
        FROM equipamentos e 
        LEFT JOIN utilizadores u ON e.id_utilizador = u.id 
        LEFT JOIN clientes c ON e.id_cliente = c.id
        LEFT JOIN obras o ON e.id_obra = o.id
        LEFT JOIN utilizadores col ON e.id_colaborador_levou = col.id
        WHERE e.id = ?";
$stmt = $pdo->prepare($sql);
$stmt->execute([$id_equipamento]);
$equipamento = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$equipamento) {
    ob_end_clean();
    die("Equipamento não encontrado.");
}

// Segurança extra para equipamento individual:
if ($equipamento['tipo'] === 'individual' && !$is_admin && $equipamento['id_utilizador'] != $id_utilizador_logado) {
    ob_end_clean();
    die("Acesso negado a este documento.");
}

$data_entrega = date('d/m/Y', strtotime($equipamento['data_registo']));
$nome_colaborador = $equipamento['nome_colaborador'] ?? 'Colaborador não identificado';
}

// --- FUNÇÕES AUXILIARES ---
function pt($texto) {
    return mb_convert_encoding((string)($texto ?? ''), 'windows-1252', 'UTF-8');
}

function otimizarImagem($ficheiro) {
    if (!file_exists($ficheiro)) return false;
    $nome_otimizado = 'min_' . $ficheiro;
    if (file_exists($nome_otimizado)) return $nome_otimizado;
    
    $info = @getimagesize($ficheiro);
    if (!$info) return $ficheiro;
    
    $largura = $info[0];
    $altura = $info[1];
    $tipo = $info[2];
    $max_largura = 350;
    
    if ($largura <= $max_largura) return $ficheiro; 
    
    $nova_largura = $max_largura;
    $nova_altura = floor(($altura / $largura) * $nova_largura);
    $nova_img = imagecreatetruecolor($nova_largura, $nova_altura);
    
    if ($tipo == IMAGETYPE_PNG) {
        imagealphablending($nova_img, false);
        imagesavealpha($nova_img, true);
        $transparente = imagecolorallocatealpha($nova_img, 255, 255, 255, 127);
        imagefilledrectangle($nova_img, 0, 0, $nova_largura, $nova_altura, $transparente);
        $origem = @imagecreatefrompng($ficheiro);
    } elseif ($tipo == IMAGETYPE_JPEG) {
        $origem = @imagecreatefromjpeg($ficheiro);
    } else {
        return $ficheiro;
    }
    
    if ($origem) {
        if ($tipo == IMAGETYPE_PNG) imagealphablending($origem, true);
        imagecopyresampled($nova_img, $origem, 0, 0, 0, 0, $nova_largura, $nova_altura, $largura, $altura);
        if ($tipo == IMAGETYPE_PNG) imagepng($nova_img, $nome_otimizado, 9);
        else imagejpeg($nova_img, $nome_otimizado, 80);
        imagedestroy($nova_img);
        imagedestroy($origem);
        return $nome_otimizado;
    }
    return $ficheiro;
}

// --- CONFIGURAÇÃO DO PDF ---
class PDF extends FPDF {
    public $titulo_doc;

    // Nº de linhas que um texto ocupa numa célula de largura $w (para alinhar a altura das linhas da tabela)
    function NbLines($w, $txt) {
        $cw = &$this->CurrentFont['cw']; if ($w == 0) $w = $this->w - $this->rMargin - $this->x;
        $wmax = ($w - 2 * $this->cMargin) * 1000 / $this->FontSize;
        $s = str_replace("\r", '', (string)$txt); $nb = strlen($s);
        if ($nb > 0 && $s[$nb - 1] == "\n") $nb--;
        $i = 0; $j = 0; $l = 0; $nl = 1; $sep = -1;
        while ($i < $nb) {
            $c = $s[$i];
            if ($c == "\n") { $i++; $sep = -1; $j = $i; $l = 0; $nl++; continue; }
            if ($c == ' ') $sep = $i;
            $l += $cw[$c] ?? 500;
            if ($l > $wmax) { if ($sep == -1) { if ($i == $j) $i++; } else $i = $sep + 1; $sep = -1; $j = $i; $l = 0; $nl++; } else $i++;
        }
        return $nl;
    }
    
    function Header() {
        $logo = otimizarImagem('mab.png');
        if ($logo && file_exists($logo)) {
            // Tamanho reduzido para 80% (de 25 para 20)
            $this->Image($logo, 15, 12, 20);
        }
        $this->SetY(20);
        $this->SetFont('Arial', 'B', 16);
        $this->SetTextColor(44, 62, 80);
        $this->Cell(0, 8, pt($this->titulo_doc), 0, 1, 'C');
        
        $this->SetY(35);
        $this->SetDrawColor(200, 200, 200);
        $this->SetLineWidth(0.5);
        $this->Line(15, 33, 195, 33);
        $this->Ln(5);
    }

    function Footer() {
        $this->SetY(-15);
        $this->SetDrawColor(200, 200, 200);
        $this->SetLineWidth(0.2);
        $this->Line(15, $this->GetY(), 195, $this->GetY());
        $this->Ln(2);
        
        $this->SetFont('Arial', 'B', 8);
        $this->SetTextColor(100, 100, 100);
        $this->Cell(0, 4, pt('m. Abreu - Instalações Técnicas, Lda. | NIF: 514 414 120'), 0, 1, 'C');
        $this->SetFont('Arial', '', 8);
        $this->Cell(0, 4, pt('Rua Damião de Góis, 205, Braga | Email: geral@mabreu.pt'), 0, 1, 'C');
    }
}


// Assinaturas do auto de entrega (colaborador + gerência)
function assinaturasEntrega($pdf, $nome_colaborador) {
    $pdf->SetFont('Arial', 'B', 9);
    $y_assinaturas = $pdf->GetY();

    // Assinatura Colaborador
    $pdf->SetXY(20, $y_assinaturas);
    $pdf->Cell(70, 5, pt('O Colaborador'), 0, 1, 'C');
    $pdf->Line(20, $pdf->GetY() + 15, 90, $pdf->GetY() + 15);
    $pdf->SetXY(20, $pdf->GetY() + 18);
    $pdf->SetFont('Arial', '', 8);
    $pdf->Cell(70, 5, pt($nome_colaborador), 0, 0, 'C');

    // Assinatura Gerência
    $pdf->SetFont('Arial', 'B', 9);
    $pdf->SetXY(115, $y_assinaturas);
    $pdf->Cell(70, 5, pt('A Gerência'), 0, 1, 'C');
    $y_linha_gerencia = $pdf->GetY() + 15;
    $pdf->Line(115, $y_linha_gerencia, 185, $y_linha_gerencia);

    $assina = otimizarImagem('assina.png');
    if ($assina && file_exists($assina)) {
        $img_width = 35;
        $img_height = 13;
        $x_center = 115 + (70 - $img_width) / 2;
        $y_img = $y_linha_gerencia - $img_height + 1;
        $pdf->Image($assina, $x_center, $y_img, $img_width, $img_height);
    }
}

// =====================================================================
// VÁRIOS EQUIPAMENTOS NUM SÓ AUTO DE ENTREGA (uma folha por colaborador)
// =====================================================================
if ($varios) {
    $marcadores = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("SELECT e.*, u.nome AS nome_colaborador
                           FROM equipamentos e
                           LEFT JOIN utilizadores u ON e.id_utilizador = u.id
                           WHERE e.id IN ($marcadores) AND e.tipo = 'individual'
                           ORDER BY e.data_registo ASC, e.descricao ASC, e.id ASC");
    $stmt->execute($ids);
    $lista = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!$lista) {
        ob_end_clean();
        die("Equipamento não encontrado.");
    }

    // Por colaborador (ordem alfabética); cada colaborador só pode imprimir o seu equipamento
    $por_colab = [];
    foreach ($lista as $eq) {
        if (!$is_admin && $eq['id_utilizador'] != $id_utilizador_logado) {
            ob_end_clean();
            die("Acesso negado a este documento.");
        }
        $por_colab[(int)$eq['id_utilizador']][] = $eq;
    }
    uasort($por_colab, function ($a, $b) {
        return strnatcasecmp((string)($a[0]['nome_colaborador'] ?? ''), (string)($b[0]['nome_colaborador'] ?? ''));
    });

    $pdf = new PDF('P', 'mm', 'A4');
    $pdf->titulo_doc = 'AUTO DE ENTREGA DE EQUIPAMENTO';
    $pdf->SetMargins(15, 15, 15);
    $pdf->SetAutoPageBreak(false);
    $pdf->AliasNbPages();

    // Colunas: Data | Descrição | Marca / Modelo | Nº de Série | Estado  (= 180 mm)
    $w = [22, 60, 38, 36, 24];
    $cabecalho = function () use ($pdf, $w) {
        $pdf->SetFont('Arial', 'B', 9);
        $pdf->SetFillColor(241, 242, 246);
        $pdf->SetDrawColor(200, 200, 200);
        $pdf->SetLineWidth(0.2);
        $tit = ['Data Entrega', 'Descrição', 'Marca / Modelo', 'Nº de Série', 'Estado'];
        foreach ($tit as $i => $t) $pdf->Cell($w[$i], 8, pt($t), 1, 0, $i === 0 || $i === 4 ? 'C' : 'L', true);
        $pdf->Ln();
    };

    foreach ($por_colab as $equipamentos) {
        $nome_colaborador = $equipamentos[0]['nome_colaborador'] ?? 'Colaborador não identificado';
        $n = count($equipamentos);
        $ultima_data = max(array_map(fn($x) => strtotime($x['data_registo']), $equipamentos));

        $pdf->AddPage();
        $pdf->SetFont('Arial', '', 11);
        $pdf->SetTextColor(0, 0, 0);
        $texto_declaracao = "Eu, " . $nome_colaborador . ", declaro para os devidos efeitos que recebi da empresa m. Abreu - Instalações Técnicas, Lda., para uso exclusivo e indispensável no desempenho das minhas funções profissionais, "
                          . ($n === 1 ? "o equipamento de trabalho abaixo discriminado:" : "os " . $n . " equipamentos de trabalho abaixo discriminados:");
        $pdf->MultiCell(0, 7, pt($texto_declaracao), 0, 'J');
        $pdf->Ln(6);

        $cabecalho();
        $pdf->SetFont('Arial', '', 9);
        $lh = 5;
        foreach ($equipamentos as $eq) {
            $desc = pt($eq['descricao']);
            $obs = trim((string)$eq['observacoes']);
            if ($obs !== '') $desc .= "\n" . pt('Obs.: ' . $obs);
            $marca = pt($eq['marca'] ?: '-');
            $serie = pt($eq['numero_serie'] ?: '-');
            $linhas = max($pdf->NbLines($w[1], $desc), $pdf->NbLines($w[2], $marca), $pdf->NbLines($w[3], $serie), 1);
            $h = $linhas * $lh + 3;

            if ($pdf->GetY() + $h > 270) {   // a tabela continua na folha seguinte
                $pdf->AddPage();
                $cabecalho();
                $pdf->SetFont('Arial', '', 9);
            }
            $x = $pdf->GetX(); $y = $pdf->GetY();
            $valores = [pt(date('d/m/Y', strtotime($eq['data_registo']))), $desc, $marca, $serie, pt($eq['estado'])];
            foreach ($valores as $i => $v) {
                $pdf->Rect($x, $y, $w[$i], $h);
                $pdf->SetXY($x, $y + 1.5);
                $pdf->MultiCell($w[$i], $lh, $v, 0, $i === 0 || $i === 4 ? 'C' : 'L');
                $x += $w[$i];
            }
            $pdf->SetXY(15, $y + $h);
        }
        $pdf->Ln(8);

        // Compromisso, data e assinaturas ficam juntos na mesma folha
        if ($pdf->GetY() + 100 > 280) $pdf->AddPage();
        $pdf->SetFont('Arial', '', 11);
        $texto_compromisso = ($n === 1 ? "Comprometo-me a zelar pelo seu bom estado de conservação, limpeza e funcionamento. Declaro ter plena consciência de que este equipamento é propriedade exclusiva da empresa, devendo ser devolvido"
                                       : "Comprometo-me a zelar pelo seu bom estado de conservação, limpeza e funcionamento. Declaro ter plena consciência de que estes equipamentos são propriedade exclusiva da empresa, devendo ser devolvidos")
                           . " imediatamente caso me seja solicitado ou no momento da cessação das minhas funções, seja por que motivo for.\n\nQualquer dano causado por uso indevido, negligência, bem como o extravio "
                           . ($n === 1 ? "do mesmo" : "dos mesmos") . ", será da minha inteira responsabilidade.";
        $pdf->MultiCell(0, 6, pt($texto_compromisso), 0, 'J');
        $pdf->Ln(12);
        $pdf->Cell(0, 6, pt("Braga, " . date('d \d\e m \d\e Y', $ultima_data)), 0, 1, 'L');
        $pdf->Ln(12);
        assinaturasEntrega($pdf, $nome_colaborador);
    }

    ob_end_clean();
    if (count($por_colab) === 1) {
        $nome_ficheiro = 'Entrega_' . preg_replace('/[^A-Za-z0-9_-]/', '_', $nome_colaborador);
    } else {
        $nome_ficheiro = 'Entregas_Equipamento';
    }
    $pdf->Output('I', pt($nome_ficheiro . '_' . date('d_m_Y') . '.pdf'));
    exit;
}

$pdf = new PDF('P', 'mm', 'A4');
$pdf->titulo_doc = ($equipamento['tipo'] === 'individual') ? 'AUTO DE ENTREGA DE EQUIPAMENTO' : 'FICHA DE EQUIPAMENTO GERAL';
$pdf->SetMargins(15, 15, 15);
$pdf->AliasNbPages();
$pdf->AddPage();

$pdf->SetFont('Arial', '', 11);
$pdf->SetTextColor(0, 0, 0);

// --- CORPO DO TEXTO ---
if ($equipamento['tipo'] === 'individual') {
    $texto_declaracao = "Eu, " . $nome_colaborador . ", declaro para os devidos efeitos que recebi da empresa m. Abreu - Instalações Técnicas, Lda., para uso exclusivo e indispensável no desempenho das minhas funções profissionais, o equipamento de trabalho abaixo discriminado:";
} else {
    $texto_declaracao = "O seguinte equipamento encontra-se registado como propriedade e ferramenta geral da empresa m. Abreu - Instalações Técnicas, Lda., estando alocado à frota ou uso partilhado.";
}

$pdf->MultiCell(0, 7, pt($texto_declaracao), 0, 'J');
$pdf->Ln(8);

// --- QUADRO DO EQUIPAMENTO ---
$pdf->SetFont('Arial', 'B', 10);
$pdf->SetFillColor(241, 242, 246);
$pdf->Cell(0, 8, pt(' DETALHES DO EQUIPAMENTO'), 1, 1, 'L', true);

$pdf->SetFont('Arial', '', 10);

$pdf->SetFont('Arial', 'B', 10); $pdf->Cell(45, 8, pt($equipamento['tipo'] === 'individual' ? 'Data de Entrega:' : 'Data de Compra:'), 'L', 0);
$pdf->SetFont('Arial', '', 10); $pdf->Cell(0, 8, pt($data_entrega), 'R', 1);

$pdf->SetFont('Arial', 'B', 10); $pdf->Cell(45, 8, pt('Descrição:'), 'L', 0);
$pdf->SetFont('Arial', '', 10); $pdf->Cell(0, 8, pt($equipamento['descricao']), 'R', 1);

$pdf->SetFont('Arial', 'B', 10); $pdf->Cell(45, 8, pt('Marca / Modelo:'), 'L', 0);
$pdf->SetFont('Arial', '', 10); $pdf->Cell(0, 8, pt($equipamento['marca'] ?: 'N/A'), 'R', 1);

$pdf->SetFont('Arial', 'B', 10); $pdf->Cell(45, 8, pt('Nº de Série:'), 'L', 0);
$pdf->SetFont('Arial', '', 10); $pdf->Cell(0, 8, pt($equipamento['numero_serie'] ?: 'N/A'), 'R', 1);

if ($equipamento['tipo'] === 'individual') {
    $pdf->SetFont('Arial', 'B', 10); $pdf->Cell(45, 8, pt('Estado da Entrega:'), 'L', 0);
    $pdf->SetFont('Arial', '', 10); $pdf->Cell(0, 8, pt($equipamento['estado']), 'R', 1);
} else {
    $pdf->SetFont('Arial', 'B', 10); $pdf->Cell(45, 8, pt('Fornecedor:'), 'L', 0);
    $pdf->SetFont('Arial', '', 10); $pdf->Cell(0, 8, pt($equipamento['fornecedor'] ?: 'N/A'), 'R', 1);

    // --- Localização do equipamento geral ---
    $loc = $equipamento['localizacao'] ?? 'empresa';
    if ($loc === 'obra')           { $loc_label_pdf = 'Em obra'; }
    elseif ($loc === 'emprestado') { $loc_label_pdf = 'Emprestado'; }
    else                           { $loc_label_pdf = 'Na empresa'; }

    $pdf->SetFont('Arial', 'B', 10); $pdf->Cell(45, 8, pt('Localização:'), 'L', 0);
    $pdf->SetFont('Arial', '', 10); $pdf->Cell(0, 8, pt($loc_label_pdf), 'R', 1);

    if ($loc === 'obra') {
        $obra_txt = $equipamento['nome_obra'] ?? '';
        if (!empty($equipamento['ref_obra'])) $obra_txt .= ' (' . $equipamento['ref_obra'] . ')';

        $pdf->SetFont('Arial', 'B', 10); $pdf->Cell(45, 8, pt('Cliente:'), 'L', 0);
        $pdf->SetFont('Arial', '', 10); $pdf->Cell(0, 8, pt($equipamento['nome_cliente'] ?: 'N/A'), 'R', 1);

        $pdf->SetFont('Arial', 'B', 10); $pdf->Cell(45, 8, pt('Obra:'), 'L', 0);
        $pdf->SetFont('Arial', '', 10); $pdf->Cell(0, 8, pt($obra_txt ?: 'N/A'), 'R', 1);

        $pdf->SetFont('Arial', 'B', 10); $pdf->Cell(45, 8, pt('Levado por:'), 'L', 0);
        $pdf->SetFont('Arial', '', 10); $pdf->Cell(0, 8, pt($equipamento['nome_colab_levou'] ?: 'N/A'), 'R', 1);
    } elseif ($loc === 'emprestado') {
        $emp_a = !empty($equipamento['nome_cliente']) ? $equipamento['nome_cliente'] : ($equipamento['emprestado_nome'] ?? '');
        $pdf->SetFont('Arial', 'B', 10); $pdf->Cell(45, 8, pt('Emprestado a:'), 'L', 0);
        $pdf->SetFont('Arial', '', 10); $pdf->Cell(0, 8, pt($emp_a ?: 'N/A'), 'R', 1);
    }
}

$obs = trim($equipamento['observacoes']);
if (!empty($obs)) {
    $pdf->SetFont('Arial', 'B', 10); 
    $pdf->Cell(45, 8, pt('Observações:'), 'L', 0);
    $pdf->SetFont('Arial', '', 10); 
    $pdf->MultiCell(0, 8, pt($obs), 'R', 'L');
} else {
    $pdf->SetFont('Arial', 'B', 10); $pdf->Cell(45, 8, pt('Observações:'), 'L,B', 0);
    $pdf->SetFont('Arial', '', 10); $pdf->Cell(0, 8, pt('-'), 'R,B', 1);
}

if (!empty($obs)) {
    $pdf->Cell(0, 0, '', 'T', 1); 
}

$pdf->Ln(10);

// --- COMPROMISSO E ASSINATURAS ---
if ($equipamento['tipo'] === 'individual') {
    $texto_compromisso = "Comprometo-me a zelar pelo seu bom estado de conservação, limpeza e funcionamento. Declaro ter plena consciência de que este equipamento é propriedade exclusiva da empresa, devendo ser devolvido imediatamente caso me seja solicitado ou no momento da cessação das minhas funções, seja por que motivo for.\n\nQualquer dano causado por uso indevido, negligência, bem como o extravio do mesmo, será da minha inteira responsabilidade.";

    $pdf->MultiCell(0, 6, pt($texto_compromisso), 0, 'J');
    $pdf->Ln(20);

    $pdf->Cell(0, 6, pt("Braga, " . date('d \d\e m \d\e Y', strtotime($equipamento['data_registo']))), 0, 1, 'L');
    $pdf->Ln(15);

    assinaturasEntrega($pdf, $nome_colaborador);
} else {
    // Equipamento Geral (Apenas data)
    $pdf->Ln(10);
    $pdf->Cell(0, 6, pt("Braga, " . date('d \d\e m \d\e Y')), 0, 1, 'L');
}

ob_end_clean();
$nome_ficheiro = $equipamento['tipo'] === 'individual' ? 'Entrega_' . preg_replace('/[^A-Za-z0-9_-]/', '_', $nome_colaborador) : 'Ficha_' . preg_replace('/[^A-Za-z0-9_-]/', '_', $equipamento['descricao']);
$filename = $nome_ficheiro . '_' . date('d_m_Y') . '.pdf';
$pdf->Output('I', pt($filename));
?>