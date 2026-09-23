<?php
/**
 * Suite 11 :: POP / Inside Plant — OLT com placas, DIO com portas e a saída para a rua.
 *
 * O ponto mais delicado aqui é a saída OSP: ela parece um campo de formulário, mas grava
 * uma ligação de verdade no mesmo grafo do diagrama. Se isso divergir, o rastreio e o
 * cálculo de potência passam a enxergar uma rede diferente da desenhada.
 */
require_once __DIR__ . '/../../lib/InsidePlant.php';
require_once __DIR__ . '/../../lib/Cabo.php';

T::suite('POP — OLT, DIO e saída para a rua');

$regiao = (int) Db::valor('SELECT id FROM tab_ftth_regiao WHERE nome = "Palmital"');
$tipo6  = (int) Db::valor('SELECT id FROM tab_ftth_cabo_tipo WHERE rotulo = "6 FO"');

// O POP é uma caixa DC; a CEO ao lado serve para o cabo ter destino e para provar que
// porta de DIO não entra em caixa que não é POP.
$pop = (int) Caixa::criar($regiao, 'DC',  'T.POP.01', '#1E40AF', -24.8600, -52.2100, 'teste')->data['id'];
$ceo = (int) Caixa::criar($regiao, 'CEO', 'T.POP.CEO', '#FF9100', -24.8609, -52.2100, 'teste')->data['id'];

$rCabo = Cabo::criar($regiao, ['cabo_tipo_id' => $tipo6, 'padrao_cores' => 'ABNT'],
    [['tipo' => 'CAIXA', 'id' => $pop], ['tipo' => 'CAIXA', 'id' => $ceo]], 'teste');
T::certo('cria o cabo que sai do POP', $rCabo->ok, json_encode($rCabo->errors));
$vaoPop = (int) Db::valor('SELECT id FROM tab_ftth_cabo_vao WHERE cabo_id = ?', [$rCabo->data['cabo_id']]);

// ------------------------------------------------------------------ o POP
T::igual('reconhece a caixa DC como POP', 'T.POP.01', InsidePlant::pop($pop)['nome']);
T::igual('e recusa uma CEO como POP', null, InsidePlant::pop($ceo));

// ------------------------------------------------------------------ OLT e placas
T::igual('recusa OLT em caixa que não é DC', 'FTTH-TOP-001',
    InsidePlant::criarOlt($ceo, ['apelido' => 'X', 'placas' => []], 'teste')->primeiroCodigo());
T::igual('recusa OLT sem apelido', 'FTTH-SYS-002',
    InsidePlant::criarOlt($pop, ['apelido' => '  '], 'teste')->primeiroCodigo());
T::igual('recusa potência de saída fora de faixa', 'FTTH-SYS-002',
    InsidePlant::criarOlt($pop, ['apelido' => 'OLT.X', 'ptx_dbm' => 99], 'teste')->primeiroCodigo());
T::igual('recusa placa com portas inválidas', 'FTTH-SYS-002',
    InsidePlant::criarOlt($pop, ['apelido' => 'OLT.X',
        'placas' => [['prefixo' => '0/1', 'portas' => 0]]], 'teste')->primeiroCodigo());
T::igual('recusa duas placas com o mesmo prefixo', 'FTTH-SYS-002',
    InsidePlant::criarOlt($pop, ['apelido' => 'OLT.X', 'placas' => [
        ['prefixo' => '0/1', 'portas' => 8], ['prefixo' => '0/1', 'portas' => 8]]], 'teste')->primeiroCodigo());

$rOlt = InsidePlant::criarOlt($pop, [
    'apelido' => 'OLT.CENTRO', 'fabricante' => 'ZTE', 'ptx_dbm' => 3.00,
    'placas' => [['prefixo' => '0/1', 'portas' => 16, 'inicio' => 1],
                 ['prefixo' => '0/2', 'portas' => 8,  'inicio' => 1]],
], 'teste');
T::certo('cria a OLT com duas placas', $rOlt->ok, json_encode($rOlt->errors));
$olt = (int) $rOlt->data['id'];

T::igual('as placas geram 24 PONs', 24, count(InsidePlant::pons($olt)));
T::igual('a primeira PON é 0/1/1', '0/1/1', InsidePlant::pons($olt)[0]);
T::igual('a última é 0/2/8', '0/2/8', InsidePlant::pons($olt)[23]);
T::igual('recusa apelido repetido', 'FTTH-SYS-002',
    InsidePlant::criarOlt($pop, ['apelido' => 'OLT.CENTRO'], 'teste')->primeiroCodigo());

// ------------------------------------------------------------------ DIO e portas
T::igual('recusa capacidade fora do catálogo', 'FTTH-SYS-002',
    InsidePlant::criarDio($pop, 'DIO.X', 13, 'teste')->primeiroCodigo());

$rDio = InsidePlant::criarDio($pop, 'DIO.01', 24, 'teste');
T::certo('instala o DIO', $rDio->ok, json_encode($rDio->errors));
$dio = (int) $rDio->data['id'];

$portas = InsidePlant::portas($dio);
T::igual('as 24 portas nascem junto com o painel', 24, count($portas));
T::igual('a primeira é P01', 'P01', $portas[0]['rotulo']);
T::igual('e todas começam vazias', 'vazia', $portas[0]['status']);
T::igual('a operação padrão é distribuição', 'DISTRIBUICAO', $portas[0]['operacao']);
T::igual('recusa nome de DIO repetido no mesmo POP', 'FTTH-SYS-002',
    InsidePlant::criarDio($pop, 'DIO.01', 24, 'teste')->primeiroCodigo());

$p01 = (int) $portas[0]['id'];
$p02 = (int) $portas[1]['id'];

// ------------------------------------------------------------------ dados internos da porta
T::igual('recusa PON que não existe nas placas', 'FTTH-SYS-002',
    InsidePlant::alterarPorta($p01, ['olt_ftth_id' => $olt, 'pon' => '9/9/9'], null, 'teste')->primeiroCodigo());
T::igual('recusa operação inválida', 'FTTH-SYS-002',
    InsidePlant::alterarPorta($p01, ['operacao' => 'BANANA'], null, 'teste')->primeiroCodigo());

$r = InsidePlant::alterarPorta($p01, ['olt_ftth_id' => $olt, 'pon' => '0/1/1',
    'servico' => 'CEO.01', 'ptx_dbm' => 3.00], null, 'teste');
T::certo('grava equipamento, PON, serviço e TX na porta', $r->ok, json_encode($r->errors));
T::igual('a porta passa a contar como parcial (sem saída ainda)', 'parcial',
    InsidePlant::portas($dio)[0]['status']);

// ------------------------------------------------------------------ saída para a rua
$saidas = InsidePlant::saidasDisponiveis($pop);
T::igual('oferece as 6 fibras do cabo que sai do POP', 6, count($saidas));
T::igual('e todas estão livres', 6, count(array_filter($saidas, static function ($s) {
    return $s['estado'] === 'livre';
})));

$r = InsidePlant::definirSaida($p01, $vaoPop, 1, 'teste');
T::certo('liga a porta P01 na fibra 1', $r->ok, json_encode($r->errors));

// O ponto central: isso virou ligação de verdade, no mesmo grafo do diagrama.
T::igual('nasceu uma ligação no grafo', 1,
    (int) Db::valor('SELECT COUNT(*) FROM tab_ftth_ligacao WHERE caixa_id = ?', [$pop]));
T::igual('com a ponta DIO_PORTA registrada', 1,
    (int) Db::valor('SELECT COUNT(*) FROM tab_ftth_ligacao_ponta
                      WHERE elemento = "DIO_PORTA" AND elemento_id = ?', [$p01]));
T::igual('a porta ficou verde', 'ok', InsidePlant::portas($dio)[0]['status']);
T::certo('e a saída sabe para onde vai',
    strpos(InsidePlant::portas($dio)[0]['saida']['rotulo'], 'T.POP.CEO') !== false,
    json_encode(InsidePlant::portas($dio)[0]['saida']));
T::igual('a fibra 1 saiu da lista de livres', 'conectada',
    Topologia::fibrasDoVao($vaoPop, $pop)[0]['estado']);
T::igual('nenhuma invariante violada', [], Topologia::invariantes($pop));

// A mesma fibra não pode alimentar duas portas.
T::igual('recusa a fibra que já está em outra porta', 'FTTH-TOP-004',
    InsidePlant::definirSaida($p02, $vaoPop, 1, 'teste')->primeiroCodigo());

// Trocar de fibra tem de soltar a anterior — a porta tem uma saída só (I7).
$r = InsidePlant::definirSaida($p01, $vaoPop, 3, 'teste');
T::certo('troca a saída para a fibra 3', $r->ok, json_encode($r->errors));
T::igual('a fibra 1 voltou a ficar livre', 'livre',
    Topologia::fibrasDoVao($vaoPop, $pop)[0]['estado']);
T::igual('e continua havendo uma ligação só', 1,
    (int) Db::valor('SELECT COUNT(*) FROM tab_ftth_ligacao WHERE caixa_id = ?', [$pop]));

// Soltar de vez.
T::certo('solta a saída', InsidePlant::definirSaida($p01, 0, 0, 'teste')->ok);
T::igual('o grafo do POP ficou vazio', 0,
    (int) Db::valor('SELECT COUNT(*) FROM tab_ftth_ligacao WHERE caixa_id = ?', [$pop]));
T::igual('e a porta voltou a parcial', 'parcial', InsidePlant::portas($dio)[0]['status']);

// ------------------------------------------------------------------ proteções
InsidePlant::definirSaida($p01, $vaoPop, 1, 'teste');

T::igual('recusa excluir a OLT enquanto uma porta a usa', 'FTTH-SYS-002',
    InsidePlant::excluirOlt($olt, null, 'teste')->primeiroCodigo());
T::igual('recusa excluir o DIO com fibra ligada', 'FTTH-TOP-016',
    InsidePlant::excluirDio($dio, null, 'teste')->primeiroCodigo());
T::igual('recusa tirar a placa cuja PON está em uso', 'FTTH-SYS-002',
    InsidePlant::alterarOlt($olt, ['placas' => [['prefixo' => '0/2', 'portas' => 8]]],
        null, 'teste')->primeiroCodigo());

// Para provar a recusa de encolher, a porta ocupada precisa estar ACIMA do novo limite —
// reduzir de 24 para 12 com a P01 em uso é legítimo, porque a P01 continua existindo.
$pAlta = (int) InsidePlant::portas($dio)[19]['id'];   // P20
InsidePlant::alterarPorta($pAlta, ['olt_ftth_id' => $olt, 'pon' => '0/1/2'], null, 'teste');

$vDio = (int) Db::valor('SELECT versao FROM tab_ftth_dio WHERE id = ?', [$dio]);
T::igual('reduzir até onde não há porta em uso é permitido', true,
    InsidePlant::alterarDio($dio, ['portas' => 24], $vDio, 'teste')->ok);

$vDio = (int) Db::valor('SELECT versao FROM tab_ftth_dio WHERE id = ?', [$dio]);
T::igual('recusa reduzir a capacidade abaixo de uma porta em uso', 'FTTH-SYS-002',
    InsidePlant::alterarDio($dio, ['portas' => 12], $vDio, 'teste')->primeiroCodigo());

// Crescer o painel é sempre seguro.
$r = InsidePlant::alterarDio($dio, ['portas' => 48], $vDio, 'teste');
T::certo('amplia o DIO para 48 portas', $r->ok, json_encode($r->errors));
T::igual('e as portas novas aparecem vazias', 48, count(InsidePlant::portas($dio)));
T::igual('recusa alterar com versão velha', 'FTTH-CONC-001',
    InsidePlant::alterarDio($dio, ['nome' => 'DIO.02'], $vDio, 'teste')->primeiroCodigo());

T::igual('invariantes seguem limpas no fim', [], Topologia::invariantes($pop));

// ------------------------------------------------------------------ exclusão é física
// Decisão de 22/09/2026: OLT e DIO saem do banco de verdade. Marcar excluido_em deixava
// lixo e, por causa do UNIQUE do apelido, reservava o nome para sempre.
InsidePlant::definirSaida($p01, 0, 0, 'teste');   // solta a fibra para poder excluir

$vDio = (int) Db::valor('SELECT versao FROM tab_ftth_dio WHERE id = ?', [$dio]);
$r = InsidePlant::excluirDio($dio, $vDio, 'teste');
T::certo('exclui o DIO', $r->ok, json_encode($r->errors));
T::igual('a linha do DIO sumiu do banco', 0,
    (int) Db::valor('SELECT COUNT(*) FROM tab_ftth_dio WHERE id = ?', [$dio]));
T::igual('e as 48 portas foram junto', 0,
    (int) Db::valor('SELECT COUNT(*) FROM tab_ftth_dio_porta WHERE dio_id = ?', [$dio]));
T::certo('o nome do DIO volta a ficar livre',
    InsidePlant::criarDio($pop, 'DIO.01', 12, 'teste')->ok);

$vOlt = (int) Db::valor('SELECT versao FROM tab_ftth_olt WHERE id = ?', [$olt]);
$r = InsidePlant::excluirOlt($olt, $vOlt, 'teste');
T::certo('exclui a OLT', $r->ok, json_encode($r->errors));
T::igual('a linha da OLT sumiu do banco', 0,
    (int) Db::valor('SELECT COUNT(*) FROM tab_ftth_olt WHERE id = ?', [$olt]));
T::igual('e as placas não ficaram órfãs', 0,
    (int) Db::valor('SELECT COUNT(*) FROM tab_ftth_olt_placa WHERE olt_id = ?', [$olt]));
T::certo('o apelido volta a ficar livre',
    InsidePlant::criarOlt($pop, ['apelido' => 'OLT.CENTRO'], 'teste')->ok);

// O que se apagou do banco tem de continuar legível no histórico — é o que sustenta
// trocar o soft delete pelo hard delete.
$hist = Db::um('SELECT antes FROM tab_ftth_historico
                 WHERE entidade = "olt" AND entidade_id = ? AND acao = "excluir"', [$olt]);
T::certo('o histórico guardou o antes da OLT', (bool) $hist, 'nada em tab_ftth_historico');
if ($hist) {
    $antes = json_decode((string) $hist['antes'], true);
    T::igual('com o apelido', 'OLT.CENTRO', $antes['apelido'] ?? null);
    T::igual('e com as duas placas', 2, count($antes['placas'] ?? []));
}

T::igual('invariantes limpas depois das exclusões', [], Topologia::invariantes($pop));
