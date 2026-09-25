<?php
/**
 * ftth_doc :: rastreio de rota e potencia optica.
 *
 * O sinal nasce numa porta de DIO (a origem, com o TX da porta ou da OLT) e desce pela
 * rede. Este arquivo faz UMA coisa: propagar esse sinal pelo grafo que ja existe em
 * tab_ftth_ligacao e dizer, para qualquer ponta, quanto chega ali e por onde passou.
 *
 * NAO ha tabela de caminho (decisao D1). O grafo e a unica verdade; a rota e derivada
 * toda vez, em memoria. Isso custa 6 consultas e um BFS de algumas centenas de nos —
 * mais barato do que manter uma tabela que pode divergir do desenho.
 *
 * Os dois tipos de salto:
 *   - LIGACAO   dentro da caixa, entre duas pontas (fusao ou conector)
 *   - ELEMENTO  atravessando a peca: o cabo leva a fibra ate a caixa da outra ponta,
 *               o splitter leva a entrada ate cada saida, com a perda da razao
 */
require_once __DIR__ . '/Db.php';
require_once __DIR__ . '/Config.php';
require_once __DIR__ . '/Resultado.php';

final class Potencia
{
    /** Cache da propagacao dentro da mesma requisicao. */
    private static ?array $alcance = null;

    /** "caixa:ELEMENTO:id:numero" — a ponta e unica por caixa (uq_ponta_unica). */
    public static function chave(int $caixaId, string $elemento, int $elementoId, int $numero): string
    {
        return $caixaId . ':' . $elemento . ':' . $elementoId . ':' . $numero;
    }

    /**
     * Pontas da caixa que recebem sinal, na chave que o diagrama usa
     * ("ELEMENTO:id:numero"), cada uma com o dBm que chega ali.
     *
     * E isto que acende o pulso em volta da bolinha: quem esta aqui tem caminho ate a OLT.
     */
    public static function comSinal(int $caixaId): array
    {
        $saida = [];
        foreach (self::propagar() as $chave => $no) {
            $p = explode(':', $chave);
            if ((int) $p[0] !== $caixaId) {
                continue;
            }
            $saida[$p[1] . ':' . $p[2] . ':' . $p[3]] = round($no['dbm'], 2);
        }
        return $saida;
    }

    /**
     * A rota completa de uma ponta ate a origem, na ordem em que o sinal percorre.
     * Sem caminho ate um DIO, devolve FTTH-PWR-001 — que e a verdade, nao uma falha.
     */
    public static function rota(int $caixaId, string $elemento, int $elementoId, int $numero): Resultado
    {
        $alcance = self::propagar();
        $chave   = self::chave($caixaId, $elemento, $elementoId, $numero);

        if (!isset($alcance[$chave])) {
            return Resultado::erro('FTTH-PWR-001', ['ponta' => $chave],
                'Esta fibra não tem caminho óptico até uma porta de DIO com equipamento.');
        }

        // Sobe pelos predecessores e inverte: o usuario le do POP ate a ponta.
        $passos = [];
        $atual  = $chave;
        $limite = 500;                        // rede corrompida nao pode travar a tela
        while ($atual !== null && $limite-- > 0) {
            $no = $alcance[$atual];
            if ($no['de'] !== null) {
                $passos[] = [
                    'via'    => $no['via'],
                    'rotulo' => $no['rotulo'],
                    'perda'  => round($no['perda'], 2),
                    'dbm'    => round($no['dbm'], 2),
                    'caixa'  => $no['caixa'],
                    'km'     => $no['km'],
                ];
            }
            $atual = $no['de'];
        }

        $dbm = round($alcance[$chave]['dbm'], 2);

        return Resultado::ok([
            'origem' => $alcance[$chave]['origem'],
            'passos' => array_reverse($passos),
            'dbm'    => $dbm,
            'classe' => Config::classificarSinal($dbm),
        ]);
    }

    /**
     * O sinal da caixa, em uma linha: quanto chega, de onde vem e medido onde.
     *
     * Qual ponta representa a caixa depende do que ela é. Numa CTO existe um splitter de
     * atendimento, e o número que interessa é o que SAI dele — é o que o cliente recebe.
     * Sem splitter de atendimento (uma CEO de passagem, por exemplo), vale a fibra que
     * chega mais forte. Devolve null quando não há caminho óptico até uma origem.
     */
    public static function resumoDaCaixa(int $caixaId): ?array
    {
        $sinal = self::comSinal($caixaId);
        if (!$sinal) {
            return null;
        }

        $atendimento = [];
        foreach (Db::todos(
            'SELECT id, nome FROM tab_ftth_splitter
              WHERE caixa_id = ? AND funcao = "ATENDIMENTO" AND excluido_em IS NULL',
            [$caixaId]) as $s) {
            $atendimento[(int) $s['id']] = $s['nome'];
        }

        $escolhida = null;
        $onde      = '';

        // 1) Saída de splitter de atendimento, a de menor número — as de um balanceado
        //    têm todas o mesmo valor, então serve qualquer uma.
        foreach ($sinal as $chave => $dbm) {
            $p = explode(':', $chave);
            if ($p[0] !== 'SPLITTER_OUT' || !isset($atendimento[(int) $p[1]])) {
                continue;
            }
            if ($escolhida === null || (int) $p[2] < (int) explode(':', $escolhida)[2]) {
                $escolhida = $chave;
                $onde = 'saída do ' . $atendimento[(int) $p[1]];
            }
        }

        // 2) Sem atendimento na caixa: a ponta que chega mais forte.
        if ($escolhida === null) {
            foreach ($sinal as $chave => $dbm) {
                if ($escolhida === null || $dbm > $sinal[$escolhida]) {
                    $escolhida = $chave;
                }
            }
            $onde = 'fibra que chega';
        }

        $p = explode(':', $escolhida);
        $r = self::rota($caixaId, $p[0], (int) $p[1], (int) $p[2]);
        if (!$r->ok) {
            return null;
        }

        // A faixa (excelente/bom/limite) foi feita para o RX da ONU, e só existe ONU depois
        // de um splitter de atendimento. Classificar a fibra de um backbone por ela diria
        // "saturado" em toda CEO de passagem — tecnicamente verdade, praticamente enganoso.
        // Fora do atendimento vai só o nível, sem rótulo de qualidade.
        $naSaidaDeAtendimento = $p[0] === 'SPLITTER_OUT';

        return [
            'dbm'    => $r->data['dbm'],
            'classe' => $naSaidaDeAtendimento ? $r->data['classe'] : null,
            'onde'   => $onde,
            'saltos' => count($r->data['passos']),
            'origem' => $r->data['origem'],
            // A ponta escolhida sai junto para quem quiser a rota COMPLETA depois medir
            // exatamente o mesmo caminho — senão a ficha e o detalhe poderiam divergir.
            'ponta'  => ['elemento' => $p[0], 'elemento_id' => (int) $p[1],
                         'numero' => (int) $p[2]],
        ];
    }

    /**
     * Todas as caixas que recebem sinal, de uma vez: [caixa_id => true].
     *
     * É o mesmo critério do "sem caminho óptico" da ficha (alguma ponta da caixa alcançada a
     * partir de uma origem), mas numa propagação só para a rede inteira — a lista de pontos
     * do painel não pode pagar uma conta por caixa.
     */
    public static function caixasComSinal(): array
    {
        $saida = [];
        foreach (self::propagar() as $chave => $no) {
            $saida[(int) strtok($chave, ':')] = true;
        }
        return $saida;
    }

    /** Zera o cache — os testes mexem no grafo entre uma conta e outra. */
    public static function esquecer(): void
    {
        self::$alcance = null;
    }

    // ------------------------------------------------------------------ o motor

    /**
     * Propaga o sinal das origens para toda a rede.
     *
     * Guarda sempre o MELHOR caminho (maior dBm) de cada ponta. Isso resolve dois
     * problemas de uma vez: rede com dois caminhos documentados fica com o mais forte, e
     * um laco termina sozinho — dar a volta so tira potencia, entao nunca melhora.
     */
    private static function propagar(): array
    {
        if (self::$alcance !== null) {
            return self::$alcance;
        }

        $g       = self::carregar();
        $alcance = [];
        $fila    = [];

        foreach ($g['origens'] as $chave => $o) {
            $alcance[$chave] = [
                'dbm'   => $o['ptx'], 'de' => null, 'via' => 'ORIGEM', 'rotulo' => '',
                'perda' => 0.0, 'caixa' => $o['caixa'], 'km' => null, 'origem' => $o['info'],
            ];
            $fila[] = $chave;
        }

        while ($fila) {
            $de = array_shift($fila);
            foreach (self::vizinhos($de, $g) as $v) {
                $dbm = $alcance[$de]['dbm'] - $v['perda'];

                // So segue se melhorou: e o que impede laco e mantem a rota mais forte.
                if (isset($alcance[$v['para']]) && $alcance[$v['para']]['dbm'] >= $dbm) {
                    continue;
                }
                $alcance[$v['para']] = [
                    'dbm'    => $dbm,
                    'de'     => $de,
                    'via'    => $v['via'],
                    'rotulo' => $v['rotulo'],
                    'perda'  => $v['perda'],
                    'caixa'  => $v['caixa'],
                    'km'     => $v['km'] ?? null,
                    'origem' => $alcance[$de]['origem'],
                ];
                $fila[] = $v['para'];
            }
        }

        return self::$alcance = $alcance;
    }

    /** Para onde o sinal vai a partir desta ponta, e quanto custa cada passo. */
    private static function vizinhos(string $chave, array $g): array
    {
        $partes  = explode(':', $chave);
        $caixa   = (int) $partes[0];
        $tipo    = $partes[1];
        $id      = (int) $partes[2];
        $numero  = (int) $partes[3];
        $saida   = [];

        // 1) Atravessar a propria peca.
        if ($tipo === 'VAO_FIBRA' && isset($g['vaos'][$id])) {
            $v     = $g['vaos'][$id];
            $outra = $caixa === (int) $v['caixa_ini_id']
                   ? (int) $v['caixa_fim_id'] : (int) $v['caixa_ini_id'];

            if ($outra !== $caixa) {
                $km = ((float) $v['comprimento_optico']) / 1000;
                $saida[] = [
                    'para'   => self::chave($outra, 'VAO_FIBRA', $id, $numero),
                    'perda'  => $km * $g['db_km'],
                    'via'    => 'CABO',
                    'rotulo' => $v['cabo'] . ' · ' . self::fo($numero),
                    'caixa'  => $g['caixas'][$outra] ?? '',
                    'km'     => round($km, 3),
                ];
            }
        }

        // O sinal entra pela entrada e sai por TODAS as saidas — nunca ao contrario:
        // um splitter nao leva sinal de volta pela saida.
        if ($tipo === 'SPLITTER_IN' && isset($g['splitters'][$id])) {
            $s = $g['splitters'][$id];
            foreach ($s['perdas'] as $k => $perda) {
                $saida[] = [
                    'para'   => self::chave($caixa, 'SPLITTER_OUT', $id, (int) $k),
                    'perda'  => (float) $perda,
                    'via'    => 'SPLITTER',
                    'rotulo' => $s['nome'] . ' ' . $s['razao'] . ' · saída ' . (int) $k,
                    'caixa'  => $g['caixas'][$caixa] ?? '',
                ];
            }
        }

        // 2) Seguir a ligacao dentro da caixa.
        //
        // PASSAGEM custa ZERO de proposito: e a fibra que atravessa a caixa inteira numa
        // sangria, sem ser cortada nem emendada. Cobrar 0,10 dB dela seria inventar perda.
        if (isset($g['pares'][$chave])) {
            $par = $g['pares'][$chave];
            $perdas = [
                'CONECTOR' => $g['perda_conector'],
                'PASSAGEM' => 0.0,
                'FUSAO'    => $g['perda_fusao'],
            ];
            $tipo = isset($perdas[$par['tipo']]) ? $par['tipo'] : 'FUSAO';
            $saida[] = [
                'para'   => $par['outra'],
                'perda'  => $perdas[$tipo],
                'via'    => $tipo,
                'rotulo' => $par['rotulo'],
                'caixa'  => $g['caixas'][$caixa] ?? '',
            ];
        }

        return $saida;
    }

    /** Todo o grafo em poucas consultas: depois disso o BFS nao toca mais no banco. */
    private static function carregar(): array
    {
        $g = [
            'perda_fusao'    => Config::num('perda_fusao_db', 0.10),
            'perda_conector' => Config::num('perda_conector_db', 0.50),
            'db_km'          => 0.28,
            'caixas'         => [],
            'vaos'           => [],
            'splitters'      => [],
            'pares'          => [],
            'origens'        => [],
        ];

        $lambda = (int) Config::num('lambda_estimativa', 1490);
        $db = Db::valor('SELECT db_km FROM tab_ftth_atenuacao WHERE lambda_nm = ?', [$lambda]);
        if ($db !== null) {
            $g['db_km'] = (float) $db;
        }

        foreach (Db::todos('SELECT id, nome FROM tab_ftth_caixa WHERE excluido_em IS NULL') as $c) {
            $g['caixas'][(int) $c['id']] = $c['nome'];
        }

        foreach (Db::todos(
            'SELECT v.id, v.caixa_ini_id, v.caixa_fim_id, v.comprimento_optico,
                    COALESCE(cb.nome, t.rotulo) AS cabo
               FROM tab_ftth_cabo_vao v
               JOIN tab_ftth_cabo cb ON cb.id = v.cabo_id
               JOIN tab_ftth_cabo_tipo t ON t.id = cb.cabo_tipo_id
              WHERE v.excluido_em IS NULL') as $v) {
            $g['vaos'][(int) $v['id']] = $v;
        }

        foreach (Db::todos(
            'SELECT id, nome, razao, perdas_json FROM tab_ftth_splitter
              WHERE excluido_em IS NULL') as $s) {
            $g['splitters'][(int) $s['id']] = [
                'nome'   => $s['nome'],
                'razao'  => $s['razao'],
                'perdas' => json_decode((string) $s['perdas_json'], true) ?: [],
            ];
        }

        // Pontas em pares: cada ligacao tem exatamente duas (uq_lado garante).
        $porLigacao = [];
        foreach (Db::todos(
            'SELECT p.ligacao_id, p.caixa_id, p.elemento, p.elemento_id, p.numero, l.tipo
               FROM tab_ftth_ligacao_ponta p
               JOIN tab_ftth_ligacao l ON l.id = p.ligacao_id
              ORDER BY p.ligacao_id, p.lado') as $p) {
            $porLigacao[(int) $p['ligacao_id']][] = $p;
        }

        foreach ($porLigacao as $duas) {
            if (count($duas) !== 2) {
                continue;                     // ligacao quebrada nao entra no calculo
            }
            $a = $duas[0];
            $b = $duas[1];
            $ka = self::chave((int) $a['caixa_id'], $a['elemento'], (int) $a['elemento_id'], (int) $a['numero']);
            $kb = self::chave((int) $b['caixa_id'], $b['elemento'], (int) $b['elemento_id'], (int) $b['numero']);
            $rotulo = self::rotuloPonta($a, $g) . ' × ' . self::rotuloPonta($b, $g);

            $g['pares'][$ka] = ['outra' => $kb, 'tipo' => $a['tipo'], 'rotulo' => $rotulo];
            $g['pares'][$kb] = ['outra' => $ka, 'tipo' => $a['tipo'], 'rotulo' => $rotulo];
        }

        // Origens: portas de DIO que estao numa ligacao. O TX e o da porta e, na falta,
        // o da OLT — o numero que o cadastro do POP alimenta.
        foreach (Db::todos(
            'SELECT dp.id, dp.numero, dp.servico, dp.ptx_dbm, dp.pon,
                    d.nome AS dio, d.caixa_id,
                    o.apelido AS olt, o.ptx_dbm AS olt_ptx
               FROM tab_ftth_dio_porta dp
               JOIN tab_ftth_dio d ON d.id = dp.dio_id
          LEFT JOIN tab_ftth_olt o ON o.id = dp.olt_ftth_id
              WHERE dp.excluido_em IS NULL AND d.excluido_em IS NULL') as $p) {
            $chave = self::chave((int) $p['caixa_id'], 'DIO_PORTA', (int) $p['id'], 0);
            if (!isset($g['pares'][$chave])) {
                continue;                     // porta sem saida para a rua nao e origem
            }
            $ptx = $p['ptx_dbm'] !== null
                 ? (float) $p['ptx_dbm']
                 : ($p['olt_ptx'] !== null ? (float) $p['olt_ptx'] : 3.00);

            $g['origens'][$chave] = [
                'ptx'   => $ptx,
                'caixa' => $g['caixas'][(int) $p['caixa_id']] ?? '',
                'info'  => [
                    'caixa'   => $g['caixas'][(int) $p['caixa_id']] ?? '',
                    'olt'     => $p['olt'],
                    'pon'     => $p['pon'],
                    'dio'     => $p['dio'],
                    'porta'   => 'P' . str_pad((string) $p['numero'], 2, '0', STR_PAD_LEFT),
                    'servico' => $p['servico'],
                    'ptx_dbm' => $ptx,
                ],
            ];
        }

        return $g;
    }

    private static function fo(int $numero): string
    {
        return 'Fo' . str_pad((string) $numero, 2, '0', STR_PAD_LEFT);
    }

    /** "Fo03", "SPL.01 IN", "DIO porta" — o que o usuario le na linha da rota. */
    private static function rotuloPonta(array $p, array $g): string
    {
        $id  = (int) $p['elemento_id'];
        $num = (int) $p['numero'];

        if ($p['elemento'] === 'VAO_FIBRA') {
            return self::fo($num);
        }
        if ($p['elemento'] === 'SPLITTER_IN') {
            return ($g['splitters'][$id]['nome'] ?? 'Splitter') . ' IN';
        }
        if ($p['elemento'] === 'SPLITTER_OUT') {
            return ($g['splitters'][$id]['nome'] ?? 'Splitter') . ' T'
                 . str_pad((string) $num, 2, '0', STR_PAD_LEFT);
        }
        return 'DIO porta';
    }
}
