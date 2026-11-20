<?php
// ============================================================================
// acessos.php — Registo central de módulos/páginas e controlo de acessos
// ----------------------------------------------------------------------------
// 1) A Matriz de Acessos (configuracoes.php) lê os módulos da tabela
//    `modulos_sistema`, que é mantida automaticamente:
//      • módulos base do sistema (Clientes, Obras, Termos...);
//      • páginas .php novas encontradas na pasta do site (auto-deteção);
//      • módulos que as próprias páginas registam com exigirAcesso().
//
// 2) Numa página nova basta acrescentar no topo (depois do db.php):
//
//        require_once 'acessos.php';
//        exigirAcesso($pdo, 'lsjr_pdct', 'LSJR - Pedidos de Cotação', 'lsjr');
//
//    Na 1ª vez que a página é aberta, o módulo aparece sozinho na matriz
//    (com acesso só para o Admin) e, a partir daí, a página respeita a matriz.
// ============================================================================

if (!function_exists('acessosCriarTabela')) {

    function acessosCriarTabela($pdo) {
        static $feito = false;
        if ($feito) return;
        $pdo->exec("CREATE TABLE IF NOT EXISTS modulos_sistema (
            chave VARCHAR(60) NOT NULL PRIMARY KEY,
            nome VARCHAR(120) NOT NULL,
            ficheiro VARCHAR(120) DEFAULT NULL,
            pai VARCHAR(60) DEFAULT NULL,
            icone VARCHAR(60) DEFAULT 'fa-file-lines',
            cor VARCHAR(20) DEFAULT '#7f8c8d',
            ordem INT DEFAULT 999,
            origem VARCHAR(10) DEFAULT 'auto',
            ativo TINYINT(1) DEFAULT 1,
            novo TINYINT(1) DEFAULT 0,
            criado_em DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $feito = true;
    }

    // Módulos base (os que já existiam na matriz). Só são inseridos se ainda
    // não existirem, por isso nomes alterados pelo Admin mantêm-se.
    function acessosModulosBase() {
        return [
            // chave            nome                     ficheiro              pai        icone                 cor       ordem
            ['clientes',        'Clientes',              'clientes.php',       null,      'fa-users',           '#3498db', 10],
            ['visitas',         'Visitas',               'visitas.php',        null,      'fa-calendar-check',  '#e84393', 20],
            ['servicos',        'Serviços',              'servicos.php',       null,      'fa-screwdriver-wrench','#b32a2a', 30],
            ['obras',           'Obras',                 'obras.php',          null,      'fa-helmet-safety',   '#2ecc71', 40],
            ['relatorios',      'Relatórios de Obra',    'relatorios.php',     null,      'fa-clipboard-list',  '#16a085', 50],
            ['orcamentos',      'Orçamentos',            'orcamentos.php',     null,      'fa-file-invoice',    '#f39c12', 60],
            ['tabela_precos',   'Tabela de Preços',      'tabela_precos.php',  null,      'fa-list-ol',         '#3498db', 70],
            ['termos',          'Menu Principal de Termos','termos.php',       null,      'fa-file-signature',  '#9b59b6', 80],
            ['termos_eletrico', '⚡ Instalações Elétricas','termos_eletrico.php','termos',  'fa-bolt',            '#9b59b6', 81],
            ['termos_scie',     '🧯 SCIE',               'termos_scie.php',    'termos',  'fa-fire-extinguisher','#9b59b6', 82],
            ['termos_ited',     '📡 ITED',               'termos_ited.php',    'termos',  'fa-tower-broadcast', '#9b59b6', 83],
            ['termos_cctv',     '📹 CCTV',               'termos_cctv.php',    'termos',  'fa-video',           '#9b59b6', 84],
            ['termos_intrusao', '🚨 Alarme de Intrusão', 'termos_intrusao.php','termos',  'fa-bell',            '#9b59b6', 85],
            ['equipa',          'Equipa',                'utilizadores.php',   null,      'fa-id-card-clip',    '#7f8c8d', 90],
            ['viaturas',        'Viaturas e Frota',      'viaturas.php',       null,      'fa-truck-pickup',    '#d35400', 100],
            ['equipamentos',    'Equipamentos',          'equipamentos.php',   null,      'fa-toolbox',         '#4a69bd', 110],
            ['configuracoes',   'Configurações',         'configuracoes.php',  null,      'fa-gear',            '#2c3e50', 120],
        ];
    }

    // "lsjr_pdct" -> "LSJR PDCT", "termos_knx" -> "Termos KNX"
    function acessosNomeAmigavel($base) {
        $siglas = ['lsjr','pdct','knx','scie','ited','cctv','fserv','mab','iva','ets','pdf','ncre','fat','sms','api','jdias' => 'J. Dias'];
        $partes = preg_split('/[_\-]+/', $base);
        $saida = [];
        foreach ($partes as $p) {
            if ($p === '') continue;
            if (isset($siglas[$p])) { $saida[] = $siglas[$p]; continue; }
            if (in_array($p, $siglas, true)) { $saida[] = strtoupper($p); continue; }
            $saida[] = mb_strtoupper(mb_substr($p, 0, 1)) . mb_substr($p, 1);
        }
        return implode(' ', $saida) ?: $base;
    }

    // Ficheiros que NÃO são páginas de módulo (PDFs, formulários, ajax, etc.)
    function acessosIgnorarFicheiro($base) {
        $exatos = ['db', 'functions', 'index', 'login', 'logout', 'perfil', 'acessos', 'registo',
                   'recuperar_password', 'reset_password', 'sem_permissao', 'phpinfo', 'info', 'config', 'conexao'];
        if (in_array($base, $exatos, true)) return true;
        if ($base === '' || $base[0] === '_' || $base[0] === '.') return true;
        $padrao = '/(^|_)(pdf|form|ajax|api|export|exportar|import|importar|guardar|gravar|save|apagar|eliminar|delete|del|'
                . 'print|imprimir|excel|csv|xlsx|download|upload|cron|teste|test|old|bak|backup|copia|copy|tmp|debug|'
                . 'lib|inc|include|helper|helpers|funcoes|header|footer|menu|topbar|modal|acao|action|processar|process)(_|$|\d)/';
        return (bool)preg_match($padrao, $base);
    }

    // Garante que o Admin fica com acesso a um módulo novo (os outros grupos ficam sem acesso)
    function acessosPermissaoInicial($pdo, $chave) {
        try {
            $st = $pdo->prepare("INSERT IGNORE INTO permissoes (grupo, modulo, pode_aceder) VALUES ('admin', :m, 1)");
            $st->execute(['m' => $chave]);
        } catch (Exception $e) { /* tabela permissoes pode não existir ainda */ }
    }

    // Regista (se ainda não existir) um módulo na tabela
    function registarModulo($pdo, $chave, $nome = null, $pai = null, $ficheiro = null) {
        acessosCriarTabela($pdo);
        $chave = substr(preg_replace('/[^a-z0-9_]/', '', strtolower($chave)), 0, 60);
        if ($chave === '') return false;
        $st = $pdo->prepare("INSERT IGNORE INTO modulos_sistema (chave, nome, ficheiro, pai, origem, novo) VALUES (?, ?, ?, ?, 'auto', 1)");
        $st->execute([$chave, $nome ?: acessosNomeAmigavel($chave), $ficheiro, $pai ?: null]);
        if ($st->rowCount() > 0) acessosPermissaoInicial($pdo, $chave);
        return $chave;
    }

    // Sincroniza: base + permissões já existentes + páginas .php novas na pasta.
    // Devolve o nº de módulos novos detetados nesta passagem.
    function sincronizarModulos($pdo, $pasta = null) {
        acessosCriarTabela($pdo);
        $novos = 0;

        // 1. Módulos base
        $ins = $pdo->prepare("INSERT IGNORE INTO modulos_sistema (chave, nome, ficheiro, pai, icone, cor, ordem, origem, novo)
                              VALUES (?, ?, ?, ?, ?, ?, ?, 'base', 0)");
        foreach (acessosModulosBase() as $b) { $ins->execute($b); }

        $existentes = $pdo->query("SELECT chave, ficheiro, pai FROM modulos_sistema")->fetchAll(PDO::FETCH_ASSOC);
        $por_chave = []; $ficheiros = [];
        foreach ($existentes as $e) {
            $por_chave[$e['chave']] = $e;
            if (!empty($e['ficheiro'])) $ficheiros[strtolower($e['ficheiro'])] = true;
        }

        // 2. Módulos que já estão na tabela de permissões mas não na lista
        try {
            foreach ($pdo->query("SELECT DISTINCT modulo FROM permissoes")->fetchAll(PDO::FETCH_COLUMN) as $m) {
                if ($m !== '' && !isset($por_chave[$m])) {
                    $pdo->prepare("INSERT IGNORE INTO modulos_sistema (chave, nome, origem, novo) VALUES (?, ?, 'auto', 1)")
                        ->execute([$m, acessosNomeAmigavel($m)]);
                    $por_chave[$m] = ['chave' => $m, 'ficheiro' => null, 'pai' => null];
                    $novos++;
                }
            }
        } catch (Exception $e) {}

        // 3. Páginas .php novas na pasta do site
        $pasta = $pasta ?: __DIR__;
        $lista = glob(rtrim($pasta, '/\\') . DIRECTORY_SEPARATOR . '*.php') ?: [];
        $bases = array_map(fn($f) => strtolower(basename($f, '.php')), $lista);
        usort($bases, fn($a, $b) => strlen($a) <=> strlen($b)); // pais (mais curtos) primeiro

        foreach ($bases as $base) {
            if (isset($por_chave[$base]) || isset($ficheiros[$base . '.php'])) continue;
            if (acessosIgnorarFicheiro($base)) continue;
            $chave = substr(preg_replace('/[^a-z0-9_]/', '', $base), 0, 60);
            if ($chave === '' || isset($por_chave[$chave])) continue;

            // Pai: prefixo antes do 1º "_" (ex.: lsjr_pdct -> lsjr), se esse módulo existir
            $pai = null;
            if (strpos($chave, '_') !== false) {
                $prefixo = substr($chave, 0, strpos($chave, '_'));
                if (isset($por_chave[$prefixo])) {
                    $pai = $por_chave[$prefixo]['pai'] ?: $prefixo; // só um nível de hierarquia
                }
            }

            $st = $pdo->prepare("INSERT IGNORE INTO modulos_sistema (chave, nome, ficheiro, pai, origem, novo) VALUES (?, ?, ?, ?, 'auto', 1)");
            $st->execute([$chave, acessosNomeAmigavel($chave), $base . '.php', $pai]);
            if ($st->rowCount() > 0) {
                acessosPermissaoInicial($pdo, $chave);
                $por_chave[$chave] = ['chave' => $chave, 'ficheiro' => $base . '.php', 'pai' => $pai];
                $novos++;
            }
        }
        return $novos;
    }
}

if (!function_exists('temAcesso')) {
    // O Admin tem sempre acesso; os restantes perfis seguem a Matriz de Acessos
    function temAcesso($pdo, $chave) {
        $perfil = $_SESSION['perfil'] ?? '';
        if ($perfil === 'admin') return true;
        try {
            $st = $pdo->prepare("SELECT pode_aceder FROM permissoes WHERE grupo = ? AND modulo = ?");
            $st->execute([$perfil, $chave]);
            return (int)$st->fetchColumn() === 1;
        } catch (Exception $e) {
            return false;
        }
    }
}

if (!function_exists('exigirAcesso')) {
    // Usar no topo de cada página: regista o módulo (1ª vez) e bloqueia quem não tem acesso
    function exigirAcesso($pdo, $chave, $nome = null, $pai = null) {
        if (!isset($_SESSION['user_id'])) {
            header("Location: index.php");
            exit;
        }
        try {
            registarModulo($pdo, $chave, $nome, $pai, basename($_SERVER['SCRIPT_NAME'] ?? ''));
        } catch (Exception $e) {}
        if (!temAcesso($pdo, $chave)) {
            header("Location: index.php?erro=sem_permissao");
            exit;
        }
    }
}
