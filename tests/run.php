<?php
/**
 * ftth_doc :: runner de testes (3b.14).
 *
 * Sem Composer, sem PHPUnit — o servidor MK-AUTH nao tem nem um nem outro.
 * Roda por linha de comando na VM de desenvolvimento, contra um SCHEMA SEPARADO:
 *
 *   php tests/run.php --host=127.0.0.1 --user=root --pass=SENHA --db=mkradius_ftth_test
 *
 * O schema de teste e recriado do zero a cada execucao (DROP/CREATE), por isso
 * ele NUNCA pode apontar para mkradius — e por isso esta suite nao acompanha o addon
 * instalado: rodar isto num servidor de provedor apagaria um banco inteiro. Duas travas
 * cuidam disso: o nome do schema precisa conter 'test', e o arquivo recusa rodar de dentro
 * de /opt/mk-auth.
 */
declare(strict_types=1);

if (str_contains(str_replace('\\', '/', __DIR__), '/opt/mk-auth')) {
    fwrite(STDERR, "Recusado: a suite de testes nunca roda a partir de uma instalacao do MK-AUTH.\n"
                 . "Ela apaga e recria um banco inteiro. Rode a partir do repositorio.\n");
    exit(2);
}

require_once __DIR__ . '/../lib/Db.php';
require_once __DIR__ . '/../lib/Erros.php';
require_once __DIR__ . '/../lib/Resultado.php';
require_once __DIR__ . '/../lib/Schema.php';
require_once __DIR__ . '/../lib/Auditoria.php';
require_once __DIR__ . '/../lib/Versao.php';
require_once __DIR__ . '/../lib/Config.php';
require_once __DIR__ . '/../lib/Geo.php';

final class T
{
    public static int $ok = 0;
    public static int $falhou = 0;
    public static array $falhas = [];
    private static string $suite = '';

    public static function suite(string $nome): void
    {
        self::$suite = $nome;
        echo "\n\033[1m# $nome\033[0m\n";
    }

    public static function certo(string $desc, bool $cond, string $detalhe = ''): void
    {
        if ($cond) {
            self::$ok++;
            echo "  \033[32mok\033[0m   $desc\n";
        } else {
            self::$falhou++;
            self::$falhas[] = self::$suite . ' :: ' . $desc . ($detalhe ? " ($detalhe)" : '');
            echo "  \033[31mFALHOU\033[0m $desc" . ($detalhe ? " -> $detalhe" : '') . "\n";
        }
    }

    public static function igual(string $desc, $esperado, $obtido): void
    {
        self::certo($desc, $esperado == $obtido, 'esperado=' . json_encode($esperado) . ' obtido=' . json_encode($obtido));
    }

    /** Espera que a operacao falhe (invariante bloqueando estado invalido). */
    public static function recusa(string $desc, callable $fn, ?string $trechoEsperado = null): void
    {
        try {
            $fn();
            self::certo($desc, false, 'a operacao foi aceita, mas deveria falhar');
        } catch (Throwable $e) {
            $msg = $e->getMessage();
            $ok  = $trechoEsperado === null || stripos($msg, $trechoEsperado) !== false;
            self::certo($desc, $ok, $ok ? '' : 'erro diferente do esperado: ' . $msg);
        }
    }

    public static function resumo(): int
    {
        $total = self::$ok + self::$falhou;
        echo "\n" . str_repeat('-', 60) . "\n";
        if (self::$falhou === 0) {
            echo "\033[32mTUDO VERDE\033[0m: {$total} verificacoes.\n";
            return 0;
        }
        echo "\033[31m{$total} verificacoes, " . self::$falhou . " falha(s):\033[0m\n";
        foreach (self::$falhas as $f) {
            echo "  - $f\n";
        }
        return 1;
    }
}

// ------------------------------------------------------------------ argumentos
$args = [];
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--([a-z_]+)=(.*)$/', $a, $m)) {
        $args[$m[1]] = $m[2];
    }
}
$cfg = [
    'host' => $args['host'] ?? '127.0.0.1',
    'port' => (int) ($args['port'] ?? 3306),
    'user' => $args['user'] ?? 'root',
    'pass' => $args['pass'] ?? '',
    'name' => $args['db']   ?? 'mkradius_ftth_test',
];

if (!str_contains($cfg['name'], 'test')) {
    fwrite(STDERR, "Recusado: o schema de teste precisa ter 'test' no nome (recebido: {$cfg['name']}).\n");
    exit(2);
}

// ------------------------------------------------------------------ schema limpo
$admin = new PDO("mysql:host={$cfg['host']};port={$cfg['port']}", $cfg['user'], $cfg['pass'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$admin->exec("DROP DATABASE IF EXISTS `{$cfg['name']}`");
$admin->exec("CREATE DATABASE `{$cfg['name']}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

Db::conectar($cfg);
Auditoria::configurar('teste');

echo "Banco de teste: {$cfg['name']} em {$cfg['host']}\n";

foreach (glob(__DIR__ . '/suites/*.php') as $suite) {
    require $suite;
}

exit(T::resumo());
