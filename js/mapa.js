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

    // Ordem de empilhamento no mapa. A caixa por cima do cabo não é estética: é o que faz o
    // clique cair no marcador, e não na alça de edição que o Google desenha no mesmo ponto.
    var Z_QUARENTENA = 2;   // o que ainda não é rede fica por baixo do que já é
    var Z_CABO = 10;
    var Z_CAIXA = 500;
    var Z_CAIXA_ARRASTANDO = 600;

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

    /**
     * Pede o que está na área visível. `soArea` vem do arrasto/zoom do mapa: aí nada na
     * rede mudou. Sem ele a chamada vem de uma alteração (criar, mover, excluir), e a lista
     * de pontos do painel também fica velha.
     */
    function carregar(soArea) {
        if (!mapa || !regiao) return;
        if (!soArea) recarregarPontos();
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
        // Filtro da aba Pontos: o que não atende fica translúcido — some do foco, não do mapa.
        var destaque = idsDoFiltro();
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
                // A caixa fica SEMPRE acima do cabo e das alças de edição dele. As alças
                // do Google nascem no centro da caixa, e sem isto o clique pegava a alça em
                // vez do marcador: o técnico arrastava a ponta do cabo achando que estava
                // arrastando a caixa.
                zIndex: pend ? Z_CAIXA_ARRASTANDO : Z_CAIXA,
                opacity: destaque && !destaque[c.id] ? 0.3 : 1,
                label: mapa.getZoom() >= ((window.FTTH_MAPA || {}).rotulo_zoom || 17)
                    ? { text: c.nome, fontSize: '11px', color: '#22303F', className: 'ftth-rotulo' }
                    : null
            });
            m.addListener('click', function () {
                // No modo Cabo, clicar numa caixa ancora o traçado nela em vez de abrir a ficha.
                if (modo === 'cabo') {
                    // Reserva não é ponta de cabo: ela nasce no meio de um cabo já lançado.
                    if (c.tipo === 'RESERVA') {
                        FTTH.toast('erro', 'Reserva não recebe cabo: ela fica no meio de um cabo já lançado.');
                        return;
                    }
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
                    pendentes.caixas[c.id] = { lat: ev.latLng.lat(), lng: ev.latLng.lng(),
                                               nome: c.nome, tipo: c.tipo };
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
            // Os pontos menores e mais claros no meio de cada trecho são as alças do próprio
            // Google: arrastar uma delas cria vértice. Dizer isso evita que elas sejam lidas
            // como "vértices que apareceram sozinhos" depois de mexer no cabo.
            $('#mover-dica').text(editaveis
                ? 'Arraste pontos e vértices. As alças claras no meio do trecho criam vértice. '
                  + 'Botão direito: na linha insere, no vértice remove.'
                : d.vaos.length + ' cabos na tela: aproxime para editar os traçados. '
                  + 'Os pontos continuam arrastáveis.');
        }

        if (camadas.CABOS) {
            d.vaos.forEach(function (v) {
                // pontosDoVao é quem sabe montar o traçado de verdade: ele aplica o vértice
                // arrastado à mão E a ponta da caixa que está sendo movida. Desenhar direto de
                // v.vertices fazia o cabo voltar para o lugar antigo a cada zoom, enquanto a
                // caixa ficava onde o técnico soltou.
                var pts = pontosDoVao(v);
                if (pts.length < 2) return;
                var caminho = pts.map(function (p) { return { lat: p[0], lng: p[1] }; });
                var l = new google.maps.Polyline({
                    path: caminho, map: mapa,
                    strokeColor: v.cor_rota || '#00E676',
                    strokeWeight: (window.FTTH_MAPA || {}).cabo_espessura || 5,
                    strokeOpacity: destaque ? 0.35 : 0.95,
                    cursor: cursorDoCabo(),
                    zIndex: Z_CABO
                });
                l.ftthVaoId = v.id;      // para o modo Mover achar a linha deste vão
                l.addListener('click', function (e) {
                    if (modo === 'mover') return;   // no modo Mover o clique é para editar
                    // Nos modos de desenho, clicar no cabo é clicar no mapa: a polilinha tem
                    // 5 px de espessura e engolia o clique, abrindo a ficha do cabo bem na
                    // hora em que o técnico queria marcar uma caixa em cima dele.
                    if (modo === 'caixa' || modo === 'cabo') {
                        cliqueNoMapa(e);
                        return;
                    }
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
                        zIndex: Z_QUARENTENA
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
                        strokeOpacity: 0.4,
                        zIndex: Z_QUARENTENA
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
            item(d.caixas.length, 'pontos')
            + item(d.vaos.length, 'vãos')
            // Filtro ligado: quantos dos pontos na tela ele destaca — o lembrete de que há filtro.
            + (destaque ? item(d.caixas.filter(function (x) { return destaque[x.id]; }).length,
                               ROTULO_FILTRO[filtroPontos], ' ftth-resumo-item--pend') : '')
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
                if (c.tipo === 'RESERVA') {
                    painel(fichaReserva(c));
                    return;
                }
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
                     +  '';

                painel(html);
            },
            onErro: function (m) { painel('<div class="ftth-aviso ftth-aviso--erro">' + esc(m) + '</div>'); }
        });
    }

    /**
     * Ficha da Reserva: é cabo enrolado, não caixa. Sem diagrama, clientes nem sinal — só
     * os metros, o cabo em que ela está e quanto esse vão soma de reserva no total.
     */
    function fichaReserva(c) {
        var v = c.vao_reserva;
        var quem = c.alterado_por || c.criado_por || '—';
        var quando = c.alterado_em || c.criado_em || '';

        return '<div class="ftth-ficha-topo">'
          +   '<h3 class="ftth-painel-titulo">' + marcaDoTipo(c.tipo, c.cor) + ' ' + esc(c.nome) + '</h3>'
          +   '<p class="ftth-sub">Reserva técnica · ' + esc(c.regiao)
          +     (v ? '' : ' <span class="ftth-selo ftth-selo--pend">sem cabo</span>') + '</p>'
          + '</div>'
          + '<dl class="ftth-ficha">'
          +   linha('Metros de reserva', c.reserva_m ? metros(c.reserva_m) : '—')
          +   linha('Cabo', v ? esc((v.cabo || 'sem nome') + ' · ' + v.tipo) : '—')
          +   linha('Trecho', v ? esc(v.caixa_ini + ' → ' + v.caixa_fim) : '—')
          +   linha('Reserva no trecho', v ? metros(v.reserva_m) : '—')
          +   linha('Comprimento óptico', v ? metros(v.comprimento_optico) : '—')
          +   linha('Origem', c.origem === 'kmz' ? 'importada do KMZ' : 'cadastro manual')
          + '</dl>'
          + '<p class="ftth-ficha-autor">Por ' + esc(quem) + (quando ? ' · ' + esc(quando) : '') + '</p>'
          + (v ? '' : '<div class="ftth-aviso">Esta reserva não está em nenhum cabo e não soma metros. '
                    + 'Edite e informe os metros: ela passa a ser do cabo que está sob o ponto.</div>')
          + '<div class="ftth-ficha-acoes">'
          +   '<button class="ftth-acao" id="fc-centralizar" data-lat="' + c.lat + '" data-lng="' + c.lng + '">'
          +     '<i class="bi-geo-fill"></i> Ver no mapa</button>'
          +   '<button class="ftth-acao" id="fc-editar" data-id="' + c.id + '">'
          +     '<i class="bi-pencil-square"></i> Editar</button>'
          +   '<button class="ftth-acao ftth-acao--perigo" id="fc-excluir" data-id="' + c.id + '"'
          +     ' data-versao="' + c.versao + '" data-nome="' + esc(c.nome) + '">'
          +     '<i class="bi-trash3-fill"></i> Excluir</button>'
          + '</div>'
          + '<p class="ftth-sub ftth-coord">Localização: <span class="mono" id="fc-coord">'
          +   parseFloat(c.lat).toFixed(6) + ', ' + parseFloat(c.lng).toFixed(6) + '</span> '
          +   '<button class="ftth-copiar" id="fc-copiar" title="Copiar">copiar</button></p>';
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

    /**
     * Excluir caixa — com um desvio quando ela é só uma emenda no meio de um cabo.
     *
     * Nesse caso, apagar a caixa sem mais nada deixaria o cabo partido em dois lances que não
     * se encontram. Então o servidor é consultado antes: se a caixa só faz as fibras passarem
     * entre dois trechos do mesmo cabo, a exclusão junta os trechos de volta num lance só — e
     * isso é dito ao operador antes, porque excluir caixa e reescrever cabo são duas coisas.
     */
    function excluirCaixa(id, versao, nome) {
        var cfg = window.FTTH_MAPA || {};

        FTTH.chamar({
            url: 'mapa.php?ajax=emenda_simples&caixa=' + id,
            onOk: function (d) {
                var e = d && d.emenda;
                if (e) {
                    var metros = Number(e.metros).toFixed(1).replace('.', ',');
                    var msg = 'A caixa "' + nome + '" só emenda os dois trechos do cabo '
                            + (e.cabo_nome ? '"' + e.cabo_nome + '"' : e.nome_ini + ' → ' + e.nome_fim)
                            + '.\n\nExcluir vai juntar os trechos num lance único de ' + metros + ' m'
                            + (e.ligacoes ? ', desfazendo as ' + e.ligacoes + ' passagens desta caixa' : '')
                            + '.\n\nConfirma?';
                    if (!confirm(msg)) return;
                    unirEExcluir(id, versao, nome, e);
                    return;
                }
                if (!confirm('Excluir "' + nome + '"?\n\nSai do mapa, mas continua no histórico.')) {
                    return;
                }
                excluirCaixaMesmo(id, versao);
            },
            // Se a consulta falhar, segue o caminho normal: o servidor recusa se não puder.
            onErro: function () {
                if (!confirm('Excluir "' + nome + '"?\n\nSai do mapa, mas continua no histórico.')) {
                    return;
                }
                excluirCaixaMesmo(id, versao);
            }
        });
    }

    function excluirCaixaMesmo(id, versao) {
        var cfg = window.FTTH_MAPA || {};
        FTTH.chamar({
            url: 'mapa.php?ajax=excluir_caixa',
            method: 'POST',
            data: { csrf: cfg.csrf, id: id, versao: versao },
            onOk: function () {
                $('#painel').hide();
                carregar();
                FTTH.toast('ok', 'Caixa excluída.');
            },
            // O recado vai para o toast, e não para dentro da ficha: a ficha fecha, o toast
            // fica onde o operador está olhando.
            onErro: function (m) { FTTH.toast('erro', m); }
        });
    }

    function unirEExcluir(id, versao, nome, emenda) {
        var cfg = window.FTTH_MAPA || {};
        FTTH.chamar({
            url: 'mapa.php?ajax=unir_vaos',
            method: 'POST',
            data: { csrf: cfg.csrf, caixa: id, versao: versao },
            onOk: function (d) {
                $('#painel').hide();
                carregar();
                FTTH.toast('ok', nome + ' excluída e o cabo voltou a ser um lance só: '
                    + Number(d.metros).toFixed(1).replace('.', ',') + ' m entre '
                    + d.nome_ini + ' e ' + d.nome_fim + '.');
            },
            onErro: function (m) { FTTH.toast('erro', m); }
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
          +     linha('Reserva técnica', parseFloat(v.reserva_m) > 0
                      ? metros(v.reserva_m) + ' (' + v.reservas + ' reserva' + (v.reservas == 1 ? '' : 's') + ')'
                      : '—')
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
        ajustarToasts();
    }

    /**
     * Em tela estreita os cards de Cabo e Mover ficam na base do mapa, justamente onde o toast
     * nasce. Enquanto um deles estiver aberto, o toast sobe e aparece logo acima do card.
     */
    function ajustarToasts() {
        var raiz = document.documentElement;
        var estreita = window.matchMedia && window.matchMedia('(max-width: 470px)').matches;
        var card = $('#cabo-card:visible, #mover-card:visible, #regiao-card:visible').get(0);
        if (estreita && card) {
            var topo = card.getBoundingClientRect().top;
            raiz.style.setProperty('--ftth-toast-base', Math.max(20, window.innerHeight - topo + 8) + 'px');
        } else {
            raiz.style.removeProperty('--ftth-toast-base');
        }
    }

    function limparTracado() {
        tracado = [];
        if (linhaTracado) { linhaTracado.setMap(null); linhaTracado = null; }
        pinosTracado.forEach(function (p) { p.setMap(null); });
        pinosTracado = [];
        $('#cabo-card').hide();
        ajustarToasts();
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
        atualizarFinalizar();
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
        if (!podeFinalizarCabo()) {
            FTTH.toast('erro', 'O cabo precisa estar ancorado em duas caixas e terminar numa delas.');
            return;
        }
        // A configuração já veio antes do desenho: finalizar é gravar.
        salvarCabo();
    }

    /**
     * Finalizar só acende com o cabo ancorado em duas caixas e terminando numa delas — o
     * mesmo que o servidor exige. Botão apagado diz a regra antes do erro, não depois.
     */
    function podeFinalizarCabo() {
        var caixas = tracado.filter(function (p) { return p.tipo === 'CAIXA'; }).length;
        return caixas >= 2 && tracado[tracado.length - 1].tipo === 'CAIXA';
    }

    function atualizarFinalizar() {
        $('#cabo-finalizar').prop('disabled', !podeFinalizarCabo())
            .attr('title', podeFinalizarCabo() ? '' : 'O cabo precisa estar ancorado em duas caixas');
    }

    function desenhandoCabo() { return $('#cabo-card').is(':visible'); }

    /**
     * Passo 1 do modo Cabo: o popup. Na entrada ele pede para desenhar; reaberto pelo ⚙ do
     * card, só volta para o traçado — e a cor trocada ali já pinta o que está desenhado.
     */
    function abrirConfigCabo() {
        var desenhando = desenhandoCabo();
        $('#cabo-modal-titulo').text(desenhando ? 'Configuração do cabo' : 'Novo cabo');
        $('#cb-salvar').text(desenhando ? 'Continuar desenhando' : 'Desenhar');
        var caixas = tracado.filter(function (p) { return p.tipo === 'CAIXA'; }).length;
        $('#cabo-resumo').text(desenhando
            ? tracado.length + ' ponto(s) marcados · ' + caixas + ' caixa(s). O traçado continua na tela.'
            : 'Configure e desenhe: o traçado começa numa caixa e termina em outra.');
        $('#cb-saida').empty();
        $('#modal-cabo').show();
        setTimeout(function () { $('#cb-nome').trigger('focus'); }, 50);
    }

    function confirmarConfigCabo() {
        $('#modal-cabo').hide();
        if (desenhandoCabo()) {
            atualizarTracado();                  // a cor pode ter mudado
            return;
        }
        iniciarTracado();
        FTTH.toast('info', 'Clique na caixa onde o cabo começa.');
    }

    function salvarCabo() {
        var cfg = window.FTTH_MAPA || {};
        $('#cabo-finalizar').prop('disabled', true);
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
                limparTracado();
                $('#cb-nome').val(''); $('#cb-fabricante').val('');
                // Gravou: o desenho acabou e o mapa volta para o modo Navegar. Antes o modo
                // Cabo continuava ligado sem o card na tela, e o próximo traçado começava
                // sem contador, sem dica e sem os botões.
                definirModo('navegar');
                // A troca de modo não redesenha sozinha (só a entrada no modo edição faz
                // isso), e sem esta linha o cabo recém-criado só aparecia no próximo
                // movimento do mapa.
                carregar();
                FTTH.toast('ok', d.tipo + ' criado: ' + d.vaos.length + ' vão(s), '
                    + Math.round(d.metros) + ' m.');
            },
            // O traçado continua na tela: o usuário corrige (⚙ ou Desfazer) e finaliza de novo.
            onErro: function (m) { FTTH.toast('erro', m); }
        }).always(atualizarFinalizar);
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
            // A mãozinha em cima do cabo promete abrir a ficha. Nos modos de desenho o
            // clique ali marca ponto, então o cursor tem de dizer a mesma coisa que o mapa.
            linhas.forEach(function (l) { l.setOptions({ cursor: cursorDoCabo() }); });
        }

        if (novo === 'caixa') {
            FTTH.toast('info', 'Clique no mapa para marcar o ponto.');
        } else if (novo === 'cabo') {
            abrirConfigCabo();          // configura primeiro; o traçado começa no "Desenhar"
        } else if (novo === 'mover') {
            FTTH.toast('info', 'Arraste pontos. Clique num cabo para soltar os vértices.');
            $('#mover-card').show();
            ajustarToasts();
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
        ajustarToasts();
        carregar();                     // volta o que está no banco
    }

    /**
     * Geometria atual do vão: a pendente, se o usuário mexeu no traçado, senão a do banco.
     *
     * As PONTAS são sempre derivadas da posição da caixa, nunca guardadas — o mesmo que o
     * servidor faz. Assim arrastar uma caixa com três cabos não vira "4 alterações": a
     * alteração é uma só, a da caixa, e os cabos apenas acompanham.
     */
    /**
     * Onde a caixa está agora: a posição arrastada, se houver, senão a do último desenho.
     * É a única fonte de verdade para as pontas de cabo — nunca o traçado guardado, que é
     * justamente o que pode estar torto depois de um arrasto acidental na alça da ponta.
     */
    function posicaoDaCaixa(caixaId) {
        var pend = pendentes.caixas[caixaId];
        if (pend) return [pend.lat, pend.lng];
        var achada = null;
        ((ultimoDesenho && ultimoDesenho.caixas) || []).forEach(function (c) {
            if (Number(c.id) === Number(caixaId)) {
                achada = [parseFloat(c.lat), parseFloat(c.lng)];
            }
        });
        return achada;
    }

    function pontosDoVao(v) {
        var pts = (pendentes.vaos[v.id] || (v.vertices || []).map(function (p) {
            return [parseFloat(p[0]), parseFloat(p[1])];
        })).slice();

        var ini = posicaoDaCaixa(v.caixa_ini_id);
        var fim = posicaoDaCaixa(v.caixa_fim_id);
        if (ini) pts[0] = ini;
        if (fim && pts.length > 1) pts[pts.length - 1] = fim;
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

        /**
         * Grava a pendência. Só LÊ a polilinha: as pontas são trocadas no array que vai para
         * `pendentes`, nunca no path.
         *
         * Mexer no path de dentro de um listener do próprio path deixa o editor do Google em
         * estado inconsistente — dava "Cannot read properties of undefined" no meio de um
         * insertAt e fazia aparecer vértice fantasma no cabo.
         */
        var gravar = function () {
            var pts = [];
            caminho.forEach(function (p) { pts.push([p.lat(), p.lng()]); });
            var ini = posicaoDaCaixa(v.caixa_ini_id);
            var fim = posicaoDaCaixa(v.caixa_fim_id);
            if (ini) pts[0] = ini;
            if (fim && pts.length > 1) pts[pts.length - 1] = fim;
            pendentes.vaos[v.id] = pts;
            atualizarContaMover();
        };

        /**
         * Devolve as pontas para cima das caixas — depois que o Google terminar o que estava
         * fazendo. O setTimeout(0) é o que separa a nossa correção do evento dele.
         */
        var colarPontasDepois = function () {
            setTimeout(function () {
                var ultimo = caminho.getLength() - 1;
                if (ultimo < 1) return;
                var ini = posicaoDaCaixa(v.caixa_ini_id);
                var fim = posicaoDaCaixa(v.caixa_fim_id);
                ajustando = true;
                if (ini) caminho.setAt(0, new google.maps.LatLng(ini[0], ini[1]));
                if (fim) caminho.setAt(ultimo, new google.maps.LatLng(fim[0], fim[1]));
                ajustando = false;
                gravar();
            }, 0);
        };

        // A ponta pertence à caixa: arrastá-la descolaria o cabo. O Google não deixa travar um
        // vértice só, então ela volta sozinha para cima da caixa.
        caminho.addListener('set_at', function (i) {
            if (ajustando || linha.ftthColando) return;
            var ultimo = caminho.getLength() - 1;
            if (i === 0 || i === ultimo) {
                colarPontasDepois();
                FTTH.toast('info', 'A ponta do cabo mora na caixa — para movê-la, arraste a caixa.');
                return;
            }
            gravar();
        });
        // Criar e remover vértice não avisam nada: quem arrasta a alça vê o ponto nascer, e
        // um toast a cada gesto atrapalha mais do que explica. O que a alça clara faz está
        // dito na dica do card, que fica à vista o tempo todo.
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
                if (l.ftthVaoId !== v.id) return;
                // setPath troca o MVCArray inteiro, e os listeners do tornarEditavel ficavam
                // presos ao array velho: o cabo arrastado depois disso não gravava pendência
                // e voltava para o lugar antigo no Concluir. Por isso, na linha editável, só
                // as pontas mudam, no mesmo array.
                var caminho = l.getPath();
                var ultimo = caminho.getLength() - 1;
                if (!l.getEditable() || ultimo < 1) {
                    l.setPath(pts);
                    return;
                }
                l.ftthColando = true;    // não é o usuário puxando a ponta: sem aviso
                caminho.setAt(0, new google.maps.LatLng(pts[0].lat, pts[0].lng));
                caminho.setAt(ultimo, new google.maps.LatLng(pts[pts.length - 1].lat,
                                                             pts[pts.length - 1].lng));
                l.ftthColando = false;
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
        var nomesMovidos = {};
        Object.keys(pendentes.caixas).forEach(function (id) {
            nomesMovidos[id] = pendentes.caixas[id].nome;
        });
        // Reserva arrastada não é candidata a emenda: o servidor a cola no cabo sozinho.
        var reservas = {};
        Object.keys(pendentes.caixas).forEach(function (id) {
            if (pendentes.caixas[id].tipo === 'RESERVA') reservas[id] = true;
        });
        if (!caixas.length && !vaos.length) return;

        var $b = $('#mover-concluir').prop('disabled', true);
        FTTH.chamar({
            url: 'mapa.php?ajax=mover',
            method: 'POST',
            data: { csrf: cfg.csrf, caixas: JSON.stringify(caixas), vaos: JSON.stringify(vaos) },
            onOk: function (d) {
                // Guarda antes de limpar: a oferta de emenda precisa saber onde cada caixa parou.
                var movidas = caixas.filter(function (c) { return !reservas[c.id]; }).map(function (c) {
                    return { id: c.id, nome: nomesMovidos[c.id] || 'A caixa',
                             lat: c.lat, lng: c.lng };
                });
                pendentes = { caixas: {}, vaos: {} };
                // Gravou: a edição acabou. O card se fecha e o mapa volta ao modo Navegar,
                // que é onde o técnico confere o resultado do que acabou de fazer.
                definirModo('navegar');
                FTTH.toast('ok', 'Mapa atualizado: ' + d.caixas + ' caixa(s) e '
                    + d.vaos + ' traçado(s). Os comprimentos foram recalculados.');
                oferecerEmendaEmFila(movidas);
            },
            // Nada foi gravado — o lote é uma transação só —, então as pendências ficam
            // na tela para o usuário corrigir em vez de perder o trabalho.
            onErro: function (m) { FTTH.toast('erro', m); }
        }).always(function () { $b.prop('disabled', contarPendentes() === 0); });
    }

    /**
     * O que um clique no mapa faz, por modo. Vive numa função só porque o clique pode chegar
     * de dois lugares: do mapa e de cima de um cabo, que é um objeto por cima do mapa.
     */
    function cursorDoCabo() {
        return (modo === 'caixa' || modo === 'cabo') ? 'crosshair' : 'pointer';
    }

    function cliqueNoMapa(e) {
        if (modo === 'caixa') {
            marcarPonto(e.latLng);
            return;
        }
        if (modo === 'cabo') {
            pontoTracado({ tipo: 'VERTICE', lat: e.latLng.lat(), lng: e.latLng.lng() });
            return;
        }
        $('#painel').hide();
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
        // Clicou em cima de um cabo? A pergunta vem agora, antes do cadastro: a decisão é
        // sobre o ponto, e escolher o tipo da caixa depois é o passo natural.
        // Reserva não emenda: se o tipo já está escolhido, a pergunta nem aparece.
        if (tipoSelecionado() === 'RESERVA') {
            abrirModalCaixa();
            return;
        }
        perguntarEmendaNoClique(latLng, abrirModalCaixa);
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

    /** Nome do tipo para os textos da tela: "Editar CTO", "Reserva criada". */
    var ROTULO_TIPO = { CTO: 'CTO', CEO: 'CEO', DC: 'DC / POP', PREDIO: 'prédio',
                        PROBLEMA: 'problema', RESERVA: 'reserva' };

    var TIPOS_EM_SEQUENCIA = { CTO: true, CEO: true, RESERVA: true };

    /** Os metros só existem para a Reserva; o resto do modal é igual para todo tipo. */
    function camposDoTipo() {
        $('#nc-reserva-area').toggle(tipoSelecionado() === 'RESERVA');
        // Numerar em sequência só vale no cadastro e para o que se lança em série na rua.
        $('#nc-sequencia-area').toggle(!editando && TIPOS_EM_SEQUENCIA[tipoSelecionado()] === true);
    }

    function abrirModalCaixa() {
        editando = null;
        $('#modal-titulo').text('Novo ponto');
        $('#nc-criar').text('Criar ponto');
        $('#nc-reserva').val('');
        $('#nc-saida').empty();
        camposDoTipo();
        $('#modal-caixa').show();
        sugerirNome('');
        setTimeout(function () { $('#nc-nome').trigger('focus').trigger('select'); }, 50);
    }

    /** Mesmo modal do cadastro, agora preenchido com a caixa que existe. */
    function abrirModalEdicao(c) {
        editando = { id: parseInt(c.id, 10), versao: parseInt(c.versao, 10) };
        limparPin();

        $('#modal-titulo').text('Editar ' + (ROTULO_TIPO[c.tipo] || 'ponto'));
        $('#nc-criar').text('Salvar');
        $('#nc-saida').empty();

        $('#nc-nome').val(c.nome);
        $('#nc-reserva').val(c.reserva_m ? String(parseFloat(c.reserva_m)) : '');
        $('#nc-ponto').text('Ponto: ' + parseFloat(c.lat).toFixed(6) + ', ' + parseFloat(c.lng).toFixed(6)
            + ' — para mudar de lugar, use o modo Mover.');

        $('.ftth-tipo').removeClass('ativo');
        var $t = $('.ftth-tipo[data-tipo="' + c.tipo + '"]');
        ($t.length ? $t : $('.ftth-tipo[data-tipo="CTO"]')).addClass('ativo');
        camposDoTipo();

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
            $('#nc-saida').html('<div class="ftth-aviso ftth-aviso--erro">Informe o nome do ponto.</div>');
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
                    reserva_m: $('#nc-reserva').val()
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
        var sequencia = $('#nc-sequencia-area').is(':visible') && $('#nc-sequencia').is(':checked');

        $('#nc-criar').prop('disabled', true);
        FTTH.chamar({
            url: 'mapa.php?ajax=nova_caixa',
            method: 'POST',
            data: {
                csrf: cfg.csrf, regiao: regiao,
                nome: nome, tipo: tipoSelecionado(), cor: corSelecionada(),
                reserva_m: $('#nc-reserva').val(),
                lat: pontoNovo.lat(), lng: pontoNovo.lng()
            },
            onOk: function (d) {
                limparPin();
                $('#modal-caixa').hide();
                carregar();

                var seguir = function () {
                    if (sequencia) {
                        // Continua no modo Caixa: o próximo clique já marca a caixa seguinte.
                        FTTH.toast('ok', nome + ' criado. Clique no mapa para o próximo.');
                    } else {
                        definirModo('navegar');
                        abrirFicha(d.id);
                    }
                };

                // A emenda já foi decidida no clique; agora que a caixa existe, aplicamos.
                // Reserva nunca emenda: ela fica em cima do cabo, que continua inteiro.
                if (d.tipo === 'RESERVA') emendaDesejada = null;
                if (emendaDesejada) {
                    var vao = emendaDesejada.vao;
                    emendaDesejada = null;
                    aplicarEmenda(vao, d.id, seguir);
                } else {
                    seguir();
                }
            },
            onErro: function (m) {
                $('#nc-saida').html('<div class="ftth-aviso ftth-aviso--erro">' + esc(m) + '</div>');
            }
        }).always(function () { $('#nc-criar').prop('disabled', false); });
    }

    /* ------------------------------------------------------------------ vista salva */

    /**
     * Onde o mapa estava (região, centro e zoom), guardado na aba do navegador. Quem abre o
     * diagrama de uma caixa e volta quer continuar no mesmo ponto, não no centro da região.
     * sessionStorage e não localStorage: aba nova começa do padrão da região.
     */
    var CHAVE_VISTA = 'ftth_mapa_vista';

    function lerVista() {
        try {
            var v = JSON.parse(sessionStorage.getItem(CHAVE_VISTA) || 'null');
            return (v && isFinite(v.lat) && isFinite(v.lng) && isFinite(v.zoom)) ? v : null;
        } catch (e) {
            return null;
        }
    }

    function guardarVista() {
        var c = mapa && mapa.getCenter();
        if (!c) return;
        try {
            sessionStorage.setItem(CHAVE_VISTA, JSON.stringify({
                regiao: regiao, lat: c.lat(), lng: c.lng(), zoom: mapa.getZoom()
            }));
        } catch (e) { /* navegador sem storage: só não lembra */ }
    }

    /* ------------------------------------------------------------------ regiões e painel */

    /**
     * Onde o mapa vai ao entrar numa região, em ordem de prioridade (a 1ª, a vista guardada
     * na aba, é resolvida na abertura da página):
     *   2. a moldura dos pontos — um centro gravado errado deixa de importar no 1º ponto;
     *   3. o centro e o zoom gravados na região, que só valem para região vazia;
     *   4. o centro do Brasil, quando nada acima existe.
     */
    var CENTRO_BRASIL = { lat: -14.235, lng: -51.9253 };
    var ZOOM_BRASIL = 4;
    var ZOOM_MAX_ENQUADRAR = 18;   // rede de um ponto só não pode abrir no zoom 21
    var regioes = [];

    function acharRegiao(id) {
        var achada = null;
        regioes.forEach(function (r) { if (Number(r.id) === Number(id)) achada = r; });
        return achada;
    }

    function telaEstreita() {
        return !!(window.matchMedia && window.matchMedia('(max-width: 470px)').matches);
    }

    function posicionarNaRegiao(r) {
        if (!mapa) return;
        if (r && r.lat_min !== null && r.lat_min !== undefined) {
            var la1 = parseFloat(r.lat_min), la2 = parseFloat(r.lat_max);
            var ln1 = parseFloat(r.lng_min), ln2 = parseFloat(r.lng_max);
            if (Math.abs(la2 - la1) < 1e-5 && Math.abs(ln2 - ln1) < 1e-5) {
                mapa.setCenter({ lat: la1, lng: ln1 });
                mapa.setZoom(ZOOM_MAX_ENQUADRAR);
                return;
            }
            // Com o painel aberto no desktop, ele cobre 300px da esquerda: a rede é
            // enquadrada no que sobra à vista, não atrás dele.
            var coberto = $('#mapa-area').hasClass('gaveta-aberta') && !telaEstreita();
            mapa.fitBounds(new google.maps.LatLngBounds({ lat: la1, lng: ln1 }, { lat: la2, lng: ln2 }),
                coberto ? { top: 40, right: 40, bottom: 40, left: 340 } : 40);
            google.maps.event.addListenerOnce(mapa, 'idle', function () {
                if (mapa.getZoom() > ZOOM_MAX_ENQUADRAR) mapa.setZoom(ZOOM_MAX_ENQUADRAR);
            });
        } else if (r && r.lat !== null && r.lat !== undefined && r.lng !== null) {
            mapa.setCenter({ lat: parseFloat(r.lat), lng: parseFloat(r.lng) });
            mapa.setZoom(parseInt(r.zoom, 10) || 15);
        } else {
            mapa.setCenter(CENTRO_BRASIL);
            mapa.setZoom(ZOOM_BRASIL);
        }
    }

    function trocarRegiao(id) {
        if (modo !== 'navegar') {
            definirModo('navegar');
            if (modo !== 'navegar') return;     // desistiu de sair do Mover com pendências
        }
        regiao = parseInt(id, 10) || 0;
        pontos = [];
        pontosCarregados = false;
        $('#painel').hide();
        renderRegioes();
        // Escolheu a região: o painel sai da frente ANTES de enquadrar, para a rede ocupar
        // o mapa inteiro em vez de ser enquadrada no que sobra ao lado dele.
        abrirGaveta(false);
        posicionarNaRegiao(acharRegiao(regiao));
        carregar();
    }

    function plural(n, um, varios) { return n + ' ' + (n === 1 ? um : varios); }

    function renderRegioes() {
        var $l = $('#lista-regioes');
        if (!regioes.length) {
            $l.html('<p class="ftth-gaveta-vazio">Nenhuma região ainda. Crie a primeira: dê um nome '
                + 'e posicione o mapa onde ela fica.</p>');
            return;
        }
        $l.html(regioes.map(function (r) {
            var n = parseInt(r.caixas, 10) || 0;
            var v = parseInt(r.vaos, 10) || 0;
            var q = parseInt(r.quarentena, 10) || 0;
            return '<div class="ftth-regiao' + (Number(r.id) === Number(regiao) ? ' ativa' : '')
                 +      '" data-id="' + r.id + '">'
                 +   '<div class="ftth-regiao-topo">'
                 +     '<div class="ftth-regiao-nome"><strong>' + esc(r.nome) + '</strong>'
                 +       '<span>' + plural(n, 'ponto', 'pontos') + ' · ' + plural(v, 'vão', 'vãos')
                 +       (q ? ' · ' + q + ' a revisar' : '') + '</span></div>'
                 +     '<button type="button" class="ftth-regiao-mais" title="Opções">'
                 +       '<i class="bi-three-dots-vertical"></i></button>'
                 +   '</div>'
                 +   '<div class="ftth-regiao-menu">'
                 +     '<button type="button" data-acao="renomear"><i class="bi-pencil-square"></i> Renomear</button>'
                 +     '<button type="button" data-acao="vista"><i class="bi-crosshair"></i> Usar esta vista como centro</button>'
                 +     '<button type="button" data-acao="excluir" class="perigo">'
                 +       '<i class="bi-trash3-fill"></i> Excluir</button>'
                 +   '</div>'
                 + '</div>';
        }).join(''));
    }

    function recarregarRegioes(depois) {
        FTTH.chamar({
            url: 'mapa.php?ajax=regioes',
            onOk: function (d) {
                regioes = d.regioes || [];
                renderRegioes();
                if (depois) depois();
            },
            onErro: function (m) { FTTH.toast('erro', m); }
        });
    }

    /* ------------------------------------------------------------------ aba Pontos */

    /**
     * Lista da região inteira, com o filtro que também vale no mapa. Só é buscada quando
     * alguém vai olhar (aba aberta ou filtro ligado): o cálculo de sinal roda a rede toda.
     */
    var pontos = [];
    var pontosCarregados = false;
    var filtroPontos = 'todos';           // todos | sem_sinal | sem_splitter
    var gruposFechados = {};
    var ORDEM_GRUPO = ['CTO', 'CEO', 'DC', 'PREDIO', 'RESERVA', 'PROBLEMA'];
    var ROTULO_GRUPO = { CTO: 'CTO', CEO: 'CEO', DC: 'DC / POP', PREDIO: 'Prédio',
                         RESERVA: 'Reserva', PROBLEMA: 'Problema' };
    var ROTULO_FILTRO = { sem_sinal: 'sem sinal', sem_splitter: 'sem splitter' };

    /** Os dois filtros olham só CTO e CEO — e é o servidor quem diz quem é (com_sinal != null). */
    function atendeFiltro(p, f) {
        if (f === 'todos') return true;
        if (p.com_sinal === null || p.com_sinal === undefined) return false;
        if (f === 'sem_sinal') return !p.com_sinal;
        if (f === 'sem_splitter') return (parseInt(p.splitters, 10) || 0) === 0;
        return true;
    }

    /** Ids que o filtro destaca no mapa, ou null quando não há filtro (nada fica translúcido). */
    function idsDoFiltro() {
        if (filtroPontos === 'todos' || !pontosCarregados) return null;
        var ids = {};
        pontos.forEach(function (p) { if (atendeFiltro(p, filtroPontos)) ids[p.id] = true; });
        return ids;
    }

    function abaPontosVisivel() {
        return $('#mapa-area').hasClass('gaveta-aberta')
            && $('.ftth-gaveta-tab.ativo').data('aba') === 'pontos';
    }

    function recarregarPontos() {
        if (!regiao) {
            pontos = [];
            pontosCarregados = false;
            renderPontos();
            return;
        }
        // Ninguém olhando: só marca como velha, e ela é buscada quando a aba abrir.
        if (filtroPontos === 'todos' && !abaPontosVisivel()) {
            pontosCarregados = false;
            return;
        }
        var pedida = regiao;
        FTTH.chamar({
            url: 'mapa.php?ajax=pontos&regiao=' + regiao,
            onOk: function (d) {
                if (pedida !== regiao) return;       // trocou de região no meio do caminho
                pontos = d.pontos || [];
                pontosCarregados = true;
                renderPontos();
                if (filtroPontos !== 'todos' && ultimoDesenho) desenhar(ultimoDesenho);
            },
            onErro: function (m) {
                $('#lista-pontos').html('<div class="ftth-aviso ftth-aviso--erro">' + esc(m) + '</div>');
            }
        });
    }

    function linhaDoPonto(p) {
        if (p.tipo === 'RESERVA') {
            return '<small>' + (parseFloat(p.reserva_m) > 0 ? metros(p.reserva_m) + ' de reserva'
                                                            : 'sem metros informados') + '</small>';
        }
        if (p.com_sinal === null || p.com_sinal === undefined) {
            return '<small>' + esc(ROTULO_GRUPO[p.tipo] || p.tipo) + '</small>';
        }
        var partes = [];
        var alerta = false;
        if (p.com_sinal) {
            partes.push('com sinal');
        } else {
            partes.push('sem sinal');
            alerta = true;
        }
        var sp = parseInt(p.splitters, 10) || 0;
        var portas = parseInt(p.portas, 10) || 0;
        var ocupadas = parseInt(p.ocupadas, 10) || 0;
        if (!sp) {
            partes.push('sem splitter');
            alerta = true;
        } else if (portas) {
            partes.push(ocupadas + '/' + portas + ' portas');
        } else {
            partes.push(plural(sp, 'splitter', 'splitters'));
        }
        return '<small' + (alerta ? ' class="alerta"' : '') + '>' + partes.join(' · ') + '</small>';
    }

    function renderPontos() {
        var $l = $('#lista-pontos');
        var semSinal = 0, semSplitter = 0;
        pontos.forEach(function (p) {
            if (atendeFiltro(p, 'sem_sinal')) semSinal++;
            if (atendeFiltro(p, 'sem_splitter')) semSplitter++;
        });
        $('#conta-sem-sinal').text(pontosCarregados ? semSinal : '');
        $('#conta-sem-splitter').text(pontosCarregados ? semSplitter : '');
        $('#gaveta-aba').toggleClass('filtrando', filtroPontos !== 'todos');

        if (!regiao) {
            $l.html('<p class="ftth-gaveta-vazio">Escolha uma região na aba Regiões.</p>');
            return;
        }
        if (!pontosCarregados) {
            $l.html('<p class="ftth-gaveta-vazio">Carregando…</p>');
            return;
        }

        var termo = $.trim($('#pontos-busca').val() || '').toLowerCase();
        var grupos = {};
        pontos.forEach(function (p) {
            if (!atendeFiltro(p, filtroPontos)) return;
            if (termo && String(p.nome).toLowerCase().indexOf(termo) < 0) return;
            var g = p.tipo === 'CTO_AP' ? 'CTO' : p.tipo;
            (grupos[g] = grupos[g] || []).push(p);
        });
        var ordem = function (g) { var i = ORDEM_GRUPO.indexOf(g); return i < 0 ? 99 : i; };
        var chaves = Object.keys(grupos).sort(function (a, b) { return ordem(a) - ordem(b); });

        if (!chaves.length) {
            $l.html('<p class="ftth-gaveta-vazio">' + (filtroPontos !== 'todos'
                ? 'Nenhuma CTO ou CEO ' + ROTULO_FILTRO[filtroPontos] + ' nesta região.'
                : (termo ? 'Nenhum ponto com "' + esc(termo) + '".' : 'Nenhum ponto nesta região ainda.'))
                + '</p>');
            return;
        }

        $l.html(chaves.map(function (g) {
            // Buscando, os grupos abrem todos: o resultado não pode ficar escondido.
            var fechado = gruposFechados[g] && !termo;
            return '<div class="ftth-grupo' + (fechado ? ' fechado' : '') + '" data-grupo="' + g + '">'
                 +   '<button type="button" class="ftth-grupo-topo">'
                 +     marcaDoTipo(g, '#4A5568') + ' ' + esc(ROTULO_GRUPO[g] || g)
                 +     ' <span>(' + grupos[g].length + ')</span><i class="bi-chevron-down"></i></button>'
                 +   '<div class="ftth-grupo-itens">'
                 +     grupos[g].map(function (p) {
                          return '<button type="button" class="ftth-item-ponto" data-id="' + p.id + '">'
                               +   marcaDoTipo(p.tipo, p.cor)
                               +   '<span><strong>' + esc(p.nome) + '</strong>' + linhaDoPonto(p) + '</span>'
                               + '</button>';
                       }).join('')
                 +   '</div>'
                 + '</div>';
        }).join(''));
    }

    /** Clique na lista: o mapa vai até o ponto e a ficha abre. No celular o painel sai da frente. */
    function irParaPonto(id) {
        var p = null;
        pontos.forEach(function (x) { if (Number(x.id) === Number(id)) p = x; });
        if (!p || !mapa) return;
        if (telaEstreita()) abrirGaveta(false);
        mapa.setCenter({ lat: parseFloat(p.lat), lng: parseFloat(p.lng) });
        if (mapa.getZoom() < 19) mapa.setZoom(19);
        abrirFicha(p.id);
    }

    function escolherFiltro(f) {
        filtroPontos = f;
        $('.ftth-filtro').removeClass('ativo').filter('[data-filtro="' + f + '"]').addClass('ativo');
        renderPontos();
        if (!pontosCarregados) {
            recarregarPontos();          // o mapa é redesenhado quando a lista chegar
        } else if (ultimoDesenho) {
            desenhar(ultimoDesenho);     // liga/desliga a transparência sem ir ao servidor
        }
    }

    /* ------------------------------------------------------------------ aba Ajustes */

    var estadoLido = false;

    function carregarEstado() {
        if (estadoLido) return;
        FTTH.chamar({
            url: 'mapa.php?ajax=estado',
            onOk: function (e) {
                estadoLido = true;
                $('#estado-banco').html(
                    '<div class="ftth-estado-topo"><strong>Estado do banco</strong>'
                  +   '<span class="ftth-selo ' + (e.completo ? 'ftth-selo--ok">completo' : 'ftth-selo--erro">incompleto')
                  +   '</span></div>'
                  + '<dl class="ftth-estado-dados">'
                  +   '<dt>Tabelas do addon</dt><dd>' + e.tabelas + ' de ' + e.tabelas_de + '</dd>'
                  +   '<dt>Schema aplicado</dt><dd>' + (e.schema ? esc(e.schema) + ' em ' + esc(e.schema_em) : '—') + '</dd>'
                  +   '<dt>Versão do addon</dt><dd>' + esc(e.addon || '—') + '</dd>'
                  +   (e.aposentadas ? '<dt>Aposentadas</dt><dd>' + e.aposentadas + ' a remover</dd>' : '')
                  + '</dl>'
                  + '<p class="ftth-sub" style="margin:6px 0 4px">Banco e arquivos são atualizados pelo '
                  +   'instalador, no terminal do servidor:</p>'
                  + '<div class="ftth-estado-cmd"><code id="estado-cmd">' + esc(e.instalador) + '</code>'
                  +   '<button type="button" class="ftth-copiar" id="estado-copiar">copiar</button></div>');
            },
            onErro: function (m) {
                $('#estado-banco').html('<div class="ftth-aviso ftth-aviso--erro">' + esc(m) + '</div>');
            }
        });
    }

    /**
     * Salvar: o que pode valer na hora vale — tipo de mapa e zoom do rótulo redesenham o mapa;
     * o raio de emenda o servidor já lê a cada uso. Só a chave do Google pede recarregar.
     */
    function salvarAjustes() {
        var cfg = window.FTTH_MAPA || {};
        var chaveAntes = String($('#aj-chave').data('salva') !== undefined
            ? $('#aj-chave').data('salva') : $('#aj-chave').prop('defaultValue'));
        var $b = $('#aj-salvar').prop('disabled', true);
        FTTH.chamar({
            url: 'mapa.php?ajax=ajustes',
            method: 'POST',
            data: {
                csrf: cfg.csrf,
                google_maps_key: $('#aj-chave').val(),
                mapa_tipo: $('#aj-tipo').val(),
                mapa_rotulo_zoom: $('#aj-zoom').val(),
                raio_quebra_cabo_m: $('#aj-raio').val()
            },
            onOk: function (d) {
                $('#aj-chave').data('salva', d.google_maps_key);
                $('#aj-zoom').val(d.mapa_rotulo_zoom);
                $('#aj-raio').val(d.raio_quebra_cabo_m);

                cfg.rotulo_zoom = d.mapa_rotulo_zoom;
                if (mapa && d.mapa_tipo !== cfg.tipo) {
                    cfg.tipo = d.mapa_tipo;
                    // Híbrido é satélite + a camada de rótulos, que o listener de tipo liga sozinho.
                    mapa.setMapTypeId(d.mapa_tipo === 'hybrid' ? 'satellite' : d.mapa_tipo);
                }
                if (ultimoDesenho) desenhar(ultimoDesenho);

                var trocouChave = d.google_maps_key !== chaveAntes;
                $('#aj-saida').html('<div class="ftth-aviso ftth-aviso--ok" style="margin:8px 0 0">'
                    + 'Ajustes salvos.' + (trocouChave ? ' A chave nova vale ao recarregar a página.' : '')
                    + '</div>');
            },
            onErro: function (m) {
                $('#aj-saida').html('<div class="ftth-aviso ftth-aviso--erro" style="margin:8px 0 0">'
                    + esc(m) + '</div>');
            }
        }).always(function () { $b.prop('disabled', false); });
    }

    /* Primeira instalação: sem chave não há mapa, nem painel. O campo mora no próprio aviso. */
    $(function () {
        $('#chave-inicial-salvar').on('click', function () {
            var chave = $.trim($('#chave-inicial').val());
            if (!chave) {
                $('#chave-inicial-saida').html('<p class="ftth-sub" style="margin:6px 0 0">Cole a chave.</p>');
                return;
            }
            var $b = $(this).prop('disabled', true);
            FTTH.chamar({
                url: 'mapa.php?ajax=ajustes',
                method: 'POST',
                data: { csrf: (window.FTTH_MAPA || {}).csrf, google_maps_key: chave },
                onOk: function () { window.location.reload(); },
                onErro: function (m) {
                    $('#chave-inicial-saida').html('<div class="ftth-aviso ftth-aviso--erro" '
                        + 'style="margin:8px 0 0">' + esc(m) + '</div>');
                    $b.prop('disabled', false);
                }
            });
        });
        $('#chave-inicial').on('keydown', function (e) {
            if (e.key === 'Enter') $('#chave-inicial-salvar').trigger('click');
        });
    });

    /* Preferência do painel (aberto, aba): conveniência deste navegador, nada além disso. */
    function lerPref(chave) {
        try { return localStorage.getItem(chave); } catch (e) { return null; }
    }
    function gravarPref(chave, valor) {
        try { localStorage.setItem(chave, valor); } catch (e) { /* sem storage: não lembra */ }
    }

    function abrirGaveta(aberta) {
        $('#mapa-area').toggleClass('gaveta-aberta', !!aberta);
        gravarPref('ftth_gaveta', aberta ? '1' : '0');
        if (abaPontosVisivel() && !pontosCarregados) recarregarPontos();
        if (aberta && $('.ftth-gaveta-tab.ativo').data('aba') === 'ajustes') carregarEstado();
    }

    function escolherAba(aba) {
        if (!$('.ftth-gaveta-tab[data-aba="' + aba + '"]').length) aba = 'regioes';
        $('.ftth-gaveta-tab').removeClass('ativo').filter('[data-aba="' + aba + '"]').addClass('ativo');
        $('.ftth-gaveta-corpo').hide().filter('[data-aba="' + aba + '"]').show();
        gravarPref('ftth_gaveta_aba', aba);
        if (abaPontosVisivel() && !pontosCarregados) recarregarPontos();
        if (aba === 'ajustes' && $('#mapa-area').hasClass('gaveta-aberta')) carregarEstado();
    }

    /** Nome da região: o mesmo modal cria (passo 1) e renomeia. */
    var regiaoEditando = null;   // null = nova; senão, a região sendo renomeada

    function abrirModalRegiao(r) {
        regiaoEditando = r || null;
        $('#rg-titulo').text(r ? 'Renomear região' : 'Nova região');
        $('#rg-salvar').text(r ? 'Salvar' : 'Continuar');
        $('#rg-nota').text(r ? '' : 'Depois do nome, você posiciona o mapa onde a região fica.');
        $('#rg-nome').val(r ? r.nome : '');
        $('#rg-saida').empty();
        $('#modal-regiao').show();
        setTimeout(function () { $('#rg-nome').trigger('focus').trigger('select'); }, 50);
    }

    function salvarModalRegiao() {
        var cfg = window.FTTH_MAPA || {};
        var nome = $.trim($('#rg-nome').val());
        var erro = function (m) {
            $('#rg-saida').html('<div class="ftth-aviso ftth-aviso--erro" style="margin:8px 0 0">'
                + esc(m) + '</div>');
        };
        if (!nome) { erro('Informe o nome da região.'); return; }
        var repetida = regioes.some(function (x) {
            return x.nome.toLowerCase() === nome.toLowerCase()
                && (!regiaoEditando || Number(x.id) !== Number(regiaoEditando.id));
        });
        if (repetida) { erro('Já existe uma região com esse nome.'); return; }

        if (!regiaoEditando) {
            $('#modal-regiao').hide();
            iniciarPosicionamento(nome);
            return;
        }

        $('#rg-salvar').prop('disabled', true);
        FTTH.chamar({
            url: 'mapa.php?ajax=regiao_alterar',
            method: 'POST',
            data: { csrf: cfg.csrf, id: regiaoEditando.id, versao: regiaoEditando.versao, nome: nome },
            onOk: function () {
                $('#modal-regiao').hide();
                recarregarRegioes();
            },
            onErro: erro
        }).always(function () { $('#rg-salvar').prop('disabled', false); });
    }

    /** Nova região, passo 2: o usuário leva o mapa até a região e confirma. */
    var nomeNovaRegiao = null;

    function iniciarPosicionamento(nome) {
        definirModo('navegar');
        if (modo !== 'navegar') return;
        nomeNovaRegiao = nome;
        $('#painel').hide();
        abrirGaveta(false);
        $('#regiao-card-nome').text(nome);
        $('#regiao-card').show();
        ajustarToasts();
        FTTH.toast('info', 'Arraste e aproxime o mapa até ' + nome + ' (ou busque a coordenada) e confirme.');
    }

    function encerrarPosicionamento() {
        nomeNovaRegiao = null;
        $('#regiao-card').hide();
        ajustarToasts();
    }

    function confirmarNovaRegiao() {
        var cfg = window.FTTH_MAPA || {};
        var c = mapa.getCenter();
        var $b = $('#regiao-card-confirmar').prop('disabled', true);
        FTTH.chamar({
            url: 'mapa.php?ajax=regiao_criar',
            method: 'POST',
            data: { csrf: cfg.csrf, nome: nomeNovaRegiao, lat: c.lat(), lng: c.lng(), zoom: mapa.getZoom() },
            onOk: function (d) {
                var nome = nomeNovaRegiao;
                encerrarPosicionamento();
                recarregarRegioes(function () { trocarRegiao(d.id); });
                FTTH.toast('ok', 'Região ' + nome + ' criada.');
            },
            onErro: function (m) { FTTH.toast('erro', m); }
        }).always(function () { $b.prop('disabled', false); });
    }

    function usarVistaComoCentro(r) {
        var cfg = window.FTTH_MAPA || {};
        var n = parseInt(r.caixas, 10) || 0;
        var msg = 'Gravar a vista atual do mapa como centro de "' + r.nome + '"?'
            + (n ? '\n\nEla já tem pontos: ao entrar nela o mapa enquadra os pontos, e este '
                 + 'centro só vale se ela ficar vazia.' : '');
        if (!confirm(msg)) return;
        var c = mapa.getCenter();
        FTTH.chamar({
            url: 'mapa.php?ajax=regiao_alterar',
            method: 'POST',
            data: { csrf: cfg.csrf, id: r.id, versao: r.versao,
                    lat: c.lat(), lng: c.lng(), zoom: mapa.getZoom() },
            onOk: function () {
                recarregarRegioes();
                FTTH.toast('ok', 'Centro de ' + r.nome + ' atualizado.');
            },
            onErro: function (m) { FTTH.toast('erro', m); }
        });
    }

    function excluirRegiao(r) {
        var cfg = window.FTTH_MAPA || {};
        if (!confirm('Excluir a região "' + r.nome + '"?\n\nSó é possível com ela vazia.')) return;
        FTTH.chamar({
            url: 'mapa.php?ajax=regiao_excluir',
            method: 'POST',
            data: { csrf: cfg.csrf, id: r.id, versao: r.versao },
            onOk: function () {
                var eraAtual = Number(r.id) === Number(regiao);
                recarregarRegioes(function () {
                    if (!eraAtual) return;
                    if (regioes.length) {
                        trocarRegiao(regioes[0].id);
                    } else {
                        regiao = 0;
                        limpar();
                        $('#resumo-mapa').empty();
                        posicionarNaRegiao(null);
                    }
                });
                FTTH.toast('ok', 'Região ' + r.nome + ' excluída.');
            },
            onErro: function (m) { FTTH.toast('erro', m); }
        });
    }

    function iniciarGaveta() {
        renderRegioes();
        escolherAba(lerPref('ftth_gaveta_aba') || 'regioes');
        // Sem região não há o que fazer no mapa: o painel já abre mostrando como criar uma.
        // No celular ele não reabre sozinho, porque cobriria o mapa inteiro.
        if (!regioes.length) {
            escolherAba('regioes');
            abrirGaveta(true);
        } else {
            abrirGaveta(lerPref('ftth_gaveta') === '1' && !telaEstreita());
        }

        $('#gaveta-aba').on('click', function () {
            abrirGaveta(!$('#mapa-area').hasClass('gaveta-aberta'));
        });
        $('.ftth-gaveta-tab').on('click', function () { escolherAba($(this).data('aba')); });

        var $lista = $('#lista-regioes');
        $lista.on('click', '.ftth-regiao-topo', function (ev) {
            if ($(ev.target).closest('.ftth-regiao-mais').length) return;
            trocarRegiao($(this).closest('.ftth-regiao').data('id'));
        });
        $lista.on('click', '.ftth-regiao-mais', function () {
            var $r = $(this).closest('.ftth-regiao');
            $lista.find('.ftth-regiao').not($r).removeClass('menu-aberto');
            $r.toggleClass('menu-aberto');
        });
        $lista.on('click', '.ftth-regiao-menu button', function () {
            var r = acharRegiao($(this).closest('.ftth-regiao').data('id'));
            $(this).closest('.ftth-regiao').removeClass('menu-aberto');
            if (!r) return;
            var acao = $(this).data('acao');
            if (acao === 'renomear') abrirModalRegiao(r);
            else if (acao === 'vista') usarVistaComoCentro(r);
            else if (acao === 'excluir') excluirRegiao(r);
        });

        $('#regiao-nova').on('click', function () { abrirModalRegiao(null); });

        // --- aba Ajustes
        $('#aj-salvar').on('click', salvarAjustes);
        $('#estado-banco').on('click', '#estado-copiar', function () {
            var texto = $('#estado-cmd').text();
            var $b = $(this);
            if (navigator.clipboard) {
                navigator.clipboard.writeText(texto).then(function () { $b.text('copiado'); });
            } else {
                window.prompt('Copie o comando:', texto);
            }
        });

        // --- aba Pontos
        renderPontos();
        $('#pontos-busca').on('input', renderPontos);
        $('.ftth-filtro').on('click', function () { escolherFiltro($(this).data('filtro')); });
        var $pontos = $('#lista-pontos');
        $pontos.on('click', '.ftth-grupo-topo', function () {
            var $g = $(this).closest('.ftth-grupo');
            $g.toggleClass('fechado');
            gruposFechados[$g.data('grupo')] = $g.hasClass('fechado');
        });
        $pontos.on('click', '.ftth-item-ponto', function () { irParaPonto($(this).data('id')); });
        $('#rg-salvar').on('click', salvarModalRegiao);
        $('#rg-nome').on('keydown', function (e) { if (e.key === 'Enter') salvarModalRegiao(); });
        $('#rg-cancelar, #rg-fechar').on('click', function () { $('#modal-regiao').hide(); });
        $('#regiao-card-confirmar').on('click', confirmarNovaRegiao);
        $('#regiao-card-cancelar').on('click', function () {
            encerrarPosicionamento();
            abrirGaveta(true);
        });
    }

    window.ftthIniciarMapa = function () {
        var cfg = window.FTTH_MAPA || {};
        regiao = cfg.regiao || 0;

        regioes = cfg.regioes || [];

        // Prioridade 1: a vista guardada na aba (volta do diagrama, F5), se a região dela
        // ainda existe. Sem ela, a região é posicionada logo depois de o mapa nascer.
        var vista = lerVista();
        if (vista && acharRegiao(vista.regiao)) {
            regiao = parseInt(vista.regiao, 10);
        } else {
            vista = null;
        }

        ajustarAltura();
        window.addEventListener('resize', ajustarAltura);
        window.addEventListener('resize', ajustarToasts);
        // A dica longa do modo Mover, que em tela estreita sai do card.
        $('#mover-ajuda').on('click', function () {
            FTTH.toast('info', $.trim($('#mover-dica').text()));
        });

        // Os pontos de interesse do Google (farmácia, loja, mercado, ponto de ônibus)
        // disputam a tela com as caixas e os cabos, que são o assunto aqui.
        var SEM_POI = [
            { featureType: 'poi',     stylers: [{ visibility: 'off' }] },
            { featureType: 'transit', stylers: [{ visibility: 'off' }] }
        ];

        var tipo = cfg.tipo || 'hybrid';

        mapa = new google.maps.Map(document.getElementById('mapa'), {
            center: vista ? { lat: vista.lat, lng: vista.lng } : CENTRO_BRASIL,
            zoom: vista ? vista.zoom : ZOOM_BRASIL,
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
            guardarVista();
            clearTimeout(esperando);
            esperando = setTimeout(function () { carregar(true); }, 250);
        });

        // Clique no mapa (fora de qualquer item): ancora a caixa em espera, ou fecha a ficha.
        // Clique em marcador ou em cabo não chega aqui.
        mapa.addListener('click', cliqueNoMapa);

        $('.ftth-modo').on('click', function () {
            if ($(this).prop('disabled')) return;
            definirModo($(this).data('modo'));
        });

        // Trocar tipo ou cor atualiza o pino que já está no mapa, para o usuário ver o resultado.
        $(document).on('click', '.ftth-tipo', function () {
            $('.ftth-tipo').removeClass('ativo');
            $(this).addClass('ativo');
            camposDoTipo();
            // Na edição o nome é o que o ponto já tem; sugerir outro apagaria o dele.
            if (!editando) sugerirNome('');
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
        // Cancelar é o par do Finalizar, como no modo edição: os dois encerram o desenho.
        $('#cabo-cancelar').on('click', function () {
            if (tracado.length && !confirm('Descartar o traçado e sair do modo Cabo?')) return;
            limparTracado();
            definirModo('navegar');
        });
        $('#cb-salvar').on('click', confirmarConfigCabo);
        $('#cabo-config').on('click', abrirConfigCabo);
        // Antes de desenhar, fechar o popup é desistir do cabo; desenhando, é só voltar ao
        // traçado, que continua na tela.
        $('#cb-cancelar, #cabo-modal-fechar').on('click', function () {
            $('#modal-cabo').hide();
            if (!desenhandoCabo()) definirModo('navegar');
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
            // Desistiu da caixa: a emenda que ele tinha aceitado no clique morre junto.
            emendaDesejada = null;
            limparPin();
            FTTH.toast('info', 'Clique no mapa para marcar o ponto.');
        });
        $(document).on('keydown', function (e) { if (e.key === 'Escape') definirModo('navegar'); });

        iniciarGaveta();
        if (!vista) posicionarNaRegiao(acharRegiao(regiao));

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
        // Cancelar é o par do Concluir: os dois encerram a edição. Sair sem gravar volta o
        // mapa ao que está no banco — por isso o aviso quando há trabalho na tela.
        $('#mover-descartar').on('click', function () {
            if (contarPendentes() > 0
                && !confirm('Descartar as alterações do mapa e sair do modo edição?')) { return; }
            sairDoModoMover();          // zera as pendências e redesenha do banco
            definirModo('navegar');     // sem pendências, não pergunta de novo
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
                // Recado de erro vai para o toast: a ficha pode fechar, o toast fica.
                onErro: function (m) { FTTH.toast('erro', m); }
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
    /* ---------------------------------------------------------------- emendar no cabo
     *
     * O cabo quase sempre é lançado antes das caixas serem documentadas, e num rompimento
     * entram duas caixas de emenda no meio de um vão que já existe. Em vez de apagar e
     * redesenhar o cabo, o técnico marca a caixa em cima dele e o mapa oferece a emenda.
     *
     * Oferece, não faz: uma caixa pode cair perto do cabo sem ter nada a ver com ele — um
     * poste na mesma calçada, por exemplo. Quem sabe é quem está em campo.
     *
     * A pergunta aparece em dois momentos, e a diferença entre eles é de propósito:
     *
     *   ao CLICAR para criar   a decisão é sobre o ponto, e vem antes do cadastro: quem
     *                          clicou em cima do cabo já sabe o que quer, e escolher o tipo
     *                          da caixa depois é o passo natural. A emenda é aplicada assim
     *                          que a caixa nasce.
     *   ao MOVER uma caixa     só dá para perguntar depois de gravar, porque o lote de
     *                          movimentos é uma transação só.
     */
    var emendaAtiva = null;      // o que o botão "Emendar" desta vez deve fazer
    var emendaDesejada = null;   // vão escolhido no clique, aplicado quando a caixa nascer

    /** Pergunta ao servidor se este ponto cai sobre algum cabo. */
    function consultarVaoSobPonto(lat, lng, caixaId, aoResponder) {
        FTTH.chamar({
            url: 'mapa.php?ajax=vao_sob_ponto&regiao=' + regiao
                 + '&lat=' + lat + '&lng=' + lng
                 + (caixaId ? '&caixa=' + caixaId : ''),
            onOk: function (d) { aoResponder(d && d.vao ? d : null); },
            // Não achar o cabo nunca pode atrapalhar quem só queria marcar uma caixa.
            onErro: function () { aoResponder(null); }
        });
    }

    function abrirModalEmenda(texto, rotuloSim, rotuloNao, aoSim, aoNao) {
        emendaAtiva = { aoSim: aoSim, aoNao: aoNao };
        $('#em-texto').html(texto);
        $('#em-sim').text(rotuloSim).prop('disabled', false);
        $('#em-nao').text(rotuloNao).prop('disabled', false);
        $('#em-saida').empty();
        $('#modal-emenda').show();
    }

    function fecharModalEmenda() {
        emendaAtiva = null;
        $('#modal-emenda').hide();
    }

    function textoDoCabo(d) {
        return 'a <strong>' + Number(d.distancia_m).toFixed(1).replace('.', ',')
             + ' m</strong> do cabo <strong>' + esc(d.cabo_nome || 'sem nome')
             + '</strong> (' + esc(d.cabo_tipo) + ')';
    }

    /**
     * Modo Caixa: o clique caiu sobre um cabo? Pergunta antes de abrir o cadastro e guarda a
     * resposta; o cadastro segue igual, e a emenda acontece logo depois de a caixa nascer.
     */
    function perguntarEmendaNoClique(latLng, aoTerminar) {
        emendaDesejada = null;
        consultarVaoSobPonto(latLng.lat(), latLng.lng(), null, function (d) {
            if (!d) { aoTerminar(); return; }
            abrirModalEmenda(
                'Você clicou ' + textoDoCabo(d) + '. Quer emendar o cabo na caixa que vai criar aqui?',
                'Emendar no cabo', 'Só criar a caixa',
                function () {
                    emendaDesejada = { vao: d.vao, fibras: d.fibras };
                    fecharModalEmenda();
                    aoTerminar();
                },
                function () { fecharModalEmenda(); aoTerminar(); }
            );
        });
    }

    /** Modo Mover: a caixa já existe e já foi gravada onde parou. */
    function oferecerEmenda(caixaId, nomeCaixa, lat, lng, aoTerminar) {
        consultarVaoSobPonto(lat, lng, caixaId, function (d) {
            if (!d) { if (aoTerminar) aoTerminar(); return; }
            abrirModalEmenda(
                esc(nomeCaixa) + ' parou ' + textoDoCabo(d) + '. Este cabo passa por aqui — '
                + 'quer emendá-lo nesta caixa?',
                'Emendar no cabo', 'Deixar solta',
                function () { aplicarEmenda(d.vao, caixaId, aoTerminar); },
                function () { fecharModalEmenda(); if (aoTerminar) aoTerminar(); }
            );
        });
    }

    /** Quebra o vão de verdade. O servidor é quem decide se dá: aqui só mostramos o resultado. */
    function aplicarEmenda(vaoId, caixaId, aoTerminar) {
        var cfg = window.FTTH_MAPA || {};
        $('#em-sim, #em-nao').prop('disabled', true);
        FTTH.chamar({
            url: 'mapa.php?ajax=quebrar_vao',
            method: 'POST',
            data: { csrf: cfg.csrf, vao: vaoId, caixa: caixaId },
            onOk: function (d) {
                fecharModalEmenda();
                carregar();
                FTTH.toast('ok', 'Cabo emendado: dois trechos de '
                    + Math.round(d.metros_a) + ' m e ' + Math.round(d.metros_b) + ' m, com '
                    + d.fibras_passando + ' fibra(s) passando pela caixa.');
                if (aoTerminar) aoTerminar();
            },
            onErro: function (m) {
                // Com o modal aberto, o erro fica onde o usuário está olhando; sem ele
                // (emenda automática depois do cadastro), vai para o toast.
                if ($('#modal-emenda').is(':visible')) {
                    $('#em-saida').html('<div class="ftth-aviso ftth-aviso--erro">' + esc(m) + '</div>');
                    $('#em-sim, #em-nao').prop('disabled', false);
                } else {
                    FTTH.toast('erro', 'A caixa foi criada, mas não deu para emendar o cabo: ' + m);
                    if (aoTerminar) aoTerminar();
                }
            }
        });
    }

    $('#em-fechar').on('click', function () {
        var fn = emendaAtiva && emendaAtiva.aoNao;
        fecharModalEmenda();
        if (fn) fn();
    });
    $('#em-nao').on('click', function () { if (emendaAtiva) emendaAtiva.aoNao(); });
    $('#em-sim').on('click', function () { if (emendaAtiva) emendaAtiva.aoSim(); });

    /** Pergunta uma caixa de cada vez, para o operador não ver dois modais empilhados. */
    function oferecerEmendaEmFila(lista) {
        if (!lista.length) return;
        var atual = lista.shift();
        oferecerEmenda(atual.id, atual.nome, atual.lat, atual.lng, function () {
            oferecerEmendaEmFila(lista);
        });
    }
})();
