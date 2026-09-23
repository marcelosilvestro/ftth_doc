<?php
/**
 * Suite 15 :: quebrar um vão com uma caixa no meio.
 *
 * O caso de campo: o cabo já está lançado e as caixas vão sendo documentadas depois; ou houve
 * rompimento e entram duas caixas de emenda com um pedaço novo entre elas. Em vez de apagar e
 * redesenhar o cabo, o técnico solta a caixa em cima dele.
 *
 * O que não pode acontecer, e é o que esta suíte vigia: perder fibra já fundida, perder metro
 * de cabo, ou deixar uma ponta de ligação apontando para um vão que mudou de destino.
 */
require_once __DIR__ . '/../../lib/Cabo.php';
require_once __DIR__ . '/../../lib/Caixa.php';
require_once __DIR__ . '/../../lib/Topologia.php';

T::suite('Quebrar vão com caixa no meio');

$regiao = (int) Regiao::criar('Quebra', -24.90, -52.30, 15, 'teste')->data['id'];

// Um cabo reto de 12 FO entre A e B, com um vértice no meio do caminho.
$a = (int) Caixa::criar($regiao, 'CEO', 'Q.A', '#FF9100', -24.9000, -52.3000, 'teste')->data['id'];
$b = (int) Caixa::criar($regiao, 'CEO', 'Q.B', '#FF9100', -24.9000, -52.2960, 'teste')->data['id'];
$t12 = (int) Db::valor('SELECT id FROM tab_ftth_cabo_tipo WHERE rotulo = "12 FO"');

$rCabo = Cabo::criar($regiao, ['cabo_tipo_id' => $t12, 'nome' => 'Q.CABO'], [
    ['tipo' => 'CAIXA',   'id' => $a],
    ['tipo' => 'VERTICE', 'lat' => -24.9000, 'lng' => -52.2980],
    ['tipo' => 'CAIXA',   'id' => $b],
], 'teste');
T::certo('cria o cabo de 12 FO', $rCabo->ok, json_encode($rCabo->errors));
$vao = (int) $rCabo->data['vaos'][0];
$metrosAntes = (float) Db::valor('SELECT comprimento_geo FROM tab_ftth_cabo_vao WHERE id = ?', [$vao]);
Db::exec('UPDATE tab_ftth_cabo_vao SET reserva_m = 20 WHERE id = ?', [$vao]);

// Uma fibra já fundida em cada ponta, para provar que a quebra não as perde.
$splA = (int) Topologia::criarSplitter($a, ['nome' => 'Q.SPL.A', 'funcao' => 'DERIVACAO',
    'razao' => '1:2', 'saidas' => 2], 'teste')->data['id'];
$ligA = Topologia::conectar($a,
    ['elemento' => 'VAO_FIBRA',   'elemento_id' => $vao,  'numero' => 1],
    ['elemento' => 'SPLITTER_IN', 'elemento_id' => $splA, 'numero' => 0], 'FUSAO', 'teste');
T::certo('funde a fibra 1 no splitter da caixa A', $ligA->ok, json_encode($ligA->errors));

$splB = (int) Topologia::criarSplitter($b, ['nome' => 'Q.SPL.B', 'funcao' => 'DERIVACAO',
    'razao' => '1:2', 'saidas' => 2], 'teste')->data['id'];
$ligB = Topologia::conectar($b,
    ['elemento' => 'VAO_FIBRA',   'elemento_id' => $vao,  'numero' => 2],
    ['elemento' => 'SPLITTER_IN', 'elemento_id' => $splB, 'numero' => 0], 'FUSAO', 'teste');
T::certo('funde a fibra 2 no splitter da caixa B', $ligB->ok, json_encode($ligB->errors));

// ------------------------------------------------------------------ achar o cabo sob o ponto
// Uma caixa a ~7 m do traçado: dentro do raio padrão de 10 m.
$perto = Cabo::vaoSobPonto($regiao, -24.90006, -52.2980);
T::certo('acha o cabo sob um ponto a poucos metros', $perto !== null && (int) $perto['vao']['id'] === $vao,
    json_encode($perto['vao']['id'] ?? null));
T::certo('e diz a que distância ele está',
    $perto && $perto['projecao']['distancia_m'] > 0 && $perto['projecao']['distancia_m'] < 10,
    (string) ($perto['projecao']['distancia_m'] ?? '?'));

T::igual('ponto longe do cabo não acha nada', null, Cabo::vaoSobPonto($regiao, -24.9200, -52.2980));

// Trecho longo e reto, com vértice só nas pontas: o ponto do meio fica a centenas de metros
// de qualquer vértice. Foi assim que um pré-filtro errado deixou de achar cabo nenhum nos
// dados reais, e a suíte não viu porque os cabos daqui eram curtos.
$l1 = (int) Caixa::criar($regiao, 'CEO', 'Q.LONGO.A', '#FF9100', -24.9100, -52.3000, 'teste')->data['id'];
$l2 = (int) Caixa::criar($regiao, 'CEO', 'Q.LONGO.B', '#FF9100', -24.9100, -52.2800, 'teste')->data['id'];
$rLongo = Cabo::criar($regiao, ['cabo_tipo_id' => $t12, 'nome' => 'Q.LONGO'], [
    ['tipo' => 'CAIXA', 'id' => $l1],
    ['tipo' => 'CAIXA', 'id' => $l2],
], 'teste');
$vaoLongo = (int) $rLongo->data['vaos'][0];
T::certo('o trecho de teste tem mais de 1 km',
    (float) Db::valor('SELECT comprimento_geo FROM tab_ftth_cabo_vao WHERE id = ?', [$vaoLongo]) > 1000);

$meioLongo = Cabo::vaoSobPonto($regiao, -24.91004, -52.2900);
T::certo('acha o cabo no meio de um trecho longo e reto',
    $meioLongo !== null && (int) $meioLongo['vao']['id'] === $vaoLongo,
    json_encode($meioLongo['vao']['id'] ?? null));

// ------------------------------------------------------------------ a quebra
$c = (int) Caixa::criar($regiao, 'CEO', 'Q.MEIO', '#FF9100', -24.90006, -52.2980, 'teste')->data['id'];
$r = Cabo::quebrarVao($vao, $c, 'teste');
T::certo('quebra o vão na caixa do meio', $r->ok, json_encode($r->errors));

$novo = (int) ($r->data['vao_novo'] ?? 0);
T::certo('nasceu um segundo trecho', $novo > 0);
T::certo('a caixa encostou no cabo', ($r->data['caixa_moveu_m'] ?? 0) > 0,
    (string) ($r->data['caixa_moveu_m'] ?? '?'));

$vA = Db::um('SELECT * FROM tab_ftth_cabo_vao WHERE id = ?', [$vao]);
$vB = Db::um('SELECT * FROM tab_ftth_cabo_vao WHERE id = ?', [$novo]);

T::igual('o trecho original agora termina na caixa nova', $c, (int) $vA['caixa_fim_id']);
T::igual('o trecho novo começa na caixa nova', $c, (int) $vB['caixa_ini_id']);
T::igual('e termina onde o cabo terminava', $b, (int) $vB['caixa_fim_id']);
T::igual('os dois trechos são do mesmo cabo', (int) $vA['cabo_id'], (int) $vB['cabo_id']);
T::igual('o trecho novo vem logo depois na ordem', (int) $vA['ordem'] + 1, (int) $vB['ordem']);

// Metro é dinheiro: a soma dos dois trechos tem que bater com o vão original.
$somaGeo = (float) $vA['comprimento_geo'] + (float) $vB['comprimento_geo'];
T::certo('a soma dos trechos bate com o comprimento de antes',
    abs($somaGeo - $metrosAntes) < 1.0, "antes=$metrosAntes depois=$somaGeo");
T::igual('a reserva foi dividida entre os dois',
    20.0, round((float) $vA['reserva_m'] + (float) $vB['reserva_m'], 2));

// ------------------------------------------------------------------ as fibras
T::igual('a fibra fundida na caixa A continua no trecho A', 1, (int) Db::valor(
    'SELECT COUNT(*) FROM tab_ftth_ligacao_ponta
      WHERE caixa_id = ? AND elemento = "VAO_FIBRA" AND elemento_id = ? AND numero = 1', [$a, $vao]));

T::igual('a fibra fundida na caixa B migrou para o trecho novo', 1, (int) Db::valor(
    'SELECT COUNT(*) FROM tab_ftth_ligacao_ponta
      WHERE caixa_id = ? AND elemento = "VAO_FIBRA" AND elemento_id = ? AND numero = 2', [$b, $novo]));
T::igual('e não sobrou ponta apontando para o trecho errado', 0, (int) Db::valor(
    'SELECT COUNT(*) FROM tab_ftth_ligacao_ponta
      WHERE caixa_id = ? AND elemento = "VAO_FIBRA" AND elemento_id = ?', [$b, $vao]));

T::igual('as 12 fibras atravessam a caixa nova', 12, (int) ($r->data['fibras_passando'] ?? 0));
T::igual('e todas como PASSAGEM, de perda zero', 12, (int) Db::valor(
    'SELECT COUNT(*) FROM tab_ftth_ligacao WHERE caixa_id = ? AND tipo = "PASSAGEM"', [$c]));

T::igual('invariantes limpas depois da quebra', [], Topologia::invariantes($c));

// ------------------------------------------------------------------ recusas
$r2 = Cabo::quebrarVao($vao, $a, 'teste');
T::certo('recusa quebrar usando uma das próprias pontas', !$r2->ok, json_encode($r2->data));

$longe = (int) Caixa::criar($regiao, 'CEO', 'Q.LONGE', '#FF9100', -24.9300, -52.2980, 'teste')->data['id'];
$r3 = Cabo::quebrarVao($novo, $longe, 'teste');
T::certo('recusa caixa longe do cabo', !$r3->ok, json_encode($r3->data));

// ------------------------------------------------------------------ rompimento: segunda caixa
// O cenário real: com uma caixa já no cabo, a segunda entra no trecho que sobrou.
$c2 = (int) Caixa::criar($regiao, 'CEO', 'Q.MEIO2', '#FF9100', -24.90004, -52.2970, 'teste')->data['id'];
$r4 = Cabo::quebrarVao($novo, $c2, 'teste');
T::certo('quebra também o segundo trecho', $r4->ok, json_encode($r4->errors));
T::igual('o cabo agora tem três trechos', 3, (int) Db::valor(
    'SELECT COUNT(*) FROM tab_ftth_cabo_vao WHERE cabo_id = ? AND excluido_em IS NULL',
    [(int) $vA['cabo_id']]));

$ordens = array_column(Db::todos(
    'SELECT ordem FROM tab_ftth_cabo_vao WHERE cabo_id = ? AND excluido_em IS NULL ORDER BY ordem',
    [(int) $vA['cabo_id']]), 'ordem');
T::igual('e a ordem dos trechos continua sequencial', [1, 2, 3], array_map('intval', $ordens));

// ------------------------------------------------------------------ o diagrama já nasce legível
// Quem abre a caixa depois da emenda quer ver o cabo que vem do POP à esquerda e o que segue
// para a rua à direita, espelhado — as fibras de frente umas para as outras, como se desenha
// no papel. Sem isto, os dois cards nascem lado a lado apontando para o mesmo lado.
$nos = Db::todos('SELECT tipo, elemento_id, pos_x, invertido FROM tab_ftth_diagrama_no
                   WHERE caixa_id = ? AND tipo = "VAO" ORDER BY pos_x', [$c]);
T::igual('a emenda posicionou os dois cabos no diagrama', 2, count($nos));
T::certo('um de cada lado', count($nos) === 2 && (int) $nos[0]['pos_x'] < (int) $nos[1]['pos_x'],
    json_encode($nos));
T::igual('o da esquerda em pé', 0, (int) ($nos[0]['invertido'] ?? -1));
T::igual('o da direita espelhado', 1, (int) ($nos[1]['invertido'] ?? -1));

// Com um DC na ponta A, o lado do POP é o trecho que olha para lá.
Db::exec('UPDATE tab_ftth_caixa SET tipo = "DC" WHERE id = ?', [$a]);
$ladoPop = Topologia::vaoQueVemDoPop($c, $vao, $novo);
T::igual('reconhece o trecho que vem do DC', $vao, $ladoPop);

// E do outro lado, o resultado se inverte.
Db::exec('UPDATE tab_ftth_caixa SET tipo = "CEO" WHERE id = ?', [$a]);
Db::exec('UPDATE tab_ftth_caixa SET tipo = "DC"  WHERE id = ?', [$b]);
Potencia::esquecer();
T::igual('e o do outro lado quando o DC muda de ponta', $novo,
    Topologia::vaoQueVemDoPop($c, $vao, $novo));
Db::exec('UPDATE tab_ftth_caixa SET tipo = "CEO" WHERE id = ?', [$b]);

// Diagrama já organizado por alguém não pode ser mexido por uma emenda posterior.
Db::exec('UPDATE tab_ftth_diagrama_no SET pos_x = 999, invertido = 0
           WHERE caixa_id = ? AND tipo = "VAO" AND elemento_id = ?', [$c, $vao]);
Topologia::organizarEmenda($c, $vao, $novo, 'teste');
T::igual('não mexe em diagrama que já tinha posição salva', 999, (int) Db::valor(
    'SELECT pos_x FROM tab_ftth_diagrama_no WHERE caixa_id = ? AND elemento_id = ?', [$c, $vao]));

// ------------------------------------------------------------------ desfazer: unir os trechos
// A caixa de emenda sai e o cabo volta a ser um lance só. É o inverso da quebra, e o que não
// pode acontecer é o cabo ficar partido em dois pedaços que não se encontram.
T::suite('Unir trechos ao excluir a caixa');

$emenda = Cabo::emendaSimples($c2);
T::certo('reconhece a caixa como emenda simples', $emenda !== null, json_encode($emenda));
T::igual('e sabe os dois trechos que ela junta', 2,
    $emenda ? count(array_unique([$emenda['vao_a'], $emenda['vao_b']])) : 0);

$antesUniao = (float) Db::valor(
    'SELECT SUM(comprimento_geo) FROM tab_ftth_cabo_vao
      WHERE (caixa_ini_id = ? OR caixa_fim_id = ?) AND excluido_em IS NULL', [$c2, $c2]);
$vaosDoCabo = (int) Db::valor(
    'SELECT COUNT(*) FROM tab_ftth_cabo_vao WHERE cabo_id = ? AND excluido_em IS NULL', [(int) $vA['cabo_id']]);

$rU = Cabo::unirVaos($c2, null, 'teste');
T::certo('une os trechos e exclui a caixa', $rU->ok, json_encode($rU->errors));
T::certo('a caixa saiu do mapa', (int) Db::valor(
    'SELECT COUNT(*) FROM tab_ftth_caixa WHERE id = ? AND excluido_em IS NULL', [$c2]) === 0);
T::igual('o cabo tem um trecho a menos', $vaosDoCabo - 1, (int) Db::valor(
    'SELECT COUNT(*) FROM tab_ftth_cabo_vao WHERE cabo_id = ? AND excluido_em IS NULL',
    [(int) $vA['cabo_id']]));
T::certo('e o lance unido tem o comprimento dos dois juntos',
    abs((float) $rU->data['metros'] - $antesUniao) < 1.0,
    'antes=' . $antesUniao . ' depois=' . $rU->data['metros']);
T::igual('nenhuma ligação sobrou pendurada na caixa que saiu', 0, (int) Db::valor(
    'SELECT COUNT(*) FROM tab_ftth_ligacao WHERE caixa_id = ?', [$c2]));

$ordens2 = array_map('intval', array_column(Db::todos(
    'SELECT ordem FROM tab_ftth_cabo_vao WHERE cabo_id = ? AND excluido_em IS NULL ORDER BY ordem',
    [(int) $vA['cabo_id']]), 'ordem'));
T::igual('a ordem dos trechos fecha de novo', range(1, count($ordens2)), $ordens2);

// ------------------------------------------------------------------ quando NÃO dá para unir
// Caixa com splitter distribui sinal: sumir com ela não é "juntar cabo", é perder rede.
$comSpl = (int) Caixa::criar($regiao, 'CEO', 'Q.COM.SPL', '#FF9100', -24.90005, -52.2975, 'teste')->data['id'];
Cabo::quebrarVao($vao, $comSpl, 'teste');
Topologia::criarSplitter($comSpl, ['funcao' => 'ATENDIMENTO', 'nome' => 'Q.SPL.X',
    'razao' => '1:8', 'saidas' => 8], 'teste');
T::igual('caixa com splitter não é emenda simples', null, Cabo::emendaSimples($comSpl));
T::certo('e a união é recusada', !Cabo::unirVaos($comSpl, null, 'teste')->ok);

// Caixa de ponta de cabo (um trecho só) também não tem o que unir.
T::igual('caixa de ponta não é emenda simples', null, Cabo::emendaSimples($a));

// ------------------------------------------------------------------ o cabo não some no zoom
// Dar zoom no meio de um lance longo deixava a tela sem nenhuma das duas caixas das pontas —
// e o filtro de área, que olhava só as pontas, devolvia "0 vãos" sobre um cabo bem visível.
T::suite('Área visível do mapa');

require_once __DIR__ . '/../../lib/Mapa.php';

$pLongo = json_decode((string) Db::valor(
    'SELECT vertices FROM tab_ftth_cabo_vao WHERE id = ?', [$vaoLongo]), true);
// O meio geométrico, não um vértice: com dois vértices, o "do meio" seria a própria caixa.
$meio = [($pLongo[0][0] + $pLongo[1][0]) / 2, ($pLongo[0][1] + $pLongo[1][1]) / 2];
$d = 0.0005;   // ~55 m para cada lado: nenhuma caixa cabe aqui
$recorte = [$meio[0] - $d, $meio[1] - $d, $meio[0] + $d, $meio[1] + $d];

$naArea = Mapa::elementos($regiao, $recorte);
$idsCaixa = array_map('intval', array_column($naArea['caixas'], 'id'));
T::certo('as duas caixas das pontas estão fora da área',
    !in_array($l1, $idsCaixa, true) && !in_array($l2, $idsCaixa, true), json_encode($idsCaixa));
T::certo('mas o cabo que atravessa a área aparece',
    in_array($vaoLongo, array_map('intval', array_column($naArea['vaos'], 'id')), true),
    json_encode(array_column($naArea['vaos'], 'id')));

$longe = Mapa::elementos($regiao, [$meio[0] + 0.5, $meio[1] + 0.5, $meio[0] + 0.51, $meio[1] + 0.51]);
T::igual('área distante não traz cabo nenhum', 0, count($longe['vaos']));

$tudo = Mapa::elementos($regiao, null);
T::certo('sem recorte, a região inteira continua vindo', count($tudo['vaos']) > 0,
    count($tudo['vaos']) . ' vãos');
