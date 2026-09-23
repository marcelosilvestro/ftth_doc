<?php
/**
 * ftth_doc :: de onde vem o acesso ao banco.
 *
 * O addon precisa rodar em dois mundos: no servidor do autor, que ja tem
 * /opt/mk-auth/conf/secrets.php compartilhado por varios addons, e num MK-AUTH virgem de um
 * provedor, onde esse arquivo nao existe e quem o cria e o instalador. Dai a cadeia:
 *
 *   1. /opt/mk-auth/conf/ftth_doc.php   arquivo proprio deste addon, escrito pelo instalador
 *   2. /opt/mk-auth/conf/secrets.php    bloco 'db', quando o servidor ja tiver o arquivo
 *
 * O secrets.php e lido, NUNCA escrito: ele guarda tambem blocos de outros addons.
 * A CLI pode ainda passar host/user/pass/db por argumento, que tem prioridade sobre os dois.
 */
final class Credenciais
{
    public const ARQUIVO_ADDON = '/opt/mk-auth/conf/ftth_doc.php';
    public const ARQUIVO_MKA   = '/opt/mk-auth/conf/secrets.php';

    /**
     * Primeira fonte legivel vence. Devolve null quando nenhuma existe — quem chama decide
     * se mostra recado na tela (web) ou instrucao de linha de comando (CLI).
     *
     * @return array{host:string,port:int,name:string,user:string,pass:string,origem:string}|null
     */
    public static function descobrir(): ?array
    {
        foreach ([self::ARQUIVO_ADDON, self::ARQUIVO_MKA] as $caminho) {
            $cfg = self::doArquivo($caminho);
            if ($cfg !== null) {
                return $cfg;
            }
        }
        return null;
    }

    /**
     * Le um arquivo PHP que devolve ['db' => [...]]. Erro de leitura ou formato devolve null:
     * um arquivo quebrado nao pode derrubar a pagina, so passar a vez para o proximo da fila.
     *
     * @return array{host:string,port:int,name:string,user:string,pass:string,origem:string}|null
     */
    public static function doArquivo(string $caminho): ?array
    {
        if (!is_file($caminho) || !is_readable($caminho)) {
            return null;
        }
        try {
            $conf = require $caminho;
        } catch (Throwable $e) {
            return null;
        }
        if (!is_array($conf) || !isset($conf['db']) || !is_array($conf['db'])) {
            return null;
        }
        return self::normalizar($conf['db'], $caminho);
    }

    /** @return array{host:string,port:int,name:string,user:string,pass:string,origem:string} */
    public static function normalizar(array $db, string $origem): array
    {
        return [
            'host'   => (string) ($db['host'] ?? '127.0.0.1'),
            'port'   => (int) ($db['port'] ?? 3306),
            'name'   => (string) ($db['name'] ?? 'mkradius'),
            'user'   => (string) ($db['user'] ?? 'root'),
            'pass'   => (string) ($db['pass'] ?? ''),
            'origem' => $origem,
        ];
    }

    /** Mensagem unica para quando nao ha configuracao — web e CLI dizem a mesma coisa. */
    public static function comoResolver(): string
    {
        return 'Configuracao de banco nao encontrada. Rode o instalador do addon '
             . '(ele cria ' . self::ARQUIVO_ADDON . ') ou crie o arquivo a mao, '
             . 'devolvendo ["db" => ["host","port","name","user","pass"]].';
    }
}
