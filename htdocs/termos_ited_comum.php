<?php
// =====================================================================
// TERMOS ITED — funções e tabelas comuns
// Usado por: termos_ited.php (lista), termo_ited_form.php (formulário) e termo_ited_pdf.php (PDF)
//
// Cada registo ITED tem dois documentos:
//   - Termo de responsabilidade de execução da instalação ITED (Manual ITED 4.ª ed. / DL 123/2009 na redação atual)
//   - Relatório de ensaios / certificação dos pontos RJ45 (um ponto por linha: identificação, local, painel/porta,
//     categoria, configuração do ensaio, comprimento, mapa de cablagem, margem NEXT e resultado)
//
// Base de dados (criada automaticamente):
//   termos_ited          -> dados do termo (obra, edifício, projeto, técnico, equipamento de ensaio, texto do termo)
//   termos_ited_pontos   -> pontos RJ45 ensaiados
//   termos_ited_arquivo  -> NUNCA PERDER DADOS: cópia do termo e dos pontos antes de cada alteração ou eliminação
// Os dados do técnico / equipamento de ensaio da última gravação ficam em configuracoes (chaves ited_*) e
// preenchem os termos novos.
// =====================================================================

$ITED_TIPOS_EDIFICIO = ['Residencial', 'Não residencial', 'Misto', 'Moradia unifamiliar', 'Loteamento / urbanização', 'Outro'];
$ITED_CATEGORIAS = ['Cat. 6', 'Cat. 6A', 'Cat. 5e', 'Cat. 7', 'Cat. 7A', 'Cat. 8'];
$ITED_CONFIGURACOES = ['Ligação permanente', 'Canal'];
$ITED_CONFIG_PADRAO = [
    'ited_tecnico_nome'      => 'Mário Manuel Martins Abreu',
    'ited_tecnico_inscricao' => '',
    'ited_entidade_registo'  => '',
    'ited_equip_teste'       => '',
    'ited_equip_serie'       => '',
    'ited_equip_calibracao'  => '',
    'ited_norma_teste'       => 'ISO/IEC 11801 Classe E (Cat. 6) — Ligação permanente',
];

function itedCriarTabelas($pdo) {
    $pdo->exec("CREATE TABLE IF NOT EXISTS termos_ited (
        id INT AUTO_INCREMENT PRIMARY KEY,
        id_obra INT NULL,
        id_cliente INT NULL,
        data_emissao DATE NULL,
        estado VARCHAR(20) NOT NULL DEFAULT 'Rascunho',
        tipo_edificio VARCHAR(40) NOT NULL DEFAULT '',
        edificio VARCHAR(255) NOT NULL DEFAULT '',
        morada VARCHAR(255) NOT NULL DEFAULT '',
        localidade VARCHAR(150) NOT NULL DEFAULT '',
        n_fogos INT NULL,
        projetista_nome VARCHAR(150) NOT NULL DEFAULT '',
        projetista_inscricao VARCHAR(60) NOT NULL DEFAULT '',
        projeto_data DATE NULL,
        tecnico_nome VARCHAR(150) NOT NULL DEFAULT '',
        tecnico_inscricao VARCHAR(60) NOT NULL DEFAULT '',
        entidade_registo VARCHAR(60) NOT NULL DEFAULT '',
        assinatura TINYINT(1) NOT NULL DEFAULT 1,
        texto_termo TEXT NULL,
        equip_teste VARCHAR(150) NOT NULL DEFAULT '',
        equip_serie VARCHAR(80) NOT NULL DEFAULT '',
        equip_calibracao DATE NULL,
        norma_teste VARCHAR(150) NOT NULL DEFAULT '',
        data_ensaios DATE NULL,
        observacoes TEXT NULL,
        criado_por INT NULL, criado_em DATETIME NULL, atualizado_em DATETIME NULL,
        KEY idx_obra (id_obra)
    ) DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS termos_ited_pontos (
        id INT AUTO_INCREMENT PRIMARY KEY,
        id_termo INT NOT NULL,
        ordem INT NOT NULL DEFAULT 0,
        identificacao VARCHAR(60) NOT NULL DEFAULT '',
        localizacao VARCHAR(150) NOT NULL DEFAULT '',
        painel_porta VARCHAR(60) NOT NULL DEFAULT '',
        categoria VARCHAR(20) NOT NULL DEFAULT '',
        configuracao VARCHAR(30) NOT NULL DEFAULT '',
        comprimento DECIMAL(8,2) NULL,
        mapa VARCHAR(5) NOT NULL DEFAULT 'OK',
        margem_next DECIMAL(6,2) NULL,
        resultado VARCHAR(10) NOT NULL DEFAULT 'PASSA',
        observacoes VARCHAR(255) NOT NULL DEFAULT '',
        KEY idx_termo (id_termo)
    ) DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS termos_ited_arquivo (
        id INT AUTO_INCREMENT PRIMARY KEY,
        id_termo INT NOT NULL,
        acao VARCHAR(20) NOT NULL,
        dados_json MEDIUMTEXT NOT NULL,
        pontos_json MEDIUMTEXT NOT NULL,
        arquivado_por INT NULL,
        arquivado_em DATETIME NOT NULL,
        KEY idx_termo (id_termo)
    ) DEFAULT CHARSET=utf8mb4");
}

// Quem pode usar (admin / administrativo, ou permissão "termos" ou "termos_ited")
function itedTemAcesso($pdo) {
    $perfil = $_SESSION['perfil'] ?? '';
    if (in_array($perfil, ['admin', 'administrativo'])) return true;
    $st = $pdo->prepare("SELECT modulo FROM permissoes WHERE grupo = :perfil AND pode_aceder = 1");
    $st->execute(['perfil' => $perfil]);
    $ac = $st->fetchAll(PDO::FETCH_COLUMN);
    return in_array('termos', $ac) || in_array('termos_ited', $ac);
}

// Dados guardados do técnico / equipamento de ensaio (configuracoes) com os valores por defeito
function itedConfig($pdo) {
    global $ITED_CONFIG_PADRAO;
    $cfg = $ITED_CONFIG_PADRAO;
    try {
        $st = $pdo->query("SELECT chave, valor FROM configuracoes WHERE chave LIKE 'ited\\_%'");
        foreach ($st->fetchAll(PDO::FETCH_KEY_PAIR) as $k => $v) if (array_key_exists($k, $cfg) && trim((string)$v) !== '') $cfg[$k] = $v;
    } catch (Exception $e) {}
    return $cfg;
}
function itedGuardarConfig($pdo, array $valores) {
    global $ITED_CONFIG_PADRAO;
    $st = $pdo->prepare("INSERT INTO configuracoes (chave, valor) VALUES (?, ?) ON DUPLICATE KEY UPDATE valor = VALUES(valor)");
    foreach ($valores as $k => $v) if (array_key_exists($k, $ITED_CONFIG_PADRAO)) $st->execute([$k, (string)$v]);
}

// Cópia do termo e dos pontos (antes de alterar ou apagar)
function itedArquivar($pdo, $id_termo, $acao) {
    $st = $pdo->prepare("SELECT * FROM termos_ited WHERE id = ?"); $st->execute([$id_termo]);
    $t = $st->fetch(PDO::FETCH_ASSOC);
    if (!$t) return;
    $sp = $pdo->prepare("SELECT * FROM termos_ited_pontos WHERE id_termo = ? ORDER BY ordem, id"); $sp->execute([$id_termo]);
    $pdo->prepare("INSERT INTO termos_ited_arquivo (id_termo, acao, dados_json, pontos_json, arquivado_por, arquivado_em) VALUES (?, ?, ?, ?, ?, NOW())")
        ->execute([$id_termo, $acao, json_encode($t, JSON_UNESCAPED_UNICODE), json_encode($sp->fetchAll(PDO::FETCH_ASSOC), JSON_UNESCAPED_UNICODE), $_SESSION['user_id'] ?? null]);
}

// Termo com a obra e o cliente
function itedTermo($pdo, $id) {
    $st = $pdo->prepare("SELECT t.*, o.nome AS obra_nome, o.referencia_obra, o.morada AS obra_morada,
                                c.nome AS cliente_nome, c.nif AS cliente_nif, c.morada AS cliente_morada, c.codigo_postal AS cliente_cp, c.cidade AS cliente_cidade
                         FROM termos_ited t
                         LEFT JOIN obras o ON o.id = t.id_obra
                         LEFT JOIN clientes c ON c.id = COALESCE(t.id_cliente, o.id_cliente)
                         WHERE t.id = ?");
    $st->execute([$id]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}
function itedPontos($pdo, $id) {
    $st = $pdo->prepare("SELECT * FROM termos_ited_pontos WHERE id_termo = ? ORDER BY ordem ASC, id ASC");
    $st->execute([$id]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

// Resumo dos ensaios: [total, passa, falha, comprimento máximo, comprimento médio]
function itedResumo($pontos) {
    $n = count($pontos); $ok = 0; $comp = [];
    foreach ($pontos as $p) {
        if (($p['resultado'] ?? '') === 'PASSA') $ok++;
        if ($p['comprimento'] !== null && $p['comprimento'] !== '') $comp[] = (float)$p['comprimento'];
    }
    return [$n, $ok, $n - $ok, $comp ? max($comp) : null, $comp ? array_sum($comp) / count($comp) : null];
}

// Texto padrão do termo de responsabilidade (pode ser alterado no formulário de cada termo)
function itedTextoPadrao($t) {
    $tec = trim((string)($t['tecnico_nome'] ?? '')) ?: '_______________';
    $insc = trim((string)($t['tecnico_inscricao'] ?? ''));
    $reg = trim((string)($t['entidade_registo'] ?? ''));
    $edif = trim((string)($t['edificio'] ?? '')) ?: trim((string)($t['obra_nome'] ?? ''));
    $morada = trim(trim((string)($t['morada'] ?? '')) . (trim((string)($t['localidade'] ?? '')) !== '' ? ', ' . trim($t['localidade']) : ''));
    $proj = '';
    if (trim((string)($t['projetista_nome'] ?? '')) !== '') {
        $proj = ' da autoria de ' . trim($t['projetista_nome']) . (trim((string)($t['projetista_inscricao'] ?? '')) !== '' ? ' (inscrição n.º ' . trim($t['projetista_inscricao']) . ')' : '');
    }
    $p1 = "Eu, " . $tec . ", técnico ITED" . ($insc !== '' ? " inscrito na ANACOM com o n.º " . $insc : '')
        . ", ao serviço da entidade instaladora m. Abreu – Instalações Técnicas, Lda." . ($reg !== '' ? " (registo n.º " . $reg . ")" : '')
        . ", declaro, para os devidos efeitos, que a instalação de infraestruturas de telecomunicações em edifícios (ITED)"
        . ($edif !== '' ? " do edifício «" . $edif . "»" : '') . ($morada !== '' ? ", sito em " . $morada : '')
        . ", foi executada de acordo com o projeto técnico" . $proj . " e com as regras técnicas do Manual ITED (4.ª edição),"
        . " em cumprimento do Decreto-Lei n.º 123/2009, de 21 de maio, na sua redação atual.";
    $p2 = "Declaro ainda que foram realizados os ensaios de funcionalidade previstos no Manual ITED, cujos resultados constam do"
        . " relatório de ensaios que acompanha este termo, e que a instalação se encontra em condições de ser utilizada.";
    return $p1 . "\n\n" . $p2;
}
