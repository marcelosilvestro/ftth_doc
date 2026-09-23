<?php
/**
 * ftth_doc :: configuracao chave/valor (tab_ftth_config), com cache em memoria.
 * Guarda tambem as perdas padrao e a atenuacao por lambda, usadas pelo motor de potencia.
 */
require_once __DIR__ . '/Db.php';

final class Config
{
    private static array $cache = [];

    public static function get(string $chave, $padrao = null)
    {
        if (!array_key_exists($chave, self::$cache)) {
            self::$cache[$chave] = Db::valor('SELECT valor FROM tab_ftth_config WHERE chave = ?', [$chave]);
        }
        $v = self::$cache[$chave];
        return ($v === null || $v === '') ? $padrao : $v;
    }

    public static function num(string $chave, float $padrao = 0.0): float
    {
        return (float) self::get($chave, $padrao);
    }

    public static function ligado(string $chave): bool
    {
        return (string) self::get($chave, '0') === '1';
    }

    public static function set(string $chave, string $valor, string $usuario): void
    {
        Db::exec(
            'INSERT INTO tab_ftth_config (chave, valor, alterado_por, alterado_em)
             VALUES (?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE valor = VALUES(valor), alterado_por = VALUES(alterado_por), alterado_em = NOW()',
            [$chave, $valor, $usuario]
        );
        self::$cache[$chave] = $valor;
    }

    public static function limparCache(): void
    {
        self::$cache = [];
    }

    /**
     * Perdas de catalogo de um splitter.
     * BAL    -> ['1' => 10.5, '2' => 10.5, ...]
     * DESBAL -> ['1' => ramo menor, '2' => ramo maior]
     */
    public static function perdasSplitter(string $modelo, string $razao): ?array
    {
        $r = Db::um('SELECT saidas, perda_db, perda_db2 FROM tab_ftth_perda_padrao WHERE modelo = ? AND razao = ?',
            [$modelo, $razao]);
        if (!$r) {
            return null;
        }
        if ($modelo === 'DESBAL') {
            return ['1' => (float) $r['perda_db'], '2' => (float) $r['perda_db2']];
        }
        $perdas = [];
        for ($i = 1; $i <= (int) $r['saidas']; $i++) {
            $perdas[(string) $i] = (float) $r['perda_db'];
        }
        return $perdas;
    }

    /** Classifica o RX estimado/medido nas faixas configuradas (A4). */
    public static function classificarSinal(float $dbm): string
    {
        if ($dbm > self::num('saturacao_dbm', -8.0)) {
            return 'saturado';
        }
        if ($dbm >= self::num('faixa_sinal_excelente', -22.0)) {
            return 'excelente';
        }
        if ($dbm >= self::num('faixa_sinal_bom', -25.0)) {
            return 'bom';
        }
        if ($dbm >= self::num('faixa_sinal_limite', -27.0)) {
            return 'limite';
        }
        return 'critico';
    }
}
