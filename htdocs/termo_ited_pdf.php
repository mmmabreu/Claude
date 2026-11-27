<?php
// =====================================================================
// TERMO ITED — PDF
//   termo_ited_pdf.php?id=N&doc=termo     → Termo de responsabilidade de execução da instalação ITED (A4 vertical)
//   termo_ited_pdf.php?id=N&doc=relatorio → Relatório de ensaios / certificação dos pontos RJ45 (A4 horizontal)
// =====================================================================
// REGRA DE OURO 4: limpar o buffer para que avisos ou espaços não estraguem o PDF
ob_start();
session_start();
require_once 'db.php';
require_once 'termos_ited_comum.php';
require_once('fpdf/fpdf.php');

// REGRA DE OURO 1: Validação de sessão
if (!isset($_SESSION['user_id'])) { ob_end_clean(); die("Acesso negado."); }
// REGRA DE OURO 2: Acesso restrito pela matriz de permissões
if (!itedTemAcesso($pdo)) { ob_end_clean(); die("Acesso negado."); }
// REGRA DE OURO 5: ID validado como número inteiro
$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$doc = ($_GET['doc'] ?? 'termo') === 'relatorio' ? 'relatorio' : 'termo';
try {
    itedCriarTabelas($pdo);
    $t = $id ? itedTermo($pdo, $id) : null;
    $pontos = $t ? itedPontos($pdo, $id) : [];
} catch (Exception $e) {
    error_log('termo_ited_pdf.php: ' . $e->getMessage());
    $t = null;
}
if (!$t) { ob_end_clean(); die("Termo ITED não encontrado."); }

function pt($s) { return mb_convert_encoding((string)($s ?? ''), 'windows-1252', 'UTF-8'); }
function dataPT($d) { return $d ? date('d/m/Y', strtotime($d)) : '-'; }
function dataExtenso($d) {
    $m = [1 => 'janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho', 'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro'];
    $ts = strtotime($d ?: date('Y-m-d'));
    return date('j', $ts) . ' de ' . $m[(int)date('n', $ts)] . ' de ' . date('Y', $ts);
}
$ref = $t['referencia_obra'] ?: ('ITED-' . $t['id']);
$edificio = $t['edificio'] ?: ($t['obra_nome'] ?? '');
$morada = trim($t['morada'] . ($t['localidade'] ? ', ' . $t['localidade'] : '')) ?: (string)($t['obra_morada'] ?? '');

class PDF extends FPDF {
    public $titulo = ''; public $subtitulo = ''; public $ref = ''; public $largura = 190;
    function Header() {
        if (file_exists('mab.png')) $this->Image('mab.png', 10, 9, 16);
        $this->SetY(10);
        $this->SetFont('Arial', 'B', 14); $this->SetTextColor(31, 97, 141);
        $this->Cell(0, 7, pt($this->titulo), 0, 1, 'R');
        $this->SetFont('Arial', '', 9); $this->SetTextColor(100, 100, 100);
        $this->Cell(0, 5, pt($this->subtitulo), 0, 1, 'R');
        $this->SetDrawColor(31, 97, 141); $this->SetLineWidth(0.6);
        $y = max($this->GetY() + 3, 28);
        $this->Line(10, $y, 10 + $this->largura, $y);
        $this->SetY($y + 4); $this->SetTextColor(0, 0, 0); $this->SetLineWidth(0.2);
    }
    function Footer() {
        $this->SetY(-14); $this->SetDrawColor(200, 200, 200);
        $this->Line(10, $this->GetY(), 10 + $this->largura, $this->GetY()); $this->Ln(1.5);
        $this->SetFont('Arial', 'B', 7.5); $this->SetTextColor(100, 100, 100);
        $y = $this->GetY();
        $this->Cell(0, 4, pt('m. Abreu - Instalações Técnicas, Lda. | NIF: 514 414 120'), 0, 0, 'C');
        $this->SetXY(10, $y); $this->Cell($this->largura, 4, pt('Página ') . $this->PageNo() . '/{nb}', 0, 1, 'R');
        $this->SetFont('Arial', '', 7.5);
        $this->Cell(0, 4, pt('Rua Damião de Góis, 205, Braga | geral@mabreu.pt | ' . $this->ref), 0, 1, 'C');
    }
    function Caixa($titulo) {
        $this->Ln(2); $this->SetFont('Arial', 'B', 9); $this->SetFillColor(234, 242, 251); $this->SetDrawColor(200, 200, 200);
        $this->Cell($this->largura, 7, pt(' ' . $titulo), 1, 1, 'L', true); $this->SetFont('Arial', '', 9);
    }
    // Linha de pares rótulo / valor (larguras proporcionais)
    function Pares(array $pares) {
        $n = count($pares); $wl = 34; $wv = ($this->largura - $wl * $n) / $n;
        foreach ($pares as $i => [$l, $v]) {
            $this->SetFont('Arial', 'B', 8.5); $this->Cell($wl, 6.5, pt($l), $i === 0 ? 'LB' : 'LB', 0);
            $this->SetFont('Arial', '', 8.5);
            $txt = pt($v === '' || $v === null ? '-' : $v);
            while ($this->GetStringWidth($txt) > $wv - 2 && strlen($txt) > 3) $txt = substr($txt, 0, -4) . '...';
            $this->Cell($wv, 6.5, $txt, $i === $n - 1 ? 'RB' : 'B', $i === $n - 1 ? 1 : 0);
        }
    }
    function NbLines($w, $txt) {
        $cw = &$this->CurrentFont['cw']; $wmax = ($w - 2 * $this->cMargin) * 1000 / $this->FontSize;
        $s = str_replace("\r", '', (string)$txt); $nb = strlen($s); $sep = -1; $i = 0; $j = 0; $l = 0; $nl = 1;
        while ($i < $nb) { $c = $s[$i]; if ($c == "\n") { $i++; $sep = -1; $j = $i; $l = 0; $nl++; continue; } if ($c == ' ') $sep = $i;
            $l += $cw[$c] ?? 500; if ($l > $wmax) { if ($sep == -1) { if ($i == $j) $i++; } else $i = $sep + 1; $sep = -1; $j = $i; $l = 0; $nl++; } else $i++; }
        return $nl;
    }
    function Assinatura($nome, $inscricao, $com_imagem, $x_centro) {
        $y = $this->GetY() + 16; $this->SetDrawColor(0, 0, 0);
        $this->Line($x_centro - 40, $y, $x_centro + 40, $y);
        if ($com_imagem && file_exists('assina.png')) $this->Image('assina.png', $x_centro - 20, $y - 14, 40, 15);
        $this->SetXY($x_centro - 50, $y + 1.5); $this->SetFont('Arial', 'B', 9); $this->Cell(100, 5, pt($nome), 0, 2, 'C');
        $this->SetFont('Arial', '', 8); if ($inscricao !== '') $this->Cell(100, 4, pt('Técnico ITED · Inscrição ANACOM n.º ' . $inscricao), 0, 2, 'C');
        else $this->Cell(100, 4, pt('Técnico ITED'), 0, 2, 'C');
    }
}

if ($doc === 'termo') {
    // =================== TERMO DE RESPONSABILIDADE ===================
    $pdf = new PDF('P', 'mm', 'A4');
    $pdf->titulo = 'TERMO DE RESPONSABILIDADE'; $pdf->subtitulo = 'Execução da instalação ITED · ' . $ref; $pdf->ref = 'Termo ITED ' . $ref;
    $pdf->SetMargins(10, 10, 10); $pdf->AliasNbPages(); $pdf->SetAutoPageBreak(true, 20); $pdf->AddPage();

    $pdf->Caixa('IDENTIFICAÇÃO');
    $pdf->Pares([['Dono de obra:', (string)$t['cliente_nome']], ['NIF:', (string)$t['cliente_nif']]]);
    $pdf->Pares([['Edifício:', $edificio], ['Tipo:', $t['tipo_edificio'] . ($t['n_fogos'] !== null && $t['n_fogos'] !== '' ? ' · ' . $t['n_fogos'] . ' fogo(s)/fração(ões)' : '')]]);
    $pdf->Pares([['Morada:', $morada]]);
    $pdf->Pares([['Projetista ITED:', $t['projetista_nome']], ['Inscrição / data:', trim($t['projetista_inscricao'] . ($t['projeto_data'] ? ' · ' . dataPT($t['projeto_data']) : ''))]]);
    $pdf->Pares([['Técnico ITED:', $t['tecnico_nome']], ['Inscrição ANACOM:', $t['tecnico_inscricao']]]);
    $pdf->Ln(8);

    $texto = trim((string)$t['texto_termo']) !== '' ? $t['texto_termo'] : itedTextoPadrao($t);
    $pdf->SetFont('Arial', '', 11);
    foreach (preg_split("/\n\s*\n/", str_replace("\r", '', $texto)) as $par) {
        $pdf->MultiCell(0, 6.5, pt(trim($par)), 0, 'J');
        $pdf->Ln(3);
    }
    $pdf->Ln(6);
    $pdf->Cell(0, 6, pt('Braga, ' . dataExtenso($t['data_emissao'])), 0, 1, 'L');
    $pdf->Ln(6);
    $pdf->SetFont('Arial', 'B', 10); $pdf->Cell(0, 6, pt('O Técnico ITED'), 0, 1, 'C');
    if ($pdf->GetY() > 245) $pdf->AddPage();
    $pdf->Assinatura($t['tecnico_nome'], (string)$t['tecnico_inscricao'], !empty($t['assinatura']), 105);
    $nome_ficheiro = 'Termo_ITED_' . preg_replace('/[^A-Za-z0-9_-]/', '_', $ref) . '.pdf';
} else {
    // =================== RELATÓRIO DE ENSAIOS DOS PONTOS RJ45 ===================
    $pdf = new PDF('L', 'mm', 'A4');
    $pdf->largura = 277;
    $pdf->titulo = 'RELATÓRIO DE ENSAIOS · PONTOS RJ45'; $pdf->subtitulo = 'Certificação da cablagem de pares de cobre · ' . $ref; $pdf->ref = 'Relatório de ensaios ITED ' . $ref;
    $pdf->SetMargins(10, 10, 10); $pdf->AliasNbPages(); $pdf->SetAutoPageBreak(false); $pdf->AddPage();

    $pdf->Caixa('OBRA');
    $pdf->Pares([['Dono de obra:', (string)$t['cliente_nome']], ['Edifício:', $edificio], ['Morada:', $morada]]);
    $pdf->Caixa('ENSAIO');
    $pdf->Pares([['Equipamento:', $t['equip_teste']], ['N.º de série:', $t['equip_serie']], ['Calibração:', dataPT($t['equip_calibracao'])]]);
    $pdf->Pares([['Data dos ensaios:', dataPT($t['data_ensaios'] ?: $t['data_emissao'])], ['Técnico:', $t['tecnico_nome']], ['Inscrição ANACOM:', $t['tecnico_inscricao']]]);
    $pdf->Pares([['Norma / limite:', $t['norma_teste']]]);

    [$n, $ok, $falha, $cmax, $cmed] = itedResumo($pontos);
    $pdf->Ln(3);
    $pdf->SetFont('Arial', 'B', 9.5);
    $pdf->Cell(0, 6, pt($n . ' ponto(s) ensaiado(s) · ' . $ok . ' passa(m) · ' . $falha . ' falha(m)'
        . ($cmax !== null ? ' · comprimento máximo ' . number_format($cmax, 1, ',', '') . ' m · médio ' . number_format($cmed, 1, ',', '') . ' m' : '')), 0, 1, 'L');
    $pdf->Ln(1);

    // Nº | Identificação | Localização | Painel/porta | Categoria | Configuração | Compr. | Mapa | Margem NEXT | Resultado | Observações  (= 277 mm)
    $w = [9, 26, 46, 26, 18, 30, 18, 14, 26, 20, 44];
    $tit = ['Nº', 'Identificação', 'Localização', 'Painel / porta', 'Categoria', 'Configuração', 'Compr. (m)', 'Mapa', 'Margem NEXT (dB)', 'Resultado', 'Observações'];
    $cab = function () use ($pdf, $w, $tit) {
        $pdf->SetFont('Arial', 'B', 7.5); $pdf->SetFillColor(241, 242, 246); $pdf->SetDrawColor(200, 200, 200);
        foreach ($tit as $i => $tt) $pdf->Cell($w[$i], 7, pt($tt), 1, 0, 'C', true);
        $pdf->Ln();
    };
    $cab();
    $lh = 4.5;
    foreach ($pontos as $i => $p) {
        $pdf->SetFont('Arial', '', 8);
        $vals = [(string)($i + 1), $p['identificacao'], $p['localizacao'], $p['painel_porta'], $p['categoria'], $p['configuracao'],
                 $p['comprimento'] !== null ? number_format((float)$p['comprimento'], 1, ',', '') : '-', $p['mapa'],
                 $p['margem_next'] !== null ? number_format((float)$p['margem_next'], 1, ',', '') : '-', $p['resultado'], $p['observacoes']];
        $nl = 1;
        foreach ([1, 2, 3, 10] as $c) $nl = max($nl, $pdf->NbLines($w[$c], pt($vals[$c])));
        $h = $nl * $lh + 2;
        if ($pdf->GetY() + $h > 188) { $pdf->AddPage(); $cab(); $pdf->SetFont('Arial', '', 8); }
        $x = 10; $y = $pdf->GetY();
        $falhou = ($p['resultado'] !== 'PASSA' || $p['mapa'] !== 'OK');
        if ($falhou) { $pdf->SetFillColor(253, 237, 237); $pdf->Rect($x, $y, array_sum($w), $h, 'F'); }
        foreach ($vals as $c => $v) {
            $pdf->Rect($x, $y, $w[$c], $h);
            $pdf->SetXY($x, $y + 1);
            if ($c === 9 || $c === 7) { $pdf->SetFont('Arial', 'B', 8); $bom = ($c === 9 ? $v === 'PASSA' : $v === 'OK'); $pdf->SetTextColor($bom ? 30 : 192, $bom ? 132 : 57, $bom ? 73 : 43); }
            $pdf->MultiCell($w[$c], $lh, pt($v), 0, in_array($c, [1, 2, 3, 10]) ? 'L' : 'C');
            $pdf->SetTextColor(0, 0, 0); $pdf->SetFont('Arial', '', 8);
            $x += $w[$c];
        }
        $pdf->SetXY(10, $y + $h);
    }
    if (!$pontos) { $pdf->SetFont('Arial', 'I', 9); $pdf->Cell(array_sum($w), 8, pt('Sem pontos registados.'), 1, 1, 'C'); }

    // Conclusão, observações e assinatura
    if ($pdf->GetY() + 55 > 195) $pdf->AddPage();
    $pdf->Ln(4);
    $pdf->SetFont('Arial', 'B', 9.5);
    $conclusao = $n === 0 ? 'Não foram registados ensaios.'
        : ($falha === 0 ? 'Conclusão: todos os ' . $n . ' pontos ensaiados cumprem os requisitos do limite de ensaio indicado.'
                        : 'Conclusão: ' . $falha . ' de ' . $n . ' ponto(s) não cumprem o limite de ensaio indicado (assinalados a vermelho).');
    $pdf->MultiCell(0, 5.5, pt($conclusao), 0, 'L');
    if (trim((string)$t['observacoes']) !== '') { $pdf->SetFont('Arial', '', 9); $pdf->MultiCell(0, 5, pt('Observações: ' . $t['observacoes']), 0, 'L'); }
    $pdf->Ln(2);
    $pdf->SetFont('Arial', '', 9); $pdf->Cell(0, 5, pt('Braga, ' . dataExtenso($t['data_ensaios'] ?: $t['data_emissao'])), 0, 1, 'L');
    $pdf->Assinatura($t['tecnico_nome'], (string)$t['tecnico_inscricao'], !empty($t['assinatura']), 148);
    $nome_ficheiro = 'Relatorio_Ensaios_RJ45_' . preg_replace('/[^A-Za-z0-9_-]/', '_', $ref) . '.pdf';
}

ob_end_clean();
$pdf->Output('I', $nome_ficheiro);
