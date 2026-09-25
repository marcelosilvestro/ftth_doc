<?php
/**
 * ftth_doc :: primeiros passos — o assistente de quem acabou de instalar o addon.
 *
 * O progresso sai do próprio banco, nunca de uma marca "passo 3 concluído": instalação que já
 * tem rede não vê o assistente, e apagar a única região faz ele voltar no passo certo. A única
 * coisa gravada é a escolha de "pular por agora" (`onboarding` em tab_ftth_config).
 *
 * A ordem é a da rede de verdade: sem chave não há mapa, sem região não há onde marcar, o POP
 * vem antes de tudo porque é dele que as fibras saem, e o cabo só existe entre duas caixas —
 * por isso a primeira caixa da rua vem ANTES do primeiro cabo.
 *
 * Chave aceita pelo Google é coisa que só o navegador descobre (gm_authFailure): aqui o passo 1
 * conta como feito quando existe chave, e a tela desfaz isso se o Google recusar.
 */
require_once __DIR__ . '/Config.php';

final class PrimeirosPassos
{
    public const PASSOS = ['chave', 'regiao', 'pop', 'caixa', 'cabo'];

    public static function estado(): array
    {
        $chave = trim((string) Config::get('google_maps_key', '')) !== '';

        $regioes = (int) Db::valor('SELECT COUNT(*) FROM tab_ftth_regiao WHERE excluido_em IS NULL');

        $pop = Db::um(
            'SELECT id, nome, regiao_id, lat, lng FROM tab_ftth_caixa
              WHERE tipo = "DC" AND excluido_em IS NULL ORDER BY id LIMIT 1'
        );

        // A primeira caixa da rua: CEO ou CTO — provedor pequeno sai do POP direto numa CTO.
        $caixa = Db::um(
            'SELECT id, nome, tipo, regiao_id, lat, lng FROM tab_ftth_caixa
              WHERE tipo IN ("CEO", "CTO") AND excluido_em IS NULL ORDER BY id LIMIT 1'
        );

        // Onde um cabo pode ancorar: tudo menos a reserva, que fica no meio de um cabo.
        $ancoras = (int) Db::valor(
            'SELECT COUNT(*) FROM tab_ftth_caixa WHERE tipo <> "RESERVA" AND excluido_em IS NULL'
        );

        $cabos = (int) Db::valor('SELECT COUNT(*) FROM tab_ftth_cabo WHERE excluido_em IS NULL');

        $feitos = [
            'chave'  => $chave,
            'regiao' => $regioes > 0,
            'pop'    => $pop !== null,
            'caixa'  => $caixa !== null,
            'cabo'   => $cabos > 0,
        ];

        $atual = null;
        foreach (self::PASSOS as $p) {
            if (!$feitos[$p]) { $atual = $p; break; }
        }

        return [
            'feitos'  => $feitos,
            'atual'   => $atual,                      // null = tudo pronto
            'total'   => count(self::PASSOS),
            'prontos' => count(array_filter($feitos)),
            'pulado'  => (string) Config::get('onboarding', '') === 'pulado',
            'pop'     => $pop ? self::ponto($pop) : null,
            'caixa'   => $caixa ? self::ponto($caixa) : null,
            'ancoras' => $ancoras,
        ];
    }

    /** "Pular por agora" esconde o assistente nesta instalação; a pílula da barra o traz de volta. */
    public static function pular(bool $pular, string $usuario): array
    {
        Config::set('onboarding', $pular ? 'pulado' : '', $usuario);
        return self::estado();
    }

    private static function ponto(array $c): array
    {
        return [
            'id'        => (int) $c['id'],
            'nome'      => (string) $c['nome'],
            'tipo'      => (string) ($c['tipo'] ?? 'DC'),
            'regiao_id' => (int) $c['regiao_id'],
            'lat'       => (float) $c['lat'],
            'lng'       => (float) $c['lng'],
        ];
    }
}
