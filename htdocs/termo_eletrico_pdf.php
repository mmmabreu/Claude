<?php
// 1. REGRA DE OURO: Prevenir a corrupção do PDF por espaços em branco ou erros indesejados
ob_start();
session_start();
require_once 'db.php';

// INCLUIR AS BIBLIOTECAS
require_once('fpdf/fpdf.php'); 
require_once('fpdi/src/autoload.php');
use setasign\Fpdi\Fpdi;

// 2. REGRA DE OURO: Validação estrita de sessão
if (!isset($_SESSION['user_id'])) { 
    ob_end_clean();
    die("Acesso negado."); 
}

// 3. REGRA DE OURO: Validação rigorosa dos parâmetros de entrada (GET)
$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$tipo = $_GET['tipo'] ?? 'termo_execucao';

if (!$id) { 
    ob_end_clean();
    die("Erro: ID de documento inválido ou não fornecido."); 
}

try {
    // --- BUSCAR DADOS (COMUNS A TODOS OS PDFS) ---
    $sql = "SELECT t.*, 
                   c.nome as cliente_nome, c.telefone as cliente_telefone, c.email as cliente_email, 
                   c.nif as cliente_nif, c.morada as cliente_morada, c.codigo_postal as cliente_cp, c.cidade as cliente_cidade,
                   o.nome as obra_nome 
            FROM termos_eletrico t
            JOIN clientes c ON t.id_cliente = c.id
            JOIN obras o ON t.id_obra = o.id
            WHERE t.id = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$id]);
    $doc = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$doc) { 
        ob_end_clean();
        die("Erro: Documento não encontrado na base de dados."); 
    }

    // CARREGAR AS VÁRIAS INSTALAÇÕES
    $stmt_insts = $pdo->prepare("SELECT * FROM termos_eletrico_instalacoes WHERE id_termo = ?");
    $stmt_insts->execute([$id]);
    $instalacoes = $stmt_insts->fetchAll(PDO::FETCH_ASSOC);

} catch (Exception $e) {
    ob_end_clean();
    die("Erro na base de dados: " . htmlspecialchars($e->getMessage()));
}

// 4. REGRA DE OURO: Funções de processamento
function pt($texto) {
    $texto = (string)($texto ?? '');
    return iconv('UTF-8', 'windows-1252//TRANSLIT', $texto);
}

// --- FUNÇÃO PARA DETETAR MASCULINO/FEMININO DO NOME DA OBRA ---
function obterPreposicao($nome) {
    $nome = trim(mb_strtolower($nome, 'UTF-8'));
    $palavras = explode(' ', $nome);
    $p1 = preg_replace('/[^a-z0-9à-ú]/i', '', $palavras[0]); // Limpar pontuação
    
    $masculinos = ['centro', 'instituto', 'edificio', 'edifício', 'armazem', 'armazém', 'hospital', 'pavilhao', 'pavilhão', 'lar', 'ginasio', 'ginásio', 'parque', 'supermercado', 'mercado', 'talho', 'cafe', 'café', 'restaurante', 'hotel', 'stand', 'condominio', 'condomínio', 'agrupamento', 'colegio', 'colégio', 'clube', 'departamento', 'complexo', 'museu', 'estadio', 'estádio', 'banco', 'posto', 'teatro', 'cinema', 'aeroporto', 'terminal', 'jardim', 'infantario', 'infantário', 'campo'];
    
    $femininos = ['casa', 'escola', 'loja', 'clinica', 'clínica', 'fabrica', 'fábrica', 'quinta', 'herdade', 'oficina', 'farmacia', 'farmácia', 'associacao', 'associação', 'fundacao', 'fundação', 'junta', 'igreja', 'pensao', 'pensão', 'papelaria', 'pastelaria', 'padaria', 'creche', 'universidade', 'faculdade', 'sede', 'obra', 'habitacao', 'habitação', 'moradia', 'residencia', 'residência', 'instalacao', 'instalação', 'empresa', 'maternidade', 'biblioteca', 'piscina', 'garagem'];

    if (in_array($p1, $masculinos)) return "do ";
    if (in_array($p1, $femininos)) return "da ";

    // Deteção pela última letra se a palavra for desconhecida
    $ultima_letra = mb_substr($p1, -1);
    if ($ultima_letra === 'o') return "do ";
    if ($ultima_letra === 'a') return "da ";

    return "de "; // Fallback neutro (Ex: "de Braga")
}

// --- MAGIA ANTI-PESO: OTIMIZAÇÃO DE IMAGENS ---
function otimizarImagem($ficheiro) {
    if (!file_exists($ficheiro)) return false;
    $nome_otimizado = 'min_' . $ficheiro;
    if (file_exists($nome_otimizado)) return $nome_otimizado;
    
    $info = @getimagesize($ficheiro);
    if (!$info) return $ficheiro;
    
    $largura = $info[0];
    $altura = $info[1];
    $tipo = $info[2];
    $max_largura = 350;
    
    if ($largura <= $max_largura) return $ficheiro; 
    
    $nova_largura = $max_largura;
    $nova_altura = floor(($altura / $largura) * $nova_largura);
    $nova_img = imagecreatetruecolor($nova_largura, $nova_altura);
    
    if ($tipo == IMAGETYPE_PNG) {
        imagealphablending($nova_img, false);
        imagesavealpha($nova_img, true);
        $transparente = imagecolorallocatealpha($nova_img, 255, 255, 255, 127);
        imagefilledrectangle($nova_img, 0, 0, $nova_largura, $nova_altura, $transparente);
        $origem = @imagecreatefrompng($ficheiro);
    } elseif ($tipo == IMAGETYPE_JPEG) {
        $origem = @imagecreatefromjpeg($ficheiro);
    } else {
        return $ficheiro;
    }
    
    if ($origem) {
        if ($tipo == IMAGETYPE_PNG) imagealphablending($origem, true);
        imagecopyresampled($nova_img, $origem, 0, 0, 0, 0, $nova_largura, $nova_altura, $largura, $altura);
        if ($tipo == IMAGETYPE_PNG) imagepng($nova_img, $nome_otimizado, 9);
        else imagejpeg($nova_img, $nome_otimizado, 80);
        imagedestroy($nova_img);
        imagedestroy($origem);
        return $nome_otimizado;
    }
    return $ficheiro;
}

// INICIALIZAR PDF
$pdf = new Fpdi();
$pdf->AddPage();
$pdf->SetFont('Arial', '', 9);
$pdf->SetTextColor(0, 0, 0);

$data_formatada = "Braga, " . date('d/m/Y', strtotime($doc['data_emissao']));
$data_simples = date('d/m/Y', strtotime($doc['data_emissao'])); 

// =========================================================================
// DOCUMENTO 1: TERMO DE EXECUÇÃO NORMAL
// =========================================================================
if ($tipo === 'termo_execucao') {
    
    $pdf->setSourceFile('trexec.pdf'); 
    $templateId = $pdf->importPage(1);
    $pdf->useTemplate($templateId, 0, 0, 210);

    $nips = array_filter(array_column($instalacoes, 'nip'));
    $cpes = array_filter(array_column($instalacoes, 'cpe'));
    
    $pdf->SetXY(45, 52.5); $pdf->Write(0, pt($doc['cliente_nome']));
    $pdf->SetXY(45, 58);   $pdf->Write(0, pt($doc['cliente_telefone']));
    $pdf->SetXY(80, 58);   $pdf->Write(0, pt($doc['cliente_email']));
    $pdf->SetXY(166, 58);  $pdf->Write(0, pt($doc['cliente_nif']));

    $pdf->SetXY(45, 113.5); $pdf->Write(0, pt($doc['morada']));
    $pdf->SetXY(45, 119);   $pdf->Write(0, pt($doc['freguesia']));
    $pdf->SetXY(45, 124);   $pdf->Write(0, pt($doc['concelho']));
    $pdf->SetXY(125, 124);  $pdf->Write(0, pt($doc['distrito']));
    $pdf->SetXY(72, 129.5); $pdf->Write(0, pt($doc['tipo_estabelecimento']));

    $pdf->SetXY(45, 144);   $pdf->Write(0, pt(!empty($nips) ? implode(", ", $nips) : ''));
    $pdf->SetXY(45, 149.5); $pdf->Write(0, pt(!empty($cpes) ? implode(", ", $cpes) : ''));

    if ($doc['com_projeto'] === 'Sim') { $pdf->SetXY(137, 144); $pdf->Write(0, 'X'); }
    if ($doc['instalacao_nova_existente'] === 'Nova') { $pdf->SetXY(179, 144); $pdf->Write(0, 'X'); }
    if ($doc['instalacao_nova_existente'] === 'Existente') { $pdf->SetXY(179, 149.5); $pdf->Write(0, 'X'); }

    $pdf->SetXY(110, 200); $pdf->Write(0, pt($data_formatada));

    ob_end_clean();
    $pdf->Output('I', 'TRExec_' . pt($doc['obra_nome']) . '.pdf');

} 
// =========================================================================
// DOCUMENTO 2: TERMO DE EXECUÇÃO DE OBRAS
// =========================================================================
elseif ($tipo === 'termo_execucao_obras') {
    
    $pdf->setSourceFile('trexec_obras.pdf'); 
    $templateId = $pdf->importPage(1);
    $pdf->useTemplate($templateId, 0, 0, 210);

    $pdf->SetXY(40, 75);  $pdf->Write(0, pt($doc['cliente_nome']));
    $pdf->SetXY(175, 75); $pdf->Write(0, pt($doc['cliente_nif']));
    $pdf->SetXY(40, 80);  $pdf->Write(0, pt($doc['cliente_telefone']));
    $pdf->SetXY(80, 80);  $pdf->Write(0, pt($doc['cliente_email']));
    
    $morada_completa = $doc['cliente_morada'] . ($doc['cliente_cidade'] ? ' - ' . $doc['cliente_cidade'] : '');
    $pdf->SetXY(40, 86);  $pdf->Write(0, pt($morada_completa));
    $pdf->SetXY(40, 91);  $pdf->Write(0, pt($doc['cliente_cp']));

    $tipo_inst = (!empty($instalacoes) && isset($instalacoes[0])) ? $instalacoes[0]['tipo_instalacao'] : '';
    $pdf->SetXY(190, 121); $pdf->Write(0, pt($tipo_inst));

    $pdf->SetXY(40, 131.5); $pdf->Write(0, pt($doc['morada']));
    $pdf->SetXY(40, 136.5); $pdf->Write(0, pt($doc['freguesia']));
    $pdf->SetXY(128, 136.5); $pdf->Write(0, pt($doc['concelho']));
    $pdf->SetXY(40, 141.5); $pdf->Write(0, pt($doc['distrito']));
    $pdf->SetXY(138, 141.5); $pdf->Write(0, pt($doc['coordenadas']));
    $pdf->SetXY(64, 152.5); $pdf->Write(0, pt($doc['tipo_estabelecimento']));
    
    if ($doc['instalacao_nova_existente'] === 'Nova') {
        $pdf->SetXY(169.5, 152); $pdf->Write(0, 'x'); 
    } elseif ($doc['instalacao_nova_existente'] === 'Existente') {
        $pdf->SetXY(192, 152); $pdf->Write(0, 'x'); 
    }

    $linha_y = 168; 
    $soma_potencia = 0;

    foreach ($instalacoes as $inst) {
        $andar_fracao = trim($inst['andar'] . ' ' . $inst['fracao']);
        
        $pdf->SetXY(20, $linha_y);  $pdf->Write(0, pt($andar_fracao));
        $pdf->SetXY(40, $linha_y);  $pdf->Write(0, pt($inst['tipo_utilizacao']));
        $pdf->SetXY(128, $linha_y); $pdf->Write(0, pt($inst['entrada_imovel']));
        $pdf->SetXY(170, $linha_y); $pdf->Write(0, pt($inst['potencia']));

        $soma_potencia += (float)$inst['potencia_alimentar'];
        $linha_y += 6; 
    }

    $pdf->SetXY(170, 173); 
    $pdf->Write(0, pt(number_format($soma_potencia, 2)));

    $pdf->SetXY(110, 213); 
    $pdf->Write(0, pt($data_formatada));

    ob_end_clean();
    $pdf->Output('I', 'TRExec_Obras_' . pt($doc['obra_nome']) . '.pdf');

}
// =========================================================================
// DOCUMENTO 3: FICHA ELETROTÉCNICA
// =========================================================================
elseif ($tipo === 'ficha_eletrotecnica') {
    
    $pdf->setSourceFile('FE.pdf'); 
    $templateId = $pdf->importPage(1);
    $pdf->useTemplate($templateId, 0, 0, 210);
    
    $pdf->SetFont('Arial', '', 7);

    $pdf->SetXY(28, 41); $pdf->Write(0, pt($doc['cliente_nome']));
    $pdf->SetXY(28, 45.5); $pdf->Write(0, pt($doc['cliente_telefone']));
    $pdf->SetXY(28, 50); $pdf->Write(0, pt($doc['cliente_morada']));
    $pdf->SetXY(28, 55); $pdf->Write(0, pt($doc['cliente_cp']));
    $pdf->SetXY(80, 45.5); $pdf->Write(0, pt($doc['cliente_email']));
    $pdf->SetXY(173.5, 41); $pdf->Write(0, pt($doc['cliente_nif']));

    $pdf->SetXY(28, 87.5);  $pdf->Write(0, pt($doc['freguesia']));
    $pdf->SetXY(106, 87.5);  $pdf->Write(0, pt($doc['concelho']));
    $pdf->SetXY(173.5, 87.5); $pdf->Write(0, pt($doc['distrito']));
    $pdf->SetXY(57, 92.5);  $pdf->Write(0, pt($doc['morada']));
    $pdf->SetXY(173.5, 92.5); $pdf->Write(0, pt($doc['coordenadas']));

    $pdf->SetXY(50, 118.5); $pdf->Write(0, pt($doc['descricao_imovel']));
    $pdf->SetXY(50, 123.5); $pdf->Write(0, pt($doc['classificacao_instalacao']));

    $linha_y = 147;
    $soma_A = 0; $soma_B = 0; $soma_C = 0;
    $ramais_unicos = []; 

    foreach ($instalacoes as $inst) {
        $pdf->SetXY(19, $linha_y);  $pdf->Write(0, pt($inst['tipo_instalacao']));
        $pdf->SetXY(29.5, $linha_y);  $pdf->Write(0, pt($inst['entrada_imovel']));
        $pdf->SetXY(39.5, $linha_y);  $pdf->Write(0, pt($inst['ramal']));
        $pdf->SetXY(45, $linha_y);  $pdf->Write(0, pt($inst['nip']));
        $pdf->SetXY(58.5, $linha_y);  $pdf->Write(0, pt($inst['cpe']));
        $pdf->SetXY(88.5, $linha_y);  $pdf->Write(0, pt($inst['andar']));
        $pdf->SetXY(96, $linha_y); $pdf->Write(0, pt($inst['fracao']));
        
        $utilizacao_curta = substr(pt($inst['tipo_utilizacao']), 0, 18);
        $pdf->SetXY(123, $linha_y); $pdf->Write(0, $utilizacao_curta);
        
        $fase_abrev = '';
        if ($inst['entrada_fases'] === 'Monofásica') $fase_abrev = 'Mono';
        elseif ($inst['entrada_fases'] === 'Trifásica') $fase_abrev = 'Tri';
        else $fase_abrev = pt($inst['entrada_fases']);

        $pdf->SetXY(155, $linha_y); $pdf->Write(0, $fase_abrev);
        $pdf->SetXY(164, $linha_y); $pdf->Write(0, pt($inst['potencia']));
        $pdf->SetXY(177, $linha_y); $pdf->Write(0, pt($inst['fator_simultaneidade']));
        
        $potencia_alimentar_limpa = trim(str_ireplace([' kVA', 'kVA'], '', $inst['potencia_alimentar']));
        $pdf->SetXY(187, $linha_y); $pdf->Write(0, pt($potencia_alimentar_limpa));

        $potencia_linha = (float)$potencia_alimentar_limpa;
        if ($inst['tipo_instalacao'] === 'A') $soma_A += $potencia_linha;
        elseif ($inst['tipo_instalacao'] === 'B') $soma_B += $potencia_linha;
        elseif ($inst['tipo_instalacao'] === 'C') $soma_C += $potencia_linha;

        if (!empty($inst['ramal'])) {
            $ramais_unicos[] = $inst['ramal'];
        }

        $linha_y += 4.6; 
    }

    $total_ramais = count(array_unique($ramais_unicos));
    $pdf->SetXY(173.5, 123.5); $pdf->Write(0, pt((string)$total_ramais));
    $pdf->SetXY(173.5, 118.5); $pdf->Write(0, pt($doc['instalacao_nova_existente']));

    $pdf->SetXY(69, 223); $pdf->Write(0, pt(number_format($soma_A, 2)));
    $pdf->SetXY(69, 230); $pdf->Write(0, pt(number_format($soma_B, 2)));
    $pdf->SetXY(69, 237.5); $pdf->Write(0, pt(number_format($soma_C, 2)));

    $pdf->SetXY(110, 218); $pdf->Write(0, pt($data_formatada));

    ob_end_clean();
    $pdf->Output('I', 'FE_' . pt($doc['obra_nome']) . '.pdf');
}
// =========================================================================
// DOCUMENTO 4: DECLARAÇÃO DE AUTORIZAÇÃO A TERCEIROS (DAT)
// =========================================================================
elseif ($tipo === 'declaracao_autorizacao') {
    
    $pdf->setSourceFile('dat.pdf'); 
    
    $templateId = $pdf->importPage(1);
    $pdf->useTemplate($templateId, 0, 0, 210);

    $pdf->SetFont('Arial', '', 8);

    if ($doc['instalacao_nova_existente'] === 'Nova') {
        $pdf->SetXY(64, 46); $pdf->Write(0, 'X'); 
    } elseif ($doc['instalacao_nova_existente'] === 'Existente') {
        $pdf->SetXY(104, 46); $pdf->Write(0, 'X'); 
    }

    $cpe_dat = (!empty($instalacoes) && isset($instalacoes[0])) ? $instalacoes[0]['cpe'] : '';
    $pdf->SetXY(125, 46); $pdf->Write(0, pt($cpe_dat)); 

    $pdf->SetXY(64, 53.5);  $pdf->Write(0, pt($doc['morada']));
    $pdf->SetXY(64, 60.5);  $pdf->Write(0, pt($doc['freguesia']));
    $pdf->SetXY(64, 67.5); $pdf->Write(0, pt($doc['concelho']));
    $pdf->SetXY(150, 67.5);  $pdf->Write(0, pt($doc['coordenadas']));

    $pdf->SetXY(39, 87.5);  $pdf->Write(0, pt($doc['cliente_nome']));
    $pdf->SetXY(39, 94.5); $pdf->Write(0, pt($doc['cliente_telefone']));
    $pdf->SetXY(39, 101.7);  $pdf->Write(0, pt($doc['cliente_morada']));
    
    $pdf->SetXY(39, 109);  $pdf->Write(0, pt($doc['cliente_cp']));
    $pdf->SetXY(92, 94.5);  $pdf->Write(0, pt($doc['cliente_email']));
    $pdf->SetXY(154, 87.5); $pdf->Write(0, pt($doc['cliente_nif'])); 

    $pdf->SetXY(89.50, 251); $pdf->Write(0, pt($data_simples)); 

    ob_end_clean();
    $pdf->Output('I', 'DAT_' . pt($doc['obra_nome']) . '.pdf');
}
// =========================================================================
// DOCUMENTO 5: TERMO DE EXPLORAÇÃO EDIFÍCIOS RECEBENDO PÚBLICO (NATIVO)
// =========================================================================
elseif ($tipo === 'exploracao_edificios_publico') {

    $meses_nome = [1=>'janeiro', 2=>'fevereiro', 3=>'março', 4=>'abril', 5=>'maio', 6=>'junho', 7=>'julho', 8=>'agosto', 9=>'setembro', 10=>'outubro', 11=>'novembro', 12=>'dezembro'];

    $pdf->SetMargins(20, 20, 20);
    $pdf->SetAutoPageBreak(true, 20);

    $logo = otimizarImagem('mab.png');
    if ($logo && file_exists($logo)) {
        $pdf->Image($logo, 20, 15, 25);
    }
    
    $pdf->SetY(45); 

    $pdf->SetFont('Arial', 'B', 14);
    $pdf->Cell(0, 10, pt('TERMO DE RESPONSABILIDADE'), 0, 1, 'C');
    $pdf->Ln(6); 

    $prep_obra = obterPreposicao($doc['obra_nome']);

    $pdf->SetFont('Arial', 'B', 11);
    $pdf->Cell(45, 7, pt('Obra:'), 0, 0);
    $pdf->SetFont('Arial', '', 11);
    $pdf->Cell(0, 7, pt($doc['obra_nome']), 0, 1);

    $pdf->SetFont('Arial', 'B', 11);
    $pdf->Cell(45, 7, pt('Processo nº:'), 0, 0);
    $pdf->SetFont('Arial', '', 11);
    $pdf->Cell(0, 7, pt($doc['referencia_termo']), 0, 1);

    $pdf->SetFont('Arial', '', 11);
    $pdf->SetX(65); 
    $pdf->Cell(0, 7, pt('Instalação elétrica ' . $prep_obra . $doc['obra_nome']), 0, 1);
    $pdf->Ln(2);

    $pdf->SetFont('Arial', 'B', 11);
    $pdf->Cell(45, 7, pt('Cliente/Requerente:'), 0, 0);
    $pdf->SetFont('Arial', '', 11);
    $pdf->Cell(0, 7, pt($doc['cliente_nome']), 0, 1);

    $pdf->SetFont('Arial', 'B', 11);
    $pdf->Cell(45, 7, pt('Local da Obra:'), 0, 0);
    
    $pdf->SetFont('Arial', '', 11);
    $local_linha1 = trim($doc['morada']);
    $local_linha2 = trim($doc['concelho'] . ' - ' . $doc['distrito']);

    $pdf->Cell(0, 7, pt($local_linha1), 0, 1);
    $pdf->SetX(65); 
    $pdf->Cell(0, 7, pt($local_linha2), 0, 1);

    $pdf->Ln(8); 

    $texto_1 = "Eu Mário Manuel Martins Abreu, inscrito no colégio de Engenharia Eletrotécnica com o número 60930 e na Direção Geral de Energia e Geologia com o número 56678, portador do cartão de cidadão número 10607554 3ZV6 e contribuinte número 216363217, residente na Rua Damião de Góis, 205 em Braga, declaro que assumo toda a responsabilidade pela exploração da instalação elétrica " . $prep_obra . $doc['obra_nome'] . ".";

    $texto_2 = "Declaro ainda que esta instalação respeita a atual regulamentação - Decreto-Lei nº 226/2005, de 28 de dezembro (Regras Técnicas das Instalações Elétricas de Baixa Tensão) aprovado pela Portaria nº 949A/2006 de 11 de setembro e as demais normas aplicáveis.";

    $pdf->SetFont('Arial', '', 11);
    $pdf->MultiCell(0, 6, pt($texto_1), 0, 'J'); 
    $pdf->Ln(4);
    $pdf->MultiCell(0, 6, pt($texto_2), 0, 'J');

    $pdf->Ln(10); 

    $data_time = strtotime($doc['data_emissao']);
    $dia = date('d', $data_time);
    $mes_extenso = $meses_nome[(int)date('m', $data_time)];
    $ano = date('Y', $data_time);

    $pdf->SetFont('Arial', '', 11);
    $pdf->Cell(0, 6, pt("Braga, $dia de $mes_extenso de $ano"), 0, 1, 'L');
    $pdf->Ln(10); 

    $pdf->SetFont('Arial', 'B', 11);
    $pdf->Cell(0, 6, pt('O Técnico responsável:'), 0, 1, 'C');
    $pdf->Ln(12); 
    
    $y_assinatura = $pdf->GetY();

    $pdf->SetDrawColor(0, 0, 0); 
    $pdf->SetLineWidth(0.3);
    $pdf->Line(60, $y_assinatura, 150, $y_assinatura);

    $assina = otimizarImagem('assina.png');
    if ($assina && file_exists($assina)) {
        $img_width = 40;
        $img_height = 15;
        $x_center = (210 - $img_width) / 2; 
        $y_img = $y_assinatura - $img_height + 2; 
        
        $pdf->Image($assina, $x_center, $y_img, $img_width, $img_height);
    }

    $pdf->SetY($y_assinatura + 2);
    $pdf->Cell(0, 6, pt('(Técnico Responsável)'), 0, 1, 'C');

    $pdf->SetAutoPageBreak(false); 
    $pdf->SetY(-22); 
    
    $pdf->SetDrawColor(180, 180, 180);
    $pdf->SetLineWidth(0.3);
    $pdf->Line(20, $pdf->GetY(), 190, $pdf->GetY());
    $pdf->Ln(2);

    $pdf->SetFont('Arial', '', 8);
    $pdf->SetTextColor(100, 100, 100);

    $pdf->Cell(75, 4, pt('Nome: Mário Manuel Martins Abreu'), 0, 0, 'L');
    $pdf->Cell(40, 4, pt('DGEG: 56678'), 0, 0, 'C');
    $pdf->Cell(55, 4, pt('email: geral@mabreu.pt'), 0, 1, 'R');

    $pdf->Cell(75, 4, pt('NIF/CCidadão: 216 363 217 / 10 607 554'), 0, 0, 'L');
    $pdf->Cell(40, 4, pt('NIPC: 514 414 120'), 0, 0, 'C');
    $pdf->Cell(55, 4, pt('tlm. 912 854 950'), 0, 1, 'R');

    ob_end_clean();
    $pdf->Output('I', 'TermoExploracaoERP_' . pt($doc['obra_nome']) . '.pdf');
}
// =========================================================================
// DOCUMENTO 6 E 7: DECLARAÇÃO ALTERAÇÃO MONOFÁSICO / TRIFÁSICO (NATIVO)
// =========================================================================
elseif ($tipo === 'declaracao_mono_tri' || $tipo === 'declaracao_tri_mono') {

    $is_mono_tri = ($tipo === 'declaracao_mono_tri');
    $meses_nome = [1=>'janeiro', 2=>'fevereiro', 3=>'março', 4=>'abril', 5=>'maio', 6=>'junho', 7=>'julho', 8=>'agosto', 9=>'setembro', 10=>'outubro', 11=>'novembro', 12=>'dezembro'];

    $pdf->SetMargins(20, 20, 20);
    $pdf->SetAutoPageBreak(true, 20);

    // LOGÓTIPO a 80% (16mm)
    $logo = otimizarImagem('mab.png');
    if ($logo && file_exists($logo)) {
        $pdf->Image($logo, 20, 15, 16);
    }
    
    $pdf->SetY(45);

    // Título Principal
    $pdf->SetFont('Arial', 'B', 14);
    $pdf->Cell(0, 10, pt('- DECLARAÇÃO -'), 0, 1, 'C');
    $pdf->Ln(10);

    // Buscar o CPE à primeira instalação do termo
    $cpe = (!empty($instalacoes) && isset($instalacoes[0]['cpe'])) ? $instalacoes[0]['cpe'] : '';
    
    // Construir Morada exata da Obra para encaixar no texto
    $morada_obra = trim($doc['morada']);
    if (!empty($doc['freguesia'])) $morada_obra .= ' - ' . trim($doc['freguesia']);
    if (!empty($doc['concelho'])) $morada_obra .= ' - ' . trim($doc['concelho']);

    // Gerar o texto dinâmico dependendo da escolha
    $texto_alteracao = $is_mono_tri 
        ? "alteração da entrada da instalação de Monofásico para Trifásico, em que foi observada a Repartição de Cargas da Instalação" 
        : "alteração da entrada da instalação de Trifásico para Monofásico, em que foi observada a Repartição de Cargas da Coluna do Edifício";

    $texto = "Eu abaixo-assinado, Mário Manuel Martins Abreu, inscrito na Direção Geral de Energia e Geologia com o n°. 56678 portador do Cartão de Cidadão n°. 10607554 com validade até 25/1/2028, domiciliado na Rua António da Rocha, 22 4700-860 Braga ao serviço da empresa m.Abreu - Instalações Técnicas, Lda., declaro que tomo toda a responsabilidade pela execução das alterações das instalações elétricas da " . $texto_alteracao . ", que " . $doc['cliente_nome'] . " pretende levar a efeito na " . $morada_obra . " CPE " . $cpe . " de acordo com as disposições regulamentares em vigor.";

    $pdf->SetFont('Arial', '', 11);
    // Espaçamento entre linhas a 7 para ficar bonito e legível
    $pdf->MultiCell(0, 7, pt($texto), 0, 'J'); 
    
    $pdf->Ln(15);

    // Data por extenso
    $data_time = strtotime($doc['data_emissao']);
    $dia = date('d', $data_time);
    $mes_extenso = $meses_nome[(int)date('m', $data_time)];
    $ano = date('Y', $data_time);

    $pdf->Cell(0, 6, pt("Braga, $dia de $mes_extenso de $ano"), 0, 1, 'L');
    $pdf->Ln(20);

    // Bloco de Assinaturas e Carimbo
    $y_assinatura = $pdf->GetY();
    
    // Dados da Empresa (Esquerda)
    $pdf->SetFont('Arial', 'B', 10);
    $pdf->Cell(0, 5, pt('m.Abreu - Instalações Técnicas, Lda'), 0, 1, 'L');
    $pdf->SetFont('Arial', '', 9);
    $pdf->Cell(0, 5, pt('Rua Damião de Góis, 205'), 0, 1, 'L');
    $pdf->Cell(0, 5, pt('4700-028 Braga'), 0, 1, 'L');
    $pdf->Cell(0, 5, pt('NIPC: 514 414 120'), 0, 1, 'L');
    
    // Linha de assinatura
    $pdf->Ln(10);
    $y_linha = $pdf->GetY();
    $pdf->SetDrawColor(0, 0, 0); 
    $pdf->SetLineWidth(0.3);
    $pdf->Line(20, $y_linha, 80, $y_linha);

    // Imagem da Assinatura
    $assina = otimizarImagem('assina.png');
    if ($assina && file_exists($assina)) {
        // Encostar a assinatura em cima da linha
        $pdf->Image($assina, 30, $y_linha - 13, 35, 12);
    }

    // Identificação do Técnico
    $pdf->SetY($y_linha + 2);
    $pdf->SetFont('Arial', 'B', 10);
    $pdf->Cell(60, 5, pt('Técnico Responsável'), 0, 1, 'C');
    $pdf->SetFont('Arial', '', 9);
    $pdf->Cell(60, 5, pt('(Eng. Mário Abreu)'), 0, 1, 'C');
    $pdf->Cell(60, 5, pt('(DGEG 56678)'), 0, 1, 'C');

    ob_end_clean();
    $nome_doc = $is_mono_tri ? 'Mono_Tri' : 'Tri_Mono';
    $pdf->Output('I', 'Declaracao_' . $nome_doc . '_' . pt($doc['obra_nome']) . '.pdf');
}
else {
    ob_end_clean();
    die("Erro: Tipo de documento desconhecido.");
}
?>