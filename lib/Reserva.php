<?php
/**
 * ftth_doc :: reserva técnica de cabo.
 *
 * A Reserva é um ponto do tipo RESERVA que mora EM CIMA de um vão, sem cortá-lo: é o rolo
 * de sobra pendurado no cabo, não uma emenda. Por isso não tem diagrama nem fusão, e o
 * cabo continua sendo um vão só.
 *
 * O que ela carrega são metros. O `reserva_m` do vão é a SOMA das reservas que estão nele,
 * e entra no comprimento óptico (geo × folga + reserva), que é o número do cálculo de
 * potência. Toda mudança que mexe em reserva ou no traçado do vão passa por aqui para a
 * soma e a posição nunca discordarem do desenho.
 *
 * Nada aqui abre transação própria: quem chama já está dentro de uma.
 */
require_once __DIR__ . '/Db.php';
require_once __DIR__ . '/Geo.php';
require_once __DIR__ . '/Config.php';
require_once __DIR__ . '/Cabo.php';

final class Reserva
{
    /** Metros aceitos numa reserva. Acima disso é digitação errada, não rolo de cabo. */
    public const MAX_METROS = 2000;

    /** "40", "40,5" ou vazio -> float|null. Negativo ou absurdo vira null. */
    public static function metros($valor): ?float
    {
        $txt = str_replace(',', '.', trim((string) $valor));
        if ($txt === '' || !is_numeric($txt)) {
            return null;
        }
        $m = round((float) $txt, 2);
        return ($m < 0 || $m > self::MAX_METROS) ? null : $m;
    }

    /**
     * Recalcula `reserva_m` e `comprimento_optico` do vão a partir das reservas dele.
     * Devolve os metros de reserva que o vão ficou tendo.
     */
    public static function recalcularVao(int $vaoId, string $usuario): float
    {
        $vao = Db::um('SELECT id, comprimento_geo, fator_folga FROM tab_ftth_cabo_vao WHERE id = ?',
            [$vaoId]);
        if (!$vao) {
            return 0.0;
        }
        $reserva = (float) Db::valor(
            'SELECT COALESCE(SUM(reserva_m), 0) FROM tab_ftth_caixa
              WHERE tipo = "RESERVA" AND vao_id = ? AND excluido_em IS NULL', [$vaoId]);
        $folga = (float) ($vao['fator_folga'] ?: Config::num('fator_folga_cabo', 1.03));

        Db::exec(
            'UPDATE tab_ftth_cabo_vao
                SET reserva_m = ?, comprimento_optico = ?, alterado_por = ?, alterado_em = NOW()
              WHERE id = ?',
            [round($reserva, 2),
             Geo::comprimentoOptico((float) $vao['comprimento_geo'], $folga, $reserva),
             $usuario, $vaoId]
        );
        return round($reserva, 2);
    }

    /**
     * Cola a reserva no traçado do vão dela, no ponto mais próximo de onde está agora.
     * Serve para depois de o traçado mudar: a reserva acompanha o cabo em vez de ficar
     * pendurada no vazio.
     */
    public static function colarNoVao(int $reservaId): void
    {
        $r = Db::um(
            'SELECT c.id, c.lat, c.lng, v.vertices
               FROM tab_ftth_caixa c
               JOIN tab_ftth_cabo_vao v ON v.id = c.vao_id AND v.excluido_em IS NULL
              WHERE c.id = ? AND c.tipo = "RESERVA" AND c.excluido_em IS NULL', [$reservaId]);
        if (!$r) {
            return;
        }
        $vertices = json_decode((string) $r['vertices'], true);
        if (!is_array($vertices) || count($vertices) < 2) {
            return;
        }
        $proj = Geo::projetarNaRota($vertices, (float) $r['lat'], (float) $r['lng']);
        if ($proj !== null && $proj['distancia_m'] > 0.01) {
            Db::exec('UPDATE tab_ftth_caixa SET lat = ?, lng = ? WHERE id = ?',
                [round($proj['lat'], 7), round($proj['lng'], 7), $reservaId]);
        }
    }

    /** Recola todas as reservas destes vãos no traçado atual de cada um. */
    public static function colarReservasDosVaos(array $vaoIds): void
    {
        $vaoIds = array_values(array_unique(array_filter(array_map('intval', $vaoIds))));
        if (!$vaoIds) {
            return;
        }
        $marcas = implode(',', array_fill(0, count($vaoIds), '?'));
        foreach (Db::todos(
            'SELECT id FROM tab_ftth_caixa
              WHERE tipo = "RESERVA" AND excluido_em IS NULL AND vao_id IN (' . $marcas . ')',
            $vaoIds) as $r) {
            self::colarNoVao((int) $r['id']);
        }
    }

    /**
     * Depois de a reserva ser arrastada: se caiu sobre um cabo, passa a ser dele (pode ter
     * mudado de cabo); se caiu longe de tudo, volta para o próprio vão. Recalcula os dois
     * vãos envolvidos quando ela troca de cabo.
     */
    public static function reancorar(int $reservaId, string $usuario): void
    {
        $r = Db::um('SELECT id, regiao_id, lat, lng, vao_id FROM tab_ftth_caixa
                      WHERE id = ? AND tipo = "RESERVA" AND excluido_em IS NULL', [$reservaId]);
        if (!$r) {
            return;
        }
        $antigo = $r['vao_id'] !== null ? (int) $r['vao_id'] : null;

        $sob = Cabo::vaoSobPonto((int) $r['regiao_id'], (float) $r['lat'], (float) $r['lng']);
        if ($sob !== null) {
            $novo = (int) $sob['vao']['id'];
            Db::exec('UPDATE tab_ftth_caixa SET vao_id = ?, lat = ?, lng = ? WHERE id = ?',
                [$novo, round($sob['projecao']['lat'], 7), round($sob['projecao']['lng'], 7),
                 $reservaId]);
            if ($antigo !== $novo) {
                if ($antigo !== null) {
                    self::recalcularVao($antigo, $usuario);
                }
                self::recalcularVao($novo, $usuario);
            }
            return;
        }
        // Longe de qualquer cabo: a reserva não fica solta, volta para o cabo dela.
        self::colarNoVao($reservaId);
    }

    /**
     * Depois de um vão ser quebrado em dois: cada reserva vai para o trecho em que ela
     * está de fato, e os dois trechos são recalculados.
     */
    public static function redistribuir(int $vaoA, int $vaoB, string $usuario): void
    {
        $geo = [];
        foreach ([$vaoA, $vaoB] as $id) {
            $g = json_decode((string) Db::valor('SELECT vertices FROM tab_ftth_cabo_vao WHERE id = ?',
                [$id]), true);
            $geo[$id] = is_array($g) ? $g : [];
        }

        foreach (Db::todos(
            'SELECT id, lat, lng FROM tab_ftth_caixa
              WHERE tipo = "RESERVA" AND excluido_em IS NULL AND vao_id IN (?, ?)',
            [$vaoA, $vaoB]) as $r) {
            $melhor = null;
            foreach ($geo as $id => $g) {
                if (count($g) < 2) {
                    continue;
                }
                $p = Geo::projetarNaRota($g, (float) $r['lat'], (float) $r['lng']);
                if ($p !== null && ($melhor === null || $p['distancia_m'] < $melhor['p']['distancia_m'])) {
                    $melhor = ['vao' => $id, 'p' => $p];
                }
            }
            if ($melhor !== null) {
                Db::exec('UPDATE tab_ftth_caixa SET vao_id = ?, lat = ?, lng = ? WHERE id = ?',
                    [$melhor['vao'], round($melhor['p']['lat'], 7), round($melhor['p']['lng'], 7),
                     (int) $r['id']]);
            }
        }

        self::recalcularVao($vaoA, $usuario);
        self::recalcularVao($vaoB, $usuario);
    }

    /** Quantas reservas e quantos metros há em cada vão pedido: [vao_id => [qtd, metros]]. */
    public static function resumoPorVao(array $vaoIds): array
    {
        $vaoIds = array_values(array_unique(array_filter(array_map('intval', $vaoIds))));
        if (!$vaoIds) {
            return [];
        }
        $marcas = implode(',', array_fill(0, count($vaoIds), '?'));
        $saida = [];
        foreach (Db::todos(
            'SELECT vao_id, COUNT(*) AS qtd, COALESCE(SUM(reserva_m), 0) AS metros
               FROM tab_ftth_caixa
              WHERE tipo = "RESERVA" AND excluido_em IS NULL AND vao_id IN (' . $marcas . ')
              GROUP BY vao_id', $vaoIds) as $l) {
            $saida[(int) $l['vao_id']] = ['qtd' => (int) $l['qtd'], 'metros' => (float) $l['metros']];
        }
        return $saida;
    }
}
