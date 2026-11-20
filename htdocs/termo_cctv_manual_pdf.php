<?php
// MANUAL DE OPERAÇÃO E MANUTENÇÃO DO SISTEMA DE VIDEOVIGILÂNCIA (CCTV)
// Gerado automaticamente a partir da ficha CCTV (termos_cctv + camaras_json + cliente).
// Por segurança, este manual NUNCA imprime palavras-passe, utilizadores nem IPs (ficam só na ficha técnica).
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
    $stmt = $pdo->prepare("SELECT * FROM termos_cctv WHERE id = ?");
    $stmt->execute([$id]);
    $termo = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$termo) { ob_end_clean(); die("Termo de CCTV não encontrado."); }

    $cli = [];
    if (!empty($termo['id_cliente'])) {
        $s = $pdo->prepare("SELECT * FROM clientes WHERE id = ?");
        $s->execute([$termo['id_cliente']]);
        $cli = $s->fetch(PDO::FETCH_ASSOC) ?: [];
    }
} catch (Exception $e) {
    ob_end_clean();
    die("Erro na Base de Dados: " . $e->getMessage());
}

$camaras = [];
if (!empty($termo['camaras_json'])) {
    $camaras = json_decode($termo['camaras_json'], true) ?: [];
}

$ref       = pegar($termo, ['referencia_termo'], 'ND');
$data_inst = !empty($termo['data_emissao']) ? date('d/m/Y', strtotime($termo['data_emissao'])) : date('d/m/Y');
$cliente   = pegar($cli, ['nome'], pegar($termo, ['nome_cliente']));
$nif       = pegar($cli, ['nif']);
$obra      = pegar($termo, ['nome_obra']);
$morada    = pegar($termo, ['morada_obra']);
$local     = trim($obra . ($morada !== '' ? ' — ' . $morada : ''));

$marca_g   = pegar($termo, ['marca_gravador']);
$modelo_g  = pegar($termo, ['modelo_gravador']);
$serie_g   = pegar($termo, ['numero_serie']);
$app       = pegar($termo, ['programa_visualizacao']);
$tem_remoto = (pegar($termo, ['dominio', 'anydesk_id', 'codigo_acesso_remoto', 'porta_ext_http', 'porta_ext_tcp', 'porta_ext_https']) !== '' || $app !== '');

// Câmaras agrupadas por marca + modelo (sem credenciais)
$grupos = [];
foreach ($camaras as $cam) {
    if (!is_array($cam)) continue;
    $marca  = pegar($cam, ['marca']);
    $modelo = pegar($cam, ['modelo']);
    if ($marca === '' && $modelo === '') continue;
    $chave = norm($marca . '|' . $modelo);
    if (!isset($grupos[$chave])) $grupos[$chave] = ['marca'=>$marca, 'modelo'=>$modelo, 'qtd'=>0, 'nums'=>[]];
    $grupos[$chave]['qtd']++;
    $num = pegar($cam, ['numero']);
    if ($num !== '') $grupos[$chave]['nums'][] = $num;
}
$n_camaras = 0;
foreach ($grupos as $g) $n_camaras += $g['qtd'];

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
        if ($this->GetY() > 262) $this->AddPage(); // evita título sozinho no fim da página
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
$pdf->titulo = 'Manual do Sistema de Videovigilância — Ref. ' . $ref;
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
$pdf->Cell(180, 10, pt('SISTEMA DE VIDEOVIGILÂNCIA (CCTV)'), 0, 1, 'C');
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

$pdf->Par('Empresa instaladora:', $NOME_EMPRESA . ' | NIPC ' . $NIPC_EMPRESA . ' | Registo Prévio n.º ' . $REGISTO_PREVIO);
$pdf->Par('Morada / Contacto:', $MORADA_EMPRESA . ' | ' . $EMAIL_EMPRESA);
$pdf->Par('Cliente:', $cliente . ($nif !== '' ? ' | NIF ' . $nif : ''));
$pdf->Par('Local da instalação:', $local);
$pdf->Par('Data da instalação:', $data_inst);
$pdf->Par('Ficha n.º:', $ref);

// 1. Caracterização
$pdf->H1('1. Caracterização Técnica do Sistema Instalado');
$pdf->P('Este sistema de videovigilância foi projetado e instalado de acordo com as normas aplicáveis a sistemas de videovigilância para aplicações de segurança (série EN 62676, em particular a EN 62676-4 relativa às regras de aplicação).');

$gravador = trim($marca_g . ' ' . $modelo_g);
$pdf->Par('Videogravador:', $gravador . ($serie_g !== '' ? ' (N.º série ' . $serie_g . ')' : ''));
$pdf->Par('N.º de câmaras:', $n_camaras > 0 ? (string)$n_camaras : '');
if ($app !== '') $pdf->Par('Aplicação / software:', $app);

$pdf->H2('Lista de câmaras');
if (empty($grupos)) {
    $pdf->P('Sem câmaras registadas na ficha.');
} else {
    foreach ($grupos as $g) {
        $desc = $g['qtd'] . 'x Câmara de videovigilância';
        $mm = trim($g['marca'] . ' ' . $g['modelo']);
        if ($mm !== '') $desc .= ' (' . $mm . ')';
        if (!empty($g['nums'])) $desc .= ': ' . (count($g['nums']) > 1 ? 'canais/câmaras ' : 'canal/câmara ') . implode(', ', array_unique($g['nums'])) . '.';
        $pdf->Item($desc);
    }
}
$pdf->Ln(1);
$pdf->P('Os dados de acesso (utilizadores, palavras-passe, endereços IP e portas) constam da ficha de instalação entregue em separado e não são reproduzidos neste manual. Guarde-os em local seguro.');

// 2. Operação
$pdf->H1('2. Instruções de Operação');
$pdf->P('Os procedimentos abaixo são gerais e podem variar ligeiramente consoante o modelo do videogravador e a versão da aplicação. Em caso de dúvida contacte o instalador.');

$pdf->H2('2.1. Visualização em direto');
$pdf->Item('No monitor ligado ao videogravador: as câmaras são apresentadas em mosaico; clique duas vezes numa imagem para a ver em ecrã inteiro e novamente para voltar ao mosaico.', '1.');
$pdf->Item('No telemóvel, tablet ou computador' . ($app !== '' ? ' (aplicação ' . $app . ')' : '') . ': inicie sessão com o utilizador que lhe foi atribuído e selecione o dispositivo e a câmara pretendida.', '2.');

$pdf->H2('2.2. Reprodução de gravações');
$pdf->Item('Aceda ao menu de reprodução (Playback) do videogravador ou da aplicação.', '1.');
$pdf->Item('Escolha a(s) câmara(s), o dia e a hora pretendidos; utilize a barra temporal para localizar o momento e os controlos de avanço/recuo.', '2.');
$pdf->Item('Sempre que disponível, utilize a pesquisa por eventos (deteção de movimento ou inteligente) para encontrar mais rapidamente as ocorrências.', '3.');

$pdf->H2('2.3. Exportação de imagens');
$pdf->Item('Insira uma pen USB no videogravador, selecione o período a exportar no menu de reprodução/cópia de segurança e confirme a exportação.', '1.');
$pdf->Item('As imagens exportadas só podem ser entregues às autoridades judiciárias ou policiais (ver ponto 3.4). Elimine as cópias que deixem de ser necessárias.', '2.');

if ($tem_remoto) {
    $pdf->H2('2.4. Acesso remoto');
    $pdf->Item('O acesso remoto depende da ligação à Internet do local. Se o acesso falhar, verifique primeiro se o router e o videogravador estão ligados.');
    $pdf->Item('A substituição do router ou a mudança de operador de telecomunicações pode obrigar a reconfigurar o acesso remoto; contacte o instalador antes de efetuar essas alterações.');
}

$pdf->H2('Boas práticas de utilização');
$pdf->Item('Altere as palavras-passe entregues na instalação, utilize palavras-passe fortes e não as partilhe; crie utilizadores distintos para cada pessoa com acesso.');
$pdf->Item('Mantenha o videogravador ligado à corrente (de preferência protegido por UPS), num local ventilado e de acesso restrito.');
$pdf->Item('Confirme periodicamente que a data e a hora do videogravador estão corretas; são essenciais para o valor probatório das gravações.');
$pdf->Item('Verifique regularmente se todas as câmaras apresentam imagem e se o disco está a gravar (indicação de erro de disco ou "sem gravação").');

// 3. Legal
$pdf->H1('3. Enquadramento Legal e Obrigações do Utilizador');
$pdf->P('A captação e gravação de imagens de pessoas constitui tratamento de dados pessoais, regulado pelo Regulamento Geral sobre a Proteção de Dados (RGPD — Regulamento (UE) 2016/679) e pela Lei n.º 58/2019, de 8 de agosto (em especial o art. 19.º). A atividade de segurança privada rege-se pela Lei n.º 34/2013, de 16 de maio, republicada pela Lei n.º 46/2019, de 8 de julho. O responsável pelo tratamento é o utilizador do sistema (proprietário ou quem detém a posse do espaço).');

$pdf->H2('3.1. Áreas que podem e não podem ser filmadas');
$pdf->Item('As câmaras devem captar apenas o espaço do utilizador, na medida do estritamente necessário à proteção de pessoas e bens.');
$pdf->Item('Não podem incidir sobre a via pública, propriedades vizinhas, nem sobre o interior de instalações sanitárias, balneários, vestiários ou zonas de descanso.');
$pdf->Item('Não podem captar teclados de introdução de códigos (multibanco, alarmes, controlo de acessos) de forma a permitir a leitura dos códigos.');
$pdf->Item('Se o enquadramento captar inevitavelmente parte da via pública ou de propriedade alheia, essa área deve ser ocultada através de máscaras de privacidade.');

$pdf->H2('3.2. Gravação de som');
$pdf->Item('É proibida a captação de som, exceto quando as instalações estejam encerradas ou mediante autorização prévia da Comissão Nacional de Proteção de Dados (CNPD). A gravação de som deve manter-se desativada.');

$pdf->H2('3.3. Informação obrigatória (aviso)');
$pdf->Item('Nos locais objeto de videovigilância deve ser afixado, em local bem visível e antes da zona filmada, um aviso com o pictograma de câmara e a menção "Para sua proteção, este local é objeto de videovigilância".');
$pdf->Item('O aviso deve identificar o responsável pelo tratamento (nome/denominação e contacto) a quem os titulares podem dirigir-se para exercer os seus direitos.');

$pdf->H2('3.4. Conservação e acesso às imagens');
$pdf->Item('As gravações devem ser conservadas pelo prazo máximo de 30 dias, findo o qual devem ser destruídas no prazo de 48 horas, salvo se forem necessárias para efeitos de processo penal.');
$pdf->Item('O acesso às imagens deve ser restrito às pessoas autorizadas. As gravações só podem ser utilizadas nos termos da lei penal, sendo entregues às autoridades judiciárias ou policiais, juntamente com a respetiva participação; não podem ser divulgadas publicamente (por exemplo, em redes sociais).');
$pdf->Item('Não é necessária autorização prévia da CNPD para instalar o sistema, mas o responsável deve cumprir as obrigações do RGPD (registo das atividades de tratamento quando aplicável, segurança e direitos dos titulares).');

$pdf->H2('3.5. Locais de trabalho');
$pdf->Item('Se existirem trabalhadores, as câmaras não podem ser utilizadas para controlar o seu desempenho profissional (Código do Trabalho, art. 20.º e 21.º). Os trabalhadores devem ser informados da existência e finalidade do sistema.');

$pdf->H2('3.6. Instalação');
$pdf->Item('A instalação foi efetuada por entidade com registo prévio junto da Direção Nacional da PSP: ' . $NOME_EMPRESA . ', Registo Prévio n.º ' . $REGISTO_PREVIO . '.');
$pdf->Item('O cumprimento das obrigações de proteção de dados durante a utilização do sistema (enquadramentos, avisos, prazos de conservação e acessos) é da responsabilidade do utilizador.');

// 4. Manutenção
$pdf->H1('4. Manutenção e Assistência');
$pdf->Item('Recomenda-se a manutenção preventiva periódica (limpeza das câmaras, verificação de enquadramentos e focagem, estado do disco, atualizações de firmware e da fonte de alimentação/UPS), a qual não está incluída na instalação inicial.');
$pdf->Item('Todas as intervenções no sistema devem ser registadas no Livro de Registos do Sistema entregue com a instalação.');
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
$pdf->Output('I', 'Manual_Sistema_CCTV_' . preg_replace('/[^A-Za-z0-9_-]/', '_', $ref) . '.pdf');
?>
