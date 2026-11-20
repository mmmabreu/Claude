<?php
// REGRA DE OURO: Limpar o buffer para evitar erros na geração do PDF
ob_start();
session_start();
require_once 'db.php';
require_once('fpdf/fpdf.php');

if (!isset($_SESSION['user_id'])) { 
    ob_end_clean(); 
    die("Acesso negado."); 
}

if (!isset($_GET['id']) || empty($_GET['id'])) {
    ob_end_clean();
    die("ID do documento não especificado.");
}

$id_termo = (int)$_GET['id'];
$doc_solicitado = $_GET['doc'] ?? 'termo';

// --- LER DADOS DO TERMO PRINCIPAL ---
$sql = "SELECT t.*, o.nome as nome_obra, o.referencia_obra as obra_ref, o.morada as obra_morada 
        FROM termos_scie t 
        LEFT JOIN obras o ON t.id_obra = o.id 
        WHERE t.id = ?";
$stmt = $pdo->prepare($sql);
$stmt->execute([$id_termo]);
$termo = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$termo) {
    ob_end_clean();
    die("Documento não encontrado.");
}

$tipo_documento = $termo['tipo_documento'];
$tipo_intervencao = $termo['tipo_intervencao'] ?? 'manutencao';
$dados_json = json_decode($termo['dados_json'], true) ?: [];

$cliente = $dados_json['cliente'] ?? [];
$observacoes_gerais_list = $dados_json['observacoes'] ?? [];

// --- LER EQUIPAMENTOS (COM ORDENAÇÃO INTELIGENTE ALFANUMÉRICA) ---
// A Central de Incêndio (numero = 'C') converte-se em 0 e fica sempre em primeiro lugar.
$stmt_items = $pdo->prepare("SELECT * FROM termos_scie_items WHERE id_termo = ? ORDER BY CAST(numero AS UNSIGNED) ASC, numero ASC, id ASC");
$stmt_items->execute([$id_termo]);
$equipamentos = $stmt_items->fetchAll(PDO::FETCH_ASSOC);

$data_emissao = date('d/m/Y', strtotime($termo['data_emissao']));

// --- TÍTULO E AÇÕES DINÂMICAS ---
if ($tipo_intervencao === 'instalacao') {
    $txt_acao = 'instalou';
    $txt_estado = 'instalados';
    $titulo_relatorio = "Relatório de Instalação";
} else {
    $txt_acao = 'verificou';
    $txt_estado = 'verificados';
    $titulo_relatorio = "Relatório de Manutenção Preventiva";
}

$tipos_doc_nomes = [
    'portas_fogo' => 'Portas e Envidraçados Resistentes ao Fogo',
    'compartimentacao' => 'Sistemas de Compartimentação',
    'sadi' => 'SADI - Deteção de Incêndio',
    'deteccao_gas' => 'Sistemas de Deteção de Gás',
    'extincao_agua' => 'Sistemas de Extinção por Água',
    'sinalizacao' => 'Sinalização de Segurança',
    'iluminacao_emergencia' => 'Iluminação de Emergência'
];
$nome_especialidade = $tipos_doc_nomes[$tipo_documento] ?? 'Equipamentos SCIE';

$resumo_equipamentos = [];
if ($doc_solicitado === 'termo') {
    foreach ($equipamentos as $eq) {
        // Linhas "Não Instalado" não podem constar no Termo de Responsabilidade
        // (comparação sem maiúsculas/minúsculas e sem acentos: "Não Instalado", "nao instalado", etc.)
        $desc_norm = strtr(mb_strtolower(trim($eq['descricao'] ?? ''), 'UTF-8'), ['ã' => 'a', 'á' => 'a', 'à' => 'a', 'â' => 'a']);
        if (strpos($desc_norm, 'nao instalado') !== false) continue;

        $chave = trim($eq['descricao']).'|'.trim($eq['referencia']).'|'.trim($eq['marca']);
        if (!isset($resumo_equipamentos[$chave])) {
            $resumo_equipamentos[$chave] = ['desc' => $eq['descricao'], 'ref' => $eq['referencia'], 'marca' => $eq['marca'], 'qtd' => 0];
        }
        $resumo_equipamentos[$chave]['qtd']++;
    }
}

$local_obra = 'Braga'; 
if (!empty($termo['obra_morada'])) {
    $partes = explode(',', $termo['obra_morada']);
    foreach (array_reverse($partes) as $p) {
        $p_limpa = trim(preg_replace('/^[0-9\-\sNnºº.]+/i', '', $p));
        if (strlen($p_limpa) > 2) { $local_obra = $p_limpa; break; }
    }
}

// ---------------------------------------------------------
// FUNÇÕES AUXILIARES PARA PDF
// ---------------------------------------------------------
function pt($texto) {
    return mb_convert_encoding((string)($texto ?? ''), 'windows-1252', 'UTF-8');
}

function truncate($text, $length) {
    if (mb_strlen($text, 'UTF-8') > $length) {
        return mb_substr($text, 0, $length - 1, 'UTF-8') . '.';
    }
    return $text;
}

// ---------------------------------------------------------
// MAGIA ANTI-PESO: FUNÇÃO PARA REDUZIR RESOLUÇÃO DAS IMAGENS
// ---------------------------------------------------------
function otimizarImagem($ficheiro) {
    if (!file_exists($ficheiro)) return false;
    
    $nome_otimizado = 'min_' . $ficheiro;
    
    // Se a imagem miniatura já foi gerada no passado, usa-a e poupa recursos!
    if (file_exists($nome_otimizado)) {
        return $nome_otimizado;
    }

    $info = @getimagesize($ficheiro);
    if (!$info) return $ficheiro; // Se não conseguir ler, devolve a original
    
    $largura = $info[0];
    $altura = $info[1];
    $tipo = $info[2]; // 2 = JPG, 3 = PNG
    
    $max_largura = 350; // Largura limite em pixels (perfeita para imprimir a poucos centímetros)
    
    // Se a imagem já for pequena, não vale a pena fazer nada
    if ($largura <= $max_largura) {
        return $ficheiro; 
    }
    
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
        return $ficheiro; // Ignora se for outro formato
    }
    
    if ($origem) {
        if ($tipo == IMAGETYPE_PNG) imagealphablending($origem, true);
        imagecopyresampled($nova_img, $origem, 0, 0, 0, 0, $nova_largura, $nova_altura, $largura, $altura);
        
        if ($tipo == IMAGETYPE_PNG) {
            imagepng($nova_img, $nome_otimizado, 9); // Nível máximo de compressão PNG
        } else {
            imagejpeg($nova_img, $nome_otimizado, 80); // Qualidade JPG aceitável
        }
        
        imagedestroy($nova_img);
        imagedestroy($origem);
        return $nome_otimizado;
    }
    
    return $ficheiro;
}

// ---------------------------------------------------------
// CONFIGURAÇÃO DO MOTOR CAMALEÃO (LARGURAS DINÂMICAS)
// O total tem de ser sempre 190mm (Largura A4 imprimível)
// Coluna 'ref' alargada para 24mm para caber a referência completa.
// ---------------------------------------------------------
$table_config = [
    'sadi' => [
        'cols' => ['num', 'desc', 'ref', 'marca', 'sn', 'loop', 'zona', 'end', 'loc', 'est', 'obs'],
        'labels' => ['num'=>'Nº', 'desc'=>'Descrição', 'ref'=>'Ref.', 'marca'=>'Marca', 'sn'=>'S/N', 'loop'=>'L', 'zona'=>'Zona', 'end'=>'End.', 'loc'=>'Localização', 'est'=>'Est.', 'obs'=>'Obs.'],
        'w' => ['num'=>7, 'desc'=>34, 'ref'=>24, 'marca'=>15, 'sn'=>15, 'loop'=>5, 'zona'=>12, 'end'=>15, 'loc'=>28, 'est'=>10, 'obs'=>25]
    ],
    'extincao_agua' => [
        'cols' => ['num', 'desc', 'ref', 'marca', 'sn', 'zona', 'loc', 'est', 'obs'],
        'labels' => ['num'=>'Nº', 'desc'=>'Descrição', 'ref'=>'Ref.', 'marca'=>'Marca', 'sn'=>'S/N', 'zona'=>'Zona', 'loc'=>'Localização', 'est'=>'Est.', 'obs'=>'Obs.'],
        'w' => ['num'=>7, 'desc'=>40, 'ref'=>24, 'marca'=>15, 'sn'=>15, 'zona'=>12, 'loc'=>35, 'est'=>10, 'obs'=>32]
    ],
    'iluminacao_emergencia' => [
        'cols' => ['num', 'desc', 'ref', 'marca', 'zona', 'loc', 'est', 'obs'],
        'labels' => ['num'=>'Nº', 'desc'=>'Descrição', 'ref'=>'Ref.', 'marca'=>'Marca', 'zona'=>'Tipo (Perm/Não)', 'loc'=>'Localização', 'est'=>'Est.', 'obs'=>'Obs.'],
        'w' => ['num'=>7, 'desc'=>42, 'ref'=>24, 'marca'=>15, 'zona'=>20, 'loc'=>40, 'est'=>10, 'obs'=>32]
    ],
    'portas_fogo' => [
        'cols' => ['num', 'desc', 'ref', 'marca', 'loc', 'est', 'obs'],
        'labels' => ['num'=>'Nº', 'desc'=>'Descrição', 'ref'=>'Ref.', 'marca'=>'Marca', 'loc'=>'Localização', 'est'=>'Est.', 'obs'=>'Obs.'],
        'w' => ['num'=>7, 'desc'=>46, 'ref'=>24, 'marca'=>15, 'loc'=>47, 'est'=>10, 'obs'=>41]
    ]
];
// Duplicação de regras para tipos similares
$table_config['deteccao_gas'] = $table_config['sadi'];
$table_config['compartimentacao'] = $table_config['portas_fogo'];
$table_config['sinalizacao'] = $table_config['portas_fogo'];

$cfg = $table_config[$tipo_documento] ?? $table_config['sadi']; // Default de segurança

// Mapa das colunas da BD
$field_map = [
    'num' => 'numero', 'desc' => 'descricao', 'ref' => 'referencia', 'marca' => 'marca',
    'sn' => 'n_serie', 'loop' => 'loop_eq', 'zona' => 'zona', 'end' => 'endereco',
    'loc' => 'localizacao', 'est' => 'estado', 'obs' => 'obs'
];

// ---------------------------------------------------------
// CLASSE PDF CUSTOMIZADA (HEADER / FOOTER SCIE)
// ---------------------------------------------------------
class PDF_SCIE extends FPDF {
    public $titulo_doc;
    public $nome_especialidade;

    function Header() {
        // Usa a imagem otimizada para o cabeçalho
        $logo = otimizarImagem('mab.png');
        if ($logo && file_exists($logo)) {
            $this->Image($logo, 10, 8, 18);
        }
        
        $this->SetY(12);
        $this->SetFont('Arial', 'B', 14);
        $this->SetTextColor(179, 42, 42); // Vermelho escuro SCIE
        $this->Cell(0, 6, pt($this->titulo_doc), 0, 1, 'R');
        $this->SetFont('Arial', '', 10);
        $this->SetTextColor(0, 0, 0);
        $this->Cell(0, 5, pt('Especialidade: ' . $this->nome_especialidade), 0, 1, 'R');
        
        $this->SetY(30);
        $this->SetDrawColor(179, 42, 42);
        $this->SetLineWidth(0.5);
        $this->Line(10, 28, 200, 28);
        $this->Ln(5);
    }

    function Footer() {
        $this->SetY(-15);
        $this->SetDrawColor(200, 200, 200);
        $this->SetLineWidth(0.2);
        $this->Line(10, $this->GetY(), 200, $this->GetY());
        $this->Ln(2);
        
        $this->SetFont('Arial', 'B', 8);
        $this->SetTextColor(100, 100, 100);
        $this->Cell(0, 4, pt('m. Abreu - Instalações Técnicas, Lda. | NIF: 514 414 120 | Registo ANPC: 3707'), 0, 1, 'C');
        
        $this->SetFont('Arial', '', 8);
        $this->Cell(0, 4, pt('Rua Damião de Góis, 205, Braga | Email: marioabreu@mabreu.pt | Tel: 914 259 487'), 0, 1, 'C');
    }
}

// ---------------------------------------------------------
// INICIAR PDF
// ---------------------------------------------------------
$pdf = new PDF_SCIE('P', 'mm', 'A4');
$pdf->titulo_doc = ($doc_solicitado === 'termo') ? "Termo de Responsabilidade" : $titulo_relatorio;
$pdf->nome_especialidade = $nome_especialidade;
$pdf->AliasNbPages();
$pdf->AddPage();

// =========================================================
// LAYOUT: TERMO DE RESPONSABILIDADE (RESUMIDO)
// =========================================================
if ($doc_solicitado === 'termo') {
    
    // CAIXA DE INFORMAÇÕES
    $pdf->SetFont('Arial', 'B', 9);
    $pdf->SetFillColor(241, 242, 246);
    $pdf->SetDrawColor(200, 200, 200);
    
    $pdf->Cell(25, 7, pt('Entidade:'), 1, 0, 'L', true);
    $pdf->SetFont('Arial', '', 9);
    $pdf->Cell(165, 7, pt(truncate($cliente['cliente_nome'] ?? 'N/A', 90)), 1, 1, 'L');
    
    $pdf->SetFont('Arial', 'B', 9);
    $pdf->Cell(25, 7, pt('NIF:'), 1, 0, 'L', true);
    $pdf->SetFont('Arial', '', 9);
    $pdf->Cell(65, 7, pt($cliente['cliente_nif'] ?? 'N/A'), 1, 0, 'L');
    
    $pdf->SetFont('Arial', 'B', 9);
    $pdf->Cell(20, 7, pt('Obra:'), 1, 0, 'L', true);
    $pdf->SetFont('Arial', '', 9);
    $pdf->Cell(80, 7, pt(truncate($termo['nome_obra'] ?? 'N/A', 45)), 1, 1, 'L');
    
    $pdf->SetFont('Arial', 'B', 9);
    $pdf->Cell(25, 7, pt('Morada:'), 1, 0, 'L', true);
    $pdf->SetFont('Arial', '', 9);
    $pdf->Cell(165, 7, pt(truncate($termo['obra_morada'] ?? 'N/A', 95)), 1, 1, 'L');
    
    $pdf->Ln(8);

    // TEXTO LEGAL 1
    $pdf->SetFont('Arial', '', 10);
    $texto1 = "Para os devidos efeitos se declara que m. Abreu - Instalações Técnicas, Lda., NIF 514 414 120, com sede na Rua Damião de Góis, 205 em Braga, registada na ANPC sob o n. 3707, $txt_acao os seguintes materiais SCIE:";
    $pdf->MultiCell(0, 6, pt($texto1), 0, 'J');
    $pdf->Ln(6);

    // TABELA RESUMO
    $pdf->SetFont('Arial', 'B', 9);
    $pdf->SetFillColor(241, 242, 246);
    $pdf->Cell(15, 6, pt('Qtd'), 1, 0, 'C', true);
    $pdf->Cell(95, 6, pt('Descrição'), 1, 0, 'L', true);
    $pdf->Cell(40, 6, pt('Referência'), 1, 0, 'C', true);
    $pdf->Cell(40, 6, pt('Marca'), 1, 1, 'C', true);

    $pdf->SetFont('Arial', '', 9);
    foreach ($resumo_equipamentos as $it) {
        $pdf->Cell(15, 6, pt($it['qtd']), 1, 0, 'C');
        $pdf->Cell(95, 6, pt(truncate($it['desc'], 50)), 1, 0, 'L');
        $pdf->Cell(40, 6, pt(truncate($it['ref'], 20)), 1, 0, 'C');
        $pdf->Cell(40, 6, pt(truncate($it['marca'], 20)), 1, 1, 'C');
    }
    
    $pdf->Ln(6);

    // TEXTO LEGAL 2
    $pdf->SetFont('Arial', '', 10);
    $texto2 = "Mais se declara que os equipamentos $txt_estado se encontram em conformidade com o projeto de segurança, as normas aplicáveis e em perfeita operacionalidade. Deste termo faz parte integrante a lista detalhada e certificados.";
    $pdf->MultiCell(0, 6, pt($texto2), 0, 'J');

} 
// =========================================================
// LAYOUT: RELATÓRIO (TABELA CAMALEÃO)
// =========================================================
else {
    
    // CAIXA DE INFORMAÇÕES DIVIDIDA
    $pdf->SetFont('Arial', 'B', 8);
    $pdf->SetDrawColor(200, 200, 200);
    
    // Largura total 190. Metade = 92.5 (com gap de 5)
    $x_inicio = $pdf->GetX();
    $y_inicio = $pdf->GetY();
    
    // Quadrado Esquerdo (Cliente)
    $pdf->Rect($x_inicio, $y_inicio, 92.5, 14);
    $pdf->SetXY($x_inicio + 2, $y_inicio + 2);
    $pdf->Cell(20, 5, pt('ENTIDADE:'), 0, 0, 'L');
    $pdf->SetFont('Arial', '', 8);
    $pdf->Cell(70, 5, pt(truncate($cliente['cliente_nome'] ?? 'N/A', 40)), 0, 1, 'L');
    
    $pdf->SetXY($x_inicio + 2, $y_inicio + 7);
    $pdf->SetFont('Arial', 'B', 8);
    $pdf->Cell(20, 5, pt('NIF:'), 0, 0, 'L');
    $pdf->SetFont('Arial', '', 8);
    $pdf->Cell(70, 5, pt($cliente['cliente_nif'] ?? 'N/A'), 0, 1, 'L');
    
    // Quadrado Direito (Obra)
    $x_direito = $x_inicio + 97.5;
    $pdf->Rect($x_direito, $y_inicio, 92.5, 14);
    
    $pdf->SetXY($x_direito + 2, $y_inicio + 2);
    $pdf->SetFont('Arial', 'B', 8);
    $pdf->Cell(15, 5, pt('OBRA:'), 0, 0, 'L');
    $pdf->SetFont('Arial', '', 8);
    $pdf->Cell(75, 5, pt(truncate($termo['nome_obra'] ?? 'N/A', 45)), 0, 1, 'L');
    
    $pdf->SetXY($x_direito + 2, $y_inicio + 7);
    $pdf->SetFont('Arial', 'B', 8);
    $pdf->Cell(15, 5, pt('LOCAL:'), 0, 0, 'L');
    $pdf->SetFont('Arial', '', 8);
    $pdf->Cell(75, 5, pt(truncate($termo['obra_morada'] ?? 'N/A', 50)), 0, 1, 'L');

    $pdf->SetY($y_inicio + 18);

    // -------------------------------------------
    // CABEÇALHO DA TABELA DINÂMICA
    // -------------------------------------------
    $pdf->SetFont('Arial', 'B', 6);
    $pdf->SetFillColor(241, 242, 246);
    
    foreach ($cfg['cols'] as $c) {
        $align = in_array($c, ['desc', 'loc', 'obs']) ? 'L' : 'C';
        $pdf->Cell($cfg['w'][$c], 6, pt($cfg['labels'][$c]), 1, 0, $align, true);
    }
    $pdf->Ln();

    // -------------------------------------------
    // LINHAS DA TABELA DINÂMICA
    // -------------------------------------------
    foreach ($equipamentos as $eq) {
        // A Central de Incêndio (numero 'C') aparece isolada na 1.ª linha, com fundo destacado
        $is_central = (trim($eq['numero']) === 'C');
        $pdf->SetFillColor(253, 242, 242);

        foreach ($cfg['cols'] as $c) {
            $w = $cfg['w'][$c];
            $align = in_array($c, ['desc', 'loc', 'obs']) ? 'L' : 'C';
            
            // Lógica de Cores para o Estado
            if ($c === 'est') {
                if ($eq['estado'] == 'OK') {
                    $pdf->SetTextColor(39, 174, 96); // Verde
                } else {
                    $pdf->SetTextColor(192, 57, 43); // Vermelho
                }
                $pdf->SetFont('Arial', 'B', 6);
            } else {
                $pdf->SetTextColor(0, 0, 0);
                if ($c === 'obs') {
                    $pdf->SetFont('Arial', '', 5); // Fonte menorzinha para observações
                } else {
                    $pdf->SetFont('Arial', '', 6);
                }
            }
            
            $texto_bruto = $eq[$field_map[$c]] ?? '';
            
            if ($c === 'ref') {
                // REFERÊNCIA: nunca truncar. Se não couber na coluna, reduz a fonte até caber.
                $val = pt($texto_bruto);
                $fs = 6;
                while ($pdf->GetStringWidth($val) > ($w - 2) && $fs > 3.5) {
                    $fs -= 0.25;
                    $pdf->SetFont('Arial', '', $fs);
                }
            } elseif (in_array($c, ['desc', 'loc', 'obs'])) {
                // Truncate inteligente proporcional à largura da coluna (Aprox. 0.9 caracteres por mm no Arial 6pt)
                $max_chars = floor($w * 0.9);
                if ($c === 'obs') $max_chars = floor($w * 1.1); // Letra mais pequena cabe mais
                $val = pt(truncate($texto_bruto, $max_chars));
            } else {
                $val = pt(truncate($texto_bruto, floor($w * 0.9)));
            }

            $pdf->Cell($w, 5, $val, 1, 0, $align, $is_central);
        }
        $pdf->Ln(); // Quebra de linha ao fim de cada equipamento
    }
}

// =========================================================
// OBSERVAÇÕES GERAIS E ASSINATURA FINAL COM CARIMBO
// =========================================================
$pdf->Ln(5);

// Impressão das observações gerais caso tenham sido inseridas no formulário
if ($doc_solicitado === 'relatorio' && !empty($observacoes_gerais_list)) {
    $pdf->SetFont('Arial', 'B', 8);
    $pdf->SetTextColor(0, 0, 0);
    $pdf->Cell(0, 6, pt('Observações Gerais:'), 0, 1, 'L');
    
    $pdf->SetFont('Arial', '', 8);
    foreach ($observacoes_gerais_list as $obs) {
        $pdf->MultiCell(0, 5, pt('- ' . $obs), 0, 'L');
    }
    $pdf->Ln(5);
}

// Evita que a assinatura quebre para a página seguinte cortando a meio
if ($pdf->GetY() > 240) {
    $pdf->AddPage();
}

$pdf->Ln(5);
$pdf->SetFont('Arial', '', 10);
$pdf->SetTextColor(0, 0, 0);
$pdf->Cell(0, 6, pt("$local_obra, $data_emissao"), 0, 1, 'C');
$pdf->Ln(10);

// Guarda a posição Y exata da linha de assinatura para colocar a imagem em cima
$y_assinatura = $pdf->GetY();

$pdf->SetDrawColor(0, 0, 0);
$pdf->SetLineWidth(0.3);

// Linha da assinatura ao centro
$pdf->Line(65, $y_assinatura, 145, $y_assinatura);

// INSERÇÃO DA ASSINATURA OTIMIZADA (min_assina.png)
$assina = otimizarImagem('assina.png');
if ($assina && file_exists($assina)) {
    $img_width = 40;
    $img_height = 15;
    $x_center = (210 - $img_width) / 2; // 210mm é a largura do papel A4
    $y_img = $y_assinatura - $img_height + 2; 
    
    $pdf->Image($assina, $x_center, $y_img, $img_width, $img_height);
}

$pdf->SetY($y_assinatura + 2); // Volta a colocar o cursor debaixo da linha
$pdf->Cell(0, 6, pt('(Técnico Responsável)'), 0, 1, 'C');

ob_end_clean();
$filename = ($doc_solicitado === 'termo' ? 'Termo_SCIE_' : 'Relatorio_SCIE_') . pt($termo['nome_obra']) . '.pdf';
$pdf->Output('I', $filename);
?>
