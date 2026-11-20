<?php
// ============================================================================
// ferias_calculo.php — Cálculo de férias partilhado
// Usado por: mapa_ferias_pdf.php e utilizador_ficha_pdf.php
// Regras (iguais à ficha do funcionário):
//   - Os períodos são marcados em dias seguidos, mas só contam os dias úteis
//     (segunda a sexta, sem feriados nem pontes das Configurações).
//   - As pontes marcadas pela empresa contam como 1 dia de férias para todos.
//   - "Gozados" = dias úteis de férias até hoje (inclusive).
// ============================================================================

if (!function_exists('feriasConfig')) {

    // Lê feriados (todos os dias de fecho) e pontes (que contam como férias)
    function feriasConfig($pdo) {
        $ler = function ($chave) use ($pdo) {
            try {
                $st = $pdo->prepare("SELECT valor FROM configuracoes WHERE chave = ? LIMIT 1");
                $st->execute([$chave]);
                $v = json_decode($st->fetchColumn() ?: '[]', true);
                return is_array($v) ? $v : [];
            } catch (Exception $e) {
                return [];
            }
        };
        $feriados = $ler('dias_feriados');
        $pontes = array_values(array_filter($ler('dias_ponte_ferias'), fn($d) => preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && (int)date('N', strtotime($d)) <= 5));
        sort($pontes);
        $feriados = array_values(array_unique(array_merge($feriados, $pontes)));
        return ['feriados' => $feriados, 'pontes' => $pontes];
    }

    // Calcula as férias de um colaborador num ano
    // Devolve: dias [Y-m-d => 'ferias'|'ponte'], marcados, gozados, periodos (com fim, dias úteis, estado)
    function feriasCalcular($ferias_json, $ano, $cfg, $hoje = null) {
        $hoje = $hoje ?: date('Y-m-d');
        $res = ['dias' => [], 'marcados' => 0, 'gozados' => 0, 'periodos' => [], 'pontes' => []];
        $periodos = json_decode($ferias_json ?: '[]', true);
        if (!is_array($periodos)) $periodos = [];
        usort($periodos, fn($a, $b) => strcmp((string)($a['inicio'] ?? ''), (string)($b['inicio'] ?? '')));

        foreach ($periodos as $p) {
            if (empty($p['inicio']) || (int)($p['dias'] ?? 0) <= 0) continue;
            try {
                $d = new DateTime($p['inicio']);
            } catch (Exception $e) {
                continue;
            }
            $uteis_ano = 0; $toca_ano = false; $fim = null;
            for ($i = 0; $i < (int)$p['dias']; $i++) {
                $data = $d->format('Y-m-d');
                $fim = $data;
                if ((int)$d->format('Y') === (int)$ano) {
                    $toca_ano = true;
                    $res['dias'][$data] = 'ferias';
                    if ((int)$d->format('N') <= 5 && !in_array($data, $cfg['feriados'])) {
                        $uteis_ano++;
                        $res['marcados']++;
                        if ($data <= $hoje) $res['gozados']++;
                    }
                }
                $d->modify('+1 day');
            }
            if ($toca_ano) {
                $estado = ($fim < $hoje) ? 'Gozado' : (($p['inicio'] > $hoje) ? 'Por gozar' : 'A decorrer');
                $res['periodos'][] = ['inicio' => $p['inicio'], 'fim' => $fim, 'dias' => (int)$p['dias'], 'uteis' => $uteis_ano, 'estado' => $estado];
            }
        }

        foreach ($cfg['pontes'] as $pd) {
            if ((int)substr($pd, 0, 4) !== (int)$ano) continue;
            $res['dias'][$pd] = 'ponte';
            $res['pontes'][] = $pd;
            $res['marcados']++;
            if ($pd <= $hoje) $res['gozados']++;
        }
        return $res;
    }
}
