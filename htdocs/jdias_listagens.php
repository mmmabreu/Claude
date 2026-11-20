<?php
// FORÇAR EXIBIÇÃO DE ERROS BÁSICOS
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

try {
    session_start();
    require_once 'db.php';

    // Validação de sessão
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

    // --- CARREGAR HISTÓRICO DE ACERTOS PARA O DROPDOWN ---
    $acertos_lista = [];
    try {
        $stmt_ac = $pdo->query("SELECT numero, data_registo FROM jdias_acertos ORDER BY numero DESC");
        if ($stmt_ac) {
            $acertos_lista = $stmt_ac->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch(Exception $e) {}

    // --- CARREGAR TODOS OS REGISTOS DAS 3 TABELAS ---
    $registos = [];

    // 1. FSERV
    try {
        $stmt = $pdo->query("SELECT * FROM jdias_fserv");
        $fserv = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach($fserv as $r) {
            $r['modulo'] = 'FSERV';
            $r['tipo_display'] = 'FSERV'; 
            $registos[] = $r;
        }
    } catch(Exception $e) {}

    // 2. FAT
    try {
        $stmt = $pdo->query("SELECT * FROM jdias_fat");
        $fat = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach($fat as $r) {
            $r['modulo'] = 'FAT';
            $r['tipo_display'] = $r['tipo']; 
            $registos[] = $r;
        }
    } catch(Exception $e) {}

    // 3. ACERTOS (FATFAV)
    try {
        $stmt = $pdo->query("SELECT * FROM jdias_fatfav");
        $fatfav = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach($fatfav as $r) {
            $r['modulo'] = 'Acertos';
            $r['tipo_display'] = $r['tipo']; 
            $registos[] = $r;
        }
    } catch(Exception $e) {}

    // --- ORDENAÇÃO: Por TIPO (ASC) e DATA (DESC) ---
    usort($registos, function($a, $b) {
        $tipoA = (string)($a['tipo_display'] ?? '');
        $tipoB = (string)($b['tipo_display'] ?? '');
        
        $cmpTipo = strcmp($tipoA, $tipoB);
        if ($cmpTipo !== 0) return $cmpTipo;
        
        $dataA = (string)($a['data_registo'] ?? '');
        $dataB = (string)($b['data_registo'] ?? '');
        
        $cmpData = strcmp($dataB, $dataA); // DESC
        if ($cmpData !== 0) return $cmpData;
        
        return ($b['numero'] ?? 0) <=> ($a['numero'] ?? 0); // DESC
    });

} catch (Throwable $e) {
    die("Erro Fatal: " . htmlspecialchars($e->getMessage()));
}
?>
<!DOCTYPE html>
<html lang="pt-PT">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Listagens - J. Dias</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { font-family: system-ui, -apple-system, sans-serif; box-sizing: border-box; }
        body { background-color: #f7f9fc; margin: 0; color: #333; font-size: 13px; padding-bottom: 100px; }
        
        /* Alterado a cor da linha de topo para o cinzento padrão */
        .topbar { background-color: #fff; padding: 15px 40px; box-shadow: 0 4px 20px rgba(0,0,0,0.05); display: flex; justify-content: space-between; align-items: center; position: sticky; top: 0; z-index: 100; border-bottom: 3px solid #576574;}
        .topbar-left .logo { height: 40px; }
        
        .topbar-right { display: flex; align-items: center; gap: 30px; }
        .btn-back { color: #576574; text-decoration: none; font-weight: 500; display: flex; align-items: center; gap: 6px; font-size: 15px; transition: 0.2s;}
        .btn-back:hover { color: #34495e; } 
        
        .user-menu { position: relative; display: inline-block;}
        .user-btn { background: none; border: none; font-size: 15px; font-weight: 600; color: #2c3e50; cursor: pointer; display: flex; align-items: center; gap: 8px; }
        .user-avatar { background: #e74c3c; color: white; width: 28px; height: 28px; border-radius: 50%; display: flex; justify-content: center; align-items: center; font-size: 14px; border: 2px solid #fadbd8; }
        
        .container { max-width: 1700px; margin: 30px auto; padding: 0 20px; }
        .page-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
        /* Alterado a cor do icone do titulo */
        .page-title { margin: 0; color: #2c3e50; font-size: 24px; font-weight: 800; display: flex; align-items: center; gap: 10px; }
        .page-title i { color: #576574; }

        .filter-bar { background: #fff; padding: 15px 25px; border-radius: 12px; box-shadow: 0 4px 15px rgba(0,0,0,0.04); border: 1px solid #e1e5ea; margin-bottom: 20px; display: flex; align-items: center; gap: 15px; }
        .filter-bar label { font-weight: bold; color: #576574; font-size: 14px; }
        .filter-bar select { padding: 10px 15px; border: 1px solid #dcdde1; border-radius: 8px; font-size: 14px; font-weight: 600; color: #2c3e50; cursor: pointer; outline: none; transition: border 0.2s; }
        /* Alterado o focus da select */
        .filter-bar select:focus { border-color: #576574; }

        .card { background: #fff; border-radius: 12px; box-shadow: 0 4px 15px rgba(0,0,0,0.04); border: 1px solid #e1e5ea; overflow: hidden; margin-bottom: 30px; padding: 25px; overflow-x: auto;}
        
        .table-list { width: 100%; border-collapse: collapse; min-width: 1400px; }
        .table-list th { background: #f8f9fa; padding: 12px 10px; border: 1px solid #e1e5ea; font-size: 11px; color: #576574; text-transform: uppercase; text-align: center; position: sticky; top: 0; z-index: 10; }
        .table-list td { border: 1px solid #f1f2f6; padding: 10px; text-align: center; vertical-align: middle; color: #34495e; font-size: 13px;}
        .table-list tr:hover td { background: #fcfcfc; }
        
        .badge { padding: 4px 8px; border-radius: 4px; font-weight: bold; font-size: 11px; }
        .badge-fserv { background: #fdf2e9; color: #e67e22; border: 1px solid #f5cba7; }
        .badge-fat { background: #ebf5fb; color: #2980b9; border: 1px solid #aed6f1; }
        .badge-ncre { background: #fdeded; color: #c0392b; border: 1px solid #f5b7b1; }
        .badge-abertura { background: #f5eef8; color: #8e44ad; border: 1px solid #d7bde2; } /* Abertura mantem a cor de origem */
        .badge-faturacli { background: #eaeded; color: #c0392b; border: 1px solid #f5b7b1; }

        .text-left { text-align: left !important; }
        .text-right { text-align: right !important; }
        .fw-bold { font-weight: bold; }
        .text-muted { color: #95a5a6; }

        .empty-msg { display: none; text-align: center; padding: 40px; color: #95a5a6; font-size: 15px; font-weight: bold; }

        /* Barra flutuante igual às outras páginas */
        .floating-action-bar { position: fixed; bottom: 20px; right: 20px; background: #fff; padding: 15px 25px; border-radius: 12px; box-shadow: 0 5px 20px rgba(0,0,0,0.15); display: flex; align-items: center; gap: 15px; z-index: 1000; border: 1px solid #e1e5ea; justify-content: space-between; }
        .btn-save { background: #576574; color: #fff; padding: 12px 25px; border-radius: 8px; border: none; font-weight: 700; cursor: pointer; transition: background 0.2s; font-size: 14px; display: flex; align-items: center; gap: 8px; }
        .btn-save:hover { background: #34495e; }

        @media (max-width: 768px) {
            .topbar { padding: 15px 20px; }
            .btn-back span { display: none; }
            .floating-action-bar { flex-direction: column; right: 10px; left: 10px; gap: 10px; }
        }
    </style>
</head>
<body>

    <header class="topbar">
        <div class="topbar-left"><a href="index.php"><img src="mab.png" alt="MAB" class="logo"></a></div>
        <div class="topbar-right">
            <a href="jdias.php" class="btn-back" title="Voltar ao Módulo J. Dias"><i class="fa-solid fa-arrow-left"></i> <span>Voltar ao Módulo J. Dias</span></a>
            <div class="user-menu">
                <button class="user-btn">
                    <div class="user-avatar"><i class="fa-solid fa-user"></i></div>
                    <?= htmlspecialchars($primeiro_nome) ?> 
                </button>
            </div>
        </div>
    </header>

    <div class="container">
        
        <div class="page-header">
            <h1 class="page-title"><i class="fa-solid fa-list-check"></i> Listagem Geral de Documentos</h1>
        </div>

        <div class="filter-bar">
            <label><i class="fa-solid fa-filter"></i> Mostrar Documentos:</label>
            <select id="filtroPrincipal" onchange="aplicarFiltros()">
                <option value="Ativo" selected>Ativos (Pendentes)</option>
                <option value="Inativo">Inativos</option>
                <option value="Acertado">Por Acerto</option>
            </select>
            
            <select id="filtroAcerto" style="display:none;" onchange="aplicarFiltros()">
                <option value="">-- Escolha um Acerto --</option>
                <?php foreach($acertos_lista as $ac): ?>
                    <option value="<?= $ac['numero'] ?>">Acerto Nº <?= $ac['numero'] ?> (<?= date('d/m/Y', strtotime($ac['data_registo'])) ?>)</option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="card">
            <table class="table-list" id="tabelaDados">
                <thead>
                    <tr>
                        <th style="width: 7%;">Módulo</th>
                        <th style="width: 8%;">Tipo</th>
                        <th style="width: 8%;">Número</th>
                        <th style="width: 8%;">Data</th>
                        <th style="width: 5%;">Secção</th>
                        <th style="width: 6%;">PDCT</th>
                        <th style="width: 12%;">Nome Cliente</th>
                        <th style="width: 8%;">FSERV Ant.</th>
                        <th style="width: 8%;">V. Base</th>
                        <th style="width: 8%;">V. Corrig.</th>
                        <th style="width: 5%;">V. Certo</th>
                        <th style="width: 7%;">Estado</th>
                        <th style="width: 10%;">Observações</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if(empty($registos)): ?>
                        <tr><td colspan="13" class="text-muted" style="padding: 30px;">Nenhum documento encontrado.</td></tr>
                    <?php else: ?>
                        <?php foreach($registos as $row): 
                            $badgeClass = '';
                            $t = $row['tipo_display'];
                            if($t === 'FSERV') $badgeClass = 'badge-fserv';
                            elseif($t === 'FAT') $badgeClass = 'badge-fat';
                            elseif($t === 'NCRE') $badgeClass = 'badge-ncre';
                            elseif($t === 'Abertura') $badgeClass = 'badge-abertura';
                            elseif($t === 'Fatura') $badgeClass = 'badge-faturacli';

                            $num = htmlspecialchars($row['numero'] ?? '-');
                            $data = !empty($row['data_registo']) ? date('d/m/Y', strtotime($row['data_registo'])) : '-';
                            $seccao = isset($row['seccao']) ? htmlspecialchars($row['seccao']) : '-';
                            $pdct = isset($row['pdct']) ? htmlspecialchars($row['pdct']) : '-';
                            $cliente = !empty($row['nome_cliente']) ? htmlspecialchars($row['nome_cliente']) : '-';
                            $fserv_ant = !empty($row['fserv_anterior']) ? htmlspecialchars($row['fserv_anterior']) : '-';
                            
                            $vBase = number_format((float)($row['valor'] ?? 0), 2, ',', '.') . ' €';
                            $vCorrigido = isset($row['valor_corrigido']) ? number_format((float)$row['valor_corrigido'], 2, ',', '.') . ' €' : '-';
                            
                            $vCerto = $row['valor_certo'] ?? '-';
                            $estado = htmlspecialchars($row['estado'] ?? '-');
                            $obs = htmlspecialchars($row['observacoes'] ?? '');
                            $id_acerto = $row['id_acerto'] ?? '';
                        ?>
                        <tr class="linha-dados" data-estado="<?= $estado ?>" data-acerto="<?= $id_acerto ?>">
                            <td class="fw-bold text-muted"><?= htmlspecialchars($row['modulo']) ?></td>
                            <td><span class="badge <?= $badgeClass ?>"><?= htmlspecialchars($t) ?></span></td>
                            <td class="fw-bold"><?= $num ?></td>
                            <td><?= $data ?></td>
                            <td><?= $seccao ?></td>
                            <td><?= $pdct ?></td>
                            <td class="text-left"><?= $cliente ?></td>
                            <td><?= $fserv_ant ?></td>
                            <td class="text-right fw-bold"><?= $vBase ?></td>
                            <td class="text-right text-muted"><?= $vCorrigido ?></td>
                            <td><?= $vCerto ?></td>
                            <td><?= $estado ?></td>
                            <td class="text-left text-muted" style="font-size: 11px;"><?= mb_strimwidth($obs, 0, 40, '...') ?></td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
            <div id="msgVazia" class="empty-msg"><i class="fa-solid fa-folder-open" style="font-size: 24px; display:block; margin-bottom: 10px;"></i> Não existem documentos para este filtro.</div>
        </div>

        <!-- Nova Barra Flutuante com o botão de Imprimir igual às outras -->
        <div class="floating-action-bar">
            <div style="display: flex; gap: 20px; margin-right: auto;">
                <div style="text-align: right;">
                    <span style="font-size: 10px; font-weight: bold; color: #576574; display: block; text-transform: uppercase;">Ação</span>
                    <span style="font-size: 14px; font-weight: 800; color: #2c3e50;">Exportar Vista Atual</span>
                </div>
            </div>
            <button type="button" class="btn-save" onclick="imprimirListagem()"><i class="fa-solid fa-print"></i> IMPRIMIR PDF</button>
        </div>

    </div>

    <script>
        function aplicarFiltros() {
            const filtroP = document.getElementById('filtroPrincipal').value;
            const filtroA = document.getElementById('filtroAcerto');
            const linhas = document.querySelectorAll('.linha-dados');
            const msgVazia = document.getElementById('msgVazia');
            const tabela = document.getElementById('tabelaDados');
            
            if (filtroP === 'Acertado') {
                filtroA.style.display = 'inline-block';
            } else {
                filtroA.style.display = 'none';
            }

            let linhasVisiveis = 0;
            const numAcerto = filtroA.value;

            linhas.forEach(row => {
                const estado = row.getAttribute('data-estado');
                const acerto = row.getAttribute('data-acerto');
                
                if (filtroP === 'Ativo' && estado === 'Ativo') {
                    row.style.display = ''; linhasVisiveis++;
                } 
                else if (filtroP === 'Inativo' && estado === 'Inativo') {
                    row.style.display = ''; linhasVisiveis++;
                } 
                else if (filtroP === 'Acertado') {
                    if (numAcerto !== '' && acerto === numAcerto) {
                        row.style.display = ''; linhasVisiveis++;
                    } else {
                        row.style.display = 'none';
                    }
                } 
                else {
                    row.style.display = 'none';
                }
            });

            if (linhasVisiveis === 0) {
                tabela.style.display = 'none'; msgVazia.style.display = 'block';
            } else {
                tabela.style.display = 'table'; msgVazia.style.display = 'none';
            }
        }

        function imprimirListagem() {
            const filtroP = document.getElementById('filtroPrincipal').value;
            const filtroA = document.getElementById('filtroAcerto').value;
            
            if (filtroP === 'Acertado' && filtroA === '') {
                alert("Por favor, escolha qual o Acerto que deseja imprimir.");
                return;
            }
            
            window.open(`jdias_listagens_pdf.php?tipo=${filtroP}&acerto=${filtroA}`, '_blank');
        }

        window.addEventListener('DOMContentLoaded', aplicarFiltros);
    </script>
</body>
</html>