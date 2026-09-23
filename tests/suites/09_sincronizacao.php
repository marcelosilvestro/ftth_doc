<?php
/**
 * Suite 09 :: projeção nas tabelas nativas (espelho de ida).
 *
 * Roda depois da 08, que já criou as nativas de mentira, a CTO.90.01 e o splitter.
 * O que se verifica aqui é o contrato das duas exceções aprovadas de escrita:
 * as chaves desligam tudo, o truncamento avisa em vez de calar, e o espelho some
 * junto com a caixa.
 */
require_once __DIR__ . '/../../lib/Sincronizacao.php';

T::suite('Sincronização com as tabelas nativas');

$regiao = (int) Db::valor('SELECT id FROM tab_ftth_regiao WHERE nome = "Palmital"');
$cto    = (int) Db::valor('SELECT id FROM tab_ftth_caixa WHERE nome = "CTO.90.01"');
$spl    = (int) Db::valor('SELECT id FROM tab_ftth_splitter WHERE caixa_id = ? AND funcao = "ATENDIMENTO" AND excluido_em IS NULL', [$cto]);

// ------------------------------------------------------------------ desligado é desligado
Config::set('sync_sis_cliente', '0', 'teste');
Config::set('sync_cto_nativa', '0', 'teste');
Db::exec('UPDATE sis_cliente SET caixa_herm = NULL, porta_splitter = NULL');

Topologia::vincularCliente(103, $spl, 7, 'teste');
T::igual('com a chave desligada não escreve em sis_cliente', null,
    Db::valor('SELECT caixa_herm FROM sis_cliente WHERE id = 103'));
T::igual('com a chave desligada não cria linha em cto', 0,
    (int) Db::valor('SELECT COUNT(*) FROM cto'));

// ------------------------------------------------------------------ cadastro do cliente
Config::set('sync_sis_cliente', '1', 'teste');
$r = Sincronizacao::cliente(103, null, 'teste');
T::certo('sincroniza o cliente sem erro', $r->ok, json_encode($r->errors));
T::igual('gravou o nome da CTO em caixa_herm', 'CTO.90.01',
    Db::valor('SELECT caixa_herm FROM sis_cliente WHERE id = 103'));
T::igual('gravou o número puro da porta', '7',
    Db::valor('SELECT porta_splitter FROM sis_cliente WHERE id = 103'));
T::igual('registrou na auditoria', 1,
    (int) Db::valor('SELECT COUNT(*) FROM tab_ftth_historico WHERE entidade = "sis_cliente" AND entidade_id = 103'));

// Rodar de novo não deve gerar segunda escrita: o valor já é o mesmo.
Sincronizacao::cliente(103, null, 'teste');
T::igual('não regrava quando nada mudou', 1,
    (int) Db::valor('SELECT COUNT(*) FROM tab_ftth_historico WHERE entidade = "sis_cliente" AND entidade_id = 103'));

T::certo('desvincular limpa os dois campos', Topologia::desvincularCliente(103, 'teste')->ok);
T::igual('caixa_herm limpo', null, Db::valor('SELECT caixa_herm FROM sis_cliente WHERE id = 103'));
T::igual('porta_splitter limpo', null, Db::valor('SELECT porta_splitter FROM sis_cliente WHERE id = 103'));

// ------------------------------------------------------------------ porta ambígua (dois splitters)
Topologia::vincularCliente(103, $spl, 7, 'teste');
$spl2 = (int) Topologia::criarSplitter($cto, ['funcao' => 'ATENDIMENTO', 'nome' => 'SPL.09',
    'razao' => '1:8', 'saidas' => 8], 'teste')->data['id'];

Config::set('sync_porta_qualificar', '1', 'teste');
$r = Sincronizacao::cliente(103, null, 'teste');
T::igual('com dois splitters, qualifica a porta', 'SPL.01:7',
    Db::valor('SELECT porta_splitter FROM sis_cliente WHERE id = 103'));
T::igual('e avisa que qualificou', 'FTTH-SYNC-003', $r->warnings[0]['code'] ?? null);

Config::set('sync_porta_qualificar', '0', 'teste');
$r = Sincronizacao::cliente(103, null, 'teste');
T::igual('sem qualificar, grava o número puro', '7',
    Db::valor('SELECT porta_splitter FROM sis_cliente WHERE id = 103'));
T::igual('mas avisa da ambiguidade', 'FTTH-SYNC-004', $r->warnings[0]['code'] ?? null);

$vS2 = (int) Db::valor('SELECT versao FROM tab_ftth_splitter WHERE id = ?', [$spl2]);
Topologia::excluirSplitter($spl2, $vS2, 'teste');
Sincronizacao::cliente(103, null, 'teste');
T::igual('voltando a um splitter, volta o número puro', '7',
    Db::valor('SELECT porta_splitter FROM sis_cliente WHERE id = 103'));

// ------------------------------------------------------------------ espelho da cto
// O cliente 101 ficou vinculado desde a suíte 08 e está em outra PON. Ele sai daqui para
// a primeira passada ter uma PON só, e volta logo abaixo para provocar a divergência.
T::certo('tira o cliente de outra PON antes de espelhar', Topologia::desvincularCliente(101, 'teste')->ok);

Config::set('sync_cto_nativa', '1', 'teste');
$r = Sincronizacao::caixa($cto, null, 'teste');
T::certo('espelha a CTO na tabela nativa', $r->ok, json_encode($r->errors));

$linha = Db::um('SELECT * FROM cto');
T::certo('criou exatamente uma CTO nativa', $linha !== null);
T::igual('gravou o nome', 'CTO.90.01', $linha['name'] ?? null);
T::igual('gravou as 8 portas do splitter', 8, (int) ($linha['ports'] ?? 0));
T::igual('apontou para a única OLT cadastrada', 1, (int) ($linha['olt_id'] ?? 0));
T::igual('deduziu a PON do provisionamento do cliente', '1/2/9', $linha['fsp'] ?? null);
T::certo('gravou as coordenadas', strpos((string) ($linha['coordenadas'] ?? ''), '-24.884') === 0);
T::igual('guardou o mapeamento caixa -> cto', 1,
    (int) Db::valor('SELECT COUNT(*) FROM tab_ftth_cto_espelho WHERE caixa_id = ?', [$cto]));

// Segunda passada não duplica nem regrava (hash do payload).
$antes = (int) Db::valor('SELECT COUNT(*) FROM tab_ftth_historico WHERE entidade = "cto"');
Sincronizacao::caixa($cto, null, 'teste');
T::igual('não duplica a CTO nativa', 1, (int) Db::valor('SELECT COUNT(*) FROM cto'));
T::igual('não regrava quando o payload não mudou', $antes,
    (int) Db::valor('SELECT COUNT(*) FROM tab_ftth_historico WHERE entidade = "cto"'));

// PON divergente entre os clientes da mesma CTO vira aviso, não valor errado.
Topologia::vincularCliente(101, $spl, 2, 'teste');   // 101 está em 1/1/5, 103 em 1/2/9
$r = Sincronizacao::caixa($cto, null, 'teste');
$codigos = array_column($r->warnings, 'code');
T::certo('avisa quando os clientes estão em PONs diferentes', in_array('FTTH-SYNC-006', $codigos, true),
    json_encode($codigos));
T::igual('e deixa a PON em branco em vez de chutar', null, Db::valor('SELECT fsp FROM cto'));

// ------------------------------------------------------------------ nome que não cabe em cto.name
$rLonga = Caixa::criar($regiao, 'CTO', 'CTO.90.02 com um nome bem longo para não caber', '#29B6F6',
    -24.8842, -52.2092, 'teste');
$ctoLonga = (int) $rLonga->data['id'];
Topologia::criarSplitter($ctoLonga, ['funcao' => 'ATENDIMENTO', 'razao' => '1:8', 'saidas' => 8], 'teste');
$r = Sincronizacao::caixa($ctoLonga, null, 'teste');
T::certo('avisa que abreviou o nome', in_array('FTTH-SYNC-002', array_column($r->warnings, 'code'), true),
    json_encode($r->warnings));
$nomeGravado = (string) Db::valor('SELECT nome_gravado FROM tab_ftth_cto_espelho WHERE caixa_id = ?', [$ctoLonga]);
T::igual('o nome gravado cabe em 32 caracteres', 32, mb_strlen($nomeGravado));

// ------------------------------------------------------------------ acento sobrevive ao latin1
$rAcento = Caixa::criar($regiao, 'CTO', 'CTO.90.03 Praça São João', '#29B6F6', -24.8843, -52.2093, 'teste');
$ctoAcento = (int) $rAcento->data['id'];
Sincronizacao::caixa($ctoAcento, null, 'teste');
$idAcento = (int) Db::valor('SELECT cto_id FROM tab_ftth_cto_espelho WHERE caixa_id = ?', [$ctoAcento]);
T::igual('acento chega inteiro na coluna latin1', 'CTO.90.03 Praça São João',
    Db::valor('SELECT name FROM cto WHERE id = ?', [$idAcento]));

// ------------------------------------------------------------------ caixa excluída perde o espelho
$vA = (int) Db::valor('SELECT versao FROM tab_ftth_caixa WHERE id = ?', [$ctoAcento]);
T::certo('exclui a caixa espelhada', Caixa::excluir($ctoAcento, $vA, 'teste')->ok);
Sincronizacao::caixa($ctoAcento, null, 'teste');
T::igual('a CTO nativa foi removida junto', 0,
    (int) Db::valor('SELECT COUNT(*) FROM cto WHERE id = ?', [$idAcento]));
T::igual('e o mapeamento também', 0,
    (int) Db::valor('SELECT COUNT(*) FROM tab_ftth_cto_espelho WHERE caixa_id = ?', [$ctoAcento]));

// Registro alterado por fora não é apagado: passou a ser do outro sistema.
$rOutra = Caixa::criar($regiao, 'CTO', 'CTO.90.04', '#29B6F6', -24.8844, -52.2094, 'teste');
$ctoOutra = (int) $rOutra->data['id'];
Sincronizacao::caixa($ctoOutra, null, 'teste');
$idOutra = (int) Db::valor('SELECT cto_id FROM tab_ftth_cto_espelho WHERE caixa_id = ?', [$ctoOutra]);
Db::exec('UPDATE cto SET name = "RENOMEADA NO HELPFIBER" WHERE id = ?', [$idOutra]);
$vO = (int) Db::valor('SELECT versao FROM tab_ftth_caixa WHERE id = ?', [$ctoOutra]);
Caixa::excluir($ctoOutra, $vO, 'teste');
$r = Sincronizacao::caixa($ctoOutra, null, 'teste');
T::igual('não apaga registro nativo alterado por fora', 1,
    (int) Db::valor('SELECT COUNT(*) FROM cto WHERE id = ?', [$idOutra]));
T::certo('e avisa o motivo', in_array('FTTH-SYNC-007', array_column($r->warnings, 'code'), true),
    json_encode($r->warnings));

// Deixa as chaves como estavam: as outras suítes não esperam espelho ligado.
Config::set('sync_sis_cliente', '0', 'teste');
Config::set('sync_cto_nativa', '0', 'teste');
Config::set('sync_porta_qualificar', '1', 'teste');
