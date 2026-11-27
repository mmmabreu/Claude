<?php
session_start();

// 1. VERIFICAÇÃO DE SEGURANÇA (Regra de Ouro)
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}

require_once 'db.php';

// Verificação do ficheiro de funções para o Log
if (file_exists('functions.php')) {
    require_once 'functions.php';
} else {
    function registrarLog($pdo, $acao, $modulo) { return true; }
}

$perfil_user = $_SESSION['perfil'] ?? '';
$is_admin = in_array($perfil_user, ['admin', 'administrativo']);

// REGRA DE OURO 2: Acesso Restrito pela Matriz (Proteção de URL direta)
$stmt_perm = $pdo->prepare("SELECT modulo FROM permissoes WHERE grupo = :perfil AND pode_aceder = 1");
$stmt_perm->execute(['perfil' => $perfil_user]);
$meus_acessos = $stmt_perm->fetchAll(PDO::FETCH_COLUMN);

if (!$is_admin && !in_array('orcamentos', $meus_acessos)) {
    header("Location: index.php?erro=sem_permissao");
    exit;
}

// REGRA DE OURO 3: Obter apenas o primeiro nome para o Menu
$nome_completo = $_SESSION['nome'] ?? 'Utilizador';
$primeiro_nome = explode(' ', trim($nome_completo))[0];

$mensagem = '';
$tipo_mensagem = '';

if (isset($_GET['editado'])) {
    $mensagem = "Operação realizada com sucesso!";
    $tipo_mensagem = "sucesso";
}

// --- LÓGICA DE PRIVACIDADE FINANCEIRA ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['btn_desbloquear_financeiro'])) {
    $pass_introduzida = $_POST['pass_privacidade'] ?? '';
    $stmt_pass = $pdo->prepare("SELECT valor FROM configuracoes WHERE chave = 'password_apagar' LIMIT 1");
    $stmt_pass->execute();
    $pass_config = $stmt_pass->fetchColumn();

    if ($pass_introduzida === $pass_config && !empty($pass_introduzida)) {
        $_SESSION['financeiro_desbloqueado'] = true;
    } else {
        $mensagem = "Password incorreta. Não foi possível desbloquear os dados.";
        $tipo_mensagem = "erro";
        $_SESSION['financeiro_desbloqueado'] = false;
    }
}

if (isset($_GET['bloquear'])) {
    $_SESSION['financeiro_desbloqueado'] = false;
    header("Location: orcamentos.php");
    exit;
}

$financeiro_visivel = isset($_SESSION['financeiro_desbloqueado']) && $_SESSION['financeiro_desbloqueado'] === true;
$colspan_tabela = $financeiro_visivel ? 9 : 6;

// --- LÓGICA PARA APAGAR ORÇAMENTO ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['btn_confirmar_apagar'])) {
    if ($_SESSION['perfil'] !== 'admin') {
        $mensagem = "Erro: Sem permissão.";
        $tipo_mensagem = "erro";
    } else {
        $id_orcamento = filter_input(INPUT_POST, 'id_orcamento_apagar', FILTER_VALIDATE_INT);
        $pass_introduzida = $_POST['pass_seguranca'] ?? '';

        $stmt_pass = $pdo->prepare("SELECT valor FROM configuracoes WHERE chave = 'password_apagar' LIMIT 1");
        $stmt_pass->execute();
        $pass_config = $stmt_pass->fetchColumn();

        if ($pass_introduzida !== $pass_config || empty($pass_introduzida)) {
            $mensagem = "Password de segurança incorreta.";
            $tipo_mensagem = "erro";
        } else {
            try {
                $pdo->beginTransaction();

                $stmt_del_itens = $pdo->prepare("DELETE FROM orcamento_itens WHERE orcamento_id = :id");
                $stmt_del_itens->execute(['id' => $id_orcamento]);

                $stmt_del_obs = $pdo->prepare("DELETE FROM orcamento_observacoes WHERE orcamento_id = :id");
                $stmt_del_obs->execute(['id' => $id_orcamento]);

                try {
                    $pdo->prepare("DELETE FROM orcamento_inclui WHERE orcamento_id = :id")->execute(['id' => $id_orcamento]);
                    $pdo->prepare("DELETE FROM orcamento_nao_inclui WHERE orcamento_id = :id")->execute(['id' => $id_orcamento]);
                } catch(Exception $e) {}

                // Limpar planeamento na NOVA tabela
                try {
                    $stmt_del_plan = $pdo->prepare("DELETE FROM planeamento_linhas WHERE orcamento_id = :id");
                    $stmt_del_plan->execute(['id' => $id_orcamento]);
                } catch(Exception $e) {}

                // CORRIGIDO: A tabela 'obras' não tem a coluna 'id_orcamento'.
                // Só apaga a obra associada se essa coluna existir; caso contrário, ignora.
                try {
                    $check_col_obra = $pdo->query("SHOW COLUMNS FROM obras LIKE 'id_orcamento'");
                    if ($check_col_obra !== false && $check_col_obra->rowCount() > 0) {
                        $stmt_del_obra = $pdo->prepare("DELETE FROM obras WHERE id_orcamento = :id");
                        $stmt_del_obra->execute(['id' => $id_orcamento]);
                    }
                } catch(Exception $e) {}

                $stmt_del = $pdo->prepare("DELETE FROM orcamentos WHERE id = :id");
                $stmt_del->execute(['id' => $id_orcamento]);

                $pdo->commit();
                registrarLog($pdo, "Apagou Orçamento ID: $id_orcamento", "Orçamentos");
                $mensagem = "Orçamento removido com sucesso.";
                $tipo_mensagem = "sucesso";
            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $mensagem = "Erro ao apagar orçamento: " . htmlspecialchars($e->getMessage());
                $tipo_mensagem = "erro";
            }
        }
    }
}

// --- LÓGICA PARA DUPLICAR ORÇAMENTO ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['btn_confirmar_duplicar'])) {
    $id_orcamento_orig = filter_input(INPUT_POST, 'id_orcamento_duplicar', FILTER_VALIDATE_INT);
    $pass_introduzida = $_POST['pass_seguranca_dup'] ?? '';

    $stmt_pass = $pdo->prepare("SELECT valor FROM configuracoes WHERE chave = 'password_apagar' LIMIT 1");
    $stmt_pass->execute();
    $pass_config = $stmt_pass->fetchColumn();

    if ($pass_introduzida !== $pass_config || empty($pass_introduzida)) {
        $mensagem = "Password de segurança incorreta.";
        $tipo_mensagem = "erro";
    } else {
        try {
            $pdo->beginTransaction();

            // 1. Buscar Orçamento Original
            $stmt_orig = $pdo->prepare("SELECT * FROM orcamentos WHERE id = ?");
            $stmt_orig->execute([$id_orcamento_orig]);
            $orig = $stmt_orig->fetch(PDO::FETCH_ASSOC);

            if (!$orig) throw new Exception("Orçamento original não encontrado.");

            // 2. Gerar Novo Número de Referência
            $ano_atual_curto = date('y');
            $stmt_ref = $pdo->query("SELECT valor FROM configuracoes WHERE chave = 'ultimo_numero_projeto'");
            $ultima_ref = $stmt_ref->fetchColumn();

            if ($ultima_ref && substr($ultima_ref, 0, 2) === $ano_atual_curto) {
                $novo_numero = (string)((int)$ultima_ref + 1);
            } else {
                $novo_numero = $ano_atual_curto . "0001";
            }

            // 3. Inserir Novo Orçamento (Com nova data e estado Rascunho)
            $sql_ins = "INSERT INTO orcamentos (
                            numero_orcamento, data, data_validade, id_cliente, taxa_iva,
                            nome_obra, morada_obra, km, contacto_obra, referencia_cliente,
                            mao_obra, valor_total_custo, valor_custo_real,
                            valor_total_liquido, valor_iva, valor_total_com_iva, estado
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

            $nova_data = date('Y-m-d');
            $nova_validade = date('Y-m-d', strtotime('+30 days'));

            $stmt_ins = $pdo->prepare($sql_ins);
            $stmt_ins->execute([
                $novo_numero, $nova_data, $nova_validade, $orig['id_cliente'], $orig['taxa_iva'],
                $orig['nome_obra'], $orig['morada_obra'], $orig['km'], $orig['contacto_obra'], $orig['referencia_cliente'],
                $orig['mao_obra'], $orig['valor_total_custo'], $orig['valor_custo_real'],
                $orig['valor_total_liquido'], $orig['valor_iva'], $orig['valor_total_com_iva'], 'Rascunho'
            ]);
            $novo_id = $pdo->lastInsertId();

            // Atualiza Configuração Global
            $stmt_seq = $pdo->prepare("INSERT INTO configuracoes (chave, valor) VALUES ('ultimo_numero_projeto', ?) ON DUPLICATE KEY UPDATE valor = ?");
            $stmt_seq->execute([$novo_numero, $novo_numero]);

            // 4. Copiar Itens
            $stmt_itens = $pdo->prepare("SELECT * FROM orcamento_itens WHERE orcamento_id = ?");
            $stmt_itens->execute([$id_orcamento_orig]);
            $itens = $stmt_itens->fetchAll(PDO::FETCH_ASSOC);
            if ($itens) {
                $sql_item = "INSERT INTO orcamento_itens (orcamento_id, tipo_linha, artigo, designacao, unidade, quantidade, preco_unitario_custo, tempo_mao_obra, valor_custo, preco_unitario_venda, valor_venda, margem_bloqueada) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                $stmt_ins_item = $pdo->prepare($sql_item);
                foreach ($itens as $it) {
                    $tipo_l = $it['tipo_linha'] ?? 'Interno';
                    $t_mo = $it['tempo_mao_obra'] ?? 0;
                    $m_bloq = $it['margem_bloqueada'] ?? 0;
                    $stmt_ins_item->execute([$novo_id, $tipo_l, $it['artigo'], $it['designacao'], $it['unidade'], $it['quantidade'], $it['preco_unitario_custo'], $t_mo, $it['valor_custo'], $it['preco_unitario_venda'], $it['valor_venda'], $m_bloq]);
                    // A cópia mantém a ligação à tabela de preços, a composição e a exclusão da linha
                    $extra = array_intersect_key($it, array_flip(['id_artigo_tabela', 'composicao_json', 'excluida']));
                    if ($extra) {
                        try {
                            $novo_item = $pdo->lastInsertId();
                            $sets = implode(', ', array_map(fn($k) => "$k = ?", array_keys($extra)));
                            $pdo->prepare("UPDATE orcamento_itens SET $sets WHERE id = ?")->execute(array_merge(array_values($extra), [$novo_item]));
                        } catch (Exception $e_x) {}
                    }
                }
            }

            // 5. Copiar Observações
            $stmt_obs = $pdo->prepare("SELECT observacao FROM orcamento_observacoes WHERE orcamento_id = ?");
            $stmt_obs->execute([$id_orcamento_orig]);
            $obs = $stmt_obs->fetchAll(PDO::FETCH_COLUMN);
            if ($obs) {
                $stmt_ins_obs = $pdo->prepare("INSERT INTO orcamento_observacoes (orcamento_id, observacao) VALUES (?, ?)");
                foreach ($obs as $ob) $stmt_ins_obs->execute([$novo_id, $ob]);
            }

            // 6. Copiar Inclui e Não Inclui
            try {
                $stmt_inc = $pdo->prepare("SELECT texto FROM orcamento_inclui WHERE orcamento_id = ?");
                $stmt_inc->execute([$id_orcamento_orig]);
                $incs = $stmt_inc->fetchAll(PDO::FETCH_COLUMN);
                if ($incs) {
                    $stmt_ins_inc = $pdo->prepare("INSERT INTO orcamento_inclui (orcamento_id, texto) VALUES (?, ?)");
                    foreach ($incs as $inc) $stmt_ins_inc->execute([$novo_id, $inc]);
                }

                $stmt_ninc = $pdo->prepare("SELECT texto FROM orcamento_nao_inclui WHERE orcamento_id = ?");
                $stmt_ninc->execute([$id_orcamento_orig]);
                $nincs = $stmt_ninc->fetchAll(PDO::FETCH_COLUMN);
                if ($nincs) {
                    $stmt_ins_ninc = $pdo->prepare("INSERT INTO orcamento_nao_inclui (orcamento_id, texto) VALUES (?, ?)");
                    foreach ($nincs as $ninc) $stmt_ins_ninc->execute([$novo_id, $ninc]);
                }
            } catch(Exception $e) {}

            $pdo->commit();
            registrarLog($pdo, "Duplicou Orçamento #".$orig['numero_orcamento']." para o novo #$novo_numero", "Orçamentos");
            $mensagem = "Orçamento duplicado com sucesso! Nova referência: $novo_numero";
            $tipo_mensagem = "sucesso";

        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $mensagem = "Erro ao duplicar orçamento: " . htmlspecialchars($e->getMessage());
            $tipo_mensagem = "erro";
        }
    }
}

// --- BUSCAR LISTAGEM (Ordenação atualizada com Congelado) ---
try {
    $query = "SELECT o.*, c.nome as nome_cliente
              FROM orcamentos o
              LEFT JOIN clientes c ON o.id_cliente = c.id
              ORDER BY
                CASE o.estado
                    WHEN 'Em Rascunho' THEN 1
                    WHEN 'Rascunho' THEN 1
                    WHEN 'Em Preparação' THEN 1
                    WHEN 'Enviado' THEN 2
                    WHEN 'Adjudicado' THEN 3
                    WHEN 'Não Adjudicado' THEN 4
                    WHEN 'Rejeitado' THEN 4
                    WHEN 'Recusado' THEN 4
                    WHEN 'Perdido' THEN 4
                    WHEN 'Congelado' THEN 5
                    ELSE 1
                END ASC,
                o.data DESC,
                o.id DESC";
    $stmt = $pdo->query($query);
    $orcamentos = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("Erro Crítico na Base de Dados: " . htmlspecialchars($e->getMessage()));
}

// Orçamentos complementares: número do orçamento de origem e nº de complementares de cada um
$num_por_id = [];
$n_complementares = [];
foreach ($orcamentos as $oo) {
    $num_por_id[(int)$oo['id']] = $oo['numero_orcamento'];
    if (!empty($oo['id_orcamento_pai'])) $n_complementares[(int)$oo['id_orcamento_pai']] = ($n_complementares[(int)$oo['id_orcamento_pai']] ?? 0) + 1;
}

// Mapeamento dos Estados
$legendas_estados = [
    'Em Rascunho'   => 'Rascunho',
    'Rascunho'      => 'Rascunho',
    'Em Preparação' => 'Rascunho',
    'Enviado'       => 'Enviado',
    'Adjudicado'    => 'Adjudicado',
    'Rejeitado'     => 'Não Adjudicado',
    'Recusado'      => 'Não Adjudicado',
    'Perdido'       => 'Não Adjudicado',
    'Não Adjudicado'=> 'Não Adjudicado',
    'Congelado'     => 'Congelado'
];

// OTIMIZAÇÃO: Novas opções de impressão para PDF CLARAS PARA O UTILIZADOR
$tipos_pdf_orcamento = [
    'orcamento_cliente_comercial' => ['grupo' => 'cliente', 'label' => 'Igual ao Comercial', 'view' => 'comercial',
        'dica' => 'Resumo Comercial exatamente como está no formulário (sem passar a vg nem esconder nada).'],
    'orcamento_cliente_detalhe' => ['grupo' => 'cliente', 'label' => 'Valores por Linha', 'view' => 'detalhe',
        'dica' => 'Resumo Comercial (se preenchido) com preço unitário e total por linha.'],
    'orcamento_cliente_detalhe_vg' => ['grupo' => 'cliente', 'label' => 'Valores por Linha VG', 'view' => 'detalhe_vg',
        'dica' => 'Como «Valores por Linha», mas as linhas em m / ml passam a vg (qtd. 1,00 e preço = total da linha).'],
    'orcamento_cliente_capitulo' => ['grupo' => 'cliente', 'label' => 'Total por Capítulo', 'view' => 'capitulo',
        'dica' => 'Resumo Comercial com o subtotal de cada capítulo/secção, sem preços parciais.'],
    'orcamento_cliente_global' => ['grupo' => 'cliente', 'label' => 'Só Valor Global', 'view' => 'global',
        'dica' => 'Resumo Comercial sem preços parciais, só com o valor total.'],
    'limpa' => ['grupo' => 'cliente', 'label' => 'Sem Preços', 'view' => 'limpo',
        'dica' => 'Resumo Comercial sem qualquer preço (cadernos de encargos).'],
    'orcamento_interno_capitulo' => ['grupo' => 'interno', 'label' => 'Interno por Capítulo', 'view' => 'capitulo_interno',
        'dica' => 'Detalhe Interno com o subtotal de cada capítulo/secção (sem custos nem margens).'],
    'orcamento_gestao' => ['grupo' => 'interno', 'label' => 'Gestão (custos/margens)', 'view' => 'gestao',
        'dica' => 'Documento estritamente interno: todo o Detalhe Interno, com custos e margens.'],
];
?>
<!DOCTYPE html>
<html lang="pt-PT">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Orçamentos - Gestão MAB</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { font-family: system-ui, -apple-system, sans-serif; background-color: #f7f9fc; margin: 0; color: #333; padding-bottom: 50px; }
        * { box-sizing: border-box; }

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
        .nav-icon.i-termos.active, .nav-icon.i-termos:hover { color: #9b59b6; }
        .nav-icon.i-equipa.active, .nav-icon.i-equipa:hover { color: #7f8c8d; }
        .nav-icon.i-config.active, .nav-icon.i-config:hover { color: #2c3e50; }

        .topbar-right { display: flex; align-items: center; gap: 20px; }
        .btn-back { color: #576574; text-decoration: none; font-size: 20px; transition: color 0.2s; }
        .btn-back:hover { color: #f39c12; }

        .user-menu { position: relative; }
        .user-btn { background: #fdfdfd; border: 1px solid #f1f2f6; border-radius: 30px; font-size: 14px; font-weight: 600; color: #2c3e50; cursor: pointer; display: flex; align-items: center; gap: 10px; padding: 6px 15px 6px 6px; transition: 0.2s; }
        .user-btn:hover { background: #fff; box-shadow: 0 4px 10px rgba(0,0,0,0.05); }
        .user-avatar { background: #e74c3c; color: white; width: 28px; height: 28px; border-radius: 50%; display: flex; justify-content: center; align-items: center; font-size: 14px; border: 2px solid #fadbd8; }

        .dropdown-content { display: none; position: absolute; right: 0; top: 100%; margin-top: 5px; background: #fff; min-width: 180px; box-shadow: 0 10px 30px rgba(0,0,0,0.1); border-radius: 8px; overflow: hidden; border: 1px solid #f1f2f6; z-index:200;}
        .dropdown-content a { color: #576574; padding: 12px 20px; text-decoration: none; display: block; font-size: 14px; }
        .dropdown-content a:hover { background: #fdf2f2; color: #f39c12; }
        .user-menu:hover .dropdown-content { display: block; }

        .container { max-width: 1300px; margin: 20px auto; padding: 0 15px; }
        .page-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px; border-bottom: 1px solid #e1e5ea; padding-bottom: 15px; flex-wrap: wrap; gap: 15px; }
        .page-title { margin: 0; color: #2c3e50; font-size: 22px; font-weight: 800; display: flex; align-items: center; gap: 10px; }
        .page-title i { color: #f39c12; }
        .header-actions { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; }

        .btn-tabela-precos { background: #fdfdfd; color: #576574; border: 1px solid #e1e5ea; padding: 8px 15px; border-radius: 30px; text-decoration: none; font-weight: 700; font-size: 13px; display: inline-flex; align-items: center; gap: 6px; transition: 0.2s; }
        .btn-tabela-precos:hover { background: #f1f2f6; color: #2c3e50; }
        .btn-novo { background: #fff; color: #f39c12; border: 1px solid #f39c12; padding: 8px 15px; border-radius: 30px; text-decoration: none; font-weight: 700; font-size: 13px; display: inline-flex; align-items: center; gap: 6px; transition: 0.2s; }
        .btn-novo:hover { background: #f39c12; color: #fff; }
        .btn-lock { background: #fdfdfd; color: #95a5a6; border: 1px solid #e1e5ea; padding: 8px 15px; border-radius: 30px; cursor: pointer; font-weight: 700; font-size: 13px; display: inline-flex; align-items: center; gap: 6px; transition: 0.2s; text-decoration: none;}
        .btn-lock:hover { background: #f1f2f6; color: #34495e; }
        .btn-unlock { background: #fffde7; color: #f39c12; border: 1px solid #f39c12; padding: 8px 15px; border-radius: 30px; cursor: pointer; font-weight: 700; font-size: 13px; display: inline-flex; align-items: center; gap: 6px; transition: 0.2s; text-decoration: none;}
        .btn-unlock:hover { background: #f39c12; color: #fff; }

        /* Filtros de Orçamento (TABS) */
        .filter-bar { display: flex; gap: 10px; margin-bottom: 20px; overflow-x: auto; padding-bottom: 5px; border-bottom: 2px solid #e1e5ea;}
        .btn-filter { background: transparent; border: none; padding: 10px 20px; font-weight: 700; font-size: 14px; color: #7f8c8d; cursor: pointer; transition: 0.2s; white-space: nowrap; border-bottom: 3px solid transparent; margin-bottom: -2px;}
        .btn-filter:hover { color: #2c3e50; }
        .btn-filter.active { color: #f39c12; border-bottom: 3px solid #f39c12; }

        .table-container { background: #fff; border-radius: 12px; border: 1px solid #e1e5ea; box-shadow: 0 2px 10px rgba(0,0,0,0.02); overflow: hidden; }
        .table-responsive { width: 100%; overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; min-width: 900px; }
        th { padding: 12px 15px; background: #f8f9fa; color: #576574; font-size: 11px; text-transform: uppercase; text-align: left; border-bottom: 2px solid #e1e5ea; font-weight: 600; }
        td { padding: 12px 15px; border-bottom: 1px solid #f1f2f6; font-size: 14px; color: #2c3e50; vertical-align: middle; }
        tbody tr.data-row:hover { background-color: #fcfcfc; }

        .text-center { text-align: center !important; }
        .text-right { text-align: right !important; }
        .numero-orc { font-weight: 700; color: #f39c12; }
        .cliente-nome { color: #2c3e50; font-weight: 600; font-size: 15px; }
        .obra-nome { color: #7f8c8d; font-size: 13px; margin-top: 2px; }

        /* BADGES DE ESTADO */
        .estado-badge { font-weight: 700; font-size: 12px; padding: 6px 12px; border-radius: 20px; display: inline-flex; align-items: center; justify-content: center; line-height: 1; }
        .estado-badge-rascunho { background: #e3f2fd; color: #1976d2; }
        .estado-badge-enviado  { background: #fdebd0; color: #e67e22; }
        .estado-badge-adjudicado{ background: #d5f5e3; color: #1e8449; }
        .estado-badge-nao-adjudicado { background: #ffebee; color: #c62828; }
        .estado-badge-congelado { background: #e0f7fa; color: #00838f; }
        .estado-badge-default  { background: #f1f2f6; color: #576574; }

        .valor-bold { font-weight: 700; }
        .acoes { display: flex; gap: 8px; justify-content: center; }
        .btn-tab-acao { color: #576574; background: #f1f2f6; padding: 8px; border-radius: 6px; text-decoration: none; transition: 0.2s; display: inline-flex; width: 32px; height: 32px; align-items: center; justify-content: center; font-size:15px; border:none; cursor:pointer;}
        .btn-tab-acao:hover { background: #e1e5ea; color: #2c3e50; }
        .btn-planeamento { color: #9b59b6; background: #f4ecf8; }
        .btn-planeamento:hover { color: #8e44ad; background: #e8daef; }
        .btn-imprimir { color: #3498db; background: #f0f8ff; }
        .btn-imprimir:hover { color: #2980b9; background: #d6eaf8; }
        .btn-clone { color: #8e44ad; background: #f5eef8; }
        .btn-clone:hover { color: #9b59b6; background: #ebdef0; }
        .btn-del { color: #e74c3c; background: #fdeded; }
        .btn-del:hover { background: #fadbd8; color: #c0392b; }

        /* LEGENDAS DE AGRUPAMENTO */
        .row-estado-legend { background-color: #fdfdfd; }
        .row-estado-legend td { font-size: 11px !important; font-weight: 800; color: #576574; text-transform: uppercase; letter-spacing: 0.5px; border-bottom: 2px solid #f1f2f6; padding-top: 25px; padding-bottom: 10px;}

        .row-legend-rascunho td { background-color: #e3f2fd; color: #1976d2; border-bottom: 2px solid #bbdefb; }
        .row-legend-enviado td { background-color: #fffde7; color: #f39c12; border-bottom: 2px solid #fdebd0;}
        .row-legend-adjudicado td { background-color: #e8f5e9; color: #27ae60; border-bottom: 2px solid #d5eedd;}
        .row-legend-nao-adjudicado td { background-color: #ffebee; color: #c62828; border-bottom: 2px solid #ffcdd2; }
        .row-legend-congelado td { background-color: #e0f7fa; color: #0097a7; border-bottom: 2px solid #b2ebf2; }

        .modal { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.6); z-index: 1001; justify-content: center; align-items: center; backdrop-filter: blur(2px); }
        .modal.show { display: flex; }
        .modal-content { background: #fff; padding: 30px; border-radius: 12px; width: 90%; max-width: 400px; text-align: center; box-shadow: 0 20px 50px rgba(0,0,0,0.2); }
        .modal-content input { width: 100%; padding: 12px; margin: 15px 0; border: 1px solid #dcdde1; border-radius: 8px; box-sizing: border-box; outline: none; transition:0.2s;}
        .modal-content input:focus { border-color: #e74c3c; }
        .btn-primary { background: #e74c3c; color: #fff; padding: 12px 24px; border-radius: 8px; text-decoration: none; font-weight: 600; border: none; cursor: pointer; width: 100%; transition:0.2s; }
        .btn-primary:hover { background: #c0392b;}
        .btn-primary-yellow { background: #f39c12; color:#fff;}
        .btn-primary-yellow:hover { background: #e67e22; }

        /* MODAL DE IMPRESSÃO */
        .modal-print-content { max-width: 460px; text-align: left; padding: 16px 18px; max-height: 90vh; overflow-y: auto; }
        .modal-print-header { display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #f1f2f6; padding-bottom: 10px; margin-bottom: 10px; }
        .modal-print-header h3 { margin: 0; color: #f39c12; font-size: 16px; display: flex; align-items: center; gap: 8px; }
        .close-btn { color: #95a5a6; font-size: 22px; cursor: pointer; font-weight: bold; background: none; border: none; padding: 0; line-height: 1; transition: 0.2s; }
        .close-btn:hover { color: #333; }
        .modal-subtitle { color: #576574; font-size: 13px; font-weight: 700; margin-bottom: 6px; text-align: left; }
        .pdf-grupo { font-size: 10px; font-weight: 800; text-transform: uppercase; letter-spacing: .5px; color: #95a5a6; margin: 10px 0 6px; }
        .pdf-grelha { display: grid; grid-template-columns: 1fr 1fr; gap: 6px; }

        .btn-pdf-option { display: flex; align-items: center; gap: 8px; width: 100%; padding: 9px 10px; background: #fdfdfd; border: 1px solid #e1e5ea; border-radius: 8px; cursor: pointer; text-align: left; transition: all 0.2s; font-family: inherit; text-decoration: none; box-sizing: border-box; }
        .btn-pdf-option:hover { background: #fcf3cf; border-color: #f39c12; box-shadow: 0 2px 5px rgba(0,0,0,0.02); }
        .btn-pdf-option i { color: #e74c3c; font-size: 15px; width: 16px; text-align: center; flex-shrink: 0; }
        .btn-pdf-interno i { color: #8e44ad; }
        .btn-pdf-material { margin-top: 10px; }
        .btn-pdf-material i { color: #1e8449; }
        .btn-pdf-material { border-color: #abebc6; background: #f4fbf7; }
        .btn-pdf-material:hover { background: #d5f5e3; border-color: #27ae60; }
        .btn-pdf-option span { color: #2c3e50; font-weight: 600; font-size: 12.5px; display: block; line-height: 1.25; }
        @media (max-width: 420px) { .pdf-grelha { grid-template-columns: 1fr; } }

        /* Esconder linhas filtradas */
        .filtered-out { display: none !important; }

        @media (max-width: 768px) {
            .topbar-center { display: none; }
            .topbar { padding: 10px 15px; }
            .page-header { flex-direction: row; justify-content: space-between; border-bottom: none; margin-bottom: 10px; }
            .btn-novo, .btn-tabela-precos { padding: 6px 12px; font-size: 12px; }
            thead { display: none; }
            tr.row-estado-legend { border:none; margin:0; padding:0; background:transparent;}
            tr { display: block; border: 1px solid #eee; margin-bottom: 15px; border-radius: 10px; background: #fff; padding: 10px 0; }
            td { display: flex; justify-content: space-between; padding: 10px 15px; text-align: right; border: none; border-bottom: 1px solid #f9f9f9; align-items: center; }
            td::before { content: attr(data-label); font-weight: bold; color: #999; text-transform: uppercase; font-size: 10px; }
            .row-estado-legend td { justify-content: center; text-align: center; background: #fcfcfc; padding: 15px; border-radius:8px;}
            .row-estado-legend td::before { display: none; }
            .cliente-nome { text-align: right; }
        }
            .tag-comp { display: block; margin-top: 3px; font-size: 10px; font-weight: 700; color: #b9770e; text-decoration: none; white-space: nowrap; }
        .btn-complementar { background: #fef5e7 !important; color: #b9770e !important; }
        .btn-complementar:hover { background: #fdebd0 !important; }
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
            <?php if ($is_admin || in_array('orcamentos', $meus_acessos)): ?><a href="orcamentos.php" class="nav-icon i-orcamentos active" title="Orçamentos"><i class="fa-solid fa-file-invoice"></i></a><?php endif; ?>
            <?php if ($is_admin || in_array('termos', $meus_acessos)): ?><a href="termos.php" class="nav-icon i-termos" title="Termos"><i class="fa-solid fa-file-signature"></i></a><?php endif; ?>
            <?php if ($is_admin || in_array('equipa', $meus_acessos)): ?><a href="utilizadores.php" class="nav-icon i-equipa" title="Equipa"><i class="fa-solid fa-id-card-clip"></i></a><?php endif; ?>
            <?php if ($is_admin || in_array('configuracoes', $meus_acessos)): ?><a href="configuracoes.php" class="nav-icon i-config" title="Configurações"><i class="fa-solid fa-gear"></i></a><?php endif; ?>
        </div>
        <div class="topbar-right">
            <a href="index.php" class="btn-back" title="Voltar"><i class="fa-solid fa-arrow-left"></i></a>
            <div class="user-menu">
                <button class="user-btn">
                    <div class="user-avatar"><i class="fa-solid fa-user"></i></div>
                    <?= htmlspecialchars($primeiro_nome) ?>
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
        <?php if ($mensagem): ?><div class="alert alert-<?= htmlspecialchars($tipo_mensagem, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($mensagem, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>

        <div class="page-header">
            <h1 class="page-title"><i class="fa-solid fa-file-lines"></i> Orçamentos</h1>
            <div class="header-actions">
                <a href="tabela_precos.php" class="btn-tabela-precos" title="Aceder à Tabela de Preços de Artigos"><i class="fa-solid fa-list-ol"></i> Tabela de Preços</a>

                <?php if ($financeiro_visivel): ?>
                    <a href="orcamentos.php?bloquear=1" class="btn-unlock" title="Ocultar Dados Financeiros"><i class="fa-solid fa-lock-open"></i> Ocultar Valores</a>
                <?php else: ?>
                    <button type="button" class="btn-lock" onclick="abrirModalPrivacidade()" title="Mostrar Dados Financeiros"><i class="fa-solid fa-lock"></i> Mostrar Valores</button>
                <?php endif; ?>

                <a href="orcamento_form.php" class="btn-novo"><i class="fa-solid fa-plus-circle"></i> Novo Registo</a>
            </div>
        </div>

        <div class="filter-bar">
            <button class="btn-filter" onclick="filtrarOrcamentos('Todos', this)">Todos</button>
            <button id="btn-filtro-inicial" class="btn-filter active" onclick="filtrarOrcamentos('Rascunho', this)">Rascunhos</button>
            <button class="btn-filter" onclick="filtrarOrcamentos('Enviado', this)">Enviados</button>
            <button class="btn-filter" onclick="filtrarOrcamentos('Adjudicado', this)">Adjudicados</button>
            <button class="btn-filter" onclick="filtrarOrcamentos('Não Adjudicado', this)">Não Adjudicados</button>
            <button class="btn-filter" onclick="filtrarOrcamentos('Congelado', this)">Congelados</button>
        </div>

        <div class="table-container">
            <div class="table-responsive">
                <table id="tabelaOrcamentos">
                    <thead>
                        <tr>
                            <th>Nº</th>
                            <th>Cliente / Obra</th>
                            <th class="text-center">Data</th>
                            <th class="text-center">Estado</th>
                            <?php if ($financeiro_visivel): ?>
                                <th class="text-right">Mão de Obra</th>
                                <th class="text-right">Total Custo</th>
                                <th class="text-right">Margem</th>
                            <?php endif; ?>
                            <th class="text-right">Total Líquido</th>
                            <th class="text-center" style="width: 180px;">Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($orcamentos): ?>
                            <?php
                            $estado_atual = null;
                            foreach ($orcamentos as $o):
                                $est_db = $o['estado'] ?: 'Em Rascunho';
                                $legenda_visivel = $legendas_estados[$est_db] ?? $est_db;

                                if ($legenda_visivel !== $estado_atual):
                                    $estado_atual = $legenda_visivel;
                                    $classe_cor = '';
                                    if ($estado_atual === 'Rascunho') $classe_cor = 'row-legend-rascunho';
                                    if ($estado_atual === 'Enviado') $classe_cor = 'row-legend-enviado';
                                    if ($estado_atual === 'Adjudicado') $classe_cor = 'row-legend-adjudicado';
                                    if ($estado_atual === 'Não Adjudicado') $classe_cor = 'row-legend-nao-adjudicado';
                                    if ($estado_atual === 'Congelado') $classe_cor = 'row-legend-congelado';
                            ?>
                                <tr class="row-estado-legend <?= $classe_cor ?> <?= $estado_atual !== 'Rascunho' ? 'filtered-out' : '' ?>" data-grupo="<?= htmlspecialchars($estado_atual, ENT_QUOTES, 'UTF-8') ?>">
                                    <td colspan="<?= $colspan_tabela ?>"><?= htmlspecialchars($estado_atual, ENT_QUOTES, 'UTF-8') ?></td>
                                </tr>
                            <?php endif; ?>

                            <?php
                                $custo_total_calculado = (float)($o['valor_total_custo'] ?? 0) + (float)($o['mao_obra'] ?? 0);
                                $margem = (float)($o['valor_total_liquido'] ?? 0) - $custo_total_calculado;
                                $cor_margem = ($margem < 0) ? '#e74c3c' : '#27ae60';

                                // Classe do badge conforme estado
                                $est_badge = $legendas_estados[$o['estado']] ?? $o['estado'];
                                $classe_badge = 'estado-badge-default';
                                if ($est_badge === 'Rascunho') $classe_badge = 'estado-badge-rascunho';
                                if ($est_badge === 'Enviado') $classe_badge = 'estado-badge-enviado';
                                if ($est_badge === 'Adjudicado') $classe_badge = 'estado-badge-adjudicado';
                                if ($est_badge === 'Não Adjudicado') $classe_badge = 'estado-badge-nao-adjudicado';
                                if ($est_badge === 'Congelado') $classe_badge = 'estado-badge-congelado';
                            ?>
                                <tr class="data-row <?= $est_badge !== 'Rascunho' ? 'filtered-out' : '' ?>" data-estado="<?= htmlspecialchars($est_badge, ENT_QUOTES, 'UTF-8') ?>">
                                    <td data-label="Nº" class="numero-orc"><?= htmlspecialchars($o['numero_orcamento'], ENT_QUOTES, 'UTF-8') ?>
                                        <?php if (!empty($o['id_orcamento_pai'])): ?>
                                            <a class="tag-comp" href="orcamento_form.php?id=<?= (int)$o['id_orcamento_pai'] ?>" title="Orçamento complementar — abrir o de origem"><i class="fa-solid fa-code-branch"></i> Compl. de <?= htmlspecialchars($num_por_id[(int)$o['id_orcamento_pai']] ?? ('#' . (int)$o['id_orcamento_pai']), ENT_QUOTES, 'UTF-8') ?></a>
                                        <?php elseif (!empty($n_complementares[(int)$o['id']])): ?>
                                            <span class="tag-comp" title="Tem orçamentos complementares"><i class="fa-solid fa-code-branch"></i> <?= (int)$n_complementares[(int)$o['id']] ?> complementar<?= $n_complementares[(int)$o['id']] > 1 ? 'es' : '' ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td data-label="Cliente / Obra">
                                        <div class="cliente-nome"><?= htmlspecialchars($o['nome_cliente'] ?? 'N/D', ENT_QUOTES, 'UTF-8') ?></div>
                                        <div class="obra-nome"><?= htmlspecialchars($o['nome_obra'] ?? '', ENT_QUOTES, 'UTF-8') ?></div>
                                    </td>
                                    <td data-label="Data" class="text-center"><?= date('d/m/Y', strtotime($o['data'])) ?></td>
                                    <td data-label="Estado" class="text-center">
                                        <span class="estado-badge <?= $classe_badge ?>"><?= htmlspecialchars($est_badge, ENT_QUOTES, 'UTF-8') ?></span>
                                    </td>
                                    <?php if ($financeiro_visivel): ?>
                                        <td data-label="Mão de Obra" class="text-right"><?= number_format($o['mao_obra'] ?? 0, 2, ',', '.') ?> €</td>
                                        <td data-label="Total Custo" class="text-right"><?= number_format($o['valor_total_custo'] ?? 0, 2, ',', '.') ?> €</td>
                                        <td data-label="Margem" class="text-right valor-bold" style="color: <?= $cor_margem ?>;"><?= number_format($margem, 2, ',', '.') ?> €</td>
                                    <?php endif; ?>
                                    <td data-label="Total Líquido" class="text-right valor-bold" style="color: #2c3e50;"><?= number_format($o['valor_total_liquido'] ?? 0, 2, ',', '.') ?> €</td>
                                    <td data-label="Ações" class="text-center">
                                        <div class="acoes">
                                            <a href="planeamento.php?orcamento_id=<?= (int)$o['id'] ?>" class="btn-tab-acao btn-planeamento" title="Planeamento de Obra"><i class="fa-solid fa-list-check"></i></a>
                                            <button type="button" onclick="abrirImpressao(<?= (int)$o['id'] ?>, '<?= htmlspecialchars($o['numero_orcamento'], ENT_QUOTES, 'UTF-8') ?>', '<?= htmlspecialchars($o['estado'] ?? '', ENT_QUOTES, 'UTF-8') ?>')" class="btn-tab-acao btn-imprimir" title="Imprimir"><i class="fa-solid fa-print"></i></button>

                                            <?php if (($o['estado'] ?? '') === 'Adjudicado' && empty($o['id_orcamento_pai'])): ?>
                                            <a href="orcamento_form.php?complementar_de=<?= (int)$o['id'] ?>" class="btn-tab-acao btn-complementar" title="Criar Orçamento Complementar (novos trabalhos para esta obra)"><i class="fa-solid fa-code-branch"></i></a>
                                            <?php endif; ?>
                                            <button type="button" onclick="abrirModalDuplicar(<?= (int)$o['id'] ?>, '<?= htmlspecialchars($o['numero_orcamento'], ENT_QUOTES, 'UTF-8') ?>')" class="btn-tab-acao btn-clone" title="Duplicar"><i class="fa-regular fa-copy"></i></button>

                                            <a href="orcamento_form.php?id=<?= (int)$o['id'] ?>" class="btn-tab-acao btn-editar" title="Editar"><i class="fa-solid fa-pen-to-square"></i></a>
                                            <?php if ($is_admin): ?>
                                            <button type="button" onclick="abrirModalApagar(<?= (int)$o['id'] ?>, '<?= htmlspecialchars($o['numero_orcamento'], ENT_QUOTES, 'UTF-8') ?>')" class="btn-tab-acao btn-del" title="Apagar"><i class="fa-solid fa-trash-can"></i></button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr id="empty_row"><td colspan="<?= $colspan_tabela ?>" class="text-center" style="padding:40px; color:#7f8c8d;">Não foram encontrados orçamentos.</td></tr>
                        <?php endif; ?>
                        <tr id="no_results_row" style="display:none;"><td colspan="<?= $colspan_tabela ?>" class="text-center" style="padding:40px; color:#7f8c8d;">Nenhum orçamento encontrado para este filtro.</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="modal" id="modalApagar">
        <div class="modal-content">
            <h3 style="color:#e74c3c; margin-top:0;"><i class="fa-solid fa-triangle-exclamation"></i> Confirmar Eliminação</h3>
            <p>Deseja apagar o orçamento nº <strong id="txt_modal_apagar"></strong>?<br><span style="font-size:12px; color:#7f8c8d;">Esta ação é irreversível.</span></p>
            <form method="POST">
                <input type="hidden" name="id_orcamento_apagar" id="id_orc_apagar_input">
                <input type="password" name="pass_seguranca" required placeholder="Password de Segurança">
                <div style="display:flex; gap:10px; margin-top:15px;">
                    <button type="button" onclick="fecharModais()" style="flex:1; background:#f1f2f6; border:none; padding:12px; border-radius:8px; cursor:pointer; font-weight:600; color:#576574;">Cancelar</button>
                    <button type="submit" name="btn_confirmar_apagar" class="btn-primary" style="flex:1;">Apagar Definitivamente</button>
                </div>
            </form>
        </div>
    </div>

    <div class="modal" id="modalDuplicar">
        <div class="modal-content">
            <h3 style="color:#8e44ad; margin-top:0;"><i class="fa-regular fa-copy"></i> Duplicar Orçamento</h3>
            <p>Deseja criar uma cópia exata do orçamento nº <strong id="txt_modal_duplicar"></strong>?</p>
            <p style="font-size:12px; color:#7f8c8d; margin-top:0;">Será gerada uma nova referência (estado "Rascunho").</p>
            <form method="POST">
                <input type="hidden" name="id_orcamento_duplicar" id="id_orc_duplicar_input">
                <input type="password" name="pass_seguranca_dup" required placeholder="Password de Segurança">
                <div style="display:flex; gap:10px; margin-top:15px;">
                    <button type="button" onclick="fecharModais()" style="flex:1; background:#f1f2f6; border:none; padding:12px; border-radius:8px; cursor:pointer; font-weight:600; color:#576574;">Cancelar</button>
                    <button type="submit" name="btn_confirmar_duplicar" class="btn-primary" style="flex:1; background:#8e44ad;">Duplicar</button>
                </div>
            </form>
        </div>
    </div>

    <div class="modal" id="modalPrivacidade">
        <div class="modal-content">
            <h3 style="color:#f39c12; margin-top:0;"><i class="fa-solid fa-unlock-keyhole"></i> Desbloquear Valores</h3>
            <p>Insira a password de segurança para visualizar os valores de custo e margem.</p>
            <form method="POST">
                <input type="password" name="pass_privacidade" required placeholder="Password de Segurança">
                <div style="display:flex; gap:10px; margin-top:15px;">
                    <button type="button" onclick="fecharModais()" style="flex:1; background:#f1f2f6; border:none; padding:12px; border-radius:8px; cursor:pointer; font-weight:600; color:#576574;">Cancelar</button>
                    <button type="submit" name="btn_desbloquear_financeiro" class="btn-primary btn-primary-yellow" style="flex:1;">Desbloquear</button>
                </div>
            </form>
        </div>
    </div>

    <div class="modal" id="modalPrint">
        <div class="modal-content modal-print-content">
            <div class="modal-print-header">
                <h3><i class="fa-solid fa-print"></i> Gerar Documento</h3>
                <button type="button" class="close-btn" onclick="fecharModais()">&times;</button>
            </div>
            <div class="modal-subtitle" id="txt_modal_print"></div>

            <?php foreach (['cliente' => 'Para o cliente', 'interno' => 'Interno'] as $grp => $grp_nome): ?>
                <div class="pdf-grupo"><?= $grp_nome ?></div>
                <div class="pdf-grelha">
                <?php foreach ($tipos_pdf_orcamento as $key => $data): if (($data['grupo'] ?? 'cliente') !== $grp) continue; ?>
                    <a href="#" id="link_<?= htmlspecialchars($key, ENT_QUOTES, 'UTF-8') ?>" target="_blank" class="btn-pdf-option<?= $grp === 'interno' ? ' btn-pdf-interno' : '' ?>" onclick="fecharModais()" title="<?= htmlspecialchars($data['dica'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                        <i class="fa-solid <?= $data['view'] === 'gestao' ? 'fa-file-invoice-dollar' : ($data['view'] === 'capitulo_interno' ? 'fa-list-ol' : 'fa-file-pdf') ?>"></i>
                        <span><?= htmlspecialchars($data['label'], ENT_QUOTES, 'UTF-8') ?></span>
                    </a>
                <?php endforeach; ?>
                </div>
            <?php endforeach; ?>

            <!-- Só para orçamentos Adjudicados -->
            <a href="#" id="link_material" target="_blank" class="btn-pdf-option btn-pdf-material" onclick="fecharModais()" style="display:none;" title="Material do Detalhe Interno agrupado (artigos iguais somados), com quadrado para marcar o que foi comprado.">
                <i class="fa-solid fa-cart-shopping"></i>
                <span>Lista de Material a Comprar</span>
            </a>
        </div>
    </div>

    <script>
        // Lógica de Filtros JavaScript
        function filtrarOrcamentos(estadoFiltro, btnElement) {
            // Atualizar botões
            document.querySelectorAll('.btn-filter').forEach(btn => btn.classList.remove('active'));
            btnElement.classList.add('active');

            let temResultados = false;

            // Ocultar/Mostrar as legendas de agrupamento
            document.querySelectorAll('tr.row-estado-legend').forEach(row => {
                if (estadoFiltro === 'Todos' || row.getAttribute('data-grupo') === estadoFiltro) {
                    row.classList.remove('filtered-out');
                } else {
                    row.classList.add('filtered-out');
                }
            });

            // Ocultar/Mostrar as linhas de dados (os orçamentos em si)
            document.querySelectorAll('tr.data-row').forEach(row => {
                if (estadoFiltro === 'Todos' || row.getAttribute('data-estado') === estadoFiltro) {
                    row.classList.remove('filtered-out');
                    temResultados = true;
                } else {
                    row.classList.add('filtered-out');
                }
            });

            // Gerir a mensagem "Sem resultados"
            const noResultsRow = document.getElementById('no_results_row');
            const emptyRow = document.getElementById('empty_row');

            if (emptyRow) return; // Se não há orçamentos de todo, não faz nada

            if (!temResultados) {
                noResultsRow.style.display = 'table-row';
            } else {
                noResultsRow.style.display = 'none';
            }
        }

        // Funções Modais
        function abrirModalApagar(id, num) {
            document.getElementById('id_orc_apagar_input').value = id;
            document.getElementById('txt_modal_apagar').innerText = num;
            document.getElementById('modalApagar').classList.add('show');
        }
        function abrirModalDuplicar(id, num) {
            document.getElementById('id_orc_duplicar_input').value = id;
            document.getElementById('txt_modal_duplicar').innerText = num;
            document.getElementById('modalDuplicar').classList.add('show');
        }
        function abrirModalPrivacidade() {
            document.getElementById('modalPrivacidade').classList.add('show');
        }
        function abrirImpressao(id, num, estado) {
            const linkMat = document.getElementById('link_material');
            if (linkMat) {
                linkMat.href = 'orcamento_material_pdf.php?id=' + id;
                linkMat.style.display = (estado === 'Adjudicado') ? 'flex' : 'none';
            }
            document.getElementById('txt_modal_print').innerText = "Orçamento Nº " + num;
            <?php foreach($tipos_pdf_orcamento as $key => $data): ?>
                document.getElementById('link_<?= htmlspecialchars($key, ENT_QUOTES, 'UTF-8') ?>').href = 'orcamento_pdf.php?id=' + id + '&tipo=<?= htmlspecialchars($data['view'], ENT_QUOTES, 'UTF-8') ?>';
            <?php endforeach; ?>
            document.getElementById('modalPrint').classList.add('show');
        }
        function fecharModais() {
            document.getElementById('modalApagar').classList.remove('show');
            document.getElementById('modalDuplicar').classList.remove('show');
            document.getElementById('modalPrint').classList.remove('show');
            document.getElementById('modalPrivacidade').classList.remove('show');
        }
        window.onclick = function(e) { if(e.target.classList.contains('modal')) { fecharModais(); } }

    </script>
</body>
</html>
