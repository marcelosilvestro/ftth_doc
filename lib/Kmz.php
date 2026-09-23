<?php
/**
 * ftth_doc :: leitura de KMZ/KML e QUARENTENA (3b.7).
 *
 * Regras que este arquivo respeita:
 *  - o importador NUNCA inventa conectividade: le geometria, nome, tipo e cor, mais nada;
 *  - nada entra nas tabelas reais aqui: tudo vai para tab_ftth_importacao_item como 'pendente';
 *  - reimportar o mesmo arquivo e detectado pelo hash (FTTH-KMZ-002);
 *  - item ja importado antes e detectado pelo hash da geometria (FTTH-KMZ-003);
 *  - itens problematicos entram com alerta, nao sao descartados em silencio.
 *
 * O arquivo de referencia e rede_ftth/AsBuilt_Palmital.kmz, exportado do UpperX Fibra:
 * 221 pontos (CTO/CEO/DC/Problema) e 212 linhas ("Cabo 6FO", "Cabo 12FO", "Cabo 24FO").
 */
require_once __DIR__ . '/Db.php';
require_once __DIR__ . '/Geo.php';
require_once __DIR__ . '/Resultado.php';
require_once __DIR__ . '/Auditoria.php';

final class Kmz
{
    /** Texto do <description> do UpperX -> tipo de caixa do addon. */
    private const TIPOS = [
        'caixa de atendimento' => 'CTO',
        'caixa de emenda'      => 'CEO',
        'data center'          => 'DC',
        'pop'                  => 'DC',
        'predio'               => 'PREDIO',
        'prédio'               => 'PREDIO',
        'poste'                => 'POSTE',
        'cliente'              => 'CLIENTE',
        'reserva'              => 'RESERVA',
        'problema'             => 'PROBLEMA',
        'falha'                => 'FALHA',
        'cto ap'               => 'CTO_AP',
    ];

    /** Nome do ícone paddle do Google -> cor hex, para manter a aparência do original. */
    private const CORES_ICONE = [
        'grn'    => '#00C853', 'green'  => '#00C853',
        'orange' => '#FF9100', 'ylw'    => '#FFD600', 'yellow' => '#FFD600',
        'red'    => '#D50000', 'pink'   => '#F50057', 'purple' => '#AA00FF',
        'blu'    => '#2962FF', 'blue'   => '#2962FF', 'ltblu'  => '#00B0FF',
        'wht'    => '#FFFFFF', 'white'  => '#FFFFFF', 'grey'   => '#757575',
    ];

    /**
     * Le o arquivo e devolve os itens SEM gravar nada.
     * @return array{itens:array,resumo:array}
     */
    public static function ler(string $caminho): array
    {
        $kml = self::extrairKml($caminho);
        if ($kml === null) {
            throw new RuntimeException('FTTH-KMZ-001');
        }

        $anterior = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($kml);
        libxml_use_internal_errors($anterior);
        if ($xml === false) {
            throw new RuntimeException('FTTH-KMZ-001');
        }

        $itens  = [];
        $resumo = ['pontos' => 0, 'linhas' => 0, 'ignorados' => 0, 'metros' => 0.0, 'tipos' => []];

        foreach ($xml->xpath('//*[local-name()="Placemark"]') ?: [] as $pm) {
            $nome = trim((string) $pm->name);
            $desc = trim((string) $pm->description);
            $cor  = self::corDoIcone((string) ($pm->Style->IconStyle->Icon->href ?? ''))
                 ?? self::corDaLinha((string) ($pm->Style->LineStyle->color ?? ''));

            $ponto = $pm->xpath('.//*[local-name()="Point"]/*[local-name()="coordinates"]');
            $linha = $pm->xpath('.//*[local-name()="LineString"]/*[local-name()="coordinates"]');

            if ($ponto) {
                $coords = self::coordenadas((string) $ponto[0]);
                if (count($coords) !== 1) { $resumo['ignorados']++; continue; }
                $tipo = self::tipoDaDescricao($desc, $nome);
                $itens[] = [
                    'tipo_sugerido' => 'CAIXA',
                    'subtipo'       => $tipo,
                    'nome'          => $nome !== '' ? $nome : null,
                    'cor'           => $cor ?? '#00C853',
                    'geometria'     => $coords,
                    'alertas'       => $nome === ''
                        ? [['code' => 'FTTH-KMZ-001', 'message' => 'Item sem nome no arquivo.']]
                        : [],
                ];
                $resumo['pontos']++;
                $resumo['tipos'][$tipo] = ($resumo['tipos'][$tipo] ?? 0) + 1;
            } elseif ($linha) {
                $coords = self::coordenadas((string) $linha[0]);
                if (count($coords) < 2) { $resumo['ignorados']++; continue; }
                $metros = Geo::comprimento($coords);
                $alertas = [];
                if ($metros < 1.0) {
                    $alertas[] = ['code' => 'FTTH-GEO-004', 'message' => 'Vão com menos de 1 metro.'];
                }
                $itens[] = [
                    'tipo_sugerido' => 'VAO',
                    'subtipo'       => self::rotuloCabo($nome),
                    'nome'          => $nome !== '' ? $nome : null,
                    'cor'           => $cor ?? '#00E676',
                    'geometria'     => $coords,
                    'metros'        => round($metros, 2),
                    'alertas'       => $alertas,
                ];
                $resumo['linhas']++;
                $resumo['metros'] += $metros;
            } else {
                $resumo['ignorados']++;
            }
        }

        $resumo['metros'] = round($resumo['metros'], 2);
        return ['itens' => $itens, 'resumo' => $resumo];
    }

    /**
     * Grava os itens lidos na QUARENTENA, resolvendo as âncoras de cada vão.
     * Tudo numa transação: ou entra a importação inteira, ou nenhuma linha (3b.7).
     *
     * @return array{importacao_id:int,resumo:array}
     */
    public static function importarParaQuarentena(
        string $caminho,
        string $nomeArquivo,
        int $regiaoId,
        string $usuario,
        bool $ignorarDuplicado = false
    ): array {
        $hashArquivo = hash_file('sha256', $caminho);

        $jaImportado = Db::um(
            'SELECT id, arquivo, criado_em FROM tab_ftth_importacao WHERE hash_arquivo = ? ORDER BY id DESC LIMIT 1',
            [$hashArquivo]
        );
        if ($jaImportado && !$ignorarDuplicado) {
            throw new RuntimeException('FTTH-KMZ-002:' . json_encode($jaImportado));
        }

        $lido = self::ler($caminho);

        return Db::transacao(function () use ($lido, $hashArquivo, $nomeArquivo, $caminho, $regiaoId, $usuario) {
            Db::exec(
                'INSERT INTO tab_ftth_importacao
                    (regiao_id, arquivo, hash_arquivo, bytes, formato, total_itens, pendentes, alertas, criado_por, criado_em)
                 VALUES (?,?,?,?,?,0,0,0,?,NOW())',
                [$regiaoId, $nomeArquivo, $hashArquivo, filesize($caminho),
                 strtoupper(pathinfo($nomeArquivo, PATHINFO_EXTENSION)) === 'KML' ? 'KML' : 'KMZ', $usuario]
            );
            $impId = Db::ultimoId();

            // Caixas primeiro: os vãos precisam delas para sugerir âncora.
            usort($lido['itens'], fn($a, $b) => ($a['tipo_sugerido'] === 'CAIXA' ? 0 : 1)
                                             <=> ($b['tipo_sugerido'] === 'CAIXA' ? 0 : 1));

            $caixasDoArquivo = [];   // [lat, lng, indice do item]
            $alertas = 0;

            foreach ($lido['itens'] as $item) {
                $hashGeo = self::hashGeometria($item['geometria']);
                $itemAlertas = $item['alertas'];

                // Já importado antes (mesma geometria virou caixa/vão de verdade)?
                $repetido = Db::um(
                    'SELECT id FROM tab_ftth_importacao_item
                     WHERE regiao_id = ? AND hash_geometria = ? AND status = "importado" LIMIT 1',
                    [$regiaoId, $hashGeo]
                );
                if ($repetido) {
                    $itemAlertas[] = ['code' => 'FTTH-KMZ-003', 'message' => 'Geometria já importada antes.'];
                }

                $ancoraIni = null;
                $ancoraFim = null;
                if ($item['tipo_sugerido'] === 'VAO') {
                    $ini = $item['geometria'][0];
                    $fim = $item['geometria'][count($item['geometria']) - 1];
                    $ancoraIni = self::caixaMaisProxima($regiaoId, $ini, $caixasDoArquivo);
                    $ancoraFim = self::caixaMaisProxima($regiaoId, $fim, $caixasDoArquivo);

                    if ($ancoraIni === null || $ancoraFim === null) {
                        $itemAlertas[] = ['code' => 'FTTH-GEO-005',
                            'message' => 'Ponta sem caixa próxima para ancorar.'];
                    } elseif ($ancoraIni['ref'] === $ancoraFim['ref']) {
                        $itemAlertas[] = ['code' => 'FTTH-GEO-003',
                            'message' => 'As duas pontas caem na mesma caixa.'];
                    }
                }

                // A ancora pode ser uma caixa que ja existe OU um item da propria quarentena
                // (numa importacao nova, as caixas das pontas ainda nao foram importadas).
                Db::exec(
                    'INSERT INTO tab_ftth_importacao_item
                        (importacao_id, regiao_id, tipo_sugerido, subtipo, nome, cor, geometria,
                         hash_geometria, ancora_ini_id, ancora_ini_item_id,
                         ancora_fim_id, ancora_fim_item_id, alertas_json, status)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,"pendente")',
                    [$impId, $regiaoId, $item['tipo_sugerido'], $item['subtipo'], $item['nome'],
                     $item['cor'], json_encode($item['geometria']), $hashGeo,
                     $ancoraIni['id'] ?? null, $ancoraIni['item_id'] ?? null,
                     $ancoraFim['id'] ?? null, $ancoraFim['item_id'] ?? null,
                     $itemAlertas ? json_encode($itemAlertas, JSON_UNESCAPED_UNICODE) : null]
                );
                $itemId = Db::ultimoId();

                if ($item['tipo_sugerido'] === 'CAIXA') {
                    $caixasDoArquivo[] = [
                        'lat' => (float) $item['geometria'][0][0],
                        'lng' => (float) $item['geometria'][0][1],
                        'ref' => 'item:' . $itemId,
                        'id'  => null,          // ainda não existe caixa real
                        'item_id' => $itemId,
                    ];
                }
                if ($itemAlertas) {
                    $alertas++;
                }
            }

            $total = count($lido['itens']);
            Db::exec(
                'UPDATE tab_ftth_importacao SET total_itens = ?, pendentes = ?, alertas = ? WHERE id = ?',
                [$total, $total, $alertas, $impId]
            );

            Auditoria::registrar('importacao', $impId, 'importar_kmz', null, [
                'arquivo' => $nomeArquivo,
                'itens'   => $total,
                'alertas' => $alertas,
                'resumo'  => $lido['resumo'],
            ], $regiaoId);

            return ['importacao_id' => $impId, 'resumo' => $lido['resumo'] + [
                'total' => $total, 'alertas' => $alertas,
            ]];
        });
    }

    // ------------------------------------------------------------------ apoio

    /** Caixa real (já importada) ou caixa que veio no mesmo arquivo, dentro da tolerância. */
    private static function caixaMaisProxima(int $regiaoId, array $ponto, array $doArquivo): ?array
    {
        $tolerancia = (float) (Db::valor('SELECT valor FROM tab_ftth_config WHERE chave = "tolerancia_ancora_m"') ?? 3);
        $lat = (float) $ponto[0];
        $lng = (float) $ponto[1];
        $melhor = null;

        // Caixas reais próximas — filtro grosso por caixa delimitadora antes do Haversine.
        $grau = $tolerancia / 111320.0 * 1.5;
        $cands = Db::todos(
            'SELECT id, nome, lat, lng FROM tab_ftth_caixa
             WHERE regiao_id = ? AND excluido_em IS NULL
               AND lat BETWEEN ? AND ? AND lng BETWEEN ? AND ?',
            [$regiaoId, $lat - $grau, $lat + $grau, $lng - $grau, $lng + $grau]
        );
        foreach ($cands as $c) {
            $d = Geo::distancia($lat, $lng, (float) $c['lat'], (float) $c['lng']);
            if ($d <= $tolerancia && ($melhor === null || $d < $melhor['dist'])) {
                $melhor = ['id' => (int) $c['id'], 'ref' => 'caixa:' . $c['id'], 'dist' => $d];
            }
        }

        // Caixas que vieram no mesmo arquivo e ainda estão na quarentena.
        foreach ($doArquivo as $c) {
            $d = Geo::distancia($lat, $lng, $c['lat'], $c['lng']);
            if ($d <= $tolerancia && ($melhor === null || $d < $melhor['dist'])) {
                $melhor = ['id' => null, 'ref' => $c['ref'], 'dist' => $d, 'item_id' => $c['item_id']];
            }
        }

        return $melhor;
    }

    private static function extrairKml(string $caminho): ?string
    {
        $conteudo = @file_get_contents($caminho, false, null, 0, 4);
        if ($conteudo === false) {
            return null;
        }
        // KML puro começa com "<" ou BOM; KMZ é um ZIP ("PK").
        if (substr($conteudo, 0, 2) !== 'PK') {
            $texto = file_get_contents($caminho);
            return $texto === false ? null : $texto;
        }
        if (!class_exists('ZipArchive')) {
            return null;
        }
        $zip = new ZipArchive();
        if ($zip->open($caminho) !== true) {
            return null;
        }
        $kml = null;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $nome = $zip->getNameIndex($i);
            if (preg_match('/\.kml$/i', $nome)) {
                $kml = $zip->getFromIndex($i);
                break;
            }
        }
        $zip->close();
        return $kml === false ? null : $kml;
    }

    /** "lng,lat,alt lng,lat,alt" -> [[lat,lng], ...] */
    private static function coordenadas(string $texto): array
    {
        $saida = [];
        foreach (preg_split('/\s+/', trim($texto)) ?: [] as $par) {
            if ($par === '') {
                continue;
            }
            $p = explode(',', $par);
            if (count($p) < 2) {
                continue;
            }
            $lng = (float) $p[0];
            $lat = (float) $p[1];
            if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
                continue;
            }
            $saida[] = [round($lat, 7), round($lng, 7)];
        }
        return $saida;
    }

    private static function tipoDaDescricao(string $desc, string $nome): string
    {
        $t = mb_strtolower($desc . ' ' . $nome);
        $t = str_replace(['tipo:', 'tipo :'], '', $t);
        foreach (self::TIPOS as $chave => $tipo) {
            if (str_contains($t, $chave)) {
                return $tipo;
            }
        }
        // Sem descrição utilizável: tenta pelo prefixo do nome (CTO.01.02, CEO.03...).
        if (preg_match('/^(cto|ceo|dc|pop)\b/i', trim($nome), $m)) {
            $p = strtoupper($m[1]);
            return $p === 'POP' ? 'DC' : $p;
        }
        return 'CTO';
    }

    /** "Cabo 6FO" -> "6 FO" (rótulo do catálogo tab_ftth_cabo_tipo). */
    private static function rotuloCabo(string $nome): ?string
    {
        if (preg_match('/(\d+)\s*FO/i', $nome, $m)) {
            return ((int) $m[1] === 1) ? 'Drop (1 FO)' : $m[1] . ' FO';
        }
        return null;
    }

    private static function corDoIcone(string $href): ?string
    {
        if ($href === '' || !preg_match('#/([a-z]+)-[a-z]+\.png#i', $href, $m)) {
            return null;
        }
        return self::CORES_ICONE[strtolower($m[1])] ?? null;
    }

    /** KML usa aabbggrr; o addon guarda #rrggbb. */
    private static function corDaLinha(string $kmlCor): ?string
    {
        if (!preg_match('/^[0-9a-f]{8}$/i', $kmlCor)) {
            return null;
        }
        return '#' . strtoupper(substr($kmlCor, 6, 2) . substr($kmlCor, 4, 2) . substr($kmlCor, 2, 2));
    }

    private static function hashGeometria(array $geometria): string
    {
        return hash('sha256', json_encode(array_map(
            fn($p) => [round((float) $p[0], 6), round((float) $p[1], 6)], $geometria
        )));
    }
}
