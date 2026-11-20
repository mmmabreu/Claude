<?php
session_start();
require_once 'db.php';
// REGRA DE OURO 1: Validação de sessão
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}
if (file_exists('functions.php')) {
    require_once 'functions.php';
} else {
    function registrarLog($pdo, $acao, $modulo) { return true; }
}
$perfil_user = $_SESSION['perfil'] ?? '';
$is_admin = in_array($perfil_user, ['admin', 'administrativo']);
// Este módulo é intencionalmente acessível a QUALQUER utilizador autenticado, independentemente
// da matriz de permissões (tabela `permissoes`) — só a sessão iniciada é exigida.
// - Adicionar manuais: TODOS os grupos de utilizadores ($pode_adicionar_manuais).
// - Editar / apagar manuais: só o perfil "admin" ($pode_gerir_manuais) — "administrativo" NÃO tem essa capacidade.
$pode_adicionar_manuais = true;
$pode_gerir_manuais = ($perfil_user === 'admin');
// Perfis que podem ser escolhidos para ver cada manual (usado no formulário de Adicionar/Editar
// e para filtrar o que cada utilizador vê em "Documentos Disponíveis").
$perfis_visibilidade = [
    'admin' => 'Admin',
    'administrativo' => 'Administrativo',
    'funcionario' => 'Funcionário',
    'externo' => 'Externo',
];
$nome_completo = $_SESSION['nome'] ?? 'Utilizador';
$primeiro_nome = explode(' ', trim($nome_completo))[0];
$id_utilizador_logado = $_SESSION['user_id'];
$mensagem = '';
$tipo_mensagem = '';
// --- PASTA DE ARMAZENAMENTO DOS FICHEIROS ---
// Fica fora do alcance de acesso direto (o .htaccess bloqueia pedidos diretos a esta pasta);
// todos os descarregamentos passam sempre por manual_download.php, que exige sessão iniciada.
$pasta_manuais = __DIR__ . '/manuais_ficheiros/';
if (!is_dir($pasta_manuais)) {
    @mkdir($pasta_manuais, 0755, true);
}
$htaccess_path = $pasta_manuais . '.htaccess';
if (is_dir($pasta_manuais) && !file_exists($htaccess_path)) {
    @file_put_contents($htaccess_path, "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n    Order allow,deny\n    Deny from all\n</IfModule>\n");
}
$extensoes_permitidas = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'jpg', 'jpeg', 'png'];
$tamanho_maximo_bytes = 25 * 1024 * 1024; // 25 MB (sujeito também aos limites do alojamento)
// --- VÁRIOS FICHEIROS POR MANUAL (out/2026) ---
// Tabela manuais_ficheiros: um registo por ficheiro. Os manuais antigos (um ficheiro nas colunas da tabela manuais)
// passam sozinhos para esta tabela, sem mexer no ficheiro guardado.
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS manuais_ficheiros (
        id INT AUTO_INCREMENT PRIMARY KEY,
        id_manual INT NOT NULL,
        nome_original VARCHAR(255) NOT NULL,
        nome_servidor VARCHAR(191) NOT NULL,
        tamanho_bytes INT NOT NULL DEFAULT 0,
        id_utilizador_upload INT NULL,
        data_upload DATETIME NULL,
        INDEX (id_manual)
    ) DEFAULT CHARSET=utf8mb4");
    $antigos = $pdo->query("SELECT id, nome_ficheiro_original, nome_ficheiro_servidor, tamanho_bytes, id_utilizador_upload, data_upload FROM manuais
                            WHERE nome_ficheiro_servidor IS NOT NULL AND nome_ficheiro_servidor <> ''")->fetchAll(PDO::FETCH_ASSOC);
    if ($antigos) {
        $ins_m = $pdo->prepare("INSERT INTO manuais_ficheiros (id_manual, nome_original, nome_servidor, tamanho_bytes, id_utilizador_upload, data_upload) VALUES (?, ?, ?, ?, ?, ?)");
        $limpa = $pdo->prepare("UPDATE manuais SET nome_ficheiro_servidor = '' WHERE id = ?");
        foreach ($antigos as $a) {
            $ins_m->execute([$a['id'], $a['nome_ficheiro_original'] ?: $a['nome_ficheiro_servidor'], $a['nome_ficheiro_servidor'], (int)$a['tamanho_bytes'], $a['id_utilizador_upload'], $a['data_upload'] ?: date('Y-m-d H:i:s')]);
            $limpa->execute([$a['id']]);
        }
    }
} catch (Exception $e) {
    $mensagem = "Erro ao preparar a tabela dos ficheiros: " . $e->getMessage();
    $tipo_mensagem = "erro";
}

// Lista de ficheiros enviados num campo <input type="file" name="X[]" multiple> (ignora entradas vazias)
function ficheiros_enviados($campo) {
    $res = [];
    if (empty($_FILES[$campo]['name'])) return $res;
    $nomes = (array)$_FILES[$campo]['name'];
    foreach ($nomes as $k => $nome) {
        if ($nome === '' || $nome === null) continue;
        $res[] = ['name' => $nome, 'tmp' => $_FILES[$campo]['tmp_name'][$k] ?? '', 'error' => $_FILES[$campo]['error'][$k] ?? UPLOAD_ERR_NO_FILE, 'size' => (int)($_FILES[$campo]['size'][$k] ?? 0)];
    }
    return $res;
}
// Verifica todos os ficheiros antes de gravar algum: devolve '' se estiver tudo bem, ou a mensagem de erro
function validar_ficheiros($lista, $extensoes_permitidas, $tamanho_maximo_bytes) {
    foreach ($lista as $f) {
        if ($f['error'] !== UPLOAD_ERR_OK) return "Erro ao carregar \"" . $f['name'] . "\" (código " . (int)$f['error'] . "). Verifique o tamanho e tente novamente.";
        $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, $extensoes_permitidas, true)) return "\"" . $f['name'] . "\": tipo de ficheiro não permitido. Formatos aceites: " . implode(', ', $extensoes_permitidas) . ".";
        if ($f['size'] > $tamanho_maximo_bytes) return "\"" . $f['name'] . "\" é demasiado grande (máximo " . round($tamanho_maximo_bytes / 1024 / 1024) . " MB).";
    }
    return '';
}
// Guarda os ficheiros na pasta e regista-os no manual. Em caso de falha apaga os que já tinha guardado.
function guardar_ficheiros($pdo, $id_manual, $lista, $pasta, $id_utilizador) {
    $guardados = [];
    $ins = $pdo->prepare("INSERT INTO manuais_ficheiros (id_manual, nome_original, nome_servidor, tamanho_bytes, id_utilizador_upload, data_upload) VALUES (?, ?, ?, ?, ?, NOW())");
    foreach ($lista as $f) {
        $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
        $nome_servidor = 'manual_' . date('YmdHis') . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
        if (!move_uploaded_file($f['tmp'], $pasta . $nome_servidor)) {
            foreach ($guardados as $g) @unlink($pasta . $g);
            throw new Exception("Não foi possível guardar \"" . $f['name'] . "\" no servidor.");
        }
        $guardados[] = $nome_servidor;
        $ins->execute([$id_manual, $f['name'], $nome_servidor, $f['size'], $id_utilizador]);
    }
    return $guardados;
}

// --- PROCESSAR NOVO MANUAL (todos os utilizadores; ficheiros opcionais, um ou vários) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['btn_upload_manual'])) {
    if (!$pode_adicionar_manuais) {
        $mensagem = "Erro: Não tem permissão para adicionar manuais.";
        $tipo_mensagem = "erro";
    } else {
        $categoria_select = trim($_POST['categoria_select'] ?? '');
        $categoria_nova = trim($_POST['categoria_nova'] ?? '');
        $categoria = ($categoria_select === '__nova__') ? $categoria_nova : $categoria_select;
        $titulo = trim($_POST['titulo'] ?? '');
        $descricao = trim($_POST['descricao'] ?? '');
        $visivel_para_input = $_POST['visivel_para'] ?? [];
        $visivel_para_valido = array_values(array_intersect($visivel_para_input, array_keys($perfis_visibilidade)));
        // Quem não gere os manuais tem de continuar a ver o que adicionou: o seu perfil entra sempre
        if (!$pode_gerir_manuais && isset($perfis_visibilidade[$perfil_user]) && !in_array($perfil_user, $visivel_para_valido, true)) {
            $visivel_para_valido[] = $perfil_user;
        }
        $novos = ficheiros_enviados('ficheiros');
        // Pedido maior do que o alojamento aceita: o PHP descarta tudo e os campos chegam vazios
        if (empty($_POST) && !empty($_SERVER['CONTENT_LENGTH'])) {
            $mensagem = "Os ficheiros ultrapassam o tamanho máximo que o alojamento aceita num envio. Envie menos ficheiros de cada vez.";
            $tipo_mensagem = "erro";
        } elseif (empty($categoria)) {
            $mensagem = "Tem de indicar (ou escolher) uma categoria.";
            $tipo_mensagem = "erro";
        } elseif (empty($titulo)) {
            $mensagem = "Tem de indicar um título para o manual.";
            $tipo_mensagem = "erro";
        } elseif (empty($visivel_para_valido)) {
            $mensagem = "Tem de escolher pelo menos um perfil que pode ver o manual.";
            $tipo_mensagem = "erro";
        } elseif (($erro_f = validar_ficheiros($novos, $extensoes_permitidas, $tamanho_maximo_bytes)) !== '') {
            $mensagem = $erro_f;
            $tipo_mensagem = "erro";
        } else {
            try {
                $pdo->beginTransaction();
                $stmt = $pdo->prepare("INSERT INTO manuais (categoria, titulo, descricao, nome_ficheiro_original, nome_ficheiro_servidor, tamanho_bytes, id_utilizador_upload, visivel_para) VALUES (?, ?, ?, '', '', 0, ?, ?)");
                $stmt->execute([$categoria, $titulo, $descricao, $id_utilizador_logado, json_encode($visivel_para_valido)]);
                $id_novo = (int)$pdo->lastInsertId();
                guardar_ficheiros($pdo, $id_novo, $novos, $pasta_manuais, $id_utilizador_logado);
                $pdo->commit();
                registrarLog($pdo, "Adicionou o manual \"$titulo\" (categoria: $categoria, " . count($novos) . " ficheiro(s))", "Manuais");
                $mensagem = $novos ? "Manual adicionado com " . count($novos) . " ficheiro(s)!" : "Manual adicionado (sem ficheiro).";
                $tipo_mensagem = "sucesso";
            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $mensagem = "Erro ao guardar o manual: " . $e->getMessage();
                $tipo_mensagem = "erro";
            }
        }
    }
}
// --- PROCESSAR EDIÇÃO DE MANUAL (só Admin): dados, juntar ficheiros e retirar ficheiros ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['btn_editar_manual'])) {
    if (!$pode_gerir_manuais) {
        $mensagem = "Erro: Apenas o administrador pode editar manuais.";
        $tipo_mensagem = "erro";
    } else {
        $id_editar = filter_input(INPUT_POST, 'id_manual_editar', FILTER_VALIDATE_INT);
        $categoria_select_e = trim($_POST['categoria_select_editar'] ?? '');
        $categoria_nova_e = trim($_POST['categoria_nova_editar'] ?? '');
        $categoria_e = ($categoria_select_e === '__nova__') ? $categoria_nova_e : $categoria_select_e;
        $titulo_e = trim($_POST['titulo_editar'] ?? '');
        $descricao_e = trim($_POST['descricao_editar'] ?? '');
        $visivel_para_input_e = $_POST['visivel_para_editar'] ?? [];
        $visivel_para_valido_e = array_values(array_intersect($visivel_para_input_e, array_keys($perfis_visibilidade)));
        $novos_e = ficheiros_enviados('ficheiros_editar');
        $retirar = array_map('intval', (array)($_POST['retirar_ficheiro'] ?? []));

        if (!$id_editar || empty($categoria_e) || empty($titulo_e) || empty($visivel_para_valido_e)) {
            $mensagem = "Dados inválidos para edição do manual (verifique também se escolheu pelo menos um perfil que pode ver o manual).";
            $tipo_mensagem = "erro";
        } elseif (($erro_f = validar_ficheiros($novos_e, $extensoes_permitidas, $tamanho_maximo_bytes)) !== '') {
            $mensagem = $erro_f;
            $tipo_mensagem = "erro";
        } else {
            try {
                $pdo->beginTransaction();
                $pdo->prepare("UPDATE manuais SET categoria=?, titulo=?, descricao=?, visivel_para=? WHERE id=?")
                    ->execute([$categoria_e, $titulo_e, $descricao_e, json_encode($visivel_para_valido_e), $id_editar]);
                // Ficheiros a retirar (só deste manual)
                $apagar_disco = [];
                if ($retirar) {
                    $st_r = $pdo->prepare("SELECT id, nome_servidor FROM manuais_ficheiros WHERE id_manual = ? AND id = ?");
                    $del_r = $pdo->prepare("DELETE FROM manuais_ficheiros WHERE id = ?");
                    foreach ($retirar as $id_f) {
                        $st_r->execute([$id_editar, $id_f]);
                        if ($r = $st_r->fetch(PDO::FETCH_ASSOC)) { $del_r->execute([$r['id']]); $apagar_disco[] = $r['nome_servidor']; }
                    }
                }
                guardar_ficheiros($pdo, $id_editar, $novos_e, $pasta_manuais, $id_utilizador_logado);
                $pdo->commit();
                foreach ($apagar_disco as $nd) @unlink($pasta_manuais . $nd);
                registrarLog($pdo, "Editou o manual (ID: $id_editar)" . ($novos_e ? ", juntou " . count($novos_e) . " ficheiro(s)" : '') . ($apagar_disco ? ", retirou " . count($apagar_disco) . " ficheiro(s)" : ''), "Manuais");
                $mensagem = "Manual atualizado com sucesso!";
                $tipo_mensagem = "sucesso";
            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $mensagem = "Erro ao editar manual: " . $e->getMessage();
                $tipo_mensagem = "erro";
            }
        }
    }
}
// --- APAGAR MANUAL (só Admin, com password de segurança): apaga também todos os ficheiros dele ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['btn_confirmar_apagar_manual'])) {
    if (!$pode_gerir_manuais) {
        $mensagem = "Erro: Apenas o administrador pode apagar manuais.";
        $tipo_mensagem = "erro";
    } else {
        $id_apagar = filter_input(INPUT_POST, 'id_manual_apagar', FILTER_VALIDATE_INT);
        $pass_introduzida = $_POST['pass_seguranca'] ?? '';
        $stmt_pass = $pdo->prepare("SELECT valor FROM configuracoes WHERE chave = 'password_apagar' LIMIT 1");
        $stmt_pass->execute();
        $pass_config = $stmt_pass->fetchColumn();
        if ($pass_introduzida !== $pass_config || empty($pass_introduzida)) {
            $mensagem = "Password de segurança incorreta.";
            $tipo_mensagem = "erro";
        } else {
            try {
                $st_f = $pdo->prepare("SELECT nome_servidor FROM manuais_ficheiros WHERE id_manual = ?");
                $st_f->execute([$id_apagar]);
                $ficheiros_apagar = $st_f->fetchAll(PDO::FETCH_COLUMN);
                $pdo->beginTransaction();
                $pdo->prepare("DELETE FROM manuais_ficheiros WHERE id_manual = ?")->execute([$id_apagar]);
                $pdo->prepare("DELETE FROM manuais WHERE id = ?")->execute([$id_apagar]);
                $pdo->commit();
                foreach ($ficheiros_apagar as $fa) @unlink($pasta_manuais . $fa);
                registrarLog($pdo, "Apagou o manual (ID: $id_apagar)", "Manuais");
                $mensagem = "Manual removido com sucesso.";
                $tipo_mensagem = "sucesso";
            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $mensagem = "Erro ao apagar manual: " . htmlspecialchars($e->getMessage());
                $tipo_mensagem = "erro";
            }
        }
    }
}
// --- BUSCAR CATEGORIAS JÁ EXISTENTES (para o select de categoria) ---
$categorias_existentes = $pdo->query("SELECT DISTINCT categoria FROM manuais ORDER BY categoria ASC")->fetchAll(PDO::FETCH_COLUMN);
// --- BUSCAR TODOS OS MANUAIS, AGRUPADOS POR CATEGORIA ---
$sql_manuais = "SELECT m.*, u.nome as nome_utilizador_upload
                FROM manuais m
                LEFT JOIN utilizadores u ON m.id_utilizador_upload = u.id
                ORDER BY m.categoria ASC, m.titulo ASC";
$manuais_todos = $pdo->query($sql_manuais)->fetchAll(PDO::FETCH_ASSOC);
// Filtra conforme quem pode ver cada manual. Quem gere os manuais ($pode_gerir_manuais) vê sempre
// todos, para poder editar/apagar qualquer um; os restantes utilizadores só veem os manuais em que
// o seu perfil está incluído em "visivel_para" (registos antigos, sem esse valor definido, contam
// como visíveis para todos, tal como era o comportamento antes desta funcionalidade existir).
$manuais = [];
foreach ($manuais_todos as $m) {
    $perfis_deste_manual = json_decode($m['visivel_para'] ?? '', true);
    if (!is_array($perfis_deste_manual) || empty($perfis_deste_manual)) {
        $perfis_deste_manual = array_keys($perfis_visibilidade);
    }
    $m['visivel_para_lista'] = $perfis_deste_manual;
    if ($pode_gerir_manuais || in_array($perfil_user, $perfis_deste_manual, true)) {
        $manuais[] = $m;
    }
}
// Ficheiros de cada manual
$ficheiros_por_manual = [];
try {
    foreach ($pdo->query("SELECT * FROM manuais_ficheiros ORDER BY id_manual, id")->fetchAll(PDO::FETCH_ASSOC) as $f) $ficheiros_por_manual[$f['id_manual']][] = $f;
} catch (Exception $e) {}
$manuais_por_categoria = [];
foreach ($manuais as $m) {
    $manuais_por_categoria[$m['categoria']][] = $m;
}
// Ícones por extensão, só para dar uma pista visual do tipo de ficheiro
function icone_para_extensao($ext) {
    $ext = strtolower($ext);
    if ($ext === '') return 'fa-note-sticky';      // manual sem ficheiro
    if ($ext === '__varios') return 'fa-folder-open';   // vários ficheiros
    if ($ext === 'pdf') return 'fa-file-pdf';
    if (in_array($ext, ['doc', 'docx'])) return 'fa-file-word';
    if (in_array($ext, ['xls', 'xlsx'])) return 'fa-file-excel';
    if (in_array($ext, ['ppt', 'pptx'])) return 'fa-file-powerpoint';
    if (in_array($ext, ['jpg', 'jpeg', 'png'])) return 'fa-file-image';
    if ($ext === 'txt') return 'fa-file-lines';
    return 'fa-file';
}
// Ficheiros que se podem pré-visualizar no browser
function e_imagem($nome) { return in_array(strtolower(pathinfo((string)$nome, PATHINFO_EXTENSION)), ['jpg', 'jpeg', 'png', 'gif', 'webp'], true); }
function e_pdf($nome) { return strtolower(pathinfo((string)$nome, PATHINFO_EXTENSION)) === 'pdf'; }

function formatar_tamanho($bytes) {
    if ($bytes >= 1024 * 1024) return number_format($bytes / 1024 / 1024, 1, ',', ' ') . ' MB';
    if ($bytes >= 1024) return number_format($bytes / 1024, 0, ',', ' ') . ' KB';
    return $bytes . ' B';
}
// Descreve, em texto, para quem um manual está visível (usado só na vista de quem gere manuais).
function rotulos_visibilidade($lista_perfis, $perfis_map) {
    if (count($lista_perfis) >= count($perfis_map)) {
        return 'Todos';
    }
    $rotulos = [];
    foreach ($perfis_map as $valor => $rotulo) {
        if (in_array($valor, $lista_perfis, true)) {
            $rotulos[] = $rotulo;
        }
    }
    return $rotulos ? implode(', ', $rotulos) : '(ninguém)';
}
?>
<!DOCTYPE html>
<html lang="pt-PT">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Manuais e Procedimentos - Gestão MAB</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { font-family: system-ui, -apple-system, sans-serif; background-color: #f7f9fc; margin: 0; color: #333; padding-bottom: 50px; }
        * { box-sizing: border-box; }

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
        .user-avatar { background: #e74c3c; color: white; width: 28px; height: 28px; border-radius: 50%; display: flex; justify-content: center; align-items: center; font-size: 13px; }

        .dropdown-content { display: none; position: absolute; right: 0; top: 100%; margin-top: 6px; background: #fff; min-width: 180px; box-shadow: 0 12px 30px rgba(0,0,0,0.1); border-radius: 10px; overflow: hidden; border: 1px solid #e8ecf1; z-index:200;}
        .dropdown-content a { color: #576574; padding: 12px 20px; text-decoration: none; display: block; font-size: 14px; }
        .dropdown-content a:hover { background: #eef2fd; color: #4b7bec; }
        .user-menu:hover .dropdown-content, .user-menu.aberto .dropdown-content { display: block; }
        .container { max-width: 1300px; margin: 30px auto; padding: 0 15px; }

        .page-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px; flex-wrap: wrap; gap: 15px; }
        .page-title { margin: 0; color: #2c3e50; font-size: 24px; font-weight: 800; display: flex; align-items: center; gap: 10px; }
        .page-title i { color: #4b7bec; }
        .alert { padding: 15px; border-radius: 8px; margin-bottom: 20px; text-align: center; font-weight: 600; }
        .alert-erro { background: #fdeded; color: #e74c3c; border: 1px solid #f9cccc; }
        .alert-sucesso { background: #eef2fd; color: #3457c9; border: 1px solid #d6e0fb; }

        .card { background: #fff; padding: 25px; border-radius: 12px; border: 1px solid #e1e5ea; box-shadow: 0 4px 15px rgba(0,0,0,0.02); margin-bottom: 20px; }
        .card-header { margin: 0 0 20px 0; padding-bottom: 15px; border-bottom: 1px solid #f1f2f6; font-size: 16px; font-weight: 700; color: #2c3e50; display: flex; align-items: center; gap: 8px; }
        .card-header i { color: #4b7bec; }
        .form-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; }
        .form-group { display: flex; flex-direction: column; }
        .form-group.full { grid-column: 1 / -1; }
        .form-group label { font-weight: 700; font-size: 11px; color: #576574; text-transform: uppercase; margin-bottom: 8px; }
        .form-group label small { text-transform: none; font-weight: 600; color: #95a5a6; }
        .form-group input, .form-group select, .form-group textarea { padding: 12px 15px; border: 1px solid #dcdde1; border-radius: 8px; font-size: 14px; outline: none; transition: border-color 0.2s; font-family: inherit; background: #fff; width: 100%; }
        .form-group input:focus, .form-group select:focus, .form-group textarea:focus { border-color: #4b7bec; box-shadow: 0 0 0 3px rgba(75, 123, 236, 0.1); }
        .form-group input[type="checkbox"] { width: auto; padding: 0; }
        .btn-primary { background: #4b7bec; color: #fff; border: none; padding: 14px 25px; border-radius: 8px; font-weight: 700; font-size: 14px; cursor: pointer; transition: 0.2s; display: inline-flex; align-items: center; justify-content: center; gap: 8px; }
        .btn-primary:hover { background: #3a67d8; }

        .categoria-secao { margin-bottom: 10px; }
        .categoria-titulo { font-size: 15px; font-weight: 800; color: #2c3e50; margin: 0 0 15px 0; display: flex; align-items: center; gap: 8px; padding-bottom: 10px; border-bottom: 2px solid #eef2fd; }
        .categoria-titulo i { color: #4b7bec; font-size: 13px; }

        .lista-manuais { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 15px; margin-bottom: 30px; }
        .manual-item { background: #fdfdfe; border: 1px solid #e1e5ea; border-radius: 10px; padding: 18px; display: flex; gap: 15px; align-items: flex-start; transition: 0.2s; }
        .manual-item:hover { box-shadow: 0 4px 12px rgba(0,0,0,0.05); border-color: #d6e0fb; }
        .manual-icone { width: 42px; height: 42px; border-radius: 10px; background: #eef2fd; color: #4b7bec; display: flex; align-items: center; justify-content: center; font-size: 18px; flex-shrink: 0; }
        .manual-icone.sem-ficheiro { background: #f4f6f8; color: #95a5a6; }
        .manual-info { flex: 1; min-width: 0; }
        .manual-titulo { font-weight: 700; color: #2c3e50; font-size: 14px; margin: 0 0 4px 0; word-break: break-word; }
        .manual-descricao { font-size: 12px; color: #7f8c8d; margin: 0 0 8px 0; line-height: 1.4; }
        .manual-meta { font-size: 11px; color: #95a5a6; margin-bottom: 10px; }
        .manual-visibilidade { font-size: 11px; color: #4b7bec; margin: -4px 0 10px 0; }
        .manual-acoes { display: flex; gap: 8px; flex-wrap: wrap; }
        .btn-descarregar { background: #4b7bec; color: #fff; border: none; padding: 7px 14px; border-radius: 6px; font-size: 12px; font-weight: 700; cursor: pointer; text-decoration: none; display: inline-flex; align-items: center; gap: 6px; }
        .btn-descarregar:hover { background: #3a67d8; }
        .lista-ficheiros { list-style: none; margin: 0 0 10px; padding: 0; border: 1px solid #eef0f3; border-radius: 8px; overflow: hidden; }
        .lista-ficheiros li + li { border-top: 1px solid #eef0f3; }
        .lista-ficheiros a { display: flex; align-items: center; gap: 8px; padding: 7px 10px; text-decoration: none; color: #2c3e50; font-size: 12.5px; }
        .lista-ficheiros a:hover { background: #eef2fd; color: #3457c9; }
        .lista-ficheiros a i { color: #4b7bec; width: 14px; text-align: center; }
        .lista-ficheiros a span { flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .lista-ficheiros a small { color: #95a5a6; white-space: nowrap; }
        .lista-escolhidos { list-style: none; margin: 6px 0 0; padding: 0; font-size: 12px; color: #576574; }
        .lista-escolhidos li { padding: 3px 0; }
        .lista-escolhidos li i { color: #4b7bec; margin-right: 5px; }
        .lista-editar { list-style: none; margin: 0; padding: 0; border: 1px solid #eef0f3; border-radius: 8px; }
        .lista-editar li { display: flex; align-items: center; gap: 8px; padding: 8px 10px; font-size: 13px; }
        .lista-editar li + li { border-top: 1px solid #eef0f3; }
        .lista-editar li span { flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .lista-editar li.retirar span { text-decoration: line-through; color: #e74c3c; }
        .lista-editar label { text-transform: none !important; font-size: 12px !important; color: #e74c3c !important; margin: 0 !important; display: inline-flex; gap: 5px; align-items: center; cursor: pointer; white-space: nowrap; }
        .lista-editar .vazio { color: #95a5a6; }
        .tag-sem-ficheiro { background: #f4f6f8; color: #7f8c8d; padding: 7px 12px; border-radius: 6px; font-size: 12px; font-weight: 700; display: inline-flex; align-items: center; gap: 6px; }
        .btn-editar-mini { color: #4b7bec; background: #eef2fd; border: none; padding: 7px 10px; border-radius: 6px; cursor: pointer; transition: 0.2s; display: inline-flex; align-items: center; justify-content: center; }
        .btn-editar-mini:hover { background: #d6e0fb; }
        .btn-del { color: #e74c3c; background: #fdeded; border: none; padding: 7px 10px; border-radius: 6px; cursor: pointer; transition: 0.2s; display: inline-flex; align-items: center; justify-content: center; }
        .btn-del:hover { background: #fadbd8; color: #c0392b; }

        .campo-nova-categoria { display: none; margin-top: 10px; }
        .campo-nova-categoria.mostrar { display: flex; flex-direction: column; }

        .visibilidade-opcoes { display: flex; gap: 20px; flex-wrap: wrap; padding: 8px 0; }
        .visibilidade-opcoes label { display: flex; align-items: center; gap: 6px; font-weight: 600; font-size: 13px; color: #2c3e50; text-transform: none; margin-bottom: 0; }

        .modal { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.6); z-index: 1001; justify-content: center; align-items: center; backdrop-filter: blur(2px); padding: 15px; }
        .modal.show { display: flex; }
        .modal-content { background: #fff; padding: 30px; border-radius: 12px; width: 100%; max-width: 420px; max-height: 94vh; overflow-y: auto; text-align: center; box-shadow: 0 20px 50px rgba(0,0,0,0.2); }
        .modal-header { border-bottom: none; margin-bottom: 10px; }
        .modal-header h3 { margin: 0; color: #e74c3c; font-size: 18px; display: flex; align-items: center; justify-content: center; gap: 8px; }
        .modal-content input[type="password"] { width: 100%; padding: 12px; border: 1px solid #dcdde1; border-radius: 8px; margin: 15px 0; outline: none; }
        .modal-content input[type="password"]:focus { border-color: #e74c3c; }
        @media (max-width: 1024px) {
            .topbar { padding: 10px 20px; }
        }
        @media (max-width: 768px) {
            .topbar { padding: 10px 15px; }
            .btn-voltar { width: 38px; height: 38px; }
            .user-nome { display: none; }
            .container { margin: 20px auto; }
            .card { padding: 18px; }
            .page-title { font-size: 20px; }
            .form-actions .btn-primary { width: 100%; }
        }
        @media (max-width: 560px) {
            .lista-manuais { grid-template-columns: 1fr; }
            .modal-content { padding: 20px; }
        }
        /* Pré-visualização de imagens / PDF */
        .manual-miniatura { width: 56px; height: 56px; flex-shrink: 0; border-radius: 10px; overflow: hidden; border: 1px solid #e1e5ea; padding: 0; background: #f8f9fa; cursor: zoom-in; }
        .manual-miniatura img { width: 100%; height: 100%; object-fit: cover; display: block; }
        .btn-ver { background: #eef2fa; color: #4b7bec; border: 1px solid #d6e0f5; padding: 7px 14px; border-radius: 6px; font-size: 12px; font-weight: 700; cursor: pointer; text-decoration: none; display: inline-flex; align-items: center; gap: 6px; font-family: inherit; }
        .btn-ver:hover { background: #dfe7f7; }
        .lista-ficheiros li { display: flex; align-items: center; gap: 6px; padding-left: 6px; }
        .lista-ficheiros li > a:last-child { flex: 1; min-width: 0; }
        .lista-ficheiros a.ficheiro-ver { padding: 0; flex: 0 0 36px; justify-content: center; }
        .lista-ficheiros a.ficheiro-ver i { color: #4b7bec; width: auto; }
        .ficheiro-mini { width: 36px; height: 36px; flex-shrink: 0; border-radius: 6px; overflow: hidden; border: 1px solid #e1e5ea; padding: 0; background: #f8f9fa; cursor: zoom-in; }
        .ficheiro-mini img { width: 100%; height: 100%; object-fit: cover; display: block; }
        .ficheiro-ver { width: 36px; height: 36px; flex-shrink: 0; border-radius: 6px; border: 1px solid #d6e0f5; background: #eef2fa; color: #4b7bec; display: inline-flex; align-items: center; justify-content: center; text-decoration: none; }
        .img-zoom { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.85); z-index: 3000; align-items: center; justify-content: center; flex-direction: column; padding: 20px; gap: 12px; }
        .img-zoom.show { display: flex; }
        .img-zoom img { max-width: 100%; max-height: calc(100vh - 110px); object-fit: contain; border-radius: 6px; background: #fff; }
        .img-zoom .zoom-barra { display: flex; align-items: center; gap: 12px; color: #fff; font-weight: 600; font-size: 13px; flex-wrap: wrap; justify-content: center; }
        .img-zoom .zoom-barra a { background: #4b7bec; color: #fff; padding: 7px 14px; border-radius: 6px; text-decoration: none; font-weight: 700; font-size: 12px; }
        .img-zoom .zoom-fechar { position: absolute; top: 14px; right: 18px; background: none; border: none; color: #fff; font-size: 30px; cursor: pointer; line-height: 1; }
    </style>
</head>
<body>
    <header class="topbar">
        <div class="topbar-left"><a href="index.php" title="Página inicial"><img src="mab.png" alt="MAB" class="logo"></a></div>
        <div class="topbar-right">
            <a href="index.php" class="btn-voltar" title="Voltar" aria-label="Voltar"><i class="fa-solid fa-arrow-left"></i></a>
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
        <?php if ($mensagem): ?><div class="alert alert-<?= htmlspecialchars($tipo_mensagem) ?>"><i class="fa-solid fa-circle-info"></i> <?= htmlspecialchars($mensagem) ?></div><?php endif; ?>
        <div class="page-header">
            <h1 class="page-title"><i class="fa-solid fa-book-open"></i> Manuais e Procedimentos</h1>
        </div>

        <?php if ($pode_adicionar_manuais): ?>
        <div class="card">
            <div class="card-header"><i class="fa-solid fa-cloud-arrow-up"></i> Adicionar Manual</div>
            <form method="POST" enctype="multipart/form-data">
                <div class="form-grid">
                    <div class="form-group">
                        <label>Categoria *</label>
                        <select name="categoria_select" id="categoria_select" onchange="toggleNovaCategoria()" required>
                            <option value="">-- Selecione --</option>
                            <?php foreach ($categorias_existentes as $cat): ?>
                                <option value="<?= htmlspecialchars($cat) ?>"><?= htmlspecialchars($cat) ?></option>
                            <?php endforeach; ?>
                            <option value="__nova__">+ Nova Categoria</option>
                        </select>
                        <div class="campo-nova-categoria" id="campo_nova_categoria">
                            <input type="text" name="categoria_nova" id="categoria_nova" placeholder="Nome da nova categoria">
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Título *</label>
                        <input type="text" name="titulo" required placeholder="Ex: Manual de Acolhimento">
                    </div>
                    <div class="form-group">
                        <label>Ficheiros <small>(opcional · pode escolher vários)</small></label>
                        <input type="file" name="ficheiros[]" id="inp_ficheiros" multiple accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt,.jpg,.jpeg,.png" onchange="listarEscolhidos(this, 'lista_escolhidos')">
                        <ul class="lista-escolhidos" id="lista_escolhidos"></ul>
                    </div>
                    <div class="form-group full">
                        <label>Descrição</label>
                        <textarea name="descricao" rows="2" placeholder="Descrição ou conteúdo do procedimento (útil sobretudo quando não há ficheiro)..."></textarea>
                    </div>
                    <div class="form-group full">
                        <label>Quem Pode Ver Este Manual?</label>
                        <div class="visibilidade-opcoes">
                            <?php foreach ($perfis_visibilidade as $valor => $rotulo):
                                // Quem não gere os manuais vê sempre o que adiciona: o seu perfil fica marcado e bloqueado
                                $o_meu = (!$pode_gerir_manuais && $valor === $perfil_user);
                            ?>
                            <label<?= $o_meu ? ' title="O seu perfil vê sempre os manuais que adiciona"' : '' ?>>
                                <input type="checkbox" name="visivel_para[]" value="<?= htmlspecialchars($valor) ?>" checked<?= $o_meu ? ' onclick="return false;"' : '' ?>>
                                <?= htmlspecialchars($rotulo) ?>
                            </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                <div class="form-actions" style="text-align: right; margin-top: 15px; border-top: 1px solid #f1f2f6; padding-top: 20px;">
                    <button type="submit" name="btn_upload_manual" class="btn-primary"><i class="fa-solid fa-floppy-disk"></i> Guardar Manual</button>
                </div>
            </form>
        </div>
        <?php endif; ?>

        <div class="card">
            <div class="card-header"><i class="fa-solid fa-folder-open"></i> Documentos Disponíveis</div>
            <?php if (empty($manuais_por_categoria)): ?>
                <p style="text-align:center; padding: 40px 0; color: #7f8c8d;">Ainda não há manuais ou procedimentos disponíveis.</p>
            <?php else: ?>
                <?php foreach ($manuais_por_categoria as $categoria_nome => $lista_manuais): ?>
                <div class="categoria-secao">
                    <h3 class="categoria-titulo"><i class="fa-solid fa-folder"></i> <?= htmlspecialchars($categoria_nome) ?></h3>
                    <div class="lista-manuais">
                        <?php foreach ($lista_manuais as $m):
                            $fichs = $ficheiros_por_manual[$m['id']] ?? [];
                            $tem_ficheiro_m = !empty($fichs);
                            $ext_m = count($fichs) === 1 ? pathinfo((string)$fichs[0]['nome_original'], PATHINFO_EXTENSION) : ($fichs ? '__varios' : '');
                            $total_bytes = array_sum(array_map(fn($x) => (int)$x['tamanho_bytes'], $fichs));
                        ?>
                        <div class="manual-item">
                            <?php if (count($fichs) === 1 && e_imagem($fichs[0]['nome_original'])): ?>
                                <button type="button" class="manual-miniatura" title="Pré-visualizar" onclick="verImagem(<?= (int)$fichs[0]['id'] ?>, <?= htmlspecialchars(json_encode($fichs[0]['nome_original']), ENT_QUOTES) ?>)">
                                    <img src="manual_download.php?f=<?= (int)$fichs[0]['id'] ?>&amp;ver=1" alt="" loading="lazy">
                                </button>
                            <?php else: ?>
                            <div class="manual-icone<?= $tem_ficheiro_m ? '' : ' sem-ficheiro' ?>"><i class="fa-solid <?= icone_para_extensao($ext_m) ?>"></i></div>
                            <?php endif; ?>
                            <div class="manual-info">
                                <p class="manual-titulo"><?= htmlspecialchars($m['titulo']) ?></p>
                                <?php if (!empty($m['descricao'])): ?><p class="manual-descricao"><?= nl2br(htmlspecialchars($m['descricao'])) ?></p><?php endif; ?>
                                <p class="manual-meta">
                                    <?= $tem_ficheiro_m ? (count($fichs) > 1 ? count($fichs) . ' ficheiros · ' : '') . formatar_tamanho($total_bytes) . ' · ' : '' ?><?= date('d/m/Y', strtotime($m['data_upload'])) ?>
                                    <?php if (!empty($m['nome_utilizador_upload'])): ?> · <?= htmlspecialchars(explode(' ', trim($m['nome_utilizador_upload']))[0]) ?><?php endif; ?>
                                </p>
                                <?php if ($pode_gerir_manuais): ?>
                                <p class="manual-visibilidade"><i class="fa-solid fa-eye"></i> Visível para: <?= htmlspecialchars(rotulos_visibilidade($m['visivel_para_lista'], $perfis_visibilidade)) ?></p>
                                <?php endif; ?>
                                <?php if (count($fichs) > 1): ?>
                                <ul class="lista-ficheiros">
                                    <?php foreach ($fichs as $f): ?>
                                        <li>
                                            <?php if (e_imagem($f['nome_original'])): ?>
                                                <button type="button" class="ficheiro-mini" title="Pré-visualizar" onclick="verImagem(<?= (int)$f['id'] ?>, <?= htmlspecialchars(json_encode($f['nome_original']), ENT_QUOTES) ?>)"><img src="manual_download.php?f=<?= (int)$f['id'] ?>&amp;ver=1" alt="" loading="lazy"></button>
                                            <?php elseif (e_pdf($f['nome_original'])): ?>
                                                <a class="ficheiro-ver" href="manual_download.php?f=<?= (int)$f['id'] ?>&amp;ver=1" target="_blank" title="Pré-visualizar PDF"><i class="fa-solid fa-eye"></i></a>
                                            <?php endif; ?>
                                            <a href="manual_download.php?f=<?= (int)$f['id'] ?>" title="Descarregar"><i class="fa-solid <?= icone_para_extensao(pathinfo((string)$f['nome_original'], PATHINFO_EXTENSION)) ?>"></i> <span><?= htmlspecialchars($f['nome_original']) ?></span> <small><?= formatar_tamanho((int)$f['tamanho_bytes']) ?></small></a>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                                <?php endif; ?>
                                <div class="manual-acoes">
                                    <?php if (count($fichs) === 1): ?>
                                        <?php if (e_imagem($fichs[0]['nome_original'])): ?>
                                            <button type="button" class="btn-ver" onclick="verImagem(<?= (int)$fichs[0]['id'] ?>, <?= htmlspecialchars(json_encode($fichs[0]['nome_original']), ENT_QUOTES) ?>)"><i class="fa-solid fa-eye"></i> Ver</button>
                                        <?php elseif (e_pdf($fichs[0]['nome_original'])): ?>
                                            <a class="btn-ver" href="manual_download.php?f=<?= (int)$fichs[0]['id'] ?>&amp;ver=1" target="_blank"><i class="fa-solid fa-eye"></i> Ver</a>
                                        <?php endif; ?>
                                        <a class="btn-descarregar" href="manual_download.php?f=<?= (int)$fichs[0]['id'] ?>" title="<?= htmlspecialchars($fichs[0]['nome_original']) ?>"><i class="fa-solid fa-download"></i> Descarregar</a>
                                    <?php elseif ($tem_ficheiro_m): ?>
                                    <?php else: ?>
                                        <span class="tag-sem-ficheiro"><i class="fa-solid fa-file-circle-xmark"></i> Sem ficheiro</span>
                                    <?php endif; ?>
                                    <?php if ($pode_gerir_manuais): ?>
                                    <button type="button" class="btn-editar-mini" title="Editar" onclick='abrirModalEditarManual(<?= (int)$m["id"] ?>, <?= json_encode($m["categoria"], JSON_HEX_APOS | JSON_HEX_QUOT) ?>, <?= json_encode($m["titulo"], JSON_HEX_APOS | JSON_HEX_QUOT) ?>, <?= json_encode($m["descricao"], JSON_HEX_APOS | JSON_HEX_QUOT) ?>, <?= json_encode($m["visivel_para_lista"]) ?>, <?= json_encode(array_map(fn($x) => ["id" => (int)$x["id"], "nome" => $x["nome_original"], "tam" => formatar_tamanho((int)$x["tamanho_bytes"])], $fichs), JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'><i class="fa-solid fa-pen-to-square"></i></button>
                                    <button type="button" class="btn-del" title="Apagar" onclick="abrirModalApagarManual(<?= (int)$m['id'] ?>)"><i class="fa-solid fa-trash-can"></i></button>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($pode_gerir_manuais): ?>
    <div class="modal" id="modalApagarManual">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fa-solid fa-triangle-exclamation"></i> Apagar Manual</h3>
            </div>
            <p>Deseja apagar definitivamente este manual/procedimento?</p>
            <form method="POST">
                <input type="hidden" name="id_manual_apagar" id="id_manual_apagar_input">
                <input type="password" name="pass_seguranca" required placeholder="Password de Segurança">
                <div style="display:flex; gap:10px; margin-top:20px;">
                    <button type="button" onclick="fecharModais()" style="flex:1; background:#f1f2f6; border:none; padding:12px; border-radius:8px; cursor:pointer; font-weight:600; color:#576574;">Cancelar</button>
                    <button type="submit" name="btn_confirmar_apagar_manual" style="flex:1; background:#e74c3c; color:#fff; border:none; border-radius:8px; font-weight:600; cursor:pointer;">Apagar</button>
                </div>
            </form>
        </div>
    </div>

    <div class="modal" id="modalEditarManual">
        <div class="modal-content" style="max-width:480px; text-align:left;">
            <div class="modal-header" style="text-align:center;">
                <h3 style="color:#4b7bec; justify-content:center;"><i class="fa-solid fa-pen-to-square"></i> Editar Manual</h3>
            </div>
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="id_manual_editar" id="edit_manual_id">
                <div class="form-grid">
                    <div class="form-group">
                        <label>Categoria</label>
                        <select name="categoria_select_editar" id="edit_manual_categoria_select" onchange="toggleNovaCategoriaEditar()" required>
                            <?php foreach ($categorias_existentes as $cat): ?>
                                <option value="<?= htmlspecialchars($cat) ?>"><?= htmlspecialchars($cat) ?></option>
                            <?php endforeach; ?>
                            <option value="__nova__">+ Nova Categoria</option>
                        </select>
                        <div class="campo-nova-categoria" id="campo_nova_categoria_editar">
                            <input type="text" name="categoria_nova_editar" id="edit_manual_categoria_nova" placeholder="Nome da nova categoria">
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Título</label>
                        <input type="text" name="titulo_editar" id="edit_manual_titulo" required>
                    </div>
                    <div class="form-group full">
                        <label>Descrição</label>
                        <textarea name="descricao_editar" id="edit_manual_descricao" rows="2"></textarea>
                    </div>
                    <div class="form-group full">
                        <label>Ficheiros deste manual</label>
                        <ul class="lista-editar" id="edit_lista_ficheiros"></ul>
                    </div>
                    <div class="form-group full">
                        <label>Juntar ficheiros <small>(opcional · pode escolher vários)</small></label>
                        <input type="file" name="ficheiros_editar[]" multiple accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt,.jpg,.jpeg,.png" onchange="listarEscolhidos(this, 'lista_escolhidos_editar')">
                        <ul class="lista-escolhidos" id="lista_escolhidos_editar"></ul>
                    </div>
                    <div class="form-group full">
                        <label>Quem Pode Ver Este Manual?</label>
                        <div class="visibilidade-opcoes">
                            <?php foreach ($perfis_visibilidade as $valor => $rotulo): ?>
                            <label>
                                <input type="checkbox" name="visivel_para_editar[]" class="edit_manual_visivel_chk" value="<?= htmlspecialchars($valor) ?>">
                                <?= htmlspecialchars($rotulo) ?>
                            </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                <div style="display:flex; gap:10px; margin-top:20px;">
                    <button type="button" onclick="fecharModais()" style="flex:1; background:#f1f2f6; border:none; padding:12px; border-radius:8px; cursor:pointer; font-weight:600; color:#576574;">Cancelar</button>
                    <button type="submit" name="btn_editar_manual" style="flex:1; background:#4b7bec; color:#fff; border:none; border-radius:8px; font-weight:600; cursor:pointer;">Guardar Alterações</button>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>
    <div class="img-zoom" id="imgZoom" onclick="if (event.target === this) fecharImagem()">
        <button type="button" class="zoom-fechar" onclick="fecharImagem()" title="Fechar">&times;</button>
        <img id="imgZoomImg" src="" alt="">
        <div class="zoom-barra"><span id="imgZoomNome"></span><a id="imgZoomDown" href="#"><i class="fa-solid fa-download"></i> Descarregar</a></div>
    </div>

    <script>
        // Pré-visualizar imagem em grande
        function verImagem(id, nome) {
            document.getElementById('imgZoomImg').src = 'manual_download.php?f=' + id + '&ver=1';
            document.getElementById('imgZoomNome').textContent = nome || '';
            document.getElementById('imgZoomDown').href = 'manual_download.php?f=' + id;
            document.getElementById('imgZoom').classList.add('show');
        }
        function fecharImagem() {
            document.getElementById('imgZoom').classList.remove('show');
            document.getElementById('imgZoomImg').src = '';
        }
        document.addEventListener('keydown', e => { if (e.key === 'Escape') fecharImagem(); });

        function toggleNovaCategoria() {
            const select = document.getElementById('categoria_select');
            const campo = document.getElementById('campo_nova_categoria');
            campo.classList.toggle('mostrar', select.value === '__nova__');
        }
        function toggleNovaCategoriaEditar() {
            const select = document.getElementById('edit_manual_categoria_select');
            const campo = document.getElementById('campo_nova_categoria_editar');
            campo.classList.toggle('mostrar', select.value === '__nova__');
        }
        function abrirModalApagarManual(id) {
            document.getElementById('id_manual_apagar_input').value = id;
            document.getElementById('modalApagarManual').classList.add('show');
            document.querySelector('#modalApagarManual input[name="pass_seguranca"]').value = '';
            document.querySelector('#modalApagarManual input[name="pass_seguranca"]').focus();
        }
        const escH = t => String(t ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/"/g, '&quot;');
        // Mostra os nomes dos ficheiros escolhidos (antes de gravar)
        function listarEscolhidos(inp, idLista) {
            const ul = document.getElementById(idLista);
            ul.innerHTML = [...inp.files].map(f => '<li><i class="fa-solid fa-paperclip"></i>' + escH(f.name) + ' (' + (f.size >= 1048576 ? (f.size / 1048576).toFixed(1).replace('.', ',') + ' MB' : Math.round(f.size / 1024) + ' KB') + ')</li>').join('');
        }
        function abrirModalEditarManual(id, categoria, titulo, descricao, visivelPara, ficheiros) {
            document.getElementById('edit_manual_id').value = id;
            document.getElementById('edit_manual_titulo').value = titulo;
            document.getElementById('edit_manual_descricao').value = descricao || '';
            // Ficheiros atuais, cada um com "Retirar" (só é retirado ao gravar)
            const ul = document.getElementById('edit_lista_ficheiros');
            const lista = Array.isArray(ficheiros) ? ficheiros : [];
            ul.innerHTML = lista.length ? lista.map(f => '<li><i class="fa-solid fa-paperclip" style="color:#4b7bec;"></i><span title="' + escH(f.nome) + '">' + escH(f.nome) + '</span><small style="color:#95a5a6;">' + escH(f.tam) + '</small>'
                + '<label><input type="checkbox" name="retirar_ficheiro[]" value="' + f.id + '" onchange="this.closest(\'li\').classList.toggle(\'retirar\', this.checked)"> Retirar</label></li>').join('')
                : '<li class="vazio">Sem ficheiros.</li>';
            document.querySelector('#modalEditarManual input[type=file]').value = '';
            document.getElementById('lista_escolhidos_editar').innerHTML = '';

            const listaVisivel = Array.isArray(visivelPara) ? visivelPara : [];
            document.querySelectorAll('.edit_manual_visivel_chk').forEach(function(chk) {
                chk.checked = listaVisivel.includes(chk.value);
            });

            const select = document.getElementById('edit_manual_categoria_select');
            let categoriaExiste = false;
            for (const opt of select.options) {
                if (opt.value === categoria) { categoriaExiste = true; break; }
            }
            if (categoriaExiste) {
                select.value = categoria;
                document.getElementById('campo_nova_categoria_editar').classList.remove('mostrar');
            } else {
                select.value = '__nova__';
                document.getElementById('edit_manual_categoria_nova').value = categoria;
                document.getElementById('campo_nova_categoria_editar').classList.add('mostrar');
            }
            document.getElementById('modalEditarManual').classList.add('show');
        }
        function fecharModais() {
            document.querySelectorAll('.modal').forEach(function(m) { m.classList.remove('show'); });
        }
        window.onclick = function(e) { if (e && e.target && e.target.classList && e.target.classList.contains('modal')) { fecharModais(); } };

        // Menu do utilizador (toque em ecrãs táteis)
        (function () {
            const menu = document.querySelector('.user-menu'); if (!menu) return;
            const btn = menu.querySelector('.user-btn');
            btn.addEventListener('click', function (e) { e.stopPropagation(); menu.classList.toggle('aberto'); });
            document.addEventListener('click', function () { menu.classList.remove('aberto'); });
        })();
    </script>
</body>
</html>
