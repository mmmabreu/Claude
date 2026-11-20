<?php
// Garantir que as variáveis base existem para o cabeçalho não falhar
$perfil_user = $_SESSION['perfil'] ?? '';
$is_admin = in_array($perfil_user, ['admin', 'administrativo']);
$nome_completo = $_SESSION['nome'] ?? 'Utilizador';
$primeiro_nome = explode(' ', trim($nome_completo))[0];

// Se o ficheiro principal não carregou os acessos, o cabeçalho carrega
if (!isset($meus_acessos)) {
    global $pdo;
    $stmt_perm = $pdo->prepare("SELECT modulo FROM permissoes WHERE grupo = :perfil AND pode_aceder = 1");
    $stmt_perm->execute(['perfil' => $perfil_user]);
    $meus_acessos = $stmt_perm->fetchAll(PDO::FETCH_COLUMN);
}

// Descobrir em que página estamos para ativar o ícone correto e gerir a seta de voltar
$current_page = basename($_SERVER['PHP_SELF']);
?>

<style>
    /* CSS EXCLUSIVO DO CABEÇALHO CENTRALIZADO */
    .topbar { background: #fff; padding: 15px 30px; box-shadow: 0 4px 20px rgba(0,0,0,0.05); display: flex; justify-content: space-between; align-items: center; position: sticky; top: 0; z-index: 100; border-bottom: 3px solid #34495e;}
    .topbar-left .logo { height: 35px; }
    
    /* Adaptado para suportar múltiplos ícones sem quebrar o layout */
    .topbar-center { display: flex; align-items: center; gap: 12px; flex: 1; justify-content: center; flex-wrap: wrap;}
    .nav-icon { color: #bdc3c7; font-size: 18px; transition: color 0.2s; text-decoration: none; padding: 5px; border-radius: 6px;}
    .nav-icon:hover { background: #f1f2f6; color: #34495e; }
    .nav-icon.active { color: #b32a2a; }
    
    .topbar-right { display: flex; align-items: center; gap: 20px; }
    .btn-back { color: #576574; text-decoration: none; font-weight: 500; display: flex; align-items: center; gap: 6px; font-size: 15px; transition: 0.2s;}
    .btn-back:hover { color: #34495e; } 
    
    .user-menu { position: relative; display: inline-block;}
    .user-btn { background: none; border: none; font-size: 14px; font-weight: 600; color: #2c3e50; cursor: pointer; display: flex; align-items: center; gap: 8px; }
    .user-avatar { background: #e74c3c; color: white; width: 28px; height: 28px; border-radius: 50%; display: flex; justify-content: center; align-items: center; font-size: 14px; border: 2px solid #fadbd8; }
    
    .dropdown-content { display: none; position: absolute; right: 0; top: 100%; margin-top: 10px; background: #fff; min-width: 180px; box-shadow: 0 10px 30px rgba(0,0,0,0.1); border-radius: 8px; overflow: hidden; border: 1px solid #f1f2f6; z-index:200;}
    .dropdown-content a { color: #576574; padding: 12px 20px; text-decoration: none; display: block; font-size: 14px; }
    .dropdown-content a:hover { background: #fdf2f2; color: #b32a2a; }
    .user-menu:hover .dropdown-content { display: block; }

    @media (max-width: 1100px) {
        .topbar-center { gap: 8px; }
        .nav-icon { font-size: 16px; }
    }

    @media (max-width: 768px) {
        .topbar { padding: 15px 20px; }
        .topbar-center { display: none; }
        .btn-back-text { display: none; }
    }
</style>

<header class="topbar">
    <div class="topbar-left"><a href="index.php"><img src="mab.png" alt="MAB" class="logo"></a></div>
    
    <div class="topbar-center">
        <!-- 1. Início -->
        <a href="index.php" class="nav-icon <?= $current_page == 'index.php' ? 'active' : '' ?>" title="Início"><i class="fa-solid fa-house"></i></a>
        
        <!-- 2. Gestão (Admin Only) -->
        <?php if ($perfil_user === 'admin'): ?><a href="gestao.php" class="nav-icon <?= $current_page == 'gestao.php' ? 'active' : '' ?>" title="Gestão"><i class="fa-solid fa-chart-line"></i></a><?php endif; ?>
        
        <!-- 3. Clientes -->
        <?php if ($is_admin || in_array('clientes', $meus_acessos)): ?><a href="clientes.php" class="nav-icon <?= $current_page == 'clientes.php' ? 'active' : '' ?>" title="Clientes"><i class="fa-solid fa-users"></i></a><?php endif; ?>
        
        <!-- 4. Visitas -->
        <?php if ($is_admin || in_array('visitas', $meus_acessos)): ?><a href="visitas.php" class="nav-icon <?= $current_page == 'visitas.php' ? 'active' : '' ?>" title="Visitas"><i class="fa-solid fa-calendar-check"></i></a><?php endif; ?>
        
        <!-- 5. Serviços -->
        <?php if ($is_admin || in_array('servicos', $meus_acessos)): ?><a href="servicos.php" class="nav-icon <?= $current_page == 'servicos.php' ? 'active' : '' ?>" title="Serviços"><i class="fa-solid fa-screwdriver-wrench"></i></a><?php endif; ?>
        
        <!-- 6. Obras -->
        <?php if ($is_admin || in_array('obras', $meus_acessos)): ?><a href="obras.php" class="nav-icon <?= in_array($current_page, ['obras.php', 'obra_form.php', 'obra_despesas.php', 'planeamento.php']) ? 'active' : '' ?>" title="Obras"><i class="fa-solid fa-helmet-safety"></i></a><?php endif; ?>
        
        <!-- 7. Relatórios de Obra -->
        <?php if ($is_admin || in_array('relatorios', $meus_acessos)): ?><a href="relatorios.php" class="nav-icon <?= $current_page == 'relatorios.php' ? 'active' : '' ?>" title="Relatórios de Obra"><i class="fa-solid fa-clipboard-list"></i></a><?php endif; ?>
        
        <!-- 8. Orçamentos -->
        <?php if ($is_admin || in_array('orcamentos', $meus_acessos)): ?><a href="orcamentos.php" class="nav-icon <?= $current_page == 'orcamentos.php' ? 'active' : '' ?>" title="Orçamentos"><i class="fa-solid fa-file-invoice"></i></a><?php endif; ?>
        
        <!-- 9. Autos de Medição -->
        <a href="autos.php" class="nav-icon <?= in_array($current_page, ['autos.php', 'auto_form.php', 'auto_imprimir.php']) ? 'active' : '' ?>" title="Autos de Medição"><i class="fa-solid fa-file-invoice-dollar"></i></a>
        
        <!-- 10. Termos -->
        <?php if ($is_admin || in_array('termos', $meus_acessos)): ?><a href="termos.php" class="nav-icon <?= $current_page == 'termos.php' ? 'active' : '' ?>" title="Termos"><i class="fa-solid fa-file-signature"></i></a><?php endif; ?>
        
        <!-- 11. Equipa -->
        <?php if ($is_admin || in_array('equipa', $meus_acessos)): ?><a href="utilizadores.php" class="nav-icon <?= in_array($current_page, ['utilizadores.php', 'funcionarios.php']) ? 'active' : '' ?>" title="Equipa"><i class="fa-solid fa-id-card-clip"></i></a><?php endif; ?>
        
        <!-- 12. Viaturas -->
        <?php if ($is_admin || in_array('viaturas', $meus_acessos)): ?><a href="viaturas.php" class="nav-icon <?= $current_page == 'viaturas.php' ? 'active' : '' ?>" title="Viaturas e Frota"><i class="fa-solid fa-truck-pickup"></i></a><?php endif; ?>
        
        <!-- 13. Equipamentos -->
        <?php if ($is_admin || in_array('equipamentos', $meus_acessos)): ?><a href="equipamentos.php" class="nav-icon <?= $current_page == 'equipamentos.php' ? 'active' : '' ?>" title="Equipamentos"><i class="fa-solid fa-toolbox"></i></a><?php endif; ?>
        
        <!-- 14. Configurações -->
        <?php if ($is_admin || in_array('configuracoes', $meus_acessos)): ?><a href="configuracoes.php" class="nav-icon <?= $current_page == 'configuracoes.php' ? 'active' : '' ?>" title="Configurações"><i class="fa-solid fa-gear"></i></a><?php endif; ?>
    </div>
    
    <div class="topbar-right">
        <?php 
            // Lógica inteligente para a seta de voltar
            $back_url = "index.php";
            if (in_array($current_page, ['obra_form.php', 'obra_despesas.php', 'planeamento.php', 'autos.php'])) $back_url = "obras.php";
            if (in_array($current_page, ['auto_form.php', 'auto_imprimir.php'])) $back_url = "autos.php";
            // Adiciona mais lógicas de retorno conforme as páginas que fores precisando
        ?>
        
        <?php if($current_page !== 'index.php'): ?>
            <a href="<?= $back_url ?>" class="btn-back" title="Voltar"><i class="fa-solid fa-arrow-left"></i> <span class="btn-back-text">Voltar</span></a>
        <?php endif; ?>
        
        <div class="user-menu">
            <button class="user-btn">
                <div class="user-avatar"><i class="fa-solid fa-user"></i></div>
                <?= htmlspecialchars($primeiro_nome, ENT_QUOTES, 'UTF-8') ?> 
                <i class="fa-solid fa-chevron-down" style="font-size: 10px;"></i>
            </button>
            <div class="dropdown-content">
                <a href="perfil.php"><i class="fa-solid fa-id-badge" style="width:20px;"></i> O meu perfil</a>
                <?php if ($is_admin || in_array('configuracoes', $meus_acessos)): ?>
                    <a href="configuracoes.php"><i class="fa-solid fa-gear" style="width:20px;"></i> Configurações</a>
                <?php endif; ?>
                <a href="index.php?logout=1" style="color: #e74c3c; border-top: 1px solid #eee;"><i class="fa-solid fa-right-from-bracket" style="width:20px;"></i> Sair</a>
            </div>
        </div>
    </div>
</header>