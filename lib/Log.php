<?php
/**
 * ftth_doc :: log tecnico (3b.5).
 *
 * Responde "o que o sistema fez": request_id, usuario, operacao, tempo, excecao.
 * NAO e auditoria (essa fica em tab_ftth_historico e responde "quem alterou o que").
 * Arquivo diario em ftth_doc/logs/, protegido por .htaccess.
 */
require_once __DIR__ . '/Resultado.php';

final class Log
{
    /** Tamanho maximo de uma linha: acima disso o append deixa de ser atomico. */
    private const LIMITE = 3500;

    private static string $dir = __DIR__ . '/../logs';
    private static string $usuario = 'sistema';

    public static function configurar(string $dir, string $usuario): void
    {
        self::$dir = rtrim($dir, '/');
        self::$usuario = $usuario;
    }

    public static function info(string $operacao, array $ctx = []): void
    {
        self::escrever('INFO', $operacao, $ctx);
    }

    public static function aviso(string $operacao, array $ctx = []): void
    {
        self::escrever('WARN', $operacao, $ctx);
    }

    public static function erro(string $operacao, array $ctx = []): void
    {
        self::escrever('ERRO', $operacao, $ctx);
    }

    /** Excecao vai INTEIRA para o log e NUNCA para a tela (3b.5). */
    public static function excecao(string $operacao, Throwable $e, array $ctx = []): void
    {
        self::escrever('ERRO', $operacao, $ctx + [
            'excecao' => get_class($e),
            'msg'     => $e->getMessage(),
            'arquivo' => $e->getFile() . ':' . $e->getLine(),
            'trace'   => array_slice(explode("\n", $e->getTraceAsString()), 0, 12),
        ]);
    }

    private static function escrever(string $nivel, string $operacao, array $ctx): void
    {
        $registro = [
            'ts'         => date('c'),
            'nivel'      => $nivel,
            'request_id' => Resultado::requestId(),
            'usuario'    => self::$usuario,
            'operacao'   => $operacao,
            'ctx'        => $ctx,
        ];

        $linha = self::serializar($registro);

        // Linha grande demais nao cabe num append atomico: em vez de arriscar duas
        // requisicoes se intercalando no arquivo, o contexto vai resumido.
        if (strlen($linha) > self::LIMITE) {
            $registro['ctx'] = [
                'truncado' => true,
                'resumo'   => substr(self::serializar($ctx), 0, 800),
            ];
            $linha = self::serializar($registro);
        }

        if (!is_dir(self::$dir)) {
            @mkdir(self::$dir, 0770, true);
        }

        $arquivo = self::$dir . '/ftth-' . date('Y-m-d') . '.log';

        // Sem LOCK_EX de proposito: o AppArmor do painel permite "rw" em
        // /opt/mk-auth/log/** mas nega "k" (file_lock), e o flock negado derrubava a
        // escrita inteira -- era por isso que o arquivo nascia com 0 bytes e nenhum
        // erro deixava rastro. Append de linha curta ja e atomico no Linux, e a
        // constante LIMITE acima e o que garante que ela e curta.
        if (@file_put_contents($arquivo, $linha . PHP_EOL, FILE_APPEND) === false) {
            // Ultimo recurso: se nem o arquivo do addon aceita, ao menos nao se perde.
            error_log('ftth_doc: falha ao escrever em ' . $arquivo . ' :: ' . $linha);
        }
    }

    /** json_encode que nunca devolve false: log quebrado e pior do que log feio. */
    private static function serializar($dado): string
    {
        $json = json_encode($dado,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);

        return $json === false ? '{"erro":"contexto nao serializavel"}' : $json;
    }
}
