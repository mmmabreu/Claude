<?php
/*
 * ==========================================================================
 * TOPBAR — GESTAO MAB  (apenas a barra de navegacao superior)
 * --------------------------------------------------------------------------
 * Inclui-se DENTRO do body de qualquer pagina com:  include 'topbar.php';
 * Nao imprime html/head/body — so o header.topbar.
 * Funciona tanto nas paginas novas (com estilo.css) como nas antigas.
 * ==========================================================================
 */

// Variáveis base (não falhar se a sessão estiver incompleta)
$perfil_user   = $_SESSION['perfil'] ?? '';
$is_admin      = in_array($perfil_user, ['admin', 'administrativo']);
$nome_completo = $_SESSION['nome'] ?? 'Utilizador';
$primeiro_nome = explode(' ', trim($nome_completo))[0];

if (!isset($meus_acessos)) {
    global $pdo;
    $stmt_perm = $pdo->prepare("SELECT modulo FROM permissoes WHERE grupo = :perfil AND pode_aceder = 1");
    $stmt_perm->execute(['perfil' => $perfil_user]);
    $meus_acessos = $stmt_perm->fetchAll(PDO::FETCH_COLUMN);
}

$current_page = basename($_SERVER['PHP_SELF']);

$mapa_modulos = [
    'clientes'     => ['clientes.php', 'cliente_form.php', 'cliente_editar.php'],
    'visitas'      => ['visitas.php', 'visita_form.php', 'visita_pdf.php'],
    'servicos'     => ['servicos.php', 'servico_form.php', 'servico_pdf.php'],
    'obras'        => ['obras.php', 'obra_form.php', 'obra_despesas.php', 'obra_despesas_pdf.php', 'planeamento.php', 'planeamento_pdf.php', 'gestao_financeira_obras.php', 'autos.php', 'auto_form.php', 'auto_imprimir.php', 'auto_apagar.php'],
    'relatorios'   => ['relatorios.php', 'relatorio_form.php', 'relatorio_pdf.php'],
    'orcamentos'   => ['orcamentos.php', 'orcamento_form.php', 'orcamento_pdf.php'],
    'termos'       => ['termos.php', 'termos_eletrico.php', 'termo_eletrico_form.php', 'termo_eletrico_pdf.php', 'termos_cctv.php', 'termo_cctv_form.php', 'termo_cctv_pdf.php', 'termos_intrusao.php', 'termo_intrusao_form.php', 'termo_intrusao_pdf.php', 'termos_scie.php', 'termo_scie_form.php', 'termo_scie_pdf.php', 'termos_analise.php', 'termo_analise_form.php', 'termo_analise_pdf.php'],
    'equipa'       => ['utilizadores.php', 'utilizador_form.php', 'funcionarios.php', 'resumo_funcionario_pdf.php'],
    'viaturas'     => ['viaturas.php'],
    'equipamentos' => ['equipamentos.php', 'equipamento_pdf.php', 'vistoria_quadros_form.php', 'vistoria_quadros_pdf.php'],
    'config'       => ['configuracoes.php', 'tabela_precos.php', 'tabela_precos_pdf.php'],
];

$menu = [
    ['index.php',         'fa-house',              'Início',              null],
    ['gestao.php',        'fa-chart-line',         'Gestão',              'admin_only'],
    ['clientes.php',      'fa-users',              'Clientes',            'clientes'],
    ['visitas.php',       'fa-calendar-check',     'Visitas',             'visitas'],
    ['servicos.php',      'fa-screwdriver-wrench', 'Serviços',            'servicos'],
    ['obras.php',         'fa-helmet-safety',      'Obras',               'obras'],
    ['relatorios.php',    'fa-clipboard-list',     'Relatórios de Obra',  'relatorios'],
    ['orcamentos.php',    'fa-file-invoice',       'Orçamentos',          'orcamentos'],
    ['autos.php',         'fa-file-invoice-dollar','Autos de Medição',    null],
    ['termos.php',        'fa-file-signature',     'Termos',              'termos'],
    ['utilizadores.php',  'fa-id-card-clip',       'Equipa',              'equipa'],
    ['viaturas.php',      'fa-truck-pickup',       'Viaturas e Frota',    'viaturas'],
    ['equipamentos.php',  'fa-toolbox',            'Equipamentos',        'equipamentos'],
    ['configuracoes.php', 'fa-gear',               'Configurações',       'configuracoes'],
];

if (!function_exists('pode_ver_item')) {
    function pode_ver_item($chave, $is_admin, $perfil_user, $meus_acessos) {
        if ($chave === null)         return true;
        if ($chave === 'admin_only') return $perfil_user === 'admin';
        return $is_admin || in_array($chave, $meus_acessos);
    }
}
if (!function_exists('item_ativo')) {
    function item_ativo($destino, $current_page, $mapa_modulos) {
        if ($destino === $current_page) return true;
        $chave = str_replace('.php', '', $destino);
        if (isset($mapa_modulos[$chave]) && in_array($current_page, $mapa_modulos[$chave])) return true;
        if ($destino === 'utilizadores.php' && in_array($current_page, $mapa_modulos['equipa'] ?? [])) return true;
        if ($destino === 'configuracoes.php' && in_array($current_page, $mapa_modulos['config'] ?? [])) return true;
        return false;
    }
}

$back_url = 'index.php';
if (in_array($current_page, ['obra_form.php', 'obra_despesas.php', 'planeamento.php', 'autos.php'])) $back_url = 'obras.php';
if (in_array($current_page, ['auto_form.php', 'auto_imprimir.php'])) $back_url = 'autos.php';
if (in_array($current_page, ['cliente_form.php', 'cliente_editar.php'])) $back_url = 'clientes.php';
?>
<header class="topbar">
    <div class="topbar-left">
        <a href="index.php"><img src="mab.png" alt="MAB" class="logo"></a>
    </div>

    <div class="topbar-center">
        <?php foreach ($menu as [$destino, $icone, $titulo, $chave]):
            if (!pode_ver_item($chave, $is_admin, $perfil_user, $meus_acessos)) continue;
            $ativo = item_ativo($destino, $current_page, $mapa_modulos) ? 'active' : '';
        ?>
            <a href="<?= $destino ?>" class="nav-icon <?= $ativo ?>" title="<?= htmlspecialchars($titulo) ?>">
                <i class="fa-solid <?= $icone ?>"></i>
            </a>
        <?php endforeach; ?>
    </div>

    <div class="topbar-right">
        <?php if ($current_page !== 'index.php'): ?>
            <a href="<?= $back_url ?>" class="btn-back" title="Voltar">
                <i class="fa-solid fa-arrow-left"></i>
                <span class="btn-back-text">Voltar</span>
            </a>
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
                <a href="index.php?logout=1" style="color: var(--erro); border-top: 1px solid #eee;">
                    <i class="fa-solid fa-right-from-bracket" style="width:20px;"></i> Sair
                </a>
            </div>
        </div>
    </div>
</header>
