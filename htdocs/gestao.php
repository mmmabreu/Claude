<?php
session_start();
require_once 'db.php';

// REGRA DE OURO: Funções centralizadas
if (file_exists('functions.php')) {
    require_once 'functions.php';
} else {
    function registrarLog($pdo, $acao, $modulo) { return true; }
}

// REGRA DE OURO 1: Validação de sessão
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}

$perfil_user = $_SESSION['perfil'] ?? '';
$is_admin = in_array($perfil_user, ['admin', 'administrativo']);

// Acesso Restrito à Gestão Global
if (!$is_admin) {
    header("Location: index.php?erro=sem_permissao");
    exit;
}

$nome_completo = $_SESSION['nome'] ?? 'Utilizador';
$primeiro_nome = explode(' ', trim($nome_completo))[0];

// Funções auxiliares de formatação
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

$obra_selecionada = filter_input(INPUT_GET, 'id_obra', FILTER_VALIDATE_INT);

// --- GESTÃO DE DESPESAS MANUAIS (Material, Outras, etc.) DIRETAMENTE A PARTIR DA GESTÃO ---
// Registar, editar, duplicar e apagar. Para sincronizar despesas com os relatórios de obra,
// continua a usar-se o botão próprio no obra_despesas.php.
$mensagem_despesa = ''; $tipo_mensagem_despesa = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $obra_selecionada) {

    // Registar nova despesa manual
    if (isset($_POST['add_despesa'])) {
        $tipo = $_POST['tipo_despesa'] ?? '';
        $data = $_POST['data_despesa'] ?? '';
        $valor = (float)str_replace(',', '.', $_POST['valor'] ?? '0');
        $descricao = trim($_POST['descricao'] ?? '');
        $quantidade = !empty($_POST['quantidade']) ? (float)str_replace(',', '.', $_POST['quantidade']) : 0;
        $id_utilizador_despesa = ($tipo === 'Material') ? null : $_SESSION['user_id'];

        try {
            $sql = "INSERT INTO obra_despesas (obra_id, tipo_despesa, data_despesa, descricao, quantidade, valor, origem, id_utilizador) VALUES (?, ?, ?, ?, ?, ?, 'Manual', ?)";
            $pdo->prepare($sql)->execute([$obra_selecionada, $tipo, $data, $descricao, $quantidade, $valor, $id_utilizador_despesa]);
            registrarLog($pdo, "Adicionou despesa manual ($tipo: $valor €) à Obra #$obra_selecionada", "Obras - Financeiro");
            header("Location: gestao.php?id_obra=" . $obra_selecionada . "&despesa=1");
            exit;
        } catch (Throwable $e) {
            $mensagem_despesa = "Erro ao registar despesa: " . $e->getMessage();
            $tipo_mensagem_despesa = "erro";
        }
    }

    // Editar despesa manual existente
    if (isset($_POST['edit_despesa'])) {
        $id_edit = (int)($_POST['id_despesa_edit'] ?? 0);
        $tipo = $_POST['tipo_despesa'] ?? '';
        $data = $_POST['data_despesa'] ?? '';
        $valor = (float)str_replace(',', '.', $_POST['valor'] ?? '0');
        $descricao = trim($_POST['descricao'] ?? '');
        $quantidade = !empty($_POST['quantidade']) ? (float)str_replace(',', '.', $_POST['quantidade']) : 0;

        try {
            $pdo->prepare("UPDATE obra_despesas SET tipo_despesa = ?, data_despesa = ?, descricao = ?, quantidade = ?, valor = ? WHERE id = ? AND obra_id = ? AND origem = 'Manual'")
                ->execute([$tipo, $data, $descricao, $quantidade, $valor, $id_edit, $obra_selecionada]);
            registrarLog($pdo, "Editou despesa manual (ID: $id_edit) na Obra #$obra_selecionada", "Obras - Financeiro");
            header("Location: gestao.php?id_obra=" . $obra_selecionada . "&despesa=2");
            exit;
        } catch (Throwable $e) {
            $mensagem_despesa = "Erro ao atualizar despesa: " . $e->getMessage();
            $tipo_mensagem_despesa = "erro";
        }
    }

    // Duplicar despesa manual existente (cria uma cópia com a data de hoje)
    if (isset($_POST['duplicar_despesa_id'])) {
        $id_dup = (int)$_POST['duplicar_despesa_id'];
        try {
            $stmt_dup = $pdo->prepare("SELECT * FROM obra_despesas WHERE id = ? AND obra_id = ? AND origem = 'Manual'");
            $stmt_dup->execute([$id_dup, $obra_selecionada]);
            $original = $stmt_dup->fetch(PDO::FETCH_ASSOC);
            if ($original) {
                $sql = "INSERT INTO obra_despesas (obra_id, tipo_despesa, data_despesa, descricao, quantidade, valor, origem, id_utilizador) VALUES (?, ?, ?, ?, ?, ?, 'Manual', ?)";
                $pdo->prepare($sql)->execute([
                    $obra_selecionada, $original['tipo_despesa'], date('Y-m-d'), $original['descricao'],
                    $original['quantidade'], $original['valor'], $original['id_utilizador']
                ]);
                registrarLog($pdo, "Duplicou despesa manual (ID original: $id_dup) na Obra #$obra_selecionada", "Obras - Financeiro");
            }
            header("Location: gestao.php?id_obra=" . $obra_selecionada . "&despesa=3");
            exit;
        } catch (Throwable $e) {
            $mensagem_despesa = "Erro ao duplicar despesa: " . $e->getMessage();
            $tipo_mensagem_despesa = "erro";
        }
    }

    // Apagar despesa manual
    if (isset($_POST['apagar_despesa_id'])) {
        try {
            $pdo->prepare("DELETE FROM obra_despesas WHERE id = ? AND obra_id = ? AND origem = 'Manual'")
                ->execute([$_POST['apagar_despesa_id'], $obra_selecionada]);
            registrarLog($pdo, "Apagou despesa manual (ID: " . $_POST['apagar_despesa_id'] . ") na Obra #$obra_selecionada", "Obras - Financeiro");
            header("Location: gestao.php?id_obra=" . $obra_selecionada . "&despesa=4");
            exit;
        } catch (Throwable $e) {
            $mensagem_despesa = "Erro ao remover despesa: " . $e->getMessage();
            $tipo_mensagem_despesa = "erro";
        }
    }
}

$msg_despesa_get = $_GET['despesa'] ?? '';
if ($msg_despesa_get !== '') {
    $mapa_msgs_despesa = [
        '1' => 'Despesa registada com sucesso!',
        '2' => 'Despesa atualizada com sucesso!',
        '3' => 'Despesa duplicada com sucesso!',
        '4' => 'Despesa removida!',
    ];
    if (isset($mapa_msgs_despesa[$msg_despesa_get])) {
        $mensagem_despesa = $mapa_msgs_despesa[$msg_despesa_get];
        $tipo_mensagem_despesa = 'sucesso';
    }
}

// --- BUSCAR TODAS AS OBRAS PARA O DROPDOWN (ordenadas por Cliente -> Obra) ---
try {
    $stmt_obras = $pdo->query("SELECT o.id, o.referencia_obra, o.nome, o.estado, c.nome AS nome_cliente
                                FROM obras o
                                LEFT JOIN clientes c ON o.id_cliente = c.id
                                ORDER BY c.nome ASC, o.nome ASC");
    $obras_lista = $stmt_obras->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    die("Erro ao carregar obras: " . $e->getMessage());
}

// Valores por defeito
$mensagem_erro = null;

$tem_orcamento = false;
$valor_adjudicado = 0;
$horas_orcamentadas = 0; // Horas de mão de obra previstas no orçamento

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

// --- BUSCAR OS DADOS FINANCEIROS DA OBRA SELECIONADA ---
if ($obra_selecionada) {
    try {
        // 1. ORÇAMENTO ADJUDICADO
        // A ligação entre obra e orçamento é feita pelo número de referência único
        // que percorre o sistema (visita -> orçamento -> obra): obras.referencia_obra = orcamentos.numero_orcamento
        $stmt_obra_ref = $pdo->prepare("SELECT referencia_obra FROM obras WHERE id = ?");
        $stmt_obra_ref->execute([$obra_selecionada]);
        $referencia_obra_atual = $stmt_obra_ref->fetchColumn();

        if (!empty($referencia_obra_atual)) {
            $stmt_orc = $pdo->prepare("SELECT id, valor_total_liquido FROM orcamentos WHERE numero_orcamento = ? ORDER BY id DESC LIMIT 1");
            $stmt_orc->execute([$referencia_obra_atual]);
            $orc_row = $stmt_orc->fetch(PDO::FETCH_ASSOC);
            if ($orc_row && $orc_row['valor_total_liquido'] !== null) {
                $tem_orcamento = true;
                $valor_adjudicado = (float)$orc_row['valor_total_liquido'];

                // 1a. HORAS DE TRABALHO ORÇAMENTADAS
                // Soma do tempo de mão de obra de todas as linhas do orçamento:
                // tempo_mao_obra é por unidade, por isso multiplica-se pela quantidade da linha
                try {
                    $stmt_horas_orc = $pdo->prepare("SELECT SUM(COALESCE(quantidade, 0) * COALESCE(tempo_mao_obra, 0)) FROM orcamento_itens WHERE orcamento_id = ?");
                    $stmt_horas_orc->execute([$orc_row['id']]);
                    $horas_orcamentadas = (float)$stmt_horas_orc->fetchColumn();
                } catch (Exception $e) {
                    $horas_orcamentadas = 0;
                }
            }
        }

        // 1b. VALOR TOTAL ACUMULADO DO ÚLTIMO AUTO DE MEDIÇÃO
        // Para efeitos de "Resultado da Obra", usa-se o valor acumulado (adjudicados + suplementares)
        // do auto mais recente (maior numero_auto e, dentro deste, maior versao_auto), e não o orçamento adjudicado.
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
        // (valores já gravados em obra_despesas pelo relatorio_form.php, tipo_despesa = 'Refeição')
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
        // O valor €/h de cada funcionário vem da tabela utilizadores, coluna valor_hora,
        // e é essa taxa (não o valor gravado em obra_despesas) que calcula o valor da mão de obra: horas × valor_hora
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
        // (o rateio de km entre colaboradores já vem calculado pelo relatorio_form.php)
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
                // não existe uma coluna direta com o €/km: é reconstruído a partir do valor gravado e dos km desse dia
                'valor_km' => ($km_dia > 0) ? ($valor_dia / $km_dia) : null,
                'sozinho' => ((int)$linha['foi_sozinho'] === 1),
            ];
            $total_deslocacoes_valor += $valor_dia;
        }
        // Taxa €/km efetiva de cada funcionário (média ponderada: valor total / km totais)
        foreach ($deslocacoes_por_funcionario as &$dl_ref) {
            $dl_ref['valor_km_medio'] = ($dl_ref['total_km'] > 0) ? ($dl_ref['total_valor'] / $dl_ref['total_km']) : null;
        }
        unset($dl_ref);

        // 5. MAPA RESUMO DA OBRA (calendário): agregados por dia e, dentro de cada dia, por funcionário
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
                // viaturas_solo: cada colaborador que foi sozinho é uma viatura à parte.
                // tem_viatura_acompanhada: 1,2 ou 3 colaboradores "acompanhados" no mesmo dia = 1 só viatura partilhada.
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

        // 6. DESPESAS MANUAIS (Material, Outras, e entradas manuais de Mão de Obra/Deslocação/Refeição)
        $stmt_manuais = $pdo->prepare("SELECT d.*, u.nome AS nome_colaborador
                                        FROM obra_despesas d
                                        LEFT JOIN utilizadores u ON d.id_utilizador = u.id
                                        WHERE d.obra_id = ? AND d.origem = 'Manual'
                                        ORDER BY d.data_despesa DESC, d.id DESC");
        $stmt_manuais->execute([$obra_selecionada]);
        $despesas_manuais = $stmt_manuais->fetchAll(PDO::FETCH_ASSOC);
        foreach ($despesas_manuais as $dm) {
            $total_despesas_manuais += (float)$dm['valor'];
        }

        // 7. VISITAS / REUNIÕES (deslocações do próprio Mário à obra, registadas em visitas.php)
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
                'hora' => $v['hora_visita'],
                'assunto' => $v['assunto'],
                'nome_colaborador' => $v['nome_colaborador'],
                'km' => $km_obra_atual,
                'valor_deslocacao' => $valor_deslocacao_visita,
                'valor_mao_obra' => $valor_mao_obra_visita,
                'valor_total' => $valor_total_visita,
            ];
            $total_visitas += $valor_total_visita;
        }

    } catch (Exception $e) {
        $mensagem_erro = "Erro ao carregar dados financeiros: " . $e->getMessage();
    }
}

// 8. RESULTADO DA OBRA: Valor Total Acumulado do Último Auto - Total de Despesas (Refeições + Horas Normais + Deslocações + Despesas Manuais + Visitas/Reuniões)
$total_despesas_obra = $total_refeicoes + $valor_horas_normais + $total_deslocacoes_valor + $total_despesas_manuais + $total_visitas;
$resultado_obra = $valor_total_autos - $total_despesas_obra;

// Contagens totais (nº de refeições / nº de deslocações) para mostrar nas KPI cards
$total_n_refeicoes = array_sum(array_column($refeicoes_por_funcionario, 'n_refeicoes'));

// Nº de Deslocações (viaturas): mesma lógica do Mapa Resumo da Obra — cada colaborador
// "sozinho" nesse dia é uma viatura à parte; os "acompanhados" desse mesmo dia (1, 2 ou 3
// colaboradores) partilham uma única viatura.
$total_n_deslocacoes = 0;
foreach ($mapa_dias as $dia_info) {
    $total_n_deslocacoes += $dia_info['viaturas_solo'] + ($dia_info['tem_viatura_acompanhada'] ? 1 : 0);
}

// --- MONTAR O MÊS DO MAPA-CALENDÁRIO (por defeito, o mês da atividade mais recente) ---
$meses_nomes_pt = [1=>'Janeiro', 2=>'Fevereiro', 3=>'Março', 4=>'Abril', 5=>'Maio', 6=>'Junho', 7=>'Julho', 8=>'Agosto', 9=>'Setembro', 10=>'Outubro', 11=>'Novembro', 12=>'Dezembro'];

$mes_mapa_get = $_GET['mes_mapa'] ?? '';
if (preg_match('/^\d{4}-\d{2}$/', $mes_mapa_get)) {
    $mes_mapa = $mes_mapa_get;
} elseif (!empty($mapa_dias)) {
    $datas_existentes = array_keys($mapa_dias);
    sort($datas_existentes);
    $mes_mapa = date('Y-m', strtotime(end($datas_existentes)));
} else {
    $mes_mapa = date('Y-m');
}

$timestamp_mes_mapa = strtotime($mes_mapa . '-01');
$nome_mes_mapa = $meses_nomes_pt[(int)date('n', $timestamp_mes_mapa)] . ' ' . date('Y', $timestamp_mes_mapa);
$dias_no_mes_mapa = (int)date('t', $timestamp_mes_mapa);
$primeiro_dia_semana_mapa = (int)date('N', $timestamp_mes_mapa); // 1=Segunda ... 7=Domingo
$mes_mapa_anterior = date('Y-m', strtotime('-1 month', $timestamp_mes_mapa));
$mes_mapa_seguinte = date('Y-m', strtotime('+1 month', $timestamp_mes_mapa));
?>
<!DOCTYPE html>
<html lang="pt-PT">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Gestão Central - Gestão MAB</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { font-family: system-ui, -apple-system, sans-serif; box-sizing: border-box; }
        body { background-color: #f7f9fc; margin: 0; color: #333; font-size: 14px; padding-bottom: 50px; }
        input, select, textarea, button { font-family: inherit; font-size: 14px; outline: none; }

        .topbar { background-color: #fff; padding: 10px 40px; box-shadow: 0 2px 15px rgba(0,0,0,0.05); display: flex; justify-content: space-between; align-items: center; position: sticky; top: 0; z-index: 100; }
        .topbar-left .logo { height: 35px; }
        .topbar-right { display: flex; align-items: center; gap: 20px; }
        .btn-back { color: #576574; text-decoration: none; font-size: 16px; font-weight: 600; display: flex; align-items: center; gap: 8px; transition: color 0.2s; }
        .btn-back:hover { color: #27ae60; }

        .user-menu { position: relative; }
        .user-btn { background: #fdfdfd; border: 1px solid #f1f2f6; border-radius: 30px; font-size: 14px; font-weight: 600; color: #2c3e50; cursor: pointer; display: flex; align-items: center; gap: 10px; padding: 6px 15px 6px 6px; transition: 0.2s; }
        .user-btn:hover { background: #fff; box-shadow: 0 4px 10px rgba(0,0,0,0.05); }
        .user-avatar { background: #e74c3c; color: white; width: 28px; height: 28px; border-radius: 50%; display: flex; justify-content: center; align-items: center; font-size: 14px; border: 2px solid #fadbd8; }

        .dropdown-content { display: none; position: absolute; right: 0; top: 100%; background: #fff; min-width: 180px; box-shadow: 0 10px 30px rgba(0,0,0,0.1); border-radius: 8px; border: 1px solid #f1f2f6; overflow: hidden; margin-top: 5px; z-index: 200; }
        .user-menu:hover .dropdown-content { display: block; }
        .dropdown-content a { color: #576574; padding: 12px 20px; text-decoration: none; display: block; font-size: 14px; }
        .dropdown-content a:hover { background: #fdf2f2; color: #27ae60; }

        .container { max-width: 1200px; margin: 25px auto; padding: 0 20px; }

        .page-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px; border-bottom: 1px solid #e1e5ea; padding-bottom: 15px; flex-wrap: wrap; gap: 15px; }
        .page-title { margin: 0; color: #2c3e50; font-size: 22px; font-weight: 800; display: flex; align-items: center; gap: 10px; }
        .page-title i { color: #27ae60; }

        .btn-acao-header { background: #fdfdfd; color: #576574; border: 1px solid #e1e5ea; padding: 8px 15px; border-radius: 30px; text-decoration: none; font-weight: 700; font-size: 13px; display: inline-flex; align-items: center; gap: 6px; transition: 0.2s; cursor: pointer; }
        .btn-acao-header:hover { background: #f1f2f6; color: #2c3e50; }
        .btn-acao-header-import { color: #7f8c8d; border-color: #dcdde1; background: #f8f9fa; }
        .btn-acao-header-import:hover { background: #e1e5ea; color: #34495e; border-color: #bdc3c7;}

        .btn-acao-jdias { color: #e67e22; border-color: #fae5d3; background: #fdf2e9; }
        .btn-acao-jdias:hover { background: #fae5d3; color: #d35400; border-color: #f5cba7; }
        .btn-acao-lsjr { color: #2471a3; border-color: #d4e6f1; background: #ebf5fb; }
        .btn-acao-lsjr:hover { background: #d4e6f1; color: #1a5276; border-color: #a9cce3; }

        .card { background: #fff; padding: 25px; border-radius: 12px; border: 1px solid #e1e5ea; box-shadow: 0 2px 10px rgba(0,0,0,0.03); margin-bottom: 20px; }
        h2 { font-size: 15px; color: #27ae60; margin: 0 0 15px 0; border-bottom: 1px solid #f1f2f6; padding-bottom: 8px; text-transform: uppercase; display: flex; align-items: center; gap: 8px; }

        /* Dropdown Obra */
        .selector-box { background: #f8f9fa; border: 1px solid #dcdde1; padding: 20px; border-radius: 8px; margin-bottom: 25px; display: flex; flex-direction: column; gap: 10px;}
        .selector-box label { font-weight: 700; color: #2c3e50; text-transform: uppercase; font-size: 12px; }
        .selector-box select { width: 100%; padding: 12px; border-radius: 8px; border: 1px solid #bdc3c7; font-size: 15px; cursor: pointer; }
        .selector-box select:focus { border-color: #27ae60; box-shadow: 0 0 0 3px rgba(39, 174, 96, 0.1); }

        /* KPI Resumo Financeiro */
        .kpi-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 15px; margin-bottom: 25px; }
        .kpi-card { background: #fff; border-radius: 10px; padding: 20px; display: flex; align-items: center; justify-content: space-between; border: 1px solid #e1e5ea; position: relative; overflow: hidden; }
        .kpi-card::before { content: ''; position: absolute; left: 0; top: 0; bottom: 0; width: 4px; }
        .kpi-orcamento::before { background: #3498db; }
        .kpi-refeicoes::before { background: #16a085; }
        .kpi-horas::before { background: #e67e22; }
        .kpi-deslocacoes::before { background: #8e44ad; }
        .kpi-despesas-manuais::before { background: #c0392b; }
        .kpi-visitas::before { background: #2980b9; }

        .kpi-info { display: flex; flex-direction: column; }
        .kpi-title { font-size: 11px; color: #7f8c8d; text-transform: uppercase; font-weight: 700; margin-bottom: 5px; }
        .kpi-qtd { font-weight: 400; text-transform: none; color: #b2bec3; }
        .kpi-value { font-size: 22px; font-weight: 800; line-height: 1;}
        .kpi-value.kpi-sem-dados { font-size: 14px; color: #d35400; font-weight: 700; }
        .kpi-icon { font-size: 24px; opacity: 0.2; }

        .kpi-resultado-bar { background: #fff; border-radius: 10px; padding: 18px 25px; margin-bottom: 25px; border: 1px solid #e1e5ea; display: flex; align-items: center; justify-content: center; flex-wrap: wrap; gap: 15px; }
        .kpi-resultado-item { display: flex; flex-direction: column; align-items: center; text-align: center; }
        .kpi-resultado-label { font-size: 11px; color: #7f8c8d; text-transform: uppercase; font-weight: 700; margin-bottom: 4px; }
        .kpi-resultado-valor { font-size: 20px; font-weight: 800; line-height: 1; }
        .kpi-resultado-valor.kpi-sem-dados { font-size: 14px; color: #d35400; }
        .kpi-resultado-oper { font-size: 22px; font-weight: 800; color: #bdc3c7; }
        .kpi-resultado-item.kpi-resultado-final .kpi-resultado-label { color: #2c3e50; }
        .kpi-resultado-item.kpi-resultado-final .kpi-resultado-valor { font-size: 26px; }

        /* Tabelas por funcionário */
        .table-responsive { width: 100%; overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; }
        th { padding: 12px 15px; background: #f1f2f6; color: #576574; font-size: 11px; text-transform: uppercase; text-align: left; border-bottom: 2px solid #e1e5ea; font-weight: 700; }
        td { padding: 12px 15px; vertical-align: middle; color: #2c3e50; border-bottom: 1px dashed #f1f2f6; }
        tbody tr:last-child td { border-bottom: none; }

        .text-center { text-align: center !important; }
        .text-right { text-align: right !important; }

        tfoot td { font-weight: 800; font-size: 14px; color: #2c3e50; border-top: 2px solid #bdc3c7; background: #fdfdfd; }

        /* Grupos expansíveis (clicar no funcionário mostra o detalhe dia a dia) */
        .group-header td { cursor: pointer; transition: 0.2s; }
        .group-header:hover td { background-color: #f8f9fa; }
        .icon-toggle { width: 22px; height: 22px; display: inline-flex; align-items: center; justify-content: center; background: #e1e5ea; border-radius: 4px; color: #576574; font-size: 11px; transition: 0.2s; }
        .group-header:hover .icon-toggle { background: #34495e; color: #fff; }
        .detail-row td { background-color: #fafbfc; font-size: 13px; color: #576574; }
        .detail-row:hover td { background-color: #f5f6f8; }
        .badge-sozinho { background:#eafaf1; color:#27ae60; padding:3px 10px; border-radius:12px; font-size:11px; font-weight:700; border:1px solid #a9dfbf; }
        .badge-acompanhado { background:#fef5e7; color:#e67e22; padding:3px 10px; border-radius:12px; font-size:11px; font-weight:700; border:1px solid #f8d9a0; }

        .empty-inline { color: #95a5a6; font-size: 13px; padding: 10px 0; }

        .empty-state { text-align: center; padding: 40px 20px; color: #95a5a6; }
        .empty-state i { font-size: 40px; margin-bottom: 15px; color: #dcdde1; }
        .empty-state h3 { margin: 0 0 5px; color: #7f8c8d; }

        /* Alertas (mensagens de sucesso/erro) */
        .alert { padding: 15px; border-radius: 8px; margin-bottom: 20px; text-align: center; font-weight: 600; }
        .alert-sucesso { background: #e8f5e9; color: #2e7d32; border: 1px solid #c8e6c9; }
        .alert-erro { background: #fdeded; color: #c0392b; border: 1px solid #f9cccc; }

        /* Formulário de Registar Nova Despesa Manual */
        .form-row { display: flex; gap: 15px; align-items: flex-end; flex-wrap: wrap; }
        .form-group { display: flex; flex-direction: column; flex: 1; min-width: 150px; }
        .form-group label { font-weight: 700; margin-bottom: 6px; font-size: 11px; color: #576574; text-transform: uppercase; }
        .form-group input, .form-group select { padding: 10px 12px; border: 1px solid #dcdde1; border-radius: 8px; font-size: 14px; background: #fff; }
        .form-group input:focus, .form-group select:focus { border-color: #27ae60; box-shadow: 0 0 0 3px rgba(39, 174, 96, 0.1); }
        .btn-add-despesa { background: #27ae60; color: #fff; border: none; padding: 11px 25px; border-radius: 8px; font-weight: 700; cursor: pointer; transition: 0.2s; white-space: nowrap; }
        .btn-add-despesa:hover { background: #229954; }
        .btn-secondary { background: #f1f2f6; color: #576574; border: 1px solid #dcdde1; padding: 11px 15px; border-radius: 8px; font-weight: 600; cursor: pointer; transition: 0.2s; white-space: nowrap; display: none; align-items: center; gap: 5px; }
        .btn-secondary:hover { background: #e1e5ea; }
        .btn-icon { color: #576574; border: none; background: none; font-size: 16px; cursor: pointer; transition: color 0.2s; padding: 0; }
        .btn-icon:hover { color: #27ae60; }
        .btn-icon.btn-del:hover { color: #e74c3c; }

        /* Mapa Resumo (calendário) */
        .mapa-nav { display: flex; align-items: center; justify-content: center; gap: 20px; margin-bottom: 20px; }
        .btn-mapa-nav { width: 34px; height: 34px; border-radius: 50%; background: #f1f2f6; color: #576574; display: flex; align-items: center; justify-content: center; text-decoration: none; transition: 0.2s; }
        .btn-mapa-nav:hover { background: #27ae60; color: #fff; }
        .mapa-mes-atual { font-weight: 800; color: #2c3e50; font-size: 16px; text-transform: capitalize; min-width: 160px; text-align: center; }

        .mapa-calendario { display: grid; grid-template-columns: repeat(7, 1fr); gap: 6px; }
        .mapa-dia-semana { text-align: center; font-size: 11px; font-weight: 700; text-transform: uppercase; color: #95a5a6; padding-bottom: 6px; }
        .mapa-dia { min-height: 78px; border: 1px solid #f1f2f6; border-radius: 8px; padding: 6px 8px; display: flex; flex-direction: column; gap: 4px; background: #fdfdfd; }
        .mapa-dia-vazio { border: none; background: transparent; }
        .mapa-dia-numero { font-size: 12px; font-weight: 700; color: #bdc3c7; }
        .mapa-dia-com-dados { background: #eafaf1; border-color: #a9dfbf; cursor: help; }
        .mapa-dia-com-dados .mapa-dia-numero { color: #27ae60; }
        .mapa-dia-colaboradores { font-size: 11px; color: #576574; }
        .mapa-dia-horas { font-size: 11px; color: #e67e22; font-weight: 700; }
        .mapa-dia-valor { font-size: 12px; color: #2c3e50; font-weight: 800; margin-top: auto; }

        @media (max-width: 600px) {
            .mapa-dia { min-height: 60px; padding: 4px 5px; }
            .mapa-dia-colaboradores, .mapa-dia-horas { display: none; }
        }

        @media (max-width: 768px) {
            .topbar { padding: 10px 15px; }
            .btn-back span { display: none; }
            .page-header { margin-bottom: 15px; padding-bottom: 10px; }
            .page-title { font-size: 18px; }
            .kpi-grid { grid-template-columns: 1fr 1fr; }
            .form-row { flex-direction: column; align-items: stretch; }
        }
        @media (max-width: 480px) {
            .kpi-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>

    <header class="topbar">
        <div class="topbar-left"><a href="index.php"><img src="mab.png" alt="MAB" class="logo"></a></div>
        <div class="topbar-right">
            <a href="index.php" class="btn-back" title="Voltar"><i class="fa-solid fa-arrow-left"></i> <span>Voltar</span></a>
            <div class="user-menu">
                <button class="user-btn">
                    <div class="user-avatar"><i class="fa-solid fa-user"></i></div>
                    <?= htmlspecialchars($primeiro_nome) ?>
                    <i class="fa-solid fa-caret-down" style="font-size: 12px; color: #95a5a6;"></i>
                </button>
                <div class="dropdown-content">
                    <a href="perfil.php"><i class="fa-solid fa-id-badge" style="width:20px;"></i> Perfil</a>
                    <a href="index.php?logout=1" style="color: #e74c3c; border-top: 1px solid #f1f2f6;"><i class="fa-solid fa-right-from-bracket" style="width:20px;"></i> Sair</a>
                </div>
            </div>
        </div>
    </header>

    <div class="container">

        <?php if ($mensagem_erro): ?>
            <div style="background: #fdeded; color: #c0392b; padding: 15px; border-radius: 8px; margin-bottom: 20px; text-align: center; font-weight: bold; border: 1px solid #f9cccc;">
                <i class="fa-solid fa-triangle-exclamation"></i> <?= htmlspecialchars($mensagem_erro) ?>
            </div>
        <?php endif; ?>

        <?php if ($mensagem_despesa): ?>
            <div class="alert alert-<?= $tipo_mensagem_despesa ?>"><?= htmlspecialchars($mensagem_despesa) ?></div>
        <?php endif; ?>

        <div class="page-header">
            <h1 class="page-title"><i class="fa-solid fa-coins"></i> Gestão Central</h1>
            <div class="header-actions">
                <?php if ($obra_selecionada): ?>
                    <a href="gestao_relatorio.php?id_obra=<?= $obra_selecionada ?>" target="_blank" class="btn-acao-header" style="color:#27ae60; border-color:#a9dfbf; background:#eafaf1;"><i class="fa-solid fa-print"></i> Imprimir Relatório</a>
                <?php endif; ?>
                <a href="jdias.php" class="btn-acao-header btn-acao-jdias"><i class="fa-solid fa-file-invoice"></i> Faturação (J. Dias)</a>
                <a href="lsjr.php" class="btn-acao-header btn-acao-lsjr"><i class="fa-solid fa-file-invoice"></i> Faturação (LSJR)</a>
                <a href="funcionarios.php" class="btn-acao-header btn-acao-header-import"><i class="fa-solid fa-id-card-clip"></i> Gerir Funcionários (Equipa)</a>
            </div>
        </div>

        <div class="selector-box">
            <label for="select_obra"><i class="fa-solid fa-helmet-safety"></i> Selecione a Obra para Análise Financeira</label>
            <form method="GET" action="gestao.php">
                <select name="id_obra" id="select_obra" onchange="this.form.submit()">
                    <option value="">-- Escolha uma Obra --</option>
                    <?php foreach ($obras_lista as $obra): ?>
                        <option value="<?= $obra['id'] ?>" <?= ($obra_selecionada == $obra['id']) ? 'selected' : '' ?>>
                            <?= htmlspecialchars($obra['nome_cliente'] ?: 'Sem Cliente') ?> — <?= htmlspecialchars($obra['nome']) ?>
                            <?= !empty($obra['referencia_obra']) ? ' (Ref: ' . htmlspecialchars($obra['referencia_obra']) . ')' : '' ?>
                            [<?= htmlspecialchars($obra['estado']) ?>]
                        </option>
                    <?php endforeach; ?>
                </select>
            </form>
        </div>

        <?php if ($obra_selecionada): ?>

            <div class="kpi-grid">
                <div class="kpi-card kpi-orcamento">
                    <div class="kpi-info">
                        <span class="kpi-title">Orçamento Adjudicado</span>
                        <?php if ($tem_orcamento): ?>
                            <span class="kpi-value" style="color:#3498db;"><?= formatNumberToPT($valor_adjudicado) ?> €
                                <span style="font-size:13px; font-weight:700; color:#7f8c8d; margin-left:6px; white-space:nowrap;" title="Horas de trabalho orçamentadas (soma de quantidade × tempo de mão de obra de cada linha do orçamento)"><i class="fa-regular fa-clock"></i> <?= formatHoras($horas_orcamentadas) ?></span>
                            </span>
                        <?php else: ?>
                            <span class="kpi-value kpi-sem-dados"><i class="fa-solid fa-circle-info"></i> Sem orçamento adjudicado</span>
                        <?php endif; ?>
                    </div>
                    <i class="fa-solid fa-file-signature kpi-icon" style="color:#3498db;"></i>
                </div>

                <div class="kpi-card kpi-refeicoes">
                    <div class="kpi-info">
                        <span class="kpi-title">Refeições <span class="kpi-qtd">(<?= $total_n_refeicoes ?>)</span></span>
                        <span class="kpi-value" style="color:#16a085;"><?= formatNumberToPT($total_refeicoes) ?> €</span>
                    </div>
                    <i class="fa-solid fa-utensils kpi-icon" style="color:#16a085;"></i>
                </div>

                <div class="kpi-card kpi-horas">
                    <div class="kpi-info">
                        <span class="kpi-title">Horas Normais de Trabalho <span class="kpi-qtd">(<?= formatHoras($total_horas_normais) ?>)</span></span>
                        <span class="kpi-value" style="color:#e67e22;"><?= formatNumberToPT($valor_horas_normais) ?> €</span>
                    </div>
                    <i class="fa-solid fa-clock kpi-icon" style="color:#e67e22;"></i>
                </div>

                <div class="kpi-card kpi-deslocacoes">
                    <div class="kpi-info">
                        <span class="kpi-title">Deslocações <span class="kpi-qtd">(<?= $total_n_deslocacoes ?>)</span></span>
                        <span class="kpi-value" style="color:#8e44ad;"><?= formatNumberToPT($total_deslocacoes_valor) ?> €</span>
                    </div>
                    <i class="fa-solid fa-route kpi-icon" style="color:#8e44ad;"></i>
                </div>

                <div class="kpi-card kpi-despesas-manuais">
                    <div class="kpi-info">
                        <span class="kpi-title">Despesas Manuais</span>
                        <span class="kpi-value" style="color:#c0392b;"><?= formatNumberToPT($total_despesas_manuais) ?> €</span>
                    </div>
                    <i class="fa-solid fa-receipt kpi-icon" style="color:#c0392b;"></i>
                </div>

                <div class="kpi-card kpi-visitas">
                    <div class="kpi-info">
                        <span class="kpi-title">Visitas / Reuniões <span class="kpi-qtd">(<?= count($visitas_obra) ?>)</span></span>
                        <span class="kpi-value" style="color:#2980b9;"><?= formatNumberToPT($total_visitas) ?> €</span>
                    </div>
                    <i class="fa-solid fa-people-arrows kpi-icon" style="color:#2980b9;"></i>
                </div>

            </div>

            <div class="kpi-resultado-bar" title="Valor total acumulado (adjudicados + suplementares) do último auto de medição, menos o total de despesas (refeições + horas normais + deslocações + despesas manuais + visitas/reuniões).">
                <?php if ($tem_auto): ?>
                    <div class="kpi-resultado-item">
                        <span class="kpi-resultado-label">Valor dos Autos</span>
                        <span class="kpi-resultado-valor" style="color:#27ae60;"><?= formatNumberToPT($valor_total_autos) ?> €</span>
                    </div>
                    <span class="kpi-resultado-oper">−</span>
                    <div class="kpi-resultado-item">
                        <span class="kpi-resultado-label">Despesas</span>
                        <span class="kpi-resultado-valor" style="color:#c0392b;"><?= formatNumberToPT($total_despesas_obra) ?> €</span>
                    </div>
                    <span class="kpi-resultado-oper">=</span>
                    <div class="kpi-resultado-item kpi-resultado-final">
                        <span class="kpi-resultado-label">Resultado da Obra</span>
                        <span class="kpi-resultado-valor" style="color:<?= $resultado_obra >= 0 ? '#27ae60' : '#c0392b' ?>;"><?= formatNumberToPT($resultado_obra) ?> €</span>
                    </div>
                <?php else: ?>
                    <div class="kpi-resultado-item kpi-resultado-final">
                        <span class="kpi-resultado-label">Resultado da Obra</span>
                        <span class="kpi-resultado-valor kpi-sem-dados"><i class="fa-solid fa-circle-info"></i> Sem autos de medição</span>
                    </div>
                <?php endif; ?>
            </div>

            <div class="card" id="form_despesa_card">
                <h2 id="form_despesa_title"><i class="fa-solid fa-plus-circle"></i> Registar Nova Despesa Manual</h2>
                <form method="POST" action="gestao.php?id_obra=<?= $obra_selecionada ?>" class="form-row" id="formDespesaGestao">
                    <input type="hidden" name="add_despesa" id="add_despesa_flag_gestao" value="1">
                    <input type="hidden" name="id_despesa_edit" id="id_despesa_edit_gestao" value="">

                    <div class="form-group" style="flex: 0.8;">
                        <label>Tipo</label>
                        <select name="tipo_despesa" id="tipo_despesa_gestao" onchange="adaptarFormularioDespesa()" required>
                            <option value="Material">Compra Material</option>
                            <option value="Mão de Obra">Mão de Obra (Normal)</option>
                            <option value="Mão de Obra (Extra)">Mão de Obra (Extra)</option>
                            <option value="Deslocação">Deslocação / KM</option>
                            <option value="Refeição">Refeição</option>
                            <option value="Outras">Outras Despesas</option>
                        </select>
                    </div>

                    <div class="form-group" style="flex: 0.8;">
                        <label>Data</label>
                        <input type="date" name="data_despesa" id="data_despesa_gestao" value="<?= date('Y-m-d') ?>" required>
                    </div>

                    <div class="form-group" style="flex: 2;">
                        <label id="lbl_descricao_gestao">Fornecedor / Loja *</label>
                        <input type="text" name="descricao" id="input_descricao_gestao" placeholder="Ex: Leroy Merlin" required>
                    </div>

                    <div class="form-group" style="flex: 0.8; display:none;" id="box_quantidade_gestao">
                        <label id="lbl_quantidade_gestao">Qtd</label>
                        <input type="text" name="quantidade" id="input_quantidade_gestao" placeholder="0">
                    </div>

                    <div class="form-group" style="flex: 0.8;">
                        <label>Valor Total (€) *</label>
                        <input type="text" name="valor" id="input_valor_gestao" placeholder="0,00" required>
                    </div>

                    <div style="display:flex; gap:10px; align-items:flex-end;">
                        <button type="button" class="btn-secondary" id="btn_cancel_edit_gestao" onclick="cancelarEdicaoDespesa()"><i class="fa-solid fa-xmark"></i> Cancelar</button>
                        <button type="submit" class="btn-add-despesa" id="btn_submit_despesa_gestao"><i class="fa-solid fa-check"></i> Adicionar</button>
                    </div>
                </form>
                <p style="margin: 12px 0 0; font-size: 12px; color: #95a5a6;">
                    Para sincronizar despesas com os relatórios de obra, usa o botão na página
                    <a href="obra_despesas.php?id=<?= $obra_selecionada ?>" style="color:#27ae60; font-weight:700;">Despesas da Obra</a>.
                </p>
            </div>

            <div class="card">
                <h2><i class="fa-solid fa-receipt"></i> Despesas Manuais Registadas</h2>
                <?php if (empty($despesas_manuais)): ?>
                    <p class="empty-inline">Ainda não existem despesas manuais registadas nesta obra.</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table>
                            <thead>
                                <tr>
                                    <th style="width:100px;">Data</th>
                                    <th style="width:150px;">Tipo</th>
                                    <th>Descrição</th>
                                    <th style="width:140px;">Colaborador</th>
                                    <th class="text-right" style="width:120px;">Valor (€)</th>
                                    <th class="text-center" style="width:110px;">Ações</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($despesas_manuais as $dm): ?>
                                    <tr>
                                        <td><?= date('d/m/Y', strtotime($dm['data_despesa'])) ?></td>
                                        <td><?= htmlspecialchars($dm['tipo_despesa']) ?></td>
                                        <td><?= htmlspecialchars($dm['descricao']) ?></td>
                                        <td style="color:#16a085; font-weight:600; font-size:13px;"><?= htmlspecialchars($dm['nome_colaborador'] ?? '-') ?></td>
                                        <td class="text-right" style="font-weight:700;"><?= formatNumberToPT($dm['valor']) ?> €</td>
                                        <td class="text-center">
                                            <div style="display:flex; gap:8px; justify-content:center; align-items:center;">
                                                <button type="button" class="btn-icon" onclick='editarDespesaGestao(<?= json_encode($dm) ?>)' title="Editar"><i class="fa-solid fa-pen-to-square"></i></button>
                                                <form method="POST" action="gestao.php?id_obra=<?= $obra_selecionada ?>" style="margin:0;" onsubmit="return confirm('Duplicar esta despesa com a data de hoje?');">
                                                    <input type="hidden" name="duplicar_despesa_id" value="<?= $dm['id'] ?>">
                                                    <button type="submit" class="btn-icon" title="Duplicar"><i class="fa-solid fa-copy"></i></button>
                                                </form>
                                                <form method="POST" action="gestao.php?id_obra=<?= $obra_selecionada ?>" style="margin:0;" onsubmit="return confirm('Apagar esta despesa definitivamente?');">
                                                    <input type="hidden" name="apagar_despesa_id" value="<?= $dm['id'] ?>">
                                                    <button type="submit" class="btn-icon btn-del" title="Apagar"><i class="fa-solid fa-trash-can"></i></button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot>
                                <tr>
                                    <td colspan="4">Total</td>
                                    <td class="text-right"><?= formatNumberToPT($total_despesas_manuais) ?> €</td>
                                    <td></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                <?php endif; ?>
            </div>

            <div class="card">
                <h2><i class="fa-solid fa-people-arrows"></i> Visitas / Reuniões Registadas</h2>
                <?php if (empty($visitas_obra)): ?>
                    <p class="empty-inline">Ainda não existem visitas registadas nesta obra.</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table>
                            <thead>
                                <tr>
                                    <th style="width:100px;">Data</th>
                                    <th>Assunto</th>
                                    <th style="width:140px;">Colaborador</th>
                                    <th class="text-right" style="width:100px;">Deslocação</th>
                                    <th class="text-right" style="width:100px;">Mão de Obra (1h)</th>
                                    <th class="text-right" style="width:110px;">Total (€)</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($visitas_obra as $vt): ?>
                                    <tr>
                                        <td><?= date('d/m/Y', strtotime($vt['data'])) ?></td>
                                        <td><?= htmlspecialchars($vt['assunto']) ?></td>
                                        <td style="color:#16a085; font-weight:600; font-size:13px;"><?= htmlspecialchars($vt['nome_colaborador'] ?? '-') ?></td>
                                        <td class="text-right"><?= formatNumberToPT($vt['valor_deslocacao']) ?> €</td>
                                        <td class="text-right"><?= formatNumberToPT($vt['valor_mao_obra']) ?> €</td>
                                        <td class="text-right" style="font-weight:700;"><?= formatNumberToPT($vt['valor_total']) ?> €</td>
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
                    </div>
                <?php endif; ?>
            </div>

            <div class="card">
                <h2><i class="fa-solid fa-utensils"></i> Refeições por Funcionário <span style="font-size:11px; color:#95a5a6; text-transform:none; font-weight:400; margin-left:auto;">(clique num funcionário para ver o detalhe por dia)</span></h2>
                <?php if (empty($refeicoes_por_funcionario)): ?>
                    <p class="empty-inline">Sem refeições registadas para esta obra.</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table>
                            <thead>
                                <tr>
                                    <th style="width:30px;"></th>
                                    <th>Funcionário</th>
                                    <th class="text-center" style="width:140px;">Nº Refeições</th>
                                    <th class="text-right" style="width:140px;">Valor (€)</th>
                                </tr>
                            </thead>
                            <?php foreach ($refeicoes_por_funcionario as $id_f => $rf): ?>
                                <tbody class="group-header" onclick="toggleGroup('ref-<?= $id_f ?>')">
                                    <tr>
                                        <td class="text-center"><span class="icon-toggle" id="icon-ref-<?= $id_f ?>"><i class="fa-solid fa-plus"></i></span></td>
                                        <td><?= htmlspecialchars($rf['nome']) ?></td>
                                        <td class="text-center"><?= (int)$rf['n_refeicoes'] ?></td>
                                        <td class="text-right"><?= formatNumberToPT($rf['total_valor']) ?> €</td>
                                    </tr>
                                </tbody>
                                <tbody class="group-details" id="details-ref-<?= $id_f ?>" style="display:none;">
                                    <?php foreach ($rf['dias'] as $dia): ?>
                                        <tr class="detail-row">
                                            <td></td>
                                            <td><i class="fa-regular fa-calendar" style="color:#bdc3c7; margin-right:6px;"></i><?= date('d/m/Y', strtotime($dia['data'])) ?></td>
                                            <td class="text-center">—</td>
                                            <td class="text-right"><?= formatNumberToPT($dia['valor']) ?> €</td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            <?php endforeach; ?>
                            <tfoot>
                                <tr>
                                    <td></td>
                                    <td>Total</td>
                                    <td class="text-center"><?= array_sum(array_column($refeicoes_por_funcionario, 'n_refeicoes')) ?></td>
                                    <td class="text-right"><?= formatNumberToPT($total_refeicoes) ?> €</td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                <?php endif; ?>
            </div>

            <div class="card">
                <h2><i class="fa-solid fa-clock"></i> Horas Normais de Trabalho por Funcionário <span style="font-size:11px; color:#95a5a6; text-transform:none; font-weight:400; margin-left:auto;">(clique num funcionário para ver o detalhe por dia)</span></h2>
                <?php if (empty($horas_normais_por_funcionario)): ?>
                    <p class="empty-inline">Sem horas normais registadas para esta obra.</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table>
                            <thead>
                                <tr>
                                    <th style="width:30px;"></th>
                                    <th>Funcionário</th>
                                    <th class="text-center" style="width:110px;">€/h</th>
                                    <th class="text-center" style="width:140px;">Horas</th>
                                    <th class="text-right" style="width:140px;">Valor (€)</th>
                                </tr>
                            </thead>
                            <?php foreach ($horas_normais_por_funcionario as $id_f => $hn): ?>
                                <tbody class="group-header" onclick="toggleGroup('hn-<?= $id_f ?>')">
                                    <tr>
                                        <td class="text-center"><span class="icon-toggle" id="icon-hn-<?= $id_f ?>"><i class="fa-solid fa-plus"></i></span></td>
                                        <td><?= htmlspecialchars($hn['nome']) ?></td>
                                        <td class="text-center"><?= ($hn['valor_hora'] !== null) ? formatNumberToPT($hn['valor_hora']) . ' €' : '—' ?></td>
                                        <td class="text-center"><?= formatHoras($hn['total_horas']) ?></td>
                                        <td class="text-right"><?= formatNumberToPT($hn['total_valor']) ?> €</td>
                                    </tr>
                                </tbody>
                                <tbody class="group-details" id="details-hn-<?= $id_f ?>" style="display:none;">
                                    <?php foreach ($hn['dias'] as $dia): ?>
                                        <tr class="detail-row">
                                            <td></td>
                                            <td><i class="fa-regular fa-calendar" style="color:#bdc3c7; margin-right:6px;"></i><?= date('d/m/Y', strtotime($dia['data'])) ?></td>
                                            <td class="text-center"><?= ($hn['valor_hora'] !== null) ? formatNumberToPT($hn['valor_hora']) . ' €' : '—' ?></td>
                                            <td class="text-center"><?= formatHoras($dia['horas']) ?></td>
                                            <td class="text-right"><?= formatNumberToPT($dia['valor']) ?> €</td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            <?php endforeach; ?>
                            <tfoot>
                                <tr>
                                    <td></td>
                                    <td>Total</td>
                                    <td class="text-center">—</td>
                                    <td class="text-center"><?= formatHoras($total_horas_normais) ?></td>
                                    <td class="text-right"><?= formatNumberToPT($valor_horas_normais) ?> €</td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                <?php endif; ?>
            </div>

            <div class="card">
                <h2><i class="fa-solid fa-route"></i> Deslocações por Funcionário <span style="font-size:11px; color:#95a5a6; text-transform:none; font-weight:400; margin-left:auto;">(clique num funcionário para ver o detalhe por dia)</span></h2>
                <?php if (empty($deslocacoes_por_funcionario)): ?>
                    <p class="empty-inline">Sem deslocações registadas para esta obra.</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table>
                            <thead>
                                <tr>
                                    <th style="width:30px;"></th>
                                    <th>Funcionário</th>
                                    <th class="text-center" style="width:140px;">Nº Deslocações</th>
                                    <th class="text-center" style="width:120px;">Km Atribuídos</th>
                                    <th class="text-center" style="width:110px;">€/km</th>
                                    <th class="text-right" style="width:140px;">Valor (€)</th>
                                </tr>
                            </thead>
                            <?php foreach ($deslocacoes_por_funcionario as $id_f => $dl): ?>
                                <tbody class="group-header" onclick="toggleGroup('desl-<?= $id_f ?>')">
                                    <tr>
                                        <td class="text-center"><span class="icon-toggle" id="icon-desl-<?= $id_f ?>"><i class="fa-solid fa-plus"></i></span></td>
                                        <td><?= htmlspecialchars($dl['nome']) ?></td>
                                        <td class="text-center"><?= (int)$dl['n_deslocacoes'] ?></td>
                                        <td class="text-center"><?= formatNumberToPT($dl['total_km']) ?></td>
                                        <td class="text-center"><?= ($dl['valor_km_medio'] !== null) ? formatNumberToPT($dl['valor_km_medio']) . ' €' : '—' ?></td>
                                        <td class="text-right"><?= formatNumberToPT($dl['total_valor']) ?> €</td>
                                    </tr>
                                </tbody>
                                <tbody class="group-details" id="details-desl-<?= $id_f ?>" style="display:none;">
                                    <?php foreach ($dl['dias'] as $dia): ?>
                                        <tr class="detail-row">
                                            <td></td>
                                            <td><i class="fa-regular fa-calendar" style="color:#bdc3c7; margin-right:6px;"></i><?= date('d/m/Y', strtotime($dia['data'])) ?></td>
                                            <td class="text-center">
                                                <?php if ($dia['sozinho']): ?>
                                                    <span class="badge-sozinho"><i class="fa-solid fa-user"></i> Sozinho</span>
                                                <?php else: ?>
                                                    <span class="badge-acompanhado"><i class="fa-solid fa-user-group"></i> Acompanhado</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-center"><?= formatNumberToPT($dia['km']) ?></td>
                                            <td class="text-center"><?= ($dia['valor_km'] !== null) ? formatNumberToPT($dia['valor_km']) . ' €' : '—' ?></td>
                                            <td class="text-right"><?= formatNumberToPT($dia['valor']) ?> €</td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            <?php endforeach; ?>
                            <tfoot>
                                <tr>
                                    <td></td>
                                    <td>Total</td>
                                    <td class="text-center"><?= array_sum(array_column($deslocacoes_por_funcionario, 'n_deslocacoes')) ?></td>
                                    <td class="text-center"><?= formatNumberToPT(array_sum(array_column($deslocacoes_por_funcionario, 'total_km'))) ?></td>
                                    <td class="text-center">—</td>
                                    <td class="text-right"><?= formatNumberToPT($total_deslocacoes_valor) ?> €</td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                <?php endif; ?>
            </div>

            <div class="card">
                <h2><i class="fa-solid fa-calendar-days"></i> Mapa Resumo da Obra</h2>
                <div class="mapa-nav">
                    <a href="?id_obra=<?= $obra_selecionada ?>&mes_mapa=<?= $mes_mapa_anterior ?>" class="btn-mapa-nav" title="Mês anterior"><i class="fa-solid fa-chevron-left"></i></a>
                    <span class="mapa-mes-atual"><?= htmlspecialchars($nome_mes_mapa) ?></span>
                    <a href="?id_obra=<?= $obra_selecionada ?>&mes_mapa=<?= $mes_mapa_seguinte ?>" class="btn-mapa-nav" title="Mês seguinte"><i class="fa-solid fa-chevron-right"></i></a>
                </div>

                <?php if (empty($mapa_dias)): ?>
                    <p class="empty-inline">Sem atividade registada nesta obra.</p>
                <?php else: ?>
                    <div class="mapa-calendario">
                        <?php foreach (['Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb', 'Dom'] as $dia_semana_nome): ?>
                            <div class="mapa-dia-semana"><?= $dia_semana_nome ?></div>
                        <?php endforeach; ?>

                        <?php for ($i = 0; $i < ($primeiro_dia_semana_mapa - 1); $i++): ?>
                            <div class="mapa-dia mapa-dia-vazio"></div>
                        <?php endfor; ?>

                        <?php for ($dia = 1; $dia <= $dias_no_mes_mapa; $dia++):
                            $data_str_mapa = $mes_mapa . '-' . str_pad($dia, 2, '0', STR_PAD_LEFT);
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
                                            <i class="fa-solid fa-car-side" style="margin-left:6px;"></i> <?= $n_viaturas_dia ?>
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
                <?php endif; ?>
            </div>

        <?php elseif (!$mensagem_erro): ?>
            <div class="empty-state" style="background: #fff; border-radius: 12px; border: 1px dashed #dcdde1; padding: 60px 20px;">
                <i class="fa-solid fa-hand-pointer" style="color:#bdc3c7;"></i>
                <h3>Selecione uma obra acima</h3>
                <p>Escolha um projeto no menu para visualizar os dados financeiros da obra.</p>
            </div>
        <?php endif; ?>

    </div>

    <script>
        function adaptarFormularioDespesa() {
            const tipo = document.getElementById('tipo_despesa_gestao').value;
            const lblDesc = document.getElementById('lbl_descricao_gestao');
            const inpDesc = document.getElementById('input_descricao_gestao');
            const boxQtd = document.getElementById('box_quantidade_gestao');
            const lblQtd = document.getElementById('lbl_quantidade_gestao');

            boxQtd.style.display = 'none';
            inpDesc.required = true;

            if (tipo === 'Material') {
                lblDesc.innerText = 'Fornecedor / Loja *';
                inpDesc.placeholder = 'Ex: Leroy Merlin';
            } else if (tipo.includes('Mão de Obra')) {
                lblDesc.innerText = 'Descrição (Opcional)';
                inpDesc.placeholder = 'Ex: Trabalhos extra...';
                inpDesc.required = false;
                boxQtd.style.display = 'flex';
                lblQtd.innerText = 'Horas';
            } else if (tipo === 'Deslocação') {
                lblDesc.innerText = 'Origem - Destino *';
                inpDesc.placeholder = 'Ex: Sede - Obra';
                boxQtd.style.display = 'flex';
                lblQtd.innerText = 'KM';
            } else if (tipo === 'Refeição') {
                lblDesc.innerText = 'Restaurante / Colaborador *';
                inpDesc.placeholder = 'Ex: Restaurante O Manuel';
            } else {
                lblDesc.innerText = 'Descrição da Despesa *';
                inpDesc.placeholder = 'Ex: Portagens';
            }
        }

        // MODO EDIÇÃO da despesa manual
        function editarDespesaGestao(d) {
            document.getElementById('id_despesa_edit_gestao').value = d.id;
            document.getElementById('tipo_despesa_gestao').value = d.tipo_despesa;
            document.getElementById('data_despesa_gestao').value = d.data_despesa;
            document.getElementById('input_descricao_gestao').value = d.descricao;
            document.getElementById('input_quantidade_gestao').value = d.quantidade > 0 ? parseFloat(d.quantidade).toString().replace('.', ',') : '';
            document.getElementById('input_valor_gestao').value = parseFloat(d.valor).toString().replace('.', ',');

            adaptarFormularioDespesa();

            document.getElementById('form_despesa_title').innerHTML = '<i class="fa-solid fa-pen-to-square"></i> Editar Despesa Manual';
            document.getElementById('btn_submit_despesa_gestao').innerHTML = '<i class="fa-solid fa-floppy-disk"></i> Atualizar';
            document.getElementById('add_despesa_flag_gestao').name = 'edit_despesa';
            document.getElementById('btn_cancel_edit_gestao').style.display = 'inline-flex';

            window.scrollTo({ top: document.getElementById('form_despesa_card').offsetTop - 80, behavior: 'smooth' });
        }

        function cancelarEdicaoDespesa() {
            document.getElementById('id_despesa_edit_gestao').value = '';
            document.getElementById('tipo_despesa_gestao').value = 'Material';
            document.getElementById('data_despesa_gestao').value = '<?= date('Y-m-d') ?>';
            document.getElementById('input_descricao_gestao').value = '';
            document.getElementById('input_quantidade_gestao').value = '';
            document.getElementById('input_valor_gestao').value = '';

            adaptarFormularioDespesa();

            document.getElementById('form_despesa_title').innerHTML = '<i class="fa-solid fa-plus-circle"></i> Registar Nova Despesa Manual';
            document.getElementById('btn_submit_despesa_gestao').innerHTML = '<i class="fa-solid fa-check"></i> Adicionar';
            document.getElementById('add_despesa_flag_gestao').name = 'add_despesa';
            document.getElementById('btn_cancel_edit_gestao').style.display = 'none';
        }

        function toggleGroup(id) {
            const details = document.getElementById('details-' + id);
            const icon = document.getElementById('icon-' + id).querySelector('i');

            if (details.style.display === 'none' || details.style.display === '') {
                details.style.display = 'table-row-group';
                icon.classList.replace('fa-plus', 'fa-minus');
            } else {
                details.style.display = 'none';
                icon.classList.replace('fa-minus', 'fa-plus');
            }
        }
    </script>
</body>
</html>