<?php
/**
 * ftth_doc :: lock otimista (3b.6).
 *
 * Toda entidade editavel tem coluna `versao`. Quem salva manda a versao que leu.
 * Se nao bater, nada e gravado e volta FTTH-CONC-001 — nunca sobrescreve em silencio.
 */
require_once __DIR__ . '/Db.php';

final class Versao
{
    private const TABELAS = [
        'regiao'   => 'tab_ftth_regiao',
        'caixa'    => 'tab_ftth_caixa',
        'cabo'     => 'tab_ftth_cabo',
        'vao'      => 'tab_ftth_cabo_vao',
        'splitter' => 'tab_ftth_splitter',
        'ligacao'  => 'tab_ftth_ligacao',
        'porta'    => 'tab_ftth_porta',
        'dio'      => 'tab_ftth_dio',
        'dio_porta'=> 'tab_ftth_dio_porta',
        'olt'      => 'tab_ftth_olt',
    ];

    public static function tabela(string $entidade): string
    {
        if (!isset(self::TABELAS[$entidade])) {
            throw new InvalidArgumentException('Entidade desconhecida: ' . $entidade);
        }
        return self::TABELAS[$entidade];
    }

    public static function atual(string $entidade, int $id): ?int
    {
        $t = self::tabela($entidade);
        $v = Db::valor("SELECT versao FROM `$t` WHERE id = ?", [$id]);
        return $v === null ? null : (int) $v;
    }

    /**
     * Incrementa a versao exigindo a versao esperada.
     * Devolve false quando outro usuario alterou antes (conflito).
     */
    public static function avancar(string $entidade, int $id, ?int $versaoEsperada, string $usuario): bool
    {
        $t = self::tabela($entidade);
        if ($versaoEsperada === null) {
            Db::exec("UPDATE `$t` SET versao = versao + 1, alterado_por = ?, alterado_em = NOW() WHERE id = ?",
                [$usuario, $id]);
            return true;
        }
        $n = Db::exec(
            "UPDATE `$t` SET versao = versao + 1, alterado_por = ?, alterado_em = NOW()
             WHERE id = ? AND versao = ?",
            [$usuario, $id, $versaoEsperada]
        );
        return $n === 1;
    }
}
