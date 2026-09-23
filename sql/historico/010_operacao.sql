-- ftth_doc :: 010 :: medicoes, certificacao, ocorrencias e fotos

-- Potencia MEDIDA (o "real"), sempre separada da estimada (3b.9).
CREATE TABLE IF NOT EXISTS `tab_ftth_medicao` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `alvo`        ENUM('PORTA','CLIENTE','DIO_PORTA','LIGACAO') NOT NULL,
  `alvo_id`     INT UNSIGNED NOT NULL,
  `lambda_nm`   SMALLINT UNSIGNED NOT NULL DEFAULT 1490,
  `valor_dbm`   DECIMAL(6,2) NOT NULL,
  `origem`      ENUM('MANUAL','OLT_ZTE','POWER_METER','OTDR') NOT NULL DEFAULT 'MANUAL',
  `observacao`  VARCHAR(160) NULL,
  `criado_por`  VARCHAR(60)  NOT NULL,
  `criado_em`   DATETIME     NOT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_alvo` (`alvo`, `alvo_id`, `criado_em`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- U8: certificar e decisao humana. Guarda a versao da topologia certificada para o selo
-- virar "certificada em <data>, alterada depois" quando a caixa mudar.
CREATE TABLE IF NOT EXISTS `tab_ftth_certificacao` (
  `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `caixa_id`         INT UNSIGNED NOT NULL,
  `topology_version` INT UNSIGNED NOT NULL,
  `checklist`        TEXT         NOT NULL COMMENT 'JSON com o resultado de cada item',
  `pendencias`       SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `certificado_por`  VARCHAR(60)  NOT NULL,
  `certificado_em`   DATETIME     NOT NULL,
  `invalidado_em`    DATETIME     NULL,
  `invalidado_motivo` VARCHAR(160) NULL,
  PRIMARY KEY (`id`),
  KEY `ix_caixa` (`caixa_id`, `certificado_em`),
  CONSTRAINT `fk_cert_caixa` FOREIGN KEY (`caixa_id`) REFERENCES `tab_ftth_caixa` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Ruptura/manutencao. Posicao no mapa e SEMPRE estimativa (3b.9).
CREATE TABLE IF NOT EXISTS `tab_ftth_ocorrencia` (
  `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `regiao_id`       INT UNSIGNED NOT NULL,
  `tipo`            ENUM('ROMPIMENTO','MANUTENCAO','PROBLEMA') NOT NULL DEFAULT 'ROMPIMENTO',
  `vao_id`          INT UNSIGNED NULL,
  `distancia_otdr_m` DECIMAL(10,2) NULL COMMENT 'informada pelo OTDR (medida)',
  `lat_estimada`    DECIMAL(10,7) NULL COMMENT 'ESTIMATIVA calculada, nunca posicao exata',
  `lng_estimada`    DECIMAL(10,7) NULL,
  `descricao`       TEXT         NULL,
  `afetados_json`   LONGTEXT     NULL COMMENT 'snapshot dos clientes por estado de afetacao',
  `status`          ENUM('aberta','fechada') NOT NULL DEFAULT 'aberta',
  `criado_por`      VARCHAR(60)  NOT NULL,
  `criado_em`       DATETIME     NOT NULL,
  `fechado_em`      DATETIME     NULL,
  PRIMARY KEY (`id`),
  KEY `ix_regiao` (`regiao_id`, `status`),
  CONSTRAINT `fk_ocor_regiao` FOREIGN KEY (`regiao_id`) REFERENCES `tab_ftth_regiao` (`id`),
  CONSTRAINT `fk_ocor_vao`    FOREIGN KEY (`vao_id`)    REFERENCES `tab_ftth_cabo_vao` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `tab_ftth_foto` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `caixa_id`    INT UNSIGNED NOT NULL,
  `arquivo`     VARCHAR(160) NOT NULL COMMENT 'nome gerado, nunca o nome enviado pelo usuario',
  `mime`        VARCHAR(40)  NOT NULL,
  `bytes`       INT UNSIGNED NOT NULL,
  `legenda`     VARCHAR(160) NULL,
  `criado_por`  VARCHAR(60)  NOT NULL,
  `criado_em`   DATETIME     NOT NULL,
  `excluido_em` DATETIME     NULL,
  PRIMARY KEY (`id`),
  KEY `ix_caixa` (`caixa_id`, `excluido_em`),
  CONSTRAINT `fk_foto_caixa` FOREIGN KEY (`caixa_id`) REFERENCES `tab_ftth_caixa` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
