<?php
/**
 * Suite 14 :: modo Mover — caixa arrasta o cabo junto, e o traçado se redesenha.
 *
 * O ponto que esta suite guarda: geometria nao e so desenho. `comprimento_optico` alimenta
 * o calculo de potencia, entao mover uma caixa ou um vertice MUDA o dBm que chega na ponta.
 * Se o recalculo nao vier junto, o mapa e a conta passam a contar historias diferentes.
 */
require_once __DIR__ . '/../../lib/Mapa.php';
require_once __DIR__ . '/../../lib/Cabo.php';
require_once __DIR__ . '/../../lib/Caixa.php';

T::suite('Mover — caixas e traçados');

$regiao = (int) Db::valor('SELECT id FROM tab_ftth_regiao WHERE nome = "Palmital"');
$tipo6  = (int) Db::valor('SELECT id FROM tab_ftth_cabo_tipo WHERE rotulo = "6 FO"');

$a = (int) Caixa::criar($regiao, 'CEO', 'T.MV.A', '#FF9100', -24.8950, -52.2400, 'teste')->data['id'];
$b = (int) Caixa::criar($regiao, 'CTO', 'T.MV.B', '#43A047', -24.8960, -52.2400, 'teste')->data['id'];

$r = Cabo::criar($regiao, ['cabo_tipo_id' => $tipo6, 'nome' => 'MV CABO'], [
    ['tipo' => 'CAIXA',   'id' => $a],
    ['tipo' => 'VERTICE', 'lat' => -24.8955, 'lng' => -52.2402],
    ['tipo' => 'CAIXA',   'id' => $b],
], 'teste');
T::certo('cria o cabo com um vértice no meio', $r->ok, json_encode($r->errors));
$vao = (int) $r->data['vaos'][0];

$lerVao = function () use ($vao) {
    $v = Db::um('SELECT vertices, comprimento_geo, comprimento_optico, versao
                   FROM tab_ftth_cabo_vao WHERE id = ?', [$vao]);
    $v['pontos'] = json_decode((string) $v['vertices'], true);
    return $v;
};

$antes = $lerVao();
T::igual('o traçado nasce com 3 pontos', 3, count($antes['pontos']));

// ------------------------------------------------------------------ mover a caixa
$vCaixa = (int) Db::valor('SELECT versao FROM tab_ftth_caixa WHERE id = ?', [$a]);
$rm = Caixa::mover($a, -24.8940, -52.2410, $vCaixa, 'teste');
T::certo('move a caixa A', $rm->ok, json_encode($rm->errors));
T::igual('e avisa que um vão foi ajustado', 1, (int) $rm->data['vaos']);

$dep = $lerVao();
T::certo('a ponta do cabo foi junto com a caixa',
    abs($dep['pontos'][0][0] - (-24.8940)) < 0.000001
    && abs($dep['pontos'][0][1] - (-52.2410)) < 0.000001,
    json_encode($dep['pontos'][0]));
T::igual('o vértice do meio não se mexeu', $antes['pontos'][1], $dep['pontos'][1]);
T::certo('e o comprimento foi recalculado',
    abs((float) $dep['comprimento_geo'] - (float) $antes['comprimento_geo']) > 1,
    'antes=' . $antes['comprimento_geo'] . ' depois=' . $dep['comprimento_geo']);
T::certo('o óptico acompanhou o geométrico',
    (float) $dep['comprimento_optico'] > (float) $dep['comprimento_geo'],
    $dep['comprimento_optico'] . ' vs ' . $dep['comprimento_geo']);

T::igual('recusa coordenada fora do mundo', 'FTTH-GEO-002',
    Caixa::mover($a, 999, 0, null, 'teste')->primeiroCodigo());

// ------------------------------------------------------------------ mover o traçado
$v = $lerVao();
$novos = $v['pontos'];
$novos[1] = [-24.8948, -52.2418];          // arrasta o vértice do meio

$rv = Cabo::moverVertices($vao, $novos, (int) $v['versao'], 'teste');
T::certo('redesenha o traçado', $rv->ok, json_encode($rv->errors));

$dep2 = $lerVao();
T::igual('o vértice foi para onde se pediu', [-24.8948, -52.2418], $dep2['pontos'][1]);
T::certo('e o comprimento mudou de novo',
    abs((float) $dep2['comprimento_geo'] - (float) $v['comprimento_geo']) > 1);

// A trava central: a ponta é da caixa, não do desenho. Mesmo mandando outra coordenada,
// o servidor reescreve com a da caixa — senão um POST adulterado pendura o cabo no vazio.
$v = $lerVao();
$mentira = $v['pontos'];
$mentira[0] = [-20.0000, -50.0000];
$rv = Cabo::moverVertices($vao, $mentira, (int) $v['versao'], 'teste');
T::certo('aceita o envio', $rv->ok, json_encode($rv->errors));
$dep3 = $lerVao();
T::certo('mas a ponta continua na caixa, não onde o cliente mandou',
    abs($dep3['pontos'][0][0] - (-24.8940)) < 0.000001, json_encode($dep3['pontos'][0]));

T::igual('recusa traçado com um ponto só', 'FTTH-GEO-001',
    Cabo::moverVertices($vao, [[-24.89, -52.24]], null, 'teste')->primeiroCodigo());
T::igual('recusa coordenada inválida no meio', 'FTTH-GEO-002',
    Cabo::moverVertices($vao, [[-24.89, -52.24], [999, 0], [-24.90, -52.24]], null, 'teste')
        ->primeiroCodigo());
T::igual('recusa vão inexistente', 'FTTH-TOP-002',
    Cabo::moverVertices(999999, [[-24.89, -52.24], [-24.90, -52.24]], null, 'teste')
        ->primeiroCodigo());
T::igual('recusa versão velha', 'FTTH-CONC-001',
    Cabo::moverVertices($vao, $lerVao()['pontos'], 1, 'teste')->primeiroCodigo());

// ------------------------------------------------------------------ o lote
// Caixa e vão na mesma leva: as caixas vão primeiro, e o vão herda a ponta nova.
$v = $lerVao();
$vCaixaB = (int) Db::valor('SELECT versao FROM tab_ftth_caixa WHERE id = ?', [$b]);
$pontos  = $v['pontos'];
$pontos[1] = [-24.8952, -52.2405];

$rl = Mapa::aplicarMovimentos(
    [['id' => $b, 'lat' => -24.8975, 'lng' => -52.2395, 'versao' => $vCaixaB]],
    [['id' => $vao, 'vertices' => $pontos]],           // sem versão: o lote já é atômico
    'teste');
T::certo('aplica caixa e traçado de uma vez', $rl->ok, json_encode($rl->errors));
T::igual('contando o que foi movido', 1, (int) $rl->data['caixas']);

$dep4 = $lerVao();
T::certo('a ponta final ficou onde a caixa B foi parar',
    abs($dep4['pontos'][2][0] - (-24.8975)) < 0.000001, json_encode($dep4['pontos'][2]));
T::igual('e o vértice do meio é o que o lote mandou', [-24.8952, -52.2405], $dep4['pontos'][1]);

// Se um item do lote falha, nada do lote vale — é o que justifica a transação única.
$antesFalha = $lerVao();
$latA = (float) Db::valor('SELECT lat FROM tab_ftth_caixa WHERE id = ?', [$a]);

$rl = Mapa::aplicarMovimentos(
    [['id' => $a, 'lat' => -24.8930, 'lng' => -52.2420]],
    [['id' => 999999, 'vertices' => [[-24.89, -52.24], [-24.90, -52.24]]]],
    'teste');
T::certo('o lote com um vão inexistente falha', !$rl->ok);
T::igual('e a caixa que ia junto NÃO se moveu', $latA,
    (float) Db::valor('SELECT lat FROM tab_ftth_caixa WHERE id = ?', [$a]));
T::igual('nem o traçado', $antesFalha['pontos'], $lerVao()['pontos']);

T::certo('lote vazio é operação válida', Mapa::aplicarMovimentos([], [], 'teste')->ok);
