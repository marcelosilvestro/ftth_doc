-- ftth_doc :: 004 :: catalogos (tipos de cabo, perdas de splitter, atenuacao por lambda)
-- Todos os valores sao editaveis pelo provedor. A procedencia fica explicita:
-- 'catalogo' = valor tipico de fabricante; 'provedor' = ajustado pelo provedor.

CREATE TABLE IF NOT EXISTS `tab_ftth_cabo_tipo` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `rotulo`        VARCHAR(40)  NOT NULL,
  `fibras`        SMALLINT UNSIGNED NOT NULL,
  `tubos`         SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  `fibras_por_tubo` SMALLINT UNSIGNED NOT NULL,
  `construcao`    ENUM('monotubo','multitubo') NOT NULL DEFAULT 'monotubo',
  `ordem`         SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `ativo`         TINYINT(1)   NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_rotulo` (`rotulo`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `tab_ftth_cabo_tipo` (`rotulo`,`fibras`,`tubos`,`fibras_por_tubo`,`construcao`,`ordem`) VALUES
  ('Drop (1 FO)',        1,  1,  1, 'monotubo', 10),
  ('2 FO',               2,  1,  2, 'monotubo', 20),
  ('4 FO',               4,  1,  4, 'monotubo', 30),
  ('6 FO',               6,  1,  6, 'monotubo', 40),
  ('8 FO',               8,  1,  8, 'monotubo', 50),
  ('12 FO',             12,  1, 12, 'monotubo', 60),
  ('12 FO MULT (2x6)',  12,  2,  6, 'multitubo', 70),
  ('24 FO (Monotubo)',  24,  1, 24, 'monotubo', 80),
  ('24 FO MULT (4x6)',  24,  4,  6, 'multitubo', 90),
  ('36 FO MULT (6x6)',  36,  6,  6, 'multitubo',100),
  ('36 FO MULT (3x12)', 36,  3, 12, 'multitubo',110),
  ('48 FO MULT (4x12)', 48,  4, 12, 'multitubo',120),
  ('72 FO MULT (6x12)', 72,  6, 12, 'multitubo',130),
  ('96 FO MULT (8x12)', 96,  8, 12, 'multitubo',140),
  ('144 FO MULT (12x12)',144,12,12, 'multitubo',150)
ON DUPLICATE KEY UPDATE `rotulo` = `rotulo`;

-- Perda por saida de splitter. Balanceado: uma linha por razao.
-- Desbalanceado: uma linha por razao com perda do ramo menor e do ramo maior.
CREATE TABLE IF NOT EXISTS `tab_ftth_perda_padrao` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `modelo`      ENUM('BAL','DESBAL') NOT NULL,
  `razao`       VARCHAR(12)  NOT NULL COMMENT 'BAL: 1:8 | DESBAL: 10/90',
  `saidas`      SMALLINT UNSIGNED NOT NULL,
  `perda_db`    DECIMAL(5,2) NOT NULL COMMENT 'BAL: perda de cada saida | DESBAL: ramo menor',
  `perda_db2`   DECIMAL(5,2) NULL     COMMENT 'DESBAL: ramo maior',
  `procedencia` ENUM('catalogo','provedor') NOT NULL DEFAULT 'catalogo',
  `ordem`       SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_modelo_razao` (`modelo`, `razao`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `tab_ftth_perda_padrao` (`modelo`,`razao`,`saidas`,`perda_db`,`perda_db2`,`ordem`) VALUES
  ('BAL','1:2',  2,  3.70, NULL, 10),
  ('BAL','1:4',  4,  7.30, NULL, 20),
  ('BAL','1:8',  8, 10.50, NULL, 30),
  ('BAL','1:16',16, 13.80, NULL, 40),
  ('BAL','1:32',32, 17.10, NULL, 50),
  ('BAL','1:64',64, 20.50, NULL, 60),
  ('DESBAL','1/99', 2, 20.00, 0.30, 70),
  ('DESBAL','2/98', 2, 17.00, 0.40, 80),
  ('DESBAL','5/95', 2, 13.70, 0.50, 90),
  ('DESBAL','10/90',2, 10.50, 0.70,100),
  ('DESBAL','15/85',2,  8.70, 0.90,110),
  ('DESBAL','20/80',2,  7.40, 1.20,120),
  ('DESBAL','25/75',2,  6.40, 1.50,130),
  ('DESBAL','30/70',2,  5.60, 1.80,140),
  ('DESBAL','35/65',2,  4.90, 2.10,150),
  ('DESBAL','40/60',2,  4.30, 2.50,160),
  ('DESBAL','45/55',2,  3.80, 2.90,170),
  ('DESBAL','50/50',2,  3.40, 3.40,180)
ON DUPLICATE KEY UPDATE `modelo` = `modelo`;

-- Atenuacao da fibra por comprimento de onda. 1490 nm e o downstream GPON (OLT -> ONU).
CREATE TABLE IF NOT EXISTS `tab_ftth_atenuacao` (
  `lambda_nm`   SMALLINT UNSIGNED NOT NULL,
  `db_km`       DECIMAL(4,3) NOT NULL,
  `descricao`   VARCHAR(60)  NOT NULL,
  `procedencia` ENUM('catalogo','provedor') NOT NULL DEFAULT 'catalogo',
  PRIMARY KEY (`lambda_nm`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `tab_ftth_atenuacao` (`lambda_nm`,`db_km`,`descricao`) VALUES
  (1270, 0.380, 'XGS-PON upstream'),
  (1310, 0.350, 'GPON upstream'),
  (1490, 0.280, 'GPON downstream'),
  (1550, 0.220, 'RF video / OTDR'),
  (1577, 0.220, 'XGS-PON downstream')
ON DUPLICATE KEY UPDATE `lambda_nm` = `lambda_nm`;
