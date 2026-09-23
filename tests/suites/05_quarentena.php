<?php
/**
 * Suite 05 :: regioes e promocao da quarentena para a rede real.
 * Usa a importacao feita na suite 04 (mesmo banco de teste, mesma execucao).
 */
require_once __DIR__ . '/../../lib/Regiao.php';
require_once __DIR__ . '/../../lib/Quarentena.php';
require_once __DIR__ . '/../../lib/Caixa.php';

T::suite('Regiões');

$r = Regiao::criar('Corbélia', -24.7994, -53.3056, 15, 'teste');
T::certo('cria região', $r->ok, json_encode($r->errors));
$corbelia = (int) $r->data['id'];

$dup = Regiao::criar('Corbélia', null, null, 15, 'teste');
T::igual('recusa nome repetido', false, $dup->ok);

$alt = Regiao::alterar($corbelia, 'Corbélia Centro', -24.79, -53.30, 16,
    (int) Regiao::obter($corbelia)['versao'], 'teste');
T::certo('altera região', $alt->ok, json_encode($alt->errors));

$conf = Regiao::alterar($corbelia, 'Outro nome', null, null, 15, 1, 'teste');
T::igual('recusa alteração com versão velha', 'FTTH-CONC-001', $conf->primeiroCodigo());

$exc = Regiao::excluir($corbelia, (int) Regiao::obter($corbelia)['versao'], 'teste');
T::certo('exclui região vazia', $exc->ok, json_encode($exc->errors));

// Daqui para baixo tudo depende da importacao que a suite 04 faz do KMZ real. Sem o
// arquivo (ele nao vai para o repositorio publico), nao ha o que conferir.
$palmital = (int) Db::valor('SELECT id FROM tab_ftth_regiao WHERE nome = "Palmital"');
if ($palmital === 0) {
    echo '  --   suite pulada: depende de tests/dados/AsBuilt_Palmital.kmz, que nao vai para o repositorio', PHP_EOL;
    return;
}
$excCheia = Regiao::excluir($palmital, null, 'teste');
T::igual('recusa excluir região com itens', 'FTTH-TOP-016', $excCheia->primeiroCodigo());

T::suite('Quarentena → rede real');

// Limpa o que a suite 02 criou na mao, para os numeros baterem com o KMZ.
Db::exec('DELETE FROM tab_ftth_ligacao_ponta');
Db::exec('DELETE FROM tab_ftth_ligacao');
Db::exec('DELETE FROM tab_ftth_porta');
Db::exec('DELETE FROM tab_ftth_splitter');
Db::exec('DELETE FROM tab_ftth_dio_porta');
Db::exec('DELETE FROM tab_ftth_dio');
Db::exec('DELETE FROM tab_ftth_olt');
Db::exec('DELETE FROM tab_ftth_cabo_vao');
Db::exec('DELETE FROM tab_ftth_cabo');
Db::exec('DELETE FROM tab_ftth_caixa');
// Sobrou uma importacao duplicada da suite 04; fica so a primeira.
$primeira = (int) Db::valor('SELECT MIN(importacao_id) FROM tab_ftth_importacao_item');
Db::exec('DELETE FROM tab_ftth_importacao_item WHERE importacao_id <> ?', [$primeira]);

$cont = Quarentena::contar($palmital);
T::igual('433 itens pendentes', 433, $cont['pendentes']);
T::igual('4 com alerta', 4, $cont['com_alerta']);

// --- item a item
$umaCaixa = Db::um('SELECT * FROM tab_ftth_importacao_item
                     WHERE regiao_id = ? AND tipo_sugerido = "CAIXA" AND status = "pendente" LIMIT 1', [$palmital]);
$imp = Quarentena::importarItem((int) $umaCaixa['id'], 'teste');
T::certo('importa uma caixa', $imp->ok, json_encode($imp->errors));
T::igual('caixa existe de verdade', 1, (int) Db::valor('SELECT COUNT(*) FROM tab_ftth_caixa'));
T::igual('item marcado como importado', 'importado',
    Db::valor('SELECT status FROM tab_ftth_importacao_item WHERE id = ?', [$umaCaixa['id']]));

$rep = Quarentena::importarItem((int) $umaCaixa['id'], 'teste');
T::igual('nao importa o mesmo item duas vezes', 'FTTH-KMZ-005', $rep->primeiroCodigo());

// --- vao antes das caixas: precisa recusar
$umVao = Db::um('SELECT * FROM tab_ftth_importacao_item
                  WHERE regiao_id = ? AND tipo_sugerido = "VAO" AND status = "pendente"
                    AND alertas_json IS NULL LIMIT 1', [$palmital]);
$vaoCedo = Quarentena::importarItem((int) $umVao['id'], 'teste');
T::igual('recusa vão antes das caixas das pontas', 'FTTH-KMZ-004', $vaoCedo->primeiroCodigo());

// --- lote com a rede inteira
$pendentes = Db::todos('SELECT id FROM tab_ftth_importacao_item WHERE regiao_id = ? AND status = "pendente"',
    [$palmital]);
$ids = array_column($pendentes, 'id');
$lote = Quarentena::importarLote($ids, 'teste');
T::certo('lote executa', $lote->ok, json_encode($lote->errors));

$caixas = (int) Db::valor('SELECT COUNT(*) FROM tab_ftth_caixa WHERE excluido_em IS NULL');
$vaos   = (int) Db::valor('SELECT COUNT(*) FROM tab_ftth_cabo_vao WHERE excluido_em IS NULL');
T::igual('221 caixas na rede', 221, $caixas);
T::igual('208 vãos na rede (4 com alerta ficaram de fora)', 208, $vaos);
T::igual('4 itens seguem pendentes (os com alerta)', 4, Quarentena::contar($palmital)['pendentes']);

$km = round(((float) Db::valor('SELECT SUM(comprimento_optico) FROM tab_ftth_cabo_vao')) / 1000, 1);
T::certo('extensão óptica ~31 km (30,5 km + 3% de folga)', $km > 30 && $km < 32, 'km=' . $km);

$semAncora = (int) Db::valor(
    'SELECT COUNT(*) FROM tab_ftth_cabo_vao v
      LEFT JOIN tab_ftth_caixa a ON a.id = v.caixa_ini_id
      LEFT JOIN tab_ftth_caixa b ON b.id = v.caixa_fim_id
     WHERE a.id IS NULL OR b.id IS NULL');
T::igual('nenhum vão ficou com ponta solta', 0, $semAncora);

$mesmaCaixa = (int) Db::valor('SELECT COUNT(*) FROM tab_ftth_cabo_vao WHERE caixa_ini_id = caixa_fim_id');
T::igual('nenhum vão começa e termina na mesma caixa', 0, $mesmaCaixa);

// --- itens com alerta: entram só quando o usuário pede
$comAlerta = array_column(Db::todos(
    'SELECT id FROM tab_ftth_importacao_item WHERE regiao_id = ? AND status = "pendente"', [$palmital]), 'id');
$loteAlerta = Quarentena::importarLote($comAlerta, 'teste', true);
T::certo('lote com alerta executa quando pedido', $loteAlerta->ok);
T::igual('os 4 vãos ruins foram recusados pela regra de geometria', 4, count($loteAlerta->data['pulados']));
T::igual('rede continua com 208 vãos', 208,
    (int) Db::valor('SELECT COUNT(*) FROM tab_ftth_cabo_vao WHERE excluido_em IS NULL'));

// --- descarte
$desc = Quarentena::descartar($comAlerta, 'teste', 'vãos inválidos do arquivo');
T::certo('descarta os itens ruins', $desc->ok);
T::igual('quarentena zerada', 0, Quarentena::contar($palmital)['pendentes']);
T::igual('contadores da importação batem', 221 + 208,
    (int) Db::valor('SELECT importados FROM tab_ftth_importacao WHERE id = ?', [$primeira]));

// --- rastreabilidade
$origem = (int) Db::valor('SELECT COUNT(*) FROM tab_ftth_caixa WHERE origem = "kmz" AND importacao_id IS NOT NULL');
T::igual('toda caixa importada sabe de onde veio', 221, $origem);
$aud = (int) Db::valor('SELECT COUNT(*) FROM tab_ftth_historico WHERE acao = "importar_kmz"');
T::certo('auditoria registrou cada promoção', $aud >= 429, 'registros=' . $aud);

// ------------------------------------------------------------------ capacidade do cabo
// O KMZ escreve "Cabo 24FO" e o catálogo tem "24 FO (Monotubo)": o texto não casa. Antes
// isso caía em 6 FO EM SILÊNCIO, e 7 cabos da rede real entraram com 18 fibras a menos.
$regiaoQ = (int) Db::valor('SELECT id FROM tab_ftth_regiao WHERE nome = "Palmital"');
$qa = (int) Caixa::criar($regiaoQ, 'CEO', 'T.KZ.A', '#FF9100', -24.9000, -52.2500, 'teste')->data['id'];
$qb = (int) Caixa::criar($regiaoQ, 'CEO', 'T.KZ.B', '#FF9100', -24.9010, -52.2500, 'teste')->data['id'];

$impQ = (int) Db::valor('SELECT id FROM tab_ftth_importacao ORDER BY id LIMIT 1');

$importarComRotulo = function (string $rotulo) use ($regiaoQ, $qa, $qb, $impQ) {
    $geo = json_encode([[-24.9000, -52.2500], [-24.9010, -52.2500]]);
    Db::exec(
        'INSERT INTO tab_ftth_importacao_item
            (importacao_id, regiao_id, tipo_sugerido, subtipo, nome, cor, geometria,
             hash_geometria, ancora_ini_id, ancora_fim_id, alertas_json, status)
         VALUES (?,?,"VAO",?,?,"#00E676",?,?,?,?,"[]","pendente")',
        [$impQ, $regiaoQ, $rotulo, 'T.KZ ' . $rotulo, $geo,
         hash('sha256', $rotulo . $geo), $qa, $qb]);
    return Quarentena::importarItem(Db::ultimoId(), 'teste');
};

$r = $importarComRotulo('24 FO');
T::certo('importa um cabo anunciado como 24 FO', $r->ok, json_encode($r->errors));
T::igual('e ele entra com 24 fibras, não com 6', 24,
    (int) Db::valor('SELECT t.fibras FROM tab_ftth_cabo c
                       JOIN tab_ftth_cabo_tipo t ON t.id = c.cabo_tipo_id
                      WHERE c.id = ?', [$r->data['cabo_id']]));
T::igual('escolhendo o monotubo, que é o mais simples', '24 FO (Monotubo)', $r->data['cabo_tipo']);
T::igual('sem avisar nada, porque reconheceu', [], $r->warnings);

$r = $importarComRotulo('6 FO');
T::igual('rótulo que existe no catálogo continua casando direto', '6 FO', $r->data['cabo_tipo']);

// Capacidade que não existe no catálogo ainda cai no padrão — mas agora avisa.
$r = $importarComRotulo('7 FO');
T::certo('importa mesmo sem reconhecer a capacidade', $r->ok, json_encode($r->errors));
T::igual('caindo no padrão', '6 FO', $r->data['cabo_tipo']);
T::igual('mas dizendo que não reconheceu', 'FTTH-KMZ-006', $r->warnings[0]['code'] ?? null);
