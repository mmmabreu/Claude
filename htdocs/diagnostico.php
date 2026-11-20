<?php
// =====================================================================
// DIAGNÓSTICO — mostra o erro que está por trás do "HTTP ERROR 500"
// Abrir no browser: mab.free.nf/diagnostico.php
// Não altera nada no site (só lê e cria as tabelas da FAME, se faltarem).
// APAGAR ESTE FICHEIRO DO SERVIDOR DEPOIS DE RESOLVIDO.
// =====================================================================
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

// Mostra também os erros fatais (que normalmente dão a página branca / erro 500)
register_shutdown_function(function () {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        echo '<div style="background:#fdeded;border:1px solid #e74c3c;padding:12px;margin:10px 0;border-radius:8px;">'
           . '<b>ERRO FATAL:</b> ' . htmlspecialchars($e['message']) . '<br><b>Ficheiro:</b> ' . htmlspecialchars($e['file']) . ' <b>linha</b> ' . $e['line'] . '</div>';
    }
});

function ok($t)  { echo '<div style="color:#27ae60;">✔ ' . htmlspecialchars($t) . '</div>'; }
function mal($t) { echo '<div style="color:#e74c3c;font-weight:bold;">✘ ' . htmlspecialchars($t) . '</div>'; }

echo '<!DOCTYPE html><meta charset="utf-8"><title>Diagnóstico</title><body style="font-family:system-ui;padding:20px;max-width:900px;">';
echo '<h2>Diagnóstico do site</h2>';
echo '<p>PHP ' . PHP_VERSION . '</p>';

// 1. Ficheiros e erros de sintaxe (cada ficheiro é lido, não executado)
echo '<h3>1. Ficheiros</h3>';
$ficheiros = ['db.php', 'functions.php', 'index.php', 'obras.php', 'obra_fame_comum.php', 'obra_fame.php', 'obra_fame_form.php',
              'obra_fame_pdf.php', 'obra_material.php', 'obra_material_pdf.php', 'orcamento_form.php', 'levantamento_def.php',
              'visita_form.php', 'visita_pdf.php'];
foreach ($ficheiros as $f) {
    if (!file_exists($f)) { mal("$f não existe no servidor"); continue; }
    $conteudo = file_get_contents($f);
    $info = $f . ' (' . number_format(strlen($conteudo), 0, ',', '.') . ' bytes, alterado ' . date('d/m/Y H:i', filemtime($f)) . ')';
    if (substr($conteudo, 0, 3) === "\xEF\xBB\xBF") { mal("$info começa com BOM (caracteres invisíveis) — gravar como UTF-8 sem BOM"); continue; }
    if (strlen($conteudo) < 50) { mal("$info está vazio ou cortado"); continue; }
    if (trim(substr($conteudo, -200)) === '' ) { mal("$info parece cortado no fim"); continue; }
    // Verificação de sintaxe sem executar
    try {
        $tokens = @token_get_all($conteudo, TOKEN_PARSE);
        ok($info);
    } catch (ParseError $e) {
        mal("$info — erro de sintaxe na linha " . $e->getLine() . ': ' . $e->getMessage());
    }
}

// 2. Base de dados
echo '<h3>2. Base de dados</h3>';
try {
    require_once 'db.php';
    if (!isset($pdo)) { mal('db.php não criou a ligação $pdo'); }
    else {
        ok('Ligação à base de dados');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }
} catch (Throwable $e) {
    mal('db.php: ' . $e->getMessage());
}

// 3. FAME / material
if (isset($pdo) && file_exists('obra_fame_comum.php')) {
    echo '<h3>3. FAME e material a aprovisionar</h3>';
    try {
        require_once 'obra_fame_comum.php';
        ok('obra_fame_comum.php carregado');
        fameCriarTabelas($pdo);
        ok('Tabelas da FAME criadas / atualizadas');
        foreach (['obras_fames', 'obras_fame', 'fame_catalogo'] as $t) {
            $n = $pdo->query("SELECT COUNT(*) FROM `$t`")->fetchColumn();
            ok("Tabela $t: $n registo(s)");
        }
        $n = $pdo->query("SELECT COUNT(*) FROM obras o LEFT JOIN clientes c ON o.id_cliente = c.id")->fetchColumn();
        $pdo->query("SELECT o.id, (SELECT COUNT(*) FROM obras_fame f WHERE f.id_obra = o.id AND f.incluir = 1) AS n FROM obras o LIMIT 1")->fetchAll();
        ok("Consulta da lista de obras ($n obras)");
        $o = $pdo->query("SELECT id FROM obras ORDER BY id DESC LIMIT 1")->fetchColumn();
        if ($o) {
            $obra = fameObra($pdo, $o);
            $orc = fameOrcamentoDaObra($pdo, $obra);
            $g = fameMaterialAprovisionar($pdo, $o, $orc['id'] ?? 0);
            ok('Material a aprovisionar da obra ' . $o . ': ' . count($g) . ' grupo(s)' . ($orc ? ' · orçamento ' . $orc['numero_orcamento'] : ' · sem orçamento'));
        }
    } catch (Throwable $e) {
        mal(get_class($e) . ': ' . $e->getMessage() . ' (' . basename($e->getFile()) . ' linha ' . $e->getLine() . ')');
    }
}

echo '<p style="margin-top:24px;color:#7f8c8d;">Copie esta página (ou tire um print) e envie. Depois apague o diagnostico.php do servidor.</p></body>';
