/**
 * ftth_doc :: diagrama da caixa — SVG desenhado à mão, sem biblioteca nova.
 *
 * Regra que vale para tudo aqui: o desenho é uma VISÃO do grafo. Conectar e desconectar
 * chamam o servidor na hora e, quando voltam, o SVG é redesenhado inteiro a partir do que
 * o banco respondeu. O JavaScript nunca é a verdade — se esta tela for fechada no meio, a
 * topologia no banco continua correta.
 *
 * O que o JS decide sozinho é só aparência: posição dos nós e por onde a linha passa.
 */
(function () {
    'use strict';

    var CFG   = window.FTTH_DIAGRAMA || {};
    var SVGNS = 'http://www.w3.org/2000/svg';

    /* Medidas do desenho. Andam juntas: mexer em ALT_LINHA sem mexer em RAIO desalinha
       a bolinha da fibra em relação ao rótulo. */
    var LARG_NO = 210, ALT_CAB = 40, ALT_LINHA = 22, PAD_BASE = 14, RAIO = 5,
        STUB = 24, MARGEM = 60, DESVIO = 22, FAIXA_CONTORNO = 14,
        ESPACO_TUBO = 10, FOLGA_NO = 30, LARGURA_VOLTA = 140;

    var estado = {
        dados:      null,
        layout:     {},
        pontas:     {},   // "ELEMENTO:id:numero" -> geometria e estado da ponta
        caixas:     {},   // retangulo de cada no, para a linha saber o que desviar
        selecao:    null, // ponta de origem da conexão em andamento
        ligacaoSel: null,
        fichaPonta: null, // ponta cuja ficha de potência está aberta à direita
        sujos:      {},   // nós movidos aguardando a gravação automática
        historico:  [],   // pilha de acoes desde que a caixa foi aberta (Desfazer)
        arraste:    null,
        splitter:   { modelo: 'BAL', razao: '', saidas: 0, funcao: 'ATENDIMENTO' }
    };

    /* ------------------------------------------------------------------ utilidades */

    function el(tag, attrs, pai) {
        var n = document.createElementNS(SVGNS, tag);
        for (var k in attrs) {
            if (attrs.hasOwnProperty(k) && attrs[k] !== null && attrs[k] !== undefined) {
                n.setAttribute(k, attrs[k]);
            }
        }
        if (pai) pai.appendChild(n);
        return n;
    }

    function texto(pai, x, y, str, classe) {
        var t = el('text', { x: x, y: y, 'class': classe || 'dg-no-titulo' }, pai);
        t.textContent = str;
        return t;
    }

    function chavePonta(p) {
        return p.elemento + ':' + p.elemento_id + ':' + (p.numero || 0);
    }

    function chaveNo(tipo, id) { return tipo + ':' + id; }

    /** Mesma normalização da matriz do servidor: par em ordem alfabética. */
    function chavePar(a, b) { return [a, b].sort().join('|'); }

    function faixa(tipo, texto, itens) {
        var $f = $('#faixa');
        var html = FTTH.esc(texto);
        if (itens && itens.length) {
            html += '<ul>';
            itens.forEach(function (i) { html += '<li>' + FTTH.esc(i) + '</li>'; });
            html += '</ul>';
        }
        $f.attr('class', 'ftth-dg-faixa ftth-dg-faixa--' + tipo).html(html).show();
    }

    /**
     * Aviso flutuante: aparece no canto, é lido e sai sozinho.
     *
     * Erro fica mais tempo na tela que sucesso — quem acertou já viu o resultado no
     * desenho; quem errou precisa ler o motivo.
     */
    function toast(tipo, texto, itens) {
        var icones = { ok: 'bi-check-circle-fill', avis: 'bi-exclamation-triangle-fill',
                       erro: 'bi-x-octagon-fill' };
        var html = '<i class="' + (icones[tipo] || icones.ok) + '"></i>' + FTTH.esc(texto);
        if (itens && itens.length) {
            html += '<ul>';
            itens.forEach(function (i) { html += '<li>' + FTTH.esc(i) + '</li>'; });
            html += '</ul>';
        }

        var $t = $('<div class="ftth-dg-toast ftth-dg-toast--' + tipo + '">' + html + '</div>');
        $('#toasts').append($t);
        window.requestAnimationFrame(function () { $t.addClass('aparece'); });

        var vida = tipo === 'erro' ? 8000 : 4000;
        var sumir = function () {
            $t.removeClass('aparece').addClass('sai');
            window.setTimeout(function () { $t.remove(); }, 220);
        };
        var relogio = window.setTimeout(sumir, vida);
        $t.on('click', function () { window.clearTimeout(relogio); sumir(); });
    }

    /** Avisos do envelope: existem desde a fase 0 e nenhuma tela mostrava. */
    function mostrarResposta(msgOk, resp) {
        var avisos = (resp && resp.warnings || []).map(function (w) { return w.message; });
        if (avisos.length) {
            toast('avis', msgOk, avisos);
        } else {
            toast('ok', msgOk);
        }
    }

    /* ------------------------------------------------------------------ carga */

    function carregar(aoTerminar) {
        FTTH.chamar({
            url: 'caixa.php?ajax=diagrama&caixa=' + encodeURIComponent(CFG.caixa),
            method: 'GET',
            onOk: function (d) {
                estado.dados  = d;

                // Conectar e desconectar recarregam o diagrama do servidor, e o servidor
                // só conhece as posições já salvas — quem moveu um nó e ainda não salvou
                // via tudo voltar para o lugar antigo no meio do trabalho. As posições
                // pendentes são reaplicadas por cima do que veio.
                var pendentes = {};
                for (var k in estado.sujos) {
                    if (estado.sujos.hasOwnProperty(k) && estado.layout[k]) {
                        pendentes[k] = estado.layout[k];
                    }
                }
                estado.layout = d.layout || {};
                for (var p in pendentes) {
                    if (pendentes.hasOwnProperty(p)) estado.layout[p] = pendentes[p];
                }

                estado.matriz = {};
                (d.matriz || []).forEach(function (par) { estado.matriz[par] = true; });
                $('#carregando').hide();

                // Antes de desenhar: quem chegou sem lugar definido ganha um vazio.
                acomodarNovos();
                render();
                if (aoTerminar) aoTerminar();
            },
            onErro: function (m) {
                $('#carregando').hide();
                toast('erro', m);
            }
        });
    }

    /* ------------------------------------------------------------------ geometria */

    /** Altura do nó depende de quantas pontas ele mostra. */
    function alturaNo(qtdPontas) {
        return ALT_CAB + Math.max(1, qtdPontas) * ALT_LINHA + PAD_BASE;
    }

    /** Mesmo critério das vias do splitter: a fibra desenhada é o próprio conector. */
    function passoDasFibras(n) {
        return n <= 16 ? 18 : (n <= 32 ? 16 : 14);
    }

    function qtdTubos(n, fibrasPorTubo) {
        return (fibrasPorTubo > 0 && fibrasPorTubo < n) ? Math.ceil(n / fibrasPorTubo) : 1;
    }

    function alturaCabo(n, fibrasPorTubo) {
        var tubos = qtdTubos(n, fibrasPorTubo);
        return ALT_CAB + 20 + n * passoDasFibras(n)
             + (tubos - 1) * ESPACO_TUBO + PAD_BASE;
    }

    /**
     * Onde cada fibra do cabo fica, já agrupada por tubo.
     *
     * Em cabo multitubo a sequência de cores reinicia a cada tubo, então duas fibras
     * podem ser "verde" no mesmo cabo — o que as distingue é o tubo. Por isso o desenho
     * tem dois estágios: o tubo sai da capa na cor dele e só então se abre nas fibras.
     * Num monotubo o estágio do tubo não existe e as fibras saem direto da boca.
     */
    function geometriaCabo(fibras, fibrasPorTubo, lado) {
        var n     = fibras.length;
        var passo = passoDasFibras(n);
        var porTubo = (fibrasPorTubo > 0 && fibrasPorTubo < n) ? fibrasPorTubo : n;
        var multi = qtdTubos(n, fibrasPorTubo) > 1;
        var esq   = (lado === 'E');

        // Espelha a coordenada quando as pontas ficam à esquerda. Feito na conta, e não
        // com scale(-1,1), para os números não saírem escritos ao contrário.
        var mx = function (x) { return esq ? LARG_NO - x : x; };

        var itens = [];
        var tubos = {};
        var coresTubo = {};
        var y = ALT_CAB + 14 + passo / 2;

        for (var i = 0; i < n; i++) {
            var t = Math.floor(i / porTubo);
            if (i > 0 && i % porTubo === 0) {
                y += ESPACO_TUBO;
            }
            itens.push({ fibra: fibras[i], y: Math.round(y), tubo: t });
            (tubos[t] = tubos[t] || []).push(Math.round(y));
            // A cor do tubo vem pronta do servidor (lib/Fibra), para a tabela de cores da
            // norma existir num lugar só e não divergir entre PHP e JavaScript.
            coresTubo[t] = fibras[i].cor_tubo;
            y += passo;
        }

        var centrosTubo = {};
        for (var k in tubos) {
            if (!tubos.hasOwnProperty(k)) continue;
            var lista = tubos[k];
            centrosTubo[k] = Math.round((lista[0] + lista[lista.length - 1]) / 2);
        }

        return {
            itens: itens, multi: multi, centrosTubo: centrosTubo, coresTubo: coresTubo,
            passo: passo, esq: esq,
            xCapaIni:  mx(6),
            xBoca:     mx(66),
            xTuboFim:  mx(102),
            xAbre:     mx(multi ? 116 : 84),
            xFibraFim: mx(LARG_NO - 14),
            xPonta:    mx(LARG_NO),
            cyCapa:    ALT_CAB + 14 + (n * passo + (qtdTubos(n, fibrasPorTubo) - 1) * ESPACO_TUBO) / 2
        };
    }

    /**
     * Desenha o cabo inteiro: capa, tubos e cada fibra saindo até a sua ponta.
     *
     * A fibra desenhada É o conector — não há mais lista de "Fo01 · Verde" ao lado. A cor
     * da linha diz a cor da fibra, que é como o técnico a identifica na emenda; sobra só
     * o número, pequeno, porque num 12 FO saber que é a sétima importa.
     */
    function desenharCorpoCabo(g, fibras, fibrasPorTubo, lado) {
        var gm = geometriaCabo(fibras, fibrasPorTubo, lado);
        var gi = el('g', { 'class': 'dg-cabo-arte' }, g);

        var altCapa = Math.max(34, Math.abs(gm.xBoca - gm.xCapaIni));
        var yCapa = gm.cyCapa;

        // Capa externa.
        el('rect', { x: Math.min(gm.xCapaIni, gm.xBoca), y: yCapa - 19,
                     width: Math.abs(gm.xBoca - gm.xCapaIni), height: 38, rx: 19,
                     'class': 'dg-cabo-capa' }, gi);
        el('ellipse', { cx: gm.xBoca, cy: yCapa, rx: 7, ry: 15, 'class': 'dg-cabo-boca' }, gi);

        if (gm.multi) {
            // Estágio 1: cada tubo sai da capa na cor dele.
            for (var k in gm.centrosTubo) {
                if (!gm.centrosTubo.hasOwnProperty(k)) continue;
                var cor = gm.coresTubo[k];
                el('path', {
                    d: 'M' + gm.xBoca + ' ' + yCapa +
                       ' L' + gm.xTuboFim + ' ' + gm.centrosTubo[k] +
                       ' L' + gm.xAbre + ' ' + gm.centrosTubo[k],
                    'class': 'dg-cabo-tubo-linha', stroke: cor
                }, gi);
            }
        }

        // Estágio 2: as fibras. Em multitubo saem do tubo; em monotubo, direto da boca.
        gm.itens.forEach(function (it) {
            var xIni = gm.multi ? gm.xAbre : gm.xBoca;
            var yIni = gm.multi ? gm.centrosTubo[it.tubo] : yCapa;
            var xCurva = gm.multi
                ? gm.xAbre + (gm.esq ? -18 : 18)
                : gm.xAbre;

            el('path', {
                d: 'M' + xIni + ' ' + yIni + ' L' + xCurva + ' ' + it.y +
                   ' L' + gm.xFibraFim + ' ' + it.y,
                'class': 'dg-cabo-fibra', stroke: it.fibra.cor
            }, gi);

            if (it.fibra.contorno) {
                el('path', {
                    d: 'M' + xCurva + ' ' + it.y + ' L' + gm.xFibraFim + ' ' + it.y,
                    'class': 'dg-cabo-fibra-borda', stroke: it.fibra.contorno
                }, gi);
            }

            var t = el('text', { x: gm.xFibraFim + (gm.esq ? 14 : -14), y: it.y - 5,
                                 'text-anchor': 'middle', 'class': 'dg-cabo-num' }, gi);
            t.textContent = it.fibra.numero;
        });

        return gm;
    }


    /**
     * Passo entre as vias do splitter.
     *
     * Aqui a via É o conector: a bolinha de conexão fica alinhada com ela, então o passo
     * precisa comportar o alvo de clique, não só o desenho. Por isso não desce abaixo de
     * 14 px mesmo num 1:64 — o nó fica alto, mas continua clicável.
     */
    function passoDasVias(n) {
        return n <= 16 ? 18 : (n <= 32 ? 16 : 14);
    }

    function alturaIlustraSplitter(n) {
        return Math.max(56, n * passoDasVias(n) + 20);
    }

    /** Sem lista separada de portas: a ilustração já traz as pontas. */
    function alturaSplitter(n) {
        return ALT_CAB + alturaIlustraSplitter(n) + PAD_BASE;
    }

    /**
     * Geometria do splitter, num lugar só: onde fica a entrada, a cunha e cada via.
     *
     * A via e o conector são a MESMA coisa (pedido do Marcelo, 21/09/2026) — antes havia
     * a ilustração e, embaixo dela, a lista T01…Tn repetindo tudo. Unificado, um 1:16
     * deixa de ocupar mais de 500 px de altura.
     *
     * As coordenadas são calculadas por lado em vez de espelhar o grupo, porque número
     * espelhado sai escrito ao contrário.
     */
    function geometriaSplitter(n, ladoIn) {
        var esq   = (ladoIn === 'E');
        var passo = passoDasVias(n);
        var largVia = 16;

        return {
            passo:   passo,
            largVia: largVia,
            xPontaIn:  esq ? 0 : LARG_NO,            // bolinha de conexão, na borda
            xCirculo:  esq ? 30 : LARG_NO - 30,      // entrada desenhada
            xVia:      esq ? LARG_NO - 52 : 36,      // canto da via virado para a cunha
            xPontaOut: esq ? LARG_NO : 0,
            xCunha:    esq ? LARG_NO - 52 : 52,      // onde a cunha encosta na coluna
            yTopo:     ALT_CAB + 10 + passo / 2
        };
    }

    function desenharCorpoSplitter(g, spl, ladoIn) {
        var n  = spl.saidas_lista.length;
        var gm = geometriaSplitter(n, ladoIn);
        var gi = el('g', { 'class': 'dg-spl-arte' }, g);

        var yFim = gm.yTopo + (n - 1) * gm.passo;
        var cy   = Math.round((gm.yTopo + yFim) / 2);

        el('path', {
            d: 'M' + gm.xCirculo + ' ' + cy +
               ' L' + gm.xCunha + ' ' + Math.round(gm.yTopo - gm.passo / 2 - 2) +
               ' L' + gm.xCunha + ' ' + Math.round(yFim + gm.passo / 2 + 2) + ' Z',
            'class': 'dg-spl-corpo'
        }, gi);

        // Da bolinha da entrada até o círculo desenhado, e de cada via até a sua bolinha.
        el('line', { x1: gm.xPontaIn, y1: cy, x2: gm.xCirculo, y2: cy, 'class': 'dg-spl-perna' }, gi);
        el('circle', { cx: gm.xCirculo, cy: cy, r: 7,
                       'class': 'dg-spl-in' + (spl.sem_alimentacao ? ' dg-spl-in--vazio' : '') }, gi);

        for (var i = 0; i < n; i++) {
            var s = spl.saidas_lista[i];
            var y = Math.round(gm.yTopo + i * gm.passo);
            var xFimVia = ladoIn === 'E' ? gm.xVia + gm.largVia : gm.xVia;

            el('line', { x1: xFimVia, y1: y, x2: gm.xPontaOut, y2: y, 'class': 'dg-spl-perna' }, gi);

            // Via ocupada fica sólida: dá para ver a lotação do splitter de relance.
            el('rect', { x: gm.xVia, y: y - Math.floor(gm.passo / 2) + 1,
                         width: gm.largVia, height: Math.max(3, gm.passo - 3), rx: 2,
                         'class': 'dg-spl-via' + (s.estado === 'conectada' ? ' dg-spl-via--usada' : '') }, gi);

            var t = el('text', { x: gm.xVia + gm.largVia / 2, y: y + 3.5, 'text-anchor': 'middle',
                                 'class': 'dg-spl-num' }, gi);
            t.textContent = s.numero;
        }

        return { gm: gm, cy: cy };
    }

    /**
     * De que lado do nó saem as pontas.
     *
     * Quem decide é o usuário, pelo botão ⇄ do cabeçalho — e mais ninguém. Antes o lado
     * era deduzido da posição no canvas, então arrastar um nó virava as pontas sozinho e o
     * desenho mudava debaixo da mão de quem estava trabalhando. Com o botão disponível,
     * essa adivinhação deixou de se pagar: o padrão aponta para a direita, o resto é
     * escolha de quem desenha.
     */
    function ladoDoNo(pos) {
        return pos.invertido ? 'E' : 'D';
    }

    function oposto(lado) { return lado === 'D' ? 'E' : 'D'; }

    function noDaPonta(ref) {
        return (ref.elemento === 'VAO_FIBRA' ? 'VAO:' : 'SPLITTER:') + ref.elemento_id;
    }

    /**
     * De que lado fica o IN do splitter.
     *
     * Mesma regra do cabo: decide o botão ⇄, e mais nada. Havia aqui uma orientação
     * automática que votava com base nas ligações existentes; ela acertava na maioria das
     * vezes, mas virava o splitter sozinho no instante em que uma fibra era ligada — e
     * desenho que se mexe sem o usuário mandar vale menos que desenho previsível.
     * O padrão é entrada à esquerda e saídas à direita, como se lê um diagrama.
     */
    function ladoDaEntrada(spl, pos) {
        return pos.invertido ? 'D' : 'E';
    }

    /* ------------------------------------------------------------------ acomodar novos */

    /** Retângulo que cada nó ocupa, com a altura real do desenho. */
    function medirNos() {
        var lista = [];
        (estado.dados.vaos || []).forEach(function (v) {
            var pos = estado.layout[chaveNo('VAO', v.id)];
            if (pos) {
                lista.push({ chave: chaveNo('VAO', v.id), pos: pos,
                             alt: alturaCabo(v.fibras.length, v.fibras_por_tubo) });
            }
        });
        (estado.dados.splitters || []).forEach(function (s) {
            var pos = estado.layout[chaveNo('SPLITTER', s.id)];
            if (pos) {
                lista.push({ chave: chaveNo('SPLITTER', s.id), pos: pos,
                             alt: alturaSplitter(s.saidas_lista.length) });
            }
        });
        return lista;
    }

    function colide(x, y, alt, ocupados) {
        for (var i = 0; i < ocupados.length; i++) {
            var o = ocupados[i];
            if (x < o.pos.pos_x + LARG_NO + FOLGA_NO && x + LARG_NO + FOLGA_NO > o.pos.pos_x
                && y < o.pos.pos_y + o.alt + FOLGA_NO && y + alt + FOLGA_NO > o.pos.pos_y) {
                return true;
            }
        }
        return false;
    }

    /**
     * Dá lugar aos nós que ainda não têm um.
     *
     * O servidor sugere uma posição, mas não sabe a altura do desenho — um splitter novo
     * nascia sempre no mesmo ponto e caía por cima do que já estava lá. Aqui a tela varre
     * a área em busca do primeiro vazio que comporte o nó, e grava a escolha para ela não
     * mudar na próxima abertura.
     */
    function acomodarNovos() {
        var todos = medirNos();
        var fixos = [];
        var novos = [];

        todos.forEach(function (n) { (n.pos.auto ? novos : fixos).push(n); });
        if (!novos.length) return;

        novos.forEach(function (n) {
            for (var y = 40; y <= 4000; y += 40) {
                for (var x = 40; x <= 2000; x += 40) {
                    if (!colide(x, y, n.alt, fixos)) {
                        n.pos.pos_x = x;
                        n.pos.pos_y = y;
                        n.pos.auto = false;
                        estado.sujos[n.chave] = true;
                        fixos.push(n);
                        return;
                    }
                }
            }
            fixos.push(n);   // diagrama lotado: fica onde o servidor sugeriu
        });

        // Grava sem passar pelo histórico: acomodar não é ação do usuário.
        gravarLayout();
    }

    /* ------------------------------------------------------------------ render */

    function render() {
        // O canvas volta ao tamanho da janela a cada desenho: mover um nó muda o tamanho
        // do SVG, e é nessa hora que ele tentava empurrar o canvas para fora da tela.
        ajustarAltura();

        var svg = document.getElementById('svg');
        while (svg.firstChild) svg.removeChild(svg.firstChild);

        // Clique no fundo cancela a conexão em andamento. Substitui o botão "Cancelar
        // conexão" que ocupava uma faixa inteira: o gesto é o mesmo em mouse e em tablet,
        // onde não há tecla Esc. As pontas e as linhas param o clique antes de chegar aqui.
        if (!svg.ftthFundoLigado) {
            svg.addEventListener('click', function () {
                if (estado.selecao) limparSelecao();
            });
            svg.ftthFundoLigado = true;
        }

        estado.pontas = {};
        estado.caixas = {};
        estado.corredores = [];
        estado.caminhos   = {};
        estado.verticais  = [];

        // Índice por id: a orientação dos splitters precisa achar a outra ponta de cada
        // ligação antes de desenhar, e varrer a lista inteira por nó sairia caro.
        estado.ligacaoPorId = {};
        (estado.dados.ligacoes || []).forEach(function (l) { estado.ligacaoPorId[l.id] = l; });

        var gLigacoes = el('g', { 'class': 'dg-ligacoes' }, svg);
        var gNos      = el('g', { 'class': 'dg-nos' }, svg);
        var maxX = 0, maxY = 0;

        (estado.dados.vaos || []).forEach(function (v) {
            var pos = estado.layout[chaveNo('VAO', v.id)];
            if (!pos) return;
            desenharCabo(gNos, v, pos);
            maxX = Math.max(maxX, pos.pos_x + LARG_NO);
            maxY = Math.max(maxY, pos.pos_y + alturaCabo(v.fibras.length, v.fibras_por_tubo));
        });

        (estado.dados.splitters || []).forEach(function (s) {
            var pos = estado.layout[chaveNo('SPLITTER', s.id)];
            if (!pos) return;
            desenharSplitter(gNos, s, pos);
            maxX = Math.max(maxX, pos.pos_x + LARG_NO);
            maxY = Math.max(maxY, pos.pos_y + alturaSplitter(s.saidas_lista.length));
        });

        svg.setAttribute('width',  Math.max(maxX + MARGEM, $('#canvas').width() - 4));
        svg.setAttribute('height', Math.max(maxY + MARGEM, 420));

        desenharLigacoes(gLigacoes);
        aplicarSelecao();

        if (!(estado.dados.vaos || []).length && !(estado.dados.splitters || []).length) {
            $('#canvas').append('<div class="ftth-dg-vazio" id="vazio">' +
                'Nenhum cabo chega nesta caixa e ela não tem splitter.<br>' +
                'Lance um cabo no mapa ou adicione um splitter em Ferramentas.</div>');
        } else {
            $('#vazio').remove();
        }
    }

    function moldura(pai, pos, tipo, id) {
        var g = el('g', { 'class': 'dg-no', 'data-tipo': tipo, 'data-id': id,
                          transform: 'translate(' + pos.pos_x + ',' + pos.pos_y + ')' }, pai);
        return g;
    }

    /**
     * Cabeçalho arrastável. Listener nativo pelo mesmo motivo das pontas: delegação por
     * classe não funciona em SVG com o jQuery 1.9 do MK-AUTH.
     */
    function tornarArrastavel(cabecalho, tipo, id) {
        cabecalho.addEventListener('mousedown', function (ev) {
            iniciarArraste(ev, tipo, id);
        });
    }

    /**
     * Botão ⇄ no canto do cabeçalho: vira as pontas para o outro lado.
     *
     * O `mousedown` é bloqueado antes de subir, senão o clique no botão começaria a
     * arrastar o nó — ele está dentro do cabeçalho, que é a alça de arraste.
     */
    function botaoInverter(cab, tipo, id) {
        var g = el('g', { 'class': 'dg-no-botao' }, cab);
        el('rect', { x: LARG_NO - 25, y: 6, width: 19, height: 19, rx: 4,
                     'class': 'dg-no-botao-fundo' }, g);
        var ic = el('text', { x: LARG_NO - 15.5, y: 20, 'text-anchor': 'middle',
                              'class': 'dg-no-botao-icone' }, g);
        ic.textContent = '⇄';
        var t = el('title', {}, g);
        t.textContent = 'Virar as pontas para o outro lado';

        g.addEventListener('mousedown', function (ev) { ev.stopPropagation(); });
        g.addEventListener('click', function (ev) {
            ev.stopPropagation();
            inverterNo(tipo, id);
        });
    }

    function inverterNo(tipo, id) {
        var chave = chaveNo(tipo, id);
        var pos = estado.layout[chave];
        if (!pos) return;

        var antes = { pos_x: pos.pos_x, pos_y: pos.pos_y,
                      rotacao: pos.rotacao || 0, invertido: !!pos.invertido };

        pos.invertido = !pos.invertido;
        estado.sujos[chave] = true;
        render();

        registrarHistorico({ tipo: 'layout', chave: chave, antes: antes,
                             descricao: 'virar ' + nomeDoNo(tipo, id) });
        gravarLayout();
    }

    function desenharCabo(pai, vao, pos) {
        var alt  = alturaCabo(vao.fibras.length, vao.fibras_por_tubo);
        var lado = ladoDoNo(pos);
        var g = moldura(pai, pos, 'VAO', vao.id);

        el('rect', { x: 0, y: 0, width: LARG_NO, height: alt, rx: 6, 'class': 'dg-no-fundo' }, g);
        var cab = el('g', { 'class': 'dg-no-arrasta' }, g);
        el('rect', { x: 0, y: 0, width: LARG_NO, height: ALT_CAB, rx: 6, 'class': 'dg-no-cabecalho' }, cab);
        texto(cab, 10, 17, vao.sentido);
        texto(cab, 10, 31, (vao.tipo_rotulo || '') + ' · ' + vao.construcao, 'dg-no-sub');
        tornarArrastavel(cab, 'VAO', vao.id);
        botaoInverter(cab, 'VAO', vao.id);
        registrarCaixa('VAO', vao.id, pos, alt);

        var gm = desenharCorpoCabo(g, vao.fibras, vao.fibras_por_tubo, lado);

        gm.itens.forEach(function (it) {
            var f = it.fibra;
            desenharPonta(g, {
                ref:    { elemento: 'VAO_FIBRA', elemento_id: vao.id, numero: f.numero },
                no:     chaveNo('VAO', vao.id),
                x:      gm.xPonta,
                y:      it.y,
                absX:   pos.pos_x + gm.xPonta,
                absY:   pos.pos_y + it.y,
                lado:   lado,
                cor:    f.cor,
                contorno: f.contorno,
                estado: f.estado,
                ligacao_id: f.ligacao_id,
                // A cor da linha já diz qual fibra é; o nome da cor virou dica.
                rotulo: null,
                titulo: f.rotulo + ' · ' + f.cor_nome +
                        (gm.multi ? ' · tubo ' + f.tubo : '') +
                        (f.estado === 'conectada' ? ' · conectada' : ' · livre')
            });
        });
    }

    function desenharSplitter(pai, spl, pos) {
        var alt  = alturaSplitter(spl.saidas_lista.length);
        // A entrada manda: ela aponta para quem alimenta, e as saídas ficam do lado oposto.
        var ladoIn = ladoDaEntrada(spl, pos);
        var lado   = oposto(ladoIn);
        var g = moldura(pai, pos, 'SPLITTER', spl.id);

        el('rect', { x: 0, y: 0, width: LARG_NO, height: alt, rx: 6, 'class': 'dg-no-fundo' }, g);
        var cab = el('g', { 'class': 'dg-no-arrasta' }, g);
        el('rect', { x: 0, y: 0, width: LARG_NO, height: ALT_CAB, rx: 6, 'class': 'dg-no-cabecalho' }, cab);
        tornarArrastavel(cab, 'SPLITTER', spl.id);
        botaoInverter(cab, 'SPLITTER', spl.id);
        registrarCaixa('SPLITTER', spl.id, pos, alt);

        var atend = spl.funcao === 'ATENDIMENTO';
        el('rect', { x: 10, y: 6, width: atend ? 74 : 66, height: 12, rx: 3,
                     'class': 'dg-selo-fundo--' + (atend ? 'atend' : 'deriv') }, cab);
        var selo = el('text', { x: 14, y: 15, 'class': 'dg-selo' }, cab);
        selo.textContent = atend ? 'ATENDIMENTO' : 'DERIVAÇÃO';
        texto(cab, 10, 32, spl.nome + ' · ' + spl.razao);

        if (spl.sem_alimentacao) {
            var av = el('text', { x: LARG_NO - 32, y: 19, 'text-anchor': 'end', 'class': 'dg-alerta' }, cab);
            av.textContent = '⚠ sem alimentação';
        }

        var corpo = desenharCorpoSplitter(g, spl, ladoIn);

        // Entrada

        desenharPonta(g, {
            ref:    { elemento: 'SPLITTER_IN', elemento_id: spl.id, numero: 0 },
            no:     chaveNo('SPLITTER', spl.id),
            x:      corpo.gm.xPontaIn,
            y:      corpo.cy,
            absX:   pos.pos_x + corpo.gm.xPontaIn,
            absY:   pos.pos_y + corpo.cy,
            lado:   ladoIn,
            cor:    '#2979FF',
            estado: spl.entrada_ligacao ? 'conectada' : 'livre',
            ligacao_id: spl.entrada_ligacao,
            rotulo: 'IN',
            titulo: 'Entrada do splitter ' + spl.nome
        });

        spl.saidas_lista.forEach(function (s, i) {
            var y = Math.round(corpo.gm.yTopo + i * corpo.gm.passo);
            desenharPonta(g, {
                ref:    { elemento: 'SPLITTER_OUT', elemento_id: spl.id, numero: s.numero },
                no:     chaveNo('SPLITTER', spl.id),
                x:      corpo.gm.xPontaOut,
                y:      y,
                absX:   pos.pos_x + corpo.gm.xPontaOut,
                absY:   pos.pos_y + y,
                lado:   lado,
                cor:    '#00C853',
                estado: s.estado,
                ligacao_id: s.ligacao_id,
                // O número já está dentro da via: repetir "T01" ao lado da bolinha só
                // ocuparia a largura que a unificação acabou de liberar.
                rotulo: null,
                cliente: s.cliente ? s.cliente.login : null,
                so_cliente: !!s.so_cliente,
                titulo: 'Saída ' + s.rotulo
                      + (s.cliente ? ' · cliente ' + s.cliente.login
                                   : (s.so_cliente ? ' · sem cliente' : ' · livre'))
            });
        });
    }

    function desenharPonta(g, p) {
        var chave = chavePonta(p.ref);
        estado.pontas[chave] = p;

        // Sinal vivo: esta ponta tem caminho óptico até uma porta de DIO com equipamento.
        // É o que o técnico quer saber de relance ao abrir a caixa.
        var dbm = (estado.dados.com_sinal || {})[chave];
        var temSinal = dbm !== undefined;

        var gp = el('g', { 'class': 'dg-ponta dg-ponta--' + p.estado
                                  + (temSinal ? ' dg-ponta--sinal' : ''),
                           'data-ponta': chave }, g);

        // O halo pulsante vai ANTES da bolinha, senão cobre a cor da fibra.
        if (temSinal) {
            el('circle', { cx: p.x, cy: p.y, r: RAIO + 3, 'class': 'dg-ponta-pulso' }, gp);
        }

        el('circle', { cx: p.x, cy: p.y, r: RAIO, 'class': 'dg-ponta-marca',
                       fill: p.estado === 'conectada' ? p.cor : 'none',
                       stroke: p.contorno || p.cor }, gp);
        // Área invisível maior que a bolinha: 5 px de raio é alvo pequeno demais para o mouse.
        el('circle', { cx: p.x, cy: p.y, r: RAIO + 7, fill: 'transparent' }, gp);

        var t = el('title', {}, gp);
        t.textContent = p.titulo + (temSinal ? ' · ' + dbm.toFixed(2) + ' dBm' : '');

        // Ponta sem rótulo (as vias do splitter, que já trazem o número dentro) não ganha
        // texto: o espaço ao lado da bolinha é justamente o que a unificação liberou.
        if (p.rotulo) {
            var alinha = p.lado === 'D' ? 'end' : 'start';
            var xr     = p.lado === 'D' ? p.x - RAIO - 6 : p.x + RAIO + 6;
            var rot    = p.rotulo + (p.cliente ? ' · ' + p.cliente : '');
            texto(gp, xr, p.y + 4, rot, 'dg-ponta-rotulo').setAttribute('text-anchor', alinha);
        }

        // Listener NATIVO, não delegação por classe: o Sizzle do jQuery 1.9 (o que o MK-AUTH
        // carrega) filtra classe por `elem.className`, que em SVG é um SVGAnimatedString e
        // nunca casa. Delegar `.dg-ponta` no document simplesmente não dispara.
        gp.addEventListener('click', function (ev) {
            ev.stopPropagation();
            clicarPonta(chave);
        });
        gp.addEventListener('mouseenter', function () { mostrarPreview(chave); });
        gp.addEventListener('mouseleave', function () { $('#preview').remove(); });

        return gp;
    }

    /** Linha pontilhada até o destino enquanto o mouse passa por cima. Não grava nada. */
    function mostrarPreview(chave) {
        var alvo = estado.pontas[chave];
        if (!estado.selecao || !alvo || !podeLigar(estado.selecao, alvo)) return;

        var a = estado.selecao, b = alvo;
        if (a.absX > b.absX) { var t = a; a = b; b = t; }
        var faixa = faixaDeCanais(a, b);

        $('#preview').remove();
        el('path', { d: caminho(rotear(a, b, Math.round((faixa.lo + faixa.hi) / 2))),
                     id: 'preview', 'class': 'dg-preview' }, document.getElementById('svg'));
    }

    function caminho(pontos) {
        return pontos.map(function (p, i) {
            return (i === 0 ? 'M' : 'L') + p[0] + ' ' + p[1];
        }).join(' ');
    }

    /**
     * A marca de emenda: duas barras inclinadas cruzando a linha, o "//" que o desenho
     * técnico usa para interrupção. Lê-se de longe muito melhor do que um ponto.
     *
     * O traçado é ortogonal, então basta saber se o trecho do meio corre na horizontal ou
     * na vertical para as barras cruzarem a linha em vez de acompanharem ela.
     */
    function marcarEmenda(pts, g, ligacaoId) {
        if (pts.length < 2) return;
        // Meio do trecho mais longo, nunca um vértice: no vértice do meio a marca caía na
        // quina da linha e, na linha reta (só dois pontos), em cima da bolinha do destino.
        var maior = 1, comp = -1;
        for (var i = 1; i < pts.length; i++) {
            var c = Math.abs(pts[i][0] - pts[i - 1][0]) + Math.abs(pts[i][1] - pts[i - 1][1]);
            if (c > comp) { comp = c; maior = i; }
        }
        var p0 = pts[maior - 1], p1 = pts[maior];
        var m  = [(p0[0] + p1[0]) / 2, (p0[1] + p1[1]) / 2];
        var vertical = Math.abs(p1[1] - p0[1]) > Math.abs(p1[0] - p0[0]);

        var d = vertical
            ? 'M' + (m[0] - 5) + ' ' + (m[1] - 4) + ' l10 4 '
            + 'M' + (m[0] - 5) + ' ' + (m[1] + 1) + ' l10 4'
            : 'M' + (m[0] - 4) + ' ' + (m[1] + 5) + ' l4 -10 '
            + 'M' + (m[0] + 1) + ' ' + (m[1] + 5) + ' l4 -10';

        el('path', { d: d, 'class': 'dg-emenda', 'data-ligacao': ligacaoId }, g);
    }

    /* ------------------------------------------------------------------ ligações */

    /**
     * Traça uma ligação: horizontal para fora da ponta, vertical no canal que ela recebeu,
     * horizontal para dentro do destino. Função pura — recebe o canal pronto.
     */
    function rotear(a, b, canal) {
        if (Math.abs(a.absY - b.absY) < 1) {
            return [[a.absX, a.absY], [b.absX, b.absY]];
        }
        var saiA = a.absX + (a.lado === 'D' ? STUB : -STUB);
        var entB = b.absX + (b.lado === 'D' ? STUB : -STUB);
        return [[a.absX, a.absY], [saiA, a.absY], [canal, a.absY],
                [canal, b.absY], [entB, b.absY], [b.absX, b.absY]];
    }

    /**
     * Onde o canal vertical pode ficar.
     *
     * Quando as pontas apontam para lados opostos, o canal fica no meio do caminho —
     * o caso comum. Quando apontam para o MESMO lado, ele precisa ficar além das duas:
     * um canal no meio obrigaria o trecho final a voltar por cima da ponta, e o desenho
     * ganhava um gancho curto do lado de fora do nó.
     */
    function faixaDeCanais(a, b) {
        var saiA = a.absX + (a.lado === 'D' ? STUB : -STUB);
        var entB = b.absX + (b.lado === 'D' ? STUB : -STUB);

        if (a.lado === b.lado) {
            if (a.lado === 'D') {
                var dir = Math.max(saiA, entB);
                return { lo: dir, hi: dir + LARGURA_VOLTA };
            }
            var esq = Math.min(saiA, entB);
            return { lo: esq - LARGURA_VOLTA, hi: esq };
        }
        return { lo: Math.min(saiA, entB), hi: Math.max(saiA, entB) };
    }

    /* ---------------- desvio de nós ---------------- */

    function registrarCaixa(tipo, id, pos, alt) {
        estado.caixas[chaveNo(tipo, id)] = {
            x1: pos.pos_x, y1: pos.pos_y,
            x2: pos.pos_x + LARG_NO, y2: pos.pos_y + alt
        };
    }

    /**
     * TODOS os nós entram como obstáculo, inclusive os dois que a ligação conecta.
     *
     * Parece contraintuitivo, mas é o que impede o pior caso: uma saída que fica na borda
     * esquerda do splitter e precisa alcançar algo à direita saía da ponta, dava meia-volta
     * e cruzava o próprio nó por dentro. A linha encosta na borda — e `segmentoCruza` usa
     * comparação estrita, então tocar não conta —, só não pode passar por dentro.
     */
    function todosOsObstaculos() {
        var lista = [];
        for (var k in estado.caixas) {
            if (estado.caixas.hasOwnProperty(k)) lista.push(estado.caixas[k]);
        }
        return lista;
    }

    /** Os segmentos são sempre horizontais ou verticais, então basta sobrepor retângulos. */
    function segmentoCruza(p1, p2, r) {
        return Math.max(p1[0], p2[0]) > r.x1 && Math.min(p1[0], p2[0]) < r.x2
            && Math.max(p1[1], p2[1]) > r.y1 && Math.min(p1[1], p2[1]) < r.y2;
    }

    function obstaculosNoCaminho(pts, obstaculos) {
        var batidos = [];
        obstaculos.forEach(function (r) {
            for (var i = 0; i + 1 < pts.length; i++) {
                if (segmentoCruza(pts[i], pts[i + 1], r)) { batidos.push(r); return; }
            }
        });
        return batidos;
    }

    /**
     * Sai da ponta, vai por cima (ou por baixo) de tudo que atrapalha e desce no destino.
     * É o "contorna o objeto" — melhor uma linha que dá a volta do que uma que some atrás
     * da caixa e deixa dúvida sobre onde ela termina.
     */
    function limites(lista) {
        var topo = Infinity, base = -Infinity;
        lista.forEach(function (r) {
            topo = Math.min(topo, r.y1);
            base = Math.max(base, r.y2);
        });
        return { topo: topo, base: base };
    }

    /**
     * Corredores horizontais já ocupados por outra linha.
     *
     * Evitar cruzar caixa não bastava: o contorno escolhia a altura "logo acima do nó" e
     * caía justamente em cima da horizontal de outra fibra, que passa por ali na altura da
     * própria ponta. Duas linhas no mesmo corredor viram uma só aos olhos de quem lê.
     */
    function corredorLivre(y, xa, xb) {
        var x1 = Math.min(xa, xb), x2 = Math.max(xa, xb);
        for (var i = 0; i < estado.corredores.length; i++) {
            var c = estado.corredores[i];
            if (Math.abs(c.y - y) < FAIXA_CONTORNO && x2 > c.x1 && x1 < c.x2) return false;
        }
        return true;
    }

    /**
     * O mesmo para os trechos verticais.
     *
     * Duas ligações que saem do MESMO nó pelo mesmo lado têm o stub no mesmo x — os 24 px
     * são fixos —, então descem uma sobre a outra e só se separam lá na horizontal. É o
     * que parecia "linha sobreposta" mesmo com os corredores já separados.
     */
    function verticalLivre(x, ya, yb) {
        var y1 = Math.min(ya, yb), y2 = Math.max(ya, yb);
        for (var i = 0; i < estado.verticais.length; i++) {
            var v = estado.verticais[i];
            if (Math.abs(v.x - x) < FAIXA_CONTORNO && y2 > v.y1 && y1 < v.y2) return false;
        }
        return true;
    }

    function registrarCorredores(pts) {
        for (var i = 0; i + 1 < pts.length; i++) {
            if (pts[i][1] === pts[i + 1][1]) {
                estado.corredores.push({
                    y:  pts[i][1],
                    x1: Math.min(pts[i][0], pts[i + 1][0]),
                    x2: Math.max(pts[i][0], pts[i + 1][0])
                });
            } else if (pts[i][0] === pts[i + 1][0]) {
                estado.verticais.push({
                    x:  pts[i][0],
                    y1: Math.min(pts[i][1], pts[i + 1][1]),
                    y2: Math.max(pts[i][1], pts[i + 1][1])
                });
            }
        }
    }

    /** Afasta a altura do contorno até achar um corredor que ninguém esteja usando. */
    function alturaLivre(base, paraBaixo, xa, xb) {
        for (var k = 0; k < 14; k++) {
            var y = Math.round(base + (paraBaixo ? 1 : -1) * k * FAIXA_CONTORNO);
            if (corredorLivre(y, xa, xb)) return y;
        }
        return Math.round(base);
    }

    /** Afasta o stub da ponta até o trecho vertical não dividir x com outro. */
    function stubLivre(ponta, y) {
        var dir = ponta.lado === 'D' ? 1 : -1;
        for (var k = 0; k < 12; k++) {
            var x = ponta.absX + dir * (STUB + k * FAIXA_CONTORNO);
            if (verticalLivre(x, ponta.absY, y)) return x;
        }
        return ponta.absX + dir * STUB;
    }

    /**
     * Ajusta o canal do caminho direto para não dividir x com um vertical já traçado.
     *
     * `distribuirCanais` separa as ligações DENTRO de um feixe, mas não sabe dos stubs que
     * os contornos vão ocupar nem dos canais de outro feixe. Sem isso, uma ligação direta
     * podia descer exatamente por cima do trecho vertical de outra.
     */
    function canalLivre(canal, ya, yb, fx) {
        if (Math.abs(ya - yb) < 1) return canal;   // linha reta não tem vertical

        for (var k = 0; k < 14; k++) {
            for (var s = 0; s < 2; s++) {
                var x = canal + (s === 0 ? 1 : -1) * k * FAIXA_CONTORNO;
                if (x > fx.lo && x < fx.hi && verticalLivre(x, ya, yb)) return x;
            }
        }
        return canal;
    }

    function contornar(a, b, obstaculos, batidos) {
        if (!batidos.length) return null;

        // Quatro alturas candidatas: logo abaixo e logo acima do que atrapalha, e —
        // se nem isso bastar — fora de tudo que está desenhado.
        var perto = limites(batidos);
        var tudo  = limites(obstaculos);
        var alturas = [
            { base: perto.base + DESVIO, baixo: true },
            { base: perto.topo - DESVIO, baixo: false },
            { base: tudo.base  + DESVIO, baixo: true },
            { base: tudo.topo  - DESVIO, baixo: false }
        ];

        var limpo = null, limpoCusto = Infinity;
        var sujo  = null, sujoCusto  = Infinity;

        for (var i = 0; i < alturas.length; i++) {
            if (!isFinite(alturas[i].base)) continue;

            var estimaA = a.absX + (a.lado === 'D' ? STUB : -STUB);
            var estimaB = b.absX + (b.lado === 'D' ? STUB : -STUB);
            var y = alturaLivre(alturas[i].base, alturas[i].baixo, estimaA, estimaB);

            // Só depois de saber a altura dá para escolher o stub: o trecho vertical vai
            // da ponta até essa altura, e é ele que não pode dividir x com outro.
            var saiA = stubLivre(a, y);
            var entB = stubLivre(b, y);

            var pts = [[a.absX, a.absY], [saiA, a.absY], [saiA, y],
                       [entB, y], [entB, b.absY], [b.absX, b.absY]];
            var custo = Math.abs(a.absY - y) + Math.abs(b.absY - y);

            if (!obstaculosNoCaminho(pts, obstaculos).length) {
                if (custo < limpoCusto) { limpo = pts; limpoCusto = custo; }
            } else if (custo < sujoCusto) {
                sujo = pts; sujoCusto = custo;
            }
        }
        return limpo || sujo;
    }

    /**
     * Caminho definitivo: tenta o canal que o feixe reservou; se esbarrar num nó, varre a
     * faixa atrás de um canal livre; em último caso contorna por fora.
     */
    function caminhoDaLigacao(a, b, canal) {
        var obstaculos = todosOsObstaculos();
        var pts = rotear(a, b, canal);
        var batidos = obstaculosNoCaminho(pts, obstaculos);
        if (!batidos.length) return pts;

        // Procura um canal que passe longe dos nós E não divida x com um vertical já
        // traçado — as duas condições, senão a linha só troca de problema.
        var fx = faixaDeCanais(a, b);
        for (var t = 0; t <= 12; t++) {
            var c = Math.round(fx.lo + (fx.hi - fx.lo) * t / 12);
            if (!verticalLivre(c, a.absY, b.absY)) continue;
            var alt = rotear(a, b, c);
            if (!obstaculosNoCaminho(alt, obstaculos).length) return alt;
        }
        return contornar(a, b, obstaculos, batidos) || pts;
    }

    /**
     * Distribui os canais de um feixe de ligações entre os MESMOS dois nós.
     *
     * A ordem é o que evita cruzamento, e ela não é óbvia: com as ligações descendo, a que
     * sai mais em cima precisa do canal mais à DIREITA. Se fosse o contrário, o trecho
     * horizontal da ligação de baixo atravessaria o trecho vertical da de cima — era isso
     * que embolava o desenho quando o splitter ficava deslocado.
     *
     * Quando as ligações sobem, vale o espelho. Feixe misto (umas sobem, outras descem) não
     * tem solução sem cruzamento com três segmentos; aí o critério só reduz o estrago.
     */
    function distribuirCanais(feixe) {
        var sentido = 0;
        feixe.forEach(function (it) { sentido += (it.b.absY > it.a.absY) ? 1 : -1; });

        feixe.sort(function (p, q) {
            return (p.a.absY - q.a.absY) || (p.b.absY - q.b.absY);
        });
        if (sentido > 0) feixe.reverse();   // descendo: de cima para baixo vira direita→esquerda

        var faixa = faixaDeCanais(feixe[0].a, feixe[0].b);
        var largura = Math.max(0, faixa.hi - faixa.lo);
        var n = feixe.length;

        feixe.forEach(function (it, i) {
            // (i+1)/(n+1) deixa folga nas duas pontas em vez de colar no nó.
            it.canal = Math.round(faixa.lo + largura * (i + 1) / (n + 1));
        });
    }

    function desenharLigacoes(g) {
        var feixes = {};

        (estado.dados.ligacoes || []).forEach(function (l) {
            if (!l.a || !l.b) return;
            var a = estado.pontas[chavePonta(l.a)];
            var b = estado.pontas[chavePonta(l.b)];
            if (!a || !b) return;

            // Origem é sempre a ponta mais à esquerda: assim o feixe entre dois nós fica
            // com a mesma orientação, independente de qual lado foi clicado primeiro.
            if (a.absX > b.absX) { var t = a; a = b; b = t; }

            var chave = a.no + '|' + b.no;
            (feixes[chave] = feixes[chave] || []).push({ l: l, a: a, b: b });
        });

        var todos = [];
        for (var chave in feixes) {
            if (!feixes.hasOwnProperty(chave)) continue;
            distribuirCanais(feixes[chave]);
            todos = todos.concat(feixes[chave]);
        }

        // Duas passadas, e a ordem importa: quem tem caminho direto passa primeiro e marca
        // os corredores que ocupou; só então quem precisa contornar escolhe por onde ir,
        // sabendo o que já está no caminho. O contrário deixava o contorno em cima de uma
        // linha reta que ainda nem tinha sido traçada.
        var obstaculos = todosOsObstaculos();

        todos.forEach(function (it) {
            var canal = canalLivre(it.canal, it.a.absY, it.b.absY, faixaDeCanais(it.a, it.b));
            var pts = rotear(it.a, it.b, canal);
            if (!obstaculosNoCaminho(pts, obstaculos).length) {
                it.pts = pts;
                registrarCorredores(pts);
            }
        });

        todos.forEach(function (it) {
            if (it.pts) return;
            it.pts = caminhoDaLigacao(it.a, it.b, it.canal);
            registrarCorredores(it.pts);
        });

        todos.forEach(function (it) {
            var d = caminho(it.pts);
            // Guardado para o botão de desconectar saber onde a linha passa.
            estado.caminhos[it.l.id] = it.pts;

            // Duas linhas: a fina que se vê e uma larga transparente para o clique pegar.
            var area = el('path', { d: d, 'class': 'dg-ligacao-area', 'data-ligacao': it.l.id }, g);
            var fio  = el('path', { d: d, 'data-ligacao': it.l.id,
                         'class': 'dg-ligacao' + (estado.ligacaoSel === it.l.id ? ' dg-ligacao--sel' : ''),
                         stroke: it.a.estado === 'conectada' ? it.a.cor : null }, g);

            // Emenda ganha a marca de interrupção no meio da linha; passagem fica lisa, que
            // é o que ela é — a fibra atravessando a caixa inteira. Assim o que custa dB é
            // o que salta aos olhos, e o caso comum não polui o desenho.
            if (it.l.tipo !== 'PASSAGEM') {
                marcarEmenda(it.pts, g, it.l.id);
            }

            [area, fio].forEach(function (p) {
                p.addEventListener('click', function (ev) {
                    ev.stopPropagation();
                    selecionarLigacao(it.l.id);
                });
            });
        });
    }

    /* ------------------------------------------------------------------ seleção e conexão */

    /** A mesma matriz do servidor, só para decidir o que realçar. Quem decide é o PHP. */
    function podeLigar(origem, alvo) {
        if (!origem || !alvo) return false;
        if (alvo.estado === 'conectada') return false;
        // Saída de atendimento é o fim da linha: dali sai o drop do cliente, que não
        // aparece no diagrama. O servidor recusa isso com FTTH-TOP-020; aqui a saída
        // nem chega a ser oferecida como alvo.
        if (origem.so_cliente || alvo.so_cliente) return false;
        if (chavePonta(origem.ref) === chavePonta(alvo.ref)) return false;
        if (!estado.matriz[chavePar(origem.ref.elemento, alvo.ref.elemento)]) return false;
        // Entrada e saída do mesmo splitter é laço interno.
        if (origem.ref.elemento !== 'VAO_FIBRA' && alvo.ref.elemento !== 'VAO_FIBRA'
            && origem.ref.elemento_id === alvo.ref.elemento_id) return false;
        return true;
    }

    /**
     * Repinta o estado de cada ponta.
     *
     * Tudo por DOM nativo de propósito: o jQuery 1.9 do MK-AUTH escreve classe via
     * `elem.className`, que em SVG é somente leitura — addClass simplesmente não teria
     * efeito, e o realce das pontas compatíveis nunca apareceria.
     */
    function aplicarSelecao() {
        var origem = estado.selecao;
        var marcas = document.querySelectorAll('[data-ponta]');

        for (var i = 0; i < marcas.length; i++) {
            var marca = marcas[i];
            var chave = marca.getAttribute('data-ponta');
            var p = estado.pontas[chave];
            if (!p) continue;

            // A classe é reescrita por inteiro, então tudo que o desenho pôs precisa
            // voltar aqui — senão o pulso do sinal some assim que algo é selecionado.
            var cls = 'dg-ponta dg-ponta--' + p.estado;
            if ((estado.dados.com_sinal || {})[chave] !== undefined) {
                cls += ' dg-ponta--sinal';
            }
            if (estado.fichaPonta === chave) {
                cls += ' dg-ponta--ficha';
            }
            if (origem) {
                cls += (chave === chavePonta(origem.ref))
                    ? ' dg-ponta--origem'
                    : (podeLigar(origem, p) ? ' dg-ponta--alvo' : ' dg-ponta--indisponivel');
            }
            marca.setAttribute('class', cls);
        }

    }

    function limparSelecao() {
        estado.selecao = null;
        $('#preview').remove();
        aplicarSelecao();
    }

    /* ------------------------------------------------------------------ ficha da fibra */

    /**
     * Painel deslizante com a rota óptica da ponta clicada, no espírito do UpperX:
     * de onde vem o sinal, cada salto com a sua perda, e quanto chega aqui.
     *
     * O cálculo é sempre do servidor (Potencia::rota). A tela não soma dB nenhum — se
     * somasse, passaria a existir uma segunda conta, e duas contas sempre divergem.
     */
    function abrirFichaPonta(chave) {
        var p = estado.pontas[chave];
        if (!p) return;

        estado.fichaPonta = chave;
        aplicarSelecao();
        esconderAcaoLigacao();
        $('#drawer').removeClass('aberto');   // mesma faixa à direita

        $('#ficha-corpo').html('<p class="ftth-dg-vazio">Calculando a rota…</p>');
        alinharDrawer();
        $('#ficha-fibra').addClass('aberto');

        FTTH.chamar({
            url: 'caixa.php?ajax=rota&caixa=' + CFG.caixa
               + '&elemento=' + encodeURIComponent(p.ref.elemento)
               + '&elemento_id=' + p.ref.elemento_id
               + '&numero=' + p.ref.numero,
            onOk: function (d) { $('#ficha-corpo').html(htmlFicha(p, d)); },
            onErro: function (m) {
                // Fibra sem caminho até a OLT é estado normal da rede, não falha: mostra
                // o que se sabe da ponta e diz o porquê, em vez de um erro seco.
                $('#ficha-corpo').html(htmlCabecalhoPonta(p)
                    + '<div class="ftth-dg-sem-rota"><i class="bi-slash-circle"></i> '
                    + FTTH.esc(m) + '</div>');
            }
        });
    }

    function fecharFicha() {
        estado.fichaPonta = null;
        $('#ficha-fibra').removeClass('aberto');
        aplicarSelecao();
    }

    /** Identificação da ponta: qual fibra (ou saída) é esta, e de que peça. */
    function htmlCabecalhoPonta(p) {
        var cor = p.cor || '#9AA7BD';
        return '<div class="ftth-dg-ficha-ponta">'
             +   '<span class="ftth-dg-ficha-bola" style="background:' + cor
             +     ';border-color:' + (p.contorno || cor) + '"></span>'
             +   '<div>'
             +     '<strong>' + FTTH.esc(p.titulo || p.rotulo || '—') + '</strong>'
             +   '</div>'
             + '</div>';
    }

    function htmlFicha(p, d) {
        var o = d.origem || {};

        var html = htmlCabecalhoPonta(p)

            // Cartão da origem: é o que o UpperX põe no topo, e é a pergunta que o
            // técnico faz primeiro -- "de onde vem esse sinal?".
            + '<div class="ftth-dg-origem">'
            +   '<p class="ftth-dg-origem-titulo"><i class="bi-hdd-rack-fill"></i> '
            +     'Origem do sinal</p>'
            +   '<p class="ftth-dg-origem-caixa">' + FTTH.esc(o.caixa || '—') + '</p>'
            +   '<div class="ftth-dg-origem-grade">'
            +     campoOrigem('Serviço', o.servico)
            +     campoOrigem('Equipamento', [o.olt, o.pon].filter(Boolean).join(' · '))
            +     campoOrigem('DIO / Porta', [o.dio, o.porta].filter(Boolean).join(' / '))
            +     campoOrigem('Potência', formatarDbm(o.ptx_dbm))
            +   '</div>'
            + '</div>'

            + '<div class="ftth-dg-chegada ftth-dg-chegada--' + FTTH.esc(d.classe) + '">'
            +   '<span>Chega nesta ponta</span>'
            +   '<strong>' + formatarDbm(d.dbm) + '</strong>'
            +   '<span class="ftth-dg-classe">' + rotuloClasse(d.classe) + '</span>'
            + '</div>'

            + '<p class="ftth-dg-ficha-secao">Rota óptica · '
            +   (d.passos || []).length + ' salto(s)</p>';

        // Os passos vêm lineares do servidor; aqui só se agrupa por caixa, que é como
        // quem lê pensa a rede: "no POP aconteceu isso, na CEO aquilo".
        var caixaAtual = null;
        (d.passos || []).forEach(function (s) {
            if (s.via === 'CABO') {
                html += '<div class="ftth-dg-salto-cabo">'
                     +    '<i class="bi-arrow-down"></i> '
                     +    FTTH.esc(s.rotulo) + ' · ' + formatarKm(s.km)
                     +    '<span class="ftth-dg-perda">−' + s.perda.toFixed(2) + ' dB</span>'
                     +  '</div>';
                caixaAtual = null;   // o próximo passo já é na caixa seguinte
                return;
            }

            if (s.caixa && s.caixa !== caixaAtual) {
                caixaAtual = s.caixa;
                html += '<div class="ftth-dg-salto-caixa">'
                     +    '<span>' + FTTH.esc(s.caixa) + '</span>'
                     +    '<strong>' + formatarDbm(s.dbm) + '</strong>'
                     +  '</div>';
            }

            html += '<div class="ftth-dg-salto">'
                 +    '<span class="ftth-dg-salto-tipo">' + rotuloVia(s.via) + '</span>'
                 +    FTTH.esc(s.rotulo)
                 +    '<span class="ftth-dg-perda">−' + s.perda.toFixed(2) + ' dB</span>'
                 +  '</div>';
        });

        return html;
    }

    function campoOrigem(rotulo, valor) {
        return '<div><span>' + FTTH.esc(rotulo) + '</span><strong>'
             + FTTH.esc(valor || '—') + '</strong></div>';
    }

    /** +3,00 dBm / −19,27 dBm — sinal explícito, vírgula decimal. */
    function formatarDbm(v) {
        var n = parseFloat(v);
        if (isNaN(n)) return '—';
        return (n > 0 ? '+' : n < 0 ? '−' : '') + Math.abs(n).toFixed(2).replace('.', ',') + ' dBm';
    }

    function formatarKm(km) {
        var n = parseFloat(km);
        if (isNaN(n)) return '';
        return n < 1 ? Math.round(n * 1000) + ' m' : n.toFixed(2).replace('.', ',') + ' km';
    }

    function rotuloVia(via) {
        return { FUSAO: 'Fusão', CONECTOR: 'Conector', SPLITTER: 'Splitter',
                 CABO: 'Cabo' }[via] || via;
    }

    function rotuloClasse(classe) {
        return { saturado: 'Saturado', excelente: 'Excelente', bom: 'Bom',
                 limite: 'No limite', critico: 'Crítico' }[classe] || classe;
    }

    function clicarPonta(chave) {
        var p = estado.pontas[chave];
        if (!p) return;

        // Saída de atendimento nunca conecta, nem ocupada nem livre: ali o clique só
        // consulta, porque quem ocupa a saída é o cliente e o drop não vem para o desenho.
        // Ponta ocupada segue a mesma regra — desconectar saiu daqui de propósito e agora
        // é só pela linha da ligação, para consultar e destruir não ficarem a um clique
        // de distância um do outro.
        if (!estado.selecao && (p.so_cliente || p.estado === 'conectada')) {
            abrirFichaPonta(chave);
            return;
        }
        if (!estado.selecao) {
            esconderAcaoLigacao();
            estado.selecao = p;
            aplicarSelecao();
            // O toast sai aqui, onde a seleção NASCE. Em aplicarSelecao() ele repetiria a
            // cada redesenho. As pontas compatíveis já estão realçadas no canvas; isto é
            // só o lembrete de que há uma conexão em andamento, e de como sair dela.
            toast('info', 'Conectando ' + descreverPonta(p.ref)
                + ' — clique na outra ponta, ou no fundo para cancelar.');
            return;
        }
        if (chavePonta(estado.selecao.ref) === chave) {
            limparSelecao();
            return;
        }
        if (!podeLigar(estado.selecao, p)) {
            toast('erro', 'Essas duas pontas não podem ser ligadas.');
            return;
        }
        conectar(estado.selecao.ref, p.ref);
    }

    function conectar(a, b, opcoes) {
        opcoes = opcoes || {};
        FTTH.chamar({
            url: 'caixa.php?ajax=conectar',
            method: 'POST',
            data: {
                csrf: CFG.csrf, caixa: CFG.caixa,
                a_elemento: a.elemento, a_id: a.elemento_id, a_numero: a.numero,
                b_elemento: b.elemento, b_id: b.elemento_id, b_numero: b.numero,
                tipo: opcoes.tipo || null
            },
            onOk: function (d, resp) {
                limparSelecao();
                if (!opcoes.semHistorico) {
                    registrarHistorico({
                        tipo: 'ligacao_criada', id: d.id,
                        descricao: 'ligar ' + descreverPonta(a) + ' a ' + descreverPonta(b)
                    });
                }
                carregar(function () {
                    if (opcoes.aoTerminar) { opcoes.aoTerminar(); }
                    else { mostrarResposta('Conexão criada.', resp); }
                });
            },
            onErro: function (m) { limparSelecao(); toast('erro', m); }
        });
    }

    /** Texto curto de uma ponta, para o histórico ficar legível. */
    function descreverPonta(ref) {
        var p = estado.pontas[chavePonta(ref)];
        if (p) return p.titulo.split(' · ')[0];
        return ref.elemento === 'VAO_FIBRA' ? 'fibra ' + ref.numero
             : (ref.elemento === 'SPLITTER_IN' ? 'entrada do splitter' : 'saída ' + ref.numero);
    }

    function selecionarLigacao(id) {
        estado.ligacaoSel = id;
        limparSelecao();
        render();
        posicionarAcaoLigacao();
    }

    /**
     * Põe o botão de desconectar no meio da linha selecionada.
     *
     * Dentro do canvas, em coordenadas do desenho: assim ele acompanha a rolagem e fica
     * onde o olho já está, em vez de numa faixa no topo com a largura da tela.
     */
    function posicionarAcaoLigacao() {
        var $a = $('#acao-ligacao');
        var pts = estado.ligacaoSel ? estado.caminhos[estado.ligacaoSel] : null;

        if (!pts || !pts.length) {
            $a.hide();
            return;
        }
        var meio = pts[Math.floor(pts.length / 2)];
        $a.css({ left: meio[0] + 'px', top: meio[1] + 'px' }).show();

        // O botão de alternar só faz sentido onde a sangria é possível: fibra com fibra,
        // mesma bitola e mesmo número. Nas outras, marcar passagem seria zerar uma perda
        // que existe — o servidor recusa com FTTH-TOP-021 e aqui nem se oferece.
        var l = estado.ligacaoPorId[estado.ligacaoSel];
        if (l && podeSerPassagem(l)) {
            $('#btn-tipo-texto').text(l.tipo === 'PASSAGEM' ? 'Marcar fusão' : 'Marcar passagem');
            $('#btn-tipo-ligacao').show();
        } else {
            $('#btn-tipo-ligacao').hide();
        }
    }

    /** Espelha o Topologia::ehPassagem — só para decidir o que mostrar, nunca o que vale. */
    function podeSerPassagem(l) {
        var a = l.a, b = l.b;
        if (!a || !b) return false;
        if (a.elemento !== 'VAO_FIBRA' || b.elemento !== 'VAO_FIBRA') return false;
        if (Number(a.numero) !== Number(b.numero)) return false;

        var va = vaoPorId(a.elemento_id), vb = vaoPorId(b.elemento_id);
        return !!va && !!vb && va.cabo_tipo_id === vb.cabo_tipo_id;
    }

    function vaoPorId(id) {
        var achado = null;
        (estado.dados.vaos || []).forEach(function (v) {
            if (Number(v.id) === Number(id)) achado = v;
        });
        return achado;
    }

    function esconderAcaoLigacao() {
        estado.ligacaoSel = null;
        $('#acao-ligacao').hide();
    }

    /**
     * Alterna a ligação entre sangria e emenda.
     *
     * Não é topologia: as pontas ficam onde estão, muda só se aquele ponto custa 0,10 dB
     * ou nada. Por isso entra no Desfazer como qualquer outra alteração da caixa.
     */
    function alterarTipoLigacao(id, tipo, opcoes) {
        opcoes = opcoes || {};
        var antes = (estado.ligacaoPorId[id] || {}).tipo;

        FTTH.chamar({
            url: 'caixa.php?ajax=tipo_ligacao',
            method: 'POST',
            data: { csrf: CFG.csrf, ligacao: id, tipo: tipo },
            onOk: function (d, resp) {
                if (!opcoes.semHistorico && antes) {
                    registrarHistorico({
                        tipo: 'tipo_ligacao', id: id, antes: antes,
                        descricao: (tipo === 'PASSAGEM' ? 'marcar passagem' : 'marcar fusão')
                    });
                }
                // Recarrega porque a potência de toda a caixa muda junto: o pulso e os
                // dBm da ficha saem da mesma conta que acabou de mudar.
                carregar(function () {
                    if (opcoes.aoTerminar) { opcoes.aoTerminar(); }
                    else {
                        mostrarResposta(tipo === 'PASSAGEM'
                            ? 'Marcada como passagem — sem perda de fusão.'
                            : 'Marcada como fusão.', resp);
                    }
                });
            },
            onErro: function (m) { toast('erro', m); }
        });
    }

    function desconectar(id, opcoes) {
        opcoes = opcoes || {};

        // Guarda as pontas ANTES de apagar: é com elas que o desfazer religa a fibra.
        var lig = estado.ligacaoPorId[id];
        var a = lig && lig.a, b = lig && lig.b, tipoLig = lig && lig.tipo;

        FTTH.chamar({
            url: 'caixa.php?ajax=desconectar',
            method: 'POST',
            data: { csrf: CFG.csrf, ligacao: id },
            onOk: function (d, resp) {
                // Some com o botão na hora: a linha que ele oferecia cortar já não existe.
                esconderAcaoLigacao();
                if (!opcoes.semHistorico && a && b) {
                    registrarHistorico({
                        tipo: 'ligacao_removida', a: a, b: b, tipoLigacao: tipoLig,
                        descricao: 'desligar ' + descreverPonta(a) + ' de ' + descreverPonta(b)
                    });
                }
                carregar(function () {
                    if (opcoes.aoTerminar) { opcoes.aoTerminar(); }
                    else { mostrarResposta('Ligação desfeita.', resp); }
                });
            },
            onErro: function (m) { toast('erro', m); }
        });
    }

    /* ------------------------------------------------------------------ arraste dos nós */

    function iniciarArraste(ev, tipo, id) {
        var pos = estado.layout[chaveNo(tipo, id)];
        if (!pos) return;
        var svg = document.getElementById('svg');
        var r = svg.getBoundingClientRect();
        estado.arraste = {
            tipo: tipo, id: id,
            dx: (ev.clientX - r.left) - pos.pos_x,
            dy: (ev.clientY - r.top)  - pos.pos_y,
            // Posição de partida: é para cá que o Desfazer traz o nó de volta.
            antes: { pos_x: pos.pos_x, pos_y: pos.pos_y,
                     rotacao: pos.rotacao || 0, invertido: !!pos.invertido }
        };
        ev.preventDefault();
    }

    function moverArraste(ev) {
        if (!estado.arraste) return;
        var svg = document.getElementById('svg');
        var r = svg.getBoundingClientRect();
        var pos = estado.layout[chaveNo(estado.arraste.tipo, estado.arraste.id)];
        pos.pos_x = Math.max(0, Math.round((ev.clientX - r.left) - estado.arraste.dx));
        pos.pos_y = Math.max(0, Math.round((ev.clientY - r.top)  - estado.arraste.dy));
        estado.sujos[chaveNo(estado.arraste.tipo, estado.arraste.id)] = true;
        agendarRender();
    }

    /**
     * Redesenha no máximo uma vez por quadro. O mousemove dispara muito mais que isso, e
     * um cabo de 144 fibras recria centenas de elementos a cada render.
     */
    var renderPendente = false;
    function agendarRender() {
        if (renderPendente) return;
        renderPendente = true;
        window.requestAnimationFrame(function () {
            renderPendente = false;
            render();
        });
    }

    /**
     * Fim do arraste: grava a posição e registra UMA entrada no histórico.
     *
     * O registro é aqui, e não no mousemove, senão arrastar um nó pela tela encheria a
     * pilha de dezenas de passos de um pixel cada.
     */
    function soltarArraste() {
        var a = estado.arraste;
        estado.arraste = null;
        if (!a) return;

        var chave = chaveNo(a.tipo, a.id);
        var pos = estado.layout[chave];
        if (!pos || (pos.pos_x === a.antes.pos_x && pos.pos_y === a.antes.pos_y)) {
            return;   // clique sem arrastar não é alteração
        }

        registrarHistorico({ tipo: 'layout', chave: chave, antes: a.antes,
                             descricao: 'mover ' + nomeDoNo(a.tipo, a.id) });
        gravarLayout();
    }

    /** Nome legível do nó, para as mensagens do histórico. */
    function nomeDoNo(tipo, id) {
        var lista = tipo === 'VAO' ? (estado.dados.vaos || []) : (estado.dados.splitters || []);
        for (var i = 0; i < lista.length; i++) {
            if (parseInt(lista[i].id, 10) === parseInt(id, 10)) {
                return lista[i].sentido || lista[i].nome || 'item';
            }
        }
        return 'item';
    }

    /* ------------------------------------------------------------------ histórico */

    /**
     * Pilha de tudo que foi feito desde que a caixa foi aberta.
     *
     * Antes havia um botão Salvar que gravava só o layout, enquanto as ligações já iam
     * para o banco na hora. Era meia verdade: o usuário lia "Salvar" e entendia que podia
     * mexer à vontade e sair sem consequência. Agora tudo grava na hora e a rede de
     * segurança é esta — desfazer é a operação inversa de verdade, no banco, não um
     * rascunho no navegador.
     */
    function registrarHistorico(acao) {
        estado.historico.push(acao);
        atualizarDesfazer();
    }

    function atualizarDesfazer() {
        var n = estado.historico.length;
        var ultima = n ? estado.historico[n - 1] : null;
        $('#btn-desfazer').prop('disabled', n === 0)
            .attr('title', ultima ? 'Desfazer: ' + ultima.descricao : 'Nada para desfazer');
        $('#desfazer-conta').text(n ? ' (' + n + ')' : '');
    }

    function desfazer() {
        var acao = estado.historico.pop();
        if (!acao) return;
        atualizarDesfazer();

        var aviso = function () { toast('ok', 'Desfeito: ' + acao.descricao + '.'); };

        if (acao.tipo === 'layout') {
            estado.layout[acao.chave] = acao.antes;
            estado.sujos[acao.chave] = true;
            render();
            gravarLayout(aviso);

        } else if (acao.tipo === 'ligacao_criada') {
            desconectar(acao.id, { semHistorico: true, aoTerminar: aviso });

        } else if (acao.tipo === 'ligacao_removida') {
            conectar(acao.a, acao.b, { semHistorico: true, tipo: acao.tipoLigacao, aoTerminar: aviso });

        } else if (acao.tipo === 'tipo_ligacao') {
            alterarTipoLigacao(acao.id, acao.antes, { semHistorico: true, aoTerminar: aviso });

        } else if (acao.tipo === 'fusao_lote') {
            FTTH.chamar({
                url: 'caixa.php?ajax=desconectar_lote',
                method: 'POST',
                data: { csrf: CFG.csrf, ligacoes: JSON.stringify(acao.ids) },
                onOk: function (d) {
                    carregar(function () {
                        // Menos do que foi pedido significa que alguma ligação do lote já
                        // tinha saído por outro caminho — não é falha, mas o usuário deve
                        // saber que o estado não voltou exatamente ao de antes.
                        if (d.desfeitas < d.pedidas) {
                            toast('avis', 'Desfeitas ' + d.desfeitas + ' de ' + d.pedidas
                                + ': o resto já não existia.');
                        } else {
                            aviso();
                        }
                        if ($('#aba-fusionar').is(':visible')) montarFusionar();
                    });
                },
                onErro: function (m) { toast('erro', m); }
            });

        } else if (acao.tipo === 'splitter_criado') {
            excluirSplitter(acao.id, aviso);

        } else if (acao.tipo === 'splitter_excluido') {
            // Volta o splitter com os mesmos dados. As ligações não voltam — para ele ter
            // sido excluído, tinha de estar sem nenhuma.
            criarSplitter(acao.dados, { semHistorico: true, aoTerminar: aviso });

        } else if (acao.tipo === 'splitter_alterado') {
            FTTH.chamar({
                url: 'caixa.php?ajax=alterar_splitter',
                method: 'POST',
                data: { csrf: CFG.csrf, splitter: acao.id, nome: acao.antes.nome,
                        razao: acao.antes.razao, saidas: acao.antes.saidas },
                onOk: function () { carregar(function () { listarItens(); aviso(); }); },
                onErro: function (m) { toast('erro', m); }
            });
        }
    }

    function excluirSplitter(id, aoTerminar) {
        FTTH.chamar({
            url: 'caixa.php?ajax=excluir_splitter',
            method: 'POST',
            data: { csrf: CFG.csrf, splitter: id },
            onOk: function (d, resp) {
                carregar(function () { if (aoTerminar) aoTerminar(); });
            },
            // Se o splitter já tem fibra ligada, o serviço recusa — e está certo. A
            // mensagem do catálogo já explica o que desfazer antes.
            onErro: function (m) { toast('erro', m); }
        });
    }

    function gravarLayout(aoTerminar) {
        var nos = [];
        for (var chave in estado.sujos) {
            if (!estado.sujos.hasOwnProperty(chave)) continue;
            var partes = chave.split(':');
            var pos = estado.layout[chave];
            nos.push({ tipo: partes[0], elemento_id: parseInt(partes[1], 10),
                       pos_x: pos.pos_x, pos_y: pos.pos_y,
                       rotacao: pos.rotacao || 0, invertido: pos.invertido ? 1 : 0 });
        }
        if (!nos.length) {
            if (aoTerminar) aoTerminar();
            return;
        }
        FTTH.chamar({
            url: 'caixa.php?ajax=salvar_layout',
            method: 'POST',
            data: { csrf: CFG.csrf, caixa: CFG.caixa, nos: JSON.stringify(nos) },
            onOk: function (d, resp) {
                estado.sujos = {};
                if (aoTerminar) aoTerminar();
            },
            onErro: function (m) { toast('erro', m); }
        });
    }

    /* ------------------------------------------------------------------ drawer */

    /**
     * O drawer é `fixed`, então precisa saber onde o canvas está na janela: sem isso ele
     * cobria a barra do diagrama e ficava por baixo do menu do painel.
     */
    function alinharDrawer() {
        var $c = $('#canvas');
        if (!$c.length) return;
        var r = $c[0].getBoundingClientRect();
        $('#drawer, #ficha-fibra').css({ top: Math.round(r.top) + 'px',
                                         height: Math.round(r.height) + 'px' });
    }

    function abrirDrawer() {
        fecharFicha();            // os dois ocupam a mesma faixa à direita
        alinharDrawer();
        listarItens();
        $('#drawer').addClass('aberto');
    }

    function trocarAba(aba) {
        $('.ftth-dg-aba').removeClass('ativa');
        $('.ftth-dg-aba[data-aba="' + aba + '"]').addClass('ativa');
        $('#aba-adicionar').toggle(aba === 'adicionar');
        $('#aba-editar').toggle(aba === 'editar');
        $('#aba-fusionar').toggle(aba === 'fusionar');
        if (aba === 'editar') listarItens();
        if (aba === 'fusionar') montarFusionar();
    }

    /* ------------------------------------------------------------------ fusão em lote */

    /** Os dois seletores trazem os cabos que chegam nesta caixa, com a capacidade à vista. */
    function montarFusionar() {
        var vaos = estado.dados.vaos || [];
        if (vaos.length < 2) {
            $('#aba-fusionar .ftth-dg-grupo').nextAll().hide();
            $('#fus-previa').html('<p class="ftth-dg-vazio">'
                + 'Esta caixa tem menos de dois cabos — não há o que interligar.</p>').show();
            return;
        }
        $('#aba-fusionar .ftth-dg-grupo').nextAll().show();

        var opcoes = vaos.map(function (v) {
            return '<option value="' + v.id + '">' + FTTH.esc(v.sentido)
                 + ' · ' + FTTH.esc(v.tipo_rotulo) + '</option>';
        }).join('');

        $('#fus-a').html(opcoes).val(vaos[0].id);
        $('#fus-b').html(opcoes).val(vaos[1].id);
        $('#fus-previa').empty();
    }

    /** "ST CEO.09" — o nome que o usuário vê, para a descrição do Desfazer. */
    function nomeDoVao(id) {
        var achado = null;
        (estado.dados.vaos || []).forEach(function (v) {
            if (Number(v.id) === Number(id)) achado = v.sentido;
        });
        return achado || ('cabo ' + id);
    }

    /**
     * Escolher o mesmo cabo dos dois lados não é um erro a ser reclamado depois: é um
     * estado que não devia existir. Quando os dois seletores coincidem, o outro anda para
     * o próximo cabo — assim nunca dá para pedir o impossível.
     */
    function ajustarSelecaoFusao(mexido) {
        var $a = $('#fus-a'), $b = $('#fus-b');
        if ($a.val() !== $b.val()) {
            $('#fus-aviso').remove();
            return;
        }
        var $outro = mexido === 'a' ? $b : $a;
        var alternativa = null;
        $outro.find('option').each(function () {
            if (alternativa === null && this.value !== $a.val()) alternativa = this.value;
        });

        if (alternativa !== null) {
            $outro.val(alternativa);
            $('#fus-aviso').remove();
        }
        $('#fus-previa').empty();
    }

    function simularFusao() {
        var a = parseInt($('#fus-a').val(), 10);
        var b = parseInt($('#fus-b').val(), 10);
        if (!a || !b) return;
        if (a === b) {
            // Só chega aqui se a caixa tiver um cabo só — o ajuste não teve para onde ir.
            $('#fus-previa').html('<div class="ftth-aviso ftth-aviso--erro" id="fus-aviso">'
                + 'Escolha dois cabos diferentes.</div>');
            return;
        }
        chamarFusao(a, b, false);
    }

    function chamarFusao(a, b, aplicar) {
        $('#fus-previa').html('<p class="ftth-dg-vazio">Calculando…</p>');
        FTTH.chamar({
            url: 'caixa.php?ajax=ligar_cabos',
            method: 'POST',
            data: { csrf: CFG.csrf, caixa: CFG.caixa, vao_a: a, vao_b: b,
                    aplicar: aplicar ? '1' : '0' },
            onOk: function (d) {
                if (!aplicar) { $('#fus-previa').html(htmlPreviaFusao(d, a, b)); return; }
                $('#fus-previa').empty();

                // O lote entra no Desfazer como UMA ação: desfazer 24 fusões uma a uma
                // seria pior do que não ter desfazer.
                var criadas = (d.pares || []).filter(function (p) {
                    return p.estado === 'ligada' && p.ligacao_id;
                }).map(function (p) { return p.ligacao_id; });

                if (criadas.length) {
                    registrarHistorico({
                        tipo: 'fusao_lote', ids: criadas,
                        descricao: 'ligar ' + criadas.length + ' fibra(s) entre '
                                 + nomeDoVao(a) + ' e ' + nomeDoVao(b)
                    });
                }
                carregar(function () {
                    toast(d.puladas ? 'avis' : 'ok',
                        d.ligadas + ' fibra(s) ligada(s)'
                        + (d.puladas ? ', ' + d.puladas + ' pulada(s) por já estarem conectadas.' : '.'));
                    montarFusionar();
                });
            },
            onErro: function (m) {
                $('#fus-previa').html('<div class="ftth-aviso ftth-aviso--erro">'
                    + FTTH.esc(m) + '</div>');
            }
        });
    }

    /**
     * A prévia existe porque isto cria dezenas de ligações de uma vez: ver antes o que vai
     * acontecer é mais barato do que desfazer vinte fusões depois.
     */
    function htmlPreviaFusao(d, a, b) {
        if (!d.ligadas) {
            return '<div class="ftth-aviso">Nada a ligar: todas as '
                 + d.total + ' fibras do trecho já estão conectadas.</div>';
        }

        var tipos = {};
        (d.pares || []).forEach(function (p) {
            if (p.estado === 'ligar') tipos[p.tipo] = (tipos[p.tipo] || 0) + 1;
        });
        var comoSerao = Object.keys(tipos).map(function (t) {
            return tipos[t] + ' ' + (t === 'PASSAGEM' ? 'passagem (sem perda)' : 'fusão');
        }).join(' · ');

        var html = '<div class="ftth-dg-previa">'
                 + '<strong>' + d.ligadas + ' de ' + d.total + ' fibras</strong>'
                 + '<span>' + comoSerao + '</span>';

        if (d.fibras_a !== d.fibras_b) {
            html += '<span class="ftth-dg-previa-nota">Cabos de ' + d.fibras_a + ' e '
                 +  d.fibras_b + ' fibras: para na menor.</span>';
        }
        if (d.puladas) {
            html += '<span class="ftth-dg-previa-nota">' + d.puladas
                 +  ' pulada(s) por já estarem conectadas.</span>';
        }
        html += '</div>';

        // A lista só aparece quando há o que explicar — em 24 pares limpos, ela é ruído.
        var puladas = (d.pares || []).filter(function (p) { return p.estado === 'pulada'; });
        if (puladas.length) {
            html += '<ul class="ftth-dg-previa-lista">';
            puladas.slice(0, 8).forEach(function (p) {
                html += '<li>Fo' + (p.numero < 10 ? '0' : '') + p.numero + ' — '
                     +  FTTH.esc(p.motivo) + '</li>';
            });
            if (puladas.length > 8) {
                html += '<li>… e mais ' + (puladas.length - 8) + '</li>';
            }
            html += '</ul>';
        }

        html += '<button class="ftth-btn ftth-btn--pri" id="btn-fus-aplicar" '
             +  'style="width:100%;margin-top:8px" data-a="' + a + '" data-b="' + b + '">'
             +  '<i class="bi-check-circle-fill"></i> Ligar as ' + d.ligadas + '</button>';
        return html;
    }

    /**
     * Lista o que está no diagrama, no padrão do UpperX.
     *
     * Cabo aparece só para conferência, sem ações: ele pertence ao mapa, onde tem rota e
     * comprimento. Editar ou excluir cabo por aqui deixaria a planta e o diagrama
     * discordando sobre o que existe em campo.
     */
    function listarItens() {
        var $l = $('#lista-itens').empty();
        if (!estado.dados) return;

        var vaos = estado.dados.vaos || [];
        var spls = estado.dados.splitters || [];

        vaos.forEach(function (v) {
            $l.append(
                '<div class="ftth-dg-item ftth-dg-item--cabo">' +
                '<div><strong>' + FTTH.esc(v.sentido) + '</strong>' +
                '<span>' + FTTH.esc(v.tipo_rotulo || '') + ' · ' + FTTH.esc(v.construcao) +
                ' · no mapa</span></div></div>'
            );
        });

        spls.forEach(function (s) {
            var classe = s.funcao === 'ATENDIMENTO' ? ' ftth-dg-item--spl-at' : '';
            var acoes = '<div class="ftth-dg-item-acoes">' +
                '<button class="ftth-dg-mini js-spl-editar" data-id="' + s.id + '" title="Editar">' +
                '<i class="bi-pencil"></i></button>' +
                '<button class="ftth-dg-mini ftth-dg-mini--perigo js-spl-excluir" data-id="' + s.id +
                '" title="Excluir"><i class="bi-trash3"></i></button></div>';
            $l.append(
                '<div class="ftth-dg-item ftth-dg-item--spl' + classe + '">' +
                '<div><strong>' + FTTH.esc(s.nome) + '</strong>' +
                '<span>SPL ' + FTTH.esc(s.razao) + ' · ' +
                (s.funcao === 'ATENDIMENTO' ? 'atendimento' : 'derivação') + '</span></div>' +
                acoes + '</div>'
            );
        });

        if (!vaos.length && !spls.length) {
            $l.append('<div class="ftth-dg-vazio-lista">Nada no diagrama ainda.</div>');
        }
    }

    function splitterPorId(id) {
        var lista = estado.dados.splitters || [];
        for (var i = 0; i < lista.length; i++) {
            if (parseInt(lista[i].id, 10) === parseInt(id, 10)) return lista[i];
        }
        return null;
    }

    function abrirModalEditar(id) {
        var s = splitterPorId(id);
        if (!s) return;

        var razoes = (CFG.catalogo && CFG.catalogo[s.modelo === 'DESBAL' ? 'DESBAL' : 'BAL']) || [];
        var $sel = $('#editar-razao').empty();
        razoes.forEach(function (r) {
            $sel.append('<option value="' + FTTH.esc(r.razao) + '" data-saidas="' + r.saidas + '">'
                        + FTTH.esc(r.razao) + ' · ' + r.saidas + ' saídas</option>');
        });
        // Modelo personalizado não tem catálogo: mantém a razão atual como única opção.
        if (!razoes.length) {
            $sel.append('<option value="' + FTTH.esc(s.razao) + '" data-saidas="' + s.saidas + '">'
                        + FTTH.esc(s.razao) + '</option>');
        }
        $sel.val(s.razao);

        $('#editar-titulo').text('Editar ' + s.nome);
        $('#editar-nome').val(s.nome);
        $('#editar-saida').empty();
        $('#modal-editar').data('id', s.id).data('versao', s.versao).show();
    }

    function salvarEdicao() {
        var id = $('#modal-editar').data('id');
        var s = splitterPorId(id);
        if (!s) return;

        var razao  = $('#editar-razao').val();
        var saidas = parseInt($('#editar-razao option:selected').attr('data-saidas'), 10) || s.saidas;
        var antes  = { nome: s.nome, razao: s.razao, saidas: s.saidas };

        var $b = $('#btn-editar-salvar').prop('disabled', true);
        FTTH.chamar({
            url: 'caixa.php?ajax=alterar_splitter',
            method: 'POST',
            data: {
                csrf: CFG.csrf, splitter: id, versao: $('#modal-editar').data('versao'),
                nome: $('#editar-nome').val(), razao: razao, saidas: saidas
            },
            onOk: function (d, resp) {
                $('#modal-editar').hide();
                registrarHistorico({ tipo: 'splitter_alterado', id: id, antes: antes,
                                     descricao: 'editar ' + antes.nome });
                carregar(function () {
                    listarItens();
                    mostrarResposta('Splitter atualizado.', resp);
                });
            },
            onErro: function (m) {
                $('#editar-saida').html('<div class="ftth-aviso ftth-aviso--erro">' +
                    FTTH.esc(m) + '</div>');
            }
        }).always(function () { $b.prop('disabled', false); });
    }

    function confirmarExclusao(id) {
        var s = splitterPorId(id);
        if (!s) return;
        if (!window.confirm('Excluir o splitter ' + s.nome + '?\n\n'
            + 'Só sai se não houver cliente nem fibra ligada nele.')) {
            return;
        }
        var dados = { funcao: s.funcao, modelo: s.modelo, razao: s.razao,
                      saidas: s.saidas, nome: s.nome };
        excluirSplitter(id, function () {
            registrarHistorico({ tipo: 'splitter_excluido', dados: dados,
                                 descricao: 'excluir ' + s.nome });
            listarItens();
            toast('ok', 'Splitter ' + s.nome + ' excluído.');
        });
    }

    function fecharDrawer() {
        $('#drawer').removeClass('aberto');
    }

    /* ------------------------------------------------------------------ splitter */

    function abrirModalSplitter(modelo, razao, saidas) {
        estado.splitter = { modelo: modelo, razao: razao || '', saidas: saidas || 0,
                            funcao: 'ATENDIMENTO' };
        $('#splitter-titulo').text(modelo === 'BAL' ? 'Configurar SPL ' + razao
            : (modelo === 'DESBAL' ? 'Configurar SPL Desbalanceado' : 'Configurar SPL Personalizado'));
        $('#linha-razao').toggle(modelo === 'DESBAL');
        $('#linha-saidas').toggle(modelo === 'PERSONALIZADO');
        $('#splitter-nome').val('');
        $('#splitter-saida').empty();
        $('.ftth-dg-funcoes .ftth-tipo').removeClass('ativo').first().addClass('ativo');
        $('#modal-splitter').show();
    }

    function adicionarSplitter() {
        var s = estado.splitter;
        criarSplitter({
            funcao: s.funcao,
            modelo: s.modelo,
            razao:  s.modelo === 'DESBAL' ? $('#splitter-razao').val() : s.razao,
            saidas: s.modelo === 'PERSONALIZADO' ? parseInt($('#splitter-saidas').val(), 10)
                  : (s.modelo === 'DESBAL' ? 2 : s.saidas),
            nome:   $('#splitter-nome').val()
        });
    }

    /** Criação em si, reaproveitada pelo desfazer de uma exclusão. */
    function criarSplitter(dados, opcoes) {
        opcoes = opcoes || {};
        var $b = $('#btn-add-splitter').prop('disabled', true);

        FTTH.chamar({
            url: 'caixa.php?ajax=criar_splitter',
            method: 'POST',
            data: {
                csrf: CFG.csrf, caixa: CFG.caixa,
                funcao: dados.funcao, modelo: dados.modelo, razao: dados.razao,
                saidas: dados.saidas, nome: dados.nome,
                // Orientação segue a função: atendimento horizontal, derivação vertical.
                orientacao: dados.funcao === 'ATENDIMENTO' ? 'H' : 'V'
            },
            onOk: function (d, resp) {
                $('#modal-splitter').hide();
                fecharDrawer();
                if (!opcoes.semHistorico) {
                    registrarHistorico({ tipo: 'splitter_criado', id: d.id,
                                         descricao: 'adicionar ' + d.nome });
                }
                carregar(function () {
                    listarItens();
                    if (opcoes.aoTerminar) { opcoes.aoTerminar(); }
                    else { mostrarResposta('Splitter ' + d.nome + ' adicionado.', resp); }
                });
            },
            onErro: function (m) {
                $('#splitter-saida').html('<div class="ftth-aviso ftth-aviso--erro">' +
                    FTTH.esc(m) + '</div>');
                toast('erro', m);
            }
        }).always(function () { $b.prop('disabled', false); });
    }

    /* ------------------------------------------------------------------ eventos */

    $(function () {
        if (!CFG.caixa) return;

        // O <html> também precisa ser travado: `overflow` no body sozinho não impede a
        // página de rolar em todo navegador, e é essa rolagem que levava a barra embora.
        document.documentElement.style.overflow = 'hidden';

        ajustarAltura();
        atualizarDesfazer();
        $(window).on('resize', function () { ajustarAltura(); alinharDrawer(); });
        carregar();

        // Clique em ponta, em ligação e o arraste são ligados na criação de cada elemento
        // SVG (ver desenharPonta / tornarArrastavel). Delegação por classe não serve aqui:
        // o Sizzle do jQuery 1.9 não enxerga classe em SVG.
        $(document).on('click', '#btn-desconectar', function () {
            if (estado.ligacaoSel) desconectar(estado.ligacaoSel);
        });

        $(document).on('click', '#btn-tipo-ligacao', function () {
            var l = estado.ligacaoPorId[estado.ligacaoSel];
            if (!l) return;
            alterarTipoLigacao(estado.ligacaoSel, l.tipo === 'PASSAGEM' ? 'FUSAO' : 'PASSAGEM');
        });

        document.addEventListener('mousemove', moverArraste);
        document.addEventListener('mouseup', soltarArraste);

        // Clicar no vazio fecha o que estiver aberto — mesmo gesto do mapa.
        $(document).on('click', '#canvas', function () {
            limparSelecao();
            if (estado.ligacaoSel) { esconderAcaoLigacao(); render(); }
        });

        $(document).on('keydown', function (ev) {
            if (ev.key === 'Escape') {
                limparSelecao();
                if (estado.ligacaoSel) { esconderAcaoLigacao(); render(); }
                $('#modal-splitter').hide();
                $('#modal-editar').hide();
                fecharDrawer();
                fecharFicha();
            }
        });

        $('#btn-fechar-ficha').on('click', fecharFicha);

        // Clique fora fecha a ficha. Listener NATIVO e subida manual da árvore porque o
        // alvo costuma ser um elemento SVG, e o Sizzle do jQuery 1.9 não lida com eles.
        // A bolinha não chega aqui: o listener dela dá stopPropagation.
        document.addEventListener('click', function (ev) {
            if (!estado.fichaPonta) return;
            for (var n = ev.target; n; n = n.parentNode) {
                if (n.id === 'ficha-fibra') return;
            }
            fecharFicha();
        });
        $('#btn-desfazer').on('click', desfazer);

        // Sair não pergunta mais nada: não há pendência para perder. Ctrl+Z desfaz.
        $(document).on('keydown', function (ev) {
            if ((ev.ctrlKey || ev.metaKey) && (ev.key === 'z' || ev.key === 'Z')) {
                ev.preventDefault();
                desfazer();
            }
        });

        $('#btn-tela-cheia').on('click', alternarTelaCheia);

        // Entrar e sair da tela cheia muda a altura disponível; o canvas precisa refazer
        // a conta, senão sobra ou falta espaço embaixo.
        document.addEventListener('fullscreenchange', function () {
            ajustarAltura();
            render();
        });
        $('#btn-ferramentas').on('click', function () {
            if ($('#drawer').hasClass('aberto')) { fecharDrawer(); } else { abrirDrawer(); }
        });
        $('#btn-fechar-drawer').on('click', fecharDrawer);

        $('.ftth-dg-cartao').on('click', function () {
            abrirModalSplitter($(this).attr('data-modelo'), $(this).attr('data-razao'),
                parseInt($(this).attr('data-saidas'), 10) || 0);
        });

        $('.ftth-dg-funcoes .ftth-tipo').on('click', function () {
            $('.ftth-dg-funcoes .ftth-tipo').removeClass('ativo');
            $(this).addClass('ativo');
            estado.splitter.funcao = $(this).attr('data-funcao');
        });

        $('#btn-add-splitter').on('click', adicionarSplitter);

        $('.ftth-dg-aba').on('click', function () { trocarAba($(this).attr('data-aba')); });

        $('#btn-fus-simular').on('click', simularFusao);
        $('#fus-a').on('change', function () { ajustarSelecaoFusao('a'); });
        $('#fus-b').on('change', function () { ajustarSelecaoFusao('b'); });
        // O botão de aplicar nasce dentro da prévia, então é delegado.
        $(document).on('click', '#btn-fus-aplicar', function () {
            chamarFusao(parseInt($(this).data('a'), 10), parseInt($(this).data('b'), 10), true);
        });
        $(document).on('click', '.js-spl-editar', function () {
            abrirModalEditar($(this).attr('data-id'));
        });
        $(document).on('click', '.js-spl-excluir', function () {
            confirmarExclusao($(this).attr('data-id'));
        });
        $('#btn-editar-salvar').on('click', salvarEdicao);
        $('#btn-editar-cancelar, #btn-editar-fechar').on('click', function () {
            $('#modal-editar').hide();
        });
        $('#btn-fechar-splitter, #btn-cancelar-splitter').on('click', function () {
            $('#modal-splitter').hide();
        });
    });

    /**
     * Tela cheia de verdade, pela API do navegador: o diagrama ocupa o monitor inteiro,
     * sem o painel do MK-AUTH em volta. Sair é pelo mesmo botão ou pelo Esc.
     */
    function alternarTelaCheia() {
        var alvo = document.querySelector('.ftth-diagrama');
        if (!alvo) return;

        if (document.fullscreenElement) {
            if (document.exitFullscreen) document.exitFullscreen();
        } else if (alvo.requestFullscreen) {
            alvo.requestFullscreen();
        }
    }

    /**
     * Prende o diagrama à janela, nas duas dimensões.
     *
     * A altura segue a conta do mapa: o canvas fica com o que sobra da tela. A largura é
     * travada em JavaScript porque não dá para confiar no CSS aqui — os containers do
     * painel podem ser mais largos que a janela, e como a barra distribui o conteúdo com
     * space-between, os botões acabavam na ponta direita DELES, fora da vista.
     */
    function ajustarAltura() {
        var $c = $('#canvas');
        if (!$c.length) return;

        var larg = document.documentElement.clientWidth;
        $('.ftth-diagrama').css('max-width', larg + 'px');

        // Largura e altura em pixels, medidas a partir de onde o canvas começa. Deixar a
        // largura por conta do CSS não bastava: ao arrastar um nó para longe, o desenho
        // crescia, o canvas passava da janela e levava a própria barra de rolagem para
        // fora da tela — o scroll continuava existindo, só não dava para alcançar.
        var r = $c[0].getBoundingClientRect();
        $c.width(Math.max(320, larg - r.left - 22));
        $c.height(Math.max(280, window.innerHeight - r.top - 24));
    }
})();
