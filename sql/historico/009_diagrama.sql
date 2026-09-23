-- ftth_doc :: 009 :: layout do diagrama, versoes (historico) e anotacoes
--
-- P1: o diagrama NAO guarda conectividade. Conectividade esta em tab_ftth_ligacao.
--     Aqui fica so posicao/rotacao dos nos e os snapshots de historico.

CREATE TABLE IF NOT EXISTS `tab_ftth_diagrama_no` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `caixa_id`     INT UNSIGNED NOT NULL,
  `tipo`         ENUM('VAO','SPLITTER','DIO') NOT NULL,
  `elemento_id`  INT UNSIGNED NOT NULL,
  `pos_x`        INT NOT NULL DEFAULT 0,
  `pos_y`        INT NOT NULL DEFAULT 0,
  `rotacao`      SMALLINT NOT NULL DEFAULT 0,
  `invertido`    TINYINT(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_no` (`caixa_id`, `tipo`, `elemento_id`),
  CONSTRAINT `fk_no_caixa` FOREIGN KEY (`caixa_id`) REFERENCES `tab_ftth_caixa` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `tab_ftth_diagrama_versao` (
  `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `caixa_id`         INT UNSIGNED NOT NULL,
  `schema_version`   INT UNSIGNED NOT NULL DEFAULT 1,
  `topology_version` INT UNSIGNED NOT NULL DEFAULT 1,
  `conteudo`         LONGTEXT     NOT NULL COMMENT 'JSON: layout + nos + ligacoes + splitters',
  `criado_por`       VARCHAR(60)  NOT NULL,
  `criado_em`        DATETIME     NOT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_caixa` (`caixa_id`, `criado_em`),
  CONSTRAINT `fk_versao_caixa` FOREIGN KEY (`caixa_id`) REFERENCES `tab_ftth_caixa` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `tab_ftth_anotacao` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `caixa_id`    INT UNSIGNED NOT NULL,
  `texto`       TEXT         NOT NULL,
  `criado_por`  VARCHAR(60)  NOT NULL,
  `criado_em`   DATETIME     NOT NULL,
  `excluido_em` DATETIME     NULL,
  PRIMARY KEY (`id`),
  KEY `ix_caixa` (`caixa_id`, `excluido_em`),
  CONSTRAINT `fk_anotacao_caixa` FOREIGN KEY (`caixa_id`) REFERENCES `tab_ftth_caixa` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
