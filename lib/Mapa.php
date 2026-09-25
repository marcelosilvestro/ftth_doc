<?php
/**
 * ftth_doc :: consultas do mapa.
 *
 * 3b.12: nunca carregar a rede inteira — sempre regiao + area visivel (bbox) + limite.
 * Os itens da quarentena vem junto, marcados, para aparecerem translucidos no mapa.
 */
require_once __DIR__ . '/Db.php';
require_once __DIR__ . '/Geo.php';
require_once __DIR__ . '/Potencia.php';
require_once __DIR__ . '/Caixa.php';
require_once __DIR__ . '/Cabo.php';
require_once __DIR__ . '/Reserva.php';

final class Mapa
{
    public const LIMITE_PADRAO = 3000;

    /**
     * @param array{0:float,1:float,2:float,3:float}|null $bbox [latMin, lngMin, latMax, lngMax]
     */
    public static function elementos(int $regiaoId, ?array $bbox, array $opcoes = []): array
    {
        $limite = (int) ($opcoes['limite'] ?? self::LIMITE_PADRAO);
        $incluirQuarentena = $opcoes['quarentena'] ?? true;

        $filtroCaixa = 'c.regiao_id = ? AND c.excluido_em IS NULL';
        $parCaixa    = [$regiaoId];
        if ($bbox) {
            $filtroCaixa .= ' AND c.lat BETWEEN ? AND ? AND c.lng BETWEEN ? AND ?';
            array_push($parCaixa, $bbox[0], $bbox[2], $bbox[1], $bbox[3]);
        }

        // Ocupação da CTO sai dos splitters de atendimento: portas usadas / saídas do splitter.
        $caixas = Db::todos(
            "SELECT c.id, c.tipo, c.nome, c.cor, c.lat, c.lng, c.status, c.capacidade, c.versao,
                    c.reserva_m, c.vao_id,
                    (SELECT COUNT(*) FROM tab_ftth_splitter s
                      WHERE s.caixa_id = c.id AND s.excluido_em IS NULL) AS splitters,
                    (SELECT COALESCE(SUM(s.saidas),0) FROM tab_ftth_splitter s
                      WHERE s.caixa_id = c.id AND s.excluido_em IS NULL AND s.funcao = 'ATENDIMENTO') AS portas,
                    (SELECT COUNT(*) FROM tab_ftth_porta p
                       JOIN tab_ftth_splitter s2 ON s2.id = p.splitter_id
                      WHERE s2.caixa_id = c.id) AS ocupadas,
                    (SELECT COUNT(*) FROM tab_ftth_ligacao l
                      WHERE l.caixa_id = c.id) AS ligacoes
               FROM tab_ftth_caixa c
              WHERE $filtroCaixa
              ORDER BY c.id
              LIMIT $limite",
            $parCaixa
        );

        // Vãos: entra todo trecho cuja MOLDURA cruza a área visível — não só os que têm uma
        // ponta dentro dela. Filtrar pela ponta fazia o cabo sumir quando se dava zoom no meio
        // de um lance longo: as duas caixas ficavam fora da tela e o trecho inteiro saía do
        // resultado, com o contador acusando "0 vãos" sobre um cabo que estava ali.
        //
        // A moldura é a das duas pontas, com uma folga de 25% da área pedida para acomodar a
        // curva do traçado. Não é a moldura exata do desenho (os vértices são JSON, e lê-los
        // custaria a região inteira a cada arrasto do mapa), mas cobre o cabo que acompanha a
        // rua, que é o caso real.
        $filtroVao = 'v.regiao_id = ? AND v.excluido_em IS NULL';
        $parVao    = [$regiaoId];
        if ($bbox) {
            $folgaLat = (($bbox[2] - $bbox[0]) ?: 0.001) * 0.25;
            $folgaLng = (($bbox[3] - $bbox[1]) ?: 0.001) * 0.25;
            $areaLatMin = $bbox[0] - $folgaLat;
            $areaLatMax = $bbox[2] + $folgaLat;
            $areaLngMin = $bbox[1] - $folgaLng;
            $areaLngMax = $bbox[3] + $folgaLng;

            $filtroVao .= ' AND LEAST(ci.lat, cf.lat)    <= ?
                            AND GREATEST(ci.lat, cf.lat) >= ?
                            AND LEAST(ci.lng, cf.lng)    <= ?
                            AND GREATEST(ci.lng, cf.lng) >= ?';
            array_push($parVao, $areaLatMax, $areaLatMin, $areaLngMax, $areaLngMin);
        }

        $vaos = Db::todos(
            "SELECT v.id, v.cabo_id, v.caixa_ini_id, v.caixa_fim_id, v.vertices,
                    v.comprimento_geo, v.comprimento_optico, v.reserva_m, v.versao,
                    (SELECT COUNT(*) FROM tab_ftth_caixa rs WHERE rs.tipo = 'RESERVA'
                        AND rs.vao_id = v.id AND rs.excluido_em IS NULL) AS reservas,
                    cb.nome AS cabo_nome, cb.cor_rota, cb.fabricante, cb.padrao_cores,
                    cb.cabo_tipo_id, cb.status, cb.origem AS cabo_origem, cb.versao AS cabo_versao,
                    t.rotulo AS cabo_tipo, t.fibras, t.construcao,
                    ci.nome AS caixa_ini, cf.nome AS caixa_fim
               FROM tab_ftth_cabo_vao v
               JOIN tab_ftth_cabo cb ON cb.id = v.cabo_id
               JOIN tab_ftth_cabo_tipo t ON t.id = cb.cabo_tipo_id
               JOIN tab_ftth_caixa ci ON ci.id = v.caixa_ini_id
               JOIN tab_ftth_caixa cf ON cf.id = v.caixa_fim_id
              WHERE $filtroVao
              ORDER BY v.id
              LIMIT $limite",
            $parVao
        );
        foreach ($vaos as &$v) {
            $v['vertices'] = json_decode((string) $v['vertices'], true) ?: [];
        }
        unset($v);

        $quarentena = [];
        if ($incluirQuarentena) {
            $itens = Db::todos(
                'SELECT id, tipo_sugerido, subtipo, nome, cor, geometria, alertas_json
                   FROM tab_ftth_importacao_item
                  WHERE regiao_id = ? AND status = "pendente"
                  ORDER BY id
                  LIMIT ' . $limite,
                [$regiaoId]
            );
            foreach ($itens as $i) {
                $g = json_decode((string) $i['geometria'], true) ?: [];
                if (!$g) {
                    continue;
                }
                // Mesma regra dos vãos: vale a moldura do item, não o primeiro ponto dele.
                // Aqui a geometria já está lida, então a moldura é a de verdade.
                if ($bbox && !self::molduraCruza($g, $bbox)) {
                    continue;
                }
                $quarentena[] = [
                    'id'      => (int) $i['id'],
                    'tipo'    => $i['tipo_sugerido'],
                    'subtipo' => $i['subtipo'],
                    'nome'    => $i['nome'],
                    'cor'     => $i['cor'],
                    'geo'     => $g,
                    'alertas' => json_decode((string) $i['alertas_json'], true) ?: [],
                ];
            }
        }

        return [
            'caixas'     => $caixas,
            'vaos'       => $vaos,
            'quarentena' => $quarentena,
            'limite'     => $limite,
            'truncado'   => count($caixas) >= $limite || count($vaos) >= $limite,
        ];
    }

    /**
     * A moldura desta geometria cruza a área visível?
     *
     * Um traçado pode atravessar a tela inteira sem ter nenhum vértice dentro dela — é o caso
     * de dar zoom no meio de um lance longo. Comparar retângulos resolve isso sem precisar
     * testar segmento por segmento.
     *
     * @param array $geo  [[lat,lng], ...]
     * @param array $bbox [latMin, lngMin, latMax, lngMax]
     */
    private static function molduraCruza(array $geo, array $bbox): bool
    {
        $latMin = $latMax = (float) $geo[0][0];
        $lngMin = $lngMax = (float) $geo[0][1];
        foreach ($geo as $p) {
            $latMin = min($latMin, (float) $p[0]);
            $latMax = max($latMax, (float) $p[0]);
            $lngMin = min($lngMin, (float) $p[1]);
            $lngMax = max($lngMax, (float) $p[1]);
        }
        return $latMin <= $bbox[2] && $latMax >= $bbox[0]
            && $lngMin <= $bbox[3] && $lngMax >= $bbox[1];
    }

    /** Busca universal simplificada da fase 1: caixa, cabo e cliente já vinculado. */
    public static function buscar(int $regiaoId, string $termo, int $limite = 20): array
    {
        $termo = trim($termo);
        if ($termo === '') {
            return [];
        }
        $like = '%' . $termo . '%';
        $saida = [];

        foreach (Db::todos(
            'SELECT id, nome, tipo, lat, lng FROM tab_ftth_caixa
              WHERE regiao_id = ? AND excluido_em IS NULL AND nome LIKE ?
              ORDER BY nome LIMIT ' . $limite, [$regiaoId, $like]) as $c) {
            $saida[] = ['grupo' => 'Caixas', 'rotulo' => $c['nome'], 'detalhe' => $c['tipo'],
                        'tipo' => 'caixa', 'id' => (int) $c['id'],
                        'lat' => (float) $c['lat'], 'lng' => (float) $c['lng']];
        }

        foreach (Db::todos(
            'SELECT p.id, p.login, p.numero, s.nome AS splitter, c.id AS caixa_id, c.nome AS caixa,
                    c.lat, c.lng
               FROM tab_ftth_porta p
               JOIN tab_ftth_splitter s ON s.id = p.splitter_id
               JOIN tab_ftth_caixa c ON c.id = s.caixa_id
              WHERE c.regiao_id = ? AND p.login LIKE ?
              LIMIT ' . $limite, [$regiaoId, $like]) as $p) {
            $saida[] = ['grupo' => 'Clientes', 'rotulo' => $p['login'],
                        'detalhe' => $p['caixa'] . ' · porta ' . $p['numero'],
                        'tipo' => 'caixa', 'id' => (int) $p['caixa_id'],
                        'lat' => (float) $p['lat'], 'lng' => (float) $p['lng']];
        }

        // Coordenada colada direto ("-24.88, -52.21")
        if (preg_match('/^\s*(-?\d+[\.,]\d+)\s*,\s*(-?\d+[\.,]\d+)\s*$/', $termo, $m)) {
            $lat = (float) str_replace(',', '.', $m[1]);
            $lng = (float) str_replace(',', '.', $m[2]);
            if ($lat >= -90 && $lat <= 90 && $lng >= -180 && $lng <= 180) {
                array_unshift($saida, ['grupo' => 'Coordenada', 'rotulo' => "$lat, $lng",
                    'detalhe' => 'ir para o ponto', 'tipo' => 'ponto', 'id' => 0,
                    'lat' => $lat, 'lng' => $lng]);
            }
        }

        return $saida;
    }

    /**
     * Aplica de uma vez os movimentos feitos no modo Mover: caixas e traçados de cabo.
     *
     * Tudo numa transação só porque um mapa meio movido é pior do que um mapa não movido —
     * se um vão falhar, as caixas que já tinham ido não podem ficar. E a ordem importa:
     * as CAIXAS primeiro, porque mover a caixa reescreve a ponta dos vãos que encostam
     * nela; os vãos depois, e o `moverVertices` refaz as pontas com a coordenada nova.
     *
     * @param array $caixas [{id, lat, lng, versao?}, ...]
     * @param array $vaos   [{id, vertices: [[lat,lng],...], versao?}, ...]
     */
    public static function aplicarMovimentos(array $caixas, array $vaos, string $usuario): Resultado
    {
        if (!$caixas && !$vaos) {
            return Resultado::ok(['caixas' => 0, 'vaos' => 0]);
        }

        // Db::transacao só desfaz quando a closure LANÇA: devolver um Resultado com erro
        // daria commit no que já passou, e o lote deixaria de ser atômico. Por isso a
        // recusa vira exceção aqui dentro e volta a ser Resultado do lado de fora.
        $falha = null;

        try {
            return Db::transacao(function () use ($caixas, $vaos, $usuario, &$falha) {
                foreach ($caixas as $c) {
                    $r = Caixa::mover(
                        (int) ($c['id'] ?? 0), (float) ($c['lat'] ?? 0), (float) ($c['lng'] ?? 0),
                        isset($c['versao']) ? (int) $c['versao'] : null, $usuario);
                    if (!$r->ok) {
                        $falha = $r;
                        throw new RuntimeException('movimento recusado');
                    }
                }
                foreach ($vaos as $v) {
                    $r = Cabo::moverVertices(
                        (int) ($v['id'] ?? 0), (array) ($v['vertices'] ?? []),
                        isset($v['versao']) ? (int) $v['versao'] : null, $usuario);
                    if (!$r->ok) {
                        $falha = $r;
                        throw new RuntimeException('movimento recusado');
                    }
                }
                self::ajustarReservas($caixas, $vaos, $usuario);
                return Resultado::ok(['caixas' => count($caixas), 'vaos' => count($vaos)]);
            });
        } catch (Throwable $e) {
            if ($falha !== null) {
                return $falha;
            }
            throw $e;      // erro de verdade continua subindo para o log
        }
    }

    /**
     * Reservas depois do lote do modo Mover, quando o traçado já é o novo.
     *
     * Primeiro a reserva que foi arrastada: cai sobre um cabo e passa a ser dele, ou volta
     * para o seu. Depois as que estão em vão que mudou de forma (traçado mexido ou caixa da
     * ponta movida) são recoladas no traçado. A ordem importa: recolar antes jogaria a
     * reserva arrastada de volta para o cabo antigo.
     */
    private static function ajustarReservas(array $caixas, array $vaos, string $usuario): void
    {
        $idsCaixas = array_values(array_filter(array_map(static function ($c) {
            return (int) ($c['id'] ?? 0);
        }, $caixas)));
        $vaoIds = array_map(static function ($v) { return (int) ($v['id'] ?? 0); }, $vaos);

        if ($idsCaixas) {
            $marcas = implode(',', array_fill(0, count($idsCaixas), '?'));
            foreach (Db::todos('SELECT id FROM tab_ftth_caixa WHERE tipo = "RESERVA" AND id IN ('
                               . $marcas . ')', $idsCaixas) as $r) {
                Reserva::reancorar((int) $r['id'], $usuario);
            }
            foreach (Db::todos('SELECT id FROM tab_ftth_cabo_vao WHERE excluido_em IS NULL
                                  AND (caixa_ini_id IN (' . $marcas . ') OR caixa_fim_id IN (' . $marcas . '))',
                               array_merge($idsCaixas, $idsCaixas)) as $v) {
                $vaoIds[] = (int) $v['id'];
            }
        }
        Reserva::colarReservasDosVaos($vaoIds);
    }

    /**
     * Todos os pontos da região, para a lista do painel — a região inteira, não a área
     * visível: o filtro "sem sinal" precisa dizer quantas faltam mesmo fora da tela.
     *
     * `com_sinal` só é calculado para CTO e CEO (o que o filtro olha); nos demais vem null.
     */
    public static function pontos(int $regiaoId): array
    {
        $pontos = Db::todos(
            "SELECT c.id, c.tipo, c.nome, c.cor, c.lat, c.lng, c.reserva_m,
                    (SELECT COUNT(*) FROM tab_ftth_splitter s
                      WHERE s.caixa_id = c.id AND s.excluido_em IS NULL) AS splitters,
                    (SELECT COALESCE(SUM(s.saidas),0) FROM tab_ftth_splitter s
                      WHERE s.caixa_id = c.id AND s.excluido_em IS NULL AND s.funcao = 'ATENDIMENTO') AS portas,
                    (SELECT COUNT(*) FROM tab_ftth_porta p
                       JOIN tab_ftth_splitter s2 ON s2.id = p.splitter_id
                      WHERE s2.caixa_id = c.id AND s2.excluido_em IS NULL) AS ocupadas
               FROM tab_ftth_caixa c
              WHERE c.regiao_id = ? AND c.excluido_em IS NULL
              ORDER BY c.nome", [$regiaoId]);

        $comSinal = Potencia::caixasComSinal();
        foreach ($pontos as &$p) {
            $p['com_sinal'] = in_array($p['tipo'], ['CTO', 'CTO_AP', 'CEO'], true)
                ? isset($comSinal[(int) $p['id']]) : null;
        }
        unset($p);
        return $pontos;
    }

    /** Ficha resumida de uma caixa (nível 1 da UX). */
    public static function ficha(int $caixaId): ?array
    {
        $c = Db::um(
            'SELECT c.*, r.nome AS regiao
               FROM tab_ftth_caixa c
               JOIN tab_ftth_regiao r ON r.id = c.regiao_id
              WHERE c.id = ? AND c.excluido_em IS NULL', [$caixaId]);
        if (!$c) {
            return null;
        }

        $c['splitters'] = Db::todos(
            'SELECT id, nome, funcao, razao, saidas FROM tab_ftth_splitter
              WHERE caixa_id = ? AND excluido_em IS NULL ORDER BY nome', [$caixaId]);

        $c['clientes'] = Db::todos(
            'SELECT p.numero, p.login, s.nome AS splitter
               FROM tab_ftth_porta p JOIN tab_ftth_splitter s ON s.id = p.splitter_id
              WHERE s.caixa_id = ? ORDER BY s.nome, p.numero', [$caixaId]);

        $c['cabos'] = Db::todos(
            'SELECT v.id, v.comprimento_geo, v.comprimento_optico,
                    t.rotulo AS tipo, cb.nome AS cabo,
                    IF(v.caixa_ini_id = ?, cf.nome, ci.nome) AS sentido
               FROM tab_ftth_cabo_vao v
               JOIN tab_ftth_cabo cb ON cb.id = v.cabo_id
               JOIN tab_ftth_cabo_tipo t ON t.id = cb.cabo_tipo_id
               JOIN tab_ftth_caixa ci ON ci.id = v.caixa_ini_id
               JOIN tab_ftth_caixa cf ON cf.id = v.caixa_fim_id
              WHERE (v.caixa_ini_id = ? OR v.caixa_fim_id = ?) AND v.excluido_em IS NULL',
            [$caixaId, $caixaId, $caixaId]);

        $c['ligacoes'] = (int) Db::valor('SELECT COUNT(*) FROM tab_ftth_ligacao WHERE caixa_id = ?', [$caixaId]);

        // Reserva: o cabo em que ela está e quanto o vão soma de reserva no total.
        $c['vao_reserva'] = null;
        if ($c['tipo'] === 'RESERVA' && $c['vao_id'] !== null) {
            $c['vao_reserva'] = Db::um(
                'SELECT v.id, v.reserva_m, v.comprimento_geo, v.comprimento_optico,
                        cb.nome AS cabo, t.rotulo AS tipo, ci.nome AS caixa_ini, cf.nome AS caixa_fim
                   FROM tab_ftth_cabo_vao v
                   JOIN tab_ftth_cabo cb ON cb.id = v.cabo_id
                   JOIN tab_ftth_cabo_tipo t ON t.id = cb.cabo_tipo_id
                   JOIN tab_ftth_caixa ci ON ci.id = v.caixa_ini_id
                   JOIN tab_ftth_caixa cf ON cf.id = v.caixa_fim_id
                  WHERE v.id = ? AND v.excluido_em IS NULL', [(int) $c['vao_id']]) ?: null;
        }

        // Serviço, equipamento, DIO/porta e a estimativa de sinal vinham escritos como "—"
        // na tela desde a fase 1, à espera do motor de potência. Ele existe agora, então a
        // ficha passa a responder de verdade de onde vem o sinal desta caixa.
        $c['potencia'] = Potencia::resumoDaCaixa($caixaId);

        return $c;
    }
}
