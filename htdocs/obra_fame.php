<?php
// =====================================================================
// FAME — Ficha de Aprovação de Material e Equipamento da obra (?id=obra)
// Uma lista por obra; cada linha tem a sua especialidade (escolhida linha a linha).
// Linhas do orçamento (importadas) ou acrescentadas à mão; escolher uma ou várias para subir / descer,
// mudar a especialidade ou apagar. O PDF sai uma FAME por especialidade.
// Fabricante / Referência / Ficha técnica ficam gravados na linha e na memória geral (fame_catalogo).
// A designação de TODAS as linhas pode ser corrigida (também as que vêm do orçamento): a linha continua ligada
// ao material do orçamento (chave) e a correção fica na memória — nas obras seguintes o mesmo material do orçamento
// já entra com a designação corrigida, fabricante, referência e ficha técnica.
// A unidade também pode ser mudada nas linhas do orçamento (aparece "orç.: X" para voltar à do orçamento);
// o material a aprovisionar usa a unidade da FAME.
// A Ref / Artigo do orçamento vem com as linhas (coluna "Ref. / Art.", editável); linhas antigas sem artigo
// recebem-no do orçamento ao abrir a FAME (fica gravado na próxima gravação).
// =====================================================================
session_start();
require_once 'db.php';
require_once 'obra_fame_comum.php';

if (!isset($_SESSION['user_id'])) { header("Location: index.php"); exit; }
if (file_exists('functions.php')) { require_once 'functions.php'; }
if (!function_exists('registrarLog')) { function registrarLog($pdo, $acao, $modulo) { return true; } }

if (!fameTemAcesso($pdo)) { header("Location: index.php?erro=sem_permissao"); exit; }

$nome_completo = $_SESSION['nome'] ?? 'Utilizador';
$primeiro_nome = explode(' ', trim($nome_completo))[0];

fameCriarTabelas($pdo);

$id_obra = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$obra = $id_obra ? fameObra($pdo, $id_obra) : null;
if (!$obra) { header("Location: obras.php"); exit; }

$orc = fameOrcamentoDaObra($pdo, $obra);
$fame = fameDaObra($pdo, $id_obra);
$id_fame = (int)$fame['id'];
// Linhas antigas do orçamento passam para a chave com especialidade (o mesmo material no Elétrico e no ITED = 2 linhas)
if ($orc) { try { fameAtualizarChaves($pdo, $id_obra, null, $orc['id']); } catch (Exception $e) {} }
$mensagem = ''; $tipo_mensagem = '';

// --- REPOR AS LINHAS DO ORÇAMENTO ORIGINAL ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['repor_orcamento'])) {
    if (!$orc) {
        $mensagem = "Esta obra não está ligada a um orçamento."; $tipo_mensagem = 'erro';
    } else {
        try {
            $pdo->beginTransaction();
            $n = fameReporOrcamento($pdo, $id_obra, $id_fame, $orc['id'], !empty($_POST['manter_manuais']));
            $pdo->commit();
            registrarLog($pdo, "Repôs as linhas do orçamento na FAME da obra ID: $id_obra", "Obras");
            header("Location: obra_fame.php?id=" . $id_obra . "&reposto=" . $n); exit;
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $mensagem = "Erro ao repor: " . $e->getMessage(); $tipo_mensagem = 'erro';
        }
    }
}

// --- GRAVAR (e, se pedido, importar material do orçamento ou imprimir) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['gravar_fame'])) {
    try {
        $pdo->beginTransaction();

        $pdo->prepare("UPDATE obras_fames SET revisao = ?, data = ?, destinatario = ?, observacoes = ?, atualizado_por = ?, atualizado_em = NOW() WHERE id = ?")
            ->execute([max(0, (int)($_POST['revisao'] ?? 0)), !empty($_POST['data']) ? $_POST['data'] : null,
                       trim($_POST['destinatario'] ?? ''), trim($_POST['observacoes'] ?? ''), $_SESSION['user_id'], $id_fame]);

        // Linhas: grava pela ordem em que estão no ecrã
        $pdo->prepare("DELETE FROM obras_fame WHERE id_fame = ?")->execute([$id_fame]);
        $bib = fameBiblioteca($pdo);
        $ins = $pdo->prepare("INSERT INTO obras_fame (id_obra, id_fame, ordem, chave, artigo, designacao, unidade, quantidade, especialidade, fabricante, referencia, ficha_tecnica, id_ficha_tecnica, incluir, manual, alternativa)
                              VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $cat = $pdo->prepare("INSERT INTO fame_catalogo (chave, designacao, fabricante, referencia, ficha_tecnica, id_ficha_tecnica, atualizado_em)
                              VALUES (?, ?, ?, ?, ?, ?, NOW())
                              ON DUPLICATE KEY UPDATE designacao = VALUES(designacao),
                                  fabricante    = IF(VALUES(fabricante) <> '', VALUES(fabricante), fabricante),
                                  referencia    = IF(VALUES(referencia) <> '', VALUES(referencia), referencia),
                                  ficha_tecnica = IF(VALUES(ficha_tecnica) <> '', VALUES(ficha_tecnica), ficha_tecnica),
                                  id_ficha_tecnica = IF(VALUES(ficha_tecnica) <> '', VALUES(id_ficha_tecnica), id_ficha_tecnica),
                                  atualizado_em = NOW()");
        $ordem = 0; $esp_usadas = [];
        foreach ((array)($_POST['linha'] ?? []) as $l) {
            $desig = trim(preg_replace('/\s+/', ' ', (string)($l['designacao'] ?? '')));
            if ($desig === '') continue;
            $unid = mb_substr(trim((string)($l['unidade'] ?? '')), 0, 10);
            $manual = ($l['origem'] ?? '') === 'orc' ? 0 : 1;
            $chave = $manual ? fameChave($desig, $unid) : (string)($l['chave'] ?? fameChave($desig, $unid));
            // Uma ou várias especialidades ("Elétrico;ITED")
            $esp = fameEspsTexto(fameEspsLista($l['especialidade'] ?? ''));
            foreach (fameEspsLista($esp) as $x) $esp_usadas[$x] = 1;
            $fab = mb_substr(trim((string)($l['fabricante'] ?? '')), 0, 120);
            $ref = mb_substr(trim((string)($l['referencia'] ?? '')), 0, 120);
            $fic = mb_substr(trim((string)($l['ficha_tecnica'] ?? '')), 0, 255);
            $art = mb_substr(trim(preg_replace('/\s+/', ' ', (string)($l['artigo'] ?? ''))), 0, 120);
            // Ficha da biblioteca (pelo nome escolhido na lista: "Marca Modelo")
            $id_fic = fameResolverFicha($bib, $l['id_ficha'] ?? 0, $fic) ?: null;
            $ins->execute([$id_obra, $id_fame, ++$ordem, $chave, $art, $desig, $unid, fameNum($l['quantidade'] ?? '0'), $esp, $fab, $ref, $fic, $id_fic, !empty($l['incluir']) ? 1 : 0, $manual, !empty($l['alternativa']) ? 1 : 0]);
            if ($fab !== '' || $ref !== '' || $fic !== '') $cat->execute([fameChaveCatalogo($desig), $desig, $fab, $ref, $fic, $id_fic]);
            // Linha do orçamento com a designação corrigida: a memória do material do orçamento passa a ter a nova designação
            $orig = trim(preg_replace('/\s+/', ' ', (string)($l['desig_orig'] ?? '')));
            if (!$manual && $orig !== '' && fameChaveCatalogo($orig) !== fameChaveCatalogo($desig)) {
                $cat->execute([fameChaveCatalogo($orig), $desig, $fab, $ref, $fic, $id_fic]);
            }
        }
        // Especialidades novas (escritas em "Outra") ficam para as próximas vezes
        foreach (array_keys($esp_usadas) as $esp) fameJuntarEspecialidade($pdo, $esp);

        $n_imp = 0;
        if (($_POST['acao'] ?? '') === 'importar') $n_imp = fameImportar($pdo, $id_obra, $id_fame, $orc['id'] ?? 0, $_POST['importar'] ?? [], $_POST['esp_grupo'] ?? []);
        $pdo->commit();
        registrarLog($pdo, "Gravou FAME da obra ID: $id_obra", "Obras");

        if (($_POST['acao'] ?? '') === 'imprimir') {
            $esp_pdf = trim((string)($_POST['imprimir_esp'] ?? ''));
            // Com as fichas técnicas juntas no fim (PDF completo para enviar à fiscalização)
            if (!empty($_POST['com_fichas'])) { header("Location: obra_fame_dossier.php?id=" . $id_obra . ($esp_pdf !== '' ? '&esp=' . urlencode($esp_pdf) : '')); exit; }
            header("Location: obra_fame_pdf.php?id=" . $id_obra . ($esp_pdf !== '' ? '&esp=' . urlencode($esp_pdf) : '')); exit;
        }
        header("Location: obra_fame.php?id=" . $id_obra . (($_POST['acao'] ?? '') === 'importar' ? "&importadas=" . $n_imp : "&gravado=1")); exit;
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $mensagem = "Erro ao gravar: " . $e->getMessage(); $tipo_mensagem = 'erro';
    }
}
if (isset($_GET['gravado'])) { $mensagem = "FAME gravada. Fabricantes, referências e fichas técnicas ficaram na memória para as próximas obras."; $tipo_mensagem = 'sucesso'; }
if (isset($_GET['reposto'])) { $mensagem = "Linhas do orçamento repostas (" . (int)$_GET['reposto'] . " linhas, pela ordem e com as quantidades do orçamento)."; $tipo_mensagem = 'sucesso'; }
if (isset($_GET['importadas'])) { $mensagem = (int)$_GET['importadas'] . " linha(s) do orçamento acrescentadas no fim da lista."; $tipo_mensagem = 'sucesso'; }

$fame = fameDaObra($pdo, $id_obra);
$linhas = fameLinhasDaFame($pdo, $id_fame);
$catalogo = fameCatalogo($pdo);
$disponiveis = fameDisponiveis($pdo, $id_obra, $orc['id'] ?? 0);
$qtd_orc = []; $desig_orc = []; $art_orc = []; $un_orc = [];
foreach (fameMateriaisOrcamento($pdo, $orc['id'] ?? 0, false) as $k => $m) { $qtd_orc[$k] = $m['quantidade']; $desig_orc[$k] = $m['designacao']; $art_orc[$k] = $m['artigo']; $un_orc[$k] = $m['unidade']; }
// Linhas do orçamento importadas antes de haver a coluna Ref. / Art.: vai buscar o artigo ao orçamento
$artigos_novos = 0;
foreach ($linhas as &$l_a) {
    if (!$l_a['manual'] && trim((string)($l_a['artigo'] ?? '')) === '' && ($art_orc[$l_a['chave']] ?? '') !== '') { $l_a['artigo'] = $art_orc[$l_a['chave']]; $artigos_novos++; }
}
unset($l_a);
if ($artigos_novos && $mensagem === '') {
    $mensagem = "Foram preenchidas $artigos_novos Ref. / Art. a partir do orçamento. Grave para as guardar.";
    $tipo_mensagem = 'info';
}

$especialidades = fameEspecialidades($pdo);
foreach ($linhas as $l) foreach (fameEspsLista($l['especialidade']) as $x) if (!in_array($x, $especialidades, true)) $especialidades[] = $x;

// Listas para sugestões (datalist) e mapa referência → fabricante / ficha
$l_fab = []; $l_ref = []; $l_fic = []; $mapa_ref = [];
foreach ($catalogo as $c) {
    if ((string)$c['fabricante'] !== '') $l_fab[$c['fabricante']] = 1;
    if ((string)$c['ficha_tecnica'] !== '') $l_fic[$c['ficha_tecnica']] = 1;
    if ((string)$c['referencia'] !== '') { $l_ref[$c['referencia']] = 1; $mapa_ref[mb_strtolower($c['referencia'], 'UTF-8')] = ['f' => (string)$c['fabricante'], 't' => (string)$c['ficha_tecnica']]; }
}
// Fichas da biblioteca (fichas_tecnicas.php): aparecem primeiro na lista da ficha técnica
$bib = fameBiblioteca($pdo);
$bib_js = [];
foreach ($bib['id'] as $f) {
    if ((int)$f['ativo'] !== 1) continue;
    $bib_js[mb_strtolower($f['nome'], 'UTF-8')] = ['u' => $f['existe'] ? $f['url'] : '', 'm' => (string)$f['marca'], 'r' => (string)$f['referencia']];
    if ((string)$f['marca'] !== '') $l_fab[$f['marca']] = 1;
    if ((string)$f['referencia'] !== '') $l_ref[$f['referencia']] = 1;
    unset($l_fic[$f['nome']]);
}
foreach ([&$l_fab, &$l_ref, &$l_fic] as &$lista) { $lista = array_keys($lista); natcasesort($lista); }
unset($lista);
$l_fic_bib = array_values(array_map(fn($f) => $f['nome'], array_filter($bib['id'], fn($f) => (int)$f['ativo'] === 1)));

$e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$ref_obra = $obra['referencia_obra'] ?: ($orc['numero_orcamento'] ?? (string)$id_obra);

// Especialidade(s) de uma linha: botão que abre a lista com caixas (várias) — clicar no nome escolhe só essa
$selEsp = function ($i, $valor) use ($e) {
    $lista = fameEspsLista($valor);
    return '<div class="esp-multi"><input type="hidden" class="i-esp" name="linha[' . $i . '][especialidade]" value="' . $e(implode(';', $lista)) . '">'
         . '<button type="button" class="esp-btn' . ($lista ? '' : ' vazio') . '" onclick="abrirEspMenu(this)"><span class="esp-txt">' . ($lista ? $e(implode(' + ', $lista)) : '—') . '</span><i class="fa-solid fa-caret-down"></i></button></div>';
};
?>
<!DOCTYPE html>
<html lang="pt-PT">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>FAME - <?= $e($obra['nome']) ?> - Gestão MAB</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { font-family: system-ui, -apple-system, sans-serif; box-sizing: border-box; }
        body { background-color: #f7f9fc; margin: 0; color: #333; font-size: 14px; padding-bottom: 110px; }

        .topbar { background: rgba(255,255,255,0.94); backdrop-filter: blur(8px); -webkit-backdrop-filter: blur(8px); padding: 10px 40px; box-shadow: 0 1px 0 #e8ecf1; display: flex; justify-content: space-between; align-items: center; position: sticky; top: 0; z-index: 100; }
        .topbar-left a { display: block; }
        .topbar-left .logo { height: 35px; display: block; }
        .topbar-right { display: flex; align-items: center; gap: 12px; }
        .btn-voltar { width: 40px; height: 40px; border-radius: 50%; background: #fff; border: 1px solid #e8ecf1; color: #576574; display: inline-flex; align-items: center; justify-content: center; text-decoration: none; font-size: 16px; transition: all 0.2s ease; box-shadow: 0 2px 6px rgba(0,0,0,0.04); flex-shrink: 0; }
        .btn-voltar:hover { background: #eef2f6; color: #34495e; border-color: #d5dce4; transform: translateX(-2px); }
        .btn-voltar:active { transform: scale(0.94); }
        .user-menu { position: relative; }
        .user-btn { background: #fff; border: 1px solid #e8ecf1; border-radius: 30px; font-size: 14px; font-weight: 600; color: #2c3e50; cursor: pointer; display: flex; align-items: center; gap: 10px; padding: 5px 14px 5px 5px; transition: 0.2s; font-family: inherit; }
        .user-avatar { background: #e74c3c; color: #fff; width: 28px; height: 28px; border-radius: 50%; display: flex; justify-content: center; align-items: center; font-size: 13px; }
        .dropdown-content { display: none; position: absolute; right: 0; top: 100%; background: #fff; min-width: 180px; box-shadow: 0 12px 30px rgba(0,0,0,0.1); border-radius: 10px; border: 1px solid #e8ecf1; overflow: hidden; margin-top: 6px; z-index: 200; }
        .user-menu:hover .dropdown-content, .user-menu.aberto .dropdown-content { display: block; }
        .dropdown-content a { color: #576574; padding: 12px 20px; text-decoration: none; display: block; font-size: 14px; }
        .dropdown-content a:hover { background: #f0fff4; color: #2ecc71; }

        .container { max-width: 1400px; margin: 20px auto; padding: 0 15px; }
        .alert { padding: 14px; border-radius: 8px; margin-bottom: 18px; font-weight: 600; text-align: center; }
        .alert-sucesso { background: #e8f5e9; color: #27ae60; border: 1px solid #c8e6c9; }
        .alert-erro { background: #fdeded; color: #e74c3c; border: 1px solid #fadbd8; }
        .alert-info { background: #f4ecf7; color: #7d3c98; border: 1px solid #e8daef; }

        .header-box { display: flex; justify-content: space-between; align-items: flex-start; gap: 15px; margin-bottom: 18px; flex-wrap: wrap; }
        h1 { margin: 0; color: #2c3e50; font-size: 22px; font-weight: 800; }
        .sub { color: #7f8c8d; font-size: 13px; margin-top: 6px; line-height: 1.5; }
        .badge-ref { font-size: 11px; color: #2ecc71; font-weight: 700; background: #e8f5e9; padding: 3px 8px; border-radius: 5px; display: inline-block; }
        .header-actions { display: flex; gap: 8px; flex-wrap: wrap; }
        .btn-out { background: #fff; color: #34495e; border: 1px solid #cfd6de; padding: 8px 14px; border-radius: 30px; text-decoration: none; font-weight: 700; font-size: 13px; display: inline-flex; align-items: center; gap: 6px; cursor: pointer; font-family: inherit; }
        .btn-out:hover { background: #34495e; color: #fff; border-color: #34495e; }

        .card { background: #fff; border-radius: 12px; border: 1px solid #e1e5ea; box-shadow: 0 2px 10px rgba(0,0,0,0.02); padding: 18px; margin-bottom: 18px; }
        .card h2 { margin: 0 0 14px; font-size: 14px; color: #2c3e50; text-transform: uppercase; letter-spacing: .3px; display: flex; align-items: center; gap: 8px; }
        .grid-info { display: grid; grid-template-columns: 100px 160px 1fr; gap: 12px; }
        .grid-info .full { grid-column: 1 / -1; }
        label.lb { display: block; font-size: 11px; font-weight: 700; color: #576574; text-transform: uppercase; margin-bottom: 5px; }
        input[type=text], input[type=number], input[type=date], textarea, select { width: 100%; padding: 9px 10px; border: 1px solid #dcdde1; border-radius: 8px; font-size: 14px; font-family: inherit; color: #2c3e50; background: #fff; outline: none; }
        input:focus, textarea:focus, select:focus { border-color: #2ecc71; box-shadow: 0 0 0 3px rgba(46,204,113,0.1); }
        textarea { resize: vertical; min-height: 42px; }

        .barra { display: flex; justify-content: space-between; align-items: center; gap: 10px; flex-wrap: wrap; margin-bottom: 12px; }
        .progresso { font-size: 13px; color: #576574; font-weight: 600; }
        .progresso b { color: #27ae60; }
        .filtros { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
        .filtros input[type=text] { width: 210px; padding: 7px 10px; font-size: 13px; }
        .filtros select { width: auto; padding: 7px 10px; font-size: 13px; }
        .chk-lbl { font-size: 13px; color: #576574; display: inline-flex; align-items: center; gap: 6px; cursor: pointer; font-weight: 600; }

        /* Barra das linhas escolhidas */
        .sel-barra { position: sticky; top: 56px; z-index: 50; display: flex; align-items: center; gap: 8px; flex-wrap: wrap; background: #eafaf1; border: 1px solid #abebc6; border-radius: 10px; padding: 8px 10px; margin-bottom: 10px; }
        .sel-barra.vazia { background: #f8f9fa; border-color: #e1e5ea; }
        .sel-barra.vazia .sel-acao { opacity: .45; pointer-events: none; }
        .sel-info { font-size: 13px; font-weight: 700; color: #1e8449; margin-right: 4px; }
        .sel-barra.vazia .sel-info { color: #95a5a6; font-weight: 600; }
        .sel-acao { background: #fff; border: 1px solid #cfd6de; color: #34495e; padding: 6px 10px; border-radius: 8px; font-size: 12px; font-weight: 700; cursor: pointer; font-family: inherit; display: inline-flex; align-items: center; gap: 5px; }
        .sel-acao:hover { background: #34495e; color: #fff; border-color: #34495e; }
        .sel-acao.perigo { color: #e74c3c; border-color: #f5b7b1; }
        .sel-acao.perigo:hover { background: #e74c3c; color: #fff; }
        .sel-esp { width: auto !important; padding: 6px 8px !important; font-size: 12px !important; }
        .sel-sep { width: 1px; height: 22px; background: #d5dce4; margin: 0 2px; }

        table { width: 100%; border-collapse: collapse; }
        th { background: #f8f9fa; padding: 10px 6px; text-align: left; font-size: 11px; text-transform: uppercase; color: #576574; border-bottom: 2px solid #e1e5ea; }
        td { padding: 7px 6px; border-bottom: 1px solid #f1f2f6; color: #2c3e50; vertical-align: middle; }
        td input[type=text], td select { padding: 7px 8px; font-size: 13px; }
        .c-sel { width: 34px; text-align: center; }
        .c-sel input { width: 17px; height: 17px; accent-color: #2ecc71; cursor: pointer; }
        .c-inc { width: 38px; text-align: center; }
        .c-un { width: 62px; text-align: center; }
        .c-un input.i-un { padding: 7px 3px !important; }
        .i-un::-webkit-calendar-picker-indicator { display: none !important; }
        .c-art { width: 82px; }
        .c-art input { font-weight: 700; color: #8e44ad; font-size: 12px !important; padding: 7px 6px !important; }
        .c-qtd { width: 78px; text-align: center; }
        td.c-desig { min-width: 200px; }
        /* Alternativa */
        .c-alt { width: 46px; text-align: center; }
        .alt-lbl { display: inline-flex; align-items: center; justify-content: center; gap: 6px; cursor: pointer; }
        .alt-lbl input { width: 17px; height: 17px; accent-color: #e67e22; cursor: pointer; margin: 0; }
        .alt-lbl span { display: none; font-size: 12px; font-weight: 700; color: #576574; }
        tr.alternativa td { background: #fffaf2; }
        tr.alternativa td.c-desig { box-shadow: inset 3px 0 0 #e67e22; }
        tr.alternativa .alt-lbl span { color: #d35400; }
        .c-esp { width: 150px; }
        .c-del { width: 74px; text-align: center; white-space: nowrap; }
        .c-del button + button { margin-left: 4px; }
        tr.selecionada td { background: #f0fbf5; }
        .tg-print { width: 30px; height: 30px; border-radius: 6px; border: 1px solid #e1e5ea; background: #fff; color: #bdc3c7; cursor: pointer; }
        .tg-print.on { color: #34495e; border-color: #cfd6de; background: #f4f6f8; }
        .desig { font-weight: 600; line-height: 1.35; display: block; }
        /* Designação editável (linhas do orçamento: caixa que cresce com o texto) */
        textarea.i-desig { width: 100%; padding: 7px 8px; font-size: 13px; font-family: inherit; font-weight: 600; color: #2c3e50; line-height: 1.35; border: 1px solid transparent; border-radius: 6px; background: transparent; resize: none; overflow: hidden; display: block; box-sizing: border-box; min-height: 0; }
        textarea.i-desig:hover { border-color: #e1e5ea; background: #fff; }
        textarea.i-desig:focus { border-color: #3498db; background: #fff; outline: none; }
        tr.completa .i-desig { box-shadow: inset 3px 0 0 #27ae60; }
        .desig-orc { display: inline-block; margin: 3px 0 0 8px; padding: 0; border: none; background: none; font-size: 10px; color: #e67e22; font-weight: 700; cursor: pointer; text-align: left; font-family: inherit; }
        .desig-orc:hover { text-decoration: underline; }
        .un-orc { display: block; margin: 2px auto 0; padding: 0; border: none; background: none; font-size: 10px; color: #e67e22; font-weight: 700; cursor: pointer; font-family: inherit; white-space: nowrap; }
        .un-orc:hover { text-decoration: underline; }
        .qtd-orc { display: block; font-size: 10px; color: #e67e22; font-weight: 700; margin-top: 2px; }
        tr.excluida td.c-art, tr.excluida td.c-desig, tr.excluida td.c-un, tr.excluida td.c-qtd, tr.excluida td[data-label] { opacity: .45; }
        tr.completa .desig::before { content: "\f00c"; font-family: "Font Awesome 6 Free"; font-weight: 900; color: #27ae60; margin-right: 6px; font-size: 11px; }
        tr.destaque td { background: #d5f5e3 !important; transition: background .6s; }
        /* Especialidade(s) da linha */
        .esp-multi { position: relative; }
        .esp-btn { width: 100%; display: flex; align-items: center; justify-content: space-between; gap: 6px; padding: 7px 8px; border: 1px solid #dcdde1; border-radius: 8px; background: #fff; font-size: 12.5px; font-family: inherit; color: #2c3e50; cursor: pointer; text-align: left; line-height: 1.3; }
        .esp-btn:hover { border-color: #2ecc71; }
        .esp-btn .esp-txt { flex: 1; }
        .esp-btn i { color: #95a5a6; font-size: 11px; }
        .esp-btn.vazio { color: #e67e22; border-color: #f8c471; }
        #espMenu { position: absolute; z-index: 1200; background: #fff; border: 1px solid #dcdde1; border-radius: 10px; box-shadow: 0 12px 30px rgba(0,0,0,0.15); padding: 6px; min-width: 220px; display: none; }
        #espMenu.show { display: block; }
        .em-op { display: flex; align-items: center; gap: 8px; padding: 4px 6px; border-radius: 6px; }
        .em-op:hover { background: #f4f6f8; }
        .em-op input { width: 17px; height: 17px; accent-color: #2ecc71; cursor: pointer; flex-shrink: 0; margin: 0; }
        .em-txt { flex: 1; cursor: pointer; padding: 4px 2px; font-size: 13px; font-weight: 600; color: #2c3e50; }
        .em-txt:hover { color: #1e8449; text-decoration: underline; }
        .em-dica { font-size: 10.5px; color: #95a5a6; padding: 4px 6px 6px; border-bottom: 1px solid #f1f2f6; margin-bottom: 4px; }
        .em-rodape { display: flex; justify-content: space-between; gap: 8px; border-top: 1px solid #f1f2f6; margin-top: 4px; padding: 6px 4px 2px; }
        .em-rodape button { background: none; border: none; font-size: 12px; font-weight: 700; cursor: pointer; font-family: inherit; color: #2980b9; padding: 4px; }
        .em-rodape button.em-limpar { color: #7f8c8d; }
        .tag-mem { display: inline-block; font-size: 10px; font-weight: 700; padding: 2px 6px; border-radius: 4px; background: #eaf2fb; color: #1f6fb2; }
        .btn-del { color: #e74c3c; background: #fdeded; border: none; cursor: pointer; width: 30px; height: 30px; border-radius: 6px; }
        .btn-ins { color: #8e44ad; background: #f4ecf7; border: none; cursor: pointer; width: 30px; height: 30px; border-radius: 6px; }
        .btn-ins:hover { background: #e8daef; }
        .btn-add { background: #fff; color: #8e44ad; border: 1px dashed #c39bd3; padding: 9px 14px; border-radius: 8px; font-weight: 700; font-size: 13px; cursor: pointer; font-family: inherit; }
        .btn-imp { background: #fff; color: #2980b9; border: 1px dashed #85c1e9; padding: 9px 14px; border-radius: 8px; font-weight: 700; font-size: 13px; cursor: pointer; font-family: inherit; }
        .btn-repor { background: #fff; color: #d35400; border: 1px dashed #f0b27a; padding: 9px 14px; border-radius: 8px; font-weight: 700; font-size: 13px; cursor: pointer; font-family: inherit; margin-left: auto; }
        .btn-repor:hover { background: #fdf2e9; }
        .acoes-tabela { display: flex; gap: 10px; flex-wrap: wrap; margin-top: 12px; }
        .vazio-lista { text-align: center; color: #95a5a6; padding: 30px 10px; }

        .rodape { position: fixed; left: 0; right: 0; bottom: 0; background: rgba(255,255,255,0.97); border-top: 1px solid #e1e5ea; padding: 12px 20px; display: flex; justify-content: flex-end; gap: 10px; z-index: 90; box-shadow: 0 -4px 14px rgba(0,0,0,0.04); }
        .btn-gravar { background: #2ecc71; color: #fff; border: none; padding: 12px 22px; border-radius: 10px; font-weight: 800; font-size: 14px; cursor: pointer; font-family: inherit; display: inline-flex; align-items: center; gap: 8px; }
        .btn-imprimir { background: #34495e; color: #fff; border: none; padding: 12px 22px; border-radius: 10px; font-weight: 800; font-size: 14px; cursor: pointer; font-family: inherit; display: inline-flex; align-items: center; gap: 8px; }

        .modal { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.55); z-index: 1001; justify-content: center; align-items: center; padding: 16px; }
        .modal.show { display: flex; }
        .modal-content { background: #fff; padding: 22px; border-radius: 14px; width: 100%; max-width: 640px; max-height: 94vh; overflow-y: auto; }
        .modal-content h3 { margin: 0 0 6px; color: #2c3e50; font-size: 17px; }
        .modal-btns { display: flex; gap: 10px; margin-top: 16px; }
        .modal-btns button { flex: 1; padding: 12px; border: none; border-radius: 8px; font-weight: 700; cursor: pointer; font-family: inherit; font-size: 14px; }
        .btn-cancelar { background: #f1f2f6; color: #576574; }
        .btn-confirmar { background: #2980b9; color: #fff; }
        .btn-confirmar:disabled { background: #aed6f1; cursor: not-allowed; }
        .op-lista { display: flex; flex-direction: column; gap: 8px; margin-top: 12px; }
        .op-lista label { display: flex; align-items: center; gap: 10px; padding: 10px 12px; border: 1px solid #e1e5ea; border-radius: 10px; cursor: pointer; font-weight: 600; color: #2c3e50; }
        .op-lista label small { margin-left: auto; color: #95a5a6; font-weight: 600; }
        .op-lista input { accent-color: #34495e; width: 16px; height: 16px; }
        .op-fichas { display: flex; gap: 10px; align-items: flex-start; margin-top: 12px; padding: 10px 12px; border: 1px dashed #16a085; border-radius: 10px; background: #f0faf7; cursor: pointer; font-size: 13px; color: #2c3e50; }
        .op-fichas input { accent-color: #16a085; width: 16px; height: 16px; margin-top: 2px; }
        .op-fichas small { color: #7f8c8d; }
        .c-fic { position: relative; }
        .c-fic .i-fic.na-biblioteca { padding-right: 30px; border-color: #a3e4d7; background: #f4fbf9; }
        .fic-pdf { position: absolute; right: 12px; top: 50%; transform: translateY(-50%); color: #c0392b; font-size: 15px; text-decoration: none; }

        @media (max-width: 1024px) {
            .topbar { padding: 10px 20px; }
            .grid-info { grid-template-columns: 1fr 1fr; }
        }
        @media (max-width: 768px) {
            .topbar { padding: 10px 15px; }
            .btn-voltar { width: 38px; height: 38px; }
            .user-nome { display: none; }
            .header-actions { width: 100%; }
            .header-actions .btn-out { flex: 1; justify-content: center; }
            .filtros, .filtros input[type=text], .filtros select { width: 100%; }
            .sel-barra { top: 52px; }
            thead { display: none; }
            table, tbody, tr, td { display: block; width: 100%; }
            tr { border: 1px solid #e1e5ea; border-radius: 10px; margin-bottom: 10px; padding: 10px 10px 10px 44px; position: relative; background: #fff; }
            td { border: none; padding: 4px 0; }
            td.c-sel { position: absolute; left: 10px; top: 12px; width: auto; padding: 0; }
            td.c-sel input { width: 20px; height: 20px; }
            td.c-inc { position: absolute; top: 8px; right: 82px; width: auto; padding: 0; }
            td.c-del { position: absolute; top: 8px; right: 8px; width: auto; padding: 0; }
            td.c-desig { padding-right: 116px; }
            td.c-art { width: 100%; padding-right: 116px; }
            td.c-un, td.c-qtd { display: inline-block; width: 48%; text-align: left; }
            td.c-alt { width: 100%; text-align: left; }
            td.c-alt[data-label]::before { display: none; }
            .alt-lbl span { display: inline; }
            td.c-esp { width: 100%; }
            td[data-label]::before { content: attr(data-label); display: block; font-size: 10px; font-weight: 700; color: #95a5a6; text-transform: uppercase; margin: 4px 0 3px; }
            .rodape { padding: 10px 12px; }
            .rodape button { flex: 1; justify-content: center; padding: 12px 10px; }
        }
        @media (max-width: 560px) {
            .grid-info { grid-template-columns: 1fr; }
            h1 { font-size: 19px; }
            .acoes-tabela > * { flex: 1; }
            .sel-sep { display: none; }
        }
    </style>
    <?= fameEscolherRecursos() ?>
</head>
<body>

    <header class="topbar">
        <div class="topbar-left"><a href="index.php" title="Página inicial"><img src="mab.png" alt="MAB" class="logo"></a></div>
        <div class="topbar-right">
            <a href="obras.php" class="btn-voltar" title="Voltar" aria-label="Voltar"><i class="fa-solid fa-arrow-left"></i></a>
            <div class="user-menu">
                <button type="button" class="user-btn">
                    <div class="user-avatar"><i class="fa-solid fa-user"></i></div>
                    <span class="user-nome"><?= $e($primeiro_nome) ?></span>
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
            <div class="alert alert-<?= $tipo_mensagem ?>"><?= $e($mensagem) ?></div>
        <?php endif; ?>

        <div class="header-box">
            <div>
                <h1><i class="fa-solid fa-clipboard-check" style="color:#2ecc71; margin-right:6px;"></i> FAME</h1>
                <div class="sub">
                    Ficha de Aprovação de Material e Equipamento · Rev. <?= str_pad((string)(int)$fame['revisao'], 2, '0', STR_PAD_LEFT) ?><br>
                    <strong style="color:#2c3e50;"><?= $e($obra['nome']) ?></strong> <span class="badge-ref">REF: <?= $e($ref_obra) ?></span>
                    <?php if (!empty($obra['cliente_nome'])): ?><br><i class="fa-solid fa-user" style="font-size:11px;"></i> <?= $e($obra['cliente_nome']) ?><?php endif; ?>
                </div>
            </div>
            <div class="header-actions">
                <a href="obra_material.php?id=<?= $id_obra ?>" class="btn-out"><i class="fa-solid fa-boxes-stacked"></i> Material a aprovisionar</a>
            </div>
        </div>

        <form method="POST" id="formFame" autocomplete="off">
            <input type="hidden" name="gravar_fame" value="1">
            <input type="hidden" name="acao" id="acao" value="">
            <input type="hidden" name="imprimir_esp" id="imprimir_esp" value="">
            <input type="hidden" name="com_fichas" id="com_fichas" value="">

            <div class="card">
                <h2><i class="fa-solid fa-file-signature" style="color:#2ecc71;"></i> Dados da ficha</h2>
                <div class="grid-info">
                    <div><label class="lb">Revisão</label><input type="number" name="revisao" min="0" value="<?= (int)$fame['revisao'] ?>" oninput="alterado = true;"></div>
                    <div><label class="lb">Data</label><input type="date" name="data" value="<?= $e($fame['data'] ?: date('Y-m-d')) ?>" onchange="alterado = true;"></div>
                    <div><label class="lb">Para aprovação de</label><input type="text" name="destinatario" value="<?= $e($fame['destinatario']) ?>" placeholder="Fiscalização / Dono de obra / Projetista" oninput="alterado = true;"></div>
                    <div class="full"><label class="lb">Observações</label><textarea name="observacoes" rows="2" oninput="alterado = true;"><?= $e($fame['observacoes']) ?></textarea></div>
                </div>
            </div>

            <div class="card">
                <div class="barra">
                    <h2 style="margin:0;"><i class="fa-solid fa-list-check" style="color:#2ecc71;"></i> Material e equipamento</h2>
                    <span class="progresso" id="progresso"></span>
                </div>
                <div class="barra">
                    <div class="filtros">
                        <input type="text" id="pesquisa" placeholder="Procurar material..." oninput="filtrar()">
                        <select id="filtro_esp" onchange="filtrar()">
                            <option value="*">Todas as especialidades</option>
                            <?php foreach ($especialidades as $esp): ?><option><?= $e($esp) ?></option><?php endforeach; ?>
                            <option value="">Sem especialidade</option>
                        </select>
                        <label class="chk-lbl"><input type="checkbox" id="so_falta" onchange="filtrar()"> Só os incompletos</label>
                    </div>
                    <small style="color:#95a5a6;"><i class="fa-solid fa-print"></i> = sai no PDF · <span class="tag-mem">memória</span> = já preenchido noutra obra</small>
                </div>

                <!-- Ações para as linhas escolhidas -->
                <div class="sel-barra vazia" id="selBarra">
                    <span class="sel-info" id="selInfo">Escolha uma ou várias linhas na caixa à esquerda</span>
                    <button type="button" class="sel-acao" onclick="moverSel('topo')" title="Para o início"><i class="fa-solid fa-angles-up"></i></button>
                    <button type="button" class="sel-acao" onclick="moverSel(-1)"><i class="fa-solid fa-arrow-up"></i> Subir</button>
                    <button type="button" class="sel-acao" onclick="moverSel(1)"><i class="fa-solid fa-arrow-down"></i> Descer</button>
                    <button type="button" class="sel-acao" onclick="moverSel('fim')" title="Para o fim"><i class="fa-solid fa-angles-down"></i></button>
                    <span class="sel-sep"></span>
                    <select class="sel-esp sel-acao" id="selEsp" onchange="aplicarEspSel(this)">
                        <option value="">Especialidade…</option>
                        <optgroup label="Passar a ser só" id="selEspSo">
                            <?php foreach ($especialidades as $esp): ?><option value="so:<?= $e($esp) ?>"><?= $e($esp) ?></option><?php endforeach; ?>
                        </optgroup>
                        <optgroup label="Acrescentar" id="selEspAdd">
                            <?php foreach ($especialidades as $esp): ?><option value="add:<?= $e($esp) ?>">+ <?= $e($esp) ?></option><?php endforeach; ?>
                        </optgroup>
                        <option value="__limpar">— Sem especialidade</option>
                        <option value="__outra">+ Outra…</option>
                    </select>
                    <span class="sel-sep"></span>
                    <button type="button" class="sel-acao perigo" onclick="apagarSel()"><i class="fa-solid fa-trash-can"></i> Apagar</button>
                    <button type="button" class="sel-acao" onclick="limparSel()" title="Desmarcar"><i class="fa-solid fa-xmark"></i></button>
                </div>

                <table id="tabela">
                    <thead>
                        <tr>
                            <th class="c-sel"><input type="checkbox" id="selTodas" onchange="selecionarTodas(this.checked)" title="Escolher todas as linhas visíveis"></th>
                            <th class="c-inc" title="Sai no PDF da FAME"><i class="fa-solid fa-print"></i></th>
                            <th class="c-art">Ref. / Art.</th>
                            <th>Designação</th>
                            <th class="c-alt" title="Material proposto em alternativa">Alt.</th>
                            <th class="c-un">Un.</th>
                            <th class="c-qtd">Qtd.</th>
                            <th class="c-esp">Especialidade</th>
                            <th style="width:14%;">Fabricante</th>
                            <th style="width:14%;">Referência</th>
                            <th style="width:18%;">Ficha técnica</th>
                            <th class="c-del"></th>
                        </tr>
                    </thead>
                    <tbody id="corpo">
                        <?php foreach ($linhas as $i => $l):
                            $origem = $l['manual'] ? 'manual' : 'orc';
                            $completa = $l['fabricante'] !== '' && $l['referencia'] !== '' && $l['ficha_tecnica'] !== '';
                            $q_orc = (!$l['manual'] && isset($qtd_orc[$l['chave']]) && abs($qtd_orc[$l['chave']] - (float)$l['quantidade']) > 0.001) ? $qtd_orc[$l['chave']] : null;
                        ?>
                        <tr class="linha<?= $l['incluir'] ? '' : ' excluida' ?><?= $completa ? ' completa' : '' ?><?= !empty($l['alternativa']) ? ' alternativa' : '' ?>">
                            <td class="c-sel"><input type="checkbox" class="chk-sel" onchange="mudouSel()"></td>
                            <td class="c-inc"><input type="hidden" class="v-inc" name="linha[<?= $i ?>][incluir]" value="<?= $l['incluir'] ? 1 : 0 ?>"><button type="button" class="tg-print<?= $l['incluir'] ? ' on' : '' ?>" onclick="alternarPrint(this)" title="Sai no PDF da FAME"><i class="fa-solid fa-print"></i></button></td>
                            <td class="c-art" data-label="Ref. / Art."><input type="text" class="i-art" name="linha[<?= $i ?>][artigo]" value="<?= $e($l['artigo'] ?? '') ?>" maxlength="120" oninput="alterado = true;"<?= (!$l['manual'] && ($art_orc[$l['chave']] ?? '') !== '' && ($art_orc[$l['chave']] ?? '') !== ($l['artigo'] ?? '')) ? ' title="No orçamento: ' . $e($art_orc[$l['chave']]) . '"' : '' ?>></td>
                            <td class="c-desig">
                                <input type="hidden" name="linha[<?= $i ?>][origem]" value="<?= $origem ?>">
                                <input type="hidden" name="linha[<?= $i ?>][chave]" value="<?= $e($l['chave']) ?>">
                                <?php if ($origem === 'manual'): ?>
                                    <input type="text" class="i-desig" name="linha[<?= $i ?>][designacao]" value="<?= $e($l['designacao']) ?>" placeholder="Designação do material" oninput="memoriaDesignacao(this)">
                                <?php else:
                                    // Designação do orçamento (para mostrar se foi corrigida e poder voltar a ela)
                                    $d_orig = $desig_orc[$l['chave']] ?? '';
                                ?>
                                    <input type="hidden" class="v-desig-orig" name="linha[<?= $i ?>][desig_orig]" value="<?= $e($d_orig) ?>">
                                    <textarea class="i-desig" name="linha[<?= $i ?>][designacao]" rows="1" placeholder="Designação do material" oninput="mudouDesignacao(this)" onkeydown="if (event.key === 'Enter') event.preventDefault();"><?= $e($l['designacao']) ?></textarea>
                                    <?php if ($d_orig !== ''): ?>
                                    <button type="button" class="desig-orc" onclick="reporDesignacao(this)" title="Designação no orçamento — clique para voltar a ela" style="<?= fameChaveCatalogo($d_orig) === fameChaveCatalogo($l['designacao']) ? 'display:none;' : '' ?>"><i class="fa-solid fa-rotate-left"></i> orç.: <span><?= $e($d_orig) ?></span></button>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </td>
                            <td class="c-alt" data-label="Alternativa"><label class="alt-lbl" title="Material proposto em alternativa"><input type="checkbox" class="i-alt" name="linha[<?= $i ?>][alternativa]" value="1"<?= !empty($l['alternativa']) ? ' checked' : '' ?> onchange="mudouAlt(this)"><span>Alternativa</span></label></td>
                            <td class="c-un" data-label="Un.">
                                <input type="text" class="i-un" name="linha[<?= $i ?>][unidade]" value="<?= $e($l['unidade']) ?>" maxlength="10" list="dl_un" style="text-align:center;" oninput="mudouUnidade(this)"<?php if ($origem !== 'manual' && isset($un_orc[$l['chave']])): ?> data-orc="<?= $e($un_orc[$l['chave']]) ?>"<?php endif; ?>>
                                <?php if ($origem !== 'manual' && isset($un_orc[$l['chave']])): ?><button type="button" class="un-orc" onclick="reporUnidade(this)" title="Unidade no orçamento — clique para voltar a ela" style="<?= mb_strtolower(trim($un_orc[$l['chave']])) === mb_strtolower(trim((string)$l['unidade'])) ? 'display:none;' : '' ?>">orç.: <?= $e($un_orc[$l['chave']]) ?></button><?php endif; ?>
                            </td>
                            <td class="c-qtd" data-label="Qtd.">
                                <input type="text" name="linha[<?= $i ?>][quantidade]" value="<?= (float)$l['quantidade'] ? $e(fameQtd($l['quantidade'])) : '' ?>" inputmode="decimal" style="text-align:center; font-weight:700;" oninput="alterado = true;">
                                <?php if ($q_orc !== null): ?><span class="qtd-orc" title="Quantidade atual no orçamento">orç.: <?= fameQtd($q_orc) ?></span><?php endif; ?>
                            </td>
                            <td class="c-esp" data-label="Especialidade"><?= $selEsp($i, (string)$l['especialidade']) ?></td>
                            <td data-label="Fabricante"><input type="text" class="i-fab" name="linha[<?= $i ?>][fabricante]" value="<?= $e($l['fabricante']) ?>" list="dl_fab" oninput="atualizarLinha(this)"></td>
                            <td data-label="Referência"><input type="text" class="i-ref" name="linha[<?= $i ?>][referencia]" value="<?= $e($l['referencia']) ?>" list="dl_ref" oninput="atualizarLinha(this)" onchange="referenciaEscolhida(this)"></td>
                            <td data-label="Ficha técnica" class="c-fic"><input type="hidden" class="v-idfic" name="linha[<?= $i ?>][id_ficha]" value="<?= (int)($l['id_ficha_tecnica'] ?? 0) ?>"><input type="text" class="i-fic" name="linha[<?= $i ?>][ficha_tecnica]" value="<?= $e($l['ficha_tecnica']) ?>" list="dl_fic" oninput="atualizarLinha(this); fichaEscolhida(this)"><a class="fic-pdf" href="#" target="_blank" title="Abrir a ficha técnica" style="display:none;"><i class="fa-solid fa-file-pdf"></i></a></td>
                            <td class="c-del"><button type="button" class="btn-ins" onclick="novaLinha(this.closest('tr'))" title="Acrescentar linha a seguir a esta"><i class="fa-solid fa-plus"></i></button><button type="button" class="btn-del" onclick="apagarLinha(this)" title="Apagar linha"><i class="fa-solid fa-trash-can"></i></button></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <div class="vazio-lista" id="vazio" style="<?= $linhas ? 'display:none;' : '' ?>"><i class="fa-solid fa-box-open" style="font-size:26px; display:block; margin-bottom:8px;"></i>Sem linhas. Importe do orçamento ou acrescente à mão.</div>
                <div class="acoes-tabela">
                    <button type="button" class="btn-add" onclick="novaLinha()"><i class="fa-solid fa-plus"></i> Acrescentar material</button>
                    <?php if ($orc): ?>
                        <button type="button" class="btn-imp" onclick="abrirImportar()"><i class="fa-solid fa-file-import"></i> Importar do orçamento (<?= count($disponiveis) ?>)</button>
                        <button type="button" class="btn-repor" onclick="document.getElementById('modalRepor').classList.add('show')"><i class="fa-solid fa-rotate-left"></i> Repor linhas do orçamento</button>
                    <?php endif; ?>
                </div>
            </div>

            <!-- JANELA: IMPORTAR DO ORÇAMENTO (dentro do formulário: grava as alterações e acrescenta as linhas) -->
            <div class="modal" id="modalImportar">
                <div class="modal-content">
                    <h3><i class="fa-solid fa-file-import" style="color:#2980b9;"></i> Importar do orçamento</h3>
                    <p style="font-size:12px; color:#7f8c8d; margin:0 0 10px;">Todas as linhas do orçamento que ainda não estão na FAME (já vêm todas escolhidas), por capítulo do orçamento. Ao lado de cada capítulo está a especialidade que as linhas vão ter (pode mudar aqui ou depois, linha a linha). Depois de importar, apague na FAME o que não interessa.</p>
                    <div class="esc-caixa" id="escImportar"><?= fameEscolherHTML($disponiveis, $especialidades) ?></div>
                    <div class="modal-btns">
                        <button type="button" class="btn-cancelar" onclick="fecharImportar()">Cancelar</button>
                        <button type="submit" class="btn-confirmar" id="btnImportar" disabled onclick="document.getElementById('acao').value='importar';">Importar</button>
                    </div>
                </div>
            </div>

            <div class="rodape">
                <button type="submit" class="btn-gravar" onclick="document.getElementById('acao').value=''; desmarcarImportar();"><i class="fa-solid fa-floppy-disk"></i> Gravar</button>
                <button type="button" class="btn-imprimir" onclick="abrirImprimir()"><i class="fa-solid fa-print"></i> Gravar e imprimir</button>
            </div>
        </form>
    </div>

    <!-- JANELA: IMPRIMIR (uma FAME por especialidade) -->
    <div class="modal" id="modalImprimir">
        <div class="modal-content" style="max-width:420px;">
            <h3><i class="fa-solid fa-print"></i> Imprimir FAME</h3>
            <p style="font-size:12px; color:#7f8c8d; margin:0;">Cada especialidade sai como uma FAME própria (com o seu número).</p>
            <div class="op-lista" id="opImprimir"></div>
            <label class="op-fichas"><input type="checkbox" id="chkComFichas" checked> <span><strong>Juntar as fichas técnicas</strong><br><small>Um só PDF: a FAME e, a seguir, as fichas da biblioteca (Anexo 1, 2, …)</small></span></label>
            <div class="modal-btns">
                <button type="button" class="btn-cancelar" onclick="fecharModal('modalImprimir')">Cancelar</button>
                <button type="button" class="btn-confirmar" style="background:#34495e;" onclick="confirmarImprimir()">Gravar e imprimir</button>
            </div>
        </div>
    </div>

    <!-- JANELA: REPOR AS LINHAS DO ORÇAMENTO ORIGINAL -->
    <?php if ($orc): ?>
    <div class="modal" id="modalRepor">
        <div class="modal-content" style="max-width:460px;">
            <h3 style="color:#d35400;"><i class="fa-solid fa-rotate-left"></i> Repor linhas do orçamento</h3>
            <p style="font-size:13px; color:#576574; line-height:1.5; margin:8px 0;">
                A lista volta a ter <b>todas as linhas do orçamento Nº <?= $e($orc['numero_orcamento']) ?></b>, pela ordem e com as quantidades do orçamento, e a especialidade sugerida pelo capítulo.
                As linhas do orçamento que foram apagadas voltam; as alterações de quantidade, especialidade e posição perdem-se.
            </p>
            <p style="font-size:12px; color:#7f8c8d; margin:0 0 12px;">Fabricante, referência e ficha técnica voltam a vir da memória (o que já gravou fica lá). As alterações que ainda não gravou nesta página perdem-se.</p>
            <form method="POST">
                <input type="hidden" name="repor_orcamento" value="1">
                <label class="chk-lbl" style="padding:10px 12px; border:1px solid #e1e5ea; border-radius:10px; width:100%;"><input type="checkbox" name="manter_manuais" value="1" checked> Manter as linhas acrescentadas à mão (ficam no fim)</label>
                <div class="modal-btns">
                    <button type="button" class="btn-cancelar" onclick="fecharModal('modalRepor')">Cancelar</button>
                    <button type="submit" class="btn-confirmar" style="background:#d35400;" onclick="alterado = false;">Repor</button>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <!-- JANELA: OUTRA ESPECIALIDADE -->
    <div class="modal" id="modalOutra">
        <div class="modal-content" style="max-width:420px;">
            <h3><i class="fa-solid fa-plus" style="color:#2ecc71;"></i> Outra especialidade</h3>
            <p style="font-size:12px; color:#7f8c8d; margin:0 0 10px;">Fica gravada para as próximas vezes.</p>
            <input type="text" id="outraNome" maxlength="80" placeholder="Ex.: AVAC, Iluminação de emergência..." onkeydown="if (event.key === 'Enter') { event.preventDefault(); confirmarOutra(); }">
            <div class="modal-btns">
                <button type="button" class="btn-cancelar" onclick="cancelarOutra()">Cancelar</button>
                <button type="button" class="btn-confirmar" style="background:#2ecc71;" onclick="confirmarOutra()">Juntar</button>
            </div>
        </div>
    </div>

    <!-- Lista das especialidades de uma linha (caixas = várias; clicar no nome = só essa) -->
    <div id="espMenu" onclick="event.stopPropagation()"></div>

    <datalist id="dl_un"><?php foreach (['un', 'm', 'ml', 'm2', 'm3', 'kg', 'cx', 'rl', 'pç', 'cj', 'vg'] as $v) echo '<option value="' . $v . '">'; ?></datalist>
    <datalist id="dl_fab"><?php foreach ($l_fab as $v) echo '<option value="' . $e($v) . '">'; ?></datalist>
    <datalist id="dl_ref"><?php foreach ($l_ref as $v) echo '<option value="' . $e($v) . '">'; ?></datalist>
    <datalist id="dl_fic"><?php foreach ($l_fic_bib as $v) echo '<option value="' . $e($v) . '" label="Biblioteca de fichas">'; foreach ($l_fic as $v) echo '<option value="' . $e($v) . '">'; ?></datalist>

    <script>
        const mapaRef = <?= json_encode($mapa_ref, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;
        // Biblioteca de fichas técnicas: nome (minúsculas) → { u: link do ficheiro, m: marca, r: referência }
        const biblioteca = <?= json_encode((object)$bib_js, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS) ?>;
        // Ficha escolhida da biblioteca: mostra o botão para a abrir e preenche fabricante / referência (se vazios)
        function fichaEscolhida(inp, inicial) {
            const td = inp.closest('td'), a = td.querySelector('.fic-pdf'), tr = inp.closest('tr');
            const f = biblioteca[inp.value.trim().replace(/\s+/g, ' ').toLowerCase()];
            if (f && f.u) { a.href = f.u; a.style.display = ''; } else { a.style.display = 'none'; }
            inp.classList.toggle('na-biblioteca', !!f);
            if (!f) { td.querySelector('.v-idfic').value = '0'; return; }
            if (inicial) return;
            const fab = tr.querySelector('.i-fab'), ref = tr.querySelector('.i-ref');
            if (fab.value.trim() === '' && f.m) fab.value = f.m;
            if (ref.value.trim() === '' && f.r) ref.value = f.r;
            atualizarLinha(inp);
        }
        const memoria = <?= json_encode(array_map(fn($c) => ['f' => (string)$c['fabricante'], 'r' => (string)$c['referencia'], 't' => (string)$c['ficha_tecnica']], $catalogo), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;
        let especialidades = <?= json_encode(array_values($especialidades), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;
        let proximo = <?= count($linhas) ?>;
        let alterado = false;
        const corpo = document.getElementById('corpo');

        // ---------- Linha ----------
        function atualizarLinha(el) {
            alterado = true;
            const tr = el.closest('tr');
            const ok = ['.i-fab', '.i-ref', '.i-fic'].every(s => tr.querySelector(s).value.trim() !== '');
            tr.classList.toggle('completa', ok);
            contar();
        }
        function mudouAlt(chk) {
            chk.closest('tr').classList.toggle('alternativa', chk.checked);
            alterado = true;
        }
        function alternarPrint(btn) {
            const tr = btn.closest('tr'), v = tr.querySelector('.v-inc');
            const on = v.value !== '1';
            v.value = on ? '1' : '0';
            btn.classList.toggle('on', on);
            tr.classList.toggle('excluida', !on);
            alterado = true; contar();
        }
        function referenciaEscolhida(inp) {
            const m = mapaRef[inp.value.trim().toLowerCase()];
            if (!m) return;
            const tr = inp.closest('tr');
            const fab = tr.querySelector('.i-fab'), fic = tr.querySelector('.i-fic');
            if (fab.value.trim() === '' && m.f) fab.value = m.f;
            if (fic.value.trim() === '' && m.t) { fic.value = m.t; fichaEscolhida(fic, true); }
            atualizarLinha(inp);
        }
        // ---------- Designação das linhas do orçamento ----------
        function ajustarAltura(ta) { ta.style.height = 'auto'; ta.style.height = (ta.scrollHeight + 2) + 'px'; }
        function normDesig(t) { return t.trim().replace(/\s+/g, ' ').toLowerCase(); }
        function mudouDesignacao(ta) {
            alterado = true;
            ajustarAltura(ta);
            const td = ta.closest('td'), orig = td.querySelector('.v-desig-orig'), b = td.querySelector('.desig-orc');
            if (orig && b) b.style.display = normDesig(ta.value) === normDesig(orig.value) ? 'none' : '';
            memoriaDesignacao(ta);
        }
        // ---------- Unidade (também nas linhas do orçamento; o material a aprovisionar usa a da FAME) ----------
        function mudouUnidade(inp) {
            alterado = true;
            const b = inp.closest('td').querySelector('.un-orc');
            if (b) b.style.display = inp.value.trim().toLowerCase() === (inp.dataset.orc || '').trim().toLowerCase() ? 'none' : '';
        }
        function reporUnidade(btn) {
            const inp = btn.closest('td').querySelector('.i-un');
            inp.value = inp.dataset.orc || '';
            mudouUnidade(inp);
        }
        function reporDesignacao(btn) {
            const td = btn.closest('td'), ta = td.querySelector('.i-desig');
            ta.value = td.querySelector('.v-desig-orig').value;
            mudouDesignacao(ta);
        }
        function memoriaDesignacao(inp) {
            alterado = true;
            const m = memoria[inp.value.trim().replace(/\s+/g, ' ').toLowerCase()];
            if (!m) return;
            const tr = inp.closest('tr');
            [['.i-fab', m.f], ['.i-ref', m.r], ['.i-fic', m.t]].forEach(([s, v]) => { const x = tr.querySelector(s); if (x.value.trim() === '' && v) x.value = v; });
            fichaEscolhida(tr.querySelector('.i-fic'), true);
            atualizarLinha(inp);
        }
        function contar() {
            let t = 0, ok = 0, semEsp = 0;
            const linhas = corpo.querySelectorAll('tr');
            linhas.forEach(tr => {
                const esp = tr.querySelector('.i-esp');
                if (esp.value === '') semEsp++;
                if (tr.querySelector('.v-inc').value !== '1') return;
                t++; if (tr.classList.contains('completa')) ok++;
            });
            document.getElementById('progresso').innerHTML = '<b>' + ok + '</b> de ' + t + ' completos' + (semEsp ? ' · <span style="color:#e67e22;">' + semEsp + ' sem especialidade</span>' : '');
            document.getElementById('vazio').style.display = linhas.length ? 'none' : '';
        }

        // ---------- Especialidade(s) ----------
        // Cada linha tem um campo escondido .i-esp com as especialidades juntas por ";" (ex.: "Elétrico;ITED")
        const escH = t => String(t).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/"/g, '&quot;');
        function espLista(inp) { return inp.value.split(';').map(x => x.trim()).filter(x => x !== ''); }
        function espDefinir(inp, lista) {
            const vistos = [];
            lista.forEach(x => { if (x && !vistos.some(v => v.toLowerCase() === x.toLowerCase())) vistos.push(x); });
            inp.value = vistos.join(';');
            const btn = inp.parentNode.querySelector('.esp-btn');
            btn.querySelector('.esp-txt').textContent = vistos.length ? vistos.join(' + ') : '—';
            btn.classList.toggle('vazio', vistos.length === 0);
        }
        let espMenuInp = null;   // campo .i-esp da linha cuja lista está aberta
        function abrirEspMenu(btn) {
            const menu = document.getElementById('espMenu');
            const inp = btn.parentNode.querySelector('.i-esp');
            if (menu.classList.contains('show') && espMenuInp === inp) { fecharEspMenu(); return; }
            espMenuInp = inp;
            const atuais = espLista(inp).map(x => x.toLowerCase());
            let h = '<div class="em-dica">Caixa = várias especialidades · clicar no nome = só essa</div>';
            especialidades.forEach((x, k) => {
                h += '<div class="em-op"><input type="checkbox" id="em_' + k + '" data-v="' + escH(x) + '"' + (atuais.includes(x.toLowerCase()) ? ' checked' : '') + ' onchange="espMenuCaixa()">'
                   + '<span class="em-txt" onclick="espMenuSo(this.previousElementSibling.dataset.v)">' + escH(x) + '</span></div>';
            });
            h += '<div class="em-rodape"><button type="button" onclick="pedirOutra(\'linha\')">+ Outra…</button><button type="button" class="em-limpar" onclick="espMenuSo(\'\')">Nenhuma</button></div>';
            menu.innerHTML = h;
            const r = btn.getBoundingClientRect();
            menu.classList.add('show');
            const largura = Math.max(r.width, 220);
            let x = r.left + window.scrollX;
            if (x + largura > window.scrollX + document.documentElement.clientWidth - 8) x = window.scrollX + document.documentElement.clientWidth - largura - 8;
            menu.style.minWidth = largura + 'px';
            menu.style.left = Math.max(8, x) + 'px';
            let y = r.bottom + window.scrollY + 4;
            if (r.bottom + menu.offsetHeight > window.innerHeight - 70 && r.top > menu.offsetHeight + 60) y = r.top + window.scrollY - menu.offsetHeight - 4;
            menu.style.top = y + 'px';
        }
        function fecharEspMenu() { document.getElementById('espMenu').classList.remove('show'); espMenuInp = null; }
        // Caixas: várias especialidades (pela ordem da lista)
        function espMenuCaixa() {
            if (!espMenuInp) return;
            const lista = [...document.querySelectorAll('#espMenu input[type=checkbox]')].filter(c => c.checked).map(c => c.dataset.v);
            espDefinir(espMenuInp, lista);
            alterado = true; contar();
        }
        // Nome: fica só essa (ou nenhuma)
        function espMenuSo(v) {
            if (!espMenuInp) return;
            espDefinir(espMenuInp, v ? [v] : []);
            destacar([espMenuInp.closest('tr')]);
            fecharEspMenu(); alterado = true; contar();
        }
        document.addEventListener('click', e => { if (!e.target.closest('.esp-btn')) fecharEspMenu(); });
        document.addEventListener('keydown', e => { if (e.key === 'Escape') fecharEspMenu(); });
        window.addEventListener('resize', fecharEspMenu);

        // "Outra…": a partir da lista de uma linha ('linha') ou da barra das linhas escolhidas (<select id="selEsp">)
        let espPendente = null;
        function pedirOutra(origem) {
            espPendente = (origem === 'linha') ? { linha: espMenuInp } : { barra: true };
            document.getElementById('espMenu').classList.remove('show');
            document.getElementById('outraNome').value = '';
            document.getElementById('modalOutra').classList.add('show');
            setTimeout(() => document.getElementById('outraNome').focus(), 50);
        }
        function juntarOpcaoEsp(nome) {
            const ja = especialidades.find(x => x.toLowerCase() === nome.toLowerCase());
            if (ja) return ja;
            especialidades.push(nome);
            const opt = (v, t) => { const o = document.createElement('option'); o.value = v; o.textContent = t; return o; };
            document.getElementById('selEspSo').appendChild(opt('so:' + nome, nome));
            document.getElementById('selEspAdd').appendChild(opt('add:' + nome, '+ ' + nome));
            const fe = document.getElementById('filtro_esp'); fe.insertBefore(opt(nome, nome), fe.querySelector('option[value=""]'));
            return nome;
        }
        function confirmarOutra() {
            const nome = document.getElementById('outraNome').value.trim();
            if (!nome) { document.getElementById('outraNome').focus(); return; }
            const final = juntarOpcaoEsp(nome);
            if (espPendente && espPendente.barra) aplicarEsp('add', final);
            else if (espPendente && espPendente.linha) espDefinir(espPendente.linha, espLista(espPendente.linha).concat([final]));
            espPendente = null;
            espMenuInp = null;
            document.getElementById('modalOutra').classList.remove('show');
            document.getElementById('selEsp').value = '';
            alterado = true; contar();
        }
        function cancelarOutra() {
            espPendente = null; espMenuInp = null;
            document.getElementById('selEsp').value = '';
            document.getElementById('modalOutra').classList.remove('show');
            contar();
        }

        // ---------- Escolher linhas (uma ou várias) ----------
        function escolhidas() { return [...corpo.querySelectorAll('tr')].filter(tr => tr.querySelector('.chk-sel').checked); }
        function mudouSel() {
            const sel = escolhidas();
            corpo.querySelectorAll('tr').forEach(tr => tr.classList.toggle('selecionada', tr.querySelector('.chk-sel').checked));
            const barra = document.getElementById('selBarra');
            barra.classList.toggle('vazia', sel.length === 0);
            document.getElementById('selInfo').textContent = sel.length ? sel.length + ' linha(s) escolhida(s)' : 'Escolha uma ou várias linhas na caixa à esquerda';
            const visiveis = [...corpo.querySelectorAll('tr')].filter(tr => tr.style.display !== 'none');
            const st = document.getElementById('selTodas');
            st.checked = visiveis.length > 0 && visiveis.every(tr => tr.querySelector('.chk-sel').checked);
            st.indeterminate = !st.checked && sel.length > 0;
        }
        function selecionarTodas(v) {
            corpo.querySelectorAll('tr').forEach(tr => { if (tr.style.display !== 'none') tr.querySelector('.chk-sel').checked = v; });
            mudouSel();
        }
        function limparSel() { corpo.querySelectorAll('.chk-sel').forEach(c => c.checked = false); mudouSel(); }
        function destacar(trs) { trs.forEach(tr => { tr.classList.add('destaque'); setTimeout(() => tr.classList.remove('destaque'), 600); }); }

        // Sobe / desce as linhas escolhidas uma posição (em bloco), ou leva-as para o início / fim
        function moverSel(dir) {
            const sel = escolhidas();
            if (!sel.length) return;
            if (dir === 'topo') { [...sel].reverse().forEach(tr => corpo.prepend(tr)); }
            else if (dir === 'fim') { sel.forEach(tr => corpo.append(tr)); }
            else if (dir < 0) {
                sel.forEach(tr => {
                    let ant = tr.previousElementSibling;
                    while (ant && ant.style.display === 'none') ant = ant.previousElementSibling;
                    if (ant && !ant.querySelector('.chk-sel').checked) ant.before(tr);
                });
            } else {
                [...sel].reverse().forEach(tr => {
                    let seg = tr.nextElementSibling;
                    while (seg && seg.style.display === 'none') seg = seg.nextElementSibling;
                    if (seg && !seg.querySelector('.chk-sel').checked) seg.after(tr);
                });
            }
            destacar(sel);
            sel[0].scrollIntoView({ block: 'nearest', behavior: 'smooth' });
            alterado = true;
        }
        function aplicarEspSel(sel) {
            const v = sel.value;
            if (v === '__outra') { pedirOutra('barra'); return; }
            if (v === '') return;
            if (v === '__limpar') aplicarEsp('so', '');
            else aplicarEsp(v.slice(0, v.indexOf(':')), v.slice(v.indexOf(':') + 1));
            sel.value = '';
        }
        // modo 'so' = as linhas escolhidas passam a ter só esta especialidade; 'add' = junta-a às que já têm
        function aplicarEsp(modo, v) {
            const sel = escolhidas();
            if (!sel.length) return;
            sel.forEach(tr => {
                const inp = tr.querySelector('.i-esp');
                espDefinir(inp, modo === 'add' ? espLista(inp).concat([v]) : (v ? [v] : []));
            });
            destacar(sel); alterado = true; contar();
        }
        function apagarSel() {
            const sel = escolhidas();
            if (!sel.length) return;
            if (!confirm('Apagar ' + sel.length + ' linha(s)? As que vieram do orçamento voltam a ficar disponíveis para importar.')) return;
            sel.forEach(tr => tr.remove());
            mudouSel(); alterado = true; contar();
        }

        // ---------- Acrescentar / apagar ----------
        function campoEsp(i, valor) {
            const lista = (valor || '').split(';').map(x => x.trim()).filter(x => x);
            return '<div class="esp-multi"><input type="hidden" class="i-esp" name="linha[' + i + '][especialidade]" value="' + escH(lista.join(';')) + '">'
                 + '<button type="button" class="esp-btn' + (lista.length ? '' : ' vazio') + '" onclick="abrirEspMenu(this)"><span class="esp-txt">' + (lista.length ? escH(lista.join(' + ')) : '—') + '</span><i class="fa-solid fa-caret-down"></i></button></div>';
        }
        // depoisDe = linha onde se carregou no "+" (a nova fica logo a seguir, com a mesma especialidade);
        // sem linha = botão "Acrescentar material" (vai para o fim)
        function novaLinha(depoisDe) {
            const i = proximo++;
            // Nova linha fica com a especialidade da linha de cima ou, no fim, a do filtro (se estiver escolhida uma)
            let fe = document.getElementById('filtro_esp').value;
            if (depoisDe) fe = depoisDe.querySelector('.i-esp').value || '*';   // pode ser "Elétrico;ITED"
            const tr = document.createElement('tr');
            tr.className = 'linha';
            tr.innerHTML =
                '<td class="c-sel"><input type="checkbox" class="chk-sel" onchange="mudouSel()"></td>' +
                '<td class="c-inc"><input type="hidden" class="v-inc" name="linha[' + i + '][incluir]" value="1"><button type="button" class="tg-print on" onclick="alternarPrint(this)" title="Sai no PDF da FAME"><i class="fa-solid fa-print"></i></button></td>' +
                '<td class="c-art" data-label="Ref. / Art."><input type="text" class="i-art" name="linha[' + i + '][artigo]" maxlength="120" oninput="alterado = true;"></td>' +
                '<td class="c-desig"><input type="hidden" name="linha[' + i + '][origem]" value="manual"><input type="hidden" name="linha[' + i + '][chave]" value="">' +
                '<input type="text" class="i-desig" name="linha[' + i + '][designacao]" placeholder="Designação do material" oninput="memoriaDesignacao(this)"></td>' +
                '<td class="c-alt" data-label="Alternativa"><label class="alt-lbl" title="Material proposto em alternativa"><input type="checkbox" class="i-alt" name="linha[' + i + '][alternativa]" value="1" onchange="mudouAlt(this)"><span>Alternativa</span></label></td>' +
                '<td class="c-un" data-label="Un."><input type="text" class="i-un" name="linha[' + i + '][unidade]" value="un" maxlength="10" list="dl_un" style="text-align:center;" oninput="mudouUnidade(this)"></td>' +
                '<td class="c-qtd" data-label="Qtd."><input type="text" name="linha[' + i + '][quantidade]" inputmode="decimal" style="text-align:center; font-weight:700;"></td>' +
                '<td class="c-esp" data-label="Especialidade">' + campoEsp(i, fe !== '*' ? fe : '') + '</td>' +
                '<td data-label="Fabricante"><input type="text" class="i-fab" name="linha[' + i + '][fabricante]" list="dl_fab" oninput="atualizarLinha(this)"></td>' +
                '<td data-label="Referência"><input type="text" class="i-ref" name="linha[' + i + '][referencia]" list="dl_ref" oninput="atualizarLinha(this)" onchange="referenciaEscolhida(this)"></td>' +
                '<td data-label="Ficha técnica" class="c-fic"><input type="hidden" class="v-idfic" name="linha[' + i + '][id_ficha]" value="0"><input type="text" class="i-fic" name="linha[' + i + '][ficha_tecnica]" list="dl_fic" oninput="atualizarLinha(this); fichaEscolhida(this)"><a class="fic-pdf" href="#" target="_blank" title="Abrir a ficha técnica" style="display:none;"><i class="fa-solid fa-file-pdf"></i></a></td>' +
                '<td class="c-del"><button type="button" class="btn-ins" onclick="novaLinha(this.closest(\'tr\'))" title="Acrescentar linha a seguir a esta"><i class="fa-solid fa-plus"></i></button><button type="button" class="btn-del" onclick="apagarLinha(this)" title="Apagar linha"><i class="fa-solid fa-trash-can"></i></button></td>';
            if (depoisDe) { depoisDe.after(tr); destacar([tr]); } else corpo.appendChild(tr);
            tr.querySelector('.c-desig input[type=text]').focus();
            alterado = true; contar();
        }
        function apagarLinha(btn) {
            const tr = btn.closest('tr');
            const orc = tr.querySelector('input[name$="[origem]"]').value === 'orc';
            if (!confirm(orc ? 'Apagar esta linha? O material volta a ficar disponível para importar.' : 'Apagar esta linha?')) return;
            tr.remove(); mudouSel(); alterado = true; contar();
        }

        // ---------- Filtros ----------
        function filtrar() {
            const q = document.getElementById('pesquisa').value.trim().toLowerCase();
            const fe = document.getElementById('filtro_esp').value;
            const so = document.getElementById('so_falta').checked;
            corpo.querySelectorAll('tr').forEach(tr => {
                const txt = tr.textContent.toLowerCase() + ' ' + [...tr.querySelectorAll('input[type=text], textarea')].map(i => i.value.toLowerCase()).join(' ');
                const esps = espLista(tr.querySelector('.i-esp'));
                const okEsp = fe === '*' || (fe === '' ? esps.length === 0 : esps.some(x => x.toLowerCase() === fe.toLowerCase()));
                const mostra = (!q || txt.includes(q)) && okEsp && (!so || (!tr.classList.contains('completa') && tr.querySelector('.v-inc').value === '1'));
                tr.style.display = mostra ? '' : 'none';
            });
            mudouSel();
        }

        // ---------- Importar do orçamento ----------
        // Abre com TODAS as linhas do orçamento escolhidas (pode desmarcar as que não quer)
        function abrirImportar() {
            const lk = document.querySelector('#escImportar .esc-link');
            if (lk) escTodos(lk, true);
            document.getElementById('modalImportar').classList.add('show');
        }
        function fecharImportar() { desmarcarImportar(); document.getElementById('modalImportar').classList.remove('show'); }
        function desmarcarImportar() { document.querySelectorAll('#escImportar .esc-chk').forEach(c => c.checked = false); }
        document.getElementById('escImportar').addEventListener('esc-mudou', e => {
            const b = document.getElementById('btnImportar');
            b.disabled = e.detail === 0;
            b.textContent = e.detail ? 'Importar ' + e.detail + ' linha(s)' : 'Importar';
        });

        // ---------- Imprimir ----------
        function abrirImprimir() {
            const cont = {};
            corpo.querySelectorAll('tr').forEach(tr => {
                if (tr.querySelector('.v-inc').value !== '1') return;
                const esps = espLista(tr.querySelector('.i-esp'));
                (esps.length ? esps : ['Geral']).forEach(esp => { cont[esp] = (cont[esp] || 0) + 1; });   // sai em cada FAME
            });
            const nomes = Object.keys(cont);
            let h = '<label><input type="radio" name="op_imp" value="" checked> Todas as especialidades <small>' + nomes.length + ' FAME(s)</small></label>';
            nomes.forEach(n => { h += '<label><input type="radio" name="op_imp" value="' + n.replace(/"/g, '&quot;') + '"> ' + n.replace(/</g, '&lt;') + ' <small>' + cont[n] + ' linha(s)</small></label>'; });
            document.getElementById('opImprimir').innerHTML = h;
            document.getElementById('modalImprimir').classList.add('show');
        }
        function confirmarImprimir() {
            const op = document.querySelector('input[name=op_imp]:checked');
            document.getElementById('imprimir_esp').value = op ? op.value : '';
            document.getElementById('com_fichas').value = document.getElementById('chkComFichas').checked ? '1' : '';
            document.getElementById('acao').value = 'imprimir';
            desmarcarImportar();
            alterado = false;
            document.getElementById('formFame').submit();
        }

        function fecharModal(id) { document.getElementById(id).classList.remove('show'); }
        window.addEventListener('click', e => {
            if (e.target.id === 'modalImportar') fecharImportar();
            if (e.target.id === 'modalImprimir') fecharModal('modalImprimir');
            if (e.target.id === 'modalOutra') cancelarOutra();
            if (e.target.id === 'modalRepor') fecharModal('modalRepor');
        });

        document.getElementById('formFame').addEventListener('submit', () => { alterado = false; });
        window.addEventListener('beforeunload', e => { if (alterado) { e.preventDefault(); e.returnValue = ''; } });
        contar();
        document.querySelectorAll('.i-fic').forEach(i => fichaEscolhida(i, true));
        // Caixas da designação com a altura do texto (e ao mudar o tamanho do ecrã)
        const ajustarTodas = () => document.querySelectorAll('textarea.i-desig').forEach(ajustarAltura);
        ajustarTodas();
        // Ref. / Art. compridas (vários artigos): texto completo ao passar o rato
        document.querySelectorAll('.i-art').forEach(i => { if (!i.title) i.title = i.value; });
        window.addEventListener('resize', ajustarTodas);

        // Menu do utilizador (toque em ecrãs táteis)
        (function () {
            const menu = document.querySelector('.user-menu'); if (!menu) return;
            const btn = menu.querySelector('.user-btn');
            btn.addEventListener('click', function (e) { e.stopPropagation(); menu.classList.toggle('aberto'); });
            document.addEventListener('click', function () { menu.classList.remove('aberto'); });
        })();
    </script>
</body>
</html>
