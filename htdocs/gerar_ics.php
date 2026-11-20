<?php
if (isset($_GET['id'])) {
    require_once 'db.php';
    $id = (int)$_GET['id'];
    
    $stmt = $pdo->prepare("SELECT a.*, c.nome as nome_cliente FROM malu_agendamentos a JOIN malu_clientes c ON a.cliente_id = c.id WHERE a.id = ?");
    $stmt->execute([$id]);
    $a = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($a) {
        $dtStart = date('Ymd\THis', strtotime($a['data_agendamento'] . ' ' . $a['hora_agendamento']));
        $dtEnd   = date('Ymd\THis', strtotime($a['data_agendamento'] . ' ' . $a['hora_agendamento'] . " +{$a['duracao_minutos']} minutes"));
        
        header('Content-Type: text/calendar; charset=utf-8');
        header('Content-Disposition: attachment; filename="agendamento.ics"');

        echo "BEGIN:VCALENDAR\r\n";
        echo "VERSION:2.0\r\n";
        echo "BEGIN:VEVENT\r\n";
        echo "SUMMARY:Malu Beauty: " . addslashes($a['nome_cliente']) . " (" . addslashes($a['servico']) . ")\r\n";
        echo "DTSTART:$dtStart\r\n";
        echo "DTEND:$dtEnd\r\n";
        echo "DESCRIPTION:" . addslashes($a['observacoes']) . "\r\n";
        echo "END:VEVENT\r\n";
        echo "END:VCALENDAR\r\n";
        exit;
    }
}