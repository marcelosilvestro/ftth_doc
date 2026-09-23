<?php
/**
 * ftth_doc :: tela de configuracoes e estado do addon.
 *
 * A tela principal do addon e o mapa (mapa.php); daqui se chega pelo botao Configuracoes
 * do mapa e se volta por Voltar. Mostra os indicadores da planta, o que o cadastro nativo
 * ja tem preenchido e o acesso as demais telas.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/Schema.php';

/* ------------------------------------------------------------------ AJAX */
if (isset($_GET['ajax'])) {
    try {
        if ($_GET['ajax'] === 'resumo') {
            Resultado::ok(ftth_resumo())->enviar();
        }
        if ($_GET['ajax'] === 'salvar_ajustes') {
            ftth_exigir_csrf();
            Resultado::ok(ftth_salvar_ajustes())->enviar();
        }
        Resultado::erro('FTTH-SYS-002')->enviar(400);
    } catch (Throwable $e) {
        Log::excecao('index.ajax', $e, ['ajax' => $_GET['ajax'] ?? '']);
        Resultado::erro('FTTH-SYS-001')->enviar(500);
    }
}

/** Contagens do addon + do cadastro nativo (somente leitura). */
function ftth_resumo(): array
{
    $instalado = (bool) Db::valor(
        'SELECT COUNT(*) FROM information_schema.tables
         WHERE table_schema = DATABASE() AND table_name IN ("tab_ftth_caixa","tab_ftth_regiao")
         HAVING COUNT(*) = 2'
    );

    $dados = ['instalado' => $instalado];

    if ($instalado) {
        $dados['regioes']  = (int) Db::valor('SELECT COUNT(*) FROM tab_ftth_regiao WHERE excluido_em IS NULL');
        $dados['caixas']   = (int) Db::valor('SELECT COUNT(*) FROM tab_ftth_caixa  WHERE excluido_em IS NULL');
        $dados['vaos']     = (int) Db::valor('SELECT COUNT(*) FROM tab_ftth_cabo_vao WHERE excluido_em IS NULL');
        $dados['km']       = round(((float) Db::valor(
            'SELECT COALESCE(SUM(comprimento_optico),0) FROM tab_ftth_cabo_vao WHERE excluido_em IS NULL')) / 1000, 2);
        $dados['ligacoes'] = (int) Db::valor('SELECT COUNT(*) FROM tab_ftth_ligacao');
        $dados['clientes_vinculados'] = (int) Db::valor('SELECT COUNT(*) FROM tab_ftth_porta');
        $dados['quarentena'] = (int) Db::valor(
            'SELECT COUNT(*) FROM tab_ftth_importacao_item WHERE status = "pendente"');
    }

    // Cadastro nativo: so leitura, e sem JOIN por string (F5).
    $nativo = Db::um(
        "SELECT COUNT(*) AS ativos,
                SUM(coordenadas    <> '') AS com_coordenadas,
                SUM(porta_olt      <> '') AS com_pon,
                SUM(onu_ont        <> '') AS com_onu,
                SUM(caixa_herm     <> '') AS com_cto,
                SUM(porta_splitter <> '') AS com_porta
         FROM sis_cliente WHERE cli_ativado = 's'"
    ) ?: [];
    $dados['cadastro'] = array_map('intval', $nativo);
    $dados['olts'] = (int) Db::valor('SELECT COUNT(*) FROM olt');

    return $dados;
}

/**
 * Grava os ajustes da tela. Só as chaves que a tela oferece: uma lista fixa evita que um POST
 * forjado escreva qualquer coisa em tab_ftth_config — inclusive os interruptores de escrita
 * em tabela nativa, que continuam só por SQL, de propósito.
 */
function ftth_salvar_ajustes(): array
{
    global $usuario_logado;

    $tipos = ['roadmap', 'satellite', 'hybrid', 'terrain'];
    $salvas = [];

    if (isset($_POST['google_maps_key'])) {
        Config::set('google_maps_key', trim((string) $_POST['google_maps_key']), $usuario_logado);
        $salvas[] = 'google_maps_key';
    }
    if (isset($_POST['mapa_tipo'])) {
        $tipo = in_array($_POST['mapa_tipo'], $tipos, true) ? (string) $_POST['mapa_tipo'] : 'hybrid';
        Config::set('mapa_tipo', $tipo, $usuario_logado);
        $salvas[] = 'mapa_tipo';
    }
    if (isset($_POST['mapa_rotulo_zoom'])) {
        $zoom = max(3, min(21, (int) $_POST['mapa_rotulo_zoom']));
        Config::set('mapa_rotulo_zoom', (string) $zoom, $usuario_logado);
        $salvas[] = 'mapa_rotulo_zoom';
    }

    return ['salvas' => $salvas];
}

$schema  = new Schema(__DIR__ . '/sql');
$estado  = ['instalado' => false, 'faltando' => [], 'aposentadas' => [], 'ledger' => ['baseline' => null, 'legados' => 0]];
$resumo  = [];
$falha   = null;

try {
    $estado = $schema->estado();
    $resumo = ftth_resumo();
} catch (Throwable $e) {
    // Nunca deixar a pagina parar no meio: registra e mostra recado ao usuario.
    Log::excecao('index.carregar', $e);
    $falha = 'Não foi possível ler o estado do addon. Código FTTH-SYS-001 · ' . Resultado::requestId();
}

/** Separador de milhar nos indicadores — a planta passa de mil itens rápido. */
function ftth_num($valor): string
{
    return number_format((float) $valor, 0, ',', '.');
}

include('nav/header.php');
?>
<body>
<?php include('../../topo.php'); ?>

<div class="ftth-wrap">
    <h1 class="ftth-titulo">FTTH Doc — configurações</h1>
    <p class="ftth-sub">Documentação e inteligência operacional da rede óptica.</p>

    <!-- A tela principal do addon é o mapa: daqui só se volta para ele. -->
    <div class="ftth-acoes-topo">
        <a class="ftth-btn ftth-btn--pri" href="mapa.php"><i class="bi-arrow-left"></i> Voltar</a>
        <span class="ftth-acoes-sep"></span>
        <a class="ftth-btn ftth-btn--sec" href="regioes.php"><i class="bi-geo-fill"></i> Regiões</a>
        <a class="ftth-btn ftth-btn--sec" href="pop.php"><i class="bi-hdd-rack-fill"></i> POP / Data Center</a>
        <a class="ftth-btn ftth-btn--sec" href="importar.php"><i class="bi-box-seam"></i> Importar KMZ</a>
    </div>

    <?php if ($falha): ?>
        <div class="ftth-aviso ftth-aviso--erro"><?= htmlspecialchars($falha) ?></div>
    <?php endif; ?>

    <?php if (!empty($estado['faltando'])): ?>
        <div class="ftth-aviso">
            <strong>O banco não está completo</strong> — faltam
            <?= count($estado['faltando']) ?> tabela(s) do addon.
            Rode o instalador no terminal do servidor:
            <code>wget -O - <?= htmlspecialchars(FTTH_URL_INSTALADOR) ?> | bash</code>
        </div>
    <?php endif; ?>

    <?php if (!empty($resumo['instalado'])): ?>
    <div class="row g-3">
        <div class="col-12 col-md-6">
            <div class="ftth-card">
                <div class="ftth-card-topo">
                    <h2><i class="bi-diagram-3-fill"></i> Planta documentada</h2>
                    <a class="ftth-card-link" href="mapa.php">ver no mapa</a>
                </div>
                <div class="ftth-kpis">
                    <div class="ftth-kpi">
                        <span class="ftth-kpi-rotulo">Regiões</span>
                        <span class="ftth-kpi-valor"><?= ftth_num($resumo['regioes']) ?></span>
                    </div>
                    <div class="ftth-kpi">
                        <span class="ftth-kpi-rotulo">Caixas</span>
                        <span class="ftth-kpi-valor"><?= ftth_num($resumo['caixas']) ?></span>
                    </div>
                    <div class="ftth-kpi">
                        <span class="ftth-kpi-rotulo">Vãos de cabo</span>
                        <span class="ftth-kpi-valor"><?= ftth_num($resumo['vaos']) ?></span>
                    </div>
                    <div class="ftth-kpi">
                        <span class="ftth-kpi-rotulo">Extensão óptica</span>
                        <span class="ftth-kpi-valor"><?= number_format((float) $resumo['km'], 2, ',', '.') ?><small>km</small></span>
                    </div>
                    <div class="ftth-kpi">
                        <span class="ftth-kpi-rotulo">Ligações de fibra</span>
                        <span class="ftth-kpi-valor"><?= ftth_num($resumo['ligacoes']) ?></span>
                    </div>
                    <div class="ftth-kpi">
                        <span class="ftth-kpi-rotulo">Clientes em porta</span>
                        <span class="ftth-kpi-valor"><?= ftth_num($resumo['clientes_vinculados']) ?></span>
                    </div>
                    <?php $quarentena = (int) $resumo['quarentena']; ?>
                    <a class="ftth-kpi<?= $quarentena ? ' ftth-kpi--alerta' : '' ?>" href="importar.php"
                       title="Itens de KMZ aguardando revisão">
                        <span class="ftth-kpi-rotulo">Em quarentena</span>
                        <span class="ftth-kpi-valor"><?= ftth_num($quarentena) ?></span>
                    </a>
                </div>
            </div>
        </div>
        <div class="col-12 col-md-6">
            <div class="ftth-card">
                <div class="ftth-card-topo">
                    <h2><i class="bi-people-fill"></i> Cadastro do MK-AUTH</h2>
                    <span class="ftth-selo ftth-selo--info">somente leitura</span>
                </div>
                <div class="ftth-kpis">
                    <div class="ftth-kpi">
                        <span class="ftth-kpi-rotulo">Clientes ativos</span>
                        <span class="ftth-kpi-valor"><?= ftth_num($resumo['cadastro']['ativos'] ?? 0) ?></span>
                    </div>
                    <div class="ftth-kpi">
                        <span class="ftth-kpi-rotulo">Com coordenadas</span>
                        <span class="ftth-kpi-valor"><?= ftth_num($resumo['cadastro']['com_coordenadas'] ?? 0) ?></span>
                    </div>
                    <div class="ftth-kpi" title="Porta da OLT preenchida pelo provisionamento">
                        <span class="ftth-kpi-rotulo">Com PON</span>
                        <span class="ftth-kpi-valor"><?= ftth_num($resumo['cadastro']['com_pon'] ?? 0) ?></span>
                    </div>
                    <div class="ftth-kpi" title="Serial da ONU/ONT preenchido">
                        <span class="ftth-kpi-rotulo">Com ONU</span>
                        <span class="ftth-kpi-valor"><?= ftth_num($resumo['cadastro']['com_onu'] ?? 0) ?></span>
                    </div>
                    <div class="ftth-kpi" title="Caixa hermética (CTO) preenchida">
                        <span class="ftth-kpi-rotulo">Com CTO</span>
                        <span class="ftth-kpi-valor"><?= ftth_num($resumo['cadastro']['com_cto'] ?? 0) ?></span>
                    </div>
                    <div class="ftth-kpi" title="Porta do splitter preenchida">
                        <span class="ftth-kpi-rotulo">Com porta</span>
                        <span class="ftth-kpi-valor"><?= ftth_num($resumo['cadastro']['com_porta'] ?? 0) ?></span>
                    </div>
                    <div class="ftth-kpi">
                        <span class="ftth-kpi-rotulo">OLTs cadastradas</span>
                        <span class="ftth-kpi-valor"><?= ftth_num($resumo['olts'] ?? 0) ?></span>
                    </div>
                </div>
                <p class="ftth-sub ftth-card-nota">
                    CTO e porta do splitter são preenchidas por este addon
                    (exceção aprovada), o resto vem do provisionamento.
                </p>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Ajustes: sem esta tela, a chave do Google Maps só entraria por SQL no banco. -->
    <div class="row g-3">
        <div class="col-12 col-md-7">
            <div class="ftth-card">
                <div class="ftth-card-topo">
                    <h2><i class="bi-gear-fill"></i> Ajustes</h2>
                    <span class="ftth-sub" style="margin:0">Valem para todos os usuários do painel.</span>
                </div>
                <div class="ftth-form-linha">
                    <div class="ftth-form-campo" style="flex:1 1 320px">
                        <label for="f-maps">Chave do Google Maps</label>
                        <input id="f-maps" class="ftth-campo" autocomplete="off" spellcheck="false"
                               placeholder="AIza..." value="<?= htmlspecialchars((string) Config::get('google_maps_key', '')) ?>">
                    </div>
                    <div class="ftth-form-campo ftth-form-campo--md">
                        <label for="f-tipo">Tipo de mapa</label>
                        <select id="f-tipo" class="ftth-campo">
                            <?php foreach (['hybrid' => 'Híbrido', 'satellite' => 'Satélite',
                                            'roadmap' => 'Ruas', 'terrain' => 'Relevo'] as $v => $r): ?>
                                <option value="<?= $v ?>" <?= (string) Config::get('mapa_tipo', 'hybrid') === $v ? 'selected' : '' ?>>
                                    <?= $r ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="ftth-form-campo ftth-form-campo--sm">
                        <label for="f-zoom">Zoom do rótulo</label>
                        <input id="f-zoom" class="ftth-campo" type="number" min="3" max="21"
                               value="<?= (int) Config::num('mapa_rotulo_zoom', 17) ?>">
                    </div>
                    <div class="ftth-form-acoes">
                        <button class="ftth-btn ftth-btn--pri" id="btn-ajustes">Salvar</button>
                    </div>
                </div>
                <p class="ftth-sub ftth-card-nota">
                    A chave vem do console do Google (Maps JavaScript API) e deve ser
                    <strong>restrita por domínio</strong> ao endereço deste painel. Sem ela o mapa não abre.
                </p>
                <div id="saida-ajustes"></div>
            </div>
        </div>

        <div class="col-12 col-md-5">
            <div class="ftth-card">
                <div class="ftth-card-topo">
                    <h2><i class="bi-hdd-network-fill"></i> Estado do banco</h2>
                    <span class="ftth-selo <?= empty($estado['faltando']) ? 'ftth-selo--ok' : 'ftth-selo--erro' ?>">
                        <?= empty($estado['faltando']) ? 'completo' : 'incompleto' ?></span>
                </div>
                <table class="ftth-tabela">
                    <tr>
                        <td>Tabelas do addon</td>
                        <td class="mono"><?= count($estado['tabelas'] ?? []) ?> de <?= count(Schema::TABELAS) ?></td>
                    </tr>
                    <tr>
                        <td>Schema aplicado</td>
                        <td class="mono">
                            <?php if (!empty($estado['ledger']['baseline'])): ?>
                                versão <?= htmlspecialchars((string) $estado['ledger']['baseline']['versao']) ?>
                                em <?= htmlspecialchars(substr((string) $estado['ledger']['baseline']['executed_at'], 0, 16)) ?>
                            <?php else: ?>
                                —
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <td>Versão do addon</td>
                        <td class="mono"><?= htmlspecialchars(isset($Manifest->{'version'}) ? (string) $Manifest->{'version'} : '—') ?></td>
                    </tr>
                    <?php if (!empty($estado['aposentadas'])): ?>
                    <tr>
                        <td>Tabelas aposentadas</td>
                        <td class="mono"><?= count($estado['aposentadas']) ?> a remover</td>
                    </tr>
                    <?php endif; ?>
                </table>
                <p class="ftth-sub ftth-card-nota">
                    Banco e arquivos são atualizados pelo instalador, no terminal do servidor:
                    <code>wget -O - <?= htmlspecialchars(FTTH_URL_INSTALADOR) ?> | bash</code>
                </p>
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    // Único formulário desta tela. O CSRF é o mesmo do resto do addon; sem ele o endpoint recusa.
    var CSRF = <?= json_encode(ftth_csrf_token()) ?>;

    $('#btn-ajustes').on('click', function () {
        var $b = $(this).prop('disabled', true);
        // chamar() devolve a promise do jQuery: o always() reabilita o botao nos dois desfechos.
        FTTH.chamar({
            url: 'index.php?ajax=salvar_ajustes',
            method: 'POST',
            data: {
                csrf: CSRF,
                google_maps_key: $('#f-maps').val(),
                mapa_tipo: $('#f-tipo').val(),
                mapa_rotulo_zoom: $('#f-zoom').val()
            },
            onOk: function () {
                $('#saida-ajustes').html('<div class="ftth-aviso ftth-aviso--ok">Ajustes salvos. '
                    + 'Recarregue o mapa para ver o efeito.</div>');
            },
            onErro: function (m) {
                $('#saida-ajustes').html('<div class="ftth-aviso ftth-aviso--erro">' + FTTH.esc(m) + '</div>');
            }
        }).always(function () { $b.prop('disabled', false); });
    });
})();
</script>

<?php include('../../baixo.php'); ?>

<!-- Monta os menus suspensos do painel. Sem isso, os menus do core abrem VAZIOS.
     Usa $ext_mk (.hhvm ou .php) definido no config.php, em vez de fixar a extensao. -->
<script src="../../menu.js<?= $ext_mk ?>"></script>
</body>
</html>
