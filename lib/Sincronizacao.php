<?php
/**
 * ftth_doc :: projeção do addon nas tabelas NATIVAS do MK-AUTH.
 *
 * ESPELHO DE IDA. Escrevemos, nunca lemos de volta como verdade. A verdade mora em
 * tab_ftth_caixa + tab_ftth_splitter + tab_ftth_porta; o que sai daqui é uma cópia para
 * o cadastro do cliente e para o módulo CTO do HelpFiber enxergarem a rede documentada.
 *
 * Duas exceções aprovadas de escrita em tabela nativa — e só estas:
 *   1) sis_cliente.caixa_herm e sis_cliente.porta_splitter   (config sync_sis_cliente)
 *   2) a tabela cto                                          (config sync_cto_nativa, 21/09/2026)
 * Qualquer outra coluna nativa continua SOMENTE LEITURA.
 *
 * `cto_option` fica de fora de propósito: ela guarda portas INUTILIZADAS com o motivo, e
 * não a ocupação — o HelpFiber monta a ocupação a partir de caixa_herm/porta_splitter.
 *
 * Falha aqui NUNCA derruba a operação de documentação: vira warning no envelope e linha
 * no log técnico. Documentar a rede não pode depender do espelho.
 */
require_once __DIR__ . '/Db.php';
require_once __DIR__ . '/Log.php';
require_once __DIR__ . '/Config.php';
require_once __DIR__ . '/Auditoria.php';
require_once __DIR__ . '/Resultado.php';
require_once __DIR__ . '/Topologia.php';

final class Sincronizacao
{
    /** Limites reais das colunas nativas (latin1) — medidos na base, não chutados. */
    private const LIM_CTO_NAME       = 32;
    private const LIM_CTO_FSP        = 10;
    private const LIM_CTO_COORD      = 50;
    private const LIM_CLI_CAIXA      = 128;
    private const LIM_CLI_PORTA      = 32;
    private const MAX_CTO_PORTS      = 127;   // tinyint

    // ------------------------------------------------------------------ cadastro do cliente

    /**
     * Grava caixa_herm / porta_splitter no cadastro do cliente a partir do vínculo atual.
     * Sem vínculo, limpa os dois campos.
     */
    public static function cliente(int $clienteId, ?Resultado $r = null, string $usuario = 'sistema'): Resultado
    {
        $r = $r ?? Resultado::ok();
        if (!Config::ligado('sync_sis_cliente')) {
            return $r;
        }

        try {
            $porta = Topologia::portaDoCliente($clienteId);
            if (!$porta) {
                return self::escreverCliente($clienteId, null, null, $r, $usuario);
            }

            $caixaHerm = self::paraNativo((string) $porta['caixa_nome'], self::LIM_CLI_CAIXA, $cortou);
            if ($cortou) {
                $r->addAviso('FTTH-SYNC-002', ['campo' => 'caixa_herm', 'gravado' => $caixaHerm]);
            }

            // Numeração é por splitter (decisão de 21/09/2026). Quando a CTO tem mais de um
            // splitter de atendimento, "5" sozinho é ambíguo — daí o qualificador.
            $numero = (string) (int) $porta['numero'];
            $quantos = count(Topologia::splittersDeAtendimento((int) $porta['caixa_id']));
            if ($quantos > 1) {
                if (Config::ligado('sync_porta_qualificar')) {
                    $numero = $porta['splitter_nome'] . ':' . $numero;
                    $r->addAviso('FTTH-SYNC-003',
                        ['gravado' => $numero, 'splitters' => $quantos]);
                } else {
                    $r->addAviso('FTTH-SYNC-004',
                        ['gravado' => $numero, 'splitters' => $quantos]);
                }
            }
            $portaSplitter = self::paraNativo($numero, self::LIM_CLI_PORTA, $cortou);

            return self::escreverCliente($clienteId, $caixaHerm, $portaSplitter, $r, $usuario);
        } catch (Throwable $e) {
            Log::excecao('sincronizacao.cliente', $e, ['cliente_id' => $clienteId]);
            return $r->addAviso('FTTH-SYNC-001', ['cliente_id' => $clienteId]);
        }
    }

    public static function limparCliente(int $clienteId, ?Resultado $r = null, string $usuario = 'sistema'): Resultado
    {
        $r = $r ?? Resultado::ok();
        if (!Config::ligado('sync_sis_cliente')) {
            return $r;
        }
        try {
            return self::escreverCliente($clienteId, null, null, $r, $usuario);
        } catch (Throwable $e) {
            Log::excecao('sincronizacao.limparCliente', $e, ['cliente_id' => $clienteId]);
            return $r->addAviso('FTTH-SYNC-001', ['cliente_id' => $clienteId]);
        }
    }

    /** UPDATE cirúrgico: só as duas colunas autorizadas, e só quando o valor muda de fato. */
    private static function escreverCliente(int $clienteId, ?string $caixa, ?string $porta,
                                            Resultado $r, string $usuario): Resultado
    {
        $antes = Db::um('SELECT caixa_herm, porta_splitter FROM sis_cliente WHERE id = ?', [$clienteId]);
        if (!$antes) {
            return $r->addAviso('FTTH-SYNC-001', ['cliente_id' => $clienteId, 'motivo' => 'cliente inexistente']);
        }
        if ((string) $antes['caixa_herm'] === (string) $caixa
            && (string) $antes['porta_splitter'] === (string) $porta) {
            return $r;
        }

        Db::exec('UPDATE sis_cliente SET caixa_herm = ?, porta_splitter = ? WHERE id = ?',
            [$caixa, $porta, $clienteId]);
        Auditoria::registrar('sis_cliente', $clienteId, 'sincronizar',
            ['caixa_herm' => $antes['caixa_herm'], 'porta_splitter' => $antes['porta_splitter']],
            ['caixa_herm' => $caixa, 'porta_splitter' => $porta]);
        return $r;
    }

    // ------------------------------------------------------------------ tabela nativa `cto`

    /**
     * Espelha a caixa na tabela nativa `cto`. Caixa que não é CTO ativa tem o espelho removido.
     * Só escreve quando o conteúdo muda (hash do payload), para não encher o banco de UPDATE.
     */
    public static function caixa(int $caixaId, ?Resultado $r = null, string $usuario = 'sistema'): Resultado
    {
        $r = $r ?? Resultado::ok();
        if (!Config::ligado('sync_cto_nativa')) {
            return $r;
        }

        try {
            $caixa = Db::um('SELECT id, nome, tipo, lat, lng, excluido_em FROM tab_ftth_caixa WHERE id = ?',
                [$caixaId]);
            $espelho = Db::um('SELECT * FROM tab_ftth_cto_espelho WHERE caixa_id = ?', [$caixaId]);

            $eCto = $caixa && $caixa['excluido_em'] === null
                    && in_array($caixa['tipo'], ['CTO', 'CTO_AP'], true);
            if (!$eCto) {
                return $espelho ? self::removerEspelho($espelho, $r) : $r;
            }

            $payload = self::payloadCto($caixa, $r);
            $hash    = sha1(json_encode($payload, JSON_UNESCAPED_UNICODE));

            if ($espelho && $espelho['hash'] === $hash
                && Db::valor('SELECT id FROM cto WHERE id = ?', [(int) $espelho['cto_id']])) {
                return $r;
            }

            return self::gravarCto($caixaId, $espelho, $payload, $hash, $r);
        } catch (Throwable $e) {
            Log::excecao('sincronizacao.caixa', $e, ['caixa_id' => $caixaId]);
            return $r->addAviso('FTTH-SYNC-001', ['caixa_id' => $caixaId]);
        }
    }

    /**
     * Monta o que vai para `cto`.
     *
     * `fsp` (a PON) ainda não vem da topologia: o caminho óptico até o DIO/OLT é fase 3.
     * Até lá a PON é deduzida do provisionamento dos clientes já atendidos pela CTO — se
     * todos concordam, é ela; se divergem, fica em branco e o conflito vira aviso. É o
     * cruzamento documentação × provisionamento (F2) aparecendo mais cedo, de graça.
     */
    private static function payloadCto(array $caixa, Resultado $r): array
    {
        $caixaId = (int) $caixa['id'];

        $nome = self::paraNativo((string) $caixa['nome'], self::LIM_CTO_NAME, $cortou);
        if ($cortou) {
            $r->addAviso('FTTH-SYNC-002',
                ['campo' => 'cto.name', 'nome' => $caixa['nome'], 'gravado' => $nome]);
        }

        $portas = 0;
        foreach (Topologia::splittersDeAtendimento($caixaId) as $s) {
            $portas += (int) $s['saidas'];
        }
        if ($portas > self::MAX_CTO_PORTS) {
            $r->addAviso('FTTH-SYNC-005', ['campo' => 'cto.ports', 'calculado' => $portas]);
            $portas = self::MAX_CTO_PORTS;
        }

        $fsp = null;
        $pons = [];
        foreach (Db::todos('SELECT cliente_id FROM tab_ftth_porta p
                             JOIN tab_ftth_splitter s ON s.id = p.splitter_id
                            WHERE s.caixa_id = ? AND s.excluido_em IS NULL', [$caixaId]) as $p) {
            $cli = Topologia::cliente((int) $p['cliente_id']);
            $pon = trim((string) ($cli['porta_olt'] ?? ''));
            if ($pon !== '') {
                $pons[$pon] = true;
            }
        }
        if (count($pons) === 1) {
            $fsp = self::paraNativo((string) array_key_first($pons), self::LIM_CTO_FSP, $cortou);
        } elseif (count($pons) > 1) {
            $r->addAviso('FTTH-SYNC-006', ['caixa' => $caixa['nome'], 'pons' => array_keys($pons)]);
        }

        return [
            'name'        => $nome,
            'olt_id'      => self::oltNativa(),
            'fsp'         => $fsp,
            'ports'       => $portas > 0 ? $portas : null,
            'coordenadas' => substr(
                number_format((float) $caixa['lat'], 7, '.', '') . ',' .
                number_format((float) $caixa['lng'], 7, '.', ''), 0, self::LIM_CTO_COORD),
        ];
    }

    private static function gravarCto(int $caixaId, ?array $espelho, array $payload,
                                      string $hash, Resultado $r): Resultado
    {
        $existe = $espelho
            && Db::valor('SELECT id FROM cto WHERE id = ?', [(int) $espelho['cto_id']]);

        if ($existe) {
            Db::exec('UPDATE cto SET name = ?, olt_id = ?, fsp = ?, ports = ?, coordenadas = ? WHERE id = ?',
                [$payload['name'], $payload['olt_id'], $payload['fsp'], $payload['ports'],
                 $payload['coordenadas'], (int) $espelho['cto_id']]);
            $ctoId = (int) $espelho['cto_id'];
        } else {
            Db::exec('INSERT INTO cto (name, olt_id, fsp, ports, coordenadas) VALUES (?,?,?,?,?)',
                [$payload['name'], $payload['olt_id'], $payload['fsp'], $payload['ports'],
                 $payload['coordenadas']]);
            $ctoId = Db::ultimoId();
        }

        if ($espelho) {
            Db::exec('UPDATE tab_ftth_cto_espelho
                         SET cto_id = ?, nome_gravado = ?, hash = ?, sincronizado_em = NOW()
                       WHERE caixa_id = ?',
                [$ctoId, $payload['name'], $hash, $caixaId]);
        } else {
            Db::exec('INSERT INTO tab_ftth_cto_espelho
                        (caixa_id, cto_id, nome_gravado, hash, sincronizado_em)
                      VALUES (?,?,?,?,NOW())',
                [$caixaId, $ctoId, $payload['name'], $hash]);
        }

        Auditoria::registrar('cto', $ctoId, $existe ? 'sincronizar' : 'criar_espelho', null, $payload);
        return $r;
    }

    /**
     * Tira a CTO do cadastro nativo quando a caixa deixa de ser CTO ativa.
     * Só apaga a linha se ela ainda for a que gravamos — se alguém a renomeou pela tela do
     * HelpFiber, o registro passou a ser dele e fica onde está.
     */
    private static function removerEspelho(array $espelho, Resultado $r): Resultado
    {
        $nativa = Db::um('SELECT id, name FROM cto WHERE id = ?', [(int) $espelho['cto_id']]);
        if ($nativa && (string) $nativa['name'] === (string) $espelho['nome_gravado']) {
            Db::exec('DELETE FROM cto WHERE id = ?', [(int) $espelho['cto_id']]);
            Auditoria::registrar('cto', (int) $espelho['cto_id'], 'excluir_espelho',
                ['name' => $espelho['nome_gravado']], null);
        } elseif ($nativa) {
            $r->addAviso('FTTH-SYNC-007',
                ['cto_id' => (int) $espelho['cto_id'], 'name' => $nativa['name']]);
        }
        Db::exec('DELETE FROM tab_ftth_cto_espelho WHERE id = ?', [(int) $espelho['id']]);
        return $r;
    }

    /** olt.id a gravar: o configurado, senão a única cadastrada. Mais de uma sem config = nulo. */
    private static function oltNativa(): ?int
    {
        $cfg = (string) Config::get('sync_cto_olt_id', '');
        if ($cfg !== '' && ctype_digit($cfg)) {
            return (int) $cfg;
        }
        $linhas = Db::todos('SELECT id FROM olt ORDER BY id LIMIT 2');
        return count($linhas) === 1 ? (int) $linhas[0]['id'] : null;
    }

    // ------------------------------------------------------------------ apoio

    /**
     * Prepara texto para coluna latin1.
     *
     * A conexão é utf8mb4 e o MySQL converte sozinho na escrita — o que ele NÃO faz é
     * avisar quando um caractere não existe em latin1: vira '?' em silêncio. Então a
     * transliteração é feita aqui, de propósito, e o texto segue já no formato que vai
     * sobreviver. O corte é por caractere porque em latin1 cada um ocupa 1 byte.
     */
    private static function paraNativo(string $texto, int $limite, ?bool &$cortou = null): string
    {
        $cortou = false;

        $latin = @iconv('UTF-8', 'ISO-8859-1//TRANSLIT', $texto);
        if ($latin === false) {
            $latin = preg_replace('/[^\x20-\x7E]/', '?', $texto);
        }
        $seguro = @iconv('ISO-8859-1', 'UTF-8', $latin);
        if ($seguro === false) {
            $seguro = $texto;
        }

        if (mb_strlen($seguro) > $limite) {
            $cortou = true;
            $seguro = mb_substr($seguro, 0, $limite);
        }
        return $seguro;
    }
}
