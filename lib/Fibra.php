<?php
/**
 * ftth_doc :: cores de fibra óptica.
 *
 * Cor de fibra é NORMA, não configuração do provedor — por isso mora aqui, em constante,
 * e não em tabela com migration. O que é configurável é qual padrão cada cabo segue, e
 * isso já está em `tab_ftth_cabo.padrao_cores`.
 *
 * ABNT     — NBR 14433, usada no Brasil: verde, amarelo, branco, azul, …
 * EIA-TIA  — TIA-598-C, usada em equipamento importado: azul, laranja, verde, marrom, …
 *
 * Em cabo multitubo a sequência REINICIA a cada tubo, e o tubo é identificado pela cor da
 * mesma sequência: o tubo 2 de um 24 FO MULT (4x6) é o amarelo em ABNT, e dentro dele as
 * fibras voltam a ser verde, amarelo, branco…
 */
final class Fibra
{
    /** Ordem oficial de cada padrão. O índice 0 é a fibra 1. */
    public const SEQUENCIAS = [
        'ABNT' => ['Verde', 'Amarelo', 'Branco', 'Azul', 'Vermelho', 'Violeta',
                   'Marrom', 'Rosa', 'Preto', 'Cinza', 'Laranja', 'Aqua'],
        'EIA-TIA' => ['Azul', 'Laranja', 'Verde', 'Marrom', 'Cinza', 'Branco',
                      'Vermelho', 'Preto', 'Amarelo', 'Violeta', 'Rosa', 'Aqua'],
    ];

    /**
     * Tons escolhidos para o canvas ESCURO do diagrama.
     * `contorno` existe porque cor clara sobre fundo escuro (branco, amarelo, aqua) e preto
     * sobre escuro somem sem uma borda — o mesmo problema dos dois lados.
     */
    public const CORES = [
        'Verde'    => ['hex' => '#00C853', 'contorno' => null],
        'Amarelo'  => ['hex' => '#FFD600', 'contorno' => '#7A6500'],
        'Branco'   => ['hex' => '#FFFFFF', 'contorno' => '#6B7280'],
        'Azul'     => ['hex' => '#2979FF', 'contorno' => null],
        'Vermelho' => ['hex' => '#FF1744', 'contorno' => null],
        'Violeta'  => ['hex' => '#AA00FF', 'contorno' => null],
        'Marrom'   => ['hex' => '#8D6E63', 'contorno' => null],
        'Rosa'     => ['hex' => '#FF80AB', 'contorno' => null],
        'Preto'    => ['hex' => '#212121', 'contorno' => '#9E9E9E'],
        'Cinza'    => ['hex' => '#9E9E9E', 'contorno' => null],
        'Laranja'  => ['hex' => '#FF6D00', 'contorno' => null],
        'Aqua'     => ['hex' => '#00E5FF', 'contorno' => '#006C7A'],
    ];

    public const PADRAO_PADRAO = 'ABNT';

    public static function padraoValido(string $padrao): string
    {
        $p = strtoupper(trim($padrao));
        return isset(self::SEQUENCIAS[$p]) ? $p : self::PADRAO_PADRAO;
    }

    /**
     * Onde a fibra está dentro do cabo.
     * Monotubo (ou `fibrasPorTubo` <= 0) devolve sempre tubo 1.
     */
    public static function posicao(int $numero, int $fibrasPorTubo): array
    {
        if ($numero < 1) {
            $numero = 1;
        }
        if ($fibrasPorTubo < 1) {
            return ['tubo' => 1, 'na_tubo' => $numero];
        }
        return [
            'tubo'    => (int) floor(($numero - 1) / $fibrasPorTubo) + 1,
            'na_tubo' => (($numero - 1) % $fibrasPorTubo) + 1,
        ];
    }

    /**
     * Cor da fibra pela posição DENTRO do tubo.
     * Passar `fibrasPorTubo` = 0 trata o cabo como monotubo e usa o número direto.
     */
    public static function cor(int $numero, string $padrao = self::PADRAO_PADRAO, int $fibrasPorTubo = 0): array
    {
        $seq = self::SEQUENCIAS[self::padraoValido($padrao)];
        $pos = self::posicao($numero, $fibrasPorTubo);
        $nome = $seq[($pos['na_tubo'] - 1) % count($seq)];

        return ['nome' => $nome] + self::CORES[$nome];
    }

    /** Cor do tubo: a mesma sequência, na posição do tubo. */
    public static function corTubo(int $tubo, string $padrao = self::PADRAO_PADRAO): array
    {
        $seq  = self::SEQUENCIAS[self::padraoValido($padrao)];
        $nome = $seq[(max(1, $tubo) - 1) % count($seq)];

        return ['nome' => $nome] + self::CORES[$nome];
    }

    /** Fo01, Fo12, Fo144 — o formato que o UpperX usa e que o pessoal de campo lê. */
    public static function rotulo(int $numero): string
    {
        return 'Fo' . str_pad((string) max(1, $numero), 2, '0', STR_PAD_LEFT);
    }

    /**
     * Descreve uma fibra inteira: número, rótulo, tubo e cores.
     * É o formato que o diagrama consome — por isso monta tudo de uma vez.
     */
    public static function descrever(int $numero, string $padrao, int $fibrasPorTubo): array
    {
        $pos = self::posicao($numero, $fibrasPorTubo);
        $cor = self::cor($numero, $padrao, $fibrasPorTubo);

        return [
            'numero'   => $numero,
            'rotulo'   => self::rotulo($numero),
            'tubo'     => $pos['tubo'],
            'na_tubo'  => $pos['na_tubo'],
            'cor'      => $cor['hex'],
            'cor_nome' => $cor['nome'],
            'contorno' => $cor['contorno'],
            'cor_tubo' => self::corTubo($pos['tubo'], $padrao)['hex'],
        ];
    }
}
