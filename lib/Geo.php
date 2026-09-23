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
