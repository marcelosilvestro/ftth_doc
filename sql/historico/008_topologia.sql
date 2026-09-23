-- ftth_doc :: 008 :: splitters, portas de atendimento e LIGACOES (o grafo)
--
-- Invariantes garantidas aqui pelo proprio banco:
--  I3  toda ligacao tem exatamente 2 pontas  -> UNIQUE(ligacao_id, lado) + checagem no lib/
--  I4  uma ponta participa de no maximo UMA ligacao
--      -> UNIQUE(caixa_id, elemento, elemento_id, numero) em tab_ftth_ligacao_ponta
--  I5  splitter = 1 entrada + N saidas (modelo)
--  I6  saida de ATENDIMENTO aceita no maximo 1 cliente -> UNIQUE(cliente_id) e UNIQUE(splitter,numero)
--
-- F1: a identidade do cliente e sis_cliente.id (INT). `login` vai junto so como atributo,
--     nunca como chave de JOIN (F5: mkradius e latin1, nossas tabelas sao utf8mb4).

CREATE TABLE IF NOT EXISTS `tab_ftth_splitter` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `caixa_id`      INT UNSIGNED NOT NULL,
  `nome`          VARCHAR(60)  NOT NULL,
  `funcao`        ENUM('ATENDIMENTO','DERIVACAO') NOT NULL,
  `modelo`        ENUM('BAL','DESBAL','PERSONALIZADO') NOT NULL DEFAULT 'BAL',
  `razao`         VARCHAR(12)  NOT NULL COMMENT '1:8 | 10/90 | LIVRE',
  `saidas`        SMALLINT UNSIGNED NOT NULL,
  `perdas_json`   TEXT         NOT NULL COMMENT 'JSON {"1":10.50,...} copiado do catalogo e editavel',
  `orientacao`    ENUM('V','H') NOT NULL DEFAULT 'V',
  `conectorizado` TINYINT(1)   NOT NULL DEFAULT 0,
  `versao`        INT UNSIGNED NOT NULL DEFAULT 1,
  `criado_por`    VARCHAR(60)  NULL,
  `criado_em`     DATETIME     NULL,
  `alterado_por`  VARCHAR(60)  NULL,
  `alterado_em`   DATETIME     NULL,
  `excluido_em`   DATETIME     NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_splitter_nome` (`caixa_id`, `nome`),
  KEY `ix_caixa_ativo` (`caixa_id`, `excluido_em`),
  CONSTRAINT `fk_splitter_caixa` FOREIGN KEY (`caixa_id`) REFERENCES `tab_ftth_caixa` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Uma linha por saida de splitter de ATENDIMENTO que recebeu cliente.
-- Saida de DERIVACAO nunca entra aqui (regra no lib/Topologia).
-- Tambem SEM soft delete, pelo mesmo motivo da ligacao: desvincular precisa liberar
-- a porta e o cliente de verdade. O historico fica em tab_ftth_historico.
CREATE TABLE IF NOT EXISTS `tab_ftth_porta` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `splitter_id`   INT UNSIGNED NOT NULL,
  `numero`        SMALLINT UNSIGNED NOT NULL,
  `cliente_id`    INT UNSIGNED NOT NULL COMMENT 'sis_cliente.id (F1)',
  `login`         VARCHAR(60)  NOT NULL COMMENT 'atributo de exibicao/busca, nunca chave de JOIN',
  `versao`        INT UNSIGNED NOT NULL DEFAULT 1,
  `criado_por`    VARCHAR(60)  NULL,
  `criado_em`     DATETIME     NULL,
  `alterado_por`  VARCHAR(60)  NULL,
  `alterado_em`   DATETIME     NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_splitter_porta` (`splitter_id`, `numero`),
  UNIQUE KEY `uq_cliente` (`cliente_id`),
  KEY `ix_login` (`login`),
  CONSTRAINT `fk_porta_splitter` FOREIGN KEY (`splitter_id`) REFERENCES `tab_ftth_splitter` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===== O GRAFO =====
-- Uma ligacao une duas pontas dentro de UMA caixa. Tipos:
--   FUSAO    fibra soldada em fibra / em saida de splitter
--   PASSAGEM fibra que atravessa a caixa sem derivacao (A2, gerada automaticamente)
--   CONECTOR conexao conectorizada (adiciona perda de conector)
--
-- ATENCAO (decisao de modelagem): ligacao NAO tem soft delete.
-- Se tivesse, a linha da ponta continuaria existindo e o UNIQUE uq_ponta_unica impediria
-- religar aquela fibra para sempre. Desconectar apaga a ligacao (CASCADE nas pontas) e o
-- historico fica em tab_ftth_historico (antes/depois + usuario + request_id), que e o lugar
-- certo para isso. Snapshot do diagrama tambem preserva o estado anterior.
CREATE TABLE IF NOT EXISTS `tab_ftth_ligacao` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `caixa_id`      INT UNSIGNED NOT NULL,
  `tipo`          ENUM('FUSAO','PASSAGEM','CONECTOR') NOT NULL DEFAULT 'FUSAO',
  `perda_medida_db` DECIMAL(5,2) NULL COMMENT 'perda real medida na fusao, quando houver',
  `versao`        INT UNSIGNED NOT NULL DEFAULT 1,
  `criado_por`    VARCHAR(60)  NULL,
  `criado_em`     DATETIME     NULL,
  `alterado_por`  VARCHAR(60)  NULL,
  `alterado_em`   DATETIME     NULL,
  PRIMARY KEY (`id`),
  KEY `ix_caixa` (`caixa_id`),
  CONSTRAINT `fk_ligacao_caixa` FOREIGN KEY (`caixa_id`) REFERENCES `tab_ftth_caixa` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- elemento + elemento_id + numero identificam a ponta:
--   VAO_FIBRA    elemento_id = tab_ftth_cabo_vao.id, numero = numero da fibra
--   SPLITTER_IN  elemento_id = tab_ftth_splitter.id, numero = 0
--   SPLITTER_OUT elemento_id = tab_ftth_splitter.id, numero = saida (1..N)
--   DIO_PORTA    elemento_id = tab_ftth_dio_porta.id, numero = 0
CREATE TABLE IF NOT EXISTS `tab_ftth_ligacao_ponta` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `ligacao_id`   INT UNSIGNED NOT NULL,
  `lado`         ENUM('A','B') NOT NULL,
  `caixa_id`     INT UNSIGNED NOT NULL,
  `elemento`     ENUM('VAO_FIBRA','SPLITTER_IN','SPLITTER_OUT','DIO_PORTA') NOT NULL,
  `elemento_id`  INT UNSIGNED NOT NULL,
  `numero`       SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ponta_unica` (`caixa_id`, `elemento`, `elemento_id`, `numero`),
  UNIQUE KEY `uq_lado` (`ligacao_id`, `lado`),
  KEY `ix_elemento` (`elemento`, `elemento_id`, `numero`),
  CONSTRAINT `fk_ponta_ligacao` FOREIGN KEY (`ligacao_id`) REFERENCES `tab_ftth_ligacao` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ponta_caixa`   FOREIGN KEY (`caixa_id`)   REFERENCES `tab_ftth_caixa` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
