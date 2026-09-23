-- ftth_doc :: 016 :: espelho da tabela nativa `cto` e chaves de sincronizacao
--
-- DECISAO DE 21/09/2026 — segunda excecao aprovada de escrita em tabela nativa.
-- O addon alimenta `cto` como ESPELHO DE IDA: escreve e NUNCA le de volta como verdade.
-- A verdade mora em tab_ftth_caixa + topologia; `cto` e projecao para o modulo CTO do
-- HelpFiber enxergar a rede documentada aqui.
--
-- `cto_option` fica FORA do escopo de proposito: ela guarda portas INUTILIZADAS com o
-- motivo (botao "Inutilizar Porta"), e nao a ocupacao. A ocupacao o HelpFiber monta a
-- partir de sis_cliente.caixa_herm / porta_splitter, que ja sao excecao aprovada.
--
-- Nada de FK para `cto`: e schema alheio, latin1, sem UNIQUE e fora do nosso controle.
-- Quem garante a consistencia e esta tabela de mapeamento, nao o banco.

CREATE TABLE IF NOT EXISTS `tab_ftth_cto_espelho` (
  `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `caixa_id`        INT UNSIGNED NOT NULL,
  `cto_id`          INT UNSIGNED NOT NULL COMMENT 'cto.id na base nativa — sem FK, schema alheio',
  `nome_gravado`    VARCHAR(32)  NOT NULL COMMENT 'o que coube em cto.name (varchar(32) la)',
  `hash`            CHAR(40)     NOT NULL COMMENT 'sha1 do payload: evita UPDATE que nao muda nada',
  `sincronizado_em` DATETIME     NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_espelho_caixa` (`caixa_id`),
  UNIQUE KEY `uq_espelho_cto`   (`cto_id`),
  CONSTRAINT `fk_espelho_caixa` FOREIGN KEY (`caixa_id`) REFERENCES `tab_ftth_caixa` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `tab_ftth_config` (`chave`, `valor`, `descricao`) VALUES
  ('sync_cto_nativa',      '0',  'Excecao aprovada: espelha as CTOs na tabela nativa `cto` (1=liga)'),
  ('sync_cto_olt_id',      '',   'olt.id gravado em cto.olt_id; vazio = usa a unica OLT cadastrada'),
  ('sync_porta_qualificar','1',  'CTO com mais de um splitter de atendimento: grava "Splitter:numero" em porta_splitter (1) ou so o numero, com aviso de ambiguidade (0)')
ON DUPLICATE KEY UPDATE `chave` = `chave`;
