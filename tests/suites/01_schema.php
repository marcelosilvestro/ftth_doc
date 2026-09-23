<?php
/**
 * Suite 01 :: schema (Gate 0).
 *
 * Aplica o baseline do zero e prova o que o modelo novo promete: que ele pode rodar de novo
 * sem mudar nada. É a suíte que sustenta a distribuição — um baseline que não é idempotente
 * quebra a atualização no servidor de um cliente.
 */

T::suite('Schema');

$schema = new Schema(__DIR__ . '/../../sql');
$r = $schema->aplicar('teste', 'suite');

T::certo('baseline aplica sem erro', $r['comandos'] > 0, 'comandos=' . $r['comandos']);

$tabelas = array_column(Db::todos(
    'SELECT table_name AS t FROM information_schema.tables
      WHERE table_schema = DATABASE() AND table_name LIKE "tab_ftth_%"'), 't');

$faltando = array_diff(Schema::TABELAS, $tabelas);
T::certo('as 22 tabelas do addon existem', $faltando === [], implode(', ', $faltando));

$sobrando = array_diff($tabelas, Schema::TABELAS);
T::certo('nenhuma tabela aposentada foi criada', $sobrando === [], implode(', ', $sobrando));

// Idempotência de verdade: a assinatura do schema não pode mudar ao reaplicar.
$antes = $schema->digital();
$r2 = $schema->aplicar('teste', 'suite');
$depois = $schema->digital();
T::igual('reaplicar o baseline nao muda o schema', $antes, $depois);
T::certo('segunda aplicacao sem erro', $r2['comandos'] > 0);

// O diário registra a aplicação, e não os arquivos numerados de antigamente.
$ledger = $schema->ledger();
T::certo('o diario registra o baseline', ($ledger['baseline']['migration'] ?? '') === 'baseline.sql');
T::igual('o diario nao tem migrations numeradas', 0, $ledger['legados']);

// Engine e charset (F5/A1).
$erradas = Db::todos('SELECT table_name AS t, engine, table_collation FROM information_schema.tables
                      WHERE table_schema = DATABASE() AND table_name LIKE "tab_ftth_%"
                        AND (engine <> "InnoDB" OR table_collation NOT LIKE "utf8mb4%")');
T::certo('todas as tabelas sao InnoDB + utf8mb4', $erradas === [], json_encode($erradas));

// Seeds.
T::igual('15 tipos de cabo no catalogo', 15, (int) Db::valor('SELECT COUNT(*) FROM tab_ftth_cabo_tipo'));
T::igual('18 perdas de splitter no catalogo', 18, (int) Db::valor('SELECT COUNT(*) FROM tab_ftth_perda_padrao'));
T::igual('5 comprimentos de onda', 5, (int) Db::valor('SELECT COUNT(*) FROM tab_ftth_atenuacao'));
T::igual('20 chaves de configuracao', 20, (int) Db::valor('SELECT COUNT(*) FROM tab_ftth_config'));
T::igual('perda do 1:8 e 10.50 dB', '10.50', (string) Db::valor(
    'SELECT perda_db FROM tab_ftth_perda_padrao WHERE modelo = "BAL" AND razao = "1:8"'));
T::igual('atenuacao de 1490 nm e 0.280 dB/km', '0.280', (string) Db::valor(
    'SELECT db_km FROM tab_ftth_atenuacao WHERE lambda_nm = 1490'));
T::igual('lambda padrao de estimativa e 1490', '1490', (string) Config::get('lambda_estimativa'));
T::certo('escrita em sis_cliente comeca DESLIGADA', Config::ligado('sync_sis_cliente') === false);
T::certo('espelho da cto nativa comeca DESLIGADO', Config::ligado('sync_cto_nativa') === false);

// As chaves órfãs não voltam.
T::igual('schema_version nao existe mais', null, Config::get('schema_version'));
T::igual('mapa_limite nao existe mais', null, Config::get('mapa_limite'));

// Um seed com valor ajustado pelo provedor sobrevive a uma reaplicação.
Config::set('perda_fusao_db', '0.25', 'teste');
$schema->aplicar('teste', 'suite');
Config::limparCache();
T::igual('reaplicar nao pisa em valor ajustado pelo provedor', '0.25', (string) Config::get('perda_fusao_db'));
Config::set('perda_fusao_db', '0.10', 'teste');

// A limpeza roda mesmo quando não há nada para derrubar (instalação nova).
$rl = $schema->limpar('teste', false, 'suite');
T::certo('limpeza roda em banco novo sem reclamar', $rl['comandos'] > 0);

// Impressão digital: muda junto com o schema, e só com ele. Quando este valor mudar num
// pull request, é porque alguém mexeu no banco — confira se foi de propósito.
T::certo('assinatura do schema tem as 4 dimensoes',
    count(array_filter($schema->assinatura(), fn($l) => str_starts_with($l, 'TABELA '))) === 22,
    'linhas=' . count($schema->assinatura()));
