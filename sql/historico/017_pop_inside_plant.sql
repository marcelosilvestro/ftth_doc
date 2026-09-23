-- ftth_doc :: 017 :: o que faltava no POP (placas PON, fabricante e operacao da porta)
--
-- A 007 ja trouxe tab_ftth_olt, tab_ftth_dio e tab_ftth_dio_porta com quase tudo — inclusive
-- ptx_dbm, que e a origem do sinal para o calculo de potencia. Falta so o que a interface
-- do POP pediu (22/09/2026):
--
--   * fabricante e metodo de acesso na OLT, para uma OLT sem vinculo com a nativa `olt`;
--   * as PLACAS PON (line cards), que sao o que gera os rotulos 0/1/1, 0/1/2 ... do seletor
--     de equipamento da porta do DIO — sem elas a PON teria de ser digitada a mao;
--   * a operacao da porta (distribuicao ou ponto a ponto).
--
-- ALTER TABLE nao e idempotente por natureza, e o Migrator promete que todo arquivo pode ser
-- reexecutado sem estrago (DDL no MySQL nao tem rollback). Por isso cada alteracao e
-- precedida de uma checagem no information_schema: se a coluna ja existe, o comando vira um
-- SELECT inofensivo.

-- ---------------------------------------------------------------- OLT: fabricante
SET @tem := (SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tab_ftth_olt'
                AND COLUMN_NAME = 'fabricante');
SET @sql := IF(@tem = 0,
  'ALTER TABLE `tab_ftth_olt` ADD COLUMN `fabricante` VARCHAR(40) NULL COMMENT "vendor; vazio quando vem da nativa olt.maker" AFTER `apelido`',
  'SELECT 1');
PREPARE st FROM @sql;
EXECUTE st;
DEALLOCATE PREPARE st;

-- ---------------------------------------------------------------- OLT: metodo de acesso
SET @tem := (SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tab_ftth_olt'
                AND COLUMN_NAME = 'acesso');
SET @sql := IF(@tem = 0,
  'ALTER TABLE `tab_ftth_olt` ADD COLUMN `acesso` ENUM("simulado","ssh","telnet") NOT NULL DEFAULT "simulado" COMMENT "conexao real com a OLT e fase 4; ate la, simulado" AFTER `formato_porta`',
  'SELECT 1');
PREPARE st FROM @sql;
EXECUTE st;
DEALLOCATE PREPARE st;

-- ---------------------------------------------------------------- porta do DIO: operacao
SET @tem := (SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tab_ftth_dio_porta'
                AND COLUMN_NAME = 'operacao');
SET @sql := IF(@tem = 0,
  'ALTER TABLE `tab_ftth_dio_porta` ADD COLUMN `operacao` ENUM("DISTRIBUICAO","PTP_TX","PTP_RX","PTP_TXRX") NOT NULL DEFAULT "DISTRIBUICAO" AFTER `numero`',
  'SELECT 1');
PREPARE st FROM @sql;
EXECUTE st;
DEALLOCATE PREPARE st;

-- ---------------------------------------------------------------- placas PON (line cards)
-- Uma placa descreve uma faixa de PONs do chassi: prefixo 0/1, 16 portas comecando na 1
-- gera 0/1/1 ate 0/1/16. E dai que sai a lista do seletor "equipamento interno" da porta.
CREATE TABLE IF NOT EXISTS `tab_ftth_olt_placa` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `olt_id`     INT UNSIGNED NOT NULL COMMENT 'tab_ftth_olt.id (a do addon, nao a nativa)',
  `prefixo`    VARCHAR(20)  NOT NULL COMMENT 'frame/slot, ex 0/1',
  `portas`     SMALLINT UNSIGNED NOT NULL DEFAULT 16,
  `inicio`     SMALLINT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'numero da primeira PON',
  `ordem`      SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_placa_prefixo` (`olt_id`, `prefixo`),
  CONSTRAINT `fk_placa_olt` FOREIGN KEY (`olt_id`) REFERENCES `tab_ftth_olt` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
