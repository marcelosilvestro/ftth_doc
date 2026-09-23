<?php
/**
 * Suite 13 :: passagem (sangria) contra fusao.
 *
 * Numa caixa de passagem o cabo e aberto, so a fibra que vai ser usada e quebrada, e as
 * outras seguem inteiras — sem emenda e sem perda. Esta suite prova que o addon distingue
 * os dois casos sozinho e que o calculo de potencia respeita a diferenca.
 */
require_once __DIR__ . '/../../lib/Topologia.php';
require_once __DIR__ . '/../../lib/Potencia.php';
require_once __DIR__ . '/../../lib/InsidePlant.php';
require_once __DIR__ . '/../../lib/Cabo.php';

T::suite('Passagem — sangria sem perda');

$regiao = (int) Db::valor('SELECT id FROM tab_ftth_regiao WHERE nome = "Palmital"');
$t12    = (int) Db::valor('SELECT id FROM tab_ftth_cabo_tipo WHERE rotulo = "12 FO"');
$t6     = (int) Db::valor('SELECT id FROM tab_ftth_cabo_tipo WHERE rotulo = "6 FO"');

$oeste = (int) Caixa::criar($regiao, 'CEO', 'T.PS.OESTE', '#FF9100', -24.8900, -52.2300, 'teste')->data['id'];
$meio  = (int) Caixa::criar($regiao, 'CEO', 'T.PS.MEIO',  '#FF9100', -24.8910, -52.2300, 'teste')->data['id'];
$leste = (int) Caixa::criar($regiao, 'CEO', 'T.PS.LESTE', '#FF9100', -24.8920, -52.2300, 'teste')->data['id'];
$sul   = (int) Caixa::criar($regiao, 'CTO', 'T.PS.SUL',   '#43A047', -24.8910, -52.2310, 'teste')->data['id'];

// Dois trechos de 12 FO em linha (é o que a importação do KMZ produz: um cabo por trecho)
// e um ramal de 6 FO saindo do meio.
$cO = Cabo::criar($regiao, ['cabo_tipo_id' => $t12, 'nome' => 'PS OESTE'],
    [['tipo' => 'CAIXA', 'id' => $oeste], ['tipo' => 'CAIXA', 'id' => $meio]], 'teste');
$cL = Cabo::criar($regiao, ['cabo_tipo_id' => $t12, 'nome' => 'PS LESTE'],
    [['tipo' => 'CAIXA', 'id' => $meio], ['tipo' => 'CAIXA', 'id' => $leste]], 'teste');
$cS = Cabo::criar($regiao, ['cabo_tipo_id' => $t6,  'nome' => 'PS RAMAL'],
    [['tipo' => 'CAIXA', 'id' => $meio], ['tipo' => 'CAIXA', 'id' => $sul]], 'teste');
T::certo('monta os três cabos', $cO->ok && $cL->ok && $cS->ok,
    json_encode([$cO->errors, $cL->errors, $cS->errors]));

$vO = (int) $cO->data['vaos'][0];
$vL = (int) $cL->data['vaos'][0];
$vS = (int) $cS->data['vaos'][0];

$fibra = function (int $vao, int $n) {
    return ['elemento' => 'VAO_FIBRA', 'elemento_id' => $vao, 'numero' => $n];
};

// ------------------------------------------------------------------ o tipo nasce sozinho
$r = Topologia::conectar($meio, $fibra($vO, 5), $fibra($vL, 5), null, 'teste');
T::certo('liga a fibra 5 que atravessa a caixa', $r->ok, json_encode($r->errors));
$ligPassagem = (int) $r->data['id'];
T::igual('mesma bitola e mesma fibra nasce PASSAGEM', 'PASSAGEM',
    Db::valor('SELECT tipo FROM tab_ftth_ligacao WHERE id = ?', [$ligPassagem]));

$r = Topologia::conectar($meio, $fibra($vO, 6), $fibra($vL, 8), null, 'teste');
T::certo('liga a fibra 6 na 8 do outro trecho', $r->ok, json_encode($r->errors));
T::igual('número diferente é emenda de verdade: FUSAO', 'FUSAO',
    Db::valor('SELECT tipo FROM tab_ftth_ligacao WHERE id = ?', [$r->data['id']]));

$r = Topologia::conectar($meio, $fibra($vO, 1), $fibra($vS, 1), null, 'teste');
T::certo('deriva a fibra 1 para o ramal de 6 FO', $r->ok, json_encode($r->errors));
$ligRamal = (int) $r->data['id'];
T::igual('bitola diferente também é FUSAO', 'FUSAO',
    Db::valor('SELECT tipo FROM tab_ftth_ligacao WHERE id = ?', [$ligRamal]));

// Splitter no caminho nunca é passagem: ali a luz é dividida de verdade.
$spl = (int) Topologia::criarSplitter($meio,
    ['funcao' => 'DERIVACAO', 'razao' => '1:2', 'saidas' => 2], 'teste')->data['id'];
$r = Topologia::conectar($meio, $fibra($vO, 2),
    ['elemento' => 'SPLITTER_IN', 'elemento_id' => $spl, 'numero' => 0], null, 'teste');
T::certo('liga uma fibra na entrada do splitter', $r->ok, json_encode($r->errors));
$ligSplitter = (int) $r->data['id'];
T::igual('com splitter continua FUSAO', 'FUSAO',
    Db::valor('SELECT tipo FROM tab_ftth_ligacao WHERE id = ?', [$ligSplitter]));

// ------------------------------------------------------------------ alternar à mão
T::certo('marca a passagem como fusão',
    Topologia::alterarTipoLigacao($ligPassagem, 'FUSAO', 'teste')->ok);
T::igual('e o banco concorda', 'FUSAO',
    Db::valor('SELECT tipo FROM tab_ftth_ligacao WHERE id = ?', [$ligPassagem]));
T::certo('volta para passagem',
    Topologia::alterarTipoLigacao($ligPassagem, 'PASSAGEM', 'teste')->ok);

// A trava que sustenta a confiança no número: não dá para zerar uma perda que existe.
T::igual('recusa marcar passagem onde há splitter', 'FTTH-TOP-021',
    Topologia::alterarTipoLigacao($ligSplitter, 'PASSAGEM', 'teste')->primeiroCodigo());
T::igual('recusa marcar passagem entre bitolas diferentes', 'FTTH-TOP-021',
    Topologia::alterarTipoLigacao($ligRamal, 'PASSAGEM', 'teste')->primeiroCodigo());
T::igual('recusa um tipo que não existe', 'FTTH-SYS-002',
    Topologia::alterarTipoLigacao($ligPassagem, 'BANANA', 'teste')->primeiroCodigo());
T::igual('recusa ligação inexistente', 'FTTH-TOP-015',
    Topologia::alterarTipoLigacao(999999, 'FUSAO', 'teste')->primeiroCodigo());

T::igual('alternar o tipo não mexe nas pontas', 2,
    (int) Db::valor('SELECT COUNT(*) FROM tab_ftth_ligacao_ponta WHERE ligacao_id = ?',
                    [$ligPassagem]));
T::igual('nenhuma invariante violada', [], Topologia::invariantes($meio));

// ------------------------------------------------------------------ a conta muda
// A fibra 5 atravessa o MEIO sem emenda; a 6 é emendada. Mesma geometria, perdas diferentes.
$pop = (int) Caixa::criar($regiao, 'DC', 'T.PS.POP', '#1E40AF', -24.8890, -52.2300, 'teste')->data['id'];
$cP  = Cabo::criar($regiao, ['cabo_tipo_id' => $t12, 'nome' => 'PS ENTRADA'],
    [['tipo' => 'CAIXA', 'id' => $pop], ['tipo' => 'CAIXA', 'id' => $oeste]], 'teste');
$vP  = (int) $cP->data['vaos'][0];

$olt = (int) InsidePlant::criarOlt($pop, ['apelido' => 'PS.OLT', 'ptx_dbm' => 3.00,
    'placas' => [['prefixo' => '0/1', 'portas' => 4, 'inicio' => 1]]], 'teste')->data['id'];
$dio = (int) InsidePlant::criarDio($pop, 'PS.DIO', 12, 'teste')->data['id'];
$p5  = (int) InsidePlant::portas($dio)[4]['id'];
$p6  = (int) InsidePlant::portas($dio)[5]['id'];
InsidePlant::alterarPorta($p5, ['olt_ftth_id' => $olt, 'pon' => '0/1/1'], null, 'teste');
InsidePlant::alterarPorta($p6, ['olt_ftth_id' => $olt, 'pon' => '0/1/2'], null, 'teste');
InsidePlant::definirSaida($p5, $vP, 5, 'teste');
InsidePlant::definirSaida($p6, $vP, 6, 'teste');

// No OESTE as duas seguem em frente, as duas como passagem (mesma bitola, mesmo número).
Topologia::conectar($oeste, $fibra($vP, 5), $fibra($vO, 5), null, 'teste');
Topologia::conectar($oeste, $fibra($vP, 6), $fibra($vO, 6), null, 'teste');

Potencia::esquecer();
$rota5 = Potencia::rota($leste, 'VAO_FIBRA', $vL, 5);
$rota8 = Potencia::rota($leste, 'VAO_FIBRA', $vL, 8);
T::certo('as duas rotas chegam ao LESTE', $rota5->ok && $rota8->ok,
    json_encode([$rota5->errors, $rota8->errors]));

$fusao = Config::num('perda_fusao_db', 0.10);
T::certo('a fibra da sangria chega mais forte que a emendada',
    $rota5->data['dbm'] > $rota8->data['dbm'],
    'sangria=' . $rota5->data['dbm'] . ' emenda=' . $rota8->data['dbm']);
T::certo('e a diferença é exatamente uma fusão',
    abs(($rota5->data['dbm'] - $rota8->data['dbm']) - $fusao) < 0.02,
    'diferenca=' . round($rota5->data['dbm'] - $rota8->data['dbm'], 3));

$vias = array_column($rota5->data['passos'], 'via');
T::certo('a rota da sangria mostra o passo como PASSAGEM',
    in_array('PASSAGEM', $vias, true), json_encode($vias));
T::igual('e nenhum passo de passagem cobra perda', 0.0,
    array_sum(array_map(static function ($p) {
        return $p['via'] === 'PASSAGEM' ? $p['perda'] : 0;
    }, $rota5->data['passos'])));

// ------------------------------------------------------------------ ligar cabos em lote
// O serviço de um cabo passante: 24 cliques viram um. A ordem é 1:1 até o menor acabar.
$leste2 = (int) Caixa::criar($regiao, 'CEO', 'T.PS.LESTE2', '#FF9100', -24.8930, -52.2300, 'teste')->data['id'];
$cLote  = Cabo::criar($regiao, ['cabo_tipo_id' => $t12, 'nome' => 'PS LOTE'],
    [['tipo' => 'CAIXA', 'id' => $leste], ['tipo' => 'CAIXA', 'id' => $leste2]], 'teste');
$vLote  = (int) $cLote->data['vaos'][0];

$sim = Topologia::ligarCabos($leste, $vL, $vLote, false, 'teste');
T::certo('simula a ligação dos dois 12 FO', $sim->ok, json_encode($sim->errors));
T::igual('são 12 pares, o limite dos dois cabos', 12, $sim->data['total']);
T::igual('todas nascem como passagem (mesma bitola, mesmo número)', 'PASSAGEM',
    $sim->data['pares'][0]['tipo']);
T::igual('a simulação não gravou nada', 0,
    (int) Db::valor('SELECT COUNT(*) FROM tab_ftth_ligacao WHERE caixa_id = ?', [$leste]));

// As fibras 5 e 8 já estavam fusionadas na chegada — a automática não pode desfazer nada.
$sim = Topologia::ligarCabos($leste, $vL, $vLote, false, 'teste');
$ocupadas = array_values(array_filter($sim->data['pares'], static function ($p) {
    return $p['estado'] === 'pulada';
}));
T::igual('nada a pular nesta caixa ainda', 0, count($ocupadas));

$r = Topologia::ligarCabos($leste, $vL, $vLote, true, 'teste');
T::certo('aplica o lote', $r->ok, json_encode($r->errors));
T::igual('ligou as 12', 12, $r->data['ligadas']);
T::igual('e o banco concorda', 12,
    (int) Db::valor('SELECT COUNT(*) FROM tab_ftth_ligacao WHERE caixa_id = ?', [$leste]));
T::igual('todas gravadas como PASSAGEM', 12,
    (int) Db::valor('SELECT COUNT(*) FROM tab_ftth_ligacao WHERE caixa_id = ? AND tipo = "PASSAGEM"',
                    [$leste]));
T::igual('Fo07 de um lado casou com Fo07 do outro', 1,
    (int) Db::valor('SELECT COUNT(*) FROM tab_ftth_ligacao l
                       JOIN tab_ftth_ligacao_ponta pa ON pa.ligacao_id = l.id AND pa.lado = "A"
                       JOIN tab_ftth_ligacao_ponta pb ON pb.ligacao_id = l.id AND pb.lado = "B"
                      WHERE pa.elemento_id = ? AND pa.numero = 7
                        AND pb.elemento_id = ? AND pb.numero = 7', [$vL, $vLote]));

// Rodar de novo não duplica nada: está tudo ocupado agora.
$r2 = Topologia::ligarCabos($leste, $vL, $vLote, true, 'teste');
T::igual('na segunda passada não liga mais nada', 0, $r2->data['ligadas']);
T::igual('e pula as 12 que já existem', 12, $r2->data['puladas']);
T::certo('dizendo o motivo de cada uma',
    strpos((string) $r2->data['pares'][0]['motivo'], 'já está conectada') !== false,
    (string) $r2->data['pares'][0]['motivo']);
T::igual('sem criar ligação nova', 12,
    (int) Db::valor('SELECT COUNT(*) FROM tab_ftth_ligacao WHERE caixa_id = ?', [$leste]));

// Bitolas diferentes: para no menor, e a emenda é de verdade (tem perda).
$cSeis = Cabo::criar($regiao, ['cabo_tipo_id' => $t6, 'nome' => 'PS LOTE 6'],
    [['tipo' => 'CAIXA', 'id' => $leste2], ['tipo' => 'CAIXA', 'id' => $sul]], 'teste');
$vSeis = (int) $cSeis->data['vaos'][0];

$r = Topologia::ligarCabos($leste2, $vLote, $vSeis, true, 'teste');
T::igual('12 FO com 6 FO para nas 6 do menor', 6, $r->data['total']);
T::igual('ligou as 6', 6, $r->data['ligadas']);
T::igual('e como as bitolas diferem, é FUSAO', 6,
    (int) Db::valor('SELECT COUNT(*) FROM tab_ftth_ligacao WHERE caixa_id = ? AND tipo = "FUSAO"',
                    [$leste2]));

T::igual('recusa ligar um cabo nele mesmo', 'FTTH-SYS-002',
    Topologia::ligarCabos($leste, $vL, $vL, false, 'teste')->primeiroCodigo());
T::igual('recusa cabo que não encosta na caixa', 'FTTH-TOP-002',
    Topologia::ligarCabos($leste, $vL, $vSeis, false, 'teste')->primeiroCodigo());
T::igual('invariantes limpas depois do lote', [], Topologia::invariantes($leste));

// Desfazer um lote: é o inverso do ligarCabos, e existe para a tela não ter de mandar
// 24 requisições (e 24 recargas do diagrama) para desfazer uma fusão de 24 fibras.
$ids = array_values(array_filter(array_map(static function ($p) {
    return $p['ligacao_id'] ?? null;
}, Topologia::ligarCabos($leste2, $vLote, $vSeis, true, 'teste')->data['pares'])));
T::igual('a segunda passada não cria nada (já estava tudo ligado)', 0, count($ids));

$r = Topologia::ligarCabos($leste, $vL, $vLote, false, 'teste');
$antesLig = (int) Db::valor('SELECT COUNT(*) FROM tab_ftth_ligacao WHERE caixa_id = ?', [$leste]);
$idsLeste = array_values(array_filter(Db::todos(
    'SELECT id FROM tab_ftth_ligacao WHERE caixa_id = ?', [$leste]), 'is_array'));
$idsLeste = array_column($idsLeste, 'id');

$rd = Topologia::desconectarVarias($idsLeste, 'teste');
T::certo('desfaz o lote inteiro', $rd->ok, json_encode($rd->errors));
T::igual('desfez as 12', 12, $rd->data['desfeitas']);
T::igual('e a caixa ficou sem ligação', 0,
    (int) Db::valor('SELECT COUNT(*) FROM tab_ftth_ligacao WHERE caixa_id = ?', [$leste]));
T::igual('as fibras voltaram a ficar livres', 'livre',
    Topologia::fibrasDoVao($vL, $leste)[0]['estado']);

// Desfazer o que já não existe não é erro: o objetivo é o estado final, e ele foi alcançado.
$rd = Topologia::desconectarVarias($idsLeste, 'teste');
T::certo('repetir o desfazer não quebra', $rd->ok, json_encode($rd->errors));
T::igual('e diz que não havia nada a desfazer', 0, $rd->data['desfeitas']);
T::igual('lista vazia é operação válida', 0,
    Topologia::desconectarVarias([], 'teste')->data['desfeitas']);
T::igual('invariantes limpas depois de desfazer', [], Topologia::invariantes($leste));
