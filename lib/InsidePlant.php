<?php
/**
 * ftth_doc :: inside plant — OLT, placas PON, DIO e portas dentro do POP.
 *
 * O POP é uma caixa do tipo DC no mapa; tudo aqui pendura nela. Mesmo contrato do resto do
 * addon (3b.0): validação fora da transação, `Db::transacao` só na escrita, lock otimista,
 * auditoria e envelope `Resultado`.
 *
 * F4: host, credencial e coordenadas da OLT vivem na tabela NATIVA `olt`, somente leitura.
 *     Aqui guardamos o que é do addon — apelido, óptica e as placas. Quando há vínculo, o
 *     fabricante vem de lá; sem vínculo, o usuário informa.
 *
 * A porta do DIO é a ORIGEM do circuito óptico: é dela que o cálculo de potência parte,
 * usando `ptx_dbm` da própria porta e, na falta, o da OLT. A ligação física dela com a
 * fibra da rua é uma ligação comum em `tab_ftth_ligacao` (elemento `DIO_PORTA`) — não
 * existe tabela de caminho (D1).
 */
require_once __DIR__ . '/Db.php';
require_once __DIR__ . '/Log.php';
require_once __DIR__ . '/Versao.php';
require_once __DIR__ . '/Auditoria.php';
require_once __DIR__ . '/Resultado.php';
require_once __DIR__ . '/Fibra.php';
require_once __DIR__ . '/Topologia.php';

final class InsidePlant
{
    /** Capacidades de DIO que o mercado vende — as mesmas do seletor da tela. */
    public const CAPACIDADES_DIO = [12, 24, 36, 48, 72, 96, 144];

    public const ACESSOS = ['simulado', 'ssh', 'telnet'];

    public const OPERACOES = ['DISTRIBUICAO', 'PTP_TX', 'PTP_RX', 'PTP_TXRX'];

    /** Vendors mais comuns no mercado brasileiro de GPON. "Outro" é texto livre. */
    public const FABRICANTES = ['Huawei', 'ZTE', 'Fiberhome', 'Datacom', 'Nokia', 'V-SOL',
                                'C-Data', 'Intelbras', 'Parks', 'Cianet', 'Furukawa',
                                'Ubiquiti', 'TP-Link', 'MikroTik'];

    private const MAX_PLACAS = 32;

    // ------------------------------------------------------------------ o POP

    /** A caixa precisa existir, estar ativa e ser do tipo DC. */
    public static function pop(int $caixaId): ?array
    {
        return Db::um(
            'SELECT c.id, c.nome, c.tipo, c.regiao_id, r.nome AS regiao
               FROM tab_ftth_caixa c
               JOIN tab_ftth_regiao r ON r.id = c.regiao_id
              WHERE c.id = ? AND c.tipo = "DC" AND c.excluido_em IS NULL', [$caixaId]);
    }

    /** Todos os POPs, para a tela oferecer a escolha quando vier sem `?caixa=`. */
    public static function pops(): array
    {
        return Db::todos(
            'SELECT c.id, c.nome, r.nome AS regiao,
                    (SELECT COUNT(*) FROM tab_ftth_olt o
                      WHERE o.caixa_id = c.id AND o.excluido_em IS NULL) AS olts,
                    (SELECT COUNT(*) FROM tab_ftth_dio d
                      WHERE d.caixa_id = c.id AND d.excluido_em IS NULL) AS dios
               FROM tab_ftth_caixa c
               JOIN tab_ftth_regiao r ON r.id = c.regiao_id
              WHERE c.tipo = "DC" AND c.excluido_em IS NULL
              ORDER BY c.nome');
    }

    // ------------------------------------------------------------------ OLT

    public static function olts(int $caixaId): array
    {
        $lista = Db::todos(
            'SELECT * FROM tab_ftth_olt
              WHERE caixa_id = ? AND excluido_em IS NULL ORDER BY apelido', [$caixaId]);

        foreach ($lista as &$o) {
            $o['placas'] = self::placas((int) $o['id']);
            $o['pons']   = self::pons((int) $o['id']);
            $o['nativa'] = $o['olt_id'] ? self::oltNativa((int) $o['olt_id']) : null;
        }
        return $lista;
    }

    /** A nativa é só leitura — e a senha de acesso dela nunca sai daqui. */
    public static function oltNativa(int $id): ?array
    {
        return Db::um('SELECT id, name, maker, ipaddress, access_port FROM olt WHERE id = ?', [$id]);
    }

    public static function oltsNativas(): array
    {
        return Db::todos('SELECT id, name, maker, ipaddress FROM olt ORDER BY name');
    }

    public static function placas(int $oltId): array
    {
        return Db::todos(
            'SELECT id, prefixo, portas, inicio, ordem FROM tab_ftth_olt_placa
              WHERE olt_id = ? ORDER BY ordem, id', [$oltId]);
    }

    /**
     * Os rótulos de PON que a OLT oferece, montados das placas.
     * Placa `0/1` com 16 portas começando em 1 → `0/1/1` … `0/1/16`.
     */
    public static function pons(int $oltId): array
    {
        $pons = [];
        foreach (self::placas($oltId) as $p) {
            $ini = max(0, (int) $p['inicio']);
            for ($i = 0; $i < (int) $p['portas']; $i++) {
                $pons[] = rtrim($p['prefixo'], '/') . '/' . ($ini + $i);
            }
        }
        return $pons;
    }

    /**
     * Cria a OLT com as placas de uma vez — sem placa, o seletor de PON da porta do DIO
     * nasceria vazio e o usuário teria de digitar o rótulo à mão.
     */
    public static function criarOlt(int $caixaId, array $d, string $usuario): Resultado
    {
        $pop = self::pop($caixaId);
        if (!$pop) {
            return Resultado::erro('FTTH-TOP-001', ['caixa' => $caixaId],
                'O POP precisa ser uma caixa do tipo DC.');
        }

        $apelido = trim((string) ($d['apelido'] ?? ''));
        if ($apelido === '' || mb_strlen($apelido) > 60) {
            return Resultado::erro('FTTH-SYS-002', ['campo' => 'apelido'],
                'Informe um apelido de até 60 caracteres.');
        }
        $conf = Db::um('SELECT id, excluido_em FROM tab_ftth_olt WHERE apelido = ?', [$apelido]);
        if ($msg = self::conflitoNome($conf, 'Já existe uma OLT com esse apelido.')) {
            return Resultado::erro('FTTH-SYS-002', ['campo' => 'apelido'], $msg);
        }

        $erro = self::validarOptica($d);
        if ($erro !== null) {
            return $erro;
        }
        $placas = self::normalizarPlacas($d['placas'] ?? []);
        if ($placas instanceof Resultado) {
            return $placas;
        }

        $oltNativa = (int) ($d['olt_id'] ?? 0);
        if ($oltNativa > 0 && !self::oltNativa($oltNativa)) {
            return Resultado::erro('FTTH-SYS-002', ['campo' => 'olt_id'],
                'A OLT escolhida não existe no cadastro do MK-AUTH.');
        }

        return Db::transacao(function () use ($caixaId, $pop, $apelido, $d, $placas, $oltNativa, $usuario) {
            Db::exec(
                'INSERT INTO tab_ftth_olt
                    (caixa_id, olt_id, apelido, fabricante, formato_porta, acesso, classe_optica,
                     ptx_dbm, sensibilidade_dbm, saturacao_dbm, criado_por, criado_em)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,NOW())',
                [$caixaId, $oltNativa > 0 ? $oltNativa : null, $apelido,
                 trim((string) ($d['fabricante'] ?? '')) ?: null,
                 trim((string) ($d['formato_porta'] ?? '0/1/1')),
                 in_array($d['acesso'] ?? '', self::ACESSOS, true) ? $d['acesso'] : 'simulado',
                 $d['classe_optica'] ?? 'B+',
                 (float) ($d['ptx_dbm'] ?? 3.00),
                 (float) ($d['sensibilidade_dbm'] ?? -27.00),
                 (float) ($d['saturacao_dbm'] ?? -8.00),
                 $usuario]
            );
            $id = Db::ultimoId();
            self::gravarPlacas($id, $placas);

            Auditoria::registrar('olt', $id, 'criar', null,
                ['caixa_id' => $caixaId, 'apelido' => $apelido, 'placas' => count($placas)],
                (int) $pop['regiao_id']);

            return Resultado::ok(['id' => $id, 'apelido' => $apelido,
                                  'pons' => count(self::pons($id))]);
        });
    }

    public static function alterarOlt(int $id, array $d, ?int $versao, string $usuario): Resultado
    {
        $antes = Db::um('SELECT * FROM tab_ftth_olt WHERE id = ? AND excluido_em IS NULL', [$id]);
        if (!$antes) {
            return Resultado::erro('FTTH-SYS-002', ['olt' => $id], 'OLT não encontrada.');
        }

        $apelido = isset($d['apelido']) ? trim((string) $d['apelido']) : $antes['apelido'];
        if ($apelido === '' || mb_strlen($apelido) > 60) {
            return Resultado::erro('FTTH-SYS-002', ['campo' => 'apelido']);
        }
        $conf = $apelido !== $antes['apelido']
            ? Db::um('SELECT id, excluido_em FROM tab_ftth_olt WHERE apelido = ? AND id <> ?', [$apelido, $id])
            : null;
        if ($msg = self::conflitoNome($conf, 'Já existe uma OLT com esse apelido.')) {
            return Resultado::erro('FTTH-SYS-002', ['campo' => 'apelido'], $msg);
        }

        $erro = self::validarOptica($d + $antes);
        if ($erro !== null) {
            return $erro;
        }

        // Placas só são substituídas quando vieram na requisição.
        $placas = array_key_exists('placas', $d) ? self::normalizarPlacas($d['placas']) : null;
        if ($placas instanceof Resultado) {
            return $placas;
        }
        if (is_array($placas)) {
            $erroUso = self::conferirPonsEmUso($id, $placas);
            if ($erroUso !== null) {
                return $erroUso;
            }
        }

        return Db::transacao(function () use ($id, $antes, $apelido, $d, $placas, $versao, $usuario) {
            if (!Versao::avancar('olt', $id, $versao, $usuario)) {
                return Resultado::erro('FTTH-CONC-001',
                    ['entidade' => 'olt', 'id' => $id, 'versao_atual' => Versao::atual('olt', $id)]);
            }
            Db::exec(
                'UPDATE tab_ftth_olt
                    SET apelido = ?, fabricante = ?, formato_porta = ?, acesso = ?,
                        classe_optica = ?, ptx_dbm = ?, sensibilidade_dbm = ?, saturacao_dbm = ?
                  WHERE id = ?',
                [$apelido,
                 array_key_exists('fabricante', $d) ? (trim((string) $d['fabricante']) ?: null) : $antes['fabricante'],
                 $d['formato_porta'] ?? $antes['formato_porta'],
                 in_array($d['acesso'] ?? '', self::ACESSOS, true) ? $d['acesso'] : $antes['acesso'],
                 $d['classe_optica'] ?? $antes['classe_optica'],
                 isset($d['ptx_dbm']) ? (float) $d['ptx_dbm'] : $antes['ptx_dbm'],
                 isset($d['sensibilidade_dbm']) ? (float) $d['sensibilidade_dbm'] : $antes['sensibilidade_dbm'],
                 isset($d['saturacao_dbm']) ? (float) $d['saturacao_dbm'] : $antes['saturacao_dbm'],
                 $id]
            );
            if (is_array($placas)) {
                Db::exec('DELETE FROM tab_ftth_olt_placa WHERE olt_id = ?', [$id]);
                self::gravarPlacas($id, $placas);
            }

            $depois = Db::um('SELECT * FROM tab_ftth_olt WHERE id = ?', [$id]);
            Auditoria::registrar('olt', $id, 'alterar', $antes, $depois, self::regiaoDaOlt($id));
            return Resultado::ok($depois);
        });
    }

    /** Exclusão lógica. Recusa enquanto houver porta de DIO apontando para esta OLT. */
    public static function excluirOlt(int $id, ?int $versao, string $usuario): Resultado
    {
        $antes = Db::um('SELECT * FROM tab_ftth_olt WHERE id = ? AND excluido_em IS NULL', [$id]);
        if (!$antes) {
            return Resultado::erro('FTTH-SYS-002', ['olt' => $id], 'OLT não encontrada.');
        }
        $portas = (int) Db::valor(
            'SELECT COUNT(*) FROM tab_ftth_dio_porta WHERE olt_ftth_id = ? AND excluido_em IS NULL', [$id]);
        if ($portas > 0) {
            return Resultado::erro('FTTH-SYS-002', ['portas' => $portas],
                'Há ' . $portas . ' porta(s) de DIO usando esta OLT. Libere antes de excluir.');
        }

        // Precisa sair antes do DELETE: depois dele nao ha mais de onde tirar a regiao.
        $regiao = self::regiaoDaOlt($id);
        $antes['placas'] = Db::todos(
            'SELECT prefixo, portas, inicio, ordem FROM tab_ftth_olt_placa
              WHERE olt_id = ? ORDER BY ordem', [$id]);

        return Db::transacao(function () use ($id, $antes, $versao, $usuario, $regiao) {
            if (!Versao::avancar('olt', $id, $versao, $usuario)) {
                return Resultado::erro('FTTH-CONC-001', ['entidade' => 'olt', 'id' => $id]);
            }
            // Exclusao de verdade (decisao de 22/09/2026). OLT e DIO sao inventario interno
            // do POP, nao ponto fisico com QR como a caixa: marcar excluido_em so deixava
            // lixo no banco e reservava o apelido para sempre, por causa do UNIQUE uq_apelido.
            // O "antes" completo, placas inclusive, continua em tab_ftth_historico.
            Db::exec('DELETE FROM tab_ftth_olt_placa WHERE olt_id = ?', [$id]);
            Db::exec('DELETE FROM tab_ftth_olt WHERE id = ?', [$id]);
            Auditoria::registrar('olt', $id, 'excluir', $antes, null, $regiao);
            return Resultado::ok(['id' => $id]);
        });
    }

    // ------------------------------------------------------------------ DIO

    public static function dios(int $caixaId): array
    {
        $lista = Db::todos(
            'SELECT * FROM tab_ftth_dio
              WHERE caixa_id = ? AND excluido_em IS NULL ORDER BY nome', [$caixaId]);

        foreach ($lista as &$d) {
            $d['portas_lista'] = self::portas((int) $d['id']);
        }
        return $lista;
    }

    /**
     * Cria o DIO e todas as portas de uma vez.
     *
     * Um DIO é um painel físico: as portas existem desde que ele é instalado, vazias. Criar
     * sob demanda deixaria buracos na numeração e quebraria a grade da tela.
     */
    public static function criarDio(int $caixaId, string $nome, int $portas, string $usuario): Resultado
    {
        $pop = self::pop($caixaId);
        if (!$pop) {
            return Resultado::erro('FTTH-TOP-001', ['caixa' => $caixaId],
                'O POP precisa ser uma caixa do tipo DC.');
        }

        $nome = trim($nome);
        if ($nome === '' || mb_strlen($nome) > 60) {
            return Resultado::erro('FTTH-SYS-002', ['campo' => 'nome'],
                'Informe um nome de até 60 caracteres.');
        }
        if (!in_array($portas, self::CAPACIDADES_DIO, true)) {
            return Resultado::erro('FTTH-SYS-002', ['campo' => 'portas'],
                'Capacidade inválida. Use ' . implode(', ', self::CAPACIDADES_DIO) . '.');
        }
        $conf = Db::um('SELECT id, excluido_em FROM tab_ftth_dio WHERE caixa_id = ? AND nome = ?',
                       [$caixaId, $nome]);
        if ($msg = self::conflitoNome($conf, 'Já existe um DIO com esse nome neste POP.')) {
            return Resultado::erro('FTTH-SYS-002', ['campo' => 'nome'], $msg);
        }

        return Db::transacao(function () use ($caixaId, $pop, $nome, $portas, $usuario) {
            Db::exec('INSERT INTO tab_ftth_dio (caixa_id, nome, portas, criado_por, criado_em)
                      VALUES (?,?,?,?,NOW())', [$caixaId, $nome, $portas, $usuario]);
            $id = Db::ultimoId();

            for ($n = 1; $n <= $portas; $n++) {
                Db::exec('INSERT INTO tab_ftth_dio_porta (dio_id, numero, criado_por, criado_em)
                          VALUES (?,?,?,NOW())', [$id, $n, $usuario]);
            }

            Auditoria::registrar('dio', $id, 'criar', null,
                ['caixa_id' => $caixaId, 'nome' => $nome, 'portas' => $portas],
                (int) $pop['regiao_id']);

            return Resultado::ok(['id' => $id, 'nome' => $nome, 'portas' => $portas]);
        });
    }

    /** Renomear e mudar capacidade. Reduzir só passa se as portas que somem estiverem vazias. */
    public static function alterarDio(int $id, array $campos, ?int $versao, string $usuario): Resultado
    {
        $antes = Db::um('SELECT * FROM tab_ftth_dio WHERE id = ? AND excluido_em IS NULL', [$id]);
        if (!$antes) {
            return Resultado::erro('FTTH-SYS-002', ['dio' => $id], 'DIO não encontrado.');
        }

        $nome   = isset($campos['nome']) ? trim((string) $campos['nome']) : $antes['nome'];
        $portas = isset($campos['portas']) ? (int) $campos['portas'] : (int) $antes['portas'];
        $ativo  = array_key_exists('ativo', $campos) ? (!empty($campos['ativo']) ? 1 : 0) : (int) $antes['ativo'];

        if ($nome === '' || mb_strlen($nome) > 60) {
            return Resultado::erro('FTTH-SYS-002', ['campo' => 'nome']);
        }
        if (!in_array($portas, self::CAPACIDADES_DIO, true)) {
            return Resultado::erro('FTTH-SYS-002', ['campo' => 'portas'], 'Capacidade inválida.');
        }
        $conf = $nome !== $antes['nome']
            ? Db::um('SELECT id, excluido_em FROM tab_ftth_dio WHERE caixa_id = ? AND nome = ? AND id <> ?',
                     [$antes['caixa_id'], $nome, $id])
            : null;
        if ($msg = self::conflitoNome($conf, 'Já existe um DIO com esse nome neste POP.')) {
            return Resultado::erro('FTTH-SYS-002', ['campo' => 'nome'], $msg);
        }

        if ($portas < (int) $antes['portas']) {
            $ocupadas = (int) Db::valor(
                'SELECT COUNT(*) FROM tab_ftth_dio_porta p
                  WHERE p.dio_id = ? AND p.numero > ? AND p.excluido_em IS NULL
                    AND (p.olt_ftth_id IS NOT NULL
                         OR EXISTS (SELECT 1 FROM tab_ftth_ligacao_ponta lp
                                     WHERE lp.elemento = "DIO_PORTA" AND lp.elemento_id = p.id))',
                [$id, $portas]);
            if ($ocupadas > 0) {
                return Resultado::erro('FTTH-SYS-002', ['campo' => 'portas', 'ocupadas' => $ocupadas],
                    'Há ' . $ocupadas . ' porta(s) em uso acima de ' . $portas . '. Libere antes de reduzir.');
            }
        }

        return Db::transacao(function () use ($id, $antes, $nome, $portas, $ativo, $versao, $usuario) {
            if (!Versao::avancar('dio', $id, $versao, $usuario)) {
                return Resultado::erro('FTTH-CONC-001',
                    ['entidade' => 'dio', 'id' => $id, 'versao_atual' => Versao::atual('dio', $id)]);
            }
            Db::exec('UPDATE tab_ftth_dio SET nome = ?, portas = ?, ativo = ? WHERE id = ?',
                [$nome, $portas, $ativo, $id]);

            $tinha = (int) $antes['portas'];
            if ($portas > $tinha) {
                for ($n = $tinha + 1; $n <= $portas; $n++) {
                    Db::exec('INSERT INTO tab_ftth_dio_porta (dio_id, numero, criado_por, criado_em)
                              VALUES (?,?,?,NOW())
                              ON DUPLICATE KEY UPDATE excluido_em = NULL', [$id, $n, $usuario]);
                }
            } elseif ($portas < $tinha) {
                // Some da grade, mas a linha fica: se o painel voltar a crescer, o histórico
                // daquela porta continua lá.
                Db::exec('UPDATE tab_ftth_dio_porta SET excluido_em = NOW()
                           WHERE dio_id = ? AND numero > ?', [$id, $portas]);
            }

            $depois = Db::um('SELECT * FROM tab_ftth_dio WHERE id = ?', [$id]);
            Auditoria::registrar('dio', $id, 'alterar', $antes, $depois, self::regiaoDaCaixa((int) $antes['caixa_id']));
            return Resultado::ok($depois);
        });
    }

    public static function excluirDio(int $id, ?int $versao, string $usuario): Resultado
    {
        $antes = Db::um('SELECT * FROM tab_ftth_dio WHERE id = ? AND excluido_em IS NULL', [$id]);
        if (!$antes) {
            return Resultado::erro('FTTH-SYS-002', ['dio' => $id], 'DIO não encontrado.');
        }
        $ligadas = (int) Db::valor(
            'SELECT COUNT(*) FROM tab_ftth_ligacao_ponta lp
               JOIN tab_ftth_dio_porta p ON p.id = lp.elemento_id
              WHERE lp.elemento = "DIO_PORTA" AND p.dio_id = ?', [$id]);
        if ($ligadas > 0) {
            return Resultado::erro('FTTH-TOP-016', ['ligacoes' => $ligadas],
                'O DIO ainda tem ' . $ligadas . ' fibra(s) ligada(s). Desconecte antes de excluir.');
        }

        $regiao = self::regiaoDaCaixa((int) $antes['caixa_id']);
        // O que a porta guardava vai junto para o historico -- so as que tinham conteudo,
        // porque gravar 72 portas vazias nao conta nada a quem le depois.
        $antes['portas_usadas'] = Db::todos(
            'SELECT numero, olt_ftth_id, pon, servico, ptx_dbm, operacao
               FROM tab_ftth_dio_porta
              WHERE dio_id = ? AND (olt_ftth_id IS NOT NULL OR servico IS NOT NULL)
              ORDER BY numero', [$id]);

        return Db::transacao(function () use ($id, $antes, $versao, $usuario, $regiao) {
            if (!Versao::avancar('dio', $id, $versao, $usuario)) {
                return Resultado::erro('FTTH-CONC-001', ['entidade' => 'dio', 'id' => $id]);
            }
            Db::exec('DELETE FROM tab_ftth_dio_porta WHERE dio_id = ?', [$id]);
            Db::exec('DELETE FROM tab_ftth_dio WHERE id = ?', [$id]);
            Auditoria::registrar('dio', $id, 'excluir', $antes, null, $regiao);
            return Resultado::ok(['id' => $id]);
        });
    }

    // ------------------------------------------------------------------ portas do DIO

    /**
     * A grade de portas: para cada uma, equipamento, serviço e a saída para a rua.
     * É o que a tela desenha e o que dá o status de cada cartãozinho.
     */
    public static function portas(int $dioId): array
    {
        $portas = Db::todos(
            'SELECT p.*, o.apelido AS olt_apelido, o.fabricante AS olt_fabricante,
                    o.olt_id AS olt_nativa_id, o.ptx_dbm AS olt_ptx_dbm
               FROM tab_ftth_dio_porta p
               LEFT JOIN tab_ftth_olt o ON o.id = p.olt_ftth_id AND o.excluido_em IS NULL
              WHERE p.dio_id = ? AND p.excluido_em IS NULL
              ORDER BY p.numero', [$dioId]);

        foreach ($portas as &$p) {
            $p['saida'] = self::saidaDaPorta((int) $p['id']);
            // Verde: tem equipamento e saída. Laranja: saída sem equipamento (ou o contrário).
            // Cinza: nada. É o mesmo semáforo da tela do UpperX.
            $temEquip = $p['olt_ftth_id'] !== null;
            $temSaida = $p['saida'] !== null;
            $p['status'] = ($temEquip && $temSaida) ? 'ok'
                         : (($temEquip || $temSaida) ? 'parcial' : 'vazia');
            $p['rotulo'] = 'P' . str_pad((string) $p['numero'], 2, '0', STR_PAD_LEFT);
        }
        return $portas;
    }

    /** Para onde a porta sai: cabo, fibra e a caixa do outro lado do vão. */
    public static function saidaDaPorta(int $portaId): ?array
    {
        $ponta = Db::um(
            'SELECT lp.ligacao_id, lp.caixa_id FROM tab_ftth_ligacao_ponta lp
              WHERE lp.elemento = "DIO_PORTA" AND lp.elemento_id = ?', [$portaId]);
        if (!$ponta) {
            return null;
        }

        $outra = Db::um(
            'SELECT elemento, elemento_id, numero FROM tab_ftth_ligacao_ponta
              WHERE ligacao_id = ? AND NOT (elemento = "DIO_PORTA" AND elemento_id = ?)',
            [(int) $ponta['ligacao_id'], $portaId]);
        if (!$outra || $outra['elemento'] !== 'VAO_FIBRA') {
            return ['ligacao_id' => (int) $ponta['ligacao_id'], 'rotulo' => 'ligada'];
        }

        $vao = Db::um(
            'SELECT v.id, v.caixa_ini_id, v.caixa_fim_id, cb.padrao_cores, t.fibras_por_tubo
               FROM tab_ftth_cabo_vao v
               JOIN tab_ftth_cabo cb     ON cb.id = v.cabo_id
               JOIN tab_ftth_cabo_tipo t ON t.id = cb.cabo_tipo_id
              WHERE v.id = ?', [(int) $outra['elemento_id']]);
        if (!$vao) {
            return ['ligacao_id' => (int) $ponta['ligacao_id'], 'rotulo' => 'ligada'];
        }

        $outraCaixa = (int) $vao['caixa_ini_id'] === (int) $ponta['caixa_id']
            ? (int) $vao['caixa_fim_id'] : (int) $vao['caixa_ini_id'];
        $nome = (string) Db::valor('SELECT nome FROM tab_ftth_caixa WHERE id = ?', [$outraCaixa]);
        $fibra = Fibra::descrever((int) $outra['numero'], (string) $vao['padrao_cores'],
                                  (int) $vao['fibras_por_tubo']);

        return [
            'ligacao_id' => (int) $ponta['ligacao_id'],
            'vao_id'     => (int) $vao['id'],
            'numero'     => (int) $outra['numero'],
            'sentido'    => 'ST ' . $nome,
            'rotulo'     => 'ST ' . $nome . ' - ' . $fibra['rotulo'],
            'cor'        => $fibra['cor'],
            'cor_nome'   => $fibra['cor_nome'],
        ];
    }

    /** Altera os dados internos da porta. A saída OSP tem caminho próprio. */
    public static function alterarPorta(int $portaId, array $campos, ?int $versao, string $usuario): Resultado
    {
        $antes = Db::um('SELECT * FROM tab_ftth_dio_porta WHERE id = ? AND excluido_em IS NULL', [$portaId]);
        if (!$antes) {
            return Resultado::erro('FTTH-TOP-013', ['porta' => $portaId]);
        }

        $operacao = strtoupper((string) ($campos['operacao'] ?? $antes['operacao']));
        if (!in_array($operacao, self::OPERACOES, true)) {
            return Resultado::erro('FTTH-SYS-002', ['campo' => 'operacao'], 'Operação inválida.');
        }

        $oltId = array_key_exists('olt_ftth_id', $campos)
            ? ((int) $campos['olt_ftth_id'] ?: null) : $antes['olt_ftth_id'];
        if ($oltId !== null) {
            $olt = Db::um('SELECT id FROM tab_ftth_olt WHERE id = ? AND excluido_em IS NULL', [$oltId]);
            if (!$olt) {
                return Resultado::erro('FTTH-SYS-002', ['campo' => 'olt_ftth_id'], 'OLT não encontrada.');
            }
        }

        $pon = array_key_exists('pon', $campos) ? (trim((string) $campos['pon']) ?: null) : $antes['pon'];
        if ($pon !== null && $oltId !== null && !in_array($pon, self::pons((int) $oltId), true)) {
            return Resultado::erro('FTTH-SYS-002', ['campo' => 'pon'],
                'Esta PON não existe nas placas da OLT escolhida.');
        }

        return Db::transacao(function () use ($portaId, $antes, $operacao, $oltId, $pon, $campos, $versao, $usuario) {
            if (!Versao::avancar('dio_porta', $portaId, $versao, $usuario)) {
                return Resultado::erro('FTTH-CONC-001',
                    ['entidade' => 'dio_porta', 'id' => $portaId,
                     'versao_atual' => Versao::atual('dio_porta', $portaId)]);
            }
            Db::exec(
                'UPDATE tab_ftth_dio_porta SET operacao = ?, olt_ftth_id = ?, pon = ?,
                        servico = ?, ptx_dbm = ? WHERE id = ?',
                [$operacao, $oltId, $pon,
                 array_key_exists('servico', $campos) ? (trim((string) $campos['servico']) ?: null) : $antes['servico'],
                 array_key_exists('ptx_dbm', $campos)
                    ? ($campos['ptx_dbm'] === '' ? null : (float) $campos['ptx_dbm'])
                    : $antes['ptx_dbm'],
                 $portaId]
            );
            $depois = Db::um('SELECT * FROM tab_ftth_dio_porta WHERE id = ?', [$portaId]);
            Auditoria::registrar('dio_porta', $portaId, 'alterar', $antes, $depois);
            return Resultado::ok($depois);
        });
    }

    // ------------------------------------------------------------------ saída para a rua

    /**
     * As fibras que a porta do DIO pode alcançar: as dos vãos que saem deste POP.
     * Fibra já usada nesta caixa aparece marcada — a tela mostra e não deixa escolher.
     */
    public static function saidasDisponiveis(int $caixaId): array
    {
        $opcoes = [];
        foreach (Db::todos(
            'SELECT v.id, v.caixa_ini_id, v.caixa_fim_id
               FROM tab_ftth_cabo_vao v
               JOIN tab_ftth_cabo cb ON cb.id = v.cabo_id
              WHERE (v.caixa_ini_id = ? OR v.caixa_fim_id = ?)
                AND v.excluido_em IS NULL AND cb.excluido_em IS NULL
              ORDER BY v.id', [$caixaId, $caixaId]) as $v) {

            $outra = (int) $v['caixa_ini_id'] === $caixaId ? (int) $v['caixa_fim_id'] : (int) $v['caixa_ini_id'];
            $nome  = (string) Db::valor('SELECT nome FROM tab_ftth_caixa WHERE id = ?', [$outra]);

            foreach (Topologia::fibrasDoVao((int) $v['id'], $caixaId) as $f) {
                $opcoes[] = [
                    'vao_id'  => (int) $v['id'],
                    'numero'  => $f['numero'],
                    'sentido' => 'ST ' . $nome,
                    'rotulo'  => $f['rotulo'] . ' — ST ' . $nome,
                    'cor'     => $f['cor'],
                    'estado'  => $f['estado'],
                ];
            }
        }
        return $opcoes;
    }

    /**
     * Liga a porta do DIO a uma fibra — ou solta a atual quando `$vaoId` vem zerado.
     *
     * É o mesmo `Topologia::conectar()` do diagrama: a ligação nasce em `tab_ftth_ligacao`
     * com as mesmas validações e a mesma matriz. Aqui muda só por onde o usuário escolhe.
     */
    public static function definirSaida(int $portaId, int $vaoId, int $numero, string $usuario): Resultado
    {
        $porta = Db::um(
            'SELECT p.id, p.numero, d.caixa_id
               FROM tab_ftth_dio_porta p
               JOIN tab_ftth_dio d ON d.id = p.dio_id
              WHERE p.id = ? AND p.excluido_em IS NULL AND d.excluido_em IS NULL', [$portaId]);
        if (!$porta) {
            return Resultado::erro('FTTH-TOP-013', ['porta' => $portaId]);
        }
        $caixaId = (int) $porta['caixa_id'];

        return Db::transacao(function () use ($portaId, $vaoId, $numero, $caixaId, $usuario) {
            // Trocar de fibra solta a anterior primeiro — a porta tem uma saída só (I7).
            $atual = self::saidaDaPorta($portaId);
            if ($atual) {
                $r = Topologia::desconectar((int) $atual['ligacao_id'], $usuario);
                if (!$r->ok) {
                    return $r;
                }
            }
            if ($vaoId <= 0) {
                return Resultado::ok(['porta' => $portaId, 'saida' => null]);
            }

            $r = Topologia::conectar($caixaId,
                ['elemento' => 'DIO_PORTA',  'elemento_id' => $portaId, 'numero' => 0],
                ['elemento' => 'VAO_FIBRA',  'elemento_id' => $vaoId,   'numero' => $numero],
                null, $usuario);
            if (!$r->ok) {
                return $r;
            }
            return Resultado::ok(['porta' => $portaId, 'saida' => self::saidaDaPorta($portaId)]);
        });
    }

    // ------------------------------------------------------------------ apoio

    private static function validarOptica(array $d): ?Resultado
    {
        if (isset($d['classe_optica']) && !in_array($d['classe_optica'], ['B+', 'C+', 'C++'], true)) {
            return Resultado::erro('FTTH-SYS-002', ['campo' => 'classe_optica']);
        }
        $ptx = (float) ($d['ptx_dbm'] ?? 3.00);
        if ($ptx < -10 || $ptx > 20) {
            return Resultado::erro('FTTH-SYS-002', ['campo' => 'ptx_dbm'],
                'Potência de saída fora de faixa (-10 a +20 dBm).');
        }
        return null;
    }

    /** @return array|Resultado lista normalizada, ou o erro que impede de seguir */
    private static function normalizarPlacas($placas)
    {
        if (!is_array($placas)) {
            return [];
        }
        if (count($placas) > self::MAX_PLACAS) {
            return Resultado::erro('FTTH-SYS-002', ['campo' => 'placas'],
                'Máximo de ' . self::MAX_PLACAS . ' placas por OLT.');
        }

        $saida = [];
        $vistos = [];
        foreach ($placas as $i => $p) {
            $prefixo = trim((string) ($p['prefixo'] ?? ''));
            $portas  = (int) ($p['portas'] ?? 0);
            $inicio  = (int) ($p['inicio'] ?? 1);

            if ($prefixo === '' || mb_strlen($prefixo) > 20) {
                return Resultado::erro('FTTH-SYS-002', ['campo' => 'placas'],
                    'Informe o prefixo da placa (ex.: 0/1).');
            }
            if ($portas < 1 || $portas > 64) {
                return Resultado::erro('FTTH-SYS-002', ['campo' => 'placas'],
                    'Placa com número de portas inválido (1 a 64).');
            }
            if (isset($vistos[$prefixo])) {
                return Resultado::erro('FTTH-SYS-002', ['campo' => 'placas'],
                    'A placa ' . $prefixo . ' aparece duas vezes.');
            }
            $vistos[$prefixo] = true;
            $saida[] = ['prefixo' => $prefixo, 'portas' => $portas,
                        'inicio' => max(0, $inicio), 'ordem' => $i];
        }
        return $saida;
    }

    private static function gravarPlacas(int $oltId, array $placas): void
    {
        foreach ($placas as $p) {
            Db::exec('INSERT INTO tab_ftth_olt_placa (olt_id, prefixo, portas, inicio, ordem)
                      VALUES (?,?,?,?,?)',
                [$oltId, $p['prefixo'], $p['portas'], $p['inicio'], $p['ordem']]);
        }
    }

    /**
     * Trocar as placas não pode apagar do mapa uma PON que alguma porta já usa — a porta
     * ficaria apontando para um rótulo que não existe mais.
     */
    private static function conferirPonsEmUso(int $oltId, array $placasNovas): ?Resultado
    {
        $novas = [];
        foreach ($placasNovas as $p) {
            for ($i = 0; $i < $p['portas']; $i++) {
                $novas[rtrim($p['prefixo'], '/') . '/' . ($p['inicio'] + $i)] = true;
            }
        }
        $perdidas = [];
        foreach (Db::todos(
            'SELECT DISTINCT pon FROM tab_ftth_dio_porta
              WHERE olt_ftth_id = ? AND pon IS NOT NULL AND excluido_em IS NULL', [$oltId]) as $r) {
            if (!isset($novas[$r['pon']])) {
                $perdidas[] = $r['pon'];
            }
        }
        if ($perdidas) {
            return Resultado::erro('FTTH-SYS-002', ['pons' => $perdidas],
                'Estas PONs estão em uso e sumiriam com as novas placas: ' . implode(', ', $perdidas) . '.');
        }
        return null;
    }

    /**
     * Mensagem de nome repetido, ou null se nao ha conflito.
     *
     * Desde 22/09/2026 a exclusao de OLT e DIO e fisica, entao o normal e o conflito ser
     * com um equipamento que esta na tela. A linha do excluido so aparece se sobrou
     * registro marcado antes daquela mudanca -- e ai o usuario merece ler isso, em vez de
     * procurar na tela uma OLT que nao esta mais la.
     */
    private static function conflitoNome(?array $achado, string $normal): ?string
    {
        if (!$achado) {
            return null;
        }
        if (!empty($achado['excluido_em'])) {
            return 'Esse nome ainda está preso a um registro excluído em '
                 . date('d/m/Y', strtotime((string) $achado['excluido_em'])) . '. Escolha outro.';
        }
        return $normal;
    }

    private static function regiaoDaOlt(int $oltId): ?int
    {
        $v = Db::valor('SELECT c.regiao_id FROM tab_ftth_olt o
                          JOIN tab_ftth_caixa c ON c.id = o.caixa_id WHERE o.id = ?', [$oltId]);
        return $v === null ? null : (int) $v;
    }

    private static function regiaoDaCaixa(int $caixaId): ?int
    {
        $v = Db::valor('SELECT regiao_id FROM tab_ftth_caixa WHERE id = ?', [$caixaId]);
        return $v === null ? null : (int) $v;
    }
}
