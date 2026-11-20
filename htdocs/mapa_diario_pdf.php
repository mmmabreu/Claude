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

// --- MÊS ESCOLHIDO (o mesmo filtro do funcionarios.php) ---
$mes_filtro = filter_input(INPUT_GET, 'mes', FILTER_VALIDATE_INT) ?: (int)date('m');
$ano_filtro = filter_input(INPUT_GET, 'ano', FILTER_VALIDATE_INT) ?: (int)date('Y');
if ($mes_filtro < 1 || $mes_filtro > 12) $mes_filtro = (int)date('m');

$meses = [1 => 'Janeiro', 2 => 'Fevereiro', 3 => 'Março', 4 => 'Abril', 5 => 'Maio', 6 => 'Junho', 7 => 'Julho', 8 => 'Agosto', 9 => 'Setembro', 10 => 'Outubro', 11 => 'Novembro', 12 => 'Dezembro'];
$dias_semana = [1 => 'S', 2 => 'T', 3 => 'Q', 4 => 'Q', 5 => 'S', 6 => 'S', 7 => 'D'];

function calcHoras($entrada, $saida) {
    if (empty($entrada) || empty($saida)) return 0;
    $t1 = strtotime($entrada);
    $t2 = strtotime($saida);
    if ($t2 < $t1) return 0;
    return ($t2 - $t1) / 3600;
}

// =====================================================================
// CÁLCULO (mesmas regras do funcionarios.php)
// =====================================================================

// Feriados e pontes das Configurações
$feriados_array = [];
try {
    $stmt_fer = $pdo->prepare("SELECT valor FROM configuracoes WHERE chave = 'dias_feriados' LIMIT 1");
    $stmt_fer->execute();
    $feriados_json = $stmt_fer->fetchColumn();
    $feriados_array = $feriados_json ? json_decode($feriados_json, true) : [];
    if (!is_array($feriados_array)) $feriados_array = [];
} catch (Exception $e) {}

$dias_no_mes = cal_days_in_month(CAL_GREGORIAN, $mes_filtro, $ano_filtro);
$fins_de_semana = []; $feriados_mes = []; $dia_semana_letra = [];
$dias_uteis = 0;
for ($d = 1; $d <= $dias_no_mes; $d++) {
    $data_str = sprintf("%04d-%02d-%02d", $ano_filtro, $mes_filtro, $d);
    $n = (int)date('N', strtotime($data_str));
    $fins_de_semana[$d] = ($n >= 6);
    $feriados_mes[$d] = in_array($data_str, $feriados_array) && !$fins_de_semana[$d];
    $dia_semana_letra[$d] = $dias_semana[$n];
    if (!$fins_de_semana[$d] && !$feriados_mes[$d]) $dias_uteis++;
}

$funcionarios = [];
$mapa_diario = [];
$totais = ['horas' => 0, 'qtd_almocos' => 0, 'valor_almocos' => 0];
$totais_dia = array_fill(1, $dias_no_mes, 0);

try {
    // 1. Utilizadores ativos e as suas férias (sem o perfil Administrador — não entra na folha de ponto)
    $stmt_users = $pdo->query("SELECT id, nome, perfil, ferias_marcadas FROM utilizadores WHERE ativo = 1 AND perfil != 'admin' AND nome NOT LIKE '%Isabel%' ORDER BY nome ASC");
    while ($row = $stmt_users->fetch(PDO::FETCH_ASSOC)) {
        $funcionarios[$row['id']] = ['nome' => $row['nome'], 'perfil' => $row['perfil'], 'horas' => 0, 'qtd_almocos' => 0, 'valor_almocos' => 0, 'dias_ferias' => 0];

        $dias_de_ferias_deste_mes = [];
        $arr = !empty($row['ferias_marcadas']) ? json_decode($row['ferias_marcadas'], true) : [];
        if (is_array($arr)) {
            foreach ($arr as $periodo) {
                if (empty($periodo['inicio']) || empty($periodo['dias'])) continue;
                try {
                    $data_inicio = new DateTime($periodo['inicio']);
                    for ($i = 0; $i < (int)$periodo['dias']; $i++) {
                        $data_atual = clone $data_inicio;
                        if ($i > 0) $data_atual->modify("+$i days");
                        if ((int)$data_atual->format('m') === $mes_filtro && (int)$data_atual->format('Y') === $ano_filtro) {
                            $dias_de_ferias_deste_mes[(int)$data_atual->format('j')] = true;
                        }
                    }
                } catch (Exception $e) {}
            }
        }

        for ($d = 1; $d <= $dias_no_mes; $d++) {
            $is_ferias = isset($dias_de_ferias_deste_mes[$d]);
            $mapa_diario[$row['id']][$d] = ['horas' => 0, 'almoco' => 0, 'is_ferias' => $is_ferias];
            if ($is_ferias && !$fins_de_semana[$d] && !$feriados_mes[$d]) $funcionarios[$row['id']]['dias_ferias']++;
        }
    }

    // Soma horas/almoço de um colaborador num dia (se não estiver de férias)
    $somar = function ($c, $dia, $horas, $almoco) use (&$funcionarios, &$mapa_diario, &$totais, &$totais_dia) {
        if (empty($c) || !isset($funcionarios[$c]) || !isset($mapa_diario[$c][$dia]) || $mapa_diario[$c][$dia]['is_ferias']) return;
        $funcionarios[$c]['horas'] += $horas;
        $mapa_diario[$c][$dia]['horas'] += $horas;
        $totais['horas'] += $horas;
        $totais_dia[$dia] += $horas;
        if ($almoco > 0) {
            $funcionarios[$c]['qtd_almocos']++;
            $funcionarios[$c]['valor_almocos'] += $almoco;
            $mapa_diario[$c][$dia]['almoco'] += $almoco;
            $totais['qtd_almocos']++;
            $totais['valor_almocos'] += $almoco;
        }
    };

    // 2. Relatórios de obra
    $st = $pdo->prepare("SELECT colaborador_1, colaborador_2, h1_entrada, h1_saida, h2_entrada, h2_saida, custo_refeicao_1, custo_refeicao_2, data_relatorio AS data FROM relatorios_obra WHERE MONTH(data_relatorio) = ? AND YEAR(data_relatorio) = ?");
    $st->execute([$mes_filtro, $ano_filtro]);
    // 3. Serviços
    $st2 = $pdo->prepare("SELECT colaborador_1, colaborador_2, h1_entrada, h1_saida, h2_entrada, h2_saida, custo_refeicao_1, custo_refeicao_2, data_servico AS data FROM servicos WHERE MONTH(data_servico) = ? AND YEAR(data_servico) = ?");
    $st2->execute([$mes_filtro, $ano_filtro]);
    foreach ([$st, $st2] as $stmt) {
        while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $horas = calcHoras($r['h1_entrada'], $r['h1_saida']) + calcHoras($r['h2_entrada'], $r['h2_saida']);
            $dia = (int)date('j', strtotime($r['data']));
            $somar($r['colaborador_1'], $dia, $horas, (float)($r['custo_refeicao_1'] ?? 0));
            $somar($r['colaborador_2'], $dia, $horas, (float)($r['custo_refeicao_2'] ?? 0));
        }
    }

    // 4. Visitas (1 hora por visita)
    $st3 = $pdo->prepare("SELECT colaborador_1, data_visita FROM visitas WHERE MONTH(data_visita) = ? AND YEAR(data_visita) = ?");
    $st3->execute([$mes_filtro, $ano_filtro]);
    while ($v = $st3->fetch(PDO::FETCH_ASSOC)) {
        $somar($v['colaborador_1'], (int)date('j', strtotime($v['data_visita'])), 1, 0);
    }
} catch (Exception $e) {
    ob_end_clean();
    die("Erro na BD: " . htmlspecialchars($e->getMessage()));
}

// =====================================================================
// PDF
// =====================================================================
function pt($texto) {
    return mb_convert_encoding((string)($texto ?? ''), 'windows-1252', 'UTF-8');
}
function num1($v) { return number_format((float)$v, 1, ',', ''); }
function num2($v) { return number_format((float)$v, 2, ',', '.'); }

class PDF extends FPDF {
    public $titulo_doc;
    public $subtitulo_doc;
    public $desenhar_cabecalho_tabela = null; // função que repete o cabeçalho da grelha

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
        $this->SetDrawColor(52, 73, 94);
        $this->SetLineWidth(0.6);
        $this->Line(10, 25, 287, 25);
        $this->SetY(28);
        if ($this->desenhar_cabecalho_tabela) call_user_func($this->desenhar_cabecalho_tabela, $this);
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
}

// Larguras (A4 horizontal: 277 mm úteis)
$w_nome = 42;
$w_horas = 15;
$w_alm = 20;
$w_dia = ($dias_no_mes > 0) ? (277 - $w_nome - $w_horas - $w_alm) / $dias_no_mes : 6;
$h_linha = 9;

// Cores
$cor_fds = [241, 242, 246];
$cor_feriado = [252, 243, 207];
$cor_ferias = [234, 250, 241];

$cabecalho = function ($pdf) use ($w_nome, $w_horas, $w_alm, $w_dia, $dias_no_mes, $fins_de_semana, $feriados_mes, $dia_semana_letra, $cor_fds, $cor_feriado) {
    $pdf->SetDrawColor(210, 214, 220);
    $pdf->SetLineWidth(0.15);
    $pdf->SetTextColor(87, 101, 116);
    $y = $pdf->GetY();
    // Nome
    $pdf->SetFillColor(236, 239, 243);
    $pdf->SetFont('Arial', 'B', 7.5);
    $pdf->Cell($w_nome, 9, pt('Colaborador'), 1, 0, 'L', true);
    // Dias (número + letra do dia da semana)
    for ($d = 1; $d <= $dias_no_mes; $d++) {
        $x = $pdf->GetX();
        $c = $feriados_mes[$d] ? $cor_feriado : ($fins_de_semana[$d] ? $cor_fds : [248, 249, 251]);
        $pdf->SetFillColor($c[0], $c[1], $c[2]);
        $pdf->Rect($x, $y, $w_dia, 9, 'DF');
        $pdf->SetFont('Arial', 'B', 7);
        $pdf->SetXY($x, $y + 0.8);
        $pdf->Cell($w_dia, 4, sprintf('%02d', $d), 0, 0, 'C');
        $pdf->SetFont('Arial', '', 5.5);
        $pdf->SetXY($x, $y + 4.6);
        $pdf->Cell($w_dia, 3.5, $dia_semana_letra[$d], 0, 0, 'C');
        $pdf->SetXY($x + $w_dia, $y);
    }
    $pdf->SetFillColor(236, 239, 243);
    $pdf->SetFont('Arial', 'B', 7.5);
    $pdf->Cell($w_horas, 9, pt('Horas'), 1, 0, 'C', true);
    $pdf->Cell($w_alm, 9, pt('Almoços'), 1, 1, 'C', true);
    $pdf->SetTextColor(0, 0, 0);
};

$pdf = new PDF('L', 'mm', 'A4');
$pdf->titulo_doc = 'FOLHA DE PONTO / MAPA DIÁRIO';
$pdf->subtitulo_doc = $meses[$mes_filtro] . ' ' . $ano_filtro . ' | ' . count($funcionarios) . ' colaborador(es) | ' . $dias_uteis . ' dias úteis';
$pdf->desenhar_cabecalho_tabela = $cabecalho;
$pdf->SetMargins(10, 10, 10);
$pdf->AliasNbPages();
$pdf->SetAutoPageBreak(true, 15);
$pdf->AddPage();

if (empty($funcionarios)) {
    $pdf->SetFont('Arial', 'I', 10);
    $pdf->Cell(0, 10, pt('Nenhum funcionário ativo encontrado.'), 0, 1, 'C');
} else {
    $fundo = false;
    foreach ($funcionarios as $id => $f) {
        $pdf->Espaco($h_linha);
        $y = $pdf->GetY();
        $x = 10;
        $pdf->SetDrawColor(210, 214, 220);
        $pdf->SetLineWidth(0.15);

        // Nome
        $pdf->SetFillColor($fundo ? 250 : 255, $fundo ? 251 : 255, $fundo ? 252 : 255);
        $pdf->Rect($x, $y, $w_nome, $h_linha, 'DF');
        $pdf->SetXY($x + 1, $y + 1.2);
        $pdf->SetFont('Arial', 'B', 7.5);
        $pdf->SetTextColor(44, 62, 80);
        $pdf->Cell($w_nome - 2, 3.6, $pdf->Cortar($f['nome'], $w_nome - 1), 0, 0, 'L');
        $pdf->SetXY($x + 1, $y + 4.8);
        $pdf->SetFont('Arial', '', 6);
        $pdf->SetTextColor(127, 140, 141);
        $pdf->Cell($w_nome - 2, 3, pt(ucfirst((string)$f['perfil']) . ($f['dias_ferias'] ? ' | ' . $f['dias_ferias'] . ' dia(s) férias' : '')), 0, 0, 'L');
        $x += $w_nome;

        // Dias
        for ($d = 1; $d <= $dias_no_mes; $d++) {
            $dd = $mapa_diario[$id][$d];
            $h = $dd['horas']; $a = $dd['almoco'];
            if ($dd['is_ferias']) $c = $cor_ferias;
            elseif ($feriados_mes[$d]) $c = $cor_feriado;
            elseif ($fins_de_semana[$d]) $c = $cor_fds;
            else $c = $fundo ? [250, 251, 252] : [255, 255, 255];
            $pdf->SetFillColor($c[0], $c[1], $c[2]);
            $pdf->Rect($x, $y, $w_dia, $h_linha, 'DF');

            if ($dd['is_ferias']) {
                $pdf->SetFont('Arial', 'B', 5.5);
                $pdf->SetTextColor(39, 174, 96);
                $pdf->SetXY($x, $y + 3);
                $pdf->Cell($w_dia, 3, pt('FÉR'), 0, 0, 'C');
            } else {
                if ($h > 0) {
                    $pdf->SetFont('Arial', 'B', 6.5);
                    $pdf->SetTextColor(41, 128, 185);
                    $pdf->SetXY($x, $y + 1.3);
                    $pdf->Cell($w_dia, 3.4, num1($h), 0, 0, 'C');
                } elseif ($feriados_mes[$d]) {
                    $pdf->SetFont('Arial', 'B', 5.5);
                    $pdf->SetTextColor(243, 156, 18);
                    $pdf->SetXY($x, $y + 1.6);
                    $pdf->Cell($w_dia, 3, pt('FER'), 0, 0, 'C');
                } elseif (!$fins_de_semana[$d]) {
                    $pdf->SetFont('Arial', '', 6);
                    $pdf->SetTextColor(200, 204, 210);
                    $pdf->SetXY($x, $y + 1.6);
                    $pdf->Cell($w_dia, 3, '-', 0, 0, 'C');
                }
                if ($a > 0) {
                    $pdf->SetFont('Arial', '', 5);
                    $pdf->SetTextColor(211, 84, 0);
                    $pdf->SetXY($x, $y + 5);
                    $pdf->Cell($w_dia, 3, number_format($a, 2, ',', ''), 0, 0, 'C');
                }
            }
            $x += $w_dia;
        }

        // Totais do colaborador
        $pdf->SetFillColor(244, 246, 248);
        $pdf->Rect($x, $y, $w_horas, $h_linha, 'DF');
        $pdf->SetXY($x, $y + 2.6);
        $pdf->SetFont('Arial', 'B', 8);
        $pdf->SetTextColor(41, 128, 185);
        $pdf->Cell($w_horas, 4, num1($f['horas']) . 'h', 0, 0, 'C');
        $x += $w_horas;
        $pdf->Rect($x, $y, $w_alm, $h_linha, 'DF');
        $pdf->SetXY($x, $y + 1.3);
        $pdf->SetFont('Arial', 'B', 7.5);
        $pdf->SetTextColor(211, 84, 0);
        $pdf->Cell($w_alm, 3.6, num2($f['valor_almocos']) . ' ' . chr(128), 0, 0, 'C');
        $pdf->SetXY($x, $y + 5);
        $pdf->SetFont('Arial', '', 6);
        $pdf->SetTextColor(127, 140, 141);
        $pdf->Cell($w_alm, 3, $f['qtd_almocos'] . pt(' refeição(ões)'), 0, 0, 'C');

        $pdf->SetXY(10, $y + $h_linha);
        $fundo = !$fundo;
    }

    // Linha de totais por dia
    $pdf->Espaco(8);
    $y = $pdf->GetY();
    $x = 10;
    $pdf->SetFillColor(236, 239, 243);
    $pdf->SetDrawColor(180, 186, 194);
    $pdf->Rect($x, $y, $w_nome, 8, 'DF');
    $pdf->SetXY($x + 1, $y + 2);
    $pdf->SetFont('Arial', 'B', 7.5);
    $pdf->SetTextColor(44, 62, 80);
    $pdf->Cell($w_nome - 2, 4, pt('TOTAL HORAS / DIA'), 0, 0, 'L');
    $x += $w_nome;
    for ($d = 1; $d <= $dias_no_mes; $d++) {
        $pdf->Rect($x, $y, $w_dia, 8, 'DF');
        if ($totais_dia[$d] > 0) {
            $pdf->SetXY($x, $y + 2.2);
            $pdf->SetFont('Arial', 'B', 6);
            $pdf->SetTextColor(41, 128, 185);
            $pdf->Cell($w_dia, 3.6, num1($totais_dia[$d]), 0, 0, 'C');
        }
        $x += $w_dia;
    }
    $pdf->Rect($x, $y, $w_horas, 8, 'DF');
    $pdf->SetXY($x, $y + 2);
    $pdf->SetFont('Arial', 'B', 8);
    $pdf->SetTextColor(41, 128, 185);
    $pdf->Cell($w_horas, 4, num1($totais['horas']) . 'h', 0, 0, 'C');
    $x += $w_horas;
    $pdf->Rect($x, $y, $w_alm, 8, 'DF');
    $pdf->SetXY($x, $y + 2);
    $pdf->SetTextColor(211, 84, 0);
    $pdf->Cell($w_alm, 4, num2($totais['valor_almocos']) . ' ' . chr(128), 0, 0, 'C');
    $pdf->SetXY(10, $y + 8);

    // Linha da MÉDIA de horas por funcionário em cada dia
    // = horas do dia / nº de funcionários que trabalharam nesse dia (com horas registadas)
    $trab_dia = array_fill(1, $dias_no_mes, 0);
    foreach ($mapa_diario as $id_f => $dias_f) {
        for ($d = 1; $d <= $dias_no_mes; $d++) {
            if ($dias_f[$d]['horas'] > 0) $trab_dia[$d]++;
        }
    }
    $dias_pessoa = array_sum($trab_dia); // total de "dias de trabalho" (funcionário x dia)

    $pdf->Espaco(8);
    $y = $pdf->GetY();
    $x = 10;
    $pdf->SetFillColor(248, 249, 251);
    $pdf->SetDrawColor(180, 186, 194);
    $pdf->Rect($x, $y, $w_nome, 8, 'DF');
    $pdf->SetXY($x + 1, $y + 0.9);
    $pdf->SetFont('Arial', 'B', 7.5);
    $pdf->SetTextColor(44, 62, 80);
    $pdf->Cell($w_nome - 2, 3.6, pt('MÉDIA HORAS / FUNC.'), 0, 0, 'L');
    $pdf->SetXY($x + 1, $y + 4.4);
    $pdf->SetFont('Arial', '', 5.5);
    $pdf->SetTextColor(127, 140, 141);
    $pdf->Cell($w_nome - 2, 2.8, pt('horas do dia ÷ nº a trabalhar'), 0, 0, 'L');
    $x += $w_nome;
    for ($d = 1; $d <= $dias_no_mes; $d++) {
        $pdf->Rect($x, $y, $w_dia, 8, 'DF');
        if ($trab_dia[$d] > 0) {
            $pdf->SetXY($x, $y + 1.1);
            $pdf->SetFont('Arial', 'B', 6);
            $pdf->SetTextColor(22, 160, 133);
            $pdf->Cell($w_dia, 3.4, num1($totais_dia[$d] / $trab_dia[$d]), 0, 0, 'C');
            $pdf->SetXY($x, $y + 4.6);
            $pdf->SetFont('Arial', '', 4.8);
            $pdf->SetTextColor(149, 165, 166);
            $pdf->Cell($w_dia, 2.6, $trab_dia[$d] . 'f', 0, 0, 'C');
        }
        $x += $w_dia;
    }
    // Média do mês = total de horas ÷ total de dias trabalhados (por funcionário)
    $pdf->Rect($x, $y, $w_horas, 8, 'DF');
    $pdf->SetXY($x, $y + 2);
    $pdf->SetFont('Arial', 'B', 8);
    $pdf->SetTextColor(22, 160, 133);
    $pdf->Cell($w_horas, 4, $dias_pessoa > 0 ? num1($totais['horas'] / $dias_pessoa) . 'h' : '-', 0, 0, 'C');
    $x += $w_horas;
    $pdf->Rect($x, $y, $w_alm, 8, 'DF');
    $pdf->SetXY($x, $y + 1.2);
    $pdf->SetFont('Arial', '', 5.5);
    $pdf->SetTextColor(127, 140, 141);
    $pdf->Cell($w_alm, 3, pt('média do mês'), 0, 0, 'C');
    $pdf->SetXY($x, $y + 4.3);
    $pdf->Cell($w_alm, 3, pt($dias_pessoa . ' dias trab.'), 0, 0, 'C');
    $pdf->SetXY(10, $y + 8);

    // Legenda e resumo
    $pdf->desenhar_cabecalho_tabela = null;
    $pdf->Espaco(16);
    $pdf->Ln(4);
    $y = $pdf->GetY();
    $legenda = [
        [$cor_fds, 'Fim de semana'],
        [$cor_feriado, 'FER = Feriado / Ponte / Fecho'],
        [$cor_ferias, 'FÉR = Férias (horas não contam)'],
    ];
    $x = 10;
    $pdf->SetFont('Arial', '', 7.5);
    $pdf->SetDrawColor(200, 200, 200);
    foreach ($legenda as [$c, $txt]) {
        $pdf->SetFillColor($c[0], $c[1], $c[2]);
        $pdf->Rect($x, $y + 0.5, 4, 4, 'DF');
        $pdf->SetXY($x + 5, $y);
        $pdf->SetTextColor(87, 101, 116);
        $pdf->Cell(52, 5, pt($txt), 0, 0, 'L');
        $x += 58;
    }
    $pdf->SetXY(10, $y + 6);
    $pdf->SetFont('Arial', 'I', 7);
    $pdf->SetTextColor(127, 140, 141);
    $pdf->Cell(0, 4, pt('Horas = relatórios de obra + serviços (entrada/saída) + 1 h por visita. Em cada dia: horas em cima, valor do almoço (€) em baixo.'), 0, 1, 'L');
    $pdf->SetX(10);
    $pdf->Cell(0, 4, pt('Média horas / func. = horas do dia ÷ nº de funcionários com horas nesse dia (o "f" indica quantos trabalharam).'), 0, 1, 'L');

    $pdf->SetXY(167, $y);
    $pdf->SetFont('Arial', 'B', 9);
    $pdf->SetTextColor(44, 62, 80);
    $pdf->Cell(120, 5, pt('Total do mês: ' . num1($totais['horas']) . ' h  |  ' . $totais['qtd_almocos'] . ' almoços  |  ' . num2($totais['valor_almocos']) . ' €'), 0, 1, 'R');
}

ob_end_clean();
$pdf->Output('I', 'Mapa_Diario_' . sprintf('%04d_%02d', $ano_filtro, $mes_filtro) . '.pdf');
?>
