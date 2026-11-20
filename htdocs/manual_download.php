<?php
// Captura qualquer saída acidental (espaços, BOM, avisos) que possa vir de db.php/functions.php
// ANTES de sabermos se vamos enviar um ficheiro. Sem isto, essa saída "escapa" para o browser
// antes dos header() mais abaixo, o que faz o PHP ignorar esses header() em silêncio (sem erro
// visível quando display_errors está desligado, como é normal em produção) — e o download
// simplesmente não acontece, sem mensagem nenhuma.
ob_start();
// Em muitos alojamentos partilhados (incluindo o InfinityFree) a compressão automática de saída
// pode desfasar o Content-Length do tamanho real enviado e corromper o download; desligamo-la
// explicitamente para este pedido.
@ini_set('zlib.output_compression', 'Off');

// Vários ficheiros por manual (out/2026):
//   manual_download.php?f=ID_FICHEIRO   → um ficheiro da tabela manuais_ficheiros
//   manual_download.php?id=ID_MANUAL    → (links antigos) o 1.º ficheiro do manual
//   …&ver=1                          → pré-visualizar (imagens e PDF abrem no browser)
// Os ficheiros continuam na pasta manuais_ficheiros/ (protegida por .htaccess).

session_start();
require_once 'db.php';
// REGRA DE OURO 1: Validação de sessão — qualquer utilizador autenticado pode descarregar
// manuais/procedimentos, tal como em manuais.php.
if (!isset($_SESSION['user_id'])) {
    ob_end_clean();
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

$pasta_manuais = __DIR__ . '/manuais_ficheiros/';

// Devolve [titulo do manual, nome original, nome no servidor] do ficheiro pedido, ou null
function ficheiroPedido($pdo, $id_ficheiro, $id_manual) {
    try {
        if ($id_ficheiro) {
            $st = $pdo->prepare("SELECT f.nome_original, f.nome_servidor, m.titulo FROM manuais_ficheiros f JOIN manuais m ON m.id = f.id_manual WHERE f.id = ?");
            $st->execute([$id_ficheiro]);
            $r = $st->fetch(PDO::FETCH_ASSOC);
            if ($r) return $r;
        }
        if ($id_manual) {
            $st = $pdo->prepare("SELECT f.nome_original, f.nome_servidor, m.titulo FROM manuais_ficheiros f JOIN manuais m ON m.id = f.id_manual WHERE f.id_manual = ? ORDER BY f.id LIMIT 1");
            $st->execute([$id_manual]);
            $r = $st->fetch(PDO::FETCH_ASSOC);
            if ($r) return $r;
        }
    } catch (Exception $e) {}   // tabela nova ainda não criada (manuais.php ainda não foi aberto)
    // Manual antigo, com o ficheiro ainda nas colunas da tabela manuais
    if ($id_manual) {
        $st = $pdo->prepare("SELECT nome_ficheiro_original AS nome_original, nome_ficheiro_servidor AS nome_servidor, titulo FROM manuais WHERE id = ? AND nome_ficheiro_servidor <> ''");
        $st->execute([$id_manual]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if ($r) return $r;
    }
    return null;
}

$id_ficheiro = filter_input(INPUT_GET, 'f', FILTER_VALIDATE_INT);
$id_manual = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

// --- MODO DE DIAGNÓSTICO (só Admin/Administrativo) ---
// manual_download.php?f=X&debug=1 (ou ?id=X&debug=1) mostra, em texto simples, o que se passa com esse
// ficheiro (sem o enviar), para ajudar a perceber porque é que o descarregamento não está a funcionar.
if ($is_admin && isset($_GET['debug'])) {
    ob_end_clean();
    header('Content-Type: text/plain; charset=utf-8');
    echo "=== Diagnóstico de descarregamento de manual ===\n\n";
    echo "Ficheiro pedido (f): " . var_export($id_ficheiro, true) . "\n";
    echo "Manual pedido (id): " . var_export($id_manual, true) . "\n";
    $r = ficheiroPedido($pdo, $id_ficheiro, $id_manual);
    if (!$r) {
        echo "-> Não foi encontrado nenhum ficheiro para este pedido.\n";
        exit;
    }
    echo "Manual: {$r['titulo']}\n";
    echo "Nome do ficheiro (original): {$r['nome_original']}\n";
    echo "Nome do ficheiro (servidor): {$r['nome_servidor']}\n";
    echo "\nPasta de manuais (caminho no servidor): $pasta_manuais\n";
    echo "Essa pasta existe? " . (is_dir($pasta_manuais) ? "SIM" : "NÃO — este é provavelmente o problema.") . "\n";
    $caminho_teste = $pasta_manuais . $r['nome_servidor'];
    echo "Caminho completo esperado do ficheiro: $caminho_teste\n";
    echo "Esse ficheiro existe no disco? " . (is_file($caminho_teste) ? "SIM" : "NÃO — este é provavelmente o problema.") . "\n";
    if (is_file($caminho_teste)) {
        echo "Tamanho no disco: " . filesize($caminho_teste) . " bytes\n";
        echo "Permissões de leitura OK? " . (is_readable($caminho_teste) ? "SIM" : "NÃO — este é provavelmente o problema.") . "\n";
    }
    echo "\nVersão do PHP: " . phpversion() . "\n";
    echo "Extensão fileinfo disponível? " . (function_exists('mime_content_type') ? "SIM" : "NÃO (sem problema, há um valor por omissão)") . "\n";
    echo "\nSe tudo acima disser SIM/OK e mesmo assim o botão Descarregar não fizer nada, tira uma\n";
    echo "captura de ecrã desta página e envia-a.\n";
    exit;
}

if (!$id_ficheiro && !$id_manual) {
    ob_end_clean();
    http_response_code(400);
    die("Pedido inválido.");
}

$ficheiro = ficheiroPedido($pdo, $id_ficheiro, $id_manual);
if (!$ficheiro) {
    ob_end_clean();
    http_response_code(404);
    die("Ficheiro não encontrado.");
}

$caminho_ficheiro = $pasta_manuais . $ficheiro['nome_servidor'];

// Proteção adicional: garante que o caminho final continua dentro da pasta de manuais
// (evita qualquer tentativa de path traversal através do nome guardado na base de dados).
$caminho_real = realpath($caminho_ficheiro);
$pasta_real = realpath($pasta_manuais);
if ($caminho_real === false || $pasta_real === false || strpos($caminho_real, $pasta_real) !== 0) {
    ob_end_clean();
    http_response_code(404);
    die("Ficheiro não encontrado. (Se for administrador, acrescente &debug=1 ao URL para mais detalhes.)");
}

if (!is_file($caminho_real) || !is_readable($caminho_real)) {
    ob_end_clean();
    http_response_code(404);
    die("Ficheiro não encontrado ou sem permissões de leitura no servidor. (Se for administrador, acrescente &debug=1 ao URL para mais detalhes.)");
}

// Pré-visualização (?ver=1): imagens e PDF abrem no browser em vez de descarregar (não fica no log)
$ext_ficheiro = strtolower(pathinfo((string)($ficheiro['nome_original'] ?: $ficheiro['nome_servidor']), PATHINFO_EXTENSION));
$mimes_ver = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp', 'pdf' => 'application/pdf'];
$pre_visualizar = isset($_GET['ver']) && isset($mimes_ver[$ext_ficheiro]);

if (!$pre_visualizar) {
    registrarLog($pdo, "Descarregou \"{$ficheiro['nome_original']}\" do manual \"{$ficheiro['titulo']}\"", "Manuais");
}

$nome_para_download = $ficheiro['nome_original'] ?: $ficheiro['nome_servidor'];
$tamanho = filesize($caminho_real);

$mime = 'application/octet-stream';
if (function_exists('mime_content_type')) {
    $mime_detetado = @mime_content_type($caminho_real);
    if ($mime_detetado) {
        $mime = $mime_detetado;
    }
}

if ($pre_visualizar) $mime = $mimes_ver[$ext_ficheiro];

// Descarta tudo o que possa ter sido capturado no buffer — a partir daqui a resposta é só o ficheiro.
while (ob_get_level() > 0) {
    ob_end_clean();
}

if (headers_sent($ficheiro_erro, $linha_erro)) {
    die("Não foi possível iniciar o descarregamento: já tinha sido enviada informação para o browser a partir de $ficheiro_erro, linha $linha_erro.");
}

// Nome com acentos: versão simples + versão UTF-8 (RFC 5987) para os browsers atuais
$nome_simples = str_replace(['"', "\r", "\n"], '', $nome_para_download);
$nome_ascii = function_exists('iconv') ? @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $nome_simples) : '';
$nome_ascii = preg_replace('/[^\x20-\x7E]/', '_', ($nome_ascii !== false && $nome_ascii !== '') ? $nome_ascii : $nome_simples);
$nome_ascii = str_replace('"', '', $nome_ascii);

if ($pre_visualizar) {
    header('Content-Type: ' . $mime);
    header('Content-Disposition: inline; filename="' . $nome_ascii . '"; filename*=UTF-8\'\'' . rawurlencode($nome_simples));
    header('Content-Length: ' . $tamanho);
    header('Cache-Control: private, max-age=3600');   // miniaturas não voltam a ser pedidas a cada abertura da página
    header('X-Content-Type-Options: nosniff');
} else {
    header('Content-Description: File Transfer');
    header('Content-Type: ' . $mime);
    header('Content-Disposition: attachment; filename="' . $nome_ascii . '"; filename*=UTF-8\'\'' . rawurlencode($nome_simples));
    header('Content-Transfer-Encoding: binary');
    header('Content-Length: ' . $tamanho);
    header('Cache-Control: private, no-cache, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');
}

readfile($caminho_real);
exit;
