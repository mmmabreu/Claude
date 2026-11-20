<?php
// REGRA DE OURO: Limpar qualquer espaço em branco ou lixo antes de gerar o PDF
ob_start();
session_start();

if (!isset($_SESSION['user_id'])) {
    ob_end_clean();
    die("Acesso negado. A sua sessão expirou.");
}

$perfil_user = $_SESSION['perfil'] ?? '';
if (!in_array($perfil_user, ['admin', 'administrativo'])) {
    ob_end_clean();
    die("Acesso negado.");
}

require_once 'db.php';
require_once('fpdf/fpdf.php');

// --- BUSCAR DADOS (os mesmos do ecrã: não acertados, pela data do documento) ---
try {
    $registos = $pdo->query("SELECT * FROM jdias_fserv WHERE estado != 'Acertado' ORDER BY (data_registo IS NULL), data_registo ASC, numero ASC")
                    ->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    ob_end_clean();
    die("Erro na BD: " . htmlspecialchars($e->getMessage()));
}

// Função de encoding para FPDF
function pt($texto) {
    return mb_convert_encoding((string)($texto ?? ''), 'windows-1252', 'UTF-8');
}
function eur($v) {
    return number_format((float)$v, 2, ',', '.') . ' €';
}
function dataPT($d) {
    return !empty($d) ? date('d/m/Y', strtotime($d)) : '';
}

// --- CLASSE PDF CUSTOMIZADA (mesmo estilo do módulo LSJR) ---
class PDF extends FPDF {
    public $titulo_doc;
    public $subtitulo_doc;
    public $tabela_atual = null; // [cabecalhos, larguras, alinhamentos]

    function Header() {
        if (file_exists('mab.png')) {
            $this->Image('mab.png', 10, 8, 14);
        }
        $this->SetY(9);
        $this->SetFont('Arial', 'B', 14);
        $this->SetTextColor(44, 62, 80);
        $this->Cell(0, 7, pt($this->titulo_doc), 0, 1, 'R');
        $this->SetFont('Arial', '', 9);
        $this->SetTextColor(100, 100, 100);
        $this->Cell(0, 5, pt($this->subtitulo_doc), 0, 1, 'R');

        $this->SetDrawColor(230, 126, 34); // Laranja do módulo de faturação
        $this->SetLineWidth(0.6);
        $this->Line(10, 25, 287, 25);
        $this->SetY(29);
        $this->CabecalhoTabela();
    }

    function Footer() {
        $this->SetY(-12);
        $this->SetDrawColor(200, 200, 200);
        $this->SetLineWidth(0.2);
        $this->Line(10, $this->GetY(), 287, $this->GetY());
        $this->Ln(1);
        $this->SetFont('Arial', '', 8);
        $this->SetTextColor(100, 100, 100);
        $this->Cell(0, 4, pt('m. Abreu - Instalações Técnicas, Lda. | Emitido em ' . date('d/m/Y H:i')), 0, 0, 'L');
        $this->Cell(0, 4, pt('Página ') . $this->PageNo() . '/{nb}', 0, 0, 'R');
    }

    function Cortar($texto, $largura) {
        $texto = pt($texto);
        if ($this->GetStringWidth($texto) <= $largura - 2) return $texto;
        while ($texto !== '' && $this->GetStringWidth($texto . '...') > $largura - 2) {
            $texto = substr($texto, 0, -1);
        }
        return $texto . '...';
    }

    function Espaco($altura) {
        if ($this->GetY() + $altura > $this->PageBreakTrigger) { $this->AddPage(); }
    }

    function CorValor($v) {
        if ($v < 0) $this->SetTextColor(192, 57, 43);
        else $this->SetTextColor(0, 0, 0);
    }

    function CabecalhoTabela() {
        if (!$this->tabela_atual) return;
        [$cab, $larg, $al] = $this->tabela_atual;
        $this->SetFont('Arial', 'B', 8);
        $this->SetFillColor(241, 242, 246);
        $this->SetTextColor(87, 101, 116);
        $this->SetDrawColor(220, 220, 220);
        $this->SetLineWidth(0.2);
        foreach ($cab as $i => $c) {
            $this->Cell($larg[$i], 7, pt($c), 1, 0, $al[$i], true);
        }
        $this->Ln();
        $this->SetTextColor(0, 0, 0);
    }

    // $celulas: lista de [texto, negrito, valor_numerico_para_cor|null]
    function Linha($celulas, $fundo, $realce = false) {
        if ($this->GetY() + 6 > $this->PageBreakTrigger) { $this->AddPage(); }
        [$cab, $larg, $al] = $this->tabela_atual;
        if ($realce) $this->SetFillColor(254, 249, 231); else $this->SetFillColor(250, 251, 252);
        $this->SetDrawColor(220, 220, 220);
        foreach ($celulas as $i => $c) {
            [$txt, $neg, $num] = $c + [null, false, null];
            $this->SetFont('Arial', $neg ? 'B' : '', 8);
            if ($num !== null) $this->CorValor($num); else $this->SetTextColor(0, 0, 0);
            $this->Cell($larg[$i], 6, $this->Cortar($txt, $larg[$i]), 1, 0, $al[$i], $fundo || $realce);
        }
        $this->Ln();
        $this->SetTextColor(0, 0, 0);
    }

    function Subtotal($rotulo, $valores) {
        $this->Espaco(8);
        [$cab, $larg] = $this->tabela_atual;
        $primeira = min(array_keys($valores));
        $this->SetFont('Arial', 'B', 9);
        $this->SetFillColor(241, 242, 246);
        $this->SetTextColor(0, 0, 0);
        $this->Cell(array_sum(array_slice($larg, 0, $primeira)), 7, pt($rotulo), 1, 0, 'R', true);
        for ($i = $primeira; $i < count($larg); $i++) {
            if (array_key_exists($i, $valores)) {
                $this->CorValor($valores[$i]);
                $this->Cell($larg[$i], 7, pt(eur($valores[$i])), 1, 0, 'R', true);
                $this->SetTextColor(0, 0, 0);
            } else {
                $this->Cell($larg[$i], 7, '', 1, 0, 'L', true);
            }
        }
        $this->Ln();
    }

    // Quadro "Por tipo" (esquerda) + "Resumo com/sem IVA" (direita)
    function Resumos($por_tipo, $linhas_resumo, $taxa_iva = 0.23, $titulo_tipo = ' POR TIPO') {
        $this->tabela_atual = null; // as páginas seguintes não repetem o cabeçalho da tabela
        $altura = 7 + 7 * max(count($por_tipo) + 1, count($linhas_resumo) + 1) + 8;
        $this->Espaco(5 + $altura);
        $this->Ln(5);
        $y = $this->GetY();
        $this->SetDrawColor(220, 220, 220);
        $this->SetLineWidth(0.2);

        if (!empty($por_tipo)) {
            $lt = [40, 14, 34];
            $this->SetXY(10, $y);
            $this->SetFont('Arial', 'B', 9);
            $this->SetFillColor(241, 242, 246);
            $this->Cell($lt[0], 7, pt($titulo_tipo), 1, 0, 'L', true);
            $this->Cell($lt[1], 7, pt('Qtd.'), 1, 0, 'C', true);
            $this->Cell($lt[2], 7, pt('Valor'), 1, 1, 'R', true);
            $this->SetFont('Arial', '', 9);
            foreach ($por_tipo as $tipo => $v) {
                $this->SetX(10);
                $this->SetTextColor(0, 0, 0);
                $this->Cell($lt[0], 7, pt(' ' . $tipo), 1, 0, 'L');
                $this->Cell($lt[1], 7, $v['n'], 1, 0, 'C');
                $this->CorValor($v['valor']);
                $this->Cell($lt[2], 7, pt(eur($v['valor']) . ' '), 1, 1, 'R');
            }
            $this->SetTextColor(0, 0, 0);
        }

        $sem_iva = fn($v) => round($v / (1 + $taxa_iva), 2);
        $lr = [60, 30, 30, 30];
        $larg_resumo = array_sum($lr);
        $x = 287 - $larg_resumo;
        $this->SetXY($x, $y);
        $this->SetFont('Arial', 'B', 9);
        $this->SetFillColor(241, 242, 246);
        $this->Cell($lr[0], 7, pt(' RESUMO'), 1, 0, 'L', true);
        $this->Cell($lr[1], 7, pt('Sem IVA'), 1, 0, 'R', true);
        $this->Cell($lr[2], 7, pt('IVA (' . round($taxa_iva * 100) . '%)'), 1, 0, 'R', true);
        $this->Cell($lr[3], 7, pt('Com IVA'), 1, 1, 'R', true);
        foreach ($linhas_resumo as [$desc, $com, $destaque]) {
            $sem = $sem_iva($com);
            $iva = round($com - $sem, 2);
            $this->SetX($x);
            $this->SetFont('Arial', $destaque ? 'B' : '', $destaque ? 10 : 9);
            $this->SetTextColor(0, 0, 0);
            $this->SetFillColor(253, 242, 233);
            $this->Cell($lr[0], 7, pt(' ' . $desc), 1, 0, 'L', $destaque);
            if ($com < 0) $this->SetTextColor(192, 57, 43); else $this->SetTextColor(44, 62, 80);
            $this->Cell($lr[1], 7, pt(eur($sem) . ' '), 1, 0, 'R', $destaque);
            $this->Cell($lr[2], 7, pt(eur($iva) . ' '), 1, 0, 'R', $destaque);
            $this->Cell($lr[3], 7, pt(eur($com) . ' '), 1, 1, 'R', $destaque);
        }
        $this->SetX($x);
        $this->SetFont('Arial', 'I', 8);
        $this->SetTextColor(120, 120, 120);
        $this->Cell($larg_resumo, 5, pt('Os valores incluem IVA a ' . round($taxa_iva * 100) . '%. Sem IVA = Com IVA / ' . number_format(1 + $taxa_iva, 2, ',', '') . '.'), 0, 1, 'L');
        $this->SetTextColor(0, 0, 0);
    }
}

// --- TOTAIS ---
$total_valor = 0; $total_corrigido = 0; $total_ativo = 0;
$n_por_corrigir = 0;
foreach ($registos as $r) {
    $total_valor += (float)$r['valor'];
    $total_corrigido += (float)$r['valor_corrigido'];
    if ($r['estado'] === 'Ativo') {
        $total_ativo += (float)$r['valor_corrigido'];
        if (($r['valor_certo'] ?? 'Nao') !== 'Sim') $n_por_corrigir++;
    }
}

// --- GERAR PDF ---
$pdf = new PDF('L', 'mm', 'A4');
$pdf->titulo_doc = 'J. DIAS - FOLHAS DE SERVIÇO (FSERV)';
$pdf->subtitulo_doc = count($registos) . ' registo(s) por acertar' . ($n_por_corrigir > 0 ? ' | ' . $n_por_corrigir . ' ativa(s) por corrigir' : '');
$pdf->tabela_atual = [
    ['Número', 'Secção', 'Data', 'PDCT', 'Valor', 'V. Corrigido', 'V. Certo', 'FSERV Ant.', 'Estado', 'Observações'],
    [24, 14, 23, 20, 28, 28, 17, 21, 18, 84],
    ['C', 'C', 'C', 'C', 'R', 'R', 'C', 'C', 'C', 'L'],
];
$pdf->SetMargins(10, 10, 10);
$pdf->AliasNbPages();
$pdf->SetAutoPageBreak(true, 15);
$pdf->AddPage();

if (empty($registos)) {
    $pdf->SetFont('Arial', 'I', 10);
    $pdf->Cell(0, 10, pt('Não existem Folhas de Serviço por acertar.'), 0, 1, 'C');
} else {
    $fundo = false;
    foreach ($registos as $r) {
        $vc = $r['valor_corrigido'] !== null ? (float)$r['valor_corrigido'] : null;
        $certo = ($r['valor_certo'] ?? 'Nao') === 'Sim';
        $pdf->Linha([
            [$r['numero'], true],
            [$r['seccao']],
            [dataPT($r['data_registo'])],
            [$r['pdct'] ?: '-'],
            [eur($r['valor']), false, (float)$r['valor']],
            [$vc !== null ? eur($vc) : '-', true, $vc ?? 0],
            [$certo ? 'Sim' : 'Não', !$certo, $certo ? 0 : -1],
            [$r['fserv_anterior'] ?: '-'],
            [$r['estado']],
            [$r['observacoes'] ?? ''],
        ], $fundo);
        $fundo = !$fundo;
    }
    $pdf->Subtotal('TOTAIS', [4 => $total_valor, 5 => $total_corrigido]);

    $pdf->Resumos([], [
        ['Total V. Corrigido (todas)', $total_corrigido, false],
        ['Entra no acerto (só Ativas)', $total_ativo, true],
    ]);
}

ob_end_clean();
$pdf->Output('I', 'JDias_FSERV_' . date('Ymd') . '.pdf');
?>
