<?php
session_start();
require_once 'db.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}

$perfil_user = $_SESSION['perfil'] ?? '';
$is_admin = in_array($perfil_user, ['admin', 'administrativo']);

if (!$is_admin) {
    header("Location: index.php?erro=sem_permissao");
    exit;
}

// Funções auxiliares de formatação (iguais às da gestao.php)
function formatNumberToPT($num, $dec = 2) {
    if ($num === null || $num === '') return '';
    return number_format((float)$num, $dec, ',', ' ');
}
function formatHoras($horas_dec) {
    $horas_dec = (float)$horas_dec;
    $h = floor($horas_dec);
    $m = round(($horas_dec - $h) * 60);
    if ($m == 60) { $h++; $m = 0; }
    return $h . 'h ' . str_pad($m, 2, '0', STR_PAD_LEFT) . 'm';
}

$EMPRESA_NOME   = 'm.Abreu – Instalações Técnicas, Unipessoal, Lda.';
$EMPRESA_RODAPE = 'Rua Damião de Góis, 205 | 4700-028 Braga | NIF: 514414120 | geral@mabreu.pt | +351 912 854 950';

$obra_selecionada = filter_input(INPUT_GET, 'id_obra', FILTER_VALIDATE_INT);
if (!$obra_selecionada) {
    die("Obra não especificada.");
}

// --- DADOS DA OBRA / CLIENTE ---
$stmt_obra = $pdo->prepare("SELECT o.nome, o.referencia_obra, o.estado, o.morada, c.nome AS nome_cliente
                             FROM obras o LEFT JOIN clientes c ON o.id_cliente = c.id
                             WHERE o.id = ?");
$stmt_obra->execute([$obra_selecionada]);
$obra_info = $stmt_obra->fetch(PDO::FETCH_ASSOC);

if (!$obra_info) {
    die("Obra não encontrada.");
}

// Valores por defeito
$mensagem_erro = null;

$tem_orcamento = false;
$valor_adjudicado = 0;

$tem_auto = false;
$valor_total_autos = 0;

$refeicoes_por_funcionario = [];
$total_refeicoes = 0;

$horas_normais_por_funcionario = [];
$total_horas_normais = 0;
$valor_horas_normais = 0;

$deslocacoes_por_funcionario = [];
$total_deslocacoes_valor = 0;

$mapa_dias = [];

$despesas_manuais = [];
$total_despesas_manuais = 0;

$visitas_obra = [];
$total_visitas = 0;

// --- BUSCAR OS DADOS FINANCEIROS DA OBRA (mesma lógica da gestao.php) ---
try {
    // 1. ORÇAMENTO ADJUDICADO
    // A ligação entre obra e orçamento é feita pelo número de referência único
    // que percorre o sistema (visita -> orçamento -> obra): obras.referencia_obra = orcamentos.numero_orcamento
    $stmt_obra_ref = $pdo->prepare("SELECT referencia_obra FROM obras WHERE id = ?");
    $stmt_obra_ref->execute([$obra_selecionada]);
    $referencia_obra_atual = $stmt_obra_ref->fetchColumn();

    if (!empty($referencia_obra_atual)) {
        $stmt_orc = $pdo->prepare("SELECT valor_total_liquido FROM orcamentos WHERE numero_orcamento = ? ORDER BY id DESC LIMIT 1");
        $stmt_orc->execute([$referencia_obra_atual]);
        $valor_orc = $stmt_orc->fetchColumn();
        if ($valor_orc !== false && $valor_orc !== null) {
            $tem_orcamento = true;
            $valor_adjudicado = (float)$valor_orc;
        }
    }

    // 1b. VALOR TOTAL ACUMULADO DO ÚLTIMO AUTO DE MEDIÇÃO
    // Usa-se o valor acumulado (adjudicados + suplementares) do auto mais recente
    // (maior numero_auto e, dentro deste, maior versao_auto) para o Resultado da Obra.
    $stmt_ultimo_auto = $pdo->prepare("SELECT id FROM autos WHERE id_obra = ? ORDER BY numero_auto DESC, versao_auto DESC LIMIT 1");
    $stmt_ultimo_auto->execute([$obra_selecionada]);
    $id_ultimo_auto = $stmt_ultimo_auto->fetchColumn();

    if ($id_ultimo_auto !== false && $id_ultimo_auto !== null) {
        $stmt_valor_auto = $pdo->prepare("SELECT SUM((qtd_anterior + qtd_mes) * preco_unitario) AS total_acumulado
                                           FROM auto_itens WHERE id_auto = ?");
        $stmt_valor_auto->execute([$id_ultimo_auto]);
        $valor_acum = $stmt_valor_auto->fetchColumn();
        if ($valor_acum !== false && $valor_acum !== null) {
            $tem_auto = true;
            $valor_total_autos = (float)$valor_acum;
        }
    }

    // 2. REFEIÇÕES por funcionário, com o detalhe dia a dia
    $stmt_ref = $pdo->prepare("SELECT u.id AS id_funcionario, u.nome, od.data_despesa, od.valor
                                FROM obra_despesas od
                                INNER JOIN relatorios_obra r ON od.id_relatorio = r.id
                                INNER JOIN utilizadores u ON r.colaborador_1 = u.id
                                WHERE od.obra_id = ? AND od.tipo_despesa = 'Refeição' AND od.origem = 'Relatório'
                                ORDER BY u.nome ASC, od.data_despesa ASC");
    $stmt_ref->execute([$obra_selecionada]);
    foreach ($stmt_ref->fetchAll(PDO::FETCH_ASSOC) as $linha) {
        $id_f = $linha['id_funcionario'];
        if (!isset($refeicoes_por_funcionario[$id_f])) {
            $refeicoes_por_funcionario[$id_f] = ['nome' => $linha['nome'], 'n_refeicoes' => 0, 'total_valor' => 0, 'dias' => []];
        }
        $valor_dia = (float)$linha['valor'];
        $refeicoes_por_funcionario[$id_f]['n_refeicoes']++;
        $refeicoes_por_funcionario[$id_f]['total_valor'] += $valor_dia;
        $refeicoes_por_funcionario[$id_f]['dias'][] = ['data' => $linha['data_despesa'], 'valor' => $valor_dia];
        $total_refeicoes += $valor_dia;
    }

    // 3. HORAS NORMAIS DE TRABALHO por funcionário, com o detalhe dia a dia (exclui trabalho_extra)
    $stmt_hn = $pdo->prepare("SELECT u.id AS id_funcionario, u.nome, u.valor_hora AS valor_hora_funcionario,
                                      od.data_despesa, od.quantidade AS horas
                               FROM obra_despesas od
                               INNER JOIN relatorios_obra r ON od.id_relatorio = r.id
                               INNER JOIN utilizadores u ON r.colaborador_1 = u.id
                               WHERE od.obra_id = ? AND od.tipo_despesa = 'Mão de Obra' AND od.origem = 'Relatório'
                                     AND r.trabalho_extra = 0
                               ORDER BY u.nome ASC, od.data_despesa ASC");
    $stmt_hn->execute([$obra_selecionada]);
    foreach ($stmt_hn->fetchAll(PDO::FETCH_ASSOC) as $linha) {
        $id_f = $linha['id_funcionario'];
        if (!isset($horas_normais_por_funcionario[$id_f])) {
            $horas_normais_por_funcionario[$id_f] = [
                'nome' => $linha['nome'],
                'valor_hora' => ($linha['valor_hora_funcionario'] !== null) ? (float)$linha['valor_hora_funcionario'] : null,
                'total_horas' => 0,
                'total_valor' => 0,
                'dias' => [],
            ];
        }
        $horas_dia = (float)$linha['horas'];
        $valor_hora_func = $horas_normais_por_funcionario[$id_f]['valor_hora'] ?? 0;
        $valor_dia = $horas_dia * $valor_hora_func;
        $horas_normais_por_funcionario[$id_f]['total_horas'] += $horas_dia;
        $horas_normais_por_funcionario[$id_f]['total_valor'] += $valor_dia;
        $horas_normais_por_funcionario[$id_f]['dias'][] = [
            'data' => $linha['data_despesa'],
            'horas' => $horas_dia,
            'valor' => $valor_dia,
        ];
        $total_horas_normais += $horas_dia;
        $valor_horas_normais += $valor_dia;
    }

    // 4. DESLOCAÇÕES por funcionário, com o detalhe dia a dia
    $stmt_desl = $pdo->prepare("SELECT u.id AS id_funcionario, u.nome, od.data_despesa, od.quantidade AS km,
                                        od.valor, r.foi_sozinho
                                 FROM obra_despesas od
                                 INNER JOIN relatorios_obra r ON od.id_relatorio = r.id
                                 INNER JOIN utilizadores u ON r.colaborador_1 = u.id
                                 WHERE od.obra_id = ? AND od.tipo_despesa = 'Deslocação' AND od.origem = 'Relatório'
                                 ORDER BY u.nome ASC, od.data_despesa ASC");
    $stmt_desl->execute([$obra_selecionada]);
    foreach ($stmt_desl->fetchAll(PDO::FETCH_ASSOC) as $linha) {
        $id_f = $linha['id_funcionario'];
        if (!isset($deslocacoes_por_funcionario[$id_f])) {
            $deslocacoes_por_funcionario[$id_f] = ['nome' => $linha['nome'], 'n_deslocacoes' => 0, 'total_km' => 0, 'total_valor' => 0, 'dias' => []];
        }
        $km_dia = (float)$linha['km'];
        $valor_dia = (float)$linha['valor'];
        $deslocacoes_por_funcionario[$id_f]['n_deslocacoes']++;
        $deslocacoes_por_funcionario[$id_f]['total_km'] += $km_dia;
        $deslocacoes_por_funcionario[$id_f]['total_valor'] += $valor_dia;
        $deslocacoes_por_funcionario[$id_f]['dias'][] = [
            'data' => $linha['data_despesa'],
            'km' => $km_dia,
            'valor' => $valor_dia,
            'valor_km' => ($km_dia > 0) ? ($valor_dia / $km_dia) : null,
            'sozinho' => ((int)$linha['foi_sozinho'] === 1),
        ];
        $total_deslocacoes_valor += $valor_dia;
    }
    foreach ($deslocacoes_por_funcionario as &$dl_ref) {
        $dl_ref['valor_km_medio'] = ($dl_ref['total_km'] > 0) ? ($dl_ref['total_valor'] / $dl_ref['total_km']) : null;
    }
    unset($dl_ref);

    // 4b. MAPA RESUMO DA OBRA (calendário): agregados por dia e, dentro de cada dia, por funcionário
    // (mesma lógica da gestao.php, incluindo a contagem de viaturas por dia)
    $stmt_mapa = $pdo->prepare("SELECT od.data_despesa, od.tipo_despesa, od.quantidade, od.valor,
                                        r.trabalho_extra, r.foi_sozinho, r.colaborador_1 AS id_funcionario, u.nome
                                 FROM obra_despesas od
                                 INNER JOIN relatorios_obra r ON od.id_relatorio = r.id
                                 INNER JOIN utilizadores u ON r.colaborador_1 = u.id
                                 WHERE od.obra_id = ? AND od.origem = 'Relatório'
                                 ORDER BY od.data_despesa ASC");
    $stmt_mapa->execute([$obra_selecionada]);
    foreach ($stmt_mapa->fetchAll(PDO::FETCH_ASSOC) as $linha) {
        $data = $linha['data_despesa'];
        $id_f = $linha['id_funcionario'];
        $valor = (float)$linha['valor'];
        $qtd = (float)$linha['quantidade'];

        if (!isset($mapa_dias[$data])) {
            $mapa_dias[$data] = ['horas_normais' => 0, 'valor_mao_obra' => 0, 'valor_refeicoes' => 0, 'valor_deslocacoes' => 0, 'viaturas_solo' => 0, 'tem_viatura_acompanhada' => false, 'colaboradores' => []];
        }
        if (!isset($mapa_dias[$data]['colaboradores'][$id_f])) {
            $mapa_dias[$data]['colaboradores'][$id_f] = ['nome' => $linha['nome'], 'horas' => 0, 'valor_mao_obra' => 0, 'valor_refeicao' => 0, 'valor_deslocacao' => 0];
        }

        switch ($linha['tipo_despesa']) {
            case 'Mão de Obra':
                $mapa_dias[$data]['valor_mao_obra'] += $valor;
                $mapa_dias[$data]['colaboradores'][$id_f]['valor_mao_obra'] += $valor;
                if ((int)$linha['trabalho_extra'] === 0) {
                    $mapa_dias[$data]['horas_normais'] += $qtd;
                    $mapa_dias[$data]['colaboradores'][$id_f]['horas'] += $qtd;
                }
                break;
            case 'Refeição':
                $mapa_dias[$data]['valor_refeicoes'] += $valor;
                $mapa_dias[$data]['colaboradores'][$id_f]['valor_refeicao'] += $valor;
                break;
            case 'Deslocação':
                $mapa_dias[$data]['valor_deslocacoes'] += $valor;
                $mapa_dias[$data]['colaboradores'][$id_f]['valor_deslocacao'] += $valor;
                if ((int)$linha['foi_sozinho'] === 1) {
                    $mapa_dias[$data]['viaturas_solo']++;
                } else {
                    $mapa_dias[$data]['tem_viatura_acompanhada'] = true;
                }
                break;
        }
    }

    // 5. DESPESAS MANUAIS (Material, Outras, e entradas manuais de Mão de Obra/Deslocação/Refeição)
    $stmt_manuais = $pdo->prepare("SELECT d.*, u.nome AS nome_colaborador
                                    FROM obra_despesas d
                                    LEFT JOIN utilizadores u ON d.id_utilizador = u.id
                                    WHERE d.obra_id = ? AND d.origem IN ('Manual', 'Compra')
                                    ORDER BY d.data_despesa DESC, d.id DESC");
    $stmt_manuais->execute([$obra_selecionada]);
    $despesas_manuais = $stmt_manuais->fetchAll(PDO::FETCH_ASSOC);
    foreach ($despesas_manuais as $dm) {
        $total_despesas_manuais += (float)$dm['valor'];
    }

    // 6. VISITAS / REUNIÕES (deslocações do próprio Mário à obra, registadas em visitas.php)
    // Não há km nem valor gravados na tabela visitas — o custo é calculado aqui:
    //   Deslocação = obras.km (já é ida e volta) × configuracoes.preco_km
    //   Mão de Obra = 1 hora fixa (não há hora de entrada/saída registada) × utilizadores.valor_hora do colaborador que visitou
    $stmt_km_obra = $pdo->prepare("SELECT km FROM obras WHERE id = ?");
    $stmt_km_obra->execute([$obra_selecionada]);
    $km_obra_atual = (float)$stmt_km_obra->fetchColumn();

    // A tabela 'configuracoes' é do tipo chave/valor (colunas: id, chave, valor), não tem uma coluna 'preco_km' própria.
    $stmt_preco_km = $pdo->prepare("SELECT valor FROM configuracoes WHERE chave = 'preco_km' LIMIT 1");
    $stmt_preco_km->execute();
    $preco_km_config = (float)$stmt_preco_km->fetchColumn();

    $stmt_visitas = $pdo->prepare("SELECT v.id, v.data_visita, v.hora_visita, v.assunto,
                                           u.nome AS nome_colaborador, u.valor_hora
                                    FROM visitas v
                                    LEFT JOIN utilizadores u ON v.colaborador_1 = u.id
                                    WHERE v.id_obra = ?
                                    ORDER BY v.data_visita ASC, v.hora_visita ASC");
    $stmt_visitas->execute([$obra_selecionada]);
    foreach ($stmt_visitas->fetchAll(PDO::FETCH_ASSOC) as $v) {
        $valor_hora_colab = ($v['valor_hora'] !== null) ? (float)$v['valor_hora'] : 0;
        $valor_deslocacao_visita = $km_obra_atual * $preco_km_config;
        $valor_mao_obra_visita = 1 * $valor_hora_colab; // sempre 1 hora fixa
        $valor_total_visita = $valor_deslocacao_visita + $valor_mao_obra_visita;

        $visitas_obra[] = [
            'id' => $v['id'],
            'data' => $v['data_visita'],
            'assunto' => $v['assunto'],
            'nome_colaborador' => $v['nome_colaborador'],
            'valor_deslocacao' => $valor_deslocacao_visita,
            'valor_mao_obra' => $valor_mao_obra_visita,
            'valor_total' => $valor_total_visita,
        ];
        $total_visitas += $valor_total_visita;
    }

} catch (Throwable $e) {
    $mensagem_erro = "Erro ao carregar dados financeiros: " . $e->getMessage();
}

// 7. RESULTADO DA OBRA: Valor Total Acumulado do Último Auto - Total de Despesas
$total_despesas_obra = $total_refeicoes + $valor_horas_normais + $total_deslocacoes_valor + $total_despesas_manuais + $total_visitas;
$resultado_obra = $valor_total_autos - $total_despesas_obra;

// Contagens (nº de refeições / deslocações) para mostrar no Resumo Financeiro
$total_n_refeicoes = array_sum(array_column($refeicoes_por_funcionario, 'n_refeicoes'));

// Nº de Deslocações (viaturas): igual à lógica do Mapa Resumo da Obra em gestao.php —
// cada colaborador "sozinho" nesse dia é uma viatura à parte; os "acompanhados" desse mesmo dia
// (1, 2 ou 3 colaboradores) partilham uma única viatura. Sem isto, um dia com 3 colaboradores na
// mesma viatura seria contado como 3 deslocações em vez de 1.
$viaturas_por_dia = [];
foreach ($deslocacoes_por_funcionario as $dl) {
    foreach ($dl['dias'] as $dia) {
        $d = $dia['data'];
        if (!isset($viaturas_por_dia[$d])) {
            $viaturas_por_dia[$d] = ['solo' => 0, 'acompanhado' => false];
        }
        if ($dia['sozinho']) {
            $viaturas_por_dia[$d]['solo']++;
        } else {
            $viaturas_por_dia[$d]['acompanhado'] = true;
        }
    }
}
$total_n_deslocacoes = 0;
foreach ($viaturas_por_dia as $vd) {
    $total_n_deslocacoes += $vd['solo'] + ($vd['acompanhado'] ? 1 : 0);
}

// Meses a mostrar no Mapa Resumo da Obra: este relatório cobre a obra toda, por isso
// mostram-se todos os meses com atividade registada (sem navegação, ao contrário da gestao.php)
$meses_nomes_pt = [1=>'Janeiro', 2=>'Fevereiro', 3=>'Março', 4=>'Abril', 5=>'Maio', 6=>'Junho', 7=>'Julho', 8=>'Agosto', 9=>'Setembro', 10=>'Outubro', 11=>'Novembro', 12=>'Dezembro'];
$meses_mapa = array_values(array_unique(array_map(fn($d) => substr($d, 0, 7), array_keys($mapa_dias))));
sort($meses_mapa);

$data_emissao = date('d/m/Y H:i');
?>
<!DOCTYPE html>
<html lang="pt-PT">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Relatório da Obra - <?= htmlspecialchars($obra_info['nome']) ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { font-family: 'Segoe UI', system-ui, -apple-system, sans-serif; box-sizing: border-box; }
        body { background: #e9ecef; margin: 0; color: #2c3e50; font-size: 13px; }

        .no-print { }

        .toolbar { position: sticky; top: 0; background: #2c3e50; padding: 12px 20px; display: flex; justify-content: center; gap: 12px; z-index: 100; box-shadow: 0 2px 10px rgba(0,0,0,0.15); }
        .toolbar a, .toolbar button { font-family: inherit; font-size: 14px; font-weight: 700; border-radius: 8px; padding: 10px 20px; border: none; cursor: pointer; text-decoration: none; display: inline-flex; align-items: center; gap: 8px; transition: 0.2s; }
        .btn-voltar { background: #576574; color: #fff; }
        .btn-voltar:hover { background: #4b5563; }
        .btn-imprimir { background: #27ae60; color: #fff; }
        .btn-imprimir:hover { background: #229954; }

        .folha { max-width: 900px; margin: 25px auto; background: #fff; padding: 40px 45px; box-shadow: 0 5px 25px rgba(0,0,0,0.1); border-radius: 4px; }

        .cabecalho { display: flex; justify-content: space-between; align-items: flex-start; border-bottom: 3px solid #27ae60; padding-bottom: 18px; margin-bottom: 25px; }
        .cabecalho .logo { height: 42px; margin-bottom: 8px; }
        .cabecalho .empresa-info { font-size: 11px; color: #7f8c8d; line-height: 1.5; max-width: 320px; }
        .cabecalho .titulo-doc { text-align: right; }
        .cabecalho .titulo-doc h1 { margin: 0; font-size: 20px; color: #2c3e50; text-transform: uppercase; letter-spacing: 0.5px; }
        .cabecalho .titulo-doc span { font-size: 12px; color: #95a5a6; }

        .dados-obra { background: #f8f9fa; border: 1px solid #e1e5ea; border-radius: 8px; padding: 15px 20px; margin-bottom: 25px; display: grid; grid-template-columns: 1fr 1fr; gap: 8px 25px; }
        .dados-obra div span.lbl { display: block; font-size: 10px; text-transform: uppercase; font-weight: 700; color: #95a5a6; margin-bottom: 2px; }
        .dados-obra div span.val { font-size: 14px; font-weight: 700; color: #2c3e50; }

        h2.sec { font-size: 13px; color: #27ae60; text-transform: uppercase; letter-spacing: 0.5px; margin: 30px 0 12px; padding-bottom: 6px; border-bottom: 1px solid #e1e5ea; display: flex; align-items: center; gap: 8px; }
        h2.sec:first-of-type { margin-top: 0; }

        table { width: 100%; border-collapse: collapse; margin-bottom: 6px; }
        th { background: #f1f2f6; color: #576574; font-size: 10px; text-transform: uppercase; text-align: left; padding: 8px 10px; border-bottom: 2px solid #e1e5ea; font-weight: 700; }
        td { padding: 7px 10px; border-bottom: 1px dashed #ecf0f1; vertical-align: middle; }
        tbody tr:last-child td { border-bottom: none; }
        .text-right { text-align: right; }
        .text-center { text-align: center; }
        tfoot td { font-weight: 800; border-top: 2px solid #bdc3c7; background: #fdfdfd; }
        .linha-func td { font-weight: 700; background: #fafbfc; }
        .linha-dia td { color: #7f8c8d; font-size: 12px; padding-left: 26px; }
        .sem-dados { color: #95a5a6; font-style: italic; padding: 8px 0; font-size: 12px; }

        .resumo-tabela td.rotulo { color: #576574; }
        .resumo-tabela .rotulo-qtd { color: #95a5a6; font-size: 11px; font-weight: 400; }
        .resumo-tabela td.valor { text-align: right; font-weight: 700; }
        .resumo-tabela tr.linha-total td { border-top: 2px solid #bdc3c7; font-weight: 800; }
        .resumo-tabela tr.linha-resultado td { border-top: 3px double #2c3e50; font-size: 16px; font-weight: 800; padding-top: 12px; }
        .cor-pos { color: #27ae60; }
        .cor-neg { color: #c0392b; }

        .mapa-mes-bloco { margin-bottom: 22px; page-break-inside: avoid; }
        .mapa-mes-titulo { font-size: 12px; font-weight: 800; color: #2c3e50; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 8px; }
        .mapa-calendario { display: grid; grid-template-columns: repeat(7, 1fr); gap: 5px; }
        .mapa-dia-semana { text-align: center; font-size: 9px; font-weight: 700; text-transform: uppercase; color: #95a5a6; padding-bottom: 4px; }
        .mapa-dia { min-height: 56px; border: 1px solid #f1f2f6; border-radius: 6px; padding: 4px 6px; display: flex; flex-direction: column; gap: 2px; background: #fdfdfd; }
        .mapa-dia-vazio { border: none; background: transparent; }
        .mapa-dia-numero { font-size: 10px; font-weight: 700; color: #bdc3c7; }
        .mapa-dia-com-dados { background: #eafaf1; border-color: #a9dfbf; }
        .mapa-dia-com-dados .mapa-dia-numero { color: #27ae60; }
        .mapa-dia-colaboradores { font-size: 9px; color: #576574; }
        .mapa-dia-horas { font-size: 9px; color: #e67e22; font-weight: 700; }
        .mapa-dia-valor { font-size: 10px; color: #2c3e50; font-weight: 800; margin-top: auto; }

        .rodape { margin-top: 35px; padding-top: 15px; border-top: 1px solid #e1e5ea; font-size: 10px; color: #95a5a6; text-align: center; }

        .alerta-erro { background: #fdeded; color: #c0392b; border: 1px solid #f9cccc; padding: 12px; border-radius: 8px; margin-bottom: 20px; font-weight: 700; text-align: center; }

        @media print {
            body { background: #fff; }
            .no-print { display: none !important; }
            .folha { box-shadow: none; margin: 0; padding: 0; max-width: none; }
            h2.sec { page-break-after: avoid; }
            table { page-break-inside: auto; }
            tr { page-break-inside: avoid; }
            thead { display: table-header-group; }
            .mapa-mes-bloco { page-break-inside: avoid; }
            @page { size: A4; margin: 15mm; }
        }
    </style>
</head>
<body>

    <div class="toolbar no-print">
        <a href="gestao.php?id_obra=<?= $obra_selecionada ?>" class="btn-voltar"><i class="fa-solid fa-arrow-left"></i> Voltar à Gestão</a>
        <button type="button" class="btn-imprimir" onclick="window.print()"><i class="fa-solid fa-print"></i> Imprimir / Guardar PDF</button>
    </div>

    <div class="folha">

        <div class="cabecalho">
            <div>
                <img src="mab.png" alt="MAB" class="logo"><br>
                <div class="empresa-info"><?= htmlspecialchars($EMPRESA_NOME) ?><br><?= htmlspecialchars($EMPRESA_RODAPE) ?></div>
            </div>
            <div class="titulo-doc">
                <h1>Relatório da Obra</h1>
                <span>Emitido em <?= $data_emissao ?></span>
            </div>
        </div>

        <?php if ($mensagem_erro): ?>
            <div class="alerta-erro"><i class="fa-solid fa-triangle-exclamation"></i> <?= htmlspecialchars($mensagem_erro) ?></div>
        <?php endif; ?>

        <div class="dados-obra">
            <div><span class="lbl">Obra</span><span class="val"><?= htmlspecialchars($obra_info['nome']) ?></span></div>
            <div><span class="lbl">Cliente</span><span class="val"><?= htmlspecialchars($obra_info['nome_cliente'] ?: 'N/D') ?></span></div>
            <div><span class="lbl">Referência</span><span class="val"><?= htmlspecialchars($obra_info['referencia_obra'] ?: 'N/D') ?></span></div>
            <div><span class="lbl">Estado</span><span class="val"><?= htmlspecialchars($obra_info['estado'] ?: 'N/D') ?></span></div>
            <?php if (!empty($obra_info['morada'])): ?>
                <div style="grid-column: 1 / -1;"><span class="lbl">Morada</span><span class="val"><?= htmlspecialchars($obra_info['morada']) ?></span></div>
            <?php endif; ?>
        </div>

        <h2 class="sec"><i class="fa-solid fa-scale-balanced"></i> Resumo Financeiro</h2>
        <table class="resumo-tabela">
            <tbody>
                <tr>
                    <td class="rotulo">Orçamento Adjudicado</td>
                    <td class="valor"><?= $tem_orcamento ? formatNumberToPT($valor_adjudicado) . ' €' : '—' ?></td>
                </tr>
                <tr>
                    <td class="rotulo">Valor dos Autos (último auto de medição)</td>
                    <td class="valor"><?= $tem_auto ? formatNumberToPT($valor_total_autos) . ' €' : '—' ?></td>
                </tr>
                <tr><td class="rotulo" colspan="2" style="padding-top:14px; font-weight:700; color:#2c3e50;">Despesas</td></tr>
                <tr>
                    <td class="rotulo">Refeições <span class="rotulo-qtd">(<?= $total_n_refeicoes ?> refeições)</span></td>
                    <td class="valor"><?= formatNumberToPT($total_refeicoes) ?> €</td>
                </tr>
                <tr>
                    <td class="rotulo">Horas Normais de Trabalho <span class="rotulo-qtd">(<?= formatHoras($total_horas_normais) ?>)</span></td>
                    <td class="valor"><?= formatNumberToPT($valor_horas_normais) ?> €</td>
                </tr>
                <tr>
                    <td class="rotulo">Deslocações <span class="rotulo-qtd">(<?= $total_n_deslocacoes ?> deslocações)</span></td>
                    <td class="valor"><?= formatNumberToPT($total_deslocacoes_valor) ?> €</td>
                </tr>
                <tr>
                    <td class="rotulo">Despesas Manuais</td>
                    <td class="valor"><?= formatNumberToPT($total_despesas_manuais) ?> €</td>
                </tr>
                <?php foreach ($despesas_manuais as $dm): ?>
                    <tr class="linha-dia">
                        <td><?= date('d/m/Y', strtotime($dm['data_despesa'])) ?> — <?= htmlspecialchars($dm['tipo_despesa']) ?><?= !empty($dm['descricao']) ? ' (' . htmlspecialchars($dm['descricao']) . ')' : '' ?></td>
                        <td class="valor" style="font-weight:400;"><?= formatNumberToPT($dm['valor']) ?> €</td>
                    </tr>
                <?php endforeach; ?>
                <tr>
                    <td class="rotulo">Visitas / Reuniões <span class="rotulo-qtd">(<?= count($visitas_obra) ?> visitas)</span></td>
                    <td class="valor"><?= formatNumberToPT($total_visitas) ?> €</td>
                </tr>
                <?php foreach ($visitas_obra as $vt): ?>
                    <tr class="linha-dia">
                        <td><?= date('d/m/Y', strtotime($vt['data'])) ?> — <?= htmlspecialchars($vt['assunto']) ?> (<?= htmlspecialchars($vt['nome_colaborador']) ?>)</td>
                        <td class="valor" style="font-weight:400;"><?= formatNumberToPT($vt['valor_total']) ?> €</td>
                    </tr>
                <?php endforeach; ?>
                <tr class="linha-total">
                    <td class="rotulo">Total de Despesas</td>
                    <td class="valor"><?= formatNumberToPT($total_despesas_obra) ?> €</td>
                </tr>
                <tr class="linha-resultado">
                    <td class="rotulo">Resultado da Obra</td>
                    <td class="valor <?= $resultado_obra >= 0 ? 'cor-pos' : 'cor-neg' ?>">
                        <?= $tem_auto ? formatNumberToPT($resultado_obra) . ' €' : 'Sem autos de medição' ?>
                    </td>
                </tr>
            </tbody>
        </table>

        <h2 class="sec"><i class="fa-solid fa-receipt"></i> Despesas Manuais Registadas</h2>
        <?php if (empty($despesas_manuais)): ?>
            <p class="sem-dados">Ainda não existem despesas manuais registadas nesta obra.</p>
        <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th style="width:80px;">Data</th>
                        <th style="width:120px;">Tipo</th>
                        <th>Descrição</th>
                        <th style="width:110px;">Colaborador</th>
                        <th class="text-right" style="width:90px;">Valor (€)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($despesas_manuais as $dm): ?>
                        <tr>
                            <td><?= date('d/m/Y', strtotime($dm['data_despesa'])) ?></td>
                            <td><?= htmlspecialchars($dm['tipo_despesa']) ?></td>
                            <td><?= htmlspecialchars($dm['descricao']) ?></td>
                            <td><?= htmlspecialchars($dm['nome_colaborador'] ?? '-') ?></td>
                            <td class="text-right"><?= formatNumberToPT($dm['valor']) ?> €</td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr>
                        <td colspan="4">Total</td>
                        <td class="text-right"><?= formatNumberToPT($total_despesas_manuais) ?> €</td>
                    </tr>
                </tfoot>
            </table>
        <?php endif; ?>

        <h2 class="sec"><i class="fa-solid fa-people-arrows"></i> Visitas / Reuniões Registadas</h2>
        <?php if (empty($visitas_obra)): ?>
            <p class="sem-dados">Ainda não existem visitas registadas nesta obra.</p>
        <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th style="width:80px;">Data</th>
                        <th>Assunto</th>
                        <th style="width:110px;">Colaborador</th>
                        <th class="text-right" style="width:90px;">Deslocação</th>
                        <th class="text-right" style="width:90px;">M. Obra (1h)</th>
                        <th class="text-right" style="width:90px;">Total (€)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($visitas_obra as $vt): ?>
                        <tr>
                            <td><?= date('d/m/Y', strtotime($vt['data'])) ?></td>
                            <td><?= htmlspecialchars($vt['assunto']) ?></td>
                            <td><?= htmlspecialchars($vt['nome_colaborador'] ?? '-') ?></td>
                            <td class="text-right"><?= formatNumberToPT($vt['valor_deslocacao']) ?> €</td>
                            <td class="text-right"><?= formatNumberToPT($vt['valor_mao_obra']) ?> €</td>
                            <td class="text-right"><?= formatNumberToPT($vt['valor_total']) ?> €</td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr>
                        <td colspan="5">Total</td>
                        <td class="text-right"><?= formatNumberToPT($total_visitas) ?> €</td>
                    </tr>
                </tfoot>
            </table>
        <?php endif; ?>

        <h2 class="sec"><i class="fa-solid fa-utensils"></i> Refeições por Funcionário</h2>
        <?php if (empty($refeicoes_por_funcionario)): ?>
            <p class="sem-dados">Sem refeições registadas para esta obra.</p>
        <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>Funcionário / Data</th>
                        <th class="text-center" style="width:120px;">Nº Refeições</th>
                        <th class="text-right" style="width:110px;">Valor (€)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($refeicoes_por_funcionario as $rf): ?>
                        <tr class="linha-func">
                            <td><?= htmlspecialchars($rf['nome']) ?></td>
                            <td class="text-center"><?= (int)$rf['n_refeicoes'] ?></td>
                            <td class="text-right"><?= formatNumberToPT($rf['total_valor']) ?> €</td>
                        </tr>
                        <?php foreach ($rf['dias'] as $dia): ?>
                            <tr class="linha-dia">
                                <td><?= date('d/m/Y', strtotime($dia['data'])) ?></td>
                                <td class="text-center">—</td>
                                <td class="text-right"><?= formatNumberToPT($dia['valor']) ?> €</td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr>
                        <td>Total</td>
                        <td class="text-center"><?= array_sum(array_column($refeicoes_por_funcionario, 'n_refeicoes')) ?></td>
                        <td class="text-right"><?= formatNumberToPT($total_refeicoes) ?> €</td>
                    </tr>
                </tfoot>
            </table>
        <?php endif; ?>

        <h2 class="sec"><i class="fa-solid fa-clock"></i> Horas Normais de Trabalho por Funcionário</h2>
        <?php if (empty($horas_normais_por_funcionario)): ?>
            <p class="sem-dados">Sem horas normais registadas para esta obra.</p>
        <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>Funcionário / Data</th>
                        <th class="text-center" style="width:90px;">€/h</th>
                        <th class="text-center" style="width:100px;">Horas</th>
                        <th class="text-right" style="width:110px;">Valor (€)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($horas_normais_por_funcionario as $hn): ?>
                        <tr class="linha-func">
                            <td><?= htmlspecialchars($hn['nome']) ?></td>
                            <td class="text-center"><?= ($hn['valor_hora'] !== null) ? formatNumberToPT($hn['valor_hora']) . ' €' : '—' ?></td>
                            <td class="text-center"><?= formatHoras($hn['total_horas']) ?></td>
                            <td class="text-right"><?= formatNumberToPT($hn['total_valor']) ?> €</td>
                        </tr>
                        <?php foreach ($hn['dias'] as $dia): ?>
                            <tr class="linha-dia">
                                <td><?= date('d/m/Y', strtotime($dia['data'])) ?></td>
                                <td class="text-center">—</td>
                                <td class="text-center"><?= formatHoras($dia['horas']) ?></td>
                                <td class="text-right"><?= formatNumberToPT($dia['valor']) ?> €</td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr>
                        <td>Total</td>
                        <td class="text-center">—</td>
                        <td class="text-center"><?= formatHoras($total_horas_normais) ?></td>
                        <td class="text-right"><?= formatNumberToPT($valor_horas_normais) ?> €</td>
                    </tr>
                </tfoot>
            </table>
        <?php endif; ?>

        <h2 class="sec"><i class="fa-solid fa-route"></i> Deslocações por Funcionário</h2>
        <?php if (empty($deslocacoes_por_funcionario)): ?>
            <p class="sem-dados">Sem deslocações registadas para esta obra.</p>
        <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>Funcionário / Data</th>
                        <th class="text-center" style="width:90px;">Sozinho?</th>
                        <th class="text-center" style="width:80px;">Km</th>
                        <th class="text-center" style="width:80px;">€/km</th>
                        <th class="text-right" style="width:110px;">Valor (€)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($deslocacoes_por_funcionario as $dl): ?>
                        <tr class="linha-func">
                            <td><?= htmlspecialchars($dl['nome']) ?> <span style="font-weight:400; color:#95a5a6;">(<?= (int)$dl['n_deslocacoes'] ?> desloc.)</span></td>
                            <td class="text-center">—</td>
                            <td class="text-center"><?= formatNumberToPT($dl['total_km']) ?></td>
                            <td class="text-center"><?= ($dl['valor_km_medio'] !== null) ? formatNumberToPT($dl['valor_km_medio']) . ' €' : '—' ?></td>
                            <td class="text-right"><?= formatNumberToPT($dl['total_valor']) ?> €</td>
                        </tr>
                        <?php foreach ($dl['dias'] as $dia): ?>
                            <tr class="linha-dia">
                                <td><?= date('d/m/Y', strtotime($dia['data'])) ?></td>
                                <td class="text-center"><?= $dia['sozinho'] ? 'Sim' : 'Não' ?></td>
                                <td class="text-center"><?= formatNumberToPT($dia['km']) ?></td>
                                <td class="text-center"><?= ($dia['valor_km'] !== null) ? formatNumberToPT($dia['valor_km']) . ' €' : '—' ?></td>
                                <td class="text-right"><?= formatNumberToPT($dia['valor']) ?> €</td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr>
                        <td>Total</td>
                        <td class="text-center">—</td>
                        <td class="text-center"><?= formatNumberToPT(array_sum(array_column($deslocacoes_por_funcionario, 'total_km'))) ?></td>
                        <td class="text-center">—</td>
                        <td class="text-right"><?= formatNumberToPT($total_deslocacoes_valor) ?> €</td>
                    </tr>
                </tfoot>
            </table>
        <?php endif; ?>

        <h2 class="sec"><i class="fa-solid fa-calendar-days"></i> Mapa Resumo da Obra</h2>
        <?php if (empty($mapa_dias)): ?>
            <p class="sem-dados">Sem atividade registada nesta obra.</p>
        <?php else: ?>
            <?php foreach ($meses_mapa as $mes_ym):
                $timestamp_mes_mapa = strtotime($mes_ym . '-01');
                $nome_mes_mapa = $meses_nomes_pt[(int)date('n', $timestamp_mes_mapa)] . ' ' . date('Y', $timestamp_mes_mapa);
                $dias_no_mes_mapa = (int)date('t', $timestamp_mes_mapa);
                $primeiro_dia_semana_mapa = (int)date('N', $timestamp_mes_mapa); // 1=Segunda ... 7=Domingo
            ?>
                <div class="mapa-mes-bloco">
                    <div class="mapa-mes-titulo"><?= htmlspecialchars($nome_mes_mapa) ?></div>
                    <div class="mapa-calendario">
                        <?php foreach (['Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb', 'Dom'] as $dia_semana_nome): ?>
                            <div class="mapa-dia-semana"><?= $dia_semana_nome ?></div>
                        <?php endforeach; ?>

                        <?php for ($i = 0; $i < ($primeiro_dia_semana_mapa - 1); $i++): ?>
                            <div class="mapa-dia mapa-dia-vazio"></div>
                        <?php endfor; ?>

                        <?php for ($dia = 1; $dia <= $dias_no_mes_mapa; $dia++):
                            $data_str_mapa = $mes_ym . '-' . str_pad($dia, 2, '0', STR_PAD_LEFT);
                            $info_dia = $mapa_dias[$data_str_mapa] ?? null;
                        ?>
                            <?php if ($info_dia): ?>
                                <?php
                                    $tooltip_linhas = [];
                                    foreach ($info_dia['colaboradores'] as $colab) {
                                        $tooltip_linhas[] = $colab['nome'] . ': ' . formatHoras($colab['horas']) . ' | Mão de obra ' . formatNumberToPT($colab['valor_mao_obra']) . ' € | Refeição ' . formatNumberToPT($colab['valor_refeicao']) . ' € | Deslocação ' . formatNumberToPT($colab['valor_deslocacao']) . ' €';
                                    }
                                    // Nº de viaturas: cada colaborador "sozinho" é uma viatura à parte; os "acompanhados"
                                    // desse dia (1, 2 ou 3 colaboradores) partilham uma única viatura.
                                    $n_viaturas_dia = $info_dia['viaturas_solo'] + ($info_dia['tem_viatura_acompanhada'] ? 1 : 0);
                                    if ($n_viaturas_dia > 0) {
                                        $tooltip_linhas[] = '';
                                        $tooltip_linhas[] = 'Viaturas: ' . $n_viaturas_dia;
                                    }
                                    $tooltip_texto = implode("\n", $tooltip_linhas);
                                    $total_dia_valor = $info_dia['valor_mao_obra'] + $info_dia['valor_refeicoes'] + $info_dia['valor_deslocacoes'];
                                ?>
                                <div class="mapa-dia mapa-dia-com-dados" title="<?= htmlspecialchars($tooltip_texto) ?>">
                                    <span class="mapa-dia-numero"><?= $dia ?></span>
                                    <span class="mapa-dia-colaboradores">
                                        <i class="fa-solid fa-user"></i> <?= count($info_dia['colaboradores']) ?>
                                        <?php if ($n_viaturas_dia > 0): ?>
                                            <i class="fa-solid fa-car-side" style="margin-left:5px;"></i> <?= $n_viaturas_dia ?>
                                        <?php endif; ?>
                                    </span>
                                    <span class="mapa-dia-horas"><?= formatHoras($info_dia['horas_normais']) ?></span>
                                    <span class="mapa-dia-valor"><?= formatNumberToPT($total_dia_valor) ?> €</span>
                                </div>
                            <?php else: ?>
                                <div class="mapa-dia">
                                    <span class="mapa-dia-numero"><?= $dia ?></span>
                                </div>
                            <?php endif; ?>
                        <?php endfor; ?>

                        <?php
                            $celulas_preenchidas_mapa = ($primeiro_dia_semana_mapa - 1) + $dias_no_mes_mapa;
                            $celulas_finais_mapa = (7 - ($celulas_preenchidas_mapa % 7)) % 7;
                        ?>
                        <?php for ($i = 0; $i < $celulas_finais_mapa; $i++): ?>
                            <div class="mapa-dia mapa-dia-vazio"></div>
                        <?php endfor; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>

        <div class="rodape">
            Relatório gerado automaticamente pelo sistema Gestão MAB &middot; <?= $data_emissao ?>
        </div>

    </div>

</body>
</html>