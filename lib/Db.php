<?php
/**
 * ftth_doc :: conexao PDO e transacoes (3b.0).
 *
 * Regra: nada de SQL fora do lib/. As paginas chamam servicos, os servicos usam Db.
 * Conexao em utf8mb4; as tabelas nativas do MK-AUTH sao latin1 e por isso NUNCA
 * fazemos JOIN por string com elas (F5) — a ligacao e sempre por id inteiro.
 */
final class Db
{
    /**
     * Passar isto a tabelaExiste() zera o cache. So os testes precisam: em producao o conjunto
     * de tabelas nao muda no meio de uma requisicao, mas a suite cria e derruba `olt`/`cto`
     * para exercitar o servidor sem o HelpFiber.
     */
    public const ESQUECER = '__esquecer_cache__';

    private static ?PDO $pdo = null;
    private static int $nivelTransacao = 0;

    public static function conectar(array $cfg): PDO
    {
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $cfg['host'] ?? '127.0.0.1', (int)($cfg['port'] ?? 3306), $cfg['name'] ?? 'mkradius');

        self::$pdo = new PDO($dsn, $cfg['user'] ?? 'root', $cfg['pass'] ?? '', [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
        return self::$pdo;
    }

    public static function pdo(): PDO
    {
        if (self::$pdo === null) {
            throw new RuntimeException('Db nao inicializado.');
        }
        return self::$pdo;
    }

    /**
     * A tabela existe neste banco?
     *
     * Serve para as tabelas que NAO sao do MK-AUTH nem nossas: `olt` e `cto` vem do addon
     * HelpFiber, e num MK-AUTH sem ele elas simplesmente nao existem. Consultar direto
     * derrubava a tela do POP com "Table 'mkradius.olt' doesn't exist" no meio do HTML.
     *
     * O resultado fica em cache por requisicao: a pergunta se repete em varios lugares e a
     * resposta nao muda no meio de uma pagina.
     */
    public static function tabelaExiste(string $tabela): bool
    {
        static $cache = [];
        if ($tabela === self::ESQUECER) {
            $cache = [];
            return false;
        }
        if (!array_key_exists($tabela, $cache)) {
            try {
                $cache[$tabela] = (bool) self::valor(
                    'SELECT COUNT(*) FROM information_schema.tables
                      WHERE table_schema = DATABASE() AND table_name = ?', [$tabela]);
            } catch (Throwable $e) {
                $cache[$tabela] = false;
            }
        }
        return $cache[$tabela];
    }

    /** SELECT que devolve varias linhas. */
    public static function todos(string $sql, array $params = []): array
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        return $st->fetchAll();
    }

    /** SELECT que devolve uma linha (ou null). */
    public static function um(string $sql, array $params = []): ?array
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        $row = $st->fetch();
        return $row === false ? null : $row;
    }

    /** SELECT de um unico valor. */
    public static function valor(string $sql, array $params = [])
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        $v = $st->fetchColumn();
        return $v === false ? null : $v;
    }

    /** INSERT/UPDATE/DELETE. Devolve linhas afetadas. */
    public static function exec(string $sql, array $params = []): int
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        return $st->rowCount();
    }

    public static function ultimoId(): int
    {
        return (int) self::pdo()->lastInsertId();
    }

    /**
     * Transacao com aninhamento por SAVEPOINT — um servico pode chamar outro sem
     * que o COMMIT interno encerre a transacao externa.
     */
    public static function transacao(callable $fn)
    {
        $pdo = self::pdo();
        if (self::$nivelTransacao === 0) {
            $pdo->beginTransaction();
        } else {
            $pdo->exec('SAVEPOINT sp' . self::$nivelTransacao);
        }
        self::$nivelTransacao++;

        try {
            $r = $fn();
            self::$nivelTransacao--;
            if (self::$nivelTransacao === 0) {
                $pdo->commit();
            } else {
                $pdo->exec('RELEASE SAVEPOINT sp' . self::$nivelTransacao);
            }
            return $r;
        } catch (Throwable $e) {
            self::$nivelTransacao--;
            if (self::$nivelTransacao === 0) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
            } else {
                $pdo->exec('ROLLBACK TO SAVEPOINT sp' . self::$nivelTransacao);
            }
            throw $e;
        }
    }
}
