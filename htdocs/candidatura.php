<?php
/**
 * candidatura.php — formulário de candidatura a estágio · m.Abreu – Instalações Técnicas
 * Ficheiro único: não precisa de mais nada (o símbolo MAB vai incluído no fim deste ficheiro).
 */

date_default_timezone_set('Europe/Lisbon');

/* ============================ CONFIGURAÇÃO ============================ */

// Para onde chegam os emails com as candidaturas
const DESTINO_EMAIL   = 'marioabreu@mabreu.pt';

// Remetente dos emails. Tem de ser um endereço do domínio mabreu.pt
// (senão muitos servidores marcam como spam). Pode ser uma caixa que nem exista.
const REMETENTE_EMAIL = 'formularios@mabreu.pt';

// Pasta onde fica guardada cada candidatura (um ficheiro JSON por candidatura).
// IDEAL: fora da pasta pública do site. Por defeito, uma pasta ao lado do site.
const PASTA_DADOS     = __DIR__ . '/../dados_estagios';

// Diagnóstico: ponha true APENAS para testar. Se o envio falhar, mostra no ecrã o motivo técnico.
// Volte a pôr false antes de publicar.
const MODO_DIAGNOSTICO = false;

// Envio por SMTP (recomendado: é o que garante que o email chega).
// Preencha com os dados da caixa de email do domínio (ex.: abreu@mabreu.pt), que o alojamento indica.
// Se SMTP_HOST ficar vazio, usa o envio normal do servidor (mail()), que em muitos alojamentos falha ou cai no spam.
const SMTP_HOST          = 'smtp.mabreu.pt';       // ex.: mail.mabreu.pt
const SMTP_PORTA         = 587;      // 465 (com 'ssl') ou 587 (com 'tls')
const SMTP_SEGURANCA     = 'starttls';    // 'ssl' (465) ou 'tls' (587) = ligação cifrada (recomendado). Qualquer outro valor liga SEM cifra
const SMTP_UTILIZADOR    = 'abreu@mabreu.pt';       // ex.: abreu@mabreu.pt
const SMTP_PALAVRA_PASSE = '2017M@breu';       // palavra-passe dessa caixa (fica só neste ficheiro, no servidor)

/* ============================ FUNÇÕES ============================ */

function iniciar_sessao(): void {
    if (session_status() === PHP_SESSION_NONE) {
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'secure'   => !empty($_SERVER['HTTPS']),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }
}

function h($s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function csrf_token(): string {
    iniciar_sessao();
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_valido($t): bool {
    iniciar_sessao();
    return !empty($_SESSION['csrf']) && is_string($t) && hash_equals($_SESSION['csrf'], $t);
}

/** Limpa um valor recebido. Por defeito, uma só linha. */
function limpar($v, int $max = 200, bool $multilinha = false): string {
    $v = is_string($v) ? $v : '';
    $v = (string)preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $v);
    if (!$multilinha) {
        $v = (string)preg_replace('/\s+/u', ' ', $v);
    }
    return mb_substr(trim($v), 0, $max);
}

/** Honeypot, tempo mínimo de preenchimento e intervalo entre envios. */
function verificar_anti_spam(): ?string {
    iniciar_sessao();
    if (!empty($_POST['_gotcha'])) {
        return 'Pedido inválido.';
    }
    $ts = (int)($_SESSION['form_ts'] ?? 0);
    if ($ts && (time() - $ts) < 4) {
        return 'Reveja o formulário e volte a carregar em enviar.';
    }
    $ult = (int)($_SESSION['ultimo_envio'] ?? 0);
    if ($ult && (time() - $ult) < 30) {
        return 'Aguarde um momento antes de enviar novamente.';
    }
    return null;
}

/** Guarda a candidatura num ficheiro JSON. Devolve a referência. */
function guardar_submissao(array $dados): array {
    $id = date('Ymd-His') . '-' . bin2hex(random_bytes(4));
    $json = json_encode(
        ['referencia' => $id, 'recebido_em' => date('c'), 'dados' => $dados],
        JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
    );
    $falhas = [];
    // 1.º a pasta configurada; se não for possível, uma pasta junto deste ficheiro (protegida por .htaccess)
    foreach ([PASTA_DADOS, __DIR__ . '/dados_estagios'] as $pasta) {
        if (!is_dir($pasta) && !@mkdir($pasta, 0750, true)) {
            $falhas[] = 'não foi possível criar ' . $pasta;
            continue;
        }
        $ht = $pasta . '/.htaccess';
        if (!file_exists($ht)) {
            @file_put_contents($ht, "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n");
        }
        $ficheiro = $pasta . '/candidatura-' . $id . '.json';
        if (@file_put_contents($ficheiro, $json, LOCK_EX) !== false) {
            @chmod($ficheiro, 0640);
            return ['id' => $id, 'ficheiro' => $ficheiro];
        }
        $falhas[] = 'sem permissão para escrever em ' . $pasta;
    }
    throw new RuntimeException(implode('; ', $falhas));
}

function enviar_email(string $assunto, string $corpo, string $responderA = '', string $para = DESTINO_EMAIL, string $nomeRemetente = 'm.Abreu Formularios', array $anexos = []): bool {
    if (preg_match('/[\r\n]/', $para) || !filter_var($para, FILTER_VALIDATE_EMAIL)) {
        $GLOBALS['erro_email'] = 'endereço de destino inválido';
        return false;
    }
    if (SMTP_HOST !== '') {
        try {
            smtp_enviar($assunto, $corpo, $responderA, $para, $nomeRemetente, $anexos);
            return true;
        } catch (Throwable $e) {
            $GLOBALS['erro_email'] = 'SMTP: ' . $e->getMessage();
            error_log('candidatura (email): ' . $GLOBALS['erro_email']);
            return false;
        }
    }
    $cab = [
        'From: =?UTF-8?B?' . base64_encode($nomeRemetente) . '?= <' . REMETENTE_EMAIL . '>',
        'MIME-Version: 1.0',
    ];
    if ($responderA !== '' && !preg_match('/[\r\n]/', $responderA) && filter_var($responderA, FILTER_VALIDATE_EMAIL)) {
        $cab[] = 'Reply-To: ' . $responderA;
    }
    $assuntoCodificado = '=?UTF-8?B?' . base64_encode($assunto) . '?=';
    [$cabMime, $corpoMime] = mime_corpo($corpo, $anexos);
    $cabecalhos = implode("\r\n", array_merge($cab, $cabMime));
    error_clear_last();
    $ok = @mail($para, $assuntoCodificado, $corpoMime, $cabecalhos, '-f' . REMETENTE_EMAIL);
    if (!$ok) {
        // Alguns alojamentos não aceitam o parâmetro -f: tenta sem ele
        $ok = @mail($para, $assuntoCodificado, $corpoMime, $cabecalhos);
    }
    if (!$ok) {
        $err = error_get_last();
        $GLOBALS['erro_email'] = $err['message'] ?? 'mail() devolveu false (o servidor não tem envio de email configurado ou recusou a mensagem)';
        error_log('candidatura (email): ' . $GLOBALS['erro_email']);
    }
    return $ok;
}

/** Envia por SMTP com autenticação. Lança exceção se algo falhar. */
function smtp_enviar(string $assunto, string $corpo, string $responderA, string $para, string $nomeRemetente, array $anexos = []): void {
    $ctx  = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);
    $alvo = (SMTP_SEGURANCA === 'ssl' ? 'ssl://' : 'tcp://') . SMTP_HOST . ':' . SMTP_PORTA;
    $f = @stream_socket_client($alvo, $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $ctx);
    if (!$f) {
        throw new RuntimeException('ligação a ' . $alvo . ' falhou (' . $errno . ' ' . $errstr . ')');
    }
    stream_set_timeout($f, 15);

    $ler = function () use ($f): string {
        $r = '';
        while (($l = fgets($f, 515)) !== false) {
            $r .= $l;
            if (strlen($l) < 4 || $l[3] !== '-') break;
        }
        return $r;
    };
    $cmd = function (string $c, array $esperados) use ($f, $ler): string {
        if ($c !== '') { fwrite($f, $c . "\r\n"); }
        $r = $ler();
        if (!in_array((int)substr($r, 0, 3), $esperados, true)) {
            throw new RuntimeException(trim($r) !== '' ? trim($r) : 'sem resposta do servidor de email');
        }
        return $r;
    };

    try {
        $nome = preg_replace('/[^A-Za-z0-9.-]/', '', (string)($_SERVER['SERVER_NAME'] ?? '')) ?: 'localhost';
        $cmd('', [220]);
        $cmd('EHLO ' . $nome, [250]);
        if (SMTP_SEGURANCA === 'tls') {
            $cmd('STARTTLS', [220]);
            $metodo = STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
            if (defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT')) { $metodo |= STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT; }
            if (!@stream_socket_enable_crypto($f, true, $metodo)) {
                throw new RuntimeException('não foi possível iniciar a ligação segura (TLS)');
            }
            $cmd('EHLO ' . $nome, [250]);
        }
        $cmd('AUTH LOGIN', [334]);
        $cmd(base64_encode(SMTP_UTILIZADOR), [334]);
        $cmd(base64_encode(SMTP_PALAVRA_PASSE), [235]);
        $cmd('MAIL FROM:<' . SMTP_UTILIZADOR . '>', [250]);
        $cmd('RCPT TO:<' . $para . '>', [250, 251]);
        $cmd('DATA', [354]);

        $dominio = substr((string)strrchr(SMTP_UTILIZADOR, '@'), 1) ?: 'localhost';
        $nomeCod = '=?UTF-8?B?' . base64_encode($nomeRemetente) . '?=';
        $cab = [
            'Date: ' . date('r'),
            'From: ' . $nomeCod . ' <' . SMTP_UTILIZADOR . '>',
            'To: <' . $para . '>',
            'Subject: =?UTF-8?B?' . base64_encode($assunto) . '?=',
            'Message-ID: <' . bin2hex(random_bytes(8)) . '@' . $dominio . '>',
            'MIME-Version: 1.0',
        ];
        if ($responderA !== '' && !preg_match('/[\r\n]/', $responderA) && filter_var($responderA, FILTER_VALIDATE_EMAIL)) {
            $cab[] = 'Reply-To: ' . $responderA;
        }
        [$cabMime, $corpoMime] = mime_corpo($corpo, $anexos);
        $dados = implode("\r\n", array_merge($cab, $cabMime)) . "\r\n\r\n" . $corpoMime . "\r\n.\r\n";
        for ($o = 0, $n = strlen($dados); $o < $n; ) {
            $w = fwrite($f, substr($dados, $o, 65536));
            if ($w === false || $w === 0) { throw new RuntimeException('falha ao enviar os dados da mensagem'); }
            $o += $w;
        }
        stream_set_timeout($f, 90); // o servidor pode demorar a aceitar mensagens com anexos
        $cmd('', [250]);
        @fwrite($f, "QUIT\r\n");
    } finally {
        @fclose($f);
    }
}

/** Regista no ficheiro da candidatura se os emails seguiram ou não (para se perceber depois). */
function registar_estado_email(string $ficheiro, bool $enviado, string $erro, ?bool $copia = null, string $erroCopia = ''): void {
    $j = json_decode((string)@file_get_contents($ficheiro), true);
    if (!is_array($j)) return;
    $j['email'] = $enviado ? 'aceite pelo servidor de envio' : 'FALHOU: ' . ($erro !== '' ? $erro : 'motivo desconhecido');
    if ($copia !== null) {
        $j['copia_para_candidato'] = $copia ? 'aceite pelo servidor de envio' : 'FALHOU: ' . ($erroCopia !== '' ? $erroCopia : 'motivo desconhecido');
    }
    @file_put_contents($ficheiro, json_encode($j, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
}

/** Limite de candidaturas por endereço IP (evita abusos, já que enviamos um email de confirmação). */
function limite_ip(bool $registar, int $max = 10, int $janela = 3600): bool {
    $f = sys_get_temp_dir() . '/mabreu_lim_' . sha1(($_SERVER['REMOTE_ADDR'] ?? 'x') . __FILE__) . '.json';
    $agora = time();
    $ts = json_decode((string)@file_get_contents($f), true);
    $ts = is_array($ts) ? array_values(array_filter($ts, function ($t) use ($agora, $janela) {
        return is_int($t) && $t > $agora - $janela;
    })) : [];
    if ($registar) {
        $ts[] = $agora;
        @file_put_contents($f, json_encode($ts), LOCK_EX);
        return false;
    }
    return count($ts) >= $max;
}

/** Monta o corpo do email (texto simples, ou texto + anexos). Devolve [cabeçalhos de conteúdo, corpo]. */
function mime_corpo(string $corpo, array $anexos): array {
    $texto = chunk_split(base64_encode($corpo), 76, "\r\n");
    if (!$anexos) {
        return [['Content-Type: text/plain; charset=UTF-8', 'Content-Transfer-Encoding: base64'], $texto];
    }
    $b = '=_mab_' . bin2hex(random_bytes(12));
    $partes = "--$b\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . $texto;
    foreach ($anexos as $a) {
        $nome = '=?UTF-8?B?' . base64_encode($a['nome']) . '?=';
        $partes .= "--$b\r\nContent-Type: " . $a['tipo'] . '; name="' . $nome . "\"\r\n"
                 . "Content-Transfer-Encoding: base64\r\nContent-Disposition: attachment; filename=\"" . $nome . "\"\r\n\r\n"
                 . chunk_split(base64_encode($a['conteudo']), 76, "\r\n");
    }
    $partes .= "--$b--\r\n";
    return [['Content-Type: multipart/mixed; boundary="' . $b . '"'], $partes];
}

/** Lê e valida os anexos carregados (máx. MAX_ANEXOS, tipos permitidos, tamanho e conteúdo). */
function ler_anexos(array &$erros): array {
    if (empty($_FILES['anexos']) || !is_array($_FILES['anexos']['name'])) { return []; }
    $f = $_FILES['anexos'];
    $presentes = [];
    foreach (array_keys($f['name']) as $i) {
        if ($f['error'][$i] !== UPLOAD_ERR_NO_FILE) { $presentes[] = $i; }
    }
    if (count($presentes) > MAX_ANEXOS) {
        $erros[] = 'Pode anexar no máximo ' . MAX_ANEXOS . ' ficheiros.';
        return [];
    }
    $permitidos = [
        'pdf'  => ['application/pdf', '%PDF'],
        'doc'  => ['application/msword', "\xD0\xCF\x11\xE0"],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', "PK\x03\x04"],
        'jpg'  => ['image/jpeg', "\xFF\xD8\xFF"],
        'jpeg' => ['image/jpeg', "\xFF\xD8\xFF"],
        'png'  => ['image/png', "\x89PNG\r\n\x1a\n"],
    ];
    $res = [];
    foreach ($presentes as $i) {
        $original = (string)$f['name'][$i];
        $err = $f['error'][$i];
        if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
            $erros[] = 'O ficheiro "' . $original . '" excede o tamanho máximo que o servidor aceita (' . ini_get('upload_max_filesize') . ').';
            continue;
        }
        if ($err !== UPLOAD_ERR_OK || !is_uploaded_file((string)$f['tmp_name'][$i])) {
            $erros[] = 'Não foi possível carregar o ficheiro "' . $original . '". Tente novamente.';
            continue;
        }
        $tam = (int)$f['size'][$i];
        if ($tam <= 0 || $tam > MAX_BYTES_ANEXO) {
            $erros[] = 'O ficheiro "' . $original . '" excede ' . (MAX_BYTES_ANEXO / 1048576) . ' MB.';
            continue;
        }
        $ext = strtolower(pathinfo($original, PATHINFO_EXTENSION));
        if (!isset($permitidos[$ext])) {
            $erros[] = 'O ficheiro "' . $original . '" não é de um tipo permitido (PDF, Word, JPG ou PNG).';
            continue;
        }
        $conteudo = (string)file_get_contents((string)$f['tmp_name'][$i]);
        if (strncmp($conteudo, $permitidos[$ext][1], strlen($permitidos[$ext][1])) !== 0) {
            $erros[] = 'O conteúdo do ficheiro "' . $original . '" não corresponde ao tipo indicado.';
            continue;
        }
        $nome = (string)preg_replace('/[\x00-\x1F\x7F"\\\\\/:*?<>|]+/u', '_', basename(str_replace('\\', '/', $original)));
        $nome = mb_substr($nome, 0, 80);
        if ($nome === '') { $nome = 'anexo.' . $ext; }
        $res[] = ['nome' => $nome, 'tipo' => $permitidos[$ext][0], 'conteudo' => $conteudo];
    }
    return $res;
}

/** Se o email falhar, guarda os anexos junto do ficheiro da candidatura para não se perderem. */
function guardar_anexos(string $ficheiroJson, array $anexos): void {
    $base = (string)preg_replace('/\.json$/', '', $ficheiroJson);
    foreach ($anexos as $i => $a) {
        $destino = $base . '-anexo' . ($i + 1) . '-' . preg_replace('/[^A-Za-z0-9._-]/', '_', $a['nome']);
        if (@file_put_contents($destino, $a['conteudo'], LOCK_EX) !== false) { @chmod($destino, 0640); }
    }
}

function mostrar_erros(array $erros): void {
    if (!$erros) return;
    echo '<div class="erros" role="alert"><strong>Por favor corrija:</strong><ul>';
    foreach ($erros as $e) {
        echo '<li>' . h($e) . '</li>';
    }
    echo '</ul></div>';
}

function selecionado($atual, string $valor): string {
    return $atual === $valor ? ' selected' : '';
}

function pagina_inicio(string $titulo, string $subtitulo): void {
    echo '<!doctype html><html lang="pt-PT"><head><meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<title>' . h($titulo) . ' | m.Abreu</title>';
    echo '<link rel="icon" href="data:image/png;base64,' . logo_base64() . '">';
    echo '<style>
:root{--bg:#f5f6f8;--card:#fff;--ink:#1c2430;--muted:#5b6675;--line:#d9dee5;--accent:#9d1b21;--accent-escuro:#7f1419;--tint:#fbeeee}
*{box-sizing:border-box}
[hidden]{display:none!important}
body{margin:0;background:var(--bg);color:var(--ink);font:16px/1.5 system-ui,-apple-system,Segoe UI,Roboto,sans-serif}
main{max-width:640px;margin:0 auto;padding:24px 16px 48px}
.topo{display:flex;align-items:center;gap:14px;margin-bottom:18px}
.logo{flex:none;width:56px;height:56px;display:block;border-radius:50%}
h1{font-size:1.4rem;margin:0 0 2px}
.sub{color:var(--muted);margin:0}
form,.privacy,.ok{background:var(--card);border:1px solid var(--line);border-radius:10px;padding:20px}
label{display:block;font-weight:600;margin:16px 0 4px}
label small{font-weight:400;color:var(--muted)}
input,select,textarea{width:100%;padding:10px;border:1px solid var(--line);border-radius:6px;font:inherit;background:#fff;color:inherit}
input[readonly]{background:var(--tint);font-weight:600}
input:focus,select:focus,textarea:focus{outline:2px solid var(--accent);outline-offset:1px}
.check{display:flex;gap:10px;align-items:flex-start;font-weight:400;margin-top:16px}
.check input{width:auto;margin-top:5px}
button{margin-top:20px;background:var(--accent);color:#fff;border:0;border-radius:6px;padding:12px 20px;font:inherit;font-weight:600;cursor:pointer}
button:hover{background:var(--accent-escuro)}
.tel{display:flex;gap:8px}.prefixo{flex:none;display:flex;align-items:center;justify-content:center;min-width:64px;padding:0 12px;border:1px solid var(--line);border-radius:6px;background:var(--tint);font-weight:600}
.dica{color:var(--muted);font-size:.85rem;margin:4px 0 0}
.privacy{margin-top:20px;font-size:.9rem;color:var(--muted)}
.privacy h2{font-size:1rem;color:var(--ink);margin:0 0 8px}
.erros{background:#fdecec;border:1px solid #f0b4b4;color:#7a1f1f;border-radius:8px;padding:12px 16px;margin-bottom:16px}
.erros ul{margin:0;padding-left:20px}
.ok{border-color:#9fd3aa;background:#eefaf0}
.hp{position:absolute;left:-9999px}
</style><noscript><style>[hidden]{display:block!important}</style></noscript></head><body><main>';
    echo '<div class="topo"><img class="logo" src="data:image/png;base64,' . logo_base64() . '" alt="MAB – m.Abreu" width="56" height="56">';
    echo '<div><h1>' . h($titulo) . '</h1><p class="sub">' . h($subtitulo) . '</p></div></div>';
}

function pagina_fim(): void {
    echo '</main></body></html>';
}

const FORA_PT = 'Fora de Portugal';

// Anexos: no máximo 2 ficheiros, até 3 MB cada (cabe nos limites habituais de email).
// Atenção: o servidor também tem os seus limites (upload_max_filesize e post_max_size).
const MAX_ANEXOS      = 2;
const MAX_BYTES_ANEXO = 3 * 1024 * 1024;

/* ---------- Concelhos de Portugal (308), por distrito ---------- */
// Braga em destaque, depois o distrito de Braga, depois os restantes distritos por ordem alfabética.
$braga_concelhos = explode('|', 'Amares|Barcelos|Cabeceiras de Basto|Celorico de Basto|Esposende|Fafe|Guimarães|Póvoa de Lanhoso|Terras de Bouro|Vieira do Minho|Vila Nova de Famalicão|Vila Verde|Vizela');

$outros = [
    'Aveiro'         => 'Águeda|Albergaria-a-Velha|Anadia|Arouca|Aveiro|Castelo de Paiva|Espinho|Estarreja|Ílhavo|Mealhada|Murtosa|Oliveira de Azeméis|Oliveira do Bairro|Ovar|Santa Maria da Feira|São João da Madeira|Sever do Vouga|Vagos|Vale de Cambra',
    'Beja'           => 'Aljustrel|Almodôvar|Alvito|Barrancos|Beja|Castro Verde|Cuba|Ferreira do Alentejo|Mértola|Moura|Odemira|Ourique|Serpa|Vidigueira',
    'Bragança'       => 'Alfândega da Fé|Bragança|Carrazeda de Ansiães|Freixo de Espada à Cinta|Macedo de Cavaleiros|Miranda do Douro|Mirandela|Mogadouro|Torre de Moncorvo|Vila Flor|Vimioso|Vinhais',
    'Castelo Branco' => 'Belmonte|Castelo Branco|Covilhã|Fundão|Idanha-a-Nova|Oleiros|Penamacor|Proença-a-Nova|Sertã|Vila de Rei|Vila Velha de Ródão',
    'Coimbra'        => 'Arganil|Cantanhede|Coimbra|Condeixa-a-Nova|Figueira da Foz|Góis|Lousã|Mira|Miranda do Corvo|Montemor-o-Velho|Oliveira do Hospital|Pampilhosa da Serra|Penacova|Penela|Soure|Tábua|Vila Nova de Poiares',
    'Évora'          => 'Alandroal|Arraiolos|Borba|Estremoz|Évora|Montemor-o-Novo|Mora|Mourão|Portel|Redondo|Reguengos de Monsaraz|Vendas Novas|Viana do Alentejo|Vila Viçosa',
    'Faro'           => 'Albufeira|Alcoutim|Aljezur|Castro Marim|Faro|Lagoa|Lagos|Loulé|Monchique|Olhão|Portimão|São Brás de Alportel|Silves|Tavira|Vila do Bispo|Vila Real de Santo António',
    'Guarda'         => 'Aguiar da Beira|Almeida|Celorico da Beira|Figueira de Castelo Rodrigo|Fornos de Algodres|Gouveia|Guarda|Manteigas|Mêda|Pinhel|Sabugal|Seia|Trancoso|Vila Nova de Foz Côa',
    'Leiria'         => 'Alcobaça|Alvaiázere|Ansião|Batalha|Bombarral|Caldas da Rainha|Castanheira de Pera|Figueiró dos Vinhos|Leiria|Marinha Grande|Nazaré|Óbidos|Pedrógão Grande|Peniche|Pombal|Porto de Mós',
    'Lisboa'         => 'Alenquer|Amadora|Arruda dos Vinhos|Azambuja|Cadaval|Cascais|Lisboa|Loures|Lourinhã|Mafra|Odivelas|Oeiras|Sintra|Sobral de Monte Agraço|Torres Vedras|Vila Franca de Xira',
    'Portalegre'     => 'Alter do Chão|Arronches|Avis|Campo Maior|Castelo de Vide|Crato|Elvas|Fronteira|Gavião|Marvão|Monforte|Nisa|Ponte de Sor|Portalegre|Sousel',
    'Porto'          => 'Amarante|Baião|Felgueiras|Gondomar|Lousada|Maia|Marco de Canaveses|Matosinhos|Paços de Ferreira|Paredes|Penafiel|Porto|Póvoa de Varzim|Santo Tirso|Trofa|Valongo|Vila do Conde|Vila Nova de Gaia',
    'Santarém'       => 'Abrantes|Alcanena|Almeirim|Alpiarça|Benavente|Cartaxo|Chamusca|Constância|Coruche|Entroncamento|Ferreira do Zêzere|Golegã|Mação|Ourém|Rio Maior|Salvaterra de Magos|Santarém|Sardoal|Tomar|Torres Novas|Vila Nova da Barquinha',
    'Setúbal'        => 'Alcácer do Sal|Alcochete|Almada|Barreiro|Grândola|Moita|Montijo|Palmela|Santiago do Cacém|Seixal|Sesimbra|Setúbal|Sines',
    'Viana do Castelo' => 'Arcos de Valdevez|Caminha|Melgaço|Monção|Paredes de Coura|Ponte da Barca|Ponte de Lima|Valença|Viana do Castelo|Vila Nova de Cerveira',
    'Vila Real'      => 'Alijó|Boticas|Chaves|Mesão Frio|Mondim de Basto|Montalegre|Murça|Peso da Régua|Ribeira de Pena|Sabrosa|Santa Marta de Penaguião|Valpaços|Vila Pouca de Aguiar|Vila Real',
    'Viseu'          => 'Armamar|Carregal do Sal|Castro Daire|Cinfães|Lamego|Mangualde|Moimenta da Beira|Mortágua|Nelas|Oliveira de Frades|Penalva do Castelo|Penedono|Resende|Santa Comba Dão|São João da Pesqueira|São Pedro do Sul|Sátão|Sernancelhe|Tabuaço|Tarouca|Tondela|Vila Nova de Paiva|Viseu|Vouzela',
    'Açores'         => 'Angra do Heroísmo|Calheta|Corvo|Horta|Lagoa|Lajes das Flores|Lajes do Pico|Madalena|Nordeste|Ponta Delgada|Povoação|Praia da Vitória|Ribeira Grande|Santa Cruz da Graciosa|Santa Cruz das Flores|São Roque do Pico|Velas|Vila do Porto|Vila Franca do Campo',
    'Madeira'        => 'Calheta|Câmara de Lobos|Funchal|Machico|Ponta do Sol|Porto Moniz|Porto Santo|Ribeira Brava|Santa Cruz|Santana|São Vicente',
];

function rotulo_grupo(string $d): string {
    if ($d === 'Açores')  return 'Região Autónoma dos Açores';
    if ($d === 'Madeira') return 'Região Autónoma da Madeira';
    return 'Distrito de ' . $d;
}

// Grupos: [rótulo, [[valor, texto], ...]]. O valor inclui o distrito para distinguir nomes repetidos (ex.: Lagoa, Calheta).
$grupos = [];
$grupos[] = ['★ Em destaque', [['Braga (Braga)', 'Braga']]];
$grupos[] = ['Distrito de Braga', array_map(function ($c) { return [$c . ' (Braga)', $c]; }, $braga_concelhos)];
foreach ($outros as $distrito => $lista) {
    $grupos[] = [rotulo_grupo($distrito), array_map(function ($c) use ($distrito) {
        return [$c . ' (' . $distrito . ')', $c];
    }, explode('|', $lista))];
}
$grupos[] = ['Fora de Portugal', [[FORA_PT, 'Fora de Portugal']]];
$validos = [];
foreach ($grupos as $g) {
    foreach ($g[1] as $o) { $validos[] = $o[0]; }
}

/* ---------- Estágios ---------- */
const PROGRAMA_TALENTO = 'Estágio +Talento';
const PROGRAMA_INOVAR  = 'Estágio Inovar';
const AREA_TALENTO     = 'Engº Eletrotécnico';
$areas_inovar = ['Técnico Administrativo/Contabilidade', 'Eletricista'];

$paises = [
    'Afeganistão' => '+93', 'África do Sul' => '+27', 'Albânia' => '+355', 'Alemanha' => '+49', 'Andorra' => '+376',
    'Angola' => '+244', 'Antígua e Barbuda' => '+1', 'Arábia Saudita' => '+966', 'Argélia' => '+213', 'Argentina' => '+54',
    'Arménia' => '+374', 'Austrália' => '+61', 'Áustria' => '+43', 'Azerbaijão' => '+994', 'Baamas' => '+1',
    'Bangladeche' => '+880', 'Barbados' => '+1', 'Barém' => '+973', 'Bélgica' => '+32', 'Belize' => '+501',
    'Benim' => '+229', 'Bielorrússia' => '+375', 'Bolívia' => '+591', 'Bósnia e Herzegovina' => '+387', 'Botsuana' => '+267',
    'Brasil' => '+55', 'Brunei' => '+673', 'Bulgária' => '+359', 'Burquina Faso' => '+226', 'Burúndi' => '+257',
    'Butão' => '+975', 'Cabo Verde' => '+238', 'Camarões' => '+237', 'Camboja' => '+855', 'Canadá' => '+1',
    'Catar' => '+974', 'Cazaquistão' => '+7', 'Chade' => '+235', 'Chile' => '+56', 'China' => '+86',
    'Chipre' => '+357', 'Colômbia' => '+57', 'Comores' => '+269', 'Congo' => '+242', 'Congo (República Democrática do)' => '+243',
    'Coreia do Norte' => '+850', 'Coreia do Sul' => '+82', 'Costa do Marfim' => '+225', 'Costa Rica' => '+506', 'Croácia' => '+385',
    'Cuba' => '+53', 'Dinamarca' => '+45', 'Dominica' => '+1', 'Egito' => '+20', 'El Salvador' => '+503',
    'Emirados Árabes Unidos' => '+971', 'Equador' => '+593', 'Eritreia' => '+291', 'Eslováquia' => '+421', 'Eslovénia' => '+386',
    'Espanha' => '+34', 'Estados Unidos' => '+1', 'Estónia' => '+372', 'Eswatini' => '+268', 'Etiópia' => '+251',
    'Fiji' => '+679', 'Filipinas' => '+63', 'Finlândia' => '+358', 'França' => '+33', 'Gabão' => '+241',
    'Gâmbia' => '+220', 'Gana' => '+233', 'Geórgia' => '+995', 'Granada' => '+1', 'Grécia' => '+30',
    'Guatemala' => '+502', 'Guiana' => '+592', 'Guiné' => '+224', 'Guiné Equatorial' => '+240', 'Guiné-Bissau' => '+245',
    'Haiti' => '+509', 'Honduras' => '+504', 'Hong Kong' => '+852', 'Hungria' => '+36', 'Iémen' => '+967',
    'Ilhas Marshall' => '+692', 'Ilhas Salomão' => '+677', 'Índia' => '+91', 'Indonésia' => '+62', 'Irão' => '+98',
    'Iraque' => '+964', 'Irlanda' => '+353', 'Islândia' => '+354', 'Israel' => '+972', 'Itália' => '+39',
    'Jamaica' => '+1', 'Japão' => '+81', 'Jibuti' => '+253', 'Jordânia' => '+962', 'Kiribati' => '+686',
    'Kosovo' => '+383', 'Kuwait' => '+965', 'Laos' => '+856', 'Lesoto' => '+266', 'Letónia' => '+371',
    'Líbano' => '+961', 'Libéria' => '+231', 'Líbia' => '+218', 'Listenstaine' => '+423', 'Lituânia' => '+370',
    'Luxemburgo' => '+352', 'Macau' => '+853', 'Macedónia do Norte' => '+389', 'Madagáscar' => '+261', 'Malásia' => '+60',
    'Maláui' => '+265', 'Maldivas' => '+960', 'Mali' => '+223', 'Malta' => '+356', 'Marrocos' => '+212',
    'Maurícia' => '+230', 'Mauritânia' => '+222', 'México' => '+52', 'Mianmar' => '+95', 'Micronésia' => '+691',
    'Moçambique' => '+258', 'Moldávia' => '+373', 'Mónaco' => '+377', 'Mongólia' => '+976', 'Montenegro' => '+382',
    'Namíbia' => '+264', 'Nauru' => '+674', 'Nepal' => '+977', 'Nicarágua' => '+505', 'Níger' => '+227',
    'Nigéria' => '+234', 'Noruega' => '+47', 'Nova Zelândia' => '+64', 'Omã' => '+968', 'Países Baixos' => '+31',
    'Palau' => '+680', 'Palestina' => '+970', 'Panamá' => '+507', 'Papua-Nova Guiné' => '+675', 'Paquistão' => '+92',
    'Paraguai' => '+595', 'Peru' => '+51', 'Polónia' => '+48', 'Quénia' => '+254', 'Quirguistão' => '+996',
    'Reino Unido' => '+44', 'República Centro-Africana' => '+236', 'República Checa' => '+420', 'República Dominicana' => '+1', 'Roménia' => '+40',
    'Ruanda' => '+250', 'Rússia' => '+7', 'Samoa' => '+685', 'San Marino' => '+378', 'Santa Lúcia' => '+1',
    'São Cristóvão e Neves' => '+1', 'São Tomé e Príncipe' => '+239', 'São Vicente e Granadinas' => '+1', 'Seicheles' => '+248', 'Senegal' => '+221',
    'Serra Leoa' => '+232', 'Sérvia' => '+381', 'Singapura' => '+65', 'Síria' => '+963', 'Somália' => '+252',
    'Sri Lanca' => '+94', 'Sudão' => '+249', 'Sudão do Sul' => '+211', 'Suécia' => '+46', 'Suíça' => '+41',
    'Suriname' => '+597', 'Tailândia' => '+66', 'Taiwan' => '+886', 'Tajiquistão' => '+992', 'Tanzânia' => '+255',
    'Timor-Leste' => '+670', 'Togo' => '+228', 'Tonga' => '+676', 'Trindade e Tobago' => '+1', 'Tunísia' => '+216',
    'Turquemenistão' => '+993', 'Turquia' => '+90', 'Tuvalu' => '+688', 'Ucrânia' => '+380', 'Uganda' => '+256',
    'Uruguai' => '+598', 'Uzbequistão' => '+998', 'Vanuatu' => '+678', 'Vaticano' => '+379', 'Venezuela' => '+58',
    'Vietname' => '+84', 'Zâmbia' => '+260', 'Zimbabué' => '+263',
];
$habilitacoes = ['9.º ano', '12.º ano', 'Licenciatura incompleta', 'Licenciatura', 'Mestrado'];
$cartas   = ['Sim, categoria B', 'Sim, outra categoria', 'Não'];
$viaturas = ['Sim', 'Não'];

/* ============================ PROCESSAMENTO ============================ */

iniciar_sessao();
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');

$v = ['nome' => '', 'email' => '', 'telefone' => '', 'concelho' => '', 'pais' => '', 'habilitacoes' => '', 'programa' => '', 'area_inovar' => '',
      'inicio' => '', 'carta' => '', 'viatura' => '', 'mensagem' => '', 'banco' => ''];
$erros = [];
$anexos = [];
$sucesso = false;

$aviso_diag = '';
if (!empty($_SESSION['enviado'])) {
    $sucesso = true;
    unset($_SESSION['enviado']);
    $aviso_diag = (string)($_SESSION['aviso_diag'] ?? '');
    unset($_SESSION['aviso_diag']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $v['nome']         = limpar($_POST['nome'] ?? '', 120);
    $v['email']        = limpar($_POST['email'] ?? '', 150);
    $v['telefone']     = limpar($_POST['telefone'] ?? '', 20);
    $v['concelho']     = limpar($_POST['concelho'] ?? '', 80);
    $v['pais']         = limpar($_POST['pais'] ?? '', 80);
    $v['habilitacoes'] = limpar($_POST['habilitacoes'] ?? '', 40);
    $v['programa']     = limpar($_POST['programa'] ?? '', 40);
    $v['area_inovar']  = limpar($_POST['area_inovar'] ?? '', 60);
    $v['inicio']       = limpar($_POST['inicio'] ?? '', 10);
    $v['carta']        = limpar($_POST['carta'] ?? '', 40);
    $v['viatura']      = limpar($_POST['viatura'] ?? '', 10);
    $v['mensagem']     = limpar($_POST['mensagem'] ?? '', 1500, true);
    $v['banco']        = !empty($_POST['consent_banco_talentos']) ? 'sim' : '';
    $li_aviso          = !empty($_POST['li_aviso']);

    if (empty($_POST) && empty($_FILES) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        $erros[] = 'Os ficheiros anexados excedem o tamanho máximo que o servidor aceita (' . ini_get('post_max_size') . '). Anexe ficheiros mais pequenos.';
    } elseif (!csrf_valido($_POST['csrf'] ?? null)) {
        $erros[] = 'A sessão expirou. Recarregue a página e tente novamente.';
    } elseif (($m = verificar_anti_spam()) !== null) {
        $erros[] = $m;
    } elseif (limite_ip(false)) {
        $erros[] = 'Foram feitos demasiados envios a partir deste dispositivo. Tente novamente dentro de uma hora.';
    } else {
        if (mb_strlen($v['nome']) < 3) $erros[] = 'Indique o seu nome.';
        if (!filter_var($v['email'], FILTER_VALIDATE_EMAIL)) $erros[] = 'Indique um email válido.';
        $fora = ($v['concelho'] === FORA_PT);
        $pais_ok = !$fora || isset($paises[$v['pais']]);
        $dial = ($fora && $pais_ok) ? $paises[$v['pais']] : '+351';
        $tel = (string)preg_replace('/[\s().-]/', '', $v['telefone']);
        $cod = ltrim($dial, '+');
        if (strpos($tel, '+' . $cod) === 0)       { $tel = substr($tel, strlen($cod) + 1); }
        elseif (strpos($tel, '00' . $cod) === 0)  { $tel = substr($tel, strlen($cod) + 2); }
        if ($pais_ok) {
            $tel_ok = ctype_digit($tel) && ($dial === '+351' ? (bool)preg_match('/^[239]\d{8}$/', $tel) : (strlen($tel) >= 5 && strlen($tel) <= 14));
            if (!$tel_ok) $erros[] = $dial === '+351' ? 'Indique um telefone português válido (9 dígitos).' : 'Indique um telefone válido, sem o indicativo.';
        }
        if (!in_array($v['concelho'], $validos, true)) $erros[] = 'Selecione o concelho de residência.';
        elseif ($fora && !$pais_ok) $erros[] = 'Selecione o país onde reside.';
        if (!in_array($v['habilitacoes'], $habilitacoes, true)) $erros[] = 'Indique as suas habilitações.';
        if (!in_array($v['programa'], [PROGRAMA_TALENTO, PROGRAMA_INOVAR], true)) {
            $erros[] = 'Escolha o estágio a que se candidata.';
        } elseif ($v['programa'] === PROGRAMA_INOVAR && !in_array($v['area_inovar'], $areas_inovar, true)) {
            $erros[] = 'Escolha a área do Estágio Inovar.';
        }
        if (!preg_match('#^(\d{2})/(\d{2})/(202\d)$#', $v['inicio'], $mi) || !checkdate((int)$mi[2], (int)$mi[1], (int)$mi[3])) {
            $erros[] = 'Indique a disponibilidade para início no formato dd/mm/202x (ex.: 15/01/2027).';
        } elseif ($mi[3] . '-' . $mi[2] . '-' . $mi[1] < date('Y-m-d')) {
            $erros[] = 'A data de início não pode ser anterior a hoje (' . date('d/m/Y') . ').';
        }
        if (!in_array($v['carta'], $cartas, true)) $erros[] = 'Indique se tem carta de condução.';
        if ($v['viatura'] !== '' && !in_array($v['viatura'], $viaturas, true)) $erros[] = 'Valor inválido em viatura própria.';
        if (!$li_aviso) $erros[] = 'Tem de confirmar que leu o aviso de privacidade.';
        $anexos = ler_anexos($erros);
    }

    if (!$erros) {
        limite_ip(true);
        // A área é decidida aqui, nunca pelo que vem do browser
        $area = $v['programa'] === PROGRAMA_TALENTO ? AREA_TALENTO : $v['area_inovar'];

        $lista_anexos = $anexos ? implode(', ', array_map(function ($a) {
            return $a['nome'] . ' (' . max(1, (int)round(strlen($a['conteudo']) / 1024)) . ' KB)';
        }, $anexos)) : 'Nenhum';

        $dados = [
            'Nome'                      => $v['nome'],
            'Email'                     => $v['email'],
            'Telefone'                  => $dial . ' ' . $tel,
            'Concelho de residência'    => $v['concelho'] === FORA_PT ? FORA_PT . ' (' . $v['pais'] . ')' : $v['concelho'],
            'Habilitações'              => $v['habilitacoes'],
            'Estágio'                   => $v['programa'],
            'Área'                      => $area,
            'Disponibilidade para início' => $v['inicio'],
            'Carta de condução'         => $v['carta'],
            'Viatura própria'           => $v['viatura'] !== '' ? $v['viatura'] : 'Não indicado',
            'Mensagem'                  => $v['mensagem'] !== '' ? $v['mensagem'] : '-',
            'Anexos'                    => $lista_anexos,
            'Conservar 12 meses (banco de talentos)' => $v['banco'] === 'sim' ? 'Sim' : 'Não',
        ];

        $id = '';
        $guardado = false;
        $detalhe_gravacao = '';
        $ficheiro_guardado = '';
        try {
            $g = guardar_submissao($dados);
            $id = $g['id'];
            $ficheiro_guardado = $g['ficheiro'];
            $guardado = true;
        } catch (Throwable $e) {
            $detalhe_gravacao = $e->getMessage();
            error_log('candidatura (gravação): ' . $detalhe_gravacao);
        }

        $linhas = [];
        foreach ($dados as $k => $val) {
            $linhas[] = $k . ': ' . $val;
        }
        $corpo = "Nova candidatura recebida em " . date('d/m/Y H:i') . "\n\n"
               . implode("\n", $linhas)
               . "\n\nReferência: " . ($guardado ? $id : 'não guardada no servidor') . "\n";
        $enviado = enviar_email('Candidatura a estágio: ' . $v['nome'] . ' (' . $area . ')', $corpo, $v['email'], DESTINO_EMAIL, 'm.Abreu Formularios', $anexos);
        $erro_dono = (string)($GLOBALS['erro_email'] ?? '');
        unset($GLOBALS['erro_email']);

        // Cópia de confirmação para quem se candidatou
        $corpo_copia = 'Olá ' . $v['nome'] . ",\n\n"
            . "Recebemos a sua candidatura a estágio na m.Abreu – Instalações Técnicas. Obrigado pelo interesse!\n"
            . "Se o seu perfil avançar no processo, entraremos em contacto através deste email ou do telefone indicado.\n\n"
            . "Resumo dos dados que submeteu:\n\n"
            . implode("\n", $linhas)
            . "\n\nReferência: " . ($guardado ? $id : '-') . "\n\n"
            . "Se algum dado estiver incorreto, responda a este email ou contacte abreu@mabreu.pt / 912854950.\n\n"
            . "Os seus dados são tratados apenas para o processo de seleção, nos termos do aviso de privacidade apresentado no formulário. Pode exercer os seus direitos através do mesmo contacto.\n\n"
            . "m.Abreu – Instalações Técnicas\nabreu@mabreu.pt · 912854950\n";
        $copia_ok = enviar_email('Confirmação da sua candidatura a estágio – m.Abreu', $corpo_copia, DESTINO_EMAIL, $v['email'], 'm.Abreu – Instalações Técnicas');
        $erro_copia = $copia_ok ? '' : (string)($GLOBALS['erro_email'] ?? '');
        unset($GLOBALS['erro_email']);

        if ($guardado && !$enviado && $anexos) {
            guardar_anexos($ficheiro_guardado, $anexos); // o email falhou: não perder os anexos
        }
        if ($guardado) {
            registar_estado_email($ficheiro_guardado, $enviado, $erro_dono, $copia_ok, $erro_copia);
        }

        if (!$guardado && !$enviado) {
            $msg = 'Não foi possível enviar a candidatura neste momento. Tente novamente mais tarde ou contacte abreu@mabreu.pt.';
            if (MODO_DIAGNOSTICO) {
                $msg .= ' [Diagnóstico] Gravação: ' . ($detalhe_gravacao !== '' ? $detalhe_gravacao : 'n/d')
                      . ' | Email: ' . ($erro_dono !== '' ? $erro_dono : 'n/d');
            }
            $erros[] = $msg;
        } else {
            $_SESSION['enviado'] = true;
            if (MODO_DIAGNOSTICO) {
                $_SESSION['aviso_diag'] = ($enviado
                    ? 'Email para a m.Abreu aceite pelo servidor de envio (se não chegar, veja o spam). '
                    : 'ATENÇÃO: a candidatura ficou guardada, mas o email para a m.Abreu NÃO foi enviado. Motivo: ' . ($erro_dono !== '' ? $erro_dono : 'desconhecido') . '. ')
                    . ($copia_ok
                    ? 'Cópia de confirmação aceite para ' . $v['email'] . '.'
                    : 'A cópia de confirmação para o candidato NÃO foi enviada. Motivo: ' . ($erro_copia !== '' ? $erro_copia : 'desconhecido') . '.');
            }
            $_SESSION['ultimo_envio'] = time();
            header('Location: ' . basename($_SERVER['SCRIPT_NAME']));
            exit;
        }
    }
    $_SESSION['form_ts'] = time() - 10; // reenvio após erro não deve ser bloqueado pelo tempo mínimo
} else {
    $_SESSION['form_ts'] = time();
    $v['inicio'] = date('d/m/Y'); // por defeito, a data de hoje
}

/* ============================ PÁGINA ============================ */

pagina_inicio('Candidatura a estágio', 'm.Abreu – Instalações Técnicas. Os campos com * são obrigatórios.');

if ($sucesso) {
    echo '<div class="ok" role="status"><strong>Candidatura enviada. Obrigado!</strong><br>Se o seu perfil avançar no processo, entraremos em contacto.</div>';
    if ($aviso_diag !== '') {
        echo '<div class="erros" role="alert" style="margin-top:16px"><strong>[Diagnóstico]</strong> ' . h($aviso_diag) . '</div>';
    }
    pagina_fim();
    exit;
}

$dial_atual = ($v['concelho'] === FORA_PT) ? ($paises[$v['pais']] ?? '+') : '+351';
mostrar_erros($erros);
?>
<form method="post" autocomplete="on" enctype="multipart/form-data">
  <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">

  <label for="nome">Nome *</label>
  <input id="nome" name="nome" required maxlength="120" autocomplete="name" value="<?= h($v['nome']) ?>">

  <label for="email">Email *</label>
  <input id="email" name="email" type="email" required maxlength="150" autocomplete="email" value="<?= h($v['email']) ?>">

  <label for="conc">Concelho em que reside *</label>
  <select id="conc" name="concelho" required>
    <option value="">Selecione o concelho</option>
<?php foreach ($grupos as $g): ?>
    <optgroup label="<?= h($g[0]) ?>">
<?php foreach ($g[1] as $o): ?>
      <option value="<?= h($o[0]) ?>"<?= selecionado($v['concelho'], $o[0]) ?><?= $o[0] === 'Braga (Braga)' ? ' style="font-weight:700"' : '' ?>><?= h($o[1]) ?></option>
<?php endforeach; ?>
    </optgroup>
<?php endforeach; ?>
  </select>

  <div id="bloco-pais"<?= $v['concelho'] === FORA_PT ? '' : ' hidden' ?>>
    <label for="pais">País onde reside *</label>
    <select id="pais" name="pais">
      <option value="">Selecione o país</option>
<?php foreach ($paises as $nome_pais => $cod_pais): ?>
      <option value="<?= h($nome_pais) ?>"<?= selecionado($v['pais'], $nome_pais) ?>><?= h($nome_pais) ?></option>
<?php endforeach; ?>
    </select>
  </div>

  <label for="tel">Telefone *</label>
  <div class="tel">
    <span id="prefixo" class="prefixo"><?= h($dial_atual) ?></span>
    <input id="tel" name="telefone" type="tel" required maxlength="20" inputmode="tel" autocomplete="tel-national" placeholder="Número, sem o indicativo" value="<?= h($v['telefone']) ?>">
  </div>

  <label for="habil">Habilitações *</label>
  <select id="habil" name="habilitacoes" required>
    <option value="">Selecione</option>
<?php foreach ($habilitacoes as $c): ?>
    <option<?= selecionado($v['habilitacoes'], $c) ?>><?= h($c) ?></option>
<?php endforeach; ?>
  </select>

  <label for="programa">Estágio a que se candidata *</label>
  <select id="programa" name="programa" required>
    <option value="">Escolha</option>
    <option value="<?= h(PROGRAMA_TALENTO) ?>"<?= selecionado($v['programa'], PROGRAMA_TALENTO) ?>><?= h(PROGRAMA_TALENTO) ?></option>
    <option value="<?= h(PROGRAMA_INOVAR) ?>"<?= selecionado($v['programa'], PROGRAMA_INOVAR) ?>><?= h(PROGRAMA_INOVAR) ?></option>
  </select>

  <div id="bloco-talento"<?= $v['programa'] === PROGRAMA_TALENTO ? '' : ' hidden' ?>>
    <label for="area_talento">Área</label>
    <input id="area_talento" type="text" value="<?= h(AREA_TALENTO) ?>" readonly>
  </div>

  <div id="bloco-inovar"<?= $v['programa'] === PROGRAMA_INOVAR ? '' : ' hidden' ?>>
    <label for="area_inovar">Área *</label>
    <select id="area_inovar" name="area_inovar">
      <option value="">Escolha</option>
<?php foreach ($areas_inovar as $a): ?>
      <option value="<?= h($a) ?>"<?= selecionado($v['area_inovar'], $a) ?>><?= h($a) ?></option>
<?php endforeach; ?>
    </select>
  </div>

  <label for="inicio">Disponibilidade para início <small>(data aproximada)</small> *</label>
  <input id="inicio" name="inicio" required maxlength="10" inputmode="numeric" autocomplete="off"
         placeholder="dd/mm/202x" pattern="(0[1-9]|[12][0-9]|3[01])/(0[1-9]|1[0-2])/202[0-9]"
         data-hoje="<?= h(date('Y-m-d')) ?>" title="Data no formato dd/mm/202x, nunca anterior a hoje" value="<?= h($v['inicio']) ?>">

  <label for="carta">Carta de condução *</label>
  <select id="carta" name="carta" required>
    <option value="">Selecione</option>
<?php foreach ($cartas as $c): ?>
    <option<?= selecionado($v['carta'], $c) ?>><?= h($c) ?></option>
<?php endforeach; ?>
  </select>

  <label for="viatura">Viatura própria? <small>(deslocações a obras)</small></label>
  <select id="viatura" name="viatura">
    <option value="">Prefiro não indicar</option>
<?php foreach ($viaturas as $c): ?>
    <option<?= selecionado($v['viatura'], $c) ?>><?= h($c) ?></option>
<?php endforeach; ?>
  </select>

  <label for="msg">Mensagem <small>(opcional)</small></label>
  <textarea id="msg" name="mensagem" rows="4" maxlength="1500"><?= h($v['mensagem']) ?></textarea>

  <label for="anexos">Anexos <small>(opcional, no máximo 2 ficheiros: por exemplo o CV)</small></label>
  <input id="anexos" name="anexos[]" type="file" multiple accept=".pdf,.doc,.docx,.jpg,.jpeg,.png">
  <p class="dica">PDF, Word, JPG ou PNG, até 3 MB cada. Não envie cópias do Cartão de Cidadão nesta fase.</p>
  <p class="dica" id="anexos-msg" style="color:#7a1f1f" aria-live="polite"></p>

  <div class="hp"><label>Não preencher <input name="_gotcha" tabindex="-1" autocomplete="off"></label></div>

  <label class="check"><input type="checkbox" name="li_aviso" value="1" required>
    <span>Li e compreendi o aviso de privacidade abaixo. *</span></label>

  <label class="check"><input type="checkbox" name="consent_banco_talentos" value="1"<?= $v['banco'] === 'sim' ? ' checked' : '' ?>>
    <span>Opcional: autorizo que a m.Abreu conserve a minha candidatura por 12 meses para futuras oportunidades.</span></label>

  <button type="submit">Enviar candidatura</button>
</form>

<section class="privacy" aria-labelledby="priv">
  <h2 id="priv">Aviso de privacidade</h2>
  <p><strong>Responsável pelo tratamento:</strong> m.Abreu – Instalações Técnicas, unipessoal, Lda. (NIPC 514414120). Contacto: abreu@mabreu.pt, 912854950.</p>
  <p><strong>Finalidade:</strong> avaliar a sua candidatura e contactá-lo(a) no âmbito do processo de seleção para estágio. Enviamos-lhe um email de confirmação com cópia dos dados submetidos.</p>
  <p><strong>Fundamento:</strong> diligências pré-contratuais a seu pedido (art. 6.º, n.º 1, al. b) do RGPD). A conservação para futuras oportunidades só ocorre se der o consentimento opcional acima (al. a)).</p>
  <p><strong>Dados recolhidos:</strong> apenas os indicados no formulário e os ficheiros que decidir anexar (por exemplo, o CV). Não pedimos dados sensíveis.</p>
  <p><strong>Destinatários:</strong> apenas quem participa na seleção na m.Abreu. Os dados não são vendidos nem cedidos a terceiros.</p>
  <p><strong>Conservação:</strong> até 6 meses após o fim do processo de seleção, ou 12 meses se autorizar a conservação opcional. Depois são eliminados.</p>
  <p><strong>Os seus direitos:</strong> acesso, retificação, apagamento, limitação, oposição e portabilidade, através do contacto acima. Pode retirar o consentimento a qualquer momento e apresentar reclamação à CNPD (www.cnpd.pt).</p>
</section>

<script>
(function () {
  var p  = document.getElementById('programa'),
      bt = document.getElementById('bloco-talento'),
      bi = document.getElementById('bloco-inovar'),
      ai = document.getElementById('area_inovar'),
      at = document.getElementById('area_talento');
  function atualizar() {
    var v = p.value;
    bt.hidden = v !== '<?= PROGRAMA_TALENTO ?>';
    bi.hidden = v !== '<?= PROGRAMA_INOVAR ?>';
    ai.required = (v === '<?= PROGRAMA_INOVAR ?>');
    if (v === '<?= PROGRAMA_TALENTO ?>') { at.value = '<?= AREA_TALENTO ?>'; }
    if (v !== '<?= PROGRAMA_INOVAR ?>') { ai.value = ''; }
  }
  p.addEventListener('change', atualizar);
  atualizar();

  // País (só fora de Portugal) e indicativo do telefone
  var DIAL = <?= json_encode($paises, JSON_UNESCAPED_UNICODE) ?>;
  var conc = document.getElementById('conc'),
      bp   = document.getElementById('bloco-pais'),
      pais = document.getElementById('pais'),
      pref = document.getElementById('prefixo');
  function atualizarPais() {
    var fora = conc.value === '<?= FORA_PT ?>';
    bp.hidden = !fora;
    pais.required = fora;
    if (!fora) { pais.value = ''; }
    pref.textContent = fora ? (DIAL[pais.value] || '+') : '+351';
  }
  conc.addEventListener('change', atualizarPais);
  pais.addEventListener('change', atualizarPais);
  atualizarPais();

  // Máscara dd/mm/202x: escreve as barras sozinha; se só escrever o último algarismo do ano, completa o "202"
  var ini = document.getElementById('inicio');
  ini.addEventListener('input', function (e) {
    var tipo = e.inputType || '';
    var apagar = tipo.indexOf('delete') === 0;
    var colar = tipo === 'insertFromPaste';
    var d = ini.value.replace(/\D/g, '').slice(0, 8);
    var ano = d.slice(4);
    if (ano.length === 1 && ano !== '2' && !colar) { ano = '202' + ano; }
    var ok = '', fixo = '202';
    for (var i = 0; i < ano.length; i++) {
      if (i < 3 && ano.charAt(i) !== fixo.charAt(i)) { break; }
      ok += ano.charAt(i);
    }
    d = (d.slice(0, 4) + ok).slice(0, 8);
    var out = d.slice(0, 2);
    if (d.length > 2) { out += '/' + d.slice(2, 4); }
    if (d.length > 4) { out += '/' + d.slice(4); }
    if (!apagar && (d.length === 2 || d.length === 4)) { out += '/'; }
    ini.value = out;
  });

  // Anexos: no máximo 2 ficheiros e até ao tamanho permitido (o servidor volta a verificar)
  var an = document.getElementById('anexos'), anMsg = document.getElementById('anexos-msg');
  var MAXB = <?= MAX_BYTES_ANEXO ?>, MAXN = <?= MAX_ANEXOS ?>;
  an.addEventListener('change', function () {
    var msg = '';
    if (an.files.length > MAXN) { msg = 'Pode anexar no máximo ' + MAXN + ' ficheiros.'; }
    for (var i = 0; i < an.files.length && !msg; i++) {
      if (an.files[i].size > MAXB) { msg = 'O ficheiro "' + an.files[i].name + '" excede ' + (MAXB / 1048576) + ' MB.'; }
    }
    an.setCustomValidity(msg);
    anMsg.textContent = msg;
  });

  // A data de início nunca pode ser anterior a hoje (a verificação definitiva é feita no servidor)
  var hoje = ini.getAttribute('data-hoje');
  function verificarData() {
    var m = /^(\d{2})\/(\d{2})\/(\d{4})$/.exec(ini.value), msg = '';
    if (m && (m[3] + '-' + m[2] + '-' + m[1]) < hoje) {
      msg = 'A data de início não pode ser anterior a hoje (' + hoje.split('-').reverse().join('/') + ').';
    }
    ini.setCustomValidity(msg);
  }
  ini.addEventListener('input', verificarData);
  verificarData();
})();
</script>
<?php pagina_fim(); ?>
<?php
/** Símbolo MAB (PNG em base64), incluído no próprio ficheiro. */
function logo_base64(): string {
    return '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';
}
