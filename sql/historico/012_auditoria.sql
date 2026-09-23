-- ftth_doc :: 012 :: auditoria (quem alterou o que)
--
-- 3b.5: auditoria NAO e log tecnico. O log tecnico (request_id, tempo, stack trace, SQL)
-- vai para arquivo em ftth_doc/logs/. Aqui fica so a trilha de negocio.

CREATE TABLE IF NOT EXISTS `tab_ftth_historico` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `entidade`    VARCHAR(40)  NOT NULL COMMENT 'caixa, cabo, vao, ligacao, porta, splitter...',
  `entidade_id` INT UNSIGNED NOT NULL,
  `regiao_id`   INT UNSIGNED NULL,
  `acao`        VARCHAR(40)  NOT NULL COMMENT 'criar, alterar, excluir, conectar, desconectar...',
  `antes`       MEDIUMTEXT   NULL COMMENT 'JSON',
  `depois`      MEDIUMTEXT   NULL COMMENT 'JSON',
  `usuario`     VARCHAR(60)  NOT NULL,
  `request_id`  VARCHAR(32)  NULL,
  `criado_em`   DATETIME     NOT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_entidade` (`entidade`, `entidade_id`, `criado_em`),
  KEY `ix_usuario` (`usuario`, `criado_em`),
  KEY `ix_request` (`request_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
