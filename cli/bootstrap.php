<?php
/**
 * ftth_doc :: bootstrap de linha de comando.
 *
 * O config.php do addon serve a web: abre sessao do painel, conecta o mysqli que o topo.php
 * exige e responde com HTML quando algo falta. Nada disso cabe num script de instalacao, e por
 * isso a CLI tem o seu proprio caminho de entrada — mesmas classes, sem nada de HTTP.
 *
 * Quem inclui este arquivo ganha: as classes de lib/ carregadas, a conexao PDO aberta e
 * $FTTH_ARGS com os argumentos da linha de comando ja separados.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Este arquivo so roda por linha de comando.\n");
}

require_once __DIR__ . '/../lib/Db.php';
require_once __DIR__ . '/../lib/Credenciais.php';
require_once __DIR__ . '/../lib/Erros.php';
require_once __DIR__ . '/../lib/Resultado.php';
require_once __DIR__ . '/../lib/Log.php';
require_once __DIR__ . '/../lib/Auditoria.php';
require_once __DIR__ . '/../lib/Config.php';
require_once __DIR__ . '/../lib/Schema.php';

/**
 * Argumentos no formato --chave=valor e --flag.
 * @return array<string,string|bool>
 */
function ftth_cli_args(array $argv): array
{
    $args = [];
    foreach (array_slice($argv, 1) as $a) {
        if (preg_match('/^--([a-z_]+)=(.*)$/', $a, $m)) {
            $args[$m[1]] = $m[2];
        } elseif (preg_match('/^--([a-z_]+)$/', $a, $m)) {
            $args[$m[1]] = true;
        }
    }
    return $args;
}

/**
 * Configuracao de banco para a CLI: argumentos vencem o arquivo, porque quem esta no terminal
 * sabe o que quer. Sem argumento, vale a mesma cadeia da web (ftth_doc.php, secrets.php).
 *
 * @param array<string,string|bool> $args
 * @return array{host:string,port:int,name:string,user:string,pass:string,origem:string}|null
 */
function ftth_cli_config(array $args): ?array
{
    $doArquivo = isset($args['conf']) && is_string($args['conf'])
        ? Credenciais::doArquivo($args['conf'])
        : Credenciais::descobrir();

    $temArgumentos = isset($args['user']) || isset($args['pass']) || isset($args['db']) || isset($args['host']);
    if (!$temArgumentos) {
        return $doArquivo;
    }

    $base = $doArquivo ?? Credenciais::normalizar([], 'argumentos');
    return Credenciais::normalizar([
        'host' => is_string($args['host'] ?? null) ? $args['host'] : $base['host'],
        'port' => isset($args['port']) ? (int) $args['port'] : $base['port'],
        'name' => is_string($args['db']   ?? null) ? $args['db']   : $base['name'],
        'user' => is_string($args['user'] ?? null) ? $args['user'] : $base['user'],
        'pass' => is_string($args['pass'] ?? null) ? $args['pass'] : $base['pass'],
    ], $doArquivo ? $doArquivo['origem'] . ' + argumentos' : 'argumentos');
}

/** Versao declarada no manifest.json, que e o que o instalador compara. */
function ftth_versao(): string
{
    $caminho = __DIR__ . '/../manifest.json';
    if (!is_file($caminho)) {
        return '0';
    }
    $m = json_decode((string) file_get_contents($caminho), true);
    return isset($m['version']) ? (string) $m['version'] : '0';
}

/** Quem esta rodando: entra na auditoria e no diario do schema. */
function ftth_usuario_cli(): string
{
    $login = getenv('SUDO_USER') ?: (getenv('USER') ?: 'cli');
    return substr('cli:' . $login, 0, 60);
}
