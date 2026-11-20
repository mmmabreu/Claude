<?php
// =====================================================================
// FAME + FICHAS TÉCNICAS num só PDF (obra_fame_dossier.php?id=OBRA[&esp=ITED])
// A junção é feita no browser (pdf-lib): o servidor não consegue juntar PDFs modernos.
//   1) PDF da FAME (obra_fame_pdf.php)
//   2) Fichas técnicas da biblioteca, pela ordem dos anexos (Anexo 1, 2, …), cada página com a marca
//      "FAME … · Anexo N" no canto superior direito. Fichas em JPG / PNG passam a uma página A4.
// =====================================================================
session_start();
require_once 'db.php';
require_once 'obra_fame_comum.php';

if (!isset($_SESSION['user_id'])) { header("Location: index.php"); exit; }
if (!fameTemAcesso($pdo)) { header("Location: index.php?erro=sem_permissao"); exit; }

$nome_completo = $_SESSION['nome'] ?? 'Utilizador';
$primeiro_nome = explode(' ', trim($nome_completo))[0];

fameCriarTabelas($pdo);
$id_obra = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$obra = $id_obra ? fameObra($pdo, $id_obra) : null;
if (!$obra) { header("Location: obras.php"); exit; }
$so_esp = trim((string)($_GET['esp'] ?? ''));

$orc = fameOrcamentoDaObra($pdo, $obra);
$info = fameDaObra($pdo, $id_obra);
$ref = $obra['referencia_obra'] ?: ($orc['numero_orcamento'] ?? (string)$id_obra);
$rev = (int)$info['revisao'];
$num_doc = 'FAME ' . $ref . ($so_esp !== '' ? '-' . fameSigla($so_esp) : '') . ' - Rev. ' . str_pad((string)$rev, 2, '0', STR_PAD_LEFT);
$nome_ficheiro = 'FAME_' . preg_replace('/[^A-Za-z0-9_-]/', '_', $ref . ($so_esp !== '' ? '-' . fameSigla($so_esp) : '')) . '_Rev' . $rev . '_com_fichas.pdf';
$q = 'id=' . $id_obra . ($so_esp !== '' ? '&esp=' . rawurlencode($so_esp) : '');
$e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="pt-PT">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>FAME com fichas - <?= $e($obra['nome']) ?> - MAB</title>
    <link rel="icon" type="image/png" href="mab.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { box-sizing: border-box; font-family: system-ui, -apple-system, sans-serif; }
        body { background: #f7f9fc; margin: 0; color: #333; font-size: 14px; }
        .topbar { background: #fff; padding: 10px 40px; box-shadow: 0 1px 0 #e8ecf1; display: flex; justify-content: space-between; align-items: center; position: sticky; top: 0; z-index: 100; }
        .topbar-left .logo { height: 35px; display: block; }
        .topbar-right { display: flex; align-items: center; gap: 12px; }
        .btn-voltar { width: 40px; height: 40px; border-radius: 50%; background: #fff; border: 1px solid #e8ecf1; color: #576574; display: inline-flex; align-items: center; justify-content: center; text-decoration: none; font-size: 16px; transition: all 0.2s ease; box-shadow: 0 2px 6px rgba(0,0,0,0.04); }
        .btn-voltar:hover { background: #eef2f6; color: #34495e; transform: translateX(-2px); }
        .user-menu { position: relative; }
        .user-btn { background: #fff; border: 1px solid #e8ecf1; border-radius: 30px; font-size: 14px; font-weight: 600; color: #2c3e50; cursor: pointer; display: flex; align-items: center; gap: 10px; padding: 5px 14px 5px 5px; font-family: inherit; }
        .user-avatar { background: #e74c3c; color: #fff; width: 28px; height: 28px; border-radius: 50%; display: flex; justify-content: center; align-items: center; font-size: 13px; }
        .dropdown-content { display: none; position: absolute; right: 0; top: 100%; background: #fff; min-width: 180px; box-shadow: 0 12px 30px rgba(0,0,0,0.1); border-radius: 10px; border: 1px solid #e8ecf1; overflow: hidden; margin-top: 6px; z-index: 200; }
        .user-menu.aberto .dropdown-content { display: block; }
        .dropdown-content a { color: #576574; padding: 12px 20px; text-decoration: none; display: block; }

        .container { max-width: 1100px; margin: 20px auto; padding: 0 15px; }
        h1 { margin: 0 0 4px; font-size: 20px; color: #2c3e50; }
        .sub { color: #7f8c8d; font-size: 13px; margin-bottom: 16px; }
        .card { background: #fff; border: 1px solid #e1e5ea; border-radius: 12px; padding: 18px; margin-bottom: 16px; }
        .barra { height: 10px; background: #eef1f5; border-radius: 10px; overflow: hidden; margin: 10px 0 6px; }
        .barra div { height: 100%; width: 0; background: #16a085; transition: width .2s; }
        #estado { color: #576574; font-size: 13px; }
        .lista { list-style: none; padding: 0; margin: 12px 0 0; font-size: 13px; }
        .lista li { padding: 6px 0; border-bottom: 1px solid #f4f5f7; display: flex; gap: 8px; align-items: center; }
        .lista li i { width: 16px; text-align: center; }
        .ok i { color: #27ae60; } .falha i { color: #e67e22; } .falha { color: #a04000; }
        .acoes { display: none; gap: 10px; flex-wrap: wrap; margin-top: 14px; }
        .btn { border: none; border-radius: 9px; padding: 12px 18px; font-weight: 700; cursor: pointer; font-size: 14px; display: inline-flex; align-items: center; gap: 8px; text-decoration: none; font-family: inherit; }
        .btn-verde { background: #16a085; color: #fff; }
        .btn-sec { background: #fff; color: #34495e; border: 1px solid #dcdde1; }
        iframe { width: 100%; height: 75vh; border: 1px solid #e1e5ea; border-radius: 10px; display: none; background: #fff; }
        @media (max-width: 768px) { .topbar { padding: 10px 15px; } .acoes .btn { flex: 1; justify-content: center; } iframe { height: 60vh; } }
    </style>
</head>
<body>
    <header class="topbar">
        <div class="topbar-left"><a href="index.php" title="Página inicial"><img src="mab.png" class="logo" alt="MAB"></a></div>
        <div class="topbar-right">
            <a href="obra_fame.php?id=<?= (int)$id_obra ?>" class="btn-voltar" title="Voltar à FAME" aria-label="Voltar"><i class="fa-solid fa-arrow-left"></i></a>
            <div class="user-menu" id="userMenu">
                <button type="button" class="user-btn" onclick="event.stopPropagation(); document.getElementById('userMenu').classList.toggle('aberto');">
                    <div class="user-avatar"><i class="fa-solid fa-user"></i></div><?= $e($primeiro_nome) ?>
                </button>
                <div class="dropdown-content">
                    <a href="perfil.php"><i class="fa-solid fa-id-badge"></i> O meu perfil</a>
                    <a href="index.php?logout=1" style="color:#e74c3c;"><i class="fa-solid fa-right-from-bracket"></i> Sair</a>
                </div>
            </div>
        </div>
    </header>

    <div class="container">
        <h1><i class="fa-solid fa-file-zipper" style="color:#16a085;"></i> FAME com fichas técnicas</h1>
        <div class="sub"><?= $e($num_doc) ?> · <?= $e($obra['nome']) ?></div>

        <div class="card">
            <div id="estado">A preparar…</div>
            <div class="barra"><div id="barra"></div></div>
            <ul class="lista" id="lista"></ul>
            <div class="acoes" id="acoes">
                <a class="btn btn-verde" id="btnDescarregar" href="#" download="<?= $e($nome_ficheiro) ?>"><i class="fa-solid fa-download"></i> Descarregar PDF</a>
                <a class="btn btn-sec" id="btnAbrir" href="#" target="_blank"><i class="fa-solid fa-up-right-from-square"></i> Abrir noutro separador</a>
                <a class="btn btn-sec" href="obra_fame_pdf.php?<?= $e($q) ?>" target="_blank"><i class="fa-solid fa-file-pdf"></i> Só a FAME</a>
            </div>
        </div>
        <iframe id="ver" title="Pré-visualização"></iframe>
    </div>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/pdf-lib/1.17.1/pdf-lib.min.js"></script>
    <script>
    const Q = <?= json_encode($q) ?>;
    const NUM_DOC = <?= json_encode($num_doc, JSON_UNESCAPED_UNICODE) ?>;
    document.addEventListener('click', () => document.getElementById('userMenu').classList.remove('aberto'));

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
    const limpo = t => String(t).replace(/[–—]/g, '-').replace(/[^\x20-\x7E -ÿ]/g, '');

    async function buscar(url) {
        const r = await fetch(url, { credentials: 'same-origin' });
        if (!r.ok) throw new Error('HTTP ' + r.status);
        return new Uint8Array(await r.arrayBuffer());
    }

    (async () => {
        try {
            if (!window.PDFLib) throw new Error('Não foi possível carregar o pdf-lib (verifica a ligação à internet).');
            const { PDFDocument, StandardFonts, rgb } = PDFLib;

            estado('A gerar a FAME…'); barra(5);
            const fameBytes = await buscar('obra_fame_pdf.php?' + Q);
            if (String.fromCharCode(...fameBytes.slice(0, 5)) !== '%PDF-') throw new Error('O PDF da FAME não foi gerado (sessão expirada?).');
            const final = await PDFDocument.load(fameBytes);
            item(true, 'FAME (' + final.getPageCount() + ' pág.)');

            const lista = (await (await fetch('obra_fame_pdf.php?' + Q + '&lista_fichas=1', { credentials: 'same-origin' })).json()).fichas || [];
            if (!lista.length) item(false, 'Nenhuma linha da FAME tem ficha da biblioteca — escolhe as fichas na coluna «Ficha técnica».');
            const fonte = await final.embedFont(StandardFonts.HelveticaBold);

            let feitas = 0, falhas = 0;
            for (const f of lista) {
                estado('A juntar o anexo ' + f.n + ' de ' + lista.length + ': ' + f.nome);
                barra(10 + (feitas / Math.max(1, lista.length)) * 85);
                const etiqueta = limpo(NUM_DOC + '  ·  Anexo ' + f.n);
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
                    // Identificação do anexo no canto superior direito de cada página
                    novas.forEach(pg => {
                        const { width, height } = pg.getSize();
                        const tam = 8, w = fonte.widthOfTextAtSize(etiqueta, tam);
                        pg.drawRectangle({ x: width - w - 22, y: height - 22, width: w + 12, height: 14, color: rgb(1, 1, 1), opacity: 0.9 });
                        pg.drawText(etiqueta, { x: width - w - 16, y: height - 18, size: tam, font: fonte, color: rgb(0.09, 0.63, 0.52) });
                    });
                    item(true, 'Anexo ' + f.n + ' – ' + f.nome + (f.referencia ? ' (' + f.referencia + ')' : '') + ' · ' + novas.length + ' pág.');
                } catch (err) {
                    falhas++;
                    item(false, 'Anexo ' + f.n + ' – ' + f.nome + ': não foi possível juntar (' + err.message + '). Junta este anexo à mão.');
                }
                feitas++;
            }

            estado('A criar o PDF final…'); barra(97);
            final.setTitle(NUM_DOC + ' (com fichas técnicas)');
            const blob = new Blob([await final.save()], { type: 'application/pdf' });
            const url = URL.createObjectURL(blob);
            document.getElementById('btnDescarregar').href = url;
            document.getElementById('btnAbrir').href = url;
            const ver = document.getElementById('ver'); ver.src = url; ver.style.display = 'block';
            document.getElementById('acoes').style.display = 'flex';
            barra(100);
            estado('<strong>Pronto:</strong> ' + final.getPageCount() + ' páginas · ' + feitas + ' ficha(s)' + (falhas ? ' · <span style="color:#a04000;">' + falhas + ' por juntar à mão</span>' : '') + '.');
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
