/**
 * TD Notas — notas visuais sobre os elementos da página.
 *
 * JavaScript simples: sem jQuery, sem bibliotecas, sem compilação.
 * Toda a interface vive dentro de um Shadow DOM: o CSS do tema não a afeta
 * e o CSS das notas não afeta o site.
 * Regra de segurança: o texto dos utilizadores entra sempre com textContent,
 * nunca com innerHTML.
 */
( function () {
	'use strict';

	var dados = window.tdNotasDados;

	// Sem dados, dentro de um iframe (editores visuais, Personalizador) ou em
	// browsers sem Shadow DOM: não faz nada.
	if ( ! dados || window.self !== window.top || ! document.body || ! document.body.attachShadow ) {
		return;
	}

	/* ---------------------------------------------------------------------
	 * Estado
	 * ------------------------------------------------------------------ */

	var CHAVE_VISIVEL = 'td_notas_visivel';
	var CHAVE_PAINEL = 'td_notas_painel';

	var estado = {
		visivel: lerPreferencia( CHAVE_VISIVEL ) === '1', // Interruptor "Mostrar notas" / "Ocultar notas".
		painelAberto: lerPreferencia( CHAVE_PAINEL ) !== '0', // Painel "Notas desta página" aberto ou recolhido.
		destaqueId: 0,             // Nota a destacar (vinda do backoffice).
		carregadas: false,         // As notas já foram pedidas ao servidor?
		notas: [],                 // Notas desta página + notas globais.
		modoNova: false,           // Modo "+ Nova nota": à espera de um clique num elemento.
		hover: null,               // Elemento sob o rato no modo "+ Nova nota".
		selecao: null,             // Âncora da caixa aberta: { elemento, x, y } + dados da nota nova ou { nota }.
		mostrarFechadas: false,    // Por defeito, as notas fechadas não aparecem.
		filtroEstado: '',          // Filtro do resumo do painel: só as notas deste estado ('' = sem filtro).
		rascunhos: {}              // Resposta por enviar de cada nota (id → { texto, ficheiro }), para não se perder.
	};

	var ecraLargo = window.matchMedia( '(min-width: ' + dados.larguraMinima + 'px)' );

	// Vindo do backoffice ("Ver na página"): mostra logo as notas.
	if ( dados.notaDestaque ) {
		estado.visivel = true;
		gravarPreferencia( CHAVE_VISIVEL, true );
	}

	/* ---------------------------------------------------------------------
	 * Funções de apoio
	 * ------------------------------------------------------------------ */

	function lerPreferencia( chave ) {
		try {
			return window.localStorage.getItem( chave );
		} catch ( erro ) {
			return null;
		}
	}

	function gravarPreferencia( chave, ligado ) {
		try {
			window.localStorage.setItem( chave, ligado ? '1' : '0' );
		} catch ( erro ) {
			// Sem localStorage (modo privado, bloqueado): a escolha vale só para esta página.
		}
	}

	/**
	 * Cria um elemento. O texto entra sempre como texto, nunca como HTML.
	 */
	function el( etiqueta, classe, texto ) {
		var elemento = document.createElement( etiqueta );

		if ( classe ) {
			elemento.className = classe;
		}

		if ( texto !== undefined && texto !== null ) {
			elemento.textContent = String( texto );
		}

		return elemento;
	}

	/**
	 * Pedido à REST do plugin. Devolve uma Promise com o JSON, ou rejeita
	 * com um Error cuja mensagem pode ser mostrada ao utilizador.
	 */
	function pedir( metodo, sufixo, corpo ) {
		var url = dados.restUrl + ( sufixo || '' );
		var opcoes = {
			method: metodo === 'GET' ? 'GET' : 'POST',
			credentials: 'same-origin',
			headers: { 'X-WP-Nonce': dados.nonce }
		};

		if ( metodo === 'GET' && corpo ) {
			url += ( url.indexOf( '?' ) === -1 ? '?' : '&' ) + new URLSearchParams( corpo ).toString();
		} else if ( corpo ) {
			opcoes.headers[ 'Content-Type' ] = 'application/json';
			opcoes.body = JSON.stringify( corpo );
		}

		// Alguns servidores bloqueiam DELETE: vai como POST com este cabeçalho.
		if ( metodo === 'DELETE' ) {
			opcoes.headers[ 'X-HTTP-Method-Override' ] = 'DELETE';
		}

		return window.fetch( url, opcoes ).then( function ( resposta ) {
			return resposta.json().catch( function () {
				return null;
			} ).then( function ( json ) {
				if ( ! resposta.ok ) {
					throw new Error( json && json.message ? json.message : 'Ocorreu um erro. Tenta de novo.' );
				}

				return json;
			} );
		} );
	}

	/**
	 * Um id só serve de âncora se for único na página (em listas e componentes
	 * repetidos os ids podem repetir-se).
	 */
	function ancoraDe( elemento ) {
		var candidatos = [];

		if ( elemento.id ) {
			candidatos.push( '#' + window.CSS.escape( elemento.id ) );
		}

		// O Elementor identifica os elementos com data-id, que é estável.
		if ( elemento.getAttribute( 'data-id' ) ) {
			candidatos.push( '[data-id="' + window.CSS.escape( elemento.getAttribute( 'data-id' ) ) + '"]' );
		}

		for ( var i = 0; i < candidatos.length; i++ ) {
			try {
				if ( document.querySelectorAll( candidatos[ i ] ).length === 1 ) {
					return candidatos[ i ];
				}
			} catch ( erro ) {
				// Seletor inválido: tenta o seguinte.
			}
		}

		return '';
	}

	/**
	 * Seletor CSS do elemento, igual em qualquer builder:
	 * - se o elemento (ou um ancestral) tiver um id único, parte daí;
	 * - o resto do caminho usa a posição entre irmãos do mesmo tipo.
	 * Nos filhos diretos do <body> ignora a barra de administração e as próprias
	 * notas, para o caminho ser o mesmo para quem vê e para quem não vê a barra.
	 */
	function calcularSeletor( elemento ) {
		var IGNORAR = ':not(#wpadminbar):not(#td-notas)';
		var partes = [];
		var atual = elemento;

		while ( atual && atual.nodeType === 1 && atual !== document.body && atual !== document.documentElement ) {
			var ancora = ancoraDe( atual );

			if ( ancora ) {
				partes.unshift( ancora );
				return partes.join( ' > ' );
			}

			var etiqueta = window.CSS.escape( atual.localName );
			var noCorpo = atual.parentElement === document.body;
			var indice = 1;
			var irmao = atual.previousElementSibling;

			while ( irmao ) {
				if ( irmao.localName === atual.localName && ! ( noCorpo && ( irmao.id === 'wpadminbar' || irmao.id === 'td-notas' ) ) ) {
					indice++;
				}

				irmao = irmao.previousElementSibling;
			}

			partes.unshift(
				noCorpo
					? ':nth-child(' + indice + ' of ' + etiqueta + IGNORAR + ')'
					: etiqueta + ':nth-of-type(' + indice + ')'
			);

			atual = atual.parentElement;
		}

		partes.unshift( 'body' );

		return partes.join( ' > ' );
	}

	/**
	 * Procura o elemento de um seletor. Devolve null se não existir ou se o
	 * seletor for inválido: nesse caso a nota aparece só no painel, sem erro.
	 */
	function encontrarElemento( seletor ) {
		if ( ! seletor ) {
			return null;
		}

		try {
			return document.querySelector( seletor );
		} catch ( erro ) {
			return null;
		}
	}

	/**
	 * Início do texto do elemento, para ajudar a reconhecê-lo na lista.
	 */
	function excertoDe( elemento ) {
		var texto = elemento.innerText || elemento.textContent || elemento.getAttribute( 'alt' ) || elemento.getAttribute( 'aria-label' ) || '';

		return texto.replace( /\s+/g, ' ' ).trim().slice( 0, 120 );
	}

	/**
	 * O elemento está no cabeçalho ou no rodapé do site? (nota "Global")
	 * Um <header> ou <footer> dentro de um artigo ou secção não conta.
	 */
	function estaEmZonaGlobal( elemento ) {
		var seletores = dados.seletoresGlobais.join( ',' );

		try {
			var zona = elemento.closest( seletores );

			while ( zona ) {
				var simples = zona.localName === 'header' || zona.localName === 'footer';

				if ( ! simples || ! zona.parentElement || ! zona.parentElement.closest( 'article, main, section, aside' ) ) {
					return true;
				}

				zona = zona.parentElement ? zona.parentElement.closest( seletores ) : null;
			}
		} catch ( erro ) {
			// Lista de seletores inválida (filtro mal configurado): trata como nota normal.
		}

		return false;
	}

	/**
	 * Rascunho da resposta de uma nota: sobrevive a redesenhar ou fechar a caixa
	 * (por exemplo, ao mudar o estado antes de enviar a resposta).
	 */
	function rascunhoDe( id ) {
		if ( ! estado.rascunhos[ id ] ) {
			estado.rascunhos[ id ] = { texto: '', ficheiro: null };
		}

		return estado.rascunhos[ id ];
	}

	function temRascunho( id ) {
		var rascunho = estado.rascunhos[ id ];

		return !! ( rascunho && ( rascunho.texto.trim() || rascunho.ficheiro ) );
	}

	function notaPorId( id ) {
		for ( var i = 0; i < estado.notas.length; i++ ) {
			if ( estado.notas[ i ].id === id ) {
				return estado.notas[ i ];
			}
		}

		return null;
	}

	/**
	 * Substitui uma nota pela versão devolvida pelo servidor, mantendo o número.
	 */
	function guardarNota( nova ) {
		for ( var i = 0; i < estado.notas.length; i++ ) {
			if ( estado.notas[ i ].id === nova.id ) {
				nova.numero = estado.notas[ i ].numero;
				estado.notas[ i ] = nova;
				return nova;
			}
		}

		var maior = 0;

		estado.notas.forEach( function ( nota ) {
			maior = Math.max( maior, nota.numero || 0 );
		} );

		nova.numero = maior + 1;
		estado.notas.push( nova );

		return nova;
	}

	/**
	 * Notas a mostrar na página: nenhuma se estiverem ocultas; as fechadas só
	 * com o interruptor "Mostrar fechadas".
	 */
	function notasVisiveis() {
		if ( ! estado.visivel ) {
			return [];
		}

		return estado.notas.filter( function ( nota ) {
			if ( estado.filtroEstado ) {
				return nota.estado === estado.filtroEstado;
			}

			return estado.mostrarFechadas || nota.estado !== 'fechada';
		} );
	}

	function nomeDoEstado( slug ) {
		return dados.estados[ slug ] || dados.estados.aberta;
	}

	/**
	 * Carrega um ficheiro para a Biblioteca de Multimédia pela API nativa do
	 * WordPress (aplica as regras normais de permissões, tipos e tamanho).
	 * Devolve uma Promise com o ID do ficheiro.
	 */
	function enviarFicheiro( ficheiro ) {
		var corpo = new FormData();

		corpo.append( 'file', ficheiro, ficheiro.name );

		return window.fetch( dados.mediaUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'X-WP-Nonce': dados.nonce },
			body: corpo
		} ).then( function ( resposta ) {
			return resposta.json().catch( function () {
				return null;
			} ).then( function ( json ) {
				if ( ! resposta.ok || ! json || ! json.id ) {
					throw new Error( json && json.message ? json.message : 'Não foi possível carregar o ficheiro.' );
				}

				return json.id;
			} );
		} );
	}

	function tamanhoLegivel( bytes ) {
		return bytes >= 1048576 ? Math.round( bytes / 1048576 ) + ' MB' : Math.round( bytes / 1024 ) + ' KB';
	}

	/**
	 * Notas por resolver: todas menos as fechadas. É o número do botão.
	 */
	function notasPorResolver() {
		return estado.notas.filter( function ( nota ) {
			return nota.estado !== 'fechada';
		} );
	}

	/* ---------------------------------------------------------------------
	 * Interface: contentor isolado e botão principal
	 * ------------------------------------------------------------------ */

	var anfitriao = el( 'div' );
	anfitriao.id = 'td-notas';

	var sombra = anfitriao.attachShadow( { mode: 'open' } );
	var raiz = el( 'div', 'td-notas-raiz' );
	raiz.hidden = true; // Só aparece depois de o CSS carregar.

	var folha = el( 'link' );
	folha.rel = 'stylesheet';
	folha.href = dados.cssUrl;
	folha.addEventListener( 'load', function () {
		raiz.hidden = false;
	} );
	folha.addEventListener( 'error', function () {
		raiz.hidden = false;
	} );

	var barra = el( 'div', 'td-notas-barra' );
	var botaoPrincipal = el( 'button', 'td-notas-botao td-notas-botao--principal' );
	var rotuloPrincipal = el( 'span', 'td-notas-botao__rotulo' );
	var contador = el( 'span', 'td-notas-contador' );

	var botaoNova = el( 'button', 'td-notas-botao' );
	var dica = el( 'span', 'td-notas-dica', 'Clica no elemento que queres anotar · Esc cancela' );

	// Contorno do elemento sob o rato / escolhido. Fica por cima da página, sem a alterar.
	var contorno = el( 'div', 'td-notas-contorno' );
	contorno.hidden = true;

	// Camada dos ícones das notas, por cima da página.
	var camada = el( 'div', 'td-notas-camada' );
	var marcadores = {}; // id da nota → { botao, elemento }

	// Caixa para escrever, ver e responder a uma nota.
	var popover = el( 'div', 'td-notas-popover' );
	popover.hidden = true;
	popover.setAttribute( 'role', 'dialog' );
	popover.setAttribute( 'aria-label', 'Nota' );

	// Painel "Notas desta página".
	var painel = el( 'div', 'td-notas-painel' );
	var painelCabecalho = el( 'button', 'td-notas-painel__cabecalho' );
	var painelTitulo = el( 'span', '', 'Notas desta página' );
	var painelSeta = el( 'span', 'td-notas-painel__seta' );
	var painelCorpo = el( 'div', 'td-notas-painel__corpo' );
	var lista = el( 'div', 'td-notas-lista' );
	var opcaoFechadas = el( 'label', 'td-notas-painel__opcao' );
	var caixaFechadas = el( 'input' );
	var rotuloFechadas = el( 'span', '', 'Mostrar fechadas' );

	painelCabecalho.type = 'button';
	painelCabecalho.appendChild( painelTitulo );
	painelCabecalho.appendChild( painelSeta );
	caixaFechadas.type = 'checkbox';
	opcaoFechadas.appendChild( caixaFechadas );
	opcaoFechadas.appendChild( rotuloFechadas );
	// Resumo por estado, numa só linha: clicar num estado filtra a lista e os pinos.
	var resumo = el( 'div', 'td-notas-resumo' );

	// Nomes curtos, para caberem numa linha. O nome completo fica na dica.
	var NOMES_CURTOS = { aberta: 'Abertas', 'em-curso': 'Em curso', 'para-verificar': 'Verificar', 'em-revisao': 'TD rever', fechada: 'Fechadas' };

	painelCorpo.appendChild( resumo );
	painelCorpo.appendChild( lista );
	painelCorpo.appendChild( opcaoFechadas );
	painel.appendChild( painelCabecalho );
	painel.appendChild( painelCorpo );

	painelCabecalho.addEventListener( 'click', function () {
		estado.painelAberto = ! estado.painelAberto;
		gravarPreferencia( CHAVE_PAINEL, estado.painelAberto );
		atualizar();
	} );

	caixaFechadas.addEventListener( 'change', function () {
		estado.mostrarFechadas = caixaFechadas.checked;
		atualizar();
	} );

	botaoPrincipal.type = 'button';
	botaoPrincipal.appendChild( rotuloPrincipal );
	botaoPrincipal.appendChild( contador );
	botaoNova.type = 'button';
	barra.appendChild( botaoPrincipal );
	barra.appendChild( botaoNova );
	barra.appendChild( dica );
	raiz.appendChild( contorno );
	raiz.appendChild( camada );
	raiz.appendChild( painel );
	raiz.appendChild( barra );
	raiz.appendChild( popover );

	// O que se escreve nas notas não deve ativar atalhos de teclado do site.
	[ 'keydown', 'keyup', 'keypress' ].forEach( function ( tipo ) {
		raiz.addEventListener( tipo, function ( evento ) {
			evento.stopPropagation();
		} );
	} );
	sombra.appendChild( folha );
	sombra.appendChild( raiz );
	document.body.appendChild( anfitriao );

	botaoPrincipal.addEventListener( 'click', function () {
		estado.visivel = ! estado.visivel;
		gravarPreferencia( CHAVE_VISIVEL, estado.visivel );
		atualizar();
	} );

	botaoNova.addEventListener( 'click', function () {
		if ( estado.modoNova ) {
			terminarModoNova();
		} else {
			fecharPopover();
			iniciarModoNova();
		}
	} );

	/* ---------------------------------------------------------------------
	 * Modo "+ Nova nota": escolher um elemento
	 * ------------------------------------------------------------------ */

	// Enquanto o modo está ativo, estes eventos não chegam ao site:
	// os links não abrem, os formulários não são enviados, os menus não reagem ao clique.
	var EVENTOS_BLOQUEADOS = [ 'click', 'dblclick', 'auxclick', 'contextmenu', 'mousedown', 'mouseup', 'pointerdown', 'pointerup', 'submit' ];

	function iniciarModoNova() {
		estado.modoNova = true;
		estado.hover = null;
		document.documentElement.classList.add( 'td-notas-modo-nova' );

		EVENTOS_BLOQUEADOS.forEach( function ( tipo ) {
			window.addEventListener( tipo, aoInteragirNoModoNova, true );
		} );
		window.addEventListener( 'mousemove', aoMoverNoModoNova, true );

		atualizar();
	}

	function terminarModoNova() {
		if ( ! estado.modoNova ) {
			return;
		}

		estado.modoNova = false;
		estado.hover = null;
		document.documentElement.classList.remove( 'td-notas-modo-nova' );

		EVENTOS_BLOQUEADOS.forEach( function ( tipo ) {
			window.removeEventListener( tipo, aoInteragirNoModoNova, true );
		} );
		window.removeEventListener( 'mousemove', aoMoverNoModoNova, true );

		atualizar();
	}

	/**
	 * Elemento da página a que o evento se refere, ou null se for a interface
	 * das notas, o <html> ou o <body>.
	 */
	function alvoDoEvento( evento ) {
		var alvo = evento.target;

		if ( ! alvo || alvo.nodeType !== 1 || alvo === anfitriao || alvo === document.documentElement || alvo === document.body ) {
			return null;
		}

		// Dentro de um SVG (ícones), a nota fica no SVG inteiro.
		return alvo.ownerSVGElement || alvo;
	}

	function aoMoverNoModoNova( evento ) {
		estado.hover = alvoDoEvento( evento );
		agendarReposicao();
	}

	function aoInteragirNoModoNova( evento ) {
		// Os botões das próprias notas continuam a funcionar.
		if ( evento.target === anfitriao ) {
			return;
		}

		evento.preventDefault();
		evento.stopPropagation();
		evento.stopImmediatePropagation();

		if ( evento.type !== 'click' ) {
			return;
		}

		var elemento = alvoDoEvento( evento );

		if ( ! elemento ) {
			return;
		}

		var caixa = elemento.getBoundingClientRect();
		var seletor = calcularSeletor( elemento );

		// Se o seletor não levar de volta ao mesmo elemento, não é guardado:
		// a nota fica só no painel, em vez de aparecer no sítio errado.
		if ( encontrarElemento( seletor ) !== elemento ) {
			seletor = '';
		}

		estado.selecao = {
			elemento: elemento,
			seletor: seletor,
			excerto: excertoDe( elemento ),
			global: estaEmZonaGlobal( elemento ),
			x: caixa.width ? Math.min( 100, Math.max( 0, ( evento.clientX - caixa.left ) / caixa.width * 100 ) ) : 50,
			y: caixa.height ? Math.min( 100, Math.max( 0, ( evento.clientY - caixa.top ) / caixa.height * 100 ) ) : 50
		};

		terminarModoNova();
		abrirFormularioNovaNota();
	}

	/* ---------------------------------------------------------------------
	 * Caixa da nota (popover)
	 * ------------------------------------------------------------------ */

	function fecharPopover() {
		popover.hidden = true;
		popover.textContent = '';
		popover.classList.remove( 'td-notas-popover--sem-elemento' );
		estado.selecao = null;
		agendarReposicao();
	}

	/**
	 * Campo de texto com o botão de gravar e, opcionalmente, "Cancelar".
	 * opcoes: { rotulo, botao, linhas, aoGravar( texto, anexoId ) → Promise, aoCancelar, rascunho }
	 * Com "rascunho" (objeto { texto, ficheiro }), o que se escreve fica guardado e é reposto.
	 * Quem pode carregar ficheiros vê também "Anexar ficheiro" (um ficheiro por mensagem).
	 */
	function criarFormulario( opcoes ) {
		var rotulo = opcoes.rotulo;
		var aoGravar = opcoes.aoGravar;
		var formulario = el( 'form', 'td-notas-formulario' );
		var campo = el( 'textarea', 'td-notas-campo' );
		var erro = el( 'p', 'td-notas-erro' );
		var acoes = el( 'div', 'td-notas-acoes' );
		var gravar = el( 'button', 'td-notas-botao td-notas-botao--principal td-notas-botao--pequeno', opcoes.botao || 'Gravar' );
		var cancelar = el( 'button', 'td-notas-botao td-notas-botao--pequeno', 'Cancelar' );

		campo.rows = opcoes.linhas || 4;
		campo.maxLength = dados.maxTexto;
		campo.placeholder = rotulo;
		campo.setAttribute( 'aria-label', rotulo );
		erro.hidden = true;
		gravar.type = 'submit';
		cancelar.type = 'button';

		acoes.appendChild( gravar );
		formulario.appendChild( campo );

		// Anexo (opcional).
		var ficheiroEscolhido = null;
		var seletorFicheiro = null;
		var anexar = null;
		var escolhido = el( 'span', 'td-notas-anexo-escolhido' );

		if ( dados.podeAnexar ) {
			var linhaAnexo = el( 'div', 'td-notas-linha-anexo' );
			var nomeEscolhido = el( 'span', 'td-notas-anexo-escolhido__nome' );
			var retirar = el( 'button', 'td-notas-anexo-escolhido__retirar', '×' );

			seletorFicheiro = el( 'input' );
			seletorFicheiro.type = 'file';
			seletorFicheiro.hidden = true;

			anexar = el( 'button', 'td-notas-anexar' );
			anexar.type = 'button';
			anexar.appendChild( el( 'span', 'td-notas-icone-clipe' ) );
			anexar.appendChild( el( 'span', '', 'Anexar ficheiro' ) );

			retirar.type = 'button';
			retirar.title = 'Retirar ficheiro';
			retirar.setAttribute( 'aria-label', 'Retirar ficheiro' );

			escolhido.appendChild( el( 'span', 'td-notas-icone-clipe' ) );
			escolhido.appendChild( nomeEscolhido );
			escolhido.appendChild( retirar );
			escolhido.hidden = true;

			anexar.addEventListener( 'click', function () {
				seletorFicheiro.click();
			} );

			var mostrarFicheiro = function ( ficheiro ) {
				ficheiroEscolhido = ficheiro || null;
				nomeEscolhido.textContent = ficheiroEscolhido ? ficheiroEscolhido.name : '';
				escolhido.hidden = ! ficheiroEscolhido;
				anexar.hidden = !! ficheiroEscolhido;

				if ( opcoes.rascunho ) {
					opcoes.rascunho.ficheiro = ficheiroEscolhido;
				}
			};

			seletorFicheiro.addEventListener( 'change', function () {
				var ficheiro = seletorFicheiro.files && seletorFicheiro.files[ 0 ];

				erro.hidden = true;

				if ( ficheiro && dados.maxUpload && ficheiro.size > dados.maxUpload ) {
					erro.textContent = 'O ficheiro é maior do que o permitido neste site (' + tamanhoLegivel( dados.maxUpload ) + ').';
					erro.hidden = false;
					ficheiro = null;
				}

				seletorFicheiro.value = '';
				mostrarFicheiro( ficheiro );
			} );

			retirar.addEventListener( 'click', function () {
				mostrarFicheiro( null );
			} );

			if ( opcoes.rascunho && opcoes.rascunho.ficheiro ) {
				mostrarFicheiro( opcoes.rascunho.ficheiro );
			}

			linhaAnexo.appendChild( seletorFicheiro );
			linhaAnexo.appendChild( anexar );
			linhaAnexo.appendChild( escolhido );
			formulario.appendChild( linhaAnexo );
		}

		formulario.appendChild( erro );
		formulario.appendChild( acoes );

		if ( opcoes.aoCancelar ) {
			acoes.appendChild( cancelar );
			cancelar.addEventListener( 'click', opcoes.aoCancelar );
		}

		// Repõe e vai guardando o rascunho.
		if ( opcoes.rascunho ) {
			campo.value = opcoes.rascunho.texto;
			campo.addEventListener( 'input', function () {
				opcoes.rascunho.texto = campo.value;
			} );
		}

		// Ctrl+Enter (ou Cmd+Enter) grava.
		campo.addEventListener( 'keydown', function ( evento ) {
			if ( evento.key === 'Enter' && ( evento.ctrlKey || evento.metaKey ) ) {
				evento.preventDefault();
				gravar.click();
			}
		} );

		formulario.addEventListener( 'submit', function ( evento ) {
			evento.preventDefault();

			var texto = campo.value.trim();

			if ( ! texto ) {
				erro.textContent = 'Escreve o texto primeiro.';
				erro.hidden = false;
				campo.focus();
				return;
			}

			var rotuloGravar = gravar.textContent;

			erro.hidden = true;
			gravar.disabled = true;
			cancelar.disabled = true;

			// Primeiro carrega o ficheiro (se houver) e só depois grava a mensagem.
			var envio = ficheiroEscolhido ? enviarFicheiro( ficheiroEscolhido ) : Promise.resolve( 0 );

			if ( ficheiroEscolhido ) {
				gravar.textContent = 'A enviar ficheiro…';
			}

			envio.then( function ( anexoId ) {
				return aoGravar( texto, anexoId );
			} ).catch( function ( falha ) {
				erro.textContent = falha && falha.message ? falha.message : 'Ocorreu um erro. Tenta de novo.';
				erro.hidden = false;
				gravar.textContent = rotuloGravar;
				gravar.disabled = false;
				cancelar.disabled = false;
			} );
		} );

		return { formulario: formulario, campo: campo };
	}

	function abrirFormularioNovaNota() {
		var selecao = estado.selecao;

		if ( ! selecao ) {
			return;
		}

		popover.textContent = '';
		popover.classList.remove( 'td-notas-popover--sem-elemento' );
		popover.appendChild( el( 'p', 'td-notas-popover__titulo', selecao.global ? 'Nova nota (Global: cabeçalho/rodapé)' : 'Nova nota' ) );

		var partes = criarFormulario( {
			rotulo: 'Escreve a nota…',
			aoCancelar: fecharPopover,
			aoGravar: function ( texto, anexoId ) {
				return pedir( 'POST', '', {
					texto: texto,
					anexo: anexoId || 0,
					caminho: dados.caminho,
					post_id: dados.postId,
					seletor: selecao.seletor,
					excerto: selecao.excerto,
					global: selecao.global,
					x: Math.round( selecao.x * 100 ) / 100,
					y: Math.round( selecao.y * 100 ) / 100,
					// Ecrã de quem cria a nota (só aparece no backoffice).
					janela_l: window.innerWidth || 0,
					janela_a: window.innerHeight || 0,
					monitor_l: ( window.screen && window.screen.width ) || 0,
					monitor_a: ( window.screen && window.screen.height ) || 0,
					densidade: Math.round( ( window.devicePixelRatio || 1 ) * 100 ) / 100
				} ).then( function ( nota ) {
					guardarNota( nota );
					fecharPopover();
					atualizar();
				} );
			}
		} );

		popover.appendChild( partes.formulario );
		popover.hidden = false;
		reposicionar();
		partes.campo.focus();
	}

	/* ---------------------------------------------------------------------
	 * Ver uma nota: texto, estado, respostas e apagar
	 * ------------------------------------------------------------------ */

	function abrirNota( nota ) {
		var marcador = marcadores[ nota.id ];
		var elemento = marcador ? marcador.elemento : encontrarElemento( nota.seletor );

		terminarModoNova();

		estado.selecao = {
			elemento: elemento && temTamanho( elemento ) ? elemento : null,
			x: nota.x,
			y: nota.y,
			nota: nota
		};

		desenharNota( nota );
		popover.hidden = false;
		reposicionar();
	}

	/**
	 * Uma mensagem da conversa (a nota ou uma resposta): círculo com a inicial
	 * do autor, o texto e um ícone de calendário. O nome completo e a data
	 * aparecem ao passar o rato. O círculo é azul nas mensagens dos
	 * administradores e cinzento-escuro nas dos restantes utilizadores (cliente).
	 */
	function criarMensagem( item ) {
		var bloco = el( 'div', 'td-notas-mensagem' );
		var nome = String( item.autor || '' ).trim();
		var avatar = el( 'span', 'td-notas-avatar' + ( item.admin ? ' td-notas-avatar--admin' : '' ), ( Array.from( nome )[ 0 ] || '?' ).toUpperCase() );
		var data = el( 'span', 'td-notas-data' );

		avatar.setAttribute( 'data-dica', item.minha ? nome + ' (tu)' : nome );
		avatar.setAttribute( 'aria-label', nome );
		data.setAttribute( 'data-dica', item.data );
		data.setAttribute( 'aria-label', item.data );
		data.setAttribute( 'role', 'img' );

		var corpo = el( 'div', 'td-notas-mensagem__corpo' );

		corpo.appendChild( el( 'p', 'td-notas-texto', item.texto ) );

		// Ficheiro anexado: clipe + nome, abre num separador novo.
		if ( item.anexo && /^https?:\/\//i.test( String( item.anexo.url ) ) ) {
			var ligacao = el( 'a', 'td-notas-anexo' );

			ligacao.href = item.anexo.url;
			ligacao.title = item.anexo.nome; // Nome completo, se for cortado.
			ligacao.target = '_blank';
			ligacao.rel = 'noopener noreferrer';
			ligacao.appendChild( el( 'span', 'td-notas-icone-clipe' ) );
			ligacao.appendChild( el( 'span', 'td-notas-anexo__nome', item.anexo.nome ) );
			corpo.appendChild( ligacao );
		}

		bloco.appendChild( avatar );
		bloco.appendChild( corpo );
		bloco.appendChild( data );

		return bloco;
	}

	function desenharNota( nota ) {
		var cabecalho = el( 'div', 'td-notas-popover__cabecalho' );
		var fechar = el( 'button', 'td-notas-fechar', '×' );
		var seletorEstado = el( 'select', 'td-notas-seletor' );
		var erro = el( 'p', 'td-notas-erro' );
		var rodape = el( 'div', 'td-notas-popover__rodape' );

		popover.textContent = '';

		// Cabeçalho: número, "Global" e fechar.
		var titulo = el( 'span', 'td-notas-titulo' );

		titulo.appendChild( el( 'strong', '', 'Nota ' + nota.numero ) );
		titulo.appendChild( el( 'span', 'td-notas-id', '(ID ' + nota.id + ')' ) );
		cabecalho.appendChild( titulo );

		if ( nota.global ) {
			cabecalho.appendChild( el( 'span', 'td-notas-etiqueta', 'Global' ) );
		}

		if ( avisoDoElemento( nota ) ) {
			cabecalho.appendChild( el( 'span', 'td-notas-aviso', avisoDoElemento( nota ) ) );
		}

		// Sem elemento na página: a caixa fica com fundo cinzento, como na lista.
		popover.classList.toggle( 'td-notas-popover--sem-elemento', !! avisoDoElemento( nota ) );

		fechar.type = 'button';
		fechar.setAttribute( 'aria-label', 'Fechar' );
		fechar.addEventListener( 'click', fecharPopover );
		cabecalho.appendChild( fechar );
		popover.appendChild( cabecalho );

		// A nota e as respostas, por ordem.
		popover.appendChild( criarMensagem( nota ) );

		nota.respostas.forEach( function ( resposta ) {
			popover.appendChild( criarMensagem( resposta ) );
		} );

		// Responder.
		popover.appendChild( criarFormulario( {
			rotulo: 'Escreve uma resposta…',
			botao: 'Responder',
			linhas: 2,
			rascunho: rascunhoDe( nota.id ),
			aoGravar: function ( texto, anexoId ) {
				return pedir( 'POST', '/' + nota.id + '/respostas', { texto: texto, anexo: anexoId || 0 } ).then( function ( atualizada ) {
					delete estado.rascunhos[ nota.id ];
					desenharNota( guardarNota( atualizada ) );
					atualizar();
				} );
			}
		} ).formulario );

		// Estado.
		Object.keys( dados.estados ).forEach( function ( slug ) {
			var opcao = el( 'option', '', dados.estados[ slug ] );

			opcao.value = slug;
			opcao.selected = slug === nota.estado;
			seletorEstado.appendChild( opcao );
		} );

		seletorEstado.setAttribute( 'aria-label', 'Estado da nota' );
		erro.hidden = true;

		seletorEstado.addEventListener( 'change', function () {
			seletorEstado.disabled = true;
			erro.hidden = true;

			pedir( 'POST', '/' + nota.id + '/estado', { estado: seletorEstado.value } ).then( function ( atualizada ) {
				guardarNota( atualizada );

				// Uma nota fechada sai da página (a não ser que as fechadas estejam à vista).
				// Se houver uma resposta por enviar, a caixa fica aberta para não se perder.
				if ( atualizada.estado === 'fechada' && ! estado.mostrarFechadas && ! temRascunho( nota.id ) ) {
					fecharPopover();
				} else {
					desenharNota( atualizada );
				}

				atualizar();
			} ).catch( function ( falha ) {
				seletorEstado.value = nota.estado;
				seletorEstado.disabled = false;
				erro.textContent = falha.message;
				erro.hidden = false;
			} );
		} );

		rodape.appendChild( seletorEstado );

		// Apagar: pede confirmação com um segundo clique.
		if ( nota.pode_apagar ) {
			var apagar = el( 'button', 'td-notas-botao td-notas-botao--pequeno td-notas-botao--perigo', 'Apagar' );
			var confirmar = false;

			apagar.type = 'button';
			apagar.addEventListener( 'click', function () {
				if ( ! confirmar ) {
					confirmar = true;
					apagar.textContent = 'Apagar mesmo?';
					apagar.classList.add( 'td-notas-botao--confirmar' );

					window.setTimeout( function () {
						confirmar = false;
						apagar.textContent = 'Apagar';
						apagar.classList.remove( 'td-notas-botao--confirmar' );
					}, 4000 );

					return;
				}

				apagar.disabled = true;

				pedir( 'DELETE', '/' + nota.id ).then( function () {
					estado.notas = estado.notas.filter( function ( outra ) {
						return outra.id !== nota.id;
					} );
					fecharPopover();
					atualizar();
				} ).catch( function ( falha ) {
					apagar.disabled = false;
					erro.textContent = falha.message;
					erro.hidden = false;
				} );
			} );

			rodape.appendChild( apagar );
		}

		popover.appendChild( rodape );
		popover.appendChild( erro );

		if ( ! popover.hidden ) {
			reposicionar();
		}
	}

	/* ---------------------------------------------------------------------
	 * Painel "Notas desta página"
	 * ------------------------------------------------------------------ */

	/**
	 * Lista todas as notas visíveis, incluindo as que não encontram o elemento
	 * (alterado ou removido) ou cujo elemento está escondido.
	 */
	/**
	 * Linha de contadores por estado. Estados sem notas não aparecem.
	 * Se a linha não couber, os nomes escondem-se e ficam só as bolinhas com o número.
	 */
	function desenharResumo() {
		var contagem = {};

		estado.notas.forEach( function ( nota ) {
			contagem[ nota.estado ] = ( contagem[ nota.estado ] || 0 ) + 1;
		} );

		resumo.textContent = '';
		resumo.classList.remove( 'td-notas-resumo--compacto' );
		resumo.classList.toggle( 'td-notas-resumo--filtrado', !! estado.filtroEstado );
		resumo.hidden = ! estado.notas.length;

		Object.keys( dados.estados ).forEach( function ( slug ) {
			if ( ! contagem[ slug ] ) {
				return;
			}

			var botao = el( 'button', 'td-notas-resumo__item' );
			var ativo = estado.filtroEstado === slug;

			botao.type = 'button';
			botao.title = dados.estados[ slug ] + ( ativo ? ' · clica para ver todas' : ' · clica para ver só estas' );
			botao.setAttribute( 'aria-pressed', ativo ? 'true' : 'false' );
			botao.classList.toggle( 'td-notas-resumo__item--ativo', ativo );

			botao.appendChild( el( 'span', 'td-notas-resumo__numero td-notas-marcador--' + slug, contagem[ slug ] ) );
			botao.appendChild( el( 'span', 'td-notas-resumo__nome', NOMES_CURTOS[ slug ] || dados.estados[ slug ] ) );

			botao.addEventListener( 'click', function () {
				estado.filtroEstado = estado.filtroEstado === slug ? '' : slug;
				atualizar();
			} );

			resumo.appendChild( botao );
		} );

		// Com um filtro ativo: cruz para limpar, no fim da linha.
		if ( estado.filtroEstado ) {
			var limpar = el( 'button', 'td-notas-resumo__limpar', '×' );

			limpar.type = 'button';
			limpar.title = 'Limpar filtro';
			limpar.setAttribute( 'aria-label', 'Limpar filtro' );
			limpar.addEventListener( 'click', function () {
				estado.filtroEstado = '';
				atualizar();
			} );

			resumo.appendChild( limpar );
		}

		// Garante uma só linha: primeiro esconde os nomes dos estados não escolhidos;
		// se ainda não couber, esconde todos os nomes.
		resumo.classList.remove( 'td-notas-resumo--compacto-parcial' );

		if ( ! painelCorpo.hidden && resumo.scrollWidth > resumo.clientWidth && estado.filtroEstado ) {
			resumo.classList.add( 'td-notas-resumo--compacto-parcial' );
		}

		if ( ! painelCorpo.hidden && resumo.scrollWidth > resumo.clientWidth ) {
			resumo.classList.add( 'td-notas-resumo--compacto' );
		}
	}

	function desenharPainel() {
		var notas = notasVisiveis();
		var fechadas = estado.notas.filter( function ( nota ) {
			return nota.estado === 'fechada';
		} ).length;

		painel.hidden = ! estado.visivel;
		painelCorpo.hidden = ! estado.painelAberto;
		painelCabecalho.setAttribute( 'aria-expanded', estado.painelAberto ? 'true' : 'false' );
		painelTitulo.textContent = 'Notas desta página (' + notas.length + ')';
		painelSeta.textContent = estado.painelAberto ? '–' : '+';
		caixaFechadas.checked = estado.mostrarFechadas;
		opcaoFechadas.hidden = !! estado.filtroEstado; // Com um estado escolhido, o interruptor não se aplica.
		desenharResumo();
		rotuloFechadas.textContent = 'Mostrar fechadas (' + fechadas + ')';

		lista.textContent = '';

		if ( estado.filtroEstado ) {
			painelTitulo.textContent = 'Notas desta página: ' + nomeDoEstado( estado.filtroEstado ) + ' (' + notas.length + ')';
		}

		if ( ! notas.length ) {
			lista.appendChild( el( 'p', 'td-notas-lista__vazia', 'Não há notas para mostrar nesta página.' ) );
			return;
		}

		notas.forEach( function ( nota ) {
			var item = el( 'button', 'td-notas-item' );
			var corpo = el( 'span', 'td-notas-item__corpo' );
			var etiquetas = el( 'span', 'td-notas-item__etiquetas' );
			var id = nota.id;

			item.type = 'button';
			item.appendChild( el( 'span', 'td-notas-item__numero td-notas-marcador--' + ( dados.estados[ nota.estado ] ? nota.estado : 'aberta' ), nota.numero ) );
			var linhaTexto = el( 'span', 'td-notas-item__linha' );

			linhaTexto.appendChild( el( 'span', 'td-notas-item__texto', nota.texto ) );

			if ( nota.tem_anexos ) {
				var clipe = el( 'span', 'td-notas-icone-clipe td-notas-item__clipe' );

				clipe.title = 'Tem anexos';
				clipe.setAttribute( 'aria-label', 'Tem anexos' );
				clipe.setAttribute( 'role', 'img' );
				linhaTexto.appendChild( clipe );
			}

			corpo.appendChild( linhaTexto );

			etiquetas.appendChild( el( 'span', '', nomeDoEstado( nota.estado ) ) );

			if ( nota.global ) {
				etiquetas.appendChild( el( 'span', '', 'Global' ) );
			}

			if ( nota.respostas.length ) {
				etiquetas.appendChild( el( 'span', '', nota.respostas.length === 1 ? '1 resposta' : nota.respostas.length + ' respostas' ) );
			}

			// Quem falou por último: inicial com a cor da conversa + primeiro nome.
			if ( nota.ultimo ) {
				var ultimo = el( 'span', 'td-notas-ultimo' );
				var nomeUltimo = String( nota.ultimo.nome || '' );

				ultimo.title = 'Última mensagem: ' + nota.ultimo.nome_completo + ' · ' + nota.ultimo.data;
				ultimo.appendChild( el( 'span', 'td-notas-avatar td-notas-avatar--mini' + ( nota.ultimo.admin ? ' td-notas-avatar--admin' : '' ), ( Array.from( nomeUltimo )[ 0 ] || '?' ).toUpperCase() ) );
				ultimo.appendChild( el( 'span', 'td-notas-ultimo__nome', nomeUltimo ) );
				etiquetas.appendChild( ultimo );
			}

			if ( avisoDoElemento( nota ) ) {
				etiquetas.appendChild( el( 'span', 'td-notas-aviso', avisoDoElemento( nota ) ) );
				item.classList.add( 'td-notas-item--sem-elemento' );
			}

			corpo.appendChild( etiquetas );
			item.appendChild( corpo );

			item.addEventListener( 'click', function () {
				var atual = notaPorId( id );

				if ( atual ) {
					irParaNota( atual );
				}
			} );

			lista.appendChild( item );
		} );
	}

	/**
	 * Faz scroll até ao ponto da nota e abre-a. Se o elemento não existir ou
	 * estiver escondido, abre a nota ao centro do ecrã.
	 */
	function irParaNota( nota ) {
		var marcador = marcadores[ nota.id ];
		var elemento = marcador ? marcador.elemento : null;

		if ( elemento && elemento.isConnected && temTamanho( elemento ) ) {
			var caixa = elemento.getBoundingClientRect();
			var suave = ! window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches;

			window.scrollTo( {
				top: Math.max( 0, window.pageYOffset + caixa.top + caixa.height * nota.y / 100 - window.innerHeight / 2 ),
				behavior: suave ? 'smooth' : 'auto'
			} );
		}

		abrirNota( nota );
	}

	/**
	 * Vindo do backoffice com ?td_notas=ID: mostra a nota, faz scroll até ela
	 * e destaca o ícone durante uns segundos. Só acontece uma vez.
	 */
	var destaqueFeito = false;

	function destacarNotaDoEndereco() {
		if ( destaqueFeito || ! dados.notaDestaque || ! ecraLargo.matches ) {
			return;
		}

		destaqueFeito = true;

		var nota = notaPorId( dados.notaDestaque );

		if ( ! nota ) {
			return; // Apagada, ou de outra página.
		}

		if ( nota.estado === 'fechada' ) {
			estado.mostrarFechadas = true;
		}

		estado.destaqueId = nota.id;
		atualizar();
		irParaNota( nota );

		window.setTimeout( function () {
			estado.destaqueId = 0;
			sincronizarMarcadores();
		}, 5000 );
	}

	/**
	 * Diz ao servidor que notas desta página deixaram de encontrar o elemento
	 * (ou voltaram a encontrá-lo), para o backoffice as poder assinalar.
	 * Só envia quando o resultado é diferente do que o servidor já sabe.
	 * Corre uma vez por página, depois de tudo carregar.
	 */
	var verificacaoFeita = false;

	function verificarElementos() {
		var pedidos = 0;

		if ( verificacaoFeita || ! ecraLargo.matches ) {
			return;
		}

		verificacaoFeita = true;

		estado.notas.forEach( function ( nota ) {
			// As notas globais só são verificadas na página onde foram criadas.
			if ( nota.caminho !== dados.caminho || pedidos >= 20 ) {
				return;
			}

			var emFalta = ! encontrarElemento( nota.seletor );

			if ( emFalta === !! nota.em_falta ) {
				return;
			}

			pedidos++;
			nota.em_falta = emFalta;
			pedir( 'POST', '/' + nota.id + '/elemento', { em_falta: emFalta } ).catch( function () {
				// Não é essencial: tenta de novo na próxima visita à página.
			} );
		} );
	}

	/* ---------------------------------------------------------------------
	 * Ícones das notas sobre os elementos
	 * ------------------------------------------------------------------ */

	/**
	 * Aviso a mostrar quando a nota não tem onde aparecer na página:
	 * o elemento foi alterado ou removido, ou existe mas está escondido.
	 * Devolve '' quando está tudo bem.
	 */
	function avisoDoElemento( nota ) {
		var marcador = marcadores[ nota.id ];
		var elemento = marcador ? marcador.elemento : encontrarElemento( nota.seletor );

		if ( ! elemento || ! elemento.isConnected ) {
			return 'Elemento não encontrado';
		}

		return temTamanho( elemento ) ? '' : 'Elemento escondido';
	}

	function temTamanho( elemento ) {
		var caixa = elemento.getBoundingClientRect();

		return caixa.width > 0 || caixa.height > 0;
	}

	/**
	 * Cria, atualiza e remove os ícones para corresponderem às notas visíveis.
	 * Uma nota cujo elemento não exista fica sem ícone (aparece só no painel).
	 */
	function sincronizarMarcadores() {
		var presentes = {};

		notasVisiveis().forEach( function ( nota ) {
			var marcador = marcadores[ nota.id ];
			var id = nota.id;

			presentes[ id ] = true;

			if ( ! marcador ) {
				marcador = marcadores[ id ] = { botao: el( 'button' ), elemento: null };
				marcador.botao.type = 'button';
				marcador.botao.hidden = true;
				marcador.botao.addEventListener( 'click', function () {
					var atual = notaPorId( id );

					if ( ! atual ) {
						return;
					}

					// Segundo clique no mesmo ícone fecha a caixa.
					if ( ! popover.hidden && estado.selecao && estado.selecao.nota && estado.selecao.nota.id === id ) {
						fecharPopover();
					} else {
						abrirNota( atual );
					}
				} );
				camada.appendChild( marcador.botao );
			}

			marcador.botao.className = 'td-notas-marcador td-notas-marcador--' + ( dados.estados[ nota.estado ] ? nota.estado : 'aberta' ) +
				( estado.destaqueId === id ? ' td-notas-marcador--destaque' : '' );
			marcador.botao.textContent = String( nota.numero );
			marcador.botao.title = 'Nota ' + nota.numero + ' (ID ' + nota.id + ') · ' + nomeDoEstado( nota.estado );

			if ( ! marcador.elemento || ! marcador.elemento.isConnected ) {
				marcador.elemento = encontrarElemento( nota.seletor );
			}
		} );

		Object.keys( marcadores ).forEach( function ( id ) {
			if ( ! presentes[ id ] ) {
				camada.removeChild( marcadores[ id ].botao );
				delete marcadores[ id ];
			}
		} );

		desenharPainel();
		agendarReposicao();
	}

	// A página muda depois de carregar (imagens, separadores, acordeões, sliders):
	// volta a procurar os elementos e a alinhar os ícones.
	var sincronizacaoPendente = 0;

	function agendarSincronizacao() {
		if ( ! estado.visivel ) {
			return;
		}

		window.clearTimeout( sincronizacaoPendente );
		sincronizacaoPendente = window.setTimeout( sincronizarMarcadores, 150 );
	}

	if ( window.ResizeObserver ) {
		new window.ResizeObserver( agendarSincronizacao ).observe( document.body );
	}

	window.addEventListener( 'load', agendarSincronizacao );

	window.addEventListener( 'click', function ( evento ) {
		if ( evento.target === anfitriao ) {
			return;
		}

		// Um clique na página pode abrir um separador ou acordeão: realinha a seguir.
		window.setTimeout( agendarSincronizacao, 300 );

		// Clicar fora fecha a nota aberta, a não ser que haja uma resposta por enviar.
		if ( ! popover.hidden && estado.selecao && estado.selecao.nota ) {
			var campo = popover.querySelector( 'textarea' );

			if ( ! campo || ! campo.value.trim() ) {
				fecharPopover();
			}
		}
	}, true );

	/* ---------------------------------------------------------------------
	 * Posição do contorno, dos ícones e da caixa (acompanham o scroll e o redimensionar)
	 * ------------------------------------------------------------------ */

	var reposicaoPendente = false;

	function agendarReposicao() {
		if ( ! reposicaoPendente ) {
			reposicaoPendente = true;
			window.requestAnimationFrame( reposicionar );
		}
	}

	function reposicionar() {
		reposicaoPendente = false;

		var alvo = estado.selecao ? estado.selecao.elemento : estado.hover;

		if ( alvo && document.documentElement.contains( alvo ) ) {
			var caixa = alvo.getBoundingClientRect();

			contorno.style.left = caixa.left + 'px';
			contorno.style.top = caixa.top + 'px';
			contorno.style.width = caixa.width + 'px';
			contorno.style.height = caixa.height + 'px';
			contorno.hidden = false;
		} else {
			contorno.hidden = true;
		}

		Object.keys( marcadores ).forEach( function ( id ) {
			var marcador = marcadores[ id ];
			var nota = notaPorId( Number( id ) );
			var elemento = marcador.elemento;
			var limites = elemento && elemento.isConnected ? elemento.getBoundingClientRect() : null;

			// Sem elemento, ou elemento escondido (separador fechado, por exemplo): sem ícone.
			if ( ! nota || ! limites || ( ! limites.width && ! limites.height ) ) {
				marcador.botao.hidden = true;
				return;
			}

			marcador.botao.style.left = ( limites.left + limites.width * nota.x / 100 ) + 'px';
			marcador.botao.style.top = ( limites.top + limites.height * nota.y / 100 ) + 'px';
			marcador.botao.hidden = false;
		} );

		if ( estado.selecao && ! popover.hidden ) {
			posicionarPopover( estado.selecao );
		}
	}

	/**
	 * Põe a caixa junto ao ponto clicado, sem sair da janela.
	 */
	function posicionarPopover( selecao ) {
		var largura = popover.offsetWidth;
		var altura = popover.offsetHeight;
		var janelaL = document.documentElement.clientWidth;
		var janelaA = window.innerHeight;
		var margem = 8;
		var folga = 14;

		// Nota sem elemento na página: a caixa abre ao centro.
		if ( ! selecao.elemento || ! selecao.elemento.isConnected ) {
			popover.style.left = Math.max( margem, ( janelaL - largura ) / 2 ) + 'px';
			popover.style.top = Math.max( margem, ( janelaA - altura ) / 2 ) + 'px';
			return;
		}

		var caixa = selecao.elemento.getBoundingClientRect();
		var pontoX = caixa.left + caixa.width * selecao.x / 100;
		var pontoY = caixa.top + caixa.height * selecao.y / 100;

		var esquerda = pontoX + folga;
		var topo = pontoY + folga;

		if ( esquerda + largura > janelaL - margem ) {
			esquerda = pontoX - largura - folga;
		}

		if ( topo + altura > janelaA - margem ) {
			topo = pontoY - altura - folga;
		}

		popover.style.left = Math.max( margem, Math.min( esquerda, janelaL - largura - margem ) ) + 'px';
		popover.style.top = Math.max( margem, Math.min( topo, janelaA - altura - margem ) ) + 'px';
	}

	// "capture: true" apanha também o scroll de zonas interiores da página.
	window.addEventListener( 'scroll', agendarReposicao, { passive: true, capture: true } );
	window.addEventListener( 'resize', agendarReposicao, { passive: true } );

	// Esc: cancela o modo "+ Nova nota" ou fecha a caixa aberta.
	window.addEventListener( 'keydown', function ( evento ) {
		if ( evento.key !== 'Escape' || ( ! estado.modoNova && popover.hidden ) ) {
			return;
		}

		evento.preventDefault();
		evento.stopPropagation();
		terminarModoNova();
		fecharPopover();
	}, true );

	/* ---------------------------------------------------------------------
	 * Atualização da interface
	 * ------------------------------------------------------------------ */

	function atualizar() {
		// Só desktop: abaixo da largura mínima não aparece nada.
		if ( ! ecraLargo.matches ) {
			anfitriao.style.display = 'none';
			terminarModoNova();
			fecharPopover();
			return;
		}

		anfitriao.style.display = '';

		// Com as notas ocultas, não há modo "+ Nova nota" nem caixas abertas.
		if ( ! estado.visivel && ( estado.modoNova || ! popover.hidden ) ) {
			terminarModoNova();
			fecharPopover();
		}

		var total = notasPorResolver().length;

		rotuloPrincipal.textContent = estado.visivel ? 'Ocultar notas' : 'Mostrar notas';
		botaoPrincipal.setAttribute( 'aria-pressed', estado.visivel ? 'true' : 'false' );
		contador.textContent = String( total );
		contador.hidden = total === 0;
		raiz.classList.toggle( 'td-notas-raiz--visivel', estado.visivel );

		botaoNova.hidden = ! estado.visivel;
		botaoNova.textContent = estado.modoNova ? 'Cancelar' : '+ Nova nota';
		dica.hidden = ! estado.modoNova;

		// O filtro desliga-se sozinho quando esse estado deixa de ter notas.
		if ( estado.filtroEstado && ! estado.notas.some( function ( nota ) {
			return nota.estado === estado.filtroEstado;
		} ) ) {
			estado.filtroEstado = '';
		}

		// Se a nota aberta deixou de estar à vista (fechada, apagada), fecha a caixa.
		if ( estado.selecao && estado.selecao.nota && notasVisiveis().indexOf( notaPorId( estado.selecao.nota.id ) ) === -1 && ! temRascunho( estado.selecao.nota.id ) ) {
			fecharPopover();
		}

		sincronizarMarcadores();
	}

	function carregarNotas() {
		if ( estado.carregadas || ! ecraLargo.matches ) {
			return;
		}

		estado.carregadas = true;

		pedir( 'GET', '', { caminho: dados.caminho } ).then( function ( notas ) {
			estado.notas = Array.isArray( notas ) ? notas : [];
			estado.notas.forEach( function ( nota, indice ) {
				nota.numero = indice + 1;
			} );
			atualizar();

			// Espera a página acabar de carregar, para os elementos estarem no sítio final.
			if ( document.readyState === 'complete' ) {
				aposCarregarPagina();
			} else {
				window.addEventListener( 'load', aposCarregarPagina );
			}
		} ).catch( function () {
			estado.carregadas = false; // Tenta de novo na próxima oportunidade.
		} );
	}

	function aposCarregarPagina() {
		destacarNotaDoEndereco();
		window.setTimeout( verificarElementos, 2500 );
	}

	function aoMudarLargura() {
		atualizar();
		carregarNotas();
	}

	if ( ecraLargo.addEventListener ) {
		ecraLargo.addEventListener( 'change', aoMudarLargura );
	} else if ( ecraLargo.addListener ) {
		ecraLargo.addListener( aoMudarLargura ); // Safari antigo.
	}

	atualizar();
	carregarNotas();
} )();
