/* ftth_doc :: mapa (Google Maps).
 *
 * Carrega por área visível: ao parar de mover o mapa, pede só o que está na tela.
 * Itens em quarentena vêm translúcidos — são "não revisados" e não fazem parte da rede ainda.
 */
(function () {
    'use strict';

    var mapa, infoAtual = null;
    var marcadores = [], linhas = [], quarentenaObj = [];
    var camadas = { CTO: true, CEO: true, OUTRAS: true, CABOS: true, QUARENTENA: true };
    var esperando = null, regiao = 0;
    var modo = 'navegar';                       // navegar | caixa
    var pontoNovo = null;                       // onde o usuário marcou o pin
    var pinTemporario = null;                   // marcador provisório mostrando esse ponto
    var editando = null;                        // {id, versao} quando o modal está editando
    var tracado = [];                           // pontos do cabo sendo desenhado
    var linhaTracado = null, pinosTracado = [];  // desenho provisório
    var caboAberto = null;                       // vão cuja ficha está aberta no painel
    // Modo Mover: nada vai para o banco antes do Concluir (decisão de 22/09/2026).
    var pendentes = { caixas: {}, vaos: {} };
    var ultimoDesenho = null;                    // último payload, para saber quem toca quem

    /** Lado do marcador no mapa, em px. O desenho continua num viewBox de 24. */
    var PX_ICONE = 30;

    /*
     * Os seis tipos do seletor usam a silhueta da peça real (Caixa::SILHUETAS, no PHP).
     * A `forma` aqui só vale para os tipos legados — poste, cliente e CTO AP saíram do
     * menu mas continuam válidos, e caixas antigas precisam de ícone para aparecer.
     * A cor é apenas o padrão de quando a caixa não tem uma própria.
     */
    var ICONES = {
        CTO:      { forma: 'cto', cor: '#00C853' },
        CEO:      { forma: 'ceo', cor: '#FF9100' },
        DC:       { forma: 'estrela', cor: '#1B3A6B' },
        PREDIO:   { forma: 'quadrado', cor: '#6A1B9A' },
        POSTE:    { forma: 'circulo', cor: '#795548' },
        CTO_AP:   { forma: 'quadrado', cor: '#00ACC1' },
        CLIENTE:  { forma: 'gota', cor: '#2962FF' },
        RESERVA:  { forma: 'losango', cor: '#9E9E9E' },
        PROBLEMA: { forma: 'triangulo', cor: '#D50000' },
        FALHA:    { forma: 'triangulo', cor: '#D50000' }
    };

    /** Classe de ícone (Bootstrap Icons do painel) usada nos textos da ficha. */
    var ICONE_CLASSE = {
        DC: 'bi-hdd-rack-fill', PREDIO: 'bi-building', POSTE: 'bi-signpost-2-fill',
        CEO: 'bi-diagram-3-fill', CLIENTE: 'bi-person-fill', CTO: 'bi-box-seam',
        CTO_AP: 'bi-buildings-fill', PROBLEMA: 'bi-exclamation-triangle-fill',
        RESERVA: 'bi-bookmark-fill', FALHA: 'bi-exclamation-octagon-fill'
    };

    function esc(s) {
        return String(s === null || s === undefined ? '' : s)
            .replace(/[&<>"]/g, function (c) {
                return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c];
            });
    }

    /** Ícone em SVG: sem depender de arquivo de imagem nem de CDN. */
    function icone(tipo, cor, opaco) {
        var cfg = ICONES[tipo] || ICONES.CTO;
        var c = cor || cfg.cor;
        var op = opaco === false ? 0.45 : 1;
        var corpo;
        // A silhueta vem do PHP (Caixa::SILHUETAS), para o mapa, a ficha e o seletor de
        // tipo desenharem a partir da MESMA fonte. Só a cor é trocada aqui.
        var silhuetas = (window.FTTH_MAPA || {}).silhuetas || {};
        if (silhuetas[tipo]) {
            corpo = silhuetas[tipo].split('{cor}').join(c);
        } else if (cfg.forma === 'quadrado') {
            corpo = '<rect x="4" y="4" width="16" height="16" rx="3" fill="' + c + '" stroke="#fff" stroke-width="2"/>';
        } else if (cfg.forma === 'triangulo') {
            corpo = '<path d="M12 3 L21 20 L3 20 Z" fill="' + c + '" stroke="#fff" stroke-width="2"/>';
        } else if (cfg.forma === 'losango') {
            corpo = '<path d="M12 3 L21 12 L12 21 L3 12 Z" fill="' + c + '" stroke="#fff" stroke-width="2"/>';
        } else if (cfg.forma === 'gota') {
            corpo = '<path d="M12 2 C16 6 19 9 19 13 a7 7 0 0 1 -14 0 c0 -4 3 -7 7 -11 z" fill="' + c
                  + '" stroke="#fff" stroke-width="1.8"/>';
        } else if (cfg.forma === 'estrela') {
            corpo = '<path d="M12 2 l3 6 6 1 -4.5 4.5 1 6.5 -5.5 -3 -5.5 3 1 -6.5 L3 9 l6 -1 z" fill="' + c + '" stroke="#fff" stroke-width="1.5"/>';
        } else {
            corpo = '<circle cx="12" cy="12" r="8" fill="' + c + '" stroke="#fff" stroke-width="2"/>';
        }
        // 30px em vez de 24: a silhueta tem detalhe (o anel da CEO, a trava da CTO) que
        // no tamanho antigo virava um risco só. O viewBox continua 0 0 24 24 — muda a
        // apresentação, não o desenho.
        var svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"'
                + ' width="' + PX_ICONE + '" height="' + PX_ICONE + '" opacity="' + op + '">'
                + corpo + '</svg>';
        return {
            url: 'data:image/svg+xml;charset=UTF-8,' + encodeURIComponent(svg),
            scaledSize: new google.maps.Size(PX_ICONE, PX_ICONE),
            anchor: new google.maps.Point(PX_ICONE / 2, PX_ICONE / 2),
            // O rótulo sai de cima do desenho e vai para ACIMA dele: centrado no ícone,
            // o nome cobria justamente a silhueta que ele deveria ajudar a identificar.
            // labelOrigin é medido a partir do canto superior esquerdo do ícone, então
            // um y negativo o joga para fora, acima da peça.
            labelOrigin: new google.maps.Point(PX_ICONE / 2, -7)
        };
    }

    function limpar() {
        marcadores.forEach(function (m) { m.setMap(null); });
        linhas.forEach(function (l) { l.setMap(null); });
        quarentenaObj.forEach(function (q) { q.setMap(null); });
        marcadores = []; linhas = []; quarentenaObj = [];
    }

    function visivel(tipo) {
        if (tipo === 'CTO' || tipo === 'CTO_AP') return camadas.CTO;
        if (tipo === 'CEO') return camadas.CEO;
        return camadas.OUTRAS;
    }

    function carregar() {
        if (!mapa || !regiao) return;
        var b = mapa.getBounds();
        if (!b) return;
        var ne = b.getNorthEast(), sw = b.getSouthWest();
        var bbox = [sw.lat(), sw.lng(), ne.lat(), ne.lng()].join(',');

        FTTH.chamar({
            url: 'mapa.php?ajax=elementos&regiao=' + regiao + '&bbox=' + bbox
                 + '&quarentena=' + (camadas.QUARENTENA ? 1 : 0),
            onOk: desenhar,
            onErro: function (m) { $('#resumo-mapa').text(m); }
        });
    }

    /** O número no botão diz o que está ligado sem precisar abrir o menu. */
    function atualizarContaCamadas() {
        $('#camadas-conta').text($('.cam:checked').length);
    }

    function desenhar(d) {
        limpar();
        ultimoDesenho = d;   // o modo Mover precisa saber que cabos tocam cada caixa

        d.caixas.forEach(function (c) {
            if (!visivel(c.tipo)) return;
            // No modo Mover a posição que vale é a pendente, senão a caixa voltaria para
            // o lugar antigo a cada recarga da área visível.
            var pend = pendentes.caixas[c.id];
            var m = new google.maps.Marker({
                position: pend
                    ? { lat: pend.lat, lng: pend.lng }
                    : { lat: parseFloat(c.lat), lng: parseFloat(c.lng) },
                map: mapa,
                icon: icone(c.tipo, c.cor, true),
                title: c.nome,
                draggable: modo === 'mover',
                zIndex: pend ? 40 : undefined,
                label: mapa.getZoom() >= ((window.FTTH_MAPA || {}).rotulo_zoom || 17)
                    ? { text: c.nome, fontSize: '11px', color: '#22303F', className: 'ftth-rotulo' }
                    : null
            });
            m.addListener('click', function () {
                // No modo Cabo, clicar numa caixa ancora o traçado nela em vez de abrir a ficha.
                if (modo === 'cabo') {
                    pontoTracado({ tipo: 'CAIXA', id: parseInt(c.id, 10), nome: c.nome,
                                   lat: parseFloat(c.lat), lng: parseFloat(c.lng) });
                    return;
                }
                if (modo === 'mover') return;     // no modo Mover o clique é para arrastar
                abrirFicha(c.id);
            });
            if (modo === 'mover') {
                // A ponta dos cabos acompanha na tela enquanto se arrasta; no banco quem
                // faz isso é o Caixa::mover, na hora do Concluir.
                m.addListener('drag', function (ev) {
                    pendentes.caixas[c.id] = { lat: ev.latLng.lat(), lng: ev.latLng.lng() };
                    arrastarPontasNaTela(c.id);
                });
                m.addListener('dragend', function () {
                    atualizarContaMover();
                    // As alças do Google seguem a linha sozinhas: nada a redesenhar aqui.
                });
            }
            marcadores.push(m);
        });

        // Com muitos cabos na tela, acender os vértices de todos travaria o mapa. Acima do
        // limite as caixas continuam soltas e o card explica — melhor do que ficar lento
        // sem dizer por quê.
        var editaveis = modo === 'mover' && d.vaos.length <= LIMITE_EDITAVEIS;
        if (modo === 'mover') {
            $('#mover-dica').text(editaveis
                ? 'Arraste caixas e vértices. Botão direito na linha insere, no vértice remove.'
                : d.vaos.length + ' cabos na tela: aproxime para editar os traçados. '
                  + 'As caixas continuam arrastáveis.');
        }

        if (camadas.CABOS) {
            d.vaos.forEach(function (v) {
                var caminho = (v.vertices || []).map(function (p) {
                    return { lat: parseFloat(p[0]), lng: parseFloat(p[1]) };
                });
                if (caminho.length < 2) return;
                if (pendentes.vaos[v.id]) {
                    caminho = pendentes.vaos[v.id].map(function (p) {
                        return { lat: p[0], lng: p[1] };
                    });
                }
                var l = new google.maps.Polyline({
                    path: caminho, map: mapa,
                    strokeColor: v.cor_rota || '#00E676',
                    strokeWeight: (window.FTTH_MAPA || {}).cabo_espessura || 5,
                    strokeOpacity: 0.95
                });
                l.ftthVaoId = v.id;      // para o modo Mover achar a linha deste vão
                l.addListener('click', function (e) {
                    if (modo === 'mover') return;   // no modo Mover o clique é para editar
                    abrirCabo(v, e.latLng);
                });
                if (modo === 'mover' && editaveis) {
                    tornarEditavel(l, v);
                }
                linhas.push(l);
            });
        }

        if (camadas.QUARENTENA) {
            (d.quarentena || []).forEach(function (q) {
                if (q.tipo === 'CAIXA') {
                    var m = new google.maps.Marker({
                        position: { lat: parseFloat(q.geo[0][0]), lng: parseFloat(q.geo[0][1]) },
                        map: mapa,
                        icon: icone(q.subtipo, q.cor, false),
                        title: (q.nome || '') + ' — não revisado',
                        zIndex: 1
                    });
                    m.addListener('click', function () { abrirQuarentena(q); });
                    quarentenaObj.push(m);
                } else {
                    var caminho = q.geo.map(function (p) {
                        return { lat: parseFloat(p[0]), lng: parseFloat(p[1]) };
                    });
                    if (caminho.length < 2) return;
                    var l = new google.maps.Polyline({
                        path: caminho, map: mapa,
                        strokeColor: q.cor || '#9E9E9E',
                        strokeWeight: (window.FTTH_MAPA || {}).cabo_espessura_q || 4,
                        strokeOpacity: 0.4
                    });
                    l.addListener('click', function () { abrirQuarentena(q); });
                    quarentenaObj.push(l);
                }
            });
        }

        var c = d.contadores || {};
        var item = function (valor, rotulo, extra) {
            return '<span class="ftth-resumo-item' + (extra || '') + '">'
                 + '<b>' + valor + '</b>' + rotulo + '</span>';
        };
        $('#resumo-mapa').html(
            item(d.caixas.length, 'caixas')
            + item(d.vaos.length, 'vãos')
            + (c.pendentes ? item(c.pendentes, 'a revisar', ' ftth-resumo-item--pend') : '')
            + (d.truncado
                ? '<span class="ftth-resumo-item ftth-resumo-item--aviso">'
                  + '<i class="bi-exclamation-triangle-fill"></i>&nbsp;parcial: aproxime o mapa</span>'
                : '')
        );
    }

    function painel(html) {
        $('#painel-conteudo').html(html);
        $('#painel').show();
    }

    /**
     * Ficha da caixa no formato do UpperX: identificação, dados do circuito, ações.
     * O que ainda não existe aparece como "—" e o botão fica desligado com o motivo —
     * melhor do que esconder e deixar o usuário procurando.
     */
    function abrirFicha(id) {
        var cfg = window.FTTH_MAPA || {};
        caboAberto = null;   // daqui em diante o painel fala de uma caixa, não do cabo
        FTTH.chamar({
            url: 'mapa.php?ajax=ficha&id=' + id,
            onOk: function (c) {
                var ocupadas = c.clientes.length;
                var portas = 0;
                c.splitters.forEach(function (s) { if (s.funcao === 'ATENDIMENTO') portas += parseInt(s.saidas, 10); });
                var pct = portas ? Math.round(ocupadas * 100 / portas) : 0;

                var selo = c.status === 'certificada'
                    ? '<span class="ftth-selo ftth-selo--ok">certificada</span>'
                    : (c.ligacoes > 0
                        ? '<span class="ftth-selo ftth-selo--info">' + c.ligacoes + ' ligações</span>'
                        : '<span class="ftth-selo ftth-selo--pend">sem diagrama</span>');

                var alimentacao = c.cabos.length
                    ? c.cabos.map(function (v) { return esc(v.sentido); }).join(', ')
                    : '—';
                var splitterLocal = portas
                    ? portas + ' portas (' + c.splitters.map(function (s) { return esc(s.razao); }).join(', ') + ')'
                    : '—';

                var quem = c.alterado_por || c.criado_por || '—';
                var quando = c.alterado_em || c.criado_em || '';

                // Serviço, equipamento e DIO/porta descrevem a ORIGEM do sinal que chega
                // aqui — vêm do rastreio, não do cadastro da caixa.
                var pot = c.potencia || null;
                var org = pot ? (pot.origem || {}) : {};

                var html =
                    '<div class="ftth-ficha-topo">'
                  +   '<h3 class="ftth-painel-titulo">'
                  +     marcaDoTipo(c.tipo, c.cor) + ' '
                  +     esc(c.nome) + '</h3>'
                  +   '<p class="ftth-sub">' + esc(c.tipo) + ' · ' + esc(c.regiao) + ' ' + selo + '</p>'
                  + '</div>'

                  + '<dl class="ftth-ficha">'
                  +   linha('Serviço', juntar([org.servico]))
                  +   linha('Equipamento', juntar([org.olt, org.pon]))
                  +   linha('DIO / Porta', juntar([org.dio, org.porta]))
                  +   linha('Cabos que chegam', alimentacao)
                  +   linha('Splitter local', splitterLocal)
                  +   linha('Origem', c.origem === 'kmz' ? 'importada do KMZ' : 'cadastro manual')
                  + '</dl>'

                  + '<p class="ftth-ficha-autor">Por ' + esc(quem)
                  +   (quando ? ' · ' + esc(quando) : '') + '</p>';

                if (portas) {
                    html += '<div class="ftth-barra"><span style="width:' + pct + '%"></span></div>'
                          + '<p class="ftth-sub">' + ocupadas + ' de ' + portas + ' portas ocupadas ('
                          + pct + '%)</p>';
                }

                // No POP o trabalho é outro: OLT, DIO e a grade de portas. A ligação da
                // porta com a fibra é feita lá, por seletor, e grava no mesmo grafo — por
                // isso a caixa DC não precisa de diagrama.
                html += (c.tipo === 'DC'
                     ?  '<a class="ftth-btn ftth-btn--pri ftth-ficha-principal" '
                        + 'href="pop.php?caixa=' + c.id + '"><i class="bi-hdd-rack-fill"></i> Data Center</a>'
                     :  '<a class="ftth-btn ftth-btn--pri ftth-ficha-principal" '
                        + 'href="caixa.php?id=' + c.id + '"><i class="bi-diagram-3-fill"></i> Diagrama</a>')
                     +  '<div class="ftth-ficha-acoes">'
                     // Só faz sentido onde há splitter de atendimento: é dele que sai o
                     // drop do cliente. Numa CEO de passagem o botão continua apagado.
                     +    '<button class="ftth-acao" id="fc-clientes" data-id="' + c.id + '"'
                     +      (portas ? '' : ' disabled title="Esta caixa não tem splitter de atendimento"')
                     +      '><i class="bi-people-fill"></i> Clientes'
                     +      (ocupadas ? ' <b>' + ocupadas + '</b>' : '') + '</button>'
                     +    '<button class="ftth-acao" id="fc-centralizar" data-lat="' + c.lat + '" data-lng="' + c.lng + '">'
                     +      '<i class="bi-geo-fill"></i> Ver no mapa</button>'
                     // Atalho para a rota completa: a estimativa logo abaixo responde
                     // "quanto chega aqui", o botão responde "por onde veio". Sem caminho
                     // óptico não há o que mostrar, então fica apagado.
                     +    '<button class="ftth-acao" id="fc-sinal" data-id="' + c.id + '"'
                     +      ' data-nome="' + esc(c.nome) + '"'
                     +      (pot ? '' : ' disabled title="Esta caixa não tem caminho óptico até uma OLT"')
                     +      '><i class="bi-reception-4"></i> Sinal</button>'
                     +    '<button class="ftth-acao" disabled title="Entra na fase 3">'
                     +      '<i class="bi-camera-fill"></i> Fotos</button>'
                     +    '<button class="ftth-acao" id="fc-editar" data-id="' + c.id + '">'
                     +      '<i class="bi-pencil-square"></i> Editar</button>'
                     +    '<button class="ftth-acao ftth-acao--perigo" '
                     +      'id="fc-excluir" data-id="' + c.id + '" data-versao="' + c.versao + '" '
                     +      'data-nome="' + esc(c.nome) + '">'
                     +      '<i class="bi-trash3-fill"></i> Excluir</button>'
                     +  '</div>'

                     +  '<div class="ftth-ficha-rastreio">'
                     +    '<p class="ftth-ficha-secao">Informações técnicas e rastreio</p>'
                     +    '<div class="ftth-estimativa' + (pot ? ' ftth-estimativa--' + esc(pot.classe) : '') + '">'
                     +      '<span class="ftth-sub">Estimativa de sinal</span>'
                     +      (pot
                            ? '<strong>' + dbm(pot.dbm)
                              + (pot.classe ? ' <small>' + esc(pot.classe) + '</small>' : '') + '</strong>'
                              + '<span class="ftth-sub">na ' + esc(pot.onde)
                              + ' · ' + pot.saltos + ' salto(s) desde ' + esc(org.caixa || '—') + '</span>'
                            : '<strong>sem caminho óptico</strong>'
                              + '<span class="ftth-sub">' + (c.ligacoes > 0
                                  ? 'há ligações aqui, mas nenhuma chega a uma porta de DIO com equipamento'
                                  : 'nenhuma fusão registrada nesta caixa') + '</span>')
                     +    '</div>'
                     +    '<p class="ftth-sub ftth-coord">Localização: <span class="mono" id="fc-coord">'
                     +      parseFloat(c.lat).toFixed(6) + ', ' + parseFloat(c.lng).toFixed(6) + '</span> '
                     +      '<button class="ftth-copiar" id="fc-copiar" title="Copiar">copiar</button></p>'
                     +  '</div>'
                     +  '<div id="fc-saida"></div>';

                painel(html);
            },
            onErro: function (m) { painel('<div class="ftth-aviso ftth-aviso--erro">' + esc(m) + '</div>'); }
        });
    }

    /**
     * A marca do tipo, para títulos em HTML: a silhueta quando o tipo tem uma, senão o
     * ícone do painel. Mesma fonte do marcador do mapa — o desenho vive no PHP.
     */
    function marcaDoTipo(tipo, cor) {
        var silhuetas = (window.FTTH_MAPA || {}).silhuetas || {};
        if (silhuetas[tipo]) {
            return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="20"'
                 + ' height="20" class="ftth-silhueta">'
                 + silhuetas[tipo].split('{cor}').join(cor || '#4A5568') + '</svg>';
        }
        return '<i class="' + (ICONE_CLASSE[tipo] || 'bi-box-seam') + '"></i>';
    }

    /** −19,25 dBm — sinal explícito e vírgula decimal, como no painel do diagrama. */
    function dbm(v) {
        var n = parseFloat(v);
        if (isNaN(n)) return '—';
        return (n > 0 ? '+' : n < 0 ? '−' : '') + Math.abs(n).toFixed(2).replace('.', ',') + ' dBm';
    }

    function linha(rotulo, valor) {
        return '<dt>' + esc(rotulo) + '</dt><dd>' + valor + '</dd>';
    }

    /** Partes vazias somem; sobrando nada, o traço — `linha()` recebe HTML já escapado. */
    function juntar(partes) {
        var v = partes.filter(function (x) { return x; }).join(' · ');
        return v ? esc(v) : '—';
    }

    function excluirCaixa(id, versao, nome) {
        var cfg = window.FTTH_MAPA || {};
        if (!confirm('Excluir a caixa "' + nome + '"?\n\nEla sai do mapa, mas continua no histórico.')) {
            return;
        }
        FTTH.chamar({
            url: 'mapa.php?ajax=excluir_caixa',
            method: 'POST',
            data: { csrf: cfg.csrf, id: id, versao: versao },
            onOk: function () {
                $('#painel').hide();
                carregar();
            },
            onErro: function (m) {
                $('#fc-saida').html('<div class="ftth-aviso ftth-aviso--erro">' + esc(m) + '</div>');
            }
        });
    }

    /**
     * Ficha do cabo — mesmo desenho da ficha de caixa: topo, ações e rastreio.
     * O vão clicado é o que dá os números de campo (comprimento, pontas, vértices);
     * nome, fabricante e capacidade são do CABO, e é sobre ele que as ações agem.
     */
    function abrirCabo(v) {
        var cfg = window.FTTH_MAPA || {};
        caboAberto = v;

        var origem = v.cabo_origem === 'kmz' ? 'importado do KMZ' : 'cadastro manual';
        var selo = v.status && v.status !== 'implantado'
                 ? ' <span class="ftth-selo ftth-selo--novo">' + esc(v.status) + '</span>' : '';

        var html =
            '<div class="ftth-ficha-topo">'
          +   '<h3 class="ftth-painel-titulo"><i class="bi-share-fill"></i> '
          +     esc(v.cabo_nome || ('Cabo ' + v.cabo_tipo)) + '</h3>'
          +   '<p class="ftth-sub">Fibra óptica · ' + esc(v.caixa_ini) + ' → '
          +     esc(v.caixa_fim) + selo + '</p>'
          + '</div>'

          + '<button class="ftth-btn ftth-btn--pri ftth-ficha-principal" id="fb-editar">'
          +   '<i class="bi-pencil-square"></i> Editar cabo</button>'

          + '<div class="ftth-ficha-acoes ftth-ficha-acoes--duo">'
          +   '<button class="ftth-acao" id="fb-centralizar">'
          +     '<i class="bi-geo-fill"></i> Ver no mapa</button>'
          +   '<button class="ftth-acao ftth-acao--perigo" id="fb-excluir">'
          +     '<i class="bi-trash3-fill"></i> Excluir cabo</button>'
          + '</div>'

          + '<div class="ftth-ficha-rastreio">'
          +   '<p class="ftth-ficha-secao">Informações técnicas e rastreio</p>'
          +   '<dl class="ftth-ficha">'
          +     linha('Nome do cabo', esc(v.cabo_nome || '—'))
          +     linha('Fabricante', esc(v.fabricante || '—'))
          +     linha('Capacidade total', esc(v.cabo_tipo))
          +     linha('Construção do tubo', esc(v.construcao || '—'))
          +     linha('Padrão de cores', esc(v.padrao_cores))
          +     linha('Comprimento do vão', metros(v.comprimento_geo))
          +     linha('Comprimento óptico', metros(v.comprimento_optico))
          +     linha('Caixa de início', esc(v.caixa_ini))
          +     linha('Caixa final', esc(v.caixa_fim))
          +     linha('Vértices na rota', (v.vertices || []).length)
          +     linha('Origem', origem)
          +   '</dl>'
          + '</div>'
          + '<div id="fb-saida"></div>';

        painel(html);
    }

    /** 160.43 -> "160,4 m" — o mapa fala em metros, não em decimais de engenharia. */
    function metros(valor) {
        var n = parseFloat(valor);
        return isNaN(n) ? '—' : n.toFixed(1).replace('.', ',') + ' m';
    }

    /** Enquadra o vão inteiro na tela — um cabo não cabe num ponto só. */
    function enquadrarVao(v) {
        if (!v || !(v.vertices || []).length) return;
        var area = new google.maps.LatLngBounds();
        v.vertices.forEach(function (p) {
            area.extend({ lat: parseFloat(p[0]), lng: parseFloat(p[1]) });
        });
        mapa.fitBounds(area);
    }

    /** Abre o modal de edição já preenchido com o que o cabo tem hoje. */
    function editarCabo(v) {
        $('#ec-titulo').text('Editar ' + (v.cabo_nome || ('cabo ' + v.cabo_tipo)));
        $('#ec-rota').text(v.caixa_ini + ' → ' + v.caixa_fim + ' · ' + metros(v.comprimento_geo));
        $('#ec-nome').val(v.cabo_nome || '');
        $('#ec-fabricante').val(v.fabricante || '');
        $('#ec-tipo').val(v.cabo_tipo_id);
        $('#ec-padrao').val(v.padrao_cores);
        $('#ec-status').val(v.status || 'implantado');
        $('#ec-cores .ftth-cor').removeClass('ativo').filter(function () {
            return $(this).data('cor') === (v.cor_rota || '#00E676');
        }).addClass('ativo');
        $('#ec-saida').empty();
        $('#modal-editar-cabo').show();
    }

    function salvarEdicaoCabo() {
        var cfg = window.FTTH_MAPA || {};
        var v = caboAberto;
        if (!v) return;

        var cor = $('#ec-cores .ftth-cor.ativo').data('cor') || v.cor_rota;
        var $b = $('#ec-salvar').prop('disabled', true);

        FTTH.chamar({
            url: 'mapa.php?ajax=alterar_cabo',
            method: 'POST',
            data: {
                csrf: cfg.csrf, cabo: v.cabo_id, versao: v.cabo_versao,
                nome: $('#ec-nome').val(), fabricante: $('#ec-fabricante').val(),
                cabo_tipo_id: $('#ec-tipo').val(), padrao_cores: $('#ec-padrao').val(),
                cor_rota: cor, status: $('#ec-status').val()
            },
            onOk: function (d, resp) {
                $('#modal-editar-cabo').hide();
                // O cabo pode ter mudado de cor ou de capacidade: recarrega para o mapa
                // e a ficha contarem a mesma história.
                carregar();
                $('#painel').hide();
                var avisos = (resp && resp.warnings) || [];
                if (avisos.length) {
                    FTTH.toast('avis', 'Cabo salvo, com ressalva.',
                        avisos.map(function (w) { return w.message; }));
                } else {
                    FTTH.toast('ok', 'Cabo salvo.');
                }
            },
            onErro: function (m) {
                $('#ec-saida').html('<div class="ftth-aviso ftth-aviso--erro">' + esc(m) + '</div>');
            }
        }).always(function () { $b.prop('disabled', false); });
    }

    function excluirCabo(v) {
        var cfg = window.FTTH_MAPA || {};
        var nome = v.cabo_nome || ('cabo ' + v.cabo_tipo);
        if (!confirm('Excluir o ' + nome + ' (' + v.caixa_ini + ' → ' + v.caixa_fim + ')?\n\n'
                   + 'O traçado sai do mapa. Se houver fibra ligada, a exclusão é recusada.')) {
            return;
        }
        FTTH.chamar({
            url: 'mapa.php?ajax=excluir_cabo',
            method: 'POST',
            data: { csrf: cfg.csrf, cabo: v.cabo_id },
            onOk: function () {
                $('#painel').hide();
                caboAberto = null;
                carregar();
                FTTH.toast('ok', nome + ' excluído.');
            },
            onErro: function (m, resp) {
                // Quando a recusa é por fibra ligada, o servidor manda em quais caixas:
                // cada uma vira uma linha do toast, para o técnico saber onde desconectar.
                FTTH.toast('erro', m, caixasDoErro(resp));
            }
        });
    }

    /** Extrai "CTO.18.10 — 2 fibra(s)" dos detalhes de um FTTH-TOP-016. */
    function caixasDoErro(resp) {
        var det = resp && resp.errors && resp.errors[0] && resp.errors[0].details;
        if (!det || !det.caixas) return null;
        return det.caixas.map(function (c) {
            return c.nome + ' — ' + c.fibras + ' fibra' + (c.fibras > 1 ? 's' : '');
        });
    }


    /* ------------------------------------------------------------------ clientes na porta */

    var caixaClientes = null;   // caixa cuja grade está aberta

    /**
     * A grade de atendimento da caixa: uma linha por saída do splitter, com o cliente que
     * está ali ou um campo de busca.
     *
     * É o ponto onde a documentação encontra o cadastro do MK-AUTH: a porta aponta para um
     * `sis_cliente.id`, nunca para o login — login é atributo de exibição (F1).
     */
    function abrirClientes(caixaId) {
        caixaClientes = caixaId;
        $('#cl-corpo').html('<p class="ftth-sub">Carregando…</p>');
        $('#cl-resumo').empty();
        $('#modal-clientes').show();

        FTTH.chamar({
            url: 'mapa.php?ajax=atendimento&caixa=' + caixaId,
            onOk: function (d) {
                $('#cl-titulo').text('Clientes · ' + d.caixa.nome);
                $('#cl-corpo').html(htmlGradeClientes(d));

                var total = 0, ocupadas = 0;
                (d.splitters || []).forEach(function (s) {
                    total += s.saidas; ocupadas += s.ocupadas;
                });
                $('#cl-resumo').text(ocupadas + ' de ' + total + ' portas ocupadas');
            },
            onErro: function (m) {
                $('#cl-corpo').html('<div class="ftth-aviso ftth-aviso--erro">' + esc(m) + '</div>');
            }
        });
    }

    function htmlGradeClientes(d) {
        if (!d.splitters || !d.splitters.length) {
            return '<div class="ftth-aviso">Esta caixa não tem splitter de atendimento. '
                 + 'Adicione um pelo diagrama para poder vincular clientes.</div>';
        }
        var html = '';

        d.splitters.forEach(function (s) {
            html += '<div class="ftth-grupo-portas">'
                 +    '<div class="ftth-grupo-portas-topo">'
                 +      '<strong>' + esc(s.nome) + ' · ' + esc(s.razao) + '</strong>'
                 +      '<span>' + s.ocupadas + '/' + s.saidas + ' ocupadas</span>'
                 +    '</div>';

            s.portas.forEach(function (p) {
                var sinal = p.dbm === null || p.dbm === undefined
                    ? '<span class="ftth-porta-sinal ftth-porta-sinal--sem">sem sinal</span>'
                    : '<span class="ftth-porta-sinal">' + dbm(p.dbm) + '</span>';

                html += '<div class="ftth-porta-linha' + (p.cliente ? ' ocupada' : '')
                     +    (p.cliente && !p.cliente.ativo ? ' ftth-porta-linha--cancelada' : '') + '"'
                     +    ' data-splitter="' + s.id + '" data-numero="' + p.numero + '">'
                     +    '<span class="ftth-porta-rotulo">' + esc(p.rotulo) + '</span>'
                     +    sinal;

                if (p.cliente) {
                    // Cliente cancelado ocupando porta é capacidade parada: a linha diz
                    // isso em vez de parecer um atendimento como os outros.
                    html += '<span class="ftth-porta-cliente'
                         +    (p.cliente.ativo ? '' : ' ftth-porta-cliente--cancelado') + '">'
                         +    esc(p.cliente.login)
                         +    (p.cliente.ativo ? ''
                              : ' <span class="ftth-selo ftth-selo--erro">cancelado</span>')
                         +  '</span>'
                         +  '<button class="ftth-btn ftth-btn--sec ftth-porta-acao js-desvincular"'
                         +    ' data-cliente="' + p.cliente.cliente_id + '"'
                         +    ' data-login="' + esc(p.cliente.login) + '">Tirar</button>';
                } else {
                    html += '<div class="ftth-porta-busca">'
                         +    '<input class="ftth-campo js-busca-cliente" placeholder="buscar cliente…"'
                         +      ' autocomplete="off">'
                         +    '<div class="ftth-porta-lista"></div>'
                         +  '</div>';
                }
                html += '</div>';
            });
            html += '</div>';
        });
        return html;
    }

    /** Resultados da busca, já dizendo quem não está livre. */
    function htmlResultadosCliente(lista) {
        if (!lista.length) {
            return '<div class="ftth-porta-item ftth-porta-item--vazio">nenhum cliente encontrado</div>';
        }
        return lista.map(function (c) {
            // Sem selo de situação: a busca já só devolve cliente ativo.
            return '<div class="ftth-porta-item js-escolher-cliente" data-id="' + c.id + '"'
                 +    ' data-login="' + esc(c.login) + '"'
                 +    ' data-ja="' + esc(c.ja_em || '') + '">'
                 +    '<strong>' + esc(c.login) + '</strong>'
                 +    '<span>' + esc(c.nome || '') + '</span>'
                 +    (c.ja_em ? '<span class="ftth-porta-item-ja">já em ' + esc(c.ja_em) + '</span>'
                               : (c.endereco ? '<span>' + esc(c.endereco) + '</span>' : ''))
                 +  '</div>';
        }).join('');
    }

    function vincularCliente(splitterId, numero, clienteId, mover) {
        var cfg = window.FTTH_MAPA || {};
        FTTH.chamar({
            url: 'mapa.php?ajax=vincular_cliente',
            method: 'POST',
            data: { csrf: cfg.csrf, cliente: clienteId, splitter: splitterId,
                    numero: numero, mover: mover ? '1' : '0' },
            onOk: function (d, resp) {
                abrirClientes(caixaClientes);   // redesenha a grade inteira
                atualizarFichaAberta();         // a ficha fica à vista, ao lado do modal
                carregar();                      // a ocupação da caixa muda no mapa
                mostrarAvisos(resp, d.login + ' vinculado na saída ' + numero + '.');
            },
            onErro: function (m) { FTTH.toast('erro', m); }
        });
    }

    function desvincularCliente(clienteId, login) {
        var cfg = window.FTTH_MAPA || {};
        if (!confirm('Tirar ' + login + ' desta porta?')) return;

        FTTH.chamar({
            url: 'mapa.php?ajax=desvincular_cliente',
            method: 'POST',
            data: { csrf: cfg.csrf, cliente: clienteId },
            onOk: function (d, resp) {
                abrirClientes(caixaClientes);
                atualizarFichaAberta();
                carregar();
                mostrarAvisos(resp, login + ' saiu da porta.');
            },
            onErro: function (m) { FTTH.toast('erro', m); }
        });
    }

    /**
     * Redesenha a ficha da caixa que está aberta atrás do modal.
     *
     * Ela mostra a contagem de portas ocupadas e a barra de ocupação, e ficaria mentindo
     * enquanto o modal some e volta — o usuário veria "1 de 8" ao lado de "2 de 8".
     */
    function atualizarFichaAberta() {
        if (caixaClientes && $('#painel').is(':visible')) {
            abrirFicha(caixaClientes);
        }
    }

    /**
     * O vínculo escreve em `sis_cliente` e na nativa `cto` quando a sincronização está
     * ligada, e isso produz avisos que ninguém mais mostraria — eles dizem, por exemplo,
     * que o nome da caixa não coube no campo da nativa.
     */
    function mostrarAvisos(resp, textoOk) {
        var avisos = (resp && resp.warnings) || [];
        if (avisos.length) {
            FTTH.toast('avis', textoOk, avisos.map(function (w) { return w.message; }));
        } else {
            FTTH.toast('ok', textoOk);
        }
    }

    /* ------------------------------------------------------------------ rota óptica */

    /**
     * O detalhe por trás da estimativa da ficha: de onde vem o sinal, cada salto com a sua
     * perda, e quanto chega nesta caixa.
     *
     * É o mesmo painel que o diagrama abre ao clicar numa ponta, aqui no tema claro e a
     * partir da caixa inteira — o servidor escolhe a ponta representativa pela mesma regra
     * que produziu a estimativa, então os dois números nunca discordam.
     *
     * A tela não soma dB nenhum: duas contas sempre divergem.
     */
    function abrirRota(caixaId, nome) {
        $('#rt-titulo').text('Rota óptica' + (nome ? ' · ' + nome : ''));
        $('#rt-corpo').html('<p class="ftth-sub">Calculando a rota…</p>');
        $('#modal-rota').show();

        FTTH.chamar({
            url: 'mapa.php?ajax=rota&caixa=' + caixaId,
            onOk:  function (d) { $('#rt-corpo').html(htmlRota(d)); },
            onErro: function (m) {
                // Caixa sem caminho até a OLT é estado normal da rede, não falha.
                $('#rt-corpo').html('<div class="ftth-aviso">' + esc(m) + '</div>');
            }
        });
    }

    function htmlRota(d) {
        var o = d.origem || {};

        var html = '<div class="ftth-rota-origem">'
            +   '<p class="ftth-ficha-secao"><i class="bi-hdd-rack-fill"></i> Origem do sinal</p>'
            +   '<p class="ftth-rota-caixa">' + esc(o.caixa || '—') + '</p>'
            +   '<dl class="ftth-ficha">'
            +     linha('Serviço', juntar([o.servico]))
            +     linha('Equipamento', juntar([o.olt, o.pon]))
            +     linha('DIO / Porta', juntar([o.dio, o.porta]))
            +     linha('Potência de saída', dbm(o.ptx_dbm))
            +   '</dl>'
            + '</div>'

            + '<div class="ftth-estimativa' + (d.classe ? ' ftth-estimativa--' + esc(d.classe) : '') + '">'
            +   '<span class="ftth-sub">Chega nesta caixa</span>'
            +   '<strong>' + dbm(d.dbm)
            +     (d.classe ? ' <small>' + esc(d.classe) + '</small>' : '') + '</strong>'
            +   '<span class="ftth-sub">medido na ' + esc(d.onde || 'fibra que chega') + '</span>'
            + '</div>'

            + '<p class="ftth-ficha-secao">Rota · ' + (d.passos || []).length + ' salto(s)</p>';

        // Os passos vêm lineares do servidor; aqui só se agrupa por caixa, que é como quem
        // lê pensa a rede: "no POP aconteceu isso, na CEO aquilo".
        var caixaAtual = null;
        (d.passos || []).forEach(function (s) {
            if (s.via === 'CABO') {
                html += '<div class="ftth-rota-cabo">'
                     +    '<i class="bi-arrow-down"></i> ' + esc(s.rotulo)
                     +    (s.km ? ' · ' + km(s.km) : '')
                     +    '<span class="ftth-rota-perda">−' + s.perda.toFixed(2) + ' dB</span>'
                     +  '</div>';
                caixaAtual = null;          // o próximo passo já é na caixa seguinte
                return;
            }

            if (s.caixa && s.caixa !== caixaAtual) {
                caixaAtual = s.caixa;
                html += '<div class="ftth-rota-caixa-salto">'
                     +    '<span>' + esc(s.caixa) + '</span>'
                     +    '<strong>' + dbm(s.dbm) + '</strong>'
                     +  '</div>';
            }

            html += '<div class="ftth-rota-salto">'
                 +    '<span class="ftth-rota-via">' + esc(rotuloVia(s.via)) + '</span>'
                 +    esc(s.rotulo)
                 +    '<span class="ftth-rota-perda">−' + s.perda.toFixed(2) + ' dB</span>'
                 +  '</div>';
        });

        return html;
    }

    var VIAS = {
        FUSAO:    'fusão',
        PASSAGEM: 'passagem',
        CONECTOR: 'conector',
        SPLITTER: 'splitter'
    };

    function rotuloVia(v) { return VIAS[v] || (v || '').toLowerCase(); }

    function km(v) {
        var n = parseFloat(v);
        if (isNaN(n)) return '';
        return n < 1 ? Math.round(n * 1000) + ' m' : n.toFixed(2).replace('.', ',') + ' km';
    }

    /** Item de quarentena: revisar e decidir aqui mesmo, sem sair do mapa (U1). */
    function abrirQuarentena(q) {
        var cfg = window.FTTH_MAPA || {};
        var alertas = (q.alertas || []).map(function (a) {
            return '<span class="ftth-selo ftth-selo--pend">' + esc(a.message) + '</span>';
        }).join(' ');

        var acoes = '<button class="ftth-btn ftth-btn--pri" id="q-importar" data-id="' + q.id + '">Importar para a rede</button> '
            + '<button class="ftth-btn ftth-btn--sec" id="q-descartar" data-id="' + q.id + '">Descartar</button>';

        painel('<h3 class="ftth-painel-titulo">' + esc(q.nome || '(sem nome)') + '</h3>'
            + '<p class="ftth-sub"><span class="ftth-selo ftth-selo--novo">não revisado</span> '
            + esc(q.tipo) + ' · ' + esc(q.subtipo || '') + '</p>'
            + (alertas ? '<p>' + alertas + '</p>' : '')
            + (q.tipo === 'VAO'
                ? '<p class="ftth-sub">Um cabo só entra depois das duas caixas das pontas.</p>'
                : '')
            + '<div id="q-acoes">' + acoes + '</div>'
            + '<div id="q-saida"></div>'
            + '<p class="ftth-sub" style="margin-top:10px"><a href="importar.php">Ver a lista completa</a></p>');
    }

    function decidirQuarentena(id, acao) {
        var cfg = window.FTTH_MAPA || {};
        $('#q-acoes button').prop('disabled', true);
        FTTH.chamar({
            url: 'mapa.php?ajax=' + (acao === 'importar' ? 'importar_item' : 'descartar_item'),
            method: 'POST',
            data: { csrf: cfg.csrf, id: id },
            onOk: function (d) {
                carregar();   // o item deixa de ser translúcido e vira rede
                if (acao === 'importar' && d && d.tipo === 'CAIXA' && d.id) {
                    // Já mostra a ficha da caixa recém-criada: o usuário continua no mesmo ponto,
                    // vendo o resultado, sem ter que clicar de novo no marcador.
                    abrirFicha(d.id);
                } else if (acao === 'importar') {
                    $('#q-acoes').html('<span class="ftth-selo ftth-selo--ok">Importado para a rede.</span>');
                } else {
                    $('#painel').hide();
                }
            },
            onErro: function (m) {
                $('#q-saida').html('<div class="ftth-aviso ftth-aviso--erro">' + esc(m) + '</div>');
                $('#q-acoes button').prop('disabled', false);
            }
        });
    }

    function buscar(termo) {
        if (termo.length < 2) { $('#busca-lista').hide(); return; }
        FTTH.chamar({
            url: 'mapa.php?ajax=buscar&regiao=' + regiao + '&q=' + encodeURIComponent(termo),
            onOk: function (d) {
                var html = '', grupo = '';
                d.resultados.forEach(function (r) {
                    if (r.grupo !== grupo) {
                        grupo = r.grupo;
                        html += '<div class="ftth-busca-grupo">' + esc(grupo) + '</div>';
                    }
                    html += '<div class="ftth-busca-item" data-lat="' + r.lat + '" data-lng="' + r.lng
                         +  '" data-id="' + r.id + '" data-tipo="' + r.tipo + '">'
                         +  '<strong>' + esc(r.rotulo) + '</strong> <span class="ftth-sub">' + esc(r.detalhe) + '</span>'
                         +  '</div>';
                });
                $('#busca-lista').html(html || '<div class="ftth-busca-item ftth-sub">Nada encontrado.</div>').show();
            },
            onErro: function () { $('#busca-lista').hide(); }
        });
    }

    /** O mapa ocupa tudo o que sobra da janela — nada de altura fixa chutada. */
    function ajustarAltura() {
        var el = document.getElementById('mapa');
        if (!el) return;
        // A folga é só a do padding de baixo do wrap (6px): o mapa encosta na borda da
        // janela. Qualquer valor a mais aqui vira faixa cinza inútil embaixo do mapa.
        var topo = el.getBoundingClientRect().top;
        var altura = Math.max(360, window.innerHeight - topo - 6);
        el.style.height = altura + 'px';
    }

    /* ------------------------------------------------------------------ traçado de cabo */

    /**
     * Regra do traçado (I1): começa numa caixa, passa por quantos vértices e caixas quiser,
     * e termina numa caixa. Cada trecho entre duas caixas vira um vão no servidor.
     */
    function iniciarTracado() {
        limparTracado();
        $('#cabo-card').show();
        atualizarTracado();
    }

    function limparTracado() {
        tracado = [];
        if (linhaTracado) { linhaTracado.setMap(null); linhaTracado = null; }
        pinosTracado.forEach(function (p) { p.setMap(null); });
        pinosTracado = [];
        $('#cabo-card').hide();
    }

    function pontoTracado(p) {
        if (!tracado.length && p.tipo !== 'CAIXA') {
            FTTH.toast('erro', 'O cabo precisa começar em uma caixa.');
            return;
        }
        tracado.push(p);
        atualizarTracado();
    }

    function atualizarTracado() {
        var caminho = tracado.map(function (p) { return { lat: p.lat, lng: p.lng }; });

        if (linhaTracado) { linhaTracado.setMap(null); }
        if (caminho.length > 1) {
            // Em edição o cabo é pontilhado — deixa claro que ainda não existe na rede.
            linhaTracado = new google.maps.Polyline({
                path: caminho, map: mapa,
                strokeOpacity: 0,
                icons: [{
                    icon: { path: 'M 0,-1 0,1', strokeOpacity: 1, strokeWeight: 4, scale: 3,
                            strokeColor: corCaboSelecionada() },
                    offset: '0', repeat: '14px'
                }]
            });
        }

        pinosTracado.forEach(function (p) { p.setMap(null); });
        pinosTracado = tracado.map(function (p, i) {
            return new google.maps.Marker({
                position: { lat: p.lat, lng: p.lng }, map: mapa, zIndex: 998,
                icon: {
                    path: google.maps.SymbolPath.CIRCLE,
                    scale: p.tipo === 'CAIXA' ? 7 : 5,
                    fillColor: p.tipo === 'CAIXA' ? '#1B3A6B' : '#FFFFFF',
                    fillOpacity: 1, strokeColor: '#1B3A6B', strokeWeight: 2
                },
                title: p.tipo === 'CAIXA' ? ('Caixa: ' + (p.nome || '')) : ('Ponto ' + (i + 1))
            });
        });

        var caixas = tracado.filter(function (p) { return p.tipo === 'CAIXA'; }).length;
        $('#cabo-contagem').text(tracado.length + ' ponto' + (tracado.length === 1 ? '' : 's')
            + ' · ' + caixas + ' caixa' + (caixas === 1 ? '' : 's'));

        var ultimo = tracado[tracado.length - 1];
        $('#cabo-dica').text(!tracado.length
            ? 'Clique na caixa onde o cabo começa.'
            : (ultimo.tipo === 'CAIXA'
                ? 'Clique na rua para seguir, em outra caixa para ancorar, ou finalize.'
                : 'Siga clicando na rua e termine em uma caixa.'));
    }

    function corCaboSelecionada() {
        return $('#cb-cores .ftth-cor.ativo').data('cor') || '#00E676';
    }

    function finalizarTracado() {
        if (tracado.length < 2 || tracado[tracado.length - 1].tipo !== 'CAIXA') {
            FTTH.toast('erro', 'O cabo precisa terminar em uma caixa.');
            return;
        }
        var caixas = tracado.filter(function (p) { return p.tipo === 'CAIXA'; });
        var metros = 0;
        for (var i = 1; i < tracado.length; i++) {
            metros += google.maps.geometry && google.maps.geometry.spherical
                ? google.maps.geometry.spherical.computeDistanceBetween(
                      new google.maps.LatLng(tracado[i - 1].lat, tracado[i - 1].lng),
                      new google.maps.LatLng(tracado[i].lat, tracado[i].lng))
                : 0;
        }
        $('#cabo-resumo').text(caixas.length + ' caixas · ' + (caixas.length - 1) + ' vão(s) · '
            + tracado.length + ' pontos'
            + (metros ? ' · ' + Math.round(metros) + ' m aproximados' : ''));
        $('#cb-saida').empty();
        $('#modal-cabo').show();
    }

    function salvarCabo() {
        var cfg = window.FTTH_MAPA || {};
        $('#cb-salvar').prop('disabled', true);
        FTTH.chamar({
            url: 'mapa.php?ajax=novo_cabo',
            method: 'POST',
            data: {
                csrf: cfg.csrf, regiao: regiao,
                nome: $('#cb-nome').val(), fabricante: $('#cb-fabricante').val(),
                cabo_tipo_id: $('#cb-tipo').val(), padrao_cores: $('#cb-padrao').val(),
                cor_rota: corCaboSelecionada(),
                pontos: JSON.stringify(tracado.map(function (p) {
                    return p.tipo === 'CAIXA'
                        ? { tipo: 'CAIXA', id: p.id }
                        : { tipo: 'VERTICE', lat: p.lat, lng: p.lng };
                }))
            },
            onOk: function (d) {
                $('#modal-cabo').hide();
                limparTracado();
                carregar();
                FTTH.toast('ok', d.tipo + ' criado: ' + d.vaos.length + ' vão(s), '
                    + Math.round(d.metros) + ' m. Clique numa caixa para o próximo.');
                $('#cb-nome').val(''); $('#cb-fabricante').val('');
            },
            onErro: function (m) {
                $('#cb-saida').html('<div class="ftth-aviso ftth-aviso--erro">' + esc(m) + '</div>');
            }
        }).always(function () { $('#cb-salvar').prop('disabled', false); });
    }

    /* ------------------------------------------------------------------ nova caixa */

    /**
     * Fluxo do modo Caixa: primeiro o usuário marca o ponto no mapa, depois configura.
     * Assim ele vê exatamente onde a caixa vai ficar antes de dar nome a ela.
     */
    function definirModo(novo) {
        // Sair do modo Mover com alterações na tela perderia trabalho em silêncio: nada
        // foi gravado ainda, então a saída pergunta antes.
        if (modo === 'mover' && novo !== 'mover' && contarPendentes() > 0
            && !confirm('Há alterações não concluídas no mapa.\n\nSair agora descarta tudo.')) {
            return;
        }
        if (modo === 'mover' && novo !== 'mover') {
            sairDoModoMover();
        }

        modo = novo;
        $('.ftth-modo').removeClass('ativo');
        $('.ftth-modo[data-modo="' + novo + '"]').addClass('ativo');
        if (mapa) {
            mapa.setOptions({
                draggableCursor: (novo === 'caixa' || novo === 'cabo') ? 'crosshair' : null
            });
        }

        if (novo === 'caixa') {
            FTTH.toast('info', 'Clique no mapa para marcar o ponto da caixa.');
        } else if (novo === 'cabo') {
            FTTH.toast('info', 'Clique na caixa onde o cabo começa.');
            iniciarTracado();
        } else if (novo === 'mover') {
            FTTH.toast('info', 'Arraste caixas. Clique num cabo para soltar os vértices.');
            $('#mover-card').show();
            atualizarContaMover();
            carregar();                 // redesenha com os marcadores arrastáveis
        } else {
            $('#cabo-dica').text('');
        }

        if (novo !== 'caixa') {
            limparPin();
            $('#modal-caixa').hide();
        }
        if (novo !== 'cabo') {
            limparTracado();
            $('#modal-cabo').hide();
        }
    }

    /* ------------------------------------------------------------------ modo Mover */

    /**
     * Acima disto os traçados não ficam editáveis de uma vez.
     *
     * Não é chute: a rede tem 206 vãos e 1.025 vértices no total. Acender tudo junto é peso
     * real no navegador, e ninguém ajusta traçado com a cidade inteira na tela — 80 vãos
     * já é mais que um bairro. Passando disso, as caixas continuam soltas e o card explica
     * o que fazer, em vez de a tela ficar lenta sem dizer por quê.
     */
    var LIMITE_EDITAVEIS = 80;

    function contarPendentes() {
        return Object.keys(pendentes.caixas).length + Object.keys(pendentes.vaos).length;
    }

    function atualizarContaMover() {
        var n = contarPendentes();
        $('#mover-conta').text(n ? n + ' alteração(ões)' : 'nada alterado');
        $('#mover-concluir').prop('disabled', n === 0);
    }

    function sairDoModoMover() {
        pendentes = { caixas: {}, vaos: {} };
        $('#mover-card').hide();
        carregar();                     // volta o que está no banco
    }

    /**
     * Geometria atual do vão: a pendente, se o usuário mexeu no traçado, senão a do banco.
     *
     * As PONTAS são sempre derivadas da posição da caixa, nunca guardadas — o mesmo que o
     * servidor faz. Assim arrastar uma caixa com três cabos não vira "4 alterações": a
     * alteração é uma só, a da caixa, e os cabos apenas acompanham.
     */
    function pontosDoVao(v) {
        var pts = (pendentes.vaos[v.id] || (v.vertices || []).map(function (p) {
            return [parseFloat(p[0]), parseFloat(p[1])];
        })).slice();

        var ini = pendentes.caixas[v.caixa_ini_id];
        var fim = pendentes.caixas[v.caixa_fim_id];
        if (ini) pts[0] = [ini.lat, ini.lng];
        if (fim && pts.length > 1) pts[pts.length - 1] = [fim.lat, fim.lng];
        return pts;
    }

    /**
     * Liga a edição nativa do Google na linha: ele desenha as alças dos vértices e, entre
     * elas, as alças intermediárias que criam um vértice novo ao serem arrastadas — é o
     * "criar vértice no cabo" do UpperX, de graça e bem mais leve que marcadores próprios.
     */
    function tornarEditavel(linha, v) {
        linha.setEditable(true);

        var caminho = linha.getPath();
        var ajustando = false;   // setAt dentro do próprio listener dispara ele de novo

        var gravar = function () {
            var pts = [];
            caminho.forEach(function (p) { pts.push([p.lat(), p.lng()]); });
            pendentes.vaos[v.id] = pts;
            atualizarContaMover();
        };

        // A ponta pertence à caixa: arrastá-la descolaria o cabo. Em vez de bloquear o
        // arraste (o Google não permite travar um vértice só), ela volta para o lugar.
        caminho.addListener('set_at', function (i) {
            if (ajustando) return;
            var ultimo = caminho.getLength() - 1;
            if (i === 0 || i === ultimo) {
                var fixo = pontosDoVao(v)[i];
                ajustando = true;
                caminho.setAt(i, new google.maps.LatLng(fixo[0], fixo[1]));
                ajustando = false;
                FTTH.toast('info', 'A ponta do cabo acompanha a caixa — mova a caixa.');
                return;
            }
            gravar();
        });
        caminho.addListener('insert_at', gravar);
        caminho.addListener('remove_at', gravar);

        // Botão direito: no vértice remove, na linha insere no ponto clicado.
        linha.addListener('rightclick', function (e) {
            if (modo !== 'mover') return;
            if (e.vertex !== undefined && e.vertex !== null) {
                var ultimo = caminho.getLength() - 1;
                if (e.vertex === 0 || e.vertex === ultimo) {
                    FTTH.toast('erro', 'A ponta do cabo é da caixa e não pode sair.');
                    return;
                }
                if (caminho.getLength() <= 2) {
                    FTTH.toast('erro', 'O cabo precisa de ao menos dois pontos.');
                    return;
                }
                caminho.removeAt(e.vertex);
                return;
            }
            inserirVertice(caminho, e.latLng);
        });
    }

    /** Insere um vértice no trecho onde o clique caiu. */
    function inserirVertice(caminho, latLng) {
        var pts = [];
        caminho.forEach(function (p) { pts.push([p.lat(), p.lng()]); });

        // O trecho que menos cresce ao receber o ponto novo é aquele em que o clique caiu.
        var alvo = [latLng.lat(), latLng.lng()];
        var melhor = 1, menor = Infinity;
        for (var i = 1; i < pts.length; i++) {
            var extra = dist(pts[i - 1], alvo) + dist(alvo, pts[i]) - dist(pts[i - 1], pts[i]);
            if (extra < menor) { menor = extra; melhor = i; }
        }
        caminho.insertAt(melhor, latLng);   // o insert_at grava a pendência
    }

    /** Distância plana, só para comparar trechos — não é a do cálculo óptico. */
    function dist(a, b) {
        var dx = a[0] - b[0], dy = a[1] - b[1];
        return Math.sqrt(dx * dx + dy * dy);
    }

    /** Enquanto a caixa é arrastada, as pontas dos cabos dela acompanham na tela. */
    function arrastarPontasNaTela(caixaId) {
        if (!ultimoDesenho) return;
        (ultimoDesenho.vaos || []).forEach(function (v) {
            if (Number(v.caixa_ini_id) !== Number(caixaId)
                && Number(v.caixa_fim_id) !== Number(caixaId)) {
                return;
            }
            var pts = pontosDoVao(v).map(function (p) { return { lat: p[0], lng: p[1] }; });
            linhas.forEach(function (l) {
                if (l.ftthVaoId === v.id) l.setPath(pts);
            });
        });
    }

    function concluirMover() {
        var cfg = window.FTTH_MAPA || {};
        var caixas = Object.keys(pendentes.caixas).map(function (id) {
            return { id: parseInt(id, 10), lat: pendentes.caixas[id].lat,
                     lng: pendentes.caixas[id].lng };
        });
        var vaos = Object.keys(pendentes.vaos).map(function (id) {
            return { id: parseInt(id, 10), vertices: pendentes.vaos[id] };
        });
        if (!caixas.length && !vaos.length) return;

        var $b = $('#mover-concluir').prop('disabled', true);
        FTTH.chamar({
            url: 'mapa.php?ajax=mover',
            method: 'POST',
            data: { csrf: cfg.csrf, caixas: JSON.stringify(caixas), vaos: JSON.stringify(vaos) },
            onOk: function (d) {
                pendentes = { caixas: {}, vaos: {} };
                atualizarContaMover();
                carregar();
                FTTH.toast('ok', 'Mapa atualizado: ' + d.caixas + ' caixa(s) e '
                    + d.vaos + ' traçado(s). Os comprimentos foram recalculados.');
            },
            // Nada foi gravado — o lote é uma transação só —, então as pendências ficam
            // na tela para o usuário corrigir em vez de perder o trabalho.
            onErro: function (m) { FTTH.toast('erro', m); }
        }).always(function () { $b.prop('disabled', contarPendentes() === 0); });
    }

    function limparPin() {
        if (pinTemporario) { pinTemporario.setMap(null); pinTemporario = null; }
        pontoNovo = null;
    }

    /** Marca o ponto escolhido e abre o modal já sabendo onde a caixa vai nascer. */
    function marcarPonto(latLng) {
        limparPin();
        pontoNovo = latLng;
        pinTemporario = new google.maps.Marker({
            position: latLng, map: mapa, zIndex: 999,
            icon: icone(tipoSelecionado(), corSelecionada(), true)
        });
        $('#nc-ponto').text('Ponto: ' + latLng.lat().toFixed(6) + ', ' + latLng.lng().toFixed(6));
        abrirModalCaixa();
    }

    function tipoSelecionado() { return $('.ftth-tipo.ativo').data('tipo') || 'CTO'; }

    /**
     * A cor da caixa pode não estar na paleta (as importadas do KMZ têm a cor do arquivo).
     * Nesse caso a cor original é preservada, em vez de ser trocada em silêncio ao salvar.
     */
    var corEscolhida = null;
    function corSelecionada() {
        var ativa = $('.ftth-cor.ativo').data('cor');
        return ativa || corEscolhida || '#43A047';
    }

    function sugerirNome(base) {
        FTTH.chamar({
            url: 'mapa.php?ajax=sugerir_nome&regiao=' + regiao + '&tipo=' + tipoSelecionado()
                 + '&base=' + encodeURIComponent(base || ''),
            onOk: function (d) { if (d.nome) $('#nc-nome').val(d.nome); }
        });
    }

    function abrirModalCaixa() {
        editando = null;
        $('#modal-titulo').text('Nova caixa');
        $('#nc-criar').text('Criar caixa');
        $('#nc-sequencia-area').show();
        $('#nc-capacidade').val('');
        $('#nc-saida').empty();
        $('#modal-caixa').show();
        sugerirNome('');
        setTimeout(function () { $('#nc-nome').trigger('focus').trigger('select'); }, 50);
    }

    /** Mesmo modal do cadastro, agora preenchido com a caixa que existe. */
    function abrirModalEdicao(c) {
        editando = { id: parseInt(c.id, 10), versao: parseInt(c.versao, 10) };
        limparPin();

        $('#modal-titulo').text('Editar caixa');
        $('#nc-criar').text('Salvar');
        $('#nc-sequencia-area').hide();
        $('#nc-saida').empty();

        $('#nc-nome').val(c.nome);
        $('#nc-capacidade').val(c.capacidade || '');
        $('#nc-ponto').text('Ponto: ' + parseFloat(c.lat).toFixed(6) + ', ' + parseFloat(c.lng).toFixed(6)
            + ' — para mudar de lugar, use o modo Mover.');

        $('.ftth-tipo').removeClass('ativo');
        var $t = $('.ftth-tipo[data-tipo="' + c.tipo + '"]');
        ($t.length ? $t : $('.ftth-tipo[data-tipo="CTO"]')).addClass('ativo');

        corEscolhida = c.cor;
        $('.ftth-cor').removeClass('ativo');
        var $c = $('.ftth-cor[data-cor="' + String(c.cor).toUpperCase() + '"]');
        if ($c.length) {
            $c.addClass('ativo');
        } else {
            // Cor fora da paleta (veio do KMZ): mostra como está, sem forçar troca.
            $('#nc-saida').html('<p class="ftth-sub">Cor atual <span class="mono">' + esc(c.cor)
                + '</span> não está na paleta; escolha uma só se quiser trocar.</p>');
        }

        $('#modal-caixa').show();
        setTimeout(function () { $('#nc-nome').trigger('focus').trigger('select'); }, 50);
    }

    /** Um só botão para os dois casos: criar (com ponto marcado) e salvar edição. */
    function salvarCaixa() {
        var cfg = window.FTTH_MAPA || {};
        var nome = $.trim($('#nc-nome').val());
        if (!nome) {
            $('#nc-saida').html('<div class="ftth-aviso ftth-aviso--erro">Informe o nome da caixa.</div>');
            return;
        }

        if (editando) {
            $('#nc-criar').prop('disabled', true);
            FTTH.chamar({
                url: 'mapa.php?ajax=alterar_caixa',
                method: 'POST',
                data: {
                    csrf: cfg.csrf, id: editando.id, versao: editando.versao,
                    nome: nome, tipo: tipoSelecionado(), cor: corSelecionada(),
                    capacidade: $('#nc-capacidade').val()
                },
                onOk: function (d) {
                    var id = editando.id;
                    editando = null;
                    $('#modal-caixa').hide();
                    carregar();
                    abrirFicha(id);
                },
                onErro: function (m) {
                    $('#nc-saida').html('<div class="ftth-aviso ftth-aviso--erro">' + esc(m) + '</div>');
                }
            }).always(function () { $('#nc-criar').prop('disabled', false); });
            return;
        }

        if (!pontoNovo) {
            $('#nc-saida').html('<div class="ftth-aviso ftth-aviso--erro">Marque o ponto no mapa.</div>');
            return;
        }
        var sequencia = $('#nc-sequencia').is(':checked');

        $('#nc-criar').prop('disabled', true);
        FTTH.chamar({
            url: 'mapa.php?ajax=nova_caixa',
            method: 'POST',
            data: {
                csrf: cfg.csrf, regiao: regiao,
                nome: nome, tipo: tipoSelecionado(), cor: corSelecionada(),
                capacidade: $('#nc-capacidade').val(),
                lat: pontoNovo.lat(), lng: pontoNovo.lng()
            },
            onOk: function (d) {
                limparPin();
                $('#modal-caixa').hide();
                carregar();
                if (sequencia) {
                    // Continua no modo Caixa: o próximo clique já marca a caixa seguinte.
                    FTTH.toast('ok', nome + ' criada. Clique no mapa para a próxima.');
                } else {
                    definirModo('navegar');
                    abrirFicha(d.id);
                }
            },
            onErro: function (m) {
                $('#nc-saida').html('<div class="ftth-aviso ftth-aviso--erro">' + esc(m) + '</div>');
            }
        }).always(function () { $('#nc-criar').prop('disabled', false); });
    }

    window.ftthIniciarMapa = function () {
        var cfg = window.FTTH_MAPA || {};
        regiao = cfg.regiao || 0;

        ajustarAltura();
        window.addEventListener('resize', ajustarAltura);

        // Os pontos de interesse do Google (farmácia, loja, mercado, ponto de ônibus)
        // disputam a tela com as caixas e os cabos, que são o assunto aqui.
        var SEM_POI = [
            { featureType: 'poi',     stylers: [{ visibility: 'off' }] },
            { featureType: 'transit', stylers: [{ visibility: 'off' }] }
        ];

        var tipo = cfg.tipo || 'hybrid';

        mapa = new google.maps.Map(document.getElementById('mapa'), {
            center: { lat: cfg.lat, lng: cfg.lng },
            zoom: cfg.zoom || 15,
            // Tipo de mapa vem de tab_ftth_config (mapa_tipo). Padrão: híbrido — satélite
            // com nome de rua, que é o que serve para o técnico em campo.
            //
            // Em satélite e híbrido o Google IGNORA `styles` — não há como esconder os POIs
            // por ali. Por isso o híbrido é montado à mão logo abaixo: satélite puro (que
            // não tem POI nenhum) mais uma camada só de rótulos.
            mapTypeId: tipo === 'hybrid' ? 'satellite' : tipo,
            mapTypeControl: true,
            streetViewControl: true,
            fullscreenControl: true,
            gestureHandling: 'greedy',
            styles: SEM_POI
        });

        // A camada de rótulos: geometria desligada deixa os tiles transparentes, então o
        // satélite continua aparecendo por baixo e só nome de rua e bairro ficam por cima.
        var rotulos = new google.maps.StyledMapType([
            { elementType: 'geometry', stylers: [{ visibility: 'off' }] },
            { featureType: 'poi',      stylers: [{ visibility: 'off' }] },
            { featureType: 'transit',  stylers: [{ visibility: 'off' }] },
            { featureType: 'administrative', elementType: 'geometry',
              stylers: [{ visibility: 'off' }] }
        ], { name: 'rotulos' });

        /** O overlay só faz sentido sobre satélite; em roadmap ele duplicaria os nomes. */
        function ajustarRotulos() {
            var sobreSatelite = mapa.getMapTypeId() === 'satellite';
            var jaTem = mapa.overlayMapTypes.getLength() > 0;
            if (sobreSatelite && !jaTem) {
                mapa.overlayMapTypes.insertAt(0, rotulos);
            } else if (!sobreSatelite && jaTem) {
                mapa.overlayMapTypes.clear();
            }
        }
        if (tipo === 'hybrid') ajustarRotulos();
        mapa.addListener('maptypeid_changed', ajustarRotulos);

        // Só busca depois que o usuário parou de mexer — evita uma consulta por pixel.
        mapa.addListener('idle', function () {
            clearTimeout(esperando);
            esperando = setTimeout(carregar, 250);
        });

        // Clique no mapa (fora de qualquer item): ancora a caixa em espera, ou fecha a ficha.
        // Clique em marcador ou em cabo não chega aqui.
        mapa.addListener('click', function (e) {
            if (modo === 'caixa') {
                marcarPonto(e.latLng);
                return;
            }
            if (modo === 'cabo') {
                pontoTracado({ tipo: 'VERTICE', lat: e.latLng.lat(), lng: e.latLng.lng() });
                return;
            }
            $('#painel').hide();
        });

        $('.ftth-modo').on('click', function () {
            if ($(this).prop('disabled')) return;
            definirModo($(this).data('modo'));
        });

        // Trocar tipo ou cor atualiza o pino que já está no mapa, para o usuário ver o resultado.
        $(document).on('click', '.ftth-tipo', function () {
            $('.ftth-tipo').removeClass('ativo');
            $(this).addClass('ativo');
            sugerirNome('');
            if (pinTemporario) pinTemporario.setIcon(icone(tipoSelecionado(), corSelecionada(), true));
        });
        $(document).on('click', '.ftth-cor', function () {
            $('.ftth-cor').removeClass('ativo');
            $(this).addClass('ativo');
            if (pinTemporario) pinTemporario.setIcon(icone(tipoSelecionado(), corSelecionada(), true));
        });

        // Card flutuante do traçado
        $('#cabo-desfazer').on('click', function () {
            tracado.pop();
            atualizarTracado();
        });
        $('#cabo-finalizar').on('click', finalizarTracado);
        $('#cabo-cancelar').on('click', function () {
            if (tracado.length && !confirm('Descartar o traçado?')) return;
            limparTracado();
            iniciarTracado();
        });
        $('#cb-salvar').on('click', salvarCabo);
        $('#cb-cancelar, #cabo-modal-fechar').on('click', function () {
            $('#modal-cabo').hide();   // volta para o traçado, que continua desenhado
        });
        $(document).on('click', '#cb-cores .ftth-cor', function () {
            $('#cb-cores .ftth-cor').removeClass('ativo');
            $(this).addClass('ativo');
            atualizarTracado();
        });

        // Ficha do cabo: as ações agem sobre o vão que está aberto no painel.
        $(document).on('click', '#fb-editar', function () {
            if (caboAberto) editarCabo(caboAberto);
        });
        $(document).on('click', '#fb-excluir', function () {
            if (caboAberto) excluirCabo(caboAberto);
        });
        $(document).on('click', '#fb-centralizar', function () { enquadrarVao(caboAberto); });

        $('#ec-salvar').on('click', salvarEdicaoCabo);
        $('#ec-cancelar, #ec-fechar').on('click', function () {
            $('#modal-editar-cabo').hide();
        });
        $(document).on('click', '#ec-cores .ftth-cor', function () {
            $('#ec-cores .ftth-cor').removeClass('ativo');
            $(this).addClass('ativo');
        });

        $('#nc-criar').on('click', salvarCaixa);
        $('#nc-nome').on('keydown', function (e) { if (e.key === 'Enter') salvarCaixa(); });
        // Cancelar tira o pino provisório mas mantém o modo Caixa: quase sempre o usuário
        // só errou o ponto e vai clicar de novo.
        $('#nc-cancelar, #modal-fechar').on('click', function () {
            $('#modal-caixa').hide();
            if (editando) {
                editando = null;          // saiu da edição: o mapa continua como estava
                return;
            }
            limparPin();
            FTTH.toast('info', 'Clique no mapa para marcar o ponto da caixa.');
        });
        $(document).on('keydown', function (e) { if (e.key === 'Escape') definirModo('navegar'); });

        $('#sel-regiao').on('change', function () {
            var o = this.options[this.selectedIndex];
            regiao = parseInt(this.value, 10) || 0;
            var lat = parseFloat(o.getAttribute('data-lat'));
            var lng = parseFloat(o.getAttribute('data-lng'));
            if (!isNaN(lat) && !isNaN(lng)) {
                mapa.setCenter({ lat: lat, lng: lng });
                mapa.setZoom(parseInt(o.getAttribute('data-zoom'), 10) || 15);
            }
            carregar();
        });

        $('.cam').on('change', function () {
            camadas[this.value] = this.checked;
            atualizarContaCamadas();
            carregar();
        });

        // O menu de camadas fica aberto enquanto se marcam várias de uma vez; fecha ao
        // clicar fora. Sem o stopPropagation, o próprio clique no botão fecharia de volta.
        // --- clientes na porta
        $(document).on('click', '#fc-clientes', function () {
            abrirClientes(parseInt($(this).data('id'), 10));
        });
        $('#cl-fechar, #cl-cancelar').on('click', function () {
            $('#modal-clientes').hide();
            atualizarFichaAberta();
        });

        // --- rota óptica
        $(document).on('click', '#fc-sinal', function () {
            abrirRota(parseInt($(this).data('id'), 10), $(this).data('nome'));
        });
        $('#rt-fechar, #rt-cancelar').on('click', function () {
            $('#modal-rota').hide();
        });

        // A busca dispara depois que o usuário para de digitar: uma consulta por tecla
        // varreria os 800 clientes do cadastro a cada letra.
        var timerCliente = null;
        $(document).on('input', '.js-busca-cliente', function () {
            var $campo = $(this);
            var $lista = $campo.next('.ftth-porta-lista');
            var termo  = $campo.val();

            clearTimeout(timerCliente);
            if (termo.length < 2) { $lista.empty(); return; }

            timerCliente = setTimeout(function () {
                FTTH.chamar({
                    url: 'mapa.php?ajax=buscar_cliente&q=' + encodeURIComponent(termo),
                    onOk: function (d) {
                        $lista.html(htmlResultadosCliente(d.clientes || []));
                        // Nas últimas portas a lista nasce abaixo da área visível do
                        // modal, que rola: sem isto o usuário digitaria e não veria nada.
                        if ($lista[0] && $lista[0].scrollIntoView) {
                            $lista[0].scrollIntoView({ block: 'nearest' });
                        }
                    },
                    onErro: function (m) { $lista.html('<div class="ftth-porta-item">' + esc(m) + '</div>'); }
                });
            }, 300);
        });

        $(document).on('click', '.js-escolher-cliente', function () {
            var $linha = $(this).closest('.ftth-porta-linha');
            var ja = String($(this).data('ja') || '');

            // Cliente que já está em outra porta só muda de lugar se o usuário confirmar —
            // o servidor recusaria com FTTH-TOP-012 e a tela ficaria sem explicação.
            if (ja && !confirm($(this).data('login') + ' já está em ' + ja
                             + '.\n\nMover para esta porta?')) {
                return;
            }
            vincularCliente(parseInt($linha.data('splitter'), 10),
                            parseInt($linha.data('numero'), 10),
                            parseInt($(this).data('id'), 10), !!ja);
        });

        $(document).on('click', '.js-desvincular', function () {
            desvincularCliente(parseInt($(this).data('cliente'), 10), String($(this).data('login')));
        });

        $('#mover-concluir').on('click', concluirMover);
        $('#mover-descartar').on('click', function () {
            if (contarPendentes() > 0
                && !confirm('Descartar as alterações do mapa?')) { return; }
            sairDoModoMover();
            $('#mover-card').show();     // continua no modo, só sem pendências
            atualizarContaMover();
        });

        $('#btn-camadas').on('click', function (ev) {
            ev.stopPropagation();
            $('#camadas').toggleClass('aberto');
        });
        $('#camadas-lista').on('click', function (ev) { ev.stopPropagation(); });
        $(document).on('click', function () { $('#camadas').removeClass('aberto'); });
        $(document).on('keydown', function (ev) {
            if (ev.key === 'Escape') $('#camadas').removeClass('aberto');
        });
        atualizarContaCamadas();

        var timerBusca = null;
        $('#busca').on('input', function () {
            var t = this.value;
            clearTimeout(timerBusca);
            timerBusca = setTimeout(function () { buscar(t); }, 250);
        });

        $(document).on('click', '.ftth-busca-item[data-lat]', function () {
            var lat = parseFloat($(this).data('lat'));
            var lng = parseFloat($(this).data('lng'));
            var id  = parseInt($(this).data('id'), 10);
            if (!isNaN(lat)) {
                mapa.setCenter({ lat: lat, lng: lng });
                mapa.setZoom(19);
            }
            $('#busca-lista').hide();
            if (id && $(this).data('tipo') === 'caixa') abrirFicha(id);
        });

        $(document).on('click', function (e) {
            if (!$(e.target).closest('.ftth-busca').length) $('#busca-lista').hide();
        });

        $('#painel-fechar').on('click', function () { $('#painel').hide(); });

        $(document).on('click', '#fc-excluir', function () {
            excluirCaixa(parseInt($(this).data('id'), 10),
                         parseInt($(this).data('versao'), 10),
                         String($(this).data('nome')));
        });
        $(document).on('click', '#fc-centralizar', function () {
            mapa.setCenter({ lat: parseFloat($(this).data('lat')), lng: parseFloat($(this).data('lng')) });
            mapa.setZoom(20);
        });
        $(document).on('click', '#fc-copiar', function () {
            var texto = $('#fc-coord').text();
            var $b = $(this);
            if (navigator.clipboard) {
                navigator.clipboard.writeText(texto).then(function () { $b.text('copiado'); });
            } else {
                window.prompt('Copie a coordenada:', texto);
            }
        });
        $(document).on('click', '#fc-editar', function () {
            var id = parseInt($(this).data('id'), 10);
            FTTH.chamar({
                url: 'mapa.php?ajax=ficha&id=' + id,
                onOk: abrirModalEdicao,
                onErro: function (m) {
                    $('#fc-saida').html('<div class="ftth-aviso ftth-aviso--erro">' + esc(m) + '</div>');
                }
            });
        });

        $(document).on('click', '#q-importar', function () {
            decidirQuarentena(parseInt($(this).data('id'), 10), 'importar');
        });
        $(document).on('click', '#q-descartar', function () {
            if (!confirm('Descartar este item? Ele não entra na rede.')) return;
            decidirQuarentena(parseInt($(this).data('id'), 10), 'descartar');
        });
    };
})();
