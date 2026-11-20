<?php
// =====================================================================
// CONTROLO DE ACESSOS — definições comuns
// Usado por: termos_acessos.php, termos_acessos_form.php, termos_acessos_pdf.php
// Um registo pode ter várias controladoras (de qualquer marca); cada controladora tem as suas portas.
// =====================================================================

// Marcas de origem (as escritas em "Outra" ficam gravadas em configuracoes → ca_marcas_extra)
$CA_MARCAS_BASE = ['ZKTeco', 'Hikvision', 'Dahua', 'Suprema', 'Paxton', 'Nedap'];

// Modelos conhecidos e nº de portas (sugestões ao escrever o modelo)
$CA_MODELOS_CONHECIDOS = [
    'ZKTeco' => ['C3-100' => 1, 'C3-200' => 2, 'C3-400' => 4, 'inBio160' => 1, 'inBio260' => 2, 'inBio460' => 4],
];

// Softwares de origem (os escritos em "Outro" ficam gravados em configuracoes → ca_softwares_extra)
$CA_SOFTWARES_BASE = ['ZKBioAccess', 'ZKBio CVSecurity', 'ZKAccess 3.5', 'BioTime', 'Hik-Connect / iVMS-4200', 'Sem software (autónoma)'];

$CA_COMUNICACAO = ['TCP/IP', 'RS485', 'TCP/IP + RS485', 'Wiegand', 'Wi-Fi'];
$CA_FECHADURAS  = ['Eletroíman', 'Trinco elétrico', 'Fechadura elétrica / motorizada', 'Torniquete / barreira', 'Portão / automatismo', 'Outro'];
$CA_MODOS       = ['Fail-safe (abre sem energia)', 'Fail-secure (fica fechada sem energia)'];
$CA_CREDENCIAIS = ['Cartão RFID', 'PIN', 'Impressão digital', 'Facial', 'QR / Telemóvel'];

// Checklist de testes e entrega (chave => texto)
$CA_TESTES = [
    'abertura_credencial' => 'Abertura com credencial em todas as portas',
    'botao_saida'         => 'Botões de saída a abrir corretamente',
    'sensor_porta'        => 'Sensores de porta / alarme de porta aberta',
    'emergencia'          => 'Quebra-vidros / emergência liberta a porta',
    'falha_energia'       => 'Comportamento sem energia conforme previsto (fail-safe / fail-secure)',
    'bateria'             => 'Bateria de backup testada',
    'software'            => 'Comunicação com o software / servidor',
    'data_hora'           => 'Data e hora sincronizadas',
    'eventos'             => 'Registo de eventos a funcionar',
    'utilizadores_teste'  => 'Utilizadores e cartões de teste removidos',
    'passwords'           => 'Passwords de fábrica alteradas',
    'backup'              => 'Cópia da configuração guardada',
    'formacao'            => 'Cliente formado na utilização do sistema'
];

function caColunaExiste($pdo, $tabela, $coluna) {
    return (bool)$pdo->query("SHOW COLUMNS FROM `$tabela` LIKE " . $pdo->quote($coluna))->fetch();
}

function caCriarTabelas($pdo) {
    // Cabeçalho do registo (dados gerais + software + testes)
    $pdo->exec("CREATE TABLE IF NOT EXISTS termos_acessos (
        id INT AUTO_INCREMENT PRIMARY KEY,
        referencia VARCHAR(20) NULL,
        id_cliente INT NULL,
        id_obra INT NULL,
        local_instalacao VARCHAR(250) NULL,
        gps VARCHAR(60) NULL,
        data_instalacao DATE NULL,
        id_tecnico INT NULL,
        tecnico VARCHAR(100) NULL,
        software VARCHAR(80) NULL,
        servidor VARCHAR(150) NULL,
        software_utilizador VARCHAR(100) NULL,
        software_password VARCHAR(100) NULL,
        acesso_remoto TEXT NULL,
        testes TEXT NULL,
        entregue_a VARCHAR(150) NULL,
        data_entrega DATE NULL,
        observacoes TEXT NULL,
        criado_por INT NULL,
        criado_em DATETIME NULL,
        atualizado_em DATETIME NULL
    ) DEFAULT CHARSET=utf8mb4");
    foreach (['gps' => "VARCHAR(60) NULL AFTER local_instalacao", 'id_tecnico' => "INT NULL AFTER data_instalacao"] as $col => $def) {
        if (!caColunaExiste($pdo, 'termos_acessos', $col)) $pdo->exec("ALTER TABLE termos_acessos ADD COLUMN $col $def");
    }

    // Controladoras (várias por registo, qualquer marca)
    $pdo->exec("CREATE TABLE IF NOT EXISTS termos_acessos_controladoras (
        id INT AUTO_INCREMENT PRIMARY KEY,
        id_termo INT NOT NULL,
        ordem INT NOT NULL DEFAULT 1,
        marca VARCHAR(60) NULL,
        modelo VARCHAR(60) NULL,
        num_portas INT NOT NULL DEFAULT 1,
        num_serie VARCHAR(60) NULL,
        firmware VARCHAR(60) NULL,
        localizacao VARCHAR(200) NULL,
        alimentacao VARCHAR(100) NULL,
        bateria VARCHAR(60) NULL,
        comunicacao VARCHAR(30) NULL,
        ip VARCHAR(45) NULL,
        mascara VARCHAR(45) NULL,
        gateway VARCHAR(45) NULL,
        dns VARCHAR(45) NULL,
        porta_tcp VARCHAR(10) NULL,
        mac VARCHAR(30) NULL,
        endereco_rs485 VARCHAR(10) NULL,
        password_comunicacao VARCHAR(100) NULL,
        INDEX (id_termo)
    ) DEFAULT CHARSET=utf8mb4");

    // Portas (de cada controladora)
    $pdo->exec("CREATE TABLE IF NOT EXISTS termos_acessos_portas (
        id INT AUTO_INCREMENT PRIMARY KEY,
        id_termo INT NOT NULL,
        id_controladora INT NULL,
        numero INT NOT NULL DEFAULT 1,
        nome VARCHAR(100) NULL,
        leitor_entrada VARCHAR(100) NULL,
        leitor_saida VARCHAR(100) NULL,
        credenciais VARCHAR(150) NULL,
        fechadura VARCHAR(60) NULL,
        modo_fechadura VARCHAR(60) NULL,
        botao_saida VARCHAR(5) NULL,
        sensor_porta VARCHAR(5) NULL,
        quebra_vidros VARCHAR(5) NULL,
        tempo_abertura VARCHAR(10) NULL,
        observacoes TEXT NULL,
        INDEX (id_termo)
    ) DEFAULT CHARSET=utf8mb4");
    if (!caColunaExiste($pdo, 'termos_acessos_portas', 'id_controladora')) {
        $pdo->exec("ALTER TABLE termos_acessos_portas ADD COLUMN id_controladora INT NULL AFTER id_termo");
    }

    // Migração da 1ª versão (uma controladora guardada no próprio registo)
    if (caColunaExiste($pdo, 'termos_acessos', 'modelo')) {
        $antigos = $pdo->query("SELECT t.* FROM termos_acessos t
                                WHERE NOT EXISTS (SELECT 1 FROM termos_acessos_controladoras c WHERE c.id_termo = t.id)
                                AND t.modelo IS NOT NULL")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($antigos as $a) {
            $modelo = ($a['modelo'] === 'Outro' && !empty($a['modelo_outro'])) ? $a['modelo_outro'] : $a['modelo'];
            $pdo->prepare("INSERT INTO termos_acessos_controladoras (id_termo, ordem, marca, modelo, num_portas, num_serie, firmware, localizacao, alimentacao, bateria, comunicacao, ip, mascara, gateway, dns, porta_tcp, mac, endereco_rs485, password_comunicacao)
                           VALUES (?, 1, 'ZKTeco', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
                ->execute([$a['id'], $modelo, (int)$a['num_portas'], $a['num_serie'], $a['firmware'], $a['localizacao_controladora'], $a['alimentacao'], $a['bateria'],
                           $a['comunicacao'], $a['ip'], $a['mascara'], $a['gateway'], $a['dns'], $a['porta_tcp'], $a['mac'], $a['endereco_rs485'], $a['password_comunicacao']]);
            $id_ctrl = $pdo->lastInsertId();
            $pdo->prepare("UPDATE termos_acessos_portas SET id_controladora = ? WHERE id_termo = ? AND id_controladora IS NULL")->execute([$id_ctrl, $a['id']]);
        }
    }
}

// Listas com os valores escritos pelo utilizador ("Outro"), guardados em configuracoes
function caListaExtra($pdo, $chave) {
    try {
        $st = $pdo->prepare("SELECT valor FROM configuracoes WHERE chave = ? LIMIT 1");
        $st->execute([$chave]);
        $lista = json_decode((string)$st->fetchColumn(), true);
        return is_array($lista) ? $lista : [];
    } catch (Exception $e) { return []; }
}
function caJuntarExtra($pdo, $chave, $valor, $base) {
    $valor = trim((string)$valor);
    if ($valor === '') return;
    foreach ($base as $b) { if (mb_strtolower($b, 'UTF-8') === mb_strtolower($valor, 'UTF-8')) return; }
    $lista = caListaExtra($pdo, $chave);
    foreach ($lista as $b) { if (mb_strtolower($b, 'UTF-8') === mb_strtolower($valor, 'UTF-8')) return; }
    $lista[] = $valor;
    $json = json_encode(array_values($lista), JSON_UNESCAPED_UNICODE);
    $pdo->prepare("INSERT INTO configuracoes (chave, valor) VALUES (?, ?) ON DUPLICATE KEY UPDATE valor = ?")->execute([$chave, $json, $json]);
}

// Número único partilhado (visita → orçamento → obra → termo). Formato AA + sequência, mínimo AA0100.
if (!function_exists('gerarNumeroProjeto')) {
    function gerarNumeroProjeto($pdo) {
        $ano = date('y');
        $ultima = $pdo->query("SELECT valor FROM configuracoes WHERE chave = 'ultimo_numero_projeto'")->fetchColumn();
        if ($ultima && substr($ultima, 0, 2) === $ano) {
            $n = (string)((int)$ultima + 1);
            if ((int)$n < (int)($ano . '0100')) $n = $ano . '0100';
        } else {
            $n = $ano . '0100';
        }
        $pdo->prepare("INSERT INTO configuracoes (chave, valor) VALUES ('ultimo_numero_projeto', ?) ON DUPLICATE KEY UPDATE valor = ?")->execute([$n, $n]);
        return $n;
    }
}

// Quem pode usar o módulo
function caTemAcesso($pdo) {
    $perfil = $_SESSION['perfil'] ?? '';
    if (in_array($perfil, ['admin', 'administrativo'])) return true;
    $st = $pdo->prepare("SELECT modulo FROM permissoes WHERE grupo = :perfil AND pode_aceder = 1");
    $st->execute(['perfil' => $perfil]);
    return in_array('termos_acessos', $st->fetchAll(PDO::FETCH_COLUMN));
}
