<?php
// Iniciar sessão
session_start();

// VERIFICAÇÃO DE SEGURANÇA: Se o utilizador não estiver logado, é expulso para o index.php
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}

// Lógica para fazer logout
if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: index.php");
    exit;
}
?>
<!DOCTYPE html>
<html lang="pt-PT">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Painel Central - Gestão MAB</title>
    <style>
        /* --- ESTILOS GERAIS MODERNOS --- */
        body {
            font-family: system-ui, -apple-system, sans-serif;
            background-color: #f7f9fc;
            margin: 0;
            color: #333;
        }

        /* --- CABEÇALHO (TOPBAR) --- */
        .topbar {
            background-color: #fff;
            padding: 15px 40px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.05);
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: sticky;
            top: 0;
            z-index: 100;
        }

        .topbar-left .logo {
            height: 40px; 
            width: auto;
            display: block;
        }

        /* Menu de Utilizador encostado à direita */
        .topbar-right {
            display: flex;
            align-items: center;
            justify-content: flex-end;
        }

        .user-menu {
            position: relative;
            display: inline-block;
        }

        .user-btn {
            background-color: transparent;
            border: none;
            font-size: 15px;
            font-weight: 600;
            color: #2c3e50;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 5px 0;
        }

        .user-btn:hover {
            color: #b32a2a;
        }

        /* Dropdown do utilizador */
        .dropdown-content {
            display: none;
            position: absolute;
            right: 0;
            top: 100%;
            margin-top: 10px;
            background-color: #fff;
            min-width: 180px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
            border-radius: 8px;
            overflow: hidden;
            border: 1px solid #f1f2f6;
        }

        .dropdown-content a {
            color: #576574;
            padding: 12px 20px;
            text-decoration: none;
            display: block;
            font-size: 14px;
            font-weight: 500;
            transition: background-color 0.2s, color 0.2s;
        }

        .dropdown-content a:hover {
            background-color: #fdf2f2;
            color: #b32a2a;
        }

        .user-menu:hover .dropdown-content {
            display: block;
        }

        /* --- CONTEÚDO PRINCIPAL (DASHBOARD) --- */
        .container {
            max-width: 1200px;
            margin: 40px auto;
            padding: 0 20px;
        }

        .boas-vindas {
            margin-bottom: 40px;
        }

        .boas-vindas h1 {
            margin: 0;
            font-size: 28px;
            color: #2c3e50;
            font-weight: 600;
        }

        .boas-vindas p {
            margin: 5px 0 0 0;
            color: #576574;
            font-size: 16px;
        }

        /* Grelha de Módulos (Cartões) */
        .grid-modulos {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
            gap: 25px;
        }

        .card-modulo {
            background-color: #fff;
            border-radius: 12px;
            padding: 35px 25px;
            text-align: center;
            text-decoration: none;
            color: #333;
            box-shadow: 0 4px 15px rgba(0,0,0,0.04);
            border: 1px solid #e1e5ea;
            transition: transform 0.3s ease, box-shadow 0.3s ease, border-color 0.3s ease;
        }

        .card-modulo:hover {
            transform: translateY(-6px);
            box-shadow: 0 12px 30px rgba(0,0,0,0.08);
            border-color: #b32a2a;
        }

        .card-icon {
            font-size: 40px;
            margin-bottom: 20px;
            display: block;
        }

        .card-modulo h3 {
            margin: 0 0 10px 0;
            font-size: 20px;
            color: #2c3e50;
        }

        .card-modulo p {
            margin: 0;
            font-size: 14px;
            color: #576574;
            line-height: 1.5;
        }
    </style>
</head>
<body>

    <header class="topbar">
        <div class="topbar-left">
            <a href="dashboard.php">
                <img src="mab.png" alt="Logótipo Gestão MAB" class="logo">
            </a>
        </div>
        
        <div class="topbar-right">
            <div class="user-menu">
                <button class="user-btn">
                    <?= htmlspecialchars($_SESSION['nome']) ?> ▼
                </button>
                <div class="dropdown-content">
                    <a href="perfil.php">O meu perfil</a>
                    <a href="dashboard.php?logout=1" style="border-top: 1px solid #f1f2f6; color: #b32a2a;">Sair da Sessão</a>
                </div>
            </div>
        </div>
    </header>

    <div class="container">
        
        <div class="boas-vindas">
            <h1>Painel Central</h1>
            <p>Bem-vindo ao sistema de Gestão MAB. O que pretende fazer hoje?</p>
        </div>

        <div class="grid-modulos">
            
            <a href="clientes.php" class="card-modulo">
                <span class="card-icon">👥</span>
                <h3>Clientes</h3>
                <p>Gerir a carteira de clientes, adicionar novos contactos e consultar histórico.</p>
            </a>

            <a href="orcamentos.php" class="card-modulo">
                <span class="card-icon">📄</span>
                <h3>Orçamentos</h3>
                <p>Criar novos orçamentos, verificar pendentes e aprovar adjudicações.</p>
            </a>

            <a href="obras.php" class="card-modulo">
                <span class="card-icon">🏗️</span>
                <h3>Obras</h3>
                <p>Acompanhar obras em curso, ver planeamento e fechar projetos concluídos.</p>
            </a>

            <?php if ($_SESSION['perfil'] === 'admin'): ?>
            <a href="utilizadores.php" class="card-modulo">
                <span class="card-icon">👷</span>
                <h3>Equipa</h3>
                <p>Gestão de utilizadores, funcionários, acessos e valores à hora.</p>
            </a>

            <a href="configuracoes.php" class="card-modulo">
                <span class="card-icon">⚙️</span>
                <h3>Configurações</h3>
                <p>Dados da empresa, taxas, categorias e parametrizações gerais do sistema.</p>
            </a>
            <?php endif; ?>

        </div>
        
    </div>

</body>
</html>