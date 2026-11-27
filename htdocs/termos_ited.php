<?php
// =====================================================================
// TERMOS ITED — lista
// Cada registo tem o Termo de responsabilidade da instalação ITED e o Relatório de ensaios dos pontos RJ45.
// Apagar pede a password de segurança e guarda antes uma cópia completa (termos_ited_arquivo).
// =====================================================================
session_start();
require_once 'db.php';
require_once 'functions.php';
require_once 'termos_ited_comum.php';

// REGRA DE OURO 1: Validação de sessão
if (!isset($_SESSION['user_id'])) { header("Location: index.php"); exit; }
// REGRA DE OURO 2: Acesso restrito pela matriz de permissões
if (!itedTemAcesso($pdo)) { header("Location: index.php?erro=sem_permissao"); exit; }
// REGRA DE OURO 3: Primeiro nome no menu
$primeiro_nome = explode(' ', trim($_SESSION['nome'] ?? 'Utilizador'))[0];
$is_admin = in_array($_SESSION['perfil'] ?? '', ['admin', 'administrativo']);

$mensagem = ''; $tipo_mensagem = '';
try { itedCriarTabelas($pdo); } catch (Exception $e) { error_log('termos_ited.php (tabelas): ' . $e->getMessage()); $mensagem = 'Não foi possível preparar as tabelas ITED.'; $tipo_mensagem = 'erro'; }

// --- APAGAR (password de segurança + cópia em arquivo) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['apagar_id'])) {
    // REGRA DE OURO 7: token CSRF
    if (!validar_csrf_token($_POST['csrf_token'] ?? '')) {
        $mensagem = 'A sessão expirou. Atualize a página e tente de novo.'; $tipo_mensagem = 'erro';
    } elseif (!$is_admin) {
        $mensagem = 'Só a administração pode apagar termos.'; $tipo_mensagem = 'erro';
    } else {
        $id_apagar = filter_input(INPUT_POST, 'apagar_id', FILTER_VALIDATE_INT);
        $st_p = $pdo->prepare("SELECT valor FROM configuracoes WHERE chave = 'password_apagar' LIMIT 1"); $st_p->execute();
        $pass = (string)$st_p->fetchColumn();
        if ($pass === '' || !hash_equals($pass, (string)($_POST['pass_seguranca'] ?? ''))) {
            $mensagem = 'Password de segurança incorreta. Nada foi apagado.'; $tipo_mensagem = 'erro';
        } else {
            try {
                $pdo->beginTransaction();
                itedArquivar($pdo, $id_apagar, 'apagado');
                $pdo->prepare("DELETE FROM termos_ited_pontos WHERE id_termo = ?")->execute([$id_apagar]);
                $pdo->prepare("DELETE FROM termos_ited WHERE id = ?")->execute([$id_apagar]);
                $pdo->commit();
                registrarLog($pdo, "Apagou termo ITED #$id_apagar (cópia em arquivo)", "Termos ITED");
                header("Location: termos_ited.php?apagado=1"); exit;
            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                error_log('termos_ited.php (apagar): ' . $e->getMessage());
                $mensagem = 'Não foi possível apagar. Nada foi alterado.'; $tipo_mensagem = 'erro';
            }
        }
    }
}
if (isset($_GET['gravado'])) { $mensagem = 'Termo ITED guardado.'; $tipo_mensagem = 'sucesso'; }
if (isset($_GET['apagado'])) { $mensagem = 'Termo apagado (ficou uma cópia no arquivo).'; $tipo_mensagem = 'sucesso'; }

$termos = [];
try {
    $termos = $pdo->query("SELECT t.*, o.nome AS obra_nome, o.referencia_obra, c.nome AS cliente_nome,
                                  (SELECT COUNT(*) FROM termos_ited_pontos p WHERE p.id_termo = t.id) AS n_pontos,
                                  (SELECT COUNT(*) FROM termos_ited_pontos p WHERE p.id_termo = t.id AND p.resultado <> 'PASSA') AS n_falha
                           FROM termos_ited t
                           LEFT JOIN obras o ON o.id = t.id_obra
                           LEFT JOIN clientes c ON c.id = COALESCE(t.id_cliente, o.id_cliente)
                           ORDER BY t.data_emissao DESC, t.id DESC")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { error_log('termos_ited.php (lista): ' . $e->getMessage()); }
$e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="pt-PT">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Termos ITED - Gestão MAB</title>
    <?php /* REGRA DE OURO 8: ícone MAB no separador */ ?>
    <link rel="icon" type="image/png" href="/mab.png">
    <link rel="apple-touch-icon" href="/mab.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { box-sizing: border-box; font-family: system-ui, -apple-system, sans-serif; }
        body { background: #f7f9fc; margin: 0; color: #2c3e50; font-size: 14px; padding-bottom: 50px; }
        .topbar { background: #fff; padding: 10px 40px; box-shadow: 0 1px 0 #e8ecf1; display: flex; justify-content: space-between; align-items: center; position: sticky; top: 0; z-index: 100; }
        .topbar .logo { height: 35px; display: block; }
        .topbar-right { display: flex; align-items: center; gap: 12px; }
        .btn-voltar { width: 40px; height: 40px; border-radius: 50%; background: #fff; border: 1px solid #e8ecf1; color: #576574; display: inline-flex; align-items: center; justify-content: center; text-decoration: none; font-size: 16px; box-shadow: 0 2px 6px rgba(0,0,0,0.04); }
        .btn-voltar:hover { background: #eaf2fb; color: #1f618d; }
        .user-menu { position: relative; }
        .user-btn { background: #fff; border: 1px solid #e8ecf1; border-radius: 30px; font-weight: 600; color: #2c3e50; cursor: pointer; display: flex; align-items: center; gap: 10px; padding: 5px 14px 5px 5px; font-family: inherit; }
        .user-avatar { background: #e74c3c; color: #fff; width: 28px; height: 28px; border-radius: 50%; display: flex; justify-content: center; align-items: center; font-size: 13px; }
        .dropdown-content { display: none; position: absolute; right: 0; top: 100%; background: #fff; min-width: 180px; box-shadow: 0 12px 30px rgba(0,0,0,0.1); border-radius: 10px; border: 1px solid #e8ecf1; overflow: hidden; margin-top: 6px; z-index: 200; }
        .user-menu:hover .dropdown-content, .user-menu.aberto .dropdown-content { display: block; }
        .dropdown-content a { color: #576574; padding: 12px 20px; text-decoration: none; display: block; }
        .container { max-width: 1250px; margin: 25px auto; padding: 0 15px; }
        .page-header { display: flex; justify-content: space-between; align-items: center; gap: 12px; flex-wrap: wrap; margin-bottom: 18px; }
        h1 { margin: 0; font-size: 22px; font-weight: 800; display: flex; align-items: center; gap: 10px; }
        h1 i { color: #1f618d; }
        .acoes-topo { display: flex; gap: 10px; flex-wrap: wrap; }
        .pesquisa { padding: 10px 14px; border: 1px solid #dcdde1; border-radius: 30px; font-size: 13px; width: 260px; font-family: inherit; }
        .btn-novo { background: #1f618d; color: #fff; padding: 10px 18px; border-radius: 30px; text-decoration: none; font-weight: 700; font-size: 13px; display: inline-flex; align-items: center; gap: 8px; }
        .btn-novo:hover { background: #1a5276; }
        .alert { padding: 13px 15px; border-radius: 8px; margin-bottom: 16px; font-weight: 600; }
        .alert-sucesso { background: #e8f5e9; color: #1e8449; border: 1px solid #c8e6c9; }
        .alert-erro { background: #fdeded; color: #c0392b; border: 1px solid #fadbd8; }
        .card { background: #fff; border: 1px solid #e1e5ea; border-radius: 12px; overflow: hidden; }
        table { width: 100%; border-collapse: collapse; }
        th { padding: 11px 12px; text-align: left; font-size: 11px; text-transform: uppercase; color: #95a5a6; background: #fafbfc; border-bottom: 1px solid #e1e5ea; }
        td { padding: 11px 12px; border-bottom: 1px solid #f2f3f5; vertical-align: middle; }
        .ref { font-size: 11px; font-weight: 700; color: #1f618d; background: #eaf2fb; padding: 2px 7px; border-radius: 5px; }
        .estado { font-size: 11px; font-weight: 800; padding: 3px 9px; border-radius: 10px; text-transform: uppercase; }
        .estado-Emitido { background: #e8f5e9; color: #1e8449; } .estado-Rascunho { background: #fef5e7; color: #d35400; }
        .pts { font-weight: 700; } .pts .f { color: #c0392b; } .pts .ok { color: #1e8449; }
        .acoes { display: flex; gap: 6px; justify-content: flex-end; }
        .btn-ic { width: 34px; height: 34px; border-radius: 8px; border: none; background: #f1f2f6; color: #576574; display: inline-flex; align-items: center; justify-content: center; text-decoration: none; cursor: pointer; font-size: 14px; }
        .btn-ic:hover { background: #e1e5ea; }
        .btn-ic.termo { color: #1f618d; background: #eaf2fb; } .btn-ic.rel { color: #8e44ad; background: #f5eef8; } .btn-ic.del { color: #c0392b; background: #fdeded; }
        .vazio { padding: 40px; text-align: center; color: #95a5a6; }
        .modal { display: none; position: fixed; inset: 0; background: rgba(0,0,0,.55); z-index: 1000; align-items: center; justify-content: center; }
        .modal.show { display: flex; }
        .modal-box { background: #fff; border-radius: 12px; padding: 22px; width: 92%; max-width: 400px; }
        .modal-box input[type=password] { width: 100%; padding: 11px; border: 1px solid #dcdde1; border-radius: 8px; margin: 12px 0; }
        .modal-box .btns { display: flex; gap: 10px; }
        .modal-box button { flex: 1; padding: 11px; border: none; border-radius: 8px; font-weight: 700; cursor: pointer; font-family: inherit; }
        @media (max-width: 768px) {
            .topbar { padding: 10px 15px; } .user-nome { display: none; }
            .pesquisa { width: 100%; } .acoes-topo { width: 100%; } .btn-novo { flex: 1; justify-content: center; }
            .card { background: transparent; border: none; }
            thead { display: none; }
            table, tbody, tr, td { display: block; width: 100%; }
            tbody tr { background: #fff; border: 1px solid #e1e5ea; border-radius: 12px; margin-bottom: 10px; padding: 8px 0; }
            td { border: none; padding: 4px 14px; }
            td[data-label]::before { content: attr(data-label) ": "; font-size: 11px; font-weight: 700; color: #95a5a6; text-transform: uppercase; }
            .acoes { justify-content: flex-start; padding-top: 6px; }
        }
    </style>
</head>
<body>
    <header class="topbar">
        <div><a href="index.php" title="Página inicial"><img src="mab.png" alt="MAB" class="logo"></a></div>
        <div class="topbar-right">
            <a href="termos.php" class="btn-voltar" title="Voltar" aria-label="Voltar"><i class="fa-solid fa-arrow-left"></i></a>
            <div class="user-menu">
                <button type="button" class="user-btn"><div class="user-avatar"><i class="fa-solid fa-user"></i></div><span class="user-nome"><?= $e($primeiro_nome) ?></span></button>
                <div class="dropdown-content">
                    <a href="perfil.php"><i class="fa-solid fa-id-badge" style="width:20px;"></i> Perfil</a>
                    <a href="index.php?logout=1" style="color:#e74c3c; border-top:1px solid #f1f2f6;"><i class="fa-solid fa-right-from-bracket" style="width:20px;"></i> Sair</a>
                </div>
            </div>
        </div>
    </header>

    <div class="container">
        <?php if ($mensagem): ?><div class="alert alert-<?= $tipo_mensagem === 'sucesso' ? 'sucesso' : 'erro' ?>"><?= $e($mensagem) ?></div><?php endif; ?>
        <div class="page-header">
            <h1><i class="fa-solid fa-network-wired"></i> Termos ITED</h1>
            <div class="acoes-topo">
                <input type="search" class="pesquisa" id="pesquisa" placeholder="Pesquisar obra, cliente, referência…" oninput="filtrar()">
                <a href="termo_ited_form.php" class="btn-novo"><i class="fa-solid fa-plus"></i> Novo termo ITED</a>
            </div>
        </div>

        <div class="card">
            <table>
                <thead><tr><th>Referência</th><th>Obra / Edifício</th><th>Cliente</th><th>Data</th><th>Pontos RJ45</th><th>Estado</th><th style="text-align:right;">Ações</th></tr></thead>
                <tbody>
                <?php if (!$termos): ?><tr><td colspan="7" class="vazio">Ainda não há termos ITED. Comece por <strong>Novo termo ITED</strong>.</td></tr><?php endif; ?>
                <?php foreach ($termos as $t):
                    $pesq = mb_strtolower($t['referencia_obra'] . ' ' . $t['obra_nome'] . ' ' . $t['edificio'] . ' ' . $t['cliente_nome'] . ' ' . $t['morada'], 'UTF-8'); ?>
                    <tr data-pesq="<?= $e($pesq) ?>">
                        <td data-label="Referência"><span class="ref"><?= $e($t['referencia_obra'] ?: '#' . $t['id']) ?></span></td>
                        <td><strong><?= $e($t['obra_nome'] ?: $t['edificio']) ?></strong><?php if ($t['obra_nome'] && $t['edificio'] && $t['edificio'] !== $t['obra_nome']): ?><div style="font-size:12px; color:#7f8c8d;"><?= $e($t['edificio']) ?></div><?php endif; ?></td>
                        <td data-label="Cliente"><?= $e($t['cliente_nome'] ?: '-') ?></td>
                        <td data-label="Data"><?= $t['data_emissao'] ? date('d/m/Y', strtotime($t['data_emissao'])) : '-' ?></td>
                        <td data-label="Pontos RJ45" class="pts"><?= (int)$t['n_pontos'] ?><?php if ((int)$t['n_pontos']): ?> · <?php if ((int)$t['n_falha']): ?><span class="f"><?= (int)$t['n_falha'] ?> falha(m)</span><?php else: ?><span class="ok">todos passam</span><?php endif; endif; ?></td>
                        <td data-label="Estado"><span class="estado estado-<?= $e($t['estado']) ?>"><?= $e($t['estado']) ?></span></td>
                        <td>
                            <div class="acoes">
                                <a class="btn-ic termo" href="termo_ited_pdf.php?id=<?= (int)$t['id'] ?>&amp;doc=termo" target="_blank" title="Termo de responsabilidade"><i class="fa-solid fa-file-signature"></i></a>
                                <a class="btn-ic rel" href="termo_ited_pdf.php?id=<?= (int)$t['id'] ?>&amp;doc=relatorio" target="_blank" title="Relatório de ensaios dos pontos RJ45"><i class="fa-solid fa-file-lines"></i></a>
                                <a class="btn-ic" href="termo_ited_form.php?id=<?= (int)$t['id'] ?>" title="Editar"><i class="fa-solid fa-pen-to-square"></i></a>
                                <?php if ($is_admin): ?><button type="button" class="btn-ic del" title="Apagar" onclick="abrirApagar(<?= (int)$t['id'] ?>)"><i class="fa-solid fa-trash-can"></i></button><?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="modal" id="modalApagar">
        <div class="modal-box">
            <h3 style="margin-top:0; color:#c0392b;"><i class="fa-solid fa-trash-can"></i> Apagar termo ITED</h3>
            <p style="color:#576574; margin:0;">O termo e os pontos RJ45 saem da lista (fica uma cópia no arquivo). Introduza a password de segurança:</p>
            <form method="POST">
                <?php /* REGRA DE OURO 7 */ ?><input type="hidden" name="csrf_token" value="<?= $e(gerar_csrf_token()) ?>">
                <input type="hidden" name="apagar_id" id="apagar_id">
                <input type="password" name="pass_seguranca" placeholder="Password de segurança" required autocomplete="off">
                <div class="btns">
                    <button type="button" style="background:#f1f2f6; color:#576574;" onclick="document.getElementById('modalApagar').classList.remove('show')">Cancelar</button>
                    <button type="submit" style="background:#c0392b; color:#fff;">Apagar</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function filtrar() {
            const q = document.getElementById('pesquisa').value.trim().toLowerCase();
            document.querySelectorAll('tbody tr[data-pesq]').forEach(tr => tr.style.display = !q || tr.dataset.pesq.includes(q) ? '' : 'none');
        }
        function abrirApagar(id) {
            document.getElementById('apagar_id').value = id;
            const m = document.getElementById('modalApagar'); m.querySelector('input[type=password]').value = '';
            m.classList.add('show'); m.querySelector('input[type=password]').focus();
        }
        (function () {
            const menu = document.querySelector('.user-menu');
            menu.querySelector('.user-btn').addEventListener('click', e => { e.stopPropagation(); menu.classList.toggle('aberto'); });
            document.addEventListener('click', () => menu.classList.remove('aberto'));
        })();
    </script>
</body>
</html>
