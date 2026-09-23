<?php
/**
 * Suite 10 :: conectividade — a matriz de conexões, ligar, desligar e o payload do diagrama.
 *
 * É a suíte que guarda a regra mais cara do addon: nenhum caminho pode criar estado
 * topológico inválido. Por isso `Topologia::invariantes()` é chamada depois de cada bloco
 * e precisa voltar vazia.
 */
require_once __DIR__ . '/../../lib/Topologia.php';
require_once __DIR__ . '/../../lib/Cabo.php';
require_once __DIR__ . '/../../lib/Fibra.php';

T::suite('Conectividade — matriz, ligações e diagrama');

$regiao = (int) Db::valor('SELECT id FROM tab_ftth_regiao WHERE nome = "Palmital"');
$tipo6  = (int) Db::valor('SELECT id FROM tab_ftth_cabo_tipo WHERE rotulo = "6 FO"');
$tipo12 = (int) Db::valor('SELECT id FROM tab_ftth_cabo_tipo WHERE rotulo = "12 FO MULT (2x6)"');

// Uma CEO no meio de duas caixas: o cabo entra por um lado e sai pelo outro, que é o
// arranjo de uma caixa de passagem real.
$norte = (int) Caixa::criar($regiao, 'CTO', 'T.LIG.N', '#43A047', -24.8700, -52.2200, 'teste')->data['id'];
$ceo   = (int) Caixa::criar($regiao, 'CEO', 'T.LIG.CEO', '#FF9100', -24.8709, -52.2200, 'teste')->data['id'];
$sul   = (int) Caixa::criar($regiao, 'CTO', 'T.LIG.S', '#43A047', -24.8718, -52.2200, 'teste')->data['id'];
$fora  = (int) Caixa::criar($regiao, 'CTO', 'T.LIG.FORA', '#43A047', -24.8730, -52.2200, 'teste')->data['id'];

$rCabo = Cabo::criar($regiao, ['cabo_tipo_id' => $tipo6, 'nome' => 'Cabo de teste da CEO',
                               'padrao_cores' => 'ABNT', 'cor_rota' => '#00E676'],
    [['tipo' => 'CAIXA', 'id' => $norte],
     ['tipo' => 'CAIXA', 'id' => $ceo],
     ['tipo' => 'CAIXA', 'id' => $sul]], 'teste');
T::certo('cria o cabo que atravessa a CEO', $rCabo->ok, json_encode($rCabo->errors));

$vaos = Db::todos('SELECT id FROM tab_ftth_cabo_vao WHERE cabo_id = ? ORDER BY ordem',
    [$rCabo->data['cabo_id']]);
$vaoN = (int) $vaos[0]['id'];   // norte -> ceo
$vaoS = (int) $vaos[1]['id'];   // ceo -> sul

// Um cabo que NÃO passa pela CEO, para testar ponta de outra caixa.
$rForaCabo = Cabo::criar($regiao, ['cabo_tipo_id' => $tipo6, 'padrao_cores' => 'ABNT'],
    [['tipo' => 'CAIXA', 'id' => $sul], ['tipo' => 'CAIXA', 'id' => $fora]], 'teste');
$vaoFora = (int) Db::valor('SELECT id FROM tab_ftth_cabo_vao WHERE cabo_id = ?',
    [$rForaCabo->data['cabo_id']]);

$splA = (int) Topologia::criarSplitter($ceo, ['funcao' => 'ATENDIMENTO', 'nome' => 'SPL.AT',
    'razao' => '1:8', 'saidas' => 8], 'teste')->data['id'];
$splD = (int) Topologia::criarSplitter($ceo, ['funcao' => 'DERIVACAO', 'nome' => 'SPL.DER',
    'razao' => '1:8', 'saidas' => 8], 'teste')->data['id'];

/** Atalhos para montar ponta. */
$fibra = function (int $vao, int $n) { return ['elemento' => 'VAO_FIBRA', 'elemento_id' => $vao, 'numero' => $n]; };
$in    = function (int $spl) { return ['elemento' => 'SPLITTER_IN', 'elemento_id' => $spl, 'numero' => 0]; };
$out   = function (int $spl, int $n) { return ['elemento' => 'SPLITTER_OUT', 'elemento_id' => $spl, 'numero' => $n]; };

// ------------------------------------------------------------------ pares permitidos
$r = Topologia::conectar($ceo, $fibra($vaoN, 2), $in($splD), null, 'teste');
T::certo('liga fibra do cabo na entrada do splitter de derivação', $r->ok, json_encode($r->errors));
$lig1 = (int) $r->data['id'];
T::igual('a ligação nasceu com exatamente duas pontas', 2,
    (int) Db::valor('SELECT COUNT(*) FROM tab_ftth_ligacao_ponta WHERE ligacao_id = ?', [$lig1]));
T::igual('o tipo padrão é fusão', 'FUSAO', Db::valor('SELECT tipo FROM tab_ftth_ligacao WHERE id = ?', [$lig1]));

$r = Topologia::conectar($ceo, $out($splD, 1), $in($splA), null, 'teste');
T::certo('liga saída da derivação na entrada do atendimento', $r->ok, json_encode($r->errors));

$r = Topologia::conectar($ceo, $out($splD, 2), $fibra($vaoS, 1), null, 'teste');
T::certo('liga saída da derivação numa fibra que sai', $r->ok, json_encode($r->errors));

// Saída de ATENDIMENTO é o fim da linha: dali sai o drop do cliente, que não é
// documentado no diagrama. Quem encadeia splitter e alimenta o próximo trecho é a
// DERIVAÇÃO — fundir uma saída de atendimento seria documentar rede que não existe.
T::igual('recusa fundir saída de splitter de atendimento', 'FTTH-TOP-020',
    Topologia::conectar($ceo, $out($splA, 1), $fibra($vaoS, 2), null, 'teste')->primeiroCodigo());
T::igual('recusa mesmo com a outra ponta sendo outro splitter', 'FTTH-TOP-020',
    Topologia::conectar($ceo, $out($splA, 2), $in($splD), null, 'teste')->primeiroCodigo());
T::certo('e a fibra que seria usada continua livre',
    Topologia::fibrasDoVao($vaoS, $ceo)[1]['estado'] === 'livre');

// A fusão de passagem: fibra que entra e segue direto, sem derivar. É o que documenta uma CEO.
$r = Topologia::conectar($ceo, $fibra($vaoN, 5), $fibra($vaoS, 5), null, 'teste');
T::certo('funde fibra em fibra (passagem manual)', $r->ok, json_encode($r->errors));
$ligPassagem = (int) $r->data['id'];

T::igual('nenhuma invariante violada até aqui', [], Topologia::invariantes($ceo));

// ------------------------------------------------------------------ pares proibidos
T::igual('recusa entrada contra entrada', 'FTTH-TOP-017',
    Topologia::conectar($ceo, $in($splA), $in($splD), null, 'teste')->primeiroCodigo());
T::igual('recusa saída contra saída', 'FTTH-TOP-017',
    Topologia::conectar($ceo, $out($splA, 3), $out($splD, 2), null, 'teste')->primeiroCodigo());
T::igual('recusa entrada e saída do mesmo splitter', 'FTTH-TOP-018',
    Topologia::conectar($ceo, $in($splA), $out($splA, 4), null, 'teste')->primeiroCodigo());
T::igual('recusa a ponta ligada nela mesma', 'FTTH-TOP-008',
    Topologia::conectar($ceo, $fibra($vaoN, 4), $fibra($vaoN, 4), null, 'teste')->primeiroCodigo());
// Porta de DIO é equipamento de POP: numa CEO de rua não existe DIO nenhum, e aceitar a
// ponta ali seria documentar uma rede que não existe. A regra é do serviço, não da tela.
T::igual('recusa porta de DIO fora de uma caixa DC', 'FTTH-TOP-017',
    Topologia::conectar($ceo, ['elemento' => 'DIO_PORTA', 'elemento_id' => 1, 'numero' => 0],
        $fibra($vaoN, 4), null, 'teste')->primeiroCodigo());
T::igual('recusa elemento desconhecido', 'FTTH-SYS-002',
    Topologia::conectar($ceo, ['elemento' => 'BANANA', 'elemento_id' => 1, 'numero' => 1],
        $fibra($vaoN, 4), null, 'teste')->primeiroCodigo());

// ------------------------------------------------------------------ ponta ocupada e faixa
T::igual('recusa fibra que já está conectada', 'FTTH-TOP-004',
    Topologia::conectar($ceo, $fibra($vaoN, 2), $out($splD, 5), null, 'teste')->primeiroCodigo());
T::igual('recusa entrada de splitter já conectada', 'FTTH-TOP-005',
    Topologia::conectar($ceo, $fibra($vaoN, 3), $in($splD), null, 'teste')->primeiroCodigo());
T::igual('recusa saída de splitter já conectada', 'FTTH-TOP-005',
    Topologia::conectar($ceo, $fibra($vaoN, 3), $out($splD, 2), null, 'teste')->primeiroCodigo());
T::igual('recusa fibra acima da capacidade do cabo', 'FTTH-TOP-003',
    Topologia::conectar($ceo, $fibra($vaoN, 7), $out($splD, 3), null, 'teste')->primeiroCodigo());
T::igual('recusa saída acima do número de saídas', 'FTTH-TOP-009',
    Topologia::conectar($ceo, $fibra($vaoN, 3), $out($splD, 9), null, 'teste')->primeiroCodigo());
T::igual('recusa vão que não encosta nesta caixa', 'FTTH-TOP-002',
    Topologia::conectar($ceo, $fibra($vaoFora, 1), $out($splD, 3), null, 'teste')->primeiroCodigo());
T::igual('recusa splitter de outra caixa', 'FTTH-TOP-009',
    Topologia::conectar($norte, $fibra($vaoN, 3), $in($splA), null, 'teste')->primeiroCodigo());

T::igual('as recusas não deixaram sujeira', [], Topologia::invariantes($ceo));

// ------------------------------------------------------------------ o banco é a última defesa
// A validação dá a mensagem boa; o índice único é quem garante que nem uma corrida entre dois
// usuários cria a segunda ponta. Aqui o INSERT é feito por fora, de propósito.
$antesPontas = (int) Db::valor('SELECT COUNT(*) FROM tab_ftth_ligacao_ponta WHERE caixa_id = ?', [$ceo]);
$barrouNoBanco = false;
$sqlstate = '';
try {
    Db::exec('INSERT INTO tab_ftth_ligacao_ponta (ligacao_id, lado, caixa_id, elemento, elemento_id, numero)
              VALUES (?,?,?,?,?,?)', [$ligPassagem, 'A', $ceo, 'VAO_FIBRA', $vaoN, 5]);
} catch (PDOException $e) {
    $barrouNoBanco = true;
    $sqlstate = (string) $e->getCode();
}
T::certo('o índice único barra uma segunda ponta na mesma fibra', $barrouNoBanco);
T::igual('e o erro é de violação de integridade (o que o catch converte)', '23000', $sqlstate);
T::igual('nenhuma ponta extra ficou gravada', $antesPontas,
    (int) Db::valor('SELECT COUNT(*) FROM tab_ftth_ligacao_ponta WHERE caixa_id = ?', [$ceo]));

// ------------------------------------------------------------------ atomicidade
// Uma falha depois do conectar derruba a ligação inteira: nunca sobra ligação sem ponta.
$antesLig = (int) Db::valor('SELECT COUNT(*) FROM tab_ftth_ligacao WHERE caixa_id = ?', [$ceo]);
try {
    Db::transacao(function () use ($ceo, $fibra, $out, $vaoN, $splD) {
        $r = Topologia::conectar($ceo, $fibra($vaoN, 6), $out($splD, 6), null, 'teste');
        if (!$r->ok) {
            throw new RuntimeException('conectar falhou antes da hora: ' . json_encode($r->errors));
        }
        throw new RuntimeException('falha proposital depois de conectar');
    });
} catch (Throwable $e) {
    // esperado
}
T::igual('a ligação não sobreviveu ao rollback', $antesLig,
    (int) Db::valor('SELECT COUNT(*) FROM tab_ftth_ligacao WHERE caixa_id = ?', [$ceo]));
T::igual('e a fibra continua livre', 'livre',
    Topologia::fibrasDoVao($vaoN, $ceo)[5]['estado']);
T::igual('nenhuma ponta órfã ficou para trás', [], Topologia::invariantes($ceo));

// ------------------------------------------------------------------ desconectar
T::igual('recusa desconectar ligação inexistente', 'FTTH-TOP-015',
    Topologia::desconectar(999999, 'teste')->primeiroCodigo());

$r = Topologia::desconectar($ligPassagem, 'teste');
T::certo('desconecta a fusão de passagem', $r->ok, json_encode($r->errors));
T::igual('a ligação sumiu', 0,
    (int) Db::valor('SELECT COUNT(*) FROM tab_ftth_ligacao WHERE id = ?', [$ligPassagem]));
T::igual('as pontas saíram junto (cascade)', 0,
    (int) Db::valor('SELECT COUNT(*) FROM tab_ftth_ligacao_ponta WHERE ligacao_id = ?', [$ligPassagem]));
T::igual('a fibra 5 do vão norte voltou a ficar livre', 'livre',
    Topologia::fibrasDoVao($vaoN, $ceo)[4]['estado']);
T::certo('o histórico guardou as duas pontas desfeitas',
    (int) Db::valor('SELECT COUNT(*) FROM tab_ftth_historico
                      WHERE entidade = "ligacao" AND entidade_id = ? AND acao = "desconectar"
                        AND JSON_LENGTH(antes, "$.pontas") = 2', [$ligPassagem]) === 1);
T::certo('e a fibra pode ser religada depois de desconectar',
    Topologia::conectar($ceo, $fibra($vaoN, 5), $fibra($vaoS, 5), null, 'teste')->ok);

// ------------------------------------------------------------------ cores das fibras
$fibrasN = Topologia::fibrasDoVao($vaoN, $ceo);
T::igual('o vão tem as 6 fibras do tipo de cabo', 6, count($fibrasN));
T::igual('fibra 1 é verde na ABNT', 'Verde', $fibrasN[0]['cor_nome']);
T::igual('fibra 2 é amarela', 'Amarelo', $fibrasN[1]['cor_nome']);
T::igual('fibra 3 é branca', 'Branco', $fibrasN[2]['cor_nome']);
T::igual('fibra 6 é violeta', 'Violeta', $fibrasN[5]['cor_nome']);
T::igual('o rótulo segue o formato de campo', 'Fo01', $fibrasN[0]['rotulo']);
T::certo('a fibra branca tem contorno para aparecer no canvas', $fibrasN[2]['contorno'] !== null);

// Multitubo: a sequência reinicia a cada tubo.
$rMult = Cabo::criar($regiao, ['cabo_tipo_id' => $tipo12, 'padrao_cores' => 'ABNT'],
    [['tipo' => 'CAIXA', 'id' => $ceo], ['tipo' => 'CAIXA', 'id' => $fora]], 'teste');
$vaoMult = (int) Db::valor('SELECT id FROM tab_ftth_cabo_vao WHERE cabo_id = ?', [$rMult->data['cabo_id']]);
$fibrasM = Topologia::fibrasDoVao($vaoMult, $ceo);
T::igual('o cabo multitubo tem 12 fibras', 12, count($fibrasM));
T::igual('a fibra 6 fecha o tubo 1', 1, $fibrasM[5]['tubo']);
T::igual('a fibra 7 abre o tubo 2', 2, $fibrasM[6]['tubo']);
T::igual('e volta a ser verde no tubo 2', 'Verde', $fibrasM[6]['cor_nome']);
T::igual('a cor do tubo 2 é a segunda da sequência', '#FFD600', $fibrasM[6]['cor_tubo']);

// EIA-TIA usa outra ordem.
T::igual('na EIA-TIA a fibra 1 é azul', 'Azul', Fibra::cor(1, 'EIA-TIA')['nome']);
T::igual('padrão desconhecido cai na ABNT', 'Verde', Fibra::cor(1, 'QUALQUER')['nome']);

// ------------------------------------------------------------------ payload do diagrama
$d = Topologia::diagrama($ceo);
T::igual('o diagrama traz a caixa certa', 'T.LIG.CEO', $d['caixa']['nome']);
T::igual('traz os vãos que encostam na caixa', 3, count($d['vaos']));
T::certo('cada vão sabe para onde vai',
    in_array('ST T.LIG.N', array_column($d['vaos'], 'sentido'), true), json_encode(array_column($d['vaos'], 'sentido')));
T::igual('traz os dois splitters', 2, count($d['splitters']));
T::certo('o splitter de atendimento está alimentado',
    !$d['splitters'][0]['sem_alimentacao'] || !$d['splitters'][1]['sem_alimentacao']);
T::igual('traz as ligações com as duas pontas resolvidas', 0,
    count(array_filter($d['ligacoes'], static function ($l) { return $l['a'] === null || $l['b'] === null; })));
T::certo('todo nó tem posição no layout',
    count($d['layout']) === count($d['vaos']) + count($d['splitters']), json_encode($d['layout']));

// Splitter sem entrada é estado legítimo durante a edição — mas precisa aparecer como pendência.
$splSozinho = (int) Topologia::criarSplitter($ceo, ['funcao' => 'ATENDIMENTO', 'nome' => 'SPL.SEMIN',
    'razao' => '1:4', 'saidas' => 4], 'teste')->data['id'];
$d = Topologia::diagrama($ceo);
$semIn = null;
foreach ($d['splitters'] as $s) {
    if ((int) $s['id'] === $splSozinho) {
        $semIn = $s;
    }
}
T::certo('splitter recém-criado aparece como sem alimentação', $semIn['sem_alimentacao'] ?? false);
T::igual('e isso não é uma violação de invariante', [], Topologia::invariantes($ceo));

// O desenho é reconstruído do banco: duas leituras seguidas dão o mesmo grafo.
$d2 = Topologia::diagrama($ceo);
T::igual('reabrir a caixa devolve exatamente o mesmo grafo',
    json_encode($d['ligacoes']), json_encode($d2['ligacoes']));

// ------------------------------------------------------------------ layout
$r = Topologia::salvarLayout($ceo, [
    ['tipo' => 'VAO', 'elemento_id' => $vaoN, 'pos_x' => 100, 'pos_y' => 200],
    ['tipo' => 'SPLITTER', 'elemento_id' => $splA, 'pos_x' => 400, 'pos_y' => 120, 'rotacao' => 90],
], 'teste');
T::certo('salva o layout', $r->ok, json_encode($r->errors));
T::igual('gravou os dois nós movidos', 2, $r->data['gravados']);

$d = Topologia::diagrama($ceo);
T::igual('a posição do vão voltou como salva', [100, 200],
    [$d['layout']['VAO:' . $vaoN]['pos_x'], $d['layout']['VAO:' . $vaoN]['pos_y']]);
T::igual('a rotação do splitter voltou', 90, $d['layout']['SPLITTER:' . $splA]['rotacao']);

T::certo('salvar de novo é idempotente',
    Topologia::salvarLayout($ceo, [['tipo' => 'VAO', 'elemento_id' => $vaoN, 'pos_x' => 100, 'pos_y' => 200]],
        'teste')->ok);
T::igual('e não duplica a linha do nó', 1,
    (int) Db::valor('SELECT COUNT(*) FROM tab_ftth_diagrama_no WHERE caixa_id = ? AND tipo = "VAO" AND elemento_id = ?',
        [$ceo, $vaoN]));

$r = Topologia::salvarLayout($ceo, [
    ['tipo' => 'VAO', 'elemento_id' => $vaoFora, 'pos_x' => 10, 'pos_y' => 10],
    ['tipo' => 'BANANA', 'elemento_id' => 1, 'pos_x' => 10, 'pos_y' => 10],
], 'teste');
T::igual('ignora nó que não é desta caixa e tipo inválido', 0, $r->data['gravados']);
T::igual('recusa layout de caixa inexistente', 'FTTH-TOP-001',
    Topologia::salvarLayout(999999, [], 'teste')->primeiroCodigo());

T::igual('layout não mexeu na topologia', [], Topologia::invariantes($ceo));
T::igual('e não gerou auditoria', 0,
    (int) Db::valor('SELECT COUNT(*) FROM tab_ftth_historico WHERE entidade = "diagrama_no"'));

// ------------------------------------------------------------------ exclusão protegida
T::igual('recusa excluir splitter com fibra ligada', 'FTTH-TOP-016',
    Topologia::excluirSplitter($splD, null, 'teste')->primeiroCodigo());

// ------------------------------------------------------------------ fibra tem DUAS pontas
// O vão liga duas caixas e a fibra tem uma ponta em cada. Fundida na CEO e livre na CTO é o
// caso normal de campo. Faltava este teste, e por isso passou despercebido que o diagrama de
// uma caixa mostrava como conectada uma fibra emendada na OUTRA ponta do mesmo vão — sem
// linha no desenho, e sem deixar ligá-la ali.
$fibraLivre = 4;
T::certo('a fibra escolhida está livre nas duas pontas',
    Topologia::fibrasDoVao($vaoN, $ceo)[$fibraLivre - 1]['estado'] === 'livre'
    && Topologia::fibrasDoVao($vaoN, $norte)[$fibraLivre - 1]['estado'] === 'livre');

// Conecta a fibra dentro da caixa NORTE (a outra ponta do vão).
$splN = (int) Topologia::criarSplitter($norte, ['funcao' => 'ATENDIMENTO', 'nome' => 'SPL.N',
    'razao' => '1:2', 'saidas' => 2], 'teste')->data['id'];
$rN = Topologia::conectar($norte, $fibra($vaoN, $fibraLivre), $in($splN), null, 'teste');
T::certo('conecta a fibra na caixa da outra ponta', $rN->ok, json_encode($rN->errors));

T::igual('lá ela aparece conectada', 'conectada',
    Topologia::fibrasDoVao($vaoN, $norte)[$fibraLivre - 1]['estado']);
T::igual('e aqui continua LIVRE — a ponta desta caixa não foi usada', 'livre',
    Topologia::fibrasDoVao($vaoN, $ceo)[$fibraLivre - 1]['estado']);

T::certo('e por isso ainda dá para ligá-la nesta caixa',
    Topologia::conectar($ceo, $fibra($vaoN, $fibraLivre), $out($splD, 8), null, 'teste')->ok);
T::igual('agora sim conectada nas duas pontas, cada uma na sua ligação', 'conectada',
    Topologia::fibrasDoVao($vaoN, $ceo)[$fibraLivre - 1]['estado']);
T::certo('e as duas ligações são diferentes',
    Topologia::fibrasDoVao($vaoN, $ceo)[$fibraLivre - 1]['ligacao_id']
    !== Topologia::fibrasDoVao($vaoN, $norte)[$fibraLivre - 1]['ligacao_id']);

T::igual('nenhuma invariante violada nas duas caixas',
    [], array_merge(Topologia::invariantes($ceo), Topologia::invariantes($norte)));
