<?php
/**
 * ftth_doc :: prova de que o baseline reproduz o schema das 17 migrations.
 *
 * Este script existe para uma decisão pontual: trocar sql/001..017 por sql/baseline.sql sem
 * fé, com evidência. Ele monta dois bancos descartáveis — um com as migrations antigas
 * (sql/historico/, pelo MigratorLegado), outro com o baseline —, aplica a limpeza nos dois e
 * compara tabela por tabela, coluna por coluna, índice por índice, chave estrangeira por
 * chave estrangeira, mais o conteúdo dos catálogos.
 *
 *   php tests/comparar_schema.php --host=127.0.0.1 --user=root --pass=SENHA
 *
 * Sai 0 quando os dois schemas são idênticos e 1 na primeira divergência, listando cada uma.
 * Os dois bancos precisam ter 'test' no nome e são derrubados no fim.
 */
declare(strict_types=1);

require_once __DIR__ . '/../lib/Db.php';
require_once __DIR__ . '/../lib/Schema.php';
require_once __DIR__ . '/apoio/MigratorLegado.php';

$args = [];
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--([a-z_]+)=(.*)$/', $a, $m)) {
        $args[$m[1]] = $m[2];
    }
}
$host = $args['host'] ?? '127.0.0.1';
$port = (int) ($args['port'] ?? 3306);
$user = $args['user'] ?? 'root';
$pass = $args['pass'] ?? '';
$bancoRef  = $args['ref']  ?? 'mkradius_ftth_test_ref';
$bancoNovo = $args['novo'] ?? 'mkradius_ftth_test_novo';
$manter    = isset($args['manter']);

foreach ([$bancoRef, $bancoNovo] as $b) {
    if (!str_contains($b, 'test')) {
        fwrite(STDERR, "Recusado: o banco de comparacao precisa ter 'test' no nome (recebido: $b).\n");
        exit(2);
    }
}

$admin = new PDO("mysql:host=$host;port=$port", $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

function recriar(PDO $admin, string $nome): void
{
    $admin->exec("DROP DATABASE IF EXISTS `$nome`");
    $admin->exec("CREATE DATABASE `$nome` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
}

function conectar(string $host, int $port, string $user, string $pass, string $nome): void
{
    Db::conectar(['host' => $host, 'port' => $port, 'user' => $user, 'pass' => $pass, 'name' => $nome]);
}

echo "Comparando schemas\n";
echo "  referencia : $bancoRef  (sql/historico/, 17 migrations)\n";
echo "  novo       : $bancoNovo (sql/baseline.sql)\n\n";

// ------------------------------------------------------------------ referência: as 17
recriar($admin, $bancoRef);
conectar($host, $port, $user, $pass, $bancoRef);
$legado = new MigratorLegado(__DIR__ . '/../sql/historico');
$r = $legado->aplicar('comparacao');
if ($r['erro'] !== null) {
    fwrite(STDERR, "Falha ao aplicar as migrations antigas: {$r['erro']}\n");
    exit(1);
}
echo "  " . count($r['aplicadas']) . " migrations aplicadas na referencia\n";

// A referência tem as 8 tabelas aposentadas; a limpeza as derruba, para os dois bancos
// terminarem com o mesmo conjunto.
$schemaRef = new Schema(__DIR__ . '/../sql');
$schemaRef->limpar('comparacao', true, 'comparacao');
echo "  limpeza aplicada na referencia\n";
$assinaturaRef = $schemaRef->assinatura();
$seedsRef      = $schemaRef->seeds();

// ------------------------------------------------------------------ novo: o baseline
recriar($admin, $bancoNovo);
conectar($host, $port, $user, $pass, $bancoNovo);
$schemaNovo = new Schema(__DIR__ . '/../sql');
$aplicado = $schemaNovo->aplicar('comparacao', 'comparacao');
echo "  baseline aplicado no novo ({$aplicado['comandos']} comandos, {$aplicado['ms']} ms)\n";
$schemaNovo->limpar('comparacao', true, 'comparacao');
$assinaturaNovo = $schemaNovo->assinatura();
$seedsNovo      = $schemaNovo->seeds();

// ------------------------------------------------------------------ comparação
/** Diferenças entre duas listas de linhas, nos dois sentidos. */
function diferencas(array $ref, array $novo): array
{
    $soRef  = array_values(array_diff($ref, $novo));
    $soNovo = array_values(array_diff($novo, $ref));
    return [$soRef, $soNovo];
}

$problemas = 0;

[$soRef, $soNovo] = diferencas($assinaturaRef, $assinaturaNovo);
if ($soRef || $soNovo) {
    $problemas += count($soRef) + count($soNovo);
    echo "\n\033[31mDIVERGENCIAS DE SCHEMA\033[0m\n";
    foreach ($soRef as $l) {
        echo "  so nas migrations antigas : $l\n";
    }
    foreach ($soNovo as $l) {
        echo "  so no baseline            : $l\n";
    }
} else {
    echo "\n  \033[32mok\033[0m  schema identico (" . count($assinaturaRef) . " itens conferidos)\n";
}

[$soRef, $soNovo] = diferencas($seedsRef, $seedsNovo);
// A descrição das chaves de config pode ter melhorado de propósito no baseline; o que não
// pode mudar é a chave existir e o valor de fábrica.
if ($soRef || $soNovo) {
    $problemas += count($soRef) + count($soNovo);
    echo "\n\033[31mDIVERGENCIAS DE SEED\033[0m\n";
    foreach ($soRef as $l) {
        echo "  so nas migrations antigas : $l\n";
    }
    foreach ($soNovo as $l) {
        echo "  so no baseline            : $l\n";
    }
} else {
    echo "  \033[32mok\033[0m  seeds identicos (" . count($seedsRef) . " linhas conferidas)\n";
}

// ------------------------------------------------------------------ idempotência
conectar($host, $port, $user, $pass, $bancoNovo);
$digitalAntes = $schemaNovo->digital();
$schemaNovo->aplicar('comparacao', 'comparacao');
$digitalDepois = $schemaNovo->digital();
if ($digitalAntes !== $digitalDepois) {
    $problemas++;
    echo "  \033[31mFALHOU\033[0m reaplicar o baseline mudou o schema\n";
} else {
    echo "  \033[32mok\033[0m  reaplicar o baseline nao muda nada\n";
}

if (!$manter) {
    $admin->exec("DROP DATABASE IF EXISTS `$bancoRef`");
    $admin->exec("DROP DATABASE IF EXISTS `$bancoNovo`");
}

echo "\n" . str_repeat('-', 60) . "\n";
if ($problemas === 0) {
    echo "\033[32mEQUIVALENTE\033[0m: o baseline reproduz o schema das 17 migrations.\n";
    exit(0);
}
echo "\033[31m$problemas divergencia(s)\033[0m — o baseline ainda nao pode substituir as migrations.\n";
exit(1);
