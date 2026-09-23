<?php
/**
 * Suite 06 :: criação, alteração, movimentação e exclusão de caixa pelo serviço.
 */
require_once __DIR__ . '/../../lib/Caixa.php';

T::suite('Caixas');

$regiao = (int) Db::valor('SELECT id FROM tab_ftth_regiao WHERE nome = "Palmital"');

$r = Caixa::criar($regiao, 'CEO', 'CEO.99.01', '#FF9100', -24.8850, -52.2100, 'teste');
T::certo('cria caixa', $r->ok, json_encode($r->errors));
$nova = (int) $r->data['id'];

T::igual('recusa nome repetido', false,
    Caixa::criar($regiao, 'CEO', 'CEO.99.01', '#FF9100', -24.88, -52.21, 'teste')->ok);
T::igual('recusa tipo inválido', false,
    Caixa::criar($regiao, 'BANANA', 'X.01', '#FF9100', -24.88, -52.21, 'teste')->ok);
T::igual('recusa coordenada fora de faixa', 'FTTH-GEO-002',
    Caixa::criar($regiao, 'CTO', 'CTO.99.99', '#FF9100', 91.0, -52.21, 'teste')->primeiroCodigo());
T::igual('recusa nome vazio', false,
    Caixa::criar($regiao, 'CTO', '   ', '#FF9100', -24.88, -52.21, 'teste')->ok);
T::igual('recusa região inexistente', false,
    Caixa::criar(999999, 'CTO', 'CTO.98.01', '#FF9100', -24.88, -52.21, 'teste')->ok);

$c = Db::um('SELECT * FROM tab_ftth_caixa WHERE id = ?', [$nova]);
T::igual('origem é manual', 'manual', $c['origem']);
T::igual('guardou a cor escolhida', '#FF9100', $c['cor']);
T::certo('auditoria registrou a criação',
    (int) Db::valor('SELECT COUNT(*) FROM tab_ftth_historico WHERE entidade = "caixa" AND entidade_id = ? AND acao = "criar"',
        [$nova]) === 1);

// Sugestão de nome segue o padrão do provedor.
T::igual('sugere o próximo número', 'CEO.99.02', Caixa::sugerirNome($regiao, 'CEO', 'CEO.99.01'));
// A rede real já tem CTO.02.06 a CTO.02.08 — a sugestão precisa pular os ocupados.
$sug = Caixa::sugerirNome($regiao, 'CTO', 'CTO.02.05');
T::igual('pula os nomes ocupados e sugere o primeiro livre', 'CTO.02.09', $sug);
T::igual('o nome sugerido realmente está livre', null,
    Db::valor('SELECT id FROM tab_ftth_caixa WHERE regiao_id = ? AND nome = ?', [$regiao, $sug]));

// Mover
$v = (int) Db::valor('SELECT versao FROM tab_ftth_caixa WHERE id = ?', [$nova]);
T::certo('move a caixa', Caixa::mover($nova, -24.8860, -52.2110, $v, 'teste')->ok);
T::igual('recusa mover com versão velha', 'FTTH-CONC-001',
    Caixa::mover($nova, -24.8861, -52.2111, $v, 'teste')->primeiroCodigo());

$dep = Db::um('SELECT lat, lng FROM tab_ftth_caixa WHERE id = ?', [$nova]);
T::igual('coordenada nova gravada', '-24.8860000', $dep['lat']);

// Alterar
$v = (int) Db::valor('SELECT versao FROM tab_ftth_caixa WHERE id = ?', [$nova]);
$alt = Caixa::alterar($nova, ['nome' => 'CEO.99.10', 'cor' => '#8E24AA', 'capacidade' => 16], $v, 'teste');
T::certo('altera nome, cor e capacidade', $alt->ok, json_encode($alt->errors));
T::igual('nome alterado', 'CEO.99.10', Db::valor('SELECT nome FROM tab_ftth_caixa WHERE id = ?', [$nova]));

// Excluir: caixa presa a cabo não sai.
$presa = (int) Db::valor('SELECT caixa_ini_id FROM tab_ftth_cabo_vao LIMIT 1');
if ($presa) {
    T::igual('recusa excluir caixa com cabo', 'FTTH-TOP-016',
        Caixa::excluir($presa, null, 'teste')->primeiroCodigo());
}

$v = (int) Db::valor('SELECT versao FROM tab_ftth_caixa WHERE id = ?', [$nova]);
T::certo('exclui caixa solta', Caixa::excluir($nova, $v, 'teste')->ok);
T::certo('exclusão é lógica (registro continua)',
    Db::um('SELECT excluido_em FROM tab_ftth_caixa WHERE id = ?', [$nova])['excluido_em'] !== null);
T::igual('nome excluído NÃO volta a ser usado', false,
    Caixa::criar($regiao, 'CEO', 'CEO.99.10', '#FF9100', -24.88, -52.21, 'teste')->ok);
