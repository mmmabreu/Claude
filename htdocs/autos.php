<?php

// Total de linha (Qtd × P. Unit) arredondado ao cêntimo de forma EXATA (meio cêntimo arredonda para cima).
// O round() do PHP 8.4 sobre o produto em vírgula flutuante falha nos meios cêntimos
// (ex.: 5 × 0,411 = 2,055 → dava 2,05 em vez de 2,06). Aqui a conta é feita em inteiros.
if (!function_exists('totLinha')) {
    function totLinha($q, $p) {
        $qi = (int)round((float)$q * 1000);
        $pi = (int)round((float)$p * 1000);
        $prod = $qi * $pi;                          // em milionésimos
        if (!is_int($prod)) return round((float)$q * (float)$p, 2);   // valores enormes (não acontece na prática)
        $neg = $prod < 0;
        $c = intdiv(abs($prod) + 5000, 10000);       // cêntimos
        return ($neg ? -1 : 1) * $c / 100;
    }
}
// Total gravado é um "acerto manual"? (diferente do valor exato e do valor antigo com o arredondamento errado)
if (!function_exists('totalFoiAcertado')) {
    function totalFoiAcertado($t, $q, $p) {
        return abs((float)$t - totLinha($q, $p)) >= 0.005 && abs((float)$t - round((float)$q * (float)$p, 2)) >= 0.005;
    }
}

// Os erros não são mostrados no ecrã (ficam no registo de erros do servidor)
ini_set('display_errors', 0);

try {
    session_start();
    require_once 'db.php';
    require_once 'functions.php';

    // REGRA DE OURO 1: Validação de sessão
    if (!isset($_SESSION['user_id'])) {
        header("Location: index.php");
        exit;
    }

    // Proteção extra: Garantir que o perfil existe na sessão
    $perfil_utilizador = $_SESSION['perfil'] ?? '';

    // --- PERMISSÕES ---
    $stmt_perm = $pdo->prepare("SELECT modulo FROM permissoes WHERE grupo = :perfil AND pode_aceder = 1");
    $stmt_perm->execute(['perfil' => $perfil_utilizador]);
    $meus_acessos = $stmt_perm->fetchAll(PDO::FETCH_COLUMN);
    $is_admin = ($perfil_utilizador === 'admin' || $perfil_utilizador === 'administrativo');

    // REGRA DE OURO 2: Acesso restrito pela matriz de permissões (os autos fazem parte do módulo Obras)
    if (!$is_admin && !in_array('obras', $meus_acessos)) {
        header("Location: index.php?erro=sem_permissao");
        exit;
    }

    // REGRA DE OURO: Obter apenas o primeiro nome
    $nome_completo = $_SESSION['nome'] ?? 'Utilizador';
    $primeiro_nome = explode(' ', trim($nome_completo))[0];

    // --- BUSCAR PASSWORD DE SEGURANÇA ---
    $stmt_pass = $pdo->prepare("SELECT valor FROM configuracoes WHERE chave = 'password_apagar' LIMIT 1");
    $stmt_pass->execute();
    $pass_seguranca_sistema = $stmt_pass->fetchColumn();
    if (!$pass_seguranca_sistema) {
        $pass_seguranca_sistema = 'mab2026';
    }

    // --- EDITAR AUTO ANTIGO: a password é verificada AQUI, no servidor ---
    // (antes era comparada no browser e a password ficava escrita no código da página, à vista de todos)
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'editar_antigo') {
        $id_ed = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
        if (!validar_csrf_token($_POST['csrf_token'] ?? '')) {
            header("Location: autos.php?erro=sessao"); exit;
        }
        if (!$id_ed || !hash_equals((string)$pass_seguranca_sistema, (string)($_POST['pass_seguranca'] ?? ''))) {
            header("Location: autos.php?erro=password"); exit;
        }
        header("Location: auto_form.php?id=" . $id_ed); exit;
    }

    // Mensagens depois de apagar / da verificação da password
    $msg_topo = ''; $msg_tipo = 'erro';
    $erros_txt = [
        'password'      => 'Password de segurança incorreta. Nada foi alterado.',
        'sessao'        => 'A sessão expirou ou o pedido não é válido. Atualize a página e tente de novo.',
        'sem_permissao' => 'Só o administrador pode apagar autos.',
        'nao_ultimo'    => 'Só se pode apagar o último auto de cada obra.',
        'db'            => 'Não foi possível apagar o auto. Nada foi alterado.',
    ];
    if (isset($_GET['sucesso'])) { $msg_topo = 'Auto apagado. Fica guardada uma cópia no arquivo.'; $msg_tipo = 'sucesso'; }
    elseif (isset($_GET['erro'], $erros_txt[$_GET['erro']])) { $msg_topo = $erros_txt[$_GET['erro']]; }

    // --- EXPORTAR EXCEL (MAPA DA OBRA RESUMO) ---
    if (isset($_GET['exportar_excel'])) {
        $obra_id = (int)$_GET['exportar_excel'];

        $stmt_obra = $pdo->prepare("SELECT nome, referencia_obra FROM obras WHERE id = ?");
        $stmt_obra->execute([$obra_id]);
        $obra_data = $stmt_obra->fetch();

        if (!$obra_data) die("Obra não encontrada.");

        $nome_ficheiro = "Autos_Resumo_Obra_" . preg_replace('/[^a-zA-Z0-9]/', '_', $obra_data['nome']) . "_" . date('Ymd') . ".xls";

        header("Content-Type: application/vnd.ms-excel; charset=utf-8");
        header("Content-Disposition: attachment; filename=\"$nome_ficheiro\"");

        echo "<html xmlns:x=\"urn:schemas-microsoft-com:office:excel\">";
        echo "<head><meta charset=\"UTF-8\">";
        echo "<style>
                body, table, td, th { font-family: Arial, sans-serif; font-size: 6pt; }
                .num-2 { mso-number-format:\"\#\,\#\#0\.00\"; text-align: right; }
              </style>";
        echo "</head><body>";
        echo "<h2>Autos de Medição: " . htmlspecialchars($obra_data['nome']) . " (REF: " . htmlspecialchars($obra_data['referencia_obra']) . ")</h2>";

        $stmt_autos = $pdo->prepare("SELECT id, numero_auto, mes_referencia, valor_total_mes, valor_total_acumulado FROM autos WHERE id_obra = ? ORDER BY numero_auto ASC");
        $stmt_autos->execute([$obra_id]);
        $autos_export = $stmt_autos->fetchAll();

        echo "<table border='1'>";
        echo "<tr style='background-color:#b32a2a; color:#fff;'>";
        echo "<th>Nº Auto</th>";
        echo "<th>Mês Referência</th>";
        echo "<th>Total Auto (€)</th>";
        echo "<th>Total Acumulado (€)</th>";
        echo "</tr>";

        $linha = 3;
        foreach ($autos_export as $a) {
            $val_mes_raw = number_format((float)$a['valor_total_mes'], 2, '.', '');
            $val_mes_show = number_format((float)$a['valor_total_mes'], 2, ',', '');

            $val_acum_raw = number_format((float)$a['valor_total_acumulado'], 2, '.', '');
            $val_acum_show = number_format((float)$a['valor_total_acumulado'], 2, ',', '');

            echo "<tr>";
            echo "<td style='text-align:center;'>Auto #" . $a['numero_auto'] . "</td>";
            echo "<td style='text-align:center;'>" . $a['mes_referencia'] . "</td>";
            echo "<td class='num-2' x:num='{$val_mes_raw}'>{$val_mes_show}</td>";
            if ($linha == 3) {
                echo "<td class='num-2' x:num='{$val_acum_raw}' x:fmla='=C3'>{$val_acum_show}</td>";
            } else {
                echo "<td class='num-2' x:num='{$val_acum_raw}' x:fmla='=C" . $linha . "+D" . ($linha - 1) . "'>{$val_acum_show}</td>";
            }
            echo "</tr>";
            $linha++;
        }
        echo "</table></body></html>";
        exit;
    }

    // --- FILTROS PARA A TABELA ---
    $filtro_estado = $_GET['estado'] ?? 'abertas';
    $filtro_obra = $_GET['obra_id'] ?? null;

    $sql = "SELECT a.*, o.id as id_obra_real, o.nome as nome_obra, c.nome as nome_cliente, o.estado as estado_obra
            FROM obras o
            JOIN clientes c ON o.id_cliente = c.id
            LEFT JOIN autos a ON a.id_obra = o.id";

    $condicoes = [];
    if ($filtro_estado == 'abertas') {
        $condicoes[] = "o.estado != 'Concluída'";
    } elseif ($filtro_estado == 'concluidas') {
        $condicoes[] = "o.estado = 'Concluída'";
    }

    if ($filtro_obra) {
        $condicoes[] = "o.id = " . (int)$filtro_obra;
    }

    if (!empty($condicoes)) {
        $sql .= " WHERE " . implode(' AND ', $condicoes);
    }

    $sql .= " ORDER BY o.nome ASC, a.numero_auto DESC";

    $autos = $pdo->query($sql)->fetchAll();
    $autos_por_obra = [];

    // --- DATA DE CADA AUTO (para ordenar as obras pela data do último auto) ---
    // Usa a coluna de data da tabela 'autos' se existir; senão interpreta o "Mês Referência"
    // (ex.: 2026-09, 09/2026, Setembro 2026); em último caso usa a ordem de criação (id).
    $cols_autos = $pdo->query("SHOW COLUMNS FROM autos")->fetchAll(PDO::FETCH_COLUMN);
    $col_data_auto = null;
    foreach (['data_auto', 'data_emissao', 'data', 'data_registo', 'data_criacao', 'criado_em', 'created_at'] as $c) {
        if (in_array($c, $cols_autos)) { $col_data_auto = $c; break; }
    }

    function dataDoMesReferencia($mes) {
        $mes = trim(mb_strtolower((string)$mes));
        if ($mes === '') return 0;
        if (preg_match('/^(\d{4})[-\/.](\d{1,2})/', $mes, $m)) return mktime(0, 0, 0, (int)$m[2], 1, (int)$m[1]);        // 2026-09
        if (preg_match('/^(\d{1,2})[-\/.](\d{4})/', $mes, $m)) return mktime(0, 0, 0, (int)$m[1], 1, (int)$m[2]);        // 09/2026
        $meses = ['janeiro' => 1, 'fevereiro' => 2, 'março' => 3, 'marco' => 3, 'abril' => 4, 'maio' => 5, 'junho' => 6,
                  'julho' => 7, 'agosto' => 8, 'setembro' => 9, 'outubro' => 10, 'novembro' => 11, 'dezembro' => 12,
                  'jan' => 1, 'fev' => 2, 'mar' => 3, 'abr' => 4, 'mai' => 5, 'jun' => 6, 'jul' => 7, 'ago' => 8, 'set' => 9, 'out' => 10, 'nov' => 11, 'dez' => 12];
        if (preg_match('/([a-zç]+)\W*(\d{4})/u', $mes, $m) && isset($meses[$m[1]])) return mktime(0, 0, 0, $meses[$m[1]], 1, (int)$m[2]); // Setembro 2026
        $t = strtotime($mes);
        return $t ?: 0;
    }

    function chaveDataAuto($a, $col_data_auto) {
        $t = 0;
        if ($col_data_auto && !empty($a[$col_data_auto])) $t = (int)strtotime($a[$col_data_auto]);
        if (!$t) $t = dataDoMesReferencia($a['mes_referencia'] ?? '');
        return [$t, (int)($a['id'] ?? 0)]; // em caso de empate, o auto criado mais tarde
    }

    foreach ($autos as $a) {
        $nome_obra = $a['nome_obra'] ?? 'Obra Sem Nome';

        if (!isset($autos_por_obra[$nome_obra])) {
            $autos_por_obra[$nome_obra] = [
                'id_obra' => $a['id_obra_real'],
                'cliente' => $a['nome_cliente'] ?? 'Cliente Desconhecido',
                'estado_obra' => $a['estado_obra'] ?? 'N/D',
                'autos' => []
            ];
        }

        if (!empty($a['id'])) {
            $autos_por_obra[$nome_obra]['autos'][] = $a;
            $chave = chaveDataAuto($a, $col_data_auto);
            if (!isset($autos_por_obra[$nome_obra]['ultimo']) || $chave > $autos_por_obra[$nome_obra]['ultimo']) {
                $autos_por_obra[$nome_obra]['ultimo'] = $chave;
                $autos_por_obra[$nome_obra]['ultimo_auto'] = $a;
            }
        }
    }

    // Ordenar: obra com o auto mais recente primeiro; obras sem autos no fim (por nome)
    uksort($autos_por_obra, function ($na, $nb) use ($autos_por_obra) {
        $a = $autos_por_obra[$na]['ultimo'] ?? null;
        $b = $autos_por_obra[$nb]['ultimo'] ?? null;
        if ($a === null && $b === null) return strcasecmp($na, $nb);
        if ($a === null) return 1;
        if ($b === null) return -1;
        return $b <=> $a;
    });
} catch (Throwable $e) {
    error_log('autos.php: ' . $e->getMessage() . ' em ' . $e->getFile() . ':' . $e->getLine());
    die("<div style='margin: 50px auto; max-width: 800px; padding: 25px; background: #fff3f3; color: #d63031; border: 2px solid #ff7675; border-radius: 8px; font-family: sans-serif; box-shadow: 0 4px 15px rgba(0,0,0,0.1);'>
            <h2 style='margin-top:0; border-bottom: 1px solid #ff7675; padding-bottom: 10px;'>⚠️ Não foi possível abrir os autos</h2>
            <p style='font-size: 16px;'>Tente de novo. Se o erro continuar, avise o administrador.</p>
         </div>");
}
?>
<!DOCTYPE html>
<html lang="pt-PT">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Autos de Medição - Gestão MAB</title>
    <?php /* REGRA DE OURO 8: ícone MAB no separador */ ?>
    <link rel="icon" type="image/png" href="/mab.png">
    <link rel="apple-touch-icon" href="/mab.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { font-family: system-ui, -apple-system, sans-serif; background-color: #f7f9fc; margin: 0; color: #333; font-size: 14px; padding-bottom:50px;}
        * { box-sizing: border-box; }

        /* CABEÇALHO (regras do projeto: logo -> início, sem ícones de navegação, voltar só com símbolo) */
        .topbar { background: rgba(255,255,255,0.94); backdrop-filter: blur(8px); -webkit-backdrop-filter: blur(8px); padding: 10px 40px; box-shadow: 0 1px 0 #e8ecf1; display: flex; justify-content: space-between; align-items: center; position: sticky; top: 0; z-index: 100; }
        .topbar-left a { display: block; }
        .topbar-left .logo { height: 35px; display: block; }
        .topbar-right { display: flex; align-items: center; gap: 12px; }
        .btn-voltar { width: 40px; height: 40px; border-radius: 50%; background: #fff; border: 1px solid #e8ecf1; color: #576574; display: inline-flex; align-items: center; justify-content: center; text-decoration: none; font-size: 16px; transition: all 0.2s ease; box-shadow: 0 2px 6px rgba(0,0,0,0.04); flex-shrink: 0; }
        .btn-voltar:hover { background: #fdf2f2; color: #b32a2a; border-color: #f5b7b1; transform: translateX(-2px); }
        .btn-voltar:active { transform: scale(0.94); }

        .user-menu { position: relative; }
        .user-btn { background: #fff; border: 1px solid #e8ecf1; border-radius: 30px; font-size: 14px; font-weight: 600; color: #2c3e50; cursor: pointer; display: flex; align-items: center; gap: 10px; padding: 5px 14px 5px 5px; transition: 0.2s; }
        .user-btn:hover { box-shadow: 0 4px 12px rgba(0,0,0,0.06); }
        .user-avatar { background: #e74c3c; color: white; width: 28px; height: 28px; border-radius: 50%; display: flex; justify-content: center; align-items: center; font-size: 13px; }

        .dropdown-content { display: none; position: absolute; right: 0; top: 100%; margin-top: 6px; background: #fff; min-width: 180px; box-shadow: 0 12px 30px rgba(0,0,0,0.1); border-radius: 10px; overflow: hidden; border: 1px solid #e8ecf1; z-index:200;}
        .dropdown-content a { color: #576574; padding: 12px 20px; text-decoration: none; display: block; font-size: 14px; }
        .dropdown-content a:hover { background: #fdf2f2; color: #b32a2a; }
        .user-menu:hover .dropdown-content, .user-menu.aberto .dropdown-content { display: block; }

        .container { max-width: 1400px; margin: 30px auto; padding: 0 20px; }
        .page-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
        .page-title { margin: 0; color: #2c3e50; font-size: 24px; font-weight: 800; }

        .header-actions { display: flex; gap: 15px; margin-bottom: 25px; }
        .btn-secondary { background: #fff; color: #576574; border: 1px solid #dcdde1; padding: 12px 20px; border-radius: 8px; text-decoration: none; font-weight: 600; display: inline-flex; align-items: center; gap: 8px; cursor: pointer; transition: 0.2s;}
        .btn-secondary:hover, .btn-secondary.active { border-color: #b32a2a; color: #b32a2a; background: #fdf2f2; }

        .btn-primary { background: #b32a2a; color: #fff; padding: 10px 18px; border-radius: 8px; text-decoration: none; font-weight: 600; display: inline-flex; align-items: center; gap: 8px; font-size: 13px; transition: background 0.2s; border:none; cursor:pointer;}
        .btn-primary:hover { background: #902222; }
        .btn-excel { background: #27ae60; color: #fff; padding: 6px 12px; border-radius: 6px; text-decoration: none; font-weight: 600; font-size: 12px; transition: 0.2s; }
        .btn-excel:hover { background: #219653; }
        .btn-locked { background: #eee; color: #999; border: 1px solid #ddd; cursor: not-allowed; padding: 10px 18px; border-radius: 8px; display: inline-flex; align-items: center; gap: 8px; font-size: 13px; text-decoration: none; }

        .table-container { background: #fff; border-radius: 12px; box-shadow: 0 4px 15px rgba(0,0,0,0.04); border: 1px solid #e1e5ea; overflow: hidden; margin-bottom: 30px;}
        .obra-header { background: #fdf2f2; border-bottom: 2px solid #b32a2a; padding: 12px 20px; font-size: 15px; font-weight: 700; color: #b32a2a; display: flex; justify-content: space-between; align-items: center; }
        .obra-info { display: flex; flex-direction: column; gap: 2px; }
        .obra-cliente { font-size: 11px; color: #576574; font-weight: normal; }
        .obra-actions { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; justify-content: flex-end; }
        .obra-ultimo { font-size: 11px; color: #576574; font-weight: 600; background: #fff; border: 1px solid #f5b7b1; border-radius: 20px; padding: 3px 9px; display: inline-flex; align-items: center; gap: 5px; margin-top: 4px; align-self: flex-start; }
        .tabela-wrap { overflow-x: auto; }

        table { width: 100%; border-collapse: collapse; }
        th { padding: 12px 10px; background: #f8f9fa; color: #576574; font-size: 10px; text-transform: uppercase; text-align: left; border-bottom: 1px solid #eee; }
        td { padding: 12px 10px; border-bottom: 1px solid #f1f2f6; font-size: 13px; }
        .text-center { text-align: center; }

        .btn-icon { color: #576574; margin: 0 6px; text-decoration: none; font-size: 16px; cursor: pointer; border:none; background:none; transition: color 0.2s; }
        .btn-icon:hover { color: #b32a2a; }
        .btn-icon-excel:hover { color: #27ae60; }
        .btn-disabled { color: #ccc !important; cursor: not-allowed !important; }

        .modal { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.6); z-index: 1001; justify-content: center; align-items: center; }
        .modal.show { display: flex; }
        .modal-content { background: #fff; padding: 30px; border-radius: 12px; width: 90%; max-width: 400px; text-align: center; }
        .modal-content input { width: 100%; padding: 12px; margin: 15px 0; border: 1px solid #ddd; border-radius: 8px; }

        /* TABLET */
        @media (max-width: 1024px) {
            .topbar { padding: 10px 24px; }
            .container { margin: 24px auto; }
            table { min-width: 860px; }
        }

        /* TELEMÓVEL: cada auto passa a ser um cartão */
        @media (max-width: 768px) {
            .topbar { padding: 8px 15px; }
            .topbar-left .logo { height: 30px; }
            .btn-voltar { width: 38px; height: 38px; }
            .container { margin: 18px auto; padding: 0 12px; }
            .page-header { flex-direction: column; align-items: stretch; gap: 12px; }
            .page-title { font-size: 22px; }
            .page-header .btn-primary { justify-content: center; padding: 12px; }
            .header-actions { gap: 8px; overflow-x: auto; flex-wrap: nowrap; padding-bottom: 4px; -webkit-overflow-scrolling: touch; }
            .btn-secondary { flex-shrink: 0; padding: 10px 14px; font-size: 13px; }

            .obra-header { flex-direction: column; align-items: stretch; gap: 10px; padding: 12px 14px; }
            .obra-actions { justify-content: stretch; }
            .obra-actions > * { flex: 1; justify-content: center; text-align: center; }

            table { min-width: 0; }
            table, thead, tbody, tr, td { display: block; width: 100%; }
            thead { display: none; }
            tbody tr { border-bottom: 1px solid #eef0f3; padding: 10px 14px; display: grid; grid-template-columns: 1fr 1fr; gap: 6px 12px; }
            tbody tr:last-child { border-bottom: none; }
            td { border: none; padding: 0; text-align: left !important; font-size: 13px; }
            td::before { content: attr(data-label); display: block; font-size: 10px; font-weight: 700; color: #95a5a6; text-transform: uppercase; letter-spacing: 0.3px; }
            td.td-auto { grid-column: 1 / -1; font-size: 15px; }
            td.td-auto::before, td.td-acoes::before, td.td-vazio::before { display: none; }
            td.td-acoes { grid-column: 1 / -1; display: flex; justify-content: flex-end; gap: 4px; border-top: 1px dashed #eef0f3; padding-top: 8px; }
            td.td-vazio { grid-column: 1 / -1; text-align: center !important; }
            .btn-icon { font-size: 18px; padding: 8px 10px; margin: 0; background: #f8f9fb; border-radius: 8px; }
        }
        @media (max-width: 560px) {
            .user-nome, .user-btn .fa-chevron-down { display: none; }
            .user-btn { padding: 4px; }
        }
    </style>
</head>
<body>
    <header class="topbar">
        <div class="topbar-left"><a href="index.php" title="Página inicial"><img src="mab.png" alt="MAB" class="logo"></a></div>
        <div class="topbar-right">
            <a href="index.php" class="btn-voltar" title="Voltar" aria-label="Voltar"><i class="fa-solid fa-arrow-left"></i></a>
            <div class="user-menu">
                <button type="button" class="user-btn">
                    <div class="user-avatar"><i class="fa-solid fa-user"></i></div>
                    <span class="user-nome"><?= htmlspecialchars($primeiro_nome) ?></span>
                    <i class="fa-solid fa-chevron-down" style="font-size: 10px;"></i>
                </button>
                <div class="dropdown-content">
                    <a href="perfil.php"><i class="fa-solid fa-id-badge" style="width:20px;"></i> O meu perfil</a>
                    <?php if ($is_admin): ?>
                        <a href="configuracoes.php"><i class="fa-solid fa-gear" style="width:20px;"></i> Configurações</a>
                    <?php endif; ?>
                    <a href="index.php?logout=1" style="color: #e74c3c; border-top: 1px solid #eee;"><i class="fa-solid fa-right-from-bracket" style="width:20px;"></i> Sair</a>
                </div>
            </div>
        </div>
    </header>

    <div class="container">
        <?php if ($msg_topo): ?>
            <div style="padding:13px 15px; border-radius:8px; margin-bottom:18px; font-weight:600; <?= $msg_tipo === 'sucesso' ? 'background:#e8f5e9; color:#1e8449; border:1px solid #c8e6c9;' : 'background:#fdeded; color:#c0392b; border:1px solid #fadbd8;' ?>"><?= htmlspecialchars($msg_topo) ?></div>
        <?php endif; ?>
        <div class="page-header">
            <h1 class="page-title">Autos de Medição</h1>
            <a href="auto_form.php" class="btn-primary" style="background:#34495e;"><i class="fa-solid fa-file-circle-plus"></i> Auto Independente</a>
        </div>

        <div class="header-actions">
            <a href="?estado=abertas" class="btn-secondary <?= $filtro_estado=='abertas'?'active':'' ?>"><i class="fa-solid fa-folder-open" style="color:#3498db;"></i> Obras Abertas</a>
            <a href="?estado=concluidas" class="btn-secondary <?= $filtro_estado=='concluidas'?'active':'' ?>"><i class="fa-solid fa-folder-closed" style="color:#f39c12;"></i> Obras Concluídas</a>
            <a href="?estado=todas" class="btn-secondary <?= $filtro_estado=='todas'?'active':'' ?>"><i class="fa-solid fa-layer-group" style="color:#9b59b6;"></i> Todas</a>
        </div>

        <?php if(!empty($autos_por_obra)): foreach($autos_por_obra as $nome_obra => $dados_obra):
            $stmt_last = $pdo->prepare("SELECT MAX(numero_auto) FROM autos WHERE id_obra = ?");
            $stmt_last->execute([$dados_obra['id_obra']]);
            $ultimo_numero_auto = $stmt_last->fetchColumn();

            // VERIFICAÇÃO DE BLOQUEIO: Obra Concluída?
            $obra_concluida = ($dados_obra['estado_obra'] === 'Concluída');
        ?>
            <div class="table-container">
                <div class="obra-header">
                    <div class="obra-info">
                        <span><i class="fa-solid fa-building" style="margin-right: 8px;"></i> <?= htmlspecialchars($nome_obra) ?></span>
                        <span class="obra-cliente"><i class="fa-solid fa-user" style="margin-right: 5px;"></i> <?= htmlspecialchars($dados_obra['cliente']) ?></span>
                        <?php if (!empty($dados_obra['ultimo_auto'])):
                            $ua = $dados_obra['ultimo_auto'];
                            $ua_data = ($col_data_auto && !empty($ua[$col_data_auto])) ? date('d/m/Y', strtotime($ua[$col_data_auto])) : ($ua['mes_referencia'] ?: ''); ?>
                            <span class="obra-ultimo"><i class="fa-solid fa-clock-rotate-left"></i> Último: Auto #<?= htmlspecialchars($ua['numero_auto'] ?? '') ?><?= $ua_data !== '' ? ' · ' . htmlspecialchars($ua_data) : '' ?></span>
                        <?php endif; ?>
                    </div>

                    <div class="obra-actions">
                        <!-- BOTÃO EXCEL GERAL MAPA OBRA -->
                        <?php if(count($dados_obra['autos']) > 0): ?>
                            <a href="?exportar_excel=<?= $dados_obra['id_obra'] ?>" class="btn-excel" title="Exportar Mapa da Obra para Excel com Fórmulas"><i class="fa-solid fa-file-excel"></i> Mapa Geral</a>
                        <?php endif; ?>

                        <?php if($obra_concluida): ?>
                            <span class="btn-locked" title="Obra concluída. Não é possível criar novos autos.">
                                <i class="fa-solid fa-lock"></i> Obra Concluída
                            </span>
                        <?php else: ?>
                            <?php if(count($dados_obra['autos']) == 0): ?>
                                <!-- BOTAO NOVO AUTO QUE ABRE A JANELA MODAL PARA O 1º AUTO -->
                                <button type="button" class="btn-primary" onclick="abrirModalTipoImportacao(<?= $dados_obra['id_obra'] ?>)" title="Gerar primeiro auto para esta obra">
                                    <i class="fa-solid fa-plus"></i> Novo Auto
                                </button>
                            <?php else: ?>
                                <a href="auto_form.php?obra_id=<?= $dados_obra['id_obra'] ?>" class="btn-primary" title="Gerar próximo auto para esta obra">
                                    <i class="fa-solid fa-plus"></i> Novo Auto
                                </a>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="tabela-wrap">
                <table>
                    <thead>
                        <tr>
                            <th class="text-center">Nº Auto</th>
                            <th class="text-center">Mês Referência</th>
                            <th class="text-center">Total Adjudicado</th>
                            <th class="text-center">Total Sup.</th>
                            <th class="text-center">Total Adj. + Sup.</th>
                            <th class="text-center">Mês Adjudicado</th>
                            <th class="text-center">Mês Sup.</th>
                            <th class="text-center">Total Auto</th>
                            <th class="text-center">Total Acumulado</th>
                            <th class="text-center">Saldo Adj. + Sup.</th>
                            <th class="text-center">Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if(count($dados_obra['autos']) > 0): ?>
                            <?php foreach($dados_obra['autos'] as $a):
                                // Totais por linha arredondados ao cêntimo (preço unitário com 3 casas) — iguais ao auto_form / PDF / Excel
                                // Totais calculados linha a linha com as mesmas regras do auto_form / PDF / Excel
                                $stmt_calc = $pdo->prepare("SELECT * FROM auto_itens WHERE id_auto = ?");
                                $stmt_calc->execute([$a['id']]);
                                $tot_adj = 0; $tot_sup = 0; $tot_mes_adj = 0; $tot_mes_sup = 0; $saldo_adj = 0; $saldo_sup = 0;
                                foreach ($stmt_calc->fetchAll(PDO::FETCH_ASSOC) as $li) {
                                    $q_adj = (float)$li['qtd_adjudicada']; $p_un = (float)$li['preco_unitario'];
                                    $q_ant = (float)$li['qtd_anterior'];   $q_mes = (float)$li['qtd_mes'];
                                    $t_adj = (float)$li['total_adjudicado'];
                                    $t_acum = (float)$li['total_acumulado'];
                                    // Mês: mesmas regras do auto_form (Total acertado à mão + acerto de cêntimos do saldo)
                                    $acerto = totalFoiAcertado($t_adj, $q_adj, $p_un);
                                    if ($acerto && $q_adj > 0 && abs((float)$li['qtd_acumulada'] - $q_adj) < 0.0005) {
                                        $t_mes = (abs($q_ant - $q_adj) < 0.0005) ? 0 : round($t_adj - totLinha($q_ant, $p_un), 2);
                                    } else {
                                        $t_mes = totLinha($q_mes, $p_un);
                                    }
                                    $t_mes = round($t_mes + (float)($li['acerto_acum'] ?? 0) - (float)($li['acerto_ant'] ?? 0), 2);
                                    // Saldo só nas linhas com quantidade adjudicada / prevista
                                    $saldo_l = $q_adj > 0 ? round($t_adj - $t_acum, 2) : 0;
                                    if ($li['tipo'] === 'Suplementar') {
                                        $tot_sup += $t_adj; $tot_mes_sup += $t_mes; $saldo_sup += $saldo_l;
                                    } else {
                                        $tot_adj += $t_adj; $tot_mes_adj += $t_mes; $saldo_adj += $saldo_l;
                                    }
                                }
                                $tot_adj_sup = $tot_adj + $tot_sup;
                                $saldo = round($saldo_adj + $saldo_sup, 2);
                                $cor_saldo = ($saldo < 0) ? '#b32a2a' : ($saldo == 0 ? '#27ae60' : '#e67e22');
                                $is_last = ($a['numero_auto'] == $ultimo_numero_auto);
                            ?>
                            <tr>
                                <td class="text-center td-auto" style="font-weight:bold;">Auto #<?= htmlspecialchars($a['numero_auto'] ?? '') ?></td>
                                <td class="text-center" data-label="Mês Referência"><?= htmlspecialchars($a['mes_referencia'] ?: 'N/D') ?></td>
                                <td class="text-center" data-label="Total Adjudicado"><?= number_format($tot_adj, 2, ',', '.') ?> €</td>
                                <td class="text-center" data-label="Total Sup."><?= $tot_sup != 0 ? number_format($tot_sup, 2, ',', '.') . ' €' : '-' ?></td>
                                <td class="text-center" data-label="Total Adj. + Sup." style="font-weight:bold;"><?= number_format($tot_adj_sup, 2, ',', '.') ?> €</td>
                                <td class="text-center" data-label="Mês Adjudicado"><?= number_format($tot_mes_adj, 2, ',', '.') ?> €</td>
                                <td class="text-center" data-label="Mês Sup."><?= $tot_mes_sup > 0 ? number_format($tot_mes_sup, 2, ',', '.') . ' €' : '-' ?></td>
                                <td class="text-center" data-label="Total Auto" style="font-weight:bold; color:#b32a2a;"><?= number_format($a['valor_total_mes'] ?? 0, 2, ',', '.') ?> €</td>
                                <td class="text-center" data-label="Total Acumulado"><?= number_format($a['valor_total_acumulado'] ?? 0, 2, ',', '.') ?> €</td>
                                <td class="text-center" data-label="Saldo Adj. + Sup." style="font-weight:bold; color: <?= $cor_saldo ?>;" title="Adjudicado: <?= number_format($saldo_adj, 2, ',', '.') ?> € · Suplementares: <?= number_format($saldo_sup, 2, ',', '.') ?> €"><?= number_format($saldo, 2, ',', '.') ?> €<?php if (abs($saldo_sup) >= 0.005): ?><div style="font-size:10px;font-weight:600;color:#7f8c8d">Adj. <?= number_format($saldo_adj, 2, ',', '.') ?> € · Sup. <?= number_format($saldo_sup, 2, ',', '.') ?> €</div><?php endif; ?></td>
                                <td class="text-center td-acoes">
                                    <a href="auto_excel.php?id=<?= htmlspecialchars($a['id'] ?? '') ?>" class="btn-icon btn-icon-excel" title="Exportar Detalhe do Auto para Excel (com fórmulas)"><i class="fa-solid fa-file-excel"></i></a>
                                    <a href="auto_imprimir.php?id=<?= htmlspecialchars($a['id'] ?? '') ?>" class="btn-icon" title="Imprimir em PDF"><i class="fa-solid fa-print"></i></a>
                                    <?php if($is_last && !$obra_concluida): ?>
                                        <a href="auto_form.php?id=<?= htmlspecialchars($a['id'] ?? '') ?>" class="btn-icon" title="Editar"><i class="fa-solid fa-pen-to-square"></i></a>
                                        <button onclick="abrirModalApagar(<?= htmlspecialchars($a['id'] ?? '') ?>)" class="btn-icon" style="color:#b32a2a" title="Apagar"><i class="fa-solid fa-trash-can"></i></button>
                                    <?php elseif($is_last && $obra_concluida): ?>
                                        <button class="btn-icon btn-disabled" title="Obra concluída: Edição bloqueada"><i class="fa-solid fa-lock"></i></button>
                                    <?php else: ?>
                                        <button onclick="validarEdicaoAntiga(<?= htmlspecialchars($a['id'] ?? '') ?>)" class="btn-icon" title="Editar Auto Antigo"><i class="fa-solid fa-lock"></i></button>
                                        <button class="btn-icon btn-disabled" title="Bloqueado"><i class="fa-solid fa-ban"></i></button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="11" class="text-center td-vazio" style="color: #7f8c8d; padding: 20px; font-style: italic;">
                                    Nenhum auto emitido para esta obra.
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
                </div>
            </div>
        <?php endforeach; else: ?>
            <div class="table-container"><table><tbody><tr><td class="text-center td-vazio" style="padding:50px; color:#7f8c8d;">Nenhuma obra encontrada para este filtro.</td></tr></tbody></table></div>
        <?php endif; ?>
    </div>

    <!-- JANELA MODAL PARA SENHA DE SEGURANÇA -->
    <div class="modal" id="modalSeguranca">
        <div class="modal-content">
            <h3 id="modal_titulo" style="color:#b32a2a;">Segurança</h3>
            <p id="modal_texto">Introduza a password de segurança:</p>
            <form method="POST" id="form_seguranca" action="autos.php">
                <?php /* REGRA DE OURO 7 */ ?><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(gerar_csrf_token()) ?>">
                <input type="hidden" name="acao" id="seg_acao" value="">
                <input type="hidden" name="id" id="seg_id" value="">
                <input type="password" name="pass_seguranca" id="input_pass_seguranca" placeholder="Password" required autocomplete="off">
                <div style="display:flex; gap:10px;">
                    <button type="button" onclick="fecharModal()" style="flex:1; background:#eee; border:none; padding:10px; border-radius:8px; cursor:pointer;">Cancelar</button>
                    <button type="submit" id="btn_confirmar_modal" class="btn-primary" style="flex:1; justify-content:center;">Confirmar</button>
                </div>
            </form>
        </div>
    </div>

    <!-- JANELA MODAL PARA TIPO DE IMPORTAÇÃO (1º AUTO) -->
    <div class="modal" id="modalTipoImportacao">
        <div class="modal-content" style="text-align: left; max-width: 450px;">
            <h3 style="color:#34495e; margin-top:0; display:flex; justify-content:space-between; align-items:center;">
                <span><i class="fa-solid fa-file-import"></i> Importar 1º Auto</span>
                <button type="button" onclick="fecharModalTipoImportacao()" style="background:none; border:none; font-size:24px; cursor:pointer; color:#95a5a6; padding:0;">&times;</button>
            </h3>
            <p style="color:#576574; margin-bottom:20px; font-size: 14px;">Que linhas do orçamento pretende importar como base para este Auto de Medição?</p>
            <div style="display:flex; flex-direction:column; gap:10px;">
                <button type="button" onclick="confirmarTipoImportacao('Comercial')" class="btn-primary" style="justify-content:center; padding:15px; font-size: 14px; background:#8b5cf6;"><i class="fa-solid fa-eye"></i> Resumo Comercial (Para o Cliente)</button>
                <button type="button" onclick="confirmarTipoImportacao('Interno')" class="btn-secondary" style="justify-content:center; padding:15px; font-size: 14px; margin:0;"><i class="fa-solid fa-calculator"></i> Detalhe Interno (Estudo de Custos)</button>
            </div>
        </div>
    </div>

    <script>
        let idAlvo = null;
        let acaoAlvo = '';

        // FUNÇÕES PARA O MODAL DE SEGURANÇA
        function abrirModal(titulo, texto, id, acao) {
            idAlvo = id; acaoAlvo = acao;
            // Editar auto antigo → verificado em autos.php; apagar → verificado em auto_apagar.php
            document.getElementById('form_seguranca').action = (acao === 'apagar') ? 'auto_apagar.php' : 'autos.php';
            document.getElementById('seg_acao').value = (acao === 'apagar') ? 'apagar' : 'editar_antigo';
            document.getElementById('seg_id').value = id;
            document.getElementById('modal_titulo').innerText = titulo;
            document.getElementById('modal_texto').innerText = texto;
            document.getElementById('input_pass_seguranca').value = '';
            document.getElementById('modalSeguranca').classList.add('show');
            document.getElementById('input_pass_seguranca').focus();
        }

        function fecharModal() {
            document.getElementById('modalSeguranca').classList.remove('show');
        }

        function validarEdicaoAntiga(id) {
            abrirModal('Editar Auto Antigo', 'Esta ação requer password:', id, 'editar');
        }

        function abrirModalApagar(id) {
            abrirModal('Apagar Auto', 'Eliminar o último registo? Requer password:', id, 'apagar');
        }


        // FUNÇÕES PARA O MODAL DE IMPORTAÇÃO (1º AUTO)
        let obraIdParaImportar = null;

        function abrirModalTipoImportacao(idObra) {
            obraIdParaImportar = idObra;
            document.getElementById('modalTipoImportacao').classList.add('show');
        }

        function fecharModalTipoImportacao() {
            document.getElementById('modalTipoImportacao').classList.remove('show');
            obraIdParaImportar = null;
        }

        function confirmarTipoImportacao(tipo) {
            if (obraIdParaImportar) {
                window.location.href = 'auto_form.php?obra_id=' + obraIdParaImportar + '&tipo_import=' + tipo;
            }
        }

        window.onclick = function(e) {
            if (e.target.classList.contains('modal')) {
                e.target.classList.remove('show');
            }
        };

        // Menu do utilizador: em ecrãs táteis (tablet/telemóvel) abre com toque
        (function () {
            const menu = document.querySelector('.user-menu');
            const btn = menu ? menu.querySelector('.user-btn') : null;
            if (!btn) return;
            btn.addEventListener('click', function (e) { e.stopPropagation(); menu.classList.toggle('aberto'); });
            document.addEventListener('click', function () { menu.classList.remove('aberto'); });
        })();
    </script>
</body>
</html>