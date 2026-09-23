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
}
