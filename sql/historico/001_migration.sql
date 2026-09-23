-- ftth_doc :: 001 :: controle de migrations
-- Tabela que registra o que já foi aplicado. É a única que o Migrator cria sozinho.

CREATE TABLE IF NOT EXISTS `tab_ftth_migration` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `migration`   VARCHAR(160)  NOT NULL,
  `checksum`    CHAR(64)      NOT NULL,
  `versao`      VARCHAR(20)   NOT NULL DEFAULT '1.0',
  `executed_at` DATETIME      NOT NULL,
  `executed_by` VARCHAR(60)   NOT NULL,
  `duracao_ms`  INT UNSIGNED  NOT NULL DEFAULT 0,
  `resultado`   ENUM('ok','erro') NOT NULL DEFAULT 'ok',
  `erro`        TEXT          NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_migration` (`migration`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
