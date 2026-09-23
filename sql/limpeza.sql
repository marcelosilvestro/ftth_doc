-- ftth_doc :: limpeza do que foi aposentado
--
-- Estas tabelas foram criadas na fase de fundacao e NENHUM codigo do addon as le ou escreve.
-- Deixa-las no banco de um provedor e prometer funcionalidade que nao existe, entao elas saem
-- antes do beta. O levantamento que sustenta cada linha esta no plano de 23/09/2026.
--
--   tab_ftth_permissao       perfis do addon — removidos: quem entra no painel pode tudo
--   tab_ftth_regiao_backup   "restaurar regiao" nunca foi implementado
--   tab_ftth_diagrama_versao snapshots do diagrama nunca foram gravados
--   tab_ftth_anotacao        anotacoes no diagrama nunca foram implementadas
--   tab_ftth_medicao         potencia medida em campo: entra quando houver tela
--   tab_ftth_certificacao    selo de certificacao: idem
--   tab_ftth_ocorrencia      rompimento/manutencao: idem
--   tab_ftth_foto            fotos da caixa: so havia um COUNT(*) que sempre devolvia 0
--
-- Nenhuma tabela que FICA referencia qualquer uma destas (as FKs sao todas no sentido oposto),
-- entao um DROP unico resolve, sem mexer em FOREIGN_KEY_CHECKS.
--
-- Este arquivo NAO roda sozinho: o instalador so o aplica com --limpar, e o runner exige
-- --forcar quando alguma das tabelas tiver dados.

DROP TABLE IF EXISTS `tab_ftth_regiao_backup`, `tab_ftth_diagrama_versao`, `tab_ftth_anotacao`, `tab_ftth_medicao`, `tab_ftth_certificacao`, `tab_ftth_ocorrencia`, `tab_ftth_foto`, `tab_ftth_permissao`;

-- Chaves de configuracao que nenhum codigo le:
--   schema_version  versionava os snapshots de regiao/diagrama, que sairam acima
--   mapa_limite     limite de elementos por consulta que o Mapa nunca aplicou
DELETE FROM `tab_ftth_config` WHERE `chave` IN ('schema_version', 'mapa_limite');
