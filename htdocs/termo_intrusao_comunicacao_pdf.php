<?php
// COMUNICAÇÃO DE INSTALAÇÃO DE ALARME À PSP/GNR
// (Lei n.º 34/2013, de 16 de maio, art. 11.º, n.º 1 - alterada pela Lei n.º 46/2019, de 8 de julho)
// Réplica do impresso oficial, já preenchida com os dados da ficha de intrusão.

// Limpar o buffer para evitar erros na geração do PDF
ob_start();
session_start();
require_once 'db.php';
require_once('fpdf/fpdf.php');

if (!isset($_SESSION['user_id'])) {
    ob_end_clean();
    die("Acesso negado.");
}

$id_termo = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$id_termo) {
    ob_end_clean();
    die("ID da instalação inválido.");
}

// --- CONFIGURAÇÃO DA EMPRESA ---
$NOME_EMPRESA = 'm.Abreu - Instalações Técnicas, Unipessoal, Lda.';
$REGISTO_PREVIO_EMPRESA = '4944';
$LOCALIDADE_ASSINATURA = 'Braga';

// ---------------------------------------------------------
// FUNÇÕES AUXILIARES
// ---------------------------------------------------------
function pt($texto) {
    return mb_convert_encoding((string)($texto ?? ''), 'windows-1252', 'UTF-8');
}

// Minúsculas e sem acentos (para procurar "sirene exterior", "pânico", etc.)
function norm($s) {
    $s = mb_strtolower((string)$s, 'UTF-8');
    return strtr($s, ['á'=>'a','à'=>'a','ã'=>'a','â'=>'a','é'=>'e','ê'=>'e','í'=>'i','ó'=>'o','õ'=>'o','ô'=>'o','ú'=>'u','ç'=>'c']);
}

function dividirCP($cp) {
    if (preg_match('/(\d{4})\s*-?\s*(\d{3})/', (string)$cp, $m)) return [$m[1], $m[2]];
    return ['', ''];
}

// Separa a morada da instalação em morada / localidade / código postal
function separarMoradaObra($txt, $cli) {
    $txt = trim((string)$txt);
    if ($txt === '') return ['', '', ['', '']];

    // Caso a morada traga o código postal: "Rua X, 12, 4700-103 Braga"
    if (preg_match('/^(.*?)[,\s]*(\d{4})\s*-\s*(\d{3})\s*(.*)$/u', $txt, $m)) {
        return [trim(rtrim(trim($m[1]), ',')), trim($m[4]), [$m[2], $m[3]]];
    }

    // Caso termine na localidade do cliente (o formulário junta "morada, cidade")
    $cidade = trim((string)($cli['cidade'] ?? ''));
    $morada = $txt; $loc = ''; $cp = ['', ''];
    if ($cidade !== '' && mb_strtolower(mb_substr($txt, -mb_strlen($cidade, 'UTF-8'), null, 'UTF-8'), 'UTF-8') === mb_strtolower($cidade, 'UTF-8')) {
        $loc = $cidade;
        $morada = trim(rtrim(trim(mb_substr($txt, 0, mb_strlen($txt, 'UTF-8') - mb_strlen($cidade, 'UTF-8'), 'UTF-8')), ','));
        // Se for a mesma morada do cliente, aproveita o código postal dele
        if (mb_strtolower($morada, 'UTF-8') === mb_strtolower(trim((string)($cli['morada'] ?? '')), 'UTF-8')) {
            $cp = dividirCP($cli['codigo_postal'] ?? '');
        }
    }
    return [$morada, $loc, $cp];
}

// Telemóvel (9...) e telefone fixo (2...) do cliente
function classificarTelefones($cli) {
    $tlm = ''; $tlf = '';
    foreach (['telemovel', 'telefone', 'telefone1', 'telefone2', 'tel'] as $col) {
        $v = trim((string)($cli[$col] ?? ''));
        if ($v === '') continue;
        $d = preg_replace('/\D+/', '', $v);
        if (strpos($d, '351') === 0 && strlen($d) > 9) $d = substr($d, 3);
        $primeiro = ($d !== '') ? $d[0] : '';
        if ($primeiro === '9') { if ($tlm === '') $tlm = $v; }
        elseif ($primeiro === '2') { if ($tlf === '') $tlf = $v; }
        else {
            if ($col === 'telemovel') { if ($tlm === '') $tlm = $v; }
            else { if ($tlf === '') $tlf = $v; }
        }
    }
    return [$tlm, $tlf];
}

// Otimizador de imagens (igual ao dos outros PDFs)
function otimizarImagem($ficheiro) {
    if (!file_exists($ficheiro)) return false;

    $nome_otimizado = 'min_' . $ficheiro;
    if (file_exists($nome_otimizado)) return $nome_otimizado;

    $info = @getimagesize($ficheiro);
    if (!$info) return $ficheiro;

    $largura = $info[0];
    $altura = $info[1];
    $tipo = $info[2];

    $max_largura = 700;
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
        if ($tipo == IMAGETYPE_PNG) { imagepng($nova_img, $nome_otimizado, 9); }
        else { imagejpeg($nova_img, $nome_otimizado, 80); }
        imagedestroy($nova_img); imagedestroy($origem);
        return $nome_otimizado;
    }
    return $ficheiro;
}

// ---------------------------------------------------------
// LER DADOS
// ---------------------------------------------------------
$stmt = $pdo->prepare("SELECT * FROM termos_intrusao WHERE id = ?");
$stmt->execute([$id_termo]);
$termo = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$termo) {
    ob_end_clean();
    die("Instalação não encontrada.");
}

$cli = [];
if (!empty($termo['id_cliente'])) {
    $stmt_cli = $pdo->prepare("SELECT * FROM clientes WHERE id = ?");
    $stmt_cli->execute([$termo['id_cliente']]);
    $cli = $stmt_cli->fetch(PDO::FETCH_ASSOC) ?: [];
}

$equipamentos = [];
try {
    $stmt_eq = $pdo->prepare("SELECT * FROM termos_intrusao_equipamentos WHERE id_termo = ? ORDER BY id ASC");
    $stmt_eq->execute([$id_termo]);
    $equipamentos = $stmt_eq->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $equipamentos = [];
}

// --- Equipamentos relevantes: sirene exterior e botão de pânico ---
$sirenes_ext = [];
$tem_panico = false;
foreach ($equipamentos as $eq) {
    $tipo = norm($eq['equipamento'] ?? '');
    if (strpos($tipo, 'sirene') !== false && strpos($tipo, 'exterior') !== false) {
        $sirenes_ext[] = $eq;
    }
    if (strpos($tipo, 'panico') !== false) $tem_panico = true;
}

// --- 1. Comunicação (proprietário/utilizador = cliente) ---
$c_nome   = trim((string)($cli['nome'] ?? ''));
$c_morada = trim((string)($cli['morada'] ?? ''));
$c_local  = trim((string)($cli['cidade'] ?? ''));
list($c_cp4, $c_cp3) = dividirCP($cli['codigo_postal'] ?? '');
list($c_tlm, $c_tlf) = classificarTelefones($cli);
$c_email  = trim((string)($cli['email'] ?? ''));
// Se a morada do cliente trouxer o código postal/localidade juntos, separa-os
if ($c_cp4 === '' && preg_match('/\d{4}\s*-\s*\d{3}/', $c_morada)) {
    list($mm, $ll, $cc) = separarMoradaObra($c_morada, $cli);
    $c_morada = $mm; if ($c_local === '') $c_local = $ll; list($c_cp4, $c_cp3) = $cc;
}

// --- Local objeto de proteção ---
$local_obra = trim((string)($termo['nome_obra'] ?? ''));
list($o_morada, $o_local, $o_cp) = separarMoradaObra($termo['morada_obra'] ?? '', $cli);

// --- Características do alarme (central) ---
$a_marca  = trim((string)($termo['marca_central'] ?? ''));
$a_modelo = trim((string)($termo['ref_central'] ?? ($termo['modelo_central'] ?? '')));
$instalado_por = $NOME_EMPRESA . ' (Registo Prévio n.º ' . $REGISTO_PREVIO_EMPRESA . ')';

// --- Observações: identificação da(s) sirene(s) exterior(es) ---
$obs_partes = [];
foreach ($sirenes_ext as $s) {
    $mm = trim(trim((string)($s['marca'] ?? '')) . ' ' . trim((string)($s['modelo'] ?? '')));
    $txt = 'Sirene exterior' . ($mm !== '' ? ': ' . $mm : '');
    if (trim((string)($s['numero_serie'] ?? '')) !== '') $txt .= ' (N.º série ' . trim($s['numero_serie']) . ')';
    if (trim((string)($s['localizacao'] ?? '')) !== '')  $txt .= ' - ' . trim($s['localizacao']);
    $obs_partes[] = $txt;
}
$observacoes = implode('; ', $obs_partes);

$data_doc = !empty($termo['data_emissao']) ? date('d/m/Y', strtotime($termo['data_emissao'])) : date('d/m/Y');

// ---------------------------------------------------------
// CLASSE PDF
// ---------------------------------------------------------
class PDF_COMUNICACAO extends FPDF {
    // Largura (mm) do texto com a fonte atual
    function larg($txt) { return $this->GetStringWidth(pt($txt)); }
}

$TH = 0.44;   // linha exterior (1,25 pt)
$TI = 0.265;  // linha interior (0,75 pt)
$X_ESQ = 14.64; $X_DIR = 195.0;   // extremos horizontais das tabelas

$pdf = new PDF_COMUNICACAO('P', 'mm', 'A4');
$pdf->SetMargins(0, 0, 0);
$pdf->SetAutoPageBreak(false);
$pdf->AddPage();
$pdf->SetTextColor(0, 0, 0);
$pdf->SetDrawColor(0, 0, 0);

// ---------------------------------------------------------
// Helpers de desenho
// ---------------------------------------------------------
function linhaH($pdf, $y, $esp) {
    global $X_ESQ, $X_DIR;
    $pdf->SetLineWidth($esp);
    $pdf->Line($X_ESQ, $y, $X_DIR, $y);
}
function linhaV($pdf, $x, $y1, $y2, $esp) {
    $pdf->SetLineWidth($esp);
    $pdf->Line($x, $y1, $x, $y2);
}
// Moldura de uma tabela: linhas horizontais [[y, espessura], ...] + laterais exteriores
function moldura($pdf, $linhas) {
    global $TH;
    foreach ($linhas as $l) linhaH($pdf, $l[0], $l[1]);
    $y1 = $linhas[0][0] - 0.23;
    $y2 = $linhas[count($linhas) - 1][0] + 0.21;
    linhaV($pdf, 14.85, $y1, $y2, $TH);
    linhaV($pdf, 194.77, $y1, $y2, $TH);
}

// Etiqueta (texto fixo do impresso). Devolve a largura usada.
function rotulo($pdf, $txt, $x, $yTopo, $h = 6.75, $size = 11, $style = '') {
    $pdf->SetTextColor(0, 0, 0);
    $pdf->SetFont('Arial', $style, $size);
    $w = $pdf->larg($txt);
    $pdf->SetXY($x, $yTopo + 0.3);
    $pdf->Cell($w + 1, $h, pt($txt), 0, 0, 'L');
    return $w;
}

// Valor preenchido (negrito), encolhe a fonte até caber em $maxW
function valor($pdf, $txt, $x, $yTopo, $maxW, $h = 6.75, $size = 9.5, $align = 'L') {
    $txt = trim((string)$txt);
    if ($txt === '') return;
    $pdf->SetTextColor(0, 0, 0);
    $pdf->SetFont('Arial', 'B', $size);
    while ($size > 6.0 && $pdf->larg($txt) > $maxW) {
        $size -= 0.2;
        $pdf->SetFont('Arial', 'B', $size);
    }
    // se ainda não cabe, corta
    while (mb_strlen($txt, 'UTF-8') > 1 && $pdf->larg($txt) > $maxW) {
        $txt = mb_substr($txt, 0, -1, 'UTF-8');
    }
    $pdf->SetXY($x, $yTopo + 0.3);
    $pdf->Cell($maxW, $h, pt($txt), 0, 0, $align);
}

// "Etiqueta: valor" - o valor começa logo a seguir à etiqueta e vai até $xFim
function campo($pdf, $rot, $xRot, $yTopo, $val, $xFim, $h = 6.75) {
    $w = rotulo($pdf, $rot, $xRot, $yTopo, $h);
    $xv = $xRot + $w + 1.8;
    valor($pdf, $val, $xv, $yTopo, $xFim - $xv, $h);
}

// Código Postal: etiqueta + [4 dígitos] - [3 dígitos]
function codigoPostal($pdf, $yTopo, $cp4, $cp3) {
    rotulo($pdf, 'Código Postal:', 147.88, $yTopo);
    $pdf->SetFont('Arial', '', 11);
    $pdf->SetXY(183.66, $yTopo + 0.3);
    $pdf->Cell(1.58, 6.75, '-', 0, 0, 'C');
    valor($pdf, $cp4, 174.17, $yTopo, 9.49, 6.75, 9.5, 'C');
    valor($pdf, $cp3, 185.24, $yTopo, 8.54, 6.75, 9.5, 'C');
}

// Ícone de telemóvel (desenhado) e de telefone
function iconeTelemovel($pdf, $x, $y) {
    $pdf->SetDrawColor(0, 0, 0);
    $pdf->SetLineWidth(0.3);
    $pdf->Rect($x + 1.2, $y + 0.2, 2.3, 4.3);
    $pdf->SetLineWidth(0.2);
    $pdf->Line($x + 1.2, $y + 1.0, $x + 3.5, $y + 1.0);
    $pdf->Line($x + 1.2, $y + 3.7, $x + 3.5, $y + 3.7);
}
function iconeTelefone($pdf, $x, $y) {
    $pdf->SetTextColor(0, 0, 0);
    $tem_zapf = file_exists('fpdf/font/zapfdingbats.php') || file_exists('fpdf/font/zapfdingbats.json');
    if ($tem_zapf) {
        $pdf->SetFont('ZapfDingbats', '', 13);
        $pdf->SetXY($x, $y - 0.2);
        $pdf->Cell(4.2, 5, chr(37), 0, 0, 'C');   // ☎
    } else {
        $pdf->SetDrawColor(0, 0, 0);
        $pdf->SetLineWidth(0.3);
        $pdf->Rect($x + 0.4, $y + 2.0, 3.4, 2.4);
        $pdf->Line($x + 0.4, $y + 1.2, $x + 3.8, $y + 1.2);
    }
}

// ---------------------------------------------------------
// CABEÇALHO
// ---------------------------------------------------------
$pdf->SetFillColor(0, 44, 119);
$pdf->SetLineWidth(0.1);
$pdf->Rect(14.85, 10.83, 180.04, 11.62, 'DF');
$pdf->SetTextColor(255, 255, 255);
$pdf->SetFont('Arial', 'B', 16);
$pdf->SetXY(14.85, 10.83);
$pdf->Cell(180.04, 11.62, pt('COMUNICAÇÃO DE INSTALAÇÃO DE ALARME'), 0, 0, 'C');

$pdf->SetTextColor(0, 0, 0);
$pdf->SetFont('Arial', '', 7);
$pdf->SetXY(14.85, 24.4);
$pdf->Cell(180.04, 3.4, pt('Comunicação prevista no n.º 1 do Artigo 11º da Lei n.º 34/2013 de 16 de maio, alterada e republicada pela Lei n.º 46/2019 de 8 de julho'), 0, 0, 'C');

// Logótipo da Polícia de Segurança Pública
$psp = otimizarImagem('psp2.png');
if ($psp && file_exists($psp)) { $pdf->Image($psp, 19.12, 36.55, 71.3); }

// Quadro "REGISTO NA SUBUNIDADE POLICIAL" (uso exclusivo da PSP/GNR)
$pdf->SetFillColor(238, 238, 238);
$pdf->SetLineWidth(0.1);
$pdf->Rect(97.72, 34.61, 97.17, 5.98, 'DF');
$pdf->Rect(97.72, 40.60, 25.01, 7.64, 'DF');
$pdf->Rect(122.73, 40.60, 72.16, 7.64, 'DF');
$pdf->Rect(97.72, 48.26, 25.01, 5.94, 'DF');
$pdf->Rect(122.73, 48.26, 39.95, 5.94, 'DF');
$pdf->Rect(162.70, 48.26, 8.98, 5.94, 'DF');
$pdf->Rect(171.70, 48.26, 23.19, 5.94, 'DF');
$pdf->SetFont('Arial', 'B', 10);
$pdf->SetXY(97.72, 34.61);
$pdf->Cell(97.17, 5.98, pt('REGISTO NA SUBUNIDADE POLICIAL'), 0, 0, 'C');
$pdf->SetFont('Arial', '', 8);
$pdf->SetXY(98.74, 41.3);
$pdf->Cell(20, 3.4, pt('LOCAL:'), 0, 0, 'L');
$pdf->SetFont('Arial', '', 3.5);
$pdf->SetXY(98.74, 45.2);
$pdf->Cell(20, 2, pt('(Subunidade)'), 0, 0, 'L');
$pdf->SetFont('Arial', '', 8);
$pdf->SetXY(98.74, 48.9);
$pdf->Cell(22, 4.6, pt('N.º DE REGISTO:'), 0, 0, 'L');
$pdf->SetXY(163.4, 48.9);
$pdf->Cell(8, 4.6, pt('DATA:'), 0, 0, 'L');

// ---------------------------------------------------------
// 1. COMUNICAÇÃO
// ---------------------------------------------------------
$pdf->SetTextColor(0, 0, 0);
$pdf->SetFont('Arial', 'B', 11);
$pdf->SetXY(14.89, 61.7);
$pdf->Cell(31, 5, pt('1. Comunicação'), 0, 0, 'L');
$pdf->SetFont('Arial', 'I', 6.4);
$pdf->SetXY(45.72, 63.6);
$pdf->Cell(60, 3, pt('(a preencher pelo proprietário ou utilizador do alarme)'), 0, 0, 'L');

$A = 67.33;
moldura($pdf, [[$A, $TH], [$A + 6.75, $TI], [$A + 13.42, $TI], [$A + 20.09, $TI], [$A + 26.76, $TI], [$A + 33.51, $TH]]);
linhaV($pdf, 146.9, $A + 13.28, $A + 20.2, $TI);   // Localidade | Código Postal
linhaV($pdf, 90.8,  $A + 19.95, $A + 33.71, $TI);  // Tipo Doc | N.º Doc  e  Tlm/Tlf | email

campo($pdf, 'Nome:',        15.87, $A,          $c_nome,   194);
campo($pdf, 'Morada:',      15.87, $A + 6.75,   $c_morada, 194);
campo($pdf, 'Localidade:',  15.87, $A + 13.42,  $c_local,  146);
codigoPostal($pdf, $A + 13.42, $c_cp4, $c_cp3);
campo($pdf, 'Tipo Doc. Ident.:', 15.87, $A + 20.09, '', 90);
campo($pdf, 'N.º do Doc.:', 91.79, $A + 20.09, '', 194);

// Linha dos contactos: Tlm | Tlf | correio eletrónico
$yc = $A + 26.76;
iconeTelemovel($pdf, 15.93, 94.84);
$w = rotulo($pdf, 'Tlm.:', 21.4, $yc);
valor($pdf, $c_tlm, 21.4 + $w + 1.8, $yc, 52.4 - (21.4 + $w + 1.8));
rotulo($pdf, '|', 53.2, $yc);
iconeTelefone($pdf, 55.17, 94.9);
$w = rotulo($pdf, 'Tlf.:', 60.3, $yc);
valor($pdf, $c_tlf, 60.3 + $w + 1.8, $yc, 90.2 - (60.3 + $w + 1.8));
campo($pdf, '@ correio eletrónico:', 91.79, $yc, $c_email, 194);

// ---------------------------------------------------------
// DECLARA QUE NA/O/EM ... (local objeto de proteção)
// ---------------------------------------------------------
$pdf->SetFont('Arial', 'B', 8);
$pdf->SetXY(14.89, 107.3);
$pdf->Cell(28, 4, pt('Declara que na/o/em '), 0, 0, 'L');
valor($pdf, $local_obra, 43.5, 106.0, 150, 5.5, 10);

$B = 111.81;
moldura($pdf, [[$B, $TH], [$B + 6.76, $TI], [$B + 13.51, $TH], [$B + 20.36, $TH], [$B + 27.2, $TH]]);
linhaV($pdf, 146.9, $B + 6.62, $B + 13.73, $TI);

campo($pdf, 'Morada:',     15.87, $B,          $o_morada, 194);
campo($pdf, 'Localidade:', 15.87, $B + 6.76,   $o_local,  146);
codigoPostal($pdf, $B + 6.76, $o_cp[0], $o_cp[1]);

rotulo($pdf, 'Encontra-se instalado um alarme com sirene audível do exterior:', 15.87, $B + 13.51);
valor($pdf, count($sirenes_ext) > 0 ? 'Sim' : 'Não', 178.22, $B + 13.51, 15.65, 6.85, 10, 'C');
rotulo($pdf, 'Encontra-se instalado um botão de pânico:', 15.87, $B + 20.36);
valor($pdf, $tem_panico ? 'Sim' : 'Não', 178.31, $B + 20.36, 15.65, 6.85, 10, 'C');

// ---------------------------------------------------------
// COM AS SEGUINTES CARACTERÍSTICAS
// ---------------------------------------------------------
$pdf->SetTextColor(0, 0, 0);
$pdf->SetFont('Arial', 'B', 8);
$pdf->SetXY(14.89, 142.3);
$pdf->Cell(50, 4, pt('Com as seguintes características:'), 0, 0, 'L');

$C = 145.96;
moldura($pdf, [[$C, $TH], [$C + 6.76, $TI], [$C + 13.43, $TI], [$C + 24.63, $TH]]);
linhaV($pdf, 104.81, $C - 0.23, $C + 6.88, $TI);
linhaV($pdf, 174.94, $C + 13.28, $C + 24.84, $TH);

campo($pdf, 'Marca:',  15.87,  $C,         $a_marca,  104);
campo($pdf, 'Modelo:', 105.83, $C,         $a_modelo, 194);
campo($pdf, 'Alarme instalado por:', 15.87, $C + 6.76, $instalado_por, 194);

$pdf->SetTextColor(0, 0, 0);
$pdf->SetFont('Arial', '', 11);
$pdf->SetXY(15.87, 160.4);
$pdf->Cell(150, 5, pt('Junta cópia da declaração de instalação garantindo a sua conformidade com as normas'), 0, 0, 'L');
$pdf->SetXY(15.87, 164.85);
$pdf->Cell(60, 5, pt('técnicas aplicáveis:'), 0, 0, 'L');
valor($pdf, 'Sim', 180.04, 162.45, 13.83, 5, 10, 'C');

// ---------------------------------------------------------
// 2. REPOSIÇÃO DO ALARME
// ---------------------------------------------------------
$pdf->SetTextColor(0, 0, 0);
$pdf->SetFont('Arial', 'B', 11);
$pdf->SetXY(14.89, 173.2);
$pdf->Cell(50, 5, pt('2. Reposição do alarme'), 0, 0, 'L');
$pdf->SetFont('Arial', '', 8);
$pdf->SetXY(14.89, 177.8);
$pdf->Cell(180.04, 3.4, pt('Qualquer ocorrência com o alarme instalado, deverá ser contactado o proprietário, outro responsável ou o serviço abaixo indicado'), 0, 0, 'C');

// Dois quadros iguais (o 1.º preenchido com o proprietário; o 2.º fica para outro responsável)
function quadroContacto($pdf, $topo, $d) {
    global $TH, $TI;
    moldura($pdf, [[$topo, $TH], [$topo + 6.75, $TI], [$topo + 13.42, $TI], [$topo + 20.09, $TI], [$topo + 26.76, $TI], [$topo + 33.51, $TH]]);
    linhaV($pdf, 146.9,  $topo + 13.28, $topo + 20.21, $TI);
    linhaV($pdf, 104.81, $topo + 19.95, $topo + 33.72, $TI);

    campo($pdf, 'Nome:',       15.87, $topo,          $d['nome'],   194);
    campo($pdf, 'Morada:',     15.87, $topo + 6.75,   $d['morada'], 194);
    campo($pdf, 'Localidade:', 15.87, $topo + 13.42,  $d['local'],  146);
    codigoPostal($pdf, $topo + 13.42, $d['cp4'], $d['cp3']);
    campo($pdf, 'Tipo Doc. Ident.:', 15.87,  $topo + 20.09, '', 104);
    campo($pdf, 'N.º do Doc.:',      105.83, $topo + 20.09, '', 194);
    campo($pdf, 'Telefone:',         15.87,  $topo + 26.76, $d['tlf'], 104);
    campo($pdf, 'Telemóvel:',        105.83, $topo + 26.76, $d['tlm'], 194);
}
quadroContacto($pdf, 181.17, ['nome' => $c_nome, 'morada' => $c_morada, 'local' => $c_local, 'cp4' => $c_cp4, 'cp3' => $c_cp3, 'tlf' => $c_tlf, 'tlm' => $c_tlm]);
quadroContacto($pdf, 221.17, ['nome' => '', 'morada' => '', 'local' => '', 'cp4' => '', 'cp3' => '', 'tlf' => '', 'tlm' => '']);

// ---------------------------------------------------------
// OBSERVAÇÕES / LOCAL E DATA / ASSINATURA
// ---------------------------------------------------------
$E = 260.72;
moldura($pdf, [[$E, $TH], [$E + 6.76, $TI], [$E + 13.51, $TH]]);
linhaV($pdf, 104.81, $E + 6.62, $E + 13.72, $TI);

$w = rotulo($pdf, 'Observações:', 15.87, $E);
$xo = 15.87 + $w + 1.8;
$larg_obs = 194 - $xo;
// Observações: uma linha; se for muito comprida, duas linhas em letra mais pequena
$pdf->SetFont('Arial', 'B', 8.5);
if ($observacoes !== '' && $pdf->larg($observacoes) > $larg_obs) {
    $pdf->SetFont('Arial', 'B', 6.6);
    $linhas = [];
    $cur = '';
    foreach (preg_split('/\s+/u', $observacoes) as $pal) {
        $try = ($cur === '') ? $pal : $cur . ' ' . $pal;
        if ($pdf->larg($try) <= $larg_obs) { $cur = $try; } else { $linhas[] = $cur; $cur = $pal; }
    }
    $linhas[] = $cur;
    $linhas = array_slice($linhas, 0, 2);
    $ly = $E + 0.8;
    foreach ($linhas as $ln) {
        $pdf->SetXY($xo, $ly);
        $pdf->Cell($larg_obs, 2.7, pt($ln), 0, 0, 'L');
        $ly += 2.7;
    }
} else {
    valor($pdf, $observacoes, $xo, $E, $larg_obs, 6.75, 8.5);
}

campo($pdf, 'Local e Data:', 15.87, $E + 6.76, $LOCALIDADE_ASSINATURA . ', ' . $data_doc, 104);
campo($pdf, 'Assinatura:',   105.83, $E + 6.76, '', 194);

ob_end_clean();
$ref = preg_replace('/[^A-Za-z0-9_-]/', '_', (string)($termo['referencia_termo'] ?? 'Instalacao'));
$pdf->Output('I', 'Comunicacao_Alarme_PSP_' . $ref . '.pdf');
?>
