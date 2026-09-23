-- ftth_doc :: 006 :: cabos (logico) e vaos (fisico, entre duas caixas)
--
-- Invariantes relacionadas:
--  I1  cabo tem >= 1 vao; vao tem origem e destino validos, geometria valida e caixas distintas
--  I2  uma fibra pertence a um unico vao (fibra = vao + numero, nao tem tabela propria)
--  A5  comprimento optico = geometrico * fator_folga + reserva

CREATE TABLE IF NOT EXISTS `tab_ftth_cabo` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `regiao_id`     INT UNSIGNED NOT NULL,
  `nome`          VARCHAR(80)  NULL,
  `fabricante`    VARCHAR(60)  NULL,
  `cabo_tipo_id`  INT UNSIGNED NOT NULL,
  `padrao_cores`  ENUM('ABNT','EIA-TIA') NOT NULL DEFAULT 'ABNT',
  `cor_rota`      CHAR(7)      NOT NULL DEFAULT '#00E676',
  `status`        ENUM('projeto','implantado','certificado') NOT NULL DEFAULT 'implantado',
  `origem`        ENUM('manual','kmz') NOT NULL DEFAULT 'manual',
  `importacao_id` INT UNSIGNED NULL,
  `versao`        INT UNSIGNED NOT NULL DEFAULT 1,
  `criado_por`    VARCHAR(60)  NULL,
  `criado_em`     DATETIME     NULL,
  `alterado_por`  VARCHAR(60)  NULL,
  `alterado_em`   DATETIME     NULL,
  `excluido_em`   DATETIME     NULL,
  PRIMARY KEY (`id`),
  KEY `ix_regiao_ativo` (`regiao_id`, `excluido_em`),
  CONSTRAINT `fk_cabo_regiao` FOREIGN KEY (`regiao_id`) REFERENCES `tab_ftth_regiao` (`id`),
  CONSTRAINT `fk_cabo_tipo`   FOREIGN KEY (`cabo_tipo_id`) REFERENCES `tab_ftth_cabo_tipo` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `tab_ftth_cabo_vao` (
  `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `cabo_id`          INT UNSIGNED NOT NULL,
  `regiao_id`        INT UNSIGNED NOT NULL,
  `ordem`            SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  `caixa_ini_id`     INT UNSIGNED NOT NULL,
  `caixa_fim_id`     INT UNSIGNED NOT NULL,
  `vertices`         MEDIUMTEXT   NOT NULL COMMENT 'JSON [[lat,lng],...] incluindo as duas pontas',
  `comprimento_geo`  DECIMAL(10,2) NOT NULL DEFAULT 0 COMMENT 'metros, Haversine sobre os vertices',
  `fator_folga`      DECIMAL(4,3) NOT NULL DEFAULT 1.030,
  `reserva_m`        DECIMAL(8,2) NOT NULL DEFAULT 0,
  `comprimento_optico` DECIMAL(10,2) NOT NULL DEFAULT 0 COMMENT 'geo * folga + reserva',
  `origem`           ENUM('manual','kmz') NOT NULL DEFAULT 'manual',
  `importacao_id`    INT UNSIGNED NULL,
  `versao`           INT UNSIGNED NOT NULL DEFAULT 1,
  `criado_por`       VARCHAR(60)  NULL,
  `criado_em`        DATETIME     NULL,
  `alterado_por`     VARCHAR(60)  NULL,
  `alterado_em`      DATETIME     NULL,
  `excluido_em`      DATETIME     NULL,
  PRIMARY KEY (`id`),
  KEY `ix_cabo` (`cabo_id`, `ordem`),
  KEY `ix_regiao_ativo` (`regiao_id`, `excluido_em`),
  KEY `ix_caixa_ini` (`caixa_ini_id`),
  KEY `ix_caixa_fim` (`caixa_fim_id`),
  CONSTRAINT `fk_vao_cabo`      FOREIGN KEY (`cabo_id`)      REFERENCES `tab_ftth_cabo` (`id`),
  CONSTRAINT `fk_vao_regiao`    FOREIGN KEY (`regiao_id`)    REFERENCES `tab_ftth_regiao` (`id`),
  CONSTRAINT `fk_vao_caixa_ini` FOREIGN KEY (`caixa_ini_id`) REFERENCES `tab_ftth_caixa` (`id`),
  CONSTRAINT `fk_vao_caixa_fim` FOREIGN KEY (`caixa_fim_id`) REFERENCES `tab_ftth_caixa` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
