<?php
/**
 * Suite 03 :: geometria, lock otimista, auditoria e classificacao de sinal.
 */

T::suite('Geometria');

// Distancia conhecida: 1 grau de latitude ~ 111,19 km.
$d = Geo::distancia(-24.0, -52.0, -25.0, -52.0);
T::certo('1 grau de latitude ~ 111 km', abs($d - 111195) < 500, 'obtido=' . round($d));

// Vao real do KMZ do UpperX: CTO.04.02 -> CTO.04.01, ficha diz 238,3 m.
$rota = [
    [-24.8846060, -52.2062340],
    [-24.8846060, -52.2038000],
    [-24.8838000, -52.2038000],
];
T::certo('comprimento de rota com varios vertices', Geo::comprimento($rota) > 300);

T::igual('rota com 1 ponto e invalida', false, Geo::rotaValida([[-24.88, -52.20]]));
T::igual('coordenada fora de faixa e invalida', false, Geo::rotaValida([[-24.88, -52.20], [999, 0]]));
T::igual('rota valida', true, Geo::rotaValida([[-24.88, -52.20], [-24.89, -52.21]]));

// A5: comprimento optico = geo * folga + reserva.
T::igual('comprimento optico com folga 3% e reserva 20 m', 123.0,
    Geo::comprimentoOptico(100.0, 1.03, 20.0));

T::suite('Lock otimista e auditoria');

$regiao = (int) Db::valor('SELECT id FROM tab_ftth_regiao LIMIT 1');
$caixa  = (int) Db::valor('SELECT id FROM tab_ftth_caixa WHERE tipo = "CTO" LIMIT 1');
$v0 = Versao::atual('caixa', $caixa);

T::certo('usuario A salva com a versao que leu', Versao::avancar('caixa', $caixa, $v0, 'usuarioA'));
T::igual('versao subiu', $v0 + 1, Versao::atual('caixa', $caixa));
T::igual('usuario B com versao velha e RECUSADO', false, Versao::avancar('caixa', $caixa, $v0, 'usuarioB'));
T::igual('versao nao mudou apos o conflito', $v0 + 1, Versao::atual('caixa', $caixa));

Auditoria::registrar('caixa', $caixa, 'alterar', ['nome' => 'CTO.02.05'], ['nome' => 'CTO.02.06'], $regiao);
$log = Db::um('SELECT * FROM tab_ftth_historico ORDER BY id DESC LIMIT 1');
T::igual('auditoria guardou a acao', 'alterar', $log['acao']);
T::certo('auditoria guardou antes e depois',
    str_contains((string) $log['antes'], 'CTO.02.05') && str_contains((string) $log['depois'], 'CTO.02.06'));
T::certo('auditoria guardou o request_id', str_starts_with((string) $log['request_id'], 'REQ-'));

T::suite('Sinal e catalogo');

T::igual('-19.43 dBm e Excelente', 'excelente', Config::classificarSinal(-19.43));
T::igual('-23.5 dBm e Bom', 'bom', Config::classificarSinal(-23.5));
T::igual('-26.0 dBm e Limite', 'limite', Config::classificarSinal(-26.0));
T::igual('-28.0 dBm e Critico', 'critico', Config::classificarSinal(-28.0));
T::igual('-5.0 dBm e saturacao', 'saturado', Config::classificarSinal(-5.0));

// Conferencia com o diagrama real do UpperX: -8,77 dBm na entrada de um 1:8 -> -19,27 dBm.
$perdas = Config::perdasSplitter('BAL', '1:8');
T::igual('saida do 1:8 bate com o UpperX', -19.27, round(-8.77 - $perdas['1'], 2));

$desb = Config::perdasSplitter('DESBAL', '10/90');
T::certo('desbalanceado 10/90 tem ramo menor e maior',
    $desb['1'] === 10.5 && $desb['2'] === 0.7, json_encode($desb));

// A atenuacao por lambda e lida direto pelo motor de potencia (lib/Potencia.php).
T::igual('atenuacao de 1490 nm cadastrada', '0.280',
    (string) Db::valor('SELECT db_km FROM tab_ftth_atenuacao WHERE lambda_nm = 1490'));

