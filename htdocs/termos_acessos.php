<?php
session_start();
require_once 'db.php';

if (file_exists('functions.php')) {
    require_once 'functions.php';
}
if (!function_exists('registrarLog')) { function registrarLog($pdo, $acao, $modulo) { return true; } }
if (!function_exists('gerar_csrf_token')) { function gerar_csrf_token() { return ''; } }
if (!function_exists('validar_csrf_token')) { function validar_csrf_token($t) { return true; } }

require_once 'termos_acessos_comum.php';

// REGRA DE OURO 1: Sessão
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}

// REGRA DE OURO 2: Acesso pela matriz
if (!caTemAcesso($pdo)) {
    header("Location: index.php?erro=sem_permissao");
    exit;
}

$perfil_user = $_SESSION['perfil'] ?? '';
$is_admin = in_array($perfil_user, ['admin', 'administrativo']);
$nome_completo = $_SESSION['nome'] ?? 'Utilizador';
$primeiro_nome = explode(' ', trim($nome_completo))[0];
$csrf_token = gerar_csrf_token();

try { caCriarTabelas($pdo); } catch (Exception $e) { /* continua */ }

$mensagem = '';
$tipo_mensagem = '';
if (isset($_GET['sucesso'])) {
    $mensagem = ($_GET['sucesso'] === 'apagado') ? 'Registo removido com sucesso.' : 'Registo gravado com sucesso!';
    $tipo_mensagem = 'sucesso';
}

// --- APAGAR (só admin, com password de segurança) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['btn_confirmar_apagar'])) {
    if (!validar_csrf_token($_POST['csrf_token'] ?? '')) {
        die("Tentativa bloqueada por segurança (CSRF Inválido). Volte atrás e recarregue a página.");
    }
    if (!$is_admin) {
        $mensagem = 'Erro: Sem permissão para apagar.'; $tipo_mensagem = 'erro';
    } else {
        $id_apagar = filter_input(INPUT_POST, 'id_apagar', FILTER_VALIDATE_INT);
        $pass = $_POST['pass_seguranca'] ?? '';
        $st = $pdo->prepare("SELECT valor FROM configuracoes WHERE chave = 'password_apagar' LIMIT 1");
        $st->execute();
        $pass_config = $st->fetchColumn();
        if ($pass === '' || $pass !== $pass_config) {
            $mensagem = 'Password de segurança incorreta.'; $tipo_mensagem = 'erro';
        } else {
            try {
                $pdo->beginTransaction();
                $st_ref = $pdo->prepare("SELECT referencia FROM termos_acessos WHERE id = ?");
                $st_ref->execute([$id_apagar]);
                $ref_apagar = $st_ref->fetchColumn() ?: $id_apagar;
                $pdo->prepare("DELETE FROM termos_acessos_portas WHERE id_termo = ?")->execute([$id_apagar]);
                $pdo->prepare("DELETE FROM termos_acessos WHERE id = ?")->execute([$id_apagar]);
                $pdo->commit();
                registrarLog($pdo, "Apagou Registo de Controlo de Acessos Nº $ref_apagar", "Termos Controlo de Acessos");
                header("Location: termos_acessos.php?sucesso=apagado");
                exit;
            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $mensagem = 'Erro ao apagar: ' . $e->getMessage(); $tipo_mensagem = 'erro';
            }
        }
    }
}

// --- LISTAGEM ---
$pesquisa = trim($_GET['q'] ?? '');
$registos = [];
try {
    $sql = "SELECT t.*, c.nome AS cliente_nome, o.nome AS obra_nome,
                   (SELECT COUNT(*) FROM termos_acessos_controladoras k WHERE k.id_termo = t.id) AS n_ctrl,
                   (SELECT COALESCE(SUM(k.num_portas), 0) FROM termos_acessos_controladoras k WHERE k.id_termo = t.id) AS n_portas,
                   (SELECT GROUP_CONCAT(DISTINCT TRIM(CONCAT(COALESCE(k.marca, ''), ' ', COALESCE(k.modelo, ''))) ORDER BY k.ordem SEPARATOR ' / ') FROM termos_acessos_controladoras k WHERE k.id_termo = t.id) AS modelos,
                   (SELECT GROUP_CONCAT(k.ip ORDER BY k.ordem SEPARATOR ' · ') FROM termos_acessos_controladoras k WHERE k.id_termo = t.id AND k.ip IS NOT NULL AND k.ip <> '') AS ips
            FROM termos_acessos t
            LEFT JOIN clientes c ON t.id_cliente = c.id
            LEFT JOIN obras o ON t.id_obra = o.id";
    $params = [];
    if ($pesquisa !== '') {
        $sql .= " WHERE t.referencia LIKE ? OR c.nome LIKE ? OR o.nome LIKE ? OR t.local_instalacao LIKE ?
                  OR EXISTS (SELECT 1 FROM termos_acessos_controladoras k WHERE k.id_termo = t.id AND (k.ip LIKE ? OR k.num_serie LIKE ? OR k.marca LIKE ? OR k.modelo LIKE ?))";
        $like = '%' . $pesquisa . '%';
        $params = [$like, $like, $like, $like, $like, $like, $like, $like];
    }
    $sql .= " ORDER BY t.data_instalacao DESC, t.id DESC";
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $registos = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $mensagem = 'Erro ao carregar registos: ' . $e->getMessage(); $tipo_mensagem = 'erro';
}
?>
<!DOCTYPE html>
<html lang="pt-PT">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Controlo de Acessos - Gestão MAB</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { box-sizing: border-box; }
        body { font-family: system-ui, -apple-system, sans-serif; background: #f7f9fc; margin: 0; color: #333; font-size: 13px; padding-bottom: 50px; }

        .topbar { background: rgba(255,255,255,0.94); backdrop-filter: blur(8px); -webkit-backdrop-filter: blur(8px); padding: 10px 40px; box-shadow: 0 1px 0 #e8ecf1; display: flex; justify-content: space-between; align-items: center; position: sticky; top: 0; z-index: 100; }
        .topbar-left a { display: block; }
        .topbar-left .logo { height: 35px; display: block; }
        .topbar-right { display: flex; align-items: center; gap: 12px; }
        .btn-voltar { width: 40px; height: 40px; border-radius: 50%; background: #fff; border: 1px solid #e8ecf1; color: #576574; display: inline-flex; align-items: center; justify-content: center; text-decoration: none; font-size: 16px; transition: all 0.2s ease; box-shadow: 0 2px 6px rgba(0,0,0,0.04); flex-shrink: 0; }
        .btn-voltar:hover { background: #eef2f6; color: #34495e; border-color: #d5dce4; transform: translateX(-2px); }
        .btn-voltar:active { transform: scale(0.94); }
        .user-menu { position: relative; }
        .user-btn { background: #fff; border: 1px solid #e8ecf1; border-radius: 30px; font-size: 14px; font-weight: 600; color: #2c3e50; cursor: pointer; display: flex; align-items: center; gap: 10px; padding: 5px 14px 5px 5px; transition: 0.2s; font-family: inherit; }
        .user-btn:hover { box-shadow: 0 4px 12px rgba(0,0,0,0.06); }
        .user-avatar { background: #e74c3c; color: #fff; width: 28px; height: 28px; border-radius: 50%; display: flex; justify-content: center; align-items: center; font-size: 13px; }
        .dropdown-content { display: none; position: absolute; right: 0; top: 100%; background: #fff; min-width: 180px; box-shadow: 0 12px 30px rgba(0,0,0,0.1); border-radius: 10px; border: 1px solid #e8ecf1; overflow: hidden; margin-top: 6px; z-index: 200; }
        .user-menu:hover .dropdown-content, .user-menu.aberto .dropdown-content { display: block; }
        .dropdown-content a { color: #576574; padding: 12px 20px; text-decoration: none; display: block; font-size: 14px; }
        .dropdown-content a:hover { background: #f4f6f8; color: #2c3e50; }

        .container { max-width: 1300px; margin: 30px auto; padding: 0 20px; }
        .page-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 15px; }
        .page-title { margin: 0; color: #2c3e50; font-size: 24px; font-weight: 800; display: flex; align-items: center; gap: 10px; }
        .page-title i { color: #16a085; }
        .page-sub { margin: 4px 0 0; color: #7f8c8d; font-size: 13px; }

        .btn-novo { background: #16a085; color: #fff; padding: 12px 20px; border-radius: 10px; text-decoration: none; font-weight: 700; display: inline-flex; align-items: center; gap: 8px; transition: 0.2s; }
        .btn-novo:hover { background: #138d75; }

        .barra-pesquisa { display: flex; gap: 10px; margin-bottom: 20px; }
        .barra-pesquisa input { flex: 1; padding: 11px 14px; border: 1px solid #dcdde1; border-radius: 10px; font-size: 14px; font-family: inherit; outline: none; background: #fff; }
        .barra-pesquisa input:focus { border-color: #16a085; box-shadow: 0 0 0 3px rgba(22,160,133,0.12); }
        .barra-pesquisa button, .barra-pesquisa a { padding: 0 16px; border-radius: 10px; border: 1px solid #dcdde1; background: #fff; color: #576574; cursor: pointer; font-family: inherit; font-weight: 600; text-decoration: none; display: inline-flex; align-items: center; gap: 6px; }

        .card { background: #fff; border-radius: 12px; border: 1px solid #e1e5ea; overflow: hidden; box-shadow: 0 4px 15px rgba(0,0,0,0.03); }
        table { width: 100%; border-collapse: collapse; }
        th { text-align: left; padding: 12px 15px; background: #f8f9fa; font-size: 11px; text-transform: uppercase; color: #7f8c8d; border-bottom: 1px solid #eee; }
        td { padding: 12px 15px; border-bottom: 1px solid #f1f2f6; vertical-align: middle; }
        tbody tr:hover { background: #fcfcfc; }
        .ref-badge { font-weight: 800; color: #117a65; background: #e8f8f5; padding: 4px 9px; border-radius: 6px; border: 1px solid #d1f2eb; font-size: 12px; white-space: nowrap; }
        .chip { display: inline-block; background: #f1f2f6; color: #576574; border-radius: 20px; padding: 3px 9px; font-size: 11px; font-weight: 700; }
        .mono { font-family: ui-monospace, Menlo, Consolas, monospace; font-size: 12px; color: #2c3e50; }
        .muted { color: #95a5a6; font-size: 12px; }
        .acoes { display: flex; justify-content: center; gap: 6px; }
        .btn-acao { background: #f1f2f6; border: none; cursor: pointer; font-size: 14px; color: #576574; width: 32px; height: 32px; border-radius: 6px; display: inline-flex; align-items: center; justify-content: center; text-decoration: none; transition: 0.2s; }
        .btn-acao:hover { background: #e1e5ea; color: #2c3e50; }
        .btn-imprimir { color: #3498db; background: #f0f8ff; }
        .btn-imprimir:hover { background: #d6eaf8; }
        .btn-interno { color: #8e44ad; background: #f5eef8; }
        .btn-interno:hover { background: #ebdef0; }
        .btn-del { color: #e74c3c; background: #fdeded; }
        .btn-del:hover { background: #fadbd8; }
        .text-center { text-align: center !important; }
        .vazio { text-align: center; padding: 50px 20px; color: #7f8c8d; }
        .vazio i { font-size: 40px; color: #d5dbdb; display: block; margin-bottom: 10px; }

        .alert { padding: 14px; border-radius: 10px; margin-bottom: 20px; text-align: center; font-weight: 600; }
        .alert-erro { background: #fdeded; color: #c0392b; border: 1px solid #f9cccc; }
        .alert-sucesso { background: #e8f8f5; color: #117a65; border: 1px solid #d1f2eb; }

        .modal { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.55); z-index: 1000; align-items: center; justify-content: center; padding: 20px; backdrop-filter: blur(2px); }
        .modal.show { display: flex; }
        .modal-content { background: #fff; padding: 28px; border-radius: 15px; width: 100%; max-width: 440px; text-align: center; box-shadow: 0 20px 50px rgba(0,0,0,0.2); }
        .modal-content input[type="password"] { width: 100%; padding: 12px; border: 1px solid #dcdde1; border-radius: 8px; margin: 15px 0; outline: none; font-family: inherit; }
        .modal-btns { display: flex; gap: 10px; margin-top: 5px; }
        .modal-btns button { flex: 1; padding: 12px; border: none; border-radius: 8px; font-weight: 700; cursor: pointer; font-family: inherit; }
        .btn-cancelar { background: #f1f2f6; color: #576574; }

        @media (max-width: 1024px) {
            .topbar { padding: 10px 24px; }
        }
        @media (max-width: 768px) {
            .topbar { padding: 8px 15px; }
            .topbar-left .logo { height: 30px; }
            .btn-voltar { width: 38px; height: 38px; }
            .container { margin: 20px auto; padding: 0 15px; }
            .page-title { font-size: 20px; }
            .page-header > a { width: 100%; justify-content: center; }

            table, thead, tbody, tr, td { display: block; width: 100%; }
            thead { display: none; }
            tbody tr { border-bottom: 1px solid #eef1f4; padding: 10px 16px; }
            td { border: none; padding: 5px 0; display: flex; justify-content: space-between; align-items: center; gap: 12px; text-align: right; }
            td::before { content: attr(data-label); font-size: 10px; font-weight: 700; text-transform: uppercase; color: #95a5a6; text-align: left; flex-shrink: 0; }
            td.td-acoes { justify-content: flex-end; padding-top: 10px; }
            td.td-acoes::before { display: none; }
        }
        @media (max-width: 560px) {
            .user-nome { display: none; }
            .user-btn { padding: 5px 10px 5px 5px; }
            .modal-btns { flex-direction: column-reverse; }
        }
    </style>
</head>
<body>

    <header class="topbar">
        <div class="topbar-left"><a href="index.php" title="Página inicial"><img src="mab.png" alt="MAB" class="logo"></a></div>
        <div class="topbar-right">
            <a href="termos.php" class="btn-voltar" title="Voltar" aria-label="Voltar"><i class="fa-solid fa-arrow-left"></i></a>
            <div class="user-menu">
                <button type="button" class="user-btn">
                    <div class="user-avatar"><i class="fa-solid fa-user"></i></div>
                    <span class="user-nome"><?= htmlspecialchars($primeiro_nome) ?></span>
                    <i class="fa-solid fa-caret-down" style="font-size: 12px; color: #95a5a6;"></i>
                </button>
                <div class="dropdown-content">
                    <a href="perfil.php"><i class="fa-solid fa-id-badge" style="width:20px;"></i> Perfil</a>
                    <a href="index.php?logout=1" style="color: #e74c3c; border-top: 1px solid #f1f2f6;"><i class="fa-solid fa-right-from-bracket" style="width:20px;"></i> Sair</a>
                </div>
            </div>
        </div>
    </header>

    <div class="container">
        <?php if ($mensagem): ?>
            <div class="alert <?= $tipo_mensagem === 'sucesso' ? 'alert-sucesso' : 'alert-erro' ?>"><?= htmlspecialchars($mensagem) ?></div>
        <?php endif; ?>

        <div class="page-header">
            <div>
                <h1 class="page-title"><i class="fa-solid fa-door-closed"></i> Controlo de Acessos</h1>
                <p class="page-sub">Registos de instalação de controladoras de acessos.</p>
            </div>
            <a href="termos_acessos_form.php" class="btn-novo"><i class="fa-solid fa-plus-circle"></i> Novo Registo</a>
        </div>

        <form class="barra-pesquisa" method="GET">
            <input type="text" name="q" value="<?= htmlspecialchars($pesquisa) ?>" placeholder="Pesquisar por Nº, cliente, obra, local, marca, modelo, IP ou nº de série...">
            <button type="submit"><i class="fa-solid fa-magnifying-glass"></i></button>
            <?php if ($pesquisa !== ''): ?><a href="termos_acessos.php" title="Limpar"><i class="fa-solid fa-xmark"></i></a><?php endif; ?>
        </form>

        <div class="card">
            <?php if (empty($registos)): ?>
                <div class="vazio"><i class="fa-solid fa-door-closed"></i><?= $pesquisa !== '' ? 'Nenhum registo encontrado para esta pesquisa.' : 'Ainda não há registos de instalação.' ?></div>
            <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th width="100">Nº</th>
                        <th width="110">Data</th>
                        <th>Cliente</th>
                        <th>Obra / Local</th>
                        <th>Controladoras</th>
                        <th>IP</th>
                        <th width="170" class="text-center">Ações</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($registos as $r): ?>
                    <tr>
                        <td data-label="Nº"><span class="ref-badge"><?= htmlspecialchars($r['referencia'] ?: ('#' . $r['id'])) ?></span></td>
                        <td data-label="Data"><?= !empty($r['data_instalacao']) ? date('d/m/Y', strtotime($r['data_instalacao'])) : '-' ?></td>
                        <td data-label="Cliente"><strong><?= htmlspecialchars($r['cliente_nome'] ?? '-') ?></strong></td>
                        <td data-label="Obra / Local">
                            <span><?= htmlspecialchars($r['obra_nome'] ?: ($r['local_instalacao'] ?: '-')) ?>
                            <?php if (!empty($r['obra_nome']) && !empty($r['local_instalacao'])): ?><br><span class="muted"><?= htmlspecialchars($r['local_instalacao']) ?></span><?php endif; ?></span>
                        </td>
                        <td data-label="Controladoras"><span><?= htmlspecialchars(trim((string)$r['modelos']) ?: '-') ?><br><span class="chip"><?= (int)$r['n_ctrl'] ?> ctrl. · <?= (int)$r['n_portas'] ?> porta<?= (int)$r['n_portas'] === 1 ? '' : 's' ?></span></span></td>
                        <td data-label="IP"><span class="mono"><?= htmlspecialchars($r['ips'] ?: '-') ?></span></td>
                        <td class="td-acoes">
                            <div class="acoes">
                                <a href="termos_acessos_pdf.php?id=<?= (int)$r['id'] ?>&tipo=cliente" target="_blank" class="btn-acao btn-imprimir" title="Imprimir para o cliente (sem passwords)"><i class="fa-solid fa-print"></i></a>
                                <a href="termos_acessos_pdf.php?id=<?= (int)$r['id'] ?>&tipo=interno" target="_blank" class="btn-acao btn-interno" title="Imprimir interno (com passwords)"><i class="fa-solid fa-key"></i></a>
                                <a href="termos_acessos_form.php?id=<?= (int)$r['id'] ?>" class="btn-acao" title="Editar"><i class="fa-solid fa-pen-to-square"></i></a>
                                <?php if ($is_admin): ?>
                                    <button type="button" class="btn-acao btn-del" title="Eliminar" onclick="abrirModalApagar(<?= (int)$r['id'] ?>, '<?= htmlspecialchars(addslashes($r['referencia'] ?: ('#' . $r['id'])), ENT_QUOTES) ?>')"><i class="fa-solid fa-trash-can"></i></button>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($is_admin): ?>
    <div class="modal" id="modalApagar">
        <div class="modal-content">
            <h3 style="color:#e74c3c; margin-top:0;"><i class="fa-solid fa-triangle-exclamation"></i> Eliminar Registo</h3>
            <p>Deseja apagar definitivamente o registo de controlo de acessos<br><strong id="txt_apagar" style="font-size:16px; color:#2c3e50;"></strong>?</p>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                <input type="hidden" name="id_apagar" id="id_apagar">
                <input type="password" name="pass_seguranca" required placeholder="Password de Segurança">
                <div class="modal-btns">
                    <button type="button" class="btn-cancelar" onclick="fecharModais()">Cancelar</button>
                    <button type="submit" name="btn_confirmar_apagar" style="background:#e74c3c; color:#fff;">Eliminar</button>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <script>
        function abrirModalApagar(id, ref) {
            document.getElementById('id_apagar').value = id;
            document.getElementById('txt_apagar').innerText = 'Nº ' + ref;
            document.querySelector('#modalApagar input[type="password"]').value = '';
            document.getElementById('modalApagar').classList.add('show');
        }
        function fecharModais() { document.querySelectorAll('.modal').forEach(m => m.classList.remove('show')); }
        window.addEventListener('click', e => { if (e.target.classList.contains('modal')) fecharModais(); });

        // Menu do utilizador: em ecrãs táteis abre com toque
        (function () {
            const menu = document.querySelector('.user-menu');
            const btn = menu ? menu.querySelector('.user-btn') : null;
            if (!btn) return;
            btn.addEventListener('click', function (e) { e.stopPropagation(); menu.classList.toggle('aberto'); });
            document.addEventListener('click', function () { menu.classList.remove('aberto'); });
        })();
    </script>
</body>
</html>
