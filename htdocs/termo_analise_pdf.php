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

if (!isset($_GET['id']) || empty($_GET['id'])) {
    ob_end_clean();
    die("ID do relatório não especificado.");
}

$id_termo = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

// --- BUSCAR DADOS NA BD ---
try {
    $sql = "SELECT t.*, o.nome as nome_obra, o.morada as morada_obra, c.nome as cliente_nome, c.nif as cliente_nif
            FROM termos_analise t
            LEFT JOIN obras o ON t.id_obra = o.id
            LEFT JOIN clientes c ON t.id_cliente = c.id
            WHERE t.id = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$id_termo]);
    $relatorio = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$relatorio) throw new Exception("Relatório não encontrado.");

    $equipamentos = json_decode($relatorio['equipamentos_json'], true) ?: [];
    // Fotos (pasta imagens/) — só as que existem e o FPDF consegue ler (JPG / PNG / GIF)
    $fotos = [];
    foreach ((json_decode((string)($relatorio['fotos'] ?? ''), true) ?: []) as $f) {
        $cam = 'imagens/' . basename((string)$f);
        $inf = is_file($cam) ? @getimagesize($cam) : false;
        if ($inf && in_array($inf[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_GIF], true)) $fotos[] = ['cam' => $cam, 'w' => $inf[0], 'h' => $inf[1]];
    }
    $data_vistoria = date('d/m/Y', strtotime($relatorio['data_vistoria']));

    $local_obra = 'Braga';
    if (!empty($relatorio['morada_obra'])) {
        $partes = explode(',', $relatorio['morada_obra']);
        foreach (array_reverse($partes) as $p) {
            $p_limpa = trim(preg_replace('/^[0-9\-\sNnºº.]+/i', '', $p));
            if (strlen($p_limpa) > 2) { $local_obra = $p_limpa; break; }
        }
    }

} catch (Exception $e) {
    ob_end_clean();
    die("Erro na BD: " . htmlspecialchars($e->getMessage()));
}

function pt($texto) {
    $texto = (string)($texto ?? '');
    return mb_convert_encoding($texto, 'windows-1252', 'UTF-8');
}

class PDF extends FPDF {
    public $titulo_doc;

    function NbLines($w, $txt) {
        $cw = &$this->CurrentFont['cw'];
        if($w == 0) $w = $this->w - $this->rMargin - $this->x;
        $wmax = ($w - 2 * $this->cMargin) * 1000 / $this->FontSize;
        $s = str_replace("\r", '', $txt);
        $nb = strlen($s);
        if($nb > 0 && $s[$nb-1] == "\n") $nb--;
        $sep = -1; $i = 0; $j = 0; $l = 0; $nl = 1;
        while($i < $nb) {
            $c = $s[$i];
            if($c == "\n") { $i++; $sep = -1; $j = $i; $l = 0; $nl++; continue; }
            if($c == ' ') $sep = $i;
            $l += $cw[$c];
            if($l > $wmax) {
                if($sep == -1) { if($i == $j) $i++; } else $i = $sep + 1;
                $sep = -1; $j = $i; $l = 0; $nl++;
            } else $i++;
        }
        return $nl;
    }

    function Header() {
        if (file_exists('mab.png')) {
            $this->Image('mab.png', 10, 10, 17.5);
        }
        $this->SetY(12);
        $this->SetFont('Arial', 'B', 13);
        $this->SetTextColor(0, 0, 0); // Preto
        $this->Cell(0, 6, pt($this->titulo_doc), 0, 1, 'R');
        $this->SetFont('Arial', '', 9);
        $this->SetTextColor(80, 80, 80); // Cinza escuro
        $this->Cell(0, 5, pt('Equipamentos Elétricos e Eletrónicos'), 0, 1, 'R');
        $this->Ln(8);
        $y = $this->GetY();
        $this->SetDrawColor(0, 0, 0); // Preto
        $this->SetLineWidth(0.5);
        $this->Line(10, $y, 200, $y);
        $this->Ln(5);
    }

    function Footer() {
        $this->SetY(-15);
        $this->SetDrawColor(0, 0, 0); // Preto
        $this->SetLineWidth(0.5);
        $this->Line(10, $this->GetY(), 200, $this->GetY());
        $this->Ln(2);
        $this->SetFont('Arial', '', 8);
        $this->SetTextColor(0, 0, 0);
        $linha1 = pt("m. Abreu - Instalações Técnicas, Lda. | NIF: 514 414 120 | Registo ANPC: 3707");
        $linha2 = pt("Rua Damião de Góis, 205, Braga | Email: marioabreu@mabreu.pt | Tel: 914 259 487");
        $y_footer = $this->GetY();
        $this->Cell(0, 4, $linha1, 0, 0, 'C');
        $this->SetXY(10, $y_footer);
        $this->Cell(0, 4, pt('Página ') . $this->PageNo() . '/{nb}', 0, 1, 'R');
        $this->Cell(0, 4, $linha2, 0, 1, 'C');
    }

    function TituloSeccao($num, $texto, $cor = [0, 0, 0]) {
        $this->SetFont('Arial', 'B', 10);
        $this->SetTextColor($cor[0], $cor[1], $cor[2]);
        $this->Cell(0, 8, pt($num . '. ' . $texto), 0, 1, 'L');
        $this->SetDrawColor(200, 200, 200); // Cinza claro
        $this->SetLineWidth(0.2);
        $this->Line(10, $this->GetY(), 200, $this->GetY());
        $this->Ln(3);
        $this->SetTextColor(0, 0, 0);
    }
}

$pdf = new PDF();
$pdf->titulo_doc = 'RELATÓRIO DE ANÁLISE TÉCNICA';
$pdf->AliasNbPages();
$pdf->AddPage();
$pdf->SetAutoPageBreak(true, 20);

// --- DADOS DA VISTORIA ---
$pdf->SetFont('Arial', 'B', 9);
$pdf->SetFillColor(240, 240, 240); // Cinza claro
$pdf->Cell(35, 6, pt('Data:'), 1, 0, 'L', true);
$pdf->SetFont('Arial', '', 9);
$pdf->Cell(55, 6, pt(' ' . $data_vistoria), 1, 0, 'L');

$pdf->SetFont('Arial', 'B', 9);
$pdf->Cell(35, 6, pt('Nº Relatório:'), 1, 0, 'R', true);
$pdf->SetFont('Arial', '', 9);
$pdf->Cell(65, 6, pt(' ' . ($relatorio['numero_chamado'] ?: 'N/D')), 1, 1, 'L');

$pdf->SetFont('Arial', 'B', 9);
$pdf->Cell(35, 6, pt('Técnico Responsável:'), 1, 0, 'R', true);
$pdf->SetFont('Arial', '', 9);
$pdf->Cell(155, 6, pt(' ' . ($relatorio['tecnico'] ?: 'N/D')), 1, 1, 'L');

$pdf->Ln(5);

// --- 1. IDENTIFICAÇÃO DO CLIENTE ---
$pdf->TituloSeccao('1', 'Identificação do Cliente');
$pdf->SetFont('Arial', 'B', 9);
$pdf->Cell(35, 6, pt('Cliente:'), 1, 0, 'R', true);
$pdf->SetFont('Arial', '', 9);
$pdf->Cell(155, 6, pt(' ' . ($relatorio['cliente_nome'] ?? 'N/D')), 1, 1, 'L');

$pdf->SetFont('Arial', 'B', 9);
$pdf->Cell(35, 6, pt('Contribuinte:'), 1, 0, 'R', true);
$pdf->SetFont('Arial', '', 9);
$pdf->Cell(155, 6, pt(' ' . ($relatorio['cliente_nif'] ?: 'N/D')), 1, 1, 'L');

// Obra e Local ocupam a largura toda e passam para a linha seguinte se o texto for comprido (moradas)
$linhaLonga = function ($rotulo, $valor) use ($pdf) {
    $txt = pt(' ' . (trim((string)$valor) !== '' ? trim(preg_replace('/\s+/', ' ', (string)$valor)) : 'N/D'));
    $pdf->SetFont('Arial', '', 9);
    $h = 6 * max(1, $pdf->NbLines(155, $txt));
    $x = $pdf->GetX(); $y = $pdf->GetY();
    $pdf->SetFont('Arial', 'B', 9);
    $pdf->Cell(35, $h, pt($rotulo), 1, 0, 'R', true);
    $pdf->SetFont('Arial', '', 9);
    $pdf->SetXY($x + 35, $y);
    $pdf->MultiCell(155, 6, $txt, 1, 'L');
    $pdf->SetXY($x, $y + $h);
};
$linhaLonga('Obra:', $relatorio['nome_obra'] ?? '');
$linhaLonga('Local Ocorrência:', $relatorio['morada_obra'] ?? '');

$pdf->Ln(5);

// --- 2. ESPECIFICAÇÕES DO EQUIPAMENTO ---
// A coluna "Nº de Série" só aparece se pelo menos um equipamento tiver o número de série preenchido;
// se nenhum tiver, a coluna desaparece e a descrição fica mais larga.
$tem_sn = false;
foreach ($equipamentos as $eq) {
    if (trim((string)($eq['sn'] ?? '')) !== '') { $tem_sn = true; break; }
}
$w_desc = $tem_sn ? 75 : 105;   // 15 + desc + 40 + 30 (+ 30) = 190

$pdf->TituloSeccao('2', 'Especificações do Equipamento');
$pdf->SetFont('Arial', 'B', 8);
$pdf->SetFillColor(240, 240, 240); // Cinza claro
$pdf->SetTextColor(0, 0, 0); // Preto
$pdf->Cell(15, 6, pt('Item'), 1, 0, 'C', true);
$pdf->Cell($w_desc, 6, pt('Descrição do Aparelho'), 1, 0, 'L', true);
$pdf->Cell(40, 6, pt('Marca'), 1, 0, 'L', true);
if ($tem_sn) {
    $pdf->Cell(30, 6, pt('Modelo'), 1, 0, 'L', true);
    $pdf->Cell(30, 6, pt('Nº de Série'), 1, 1, 'L', true);
} else {
    $pdf->Cell(30, 6, pt('Modelo'), 1, 1, 'L', true);
}

$pdf->SetTextColor(0, 0, 0);
$pdf->SetFont('Arial', '', 8);

if (empty($equipamentos)) {
    $pdf->Cell(190, 8, pt('Nenhum equipamento detalhado.'), 1, 1, 'C');
} else {
    $i = 1;
    foreach ($equipamentos as $eq) {
        $desc = (string)($eq['descricao'] ?? '');
        $linhas = $pdf->NbLines($w_desc, pt(' ' . $desc));
        $h = 5 * max(1, $linhas);

        // Não partir uma linha da tabela entre duas páginas
        if ($pdf->GetY() + $h > 277) $pdf->AddPage();   // A4: 297 mm - margem do rodapé

        $x = $pdf->GetX(); $y = $pdf->GetY();
        $pdf->Cell(15, $h, str_pad($i++, 2, '0', STR_PAD_LEFT), 1, 0, 'C');

        $pdf->SetXY($x + 15, $y);
        $pdf->MultiCell($w_desc, 5, pt(' ' . $desc), 1, 'L');
        // Se a descrição ocupou menos linhas que a altura da linha, completa a moldura
        if ($pdf->GetY() < $y + $h) $pdf->Rect($x + 15, $y, $w_desc, $h);

        $cx = $x + 15 + $w_desc;
        $pdf->SetXY($cx, $y);
        $pdf->Cell(40, $h, pt(' ' . ($eq['marca'] ?? '')), 1, 0, 'L');
        $cx += 40;

        $pdf->SetXY($cx, $y);
        if ($tem_sn) {
            $pdf->Cell(30, $h, pt(' ' . ($eq['modelo'] ?? '')), 1, 0, 'L');
            $cx += 30;
            $pdf->SetXY($cx, $y);
            $pdf->Cell(30, $h, pt(' ' . trim((string)($eq['sn'] ?? ''))), 1, 1, 'L');
        } else {
            $pdf->Cell(30, $h, pt(' ' . ($eq['modelo'] ?? '')), 1, 1, 'L');
        }
    }
}
$pdf->Ln(5);

// Fotos de cada equipamento (tiradas na secção 2 do formulário): por baixo da tabela, identificadas pelo nº do item
$fotos_eq_pdf = [];
foreach (array_values($equipamentos) as $i_eq => $eq) {
    $lst = [];
    foreach (($eq['fotos'] ?? []) as $fo) {
        $cam = 'imagens/' . basename((string)$fo);
        $inf = is_file($cam) ? @getimagesize($cam) : false;
        if ($inf && in_array($inf[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_GIF], true)) $lst[] = ['cam' => $cam, 'w' => $inf[0], 'h' => $inf[1]];
    }
    if ($lst) $fotos_eq_pdf[] = ['n' => $i_eq + 1, 'desc' => (string)($eq['descricao'] ?? ''), 'fotos' => $lst];
}
if ($fotos_eq_pdf) {
    $cw = 92; $ch = 69;                     // caixa de cada foto (mm), duas por linha
    foreach ($fotos_eq_pdf as $bloco) {
        if ($pdf->GetY() + 8 + $ch + 6 > 277) $pdf->AddPage();
        $pdf->SetFont('Arial', 'B', 8.5); $pdf->SetTextColor(74, 105, 189);
        $pdf->Cell(0, 6, pt('Item ' . str_pad($bloco['n'], 2, '0', STR_PAD_LEFT) . ($bloco['desc'] !== '' ? ' — ' . $bloco['desc'] : '')), 0, 1, 'L');
        $pdf->SetTextColor(0, 0, 0);
        $col = 0; $y_lin = $pdf->GetY();
        foreach ($bloco['fotos'] as $k => $f) {
            if ($col === 0 && $y_lin + $ch + 6 > 277) { $pdf->AddPage(); $y_lin = $pdf->GetY(); }
            $x0 = 10 + $col * ($cw + 6);
            $esc = min($cw / $f['w'], $ch / $f['h']);
            $iw = $f['w'] * $esc; $ih = $f['h'] * $esc;
            $pdf->SetDrawColor(220, 220, 220); $pdf->SetLineWidth(0.2);
            $pdf->Rect($x0, $y_lin, $cw, $ch);
            $pdf->Image($f['cam'], $x0 + ($cw - $iw) / 2, $y_lin + ($ch - $ih) / 2, $iw, $ih);
            $pdf->SetFont('Arial', '', 7.5); $pdf->SetTextColor(110, 110, 110);
            $pdf->SetXY($x0, $y_lin + $ch + 0.5);
            $pdf->Cell($cw, 4, pt('Item ' . str_pad($bloco['n'], 2, '0', STR_PAD_LEFT) . ' · Foto ' . ($k + 1)), 0, 0, 'C');
            $pdf->SetTextColor(0, 0, 0);
            $col++;
            if ($col === 2) { $col = 0; $y_lin += $ch + 7; }
        }
        $pdf->SetY($col === 0 ? $y_lin : $y_lin + $ch + 7);
    }
    $pdf->Ln(2);
}

// --- 3. DESCRIÇÃO DO DANO E CONSTATAÇÕES ---
$pdf->TituloSeccao('3', 'Descrição do Dano e Constatações');

$pdf->SetFont('Arial', 'B', 9);
$pdf->Cell(0, 5, pt('Sintomas Relatados:'), 0, 1, 'L');
$pdf->SetFont('Arial', '', 9);
$pdf->MultiCell(0, 5, pt($relatorio['sintomas'] ?: 'Sem sintomas relatados.'), 0, 'J');
$pdf->Ln(3);

$pdf->SetFont('Arial', 'B', 9);
$pdf->Cell(0, 5, pt('Constatações Técnicas:'), 0, 1, 'L');
$pdf->SetFont('Arial', '', 9);
$pdf->MultiCell(0, 5, pt($relatorio['constatacoes'] ?: 'Sem constatações registadas.'), 0, 'J');
$pdf->Ln(3);

$pdf->SetFont('Arial', 'B', 9);
$pdf->Cell(0, 5, pt('Causa Provável:'), 0, 1, 'L');
$pdf->SetFont('Arial', '', 9);
$pdf->MultiCell(0, 5, pt($relatorio['causa'] ?: 'Indeterminada.'), 0, 'J');
$pdf->Ln(3);

if (!empty($relatorio['observacoes'])) {
    $pdf->SetFont('Arial', 'B', 9);
    $pdf->Cell(0, 5, pt('Observações Adicionais:'), 0, 1, 'L');
    $pdf->SetFont('Arial', '', 9);
    $pdf->MultiCell(0, 5, pt($relatorio['observacoes']), 0, 'J');
    $pdf->Ln(3);
}

$pdf->Ln(5);

// --- 4. REGISTO FOTOGRÁFICO (só se houver fotos): duas por linha, sem deformar ---
$sec = 4;
if ($fotos) {
    if ($pdf->GetY() > 200) $pdf->AddPage();
    $pdf->TituloSeccao((string)$sec++, 'Registo Fotográfico');
    $cw = 92; $ch = 69; $gap = 6;           // caixa de cada foto (mm)
    $col = 0; $y_lin = $pdf->GetY();
    foreach ($fotos as $k => $f) {
        if ($col === 0 && $y_lin + $ch + 6 > 277) { $pdf->AddPage(); $y_lin = $pdf->GetY(); }
        $x0 = 10 + $col * ($cw + $gap);
        $esc = min($cw / $f['w'], $ch / $f['h']);
        $iw = $f['w'] * $esc; $ih = $f['h'] * $esc;
        $pdf->SetDrawColor(220, 220, 220); $pdf->SetLineWidth(0.2);
        $pdf->Rect($x0, $y_lin, $cw, $ch);
        $pdf->Image($f['cam'], $x0 + ($cw - $iw) / 2, $y_lin + ($ch - $ih) / 2, $iw, $ih);
        $pdf->SetFont('Arial', '', 7.5); $pdf->SetTextColor(110, 110, 110);
        $pdf->SetXY($x0, $y_lin + $ch + 0.5); $pdf->Cell($cw, 4, pt('Foto ' . ($k + 1)), 0, 0, 'C');
        $pdf->SetTextColor(0, 0, 0);
        $col++;
        if ($col === 2) { $col = 0; $y_lin += $ch + 7; }
    }
    $pdf->SetY($col === 0 ? $y_lin : $y_lin + $ch + 7);
    $pdf->Ln(2);
}

if ($pdf->GetY() > 220) $pdf->AddPage();

// --- EQUIPAMENTOS UTILIZADOS ---
$pdf->SetFont('Arial', 'B', 9);
$pdf->Cell(0, 5, pt('Equipamentos utilizados nos testes:'), 0, 1, 'L');
$pdf->SetFont('Arial', '', 9);
$pdf->SetTextColor(80, 80, 80);
$pdf->Cell(0, 5, pt('- Osciloscópio Digital UNI-T UTD2052CL'), 0, 1, 'L');
$pdf->Cell(0, 5, pt('- Gerador de Sinais UNI-T UTG1005A'), 0, 1, 'L');
$pdf->Cell(0, 5, pt('- Multímetro Fluke 117'), 0, 1, 'L');
$pdf->SetTextColor(0, 0, 0);
$pdf->Ln(5);

// --- 4. ORÇAMENTO OU INVIABILIDADE (CONDICIONAL) ---
if ($relatorio['reparacao_possivel'] === 'Sim') {

    $pdf->TituloSeccao((string)$sec, 'Orçamento de Reparo / Avaliação');

    $y_orcamento = $pdf->GetY();
    $pdf->SetFont('Arial', 'B', 9);
    $pdf->Cell(95, 5, pt('Peças Necessárias / Avaliação:'), 0, 1, 'L');
    $pdf->SetFont('Arial', '', 9);
    $pdf->MultiCell(95, 5, pt($relatorio['pecas_lista'] ?: 'Sem peças indicadas.'), 0, 'L');

    $pdf->SetXY(110, $y_orcamento);
    $pdf->SetDrawColor(0, 0, 0); // Preto
    $pdf->SetLineWidth(0.4);
    $pdf->Cell(90, 22, '', 1, 0);

    $pdf->SetXY(112, $y_orcamento + 2);
    $pdf->SetFont('Arial', '', 9);
    $pdf->Cell(50, 6, pt('Mão de Obra Técnica:'), 0, 0, 'L');
    $pdf->SetFont('Arial', 'B', 9);
    $pdf->Cell(35, 6, number_format((float)$relatorio['mao_obra'], 2, ',', '.') . ' ' . chr(128), 0, 1, 'R');

    $pdf->SetXY(112, $pdf->GetY());
    $pdf->SetFont('Arial', 'I', 7);
    $pdf->SetTextColor(100, 100, 100);
    $pdf->Cell(85, 4, pt('(Peças descritas à esquerda)'), 0, 1, 'L');

    $pdf->SetXY(112, $pdf->GetY() + 1);
    $pdf->SetDrawColor(200, 200, 200);
    $pdf->SetLineWidth(0.2);
    $pdf->Line(112, $pdf->GetY(), 198, $pdf->GetY());

    $pdf->SetXY(112, $pdf->GetY() + 2);
    $pdf->SetFont('Arial', 'B', 10);
    $pdf->SetTextColor(0, 0, 0); // Preto
    $pdf->Cell(50, 6, pt('Valor Total do Reparo:'), 0, 0, 'L');
    $pdf->Cell(35, 6, number_format((float)$relatorio['total_orcamento'], 2, ',', '.') . ' ' . chr(128), 0, 1, 'R');

    $pdf->SetTextColor(0, 0, 0);
    $pdf->SetY(max($pdf->GetY(), $y_orcamento + 30));

} else {

    // CAIXA DE DANO TOTAL / INVIABILIDADE
    $pdf->TituloSeccao((string)$sec, 'Conclusões', [0, 0, 0]); // Título a Preto

    $y_inv = $pdf->GetY();
    $pdf->SetFont('Arial', '', 9);
    $pdf->SetTextColor(0, 0, 0);

    $justificacao = $relatorio['conclusao_tecnica'] ?: 'O equipamento apresenta dano total, sendo o reparo técnica ou economicamente inviável.';
    $pdf->MultiCell(186, 5, pt($justificacao), 0, 'J');

    $pdf->SetY($pdf->GetY() + 2);

}

// --- 5. NOTA LEGAL E ASSINATURA ---
$pdf->TituloSeccao((string)($sec + 1), 'Parecer Final e Assinatura');
$pdf->SetFont('Arial', '', 9);
$pdf->SetTextColor(80, 80, 80);
$txt_legal = "Declaro para os devidos fins que as informações apresentadas neste relatório são verdadeiras e baseadas exclusivamente na análise técnica e rigorosa realizada ao equipamento no local ou no nosso laboratório.";
$pdf->MultiCell(0, 5, pt($txt_legal), 0, 'J');

// Assinatura: local/data + assinatura + nome ficam sempre juntos na mesma página (bloco de ~52 mm)
if ($pdf->GetY() + 52 > 277) $pdf->AddPage();
$pdf->Ln(20);
$pdf->SetTextColor(0, 0, 0);
$pdf->SetFont('Arial', '', 9);
$pdf->Cell(0, 5, pt(trim($local_obra) . ', ' . $data_vistoria), 0, 1, 'C');

$pdf->Ln(15);
$y_linha = $pdf->GetY();
// Assinatura digitalizada (assina.png) por cima da linha, centrada
if (file_exists('assina.png')) {
    $img_w = 35; $img_h = 13;
    $pdf->Image('assina.png', 105 - $img_w / 2, $y_linha - $img_h + 1, $img_w, $img_h);
}
$pdf->SetDrawColor(0, 0, 0);
$pdf->SetLineWidth(0.2);
$pdf->Line(65, $y_linha, 145, $y_linha);
$pdf->SetY($y_linha);
$pdf->Ln(2);

$pdf->SetFont('Arial', 'B', 9);
$pdf->Cell(0, 5, pt($relatorio['tecnico'] ?: 'Técnico Responsável'), 0, 1, 'C');
$pdf->SetFont('Arial', '', 8);
$pdf->Cell(0, 4, pt('m. Abreu - Instalações Técnicas, Lda.'), 0, 1, 'C');

ob_end_clean();
$filename = 'Relatorio_Analise_' . ($relatorio['numero_chamado'] ?: $id_termo) . '.pdf';
$pdf->Output('I', preg_replace('/[^A-Za-z0-9_.-]/', '_', $filename));
?>
