<?php
/**
 * ftth_doc :: indicadores da planta e do cadastro nativo.
 *
 * A tela principal do addon e o mapa (mapa.php). Ajustes, importacao e estado do banco
 * moram na aba Ajustes do painel do mapa desde 24/09/2026; aqui ficaram so os indicadores,
 * sem link a partir do mapa, ate virarem o dashboard.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/Schema.php';

/* ------------------------------------------------------------------ AJAX */
if (isset($_GET['ajax'])) {
    try {
        if ($_GET['ajax'] === 'resumo') {
            Resultado::ok(ftth_resumo())->enviar();
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
    // `olt` vem do addon HelpFiber. Sem ele, o indicador nao existe — e isso nao e erro.
    $dados['olts'] = Db::tabelaExiste('olt')
        ? (int) Db::valor('SELECT COUNT(*) FROM olt')
        : null;

    return $dados;
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
    <h1 class="ftth-titulo">FTTH Doc — indicadores</h1>
    <p class="ftth-sub">Documentação e inteligência operacional da rede óptica.</p>

    <!-- A tela principal do addon é o mapa: daqui só se volta para ele. -->
    <div class="ftth-acoes-topo">
        <a class="ftth-btn ftth-btn--pri" href="mapa.php"><i class="bi-arrow-left"></i> Voltar</a>
        <span class="ftth-acoes-sep"></span>
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
    <div class="ftth-duplo">
        <div>
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
        <div>
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
                    <div class="ftth-kpi" title="Cadastro de OLT do addon HelpFiber; sem ele, as OLTs sao cadastradas aqui mesmo, no POP">
                        <span class="ftth-kpi-rotulo">OLTs no HelpFiber</span>
                        <span class="ftth-kpi-valor"><?= isset($resumo['olts']) ? ftth_num($resumo['olts']) : '—' ?></span>
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
</div>


<?php include('../../baixo.php'); ?>

<!-- Monta os menus suspensos do painel. Sem isso, os menus do core abrem VAZIOS.
     Usa $ext_mk (.hhvm ou .php) definido no config.php, em vez de fixar a extensao. -->
<script src="../../menu.js<?= $ext_mk ?>"></script>
</body>
</html>
