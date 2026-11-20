<?php
session_start();
require_once 'db.php';

// Segurança
if (!isset($_SESSION['user_id'])) {
    echo json_encode([]);
    exit;
}

$busca = trim($_GET['q'] ?? '');

if (strlen($busca) < 2) {
    echo json_encode([]);
    exit;
}

try {
    // Verifica se já criaste a coluna 'tags_pesquisa' na base de dados
    $stmt_cols = $pdo->query("SHOW COLUMNS FROM tabela_precos LIKE 'tags_pesquisa'");
    $has_tags = $stmt_cols->fetch();

    // A MAGIA ACONTECE AQUI:
    // Esta expressão regular ignora espaços, barras, traços e separa SEMPRE letras de números.
    // Exemplo: "vd/16mm" ou "vd16" ou "vd 16 mm" -> transforma-se num array limpo: ['vd', '16', 'mm']
    preg_match_all('/[a-zA-ZÀ-ÿ]+|[0-9]+/u', $busca, $matches);
    $tokens = $matches[0];

    // Se por algum motivo não houver tokens, devolve vazio
    if (empty($tokens)) {
        echo json_encode([]);
        exit;
    }

    $where_clauses = [];
    $params = [];

    // Constrói a query para que TODAS as palavras/números tenham de existir no artigo
    foreach ($tokens as $index => $token) {
        $p_name = 't' . $index; // Nome do parâmetro dinâmico (t0, t1, t2...)
        
        if ($has_tags) {
            $where_clauses[] = "(referencia LIKE :$p_name OR descricao LIKE :$p_name OR tags_pesquisa LIKE :$p_name)";
        } else {
            $where_clauses[] = "(referencia LIKE :$p_name OR descricao LIKE :$p_name)";
        }
        
        $params[$p_name] = '%' . $token . '%';
    }

    // Junta as condições com AND (tem de ter 'vd' AND '16')
    $sql = "SELECT id, referencia, descricao, unidade, preco_custo, tempo_mao_obra 
            FROM tabela_precos 
            WHERE " . implode(' AND ', $where_clauses) . " 
            ORDER BY descricao ASC 
            LIMIT 20";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $resultados = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode($resultados);

} catch (Exception $e) {
    // Em caso de erro, devolve um array vazio para não quebrar o formulário
    echo json_encode([]);
}
?>