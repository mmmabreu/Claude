<?php
// =====================================================================
// TERMO SCIE + FICHAS TÉCNICAS + CERTIFICADOS num só PDF (termo_scie_dossier.php?id=TERMO)
// A junção é feita no browser (pdf-lib), como em obra_fame_dossier.php: o servidor não consegue juntar PDFs modernos.
//   1) Termo de responsabilidade (termo_scie_pdf.php?doc=termo) — igual ao de sempre
//   2) Para cada produto instalado (por ordem dos equipamentos, sem repetir): ficha técnica e certificado de
//      conformidade da biblioteca (fichas_tecnicas.php), cada página com "Termo SCIE … · Anexo N" no canto.
// Produto de cada equipamento: o artigo da tabela de preços ligado no termo → a sua ficha na biblioteca;
// sem artigo ligado, procura-se na biblioteca um produto com a mesma referência (e a mesma marca, se tiver).
// O relatório (termo_scie_pdf.php?doc=relatorio) não muda.
// =====================================================================
session_start();
require_once 'db.php';
require_once 'functions.php';
require_once 'fichas_tecnicas_lib.php';

// REGRA DE OURO 1: Validação de sessão
if (!isset($_SESSION['user_id'])) { header("Location: index.php"); exit; }

// REGRA DE OURO 2: Acesso restrito (o mesmo dos termos)
$perfil_user = $_SESSION['perfil'] ?? '';
$stmt_perm = $pdo->prepare("SELECT modulo FROM permissoes WHERE grupo = :perfil AND pode_aceder = 1");
$stmt_perm->execute(['perfil' => $perfil_user]);
if (!in_array($perfil_user, ['admin', 'administrativo']) && !in_array('termos', $stmt_perm->fetchAll(PDO::FETCH_COLUMN))) {
    header("Location: index.php?erro=sem_permissao"); exit;
}

// REGRA DE OURO 3: primeiro nome no menu
$primeiro_nome = explode(' ', trim($_SESSION['nome'] ?? 'Utilizador'))[0];

// REGRA DE OURO 5: ID validado como número inteiro
$id_termo = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$st = $pdo->prepare("SELECT t.*, o.nome AS nome_obra, o.referencia_obra FROM termos_scie t LEFT JOIN obras o ON o.id = t.id_obra WHERE t.id = ?");
$st->execute([$id_termo ?: 0]);
$termo = $st->fetch(PDO::FETCH_ASSOC);
if (!$termo) { header("Location: termos_scie.php"); exit; }

ft_migrar($pdo);
$norm_ref = fn($t) => strtolower(preg_replace('/[^A-Za-z0-9]/', '', (string)$t));
$norm_txt = fn($t) => mb_strtolower(trim((string)$t), 'UTF-8');

// ---------- Documentos dos produtos instalados ----------
function documentosDoTermo($pdo, $id_termo, $norm_ref, $norm_txt) {
    $tem_col = (bool)$pdo->query("SHOW COLUMNS FROM termos_scie_items LIKE 'id_artigo_tabela'")->fetch();
    $st = $pdo->prepare("SELECT * FROM termos_scie_items WHERE id_termo = ? ORDER BY CAST(numero AS UNSIGNED) ASC, numero ASC, id ASC");
    $st->execute([$id_termo]);
    $itens = $st->fetchAll(PDO::FETCH_ASSOC);

    $fichas = [];
    foreach ($pdo->query("SELECT * FROM fichas_tecnicas WHERE ativo = 1")->fetchAll(PDO::FETCH_ASSOC) as $f) $fichas[(int)$f['id']] = $f;
    $ficha_do_artigo = [];
    foreach ($pdo->query("SELECT id, id_ficha_tecnica FROM tabela_precos WHERE id_ficha_tecnica IS NOT NULL")->fetchAll(PDO::FETCH_ASSOC) as $a) $ficha_do_artigo[(int)$a['id']] = (int)$a['id_ficha_tecnica'];
    // Biblioteca por referência (para equipamentos sem artigo ligado)
    $por_ref = [];
    foreach ($fichas as $f) { $k = $norm_ref($f['referencia']); if ($k !== '') $por_ref[$k][] = $f; }

    $produtos = []; $sem_docs = [];
    foreach ($itens as $it) {
        $id_f = 0;
        if ($tem_col && !empty($it['id_artigo_tabela'])) $id_f = $ficha_do_artigo[(int)$it['id_artigo_tabela']] ?? 0;
        if (!$id_f || !isset($fichas[$id_f])) {
            $id_f = 0;
            $cands = $por_ref[$norm_ref($it['referencia'])] ?? [];
            foreach ($cands as $c) {
                if ($norm_txt($it['marca']) === '' || $norm_txt($c['marca']) === '' || $norm_txt($c['marca']) === $norm_txt($it['marca'])) { $id_f = (int)$c['id']; break; }
            }
        }
        $nome_eq = trim($it['descricao'] . ($it['referencia'] !== '' ? ' (' . $it['referencia'] . ')' : ''));
        if (!$id_f) { if ($nome_eq !== '') $sem_docs[mb_strtolower($nome_eq, 'UTF-8')] = $nome_eq; continue; }
        if (!isset($produtos[$id_f])) $produtos[$id_f] = $fichas[$id_f];
    }

    // Lista de anexos: ficha e certificado de cada produto (só os ficheiros que existem)
    $docs = []; $n = 0; $alertas = [];
    foreach ($produtos as $f) {
        $nome = trim($f['marca'] . ' ' . $f['modelo']);
        $tem_algum = false;
        foreach ([['ficha', 'Ficha técnica', $f['ficheiro']], ['certificado', 'Certificado de conformidade', $f['cert_ficheiro']]] as [$tipo, $rotulo, $fich]) {
            if ($fich === '' || !is_file(FT_PASTA . basename($fich))) continue;
            $tem_algum = true;
            $docs[] = ['n' => ++$n, 'tipo_doc' => $tipo, 'rotulo' => $rotulo, 'nome' => $nome, 'referencia' => (string)$f['referencia'],
                       'url' => ft_url($fich), 'tipo' => strtolower(pathinfo($fich, PATHINFO_EXTENSION)),
                       'validade' => $tipo === 'certificado' ? (string)($f['cert_validade'] ?? '') : '', 'estado' => $tipo === 'certificado' ? ft_estado_certificado($f) : ''];
        }
        if (!$tem_algum) $alertas[] = $nome . ': sem ficha técnica nem certificado na biblioteca.';
        elseif ($f['cert_ficheiro'] === '') $alertas[] = $nome . ': sem certificado de conformidade.';
        elseif (ft_estado_certificado($f) === 'expirado') $alertas[] = $nome . ': o certificado expirou a ' . date('d/m/Y', strtotime($f['cert_validade'])) . '.';
    }
    return [$docs, $alertas, array_values($sem_docs)];
}

if (!empty($_GET['lista'])) {
    header('Content-Type: application/json; charset=utf-8');
    try {
        [$docs, $alertas, $sem] = documentosDoTermo($pdo, $id_termo, $norm_ref, $norm_txt);
        echo json_encode(['docs' => $docs, 'alertas' => $alertas, 'sem_produto' => $sem], JSON_UNESCAPED_UNICODE);
    } catch (Exception $e) {
        error_log('termo_scie_dossier.php: ' . $e->getMessage());
        echo json_encode(['docs' => [], 'alertas' => ['Não foi possível ler os documentos dos produtos.'], 'sem_produto' => []]);
    }
    exit;
}

$num_doc = 'Termo SCIE ' . ($termo['referencia_obra'] ?: ('#' . $termo['id']));
$nome_ficheiro = 'Termo_SCIE_' . preg_replace('/[^A-Za-z0-9_-]/', '_', (string)($termo['nome_obra'] ?: $termo['id'])) . '_com_anexos.pdf';
$e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="pt-PT">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Termo SCIE com fichas e certificados - <?= $e($termo['nome_obra'] ?? '') ?> - MAB</title>
    <?php /* REGRA DE OURO 8: ícone MAB no separador */ ?>
    <link rel="icon" type="image/png" href="/mab.png">
    <link rel="apple-touch-icon" href="/mab.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { box-sizing: border-box; font-family: system-ui, -apple-system, sans-serif; }
        body { background: #f7f9fc; margin: 0; color: #333; font-size: 14px; }
        .topbar { background: #fff; padding: 10px 40px; box-shadow: 0 1px 0 #e8ecf1; display: flex; justify-content: space-between; align-items: center; position: sticky; top: 0; z-index: 100; }
        .topbar .logo { height: 35px; display: block; }
        .topbar-right { display: flex; align-items: center; gap: 12px; }
        .btn-voltar { width: 40px; height: 40px; border-radius: 50%; background: #fff; border: 1px solid #e8ecf1; color: #576574; display: inline-flex; align-items: center; justify-content: center; text-decoration: none; font-size: 16px; }
        .btn-voltar:hover { background: #fdf2f2; color: #b32a2a; }
        .user-menu { position: relative; }
        .user-btn { background: #fff; border: 1px solid #e8ecf1; border-radius: 30px; font-weight: 600; color: #2c3e50; cursor: pointer; display: flex; align-items: center; gap: 10px; padding: 5px 14px 5px 5px; font-family: inherit; }
        .user-avatar { background: #e74c3c; color: #fff; width: 28px; height: 28px; border-radius: 50%; display: flex; justify-content: center; align-items: center; font-size: 13px; }
        .dropdown-content { display: none; position: absolute; right: 0; top: 100%; background: #fff; min-width: 180px; box-shadow: 0 12px 30px rgba(0,0,0,0.1); border-radius: 10px; border: 1px solid #e8ecf1; overflow: hidden; margin-top: 6px; z-index: 200; }
        .user-menu:hover .dropdown-content, .user-menu.aberto .dropdown-content { display: block; }
        .dropdown-content a { color: #576574; padding: 12px 20px; text-decoration: none; display: block; }
        .container { max-width: 1100px; margin: 20px auto; padding: 0 15px; }
        h1 { margin: 0 0 4px; font-size: 20px; color: #2c3e50; }
        .sub { color: #7f8c8d; font-size: 13px; margin-bottom: 16px; }
        .card { background: #fff; border: 1px solid #e1e5ea; border-radius: 12px; padding: 18px; margin-bottom: 16px; }
        .barra { height: 10px; background: #eef1f5; border-radius: 10px; overflow: hidden; margin: 10px 0 6px; }
        .barra div { height: 100%; width: 0; background: #b32a2a; transition: width .2s; }
        #estado { color: #576574; font-size: 13px; }
        .lista { list-style: none; padding: 0; margin: 12px 0 0; font-size: 13px; }
        .lista li { padding: 6px 0; border-bottom: 1px solid #f4f5f7; display: flex; gap: 8px; align-items: flex-start; }
        .lista li i { width: 16px; text-align: center; margin-top: 2px; }
        .ok i { color: #27ae60; } .falha i { color: #e67e22; } .falha { color: #a04000; }
        .acoes { display: none; gap: 10px; flex-wrap: wrap; margin-top: 14px; }
        .btn { border: none; border-radius: 9px; padding: 12px 18px; font-weight: 700; cursor: pointer; font-size: 14px; display: inline-flex; align-items: center; gap: 8px; text-decoration: none; font-family: inherit; }
        .btn-verm { background: #b32a2a; color: #fff; }
        .btn-sec { background: #fff; color: #34495e; border: 1px solid #dcdde1; }
        iframe { width: 100%; height: 75vh; border: 1px solid #e1e5ea; border-radius: 10px; display: none; background: #fff; }
        @media (max-width: 768px) { .topbar { padding: 10px 15px; } .user-nome { display: none; } .acoes .btn { flex: 1; justify-content: center; } iframe { height: 60vh; } }
    </style>
</head>
<body>
    <header class="topbar">
        <div><a href="index.php" title="Página inicial"><img src="mab.png" class="logo" alt="MAB"></a></div>
        <div class="topbar-right">
            <a href="termos_scie.php" class="btn-voltar" title="Voltar" aria-label="Voltar"><i class="fa-solid fa-arrow-left"></i></a>
            <div class="user-menu">
                <button type="button" class="user-btn"><div class="user-avatar"><i class="fa-solid fa-user"></i></div><span class="user-nome"><?= $e($primeiro_nome) ?></span></button>
                <div class="dropdown-content">
                    <a href="perfil.php"><i class="fa-solid fa-id-badge"></i> O meu perfil</a>
                    <a href="index.php?logout=1" style="color:#e74c3c;"><i class="fa-solid fa-right-from-bracket"></i> Sair</a>
                </div>
            </div>
        </div>
    </header>

    <div class="container">
        <h1><i class="fa-solid fa-file-shield" style="color:#b32a2a;"></i> Termo de responsabilidade com fichas e certificados</h1>
        <div class="sub"><?= $e($num_doc) ?> · <?= $e($termo['nome_obra'] ?? '') ?></div>

        <div class="card">
            <div id="estado">A preparar…</div>
            <div class="barra"><div id="barra"></div></div>
            <ul class="lista" id="lista"></ul>
            <div class="acoes" id="acoes">
                <a class="btn btn-verm" id="btnDescarregar" href="#" download="<?= $e($nome_ficheiro) ?>"><i class="fa-solid fa-download"></i> Descarregar PDF</a>
                <a class="btn btn-sec" id="btnAbrir" href="#" target="_blank"><i class="fa-solid fa-up-right-from-square"></i> Abrir noutro separador</a>
                <a class="btn btn-sec" href="termo_scie_pdf.php?id=<?= (int)$id_termo ?>&amp;doc=termo" target="_blank"><i class="fa-solid fa-file-pdf"></i> Só o termo</a>
                <a class="btn btn-sec" href="fichas_tecnicas.php" target="_blank"><i class="fa-solid fa-book-open"></i> Fichas e certificados</a>
            </div>
        </div>
        <iframe id="ver" title="Pré-visualização"></iframe>
    </div>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/pdf-lib/1.17.1/pdf-lib.min.js"></script>
    <script>
    const ID = <?= (int)$id_termo ?>;
    const NUM_DOC = <?= json_encode($num_doc, JSON_UNESCAPED_UNICODE) ?>;

    (function () {
        const menu = document.querySelector('.user-menu');
        menu.querySelector('.user-btn').addEventListener('click', e => { e.stopPropagation(); menu.classList.toggle('aberto'); });
        document.addEventListener('click', () => menu.classList.remove('aberto'));
    })();

    const estado = t => document.getElementById('estado').innerHTML = t;
    const barra = p => document.getElementById('barra').style.width = Math.round(p) + '%';
    function item(ok, txt) {
        const li = document.createElement('li');
        li.className = ok ? 'ok' : 'falha';
        li.innerHTML = '<i class="fa-solid ' + (ok ? 'fa-circle-check' : 'fa-triangle-exclamation') + '"></i><span></span>';
        li.querySelector('span').textContent = txt;
        document.getElementById('lista').appendChild(li);
    }
    // Só caracteres que a fonte padrão do PDF consegue escrever
    const limpo = t => String(t).replace(/[–—]/g, '-').replace(/[^\x20-\x7E\xA0-\xFF]/g, '');
    async function buscar(url) {
        const r = await fetch(url, { credentials: 'same-origin' });
        if (!r.ok) throw new Error('HTTP ' + r.status);
        return new Uint8Array(await r.arrayBuffer());
    }

    (async () => {
        try {
            if (!window.PDFLib) throw new Error('Não foi possível carregar o pdf-lib (verifique a ligação à internet).');
            const { PDFDocument, StandardFonts, rgb } = PDFLib;

            estado('A gerar o termo…'); barra(5);
            const termoBytes = await buscar('termo_scie_pdf.php?id=' + ID + '&doc=termo');
            if (String.fromCharCode(...termoBytes.slice(0, 5)) !== '%PDF-') throw new Error('O PDF do termo não foi gerado (sessão expirada?).');
            const final = await PDFDocument.load(termoBytes);
            item(true, 'Termo de responsabilidade (' + final.getPageCount() + ' pág.)');

            const info = await (await fetch('termo_scie_dossier.php?id=' + ID + '&lista=1', { credentials: 'same-origin' })).json();
            const lista = info.docs || [];
            if (!lista.length) item(false, 'Nenhum produto do termo tem ficha técnica ou certificado na biblioteca. Ligue os equipamentos aos artigos da tabela de preços (coluna "Artigo") e carregue os documentos em Fichas e certificados.');
            (info.alertas || []).forEach(a => item(false, a));
            if ((info.sem_produto || []).length) item(false, 'Sem produto da biblioteca: ' + info.sem_produto.join(', ') + '.');
            const fonte = await final.embedFont(StandardFonts.HelveticaBold);

            let feitas = 0, falhas = 0;
            for (const f of lista) {
                estado('A juntar o anexo ' + f.n + ' de ' + lista.length + ': ' + f.rotulo + ' – ' + f.nome);
                barra(10 + (feitas / Math.max(1, lista.length)) * 85);
                const etiqueta = limpo(NUM_DOC + '  ·  Anexo ' + f.n + ' · ' + (f.tipo_doc === 'certificado' ? 'Certificado' : 'Ficha técnica'));
                try {
                    const bytes = await buscar(f.url);
                    let novas = [];
                    if (f.tipo === 'jpg' || f.tipo === 'jpeg' || f.tipo === 'png') {
                        const img = f.tipo === 'png' ? await final.embedPng(bytes) : await final.embedJpg(bytes);
                        const pg = final.addPage([595.28, 841.89]);
                        const m = 36, esc = Math.min((595.28 - 2 * m) / img.width, (841.89 - 2 * m - 20) / img.height);
                        const w = img.width * esc, h = img.height * esc;
                        pg.drawImage(img, { x: (595.28 - w) / 2, y: (841.89 - 20 - h) / 2, width: w, height: h });
                        novas = [pg];
                    } else {
                        const doc = await PDFDocument.load(bytes, { ignoreEncryption: true });
                        const copiadas = await final.copyPages(doc, doc.getPageIndices());
                        copiadas.forEach(p => { final.addPage(p); novas.push(p); });
                    }
                    novas.forEach(pg => {
                        const { width, height } = pg.getSize();
                        const tam = 8, w = fonte.widthOfTextAtSize(etiqueta, tam);
                        pg.drawRectangle({ x: width - w - 22, y: height - 22, width: w + 12, height: 14, color: rgb(1, 1, 1), opacity: 0.9 });
                        pg.drawText(etiqueta, { x: width - w - 16, y: height - 18, size: tam, font: fonte, color: rgb(0.70, 0.16, 0.16) });
                    });
                    item(true, 'Anexo ' + f.n + ' – ' + f.rotulo + ' – ' + f.nome + (f.referencia ? ' (' + f.referencia + ')' : '') + ' · ' + novas.length + ' pág.'
                        + (f.estado === 'expirado' ? ' · ATENÇÃO: certificado expirado' : (f.estado === 'a_expirar' ? ' · certificado a expirar' : '')));
                } catch (err) {
                    falhas++;
                    item(false, 'Anexo ' + f.n + ' – ' + f.rotulo + ' – ' + f.nome + ': não foi possível juntar (' + err.message + '). Junte este anexo à mão.');
                }
                feitas++;
            }

            estado('A criar o PDF final…'); barra(97);
            final.setTitle(NUM_DOC + ' (com fichas técnicas e certificados)');
            const blob = new Blob([await final.save()], { type: 'application/pdf' });
            const url = URL.createObjectURL(blob);
            document.getElementById('btnDescarregar').href = url;
            document.getElementById('btnAbrir').href = url;
            const ver = document.getElementById('ver'); ver.src = url; ver.style.display = 'block';
            document.getElementById('acoes').style.display = 'flex';
            barra(100);
            estado('<strong>Pronto:</strong> ' + final.getPageCount() + ' páginas · ' + feitas + ' anexo(s)' + (falhas ? ' · <span style="color:#a04000;">' + falhas + ' por juntar à mão</span>' : '') + '.');
        } catch (err) {
            estado('<span style="color:#c0392b;"><i class="fa-solid fa-triangle-exclamation"></i> ' + err.message + '</span>');
            document.getElementById('acoes').style.display = 'flex';
            document.getElementById('btnDescarregar').style.display = 'none';
            document.getElementById('btnAbrir').style.display = 'none';
        }
    })();
    </script>
</body>
</html>
