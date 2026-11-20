<?php
session_start();
require_once 'db.php';
require_once 'functions.php';

// REGRA DE OURO: Validação de sessão
if (!isset($_SESSION['user_id'])) { 
    header("Location: index.php"); 
    exit; 
}

// SEGURANÇA MÁXIMA: Apenas o 'admin' pode gerir utilizadores
if ($_SESSION['perfil'] !== 'admin') {
    header("Location: index.php?erro=sem_permissao");
    exit;
}

$csrf_token = gerar_csrf_token();

// REGRA DE OURO: 1º Nome no Avatar
$nome_completo = $_SESSION['nome'] ?? 'Utilizador';
$primeiro_nome = explode(' ', trim($nome_completo))[0];

// --- ATUALIZAÇÃO AUTOMÁTICA DA ESTRUTURA DA BASE DE DADOS ---
try {
    $cols = $pdo->query("SHOW COLUMNS FROM utilizadores")->fetchAll(PDO::FETCH_COLUMN);
    $queries = [];
    if (!in_array('ordenado_base', $cols))    $queries[] = "ADD COLUMN ordenado_base DECIMAL(10,2) DEFAULT 0.00";
    if (!in_array('meses_ano', $cols))        $queries[] = "ADD COLUMN meses_ano INT DEFAULT 14";
    if (!in_array('sub_alimentacao', $cols))  $queries[] = "ADD COLUMN sub_alimentacao DECIMAL(10,2) DEFAULT 0.00";
    if (!in_array('ajudas_custo', $cols))     $queries[] = "ADD COLUMN ajudas_custo DECIMAL(10,2) DEFAULT 0.00";
    if (!in_array('seguro_acidentes', $cols)) $queries[] = "ADD COLUMN seguro_acidentes DECIMAL(5,2) DEFAULT 0.00";
    if (!in_array('dias_ferias', $cols))      $queries[] = "ADD COLUMN dias_ferias INT DEFAULT 22";
    if (!in_array('ferias_marcadas', $cols))  $queries[] = "ADD COLUMN ferias_marcadas TEXT DEFAULT NULL";
    
    if (!empty($queries)) {
        $pdo->exec("ALTER TABLE utilizadores " . implode(", ", $queries));
    }
} catch (Exception $e) {}
// -------------------------------------------------------------

$id_edit = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$modo_editar = !empty($id_edit);
$mensagem = ''; $tipo_mensagem = '';

function validarNIF($nif) {
    if (!preg_match("/^[0-9]{9}$/", $nif)) return false;
    $soma = 0;
    for ($i = 0; $i < 8; $i++) { $soma += $nif[$i] * (9 - $i); }
    $resto = $soma % 11;
    $digito_controlo = ($resto < 2) ? 0 : 11 - $resto;
    return $digito_controlo == $nif[8];
}

$v_nome = ''; $v_nif = ''; $v_email = ''; $v_telefone = '';
$v_cargo = ''; $v_perfil = 'funcionario'; $v_username = ''; $v_ativo = 1;
$v_ordenado_base = '0.00'; $v_meses_ano = '14'; $v_sub_alimentacao = '0.00'; 
$v_ajudas_custo = '0.00'; $v_seguro_acidentes = '0.00'; $v_dias_ferias = '22';
$v_valor_hora = '0.00'; 
$v_ferias_marcadas = '[]'; 

if ($modo_editar && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $stmt = $pdo->prepare("SELECT * FROM utilizadores WHERE id = ?");
    $stmt->execute([$id_edit]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) { header("Location: utilizadores.php"); exit; }

    $v_nome = $user['nome']; $v_nif = $user['nif']; $v_email = $user['email'];
    $v_telefone = $user['telefone']; $v_cargo = $user['cargo']; $v_perfil = $user['perfil'];
    $v_username = $user['username']; $v_ativo = $user['ativo'];
    
    $v_ordenado_base = $user['ordenado_base'] ?? '0.00';
    $v_meses_ano = $user['meses_ano'] ?? '14';
    $v_sub_alimentacao = $user['sub_alimentacao'] ?? '0.00';
    $v_ajudas_custo = $user['ajudas_custo'] ?? '0.00';
    $v_seguro_acidentes = $user['seguro_acidentes'] ?? '0.00';
    $v_dias_ferias = $user['dias_ferias'] ?? '22';
    $v_valor_hora = $user['valor_hora'] ?? '0.00';
    $v_ferias_marcadas = $user['ferias_marcadas'] ?: '[]';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    if (!validar_csrf_token($_POST['csrf_token'] ?? '')) {
        die("Tentativa de gravação bloqueada por segurança (CSRF Inválido). Volte atrás e tente novamente.");
    }

    $alterou_credenciais = isset($_POST['alterou_credenciais']) && $_POST['alterou_credenciais'] === '1';

    $v_nome = trim($_POST['nome']);
    $v_nif = trim($_POST['nif']);
    $v_email = trim($_POST['email']);
    $v_telefone = trim($_POST['telefone']);
    $v_cargo = trim($_POST['cargo']);
    $v_perfil = $_POST['perfil'];
    $v_ativo = isset($_POST['ativo']) ? (int)$_POST['ativo'] : 1;
    
    $v_ordenado_base = floatval(str_replace(',', '.', $_POST['ordenado_base'] ?? 0));
    $v_meses_ano = (int)($_POST['meses_ano'] ?? 14);
    $v_sub_alimentacao = floatval(str_replace(',', '.', $_POST['sub_alimentacao'] ?? 0));
    $v_ajudas_custo = floatval(str_replace(',', '.', $_POST['ajudas_custo'] ?? 0));
    $v_seguro_acidentes = floatval(str_replace(',', '.', $_POST['seguro_acidentes'] ?? 0));
    $v_dias_ferias = (int)($_POST['dias_ferias'] ?? 22);
    $v_valor_hora = floatval(str_replace(',', '.', $_POST['valor_hora'] ?? 0));
    
    // Férias JSON
    $inicios_ferias = $_POST['ferias_inicio'] ?? [];
    $dias_ferias_lista = $_POST['ferias_dias'] ?? [];
    $ferias_guardar = [];
    for ($i = 0; $i < count($inicios_ferias); $i++) {
        if (!empty($inicios_ferias[$i]) && !empty($dias_ferias_lista[$i])) {
            $ferias_guardar[] = [
                'inicio' => $inicios_ferias[$i],
                'dias' => (int)$dias_ferias_lista[$i]
            ];
        }
    }
    // Guardar sempre por ordem crescente de data de início
    usort($ferias_guardar, fn($a, $b) => strcmp($a['inicio'], $b['inicio']));
    $v_ferias_marcadas = json_encode($ferias_guardar);
    
    // 🛡️ LÓGICA DE CREDENCIAIS (ANTI-AUTOFILL)
    if ($modo_editar && !$alterou_credenciais) {
        $stmt_old = $pdo->prepare("SELECT username FROM utilizadores WHERE id = ?");
        $stmt_old->execute([$id_edit]);
        $v_username = $stmt_old->fetchColumn();
        $v_password = ''; 
    } else {
        $v_username = trim($_POST['cred_username'] ?? '');
        $v_password = trim($_POST['cred_password'] ?? ''); 
    }

    if (!validarNIF($v_nif)) {
        $mensagem = "O NIF introduzido não é válido."; $tipo_mensagem = "erro";
    } elseif (!preg_match("/^[0-9]{9}$/", $v_telefone)) {
        $mensagem = "O telefone deve ter exatamente 9 algarismos."; $tipo_mensagem = "erro";
    } else {
        $sql_check = "SELECT id FROM utilizadores WHERE (username = ? OR nif = ?) ";
        $params_check = [$v_username, $v_nif];
        if ($modo_editar) { $sql_check .= "AND id != ?"; $params_check[] = $id_edit; }
        
        $check = $pdo->prepare($sql_check);
        $check->execute($params_check);
        
        if ($check->fetch()) {
            $mensagem = "O NIF ou o Username já estão registados noutra conta."; $tipo_mensagem = "erro";
        } else {
            try {
                if ($modo_editar) {
                    if ($alterou_credenciais) {
                        if (!empty($v_password)) {
                            $hash = password_hash($v_password, PASSWORD_DEFAULT);
                            $stmt = $pdo->prepare("UPDATE utilizadores SET nome=?, nif=?, email=?, telefone=?, cargo=?, perfil=?, username=?, password=?, ativo=?, ordenado_base=?, meses_ano=?, sub_alimentacao=?, ajudas_custo=?, seguro_acidentes=?, dias_ferias=?, valor_hora=?, ferias_marcadas=? WHERE id=?");
                            $stmt->execute([$v_nome, $v_nif, $v_email, $v_telefone, $v_cargo, $v_perfil, $v_username, $hash, $v_ativo, $v_ordenado_base, $v_meses_ano, $v_sub_alimentacao, $v_ajudas_custo, $v_seguro_acidentes, $v_dias_ferias, $v_valor_hora, $v_ferias_marcadas, $id_edit]);
                        } else {
                            $stmt = $pdo->prepare("UPDATE utilizadores SET nome=?, nif=?, email=?, telefone=?, cargo=?, perfil=?, username=?, ativo=?, ordenado_base=?, meses_ano=?, sub_alimentacao=?, ajudas_custo=?, seguro_acidentes=?, dias_ferias=?, valor_hora=?, ferias_marcadas=? WHERE id=?");
                            $stmt->execute([$v_nome, $v_nif, $v_email, $v_telefone, $v_cargo, $v_perfil, $v_username, $v_ativo, $v_ordenado_base, $v_meses_ano, $v_sub_alimentacao, $v_ajudas_custo, $v_seguro_acidentes, $v_dias_ferias, $v_valor_hora, $v_ferias_marcadas, $id_edit]);
                        }
                    } else {
                        $stmt = $pdo->prepare("UPDATE utilizadores SET nome=?, nif=?, email=?, telefone=?, cargo=?, perfil=?, ativo=?, ordenado_base=?, meses_ano=?, sub_alimentacao=?, ajudas_custo=?, seguro_acidentes=?, dias_ferias=?, valor_hora=?, ferias_marcadas=? WHERE id=?");
                        $stmt->execute([$v_nome, $v_nif, $v_email, $v_telefone, $v_cargo, $v_perfil, $v_ativo, $v_ordenado_base, $v_meses_ano, $v_sub_alimentacao, $v_ajudas_custo, $v_seguro_acidentes, $v_dias_ferias, $v_valor_hora, $v_ferias_marcadas, $id_edit]);
                    }
                    registrarLog($pdo, "Editou utilizador: $v_nome", "Equipa");
                    $id_gravado = $id_edit;
                } else {
                    if (empty($v_password)) { throw new Exception("A palavra-passe é obrigatória para novos utilizadores."); }
                    $hash = password_hash($v_password, PASSWORD_DEFAULT);
                    $stmt = $pdo->prepare("INSERT INTO utilizadores (nome, nif, email, telefone, cargo, perfil, username, password, ativo, ordenado_base, meses_ano, sub_alimentacao, ajudas_custo, seguro_acidentes, dias_ferias, valor_hora, ferias_marcadas, data_registo) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
                    $stmt->execute([$v_nome, $v_nif, $v_email, $v_telefone, $v_cargo, $v_perfil, $v_username, $hash, $v_ativo, $v_ordenado_base, $v_meses_ano, $v_sub_alimentacao, $v_ajudas_custo, $v_seguro_acidentes, $v_dias_ferias, $v_valor_hora, $v_ferias_marcadas]);
                    $id_gravado = (int)$pdo->lastInsertId(); // guardar antes do registo de log
                    registrarLog($pdo, "Criou novo utilizador: $v_nome", "Equipa");
                }

                // Volta à ficha e pergunta se quer regressar à lista ou ficar na página
                header("Location: utilizador_form.php?id=" . (int)$id_gravado . "&gravado=1");
                exit;
            } catch (Exception $e) {
                $mensagem = "Erro na base de dados: " . $e->getMessage(); $tipo_mensagem = "erro";
            }
        }
    }
}

$acabou_de_gravar = $modo_editar && isset($_GET['gravado']) && $_SERVER['REQUEST_METHOD'] !== 'POST';

$ferias_array = json_decode($v_ferias_marcadas, true) ?: [];
if (!is_array($ferias_array)) $ferias_array = [];
// Ordem crescente por data de início
usort($ferias_array, fn($a, $b) => strcmp((string)($a['inicio'] ?? ''), (string)($b['inicio'] ?? '')));

// --- FERIADOS E PONTES (Configurações) para contar só os dias úteis de férias ---
$feriados_config = [];
try {
    $stmt_fer = $pdo->prepare("SELECT valor FROM configuracoes WHERE chave = 'dias_feriados' LIMIT 1");
    $stmt_fer->execute();
    $feriados_config = json_decode($stmt_fer->fetchColumn() ?: '[]', true);
    if (!is_array($feriados_config)) $feriados_config = [];
} catch (Exception $e) {}

// --- PONTES MARCADAS PELA EMPRESA (contam como férias de todos os funcionários) ---
// Vêm de Configurações -> Feriados e Fecho. Só contam as que calham em dias úteis (seg-sex).
$pontes_ferias = [];
try {
    $stmt_pt = $pdo->prepare("SELECT valor FROM configuracoes WHERE chave = 'dias_ponte_ferias' LIMIT 1");
    $stmt_pt->execute();
    $pontes_ferias = json_decode($stmt_pt->fetchColumn() ?: '[]', true);
    if (!is_array($pontes_ferias)) $pontes_ferias = [];
} catch (Exception $e) {}
$pontes_ferias = array_values(array_filter($pontes_ferias, fn($d) => preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && (int)date('N', strtotime($d)) <= 5));
sort($pontes_ferias);
// As pontes também são dias de fecho: os períodos de férias individuais não as contam (evita contar a dobrar)
$feriados_config = array_values(array_unique(array_merge($feriados_config, $pontes_ferias)));

// Dias úteis (seg-sex, sem feriados) de um período, separados por ano: [ano => dias]
function diasUteisPorAno($inicio, $dias, $feriados) {
    $res = [];
    if (empty($inicio) || (int)$dias <= 0) return $res;
    try {
        $d = new DateTime($inicio);
        for ($i = 0; $i < (int)$dias; $i++) {
            $data = $d->format('Y-m-d');
            if ((int)$d->format('N') <= 5 && !in_array($data, $feriados)) {
                $ano = (int)$d->format('Y');
                $res[$ano] = ($res[$ano] ?? 0) + 1;
            }
            $d->modify('+1 day');
        }
    } catch (Exception $e) {}
    return $res;
}

$ano_atual = (int)date('Y');
$ferias_por_ano = [];
foreach ($ferias_array as $f) {
    foreach (diasUteisPorAno($f['inicio'] ?? '', $f['dias'] ?? 0, $feriados_config) as $ano => $n) {
        $ferias_por_ano[$ano] = ($ferias_por_ano[$ano] ?? 0) + $n;
    }
}
// Pontes da empresa somam às férias de cada ano
$pontes_por_ano = [];
foreach ($pontes_ferias as $pd) {
    $ano_p = (int)substr($pd, 0, 4);
    $ferias_por_ano[$ano_p] = ($ferias_por_ano[$ano_p] ?? 0) + 1;
    $pontes_por_ano[$ano_p][] = $pd;
}
$marcados_ano = $ferias_por_ano[$ano_atual] ?? 0;
$faltam_ano = (int)$v_dias_ferias - $marcados_ano;

// Gozados = dias úteis de férias deste ano até hoje (inclusive)
$hoje = date('Y-m-d');
$gozados_ano = 0;
foreach ($ferias_array as $f) {
    if (empty($f['inicio']) || (int)($f['dias'] ?? 0) <= 0) continue;
    try {
        $d = new DateTime($f['inicio']);
        for ($i = 0; $i < (int)$f['dias']; $i++) {
            $data = $d->format('Y-m-d');
            if ($data > $hoje) break;
            if ((int)$d->format('Y') === $ano_atual && (int)$d->format('N') <= 5 && !in_array($data, $feriados_config)) $gozados_ano++;
            $d->modify('+1 day');
        }
    } catch (Exception $e) {}
}
foreach ($pontes_ferias as $pd) {
    if ($pd <= $hoje && (int)substr($pd, 0, 4) === $ano_atual) $gozados_ano++;
}
$por_gozar_ano = (int)$v_dias_ferias - $gozados_ano;     // ainda por gozar (marcados no futuro + por marcar)
$marcados_futuro = $marcados_ano - $gozados_ano;          // já marcados mas ainda não gozados
?>
<!DOCTYPE html>
<html lang="pt-PT">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $modo_editar ? 'Editar Utilizador' : 'Novo Utilizador' ?> - Gestão MAB</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { font-family: system-ui, -apple-system, sans-serif; background: #f7f9fc; margin: 0; color: #333; padding-bottom: 80px;}
        * { box-sizing: border-box; }
        
        /* CABEÇALHO (regras do projeto: logo -> início, sem ícones de navegação, voltar só com símbolo) */
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
        @media (max-width: 1024px) { .topbar { padding: 10px 24px; } }
        @media (max-width: 768px) { .topbar { padding: 8px 15px; } .topbar-left .logo { height: 30px; } .btn-voltar { width: 38px; height: 38px; } .container { margin: 20px auto; padding: 0 12px; } .card { padding: 20px; } }
        @media (max-width: 560px) { .user-nome, .user-btn .fa-caret-down { display: none; } .user-btn { padding: 4px; } }

        .container { max-width: 1000px; margin: 40px auto; padding: 0 20px; }
        
        .page-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px; }
        .page-title { margin: 0; color: #2c3e50; font-size: 24px; font-weight: 800; display: flex; align-items: center; gap: 10px; }
        .page-title i { color: #7f8c8d; }

        .alert { padding: 15px; border-radius: 8px; margin-bottom: 20px; text-align: center; font-weight: 600; }
        .alert-erro { background: #fdeded; color: #e74c3c; border: 1px solid #f9cccc; }

        .card { background: #fff; padding: 35px; border-radius: 12px; border: 1px solid #e1e5ea; box-shadow: 0 4px 15px rgba(0,0,0,0.02); margin-bottom: 25px;}
        .card-header { font-size: 16px; color: #2c3e50; margin: 0 0 20px 0; border-bottom: 2px solid #f1f2f6; padding-bottom: 10px; text-transform: uppercase; display: flex; align-items: center; gap: 8px; font-weight: 800;}
        .header-finance { color: #d35400; border-color: #fae5d3;}
        .header-vacation { color: #27ae60; border-color: #d5f5e3;}

        .form-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 20px; }
        .form-group { display: flex; flex-direction: column; }
        .form-grid > * { min-width: 0; } /* evita que campos largos saiam do ecrã no telemóvel */
        .form-group input, .form-group select, .form-group textarea { width: 100%; min-width: 0; }
        .col-span-2 { grid-column: span 2; }
        .full-width { grid-column: 1 / -1; }
        label { font-weight: 700; margin-bottom: 8px; font-size: 11px; text-transform: uppercase; color: #576574; }
        input, select, textarea { padding: 12px 15px; border: 1px solid #dcdde1; border-radius: 8px; font-size: 14px; background: #fff; transition: border-color 0.2s; font-family: inherit; outline:none;}
        input:focus, select:focus, textarea:focus { border-color: #3498db; box-shadow: 0 0 0 3px rgba(52, 152, 219, 0.1); }
        input.finance-input:focus { border-color: #e67e22; box-shadow: 0 0 0 3px rgba(230, 126, 34, 0.1); }
        
        .valor-hora-box { background: #fffaf0; border: 2px dashed #e67e22; padding: 15px; border-radius: 8px; text-align: center; }
        .valor-hora-box label { color: #d35400; font-size: 13px; margin-bottom: 5px;}
        .valor-hora-box input { background: transparent; border: none; font-size: 28px; font-weight: 900; color: #d35400; text-align: center; padding: 0; box-shadow: none !important;}

        .pass-hint { font-size: 11px; color: #95a5a6; margin-top: 5px; font-weight: 600; }

        .floating-bar { position: fixed; bottom: 20px; right: 20px; left: 20px; max-width: 1000px; margin: 0 auto; background: #fff; padding: 15px 25px; border-radius: 12px; box-shadow: 0 -5px 20px rgba(0,0,0,0.1); display: flex; justify-content: flex-end; z-index: 1000; border: 1px solid #e1e5ea; }
        .btn-save { background: #2c3e50; color: #fff; padding: 14px 30px; border-radius: 8px; border: none; font-weight: 700; font-size: 14px; cursor: pointer; display: inline-flex; align-items: center; justify-content: center; gap: 8px; transition: 0.2s; text-transform: uppercase;}
        .btn-save:hover { background: #1a252f; transform: translateY(-2px);}

        /* Janela depois de gravar */
        .modal-gravado { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.55); z-index: 2000; justify-content: center; align-items: center; padding: 20px; }
        .modal-gravado.show { display: flex; }
        .modal-gravado .caixa { background: #fff; border-radius: 16px; padding: 28px; width: 100%; max-width: 420px; text-align: center; box-shadow: 0 20px 50px rgba(0,0,0,0.2); }
        .modal-gravado .icone-ok { width: 56px; height: 56px; border-radius: 50%; background: #eafaf1; color: #27ae60; display: inline-flex; align-items: center; justify-content: center; font-size: 26px; margin-bottom: 12px; }
        .modal-gravado h3 { margin: 0 0 6px; color: #2c3e50; font-size: 19px; }
        .modal-gravado p { margin: 0 0 20px; color: #7f8c8d; font-size: 14px; }
        .modal-gravado .botoes { display: flex; flex-direction: column; gap: 10px; }
        .btn-modal { padding: 13px; border-radius: 10px; font-weight: 700; font-size: 14px; cursor: pointer; text-decoration: none; display: flex; align-items: center; justify-content: center; gap: 8px; border: 1px solid #dcdde1; background: #fff; color: #2c3e50; font-family: inherit; }
        .btn-modal.principal { background: #2c3e50; border-color: #2c3e50; color: #fff; }
        .btn-modal:hover { filter: brightness(0.95); }

        .switch-container { display: flex; align-items: center; gap: 10px; margin-top: 10px; }
        .switch { position: relative; display: inline-block; width: 44px; height: 24px; }
        .switch input { opacity: 0; width: 0; height: 0; }
        .slider { position: absolute; cursor: pointer; top: 0; left: 0; right: 0; bottom: 0; background-color: #e74c3c; transition: .3s; border-radius: 24px; }
        .slider:before { position: absolute; content: ""; height: 18px; width: 18px; left: 3px; bottom: 3px; background-color: white; transition: .3s; border-radius: 50%; box-shadow: 0 2px 4px rgba(0,0,0,0.2); }
        input:checked + .slider { background-color: #2ecc71; }
        input:checked + .slider:before { transform: translateX(20px); }
        .status-text { font-weight: 700; font-size: 13px; color: #2c3e50; }

        .ferias-row { display: flex; gap: 15px; align-items: center; margin-bottom: 10px; background: #fdfdfd; padding: 10px; border: 1px solid #f1f2f6; border-radius: 8px; }
        .btn-remove-ferias { background: #fdeded; color: #e74c3c; border: 1px solid #f9cccc; width: 40px; height: 40px; border-radius: 8px; cursor: pointer; display: flex; justify-content: center; align-items: center; transition: 0.2s; flex-shrink: 0; margin-top: 18px;}
        .btn-remove-ferias:hover { background: #e74c3c; color: #fff; }
        .btn-add-ferias { background: #eafaf1; color: #27ae60; border: 1px dashed #2ecc71; padding: 12px; width: 100%; border-radius: 8px; font-weight: 600; cursor: pointer; transition: 0.2s; margin-top: 10px;}
        .btn-add-ferias:hover { background: #2ecc71; color: #fff; }

        /* Resumo de férias do ano */
        .resumo-ferias { display: grid; grid-template-columns: repeat(5, 1fr); gap: 12px; margin-bottom: 18px; }
        .rf-box .sub { display: block; font-size: 11px; color: #95a5a6; margin-top: 3px; font-weight: 600; }
        .rf-box.gozados { background: #ebf5fb; border-color: #aed6f1; }
        .rf-box.gozados b { color: #2980b9; }
        .rf-box.por-gozar { background: #fef5e7; border-color: #f9d9a6; }
        .rf-box.por-gozar b { color: #d35400; }
        .rf-box { background: #f8f9fa; border: 1px solid #e1e5ea; border-radius: 10px; padding: 14px; text-align: center; }
        .rf-box small { display: block; font-size: 11px; font-weight: 700; color: #7f8c8d; text-transform: uppercase; margin-bottom: 4px; }
        .rf-box b { font-size: 24px; color: #2c3e50; }
        .rf-box.faltam { background: #eafaf1; border-color: #abebc6; }
        .rf-box.faltam b { color: #27ae60; }
        .rf-box.excesso { background: #fdeded; border-color: #f5b7b1; }
        .rf-box.excesso b, .rf-box.excesso small { color: #c0392b; }
        .rf-outros { font-size: 12px; color: #7f8c8d; margin: -8px 0 15px; }
        .bloco-pontes { background: #f6fdf9; border: 1px dashed #82e0aa; border-radius: 10px; padding: 12px 14px; margin-bottom: 15px; }
        .bloco-pontes h4 { margin: 0 0 8px; font-size: 13px; color: #1e8449; display: flex; justify-content: space-between; align-items: center; gap: 10px; flex-wrap: wrap; }
        .bloco-pontes h4 a { font-size: 11px; color: #7f8c8d; font-weight: 600; text-decoration: none; }
        .bloco-pontes h4 a:hover { color: #27ae60; }
        .chips-pontes { display: flex; flex-wrap: wrap; gap: 6px; }
        .chip-ponte { background: #fff; border: 1px solid #abebc6; border-radius: 20px; padding: 4px 10px; font-size: 12px; color: #2c3e50; font-weight: 600; display: inline-flex; align-items: center; gap: 6px; }
        .chip-ponte.gozada { background: #eafaf1; color: #7f8c8d; }
        .chip-ponte small { font-size: 10px; font-weight: 700; text-transform: uppercase; color: #27ae60; }
        .chip-ponte.gozada small { color: #95a5a6; }
        .ferias-info { font-size: 11.5px; color: #7f8c8d; font-weight: 600; min-width: 150px; margin-top: 18px; }
        .ferias-info b { color: #27ae60; }

        .btn-unlock-creds { background: #fdfdfd; border: 1px solid #dcdde1; padding: 6px 12px; border-radius: 6px; cursor: pointer; color: #576574; font-weight: bold; font-size: 11px; transition: 0.2s; display: inline-flex; align-items: center; gap: 5px;}
        .btn-unlock-creds:hover { border-color: #3498db; color: #3498db; }

        .input-dummy { background-color: #f1f2f6; color: #7f8c8d; cursor: not-allowed; text-align: center; letter-spacing: 3px; font-weight: bold;}

        @media (max-width: 768px) { 
            .form-grid { grid-template-columns: 1fr; } 
            .col-span-2 { grid-column: span 1; }
            .ferias-row { flex-direction: column; align-items: flex-start; }
            .btn-remove-ferias { width: 100%; margin-top: 0; }
            .ferias-row .form-group { width: 100%; }
            .ferias-info { margin-top: 0; }
            .resumo-ferias { grid-template-columns: 1fr 1fr; gap: 8px; }
            .resumo-ferias .rf-box:first-child { grid-column: 1 / -1; }
            .rf-box { padding: 10px 6px; }
            .rf-box b { font-size: 20px; }
        }
    </style>
</head>
<body>
    
    <header class="topbar">
        <div class="topbar-left"><a href="index.php" title="Página inicial"><img src="mab.png" alt="MAB" class="logo"></a></div>
        <div class="topbar-right">
            <a href="utilizadores.php" class="btn-voltar" title="Voltar" aria-label="Voltar"><i class="fa-solid fa-arrow-left"></i></a>
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
        <div class="page-header">
            <h1 class="page-title">
                <i class="fa-solid <?= $modo_editar ? 'fa-user-pen' : 'fa-user-plus' ?>"></i>
                <?= $modo_editar ? 'Editar Colaborador' : 'Adicionar Colaborador' ?>
            </h1>
        </div>

        <?php if ($mensagem): ?><div class="alert alert-<?= $tipo_mensagem ?>"><i class="fa-solid fa-circle-exclamation"></i> <?= htmlspecialchars($mensagem) ?></div><?php endif; ?>
        
        <form method="POST" id="userForm" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
            <input type="hidden" name="alterou_credenciais" id="alterou_credenciais" value="<?= $modo_editar ? '0' : '1' ?>">

            <!-- BLOCO 1: DADOS PESSOAIS -->
            <div class="card">
                <h2 class="card-header"><i class="fa-solid fa-address-card"></i> Identificação e Acessos</h2>
                <div class="form-grid">
                    
                    <div class="form-group full-width" style="border-bottom: 1px solid #f1f2f6; padding-bottom: 15px; margin-bottom: 5px;">
                        <label>Estado da Conta</label>
                        <div class="switch-container">
                            <label class="switch">
                                <input type="checkbox" name="ativo" id="chk_ativo" value="1" <?= $v_ativo ? 'checked' : '' ?>>
                                <span class="slider"></span>
                            </label>
                            <span class="status-text" id="lbl_ativo"><?= $v_ativo ? 'Conta Ativa no Sistema' : 'Conta Suspensa / Inativa' ?></span>
                        </div>
                    </div>

                    <div class="form-group col-span-2">
                        <label>Nome Completo *</label>
                        <input type="text" name="nome" value="<?= htmlspecialchars($v_nome) ?>" required placeholder="Ex: João Silva Manuel">
                    </div>
                    <div class="form-group">
                        <label>NIF *</label>
                        <input type="text" name="nif" id="nif" maxlength="9" value="<?= htmlspecialchars($v_nif) ?>" required placeholder="9 algarismos">
                    </div>

                    <div class="form-group">
                        <label>Telemóvel / Telefone *</label>
                        <input type="text" name="telefone" id="telefone" maxlength="9" value="<?= htmlspecialchars($v_telefone) ?>" required placeholder="9 algarismos">
                    </div>
                    <div class="form-group col-span-2">
                        <label>Correio Eletrónico (Email)</label>
                        <input type="email" name="email" value="<?= htmlspecialchars($v_email) ?>" placeholder="email@empresa.pt">
                    </div>

                    <div class="form-group">
                        <label>Cargo / Função na Empresa</label>
                        <input type="text" name="cargo" value="<?= htmlspecialchars($v_cargo) ?>" placeholder="Ex: Eletricista, Ajudante...">
                    </div>
                    <div class="form-group col-span-2">
                        <label>Perfil de Acesso no Sistema *</label>
                        <select name="perfil" required>
                            <option value="funcionario" <?= $v_perfil == 'funcionario' ? 'selected' : '' ?>>Funcionário (Apenas preenche relatórios)</option>
                            <option value="externo" <?= $v_perfil == 'externo' ? 'selected' : '' ?>>Externo (Parceiros/Subempreiteiros)</option>
                            <option value="administrativo" <?= $v_perfil == 'administrativo' ? 'selected' : '' ?>>Administrativo (Acesso à Gestão Global)</option>
                            <option value="admin" <?= $v_perfil == 'admin' ? 'selected' : '' ?>>Administrador (Acesso Total)</option>
                        </select>
                    </div>

                    <!-- DIVISÓRIA CREDENCIAIS -->
                    <div class="form-group full-width" style="margin-top: 10px; border-top: 1px dashed #e1e5ea; padding-top: 20px;">
                        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:5px;">
                            <h3 style="margin:0; font-size:14px; color:#2c3e50;"><i class="fa-solid fa-lock"></i> Credenciais de Autenticação</h3>
                            <?php if ($modo_editar): ?>
                                <button type="button" class="btn-unlock-creds" id="btn_desbloqueio" onclick="desbloquearCredenciais()">
                                    <i class="fa-solid fa-pen-to-square"></i> Alterar Credenciais
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Username (Para o Login) *</label>
                        <input type="text" name="cred_username" id="input_username" autocomplete="new-password" required placeholder="Ex: jsilva"
                            <?= $modo_editar ? 'value="********" readonly class="input-dummy"' : 'value="'.htmlspecialchars($v_username).'"' ?>>
                    </div>
                    
                    <div class="form-group">
                        <label>Código de Acesso / Password <?= $modo_editar ? '' : '*' ?></label>
                        <input type="password" name="cred_password" id="input_password" autocomplete="new-password" 
                            <?= $modo_editar ? 'value="********" readonly class="input-dummy"' : 'required placeholder="****"' ?>>
                        
                        <?php if ($modo_editar): ?>
                            <span class="pass-hint" id="hint_password">Dados protegidos por segurança.</span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- BLOCO 2: ZONA FINANCEIRA -->
            <div class="card">
                <h2 class="card-header header-finance"><i class="fa-solid fa-sack-dollar"></i> Zona Financeira (Cálculo Automático)</h2>
                
                <div class="form-grid">
                    <div class="form-group">
                        <label>Ordenado Base (€) *</label>
                        <input type="text" class="finance-input" name="ordenado_base" id="ordenado_base" value="<?= number_format((float)$v_ordenado_base, 2, ',', '') ?>" oninput="formatCusto(this); calcularValorHora();" required>
                    </div>
                    <div class="form-group">
                        <label>Meses Pagos / Ano *</label>
                        <input type="number" class="finance-input" name="meses_ano" id="meses_ano" value="<?= htmlspecialchars($v_meses_ano) ?>" oninput="calcularValorHora();" required>
                    </div>
                    <div class="form-group">
                        <label>Seguro Acidentes de Trabalho (%)</label>
                        <input type="text" class="finance-input" name="seguro_acidentes" id="seguro_acidentes" value="<?= number_format((float)$v_seguro_acidentes, 2, ',', '') ?>" oninput="formatCusto(this); calcularValorHora();" placeholder="Ex: 1,50">
                    </div>

                    <div class="form-group">
                        <label>Sub. Alimentação (Valor Diário €)</label>
                        <input type="text" class="finance-input" name="sub_alimentacao" id="sub_alimentacao" value="<?= number_format((float)$v_sub_alimentacao, 2, ',', '') ?>" oninput="formatCusto(this); calcularValorHora();">
                    </div>
                    <div class="form-group">
                        <label>Ajudas de Custo Mensais (Total €)</label>
                        <input type="text" class="finance-input" name="ajudas_custo" id="ajudas_custo" value="<?= number_format((float)$v_ajudas_custo, 2, ',', '') ?>" oninput="formatCusto(this); calcularValorHora();">
                    </div>
                    <div class="form-group">
                        <label>Direito a Férias (Total Dias/Ano)</label>
                        <input type="number" class="finance-input" name="dias_ferias" id="dias_ferias" value="<?= htmlspecialchars($v_dias_ferias) ?>" oninput="calcularValorHora();" required>
                    </div>

                    <div class="form-group full-width">
                        <div class="valor-hora-box">
                            <label><i class="fa-solid fa-calculator"></i> CUSTO REAL À HORA PARA A EMPRESA (€)</label>
                            <input type="text" name="valor_hora" id="valor_hora_final" value="<?= number_format((float)$v_valor_hora, 2, ',', '') ?>" readonly>
                            <p style="font-size:11px; color:#e67e22; margin: 5px 0 0 0; font-weight:600;">O cálculo tem em conta os dados acima + a TSU de 23.75% à Segurança Social e o trabalho efetivo (descontando férias e feriados).</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- BLOCO 3: GESTÃO DE FÉRIAS ESTRUTURADA -->
            <div class="card">
                <h2 class="card-header header-vacation" style="color: #27ae60; border-color: #d5f5e3;"><i class="fa-solid fa-plane-departure"></i> Agendamento de Férias e Ausências</h2>
                
                <div class="form-group full-width">
                    <p style="font-size:13px; color:#7f8c8d; margin-top:0; margin-bottom: 15px;"><i class="fa-solid fa-info-circle"></i> Registe as datas em que o colaborador esteve ou estará de férias. Isto será refletido automaticamente nos mapas de gestão do mês correspondente. Os períodos ficam ordenados por data ao gravar.</p>

                    <!-- RESUMO DO ANO: só conta dias úteis (sem fins de semana nem feriados das Configurações) -->
                    <div class="resumo-ferias">
                        <div class="rf-box"><small>Direito <?= $ano_atual ?></small><b id="rf_direito"><?= (int)$v_dias_ferias ?></b></div>
                        <div class="rf-box"><small>Marcados <?= $ano_atual ?></small><b id="rf_marcados"><?= $marcados_ano ?></b></div>
                        <div class="rf-box <?= $faltam_ano < 0 ? 'excesso' : 'faltam' ?>" id="rf_box_faltam"><small id="rf_lbl_faltam"><?= $faltam_ano < 0 ? 'Em excesso' : 'Faltam marcar' ?></small><b id="rf_faltam"><?= abs($faltam_ano) ?></b></div>
                        <div class="rf-box gozados" title="Dias úteis de férias já gozados este ano (até hoje)"><small>Gozados</small><b id="rf_gozados"><?= $gozados_ano ?></b><span class="sub">até <?= date('d/m') ?></span></div>
                        <div class="rf-box por-gozar" title="Direito menos os dias já gozados"><small>Faltam gozar</small><b id="rf_por_gozar"><?= max(0, $por_gozar_ano) ?></b><span class="sub" id="rf_sub_por_gozar"><?= $marcados_futuro ?> já marcado(s)</span></div>
                    </div>
                    <div class="rf-outros" id="rf_outros"><?php
                        $outros = array_filter($ferias_por_ano, fn($a) => $a !== $ano_atual, ARRAY_FILTER_USE_KEY);
                        ksort($outros);
                        if ($outros) echo 'Outros anos: ' . implode(' · ', array_map(fn($a, $n) => "$a: $n dia(s) úteis", array_keys($outros), $outros));
                    ?></div>
                    
                    <!-- PONTES DA EMPRESA (só leitura: marcam-se em Configurações -> Feriados e Fecho) -->
                    <?php
                    $dias_sem = ['', 'Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb', 'Dom'];
                    $anos_pontes = array_filter(array_keys($pontes_por_ano), fn($a) => $a >= $ano_atual);
                    sort($anos_pontes);
                    ?>
                    <div class="bloco-pontes">
                        <h4>
                            <span><i class="fa-solid fa-umbrella-beach"></i> Pontes da empresa (contam como férias)</span>
                            <a href="configuracoes.php"><i class="fa-solid fa-gear"></i> Alterar em Configurações</a>
                        </h4>
                        <?php if (empty($anos_pontes)): ?>
                            <span style="font-size:12px; color:#95a5a6;">Não há pontes marcadas pela empresa para <?= $ano_atual ?>.</span>
                        <?php else: foreach ($anos_pontes as $ano_p): ?>
                            <div class="chips-pontes" style="margin-bottom: 6px;">
                                <span style="font-size:12px; font-weight:700; color:#576574; align-self:center;"><?= $ano_p ?> (<?= count($pontes_por_ano[$ano_p]) ?>):</span>
                                <?php foreach ($pontes_por_ano[$ano_p] as $pd): $gozada = $pd <= $hoje; ?>
                                    <span class="chip-ponte<?= $gozada ? ' gozada' : '' ?>"><?= date('d/m', strtotime($pd)) ?> <?= $dias_sem[(int)date('N', strtotime($pd))] ?> <small><?= $gozada ? 'gozada' : 'por gozar' ?></small></span>
                                <?php endforeach; ?>
                            </div>
                        <?php endforeach; endif; ?>
                    </div>

                    <div id="lista_ferias">
                        <?php foreach($ferias_array as $index => $feria): ?>
                            <div class="ferias-row">
                                <div class="form-group" style="flex: 1;">
                                    <label>Data de Início</label>
                                    <input type="date" name="ferias_inicio[]" value="<?= htmlspecialchars($feria['inicio']) ?>" required>
                                </div>
                                <div class="form-group" style="flex: 1;">
                                    <label>Número de Dias (Dias Seguidos)</label>
                                    <input type="number" name="ferias_dias[]" min="1" value="<?= htmlspecialchars($feria['dias']) ?>" required placeholder="Ex: 5">
                                </div>
                                <div class="ferias-info"></div>
                                <button type="button" class="btn-remove-ferias" onclick="removerFerias(this)" title="Remover Período"><i class="fa-solid fa-trash-can"></i></button>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    
                    <button type="button" class="btn-add-ferias" onclick="adicionarFerias()"><i class="fa-solid fa-plus"></i> Adicionar Período de Férias</button>
                </div>
            </div>
            
            <div class="floating-bar">
                <button type="submit" class="btn-save"><i class="fa-solid fa-floppy-disk"></i> Gravar</button>
            </div>
        </form>
    </div>

    <!-- JANELA DEPOIS DE GRAVAR -->
    <div class="modal-gravado <?= $acabou_de_gravar ? 'show' : '' ?>" id="modalGravado">
        <div class="caixa">
            <div class="icone-ok"><i class="fa-solid fa-check"></i></div>
            <h3>Ficha gravada</h3>
            <p>Os dados de <strong><?= htmlspecialchars($v_nome) ?></strong> foram gravados com sucesso. O que pretende fazer?</p>
            <div class="botoes">
                <a href="utilizadores.php?editado=1" class="btn-modal principal"><i class="fa-solid fa-arrow-left"></i> Voltar à lista de colaboradores</a>
                <button type="button" class="btn-modal" onclick="fecharModalGravado()"><i class="fa-solid fa-pen-to-square"></i> Continuar nesta página</button>
            </div>
        </div>
    </div>

    <script>
        // --- JANELA DEPOIS DE GRAVAR ---
        function fecharModalGravado() {
            document.getElementById('modalGravado').classList.remove('show');
        }
        <?php if ($acabou_de_gravar): ?>
        // Tira o "&gravado=1" do endereço para a janela não voltar a aparecer ao atualizar a página
        if (window.history.replaceState) {
            window.history.replaceState(null, '', 'utilizador_form.php?id=<?= (int)$id_edit ?>');
        }
        document.addEventListener('keydown', function (e) { if (e.key === 'Escape') fecharModalGravado(); });
        <?php endif; ?>

        document.getElementById('nif').oninput = function() { this.value = this.value.replace(/\D/g, ''); };
        document.getElementById('telefone').oninput = function() { this.value = this.value.replace(/\D/g, ''); };

        function formatCusto(el) {
            if(el.value !== "") {
                let val = el.value.replace(/[^0-9.,]/g, '').replace(',', '.');
                el.value = val.replace(/\./g, ',');
            }
        }
        
        function getFloatValue(elementId) {
            let val = document.getElementById(elementId).value.replace(',', '.');
            return parseFloat(val) || 0;
        }

        // --- SISTEMA DE DESBLOQUEIO DE CREDENCIAIS ---
        function desbloquearCredenciais() {
            document.getElementById('alterou_credenciais').value = '1';
            
            let user = document.getElementById('input_username');
            let pass = document.getElementById('input_password');
            let hint = document.getElementById('hint_password');

            user.removeAttribute('readonly');
            user.classList.remove('input-dummy');
            user.value = '<?= htmlspecialchars($v_username) ?>';

            pass.removeAttribute('readonly');
            pass.classList.remove('input-dummy');
            pass.value = '';
            pass.placeholder = 'Digite a nova password';

            if(hint) {
                hint.innerText = "Deixe em branco se quiser manter a password atual.";
                hint.style.color = "#e67e22";
            }

            user.focus();
            document.getElementById('btn_desbloqueio').style.display = 'none';
        }

        // --- MOTOR FINANCEIRO ---
        function calcularValorHora() {
            let base = getFloatValue('ordenado_base');
            let meses = parseInt(document.getElementById('meses_ano').value) || 14;
            let sub_alim_diario = getFloatValue('sub_alimentacao');
            let ajudas_mensais = getFloatValue('ajudas_custo');
            let seguro_perc = getFloatValue('seguro_acidentes');
            let ferias_direito = parseInt(document.getElementById('dias_ferias').value) || 22;

            let custo_base_anual = base * meses;
            let tsu_anual = custo_base_anual * 0.2375;
            let seguro_anual = custo_base_anual * (seguro_perc / 100);
            
            let dias_trabalho = 260 - ferias_direito - 13; // 260 dias úteis - Férias - Feriados
            let horas_ano = dias_trabalho * 8; 

            // Subsídio de Alimentação pago APENAS nos dias de trabalho efetivo
            let sub_alim_anual = sub_alim_diario * dias_trabalho;
            
            // Ajudas de Custo assumidas mensalmente (11 meses de trabalho)
            let ajudas_anual = ajudas_mensais * 11;

            let custo_total_anual = custo_base_anual + tsu_anual + seguro_anual + sub_alim_anual + ajudas_anual;

            let valor_hora = 0;
            if (horas_ano > 0) {
                valor_hora = custo_total_anual / horas_ano;
            }

            document.getElementById('valor_hora_final').value = valor_hora.toFixed(2).replace('.', ',');
        }

        document.getElementById('chk_ativo').addEventListener('change', function() {
            document.getElementById('lbl_ativo').innerText = this.checked ? 'Conta Ativa no Sistema' : 'Conta Suspensa / Inativa';
        });

        // --- SISTEMA DINÂMICO DE FÉRIAS ---
        function adicionarFerias() {
            const container = document.getElementById('lista_ferias');
            const row = document.createElement('div');
            row.className = 'ferias-row';
            row.innerHTML = `
                <div class="form-group" style="flex: 1;">
                    <label>Data de Início</label>
                    <input type="date" name="ferias_inicio[]" required>
                </div>
                <div class="form-group" style="flex: 1;">
                    <label>Número de Dias (Dias Seguidos)</label>
                    <input type="number" name="ferias_dias[]" min="1" required placeholder="Ex: 5">
                </div>
                <div class="ferias-info"></div>
                <button type="button" class="btn-remove-ferias" onclick="removerFerias(this)" title="Remover Período"><i class="fa-solid fa-trash-can"></i></button>
            `;
            container.appendChild(row);
            atualizarResumoFerias();
        }

        function removerFerias(botao) {
            botao.parentElement.remove();
            atualizarResumoFerias();
        }

        // --- DIAS DE FÉRIAS QUE FALTAM MARCAR (só dias úteis: seg-sex, sem feriados) ---
        const FERIADOS = <?= json_encode(array_values($feriados_config)) ?>;
        const PONTES = <?= json_encode($pontes_ferias) ?>; // pontes da empresa (contam como férias)
        const ANO_ATUAL = <?= $ano_atual ?>;
        const HOJE = '<?= $hoje ?>';

        function isoData(d) {
            return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
        }

        // Devolve { ano: dias úteis } e acumula em gozados.n os dias úteis do ano atual até hoje
        function diasUteisPorAno(inicio, dias, gozados) {
            const res = {};
            if (!inicio || !(dias > 0)) return res;
            const p = inicio.split('-');
            const d = new Date(+p[0], +p[1] - 1, +p[2]);
            for (let i = 0; i < dias; i++) {
                const dow = d.getDay(); // 0 = domingo, 6 = sábado
                const iso = isoData(d);
                if (dow !== 0 && dow !== 6 && !FERIADOS.includes(iso)) {
                    res[d.getFullYear()] = (res[d.getFullYear()] || 0) + 1;
                    if (gozados && iso <= HOJE && d.getFullYear() === ANO_ATUAL) gozados.n++;
                }
                d.setDate(d.getDate() + 1);
            }
            return res;
        }

        function atualizarResumoFerias() {
            const totais = {};
            const gozados = { n: 0 };
            document.querySelectorAll('#lista_ferias .ferias-row').forEach(row => {
                const inicio = row.querySelector('input[name="ferias_inicio[]"]').value;
                const dias = parseInt(row.querySelector('input[name="ferias_dias[]"]').value) || 0;
                const info = row.querySelector('.ferias-info');
                const porAno = diasUteisPorAno(inicio, dias, gozados);
                let uteis = 0;
                Object.keys(porAno).forEach(a => { totais[a] = (totais[a] || 0) + porAno[a]; uteis += porAno[a]; });
                if (info) {
                    if (inicio && dias > 0) {
                        const p = inicio.split('-');
                        const fim = new Date(+p[0], +p[1] - 1, +p[2] + dias - 1);
                        info.innerHTML = 'Até ' + fim.toLocaleDateString('pt-PT') + '<br><b>' + uteis + ' dia(s) úteis</b>';
                    } else {
                        info.innerHTML = '';
                    }
                }
            });

            // Pontes da empresa contam como dias de férias marcados
            PONTES.forEach(pd => {
                const a = +pd.substring(0, 4);
                totais[a] = (totais[a] || 0) + 1;
                if (pd <= HOJE && a === ANO_ATUAL) gozados.n++;
            });

            const direito = parseInt(document.getElementById('dias_ferias').value) || 0;
            const marcados = totais[ANO_ATUAL] || 0;
            const faltam = direito - marcados;
            document.getElementById('rf_direito').innerText = direito;
            document.getElementById('rf_marcados').innerText = marcados;
            document.getElementById('rf_faltam').innerText = Math.abs(faltam);
            document.getElementById('rf_lbl_faltam').innerText = faltam < 0 ? 'Em excesso' : 'Faltam marcar';
            document.getElementById('rf_box_faltam').className = 'rf-box ' + (faltam < 0 ? 'excesso' : 'faltam');
            document.getElementById('rf_gozados').innerText = gozados.n;
            document.getElementById('rf_por_gozar').innerText = Math.max(0, direito - gozados.n);
            document.getElementById('rf_sub_por_gozar').innerText = (marcados - gozados.n) + ' já marcado(s)';

            const outros = Object.keys(totais).filter(a => +a !== ANO_ATUAL).sort();
            document.getElementById('rf_outros').innerText = outros.length ? 'Outros anos: ' + outros.map(a => a + ': ' + totais[a] + ' dia(s) úteis').join(' · ') : '';
        }

        // Recalcula ao mexer nas datas, nos dias ou no direito a férias
        document.getElementById('lista_ferias').addEventListener('input', atualizarResumoFerias);
        document.getElementById('dias_ferias').addEventListener('input', atualizarResumoFerias);
        atualizarResumoFerias();

        // Menu do utilizador: em ecrãs táteis (tablet/telemóvel) abre com toque
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