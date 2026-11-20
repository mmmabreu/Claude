<?php
ob_start();
session_start();
if (!isset($_SESSION['user_id'])) {
    ob_end_clean();
    die("Acesso negado.");
}
require_once 'db.php';
require_once('fpdf/fpdf.php');

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$id) {
    ob_end_clean();
    die("Erro: ID de termo inválido.");
}

try {
    $sql = "SELECT t.*, c.nome as cliente_nome, c.morada as cliente_morada,
                   c.codigo_postal as cliente_cp, c.cidade as cliente_cidade,
                   c.nif as cliente_nif,
                   o.nome as obra_nome, o.referencia_obra as obra_referencia,
                   v.assunto as visita_assunto, v.referencia_visita as visita_referencia
            FROM termos_knx t
            LEFT JOIN clientes c ON t.id_cliente = c.id
            LEFT JOIN obras o ON t.id_obra = o.id
            LEFT JOIN visitas v ON t.id_visita = v.id
            WHERE t.id = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$id]);
    $doc = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$doc) {
        ob_end_clean();
        die("Erro: Registo não encontrado.");
    }
} catch (Exception $e) {
    ob_end_clean();
    die("Erro na BD: " . htmlspecialchars($e->getMessage()));
}

function pt($texto) {
    return mb_convert_encoding((string)($texto ?? ''), 'windows-1252', 'UTF-8');
}

class PDF extends FPDF {
    public $titulo_doc;

    function Header() {
        if (file_exists('mab.png')) {
            // Logótipo a 80% do tamanho anterior (20mm -> 16mm)
            $this->Image('mab.png', 10, 10, 16);
        }
        $this->SetY(12);
        $this->SetFont('Arial', 'B', 14);
        $this->SetTextColor(44, 62, 80);
        $this->Cell(0, 8, pt($this->titulo_doc), 0, 1, 'R');
        $this->Ln(8);
        $this->SetDrawColor(44, 62, 80);
        $this->SetLineWidth(0.5);
        $this->Line(10, $this->GetY(), 200, $this->GetY());
        $this->Ln(5);
    }
    function Footer() {
        $this->SetY(-15);
        $this->SetDrawColor(200, 200, 200);
        $this->Line(10, $this->GetY(), 200, $this->GetY());
        $this->SetFont('Arial', 'B', 8);
        $this->Cell(0, 4, pt("m. Abreu - Instalações Técnicas, Lda."), 0, 1, 'C');
        $this->SetFont('Arial', '', 8);
        $this->Cell(0, 4, pt("Rua Damião de Góis, 205, Braga"), 0, 1, 'C');
    }
    function DrawBoxTitle($title) {
        $this->Ln(4);
        $this->SetFont('Arial', 'B', 9);
        $this->SetFillColor(241, 242, 246);
        $this->Cell(0, 7, pt(' ' . $title), 1, 1, 'L', true);
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
    // Espaço livre (mm) até à quebra de página automática
    function EspacoRestante() {
        return $this->PageBreakTrigger - $this->GetY();
    }
    // --- TABELA: HARDWARE / MÓDULOS FÍSICOS KNX ---
    function EquipTableHeader() {
        $this->SetFont('Arial', 'B', 8);
        $this->SetFillColor(240, 240, 240);
        $w = [25, 35, 35, 45, 50];
        $headers = ['End. Físico', 'Marca', 'Referência', 'Tipo', 'Localização'];
        foreach ($headers as $i => $h) { $this->Cell($w[$i], 7, pt($h), 1, 0, 'L', true); }
        $this->Ln();
    }
    function DrawEquipRow($fisico, $marca, $ref, $tipo, $localizacao) {
        $w = [25, 35, 35, 45, 50];
        // Quebra de página manual, para a linha nunca ficar cortada a meio
        if ($this->GetY() + 6 > $this->PageBreakTrigger) {
            $this->AddPage();
            $this->EquipTableHeader();
        }
        $this->SetFont('Arial', '', 8);
        $this->Cell($w[0], 6, pt($fisico), 1);
        $this->Cell($w[1], 6, pt($marca), 1);
        $this->Cell($w[2], 6, pt($ref), 1);
        $this->Cell($w[3], 6, pt($tipo), 1);
        $this->Cell($w[4], 6, pt($localizacao), 1, 1);
    }
    // --- TABELA: ENDEREÇOS DE GRUPO E ASSOCIAÇÕES ---
    function GrupoTableHeader() {
        $this->SetFont('Arial', 'B', 8);
        $this->SetFillColor(240, 240, 240);
        $this->Cell(30, 7, pt('End. Grupo'), 1, 0, 'L', true);
        $this->Cell(66, 7, pt('Função / Descrição'), 1, 0, 'L', true);
        $this->Cell(94, 7, pt('Associações (Físico -> Objeto)'), 1, 1, 'L', true);
    }
    function DrawGrupoRow($endereco, $funcao, $associacoesArr) {
        $w1 = 30; $w2 = 66; $w3 = 94;
        $lineHeight = 5;

        $linhas = [];
        foreach ((array)$associacoesArr as $a) {
            $f = trim($a['fisico'] ?? '');
            // Compatibilidade com relatórios gravados antes da chave passar de "saida" para "objeto"
            $s = trim($a['objeto'] ?? $a['saida'] ?? '');
            if ($f !== '' || $s !== '') {
                $linhas[] = $f . ($s !== '' ? '  ->  Objeto ' . $s : '');
            }
        }
        if (empty($linhas)) { $linhas[] = '-'; }
        $rowH = count($linhas) * $lineHeight;

        // Quebra de página manual, para o bloco de associações nunca ficar cortado a meio
        if ($this->GetY() + $rowH > $this->PageBreakTrigger) {
            $this->AddPage();
            $this->GrupoTableHeader();
        }

        $x = $this->GetX(); $y = $this->GetY();
        $this->SetFont('Arial', 'B', 8);
        $this->Cell($w1, $rowH, pt($endereco), 1);
        $this->SetFont('Arial', '', 8);
        $this->Cell($w2, $rowH, pt($funcao), 1);

        $xAssoc = $this->GetX();
        foreach ($linhas as $i => $linha) {
            $this->SetXY($xAssoc, $y + $i * $lineHeight);
            $this->Cell($w3, $lineHeight, pt($linha), 1);
        }
        $this->SetXY($x, $y + $rowH);
    }
}

$pdf = new PDF('P', 'mm', 'A4');
$pdf->titulo_doc = 'DOMÓTICA (KNX) #' . $doc['numero_relatorio'];
$pdf->AliasNbPages();
$pdf->AddPage();

$morada_cli = trim($doc['cliente_morada'] . ' ' . $doc['cliente_cp'] . ' ' . $doc['cliente_cidade']);

// Referência da Obra/Visita associada (se existir)
$local_ref = '';
if (!empty($doc['obra_nome']) || !empty($doc['obra_referencia'])) {
    $local_ref = trim(($doc['obra_nome'] ?? '') . (!empty($doc['obra_referencia']) ? ' (Ref: ' . $doc['obra_referencia'] . ')' : ''));
} elseif (!empty($doc['visita_assunto']) || !empty($doc['visita_referencia'])) {
    $local_ref = trim(($doc['visita_assunto'] ?? '') . (!empty($doc['visita_referencia']) ? ' (Ref: ' . $doc['visita_referencia'] . ')' : ''));
}

$pdf->DrawBoxTitle('DADOS DO CLIENTE E RELATÓRIO');
$pdf->DrawRow('Cliente:', $doc['cliente_nome']);
$pdf->DrawRow('Data Inspeção:', date('d/m/Y', strtotime($doc['data_inspecao'])), 'Nº Relatório:', $doc['numero_relatorio']);
$pdf->DrawRow('NIF:', $doc['cliente_nif']);
$pdf->DrawRow('Morada Sede:', $morada_cli);
if ($local_ref !== '') {
    $pdf->DrawRow('Obra/Visita:', $local_ref);
}

$pdf->DrawBoxTitle('RESPONSABILIDADE TÉCNICA');
$pdf->DrawRow('Técnico:', $doc['tecnico_nome'], 'Nº Inscrição:', $doc['tecnico_n_inscricao']);
$pdf->DrawRow('Data de Emissão:', date('d/m/Y', strtotime($doc['data_emissao'])));

// --- HARDWARE / MÓDULOS FÍSICOS KNX ---
$equipamentos = json_decode($doc['dados_json'] ?? '[]', true) ?: [];
if (!empty($equipamentos)) {
    $pdf->DrawBoxTitle('HARDWARE / MÓDULOS FÍSICOS KNX');
    $pdf->EquipTableHeader();
    foreach ($equipamentos as $e) {
        $pdf->DrawEquipRow(
            $e['fisico'] ?? '',
            $e['marca'] ?? '',
            $e['ref'] ?? '',
            $e['tipo'] ?? '',
            $e['localizacao'] ?? ''
        );
    }
}

// --- ENDEREÇOS DE GRUPO E ASSOCIAÇÕES DE SAÍDAS ---
$grupos = json_decode($doc['grupos_json'] ?? '[]', true) ?: [];
if (!empty($grupos)) {
    $pdf->DrawBoxTitle('ENDEREÇOS DE GRUPO E ASSOCIAÇÕES DE SAÍDAS');
    $pdf->GrupoTableHeader();
    foreach ($grupos as $g) {
        $pdf->DrawGrupoRow($g['endereco'] ?? '', $g['funcao'] ?? '', $g['associacoes'] ?? []);
    }
}

// --- NOTAS / OBSERVAÇÕES (só aparece se houver notas escritas) ---
$notas = trim($doc['notas'] ?? '');
if ($notas !== '') {
    // Garante espaço para o título e pelo menos 2 linhas de texto na mesma página
    if ($pdf->EspacoRestante() < 25) { $pdf->AddPage(); }
    $pdf->DrawBoxTitle('NOTAS / OBSERVAÇÕES');
    $pdf->SetFont('Arial', '', 9);
    $pdf->SetTextColor(44, 62, 80);
    $pdf->MultiCell(0, 5, pt(str_replace("\r\n", "\n", $notas)), 'LRB', 'L');
    $pdf->SetTextColor(0, 0, 0);
}

ob_end_clean();
$pdf->Output('I', pt('KNX_' . $doc['numero_relatorio'] . '.pdf'));
?>