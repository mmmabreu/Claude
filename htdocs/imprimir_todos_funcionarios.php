<?php
// REGRA DE OURO: Limpar o buffer para evitar erros de ZIP/PDF
ob_start();
session_start();
require_once 'db.php';
require_once('fpdf/fpdf.php');

if (!isset($_SESSION['user_id']) || ($_SESSION['perfil'] !== 'admin' && $_SESSION['perfil'] !== 'administrativo')) {
    ob_end_clean(); die("Acesso negado.");
}

$mes = filter_input(INPUT_GET, 'mes', FILTER_VALIDATE_INT) ?: (int)date('m');
$ano = filter_input(INPUT_GET, 'ano', FILTER_VALIDATE_INT) ?: (int)date('Y');

$meses_nome = [1 => 'Janeiro', 2 => 'Fevereiro', 3 => 'Março', 4 => 'Abril', 5 => 'Maio', 6 => 'Junho', 7 => 'Julho', 8 => 'Agosto', 9 => 'Setembro', 10 => 'Outubro', 11 => 'Novembro', 12 => 'Dezembro'];

function calcHoras($entrada, $saida) {
    if (empty($entrada) || empty($saida)) return 0;
    $t1 = strtotime($entrada);
    $t2 = strtotime($saida);
    if ($t2 < $t1) return 0;
    return ($t2 - $t1) / 3600;
}

function pt($texto) {
    return mb_convert_encoding((string)($texto ?? ''), 'windows-1252', 'UTF-8');
}

class PDF extends FPDF {
    public $nome_func;
    public $periodo;

    function Header() {
        if (file_exists('mab.png')) {
            $this->Image('mab.png', 10, 8, 20);
        }
        $this->SetY(10);
        $this->SetFont('Arial', 'B', 14);
        $this->SetTextColor(44, 62, 80);
        $this->Cell(0, 6, pt('RESUMO MENSAL'), 0, 1, 'R');
        $this->SetFont('Arial', '', 9);
        $this->SetTextColor(127, 140, 141);
        $this->Cell(0, 5, pt('m. Abreu - Instalações Técnicas, Lda.'), 0, 1, 'R');

        $this->Ln(6);
        $this->SetDrawColor(44, 62, 80);
        $this->SetLineWidth(0.5);
        $this->Line(10, $this->GetY(), 200, $this->GetY());
        $this->Ln(3);

        $this->SetFont('Arial', 'B', 10);
        $this->SetTextColor(44, 62, 80);
        $this->Cell(25, 6, pt('Colaborador: '), 0, 0, 'L');
        $this->SetFont('Arial', '', 10);
        $this->Cell(95, 6, pt($this->nome_func), 0, 0, 'L');

        $this->SetFont('Arial', 'B', 10);
        $this->Cell(20, 6, pt('Mês/Ano: '), 0, 0, 'L');
        $this->SetFont('Arial', '', 10);
        $this->Cell(0, 6, pt($this->periodo), 0, 1, 'L');
        $this->Ln(3);
    }

    function Footer() {
        $this->SetY(-15);
        $this->SetDrawColor(44, 62, 80);
        $this->SetLineWidth(0.5);
        $this->Line(10, $this->GetY(), 200, $this->GetY());
        $this->Ln(2);
        $this->SetFont('Arial', '', 8);
        $this->SetTextColor(127, 140, 141);
        $this->Cell(0, 4, pt('Documento Interno - m. Abreu - Instalações Técnicas, Lda.'), 0, 0, 'L');
        $this->Cell(0, 4, pt('Página ') . $this->PageNo() . '/{nb}', 0, 1, 'R');
    }
}

// ---------------------------------------------------------
// PREPARAÇÃO ZIP
// ---------------------------------------------------------
$zip = new ZipArchive();
$zip_filename = tempnam(sys_get_temp_dir(), 'zip') . '.zip';
if ($zip->open($zip_filename, ZipArchive::CREATE) !== TRUE) {
    ob_end_clean();
    die("Erro ao criar o ficheiro ZIP.");
}

$dias_no_mes = cal_days_in_month(CAL_GREGORIAN, $mes, $ano);

$stmt_fer = $pdo->prepare("SELECT valor FROM configuracoes WHERE chave = 'dias_feriados' LIMIT 1");
$stmt_fer->execute();
$feriados_json = $stmt_fer->fetchColumn();
$feriados_array = $feriados_json ? json_decode($feriados_json, true) : [];
if (!is_array($feriados_array)) $feriados_array = [];

$stmt_users = $pdo->query("SELECT id, nome, ordenado_base, sub_alimentacao, ferias_marcadas FROM utilizadores WHERE ativo = 1 AND nome NOT LIKE '%Isabel%' ORDER BY nome ASC");
$funcionarios = $stmt_users->fetchAll(PDO::FETCH_ASSOC);

if (empty($funcionarios)) {
    ob_end_clean();
    die("Nenhum funcionário encontrado.");
}

// ---------------------------------------------------------
// GERAR DADOS E PDF PARA CADA FUNCIONÁRIO
// ---------------------------------------------------------
foreach ($funcionarios as $func) {
    $id_func = $func['id'];
    $nome_funcionario = $func['nome'];

    $ferias_json = $func['ferias_marcadas'];
    $ferias_array = [];
    if (!empty($ferias_json)) {
        $arr = json_decode($ferias_json, true);
        if (is_array($arr)) $ferias_array = $arr;
    }

    $dias_de_ferias_deste_mes = [];
    foreach ($ferias_array as $periodo) {
        if (!empty($periodo['inicio']) && !empty($periodo['dias'])) {
            try {
                $data_inicio = new DateTime($periodo['inicio']);
                $num_dias = (int)$periodo['dias'];
                for ($i = 0; $i < $num_dias; $i++) {
                    $data_atual = clone $data_inicio;
                    if ($i > 0) $data_atual->modify("+$i days");
                    if ((int)$data_atual->format('m') === $mes && (int)$data_atual->format('Y') === $ano) {
                        $dia_feria = (int)$data_atual->format('j');
                        $dias_de_ferias_deste_mes[$dia_feria] = true;
                    }
                }
            } catch (Exception $e) { }
        }
    }

    $dias_uteis_trabalho = 0;
    for ($d = 1; $d <= $dias_no_mes; $d++) {
        $data_str = sprintf("%04d-%02d-%02d", $ano, $mes, $d);
        $dia_semana = date('N', strtotime($data_str));
        if ($dia_semana < 6 && !isset($dias_de_ferias_deste_mes[$d]) && !in_array($data_str, $feriados_array)) {
            $dias_uteis_trabalho++;
        }
    }

    $horas_esperadas = $dias_uteis_trabalho * 7;
    $horas_lei = $dias_uteis_trabalho * 8;

    $mapa_diario = [];
    for ($d = 1; $d <= $dias_no_mes; $d++) {
        $mapa_diario[$d] = ['horas' => 0, 'almoco' => 0];
    }
    $totais = ['horas' => 0, 'almoco' => 0];

    // 1. Relatórios de Obra
    $stmt_rel = $pdo->prepare("SELECT h1_entrada, h1_saida, h2_entrada, h2_saida, custo_refeicao_1, custo_refeicao_2, data_relatorio, colaborador_1, colaborador_2 FROM relatorios_obra WHERE MONTH(data_relatorio) = ? AND YEAR(data_relatorio) = ? AND (colaborador_1 = ? OR colaborador_2 = ?)");
    $stmt_rel->execute([$mes, $ano, $id_func, $id_func]);
    while ($r = $stmt_rel->fetch(PDO::FETCH_ASSOC)) {
        $horas = calcHoras($r['h1_entrada'], $r['h1_saida']) + calcHoras($r['h2_entrada'], $r['h2_saida']);
        $dia = (int)date('j', strtotime($r['data_relatorio']));
        if (isset($dias_de_ferias_deste_mes[$dia])) continue;
        if ($r['colaborador_1'] == $id_func) {
            $mapa_diario[$dia]['horas'] += $horas;
            $mapa_diario[$dia]['almoco'] += (float)($r['custo_refeicao_1'] ?? 0);
        } else if ($r['colaborador_2'] == $id_func) {
            $mapa_diario[$dia]['horas'] += $horas;
            $mapa_diario[$dia]['almoco'] += (float)($r['custo_refeicao_2'] ?? 0);
        }
    }

    // 2. Serviços
    $stmt_srv = $pdo->prepare("SELECT h1_entrada, h1_saida, h2_entrada, h2_saida, custo_refeicao_1, custo_refeicao_2, data_servico, colaborador_1, colaborador_2 FROM servicos WHERE MONTH(data_servico) = ? AND YEAR(data_servico) = ? AND (colaborador_1 = ? OR colaborador_2 = ?)");
    $stmt_srv->execute([$mes, $ano, $id_func, $id_func]);
    while ($s = $stmt_srv->fetch(PDO::FETCH_ASSOC)) {
        $horas = calcHoras($s['h1_entrada'], $s['h1_saida']) + calcHoras($s['h2_entrada'], $s['h2_saida']);
        $dia = (int)date('j', strtotime($s['data_servico']));
        if (isset($dias_de_ferias_deste_mes[$dia])) continue;
        if ($s['colaborador_1'] == $id_func) {
            $mapa_diario[$dia]['horas'] += $horas;
            $mapa_diario[$dia]['almoco'] += (float)($s['custo_refeicao_1'] ?? 0);
        } else if ($s['colaborador_2'] == $id_func) {
            $mapa_diario[$dia]['horas'] += $horas;
            $mapa_diario[$dia]['almoco'] += (float)($s['custo_refeicao_2'] ?? 0);
        }
    }

    // 3. Visitas
    $stmt_vis = $pdo->prepare("SELECT data_visita FROM visitas WHERE MONTH(data_visita) = ? AND YEAR(data_visita) = ? AND colaborador_1 = ?");
    $stmt_vis->execute([$mes, $ano, $id_func]);
    while ($v = $stmt_vis->fetch(PDO::FETCH_ASSOC)) {
        $dia = (int)date('j', strtotime($v['data_visita']));
        if (isset($dias_de_ferias_deste_mes[$dia])) continue;
        $mapa_diario[$dia]['horas'] += 1;
    }

    foreach ($mapa_diario as $d => $dados) {
        $totais['horas'] += $dados['horas'];
        $totais['almoco'] += $dados['almoco'];
    }

    $ordenado_base = (float)$func['ordenado_base'];
    $sub_alimentacao_diario = (float)$func['sub_alimentacao'];

    $dias_ferias_mes = count($dias_de_ferias_deste_mes);
    $ordenado_mensal = $ordenado_base;
    if ($dias_ferias_mes > 0) {
        $ordenado_mensal = $ordenado_base - (($ordenado_base / 30) * $dias_ferias_mes);
    }

    $sub_alimentacao_total = $sub_alimentacao_diario * $dias_uteis_trabalho;
    $total_receber_mes = $ordenado_mensal + $sub_alimentacao_total + $totais['almoco'];

    // String de Horas limpa: Obra | 7h | 8h
    $str_horas_final = number_format($totais['horas'], 1, ',', '') . 'h | ' . $horas_esperadas . 'h | ' . $horas_lei . 'h';

    // GERAR PDF PARA ESTE FUNCIONÁRIO
    $pdf = new PDF();
    $pdf->nome_func = $nome_funcionario;
    $pdf->periodo = $meses_nome[$mes] . " " . $ano;
    $pdf->AliasNbPages();
    $pdf->AddPage();
    $pdf->SetAutoPageBreak(false);

    $pdf->SetFont('Arial', 'B', 9);
    $pdf->SetFillColor(241, 242, 246);
    $pdf->Cell(0, 6, pt(' RESUMO OPERACIONAL E FINANCEIRO DO MÊS'), 1, 1, 'C', true);

    $pdf->SetFont('Arial', 'B', 7);
    $pdf->Cell(95, 5, pt('Horas (Obra | 7 h/dia | 8h/dia)'), 1, 0, 'C');
    $pdf->Cell(95, 5, pt('Refeições Pagas em Obra'), 1, 1, 'C');

    $pdf->SetFont('Arial', 'B', 9); 
    $pdf->Cell(95, 6, pt($str_horas_final), 1, 0, 'C');
    $pdf->SetFont('Arial', '', 9);
    $pdf->Cell(95, 6, number_format($totais['almoco'], 2, ',', '') . ' ' . chr(128), 1, 1, 'C');

    $pdf->SetFont('Arial', 'B', 7);
    $pdf->Cell(95, 5, pt('Ordenado (Mensal)'), 1, 0, 'C');
    $pdf->Cell(95, 5, pt('Sub. Alimentação (Total do Mês)'), 1, 1, 'C');

    $pdf->SetFont('Arial', '', 9);
    $pdf->Cell(95, 6, number_format($ordenado_mensal, 2, ',', '') . ' ' . chr(128), 1, 0, 'C');
    $pdf->Cell(95, 6, number_format($sub_alimentacao_total, 2, ',', '') . ' ' . chr(128), 1, 1, 'C');

    $pdf->SetFont('Arial', 'B', 8);
    $pdf->SetFillColor(234, 250, 241); 
    $pdf->Cell(126, 7, pt('TOTAL MÊS: '), 1, 0, 'R', true);

    $pdf->SetFont('Arial', 'B', 10);
    $pdf->SetTextColor(39, 174, 96); 
    $pdf->Cell(64, 7, number_format($total_receber_mes, 2, ',', '') . ' ' . chr(128), 1, 1, 'C', true);

    $pdf->SetTextColor(44, 62, 80);
    $pdf->Ln(4);

    $pdf->SetFont('Arial', 'B', 9);
    $pdf->SetFillColor(241, 242, 246);
    $pdf->Cell(0, 6, pt(' REGISTO DIÁRIO DETALHADO'), 1, 1, 'L', true);

    $pdf->SetFont('Arial', 'B', 8);
    $pdf->Cell(63, 5, pt('Data'), 1, 0, 'C');
    $pdf->Cell(63, 5, pt('Horas'), 1, 0, 'C');
    $pdf->Cell(64, 5, pt('Almoço (€)'), 1, 1, 'C');

    $pdf->SetFont('Arial', '', 8);
    $h_row = 5; 

    for ($d = 1; $d <= $dias_no_mes; $d++) {
        $data_str = sprintf("%04d-%02d-%02d", $ano, $mes, $d);
        $dia_semana = date('N', strtotime($data_str));
        $is_fim_semana = ($dia_semana >= 6);
        $is_feriado = in_array($data_str, $feriados_array);
        $is_ferias = isset($dias_de_ferias_deste_mes[$d]);
        
        $dados = $mapa_diario[$d];
        
        if ($is_fim_semana) {
            $pdf->SetFillColor(245, 245, 245);
            $pdf->SetTextColor(150, 150, 150);
            $fill = true;
        } else {
            $pdf->SetFillColor(255, 255, 255);
            $pdf->SetTextColor(44, 62, 80);
            $fill = false;
        }
        
        if ($is_feriado && !$is_fim_semana) {
            $pdf->SetFillColor(252, 243, 207); 
            $pdf->SetTextColor(243, 156, 18);  
            $fill = true;
        } elseif ($is_ferias && !$is_fim_semana) {
            $pdf->SetFillColor(234, 250, 241); 
            $pdf->SetTextColor(39, 174, 96);   
            $fill = true;
        }
        
        $str_horas = $dados['horas'] > 0 ? number_format($dados['horas'], 1, ',', '') : '-';
        $str_alm = $dados['almoco'] > 0 ? number_format($dados['almoco'], 2, ',', '') : '-';

        if ($is_feriado && !$is_fim_semana) {
            $str_horas = pt('FERIADO');
        } elseif ($is_ferias && !$is_fim_semana) {
            $str_horas = pt('FÉRIAS');
        }

        if (!$is_fim_semana && !$is_ferias && !$is_feriado) {
            if ($dados['horas'] > 0) $pdf->SetTextColor(41, 128, 185); 
            else $pdf->SetTextColor(189, 195, 199);
        }

        $pdf->Cell(63, $h_row, sprintf("%02d/%02d/%04d", $d, $mes, $ano), 1, 0, 'C', $fill);
        $pdf->Cell(63, $h_row, $str_horas, 1, 0, 'C', $fill);
        
        if (!$is_fim_semana && !$is_ferias && !$is_feriado) {
            if ($dados['almoco'] > 0) $pdf->SetTextColor(211, 84, 0); 
            else $pdf->SetTextColor(189, 195, 199);
        }
        $pdf->Cell(64, $h_row, $str_alm, 1, 1, 'C', $fill);
    }

    $pdf->SetFont('Arial', 'B', 8);
    $pdf->SetFillColor(241, 242, 246);
    $pdf->SetTextColor(44, 62, 80);
    $pdf->Cell(63, 6, pt('TOTAIS MENSAIS'), 1, 0, 'C', true);
    $pdf->Cell(63, 6, pt($str_horas_final), 1, 0, 'C', true);
    $pdf->Cell(64, 6, number_format($totais['almoco'], 2, ',', '') . ' ' . chr(128), 1, 1, 'C', true);

    $pdf->SetY(255); 
    $pdf->SetTextColor(44, 62, 80);
    $pdf->SetDrawColor(44, 62, 80);
    $y = $pdf->GetY();

    $pdf->Line(20, $y, 80, $y);
    $pdf->SetXY(20, $y + 2);
    $pdf->SetFont('Arial', '', 9);
    $pdf->Cell(60, 5, pt('A Gerência'), 0, 0, 'C');

    $pdf->Line(110, $y, 190, $y);
    $pdf->SetXY(110, $y + 2);
    $pdf->Cell(80, 5, pt('O Colaborador'), 0, 0, 'C');
    $pdf->SetXY(110, $y + 7);
    $pdf->SetFont('Arial', 'B', 8);
    $pdf->Cell(80, 4, pt($nome_funcionario), 0, 1, 'C');

    // ---------------------------------------------------------
    // SALVAR O CONTEÚDO DO PDF NO ZIP
    // ---------------------------------------------------------
    $pdf_content = $pdf->Output('S');
    $nome_ficheiro = 'Resumo_' . preg_replace('/[^A-Za-z0-9_-]/', '_', $nome_funcionario) . '_' . $mes . '_' . $ano . '.pdf';
    $zip->addFromString($nome_ficheiro, $pdf_content);
}

$zip->close();

// Forçar o download do ZIP
ob_end_clean();
header('Content-Type: application/zip');
header('Content-disposition: attachment; filename=Resumos_Funcionarios_'.$meses_nome[$mes].'_'.$ano.'.zip');
header('Content-Length: ' . filesize($zip_filename));
readfile($zip_filename);
unlink($zip_filename); // Apagar o arquivo temporário
exit;
?>