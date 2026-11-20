<?php
// FORÇAR EXIBIÇÃO DE ERROS BÁSICOS
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

try {
    session_start();
    require_once 'db.php';

    // REGRA DE OURO 1: Validação de sessão
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

    $nome_completo = $_SESSION['nome'] ?? 'Utilizador';
    $primeiro_nome = explode(' ', trim($nome_completo))[0];

    $mensagem = '';
    $tipo_mensagem = '';

    if (isset($_GET['sucesso'])) {
        if ($_GET['sucesso'] == 'acerto') {
            $mensagem = "Acerto efetuado com sucesso! Foi gerado o saldo de abertura em 'Abertura + Faturas Cliente'.";
            $tipo_mensagem = "sucesso";
        } elseif ($_GET['sucesso'] == 'revertido') {
            $mensagem = "Acerto revertido. Os registos voltaram ao estado 'Ativo'.";
            $tipo_mensagem = "sucesso";
        }
    }

    function tabelaExiste($pdo, $tabela) {
        $st = $pdo->query("SHOW TABLES LIKE " . $pdo->quote($tabela));
        return $st && $st->rowCount() > 0;
    }

    // --- 1. CRIAÇÃO DE TABELA E COLUNAS NECESSÁRIAS ---
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS jdias_acertos (
            numero INT AUTO_INCREMENT PRIMARY KEY,
            data_registo DATE NOT NULL,
            valor DECIMAL(12,2) NOT NULL,
            valor_fserv DECIMAL(12,2) DEFAULT 0.00,
            valor_fat DECIMAL(12,2) DEFAULT 0.00,
            valor_fatfav DECIMAL(12,2) DEFAULT 0.00,
            abertura_gerada_num INT,
            n_fserv INT DEFAULT 0,
            n_fat INT DEFAULT 0,
            n_fatfav INT DEFAULT 0,
            criado_por VARCHAR(100) DEFAULT NULL,
            criado_em DATETIME DEFAULT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

        // Garantir que as colunas existem se a tabela for antiga
        $colunas_novas = [
            'valor_fserv'  => "DECIMAL(12,2) DEFAULT 0.00",
            'valor_fat'    => "DECIMAL(12,2) DEFAULT 0.00",
            'valor_fatfav' => "DECIMAL(12,2) DEFAULT 0.00",
            'n_fserv'      => "INT DEFAULT 0",
            'n_fat'        => "INT DEFAULT 0",
            'n_fatfav'     => "INT DEFAULT 0",
            'criado_por'   => "VARCHAR(100) DEFAULT NULL",
            'criado_em'    => "DATETIME DEFAULT NULL",
        ];
        foreach ($colunas_novas as $col => $def) {
            if ($pdo->query("SHOW COLUMNS FROM jdias_acertos LIKE '$col'")->rowCount() == 0) {
                $pdo->exec("ALTER TABLE jdias_acertos ADD COLUMN $col $def");
            }
        }

        // Adicionar coluna id_acerto às 3 tabelas (para permitir reversão segura)
        foreach (['jdias_fserv', 'jdias_fat', 'jdias_fatfav'] as $tb) {
            if (!tabelaExiste($pdo, $tb)) continue;
            if ($pdo->query("SHOW COLUMNS FROM $tb LIKE 'id_acerto'")->rowCount() == 0) {
                $pdo->exec("ALTER TABLE $tb ADD COLUMN id_acerto INT DEFAULT NULL");
            }
        }
    } catch (Exception $e) {
        // Ignora caso já existam
    }

    // --- 2. LÓGICA DO ACERTO / REVERSÃO (POST) ---
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {

        // EFETUAR ACERTO
        if (isset($_POST['action']) && $_POST['action'] === 'efetuar') {
            try {
                foreach (['jdias_fserv', 'jdias_fat', 'jdias_fatfav'] as $tb) {
                    if (!tabelaExiste($pdo, $tb)) throw new Exception("A tabela $tb ainda não existe. Abra primeiro as páginas Folhas de Serviço, Faturas e Abertura + Faturas Cliente.");
                }

                $data_acerto = $_POST['data_acerto'] ?? date('Y-m-d');
                if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $data_acerto)) $data_acerto = date('Y-m-d');

                // VERIFICAÇÃO 1: FSERV não corrigidas
                $fserv_por_corrigir = $pdo->query("SELECT COUNT(*) FROM jdias_fserv WHERE estado = 'Ativo' AND valor_certo = 'Nao'")->fetchColumn() ?: 0;

                if ($fserv_por_corrigir > 0) {
                    $mensagem = "Erro: Existem $fserv_por_corrigir Folha(s) de Serviço Ativas com o 'V. Certo' a 'Não'. Tem que corrigir todas antes de poder efetuar o acerto.";
                    $tipo_mensagem = "erro";
                } else {
                    $pdo->beginTransaction();

                    // 1. Somar valores Ativos (FSERV usa o valor corrigido)
                    $r_fserv  = $pdo->query("SELECT COALESCE(SUM(valor_corrigido),0) t, COUNT(*) n FROM jdias_fserv WHERE estado = 'Ativo'")->fetch(PDO::FETCH_ASSOC);
                    $r_fat    = $pdo->query("SELECT COALESCE(SUM(valor),0) t, COUNT(*) n FROM jdias_fat WHERE estado = 'Ativo'")->fetch(PDO::FETCH_ASSOC);
                    $r_fatfav = $pdo->query("SELECT COALESCE(SUM(valor),0) t, COUNT(*) n FROM jdias_fatfav WHERE estado = 'Ativo'")->fetch(PDO::FETCH_ASSOC);

                    $sum_fserv  = round((float)$r_fserv['t'], 2);
                    $sum_fat    = round((float)$r_fat['t'], 2);
                    $sum_fatfav = round((float)$r_fatfav['t'], 2);
                    $total_acerto = round($sum_fserv + $sum_fat + $sum_fatfav, 2);
                    $total_registos = (int)$r_fserv['n'] + (int)$r_fat['n'] + (int)$r_fatfav['n'];

                    if ($total_registos > 0) {
                        $data_pt = date('d/m/Y', strtotime($data_acerto));

                        // 2. Criar Registo do Acerto com as parcelas
                        $pdo->prepare("INSERT INTO jdias_acertos (data_registo, valor, valor_fserv, valor_fat, valor_fatfav, n_fserv, n_fat, n_fatfav, criado_por, criado_em)
                                       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())")
                            ->execute([$data_acerto, $total_acerto, $sum_fserv, $sum_fat, $sum_fatfav, (int)$r_fserv['n'], (int)$r_fat['n'], (int)$r_fatfav['n'], $nome_completo]);
                        $id_acerto = (int)$pdo->lastInsertId();

                        // 3. Passar todos os ativos a 'Acertado' e marcar com o nº do acerto
                        $pdo->prepare("UPDATE jdias_fserv SET estado = 'Acertado', id_acerto = ? WHERE estado = 'Ativo'")->execute([$id_acerto]);
                        $pdo->prepare("UPDATE jdias_fat SET estado = 'Acertado', id_acerto = ? WHERE estado = 'Ativo'")->execute([$id_acerto]);
                        $pdo->prepare("UPDATE jdias_fatfav SET estado = 'Acertado', id_acerto = ? WHERE estado = 'Ativo'")->execute([$id_acerto]);

                        // 4. Saldo de abertura (com a data do acerto) no 'Abertura + Faturas Cliente'
                        $abertura_num = null;
                        if (abs($total_acerto) >= 0.005) {
                            $abertura_num = (int)$pdo->query("SELECT COALESCE(MAX(numero),0) + 1 FROM jdias_fatfav")->fetchColumn();
                            $pdo->prepare("INSERT INTO jdias_fatfav (numero, tipo, data_registo, nome_cliente, valor, estado, observacoes)
                                           VALUES (?, 'Abertura', ?, 'Acerto de Contas', ?, 'Ativo', ?)")
                                ->execute([$abertura_num, $data_acerto, $total_acerto, "Saldo de abertura do Acerto nº $id_acerto de $data_pt"]);
                        }
                        $pdo->prepare("UPDATE jdias_acertos SET abertura_gerada_num = ? WHERE numero = ?")->execute([$abertura_num, $id_acerto]);

                        $pdo->commit();
                        header("Location: jdias_acertos.php?sucesso=acerto");
                        exit;
                    } else {
                        $pdo->rollBack();
                        $mensagem = "Não existem registos 'Ativos' para efetuar um acerto.";
                        $tipo_mensagem = "erro";
                    }
                }

            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $mensagem = "Erro ao efetuar acerto: " . $e->getMessage();
                $tipo_mensagem = "erro";
            }
        }

        // REVERTER ACERTO
        if (isset($_POST['action']) && $_POST['action'] === 'reverter' && !empty($_POST['id_reverter'])) {
            try {
                $id_acerto = (int)$_POST['id_reverter'];

                // VERIFICAÇÃO 2: Só pode reverter o ÚLTIMO acerto
                $ultimo_acerto = (int)$pdo->query("SELECT MAX(numero) FROM jdias_acertos")->fetchColumn();

                if ($id_acerto !== $ultimo_acerto) {
                    $mensagem = "Erro: Apenas o último acerto efetuado pode ser revertido por motivos de integridade dos dados.";
                    $tipo_mensagem = "erro";
                } else {
                    $pdo->beginTransaction();

                    // 1. Procurar o número da abertura gerada
                    $stmt = $pdo->prepare("SELECT abertura_gerada_num FROM jdias_acertos WHERE numero = ?");
                    $stmt->execute([$id_acerto]);
                    $abertura_num = $stmt->fetchColumn();

                    // 2. Apagar a Abertura gerada (se ainda não tiver entrado noutro acerto)
                    if ($abertura_num) {
                        $pdo->prepare("DELETE FROM jdias_fatfav WHERE numero = ? AND estado != 'Acertado'")->execute([$abertura_num]);
                    }

                    // 3. Devolver os registos a Ativo e limpar o id_acerto
                    $pdo->prepare("UPDATE jdias_fserv SET estado = 'Ativo', id_acerto = NULL WHERE id_acerto = ?")->execute([$id_acerto]);
                    $pdo->prepare("UPDATE jdias_fat SET estado = 'Ativo', id_acerto = NULL WHERE id_acerto = ?")->execute([$id_acerto]);
                    $pdo->prepare("UPDATE jdias_fatfav SET estado = 'Ativo', id_acerto = NULL WHERE id_acerto = ?")->execute([$id_acerto]);

                    // 4. Apagar o histórico de Acerto
                    $pdo->prepare("DELETE FROM jdias_acertos WHERE numero = ?")->execute([$id_acerto]);

                    $pdo->commit();
                    header("Location: jdias_acertos.php?sucesso=revertido");
                    exit;
                }

            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $mensagem = "Erro ao reverter acerto: " . $e->getMessage();
                $tipo_mensagem = "erro";
            }
        }
    }

    // --- 3. DADOS PARA EXIBIÇÃO (apenas documentos Ativos) ---
    $fserv_por_corrigir = 0;
    $pendentes_fserv = $pendentes_fat = $pendentes_fatfav = 0;
    $cnt_fserv = $cnt_fat = $cnt_fatfav = 0;
    if (tabelaExiste($pdo, 'jdias_fserv')) {
        $fserv_por_corrigir = (int)$pdo->query("SELECT COUNT(*) FROM jdias_fserv WHERE estado = 'Ativo' AND valor_certo = 'Nao'")->fetchColumn();
        $r = $pdo->query("SELECT COALESCE(SUM(valor_corrigido),0) t, COUNT(*) n FROM jdias_fserv WHERE estado = 'Ativo'")->fetch(PDO::FETCH_ASSOC);
        $pendentes_fserv = (float)$r['t']; $cnt_fserv = (int)$r['n'];
    }
    if (tabelaExiste($pdo, 'jdias_fat')) {
        $r = $pdo->query("SELECT COALESCE(SUM(valor),0) t, COUNT(*) n FROM jdias_fat WHERE estado = 'Ativo'")->fetch(PDO::FETCH_ASSOC);
        $pendentes_fat = (float)$r['t']; $cnt_fat = (int)$r['n'];
    }
    if (tabelaExiste($pdo, 'jdias_fatfav')) {
        $r = $pdo->query("SELECT COALESCE(SUM(valor),0) t, COUNT(*) n FROM jdias_fatfav WHERE estado = 'Ativo'")->fetch(PDO::FETCH_ASSOC);
        $pendentes_fatfav = (float)$r['t']; $cnt_fatfav = (int)$r['n'];
    }
    $total_pendente = round($pendentes_fserv + $pendentes_fat + $pendentes_fatfav, 2);
    $tem_pendentes = ($cnt_fserv + $cnt_fat + $cnt_fatfav) > 0;

    // Histórico de Acertos + documentos de cada um
    $historico = $pdo->query("SELECT * FROM jdias_acertos ORDER BY numero DESC")->fetchAll(PDO::FETCH_ASSOC);
    $docs_por_acerto = [];
    if (!empty($historico)) {
        $fontes = [
            'jdias_fserv'  => ['Folhas de Serviço', "SELECT id_acerto, data_registo AS data, CONCAT('FSERV ', numero) AS doc, valor_corrigido AS valor, '' AS extra, observacoes FROM jdias_fserv WHERE estado = 'Acertado' AND id_acerto IS NOT NULL"],
            'jdias_fat'    => ['Faturas', "SELECT id_acerto, data_registo AS data, CONCAT(IFNULL(tipo,'FAT'), ' ', numero) AS doc, valor, '' AS extra, observacoes FROM jdias_fat WHERE estado = 'Acertado' AND id_acerto IS NOT NULL"],
            'jdias_fatfav' => ['Abertura + Faturas Cliente', "SELECT id_acerto, data_registo AS data, CONCAT(tipo, ' ', numero) AS doc, valor, IFNULL(nome_cliente,'') AS extra, observacoes FROM jdias_fatfav WHERE estado = 'Acertado' AND id_acerto IS NOT NULL"],
        ];
        foreach ($fontes as $tabela => [$grupo, $sql]) {
            if (!tabelaExiste($pdo, $tabela)) continue;
            foreach ($pdo->query($sql . " ORDER BY data ASC")->fetchAll(PDO::FETCH_ASSOC) as $d) {
                $docs_por_acerto[(int)$d['id_acerto']][$grupo][] = $d;
            }
        }
    }

    function formata($val) { return number_format((float)$val, 2, ',', '.') . ' €'; }

} catch (Throwable $e) {
    die("Erro Fatal: " . htmlspecialchars($e->getMessage()));
}
?>
<!DOCTYPE html>
<html lang="pt-PT">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Acertos - J. Dias</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* ===== BASE COMUM (regras do projeto: logo -> início, sem ícones de navegação, voltar só com símbolo, responsivo) ===== */
        :root {
            --laranja: #e67e22; --laranja-esc: #d35400; --laranja-claro: #fdf2e9;
            --verde: #27ae60; --verde-claro: #eafaf1;
            --vermelho: #c0392b; --vermelho-claro: #fdedec;
            --azul: #2980b9; --azul-claro: #ebf5fb;
            --roxo: #8e44ad; --roxo-claro: #f5eef8;
            --texto: #2c3e50; --suave: #7f8c8d; --borda: #e8ecf1; --fundo: #f4f6fa;
        }
        * { font-family: system-ui, -apple-system, "Segoe UI", sans-serif; box-sizing: border-box; }
        body { background: var(--fundo); margin: 0; color: var(--texto); font-size: 14px; padding-bottom: 60px; }

        /* Cabeçalho */
        .topbar { background: rgba(255,255,255,0.94); backdrop-filter: blur(8px); -webkit-backdrop-filter: blur(8px); padding: 10px 40px; box-shadow: 0 1px 0 var(--borda); display: flex; justify-content: space-between; align-items: center; position: sticky; top: 0; z-index: 100; }
        .topbar-left a { display: block; }
        .topbar-left .logo { height: 35px; display: block; }
        .topbar-right { display: flex; align-items: center; gap: 12px; }
        .btn-voltar { width: 40px; height: 40px; border-radius: 50%; background: #fff; border: 1px solid var(--borda); color: #576574; display: inline-flex; align-items: center; justify-content: center; text-decoration: none; font-size: 16px; transition: all 0.2s ease; box-shadow: 0 2px 6px rgba(0,0,0,0.04); flex-shrink: 0; }
        .btn-voltar:hover { background: var(--laranja-claro); color: var(--laranja); border-color: #f5cba7; transform: translateX(-2px); }
        .btn-voltar:active { transform: scale(0.94); }
        .user-menu { position: relative; }
        .user-btn { background: #fff; border: 1px solid var(--borda); border-radius: 30px; font-size: 14px; font-weight: 600; color: var(--texto); cursor: pointer; display: flex; align-items: center; gap: 10px; padding: 5px 14px 5px 5px; transition: 0.2s; }
        .user-btn:hover { box-shadow: 0 4px 12px rgba(0,0,0,0.06); }
        .user-avatar { background: var(--laranja); color: #fff; width: 28px; height: 28px; border-radius: 50%; display: flex; justify-content: center; align-items: center; font-size: 13px; }
        .dropdown-content { display: none; position: absolute; right: 0; top: 100%; background: #fff; min-width: 180px; box-shadow: 0 12px 30px rgba(0,0,0,0.1); border-radius: 10px; border: 1px solid var(--borda); overflow: hidden; margin-top: 6px; z-index: 200; }
        .user-menu:hover .dropdown-content, .user-menu.aberto .dropdown-content { display: block; }
        .dropdown-content a { color: #576574; padding: 12px 20px; text-decoration: none; display: block; font-size: 14px; }
        .dropdown-content a:hover { background: var(--laranja-claro); color: var(--laranja); }

        /* Página */
        .container { max-width: 1100px; margin: 32px auto; padding: 0 20px; }
        .page-head { display: flex; justify-content: space-between; align-items: flex-end; gap: 15px; flex-wrap: wrap; margin-bottom: 22px; }
        .eyebrow { font-size: 12px; font-weight: 700; letter-spacing: 1.5px; text-transform: uppercase; color: var(--laranja); margin-bottom: 4px; }
        .page-title { margin: 0; font-size: 26px; font-weight: 800; letter-spacing: -0.5px; }
        .page-sub { color: var(--suave); margin-top: 4px; }
        .chip { background: #fff; border: 1px solid var(--borda); border-radius: 30px; padding: 8px 14px; font-size: 12.5px; color: #576574; display: inline-flex; align-items: center; gap: 8px; text-decoration: none; transition: 0.2s; font-weight: 600; }
        a.chip:hover { border-color: #f5cba7; color: var(--laranja-esc); }
        .chip i { color: var(--laranja); }

        .alert { padding: 14px 16px; border-radius: 10px; margin-bottom: 18px; font-weight: 600; display: flex; gap: 10px; align-items: flex-start; }
        .alert-erro { background: var(--vermelho-claro); color: var(--vermelho); border: 1px solid #f5b7b1; }
        .alert-sucesso { background: var(--verde-claro); color: #1e8449; border: 1px solid #abebc6; }
        .alert-aviso { background: #fef5e7; color: #9a6400; border: 1px solid #f9e79f; }

        .card { background: #fff; border-radius: 16px; border: 1px solid var(--borda); box-shadow: 0 2px 6px rgba(0,0,0,0.02); padding: 22px; margin-bottom: 24px; }
        .card h2 { margin: 0 0 16px; font-size: 16px; font-weight: 800; }

        @media (max-width: 1024px) {
            .topbar { padding: 10px 24px; }
            .container { margin: 24px auto; }
        }
        @media (max-width: 768px) {
            .topbar { padding: 8px 15px; }
            .topbar-left .logo { height: 30px; }
            .btn-voltar { width: 38px; height: 38px; }
            .container { margin: 18px auto; padding: 0 12px; }
            .page-title { font-size: 22px; }
            .page-sub { font-size: 13px; }
            .card { padding: 16px; border-radius: 14px; }
        }
        @media (max-width: 560px) {
            .user-nome { display: none; }
            .user-btn { padding: 4px; gap: 4px; }
            .user-btn .fa-caret-down { display: none; }
            .page-head .chip { width: 100%; justify-content: center; }
        }

        /* Totais */
        .grelha-totais { display: grid; grid-template-columns: repeat(4, 1fr); gap: 14px; }
        .caixa-total { background: #f8f9fb; border: 1px solid var(--borda); border-radius: 14px; padding: 18px 14px; text-align: center; display: flex; flex-direction: column; gap: 6px; border-top: 4px solid var(--c, #dcdde1); }
        .caixa-total .titulo { font-size: 11.5px; font-weight: 700; text-transform: uppercase; color: var(--suave); letter-spacing: 0.3px; }
        .caixa-total .valor { font-size: 22px; font-weight: 800; color: var(--texto); }
        .caixa-total .detalhe { font-size: 12px; color: #95a5a6; }
        .caixa-total .detalhe a { color: #7f8c8d; text-decoration: none; border-bottom: 1px dotted #bdc3c7; }
        .caixa-total .detalhe a:hover { color: var(--laranja); border-color: var(--laranja); }
        .caixa-acertar { background: #fff8e8; border-color: #fbd9a5; border-top-color: var(--laranja); }
        .caixa-acertar .titulo { color: var(--laranja-esc); }
        .caixa-acertar .valor { color: var(--laranja); font-size: 26px; }
        .neg { color: var(--vermelho) !important; }

        /* Botão de acerto */
        .barra-acerto { display: flex; gap: 12px; align-items: stretch; margin-top: 20px; flex-wrap: wrap; }
        .barra-acerto .data-acerto { display: flex; flex-direction: column; gap: 4px; }
        .barra-acerto label { font-size: 11px; font-weight: 700; color: #576574; text-transform: uppercase; }
        .barra-acerto input[type=date] { padding: 12px; border: 1px solid #dcdde1; border-radius: 10px; font-size: 14px; font-family: inherit; }
        .btn-acerto { flex: 1; min-width: 260px; background: var(--verde); color: #fff; border: none; border-radius: 10px; padding: 16px; font-size: 15px; font-weight: 800; text-transform: uppercase; cursor: pointer; display: flex; align-items: center; justify-content: center; gap: 10px; transition: 0.2s; }
        .btn-acerto:hover { background: #229954; }
        .btn-acerto:disabled { background: #bdc3c7; cursor: not-allowed; }

        /* Histórico */
        .tabela-hist { width: 100%; border-collapse: collapse; }
        .tabela-hist th { background: #f8f9fb; padding: 12px 10px; font-size: 11px; color: #576574; text-transform: uppercase; border-bottom: 1px solid var(--borda); }
        .tabela-hist td { padding: 12px 10px; border-bottom: 1px solid #f1f3f6; text-align: center; vertical-align: middle; }
        .parcelas { display: flex; justify-content: space-around; gap: 10px; flex-wrap: wrap; }
        .parcela { display: flex; flex-direction: column; font-size: 11px; color: #95a5a6; }
        .parcela b { font-size: 14px; color: var(--texto); }
        .total-hist { font-size: 17px; font-weight: 800; color: var(--laranja); }
        .sub-hist { font-size: 11px; color: var(--suave); }
        .acoes-hist { display: flex; flex-direction: column; gap: 5px; align-items: center; }
        .btn-ver, .btn-reverter { border-radius: 8px; padding: 7px 11px; font-weight: 700; cursor: pointer; font-size: 12px; display: inline-flex; align-items: center; gap: 6px; text-decoration: none; white-space: nowrap; }
        .btn-ver { background: #f1f2f6; color: #576574; border: 1px solid #dcdde1; }
        .btn-ver:hover { background: #e1e5ea; }
        .btn-imprimir { background: var(--laranja-claro); color: var(--laranja-esc); border-color: #f5cba7; }
        .btn-imprimir:hover { background: #fae5d3; }
        .btn-reverter { background: #e74c3c; color: #fff; border: none; }
        .btn-reverter:hover { background: #c0392b; }
        tr.detalhe-acerto td { background: #fbfbfc; text-align: left; }
        .lista-docs { display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 15px; }
        .lista-docs h4 { margin: 0 0 6px; font-size: 12px; color: #576574; text-transform: uppercase; }
        .lista-docs table { width: 100%; border-collapse: collapse; font-size: 12px; }
        .lista-docs td { border: none; border-bottom: 1px dashed #eef0f3; padding: 4px 2px; text-align: left; }
        .lista-docs td.v { text-align: right; white-space: nowrap; }

        @media (max-width: 1024px) {
            .grelha-totais { grid-template-columns: repeat(2, 1fr); }
        }
        @media (max-width: 768px) {
            .grelha-totais { grid-template-columns: 1fr 1fr; gap: 10px; }
            .caixa-acertar { grid-column: 1 / -1; }
            .caixa-total:nth-child(3) { grid-column: 1 / -1; }
            .caixa-total .valor { font-size: 18px; }
            .caixa-acertar .valor { font-size: 24px; }
            .barra-acerto .data-acerto { width: 100%; }
            .barra-acerto input[type=date] { font-size: 16px; }
            .btn-acerto { min-width: 0; width: 100%; }

            /* Histórico em cartões */
            .tabela-hist, .tabela-hist tbody, .tabela-hist tr, .tabela-hist td { display: block; width: 100%; }
            .tabela-hist thead { display: none; }
            .tabela-hist tr.linha-hist { border: 1px solid var(--borda); border-radius: 12px; padding: 12px; margin-top: 12px; display: grid; grid-template-columns: 1fr 1fr; gap: 8px; }
            .tabela-hist tr.linha-hist td { border: none; padding: 0; text-align: left; }
            .tabela-hist td.td-num { font-size: 16px; font-weight: 800; }
            .tabela-hist td.td-data { text-align: right; color: var(--suave); }
            .tabela-hist td.td-parcelas, .tabela-hist td.td-total, .tabela-hist td.td-acoes { grid-column: 1 / -1; }
            .parcelas { justify-content: space-between; background: #f8f9fb; border-radius: 10px; padding: 8px; }
            .acoes-hist { flex-direction: row; flex-wrap: wrap; justify-content: flex-start; }
            .acoes-hist form { margin: 0; }
            tr.detalhe-acerto td { border: 1px solid var(--borda); border-top: none; border-radius: 0 0 12px 12px; padding: 12px; }
        }
    </style>
</head>
<body>

    <header class="topbar">
        <div class="topbar-left"><a href="index.php" title="Página inicial"><img src="mab.png" alt="MAB" class="logo"></a></div>
        <div class="topbar-right">
            <a href="jdias.php" class="btn-voltar" title="Voltar" aria-label="Voltar"><i class="fa-solid fa-arrow-left"></i></a>
            <div class="user-menu">
                <button type="button" class="user-btn">
                    <div class="user-avatar"><i class="fa-solid fa-user"></i></div>
                    <span class="user-nome"><?= htmlspecialchars($primeiro_nome) ?></span>
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

        <?php if ($mensagem): ?>
            <div class="alert <?= $tipo_mensagem === 'sucesso' ? 'alert-sucesso' : 'alert-erro' ?>">
                <i class="fa-solid <?= $tipo_mensagem === 'sucesso' ? 'fa-circle-check' : 'fa-triangle-exclamation' ?>"></i>
                <span><?= htmlspecialchars($mensagem, ENT_QUOTES, 'UTF-8') ?></span>
            </div>
        <?php endif; ?>

        <div class="page-head">
            <div>
                <div class="eyebrow">J. Dias</div>
                <h1 class="page-title">Acertos</h1>
                <div class="page-sub">Total a acertar = Folhas de Serviço (V. Corrigido) + Faturas + Abertura e Faturas Cliente</div>
            </div>
            <a href="jdias_arquivo.php" class="chip"><i class="fa-solid fa-box-archive"></i> Arquivo (Acertados)</a>
        </div>

        <!-- ZONA 1: EFETUAR ACERTO -->
        <div class="card">
            <h2>Valores pendentes (estado 'Ativo')</h2>

            <?php if ($fserv_por_corrigir > 0): ?>
                <div class="alert alert-erro">
                    <i class="fa-solid fa-triangle-exclamation"></i>
                    <span><strong>Atenção:</strong> Existem <?= $fserv_por_corrigir ?> Folha(s) de Serviço por corrigir. O acerto está bloqueado até que o "V. Certo?" esteja a "Sim" em todas as Folhas de Serviço ativas. <a href="jdias_fserv.php" style="color: inherit;">Corrigir agora</a></span>
                </div>
            <?php endif; ?>

            <div class="grelha-totais">
                <div class="caixa-total" style="--c: #f0b27a;">
                    <span class="titulo">FSERV (V. Corrigido)</span>
                    <span class="valor <?= $pendentes_fserv < 0 ? 'neg' : '' ?>"><?= formata($pendentes_fserv) ?></span>
                    <span class="detalhe"><a href="jdias_fserv.php"><?= $cnt_fserv ?> registo(s)</a></span>
                </div>
                <div class="caixa-total" style="--c: #85c1e9;">
                    <span class="titulo">Faturas</span>
                    <span class="valor <?= $pendentes_fat < 0 ? 'neg' : '' ?>"><?= formata($pendentes_fat) ?></span>
                    <span class="detalhe"><a href="jdias_fat.php"><?= $cnt_fat ?> registo(s)</a></span>
                </div>
                <div class="caixa-total" style="--c: #c39bd3;">
                    <span class="titulo">Abertura + Faturas Cliente</span>
                    <span class="valor <?= $pendentes_fatfav < 0 ? 'neg' : '' ?>"><?= formata($pendentes_fatfav) ?></span>
                    <span class="detalhe"><a href="jdias_fatfav.php"><?= $cnt_fatfav ?> registo(s)</a></span>
                </div>
                <div class="caixa-total caixa-acertar">
                    <span class="titulo">Total a acertar</span>
                    <span class="valor <?= $total_pendente < 0 ? 'neg' : '' ?>"><?= formata($total_pendente) ?></span>
                    <span class="detalhe">Fica como saldo de abertura</span>
                </div>
            </div>

            <form method="POST" onsubmit="return confirm('ATENÇÃO: Ao efetuar este acerto, todos os documentos Ativos passam a Acertados e é criado o saldo de abertura com o total (<?= formata($total_pendente) ?>). Deseja prosseguir?');">
                <input type="hidden" name="action" value="efetuar">
                <div class="barra-acerto">
                    <div class="data-acerto">
                        <label for="data_acerto">Data do acerto</label>
                        <input type="date" id="data_acerto" name="data_acerto" value="<?= date('Y-m-d') ?>" required>
                    </div>
                    <button type="submit" class="btn-acerto" <?= (!$tem_pendentes || $fserv_por_corrigir > 0) ? 'disabled' : '' ?>>
                        <i class="fa-solid fa-check-double"></i>
                        <?php
                            if ($fserv_por_corrigir > 0) echo 'Acerto bloqueado (FSERV por corrigir)';
                            elseif (!$tem_pendentes) echo 'Não existem registos para acertar';
                            else echo 'Efetuar acerto das contas';
                        ?>
                    </button>
                </div>
            </form>
        </div>

        <!-- ZONA 2: HISTÓRICO -->
        <div class="card">
            <h2>Histórico de acertos</h2>

            <table class="tabela-hist">
                <thead>
                    <tr>
                        <th style="width: 7%;">Nº</th>
                        <th style="width: 12%;">Data</th>
                        <th style="width: 41%;">Parcelas</th>
                        <th style="width: 20%;">Total / Abertura</th>
                        <th style="width: 20%;">Ações</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($historico)): ?>
                        <tr><td colspan="5" style="padding: 20px; color: #7f8c8d;">Nenhum acerto efetuado.</td></tr>
                    <?php else:
                        // O primeiro elemento é o último acerto (ORDER BY numero DESC)
                        $ultimo_acerto_num = (int)$historico[0]['numero'];
                        foreach ($historico as $h):
                            $num_h = (int)$h['numero'];
                            $docs = $docs_por_acerto[$num_h] ?? [];
                    ?>
                        <tr class="linha-hist">
                            <td class="td-num">#<?= $num_h ?></td>
                            <td class="td-data"><?= date('d/m/Y', strtotime($h['data_registo'])) ?></td>
                            <td class="td-parcelas">
                                <div class="parcelas">
                                    <span class="parcela">FSERV<?= isset($h['n_fserv']) && $h['n_fserv'] ? ' (' . (int)$h['n_fserv'] . ')' : '' ?><b class="<?= $h['valor_fserv'] < 0 ? 'neg' : '' ?>"><?= formata($h['valor_fserv'] ?? 0) ?></b></span>
                                    <span class="parcela">Faturas<?= isset($h['n_fat']) && $h['n_fat'] ? ' (' . (int)$h['n_fat'] . ')' : '' ?><b class="<?= $h['valor_fat'] < 0 ? 'neg' : '' ?>"><?= formata($h['valor_fat'] ?? 0) ?></b></span>
                                    <span class="parcela">Abert.+Cli.<?= isset($h['n_fatfav']) && $h['n_fatfav'] ? ' (' . (int)$h['n_fatfav'] . ')' : '' ?><b class="<?= $h['valor_fatfav'] < 0 ? 'neg' : '' ?>"><?= formata($h['valor_fatfav'] ?? 0) ?></b></span>
                                </div>
                            </td>
                            <td class="td-total">
                                <div class="total-hist <?= $h['valor'] < 0 ? 'neg' : '' ?>"><?= formata($h['valor']) ?></div>
                                <div class="sub-hist"><?= $h['abertura_gerada_num'] ? 'Saldo de abertura: registo nº ' . (int)$h['abertura_gerada_num'] : 'Contas a zero (sem abertura)' ?></div>
                            </td>
                            <td class="td-acoes">
                                <div class="acoes-hist">
                                    <button type="button" class="btn-ver" onclick="verDocumentos(<?= $num_h ?>, this)"><i class="fa-solid fa-list"></i> Documentos</button>
                                    <a href="jdias_acerto_pdf.php?id=<?= $num_h ?>" target="_blank" class="btn-ver btn-imprimir" title="Imprimir o acerto detalhado (PDF)"><i class="fa-solid fa-print"></i> Imprimir</a>
                                    <?php if ($num_h === $ultimo_acerto_num): ?>
                                        <form method="POST" style="margin:0;" onsubmit="return confirm('Tem a certeza que deseja REVERTER o Acerto nº <?= $num_h ?>?\nIsto apaga o saldo de abertura gerado e devolve todos os registos envolvidos ao estado Ativo.');">
                                            <input type="hidden" name="action" value="reverter">
                                            <input type="hidden" name="id_reverter" value="<?= $num_h ?>">
                                            <button type="submit" class="btn-reverter"><i class="fa-solid fa-rotate-left"></i> Reverter</button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <tr class="detalhe-acerto" id="detalhe_<?= $num_h ?>" style="display:none;">
                            <td colspan="5">
                                <?php if (empty($docs)): ?>
                                    <span style="color:#95a5a6;">Sem documentos associados.</span>
                                <?php else: ?>
                                    <div class="lista-docs">
                                        <?php foreach (['Folhas de Serviço', 'Faturas', 'Abertura + Faturas Cliente'] as $grupo):
                                            if (empty($docs[$grupo])) continue;
                                            $soma_g = array_sum(array_map(fn($d) => (float)$d['valor'], $docs[$grupo])); ?>
                                            <div>
                                                <h4><?= $grupo ?> (<?= count($docs[$grupo]) ?>) · <?= formata($soma_g) ?></h4>
                                                <table>
                                                    <?php foreach ($docs[$grupo] as $d): ?>
                                                        <tr>
                                                            <td style="width: 70px; color:#95a5a6;"><?= !empty($d['data']) ? date('d/m/Y', strtotime($d['data'])) : '' ?></td>
                                                            <td><b><?= htmlspecialchars($d['doc']) ?></b><?= $d['extra'] !== '' ? ' <span style="color:#95a5a6;">' . htmlspecialchars($d['extra']) . '</span>' : '' ?></td>
                                                            <td class="v <?= (float)$d['valor'] < 0 ? 'neg' : '' ?>"><?= formata($d['valor']) ?></td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                </table>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>

    </div>

    <script>
        function verDocumentos(id, btn) {
            const linha = document.getElementById('detalhe_' + id);
            const aberta = linha.style.display !== 'none';
            linha.style.display = aberta ? 'none' : '';
            btn.innerHTML = aberta ? '<i class="fa-solid fa-list"></i> Documentos' : '<i class="fa-solid fa-xmark"></i> Fechar';
        }
    </script>
    <script>
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
