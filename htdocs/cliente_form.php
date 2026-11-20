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

// --- ATUALIZAR TABELA AUTOMATICAMENTE (Se não existir) ---
try {
    $check_tipo = $pdo->query("SHOW COLUMNS FROM clientes LIKE 'tipo_cliente'");
    if ($check_tipo !== false && $check_tipo->rowCount() == 0) {
        $pdo->exec("ALTER TABLE clientes ADD COLUMN tipo_cliente VARCHAR(50) DEFAULT 'Obra' AFTER nome");
    }
} catch (Exception $e) {
    // Ignora para não quebrar a página
}

$perfil_user = $_SESSION['perfil'] ?? '';
$is_admin = in_array($perfil_user, ['admin', 'administrativo']);

// Apenas Admin ou Administrativo podem criar/editar clientes
if (!$is_admin) {
    header("Location: clientes.php?erro=sem_permissao");
    exit;
}

$nome_completo = $_SESSION['nome'] ?? 'Utilizador';
$primeiro_nome = explode(' ', trim($nome_completo))[0];

$mensagem = ''; 
$tipo_mensagem = '';

// --- DEFINIR MODO: CRIAR OU EDITAR ---
$id_edit = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$modo_editar = !empty($id_edit);

// --- VARIÁVEIS DE NAVEGAÇÃO ---
$prev_id = null;
$next_id = null;

// --- CARREGAR DADOS SE FOR MODO EDITAR ---
$cliente = [
    'nome' => '', 'tipo_cliente' => 'Obra', 'nif' => '', 'telefone' => '', 'email' => '', 
    'morada' => '', 'codigo_postal' => '', 'cidade' => '', 'ativo' => 1
];

if ($modo_editar) {
    $stmt = $pdo->prepare("SELECT * FROM clientes WHERE id = ?");
    $stmt->execute([$id_edit]);
    $dados_bd = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($dados_bd) {
        $cliente = $dados_bd;
        
        // --- DESCOBRIR CLIENTE ANTERIOR ---
        $stmt_prev = $pdo->prepare("SELECT id FROM clientes WHERE id < ? ORDER BY id DESC LIMIT 1");
        $stmt_prev->execute([$id_edit]);
        $prev_id = $stmt_prev->fetchColumn();
        
        // --- DESCOBRIR CLIENTE PRÓXIMO ---
        $stmt_next = $pdo->prepare("SELECT id FROM clientes WHERE id > ? ORDER BY id ASC LIMIT 1");
        $stmt_next->execute([$id_edit]);
        $next_id = $stmt_next->fetchColumn();
        
    } else {
        die("Cliente não encontrado.");
    }
}

// --- NIF DE CONSUMIDOR FINAL (999999990): pode repetir-se em vários clientes ---
// Se a coluna nif da tabela clientes for única, a regra passa para uma coluna auxiliar (nif_unico) igual ao NIF,
// exceto para 999999990 e vazio (fica NULL). Todos os outros NIFs continuam sem poder repetir. Feito uma só vez.
// (a mesma função existe no orcamento_form.php)
if (!function_exists('permitirNifConsumidorFinal')) {
    function permitirNifConsumidorFinal($pdo) {
        try {
            $idx = $pdo->query("SHOW INDEX FROM clientes WHERE Non_unique = 0 AND Key_name <> 'PRIMARY'")->fetchAll(PDO::FETCH_ASSOC);
            $por_nome = [];
            foreach ($idx as $i) $por_nome[$i['Key_name']][] = $i['Column_name'];
            foreach ($por_nome as $nome => $cols) {
                if ($cols === ['nif']) {
                    if (!$pdo->query("SHOW COLUMNS FROM clientes LIKE 'nif_unico'")->fetch()) {
                        $pdo->exec("ALTER TABLE clientes ADD COLUMN nif_unico VARCHAR(20) AS (IF(nif = '999999990' OR nif = '', NULL, nif)) STORED");
                    }
                    $pdo->exec("ALTER TABLE clientes ADD UNIQUE KEY uk_nif_unico (nif_unico)");
                    $pdo->exec("ALTER TABLE clientes DROP INDEX `" . str_replace('`', '', $nome) . "`");
                }
            }
        } catch (Exception $e) { /* se não conseguir, o erro aparece ao gravar */ }
    }
}
define('NIF_CONSUMIDOR_FINAL', '999999990');

// --- FUNÇÃO PARA VALIDAR NIF PT ---
function validarNIF($nif) {
    $nif = preg_replace('/[^0-9]/', '', $nif);
    
    if (empty(trim($nif))) return true;
    // Exceções permitidas (Consumidor final genérico ou desconhecido)
    if ($nif === '999999990' || $nif === '666666666') return true;
    if (strlen($nif) != 9) return false;

    $primeiros_digitos = ['1', '2', '3', '4', '5', '6', '7', '8', '9'];
    if (!in_array($nif[0], $primeiros_digitos)) return false;

    $soma = 0;
    for ($i = 0; $i < 8; $i++) { 
        $soma += $nif[$i] * (9 - $i); 
    }
    
    $resto = $soma % 11;
    $digito_controlo = ($resto < 2) ? 0 : 11 - $resto;

    return $digito_controlo == $nif[8];
}

// --- PROCESSAMENTO DO FORMULÁRIO ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nome = trim($_POST['nome'] ?? '');
    // Recolha super rigorosa do tipo de cliente
    $tipo_cliente = isset($_POST['tipo_cliente']) ? trim($_POST['tipo_cliente']) : 'Obra';
    
    $nif = trim($_POST['nif'] ?? '');
    $telefone = trim($_POST['telefone'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $morada = trim($_POST['morada'] ?? '');
    $codigo_postal = trim($_POST['codigo_postal'] ?? '');
    $cidade = trim($_POST['cidade'] ?? '');
    $ativo = isset($_POST['ativo']) ? 1 : 0;

    // Validações Base
    if (empty($nome) || empty($nif)) {
        $mensagem = "Os campos Nome e NIF são obrigatórios.";
        $tipo_mensagem = "erro";
    } elseif (!validarNIF($nif)) {
        $mensagem = "O NIF introduzido não é um número de contribuinte válido.";
        $tipo_mensagem = "erro";
    } elseif (!empty($telefone) && !preg_match("/^[0-9]{9}$/", $telefone)) {
        $mensagem = "O telefone tem de ter exatamente 9 algarismos.";
        $tipo_mensagem = "erro";
    } elseif (!empty($codigo_postal) && !preg_match("/^[0-9]{4}-[0-9]{3}$/", $codigo_postal)) {
        $mensagem = "O código postal tem de ter o formato 9999-999.";
        $tipo_mensagem = "erro";
    } else {
        try {
            $consumidor_final = ($nif === NIF_CONSUMIDOR_FINAL);
            if ($consumidor_final) permitirNifConsumidorFinal($pdo);   // o 999999990 pode repetir-se

            if ($modo_editar) {
                // Verificar NIF duplicado em edição (excluindo ele próprio) — exceto consumidor final
                if (!$consumidor_final) {
                    $stmt_check = $pdo->prepare("SELECT id FROM clientes WHERE nif = :nif AND id != :id LIMIT 1");
                    $stmt_check->execute(['nif' => $nif, 'id' => $id_edit]);
                    if ($stmt_check->fetch()) throw new Exception("Já existe outro cliente registado com este NIF.");
                }

                $sql = "UPDATE clientes SET nome=?, tipo_cliente=?, nif=?, telefone=?, email=?, morada=?, codigo_postal=?, cidade=?, ativo=? WHERE id=?";
                $pdo->prepare($sql)->execute([$nome, $tipo_cliente, $nif ?: null, $telefone, $email, $morada, $codigo_postal, $cidade, $ativo, $id_edit]);
                registrarLog($pdo, "Editou cliente: $nome", "Clientes");
                $mensagem = "Ficha do cliente atualizada com sucesso!";
                $tipo_mensagem = "sucesso";
            } else {
                // Verificar NIF duplicado em criação — exceto consumidor final
                if (!$consumidor_final) {
                    $stmt_check = $pdo->prepare("SELECT id FROM clientes WHERE nif = :nif LIMIT 1");
                    $stmt_check->execute(['nif' => $nif]);
                    if ($stmt_check->fetch()) throw new Exception("Já existe um cliente registado com este NIF.");
                }

                $sql = "INSERT INTO clientes (nome, tipo_cliente, nif, telefone, email, morada, codigo_postal, cidade, ativo) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
                $pdo->prepare($sql)->execute([$nome, $tipo_cliente, $nif ?: null, $telefone, $email, $morada, $codigo_postal, $cidade, $ativo]);
                registrarLog($pdo, "Criou novo cliente: $nome", "Clientes");
                header("Location: clientes.php?sucesso=criado");
                exit;
            }
            
            // Atualizar os valores na view
            $cliente['nome'] = $nome; 
            $cliente['tipo_cliente'] = $tipo_cliente; 
            $cliente['nif'] = $nif; 
            $cliente['telefone'] = $telefone; 
            $cliente['email'] = $email;
            $cliente['morada'] = $morada; 
            $cliente['codigo_postal'] = $codigo_postal; 
            $cliente['cidade'] = $cidade; 
            $cliente['ativo'] = $ativo;
            
        } catch (PDOException $e) {
            $mensagem = ($e->getCode() == '23000') ? "Já existe um cliente registado com este NIF." : $e->getMessage();
            $tipo_mensagem = "erro";
        } catch (Exception $e) {
            $mensagem = $e->getMessage();
            $tipo_mensagem = "erro";
        }
    }
}

// Preparar o valor do tipo de cliente com robustez (ignora maiúsculas e espaços)
$db_tipo = trim(strtolower($cliente['tipo_cliente'] ?? 'obra'));
?>
<!DOCTYPE html>
<html lang="pt-PT">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title><?= $modo_editar ? 'Editar' : 'Novo' ?> Cliente - Gestão MAB</title>
    <link rel="icon" type="image/png" href="mab.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { font-family: system-ui, -apple-system, sans-serif; background-color: #f7f9fc; margin: 0; color: #333; padding-bottom: 100px; }
        * { box-sizing: border-box; }
        
        .topbar { background-color: #fff; padding: 15px 40px; box-shadow: 0 4px 20px rgba(0,0,0,0.05); display: flex; justify-content: space-between; align-items: center; position: sticky; top: 0; z-index: 100; }
        .topbar-left .logo { height: 40px; }
        .topbar-center { display: flex; align-items: center; gap: 20px; flex: 1;}
        .topbar-right { display: flex; align-items: center; gap: 30px; }
        
        .btn-voltar { width: 40px; height: 40px; border-radius: 50%; background: #fff; border: 1px solid #e8ecf1; color: #576574; display: inline-flex; align-items: center; justify-content: center; text-decoration: none; font-size: 16px; transition: all 0.2s ease; box-shadow: 0 2px 6px rgba(0,0,0,0.04); }
        .btn-voltar:hover { background: #eef2f6; color: #34495e; transform: translateX(-2px); }
        
        .user-menu { position: relative; display: inline-block;}
        .user-btn { background: none; border: none; font-size: 15px; font-weight: 600; color: #2c3e50; cursor: pointer; display: flex; align-items: center; gap: 8px; }
        .user-avatar { background: #e74c3c; color: white; width: 28px; height: 28px; border-radius: 50%; display: flex; justify-content: center; align-items: center; font-size: 14px; border: 2px solid #fadbd8; }
        
        .dropdown-content { display: none; position: absolute; right: 0; top: 100%; margin-top: 10px; background: #fff; min-width: 180px; box-shadow: 0 10px 30px rgba(0,0,0,0.1); border-radius: 8px; overflow: hidden; border: 1px solid #f1f2f6; z-index:200;}
        .dropdown-content a { color: #576574; padding: 12px 20px; text-decoration: none; display: block; font-size: 14px; }
        .dropdown-content a:hover { background: #fdf2f2; color: #3498db; }
        .user-menu.aberto .dropdown-content { display: block; }

        .container { max-width: 800px; margin: 30px auto; padding: 0 15px; }
        
        .page-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px; flex-wrap: wrap; gap: 15px; }
        .page-title { margin: 0; color: #2c3e50; font-size: 24px; font-weight: 800; display: flex; align-items: center; gap: 10px; }
        .page-title i { color: #3498db; }

        .client-nav { display: flex; gap: 10px; align-items: center; }
        .btn-nav { background: #fff; color: #3498db; border: 1px solid #d6eaf8; padding: 8px 15px; border-radius: 8px; font-weight: 600; font-size: 13px; text-decoration: none; transition: 0.2s; display: flex; align-items: center; gap: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.02);}
        .btn-nav:hover { background: #ebf5fb; color: #2980b9; border-color: #3498db; }
        .btn-nav.disabled { background: #f8f9fa; color: #bdc3c7; border-color: #f1f2f6; cursor: not-allowed; pointer-events: none; box-shadow: none; }

        .alert { padding: 15px; border-radius: 8px; margin-bottom: 20px; font-weight: 600; text-align: center;}
        .alert-erro { background: #fdeded; color: #e74c3c; border: 1px solid #f9cccc; }
        .alert-sucesso { background: #ebf5fb; color: #3498db; border: 1px solid #d6eaf8; }

        .card { background: #fff; border-radius: 12px; box-shadow: 0 4px 15px rgba(0,0,0,0.03); border: 1px solid #e1e5ea; padding: 30px; }
        
        .form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
        .form-group { display: flex; flex-direction: column; }
        .form-group.full { grid-column: 1 / -1; }
        .form-group label { font-weight: 700; font-size: 11px; color: #576574; text-transform: uppercase; margin-bottom: 8px; display:flex; justify-content: space-between;}
        
        input, select { padding: 12px; border: 1px solid #dcdde1; border-radius: 8px; font-size: 14px; outline: none; transition: 0.2s; background: #fcfcfc;}
        input:focus, select:focus { border-color: #3498db; background: #fff; box-shadow: 0 0 0 3px rgba(52,152,219,0.1); }
        .input-error { border-color: #e74c3c !important; background: #fdeded !important; }

        .switch-container { display: flex; align-items: center; gap: 10px; margin-top: 10px; padding: 15px; background: #f8f9fa; border-radius: 8px; border: 1px solid #e1e5ea;}
        .switch { position: relative; display: inline-block; width: 44px; height: 24px; }
        .switch input { opacity: 0; width: 0; height: 0; }
        .slider { position: absolute; cursor: pointer; top: 0; left: 0; right: 0; bottom: 0; background-color: #bdc3c7; transition: .3s; border-radius: 34px; }
        .slider:before { position: absolute; content: ""; height: 18px; width: 18px; left: 3px; bottom: 3px; background-color: white; transition: .3s; border-radius: 50%; }
        input:checked + .slider { background-color: #27ae60; }
        input:checked + .slider:before { transform: translateX(20px); }

        .floating-action-bar { position: fixed; bottom: 25px; right: 25px; background: #fff; padding: 15px 25px; border-radius: 12px; box-shadow: 0 5px 25px rgba(0,0,0,0.15); border: 1px solid #e1e5ea; z-index: 100; }
        .btn-save { background: #3498db; color: #fff; padding: 12px 35px; border-radius: 8px; border: none; font-weight: 700; cursor: pointer; font-size: 14px; display: flex; align-items: center; gap: 10px; transition: 0.2s; text-transform: uppercase;}
        .btn-save:hover { background: #2980b9; transform: translateY(-2px); }

        @media (max-width: 768px) {
            .topbar { padding: 15px 20px; }
            .topbar-center { display: none; }
            .btn-back-text { display: none; }
            .form-grid { grid-template-columns: 1fr; }
            .floating-action-bar { bottom: 15px; right: 15px; left: 15px; justify-content: center; display: flex;}
            .btn-save { width: 100%; justify-content: center; }
            .client-nav { width: 100%; justify-content: space-between; }
            .btn-nav { flex: 1; justify-content: center; }
        }
    </style>
</head>
<body>

    <header class="topbar">
        <div class="topbar-left"><a href="index.php"><img src="mab.png" alt="MAB" class="logo"></a></div>
        <div class="topbar-center"></div> 
        <div class="topbar-right">
            <a href="clientes.php" class="btn-voltar" title="Voltar" aria-label="Voltar"><i class="fa-solid fa-arrow-left"></i></a>
            <div class="user-menu" id="userMenu">
                <button type="button" class="user-btn" onclick="event.stopPropagation(); document.getElementById('userMenu').classList.toggle('aberto');">
                    <div class="user-avatar"><i class="fa-solid fa-user"></i></div>
                    <?= htmlspecialchars($primeiro_nome) ?> 
                    <i class="fa-solid fa-chevron-down" style="font-size: 10px;"></i>
                </button>
                <div class="dropdown-content">
                    <a href="perfil.php"><i class="fa-solid fa-id-badge" style="width:20px;"></i> O meu perfil</a>
                    <?php if ($is_admin): ?>
                        <a href="configuracoes.php"><i class="fa-solid fa-gear" style="width:20px;"></i> Configurações</a>
                    <?php endif; ?>
                    <a href="index.php?logout=1" style="color: #e74c3c; border-top: 1px solid #f1f2f6;"><i class="fa-solid fa-right-from-bracket" style="width:20px;"></i> Sair</a>
                </div>
            </div>
        </div>
    </header>

    <div class="container">
        <?php if ($mensagem): ?>
            <div class="alert alert-<?= $tipo_mensagem ?>"><?= htmlspecialchars($mensagem) ?></div>
        <?php endif; ?>

        <div class="page-header">
            <h1 class="page-title">
                <?php if ($modo_editar): ?>
                    <i class="fa-solid fa-user-pen"></i> Cliente #<?= $id_edit ?>
                <?php else: ?>
                    <i class="fa-solid fa-user-plus"></i> Novo Cliente
                <?php endif; ?>
            </h1>
            
            <?php if ($modo_editar): ?>
            <div class="client-nav">
                <?php if ($prev_id): ?>
                    <a href="cliente_form.php?id=<?= $prev_id ?>" class="btn-nav" title="Cliente Anterior"><i class="fa-solid fa-arrow-left"></i> Anterior</a>
                <?php else: ?>
                    <span class="btn-nav disabled"><i class="fa-solid fa-arrow-left"></i> Anterior</span>
                <?php endif; ?>
                
                <?php if ($next_id): ?>
                    <a href="cliente_form.php?id=<?= $next_id ?>" class="btn-nav" title="Próximo Cliente">Próximo <i class="fa-solid fa-arrow-right"></i></a>
                <?php else: ?>
                    <span class="btn-nav disabled">Próximo <i class="fa-solid fa-arrow-right"></i></span>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>

        <form method="POST" id="formCliente" onsubmit="return validarFormulario()">
            <div class="card">
                <div class="form-grid">
                    
                    <div class="form-group full">
                        <label>Nome / Designação Social *</label>
                        <input type="text" name="nome" value="<?= htmlspecialchars($cliente['nome']) ?>" required placeholder="Ex: Condomínio Edifício XYZ, João Silva...">
                    </div>

                    <div class="form-group full">
                        <label>Tipo de Cliente *</label>
                        <select name="tipo_cliente" required style="font-weight: bold; color: #2c3e50;">
                            <option value="Obra" <?= ($db_tipo === 'obra') ? 'selected' : '' ?>>Cliente de Obra</option>
                            <option value="Pontual" <?= ($db_tipo === 'pontual') ? 'selected' : '' ?>>Cliente Pontual (Serviço Rápido / Único)</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>
                            NIF (Contribuinte) *
                            <span id="nif-aviso" style="color: #e74c3c; display: none;">NIF Inválido</span>
                        </label>
                        <input type="text" name="nif" id="nif_input" value="<?= htmlspecialchars($cliente['nif']) ?>" required maxlength="9" placeholder="Ex: 500123456" onblur="verificarNIF(this)">
                    </div>

                    <div class="form-group">
                        <label>Telefone / Telemóvel</label>
                        <input type="tel" name="telefone" id="telefone" value="<?= htmlspecialchars($cliente['telefone']) ?>" maxlength="9" placeholder="Ex: 910 000 000">
                    </div>

                    <div class="form-group full">
                        <label>Email de Contacto</label>
                        <input type="email" name="email" value="<?= htmlspecialchars($cliente['email']) ?>" placeholder="Ex: geral@empresa.pt">
                    </div>

                    <div class="form-group full" style="margin-top: 10px;">
                        <label>Morada de Faturação</label>
                        <input type="text" name="morada" value="<?= htmlspecialchars($cliente['morada']) ?>" placeholder="Rua, Número, Andar...">
                    </div>

                    <div class="form-group">
                        <label>Código Postal</label>
                        <input type="text" name="codigo_postal" id="codigo_postal" value="<?= htmlspecialchars($cliente['codigo_postal']) ?>" maxlength="8" placeholder="0000-000">
                    </div>

                    <div class="form-group">
                        <label>Cidade / Localidade</label>
                        <input type="text" name="cidade" value="<?= htmlspecialchars($cliente['cidade']) ?>" placeholder="Ex: Braga">
                    </div>

                    <div class="form-group full">
                        <div class="switch-container">
                            <label class="switch">
                                <input type="checkbox" name="ativo" <?= $cliente['ativo'] ? 'checked' : '' ?>>
                                <span class="slider"></span>
                            </label>
                            <div>
                                <strong style="color: #2c3e50; font-size:14px; display:block;">Cliente Ativo no Sistema</strong>
                                <span style="color: #7f8c8d; font-size:12px;">Se desativar, o cliente deixará de aparecer nas listas de seleção (orçamentos, obras, etc).</span>
                            </div>
                        </div>
                    </div>

                </div>
            </div>

            <div class="floating-action-bar">
                <button type="submit" class="btn-save"><i class="fa-solid fa-floppy-disk"></i> Guardar Cliente</button>
            </div>
        </form>
    </div>

    <script>
        document.addEventListener('click', () => document.getElementById('userMenu').classList.remove('aberto'));
        function apenasNumeros(e) { e.target.value = e.target.value.replace(/\D/g, ''); }
        document.getElementById('nif_input').addEventListener('input', apenasNumeros);
        document.getElementById('telefone').addEventListener('input', apenasNumeros);
        
        document.getElementById('codigo_postal').addEventListener('input', function (e) {
            let x = e.target.value.replace(/\D/g, '').match(/(\d{0,4})(\d{0,3})/);
            e.target.value = !x[2] ? x[1] : x[1] + '-' + x[2];
        });

        function jsValidarNIF(nif) { 
            nif = nif.replace(/\D/g, ''); 
            if (nif === '999999990' || nif === '666666666') return true;
            if (nif.length !== 9) return false; 
            
            if (['000000000', '111111111', '222222222', '333333333', '444444444', '555555555', '777777777', '888888888', '999999999'].includes(nif)) return false; 
            
            let primeiros_digitos = ['1', '2', '3', '4', '5', '6', '7', '8', '9'];
            if (!primeiros_digitos.includes(nif[0])) return false;

            let total = nif[0]*9 + nif[1]*8 + nif[2]*7 + nif[3]*6 + nif[4]*5 + nif[5]*4 + nif[6]*3 + nif[7]*2; 
            let modulo11 = total % 11; 
            let comparador = modulo11 < 2 ? 0 : 11 - modulo11; 
            return nif[8] == comparador; 
        }

        function verificarNIF(input) { 
            const aviso = document.getElementById('nif-aviso');
            if(input.value !== "" && !jsValidarNIF(input.value)) { 
                input.classList.add('input-error'); 
                aviso.style.display = 'inline';
            } else { 
                input.classList.remove('input-error'); 
                aviso.style.display = 'none';
            } 
        }

        function validarFormulario() { 
            const nif = document.getElementById('nif_input').value; 
            if(nif !== "" && !jsValidarNIF(nif)) { 
                alert("O NIF introduzido não é válido. Corrija antes de guardar."); 
                document.getElementById('nif_input').focus();
                return false; 
            } 
            
            const tel = document.getElementById('telefone').value.replace(/\D/g, '');
            if(tel !== "" && tel.length !== 9) {
                alert("O número de telefone deve ter exatamente 9 dígitos.");
                document.getElementById('telefone').focus();
                return false;
            }

            return true; 
        }
    </script>
</body>
</html>
