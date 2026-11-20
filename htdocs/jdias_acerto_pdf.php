<?php
// REGRA DE OURO: Limpar qualquer espaço em branco ou lixo antes de gerar o PDF
ob_start();
session_start();

if (!isset($_SESSION['user_id'])) {
    ob_end_clean();
    die("Acesso negado.");
}

$perfil_user = $_SESSION['perfil'] ?? '';
if (!in_array($perfil_user, ['admin', 'administrativo'])) {
    ob_end_clean();
    die("Acesso negado.");
}

require_once 'db.php';
require_once('fpdf/fpdf.php');

$id_acerto = (int)($_GET['id'] ?? 0);
if ($id_acerto <= 0) {
    ob_end_clean();
    die("Acerto inválido.");
}

function tabelaExiste($pdo, $tabela) {
    try {
        return (bool)$pdo->query("SHOW TABLES LIKE " . $pdo->quote($tabela))->fetchColumn();
    } catch (Exception $e) {
        return false;
    }
}

// --- BUSCAR O ACERTO E OS SEUS DOCUMENTOS ---
try {
    $st = $pdo->prepare("SELECT * FROM jdias_acertos WHERE numero = ?");
    $st->execute([$id_acerto]);
    $acerto = $st->fetch(PDO::FETCH_ASSOC);
    if (!$acerto) {
        ob_end_clean();
        die("Acerto não encontrado.");
    }

    $docs_fserv = $docs_fat = $docs_fatfav = [];
    if (tabelaExiste($pdo, 'jdias_fserv')) {
        $st = $pdo->prepare("SELECT * FROM jdias_fserv WHERE id_acerto = ? AND estado = 'Acertado' ORDER BY (data_registo IS NULL), data_registo ASC, numero ASC");
        $st->execute([$id_acerto]);
        $docs_fserv = $st->fetchAll(PDO::FETCH_ASSOC);
    }
    if (tabelaExiste($pdo, 'jdias_fat')) {
        $st = $pdo->prepare("SELECT * FROM jdias_fat WHERE id_acerto = ? AND estado = 'Acertado' ORDER BY (data_registo IS NULL), data_registo ASC, numero ASC");
        $st->execute([$id_acerto]);
        $docs_fat = $st->fetchAll(PDO::FETCH_ASSOC);
    }
    if (tabelaExiste($pdo, 'jdias_fatfav')) {
        $st = $pdo->prepare("SELECT * FROM jdias_fatfav WHERE id_acerto = ? AND estado = 'Acertado' ORDER BY data_registo ASC, numero ASC");
        $st->execute([$id_acerto]);
        $docs_fatfav = $st->fetchAll(PDO::FETCH_ASSOC);
    }

    // Saldo de abertura gerado por este acerto
    $abertura = null;
    if (!empty($acerto['abertura_gerada_num']) && tabelaExiste($pdo, 'jdias_fatfav')) {
        $st = $pdo->prepare("SELECT * FROM jdias_fatfav WHERE numero = ?");
        $st->execute([$acerto['abertura_gerada_num']]);
        $abertura = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }
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

// --- CLASSE PDF CUSTOMIZADA ---
class PDF extends FPDF {
    public $titulo_doc;
    public $subtitulo_doc;
    public $tabela_atual = null; // [cabecalhos, larguras, alinhamentos] para repetir após quebra de página

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
        $this->SetTextColor(0, 0, 0);
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

    // Corta o texto para caber na largura da célula (acrescenta "...")
    function Cortar($texto, $largura) {
        $texto = pt($texto);
        if ($this->GetStringWidth($texto) <= $largura - 2) return $texto;
        while ($texto !== '' && $this->GetStringWidth($texto . '...') > $largura - 2) {
            $texto = substr($texto, 0, -1);
        }
        return $texto . '...';
    }

    // Garante espaço na página (o PageBreakTrigger do FPDF é protegido)
    function Espaco($altura) {
        if ($this->GetY() + $altura > $this->PageBreakTrigger) { $this->AddPage(); }
    }

    function CorValor($v) {
        if ($v < 0) $this->SetTextColor(192, 57, 43);
        else $this->SetTextColor(0, 0, 0);
    }

    function TituloSeccao($texto, $cor, $info = '') {
        $this->Espaco(22);
        $this->SetFont('Arial', 'B', 11);
        $this->SetTextColor($cor[0], $cor[1], $cor[2]);
        $this->Cell(0, 7, pt($texto), 0, 0, 'L');
        if ($info !== '') {
            $this->SetX(10);
            $this->SetFont('Arial', 'I', 8);
            $this->SetTextColor(120, 120, 120);
            $this->Cell(0, 7, pt($info), 0, 0, 'R');
        }
        $this->Ln(7);
        $this->SetDrawColor($cor[0], $cor[1], $cor[2]);
        $this->SetLineWidth(0.4);
        $this->Line(10, $this->GetY(), 287, $this->GetY());
        $this->Ln(1.5);
        $this->SetTextColor(0, 0, 0);
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
        if ($this->GetY() + 6 > $this->PageBreakTrigger) {
            $this->AddPage();
            $this->CabecalhoTabela();
        }
        [$cab, $larg, $al] = $this->tabela_atual;
        if ($realce) $this->SetFillColor(254, 249, 231); else $this->SetFillColor(250, 251, 252);
        foreach ($celulas as $i => $c) {
            [$txt, $neg, $num] = $c + [null, false, null];
            $this->SetFont('Arial', $neg ? 'B' : '', 8);
            if ($num !== null) $this->CorValor($num); else $this->SetTextColor(0, 0, 0);
            $this->Cell($larg[$i], 6, $this->Cortar($txt, $larg[$i]), 1, 0, $al[$i], $fundo || $realce);
        }
        $this->Ln();
        $this->SetTextColor(0, 0, 0);
    }

    // Linha de subtotal: rótulo ocupa as colunas até $col_valor; valores nas colunas indicadas
    function Subtotal($rotulo, $valores) {
        $this->Espaco(7);
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

    function SemDados($texto) {
        $this->SetFont('Arial', 'I', 9);
        $this->SetTextColor(120, 120, 120);
        $this->Cell(0, 7, pt($texto), 0, 1, 'L');
        $this->SetTextColor(0, 0, 0);
    }
}

// --- TOTAIS (somas dos documentos; o resumo usa os valores gravados no momento do acerto) ---
$soma_fserv = 0; $soma_fserv_base = 0;
foreach ($docs_fserv as $r) { $soma_fserv += (float)$r['valor_corrigido']; $soma_fserv_base += (float)$r['valor']; }
$soma_fat = 0;    foreach ($docs_fat as $r)    $soma_fat    += (float)$r['valor'];
$soma_fatfav = 0; foreach ($docs_fatfav as $r) $soma_fatfav += (float)$r['valor'];

$t_fserv  = (float)$acerto['valor_fserv'];
$t_fat    = (float)$acerto['valor_fat'];
$t_fatfav = (float)$acerto['valor_fatfav'];
$t_total  = (float)$acerto['valor'];

// --- GERAR PDF ---
$pdf = new PDF('L', 'mm', 'A4');
$pdf->titulo_doc = 'J. DIAS - ACERTO Nº ' . (int)$acerto['numero'];
$pdf->subtitulo_doc = 'Data do acerto: ' . dataPT($acerto['data_registo'])
    . (!empty($acerto['criado_por']) ? ' | Efetuado por: ' . $acerto['criado_por'] : '')
    . (!empty($acerto['criado_em']) ? ' em ' . date('d/m/Y H:i', strtotime($acerto['criado_em'])) : '');
$pdf->SetMargins(10, 10, 10);
$pdf->AliasNbPages();
$pdf->SetAutoPageBreak(true, 15);
$pdf->AddPage();

// ===== 1. FOLHAS DE SERVIÇO =====
$pdf->TituloSeccao('FOLHAS DE SERVIÇO (FSERV)', [230, 126, 34], count($docs_fserv) . ' registo(s) | conta o V. Corrigido');
if (empty($docs_fserv)) {
    $pdf->SemDados('Sem Folhas de Serviço neste acerto.');
} else {
    $pdf->tabela_atual = [
        ['Número', 'Secção', 'Data', 'PDCT', 'Valor', 'V. Corrigido', 'V. Certo', 'FSERV Ant.', 'Observações'],
        [25, 15, 24, 22, 30, 30, 18, 22, 91],
        ['C', 'C', 'C', 'C', 'R', 'R', 'C', 'C', 'L'],
    ];
    $pdf->CabecalhoTabela();
    $fundo = false;
    foreach ($docs_fserv as $r) {
        $vc = $r['valor_corrigido'] !== null ? (float)$r['valor_corrigido'] : null;
        $pdf->Linha([
            [$r['numero'], true],
            [$r['seccao']],
            [dataPT($r['data_registo'])],
            [$r['pdct'] ?: '-'],
            [eur($r['valor']), false, (float)$r['valor']],
            [$vc !== null ? eur($vc) : '-', true, $vc ?? 0],
            [($r['valor_certo'] ?? 'Nao') === 'Sim' ? 'Sim' : 'Não'],
            [$r['fserv_anterior'] ?: '-'],
            [$r['observacoes'] ?? ''],
        ], $fundo);
        $fundo = !$fundo;
    }
    $pdf->Subtotal('TOTAL FSERV', [4 => $soma_fserv_base, 5 => $soma_fserv]);
}
$pdf->Ln(4);

// ===== 2. FATURAS =====
$pdf->TituloSeccao('FATURAS (FAT / NCRE)', [41, 128, 185], count($docs_fat) . ' documento(s)');
if (empty($docs_fat)) {
    $pdf->SemDados('Sem Faturas neste acerto.');
} else {
    $pdf->tabela_atual = [
        ['Número', 'Secção', 'Data', 'Tipo', 'Valor', 'Observações'],
        [30, 18, 26, 22, 32, 149],
        ['C', 'C', 'C', 'C', 'R', 'L'],
    ];
    $pdf->CabecalhoTabela();
    $fundo = false;
    foreach ($docs_fat as $r) {
        $pdf->Linha([
            [$r['numero'], true],
            [$r['seccao']],
            [dataPT($r['data_registo'])],
            [$r['tipo'] ?? 'FAT', true, ($r['tipo'] ?? 'FAT') === 'NCRE' ? -1 : 0],
            [eur($r['valor']), false, (float)$r['valor']],
            [$r['observacoes'] ?? ''],
        ], $fundo);
        $fundo = !$fundo;
    }
    $pdf->Subtotal('TOTAL FATURAS', [4 => $soma_fat]);
}
$pdf->Ln(4);

// ===== 3. ABERTURA + FATURAS CLIENTE =====
$pdf->TituloSeccao('ABERTURA + FATURAS CLIENTE', [142, 68, 173], count($docs_fatfav) . ' registo(s)');
if (empty($docs_fatfav)) {
    $pdf->SemDados('Sem registos de Abertura / Faturas Cliente neste acerto.');
} else {
    $pdf->tabela_atual = [
        ['Número', 'Tipo', 'Data', 'Nome Cliente', 'Valor', 'Observações'],
        [25, 25, 26, 65, 32, 104],
        ['C', 'C', 'C', 'L', 'R', 'L'],
    ];
    $pdf->CabecalhoTabela();
    $fundo = false;
    foreach ($docs_fatfav as $r) {
        $pdf->Linha([
            [$r['numero'], true],
            [$r['tipo'], true],
            [dataPT($r['data_registo'])],
            [$r['nome_cliente'] ?? ''],
            [eur($r['valor']), false, (float)$r['valor']],
            [$r['observacoes'] ?? ''],
        ], $fundo, $r['tipo'] === 'Abertura');
        $fundo = !$fundo;
    }
    $pdf->Subtotal('TOTAL ABERTURA + FATURAS CLIENTE', [4 => $soma_fatfav]);
}
$pdf->Ln(4);

// ===== 4. RESUMO DO ACERTO =====
$taxa_iva = 0.23; // Os valores dos documentos já incluem IVA
$sem_iva = fn($v) => round($v / (1 + $taxa_iva), 2);

$pdf->Espaco(66); // resumo + assinaturas na mesma página
$pdf->TituloSeccao('RESUMO DO ACERTO', [230, 126, 34]);
$y_resumo = $pdf->GetY();

$lr = [70, 30, 30, 30];
$larg_resumo = array_sum($lr);
$xResumo = 287 - $larg_resumo;
$pdf->SetXY($xResumo, $y_resumo);
$pdf->SetDrawColor(220, 220, 220);
$pdf->SetLineWidth(0.2);
$pdf->SetFont('Arial', 'B', 9);
$pdf->SetFillColor(241, 242, 246);
$pdf->Cell($lr[0], 7, pt(' RESUMO'), 1, 0, 'L', true);
$pdf->Cell($lr[1], 7, pt('Sem IVA'), 1, 0, 'R', true);
$pdf->Cell($lr[2], 7, pt('IVA (' . round($taxa_iva * 100) . '%)'), 1, 0, 'R', true);
$pdf->Cell($lr[3], 7, pt('Com IVA'), 1, 1, 'R', true);

$n = fn($k) => !empty($acerto[$k]) ? ' (' . (int)$acerto[$k] . ')' : '';
$linhas = [
    ['Folhas de Serviço' . $n('n_fserv'), $t_fserv, false],
    ['Faturas' . $n('n_fat'), $t_fat, false],
    ['Abertura + Faturas Cliente' . $n('n_fatfav'), $t_fatfav, false],
    ['TOTAL ACERTADO', $t_total, true],
];
foreach ($linhas as [$desc, $com, $destaque]) {
    $sem = $sem_iva($com);
    $iva = round($com - $sem, 2);
    $pdf->SetX($xResumo);
    $pdf->SetFont('Arial', $destaque ? 'B' : '', $destaque ? 10 : 9);
    $pdf->SetTextColor(0, 0, 0);
    $pdf->SetFillColor(253, 242, 233);
    $pdf->Cell($lr[0], 7, pt(' ' . $desc), 1, 0, 'L', $destaque);
    if ($com < 0) $pdf->SetTextColor(192, 57, 43); else $pdf->SetTextColor(44, 62, 80);
    $pdf->Cell($lr[1], 7, pt(eur($sem) . ' '), 1, 0, 'R', $destaque);
    $pdf->Cell($lr[2], 7, pt(eur($iva) . ' '), 1, 0, 'R', $destaque);
    $pdf->Cell($lr[3], 7, pt(eur($com) . ' '), 1, 1, 'R', $destaque);
}
$pdf->SetX($xResumo);
$pdf->SetFont('Arial', 'I', 8);
$pdf->SetTextColor(120, 120, 120);
$pdf->Cell($larg_resumo, 5, pt('Os valores incluem IVA a ' . round($taxa_iva * 100) . '%. Sem IVA = Com IVA / ' . number_format(1 + $taxa_iva, 2, ',', '') . '.'), 0, 1, 'L');
$y_fim_resumo = $pdf->GetY();

// Caixa do saldo de abertura (lado esquerdo)
$larg_caixa = $xResumo - 10 - 8;
if (!empty($acerto['abertura_gerada_num'])) {
    $cor = [142, 68, 173]; $fundo = [245, 238, 248];
    $titulo = 'Saldo de abertura gerado';
} else {
    $cor = [44, 62, 80]; $fundo = [241, 242, 246];
    $titulo = 'Contas a zero (sem saldo de abertura)';
}
$pdf->SetFillColor($fundo[0], $fundo[1], $fundo[2]);
$pdf->SetDrawColor($cor[0], $cor[1], $cor[2]);
$pdf->SetLineWidth(0.4);
$pdf->Rect(10, $y_resumo, $larg_caixa, 28, 'DF');
$pdf->SetXY(14, $y_resumo + 3);
$pdf->SetFont('Arial', 'B', 11);
$pdf->SetTextColor($cor[0], $cor[1], $cor[2]);
$pdf->Cell($larg_caixa - 8, 6, pt($titulo), 0, 1, 'L');
$pdf->SetX(14);
$pdf->SetFont('Arial', 'B', 16);
if ($t_total < 0) $pdf->SetTextColor(192, 57, 43);
$pdf->Cell($larg_caixa - 8, 9, pt(eur($t_total)), 0, 1, 'L');
$pdf->SetX(14);
$pdf->SetFont('Arial', '', 8);
$pdf->SetTextColor(87, 101, 116);
if (!empty($acerto['abertura_gerada_num'])) {
    if ($abertura) {
        $pdf->Cell($larg_caixa - 8, 4.5, $pdf->Cortar('Lançado em "Abertura + Faturas Cliente" como registo nº ' . $abertura['numero'] . ' de ' . dataPT($abertura['data_registo']), $larg_caixa - 8), 0, 1, 'L');
        $pdf->SetX(14);
        $pdf->Cell($larg_caixa - 8, 4.5, $pdf->Cortar('Estado atual: ' . $abertura['estado'] . (!empty($abertura['id_acerto']) ? ' (no Acerto nº ' . $abertura['id_acerto'] . ')' : ''), $larg_caixa - 8), 0, 1, 'L');
    } else {
        $pdf->Cell($larg_caixa - 8, 4.5, pt('O registo de saldo de abertura nº ' . (int)$acerto['abertura_gerada_num'] . ' já não existe.'), 0, 1, 'L');
    }
} else {
    $pdf->Cell($larg_caixa - 8, 5, pt('O total do acerto foi zero.'), 0, 1, 'L');
}

// Aviso se os documentos atuais não batem com o histórico (ex.: documentos alterados depois)
$pdf->SetY(max($y_fim_resumo, $y_resumo + 30) + 2);
if (abs($soma_fserv - $t_fserv) >= 0.005 || abs($soma_fat - $t_fat) >= 0.005 || abs($soma_fatfav - $t_fatfav) >= 0.005) {
    $pdf->SetFont('Arial', 'I', 8);
    $pdf->SetTextColor(192, 57, 43);
    $pdf->Cell(0, 5, pt('Atenção: a soma dos documentos listados difere dos totais registados no momento do acerto (os totais do resumo são os do acerto).'), 0, 1, 'L');
}

// Assinaturas
$pdf->Espaco(22);
$pdf->Ln(10);
$y_ass = $pdf->GetY();
$pdf->SetDrawColor(150, 150, 150);
$pdf->SetLineWidth(0.2);
$pdf->Line(30, $y_ass, 120, $y_ass);
$pdf->Line(177, $y_ass, 267, $y_ass);
$pdf->SetFont('Arial', '', 8);
$pdf->SetTextColor(87, 101, 116);
$pdf->SetXY(30, $y_ass + 1);
$pdf->Cell(90, 5, pt('m. Abreu - Instalações Técnicas, Lda.'), 0, 0, 'C');
$pdf->SetXY(177, $y_ass + 1);
$pdf->Cell(90, 5, pt('J. Dias'), 0, 0, 'C');

ob_end_clean();
$pdf->Output('I', 'JDias_Acerto_' . (int)$acerto['numero'] . '_' . date('Ymd', strtotime($acerto['data_registo'])) . '.pdf');
?>
