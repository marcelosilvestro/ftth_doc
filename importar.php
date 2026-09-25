<?php
/**
 * ftth_doc :: importação de KMZ em quarentena.
 *
 * Nada do arquivo entra na rede sem revisão: o KMZ vira itens 'pendentes' e o usuário
 * importa item a item ou em lote. Itens com alerta ficam de fora do lote por padrão.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/Regiao.php';
require_once __DIR__ . '/lib/Kmz.php';
require_once __DIR__ . '/lib/Quarentena.php';
require_once __DIR__ . '/lib/Upload.php';

const FTTH_MAX_KMZ_BYTES = 33554432; // 32 MB

/* ------------------------------------------------------------------ AJAX */
if (isset($_GET['ajax'])) {
    try {
        $acao = (string) $_GET['ajax'];

        if ($acao === 'estado') {
            $regiaoId = (int) ($_GET['regiao'] ?? 0);
            Resultado::ok([
                'regioes'     => Regiao::listar(),
                'contadores'  => $regiaoId ? Quarentena::contar($regiaoId) : null,
                'importacoes' => $regiaoId ? Db::todos(
                    'SELECT id, arquivo, total_itens, pendentes, importados, descartados, alertas,
                            criado_por, criado_em
                       FROM tab_ftth_importacao WHERE regiao_id = ? ORDER BY id DESC LIMIT 10',
                    [$regiaoId]) : [],
            ])->enviar();
        }

        if ($acao === 'itens') {
            $regiaoId = (int) ($_GET['regiao'] ?? 0);
            $status   = in_array($_GET['status'] ?? 'pendente', ['pendente', 'importado', 'descartado'], true)
                        ? $_GET['status'] : 'pendente';
            $itens = Quarentena::listar($regiaoId, $status, 1000);
            // Geometria completa é pesada: a lista só precisa do primeiro ponto e do total.
            foreach ($itens as &$i) {
                $g = json_decode((string) $i['geometria'], true) ?: [];
                $i['pontos']    = count($g);
                $i['lat']       = $g[0][0] ?? null;
                $i['lng']       = $g[0][1] ?? null;
                $i['alertas']   = json_decode((string) $i['alertas_json'], true) ?: [];
                unset($i['geometria'], $i['alertas_json'], $i['hash_geometria']);
            }
            Resultado::ok(['itens' => $itens, 'contadores' => Quarentena::contar($regiaoId)])->enviar();
        }

        if ($acao === 'enviar') {
            ftth_exigir_csrf();
            $regiaoId = (int) ($_POST['regiao'] ?? 0);
            if (!Regiao::obter($regiaoId)) {
                Log::aviso('kmz.enviar.regiao_invalida', ['regiao' => $regiaoId]);
                Resultado::erro('FTTH-SYS-002', ['campo' => 'regiao'], 'Selecione uma região.')->enviar(400);
            }
            $destino = ftth_dir('uploads');   // fora da pasta do addon: AppArmor nao deixa gravar la
            if ($destino === null) {
                Log::erro('kmz.enviar.sem_pasta', ['destino' => FTTH_DIR_DADOS . '/uploads']);
                Resultado::erro('FTTH-SYS-001',
                    ['detalhe' => 'Pasta de dados indisponível: ' . FTTH_DIR_DADOS . '/uploads'])->enviar(500);
            }
            $rec = Upload::receber($_FILES['arquivo'] ?? [], ['kmz', 'kml'],
                $destino, FTTH_MAX_KMZ_BYTES);
            if (!$rec['ok']) {
                // Sem este log, uma recusa de upload vira adivinhação.
                Log::aviso('kmz.enviar.upload_recusado', [
                    'detalhe' => $rec['detalhe'] ?? '',
                    'nome'    => $_FILES['arquivo']['name'] ?? null,
                    'tamanho' => $_FILES['arquivo']['size'] ?? null,
                    'erro_php'=> $_FILES['arquivo']['error'] ?? null,
                ]);
                Resultado::erro($rec['code'], ['detalhe' => $rec['detalhe']])->enviar(400);
            }
            try {
                $r = Kmz::importarParaQuarentena($rec['caminho'], $rec['nome_original'], $regiaoId,
                    $usuario_logado, !empty($_POST['forcar']));
                Log::info('kmz.importar', ['arquivo' => $rec['nome_original']] + $r['resumo']);
                Resultado::ok($r)->enviar();
            } catch (RuntimeException $e) {
                @unlink($rec['caminho']);
                [$code, $extra] = array_pad(explode(':', $e->getMessage(), 2), 2, null);
                if (!Erros::existe($code)) {
                    throw $e;
                }
                Resultado::erro($code, ['anterior' => $extra ? json_decode($extra, true) : null])->enviar(409);
            }
        }

        if ($acao === 'importar') {
            ftth_exigir_csrf();
            $ids = array_map('intval', (array) ($_POST['ids'] ?? []));
            $r = count($ids) === 1
                ? Quarentena::importarItem($ids[0], $usuario_logado, (array) ($_POST['ajustes'] ?? []))
                : Quarentena::importarLote($ids, $usuario_logado, !empty($_POST['incluir_alerta']));
            $r->enviar();
        }

        if ($acao === 'descartar') {
            ftth_exigir_csrf();
            $ids = array_map('intval', (array) ($_POST['ids'] ?? []));
            Quarentena::descartar($ids, $usuario_logado, (string) ($_POST['motivo'] ?? ''))->enviar();
        }

        Resultado::erro('FTTH-SYS-002')->enviar(400);
    } catch (Throwable $e) {
        Log::excecao('importar.ajax', $e, ['ajax' => $_GET['ajax'] ?? '']);
        Resultado::erro('FTTH-SYS-001')->enviar(500);
    }
}

/* ------------------------------------------------------------------ página */
$regioes = [];
$falha   = null;
try {
    $regioes = Regiao::listar();
} catch (Throwable $e) {
    Log::excecao('importar.carregar', $e);
    $falha = 'Não foi possível carregar as regiões. FTTH-SYS-001 · ' . Resultado::requestId();
}

include('nav/header.php');
?>
<body>
<?php include('../../topo.php'); ?>

<!-- Tela compacta (25/09/2026): cabeçalho numa linha, envio numa linha, e quarentena + itens
     num card só — contadores, abas e ações em lote na mesma barra, em cima da tabela. -->
<div class="ftth-wrap ftth-imp">
    <div class="ftth-imp-cab">
        <a class="ftth-btn ftth-btn--sec" href="mapa.php"><i class="bi-arrow-left"></i> Mapa</a>
        <div>
            <h1 class="ftth-titulo"><i class="bi-box-seam"></i> Importar KMZ</h1>
            <p class="ftth-sub">O arquivo entra em <strong>quarentena</strong>: nada vai para a rede antes
                de você conferir. Itens com alerta ficam de fora da importação em lote.</p>
        </div>
    </div>

    <?php if ($falha): ?><div class="ftth-aviso ftth-aviso--erro"><?= htmlspecialchars($falha) ?></div><?php endif; ?>

    <div class="ftth-card ftth-imp-envio">
        <div class="ftth-imp-campo">
            <label class="ftth-rotulo-campo" for="sel-regiao">Região</label>
            <select id="sel-regiao" class="ftth-campo">
                <option value="">— selecione —</option>
                <?php foreach ($regioes as $r): ?>
                    <option value="<?= (int) $r['id'] ?>">
                        <?= htmlspecialchars($r['nome']) ?>
                        (<?= (int) $r['caixas'] ?> caixas<?= $r['quarentena'] ? ', ' . (int) $r['quarentena'] . ' na quarentena' : '' ?>)
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="ftth-imp-campo">
            <label class="ftth-rotulo-campo" for="arq">Arquivo KMZ ou KML (até 32 MB)</label>
            <input type="file" id="arq" class="ftth-imp-arquivo" accept=".kmz,.kml">
        </div>
        <button class="ftth-btn ftth-btn--pri" id="btn-enviar"><i class="bi-upload"></i> Enviar</button>
        <label class="ftth-sub ftth-imp-chk">
            <input type="checkbox" id="forcar"> importar mesmo se o arquivo já foi usado
        </label>
        <?php if (!$regioes): ?>
            <p class="ftth-sub ftth-imp-linha">Nenhuma região cadastrada ainda: crie a primeira no mapa.</p>
        <?php endif; ?>
        <div id="saida-envio" class="ftth-imp-linha"></div>
    </div>

    <div class="ftth-card" id="card-itens" style="display:none">
        <div class="ftth-imp-topo">
            <h2>Quarentena</h2>
            <div id="contadores" class="ftth-imp-contadores"></div>
        </div>
        <div class="ftth-imp-barra">
            <div class="ftth-imp-abas">
                <button class="btn-status ativo" data-status="pendente">Pendentes</button>
                <button class="btn-status" data-status="importado">Importados</button>
                <button class="btn-status" data-status="descartado">Descartados</button>
            </div>
            <!-- Em lote só se age sobre pendentes: nas outras abas a barra fica só com as abas. -->
            <div class="ftth-imp-lote" id="imp-lote">
                <span class="ftth-sub" id="contagem-sel"></span>
                <label class="ftth-sub ftth-imp-chk">
                    <input type="checkbox" id="incluir-alerta"> incluir itens com alerta
                </label>
                <button class="ftth-btn ftth-btn--sec" id="btn-descartar-sel" disabled>Descartar selecionados</button>
                <button class="ftth-btn ftth-btn--pri" id="btn-importar-sel" disabled>Importar selecionados</button>
            </div>
        </div>
        <div id="saida-lote"></div>
        <div class="ftth-imp-tabela">
            <table class="ftth-tabela" id="tabela-itens">
                <thead><tr>
                    <th style="width:26px"><input type="checkbox" id="sel-todos"></th>
                    <th>Nome</th><th>Tipo</th><th>Detalhe</th><th>Situação</th><th style="width:1%"></th>
                </tr></thead>
                <tbody></tbody>
            </table>
        </div>
    </div>

    <div class="ftth-card" id="card-importacoes" style="display:none">
        <h2>Arquivos já enviados</h2>
        <table class="ftth-tabela" id="tabela-importacoes"><tbody></tbody></table>
    </div>
</div>

<script>
(function () {
    var CSRF = <?= json_encode(ftth_csrf_token()) ?>;
    var regiao = 0, status = 'pendente', itens = [];

    function esc(s) {
        return String(s === null || s === undefined ? '' : s)
            .replace(/[&<>"]/g, function (c) {
                return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c];
            });
    }
    function aviso(sel, texto, tipo) {
        $(sel).html('<div class="ftth-aviso ' + (tipo || '') + '">' + String(texto).replace(/\n/g, '<br>') + '</div>');
    }

    function carregarEstado() {
        FTTH.chamar({
            url: 'importar.php?ajax=estado&regiao=' + regiao,
            onOk: function (d) {
                if (d.contadores) {
                    var c = d.contadores;
                    $('#contadores').html(
                        '<span class="ftth-selo ftth-selo--novo">' + (c.pendentes || 0) + ' pendentes</span> ' +
                        '<span class="ftth-selo ftth-selo--ok">' + (c.importados || 0) + ' importados</span> ' +
                        '<span class="ftth-selo ftth-selo--pend">' + (c.com_alerta || 0) + ' com alerta</span> ' +
                        '<span class="ftth-selo ftth-selo--info">' + (c.descartados || 0) + ' descartados</span>');
                }
                var html = '';
                (d.importacoes || []).forEach(function (i) {
                    html += '<tr><td class="mono">' + esc(i.arquivo) + '</td>'
                         +  '<td>' + i.total_itens + ' itens</td>'
                         +  '<td>' + i.importados + ' importados · ' + i.pendentes + ' pendentes · '
                         +  i.descartados + ' descartados</td>'
                         +  '<td class="ftth-sub">' + esc(i.criado_por) + ' · ' + esc(i.criado_em) + '</td></tr>';
                });
                $('#tabela-importacoes tbody').html(html);
                $('#card-importacoes').toggle(html !== '');
            },
            onErro: function (m) { aviso('#saida-envio', m, 'ftth-aviso--erro'); }
        });
    }

    function carregarItens() {
        if (!regiao) { $('#card-itens').hide(); return; }
        FTTH.chamar({
            url: 'importar.php?ajax=itens&regiao=' + regiao + '&status=' + status,
            onOk: function (d) {
                itens = d.itens || [];
                desenharItens();
                $('#card-itens').show();
            },
            onErro: function (m) { aviso('#saida-lote', m, 'ftth-aviso--erro'); }
        });
    }

    function desenharItens() {
        var html = '';
        itens.forEach(function (i) {
            var alertas = (i.alertas || []).map(function (a) {
                return '<span class="ftth-selo ftth-selo--pend" title="' + esc(a.code) + '">' + esc(a.message) + '</span>';
            }).join(' ');
            var situacao = i.status === 'pendente'
                ? (alertas || '<span class="ftth-selo ftth-selo--novo">não revisado</span>')
                : (i.status === 'importado'
                    ? '<span class="ftth-selo ftth-selo--ok">importado</span>'
                    : '<span class="ftth-selo ftth-selo--info">descartado</span>');
            var detalhe = i.tipo_sugerido === 'VAO'
                ? esc(i.subtipo || '') + ' · ' + i.pontos + ' vértices'
                : esc(i.subtipo || '');
            html += '<tr data-id="' + i.id + '">'
                 +  '<td>' + (i.status === 'pendente' ? '<input type="checkbox" class="chk" value="' + i.id + '">' : '') + '</td>'
                 +  '<td class="mono">' + esc(i.nome || '(sem nome)') + '</td>'
                 +  '<td>' + esc(i.tipo_sugerido) + '</td>'
                 +  '<td>' + detalhe + '</td>'
                 +  '<td>' + situacao + '</td>'
                 +  '<td class="ftth-imp-acoes">' + (i.status === 'pendente'
                        ? '<button class="ftth-btn ftth-btn--sec btn-item" data-acao="importar" data-id="' + i.id + '">Importar</button> '
                        + '<button class="ftth-btn ftth-btn--sec btn-item" data-acao="descartar" data-id="' + i.id + '">Descartar</button>'
                        : '') + '</td></tr>';
        });
        $('#tabela-itens tbody').html(html || '<tr><td colspan="6" class="ftth-sub">Nenhum item.</td></tr>');
        atualizarSelecao();
    }

    function selecionados() {
        return $('.chk:checked').map(function () { return parseInt(this.value, 10); }).get();
    }
    function atualizarSelecao() {
        var n = selecionados().length;
        $('#contagem-sel').text(n ? n + ' selecionado(s)' : '');
        $('#btn-importar-sel, #btn-descartar-sel').prop('disabled', n === 0);
    }

    function agir(ids, acao, extra) {
        var dados = { csrf: CSRF, ids: ids };
        if (extra) $.extend(dados, extra);
        FTTH.chamar({
            url: 'importar.php?ajax=' + acao,
            method: 'POST',
            data: dados,
            onOk: function (d) {
                var msg;
                if (acao === 'descartar') {
                    msg = d.descartados + ' item(ns) descartado(s).';
                } else if (d.importados) {
                    msg = d.importados.length + ' item(ns) importado(s).';
                    if (d.pulados && d.pulados.length) {
                        msg += ' ' + d.pulados.length + ' pulado(s): '
                             + d.pulados.slice(0, 5).map(function (p) { return '#' + p.id + ' (' + p.motivo + ')'; }).join(', ');
                    }
                } else {
                    msg = 'Item importado: ' + esc(d.tipo) + ' #' + d.id + '.';
                }
                aviso('#saida-lote', msg, 'ftth-aviso--ok');
                carregarItens();
                carregarEstado();
            },
            onErro: function (m) { aviso('#saida-lote', m, 'ftth-aviso--erro'); }
        });
    }

    $('#sel-regiao').on('change', function () {
        regiao = parseInt(this.value, 10) || 0;
        carregarEstado();
        carregarItens();
    });

    $('#btn-enviar').on('click', function () {
        var arq = document.getElementById('arq').files[0];
        if (!regiao) { aviso('#saida-envio', 'Selecione a região antes.', 'ftth-aviso--erro'); return; }
        if (!arq)    { aviso('#saida-envio', 'Escolha um arquivo.', 'ftth-aviso--erro'); return; }

        var fd = new FormData();
        fd.append('csrf', CSRF);
        fd.append('regiao', regiao);
        fd.append('arquivo', arq);
        if ($('#forcar').is(':checked')) fd.append('forcar', '1');

        var $b = $(this).prop('disabled', true).text('Enviando…');
        FTTH.chamar({
            url: 'importar.php?ajax=enviar',
            method: 'POST',
            data: fd,
            onOk: function (d) {
                var s = d.resumo;
                aviso('#saida-envio', 'Arquivo lido: ' + s.pontos + ' pontos e ' + s.linhas + ' linhas ('
                    + (s.metros / 1000).toFixed(1) + ' km). ' + s.total + ' itens em quarentena, '
                    + s.alertas + ' com alerta.', 'ftth-aviso--ok');
                carregarEstado(); carregarItens();
            },
            onErro: function (m) { aviso('#saida-envio', m, 'ftth-aviso--erro'); }
        }).always(function () { $b.prop('disabled', false).html('<i class="bi-upload"></i> Enviar'); });
    });

    $(document).on('change', '.chk', atualizarSelecao);
    $('#sel-todos').on('change', function () {
        $('.chk').prop('checked', this.checked);
        atualizarSelecao();
    });
    $(document).on('click', '.btn-item', function () {
        var id = parseInt($(this).data('id'), 10);
        var acao = $(this).data('acao');
        if (acao === 'descartar' && !confirm('Descartar este item?')) return;
        agir([id], acao);
    });
    $('#btn-importar-sel').on('click', function () {
        var ids = selecionados();
        if (!confirm('Importar ' + ids.length + ' item(ns) para a rede?')) return;
        agir(ids, 'importar', $('#incluir-alerta').is(':checked') ? { incluir_alerta: 1 } : null);
    });
    $('#btn-descartar-sel').on('click', function () {
        var ids = selecionados();
        if (!confirm('Descartar ' + ids.length + ' item(ns)?')) return;
        agir(ids, 'descartar');
    });
    $('.btn-status').on('click', function () {
        status = $(this).data('status');
        $('.btn-status').removeClass('ativo');
        $(this).addClass('ativo');
        $('#imp-lote').toggle(status === 'pendente');
        $('#sel-todos').prop('checked', false).toggle(status === 'pendente');
        $('#saida-lote').empty();
        carregarItens();
    });
})();
</script>

<?php include('../../baixo.php'); ?>
<script src="../../menu.js<?= $ext_mk ?>"></script>
</body>
</html>
