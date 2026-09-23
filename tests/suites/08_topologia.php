<?php
/**
 * Suite 08 :: splitters, portas de atendimento e vínculo do cliente.
 *
 * O schema de teste é do addon e não tem as tabelas nativas do MK-AUTH, então elas são
 * criadas aqui em versão mínima — mesmos nomes, mesmos tipos, mesmo latin1 da base real
 * (medidos na VM em 21/09/2026). É o que permite testar a projeção sem tocar no mkradius.
 */
require_once __DIR__ . '/../../lib/Topologia.php';
require_once __DIR__ . '/../../lib/Sincronizacao.php';

T::suite('Topologia — splitters e portas');

// ------------------------------------------------------------------ nativas de mentira
Db::pdo()->exec(
    'CREATE TABLE IF NOT EXISTS `sis_cliente` (
       `id` int(11) NOT NULL AUTO_INCREMENT,
       `login` varchar(60) DEFAULT NULL,
       `nome` varchar(255) DEFAULT NULL,
       `coordenadas` varchar(64) DEFAULT NULL,
       `armario_olt` varchar(96) DEFAULT NULL,
       `porta_olt` varchar(32) DEFAULT NULL,
       `onu_ont` varchar(64) DEFAULT NULL,
       `caixa_herm` varchar(128) DEFAULT NULL,
       `porta_splitter` varchar(32) DEFAULT NULL,
       `cli_ativado` char(1) DEFAULT "s",
       `endereco` varchar(255) DEFAULT NULL,
       `numero` varchar(20) DEFAULT NULL,
       `bairro` varchar(255) DEFAULT NULL,
       PRIMARY KEY (`id`)
     ) ENGINE=InnoDB DEFAULT CHARSET=latin1');
Db::pdo()->exec(
    'CREATE TABLE IF NOT EXISTS `cto` (
       `id` int(11) NOT NULL AUTO_INCREMENT,
       `name` varchar(32) DEFAULT NULL,
       `olt_id` int(11) DEFAULT NULL,
       `fsp` varchar(10) DEFAULT NULL,
       `ports` tinyint(4) DEFAULT NULL,
       `endereco` varchar(255) DEFAULT NULL,
       `coordenadas` varchar(50) DEFAULT NULL,
       PRIMARY KEY (`id`)
     ) ENGINE=InnoDB DEFAULT CHARSET=latin1');
Db::pdo()->exec(
    'CREATE TABLE IF NOT EXISTS `olt` (
       `id` int(11) NOT NULL AUTO_INCREMENT,
       `maker` varchar(32) NOT NULL DEFAULT "zte",
       `ipaddress` varchar(20) NOT NULL DEFAULT "",
       `name` varchar(32) NOT NULL,
       `access_port` smallint(5) unsigned NOT NULL DEFAULT 23,
       `password` varchar(128) NOT NULL DEFAULT "",
       PRIMARY KEY (`id`)
     ) ENGINE=InnoDB DEFAULT CHARSET=latin1');

Db::exec('DELETE FROM sis_cliente');
Db::exec('DELETE FROM cto');
Db::exec('DELETE FROM olt');
Db::exec('INSERT INTO olt (id, name) VALUES (1, "OLT - C320")');
Db::exec('INSERT INTO sis_cliente (id, login, nome, porta_olt, onu_ont) VALUES
            (101, "joao.silva", "João da Silva", "1/1/5", "ZTEGD111"),
            (102, "maria.souza", "Maria Souza",  "1/1/5", "ZTEGD222"),
            (103, "ana.pereira", "Ana Pereira",  "1/2/9", "ZTEGD333")');

$regiao = (int) Db::valor('SELECT id FROM tab_ftth_regiao WHERE nome = "Palmital"');

$rCaixa = Caixa::criar($regiao, 'CTO', 'CTO.90.01', '#29B6F6', -24.8840, -52.2090, 'teste');
T::certo('cria a CTO de teste', $rCaixa->ok, json_encode($rCaixa->errors));
$cto = (int) $rCaixa->data['id'];

// ------------------------------------------------------------------ splitters
$rS = Topologia::criarSplitter($cto, ['funcao' => 'ATENDIMENTO', 'razao' => '1:8', 'saidas' => 8], 'teste');
T::certo('cria splitter de atendimento', $rS->ok, json_encode($rS->errors));
$spl = (int) $rS->data['id'];
T::igual('nome sugerido automaticamente', 'SPL.01', $rS->data['nome']);

T::igual('recusa função inválida', false,
    Topologia::criarSplitter($cto, ['funcao' => 'BANANA', 'razao' => '1:8', 'saidas' => 8], 'teste')->ok);
T::igual('recusa saídas fora de faixa', 'FTTH-SYS-002',
    Topologia::criarSplitter($cto, ['funcao' => 'ATENDIMENTO', 'razao' => '1:8', 'saidas' => 1], 'teste')->primeiroCodigo());
T::igual('recusa razão vazia', 'FTTH-SYS-002',
    Topologia::criarSplitter($cto, ['funcao' => 'ATENDIMENTO', 'razao' => '', 'saidas' => 8], 'teste')->primeiroCodigo());
T::igual('recusa razão sem perda no catálogo', 'FTTH-PWR-003',
    Topologia::criarSplitter($cto, ['funcao' => 'ATENDIMENTO', 'razao' => '1:77', 'saidas' => 8], 'teste')->primeiroCodigo());
T::igual('recusa nome repetido na mesma caixa', 'FTTH-SYS-002',
    Topologia::criarSplitter($cto, ['funcao' => 'ATENDIMENTO', 'nome' => 'SPL.01', 'razao' => '1:8', 'saidas' => 8], 'teste')->primeiroCodigo());
T::igual('recusa caixa inexistente', 'FTTH-TOP-001',
    Topologia::criarSplitter(999999, ['funcao' => 'ATENDIMENTO', 'razao' => '1:8', 'saidas' => 8], 'teste')->primeiroCodigo());

$perdas = json_decode((string) Db::valor('SELECT perdas_json FROM tab_ftth_splitter WHERE id = ?', [$spl]), true);
T::igual('copiou as 8 perdas do catálogo', 8, count($perdas));

$rD = Topologia::criarSplitter($cto, ['funcao' => 'DERIVACAO', 'razao' => '1:2', 'saidas' => 2], 'teste');
T::certo('cria splitter de derivação', $rD->ok, json_encode($rD->errors));
$splD = (int) $rD->data['id'];

// ------------------------------------------------------------------ vínculo do cliente
T::igual('a grade nasce com 8 portas livres', 8,
    count(array_filter(Topologia::portas($cto), fn($p) => !$p['ocupada'])));

T::igual('recusa cliente em splitter de derivação', 'FTTH-TOP-010',
    Topologia::vincularCliente(101, $splD, 1, 'teste')->primeiroCodigo());
T::igual('recusa saída acima da capacidade', 'FTTH-SYS-002',
    Topologia::vincularCliente(101, $spl, 9, 'teste')->primeiroCodigo());
T::igual('recusa cliente inexistente', 'FTTH-SYS-002',
    Topologia::vincularCliente(999, $spl, 1, 'teste')->primeiroCodigo());
T::igual('recusa splitter inexistente', 'FTTH-TOP-009',
    Topologia::vincularCliente(101, 999999, 1, 'teste')->primeiroCodigo());

$rV = Topologia::vincularCliente(101, $spl, 3, 'teste');
T::certo('vincula o cliente na saída 3', $rV->ok, json_encode($rV->errors));
T::igual('guardou o login como atributo', 'joao.silva',
    Db::valor('SELECT login FROM tab_ftth_porta WHERE cliente_id = 101'));
T::igual('restam 7 portas livres', 7,
    count(array_filter(Topologia::portas($cto), fn($p) => !$p['ocupada'])));
T::certo('auditoria registrou o vínculo',
    (int) Db::valor('SELECT COUNT(*) FROM tab_ftth_historico WHERE entidade = "porta" AND acao = "vincular"') === 1);

T::igual('recusa segundo cliente na mesma saída', 'FTTH-TOP-011',
    Topologia::vincularCliente(102, $spl, 3, 'teste')->primeiroCodigo());
T::igual('recusa o mesmo cliente em outra saída sem mover', 'FTTH-TOP-012',
    Topologia::vincularCliente(101, $spl, 4, 'teste')->primeiroCodigo());
T::certo('revincular na mesma saída é idempotente',
    Topologia::vincularCliente(101, $spl, 3, 'teste')->ok);

$rM = Topologia::vincularCliente(101, $spl, 5, 'teste', ['mover' => true]);
T::certo('move o cliente para a saída 5', $rM->ok, json_encode($rM->errors));
T::igual('a saída 3 ficou livre de novo', null,
    Db::valor('SELECT id FROM tab_ftth_porta WHERE splitter_id = ? AND numero = 3', [$spl]));
T::igual('o cliente está numa porta só', 1,
    (int) Db::valor('SELECT COUNT(*) FROM tab_ftth_porta WHERE cliente_id = 101'));

T::certo('vincula o segundo cliente', Topologia::vincularCliente(102, $spl, 1, 'teste')->ok);
T::igual('portaDoCliente acha a caixa certa', 'CTO.90.01',
    Topologia::portaDoCliente(102)['caixa_nome']);

// ------------------------------------------------------------------ exclusão protegida
T::igual('recusa excluir splitter com cliente', 'FTTH-TOP-011',
    Topologia::excluirSplitter($spl, null, 'teste')->primeiroCodigo());
T::igual('recusa reduzir saídas abaixo de porta ocupada', 'FTTH-SYS-002',
    Topologia::alterarSplitter($spl, ['saidas' => 4], null, 'teste')->primeiroCodigo());

$vD = (int) Db::valor('SELECT versao FROM tab_ftth_splitter WHERE id = ?', [$splD]);
T::certo('exclui splitter vazio', Topologia::excluirSplitter($splD, $vD, 'teste')->ok);
T::igual('exclusão é lógica', 1,
    (int) Db::valor('SELECT COUNT(*) FROM tab_ftth_splitter WHERE id = ? AND excluido_em IS NOT NULL', [$splD]));

// ------------------------------------------------------------------ desvincular
T::certo('desvincula o cliente', Topologia::desvincularCliente(102, 'teste')->ok);
T::igual('a porta some de verdade (sem soft delete)', 0,
    (int) Db::valor('SELECT COUNT(*) FROM tab_ftth_porta WHERE cliente_id = 102'));
T::igual('o histórico guardou a desvinculação', 1,
    (int) Db::valor('SELECT COUNT(*) FROM tab_ftth_historico WHERE entidade = "porta" AND acao = "desvincular" AND JSON_EXTRACT(antes, "$.cliente_id") = 102'));
T::igual('recusa desvincular quem não está vinculado', 'FTTH-SYS-002',
    Topologia::desvincularCliente(102, 'teste')->primeiroCodigo());

// ------------------------------------------------------------------ busca e grade
// É o que a tela de clientes usa: achar quem vincular e desenhar as portas da caixa.
Db::exec('UPDATE sis_cliente SET endereco = "Rua A", numero = "100", bairro = "Centro"
           WHERE id = 101');
Db::exec('INSERT INTO sis_cliente (id, login, nome, cli_ativado)
          VALUES (104, "pedro.inativo", "Pedro Inativo", "n")');

T::igual('termo curto não busca (evita varrer o cadastro)', [],
    Topologia::buscarClientes('a'));

$b = Topologia::buscarClientes('silva');
T::certo('acha pelo nome', count($b) >= 1, json_encode($b));
T::igual('e traz o login', 'joao.silva', $b[0]['login']);
T::certo('com o endereço montado', strpos((string) $b[0]['endereco'], 'Rua A') !== false,
    (string) $b[0]['endereco']);

$b = Topologia::buscarClientes('maria');
T::igual('acha pelo login também', 'maria.souza', $b[0]['login']);

// O ponto que evita uma recusa boba na tela: quem já está numa porta vem marcado.
T::certo('quem já está numa porta diz onde',
    strpos((string) Topologia::buscarClientes('joao')[0]['ja_em'], 'saída') !== false,
    json_encode(Topologia::buscarClientes('joao')[0]));
T::igual('e quem está livre vem sem isso', null, Topologia::buscarClientes('ana')[0]['ja_em']);

// Cancelado não entra na busca: não se vincula um cliente que já não é cliente. Ele só
// interessa quando JÁ está numa porta — e aí o que se quer é tirar, não pôr.
T::igual('cliente cancelado não aparece na busca', [], Topologia::buscarClientes('inativo'));

// --- a grade de atendimento
$grade = Topologia::atendimentoDaCaixa($cto);
T::certo('a grade traz a caixa', isset($grade['caixa']['nome']), json_encode($grade));
T::igual('só splitters de ATENDIMENTO entram', 1, count($grade['splitters']));
T::igual('com todas as saídas listadas', 8, count($grade['splitters'][0]['portas']));
T::igual('a primeira é T01', 'T01', $grade['splitters'][0]['portas'][0]['rotulo']);
T::certo('e a porta ocupada sabe de quem é',
    $grade['splitters'][0]['portas'][4]['cliente']['login'] === 'joao.silva',
    json_encode($grade['splitters'][0]['portas'][4]));
T::certo('a livre vem sem cliente',
    $grade['splitters'][0]['portas'][1]['cliente'] === null);
T::igual('e o contador de ocupadas bate', 1, $grade['splitters'][0]['ocupadas']);
T::igual('caixa inexistente devolve vazio', [], Topologia::atendimentoDaCaixa(999999));

// A porta ocupada por um cliente CANCELADO fica marcada: é capacidade parada que o
// provedor pode reaver, e sem isso ela pareceria um atendimento normal.
// Montagem própria, sem depender do estado deixado pelos testes acima.
$ctoC = (int) Caixa::criar($regiao, 'CTO', 'CTO.90.CANC', '#29B6F6',
    -24.8841, -52.2091, 'teste')->data['id'];
$splC = (int) Topologia::criarSplitter($ctoC,
    ['funcao' => 'ATENDIMENTO', 'razao' => '1:8', 'saidas' => 8], 'teste')->data['id'];
Db::exec('INSERT INTO sis_cliente (id, login, nome, cli_ativado)
          VALUES (106, "carlos.cancelado", "Carlos Cancelado", "s")');
T::certo('vincula o cliente que será cancelado',
    Topologia::vincularCliente(106, $splC, 2, 'teste')->ok);

$porta = Topologia::atendimentoDaCaixa($ctoC)['splitters'][0]['portas'][1];
T::igual('enquanto ativo, a porta é um atendimento normal', true, $porta['cliente']['ativo']);

Db::exec('UPDATE sis_cliente SET cli_ativado = "n" WHERE id = 106');
$porta = Topologia::atendimentoDaCaixa($ctoC)['splitters'][0]['portas'][1];
T::certo('o cliente continua na porta depois de cancelado',
    $porta['cliente'] !== null && $porta['cliente']['login'] === 'carlos.cancelado');
T::igual('mas a grade marca que ele não é mais ativo', false, $porta['cliente']['ativo']);
T::igual('e ele sumiu da busca', [], Topologia::buscarClientes('carlos.cancelado'));

T::certo('dá para liberar a porta presa', Topologia::desvincularCliente(106, 'teste')->ok);
T::igual('e ela volta a ficar livre', null,
    Topologia::atendimentoDaCaixa($ctoC)['splitters'][0]['portas'][1]['cliente']);
