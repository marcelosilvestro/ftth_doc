<?php
/**
 * Suite 12 :: rastreio de rota e potencia optica.
 *
 * Monta uma cadeia real — POP com OLT e DIO, cabo ate uma CEO, splitter 1:8, cabo ate uma
 * CTO — e confere que o sinal chega na ponta com a conta certa. O valor NAO e escrito a
 * mao no teste: ele e recalculado aqui a partir dos mesmos numeros do catalogo, senao o
 * teste so repetiria o que o codigo fez.
 */
require_once __DIR__ . '/../../lib/Potencia.php';
require_once __DIR__ . '/../../lib/InsidePlant.php';
require_once __DIR__ . '/../../lib/Cabo.php';

T::suite('Potência — rota e dBm');

$regiao = (int) Db::valor('SELECT id FROM tab_ftth_regiao WHERE nome = "Palmital"');
$tipo12 = (int) Db::valor('SELECT id FROM tab_ftth_cabo_tipo WHERE rotulo = "12 FO"');
$tipo6  = (int) Db::valor('SELECT id FROM tab_ftth_cabo_tipo WHERE rotulo = "6 FO"');

$pop = (int) Caixa::criar($regiao, 'DC',  'T.PW.POP', '#1E40AF', -24.8700, -52.2200, 'teste')->data['id'];
$ceo = (int) Caixa::criar($regiao, 'CEO', 'T.PW.CEO', '#FF9100', -24.8710, -52.2200, 'teste')->data['id'];
$cto = (int) Caixa::criar($regiao, 'CTO', 'T.PW.CTO', '#43A047', -24.8720, -52.2200, 'teste')->data['id'];

$c1 = Cabo::criar($regiao, ['cabo_tipo_id' => $tipo12, 'nome' => 'PW BACKBONE'],
    [['tipo' => 'CAIXA', 'id' => $pop], ['tipo' => 'CAIXA', 'id' => $ceo]], 'teste');
$c2 = Cabo::criar($regiao, ['cabo_tipo_id' => $tipo6, 'nome' => 'PW DISTRIB'],
    [['tipo' => 'CAIXA', 'id' => $ceo], ['tipo' => 'CAIXA', 'id' => $cto]], 'teste');
T::certo('monta os dois cabos da cadeia', $c1->ok && $c2->ok,
    json_encode([$c1->errors, $c2->errors]));

$vao1 = (int) $c1->data['vaos'][0];
$vao2 = (int) $c2->data['vaos'][0];

// --- POP: OLT, DIO e a porta que vira a origem do sinal
$olt = (int) InsidePlant::criarOlt($pop, ['apelido' => 'PW.OLT', 'fabricante' => 'ZTE',
    'ptx_dbm' => 3.00, 'placas' => [['prefixo' => '0/1', 'portas' => 8, 'inicio' => 1]]],
    'teste')->data['id'];
$dio  = (int) InsidePlant::criarDio($pop, 'PW.DIO', 12, 'teste')->data['id'];
$p01  = (int) InsidePlant::portas($dio)[0]['id'];

InsidePlant::alterarPorta($p01, ['olt_ftth_id' => $olt, 'pon' => '0/1/1',
    'servico' => 'PW CIRCUITO'], null, 'teste');

// --- antes da saída, a fibra não tem origem nenhuma
Potencia::esquecer();
T::igual('sem saída no DIO, a fibra não tem caminho', 'FTTH-PWR-001',
    Potencia::rota($ceo, 'VAO_FIBRA', $vao1, 1)->primeiroCodigo());

T::certo('liga a porta P01 na fibra 1 do backbone',
    InsidePlant::definirSaida($p01, $vao1, 1, 'teste')->ok);

// --- CEO: fibra 1 do backbone alimenta um 1:8, e a saída 1 segue na distribuição
$spl = (int) Topologia::criarSplitter($ceo,
    ['funcao' => 'DERIVACAO', 'razao' => '1:8', 'saidas' => 8],
    'teste')->data['id'];

T::certo('funde o backbone na entrada do splitter',
    Topologia::conectar($ceo,
        ['elemento' => 'VAO_FIBRA',   'elemento_id' => $vao1, 'numero' => 1],
        ['elemento' => 'SPLITTER_IN', 'elemento_id' => $spl,  'numero' => 0],
        null, 'teste')->ok);
T::certo('e a saída 1 na fibra 1 da distribuição',
    Topologia::conectar($ceo,
        ['elemento' => 'SPLITTER_OUT', 'elemento_id' => $spl,  'numero' => 1],
        ['elemento' => 'VAO_FIBRA',    'elemento_id' => $vao2, 'numero' => 1],
        null, 'teste')->ok);

// ------------------------------------------------------------------ a conta
$dbKm    = (float) Db::valor('SELECT db_km FROM tab_ftth_atenuacao WHERE lambda_nm = 1490');
$fusao   = Config::num('perda_fusao_db', 0.10);
$conect  = Config::num('perda_conector_db', 0.50);
$km1     = ((float) Db::valor('SELECT comprimento_optico FROM tab_ftth_cabo_vao WHERE id = ?', [$vao1])) / 1000;
$km2     = ((float) Db::valor('SELECT comprimento_optico FROM tab_ftth_cabo_vao WHERE id = ?', [$vao2])) / 1000;
$perdaSpl = (float) (json_decode((string) Db::valor(
    'SELECT perdas_json FROM tab_ftth_splitter WHERE id = ?', [$spl]), true)[1] ?? 0);

T::certo('o catálogo dá 10,50 dB para a saída de um 1:8', abs($perdaSpl - 10.50) < 0.001, (string) $perdaSpl);

Potencia::esquecer();
$r = Potencia::rota($cto, 'VAO_FIBRA', $vao2, 1);
T::certo('a ponta na CTO tem rota até a origem', $r->ok, json_encode($r->errors));

// DIO -conector-> Fo01 -cabo1-> CEO -fusao-> IN -splitter-> T01 -fusao-> Fo01 -cabo2-> CTO
$esperado = 3.00 - $conect - ($km1 * $dbKm) - $fusao - $perdaSpl - $fusao - ($km2 * $dbKm);
T::certo('e o dBm bate com a conta feita à mão',
    abs($r->data['dbm'] - round($esperado, 2)) < 0.02,
    'motor=' . $r->data['dbm'] . ' conta=' . round($esperado, 2));

T::igual('a rota tem os 6 saltos da cadeia', 6, count($r->data['passos']));
T::igual('o primeiro salto é o conector do DIO', 'CONECTOR', $r->data['passos'][0]['via']);
T::igual('o segundo é o cabo', 'CABO', $r->data['passos'][1]['via']);
T::igual('e o splitter aparece no caminho', 'SPLITTER', $r->data['passos'][3]['via']);
T::certo('o último salto é o cabo de distribuição',
    $r->data['passos'][5]['via'] === 'CABO', $r->data['passos'][5]['via']);

// A origem é o que o painel mostra no cabeçalho.
T::igual('a origem aponta o POP', 'T.PW.POP', $r->data['origem']['caixa']);
T::igual('a OLT', 'PW.OLT', $r->data['origem']['olt']);
T::igual('a porta do DIO', 'P01', $r->data['origem']['porta']);
T::igual('e o TX de saída', 3.00, (float) $r->data['origem']['ptx_dbm']);

// Perde ~10,5 dB no splitter: continua longe do limite, então é sinal bom.
T::certo('classifica o sinal que chegou',
    in_array($r->data['classe'], ['saturado', 'excelente', 'bom', 'limite', 'critico'], true),
    (string) $r->data['classe']);

// ------------------------------------------------------------------ o pulso
$sinalCeo = Potencia::comSinal($ceo);
T::certo('a fibra que chega na CEO tem sinal',
    isset($sinalCeo['VAO_FIBRA:' . $vao1 . ':1']), json_encode(array_keys($sinalCeo)));
T::certo('a entrada do splitter também', isset($sinalCeo['SPLITTER_IN:' . $spl . ':0']));
T::certo('e todas as 8 saídas, mesmo as que não foram fundidas',
    isset($sinalCeo['SPLITTER_OUT:' . $spl . ':8']), json_encode(array_keys($sinalCeo)));
T::certo('a fibra 2 do backbone NÃO tem sinal — ninguém a alimentou',
    !isset($sinalCeo['VAO_FIBRA:' . $vao1 . ':2']));

$sinalCto = Potencia::comSinal($cto);
T::certo('na CTO só a fibra 1 chega com sinal',
    isset($sinalCto['VAO_FIBRA:' . $vao2 . ':1']) && !isset($sinalCto['VAO_FIBRA:' . $vao2 . ':2']),
    json_encode(array_keys($sinalCto)));

T::certo('o dBm do pulso é o mesmo da rota',
    abs($sinalCto['VAO_FIBRA:' . $vao2 . ':1'] - $r->data['dbm']) < 0.001);

// O sinal não sobe de volta pela saída do splitter: a fibra 2 da distribuição, se fosse
// fundida numa saída, receberia sinal; a entrada do splitter jamais recebe de uma saída.
T::igual('a saída do splitter perde exatamente o do catálogo',
    round($sinalCeo['SPLITTER_IN:' . $spl . ':0'] - $perdaSpl, 2),
    $sinalCeo['SPLITTER_OUT:' . $spl . ':1']);

// ------------------------------------------------------------------ laço não trava
// Funde duas fibras do MESMO vão na CEO: o sinal volta para o POP e tenta rodar em círculo.
Topologia::conectar($ceo,
    ['elemento' => 'VAO_FIBRA', 'elemento_id' => $vao1, 'numero' => 3],
    ['elemento' => 'VAO_FIBRA', 'elemento_id' => $vao1, 'numero' => 4], null, 'teste');
Potencia::esquecer();
$r2 = Potencia::rota($cto, 'VAO_FIBRA', $vao2, 1);
T::certo('com fibras do mesmo cabo fundidas, o cálculo ainda termina', $r2->ok);
T::certo('e a rota da CTO não mudou', abs($r2->data['dbm'] - $r->data['dbm']) < 0.001);

// ------------------------------------------------------------------ resumo da caixa
// É o que a ficha do mapa mostra em Serviço, Equipamento, DIO/Porta e estimativa de sinal.
Potencia::esquecer();
$rc = Potencia::resumoDaCaixa($cto);
T::certo('a CTO tem resumo de sinal', (bool) $rc, 'null');
T::igual('com a origem resolvida', 'T.PW.POP', $rc['origem']['caixa'] ?? null);
T::igual('e a PON do equipamento', '0/1/1', $rc['origem']['pon'] ?? null);

// Numa caixa com splitter de ATENDIMENTO o número é o que SAI dele — é o que o cliente
// recebe — e só ali a faixa de qualidade faz sentido, porque só ali existe ONU.
$splAt = (int) Topologia::criarSplitter($cto,
    ['funcao' => 'ATENDIMENTO', 'razao' => '1:8', 'saidas' => 8], 'teste')->data['id'];
Topologia::conectar($cto,
    ['elemento' => 'VAO_FIBRA',   'elemento_id' => $vao2, 'numero' => 1],
    ['elemento' => 'SPLITTER_IN', 'elemento_id' => $splAt, 'numero' => 0], null, 'teste');

Potencia::esquecer();
$rcAt = Potencia::resumoDaCaixa($cto);
T::certo('agora o resumo mede na saída do splitter',
    strpos((string) $rcAt['onde'], 'saída') !== false, (string) $rcAt['onde']);
// A queda é a do splitter MAIS a fusão que alimenta a entrada dele — não só os 10,50.
T::certo('e o valor caiu a perda do 1:8 mais a fusão da entrada',
    abs(($rc['dbm'] - $rcAt['dbm']) - (10.50 + $fusao)) < 0.02,
    'antes=' . $rc['dbm'] . ' depois=' . $rcAt['dbm']
    . ' queda=' . round($rc['dbm'] - $rcAt['dbm'], 2));
T::certo('com faixa de qualidade, porque ali existe ONU', (bool) $rcAt['classe'], 'sem classe');

// Fora do atendimento a faixa não vem: dizer "saturado" numa fibra de backbone seria
// tecnicamente verdade e praticamente enganoso.
T::igual('a CEO de passagem não recebe faixa', null, Potencia::resumoDaCaixa($ceo)['classe']);
T::certo('mas continua trazendo o nível', is_numeric(Potencia::resumoDaCaixa($ceo)['dbm']));
T::igual("caixa sem caminho óptico não tem resumo", null, Potencia::resumoDaCaixa(999999));

// A ponta escolhida sai no resumo para o botão Sinal do mapa poder pedir a rota COMPLETA
// do mesmo ponto. Se ela não reproduzisse o mesmo dBm, a ficha e o detalhe mostrariam
// números diferentes para a mesma caixa — que é exatamente o que essa verificação impede.
$p = $rcAt['ponta'];
T::igual('a ponta do resumo é a saída do splitter de atendimento',
    'SPLITTER_OUT', $p['elemento'] ?? null);
$rr = Potencia::rota($cto, $p['elemento'], $p['elemento_id'], $p['numero']);
T::certo('e a rota dessa ponta é calculável', $rr->ok);
T::igual('com exatamente o dBm que a ficha mostra', $rcAt['dbm'], $rr->data['dbm']);
T::igual('e o mesmo número de saltos', $rcAt['saltos'], count($rr->data['passos']));
