# FTTH Doc — documentação da rede óptica dentro do MK-AUTH

Addon para MK-AUTH que documenta a planta FTTH: caixas e cabos no mapa, importação do KMZ do
projeto, diagrama de emendas de cada CEO/CTO, POP com OLT e DIO, e cálculo de potência do sinal
que chega em cada cliente.

> **Versão 0.9.3 — beta.** Está rodando em produção no provedor que o desenvolve, mas é a
> primeira versão publicada. Instale primeiro num servidor de teste.

## Instalação

No terminal do servidor MK-AUTH, como root:

```bash
wget -O - https://raw.githubusercontent.com/marcelosilvestro/ftth_doc/main/instalar.sh | bash
```

O script faz tudo: confere o ambiente, baixa a última versão, cria as tabelas, ajusta
permissões e coloca o link no menu do painel. O mesmo comando também **atualiza** — rode de
novo quando sair uma versão nova, que ele compara e só troca o que precisa.

Depois de instalar, entre no painel: **PROVEDOR → Documentação FTTH**.

### O que você precisa ter

- MK-AUTH instalado (o script confere `/opt/mk-auth`)
- PHP 8.0 ou superior com `pdo_mysql`, `zip`, `dom`, `simplexml` e `mbstring`
- Uma **chave do Google Maps** (Maps JavaScript API). O mapa não abre sem ela — cadastre em
  *Configurações*, dentro do addon, e restrinja a chave ao domínio do seu painel no console do
  Google.

### Se o seu MySQL não usa a senha padrão

O instalador descobre sozinho na maioria dos servidores. Se não conseguir, ele pergunta — ou
você já entrega:

```bash
wget -O - https://raw.githubusercontent.com/marcelosilvestro/ftth_doc/main/instalar.sh \
  | FTTH_DB_PASS='sua-senha' bash
```

### Outras opções

```bash
# só conferir uma instalação existente, sem mexer em nada
wget -O - .../instalar.sh | bash -s -- --diagnostico

# instalar uma versão específica
wget -O - .../instalar.sh | bash -s -- --versao=v0.9.3

# remover as tabelas que o addon aposentou
wget -O - .../instalar.sh | bash -s -- --limpar
```

## Emendar uma caixa num cabo já lançado

O cabo quase sempre é lançado antes de as caixas serem documentadas, e num rompimento entram
duas caixas de emenda no meio de um lance existente. Para isso não é preciso apagar e
redesenhar o cabo: **solte a caixa em cima dele**.

- No modo **Caixa**, clique sobre o traçado: o addon pergunta se você quer emendar antes de
  abrir o cadastro da caixa.
- No modo **Mover**, arraste uma caixa existente para cima do cabo: a pergunta vem depois do
  Concluir.

Confirmando, o cabo é cortado em dois trechos que passam a chegar na caixa, ela encosta no
traçado e todas as fibras atravessam como **passagem** (perda zero) — o sinal dos clientes
continua batendo no mesmo instante. No diagrama da caixa você troca por fusão ou sangria o que
precisar; ele já abre com o cabo que vem do POP à esquerda e o que segue para a rua à direita.

O caminho de volta existe: ao **excluir** uma caixa que só faz essa emenda, o addon avisa que
vai juntar os dois trechos num lance só e faz isso — preservando o que já estava fundido nas
pontas. Caixa com splitter, DIO ou fusão cruzada não entra nessa conta, porque aí ela tem
função de verdade.

A distância em que o mapa considera que a caixa caiu "em cima" do cabo é ajustável em
*Configurações → Raio de emenda*, e começa em 10 m.

## Como começar a usar

1. **Configurações** → cadastre a chave do Google Maps.
2. **Regiões** → crie a primeira região (uma cidade ou bairro), com as coordenadas do centro.
3. **Importar KMZ** → envie o as-built do seu projeto. Nada entra na rede direto: tudo fica em
   *quarentena* para você conferir item por item antes de aprovar.
4. No mapa, abra uma caixa e clique em **Diagrama** para montar as emendas e os splitters.
5. **POP / Data Center** → cadastre a OLT e o DIO; a partir daí o addon calcula o sinal
   estimado em cada porta de cliente.

## O que o addon escreve no seu banco

Só tabelas próprias, todas com o prefixo `tab_ftth_`. As tabelas nativas do MK-AUTH são lidas,
nunca alteradas — com **duas exceções**, que vêm **desligadas** e só ligam por SQL:

| Chave em `tab_ftth_config` | O que passa a fazer quando ligada |
|---|---|
| `sync_sis_cliente` | grava `caixa_herm` e `porta_splitter` em `sis_cliente` |
| `sync_cto_nativa` | espelha as CTOs documentadas na tabela nativa `cto` |

### Convive com o HelpFiber, mas não depende dele

Se o servidor tiver o addon **HelpFiber** instalado, o FTTH Doc oferece vincular cada OLT ao
cadastro que já existe lá, e pode espelhar as CTOs documentadas na tabela `cto` (desligado por
padrão). Sem o HelpFiber, nada disso aparece e você cadastra as OLTs no próprio addon, na tela
POP / Data Center.

Toda alteração feita pelo addon fica registrada em `tab_ftth_historico`: quem, quando, o que
era antes e o que virou.

## Segurança

O MK-AUTH sai de fábrica com uma senha conhecida no MySQL. Se o seu servidor ainda usa essa
senha, **troque** — o diagnóstico do addon avisa quando esse é o caso. Depois de trocar, ajuste
`/opt/mk-auth/conf/ftth_doc.php`, que é onde o addon guarda o acesso ao banco (640, root:www-data,
fora do diretório servido pelo Apache).

## Suporte e problemas

Abra uma *issue* neste repositório com a saída de:

```bash
php /opt/mk-auth/admin/addons/ftth_doc/cli/diagnostico.php
```

Ela mostra versão, estado do banco, permissões e o que está faltando — sem expor senha nenhuma.

## Para desenvolvedores

```bash
php tests/run.php --user=root --pass=SENHA --db=mkradius_ftth_test   # suíte completa
php tests/comparar_schema.php --user=root --pass=SENHA               # schema x migrations antigas
./empacotar.sh                                                       # gera pacote/*.tar.gz
```

A suíte **apaga e recria** o banco que você indicar, por isso exige `test` no nome e recusa
rodar de dentro de `/opt/mk-auth`. O arquivo KMZ usado pelas suítes 04 e 05 não está no
repositório (é a planta real de um provedor); sem ele essas duas suítes são puladas.

O schema vive em [`sql/baseline.sql`](sql/baseline.sql), aplicado inteiro a cada instalação e a
cada atualização — ele é idempotente. As 17 migrations anteriores ficam em
[`sql/historico/`](sql/historico/) apenas como registro e para o teste de equivalência.
