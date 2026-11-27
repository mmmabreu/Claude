<?php
/**
 * fichas_tecnicas.php
 * Biblioteca de fichas técnicas e certificados de conformidade: carregar PDFs (um a um ou vários de uma vez),
 * marca / modelo / referência, substituir por versões novas (as antigas ficam guardadas) e ligar cada produto
 * aos artigos da tabela de preços. Cada produto tem ficha técnica e/ou certificado (pelo menos um dos dois).
 * O certificado tem nº e validade (opcionais): a lista avisa os que estão a expirar (60 dias) ou expirados.
 */
ini_set('display_errors', 0);

session_start();
require_once 'db.php';
require_once 'fichas_tecnicas_lib.php';

if (!isset($_SESSION['user_id'])) { header("Location: index.php"); exit; }

if (file_exists('functions.php')) { require_once 'functions.php'; }
if (!function_exists('registrarLog')) { function registrarLog($pdo, $acao, $modulo) { return true; } }
if (!function_exists('gerar_csrf_token')) { function gerar_csrf_token() { return ''; } }
if (!function_exists('validar_csrf_token')) { function validar_csrf_token($t) { return true; } }

$perfil_user = $_SESSION['perfil'] ?? '';
$is_admin = in_array($perfil_user, ['admin', 'administrativo']);
$stmt_perm = $pdo->prepare("SELECT modulo FROM permissoes WHERE grupo = :perfil AND pode_aceder = 1");
$stmt_perm->execute(['perfil' => $perfil_user]);
$meus_acessos = $stmt_perm->fetchAll(PDO::FETCH_COLUMN);
if (!$is_admin && !array_intersect(['fichas_tecnicas', 'orcamentos', 'obras', 'tabela_precos'], $meus_acessos)) {
    header("Location: index.php?erro=sem_permissao"); exit;
}

$nome_completo = $_SESSION['nome'] ?? 'Utilizador';
$primeiro_nome = explode(' ', trim($nome_completo))[0];
$csrf_token = gerar_csrf_token();

ft_migrar($pdo);
$chave_art = ft_chave_artigo($pdo);

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function voltar($msg, $tipo = 'sucesso') { header('Location: fichas_tecnicas.php?' . ($tipo === 'sucesso' ? 'ok' : 'erro') . '=' . rawurlencode($msg)); exit; }

// Liga a ficha aos artigos escolhidos (e desliga os que deixaram de estar escolhidos)
function ligar_artigos($pdo, $chave_art, $id_ficha, array $chaves, $marca, $por_marca) {
    $chaves = array_values(array_unique(array_filter(array_map('strval', $chaves), 'strlen')));
    if ($chaves) {
        $ph = implode(',', array_fill(0, count($chaves), '?'));
        $pdo->prepare("UPDATE tabela_precos SET id_ficha_tecnica = NULL WHERE id_ficha_tecnica = ? AND `$chave_art` NOT IN ($ph)")->execute(array_merge([$id_ficha], $chaves));
        $pdo->prepare("UPDATE tabela_precos SET id_ficha_tecnica = ? WHERE `$chave_art` IN ($ph)")->execute(array_merge([$id_ficha], $chaves));
        if ($por_marca && $marca !== '') {
            $pdo->prepare("UPDATE tabela_precos SET marca = ? WHERE `$chave_art` IN ($ph) AND (marca IS NULL OR marca = '')")->execute(array_merge([$marca], $chaves));
        }
    } else {
        $pdo->prepare("UPDATE tabela_precos SET id_ficha_tecnica = NULL WHERE id_ficha_tecnica = ?")->execute([$id_ficha]);
    }
}

// =====================================================================================
// POST
// =====================================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validar_csrf_token($_POST['csrf_token'] ?? '')) die("CSRF Inválido.");
    $acao = $_POST['acao'] ?? '';
    $quem = $_SESSION['nome'] ?? '';

    try {
        // --- Nova ficha / editar ficha ---
        if ($acao === 'guardar') {
            $id_f   = (int)($_POST['id_ficha'] ?? 0);
            $marca  = mb_substr(trim((string)($_POST['marca'] ?? '')), 0, 100);
            $modelo = mb_substr(trim((string)($_POST['modelo'] ?? '')), 0, 150);
            $ref    = mb_substr(trim((string)($_POST['referencia'] ?? '')), 0, 100);
            $desc   = mb_substr(trim((string)($_POST['descricao'] ?? '')), 0, 255);
            if ($marca === '' || $modelo === '') throw new Exception('Indica a marca e o modelo.');
            $tem_fich = !empty($_FILES['ficheiro']['name']);
            $tem_cert = !empty($_FILES['certificado']['name']);
            $cert_num = mb_substr(trim((string)($_POST['cert_numero'] ?? '')), 0, 100);
            $cert_val = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_POST['cert_validade'] ?? '')) ? $_POST['cert_validade'] : null;
            $artigos = json_decode((string)($_POST['artigos_json'] ?? '[]'), true);
            if (!is_array($artigos)) $artigos = [];

            if ($id_f > 0) {
                $st = $pdo->prepare("SELECT * FROM fichas_tecnicas WHERE id = ?"); $st->execute([$id_f]);
                $atual = $st->fetch(PDO::FETCH_ASSOC);
                if (!$atual) throw new Exception('Ficha não encontrada.');
                $ficheiro = $atual['ficheiro']; $nome_or = $atual['nome_original']; $tam = $atual['tamanho'];
                $versoes = json_decode((string)($atual['versoes_json'] ?? ''), true) ?: [];
                if ($tem_fich) {
                    [$ficheiro, $nome_or, $tam] = ft_guardar_upload($_FILES['ficheiro'], $marca, $modelo);
                    // Nunca perder dados: a versão anterior fica guardada (ficheiro não é apagado)
                    if ($atual['ficheiro'] !== '') $versoes[] = ['ficheiro' => $atual['ficheiro'], 'nome_original' => $atual['nome_original'], 'tamanho' => (int)$atual['tamanho'], 'ate' => date('Y-m-d H:i:s')];
                }
                // Certificado de conformidade (a versão anterior também fica guardada, com o nº e a validade que tinha)
                $c_fich = (string)$atual['cert_ficheiro']; $c_nome = (string)$atual['cert_nome_original']; $c_tam = (int)$atual['cert_tamanho'];
                $c_versoes = json_decode((string)($atual['cert_versoes_json'] ?? ''), true) ?: [];
                if ($tem_cert) {
                    [$c_fich, $c_nome, $c_tam] = ft_guardar_upload($_FILES['certificado'], $marca, $modelo, 'cert_');
                    if ($atual['cert_ficheiro'] !== '') $c_versoes[] = ['ficheiro' => $atual['cert_ficheiro'], 'nome_original' => $atual['cert_nome_original'], 'tamanho' => (int)$atual['cert_tamanho'],
                                                                         'numero' => $atual['cert_numero'], 'validade' => $atual['cert_validade'], 'ate' => date('Y-m-d H:i:s')];
                }
                if ($ficheiro === '' && $c_fich === '') throw new Exception('Carrega a ficha técnica e/ou o certificado de conformidade.');
                $pdo->prepare("UPDATE fichas_tecnicas SET marca=?, modelo=?, referencia=?, descricao=?, ficheiro=?, nome_original=?, tamanho=?, versoes_json=?,
                               cert_ficheiro=?, cert_nome_original=?, cert_tamanho=?, cert_versoes_json=?, cert_numero=?, cert_validade=?, atualizado_em=NOW() WHERE id=?")
                    ->execute([$marca, $modelo, $ref, $desc, $ficheiro, $nome_or, $tam, json_encode($versoes, JSON_UNESCAPED_UNICODE),
                               $c_fich, $c_nome, $c_tam, json_encode($c_versoes, JSON_UNESCAPED_UNICODE), $cert_num, $cert_val, $id_f]);
                ligar_artigos($pdo, $chave_art, $id_f, $artigos, $marca, !empty($_POST['marca_artigos']));
                registrarLog($pdo, "Editou produto ($marca $modelo)" . ($tem_fich ? " - nova ficha" : '') . ($tem_cert ? " - novo certificado" : ''), "Fichas Técnicas");
                voltar('«' . $marca . ' ' . $modelo . '» guardado' . (($tem_fich || $tem_cert) && ($atual['ficheiro'] !== '' || $atual['cert_ficheiro'] !== '') ? ' (as versões anteriores ficaram no histórico).' : '.'));
            } else {
                if (!$tem_fich && !$tem_cert) throw new Exception('Escolhe a ficha técnica e/ou o certificado de conformidade (PDF, JPG ou PNG).');
                [$ficheiro, $nome_or, $tam] = $tem_fich ? ft_guardar_upload($_FILES['ficheiro'], $marca, $modelo) : ['', '', 0];
                [$c_fich, $c_nome, $c_tam] = $tem_cert ? ft_guardar_upload($_FILES['certificado'], $marca, $modelo, 'cert_') : ['', '', 0];
                $pdo->prepare("INSERT INTO fichas_tecnicas (marca, modelo, referencia, descricao, ficheiro, nome_original, tamanho, versoes_json,
                                   cert_ficheiro, cert_nome_original, cert_tamanho, cert_versoes_json, cert_numero, cert_validade, ativo, carregado_em, carregado_por, atualizado_em)
                               VALUES (?,?,?,?,?,?,?,'[]',?,?,?,'[]',?,?,1,NOW(),?,NOW())")
                    ->execute([$marca, $modelo, $ref, $desc, $ficheiro, $nome_or, $tam, $c_fich, $c_nome, $c_tam, $cert_num, $cert_val, $quem]);
                $novo_id = (int)$pdo->lastInsertId();
                ligar_artigos($pdo, $chave_art, $novo_id, $artigos, $marca, !empty($_POST['marca_artigos']));
                registrarLog($pdo, "Carregou produto ($marca $modelo)" . ($tem_fich ? ' - ficha' : '') . ($tem_cert ? ' - certificado' : ''), "Fichas Técnicas");
                voltar('«' . $marca . ' ' . $modelo . '» carregado.');
            }
        }

        // --- Carregar várias fichas de uma vez (uma marca) ---
        if ($acao === 'lote') {
            $marca = mb_substr(trim((string)($_POST['marca_lote'] ?? '')), 0, 100);
            if ($marca === '') throw new Exception('Indica a marca das fichas.');
            $fx = $_FILES['ficheiros'] ?? null;
            if (!$fx || !is_array($fx['name']) || !count(array_filter($fx['name']))) throw new Exception('Escolhe os ficheiros.');
            $ok = 0; $falhas = [];
            $ins = $pdo->prepare("INSERT INTO fichas_tecnicas (marca, modelo, referencia, descricao, ficheiro, nome_original, tamanho, versoes_json, ativo, carregado_em, carregado_por, atualizado_em)
                                  VALUES (?,?,?,?,?,?,?,'[]',1,NOW(),?,NOW())");
            foreach ($fx['name'] as $i => $nm) {
                if ($nm === '') continue;
                $um = ['name' => $nm, 'type' => $fx['type'][$i], 'tmp_name' => $fx['tmp_name'][$i], 'error' => $fx['error'][$i], 'size' => $fx['size'][$i]];
                // Modelo = nome do ficheiro sem extensão (pode corrigir-se depois)
                $modelo = mb_substr(trim(preg_replace('/[_]+/', ' ', pathinfo($nm, PATHINFO_FILENAME))), 0, 150);
                try {
                    [$ficheiro, $nome_or, $tam] = ft_guardar_upload($um, $marca, $modelo);
                    $ins->execute([$marca, $modelo, '', '', $ficheiro, $nome_or, $tam, $quem]);
                    $ok++;
                } catch (Exception $e) { $falhas[] = $e->getMessage(); }
            }
            registrarLog($pdo, "Carregou $ok fichas técnicas ($marca)", "Fichas Técnicas");
            if ($falhas) voltar("$ok ficha(s) carregada(s). Falharam: " . implode(' | ', $falhas), $ok ? 'sucesso' : 'erro');
            voltar("$ok ficha(s) da marca $marca carregada(s). Confirma o modelo de cada uma e liga-as aos artigos.");
        }

        // --- Arquivar / repor (nunca apaga ficheiros) ---
        if ($acao === 'arquivar' || $acao === 'repor') {
            $id_f = (int)($_POST['id_ficha'] ?? 0);
            if ($acao === 'arquivar') {
                $st_p = $pdo->prepare("SELECT valor FROM configuracoes WHERE chave = 'password_apagar' LIMIT 1"); $st_p->execute();
                $pass_cfg = $st_p->fetchColumn();
                if (($_POST['pass_seguranca'] ?? '') === '' || !hash_equals((string)$pass_cfg, (string)$_POST['pass_seguranca'])) throw new Exception('Password de segurança incorreta.');
            }
            $pdo->prepare("UPDATE fichas_tecnicas SET ativo = ?, atualizado_em = NOW() WHERE id = ?")->execute([$acao === 'repor' ? 1 : 0, $id_f]);
            registrarLog($pdo, ($acao === 'repor' ? 'Repôs' : 'Arquivou') . " ficha técnica #$id_f", "Fichas Técnicas");
            voltar($acao === 'repor' ? 'Ficha reposta.' : 'Ficha arquivada (o ficheiro continua guardado; podes repô-la em «Arquivadas»).');
        }
    } catch (PDOException $e) {
        error_log('fichas_tecnicas.php: ' . $e->getMessage());
        voltar('Não foi possível gravar. Nada foi alterado.', 'erro');
    } catch (Exception $e) {
        voltar($e->getMessage(), 'erro');
    }
}

// =====================================================================================
// DADOS
// =====================================================================================
$fichas = $pdo->query("SELECT f.*, (SELECT COUNT(*) FROM tabela_precos t WHERE t.id_ficha_tecnica = f.id) AS n_artigos
                       FROM fichas_tecnicas f ORDER BY f.marca ASC, f.modelo ASC")->fetchAll(PDO::FETCH_ASSOC);

$artigos = [];
try {
    $artigos = $pdo->query("SELECT `$chave_art` AS k, referencia, descricao, marca, id_ficha_tecnica FROM tabela_precos ORDER BY descricao ASC")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

$marcas = [];
foreach ($fichas as $f) if ($f['marca'] !== '') $marcas[$f['marca']] = ($marcas[$f['marca']] ?? 0) + ($f['ativo'] ? 1 : 0);
foreach ($artigos as $a) if (!empty($a['marca']) && !isset($marcas[$a['marca']])) $marcas[$a['marca']] = 0;
ksort($marcas, SORT_NATURAL | SORT_FLAG_CASE);

$n_ativas = count(array_filter($fichas, fn($f) => (int)$f['ativo'] === 1));
$n_arquivadas = count($fichas) - $n_ativas;
$n_art_ligados = count(array_filter($artigos, fn($a) => !empty($a['id_ficha_tecnica'])));
$n_cert = 0; $n_cert_alerta = 0;
foreach ($fichas as $f) {
    if (!(int)$f['ativo']) continue;
    $ec = ft_estado_certificado($f);
    if ($ec !== '') $n_cert++;
    if ($ec === 'a_expirar' || $ec === 'expirado') $n_cert_alerta++;
}

$msg = $_GET['ok'] ?? ''; $erro = $_GET['erro'] ?? '';
?>
<!DOCTYPE html>
<html lang="pt-PT">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Fichas Técnicas e Certificados - MAB</title>
    <?php /* REGRA DE OURO 8: ícone MAB no separador */ ?>
    <link rel="icon" type="image/png" href="/mab.png">
    <link rel="apple-touch-icon" href="/mab.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { font-family: system-ui, sans-serif; background: #f7f9fc; margin: 0; color: #333; font-size: 13px; padding-bottom: 60px; }
        * { box-sizing: border-box; }
        .topbar { background: #fff; padding: 10px 40px; box-shadow: 0 2px 15px rgba(0,0,0,0.05); display: flex; justify-content: space-between; align-items: center; position: sticky; top: 0; z-index: 100; border-bottom: 3px solid #16a085; }
        .topbar-left .logo { height: 38px; display: block; }
        .topbar-right { display: flex; align-items: center; gap: 18px; }
        .btn-voltar { width: 40px; height: 40px; border-radius: 50%; background: #fff; border: 1px solid #e8ecf1; color: #576574; display: inline-flex; align-items: center; justify-content: center; text-decoration: none; font-size: 16px; transition: all 0.2s ease; box-shadow: 0 2px 6px rgba(0,0,0,0.04); }
        .btn-voltar:hover { background: #eef2f6; color: #34495e; transform: translateX(-2px); }
        .user-menu { position: relative; }
        .user-btn { background: none; border: none; font-size: 14px; font-weight: 600; color: #2c3e50; cursor: pointer; display: flex; align-items: center; gap: 8px; font-family: inherit; padding: 4px; }
        .user-avatar { background: #e74c3c; color: #fff; width: 28px; height: 28px; border-radius: 50%; display: flex; justify-content: center; align-items: center; font-size: 13px; border: 2px solid #fadbd8; }
        .dropdown-content { display: none; position: absolute; right: 0; top: 100%; margin-top: 8px; background: #fff; min-width: 180px; box-shadow: 0 10px 30px rgba(0,0,0,0.1); border-radius: 8px; overflow: hidden; border: 1px solid #f1f2f6; z-index: 200; }
        .user-menu.aberto .dropdown-content { display: block; }
        .dropdown-content a { color: #576574; padding: 12px 20px; text-decoration: none; display: block; font-size: 14px; }
        .dropdown-content a:hover { background: #fdf2f2; color: #b32a2a; }

        .container { max-width: 1300px; margin: 25px auto; padding: 0 20px; }
        .page-header { display: flex; justify-content: space-between; align-items: center; gap: 12px; flex-wrap: wrap; margin-bottom: 18px; }
        .page-title { margin: 0; color: #2c3e50; font-size: 22px; font-weight: 800; display: flex; align-items: center; gap: 10px; }
        .acoes-topo { display: flex; gap: 8px; flex-wrap: wrap; }
        .btn { border: none; border-radius: 9px; padding: 11px 16px; font-weight: 700; cursor: pointer; font-family: inherit; font-size: 13px; display: inline-flex; align-items: center; gap: 8px; text-decoration: none; }
        .btn-verde { background: #16a085; color: #fff; }
        .btn-verde:hover { background: #138d75; }
        .btn-sec { background: #fff; color: #34495e; border: 1px solid #dcdde1; }
        .btn-sec:hover { background: #f4f6f9; }

        .resumo { display: flex; gap: 10px; flex-wrap: wrap; margin-bottom: 14px; }
        .resumo div { background: #fff; border: 1px solid #e1e5ea; border-radius: 10px; padding: 10px 14px; }
        .resumo b { font-size: 18px; color: #2c3e50; display: block; }
        .resumo small { color: #7f8c8d; }

        .filtros { display: flex; gap: 10px; flex-wrap: wrap; align-items: center; margin-bottom: 12px; }
        .filtros input[type=search] { flex: 1; min-width: 220px; padding: 11px 12px; border: 1px solid #dcdde1; border-radius: 9px; font-size: 14px; font-family: inherit; }
        .filtros select { padding: 10px; border: 1px solid #dcdde1; border-radius: 9px; font-family: inherit; background: #fff; }
        .chk-arq { display: flex; gap: 6px; align-items: center; color: #576574; font-weight: 600; cursor: pointer; }

        .card { background: #fff; border-radius: 12px; border: 1px solid #e1e5ea; overflow: hidden; box-shadow: 0 4px 15px rgba(0,0,0,0.03); }
        table { width: 100%; border-collapse: collapse; }
        th { text-align: left; padding: 12px; background: #f8f9fa; font-size: 11px; text-transform: uppercase; color: #7f8c8d; border-bottom: 1px solid #eee; }
        td { padding: 11px 12px; border-bottom: 1px solid #f1f2f6; vertical-align: middle; }
        tbody tr:hover { background: #fcfcfc; }
        tr.arquivada td { opacity: .55; }
        .marca-tag { background: #e8f6f3; color: #117864; font-weight: 800; padding: 3px 8px; border-radius: 6px; font-size: 12px; white-space: nowrap; }
        .modelo { font-weight: 700; color: #2c3e50; }
        .sub { color: #7f8c8d; font-size: 12px; }
        .n-art { font-weight: 800; color: #2c3e50; }
        .n-art.zero { color: #e67e22; }
        .btn-acao { background: #f1f2f6; border: none; cursor: pointer; font-size: 14px; color: #576574; width: 32px; height: 32px; border-radius: 6px; display: inline-flex; align-items: center; justify-content: center; text-decoration: none; margin-right: 3px; }
        .btn-acao:hover { background: #e1e5ea; color: #2c3e50; }
        .btn-ver { color: #c0392b; background: #fdedec; }
        .btn-arq { color: #7f8c8d; }
        .vazio { text-align: center; padding: 40px; color: #95a5a6; }

        .alert { padding: 13px 15px; border-radius: 8px; margin-bottom: 16px; font-weight: 600; }
        .alert-ok { background: #eafaf1; color: #1e8449; border: 1px solid #d5f5e3; }
        .alert-erro { background: #fdeded; color: #c0392b; border: 1px solid #f9cccc; }

        /* Modais */
        .modal { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.55); z-index: 1000; align-items: flex-start; justify-content: center; padding: 30px 12px; overflow-y: auto; }
        .modal.show { display: flex; }
        .modal-box { background: #fff; border-radius: 14px; width: 100%; max-width: 720px; padding: 20px 22px; box-shadow: 0 20px 50px rgba(0,0,0,0.2); }
        .modal-box.peq { max-width: 420px; }
        .modal-top { display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #f1f2f6; padding-bottom: 10px; margin-bottom: 14px; }
        .modal-top h3 { margin: 0; font-size: 16px; color: #16a085; display: flex; gap: 8px; align-items: center; }
        .fechar { background: none; border: none; font-size: 24px; color: #95a5a6; cursor: pointer; line-height: 1; }
        .grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
        .fg { display: flex; flex-direction: column; gap: 5px; }
        .fg.full { grid-column: 1 / -1; }
        label { font-weight: 700; font-size: 11px; color: #576574; text-transform: uppercase; }
        .fg input, .fg select { padding: 10px; border: 1px solid #dcdde1; border-radius: 8px; font-size: 13px; font-family: inherit; width: 100%; }
        .fg input:focus { border-color: #16a085; outline: none; box-shadow: 0 0 0 3px rgba(22,160,133,.12); }
        .ficheiro-atual { background: #f8f9fa; border: 1px solid #e1e5ea; border-radius: 8px; padding: 9px 12px; font-size: 12px; color: #2c3e50; display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
        .ficheiro-atual a { color: #c0392b; font-weight: 700; text-decoration: none; }
        .versoes { font-size: 12px; color: #7f8c8d; margin-top: 6px; }
        .doc-link { color: #c0392b; font-weight: 700; text-decoration: none; white-space: nowrap; }
        .doc-link.cert { color: #2471a3; }
        .doc-link:hover { text-decoration: underline; }
        .falta-doc { color: #b2babb; font-style: italic; }
        .val-valido { color: #1e8449; } .val-a_expirar { color: #d35400; font-weight: 700; } .val-expirado { color: #c0392b; font-weight: 700; }
        .bloco-cert { border: 1px solid #d6eaf8; background: #f8fbfe; border-radius: 10px; padding: 12px; }
        .bloco-cert h4 { margin: 0 0 10px; color: #2471a3; font-size: 13px; }
        .versoes a { color: #576574; }

        .bloco-art { margin-top: 16px; border-top: 1px solid #f1f2f6; padding-top: 14px; }
        .bloco-art h4 { margin: 0 0 8px; font-size: 13px; color: #2c3e50; display: flex; justify-content: space-between; gap: 8px; flex-wrap: wrap; }
        .lista-art { max-height: 280px; overflow-y: auto; border: 1px solid #e1e5ea; border-radius: 8px; margin-top: 8px; }
        .art-linha { display: flex; gap: 10px; align-items: flex-start; padding: 8px 10px; border-bottom: 1px solid #f4f5f7; cursor: pointer; }
        .art-linha:hover { background: #f8fbfa; }
        .art-linha input { margin-top: 2px; }
        .art-linha .r { font-weight: 700; color: #2c3e50; white-space: nowrap; }
        .art-linha .d { color: #576574; flex: 1; }
        .art-linha .o { font-size: 11px; color: #e67e22; white-space: nowrap; }
        .art-linha.ligado { background: #eafaf1; }
        .chips { display: flex; gap: 6px; flex-wrap: wrap; margin-top: 8px; }
        .chip { background: #e8f6f3; color: #117864; border-radius: 14px; padding: 3px 10px; font-size: 11px; font-weight: 700; display: inline-flex; gap: 6px; align-items: center; }
        .chip button { background: none; border: none; color: #117864; cursor: pointer; padding: 0; font-size: 12px; }
        .modal-btns { display: flex; gap: 10px; justify-content: flex-end; margin-top: 18px; flex-wrap: wrap; }
        .ajuda { font-size: 12px; color: #7f8c8d; margin: 6px 0 0; }

        @media (max-width: 768px) {
            .topbar { padding: 10px 15px; }
            .container { margin: 16px auto; padding: 0 12px; }
            .page-title { font-size: 18px; }
            .acoes-topo, .acoes-topo .btn { width: 100%; justify-content: center; }
            .grid { grid-template-columns: 1fr; }
            #tabFichas thead { display: none; }
            #tabFichas, #tabFichas tbody, #tabFichas tr, #tabFichas td { display: block; width: 100%; }
            #tabFichas tr { border-bottom: 1px solid #e1e5ea; padding: 10px 12px; }
            #tabFichas td { border: none; padding: 3px 0; }
            #tabFichas td.acoes { padding-top: 8px; }
        }
    </style>
</head>
<body>
    <header class="topbar">
        <div class="topbar-left"><a href="index.php" title="Página inicial"><img src="mab.png" class="logo" alt="MAB"></a></div>
        <div class="topbar-right">
            <a href="index.php" class="btn-voltar" title="Voltar" aria-label="Voltar"><i class="fa-solid fa-arrow-left"></i></a>
            <div class="user-menu" id="userMenu">
                <button type="button" class="user-btn" onclick="event.stopPropagation(); document.getElementById('userMenu').classList.toggle('aberto');">
                    <div class="user-avatar"><i class="fa-solid fa-user"></i></div>
                    <?= h($primeiro_nome) ?> <i class="fa-solid fa-chevron-down" style="font-size:10px;"></i>
                </button>
                <div class="dropdown-content">
                    <a href="perfil.php"><i class="fa-solid fa-id-badge"></i> O meu perfil</a>
                    <a href="index.php?logout=1" style="color:#e74c3c;"><i class="fa-solid fa-right-from-bracket"></i> Sair</a>
                </div>
            </div>
        </div>
    </header>

    <div class="container">
        <?php if ($msg): ?><div class="alert alert-ok"><i class="fa-solid fa-check"></i> <?= h($msg) ?></div><?php endif; ?>
        <?php if ($erro): ?><div class="alert alert-erro"><i class="fa-solid fa-triangle-exclamation"></i> <?= h($erro) ?></div><?php endif; ?>

        <div class="page-header">
            <h1 class="page-title"><i class="fa-solid fa-book-open" style="color:#16a085;"></i> Fichas Técnicas e Certificados</h1>
            <div class="acoes-topo">
                <button type="button" class="btn btn-sec" onclick="abrirLote()"><i class="fa-solid fa-layer-group"></i> Carregar várias</button>
                <button type="button" class="btn btn-verde" onclick="abrirFicha(0)"><i class="fa-solid fa-plus"></i> Novo produto</button>
            </div>
        </div>

        <div class="resumo">
            <div><b><?= $n_ativas ?></b><small>fichas</small></div>
            <div><b><?= count(array_filter($marcas)) ?></b><small>marcas</small></div>
            <div><b><?= $n_art_ligados ?> / <?= count($artigos) ?></b><small>artigos ligados</small></div>
            <div><b><?= $n_cert ?></b><small>com certificado</small></div>
            <?php if ($n_cert_alerta): ?><div style="border-color:#f5b7b1;"><b style="color:#c0392b;"><?= $n_cert_alerta ?></b><small>certificado(s) a expirar / expirado(s)</small></div><?php endif; ?>
        </div>

        <div class="filtros">
            <input type="search" id="pesquisa" placeholder="Pesquisar marca, modelo, referência ou artigo…" oninput="filtrar()">
            <select id="filtroMarca" onchange="filtrar()">
                <option value="">Todas as marcas</option>
                <?php foreach ($marcas as $m => $n): if (!$n) continue; ?><option value="<?= h($m) ?>"><?= h($m) ?> (<?= $n ?>)</option><?php endforeach; ?>
            </select>
            <?php if ($n_arquivadas): ?><label class="chk-arq"><input type="checkbox" id="verArq" onchange="filtrar()"> Arquivadas (<?= $n_arquivadas ?>)</label><?php endif; ?>
        </div>

        <div class="card">
            <table id="tabFichas">
                <thead><tr><th>Marca</th><th>Modelo / Referência</th><th>Ficha técnica</th><th>Certificado de conformidade</th><th>Artigos</th><th style="text-align:right;">Ações</th></tr></thead>
                <tbody>
                <?php if (!$fichas): ?>
                    <tr><td colspan="6" class="vazio">Ainda não há fichas técnicas. Começa por <strong>Carregar várias</strong> (uma marca de cada vez) ou <strong>Nova ficha</strong>.</td></tr>
                <?php endif; ?>
                <?php foreach ($fichas as $f):
                    $txt_pesq = mb_strtolower($f['marca'] . ' ' . $f['modelo'] . ' ' . $f['referencia'] . ' ' . $f['descricao'] . ' ' . $f['nome_original'] . ' ' . $f['cert_numero'] . ' ' . $f['cert_nome_original'], 'UTF-8');
                ?>
                    <tr class="<?= $f['ativo'] ? '' : 'arquivada' ?>" data-id="<?= (int)$f['id'] ?>" data-marca="<?= h($f['marca']) ?>" data-ativo="<?= (int)$f['ativo'] ?>" data-pesq="<?= h($txt_pesq) ?>" <?= $f['ativo'] ? '' : 'style="display:none;"' ?>>
                        <td><span class="marca-tag"><?= h($f['marca']) ?></span></td>
                        <td><div class="modelo"><?= h($f['modelo']) ?></div><?php if ($f['referencia'] !== '' || $f['descricao'] !== ''): ?><div class="sub"><?= h(trim($f['referencia'] . ($f['referencia'] !== '' && $f['descricao'] !== '' ? ' · ' : '') . $f['descricao'])) ?></div><?php endif; ?></td>
                        <td class="sub" data-label="Ficha técnica"><?php if ($f['ficheiro'] !== ''): ?><a class="doc-link" href="<?= h(ft_url($f['ficheiro'])) ?>" target="_blank"><i class="fa-solid fa-file-pdf"></i> <?= h(strtoupper(pathinfo($f['ficheiro'], PATHINFO_EXTENSION))) ?></a> · <?= h(ft_tamanho_txt($f['tamanho'])) ?><?php $nv = count(json_decode((string)$f['versoes_json'], true) ?: []); if ($nv): ?> · <?= $nv ?> versão(ões) anterior(es)<?php endif; ?><?php else: ?><span class="falta-doc">sem ficha</span><?php endif; ?></td>
                        <td class="sub" data-label="Certificado"><?php $ec = ft_estado_certificado($f); if ($ec !== ''): ?><a class="doc-link cert" href="<?= h(ft_url($f['cert_ficheiro'])) ?>" target="_blank"><i class="fa-solid fa-certificate"></i> <?= $f['cert_numero'] !== '' ? h($f['cert_numero']) : 'Certificado' ?></a><?php if ($f['cert_validade']): ?> · <span class="val-<?= $ec ?>"><?= $ec === 'expirado' ? 'expirou a ' : 'válido até ' ?><?= date('d/m/Y', strtotime($f['cert_validade'])) ?></span><?php endif; ?><?php else: ?><span class="falta-doc">sem certificado</span><?php endif; ?></td>
                        <td><span class="n-art <?= (int)$f['n_artigos'] ? '' : 'zero' ?>"><?= (int)$f['n_artigos'] ? (int)$f['n_artigos'] . ((int)$f['n_artigos'] === 1 ? ' artigo' : ' artigos') : 'sem artigos' ?></span></td>
                        <td class="acoes" style="text-align:right; white-space:nowrap;">
                            <button type="button" class="btn-acao" onclick="abrirFicha(<?= (int)$f['id'] ?>)" title="Editar / ligar artigos / nova versão"><i class="fa-solid fa-pen-to-square"></i></button>
                            <?php if ($f['ativo']): ?>
                                <button type="button" class="btn-acao btn-arq" onclick="abrirArquivar(<?= (int)$f['id'] ?>, '<?= h(addslashes($f['marca'] . ' ' . $f['modelo'])) ?>')" title="Arquivar"><i class="fa-solid fa-box-archive"></i></button>
                            <?php else: ?>
                                <form method="POST" style="display:inline;"><input type="hidden" name="csrf_token" value="<?= h($csrf_token) ?>"><input type="hidden" name="acao" value="repor"><input type="hidden" name="id_ficha" value="<?= (int)$f['id'] ?>"><button type="submit" class="btn-acao" title="Repor"><i class="fa-solid fa-rotate-left"></i></button></form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                    <tr id="semResultados" style="display:none;"><td colspan="6" class="vazio">Nenhuma ficha corresponde à pesquisa.</td></tr>
                </tbody>
            </table>
        </div>
    </div>

    <!-- ============ MODAL: NOVA / EDITAR FICHA ============ -->
    <div class="modal" id="modalFicha">
        <div class="modal-box">
            <div class="modal-top"><h3 id="tituloFicha"><i class="fa-solid fa-file-circle-plus"></i> Nova ficha técnica</h3><button type="button" class="fechar" onclick="fechar('modalFicha')">&times;</button></div>
            <form method="POST" enctype="multipart/form-data" id="formFicha" onsubmit="return prepararFicha()">
                <input type="hidden" name="csrf_token" value="<?= h($csrf_token) ?>">
                <input type="hidden" name="acao" value="guardar">
                <input type="hidden" name="id_ficha" id="f_id" value="0">
                <input type="hidden" name="artigos_json" id="f_artigos" value="[]">
                <div class="grid">
                    <div class="fg"><label>Marca *</label><input type="text" name="marca" id="f_marca" list="dl_marcas" required maxlength="100" placeholder="ex.: Legrand"></div>
                    <div class="fg"><label>Modelo *</label><input type="text" name="modelo" id="f_modelo" required maxlength="150" placeholder="ex.: Niloé Step tomada Schuko"></div>
                    <div class="fg"><label>Referência do fabricante</label><input type="text" name="referencia" id="f_ref" maxlength="100" placeholder="ex.: 863 22"></div>
                    <div class="fg"><label>Descrição (opcional)</label><input type="text" name="descricao" id="f_desc" maxlength="255"></div>
                    <div class="fg full">
                        <label id="lblFicheiro">Ficha técnica (PDF, JPG ou PNG · máx. 10 MB)</label>
                        <div class="ficheiro-atual" id="fichAtual" style="display:none;"></div>
                        <input type="file" name="ficheiro" id="f_ficheiro" accept="application/pdf,.pdf,image/jpeg,image/png">
                        <div class="versoes" id="fichVersoes"></div>
                    </div>
                    <div class="fg full bloco-cert">
                        <h4><i class="fa-solid fa-certificate"></i> Certificado de conformidade</h4>
                        <div class="ficheiro-atual" id="certAtual" style="display:none;"></div>
                        <label id="lblCert" style="margin-top:6px;">Ficheiro do certificado (PDF, JPG ou PNG · máx. 10 MB)</label>
                        <input type="file" name="certificado" id="f_cert" accept="application/pdf,.pdf,image/jpeg,image/png">
                        <div class="grid" style="margin-top:8px;">
                            <div class="fg"><label>Nº do certificado</label><input type="text" name="cert_numero" id="f_cert_num" maxlength="100" placeholder="ex.: 0832-CPR-F0315"></div>
                            <div class="fg"><label>Válido até</label><input type="date" name="cert_validade" id="f_cert_val"></div>
                        </div>
                        <div class="versoes" id="certVersoes"></div>
                    </div>
                </div>
                <p class="ajuda" style="margin-top:8px;">Ficha técnica e/ou certificado: nenhum dos dois é obrigatório, mas tem de haver pelo menos um.</p>

                <div class="bloco-art">
                    <h4><span><i class="fa-solid fa-link" style="color:#16a085;"></i> Artigos da tabela de preços com esta ficha</span><span id="nLigados" class="sub"></span></h4>
                    <div class="chips" id="chipsLigados"></div>
                    <div class="fg" style="margin-top:10px;"><input type="search" id="pesqArt" placeholder="Procurar artigo pela referência ou descrição…" oninput="listarArtigos()"></div>
                    <div class="lista-art" id="listaArt"></div>
                    <label class="chk-arq" style="margin-top:10px; text-transform:none; font-size:12px;"><input type="checkbox" name="marca_artigos" value="1" checked> Pôr esta marca nos artigos ligados que ainda não têm marca</label>
                    <p class="ajuda">Um artigo só pode ter uma ficha: se já tiver outra (assinalado a laranja), passa para esta.</p>
                </div>

                <div class="modal-btns">
                    <button type="button" class="btn btn-sec" onclick="fechar('modalFicha')">Cancelar</button>
                    <button type="submit" class="btn btn-verde"><i class="fa-solid fa-floppy-disk"></i> Guardar</button>
                </div>
            </form>
        </div>
    </div>

    <!-- ============ MODAL: CARREGAR VÁRIAS ============ -->
    <div class="modal" id="modalLote">
        <div class="modal-box peq">
            <div class="modal-top"><h3><i class="fa-solid fa-layer-group"></i> Carregar várias fichas</h3><button type="button" class="fechar" onclick="fechar('modalLote')">&times;</button></div>
            <form method="POST" enctype="multipart/form-data" onsubmit="this.querySelector('button[type=submit]').disabled = true;">
                <input type="hidden" name="csrf_token" value="<?= h($csrf_token) ?>">
                <input type="hidden" name="acao" value="lote">
                <div class="fg"><label>Marca *</label><input type="text" name="marca_lote" list="dl_marcas" required maxlength="100"></div>
                <div class="fg" style="margin-top:12px;"><label>Ficheiros (PDF, JPG ou PNG)</label><input type="file" name="ficheiros[]" id="lote_fich" multiple required accept="application/pdf,.pdf,image/jpeg,image/png" onchange="verLote(this)"></div>
                <p class="ajuda" id="loteInfo">O modelo de cada ficha fica com o nome do ficheiro; depois podes corrigir e ligar aos artigos.</p>
                <div class="modal-btns">
                    <button type="button" class="btn btn-sec" onclick="fechar('modalLote')">Cancelar</button>
                    <button type="submit" class="btn btn-verde"><i class="fa-solid fa-cloud-arrow-up"></i> Carregar</button>
                </div>
            </form>
        </div>
    </div>

    <!-- ============ MODAL: ARQUIVAR ============ -->
    <div class="modal" id="modalArq">
        <div class="modal-box peq" style="text-align:center;">
            <h3 style="color:#e67e22; margin-top:0;"><i class="fa-solid fa-box-archive"></i> Arquivar ficha</h3>
            <p>Arquivar <strong id="arqNome"></strong>?<br><small style="color:#7f8c8d;">O ficheiro não é apagado e as ligações aos artigos mantêm-se. Podes repô-la depois.</small></p>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= h($csrf_token) ?>">
                <input type="hidden" name="acao" value="arquivar">
                <input type="hidden" name="id_ficha" id="arqId">
                <div class="fg"><input type="password" name="pass_seguranca" required placeholder="Password de segurança"></div>
                <div class="modal-btns" style="justify-content:center;">
                    <button type="button" class="btn btn-sec" onclick="fechar('modalArq')">Cancelar</button>
                    <button type="submit" class="btn" style="background:#e67e22; color:#fff;">Arquivar</button>
                </div>
            </form>
        </div>
    </div>

    <datalist id="dl_marcas"><?php foreach ($marcas as $m => $n): ?><option value="<?= h($m) ?>"><?php endforeach; ?></datalist>

    <script>
    const FICHAS = <?= json_encode(array_map(fn($f) => [
        'id' => (int)$f['id'], 'marca' => $f['marca'], 'modelo' => $f['modelo'], 'referencia' => $f['referencia'], 'descricao' => $f['descricao'],
        'ficheiro' => $f['ficheiro'], 'url' => ft_url($f['ficheiro']), 'nome_original' => $f['nome_original'], 'tamanho' => ft_tamanho_txt($f['tamanho']),
        'versoes' => array_map(fn($v) => ['url' => ft_url($v['ficheiro'] ?? ''), 'nome' => $v['nome_original'] ?? '', 'ate' => $v['ate'] ?? ''], json_decode((string)$f['versoes_json'], true) ?: []),
        'cert' => (string)$f['cert_ficheiro'], 'cert_url' => $f['cert_ficheiro'] !== '' ? ft_url($f['cert_ficheiro']) : '', 'cert_nome' => (string)$f['cert_nome_original'],
        'cert_tamanho' => ft_tamanho_txt($f['cert_tamanho']), 'cert_numero' => (string)$f['cert_numero'], 'cert_validade' => (string)($f['cert_validade'] ?? ''),
        'cert_versoes' => array_map(fn($v) => ['url' => ft_url($v['ficheiro'] ?? ''), 'nome' => $v['nome_original'] ?? '', 'ate' => $v['ate'] ?? '', 'numero' => $v['numero'] ?? ''], json_decode((string)($f['cert_versoes_json'] ?? ''), true) ?: []),
    ], $fichas), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS) ?>;
    const ARTIGOS = <?= json_encode(array_map(fn($a) => ['k' => (string)$a['k'], 'r' => (string)$a['referencia'], 'd' => (string)$a['descricao'], 'm' => (string)($a['marca'] ?? ''), 'f' => (int)($a['id_ficha_tecnica'] ?? 0)], $artigos), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS) ?>;
    const NOME_FICHA = {}; FICHAS.forEach(f => NOME_FICHA[f.id] = f.marca + ' ' + f.modelo);

    let fichaAtual = 0;
    let ligados = new Set();

    document.addEventListener('click', () => document.getElementById('userMenu').classList.remove('aberto'));
    function esc(s) { return String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }
    function norm(s) { return String(s ?? '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, ''); }
    function fechar(id) { document.getElementById(id).classList.remove('show'); }
    document.querySelectorAll('.modal').forEach(m => m.addEventListener('click', e => { if (e.target === m) m.classList.remove('show'); }));

    // ---------- Lista principal ----------
    function filtrar() {
        const q = norm(document.getElementById('pesquisa').value.trim());
        const marca = document.getElementById('filtroMarca').value;
        const arq = document.getElementById('verArq');
        const verArq = arq && arq.checked;
        // artigos ligados também entram na pesquisa
        const porArtigo = {};
        if (q) ARTIGOS.forEach(a => { if (a.f && norm(a.r + ' ' + a.d).includes(q)) porArtigo[a.f] = true; });
        let vis = 0;
        document.querySelectorAll('#tabFichas tbody tr[data-id]').forEach(tr => {
            const ok = (verArq ? tr.dataset.ativo === '0' : tr.dataset.ativo === '1')
                && (!marca || tr.dataset.marca === marca)
                && (!q || norm(tr.dataset.pesq).includes(q) || porArtigo[tr.dataset.id]);
            tr.style.display = ok ? '' : 'none';
            if (ok) vis++;
        });
        document.getElementById('semResultados').style.display = (vis === 0 && FICHAS.length) ? '' : 'none';
    }

    // ---------- Modal ficha ----------
    function abrirFicha(id) {
        fichaAtual = id;
        const f = FICHAS.find(x => x.id === id);
        document.getElementById('f_id').value = id;
        document.getElementById('f_marca').value = f ? f.marca : '';
        document.getElementById('f_modelo').value = f ? f.modelo : '';
        document.getElementById('f_ref').value = f ? f.referencia : '';
        document.getElementById('f_desc').value = f ? f.descricao : '';
        document.getElementById('f_ficheiro').value = '';
        document.getElementById('f_ficheiro').required = false;
        document.getElementById('f_cert').value = '';
        document.getElementById('f_cert_num').value = f ? f.cert_numero : '';
        document.getElementById('f_cert_val').value = f ? f.cert_validade : '';
        const ca = document.getElementById('certAtual');
        if (f && f.cert) {
            ca.style.display = 'flex';
            ca.innerHTML = '<i class="fa-solid fa-certificate" style="color:#2471a3;"></i> <a href="' + esc(f.cert_url) + '" target="_blank" style="color:#2471a3;">' + esc(f.cert_nome || f.cert) + '</a> <span class="sub">' + esc(f.cert_tamanho) + '</span>';
            document.getElementById('lblCert').textContent = 'Substituir por um certificado novo (opcional · o atual fica no histórico)';
        } else {
            ca.style.display = 'none';
            document.getElementById('lblCert').textContent = 'Ficheiro do certificado (PDF, JPG ou PNG · máx. 10 MB)';
        }
        document.getElementById('certVersoes').innerHTML = (f && f.cert_versoes.length)
            ? 'Certificados anteriores: ' + f.cert_versoes.map((v, i) => '<a href="' + esc(v.url) + '" target="_blank">' + (i + 1) + '. ' + esc(v.numero || v.nome || 'certificado') + '</a>' + (v.ate ? ' <span>(até ' + esc(v.ate.substring(0, 10).split('-').reverse().join('/')) + ')</span>' : '')).join(' · ')
            : '';
        document.getElementById('tituloFicha').innerHTML = f ? '<i class="fa-solid fa-pen-to-square"></i> Editar produto' : '<i class="fa-solid fa-file-circle-plus"></i> Novo produto';
        document.getElementById('lblFicheiro').textContent = (f && f.ficheiro) ? 'Ficha técnica — substituir por uma versão nova (opcional · a atual fica no histórico)' : 'Ficha técnica (PDF, JPG ou PNG · máx. 10 MB)';
        const at = document.getElementById('fichAtual');
        if (f && f.ficheiro) {
            at.style.display = 'flex';
            at.innerHTML = '<i class="fa-solid fa-file-pdf" style="color:#c0392b;"></i> <a href="' + esc(f.url) + '" target="_blank">' + esc(f.nome_original || f.ficheiro) + '</a> <span class="sub">' + esc(f.tamanho) + '</span>';
        } else { at.style.display = 'none'; }
        document.getElementById('fichVersoes').innerHTML = (f && f.versoes.length)
            ? 'Versões anteriores: ' + f.versoes.map((v, i) => '<a href="' + esc(v.url) + '" target="_blank">' + (i + 1) + '. ' + esc(v.nome || 'versão') + '</a>' + (v.ate ? ' <span>(até ' + esc(v.ate.substring(0, 10).split('-').reverse().join('/')) + ')</span>' : '')).join(' · ')
            : '';
        ligados = new Set(ARTIGOS.filter(a => id && a.f === id).map(a => a.k));
        // nova ficha: sugere artigos pela pesquisa do modelo
        document.getElementById('pesqArt').value = '';
        listarArtigos(); desenharChips();
        document.getElementById('modalFicha').classList.add('show');
        setTimeout(() => document.getElementById(f ? 'pesqArt' : 'f_marca').focus(), 80);
    }

    function listarArtigos() {
        const q = norm(document.getElementById('pesqArt').value.trim());
        const box = document.getElementById('listaArt');
        let lista;
        if (!q) lista = ARTIGOS.filter(a => ligados.has(a.k));
        else {
            const termos = q.split(/\s+/);
            lista = ARTIGOS.filter(a => { const t = norm(a.r + ' ' + a.d + ' ' + a.m); return termos.every(x => t.includes(x)); });
        }
        if (!ARTIGOS.length) { box.innerHTML = '<div class="vazio" style="padding:18px;">A tabela de preços não tem artigos.</div>'; return; }
        if (!lista.length) { box.innerHTML = '<div class="vazio" style="padding:18px;">' + (q ? 'Nenhum artigo encontrado.' : 'Procura acima os artigos que usam esta ficha.') + '</div>'; return; }
        const mais = lista.length > 60;
        box.innerHTML = lista.slice(0, 60).map(a => {
            const lig = ligados.has(a.k);
            const outra = a.f && a.f !== fichaAtual ? '<span class="o" title="Tem outra ficha">' + esc(NOME_FICHA[a.f] || 'outra ficha') + '</span>' : '';
            return '<label class="art-linha' + (lig ? ' ligado' : '') + '"><input type="checkbox" ' + (lig ? 'checked' : '') + ' data-k="' + esc(a.k) + '" onchange="marcarArtigo(this.dataset.k, this.checked)">'
                 + '<span class="r">' + esc(a.r) + '</span><span class="d">' + esc(a.d) + (a.m ? ' <span class="sub">· ' + esc(a.m) + '</span>' : '') + '</span>' + outra + '</label>';
        }).join('') + (mais ? '<div class="sub" style="padding:8px 10px;">Mostram-se os primeiros 60 — afina a pesquisa.</div>' : '');
    }
    function marcarArtigo(k, on) { if (on) ligados.add(k); else ligados.delete(k); desenharChips(); listarArtigos(); }
    function desenharChips() {
        const sel = ARTIGOS.filter(a => ligados.has(a.k));
        document.getElementById('nLigados').textContent = sel.length ? sel.length + ' ligado(s)' : 'nenhum ligado';
        document.getElementById('chipsLigados').innerHTML = sel.map(a =>
            '<span class="chip">' + esc(a.r || a.d.substring(0, 30)) + '<button type="button" title="Desligar" data-k="' + esc(a.k) + '" onclick="marcarArtigo(this.dataset.k, false)">&times;</button></span>').join('');
    }
    function prepararFicha() {
        const f = document.getElementById('f_ficheiro'), c = document.getElementById('f_cert');
        if (f.files[0] && f.files[0].size > 10 * 1024 * 1024) { alert('A ficha técnica tem mais de 10 MB.'); return false; }
        if (c.files[0] && c.files[0].size > 10 * 1024 * 1024) { alert('O certificado tem mais de 10 MB.'); return false; }
        const atual = FICHAS.find(x => x.id === fichaAtual);
        if (!f.files[0] && !c.files[0] && !(atual && (atual.ficheiro || atual.cert))) { alert('Escolhe a ficha técnica e/ou o certificado de conformidade.'); return false; }
        document.getElementById('f_artigos').value = JSON.stringify(Array.from(ligados));
        return true;
    }

    // ---------- Lote / arquivar ----------
    function abrirLote() { document.getElementById('modalLote').classList.add('show'); }
    function verLote(inp) {
        const n = inp.files.length, grandes = Array.from(inp.files).filter(f => f.size > 10 * 1024 * 1024).length;
        const tot = Array.from(inp.files).reduce((s, f) => s + f.size, 0);
        document.getElementById('loteInfo').innerHTML = n + ' ficheiro(s), ' + (tot / 1048576).toFixed(1).replace('.', ',') + ' MB no total.'
            + (grandes ? ' <strong style="color:#c0392b;">' + grandes + ' com mais de 10 MB não vão ser aceites.</strong>' : '')
            + (tot > 40 * 1048576 ? ' <strong style="color:#e67e22;">Envia em grupos mais pequenos (o servidor pode recusar um envio tão grande).</strong>' : '');
    }
    function abrirArquivar(id, nome) {
        document.getElementById('arqId').value = id;
        document.getElementById('arqNome').textContent = nome;
        document.getElementById('modalArq').classList.add('show');
    }
    </script>
</body>
</html>
