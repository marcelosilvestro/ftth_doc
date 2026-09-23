-- ftth_doc :: 013 :: ancora de vao apontando para item da propria quarentena
--
-- Achado pelos testes com o KMZ real: numa importacao nova, as caixas das pontas ainda NAO
-- existem em tab_ftth_caixa — elas tambem estao na quarentena. As colunas ancora_ini_id/
-- ancora_fim_id so sabem apontar para caixa real, entao os 212 vaos ficavam sem ancora.
-- Agora a ancora pode ser uma caixa real OU um item da quarentena; ao importar o vao,
-- o item ja importado vira caixa e a referencia e resolvida.

ALTER TABLE `tab_ftth_importacao_item`
  ADD COLUMN `ancora_ini_item_id` INT UNSIGNED NULL AFTER `ancora_ini_id`,
  ADD COLUMN `ancora_fim_item_id` INT UNSIGNED NULL AFTER `ancora_fim_id`,
  ADD KEY `ix_ancora_item` (`ancora_ini_item_id`, `ancora_fim_item_id`);
