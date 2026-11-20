<?php
// Configurações da base de dados
// NOTA: Terá de substituir estes valores pelos do seu painel de alojamento
$host = '***'; 
$dbname = '***';
$username = '***'; // Substitua pelo utilizador do seu alojamento (ex: if0_41562631)
$password = '***';     // Substitua pela palavra-passe da base de dados do alojamento

try {
    // Criar a ligação PDO à base de dados
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    
    // Configurar para mostrar os erros de forma clara (ajuda muito na programação)
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Configurar para devolver dados como arrays associativos por defeito
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    // Em caso de erro, parar a execução e mostrar a mensagem
    die("Erro de ligação à base de dados: " . $e->getMessage());
}
?>