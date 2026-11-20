<?php
// REGRA DE OURO: Limpar o buffer para evitar erros na geração do PDF
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

// --- CONFIGURAÇÃO ---
$NUM_REGISTO_ALVARA = '4944'; // Nº de registo prévio (certificado PSP)
$NOME_TECNICO_INSTALADOR = 'Mário Abreu (MAB)';

// Função auxiliar: devolve o primeiro campo preenchido de uma lista de nomes possíveis
function pegar($arr, $chaves, $default = '') {
    foreach ($chaves as $k) {
        if (isset($arr[$k]) && trim((string)$arr[$k]) !== '') {
            return trim((string)$arr[$k]);
        }
    }
    return $default;
}

// --- LER DADOS DO TERMO E DO CLIENTE ---
$stmt_termo = $pdo->prepare("SELECT c.*, t.*, c.nome as nome_cliente, c.morada as morada_cliente
                             FROM termos_intrusao t
                             LEFT JOIN clientes c ON t.id_cliente = c.id
                             WHERE t.id = ?");
$stmt_termo->execute([$id_termo]);
$termo = $stmt_termo->fetch(PDO::FETCH_ASSOC);

if (!$termo) {
    ob_end_clean();
    die("Instalação não encontrada.");
}

// --- LER TODO O HISTÓRICO DE INTERVENÇÕES ---
// Se a tabela de intervenções de intrusão ainda não existir, o livro sai só com a linha em branco do modelo
$intervencoes = [];
try {
    $stmt_int = $pdo->prepare("SELECT * FROM intrusao_intervencoes WHERE id_termo_intrusao = ? ORDER BY data_intervencao ASC, hora_intervencao ASC");
    $stmt_int->execute([$id_termo]);
    $intervencoes = $stmt_int->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $intervencoes = [];
}

// --- DADOS DA INSTALAÇÃO (campos em branco ficam em branco, como no modelo) ---
$marca_modelo    = trim(pegar($termo, ['marca_central']) . ' ' . pegar($termo, ['modelo_central', 'ref_central']));
$data_instalacao = !empty($termo['data_emissao']) ? date('d/m/Y', strtotime($termo['data_emissao'])) : '';
$entidade        = (string)($termo['nome_cliente'] ?? '');
$monitorizado    = $entidade; // sistema monitorizado pelo próprio cliente
$morada          = pegar($termo, ['morada_obra', 'morada_instalacao', 'local_obra', 'localizacao'], (string)($termo['morada_cliente'] ?? ''));
$responsavel     = $entidade;

// Contacto = telemóvel e email do cliente (lido diretamente da ficha do cliente, para não ser afetado por colunas com o mesmo nome no termo)
$cliente = [];
if (!empty($termo['id_cliente'])) {
    $stmt_cli = $pdo->prepare("SELECT * FROM clientes WHERE id = ?");
    $stmt_cli->execute([$termo['id_cliente']]);
    $cliente = $stmt_cli->fetch(PDO::FETCH_ASSOC) ?: [];
}
$cont_arr = [];
foreach (['telemovel', 'telefone', 'telefone1', 'telefone2', 'tel'] as $col) {
    if (!empty($cliente[$col]) && trim((string)$cliente[$col]) !== '') {
        $cont_arr[] = trim((string)$cliente[$col]);
    }
}
foreach (['email', 'e_mail', 'mail'] as $col) {
    if (!empty($cliente[$col]) && trim((string)$cliente[$col]) !== '') {
        $cont_arr[] = trim((string)$cliente[$col]);
        break;
    }
}
$contacto_final = implode(' / ', array_unique($cont_arr));

// ---------------------------------------------------------
// FUNÇÕES AUXILIARES
// ---------------------------------------------------------
function pt($texto) {
    return mb_convert_encoding((string)($texto ?? ''), 'windows-1252', 'UTF-8');
}

// Tamanhos (o modelo usa Calibri 9/10/8 pt; em Arial reduzem-se ~8% para ocupar o mesmo espaço)
define('F8', 7.6);
define('F9', 8.6);
define('F10', 9.4);

// Otimizador de imagens (igual ao do livro CCTV)
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
        if ($tipo == IMAGETYPE_PNG) { imagepng($nova_img, $nome_otimizado, 9); }
        else { imagejpeg($nova_img, $nome_otimizado, 80); }
        imagedestroy($nova_img); imagedestroy($origem);
        return $nome_otimizado;
    }
    return $ficheiro;
}

// Parte o texto em linhas que cabem na largura $w (mm), com a fonte atualmente definida
function wrapText($pdf, $text, $w) {
    $lines = [];
    foreach (preg_split('/\R/u', (string)$text) as $para) {
        $words = preg_split('/\s+/u', trim($para), -1, PREG_SPLIT_NO_EMPTY);
        if (!$words) { $lines[] = ''; continue; }
        $cur = '';
        foreach ($words as $word) {
            $try = ($cur === '') ? $word : $cur . ' ' . $word;
            if ($pdf->GetStringWidth(pt($try)) <= $w) {
                $cur = $try;
            } else {
                if ($cur !== '') $lines[] = $cur;
                // palavra maior do que a coluna: parte-a
                while ($pdf->GetStringWidth(pt($word)) > $w && mb_strlen($word, 'UTF-8') > 1) {
                    $i = mb_strlen($word, 'UTF-8');
                    while ($i > 1 && $pdf->GetStringWidth(pt(mb_substr($word, 0, $i, 'UTF-8'))) > $w) $i--;
                    $lines[] = mb_substr($word, 0, $i, 'UTF-8');
                    $word = mb_substr($word, $i, null, 'UTF-8');
                }
                $cur = $word;
            }
        }
        $lines[] = $cur;
    }
    return $lines;
}

// Corta o texto (uma linha) para caber na largura $w
function fitText($pdf, $text, $w) {
    $text = (string)$text;
    if ($pdf->GetStringWidth(pt($text)) <= $w) return $text;
    while (mb_strlen($text, 'UTF-8') > 1 && $pdf->GetStringWidth(pt($text . '.')) > $w) {
        $text = mb_substr($text, 0, -1, 'UTF-8');
    }
    return $text . '.';
}

// Reduz o tamanho da fonte até o texto caber na largura $w
function tamanhoQueCabe($pdf, $text, $w, $style, $size) {
    $pdf->SetFont('Arial', $style, $size);
    while ($size > 6.0 && $pdf->GetStringWidth(pt($text)) > $w) {
        $size -= 0.2;
        $pdf->SetFont('Arial', $style, $size);
    }
    return $size;
}

// Etiqueta sublinhada no canto superior esquerdo de uma célula
function etiqueta($pdf, $x, $y, $w, $txt) {
    $size = tamanhoQueCabe($pdf, $txt, $w - 3.8, 'U', F9);
    $pdf->SetFont('Arial', 'U', $size);
    $pdf->SetXY($x + 1.9, $y + 0.6);
    $pdf->Cell($w - 3.8, 3.6, pt($txt), 0, 0, 'L');
    return $pdf->GetStringWidth(pt($txt));
}

// Valor (texto normal) no canto superior esquerdo de uma célula
function valor($pdf, $x, $y, $w, $txt) {
    if ((string)$txt === '') return;
    $pdf->SetFont('Arial', '', F9 + 0.4);
    $pdf->SetXY($x + 1.9, $y + 0.6);
    $pdf->Cell($w - 3.8, 3.6, pt(fitText($pdf, $txt, $w - 3.8)), 0, 0, 'L');
}

// Linha de texto sublinhada e centrada, com índice (¹ ² ³) opcional em expoente
function linhaCentrada($pdf, $x, $y, $w, $lh, $text, $sup, $size) {
    $pdf->SetFont('Arial', 'U', $size);
    $tw = $pdf->GetStringWidth(pt($text));
    $sw = 0;
    if ($sup !== '') {
        $pdf->SetFont('Arial', 'U', $size * 0.6);
        $sw = $pdf->GetStringWidth($sup);
    }
    $start = $x + ($w - $tw - $sw) / 2;
    $pdf->SetFont('Arial', 'U', $size);
    $pdf->SetXY($start, $y);
    $pdf->Cell($tw, $lh, pt($text), 0, 0, 'L');
    if ($sup !== '') {
        $pdf->SetFont('Arial', 'U', $size * 0.6);
        $pdf->SetXY($start + $tw, $y - 0.9);
        $pdf->Cell($sw, $lh, $sup, 0, 0, 'L');
    }
}

// ---------------------------------------------------------
// TABELA "REGISTO DE INTERVENÇÃO NO SISTEMA" (larguras do modelo, total 270 mm)
// ---------------------------------------------------------
$X2 = 15.0;
$wt = [17.76, 12.49, 37.51, 20.00, 27.50, 27.51, 30.00, 25.01, 37.23, 34.99];

// Cabeçalho da tabela; devolve a altura usada
function cabecalhoIntervencoes($pdf, $x, $y, $wt) {
    $cells = [
        [['Data', F9]],
        [['Hora', F9]],
        [['Tipo de intervenção:', F10], ['(M) Manutenção', F8], ['(AT) Assistência Técnica', F8], ['(A) Alteração', F8],
         ['(R) Reparação', F8], ['(FA) Falso Alarme', F8], ['(O) Outra', F8]],
        [['Presencial/Remota:', F9]],
        [['Identificação do responsável pela intervenção (Alvará C/Registo Prévio)', F9]],
        [['Causa da intervenção', F9, '1']],
        [['Trabalhos executados', F9, '1']],
        [['N.º relatório técnico de intervenção', F9, '2']],
        [['Nome do técnico executante', F9]],
        [['Assinatura do técnico executante', F9, '3']],
    ];

    $blocks = [];
    $maxH = 0;
    foreach ($cells as $i => $paras) {
        $lines = [];
        foreach ($paras as $p) {
            $sz  = $p[1];
            $sup = $p[2] ?? '';
            $pdf->SetFont('Arial', 'U', $sz);
            $ws = wrapText($pdf, $p[0], $wt[$i] - 3.8);
            foreach ($ws as $k => $ln) {
                $lines[] = [$ln, $sz, ($k === count($ws) - 1) ? $sup : ''];
            }
        }
        $h = 0;
        foreach ($lines as $l) { $h += $l[1] * 0.43; }
        $maxH = max($maxH, $h);
        $blocks[$i] = [$lines, $h];
    }

    $H = max(26.1, $maxH + 3.0);
    $cx = $x;
    foreach ($blocks as $i => $b) {
        $pdf->Rect($cx, $y, $wt[$i], $H);
        $ty = $y + ($H - $b[1]) / 2;
        foreach ($b[0] as $l) {
            $lh = $l[1] * 0.43;
            linhaCentrada($pdf, $cx, $ty, $wt[$i], $lh, $l[0], $l[2], $l[1]);
            $ty += $lh;
        }
        $cx += $wt[$i];
    }
    return $H;
}

// Prepara uma linha preenchida: calcula as linhas de texto e a altura
function prepararLinha($pdf, $wt, $vals) {
    $lh = 3.3;
    $pdf->SetFont('Arial', '', 7.6);
    $wrapped = [];
    $maxL = 1;
    foreach ($vals as $i => $v) {
        $wrapped[$i] = wrapText($pdf, $v, $wt[$i] - 3.8);
        $maxL = max($maxL, count($wrapped[$i]));
    }
    $h = max(14.7, $maxL * $lh + 3.0); // 14,7 mm = altura mínima de linha do modelo
    return [$h, $wrapped];
}

function desenharLinha($pdf, $x, $y, $wt, $h, $wrapped, $assina) {
    $lh = 3.3;
    $aligns = ['C', 'C', 'C', 'C', 'C', 'L', 'L', 'C', 'C'];
    $cx = $x;
    $pdf->SetFont('Arial', '', 7.6);
    foreach ($wt as $i => $w) {
        $pdf->Rect($cx, $y, $w, $h);
        if (isset($wrapped[$i])) {
            $n  = count($wrapped[$i]);
            $ty = $y + ($h - $n * $lh) / 2;
            foreach ($wrapped[$i] as $ln) {
                $pdf->SetXY($cx + 1.9, $ty);
                $pdf->Cell($w - 3.8, $lh, pt($ln), 0, 0, $aligns[$i]);
                $ty += $lh;
            }
        } elseif ($i === 9 && $assina && file_exists($assina)) {
            $pdf->Image($assina, $cx + ($w - 24) / 2, $y + ($h - 8) / 2, 24, 8);
        }
        $cx += $w;
    }
}

// Linha em branco (como no modelo: "_/_/_" na coluna da data)
function linhaBranca($pdf, $x, $y, $wt, $h) {
    $cx = $x;
    foreach ($wt as $i => $w) {
        $pdf->Rect($cx, $y, $w, $h);
        if ($i === 0) {
            linhaCentrada($pdf, $cx, $y + $h / 2 - 2.0, $w, 4.0, '_/_/_', '', F10);
        }
        $cx += $w;
    }
}

// ---------------------------------------------------------
// CLASSE PDF (A4 PAISAGEM) - cabeçalho e rodapé do modelo
// ---------------------------------------------------------
class PDF_LRS extends FPDF {
    function Header() {
        // Logótipo da empresa (canto superior esquerdo)
        $logo = otimizarImagem('mab.png');
        if ($logo && file_exists($logo)) { $this->Image($logo, 12.95, 6.65, 17.5); }

        // Departamento de Segurança Privada (canto superior direito)
        $psp1 = otimizarImagem('psp1.png');
        if ($psp1 && file_exists($psp1)) { $this->Image($psp1, 262.7, 6.8, 19.7); }
    }

    function Footer() {
        // Logótipo "Polícia de Segurança Pública" centrado, no fundo da página
        $psp2 = otimizarImagem('psp2.png');
        if ($psp2 && file_exists($psp2)) { $this->Image($psp2, (297 - 43) / 2, 193, 43); }
    }
}

$pdf = new PDF_LRS('L', 'mm', 'A4');
$pdf->SetMargins(0, 0, 0);
$pdf->SetAutoPageBreak(false);
$pdf->SetLineWidth(0.18);
$pdf->SetDrawColor(0, 0, 0);
$pdf->SetTextColor(0, 0, 0);
$pdf->AddPage();

// =========================================================
// TÍTULO
// =========================================================
$pdf->SetFont('Arial', 'B', 11);
$pdf->SetXY(0, 25.0);
$pdf->Cell(297, 5.0, pt('LIVRO DE REGISTOS DO SISTEMA'), 0, 0, 'C');

// =========================================================
// DADOS DA INSTALAÇÃO
// =========================================================
$pdf->SetFont('Arial', 'BU', 11.3);
$pdf->SetXY(24.0, 29.4);
$pdf->Cell(60, 5.0, pt('Dados da instalação'), 0, 0, 'L');

$X1 = 16.0;     // início da tabela
$C1 = 65.0;     // coluna das etiquetas
$C2 = 77.5;     // coluna do valor (linha "instalado por")
$C3 = 127.0;    // coluna "Nº de registo prévio/Alvará C"
$RH = 7.0;      // altura de cada linha
$y  = 37.0;

$linhas1 = [
    ['Identificação do sistema (marca e modelo)', $marca_modelo],
    ['Tipo de sistema', 'Sistema de alarme de intrusão'],
    ['Entidade', $entidade],
    ['Morada', $morada],
    ['Responsável pelo sistema', $responsavel],
    ['Contacto do responsável pelo sistema', $contacto_final],
];
foreach ($linhas1 as $l) {
    $pdf->Rect($X1, $y, $C1, $RH);
    $pdf->Rect($X1 + $C1, $y, $C2 + $C3, $RH);
    etiqueta($pdf, $X1, $y, $C1, $l[0]);
    valor($pdf, $X1 + $C1, $y, $C2 + $C3, $l[1]);
    $y += $RH;
}

// Linha: Sistema instalado por / Data | Nº de registo prévio/Alvará C
$RH2 = 14.1;
$pdf->Rect($X1, $y, $C1, $RH2);
$pdf->Rect($X1 + $C1, $y, $C2, $RH2);
$pdf->Rect($X1 + $C1 + $C2, $y, $C3, $RH2);
etiqueta($pdf, $X1, $y, $C1, 'Sistema instalado por:');
etiqueta($pdf, $X1, $y + 6.8, $C1, 'Data:');
valor($pdf, $X1 + $C1, $y, $C2, $NOME_TECNICO_INSTALADOR);
valor($pdf, $X1 + $C1, $y + 6.8, $C2, $data_instalacao);
$lw = etiqueta($pdf, $X1 + $C1 + $C2, $y, $C3, 'Nº de registo prévio/Alvará C:');
valor($pdf, $X1 + $C1 + $C2 + $lw + 2.0, $y, $C3 - $lw - 2.0, $NUM_REGISTO_ALVARA);
$y += $RH2;

// Linha: Sistema monitorizado por
$pdf->Rect($X1, $y, $C1, $RH);
$pdf->Rect($X1 + $C1, $y, $C2 + $C3, $RH);
etiqueta($pdf, $X1, $y, $C1, 'Sistema monitorizado por:');
valor($pdf, $X1 + $C1, $y, $C2 + $C3, $monitorizado);
$y += $RH;

// =========================================================
// REGISTO DE INTERVENÇÃO NO SISTEMA
// =========================================================
$pdf->SetFont('Arial', 'BU', 11.3);
$pdf->SetXY(17.2, 107.0);
$pdf->Cell(80, 5.0, pt('Registo de Intervenção no sistema'), 0, 0, 'L');

$Y_MAX = 176.0;   // limite inferior das linhas (deixa espaço às notas de rodapé e ao logótipo)
$y = 115.4;
$y += cabecalhoIntervencoes($pdf, $X2, $y, $wt);

$assina = otimizarImagem('assina.png');

foreach ($intervencoes as $inv) {
    $tipo_inv = (string)($inv['tipo_intervencao'] ?? '');
    preg_match('/\((.*?)\)/', $tipo_inv, $m);
    $tipo_curto = !empty($m[1]) ? trim($m[1]) : $tipo_inv;

    $d = !empty($inv['data_intervencao']) ? date('d/m/y', strtotime($inv['data_intervencao'])) : '';
    $h = !empty($inv['hora_intervencao']) ? date('H:i', strtotime($inv['hora_intervencao'])) : '';

    $vals = [
        $d,
        $h,
        $tipo_curto,
        (string)($inv['modo_intervencao'] ?? ''),
        (string)($inv['alvara_responsavel'] ?? ''),
        (string)($inv['causa'] ?? ''),
        (string)($inv['trabalhos_executados'] ?? ''),
        (string)($inv['num_relatorio'] ?? ''),
        (string)($inv['nome_tecnico'] ?? ''),
    ];
    list($hl, $wrapped) = prepararLinha($pdf, $wt, $vals);

    if ($y + $hl > $Y_MAX) {
        $pdf->AddPage();
        $y = 36.0;
        $y += cabecalhoIntervencoes($pdf, $X2, $y, $wt);
    }
    desenharLinha($pdf, $X2, $y, $wt, $hl, $wrapped, $assina);
    $y += $hl;
}

// Linhas em branco para preencher à mão (a página fica preenchida; no mínimo uma, como no modelo)
$H_BRANCA = 28.0;
$brancas = 0;
while (true) {
    if ($y + $H_BRANCA > $Y_MAX) {
        if ($brancas > 0) break;
        $pdf->AddPage();
        $y = 36.0;
        $y += cabecalhoIntervencoes($pdf, $X2, $y, $wt);
    }
    linhaBranca($pdf, $X2, $y, $wt, $H_BRANCA);
    $y += $H_BRANCA;
    $brancas++;
}

// =========================================================
// NOTAS DE RODAPÉ (como no modelo)
// =========================================================
$notas = [
    ['1', 'Preencher caso não exista relatório técnico de intervenção'],
    ['2', 'Anexar relatório técnico de intervenção (se aplicável)'],
    ['3', 'Preencher em caso de intervenção presencial'],
];
$fy = $y + 2.5;
foreach ($notas as $n) {
    $pdf->SetFont('Arial', 'U', 4.6);
    $sw = $pdf->GetStringWidth($n[0] . ' ');
    $pdf->SetXY(17.4, $fy - 0.7);
    $pdf->Cell($sw, 3.3, $n[0] . ' ', 0, 0, 'L');
    $pdf->SetFont('Arial', 'U', F8);
    $pdf->SetXY(17.4 + $sw, $fy);
    $pdf->Cell(200, 3.3, pt($n[1]), 0, 0, 'L');
    $fy += 3.3;
}

ob_end_clean();
$ref = preg_replace('/[^A-Za-z0-9_-]/', '_', (string)($termo['referencia_termo'] ?? 'Instalacao'));
$pdf->Output('I', 'LRS_Intrusao_' . $ref . '.pdf');
?>
