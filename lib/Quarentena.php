<?php
/**
 * ftth_doc :: promocao de itens da quarentena para a rede real.
 *
 * Esta e a unica porta entre "o que veio do arquivo" e "o que e a rede". Regras:
 *  - um VAO so entra depois das duas caixas das pontas (FTTH-KMZ-004);
 *  - item ja decidido nao volta atras (FTTH-KMZ-005);
 *  - lote e tudo-ou-nada (3b.7): uma falha desfaz a operacao inteira;
 *  - itens com alerta ficam de fora do lote, salvo pedido explicito;
 *  - cada promocao deixa rastro: o item guarda o id gerado, e a auditoria guarda o resto.
 */
require_once __DIR__ . '/Db.php';
require_once __DIR__ . '/Geo.php';
require_once __DIR__ . '/Config.php';
require_once __DIR__ . '/Auditoria.php';
require_once __DIR__ . '/Resultado.php';

final class Quarentena
{
    public static function listar(int $regiaoId, string $status = 'pendente', int $limite = 500): array
    {
        return Db::todos(
            'SELECT * FROM tab_ftth_importacao_item
              WHERE regiao_id = ? AND status = ?
              ORDER BY tipo_sugerido, id
              LIMIT ' . (int) $limite,
            [$regiaoId, $status]
        );
    }

    public static function contar(int $regiaoId): array
    {
        $r = Db::um(
            'SELECT
                SUM(status = "pendente")   AS pendentes,
                SUM(status = "importado")  AS importados,
                SUM(status = "descartado") AS descartados,
                SUM(status = "pendente" AND alertas_json IS NOT NULL) AS com_alerta
             FROM tab_ftth_importacao_item WHERE regiao_id = ?',
            [$regiaoId]
        ) ?: [];
        return array_map('intval', $r);
    }

    /** Importa um item. Usado tambem pelo lote. */
    public static function importarItem(int $itemId, string $usuario, array $ajustes = []): Resultado
    {
        return Db::transacao(function () use ($itemId, $usuario, $ajustes) {
            $item = Db::um('SELECT * FROM tab_ftth_importacao_item WHERE id = ? FOR UPDATE', [$itemId]);
            if (!$item || $item['status'] !== 'pendente') {
                return Resultado::erro('FTTH-KMZ-005', ['item' => $itemId]);
            }

            $geo = json_decode((string) $item['geometria'], true);
            if (!is_array($geo) || !Geo::rotaValida($geo) && $item['tipo_sugerido'] === 'VAO') {
                return Resultado::erro('FTTH-GEO-001', ['item' => $itemId]);
            }

            return $item['tipo_sugerido'] === 'CAIXA'
                ? self::criarCaixa($item, $geo, $usuario, $ajustes)
                : self::criarVao($item, $geo, $usuario, $ajustes);
        });
    }

    /**
     * Importa varios de uma vez: caixas primeiro, depois os vaos cujas duas ancoras ja existem.
     * Tudo numa transacao — se um falhar, nenhum entra.
     *
     * @param int[] $ids
     */
    public static function importarLote(array $ids, string $usuario, bool $incluirComAlerta = false): Resultado
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if (!$ids) {
            return Resultado::erro('FTTH-SYS-002', [], 'Nenhum item selecionado.');
        }

        return Db::transacao(function () use ($ids, $usuario, $incluirComAlerta) {
            $marcas = implode(',', array_fill(0, count($ids), '?'));
            $itens = Db::todos(
                "SELECT * FROM tab_ftth_importacao_item
                  WHERE id IN ($marcas) AND status = 'pendente'
                  ORDER BY FIELD(tipo_sugerido, 'CAIXA', 'VAO'), id", $ids);

            $importados = [];
            $pulados    = [];

            foreach ($itens as $item) {
                if (!$incluirComAlerta && $item['alertas_json'] !== null) {
                    $pulados[] = ['id' => (int) $item['id'], 'motivo' => 'item com alerta'];
                    continue;
                }
                $r = self::importarItem((int) $item['id'], $usuario);
                if ($r->ok) {
                    $importados[] = ['id' => (int) $item['id'], 'gerado' => $r->data];
                } else {
                    $pulados[] = ['id' => (int) $item['id'], 'motivo' => $r->primeiroCodigo()];
                }
            }

            Auditoria::registrar('importacao_lote', count($ids), 'importar_lote', null,
                ['importados' => count($importados), 'pulados' => count($pulados)],
                isset($itens[0]) ? (int) $itens[0]['regiao_id'] : null);

            return Resultado::ok(['importados' => $importados, 'pulados' => $pulados]);
        });
    }

    public static function descartar(array $ids, string $usuario, string $motivo = ''): Resultado
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if (!$ids) {
            return Resultado::erro('FTTH-SYS-002', [], 'Nenhum item selecionado.');
        }
        return Db::transacao(function () use ($ids, $usuario, $motivo) {
            $marcas = implode(',', array_fill(0, count($ids), '?'));
            $n = Db::exec(
                "UPDATE tab_ftth_importacao_item
                    SET status = 'descartado', decidido_por = ?, decidido_em = NOW()
                  WHERE id IN ($marcas) AND status = 'pendente'",
                array_merge([$usuario], $ids));
            Auditoria::registrar('importacao_item', $ids[0], 'descartar', null,
                ['quantidade' => $n, 'motivo' => $motivo]);
            self::recontar($ids);
            return Resultado::ok(['descartados' => $n]);
        });
    }

    // ------------------------------------------------------------------ interno

    private static function criarCaixa(array $item, array $geo, string $usuario, array $ajustes): Resultado
    {
        $nome = trim((string) ($ajustes['nome'] ?? $item['nome'] ?? ''));
        if ($nome === '') {
            return Resultado::erro('FTTH-SYS-002', ['item' => $item['id']], 'A caixa precisa de um nome.');
        }
        $tipo = (string) ($ajustes['tipo'] ?? $item['subtipo'] ?? 'CTO');

        $jaExiste = Db::valor('SELECT id FROM tab_ftth_caixa WHERE regiao_id = ? AND nome = ?',
            [$item['regiao_id'], $nome]);
        if ($jaExiste) {
            return Resultado::erro('FTTH-KMZ-003', ['nome' => $nome, 'caixa_id' => (int) $jaExiste],
                'Já existe uma caixa com esse nome na região.');
        }

        Db::exec(
            'INSERT INTO tab_ftth_caixa
                (regiao_id, tipo, nome, cor, lat, lng, capacidade, origem, importacao_id, criado_por, criado_em)
             VALUES (?,?,?,?,?,?,?,"kmz",?,?,NOW())',
            [$item['regiao_id'], $tipo, $nome, $ajustes['cor'] ?? $item['cor'] ?? '#00C853',
             $geo[0][0], $geo[0][1], $ajustes['capacidade'] ?? null,
             $item['importacao_id'], $usuario]
        );
        $caixaId = Db::ultimoId();

        self::marcarImportado((int) $item['id'], 'CAIXA', $caixaId, $usuario);
        Auditoria::registrar('caixa', $caixaId, 'importar_kmz', null,
            ['nome' => $nome, 'tipo' => $tipo, 'item' => (int) $item['id']], (int) $item['regiao_id']);

        return Resultado::ok(['tipo' => 'CAIXA', 'id' => $caixaId, 'nome' => $nome]);
    }

    private static function criarVao(array $item, array $geo, string $usuario, array $ajustes): Resultado
    {
        // Resolve as ancoras: caixa real direta, ou o item de quarentena ja importado.
        $ini = self::resolverAncora($item['ancora_ini_id'], $item['ancora_ini_item_id']);
        $fim = self::resolverAncora($item['ancora_fim_id'], $item['ancora_fim_item_id']);

        if ($ini === null || $fim === null) {
            return Resultado::erro('FTTH-KMZ-004', [
                'item' => (int) $item['id'],
                'falta' => $ini === null ? 'caixa inicial' : 'caixa final',
            ]);
        }
        if ($ini === $fim) {
            return Resultado::erro('FTTH-GEO-003', ['item' => (int) $item['id'], 'caixa' => $ini]);
        }

        $rotulo = (string) ($ajustes['cabo_tipo'] ?? $item['subtipo'] ?? '6 FO');
        $tipo   = self::tipoDeCabo($rotulo);
        $tipoId = $tipo['id'];
        if (!$tipoId) {
            return Resultado::erro('FTTH-SYS-002', ['rotulo' => $rotulo], 'Tipo de cabo não encontrado.');
        }

        // Um cabo por vao importado: o agrupamento em cabo logico e decisao humana, depois.
        Db::exec(
            'INSERT INTO tab_ftth_cabo
                (regiao_id, nome, cabo_tipo_id, cor_rota, origem, importacao_id, criado_por, criado_em)
             VALUES (?,?,?,?,"kmz",?,?,NOW())',
            [$item['regiao_id'], $item['nome'], $tipoId, $item['cor'] ?? '#00E676',
             $item['importacao_id'], $usuario]
        );
        $caboId = Db::ultimoId();

        $metros = Geo::comprimento($geo);
        $folga  = Config::num('fator_folga_cabo', 1.03);

        Db::exec(
            'INSERT INTO tab_ftth_cabo_vao
                (cabo_id, regiao_id, ordem, caixa_ini_id, caixa_fim_id, vertices,
                 comprimento_geo, fator_folga, reserva_m, comprimento_optico,
                 origem, importacao_id, criado_por, criado_em)
             VALUES (?,?,?,?,?,?,?,?,0,?,"kmz",?,?,NOW())',
            [$caboId, $item['regiao_id'], 1, $ini, $fim, json_encode($geo),
             round($metros, 2), $folga, Geo::comprimentoOptico($metros, $folga, 0),
             $item['importacao_id'], $usuario]
        );
        $vaoId = Db::ultimoId();

        self::marcarImportado((int) $item['id'], 'VAO', $vaoId, $usuario);
        Auditoria::registrar('vao', $vaoId, 'importar_kmz', null,
            ['cabo' => $caboId, 'caixa_ini' => $ini, 'caixa_fim' => $fim, 'metros' => round($metros, 2)],
            (int) $item['regiao_id']);

        $r = Resultado::ok(['tipo' => 'VAO', 'id' => $vaoId, 'cabo_id' => $caboId,
                            'caixa_ini_id' => $ini, 'caixa_fim_id' => $fim,
                            'cabo_tipo' => $tipo['rotulo']]);

        // Cair no padrão em silêncio foi o que fez sete cabos de 24 FO entrarem como 6 FO:
        // dezoito fibras por cabo que simplesmente não existiam no diagrama, e ninguém
        // tinha como saber. Agora a importação diz o que não conseguiu reconhecer.
        return $tipo['adivinhado']
            ? $r->addAviso('FTTH-KMZ-006', ['lido' => $rotulo, 'usado' => $tipo['rotulo']])
            : $r;
    }

    /**
     * Acha o tipo de cabo pelo rótulo do KMZ.
     *
     * O casamento não pode ser só pelo texto exato: o KMZ escreve "Cabo 24FO", e o catálogo
     * tem "24 FO (Monotubo)" e "24 FO MULT (4x6)" — nenhum dos dois casa com "24 FO". Então,
     * falhando o texto, vale a CAPACIDADE: procura um tipo com o mesmo número de fibras,
     * preferindo o monotubo, porque o KMZ não diz nada sobre tubagem.
     *
     * @return array{id:?int, rotulo:string, adivinhado:bool}
     */
    private static function tipoDeCabo(string $rotulo): array
    {
        $exato = Db::um('SELECT id, rotulo FROM tab_ftth_cabo_tipo WHERE rotulo = ? AND ativo = 1',
                        [$rotulo]);
        if ($exato) {
            return ['id' => (int) $exato['id'], 'rotulo' => $exato['rotulo'], 'adivinhado' => false];
        }

        if (preg_match('/(\d+)/', $rotulo, $m)) {
            $porFibras = Db::um(
                'SELECT id, rotulo FROM tab_ftth_cabo_tipo
                  WHERE fibras = ? AND ativo = 1
                  ORDER BY tubos ASC, ordem ASC LIMIT 1', [(int) $m[1]]);
            if ($porFibras) {
                return ['id' => (int) $porFibras['id'], 'rotulo' => $porFibras['rotulo'],
                        'adivinhado' => false];
            }
        }

        $padrao = Db::um('SELECT id, rotulo FROM tab_ftth_cabo_tipo WHERE rotulo = "6 FO"');
        return ['id' => $padrao ? (int) $padrao['id'] : null,
                'rotulo' => $padrao['rotulo'] ?? '6 FO', 'adivinhado' => true];
    }

    /** Caixa real informada, ou a caixa que nasceu do item de quarentena indicado. */
    private static function resolverAncora($caixaId, $itemId): ?int
    {
        if ($caixaId) {
            $existe = Db::valor('SELECT id FROM tab_ftth_caixa WHERE id = ? AND excluido_em IS NULL', [$caixaId]);
            return $existe ? (int) $existe : null;
        }
        if ($itemId) {
            $gerado = Db::um(
                'SELECT gerado_tipo, gerado_id FROM tab_ftth_importacao_item WHERE id = ? AND status = "importado"',
                [$itemId]);
            if ($gerado && $gerado['gerado_tipo'] === 'CAIXA') {
                return (int) $gerado['gerado_id'];
            }
        }
        return null;
    }

    private static function marcarImportado(int $itemId, string $tipo, int $geradoId, string $usuario): void
    {
        Db::exec(
            'UPDATE tab_ftth_importacao_item
                SET status = "importado", gerado_tipo = ?, gerado_id = ?, decidido_por = ?, decidido_em = NOW()
              WHERE id = ?',
            [$tipo, $geradoId, $usuario, $itemId]);
        self::recontar([$itemId]);
    }

    /** Atualiza os contadores da importacao a que os itens pertencem. */
    private static function recontar(array $itemIds): void
    {
        if (!$itemIds) {
            return;
        }
        $marcas = implode(',', array_fill(0, count($itemIds), '?'));
        $imps = Db::todos(
            "SELECT DISTINCT importacao_id FROM tab_ftth_importacao_item WHERE id IN ($marcas)", $itemIds);
        foreach ($imps as $i) {
            Db::exec(
                'UPDATE tab_ftth_importacao imp
                    SET pendentes  = (SELECT COUNT(*) FROM tab_ftth_importacao_item x
                                       WHERE x.importacao_id = imp.id AND x.status = "pendente"),
                        importados = (SELECT COUNT(*) FROM tab_ftth_importacao_item x
                                       WHERE x.importacao_id = imp.id AND x.status = "importado"),
                        descartados= (SELECT COUNT(*) FROM tab_ftth_importacao_item x
                                       WHERE x.importacao_id = imp.id AND x.status = "descartado")
                  WHERE imp.id = ?', [$i['importacao_id']]);
        }
    }
}
