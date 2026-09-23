<?php
/**
 * Suite 07 :: lançamento de cabo e a quebra do traçado em vãos (I1).
 */
require_once __DIR__ . '/../../lib/Cabo.php';
require_once __DIR__ . '/../../lib/Caixa.php';

T::suite('Cabos');

$regiao = (int) Db::valor('SELECT id FROM tab_ftth_regiao WHERE nome = "Palmital"');
$tipo6  = (int) Db::valor('SELECT id FROM tab_ftth_cabo_tipo WHERE rotulo = "6 FO"');

// Três caixas em linha, ~100 m entre elas.
$a = (int) Caixa::criar($regiao, 'CEO', 'T.CABO.A', '#FF9100', -24.8800, -52.2100, 'teste')->data['id'];
$b = (int) Caixa::criar($regiao, 'CTO', 'T.CABO.B', '#43A047', -24.8809, -52.2100, 'teste')->data['id'];
$c = (int) Caixa::criar($regiao, 'CTO', 'T.CABO.C', '#43A047', -24.8818, -52.2100, 'teste')->data['id'];

// --- traçado passando por 3 caixas: tem de virar 2 vãos
$pontos = [
    ['tipo' => 'CAIXA', 'id' => $a],
    ['tipo' => 'VERTICE', 'lat' => -24.8804, 'lng' => -52.2101],
    ['tipo' => 'CAIXA', 'id' => $b],
    ['tipo' => 'VERTICE', 'lat' => -24.8813, 'lng' => -52.2099],
    ['tipo' => 'CAIXA', 'id' => $c],
];
$r = Cabo::criar($regiao, ['cabo_tipo_id' => $tipo6, 'nome' => 'Rota de teste',
                           'padrao_cores' => 'ABNT', 'cor_rota' => '#00E676'], $pontos, 'teste');
T::certo('cria cabo passando por 3 caixas', $r->ok, json_encode($r->errors));
T::igual('gerou 2 vãos', 2, count($r->data['vaos']));

$vaos = Db::todos('SELECT * FROM tab_ftth_cabo_vao WHERE cabo_id = ? ORDER BY ordem', [$r->data['cabo_id']]);
T::igual('vão 1 vai de A para B', [$a, $b], [(int) $vaos[0]['caixa_ini_id'], (int) $vaos[0]['caixa_fim_id']]);
T::igual('vão 2 vai de B para C', [$b, $c], [(int) $vaos[1]['caixa_ini_id'], (int) $vaos[1]['caixa_fim_id']]);
T::igual('vão 1 guardou 3 vértices', 3, count(json_decode($vaos[0]['vertices'], true)));
T::certo('comprimento do vão bate com a distância real',
    abs((float) $vaos[0]['comprimento_geo'] - 100) < 25, 'obtido=' . $vaos[0]['comprimento_geo']);
T::certo('comprimento óptico é maior que o geométrico (folga)',
    (float) $vaos[0]['comprimento_optico'] > (float) $vaos[0]['comprimento_geo']);

// --- recusas
T::igual('recusa traçado que não começa em caixa', 'FTTH-GEO-005',
    Cabo::criar($regiao, ['cabo_tipo_id' => $tipo6],
        [['tipo' => 'VERTICE', 'lat' => -24.88, 'lng' => -52.21], ['tipo' => 'CAIXA', 'id' => $b]],
        'teste')->primeiroCodigo());

T::igual('recusa traçado que não termina em caixa', 'FTTH-GEO-005',
    Cabo::criar($regiao, ['cabo_tipo_id' => $tipo6],
        [['tipo' => 'CAIXA', 'id' => $a], ['tipo' => 'VERTICE', 'lat' => -24.88, 'lng' => -52.21]],
        'teste')->primeiroCodigo());

T::igual('recusa vão com a mesma caixa nas duas pontas', 'FTTH-GEO-003',
    Cabo::criar($regiao, ['cabo_tipo_id' => $tipo6],
        [['tipo' => 'CAIXA', 'id' => $a], ['tipo' => 'VERTICE', 'lat' => -24.8805, 'lng' => -52.2105],
         ['tipo' => 'CAIXA', 'id' => $a]], 'teste')->primeiroCodigo());

T::igual('recusa um ponto só', 'FTTH-GEO-001',
    Cabo::criar($regiao, ['cabo_tipo_id' => $tipo6],
        [['tipo' => 'CAIXA', 'id' => $a]], 'teste')->primeiroCodigo());

T::igual('recusa sem capacidade válida', false,
    Cabo::criar($regiao, ['cabo_tipo_id' => 999999],
        [['tipo' => 'CAIXA', 'id' => $a], ['tipo' => 'CAIXA', 'id' => $b]], 'teste')->ok);

T::igual('recusa caixa inexistente', 'FTTH-TOP-001',
    Cabo::criar($regiao, ['cabo_tipo_id' => $tipo6],
        [['tipo' => 'CAIXA', 'id' => 999999], ['tipo' => 'CAIXA', 'id' => $b]],
        'teste')->primeiroCodigo());

// --- caixa presa a cabo não pode ser excluída
T::igual('caixa com cabo não é excluída', 'FTTH-TOP-016',
    Caixa::excluir($b, null, 'teste')->primeiroCodigo());

// --- exclusão do cabo
T::certo('exclui o cabo inteiro', Cabo::excluir((int) $r->data['cabo_id'], 'teste')->ok);
T::igual('nenhum vão ativo sobrou', 0,
    (int) Db::valor('SELECT COUNT(*) FROM tab_ftth_cabo_vao WHERE cabo_id = ? AND excluido_em IS NULL',
        [$r->data['cabo_id']]));

// --- cabo com fibra ligada não sai
$novo = Cabo::criar($regiao, ['cabo_tipo_id' => $tipo6],
    [['tipo' => 'CAIXA', 'id' => $a], ['tipo' => 'CAIXA', 'id' => $b]], 'teste');
$vaoNovo = (int) $novo->data['vaos'][0];
Db::exec('INSERT INTO tab_ftth_ligacao (caixa_id, tipo, criado_por, criado_em) VALUES (?,?,?,NOW())',
    [$a, 'FUSAO', 'teste']);
$lig = Db::ultimoId();
Db::exec('INSERT INTO tab_ftth_ligacao_ponta (ligacao_id, lado, caixa_id, elemento, elemento_id, numero)
          VALUES (?,?,?,?,?,?)', [$lig, 'A', $a, 'VAO_FIBRA', $vaoNovo, 1]);

T::igual('cabo com fibra ligada não é excluído', 'FTTH-TOP-016',
    Cabo::excluir((int) $novo->data['cabo_id'], 'teste')->primeiroCodigo());

// A recusa precisa dizer ONDE desconectar: sem a caixa, o técnico abre diagrama por diagrama.
$rRec = Cabo::excluir((int) $novo->data['cabo_id'], 'teste');
$det  = $rRec->errors[0]['details'] ?? [];
T::igual('a recusa diz em quantas caixas há fibra presa', 1, count($det['caixas'] ?? []));
T::igual('e diz qual é a caixa', 'T.CABO.A', $det['caixas'][0]['nome'] ?? null);
T::certo('a mensagem cita a caixa pelo nome',
    strpos((string) ($rRec->errors[0]['message'] ?? ''), 'T.CABO.A') !== false,
    (string) ($rRec->errors[0]['message'] ?? ''));
Db::exec('DELETE FROM tab_ftth_ligacao WHERE id = ?', [$lig]);
T::certo('depois de desconectar, o cabo sai', Cabo::excluir((int) $novo->data['cabo_id'], 'teste')->ok);

// ------------------------------------------------------------------ editar atributos
// Decisão de 22/09/2026: "Editar cabo" mexe nos dados, nunca no traçado.
$tipo12 = (int) Db::valor('SELECT id FROM tab_ftth_cabo_tipo WHERE rotulo = "12 FO"');

$rEd = Cabo::criar($regiao, ['cabo_tipo_id' => $tipo12, 'nome' => 'Rota editável'],
    [['tipo' => 'CAIXA', 'id' => $a], ['tipo' => 'CAIXA', 'id' => $b]], 'teste');
T::certo('cria o cabo que será editado', $rEd->ok, json_encode($rEd->errors));
$caboEd = (int) $rEd->data['cabo_id'];
$vaoEd  = (int) $rEd->data['vaos'][0];
$vEd    = (int) Db::valor('SELECT versao FROM tab_ftth_cabo WHERE id = ?', [$caboEd]);
$geoAntes = Db::valor('SELECT comprimento_geo FROM tab_ftth_cabo_vao WHERE id = ?', [$vaoEd]);

T::igual('recusa cabo inexistente', 'FTTH-TOP-002',
    Cabo::alterar(999999, [], null, 'teste')->primeiroCodigo());
T::igual('recusa capacidade que não existe no catálogo', 'FTTH-SYS-002',
    Cabo::alterar($caboEd, ['cabo_tipo_id' => 999999], $vEd, 'teste')->primeiroCodigo());

$r = Cabo::alterar($caboEd, ['nome' => 'Backbone Centro', 'fabricante' => 'Furukawa',
    'cabo_tipo_id' => $tipo12, 'padrao_cores' => 'ABNT', 'cor_rota' => '#0D47A1',
    'status' => 'certificado'], $vEd, 'teste');
T::certo('grava nome, fabricante, cor e situação', $r->ok, json_encode($r->errors));

$cabo = Db::um('SELECT * FROM tab_ftth_cabo WHERE id = ?', [$caboEd]);
T::igual('o nome mudou', 'Backbone Centro', $cabo['nome']);
T::igual('o fabricante mudou', 'Furukawa', $cabo['fabricante']);
T::igual('a cor da rota mudou', '#0D47A1', $cabo['cor_rota']);
T::igual('a situação mudou', 'certificado', $cabo['status']);
T::igual('e a versão avançou', $vEd + 1, (int) $cabo['versao']);

// O ponto que dá nome à decisão: editar atributo não pode mexer na geometria.
T::igual('o traçado ficou intacto', $geoAntes,
    Db::valor('SELECT comprimento_geo FROM tab_ftth_cabo_vao WHERE id = ?', [$vaoEd]));
T::igual('e o vão continua entre as mesmas caixas', [$a, $b], array_map('intval',
    array_values(Db::um('SELECT caixa_ini_id, caixa_fim_id FROM tab_ftth_cabo_vao WHERE id = ?',
        [$vaoEd]))));

T::igual('recusa versão velha', 'FTTH-CONC-001',
    Cabo::alterar($caboEd, ['nome' => 'X'], $vEd, 'teste')->primeiroCodigo());

// Trocar o padrão de cores é permitido, mas avisa: o diagrama vai mudar de cor.
$vEd = (int) Db::valor('SELECT versao FROM tab_ftth_cabo WHERE id = ?', [$caboEd]);
$r = Cabo::alterar($caboEd, ['padrao_cores' => 'EIA-TIA'], $vEd, 'teste');
T::certo('troca o padrão de cores', $r->ok, json_encode($r->errors));
T::igual('e avisa que as fibras mudam de cor', 'FTTH-TOP-019', $r->warnings[0]['code'] ?? null);

// Encolher: só é recusado quando há fibra ligada ACIMA da nova capacidade.
Db::exec('INSERT INTO tab_ftth_ligacao (caixa_id, tipo, criado_por, criado_em) VALUES (?,?,?,NOW())',
    [$a, 'FUSAO', 'teste']);
$ligEd = Db::ultimoId();
Db::exec('INSERT INTO tab_ftth_ligacao_ponta (ligacao_id, lado, caixa_id, elemento, elemento_id, numero)
          VALUES (?,?,?,?,?,?)', [$ligEd, 'A', $a, 'VAO_FIBRA', $vaoEd, 9]);

$vEd = (int) Db::valor('SELECT versao FROM tab_ftth_cabo WHERE id = ?', [$caboEd]);
T::igual('recusa reduzir para 6 FO com a fibra 9 ligada', 'FTTH-TOP-003',
    Cabo::alterar($caboEd, ['cabo_tipo_id' => $tipo6], $vEd, 'teste')->primeiroCodigo());

Db::exec('UPDATE tab_ftth_ligacao_ponta SET numero = 3 WHERE ligacao_id = ?', [$ligEd]);
T::certo('com a fibra 3 ligada, reduzir para 6 FO é permitido',
    Cabo::alterar($caboEd, ['cabo_tipo_id' => $tipo6], $vEd, 'teste')->ok);
T::igual('e o cabo passou a ter 6 fibras', $tipo6,
    (int) Db::valor('SELECT cabo_tipo_id FROM tab_ftth_cabo WHERE id = ?', [$caboEd]));

Db::exec('DELETE FROM tab_ftth_ligacao WHERE id = ?', [$ligEd]);
