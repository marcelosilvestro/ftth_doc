<?php
/**
 * ftth_doc :: aplicacao do schema.
 *
 * Substitui o antigo Migrator (que virou tests/apoio/MigratorLegado.php). A diferenca que
 * motivou a troca: o Migrator aplicava cada arquivo UMA vez e, quando o checksum mudava,
 * apenas marcava "alterada" e nao reaplicava. Com o addon indo para servidores de terceiros,
 * o schema precisa ser reaplicavel — a versao nova traz o baseline inteiro, ele roda de novo,
 * e so o que falta e criado. Por isso sql/baseline.sql e idempotente por construcao.
 *
 * DDL no MySQL faz commit implicito: nao existe rollback de um schema pela metade. Quem chama
 * (cli/schema.php, e o instalar.sh atraves dele) faz o dump antes.
 */
require_once __DIR__ . '/Db.php';

final class Schema
{
    /** Tabelas que o baseline cria. A ordem e a de criacao (FKs). */
    public const TABELAS = [
        'tab_ftth_migration',
        'tab_ftth_config',
        'tab_ftth_regiao',
        'tab_ftth_cabo_tipo',
        'tab_ftth_perda_padrao',
        'tab_ftth_atenuacao',
        'tab_ftth_caixa',
        'tab_ftth_cabo',
        'tab_ftth_cabo_vao',
        'tab_ftth_olt',
        'tab_ftth_olt_placa',
        'tab_ftth_dio',
        'tab_ftth_dio_porta',
        'tab_ftth_splitter',
        'tab_ftth_porta',
        'tab_ftth_ligacao',
        'tab_ftth_ligacao_ponta',
        'tab_ftth_diagrama_no',
        'tab_ftth_importacao',
        'tab_ftth_importacao_item',
        'tab_ftth_historico',
        'tab_ftth_cto_espelho',
    ];

    /** Tabelas da fase de fundacao que sql/limpeza.sql derruba. */
    public const APOSENTADAS = [
        'tab_ftth_permissao',
        'tab_ftth_regiao_backup',
        'tab_ftth_diagrama_versao',
        'tab_ftth_anotacao',
        'tab_ftth_medicao',
        'tab_ftth_certificacao',
        'tab_ftth_ocorrencia',
        'tab_ftth_foto',
    ];

    private string $dir;

    public function __construct(?string $dir = null)
    {
        $this->dir = rtrim($dir ?? (__DIR__ . '/../sql'), '/\\');
    }

    /**
     * Aplica sql/baseline.sql inteiro e registra no diario. Roda toda vez: e o que faz a
     * atualizacao de schema funcionar sem arquivo incremental.
     *
     * @return array{arquivo:string,comandos:int,ms:int,versao:string}
     */
    public function aplicar(string $usuario, string $versao = ''): array
    {
        return $this->rodar('baseline.sql', $usuario, $versao);
    }

    /**
     * Aplica sql/limpeza.sql (DROP das aposentadas). Recusa quando alguma delas tem dados,
     * a menos que $forcar — apagar tabela com conteudo tem de ser escolha explicita.
     *
     * @return array{arquivo:string,comandos:int,ms:int,versao:string}
     * @throws RuntimeException quando ha dados e $forcar e false
     */
    public function limpar(string $usuario, bool $forcar = false, string $versao = ''): array
    {
        if (!$forcar) {
            $comDados = [];
            foreach ($this->aposentadasPresentes() as $t => $linhas) {
                if ($linhas > 0) {
                    $comDados[] = $t . ' (' . $linhas . ')';
                }
            }
            if ($comDados) {
                throw new RuntimeException(
                    'Tabelas aposentadas com dados: ' . implode(', ', $comDados) .
                    '. Use --forcar para derrubar mesmo assim.'
                );
            }
        }
        return $this->rodar('limpeza.sql', $usuario, $versao);
    }

    /**
     * Retrato do banco, sem escrever nada. E o que o diagnostico do instalador mostra.
     *
     * @return array{instalado:bool,tabelas:array,faltando:array,aposentadas:array,ledger:array,contagens:array}
     */
    public function estado(): array
    {
        $presentes = $this->tabelasPresentes();
        $faltando  = array_values(array_diff(self::TABELAS, $presentes));

        $contagens = [];
        if (!$faltando) {
            $contagens = [
                'regioes'    => (int) Db::valor('SELECT COUNT(*) FROM tab_ftth_regiao WHERE excluido_em IS NULL'),
                'caixas'     => (int) Db::valor('SELECT COUNT(*) FROM tab_ftth_caixa WHERE excluido_em IS NULL'),
                'vaos'       => (int) Db::valor('SELECT COUNT(*) FROM tab_ftth_cabo_vao WHERE excluido_em IS NULL'),
                'ligacoes'   => (int) Db::valor('SELECT COUNT(*) FROM tab_ftth_ligacao'),
                'clientes'   => (int) Db::valor('SELECT COUNT(*) FROM tab_ftth_porta'),
                'quarentena' => (int) Db::valor('SELECT COUNT(*) FROM tab_ftth_importacao_item WHERE status = "pendente"'),
            ];
        }

        return [
            'instalado'   => $faltando === [],
            'tabelas'     => array_values(array_intersect(self::TABELAS, $presentes)),
            'faltando'    => $faltando,
            'aposentadas' => $this->aposentadasPresentes(),
            'ledger'      => $this->ledger(),
            'contagens'   => $contagens,
        ];
    }

    /**
     * Impressao digital do schema: uma linha de texto por tabela, coluna, indice e chave
     * estrangeira, em ordem estavel. Dois bancos com a mesma assinatura tem o mesmo schema.
     * E o que o teste de equivalencia compara e o que a suite fixa por hash.
     *
     * @return string[]
     */
    public function assinatura(?PDO $pdo = null): array
    {
        $pdo   = $pdo ?? Db::pdo();
        $lista = self::TABELAS;
        $in    = implode(',', array_fill(0, count($lista), '?'));
        $linhas = [];

        $sql = "SELECT TABLE_NAME, ENGINE, TABLE_COLLATION
                  FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ($in)
              ORDER BY TABLE_NAME";
        foreach ($this->consultar($pdo, $sql, $lista) as $r) {
            $linhas[] = sprintf('TABELA %s engine=%s collation=%s',
                $r['TABLE_NAME'], $r['ENGINE'], $r['TABLE_COLLATION']);
        }

        $sql = "SELECT TABLE_NAME, COLUMN_NAME, ORDINAL_POSITION, COLUMN_TYPE, IS_NULLABLE,
                       COLUMN_DEFAULT, EXTRA, COLUMN_COMMENT, COLLATION_NAME
                  FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ($in)
              ORDER BY TABLE_NAME, ORDINAL_POSITION";
        foreach ($this->consultar($pdo, $sql, $lista) as $r) {
            $linhas[] = sprintf('COLUNA %s.%s #%d tipo=%s nulo=%s default=%s extra=%s collation=%s comentario=%s',
                $r['TABLE_NAME'], $r['COLUMN_NAME'], (int) $r['ORDINAL_POSITION'], $r['COLUMN_TYPE'],
                $r['IS_NULLABLE'], $r['COLUMN_DEFAULT'] ?? 'NULL', $r['EXTRA'],
                $r['COLLATION_NAME'] ?? '-', $r['COLUMN_COMMENT']);
        }

        $sql = "SELECT TABLE_NAME, INDEX_NAME, NON_UNIQUE, SEQ_IN_INDEX, COLUMN_NAME, INDEX_TYPE
                  FROM information_schema.STATISTICS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ($in)
              ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX";
        foreach ($this->consultar($pdo, $sql, $lista) as $r) {
            $linhas[] = sprintf('INDICE %s.%s #%d coluna=%s unico=%s tipo=%s',
                $r['TABLE_NAME'], $r['INDEX_NAME'], (int) $r['SEQ_IN_INDEX'], $r['COLUMN_NAME'],
                ((int) $r['NON_UNIQUE'] === 0 ? 'sim' : 'nao'), $r['INDEX_TYPE']);
        }

        $sql = "SELECT k.TABLE_NAME, k.CONSTRAINT_NAME, k.ORDINAL_POSITION, k.COLUMN_NAME,
                       k.REFERENCED_TABLE_NAME, k.REFERENCED_COLUMN_NAME,
                       r.DELETE_RULE, r.UPDATE_RULE
                  FROM information_schema.KEY_COLUMN_USAGE k
                  JOIN information_schema.REFERENTIAL_CONSTRAINTS r
                    ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA
                   AND r.CONSTRAINT_NAME   = k.CONSTRAINT_NAME
                 WHERE k.TABLE_SCHEMA = DATABASE() AND k.TABLE_NAME IN ($in)
              ORDER BY k.TABLE_NAME, k.CONSTRAINT_NAME, k.ORDINAL_POSITION";
        foreach ($this->consultar($pdo, $sql, $lista) as $r) {
            $linhas[] = sprintf('FK %s.%s #%d %s -> %s.%s on_delete=%s on_update=%s',
                $r['TABLE_NAME'], $r['CONSTRAINT_NAME'], (int) $r['ORDINAL_POSITION'], $r['COLUMN_NAME'],
                $r['REFERENCED_TABLE_NAME'], $r['REFERENCED_COLUMN_NAME'], $r['DELETE_RULE'], $r['UPDATE_RULE']);
        }

        return $linhas;
    }

    /** sha256 da assinatura — o valor que a suite fixa para detectar mudanca de schema. */
    public function digital(?PDO $pdo = null): string
    {
        return hash('sha256', implode("\n", $this->assinatura($pdo)));
    }

    /** Conteudo dos catalogos e da configuracao, para comparar seeds entre dois bancos. */
    public function seeds(?PDO $pdo = null): array
    {
        $pdo = $pdo ?? Db::pdo();
        $linhas = [];
        $consultas = [
            'config'       => 'SELECT chave, valor, descricao FROM tab_ftth_config ORDER BY chave',
            'cabo_tipo'    => 'SELECT rotulo, fibras, tubos, fibras_por_tubo, construcao, ordem, ativo FROM tab_ftth_cabo_tipo ORDER BY rotulo',
            'perda_padrao' => 'SELECT modelo, razao, saidas, perda_db, perda_db2, procedencia, ordem FROM tab_ftth_perda_padrao ORDER BY modelo, razao',
            'atenuacao'    => 'SELECT lambda_nm, db_km, descricao, procedencia FROM tab_ftth_atenuacao ORDER BY lambda_nm',
        ];
        foreach ($consultas as $nome => $sql) {
            foreach ($this->consultar($pdo, $sql, []) as $r) {
                $linhas[] = $nome . ' ' . implode(' | ', array_map(fn($v) => (string) ($v ?? 'NULL'), $r));
            }
        }
        return $linhas;
    }

    /** @return string[] nomes das tabelas do addon presentes no banco */
    public function tabelasPresentes(): array
    {
        $todas = array_merge(self::TABELAS, self::APOSENTADAS);
        $in    = implode(',', array_fill(0, count($todas), '?'));
        $rows  = Db::todos(
            "SELECT TABLE_NAME FROM information_schema.TABLES
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ($in)", $todas);
        return array_column($rows, 'TABLE_NAME');
    }

    /** @return array<string,int> aposentadas que ainda existem => quantidade de linhas */
    public function aposentadasPresentes(): array
    {
        $presentes = array_intersect(self::APOSENTADAS, $this->tabelasPresentes());
        $saida = [];
        foreach ($presentes as $t) {
            $saida[$t] = (int) Db::valor('SELECT COUNT(*) FROM `' . $t . '`');
        }
        return $saida;
    }

    /**
     * O diario de aplicacoes. Separa a linha do baseline dos registros das antigas migrations
     * numeradas, que ficam no banco de quem instalou o addon antes de 23/09/2026.
     *
     * @return array{baseline:?array,legados:int}
     */
    public function ledger(): array
    {
        try {
            $baseline = Db::um(
                'SELECT migration, versao, checksum, executed_at, executed_by, resultado, erro
                   FROM tab_ftth_migration WHERE migration = ?', ['baseline.sql']);
            $legados = (int) Db::valor(
                "SELECT COUNT(*) FROM tab_ftth_migration WHERE migration REGEXP '^[0-9]{3}_'");
        } catch (Throwable $e) {
            return ['baseline' => null, 'legados' => 0];
        }
        return ['baseline' => $baseline, 'legados' => $legados];
    }

    public function caminho(string $arquivo): string
    {
        return $this->dir . '/' . $arquivo;
    }

    public function checksum(string $arquivo): string
    {
        $caminho = $this->caminho($arquivo);
        return is_file($caminho) ? hash_file('sha256', $caminho) : '';
    }

    // ---------------------------------------------------------------- interno

    /** Executa um arquivo inteiro e registra o resultado no diario. */
    private function rodar(string $arquivo, string $usuario, string $versao): array
    {
        $caminho = $this->caminho($arquivo);
        if (!is_file($caminho)) {
            throw new RuntimeException('Arquivo de schema nao encontrado: ' . $caminho);
        }

        $sql      = (string) file_get_contents($caminho);
        $checksum = hash('sha256', $sql);
        $comandos = self::comandos($sql);
        $ini      = microtime(true);

        try {
            foreach ($comandos as $cmd) {
                // query() em vez de exec() porque um EXECUTE de statement preparado pode devolver
                // resultset; sem fechar o cursor, o proximo comando morre com
                // "unbuffered queries are active".
                $st = Db::pdo()->query($cmd);
                if ($st instanceof PDOStatement) {
                    $st->closeCursor();
                }
            }
        } catch (Throwable $e) {
            $this->registrar($arquivo, $checksum, $usuario, $versao,
                (int) ((microtime(true) - $ini) * 1000), 'erro', $e->getMessage());
            throw new RuntimeException($arquivo . ': ' . $e->getMessage(), 0, $e);
        }

        $ms = (int) ((microtime(true) - $ini) * 1000);
        $this->registrar($arquivo, $checksum, $usuario, $versao, $ms, 'ok', null);

        return ['arquivo' => $arquivo, 'comandos' => count($comandos), 'ms' => $ms, 'versao' => $versao];
    }

    /**
     * Grava no diario. Nunca deixa uma falha de registro derrubar a aplicacao do schema:
     * o schema e o que importa; o diario e informativo.
     */
    private function registrar(string $arquivo, string $checksum, string $usuario, string $versao,
                               int $ms, string $resultado, ?string $erro): void
    {
        try {
            Db::exec(
                'INSERT INTO tab_ftth_migration
                        (migration, checksum, versao, executed_at, executed_by, duracao_ms, resultado, erro)
                 VALUES (?, ?, ?, NOW(), ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE checksum = VALUES(checksum), versao = VALUES(versao),
                                         executed_at = NOW(), executed_by = VALUES(executed_by),
                                         duracao_ms = VALUES(duracao_ms), resultado = VALUES(resultado),
                                         erro = VALUES(erro)',
                [$arquivo, $checksum, $versao !== '' ? $versao : '0', $usuario, $ms, $resultado, $erro]
            );
        } catch (Throwable $e) {
            // Diario indisponivel (banco antigo, tabela ainda nao criada): segue em frente.
        }
    }

    /**
     * Quebra o arquivo em comandos por ";" no fim da linha, descartando linhas de comentario.
     * Mesmo criterio do Migrator antigo — ja validado contra o PREPARE/EXECUTE dos ALTERs
     * condicionais. Por isso o baseline nunca traz comentario depois do ";".
     *
     * @return string[]
     */
    public static function comandos(string $sql): array
    {
        $linhas = preg_split('/\R/', $sql);
        $buffer = '';
        $saida  = [];
        foreach ($linhas as $linha) {
            $t = trim($linha);
            if ($t === '' || str_starts_with($t, '--')) {
                continue;
            }
            $buffer .= $linha . "\n";
            if (str_ends_with($t, ';')) {
                $saida[] = trim($buffer);
                $buffer  = '';
            }
        }
        if (trim($buffer) !== '') {
            $saida[] = trim($buffer);
        }
        return $saida;
    }

    /** @return array<int,array<string,mixed>> */
    private function consultar(PDO $pdo, string $sql, array $params): array
    {
        $st = $pdo->prepare($sql);
        $st->execute($params);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }
}
