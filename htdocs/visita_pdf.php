<?php
// REGRA DE OURO 4: Limpar qualquer espaço em branco ou lixo antes de gerar o PDF
ob_start();
session_start();

// REGRA DE OURO 1: Validação de sessão
if (!isset($_SESSION['user_id'])) {
    ob_end_clean();
    die("Acesso negado.");
}

require_once 'db.php';
require_once('fpdf/fpdf.php');
require_once 'levantamento_def.php';

// REGRA DE OURO 2: Acesso restrito pela matriz de permissões (as mesmas da ficha da visita)
$perfil_user = $_SESSION['perfil'] ?? '';
$stmt_perm = $pdo->prepare("SELECT modulo FROM permissoes WHERE grupo = :perfil AND pode_aceder = 1");
$stmt_perm->execute(['perfil' => $perfil_user]);
$meus_acessos = $stmt_perm->fetchAll(PDO::FETCH_COLUMN);
if (!in_array($perfil_user, ['admin', 'administrativo']) && !in_array('visitas', $meus_acessos) && !in_array('relatorios', $meus_acessos)) {
    ob_end_clean();
    die("Acesso negado.");
}

// REGRA DE OURO 5: ID validado como número inteiro
$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$id) {
    ob_end_clean();
    die("Erro: ID de visita inválido.");
}

// --- BUSCAR DADOS DA VISITA E JUNÇÕES ---
try {
    $sql = "SELECT v.*,
                   c.nome as cliente_nome, c.nif as cliente_nif, c.morada as cliente_morada,
                   c.codigo_postal as cliente_cp, c.cidade as cliente_cidade, c.telefone as cliente_telefone,
                   u.nome as colab_nome,
                   ob.nome as nome_obra, ob.referencia_obra,
                   orc.numero_orcamento
            FROM visitas v
            LEFT JOIN clientes c ON v.id_cliente = c.id
            LEFT JOIN utilizadores u ON v.colaborador_1 = u.id
            LEFT JOIN obras ob ON v.id_obra = ob.id
            LEFT JOIN orcamentos orc ON v.id_orcamento = orc.id
            WHERE v.id = ?";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$id]);
    $doc = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$doc) {
        ob_end_clean();
        die("Erro: Registo não encontrado.");
    }
} catch (Exception $e) {
    error_log('visita_pdf.php: ' . $e->getMessage());
    ob_end_clean();
    die("Não foi possível gerar o documento. Avise o administrador.");
}

// Acompanhamentos e reuniões de obra: só para utilizadores do tipo admin
if (!empty($doc['id_obra']) && ($_SESSION['perfil'] ?? '') !== 'admin') {
    ob_end_clean();
    die("Acesso negado.");
}

// Função de encoding para FPDF
function pt($texto) {
    $texto = (string)($texto ?? '');
    return mb_convert_encoding($texto, 'windows-1252', 'UTF-8');
}

// Otimização da imagem do logotipo
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

// ---------- FOTOS ----------
// Orientação gravada pela câmara do telemóvel (EXIF). Sem isto, as fotos tiradas ao alto saem deitadas no PDF.
function orientacaoExif($ficheiro) {
    if (function_exists('exif_read_data')) {
        $exif = @exif_read_data($ficheiro);
        return (int)($exif['Orientation'] ?? 1);
    }
    // Sem a extensão exif: lê a etiqueta diretamente do ficheiro JPEG
    $fh = @fopen($ficheiro, 'rb'); if (!$fh) return 1;
    $dados = fread($fh, 131072); fclose($fh);
    $pos = strpos($dados, "Exif\0\0"); if ($pos === false) return 1;
    $tiff = substr($dados, $pos + 6);
    if (strlen($tiff) < 8) return 1;
    $le = substr($tiff, 0, 2) === 'II';
    $u16 = fn($o) => $o + 2 <= strlen($tiff) ? unpack($le ? 'v' : 'n', substr($tiff, $o, 2))[1] : 0;
    $u32 = fn($o) => $o + 4 <= strlen($tiff) ? unpack($le ? 'V' : 'N', substr($tiff, $o, 4))[1] : 0;
    $ifd = $u32(4); $n = $u16($ifd);
    for ($i = 0; $i < $n && $i < 200; $i++) {
        $ent = $ifd + 2 + $i * 12;
        if ($u16($ent) === 0x0112) return (int)$u16($ent + 8);
    }
    return 1;
}

// Prepara a foto para o PDF: endireita-a (EXIF), reduz para no máximo 1400 px e converte WEBP/GIF em JPEG.
// A cópia fica em imagens/pdf_cache/ (é criada só uma vez). Devolve [ficheiro, largura px, altura px] ou null.
function fotoParaPdf($ficheiro) {
    $info = @getimagesize($ficheiro);
    if (!$info || $info[0] < 1 || $info[1] < 1) return null;
    [$w, $h, $tipo] = $info;
    $orient = ($tipo == IMAGETYPE_JPEG) ? orientacaoExif($ficheiro) : 1;
    $roda = in_array($orient, [3, 6, 8]);
    $max = 1400;
    $precisa = $roda || max($w, $h) > $max || !in_array($tipo, [IMAGETYPE_JPEG, IMAGETYPE_PNG]);
    if (!$precisa || !function_exists('imagecreatetruecolor')) {
        return in_array($tipo, [IMAGETYPE_JPEG, IMAGETYPE_PNG]) ? [$ficheiro, $w, $h] : null;
    }

    $pasta = 'imagens/pdf_cache/';
    $cache = $pasta . md5($ficheiro . '|' . @filemtime($ficheiro) . '|v2') . '.jpg';
    if (file_exists($cache) && ($ci = @getimagesize($cache))) return [$cache, $ci[0], $ci[1]];

    $origem = null;
    if ($tipo == IMAGETYPE_JPEG) $origem = @imagecreatefromjpeg($ficheiro);
    elseif ($tipo == IMAGETYPE_PNG) $origem = @imagecreatefrompng($ficheiro);
    elseif ($tipo == IMAGETYPE_GIF) $origem = @imagecreatefromgif($ficheiro);
    elseif (defined('IMAGETYPE_WEBP') && $tipo == IMAGETYPE_WEBP && function_exists('imagecreatefromwebp')) $origem = @imagecreatefromwebp($ficheiro);
    if (!$origem) return in_array($tipo, [IMAGETYPE_JPEG, IMAGETYPE_PNG]) ? [$ficheiro, $w, $h] : null;

    // Reduz primeiro (gasta menos memória a rodar)
    $f = min(1, $max / max($w, $h));
    $nw = max(1, (int)round($w * $f)); $nh = max(1, (int)round($h * $f));
    $img = imagecreatetruecolor($nw, $nh);
    imagefill($img, 0, 0, imagecolorallocate($img, 255, 255, 255));   // fundo branco (PNG/GIF transparentes)
    imagecopyresampled($img, $origem, 0, 0, 0, 0, $nw, $nh, $w, $h);
    imagedestroy($origem);
    if ($orient == 3) $img = imagerotate($img, 180, 0);
    elseif ($orient == 6) $img = imagerotate($img, -90, 0);
    elseif ($orient == 8) $img = imagerotate($img, 90, 0);

    if (!is_dir($pasta)) @mkdir($pasta, 0755, true);
    $ok = @imagejpeg($img, $cache, 82);
    $fw = imagesx($img); $fh = imagesy($img);
    imagedestroy($img);
    if ($ok) return [$cache, $fw, $fh];
    return in_array($tipo, [IMAGETYPE_JPEG, IMAGETYPE_PNG]) && !$roda ? [$ficheiro, $w, $h] : null;
}

// Desenha as fotos em grelha ($por_linha por linha), cada uma dentro de uma célula de largura fixa e altura
// máxima $h_max, sem deformar e centrada. Cada linha começa abaixo da foto mais alta da linha anterior e, no fim,
// o cursor fica por baixo da última linha (para o que vem a seguir não ficar por cima das fotos).
function desenharFotos($pdf, $ficheiros, $por_linha, $h_max) {
    $x0 = 10; $largura_util = 190; $espaco = 4; $limite = 272;
    $w_cel = ($largura_util - ($por_linha - 1) * $espaco) / $por_linha;
    $fotos = [];
    foreach ($ficheiros as $f) {
        $p = fotoParaPdf($f);
        if (!$p) continue;
        [$fich, $pw, $ph] = $p;
        $w = $w_cel; $h = $w * $ph / $pw;
        if ($h > $h_max) { $h = $h_max; $w = $h * $pw / $ph; }
        $fotos[] = [$fich, $w, $h];
    }
    if (!$fotos) return;
    foreach (array_chunk($fotos, $por_linha) as $linha) {
        $alt = max(array_column($linha, 2));
        $y = $pdf->GetY();
        if ($y + $alt > $limite) { $pdf->AddPage(); $y = $pdf->GetY(); }
        foreach ($linha as $i => [$fich, $w, $h]) {
            $x = $x0 + $i * ($w_cel + $espaco) + ($w_cel - $w) / 2;
            try { $pdf->Image($fich, $x, $y, $w, $h); } catch (Exception $e) { error_log('visita_pdf.php (foto ' . $fich . '): ' . $e->getMessage()); }
        }
        $pdf->SetY($y + $alt + $espaco);
    }
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

        // Linha Dinâmica
        $y_text = $this->GetY();
        $y_line = max($y_text, $logo_bottom_y) + 3;

        $this->SetDrawColor(232, 67, 147); // Cor Rosa das Visitas
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

    // Nº de linhas que um texto ocupa numa largura (para caixas de altura variável)
    function NbLinhas($w, $txt) {
        $cw = &$this->CurrentFont['cw'];
        $wmax = ($w - 2 * $this->cMargin) * 1000 / $this->FontSize;
        $s = str_replace("\r", '', (string)$txt); $nb = strlen($s);
        $sep = -1; $i = 0; $j = 0; $l = 0; $nl = 1;
        while ($i < $nb) {
            $c = $s[$i];
            if ($c == "\n") { $i++; $sep = -1; $j = $i; $l = 0; $nl++; continue; }
            if ($c == ' ') $sep = $i;
            $l += $cw[$c] ?? 500;
            if ($l > $wmax) { if ($sep == -1) { if ($i == $j) $i++; } else $i = $sep + 1; $sep = -1; $j = $i; $l = 0; $nl++; } else $i++;
        }
        return $nl;
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

    // NOVO: Linha com 3 pares (Data | Hora Entrada | Hora Saída) - total 190mm
    function DrawRow3($label1, $val1, $label2, $val2, $label3, $val3) {
        $w_lbl = 25;
        $w_val = 38.33;

        $this->SetFont('Arial', 'B', 9);
        $this->Cell($w_lbl, 6, pt($label1), 'L,B', 0);
        $this->SetFont('Arial', '', 9);
        $this->Cell($w_val, 6, pt($val1), 'B', 0);

        $this->SetFont('Arial', 'B', 9);
        $this->Cell($w_lbl, 6, pt($label2), 'L,B', 0);
        $this->SetFont('Arial', '', 9);
        $this->Cell($w_val, 6, pt($val2), 'B', 0);

        $this->SetFont('Arial', 'B', 9);
        $this->Cell($w_lbl, 6, pt($label3), 'L,B', 0);
        $this->SetFont('Arial', '', 9);
        $this->Cell(190 - (3 * $w_lbl) - (2 * $w_val), 6, pt($val3), 'R,B', 1);
    }
}

// PREPARAÇÃO DE CONTEXTO (o título do documento acompanha o tipo de registo)
$contexto = 'Visita Comercial / Angariação';
$titulo_tipo = 'VISITA COMERCIAL';
$titulo_notas = 'NOTAS E DETALHES DA VISITA';
if (!empty($doc['id_obra'])) {
    if (stripos($doc['assunto'], 'Reunião') !== false) {
        $contexto = 'Reunião Referente à Obra [' . $doc['referencia_obra'] . '] ' . $doc['nome_obra'];
        $titulo_tipo = 'REUNIÃO DE OBRA';
        $titulo_notas = 'NOTAS E DETALHES DA REUNIÃO';
    } else {
        $contexto = 'Acompanhamento Obra [' . $doc['referencia_obra'] . '] ' . $doc['nome_obra'];
        $titulo_tipo = 'ACOMPANHAMENTO DE OBRA';
        $titulo_notas = 'NOTAS DO ACOMPANHAMENTO';
    }
} elseif (!empty($doc['id_orcamento'])) {
    $contexto = 'Acompanhamento Orçamento [' . $doc['numero_orcamento'] . ']';
    $titulo_tipo = 'ACOMPANHAMENTO DE ORÇAMENTO';
    $titulo_notas = 'NOTAS DO ACOMPANHAMENTO';
}

$data_v = date('d/m/Y', strtotime($doc['data_visita']));
$hora_v = date('H:i', strtotime($doc['hora_visita']));
$hora_s = !empty($doc['hora_saida']) ? date('H:i', strtotime($doc['hora_saida'])) : '--:--';
$morada_cli = trim($doc['cliente_morada'] . ' ' . $doc['cliente_cp'] . ' ' . $doc['cliente_cidade']);

// INICIALIZAR PDF
$pdf = new PDF('P', 'mm', 'A4');
$pdf->titulo_doc = $titulo_tipo . ' Nº ' . $doc['referencia_visita'];
$pdf->SetMargins(10, 35, 10);
$pdf->AliasNbPages();
$pdf->AddPage();
$pdf->SetAutoPageBreak(true, 20);

// --- IDENTIFICAÇÃO GERAL ---
$pdf->DrawBoxTitle('DETALHES DO REGISTO E CLIENTE');

// Cliente numa linha isolada para evitar sobreposição
$pdf->DrawRow('Cliente:', $doc['cliente_nome']);

// NIF e Telefone partilham a mesma linha
$pdf->DrawRow('NIF Cliente:', $doc['cliente_nif'] ?: 'N/D', 'Telefone:', $doc['cliente_telefone'] ?: 'N/D');

// Morada do Cliente
if (!empty($morada_cli)) {
    $pdf->DrawRow('Morada:', $morada_cli);
}

// Contexto ganha agora também a linha inteira porque as descrições de obra/orçamentos podem ser longas
$pdf->DrawRow('Contexto:', $contexto);

// Informações da Visita agrupadas
$pdf->DrawRow3('Data Registo:', $data_v, 'Hora Entrada:', $hora_v, 'Hora Saída:', $hora_s);
$pdf->DrawRow('Estado:', $doc['estado'] ?? 'Não Processada', 'Colaborador:', $doc['colab_nome']);


// --- ASSUNTO E NOTAS ---
$pdf->DrawBoxTitle('ASSUNTO PRINCIPAL');
$pdf->Ln(2);
$pdf->SetFont('Arial', 'B', 10);
$pdf->SetTextColor(44, 62, 80);
$pdf->MultiCell(0, 6, pt($doc['assunto']), 0, 'L');
$pdf->SetTextColor(0, 0, 0);

$pdf->DrawBoxTitle($titulo_notas);
$pdf->Ln(2);
$pdf->SetFont('Arial', '', 10);
$notas = trim($doc['notas']);
if (empty($notas)) {
    $pdf->SetFont('Arial', 'I', 9);
    $pdf->Cell(0, 6, pt('Nenhum detalhe ou nota registada.'), 0, 1, 'L');
} else {
    // Espaçamento de linha confortável (1.5)
    $pdf->MultiCell(0, 6, pt($notas), 0, 'J');
}

// --- LEVANTAMENTO DE NECESSIDADES ---
$levantamento = levCarregar($pdo, $id);
if (!empty($levantamento)) {
    if ($pdf->GetY() > 240) $pdf->AddPage();
    $pdf->Ln(4);
    $pdf->DrawBoxTitle('LEVANTAMENTO DE NECESSIDADES');

    // Resumo: áreas e o que é para orçamentar
    $resumo = [];
    foreach ($LEV_GRUPOS as $g) {
        foreach ($g['areas'] as $ak) {
            if (isset($levantamento[$ak])) $resumo[] = $LEV_AREAS[$ak]['nome'] . (!empty($levantamento[$ak]['fazer_orcamento']) ? ' (orçamentar)' : '');
        }
    }
    $pdf->SetFont('Arial', '', 9);
    $pdf->MultiCell(0, 5, pt('Áreas: ' . implode(' · ', $resumo)), 0, 'L');

    foreach ($LEV_GRUPOS as $g) {
        foreach ($g['areas'] as $ak) {
            if (!isset($levantamento[$ak])) continue;
            $ad = $LEV_AREAS[$ak];
            $lv = $levantamento[$ak];
            if ($pdf->GetY() > 245) $pdf->AddPage();
            $pdf->Ln(3);
            [$r, $gg, $b] = sscanf($g['cor'], '#%02x%02x%02x');
            $pdf->SetFillColor($r, $gg, $b); $pdf->SetTextColor(255, 255, 255); $pdf->SetFont('Arial', 'B', 9);
            $pdf->Cell(0, 6, pt('  ' . mb_strtoupper($ad['nome'], 'UTF-8') . '  ·  ' . $g['nome']), 0, 1, 'L', true);
            $pdf->SetTextColor(0, 0, 0);

            // Respostas (2 por linha)
            $pares = [];
            foreach ($ad['campos'] as $ck => $cdef) {
                $txt = levValorTexto($cdef, $lv['dados'][$ck] ?? '');
                if ($txt !== '') $pares[] = [str_replace('→', '>', $cdef[0]) . ':', $txt]; // a seta não existe no tipo de letra do PDF
            }
            if (!empty($ad['estimativa_cabos'])) {
                $est = levEstimativaCabos($lv['dados'] ?? []);
                if ($est) $pares[] = ['Estimativa de cabo:', $est['texto']];
            }
            if (!empty($lv['prioridade'])) $pares[] = ['Prioridade:', $lv['prioridade']];
            if (!empty($lv['orcamento_cliente'])) $pares[] = ['Orçamento do cliente:', $lv['orcamento_cliente']];
            $pares[] = ['Fazer orçamento:', !empty($lv['fazer_orcamento']) ? 'Sim' : 'Não'];

            $rotulo = function ($txt) use ($pdf) {
                // Rótulos compridos encolhem a letra para caberem nos 45 mm
                $fs = 8.5; $pdf->SetFont('Arial', 'B', $fs);
                while ($fs > 6 && $pdf->GetStringWidth(pt($txt)) > 44) { $fs -= 0.5; $pdf->SetFont('Arial', 'B', $fs); }
                $pdf->Cell(45, 6, pt($txt), 'L,B', 0);
            };
            // Valores curtos vão 2 por linha; valores compridos ocupam a linha toda
            $pdf->SetFont('Arial', '', 8.5);
            $curtos = []; $longos = [];
            foreach ($pares as $par) {
                if ($pdf->GetStringWidth(pt($par[1])) > 48) $longos[] = $par; else $curtos[] = $par;
            }
            for ($i = 0; $i < count($curtos); $i += 2) {
                $p1 = $curtos[$i]; $p2 = $curtos[$i + 1] ?? null;
                $rotulo($p1[0]);
                $pdf->SetFont('Arial', '', 8.5);  $pdf->Cell($p2 ? 50 : 145, 6, pt($p1[1]), $p2 ? 'B' : 'R,B', $p2 ? 0 : 1);
                if ($p2) {
                    $rotulo($p2[0]);
                    $pdf->SetFont('Arial', '', 8.5);  $pdf->Cell(50, 6, pt($p2[1]), 'R,B', 1);
                }
            }
            foreach ($longos as $par) {
                $y0 = $pdf->GetY();
                $pdf->SetFont('Arial', '', 8.5);
                $n_l = max(1, $pdf->NbLinhas(143, pt($par[1])));
                $h_l = max(6, $n_l * 5 + 1);
                if ($y0 + $h_l > 270) { $pdf->AddPage(); $y0 = $pdf->GetY(); }
                $pdf->Rect(10, $y0, 190, $h_l);
                $rotulo($par[0]);
                $pdf->SetFont('Arial', '', 8.5);
                $pdf->SetXY(57, $y0 + 0.5);
                $pdf->MultiCell(143, 5, pt($par[1]), 0, 'L');
                $pdf->SetY($y0 + $h_l);
            }
            // Linhas da área (tabela)
            $linhas_area = $lv['dados']['_linhas'] ?? [];
            if (!empty($ad['linhas']) && $linhas_area) {
                $cols = $ad['linhas'];
                $pdf->Ln(2);
                $pdf->SetFont('Arial', 'B', 8.5); $pdf->Cell(0, 5, pt(($ad['linhas_titulo'] ?? 'Linhas') . ' (' . count($linhas_area) . ')'), 0, 1);
                // Larguras: colunas de número estreitas, o resto divide os 190 mm
                $n_num = 0; foreach ($cols as $cd) if ($cd[1] === 'num') $n_num++;
                $larga = count($cols) > 5;
                $w_num = $larga ? 18 : 16; $w_txt = (190 - $n_num * $w_num) / max(1, count($cols) - $n_num);
                $pdf->SetFillColor(241, 242, 246); $pdf->SetFont('Arial', 'B', $larga ? 6.5 : 7.5);
                foreach ($cols as $cd) {
                    $w_c = $cd[1] === 'num' ? $w_num : $w_txt;
                    $tit = pt($cd[0]);
                    while ($tit !== '' && $pdf->GetStringWidth($tit) > $w_c - 1.5) $tit = substr($tit, 0, -1);
                    $pdf->Cell($w_c, 6, $tit, 1, 0, $cd[1] === 'num' ? 'C' : 'L', true);
                }
                $pdf->Ln();
                $pdf->SetFont('Arial', '', $larga ? 7 : 8);
                foreach ($linhas_area as $la) {
                    if ($pdf->GetY() > 265) $pdf->AddPage();
                    foreach ($cols as $ck => $cd) {
                        $w_c = $cd[1] === 'num' ? $w_num : $w_txt;
                        $txt = pt($la[$ck] ?? '');
                        while ($txt !== '' && $pdf->GetStringWidth($txt) > $w_c - 2) $txt = substr($txt, 0, -1);
                        $pdf->Cell($w_c, 6, $txt, 1, 0, $cd[1] === 'num' ? 'C' : 'L');
                    }
                    $pdf->Ln();
                }
            }
            if (!empty($lv['notas'])) {
                $pdf->SetFont('Arial', 'B', 8.5); $pdf->Cell(0, 6, pt('Notas:'), 0, 1);
                $pdf->SetFont('Arial', '', 9); $pdf->MultiCell(0, 5, pt($lv['notas']), 0, 'J');
            }
            // Fotos da área (4 por linha)
            $fotos_area = [];
            foreach ((array)($lv['fotos'] ?? []) as $f) {
                $f = basename((string)$f);
                if ($f !== '' && file_exists('imagens/' . $f)) $fotos_area[] = 'imagens/' . $f;
            }
            if ($fotos_area) {
                $pdf->Ln(2);
                desenharFotos($pdf, $fotos_area, 4, 60);
            }
        }
    }
}

// --- REGISTO FOTOGRÁFICO ---
$fotos = json_decode($doc['fotos'] ?? '[]', true);

if (!empty($fotos) && is_array($fotos)) {
    // Garante que há espaço para o título das fotos, se não salta página
    if ($pdf->GetY() > 240) { $pdf->AddPage(); }

    $pdf->Ln(5);
    $pdf->DrawBoxTitle('REGISTO FOTOGRÁFICO / ANEXOS');
    $pdf->Ln(4);

    // 3 fotos por linha, até 80 mm de altura cada (as fotos ao alto ficam mais estreitas, centradas)
    $lista_fotos = [];
    foreach ($fotos as $f) {
        $f = basename((string)$f);
        if ($f !== '' && file_exists('imagens/' . $f)) $lista_fotos[] = 'imagens/' . $f;
    }
    desenharFotos($pdf, $lista_fotos, 3, 80);
}

// --- ASSINATURAS FINAIS ---
$pdf->Ln(15);
// Prevenção de quebra a meio das assinaturas (precisam de ~30 mm)
if ($pdf->GetY() > 245) { $pdf->AddPage(); $pdf->Ln(10); }

$y_assinaturas = $pdf->GetY();

// Assinatura Cliente
$pdf->SetFont('Arial', 'B', 9);
$pdf->SetXY(20, $y_assinaturas);
$pdf->Cell(70, 5, pt('O Cliente / Responsável'), 0, 1, 'C');
$pdf->Line(20, $pdf->GetY() + 12, 90, $pdf->GetY() + 12);
$pdf->SetXY(20, $pdf->GetY() + 15);
$pdf->SetFont('Arial', '', 8);
$pdf->Cell(70, 5, pt($doc['cliente_nome']), 0, 0, 'C');

// Assinatura Colaborador
$pdf->SetFont('Arial', 'B', 9);
$pdf->SetXY(115, $y_assinaturas);
$pdf->Cell(70, 5, pt('Pela M. Abreu, Lda / Colaborador'), 0, 1, 'C');
$pdf->Line(115, $pdf->GetY() + 12, 185, $pdf->GetY() + 12);
$pdf->SetXY(115, $pdf->GetY() + 15);
$pdf->SetFont('Arial', '', 8);
$pdf->Cell(70, 5, pt($doc['colab_nome']), 0, 0, 'C');

ob_end_clean();
$filename = str_replace(' ', '_', ucwords(mb_strtolower($titulo_tipo, 'UTF-8'))) . '_' . preg_replace('/[^A-Za-z0-9_-]/', '_', $doc['cliente_nome']) . '_' . $doc['referencia_visita'] . '.pdf';
$pdf->Output('I', pt($filename));
?>