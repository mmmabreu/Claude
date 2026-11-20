<?php
ob_start();
session_start();
if (!isset($_SESSION['user_id'])) {
    ob_end_clean();
    die("Acesso negado.");
}
require_once 'db.php';
require_once('fpdf/fpdf.php');

$perfil_user = $_SESSION['perfil'] ?? '';
$is_admin = in_array($perfil_user, ['admin', 'administrativo']);

$stmt_perm = $pdo->prepare("SELECT modulo FROM permissoes WHERE grupo = :perfil AND pode_aceder = 1");
$stmt_perm->execute(['perfil' => $perfil_user]);
$meus_acessos = $stmt_perm->fetchAll(PDO::FETCH_COLUMN);

if (!$is_admin && !in_array('termos', $meus_acessos) && !in_array('termos_eletrico', $meus_acessos)) {
    ob_end_clean();
    die("Acesso negado.");
}

$id_vistoria = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$id_vistoria) {
    ob_end_clean();
    die("Erro: ID de vistoria inválido.");
}

try {
    $sql = "SELECT vi.*, c.nome as cliente_nome, c.nif as cliente_nif,
                   c.morada as cliente_morada, c.codigo_postal as cliente_cp, c.cidade as cliente_cidade,
                   o.nome as obra_nome, o.morada as obra_morada, o.referencia_obra
            FROM vistorias_instalacao vi
            LEFT JOIN clientes c ON vi.id_cliente = c.id
            LEFT JOIN obras o ON vi.id_obra = o.id
            WHERE vi.id = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$id_vistoria]);
    $vistoria = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$vistoria) {
        ob_end_clean();
        die("Erro: Vistoria não encontrada.");
    }

    $stmt_p = $pdo->prepare("SELECT * FROM vistorias_instalacao_pisos WHERE id_vistoria = ? ORDER BY numero_piso ASC, id ASC");
    $stmt_p->execute([$id_vistoria]);
    $pisos = $stmt_p->fetchAll(PDO::FETCH_ASSOC);

    $stmt_alt = $pdo->prepare("SELECT * FROM vistorias_instalacao_alteracoes WHERE id_vistoria = ? ORDER BY id ASC");
    $stmt_alt->execute([$id_vistoria]);
    $alteracoes = $stmt_alt->fetchAll(PDO::FETCH_ASSOC);

    $observacoes = [];
    try {
        $stmt_obs = $pdo->prepare("SELECT * FROM vistorias_instalacao_observacoes WHERE id_vistoria = ? ORDER BY id ASC");
        $stmt_obs->execute([$id_vistoria]);
        $observacoes = $stmt_obs->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) { /* tabela pode ainda não existir */ }
} catch (Exception $e) {
    ob_end_clean();
    die("Erro na BD: " . htmlspecialchars($e->getMessage()));
}

// Número partilhado (visita → orçamento → obra → termo → vistoria)
$referencia = trim((string)($vistoria['referencia'] ?? ''));
if ($referencia === '') $referencia = trim((string)($vistoria['referencia_obra'] ?? ''));
if ($referencia === '') $referencia = (string)$vistoria['id'];

function pt($texto) {
    return mb_convert_encoding((string)($texto ?? ''), 'windows-1252', 'UTF-8');
}

function labelReferenciaPiso($ref, $pisos) {
    if ($ref === null || $ref === '') return '-';
    if ($ref === 'entrada') return 'Entrada';
    $num = (int)$ref;
    if ($num >= 1 && $num <= count($pisos)) {
        $nome = $pisos[$num - 1]['nome_piso'] ?? '';
        return 'Piso ' . $num . (!empty($nome) ? ' - ' . $nome : '');
    }
    return '-';
}

class PDF extends FPDF {
    public $titulo_doc;
    public $ref_doc;

    function Header() {
        if (file_exists('mab.png')) {
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
        $this->Cell(0, 4, pt("Rua Damião de Góis, 205, Braga"), 0, 0, 'C');
        // Número da vistoria e paginação em todas as páginas
        $this->SetXY(10, -11);
        $this->SetFont('Arial', '', 7);
        $this->SetTextColor(127, 140, 141);
        $this->Cell(60, 4, pt('Vistoria Nº ' . $this->ref_doc), 0, 0, 'L');
        $this->SetX(140);
        $this->Cell(60, 4, pt('Página ' . $this->PageNo() . '/{nb}'), 0, 0, 'R');
        $this->SetTextColor(0, 0, 0);
    }
    function DrawBoxTitle($title) {
        $this->Ln(4);
        $this->SetFont('Arial', 'B', 9);
        $this->SetFillColor(241, 242, 246);
        $this->Cell(0, 7, pt(' ' . $title), 1, 1, 'L', true);
    }
    function DrawRow($label1, $val1, $label2 = '', $val2 = '') {
        $totalWidth = 190;
        $minLabel = 25;
        $minVal2 = 25;
        $minVal1 = 20;

        // Cada rótulo é medido pelo seu próprio texto, para nunca ficar cortado
        // nem sobrepor o valor a seguir (ex: "Caixa de Medição de Terra?:").
        $this->SetFont('Arial', 'B', 9);
        $w1 = max($minLabel, $this->GetStringWidth(pt($label1)) + 4);

        if ($label2 !== '') {
            $w3 = max($minLabel, $this->GetStringWidth(pt($label2)) + 4);
            $this->SetFont('Arial', '', 9);
            $w4 = max($minVal2, $this->GetStringWidth(pt($val2)) + 4);
            // O resto do espaço fica para o 1º valor (normalmente o texto mais longo, ex: nome do cliente)
            $w2 = max($minVal1, $totalWidth - $w1 - $w3 - $w4);

            $this->SetFont('Arial', 'B', 9);
            $this->Cell($w1, 6, pt($label1), 'L,B', 0);
            $this->SetFont('Arial', '', 9);
            $this->Cell($w2, 6, pt($val1), 'B', 0);
            $this->SetFont('Arial', 'B', 9);
            $this->Cell($w3, 6, pt($label2), 'L,B', 0);
            $this->SetFont('Arial', '', 9);
            $this->Cell($w4, 6, pt($val2), 'R,B', 1);
        } else {
            $w2 = $totalWidth - $w1;
            $this->Cell($w1, 6, pt($label1), 'L,B', 0);
            $this->SetFont('Arial', '', 9);
            $this->Cell($w2, 6, pt($val1), 'R,B', 1);
        }
    }
    function DrawTextoLivre($titulo, $texto) {
        $this->SetFont('Arial', 'B', 9);
        $this->MultiCell(0, 6, pt($titulo), 0);
        $this->SetFont('Arial', '', 9);
        $this->MultiCell(0, 5, pt($texto), 0);
        $this->Ln(2);
    }
}

$pdf = new PDF('P', 'mm', 'A4');
$pdf->titulo_doc = 'VISTORIA DE INSTALAÇÃO Nº ' . $referencia;
$pdf->ref_doc = $referencia;
$pdf->AliasNbPages();
$pdf->AddPage();

$morada_cli = trim(($vistoria['cliente_morada'] ?? '') . ' ' . ($vistoria['cliente_cp'] ?? '') . ' ' . ($vistoria['cliente_cidade'] ?? ''));

$pdf->DrawBoxTitle('DADOS DO CLIENTE E OBRA');
$pdf->DrawRow('Cliente:', $vistoria['cliente_nome'] ?? '-', 'NIF:', $vistoria['cliente_nif'] ?? '-');
$pdf->DrawRow('Morada:', $morada_cli !== '' ? $morada_cli : '-');
$pdf->DrawRow('Obra:', $vistoria['obra_nome'] ?? '-', 'Nº Obra:', $referencia);
$pdf->DrawRow('Data da Vistoria:', !empty($vistoria['data_vistoria']) ? date('d/m/Y', strtotime($vistoria['data_vistoria'])) : '-');

$pdf->DrawBoxTitle('EDIFÍCIO');
$pdf->DrawRow('Tem Portinhola?:', $vistoria['tem_portinhola'] ?? '-', 'Caixa de Contador?:',
    ($vistoria['caixa_contador'] ?? '-') . (!empty($vistoria['caixa_contador_local']) ? ' (' . $vistoria['caixa_contador_local'] . ')' : ''));
$pdf->DrawRow('Cabo de Entrada:',
    trim(($vistoria['cabo_entrada_tipo'] ?? '-') . ' / ' . (!empty($vistoria['cabo_entrada_seccao']) ? $vistoria['cabo_entrada_seccao'] . ' mm²' : '-') . ' / ' . ($vistoria['cabo_entrada_fases'] ?? '-')));
$pdf->DrawRow('Cabo de Terra:',
    trim(($vistoria['cabo_terra_tipo'] ?? '-') . ' / ' . (!empty($vistoria['cabo_terra_seccao']) ? $vistoria['cabo_terra_seccao'] . ' mm²' : '-')));
$pdf->DrawRow('Caixa de Medição de Terra?:', $vistoria['caixa_medicao_terra'] ?? '-', 'Número de Pisos:', $vistoria['numero_pisos'] ?? '-');

foreach ($pisos as $i => $p) {
    $nome_piso = $p['nome_piso'] ?? '';
    $titulo_piso = 'PISO ' . ($i + 1) . (!empty($nome_piso) ? ' - ' . mb_strtoupper($nome_piso, 'UTF-8') : '');
    $pdf->DrawBoxTitle($titulo_piso);
    $pdf->DrawRow('Quadro Elétrico?:', $p['quadro_eletrico'] ?? '-', 'Corte de Entrada:',
        trim(($p['tipo_corte_entrada'] ?? '-') . (!empty($p['amperagem_corte']) ? ' - ' . $p['amperagem_corte'] . ' A' : '')));
    $pdf->DrawRow('Proteção Diferencial:', $p['protecao_diferencial'] ?? '-', 'Cabo de Entrada:',
        trim(($p['cabo_entrada_tipo'] ?? '-') . ' / ' . (!empty($p['cabo_entrada_seccao']) ? $p['cabo_entrada_seccao'] . ' mm²' : '-') . ' / ' . ($p['cabo_entrada_fases'] ?? '-')));
    $pdf->DrawRow('Casas de Banho:', $p['casas_banho'] ?? '0', 'Proteção Dif. 30mA (WC):', $p['casas_banho_diferencial_30ma'] ?? '-');
    $pdf->DrawRow('Iluminação Vol. 0:', $p['iluminacao_vol0'] ?? '0', 'Tomada/Equip. Vol. 0:', $p['tomada_equipamento_vol0'] ?? '0');
}

if (!empty($alteracoes)) {
    $pdf->DrawBoxTitle('ALTERAÇÕES A EFETUAR');
    foreach ($alteracoes as $alt) {
        $local = labelReferenciaPiso($alt['referencia_piso'] ?? null, $pisos);
        $pdf->DrawTextoLivre($local . ':', $alt['descricao'] ?? '');
    }
}

if (!empty($observacoes)) {
    $pdf->DrawBoxTitle('OBSERVAÇÕES');
    $pdf->SetFont('Arial', '', 9);
    foreach ($observacoes as $obs) {
        $pdf->MultiCell(0, 5, pt('• ' . ($obs['descricao'] ?? '')), 0);
        $pdf->Ln(1);
    }
}

ob_end_clean();
$pdf->Output('I', pt('Vistoria_Instalacao_' . preg_replace('/[^A-Za-z0-9_-]/', '_', $referencia) . '.pdf'));
