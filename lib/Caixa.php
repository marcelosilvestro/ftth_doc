<?php
/**
 * ftth_doc :: caixas (CTO, CEO, DC, poste, prédio, cliente, reserva, problema…).
 *
 * Toda criação/alteração passa por aqui — nem o mapa nem a importação escrevem direto
 * na tabela (3b.0). Regras: nome único por região (inclusive entre excluídas), coordenada
 * válida, tipo conhecido, lock otimista e auditoria.
 */
require_once __DIR__ . '/Db.php';
require_once __DIR__ . '/Geo.php';
require_once __DIR__ . '/Config.php';
require_once __DIR__ . '/Versao.php';
require_once __DIR__ . '/Auditoria.php';
require_once __DIR__ . '/Resultado.php';

final class Caixa
{
    public const TIPOS = ['DC', 'PREDIO', 'POSTE', 'CEO', 'CTO', 'CTO_AP',
                          'CLIENTE', 'RESERVA', 'PROBLEMA', 'FALHA'];

    /**
     * O que o seletor de tipo OFERECE, na ordem em que aparece.
     *
     * É menor que TIPOS de propósito: poste, cliente e CTO AP saíram do menu por decisão
     * de 22/09/2026 (nenhuma caixa ativa os usava), mas continuam aceitos na validação —
     * senão as caixas antigas desses tipos deixariam de poder ser editadas, e o histórico
     * passaria a referenciar um tipo que o sistema não reconhece mais.
     */
    public const ROTULOS = [
        'CTO'      => 'CTO',
        'CEO'      => 'CEO',
        'DC'       => 'DC / POP',
        'PREDIO'   => 'Prédio',
        'PROBLEMA' => 'Problema',
        'RESERVA'  => 'Reserva',
    ];

    /** Ícones do Bootstrap Icons que o MK-AUTH já carrega — sem dependência nova. */
    public const ICONES = [
        'DC'       => 'bi-hdd-rack-fill',
        'PREDIO'   => 'bi-building',
        'POSTE'    => 'bi-signpost-2-fill',
        'CEO'      => 'bi-diagram-3-fill',
        'CLIENTE'  => 'bi-person-fill',
        'CTO'      => 'bi-box-seam',
        'CTO_AP'   => 'bi-buildings-fill',
        'PROBLEMA' => 'bi-exclamation-triangle-fill',
        'RESERVA'  => 'bi-bookmark-fill',
        'FALHA'    => 'bi-exclamation-octagon-fill',
    ];

    public static function icone(string $tipo): string
    {
        return self::ICONES[$tipo] ?? 'bi-box-seam';
    }

    /**
     * Silhueta da peça real, desenhada para tamanho de ícone.
     *
     * Vem do desenho técnico da caixa, mas redesenhada PARA 24px: o original tem texto de
     * 9px e parafusos de 4px, que nessa escala viram sujeira. O que sobrevive é o que
     * identifica a peça de longe — na CEO o topo abaulado e o anel de fechamento, na CTO
     * a caixa alta e a trava central de fecho.
     *
     * Mora aqui, e não no JS, para existir UMA fonte: o marcador do mapa, a ficha da caixa
     * e o seletor de tipo do modal desenham todos a partir desta constante.
     * `{cor}` é trocado por quem usa.
     */
    public const SILHUETAS = [
        'CEO' => '<path d="M7 11 a5 5 0 0 1 10 0 v5 h-10 z" fill="{cor}" stroke="#fff"'
               . ' stroke-width="1.6" stroke-linejoin="round"/>'
               . '<rect x="5.2" y="15.4" width="13.6" height="3.6" rx="1.2" fill="{cor}"'
               . ' stroke="#fff" stroke-width="1.6"/>'
               // As entradas de cabo saem do corpo, então usam a cor da caixa: em branco
               // elas sumiriam na ficha e no modal, que têm fundo claro.
               . '<path d="M10 18.8v2.6M14 18.8v2.6" stroke="{cor}" stroke-width="2"'
               . ' stroke-linecap="round"/>',

        'CTO' => '<rect x="6.2" y="3.6" width="11.6" height="16.8" rx="2.6" fill="{cor}"'
               . ' stroke="#fff" stroke-width="1.6"/>'
               . '<circle cx="12" cy="16.2" r="1.9" fill="none" stroke="#fff" stroke-width="1.5"/>'
               . '<path d="M9 7.4h6" stroke="#fff" stroke-width="1.5" stroke-linecap="round"'
               . ' opacity=".85"/>',

        // DC/POP: o rack, com as unidades empilhadas.
        'DC' => '<rect x="4.8" y="3.4" width="14.4" height="17.2" rx="2" fill="{cor}"'
              . ' stroke="#fff" stroke-width="1.6"/>'
              . '<path d="M7.6 7.6h8.8M7.6 12h8.8M7.6 16.4h8.8" stroke="#fff"'
              . ' stroke-width="1.5" stroke-linecap="round"/>',

        // Prédio: as janelas em duas colunas e a porta na base.
        'PREDIO' => '<rect x="6.4" y="3.4" width="11.2" height="17.2" rx="1.6" fill="{cor}"'
                  . ' stroke="#fff" stroke-width="1.6"/>'
                  . '<path d="M9.3 7.4h1.5M13.2 7.4h1.5M9.3 11h1.5M13.2 11h1.5"'
                  . ' stroke="#fff" stroke-width="1.6" stroke-linecap="round"/>'
                  . '<path d="M10.6 20.6v-4.2h2.8v4.2" fill="none" stroke="#fff"'
                  . ' stroke-width="1.5" stroke-linejoin="round"/>',

        // Problema: o triângulo de alerta, que já é lido como aviso em qualquer lugar.
        'PROBLEMA' => '<path d="M12 3.4 20.8 19.6H3.2z" fill="{cor}" stroke="#fff"'
                    . ' stroke-width="1.6" stroke-linejoin="round"/>'
                    . '<path d="M12 9.4v4.2" stroke="#fff" stroke-width="1.9"'
                    . ' stroke-linecap="round"/>'
                    . '<circle cx="12" cy="16.6" r="1.1" fill="#fff"/>',

        // Reserva: a sobra de cabo enrolada — o rolo visto de frente.
        'RESERVA' => '<circle cx="12" cy="12" r="7.8" fill="{cor}" stroke="#fff" stroke-width="1.6"/>'
                   . '<circle cx="12" cy="12" r="3.9" fill="none" stroke="#fff" stroke-width="1.5"/>'
                   . '<circle cx="12" cy="12" r="1.2" fill="#fff"/>',
    ];

    /** O SVG pronto, ou null quando o tipo não tem silhueta (aí vale o Bootstrap Icon). */
    public static function silhueta(string $tipo, string $cor = '#4A5568', int $px = 24): ?string
    {
        if (!isset(self::SILHUETAS[$tipo])) {
            return null;
        }
        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="' . $px
             . '" height="' . $px . '" class="ftth-silhueta">'
             . str_replace('{cor}', $cor, self::SILHUETAS[$tipo]) . '</svg>';
    }

    public const CORES = ['#29B6F6', '#1E40AF', '#FF9100', '#E53935', '#43A047',
                          '#FDD835', '#8E24AA', '#EC407A', '#212121', '#9E9E9E', '#795548'];

    public static function criar(int $regiaoId, string $tipo, string $nome, string $cor,
                                 float $lat, float $lng, string $usuario, array $extra = []): Resultado
    {
        $nome = trim($nome);
        $tipo = strtoupper(trim($tipo));

        if (!in_array($tipo, self::TIPOS, true)) {
            return Resultado::erro('FTTH-SYS-002', ['campo' => 'tipo'], 'Tipo de caixa inválido.');
        }
        if ($nome === '' || mb_strlen($nome) > 80) {
            return Resultado::erro('FTTH-SYS-002', ['campo' => 'nome'], 'Informe um nome de até 80 caracteres.');
        }
        if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
            return Resultado::erro('FTTH-GEO-002', ['lat' => $lat, 'lng' => $lng]);
        }
        if (!Db::valor('SELECT id FROM tab_ftth_regiao WHERE id = ? AND excluido_em IS NULL', [$regiaoId])) {
            return Resultado::erro('FTTH-SYS-002', ['campo' => 'regiao'], 'Região inválida.');
        }

        // O nome é único por região mesmo entre caixas excluídas: nome não se reaproveita.
        $existe = Db::um('SELECT id, excluido_em FROM tab_ftth_caixa WHERE regiao_id = ? AND nome = ?',
            [$regiaoId, $nome]);
        if ($existe) {
            return Resultado::erro('FTTH-SYS-002',
                ['campo' => 'nome', 'caixa_id' => (int) $existe['id']],
                $existe['excluido_em']
                    ? 'Já existiu uma caixa com esse nome (excluída). Escolha outro nome.'
                    : 'Já existe uma caixa com esse nome nesta região.');
        }

        if (!preg_match('/^#[0-9A-Fa-f]{6}$/', $cor)) {
            $cor = '#00C853';
        }

        return Db::transacao(function () use ($regiaoId, $tipo, $nome, $cor, $lat, $lng, $usuario, $extra) {
            Db::exec(
                'INSERT INTO tab_ftth_caixa
                    (regiao_id, tipo, nome, cor, lat, lng, capacidade, pai_id, andar, observacao,
                     origem, criado_por, criado_em)
                 VALUES (?,?,?,?,?,?,?,?,?,?,"manual",?,NOW())',
                [$regiaoId, $tipo, $nome, $cor, $lat, $lng,
                 $extra['capacidade'] ?? null, $extra['pai_id'] ?? null,
                 $extra['andar'] ?? null, $extra['observacao'] ?? null, $usuario]
            );
            $id = Db::ultimoId();
            Auditoria::registrar('caixa', $id, 'criar', null,
                ['nome' => $nome, 'tipo' => $tipo, 'lat' => $lat, 'lng' => $lng], $regiaoId);
            return Resultado::ok(['id' => $id, 'nome' => $nome, 'tipo' => $tipo, 'cor' => $cor,
                                  'lat' => $lat, 'lng' => $lng]);
        });
    }

    /**
     * Move a caixa e leva junto a ponta de cada cabo que encosta nela.
     *
     * O vínculo do vão é por id de caixa, mas o DESENHO vive em `vertices`, com as
     * coordenadas literais das duas pontas. Sem reescrevê-las, o cabo continuaria apontando
     * para onde a caixa estava — e, pior, `comprimento_optico` ficaria velho, que é o
     * número que o cálculo de potência usa. Por isso o recálculo vem junto, na mesma
     * transação: o mapa e a conta nunca discordam.
     */
    public static function mover(int $id, float $lat, float $lng, ?int $versao, string $usuario): Resultado
    {
        $antes = Db::um('SELECT * FROM tab_ftth_caixa WHERE id = ? AND excluido_em IS NULL', [$id]);
        if (!$antes) {
            return Resultado::erro('FTTH-TOP-001', ['caixa' => $id]);
        }
        if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
            return Resultado::erro('FTTH-GEO-002', ['lat' => $lat, 'lng' => $lng]);
        }

        return Db::transacao(function () use ($id, $lat, $lng, $versao, $usuario, $antes) {
            if (!Versao::avancar('caixa', $id, $versao, $usuario)) {
                return Resultado::erro('FTTH-CONC-001',
                    ['entidade' => 'caixa', 'id' => $id, 'versao_atual' => Versao::atual('caixa', $id)]);
            }
            Db::exec('UPDATE tab_ftth_caixa SET lat = ?, lng = ? WHERE id = ?', [$lat, $lng, $id]);
            $vaos = self::arrastarPontasDosVaos($id, $lat, $lng, $usuario);

            Auditoria::registrar('caixa', $id, 'mover',
                ['lat' => $antes['lat'], 'lng' => $antes['lng']],
                ['lat' => $lat, 'lng' => $lng, 'vaos_ajustados' => $vaos],
                (int) $antes['regiao_id']);

            return Resultado::ok(['id' => $id, 'lat' => $lat, 'lng' => $lng, 'vaos' => $vaos]);
        });
    }

    /**
     * Reescreve a ponta de cada vão que encosta nesta caixa e recalcula os comprimentos.
     * Devolve quantos vãos foram ajustados.
     */
    private static function arrastarPontasDosVaos(int $caixaId, float $lat, float $lng,
                                                  string $usuario): int
    {
        $folga = Config::num('fator_folga_cabo', 1.03);
        $ajustados = 0;

        foreach (Db::todos(
            'SELECT id, caixa_ini_id, caixa_fim_id, vertices, fator_folga, reserva_m
               FROM tab_ftth_cabo_vao
              WHERE (caixa_ini_id = ? OR caixa_fim_id = ?) AND excluido_em IS NULL',
            [$caixaId, $caixaId]) as $v) {

            $pontos = json_decode((string) $v['vertices'], true);
            if (!is_array($pontos) || count($pontos) < 2) {
                continue;
            }

            // A caixa pode estar nas duas pontas (não deveria, mas o dado manda).
            if ((int) $v['caixa_ini_id'] === $caixaId) {
                $pontos[0] = [$lat, $lng];
            }
            if ((int) $v['caixa_fim_id'] === $caixaId) {
                $pontos[count($pontos) - 1] = [$lat, $lng];
            }

            $metros = Geo::comprimento($pontos);
            $fator  = (float) ($v['fator_folga'] ?: $folga);
            $optico = Geo::comprimentoOptico($metros, $fator, (float) $v['reserva_m']);

            Db::exec(
                'UPDATE tab_ftth_cabo_vao
                    SET vertices = ?, comprimento_geo = ?, comprimento_optico = ?,
                        alterado_por = ?, alterado_em = NOW()
                  WHERE id = ?',
                [json_encode($pontos), round($metros, 2), $optico, $usuario, (int) $v['id']]
            );
            $ajustados++;
        }

        return $ajustados;
    }

    public static function alterar(int $id, array $campos, ?int $versao, string $usuario): Resultado
    {
        $antes = Db::um('SELECT * FROM tab_ftth_caixa WHERE id = ? AND excluido_em IS NULL', [$id]);
        if (!$antes) {
            return Resultado::erro('FTTH-TOP-001', ['caixa' => $id]);
        }

        $nome = isset($campos['nome']) ? trim((string) $campos['nome']) : $antes['nome'];
        $tipo = isset($campos['tipo']) ? strtoupper((string) $campos['tipo']) : $antes['tipo'];
        $cor  = isset($campos['cor']) && preg_match('/^#[0-9A-Fa-f]{6}$/', (string) $campos['cor'])
                ? (string) $campos['cor'] : $antes['cor'];

        if ($nome === '' || !in_array($tipo, self::TIPOS, true)) {
            return Resultado::erro('FTTH-SYS-002');
        }
        if ($nome !== $antes['nome']
            && Db::valor('SELECT id FROM tab_ftth_caixa WHERE regiao_id = ? AND nome = ? AND id <> ?',
                         [$antes['regiao_id'], $nome, $id])) {
            return Resultado::erro('FTTH-SYS-002', ['campo' => 'nome'],
                'Já existe uma caixa com esse nome nesta região.');
        }

        return Db::transacao(function () use ($id, $nome, $tipo, $cor, $campos, $versao, $usuario, $antes) {
            if (!Versao::avancar('caixa', $id, $versao, $usuario)) {
                return Resultado::erro('FTTH-CONC-001',
                    ['entidade' => 'caixa', 'id' => $id, 'versao_atual' => Versao::atual('caixa', $id)]);
            }
            Db::exec(
                'UPDATE tab_ftth_caixa SET nome = ?, tipo = ?, cor = ?, capacidade = ?, observacao = ?
                  WHERE id = ?',
                [$nome, $tipo, $cor,
                 array_key_exists('capacidade', $campos) ? $campos['capacidade'] : $antes['capacidade'],
                 array_key_exists('observacao', $campos) ? $campos['observacao'] : $antes['observacao'],
                 $id]
            );
            $depois = Db::um('SELECT * FROM tab_ftth_caixa WHERE id = ?', [$id]);
            Auditoria::registrar('caixa', $id, 'alterar', $antes, $depois, (int) $antes['regiao_id']);
            return Resultado::ok($depois);
        });
    }

    /** Exclusão lógica; recusa enquanto houver cabo ou ligação presos à caixa. */
    public static function excluir(int $id, ?int $versao, string $usuario): Resultado
    {
        $antes = Db::um('SELECT * FROM tab_ftth_caixa WHERE id = ? AND excluido_em IS NULL', [$id]);
        if (!$antes) {
            return Resultado::erro('FTTH-TOP-001', ['caixa' => $id]);
        }

        $vaos = (int) Db::valor(
            'SELECT COUNT(*) FROM tab_ftth_cabo_vao
              WHERE (caixa_ini_id = ? OR caixa_fim_id = ?) AND excluido_em IS NULL', [$id, $id]);
        $ligacoes = (int) Db::valor('SELECT COUNT(*) FROM tab_ftth_ligacao WHERE caixa_id = ?', [$id]);
        if ($vaos > 0 || $ligacoes > 0) {
            return Resultado::erro('FTTH-TOP-016', ['vaos' => $vaos, 'ligacoes' => $ligacoes],
                'A caixa ainda tem ' . $vaos . ' cabo(s) e ' . $ligacoes . ' ligação(ões).');
        }

        return Db::transacao(function () use ($id, $versao, $usuario, $antes) {
            if (!Versao::avancar('caixa', $id, $versao, $usuario)) {
                return Resultado::erro('FTTH-CONC-001',
                    ['entidade' => 'caixa', 'id' => $id, 'versao_atual' => Versao::atual('caixa', $id)]);
            }
            Db::exec('UPDATE tab_ftth_caixa SET excluido_em = NOW() WHERE id = ?', [$id]);
            Auditoria::registrar('caixa', $id, 'excluir', $antes, null, (int) $antes['regiao_id']);
            return Resultado::ok(['id' => $id]);
        });
    }

    /**
     * Sugere o próximo nome seguindo o padrão do provedor (CTO.02.05 -> CTO.02.06).
     * Só sugestão: quem decide o nome é o usuário.
     */
    public static function sugerirNome(int $regiaoId, string $tipo, ?string $base = null): ?string
    {
        $prefixo = $tipo === 'CTO_AP' ? 'CTO' : $tipo;
        if ($base && preg_match('/^(.*?)(\d+)$/', trim($base), $m)) {
            $largura = strlen($m[2]);
            for ($i = (int) $m[2] + 1; $i <= (int) $m[2] + 50; $i++) {
                $tentativa = $m[1] . str_pad((string) $i, $largura, '0', STR_PAD_LEFT);
                if (!Db::valor('SELECT id FROM tab_ftth_caixa WHERE regiao_id = ? AND nome = ?',
                               [$regiaoId, $tentativa])) {
                    return $tentativa;
                }
            }
            return null;
        }

        $ultimo = Db::valor(
            'SELECT nome FROM tab_ftth_caixa
              WHERE regiao_id = ? AND tipo = ? ORDER BY nome DESC LIMIT 1', [$regiaoId, $tipo]);
        return $ultimo ? self::sugerirNome($regiaoId, $tipo, (string) $ultimo) : $prefixo . '.01';
    }
}
