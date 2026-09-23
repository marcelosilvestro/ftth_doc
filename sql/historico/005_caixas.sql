-- ftth_doc :: 005 :: caixas (todo elemento pontual da planta)
--
-- Invariantes relacionadas:
--  I9  registros com excluido_em nao aparecem em operacao normal (filtro no lib/)
--  UNIQUE(regiao_id, nome) e GLOBAL, incluindo excluidos: nome de caixa nunca e reaproveitado,
--       para o historico continuar legivel (decisao 3b.20 #2).

CREATE TABLE IF NOT EXISTS `tab_ftth_caixa` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `regiao_id`     INT UNSIGNED NOT NULL,
  `tipo`          ENUM('DC','PREDIO','POSTE','CEO','CTO','CTO_AP','CLIENTE','RESERVA','PROBLEMA','FALHA') NOT NULL,
  `nome`          VARCHAR(80)  NOT NULL,
  `cor`           CHAR(7)      NOT NULL DEFAULT '#00C853',
  `lat`           DECIMAL(10,7) NOT NULL,
  `lng`           DECIMAL(10,7) NOT NULL,
  `pai_id`        INT UNSIGNED NULL COMMENT 'CTO dentro de PREDIO/DC',
  `andar`         VARCHAR(20)  NULL,
  `capacidade`    SMALLINT UNSIGNED NULL COMMENT 'portas de atendimento previstas',
  `status`        ENUM('projeto','implantada','certificada') NOT NULL DEFAULT 'implantada',
  `observacao`    TEXT         NULL,
  `qr_token`      CHAR(32)     NULL,
  `origem`        ENUM('manual','kmz') NOT NULL DEFAULT 'manual',
  `importacao_id` INT UNSIGNED NULL,
  `versao`        INT UNSIGNED NOT NULL DEFAULT 1,
  `criado_por`    VARCHAR(60)  NULL,
  `criado_em`     DATETIME     NULL,
  `alterado_por`  VARCHAR(60)  NULL,
  `alterado_em`   DATETIME     NULL,
  `excluido_em`   DATETIME     NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_caixa_nome` (`regiao_id`, `nome`),
  UNIQUE KEY `uq_qr` (`qr_token`),
  KEY `ix_regiao_ativo` (`regiao_id`, `excluido_em`),
  KEY `ix_bbox` (`regiao_id`, `lat`, `lng`),
  KEY `ix_tipo` (`regiao_id`, `tipo`, `excluido_em`),
  KEY `ix_pai` (`pai_id`),
  CONSTRAINT `fk_caixa_regiao` FOREIGN KEY (`regiao_id`) REFERENCES `tab_ftth_regiao` (`id`),
  CONSTRAINT `fk_caixa_pai`    FOREIGN KEY (`pai_id`)    REFERENCES `tab_ftth_caixa` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
