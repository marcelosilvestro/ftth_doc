-- ftth_doc :: 014 :: preferencias visuais do mapa
--
-- mapa_tipo usa os nomes do Google Maps:
--   roadmap   = ruas
--   satellite = satelite puro (sem nomes de rua)
--   hybrid    = satelite COM nomes de rua e rodovias  <- padrao do addon
--   terrain   = relevo
-- Padrao hibrido porque no campo o tecnico precisa ver o telhado E o nome da rua.

INSERT INTO `tab_ftth_config` (`chave`, `valor`, `descricao`) VALUES
  ('mapa_tipo',        'hybrid', 'Tipo de mapa ao abrir: roadmap | satellite | hybrid | terrain'),
  ('mapa_rotulo_zoom', '17',     'Zoom a partir do qual o nome da caixa aparece no mapa'),
  ('mapa_limite',      '3000',   'Maximo de elementos carregados por consulta de area')
ON DUPLICATE KEY UPDATE `chave` = `chave`;
