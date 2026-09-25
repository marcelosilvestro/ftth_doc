-- ftth_doc :: baseline do schema
--
-- Este arquivo e o schema INTEIRO do addon e roda em TODA instalacao e em toda atualizacao.
-- Ele substitui as 17 migrations numeradas, que ficam em sql/historico/ apenas como registro
-- (nunca sao aplicadas). Por que trocar: uma migration numerada roda uma vez e nunca mais;
-- um baseline que e reaplicado a cada versao e o que permite publicar v0.9.1 com uma coluna
-- nova sem inventar arquivo incremental.
--
-- REGRA DE OURO: tudo aqui e idempotente.
--   CREATE TABLE IF NOT EXISTS   -> nao recria o que existe
--   INSERT ... ON DUPLICATE KEY  -> nao pisa em valor que o provedor ajustou
--   ALTER condicionado por information_schema (bloco final) -> nao repete coluna
-- Rodar duas vezes seguidas tem de deixar o banco exatamente igual.
--
-- LIMITES DO PARSER (lib/Schema.php): os comandos sao separados por ";" no fim da linha e
-- linhas iniciadas por "--" sao descartadas. Logo: nunca escreva comentario depois do ";"
-- na mesma linha, e todo comando termina com ";" no fim de uma linha.
--
-- ORDEM: obrigatoria por causa das chaves estrangeiras. Quem referencia vem depois de quem
-- e referenciado.

-- ================================================================ controle
-- Diario de aplicacoes do schema: uma linha por arquivo aplicado, com checksum e versao.
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

-- ================================================================ configuracao
CREATE TABLE IF NOT EXISTS `tab_ftth_config` (
  `chave`       VARCHAR(64)  NOT NULL,
  `valor`       TEXT         NULL,
  `descricao`   VARCHAR(255) NULL,
  `alterado_por` VARCHAR(60) NULL,
  `alterado_em` DATETIME     NULL,
  PRIMARY KEY (`chave`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- O ON DUPLICATE atualiza so a descricao: o texto da tela melhora entre versoes sem nunca
-- sobrescrever a chave do Google Maps nem um valor calibrado pelo provedor.
INSERT INTO `tab_ftth_config` (`chave`, `valor`, `descricao`) VALUES
  ('google_maps_key',        '',      'Chave do Google Maps JS API (restrita por referer)'),
  ('lambda_estimativa',      '1490',  'Comprimento de onda usado na estimativa de RX da ONU (nm)'),
  ('fator_folga_cabo',       '1.03',  'Multiplicador do comprimento geometrico para obter o optico'),
  ('perda_fusao_db',         '0.10',  'Perda padrao por fusao (dB)'),
  ('perda_conector_db',      '0.50',  'Perda padrao por conector (dB)'),
  ('faixa_sinal_excelente',  '-22.0', 'RX >= este valor: Excelente (dBm)'),
  ('faixa_sinal_bom',        '-25.0', 'RX >= este valor: Bom (dBm)'),
  ('faixa_sinal_limite',     '-27.0', 'RX >= este valor: Limite; abaixo disso Critico (dBm)'),
  ('saturacao_dbm',          '-8.0',  'RX acima deste valor indica saturacao da ONU (dBm)'),
  ('raio_viabilidade_m',     '150',   'Raio padrao da busca de viabilidade (metros)'),
  ('tolerancia_ancora_m',    '3',     'Distancia maxima para ancorar ponta de vao em uma caixa (metros)'),
  ('sync_sis_cliente',       '0',     'Excecao aprovada: escreve caixa_herm/porta_splitter em sis_cliente (1=liga)'),
  ('mapa_tipo',              'hybrid','Tipo de mapa ao abrir: roadmap | satellite | hybrid | terrain'),
  ('mapa_rotulo_zoom',       '17',    'Zoom a partir do qual o nome da caixa aparece no mapa'),
  ('mapa_cabo_espessura',    '5',     'Espessura da linha do cabo no mapa (px)'),
  ('mapa_cabo_espessura_q',  '4',     'Espessura da linha de cabo ainda em quarentena (px)'),
  ('raio_quebra_cabo_m',     '10',    'Distancia em metros para o mapa oferecer emendar a caixa no cabo'),
  ('sync_cto_nativa',        '0',     'Excecao aprovada: espelha as CTOs na tabela nativa `cto` (1=liga)'),
  ('sync_cto_olt_id',        '',      'olt.id gravado em cto.olt_id; vazio = usa a unica OLT cadastrada'),
  ('sync_porta_qualificar',  '1',     'CTO com mais de um splitter de atendimento: grava "Splitter:numero" em porta_splitter (1) ou so o numero, com aviso de ambiguidade (0)')
ON DUPLICATE KEY UPDATE `descricao` = VALUES(`descricao`);

-- ================================================================ regiao
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

-- ================================================================ catalogos
-- Valores editaveis pelo provedor. A procedencia fica explicita:
-- 'catalogo' = valor tipico de fabricante; 'provedor' = ajustado pelo provedor.
CREATE TABLE IF NOT EXISTS `tab_ftth_cabo_tipo` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `rotulo`        VARCHAR(40)  NOT NULL,
  `fibras`        SMALLINT UNSIGNED NOT NULL,
  `tubos`         SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  `fibras_por_tubo` SMALLINT UNSIGNED NOT NULL,
  `construcao`    ENUM('monotubo','multitubo') NOT NULL DEFAULT 'monotubo',
  `ordem`         SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `ativo`         TINYINT(1)   NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_rotulo` (`rotulo`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `tab_ftth_cabo_tipo` (`rotulo`,`fibras`,`tubos`,`fibras_por_tubo`,`construcao`,`ordem`) VALUES
  ('Drop (1 FO)',        1,  1,  1, 'monotubo', 10),
  ('2 FO',               2,  1,  2, 'monotubo', 20),
  ('4 FO',               4,  1,  4, 'monotubo', 30),
  ('6 FO',               6,  1,  6, 'monotubo', 40),
  ('8 FO',               8,  1,  8, 'monotubo', 50),
  ('12 FO',             12,  1, 12, 'monotubo', 60),
  ('12 FO MULT (2x6)',  12,  2,  6, 'multitubo', 70),
  ('24 FO (Monotubo)',  24,  1, 24, 'monotubo', 80),
  ('24 FO MULT (4x6)',  24,  4,  6, 'multitubo', 90),
  ('36 FO MULT (6x6)',  36,  6,  6, 'multitubo',100),
  ('36 FO MULT (3x12)', 36,  3, 12, 'multitubo',110),
  ('48 FO MULT (4x12)', 48,  4, 12, 'multitubo',120),
  ('72 FO MULT (6x12)', 72,  6, 12, 'multitubo',130),
  ('96 FO MULT (8x12)', 96,  8, 12, 'multitubo',140),
  ('144 FO MULT (12x12)',144,12,12, 'multitubo',150)
ON DUPLICATE KEY UPDATE `rotulo` = `rotulo`;

-- Perda por saida de splitter. Balanceado: uma linha por razao.
-- Desbalanceado: uma linha por razao com perda do ramo menor e do ramo maior.
CREATE TABLE IF NOT EXISTS `tab_ftth_perda_padrao` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `modelo`      ENUM('BAL','DESBAL') NOT NULL,
  `razao`       VARCHAR(12)  NOT NULL COMMENT 'BAL: 1:8 | DESBAL: 10/90',
  `saidas`      SMALLINT UNSIGNED NOT NULL,
  `perda_db`    DECIMAL(5,2) NOT NULL COMMENT 'BAL: perda de cada saida | DESBAL: ramo menor',
  `perda_db2`   DECIMAL(5,2) NULL     COMMENT 'DESBAL: ramo maior',
  `procedencia` ENUM('catalogo','provedor') NOT NULL DEFAULT 'catalogo',
  `ordem`       SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_modelo_razao` (`modelo`, `razao`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `tab_ftth_perda_padrao` (`modelo`,`razao`,`saidas`,`perda_db`,`perda_db2`,`ordem`) VALUES
  ('BAL','1:2',  2,  3.70, NULL, 10),
  ('BAL','1:4',  4,  7.30, NULL, 20),
  ('BAL','1:8',  8, 10.50, NULL, 30),
  ('BAL','1:16',16, 13.80, NULL, 40),
  ('BAL','1:32',32, 17.10, NULL, 50),
  ('BAL','1:64',64, 20.50, NULL, 60),
  ('DESBAL','1/99', 2, 20.00, 0.30, 70),
  ('DESBAL','2/98', 2, 17.00, 0.40, 80),
  ('DESBAL','5/95', 2, 13.70, 0.50, 90),
  ('DESBAL','10/90',2, 10.50, 0.70,100),
  ('DESBAL','15/85',2,  8.70, 0.90,110),
  ('DESBAL','20/80',2,  7.40, 1.20,120),
  ('DESBAL','25/75',2,  6.40, 1.50,130),
  ('DESBAL','30/70',2,  5.60, 1.80,140),
  ('DESBAL','35/65',2,  4.90, 2.10,150),
  ('DESBAL','40/60',2,  4.30, 2.50,160),
  ('DESBAL','45/55',2,  3.80, 2.90,170),
  ('DESBAL','50/50',2,  3.40, 3.40,180)
ON DUPLICATE KEY UPDATE `modelo` = `modelo`;

-- Atenuacao da fibra por comprimento de onda. 1490 nm e o downstream GPON (OLT -> ONU).
CREATE TABLE IF NOT EXISTS `tab_ftth_atenuacao` (
  `lambda_nm`   SMALLINT UNSIGNED NOT NULL,
  `db_km`       DECIMAL(4,3) NOT NULL,
  `descricao`   VARCHAR(60)  NOT NULL,
  `procedencia` ENUM('catalogo','provedor') NOT NULL DEFAULT 'catalogo',
  PRIMARY KEY (`lambda_nm`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `tab_ftth_atenuacao` (`lambda_nm`,`db_km`,`descricao`) VALUES
  (1270, 0.380, 'XGS-PON upstream'),
  (1310, 0.350, 'GPON upstream'),
  (1490, 0.280, 'GPON downstream'),
  (1550, 0.220, 'RF video / OTDR'),
  (1577, 0.220, 'XGS-PON downstream')
ON DUPLICATE KEY UPDATE `lambda_nm` = `lambda_nm`;

-- ================================================================ planta externa
-- Caixa = todo elemento pontual da planta.
-- UNIQUE(regiao_id, nome) e GLOBAL, incluindo excluidos: nome de caixa nunca e reaproveitado,
-- para o historico continuar legivel.
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
  `reserva_m`     DECIMAL(8,2) NULL COMMENT 'so RESERVA: metros de cabo enrolado',
  `vao_id`        INT UNSIGNED NULL COMMENT 'so RESERVA: o vao em que a reserva esta',
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
  KEY `ix_vao` (`vao_id`),
  CONSTRAINT `fk_caixa_regiao` FOREIGN KEY (`regiao_id`) REFERENCES `tab_ftth_regiao` (`id`),
  CONSTRAINT `fk_caixa_pai`    FOREIGN KEY (`pai_id`)    REFERENCES `tab_ftth_caixa` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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

-- Vao = trecho fisico entre duas caixas. Comprimento optico = geometrico * folga + reserva.
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

-- ================================================================ inside plant (POP)
-- Host, credenciais e coordenadas da OLT vivem na tabela NATIVA `olt` (somente leitura).
-- Aqui fica so o que e do addon. olt_id referencia olt.id sem FK fisica: schema alheio, latin1.
CREATE TABLE IF NOT EXISTS `tab_ftth_olt` (
  `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `caixa_id`        INT UNSIGNED NOT NULL COMMENT 'caixa tipo DC onde a OLT esta',
  `olt_id`          INT UNSIGNED NULL COMMENT 'ID em mkradius.olt (nativa, somente leitura)',
  `apelido`         VARCHAR(60)  NOT NULL,
  `fabricante`      VARCHAR(40)  NULL COMMENT 'vendor; vazio quando vem da nativa olt.maker',
  `formato_porta`   VARCHAR(20)  NOT NULL DEFAULT '0/1/1' COMMENT 'modelo do rotulo de PON',
  `acesso`          ENUM('simulado','ssh','telnet') NOT NULL DEFAULT 'simulado' COMMENT 'conexao real com a OLT e fase 4; ate la, simulado',
  `classe_optica`   ENUM('B+','C+','C++') NOT NULL DEFAULT 'B+',
  `ptx_dbm`         DECIMAL(5,2) NOT NULL DEFAULT 3.00,
  `sensibilidade_dbm` DECIMAL(5,2) NOT NULL DEFAULT -27.00,
  `saturacao_dbm`   DECIMAL(5,2) NOT NULL DEFAULT -8.00,
  `versao`          INT UNSIGNED NOT NULL DEFAULT 1,
  `criado_por`      VARCHAR(60)  NULL,
  `criado_em`       DATETIME     NULL,
  `alterado_por`    VARCHAR(60)  NULL,
  `alterado_em`     DATETIME     NULL,
  `excluido_em`     DATETIME     NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_apelido` (`apelido`),
  KEY `ix_caixa` (`caixa_id`),
  CONSTRAINT `fk_olt_caixa` FOREIGN KEY (`caixa_id`) REFERENCES `tab_ftth_caixa` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Uma placa descreve uma faixa de PONs do chassi: prefixo 0/1 com 16 portas a partir da 1
-- gera 0/1/1 ate 0/1/16. E dai que sai a lista do seletor de equipamento da porta do DIO.
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

CREATE TABLE IF NOT EXISTS `tab_ftth_dio` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `caixa_id`      INT UNSIGNED NOT NULL,
  `nome`          VARCHAR(60)  NOT NULL,
  `portas`        SMALLINT UNSIGNED NOT NULL DEFAULT 24,
  `ativo`         TINYINT(1)   NOT NULL DEFAULT 1,
  `versao`        INT UNSIGNED NOT NULL DEFAULT 1,
  `criado_por`    VARCHAR(60)  NULL,
  `criado_em`     DATETIME     NULL,
  `alterado_por`  VARCHAR(60)  NULL,
  `alterado_em`   DATETIME     NULL,
  `excluido_em`   DATETIME     NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_dio_nome` (`caixa_id`, `nome`),
  CONSTRAINT `fk_dio_caixa` FOREIGN KEY (`caixa_id`) REFERENCES `tab_ftth_caixa` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A porta do DIO e a origem do circuito PON: nao existe tabela de caminho.
-- A ligacao fisica dela com a fibra do vao fica em tab_ftth_ligacao (elemento DIO_PORTA).
CREATE TABLE IF NOT EXISTS `tab_ftth_dio_porta` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `dio_id`        INT UNSIGNED NOT NULL,
  `numero`        SMALLINT UNSIGNED NOT NULL,
  `operacao`      ENUM('DISTRIBUICAO','PTP_TX','PTP_RX','PTP_TXRX') NOT NULL DEFAULT 'DISTRIBUICAO',
  `olt_ftth_id`   INT UNSIGNED NULL COMMENT 'equipamento ligado nesta porta',
  `pon`           VARCHAR(20)  NULL COMMENT 'rotulo da PON, ex 1/1/5',
  `servico`       VARCHAR(60)  NULL COMMENT 'nome do circuito, ex CEO.03',
  `ptx_dbm`       DECIMAL(5,2) NULL COMMENT 'potencia de saida medida/declarada nesta porta',
  `versao`        INT UNSIGNED NOT NULL DEFAULT 1,
  `criado_por`    VARCHAR(60)  NULL,
  `criado_em`     DATETIME     NULL,
  `alterado_por`  VARCHAR(60)  NULL,
  `alterado_em`   DATETIME     NULL,
  `excluido_em`   DATETIME     NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_dio_porta` (`dio_id`, `numero`),
  KEY `ix_olt` (`olt_ftth_id`),
  CONSTRAINT `fk_porta_dio` FOREIGN KEY (`dio_id`)      REFERENCES `tab_ftth_dio` (`id`),
  CONSTRAINT `fk_porta_olt` FOREIGN KEY (`olt_ftth_id`) REFERENCES `tab_ftth_olt` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ================================================================ topologia (o grafo)
-- Invariantes garantidas pelo proprio banco:
--  toda ligacao tem exatamente 2 pontas -> UNIQUE(ligacao_id, lado)
--  uma ponta participa de no maximo UMA ligacao -> uq_ponta_unica
--  saida de ATENDIMENTO aceita no maximo 1 cliente -> UNIQUE(cliente_id)
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

-- Uma linha por saida de splitter de ATENDIMENTO que recebeu cliente. A identidade do cliente
-- e sis_cliente.id; `login` vai junto so como atributo, nunca como chave de JOIN.
-- Sem soft delete: desvincular precisa liberar a porta de verdade. O historico fica na auditoria.
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

-- Uma ligacao une duas pontas dentro de UMA caixa: FUSAO, PASSAGEM (atravessa sem derivacao)
-- ou CONECTOR. Sem soft delete de proposito: a linha da ponta continuaria existindo e o
-- UNIQUE uq_ponta_unica impediria religar aquela fibra para sempre.
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

-- ================================================================ diagrama
-- O diagrama NAO guarda conectividade (ela esta em tab_ftth_ligacao): so posicao e rotacao.
CREATE TABLE IF NOT EXISTS `tab_ftth_diagrama_no` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `caixa_id`     INT UNSIGNED NOT NULL,
  `tipo`         ENUM('VAO','SPLITTER','DIO') NOT NULL,
  `elemento_id`  INT UNSIGNED NOT NULL,
  `pos_x`        INT NOT NULL DEFAULT 0,
  `pos_y`        INT NOT NULL DEFAULT 0,
  `rotacao`      SMALLINT NOT NULL DEFAULT 0,
  `invertido`    TINYINT(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_no` (`caixa_id`, `tipo`, `elemento_id`),
  CONSTRAINT `fk_no_caixa` FOREIGN KEY (`caixa_id`) REFERENCES `tab_ftth_caixa` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ================================================================ importacao de KMZ
-- Nada entra nas tabelas reais antes de o usuario revisar. hash_arquivo detecta reimportacao
-- do mesmo arquivo; hash_geometria detecta item ja importado antes.
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

-- A ancora de um vao pode ser uma caixa real OU outro item da propria quarentena: numa
-- importacao nova as caixas das pontas ainda nao existem em tab_ftth_caixa.
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
  `ancora_ini_item_id` INT UNSIGNED NULL,
  `ancora_fim_id`  INT UNSIGNED NULL,
  `ancora_fim_item_id` INT UNSIGNED NULL,
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
  KEY `ix_ancora_item` (`ancora_ini_item_id`, `ancora_fim_item_id`),
  CONSTRAINT `fk_item_importacao` FOREIGN KEY (`importacao_id`) REFERENCES `tab_ftth_importacao` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_item_regiao`     FOREIGN KEY (`regiao_id`)     REFERENCES `tab_ftth_regiao` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ================================================================ auditoria
-- Trilha de negocio, nao log tecnico. O log tecnico vai para arquivo em /opt/mk-auth/log.
-- Ninguem le esta tabela pela interface: ela existe para reconstituir o que aconteceu.
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

-- ================================================================ espelho da `cto` nativa
-- Segunda excecao aprovada de escrita em tabela nativa: o addon alimenta `cto` como espelho
-- de IDA (escreve e nunca le de volta como verdade). Sem FK: schema alheio, latin1.
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

-- ================================================================ evolucao de schema
-- Este bloco existe para bancos que JA tinham as tabelas antes desta versao: neles o
-- CREATE TABLE IF NOT EXISTS acima nao fez nada, e e aqui que as colunas novas entram.
-- Em banco novo cada checagem encontra a coluna e o comando vira um "DO 1" inofensivo.
-- Por que DO e nao SELECT: um SELECT preparado devolve resultset, e o DEALLOCATE PREPARE
-- seguinte morre com "unbuffered queries are active". O DO nao devolve nada.
-- Toda coluna nova de versoes futuras entra aqui, no mesmo formato.

SET @tem := (SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tab_ftth_olt'
                AND COLUMN_NAME = 'fabricante');
SET @sql := IF(@tem = 0,
  'ALTER TABLE `tab_ftth_olt` ADD COLUMN `fabricante` VARCHAR(40) NULL COMMENT "vendor; vazio quando vem da nativa olt.maker" AFTER `apelido`',
  'DO 1');
PREPARE st FROM @sql;
EXECUTE st;
DEALLOCATE PREPARE st;

SET @tem := (SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tab_ftth_olt'
                AND COLUMN_NAME = 'acesso');
SET @sql := IF(@tem = 0,
  'ALTER TABLE `tab_ftth_olt` ADD COLUMN `acesso` ENUM("simulado","ssh","telnet") NOT NULL DEFAULT "simulado" COMMENT "conexao real com a OLT e fase 4; ate la, simulado" AFTER `formato_porta`',
  'DO 1');
PREPARE st FROM @sql;
EXECUTE st;
DEALLOCATE PREPARE st;

SET @tem := (SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tab_ftth_dio_porta'
                AND COLUMN_NAME = 'operacao');
SET @sql := IF(@tem = 0,
  'ALTER TABLE `tab_ftth_dio_porta` ADD COLUMN `operacao` ENUM("DISTRIBUICAO","PTP_TX","PTP_RX","PTP_TXRX") NOT NULL DEFAULT "DISTRIBUICAO" AFTER `numero`',
  'DO 1');
PREPARE st FROM @sql;
EXECUTE st;
DEALLOCATE PREPARE st;

SET @tem := (SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tab_ftth_importacao_item'
                AND COLUMN_NAME = 'ancora_ini_item_id');
SET @sql := IF(@tem = 0,
  'ALTER TABLE `tab_ftth_importacao_item` ADD COLUMN `ancora_ini_item_id` INT UNSIGNED NULL AFTER `ancora_ini_id`',
  'DO 1');
PREPARE st FROM @sql;
EXECUTE st;
DEALLOCATE PREPARE st;

SET @tem := (SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tab_ftth_importacao_item'
                AND COLUMN_NAME = 'ancora_fim_item_id');
SET @sql := IF(@tem = 0,
  'ALTER TABLE `tab_ftth_importacao_item` ADD COLUMN `ancora_fim_item_id` INT UNSIGNED NULL AFTER `ancora_fim_id`',
  'DO 1');
PREPARE st FROM @sql;
EXECUTE st;
DEALLOCATE PREPARE st;

SET @tem := (SELECT COUNT(*) FROM information_schema.STATISTICS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tab_ftth_importacao_item'
                AND INDEX_NAME = 'ix_ancora_item');
SET @sql := IF(@tem = 0,
  'ALTER TABLE `tab_ftth_importacao_item` ADD KEY `ix_ancora_item` (`ancora_ini_item_id`, `ancora_fim_item_id`)',
  'DO 1');
PREPARE st FROM @sql;
EXECUTE st;
DEALLOCATE PREPARE st;

-- 0.9.3: a Reserva mora em cima de um vao e soma seus metros no comprimento optico dele.
-- O reserva_m do vao passa a ser a soma das reservas que estao nele (lib/Reserva.php).
SET @tem := (SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tab_ftth_caixa'
                AND COLUMN_NAME = 'reserva_m');
SET @sql := IF(@tem = 0,
  'ALTER TABLE `tab_ftth_caixa` ADD COLUMN `reserva_m` DECIMAL(8,2) NULL COMMENT "so RESERVA: metros de cabo enrolado" AFTER `capacidade`',
  'DO 1');
PREPARE st FROM @sql;
EXECUTE st;
DEALLOCATE PREPARE st;

SET @tem := (SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tab_ftth_caixa'
                AND COLUMN_NAME = 'vao_id');
SET @sql := IF(@tem = 0,
  'ALTER TABLE `tab_ftth_caixa` ADD COLUMN `vao_id` INT UNSIGNED NULL COMMENT "so RESERVA: o vao em que a reserva esta" AFTER `reserva_m`',
  'DO 1');
PREPARE st FROM @sql;
EXECUTE st;
DEALLOCATE PREPARE st;

SET @tem := (SELECT COUNT(*) FROM information_schema.STATISTICS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tab_ftth_caixa'
                AND INDEX_NAME = 'ix_vao');
SET @sql := IF(@tem = 0,
  'ALTER TABLE `tab_ftth_caixa` ADD KEY `ix_vao` (`vao_id`)',
  'DO 1');
PREPARE st FROM @sql;
EXECUTE st;
DEALLOCATE PREPARE st;
