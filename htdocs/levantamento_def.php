<?php
// =====================================================================
// LEVANTAMENTO DE NECESSIDADES (Visita Comercial)
// Definição das áreas e das perguntas de cada área.
// Para juntar uma área nova: acrescentar ao $LEV_AREAS (e ao grupo em $LEV_GRUPOS).
// Tipos de campo: sel (lista), sn (Sim/Não), num (contador), txt (texto curto), multi (várias escolhas)
// 4º elemento opcional de um campo (regras): ['mostrar_se' => [campo, [valores]], 'obrigatorio' => true, 'aviso' => [valor, texto]]
//   mostrar_se  → o campo só aparece (e só é gravado) quando o outro campo tem um desses valores
//   obrigatorio → não deixa gravar a visita com o campo vazio (quando está visível)
//   aviso       → mostra um alerta quando o campo tem esse valor (ou '>N' = quando o número passa de N)
//   padrao      → valor que vem preenchido quando a área é escolhida pela primeira vez
// 'estimativa_cabos' = 'ited' ou 'redes' → mostra a estimativa de cabo / equipamento calculada a partir das quantidades e distâncias
// 'linhas' = tabela de linhas da área (botões nova linha / duplicar / apagar), colunas: [chave => [rótulo, tipo, opções]]
//            guardada em dados['_linhas']
// Os dados ficam na tabela visitas_levantamento (uma linha por área), ligada à visita.
// =====================================================================

$LEV_GRUPOS = [
    'energia'   => ['nome' => 'Energia e Comunicações', 'cor' => '#2980b9', 'icone' => 'fa-bolt',         'areas' => ['eletricidade', 'domotica', 'ited', 'redes']],
    'seguranca' => ['nome' => 'Segurança',               'cor' => '#8e44ad', 'icone' => 'fa-shield-halved', 'areas' => ['intrusao', 'acessos', 'cctv']],
    'incendio'  => ['nome' => 'Incêndio (SCIE)',         'cor' => '#c0392b', 'icone' => 'fa-fire-extinguisher', 'areas' => ['sadi', 'co', 'extintores', 'portas_cf']],
];

$LEV_AREAS = [
    'eletricidade' => ['nome' => 'Eletricidade', 'icone' => 'fa-plug', 'campos' => [
        'tipo_obra'           => ['Tipo de obra', 'sel', ['Obra nova', 'Remodelação', 'Ampliação', 'Reparação']],
        'tipo_instalacao'     => ['Tipo de instalação', 'sel', ['Embutida (roços)', 'À superfície (calha técnica)', 'À superfície (tubo VD / inox)', 'Mista']],
        'alimentacao'         => ['Alimentação', 'sel', ['Monofásico', 'Trifásico']],
        'potencia_atual'      => ['Potência atual (kVA)', 'txt'],
        'potencia_pretendida' => ['Potência pretendida (kVA)', 'txt'],
        'quadro_estado'       => ['Quadro elétrico', 'sel', ['Manter existente', 'Ampliação / novos disjuntores', 'Substituição total', 'Não existe (quadro novo)']],
        'pisos'               => ['Nº de pisos', 'num'],
        'divisoes'            => ['Nº de divisões', 'num'],
        'certificacao'        => ['Certificação', 'sel', ['Não necessária', 'Termo de responsabilidade de execução', 'Termo + inspeção (EIIEL)', 'A definir']],
        'carregador_ve'       => ['Carregador de VE', 'sn'],
        've_potencia'         => ['Potência do carregador', 'sel', ['3,7 kW (mono)', '7,4 kW (mono)', '11 kW (tri)', '22 kW (tri)', 'A definir'], ['mostrar_se' => ['carregador_ve', ['Sim']]]],
        've_distancia'        => ['Distância do VE ao quadro (m)', 'num', [], ['mostrar_se' => ['carregador_ve', ['Sim']]]],
        'fotovoltaico'        => ['Fotovoltaico', 'sn'],
        'fv_potencia'         => ['Potência FV pretendida (kWp)', 'txt', [], ['mostrar_se' => ['fotovoltaico', ['Sim']]]],
        'fv_paineis'          => ['Nº de painéis (estimado)', 'num', [], ['mostrar_se' => ['fotovoltaico', ['Sim']]]],
        'fv_baterias'         => ['Baterias', 'sn', [], ['mostrar_se' => ['fotovoltaico', ['Sim']]]],
        'fv_distancia'        => ['Distância do inversor ao quadro (m)', 'num', [], ['mostrar_se' => ['fotovoltaico', ['Sim']]]],
    ], 'linhas_titulo' => 'Trabalhos pretendidos', 'linhas' => [
        'categoria' => ['Trabalho', 'sel', ['Passagem de cablagens (enfiamento)', 'Tubagem e caixas (roços)', 'Montagem de aparelhagem (tomadas / interruptores)', 'Circuito dedicado (forno, placa, AC, ...)', 'Iluminação / luminárias', 'Fitas LED', 'Iluminação exterior', 'Quadro elétrico - reformulação total', 'Quadro elétrico - ampliação', 'Rede de terras (elétrodo)', 'Ligações equipotenciais (WC)', 'Aumento de potência / ramal', 'Reparação / avaria', 'Outro']],
        'trabalho'  => ['Detalhe', 'txt'],
        'local'     => ['Local / Divisão', 'txt'],
        'qtd'       => ['Qtd.', 'num'],
    ]],
    'domotica' => ['nome' => 'Domótica', 'icone' => 'fa-house-signal', 'campos' => [
        'tipo_obra'   => ['Tipo de obra', 'sel', ['Obra nova', 'Remodelação profunda', 'Casa pronta (sem obras)'], ['aviso' => ['Casa pronta (sem obras)', 'Casa pronta: normalmente propõe-se sem fios, com micromódulos atrás dos interruptores existentes.']]],
        'tecnologia'  => ['Tecnologia', 'sel', ['Cablado (KNX / bus)', 'Sem fios (Zigbee, Z-Wave, Wi-Fi)', 'Misto', 'A definir'], ['aviso' => ['Cablado (KNX / bus)', 'Cablado: exige instalação elétrica nova, centralizada no quadro (obra nova ou remodelação profunda).']]],
        'sistema'     => ['Sistema / gama', 'sel', ['Smart home básica (Shelly, Sonoff, ...)', 'Integrada média / alta (Loxone, Control4, Somfy, ...)', 'Avançado / protocolo aberto (KNX)', 'A definir']],
        'aparelhagem' => ['Aparelhagem / interruptores', 'sel', ['Manter interruptores existentes (módulos ocultos)', 'Botoneiras de domótica de design', 'Ecrãs táteis + botoneiras', 'A definir']],
        'funcoes'     => ['Funções', 'multi', ['Iluminação', 'Estores', 'Climatização', 'Cenas / rotinas (programação)', 'App / Voz', 'Rega', 'Segurança', 'Gestão de energia']],
        'divisoes'    => ['Nº de divisões', 'num'],
    ], 'linhas_titulo' => 'Pontos a controlar', 'linhas' => [
        'funcao' => ['Elemento', 'sel', ['Iluminação on/off', 'Iluminação regulável (dimmer)', 'Fitas LED / RGB', 'Estores / persianas', 'Toldos / portões', 'Climatização / AC', 'Aquecimento (piso radiante / termostatos)', 'Rega (zonas)', 'Tomadas comandadas', 'Sensor de movimento / presença', 'Sensor de janela / porta', 'Contador de energia', 'Botoneira / interruptor', 'Ecrã tátil', 'Outro']],
        'local'  => ['Local / Divisão', 'txt'],
        'qtd'    => ['Qtd.', 'num'],
    ]],
    'ited' => ['nome' => 'ITED', 'icone' => 'fa-tower-broadcast', 'campos' => [
        'edificio'        => ['Edifício', 'sel', ['Moradia unifamiliar', 'Edifício coletivo (habitação)', 'Edifício comercial / escritórios', 'Misto (habitação + comércio)']],
        'obra'            => ['Tipo de obra', 'sel', ['Construção nova', 'Remodelação / alteração', 'Reparação / manutenção']],
        'fogos'           => ['Nº de fogos', 'num', [], ['mostrar_se' => ['edificio', ['Edifício coletivo (habitação)', 'Edifício comercial / escritórios', 'Misto (habitação + comércio)']]]],
        'divisoes'        => ['Nº de divisões por fogo', 'num'],
        'rj45_divisao'    => ['Tomadas RJ45 por divisão', 'num'],
        'coaxial_divisao' => ['Tomadas coaxiais por divisão', 'num'],
        'categoria_cabo'  => ['Categoria do cabo de pares', 'sel', ['Cat6', 'Cat6A', 'Cat7', 'A definir'], ['padrao' => 'Cat6']],
        'fibra'           => ['Fibra ótica', 'sn', [], ['padrao' => 'Sim', 'aviso' => ['Não', 'Em construção nova ou remodelação profunda, a fibra ótica faz parte da instalação ITED obrigatória.']]],
        'ati_qtd'         => ['Nº de ATI', 'num', [], ['padrao' => '1']],
        'ati_tamanho'     => ['Tamanho do ATI', 'sel', ['Pequeno', 'Médio', 'Grande', 'A dimensionar']],
        'ate'             => ['Nº de ATE (armário do edifício)', 'num', [], ['mostrar_se' => ['edificio', ['Edifício coletivo (habitação)', 'Edifício comercial / escritórios', 'Misto (habitação + comércio)']]]],
        'certificacao'    => ['Certificação, ensaios e termo ITED (ANACOM)', 'sn', [], ['padrao' => 'Sim', 'aviso' => ['Não', 'Sem certificação e termo, a instalação não pode ser ligada à rede pública.']]],
        'dist_media'      => ['Distância média ATI → tomada (m)', 'num'],
        'dist_max'        => ['Tomada mais afastada do ATI (m)', 'num', [], ['aviso' => ['>90', 'Acima de 90 m o cabo de pares não passa nos ensaios de certificação. Rever o sítio do ATI ou prever um ponto intermédio.']]],
        'dist_ate_ati'    => ['Distância ATE → ATI (m)', 'num', [], ['mostrar_se' => ['edificio', ['Edifício coletivo (habitação)', 'Edifício comercial / escritórios', 'Misto (habitação + comércio)']]]],
        'dist_entrada'    => ['Entrada operador → ATE/ATI (m)', 'num'],
    ],
    'estimativa_cabos' => 'ited'],
    'redes' => ['nome' => 'Redes Estruturadas', 'icone' => 'fa-network-wired', 'campos' => [
        'pisos'          => ['Nº de pisos', 'num'],
        'pontos'         => ['Nº de pontos de rede (RJ45)', 'num'],
        'categoria'      => ['Categoria do cabo', 'sel', ['Cat6', 'Cat6A', 'Cat7', 'Cat8', 'A definir'], ['padrao' => 'Cat6']],
        'dist_media'     => ['Distância média ao ponto (m)', 'num'],
        'dist_max'       => ['Ponto mais afastado do bastidor (m)', 'num', [], ['aviso' => ['>90', 'Acima de 90 m o cabo de rede não passa na certificação. É preciso um bastidor secundário ligado em fibra.']]],
        'bastidor'       => ['Bastidor', 'sn'],
        'bastidor_tipo'  => ['Tipo / tamanho do bastidor', 'sel', ['Parede 6U', 'Parede 9U', 'Parede 12U', 'Parede 15U', 'Parede 18U', 'Chão 24U', 'Chão 32U', 'Chão 42U', 'A dimensionar'], ['mostrar_se' => ['bastidor', ['Sim']]]],
        'bastidores_qtd' => ['Nº de bastidores', 'num', [], ['mostrar_se' => ['bastidor', ['Sim']], 'padrao' => '1']],
        'fibra_backbone' => ['Bastidores ligados em fibra', 'sn', [], ['mostrar_se' => ['bastidor', ['Sim']]]],
        'backbone_m'     => ['Fibra entre bastidores (m)', 'num', [], ['mostrar_se' => ['fibra_backbone', ['Sim']]]],
        'aps_wifi'       => ['Nº de pontos de acesso Wi-Fi', 'num'],
        'switch_poe'     => ['Switch PoE', 'sn', [], ['aviso' => ['Não', 'Os pontos de acesso e as câmaras IP são alimentados por PoE. Sem switch PoE é preciso um injetor / fonte para cada um.']]],
        'switch_portas'  => ['Switch - nº de portas', 'sel', ['8 portas', '16 portas', '24 portas', '48 portas', 'Vários switches', 'A dimensionar']],
        'certificacao'   => ['Certificação (relatório Fluke)', 'sn', [], ['padrao' => 'Sim', 'aviso' => ['Não', 'Sem certificação não há relatório ponto a ponto para dar a garantia da instalação.']]],
    ], 'linhas_titulo' => 'Pontos de acesso Wi-Fi (um por linha)', 'linhas' => [
        'local'     => ['Localização', 'txt'],
        'tipo'      => ['Montagem', 'sel', ['Interior - teto', 'Interior - parede', 'Exterior', 'Outro']],
        'distancia' => ['Dist. (m)', 'num'],
    ],
    'estimativa_cabos' => 'redes'],
    'intrusao' => ['nome' => 'Intrusão', 'icone' => 'fa-bell', 'campos' => [
        'tecnologia'     => ['Tecnologia', 'sel', ['Cablado', 'Via rádio (sem fios)', 'Misto', 'A definir']],
        'pre_instalacao' => ['Pré-instalação / tubagem existente', 'sn', [], ['mostrar_se' => ['tecnologia', ['Cablado', 'Misto']], 'obrigatorio' => true]],
        'zonas'          => ['Nº de zonas', 'num'],
        'comunicacao'    => ['Meio de comunicação', 'multi', ['Internet por cabo', 'Wi-Fi', 'GSM / GPRS (cartão SIM)', '4G', 'Linha fixa']],
        'ligacao'        => ['Ligação / comando', 'multi', ['App', 'Central recetora', 'Teclado', 'Comando']],
        'sirene_int'     => ['Sirene interior dedicada', 'sn'],
        'sirene_ext'     => ['Sirene exterior', 'sn'],
        'animais'        => ['Animais em casa', 'sn', [], ['aviso' => ['Sim', 'Lembre-se de escolher detetores com imunidade a animais (Pet Immune) na tabela de equipamentos.']]],
    ], 'linhas_titulo' => 'Detetores e equipamentos', 'linhas' => [
        'tipo'  => ['Tipo', 'sel', ['Contacto magnético (porta / janela)', 'Detetor de movimento interior (PIR)', 'Detetor de movimento interior Pet Immune', 'Detetor de dupla tecnologia', 'Detetor exterior / barreira perimetral', 'Detetor de quebra de vidro', 'Detetor de fumo', 'Detetor de incêndio (térmico)', 'Detetor de inundação', 'Botão de pânico', 'Sirene interior adicional', 'Teclado adicional', 'Comando', 'Módulo de expansão de zonas', 'Outro']],
        'local' => ['Localização', 'txt'],
        'qtd'   => ['Qtd.', 'num'],
    ]],
    'acessos' => ['nome' => 'Controlo de Acessos', 'icone' => 'fa-door-closed', 'campos' => [
        'credenciais'  => ['Credenciais', 'multi', ['Cartão', 'PIN', 'Digital', 'Facial', 'Telemóvel']],
        'utilizadores' => ['Nº de utilizadores', 'num'],
        'gestao'       => ['Gestão do sistema', 'sel', ['Software local (PC)', 'Cloud (Web / App)', 'Autónomo (stand-alone)', 'A definir']],
        'cablagem'     => ['Cablagem / infraestrutura', 'sel', ['Calhas / tubagem existentes', 'Incluir roços e tubagem', 'Calha à vista', 'A verificar']],
        'backup'       => ['Fonte com bateria de backup (UPS)', 'sn'],
    ], 'linhas_titulo' => 'Portas', 'linhas' => [
        'local'      => ['Porta / Localização', 'txt'],
        'qtd'        => ['Nº portas', 'num'],
        'leitores'   => ['Leitores por porta', 'num'],
        'fechadura'  => ['Fechadura', 'sel', ['Eletroíman', 'Trinco elétrico', 'Fechadura motorizada', 'Torniquete / barreira', 'Portão', 'A definir']],
        'abertura'   => ['Abertura', 'sel', ['Interior', 'Exterior', 'Interior e exterior']],
        'instalacao' => ['Leitor instalado', 'sel', ['Interior', 'Exterior (IP65 / IK10)']],
        'saida'      => ['Saída', 'sel', ['Botão de pressão', 'Sensor sem toque', 'Barra antipânico', 'Leitor de saída', 'Livre / sem controlo']],
        'tomada'     => ['Tomada perto', 'sel', ['Sim', 'Não']],
        'distancia'  => ['Cabo (m)', 'num'],
    ]],
    'cctv' => ['nome' => 'CCTV', 'icone' => 'fa-video', 'campos' => [
        'dias_gravacao'  => ['Dias de gravação', 'num'],
        'acesso_remoto'  => ['Acesso remoto', 'sn'],
        'sinaletica'     => ['Sinalética de videovigilância', 'sn'],
    ], 'linhas_titulo' => 'Câmaras', 'linhas' => [
        'tipo'  => ['Tipo de câmara', 'sel', ['Bullet', 'Dome', 'Turret / Eyeball', 'PTZ', 'Fisheye / 360º', 'Campainha / Vídeo-porteiro', 'Outra']],
        'local' => ['Localização', 'txt'],
        'ie'    => ['Int. / Ext.', 'sel', ['Interior', 'Exterior']],
        'qtd'   => ['Qtd.', 'num'],
    ]],
    'sadi' => ['nome' => 'SADI', 'icone' => 'fa-fire', 'campos' => [
        'utilizacao_tipo' => ['Utilização-tipo', 'sel', ['I - Habitacionais', 'II - Estacionamentos', 'III - Administrativos', 'IV - Escolares', 'V - Hospitalares e lares', 'VI - Espetáculos e reuniões públicas', 'VII - Hoteleiros e restauração', 'VIII - Comerciais e gares', 'IX - Desportivos e de lazer', 'X - Museus e galerias', 'XI - Bibliotecas e arquivos', 'XII - Industriais, oficinas e armazéns']],
        'categoria_risco' => ['Categoria de risco', 'sel', ['1ª', '2ª', '3ª', '4ª', 'A definir']],
        'area'            => ['Área (m²)', 'txt'],
        'tipo_sistema'    => ['Sistema', 'sel', ['Convencional', 'Endereçável', 'A definir']],
        'existente'       => ['Sistema existente', 'sel', ['Não existe', 'Existe com manutenção', 'Existe sem manutenção']],
        'pre_instalacao'  => ['Pré-instalação / tubagem existente', 'sn'],
        'cablagem'        => ['Passagem do cabo de incêndio', 'sel', ['Embutido em tubagem existente', 'Embutido (abrir roços)', 'Calha técnica / à vista', 'Misto', 'A verificar']],
        'cabo_metros'     => ['Cabo CR1-C1 estimado (m)', 'num'],
        'integracoes'     => ['Integrações necessárias', 'multi', ['Retenção de portas corta-fogo', 'Desenfumagem (clarabóias / exutores)', 'Corte de energia', 'Corte de gás', 'Paragem de AVAC / ventilação', 'Elevadores (envio ao piso)', 'Desbloqueio do controlo de acessos', 'Comunicador / central recetora']],
    ], 'linhas_titulo' => 'Equipamentos', 'linhas' => [
        'tipo'  => ['Tipo de equipamento', 'sel', ['Central de deteção', 'Detetor ótico de fumo', 'Detetor térmico', 'Detetor termovelocimétrico', 'Detetor multicritério', 'Detetor de feixe linear', 'Botoneira', 'Sirene interior', 'Sirene exterior com flash', 'Sinalizador ótico-acústico', 'Módulo de entradas / saídas', 'Retentor de porta', 'Repetidor / sinóptico', 'Outro']],
        'local' => ['Localização', 'txt'],
        'qtd'   => ['Qtd.', 'num'],
    ]],
    'co' => ['nome' => 'Deteção de CO', 'icone' => 'fa-smog', 'campos' => [
        'area'              => ['Área (m²)', 'txt'],
        'pisos'             => ['Nº de pisos', 'num'],
        'ventilacao'        => ['Ventilação', 'sel', ['Mecânica / extração forçada', 'Natural (grelhas / aberturas permanentes)', 'Jet fans / impulsores', 'Mista', 'Não existe']],
        'extratores'        => ['Nº de extratores / ventiladores a comandar', 'num', [], ['mostrar_se' => ['ventilacao', ['Mecânica / extração forçada', 'Jet fans / impulsores', 'Mista']]]],
        'grupos_ventilacao' => ['Nº de grupos de ventilação (saídas de relé)', 'num', [], ['mostrar_se' => ['ventilacao', ['Mecânica / extração forçada', 'Jet fans / impulsores', 'Mista']]]],
        'velocidades'       => ['Velocidades', 'sel', ['1 velocidade (extração)', '2 velocidades (extração + reforço / desenfumagem)'], ['mostrar_se' => ['ventilacao', ['Mecânica / extração forçada', 'Jet fans / impulsores', 'Mista']]]],
        'pre_instalacao'    => ['Pré-instalação / tubagem existente', 'sn'],
        'topologia'         => ['Topologia da cablagem', 'sel', ['Estrela', 'Linha / Bus', 'Conforme fabricante / a definir']],
        'cabo_sinal_m'      => ['Cabo de sinal estimado (m)', 'num'],
        'cabo_potencia_m'   => ['Cabo de potência p/ ventiladores (m)', 'num', [], ['mostrar_se' => ['ventilacao', ['Mecânica / extração forçada', 'Jet fans / impulsores', 'Mista']]]],
        'paineis_stop'      => ['Painéis exteriores "STOP CO" (entradas)', 'num'],
    ], 'linhas_titulo' => 'Equipamentos', 'linhas' => [
        'tipo'  => ['Tipo de equipamento', 'sel', ['Central de CO - 1 zona', 'Central de CO - 2 zonas', 'Central de CO - 3 zonas', 'Central de CO - 4 ou mais zonas', 'Detetor / sensor de CO', 'Painel luminoso exterior "STOP CO"', 'Módulo de comando de ventilação (relés)', 'Sinalizador ótico-acústico interior', 'Ventilador / extrator', 'Outro']],
        'local' => ['Localização', 'txt'],
        'qtd'   => ['Qtd.', 'num'],
    ],
    // Sugestão automática (não obriga): 1 detetor por cada X m² da área indicada; alterar aqui se o fabricante indicar outro valor
    'sugestao_detetores' => ['campo_area' => 'area', 'm2_por_detetor' => 200, 'tipo_contem' => 'Detetor']],
    'extintores' => ['nome' => 'Extintores', 'icone' => 'fa-fire-extinguisher', 'campos' => [
        'quantidade' => ['Quantidade existente', 'num'],
        'tipos'      => ['Tipos', 'multi', ['Pó ABC', 'CO2', 'Água', 'Espuma']],
        'manutencao' => ['Manutenção', 'sel', ['Em dia', 'Fora de prazo', 'Desconhecida']],
    ]],
    'portas_cf' => ['nome' => 'Portas Corta-fogo', 'icone' => 'fa-door-open', 'campos' => [
    ], 'linhas_titulo' => 'Portas', 'linhas' => [
        'local'             => ['Localização', 'txt'],
        'classe'            => ['Classe', 'sel', ['EI30', 'EI60', 'EI90', 'Desconhecida']],
        'estado'            => ['Estado', 'sel', ['Bom', 'Precisa de reparação', 'Substituir', 'Nova']],
        'molas'             => ['Molas de fecho', 'sel', ['Sim', 'Não']],
        'barras_antipanico' => ['Barras antipânico', 'sel', ['Sim', 'Não']],
    ]],
];

$LEV_PRIORIDADES = ['Baixa', 'Média', 'Alta', 'Urgente'];

function levCriarTabela($pdo) {
    $pdo->exec("CREATE TABLE IF NOT EXISTS visitas_levantamento (
        id INT AUTO_INCREMENT PRIMARY KEY,
        id_visita INT NOT NULL,
        area VARCHAR(40) NOT NULL,
        ordem INT NOT NULL DEFAULT 0,
        dados TEXT NULL,
        prioridade VARCHAR(20) NULL,
        orcamento_cliente VARCHAR(60) NULL,
        fazer_orcamento TINYINT(1) NOT NULL DEFAULT 1,
        notas TEXT NULL,
        fotos TEXT NULL,
        INDEX (id_visita)
    ) DEFAULT CHARSET=utf8mb4");
}

// Devolve [area => linha] com 'dados' e 'fotos' já descodificados
function levCarregar($pdo, $id_visita) {
    $res = [];
    if (!$id_visita) return $res;
    try {
        $st = $pdo->prepare("SELECT * FROM visitas_levantamento WHERE id_visita = ? ORDER BY ordem ASC, id ASC");
        $st->execute([$id_visita]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $r['dados'] = json_decode((string)$r['dados'], true) ?: [];
            $r['fotos'] = json_decode((string)$r['fotos'], true) ?: [];
            $res[$r['area']] = $r;
        }
    } catch (Exception $e) {}
    return $res;
}

// Valor legível de um campo (para PDF e listagens)
function levValorTexto($campo, $valor) {
    if (is_array($valor)) return implode(', ', $valor);
    return trim((string)$valor);
}

// Limpa as linhas vindas do formulário (descarta linhas vazias)
function levLimparLinhas($colunas, $linhas_post) {
    $res = [];
    foreach ((array)$linhas_post as $l) {
        $linha = [];
        foreach ($colunas as $ck => $cdef) {
            $v = trim((string)($l[$ck] ?? ''));
            if ($cdef[1] === 'sel' && !in_array($v, $cdef[2], true)) $v = '';
            if ($cdef[1] === 'num') $v = ($v === '') ? '' : (string)max(0, (int)$v);
            if ($v !== '') $linha[$ck] = $v;
        }
        // Ignora linhas sem nada escrito além da quantidade (uma distância ou outro número já conta)
        $tem_texto = false;
        foreach ($colunas as $ck => $cdef) { if (($cdef[1] !== 'num' || $ck !== 'qtd') && isset($linha[$ck])) { $tem_texto = true; break; } }
        if ($tem_texto) $res[] = $linha;
    }
    return $res;
}

// HTML de uma célula de linha
function levCelulaHTML($nome, $cdef, $valor) {
    $e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    $h = '<div class="lev-cel lev-cel-' . $cdef[1] . '"><label>' . $e($cdef[0]) . '</label>';
    if ($cdef[1] === 'sel') {
        $h .= '<select data-nome="' . $e($nome) . '"><option value="">-</option>';
        foreach ($cdef[2] as $o) $h .= '<option' . ((string)$valor === $o ? ' selected' : '') . '>' . $e($o) . '</option>';
        $h .= '</select>';
    } elseif ($cdef[1] === 'num') {
        $h .= '<input type="number" min="0" inputmode="numeric" data-nome="' . $e($nome) . '" value="' . $e($valor) . '">';
    } else {
        $h .= '<input type="text" data-nome="' . $e($nome) . '" value="' . $e($valor) . '">';
    }
    return $h . '</div>';
}

// HTML do bloco de linhas de uma área (os 'name' são dados pelo JavaScript: lev[area][linhas][i][col])
function levLinhasHTML($area, $area_def, $linhas) {
    if (empty($area_def['linhas'])) return '';
    $cols = $area_def['linhas'];
    $e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    $linha_html = function ($vals) use ($cols) {
        $h = '<div class="lev-linha">';
        foreach ($cols as $ck => $cdef) $h .= levCelulaHTML($ck, $cdef, $vals[$ck] ?? (($cdef[1] === 'num' && in_array($ck, ['qtd', 'leitores'], true)) ? '1' : ''));
        $h .= '<div class="lev-linha-btns">'
            . '<button type="button" class="lb-add" onclick="levLinhaNova(this)" title="Nova linha"><i class="fa-solid fa-plus"></i></button>'
            . '<button type="button" class="lb-dup" onclick="levLinhaDuplicar(this)" title="Duplicar"><i class="fa-solid fa-copy"></i></button>'
            . '<button type="button" class="lb-del" onclick="levLinhaApagar(this)" title="Apagar"><i class="fa-solid fa-trash-can"></i></button>'
            . '</div></div>';
        return $h;
    };
    $h = '<div class="lev-linhas' . (count($cols) > 5 ? ' larga' : '') . '" data-area="' . $e($area) . '">';
    $h .= '<div class="lev-linhas-tit">' . $e($area_def['linhas_titulo'] ?? 'Linhas') . ' <span class="lev-linhas-cont"></span></div>';
    $h .= '<div class="lev-linhas-lista">';
    foreach ((array)$linhas as $l) $h .= $linha_html($l);
    $h .= '</div>';
    $h .= '<template class="lev-linha-modelo">' . $linha_html([]) . '</template>';
    $h .= '<button type="button" class="lev-linha-primeira" onclick="levLinhaNova(this, true)"><i class="fa-solid fa-plus"></i> Adicionar linha</button>';
    return $h . '</div>';
}

// ITED: estimativa de metros de cabo (pares, coaxial, fibra) a partir das quantidades e distâncias.
// Tomadas = fogos × divisões × tomadas por divisão; cada tomada leva um cabo próprio desde o ATI.
// Em edifícios coletivos soma a coluna montante (1 cabo de cada tipo por fogo, ATE → ATI).
// Soma a ligação da entrada do operador e 10% de folga, arredondado aos 5 m.
function levEstimativaCabos($d) {
    $n = fn($k) => max(0, (float)str_replace(',', '.', (string)($d[$k] ?? '')));
    $coletivo = in_array($d['edificio'] ?? '', ['Edifício coletivo (habitação)', 'Edifício comercial / escritórios', 'Misto (habitação + comércio)'], true);
    $fogos = $coletivo ? max(1, $n('fogos')) : 1;
    $tom_rj = $fogos * $n('divisoes') * $n('rj45_divisao');
    $tom_cx = $fogos * $n('divisoes') * $n('coaxial_divisao');
    $media = $n('dist_media'); $coluna = $coletivo ? $fogos * $n('dist_ate_ati') : 0; $entrada = $n('dist_entrada');
    if ($media <= 0 && $coluna <= 0 && $entrada <= 0) return null;
    $folga = fn($m) => (int)(ceil($m * 1.1 / 5) * 5);
    $r = [
        'tomadas_rj' => (int)$tom_rj, 'tomadas_cx' => (int)$tom_cx,
        'pares'   => $folga($tom_rj * $media + $coluna + $entrada),
        'coaxial' => $folga($tom_cx * $media + $coluna + $entrada),
        'fibra'   => ($d['fibra'] ?? '') === 'Não' ? 0 : $folga($coluna + $entrada),
    ];
    $cat = ($d['categoria_cabo'] ?? '') !== '' && ($d['categoria_cabo'] ?? '') !== 'A definir' ? ' ' . $d['categoria_cabo'] : '';
    $r['texto'] = 'Cabo de pares' . $cat . ': ~' . $r['pares'] . ' m (' . $r['tomadas_rj'] . ' tomadas RJ45) · Coaxial: ~' . $r['coaxial'] . ' m (' . $r['tomadas_cx'] . ' tomadas)'
                . ($r['fibra'] > 0 ? ' · Fibra ótica: ~' . $r['fibra'] . ' m' : '') . ' — inclui 10% de folga';
    return $r;
}

// Redes: estimativa de cabo, portas de switch, painéis, bastidor e fibra (o mesmo cálculo está no JavaScript do visita_form.php)
// Cada ponto e cada AP levam um cabo próprio desde o bastidor. APs sem distância na tabela contam com a distância média.
// Switch com 20% de portas de reserva; painéis de 24 portas; bastidor = painéis × 2 (com passa-cabos) + switches + 4U (router, PDU, tabuleiro, reserva).
function levEstimativaRedes($d) {
    $n = fn($v) => max(0, (float)str_replace(',', '.', (string)$v));
    $linhas = is_array($d['_linhas'] ?? null) ? $d['_linhas'] : [];
    $pontos = (int)$n($d['pontos'] ?? ''); $media = $n($d['dist_media'] ?? '');
    $n_ap = max((int)$n($d['aps_wifi'] ?? ''), count($linhas));
    $total = $pontos + $n_ap;
    if ($total <= 0) return null;
    $soma_ap = 0; $ap_longe = 0;
    foreach ($linhas as $l) {
        $dist = ($l['distancia'] ?? '') !== '' ? $n($l['distancia']) : $media;
        $soma_ap += $dist; if ($dist > 90) $ap_longe++;
    }
    $soma_ap += ($n_ap - count($linhas)) * $media;
    $metros = $pontos * $media + $soma_ap;
    $partes = []; $m = 0; $b = ''; $fibra_m = 0; $sw_tam = 48;
    $cat = in_array($d['categoria'] ?? '', ['', 'A definir'], true) ? '' : ' ' . $d['categoria'];
    if ($metros > 0) { $m = (int)(ceil($metros * 1.1 / 5) * 5); $partes[] = 'Cabo' . $cat . ': ~' . $m . ' m (' . (int)ceil($m / 305) . ' caixa' . (ceil($m / 305) > 1 ? 's' : '') . ' de 305 m)'; }
    $preciso = (int)ceil($total * 1.2); $n_sw = 1; $sw_txt = '';
    foreach ([8, 16, 24, 48] as $t) { if ($preciso <= $t) { $sw_txt = '1 switch de ' . $t . ' portas'; $sw_tam = $t; break; } }
    if ($sw_txt === '') { $n_sw = (int)ceil($preciso / 48); $sw_txt = $n_sw . ' switches de 48 portas'; }
    $partes[] = $total . ' portas (' . $pontos . ' pontos + ' . $n_ap . ' AP): ' . $sw_txt . ($n_ap > 0 ? ', ' . $n_ap . ' com PoE' : '');
    $paineis = (int)ceil($total / 24);
    $partes[] = $paineis . ' painel' . ($paineis > 1 ? 'éis' : '') . ' de 24 portas';
    if (($d['bastidor'] ?? '') !== 'Não') {
        $u = $paineis * 2 + $n_sw + 4; $b = 'mais de 42U';
        foreach ([6, 9, 12, 15, 18, 24, 32, 42] as $t) { if ($u <= $t) { $b = $t . 'U'; break; } }
        $partes[] = 'bastidor sugerido: ' . $b;
    }
    if (($d['fibra_backbone'] ?? '') === 'Sim' && $n($d['backbone_m'] ?? '') > 0) { $fibra_m = (int)(ceil($n($d['backbone_m']) * 1.1 / 5) * 5); $partes[] = 'fibra entre bastidores: ~' . $fibra_m . ' m'; }
    $txt = implode(' · ', $partes) . ' — inclui 10% de folga no cabo';
    if ($ap_longe) $txt .= '. ATENÇÃO: ' . $ap_longe . ' AP a mais de 90 m do bastidor';
    return ['texto' => $txt, 'ap_longe' => $ap_longe, 'metros' => $m, 'pontos' => $pontos, 'n_ap' => $n_ap, 'total' => $total,
            'sw_tam' => $sw_tam, 'n_sw' => $n_sw, 'paineis' => $paineis, 'bastidor' => $b, 'fibra_m' => $fibra_m];
}

// =====================================================================
// LEVANTAMENTO → ORÇAMENTO
// Converte uma área do levantamento em linhas de orçamento (Detalhe Interno): designação, unidade e quantidade.
// Os preços ficam por preencher (lupa / tabela de preços). Usado pelo orcamento_form.php ao importar uma visita.
// =====================================================================
function levParaOrcamento($ak, $lv) {
    $d = $lv['dados'] ?? [];
    $linhas_t = is_array($d['_linhas'] ?? null) ? $d['_linhas'] : [];
    $out = [];
    $num = fn($v) => max(0, (float)str_replace(',', '.', (string)$v));
    $v = fn($k) => trim((string)($d[$k] ?? ''));
    $sim = fn($k) => ($d[$k] ?? '') === 'Sim';
    // Junta textos não vazios
    $j = function ($sep, ...$p) { return implode($sep, array_filter(array_map('trim', $p), fn($x) => $x !== '' && $x !== 'A definir')); };
    $add = function ($desig, $qtd, $un = 'un') use (&$out) {
        $desig = trim($desig);
        if ($desig === '' || $qtd <= 0) return;
        $out[] = ['designacao' => $desig, 'unidade' => $un, 'quantidade' => $qtd];
    };
    $qtd_linha = fn($l) => ($l['qtd'] ?? '') !== '' ? (float)$l['qtd'] : 1;
    $local = fn($l) => !empty($l['local']) ? ' - ' . $l['local'] : '';

    switch ($ak) {
        case 'eletricidade':
            if (in_array($v('quadro_estado'), ['Substituição total', 'Não existe (quadro novo)'], true)) $add('Quadro elétrico novo (' . $j(', ', $v('alimentacao'), $v('quadro_estado')) . ')', 1);
            elseif ($v('quadro_estado') === 'Ampliação / novos disjuntores') $add('Ampliação do quadro elétrico / novos disjuntores', 1, 'vg');
            foreach ($linhas_t as $l) {
                $txt = $j(': ', $l['categoria'] ?? '', $l['trabalho'] ?? '') . $local($l);
                $add($txt, $qtd_linha($l), ($l['qtd'] ?? '') !== '' ? 'un' : 'vg');
            }
            if ($sim('carregador_ve')) {
                $add($j(' ', 'Carregador de veículo elétrico', $v('ve_potencia')), 1);
                $add('Cabo de alimentação do carregador de VE (até ao quadro)', $num($d['ve_distancia'] ?? ''), 'm');
                $add('Proteções do circuito do carregador de VE', 1, 'vg');
            }
            if ($sim('fotovoltaico')) {
                $add('Painel fotovoltaico', $num($d['fv_paineis'] ?? ''));
                $add($j(' ', 'Inversor fotovoltaico', $v('fv_potencia') !== '' ? $v('fv_potencia') . ' kWp' : ''), 1);
                if ($sim('fv_baterias')) $add('Bateria para sistema fotovoltaico', 1);
                $add('Estrutura de fixação dos painéis', 1, 'vg');
                $add('Cabo do inversor ao quadro', $num($d['fv_distancia'] ?? ''), 'm');
            }
            if (!in_array($v('certificacao'), ['', 'Não necessária', 'A definir'], true)) $add('Certificação - ' . $v('certificacao'), 1, 'vg');
            break;

        case 'domotica':
            foreach ($linhas_t as $l) $add(($l['funcao'] ?? 'Ponto de domótica') . $local($l), $qtd_linha($l));
            if ($v('sistema') !== '' && $v('sistema') !== 'A definir') $add('Programação e configuração do sistema - ' . $v('sistema'), 1, 'vg');
            break;

        case 'ited':
            $est = levEstimativaCabos($d);
            $coletivo = in_array($v('edificio'), ['Edifício coletivo (habitação)', 'Edifício comercial / escritórios', 'Misto (habitação + comércio)'], true);
            $fogos = $coletivo ? max(1, $num($d['fogos'] ?? '')) : 1;
            $cat = in_array($v('categoria_cabo'), ['', 'A definir'], true) ? '' : ' ' . $v('categoria_cabo');
            $add('ATE - Armário de Telecomunicações de Edifício', $coletivo ? $num($d['ate'] ?? '') : 0);
            $add($j(' ', 'ATI - Armário de Telecomunicações Individual', $v('ati_tamanho') !== '' && $v('ati_tamanho') !== 'A dimensionar' ? '(' . $v('ati_tamanho') . ')' : ''), $num($d['ati_qtd'] ?? '') * $fogos);
            $add('Tomada RJ45' . $cat, $fogos * $num($d['divisoes'] ?? '') * $num($d['rj45_divisao'] ?? ''));
            $add('Tomada coaxial (TV)', $fogos * $num($d['divisoes'] ?? '') * $num($d['coaxial_divisao'] ?? ''));
            if ($est) {
                $add('Cabo de pares' . $cat, $est['pares'], 'm');
                $add('Cabo coaxial', $est['coaxial'], 'm');
                $add('Cabo de fibra ótica', $est['fibra'], 'm');
            }
            if ($sim('certificacao')) $add('Ensaios, certificação e termo ITED', $fogos, 'un');
            break;

        case 'redes':
            $est = levEstimativaRedes($d);
            $cat = in_array($v('categoria'), ['', 'A definir'], true) ? '' : ' ' . $v('categoria');
            $add('Tomada RJ45' . $cat, $num($d['pontos'] ?? ''));
            if ($est) $add('Cabo de rede' . $cat, $est['metros'], 'm');
            foreach ($linhas_t as $l) $add($j(' ', 'Ponto de acesso Wi-Fi', !empty($l['tipo']) ? '(' . $l['tipo'] . ')' : '') . $local($l), 1);
            $add('Ponto de acesso Wi-Fi', $num($d['aps_wifi'] ?? '') - count($linhas_t));
            if ($est) {
                $poe = $v('switch_poe') === 'Sim' || ($v('switch_poe') === '' && $est['n_ap'] > 0) ? ' PoE' : '';
                if (in_array($v('switch_portas'), ['8 portas', '16 portas', '24 portas', '48 portas'], true)) $add('Switch' . $poe . ' de ' . $v('switch_portas'), 1);
                else $add('Switch' . $poe . ' de ' . $est['sw_tam'] . ' portas', $est['n_sw']);
                $add('Painel de ligações (patch panel) 24 portas' . $cat, $est['paineis']);
                $add('Cabo de ligação (patch cord)' . $cat, $est['total']);
            }
            if ($v('bastidor') === 'Sim') {
                $tam = ($v('bastidor_tipo') !== '' && $v('bastidor_tipo') !== 'A dimensionar') ? $v('bastidor_tipo') : ($est['bastidor'] ?? '');
                $add($j(' ', 'Bastidor', $tam), max(1, $num($d['bastidores_qtd'] ?? '')));
            }
            if ($est && $est['fibra_m'] > 0) { $add('Cabo de fibra ótica entre bastidores', $est['fibra_m'], 'm'); $add('Kit de terminação de fibra (caixa, pigtails e adaptadores)', 2 * max(1, $num($d['bastidores_qtd'] ?? '') - 1)); }
            if ($sim('certificacao') && $est) $add('Certificação da cablagem com relatório (Fluke)', $est['pontos'] + $est['n_ap']);
            break;

        case 'intrusao':
            $add($j(' - ', 'Central de alarme', $v('tecnologia')), 1);
            $com = is_array($d['comunicacao'] ?? null) ? $d['comunicacao'] : [];
            if (in_array('GSM / GPRS (cartão SIM)', $com, true)) $add('Módulo de comunicação GSM / GPRS', 1);
            if (in_array('4G', $com, true)) $add('Módulo de comunicação 4G', 1);
            if (array_intersect(['Internet por cabo', 'Wi-Fi'], $com)) $add('Módulo de comunicação IP / Wi-Fi', 1);
            $lig = is_array($d['ligacao'] ?? null) ? $d['ligacao'] : [];
            if (in_array('Teclado', $lig, true)) $add('Teclado', 1);
            if ($sim('sirene_int')) $add('Sirene interior', 1);
            if ($sim('sirene_ext')) $add('Sirene exterior', 1);
            foreach ($linhas_t as $l) $add(($l['tipo'] ?? 'Equipamento') . $local($l), $qtd_linha($l));
            $add('Bateria para a central', 1);
            break;

        case 'acessos':
            foreach ($linhas_t as $l) {
                $portas = $qtd_linha($l); $loc = $local($l);
                $add('Porta com controlo de acessos' . $loc . (!empty($l['abertura']) ? ' (abertura: ' . mb_strtolower($l['abertura'], 'UTF-8') . ')' : ''), $portas, 'un');
                $add('Leitor de acessos' . (($l['instalacao'] ?? '') === 'Exterior (IP65 / IK10)' ? ' exterior IP65 / IK10' : '') . $loc, $portas * (($l['leitores'] ?? '') !== '' ? (float)$l['leitores'] : 1));
                if (!empty($l['fechadura']) && $l['fechadura'] !== 'A definir') $add($l['fechadura'] . $loc, $portas);
                if (in_array($l['saida'] ?? '', ['Botão de pressão', 'Sensor sem toque', 'Barra antipânico'], true)) $add(($l['saida'] === 'Botão de pressão' ? 'Botão de saída' : ($l['saida'] === 'Sensor sem toque' ? 'Sensor de saída sem toque' : 'Barra antipânico')) . $loc, $portas);
                $add('Cabo para controlo de acessos' . $loc, $portas * $num($l['distancia'] ?? ''), 'm');
            }
            if ($sim('backup')) $add('Fonte de alimentação com bateria de backup', 1);
            $cred = is_array($d['credenciais'] ?? null) ? $d['credenciais'] : [];
            if (in_array('Cartão', $cred, true)) $add('Cartão / porta-chaves de proximidade', $num($d['utilizadores'] ?? ''));
            if (in_array($v('gestao'), ['Software local (PC)', 'Cloud (Web / App)'], true)) $add('Configuração do software de gestão - ' . $v('gestao'), 1, 'vg');
            break;

        case 'cctv':
            $n_cam = 0;
            foreach ($linhas_t as $l) { $q = $qtd_linha($l); $n_cam += $q; $add($j(' ', 'Câmara', $l['tipo'] ?? '', !empty($l['ie']) ? strtolower($l['ie']) : '') . $local($l), $q); }
            if ($n_cam > 0) {
                $add('Gravador NVR' . ($num($d['dias_gravacao'] ?? '') > 0 ? ' (' . (int)$num($d['dias_gravacao']) . ' dias de gravação)' : ''), 1);
                $add('Disco rígido para o gravador', 1);
                $add('Ligação de câmara (cabo de rede e fichas)', $n_cam);
            }
            if ($sim('acesso_remoto')) $add('Configuração de acesso remoto (App)', 1, 'vg');
            if ($sim('sinaletica')) $add('Placa de sinalética de videovigilância', 1);
            break;

        case 'sadi':
            foreach ($linhas_t as $l) $add(($l['tipo'] ?? 'Equipamento') . $local($l), $qtd_linha($l));
            $add('Cabo de incêndio CR1-C1', $num($d['cabo_metros'] ?? ''), 'm');
            if ($v('cablagem') === 'Calha técnica / à vista') $add('Calha técnica / tubo para o cabo de incêndio', $num($d['cabo_metros'] ?? ''), 'm');
            if (is_array($d['integracoes'] ?? null)) foreach ($d['integracoes'] as $i) $add('Integração: ' . $i, 1, 'vg');
            break;

        case 'co':
            $tem_stop = false;
            foreach ($linhas_t as $l) { if (strpos($l['tipo'] ?? '', 'STOP') !== false) $tem_stop = true; $add(($l['tipo'] ?? 'Equipamento') . $local($l), $qtd_linha($l)); }
            if (!$tem_stop) $add('Painel luminoso exterior "STOP CO"', $num($d['paineis_stop'] ?? ''));
            $add('Cabo de sinal (deteção de CO)', $num($d['cabo_sinal_m'] ?? ''), 'm');
            $add('Cabo de potência para ventiladores', $num($d['cabo_potencia_m'] ?? ''), 'm');
            break;

        case 'extintores':
            $tipos = is_array($d['tipos'] ?? null) ? $d['tipos'] : [];
            if ($num($d['quantidade'] ?? '') > 0) $add('Manutenção de extintor' . ($tipos ? ' (' . implode(', ', $tipos) . ')' : ''), $num($d['quantidade']));
            break;

        case 'portas_cf':
            foreach ($linhas_t as $l) {
                $loc = $local($l); $cl = (!empty($l['classe']) && $l['classe'] !== 'Desconhecida') ? ' ' . $l['classe'] : '';
                $est = $l['estado'] ?? '';
                if (in_array($est, ['Substituir', 'Nova'], true)) $add('Porta corta-fogo' . $cl . $loc, 1);
                elseif ($est === 'Precisa de reparação') $add('Reparação de porta corta-fogo' . $cl . $loc, 1, 'vg');
                if (($l['molas'] ?? '') === 'Sim') $add('Mola de fecho' . $loc, 1);
                if (($l['barras_antipanico'] ?? '') === 'Sim') $add('Barra antipânico' . $loc, 1);
            }
            break;

        default:
            foreach ($linhas_t as $l) {
                $txt = implode(' - ', array_filter(array_map(fn($x) => is_scalar($x) ? (string)$x : '', $l), fn($x) => $x !== '' && !is_numeric($x)));
                $add($txt, $qtd_linha($l));
            }
    }
    return $out;
}

// HTML de um campo do formulário
function levCampoHTML($area, $key, $campo, $valor) {
    [$label, $tipo] = $campo;
    $opcoes = $campo[2] ?? [];
    $regras = $campo[3] ?? [];
    // null = área ainda não gravada → usa o valor por defeito (se existir)
    if ($valor === null) $valor = $regras['padrao'] ?? '';
    $nome = "lev[$area][dados][$key]";
    $e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    $attr = ' data-campo="' . $e($key) . '"';
    if (!empty($regras['mostrar_se'])) $attr .= ' data-mostrar-campo="' . $e($regras['mostrar_se'][0]) . '" data-mostrar-valores="' . $e(json_encode($regras['mostrar_se'][1], JSON_UNESCAPED_UNICODE)) . '"';
    if (!empty($regras['obrigatorio'])) $attr .= ' data-obrigatorio="1"';
    if (!empty($regras['aviso'])) $attr .= ' data-aviso-valor="' . $e($regras['aviso'][0]) . '"';
    $h = '<div class="lev-campo lev-' . $tipo . '"' . $attr . '><label>' . $e($label) . (!empty($regras['obrigatorio']) ? ' <span class="lev-obrig">*</span>' : '') . '</label>';
    switch ($tipo) {
        case 'sel':
            $h .= '<select name="' . $e($nome) . '"><option value="">-</option>';
            foreach ($opcoes as $o) $h .= '<option' . ((string)$valor === $o ? ' selected' : '') . '>' . $e($o) . '</option>';
            $h .= '</select>';
            break;
        case 'sn':
            $h .= '<div class="lev-seg">';
            foreach (['Sim', 'Não'] as $o) {
                $h .= '<label><input type="radio" name="' . $e($nome) . '" value="' . $o . '"' . ($valor === $o ? ' checked' : '') . ' onclick="levDesmarcar(this)">' . $o . '</label>';
            }
            $h .= '</div>';
            break;
        case 'num':
            $h .= '<div class="lev-num"><button type="button" onclick="levNum(this,-1)">−</button><input type="number" min="0" inputmode="numeric" name="' . $e($nome) . '" value="' . $e($valor) . '"><button type="button" onclick="levNum(this,1)">+</button></div>';
            break;
        case 'multi':
            $vals = is_array($valor) ? $valor : [];
            $h .= '<div class="lev-chips">';
            foreach ($opcoes as $o) {
                $h .= '<label><input type="checkbox" name="' . $e($nome) . '[]" value="' . $e($o) . '"' . (in_array($o, $vals, true) ? ' checked' : '') . '>' . $e($o) . '</label>';
            }
            $h .= '</div>';
            break;
        default:
            $h .= '<input type="text" name="' . $e($nome) . '" value="' . $e($valor) . '">';
    }
    if (!empty($regras['aviso'])) $h .= '<div class="lev-aviso"><i class="fa-solid fa-triangle-exclamation"></i> ' . $e($regras['aviso'][1]) . '</div>';
    return $h . '</div>';
}

// O campo deve ser gravado? (falso quando depende de outro campo que não tem o valor certo)
function levCampoAtivo($cdef, $dados_post) {
    $regras = $cdef[3] ?? [];
    if (empty($regras['mostrar_se'])) return true;
    [$outro, $valores] = $regras['mostrar_se'];
    return in_array((string)($dados_post[$outro] ?? ''), $valores, true);
}
