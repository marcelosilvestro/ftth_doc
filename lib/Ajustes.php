<?php
/**
 * ftth_doc :: ajustes do addon e estado do banco.
 *
 * Mora aqui, e não numa tela, desde que os ajustes foram para a aba Ajustes do painel do
 * mapa (24/09/2026). A mesma regra serve ao painel e ao aviso de primeira instalação, que
 * pede a chave do Google antes de existir mapa.
 */
require_once __DIR__ . '/Config.php';
require_once __DIR__ . '/Schema.php';

final class Ajustes
{
    public const TIPOS_MAPA = ['hybrid' => 'Híbrido', 'satellite' => 'Satélite',
                               'roadmap' => 'Ruas', 'terrain' => 'Relevo'];

    /** O que a aba mostra hoje. */
    public static function valores(): array
    {
        return [
            'google_maps_key'    => (string) Config::get('google_maps_key', ''),
            'mapa_tipo'          => (string) Config::get('mapa_tipo', 'hybrid'),
            'mapa_rotulo_zoom'   => (int) Config::num('mapa_rotulo_zoom', 17),
            'raio_quebra_cabo_m' => (int) Config::num('raio_quebra_cabo_m', 10),
        ];
    }

    /**
     * Grava só as chaves que a tela oferece. Uma lista fixa evita que um POST forjado escreva
     * qualquer coisa em tab_ftth_config — inclusive os interruptores de escrita em tabela
     * nativa, que continuam só por SQL, de propósito.
     */
    public static function salvar(array $post, string $usuario): array
    {
        $salvas = [];

        if (isset($post['google_maps_key'])) {
            Config::set('google_maps_key', trim((string) $post['google_maps_key']), $usuario);
            $salvas[] = 'google_maps_key';
        }
        if (isset($post['mapa_tipo'])) {
            $tipo = isset(self::TIPOS_MAPA[$post['mapa_tipo']]) ? (string) $post['mapa_tipo'] : 'hybrid';
            Config::set('mapa_tipo', $tipo, $usuario);
            $salvas[] = 'mapa_tipo';
        }
        if (isset($post['mapa_rotulo_zoom'])) {
            $zoom = max(3, min(21, (int) $post['mapa_rotulo_zoom']));
            Config::set('mapa_rotulo_zoom', (string) $zoom, $usuario);
            $salvas[] = 'mapa_rotulo_zoom';
        }
        if (isset($post['raio_quebra_cabo_m'])) {
            $raio = max(1, min(100, (int) $post['raio_quebra_cabo_m']));
            Config::set('raio_quebra_cabo_m', (string) $raio, $usuario);
            $salvas[] = 'raio_quebra_cabo_m';
        }

        return ['salvas' => $salvas] + self::valores();
    }

    /** Estado do banco e do addon, no formato curto do rodapé da aba. */
    public static function estado(): array
    {
        $schema = new Schema(__DIR__ . '/../sql');
        $e = $schema->estado();

        $manifest = json_decode((string) @file_get_contents(__DIR__ . '/../manifest.json'), true) ?: [];
        $baseline = $e['ledger']['baseline'] ?? null;

        return [
            'completo'    => empty($e['faltando']),
            'tabelas'     => count($e['tabelas'] ?? []),
            'tabelas_de'  => count(Schema::TABELAS),
            'schema'      => $baseline ? (string) $baseline['versao'] : null,
            'schema_em'   => $baseline ? substr((string) $baseline['executed_at'], 0, 16) : null,
            'aposentadas' => count($e['aposentadas'] ?? []),
            'addon'       => (string) ($manifest['version'] ?? ''),
            'instalador'  => 'wget -O - ' . FTTH_URL_INSTALADOR . ' | bash',
        ];
    }
}
