<?php
/**
 * fichas_tecnicas_lib.php
 * Biblioteca de fichas técnicas e certificados de conformidade (pasta fichas_tecnicas/) e ligação aos artigos da
 * tabela de preços. Cada registo é um produto (marca + modelo / referência) com ficha técnica e/ou certificado
 * (nenhum dos dois é obrigatório, mas tem de haver pelo menos um).
 * Usado por fichas_tecnicas.php e, mais tarde, pela tabela de preços, orçamentos e FAME.
 *
 * Base de dados (criada / acrescentada automaticamente, sem mexer no que já existe):
 *   fichas_tecnicas  -> uma linha por produto (marca, modelo, referência, ficha técnica + versões anteriores,
 *                       certificado de conformidade + nº + validade + versões anteriores)
 *   tabela_precos    -> + coluna marca  + coluna id_ficha_tecnica
 */

if (!function_exists('ft_migrar')) {

define('FT_PASTA', 'fichas_tecnicas/');
define('FT_MAX_BYTES', 10 * 1024 * 1024);   // 10 MB (limite do alojamento)

function ft_migrar($pdo) {
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS fichas_tecnicas (
            id INT AUTO_INCREMENT PRIMARY KEY,
            marca VARCHAR(100) NOT NULL DEFAULT '',
            modelo VARCHAR(150) NOT NULL DEFAULT '',
            referencia VARCHAR(100) NOT NULL DEFAULT '',
            descricao VARCHAR(255) NOT NULL DEFAULT '',
            ficheiro VARCHAR(255) NOT NULL DEFAULT '',
            nome_original VARCHAR(255) NOT NULL DEFAULT '',
            tamanho INT NOT NULL DEFAULT 0,
            versoes_json MEDIUMTEXT NULL,
            ativo TINYINT(1) NOT NULL DEFAULT 1,
            carregado_em DATETIME NULL,
            carregado_por VARCHAR(100) NOT NULL DEFAULT '',
            atualizado_em DATETIME NULL,
            INDEX (marca), INDEX (ativo)
        ) DEFAULT CHARSET=utf8mb4");
    } catch (Exception $e) {}
    // Certificado de conformidade (por produto: marca / referência)
    try {
        $novas = [
            'cert_ficheiro'      => "VARCHAR(255) NOT NULL DEFAULT ''",
            'cert_nome_original' => "VARCHAR(255) NOT NULL DEFAULT ''",
            'cert_tamanho'       => "INT NOT NULL DEFAULT 0",
            'cert_versoes_json'  => "MEDIUMTEXT NULL",
            'cert_numero'        => "VARCHAR(100) NOT NULL DEFAULT ''",
            'cert_validade'      => "DATE NULL",
        ];
        foreach ($novas as $col => $def) {
            if (!$pdo->query("SHOW COLUMNS FROM fichas_tecnicas LIKE '$col'")->fetch()) $pdo->exec("ALTER TABLE fichas_tecnicas ADD COLUMN $col $def");
        }
    } catch (Exception $e) {}
    try {
        if (!$pdo->query("SHOW COLUMNS FROM tabela_precos LIKE 'marca'")->fetch())
            $pdo->exec("ALTER TABLE tabela_precos ADD COLUMN marca VARCHAR(100) NULL");
        if (!$pdo->query("SHOW COLUMNS FROM tabela_precos LIKE 'id_ficha_tecnica'")->fetch())
            $pdo->exec("ALTER TABLE tabela_precos ADD COLUMN id_ficha_tecnica INT NULL");
    } catch (Exception $e) {}
    if (!is_dir(FT_PASTA)) @mkdir(FT_PASTA, 0755, true);
    // Impede listar a pasta no browser (os ficheiros continuam acessíveis pelo link)
    if (is_dir(FT_PASTA) && !is_file(FT_PASTA . 'index.html')) @file_put_contents(FT_PASTA . 'index.html', '');
}

// Coluna que identifica cada artigo da tabela de preços (normalmente "id"; senão "referencia")
function ft_chave_artigo($pdo) {
    static $chave = null;
    if ($chave !== null) return $chave;
    $chave = 'referencia';
    try {
        $k = $pdo->query("SHOW KEYS FROM tabela_precos WHERE Key_name = 'PRIMARY'")->fetch(PDO::FETCH_ASSOC);
        if ($k && !empty($k['Column_name'])) $chave = $k['Column_name'];
    } catch (Exception $e) {}
    return preg_replace('/[^A-Za-z0-9_]/', '', $chave);
}

function ft_slug($txt, $max = 40) {
    $t = (string)$txt;
    if (function_exists('iconv')) { $c = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $t); if ($c !== false) $t = $c; }
    $t = strtolower(preg_replace('/[^A-Za-z0-9]+/', '-', $t));
    $t = trim($t, '-');
    return substr($t !== '' ? $t : 'ficha', 0, $max);
}

// Valida e grava um ficheiro enviado. Devolve [nome_gravado, nome_original, tamanho] ou lança Exception.
function ft_guardar_upload(array $f, $marca, $modelo, $prefixo = '') {
    if (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        $erros = [UPLOAD_ERR_INI_SIZE => 'é maior do que o servidor aceita', UPLOAD_ERR_FORM_SIZE => 'é demasiado grande',
                  UPLOAD_ERR_PARTIAL => 'chegou incompleto', UPLOAD_ERR_NO_FILE => 'não foi escolhido'];
        throw new Exception('O ficheiro ' . ($erros[$f['error'] ?? 4] ?? 'não foi recebido') . '.');
    }
    if ($f['size'] > FT_MAX_BYTES) throw new Exception('O ficheiro «' . $f['name'] . '» tem mais de 10 MB.');
    $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
    $cab = (string)@file_get_contents($f['tmp_name'], false, null, 0, 8);
    $tipo = '';
    if (strpos($cab, '%PDF-') === 0) $tipo = 'pdf';
    else {
        $info = @getimagesize($f['tmp_name']);
        if ($info && $info[2] === IMAGETYPE_JPEG) $tipo = 'jpg';
        elseif ($info && $info[2] === IMAGETYPE_PNG) $tipo = 'png';
    }
    if ($tipo === '') throw new Exception('O ficheiro «' . $f['name'] . '» não é PDF, JPG nem PNG.');
    if (!is_dir(FT_PASTA)) @mkdir(FT_PASTA, 0755, true);
    $nome = $prefixo . ft_slug($marca, 25) . '_' . ft_slug($modelo !== '' ? $modelo : pathinfo($f['name'], PATHINFO_FILENAME), 45)
          . '_' . date('ymd') . bin2hex(random_bytes(2)) . '.' . $tipo;
    if (!move_uploaded_file($f['tmp_name'], FT_PASTA . $nome)) throw new Exception('Não foi possível gravar «' . $f['name'] . '» na pasta ' . FT_PASTA . '.');
    return [$nome, mb_substr((string)$f['name'], 0, 255), (int)$f['size']];
}

function ft_url($ficheiro) {
    return FT_PASTA . rawurlencode(basename((string)$ficheiro));
}

function ft_tamanho_txt($b) {
    $b = (int)$b;
    if ($b >= 1048576) return number_format($b / 1048576, 1, ',', '') . ' MB';
    if ($b >= 1024) return round($b / 1024) . ' KB';
    return $b . ' B';
}

// Estado do certificado: '' (sem certificado), 'valido', 'a_expirar' (≤ 60 dias) ou 'expirado'
function ft_estado_certificado($f) {
    if (empty($f['cert_ficheiro'])) return '';
    if (empty($f['cert_validade'])) return 'valido';
    $dias = (strtotime($f['cert_validade']) - strtotime(date('Y-m-d'))) / 86400;
    if ($dias < 0) return 'expirado';
    return $dias <= 60 ? 'a_expirar' : 'valido';
}

// Ficha ligada a um artigo da tabela de preços (para orçamentos / FAME)
function ft_ficha_do_artigo($pdo, $valor_chave) {
    $ch = ft_chave_artigo($pdo);
    try {
        $st = $pdo->prepare("SELECT f.* FROM tabela_precos t JOIN fichas_tecnicas f ON f.id = t.id_ficha_tecnica WHERE t.`$ch` = ? LIMIT 1");
        $st->execute([$valor_chave]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Exception $e) { return null; }
}

} // fim function_exists
