# Histórico — não aplicar

Estes 17 arquivos foram as migrations do `ftth_doc` até 23/09/2026. Eles **não são mais
aplicados por nada**: o schema do addon vive em `sql/baseline.sql`, que roda inteiro a cada
instalação e a cada atualização.

Por que a troca: uma migration numerada roda uma única vez e nunca mais — o `Migrator` pulava
todo arquivo já registrado, mesmo alterado. Com o addon indo para servidores de terceiros e
ganhando versões novas, era preciso um schema que pudesse ser reaplicado sem medo, e é isso que
o baseline é (`CREATE TABLE IF NOT EXISTS`, `INSERT ... ON DUPLICATE KEY`, `ALTER` condicionado
por `information_schema`).

Esta pasta continua aqui por dois motivos:

1. **Teste de equivalência** — `tests/comparar_schema.php` monta um banco com estes 17 e outro
   com o baseline, e compara tabela por tabela, coluna por coluna, índice por índice. É o que
   prova que a consolidação não perdeu nada.
2. **Leitura do passado** — cada arquivo explica, no cabeçalho, a decisão que o motivou.

A pasta **não entra no pacote distribuído** (`empacotar.sh` a exclui). Se você chegou aqui
procurando como criar as tabelas, o arquivo certo é `sql/baseline.sql`.

Servidores que aplicaram estes 17 têm as linhas correspondentes em `tab_ftth_migration`. Elas
ficam lá como registro: o baseline grava a sua própria linha e ignora as antigas.
