<?php
// REGRA DE OURO: Limpar qualquer espaço em branco ou lixo antes de gerar o PDF
ob_start();
session_start();

if (!isset($_SESSION['user_id'])) { 
    ob_end_clean();
    die("Acesso negado."); 
}

require_once 'db.php';
require_once('fpdf/fpdf.php');

// REGRA DE OURO: Validação rigorosa do ID
$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$tipo = $_GET['tipo'] ?? 'detalhe'; 

$is_limpa = ($tipo === 'limpa' || $tipo === 'limpo');
$is_gestao = ($tipo === 'gestao');
$is_global = ($tipo === 'global');
// capitulo_interno = igual ao "Total por Capítulo", mas com os artigos do Detalhe Interno (em vez do Resumo Comercial)
$is_capitulo_interno = ($tipo === 'capitulo_interno');
$is_capitulo = ($tipo === 'capitulo' || $is_capitulo_interno);
// detalhe_vg = igual ao "valor por linha", mas linhas em m/ml passam a vg, qtd 1,00 e preço = total da linha
$is_detalhe_vg = ($tipo === 'detalhe_vg');
// comercial = Resumo Comercial tal como está no formulário (sem conversões para vg, sem esconder nem agrupar nada)
$is_fiel = ($tipo === 'comercial');
$is_detalhe = ($tipo === 'detalhe' || $tipo === 'orcamento_cliente' || $tipo === 'cliente' || $is_detalhe_vg);

// Pré-visualização: imprimir com os valores que estão no formulário (orcamento_form.php), mesmo sem gravar
$e_preview = ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['preview_itens_json']));
if (!function_exists('limparNumero')) {
    function limparNumero($num) {
        if (empty(trim((string)$num))) return 0;
        $num = str_replace(['.', ' '], '', (string)$num);
        $num = str_replace(',', '.', $num);
        return floatval($num);
    }
}

if (!$id && !$e_preview) { 
    ob_end_clean();
    die("Erro: ID de orçamento inválido."); 
}

// --- BUSCAR DADOS DO ORÇAMENTO ---
try {
    $sql = "SELECT o.*, c.nome as cliente_nome, c.morada as cliente_morada, 
                   c.codigo_postal as cliente_cp, c.cidade as cliente_cidade, 
                   c.nif as cliente_nif, c.telefone as cliente_telefone, c.email as cliente_email
            FROM orcamentos o
            LEFT JOIN clientes c ON o.id_cliente = c.id
            WHERE o.id = ?";
    $orc = false;
    if ($id) {
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$id]);
        $orc = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    if (!$orc && $e_preview) $orc = ['id_orcamento_pai' => null];   // orçamento novo, ainda não gravado

    if (!$orc) { 
        ob_end_clean();
        die("Erro: Orçamento não encontrado."); 
    }

    // Artigos totais
    if ($e_preview) {
        // Linhas tal como estão no ecrã (Detalhe Interno + Resumo Comercial)
        $itens_raw = [];
        $lin_prev = json_decode((string)$_POST['preview_itens_json'], true);
        if (!is_array($lin_prev)) $lin_prev = [];
        foreach ($lin_prev as $n => $lp) {
            $desig_p = (string)($lp['designacao'] ?? ''); $art_p = (string)($lp['artigo'] ?? ''); $q_str_p = (string)($lp['quantidade'] ?? '');
            if (trim($desig_p) === '' && trim($art_p) === '' && trim($q_str_p) === '') continue;
            $q_p = limparNumero($q_str_p); $pc_p = limparNumero($lp['preco_unitario_custo'] ?? '0');
            $pv_p = limparNumero($lp['preco_unitario_venda'] ?? '0'); $tmo_p = limparNumero($lp['tempo_mao_obra'] ?? '0');
            $itens_raw[] = [
                'id' => 900000 + $n,
                'tipo_linha' => (($lp['tipo_linha'] ?? 'Interno') === 'Comercial') ? 'Comercial' : 'Interno',
                'artigo' => $art_p, 'designacao' => $desig_p, 'unidade' => (string)($lp['unidade'] ?? ''),
                'quantidade' => $q_p, 'preco_unitario_custo' => $pc_p, 'tempo_mao_obra' => $tmo_p,
                'valor_custo' => round($q_p * $pc_p, 2), 'preco_unitario_venda' => $pv_p, 'valor_venda' => round($q_p * $pv_p, 2),
            ];
        }
    } else {
        $stmt_itens = $pdo->prepare("SELECT * FROM orcamento_itens WHERE orcamento_id = ? ORDER BY id ASC");
        $stmt_itens->execute([$id]);
        $itens_raw = $stmt_itens->fetchAll(PDO::FETCH_ASSOC);
    }

    // Separar por canais Interno vs Comercial
    $itens_interno = [];
    $itens_comercial = [];
    foreach ($itens_raw as $it) {
        if (($it['tipo_linha'] ?? 'Interno') === 'Comercial') {
            $itens_comercial[] = $it;
        } else {
            $itens_interno[] = $it;
        }
    }

    // Escolha Inteligente de visualização (Fator Simbiótico)
    if ($is_gestao || $is_capitulo_interno) {
        $itens = $itens_interno;
    } else {
        $itens = !empty($itens_comercial) ? $itens_comercial : $itens_interno;
    }

    // Pré-visualização: cabeçalho e totais com os valores do formulário
    if ($e_preview) {
        foreach (['numero_orcamento', 'nome_obra', 'morada_obra', 'contacto_obra', 'referencia_cliente'] as $cp) {
            if (isset($_POST[$cp])) $orc[$cp] = (string)$_POST[$cp];
        }
        if (!empty($_POST['data'])) $orc['data'] = $_POST['data'];
        if (!empty($_POST['data_validade_final'])) $orc['data_validade'] = $_POST['data_validade_final'];
        if (empty($orc['data'])) $orc['data'] = date('Y-m-d');
        if (empty($orc['data_validade'])) $orc['data_validade'] = date('Y-m-d', strtotime($orc['data'] . ' +30 days'));
        $id_cli_p = filter_var($_POST['id_cliente'] ?? '', FILTER_VALIDATE_INT);
        if ($id_cli_p) {
            $st_cli = $pdo->prepare("SELECT nome AS cliente_nome, morada AS cliente_morada, codigo_postal AS cliente_cp, cidade AS cliente_cidade, nif AS cliente_nif, telefone AS cliente_telefone, email AS cliente_email FROM clientes WHERE id = ?");
            $st_cli->execute([$id_cli_p]);
            if ($cli_p = $st_cli->fetch(PDO::FETCH_ASSOC)) $orc = array_merge($orc, $cli_p);
        } elseif (($_POST['id_cliente'] ?? '') === 'novo') {
            $orc['cliente_nome'] = (string)($_POST['novo_cliente_nome'] ?? '');
            $orc['cliente_nif'] = (string)($_POST['novo_cliente_nif'] ?? '');
            $orc['cliente_morada'] = (string)($_POST['novo_cliente_morada'] ?? '');
            $orc['cliente_cp'] = (string)($_POST['novo_cliente_cp'] ?? '');
            $orc['cliente_cidade'] = (string)($_POST['novo_cliente_cidade'] ?? '');
        }
        foreach (['cliente_nome', 'cliente_morada', 'cliente_cp', 'cliente_cidade', 'cliente_nif'] as $cp) if (!isset($orc[$cp])) $orc[$cp] = '';
        foreach (['nome_obra', 'morada_obra', 'contacto_obra', 'referencia_cliente', 'numero_orcamento'] as $cp) if (!isset($orc[$cp])) $orc[$cp] = '';

        $taxa_p = (float)($_POST['taxa_iva'] ?? ($orc['taxa_iva'] ?? 23));
        $tem_com_p = false; $sv_i = 0; $sv_c = 0; $sc_i = 0;
        foreach ($itens_raw as $it_p) {
            if ($it_p['tipo_linha'] === 'Comercial') { if (trim($it_p['designacao']) !== '') $tem_com_p = true; $sv_c += $it_p['valor_venda']; }
            else { $sv_i += $it_p['valor_venda']; $sc_i += $it_p['valor_custo']; }
        }
        $liq_p = round($tem_com_p ? $sv_c : $sv_i, 2);
        $mo_p = limparNumero($_POST['valor_mao_obra'] ?? ($orc['mao_obra'] ?? 0));
        $orc['taxa_iva'] = $taxa_p;
        $orc['valor_total_liquido'] = $liq_p;
        $orc['valor_iva'] = round($liq_p * $taxa_p / 100, 2);
        $orc['valor_total_com_iva'] = round($liq_p + $orc['valor_iva'], 2);
        $orc['valor_total_custo'] = round($sc_i, 2);
        $orc['mao_obra'] = $mo_p;
        $orc['valor_custo_real'] = round($sc_i + $mo_p, 2);
    }

    // Observações
    $stmt_obs = $pdo->prepare("SELECT observacao FROM orcamento_observacoes WHERE orcamento_id = ? ORDER BY id ASC");
    $stmt_obs->execute([$id]);
    $observacoes = $stmt_obs->fetchAll(PDO::FETCH_COLUMN);

    // Inclui
    $inclui_lista = [];
    try {
        $stmt_inc = $pdo->prepare("SELECT texto FROM orcamento_inclui WHERE orcamento_id = ? ORDER BY id ASC");
        $stmt_inc->execute([$id]);
        $inclui_lista = $stmt_inc->fetchAll(PDO::FETCH_COLUMN);
    } catch(Exception $e) {}

    // Não Inclui
    $nao_inclui_lista = [];
    try {
        $stmt_ninc = $pdo->prepare("SELECT texto FROM orcamento_nao_inclui WHERE orcamento_id = ? ORDER BY id ASC");
        $stmt_ninc->execute([$id]);
        $nao_inclui_lista = $stmt_ninc->fetchAll(PDO::FETCH_COLUMN);
    } catch(Exception $e) {}

    if ($e_preview) {
        $lista_post = function ($k) { return array_values(array_filter(array_map('strval', (array)($_POST[$k] ?? [])), function ($v) { return trim($v) !== ''; })); };
        $observacoes = $lista_post('obs_linha');
        $inclui_lista = $lista_post('inclui_linha');
        $nao_inclui_lista = $lista_post('nao_inclui_linha');
    }

} catch (Exception $e) {
    ob_end_clean();
    die("Erro na BD: " . htmlspecialchars($e->getMessage()));
}

// --- GARANTIAS (escolhidas automaticamente pelo NIF do cliente) ---
// NIF começado por 1, 2, 3 (ou 45 = singular não residente), sem NIF ou 999999990 → Particulares
// Restantes (5 empresas, 6 Estado, 7, 8, 9...) → Empresas
// Os textos podem ser alterados em Configurações (chaves 'garantias_particular' / 'garantias_empresa', uma garantia por linha).
$garantias_padrao = [
    'particular' => [
        'Os trabalhos executados têm garantia de 3 anos, a contar da data de conclusão.',
        'Os equipamentos e materiais fornecidos têm a garantia legal aplicável (DL 84/2021) ou a do fabricante, quando esta for superior.',
        'A garantia não cobre danos resultantes de uso indevido, sobretensões ou descargas atmosféricas, intervenções de terceiros, desgaste normal ou falta da manutenção recomendada.'
    ],
    'empresa' => [
        'Os trabalhos executados têm garantia de 2 anos, a contar da data de conclusão.',
        'Os equipamentos e materiais fornecidos têm garantia de 6 meses, a contar da data de entrega, sem prejuízo da garantia do fabricante, quando esta for superior.',
        'Os defeitos devem ser comunicados por escrito no prazo de 30 dias após a sua deteção.',
        'A garantia não cobre danos resultantes de uso indevido, sobretensões ou descargas atmosféricas, intervenções de terceiros, desgaste normal ou falta da manutenção recomendada.',
        'A garantia fica condicionada à liquidação integral dos valores faturados.'
    ]
];

function tipoClientePorNIF($nif) {
    $nif = preg_replace('/\D/', '', (string)$nif);
    if ($nif === '' || $nif === '999999990') return 'particular';
    if (in_array($nif[0], ['1', '2', '3'], true)) return 'particular';
    if (substr($nif, 0, 2) === '45') return 'particular';
    return 'empresa';
}

$tipo_cliente = tipoClientePorNIF($orc['cliente_nif'] ?? '');
$garantias = $garantias_padrao[$tipo_cliente];
try {
    $stmt_gar = $pdo->prepare("SELECT valor FROM configuracoes WHERE chave = ? LIMIT 1");
    $stmt_gar->execute(['garantias_' . $tipo_cliente]);
    $txt_gar = trim((string)$stmt_gar->fetchColumn());
    if ($txt_gar !== '') {
        $garantias = array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $txt_gar)), 'strlen'));
    }
} catch (Exception $e) { /* fica o texto de origem */ }

function pt($texto) {
    $texto = (string)($texto ?? '');
    return mb_convert_encoding($texto, 'windows-1252', 'UTF-8');
}

// Calcular os totais dos Capítulos antecipadamente se for mapa de capítulos
// Os capítulos podem ter vários níveis (3 → 3.1. → 3.1.1 → linhas). Um título "pertence" ao título anterior cujo
// artigo é prefixo do seu (4.3.1 fica dentro do 4, não do 4.2.). Cada linha com preço soma no título mais fundo
// cujo artigo é prefixo do seu e em todos os de cima; se o artigo não encaixar em nenhum (ex.: 8.1.21 no meio
// do capítulo 4), soma no último título aberto. Títulos = linhas sem quantidade ou sem preço e sem valor.
$capitulos_totais = []; $capitulos_nivel = [];
if ($is_capitulo) {
    $normArt = function ($art) { return trim(preg_replace('/\s+/', '', (string)$art), '.'); };
    $nivelArtigo = function ($art) use ($normArt) {
        $a = $normArt($art);
        return $a === '' ? 1 : count(array_filter(explode('.', $a), 'strlen'));
    };
    $ePrefixo = function ($pai, $filho) use ($normArt) {
        $p = $normArt($pai); $f = $normArt($filho);
        return $p !== '' && $f !== '' && strpos($f . '.', $p . '.') === 0 && $f !== $p;
    };
    $eTitulo = function ($it) {
        return floatval($it['quantidade']) == 0
            || (round(floatval($it['preco_unitario_venda'] ?? 0), 2) == 0 && round(floatval($it['valor_venda'] ?? 0), 2) == 0);
    };
    $pilha = [];   // títulos abertos, do mais geral para o mais fundo: [id, artigo]
    foreach ($itens as $it) {
        if ($eTitulo($it)) {
            // fecha os títulos que não são "pais" deste
            while ($pilha && !$ePrefixo(end($pilha)['art'], $it['artigo'] ?? '')) array_pop($pilha);
            $pilha[] = ['id' => $it['id'], 'art' => (string)($it['artigo'] ?? '')];
            $capitulos_totais[$it['id']] = 0.0;
            $capitulos_nivel[$it['id']] = $nivelArtigo($it['artigo'] ?? '');
            continue;
        }
        if (!$pilha) continue;
        // título mais fundo cujo artigo é prefixo do desta linha; senão o último título aberto
        $k = count($pilha) - 1;
        for ($t = count($pilha) - 1; $t >= 0; $t--) {
            if ($ePrefixo($pilha[$t]['art'], $it['artigo'] ?? '')) { $k = $t; break; }
        }
        $v = round(floatval($it['valor_venda']), 2);
        for ($t = 0; $t <= $k; $t++) $capitulos_totais[$pilha[$t]['id']] += $v;
    }
    foreach ($capitulos_totais as $cid => $tv) $capitulos_totais[$cid] = round($tv, 2);
}

class PDF extends FPDF {
    public $titulo_doc;
    public $subtitulo_doc = '';
    
    function RoundedRect($x, $y, $w, $h, $r, $style = '') {
        $k = $this->k; $hp = $this->h;
        $op = ($style=='F') ? 'f' : (($style=='FD' || $style=='DF') ? 'B' : 'S');
        $MyArc = 4/3 * (sqrt(2) - 1);
        $this->_out(sprintf('%.2F %.2F m',($x+$r)*$k,($hp-$y)*$k ));
        $xc = $x+$w-$r ; $yc = $y+$r;
        $this->_out(sprintf('%.2F %.2F l', $xc*$k,($hp-$y)*$k ));
        $this->_Arc($xc + $r*$MyArc, $yc - $r, $xc + $r, $yc - $r*$MyArc, $xc + $r, $yc);
        $xc = $x+$w-$r ; $yc = $y+$h-$r;
        $this->_out(sprintf('%.2F %.2F l',($x+$w)*$k,($hp-$yc)*$k));
        $this->_Arc($xc + $r, $yc + $r*$MyArc, $xc + $r*$MyArc, $yc + $r, $xc, $yc + $r);
        $xc = $x+$r ; $yc = $y+$h-$r;
        $this->_out(sprintf('%.2F %.2F l',$xc*$k,($hp-($y+$h))*$k));
        $this->_Arc($xc - $r*$MyArc, $yc + $r, $xc - $r, $yc + $r*$MyArc, $xc - $r, $yc);
        $this->_out($op);
    }
    function _Arc($x1, $y1, $x2, $y2, $x3, $y3) { $this->_out(sprintf('%.2F %.2F %.2F %.2F %.2F %.2F c ', $x1*$this->k, ($this->h-$y1)*$this->k, $x2*$this->k, ($this->h-$y2)*$this->k, $x3*$this->k, ($this->h-$y3)*$this->k)); }
    
    function NbLines($w, $txt) {
        $cw = &$this->CurrentFont['cw']; if($w == 0) $w = $this->w - $this->rMargin - $this->x;
        $wmax = ($w - 2 * $this->cMargin) * 1000 / $this->FontSize;
        $s = str_replace("\r", '', $txt); $nb = strlen($s);
        if($nb > 0 && $s[$nb-1] == "\n") $nb--;
        $i = 0; $j = 0; $l = 0; $nl = 1; $sep = -1;
        while($i < $nb) {
            $c = $s[$i];
            if($c == "\n") { $i++; $sep = -1; $j = $i; $l = 0; $nl++; continue; }
            if($c == ' ') $sep = $i; $l += $cw[$c];
            if($l > $wmax) { if($sep == -1) { if($i == $j) $i++; } else $i = $sep + 1; $sep = -1; $j = $i; $l = 0; $nl++; } else $i++;
        }
        return $nl;
    }
    
    function Header() {
        if (file_exists('mab.png')) $this->Image('mab.png', 10, 10, 20); 
        $this->SetY(12); $this->SetFont('Arial', 'B', 13); $this->SetTextColor(179, 42, 42); 
        $this->Cell(0, 8, pt($this->titulo_doc), 0, 1, 'R');
        if ($this->subtitulo_doc !== '') {
            $this->SetFont('Arial', '', 9); $this->SetTextColor(90, 90, 90);
            $this->Cell(0, 5, pt($this->subtitulo_doc), 0, 1, 'R');
            $this->Ln(5);
        } else {
            $this->Ln(10);
        } $y = $this->GetY(); $this->SetDrawColor(179, 42, 42); $this->SetLineWidth(0.5);
        $this->Line(10, $y, 200, $y); $this->Ln(5);
    }
    function Footer() {
        $this->SetY(-15); $this->SetDrawColor(179, 42, 42); $this->SetLineWidth(0.5);
        $this->Line(10, $this->GetY(), 200, $this->GetY()); $this->Ln(2); $this->SetFont('Arial', '', 8);
        $this->Cell(0, 4, pt("m. Abreu - Instalações Técnicas, Lda. | NIF: 514 414 120"), 0, 0, 'C');
        $this->SetXY(10, $this->GetY()); $this->Cell(0, 4, pt('Página ') . $this->PageNo() . '/{nb}', 0, 1, 'R');
        $this->Cell(0, 4, pt("Rua Damião de Góis, 205, Braga | Email: geral@mabreu.pt | Tel: 912 854 950"), 0, 1, 'C');
    }
}

$pdf = new PDF();
$tipo_str = ($is_gestao) ? ' (Gestão)' : '';
$pdf->titulo_doc = 'ORÇAMENTO Nº ' . $orc['numero_orcamento'] . $tipo_str;
// Orçamento complementar (trabalhos pedidos durante a obra): indicar o orçamento de origem
if (!empty($orc['id_orcamento_pai'])) {
    try {
        $st_pai = $pdo->prepare("SELECT numero_orcamento FROM orcamentos WHERE id = ?");
        $st_pai->execute([$orc['id_orcamento_pai']]);
        $num_pai = $st_pai->fetchColumn();
        $pdf->titulo_doc = 'ORÇAMENTO COMPLEMENTAR Nº ' . $orc['numero_orcamento'] . $tipo_str;
        if ($num_pai) $pdf->subtitulo_doc = 'Trabalhos complementares ao Orçamento Nº ' . $num_pai;
    } catch (Exception $e) {}
}
$pdf->AliasNbPages(); $pdf->AddPage(); $pdf->SetAutoPageBreak(false); 

$morada_cli = trim($orc['cliente_morada'] . ' ' . $orc['cliente_cp'] . ' ' . $orc['cliente_cidade']);
$data_emissao = date('d/m/Y', strtotime($orc['data']));
$validade_texto = (new DateTime($orc['data']))->diff(new DateTime($orc['data_validade']))->days . ' dias';

$pdf->SetFont('Arial', 'B', 8); $pdf->Cell(0, 5, pt('CLIENTE'), 0, 1, 'L');
$pdf->SetFont('Arial', 'B', 10); $pdf->Cell(145, 5, pt($orc['cliente_nome']), 0, 0, 'L');
$pdf->SetFont('Arial', '', 9); $pdf->Cell(20, 5, pt('Data:'), 0, 0, 'R');
$pdf->SetFont('Arial', 'B', 9); $pdf->Cell(25, 5, pt($data_emissao), 0, 1, 'R');
$pdf->SetFont('Arial', '', 9); $pdf->Cell(145, 5, pt($morada_cli), 0, 0, 'L');
$pdf->Cell(20, 5, pt('Validade:'), 0, 0, 'R');
$pdf->SetFont('Arial', 'B', 9); $pdf->Cell(25, 5, pt($validade_texto), 0, 1, 'R');
$pdf->SetFont('Arial', '', 9); $pdf->Cell(10, 5, pt('NIF: '), 0, 0, 'L');
$pdf->Cell(180, 5, pt($orc['cliente_nif']), 0, 1, 'L'); $pdf->Ln(4);

$y_box = $pdf->GetY(); $pdf->SetDrawColor(220, 220, 220); $pdf->RoundedRect(10, $y_box, 190, 14, 2); 
$pdf->Line(135, $y_box + 2, 135, $y_box + 12); $pdf->SetY($y_box + 2);
$pdf->SetX(12); $pdf->SetFont('Arial', 'B', 9); $pdf->Cell(12, 5, pt('Obra: '), 0, 0, 'L');
$pdf->SetFont('Arial', '', 9); $pdf->Cell(105, 5, pt($orc['nome_obra']), 0, 0, 'L');
$pdf->SetX(135); $pdf->SetFont('Arial', 'B', 9); $pdf->Cell(20, 5, pt('Contacto:'), 0, 0, 'R');
$pdf->SetFont('Arial', '', 9); $pdf->Cell(33, 5, pt($orc['contacto_obra']), 0, 1, 'R');
$pdf->SetX(12); $pdf->SetFont('Arial', 'B', 9); $pdf->Cell(16, 5, pt('Morada: '), 0, 0, 'L');
$pdf->SetFont('Arial', '', 9); $pdf->Cell(101, 5, pt($orc['morada_obra']), 0, 0, 'L');
$pdf->SetX(135); $pdf->SetFont('Arial', 'B', 9); $pdf->Cell(25, 5, pt('Ref. Cliente:'), 0, 0, 'R');
$pdf->SetFont('Arial', '', 9); $pdf->Cell(28, 5, pt($orc['referencia_cliente']), 0, 1, 'R');

$pdf->Ln(7); $y = $pdf->GetY(); $pdf->SetDrawColor(179, 42, 42); $pdf->SetLineWidth(0.5); $pdf->Line(10, $y, 200, $y); $pdf->Ln(5);

if ($is_limpa) $w = [30, 120, 20, 20];
elseif ($is_detalhe || $is_capitulo || $is_global || $is_fiel) $w = [20, 90, 15, 15, 25, 25]; 
else $w = [20, 50, 10, 10, 25, 25, 25, 25];

$draw_header = function() use ($pdf, $is_detalhe, $is_capitulo, $is_global, $is_limpa, $is_fiel, $w) {
    $pdf->SetFont('Arial', 'B', 8); $pdf->SetFillColor(245, 245, 245); $pdf->SetDrawColor(200, 200, 200); $pdf->SetLineWidth(0.2);
    if ($is_limpa) {
        $pdf->Cell($w[0], 7, pt('Artigo'), 'T,B', 0, 'L', true); $pdf->Cell($w[1], 7, pt('Descrição'), 'T,B', 0, 'L', true);
        $pdf->Cell($w[2], 7, pt('Un.'), 'T,B', 0, 'C', true); $pdf->Cell($w[3], 7, pt('Qtd.'), 'T,B', 1, 'C', true);
    } elseif ($is_detalhe || $is_capitulo || $is_global || $is_fiel) {
        $pdf->Cell($w[0], 7, pt('Artigo'), 'T,B', 0, 'L', true); $pdf->Cell($w[1], 7, pt('Descrição'), 'T,B', 0, 'L', true);
        $pdf->Cell($w[2], 7, pt('Un.'), 'T,B', 0, 'C', true); $pdf->Cell($w[3], 7, pt('Qtd.'), 'T,B', 0, 'C', true);
        $pdf->Cell($w[4], 7, pt('Preço Unit.'), 'T,B', 0, 'R', true); $pdf->Cell($w[5], 7, pt('Valor'), 'T,B', 1, 'R', true);
    } else {
        $pdf->SetFont('Arial', 'B', 7);
        $pdf->Cell($w[0], 7, pt('Artigo'), 'T,B', 0, 'L', true); $pdf->Cell($w[1], 7, pt('Descrição'), 'T,B', 0, 'L', true);
        $pdf->Cell($w[2], 7, pt('Un.'), 'T,B', 0, 'C', true); $pdf->Cell($w[3], 7, pt('Qtd.'), 'T,B', 0, 'C', true);
        $pdf->Cell($w[4], 7, pt('P. Custo'), 'T,B', 0, 'R', true); $pdf->Cell($w[5], 7, pt('V. Custo'), 'T,B', 0, 'R', true);
        $pdf->Cell($w[6], 7, pt('P. Venda'), 'T,B', 0, 'R', true); $pdf->Cell($w[7], 7, pt('V. Venda'), 'T,B', 1, 'R', true);
    }
};

$draw_header(); $linha_altura_base = 5; 

foreach ($itens as $item) {
    $desig = pt($item['designacao']); $artigo = pt($item['artigo']);
    $qtd = floatval($item['quantidade']); $unid_original = trim(strtolower($item['unidade']));
    $is_linha_capitulo = ($qtd == 0); $unid_display = pt($item['unidade']);
    $qtd_display = ($qtd > 0) ? number_format($qtd, 2, ',', '.') : '';

    // Total por capítulo / Só valor global: linhas em metros (m, ml, mt, m.l.…) aparecem como vg 1,00 (não mostrar a metragem)
    $e_metros = in_array(str_replace(['.', ' '], '', $unid_original), ['m', 'ml', 'mt', 'mts', 'metro', 'metros'], true);
    if (($is_global || $is_capitulo) && $qtd > 0 && $e_metros) { $unid_display = 'vg'; $qtd_display = '1,00'; }

    // Valor por linha (vg): metros (m / ml) passam a valor global da linha
    $preco_unit_display = $item['preco_unitario_venda'];
    if ($is_detalhe_vg && $qtd > 0 && $e_metros) {
        $unid_display = 'vg';
        $qtd_display = '1,00';
        $preco_unit_display = $item['valor_venda'];
    }

    // Orçamento de cliente com total por capítulo: linhas com preço unitário 0 não mostram unidade nem quantidade
    // Orçamentos de cliente (valores por linha, valores por linha VG, total por capítulo, só valor global):
    // linhas com preço unitário e valor a 0,00 não mostram unidade, quantidade, preço unitário nem valor
    $linha_sem_valor = round(floatval($item['preco_unitario_venda'] ?? 0), 2) == 0 && round(floatval($item['valor_venda'] ?? 0), 2) == 0;
    $esconder_un_qtd = !$is_linha_capitulo && (
        ($is_capitulo && round(floatval($item['preco_unitario_venda'] ?? 0), 2) == 0)
        || (($is_detalhe || $is_global) && $linha_sem_valor)
    );
    // No total por capítulo, os títulos (com ou sem quantidade) levam o subtotal
    $e_tit_cap = $is_capitulo && array_key_exists($item['id'], $capitulos_totais);

    $pdf->SetFont('Arial', '', ($is_gestao) ? 7 : 8);
    $h = $linha_altura_base * max(1, $pdf->NbLines($w[1], $desig));

    // Resumo Comercial tal como está no formulário: cada coluna mostra exatamente o que foi preenchido
    if ($is_fiel) {
        if ($pdf->GetY() + $h > 270) { $pdf->AddPage(); $draw_header(); $pdf->SetFont('Arial', '', 8); }
        $x = $pdf->GetX(); $y = $pdf->GetY();
        $pv_f = floatval($item['preco_unitario_venda'] ?? 0); $vv_f = floatval($item['valor_venda'] ?? 0);
        $pdf->SetXY($x, $y); $pdf->Cell($w[0], $linha_altura_base, $artigo, 0, 0, 'L');
        $pdf->SetXY($x + $w[0], $y); $pdf->MultiCell($w[1], $linha_altura_base, $desig, 0, 'J');
        $curX = $x + $w[0] + $w[1];
        $pdf->SetXY($curX, $y); $pdf->Cell($w[2], $linha_altura_base, pt(trim((string)$item['unidade'])), 0, 0, 'C'); $curX += $w[2];
        $pdf->SetXY($curX, $y); $pdf->Cell($w[3], $linha_altura_base, $qtd != 0 ? number_format($qtd, 2, ',', '.') : '', 0, 0, 'C'); $curX += $w[3];
        $pdf->SetXY($curX, $y); $pdf->Cell($w[4], $linha_altura_base, $pv_f != 0 ? number_format($pv_f, 2, ',', '.') . ' ' . chr(128) : '', 0, 0, 'R'); $curX += $w[4];
        $pdf->SetXY($curX, $y); $pdf->Cell($w[5], $linha_altura_base, $vv_f != 0 ? number_format($vv_f, 2, ',', '.') . ' ' . chr(128) : '', 0, 1, 'R');
        $pdf->SetY($y + $h); $pdf->SetDrawColor(220, 220, 220); $pdf->Line(10, $pdf->GetY(), 200, $pdf->GetY()); $pdf->SetY($pdf->GetY() + 1);
        continue;
    }
    if ($pdf->GetY() + $h > 270) { $pdf->AddPage(); $draw_header(); $pdf->SetFont('Arial', '', ($is_gestao) ? 7 : 8); }
    
    $x = $pdf->GetX(); $y = $pdf->GetY();
    $pdf->SetXY($x, $y); $pdf->Cell($w[0], $linha_altura_base, $artigo, 0, 0, 'L');
    $pdf->SetXY($x + $w[0], $y); $pdf->MultiCell($w[1], $linha_altura_base, $desig, 0, 'J');
    $curX = $x + $w[0] + $w[1];
    
    $tot_cap = $capitulos_totais[$item['id']] ?? 0;
    if ($e_tit_cap && $tot_cap != 0) {
        $pdf->SetXY($curX, $y); $pdf->Cell($w[2], $linha_altura_base, 'vg', 0, 0, 'C'); $curX += $w[2];
        $pdf->SetXY($curX, $y); $pdf->Cell($w[3], $linha_altura_base, '1,00', 0, 0, 'C'); $curX += $w[3];
    } elseif (!$e_tit_cap && $qtd > 0 && !$esconder_un_qtd) {
        $pdf->SetXY($curX, $y); $pdf->Cell($w[2], $linha_altura_base, $unid_display, 0, 0, 'C'); $curX += $w[2];
        $pdf->SetXY($curX, $y); $pdf->Cell($w[3], $linha_altura_base, $qtd_display, 0, 0, 'C'); $curX += $w[3];
    } else { $curX += $w[2] + $w[3]; }

    if (!$is_limpa) {
        if ($is_gestao && $qtd > 0) {
            $pdf->SetXY($curX, $y); $pdf->Cell($w[4], $linha_altura_base, number_format($item['preco_unitario_custo'], 2, ',', '.') . ' ' . chr(128), 0, 0, 'R'); $curX += $w[4];
            $pdf->SetXY($curX, $y); $pdf->Cell($w[5], $linha_altura_base, number_format($item['valor_custo'], 2, ',', '.') . ' ' . chr(128), 0, 0, 'R'); $curX += $w[5];
            $pdf->SetXY($curX, $y); $pdf->Cell($w[6], $linha_altura_base, number_format($item['preco_unitario_venda'], 2, ',', '.') . ' ' . chr(128), 0, 0, 'R'); $curX += $w[6];
            $pdf->SetXY($curX, $y); $pdf->Cell($w[7], $linha_altura_base, number_format($item['valor_venda'], 2, ',', '.') . ' ' . chr(128), 0, 1, 'R');
        } else {
            if ($e_tit_cap) {
                // subtotal do capítulo (negrito nos capítulos de 1.º nível)
                $pdf->SetXY($curX, $y); $pdf->Cell($w[4], $linha_altura_base, '', 0, 0, 'R'); $curX += $w[4];
                if ($tot_cap != 0) {
                    $pdf->SetFont('Arial', (($capitulos_nivel[$item['id']] ?? 1) == 1) ? 'B' : '', 8);
                    $pdf->SetXY($curX, $y); $pdf->Cell($w[5], $linha_altura_base, number_format($tot_cap, 2, ',', '.') . ' ' . chr(128), 0, 1, 'R');
                    $pdf->SetFont('Arial', '', 8);
                }
            } elseif ($qtd > 0 && !$esconder_un_qtd) {
                if ($is_global || $is_capitulo) {
                    $pdf->SetXY($curX, $y); $pdf->Cell($w[4], $linha_altura_base, '', 0, 0, 'R'); $curX += $w[4];
                    $pdf->SetXY($curX, $y); $pdf->Cell($w[5], $linha_altura_base, '', 0, 1, 'R');
                } else {
                    $pdf->SetXY($curX, $y); $pdf->Cell($w[4], $linha_altura_base, number_format($preco_unit_display, 2, ',', '.') . ' ' . chr(128), 0, 0, 'R'); $curX += $w[4];
                    $pdf->SetXY($curX, $y); $pdf->Cell($w[5], $linha_altura_base, number_format($item['valor_venda'], 2, ',', '.') . ' ' . chr(128), 0, 1, 'R');
                }
            }
        }
    }
    $pdf->SetY($y + $h); $pdf->SetDrawColor(220, 220, 220); $pdf->Line(10, $pdf->GetY(), 200, $pdf->GetY()); $pdf->SetY($pdf->GetY() + 1);
}

$pdf->Ln(5); $pdf->SetAutoPageBreak(true, 20); if ($pdf->GetY() > 220) $pdf->AddPage();

$pdf->SetFont('Arial', 'B', 9); $taxa_iva = floatval($orc['taxa_iva']);

// Total por capítulo do Detalhe Interno: os totais são a soma das linhas do Detalhe Interno
// (se o Resumo Comercial tiver outro valor, o documento bate certo com os capítulos impressos)
if ($is_capitulo_interno) {
    $liq_int = 0.0;
    foreach ($itens_interno as $it_t) { if (floatval($it_t['quantidade']) > 0) $liq_int += round(floatval($it_t['valor_venda']), 2); }
    $liq_int = round($liq_int, 2);
    if (abs($liq_int - floatval($orc['valor_total_liquido'])) >= 0.01) {
        $orc['valor_total_liquido'] = $liq_int;
        $orc['valor_iva'] = round($liq_int * $taxa_iva / 100, 2);
        $orc['valor_total_com_iva'] = round($liq_int + $orc['valor_iva'], 2);
    }
}
if (!$is_limpa) {
    if ($is_gestao) {
        $margem = $orc['valor_total_liquido'] - $orc['valor_custo_real']; $y_totais = $pdf->GetY();
        $pdf->SetFont('Arial', '', 8);
        $pdf->Cell(35, 5, pt('Custo Matérias:'), 0, 0, 'R'); $pdf->Cell(25, 5, number_format($orc['valor_total_custo'], 2, ',', '.') . ' ' . chr(128), 0, 1, 'R');
        // Total de horas de mão de obra (tempo por unidade, em minutos, × quantidade — igual ao formulário)
        $min_mo = 0;
        foreach ($itens_interno as $it_mo) {
            $q_mo = (float)($it_mo['quantidade'] ?? 0); $t_mo = (float)($it_mo['tempo_mao_obra'] ?? 0);
            if ($q_mo > 0 && $t_mo > 0) $min_mo += $q_mo * $t_mo;
        }
        $min_mo_r = (int)round($min_mo);
        $horas_txt = intdiv($min_mo_r, 60) . 'h ' . str_pad((string)($min_mo_r % 60), 2, '0', STR_PAD_LEFT) . 'm';
        $pdf->Cell(35, 5, pt('Horas de Mão de Obra:'), 0, 0, 'R'); $pdf->SetFont('Arial', 'B', 8); $pdf->Cell(25, 5, pt($horas_txt), 0, 1, 'R'); $pdf->SetFont('Arial', '', 8);
        $pdf->Cell(35, 5, pt('Mão de Obra:'), 0, 0, 'R'); $pdf->Cell(25, 5, number_format($orc['mao_obra'], 2, ',', '.') . ' ' . chr(128), 'B', 1, 'R');
        $pdf->SetFont('Arial', 'B', 8); $pdf->Cell(35, 5, pt('Custo Real:'), 0, 0, 'R'); $pdf->Cell(25, 5, number_format($orc['valor_custo_real'], 2, ',', '.') . ' ' . chr(128), 0, 1, 'R');
        $pdf->SetTextColor($margem < 0 ? 192 : 39, $margem < 0 ? 57 : 174, $margem < 0 ? 43 : 96);
        $pdf->Cell(35, 6, pt('Margem:'), 0, 0, 'R'); $pdf->Cell(25, 6, number_format($margem, 2, ',', '.') . ' ' . chr(128), 0, 1, 'R');
        $pdf->SetXY(135, $y_totais); $pdf->SetTextColor(0,0,0); $pdf->SetFont('Arial', 'B', 9);
    } else { $pdf->SetX(135); }
    
    $pdf->Cell(35, 6, pt('Subtotal:'), 0, 0, 'R'); $pdf->Cell(30, 6, number_format($orc['valor_total_liquido'], 2, ',', '.') . ' ' . chr(128), ($taxa_iva > 0) ? 0 : 'B', 1, 'R');
    if ($taxa_iva > 0) {
        $pdf->SetX(135); $pdf->SetFont('Arial', '', 9); $pdf->Cell(35, 6, pt('IVA (' . $taxa_iva . '%):'), 0, 0, 'R');
        $pdf->Cell(30, 6, number_format($orc['valor_iva'], 2, ',', '.') . ' ' . chr(128), 'B', 1, 'R');
    }
    $pdf->SetX(135); $pdf->SetFont('Arial', 'B', 11); $pdf->SetTextColor(179, 42, 42);
    $pdf->Cell(35, 8, pt('TOTAL:'), 0, 0, 'R'); $pdf->Cell(30, 8, number_format($orc['valor_total_com_iva'], 2, ',', '.') . ' ' . chr(128), 0, 1, 'R');
}

function imprimir_lista($pdf, $titulo, $dados) {
    if (!empty($dados)) {
        $pdf->Ln(5); $pdf->SetTextColor(0); $pdf->SetFont('Arial', 'B', 9); $pdf->Cell(0, 8, pt($titulo . ':'), 0, 1, 'L');
        $pdf->SetFont('Arial', '', 8);
        foreach ($dados as $linha) { if (trim((string)$linha) !== '') { $pdf->SetX(15); $pdf->MultiCell(0, 5, pt(trim($linha)), 0, 'J'); } }
    }
}

imprimir_lista($pdf, 'Inclui', $inclui_lista);
imprimir_lista($pdf, 'Não Inclui', $nao_inclui_lista);
imprimir_lista($pdf, 'Observações', $observacoes);
if (!$is_gestao) {
    imprimir_lista($pdf, 'Garantias', $garantias);
}

$pdf->Ln(5); $pdf->SetTextColor(0); $pdf->SetFont('Arial', 'B', 8);
$pdf->SetX(15); $pdf->Cell(0, 5, ($taxa_iva == 0) ? pt('IVA Autoliquidação') : pt('IVA à taxa de ' . $taxa_iva . '%'), 0, 1, 'L');
$pdf->Ln(3); $pdf->SetFont('Arial', '', 8);
$pdf->SetX(15); $pdf->Cell(0, 5, pt('Prazo de Validade: ' . $validade_texto), 0, 1, 'L');
$pdf->SetX(15); $pdf->Cell(0, 5, pt('Condições de Pagamento: Habitual'), 0, 1, 'L');
$pdf->SetX(15); $pdf->Cell(0, 5, pt('Entrada em obra e Prazo de execução: a combinar'), 0, 1, 'L');
$pdf->Ln(5); $pdf->SetX(15); $pdf->Cell(0, 5, pt('Mário Abreu tlm. 914 259 487 email. geral@mabreu.pt'), 0, 1, 'L');

ob_end_clean();
// Nome do ficheiro: ORC + número do orçamento (ex.: ORC260234.pdf; complementar: ORC260234-C1.pdf)
$nome_pdf = 'ORC' . preg_replace('/[^A-Za-z0-9_-]/', '', (string)$orc['numero_orcamento']) . '.pdf';
$pdf->Output('I', $nome_pdf);
?>