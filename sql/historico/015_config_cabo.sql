-- ftth_doc :: 015 :: espessura das linhas de cabo no mapa
-- Ajustavel porque depende do gosto e da tela: no satelite, linha fina some no fundo.

INSERT INTO `tab_ftth_config` (`chave`, `valor`, `descricao`) VALUES
  ('mapa_cabo_espessura',   '5', 'Espessura da linha do cabo no mapa (px)'),
  ('mapa_cabo_espessura_q', '4', 'Espessura da linha de cabo ainda em quarentena (px)')
ON DUPLICATE KEY UPDATE `chave` = `chave`;
