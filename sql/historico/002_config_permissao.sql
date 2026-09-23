-- ftth_doc :: 002 :: configuração (chave/valor) e permissões por login

CREATE TABLE IF NOT EXISTS `tab_ftth_config` (
  `chave`       VARCHAR(64)  NOT NULL,
  `valor`       TEXT         NULL,
  `descricao`   VARCHAR(255) NULL,
  `alterado_por` VARCHAR(60) NULL,
  `alterado_em` DATETIME     NULL,
  PRIMARY KEY (`chave`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `tab_ftth_config` (`chave`, `valor`, `descricao`) VALUES
  ('google_maps_key',        '',      'Chave do Google Maps JS API (restrita por referer)'),
  ('lambda_estimativa',      '1490',  'Comprimento de onda usado na estimativa de RX da ONU (nm)'),
  ('fator_folga_cabo',       '1.03',  'Multiplicador do comprimento geometrico para obter o optico'),
  ('perda_fusao_db',         '0.10',  'Perda padrao por fusao (dB)'),
  ('perda_conector_db',      '0.50',  'Perda padrao por conector (dB)'),
  ('faixa_sinal_excelente',  '-22.0', 'RX >= este valor: Excelente (dBm)'),
  ('faixa_sinal_bom',        '-25.0', 'RX >= este valor: Bom (dBm)'),
  ('faixa_sinal_limite',     '-27.0', 'RX >= este valor: Limite; abaixo disso Critico (dBm)'),
  ('saturacao_dbm',          '-8.0',  'RX acima deste valor indica saturacao da ONU (dBm)'),
  ('raio_viabilidade_m',     '150',   'Raio padrao da busca de viabilidade (metros)'),
  ('tolerancia_ancora_m',    '3',     'Distancia maxima para ancorar ponta de vao em uma caixa (metros)'),
  ('sync_sis_cliente',       '0',     'Excecao aprovada: escreve caixa_herm/porta_splitter em sis_cliente (1=liga)'),
  ('schema_version',         '1',     'Versao do schema para compatibilidade de snapshots')
ON DUPLICATE KEY UPDATE `chave` = `chave`;

CREATE TABLE IF NOT EXISTS `tab_ftth_permissao` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `login`       VARCHAR(60)  NOT NULL,
  `perfil`      ENUM('admin','editor','tecnico','vendas','leitura') NOT NULL DEFAULT 'leitura',
  `criado_por`  VARCHAR(60)  NULL,
  `criado_em`   DATETIME     NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_login` (`login`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
