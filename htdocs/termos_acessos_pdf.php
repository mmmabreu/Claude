<?php
// REGISTO DE INSTALAÇÃO — CONTROLO DE ACESSOS (uma ou várias controladoras, qualquer marca)
// tipo=cliente (por defeito) → sem passwords | tipo=interno → com passwords
ob_start();
session_start();

if (!isset($_SESSION['user_id'])) {
    ob_end_clean();
    die("Acesso negado.");
}

require_once 'db.php';
require_once('fpdf/fpdf.php');
require_once 'termos_acessos_comum.php';

if (!caTemAcesso($pdo)) {
    ob_end_clean();
    die("Acesso negado.");
}

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$interno = (($_GET['tipo'] ?? 'cliente') === 'interno');
if (!$id) {
    ob_end_clean();
    die("Erro: ID inválido.");
}

try {
    $st = $pdo->prepare("SELECT t.*, c.nome AS cliente_nome, c.nif AS cliente_nif, c.morada AS cliente_morada,
                                c.codigo_postal AS cliente_cp, c.cidade AS cliente_cidade, o.nome AS obra_nome, o.morada AS obra_morada
                         FROM termos_acessos t
                         LEFT JOIN clientes c ON t.id_cliente = c.id
                         LEFT JOIN obras o ON t.id_obra = o.id
                         WHERE t.id = ?");
    $st->execute([$id]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if (!$r) {
        ob_end_clean();
        die("Erro: Registo não encontrado.");
    }
    caCriarTabelas($pdo); // garante a migração da 1ª versão
    $st = $pdo->prepare("SELECT * FROM termos_acessos_controladoras WHERE id_termo = ? ORDER BY ordem ASC, id ASC");
    $st->execute([$id]);
    $controladoras = $st->fetchAll(PDO::FETCH_ASSOC);
    $st = $pdo->prepare("SELECT * FROM termos_acessos_portas WHERE id_termo = ? ORDER BY numero ASC, id ASC");
    $st->execute([$id]);
    $portas_todas = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    ob_end_clean();
    die("Erro na BD: " . htmlspecialchars($e->getMessage()));
}

$referencia = $r['referencia'] ?: ('#' . $r['id']);
$testes = json_decode((string)($r['testes'] ?? ''), true) ?: [];

function pt($t) { return mb_convert_encoding((string)($t ?? ''), 'windows-1252', 'UTF-8'); }
function d($v) { return ($v === null || $v === '') ? '-' : $v; }
function dataPT($v) { return !empty($v) ? date('d/m/Y', strtotime($v)) : '-'; }

class PDF extends FPDF {
    public $titulo_doc;
    public $ref_doc;
    public $interno = false;

    function Header() {
        if (file_exists('mab.png')) $this->Image('mab.png', 10, 10, 16);
        $this->SetY(11);
        $this->SetFont('Arial', 'B', 13); $this->SetTextColor(44, 62, 80);
        $this->Cell(0, 7, pt($this->titulo_doc), 0, 1, 'R');
        $this->SetFont('Arial', 'B', 10); $this->SetTextColor(22, 160, 133);
        $this->Cell(0, 5, pt('Nº ' . $this->ref_doc), 0, 1, 'R');
        if ($this->interno) {
            $this->SetFont('Arial', 'B', 7); $this->SetTextColor(142, 68, 173);
            $this->Cell(0, 4, pt('DOCUMENTO INTERNO - CONTÉM PASSWORDS'), 0, 1, 'R');
        }
        $this->SetTextColor(0, 0, 0);
        $this->SetY(30);
        $this->SetDrawColor(44, 62, 80); $this->SetLineWidth(0.5);
        $this->Line(10, 30, 200, 30);
        $this->Ln(3);
    }
    function Footer() {
        $this->SetY(-15);
        $this->SetDrawColor(200, 200, 200); $this->SetLineWidth(0.2);
        $this->Line(10, $this->GetY(), 200, $this->GetY());
        $this->SetFont('Arial', 'B', 8); $this->SetTextColor(0, 0, 0);
        $this->Cell(0, 4, pt('m. Abreu - Instalações Técnicas, Lda.'), 0, 1, 'C');
        $this->SetFont('Arial', '', 8);
        $this->Cell(0, 4, pt('Rua Damião de Góis, 205, Braga'), 0, 0, 'C');
        $this->SetXY(10, -11); $this->SetFont('Arial', '', 7); $this->SetTextColor(127, 140, 141);
        $this->Cell(60, 4, pt('Controlo de Acessos Nº ' . $this->ref_doc), 0, 0, 'L');
        $this->SetX(140); $this->Cell(60, 4, pt('Página ' . $this->PageNo() . '/{nb}'), 0, 0, 'R');
        $this->SetTextColor(0, 0, 0);
    }
    function Espaco($h) {
        if ($this->GetY() + $h > 272) $this->AddPage();
    }
    function Titulo($t) {
        $this->Espaco(20);
        $this->Ln(3);
        $this->SetFont('Arial', 'B', 9); $this->SetFillColor(232, 248, 245); $this->SetDrawColor(209, 242, 235);
        $this->SetTextColor(17, 122, 101);
        $this->Cell(0, 7, pt(' ' . $t), 1, 1, 'L', true);
        $this->SetTextColor(0, 0, 0); $this->SetDrawColor(220, 220, 220);
    }
    // Linha com 1 ou 2 pares rótulo/valor
    function Linha($l1, $v1, $l2 = null, $v2 = null) {
        $this->Espaco(7);
        $this->SetFont('Arial', 'B', 8.5);
        if ($l2 === null) {
            $w1 = max(38, $this->GetStringWidth(pt($l1)) + 4);
            $this->Cell($w1, 6, pt($l1), 'L,B', 0);
            $this->SetFont('Arial', '', 8.5);
            $this->Cell(190 - $w1, 6, pt(d($v1)), 'R,B', 1);
        } else {
            $this->Cell(38, 6, pt($l1), 'L,B', 0);
            $this->SetFont('Arial', '', 8.5);
            $this->Cell(57, 6, pt(d($v1)), 'B', 0);
            $this->SetFont('Arial', 'B', 8.5);
            $this->Cell(38, 6, pt($l2), 'L,B', 0);
            $this->SetFont('Arial', '', 8.5);
            $this->Cell(57, 6, pt(d($v2)), 'R,B', 1);
        }
    }
}

$pdf = new PDF('P', 'mm', 'A4');
$pdf->titulo_doc = 'REGISTO DE INSTALAÇÃO - CONTROLO DE ACESSOS';
$pdf->ref_doc = $referencia;
$pdf->interno = $interno;
$pdf->AliasNbPages();
$pdf->SetAutoPageBreak(false);
$pdf->AddPage();

// --- CLIENTE E LOCAL ---
$morada_cli = trim(($r['cliente_morada'] ?? '') . ' ' . ($r['cliente_cp'] ?? '') . ' ' . ($r['cliente_cidade'] ?? ''));
$pdf->Titulo('CLIENTE E LOCAL');
$pdf->Linha('Cliente:', $r['cliente_nome'], 'NIF:', $r['cliente_nif']);
$pdf->Linha('Morada:', $morada_cli);
$pdf->Linha('Obra:', $r['obra_nome'], 'Data Instalação:', dataPT($r['data_instalacao']));
$pdf->Linha('Local da Instalação:', $r['local_instalacao'] ?: $r['obra_morada']);
if (!empty($r['gps'])) $pdf->Linha('Coordenadas GPS:', $r['gps']);
$pdf->Linha('Técnico:', $r['tecnico']);

// --- RESUMO ---
$total_portas = 0;
foreach ($controladoras as $c) $total_portas += (int)$c['num_portas'];
$pdf->Titulo('RESUMO DO SISTEMA');
$pdf->Linha('Controladoras:', (string)count($controladoras), 'Total de Portas:', (string)$total_portas);
$pdf->Linha('Software:', $r['software'], 'Utilizador:', $r['software_utilizador']);
$pdf->Linha('Servidor / PC:', $r['servidor']);
if ($interno) $pdf->Linha('Password Software:', $r['software_password']);
if (!empty($r['acesso_remoto'])) {
    $pdf->Espaco(12);
    $pdf->SetFont('Arial', 'B', 8.5); $pdf->Cell(38, 6, pt('Acesso Remoto:'), 'L', 0);
    $pdf->SetFont('Arial', '', 8.5); $pdf->MultiCell(152, 6, pt($r['acesso_remoto']), 'R');
    $pdf->Line(10, $pdf->GetY(), 200, $pdf->GetY());
}

// --- CONTROLADORAS E PORTAS ---
foreach ($controladoras as $ci => $c) {
    $nome_ctrl = trim(($c['marca'] ?? '') . ' ' . ($c['modelo'] ?? ''));
    $pdf->Titulo('CONTROLADORA ' . ($ci + 1) . ($nome_ctrl !== '' ? ' - ' . mb_strtoupper($nome_ctrl, 'UTF-8') : ''));
    $pdf->Linha('Marca:', $c['marca'], 'Modelo:', $c['modelo']);
    $pdf->Linha('Nº de Série:', $c['num_serie'], 'Firmware:', $c['firmware']);
    $pdf->Linha('Localização:', $c['localizacao'], 'Nº de Portas:', (string)(int)$c['num_portas']);
    $pdf->Linha('Alimentação:', $c['alimentacao'], 'Bateria:', $c['bateria']);
    $pdf->Linha('Comunicação:', $c['comunicacao'], 'Porta TCP:', $c['porta_tcp']);
    $pdf->Linha('Endereço IP:', $c['ip'], 'Máscara:', $c['mascara']);
    $pdf->Linha('Gateway:', $c['gateway'], 'DNS:', $c['dns']);
    $pdf->Linha('MAC:', $c['mac'], 'Endereço RS485:', $c['endereco_rs485']);
    if ($interno) $pdf->Linha('Pass. Comunicação:', $c['password_comunicacao']);

    $portas = array_values(array_filter($portas_todas, fn($p) => (int)$p['id_controladora'] === (int)$c['id']));
    foreach ($portas as $p) {
        $pdf->Espaco(44);
        $pdf->Ln(2);
        $pdf->SetFont('Arial', 'B', 8.5); $pdf->SetFillColor(244, 246, 248); $pdf->SetDrawColor(220, 220, 220);
        $pdf->Cell(0, 6, pt('  Porta ' . (int)$p['numero'] . (!empty($p['nome']) ? ' - ' . $p['nome'] : '')), 1, 1, 'L', true);
        $pdf->Linha('Leitor de Entrada:', $p['leitor_entrada'], 'Leitor de Saída:', $p['leitor_saida']);
        $pdf->Linha('Credenciais:', $p['credenciais']);
        $pdf->Linha('Fechadura:', $p['fechadura'], 'Tempo Abertura:', ($p['tempo_abertura'] !== null && $p['tempo_abertura'] !== '') ? $p['tempo_abertura'] . ' s' : '-');
        $pdf->Linha('Modo:', $p['modo_fechadura']);
        $pdf->Linha('Botão de Saída:', $p['botao_saida'], 'Sensor de Porta:', $p['sensor_porta']);
        $pdf->Linha('Quebra-vidros:', $p['quebra_vidros']);
        if (!empty($p['observacoes'])) $pdf->Linha('Observações:', $p['observacoes']);
    }
}

// --- TESTES ---
$pdf->Titulo('TESTES E ENTREGA');
$pdf->SetFont('Arial', '', 8.5);
foreach ($CA_TESTES as $k => $lbl) {
    $pdf->Espaco(7);
    $res = $testes[$k] ?? '';
    $pdf->Cell(160, 6, pt($lbl), 'L,B', 0);
    if ($res === 'Sim')      $pdf->SetTextColor(39, 174, 96);
    elseif ($res === 'Não')  $pdf->SetTextColor(192, 57, 43);
    else                     $pdf->SetTextColor(127, 140, 141);
    $pdf->SetFont('Arial', 'B', 8.5);
    $pdf->Cell(30, 6, pt($res !== '' ? $res : '-'), 'R,B', 1, 'C');
    $pdf->SetTextColor(0, 0, 0); $pdf->SetFont('Arial', '', 8.5);
}
$pdf->Linha('Entregue a:', $r['entregue_a'], 'Data de Entrega:', dataPT($r['data_entrega']));

// --- OBSERVAÇÕES ---
if (!empty($r['observacoes'])) {
    $pdf->Titulo('OBSERVAÇÕES');
    $pdf->SetFont('Arial', '', 8.5);
    $pdf->SetAutoPageBreak(true, 20);
    $pdf->MultiCell(0, 5, pt($r['observacoes']), 0, 'J');
    $pdf->SetAutoPageBreak(false);
}

// --- ASSINATURAS ---
$pdf->Espaco(32);
$pdf->Ln(14);
$y = $pdf->GetY();
$pdf->SetDrawColor(150, 150, 150);
$pdf->Line(15, $y + 10, 95, $y + 10);
$pdf->Line(115, $y + 10, 195, $y + 10);
$pdf->SetFont('Arial', '', 8);
$pdf->SetXY(15, $y + 11);  $pdf->Cell(80, 4, pt('O Técnico'), 0, 0, 'C');
$pdf->SetXY(115, $y + 11); $pdf->Cell(80, 4, pt('O Cliente'), 0, 0, 'C');
$pdf->SetFont('Arial', '', 7); $pdf->SetTextColor(127, 140, 141);
$pdf->SetXY(15, $y + 15);  $pdf->Cell(80, 4, pt(d($r['tecnico'])), 0, 0, 'C');
$pdf->SetXY(115, $y + 15); $pdf->Cell(80, 4, pt(d($r['entregue_a'])), 0, 0, 'C');

ob_end_clean();
$pdf->Output('I', 'Controlo_Acessos_' . preg_replace('/[^A-Za-z0-9_-]/', '_', $referencia) . ($interno ? '_Interno' : '') . '.pdf');
