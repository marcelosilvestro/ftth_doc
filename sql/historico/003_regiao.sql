-- ftth_doc :: 003 :: regioes (particao logica da rede: cidade/bairro) e backups de regiao

CREATE TABLE IF NOT EXISTS `tab_ftth_regiao` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `nome`        VARCHAR(80)  NOT NULL,
  `lat`         DECIMAL(10,7) NULL,
  `lng`         DECIMAL(10,7) NULL,
  `zoom`        TINYINT UNSIGNED NOT NULL DEFAULT 15,
  `versao`      INT UNSIGNED NOT NULL DEFAULT 1,
  `criado_por`  VARCHAR(60)  NULL,
  `criado_em`   DATETIME     NULL,
  `alterado_por` VARCHAR(60) NULL,
  `alterado_em` DATETIME     NULL,
  `excluido_em` DATETIME     NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_regiao_nome` (`nome`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Snapshot completo de uma regiao (JSON), usado por "Restaurar Regiao".
-- Nao e fonte de verdade: e historico. Guarda schema_version para compatibilidade futura.
CREATE TABLE IF NOT EXISTS `tab_ftth_regiao_backup` (
  `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `regiao_id`       INT UNSIGNED NOT NULL,
  `motivo`          VARCHAR(160) NOT NULL,
  `schema_version`  INT UNSIGNED NOT NULL DEFAULT 1,
  `topology_version` INT UNSIGNED NOT NULL DEFAULT 1,
  `conteudo`        LONGTEXT     NOT NULL,
  `criado_por`      VARCHAR(60)  NOT NULL,
  `criado_em`       DATETIME     NOT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_regiao` (`regiao_id`, `criado_em`),
  CONSTRAINT `fk_backup_regiao` FOREIGN KEY (`regiao_id`) REFERENCES `tab_ftth_regiao` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
