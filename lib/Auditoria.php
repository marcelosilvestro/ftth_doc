<?php
/**
 * ftth_doc :: auditoria de negocio (3b.5).
 * Responde "quem alterou o que": usuario 15 alterou CTO 125 as 14:32, de X para Y.
 */
require_once __DIR__ . '/Db.php';
require_once __DIR__ . '/Resultado.php';

final class Auditoria
{
    private static string $usuario = 'sistema';

    public static function configurar(string $usuario): void
    {
        self::$usuario = $usuario;
    }

    public static function registrar(
        string $entidade,
        int $entidadeId,
        string $acao,
        ?array $antes = null,
        ?array $depois = null,
        ?int $regiaoId = null
    ): void {
        Db::exec(
            'INSERT INTO tab_ftth_historico
                (entidade, entidade_id, regiao_id, acao, antes, depois, usuario, request_id, criado_em)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())',
            [
                $entidade,
                $entidadeId,
                $regiaoId,
                $acao,
                $antes  === null ? null : json_encode($antes,  JSON_UNESCAPED_UNICODE),
                $depois === null ? null : json_encode($depois, JSON_UNESCAPED_UNICODE),
                self::$usuario,
                Resultado::requestId(),
            ]
        );
    }
}
