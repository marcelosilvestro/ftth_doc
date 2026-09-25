<?php
/**
 * ftth_doc :: mapa — a tela principal do addon.
 *
 * Carrega sempre por região + área visível (bbox), nunca a rede inteira (3b.12).
 * Itens ainda em quarentena aparecem translúcidos, com aviso — são "não revisados" (U3).
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/Regiao.php';
require_once __DIR__ . '/lib/Mapa.php';
require_once __DIR__ . '/lib/Quarentena.php';
require_once __DIR__ . '/lib/Caixa.php';
require_once __DIR__ . '/lib/Cabo.php';
require_once __DIR__ . '/lib/Topologia.php';
require_once __DIR__ . '/lib/Ajustes.php';

/* ------------------------------------------------------------------ AJAX */
if (isset($_GET['ajax'])) {
    try {
        switch ($_GET['ajax']) {
            case 'elementos':
                $regiaoId = (int) ($_GET['regiao'] ?? 0);
                $bbox = null;
                if (!empty($_GET['bbox'])) {
                    $p = array_map('floatval', explode(',', (string) $_GET['bbox']));
                    if (count($p) === 4) {
                        $bbox = [min($p[0], $p[2]), min($p[1], $p[3]), max($p[0], $p[2]), max($p[1], $p[3])];
                    }
                }
                $dados = Mapa::elementos($regiaoId, $bbox, [
                    'quarentena' => ($_GET['quarentena'] ?? '1') === '1',
                ]);
                $dados['contadores'] = Quarentena::contar($regiaoId);
                Resultado::ok($dados)->enviar();

            // Regiões: moram no painel lateral do mapa desde 24/09/2026 (antes, regioes.php).
            // Centro e zoom vêm sempre da vista do mapa, nunca digitados.
            case 'regioes':
                Resultado::ok(['regioes' => Regiao::listar()])->enviar();

            case 'regiao_criar':
                ftth_exigir_csrf();
                Regiao::criar(
                    (string) ($_POST['nome'] ?? ''),
                    (float) ($_POST['lat'] ?? 0),
                    (float) ($_POST['lng'] ?? 0),
                    (int) ($_POST['zoom'] ?? 15),
                    $usuario_logado
                )->enviar();

            case 'regiao_alterar':
                ftth_exigir_csrf();
                $atual = Regiao::obter((int) ($_POST['id'] ?? 0));
                if (!$atual) {
                    Resultado::erro('FTTH-TOP-001', [], 'Região não encontrada.')->enviar(404);
                }
                // Renomear não mexe no centro; "usar esta vista" não mexe no nome.
                $comVista = ($_POST['lat'] ?? '') !== '' && ($_POST['lng'] ?? '') !== '';
                Regiao::alterar(
                    (int) $atual['id'],
                    isset($_POST['nome']) ? (string) $_POST['nome'] : (string) $atual['nome'],
                    $comVista ? (float) $_POST['lat'] : ($atual['lat'] !== null ? (float) $atual['lat'] : null),
                    $comVista ? (float) $_POST['lng'] : ($atual['lng'] !== null ? (float) $atual['lng'] : null),
                    $comVista ? (int) ($_POST['zoom'] ?? 15) : (int) $atual['zoom'],
                    isset($_POST['versao']) && $_POST['versao'] !== '' ? (int) $_POST['versao'] : null,
                    $usuario_logado
                )->enviar();

            case 'regiao_excluir':
                ftth_exigir_csrf();
                Regiao::excluir(
                    (int) ($_POST['id'] ?? 0),
                    isset($_POST['versao']) && $_POST['versao'] !== '' ? (int) $_POST['versao'] : null,
                    $usuario_logado
                )->enviar();

            // Aba Ajustes do painel (e o campo da chave na primeira instalação).
            case 'ajustes':
                ftth_exigir_csrf();
                Resultado::ok(Ajustes::salvar($_POST, $usuario_logado))->enviar();

            // Só quando a aba Ajustes abre: não pesa a abertura do mapa.
            case 'estado':
                Resultado::ok(Ajustes::estado())->enviar();

            case 'pontos':
                Resultado::ok(['pontos' => Mapa::pontos((int) ($_GET['regiao'] ?? 0))])->enviar();

            case 'buscar':
                Resultado::ok(['resultados' => Mapa::buscar(
                    (int) ($_GET['regiao'] ?? 0), (string) ($_GET['q'] ?? ''))])->enviar();

            case 'ficha':
                $f = Mapa::ficha((int) ($_GET['id'] ?? 0));
                $f ? Resultado::ok($f)->enviar() : Resultado::erro('FTTH-TOP-001')->enviar(404);

            // Importar/descartar direto do mapa, sem passar pela tela de importação:
            // é a mesma camada de serviço que a tela usa (3b.0).
            case 'nova_caixa':
                ftth_exigir_csrf();
                Caixa::criar(
                    (int) ($_POST['regiao'] ?? 0),
                    (string) ($_POST['tipo'] ?? ''),
                    (string) ($_POST['nome'] ?? ''),
                    (string) ($_POST['cor'] ?? '#00C853'),
                    (float) ($_POST['lat'] ?? 0),
                    (float) ($_POST['lng'] ?? 0),
                    $usuario_logado,
                    ['reserva_m' => $_POST['reserva_m'] ?? null]
                )->enviar();

            // Você soltou a caixa em cima de um cabo? Consulta pura: quem decide emendar é o
            // usuário, na confirmação que a tela mostra com estes dados.
            case 'vao_sob_ponto':
                $achado = Cabo::vaoSobPonto(
                    (int) ($_GET['regiao'] ?? 0),
                    (float) ($_GET['lat'] ?? 0),
                    (float) ($_GET['lng'] ?? 0),
                    ($_GET['caixa'] ?? '') !== '' ? (int) $_GET['caixa'] : null
                );
                Resultado::ok($achado === null ? ['vao' => null] : [
                    'vao'         => (int) $achado['vao']['id'],
                    'cabo'        => (int) $achado['vao']['cabo_id'],
                    'cabo_nome'   => $achado['vao']['cabo_nome'],
                    'cabo_tipo'   => $achado['vao']['cabo_tipo'],
                    'fibras'      => (int) $achado['vao']['fibras'],
                    'distancia_m' => $achado['projecao']['distancia_m'],
                    'raio_m'      => Cabo::raioQuebra(),
                ])->enviar();

            case 'quebrar_vao':
                ftth_exigir_csrf();
                Cabo::quebrarVao(
                    (int) ($_POST['vao'] ?? 0),
                    (int) ($_POST['caixa'] ?? 0),
                    $usuario_logado
                )->enviar();

            case 'novo_cabo':
                ftth_exigir_csrf();
                $pontos = json_decode((string) ($_POST['pontos'] ?? '[]'), true);
                if (!is_array($pontos)) {
                    Resultado::erro('FTTH-SYS-002', ['campo' => 'pontos'])->enviar(400);
                }
                Cabo::criar(
                    (int) ($_POST['regiao'] ?? 0),
                    [
                        'nome'         => (string) ($_POST['nome'] ?? ''),
                        'fabricante'   => (string) ($_POST['fabricante'] ?? ''),
                        'cabo_tipo_id' => (int) ($_POST['cabo_tipo_id'] ?? 0),
                        'padrao_cores' => (string) ($_POST['padrao_cores'] ?? 'ABNT'),
                        'cor_rota'     => (string) ($_POST['cor_rota'] ?? '#00E676'),
                    ],
                    $pontos,
                    $usuario_logado
                )->enviar();

            case 'alterar_cabo':
                ftth_exigir_csrf();
                Cabo::alterar(
                    (int) ($_POST['cabo'] ?? 0),
                    [
                        'nome'         => $_POST['nome'] ?? '',
                        'fabricante'   => $_POST['fabricante'] ?? '',
                        'cabo_tipo_id' => (int) ($_POST['cabo_tipo_id'] ?? 0),
                        'padrao_cores' => $_POST['padrao_cores'] ?? '',
                        'cor_rota'     => $_POST['cor_rota'] ?? '',
                        'status'       => $_POST['status'] ?? '',
                    ],
                    isset($_POST['versao']) ? (int) $_POST['versao'] : null,
                    $usuario_logado
                )->enviar();

            case 'excluir_cabo':
                ftth_exigir_csrf();
                Cabo::excluir((int) ($_POST['cabo'] ?? 0), $usuario_logado)->enviar();

            case 'alterar_caixa':
                ftth_exigir_csrf();
                Caixa::alterar(
                    (int) ($_POST['id'] ?? 0),
                    [
                        'nome'       => (string) ($_POST['nome'] ?? ''),
                        'tipo'       => (string) ($_POST['tipo'] ?? ''),
                        'cor'        => (string) ($_POST['cor'] ?? ''),
                    ] + (isset($_POST['reserva_m']) ? ['reserva_m' => $_POST['reserva_m']] : []),
                    isset($_POST['versao']) ? (int) $_POST['versao'] : null,
                    $usuario_logado
                )->enviar();

            // Modo Mover: caixas e traçados vão juntos, numa transação só. Um mapa meio
            // movido seria pior do que um mapa não movido.
            case 'mover':
                ftth_exigir_csrf();
                $caixas = json_decode((string) ($_POST['caixas'] ?? '[]'), true);
                $vaos   = json_decode((string) ($_POST['vaos'] ?? '[]'), true);
                if (!is_array($caixas) || !is_array($vaos)) {
                    Resultado::erro('FTTH-SYS-002', ['campo' => 'movimentos'])->enviar(400);
                }
                Mapa::aplicarMovimentos($caixas, $vaos, $usuario_logado)->enviar();

            // A caixa é só uma emenda no meio de um cabo? Consulta pura: a tela usa isto para
            // avisar que excluir vai juntar os dois trechos, antes de fazer.
            case 'emenda_simples':
                Resultado::ok(['emenda' => Cabo::emendaSimples((int) ($_GET['caixa'] ?? 0))])->enviar();

            case 'unir_vaos':
                ftth_exigir_csrf();
                Cabo::unirVaos(
                    (int) ($_POST['caixa'] ?? 0),
                    isset($_POST['versao']) && $_POST['versao'] !== '' ? (int) $_POST['versao'] : null,
                    $usuario_logado
                )->enviar();

            case 'excluir_caixa':
                ftth_exigir_csrf();
                Caixa::excluir(
                    (int) ($_POST['id'] ?? 0),
                    isset($_POST['versao']) ? (int) $_POST['versao'] : null,
                    $usuario_logado
                )->enviar();

            // ---------------------------------------------------------- clientes na porta
            case 'atendimento':
                $cx = (int) ($_GET['caixa'] ?? 0);
                $d  = Topologia::atendimentoDaCaixa($cx);
                if (!$d) {
                    Resultado::erro('FTTH-TOP-001', ['caixa' => $cx])->enviar(404);
                }
                // A potência entra aqui, não no Topologia: quem junta as duas camadas é a
                // tela, como já acontece no diagrama.
                $sinal = Potencia::comSinal($cx);
                foreach ($d['splitters'] as &$sp) {
                    foreach ($sp['portas'] as &$pt) {
                        $pt['dbm'] = $sinal['SPLITTER_OUT:' . $sp['id'] . ':' . $pt['numero']] ?? null;
                    }
                    unset($pt);
                }
                unset($sp);
                Resultado::ok($d)->enviar();

            // ------------------------------------------------------------- rota óptica
            // O diagrama pede a rota de uma ponta escolhida no desenho; aqui o usuário
            // clicou na caixa inteira. Quem escolhe a ponta representativa é o mesmo
            // resumo que alimenta a ficha — assim o dBm do detalhe nunca discorda do
            // dBm mostrado logo acima do botão.
            case 'rota':
                $cx     = (int) ($_GET['caixa'] ?? 0);
                $resumo = Potencia::resumoDaCaixa($cx);
                if (!$resumo) {
                    Resultado::erro('FTTH-PWR-001', ['caixa' => $cx],
                        'Esta caixa não tem caminho óptico até uma porta de DIO com equipamento.')
                        ->enviar(404);
                }
                $r = Potencia::rota($cx, $resumo['ponta']['elemento'],
                                    $resumo['ponta']['elemento_id'], $resumo['ponta']['numero']);
                if (!$r->ok) {
                    $r->enviar(404);
                }
                $rota           = $r->data;
                $rota['onde']   = $resumo['onde'];
                // Fora de uma saída de atendimento a faixa não se aplica (ela classifica o
                // RX da ONU), e o resumo já resolveu isso — aqui só se obedece.
                $rota['classe'] = $resumo['classe'];
                Resultado::ok($rota)->enviar();

            case 'buscar_cliente':
                Resultado::ok(['clientes' => Topologia::buscarClientes(
                    (string) ($_GET['q'] ?? ''))])->enviar();

            case 'vincular_cliente':
                ftth_exigir_csrf();
                Topologia::vincularCliente(
                    (int) ($_POST['cliente'] ?? 0),
                    (int) ($_POST['splitter'] ?? 0),
                    (int) ($_POST['numero'] ?? 0),
                    $usuario_logado,
                    ['mover' => ($_POST['mover'] ?? '') === '1']
                )->enviar();

            case 'desvincular_cliente':
                ftth_exigir_csrf();
                Topologia::desvincularCliente((int) ($_POST['cliente'] ?? 0), $usuario_logado)->enviar();

            case 'sugerir_nome':
                Resultado::ok(['nome' => Caixa::sugerirNome(
                    (int) ($_GET['regiao'] ?? 0),
                    (string) ($_GET['tipo'] ?? 'CTO'),
                    ($_GET['base'] ?? '') !== '' ? (string) $_GET['base'] : null)])->enviar();

            case 'importar_item':
                ftth_exigir_csrf();
                Quarentena::importarItem((int) ($_POST['id'] ?? 0), $usuario_logado)->enviar();

            case 'descartar_item':
                ftth_exigir_csrf();
                Quarentena::descartar([(int) ($_POST['id'] ?? 0)], $usuario_logado, 'descartado no mapa')->enviar();

            default:
                Resultado::erro('FTTH-SYS-002')->enviar(400);
        }
    } catch (Throwable $e) {
        Log::excecao('mapa.ajax', $e, ['ajax' => $_GET['ajax'] ?? '']);
        Resultado::erro('FTTH-SYS-001')->enviar(500);
    }
}

/* ------------------------------------------------------------------ página */
$regioes   = [];
$chave     = '';
$aj        = ['google_maps_key' => '', 'mapa_tipo' => 'hybrid', 'mapa_rotulo_zoom' => 17,
              'raio_quebra_cabo_m' => 10];
$falha     = null;
try {
    $regioes = Regiao::listar();
    $chave   = (string) Config::get('google_maps_key', '');
    $aj      = Ajustes::valores();
} catch (Throwable $e) {
    Log::excecao('mapa.carregar', $e);
    $falha = 'Não foi possível carregar o mapa. FTTH-SYS-001 · ' . Resultado::requestId();
}
$regiaoInicial = $regioes[0] ?? null;

$ftth_tela_cheia = true;   // o mapa ocupa a janela: sem barra de rolagem na página
include('nav/header.php');
?>
<!-- ftth-mapa-full esconde o bloco de status do MK-AUTH (#systopo: logo, RAM/CPU, usuário)
     SÓ nesta página, para o mapa ocupar a tela. O menu do topo continua intacto. -->
<body class="ftth-mapa-full">
<?php include('../../topo.php'); ?>

<div class="ftth-wrap ftth-wrap--mapa">
    <?php if ($falha): ?>
        <div class="ftth-aviso ftth-aviso--erro"><?= htmlspecialchars($falha) ?></div>
    <?php elseif ($chave === ''): ?>
        <!-- Primeiro acesso de qualquer instalação nova cai aqui. É a única tela do addon que
             não funciona sozinha, então ela explica o caminho inteiro em vez de só reclamar. -->
        <div class="ftth-aviso ftth-aviso--erro ftth-aviso--passos">
            <strong>O mapa precisa de uma chave do Google Maps para abrir.</strong>
            <ol>
                <li>No <a href="https://console.cloud.google.com/google/maps-apis" target="_blank"
                          rel="noopener">console do Google Cloud</a>, ative a
                    <strong>Maps JavaScript API</strong> e gere uma chave.</li>
                <li>Ainda no console, em <em>Restrições do aplicativo</em>, escolha
                    <strong>Referenciadores HTTP</strong> e libere o endereço deste painel —
                    <code><?= htmlspecialchars(($_SERVER['HTTP_HOST'] ?? 'seu-servidor')) ?>/*</code>.
                    Sem isso o Google recusa a chave e o mapa abre cinza.</li>
                <li>Cole a chave aqui e salve — a página recarrega sozinha:</li>
            </ol>
            <div class="ftth-chave-inicial">
                <input id="chave-inicial" class="ftth-campo" autocomplete="off" spellcheck="false"
                       placeholder="AIza...">
                <button class="ftth-btn ftth-btn--pri" id="chave-inicial-salvar">Salvar</button>
            </div>
            <div id="chave-inicial-saida"></div>
            <span class="ftth-sub">A chave fica no seu servidor, em <code>tab_ftth_config</code>;
                o addon não envia nada para fora além das requisições do próprio mapa.</span>
        </div>
    <?php endif; ?>

    <!-- Barra única: modos, região, busca, camadas, resumo e configurações numa linha só.
         Cada elemento que sai daqui é altura que o mapa ganha, e o mapa é a tela.
         Os ícones são os Bootstrap Icons que o próprio MK-AUTH já carrega (bi-icons.css):
         nada de CDN nem de fonte nova só para o addon. -->
    <div class="ftth-mapa-barra">
        <div class="ftth-modos">
            <button class="ftth-modo ativo" data-modo="navegar"><i class="bi-cursor-fill"></i> Navegar</button>
            <button class="ftth-modo" data-modo="caixa">
                <i class="bi-geo-alt-fill"></i> Ponto</button>
            <button class="ftth-modo" data-modo="cabo">
                <i class="bi-share-fill"></i> Cabo</button>
            <button class="ftth-modo" data-modo="mover">
                <i class="bi-arrows-move"></i> Mover</button>
        </div>

        <div class="ftth-busca">
            <i class="bi-search ftth-busca-icone"></i>
            <input id="busca" class="ftth-campo ftth-busca-campo" autocomplete="off"
                   placeholder="Buscar ponto, cliente ou coordenada…">
            <div id="busca-lista" class="ftth-busca-lista"></div>
        </div>

        <div class="ftth-resumo" id="resumo-mapa"></div>

    </div>

    <div class="ftth-mapa-area" id="mapa-area">
        <div id="mapa"></div>

        <!-- Painel lateral (padrão UpperX): regiões e camadas hoje; pontos e o que vier
             depois ganham aba aqui, em vez de mais botão na barra. Abre por cima do mapa. -->
        <button type="button" class="ftth-gaveta-aba" id="gaveta-aba" title="Painel">
            <i class="bi-chevron-right"></i></button>
        <aside class="ftth-gaveta" id="gaveta">
            <nav class="ftth-gaveta-abas">
                <button type="button" class="ftth-gaveta-tab ativo" data-aba="regioes">
                    <i class="bi-map"></i><span>Regiões</span></button>
                <button type="button" class="ftth-gaveta-tab" data-aba="pontos">
                    <i class="bi-geo-alt"></i><span>Pontos</span></button>
                <button type="button" class="ftth-gaveta-tab" data-aba="camadas">
                    <i class="bi-layers"></i><span>Camadas <b id="camadas-conta">5</b></span></button>
                <button type="button" class="ftth-gaveta-tab" data-aba="ajustes">
                    <i class="bi-gear"></i><span>Ajustes</span></button>
            </nav>

            <section class="ftth-gaveta-corpo" data-aba="regioes">
                <div class="ftth-gaveta-lista" id="lista-regioes"></div>
                <div class="ftth-gaveta-rodape">
                    <button type="button" class="ftth-btn ftth-btn--pri ftth-gaveta-acao" id="regiao-nova">
                        <i class="bi-plus-circle-fill"></i> Nova região</button>
                </div>
            </section>

            <!-- Lista da região inteira, não só da área visível. O filtro vale também no mapa:
                 o que não atende fica translúcido, para não se perder a noção de onde se está. -->
            <section class="ftth-gaveta-corpo" data-aba="pontos" style="display:none">
                <div class="ftth-gaveta-topo">
                    <div class="ftth-busca ftth-gaveta-busca">
                        <i class="bi-search ftth-busca-icone"></i>
                        <input id="pontos-busca" class="ftth-campo ftth-busca-campo" autocomplete="off"
                               placeholder="Buscar ponto pelo nome…">
                    </div>
                    <div class="ftth-filtros" id="pontos-filtros">
                        <button type="button" class="ftth-filtro ativo" data-filtro="todos">Todos</button>
                        <button type="button" class="ftth-filtro" data-filtro="sem_sinal"
                                title="CTO e CEO sem caminho óptico até uma porta de DIO com equipamento">
                            Sem sinal <b id="conta-sem-sinal"></b></button>
                        <button type="button" class="ftth-filtro" data-filtro="sem_splitter"
                                title="CTO e CEO sem nenhum splitter, de atendimento ou de derivação">
                            Sem splitter <b id="conta-sem-splitter"></b></button>
                    </div>
                </div>
                <div class="ftth-gaveta-lista" id="lista-pontos"></div>
            </section>

            <section class="ftth-gaveta-corpo" data-aba="camadas" style="display:none">
                <div class="ftth-gaveta-lista">
                    <p class="ftth-gaveta-secao">Mostrar no mapa</p>
                    <label class="ftth-gaveta-opcao"><input type="checkbox" class="cam" value="CTO" checked> CTO</label>
                    <label class="ftth-gaveta-opcao"><input type="checkbox" class="cam" value="CEO" checked> CEO</label>
                    <label class="ftth-gaveta-opcao"><input type="checkbox" class="cam" value="OUTRAS" checked>
                        Outros pontos <small>DC/POP, prédio, problema, reserva</small></label>
                    <label class="ftth-gaveta-opcao"><input type="checkbox" class="cam" value="CABOS" checked> Cabos</label>
                    <label class="ftth-gaveta-opcao"><input type="checkbox" class="cam" value="QUARENTENA" checked>
                        Quarentena <small>itens importados ainda não revisados</small></label>
                </div>
            </section>
            <!-- Ajustes: valem para todos os usuários do painel. O estado do banco só é lido
                 quando a aba abre. -->
            <section class="ftth-gaveta-corpo" data-aba="ajustes" style="display:none">
                <div class="ftth-gaveta-lista">
                    <p class="ftth-gaveta-secao">Ajustes do mapa</p>
                    <div class="ftth-ajustes">
                        <label class="ftth-rotulo-campo" for="aj-chave">Chave do Google Maps</label>
                        <input id="aj-chave" class="ftth-campo" autocomplete="off" spellcheck="false"
                               placeholder="AIza..." value="<?= htmlspecialchars($aj['google_maps_key']) ?>">

                        <label class="ftth-rotulo-campo" for="aj-tipo">Tipo de mapa</label>
                        <select id="aj-tipo" class="ftth-campo">
                            <?php foreach (Ajustes::TIPOS_MAPA as $v => $r): ?>
                                <option value="<?= $v ?>" <?= $aj['mapa_tipo'] === $v ? 'selected' : '' ?>><?= $r ?></option>
                            <?php endforeach; ?>
                        </select>

                        <div class="ftth-ajustes-par">
                            <div>
                                <label class="ftth-rotulo-campo" for="aj-zoom">Zoom do rótulo</label>
                                <input id="aj-zoom" class="ftth-campo" type="number" min="3" max="21"
                                       value="<?= (int) $aj['mapa_rotulo_zoom'] ?>">
                            </div>
                            <div title="Distância em que o mapa oferece emendar a caixa no cabo">
                                <label class="ftth-rotulo-campo" for="aj-raio">Raio de emenda (m)</label>
                                <input id="aj-raio" class="ftth-campo" type="number" min="1" max="100"
                                       value="<?= (int) $aj['raio_quebra_cabo_m'] ?>">
                            </div>
                        </div>

                        <button type="button" class="ftth-btn ftth-btn--pri ftth-gaveta-acao" id="aj-salvar">
                            <i class="bi-check-circle-fill"></i> Salvar</button>
                        <p class="ftth-sub" style="margin:8px 0 0">
                            A chave vem do console do Google (Maps JavaScript API) e deve ser
                            <strong>restrita por domínio</strong> ao endereço deste painel.</p>
                        <div id="aj-saida"></div>
                    </div>

                    <p class="ftth-gaveta-secao" style="margin-top:16px">Dados</p>
                    <a class="ftth-btn ftth-btn--sec ftth-gaveta-acao" href="importar.php">
                        <i class="bi-box-seam"></i> Importar KMZ</a>
                </div>

                <div class="ftth-gaveta-rodape ftth-estado" id="estado-banco">
                    <p class="ftth-gaveta-vazio" style="padding:0">Lendo o estado do banco…</p>
                </div>
            </section>
        </aside>

        <!-- Nova região, passo 2: o centro é a vista do mapa, então o usuário leva o mapa até
             lá antes de gravar. Assim a região nunca nasce no lugar errado sem ninguém ver. -->
        <div id="regiao-card" class="ftth-flutuante" style="display:none">
            <div class="ftth-flutuante-info">
                <strong><i class="bi-map"></i> Nova região</strong>
                <span id="regiao-card-nome"></span>
                <span class="ftth-flutuante-dica">Posicione o mapa onde fica a região e confirme.</span>
            </div>
            <button class="ftth-btn ftth-btn--sec" id="regiao-card-cancelar">
                <i class="bi-x-octagon-fill"></i> Cancelar</button>
            <button class="ftth-btn ftth-btn--pri" id="regiao-card-confirmar">
                <i class="bi-check-circle-fill"></i> Confirmar</button>
        </div>
        <div id="painel" class="ftth-painel" style="display:none">
            <button class="ftth-painel-fechar" id="painel-fechar">&times;</button>
            <div id="painel-conteudo"></div>
        </div>

        <!-- Card flutuante do traçado (padrão UpperX): contagem de pontos e ações. -->
        <div id="cabo-card" class="ftth-flutuante" style="display:none">
            <div class="ftth-flutuante-info">
                <strong><i class="bi-share-fill"></i> Traçando cabo</strong>
                <span id="cabo-contagem">0 pontos</span>
                <!-- O próximo passo muda a cada clique: toast a cada um seria martelar o
                     usuário. Fica aqui, onde o olho já está enquanto ele desenha. -->
                <span id="cabo-dica" class="ftth-flutuante-dica"></span>
            </div>
            <!-- Reabre a configuração sem perder o que já foi desenhado. -->
            <button type="button" class="ftth-flutuante-icone" id="cabo-config" title="Configuração do cabo">
                <i class="bi-sliders"></i></button>
            <button class="ftth-btn ftth-btn--sec" id="cabo-cancelar" title="Descartar o traçado e sair do modo Cabo">
                <i class="bi-x-octagon-fill"></i> Cancelar</button>
            <button class="ftth-btn ftth-btn--sec" id="cabo-desfazer">
                <i class="bi-arrow-counterclockwise"></i> Desfazer</button>
            <button class="ftth-btn ftth-btn--pri" id="cabo-finalizar">
                <i class="bi-check-circle-fill"></i> Finalizar</button>
        </div>

        <!-- Card do modo Mover. Nada aqui vai para o banco antes do Concluir: o usuário
             arrasta à vontade e só então assume. Sair do modo com pendência pergunta. -->
        <div id="mover-card" class="ftth-flutuante ftth-flutuante--edicao" style="display:none">
            <div class="ftth-flutuante-info">
                <strong><i class="bi-arrows-move"></i> Modo edição</strong>
                <span id="mover-dica" class="ftth-flutuante-dica">
                    Arraste um ponto. Clique num cabo para editar o traçado.</span>
            </div>
            <span class="ftth-flutuante-conta" id="mover-conta">nada alterado</span>
            <!-- Só em tela estreita: a dica longa sai do card e fica a um toque. -->
            <button type="button" class="ftth-flutuante-ajuda" id="mover-ajuda" title="Como editar">
                <i class="bi-question-circle"></i></button>
            <button class="ftth-btn ftth-btn--sec" id="mover-descartar"
                    title="Descartar as alterações e sair do modo edição">
                <i class="bi-x-octagon-fill"></i> Cancelar</button>
            <button class="ftth-btn ftth-btn--pri" id="mover-concluir" disabled>
                <i class="bi-check-circle-fill"></i> Concluir</button>
        </div>
    </div>
</div>

<!-- Modal de lançar cabo: abre DEPOIS do traçado, como no UpperX -->
<!-- Modo Cabo, passo 1: configura aqui, depois desenha no mapa (desde 24/09/2026; antes era
     o inverso). O Finalizar do traçado grava direto com o que está neste popup. -->
<div id="modal-cabo" class="ftth-modal" style="display:none">
    <div class="ftth-modal-caixa">
        <div class="ftth-modal-topo">
            <strong><i class="bi-share-fill"></i> <span id="cabo-modal-titulo">Novo cabo</span></strong>
            <button class="ftth-painel-fechar" id="cabo-modal-fechar">&times;</button>
        </div>
        <div class="ftth-modal-corpo">
            <p class="ftth-sub" id="cabo-resumo"></p>

            <label class="ftth-rotulo-campo">Nome do cabo (opcional)</label>
            <input id="cb-nome" class="ftth-campo" style="width:100%" maxlength="80">

            <label class="ftth-rotulo-campo">Fabricante (opcional)</label>
            <input id="cb-fabricante" class="ftth-campo" style="width:100%" maxlength="60">

            <div class="row g-3">
                <div class="col-7">
                    <label class="ftth-rotulo-campo">Capacidade (fibras)</label>
                    <select id="cb-tipo" class="ftth-campo" style="width:100%">
                        <?php foreach (Cabo::tipos() as $t): ?>
                            <option value="<?= (int) $t['id'] ?>" <?= $t['rotulo'] === '6 FO' ? 'selected' : '' ?>>
                                <?= htmlspecialchars($t['rotulo']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-5">
                    <label class="ftth-rotulo-campo">Padrão de cores</label>
                    <select id="cb-padrao" class="ftth-campo" style="width:100%">
                        <?php foreach (Cabo::PADROES_COR as $p): ?>
                            <option value="<?= $p ?>"><?= $p ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <label class="ftth-rotulo-campo">Cor da rota</label>
            <div class="ftth-cores" id="cb-cores">
                <?php foreach (['#00E676', '#00BCD4', '#0D47A1', '#FF9100', '#E53935',
                                '#8E24AA', '#FDD835', '#212121', '#9E9E9E'] as $i => $cor): ?>
                    <button type="button" class="ftth-cor<?= $i === 0 ? ' ativo' : '' ?>"
                            data-cor="<?= $cor ?>" style="background:<?= $cor ?>"></button>
                <?php endforeach; ?>
            </div>

            <div id="cb-saida"></div>
        </div>
        <div class="ftth-modal-rodape">
            <button class="ftth-btn ftth-btn--sec" id="cb-cancelar">Cancelar</button>
            <button class="ftth-btn ftth-btn--pri" id="cb-salvar">Desenhar</button>
        </div>
    </div>
</div>

<!-- Modal de nova caixa (padrão UpperX: nome, tipo, cor e "Ancorar no Mapa") -->
<div id="modal-caixa" class="ftth-modal" style="display:none">
    <div class="ftth-modal-caixa">
        <div class="ftth-modal-topo">
            <strong><i class="bi-geo-alt-fill"></i> <span id="modal-titulo">Novo ponto</span></strong>
            <button class="ftth-painel-fechar" id="modal-fechar">&times;</button>
        </div>
        <div class="ftth-modal-corpo">
            <p class="ftth-sub" id="nc-ponto" style="margin:0 0 4px"></p>

            <label class="ftth-rotulo-campo">Nome do ponto</label>
            <input id="nc-nome" class="ftth-campo" style="width:100%" maxlength="80">
            <!-- Junto do nome porque é sobre ele: o próximo ponto nasce com o número seguinte.
                 Só CTO, CEO e Reserva, que se lançam em sequência pela rua. -->
            <label class="ftth-chk" id="nc-sequencia-area" style="display:block;margin-top:6px">
                <input type="checkbox" id="nc-sequencia"> continuar adicionando (numera sozinho)
            </label>

            <label class="ftth-rotulo-campo">Tipo de ponto</label>
            <div class="ftth-tipos">
                <?php foreach (Caixa::ROTULOS as $valor => $rotulo): ?>
                    <button type="button" class="ftth-tipo<?= $valor === 'CTO' ? ' ativo' : '' ?>"
                            data-tipo="<?= $valor ?>">
                        <?php // CTO e CEO mostram a silhueta da peça; o resto, o ícone do painel. ?>
                        <?php if ($svg = Caixa::silhueta($valor, '#4A5568', 22)): ?>
                            <?= $svg ?>
                        <?php else: ?>
                            <i class="<?= Caixa::icone($valor) ?>"></i>
                        <?php endif; ?>
                        <span><?= htmlspecialchars($rotulo) ?></span>
                    </button>
                <?php endforeach; ?>
            </div>

            <label class="ftth-rotulo-campo">Cor de identificação</label>
            <div class="ftth-cores">
                <?php foreach (Caixa::CORES as $i => $cor): ?>
                    <button type="button" class="ftth-cor<?= $i === 4 ? ' ativo' : '' ?>"
                            data-cor="<?= $cor ?>" style="background:<?= $cor ?>"></button>
                <?php endforeach; ?>
            </div>

            <div id="nc-reserva-area" style="display:none">
                <label class="ftth-rotulo-campo">Metros de reserva</label>
                <input id="nc-reserva" class="ftth-campo" type="number" min="1" max="2000" step="0.5"
                       placeholder="ex.: 30" style="max-width:140px">
                <div class="ftth-sub" style="margin-top:4px">
                    Somados ao comprimento óptico do cabo em que a reserva está.
                </div>
            </div>


            <div id="nc-saida"></div>
        </div>
        <div class="ftth-modal-rodape">
            <button class="ftth-btn ftth-btn--sec" id="nc-cancelar">Cancelar</button>
            <button class="ftth-btn ftth-btn--pri" id="nc-criar">Criar ponto</button>
        </div>
    </div>
</div>

<!-- Nome da região: criar (passo 1) e renomear usam o mesmo modal. -->
<div id="modal-regiao" class="ftth-modal" style="display:none">
    <div class="ftth-modal-caixa">
        <div class="ftth-modal-topo">
            <strong><i class="bi-map"></i> <span id="rg-titulo">Nova região</span></strong>
            <button class="ftth-painel-fechar" id="rg-fechar">&times;</button>
        </div>
        <div class="ftth-modal-corpo">
            <label class="ftth-rotulo-campo">Nome da região</label>
            <input id="rg-nome" class="ftth-campo" style="width:100%" maxlength="80"
                   placeholder="ex.: Palmital">
            <p class="ftth-sub" id="rg-nota" style="margin:8px 0 0"></p>
            <div id="rg-saida"></div>
        </div>
        <div class="ftth-modal-rodape">
            <button class="ftth-btn ftth-btn--sec" id="rg-cancelar">Cancelar</button>
            <button class="ftth-btn ftth-btn--pri" id="rg-salvar">Continuar</button>
        </div>
    </div>
</div>

<!-- Emendar a caixa no cabo. Aparece sozinho quando a caixa cai em cima de um traçado, porque
     a resposta certa depende do que o técnico foi fazer: documentar uma emenda que existe na
     rua, ou só marcar um poste que fica ao lado do cabo. Por isso ninguém decide por ele. -->
<div id="modal-emenda" class="ftth-modal" style="display:none">
    <div class="ftth-modal-caixa">
        <div class="ftth-modal-topo">
            <strong><i class="bi-scissors"></i> Emendar no cabo?</strong>
            <button class="ftth-painel-fechar" id="em-fechar">&times;</button>
        </div>
        <div class="ftth-modal-corpo">
            <p id="em-texto" style="margin:0 0 10px"></p>
            <p class="ftth-sub" style="margin:0">
                Se você emendar: o cabo é cortado em dois trechos que passam a chegar nesta
                caixa, <strong>a caixa encosta no traçado</strong> e todas as fibras atravessam
                como passagem — sem perda e sem mexer no que já estava fundido nas pontas.
                Depois, no diagrama da caixa, você troca por fusão ou sangria o que precisar.
            </p>
            <div id="em-saida" style="margin-top:10px"></div>
        </div>
        <div class="ftth-modal-rodape">
            <button class="ftth-btn ftth-btn--sec" id="em-nao">Deixar solta</button>
            <button class="ftth-btn ftth-btn--pri" id="em-sim">Emendar no cabo</button>
        </div>
    </div>
</div>

<!-- Modal de editar cabo: atributos só. O traçado não se mexe aqui (decisão de 22/09/2026). -->
<div id="modal-editar-cabo" class="ftth-modal" style="display:none">
    <div class="ftth-modal-caixa">
        <div class="ftth-modal-topo">
            <strong><i class="bi-share-fill"></i> <span id="ec-titulo">Editar cabo</span></strong>
            <button class="ftth-painel-fechar" id="ec-fechar">&times;</button>
        </div>
        <div class="ftth-modal-corpo">
            <p class="ftth-sub" id="ec-rota" style="margin:0 0 4px"></p>

            <label class="ftth-rotulo-campo">Nome do cabo (opcional)</label>
            <input id="ec-nome" class="ftth-campo" style="width:100%" maxlength="80">

            <label class="ftth-rotulo-campo">Fabricante (opcional)</label>
            <input id="ec-fabricante" class="ftth-campo" style="width:100%" maxlength="60">

            <div class="row g-3">
                <div class="col-7">
                    <label class="ftth-rotulo-campo">Capacidade (fibras)</label>
                    <select id="ec-tipo" class="ftth-campo" style="width:100%">
                        <?php foreach (Cabo::tipos() as $t): ?>
                            <option value="<?= (int) $t['id'] ?>"><?= htmlspecialchars($t['rotulo']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-5">
                    <label class="ftth-rotulo-campo">Padrão de cores</label>
                    <select id="ec-padrao" class="ftth-campo" style="width:100%">
                        <?php foreach (Cabo::PADROES_COR as $p): ?>
                            <option value="<?= $p ?>"><?= $p ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <label class="ftth-rotulo-campo">Cor da rota</label>
            <div class="ftth-cores" id="ec-cores">
                <?php foreach (['#00E676', '#00BCD4', '#0D47A1', '#FF9100', '#E53935',
                                '#8E24AA', '#FDD835', '#212121', '#9E9E9E'] as $cor): ?>
                    <button type="button" class="ftth-cor" data-cor="<?= $cor ?>"
                            style="background:<?= $cor ?>"></button>
                <?php endforeach; ?>
            </div>

            <label class="ftth-rotulo-campo">Situação</label>
            <select id="ec-status" class="ftth-campo" style="width:100%">
                <option value="projeto">Projeto</option>
                <option value="implantado">Implantado</option>
                <option value="certificado">Certificado</option>
            </select>

            <p class="ftth-sub" style="margin-top:10px">
                Para mudar o caminho do cabo no mapa, desenhe um cabo novo — aqui só mudam
                os dados dele.
            </p>

            <div id="ec-saida"></div>
        </div>
        <div class="ftth-modal-rodape">
            <button class="ftth-btn ftth-btn--sec" id="ec-cancelar">Cancelar</button>
            <button class="ftth-btn ftth-btn--pri" id="ec-salvar">Salvar cabo</button>
        </div>
    </div>
</div>

<!-- Clientes na porta: a grade do splitter de atendimento da caixa. É aqui que a
     documentação encontra o cadastro — cada porta aponta para um sis_cliente.id. -->
<div id="modal-clientes" class="ftth-modal" style="display:none">
    <div class="ftth-modal-caixa ftth-modal-caixa--larga">
        <div class="ftth-modal-topo">
            <strong><i class="bi-people-fill"></i> <span id="cl-titulo">Clientes na caixa</span></strong>
            <button class="ftth-painel-fechar" id="cl-fechar">&times;</button>
        </div>
        <div class="ftth-modal-corpo">
            <div id="cl-corpo"></div>
        </div>
        <div class="ftth-modal-rodape">
            <span class="ftth-sub" id="cl-resumo" style="margin:0 auto 0 0"></span>
            <button class="ftth-btn ftth-btn--sec" id="cl-cancelar">Fechar</button>
        </div>
    </div>
</div>

<!-- Rota óptica: o mesmo cálculo que o diagrama mostra na ponta da fibra, aqui a partir
     da caixa inteira. Quem investiga um cliente no mapa vê de onde vem o sinal sem ter
     de abrir o diagrama e caçar a ponta certa. -->
<div id="modal-rota" class="ftth-modal" style="display:none">
    <div class="ftth-modal-caixa">
        <div class="ftth-modal-topo">
            <strong><i class="bi-reception-4"></i> <span id="rt-titulo">Rota óptica</span></strong>
            <button class="ftth-painel-fechar" id="rt-fechar">&times;</button>
        </div>
        <div class="ftth-modal-corpo">
            <div id="rt-corpo"></div>
        </div>
        <div class="ftth-modal-rodape">
            <button class="ftth-btn ftth-btn--sec" id="rt-cancelar">Fechar</button>
        </div>
    </div>
</div>

<script>
window.FTTH_MAPA = {
    csrf:   <?= json_encode(ftth_csrf_token()) ?>,
    regiao: <?= $regiaoInicial ? (int) $regiaoInicial['id'] : 0 ?>,
    // Todas as regiões, com a moldura dos pontos: o mapa se posiciona por elas.
    regioes: <?= json_encode($regioes, JSON_UNESCAPED_UNICODE) ?>,
    tipo:   <?= json_encode((string) Config::get('mapa_tipo', 'hybrid')) ?>,
    rotulo_zoom:   <?= (int) Config::num('mapa_rotulo_zoom', 17) ?>,
    cabo_espessura:   <?= (int) Config::num('mapa_cabo_espessura', 5) ?>,
    cabo_espessura_q: <?= (int) Config::num('mapa_cabo_espessura_q', 4) ?>,
    // Uma fonte só para o desenho: o marcador e a ficha trocam o {cor} por conta própria.
    silhuetas: <?= json_encode(Caixa::SILHUETAS, JSON_UNESCAPED_SLASHES) ?>
};
</script>
<script src="js/mapa.js?v=<?= time() ?>"></script>
<?php if ($chave !== ''): ?>
<script async defer
    src="https://maps.googleapis.com/maps/api/js?key=<?= rawurlencode($chave) ?>&callback=ftthIniciarMapa&language=pt-BR&region=BR"></script>
<?php endif; ?>

<?php include('../../baixo.php'); ?>
<script src="../../menu.js<?= $ext_mk ?>"></script>
</body>
</html>
