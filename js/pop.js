/**
 * ftth_doc :: POP / Data Center.
 *
 * A tela desenha os cards de OLT e DIO a partir de um único `estado` recarregado por AJAX —
 * padrão do importar.php, porque tudo aqui depende de um POP escolhido.
 *
 * O único ponto que sai do CRUD é a "saída para a rua": o seletor grava uma ligação de
 * verdade no grafo, a mesma que o diagrama criaria. Por isso ele fala com `definir_saida`
 * e não com o `alterar_porta`.
 */
(function () {
    'use strict';

    var estado = { olts: [], dios: [], saidas: [], porta: null, placas: [], oltEditando: null };

    function esc(s) { return FTTH.esc(s); }

    function aviso(sel, texto, tipo) {
        $(sel).html('<div class="ftth-aviso ' + (tipo || '') + '">' +
                    esc(texto).replace(/\n/g, '<br>') + '</div>');
    }

    /* ------------------------------------------------------------------ carga */

    function carregar(aoTerminar) {
        FTTH.chamar({
            url: 'pop.php?ajax=estado&caixa=' + CAIXA,
            onOk: function (d) {
                estado.olts   = d.olts   || [];
                estado.dios   = d.dios   || [];
                estado.saidas = d.saidas || [];
                desenharOlts();
                desenharDios();
                if (aoTerminar) aoTerminar();
            },
            onErro: function (m) { aviso('#saida', m, 'ftth-aviso--erro'); }
        });
    }

    /* ------------------------------------------------------------------ OLT */

    function desenharOlts() {
        var html = '';
        estado.olts.forEach(function (o) {
            var nativa = o.nativa
                ? esc(o.nativa.name) + ' · ' + esc(o.nativa.ipaddress) + ':' + esc(o.nativa.access_port)
                : '<span class="ftth-sub">sem vínculo com o cadastro</span>';

            html += '<div class="ftth-card ftth-card--olt">'
                 +  '<h2><i class="bi-hdd-network-fill"></i> ' + esc(o.apelido) + '</h2>'
                 +  '<dl class="ftth-ficha">'
                 +    '<dt>Fabricante</dt><dd>' + esc(o.fabricante || (o.nativa ? o.nativa.maker : '—')) + '</dd>'
                 +    '<dt>Host</dt><dd>' + nativa + '</dd>'
                 +    '<dt>Capacidade</dt><dd>' + o.pons.length + ' portas PON em ' + o.placas.length + ' placa(s)</dd>'
                 +    '<dt>Potência</dt><dd>' + Number(o.ptx_dbm).toFixed(2) + ' dBm · classe ' + esc(o.classe_optica) + '</dd>'
                 +    '<dt>Acesso</dt><dd>' + (o.acesso === 'simulado'
                        ? '<span class="ftth-selo ftth-selo--pend">modo simulado</span>' : esc(o.acesso)) + '</dd>'
                 +  '</dl>';

            html += '<div class="ftth-pop-acoes">'
                 +  '<button class="ftth-btn ftth-btn--sec js-olt-editar" data-id="' + o.id + '">'
                 +  '<i class="bi-gear"></i> Configurar</button>'
                 +  '<button class="ftth-btn ftth-btn--sec js-olt-excluir" data-id="' + o.id
                 +  '" data-versao="' + o.versao + '"><i class="bi-trash3"></i> Excluir</button></div>';
            html += '</div>';
        });
        $('#lista-olts').html(html);
    }

    function oltPorId(id) {
        for (var i = 0; i < estado.olts.length; i++) {
            if (parseInt(estado.olts[i].id, 10) === parseInt(id, 10)) return estado.olts[i];
        }
        return null;
    }

    function abrirModalOlt(o) {
        estado.oltEditando = o;
        estado.placas = o ? o.placas.map(function (p) {
            return { prefixo: p.prefixo, portas: parseInt(p.portas, 10), inicio: parseInt(p.inicio, 10) };
        }) : [{ prefixo: '0/1', portas: 16, inicio: 1 }];

        var opc = '<option value="0">— sem vínculo —</option>';
        NATIVAS.forEach(function (n) {
            opc += '<option value="' + n.id + '">' + esc(n.name) + ' (' + esc(n.maker) + ')</option>';
        });
        $('#olt-nativa').html(opc).val(o && o.olt_id ? o.olt_id : '0');

        var fab = '<option value="">—</option>';
        FABRICANTES.forEach(function (f) { fab += '<option>' + esc(f) + '</option>'; });
        fab += '<option value="Outro">Outro</option>';
        $('#olt-fabricante').html(fab).val(o && o.fabricante ? o.fabricante : '');

        $('#olt-titulo').text(o ? 'Configurar ' + o.apelido : 'Vincular nova OLT');
        $('#olt-apelido').val(o ? o.apelido : '');
        $('#olt-ptx').val(o ? Number(o.ptx_dbm).toFixed(2) : '3.00');
        $('#olt-saida').empty();
        desenharPlacas();
        $('#modal-olt').show();
    }

    function desenharPlacas() {
        var html = '';
        estado.placas.forEach(function (p, i) {
            html += '<div class="ftth-pop-placa">'
                 +  '<label class="ftth-sub">Prefixo/slot<input class="ftth-campo js-placa" data-i="' + i
                 +  '" data-campo="prefixo" value="' + esc(p.prefixo) + '" maxlength="20"></label>'
                 +  '<label class="ftth-sub">Portas<input class="ftth-campo js-placa" data-i="' + i
                 +  '" data-campo="portas" type="number" min="1" max="64" value="' + p.portas + '"></label>'
                 +  '<label class="ftth-sub">Início<input class="ftth-campo js-placa" data-i="' + i
                 +  '" data-campo="inicio" type="number" min="0" max="64" value="' + p.inicio + '"></label>'
                 +  '<button class="ftth-btn ftth-btn--sec js-placa-remover" data-i="' + i
                 +  '" title="Remover placa"><i class="bi-trash3"></i></button></div>';
        });
        if (!estado.placas.length) {
            html = '<p class="ftth-sub">Nenhuma placa — o seletor de PON da porta ficará vazio.</p>';
        }
        $('#olt-placas').html(html);
    }

    function salvarOlt() {
        var o = estado.oltEditando;
        var $b = $('#btn-olt-salvar').prop('disabled', true);

        FTTH.chamar({
            url: 'pop.php?ajax=' + (o ? 'alterar_olt' : 'criar_olt'),
            method: 'POST',
            data: {
                csrf: CSRF, caixa: CAIXA, olt: o ? o.id : 0, versao: o ? o.versao : null,
                apelido: $('#olt-apelido').val(),
                fabricante: $('#olt-fabricante').val(),
                olt_id: $('#olt-nativa').val(),
                ptx_dbm: $('#olt-ptx').val(),
                placas: JSON.stringify(estado.placas)
            },
            onOk: function () {
                $('#modal-olt').hide();
                carregar(function () { aviso('#saida', 'OLT salva.', 'ftth-aviso--ok'); });
            },
            onErro: function (m) { aviso('#olt-saida', m, 'ftth-aviso--erro'); }
        }).always(function () { $b.prop('disabled', false); });
    }

    /* ------------------------------------------------------------------ DIO e grade */

    function desenharDios() {
        var html = '';
        estado.dios.forEach(function (d) {
            var usadas = 0;
            d.portas_lista.forEach(function (p) { if (p.status !== 'vazia') usadas++; });

            html += '<div class="ftth-card">'
                 +  '<h2><i class="bi-grid-3x3-gap-fill"></i> ' + esc(d.nome)
                 +  ' <span class="ftth-selo ftth-selo--info">' + d.portas + ' portas</span>'
                 +  ' <span class="ftth-selo ' + (parseInt(d.ativo, 10) ? 'ftth-selo--ok' : 'ftth-selo--pend') + '">'
                 +  (parseInt(d.ativo, 10) ? 'painel ativo' : 'inativo') + '</span></h2>'
                 +  '<p class="ftth-sub">' + usadas + ' de ' + d.portas + ' portas em uso · '
                 +  'cada cartão traz equipamento, serviço e saída para a rua</p>'
                 +  '<div class="ftth-portas">';

            d.portas_lista.forEach(function (p) {
                html += '<button class="ftth-porta ftth-porta--' + p.status + ' js-porta" data-id="' + p.id + '">'
                     +  '<span class="ftth-porta-topo">' + esc(p.rotulo) + '<i class="ftth-porta-luz"></i></span>'
                     +  '<span class="ftth-porta-linha">' + (p.olt_apelido
                            ? esc(p.olt_apelido) + (p.pon ? ' · ' + esc(p.pon) : '') : '—') + '</span>'
                     +  '<span class="ftth-porta-linha">' + (p.servico ? esc(p.servico) : '—') + '</span>'
                     +  '<span class="ftth-porta-linha">' + (p.saida ? esc(p.saida.rotulo) : '—') + '</span>'
                     +  '</button>';
            });

            html += '</div>';
            html += '<div class="ftth-pop-acoes" style="margin-top:12px">'
                 +  '<button class="ftth-btn ftth-btn--sec js-dio-editar" data-id="' + d.id + '">'
                 +  '<i class="bi-pencil"></i> Renomear / capacidade</button>'
                 +  '<button class="ftth-btn ftth-btn--sec js-dio-excluir" data-id="' + d.id
                 +  '" data-versao="' + d.versao + '"><i class="bi-trash3"></i> Excluir</button></div>';
            html += '</div>';
        });
        $('#lista-dios').html(html || '<div class="ftth-card"><p class="ftth-sub">'
            + 'Nenhum DIO instalado neste POP ainda.</p></div>');
    }

    function portaPorId(id) {
        for (var i = 0; i < estado.dios.length; i++) {
            var lista = estado.dios[i].portas_lista;
            for (var j = 0; j < lista.length; j++) {
                if (parseInt(lista[j].id, 10) === parseInt(id, 10)) return lista[j];
            }
        }
        return null;
    }

    function abrirPorta(id) {
        var p = portaPorId(id);
        if (!p) return;
        estado.porta = p;

        // Equipamento: cada PON de cada OLT vira uma opção.
        var opc = '<option value="||">— sem equipamento —</option>';
        estado.olts.forEach(function (o) {
            o.pons.forEach(function (pon) {
                opc += '<option value="' + o.id + '|' + esc(pon) + '">'
                     + esc(o.apelido) + ' · ' + esc(pon) + '</option>';
            });
        });
        $('#porta-equipamento').html(opc)
            .val(p.olt_ftth_id ? (p.olt_ftth_id + '|' + (p.pon || '')) : '||');

        // Saída OSP: fibras livres mais a que esta porta já usa.
        var saidas = '<option value="|">— sem saída —</option>';
        estado.saidas.forEach(function (s) {
            var atual = p.saida && parseInt(p.saida.vao_id, 10) === parseInt(s.vao_id, 10)
                        && parseInt(p.saida.numero, 10) === parseInt(s.numero, 10);
            if (s.estado !== 'livre' && !atual) return;   // ocupada por outra ponta
            saidas += '<option value="' + s.vao_id + '|' + s.numero + '"' + (atual ? ' selected' : '') + '>'
                    + esc(s.rotulo) + '</option>';
        });
        $('#porta-saida').html(saidas);

        $('#porta-titulo').text('Porta ' + p.rotulo);
        $('#porta-operacao').val(p.operacao);
        $('#porta-servico').val(p.servico || '');
        $('#porta-ptx').val(p.ptx_dbm === null ? '' : Number(p.ptx_dbm).toFixed(2));
        $('#porta-saida-msg').empty();
        $('#painel-porta').show();
    }

    /**
     * Salva a porta em dois passos: os dados internos e, se mudou, a saída.
     * São operações diferentes — uma é UPDATE de cadastro, a outra mexe no grafo.
     */
    function salvarPorta() {
        var p = estado.porta;
        if (!p) return;

        var equip = ($('#porta-equipamento').val() || '||').split('|');
        var $b = $('#btn-porta-salvar').prop('disabled', true);

        FTTH.chamar({
            url: 'pop.php?ajax=alterar_porta',
            method: 'POST',
            data: {
                csrf: CSRF, porta: p.id, versao: p.versao,
                operacao: $('#porta-operacao').val(),
                olt_ftth_id: equip[0] || 0,
                pon: equip[1] || '',
                servico: $('#porta-servico').val(),
                ptx_dbm: $('#porta-ptx').val()
            },
            onOk: function () { salvarSaida(p); },
            onErro: function (m) {
                aviso('#porta-saida-msg', m, 'ftth-aviso--erro');
                $b.prop('disabled', false);
            }
        });
    }

    function salvarSaida(p) {
        var escolha = ($('#porta-saida').val() || '|').split('|');
        var vao  = parseInt(escolha[0], 10) || 0;
        var num  = parseInt(escolha[1], 10) || 0;
        var tinha = p.saida ? (parseInt(p.saida.vao_id, 10) + '|' + parseInt(p.saida.numero, 10)) : '0|0';

        if ((vao + '|' + num) === tinha) {          // nada mudou na saída
            $('#btn-porta-salvar').prop('disabled', false);
            $('#painel-porta').hide();
            carregar(function () { aviso('#saida', 'Porta ' + p.rotulo + ' salva.', 'ftth-aviso--ok'); });
            return;
        }

        FTTH.chamar({
            url: 'pop.php?ajax=definir_saida',
            method: 'POST',
            data: { csrf: CSRF, porta: p.id, vao: vao, numero: num },
            onOk: function () {
                $('#painel-porta').hide();
                carregar(function () { aviso('#saida', 'Porta ' + p.rotulo + ' salva.', 'ftth-aviso--ok'); });
            },
            onErro: function (m) { aviso('#porta-saida-msg', m, 'ftth-aviso--erro'); }
        }).always(function () { $('#btn-porta-salvar').prop('disabled', false); });
    }

    /* ------------------------------------------------------------------ eventos */

    $(function () {
        carregar();

        $('#btn-nova-olt').on('click', function () { abrirModalOlt(null); });
        $(document).on('click', '.js-olt-editar', function () {
            abrirModalOlt(oltPorId($(this).attr('data-id')));
        });
        $('#btn-olt-cancelar, #btn-olt-fechar').on('click', function () { $('#modal-olt').hide(); });
        $('#btn-olt-salvar').on('click', salvarOlt);

        $('#btn-add-placa').on('click', function () {
            estado.placas.push({ prefixo: '', portas: 16, inicio: 1 });
            desenharPlacas();
        });
        $(document).on('click', '.js-placa-remover', function () {
            estado.placas.splice(parseInt($(this).attr('data-i'), 10), 1);
            desenharPlacas();
        });
        $(document).on('change', '.js-placa', function () {
            var i = parseInt($(this).attr('data-i'), 10);
            var campo = $(this).attr('data-campo');
            estado.placas[i][campo] = campo === 'prefixo' ? $(this).val() : parseInt($(this).val(), 10);
        });

        $(document).on('click', '.js-olt-excluir', function () {
            var id = $(this).attr('data-id'), v = $(this).attr('data-versao');
            var o = oltPorId(id);
            if (!window.confirm('Excluir a OLT ' + (o ? o.apelido : '') + '?')) return;
            FTTH.chamar({
                url: 'pop.php?ajax=excluir_olt', method: 'POST',
                data: { csrf: CSRF, olt: id, versao: v },
                onOk: function () { carregar(function () { aviso('#saida', 'OLT excluída.', 'ftth-aviso--ok'); }); },
                onErro: function (m) { aviso('#saida', m, 'ftth-aviso--erro'); }
            });
        });

        $('#btn-novo-dio').on('click', function () {
            $('#dio-titulo').text('Instalar novo DIO');
            $('#dio-nome').val('');
            $('#dio-portas').val(24);
            $('#dio-saida').empty();
            $('#modal-dio').data('id', 0).show();
        });
        $(document).on('click', '.js-dio-editar', function () {
            var id = $(this).attr('data-id');
            var d = null;
            estado.dios.forEach(function (x) { if (parseInt(x.id, 10) === parseInt(id, 10)) d = x; });
            if (!d) return;
            $('#dio-titulo').text('Alterar ' + d.nome);
            $('#dio-nome').val(d.nome);
            $('#dio-portas').val(d.portas);
            $('#dio-saida').empty();
            $('#modal-dio').data('id', d.id).data('versao', d.versao).show();
        });
        $('#btn-dio-cancelar, #btn-dio-fechar').on('click', function () { $('#modal-dio').hide(); });

        $('#btn-dio-salvar').on('click', function () {
            var id = $('#modal-dio').data('id');
            var $b = $(this).prop('disabled', true);
            FTTH.chamar({
                url: 'pop.php?ajax=' + (id ? 'alterar_dio' : 'criar_dio'),
                method: 'POST',
                data: { csrf: CSRF, caixa: CAIXA, dio: id, versao: $('#modal-dio').data('versao'),
                        nome: $('#dio-nome').val(), portas: $('#dio-portas').val() },
                onOk: function () {
                    $('#modal-dio').hide();
                    carregar(function () { aviso('#saida', 'DIO salvo.', 'ftth-aviso--ok'); });
                },
                onErro: function (m) { aviso('#dio-saida', m, 'ftth-aviso--erro'); }
            }).always(function () { $b.prop('disabled', false); });
        });

        $(document).on('click', '.js-dio-excluir', function () {
            var id = $(this).attr('data-id'), v = $(this).attr('data-versao');
            if (!window.confirm('Excluir este DIO?\n\nSó sai se nenhuma porta tiver fibra ligada.')) return;
            FTTH.chamar({
                url: 'pop.php?ajax=excluir_dio', method: 'POST',
                data: { csrf: CSRF, dio: id, versao: v },
                onOk: function () { carregar(function () { aviso('#saida', 'DIO excluído.', 'ftth-aviso--ok'); }); },
                onErro: function (m) { aviso('#saida', m, 'ftth-aviso--erro'); }
            });
        });

        $(document).on('click', '.js-porta', function () {
            abrirPorta($(this).attr('data-id'));
        });
        $('#btn-porta-fechar').on('click', function () { $('#painel-porta').hide(); });
        $('#btn-porta-salvar').on('click', salvarPorta);

        $(document).on('keydown', function (ev) {
            if (ev.key === 'Escape') {
                $('#painel-porta, #modal-olt, #modal-dio').hide();
            }
        });
    });
})();
