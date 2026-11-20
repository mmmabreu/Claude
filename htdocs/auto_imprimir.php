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

session_start();
require_once 'db.php';

if (!isset($_SESSION['user_id'])) { 
    header("Location: index.php"); 
    exit; 
}

$id_auto = $_GET['id'] ?? null;
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
?>
<!DOCTYPE html>
<html lang="pt-PT">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Auto de Medição #<?= $auto['numero_auto'] ?> (v<?= $versao_auto ?>) - <?= htmlspecialchars($auto['obra_nome']) ?></title>
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

        /* OTIMIZAÇÕES DE IMPRESSÃO (A4 Horizontal) */
        @media print {
            @page { 
                size: A4 landscape; 
                margin: 0; 
            }
            body { 
                background: #fff !important; 
                padding: 0 10mm; 
            }
            * {
                -webkit-print-color-adjust: exact !important; 
                print-color-adjust: exact !important; 
            }
            .print-container { box-shadow: none; max-width: none; padding: 0; margin: 0; }
            .no-print { display: none !important; }
            .info-grid { border: none; padding: 0; background: none !important; }
            
            table.wrapper-table > thead > tr > td { padding-top: 10mm !important; }
            table.wrapper-table > tfoot > tr > td { padding-bottom: 5mm !important; }
            
            thead { display: table-header-group; }
            .data-table tfoot { display: table-row-group; } 
            
            tr { page-break-inside: avoid; }

            /* Fixa o rodapé mais abaixo no A4 na impressão */
            .page-footer-content {
                position: fixed;
                bottom: 5mm; 
                left: 10mm;
                right: 10mm;
                margin-top: 0;
                background: #fff !important;
            }
        }
    </style>
</head>
<body>

    <div class="actions no-print">
        <a href="autos.php" class="btn btn-secundary" style="margin-right:10px;">Voltar à Listagem</a>
        <button onclick="window.print()" class="btn">Imprimir / Guardar PDF</button>
    </div>

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
                        <?php if (!empty($itens_adj)): ?>
                        <div class="section-title">Trabalhos Adjudicados</div>
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th rowspan="2" class="col-artigo">Artigo</th>
                                    <th rowspan="2" class="col-desig text-left">Descrição</th>
                                    <th rowspan="2" class="col-un">Un.</th>
                                    <th colspan="3">Adjudicado</th>
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
                                <?php 
                                $tot_adj = 0; $tot_mes_adj = 0; $tot_acum_adj = 0; $tot_saldo_adj = 0;
                                foreach ($itens_adj as $it): 
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
                                    
                                    $saldo_qtd = $q_adj - $q_acum;
                                    $saldo_valor = round($t_adj - $t_acum, 2);   // Saldo € = Total Adj. − Acumulado €

                                    $tot_adj += $t_adj;
                                    $tot_mes_adj += $t_mes;
                                    $tot_acum_adj += $t_acum;
                                    $tot_saldo_adj += $saldo_valor;
                                ?>
                                <tr>
                                    <td><?= htmlspecialchars($it['artigo']) ?></td>
                                    <td class="text-left"><?= nl2br(htmlspecialchars($it['designacao'])) ?></td>
                                    <td><?= htmlspecialchars($it['unidade']) ?></td>
                                    
                                    <?php if ($q_adj > 0): ?>
                                        <td><?= $q_adj != 0 ? number_format($q_adj, 2, ',', '.') : '' ?></td>
                                        <td><?= $p_un != 0 ? number_format($p_un, 3, ',', '.') . ' €' : '' ?></td>
                                        <td><?= $t_adj != 0 ? number_format($t_adj, 2, ',', '.') . ' €' : '' ?></td>
                                        <td><?= $q_mes != 0 ? number_format($q_mes, 2, ',', '.') : '' ?></td>
                                        <td><?= $t_mes != 0 ? number_format($t_mes, 2, ',', '.') . ' €' : '' ?></td>
                                        <td><?= $q_ant != 0 ? number_format($q_ant, 2, ',', '.') : '' ?></td>
                                        <td><?= $q_acum != 0 ? number_format($q_acum, 2, ',', '.') : '' ?></td>
                                        <td><?= $t_acum != 0 ? number_format($t_acum, 2, ',', '.') . ' €' : '' ?></td>
                                        <td><?= number_format($saldo_qtd, 2, ',', '.') ?></td>
                                        <td><?= number_format($saldo_valor, 2, ',', '.') ?> €</td>
                                    <?php else: ?>
                                        <td></td><td></td><td></td>
                                        <td><?= $q_mes != 0 ? number_format($q_mes, 2, ',', '.') : '' ?></td>
                                        <td><?= $t_mes != 0 ? number_format($t_mes, 2, ',', '.') . ' €' : '' ?></td>
                                        <td><?= $q_ant != 0 ? number_format($q_ant, 2, ',', '.') : '' ?></td>
                                        <td><?= $q_acum != 0 ? number_format($q_acum, 2, ',', '.') : '' ?></td>
                                        <td><?= $t_acum != 0 ? number_format($t_acum, 2, ',', '.') . ' €' : '' ?></td>
                                        <td></td><td></td>
                                    <?php endif; ?>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot>
                                <tr class="row-totais">
                                    <td colspan="3" class="text-right">TOTAIS</td>
                                    <td colspan="2"></td>
                                    <td><?= number_format($tot_adj, 2, ',', '.') ?> €</td>
                                    <td></td>
                                    <td><?= number_format($tot_mes_adj, 2, ',', '.') ?> €</td>
                                    <td colspan="2"></td>
                                    <td><?= number_format($tot_acum_adj, 2, ',', '.') ?> €</td>
                                    <td></td>
                                    <td><?= number_format($tot_saldo_adj, 2, ',', '.') ?> €</td>
                                </tr>
                            </tfoot>
                        </table>
                        <?php else: $tot_mes_adj = 0; endif; ?>

                        <?php if (!empty($itens_sup)): ?>
                        <div class="section-title" style="margin-top:20px;">Trabalhos Suplementares</div>
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th rowspan="2" class="col-artigo">Artigo</th>
                                    <th rowspan="2" class="col-desig text-left">Descrição</th>
                                    <th rowspan="2" class="col-un">Un.</th>
                                    <th colspan="3">Suplementares</th>
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
                                <?php 
                                $tot_sup = 0; $tot_mes_sup = 0; $tot_acum_sup = 0; $tot_saldo_sup = 0;
                                foreach ($itens_sup as $it): 
                                    $q_adj = floatval($it['qtd_adjudicada']);
                                    $p_un = floatval($it['preco_unitario']);
                                    $t_adj = floatval($it['total_adjudicado']);
                                    $q_mes = floatval($it['qtd_mes']);
                                    $q_ant = floatval($it['qtd_anterior']);
                                    $q_acum = floatval($it['qtd_acumulada']);
                                    $t_acum = floatval($it['total_acumulado']);

                                    // mesmas regras dos adjudicados (Total acertado à mão + acerto de cêntimos do saldo)
                                    $acerto = totalFoiAcertado($t_adj, $q_adj, $p_un);
                                    if ($acerto && $q_adj > 0 && abs($q_acum - $q_adj) < 0.0005) {
                                        $t_mes = (abs($q_ant - $q_adj) < 0.0005) ? 0.0 : round($t_adj - totLinha($q_ant, $p_un), 2);
                                    } else {
                                        $t_mes = totLinha($q_mes, $p_un);
                                    }
                                    $t_mes = round($t_mes + (float)($it['acerto_acum'] ?? 0) - (float)($it['acerto_ant'] ?? 0), 2);

                                    $tem_qtd = $q_adj > 0;   // suplementares com quantidade prevista
                                    $saldo_qtd = $q_adj - $q_acum;
                                    $saldo_valor = round($t_adj - $t_acum, 2);

                                    $tot_sup += $t_adj;
                                    $tot_mes_sup += $t_mes;
                                    $tot_acum_sup += $t_acum;
                                    if ($tem_qtd) $tot_saldo_sup += $saldo_valor;
                                ?>
                                <tr>
                                    <td><?= htmlspecialchars($it['artigo']) ?></td>
                                    <td class="text-left"><?= nl2br(htmlspecialchars($it['designacao'])) ?></td>
                                    <td><?= htmlspecialchars($it['unidade']) ?></td>
                                    <td><?= $tem_qtd ? number_format($q_adj, 2, ',', '.') : '' ?></td>
                                    <td><?= $p_un != 0 ? number_format($p_un, 3, ',', '.') . ' €' : '' ?></td>
                                    <td><?= ($tem_qtd && $t_adj != 0) ? number_format($t_adj, 2, ',', '.') . ' €' : '' ?></td>
                                    <td><?= $q_mes != 0 ? number_format($q_mes, 2, ',', '.') : '' ?></td>
                                    <td><?= $t_mes != 0 ? number_format($t_mes, 2, ',', '.') . ' €' : '' ?></td>
                                    <td><?= $q_ant != 0 ? number_format($q_ant, 2, ',', '.') : '' ?></td>
                                    <td><?= $q_acum != 0 ? number_format($q_acum, 2, ',', '.') : '' ?></td>
                                    <td><?= $t_acum != 0 ? number_format($t_acum, 2, ',', '.') . ' €' : '' ?></td>
                                    <td><?= $tem_qtd ? number_format($saldo_qtd, 2, ',', '.') : '' ?></td>
                                    <td><?= $tem_qtd ? number_format($saldo_valor, 2, ',', '.') . ' €' : '' ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot>
                                <tr class="row-totais">
                                    <td colspan="3" class="text-right">TOTAIS</td>
                                    <td colspan="2"></td>
                                    <td><?= number_format($tot_sup, 2, ',', '.') ?> €</td>
                                    <td></td>
                                    <td><?= number_format($tot_mes_sup, 2, ',', '.') ?> €</td>
                                    <td colspan="2"></td>
                                    <td><?= number_format($tot_acum_sup, 2, ',', '.') ?> €</td>
                                    <td></td>
                                    <td><?= number_format($tot_saldo_sup, 2, ',', '.') ?> €</td>
                                </tr>
                            </tfoot>
                        </table>
                        <?php else: $tot_mes_sup = 0; endif; ?>

                        <div class="clearfix">
                            <table class="resumo">
                                <tr>
                                    <th>Total Adjudicado (Auto)</th>
                                    <td><?= number_format($tot_mes_adj, 2, ',', '.') ?> €</td>
                                </tr>
                                <?php if (!empty($itens_sup)): ?>
                                <tr>
                                    <th>Total Suplementar (Auto)</th>
                                    <td><?= number_format($tot_mes_sup, 2, ',', '.') ?> €</td>
                                </tr>
                                <?php endif; ?>
                                <tr class="total-final">
                                    <th>TOTAL DO AUTO</th>
                                    <td><?= number_format($auto['valor_total_mes'], 2, ',', '.') ?> €</td>
                                </tr>
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
        
        <div class="page-footer-content">
            <div class="footer-left"></div>
            <div class="footer-center">
                <strong>m.Abreu – Instalações Técnicas, Unipessoal, Lda.</strong><br>
                Rua Damião de Góis, 205 | 4700-028 Braga | NIF: 514414120 | geral@mabreu.pt | +351 912 854 950
            </div>
            <div class="footer-right"></div>
        </div>

    </div>

    <script>
        window.onload = function() {
            setTimeout(function() {
                window.print();
            }, 500);
        }
    </script>
</body>
</html>