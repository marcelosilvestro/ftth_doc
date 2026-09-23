<?php
/**
 * ftth_doc :: bootstrap do addon.
 *
 * Ordem obrigatoria (addon-mkauth-anatomia):
 *   config.php -> handlers AJAX (echo json; exit) -> nav/header.php -> ../../topo.php
 *
 * Este addon e AUTOSSUFICIENTE: nao inclui nada de outro addon (regra do Marcelo, 15/09/2026).
 * Do core do MK-AUTH vem apenas topo.php, baixo.php e scripts/jquery.js.
 */
include('addons.class.php');

if (function_exists('opcache_invalidate')) {
    opcache_invalidate(__FILE__, true);
}

// ---------------------------------------------------------------- sessao do painel
if (!file_exists(__DIR__ . '/../../login.hhvm')) {
    $ext_mk = '.php';
    session_name('mka');
    if (!isset($_SESSION)) session_start();
    if (!isset($_SESSION['mka_logado'])) {
        if (isset($_GET['ajax'])) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'ok'         => false,
                'data'       => null,
                'warnings'   => [],
                'errors'     => [['code' => 'FTTH-AUTH-001', 'message' => 'Sessão expirada.', 'details' => []]],
                'sessao_expirada' => true,
            ]);
            exit;
        }
        exit('Acesso negado. <a href="/admin/login.php">Fazer Login</a>');
    }
} else {
    $ext_mk = '.hhvm';
    // Sessao ja gerenciada pelo MK-AUTH em modo HHVM
}

// ---------------------------------------------------------------- credenciais fora do webroot
require_once __DIR__ . '/lib/Db.php';
require_once __DIR__ . '/lib/Credenciais.php';
require_once __DIR__ . '/lib/Erros.php';
require_once __DIR__ . '/lib/Resultado.php';
require_once __DIR__ . '/lib/Log.php';
require_once __DIR__ . '/lib/Auditoria.php';
require_once __DIR__ . '/lib/Versao.php';
require_once __DIR__ . '/lib/Geo.php';
require_once __DIR__ . '/lib/Config.php';

// O instalador grava conf/ftth_doc.php; o servidor do autor tem conf/secrets.php com varios
// blocos. A cadeia esta em lib/Credenciais.php e vale igual para a web e para a CLI.
$FTTH_DB = Credenciais::descobrir();
if ($FTTH_DB === null) {
    if (isset($_GET['ajax'])) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'data' => null, 'warnings' => [],
            'errors' => [['code' => 'FTTH-SYS-001', 'message' => 'Erro de configuração: banco não configurado.', 'details' => []]]]);
        exit;
    }
    exit(htmlspecialchars(Credenciais::comoResolver()));
}

// mysqli exigido pelo topo.php do MK-AUTH
$link = mysqli_connect($FTTH_DB['host'], $FTTH_DB['user'], $FTTH_DB['pass'], $FTTH_DB['name']);
if (!$link) {
    exit('Falha na conexao com o banco de dados MySQL.');
}

try {
    $pdo = Db::conectar($FTTH_DB);
} catch (PDOException $e) {
    Log::excecao('config.conectar', $e);
    if (isset($_GET['ajax'])) {
        Resultado::erro('FTTH-SYS-001')->enviar(500);
    }
    exit('Erro de conexao com o banco de dados.');
}

$usuario_logado = $_SESSION['MKA_Usuario'] ?? $_SESSION['MM_Usuario'] ?? 'sistema';

/*
 * ONDE O ADDON PODE GRAVAR — descoberto na VM em 15/09/2026.
 *
 * O PHP do painel roda sob perfil AppArmor (/etc/apparmor.d/sistema.php-*). A pasta de
 * addons e SOMENTE LEITURA para ele (`/opt/mk-auth/** r`), entao gravar em
 * ftth_doc/uploads ou ftth_doc/logs e negado pelo kernel, mesmo com dono e permissao certos
 * (is_writable() responde true e a escrita falha assim mesmo).
 *
 * Os caminhos liberados para escrita no perfil sao, entre outros:
 *   /opt/mk-auth/dados/**   /opt/mk-auth/log/**   /opt/mk-auth/cache/**
 *   /opt/mk-auth/admin/arquivos/**   /tmp/**   /var/tmp/**
 *
 * Usamos `dados` (fora do webroot, nao executavel) e `log`. Isso vale igual em producao.
 */
if (!defined('FTTH_DIR_DADOS')) {
    define('FTTH_DIR_DADOS', '/opt/mk-auth/dados/ftth_doc');
}
// Endereco do instalador de linha de comando, citado na tela quando o banco esta incompleto.
if (!defined('FTTH_URL_INSTALADOR')) {
    define('FTTH_URL_INSTALADOR', 'https://raw.githubusercontent.com/marcelosilvestro/ftth_doc/main/instalar.sh');
}
if (!defined('FTTH_DIR_LOGS')) {
    define('FTTH_DIR_LOGS', '/opt/mk-auth/log/ftth_doc');
}

/** Pasta de gravação do addon, criando se preciso. Devolve null quando não dá para gravar. */
function ftth_dir(string $sub = ''): ?string
{
    $base = FTTH_DIR_DADOS . ($sub !== '' ? '/' . trim($sub, '/') : '');
    if (!is_dir($base) && !@mkdir($base, 0770, true)) {
        return null;
    }
    return is_writable($base) ? $base : null;
}

Log::configurar(FTTH_DIR_LOGS, $usuario_logado);
Auditoria::configurar($usuario_logado);

// ---------------------------------------------------------------- CSRF
if (empty($_SESSION['ftth_csrf'])) {
    $_SESSION['ftth_csrf'] = bin2hex(random_bytes(16));
}
function ftth_csrf_token(): string
{
    return $_SESSION['ftth_csrf'];
}

/** Toda escrita via AJAX passa por aqui antes de qualquer coisa (3b.11). */
function ftth_exigir_csrf(): void
{
    $enviado = $_POST['csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!hash_equals($_SESSION['ftth_csrf'] ?? '', (string) $enviado)) {
        Resultado::erro('FTTH-AUTH-003')->enviar(403);
    }
}
