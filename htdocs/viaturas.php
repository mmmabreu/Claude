<?php
session_start();
require_once 'db.php';
// REGRA DE OURO 1: Validação de sessão
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}
if (file_exists('functions.php')) {
    require_once 'functions.php';
} else {
    function registrarLog($pdo, $acao, $modulo) { return true; }
}
$perfil_user = $_SESSION['perfil'] ?? '';
$is_admin = in_array($perfil_user, ['admin', 'administrativo']);
// REGRA DE OURO 2: Acesso Restrito pela Matriz
$stmt_perm = $pdo->prepare("SELECT modulo FROM permissoes WHERE grupo = :perfil AND pode_aceder = 1");
$stmt_perm->execute(['perfil' => $perfil_user]);
$meus_acessos = $stmt_perm->fetchAll(PDO::FETCH_COLUMN);
if (!$is_admin && !in_array('viaturas', $meus_acessos)) {
    header("Location: index.php?erro=sem_permissao");
    exit;
}
// REGRA DE OURO 3: Obter apenas o primeiro nome para o Menu
$nome_completo = $_SESSION['nome'] ?? 'Utilizador';
$primeiro_nome = explode(' ', trim($nome_completo))[0];
$id_utilizador_logado = $_SESSION['user_id'];
$mensagem = '';
$tipo_mensagem = '';
// Buscar a Frota das Configurações
$stmt_frota = $pdo->query("SELECT valor FROM configuracoes WHERE chave = 'frota_matriculas'");
$frota_json = $stmt_frota->fetchColumn();
$frota = json_decode($frota_json, true) ?: [];
// Só as viaturas Ativas (ver Configurações > Frota) devem poder ser escolhidas para um NOVO
// registo (diário ou de combustível). Viaturas emprestadas/inativas continuam a poder ser
// consultadas no histórico e nos filtros do "Controlo Mensal", só não entram nestes dropdowns.
// Por omissão, uma viatura sem a chave 'ativa' (gravada antes desta funcionalidade existir) conta como ativa.
$frota_ativas = array_values(array_filter($frota, function($v) {
    return !isset($v['ativa']) || $v['ativa'];
}));
// Lista de Colaboradores (só é preciso para o Admin/Administrativo poderem escolher
// qualquer nome no Registo de Combustível, e para o modal de Editar Abastecimento;
// um utilizador normal fica sempre associado a si próprio)
$lista_utilizadores = [];
if ($is_admin) {
    $lista_utilizadores = $pdo->query("SELECT id, nome FROM utilizadores ORDER BY nome ASC")->fetchAll(PDO::FETCH_ASSOC);
}
// --- PROCESSAR NOVO REGISTO DE VIATURA (REGISTO DIÁRIO) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['btn_gravar_registo'])) {
    $matricula = trim($_POST['matricula'] ?? '');
    $data_registo = $_POST['data_registo'] ?? date('Y-m-d');
    $hora_inicio = $_POST['hora_inicio'] ?: null;
    $hora_fim = $_POST['hora_fim'] ?: null;
    $km_inicio = (int)($_POST['km_inicio'] ?? 0);
    $km_fim = (int)($_POST['km_fim'] ?? 0);
    $descricao = trim($_POST['descricao_viagem'] ?? '');
    if (empty($matricula)) {
        $mensagem = "Tem de selecionar uma matrícula válida.";
        $tipo_mensagem = "erro";
    } elseif ($km_fim > 0 && $km_fim < $km_inicio) {
        $mensagem = "Erro: Os KM finais não podem ser inferiores aos KM iniciais.";
        $tipo_mensagem = "erro";
    } else {
        try {
            $sql = "INSERT INTO frota_registos (matricula, data_registo, id_utilizador, hora_inicio, hora_fim, km_inicio, km_fim, descricao_viagem)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$matricula, $data_registo, $id_utilizador_logado, $hora_inicio, $hora_fim, $km_inicio, $km_fim, $descricao]);

            $distancia = $km_fim > 0 ? ($km_fim - $km_inicio) : 0;
            registrarLog($pdo, "Registou viagem de $distancia km na viatura $matricula", "Viaturas");

            $mensagem = "Registo diário guardado com sucesso!";
            $tipo_mensagem = "sucesso";
        } catch (Exception $e) {
            $mensagem = "Erro ao guardar registo: " . $e->getMessage();
            $tipo_mensagem = "erro";
        }
    }
}
// --- PROCESSAR NOVO REGISTO DE COMBUSTÍVEL ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['btn_gravar_combustivel'])) {
    $matricula_c = trim($_POST['matricula_combustivel'] ?? '');
    $data_c = $_POST['data_combustivel'] ?? date('Y-m-d');
    $km_c = (int)($_POST['km_atual_combustivel'] ?? 0);
    $litros_c = (float)str_replace(',', '.', $_POST['litros_combustivel'] ?? 0);
    $unidade_c = (($_POST['unidade_combustivel'] ?? 'L') === 'kWh') ? 'kWh' : 'L';
    $atestou_c = (($_POST['atestou_combustivel'] ?? 'Sim') === 'Não') ? 'Não' : 'Sim';
    // REGRA DE ACESSO: um utilizador normal (funcionário/externo) só pode registar em nome
    // dele próprio — nunca confiar no valor vindo do POST para estes casos, mesmo que o
    // campo esteja "disabled" no HTML (isso não impede alguém de manipular o pedido).
    // Só Admin/Administrativo podem de facto escolher outro colaborador da lista.
    if ($is_admin) {
        $id_utilizador_c = filter_input(INPUT_POST, 'id_utilizador_combustivel', FILTER_VALIDATE_INT) ?: $id_utilizador_logado;
    } else {
        $id_utilizador_c = $id_utilizador_logado;
    }
    if (empty($matricula_c)) {
        $mensagem = "Tem de selecionar uma viatura.";
        $tipo_mensagem = "erro";
    } elseif ($km_c <= 0) {
        $mensagem = "Indique os KM atuais da viatura.";
        $tipo_mensagem = "erro";
    } elseif ($litros_c <= 0) {
        $mensagem = "Indique a quantidade abastecida/carregada.";
        $tipo_mensagem = "erro";
    } else {
        try {
            $stmt = $pdo->prepare("INSERT INTO frota_combustivel (matricula, id_utilizador, data_registo, km_atual, litros, unidade, atestou) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$matricula_c, $id_utilizador_c, $data_c, $km_c, $litros_c, $unidade_c, $atestou_c]);
            registrarLog($pdo, "Registou abastecimento da viatura $matricula_c ($km_c km, {$litros_c}{$unidade_c}, atestou: $atestou_c)", "Viaturas");
            $mensagem = "Abastecimento registado com sucesso!";
            $tipo_mensagem = "sucesso";
        } catch (Exception $e) {
            $mensagem = "Erro ao guardar abastecimento: " . $e->getMessage();
            $tipo_mensagem = "erro";
        }
    }
}
// --- PROCESSAR EDIÇÃO DE REGISTO DE COMBUSTÍVEL (só Admin) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['btn_editar_combustivel'])) {
    if (!$is_admin) {
        $mensagem = "Erro: Apenas administradores podem editar registos de combustível.";
        $tipo_mensagem = "erro";
    } else {
        $id_editar = filter_input(INPUT_POST, 'id_combustivel_editar', FILTER_VALIDATE_INT);
        $matricula_e = trim($_POST['matricula_editar'] ?? '');
        $id_utilizador_e = filter_input(INPUT_POST, 'id_utilizador_editar', FILTER_VALIDATE_INT);
        $data_e = $_POST['data_editar'] ?? date('Y-m-d');
        $km_e = (int)($_POST['km_editar'] ?? 0);
        $litros_e = (float)str_replace(',', '.', $_POST['litros_editar'] ?? 0);
        $unidade_e = (($_POST['unidade_editar'] ?? 'L') === 'kWh') ? 'kWh' : 'L';
        $atestou_e = (($_POST['atestou_editar'] ?? 'Sim') === 'Não') ? 'Não' : 'Sim';

        if (!$id_editar || empty($matricula_e) || !$id_utilizador_e) {
            $mensagem = "Dados inválidos para edição do abastecimento.";
            $tipo_mensagem = "erro";
        } elseif ($km_e <= 0 || $litros_e <= 0) {
            $mensagem = "KM e quantidade abastecida/carregada têm de ser superiores a zero.";
            $tipo_mensagem = "erro";
        } else {
            try {
                $stmt = $pdo->prepare("UPDATE frota_combustivel SET matricula=?, id_utilizador=?, data_registo=?, km_atual=?, litros=?, unidade=?, atestou=? WHERE id=?");
                $stmt->execute([$matricula_e, $id_utilizador_e, $data_e, $km_e, $litros_e, $unidade_e, $atestou_e, $id_editar]);
                registrarLog($pdo, "Editou registo de combustível (ID: $id_editar)", "Viaturas");
                $mensagem = "Abastecimento atualizado com sucesso!";
                $tipo_mensagem = "sucesso";
            } catch (Exception $e) {
                $mensagem = "Erro ao editar abastecimento: " . $e->getMessage();
                $tipo_mensagem = "erro";
            }
        }
    }
}
// --- APAGAR REGISTO (Diário ou de Combustível) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['btn_confirmar_apagar'])) {
    if (!$is_admin) {
        $mensagem = "Erro: Apenas administradores podem apagar registos de frota.";
        $tipo_mensagem = "erro";
    } else {
        $id_apagar = filter_input(INPUT_POST, 'id_registo_apagar', FILTER_VALIDATE_INT);
        $tipo_apagar = ($_POST['tipo_registo_apagar'] ?? 'diario') === 'combustivel' ? 'combustivel' : 'diario';
        $pass_introduzida = $_POST['pass_seguranca'] ?? '';
        $stmt_pass = $pdo->prepare("SELECT valor FROM configuracoes WHERE chave = 'password_apagar' LIMIT 1");
        $stmt_pass->execute();
        $pass_config = $stmt_pass->fetchColumn();
        if ($pass_introduzida !== $pass_config || empty($pass_introduzida)) {
            $mensagem = "Password de segurança incorreta.";
            $tipo_mensagem = "erro";
        } else {
            try {
                if ($tipo_apagar === 'combustivel') {
                    $pdo->prepare("DELETE FROM frota_combustivel WHERE id = ?")->execute([$id_apagar]);
                    registrarLog($pdo, "Apagou registo de combustível (ID: $id_apagar)", "Viaturas");
                } else {
                    $pdo->prepare("DELETE FROM frota_registos WHERE id = ?")->execute([$id_apagar]);
                    registrarLog($pdo, "Apagou registo de frota (ID: $id_apagar)", "Viaturas");
                }
                $mensagem = "Registo removido com sucesso.";
                $tipo_mensagem = "sucesso";
            } catch (Exception $e) {
                $mensagem = "Erro ao apagar registo: " . htmlspecialchars($e->getMessage());
                $tipo_mensagem = "erro";
            }
        }
    }
}
// --- FILTROS DE PESQUISA E LISTAGEM (REGISTO DIÁRIO / CONTROLO MENSAL) ---
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
// LÓGICA DE VISIBILIDADE: Se NÃO for admin/administrativo, vê apenas os seus registos
if (!$is_admin) {
    $where[] = "f.id_utilizador = ?";
    $params[] = $id_utilizador_logado;
}
// --- BUSCAR DADOS ---
$where_clause = implode(" AND ", $where);
$sql_list = "SELECT f.*, u.nome as nome_colaborador
             FROM frota_registos f
             LEFT JOIN utilizadores u ON f.id_utilizador = u.id
             WHERE $where_clause
             ORDER BY f.data_registo DESC, f.hora_inicio DESC";
$stmt_list = $pdo->prepare($sql_list);
$stmt_list->execute($params);
$registos = $stmt_list->fetchAll(PDO::FETCH_ASSOC);
// Cálculo para o Dashboard do Filtro
$total_km = 0;
$total_viagens = count($registos);
foreach ($registos as $r) {
    if ($r['km_fim'] > $r['km_inicio']) {
        $total_km += ($r['km_fim'] - $r['km_inicio']);
    }
}
// --- LISTAGEM DE REGISTOS DE COMBUSTÍVEL (mesma regra de visibilidade do Registo Diário) ---
$where_combustivel = [];
$params_combustivel = [];
if (!$is_admin) {
    $where_combustivel[] = "c.id_utilizador = ?";
    $params_combustivel[] = $id_utilizador_logado;
}
$where_combustivel_clause = !empty($where_combustivel) ? "WHERE " . implode(" AND ", $where_combustivel) : "";
$sql_combustivel = "SELECT c.*, u.nome as nome_colaborador
                     FROM frota_combustivel c
                     LEFT JOIN utilizadores u ON c.id_utilizador = u.id
                     $where_combustivel_clause
                     ORDER BY c.data_registo DESC, c.id DESC";
$stmt_combustivel = $pdo->prepare($sql_combustivel);
$stmt_combustivel->execute($params_combustivel);
$registos_combustivel = $stmt_combustivel->fetchAll(PDO::FETCH_ASSOC);

// --- AGRUPAR ABASTECIMENTOS POR VIATURA (para a listagem expansível) ---
// $registos_combustivel já vem ordenado por data DESC, id DESC, por isso o primeiro
// registo encontrado para cada matrícula, ao agrupar, é sempre o mais recente dessa viatura.
$combustivel_agrupado = [];
foreach ($registos_combustivel as $rc) {
    $combustivel_agrupado[$rc['matricula']][] = $rc;
}

// --- RESUMO POR VIATURA (KM percorridos e litros abastecidos, do 1º ao último registo) ---
// Não calculamos aqui um consumo médio a partir do total de litros / total de km, porque não
// há garantia de que os km percorridos entre dois abastecimentos quaisquer tenham sido feitos
// só com o combustível desse abastecimento — isso só é fiável quando o depósito foi atestado
// em dois abastecimentos CONSECUTIVOS dessa viatura (método "cheio a cheio"). Por isso
// calculamos antes a "Última Média de Consumo": o valor do intervalo cheio-a-cheio mais
// recente dessa viatura, quando existir. Esta métrica só é mostrada a administradores.
$resumo_por_matricula = [];
$registos_por_matricula = [];
foreach ($registos_combustivel as $rc) {
    $registos_por_matricula[$rc['matricula']][] = $rc;
}
foreach ($registos_por_matricula as $matricula_key => $lista_mat) {
    usort($lista_mat, function($a, $b) {
        $cmp = strcmp($a['data_registo'], $b['data_registo']);
        return $cmp !== 0 ? $cmp : ($a['id'] <=> $b['id']);
    });

    $primeiro_mat = $lista_mat[0];
    $ultimo_mat = $lista_mat[count($lista_mat) - 1];
    // A unidade "oficial" da viatura para efeitos de apresentação é a do registo mais recente
    // (uma viatura é, na prática, sempre a combustível OU sempre elétrica; só muda se, por
    // engano, alguém registar com a unidade errada — nesse caso o resumo segue o último registo).
    $unidade_mat = $ultimo_mat['unidade'] ?? 'L';
    $total_litros_mat = array_sum(array_column($lista_mat, 'litros'));
    $total_km_mat = ($ultimo_mat['km_atual'] > $primeiro_mat['km_atual']) ? ($ultimo_mat['km_atual'] - $primeiro_mat['km_atual']) : 0;

    // Percorre os abastecimentos por ordem cronológica e vai sobrescrevendo o valor sempre que
    // encontra um par consecutivo "atestou/atestou" válido, NA MESMA UNIDADE — no fim do ciclo
    // fica o mais recente. (Misturar litros com kWh no mesmo cálculo não faria sentido.)
    $ultima_media_consumo = null;
    $unidade_ultima_media = null;
    for ($i = 1; $i < count($lista_mat); $i++) {
        $atestou_atual = ($lista_mat[$i]['atestou'] ?? 'Sim') === 'Sim';
        $atestou_anterior = ($lista_mat[$i - 1]['atestou'] ?? 'Sim') === 'Sim';
        $unidade_atual = $lista_mat[$i]['unidade'] ?? 'L';
        $unidade_anterior = $lista_mat[$i - 1]['unidade'] ?? 'L';
        if ($atestou_atual && $atestou_anterior && $unidade_atual === $unidade_anterior) {
            $km_intervalo = $lista_mat[$i]['km_atual'] - $lista_mat[$i - 1]['km_atual'];
            $litros_intervalo = $lista_mat[$i]['litros'] ?? 0;
            if ($km_intervalo > 0 && $litros_intervalo > 0) {
                $ultima_media_consumo = ($litros_intervalo / $km_intervalo) * 100;
                $unidade_ultima_media = $unidade_atual;
            }
        }
    }

    $resumo_por_matricula[$matricula_key] = [
        'num_registos' => count($lista_mat),
        'data_primeiro' => $primeiro_mat['data_registo'],
        'data_ultimo' => $ultimo_mat['data_registo'],
        'total_litros' => $total_litros_mat,
        'total_km' => $total_km_mat,
        'unidade' => $unidade_mat,
        'ultima_media_consumo' => $ultima_media_consumo,
        'unidade_ultima_media' => $unidade_ultima_media,
    ];
}

// --- MANTER A ABA ATIVA APÓS GRAVAR/EDITAR/APAGAR ---
// Cada formulário desta página envia um campo escondido "aba_ativa" com o separador onde o
// utilizador estava; se não vier nenhum (pedido GET normal), decide-se pelos parâmetros do
// filtro do Controlo Mensal, tal como já acontecia antes.
$abas_validas = ['registo', 'combustivel', 'controlo'];
$aba_inicial = 'registo';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['aba_ativa'] ?? '', $abas_validas, true)) {
    $aba_inicial = $_POST['aba_ativa'];
} elseif (isset($_GET['mes']) || isset($_GET['matricula'])) {
    $aba_inicial = 'controlo';
}
?>
<!DOCTYPE html>
<html lang="pt-PT">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Viaturas e Frota - Gestão MAB</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { font-family: system-ui, -apple-system, sans-serif; background-color: #f7f9fc; margin: 0; color: #333; padding-bottom: 50px; }
        * { box-sizing: border-box; }

        .topbar { background-color: #fff; padding: 10px 40px; box-shadow: 0 2px 15px rgba(0,0,0,0.05); display: flex; justify-content: space-between; align-items: center; position: sticky; top: 0; z-index: 100; }
        .topbar-left .logo { height: 35px; }
        .topbar-center { display: flex; align-items: center; gap: 20px; }
        .nav-icon { color: #95a5a6; font-size: 20px; text-decoration: none; transition: 0.2s; padding: 5px; border-radius: 6px; }
        .nav-icon:hover { background: #f1f2f6; }

        /* Cores Temáticas Regras de Ouro */
        .nav-icon.i-clientes.active, .nav-icon.i-clientes:hover { color: #3498db; }
        .nav-icon.i-visitas.active, .nav-icon.i-visitas:hover { color: #e84393; }
        .nav-icon.i-servicos.active, .nav-icon.i-servicos:hover { color: #b32a2a; }
        .nav-icon.i-obras.active, .nav-icon.i-obras:hover { color: #2ecc71; }
        .nav-icon.i-relatorios.active, .nav-icon.i-relatorios:hover { color: #16a085; }
        .nav-icon.i-orcamentos.active, .nav-icon.i-orcamentos:hover { color: #f39c12; }
        .nav-icon.i-termos.active, .nav-icon.i-termos:hover { color: #9b59b6; }
        .nav-icon.i-equipa.active, .nav-icon.i-equipa:hover { color: #7f8c8d; }
        .nav-icon.i-viaturas.active, .nav-icon.i-viaturas:hover { color: #d35400; }
        .nav-icon.i-config.active, .nav-icon.i-config:hover { color: #2c3e50; }
        .topbar-right { display: flex; align-items: center; gap: 20px; }
        .btn-back { color: #576574; text-decoration: none; font-size: 20px; transition: color 0.2s; }
        .btn-back:hover { color: #d35400; }

        .user-menu { position: relative; }
        .user-btn { background: #fdfdfd; border: 1px solid #f1f2f6; border-radius: 30px; font-size: 14px; font-weight: 600; color: #2c3e50; cursor: pointer; display: flex; align-items: center; gap: 10px; padding: 6px 15px 6px 6px; transition: 0.2s; }
        .user-btn:hover { background: #fff; box-shadow: 0 4px 10px rgba(0,0,0,0.05); }
        .user-avatar { background: #e74c3c; color: white; width: 28px; height: 28px; border-radius: 50%; display: flex; justify-content: center; align-items: center; font-size: 14px; border: 2px solid #fadbd8; }

        .dropdown-content { display: none; position: absolute; right: 0; top: 100%; margin-top: 5px; background: #fff; min-width: 180px; box-shadow: 0 10px 30px rgba(0,0,0,0.1); border-radius: 8px; overflow: hidden; border: 1px solid #f1f2f6; z-index:200;}
        .dropdown-content a { color: #576574; padding: 12px 20px; text-decoration: none; display: block; font-size: 14px; }
        .dropdown-content a:hover { background: #fdf2f2; color: #d35400; }
        .user-menu:hover .dropdown-content { display: block; }
        .container { max-width: 1300px; margin: 30px auto; padding: 0 15px; }

        .page-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px; flex-wrap: wrap; gap: 15px; }
        .page-title { margin: 0; color: #2c3e50; font-size: 24px; font-weight: 800; display: flex; align-items: center; gap: 10px; }
        .page-title i { color: #d35400; }
        .alert { padding: 15px; border-radius: 8px; margin-bottom: 20px; text-align: center; font-weight: 600; }
        .alert-erro { background: #fdeded; color: #e74c3c; border: 1px solid #f9cccc; }
        .alert-sucesso { background: #fdf2e9; color: #d35400; border: 1px solid #fae5d3; }
        /* TABS */
        .tabs { display: flex; gap: 10px; margin-bottom: 25px; border-bottom: 2px solid #e1e5ea; padding-bottom: 10px; overflow-x: auto; }
        .tab-btn { background: transparent; color: #7f8c8d; border: none; padding: 10px 20px; font-weight: 700; font-size: 14px; cursor: pointer; transition: 0.2s; border-radius: 8px; white-space: nowrap;}
        .tab-btn:hover { color: #d35400; background: #fdf2e9; }
        .tab-btn.active { background: #d35400; color: #fff; box-shadow: 0 4px 10px rgba(211, 84, 0, 0.2); }
        .tab-content { display: none; animation: fadeIn 0.3s; }
        .tab-content.active { display: block; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
        /* CARDS & FORMS */
        .card { background: #fff; padding: 25px; border-radius: 12px; border: 1px solid #e1e5ea; box-shadow: 0 4px 15px rgba(0,0,0,0.02); margin-bottom: 20px; }
        .card-header { margin: 0 0 20px 0; padding-bottom: 15px; border-bottom: 1px solid #f1f2f6; font-size: 16px; font-weight: 700; color: #2c3e50; display: flex; align-items: center; gap: 8px; }
        .card-header i { color: #d35400; }
        .form-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; }
        .form-group { display: flex; flex-direction: column; }
        .form-group.full { grid-column: 1 / -1; }
        .form-group label { font-weight: 700; font-size: 11px; color: #576574; text-transform: uppercase; margin-bottom: 8px; }
        .form-group input, .form-group select, .form-group textarea { padding: 12px 15px; border: 1px solid #dcdde1; border-radius: 8px; font-size: 14px; outline: none; transition: border-color 0.2s; font-family: inherit; background: #fff; }
        .form-group input:focus, .form-group select:focus, .form-group textarea:focus { border-color: #d35400; box-shadow: 0 0 0 3px rgba(211, 84, 0, 0.1); }
        .form-group input:disabled { background: #f8f9fa; color: #95a5a6; cursor: not-allowed; }
        .btn-primary { background: #d35400; color: #fff; border: none; padding: 14px 25px; border-radius: 8px; font-weight: 700; font-size: 14px; cursor: pointer; transition: 0.2s; display: inline-flex; align-items: center; justify-content: center; gap: 8px; }
        .btn-primary:hover { background: #ba4a00; }
        /* DASHBOARD FILTROS */
        .filter-bar { background: #fff; padding: 20px; border-radius: 12px; border: 1px solid #e1e5ea; display: flex; gap: 15px; align-items: flex-end; margin-bottom: 20px; flex-wrap: wrap; }
        .btn-filter { background: #2c3e50; color: #fff; border: none; padding: 12px 20px; border-radius: 8px; font-weight: 600; cursor: pointer; transition: 0.2s; }
        .btn-filter:hover { background: #1a252f; }
        .btn-print-doc { background: #fff; color: #d35400; border: 1px solid #dcdde1; padding: 11px 20px; border-radius: 8px; font-weight: 600; cursor: pointer; transition: 0.2s; display:inline-flex; align-items:center; gap:8px; text-decoration:none;}
        .btn-print-doc:hover { background: #fdf2e9; border-color: #d35400; }
        .dash-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin-bottom: 20px; }
        .dash-card { background: #fdfaf2; padding: 20px; border-radius: 12px; border: 1px solid #fae5d3; text-align: center; }
        .dash-label { font-size: 11px; font-weight: 700; color: #d35400; text-transform: uppercase; display: block; margin-bottom: 5px; }
        .dash-value { font-size: 28px; font-weight: 900; color: #ba4a00; }
        /* TABELA */
        .table-responsive { width: 100%; overflow-x: auto; border-radius: 8px; border: 1px solid #e1e5ea; }
        table { width: 100%; border-collapse: collapse; min-width: 900px; background: #fff; }
        th { padding: 15px; background: #f8f9fa; color: #576574; font-size: 11px; text-transform: uppercase; text-align: left; border-bottom: 2px solid #e1e5ea; }
        td { padding: 15px; border-bottom: 1px solid #f1f2f6; font-size: 14px; color: #2c3e50; vertical-align: middle; }
        tr:hover { background: #fdfcfb; }

        .text-center { text-align: center !important; }
        .text-right { text-align: right !important; }

        .badge-mat { background: #2c3e50; color: #fff; padding: 4px 10px; border-radius: 6px; font-weight: 700; font-size: 12px; letter-spacing: 1px;}
        .badge-km { background: #fdf2e9; color: #d35400; font-weight: 800; font-size: 15px; border: 1px solid #fae5d3; padding: 4px 10px; border-radius: 6px; display: inline-block;}
        .badge-count { background: #eef2f7; color: #576574; font-weight: 700; font-size: 11px; padding: 3px 8px; border-radius: 20px; margin-left: 8px; }

        .btn-del { color: #e74c3c; background: #fdeded; border: none; padding: 8px; border-radius: 6px; cursor: pointer; transition: 0.2s; display: inline-flex; align-items: center; justify-content: center; width: 32px; height: 32px;}
        .btn-del:hover { background: #fadbd8; color: #c0392b; }
        .btn-editar-mini { color: #d35400; background: #fdf2e9; border: none; padding: 8px; border-radius: 6px; cursor: pointer; transition: 0.2s; display: inline-flex; align-items: center; justify-content: center; width: 32px; height: 32px; margin-right: 6px;}
        .btn-editar-mini:hover { background: #fae5d3; color: #ba4a00; }

        .combustivel-linha-header { cursor: pointer; }
        .combustivel-linha-header.sem-expandir { cursor: default; }
        .toggle-icon { color: #d35400; transition: transform 0.2s; width: 14px; display: inline-block; }
        .combustivel-linha-detalhe table { min-width: 0; }
        .combustivel-linha-detalhe th { background: #f1f2f6; font-size: 10px; }

        /* MODAIS */
        .modal { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.6); z-index: 1001; justify-content: center; align-items: center; backdrop-filter: blur(2px); }
        .modal.show { display: flex; }
        .modal-content { background: #fff; padding: 30px; border-radius: 12px; width: 90%; max-width: 400px; text-align: center; box-shadow: 0 20px 50px rgba(0,0,0,0.2); }
        .modal-header { border-bottom: none; margin-bottom: 10px; }
        .modal-header h3 { margin: 0; color: #e74c3c; font-size: 18px; display: flex; align-items: center; justify-content: center; gap: 8px; }
        .modal-content input[type="password"] { width: 100%; padding: 12px; border: 1px solid #dcdde1; border-radius: 8px; margin: 15px 0; outline: none; }
        .modal-content input[type="password"]:focus { border-color: #e74c3c; }
        @media (max-width: 768px) {
            .topbar-center { display: none; }
            .topbar { padding: 10px 15px; }
            .filter-bar { flex-direction: column; align-items: stretch; }
        }
    </style>
</head>
<body>
    <header class="topbar">
        <div class="topbar-left"><a href="index.php"><img src="mab.png" alt="MAB" class="logo"></a></div>
        <div class="topbar-center">
            <a href="index.php" class="nav-icon" title="Início"><i class="fa-solid fa-house"></i></a>
            <?php if ($is_admin || in_array('clientes', $meus_acessos)): ?><a href="clientes.php" class="nav-icon i-clientes" title="Clientes"><i class="fa-solid fa-users"></i></a><?php endif; ?>
            <?php if ($is_admin || in_array('visitas', $meus_acessos)): ?><a href="visitas.php" class="nav-icon i-visitas" title="Visitas"><i class="fa-solid fa-calendar-check"></i></a><?php endif; ?>
            <?php if ($is_admin || in_array('servicos', $meus_acessos)): ?><a href="servicos.php" class="nav-icon i-servicos" title="Serviços"><i class="fa-solid fa-screwdriver-wrench"></i></a><?php endif; ?>
            <?php if ($is_admin || in_array('obras', $meus_acessos)): ?><a href="obras.php" class="nav-icon i-obras" title="Obras"><i class="fa-solid fa-helmet-safety"></i></a><?php endif; ?>
            <?php if ($is_admin || in_array('relatorios', $meus_acessos)): ?><a href="relatorios.php" class="nav-icon i-relatorios" title="Relatórios de Obra"><i class="fa-solid fa-clipboard-list"></i></a><?php endif; ?>
            <?php if ($is_admin || in_array('orcamentos', $meus_acessos)): ?><a href="orcamentos.php" class="nav-icon i-orcamentos" title="Orçamentos"><i class="fa-solid fa-file-invoice"></i></a><?php endif; ?>
            <?php if ($is_admin || in_array('termos', $meus_acessos)): ?><a href="termos.php" class="nav-icon i-termos" title="Termos"><i class="fa-solid fa-file-signature"></i></a><?php endif; ?>
            <?php if ($is_admin || in_array('equipa', $meus_acessos)): ?><a href="utilizadores.php" class="nav-icon i-equipa" title="Equipa"><i class="fa-solid fa-id-card-clip"></i></a><?php endif; ?>
            <a href="viaturas.php" class="nav-icon i-viaturas active" title="Viaturas e Frota"><i class="fa-solid fa-truck-pickup"></i></a>
            <?php if ($is_admin || in_array('configuracoes', $meus_acessos)): ?><a href="configuracoes.php" class="nav-icon i-config" title="Configurações"><i class="fa-solid fa-gear"></i></a><?php endif; ?>
        </div>
        <div class="topbar-right">
            <a href="index.php" class="btn-back" title="Voltar"><i class="fa-solid fa-arrow-left"></i></a>
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
        <?php if ($mensagem): ?><div class="alert alert-<?= htmlspecialchars($tipo_mensagem) ?>"><i class="fa-solid fa-circle-info"></i> <?= htmlspecialchars($mensagem) ?></div><?php endif; ?>
        <div class="page-header">
            <h1 class="page-title"><i class="fa-solid fa-truck-pickup"></i> Viaturas e Frota</h1>
        </div>
        <div class="tabs">
            <button class="tab-btn<?= $aba_inicial === 'registo' ? ' active' : '' ?>" onclick="openTab(event, 'registo')"><i class="fa-solid fa-pen-to-square"></i> Registo Diário</button>
            <button class="tab-btn<?= $aba_inicial === 'combustivel' ? ' active' : '' ?>" onclick="openTab(event, 'combustivel')"><i class="fa-solid fa-gas-pump"></i> Combustível / Energia</button>
            <button class="tab-btn<?= $aba_inicial === 'controlo' ? ' active' : '' ?>" onclick="openTab(event, 'controlo')"><i class="fa-solid fa-chart-line"></i> <?= $is_admin ? 'Controlo Mensal (Gestão)' : 'O Meu Histórico' ?></button>
        </div>
        <div id="registo" class="tab-content<?= $aba_inicial === 'registo' ? ' active' : '' ?>">
            <div class="card">
                <div class="card-header"><i class="fa-solid fa-route"></i> Registar Utilização da Viatura</div>

                <form method="POST">
                    <input type="hidden" name="aba_ativa" value="registo">
                    <div class="form-grid">
                        <div class="form-group">
                            <label>Colaborador</label>
                            <input type="text" value="<?= htmlspecialchars($_SESSION['nome']) ?>" disabled>
                        </div>

                        <div class="form-group">
                            <label>Viatura (Matrícula) *</label>
                            <select name="matricula" required>
                                <option value="">-- Selecione a Viatura --</option>
                                <?php foreach($frota_ativas as $v): ?>
                                    <option value="<?= htmlspecialchars($v['matricula']) ?>"><?= htmlspecialchars($v['matricula']) ?> <?= !empty($v['descricao']) ? ' - ' . htmlspecialchars($v['descricao']) : '' ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Data *</label>
                            <input type="date" name="data_registo" value="<?= date('Y-m-d') ?>" required>
                        </div>
                        <div class="form-group">
                            <div style="display:flex; gap:15px;">
                                <div style="flex:1;">
                                    <label style="display:block; margin-bottom:8px; font-weight:700; font-size:11px; color:#576574; text-transform:uppercase;">Hora Início</label>
                                    <input type="time" name="hora_inicio" style="width:100%;">
                                </div>
                                <div style="flex:1;">
                                    <label style="display:block; margin-bottom:8px; font-weight:700; font-size:11px; color:#576574; text-transform:uppercase;">Hora Fim</label>
                                    <input type="time" name="hora_fim" style="width:100%;">
                                </div>
                            </div>
                        </div>
                        <div class="form-group">
                            <label>KM Iniciais (Partida) *</label>
                            <input type="number" name="km_inicio" required placeholder="Ex: 154300">
                        </div>
                        <div class="form-group">
                            <label>KM Finais (Chegada) *</label>
                            <input type="number" name="km_fim" required placeholder="Ex: 154380">
                        </div>
                        <div class="form-group full">
                            <label>Descrição do Percurso / Destino</label>
                            <textarea name="descricao_viagem" rows="2" placeholder="Ex: Sede -> Obra Expo -> Sede..."></textarea>
                        </div>
                    </div>
                    <div style="text-align: right; margin-top: 15px; border-top: 1px solid #f1f2f6; padding-top: 20px;">
                        <button type="submit" name="btn_gravar_registo" class="btn-primary"><i class="fa-solid fa-floppy-disk"></i> Guardar Registo</button>
                    </div>
                </form>
            </div>
        </div>
        <div id="combustivel" class="tab-content<?= $aba_inicial === 'combustivel' ? ' active' : '' ?>">
            <div class="card">
                <div class="card-header"><i class="fa-solid fa-gas-pump"></i> Registar Abastecimento / Carregamento</div>
                <form method="POST">
                    <input type="hidden" name="aba_ativa" value="combustivel">
                    <div class="form-grid">
                        <div class="form-group">
                            <label>Colaborador</label>
                            <?php if ($is_admin): ?>
                                <select name="id_utilizador_combustivel" required>
                                    <?php foreach($lista_utilizadores as $u): ?>
                                        <option value="<?= (int)$u['id'] ?>" <?= ((int)$u['id'] === (int)$id_utilizador_logado) ? 'selected' : '' ?>><?= htmlspecialchars($u['nome']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            <?php else: ?>
                                <input type="text" value="<?= htmlspecialchars($_SESSION['nome']) ?>" disabled>
                                <!-- Campo meramente informativo: o servidor NUNCA confia neste valor para
                                     utilizadores normais, é sempre substituído pelo utilizador da sessão -->
                                <input type="hidden" name="id_utilizador_combustivel" value="<?= (int)$id_utilizador_logado ?>">
                            <?php endif; ?>
                        </div>
                        <div class="form-group">
                            <label>Viatura (Matrícula) *</label>
                            <select name="matricula_combustivel" required>
                                <option value="">-- Selecione a Viatura --</option>
                                <?php foreach($frota_ativas as $v): ?>
                                    <option value="<?= htmlspecialchars($v['matricula']) ?>"><?= htmlspecialchars($v['matricula']) ?> <?= !empty($v['descricao']) ? ' - ' . htmlspecialchars($v['descricao']) : '' ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Data *</label>
                            <input type="date" name="data_combustivel" value="<?= date('Y-m-d') ?>" required>
                        </div>
                        <div class="form-group">
                            <label>KM no Ato do Abastecimento *</label>
                            <input type="number" name="km_atual_combustivel" required placeholder="Ex: 154380">
                        </div>
                        <div class="form-group">
                            <label>Unidade *</label>
                            <select name="unidade_combustivel" id="unidade_combustivel" onchange="atualizarRotulosUnidade()">
                                <option value="L">Litros (combustível)</option>
                                <option value="kWh">kWh (elétrico)</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label id="rotulo_quantidade">Litros Abastecidos *</label>
                            <input type="number" step="0.01" name="litros_combustivel" required placeholder="Ex: 45.50">
                        </div>
                        <div class="form-group">
                            <label id="rotulo_atestou">Atestou o Depósito?</label>
                            <select name="atestou_combustivel">
                                <option value="Sim">Sim</option>
                                <option value="Não">Não</option>
                            </select>
                        </div>
                    </div>
                    <div style="text-align: right; margin-top: 15px; border-top: 1px solid #f1f2f6; padding-top: 20px;">
                        <button type="submit" name="btn_gravar_combustivel" class="btn-primary"><i class="fa-solid fa-gas-pump"></i> Guardar Abastecimento</button>
                    </div>
                </form>
            </div>

            <div class="card">
                <div class="card-header"><i class="fa-solid fa-chart-pie"></i> Resumo por Viatura</div>
                <div class="table-responsive">
                    <table style="min-width:0;">
                        <thead>
                            <tr>
                                <th>Matrícula</th>
                                <th class="text-center">Nº Abastecimentos</th>
                                <th>1º Registo</th>
                                <th>Último Registo</th>
                                <th class="text-right">KM Percorridos</th>
                                <th class="text-right">Total Abastecido/Carregado</th>
                                <?php if ($is_admin): ?><th class="text-right">Última Média de Consumo</th><?php endif; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($resumo_por_matricula)): ?>
                                <tr><td colspan="<?= $is_admin ? 7 : 6 ?>" class="text-center" style="padding: 40px; color: #7f8c8d;">Sem dados suficientes.</td></tr>
                            <?php else: ?>
                                <?php foreach ($resumo_por_matricula as $mat_r => $res): ?>
                                <tr>
                                    <td><span class="badge-mat"><?= htmlspecialchars($mat_r) ?></span></td>
                                    <td class="text-center"><?= $res['num_registos'] ?></td>
                                    <td><?= date('d/m/Y', strtotime($res['data_primeiro'])) ?></td>
                                    <td><?= date('d/m/Y', strtotime($res['data_ultimo'])) ?></td>
                                    <td class="text-right"><?= number_format($res['total_km'], 0, '', ' ') ?> km</td>
                                    <td class="text-right"><?= number_format($res['total_litros'], 2, ',', ' ') ?> <?= htmlspecialchars($res['unidade']) ?></td>
                                    <?php if ($is_admin): ?>
                                    <td class="text-right">
                                        <?php if ($res['ultima_media_consumo'] !== null): ?>
                                            <span class="badge-km" title="Calculada entre os dois abastecimentos/carregamentos mais recentes, ambos completos e na mesma unidade"><?= number_format($res['ultima_media_consumo'], 1, ',', ' ') ?> <?= htmlspecialchars($res['unidade_ultima_media']) ?>/100km</span>
                                        <?php else: ?>
                                            <span style="color:#bdc3c7;" title="Ainda não há dois abastecimentos/carregamentos consecutivos, completos e na mesma unidade, para calcular">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <?php endif; ?>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="card">
                <div class="card-header"><i class="fa-solid fa-list"></i> Abastecimentos Registados</div>
                <div class="table-responsive">
                    <table style="min-width:0;">
                        <thead>
                            <tr>
                                <th style="width:20px;"></th>
                                <th style="width:120px;">Matrícula</th>
                                <th style="width:100px;">Data (Último)</th>
                                <th class="text-right">Qtd.</th>
                                <th class="text-right">KM</th>
                                <th class="text-center">Completo</th>
                                <th>Colaborador</th>
                                <?php if ($is_admin): ?><th class="text-center" style="width:90px;">Ações</th><?php endif; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($combustivel_agrupado)): ?>
                                <tr><td colspan="<?= $is_admin ? 8 : 7 ?>" class="text-center" style="padding: 40px; color: #7f8c8d;">Ainda não há abastecimentos registados.</td></tr>
                            <?php else: ?>
                                <?php foreach ($combustivel_agrupado as $matricula_g => $lista_g):
                                    $ultimo_g = $lista_g[0]; // já vem em ordem DESC (mais recente primeiro)
                                    $tem_mais = count($lista_g) > 1;
                                    $unidade_ultimo_g = $ultimo_g['unidade'] ?? 'L';
                                    $rotulo_completo_g = $unidade_ultimo_g === 'kWh' ? 'Carregou a bateria a 100%' : 'Atestou o depósito';
                                ?>
                                <tr class="combustivel-linha-header<?= $tem_mais ? '' : ' sem-expandir' ?>" <?= $tem_mais ? 'onclick="toggleCombustivelLinha(this)"' : '' ?>>
                                    <td class="text-center"><?php if ($tem_mais): ?><i class="fa-solid fa-chevron-right toggle-icon"></i><?php endif; ?></td>
                                    <td><span class="badge-mat"><?= htmlspecialchars($matricula_g) ?></span><?php if ($tem_mais): ?><span class="badge-count"><?= count($lista_g) ?> registos</span><?php endif; ?></td>
                                    <td><strong><?= date('d/m/Y', strtotime($ultimo_g['data_registo'])) ?></strong></td>
                                    <td class="text-right" style="color:#7f8c8d;"><?= number_format($ultimo_g['litros'] ?? 0, 2, ',', ' ') ?> <?= htmlspecialchars($unidade_ultimo_g) ?></td>
                                    <td class="text-right"><span class="badge-km"><?= number_format($ultimo_g['km_atual'], 0, '', ' ') ?> km</span></td>
                                    <td class="text-center">
                                        <?php if (($ultimo_g['atestou'] ?? 'Sim') === 'Sim'): ?>
                                            <i class="fa-solid fa-circle-check" style="color:#27ae60;" title="<?= htmlspecialchars($rotulo_completo_g) ?>"></i>
                                        <?php else: ?>
                                            <i class="fa-solid fa-circle-minus" style="color:#bdc3c7;" title="Não: <?= htmlspecialchars(lcfirst($rotulo_completo_g)) ?>"></i>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= htmlspecialchars($ultimo_g['nome_colaborador'] ?? 'N/D') ?></td>
                                    <?php if ($is_admin): ?>
                                    <td class="text-center" onclick="event.stopPropagation()">
                                        <button type="button" class="btn-editar-mini" title="Editar" onclick="abrirModalEditarCombustivel(<?= (int)$ultimo_g['id'] ?>, '<?= addslashes($ultimo_g['matricula']) ?>', <?= (int)$ultimo_g['id_utilizador'] ?>, '<?= $ultimo_g['data_registo'] ?>', <?= (float)$ultimo_g['km_atual'] ?>, <?= (float)($ultimo_g['litros'] ?? 0) ?>, '<?= htmlspecialchars($unidade_ultimo_g) ?>', '<?= ($ultimo_g['atestou'] ?? 'Sim') === 'Não' ? 'Não' : 'Sim' ?>')"><i class="fa-solid fa-pen-to-square"></i></button>
                                        <button type="button" class="btn-del" title="Apagar" onclick="abrirModalApagar(<?= (int)$ultimo_g['id'] ?>, 'combustivel')"><i class="fa-solid fa-trash-can"></i></button>
                                    </td>
                                    <?php endif; ?>
                                </tr>
                                <?php if ($tem_mais): ?>
                                <tr class="combustivel-linha-detalhe" style="display:none;">
                                    <td colspan="<?= $is_admin ? 8 : 7 ?>" style="padding:0; background:#fafbfc;">
                                        <table style="min-width:0; margin:0;">
                                            <thead>
                                                <tr>
                                                    <th style="width:100px;">Data</th>
                                                    <th class="text-right">Qtd.</th>
                                                    <th class="text-right">KM</th>
                                                    <th class="text-center">Completo</th>
                                                    <th>Colaborador</th>
                                                    <?php if ($is_admin): ?><th class="text-center" style="width:90px;">Ações</th><?php endif; ?>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($lista_g as $rc_d):
                                                    $unidade_d = $rc_d['unidade'] ?? 'L';
                                                    $rotulo_completo_d = $unidade_d === 'kWh' ? 'Carregou a bateria a 100%' : 'Atestou o depósito';
                                                ?>
                                                <tr>
                                                    <td><?= date('d/m/Y', strtotime($rc_d['data_registo'])) ?></td>
                                                    <td class="text-right" style="color:#7f8c8d;"><?= number_format($rc_d['litros'] ?? 0, 2, ',', ' ') ?> <?= htmlspecialchars($unidade_d) ?></td>
                                                    <td class="text-right"><?= number_format($rc_d['km_atual'], 0, '', ' ') ?> km</td>
                                                    <td class="text-center">
                                                        <?php if (($rc_d['atestou'] ?? 'Sim') === 'Sim'): ?>
                                                            <i class="fa-solid fa-circle-check" style="color:#27ae60;" title="<?= htmlspecialchars($rotulo_completo_d) ?>"></i>
                                                        <?php else: ?>
                                                            <i class="fa-solid fa-circle-minus" style="color:#bdc3c7;" title="Não: <?= htmlspecialchars(lcfirst($rotulo_completo_d)) ?>"></i>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td><?= htmlspecialchars($rc_d['nome_colaborador'] ?? 'N/D') ?></td>
                                                    <?php if ($is_admin): ?>
                                                    <td class="text-center">
                                                        <button type="button" class="btn-editar-mini" title="Editar" onclick="abrirModalEditarCombustivel(<?= (int)$rc_d['id'] ?>, '<?= addslashes($rc_d['matricula']) ?>', <?= (int)$rc_d['id_utilizador'] ?>, '<?= $rc_d['data_registo'] ?>', <?= (float)$rc_d['km_atual'] ?>, <?= (float)($rc_d['litros'] ?? 0) ?>, '<?= htmlspecialchars($unidade_d) ?>', '<?= ($rc_d['atestou'] ?? 'Sim') === 'Não' ? 'Não' : 'Sim' ?>')"><i class="fa-solid fa-pen-to-square"></i></button>
                                                        <button type="button" class="btn-del" title="Apagar" onclick="abrirModalApagar(<?= (int)$rc_d['id'] ?>, 'combustivel')"><i class="fa-solid fa-trash-can"></i></button>
                                                    </td>
                                                    <?php endif; ?>
                                                </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </td>
                                </tr>
                                <?php endif; ?>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <div id="controlo" class="tab-content<?= $aba_inicial === 'controlo' ? ' active' : '' ?>">
            <form method="GET" class="filter-bar">
                <div class="form-group" style="margin: 0; min-width: 150px;">
                    <label>Mês</label>
                    <select name="mes">
                        <option value="todos" <?= $filtro_mes === 'todos' ? 'selected' : '' ?>>O Ano Todo</option>
                        <?php
                        $meses = [1 => 'Janeiro', 2 => 'Fevereiro', 3 => 'Março', 4 => 'Abril', 5 => 'Maio', 6 => 'Junho', 7 => 'Julho', 8 => 'Agosto', 9 => 'Setembro', 10 => 'Outubro', 11 => 'Novembro', 12 => 'Dezembro'];
                        foreach ($meses as $num => $nome) {
                            $sel = ($filtro_mes == $num) ? 'selected' : '';
                            echo "<option value=\"$num\" $sel>$nome</option>";
                        }
                        ?>
                    </select>
                </div>

                <div class="form-group" style="margin: 0; min-width: 120px;">
                    <label>Ano</label>
                    <select name="ano">
                        <?php
                        $ano_atual = date('Y');
                        for ($a = $ano_atual; $a >= $ano_atual - 5; $a--) {
                            $sel = ($filtro_ano == $a) ? 'selected' : '';
                            echo "<option value=\"$a\" $sel>$a</option>";
                        }
                        ?>
                    </select>
                </div>
                <div class="form-group" style="margin: 0; flex: 1;">
                    <label>Matrícula</label>
                    <select name="matricula">
                        <option value="todas">Todas as Viaturas</option>
                        <?php foreach($frota as $v): ?>
                            <option value="<?= htmlspecialchars($v['matricula']) ?>" <?= $filtro_matricula === $v['matricula'] ? 'selected' : '' ?>><?= htmlspecialchars($v['matricula']) ?> <?= !empty($v['descricao']) ? ' - ' . htmlspecialchars($v['descricao']) : '' ?><?= (isset($v['ativa']) && !$v['ativa']) ? ' (inativa)' : '' ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div style="display:flex; gap:10px;">
                    <button type="submit" class="btn-filter"><i class="fa-solid fa-filter"></i> Filtrar</button>
                    <!-- BOTÃO IMPRIMIR -->
                    <a href="viatura_pdf.php?mes=<?= $filtro_mes ?>&ano=<?= $filtro_ano ?>&matricula=<?= urlencode($filtro_matricula) ?>" target="_blank" class="btn-print-doc" title="Imprimir o que está na tabela">
                        <i class="fa-solid fa-print"></i> Imprimir
                    </a>
                </div>
            </form>
            <div class="dash-grid">
                <div class="dash-card">
                    <span class="dash-label">Total Percorrido</span>
                    <span class="dash-value"><?= number_format($total_km, 0, ',', ' ') ?> KM</span>
                </div>
                <div class="dash-card">
                    <span class="dash-label">Viagens Registadas</span>
                    <span class="dash-value"><?= $total_viagens ?></span>
                </div>
            </div>
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th style="width:100px;">Data</th>
                            <th style="width:120px;">Matrícula</th>
                            <th>Colaborador</th>
                            <th>Horário</th>
                            <th class="text-right">Partida</th>
                            <th class="text-right">Chegada</th>
                            <th class="text-center">Total V.</th>
                            <?php if ($is_admin): ?><th class="text-center" style="width:60px;">Del</th><?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($registos)): ?>
                            <tr><td colspan="<?= $is_admin ? 8 : 7 ?>" class="text-center" style="padding: 40px; color: #7f8c8d;">Nenhum registo encontrado para este filtro.</td></tr>
                        <?php else: ?>
                            <?php foreach ($registos as $r):
                                $distancia = ($r['km_fim'] > 0 && $r['km_fim'] >= $r['km_inicio']) ? ($r['km_fim'] - $r['km_inicio']) : 0;
                            ?>
                            <tr>
                                <td><strong><?= date('d/m/Y', strtotime($r['data_registo'])) ?></strong></td>
                                <td><span class="badge-mat"><?= htmlspecialchars($r['matricula']) ?></span></td>
                                <td>
                                    <div style="font-weight:600; color:#2c3e50;"><?= htmlspecialchars($r['nome_colaborador'] ?? 'N/D') ?></div>
                                    <div style="font-size:11px; color:#7f8c8d;"><?= htmlspecialchars($r['descricao_viagem']) ?></div>
                                </td>
                                <td>
                                    <?php if ($r['hora_inicio'] || $r['hora_fim']): ?>
                                        <i class="fa-regular fa-clock" style="color:#95a5a6;"></i>
                                        <?= $r['hora_inicio'] ? date('H:i', strtotime($r['hora_inicio'])) : '--:--' ?> às
                                        <?= $r['hora_fim'] ? date('H:i', strtotime($r['hora_fim'])) : '--:--' ?>
                                    <?php else: ?>
                                        <span style="color:#bdc3c7;">-</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-right" style="color:#7f8c8d;"><?= number_format($r['km_inicio'], 0, '', ' ') ?></td>
                                <td class="text-right" style="color:#2c3e50; font-weight:600;"><?= number_format($r['km_fim'], 0, '', ' ') ?></td>
                                <td class="text-center"><span class="badge-km">+<?= number_format($distancia, 0, '', ' ') ?></span></td>

                                <?php if ($is_admin): ?>
                                <td class="text-center">
                                    <button type="button" class="btn-del" onclick="abrirModalApagar(<?= $r['id'] ?>, 'diario')"><i class="fa-solid fa-trash-can"></i></button>
                                </td>
                                <?php endif; ?>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php if ($is_admin): ?>
    <div class="modal" id="modalApagar">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fa-solid fa-triangle-exclamation"></i> Apagar Registo</h3>
            </div>
            <p>Deseja apagar definitivamente este registo de utilização da viatura?</p>
            <form method="POST">
                <input type="hidden" name="id_registo_apagar" id="id_reg_apagar_input">
                <input type="hidden" name="tipo_registo_apagar" id="tipo_reg_apagar_input" value="diario">
                <input type="hidden" name="aba_ativa" id="aba_ativa_apagar_input" value="controlo">
                <input type="password" name="pass_seguranca" required placeholder="Password de Segurança">
                <div style="display:flex; gap:10px; margin-top:20px;">
                    <button type="button" onclick="fecharModais()" style="flex:1; background:#f1f2f6; border:none; padding:12px; border-radius:8px; cursor:pointer; font-weight:600; color:#576574;">Cancelar</button>
                    <button type="submit" name="btn_confirmar_apagar" style="flex:1; background:#e74c3c; color:#fff; border:none; border-radius:8px; font-weight:600; cursor:pointer;">Apagar</button>
                </div>
            </form>
        </div>
    </div>

    <div class="modal" id="modalEditarCombustivel">
        <div class="modal-content" style="max-width:520px; text-align:left;">
            <div class="modal-header" style="text-align:center;">
                <h3 style="color:#d35400; justify-content:center;"><i class="fa-solid fa-pen-to-square"></i> Editar Abastecimento</h3>
            </div>
            <form method="POST">
                <input type="hidden" name="id_combustivel_editar" id="edit_comb_id">
                <input type="hidden" name="aba_ativa" value="combustivel">
                <div class="form-grid">
                    <div class="form-group">
                        <label>Viatura</label>
                        <select name="matricula_editar" id="edit_comb_matricula" required>
                            <?php foreach($frota as $v): ?>
                                <option value="<?= htmlspecialchars($v['matricula']) ?>"><?= htmlspecialchars($v['matricula']) ?><?= (isset($v['ativa']) && !$v['ativa']) ? ' (inativa)' : '' ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Colaborador</label>
                        <select name="id_utilizador_editar" id="edit_comb_utilizador" required>
                            <?php foreach($lista_utilizadores as $u): ?>
                                <option value="<?= (int)$u['id'] ?>"><?= htmlspecialchars($u['nome']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Data</label>
                        <input type="date" name="data_editar" id="edit_comb_data" required>
                    </div>
                    <div class="form-group">
                        <label>KM no Abastecimento</label>
                        <input type="number" name="km_editar" id="edit_comb_km" required>
                    </div>
                    <div class="form-group">
                        <label>Unidade</label>
                        <select name="unidade_editar" id="edit_comb_unidade" onchange="atualizarRotulosUnidadeEditar()">
                            <option value="L">Litros (combustível)</option>
                            <option value="kWh">kWh (elétrico)</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label id="edit_comb_rotulo_qtd">Litros</label>
                        <input type="number" step="0.01" name="litros_editar" id="edit_comb_litros" required>
                    </div>
                    <div class="form-group">
                        <label id="edit_comb_rotulo_atestou">Atestou o Depósito?</label>
                        <select name="atestou_editar" id="edit_comb_atestou">
                            <option value="Sim">Sim</option>
                            <option value="Não">Não</option>
                        </select>
                    </div>
                </div>
                <div style="display:flex; gap:10px; margin-top:20px;">
                    <button type="button" onclick="fecharModais()" style="flex:1; background:#f1f2f6; border:none; padding:12px; border-radius:8px; cursor:pointer; font-weight:600; color:#576574;">Cancelar</button>
                    <button type="submit" name="btn_editar_combustivel" style="flex:1; background:#d35400; color:#fff; border:none; border-radius:8px; font-weight:600; cursor:pointer;">Guardar Alterações</button>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>
    <script>
        function openTab(evt, tabName) {
            var i, tabcontent, tablinks;
            tabcontent = document.getElementsByClassName("tab-content");
            for (i = 0; i < tabcontent.length; i++) { tabcontent[i].style.display = "none"; tabcontent[i].classList.remove("active"); }
            tablinks = document.getElementsByClassName("tab-btn");
            for (i = 0; i < tablinks.length; i++) { tablinks[i].classList.remove("active"); }
            document.getElementById(tabName).style.display = "block";
            document.getElementById(tabName).classList.add("active");
            evt.currentTarget.classList.add("active");
        }
        // Nota: qual o separador que fica ativo ao carregar a página (incluindo depois de
        // gravar/editar/apagar, ou de usar o filtro do Controlo Mensal) já é decidido no
        // servidor (ver $aba_inicial em PHP), por isso não é preciso nenhuma lógica aqui.
        function abrirModalApagar(id, tipo) {
            document.getElementById('id_reg_apagar_input').value = id;
            document.getElementById('tipo_reg_apagar_input').value = tipo || 'diario';
            document.getElementById('aba_ativa_apagar_input').value = (tipo === 'combustivel') ? 'combustivel' : 'controlo';
            document.getElementById('modalApagar').classList.add('show');
            document.querySelector('#modalApagar input[name="pass_seguranca"]').value = '';
            document.querySelector('#modalApagar input[name="pass_seguranca"]').focus();
        }
        function abrirModalEditarCombustivel(id, matricula, idUtilizador, data, km, litros, unidade, atestou) {
            document.getElementById('edit_comb_id').value = id;
            document.getElementById('edit_comb_matricula').value = matricula;
            document.getElementById('edit_comb_utilizador').value = idUtilizador;
            document.getElementById('edit_comb_data').value = data;
            document.getElementById('edit_comb_km').value = km;
            document.getElementById('edit_comb_litros').value = litros;
            document.getElementById('edit_comb_unidade').value = unidade || 'L';
            document.getElementById('edit_comb_atestou').value = atestou;
            atualizarRotulosUnidadeEditar();
            document.getElementById('modalEditarCombustivel').classList.add('show');
        }
        function atualizarRotulosUnidade() {
            const eletrico = document.getElementById('unidade_combustivel').value === 'kWh';
            document.getElementById('rotulo_quantidade').textContent = eletrico ? 'Energia Carregada (kWh) *' : 'Litros Abastecidos *';
            document.getElementById('rotulo_atestou').textContent = eletrico ? 'Carregou a Bateria a 100%?' : 'Atestou o Depósito?';
        }
        function atualizarRotulosUnidadeEditar() {
            const eletrico = document.getElementById('edit_comb_unidade').value === 'kWh';
            document.getElementById('edit_comb_rotulo_qtd').textContent = eletrico ? 'Energia Carregada (kWh)' : 'Litros';
            document.getElementById('edit_comb_rotulo_atestou').textContent = eletrico ? 'Carregou a Bateria a 100%?' : 'Atestou o Depósito?';
        }
        function toggleCombustivelLinha(tr) {
            const detalhe = tr.nextElementSibling;
            if (!detalhe || !detalhe.classList.contains('combustivel-linha-detalhe')) return;
            const icon = tr.querySelector('.toggle-icon');
            const aberto = detalhe.style.display !== 'none';
            detalhe.style.display = aberto ? 'none' : 'table-row';
            if (icon) {
                icon.classList.toggle('fa-chevron-right', aberto);
                icon.classList.toggle('fa-chevron-down', !aberto);
            }
        }
        function fecharModais() {
            document.querySelectorAll('.modal').forEach(function(m) { m.classList.remove('show'); });
        }
        window.onclick = function(e) { if(e.target.classList.contains('modal')) { fecharModais(); } }
    </script>
</body>
</html>