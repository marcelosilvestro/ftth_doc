#!/usr/bin/env bash
#
# ftth_doc :: instalador do addon de documentacao de rede optica para o MK-AUTH.
#
#   wget -O - https://raw.githubusercontent.com/marcelosilvestro/ftth_doc/main/instalar.sh | bash
#
# O mesmo comando instala, atualiza e repara. Rode quantas vezes quiser: quando ja esta na
# ultima versao, ele so confere permissoes, menu e diagnostico.
#
# Opcoes (depois de "| bash -s --"):
#   --versao=v0.9.0   instala uma versao especifica em vez da ultima publicada
#   --forcar          reinstala mesmo estando atualizado
#   --limpar          derruba as tabelas aposentadas (pede confirmacao se tiverem dados)
#   --nao-interativo  nunca pergunta nada; falta de credencial vira erro
#   --sem-backup      pula o backup (nao recomendado)
#   --diagnostico     so diagnostica a instalacao existente, sem mexer em nada
#   --ajuda
#
# Variaveis de ambiente uteis:
#   FTTH_DB_USER / FTTH_DB_PASS   credenciais do MySQL, para instalacao desassistida
#   FTTH_REPO                     outro repositorio (padrao: marcelosilvestro/ftth_doc)
#   GITHUB_TOKEN                  evita o limite de requisicoes da API do GitHub
#
# Este arquivo so age na ultima linha (main "$@"): se o download for cortado no meio, o
# pedaco que chegou define funcoes e termina sem executar nada.

set -euo pipefail
umask 077
export LC_ALL=C.UTF-8 2>/dev/null || true

# ------------------------------------------------------------------ constantes
ADDON=ftth_doc
REPO="${FTTH_REPO:-marcelosilvestro/ftth_doc}"
MKAUTH=/opt/mk-auth
DEST="$MKAUTH/admin/addons/$ADDON"
CONF="$MKAUTH/conf/$ADDON.php"
CONF_MKA="$MKAUTH/conf/secrets.php"
DADOS="$MKAUTH/dados/$ADDON"
LOGDIR="$MKAUTH/log/$ADDON"
BCKP="$MKAUTH/bckp/$ADDON"
ADDONJS="$MKAUTH/admin/addons/addon.js"
CLASSE_ORIGEM="$MKAUTH/include/addons.inc.hhvm"

# O acento vai como escape çã de proposito: o Apache serve o addon.js como
# application/javascript SEM charset, e "Documentação" cru chega ao menu como "DocumentaÃ§Ã£o".
MENU_MARCA="addons/$ADDON/"
MENU_LINHA="add_menu.provedor('{\"plink\": \"' + minha_url + 'addons/$ADDON/mapa.php\", \"ptext\": \"Documenta\\u00e7\\u00e3o FTTH\"}');"

# Senha de fabrica do MK-AUTH. Esta aqui para a instalacao rodar sozinha num servidor virgem;
# e a primeira que qualquer um tentaria de qualquer forma. Se voce trocou (e deveria),
# use FTTH_DB_PASS=... ou responda a pergunta no fim da cadeia.
DB_PADRAO_USER=root
DB_PADRAO_PASS=vertrigo
DB_NOME=mkradius

TMP=""
LOG=""
MODO=""
VERSAO_INSTALADA=""
VERSAO_ALVO=""
ACAO=""
DB_USER=""
DB_PASS=""
DB_HOST=127.0.0.1
DUMP=""
declare -a DESFAZER=()

# argumentos
OPT_VERSAO=""
OPT_FORCAR=0
OPT_LIMPAR=0
OPT_INTERATIVO=1
OPT_BACKUP=1
OPT_SO_DIAGNOSTICO=0

# ------------------------------------------------------------------ saida
cor() { if [ -t 1 ]; then printf '\033[%sm%s\033[0m' "$1" "$2"; else printf '%s' "$2"; fi; }
registrar() { [ -n "$LOG" ] && printf '%s %s\n' "$(date '+%F %T')" "$1" >> "$LOG" || true; }
passo()  { echo; echo "$(cor '1;34' "==>") $(cor 1 "$1")"; registrar "==> $1"; }
ok()     { echo "  $(cor 32 'ok  ') $1"; registrar "ok   $1"; }
aviso()  { echo "  $(cor 33 '!!  ') $1"; registrar "!!   $1"; }
info()   { echo "  $(cor 90 '..  ') $1"; registrar "..   $1"; }
erro()   { echo "  $(cor 31 'XX  ') $1" >&2; registrar "XX   $1"; }
morrer() { erro "$1"; exit "${2:-1}"; }

desfazer_push() { DESFAZER+=("$1"); }

reverter() {
    [ ${#DESFAZER[@]} -eq 0 ] && return 0
    aviso "desfazendo o que dava para desfazer..."
    for (( i=${#DESFAZER[@]}-1; i>=0; i-- )); do
        eval "${DESFAZER[i]}" >/dev/null 2>&1 || true
    done
}

ao_sair() {
    local rc=$?
    if [ $rc -ne 0 ]; then
        erro "a instalacao falhou (codigo $rc)"
        reverter
        [ -n "$DUMP" ] && echo "  backup do banco: $DUMP" >&2
        [ -n "$LOG" ] && echo "  log: $LOG" >&2
    fi
    [ -n "$TMP" ] && rm -rf "$TMP" || true
    exit $rc
}

# ------------------------------------------------------------------ pre-requisitos
exigir_root() {
    [ "$(id -u)" -eq 0 ] || morrer "rode como root (sudo)."
}

ler_argumentos() {
    for a in "$@"; do
        case "$a" in
            --versao=*)      OPT_VERSAO="${a#*=}" ;;
            --forcar)        OPT_FORCAR=1 ;;
            --limpar)        OPT_LIMPAR=1 ;;
            --nao-interativo) OPT_INTERATIVO=0 ;;
            --sem-backup)    OPT_BACKUP=0 ;;
            --diagnostico)   OPT_SO_DIAGNOSTICO=1 ;;
            --db-user=*)     FTTH_DB_USER="${a#*=}" ;;
            --db-pass=*)     FTTH_DB_PASS="${a#*=}" ;;
            --ajuda|-h)      sed -n '2,26p' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
            *)               morrer "opcao desconhecida: $a (use --ajuda)" ;;
        esac
    done
}

detectar_mkauth() {
    [ -d "$MKAUTH/admin/addons" ] || morrer "isto nao parece um servidor MK-AUTH ($MKAUTH/admin/addons nao existe)."
    if [ -f "$MKAUTH/admin/login.hhvm" ]; then MODO=hhvm; else MODO=php; fi
    ok "MK-AUTH encontrado (painel em modo $MODO)"
}

checar_prerequisitos() {
    command -v php >/dev/null || morrer "PHP da linha de comando nao encontrado."
    php -r 'exit(PHP_VERSION_ID < 80000 ? 1 : 0);' \
        || morrer "PHP $(php -r 'echo PHP_VERSION;') e antigo demais; o addon precisa de 8.0+."

    local faltando=()
    for ext in pdo_mysql zip dom simplexml mbstring; do
        php -m | grep -qi "^$ext$" || faltando+=("$ext")
    done
    [ ${#faltando[@]} -eq 0 ] || morrer "faltam extensoes do PHP: ${faltando[*]} (apt install php-${faltando[0]} ...)"

    for cmd in tar gzip mysql mysqldump install; do
        command -v "$cmd" >/dev/null || morrer "comando ausente: $cmd"
    done
    command -v wget >/dev/null || command -v curl >/dev/null || morrer "preciso de wget ou curl."
    ok "PHP $(php -r 'echo PHP_VERSION;') com as extensoes necessarias"
}

preparar_log() {
    mkdir -p "$LOGDIR" 2>/dev/null || true
    LOG="$LOGDIR/instalacao.log"
    touch "$LOG" 2>/dev/null || LOG=""
    [ -n "$LOG" ] && chmod 640 "$LOG" 2>/dev/null || true
    registrar "----- $(date '+%F %T') instalar.sh $* -----"
    TMP="$(mktemp -d /tmp/ftth_doc.XXXXXX)"
}

# ------------------------------------------------------------------ versoes
baixar_para() {
    # $1 url, $2 destino
    if command -v wget >/dev/null; then
        wget -q --timeout=30 --tries=2 -O "$2" "$1"
    else
        curl -fsSL --max-time 60 -o "$2" "$1"
    fi
}

api_github() {
    # $1 caminho da API -> stdout com o JSON, ou vazio
    local url="https://api.github.com/$1" cab=()
    [ -n "${GITHUB_TOKEN:-}" ] && cab=(--header "Authorization: Bearer $GITHUB_TOKEN")
    if command -v curl >/dev/null; then
        curl -fsSL --max-time 30 "${cab[@]/--header/-H}" "$url" 2>/dev/null || true
    else
        wget -q --timeout=30 -O - "${cab[@]}" "$url" 2>/dev/null || true
    fi
}

versao_instalada() {
    [ -f "$DEST/manifest.json" ] || { echo ""; return; }
    php -r '$m = @json_decode(@file_get_contents($argv[1]), true); echo $m["version"] ?? "";' "$DEST/manifest.json" 2>/dev/null || echo ""
}

versao_publicada() {
    local json
    json="$(api_github "repos/$REPO/releases/latest")"
    [ -z "$json" ] && { echo ""; return; }
    printf '%s' "$json" | php -r '$j = json_decode(stream_get_contents(STDIN), true); echo $j["tag_name"] ?? "";' 2>/dev/null || echo ""
}

# Compara duas versoes: 0 quando iguais, 1 quando $1 > $2, 2 quando $1 < $2.
comparar_versoes() {
    local a="${1#v}" b="${2#v}"
    [ "$a" = "$b" ] && return 0
    local maior
    maior="$(printf '%s\n%s\n' "$a" "$b" | sort -V | tail -1)"
    [ "$maior" = "$a" ] && return 1 || return 2
}

decidir_acao() {
    VERSAO_INSTALADA="$(versao_instalada)"
    if [ -n "$OPT_VERSAO" ]; then
        VERSAO_ALVO="$OPT_VERSAO"
    else
        VERSAO_ALVO="$(versao_publicada)"
    fi

    if [ -z "$VERSAO_ALVO" ]; then
        if [ -n "$VERSAO_INSTALADA" ]; then
            aviso "nao consegui falar com o GitHub; sigo com o que esta instalado ($VERSAO_INSTALADA)"
            ACAO=reparar
            return
        fi
        aviso "nao consegui ler a ultima versao no GitHub; vou usar o codigo do branch main"
        VERSAO_ALVO="main"
    fi

    if [ -z "$VERSAO_INSTALADA" ]; then
        ACAO=instalar
        ok "nada instalado ainda; versao a instalar: $VERSAO_ALVO"
        return
    fi

    if [ "$OPT_FORCAR" -eq 1 ]; then
        ACAO=instalar
        ok "reinstalando $VERSAO_ALVO por cima de $VERSAO_INSTALADA (--forcar)"
        return
    fi

    set +e
    comparar_versoes "$VERSAO_ALVO" "$VERSAO_INSTALADA"
    local cmp=$?
    set -e
    case $cmp in
        1) ACAO=atualizar; ok "atualizando de $VERSAO_INSTALADA para $VERSAO_ALVO" ;;
        0) ACAO=reparar;   ok "ja esta na versao $VERSAO_INSTALADA; vou so conferir a instalacao" ;;
        2) ACAO=reparar;   aviso "a versao instalada ($VERSAO_INSTALADA) e mais nova que a publicada ($VERSAO_ALVO); nao vou trocar" ;;
    esac
}

# ------------------------------------------------------------------ pacote
baixar_pacote() {
    local staging="$TMP/staging" arquivo="$TMP/pacote.tar.gz" url

    if [ "$VERSAO_ALVO" = "main" ]; then
        url="https://github.com/$REPO/archive/refs/heads/main.tar.gz"
    else
        url="https://github.com/$REPO/releases/download/$VERSAO_ALVO/${ADDON}-${VERSAO_ALVO#v}.tar.gz"
    fi

    info "baixando $url"
    if ! baixar_para "$url" "$arquivo"; then
        if [ "$VERSAO_ALVO" != "main" ]; then
            aviso "release sem pacote publicado; caindo para o codigo do branch main"
            VERSAO_ALVO=main
            baixar_para "https://github.com/$REPO/archive/refs/heads/main.tar.gz" "$arquivo" \
                || morrer "falha ao baixar o addon. Sem internet? Tente --versao= com um pacote local."
        else
            morrer "falha ao baixar o addon de $url"
        fi
    fi

    mkdir -p "$staging"
    tar xzf "$arquivo" -C "$staging" || morrer "pacote corrompido"

    # O asset da release extrai como ftth_doc/; o tarball do branch, como ftth_doc-main/.
    local raiz
    raiz="$(find "$staging" -maxdepth 2 -name manifest.json -printf '%h\n' | head -1)"
    [ -n "$raiz" ] || morrer "manifest.json nao encontrado dentro do pacote"

    # Cinto e suspensorio: mesmo que o empacotador falhe, o servidor do cliente nao recebe a
    # suite de testes (ela apaga e recria um banco inteiro) nem as migrations antigas.
    rm -rf "$raiz/tests" "$raiz/sql/historico" "$raiz/.github" "$raiz/empacotar.sh"

    for obrigatorio in manifest.json config.php mapa.php index.php lib/Schema.php \
                       sql/baseline.sql cli/schema.php cli/diagnostico.php; do
        [ -e "$raiz/$obrigatorio" ] || morrer "pacote incompleto: falta $obrigatorio"
    done

    echo "$raiz" > "$TMP/raiz"
    ok "pacote $VERSAO_ALVO baixado e conferido"
}

# ------------------------------------------------------------------ banco
# Testa um par usuario/senha de verdade antes de aceitar. Nada e impresso nem registrado.
testar_credencial() {
    php -r '
        try {
            $p = new PDO("mysql:host=".$argv[1].";dbname=".$argv[4], $argv[2], $argv[3],
                         [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $p->query("SELECT 1");
            exit(0);
        } catch (Throwable $e) { exit(1); }
    ' "$DB_HOST" "$1" "$2" "$DB_NOME" 2>/dev/null
}

ler_do_arquivo_php() {
    # $1 arquivo, $2 chave -> valor do bloco db
    php -r '
        $c = @include $argv[1];
        echo (is_array($c) && isset($c["db"][$argv[2]])) ? $c["db"][$argv[2]] : "";
    ' "$1" "$2" 2>/dev/null || true
}

resolver_banco() {
    local u p

    # 1. ambiente/argumento — quem sabe a senha manda
    if [ -n "${FTTH_DB_PASS:-}" ]; then
        u="${FTTH_DB_USER:-$DB_PADRAO_USER}"
        if testar_credencial "$u" "$FTTH_DB_PASS"; then
            DB_USER="$u"; DB_PASS="$FTTH_DB_PASS"
            ok "credenciais do banco vindas do ambiente"; return
        fi
        aviso "as credenciais passadas nao funcionaram; vou procurar outras"
    fi

    # 2. instalacao anterior deste addon
    if [ -f "$CONF" ]; then
        u="$(ler_do_arquivo_php "$CONF" user)"; p="$(ler_do_arquivo_php "$CONF" pass)"
        if [ -n "$u" ] && testar_credencial "$u" "$p"; then
            DB_USER="$u"; DB_PASS="$p"
            ok "credenciais do banco vindas de $CONF"; return
        fi
    fi

    # 3. secrets.php, quando o servidor ja tem (lido, nunca escrito)
    if [ -f "$CONF_MKA" ]; then
        u="$(ler_do_arquivo_php "$CONF_MKA" user)"; p="$(ler_do_arquivo_php "$CONF_MKA" pass)"
        if [ -n "$u" ] && testar_credencial "$u" "$p"; then
            DB_USER="$u"; DB_PASS="$p"
            ok "credenciais do banco vindas de $CONF_MKA"; return
        fi
    fi

    # 4. padrao de fabrica do MK-AUTH
    if testar_credencial "$DB_PADRAO_USER" "$DB_PADRAO_PASS"; then
        DB_USER="$DB_PADRAO_USER"; DB_PASS="$DB_PADRAO_PASS"
        ok "credenciais padrao do MK-AUTH"
        aviso "o MySQL ainda usa a senha de fabrica — vale trocar depois do beta"
        return
    fi

    # 5. perguntar ao operador. Em "wget | bash" o stdin E o script: a leitura precisa vir de
    # /dev/tty por um descritor proprio, senao o read comeria o resto do proprio codigo.
    if [ "$OPT_INTERATIVO" -eq 1 ] && [ -e /dev/tty ]; then
        exec 3</dev/tty || true
        local tentativa=0
        while [ $tentativa -lt 3 ]; do
            tentativa=$((tentativa + 1))
            printf '  usuario do MySQL [%s]: ' "$DB_PADRAO_USER" > /dev/tty
            read -r u <&3 || break
            [ -z "$u" ] && u="$DB_PADRAO_USER"
            printf '  senha do MySQL: ' > /dev/tty
            read -rs p <&3 || break
            echo > /dev/tty
            if testar_credencial "$u" "$p"; then
                DB_USER="$u"; DB_PASS="$p"
                ok "credenciais confirmadas"; return
            fi
            aviso "nao consegui conectar com esse usuario/senha"
        done
    fi

    morrer "nao consegui acessar o banco $DB_NOME. Rode de novo com FTTH_DB_PASS=... ou --db-pass=" 3
}

escrever_conf() {
    mkdir -p "$MKAUTH/conf"
    chmod 750 "$MKAUTH/conf" 2>/dev/null || true
    chown root:www-data "$MKAUTH/conf" 2>/dev/null || true

    if [ -f "$CONF" ]; then
        cp -p "$CONF" "$CONF.bak"
        desfazer_push "mv '$CONF.bak' '$CONF'"
    else
        desfazer_push "rm -f '$CONF'"
    fi

    local tmpconf="$TMP/conf.php"
    {
        echo "<?php"
        echo "/**"
        echo " * ftth_doc :: acesso ao banco, escrito pelo instalador em $(date '+%d/%m/%Y %H:%M')."
        echo " *"
        echo " * Fica fora do webroot de proposito: o Apache do MK-AUTH nao serve /opt/mk-auth/conf."
        echo " * O addon le este arquivo primeiro e, se ele nao existir, cai para conf/secrets.php."
        echo " * Para trocar a senha do MySQL, edite aqui e nada mais precisa mudar."
        echo " */"
        echo "return ["
        echo "    'db' => ["
        printf "        'host' => %s,\n" "$(php -r 'echo var_export($argv[1], true);' "$DB_HOST")"
        echo "        'port' => 3306,"
        printf "        'name' => %s,\n" "$(php -r 'echo var_export($argv[1], true);' "$DB_NOME")"
        printf "        'user' => %s,\n" "$(php -r 'echo var_export($argv[1], true);' "$DB_USER")"
        printf "        'pass' => %s,\n" "$(php -r 'echo var_export($argv[1], true);' "$DB_PASS")"
        echo "    ],"
        echo "];"
    } > "$tmpconf"

    install -o root -g www-data -m 640 "$tmpconf" "$CONF"
    ok "configuracao do banco em $CONF (640 root:www-data)"
}

arquivo_defaults() {
    # arquivo temporario 600 para o mysql/mysqldump: senha em argv aparece no ps de todo mundo
    local f="$TMP/my.cnf"
    { echo "[client]"; echo "user=$DB_USER"; echo "password=$DB_PASS"; echo "host=$DB_HOST"; } > "$f"
    chmod 600 "$f"
    echo "$f"
}

backup_banco() {
    [ "$OPT_BACKUP" -eq 1 ] || { info "backup do banco pulado (--sem-backup)"; return; }

    local cnf tabelas
    cnf="$(arquivo_defaults)"
    tabelas="$(mysql --defaults-extra-file="$cnf" -N -B -e \
        "SELECT table_name FROM information_schema.tables
          WHERE table_schema = '$DB_NOME' AND table_name LIKE 'tab_ftth_%'" 2>/dev/null || true)"

    if [ -z "$tabelas" ]; then
        info "nenhuma tabela do addon ainda; nada para salvar"
        return
    fi

    mkdir -p "$BCKP"
    chmod 700 "$BCKP"
    DUMP="$BCKP/tab_ftth-$(date +%Y%m%d-%H%M%S).sql.gz"
    # A lista de tabelas vira um array: sem isso o shell teria de dividir a string por
    # espacos, que e justamente o tipo de coisa que quebra com um nome inesperado.
    local -a lista=()
    while IFS= read -r tabela; do
        [ -n "$tabela" ] && lista+=("$tabela")
    done <<< "$tabelas"
    mysqldump --defaults-extra-file="$cnf" --single-transaction --quick \
              "$DB_NOME" "${lista[@]}" 2>/dev/null | gzip -9 > "$DUMP"
    chmod 600 "$DUMP"
    ok "backup do banco em $DUMP ($(du -h "$DUMP" | cut -f1))"

    # guarda os 5 mais recentes
    ls -1t "$BCKP"/tab_ftth-*.sql.gz 2>/dev/null | tail -n +6 | xargs -r rm -f
}

backup_codigo() {
    [ -d "$DEST" ] || return 0
    [ "$OPT_BACKUP" -eq 1 ] || return 0
    mkdir -p "$BCKP"
    local alvo
    alvo="$BCKP/arquivos-$(date +%Y%m%d-%H%M%S).tgz"
    tar czf "$alvo" -C "$(dirname "$DEST")" "$ADDON" 2>/dev/null || true
    ok "backup dos arquivos em $alvo"
    ls -1t "$BCKP"/arquivos-*.tgz 2>/dev/null | tail -n +4 | xargs -r rm -f
}

# ------------------------------------------------------------------ publicacao
publicar() {
    local raiz antigo
    raiz="$(cat "$TMP/raiz")"
    antigo="$DEST.old.$(date +%Y%m%d%H%M%S)"

    if [ -d "$DEST" ]; then
        # Instalacoes antigas podiam ter conteudo nestas pastas; o que houver vai junto.
        for guardar in uploads logs; do
            if [ -d "$DEST/$guardar" ] && [ -n "$(ls -A "$DEST/$guardar" 2>/dev/null)" ]; then
                cp -a "$DEST/$guardar" "$raiz/" 2>/dev/null || true
            fi
        done
        mv "$DEST" "$antigo"
        desfazer_push "rm -rf '$DEST'; mv '$antigo' '$DEST'"
    fi

    mv "$raiz" "$DEST"
    ok "arquivos publicados em $DEST"
    echo "$antigo" > "$TMP/antigo"
}

instalar_classe() {
    # addons.class.php e do MK-AUTH, nao do addon: copiamos o do proprio servidor em vez de
    # redistribuir um binario de terceiro.
    if [ -f "$CLASSE_ORIGEM" ]; then
        cp -f "$CLASSE_ORIGEM" "$DEST/addons.class.php"
        ok "addons.class.php copiado do proprio MK-AUTH ($(stat -c%s "$DEST/addons.class.php") bytes)"
    elif [ -f "$DEST/addons.class.php" ]; then
        aviso "$CLASSE_ORIGEM nao existe; mantendo o addons.class.php que ja estava aqui"
    else
        morrer "nao achei $CLASSE_ORIGEM nem um addons.class.php anterior: sem ele o addon nao autentica."
    fi
}

criar_diretorios() {
    install -d -o www-data -g www-data -m 750 "$DADOS" "$DADOS/uploads" "$LOGDIR"
    if ! sudo -u www-data test -w "$DADOS" 2>/dev/null; then
        aviso "$DADOS existe mas o PHP pode nao conseguir escrever (veja o AppArmor)"
    else
        ok "pastas de dados e log prontas"
    fi
}

ajustar_permissoes() {
    chown -R www-data:www-data "$DEST"
    find "$DEST" -type d -exec chmod 755 {} +
    find "$DEST" -type f -exec chmod 644 {} +
    [ -f "$CONF" ] && chown root:www-data "$CONF" && chmod 640 "$CONF"
    ok "dono www-data:www-data, 755 nas pastas e 644 nos arquivos"
}

# ------------------------------------------------------------------ schema
aplicar_schema() {
    local saida rc=0
    saida="$(php "$DEST/cli/schema.php" aplicar --conf="$CONF" --json 2>&1)" || rc=$?
    registrar "schema aplicar -> $saida"

    if [ $rc -ne 0 ]; then
        erro "falha ao aplicar o schema (codigo $rc)"
        echo "$saida" | sed 's/^/      /' >&2
        if [ -n "$DUMP" ]; then
            echo "  para voltar o banco:  zcat $DUMP | mysql $DB_NOME" >&2
        fi
        exit 1
    fi

    local comandos tabelas
    comandos="$(printf '%s' "$saida" | php -r '$j=json_decode(stream_get_contents(STDIN),true); echo $j["comandos"] ?? "?";')"
    tabelas="$(printf '%s' "$saida" | php -r '$j=json_decode(stream_get_contents(STDIN),true); echo $j["tabelas"] ?? "?";')"
    ok "schema aplicado ($comandos comandos, $tabelas tabelas)"
}

limpar_schema() {
    [ "$OPT_LIMPAR" -eq 1 ] || return 0
    local saida rc=0
    saida="$(php "$DEST/cli/schema.php" limpar --conf="$CONF" --json 2>&1)" || rc=$?
    if [ $rc -eq 4 ]; then
        aviso "ha dados nas tabelas aposentadas; nao vou derrubar sem confirmacao"
        info "para derrubar mesmo assim: php $DEST/cli/schema.php limpar --forcar"
        return 0
    fi
    [ $rc -eq 0 ] || { erro "falha na limpeza do schema"; echo "$saida" | sed 's/^/      /' >&2; return 0; }
    ok "tabelas aposentadas removidas"
}

# ------------------------------------------------------------------ menu do painel
registrar_menu() {
    # addon.js e COMPARTILHADO por todos os addons: cada um tem a sua linha. A regra e tirar
    # so a minha e acrescentar a minha no fim; nada de outro addon pode ser tocado.
    [ -f "$ADDONJS" ] || : > "$ADDONJS"

    local antes_outros bak
    antes_outros="$(grep -c 'add_menu\.' "$ADDONJS" 2>/dev/null || true)"
    antes_outros="$(( antes_outros - $(grep -c "$MENU_MARCA" "$ADDONJS" 2>/dev/null || echo 0) ))"

    bak="$ADDONJS.bak-$(date +%Y%m%d%H%M%S)"
    cp -p "$ADDONJS" "$bak"
    desfazer_push "cp -p '$bak' '$ADDONJS'"

    # Sem newline final, o append gruda na ultima linha de outro addon.
    if [ -s "$ADDONJS" ] && [ "$(tail -c1 "$ADDONJS" | wc -l)" -eq 0 ]; then
        printf '\n' >> "$ADDONJS"
    fi

    grep -v "$MENU_MARCA" "$ADDONJS" > "$TMP/addon.js" || true
    printf '%s\n' "$MENU_LINHA" >> "$TMP/addon.js"

    chown --reference="$ADDONJS" "$TMP/addon.js" 2>/dev/null || true
    chmod --reference="$ADDONJS" "$TMP/addon.js" 2>/dev/null || chmod 644 "$TMP/addon.js"
    mv "$TMP/addon.js" "$ADDONJS"

    local minhas outros
    minhas="$(grep -c "$MENU_MARCA" "$ADDONJS" || true)"
    outros="$(( $(grep -c 'add_menu\.' "$ADDONJS" || true) - minhas ))"

    if [ "$minhas" -ne 1 ] || [ "$outros" -ne "$antes_outros" ]; then
        cp -p "$bak" "$ADDONJS"
        morrer "algo saiu errado ao editar o addon.js; restaurei o arquivo original ($bak)"
    fi
    ok "menu do painel atualizado (1 linha minha, $outros de outros addons preservadas)"

    ls -1t "$ADDONJS".bak-* 2>/dev/null | tail -n +6 | xargs -r rm -f
}

# ------------------------------------------------------------------ fim
diagnosticar() {
    echo
    php "$DEST/cli/diagnostico.php" --conf="$CONF" || true
}

resumo() {
    local url_painel
    url_painel="http://$(hostname -I 2>/dev/null | awk '{print $1}')/admin/addons/$ADDON/mapa.php"

    echo
    echo "$(cor '1;32' '========================================================')"
    echo "$(cor 1 " FTTH Doc $VERSAO_ALVO instalado")"
    echo "$(cor '1;32' '========================================================')"
    echo
    echo " No painel:  menu PROVEDOR > Documentacao FTTH"
    echo " Direto:     $url_painel"
    echo
    echo " O que falta voce fazer:"
    echo "   1. cadastrar a chave do Google Maps em Configuracoes (o mapa nao abre sem ela)"
    echo "   2. criar a primeira regiao e importar o KMZ da sua rede"
    [ -n "$DUMP" ] && echo "   3. guardar o backup do banco: $DUMP"
    echo
    echo " Atualizar no futuro: rode este mesmo comando de novo."
    [ -n "$LOG" ] && echo " Log desta instalacao: $LOG"
    echo
}

# ------------------------------------------------------------------ main
main() {
    trap ao_sair EXIT
    ler_argumentos "$@"
    exigir_root
    preparar_log "$@"

    echo "$(cor 1 "FTTH Doc") :: instalador ($REPO)"
    detectar_mkauth
    checar_prerequisitos

    if [ "$OPT_SO_DIAGNOSTICO" -eq 1 ]; then
        [ -d "$DEST" ] || morrer "o addon nao esta instalado em $DEST"
        diagnosticar
        exit 0
    fi

    passo "Versao"
    decidir_acao

    passo "Banco de dados"
    resolver_banco
    escrever_conf

    if [ "$ACAO" != "reparar" ]; then
        passo "Pacote"
        baixar_pacote

        passo "Backup"
        backup_codigo
        backup_banco

        passo "Instalacao"
        publicar
    else
        passo "Backup"
        backup_banco
    fi

    instalar_classe
    criar_diretorios
    ajustar_permissoes

    passo "Schema do banco"
    aplicar_schema
    limpar_schema

    passo "Menu do painel"
    registrar_menu

    passo "Diagnostico"
    diagnosticar

    # A partir daqui nao ha mais o que desfazer: a instalacao esta de pe.
    DESFAZER=()
    if [ -f "$TMP/antigo" ]; then
        rm -rf "$(cat "$TMP/antigo")" 2>/dev/null || true
    fi

    resumo
}

main "$@"
