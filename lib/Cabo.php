<?php
/**
 * ftth_doc :: cabos e vãos.
 *
 * O desenho vem do mapa como uma sequência de pontos, onde alguns são CAIXAS (âncoras) e
 * outros são apenas vértices da rua. O serviço quebra esse traçado em VÃOS: um vão por
 * trecho entre duas caixas consecutivas (I1). Um cabo que passa por 4 caixas vira 3 vãos.
 *
 * Regras (I1):
 *  - o traçado começa e termina em caixa;
 *  - são necessárias ao menos 2 caixas;
 *  - duas caixas seguidas não podem ser a mesma;
 *  - cada vão precisa de geometria válida.
 */
require_once __DIR__ . '/Db.php';
require_once __DIR__ . '/Geo.php';
require_once __DIR__ . '/Config.php';
require_once __DIR__ . '/Versao.php';
require_once __DIR__ . '/Auditoria.php';
require_once __DIR__ . '/Resultado.php';
require_once __DIR__ . '/Caixa.php';
require_once __DIR__ . '/Topologia.php';

final class Cabo
{
    public const PADROES_COR = ['ABNT', 'EIA-TIA'];
    public const STATUS      = ['projeto', 'implantado', 'certificado'];

    public static function tipos(): array
    {
        return Db::todos(
            'SELECT id, rotulo, fibras, tubos, fibras_por_tubo, construcao
               FROM tab_ftth_cabo_tipo WHERE ativo = 1 ORDER BY ordem, fibras');
    }

    /**
     * @param array $pontos  [{tipo:"CAIXA", id:int} | {tipo:"VERTICE", lat:float, lng:float}, ...]
     */
    public static function criar(int $regiaoId, array $dados, array $pontos, string $usuario): Resultado
    {
        if (count($pontos) < 2) {
            return Resultado::erro('FTTH-GEO-001', [], 'Desenhe ao menos o início e o fim do cabo.');
        }
        if (($pontos[0]['tipo'] ?? '') !== 'CAIXA' || (end($pontos)['tipo'] ?? '') !== 'CAIXA') {
            return Resultado::erro('FTTH-GEO-005', [],
                'O cabo precisa começar e terminar em uma caixa.');
        }

        // Resolve as caixas e monta a geometria completa na ordem do desenho.
        $sequencia = [];
        foreach ($pontos as $p) {
            if (($p['tipo'] ?? '') === 'CAIXA') {
                $c = Db::um('SELECT id, lat, lng, regiao_id FROM tab_ftth_caixa
                              WHERE id = ? AND excluido_em IS NULL', [(int) ($p['id'] ?? 0)]);
                if (!$c) {
                    return Resultado::erro('FTTH-TOP-001', ['caixa' => $p['id'] ?? null]);
                }
                if ((int) $c['regiao_id'] !== $regiaoId) {
                    return Resultado::erro('FTTH-SYS-002', ['caixa' => (int) $c['id']],
                        'A caixa é de outra região.');
                }
                $sequencia[] = ['caixa' => (int) $c['id'],
                                'ponto' => [(float) $c['lat'], (float) $c['lng']]];
            } else {
                $lat = (float) ($p['lat'] ?? 0);
                $lng = (float) ($p['lng'] ?? 0);
                if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
                    return Resultado::erro('FTTH-GEO-002', ['lat' => $lat, 'lng' => $lng]);
                }
                $sequencia[] = ['caixa' => null, 'ponto' => [$lat, $lng]];
            }
        }

        // Quebra em vãos: tudo entre duas caixas consecutivas vira um vão.
        $vaos = [];
        $atual = null;
        foreach ($sequencia as $item) {
            if ($atual === null) {
                if ($item['caixa'] === null) {
                    continue;   // não deve acontecer: o primeiro é caixa
                }
                $atual = ['ini' => $item['caixa'], 'vertices' => [$item['ponto']]];
                continue;
            }
            $atual['vertices'][] = $item['ponto'];
            if ($item['caixa'] !== null) {
                if ($item['caixa'] === $atual['ini']) {
                    return Resultado::erro('FTTH-GEO-003', ['caixa' => $item['caixa']],
                        'Um vão não pode começar e terminar na mesma caixa.');
                }
                $atual['fim'] = $item['caixa'];
                $vaos[] = $atual;
                $atual = ['ini' => $item['caixa'], 'vertices' => [$item['ponto']]];
            }
        }

        if (!$vaos) {
            return Resultado::erro('FTTH-GEO-001', [], 'O traçado não formou nenhum vão.');
        }

        $tipoId = (int) ($dados['cabo_tipo_id'] ?? 0);
        $tipo = Db::um('SELECT id, rotulo, fibras FROM tab_ftth_cabo_tipo WHERE id = ? AND ativo = 1', [$tipoId]);
        if (!$tipo) {
            return Resultado::erro('FTTH-SYS-002', ['campo' => 'capacidade'], 'Escolha a capacidade do cabo.');
        }

        $padrao = in_array($dados['padrao_cores'] ?? '', self::PADROES_COR, true)
                ? $dados['padrao_cores'] : 'ABNT';
        $cor = preg_match('/^#[0-9A-Fa-f]{6}$/', (string) ($dados['cor_rota'] ?? ''))
             ? (string) $dados['cor_rota'] : '#00E676';
        $nome = trim((string) ($dados['nome'] ?? '')) ?: null;
        $fabricante = trim((string) ($dados['fabricante'] ?? '')) ?: null;
        $folga = Config::num('fator_folga_cabo', 1.03);

        return Db::transacao(function () use ($regiaoId, $vaos, $tipo, $padrao, $cor, $nome,
                                              $fabricante, $folga, $usuario) {
            Db::exec(
                'INSERT INTO tab_ftth_cabo
                    (regiao_id, nome, fabricante, cabo_tipo_id, padrao_cores, cor_rota,
                     origem, criado_por, criado_em)
                 VALUES (?,?,?,?,?,?,"manual",?,NOW())',
                [$regiaoId, $nome, $fabricante, $tipo['id'], $padrao, $cor, $usuario]
            );
            $caboId = Db::ultimoId();

            $total = 0.0;
            $ids   = [];
            foreach ($vaos as $i => $v) {
                $metros = Geo::comprimento($v['vertices']);
                $optico = Geo::comprimentoOptico($metros, $folga, 0);
                $total += $optico;
                Db::exec(
                    'INSERT INTO tab_ftth_cabo_vao
                        (cabo_id, regiao_id, ordem, caixa_ini_id, caixa_fim_id, vertices,
                         comprimento_geo, fator_folga, reserva_m, comprimento_optico,
                         origem, criado_por, criado_em)
                     VALUES (?,?,?,?,?,?,?,?,0,?,"manual",?,NOW())',
                    [$caboId, $regiaoId, $i + 1, $v['ini'], $v['fim'], json_encode($v['vertices']),
                     round($metros, 2), $folga, $optico, $usuario]
                );
                $ids[] = Db::ultimoId();
            }

            Auditoria::registrar('cabo', $caboId, 'criar', null, [
                'nome'   => $nome,
                'tipo'   => $tipo['rotulo'],
                'vaos'   => count($vaos),
                'metros' => round($total, 2),
            ], $regiaoId);

            return Resultado::ok([
                'cabo_id' => $caboId,
                'vaos'    => $ids,
                'tipo'    => $tipo['rotulo'],
                'metros'  => round($total, 2),
            ]);
        });
    }

    /**
     * Altera os atributos do cabo — nome, fabricante, capacidade, cores e status.
     *
     * O traçado NÃO se mexe aqui (decisão de 22/09/2026): geometria é outra história, com
     * recálculo de comprimento e revalidação da rota. Quem quiser mudar o caminho redesenha.
     */
    public static function alterar(int $caboId, array $dados, ?int $versao, string $usuario): Resultado
    {
        $antes = Db::um('SELECT * FROM tab_ftth_cabo WHERE id = ? AND excluido_em IS NULL', [$caboId]);
        if (!$antes) {
            return Resultado::erro('FTTH-TOP-002', ['cabo' => $caboId], 'Cabo não encontrado.');
        }

        $tipoId = (int) ($dados['cabo_tipo_id'] ?? $antes['cabo_tipo_id']);
        $tipo = Db::um('SELECT id, rotulo, fibras FROM tab_ftth_cabo_tipo WHERE id = ? AND ativo = 1',
                       [$tipoId]);
        if (!$tipo) {
            return Resultado::erro('FTTH-SYS-002', ['campo' => 'cabo_tipo_id'],
                'Escolha a capacidade do cabo.');
        }

        $trocouCor = false;

        // Encolher o cabo é legítimo, mas não pode deixar uma fusão apontando para uma fibra
        // que deixou de existir -- mesma regra do DIO e do splitter.
        if ((int) $tipo['fibras'] < (int) self::fibrasDoCabo($caboId)) {
            $acima = (int) Db::valor(
                'SELECT COUNT(*) FROM tab_ftth_ligacao_ponta p
                   JOIN tab_ftth_cabo_vao v ON v.id = p.elemento_id
                  WHERE p.elemento = "VAO_FIBRA" AND v.cabo_id = ? AND p.numero > ?',
                [$caboId, (int) $tipo['fibras']]);
            if ($acima > 0) {
                return Resultado::erro('FTTH-TOP-003', ['fibras' => $acima, 'nova' => $tipo['fibras']],
                    'Há ' . $acima . ' fibra(s) ligada(s) acima da fibra ' . $tipo['fibras']
                    . '. Desconecte antes de reduzir a capacidade.');
            }
        }

        $padrao = in_array($dados['padrao_cores'] ?? '', self::PADROES_COR, true)
                ? (string) $dados['padrao_cores'] : (string) $antes['padrao_cores'];
        if ($padrao !== $antes['padrao_cores']) {
            // Não impede nada: cor é leitura da norma, não topologia. Mas quem estiver com o
            // diagrama aberto vai ver as fibras trocarem de cor, e é justo avisar.
            $trocouCor = true;
        }

        $cor = preg_match('/^#[0-9A-Fa-f]{6}$/', (string) ($dados['cor_rota'] ?? ''))
             ? (string) $dados['cor_rota'] : (string) $antes['cor_rota'];

        $status = in_array($dados['status'] ?? '', self::STATUS, true)
                ? (string) $dados['status'] : (string) $antes['status'];

        $nome = array_key_exists('nome', $dados)
              ? (trim((string) $dados['nome']) ?: null) : $antes['nome'];
        $fabricante = array_key_exists('fabricante', $dados)
                    ? (trim((string) $dados['fabricante']) ?: null) : $antes['fabricante'];

        if ($nome !== null && mb_strlen($nome) > 80) {
            return Resultado::erro('FTTH-SYS-002', ['campo' => 'nome'], 'Nome de até 80 caracteres.');
        }
        if ($fabricante !== null && mb_strlen($fabricante) > 60) {
            return Resultado::erro('FTTH-SYS-002', ['campo' => 'fabricante'],
                'Fabricante de até 60 caracteres.');
        }

        return Db::transacao(function () use ($caboId, $antes, $tipo, $padrao, $cor, $status,
                                              $nome, $fabricante, $versao, $usuario, $trocouCor) {
            if (!Versao::avancar('cabo', $caboId, $versao, $usuario)) {
                return Resultado::erro('FTTH-CONC-001', ['entidade' => 'cabo', 'id' => $caboId]);
            }
            Db::exec(
                'UPDATE tab_ftth_cabo
                    SET nome = ?, fabricante = ?, cabo_tipo_id = ?, padrao_cores = ?,
                        cor_rota = ?, status = ?
                  WHERE id = ?',
                [$nome, $fabricante, $tipo['id'], $padrao, $cor, $status, $caboId]
            );
            $depois = Db::um('SELECT * FROM tab_ftth_cabo WHERE id = ?', [$caboId]);
            Auditoria::registrar('cabo', $caboId, 'alterar', $antes, $depois, (int) $antes['regiao_id']);

            $r = Resultado::ok([
                'id'     => $caboId,
                'tipo'   => $tipo['rotulo'],
                'fibras' => (int) $tipo['fibras'],
                'versao' => (int) $antes['versao'] + 1,
            ]);
            return $trocouCor
                ? $r->addAviso('FTTH-TOP-019', ['padrao' => $padrao])
                : $r;
        });
    }

    /**
     * Redesenha o traçado de um vão, sem mexer nas caixas das pontas.
     *
     * As pontas NÃO vêm do cliente: são reescritas com a coordenada atual das caixas. É o
     * que impede o cabo de descolar quando cabo e caixa são movidos na mesma edição — e
     * também impede um POST adulterado de pendurar o cabo no vazio.
     *
     * @param array $vertices [[lat,lng], ...], com ao menos dois pontos
     */
    public static function moverVertices(int $vaoId, array $vertices, ?int $versao,
                                         string $usuario): Resultado
    {
        $vao = Db::um(
            'SELECT v.*, ci.lat AS ini_lat, ci.lng AS ini_lng, cf.lat AS fim_lat, cf.lng AS fim_lng
               FROM tab_ftth_cabo_vao v
               JOIN tab_ftth_caixa ci ON ci.id = v.caixa_ini_id
               JOIN tab_ftth_caixa cf ON cf.id = v.caixa_fim_id
              WHERE v.id = ? AND v.excluido_em IS NULL', [$vaoId]);
        if (!$vao) {
            return Resultado::erro('FTTH-TOP-002', ['vao' => $vaoId]);
        }

        $pontos = [];
        foreach ($vertices as $p) {
            if (!is_array($p) || count($p) < 2) {
                continue;
            }
            $lat = (float) $p[0];
            $lng = (float) $p[1];
            if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
                return Resultado::erro('FTTH-GEO-002', ['lat' => $lat, 'lng' => $lng]);
            }
            $pontos[] = [$lat, $lng];
        }
        if (count($pontos) < 2) {
            return Resultado::erro('FTTH-GEO-001', ['vao' => $vaoId],
                'O cabo precisa de ao menos dois pontos.');
        }

        // As pontas são das caixas, não do desenho.
        $pontos[0] = [(float) $vao['ini_lat'], (float) $vao['ini_lng']];
        $pontos[count($pontos) - 1] = [(float) $vao['fim_lat'], (float) $vao['fim_lng']];

        if (!Geo::rotaValida($pontos)) {
            return Resultado::erro('FTTH-GEO-001', ['vao' => $vaoId], 'Traçado inválido.');
        }

        $metros = Geo::comprimento($pontos);
        $fator  = (float) ($vao['fator_folga'] ?: Config::num('fator_folga_cabo', 1.03));
        $optico = Geo::comprimentoOptico($metros, $fator, (float) $vao['reserva_m']);

        return Db::transacao(function () use ($vaoId, $vao, $pontos, $metros, $optico,
                                              $versao, $usuario) {
            if (!Versao::avancar('vao', $vaoId, $versao, $usuario)) {
                return Resultado::erro('FTTH-CONC-001', ['entidade' => 'vao', 'id' => $vaoId]);
            }
            Db::exec(
                'UPDATE tab_ftth_cabo_vao
                    SET vertices = ?, comprimento_geo = ?, comprimento_optico = ?
                  WHERE id = ?',
                [json_encode($pontos), round($metros, 2), $optico, $vaoId]
            );
            // O comprimento alimenta o cálculo de potência: mudar a rota muda o dBm que
            // chega na ponta, e o histórico precisa deixar isso explícito.
            Auditoria::registrar('vao', $vaoId, 'mover',
                ['vertices' => count(json_decode((string) $vao['vertices'], true) ?: []),
                 'metros'   => (float) $vao['comprimento_geo'],
                 'optico'   => (float) $vao['comprimento_optico']],
                ['vertices' => count($pontos), 'metros' => round($metros, 2), 'optico' => $optico],
                (int) $vao['regiao_id']);

            return Resultado::ok(['id' => $vaoId, 'vertices' => $pontos,
                                  'metros' => round($metros, 2), 'optico' => $optico]);
        });
    }

    /**
     * "Há 3 fibras deste cabo ligadas, em CTO.18.10 (2) e CTO.18.11 (1)."
     *
     * A caixa entra na frase de propósito: sem ela o usuário sabe que não pode excluir,
     * mas não sabe onde ir desfazer.
     */
    private static function textoPresas(array $presas, string $oQue): string
    {
        $onde = array_map(static function ($p) {
            return $p['nome'] . ' (' . (int) $p['fibras'] . ')';
        }, $presas);

        $lista = count($onde) > 1
               ? implode(', ', array_slice($onde, 0, -1)) . ' e ' . end($onde)
               : $onde[0];

        $total = array_sum(array_column($presas, 'fibras'));

        return 'Há ' . $total . ' fibra' . ($total > 1 ? 's' : '') . ' ' . $oQue . ' ligada'
             . ($total > 1 ? 's' : '') . ', em ' . $lista
             . '. Desconecte no diagrama dessa(s) caixa(s) antes de excluir.';
    }

    /** Quantas fibras o cabo tem hoje, pelo tipo em que está cadastrado. */
    private static function fibrasDoCabo(int $caboId): int
    {
        return (int) Db::valor(
            'SELECT t.fibras FROM tab_ftth_cabo c
               JOIN tab_ftth_cabo_tipo t ON t.id = c.cabo_tipo_id
              WHERE c.id = ?', [$caboId]);
    }

    /** Exclui o cabo inteiro (todos os vãos) — recusa se alguma fibra estiver ligada. */
    public static function excluir(int $caboId, string $usuario): Resultado
    {
        $cabo = Db::um('SELECT * FROM tab_ftth_cabo WHERE id = ? AND excluido_em IS NULL', [$caboId]);
        if (!$cabo) {
            return Resultado::erro('FTTH-TOP-002', ['cabo' => $caboId], 'Cabo não encontrado.');
        }

        // Não basta dizer que há fibra ligada: o usuário precisa saber ONDE desconectar,
        // senão tem de abrir o diagrama de cada caixa até achar.
        $presas = Db::todos(
            'SELECT c.nome, COUNT(*) AS fibras
               FROM tab_ftth_ligacao_ponta p
               JOIN tab_ftth_cabo_vao v ON v.id = p.elemento_id
               JOIN tab_ftth_caixa c    ON c.id = p.caixa_id
              WHERE p.elemento = "VAO_FIBRA" AND v.cabo_id = ?
              GROUP BY c.id, c.nome ORDER BY c.nome', [$caboId]);
        if ($presas) {
            return Resultado::erro('FTTH-TOP-016',
                ['caixas' => $presas, 'ligacoes' => array_sum(array_column($presas, 'fibras'))],
                self::textoPresas($presas, 'deste cabo'));
        }

        return Db::transacao(function () use ($caboId, $usuario, $cabo) {
            Db::exec('UPDATE tab_ftth_cabo_vao SET excluido_em = NOW() WHERE cabo_id = ?', [$caboId]);
            Db::exec('UPDATE tab_ftth_cabo SET excluido_em = NOW() WHERE id = ?', [$caboId]);
            Auditoria::registrar('cabo', $caboId, 'excluir', $cabo, null, (int) $cabo['regiao_id']);
            return Resultado::ok(['id' => $caboId]);
        });
    }

    /**
     * Distância em que uma caixa ainda conta como "em cima do cabo".
     * Configurável porque depende do zoom em que o provedor trabalha e da precisão do GPS
     * de quem levantou a planta.
     */
    public static function raioQuebra(): float
    {
        return max(1.0, Config::num('raio_quebra_cabo_m', 10.0));
    }

    /**
     * O vão mais próximo deste ponto, dentro do raio — ou null.
     *
     * É o que responde "você soltou a caixa em cima de um cabo?". Devolve junto a projeção,
     * para a tela poder dizer quantos metros a caixa vai andar antes de o usuário confirmar.
     *
     * @param int|null $ignorarCaixa vãos que encostam nesta caixa não contam (ela é a ponta)
     * @return array{vao:array,projecao:array}|null
     */
    public static function vaoSobPonto(int $regiaoId, float $lat, float $lng,
                                       ?int $ignorarCaixa = null): ?array
    {
        $raio = self::raioQuebra();
        // Caixa grosseira em graus só para não ler a região inteira: 1 grau de latitude tem
        // ~110,5 km, e a longitude encolhe com o cosseno. O filtro fino é a projeção.
        $margemLat = ($raio + 50) / 110540.0;
        $margemLng = $margemLat / max(0.2, cos($lat * M_PI / 180));

        $sql = 'SELECT v.id, v.cabo_id, v.ordem, v.vertices, v.caixa_ini_id, v.caixa_fim_id,
                       v.versao, c.nome AS cabo_nome, t.rotulo AS cabo_tipo, t.fibras
                  FROM tab_ftth_cabo_vao v
                  JOIN tab_ftth_cabo c      ON c.id = v.cabo_id
                  JOIN tab_ftth_cabo_tipo t ON t.id = c.cabo_tipo_id
                 WHERE v.regiao_id = ? AND v.excluido_em IS NULL AND c.excluido_em IS NULL';
        $params = [$regiaoId];
        if ($ignorarCaixa !== null) {
            $sql .= ' AND v.caixa_ini_id <> ? AND v.caixa_fim_id <> ?';
            $params[] = $ignorarCaixa;
            $params[] = $ignorarCaixa;
        }

        $melhor = null;
        foreach (Db::todos($sql, $params) as $v) {
            $vertices = json_decode((string) $v['vertices'], true);
            if (!is_array($vertices) || count($vertices) < 2) {
                continue;
            }
            // Descarta cedo o que está claramente longe, sem projetar nada. O teste é contra a
            // MOLDURA do vão inteiro, não contra cada vértice: num trecho de 300 m em linha
            // reta, o ponto no meio fica a 150 m dos dois vértices e seria descartado — que
            // foi exatamente o que aconteceu no primeiro teste com dados reais.
            $latMin = $latMax = (float) $vertices[0][0];
            $lngMin = $lngMax = (float) $vertices[0][1];
            foreach ($vertices as $p) {
                $latMin = min($latMin, (float) $p[0]);
                $latMax = max($latMax, (float) $p[0]);
                $lngMin = min($lngMin, (float) $p[1]);
                $lngMax = max($lngMax, (float) $p[1]);
            }
            if ($lat < $latMin - $margemLat || $lat > $latMax + $margemLat
                || $lng < $lngMin - $margemLng || $lng > $lngMax + $margemLng) {
                continue;
            }

            $proj = Geo::projetarNaRota($vertices, $lat, $lng);
            if ($proj === null || $proj['distancia_m'] > $raio) {
                continue;
            }
            if ($melhor === null || $proj['distancia_m'] < $melhor['projecao']['distancia_m']) {
                $melhor = ['vao' => $v, 'projecao' => $proj];
            }
        }
        return $melhor;
    }

    /**
     * Quebra um vão em dois, com a caixa no meio — e emenda o cabo nela.
     *
     * É o "documentar o que já está na rua": o cabo foi lançado antes, as caixas vão sendo
     * marcadas depois; e é também o conserto de um rompimento, em que entram duas caixas de
     * emenda e um pedaço novo de cabo.
     *
     * O que acontece, tudo numa transação:
     *   1. a caixa anda até o ponto exato do cabo (o clique nunca cai na linha);
     *   2. o vão original PRESERVA o id e vira o primeiro trecho (A → C). Isso é o que mantém
     *      intactas as fibras já fundidas na caixa A: elas apontam para este id;
     *   3. nasce o segundo trecho (C → B), e as pontas de ligação que estavam na caixa B
     *      passam a apontar para ele — senão continuariam penduradas num vão que agora
     *      termina em outro lugar;
     *   4. comprimento e reserva são divididos na proporção de cada trecho;
     *   5. as fibras passam direto pela caixa nova, uma a uma. Como os dois trechos são do
     *      mesmo cabo e da mesma numeração, a topologia classifica cada ligação como
     *      PASSAGEM, de perda zero — o sinal dos clientes continua batendo no mesmo instante.
     */
    public static function quebrarVao(int $vaoId, int $caixaId, string $usuario): Resultado
    {
        $vao = Db::um(
            'SELECT v.*, c.cabo_tipo_id, c.nome AS cabo_nome
               FROM tab_ftth_cabo_vao v
               JOIN tab_ftth_cabo c ON c.id = v.cabo_id
              WHERE v.id = ? AND v.excluido_em IS NULL', [$vaoId]);
        if (!$vao) {
            return Resultado::erro('FTTH-TOP-002', ['vao' => $vaoId]);
        }

        $caixa = Db::um('SELECT * FROM tab_ftth_caixa WHERE id = ? AND excluido_em IS NULL', [$caixaId]);
        if (!$caixa) {
            return Resultado::erro('FTTH-TOP-001', ['caixa' => $caixaId]);
        }
        if ((int) $caixa['regiao_id'] !== (int) $vao['regiao_id']) {
            return Resultado::erro('FTTH-SYS-002', ['caixa' => $caixaId, 'vao' => $vaoId],
                'A caixa e o cabo são de regiões diferentes.');
        }
        if ((int) $vao['caixa_ini_id'] === $caixaId || (int) $vao['caixa_fim_id'] === $caixaId) {
            return Resultado::erro('FTTH-SYS-002', ['caixa' => $caixaId, 'vao' => $vaoId],
                'Esta caixa já é uma das pontas deste cabo.');
        }

        $vertices = json_decode((string) $vao['vertices'], true);
        if (!is_array($vertices) || count($vertices) < 2) {
            return Resultado::erro('FTTH-GEO-001', ['vao' => $vaoId], 'O cabo não tem traçado válido.');
        }

        $proj = Geo::projetarNaRota($vertices, (float) $caixa['lat'], (float) $caixa['lng']);
        if ($proj === null) {
            return Resultado::erro('FTTH-GEO-001', ['vao' => $vaoId], 'Não consegui projetar a caixa no cabo.');
        }
        if ($proj['distancia_m'] > self::raioQuebra()) {
            return Resultado::erro('FTTH-SYS-002',
                ['distancia_m' => $proj['distancia_m'], 'raio_m' => self::raioQuebra()],
                'A caixa está a ' . number_format($proj['distancia_m'], 1, ',', '.') .
                ' m do cabo — mais que o limite para emendar.');
        }
        if (count($proj['antes']) < 2 || count($proj['depois']) < 2) {
            return Resultado::erro('FTTH-GEO-001', ['vao' => $vaoId],
                'A caixa caiu exatamente sobre uma das pontas; mova-a um pouco para o meio do cabo.');
        }

        return Db::transacao(function () use ($vao, $vaoId, $caixa, $caixaId, $proj, $usuario) {
            $caixaFim = (int) $vao['caixa_fim_id'];
            $folga    = (float) ($vao['fator_folga'] ?: Config::num('fator_folga_cabo', 1.03));

            // 1. a caixa encosta no cabo
            $moveu = 0.0;
            if ($proj['distancia_m'] > 0.01) {
                $moveu = $proj['distancia_m'];
                // Caixa::mover cuida de versão, auditoria e das pontas dos outros cabos que
                // já encostam nesta caixa — elas acompanham o novo ponto.
                Caixa::mover($caixaId, (float) $proj['lat'], (float) $proj['lng'], null, $usuario);
            }

            $geoA = Geo::comprimento($proj['antes']);
            $geoB = Geo::comprimento($proj['depois']);
            $total = $geoA + $geoB;

            // Reserva técnica dividida na proporção de cada trecho: o rolo de sobra estava
            // distribuído no vão inteiro, não numa ponta só.
            $reserva  = (float) $vao['reserva_m'];
            $reservaA = $total > 0 ? round($reserva * ($geoA / $total), 2) : $reserva;
            $reservaB = round($reserva - $reservaA, 2);

            // 2. o vão original vira o trecho A -> C, mantendo o id
            Db::exec(
                'UPDATE tab_ftth_cabo_vao
                    SET caixa_fim_id = ?, vertices = ?, comprimento_geo = ?, reserva_m = ?,
                        comprimento_optico = ?, versao = versao + 1, alterado_por = ?, alterado_em = NOW()
                  WHERE id = ?',
                [$caixaId, json_encode($proj['antes']), round($geoA, 2), $reservaA,
                 Geo::comprimentoOptico($geoA, $folga, $reservaA), $usuario, $vaoId]
            );

            // 3. nasce o trecho C -> B, logo depois do original na ordem do cabo
            Db::exec(
                'UPDATE tab_ftth_cabo_vao SET ordem = ordem + 1
                  WHERE cabo_id = ? AND ordem > ? AND excluido_em IS NULL',
                [(int) $vao['cabo_id'], (int) $vao['ordem']]
            );
            Db::exec(
                'INSERT INTO tab_ftth_cabo_vao
                    (cabo_id, regiao_id, ordem, caixa_ini_id, caixa_fim_id, vertices,
                     comprimento_geo, fator_folga, reserva_m, comprimento_optico,
                     origem, importacao_id, criado_por, criado_em)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())',
                [(int) $vao['cabo_id'], (int) $vao['regiao_id'], (int) $vao['ordem'] + 1,
                 $caixaId, $caixaFim, json_encode($proj['depois']),
                 round($geoB, 2), $folga, $reservaB, Geo::comprimentoOptico($geoB, $folga, $reservaB),
                 $vao['origem'], $vao['importacao_id'], $usuario]
            );
            $novoId = Db::ultimoId();

            // 4. o que já estava fundido na caixa do fim agora pertence ao trecho novo
            $migradas = Db::exec(
                'UPDATE tab_ftth_ligacao_ponta SET elemento_id = ?
                  WHERE caixa_id = ? AND elemento = "VAO_FIBRA" AND elemento_id = ?',
                [$novoId, $caixaFim, $vaoId]
            );

            // 5. as fibras atravessam a caixa nova
            $passagens = Topologia::ligarCabos($caixaId, $vaoId, $novoId, true, $usuario);
            $ligadas = (int) ($passagens->data['ligadas'] ?? 0);

            // 6. e o diagrama já abre legível: o cabo que vem do POP à esquerda, o que segue
            // para a rua à direita e espelhado, com as fibras de frente umas para as outras.
            Topologia::organizarEmenda($caixaId, $vaoId, $novoId, $usuario);

            Auditoria::registrar('vao', $vaoId, 'quebrar',
                ['caixa_fim_id' => $caixaFim, 'comprimento_geo' => (float) $vao['comprimento_geo']],
                ['caixa' => $caixaId, 'trecho_novo' => $novoId,
                 'metros_a' => round($geoA, 2), 'metros_b' => round($geoB, 2),
                 'fibras_passando' => $ligadas, 'pontas_migradas' => $migradas],
                (int) $vao['regiao_id']);

            return Resultado::ok([
                'vao'            => $vaoId,
                'vao_novo'       => $novoId,
                'caixa'          => $caixaId,
                'cabo'           => (int) $vao['cabo_id'],
                'metros_a'       => round($geoA, 2),
                'metros_b'       => round($geoB, 2),
                'caixa_moveu_m'  => round($moveu, 2),
                'fibras_passando' => $ligadas,
                'pontas_migradas' => $migradas,
            ]);
        });
    }

    /**
     * Esta caixa é só uma emenda no meio de um cabo? Se for, descreve o que a união faria.
     *
     * É o inverso da quebra: a caixa entrou em cima de um cabo e agora sai — o cabo volta a
     * ser um lance só. Para isso valer, a caixa não pode ter função nenhuma além de deixar as
     * fibras passarem:
     *
     *   - exatamente dois trechos ativos, e do MESMO cabo;
     *   - nenhum splitter (se tem splitter, a caixa distribui, não só passa);
     *   - toda ligação daqui é fibra N de um trecho com a fibra N do outro. Uma fusão cruzada
     *     (fibra 1 com a 5) é informação que o cabo unido não teria como guardar, e some se
     *     alguém juntar por cima — então nesse caso a união não é oferecida.
     *
     * @return array{vao_a:int,vao_b:int,cabo:int,cabo_nome:?string,metros:float,ligacoes:int,
     *               caixa_ini:int,caixa_fim:int,nome_ini:string,nome_fim:string}|null
     */
    public static function emendaSimples(int $caixaId): ?array
    {
        $vaos = Db::todos(
            'SELECT v.id, v.cabo_id, v.ordem, v.caixa_ini_id, v.caixa_fim_id, v.comprimento_geo,
                    v.reserva_m, c.nome AS cabo_nome
               FROM tab_ftth_cabo_vao v
               JOIN tab_ftth_cabo c ON c.id = v.cabo_id
              WHERE (v.caixa_ini_id = ? OR v.caixa_fim_id = ?) AND v.excluido_em IS NULL
              ORDER BY v.ordem', [$caixaId, $caixaId]);

        if (count($vaos) !== 2 || (int) $vaos[0]['cabo_id'] !== (int) $vaos[1]['cabo_id']) {
            return null;
        }

        $temSplitter = (int) Db::valor(
            'SELECT COUNT(*) FROM tab_ftth_splitter WHERE caixa_id = ? AND excluido_em IS NULL', [$caixaId]);
        $temDio = (int) Db::valor(
            'SELECT COUNT(*) FROM tab_ftth_dio WHERE caixa_id = ? AND excluido_em IS NULL', [$caixaId]);
        if ($temSplitter > 0 || $temDio > 0) {
            return null;
        }

        $a = (int) $vaos[0]['id'];
        $b = (int) $vaos[1]['id'];

        // Toda ligação da caixa tem de ser "fibra N de um trecho com a fibra N do outro".
        $ligacoes = Db::todos(
            'SELECT l.id,
                    MAX(CASE WHEN p.lado = "A" THEN p.elemento END) AS elem_a,
                    MAX(CASE WHEN p.lado = "A" THEN p.elemento_id END) AS id_a,
                    MAX(CASE WHEN p.lado = "A" THEN p.numero END) AS num_a,
                    MAX(CASE WHEN p.lado = "B" THEN p.elemento END) AS elem_b,
                    MAX(CASE WHEN p.lado = "B" THEN p.elemento_id END) AS id_b,
                    MAX(CASE WHEN p.lado = "B" THEN p.numero END) AS num_b
               FROM tab_ftth_ligacao l
               JOIN tab_ftth_ligacao_ponta p ON p.ligacao_id = l.id
              WHERE l.caixa_id = ?
              GROUP BY l.id', [$caixaId]);

        foreach ($ligacoes as $l) {
            if ($l['elem_a'] !== 'VAO_FIBRA' || $l['elem_b'] !== 'VAO_FIBRA') {
                return null;
            }
            $ids = [(int) $l['id_a'], (int) $l['id_b']];
            sort($ids);
            if ($ids !== [min($a, $b), max($a, $b)]) {
                return null;
            }
            if ((int) $l['num_a'] !== (int) $l['num_b']) {
                return null;   // fusão cruzada: a união apagaria a informação
            }
        }

        $pontas = self::pontasDaUniao($caixaId, $vaos[0], $vaos[1]);
        $nomes  = Db::um('SELECT
                            (SELECT nome FROM tab_ftth_caixa WHERE id = ?) AS ini,
                            (SELECT nome FROM tab_ftth_caixa WHERE id = ?) AS fim',
                         [$pontas['ini'], $pontas['fim']]);

        return [
            'vao_a'     => $a,
            'vao_b'     => $b,
            'cabo'      => (int) $vaos[0]['cabo_id'],
            'cabo_nome' => $vaos[0]['cabo_nome'],
            'metros'    => round((float) $vaos[0]['comprimento_geo'] + (float) $vaos[1]['comprimento_geo'], 2),
            'ligacoes'  => count($ligacoes),
            'caixa_ini' => $pontas['ini'],
            'caixa_fim' => $pontas['fim'],
            'nome_ini'  => (string) ($nomes['ini'] ?? ''),
            'nome_fim'  => (string) ($nomes['fim'] ?? ''),
        ];
    }

    /** As duas pontas que sobram quando a caixa do meio sai. */
    private static function pontasDaUniao(int $caixaId, array $v1, array $v2): array
    {
        $outra = static function (array $v) use ($caixaId): int {
            return (int) $v['caixa_ini_id'] === $caixaId ? (int) $v['caixa_fim_id'] : (int) $v['caixa_ini_id'];
        };
        return ['ini' => $outra($v1), 'fim' => $outra($v2)];
    }

    /**
     * Junta os dois trechos num só e apaga a caixa do meio.
     *
     * O trecho de menor ordem sobrevive com o id: é ele que as fibras da ponta de origem já
     * referenciam. O outro é encerrado, e o que estava fundido na ponta dele migra — o mesmo
     * cuidado da quebra, no sentido contrário.
     *
     * A exclusão da caixa continua passando por Caixa::excluir, que é quem sabe recusar. Aqui
     * só tiramos o que prendia a caixa; se sobrar qualquer coisa, ele recusa e a transação
     * inteira volta atrás.
     */
    public static function unirVaos(int $caixaId, ?int $versaoCaixa, string $usuario): Resultado
    {
        $emenda = self::emendaSimples($caixaId);
        if ($emenda === null) {
            return Resultado::erro('FTTH-TOP-016', ['caixa' => $caixaId],
                'Esta caixa não é uma emenda simples entre dois trechos do mesmo cabo.');
        }

        $v1 = Db::um('SELECT * FROM tab_ftth_cabo_vao WHERE id = ?', [$emenda['vao_a']]);
        $v2 = Db::um('SELECT * FROM tab_ftth_cabo_vao WHERE id = ?', [$emenda['vao_b']]);
        if (!$v1 || !$v2) {
            return Resultado::erro('FTTH-TOP-002', ['caixa' => $caixaId]);
        }

        // A geometria é lida no sentido A -> caixa -> B, invertendo o que estiver ao contrário.
        $g1 = json_decode((string) $v1['vertices'], true);
        $g2 = json_decode((string) $v2['vertices'], true);
        if (!is_array($g1) || !is_array($g2) || count($g1) < 2 || count($g2) < 2) {
            return Resultado::erro('FTTH-GEO-001', ['caixa' => $caixaId], 'Traçado inválido nos trechos.');
        }
        if ((int) $v1['caixa_ini_id'] === $caixaId) { $g1 = array_reverse($g1); }
        if ((int) $v2['caixa_fim_id'] === $caixaId) { $g2 = array_reverse($g2); }

        $vertices = array_merge($g1, array_slice($g2, 1));   // o ponto da caixa entra uma vez só
        if (!Geo::rotaValida($vertices)) {
            return Resultado::erro('FTTH-GEO-001', ['caixa' => $caixaId], 'Traçado inválido na união.');
        }

        return Db::transacao(function () use ($caixaId, $versaoCaixa, $usuario, $emenda,
                                              $v1, $v2, $vertices) {
            $fimNovo = $emenda['caixa_fim'];
            $iniNovo = $emenda['caixa_ini'];
            $folga   = (float) ($v1['fator_folga'] ?: Config::num('fator_folga_cabo', 1.03));
            $reserva = (float) $v1['reserva_m'] + (float) $v2['reserva_m'];
            $metros  = Geo::comprimento($vertices);

            // As passagens desta caixa deixam de existir junto com ela (as pontas caem por CASCADE).
            Db::exec('DELETE FROM tab_ftth_ligacao WHERE caixa_id = ?', [$caixaId]);
            Db::exec('DELETE FROM tab_ftth_diagrama_no WHERE caixa_id = ?', [$caixaId]);

            // O que estava fundido na ponta do segundo trecho passa a ser do primeiro.
            $migradas = Db::exec(
                'UPDATE tab_ftth_ligacao_ponta SET elemento_id = ?
                  WHERE caixa_id = ? AND elemento = "VAO_FIBRA" AND elemento_id = ?',
                [(int) $v1['id'], $fimNovo, (int) $v2['id']]
            );

            Db::exec(
                'UPDATE tab_ftth_cabo_vao
                    SET caixa_ini_id = ?, caixa_fim_id = ?, vertices = ?, comprimento_geo = ?,
                        reserva_m = ?, comprimento_optico = ?, versao = versao + 1,
                        alterado_por = ?, alterado_em = NOW()
                  WHERE id = ?',
                [$iniNovo, $fimNovo, json_encode($vertices), round($metros, 2), $reserva,
                 Geo::comprimentoOptico($metros, $folga, $reserva), $usuario, (int) $v1['id']]
            );

            Db::exec('UPDATE tab_ftth_cabo_vao SET excluido_em = NOW(), alterado_por = ?
                       WHERE id = ?', [$usuario, (int) $v2['id']]);
            Db::exec('UPDATE tab_ftth_cabo_vao SET ordem = ordem - 1
                       WHERE cabo_id = ? AND ordem > ? AND excluido_em IS NULL',
                     [(int) $v2['cabo_id'], (int) $v2['ordem']]);

            // Agora nada mais prende a caixa: quem valida e apaga é o serviço de sempre.
            $exc = Caixa::excluir($caixaId, $versaoCaixa, $usuario);
            if (!$exc->ok) {
                throw new RuntimeException('nao foi possivel excluir a caixa depois de unir os trechos');
            }

            Auditoria::registrar('vao', (int) $v1['id'], 'unir',
                ['trecho_removido' => (int) $v2['id'], 'caixa_removida' => $caixaId],
                ['metros' => round($metros, 2), 'pontas_migradas' => $migradas,
                 'ligacoes_desfeitas' => $emenda['ligacoes']],
                (int) $v1['regiao_id']);

            return Resultado::ok([
                'caixa'              => $caixaId,
                'vao'                => (int) $v1['id'],
                'vao_removido'       => (int) $v2['id'],
                'metros'             => round($metros, 2),
                'ligacoes_desfeitas' => $emenda['ligacoes'],
                'pontas_migradas'    => $migradas,
                'nome_ini'           => $emenda['nome_ini'],
                'nome_fim'           => $emenda['nome_fim'],
            ]);
        });
    }
}
