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


// REGRA DE OURO 2: Acesso Restrito pela Matriz
$stmt_perm = $pdo->prepare("SELECT modulo FROM permissoes WHERE grupo = :perfil AND pode_aceder = 1");
$stmt_perm->execute(['perfil' => $perfil_user]);
$meus_acessos = $stmt_perm->fetchAll(PDO::FETCH_COLUMN);

if (!$is_admin && !in_array('termos', $meus_acessos) && !in_array('termos_eletrico', $meus_acessos)) {
    header("Location: index.php?erro=sem_permissao");
    exit;
}

// REGRA DE OURO 3: Obter apenas o primeiro nome
$nome_completo = $_SESSION['nome'] ?? 'Utilizador';
$primeiro_nome = explode(' ', trim($nome_completo))[0];

function validarNIF($nif) {
    $nif = preg_replace('/[^0-9]/', '', $nif);
    if (strlen($nif) != 9) return false;
    $ignoreList = ['000000000', '111111111', '222222222', '333333333', '444444444', '555555555', '666666666', '777777777', '888888888', '999999999'];
    if (in_array($nif, $ignoreList)) return false;
    $added = $nif[0]*9 + $nif[1]*8 + $nif[2]*7 + $nif[3]*6 + $nif[4]*5 + $nif[5]*4 + $nif[6]*3 + $nif[7]*2;
    $checkDigit = 11 - ($added % 11);
    if ($checkDigit >= 10) $checkDigit = 0;
    return $checkDigit == $nif[8];
}

// NÚMERO ÚNICO PARTILHADO (visita → orçamento → obra → termo → vistoria)
// Mesmo formato da visita comercial: AA + sequência (ex: 260123), mínimo AA0100.
function gerarNumeroProjeto($pdo) {
    $ano = date('y');
    $ultima = $pdo->query("SELECT valor FROM configuracoes WHERE chave = 'ultimo_numero_projeto'")->fetchColumn();
    if ($ultima && substr($ultima, 0, 2) === $ano) {
        $n = (string)((int)$ultima + 1);
        if ((int)$n < (int)($ano . '0100')) $n = $ano . '0100';
    } else {
        $n = $ano . '0100';
    }
    $pdo->prepare("INSERT INTO configuracoes (chave, valor) VALUES ('ultimo_numero_projeto', ?) ON DUPLICATE KEY UPDATE valor = ?")->execute([$n, $n]);
    return $n;
}

// Auto-migração: a vistoria guarda o número da obra (coluna 'referencia')
try {
    if (!$pdo->query("SHOW COLUMNS FROM vistorias_instalacao LIKE 'referencia'")->fetch()) {
        $pdo->exec("ALTER TABLE vistorias_instalacao ADD COLUMN referencia VARCHAR(20) NULL AFTER id");
        // Preencher as vistorias antigas com o número da respetiva obra
        $pdo->exec("UPDATE vistorias_instalacao vi JOIN obras o ON vi.id_obra = o.id
                    SET vi.referencia = o.referencia_obra
                    WHERE (vi.referencia IS NULL OR vi.referencia = '') AND o.referencia_obra IS NOT NULL AND o.referencia_obra <> ''");
    }
} catch (Exception $e) { /* não bloqueia a página */ }

// Listas uniformizadas para os campos do formulário
$tipos_cabo = ['H07V', 'FVV', 'XV', 'RZ-1', 'SZ1', 'XAV'];
$seccoes_padrao = ['1.5', '2.5', '4', '6', '10', '16', '25', '35', '50', '70', '95', '120', '150', '185', '240'];
$amperagens_normalizadas = ['6', '10', '16', '20', '25', '32', '40', '50', '63', '80', '100', '125', '160', '200', '250'];
$nomes_piso_opcoes = ['Piso Cave', 'Garagem', 'Rés do Chão', 'Piso 0', 'Piso 1', 'Piso 2', 'Piso 3', 'Exterior', 'Piscina'];

// Deteção do Modo: Criar vs Editar
$id_vistoria = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$modo_editar = !empty($id_vistoria);

$erro_msg = '';
$msg_style = '';
$vistoria_db = [];
$pisos_db = [];
$alteracoes_db = [];
$observacoes_db = [];

// Buscar Listas Base
$clientes = $pdo->query("SELECT id, nome, nif FROM clientes WHERE ativo = 1 ORDER BY nome ASC")->fetchAll(PDO::FETCH_ASSOC);
$obras = $pdo->query("SELECT id, id_cliente, nome, morada, referencia_obra FROM obras WHERE estado != 'Concluída' ORDER BY nome ASC")->fetchAll(PDO::FETCH_ASSOC);

if ($modo_editar) {
    $stmt = $pdo->prepare("SELECT * FROM vistorias_instalacao WHERE id = ?");
    $stmt->execute([$id_vistoria]);
    $vistoria_db = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$vistoria_db) {
        header("Location: termos_eletrico.php");
        exit;
    }

    $stmt_p = $pdo->prepare("SELECT * FROM vistorias_instalacao_pisos WHERE id_vistoria = ? ORDER BY numero_piso ASC, id ASC");
    $stmt_p->execute([$id_vistoria]);
    $pisos_db = $stmt_p->fetchAll(PDO::FETCH_ASSOC);

    $stmt_alt = $pdo->prepare("SELECT * FROM vistorias_instalacao_alteracoes WHERE id_vistoria = ? ORDER BY id ASC");
    $stmt_alt->execute([$id_vistoria]);
    $alteracoes_db = $stmt_alt->fetchAll(PDO::FETCH_ASSOC);

    $stmt_obs = $pdo->prepare("SELECT * FROM vistorias_instalacao_observacoes WHERE id_vistoria = ? ORDER BY id ASC");
    $stmt_obs->execute([$id_vistoria]);
    $observacoes_db = $stmt_obs->fetchAll(PDO::FETCH_ASSOC);

    // Número partilhado: o da vistoria; se faltar, o da obra
    $referencia_atual = $vistoria_db['referencia'] ?? '';
    if ($referencia_atual === '' && !empty($vistoria_db['id_obra'])) {
        $st_ro = $pdo->prepare("SELECT referencia_obra FROM obras WHERE id = ?");
        $st_ro->execute([$vistoria_db['id_obra']]);
        $referencia_atual = (string)$st_ro->fetchColumn();
    }
    $titulo_pagina = "Vistoria de Instalação" . ($referencia_atual !== '' ? " Nº $referencia_atual" : " #$id_vistoria");

    // Orçamentos já criados a partir desta vistoria
    $orcamentos_ligados = [];
    try {
        $st_orc = $pdo->prepare("SELECT id, numero_orcamento, estado FROM orcamentos WHERE id_vistoria = ? ORDER BY id DESC");
        $st_orc->execute([$id_vistoria]);
        $orcamentos_ligados = $st_orc->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) { /* coluna criada ao abrir o orcamento_form.php */ }
} else {
    $orcamentos_ligados = [];
    $referencia_atual = '';
    $titulo_pagina = "Nova Vistoria de Instalação";
}

// --- PROCESSAR GRAVAÇÃO (POST) ---
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    try {
        $pdo->beginTransaction();

        $id_cliente = $_POST['id_cliente'];
        if ($id_cliente === 'novo') {
            $nif = trim($_POST['novo_cliente_nif'] ?? '');
            if (!empty($nif) && !validarNIF($nif)) throw new Exception("O NIF introduzido é inválido.");
            $sql_cli = "INSERT INTO clientes (nome, nif, telefone, email, morada, codigo_postal, cidade, ativo) VALUES (?, ?, ?, ?, ?, ?, ?, 1)";
            $pdo->prepare($sql_cli)->execute([ $_POST['novo_cliente_nome'], !empty($nif) ? $nif : null, $_POST['novo_cliente_telefone'] ?: null, $_POST['novo_cliente_email'] ?: null, $_POST['novo_cliente_morada'] ?: null, $_POST['novo_cliente_cp'] ?: null, $_POST['novo_cliente_cidade'] ?: null ]);
            $id_cliente = $pdo->lastInsertId();
            registrarLog($pdo, "Criou Novo Cliente via Vistoria de Instalação (ID: $id_cliente)", "Clientes");
        }

        $id_obra = $_POST['id_obra'];
        if ($id_obra === 'nova' || $_POST['id_cliente'] === 'novo') {
            if (trim($_POST['nova_obra_nome'] ?? '') === '') throw new Exception("Indique o nome da nova obra.");
            // A nova obra recebe logo o número único partilhado
            $referencia_obra = gerarNumeroProjeto($pdo);
            $sql_obra = "INSERT INTO obras (id_cliente, nome, morada, data_inicio, estado, referencia_obra) VALUES (?, ?, ?, ?, 'Em Preparação', ?)";
            $pdo->prepare($sql_obra)->execute([ $id_cliente, $_POST['nova_obra_nome'], ($_POST['nova_obra_morada'] ?? '') ?: null, date('Y-m-d'), $referencia_obra ]);
            $id_obra = $pdo->lastInsertId();
            registrarLog($pdo, "Criou Nova Obra Nº $referencia_obra via Vistoria de Instalação", "Obras");
        } else {
            // Obra existente: usa o número dela; se ainda não tiver, atribui um agora
            $st_ro = $pdo->prepare("SELECT referencia_obra FROM obras WHERE id = ?");
            $st_ro->execute([$id_obra]);
            $referencia_obra = trim((string)$st_ro->fetchColumn());
            if ($referencia_obra === '') {
                $referencia_obra = gerarNumeroProjeto($pdo);
                $pdo->prepare("UPDATE obras SET referencia_obra = ? WHERE id = ?")->execute([$referencia_obra, $id_obra]);
                registrarLog($pdo, "Atribuiu Nº $referencia_obra à obra (ID: $id_obra) via Vistoria de Instalação", "Obras");
            }
        }

        $caixa_contador = $_POST['caixa_contador'] ?? 'Não';
        $caixa_contador_local = ($caixa_contador === 'Sim') ? ($_POST['caixa_contador_local'] ?? 'Dentro de Casa') : null;

        $caixa_medicao_terra = $_POST['caixa_medicao_terra'] ?? 'Não';

        $cabo_terra_tipo = in_array($_POST['cabo_terra_tipo'] ?? '', $tipos_cabo, true) ? $_POST['cabo_terra_tipo'] : null;
        $cabo_entrada_tipo = in_array($_POST['cabo_entrada_tipo'] ?? '', $tipos_cabo, true) ? $_POST['cabo_entrada_tipo'] : null;

        $numero_pisos = max(1, (int)($_POST['numero_pisos'] ?? 1));

        $campos_gerais = [
            $id_obra, $id_cliente, $_POST['data_vistoria'],
            $_POST['tem_portinhola'],
            $caixa_contador, $caixa_contador_local,
            $cabo_terra_tipo, $_POST['cabo_terra_seccao'],
            $caixa_medicao_terra,
            $cabo_entrada_tipo, $_POST['cabo_entrada_seccao'], $_POST['cabo_entrada_fases'],
            $numero_pisos
        ];

        $id_post = filter_input(INPUT_POST, 'id_vistoria_post', FILTER_VALIDATE_INT);

        if ($id_post) {
            // UPDATE
            $sql = "UPDATE vistorias_instalacao SET
                    id_obra=?, id_cliente=?, data_vistoria=?,
                    tem_portinhola=?,
                    caixa_contador=?, caixa_contador_local=?,
                    cabo_terra_tipo=?, cabo_terra_seccao=?,
                    caixa_medicao_terra=?,
                    cabo_entrada_tipo=?, cabo_entrada_seccao=?, cabo_entrada_fases=?,
                    numero_pisos=?
                    WHERE id=?";
            $pdo->prepare($sql)->execute(array_merge($campos_gerais, [$id_post]));

            // Limpar pisos antigos para substituir pelos novos do formulário
            $pdo->prepare("DELETE FROM vistorias_instalacao_pisos WHERE id_vistoria = ?")->execute([$id_post]);
            $id_vistoria_final = $id_post;

            registrarLog($pdo, "Editou Vistoria de Instalação Nº $referencia_obra (ID: $id_post)", "Termos Elétricos");
        } else {
            // INSERT
            $sql = "INSERT INTO vistorias_instalacao (
                    id_obra, id_cliente, data_vistoria,
                    tem_portinhola,
                    caixa_contador, caixa_contador_local,
                    cabo_terra_tipo, cabo_terra_seccao,
                    caixa_medicao_terra,
                    cabo_entrada_tipo, cabo_entrada_seccao, cabo_entrada_fases,
                    numero_pisos
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            $pdo->prepare($sql)->execute($campos_gerais);
            $id_vistoria_final = $pdo->lastInsertId();

            registrarLog($pdo, "Criou Nova Vistoria de Instalação Nº $referencia_obra (ID: $id_vistoria_final)", "Termos Elétricos");
        }

        // Guardar o número partilhado na vistoria
        $pdo->prepare("UPDATE vistorias_instalacao SET referencia = ? WHERE id = ?")->execute([$referencia_obra, $id_vistoria_final]);

        // GRAVAR OS VÁRIOS PISOS
        if (!empty($_POST['pisos']) && is_array($_POST['pisos'])) {
            $sql_p = "INSERT INTO vistorias_instalacao_pisos (
                        id_vistoria, numero_piso, nome_piso, quadro_eletrico,
                        tipo_corte_entrada, amperagem_corte, protecao_diferencial,
                        cabo_entrada_tipo, cabo_entrada_seccao, cabo_entrada_fases,
                        casas_banho, casas_banho_diferencial_30ma,
                        iluminacao_vol0, tomada_equipamento_vol0
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            $stmt_p = $pdo->prepare($sql_p);
            $numero_piso = 1;
            foreach ($_POST['pisos'] as $p) {
                $nome_piso = in_array($p['nome_piso'] ?? '', $nomes_piso_opcoes, true) ? $p['nome_piso'] : null;
                $quadro_eletrico = (($p['quadro_eletrico'] ?? 'Sim') === 'Não') ? 'Não' : 'Sim';
                $tipo_corte_entrada = (($p['tipo_corte_entrada'] ?? 'Interruptor') === 'Diferencial') ? 'Diferencial' : 'Interruptor';
                $amperagem_corte = in_array($p['amperagem_corte'] ?? '', $amperagens_normalizadas, true) ? $p['amperagem_corte'] : null;
                $protecao_diferencial = (($p['protecao_diferencial'] ?? '30mA') === '300mA') ? '300mA' : '30mA';
                $cabo_entrada_tipo_p = in_array($p['cabo_entrada_tipo'] ?? '', $tipos_cabo, true) ? $p['cabo_entrada_tipo'] : null;
                $cabo_entrada_seccao_p = in_array($p['cabo_entrada_seccao'] ?? '', $seccoes_padrao, true) ? $p['cabo_entrada_seccao'] : null;
                $cabo_entrada_fases_p = (($p['cabo_entrada_fases'] ?? 'Monofásico') === 'Trifásico') ? 'Trifásico' : 'Monofásico';

                // Casas de banho: 0 a 5. A iluminação no Volume 0 nunca pode exceder o nº de casas de banho.
                $casas_banho = max(0, min(5, (int)($p['casas_banho'] ?? 0)));
                $casas_banho_diferencial_30ma = ($casas_banho > 1) ? ((($p['casas_banho_diferencial_30ma'] ?? 'Não') === 'Sim') ? 'Sim' : 'Não') : null;
                $iluminacao_vol0 = max(0, min($casas_banho, (int)($p['iluminacao_vol0'] ?? 0)));
                $tomada_equipamento_vol0 = max(0, min(10, (int)($p['tomada_equipamento_vol0'] ?? 0)));

                $stmt_p->execute([
                    $id_vistoria_final, $numero_piso, $nome_piso, $quadro_eletrico,
                    $tipo_corte_entrada, $amperagem_corte, $protecao_diferencial,
                    $cabo_entrada_tipo_p, $cabo_entrada_seccao_p, $cabo_entrada_fases_p,
                    $casas_banho, $casas_banho_diferencial_30ma,
                    $iluminacao_vol0, $tomada_equipamento_vol0
                ]);
                $numero_piso++;
            }
        }

        // GRAVAR AS ALTERAÇÕES A EFETUAR (lista livre, cada linha associada a um piso ou à entrada)
        $pdo->prepare("DELETE FROM vistorias_instalacao_alteracoes WHERE id_vistoria = ?")->execute([$id_vistoria_final]);
        if (!empty($_POST['alteracoes']) && is_array($_POST['alteracoes'])) {
            $sql_alt = "INSERT INTO vistorias_instalacao_alteracoes (id_vistoria, referencia_piso, descricao) VALUES (?, ?, ?)";
            $stmt_alt = $pdo->prepare($sql_alt);
            foreach ($_POST['alteracoes'] as $alt) {
                $descricao_alt = trim($alt['descricao'] ?? '');
                if ($descricao_alt === '') continue; // ignora linhas em branco

                $ref_piso_alt = trim($alt['referencia_piso'] ?? '');
                if ($ref_piso_alt === 'entrada') {
                    // mantém 'entrada' tal como está
                } else {
                    $ref_piso_num = (int)$ref_piso_alt;
                    $ref_piso_alt = ($ref_piso_num >= 1 && $ref_piso_num <= $numero_pisos) ? (string)$ref_piso_num : null;
                }
                $stmt_alt->execute([$id_vistoria_final, $ref_piso_alt, $descricao_alt]);
            }
        }

        // GRAVAR AS OBSERVAÇÕES (lista livre de linhas de texto)
        $pdo->prepare("DELETE FROM vistorias_instalacao_observacoes WHERE id_vistoria = ?")->execute([$id_vistoria_final]);
        if (!empty($_POST['observacoes']) && is_array($_POST['observacoes'])) {
            $sql_obs = "INSERT INTO vistorias_instalacao_observacoes (id_vistoria, descricao) VALUES (?, ?)";
            $stmt_obs = $pdo->prepare($sql_obs);
            foreach ($_POST['observacoes'] as $obs) {
                $descricao_obs = trim($obs['descricao'] ?? '');
                if ($descricao_obs === '') continue; // ignora linhas em branco
                $stmt_obs->execute([$id_vistoria_final, $descricao_obs]);
            }
        }

        $pdo->commit();
        header("Location: termos_eletrico.php?sucesso=1");
        exit;
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $erro_msg = "Erro ao guardar: " . $e->getMessage();
        $msg_style = "alert-erro";
        // Preservar dados preenchidos em caso de erro
        $vistoria_db = $_POST;
        $pisos_db = $_POST['pisos'] ?? [];
        $alteracoes_db = $_POST['alteracoes'] ?? [];
        $observacoes_db = $_POST['observacoes'] ?? [];
    }
}

// Fallbacks para pré-preenchimento
$val_id_cliente = $_POST['id_cliente'] ?? $vistoria_db['id_cliente'] ?? '';
$val_id_obra = $_POST['id_obra'] ?? $vistoria_db['id_obra'] ?? '';
$val_data = $vistoria_db['data_vistoria'] ?? date('Y-m-d');
$val_portinhola = $vistoria_db['tem_portinhola'] ?? 'Não';
$val_caixa_contador = $vistoria_db['caixa_contador'] ?? 'Não';
$val_caixa_contador_local = $vistoria_db['caixa_contador_local'] ?? 'Dentro de Casa';
$val_terra_tipo = $vistoria_db['cabo_terra_tipo'] ?? '';
$val_terra_seccao = $vistoria_db['cabo_terra_seccao'] ?? '';
$val_caixa_medicao_terra = $vistoria_db['caixa_medicao_terra'] ?? 'Não';
$val_entrada_tipo = $vistoria_db['cabo_entrada_tipo'] ?? '';
$val_entrada_seccao = $vistoria_db['cabo_entrada_seccao'] ?? '';
$val_entrada_fases = $vistoria_db['cabo_entrada_fases'] ?? 'Monofásico';
$val_numero_pisos = $vistoria_db['numero_pisos'] ?? (!empty($pisos_db) ? count($pisos_db) : 1);
if ((int)$val_numero_pisos < 1) $val_numero_pisos = 1;

// Rótulos "Entrada" / "Piso N - Nome" para o seletor de Piso da secção de Alterações a Efetuar
$opcoes_piso_alteracoes = ['entrada' => 'Entrada'];
for ($i = 0; $i < (int)$val_numero_pisos; $i++) {
    $nome_p_opt = $pisos_db[$i]['nome_piso'] ?? '';
    $opcoes_piso_alteracoes[(string)($i + 1)] = 'Piso ' . ($i + 1) . (!empty($nome_p_opt) ? ' - ' . $nome_p_opt : '');
}
?>
<!DOCTYPE html>
<html lang="pt-PT">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($titulo_pagina) ?> - Gestão MAB</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { font-family: system-ui, sans-serif; background: #f7f9fc; margin: 0; color: #333; font-size: 13px; padding-bottom: 100px; }

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
        .user-avatar { background: #e74c3c; color: #fff; width: 28px; height: 28px; border-radius: 50%; display: flex; justify-content: center; align-items: center; font-size: 13px; }
        .dropdown-content { display: none; position: absolute; right: 0; top: 100%; background: #fff; min-width: 180px; box-shadow: 0 12px 30px rgba(0,0,0,0.1); border-radius: 10px; border: 1px solid #e8ecf1; overflow: hidden; margin-top: 6px; z-index: 200; }
        .user-menu:hover .dropdown-content, .user-menu.aberto .dropdown-content { display: block; }
        .dropdown-content a { color: #576574; padding: 12px 20px; text-decoration: none; display: block; font-size: 14px; }
        .dropdown-content a:hover { background: #f4f6f8; color: #2c3e50; }

        .container { max-width: 1200px; margin: 30px auto; padding: 0 20px; }
        .page-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px; flex-wrap: wrap; gap: 15px; }
        .page-title { margin: 0; color: #2c3e50; font-size: 24px; font-weight: 800; display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
        .ref-badge { display: inline-flex; align-items: center; gap: 6px; background: #fef5e7; color: #d35400; border: 1px solid #fae5d3; border-radius: 8px; padding: 5px 12px; font-size: 14px; font-weight: 800; letter-spacing: 0.5px; }
        .ref-badge.pendente { background: #f4f6f8; color: #7f8c8d; border-color: #e1e5ea; font-weight: 600; font-size: 12px; letter-spacing: 0; }
        .ref-nota { font-size: 12px; color: #7f8c8d; margin: -15px 0 20px; }

        .card { background: #fff; padding: 25px; border-radius: 12px; border: 1px solid #e1e5ea; margin-bottom: 25px; box-shadow: 0 4px 15px rgba(0,0,0,0.02); position: relative; }
        .card h2 { font-size: 15px; color: #e67e22; border-bottom: 2px solid #fef5e7; padding-bottom: 10px; margin-top: 0; margin-bottom: 20px; text-transform: uppercase; display: flex; align-items: center; gap: 10px; }

        .form-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; }
        .form-group { display: flex; flex-direction: column; gap: 5px; }
        label { font-weight: 700; font-size: 11px; color: #576574; text-transform: uppercase; }
        .form-grid > * { min-width: 0; }
        textarea { padding: 10px; border: 1px solid #dcdde1; border-radius: 8px; font-size: 13px; outline: none; background: #fff; font-family: inherit; resize: vertical; width: 100%; }
        textarea:focus { border-color: #e67e22; box-shadow: 0 0 0 3px rgba(230, 126, 34, 0.1); }
        input, select { width: 100%; padding: 10px; border: 1px solid #dcdde1; border-radius: 8px; font-size: 13px; outline: none; background: #fff; font-family: inherit;}
        input:focus, select:focus { border-color: #e67e22; box-shadow: 0 0 0 3px rgba(230, 126, 34, 0.1); }

        .box-fast { background: #fef5e7; padding: 20px; border-radius: 8px; border: 1px dashed #e67e22; margin-top: 15px; }
        .box-fast h4 { margin: 0 0 15px 0; color: #e67e22; font-size: 13px; display: flex; align-items: center; gap: 8px; }

        .floating-action { position: fixed; bottom: 25px; right: 25px; z-index: 100; }
        .btn-save { background: #e67e22; color: #fff; padding: 15px 45px; border-radius: 10px; border: none; font-weight: 700; cursor: pointer; box-shadow: 0 5px 15px rgba(230,126,34,0.3); transition: 0.2s; font-size: 14px; text-transform: uppercase; font-family: inherit;}
        .btn-save:hover { filter: brightness(0.9); transform: translateY(-2px); }

        .alert { padding: 15px; border-radius: 8px; margin-bottom: 20px; font-weight: 600; text-align: center;}
        .alert-erro { color: #e74c3c; background: #fdeaea; border: 1px solid #f9cccc; }
        .nif-invalido { border-color: #e74c3c !important; background: #fdeaea !important; }
        .btn-remover { position: absolute; top: 20px; right: 25px; background: none; border: none; color: #e74c3c; cursor: pointer; font-weight: bold; font-size: 12px; }

        .piso-item h2 { color: #2980b9; border-bottom-color: #eaf4fc; }

        .alteracao-linha { display: flex; gap: 12px; align-items: flex-start; padding: 15px 0; border-bottom: 1px solid #f1f2f6; }
        .alteracao-linha:first-child { padding-top: 0; }
        .alteracao-linha:last-child { border-bottom: none; padding-bottom: 0; }
        .alteracao-linha .form-group { margin: 0; }
        .alteracao-linha .form-group.piso { flex: 0 0 220px; }
        .alteracao-linha .form-group.descricao { flex: 1; }
        .btn-remover-linha { background: #fdeaea; color: #e74c3c; border: none; width: 38px; height: 38px; border-radius: 8px; cursor: pointer; flex-shrink: 0; margin-top: 22px; font-size: 14px; }
        .btn-remover-linha:hover { background: #f9cccc; }
        .btn-add-linha { background: #fef5e7; color: #e67e22; border: 1px dashed #e67e22; padding: 10px 18px; border-radius: 8px; cursor: pointer; font-weight: 700; font-size: 12px; margin-top: 15px; font-family: inherit; }
        .btn-add-linha:hover { background: #fde8cc; }
        .aviso-vazio { color: #95a5a6; font-size: 12px; padding: 10px 0; }

        .btn-imprimir { background: #fff; color: #2c3e50; border: 1px solid #dcdde1; padding: 12px 22px; border-radius: 10px; font-weight: 700; font-size: 13px; text-decoration: none; display: inline-flex; align-items: center; gap: 8px; transition: 0.2s; }
        .btn-imprimir:hover { background: #f1f2f6; }
        .header-acoes { display: flex; gap: 10px; flex-wrap: wrap; }
        .btn-orcamento { color: #b9770e; border-color: #fae5d3; background: #fef9f3; }
        .btn-orcamento:hover { background: #fdebd0; }
        .orc-ligados { display: flex; align-items: center; flex-wrap: wrap; gap: 8px; margin: -10px 0 18px; font-size: 12px; color: #576574; font-weight: 600; }
        .orc-chip { display: inline-flex; align-items: center; gap: 6px; background: #fff; border: 1px solid #fae5d3; color: #d35400; padding: 5px 10px; border-radius: 20px; text-decoration: none; font-weight: 700; }
        .orc-chip small { color: #95a5a6; font-weight: 600; }
        .orc-chip:hover { background: #fef5e7; }

        .observacao-linha { display: flex; gap: 12px; align-items: flex-start; padding: 15px 0; border-bottom: 1px solid #f1f2f6; }
        .observacao-linha:first-child { padding-top: 0; }
        .observacao-linha:last-child { border-bottom: none; padding-bottom: 0; }
        .observacao-linha .form-group { margin: 0; flex: 1; }

        @media (max-width: 1024px) {
            .topbar { padding: 10px 24px; }
            .form-grid .span-4 { grid-column: 1 / -1 !important; }
        }
        @media (max-width: 768px) {
            .topbar { padding: 8px 15px; }
            .topbar-left .logo { height: 30px; }
            .btn-voltar { width: 38px; height: 38px; }
            .container { margin: 20px auto; padding: 0 15px; }
            .page-title { font-size: 20px; }
            .card { padding: 20px 16px; }
            .form-grid { grid-template-columns: 1fr 1fr; }
            .form-grid > [style*="span"] { grid-column: 1 / -1 !important; }
            .page-header { flex-direction: column; align-items: flex-start; gap: 15px;}
            .alteracao-linha { flex-direction: column; }
            .alteracao-linha .form-group.piso, .alteracao-linha .form-group.descricao { flex: 1 1 100%; width: 100%; }
            .btn-remover-linha { margin-top: 0; align-self: flex-end; }
            .floating-action { left: 15px; right: 15px; bottom: 15px; }
            .btn-save { width: 100%; padding: 15px 20px; }
        }
        @media (max-width: 560px) {
            .form-grid { grid-template-columns: 1fr; }
            .user-nome { display: none; }
            .user-btn { padding: 5px 10px 5px 5px; }
            .btn-imprimir { width: 100%; justify-content: center; }
            .header-acoes { width: 100%; flex-direction: column; }
        }
    </style>
</head>
<body>

    <header class="topbar">
        <div class="topbar-left"><a href="index.php" title="Página inicial"><img src="mab.png" alt="MAB" class="logo"></a></div>
        <div class="topbar-right">
            <a href="termos_eletrico.php" class="btn-voltar" title="Voltar" aria-label="Voltar"><i class="fa-solid fa-arrow-left"></i></a>
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
        <?php if ($erro_msg): ?>
            <div class="alert <?= $msg_style ?>"><i class="fa-solid fa-circle-exclamation"></i> <?= htmlspecialchars($erro_msg) ?></div>
        <?php endif; ?>

        <div class="page-header">
            <h1 class="page-title">
                <i class="fa-solid fa-plug-circle-check" style="color:#e67e22"></i>
                <?= $modo_editar ? 'Vistoria de Instalação' : 'Nova Vistoria de Instalação' ?>
                <?php if ($referencia_atual !== ''): ?>
                    <span class="ref-badge" id="ref_badge"><i class="fa-solid fa-hashtag"></i> <?= htmlspecialchars($referencia_atual) ?></span>
                <?php else: ?>
                    <span class="ref-badge pendente" id="ref_badge">Nº atribuído ao gravar</span>
                <?php endif; ?>
            </h1>
            <?php if ($modo_editar): ?>
                <div class="header-acoes">
                    <?php if ($is_admin || in_array('orcamentos', $meus_acessos)): ?>
                        <a href="orcamento_form.php?import_vistoria=<?= (int)$id_vistoria ?>" class="btn-imprimir btn-orcamento"><i class="fa-solid fa-file-invoice-dollar"></i> Criar Orçamento</a>
                    <?php endif; ?>
                    <a href="vistoria_instalacao_pdf.php?id=<?= $id_vistoria ?>" target="_blank" class="btn-imprimir"><i class="fa-solid fa-print"></i> Imprimir Relatório</a>
                </div>
            <?php endif; ?>
        </div>

        <?php if (!empty($orcamentos_ligados)): ?>
            <div class="orc-ligados">
                <span><i class="fa-solid fa-file-invoice-dollar"></i> Orçamento<?= count($orcamentos_ligados) > 1 ? 's' : '' ?> desta vistoria:</span>
                <?php foreach ($orcamentos_ligados as $ol): ?>
                    <a href="orcamento_form.php?id=<?= (int)$ol['id'] ?>" class="orc-chip">Nº <?= htmlspecialchars($ol['numero_orcamento']) ?> <small><?= htmlspecialchars($ol['estado'] ?? '') ?></small></a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        <p class="ref-nota"><i class="fa-solid fa-link"></i> O número é o da obra — o mesmo da visita, do orçamento e dos termos.</p>

        <form method="POST" id="formVistoriaInstalacao" onsubmit="return validarSubmissao()">

            <?php if ($modo_editar): ?>
                <input type="hidden" name="id_vistoria_post" value="<?= $id_vistoria ?>">
            <?php endif; ?>

            <div class="card">
                <h2><i class="fa-solid fa-user-tie"></i> 1. Cliente e Obra</h2>
                <div class="form-grid">
                    <div class="form-group">
                        <label>Data da Vistoria</label>
                        <input type="date" name="data_vistoria" value="<?= htmlspecialchars($val_data) ?>" required>
                    </div>

                    <div class="form-group" style="grid-column: span 2;">
                        <label>Cliente</label>
                        <select name="id_cliente" id="sel_cli" onchange="atualizarInterfaceCliente()" required>
                            <option value="">-- Selecione --</option>
                            <option value="novo" style="font-weight:bold; color:#e67e22;">+ CRIAR NOVO CLIENTE</option>
                            <?php foreach($clientes as $c): ?>
                                <option value="<?= $c['id'] ?>" <?= ($val_id_cliente == $c['id']) ? 'selected' : '' ?>><?= htmlspecialchars($c['nome']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group span-4" style="grid-column: span 4;">
                        <label>Obra</label>
                        <select name="id_obra" id="sel_obra" onchange="aoSelecionarObra()" required>
                            <option value="">-- Escolha o Cliente --</option>
                        </select>
                    </div>
                </div>

                <div id="box_novo_cliente" class="box-fast" style="display:none;">
                    <h4><i class="fa-solid fa-user-plus"></i> Ficha do Novo Cliente</h4>
                    <div class="form-grid">
                        <div class="form-group" style="grid-column: span 2;"><label>Nome *</label><input type="text" name="novo_cliente_nome" id="novo_cliente_nome"></div>
                        <div class="form-group"><label>NIF</label><input type="text" name="novo_cliente_nif" id="nif_input" maxlength="9" onblur="verificarNIF(this)"></div>
                        <div class="form-group"><label>Telefone</label><input type="text" name="novo_cliente_telefone"></div>
                        <div class="form-group"><label>Email</label><input type="email" name="novo_cliente_email"></div>
                        <div class="form-group" style="grid-column: span 2;"><label>Morada</label><input type="text" name="novo_cliente_morada"></div>
                        <div class="form-group"><label>CP</label><input type="text" name="novo_cliente_cp" placeholder="0000-000"></div>
                        <div class="form-group"><label>Cidade (Localidade)</label><input type="text" name="novo_cliente_cidade"></div>
                    </div>
                </div>

                <div id="box_nova_obra" class="box-fast" style="display:none;">
                    <h4><i class="fa-solid fa-building-circle-check"></i> Dados da Nova Obra</h4>
                    <div class="form-grid">
                        <div class="form-group" style="grid-column: span 2;"><label>Nome da Obra *</label><input type="text" name="nova_obra_nome" id="nova_obra_nome"></div>
                        <div class="form-group" style="grid-column: span 2;"><label>Morada Física da Obra</label><input type="text" name="nova_obra_morada"></div>
                    </div>
                </div>
            </div>

            <div class="card">
                <h2><i class="fa-solid fa-building"></i> 2. Edifício</h2>
                <div class="form-grid">
                    <div class="form-group">
                        <label>Tem Portinhola?</label>
                        <select name="tem_portinhola">
                            <option value="Sim" <?= $val_portinhola=='Sim'?'selected':'' ?>>Sim</option>
                            <option value="Não" <?= $val_portinhola=='Não'?'selected':'' ?>>Não</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Caixa de Contador?</label>
                        <select name="caixa_contador" id="sel_caixa_contador" onchange="toggleCaixaContador()">
                            <option value="Sim" <?= $val_caixa_contador=='Sim'?'selected':'' ?>>Sim</option>
                            <option value="Não" <?= $val_caixa_contador=='Não'?'selected':'' ?>>Não</option>
                        </select>
                    </div>
                    <div class="form-group" id="bloco_caixa_contador_local" style="display:none;">
                        <label>Caixa de Contador - Local</label>
                        <select name="caixa_contador_local">
                            <option value="Dentro de Casa" <?= $val_caixa_contador_local=='Dentro de Casa'?'selected':'' ?>>Dentro de Casa</option>
                            <option value="Fora de Casa" <?= $val_caixa_contador_local=='Fora de Casa'?'selected':'' ?>>Fora de Casa</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Cabo de Entrada - Tipo</label>
                        <select name="cabo_entrada_tipo">
                            <option value="">-- Selecione --</option>
                            <?php foreach($tipos_cabo as $tipo): ?>
                                <option value="<?= htmlspecialchars($tipo) ?>" <?= $val_entrada_tipo==$tipo?'selected':'' ?>><?= htmlspecialchars($tipo) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Cabo de Entrada - Secção</label>
                        <select name="cabo_entrada_seccao">
                            <option value="">-- Selecione --</option>
                            <?php foreach($seccoes_padrao as $sec): ?>
                                <option value="<?= $sec ?>" <?= $val_entrada_seccao==$sec?'selected':'' ?>><?= $sec ?> mm²</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Fases (Cabo de Entrada)</label>
                        <select name="cabo_entrada_fases">
                            <option value="Monofásico" <?= $val_entrada_fases=='Monofásico'?'selected':'' ?>>Monofásico</option>
                            <option value="Trifásico" <?= $val_entrada_fases=='Trifásico'?'selected':'' ?>>Trifásico</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Cabo de Terra - Tipo</label>
                        <select name="cabo_terra_tipo">
                            <option value="">-- Selecione --</option>
                            <?php foreach($tipos_cabo as $tipo): ?>
                                <option value="<?= htmlspecialchars($tipo) ?>" <?= $val_terra_tipo==$tipo?'selected':'' ?>><?= htmlspecialchars($tipo) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Cabo de Terra - Secção</label>
                        <select name="cabo_terra_seccao">
                            <option value="">-- Selecione --</option>
                            <?php foreach($seccoes_padrao as $sec): ?>
                                <option value="<?= $sec ?>" <?= $val_terra_seccao==$sec?'selected':'' ?>><?= $sec ?> mm²</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Caixa de Medição de Terra?</label>
                        <select name="caixa_medicao_terra">
                            <option value="Sim" <?= $val_caixa_medicao_terra=='Sim'?'selected':'' ?>>Sim</option>
                            <option value="Não" <?= $val_caixa_medicao_terra=='Não'?'selected':'' ?>>Não</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Número de Pisos</label>
                        <input type="number" min="1" id="numero_pisos" name="numero_pisos" value="<?= (int)$val_numero_pisos ?>" oninput="ajustarPisos()">
                    </div>
                </div>
            </div>

            <div id="pisos_container">
                <?php for ($i = 0; $i < (int)$val_numero_pisos; $i++): $p = $pisos_db[$i] ?? []; $cb_i = max(0, min(5, (int)($p['casas_banho'] ?? 0))); ?>
                <div class="card piso-item" data-index="<?= $i ?>">
                    <h2><i class="fa-solid fa-bolt-lightning"></i> 3. Piso <?= $i + 1 ?></h2>
                    <div class="form-grid">
                        <div class="form-group">
                            <label>Nome do Piso</label>
                            <select name="pisos[<?= $i ?>][nome_piso]" onchange="atualizarOpcoesPisoAlteracoes()">
                                <option value="">-- Selecione --</option>
                                <?php foreach($nomes_piso_opcoes as $nome_op): ?>
                                    <option value="<?= htmlspecialchars($nome_op) ?>" <?= ($p['nome_piso'] ?? '')==$nome_op?'selected':'' ?>><?= htmlspecialchars($nome_op) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Quadro Elétrico?</label>
                            <select name="pisos[<?= $i ?>][quadro_eletrico]">
                                <option value="Sim" <?= ($p['quadro_eletrico'] ?? 'Sim')=='Sim'?'selected':'' ?>>Sim</option>
                                <option value="Não" <?= ($p['quadro_eletrico'] ?? '')=='Não'?'selected':'' ?>>Não</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Corte de Entrada?</label>
                            <select name="pisos[<?= $i ?>][tipo_corte_entrada]">
                                <option value="Interruptor" <?= ($p['tipo_corte_entrada'] ?? 'Interruptor')=='Interruptor'?'selected':'' ?>>Interruptor</option>
                                <option value="Diferencial" <?= ($p['tipo_corte_entrada'] ?? '')=='Diferencial'?'selected':'' ?>>Diferencial</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Amperagem do Corte</label>
                            <select name="pisos[<?= $i ?>][amperagem_corte]">
                                <option value="">-- Selecione --</option>
                                <?php foreach($amperagens_normalizadas as $amp): ?>
                                    <option value="<?= $amp ?>" <?= ($p['amperagem_corte'] ?? '')==$amp?'selected':'' ?>><?= $amp ?> A</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Proteção Diferencial</label>
                            <select name="pisos[<?= $i ?>][protecao_diferencial]">
                                <option value="30mA" <?= ($p['protecao_diferencial'] ?? '30mA')=='30mA'?'selected':'' ?>>30mA</option>
                                <option value="300mA" <?= ($p['protecao_diferencial'] ?? '')=='300mA'?'selected':'' ?>>300mA</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Cabo de Entrada - Tipo</label>
                            <select name="pisos[<?= $i ?>][cabo_entrada_tipo]">
                                <option value="">-- Selecione --</option>
                                <?php foreach($tipos_cabo as $tipo): ?>
                                    <option value="<?= htmlspecialchars($tipo) ?>" <?= ($p['cabo_entrada_tipo'] ?? '')==$tipo?'selected':'' ?>><?= htmlspecialchars($tipo) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Cabo de Entrada - Secção</label>
                            <select name="pisos[<?= $i ?>][cabo_entrada_seccao]">
                                <option value="">-- Selecione --</option>
                                <?php foreach($seccoes_padrao as $sec): ?>
                                    <option value="<?= $sec ?>" <?= ($p['cabo_entrada_seccao'] ?? '')==$sec?'selected':'' ?>><?= $sec ?> mm²</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Fases (Cabo de Entrada)</label>
                            <select name="pisos[<?= $i ?>][cabo_entrada_fases]">
                                <option value="Monofásico" <?= ($p['cabo_entrada_fases'] ?? 'Monofásico')=='Monofásico'?'selected':'' ?>>Monofásico</option>
                                <option value="Trifásico" <?= ($p['cabo_entrada_fases'] ?? '')=='Trifásico'?'selected':'' ?>>Trifásico</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Casas de Banho</label>
                            <select name="pisos[<?= $i ?>][casas_banho]" id="piso_casas_banho_<?= $i ?>" onchange="atualizarCasasBanho(<?= $i ?>)">
                                <?php for ($n = 0; $n <= 5; $n++): ?>
                                    <option value="<?= $n ?>" <?= $cb_i==$n?'selected':'' ?>><?= $n ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        <div class="form-group" id="bloco_piso_cbdif_<?= $i ?>" style="display:<?= $cb_i > 1 ? 'flex' : 'none' ?>;">
                            <label>Proteção Diferencial 30mA?</label>
                            <select name="pisos[<?= $i ?>][casas_banho_diferencial_30ma]">
                                <option value="Não" <?= ($p['casas_banho_diferencial_30ma'] ?? 'Não')=='Não'?'selected':'' ?>>Não</option>
                                <option value="Sim" <?= ($p['casas_banho_diferencial_30ma'] ?? '')=='Sim'?'selected':'' ?>>Sim</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Iluminação no Volume 0</label>
                            <select name="pisos[<?= $i ?>][iluminacao_vol0]" id="piso_iluminacao_<?= $i ?>">
                                <?php for ($n = 0; $n <= $cb_i; $n++): ?>
                                    <option value="<?= $n ?>" <?= (int)($p['iluminacao_vol0'] ?? 0)==$n?'selected':'' ?>><?= $n ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Tomada ou Equipamento no Volume 0</label>
                            <select name="pisos[<?= $i ?>][tomada_equipamento_vol0]">
                                <?php for ($n = 0; $n <= 10; $n++): ?>
                                    <option value="<?= $n ?>" <?= (int)($p['tomada_equipamento_vol0'] ?? 0)==$n?'selected':'' ?>><?= $n ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>
                    </div>
                </div>
                <?php endfor; ?>
            </div>

            <div class="card">
                <h2><i class="fa-solid fa-list-check"></i> 4. Alterações a Efetuar</h2>
                <div id="alteracoes_container">
                    <?php foreach ($alteracoes_db as $ai => $alt): ?>
                    <div class="alteracao-linha" data-index="<?= $ai ?>">
                        <div class="form-group piso">
                            <label>Piso</label>
                            <select name="alteracoes[<?= $ai ?>][referencia_piso]" class="sel_alteracao_piso">
                                <option value="">-- Selecione --</option>
                                <?php foreach ($opcoes_piso_alteracoes as $val_op => $label_op): ?>
                                    <option value="<?= $val_op ?>" <?= ((string)($alt['referencia_piso'] ?? '') === (string)$val_op) ? 'selected' : '' ?>><?= htmlspecialchars($label_op) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group descricao">
                            <label>Descrição da Alteração</label>
                            <textarea name="alteracoes[<?= $ai ?>][descricao]" rows="2"><?= htmlspecialchars($alt['descricao'] ?? '') ?></textarea>
                        </div>
                        <button type="button" class="btn-remover-linha" onclick="removerLinhaAlteracao(this)" title="Remover"><i class="fa-solid fa-trash-can"></i></button>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php if (empty($alteracoes_db)): ?>
                    <p class="aviso-vazio" id="aviso_sem_alteracoes">Ainda não há nenhuma alteração adicionada.</p>
                <?php endif; ?>
                <button type="button" class="btn-add-linha" onclick="adicionarLinhaAlteracao()"><i class="fa-solid fa-plus"></i> Adicionar Alteração</button>
            </div>

            <div class="card">
                <h2><i class="fa-solid fa-note-sticky"></i> 5. Observações</h2>
                <div id="observacoes_container">
                    <?php foreach ($observacoes_db as $oi => $obs): ?>
                    <div class="observacao-linha" data-index="<?= $oi ?>">
                        <div class="form-group">
                            <label>Observação</label>
                            <textarea name="observacoes[<?= $oi ?>][descricao]" rows="2"><?= htmlspecialchars($obs['descricao'] ?? '') ?></textarea>
                        </div>
                        <button type="button" class="btn-remover-linha" onclick="removerLinhaObservacao(this)" title="Remover"><i class="fa-solid fa-trash-can"></i></button>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php if (empty($observacoes_db)): ?>
                    <p class="aviso-vazio" id="aviso_sem_observacoes">Ainda não há nenhuma observação adicionada.</p>
                <?php endif; ?>
                <button type="button" class="btn-add-linha" onclick="adicionarLinhaObservacao()"><i class="fa-solid fa-plus"></i> Adicionar Observação</button>
            </div>

            <div class="floating-action">
                <button type="submit" class="btn-save"><i class="fa-solid fa-floppy-disk" style="margin-right:8px;"></i> GRAVAR</button>
            </div>
        </form>
    </div>

    <script>
        const obrasData = <?= json_encode($obras, JSON_UNESCAPED_UNICODE) ?>;
        const id_obra_pre_selecionada = '<?= htmlspecialchars((string)$val_id_obra, ENT_QUOTES) ?>';
        const refInicial = <?= json_encode($referencia_atual, JSON_UNESCAPED_UNICODE) ?>;
        const seccoesPadrao = <?= json_encode($seccoes_padrao, JSON_UNESCAPED_UNICODE) ?>;
        const tiposCabo = <?= json_encode($tipos_cabo, JSON_UNESCAPED_UNICODE) ?>;
        const amperagensNormalizadas = <?= json_encode($amperagens_normalizadas, JSON_UNESCAPED_UNICODE) ?>;
        const nomesPisoOpcoes = <?= json_encode($nomes_piso_opcoes, JSON_UNESCAPED_UNICODE) ?>;

        function gerarOptionsSeccao() {
            let html = '<option value="">-- Selecione --</option>';
            seccoesPadrao.forEach(sec => { html += '<option value="' + sec + '">' + sec + ' mm²</option>'; });
            return html;
        }

        function gerarOptionsTipoCabo() {
            let html = '<option value="">-- Selecione --</option>';
            tiposCabo.forEach(tipo => { html += '<option value="' + tipo + '">' + tipo + '</option>'; });
            return html;
        }

        function gerarOptionsAmperagem() {
            let html = '<option value="">-- Selecione --</option>';
            amperagensNormalizadas.forEach(amp => { html += '<option value="' + amp + '">' + amp + ' A</option>'; });
            return html;
        }

        function gerarOptionsNomePiso() {
            let html = '<option value="">-- Selecione --</option>';
            nomesPisoOpcoes.forEach(nome => { html += '<option value="' + nome + '">' + nome + '</option>'; });
            return html;
        }

        function gerarPisoHTML(index) {
            const n = index + 1;
            return '<div class="card piso-item" data-index="' + index + '">' +
                '<h2><i class="fa-solid fa-bolt-lightning"></i> 3. Piso ' + n + '</h2>' +
                '<div class="form-grid">' +
                    '<div class="form-group"><label>Nome do Piso</label><select name="pisos[' + index + '][nome_piso]" onchange="atualizarOpcoesPisoAlteracoes()">' + gerarOptionsNomePiso() + '</select></div>' +
                    '<div class="form-group"><label>Quadro Elétrico?</label><select name="pisos[' + index + '][quadro_eletrico]"><option value="Sim">Sim</option><option value="Não">Não</option></select></div>' +
                    '<div class="form-group"><label>Corte de Entrada?</label><select name="pisos[' + index + '][tipo_corte_entrada]"><option value="Interruptor">Interruptor</option><option value="Diferencial">Diferencial</option></select></div>' +
                    '<div class="form-group"><label>Amperagem do Corte</label><select name="pisos[' + index + '][amperagem_corte]">' + gerarOptionsAmperagem() + '</select></div>' +
                    '<div class="form-group"><label>Proteção Diferencial</label><select name="pisos[' + index + '][protecao_diferencial]"><option value="30mA">30mA</option><option value="300mA">300mA</option></select></div>' +
                    '<div class="form-group"><label>Cabo de Entrada - Tipo</label><select name="pisos[' + index + '][cabo_entrada_tipo]">' + gerarOptionsTipoCabo() + '</select></div>' +
                    '<div class="form-group"><label>Cabo de Entrada - Secção</label><select name="pisos[' + index + '][cabo_entrada_seccao]">' + gerarOptionsSeccao() + '</select></div>' +
                    '<div class="form-group"><label>Fases (Cabo de Entrada)</label><select name="pisos[' + index + '][cabo_entrada_fases]"><option value="Monofásico">Monofásico</option><option value="Trifásico">Trifásico</option></select></div>' +
                    '<div class="form-group"><label>Casas de Banho</label><select name="pisos[' + index + '][casas_banho]" id="piso_casas_banho_' + index + '" onchange="atualizarCasasBanho(' + index + ')"><option value="0">0</option><option value="1">1</option><option value="2">2</option><option value="3">3</option><option value="4">4</option><option value="5">5</option></select></div>' +
                    '<div class="form-group" id="bloco_piso_cbdif_' + index + '" style="display:none;"><label>Proteção Diferencial 30mA?</label><select name="pisos[' + index + '][casas_banho_diferencial_30ma]"><option value="Não">Não</option><option value="Sim">Sim</option></select></div>' +
                    '<div class="form-group"><label>Iluminação no Volume 0</label><select name="pisos[' + index + '][iluminacao_vol0]" id="piso_iluminacao_' + index + '"><option value="0">0</option></select></div>' +
                    '<div class="form-group"><label>Tomada ou Equipamento no Volume 0</label><select name="pisos[' + index + '][tomada_equipamento_vol0]"><option value="0">0</option><option value="1">1</option><option value="2">2</option><option value="3">3</option><option value="4">4</option><option value="5">5</option><option value="6">6</option><option value="7">7</option><option value="8">8</option><option value="9">9</option><option value="10">10</option></select></div>' +
                '</div>' +
            '</div>';
        }

        function ajustarPisos() {
            const desejado = Math.max(1, parseInt(document.getElementById('numero_pisos').value) || 1);
            const container = document.getElementById('pisos_container');
            let atuais = container.querySelectorAll('.piso-item');
            if (desejado > atuais.length) {
                for (let i = atuais.length; i < desejado; i++) {
                    container.insertAdjacentHTML('beforeend', gerarPisoHTML(i));
                }
            } else if (desejado < atuais.length) {
                for (let i = atuais.length - 1; i >= desejado; i--) {
                    atuais[i].remove();
                }
            }
            atualizarOpcoesPisoAlteracoes();
        }

        function gerarOptionsPiso(valorSelecionado) {
            let html = '<option value="">-- Selecione --</option>';
            const selEntrada = (String(valorSelecionado) === 'entrada') ? ' selected' : '';
            html += '<option value="entrada"' + selEntrada + '>Entrada</option>';
            document.querySelectorAll('#pisos_container .piso-item').forEach(function(card) {
                const idx = parseInt(card.dataset.index);
                const numeroPiso = idx + 1;
                const selectNome = card.querySelector('select[name="pisos[' + idx + '][nome_piso]"]');
                const nome = selectNome ? selectNome.value : '';
                const rotulo = 'Piso ' + numeroPiso + (nome ? ' - ' + nome : '');
                const sel = (String(valorSelecionado) === String(numeroPiso)) ? ' selected' : '';
                html += '<option value="' + numeroPiso + '"' + sel + '>' + rotulo + '</option>';
            });
            return html;
        }

        function atualizarOpcoesPisoAlteracoes() {
            document.querySelectorAll('.sel_alteracao_piso').forEach(function(sel) {
                const valorAtual = sel.value;
                sel.innerHTML = gerarOptionsPiso(valorAtual);
            });
        }

        let contadorAlteracoes = <?= (int)count($alteracoes_db) ?>;

        function adicionarLinhaAlteracao() {
            const idx = contadorAlteracoes++;
            const container = document.getElementById('alteracoes_container');
            const avisoVazio = document.getElementById('aviso_sem_alteracoes');
            if (avisoVazio) avisoVazio.remove();

            const div = document.createElement('div');
            div.className = 'alteracao-linha';
            div.dataset.index = idx;
            div.innerHTML =
                '<div class="form-group piso"><label>Piso</label><select name="alteracoes[' + idx + '][referencia_piso]" class="sel_alteracao_piso">' + gerarOptionsPiso() + '</select></div>' +
                '<div class="form-group descricao"><label>Descrição da Alteração</label><textarea name="alteracoes[' + idx + '][descricao]" rows="2"></textarea></div>' +
                '<button type="button" class="btn-remover-linha" onclick="removerLinhaAlteracao(this)" title="Remover"><i class="fa-solid fa-trash-can"></i></button>';
            container.appendChild(div);
        }

        let contadorObservacoes = <?= (int)count($observacoes_db) ?>;

        function adicionarLinhaObservacao() {
            const idx = contadorObservacoes++;
            const container = document.getElementById('observacoes_container');
            const avisoVazio = document.getElementById('aviso_sem_observacoes');
            if (avisoVazio) avisoVazio.remove();

            const div = document.createElement('div');
            div.className = 'observacao-linha';
            div.dataset.index = idx;
            div.innerHTML =
                '<div class="form-group"><label>Observação</label><textarea name="observacoes[' + idx + '][descricao]" rows="2"></textarea></div>' +
                '<button type="button" class="btn-remover-linha" onclick="removerLinhaObservacao(this)" title="Remover"><i class="fa-solid fa-trash-can"></i></button>';
            container.appendChild(div);
        }

        function removerLinhaObservacao(btn) {
            btn.closest('.observacao-linha').remove();
        }

        function removerLinhaAlteracao(btn) {
            btn.closest('.alteracao-linha').remove();
        }

        function atualizarCasasBanho(idx) {
            const casasBanhoSel = document.getElementById('piso_casas_banho_' + idx);
            if (!casasBanhoSel) return;
            const n = parseInt(casasBanhoSel.value) || 0;

            const blocoDiff = document.getElementById('bloco_piso_cbdif_' + idx);
            if (blocoDiff) {
                blocoDiff.style.display = (n > 1) ? 'flex' : 'none';
            }

            const iluminacaoSel = document.getElementById('piso_iluminacao_' + idx);
            if (iluminacaoSel) {
                const valorAtual = parseInt(iluminacaoSel.value) || 0;
                const novoValor = Math.min(valorAtual, n);
                let html = '';
                for (let i = 0; i <= n; i++) {
                    html += '<option value="' + i + '"' + (i === novoValor ? ' selected' : '') + '>' + i + '</option>';
                }
                iluminacaoSel.innerHTML = html;
            }
        }

        function toggleCaixaContador() {
            const val = document.getElementById('sel_caixa_contador').value;
            document.getElementById('bloco_caixa_contador_local').style.display = (val === 'Sim') ? 'flex' : 'none';
        }

        function jsValidarNIF(nif) {
            nif = nif.replace(/\D/g, '');
            if (nif.length !== 9) return false;
            if (['000000000', '111111111', '222222222', '333333333', '444444444', '555555555', '666666666', '777777777', '888888888', '999999999'].includes(nif)) return false;
            let total = nif[0]*9 + nif[1]*8 + nif[2]*7 + nif[3]*6 + nif[4]*5 + nif[5]*4 + nif[6]*3 + nif[7]*2;
            let modulo11 = total % 11;
            let comparador = modulo11 < 2 ? 0 : 11 - modulo11;
            return nif[8] == comparador;
        }

        function verificarNIF(input) {
            if(input.value !== "" && !jsValidarNIF(input.value)) {
                input.classList.add('nif-invalido');
                alert("Contribuinte inválido!");
            } else {
                input.classList.remove('nif-invalido');
            }
        }

        function validarSubmissao() {
            const id = document.getElementById('sel_cli').value;
            if(id === 'novo') {
                const nif = document.getElementById('nif_input').value;
                if(nif !== "" && !jsValidarNIF(nif)) {
                    alert("Por favor, introduza um Contribuinte válido.");
                    return false;
                }
            }
            return true;
        }

        function atualizarInterfaceCliente() {
            const id = document.getElementById('sel_cli').value;
            const selectObra = document.getElementById('sel_obra');

            document.getElementById('box_novo_cliente').style.display = (id === 'novo') ? 'block' : 'none';

            if (id === 'novo') {
                selectObra.innerHTML = '<option value="nova">+ CRIAR NOVA OBRA</option>';
            } else if (id) {
                selectObra.innerHTML = '<option value="">-- Selecione --</option>';
                obrasData.filter(o => o.id_cliente == id).forEach(o => {
                    let opt = new Option((o.referencia_obra ? o.referencia_obra + ' – ' : '') + o.nome, o.id);
                    if (o.id == id_obra_pre_selecionada) opt.selected = true;
                    selectObra.add(opt);
                });
                selectObra.add(new Option('+ CRIAR NOVA OBRA', 'nova'));
            } else {
                selectObra.innerHTML = '<option value="">-- Escolha o Cliente --</option>';
            }
            aoSelecionarObra();
        }

        function aoSelecionarObra() {
            const id = document.getElementById('sel_obra').value;
            document.getElementById('box_nova_obra').style.display = (id === 'nova') ? 'block' : 'none';
            atualizarBadgeReferencia(id);
        }

        // Mostra no título o número partilhado da obra escolhida
        function atualizarBadgeReferencia(idObra) {
            const badge = document.getElementById('ref_badge');
            if (!badge) return;
            const obra = obrasData.find(o => String(o.id) === String(idObra));
            if (obra && obra.referencia_obra) {
                badge.className = 'ref-badge';
                badge.innerHTML = '<i class="fa-solid fa-hashtag"></i> ' + obra.referencia_obra;
            } else if (idObra === 'nova' || (obra && !obra.referencia_obra)) {
                badge.className = 'ref-badge pendente';
                badge.textContent = 'Novo Nº atribuído ao gravar';
            } else if (refInicial) {
                badge.className = 'ref-badge';
                badge.innerHTML = '<i class="fa-solid fa-hashtag"></i> ' + refInicial;
            } else {
                badge.className = 'ref-badge pendente';
                badge.textContent = 'Nº atribuído ao gravar';
            }
        }

        // Menu do utilizador: em ecrãs táteis abre com toque
        (function () {
            const menu = document.querySelector('.user-menu');
            const btn = menu ? menu.querySelector('.user-btn') : null;
            if (!btn) return;
            btn.addEventListener('click', function (e) { e.stopPropagation(); menu.classList.toggle('aberto'); });
            document.addEventListener('click', function () { menu.classList.remove('aberto'); });
        })();

        window.onload = function() {
            atualizarInterfaceCliente();
            toggleCaixaContador();
            document.querySelectorAll('.piso-item').forEach(function(card) {
                atualizarCasasBanho(card.dataset.index);
            });
        };
    </script>
</body>
</html>