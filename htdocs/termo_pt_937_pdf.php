<?php
/**
 * termo_pt_937_pdf.php
 * Imprime o Relatório-tipo INCM Modelo 937 preenchido com os dados do Relatório de PT.
 *
 *   termo_pt_937_pdf.php?id=XX            -> impresso INCM (imagem das páginas carregadas) + dados
 *   termo_pt_937_pdf.php?id=XX&fundo=0    -> só os dados (para imprimir por cima do impresso em papel)
 *   &download=1                            -> descarregar em vez de abrir no browser
 */
ob_start();
session_start();
require_once 'db.php';
require_once('fpdf/fpdf.php');
require_once 'termo_pt_937_lib.php';

if (!isset($_SESSION['user_id'])) { header("Location: index.php"); exit; }

$perfil_user = $_SESSION['perfil'] ?? '';
$is_admin = in_array($perfil_user, ['admin', 'administrativo']);
$stmt_perm = $pdo->prepare("SELECT modulo FROM permissoes WHERE grupo = :perfil AND pode_aceder = 1");
$stmt_perm->execute(['perfil' => $perfil_user]);
$meus_acessos = $stmt_perm->fetchAll(PDO::FETCH_COLUMN);
if (!$is_admin && !in_array('termos', $meus_acessos) && !in_array('termos_pt', $meus_acessos)) {
    header("Location: index.php?erro=sem_permissao"); exit;
}

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$id) die("Relatório inválido.");

m937_migrar($pdo);
$t = m937_carregar_termo($pdo, $id);
if (!$t) die("Relatório não encontrado.");

$m937   = json_decode((string)($t['modelo937_json'] ?? ''), true) ?: [];
$campos = m937_campos($t, $m937, m937_ultimo($pdo, $id));
$opc    = $m937['opcoes'] ?? [];

// Páginas do impresso (imagens guardadas na pasta imagens/)
$paginas = [];
foreach (($m937['paginas'] ?? []) as $f) {
    $cam = 'imagens/' . basename((string)$f);
    if (is_file($cam)) $paginas[] = $cam;
}
$com_fundo = !isset($_GET['fundo']) || $_GET['fundo'] !== '0';
if (!$paginas) $com_fundo = false;

$assinatura = (!isset($opc['assinar']) || !empty($opc['assinar'])) ? m937_assinatura_get($pdo) : '';
$cabecalho = !empty($opc['cabecalho']);          // preencher condição, período, (1)…(4) e n.º inscrição

$dx = (float)str_replace(',', '.', (string)($opc['dx'] ?? 0)) * 72 / 25.4;   // afinação em mm -> pt
$dy = (float)str_replace(',', '.', (string)($opc['dy'] ?? 0)) * 72 / 25.4;

function pt($texto) {
    $texto = (string)($texto ?? '');
    $texto = str_replace(['Ω', 'φ', '…', '–', '—', '“', '”', '’'], ['Ohm', 'fi', '...', '-', '-', '"', '"', "'"], $texto);
    return mb_convert_encoding($texto, 'windows-1252', 'UTF-8');
}

class PDF937 extends FPDF {
    public $dx = 0, $dy = 0;
    public $anexos = [];          // texto que não coube no impresso -> vai para páginas de anexo
    public $paginaAtual = 0;
    public $erroAssinatura = '';

    function Footer() {}

    // Volta a uma página já escrita (para o n.º de anexos, que só se sabe no fim)
    function IrPagina($n) {
        $this->page = $n;
        $this->FontFamily = '';   // obriga a reescrever a fonte nessa página
    }

    // Texto numa posição (x, linha) — escreve ligeiramente acima da linha do impresso
    function Escrever($x, $yLinha, $txt) {
        $this->Text($x + $this->dx, $yLinha - 2.2 + $this->dy, pt($txt));
    }

    // Texto curto num único espaço: reduz a letra até caber
    function Curto(array $seg, $txt, $tam = 10, $estilo = 'B', $centrar = false) {
        $txt = trim((string)$txt);
        if ($txt === '') return;
        [$x1, $x2, $y] = $seg;
        $larg = $x2 - $x1 - 4;
        $s = $tam;
        $this->SetFont('Helvetica', $estilo, $s);
        while ($s > 6 && $this->GetStringWidth(pt($txt)) > $larg) { $s -= 0.5; $this->SetFont('Helvetica', $estilo, $s); }
        $w = $this->GetStringWidth(pt($txt));
        $x = $centrar ? $x1 + (($x2 - $x1) - $w) / 2 : $x1 + 3;
        $this->Escrever($x, $y, $txt);
    }

    // Texto longo distribuído pelas linhas do impresso; o que sobrar vai para anexo
    function Linhas(array $segs, $txt, $rotulo, $tam = 9.5, $estilo = '') {
        $txt = trim(str_replace("\r", '', (string)$txt));
        if ($txt === '') return;
        $this->SetFont('Helvetica', $estilo, $tam);
        $marca = ' (continua em anexo)';

        // Divide em "tokens": palavras e quebras de linha forçadas
        $tokens = [];
        foreach (explode("\n", $txt) as $i => $par) {
            if ($i > 0) $tokens[] = "\n";
            foreach (preg_split('/[ \t]+/', trim($par)) as $w) if ($w !== '') $tokens[] = $w;
        }

        $ajusta = function($reservar) use ($segs, $tokens) {
            $linhas = []; $i = 0; $n = count($tokens);
            foreach ($segs as $si => $sg) {
                $larg = $sg[1] - $sg[0] - 5;
                if ($reservar && $si === count($segs) - 1) $larg -= $this->GetStringWidth(pt(' (continua em anexo)'));
                $linha = '';
                while ($i < $n) {
                    $tk = $tokens[$i];
                    if ($tk === "\n") { $i++; if ($linha !== '') break; else continue; }
                    $teste = $linha === '' ? $tk : $linha . ' ' . $tk;
                    if ($this->GetStringWidth(pt($teste)) <= $larg) { $linha = $teste; $i++; }
                    elseif ($linha === '' ) { $linha = $tk; $i++; break; }   // palavra enorme: fica sozinha
                    else break;
                }
                $linhas[] = $linha;
                if ($i >= $n) break;
            }
            $resto = [];
            for ($j = $i; $j < $n; $j++) $resto[] = $tokens[$j];
            return [$linhas, $resto];
        };

        [$linhas, $resto] = $ajusta(false);
        if ($resto) [$linhas, $resto] = $ajusta(true);

        foreach ($linhas as $k => $l) {
            if ($l === '') continue;
            $sg = $segs[$k];
            $this->Escrever($sg[0] + 3, $sg[2], $l . (($resto && $k === count($linhas) - 1) ? $marca : ''));
        }
        if ($resto) {
            $r = trim(preg_replace("/ ?\n ?/", "\n", implode(' ', $resto)));
            $this->anexos[] = [$rotulo, $r];
        }
    }

    // Valor dentro de uma caixa (alinhado à direita)
    function Caixa(array $b, $txt) {
        $txt = trim((string)$txt);
        if ($txt === '') return;
        [$x1, $x2, $y1, $y2] = $b;
        $s = 10;
        $this->SetFont('Helvetica', 'B', $s);
        while ($s > 6 && $this->GetStringWidth(pt($txt)) > ($x2 - $x1 - 4)) { $s -= 0.5; $this->SetFont('Helvetica', 'B', $s); }
        $w = $this->GetStringWidth(pt($txt));
        $this->Text($x2 - 2.5 - $w + $this->dx, $y2 - 2 + $this->dy, pt($txt));
    }

    // Assinatura centrada sobre a linha do técnico responsável
    function Assinatura($ficheiro, $x1, $x2, $yLinha) {
        if (!$ficheiro || !is_file($ficheiro)) return;
        $info = @getimagesize($ficheiro);
        if (!$info || !in_array($info[2], [IMAGETYPE_PNG, IMAGETYPE_JPEG], true)) return;
        $maxW = 165; $maxH = 34;
        $f = min($maxW / $info[0], $maxH / $info[1]);
        $w = $info[0] * $f; $h = $info[1] * $f;
        $x = ($x1 + $x2) / 2 - $w / 2;
        $y = $yLinha - $h * 0.85;                    // quase toda acima da linha, a tocar nela
        $tipo = $info[2] === IMAGETYPE_PNG ? 'PNG' : 'JPG';     // pelo conteúdo, não pela extensão
        try {
            $this->Image($ficheiro, $x + $this->dx, $y + $this->dy, $w, $h, $tipo);
            return;
        } catch (Throwable $e) {
            $this->erroAssinatura = $e->getMessage();
        }
        // Plano B: o FPDF não leu a imagem (PNG entrelaçado, 16 bits, sem zlib…) -> converte para JPG
        if (function_exists('imagecreatetruecolor')) {
            $src = $tipo === 'PNG' ? @imagecreatefrompng($ficheiro) : @imagecreatefromjpeg($ficheiro);
            if ($src) {
                $tmp = 'imagens/assina_m937_auto.jpg';
                $dst = imagecreatetruecolor(imagesx($src), imagesy($src));
                imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
                imagecopy($dst, $src, 0, 0, 0, 0, imagesx($src), imagesy($src));
                imageinterlace($dst, false);
                if (@imagejpeg($dst, $tmp, 90)) {
                    try { $this->Image($tmp, $x + $this->dx, $y + $this->dy, $w, $h, 'JPG'); $this->erroAssinatura .= ' (resolvido com JPG)'; }
                    catch (Throwable $e2) { $this->erroAssinatura .= ' | JPG: ' . $e2->getMessage(); }
                }
                imagedestroy($dst); imagedestroy($src);
            }
        }
    }

    // Cruz dentro de uma quadrícula
    function Cruz(array $b) {
        [$x1, $x2, $y1, $y2] = $b;
        $this->SetLineWidth(0.9);
        $m = 1.6;
        $this->Line($x1 + $m + $this->dx, $y1 + $m + $this->dy, $x2 - $m + $this->dx, $y2 - $m + $this->dy);
        $this->Line($x1 + $m + $this->dx, $y2 - $m + $this->dy, $x2 - $m + $this->dx, $y1 + $m + $this->dy);
        $this->SetLineWidth(0.2);
    }
}

// --- Diagnóstico da assinatura: termo_pt_937_pdf.php?id=XX&diag=1 ---
if (!empty($_GET['diag'])) {
    ob_end_clean();
    header('Content-Type: text/plain; charset=utf-8');
    echo "DIAGNÓSTICO DA ASSINATURA (Modelo 937)\n\n";
    echo "Opção «colocar assinatura»: " . (isset($opc['assinar']) ? ($opc['assinar'] ? 'ligada' : 'DESLIGADA') : 'não definida (= ligada)') . "\n";
    echo "Pasta atual do script: " . getcwd() . "\n";
    echo "GD: " . (function_exists('imagecreatetruecolor') ? 'sim' : 'NÃO') . " | zlib: " . (function_exists('gzcompress') ? 'sim' : 'NÃO') . "\n";
    echo "Pasta imagens/ gravável: " . (is_writable('imagens') ? 'sim' : 'NÃO') . "\n\n";
    foreach (['assina.png', 'imagens/assina.png', 'Assina.png', 'assina.PNG', 'imagens/assina_m937_v2.png'] as $f) {
        echo str_pad($f, 32) . ': ';
        if (!is_file($f)) { echo "não existe\n"; continue; }
        $i = @getimagesize($f);
        echo filesize($f) . " bytes | " . ($i ? $i[0] . 'x' . $i[1] . ' ' . $i['mime'] . (isset($i['bits']) ? ' ' . $i['bits'] . ' bits' : '') : 'NÃO É IMAGEM') . "\n";
    }
    $esc = m937_assinatura_get($pdo);
    echo "\nFicheiro escolhido: " . ($esc ?: '(nenhum)') . "\n";
    if ($esc) {
        $t2 = new PDF937('P', 'pt', 'A4');
        $t2->AddPage();
        $t2->Assinatura($esc, 298, 567, 638.5);
        echo "FPDF: " . ($t2->erroAssinatura === '' ? 'imagem lida sem erros' : $t2->erroAssinatura) . "\n";
    }
    exit;
}

$pdf = new PDF937('P', 'pt', 'A4');
$pdf->SetAutoPageBreak(false);
$pdf->SetMargins(0, 0, 0);
$pdf->SetTitle(pt('Relatório-tipo Modelo 937 - ' . ($t['referencia_pt'] ?? '')));
$pdf->SetAuthor('MAB');
$pdf->dx = $dx; $pdf->dy = $dy;
$pdf->SetTextColor(0, 0, 0);
$pdf->SetDrawColor(0, 0, 0);

$fixos  = m937_fixos();
$itens  = m937_itens();
$textos = m937_textos();
$caixas = m937_caixas();

for ($p = 1; $p <= 4; $p++) {
    $pdf->AddPage();
    if ($com_fundo && isset($paginas[$p - 1])) {
        $pdf->Image($paginas[$p - 1], 0, 0, $pdf->GetPageWidth(), $pdf->GetPageHeight());
    }
    $pdf->SetTextColor(0, 0, 0);

    if ($p === 1) {
        if ($cabecalho) {
            $k = 'cond_' . ($campos['cond'] ?: 'boas');
            if (isset($fixos[$k])) $pdf->Cruz($fixos[$k]);
            $pdf->Curto($fixos['periodo_ini'][0], $campos['periodo_ini'], 10, 'B');
            $pdf->Curto($fixos['periodo_fim'][0], $campos['periodo_fim'], 10, 'B');
            $pdf->Linhas($fixos['ref1'], $campos['ref1'], '(1) Entidade', 10, 'B');
            $pdf->Linhas($fixos['ref2'], $campos['ref2'], '(2) Entidade e localização da instalação', 10, 'B');
            $pdf->Linhas($fixos['ref3'], $campos['ref3'], '(3) Descrição da instalação', 10, 'B');
            $pdf->Linhas($fixos['ref4'], $campos['ref4'], '(4) Técnico responsável', 10, 'B');
            $pdf->Curto($fixos['inscricao'][0], $campos['inscricao'], 10, 'B', true);
        }
        $pdf->Linhas($fixos['dl'], $campos['dl'], '(5) Disposição legal', 10, 'B');
        $pdf->Linhas($fixos['dias'], $campos['dias'], 'Dias da inspecção', 10, 'B');
    }

    if ($p === 2 && in_array($campos['sistema'], ['TT', 'TN', 'IT'], true)) {
        $pdf->Cruz($fixos['sis_' . $campos['sistema']]);
    }

    foreach ($caixas as $k => $c) if ($c['p'] === $p) $pdf->Caixa($c['box'], $campos[$k] ?? '');

    foreach ($textos as $k => $c) if ($c['p'] === $p) $pdf->Linhas($c['seg'], $campos[$k] ?? '', $c['n'] . ' - ' . $c['txt']);

    foreach ($itens as $k => $c) {
        if ($c['p'] !== $p) continue;
        $e = $campos['e_' . $k] ?? '';
        if ($e === 'nao') {
            $pdf->Curto($c['s6'][0], 'não', 10, 'B');
            $pdf->Curto($c['s7'][0], 'quaisquer', 10, 'B');
        } elseif ($e === 'sim') {
            $pdf->Curto($c['s7'][0], 'as seguintes', 10, 'B');
            $pdf->Linhas($c['def'], $campos['d_' . $k] ?? '', $c['n'] . ' - ' . $c['txt'] . ' (deficiências)', 9.5, '');
        }
    }

    if ($p === 4) {
        // As páginas de anexo só ficam definidas depois de escrever as 4 páginas; o n.º é escrito no fim
        $pdf->Curto([$fixos['data_dia'][0], $fixos['data_dia'][1], $fixos['data_dia'][2]], $campos['data_dia'], 10, 'B', true);
        $pdf->Curto([$fixos['data_mes'][0], $fixos['data_mes'][1], $fixos['data_mes'][2]], $campos['data_mes'], 10, 'B', true);
        $pdf->Curto([$fixos['data_ano'][0], $fixos['data_ano'][1], $fixos['data_ano'][2]], $campos['data_ano'], 10, 'B', true);
        $pdf->Assinatura($assinatura, $fixos['assinatura'][0], $fixos['assinatura'][1], $fixos['assinatura'][2]);
    }
}

// --- Anexos com o texto que não coube no impresso (nota final 2 do Modelo 937) ---
$n_paginas_anexo = 0;
if ($pdf->anexos) {
    $ref = trim((string)($t['referencia_pt'] ?? ''));
    $nimp = trim((string)($campos['n_impresso'] ?? ''));
    $novaPaginaAnexo = function() use ($pdf, &$n_paginas_anexo, $ref, $nimp) {
        $n_paginas_anexo++;
        $pdf->AddPage();
        $pdf->SetXY(42, 40);
        $pdf->SetFont('Helvetica', 'B', 13);
        $pdf->Cell(0, 18, pt('ANEXO ' . $n_paginas_anexo . ' ao Relatório-tipo do técnico responsável (Modelo n.º 937)'), 0, 1);
        $pdf->SetX(42);
        $pdf->SetFont('Helvetica', '', 9);
        $pdf->Cell(0, 13, pt(m937_juntar([$nimp !== '' ? 'Impresso n.º ' . $nimp : '', $ref !== '' ? 'Ref.ª ' . $ref : ''], '   |   ')), 0, 1);
        $pdf->SetDrawColor(52, 73, 94);
        $pdf->Line(42, $pdf->GetY() + 4, 553, $pdf->GetY() + 4);
        $pdf->SetY($pdf->GetY() + 14);
    };
    $novaPaginaAnexo();
    foreach ($pdf->anexos as [$rot, $txt]) {
        if ($pdf->GetY() > 760) $novaPaginaAnexo();
        $pdf->SetX(42);
        $pdf->SetFont('Helvetica', 'B', 10);
        $pdf->MultiCell(511, 14, pt($rot . ' (continuação)'), 0, 'L');
        $pdf->SetFont('Helvetica', '', 10);
        foreach (explode("\n", $txt) as $par) {
            $pdf->SetX(42);
            $linhas_aprox = max(1, ceil($pdf->GetStringWidth(pt($par)) / 505));
            if ($pdf->GetY() + 14 * $linhas_aprox > 790) $novaPaginaAnexo();
            $pdf->SetX(42);
            $pdf->MultiCell(511, 14, pt($par), 0, 'L');
        }
        $pdf->Ln(8);
    }
    // Rodapé de assinatura nos anexos
    if ($pdf->GetY() > 730) $novaPaginaAnexo();
    $pdf->SetY(max($pdf->GetY() + 20, 680));
    $pdf->SetX(300);
    $pdf->SetFont('Helvetica', '', 9);
    $pdf->Cell(253, 12, pt('O Técnico Responsável,'), 0, 1, 'C');
    $yLinha = $pdf->GetY() + 48;
    $pdf->SetDrawColor(0, 0, 0);
    $pdf->Line(300, $yLinha, 553, $yLinha);
    $sdx = $pdf->dx; $sdy = $pdf->dy; $pdf->dx = 0; $pdf->dy = 0;     // nos anexos não há afinação
    $pdf->Assinatura($assinatura, 300, 553, $yLinha);
    $pdf->dx = $sdx; $pdf->dy = $sdy;
}

// N.º de anexos (pág. 4): o indicado pelo utilizador + as páginas de anexo geradas
$anx_user = trim((string)($campos['anexos'] ?? ''));
$anx_total = ($anx_user !== '' && is_numeric($anx_user) ? (int)$anx_user : 0) + $n_paginas_anexo;
$anx_txt = ($anx_user !== '' && !is_numeric($anx_user)) ? $anx_user : (string)$anx_total;
$pagina_fim = $pdf->PageNo();
$pdf->IrPagina(4);
$pdf->Curto([$fixos['anexos'][0], $fixos['anexos'][1], $fixos['anexos'][2]], $anx_txt, 10, 'B', true);
$pdf->IrPagina($pagina_fim);

ob_end_clean();
$nome = 'M937_' . preg_replace('/[^A-Za-z0-9_-]/', '', (string)($t['referencia_pt'] ?: $id)) . '.pdf';
$pdf->Output(!empty($_GET['download']) ? 'D' : 'I', $nome);
