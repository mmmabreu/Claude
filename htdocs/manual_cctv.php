<?php
/*
 * MAN-002 — Manual do Sistema de Videovigilância (CCTV)
 * m.Abreu - Instalações Técnicas, Unipessoal, Lda.
 * Modelo genérico: preencher no ecrã e imprimir / guardar em PDF.
 * Opcional: manual_cctv.php?num=123 pré-preenche o n.º de obra.
 */
$num = isset($_GET['num']) ? htmlspecialchars(trim($_GET['num']), ENT_QUOTES, 'UTF-8') : '';
$hoje = date('d/m/Y');
?>
<!DOCTYPE html>
<html lang="pt-PT">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>MAN-002 · Manual do Sistema CCTV<?= $num ? ' · Obra ' . $num : '' ?></title>
<style>
:root{
  --mab:#9D1920; --mab-esc:#7a1318; --txt:#1f1f1f; --sub:#5b5b5b;
  --linha:#d9d9d9; --fundo:#f4f4f4; --papel:#ffffff; --campo:#fff8e6;
}
*{box-sizing:border-box}
html,body{margin:0}
body{font-family:"Segoe UI",Roboto,Arial,sans-serif;color:var(--txt);background:var(--fundo);line-height:1.5;font-size:15px}

/* ---------- barra superior ---------- */
.topo{position:sticky;top:0;z-index:10;display:flex;align-items:center;gap:12px;
  background:var(--papel);border-bottom:1px solid var(--linha);padding:10px 16px}
.logo{display:inline-flex;align-items:center;justify-content:center;width:46px;height:46px;border-radius:50%;
  background:var(--mab);color:#fff;font-weight:800;font-size:13px;letter-spacing:.5px;text-decoration:none;
  box-shadow:0 0 0 2px #fff,0 0 0 3px var(--mab),0 0 0 5px #fff,0 0 0 6px var(--mab);margin:6px}
.voltar{display:inline-flex;align-items:center;justify-content:center;width:40px;height:40px;border-radius:50%;
  border:1px solid var(--linha);background:#fff;color:var(--txt);text-decoration:none;cursor:pointer}
.voltar:hover{background:var(--fundo)}
.topo h1{font-size:16px;margin:0;flex:1;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.btn{border:0;border-radius:8px;padding:9px 14px;font-weight:600;cursor:pointer;font-size:14px}
.btn-p{background:var(--mab);color:#fff}.btn-p:hover{background:var(--mab-esc)}
.btn-s{background:#fff;border:1px solid var(--linha);color:var(--txt)}

/* ---------- documento ---------- */
.doc{max-width:900px;margin:20px auto;background:var(--papel);padding:36px 44px;border-radius:10px;box-shadow:0 1px 4px rgba(0,0,0,.08)}
.cab{display:flex;align-items:center;gap:16px;border-bottom:3px solid var(--mab);padding-bottom:14px;margin-bottom:22px}
.cab .logo{width:58px;height:58px;font-size:16px;flex:none}
.cab .ident{flex:1}
.cab .ident b{display:block;font-size:15px}
.cab .ident span{font-size:12px;color:var(--sub)}
.cab .ref{text-align:right;font-size:12px;color:var(--sub);white-space:nowrap}
.cab .ref b{color:var(--mab);font-size:15px}
.titulo{text-align:center;margin:10px 0 28px}
.titulo h2{margin:0;font-size:24px;color:var(--mab)}
.titulo p{margin:4px 0 0;color:var(--sub)}
h3{color:var(--mab);font-size:17px;margin:30px 0 10px;padding-bottom:4px;border-bottom:1px solid var(--linha);break-after:avoid}
h4{font-size:15px;margin:18px 0 6px}
p,li{margin:6px 0}
ul,ol{padding-left:22px}
.nota{background:#fbf1f1;border-left:4px solid var(--mab);padding:10px 14px;border-radius:4px;font-size:14px;margin:12px 0}

/* campos a preencher */
.f{display:inline-block;min-width:160px;border-bottom:1px dashed #b5b5b5;background:var(--campo);padding:0 4px;outline:none}
.f:empty::before{content:attr(data-ph);color:#a0a0a0;font-style:italic}
.f:focus{background:#fff2c7}
.fb{display:block;min-height:48px;border:1px dashed #b5b5b5;background:var(--campo);padding:6px 8px;border-radius:4px;outline:none}
.fb:empty::before{content:attr(data-ph);color:#a0a0a0;font-style:italic}

.grelha{display:grid;grid-template-columns:220px 1fr;gap:6px 14px;margin:8px 0}
.grelha .l{color:var(--sub);font-size:14px}

/* tabelas */
.tab{width:100%;overflow-x:auto}
table{width:100%;border-collapse:collapse;font-size:13.5px;margin:8px 0}
th,td{border:1px solid var(--linha);padding:6px 8px;text-align:left;vertical-align:top}
th{background:#f7eded;color:var(--mab-esc);font-weight:600}
td[contenteditable]{background:var(--campo);outline:none;min-width:60px}
td.c{text-align:center}
.add{margin:2px 0 6px;font-size:13px;padding:6px 10px}
input[type=checkbox]{width:17px;height:17px;accent-color:var(--mab);vertical-align:middle}

/* aviso */
.aviso{max-width:420px;margin:16px auto;border:4px solid #f2c200;background:#ffd400;color:#111;padding:20px;text-align:center;border-radius:8px;break-inside:avoid}
.aviso svg{width:84px;height:84px}
.aviso .t1{font-size:20px;font-weight:800;margin:8px 0}
.aviso .t2{font-size:14px;text-align:left;background:#fff;border-radius:4px;padding:8px 10px;margin-top:10px}

.ass{display:grid;grid-template-columns:1fr 1fr;gap:28px;margin-top:26px}
.ass div{border-top:1px solid #333;padding-top:6px;text-align:center;font-size:13px;margin-top:52px}

.rod{margin-top:34px;padding-top:8px;border-top:1px solid var(--linha);font-size:11px;color:var(--sub);display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap}

/* ---------- responsivo ---------- */
@media (max-width:760px){
  .doc{margin:0;border-radius:0;padding:22px 16px}
  .grelha{grid-template-columns:1fr;gap:2px}
  .grelha .l{margin-top:8px}
  .cab{flex-wrap:wrap}
  .cab .ref{text-align:left;width:100%}
  .ass{grid-template-columns:1fr}
  .topo h1{font-size:14px}
  .btn{padding:8px 10px;font-size:13px}
  .f{min-width:120px}
}

/* ---------- impressão ---------- */
@page{size:A4;margin:16mm 14mm 18mm}
@media print{
  body{background:#fff;font-size:11.5pt}
  .topo,.add,.nao-imprimir{display:none!important}
  .doc{box-shadow:none;margin:0;padding:0;max-width:none}
  .f,.fb,td[contenteditable]{background:transparent!important;border-color:#999}
  .f:empty::before,.fb:empty::before{content:""}
  .f:empty{min-width:200px}
  h3{break-after:avoid}
  table,.aviso,.ass{break-inside:avoid}
  .quebra{break-before:page}
  .rod{position:fixed;bottom:-12mm;left:0;right:0;border:0}
}
</style>
</head>
<body>

<div class="topo">
  <a class="logo" href="index.php" title="Início">MAB</a>
  <a class="voltar" href="javascript:history.back()" title="Voltar" aria-label="Voltar">
    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 18l-6-6 6-6"/></svg>
  </a>
  <h1>MAN-002 · Manual do Sistema CCTV</h1>
  <button class="btn btn-s" onclick="limpar()">Limpar</button>
  <button class="btn btn-p" onclick="window.print()">Imprimir / PDF</button>
</div>

<div class="doc">

  <!-- Cabeçalho do documento -->
  <div class="cab">
    <span class="logo">MAB</span>
    <div class="ident">
      <b>m.Abreu - Instalações Técnicas, Unipessoal, Lda.</b>
      <span>Rua Damião de Góis, 205, 4700-028 Braga · NIPC 514414120</span>
    </div>
    <div class="ref"><b>MAN-002</b><br>Rev. 00 · 06/10/2026<br>Obra n.º <span class="f" style="min-width:60px" contenteditable data-ph="—"><?= $num ?></span></div>
  </div>

  <div class="titulo">
    <h2>Manual do Sistema de Videovigilância (CCTV)</h2>
    <p>Descrição, utilização, conformidade legal e manutenção</p>
  </div>

  <!-- 1 -->
  <h3>1. Objetivo e âmbito</h3>
  <p>Este manual acompanha o sistema de videovigilância instalado no local identificado no ponto 3. Descreve o sistema entregue, as regras legais a que a sua utilização está sujeita, as instruções básicas de funcionamento, os procedimentos de acesso às imagens e o plano de manutenção.</p>
  <p>O manual deve ficar guardado junto do equipamento de gravação ou na posse do responsável pelo tratamento dos dados, e ser atualizado sempre que o sistema seja alterado (novas câmaras, mudança de posição, novos utilizadores).</p>

  <!-- 2 -->
  <h3>2. Enquadramento legal</h3>
  <ul>
    <li><b>Lei n.º 34/2013, de 16 de maio</b> (regime do exercício da atividade de segurança privada), na redação da Lei n.º 46/2019, de 8 de julho — em especial o <b>artigo 31.º</b> (sistemas de videovigilância).</li>
    <li><b>Portaria n.º 273/2013, de 20 de agosto</b> — requisitos das instalações e dos sistemas de segurança, incluindo os requisitos mínimos dos sistemas de videovigilância (Anexo I).</li>
    <li><b>Regulamento (UE) 2016/679</b> (RGPD) e <b>Lei n.º 58/2019, de 8 de agosto</b> — em especial o <b>artigo 19.º</b> (videovigilância).</li>
    <li><b>Código do Trabalho</b>, artigos 20.º e 21.º — meios de vigilância a distância no local de trabalho (quando existam trabalhadores).</li>
  </ul>
  <div class="nota">Desde a aplicação do RGPD deixou de existir autorização prévia da CNPD para videovigilância. A responsabilidade pelo cumprimento é do <b>responsável pelo tratamento</b> (o cliente/entidade que explora o sistema), que deve documentar o tratamento e, quando aplicável, realizar uma avaliação de impacto sobre a proteção de dados.</div>

  <!-- 3 -->
  <h3>3. Identificação</h3>
  <h4>3.1 Local e responsável pelo tratamento</h4>
  <div class="grelha">
    <div class="l">Entidade / cliente</div><div><span class="f" contenteditable data-ph="Nome ou denominação social"></span></div>
    <div class="l">NIF / NIPC</div><div><span class="f" contenteditable data-ph="000 000 000"></span></div>
    <div class="l">Morada do local vigiado</div><div><span class="f" contenteditable data-ph="Morada completa"></span></div>
    <div class="l">Tipo de local</div><div><span class="f" contenteditable data-ph="Ex.: estabelecimento comercial, condomínio, armazém"></span></div>
    <div class="l">Responsável pelo sistema</div><div><span class="f" contenteditable data-ph="Nome e função"></span></div>
    <div class="l">Contacto</div><div><span class="f" contenteditable data-ph="Telefone / email"></span></div>
    <div class="l">Encarregado de proteção de dados</div><div><span class="f" contenteditable data-ph="Nome e contacto (se existir)"></span></div>
    <div class="l">Finalidade</div><div><span class="f" contenteditable data-ph="Proteção de pessoas e bens"></span></div>
  </div>
  <h4>3.2 Instalador</h4>
  <div class="grelha">
    <div class="l">Empresa</div><div>m.Abreu - Instalações Técnicas, Unipessoal, Lda. · NIPC 514414120</div>
    <div class="l">Técnico responsável</div><div><span class="f" contenteditable data-ph="Nome do técnico"></span></div>
    <div class="l">Data de conclusão</div><div><span class="f" contenteditable data-ph="dd/mm/aaaa"></span></div>
  </div>
  <h4>3.3 Entidade de segurança privada (se aplicável)</h4>
  <div class="grelha">
    <div class="l">Empresa / alvará ou licença</div><div><span class="f" contenteditable data-ph="Nome e n.º de alvará / licença de autoproteção — ou «Não aplicável»"></span></div>
    <div class="l">Ligação a central de receção de alarmes</div><div><span class="f" contenteditable data-ph="Sim / Não — identificar"></span></div>
  </div>

  <!-- 4 -->
  <h3>4. Descrição do sistema</h3>
  <div class="grelha">
    <div class="l">Gravador (NVR/DVR)</div><div><span class="f" contenteditable data-ph="Marca, modelo, n.º de série"></span></div>
    <div class="l">Localização do gravador</div><div><span class="f" contenteditable data-ph="Ex.: bastidor, compartimento fechado à chave"></span></div>
    <div class="l">Armazenamento</div><div><span class="f" contenteditable data-ph="N.º e capacidade dos discos"></span></div>
    <div class="l">Autonomia estimada de gravação</div><div><span class="f" contenteditable data-ph="Dias (mín. 30 dias)"></span></div>
    <div class="l">Modo de gravação</div><div><span class="f" contenteditable data-ph="Contínuo / deteção de movimento · fps · codec"></span></div>
    <div class="l">Alimentação de socorro (UPS)</div><div><span class="f" contenteditable data-ph="Modelo e autonomia — ou «Não existe»"></span></div>
    <div class="l">Rede / switch PoE</div><div><span class="f" contenteditable data-ph="Marca, modelo, n.º de portas"></span></div>
    <div class="l">Acesso remoto</div><div><span class="f" contenteditable data-ph="Aplicação utilizada (ex.: Hik-Connect / iVMS-4200) — ou «Sem acesso remoto»"></span></div>
    <div class="l">Sincronização horária</div><div><span class="f" contenteditable data-ph="NTP — servidor"></span></div>
    <div class="l">Monitor de visualização</div><div><span class="f" contenteditable data-ph="Local — deve estar fora da vista do público"></span></div>
  </div>

  <h4>4.1 Mapa de câmaras</h4>
  <div class="tab">
  <table id="tCam">
    <thead><tr><th>ID</th><th>Localização</th><th>Tipo / modelo</th><th>Resolução</th><th>Zona coberta</th><th>Conforme*</th></tr></thead>
    <tbody>
      <tr><td contenteditable>C01</td><td contenteditable></td><td contenteditable></td><td contenteditable></td><td contenteditable></td><td class="c"><input type="checkbox"></td></tr>
      <tr><td contenteditable>C02</td><td contenteditable></td><td contenteditable></td><td contenteditable></td><td contenteditable></td><td class="c"><input type="checkbox"></td></tr>
      <tr><td contenteditable>C03</td><td contenteditable></td><td contenteditable></td><td contenteditable></td><td contenteditable></td><td class="c"><input type="checkbox"></td></tr>
      <tr><td contenteditable>C04</td><td contenteditable></td><td contenteditable></td><td contenteditable></td><td contenteditable></td><td class="c"><input type="checkbox"></td></tr>
    </tbody>
  </table>
  </div>
  <button class="btn btn-s add" onclick="addLinha('tCam',true)">+ Câmara</button>
  <p style="font-size:13px;color:var(--sub)">* Verificado que o campo de visão não abrange zonas proibidas (ponto 5.1) e que a via pública só é captada na medida estritamente necessária para cobrir o acesso.</p>

  <!-- 5 -->
  <h3 class="quebra">5. Requisitos legais de instalação e utilização</h3>
  <p>Verificação efetuada na entrega do sistema. Assinalar cada ponto cumprido.</p>
  <div class="tab">
  <table>
    <thead><tr><th style="width:42px">✓</th><th>Requisito</th><th style="width:150px">Base legal</th></tr></thead>
    <tbody>
      <tr><td class="c"><input type="checkbox"></td><td>As câmaras <b>não incidem</b> sobre instalações sanitárias, vestiários, provadores, zonas de repouso ou de refeição de trabalhadores.</td><td>Lei 58/2019, art. 19.º</td></tr>
      <tr><td class="c"><input type="checkbox"></td><td>As câmaras <b>não captam</b> via pública nem propriedades vizinhas, salvo o estritamente necessário para cobrir o acesso ao local (usar máscaras de privacidade quando necessário).</td><td>Lei 58/2019, art. 19.º</td></tr>
      <tr><td class="c"><input type="checkbox"></td><td>As câmaras <b>não captam</b> teclados de terminais de pagamento ou multibanco, nem a introdução de códigos.</td><td>Lei 58/2019, art. 19.º</td></tr>
      <tr><td class="c"><input type="checkbox"></td><td><b>Sem captação de som</b> (salvo durante o período de encerramento ou mediante autorização prévia da CNPD).</td><td>Lei 34/2013, art. 31.º</td></tr>
      <tr><td class="c"><input type="checkbox"></td><td>Imagens conservadas em <b>registo codificado durante 30 dias</b> e destruídas no prazo máximo de <b>48 horas</b> após esse período (sobreescrita automática configurada).</td><td>Lei 34/2013, art. 31.º</td></tr>
      <tr><td class="c"><input type="checkbox"></td><td><b>Avisos afixados</b> em local bem visível em todos os acessos à zona vigiada (ver ponto 6).</td><td>Lei 34/2013, art. 31.º</td></tr>
      <tr><td class="c"><input type="checkbox"></td><td>Câmaras com qualidade de imagem que permita a <b>identificação de pessoas</b> nas zonas de acesso.</td><td>Portaria 273/2013, Anexo I</td></tr>
      <tr><td class="c"><input type="checkbox"></td><td>Data e hora <b>sincronizadas</b> com a hora legal portuguesa e gravadas na imagem.</td><td>Portaria 273/2013, Anexo I</td></tr>
      <tr><td class="c"><input type="checkbox"></td><td>Acesso protegido por <b>utilizador e palavra-passe individuais</b>; palavra-passe de fábrica alterada; sistema regista os acessos com identificação de quem acede.</td><td>Lei 34/2013, art. 31.º; RGPD, art. 32.º</td></tr>
      <tr><td class="c"><input type="checkbox"></td><td>Gravador em local <b>de acesso restrito</b>; monitor fora da vista do público.</td><td>RGPD, art. 32.º</td></tr>
      <tr><td class="c"><input type="checkbox"></td><td>Firmware do gravador e das câmaras atualizado; portas desnecessárias fechadas; acesso remoto apenas por canal cifrado.</td><td>RGPD, art. 32.º</td></tr>
      <tr><td class="c"><input type="checkbox"></td><td>Havendo trabalhadores: informados por escrito da existência e finalidade do sistema; imagens <b>não usadas para controlar o desempenho</b>.</td><td>Código do Trabalho, arts. 20.º e 21.º</td></tr>
    </tbody>
  </table>
  </div>
  <div class="nota">Quando o sistema é <b>obrigatório</b> por força da Lei 34/2013 (p. ex. determinados estabelecimentos sujeitos a medidas de segurança obrigatórias), aplicam-se ainda os requisitos adicionais do artigo 31.º e da Portaria 273/2013, nomeadamente a possibilidade de acesso às imagens pelas forças e serviços de segurança e a ligação a um sistema de alarme. Confirmar o regime aplicável antes da entrega.</div>

  <!-- 6 -->
  <h3>6. Aviso de videovigilância</h3>
  <p>Afixar em todos os acessos à zona vigiada, a uma altura e em local que permitam a leitura antes de se entrar no campo de visão das câmaras. O aviso deve conter, no mínimo, a menção de que o local é objeto de videovigilância, a identificação do responsável pelo tratamento (e da entidade de segurança privada autorizada, quando exista) e o contacto para o exercício de direitos.</p>
  <p>Número de avisos afixados: <span class="f" contenteditable data-ph="N.º"></span> · Locais: <span class="f" contenteditable data-ph="Ex.: porta principal, portão garagem"></span></p>

  <div class="aviso">
    <svg viewBox="0 0 100 100" aria-hidden="true"><g fill="#111"><rect x="18" y="34" width="50" height="24" rx="4" transform="rotate(-12 43 46)"/><polygon points="66,36 84,28 84,56 66,50" transform="rotate(-12 43 46)"/><rect x="36" y="58" width="6" height="22"/><rect x="26" y="78" width="26" height="6" rx="2"/><circle cx="28" cy="47" r="5" fill="#ffd400"/></g></svg>
    <div class="t1">PARA SUA PROTEÇÃO, ESTE LOCAL É OBJETO DE VIDEOVIGILÂNCIA</div>
    <div class="t2">
      Responsável pelo tratamento: <span class="f" contenteditable data-ph="Entidade" style="min-width:120px"></span><br>
      Contacto: <span class="f" contenteditable data-ph="Telefone / email" style="min-width:120px"></span><br>
      Entidade de segurança: <span class="f" contenteditable data-ph="Nome e alvará (se aplicável)" style="min-width:120px"></span>
    </div>
  </div>

  <!-- 7 -->
  <h3>7. Utilizadores com acesso ao sistema</h3>
  <p>Lista obrigatoriamente atualizada. Cada pessoa tem utilizador próprio; não partilhar palavras-passe. Remover o acesso de quem deixar de precisar dele.</p>
  <div class="tab">
  <table id="tUsr">
    <thead><tr><th>Nome</th><th>Função</th><th>Utilizador</th><th>Perfil</th><th>Data atribuição</th><th>Data remoção</th></tr></thead>
    <tbody>
      <tr><td contenteditable></td><td contenteditable></td><td contenteditable></td><td contenteditable>Administrador</td><td contenteditable></td><td contenteditable></td></tr>
      <tr><td contenteditable></td><td contenteditable></td><td contenteditable></td><td contenteditable>Visualização</td><td contenteditable></td><td contenteditable></td></tr>
      <tr><td contenteditable></td><td contenteditable></td><td contenteditable></td><td contenteditable></td><td contenteditable></td><td contenteditable></td></tr>
    </tbody>
  </table>
  </div>
  <button class="btn btn-s add" onclick="addLinha('tUsr')">+ Utilizador</button>
  <p style="font-size:13px;color:var(--sub)">As palavras-passe não se escrevem neste manual. São entregues em separado ao responsável pelo sistema.</p>

  <!-- 8 -->
  <h3 class="quebra">8. Instruções de utilização</h3>
  <h4>8.1 Visualização em direto</h4>
  <ol>
    <li>No monitor do gravador ou na aplicação, iniciar sessão com o seu utilizador.</li>
    <li>Escolher a vista (1, 4, 9… câmaras) ou fazer duplo clique numa câmara para a ver em ecrã inteiro.</li>
    <li>Terminar sessão no fim da consulta.</li>
  </ol>
  <h4>8.2 Reprodução de gravações</h4>
  <ol>
    <li>Menu <i>Reprodução</i> → escolher câmara(s), data e hora.</li>
    <li>Usar a linha temporal para avançar; os segmentos a cor indicam gravação contínua ou por evento.</li>
    <li>A consulta de gravações só deve ser feita por motivo justificado (ocorrência, pedido de autoridade, pedido do titular).</li>
  </ol>
  <h4>8.3 Exportação de imagens</h4>
  <p>É proibido copiar gravações, exceto para entrega às autoridades judiciárias ou policiais no âmbito de investigação criminal ou nos casos previstos no ponto 9. Para exportar:</p>
  <ol>
    <li>Na reprodução, selecionar o intervalo de tempo e a opção <i>Exportar / Cópia de segurança</i>.</li>
    <li>Gravar para pen USB, no formato do fabricante (inclui leitor) ou MP4.</li>
    <li>Registar a exportação no registo de ocorrências (ponto 10).</li>
  </ol>
  <h4>8.4 Notas específicas desta instalação</h4>
  <div class="fb" contenteditable data-ph="Particularidades do equipamento, menus próprios da marca, configuração da aplicação móvel, etc."></div>

  <!-- 9 -->
  <h3>9. Procedimentos</h3>
  <h4>9.1 Pedido das autoridades</h4>
  <p>Entregar cópia apenas mediante pedido identificado (número de processo / identificação do agente). Registar data, entidade, câmaras e período exportado. Se necessário, preservar as imagens antes de serem apagadas pelo ciclo de 30 dias.</p>
  <h4>9.2 Pedido de acesso pelo titular dos dados</h4>
  <p>Qualquer pessoa filmada pode pedir acesso às suas imagens. Responder no prazo de um mês, ocultando terceiros identificáveis quando não seja possível separá-los. Registar o pedido e a resposta.</p>
  <h4>9.3 Incidente de segurança</h4>
  <p>Em caso de acesso indevido, perda ou furto do gravador ou dos discos: alterar de imediato as palavras-passe, contactar a m.Abreu e avaliar a notificação à CNPD, que deve ser feita no prazo de <b>72 horas</b> quando houver risco para as pessoas (RGPD, art. 33.º).</p>
  <h4>9.4 Avaria</h4>
  <p>Se uma câmara deixar de ter imagem, o gravador emitir alarme de disco ou a hora estiver errada, contactar a m.Abreu: <span class="f" contenteditable data-ph="Telefone / email de assistência"></span></p>

  <!-- 10 -->
  <h3>10. Manutenção e garantia</h3>
  <h4>10.1 Pelo utilizador (mensal)</h4>
  <ul>
    <li>Confirmar que todas as câmaras têm imagem nítida e que a hora está correta.</li>
    <li>Confirmar que existe gravação dos últimos dias (abrir uma reprodução ao acaso).</li>
    <li>Verificar que os avisos continuam afixados e legíveis.</li>
  </ul>
  <h4>10.2 Pela m.Abreu (preventiva — periodicidade <span class="f" style="min-width:80px" contenteditable data-ph="anual"></span>)</h4>
  <ul>
    <li>Limpeza das óticas e verificação de fixações, enquadramentos e máscaras de privacidade.</li>
    <li>Estado dos discos (S.M.A.R.T.), autonomia real de gravação e eliminação ao fim de 30 dias.</li>
    <li>Atualização de firmware, revisão de utilizadores e registo de acessos.</li>
    <li>Teste da UPS e da alimentação PoE.</li>
  </ul>
  <h4>10.3 Garantia</h4>
  <p>A instalação tem garantia de <b>3 anos</b> a contar da data de conclusão, cobrindo defeitos de material e de execução. Excluem-se danos por vandalismo, descargas atmosféricas, sobretensões, intervenção de terceiros ou utilização indevida. Os equipamentos seguem também a garantia do fabricante.</p>

  <h4>10.4 Registo de manutenção e ocorrências</h4>
  <div class="tab">
  <table id="tReg">
    <thead><tr><th>Data</th><th>Tipo (manutenção / exportação / pedido / avaria)</th><th>Descrição</th><th>Responsável</th></tr></thead>
    <tbody>
      <tr><td contenteditable></td><td contenteditable></td><td contenteditable></td><td contenteditable></td></tr>
      <tr><td contenteditable></td><td contenteditable></td><td contenteditable></td><td contenteditable></td></tr>
      <tr><td contenteditable></td><td contenteditable></td><td contenteditable></td><td contenteditable></td></tr>
      <tr><td contenteditable></td><td contenteditable></td><td contenteditable></td><td contenteditable></td></tr>
    </tbody>
  </table>
  </div>
  <button class="btn btn-s add" onclick="addLinha('tReg')">+ Linha</button>

  <!-- 11 -->
  <h3>11. Termo de entrega</h3>
  <p>Declara-se que o sistema de videovigilância descrito neste manual foi instalado, testado e entregue em funcionamento, que o responsável pelo tratamento recebeu as instruções de utilização, as credenciais de administrador e um exemplar deste manual, e que tomou conhecimento das obrigações legais descritas nos pontos 5, 6 e 9.</p>
  <p>Data: <span class="f" contenteditable data-ph="dd/mm/aaaa"><?= $num ? $hoje : '' ?></span></p>
  <div class="ass">
    <div>Pela m.Abreu - Instalações Técnicas<br><span class="f" style="min-width:140px" contenteditable data-ph="Nome"></span></div>
    <div>O responsável pelo tratamento<br><span class="f" style="min-width:140px" contenteditable data-ph="Nome"></span></div>
  </div>

  <div class="rod">
    <span>m.Abreu - Instalações Técnicas, Unipessoal, Lda.</span>
    <span>MAN-002 · Manual do Sistema CCTV · Rev. 00 · 06/10/2026</span>
  </div>
</div>

<script>
function addLinha(id, comCheck){
  const tb = document.querySelector('#'+id+' tbody');
  const n = tb.rows[0].cells.length;
  const tr = document.createElement('tr');
  for(let i=0;i<n;i++){
    const td = document.createElement('td');
    if(comCheck && i===n-1){ td.className='c'; td.innerHTML='<input type="checkbox">'; }
    else { td.contentEditable = true; }
    if(id==='tCam' && i===0){ td.textContent = 'C' + String(tb.rows.length+1).padStart(2,'0'); }
    tr.appendChild(td);
  }
  tb.appendChild(tr);
}
function limpar(){
  if(!confirm('Limpar todos os campos preenchidos?')) return;
  location.reload();
}
// Evita colar formatação de outros documentos
document.addEventListener('paste', e=>{
  const el = e.target.closest('[contenteditable]');
  if(!el) return;
  e.preventDefault();
  document.execCommand('insertText', false, (e.clipboardData||window.clipboardData).getData('text'));
});
</script>
</body>
</html>
