<?php
// MANUAL DE OPERAÇÃO E MANUTENÇÃO DO SISTEMA DE ALARME DE INTRUSÃO
// Gerado automaticamente a partir da ficha de intrusão (termos_intrusao + equipamentos + cliente).
ini_set('display_errors', 1);
error_reporting(E_ALL);

ob_start();
session_start();
require_once 'db.php';

if (!isset($_SESSION['user_id'])) { ob_end_clean(); die("Acesso negado."); }

require_once('fpdf/fpdf.php');

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$id) { ob_end_clean(); die("ID de termo não especificado."); }

// --- CONFIGURAÇÃO DA EMPRESA ---
$NOME_EMPRESA   = "m.Abreu - Instalações Técnicas, Unipessoal, Lda.";
$NIPC_EMPRESA   = "514414120";
$MORADA_EMPRESA = "Rua Damião de Góis, 205, Braga";
$EMAIL_EMPRESA  = "geral@mabreu.pt";
$REGISTO_PREVIO = "4944";

function pt($t) { return mb_convert_encoding((string)($t ?? ''), 'windows-1252', 'UTF-8'); }

function pegar($arr, $chaves, $default = '') {
    foreach ($chaves as $k) {
        if (isset($arr[$k]) && trim((string)$arr[$k]) !== '') return trim((string)$arr[$k]);
    }
    return $default;
}

function norm($s) {
    $s = mb_strtolower((string)$s, 'UTF-8');
    return strtr($s, ['á'=>'a','à'=>'a','ã'=>'a','â'=>'a','é'=>'e','ê'=>'e','í'=>'i','ó'=>'o','õ'=>'o','ô'=>'o','ú'=>'u','ç'=>'c']);
}

// --- DADOS ---
try {
    $stmt = $pdo->prepare("SELECT * FROM termos_intrusao WHERE id = ?");
    $stmt->execute([$id]);
    $termo = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$termo) { ob_end_clean(); die("Termo de Intrusão não encontrado."); }

    $cli = [];
    if (!empty($termo['id_cliente'])) {
        $s = $pdo->prepare("SELECT * FROM clientes WHERE id = ?");
        $s->execute([$termo['id_cliente']]);
        $cli = $s->fetch(PDO::FETCH_ASSOC) ?: [];
    }

    $s = $pdo->prepare("SELECT * FROM termos_intrusao_equipamentos WHERE id_termo = ? ORDER BY id ASC");
    $s->execute([$id]);
    $equipamentos = $s->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    ob_end_clean();
    die("Erro na Base de Dados: " . $e->getMessage());
}

$ref       = pegar($termo, ['referencia_termo'], 'ND');
$data_inst = !empty($termo['data_emissao']) ? date('d/m/Y', strtotime($termo['data_emissao'])) : date('d/m/Y');
$cliente   = pegar($cli, ['nome'], pegar($termo, ['nome_cliente']));
$nif       = pegar($cli, ['nif']);
$obra      = pegar($termo, ['nome_obra']);
$morada    = pegar($termo, ['morada_obra']);
$local     = trim($obra . ($morada !== '' ? ' — ' . $morada : ''));

$marca_c   = pegar($termo, ['marca_central']);
$modelo_c  = pegar($termo, ['ref_central', 'modelo_central']);
$serie_c   = pegar($termo, ['numero_serie']);
$local_c   = pegar($termo, ['localizacao_central']);
$grau      = pegar($termo, ['grau_seguranca', 'grau']);
$comunic   = pegar($termo, ['meio_transmissao', 'comunicacao', 'comunicador']);

// Equipamentos agrupados (tipo + marca + modelo) com localizações
$grupos = [];
$tem_sirene_ext = false;
$sirene_ext_txt = '';
foreach ($equipamentos as $eq) {
    $tipo   = pegar($eq, ['equipamento', 'tipo']);
    $marca  = pegar($eq, ['marca']);
    $modelo = pegar($eq, ['modelo']);
    $loc    = pegar($eq, ['localizacao']);
    if ($tipo === '' && $marca === '' && $modelo === '') continue;

    $n = norm($tipo);
    if (strpos($n, 'sirene') !== false && strpos($n, 'exterior') !== false) {
        $tem_sirene_ext = true;
        if ($sirene_ext_txt === '') $sirene_ext_txt = trim($marca . ' ' . $modelo);
    }
    $chave = $tipo . '|' . $marca . '|' . $modelo;
    if (!isset($grupos[$chave])) $grupos[$chave] = ['tipo'=>$tipo, 'marca'=>$marca, 'modelo'=>$modelo, 'qtd'=>0, 'locs'=>[]];
    $grupos[$chave]['qtd']++;
    if ($loc !== '') $grupos[$chave]['locs'][] = $loc;
}

// --- PDF ---
class PDF_MANUAL extends FPDF {
    public $titulo = '';
    function Header() {
        // A capa (página 1) é desenhada à parte; nas restantes o texto começa no topo
        if ($this->PageNo() > 1) $this->SetY(15);
    }
    function Footer() {
        if ($this->PageNo() == 1) return; // sem rodapé na capa
        $this->SetY(-14);
        $this->SetFont('Arial', '', 8);
        $this->SetTextColor(110, 110, 110);
        $this->Cell(0, 4, pt($this->titulo), 0, 0, 'L');
        $this->SetX(15);
        $this->Cell(0, 4, pt('Página ' . $this->PageNo() . ' de {nb}'), 0, 0, 'R');
        $this->SetTextColor(0, 0, 0);
    }
    function H1($t) {
        if ($this->GetY() > 255) $this->AddPage();
        $this->Ln(3);
        $this->SetFont('Arial', 'B', 11.5);
        $this->SetFillColor(0, 44, 119);
        $this->SetTextColor(255, 255, 255);
        $this->Cell(0, 7, pt('  ' . $t), 0, 1, 'L', true);
        $this->SetTextColor(0, 0, 0);
        $this->Ln(2);
    }
    function H2($t) {
        $this->Ln(1);
        $this->SetFont('Arial', 'B', 10);
        $this->Cell(0, 5.5, pt($t), 0, 1, 'L');
    }
    function P($t, $b = false) {
        $this->SetFont('Arial', $b ? 'B' : '', 10);
        $this->MultiCell(0, 5, pt($t), 0, 'J');
        $this->Ln(1);
    }
    function Item($t, $marcador = '-') {
        $this->SetFont('Arial', '', 10);
        $x = $this->GetX();
        $this->Cell(5, 5, pt($marcador), 0, 0, 'L');
        $this->MultiCell(0, 5, pt($t), 0, 'J');
    }
    function Par($rot, $val) {
        $this->SetFont('Arial', 'B', 10);
        $w = 38;
        $this->Cell($w, 5.5, pt($rot), 0, 0, 'L');
        $this->SetFont('Arial', '', 10);
        $this->MultiCell(0, 5.5, pt($val !== '' ? $val : '-'), 0, 'L');
    }
}

$pdf = new PDF_MANUAL('P', 'mm', 'A4');
$pdf->titulo = 'Manual do Sistema de Alarme — Ref. ' . $ref;
$pdf->AliasNbPages();
$pdf->SetMargins(15, 15, 15);
$pdf->SetAutoPageBreak(true, 18);
$pdf->AddPage();

// ---------------- CAPA ----------------
$pdf->SetAutoPageBreak(false);
if (file_exists('mab.png')) $pdf->Image('mab.png', 85, 28, 40);

$pdf->SetFillColor(0, 44, 119);
$pdf->Rect(0, 88, 210, 52, 'F');
$pdf->SetTextColor(255, 255, 255);
$pdf->SetFont('Arial', 'B', 24);
$pdf->SetXY(15, 98);
$pdf->Cell(180, 11, pt('MANUAL DO SISTEMA'), 0, 1, 'C');
$pdf->SetFont('Arial', '', 15);
$pdf->SetX(15);
$pdf->Cell(180, 8, pt('Operação e Manutenção'), 0, 1, 'C');
$pdf->SetFont('Arial', 'B', 15);
$pdf->SetX(15);
$pdf->Cell(180, 10, pt('SISTEMA DE ALARME DE INTRUSÃO'), 0, 1, 'C');
$pdf->SetTextColor(0, 0, 0);

$pdf->SetY(158);
$linhas_capa = [
    ['Cliente',              $cliente],
    ['Local da instalação',  $local],
    ['Data da instalação',   $data_inst],
    ['Ficha n.º',            $ref],
];
foreach ($linhas_capa as $lc) {
    $pdf->SetX(30);
    $pdf->SetFont('Arial', 'B', 11);
    $pdf->Cell(52, 7, pt($lc[0] . ':'), 0, 0, 'L');
    $pdf->SetFont('Arial', '', 11);
    $pdf->MultiCell(98, 7, pt($lc[1] !== '' ? $lc[1] : '-'), 0, 'L');
    $pdf->Ln(1);
}

$pdf->SetDrawColor(0, 44, 119);
$pdf->SetLineWidth(0.5);
$pdf->Line(15, 262, 195, 262);
$pdf->SetXY(15, 265);
$pdf->SetFont('Arial', 'B', 9.5);
$pdf->Cell(180, 5, pt($NOME_EMPRESA), 0, 1, 'C');
$pdf->SetFont('Arial', '', 9);
$pdf->SetX(15);
$pdf->Cell(180, 4.5, pt($MORADA_EMPRESA . ' | ' . $EMAIL_EMPRESA), 0, 1, 'C');
$pdf->SetX(15);
$pdf->Cell(180, 4.5, pt('NIPC ' . $NIPC_EMPRESA . ' | Registo Prévio PSP n.º ' . $REGISTO_PREVIO), 0, 1, 'C');
$pdf->SetLineWidth(0.2);
$pdf->SetDrawColor(0, 0, 0);
$pdf->SetAutoPageBreak(true, 18);

// ---------------- CONTEÚDO ----------------
$pdf->AddPage();
$pdf->H1('Identificação');

// Identificação
$pdf->Par('Empresa instaladora:', $NOME_EMPRESA . ' | NIPC ' . $NIPC_EMPRESA . ' | Registo Prévio n.º ' . $REGISTO_PREVIO);
$pdf->Par('Morada / Contacto:', $MORADA_EMPRESA . ' | ' . $EMAIL_EMPRESA);
$pdf->Par('Cliente:', $cliente . ($nif !== '' ? ' | NIF ' . $nif : ''));
$pdf->Par('Local da instalação:', $local);
$pdf->Par('Data da instalação:', $data_inst);
$pdf->Par('Ficha n.º:', $ref);

// 1. Caracterização
$pdf->H1('1. Caracterização Técnica do Sistema Instalado');
$pdf->P('Este sistema de segurança foi projetado e instalado de acordo com as normas aplicáveis a sistemas de alarme de intrusão (série EN 50131 e CLC/TS 50131-7).');

$central = trim($marca_c . ' ' . $modelo_c);
$pdf->Par('Central:', $central . ($serie_c !== '' ? ' (N.º série ' . $serie_c . ')' : '') . ($local_c !== '' ? ' — ' . $local_c : ''));
if ($grau !== '')   $pdf->Par('Grau de segurança:', $grau);
if ($comunic !== '') $pdf->Par('Transmissão:', $comunic);

$pdf->H2('Lista de equipamentos e componentes');
if (empty($grupos)) {
    $pdf->P('Sem equipamentos registados na ficha.');
} else {
    foreach ($grupos as $g) {
        $desc = $g['qtd'] . 'x ' . ($g['tipo'] !== '' ? $g['tipo'] : 'Equipamento');
        $mm = trim($g['marca'] . ' ' . $g['modelo']);
        if ($mm !== '') $desc .= ' (' . $mm . ')';
        if (!empty($g['locs'])) $desc .= ': ' . implode('; ', array_unique($g['locs'])) . '.';
        $pdf->Item($desc);
    }
}

// 2. Operação
$pdf->H1('2. Instruções de Operação');
$pdf->P('Os procedimentos abaixo são gerais. Os códigos de utilizador e a configuração dos tempos de entrada e saída são os definidos na entrega do sistema; em caso de dúvida contacte o instalador.');

$pdf->H2('2.1. Armar o sistema');
$pdf->Item('Confirme que não há movimento nas áreas protegidas e que as portas e janelas estão fechadas (o teclado deve indicar estado pronto).', '1.');
$pdf->Item('Introduza o seu código pessoal de utilizador no teclado ou aproxime o comando/tag.', '2.');
$pdf->Item('Selecione o modo de armar pretendido (total ou parcial). Inicia-se o tempo de saída: abandone o local de imediato.', '3.');

$pdf->H2('2.2. Desarmar o sistema');
$pdf->Item('Entre exclusivamente pelo acesso definido como percurso de entrada. O teclado emite o sinal sonoro de tempo de entrada.', '1.');
$pdf->Item('Introduza o seu código pessoal antes de terminar o tempo de entrada.', '2.');
$pdf->Item('O teclado confirma que o sistema foi desarmado.', '3.');

$pdf->H2('2.3. Procedimento em caso de falso alarme');
$pdf->Item('Introduza o seu código de utilizador no teclado para desarmar e silenciar as sirenes.', '1.');
$pdf->Item('Se o sistema estiver ligado a uma central de receção de alarmes, aguarde o contacto ou contacte de imediato a central de monitorização, indicando a sua palavra-passe, para cancelar a deslocação das autoridades.', '2.');
$pdf->Item('Identifique a causa do disparo (por exemplo, animais, correntes de ar, entrada acidental) antes de voltar a armar.', '3.');

// 3. Legal
$pdf->H1('3. Enquadramento Legal e Obrigações do Utilizador');
$pdf->P('Lei n.º 34/2013, de 16 de maio (regime do exercício da atividade de segurança privada), republicada pela Lei n.º 46/2019, de 8 de julho. Considera-se utilizador quem detém a posse do espaço protegido, independentemente do título (proprietário, arrendatário, etc.).');

$pdf->H2('3.1. Comunicação à autoridade policial (art. 11.º)');
if ($tem_sirene_ext) {
    $pdf->P('Este sistema inclui sirene audível do exterior' . ($sirene_ext_txt !== '' ? ' (' . $sirene_ext_txt . ')' : '') . '. Por isso, a respetiva instalação tem de ser comunicada.');
}
$pdf->Item('Os dispositivos de alarme com sirene audível do exterior, ou capazes de desencadear automaticamente chamadas para o número nacional de emergência ou das forças de segurança, devem ser comunicados à autoridade policial da área (PSP ou GNR) no prazo de cinco dias úteis posteriores à sua montagem.');
$pdf->Item('A comunicação é feita pelo proprietário ou utilizador do alarme e deve indicar o nome, a morada e o contacto das pessoas ou serviços que, de forma permanente ou por escala, podem desligar o dispositivo a qualquer momento.');
$pdf->Item('Havendo sirene exterior, o utilizador deve assegurar que essas pessoas ou serviços se deslocam ao local e repõem o alarme no prazo máximo de duas horas após a comunicação da autoridade policial.');
$pdf->Item('Para este efeito é entregue o impresso "Comunicação de Instalação de Alarme", pré-preenchido, acompanhado da declaração de instalação. Qualquer alteração dos contactos deve ser comunicada à autoridade policial.');

$pdf->H2('3.2. Falsos alarmes e chamadas automáticas');
$pdf->Item('O utilizador deve manter o sistema em bom estado e utilizá-lo corretamente, de modo a evitar disparos indevidos; a deslocação das forças de segurança por falso alarme pode originar custos e sanções nos termos da lei.');
$pdf->Item('É proibida a instalação de sistemas de alarme que desencadeiem chamadas telefónicas automáticas com mensagem pré-gravada para o número de emergência ou para as forças de segurança. Este sistema não deve ser configurado dessa forma.');

$pdf->H2('3.3. Instalação e monitorização');
$pdf->Item('A instalação foi efetuada por entidade com registo prévio junto da Direção Nacional da PSP (art. 4.º-A): ' . $NOME_EMPRESA . ', Registo Prévio n.º ' . $REGISTO_PREVIO . '.');
$pdf->Item('A ligação a central de receção e monitorização de alarmes, quando existente, deve ser feita a entidade devidamente licenciada para a atividade.');

// 4. Manutenção
$pdf->H1('4. Manutenção e Assistência');
$pdf->Item('Recomenda-se a manutenção preventiva periódica do sistema (baterias, sirenes, detetores e meios de comunicação), a qual não está incluída na instalação inicial.');
$pdf->Item('Teste o sistema regularmente e comunique de imediato qualquer avaria ou indicação de falha no teclado.');
$pdf->Item('Assistência técnica: ' . $NOME_EMPRESA . ' — ' . $EMAIL_EMPRESA . '.');

// Entrega
if ($pdf->GetY() > 245) $pdf->AddPage();
$pdf->Ln(8);
$pdf->SetFont('Arial', '', 10);
$pdf->Cell(0, 6, pt('Braga, ' . $data_inst), 0, 1, 'C');
$pdf->Ln(8);
$y = $pdf->GetY();
$pdf->Line(25, $y, 90, $y);
$pdf->Line(120, $y, 185, $y);
$pdf->SetXY(25, $y + 1);
$pdf->Cell(65, 5, pt('A entidade instaladora'), 0, 0, 'C');
$pdf->SetXY(120, $y + 1);
$pdf->Cell(65, 5, pt('O cliente (receção do manual)'), 0, 1, 'C');

ob_end_clean();
$pdf->Output('I', 'Manual_Sistema_Alarme_' . preg_replace('/[^A-Za-z0-9_-]/', '_', $ref) . '.pdf');
?>
