<?php
/**
 * ftth_doc :: geometria (Haversine, comprimento de rota, ponto a X metros da rota).
 *
 * A5: comprimento optico = geometrico * fator de folga + reserva tecnica.
 * Nada aqui depende de tipos espaciais do MySQL (MariaDB do MK-AUTH e 10.3).
 */
final class Geo
{
    public const RAIO_TERRA_M = 6371000.0;

    /** Distancia em metros entre dois pontos [lat, lng]. */
    public static function distancia(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $r  = M_PI / 180;
        $dLat = ($lat2 - $lat1) * $r;
        $dLng = ($lng2 - $lng1) * $r;
        $a = sin($dLat / 2) ** 2 + cos($lat1 * $r) * cos($lat2 * $r) * sin($dLng / 2) ** 2;
        return 2 * self::RAIO_TERRA_M * asin(min(1.0, sqrt($a)));
    }

    /** Comprimento total de uma rota [[lat,lng], ...] em metros. */
    public static function comprimento(array $vertices): float
    {
        $t = 0.0;
        for ($i = 1, $n = count($vertices); $i < $n; $i++) {
            $t += self::distancia(
                (float) $vertices[$i - 1][0], (float) $vertices[$i - 1][1],
                (float) $vertices[$i][0],     (float) $vertices[$i][1]
            );
        }
        return $t;
    }

    public static function comprimentoOptico(float $geoM, float $fatorFolga, float $reservaM): float
    {
        return round($geoM * $fatorFolga + $reservaM, 2);
    }

    /**
     * Onde este ponto encosta na rota, e a que distancia.
     *
     * E o que permite soltar uma caixa em cima de um cabo ja lancado: o clique nunca cai
     * exatamente sobre a linha, entao projetamos o ponto em cada segmento e ficamos com o
     * mais proximo. A conta e feita num plano local (equirretangular, com o cosseno da
     * latitude corrigindo a longitude) — para dezenas de metros o erro e centimetrico, e
     * evita trigonometria esferica em algo que roda a cada clique.
     *
     * Devolve null quando a rota nao tem dois pontos. Senao:
     *   lat, lng      o ponto exato sobre a rota
     *   distancia_m   quanto o ponto original estava fora
     *   indice        o segmento onde caiu (0 = entre o 1o e o 2o vertice)
     *   antes/depois  os vertices de cada lado, ja com o ponto projetado nas duas pontas
     *
     * @param array $vertices [[lat,lng], ...]
     * @return array{lat:float,lng:float,distancia_m:float,indice:int,antes:array,depois:array}|null
     */
    public static function projetarNaRota(array $vertices, float $lat, float $lng): ?array
    {
        $n = count($vertices);
        if ($n < 2) {
            return null;
        }

        $cos = cos($lat * M_PI / 180);
        // graus -> metros, aproximado e local: suficiente para achar o segmento mais proximo.
        $mx = static function (float $la, float $ln) use ($cos): array {
            return [$ln * $cos * 111320.0, $la * 110540.0];
        };

        [$px, $py] = $mx($lat, $lng);
        $melhor = null;

        for ($i = 1; $i < $n; $i++) {
            $aLat = (float) $vertices[$i - 1][0];
            $aLng = (float) $vertices[$i - 1][1];
            $bLat = (float) $vertices[$i][0];
            $bLng = (float) $vertices[$i][1];

            [$ax, $ay] = $mx($aLat, $aLng);
            [$bx, $by] = $mx($bLat, $bLng);

            $dx = $bx - $ax;
            $dy = $by - $ay;
            $len2 = $dx * $dx + $dy * $dy;

            // t = onde a projecao cai dentro do segmento (0 = ponta A, 1 = ponta B).
            $t = $len2 > 0 ? (($px - $ax) * $dx + ($py - $ay) * $dy) / $len2 : 0.0;
            $t = max(0.0, min(1.0, $t));

            $qLat = $aLat + ($bLat - $aLat) * $t;
            $qLng = $aLng + ($bLng - $aLng) * $t;
            $dist = self::distancia($lat, $lng, $qLat, $qLng);

            if ($melhor === null || $dist < $melhor['distancia_m']) {
                $melhor = ['lat' => $qLat, 'lng' => $qLng, 'distancia_m' => $dist,
                           'indice' => $i - 1, 't' => $t];
            }
        }

        if ($melhor === null) {
            return null;
        }

        $i = $melhor['indice'];
        $ponto = [$melhor['lat'], $melhor['lng']];

        $antes = array_slice($vertices, 0, $i + 1);
        $antes[] = $ponto;
        $depois = array_merge([$ponto], array_slice($vertices, $i + 1));

        // Projecao que caiu exatamente sobre um vertice deixaria um ponto repetido.
        $antes  = self::semPontoRepetido($antes);
        $depois = self::semPontoRepetido($depois);

        return ['lat' => $melhor['lat'], 'lng' => $melhor['lng'],
                'distancia_m' => round($melhor['distancia_m'], 2),
                'indice' => $i, 'antes' => $antes, 'depois' => $depois];
    }

    /** Tira vertices colados (< 1 cm), que so atrapalham o desenho e o calculo. */
    private static function semPontoRepetido(array $vertices): array
    {
        $saida = [];
        foreach ($vertices as $v) {
            $ultimo = end($saida);
            if ($ultimo !== false
                && self::distancia((float) $ultimo[0], (float) $ultimo[1], (float) $v[0], (float) $v[1]) < 0.01) {
                continue;
            }
            $saida[] = [(float) $v[0], (float) $v[1]];
        }
        return $saida;
    }

    /** Valida uma rota: ao menos 2 pontos e coordenadas dentro de faixa. */
    public static function rotaValida(array $vertices): bool
    {
        if (count($vertices) < 2) {
            return false;
        }
        foreach ($vertices as $v) {
            if (!is_array($v) || count($v) < 2) {
                return false;
            }
            $lat = (float) $v[0];
            $lng = (float) $v[1];
            if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
                return false;
            }
        }
        return true;
    }
}
