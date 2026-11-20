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

$perfil_user = $_SESSION['perfil'] ?? '';
$is_admin = in_array($perfil_user, ['admin', 'administrativo']);
$id_utilizador_logado = $_SESSION['user_id'];

// --- FILTROS QUE VÊM DO BOTÃO DE IMPRESSÃO ---
$filtro_mes = filter_input(INPUT_GET, 'mes', FILTER_VALIDATE_INT) ?: date('m');
$filtro_ano = filter_input(INPUT_GET, 'ano', FILTER_VALIDATE_INT) ?: date('Y');
$filtro_matricula = $_GET['matricula'] ?? 'todas';

$where = ["YEAR(f.data_registo) = ?"];
$params = [$filtro_ano];

if ($filtro_mes !== 'todos') {
    $where[] = "MONTH(f.data_registo) = ?";
    $params[] = $filtro_mes;
}
if ($filtro_matricula !== 'todas') {
    $where[] = "f.matricula = ?";
    $params[] = $filtro_matricula;
}
if (!$is_admin) {
    $where[] = "f.id_utilizador = ?";
    $params[] = $id_utilizador_logado;
}

// --- BUSCAR DADOS DA FROTA ---
try {
    $where_clause = implode(" AND ", $where);
    $sql_list = "SELECT f.*, u.nome as nome_colaborador 
                 FROM frota_registos f 
                 LEFT JOIN utilizadores u ON f.id_utilizador = u.id 
                 WHERE $where_clause 
                 ORDER BY f.data_registo ASC, f.hora_inicio ASC";
    
    $stmt_list = $pdo->prepare($sql_list);
    $stmt_list->execute($params);
    $registos = $stmt_list->fetchAll(PDO::FETCH_ASSOC);

} catch (Exception $e) {
    ob_end_clean();
    die("Erro na BD: " . htmlspecialchars($e->getMessage()));
}

// Função de encoding para FPDF
function pt($texto) {
    $texto = (string)($texto ?? '');
    return mb_convert_encoding($texto, 'windows-1252', 'UTF-8');
}

// --- CLASSE PDF CUSTOMIZADA (Igual ao Visitas) ---
class PDF extends FPDF {
    public $titulo_doc;
    public $subtitulo_doc;
    
    function Header() {
        $this->SetTopMargin(35);
        $logo_bottom_y = 10;
        
        if (file_exists('mab.png')) {
            $this->Image('mab.png', 10, 10, 16); 
            $logo_bottom_y = 10 + 16; 
        }
        
        $this->SetY(12);
        $this->SetFont('Arial', 'B', 14);
        $this->SetTextColor(44, 62, 80); 
        $this->Cell(0, 8, pt($this->titulo_doc), 0, 1, 'R');
        
        $this->SetFont('Arial', '', 10);
        $this->SetTextColor(127, 140, 141); 
        $this->Cell(0, 5, pt($this->subtitulo_doc), 0, 1, 'R');
        
        $y_text = $this->GetY();
        $y_line = max($y_text, $logo_bottom_y) + 3;
        
        // Cor Laranja (Viaturas)
        $this->SetDrawColor(211, 84, 0); 
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

    function DrawBoxTitle($title) {
        $this->Ln(2);
        $this->SetFont('Arial', 'B', 9);
        $this->SetFillColor(253, 242, 233); // Fundo Laranja Claro
        $this->SetDrawColor(250, 229, 211); // Borda Laranja
        $this->Cell(0, 7, pt(' ' . $title), 1, 1, 'L', true);
        $this->SetFont('Arial', '', 9);
    }
}

// INICIALIZAR PDF E DADOS BASE
$meses_nome = [1=>'Janeiro', 2=>'Fevereiro', 3=>'Março', 4=>'Abril', 5=>'Maio', 6=>'Junho', 7=>'Julho', 8=>'Agosto', 9=>'Setembro', 10=>'Outubro', 11=>'Novembro', 12=>'Dezembro'];
$nome_mes = ($filtro_mes === 'todos') ? 'O Ano Todo' : ($meses_nome[(int)$filtro_mes] ?? '');

$pdf = new PDF('P', 'mm', 'A4');
$pdf->titulo_doc = 'RELATÓRIO DE FROTA E VIATURAS';
$pdf->subtitulo_doc = 'Período: ' . $nome_mes . ' ' . $filtro_ano;

if ($filtro_matricula !== 'todas') {
    $pdf->subtitulo_doc .= ' | Matrícula: ' . $filtro_matricula;
}
if (!$is_admin) {
    $pdf->subtitulo_doc .= ' | Colab: ' . explode(' ', $_SESSION['nome'])[0];
}

$pdf->SetMargins(10, 35, 10);
$pdf->AliasNbPages();
$pdf->AddPage();
$pdf->SetAutoPageBreak(true, 20);

// --- CÁLCULO DE ESTATÍSTICAS ---
$total_km = 0;
$total_viagens = count($registos);
foreach ($registos as $r) {
    if ($r['km_fim'] > $r['km_inicio']) {
        $total_km += ($r['km_fim'] - $r['km_inicio']);
    }
}

// --- RESUMO DO RELATÓRIO ---
$pdf->DrawBoxTitle('RESUMO DO PERÍODO SELECIONADO');
$pdf->SetFont('Arial', 'B', 9);
$pdf->Cell(60, 6, pt('Viagens Registadas:'), 'L,B', 0);
$pdf->SetFont('Arial', '', 9);
$pdf->Cell(35, 6, pt($total_viagens), 'B', 0);

$pdf->SetFont('Arial', 'B', 9);
$pdf->Cell(60, 6, pt('Total Distância Percorrida:'), 'L,B', 0);
$pdf->SetFont('Arial', '', 9);
$pdf->Cell(35, 6, pt(number_format($total_km, 0, ',', ' ') . ' KM'), 'R,B', 1);

$pdf->Ln(5);

// --- TABELA DE REGISTOS ---
$pdf->DrawBoxTitle('REGISTO DIÁRIO DE VIAGENS');

if (empty($registos)) {
    $pdf->SetFont('Arial', 'I', 9);
    $pdf->Cell(0, 10, pt('Nenhum registo encontrado para os filtros selecionados.'), 'L,R,B', 1, 'C');
} else {
    // Cabeçalho da Tabela
    $pdf->SetFont('Arial', 'B', 8);
    $pdf->SetFillColor(248, 249, 250);
    // Larguras (Total = 190mm)
    $w = [18, 20, 35, 20, 18, 18, 18, 43]; 
    
    $pdf->Cell($w[0], 7, pt(' Data'), 'L,B', 0, 'C', true);
    $pdf->Cell($w[1], 7, pt(' Matrícula'), 'L,B', 0, 'C', true);
    $pdf->Cell($w[2], 7, pt(' Colaborador'), 'L,B', 0, 'L', true);
    $pdf->Cell($w[3], 7, pt(' Horário'), 'L,B', 0, 'C', true);
    $pdf->Cell($w[4], 7, pt(' Partida'), 'L,B', 0, 'R', true);
    $pdf->Cell($w[5], 7, pt(' Chegada'), 'L,B', 0, 'R', true);
    $pdf->Cell($w[6], 7, pt(' Dist.'), 'L,B', 0, 'C', true);
    $pdf->Cell($w[7], 7, pt(' Descrição'), 'L,R,B', 1, 'L', true);

    $pdf->SetFont('Arial', '', 8);
    
    foreach ($registos as $r) {
        $distancia = ($r['km_fim'] > 0 && $r['km_fim'] >= $r['km_inicio']) ? ($r['km_fim'] - $r['km_inicio']) : 0;
        
        $data_f = date('d/m', strtotime($r['data_registo']));
        $hora_f = ($r['hora_inicio'] ? date('H:i', strtotime($r['hora_inicio'])) : '-') . ' / ' . ($r['hora_fim'] ? date('H:i', strtotime($r['hora_fim'])) : '-');
        
        // Tratar descrições muito longas limitando caracteres (truncate)
        $desc = mb_substr($r['descricao_viagem'], 0, 30, 'UTF-8');
        if (mb_strlen($r['descricao_viagem'], 'UTF-8') > 30) $desc .= '...';

        // Preenchimento de Linha
        $pdf->Cell($w[0], 6, pt($data_f), 'L,B', 0, 'C');
        
        // Matrícula em Bold
        $pdf->SetFont('Arial', 'B', 8);
        $pdf->Cell($w[1], 6, pt($r['matricula']), 'L,B', 0, 'C');
        $pdf->SetFont('Arial', '', 8);
        
        $pdf->Cell($w[2], 6, pt(explode(' ', $r['nome_colaborador'] ?? '')[0]), 'L,B', 0, 'L');
        $pdf->Cell($w[3], 6, pt($hora_f), 'L,B', 0, 'C');
        
        $pdf->SetTextColor(127, 140, 141);
        $pdf->Cell($w[4], 6, pt($r['km_inicio']), 'L,B', 0, 'R');
        
        $pdf->SetTextColor(44, 62, 80);
        $pdf->Cell($w[5], 6, pt($r['km_fim']), 'L,B', 0, 'R');
        
        $pdf->SetFont('Arial', 'B', 8);
        $pdf->SetTextColor(211, 84, 0); // Laranja para a distância
        $pdf->Cell($w[6], 6, pt('+' . $distancia), 'L,B', 0, 'C');
        
        $pdf->SetFont('Arial', '', 7);
        $pdf->SetTextColor(0, 0, 0);
        $pdf->Cell($w[7], 6, pt($desc), 'L,R,B', 1, 'L');
        $pdf->SetFont('Arial', '', 8);
    }
}

// Limpa qualquer erro e exibe PDF
ob_end_clean();
$filename = 'Relatorio_Viaturas_' . $filtro_ano . '_' . $filtro_mes . '.pdf';
$pdf->Output('I', pt($filename));
?>