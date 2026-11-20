<?php
// Configurar cookies de sessão ANTES de iniciar
ini_set('session.cookie_httponly', 1);
ini_set('session.cookie_samesite', 'Strict');
// Descomentar quando o site estiver em HTTPS:
// ini_set('session.cookie_secure', 1);

session_start();
require_once 'db.php';
require_once 'functions.php'; // REGRA DE OURO: Funções centralizadas (Log, CSRF, etc.)

// 🛡️ REGRA DE OURO (SEGURANÇA): GERAR TOKEN CSRF PARA A PÁGINA
$csrf_token = gerar_csrf_token();

// --- LOGOUT ---
if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: index.php");
    exit;
}

$erro_login = '';
$msg_recuperacao = '';
$tipo_msg_rec = '';

if (isset($_GET['erro']) && $_GET['erro'] == 'sem_permissao') {
    $erro_login = "Acesso negado: O seu perfil não tem permissão para este módulo.";
}

if (isset($_GET['recuperado']) && $_GET['recuperado'] == 1) {
    $msg_recuperacao = "Novo código guardado com sucesso! Já pode iniciar sessão.";
    $tipo_msg_rec = "sucesso";
}

// --- RECUPERAÇÃO DE PASSWORD ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['btn_recuperar'])) {

    // 🛡️ Validação CSRF centralizada
    if (!validar_csrf_token($_POST['csrf_token'] ?? '')) {
        die("Pedido inválido ou expirado por segurança (CSRF). Por favor recarregue a página e tente novamente.");
    }

    $rec_username = trim($_POST['rec_username'] ?? '');
    $rec_nif      = trim($_POST['rec_nif'] ?? '');
    $nova_pass    = trim($_POST['nova_pass'] ?? '');

    if (!preg_match('/^\d{4}$/', $nova_pass)) {
        $msg_recuperacao = "Erro: O novo código tem de ter exatamente 4 números.";
        $tipo_msg_rec = "erro";
    } else {
        $stmt = $pdo->prepare("SELECT id FROM utilizadores WHERE username = :u AND nif = :n LIMIT 1");
        $stmt->execute(['u' => $rec_username, 'n' => $rec_nif]);
        $user_rec = $stmt->fetch();

        if ($user_rec) {
            $hash = password_hash($nova_pass, PASSWORD_DEFAULT);
            $upd  = $pdo->prepare("UPDATE utilizadores SET password = :p WHERE id = :id");
            $upd->execute(['p' => $hash, 'id' => $user_rec['id']]);

            registrarLog($pdo, "Recuperou a password para o utilizador: $rec_username", "Autenticação");

            header("Location: index.php?recuperado=1");
            exit;
        } else {
            $msg_recuperacao = "Dados incorretos. Verifique o Utilizador e o NIF.";
            $tipo_msg_rec = "erro";
        }
    }
}

// --- LOGIN ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['btn_login']) && !isset($_SESSION['user_id'])) {

    // 🛡️ Validação CSRF centralizada
    if (!validar_csrf_token($_POST['csrf_token'] ?? '')) {
        die("Pedido inválido ou expirado por segurança (CSRF). Por favor recarregue a página e tente novamente.");
    }

    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';

    if (!empty($username) && !empty($password)) {
        $stmt = $pdo->prepare("SELECT * FROM utilizadores WHERE username = :username LIMIT 1");
        $stmt->execute(['username' => $username]);
        $user = $stmt->fetch();

        $autenticado = false;

        if ($user) {
            // Suporte a migração suave de passwords antigas em texto simples
            $is_plain_text = !str_starts_with($user['password'], '$');

            if ($is_plain_text && $password === $user['password']) {
                // Migração: converter para hash na primeira autenticação
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $pdo->prepare("UPDATE utilizadores SET password = :p WHERE id = :id")
                    ->execute(['p' => $hash, 'id' => $user['id']]);
                $autenticado = true;
            } elseif (!$is_plain_text && password_verify($password, $user['password'])) {
                $autenticado = true;
            }
        }

        if ($autenticado) {
            if ($user['ativo'] == 1) {
                // Regenerar ID de sessão após login (previne session fixation)
                session_regenerate_id(true);

                $_SESSION['user_id']  = $user['id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['nome']     = $user['nome'];
                $_SESSION['perfil']   = $user['perfil'];

                registrarLog($pdo, "Iniciou sessão", "Autenticação");

                header("Location: index.php");
                exit;
            } else {
                $erro_login = "Esta conta encontra-se desativada.";
            }
        } else {
            $erro_login = "Utilizador ou código incorretos.";
        }
    }
}

// ==========================================
// VISTA DO DASHBOARD (LOGADO)
// ==========================================
if (isset($_SESSION['user_id'])):

    $nome_completo = $_SESSION['nome'] ?? 'Utilizador';
    $primeiro_nome = explode(' ', trim($nome_completo))[0];
    $is_admin      = ($_SESSION['perfil'] === 'admin');

    $stmt_perm = $pdo->prepare("SELECT modulo FROM permissoes WHERE grupo = :perfil AND pode_aceder = 1");
    $stmt_perm->execute(['perfil' => $_SESSION['perfil']]);
    $meus_acessos = $stmt_perm->fetchAll(PDO::FETCH_COLUMN);
?>
<!DOCTYPE html>
<html lang="pt-PT">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Painel Central - Gestão MAB</title>
    <link rel="icon" href="favicon.ico" type="image/x-icon">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { font-family: system-ui, -apple-system, sans-serif; box-sizing: border-box; }
        body { background-color: #f7f9fc; margin: 0; color: #333; padding-bottom: 50px; }

        .container { max-width: 1300px; margin: 40px auto; padding: 0 20px; }

        .header-painel { display: flex; justify-content: space-between; align-items: flex-end; margin-bottom: 30px; border-bottom: 2px solid #e1e5ea; padding-bottom: 20px; }
        .boas-vindas h1 { margin: 0; font-size: 28px; color: #2c3e50; font-weight: 800; }
        .boas-vindas p  { color: #7f8c8d; margin: 5px 0 0 0; font-size: 15px; }
        .data-hoje { font-size: 14px; color: #95a5a6; font-weight: 600; background: #fff; padding: 8px 15px; border-radius: 20px; border: 1px solid #e1e5ea; box-shadow: 0 2px 10px rgba(0,0,0,0.02); }

        .grid-modulos { display: grid; grid-template-columns: repeat(auto-fill, minmax(250px, 1fr)); gap: 20px; }

        .card-modulo { background: #fff; border-radius: 12px; padding: 25px; text-decoration: none; color: #333; box-shadow: 0 4px 15px rgba(0,0,0,0.03); border: 1px solid #e1e5ea; transition: all 0.3s ease; display: flex; flex-direction: column; align-items: flex-start; position: relative; overflow: hidden; }
        .card-modulo:hover { transform: translateY(-5px); box-shadow: 0 8px 25px rgba(0,0,0,0.08); }

        .icon-wrapper { width: 50px; height: 50px; border-radius: 12px; display: flex; justify-content: center; align-items: center; font-size: 22px; margin-bottom: 15px; color: #fff; }

        .card-modulo h3 { margin: 0 0 8px; font-size: 18px; color: #2c3e50; font-weight: 800; }
        .card-modulo p  { margin: 0; font-size: 13px; color: #7f8c8d; line-height: 1.5; }

        /* Cores Temáticas dos Módulos */
        .c-gestao:hover      { border-color: #8e44ad; } .c-gestao .icon-wrapper      { background: #8e44ad; box-shadow: 0 4px 10px rgba(142,68,173,0.3); }
        .c-clientes:hover    { border-color: #3498db; } .c-clientes .icon-wrapper    { background: #3498db; box-shadow: 0 4px 10px rgba(52,152,219,0.3); }
        .c-visitas:hover     { border-color: #e84393; } .c-visitas .icon-wrapper     { background: #e84393; box-shadow: 0 4px 10px rgba(232,67,147,0.3); }
        .c-servicos:hover    { border-color: #b32a2a; } .c-servicos .icon-wrapper    { background: #b32a2a; box-shadow: 0 4px 10px rgba(179,42,42,0.3); }
        .c-obras:hover       { border-color: #2ecc71; } .c-obras .icon-wrapper       { background: #2ecc71; box-shadow: 0 4px 10px rgba(46,204,113,0.3); }
        .c-relatorios:hover  { border-color: #16a085; } .c-relatorios .icon-wrapper  { background: #16a085; box-shadow: 0 4px 10px rgba(22,160,133,0.3); }
        .c-orcamentos:hover  { border-color: #f39c12; } .c-orcamentos .icon-wrapper  { background: #f39c12; box-shadow: 0 4px 10px rgba(243,156,18,0.3); }
        .c-termos:hover      { border-color: #9b59b6; } .c-termos .icon-wrapper      { background: #9b59b6; box-shadow: 0 4px 10px rgba(155,89,182,0.3); }
        .c-manuais:hover     { border-color: #4b7bec; } .c-manuais .icon-wrapper     { background: #4b7bec; box-shadow: 0 4px 10px rgba(75,123,236,0.3); }
        .c-equipa:hover      { border-color: #7f8c8d; } .c-equipa .icon-wrapper      { background: #7f8c8d; box-shadow: 0 4px 10px rgba(127,140,141,0.3); }
        .c-viaturas:hover    { border-color: #d35400; } .c-viaturas .icon-wrapper    { background: #d35400; box-shadow: 0 4px 10px rgba(211,84,0,0.3); }
        .c-equipamentos:hover{ border-color: #4a69bd; } .c-equipamentos .icon-wrapper{ background: #4a69bd; box-shadow: 0 4px 10px rgba(74,105,189,0.3); }
        .c-config:hover      { border-color: #2c3e50; } .c-config .icon-wrapper      { background: #2c3e50; box-shadow: 0 4px 10px rgba(44,62,80,0.3); }

        .alerta-erro { background: #fdeded; color: #b32a2a; padding: 15px; border-radius: 8px; margin-bottom: 20px; text-align: center; border: 1px solid #f9cccc; font-weight: 600; }

        @media (max-width: 768px) {
            .header-painel { flex-direction: column; align-items: flex-start; gap: 15px; }
            .grid-modulos { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>

    <!-- REGRA DE OURO: CABEÇALHO CENTRALIZADO -->
    <?php include 'header.php'; ?>

    <div class="container">
        <?php if (isset($_GET['erro'])): ?>
            <div class="alerta-erro"><?= htmlspecialchars($erro_login, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>

        <div class="header-painel">
            <div class="boas-vindas">
                <h1>Painel Central</h1>
                <p>Bem-vindo(a), <strong><?= htmlspecialchars($nome_completo, ENT_QUOTES, 'UTF-8') ?></strong></p>
            </div>
            <div class="data-hoje">
                <i class="fa-regular fa-calendar" style="color:#b32a2a;margin-right:5px;"></i> <?= date('d / m / Y') ?>
            </div>
        </div>

        <div class="grid-modulos">

            <?php if ($is_admin): ?>
            <a href="gestao.php" class="card-modulo c-gestao">
                <div class="icon-wrapper"><i class="fa-solid fa-chart-line"></i></div>
                <h3>Gestão Global</h3>
                <p>Indicadores financeiros, produtividade e métricas de desempenho.</p>
            </a>
            <?php endif; ?>

            <?php if ($is_admin || in_array('clientes', $meus_acessos)): ?>
            <a href="clientes.php" class="card-modulo c-clientes">
                <div class="icon-wrapper"><i class="fa-solid fa-users"></i></div>
                <h3>Clientes</h3>
                <p>Gestão de contactos, moradas e dados de faturação.</p>
            </a>
            <?php endif; ?>

            <?php if ($is_admin || in_array('visitas', $meus_acessos)): ?>
            <a href="visitas.php" class="card-modulo c-visitas">
                <div class="icon-wrapper"><i class="fa-solid fa-calendar-check"></i></div>
                <h3>Visitas</h3>
                <p>Registo de deslocações e vistoriamentos prévios.</p>
            </a>
            <?php endif; ?>

            <?php if ($is_admin || in_array('servicos', $meus_acessos)): ?>
            <a href="servicos.php" class="card-modulo c-servicos">
                <div class="icon-wrapper"><i class="fa-solid fa-screwdriver-wrench"></i></div>
                <h3>Serviços</h3>
                <p>Registo de intervenções, materiais e faturação.</p>
            </a>
            <?php endif; ?>

            <?php if ($is_admin || in_array('obras', $meus_acessos)): ?>
            <a href="obras.php" class="card-modulo c-obras">
                <div class="icon-wrapper"><i class="fa-solid fa-helmet-safety"></i></div>
                <h3>Obras</h3>
                <p>Acompanhamento e gestão de projetos adjudicados.</p>
            </a>
            <?php endif; ?>

            <?php if ($is_admin || in_array('relatorios', $meus_acessos)): ?>
            <a href="relatorios.php" class="card-modulo c-relatorios">
                <div class="icon-wrapper"><i class="fa-solid fa-clipboard-list"></i></div>
                <h3>Relatórios de Obra</h3>
                <p>Registo diário de horas, materiais e trabalhos nas obras.</p>
            </a>
            <?php endif; ?>

            <?php if ($is_admin || in_array('orcamentos', $meus_acessos)): ?>
            <a href="orcamentos.php" class="card-modulo c-orcamentos">
                <div class="icon-wrapper"><i class="fa-solid fa-file-invoice"></i></div>
                <h3>Orçamentos</h3>
                <p>Criação e consulta de propostas comerciais.</p>
            </a>
            <?php endif; ?>

            <?php if ($is_admin || in_array('termos', $meus_acessos)): ?>
            <a href="termos.php" class="card-modulo c-termos">
                <div class="icon-wrapper"><i class="fa-solid fa-file-signature"></i></div>
                <h3>Termos</h3>
                <p>Emissão de termos de responsabilidade e certificados.</p>
            </a>
            <?php endif; ?>

            <!-- Módulo aberto a todos os utilizadores, sem depender da matriz de permissões -->
            <a href="manuais.php" class="card-modulo c-manuais">
                <div class="icon-wrapper"><i class="fa-solid fa-book-open"></i></div>
                <h3>Manuais e Procedimentos</h3>
                <p>Consulta e descarregamento de manuais e procedimentos internos.</p>
            </a>

            <?php if ($is_admin || in_array('equipa', $meus_acessos)): ?>
            <a href="utilizadores.php" class="card-modulo c-equipa">
                <div class="icon-wrapper"><i class="fa-solid fa-id-card-clip"></i></div>
                <h3>Equipa</h3>
                <p>Gestão de utilizadores, acessos e funcionários.</p>
            </a>
            <?php endif; ?>

            <?php if ($is_admin || in_array('viaturas', $meus_acessos)): ?>
            <a href="viaturas.php" class="card-modulo c-viaturas">
                <div class="icon-wrapper"><i class="fa-solid fa-truck-pickup"></i></div>
                <h3>Viaturas</h3>
                <p>Registo diário de quilómetros e controlo mensal de frota.</p>
            </a>
            <?php endif; ?>

            <?php if ($is_admin || in_array('equipamentos', $meus_acessos)): ?>
            <a href="equipamentos.php" class="card-modulo c-equipamentos">
                <div class="icon-wrapper"><i class="fa-solid fa-toolbox"></i></div>
                <h3>Equipamentos</h3>
                <p>Gestão de ferramentas gerais e material atribuído à equipa.</p>
            </a>
            <?php endif; ?>

            <?php if ($is_admin || in_array('configuracoes', $meus_acessos)): ?>
            <a href="configuracoes.php" class="card-modulo c-config">
                <div class="icon-wrapper"><i class="fa-solid fa-gears"></i></div>
                <h3>Configurações</h3>
                <p>Parâmetros gerais, seguranças e taxas do sistema.</p>
            </a>
            <?php endif; ?>

        </div>
    </div>
</body>
</html>

<?php else: ?>
<!-- ==========================================
// VISTA DE LOGIN E RECUPERAÇÃO (NÃO LOGADO)
// ========================================== -->
<!DOCTYPE html>
<html lang="pt-PT">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Acesso ao sistema de gestão MAB.">
    <title>Acesso - Gestão MAB</title>
    <link rel="icon" href="favicon.ico" type="image/x-icon">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { font-family: system-ui, -apple-system, sans-serif; background-color: #f7f9fc; display: flex; justify-content: center; align-items: center; min-height: 100vh; margin: 0; }
        .container-login { background: #fff; padding: 40px 30px; border-radius: 12px; box-shadow: 0 8px 30px rgba(0,0,0,0.05); width: 100%; max-width: 380px; text-align: center; border: 1px solid #f1f2f6; }
        .logo { max-width: 140px; margin-bottom: 25px; }

        .input-group { position: relative; margin-bottom: 20px; }
        .input-group i { position: absolute; left: 15px; top: 14px; color: #95a5a6; font-size: 16px; }
        input { width: 100%; padding: 14px 14px 14px 45px; border: 1px solid #dcdde1; border-radius: 8px; box-sizing: border-box; font-size: 15px; outline: none; transition: 0.2s; background: #fdfdfd; font-family: inherit; }
        input:focus { border-color: #b32a2a; background: #fff; box-shadow: 0 0 0 3px rgba(179,42,42,0.1); }

        .btn { width: 100%; padding: 14px; background: #b32a2a; color: #fff; border: none; border-radius: 8px; font-weight: 700; font-size: 15px; cursor: pointer; transition: background 0.2s; display: flex; justify-content: center; align-items: center; gap: 8px; }
        .btn:hover { background: #902222; }

        .erro   { color: #b32a2a; margin-bottom: 20px; font-size: 14px; background: #fdeded; padding: 10px; border-radius: 6px; font-weight: 600; border: 1px solid #f9cccc; }
        .sucesso{ color: #2e7d32; margin-bottom: 20px; font-size: 14px; background: #e8f5e9; padding: 10px; border-radius: 6px; font-weight: 600; border: 1px solid #c8e6c9; }

        .link-recuperar { display: inline-block; margin-top: 20px; font-size: 13px; color: #576574; text-decoration: none; font-weight: 600; transition: color 0.2s; }
        .link-recuperar:hover { color: #b32a2a; text-decoration: underline; }

        .instrucao { font-size: 13px; color: #7f8c8d; margin-bottom: 25px; line-height: 1.5; }
    </style>
</head>
<body>
    <div class="container-login">
        <img src="mab.png" alt="MAB" class="logo">

        <?php if (isset($_GET['recuperar'])): ?>
            <h2 style="margin-top:0;color:#2c3e50;font-size:18px;margin-bottom:10px;">Redefinir Código</h2>
            <p class="instrucao">Insira os seus dados para criar um novo código de acesso com exatamente 4 números.</p>

            <?php if ($msg_recuperacao): ?>
                <div class="<?= $tipo_msg_rec === 'sucesso' ? 'sucesso' : 'erro' ?>">
                    <?= htmlspecialchars($msg_recuperacao, ENT_QUOTES, 'UTF-8') ?>
                </div>
            <?php endif; ?>

            <form method="POST" action="index.php?recuperar=1">
                <!-- 🛡️ REGRA DE OURO (SEGURANÇA): INJEÇÃO DO TOKEN -->
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">

                <div class="input-group">
                    <i class="fa-solid fa-user"></i>
                    <input type="text" name="rec_username" placeholder="Nome de Utilizador" required autocomplete="username">
                </div>
                <div class="input-group">
                    <i class="fa-solid fa-id-card"></i>
                    <input type="text" name="rec_nif" placeholder="Nº de Contribuinte (NIF)" required autocomplete="off">
                </div>
                <div class="input-group">
                    <i class="fa-solid fa-key"></i>
                    <input type="password" name="nova_pass" placeholder="Novo Código (4 números)" required pattern="\d{4}" maxlength="4" inputmode="numeric" title="O código tem de ter exatamente 4 algarismos" autocomplete="new-password">
                </div>
                <button type="submit" name="btn_recuperar" class="btn">
                    <i class="fa-solid fa-floppy-disk"></i> Guardar Código
                </button>
            </form>

            <a href="index.php" class="link-recuperar"><i class="fa-solid fa-arrow-left"></i> Voltar ao Login</a>

        <?php else: ?>
            <h2 style="margin-top:0;color:#2c3e50;font-size:20px;margin-bottom:25px;">Iniciar Sessão</h2>

            <?php if ($erro_login): ?>
                <div class="erro"><?= htmlspecialchars($erro_login, ENT_QUOTES, 'UTF-8') ?></div>
            <?php endif; ?>
            <?php if ($msg_recuperacao && $tipo_msg_rec === 'sucesso'): ?>
                <div class="sucesso"><?= htmlspecialchars($msg_recuperacao, ENT_QUOTES, 'UTF-8') ?></div>
            <?php endif; ?>

            <form method="POST" action="index.php">
                <!-- 🛡️ REGRA DE OURO (SEGURANÇA): INJEÇÃO DO TOKEN -->
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">

                <div class="input-group">
                    <i class="fa-solid fa-user"></i>
                    <input type="text" name="username" placeholder="Nome de Utilizador" required autocomplete="username">
                </div>
                <div class="input-group">
                    <i class="fa-solid fa-lock"></i>
                    <input type="password" name="password" placeholder="Código de Acesso" required autocomplete="current-password">
                </div>
                <button type="submit" name="btn_login" class="btn">
                    Entrar <i class="fa-solid fa-arrow-right-to-bracket"></i>
                </button>
            </form>

            <a href="index.php?recuperar=1" class="link-recuperar">Esqueceu-se do código?</a>
        <?php endif; ?>

    </div>
</body>
</html>
<?php endif; ?>
