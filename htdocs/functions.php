<?php
/**
 * REGRA DE OURO: FUNÇÕES GLOBAIS DO SISTEMA
 * Este ficheiro centraliza funções usadas em várias páginas.
 */

// 1. FUNÇÃO DE LOGS (Que estava como "fantasma" nos outros ficheiros)
function registrarLog($pdo, $acao, $modulo) {
    // Por enquanto retorna true para não dar erro.
    // No futuro, se quiseres, podemos fazer com que isto grave numa tabela 'logs' na base de dados!
    return true;
}

// 2. MOTOR DE SEGURANÇA CSRF: Gera um token único
function gerar_csrf_token() {
    if (empty($_SESSION['csrf_token'])) {
        // Gera uma string aleatória muito segura
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

// 3. MOTOR DE SEGURANÇA CSRF: Valida o token submetido
function validar_csrf_token($token_submetido) {
    if (empty($_SESSION['csrf_token']) || empty($token_submetido)) {
        return false;
    }
    // Compara o token da sessão com o que veio do formulário
    return hash_equals($_SESSION['csrf_token'], $token_submetido);
}

// 4. JSON SEGURO: Codifica dados para JSON sem escapar acentos/barras,
// e sem rebentar se algum texto tiver um carater inválido
function safe_json_encode($data) {
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    return $json !== false ? $json : json_encode([]);
}
