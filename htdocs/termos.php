<?php
session_start();
require_once 'db.php';

// REGRA DE OURO 1: Validação de sessão
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}

$perfil_user = $_SESSION['perfil'] ?? '';
$is_admin = ($perfil_user === 'admin');
// NOVO: Grupo "funcionario" tem acesso à área de Instalações Elétricas
$is_funcionario = ($perfil_user === 'funcionario');

// REGRA DE OURO 2: Buscar Permissões Dinâmicas
$stmt_perm = $pdo->prepare("SELECT modulo FROM permissoes WHERE grupo = :perfil AND pode_aceder = 1");
$stmt_perm->execute(['perfil' => $perfil_user]);
$meus_acessos = $stmt_perm->fetchAll(PDO::FETCH_COLUMN);

// O acesso à página inicial "Termos" depende da permissão geral 'termos'
// ALTERADO: os funcionários também entram (para chegarem às Instalações Elétricas)
if (!$is_admin && !$is_funcionario && !in_array('termos', $meus_acessos)) {
    header("Location: index.php?erro=sem_permissao");
    exit;
}

// Quem vê o botão "Instalações Elétricas"
$ve_termos_eletrico = $is_admin || $is_funcionario || in_array('termos_eletrico', $meus_acessos);

// REGRA DE OURO 3: 1º Nome no Avatar
$nome_completo = $_SESSION['nome'] ?? 'Utilizador';
$primeiro_nome = explode(' ', trim($nome_completo))[0];

?>
<!DOCTYPE html>
<html lang="pt-PT">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Termos de Responsabilidade - Gestão MAB</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { font-family: system-ui, -apple-system, sans-serif; box-sizing: border-box; }
        body { background-color: #f7f9fc; margin: 0; color: #333; font-size: 14px; padding-bottom: 50px; }

        /* TOPBAR - REGRAS DE OURO */
        .topbar { background-color: #fff; padding: 10px 40px; box-shadow: 0 2px 15px rgba(0,0,0,0.05); display: flex; justify-content: space-between; align-items: center; position: sticky; top: 0; z-index: 100; }
        .topbar-left .logo { height: 35px; }
        .topbar-center { display: flex; align-items: center; gap: 20px; }
        .nav-icon { color: #95a5a6; font-size: 20px; text-decoration: none; transition: 0.2s; padding: 5px; border-radius: 6px; }
        .nav-icon:hover { background: #f1f2f6; }

        .nav-icon.i-clientes.active, .nav-icon.i-clientes:hover { color: #3498db; }
        .nav-icon.i-visitas.active, .nav-icon.i-visitas:hover { color: #e84393; }
        .nav-icon.i-servicos.active, .nav-icon.i-servicos:hover { color: #b32a2a; }
        .nav-icon.i-obras.active, .nav-icon.i-obras:hover { color: #2ecc71; }
        .nav-icon.i-relatorios.active, .nav-icon.i-relatorios:hover { color: #16a085; }
        .nav-icon.i-orcamentos.active, .nav-icon.i-orcamentos:hover { color: #f39c12; }
        .nav-icon.i-termos.active, .nav-icon.i-termos:hover { color: #9b59b6; } /* ROXO */
        .nav-icon.i-equipa.active, .nav-icon.i-equipa:hover { color: #7f8c8d; }
        .nav-icon.i-viaturas.active, .nav-icon.i-viaturas:hover { color: #d35400; }
        .nav-icon.i-equipamentos.active, .nav-icon.i-equipamentos:hover { color: #4a69bd; }
        .nav-icon.i-config.active, .nav-icon.i-config:hover { color: #2c3e50; }

        .topbar-right { display: flex; align-items: center; gap: 20px; }
        .btn-back { color: #576574; text-decoration: none; font-size: 20px; transition: color 0.2s; }
        .btn-back:hover { color: #9b59b6; }

        /* MENU UTILIZADOR HORIZONTAL COM PONTE INVISÍVEL */
        .user-menu { position: relative; }
        .user-btn { background: #fdfdfd; border: 1px solid #f1f2f6; border-radius: 30px; font-size: 14px; font-weight: 600; color: #2c3e50; cursor: pointer; display: flex; align-items: center; gap: 10px; padding: 6px 15px 6px 6px; transition: 0.2s; }
        .user-btn:hover { background: #fff; box-shadow: 0 4px 10px rgba(0,0,0,0.05); }
        .user-avatar { background: #e74c3c; color: white; width: 28px; height: 28px; border-radius: 50%; display: flex; justify-content: center; align-items: center; font-size: 14px; border: 2px solid #fadbd8; }

        .dropdown-content { display: none; position: absolute; right: 0; top: 100%; background: #fff; min-width: 180px; box-shadow: 0 10px 30px rgba(0,0,0,0.1); border-radius: 8px; border: 1px solid #f1f2f6; overflow: hidden; margin-top: 5px; z-index: 200; }
        .user-menu:hover .dropdown-content { display: block; }
        .dropdown-content a { color: #576574; padding: 12px 20px; text-decoration: none; display: block; font-size: 14px; }
        .dropdown-content a:hover { background: #fdf2f2; color: #9b59b6; }

        .container { max-width: 1200px; margin: 40px auto; padding: 0 20px; }
        .page-header { margin-bottom: 40px; }
        .page-header h1 { margin: 0; font-size: 24px; color: #2c3e50; font-weight: 800; display: flex; align-items: center; gap: 10px; }
        .page-header h1 i { color: #9b59b6; }
        .page-header p { color: #7f8c8d; margin-top: 5px; font-size: 14px; }

        /* GRELHA TIPO INDEX */
        .grid-modulos { display: grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap: 20px; }
        .card-modulo { background: #fff; border-radius: 12px; padding: 35px 25px; text-align: center; text-decoration: none; color: #333; box-shadow: 0 4px 15px rgba(0,0,0,0.03); border: 1px solid #e1e5ea; transition: all 0.3s ease; }
        .card-modulo:hover { transform: translateY(-6px); border-color: #9b59b6; box-shadow: 0 8px 25px rgba(155,89,182,0.1); }

        /* ÍCONES COLORIDOS (EMOJIS) COM EFEITO ZOOM NO HOVER */
        .card-icon { font-size: 45px; margin-bottom: 20px; display: inline-block; transition: transform 0.3s ease; }
        .card-modulo:hover .card-icon { transform: scale(1.15); }

        .card-modulo h3 { margin: 0 0 10px; font-size: 18px; color: #2c3e50; }
        .card-modulo p { margin: 0; font-size: 13px; color: #7f8c8d; line-height: 1.5; }

        .alert-info { background: #fdfaf2; color: #f39c12; border: 1px solid #fcf3cf; padding: 20px; border-radius: 12px; text-align: center; font-weight: 600; }

        @media (max-width: 768px) {
            .topbar { padding: 10px 15px; }
            .topbar-center { display: none; }
            .grid-modulos { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
    <header class="topbar">
        <div class="topbar-left"><a href="index.php"><img src="mab.png" alt="MAB" class="logo"></a></div>
        <div class="topbar-center">
            <a href="index.php" class="nav-icon" title="Início"><i class="fa-solid fa-house"></i></a>
            <?php if ($is_admin || in_array('clientes', $meus_acessos)): ?><a href="clientes.php" class="nav-icon i-clientes" title="Clientes"><i class="fa-solid fa-users"></i></a><?php endif; ?>
            <?php if ($is_admin || in_array('visitas', $meus_acessos)): ?><a href="visitas.php" class="nav-icon i-visitas" title="Visitas"><i class="fa-solid fa-calendar-check"></i></a><?php endif; ?>
            <?php if ($is_admin || in_array('servicos', $meus_acessos)): ?><a href="servicos.php" class="nav-icon i-servicos" title="Serviços"><i class="fa-solid fa-screwdriver-wrench"></i></a><?php endif; ?>
            <?php if ($is_admin || in_array('obras', $meus_acessos)): ?><a href="obras.php" class="nav-icon i-obras" title="Obras"><i class="fa-solid fa-helmet-safety"></i></a><?php endif; ?>
            <?php if ($is_admin || in_array('relatorios', $meus_acessos)): ?><a href="relatorios.php" class="nav-icon i-relatorios" title="Relatórios de Obra"><i class="fa-solid fa-clipboard-list"></i></a><?php endif; ?>
            <?php if ($is_admin || in_array('orcamentos', $meus_acessos)): ?><a href="orcamentos.php" class="nav-icon i-orcamentos" title="Orçamentos"><i class="fa-solid fa-file-invoice"></i></a><?php endif; ?>
            <?php if ($is_admin || $is_funcionario || in_array('termos', $meus_acessos)): ?><a href="termos.php" class="nav-icon i-termos active" title="Termos"><i class="fa-solid fa-file-signature"></i></a><?php endif; ?>
            <?php if ($is_admin || in_array('equipa', $meus_acessos)): ?><a href="utilizadores.php" class="nav-icon i-equipa" title="Equipa"><i class="fa-solid fa-id-card-clip"></i></a><?php endif; ?>
            <?php if ($is_admin || in_array('viaturas', $meus_acessos)): ?><a href="viaturas.php" class="nav-icon i-viaturas" title="Viaturas e Frota"><i class="fa-solid fa-truck-pickup"></i></a><?php endif; ?>
            <?php if ($is_admin || in_array('equipamentos', $meus_acessos)): ?><a href="equipamentos.php" class="nav-icon i-equipamentos" title="Equipamentos"><i class="fa-solid fa-toolbox"></i></a><?php endif; ?>
            <?php if ($is_admin || in_array('configuracoes', $meus_acessos)): ?><a href="configuracoes.php" class="nav-icon i-config" title="Configurações"><i class="fa-solid fa-gear"></i></a><?php endif; ?>
        </div>
        <div class="topbar-right">
            <a href="index.php" class="btn-back" title="Voltar ao Início"><i class="fa-solid fa-arrow-left"></i></a>
            <div class="user-menu">
                <button class="user-btn">
                    <div class="user-avatar"><i class="fa-solid fa-user"></i></div>
                    <?= htmlspecialchars($primeiro_nome) ?>
                    <i class="fa-solid fa-caret-down" style="font-size: 12px; color: #95a5a6;"></i>
                </button>
                <div class="dropdown-content">
                    <a href="perfil.php"><i class="fa-solid fa-id-badge" style="width:20px;"></i> O meu perfil</a>
                    <a href="index.php?logout=1" style="color: #e74c3c; border-top: 1px solid #f1f2f6;"><i class="fa-solid fa-right-from-bracket" style="width:20px;"></i> Sair da Sessão</a>
                </div>
            </div>
        </div>
    </header>

    <div class="container">
        <div class="page-header">
            <h1><i class="fa-solid fa-file-signature"></i> Termos de Responsabilidade</h1>
            <p>Selecione a área técnica do termo que pretende gerir ou emitir.</p>
        </div>

        <div class="grid-modulos">

            <?php if ($ve_termos_eletrico): ?>
            <a href="termos_eletrico.php" class="card-modulo">
                <span class="card-icon">⚡</span>
                <h3>Instalações Elétricas</h3>
                <p>Emissão de termos para instalações de energia e infraestruturas elétricas.</p>
            </a>
            <?php endif; ?>

            <?php if ($is_admin || in_array('termos_scie', $meus_acessos)): ?>
            <a href="termos_scie.php" class="card-modulo">
                <span class="card-icon">🧯</span>
                <h3>SCIE</h3>
                <p>Segurança Contra Incêndios em Edifícios e medidas preventivas.</p>
            </a>
            <?php endif; ?>

            <?php if ($is_admin || in_array('termos_ited', $meus_acessos)): ?>
            <a href="termos_ited.php" class="card-modulo">
                <span class="card-icon">📡</span>
                <h3>ITED</h3>
                <p>Infraestruturas de Telecomunicações em Edifícios.</p>
            </a>
            <?php endif; ?>

            <?php if ($is_admin || in_array('termos_cctv', $meus_acessos)): ?>
            <a href="termos_cctv.php" class="card-modulo">
                <span class="card-icon">📹</span>
                <h3>Videovigilância (CCTV)</h3>
                <p>Termos legais e técnicos para sistemas de captação de imagem.</p>
            </a>
            <?php endif; ?>

            <?php if ($is_admin || in_array('termos_intrusao', $meus_acessos)): ?>
            <a href="termos_intrusao.php" class="card-modulo">
                <span class="card-icon">🚨</span>
                <h3>Alarme de Intrusão</h3>
                <p>Certificação de instalação de sistemas de intrusão e segurança.</p>
            </a>
            <?php endif; ?>

            <?php if ($is_admin || in_array('termos_analise', $meus_acessos)): ?>
            <a href="termos_analise.php" class="card-modulo">
                <span class="card-icon">💻</span>
                <h3>Análise Eletrónica</h3>
                <p>Relatórios de avarias e diagnósticos de equipamentos elétricos e eletrónicos.</p>
            </a>
            <?php endif; ?>

            <?php if ($is_admin || in_array('termos_pt', $meus_acessos)): ?>
            <a href="termos_pt.php" class="card-modulo">
                <span class="card-icon">🏭</span>
                <h3>Postos de Transformação</h3>
                <p>Registos de vistoria e manutenção preventiva de postos de transformação.</p>
            </a>
            <?php endif; ?>

            <?php if ($is_admin || in_array('termos_acessos', $meus_acessos)): ?>
            <a href="termos_acessos.php" class="card-modulo">
                <span class="card-icon">🚪</span>
                <h3>Controlo de Acessos</h3>
                <p>Registo de instalação de controladoras de acessos: rede, portas, testes e entrega.</p>
            </a>
            <?php endif; ?>

            <!-- NOVA OPÇÃO: DOMÓTICA / KNX -->
            <?php if ($is_admin || in_array('termos_knx', $meus_acessos)): ?>
            <a href="termos_knx.php" class="card-modulo">
                <span class="card-icon">🎛️</span>
                <h3>Domótica (KNX)</h3>
                <p>Relatórios de ensaio e certificação para sistemas de automação em edifícios.</p>
            </a>
            <?php endif; ?>

        </div>

        <?php
        // Se o utilizador não tiver permissão para NENHUMA das sub-áreas, mostrar aviso simpático
        $tem_algum_subtermo = $is_admin || $ve_termos_eletrico || in_array('termos_scie', $meus_acessos) || in_array('termos_ited', $meus_acessos) || in_array('termos_cctv', $meus_acessos) || in_array('termos_intrusao', $meus_acessos) || in_array('termos_analise', $meus_acessos) || in_array('termos_pt', $meus_acessos) || in_array('termos_knx', $meus_acessos) || in_array('termos_acessos', $meus_acessos);

        if (!$tem_algum_subtermo): ?>
            <div class="alert-info">
                <i class="fa-solid fa-lock" style="font-size:24px; margin-bottom:10px; display:block;"></i>
                Não tens permissões configuradas para gerir categorias específicas de Termos de Responsabilidade.<br>
                <small style="color:#7f8c8d; font-weight:normal;">Contacta a administração se precisares de acesso a esta área.</small>
            </div>
        <?php endif; ?>

    </div>
</body>
</html>
