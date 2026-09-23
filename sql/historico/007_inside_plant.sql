-- ftth_doc :: 007 :: inside plant (OLT e DIO dentro do POP/DC)
--
-- F4: host, credenciais e coordenadas da OLT vivem na tabela NATIVA `olt` (somente leitura).
--     Aqui guardamos so o que e do addon. olt_id referencia olt.id, sem FK fisica porque
--     `olt` e tabela nativa latin1 e nao queremos FK cruzando dominios.
-- I7: uma porta de DIO tem no maximo uma saida OSP e no maximo um equipamento.

CREATE TABLE IF NOT EXISTS `tab_ftth_olt` (
  `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `caixa_id`        INT UNSIGNED NOT NULL COMMENT 'caixa tipo DC onde a OLT esta',
  `olt_id`          INT UNSIGNED NULL COMMENT 'ID em mkradius.olt (nativa, somente leitura)',
  `apelido`         VARCHAR(60)  NOT NULL,
  `formato_porta`   VARCHAR(20)  NOT NULL DEFAULT '0/1/1' COMMENT 'modelo do rotulo de PON',
  `classe_optica`   ENUM('B+','C+','C++') NOT NULL DEFAULT 'B+',
  `ptx_dbm`         DECIMAL(5,2) NOT NULL DEFAULT 3.00,
  `sensibilidade_dbm` DECIMAL(5,2) NOT NULL DEFAULT -27.00,
  `saturacao_dbm`   DECIMAL(5,2) NOT NULL DEFAULT -8.00,
  `versao`          INT UNSIGNED NOT NULL DEFAULT 1,
  `criado_por`      VARCHAR(60)  NULL,
  `criado_em`       DATETIME     NULL,
  `alterado_por`    VARCHAR(60)  NULL,
  `alterado_em`     DATETIME     NULL,
  `excluido_em`     DATETIME     NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_apelido` (`apelido`),
  KEY `ix_caixa` (`caixa_id`),
  CONSTRAINT `fk_olt_caixa` FOREIGN KEY (`caixa_id`) REFERENCES `tab_ftth_caixa` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `tab_ftth_dio` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `caixa_id`      INT UNSIGNED NOT NULL,
  `nome`          VARCHAR(60)  NOT NULL,
  `portas`        SMALLINT UNSIGNED NOT NULL DEFAULT 24,
  `ativo`         TINYINT(1)   NOT NULL DEFAULT 1,
  `versao`        INT UNSIGNED NOT NULL DEFAULT 1,
  `criado_por`    VARCHAR(60)  NULL,
  `criado_em`     DATETIME     NULL,
  `alterado_por`  VARCHAR(60)  NULL,
  `alterado_em`   DATETIME     NULL,
  `excluido_em`   DATETIME     NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_dio_nome` (`caixa_id`, `nome`),
  CONSTRAINT `fk_dio_caixa` FOREIGN KEY (`caixa_id`) REFERENCES `tab_ftth_caixa` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A porta do DIO e a origem do circuito PON (D1: nao existe tabela de caminho).
-- A ligacao fisica dela com a fibra do vao fica em tab_ftth_ligacao (elemento DIO_PORTA).
CREATE TABLE IF NOT EXISTS `tab_ftth_dio_porta` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `dio_id`        INT UNSIGNED NOT NULL,
  `numero`        SMALLINT UNSIGNED NOT NULL,
  `olt_ftth_id`   INT UNSIGNED NULL COMMENT 'equipamento ligado nesta porta',
  `pon`           VARCHAR(20)  NULL COMMENT 'rotulo da PON, ex 1/1/5',
  `servico`       VARCHAR(60)  NULL COMMENT 'nome do circuito, ex CEO.03',
  `ptx_dbm`       DECIMAL(5,2) NULL COMMENT 'potencia de saida medida/declarada nesta porta',
  `versao`        INT UNSIGNED NOT NULL DEFAULT 1,
  `criado_por`    VARCHAR(60)  NULL,
  `criado_em`     DATETIME     NULL,
  `alterado_por`  VARCHAR(60)  NULL,
  `alterado_em`   DATETIME     NULL,
  `excluido_em`   DATETIME     NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_dio_porta` (`dio_id`, `numero`),
  KEY `ix_olt` (`olt_ftth_id`),
  CONSTRAINT `fk_porta_dio` FOREIGN KEY (`dio_id`)      REFERENCES `tab_ftth_dio` (`id`),
  CONSTRAINT `fk_porta_olt` FOREIGN KEY (`olt_ftth_id`) REFERENCES `tab_ftth_olt` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
