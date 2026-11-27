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

// =====================================================================
// IMPRESSÃO DO AUTO DE MEDIÇÃO
// Quando o auto tem trabalhos adjudicados E suplementares, saem 2 documentos separados:
//   auto_imprimir.php?id=12&parte=adj → Auto de Medição — Trabalhos Adjudicados
//   auto_imprimir.php?id=12&parte=sup → Auto de Medição — Trabalhos Suplementares
//   auto_imprimir.php?id=12&parte=todos → um só documento com as duas tabelas e o resumo (como era antes)
//   auto_imprimir.php?id=12           → mostra os dois (um em cada folha), com um botão para imprimir cada um
// Se só houver um dos tipos, sai só esse documento (como antes).
// =====================================================================
session_start();
require_once 'db.php';

// REGRA DE OURO 1: Validação de sessão
if (!isset($_SESSION['user_id'])) { 
    header("Location: index.php"); 
    exit; 
}

// REGRA DE OURO 2: Acesso restrito pela matriz de permissões (o mesmo acesso da ficha do auto)
$perfil_user = $_SESSION['perfil'] ?? '';
$stmt_perm = $pdo->prepare("SELECT modulo FROM permissoes WHERE grupo = :perfil AND pode_aceder = 1");
$stmt_perm->execute(['perfil' => $perfil_user]);
if (!in_array($perfil_user, ['admin', 'administrativo']) && !in_array('obras', $stmt_perm->fetchAll(PDO::FETCH_COLUMN))) {
    header("Location: index.php?erro=sem_permissao");
    exit;
}

// REGRA DE OURO 5: ID validado como número inteiro
$id_auto = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$id_auto) {
    die("Auto não especificado.");
}

// Buscar o Cabeçalho do Auto
$stmt_auto = $pdo->prepare("
    SELECT a.*, o.nome as obra_nome, o.referencia_obra, o.morada as obra_morada, 
           c.nome as cliente_nome, c.nif as cliente_nif
    FROM autos a
    JOIN obras o ON a.id_obra = o.id
    JOIN clientes c ON o.id_cliente = c.id
    WHERE a.id = ?
");
$stmt_auto->execute([$id_auto]);
$auto = $stmt_auto->fetch();

if (!$auto) {
    die("Auto de medição não encontrado.");
}

// Tratar a versão do auto (se for antigo e não tiver versão, assume 1)
$versao_auto = $auto['versao_auto'] ?? 1;

// Buscar os Itens do Auto
$stmt_itens = $pdo->prepare("SELECT * FROM auto_itens WHERE id_auto = ? ORDER BY id ASC");
$stmt_itens->execute([$id_auto]);
$itens = $stmt_itens->fetchAll();

// Separar itens em Adjudicados e Suplementares
$itens_adj = [];
$itens_sup = [];
foreach ($itens as $it) {
    if ($it['tipo'] === 'Suplementar') {
        $itens_sup[] = $it;
    } else {
        $itens_adj[] = $it;
    }
}

// Documentos a mostrar
$parte = $_GET['parte'] ?? '';
$docs = [];
if ($parte === 'adj' && $itens_adj) $docs = ['adj'];
elseif ($parte === 'sup' && $itens_sup) $docs = ['sup'];
elseif ($parte === 'todos') $docs = ['todos'];
else {
    if ($itens_adj) $docs[] = 'adj';
    if ($itens_sup) $docs[] = 'sup';
    if (!$docs) $docs = ['adj'];          // auto sem linhas: documento vazio, como antes
}
$dois_tipos = ($itens_adj && $itens_sup);
$ver_os_dois = (count($docs) === 2);
$TITULOS = ['adj' => 'Trabalhos Adjudicados', 'sup' => 'Trabalhos Suplementares'];

// Título da página = nome do PDF ao "Guardar como PDF"
$nome_doc = 'Auto ' . $auto['numero_auto'] . ' (v' . $versao_auto . ')'
          . (count($docs) === 1 && $dois_tipos && isset($TITULOS[$docs[0]]) ? ' - ' . $TITULOS[$docs[0]] : '')
          . ' - ' . $auto['obra_nome'];

$fmt = fn($v, $d = 2) => number_format((float)$v, $d, ',', '.');

// Linhas da tabela de um tipo (adjudicados ou suplementares). Devolve os totais.
function linhasAuto($lista, $suplementar, $fmt) {
    $t = ['adj' => 0, 'mes' => 0, 'acum' => 0, 'saldo' => 0];
    foreach ($lista as $it) {
        $q_adj = floatval($it['qtd_adjudicada']);
        $p_un = floatval($it['preco_unitario']);
        $t_adj = floatval($it['total_adjudicado']);
        $q_mes = floatval($it['qtd_mes']);
        $q_ant = floatval($it['qtd_anterior']);
        $q_acum = floatval($it['qtd_acumulada']);
        $t_acum = floatval($it['total_acumulado']);

        // Total Adj. acertado à mão (≠ Qtd × P.Unit): ao completar a linha o mês absorve os cêntimos
        $acerto = totalFoiAcertado($t_adj, $q_adj, $p_un);
        if ($acerto && $q_adj > 0 && abs($q_acum - $q_adj) < 0.0005) {
            $t_mes = (abs($q_ant - $q_adj) < 0.0005) ? 0.0 : round($t_adj - totLinha($q_ant, $p_un), 2);
        } else {
            $t_mes = totLinha($q_mes, $p_un);
        }
        // Acerto de cêntimos do Saldo € feito neste auto
        $t_mes = round($t_mes + (float)($it['acerto_acum'] ?? 0) - (float)($it['acerto_ant'] ?? 0), 2);

        $tem_qtd = $q_adj > 0;
        $saldo_qtd = $q_adj - $q_acum;
        $saldo_valor = round($t_adj - $t_acum, 2);   // Saldo € = Total Adj. − Acumulado €

        $t['adj'] += $t_adj;
        $t['mes'] += $t_mes;
        $t['acum'] += $t_acum;
        if (!$suplementar || $tem_qtd) $t['saldo'] += $saldo_valor;

        echo '<tr>';
        echo '<td>' . htmlspecialchars($it['artigo']) . '</td>';
        echo '<td class="text-left">' . nl2br(htmlspecialchars($it['designacao'])) . '</td>';
        echo '<td>' . htmlspecialchars($it['unidade']) . '</td>';
        if ($suplementar) {
            echo '<td>' . ($tem_qtd ? $fmt($q_adj) : '') . '</td>';
            echo '<td>' . ($p_un != 0 ? $fmt($p_un, 3) . ' €' : '') . '</td>';
            echo '<td>' . (($tem_qtd && $t_adj != 0) ? $fmt($t_adj) . ' €' : '') . '</td>';
        } elseif ($tem_qtd) {
            echo '<td>' . ($q_adj != 0 ? $fmt($q_adj) : '') . '</td>';
            echo '<td>' . ($p_un != 0 ? $fmt($p_un, 3) . ' €' : '') . '</td>';
            echo '<td>' . ($t_adj != 0 ? $fmt($t_adj) . ' €' : '') . '</td>';
        } else {
            echo '<td></td><td></td><td></td>';
        }
        echo '<td>' . ($q_mes != 0 ? $fmt($q_mes) : '') . '</td>';
        echo '<td>' . ($t_mes != 0 ? $fmt($t_mes) . ' €' : '') . '</td>';
        echo '<td>' . ($q_ant != 0 ? $fmt($q_ant) : '') . '</td>';
        echo '<td>' . ($q_acum != 0 ? $fmt($q_acum) : '') . '</td>';
        echo '<td>' . ($t_acum != 0 ? $fmt($t_acum) . ' €' : '') . '</td>';
        if ($tem_qtd) {
            echo '<td>' . $fmt($saldo_qtd) . '</td>';
            echo '<td>' . $fmt($saldo_valor) . ' €</td>';
        } else {
            echo '<td></td><td></td>';
        }
        echo "</tr>\n";
    }
    return $t;
}
?>
<!DOCTYPE html>
<html lang="pt-PT">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($nome_doc) ?></title>
    <?php /* REGRA DE OURO 8: ícone MAB no separador */ ?>
    <link rel="icon" type="image/png" href="/mab.png">
    <link rel="apple-touch-icon" href="/mab.png">
    <!-- Folha A4 horizontal, definida antes de tudo (não depende de nenhum ficheiro externo) -->
    <style>
        @page {
            size: A4 landscape;
            margin: 10mm 10mm 16mm 10mm;  /* margens reais: valem em TODAS as folhas */
            /* Caixas de margem vazias: o browser não imprime data / endereço da página por cima do auto */
            @top-left { content: ""; } @top-center { content: ""; } @top-right { content: ""; }
            @bottom-left { content: ""; } @bottom-right { content: ""; }
        }
    </style>
    <!-- Ícones só para os botões do ecrã: media="screen" para não interferirem com a impressão -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" media="screen">
    <style>
        body {
            font-family: Arial, sans-serif;
            font-size: 10px; 
            color: #333;
            margin: 0;
            padding: 20px;
            background: #f7f9fc;
            -webkit-print-color-adjust: exact !important; 
            print-color-adjust: exact !important; 
        }
        
        .print-container {
            background: #fff;
            max-width: 297mm; 
            margin: 0 auto;
            padding: 20px;
            box-shadow: 0 0 10px rgba(0,0,0,0.1);
            position: relative;
            min-height: 100%;
        }

        /* Estilos da Tabela Wrapper (Para Cabeçalho repetir e Rodapé reservar espaço) */
        table.wrapper-table { width: 100%; border: none; margin: 0; border-collapse: collapse; }
        table.wrapper-table > thead > tr > td,
        table.wrapper-table > tbody > tr > td, 
        table.wrapper-table > tfoot > tr > td { border: none !important; padding: 0; background: transparent; text-align: left; }
        .wrapper-table thead { display: table-header-group; }
        .wrapper-table tfoot { display: table-footer-group; }

        /* Rodapé Fixo */
        .page-footer-content {
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 9px; 
            color: #576574;
            border-top: 1px solid #dcdde1;
            padding-top: 10px;
            margin-top: 30px;
        }
        
        .footer-left { flex: 1; } 
        .footer-center { flex: 3; text-align: center; }
        .footer-right { flex: 1; text-align: right; }
        
        /* Espaçador invisível dentro da tabela para os itens não atropelarem o rodapé na última folha */
        .footer-space { height: 40px; }

        .header {
            display: flex;
            justify-content: space-between;
            border-bottom: 2px solid #b32a2a;
            padding-bottom: 12px;
            margin-bottom: 15px;
        }
        .logo { height: 45px; }
        .doc-title { text-align: right; }
        .doc-title h1 { color: #b32a2a; margin: 0; font-size: 19px; text-transform: uppercase; }
        .doc-title p { margin: 5px 0 0 0; font-size: 13px; font-weight: bold; color: #576574; }
        
        .info-grid {
            display: flex;
            justify-content: space-between;
            margin-bottom: 15px;
            border: 1px solid #e1e5ea;
            border-radius: 8px;
            padding: 12px;
            background: #f9f9f9 !important;
        }
        .info-col h3 { margin: 0 0 5px 0; font-size: 10px; color: #b32a2a; text-transform: uppercase; }
        .info-col p { margin: 3px 0; font-size: 11px; }

        /* Estilos genéricos das tabelas de dados */
        table.data-table { width: 100%; border-collapse: collapse; margin-bottom: 15px; table-layout: fixed; }
        
        table.data-table th { background: #f1f2f6 !important; border: 1px solid #dcdde1; padding: 5px; text-align: center; font-size: 9px; color: #576574; text-transform: uppercase; }
        table.data-table td { border: 1px solid #e1e5ea; padding: 4px; text-align: center; vertical-align: middle; font-size: 10px; }
        
        .data-table tbody td { font-size: 8px; }

        .col-artigo { width: 8%; }
        .col-un { width: 3%; }
        .col-desig { width: 18%; } 
        .col-num { width: 7.1%; } 

        .text-left { text-align: left !important; }
        .text-right { text-align: right !important; }
        
        .row-totais th, .row-totais td { background: #f1f2f6 !important; font-weight: bold; color: #2c3e50; font-size: 10px; }
        
        .section-title { font-size: 13px; font-weight: bold; color: #b32a2a; margin-bottom: 8px; border-bottom: 1px solid #e1e5ea; padding-bottom: 4px; text-transform: uppercase; }

        .resumo { width: 380px; float: right; border: 1px solid #dcdde1; margin-top: 10px; border-radius: 6px; overflow: hidden; }
        .resumo th { text-align: left; padding: 8px; background: #f9f9f9 !important; font-size: 11px; }
        .resumo td { text-align: right; padding: 8px; font-weight: bold; font-size: 12px; }
        .resumo .total-final th, .resumo .total-final td { background: #f1f2f6 !important; color: #b32a2a; font-size: 14px; border-top: 2px solid #b32a2a; }

        .clearfix::after { content: ""; clear: both; display: table; }

        /* Estilos e botões apenas para visualização no Ecrã */
        .actions { margin-bottom: 20px; text-align: right; max-width: 297mm; margin-left: auto; margin-right: auto; }
        .btn { background: #b32a2a; color: #fff; border: none; padding: 10px 20px; font-weight: bold; cursor: pointer; border-radius: 5px; text-decoration: none; font-size: 12px; display: inline-block; }
        .btn-secundary { background: #576574; }
        .actions { display: flex; justify-content: flex-end; align-items: center; gap: 10px; flex-wrap: wrap; }
        .btn-voltar { width: 40px; height: 40px; border-radius: 50%; background: #fff; border: 1px solid #e8ecf1; color: #576574; display: inline-flex; align-items: center; justify-content: center; text-decoration: none; font-size: 16px; margin-right: auto; box-shadow: 0 2px 6px rgba(0,0,0,0.04); }
        .btn-voltar:hover { background: #eef2f6; color: #34495e; }
        .btn-out { background: #fff; color: #b32a2a; border: 1px solid #b32a2a; }
        .btn i { margin-right: 6px; }
        .aviso-dois { max-width: 297mm; margin: 0 auto 15px; background: #fff8e1; color: #8a6d00; border: 1px solid #ffe082; border-radius: 8px; padding: 10px 14px; font-size: 12px; font-weight: 600; box-sizing: border-box; }
        .print-container + .print-container { margin-top: 30px; }
        .doc-tipo { display: inline-block; margin-top: 4px; font-size: 11px; font-weight: bold; color: #fff; background: #b32a2a; padding: 3px 10px; border-radius: 4px; text-transform: uppercase; }
        .doc-tipo.sup { background: #576574; }
        @media (max-width: 700px) { body { padding: 10px; } .actions .btn { flex: 1 1 100%; text-align: center; } }

        /* OTIMIZAÇÕES DE IMPRESSÃO (A4 Horizontal) */
        @media print {
            body { 
                background: #fff !important; 
                padding: 0; 
            }
            * {
                -webkit-print-color-adjust: exact !important; 
                print-color-adjust: exact !important; 
            }
            .print-container { box-shadow: none; max-width: none; padding: 0; margin: 0; }
            .print-container + .print-container { margin-top: 0; page-break-before: always; break-before: page; }
            .no-print { display: none !important; }
            .info-grid { border: none; padding: 0; background: none !important; }
            
            table.wrapper-table > thead > tr > td { padding-top: 0 !important; }
            table.wrapper-table > tfoot > tr > td { padding-bottom: 0 !important; }
            
            thead { display: table-header-group; }
            .data-table tfoot { display: table-row-group; } 
            
            /* Só as linhas de artigos não se partem entre folhas. A linha "invisível" que contém a tabela inteira
               TEM de poder partir-se: se não, o browser empurra a tabela toda para a folha seguinte e a primeira
               folha fica só com o cabeçalho. */
            .data-table tr { page-break-inside: avoid; break-inside: avoid; }
            table.wrapper-table > tbody > tr, table.wrapper-table > tbody > tr > td { page-break-inside: auto; break-inside: auto; }
            .data-table thead { display: table-header-group; }

            /* Fixa o rodapé mais abaixo no A4 na impressão */
            .page-footer-content {
                position: fixed;
                bottom: 0;
                left: 0;
                right: 0;
                margin-top: 0;
                background: #fff !important;
            }
        }
    </style>
</head>
<body>

    <div class="actions no-print">
        <a href="autos.php" class="btn-voltar" title="Voltar" aria-label="Voltar"><i class="fa-solid fa-arrow-left"></i></a>
        <?php if ($dois_tipos): ?>
            <a href="auto_imprimir.php?id=<?= (int)$id_auto ?>&amp;parte=adj" class="btn<?= $docs === ['adj'] ? '' : ' btn-out' ?>"><i class="fa-solid fa-print"></i> Imprimir Auto Adjudicado</a>
            <a href="auto_imprimir.php?id=<?= (int)$id_auto ?>&amp;parte=sup" class="btn<?= $docs === ['sup'] ? '' : ' btn-out' ?>"><i class="fa-solid fa-print"></i> Imprimir Auto Suplementar</a>
            <a href="auto_imprimir.php?id=<?= (int)$id_auto ?>&amp;parte=todos" class="btn<?= $docs === ['todos'] ? '' : ' btn-out' ?>"><i class="fa-solid fa-print"></i> Imprimir Auto Completo</a>
            <?php if (!$ver_os_dois): ?><a href="auto_imprimir.php?id=<?= (int)$id_auto ?>" class="btn btn-secundary"><i class="fa-solid fa-copy"></i> Ver os dois</a><?php endif; ?>
        <?php endif; ?>
        <?php if (!$ver_os_dois): ?><button onclick="window.print()" class="btn"><i class="fa-solid fa-file-pdf"></i> Imprimir / Guardar PDF</button><?php endif; ?>
    </div>
    <?php if ($ver_os_dois): ?>
        <div class="aviso-dois no-print"><i class="fa-solid fa-circle-info"></i> Este auto tem trabalhos adjudicados e suplementares. Imprima cada um em separado, ou o <strong>Auto Completo</strong> (as duas tabelas num só documento, como antes).</div>
    <?php endif; ?>

    <?php foreach ($docs as $doc_tipo):
        // Secções (tabelas) deste documento: "todos" = adjudicados + suplementares no mesmo documento
        $secoes = ($doc_tipo === 'todos') ? array_values(array_filter(['adj' => $itens_adj ? 'adj' : null, 'sup' => $itens_sup ? 'sup' : null])) : [$doc_tipo];
        $completo = ($doc_tipo === 'todos' || !$dois_tipos);
        $sup = ($doc_tipo === 'sup');
        $tots = ['adj' => ['mes' => 0], 'sup' => ['mes' => 0]];
    ?>
    <div class="print-container">
        
        <table class="wrapper-table">
            <thead>
                <tr>
                    <td>
                        <div class="header">
                            <div>
                                <img src="mab.png" alt="MAB" class="logo">
                            </div>
                            <div class="doc-title">
                                <h1>Auto de Medição</h1>
                                <p>Nº <?= htmlspecialchars($auto['numero_auto']) ?> (v<?= $versao_auto ?>)<?= !empty($auto['mes_referencia']) ? ' - ' . htmlspecialchars($auto['mes_referencia']) : '' ?></p>
                                <?php if ($dois_tipos && !$completo): ?><span class="doc-tipo<?= $sup ? ' sup' : '' ?>"><?= $TITULOS[$doc_tipo] ?></span><?php endif; ?>
                            </div>
                        </div>

                        <div class="info-grid">
                            <div class="info-col">
                                <h3>Cliente</h3>
                                <p><strong><?= htmlspecialchars($auto['cliente_nome']) ?></strong></p>
                                <p>NIF: <?= htmlspecialchars($auto['cliente_nif']) ?></p>
                            </div>
                            <div class="info-col">
                                <h3>Obra</h3>
                                <p><strong><?= htmlspecialchars($auto['obra_nome']) ?></strong></p>
                                <p>Referência da Obra: <?= htmlspecialchars($auto['referencia_obra'] ?: 'N/D') ?></p>
                                <p><?= htmlspecialchars($auto['obra_morada']) ?></p>
                            </div>
                            <div class="info-col">
                                <h3>Detalhes</h3>
                                <p><strong>Data:</strong> <?= date('d/m/Y', strtotime($auto['data_auto'])) ?></p>
                                <p><strong>Referência:</strong> <?= htmlspecialchars($auto['mes_referencia'] ?: 'N/D') ?></p>
                            </div>
                        </div>
                    </td>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>
                        <?php foreach ($secoes as $n_sec => $sec):
                            $sup = ($sec === 'sup');
                            $lista = $sup ? $itens_sup : $itens_adj;
                            if (!$lista) continue; ?>
                        <div class="section-title"<?= $n_sec > 0 ? ' style="margin-top:20px;"' : '' ?>><?= $TITULOS[$sec] ?></div>
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th rowspan="2" class="col-artigo">Artigo</th>
                                    <th rowspan="2" class="col-desig text-left">Descrição</th>
                                    <th rowspan="2" class="col-un">Un.</th>
                                    <th colspan="3"><?= $sup ? 'Suplementares' : 'Adjudicado' ?></th>
                                    <th colspan="2">Mês</th>
                                    <th colspan="3">Acumulado</th>
                                    <th colspan="2">Saldo</th>
                                </tr>
                                <tr>
                                    <th class="col-num">Qtd</th>
                                    <th class="col-num">P. Unit</th>
                                    <th class="col-num">Total</th>
                                    <th class="col-num">Qtd</th>
                                    <th class="col-num">Total</th>
                                    <th class="col-num">Qtd Ant.</th>
                                    <th class="col-num">Qtd Total</th>
                                    <th class="col-num">Total €</th>
                                    <th class="col-num">Qtd</th>
                                    <th class="col-num">Valor €</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php $tot = linhasAuto($lista, $sup, $fmt); $tots[$sec] = $tot; ?>
                            </tbody>
                            <tfoot>
                                <tr class="row-totais">
                                    <td colspan="3" class="text-right">TOTAIS</td>
                                    <td colspan="2"></td>
                                    <td><?= $fmt($tot['adj']) ?> €</td>
                                    <td></td>
                                    <td><?= $fmt($tot['mes']) ?> €</td>
                                    <td colspan="2"></td>
                                    <td><?= $fmt($tot['acum']) ?> €</td>
                                    <td></td>
                                    <td><?= $fmt($tot['saldo']) ?> €</td>
                                </tr>
                            </tfoot>
                        </table>
                        <?php endforeach; ?>

                        <div class="clearfix">
                            <table class="resumo">
                                <?php if (!$completo): ?>
                                <tr class="total-final">
                                    <th>TOTAL DO AUTO (<?= $sup ? 'SUPLEMENTARES' : 'ADJUDICADOS' ?>)</th>
                                    <td><?= $fmt($tots[$doc_tipo]['mes']) ?> €</td>
                                </tr>
                                <?php else: ?>
                                <tr>
                                    <th>Total Adjudicado (Auto)</th>
                                    <td><?= $fmt($tots['adj']['mes']) ?> €</td>
                                </tr>
                                <?php if ($itens_sup): ?>
                                <tr>
                                    <th>Total Suplementar (Auto)</th>
                                    <td><?= $fmt($tots['sup']['mes']) ?> €</td>
                                </tr>
                                <?php endif; ?>
                                <tr class="total-final">
                                    <th>TOTAL DO AUTO</th>
                                    <td><?= $fmt($auto['valor_total_mes']) ?> €</td>
                                </tr>
                                <?php endif; ?>
                            </table>
                        </div>
                    </td>
                </tr>
            </tbody>
            <tfoot>
                <tr>
                    <td><div class="footer-space"></div></td>
                </tr>
            </tfoot>
        </table>

    </div>
    <?php endforeach; ?>
        
    <div class="page-footer-content">
        <div class="footer-left"></div>
        <div class="footer-center">
            <strong>m.Abreu – Instalações Técnicas, Unipessoal, Lda.</strong><br>
            Rua Damião de Góis, 205 | 4700-028 Braga | NIF: 514414120 | geral@mabreu.pt | +351 912 854 950
        </div>
        <div class="footer-right"></div>
    </div>

    <?php if (!$ver_os_dois): ?>
    <script>
        // Um só documento: abre logo a janela de impressão (com os dois, escolhe-se qual imprimir nos botões)
        window.onload = function() {
            setTimeout(function() {
                window.print();
            }, 500);
        }
    </script>
    <?php endif; ?>
</body>
</html>