-- ftth_doc :: 011 :: importacao de KMZ em QUARENTENA
--
-- 3b.7: nada entra nas tabelas reais antes de o usuario revisar. hash_arquivo detecta
-- reimportacao do mesmo arquivo; hash_geometria detecta item ja importado antes.

CREATE TABLE IF NOT EXISTS `tab_ftth_importacao` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `regiao_id`     INT UNSIGNED NOT NULL,
  `arquivo`       VARCHAR(160) NOT NULL COMMENT 'nome original, apenas informativo',
  `hash_arquivo`  CHAR(64)     NOT NULL COMMENT 'SHA-256 do KMZ',
  `bytes`         INT UNSIGNED NOT NULL,
  `formato`       ENUM('KMZ','KML','CSV') NOT NULL DEFAULT 'KMZ',
  `total_itens`   INT UNSIGNED NOT NULL DEFAULT 0,
  `pendentes`     INT UNSIGNED NOT NULL DEFAULT 0,
  `importados`    INT UNSIGNED NOT NULL DEFAULT 0,
  `descartados`   INT UNSIGNED NOT NULL DEFAULT 0,
  `alertas`       INT UNSIGNED NOT NULL DEFAULT 0,
  `erro`          TEXT         NULL,
  `criado_por`    VARCHAR(60)  NOT NULL,
  `criado_em`     DATETIME     NOT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_hash` (`hash_arquivo`),
  KEY `ix_regiao` (`regiao_id`, `criado_em`),
  CONSTRAINT `fk_imp_regiao` FOREIGN KEY (`regiao_id`) REFERENCES `tab_ftth_regiao` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `tab_ftth_importacao_item` (
  `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `importacao_id`  INT UNSIGNED NOT NULL,
  `regiao_id`      INT UNSIGNED NOT NULL,
  `tipo_sugerido`  ENUM('CAIXA','VAO') NOT NULL,
  `subtipo`        VARCHAR(20)  NULL COMMENT 'CEO, CTO, DC... ou rotulo do cabo',
  `nome`           VARCHAR(80)  NULL,
  `cor`            CHAR(7)      NULL,
  `geometria`      MEDIUMTEXT   NOT NULL COMMENT 'JSON [[lat,lng],...]',
  `hash_geometria` CHAR(64)     NOT NULL,
  `ancora_ini_id`  INT UNSIGNED NULL COMMENT 'caixa sugerida para a ponta inicial',
  `ancora_fim_id`  INT UNSIGNED NULL,
  `alertas_json`   TEXT         NULL COMMENT 'JSON [{code,message}] - ex. pontas na mesma caixa',
  `status`         ENUM('pendente','importado','descartado') NOT NULL DEFAULT 'pendente',
  `gerado_tipo`    ENUM('CAIXA','VAO') NULL COMMENT 'o que foi criado ao importar',
  `gerado_id`      INT UNSIGNED NULL,
  `decidido_por`   VARCHAR(60)  NULL,
  `decidido_em`    DATETIME     NULL,
  PRIMARY KEY (`id`),
  KEY `ix_importacao` (`importacao_id`, `status`),
  KEY `ix_regiao_status` (`regiao_id`, `status`),
  KEY `ix_hash_geo` (`hash_geometria`),
  CONSTRAINT `fk_item_importacao` FOREIGN KEY (`importacao_id`) REFERENCES `tab_ftth_importacao` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_item_regiao`     FOREIGN KEY (`regiao_id`)     REFERENCES `tab_ftth_regiao` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
