<?php
/**
 * Suite 04 :: importacao de KMZ em quarentena, contra o arquivo REAL da rede.
 *
 * O arquivo fica em tests/dados/AsBuilt_Palmital.kmz (copia do rede_ftth/ do repositorio).
 * Numeros esperados, medidos na analise do arquivo em 15/09/2026:
 *   221 pontos  | 212 linhas | 30,5 km | 4 vaos com as duas pontas na mesma caixa
 */
require_once __DIR__ . '/../../lib/Kmz.php';

T::suite('KMZ — leitura do arquivo real');

// O KMZ real nao vai para o repositorio: e a planta de um provedor de verdade, e o repo e
// publico. Quem tiver o arquivo roda esta suite; quem nao tiver ve o aviso e segue.
$arquivo = __DIR__ . '/../dados/AsBuilt_Palmital.kmz';
if (!is_file($arquivo)) {
    echo '  --   suite pulada: depende de tests/dados/AsBuilt_Palmital.kmz, que nao vai para o repositorio', PHP_EOL;
    return;
}

$lido = Kmz::ler($arquivo);
$r = $lido['resumo'];

T::igual('221 pontos', 221, $r['pontos']);
T::igual('212 linhas', 212, $r['linhas']);
T::igual('nada ignorado', 0, $r['ignorados']);
T::certo('30,5 km de cabo', abs($r['metros'] - 30490) < 300, 'obtido=' . round($r['metros']));
T::igual('178 CTOs', 178, $r['tipos']['CTO'] ?? 0);
T::igual('41 CEOs', 41, $r['tipos']['CEO'] ?? 0);
T::igual('1 DC (Pop1)', 1, $r['tipos']['DC'] ?? 0);
T::igual('1 problema', 1, $r['tipos']['PROBLEMA'] ?? 0);

$caixas = array_values(array_filter($lido['itens'], fn($i) => $i['tipo_sugerido'] === 'CAIXA'));
$vaos   = array_values(array_filter($lido['itens'], fn($i) => $i['tipo_sugerido'] === 'VAO'));

T::certo('todo item tem nome', count(array_filter($caixas, fn($c) => $c['nome'] === null)) === 0);
T::certo('cor do icone virou hex', preg_match('/^#[0-9A-F]{6}$/', $caixas[0]['cor']) === 1, $caixas[0]['cor']);
T::certo('cor da linha virou hex', preg_match('/^#[0-9A-F]{6}$/', $vaos[0]['cor']) === 1, $vaos[0]['cor']);

$rotulos = array_count_values(array_map(fn($v) => (string) $v['subtipo'], $vaos));
T::igual('194 vaos de 6 FO', 194, $rotulos['6 FO'] ?? 0);
T::igual('8 vaos de 12 FO',   8, $rotulos['12 FO'] ?? 0);
T::igual('10 vaos de 24 FO', 10, $rotulos['24 FO'] ?? 0);

// Dos 4 vaos que comecam e terminam na mesma caixa, 3 tem 0 m e um tem 2,8 m —
// por isso "menos de 1 metro" sao 3, e "duas pontas na mesma caixa" sao 4 (testado adiante).
$comAlerta = array_filter($vaos, fn($v) => $v['alertas'] !== []);
T::igual('3 vaos com menos de 1 metro', 3, count($comAlerta));

T::suite('KMZ — quarentena');

Db::exec('DELETE FROM tab_ftth_importacao_item');
Db::exec('DELETE FROM tab_ftth_importacao');
$regiao = (int) Db::valor('SELECT id FROM tab_ftth_regiao LIMIT 1');

$antesCaixas = (int) Db::valor('SELECT COUNT(*) FROM tab_ftth_caixa');
$antesVaos   = (int) Db::valor('SELECT COUNT(*) FROM tab_ftth_cabo_vao');

$imp = Kmz::importarParaQuarentena($arquivo, 'AsBuilt_Palmital.kmz', $regiao, 'teste');

T::igual('433 itens na quarentena', 433,
    (int) Db::valor('SELECT COUNT(*) FROM tab_ftth_importacao_item WHERE importacao_id = ?', [$imp['importacao_id']]));
T::igual('todos pendentes', 433,
    (int) Db::valor('SELECT COUNT(*) FROM tab_ftth_importacao_item WHERE status = "pendente"'));

// O ponto central: a quarentena NAO pode ter tocado nas tabelas reais.
T::igual('nenhuma caixa real criada', $antesCaixas, (int) Db::valor('SELECT COUNT(*) FROM tab_ftth_caixa'));
T::igual('nenhum vao real criado',    $antesVaos,   (int) Db::valor('SELECT COUNT(*) FROM tab_ftth_cabo_vao'));

$comAncora = (int) Db::valor(
    'SELECT COUNT(*) FROM tab_ftth_importacao_item
     WHERE tipo_sugerido = "VAO"
       AND COALESCE(ancora_ini_id, ancora_ini_item_id) IS NOT NULL
       AND COALESCE(ancora_fim_id, ancora_fim_item_id) IS NOT NULL');
T::igual('as 212 pontas de vao foram ancoradas', 212, $comAncora);

$semAncora = (int) Db::valor(
    'SELECT COUNT(*) FROM tab_ftth_importacao_item WHERE alertas_json LIKE "%FTTH-GEO-005%"');
T::igual('nenhuma ponta solta no arquivo real', 0, $semAncora);

$alertas = (int) Db::valor('SELECT alertas FROM tab_ftth_importacao WHERE id = ?', [$imp['importacao_id']]);
T::certo('importacao registrou os alertas', $alertas >= 4, 'alertas=' . $alertas);

$mesmaCaixa = (int) Db::valor(
    'SELECT COUNT(*) FROM tab_ftth_importacao_item WHERE alertas_json LIKE "%FTTH-GEO-003%"');
T::igual('4 vaos com as duas pontas na mesma caixa', 4, $mesmaCaixa);

// Idempotencia: o mesmo arquivo nao entra duas vezes sem confirmacao explicita.
T::recusa('recusa reimportar o mesmo arquivo',
    fn() => Kmz::importarParaQuarentena($arquivo, 'AsBuilt_Palmital.kmz', $regiao, 'teste'),
    'FTTH-KMZ-002');

$imp2 = Kmz::importarParaQuarentena($arquivo, 'AsBuilt_Palmital.kmz', $regiao, 'teste', true);
T::certo('reimporta quando o usuario confirma', $imp2['importacao_id'] > $imp['importacao_id']);

// Arquivo invalido.
$lixo = sys_get_temp_dir() . '/ftth_lixo.kmz';
file_put_contents($lixo, 'isto nao e um kmz');
T::recusa('recusa arquivo invalido', fn() => Kmz::ler($lixo), 'FTTH-KMZ-001');
@unlink($lixo);

// Auditoria da importacao.
$aud = Db::um('SELECT * FROM tab_ftth_historico WHERE entidade = "importacao" ORDER BY id DESC LIMIT 1');
T::certo('importacao ficou registrada na auditoria', $aud !== null && $aud['acao'] === 'importar_kmz');
