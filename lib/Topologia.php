<?php
/**
 * ftth_doc :: topologia — splitters, portas de atendimento e vínculo do cliente.
 *
 * Nem a tela, nem o AJAX, nem a API escrevem nessas tabelas direto (3b.0): tudo passa
 * por aqui, dentro de transação, com auditoria e lock otimista.
 *
 * Invariantes que este serviço sustenta (o banco já recusa parte delas):
 *  I5  splitter = 1 entrada + N saídas;
 *  I6  cliente só entra em saída de splitter de ATENDIMENTO, uma saída por cliente
 *      (UNIQUE uq_splitter_porta) e um cliente em uma só porta (UNIQUE uq_cliente).
 *
 * F1: a identidade do cliente é sis_cliente.id (INT). O `login` viaja junto como atributo
 *     de exibição — nunca como chave de JOIN, porque o mkradius é latin1 e as tab_ftth_*
 *     são utf8mb4 (F5). Por isso sis_cliente é lido em consulta separada, sempre por id.
 */
require_once __DIR__ . '/Db.php';
require_once __DIR__ . '/Geo.php';
require_once __DIR__ . '/Log.php';
require_once __DIR__ . '/Fibra.php';
require_once __DIR__ . '/Config.php';
require_once __DIR__ . '/Versao.php';
require_once __DIR__ . '/Auditoria.php';
require_once __DIR__ . '/Resultado.php';
require_once __DIR__ . '/Sincronizacao.php';

final class Topologia
{
    public const FUNCOES = ['ATENDIMENTO', 'DERIVACAO'];
    public const MODELOS = ['BAL', 'DESBAL', 'PERSONALIZADO'];

    private const MAX_SAIDAS = 128;

    // ------------------------------------------------------------------ splitters

    /**
     * Cria um splitter dentro de uma caixa.
     * $d: funcao, razao, saidas, nome?, modelo?, orientacao?, conectorizado?, perdas?
     */
    public static function criarSplitter(int $caixaId, array $d, string $usuario): Resultado
    {
        $caixa = Db::um('SELECT id, regiao_id, nome FROM tab_ftth_caixa WHERE id = ? AND excluido_em IS NULL',
            [$caixaId]);
        if (!$caixa) {
            return Resultado::erro('FTTH-TOP-001', ['caixa' => $caixaId]);
        }

        $funcao = strtoupper(trim((string) ($d['funcao'] ?? '')));
        $modelo = strtoupper(trim((string) ($d['modelo'] ?? 'BAL')));
        $razao  = trim((string) ($d['razao'] ?? ''));
        $saidas = (int) ($d['saidas'] ?? 0);

        if (!in_array($funcao, self::FUNCOES, true)) {
            return Resultado::erro('FTTH-SYS-002', ['campo' => 'funcao'],
                'Função do splitter deve ser Atendimento ou Derivação.');
        }
        if (!in_array($modelo, self::MODELOS, true)) {
            return Resultado::erro('FTTH-SYS-002', ['campo' => 'modelo'], 'Modelo de splitter inválido.');
        }
        if ($razao === '' || mb_strlen($razao) > 12) {
            return Resultado::erro('FTTH-SYS-002', ['campo' => 'razao'],
                'Informe a razão do splitter (ex.: 1:8).');
        }
        if ($saidas < 2 || $saidas > self::MAX_SAIDAS) {
            return Resultado::erro('FTTH-SYS-002', ['campo' => 'saidas'],
                'Número de saídas deve ficar entre 2 e ' . self::MAX_SAIDAS . '.');
        }

        $perdas = self::resolverPerdas($modelo, $razao, $saidas, $d['perdas'] ?? null);
        if ($perdas === null) {
            return Resultado::erro('FTTH-PWR-003', ['modelo' => $modelo, 'razao' => $razao]);
        }

        $nome = trim((string) ($d['nome'] ?? ''));
        if ($nome === '') {
            $nome = self::sugerirNomeSplitter($caixaId);
        }
        if (mb_strlen($nome) > 60) {
            return Resultado::erro('FTTH-SYS-002', ['campo' => 'nome'],
                'Nome do splitter: até 60 caracteres.');
        }
        if (Db::valor('SELECT id FROM tab_ftth_splitter WHERE caixa_id = ? AND nome = ?', [$caixaId, $nome])) {
            return Resultado::erro('FTTH-SYS-002', ['campo' => 'nome'],
                'Já existe um splitter com esse nome nesta caixa.');
        }

        $orientacao    = strtoupper((string) ($d['orientacao'] ?? 'V')) === 'H' ? 'H' : 'V';
        $conectorizado = !empty($d['conectorizado']) ? 1 : 0;

        return Db::transacao(function () use ($caixaId, $caixa, $nome, $funcao, $modelo, $razao,
                                              $saidas, $perdas, $orientacao, $conectorizado, $usuario) {
            Db::exec(
                'INSERT INTO tab_ftth_splitter
                    (caixa_id, nome, funcao, modelo, razao, saidas, perdas_json, orientacao,
                     conectorizado, criado_por, criado_em)
                 VALUES (?,?,?,?,?,?,?,?,?,?,NOW())',
                [$caixaId, $nome, $funcao, $modelo, $razao, $saidas,
                 json_encode($perdas, JSON_UNESCAPED_UNICODE), $orientacao, $conectorizado, $usuario]
            );
            $id = Db::ultimoId();
            Auditoria::registrar('splitter', $id, 'criar', null,
                ['caixa_id' => $caixaId, 'nome' => $nome, 'funcao' => $funcao,
                 'razao' => $razao, 'saidas' => $saidas], (int) $caixa['regiao_id']);

            $r = Resultado::ok(['id' => $id, 'caixa_id' => $caixaId, 'nome' => $nome,
                                'funcao' => $funcao, 'razao' => $razao, 'saidas' => $saidas]);
            return Sincronizacao::caixa($caixaId, $r, $usuario);
        });
    }

    /** Altera o splitter. Reduzir saídas é recusado enquanto houver porta ocupada acima do novo limite. */
    public static function alterarSplitter(int $id, array $campos, ?int $versao, string $usuario): Resultado
    {
        $antes = Db::um('SELECT * FROM tab_ftth_splitter WHERE id = ? AND excluido_em IS NULL', [$id]);
        if (!$antes) {
            return Resultado::erro('FTTH-TOP-009', ['splitter' => $id]);
        }

        $nome   = isset($campos['nome'])   ? trim((string) $campos['nome'])         : $antes['nome'];
        $razao  = isset($campos['razao'])  ? trim((string) $campos['razao'])        : $antes['razao'];
        $modelo = isset($campos['modelo']) ? strtoupper((string) $campos['modelo']) : $antes['modelo'];
        $saidas = isset($campos['saidas']) ? (int) $campos['saidas']                : (int) $antes['saidas'];

        if ($nome === '' || mb_strlen($nome) > 60 || !in_array($modelo, self::MODELOS, true)
            || $razao === '' || $saidas < 2 || $saidas > self::MAX_SAIDAS) {
            return Resultado::erro('FTTH-SYS-002');
        }
        if ($nome !== $antes['nome']
            && Db::valor('SELECT id FROM tab_ftth_splitter WHERE caixa_id = ? AND nome = ? AND id <> ?',
                         [$antes['caixa_id'], $nome, $id])) {
            return Resultado::erro('FTTH-SYS-002', ['campo' => 'nome'],
                'Já existe um splitter com esse nome nesta caixa.');
        }

        // Reduzir saídas não pode desligar cliente em silêncio.
        if ($saidas < (int) $antes['saidas']) {
            $acima = (int) Db::valor('SELECT COUNT(*) FROM tab_ftth_porta WHERE splitter_id = ? AND numero > ?',
                [$id, $saidas]);
            if ($acima > 0) {
                return Resultado::erro('FTTH-SYS-002', ['campo' => 'saidas', 'portas_ocupadas' => $acima],
                    'Há ' . $acima . ' cliente(s) em saídas acima de ' . $saidas . '. Desvincule antes de reduzir.');
            }
        }

        // Só reaproveita as perdas gravadas quando modelo, razão e saídas continuam os mesmos.
        $mesmaConfiguracao = $modelo === $antes['modelo'] && $razao === $antes['razao']
                             && $saidas === (int) $antes['saidas'];
        $informadas = $campos['perdas'] ?? ($mesmaConfiguracao ? json_decode((string) $antes['perdas_json'], true) : null);

        $perdas = self::resolverPerdas($modelo, $razao, $saidas, $informadas);
        if ($perdas === null) {
            return Resultado::erro('FTTH-PWR-003', ['modelo' => $modelo, 'razao' => $razao]);
        }

        $orientacao = isset($campos['orientacao'])
            ? (strtoupper((string) $campos['orientacao']) === 'H' ? 'H' : 'V') : $antes['orientacao'];
        $conectorizado = array_key_exists('conectorizado', $campos)
            ? (!empty($campos['conectorizado']) ? 1 : 0) : (int) $antes['conectorizado'];

        return Db::transacao(function () use ($id, $antes, $nome, $modelo, $razao, $saidas,
                                              $perdas, $orientacao, $conectorizado, $versao, $usuario) {
            if (!Versao::avancar('splitter', $id, $versao, $usuario)) {
                return Resultado::erro('FTTH-CONC-001',
                    ['entidade' => 'splitter', 'id' => $id, 'versao_atual' => Versao::atual('splitter', $id)]);
            }
            Db::exec(
                'UPDATE tab_ftth_splitter
                    SET nome = ?, modelo = ?, razao = ?, saidas = ?, perdas_json = ?,
                        orientacao = ?, conectorizado = ?
                  WHERE id = ?',
                [$nome, $modelo, $razao, $saidas, json_encode($perdas, JSON_UNESCAPED_UNICODE),
                 $orientacao, $conectorizado, $id]
            );
            $depois = Db::um('SELECT * FROM tab_ftth_splitter WHERE id = ?', [$id]);
            Auditoria::registrar('splitter', $id, 'alterar', $antes, $depois,
                self::regiaoDaCaixa((int) $antes['caixa_id']));

            $r = Resultado::ok($depois);
            return Sincronizacao::caixa((int) $antes['caixa_id'], $r, $usuario);
        });
    }

    /** Exclusão lógica. Recusa enquanto houver cliente na saída ou fibra ligada ao splitter. */
    public static function excluirSplitter(int $id, ?int $versao, string $usuario): Resultado
    {
        $antes = Db::um('SELECT * FROM tab_ftth_splitter WHERE id = ? AND excluido_em IS NULL', [$id]);
        if (!$antes) {
            return Resultado::erro('FTTH-TOP-009', ['splitter' => $id]);
        }

        $clientes = (int) Db::valor('SELECT COUNT(*) FROM tab_ftth_porta WHERE splitter_id = ?', [$id]);
        if ($clientes > 0) {
            return Resultado::erro('FTTH-TOP-011', ['clientes' => $clientes],
                'O splitter ainda atende ' . $clientes . ' cliente(s). Desvincule antes de excluir.');
        }
        $pontas = (int) Db::valor(
            'SELECT COUNT(*) FROM tab_ftth_ligacao_ponta
              WHERE elemento IN ("SPLITTER_IN","SPLITTER_OUT") AND elemento_id = ?', [$id]);
        if ($pontas > 0) {
            return Resultado::erro('FTTH-TOP-016', ['ligacoes' => $pontas],
                'O splitter ainda tem ' . $pontas . ' fibra(s) ligada(s). Desconecte antes de excluir.');
        }

        return Db::transacao(function () use ($id, $antes, $versao, $usuario) {
            if (!Versao::avancar('splitter', $id, $versao, $usuario)) {
                return Resultado::erro('FTTH-CONC-001',
                    ['entidade' => 'splitter', 'id' => $id, 'versao_atual' => Versao::atual('splitter', $id)]);
            }
            Db::exec('UPDATE tab_ftth_splitter SET excluido_em = NOW() WHERE id = ?', [$id]);
            Auditoria::registrar('splitter', $id, 'excluir', $antes, null,
                self::regiaoDaCaixa((int) $antes['caixa_id']));

            $r = Resultado::ok(['id' => $id]);
            return Sincronizacao::caixa((int) $antes['caixa_id'], $r, $usuario);
        });
    }

    /**
     * Catálogo para o drawer "Adicionar splitter": o que existe em tab_ftth_perda_padrao,
     * já separado entre balanceado e desbalanceado, mais a opção personalizada.
     */
    public static function catalogoSplitters(): array
    {
        $bal = [];
        $desbal = [];
        foreach (Db::todos(
            'SELECT modelo, razao, saidas, perda_db, perda_db2
               FROM tab_ftth_perda_padrao ORDER BY modelo, ordem') as $p) {
            $item = ['razao' => $p['razao'], 'saidas' => (int) $p['saidas'],
                     'perda_db' => (float) $p['perda_db']];
            if ($p['modelo'] === 'BAL') {
                $bal[] = $item;
            } else {
                $desbal[] = $item + ['perda_db2' => (float) $p['perda_db2']];
            }
        }
        return ['BAL' => $bal, 'DESBAL' => $desbal];
    }

    public static function splitters(int $caixaId): array
    {
        return Db::todos(
            'SELECT id, nome, funcao, modelo, razao, saidas, orientacao, conectorizado, versao
               FROM tab_ftth_splitter
              WHERE caixa_id = ? AND excluido_em IS NULL
              ORDER BY funcao DESC, nome', [$caixaId]);
    }

    /** Splitters de atendimento da caixa — os únicos que recebem cliente (I6). */
    public static function splittersDeAtendimento(int $caixaId): array
    {
        return array_values(array_filter(self::splitters($caixaId),
            static function (array $s) { return $s['funcao'] === 'ATENDIMENTO'; }));
    }

    // ------------------------------------------------------------------ portas

    /**
     * Grade de portas de atendimento da caixa: uma entrada por saída de cada splitter
     * de ATENDIMENTO, ocupada ou livre. É o que a ficha da CTO e o app mostram.
     *
     * A numeração é POR SPLITTER (decisão do Marcelo, 21/09/2026): dois splitters na
     * mesma CTO têm, cada um, saídas 1..N.
     */
    public static function portas(int $caixaId): array
    {
        $grade = [];
        foreach (self::splittersDeAtendimento($caixaId) as $s) {
            $ocupadas = [];
            foreach (Db::todos('SELECT id, numero, cliente_id, login FROM tab_ftth_porta WHERE splitter_id = ?',
                               [(int) $s['id']]) as $p) {
                $ocupadas[(int) $p['numero']] = $p;
            }
            for ($n = 1; $n <= (int) $s['saidas']; $n++) {
                $grade[] = [
                    'splitter_id'   => (int) $s['id'],
                    'splitter_nome' => $s['nome'],
                    'numero'        => $n,
                    'ocupada'       => isset($ocupadas[$n]),
                    'porta_id'      => isset($ocupadas[$n]) ? (int) $ocupadas[$n]['id'] : null,
                    'cliente_id'    => isset($ocupadas[$n]) ? (int) $ocupadas[$n]['cliente_id'] : null,
                    'login'         => $ocupadas[$n]['login'] ?? null,
                ];
            }
        }
        return $grade;
    }

    public static function portaDoCliente(int $clienteId): ?array
    {
        return Db::um(
            'SELECT p.id, p.numero, p.login, s.id AS splitter_id, s.nome AS splitter_nome, s.saidas,
                    c.id AS caixa_id, c.nome AS caixa_nome, c.regiao_id, c.lat, c.lng
               FROM tab_ftth_porta p
               JOIN tab_ftth_splitter s ON s.id = p.splitter_id
               JOIN tab_ftth_caixa c    ON c.id = s.caixa_id
              WHERE p.cliente_id = ?', [$clienteId]);
    }

    // ------------------------------------------------------------------ vínculo do cliente

    /**
     * Liga o cliente a uma saída de splitter de atendimento.
     * $opcoes['mover'] = true move o cliente que já esteja em outra porta.
     *
     * É este o caminho que o MkMobile usa no fechamento da OS (decisão de 21/09/2026),
     * e é ele que devolve caixa_herm/porta_splitter para o cadastro do cliente.
     */
    public static function vincularCliente(int $clienteId, int $splitterId, int $numero,
                                           string $usuario, array $opcoes = []): Resultado
    {
        $splitter = Db::um(
            'SELECT s.*, c.id AS caixa_id, c.nome AS caixa_nome, c.regiao_id
               FROM tab_ftth_splitter s
               JOIN tab_ftth_caixa c ON c.id = s.caixa_id
              WHERE s.id = ? AND s.excluido_em IS NULL AND c.excluido_em IS NULL', [$splitterId]);
        if (!$splitter) {
            return Resultado::erro('FTTH-TOP-009', ['splitter' => $splitterId]);
        }
        if ($splitter['funcao'] !== 'ATENDIMENTO') {
            return Resultado::erro('FTTH-TOP-010', ['splitter' => $splitterId, 'funcao' => $splitter['funcao']]);
        }
        if ($numero < 1 || $numero > (int) $splitter['saidas']) {
            return Resultado::erro('FTTH-SYS-002', ['campo' => 'numero', 'saidas' => (int) $splitter['saidas']],
                'Este splitter tem saídas de 1 a ' . (int) $splitter['saidas'] . '.');
        }

        $cliente = self::cliente($clienteId);
        if (!$cliente) {
            return Resultado::erro('FTTH-SYS-002', ['campo' => 'cliente_id'],
                'Cliente não encontrado no cadastro.');
        }

        $naPorta = Db::um('SELECT cliente_id, login FROM tab_ftth_porta WHERE splitter_id = ? AND numero = ?',
            [$splitterId, $numero]);
        if ($naPorta && (int) $naPorta['cliente_id'] !== $clienteId) {
            return Resultado::erro('FTTH-TOP-011',
                ['splitter' => $splitterId, 'numero' => $numero, 'login' => $naPorta['login']],
                'A saída ' . $numero . ' já atende o cliente ' . $naPorta['login'] . '.');
        }

        $atual = self::portaDoCliente($clienteId);
        if ($atual && (int) $atual['splitter_id'] === $splitterId && (int) $atual['numero'] === $numero) {
            $r = Resultado::ok(['id' => (int) $atual['id'], 'ja_vinculado' => true]
                               + self::resumoVinculo($splitter, $numero, $cliente));
            return Sincronizacao::cliente($clienteId, $r, $usuario);
        }
        if ($atual && empty($opcoes['mover'])) {
            return Resultado::erro('FTTH-TOP-012',
                ['caixa' => $atual['caixa_nome'], 'splitter' => $atual['splitter_nome'],
                 'numero' => (int) $atual['numero']],
                'Este cliente já está na saída ' . (int) $atual['numero'] . ' do splitter '
                . $atual['splitter_nome'] . ' (' . $atual['caixa_nome'] . ').');
        }

        return Db::transacao(function () use ($clienteId, $splitterId, $numero, $usuario,
                                              $splitter, $cliente, $atual) {
            $caixaAnterior = null;
            if ($atual) {
                $caixaAnterior = (int) $atual['caixa_id'];
                Db::exec('DELETE FROM tab_ftth_porta WHERE id = ?', [(int) $atual['id']]);
                Auditoria::registrar('porta', (int) $atual['id'], 'desvincular',
                    ['cliente_id' => $clienteId, 'login' => $atual['login'],
                     'caixa' => $atual['caixa_nome'], 'numero' => (int) $atual['numero']],
                    null, (int) $atual['regiao_id']);
            }

            Db::exec(
                'INSERT INTO tab_ftth_porta (splitter_id, numero, cliente_id, login, criado_por, criado_em)
                 VALUES (?,?,?,?,?,NOW())',
                [$splitterId, $numero, $clienteId, $cliente['login'], $usuario]
            );
            $id = Db::ultimoId();
            Auditoria::registrar('porta', $id, 'vincular', null,
                ['cliente_id' => $clienteId, 'login' => $cliente['login'],
                 'caixa' => $splitter['caixa_nome'], 'splitter' => $splitter['nome'], 'numero' => $numero],
                (int) $splitter['regiao_id']);

            $r = Resultado::ok(['id' => $id] + self::resumoVinculo($splitter, $numero, $cliente));
            $r = Sincronizacao::cliente($clienteId, $r, $usuario);
            $r = Sincronizacao::caixa((int) $splitter['caixa_id'], $r, $usuario);
            if ($caixaAnterior && $caixaAnterior !== (int) $splitter['caixa_id']) {
                $r = Sincronizacao::caixa($caixaAnterior, $r, $usuario);
            }
            return $r;
        });
    }

    /**
     * Desvincula o cliente. Apaga de verdade — a porta não tem soft delete: se tivesse, o
     * UNIQUE deixaria a saída presa para sempre. O histórico fica em tab_ftth_historico.
     */
    public static function desvincularCliente(int $clienteId, string $usuario): Resultado
    {
        $atual = self::portaDoCliente($clienteId);
        if (!$atual) {
            return Resultado::erro('FTTH-SYS-002', ['cliente_id' => $clienteId],
                'Este cliente não está vinculado a nenhuma porta.');
        }

        return Db::transacao(function () use ($clienteId, $atual, $usuario) {
            Db::exec('DELETE FROM tab_ftth_porta WHERE id = ?', [(int) $atual['id']]);
            Auditoria::registrar('porta', (int) $atual['id'], 'desvincular',
                ['cliente_id' => $clienteId, 'login' => $atual['login'],
                 'caixa' => $atual['caixa_nome'], 'splitter' => $atual['splitter_nome'],
                 'numero' => (int) $atual['numero']],
                null, (int) $atual['regiao_id']);

            $r = Resultado::ok(['cliente_id' => $clienteId, 'caixa_id' => (int) $atual['caixa_id']]);
            $r = Sincronizacao::limparCliente($clienteId, $r, $usuario);
            return Sincronizacao::caixa((int) $atual['caixa_id'], $r, $usuario);
        });
    }

    // ------------------------------------------------------------------ conectividade (o grafo)

    /**
     * FTTH Connection Matrix — quais pares de pontas podem virar ligação.
     *
     * A política mora AQUI, declarada, e não espalhada em `if` pelo serviço nem no JavaScript.
     * A tela lê a mesma matriz só para decidir o que realçar; quem decide de verdade é este
     * serviço, e um POST adulterado bate na mesma parede.
     *
     * A chave é o par de elementos em ordem alfabética (ver `chavePar`), e o valor é o tipo
     * padrão da ligação. Par que não está aqui é proibido — inclusive tudo que envolve
     * DIO_PORTA, que o domínio conhece mas quem edita é o futuro editor de POP/DIO.
     */
    public const MATRIZ = [
        'SPLITTER_IN|VAO_FIBRA'    => 'FUSAO',  // fibra alimenta o splitter
        'SPLITTER_OUT|VAO_FIBRA'   => 'FUSAO',  // saída alimenta o próximo trecho ou o drop
        'VAO_FIBRA|VAO_FIBRA'      => 'FUSAO',  // emenda de passagem: o que documenta uma CEO
        'SPLITTER_IN|SPLITTER_OUT' => 'FUSAO',  // derivação alimentando atendimento (splitters DIFERENTES)
        'DIO_PORTA|VAO_FIBRA'      => 'CONECTOR', // porta do DIO na fibra da rua: a origem do circuito
    ];

    public const ELEMENTOS_EDITAVEIS = ['VAO_FIBRA', 'SPLITTER_IN', 'SPLITTER_OUT', 'DIO_PORTA'];

    /**
     * `DIO_PORTA` só existe dentro do POP.
     *
     * A porta do DIO é equipamento de inside plant: numa CTO de poste não há DIO nenhum, e
     * aceitar a ponta ali seria documentar uma rede que não existe. Quem informa a ligação é
     * a tela do Data Center, não o diagrama — mas a regra vive aqui, no serviço, para um POST
     * adulterado bater na mesma parede.
     */
    private static function elementoPermitidoNaCaixa(string $elemento, int $caixaId): bool
    {
        if ($elemento !== 'DIO_PORTA') {
            return true;
        }
        return (string) Db::valor('SELECT tipo FROM tab_ftth_caixa WHERE id = ?', [$caixaId]) === 'DC';
    }

    /** Tipos de ligação. PASSAGEM fica reservado para a geração automática da próxima rodada. */
    public const TIPOS_LIGACAO = ['FUSAO', 'CONECTOR'];

    private static function chavePar(string $a, string $b): string
    {
        $par = [$a, $b];
        sort($par);
        return $par[0] . '|' . $par[1];
    }

    public static function parPermitido(string $a, string $b): bool
    {
        return isset(self::MATRIZ[self::chavePar($a, $b)]);
    }

    /**
     * Liga duas pontas dentro de uma caixa.
     *
     * Ponta: ['elemento' => 'VAO_FIBRA'|'SPLITTER_IN'|'SPLITTER_OUT', 'elemento_id' => n, 'numero' => n].
     * SPLITTER_IN ignora `numero` (é sempre 0, como manda o comentário da migration 008).
     */
    public static function conectar(int $caixaId, array $pontaA, array $pontaB,
                                    ?string $tipo, string $usuario): Resultado
    {
        $a = self::normalizarPonta($pontaA);
        $b = self::normalizarPonta($pontaB);
        if ($a === null || $b === null) {
            return Resultado::erro('FTTH-SYS-002', ['campo' => 'ponta'], 'Ponta inválida na requisição.');
        }

        // 1. o par existe na matriz, e os dois elementos cabem neste tipo de caixa?
        if (!self::parPermitido($a['elemento'], $b['elemento'])) {
            return Resultado::erro('FTTH-TOP-017',
                ['a' => $a['elemento'], 'b' => $b['elemento']]);
        }
        foreach ([$a, $b] as $ponta) {
            if (!self::elementoPermitidoNaCaixa($ponta['elemento'], $caixaId)) {
                return Resultado::erro('FTTH-TOP-017',
                    ['elemento' => $ponta['elemento'], 'caixa' => $caixaId],
                    'Porta de DIO só existe em caixa do tipo DC (o POP).');
            }
        }
        // 2. a mesma ponta dos dois lados
        if ($a['elemento'] === $b['elemento'] && $a['elemento_id'] === $b['elemento_id']
            && $a['numero'] === $b['numero']) {
            return Resultado::erro('FTTH-TOP-008', ['ponta' => $a]);
        }
        // 3. entrada e saída do MESMO splitter é laço interno
        if (self::chavePar($a['elemento'], $b['elemento']) === 'SPLITTER_IN|SPLITTER_OUT'
            && $a['elemento_id'] === $b['elemento_id']) {
            return Resultado::erro('FTTH-TOP-018', ['splitter' => $a['elemento_id']]);
        }
        // 3b. saída de ATENDIMENTO é o fim da linha: dali sai o drop do cliente, que não
        // é documentado no diagrama. Quem encadeia splitter e alimenta o próximo trecho é
        // a DERIVAÇÃO. Fundir uma saída de atendimento seria documentar rede que não existe.
        foreach ([$a, $b] as $ponta) {
            if ($ponta['elemento'] !== 'SPLITTER_OUT') {
                continue;
            }
            $funcao = Db::valor('SELECT funcao FROM tab_ftth_splitter WHERE id = ?',
                                [$ponta['elemento_id']]);
            if ($funcao === 'ATENDIMENTO') {
                return Resultado::erro('FTTH-TOP-020',
                    ['splitter' => $ponta['elemento_id'], 'saida' => $ponta['numero']]);
            }
        }

        // 4 e 5. o elemento existe, é desta caixa, e o número está na faixa
        foreach ([$a, $b] as $ponta) {
            $erro = self::validarPonta($caixaId, $ponta);
            if ($erro !== null) {
                return $erro;
            }
        }
        // 6. as duas pontas estão livres
        foreach ([$a, $b] as $ponta) {
            $ocupada = self::pontaOcupada($caixaId, $ponta);
            if ($ocupada !== null) {
                return Resultado::erro(self::codigoPontaOcupada($ponta),
                    ['ponta' => $ponta, 'ligacao' => $ocupada]);
            }
        }

        $tipo = in_array((string) $tipo, self::TIPOS_LIGACAO, true)
            ? (string) $tipo
            : (self::ehPassagem($a, $b)
                ? 'PASSAGEM'
                : self::MATRIZ[self::chavePar($a['elemento'], $b['elemento'])]);

        try {
            return Db::transacao(function () use ($caixaId, $a, $b, $tipo, $usuario) {
                Db::exec('INSERT INTO tab_ftth_ligacao (caixa_id, tipo, criado_por, criado_em)
                          VALUES (?,?,?,NOW())', [$caixaId, $tipo, $usuario]);
                $ligacaoId = Db::ultimoId();

                foreach ([['A', $a], ['B', $b]] as [$lado, $ponta]) {
                    Db::exec(
                        'INSERT INTO tab_ftth_ligacao_ponta
                            (ligacao_id, lado, caixa_id, elemento, elemento_id, numero)
                         VALUES (?,?,?,?,?,?)',
                        [$ligacaoId, $lado, $caixaId, $ponta['elemento'],
                         $ponta['elemento_id'], $ponta['numero']]
                    );
                }

                Auditoria::registrar('ligacao', $ligacaoId, 'conectar', null,
                    ['tipo' => $tipo, 'a' => $a, 'b' => $b], self::regiaoDaCaixa($caixaId));

                return Resultado::ok(['id' => $ligacaoId, 'caixa_id' => $caixaId,
                                      'tipo' => $tipo, 'a' => $a, 'b' => $b]);
            });
        } catch (PDOException $e) {
            // Corrida: os dois passaram pela validação e o índice único barrou o segundo.
            // Vira o erro do catálogo, não o FTTH-SYS-001 genérico nem SQL cru na tela.
            if ((string) $e->getCode() === '23000') {
                foreach ([$a, $b] as $ponta) {
                    if (self::pontaOcupada($caixaId, $ponta) !== null) {
                        Log::aviso('topologia.conectar.corrida',
                            ['caixa' => $caixaId, 'ponta' => $ponta]);
                        return Resultado::erro(self::codigoPontaOcupada($ponta), ['ponta' => $ponta]);
                    }
                }
                return Resultado::erro('FTTH-TOP-004', ['caixa' => $caixaId]);
            }
            throw $e;
        }
    }

    /**
     * A ligação é uma SANGRIA e não uma emenda?
     *
     * Numa caixa de passagem o técnico abre o cabo, quebra só a fibra que vai usar e as
     * outras seguem inteiras — elas não passam por emenda nenhuma, então não perdem nada.
     * Tratar isso como fusão inventa 0,10 dB por caixa, e numa rota de 10 CEOs vira 1 dB.
     *
     * A assinatura da sangria é a fibra continuar sendo ela mesma: cabos da MESMA bitola e
     * o MESMO número dos dois lados. Fo03 com Fo07, ou 12 FO com 6 FO, exigem corte e
     * emenda de verdade — ali a perda existe (decisão de 22/09/2026).
     */
    public static function ehPassagem(array $a, array $b): bool
    {
        if ($a['elemento'] !== 'VAO_FIBRA' || $b['elemento'] !== 'VAO_FIBRA') {
            return false;
        }
        if ((int) $a['numero'] !== (int) $b['numero']) {
            return false;
        }
        $tipoA = Db::valor(
            'SELECT c.cabo_tipo_id FROM tab_ftth_cabo_vao v
               JOIN tab_ftth_cabo c ON c.id = v.cabo_id WHERE v.id = ?', [$a['elemento_id']]);
        $tipoB = Db::valor(
            'SELECT c.cabo_tipo_id FROM tab_ftth_cabo_vao v
               JOIN tab_ftth_cabo c ON c.id = v.cabo_id WHERE v.id = ?', [$b['elemento_id']]);

        return $tipoA !== null && $tipoA === $tipoB;
    }

    /**
     * Alterna a ligação entre passagem e fusão.
     *
     * A trava importante: só vira PASSAGEM o que o `ehPassagem` admite. Sem ela, daria
     * para zerar à mão a perda de um splitter ou de uma emenda real, e o cálculo passaria
     * a mentir sem deixar rastro na tela.
     */
    public static function alterarTipoLigacao(int $ligacaoId, string $tipo, string $usuario): Resultado
    {
        $tipo = strtoupper(trim($tipo));
        if (!in_array($tipo, ['FUSAO', 'PASSAGEM'], true)) {
            return Resultado::erro('FTTH-SYS-002', ['campo' => 'tipo'],
                'Só é possível alternar entre fusão e passagem.');
        }

        $ligacao = Db::um('SELECT * FROM tab_ftth_ligacao WHERE id = ?', [$ligacaoId]);
        if (!$ligacao) {
            return Resultado::erro('FTTH-TOP-015', ['ligacao' => $ligacaoId]);
        }

        $pontas = Db::todos(
            'SELECT lado, elemento, elemento_id, numero FROM tab_ftth_ligacao_ponta
              WHERE ligacao_id = ? ORDER BY lado', [$ligacaoId]);
        if (count($pontas) !== 2) {
            return Resultado::erro('FTTH-TOP-006', ['ligacao' => $ligacaoId]);
        }

        if ($tipo === 'PASSAGEM' && !self::ehPassagem($pontas[0], $pontas[1])) {
            return Resultado::erro('FTTH-TOP-021', ['ligacao' => $ligacaoId]);
        }
        if ($ligacao['tipo'] === $tipo) {
            return Resultado::ok(['id' => $ligacaoId, 'tipo' => $tipo]);
        }

        return Db::transacao(function () use ($ligacaoId, $ligacao, $tipo, $usuario) {
            Db::exec('UPDATE tab_ftth_ligacao SET tipo = ?, alterado_por = ?, alterado_em = NOW()
                       WHERE id = ?', [$tipo, $usuario, $ligacaoId]);
            Auditoria::registrar('ligacao', $ligacaoId, 'alterar',
                ['tipo' => $ligacao['tipo']], ['tipo' => $tipo],
                self::regiaoDaCaixa((int) $ligacao['caixa_id']));

            return Resultado::ok(['id' => $ligacaoId, 'tipo' => $tipo]);
        });
    }

    /**
     * Desfaz a ligação. Apaga de verdade — as pontas saem por ON DELETE CASCADE — porque o
     * índice único deixaria a fibra presa para sempre se houvesse soft delete (decisão da 008).
     * A rastreabilidade não se perde: o estado anterior completo vai para tab_ftth_historico.
     */
    public static function desconectar(int $ligacaoId, string $usuario): Resultado
    {
        $ligacao = Db::um('SELECT * FROM tab_ftth_ligacao WHERE id = ?', [$ligacaoId]);
        if (!$ligacao) {
            return Resultado::erro('FTTH-TOP-015', ['ligacao' => $ligacaoId]);
        }
        $pontas = Db::todos(
            'SELECT lado, elemento, elemento_id, numero FROM tab_ftth_ligacao_ponta
              WHERE ligacao_id = ? ORDER BY lado', [$ligacaoId]);

        return Db::transacao(function () use ($ligacaoId, $ligacao, $pontas, $usuario) {
            Auditoria::registrar('ligacao', $ligacaoId, 'desconectar',
                ['tipo' => $ligacao['tipo'], 'caixa_id' => (int) $ligacao['caixa_id'],
                 'pontas' => $pontas],
                null, self::regiaoDaCaixa((int) $ligacao['caixa_id']));
            Db::exec('DELETE FROM tab_ftth_ligacao WHERE id = ?', [$ligacaoId]);

            return Resultado::ok(['id' => $ligacaoId, 'caixa_id' => (int) $ligacao['caixa_id'],
                                  'pontas' => $pontas]);
        });
    }

    /**
     * Liga dois cabos fibra a fibra, na ordem: Fo01 com Fo01, Fo02 com Fo02, até onde o
     * menor dos dois alcança.
     *
     * É o serviço de um cabo passante numa CEO, que a mão faria em 24 cliques. O tipo de
     * cada ligação sai do `conectar()` de sempre — mesma bitola e mesmo número dá PASSAGEM
     * (sem perda), bitolas diferentes dá FUSAO —, então a emenda automática conta a mesma
     * história que a manual.
     *
     * Fibra já ocupada é PULADA, não é erro: numa CEO meio fusionada, que é o caso comum,
     * isso deixa completar o serviço sem desfazer o que já existe. E não há transação
     * envolvendo tudo de propósito: ligar 20 de 24 é progresso real, não meia falha.
     *
     * Roda em simulação quando `$aplicar` é false — e a tela sempre mostra antes.
     */
    public static function ligarCabos(int $caixaId, int $vaoA, int $vaoB, bool $aplicar,
                                      string $usuario): Resultado
    {
        if ($vaoA === $vaoB) {
            return Resultado::erro('FTTH-SYS-002', ['campo' => 'cabo'],
                'Escolha dois cabos diferentes.');
        }

        // `fibrasDoVao` devolve as fibras de qualquer vão — o filtro de caixa ali serve só
        // para saber quais estão ocupadas AQUI. Quem confere se o cabo realmente encosta
        // nesta caixa é o `conectar`, e a simulação não passa por ele: sem esta checagem,
        // a prévia mostraria pares que a aplicação recusaria um a um.
        foreach ([$vaoA, $vaoB] as $vao) {
            $encosta = Db::valor(
                'SELECT id FROM tab_ftth_cabo_vao
                  WHERE id = ? AND excluido_em IS NULL
                    AND (caixa_ini_id = ? OR caixa_fim_id = ?)', [$vao, $caixaId, $caixaId]);
            if (!$encosta) {
                return Resultado::erro('FTTH-TOP-002', ['vao' => $vao, 'caixa' => $caixaId],
                    'Este cabo não encosta nesta caixa.');
            }
        }

        $fibrasA = self::fibrasDoVao($vaoA, $caixaId);
        $fibrasB = self::fibrasDoVao($vaoB, $caixaId);
        if (!$fibrasA || !$fibrasB) {
            return Resultado::erro('FTTH-TOP-002', ['vao' => $fibrasA ? $vaoB : $vaoA]);
        }

        $quantas = min(count($fibrasA), count($fibrasB));
        $pares   = [];
        $ligadas = 0;
        $puladas = 0;

        for ($n = 1; $n <= $quantas; $n++) {
            $a = $fibrasA[$n - 1];
            $b = $fibrasB[$n - 1];

            $ocupada = null;
            if ($a['estado'] !== 'livre') {
                $ocupada = 'a fibra ' . $a['rotulo'] . ' do primeiro cabo já está conectada';
            } elseif ($b['estado'] !== 'livre') {
                $ocupada = 'a fibra ' . $b['rotulo'] . ' do segundo cabo já está conectada';
            }
            if ($ocupada !== null) {
                $pares[] = ['numero' => $n, 'estado' => 'pulada', 'motivo' => $ocupada];
                $puladas++;
                continue;
            }

            $pa = ['elemento' => 'VAO_FIBRA', 'elemento_id' => $vaoA, 'numero' => $n];
            $pb = ['elemento' => 'VAO_FIBRA', 'elemento_id' => $vaoB, 'numero' => $n];
            $tipo = self::ehPassagem($pa, $pb) ? 'PASSAGEM' : 'FUSAO';

            if (!$aplicar) {
                $pares[] = ['numero' => $n, 'estado' => 'ligar', 'tipo' => $tipo];
                $ligadas++;
                continue;
            }

            $r = self::conectar($caixaId, $pa, $pb, null, $usuario);
            if ($r->ok) {
                $pares[] = ['numero' => $n, 'estado' => 'ligada', 'tipo' => $tipo,
                            'ligacao_id' => (int) $r->data['id']];
                $ligadas++;
            } else {
                // Só chega aqui numa corrida com outro usuário: a ocupação já foi checada.
                $pares[] = ['numero' => $n, 'estado' => 'pulada',
                            'motivo' => $r->primeiraMensagem()];
                $puladas++;
            }
        }

        return Resultado::ok([
            'aplicado' => $aplicar,
            'total'    => $quantas,
            'ligadas'  => $ligadas,
            'puladas'  => $puladas,
            'pares'    => $pares,
            'fibras_a' => count($fibrasA),
            'fibras_b' => count($fibrasB),
        ]);
    }

    /**
     * Desfaz várias ligações de uma vez — o inverso do `ligarCabos`.
     *
     * Existe para o Desfazer da tela: sem ele, desfazer uma fusão de 24 fibras seriam 24
     * requisições e 24 recargas do diagrama. Uma ligação que já não existe não é erro: o
     * objetivo é o estado final, e ele foi alcançado.
     */
    public static function desconectarVarias(array $ligacaoIds, string $usuario): Resultado
    {
        $desfeitas = 0;
        $erros     = [];

        foreach ($ligacaoIds as $id) {
            $id = (int) $id;
            if ($id <= 0) {
                continue;
            }
            if (!Db::valor('SELECT id FROM tab_ftth_ligacao WHERE id = ?', [$id])) {
                continue;                      // já não existe: nada a fazer
            }
            $r = self::desconectar($id, $usuario);
            if ($r->ok) {
                $desfeitas++;
            } else {
                $erros[] = ['ligacao' => $id, 'motivo' => $r->primeiraMensagem()];
            }
        }

        $res = Resultado::ok(['desfeitas' => $desfeitas, 'pedidas' => count($ligacaoIds),
                              'falhas' => $erros]);
        return $erros ? $res->addAviso('FTTH-TOP-015', ['falhas' => count($erros)]) : $res;
    }

    /** As ligações da caixa, com as duas pontas resolvidas. */
    public static function ligacoes(int $caixaId): array
    {
        $ligacoes = [];
        foreach (Db::todos('SELECT id, tipo, perda_medida_db FROM tab_ftth_ligacao WHERE caixa_id = ?',
                           [$caixaId]) as $l) {
            $ligacoes[(int) $l['id']] = [
                'id'              => (int) $l['id'],
                'tipo'            => $l['tipo'],
                'perda_medida_db' => $l['perda_medida_db'] === null ? null : (float) $l['perda_medida_db'],
                'a'               => null,
                'b'               => null,
            ];
        }
        if (!$ligacoes) {
            return [];
        }

        foreach (Db::todos(
            'SELECT ligacao_id, lado, elemento, elemento_id, numero
               FROM tab_ftth_ligacao_ponta WHERE caixa_id = ?', [$caixaId]) as $p) {
            $id = (int) $p['ligacao_id'];
            if (isset($ligacoes[$id])) {
                $ligacoes[$id][strtolower($p['lado'])] = [
                    'elemento'    => $p['elemento'],
                    'elemento_id' => (int) $p['elemento_id'],
                    'numero'      => (int) $p['numero'],
                ];
            }
        }
        return array_values($ligacoes);
    }

    /**
     * As fibras de um vão: número, rótulo, tubo, cor da norma e o estado de cada uma.
     * Fibra não tem tabela própria (I2) — ela é o par vão + número, então a lista é gerada
     * a partir da capacidade do tipo de cabo.
     */
    public static function fibrasDoVao(int $vaoId, int $caixaId): array
    {
        $vao = Db::um(
            'SELECT v.id, v.cabo_id, c.padrao_cores, t.fibras, t.fibras_por_tubo
               FROM tab_ftth_cabo_vao v
               JOIN tab_ftth_cabo c      ON c.id = v.cabo_id
               JOIN tab_ftth_cabo_tipo t ON t.id = c.cabo_tipo_id
              WHERE v.id = ? AND v.excluido_em IS NULL', [$vaoId]);
        if (!$vao) {
            return [];
        }

        // O filtro por caixa é essencial, não detalhe: a fibra atravessa o vão e tem uma
        // ponta em CADA caixa. Fundida na CEO e livre na CTO é o caso normal. Sem ele, o
        // diagrama de uma caixa mostrava como conectada uma fibra que foi emendada na
        // outra ponta — sem linha nenhuma no desenho, e sem deixar ligá-la aqui.
        $ocupadas = [];
        foreach (Db::todos(
            'SELECT numero, ligacao_id FROM tab_ftth_ligacao_ponta
              WHERE elemento = "VAO_FIBRA" AND elemento_id = ? AND caixa_id = ?',
            [$vaoId, $caixaId]) as $p) {
            $ocupadas[(int) $p['numero']] = (int) $p['ligacao_id'];
        }

        $porTubo = (int) $vao['fibras_por_tubo'];
        $fibras  = [];
        for ($n = 1; $n <= (int) $vao['fibras']; $n++) {
            $fibras[] = Fibra::descrever($n, (string) $vao['padrao_cores'], $porTubo) + [
                'ligacao_id' => $ocupadas[$n] ?? null,
                'estado'     => isset($ocupadas[$n]) ? 'conectada' : 'livre',
            ];
        }
        return $fibras;
    }

    /**
     * O payload inteiro do diagrama da caixa, numa chamada só: nós, ligações e layout.
     *
     * O que volta daqui é reconstruído do banco a cada abertura — o desenho nunca é a verdade.
     */
    public static function diagrama(int $caixaId): array
    {
        $caixa = Db::um(
            'SELECT c.id, c.nome, c.tipo, c.regiao_id, c.versao, r.nome AS regiao
               FROM tab_ftth_caixa c
               JOIN tab_ftth_regiao r ON r.id = c.regiao_id
              WHERE c.id = ? AND c.excluido_em IS NULL', [$caixaId]);
        if (!$caixa) {
            return [];
        }

        // Vãos que encostam na caixa. O "sentido" é a caixa do outro lado — é assim que o
        // pessoal de campo chama o cabo ("ST CEO.01" = o que vai para a CEO.01).
        $vaos = [];
        foreach (Db::todos(
            'SELECT v.id, v.cabo_id, v.caixa_ini_id, v.caixa_fim_id, v.comprimento_optico,
                    cb.nome AS cabo_nome, cb.padrao_cores, cb.cabo_tipo_id,
                    t.rotulo AS tipo_rotulo, t.fibras, t.fibras_por_tubo, t.construcao
               FROM tab_ftth_cabo_vao v
               JOIN tab_ftth_cabo cb     ON cb.id = v.cabo_id
               JOIN tab_ftth_cabo_tipo t ON t.id = cb.cabo_tipo_id
              WHERE (v.caixa_ini_id = ? OR v.caixa_fim_id = ?)
                AND v.excluido_em IS NULL AND cb.excluido_em IS NULL
              ORDER BY v.id', [$caixaId, $caixaId]) as $v) {
            $outraId = (int) $v['caixa_ini_id'] === $caixaId
                ? (int) $v['caixa_fim_id'] : (int) $v['caixa_ini_id'];
            $outra = Db::valor('SELECT nome FROM tab_ftth_caixa WHERE id = ?', [$outraId]);

            $vaos[] = [
                'id'          => (int) $v['id'],
                'cabo_id'     => (int) $v['cabo_id'],
                'cabo_nome'   => $v['cabo_nome'],
                'tipo_rotulo' => $v['tipo_rotulo'],
                'construcao'  => $v['construcao'],
                'padrao'      => $v['padrao_cores'],
                'fibras_por_tubo' => (int) $v['fibras_por_tubo'],
                'sentido_id'  => $outraId,
                'sentido'     => 'ST ' . (string) $outra,
                'comprimento_optico' => (float) $v['comprimento_optico'],
                'fibras'      => self::fibrasDoVao((int) $v['id'], $caixaId),
            ];
        }

        // Splitters, com as saídas e quem está em cada uma.
        $splitters = [];
        foreach (self::splitters($caixaId) as $s) {
            $splitterId = (int) $s['id'];
            $clientes = [];
            foreach (Db::todos('SELECT numero, cliente_id, login FROM tab_ftth_porta WHERE splitter_id = ?',
                               [$splitterId]) as $p) {
                $clientes[(int) $p['numero']] = ['cliente_id' => (int) $p['cliente_id'],
                                                 'login' => $p['login']];
            }
            $ligadas = [];
            foreach (Db::todos(
                'SELECT elemento, numero, ligacao_id FROM tab_ftth_ligacao_ponta
                  WHERE elemento IN ("SPLITTER_IN","SPLITTER_OUT") AND elemento_id = ?',
                [$splitterId]) as $p) {
                $ligadas[$p['elemento'] . ':' . (int) $p['numero']] = (int) $p['ligacao_id'];
            }

            // Numa saída de ATENDIMENTO quem ocupa é o cliente, não uma fusão: o drop da
            // última milha não é documentado aqui. Então a bolinha cheia tem de significar
            // "tem cliente", que é o que se procura ao abrir uma CTO.
            $atendimento = $s['funcao'] === 'ATENDIMENTO';

            $saidas = [];
            for ($n = 1; $n <= (int) $s['saidas']; $n++) {
                $ocupada = $atendimento
                         ? isset($clientes[$n])
                         : isset($ligadas['SPLITTER_OUT:' . $n]);
                $saidas[] = [
                    'numero'     => $n,
                    'rotulo'     => 'T' . str_pad((string) $n, 2, '0', STR_PAD_LEFT),
                    'ligacao_id' => $ligadas['SPLITTER_OUT:' . $n] ?? null,
                    'cliente'    => $clientes[$n] ?? null,
                    'estado'     => $ocupada ? 'conectada' : 'livre',
                    // O diagrama usa isto para nunca oferecer fusão nessa saída.
                    'so_cliente' => $atendimento,
                ];
            }
            $entradaLigacao = $ligadas['SPLITTER_IN:0'] ?? null;

            $splitters[] = $s + [
                'id'              => $splitterId,
                'entrada_ligacao' => $entradaLigacao,
                'saidas_lista'    => $saidas,
                // Estado incompleto é legítimo durante a edição, mas não pode parecer correto.
                'sem_alimentacao' => $entradaLigacao === null,
            ];
        }

        return [
            'caixa'     => $caixa,
            'vaos'      => $vaos,
            'splitters' => $splitters,
            'ligacoes'  => self::ligacoes($caixaId),
            'layout'    => self::layout($caixaId, $vaos, $splitters),
            'matriz'    => array_keys(self::MATRIZ),
        ];
    }

    /**
     * Posição de cada nó. Quem ainda não tem linha em tab_ftth_diagrama_no ganha um lugar
     * calculado: cabos alternando entre a coluna da esquerda e a da direita, splitters no meio.
     */
    public static function layout(int $caixaId, array $vaos, array $splitters): array
    {
        $salvos = [];
        foreach (Db::todos(
            'SELECT tipo, elemento_id, pos_x, pos_y, rotacao, invertido
               FROM tab_ftth_diagrama_no WHERE caixa_id = ?', [$caixaId]) as $n) {
            $salvos[$n['tipo'] . ':' . (int) $n['elemento_id']] = [
                'pos_x'     => (int) $n['pos_x'],
                'pos_y'     => (int) $n['pos_y'],
                'rotacao'   => (int) $n['rotacao'],
                'invertido' => (int) $n['invertido'] === 1,
                // Posição escolhida por alguém: a tela não mexe.
                'auto'      => false,
            ];
        }

        $layout = [];
        $esq = 0;
        $dir = 0;
        foreach ($vaos as $i => $v) {
            $chave = 'VAO:' . $v['id'];
            if ($i % 2 === 0) {
                $padrao = ['pos_x' => 40,  'pos_y' => 40 + ($esq++ * 220)];
            } else {
                $padrao = ['pos_x' => 620, 'pos_y' => 40 + ($dir++ * 220)];
            }
            $layout[$chave] = $salvos[$chave]
                ?? $padrao + ['rotacao' => 0, 'invertido' => false, 'auto' => true];
        }
        foreach ($splitters as $i => $s) {
            $chave = 'SPLITTER:' . $s['id'];
            // `auto` diz à tela que ninguém escolheu este lugar ainda: ela procura um
            // vazio antes de desenhar, senão o nó novo nasce em cima de outro. O servidor
            // não tem como fazer essa conta — a altura do nó depende do desenho.
            $layout[$chave] = $salvos[$chave]
                ?? ['pos_x' => 340, 'pos_y' => 60 + ($i * 200),
                    'rotacao' => 0, 'invertido' => false, 'auto' => true];
        }
        return $layout;
    }


    /**
     * Dos dois vãos que chegam nesta caixa, qual é o que vem do POP?
     *
     * Serve para o diagrama nascer na leitura de quem trabalha nele: o cabo que vem do
     * DC/POP à esquerda, o que segue para a rua à direita. Duas perguntas, nessa ordem:
     *
     *   1. por onde chega o sinal — o motor de potência já propaga a partir das portas do
     *      DIO, então uma fibra com dBm neste vão é a resposta direta;
     *   2. sem sinal (rede ainda não ligada, ou trecho isolado), anda pelo traçado contando
     *      saltos até encontrar uma caixa do tipo DC. Vence o ramo que chegar mais perto.
     *
     * Devolve null quando os dois lados empatam — aí não há por que preferir um.
     */
    public static function vaoQueVemDoPop(int $caixaId, int $vaoA, int $vaoB): ?int
    {
        require_once __DIR__ . '/Potencia.php';

        $temA = false;
        $temB = false;
        foreach (Potencia::comSinal($caixaId) as $chave => $dbm) {
            $p = explode(':', $chave);
            if (($p[0] ?? '') !== 'VAO_FIBRA') {
                continue;
            }
            if ((int) ($p[1] ?? 0) === $vaoA) { $temA = true; }
            if ((int) ($p[1] ?? 0) === $vaoB) { $temB = true; }
        }
        if ($temA !== $temB) {
            return $temA ? $vaoA : $vaoB;
        }

        $saltosA = self::saltosAteODC($caixaId, $vaoA);
        $saltosB = self::saltosAteODC($caixaId, $vaoB);
        if ($saltosA === $saltosB) {
            return null;
        }
        if ($saltosA === null) { return $vaoB; }
        if ($saltosB === null) { return $vaoA; }
        return $saltosA < $saltosB ? $vaoA : $vaoB;
    }

    /**
     * Quantos trechos de cabo separam esta caixa de um DC, começando pelo vão indicado.
     * Caminha pela planta (caixas e vãos), não pelas fusões: serve justamente para quando
     * ainda não há fibra ligada. Devolve null se aquele lado não leva a nenhum DC.
     */
    private static function saltosAteODC(int $caixaId, int $vaoInicial): ?int
    {
        $vao = Db::um('SELECT caixa_ini_id, caixa_fim_id FROM tab_ftth_cabo_vao
                        WHERE id = ? AND excluido_em IS NULL', [$vaoInicial]);
        if (!$vao) {
            return null;
        }
        $proxima = (int) $vao['caixa_ini_id'] === $caixaId
            ? (int) $vao['caixa_fim_id']
            : (int) $vao['caixa_ini_id'];

        $vistas = [$caixaId => true];
        $fila   = [[$proxima, 1]];

        while ($fila) {
            [$atual, $saltos] = array_shift($fila);
            if (isset($vistas[$atual])) {
                continue;
            }
            $vistas[$atual] = true;

            $tipo = Db::valor('SELECT tipo FROM tab_ftth_caixa WHERE id = ? AND excluido_em IS NULL', [$atual]);
            if ($tipo === 'DC') {
                return $saltos;
            }
            // Um limite baixo é suficiente: se o POP estiver a mais de 40 caixas daqui, a
            // diferença entre os dois lados já não ajuda ninguém a ler o diagrama.
            if ($saltos >= 40) {
                continue;
            }

            foreach (Db::todos(
                'SELECT caixa_ini_id, caixa_fim_id FROM tab_ftth_cabo_vao
                  WHERE (caixa_ini_id = ? OR caixa_fim_id = ?) AND excluido_em IS NULL',
                [$atual, $atual]) as $v) {
                $vizinha = (int) $v['caixa_ini_id'] === $atual
                    ? (int) $v['caixa_fim_id']
                    : (int) $v['caixa_ini_id'];
                if (!isset($vistas[$vizinha])) {
                    $fila[] = [$vizinha, $saltos + 1];
                }
            }
        }
        return null;
    }

    /**
     * Posiciona os dois cabos de uma emenda recém-criada: o que vem do POP à esquerda, o que
     * segue para a rua à direita e ESPELHADO, para as fibras ficarem de frente umas para as
     * outras. É como o técnico desenharia no papel, e é o que se vê ao abrir a caixa.
     *
     * Nunca mexe num nó que já tem posição salva: se alguém já organizou este diagrama,
     * a organização é dele.
     */
    public static function organizarEmenda(int $caixaId, int $vaoA, int $vaoB, string $usuario): void
    {
        $jaPosicionados = array_column(Db::todos(
            'SELECT elemento_id FROM tab_ftth_diagrama_no WHERE caixa_id = ? AND tipo = "VAO"',
            [$caixaId]), 'elemento_id');
        $jaPosicionados = array_map('intval', $jaPosicionados);
        if (in_array($vaoA, $jaPosicionados, true) || in_array($vaoB, $jaPosicionados, true)) {
            return;
        }

        $doPop = self::vaoQueVemDoPop($caixaId, $vaoA, $vaoB) ?? $vaoA;
        $daRua = $doPop === $vaoA ? $vaoB : $vaoA;

        self::salvarLayout($caixaId, [
            ['tipo' => 'VAO', 'elemento_id' => $doPop, 'pos_x' => 40,  'pos_y' => 40,
             'rotacao' => 0, 'invertido' => false],
            ['tipo' => 'VAO', 'elemento_id' => $daRua, 'pos_x' => 620, 'pos_y' => 40,
             'rotacao' => 0, 'invertido' => true],
        ], $usuario);
    }
    /**
     * Grava a posição dos nós. Só os que a tela mandou — assim dois técnicos mexendo em nós
     * diferentes da mesma caixa não derrubam o trabalho um do outro.
     *
     * Layout NÃO é topologia: sem lock otimista, sem auditoria, sem snapshot.
     */
    public static function salvarLayout(int $caixaId, array $nos, string $usuario): Resultado
    {
        if (!Db::valor('SELECT id FROM tab_ftth_caixa WHERE id = ? AND excluido_em IS NULL', [$caixaId])) {
            return Resultado::erro('FTTH-TOP-001', ['caixa' => $caixaId]);
        }

        return Db::transacao(function () use ($caixaId, $nos) {
            $gravados = 0;
            foreach ($nos as $no) {
                $tipo = strtoupper((string) ($no['tipo'] ?? ''));
                $id   = (int) ($no['elemento_id'] ?? 0);
                if (!in_array($tipo, ['VAO', 'SPLITTER', 'DIO'], true) || $id <= 0) {
                    continue;
                }
                if (!self::noPertenceACaixa($caixaId, $tipo, $id)) {
                    continue;
                }
                Db::exec(
                    'INSERT INTO tab_ftth_diagrama_no
                        (caixa_id, tipo, elemento_id, pos_x, pos_y, rotacao, invertido)
                     VALUES (?,?,?,?,?,?,?)
                     ON DUPLICATE KEY UPDATE pos_x = VALUES(pos_x), pos_y = VALUES(pos_y),
                                             rotacao = VALUES(rotacao), invertido = VALUES(invertido)',
                    [$caixaId, $tipo, $id, (int) ($no['pos_x'] ?? 0), (int) ($no['pos_y'] ?? 0),
                     (int) ($no['rotacao'] ?? 0), !empty($no['invertido']) ? 1 : 0]
                );
                $gravados++;
            }
            return Resultado::ok(['gravados' => $gravados]);
        });
    }

    /**
     * Invariantes topológicas da caixa — ferramenta de TESTE, não custo de produção.
     *
     * Em produção quem garante a integridade são as UNIQUE e as FKs da migration 008, que
     * custam zero. Aqui a checagem é explícita para as suítes exigirem lista vazia depois de
     * cada operação, e para diagnóstico quando algo estranho aparecer numa base real.
     *
     * @return array<int,array{regra:string,detalhe:array}> vazio = tudo certo
     */
    public static function invariantes(int $caixaId): array
    {
        $falhas = [];
        $pontasPorLigacao = [];

        foreach (Db::todos('SELECT id, tipo FROM tab_ftth_ligacao WHERE caixa_id = ?', [$caixaId]) as $l) {
            $pontasPorLigacao[(int) $l['id']] = [];
        }
        foreach (Db::todos(
            'SELECT p.id, p.ligacao_id, p.lado, p.elemento, p.elemento_id, p.numero
               FROM tab_ftth_ligacao_ponta p WHERE p.caixa_id = ?', [$caixaId]) as $p) {
            $lig = (int) $p['ligacao_id'];
            if (!isset($pontasPorLigacao[$lig])) {
                $falhas[] = ['regra' => 'ponta orfa', 'detalhe' => ['ponta' => (int) $p['id']]];
                continue;
            }
            $pontasPorLigacao[$lig][] = $p;
        }

        foreach ($pontasPorLigacao as $ligacaoId => $pontas) {
            if (count($pontas) !== 2) {
                $falhas[] = ['regra' => 'ligacao sem exatamente duas pontas',
                             'detalhe' => ['ligacao' => $ligacaoId, 'pontas' => count($pontas)]];
                continue;
            }
            [$a, $b] = $pontas;
            if (!self::parPermitido($a['elemento'], $b['elemento'])) {
                $falhas[] = ['regra' => 'par fora da matriz',
                             'detalhe' => ['ligacao' => $ligacaoId,
                                           'par' => $a['elemento'] . '|' . $b['elemento']]];
            }
            if ($a['elemento'] !== 'VAO_FIBRA' && $b['elemento'] !== 'VAO_FIBRA'
                && (int) $a['elemento_id'] === (int) $b['elemento_id']) {
                $falhas[] = ['regra' => 'laco no mesmo splitter',
                             'detalhe' => ['ligacao' => $ligacaoId]];
            }
            foreach ($pontas as $ponta) {
                $erro = self::validarPonta($caixaId, [
                    'elemento'    => $ponta['elemento'],
                    'elemento_id' => (int) $ponta['elemento_id'],
                    'numero'      => (int) $ponta['numero'],
                ]);
                if ($erro !== null) {
                    $falhas[] = ['regra' => 'ponta invalida',
                                 'detalhe' => ['ligacao' => $ligacaoId,
                                               'codigo' => $erro->primeiroCodigo(),
                                               'elemento' => $ponta['elemento'],
                                               'numero' => (int) $ponta['numero']]];
                }
            }
        }
        return $falhas;
    }

    // ------------------------------------------------------------------ apoio da conectividade

    /** Normaliza a ponta vinda da requisição. Devolve null quando o formato não serve. */
    private static function normalizarPonta(array $ponta): ?array
    {
        $elemento = strtoupper(trim((string) ($ponta['elemento'] ?? '')));
        $id       = (int) ($ponta['elemento_id'] ?? 0);
        if ($elemento === '' || $id <= 0) {
            return null;
        }
        if (!in_array($elemento, self::ELEMENTOS_EDITAVEIS, true)) {
            return null;
        }
        // A entrada do splitter é uma só: numero é sempre 0 (comentário da migration 008).
        $numero = $elemento === 'SPLITTER_IN' ? 0 : (int) ($ponta['numero'] ?? 0);

        return ['elemento' => $elemento, 'elemento_id' => $id, 'numero' => $numero];
    }

    /** O elemento existe, é desta caixa e o número está na faixa? Devolve o erro, ou null. */
    private static function validarPonta(int $caixaId, array $ponta): ?Resultado
    {
        if ($ponta['elemento'] === 'VAO_FIBRA') {
            $vao = Db::um(
                'SELECT v.id, t.fibras
                   FROM tab_ftth_cabo_vao v
                   JOIN tab_ftth_cabo cb     ON cb.id = v.cabo_id
                   JOIN tab_ftth_cabo_tipo t ON t.id = cb.cabo_tipo_id
                  WHERE v.id = ? AND v.excluido_em IS NULL
                    AND (v.caixa_ini_id = ? OR v.caixa_fim_id = ?)',
                [$ponta['elemento_id'], $caixaId, $caixaId]);
            if (!$vao) {
                return Resultado::erro('FTTH-TOP-002', ['vao' => $ponta['elemento_id'], 'caixa' => $caixaId]);
            }
            if ($ponta['numero'] < 1 || $ponta['numero'] > (int) $vao['fibras']) {
                return Resultado::erro('FTTH-TOP-003',
                    ['vao' => $ponta['elemento_id'], 'numero' => $ponta['numero'],
                     'capacidade' => (int) $vao['fibras']]);
            }
            return null;
        }

        if ($ponta['elemento'] === 'DIO_PORTA') {
            // A porta tem de existir e pertencer a um DIO instalado NESTE POP.
            $porta = Db::um(
                'SELECT p.id FROM tab_ftth_dio_porta p
                   JOIN tab_ftth_dio d ON d.id = p.dio_id
                  WHERE p.id = ? AND d.caixa_id = ?
                    AND p.excluido_em IS NULL AND d.excluido_em IS NULL',
                [$ponta['elemento_id'], $caixaId]);
            if (!$porta) {
                return Resultado::erro('FTTH-TOP-013',
                    ['porta' => $ponta['elemento_id'], 'caixa' => $caixaId]);
            }
            return null;
        }

        $splitter = Db::um(
            'SELECT id, saidas FROM tab_ftth_splitter
              WHERE id = ? AND caixa_id = ? AND excluido_em IS NULL',
            [$ponta['elemento_id'], $caixaId]);
        if (!$splitter) {
            return Resultado::erro('FTTH-TOP-009',
                ['splitter' => $ponta['elemento_id'], 'caixa' => $caixaId]);
        }
        if ($ponta['elemento'] === 'SPLITTER_OUT'
            && ($ponta['numero'] < 1 || $ponta['numero'] > (int) $splitter['saidas'])) {
            return Resultado::erro('FTTH-TOP-009',
                ['splitter' => $ponta['elemento_id'], 'numero' => $ponta['numero'],
                 'saidas' => (int) $splitter['saidas']],
                'Este splitter tem saídas de 1 a ' . (int) $splitter['saidas'] . '.');
        }
        return null;
    }

    /** Id da ligação que já ocupa a ponta, ou null se ela está livre. */
    private static function pontaOcupada(int $caixaId, array $ponta): ?int
    {
        $v = Db::valor(
            'SELECT ligacao_id FROM tab_ftth_ligacao_ponta
              WHERE caixa_id = ? AND elemento = ? AND elemento_id = ? AND numero = ?',
            [$caixaId, $ponta['elemento'], $ponta['elemento_id'], $ponta['numero']]);
        return $v === null ? null : (int) $v;
    }

    private static function codigoPontaOcupada(array $ponta): string
    {
        if ($ponta['elemento'] === 'VAO_FIBRA')  return 'FTTH-TOP-004';
        if ($ponta['elemento'] === 'DIO_PORTA')  return 'FTTH-TOP-014';
        return 'FTTH-TOP-005';
    }

    private static function noPertenceACaixa(int $caixaId, string $tipo, int $elementoId): bool
    {
        if ($tipo === 'VAO') {
            return (bool) Db::valor(
                'SELECT id FROM tab_ftth_cabo_vao
                  WHERE id = ? AND excluido_em IS NULL AND (caixa_ini_id = ? OR caixa_fim_id = ?)',
                [$elementoId, $caixaId, $caixaId]);
        }
        if ($tipo === 'SPLITTER') {
            return (bool) Db::valor(
                'SELECT id FROM tab_ftth_splitter WHERE id = ? AND caixa_id = ? AND excluido_em IS NULL',
                [$elementoId, $caixaId]);
        }
        return (bool) Db::valor('SELECT id FROM tab_ftth_dio_porta WHERE id = ?', [$elementoId]);
    }

    // ------------------------------------------------------------------ apoio

    /**
     * Procura clientes no cadastro nativo, por login ou nome.
     *
     * Consulta isolada em `sis_cliente`, sem JOIN com as tabelas do addon (F5): a nativa é
     * latin1 e as nossas são utf8mb4, então cruzar por string traria resultado errado em
     * qualquer nome com acento. O casamento com o vínculo é feito depois, por id (F1).
     *
     * Traz `ja_em` preenchido quando o cliente já ocupa uma porta — sem isso a tela
     * ofereceria alegremente um cliente que está em outra CTO, e o servidor recusaria
     * depois com FTTH-TOP-012.
     */
    public static function buscarClientes(string $termo, int $limite = 12): array
    {
        $termo = trim($termo);
        if (mb_strlen($termo) < 2) {
            return [];
        }
        $like = '%' . $termo . '%';

        // Só cliente ATIVO entra: não faz sentido oferecer um cancelado para ocupar uma
        // porta. Cancelado só interessa quando já está numa — e aí o que se quer é tirar,
        // não pôr (ver `atendimentoDaCaixa`, que marca a porta presa).
        $achados = Db::todos(
            'SELECT id, login, nome, endereco, numero, bairro, coordenadas
               FROM sis_cliente
              WHERE cli_ativado = "s" AND (login LIKE ? OR nome LIKE ?)
              ORDER BY login
              LIMIT ' . max(1, min(50, $limite)),
            [$like, $like]);
        if (!$achados) {
            return [];
        }

        // Onde cada um já está, numa consulta só — não uma por linha.
        $ids = array_map('intval', array_column($achados, 'id'));
        $ocupados = [];
        foreach (Db::todos(
            'SELECT p.cliente_id, p.numero, s.nome AS splitter, c.nome AS caixa
               FROM tab_ftth_porta p
               JOIN tab_ftth_splitter s ON s.id = p.splitter_id
               JOIN tab_ftth_caixa c ON c.id = s.caixa_id
              WHERE p.cliente_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')',
            $ids) as $o) {
            $ocupados[(int) $o['cliente_id']] = $o['caixa'] . ' · ' . $o['splitter']
                                              . ' saída ' . (int) $o['numero'];
        }

        $saida = [];
        foreach ($achados as $c) {
            $id = (int) $c['id'];
            $saida[] = [
                'id'       => $id,
                'login'    => $c['login'],
                'nome'     => $c['nome'],
                'endereco' => trim(($c['endereco'] ?? '') . ' ' . ($c['numero'] ?? '')
                                   . ' ' . ($c['bairro'] ?? '')),
                'ja_em'    => $ocupados[$id] ?? null,
            ];
        }
        return $saida;
    }

    /**
     * A grade de atendimento da caixa: cada splitter de ATENDIMENTO com suas saídas, o
     * cliente de cada uma e o sinal que chega ali.
     *
     * É o que a tela de clientes desenha. Splitter de derivação não entra: ali não há
     * cliente, só o encadeamento para o próximo trecho.
     *
     * O dBm de cada porta NÃO vem daqui: a topologia não sabe de potência, e quem junta as
     * duas é a tela — a mesma separação que o diagrama já segue.
     */
    public static function atendimentoDaCaixa(int $caixaId): array
    {
        $caixa = Db::um('SELECT id, nome, tipo FROM tab_ftth_caixa
                          WHERE id = ? AND excluido_em IS NULL', [$caixaId]);
        if (!$caixa) {
            return [];
        }

        $splitters = [];

        foreach (Db::todos(
            'SELECT id, nome, razao, saidas FROM tab_ftth_splitter
              WHERE caixa_id = ? AND funcao = "ATENDIMENTO" AND excluido_em IS NULL
              ORDER BY nome', [$caixaId]) as $s) {

            $clientes = [];
            foreach (Db::todos(
                'SELECT numero, cliente_id, login FROM tab_ftth_porta WHERE splitter_id = ?',
                [(int) $s['id']]) as $p) {
                $clientes[(int) $p['numero']] = ['cliente_id' => (int) $p['cliente_id'],
                                                 'login' => $p['login'], 'ativo' => true];
            }

            // Porta ocupada por cliente CANCELADO é capacidade desperdiçada, e sem marcar
            // isso ela pareceria um atendimento normal. A consulta é por id (F1/F5), numa
            // ida só — nunca um SELECT por porta.
            if ($clientes) {
                $ids = array_column($clientes, 'cliente_id');
                $ativos = [];
                foreach (Db::todos(
                    'SELECT id FROM sis_cliente
                      WHERE cli_ativado = "s" AND id IN ('
                    . implode(',', array_fill(0, count($ids), '?')) . ')', $ids) as $a) {
                    $ativos[(int) $a['id']] = true;
                }
                foreach ($clientes as $n => $c) {
                    $clientes[$n]['ativo'] = isset($ativos[$c['cliente_id']]);
                }
            }

            $portas = [];
            for ($n = 1; $n <= (int) $s['saidas']; $n++) {
                $portas[] = [
                    'numero'  => $n,
                    'rotulo'  => 'T' . str_pad((string) $n, 2, '0', STR_PAD_LEFT),
                    'cliente' => $clientes[$n] ?? null,
                ];
            }

            $splitters[] = [
                'id'       => (int) $s['id'],
                'nome'     => $s['nome'],
                'razao'    => $s['razao'],
                'saidas'   => (int) $s['saidas'],
                'ocupadas' => count($clientes),
                'portas'   => $portas,
            ];
        }

        return ['caixa' => $caixa, 'splitters' => $splitters];
    }

    /** Lê o cliente no cadastro nativo. Consulta isolada, por id (F1/F5): nunca JOIN por string. */
    public static function cliente(int $clienteId): ?array
    {
        return Db::um(
            'SELECT id, login, nome, coordenadas, armario_olt, porta_olt, onu_ont,
                    caixa_herm, porta_splitter
               FROM sis_cliente WHERE id = ?', [$clienteId]);
    }

    private static function resumoVinculo(array $splitter, int $numero, array $cliente): array
    {
        return [
            'cliente_id'    => (int) $cliente['id'],
            'login'         => $cliente['login'],
            'caixa_id'      => (int) $splitter['caixa_id'],
            'caixa_nome'    => $splitter['caixa_nome'],
            'splitter_id'   => (int) $splitter['id'],
            'splitter_nome' => $splitter['nome'],
            'numero'        => $numero,
        ];
    }

    private static function regiaoDaCaixa(int $caixaId): ?int
    {
        $v = Db::valor('SELECT regiao_id FROM tab_ftth_caixa WHERE id = ?', [$caixaId]);
        return $v === null ? null : (int) $v;
    }

    private static function sugerirNomeSplitter(int $caixaId): string
    {
        for ($i = 1; $i <= 99; $i++) {
            $nome = 'SPL.' . str_pad((string) $i, 2, '0', STR_PAD_LEFT);
            if (!Db::valor('SELECT id FROM tab_ftth_splitter WHERE caixa_id = ? AND nome = ?', [$caixaId, $nome])) {
                return $nome;
            }
        }
        return 'SPL.' . substr((string) time(), -4);
    }

    /**
     * Perdas por saída: usa as informadas, senão as do catálogo (tab_ftth_perda_padrao).
     * Devolve null quando não há catálogo para a combinação — o chamador vira FTTH-PWR-003.
     */
    private static function resolverPerdas(string $modelo, string $razao, int $saidas, $informadas): ?array
    {
        if (is_array($informadas) && $informadas) {
            $perdas = [];
            foreach ($informadas as $k => $v) {
                $perdas[(string) (int) $k] = round((float) $v, 2);
            }
            return $perdas ?: null;
        }
        $catalogo = Config::perdasSplitter($modelo, $razao);
        if ($catalogo) {
            return $catalogo;
        }
        // PERSONALIZADO nasce zerado de propósito: quem escolheu esse modelo vai digitar as perdas.
        return $modelo === 'PERSONALIZADO'
            ? array_fill_keys(array_map('strval', range(1, $saidas)), 0.0)
            : null;
    }
}
