<?php
/**
 * Plugin Name:       TD Notas
 * Description:       Notas visuais sobre os elementos das páginas, para rondas de correções em sites em desenvolvimento.
 * Version:           1.7.0
 * Author:            Tecla Digital
 * Author URI:        https://tecladigital.pt
 * Update URI:        https://github.com/tdpt/TD-notas
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Text Domain:       td-notas
 */

/*
 * Histórico de versões
 *
 * 1.7.0 (18-11-2026)
 * - Atualizações automáticas a partir do GitHub (github.com/tdpt/TD-notas, etiquetas vX.Y.Z):
 *   o WordPress mostra as versões novas em Plugins e Atualizações, como nos outros plugins.
 *
 * 1.6.0 (18-11-2026)
 * - O estado "Em revisão" passa a chamar-se "Para a TD rever" (o slug e as regras automáticas mantêm-se).
 * - Cada nota guarda o ecrã de quem a criou (janela, monitor e densidade), visível só no ecrã
 *   da nota no backoffice.
 *
 * 1.5.0 (17-11-2026)
 * - Quem falou por último em cada nota (círculo azul/cinzento-escuro + primeiro nome), no painel
 *   "Notas desta página" e na coluna Respostas do backoffice. Nome completo e data ao passar o rato.
 *
 * 1.4.2 (17-11-2026)
 * - Correção: mudar o estado de uma nota já não apaga a resposta que estava a ser escrita
 *   (nem o ficheiro escolhido). O rascunho fica guardado enquanto a página estiver aberta.
 *
 * 1.4.1 (16-11-2026)
 * - Backoffice: quem tem acesso às notas pode mudar o estado de várias notas de uma vez.
 *   Apagar em massa continua só para administradores.
 *
 * 1.4.0 (16-11-2026)
 * - Novo estado "Em revisão", depois de "Para verificar": quando quem não é administrador
 *   responde a uma nota (exceto se ainda estiver Aberta), ela passa automaticamente a "Em revisão".
 * - Barra de administração: o número vermelho soma Abertas + Em revisão (administradores) ou
 *   Para verificar + Em revisão (restantes); o widget mostra as mais recentes desses estados.
 *
 * 1.3.0 (16-11-2026)
 * - Anexos: um ficheiro por nota ou resposta, carregado pela API de Multimédia do WordPress
 *   (só para quem pode carregar ficheiros). Notas com anexos assinaladas com um clipe no painel
 *   e no backoffice.
 *
 * 1.2.0 (16-11-2026)
 * - Backoffice das notas aberto a quem tem acesso às notas: consultar e mudar o estado de
 *   qualquer nota; apagar só as próprias. Ações em massa só para administradores.
 * - Barra de administração: item "TD Notas" com o número de notas à espera (Abertas para os
 *   administradores, Para verificar para os restantes) e menu com todos os estados.
 * - Widget do Dashboard visível a quem tem acesso às notas.
 * - WooCommerce: quem tem acesso às notas pode entrar no backoffice.
 *
 * 1.1.2 (16-11-2026)
 * - Conversa da nota: mensagens dos administradores com círculo azul, as dos restantes utilizadores com cinzento-escuro.
 *
 * 1.1.1 (16-11-2026)
 * - Backoffice: o filtro por estado mostra a quantidade de notas em cada estado.
 *
 * 1.1.0 (16-11-2026)
 * - ID da nota na caixa da nota, na dica dos pinos e no backoffice (coluna ID ordenável).
 * - Painel: resumo por estado numa linha, com filtro ao clicar e cruz para limpar.
 * - Barra de botões no canto inferior esquerdo (não colide com o Pencilino).
 * - Avisos "só em computador" no perfil, na lista de notas e no widget do Dashboard.
 *
 * 1.0.0 (12-11-2026)
 * - Primeira versão.
 */

defined( 'ABSPATH' ) || exit;

const TD_NOTAS_VERSAO    = '1.7.0';
const TD_NOTAS_CAP       = 'td_notas';            // Capacidade para usar as notas no site.
const TD_NOTAS_CAP_ADMIN = 'manage_options';      // Capacidade para gerir as notas no backoffice.
const TD_NOTAS_CPT       = 'td_nota';
const TD_NOTAS_TAX       = 'td_nota_estado';
const TD_NOTAS_META_USER = 'td_notas_pode_usar';  // Caixa "Pode usar TD Notas" no perfil.

// Meta de cada nota (protegida: começa por "_", não aparece em campos personalizados nem na REST).
const TD_NOTAS_META_CAMINHO  = '_td_notas_caminho';   // Caminho da página, relativo à raiz do site. Ex.: /servicos/
const TD_NOTAS_META_POST     = '_td_notas_post_id';   // ID do conteúdo, se existir.
const TD_NOTAS_META_SELETOR  = '_td_notas_seletor';   // Seletor CSS do elemento.
const TD_NOTAS_META_EXCERTO  = '_td_notas_excerto';   // Início do texto do elemento.
const TD_NOTAS_META_GLOBAL   = '_td_notas_global';    // "1" se estiver no cabeçalho ou no rodapé.
const TD_NOTAS_META_X        = '_td_notas_x';         // Posição do clique dentro do elemento (%).
const TD_NOTAS_META_Y        = '_td_notas_y';
const TD_NOTAS_META_RESPOSTA = '_td_notas_resposta';  // Uma linha por resposta: autor, data, texto.
const TD_NOTAS_META_ANEXO    = '_td_notas_anexo';     // ID do ficheiro anexado à nota (Biblioteca de Multimédia).
const TD_NOTAS_META_EM_FALTA = '_td_notas_em_falta';
const TD_NOTAS_META_ECRA     = '_td_notas_ecra';     // Ecrã de quem criou a nota: janela, monitor e densidade.  // "1" se o elemento não foi encontrado na última visita à página.

/* -------------------------------------------------------------------------
 * 1. DADOS: tipo de conteúdo, estados
 * ---------------------------------------------------------------------- */

/**
 * Os 4 estados possíveis de uma nota (slug => nome).
 */
function td_notas_estados() {
	return array(
		'aberta'         => 'Aberta',
		'em-curso'       => 'Em curso',
		'para-verificar' => 'Para verificar',
		'em-revisao'     => 'Para a TD rever', // O cliente respondeu depois de verificar: a Tecla Digital tem de rever outra vez.
		'fechada'        => 'Fechada',
	);
}

/**
 * Regista o tipo de conteúdo e a taxonomia. Nada disto é público:
 * sem página no site, sem pesquisa, sem REST nativa, sem feeds nem sitemaps.
 */
function td_notas_registar_dados() {
	register_post_type(
		TD_NOTAS_CPT,
		array(
			'labels'              => array(
				'name'               => 'Notas',
				'singular_name'      => 'Nota',
				'menu_name'          => 'TD Notas',
				'all_items'          => 'Todas as notas',
				'edit_item'          => 'Nota',
				'search_items'       => 'Procurar notas',
				'not_found'          => 'Nenhuma nota encontrada.',
				'not_found_in_trash' => 'Nenhuma nota no lixo.',
			),
			'public'              => false,
			'publicly_queryable'  => false,
			'exclude_from_search' => true,
			'show_ui'             => true,
			'show_in_menu'        => true,
			'show_in_nav_menus'   => false,
			'show_in_admin_bar'   => false,
			'show_in_rest'        => false,
			'has_archive'         => false,
			'rewrite'             => false,
			'query_var'           => false,
			'delete_with_user'    => false,
			'menu_icon'           => 'dashicons-format-status',
			'menu_position'       => 80,
			'supports'            => false, // O ecrã da nota só mostra as nossas caixas.
			// Backoffice para quem tem acesso às notas, com as mesmas regras do site:
			// ver e mudar o estado de qualquer nota; apagar só as próprias (os
			// administradores apagam todas). As notas criam-se apenas no site.
			// O apagar por nota é decidido em td_notas_mapear_capacidades().
			'map_meta_cap'        => false,
			'capabilities'        => array(
				'edit_post'           => TD_NOTAS_CAP,
				'read_post'           => TD_NOTAS_CAP,
				'delete_post'         => TD_NOTAS_CAP,
				'edit_posts'          => TD_NOTAS_CAP,
				'edit_others_posts'   => TD_NOTAS_CAP,
				'delete_posts'        => TD_NOTAS_CAP,
				'delete_others_posts' => TD_NOTAS_CAP_ADMIN,
				'publish_posts'       => TD_NOTAS_CAP_ADMIN,
				'read_private_posts'  => TD_NOTAS_CAP_ADMIN,
				'create_posts'        => 'do_not_allow',
			),
		)
	);

	register_taxonomy(
		TD_NOTAS_TAX,
		TD_NOTAS_CPT,
		array(
			'labels'             => array(
				'name'          => 'Estados',
				'singular_name' => 'Estado',
				'all_items'     => 'Todos os estados',
			),
			'public'             => false,
			'publicly_queryable' => false,
			'show_ui'            => true,
			'show_in_menu'       => false, // Os estados são fixos: não se editam.
			'show_in_nav_menus'  => false,
			'show_in_rest'       => false,
			'show_tagcloud'      => false,
			'show_in_quick_edit' => false,
			'show_admin_column'  => true,
			'meta_box_cb'        => false,
			'hierarchical'       => false,
			'rewrite'            => false,
			'capabilities'       => array(
				'manage_terms' => TD_NOTAS_CAP_ADMIN,
				'edit_terms'   => TD_NOTAS_CAP_ADMIN,
				'delete_terms' => TD_NOTAS_CAP_ADMIN,
				'assign_terms' => TD_NOTAS_CAP_ADMIN,
			),
		)
	);

	td_notas_instalar();
}
add_action( 'init', 'td_notas_registar_dados' );

/**
 * Cria os 4 estados na primeira execução e em cada atualização de versão.
 * Custa uma leitura de opção (em memória) por pedido.
 */
function td_notas_instalar() {
	if ( TD_NOTAS_VERSAO === get_option( 'td_notas_versao' ) ) {
		return;
	}

	// Cria os estados que faltam e atualiza o nome dos existentes (o slug nunca muda,
	// por isso as notas e as regras automáticas não são afetadas por uma mudança de nome).
	foreach ( td_notas_estados() as $slug => $nome ) {
		$termo = get_term_by( 'slug', $slug, TD_NOTAS_TAX );

		if ( ! $termo ) {
			wp_insert_term( $nome, TD_NOTAS_TAX, array( 'slug' => $slug ) );
		} elseif ( $nome !== $termo->name ) {
			wp_update_term( $termo->term_id, TD_NOTAS_TAX, array( 'name' => $nome ) );
		}
	}

	update_option( 'td_notas_versao', TD_NOTAS_VERSAO );
}

/* -------------------------------------------------------------------------
 * 2. PERMISSÕES
 * ---------------------------------------------------------------------- */

/**
 * Atribui a capacidade "td_notas" sem escrever nada nas funções do WordPress:
 * - aos administradores, automaticamente;
 * - a qualquer outro utilizador (Editor, Gestor de loja, Subscritor...),
 *   só se tiver a caixa "Pode usar TD Notas" marcada no perfil.
 */
function td_notas_filtrar_capacidade( $allcaps, $caps, $args, $user ) {
	if ( ! in_array( TD_NOTAS_CAP, (array) $caps, true ) ) {
		return $allcaps;
	}

	if ( ! ( $user instanceof WP_User ) || ! $user->exists() ) {
		return $allcaps;
	}

	if ( is_multisite() && ! is_user_member_of_blog( $user->ID ) ) {
		return $allcaps;
	}

	$automaticas = apply_filters( 'td_notas_capacidades_automaticas', array( TD_NOTAS_CAP_ADMIN ) );

	foreach ( (array) $automaticas as $cap ) {
		if ( ! empty( $allcaps[ $cap ] ) ) {
			$allcaps[ TD_NOTAS_CAP ] = true;
			return $allcaps;
		}
	}

	if ( '1' === get_user_meta( $user->ID, TD_NOTAS_META_USER, true ) ) {
		$allcaps[ TD_NOTAS_CAP ] = true;
	}

	return $allcaps;
}
add_filter( 'user_has_cap', 'td_notas_filtrar_capacidade', 10, 4 );

/**
 * A verificação única, usada no carregamento dos ficheiros e em toda a REST.
 */
function td_notas_pode_usar() {
	return is_user_logged_in() && current_user_can( TD_NOTAS_CAP );
}

/**
 * Apagar (e restaurar) uma nota no backoffice: só o autor ou um administrador.
 * É a mesma regra da REST, para o site e o backoffice se comportarem igual.
 */
function td_notas_mapear_capacidades( $caps, $cap, $user_id, $args ) {
	if ( 'delete_post' !== $cap || empty( $args[0] ) ) {
		return $caps;
	}

	$nota = get_post( (int) $args[0] );

	if ( ! $nota || TD_NOTAS_CPT !== $nota->post_type ) {
		return $caps;
	}

	if ( user_can( $user_id, TD_NOTAS_CAP_ADMIN ) ) {
		return array( TD_NOTAS_CAP_ADMIN );
	}

	return (int) $nota->post_author === (int) $user_id ? array( TD_NOTAS_CAP ) : array( 'do_not_allow' );
}
add_filter( 'map_meta_cap', 'td_notas_mapear_capacidades', 10, 4 );

/**
 * Proteção do ecrã da nota no backoffice: ao gravar, só o estado pode mudar.
 * Texto, autor, data, estado de publicação e o resto ficam sempre como estavam,
 * mesmo que alguém altere o formulário à mão.
 */
function td_notas_proteger_gravacao( $dados, $postarr ) {
	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- O WordPress já verificou o nonce do formulário.
	$acao = isset( $_POST['action'] ) ? sanitize_key( wp_unslash( $_POST['action'] ) ) : '';

	if ( TD_NOTAS_CPT !== $dados['post_type'] || empty( $postarr['ID'] ) || 'editpost' !== $acao ) {
		return $dados;
	}

	$original = get_post( (int) $postarr['ID'] );

	if ( ! $original ) {
		return $dados;
	}

	foreach ( array( 'post_title', 'post_content', 'post_excerpt', 'post_status', 'post_author', 'post_date', 'post_date_gmt', 'post_password', 'post_name', 'post_parent', 'menu_order', 'comment_status', 'ping_status' ) as $campo ) {
		$dados[ $campo ] = $original->$campo;
	}

	return $dados;
}
add_filter( 'wp_insert_post_data', 'td_notas_proteger_gravacao', 99, 2 );

/**
 * WooCommerce: deixa entrar no backoffice quem tem acesso às notas
 * (por defeito, a loja manda os Subscritores para "A minha conta").
 */
function td_notas_acesso_backoffice_woocommerce( $bloquear ) {
	return td_notas_pode_usar() ? false : $bloquear;
}
add_filter( 'woocommerce_prevent_admin_access', 'td_notas_acesso_backoffice_woocommerce' );

/**
 * Os estados que cada pessoa acompanha (número vermelho na barra, widget):
 * - administradores (equipa): Abertas e Em revisão;
 * - restantes utilizadores (cliente): Para verificar e Em revisão.
 */
function td_notas_estados_prioritarios() {
	$estados = current_user_can( TD_NOTAS_CAP_ADMIN )
		? array( 'aberta', 'em-revisao' )          // Equipa: notas novas e notas a que o cliente voltou a responder.
		: array( 'para-verificar', 'em-revisao' ); // Cliente: notas a confirmar e notas que voltaram para revisão.

	return array_values( array_intersect( (array) apply_filters( 'td_notas_estados_prioritarios', $estados ), array_keys( td_notas_estados() ) ) );
}

/**
 * Número de notas ativas (fora do lixo) em cada estado.
 *
 * @return int[] slug => número
 */
function td_notas_contagens() {
	$contagens = array();

	foreach ( array_keys( td_notas_estados() ) as $slug ) {
		$termo              = get_term_by( 'slug', $slug, TD_NOTAS_TAX );
		$contagens[ $slug ] = $termo ? (int) $termo->count : 0;
	}

	return $contagens;
}

/* -------------------------------------------------------------------------
 * 3. CAIXA "PODE USAR TD NOTAS" NO PERFIL DO UTILIZADOR
 * ---------------------------------------------------------------------- */

/**
 * Mostra a caixa no perfil e no ecrã "Adicionar utilizador".
 * Só os administradores a veem; ninguém a pode marcar para si próprio
 * sem ser administrador.
 *
 * @param WP_User|string $contexto Utilizador a editar, ou texto no ecrã de novo utilizador.
 */
function td_notas_campo_perfil( $contexto ) {
	if ( ! current_user_can( TD_NOTAS_CAP_ADMIN ) ) {
		return;
	}

	$user       = ( $contexto instanceof WP_User ) ? $contexto : null;
	$marcado    = $user && '1' === get_user_meta( $user->ID, TD_NOTAS_META_USER, true );
	$automatico = $user && ! $marcado && user_can( $user, TD_NOTAS_CAP );

	wp_nonce_field( 'td_notas_perfil', 'td_notas_perfil_nonce', false );
	?>
	<h2>TD Notas</h2>
	<table class="form-table" role="presentation">
		<tr>
			<th scope="row">Notas visuais</th>
			<td>
				<label>
					<input type="checkbox" name="td_notas_pode_usar" value="1"
						<?php checked( $marcado || $automatico ); ?>
						<?php disabled( $automatico ); ?> />
					Pode usar TD Notas
				</label>
				<p class="description">
					<?php if ( $automatico ) : ?>
						Os administradores têm sempre acesso às notas.
					<?php else : ?>
						Permite criar, ver e responder a notas no site, sem dar permissão para editar conteúdos.
					<?php endif; ?>
					<br>Funciona só em computador (ecrãs com 1024 px ou mais de largura).
				</p>
			</td>
		</tr>
	</table>
	<?php
}
add_action( 'show_user_profile', 'td_notas_campo_perfil' );
add_action( 'edit_user_profile', 'td_notas_campo_perfil' );
add_action( 'user_new_form', 'td_notas_campo_perfil' );

/**
 * Grava a caixa. Exige administrador, permissão sobre o utilizador e nonce próprio.
 *
 * @param int $user_id ID do utilizador editado ou acabado de criar.
 */
function td_notas_gravar_perfil( $user_id ) {
	$user_id = (int) $user_id;

	if ( ! $user_id || ! current_user_can( TD_NOTAS_CAP_ADMIN ) || ! current_user_can( 'edit_user', $user_id ) ) {
		return;
	}

	if ( ! isset( $_POST['td_notas_perfil_nonce'] )
		|| ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['td_notas_perfil_nonce'] ) ), 'td_notas_perfil' ) ) {
		return;
	}

	if ( ! empty( $_POST['td_notas_pode_usar'] ) ) {
		update_user_meta( $user_id, TD_NOTAS_META_USER, '1' );
	} else {
		delete_user_meta( $user_id, TD_NOTAS_META_USER );
	}
}
add_action( 'personal_options_update', 'td_notas_gravar_perfil' );
add_action( 'edit_user_profile_update', 'td_notas_gravar_perfil' );
add_action( 'user_register', 'td_notas_gravar_perfil' );

/* -------------------------------------------------------------------------
 * 4. NOTAS: funções de apoio (usadas pelo backoffice e pela REST)
 * ---------------------------------------------------------------------- */

/**
 * Estado atual de uma nota (slug). Sem estado atribuído conta como "aberta".
 */
function td_notas_obter_estado( $nota_id ) {
	$termos  = wp_get_object_terms( (int) $nota_id, TD_NOTAS_TAX, array( 'fields' => 'slugs' ) );
	$estados = td_notas_estados();

	if ( ! is_wp_error( $termos ) && ! empty( $termos ) && isset( $estados[ $termos[0] ] ) ) {
		return $termos[0];
	}

	return 'aberta';
}

/**
 * Muda o estado de uma nota. Só aceita um dos 4 estados.
 */
function td_notas_definir_estado( $nota_id, $estado ) {
	if ( ! is_string( $estado ) || ! array_key_exists( $estado, td_notas_estados() ) ) {
		return false;
	}

	$resultado = wp_set_object_terms( (int) $nota_id, $estado, TD_NOTAS_TAX, false );

	return ! is_wp_error( $resultado );
}

/**
 * Respostas de uma nota, por ordem de criação.
 *
 * @return array[] Cada resposta: autor (ID), data (timestamp), texto.
 */
function td_notas_respostas( $nota_id ) {
	$respostas = array();

	foreach ( (array) get_post_meta( (int) $nota_id, TD_NOTAS_META_RESPOSTA, false ) as $linha ) {
		if ( is_array( $linha ) && isset( $linha['texto'] ) ) {
			$respostas[] = array(
				'autor' => isset( $linha['autor'] ) ? (int) $linha['autor'] : 0,
				'data'  => isset( $linha['data'] ) ? (int) $linha['data'] : 0,
				'texto' => (string) $linha['texto'],
				'anexo' => isset( $linha['anexo'] ) ? (int) $linha['anexo'] : 0,
			);
		}
	}

	return $respostas;
}

/**
 * O autor é administrador do site (equipa da Tecla Digital)? Serve só para
 * dar cor às mensagens; não devolve nenhum dado do utilizador.
 */
function td_notas_e_admin( $user_id ) {
	static $cache = array();

	$user_id = (int) $user_id;

	if ( ! isset( $cache[ $user_id ] ) ) {
		$cache[ $user_id ] = $user_id > 0 && user_can( $user_id, TD_NOTAS_CAP_ADMIN );
	}

	return $cache[ $user_id ];
}

/**
 * Nome a mostrar de um utilizador. Nunca devolve o login nem o email.
 */
function td_notas_nome_autor( $user_id ) {
	$user = get_userdata( (int) $user_id );

	return $user ? $user->display_name : 'Utilizador removido';
}

/**
 * Primeiro nome a mostrar (o "Nome" do perfil, ou a primeira palavra do nome público).
 */
function td_notas_primeiro_nome( $user_id ) {
	$user = get_userdata( (int) $user_id );

	if ( ! $user ) {
		return 'Utilizador removido';
	}

	$nome = trim( (string) $user->first_name );

	if ( '' === $nome ) {
		$partes = preg_split( '/\s+/', trim( $user->display_name ) );
		$nome   = $partes ? $partes[0] : $user->display_name;
	}

	return $nome;
}

/**
 * Quem falou por último numa nota: o autor da última resposta ou, sem respostas, o autor da nota.
 *
 * @return array autor (ID), data (timestamp)
 */
function td_notas_ultima_mensagem( $nota ) {
	$respostas = td_notas_respostas( $nota->ID );

	if ( $respostas ) {
		$ultima = end( $respostas );

		return array( 'autor' => $ultima['autor'], 'data' => $ultima['data'] );
	}

	return array( 'autor' => (int) $nota->post_author, 'data' => (int) get_post_timestamp( $nota ) );
}

/**
 * Data legível, no fuso horário do site.
 */
function td_notas_data( $timestamp ) {
	return wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $timestamp );
}

/**
 * O elemento da nota não foi encontrado na última visita à página?
 * Quem verifica é o browser (só ele vê a página montada) e avisa pela REST.
 */
function td_notas_em_falta( $nota_id ) {
	return '1' === get_post_meta( (int) $nota_id, TD_NOTAS_META_EM_FALTA, true );
}

/**
 * Aviso a vermelho para o backoffice.
 */
function td_notas_aviso_em_falta( $nota_id ) {
	if ( ! td_notas_em_falta( $nota_id ) ) {
		return '';
	}

	return '<span style="color:#b32d2e;font-weight:600;">Elemento não encontrado</span>';
}

/**
 * Endereço que abre a página da nota e a destaca (?td_notas=ID).
 * É sempre construído a partir da raiz deste site: um caminho guardado
 * nunca consegue apontar para outro domínio. As notas globais abrem a homepage.
 */
function td_notas_url_pagina( $nota_id ) {
	$nota_id = (int) $nota_id;
	$caminho = (string) get_post_meta( $nota_id, TD_NOTAS_META_CAMINHO, true );

	if ( '1' === get_post_meta( $nota_id, TD_NOTAS_META_GLOBAL, true ) || '' === $caminho || '/' !== $caminho[0] ) {
		$caminho = '/';
	}

	return add_query_arg( 'td_notas', $nota_id, home_url( $caminho ) );
}

/* -------------------------------------------------------------------------
 * 5. BACKOFFICE: lista de notas
 * ---------------------------------------------------------------------- */

/**
 * Colunas: nota, página, estado, respostas, autor, data.
 */
function td_notas_colunas( $colunas ) {
	$novas = array(
		'cb'                         => isset( $colunas['cb'] ) ? $colunas['cb'] : '<input type="checkbox" />',
		'td_id'                      => 'ID',
		'title'                      => 'Nota',
		'td_pagina'                  => 'Página',
		'taxonomy-' . TD_NOTAS_TAX   => 'Estado',
		'td_respostas'               => 'Respostas',
		'author'                     => 'Autor',
		'date'                       => 'Data',
	);

	return $novas;
}
add_filter( 'manage_' . TD_NOTAS_CPT . '_posts_columns', 'td_notas_colunas' );

/**
 * A coluna ID ordena-se clicando no cabeçalho.
 */
function td_notas_colunas_ordenaveis( $colunas ) {
	$colunas['td_id'] = array( 'ID', true );

	return $colunas;
}
add_filter( 'manage_edit-' . TD_NOTAS_CPT . '_sortable_columns', 'td_notas_colunas_ordenaveis' );

/**
 * Coluna ID estreita.
 */
function td_notas_estilo_lista() {
	$ecra = get_current_screen();

	if ( $ecra && 'edit-' . TD_NOTAS_CPT === $ecra->id ) {
		echo '<style>.column-td_id{width:70px;}</style>';
	}
}
add_action( 'admin_head', 'td_notas_estilo_lista' );

function td_notas_coluna_conteudo( $coluna, $nota_id ) {
	if ( 'td_id' === $coluna ) {
		echo esc_html( (string) $nota_id );
	}

	if ( 'td_pagina' === $coluna ) {
		$global  = '1' === get_post_meta( $nota_id, TD_NOTAS_META_GLOBAL, true );
		$caminho = (string) get_post_meta( $nota_id, TD_NOTAS_META_CAMINHO, true );

		printf(
			'<a href="%s" target="_blank" rel="noopener">%s</a>',
			esc_url( td_notas_url_pagina( $nota_id ) ),
			esc_html( $global ? 'Global (cabeçalho/rodapé)' : ( '' !== $caminho ? $caminho : '/' ) )
		);

		if ( $global && '' !== $caminho ) {
			echo '<br><span class="description">' . esc_html( 'Criada em ' . $caminho ) . '</span>';
		}

		if ( td_notas_em_falta( $nota_id ) ) {
			echo '<br>' . wp_kses( td_notas_aviso_em_falta( $nota_id ), array( 'span' => array( 'style' => true ) ) );
		}
	}

	if ( 'td_respostas' === $coluna ) {
		echo esc_html( (string) count( td_notas_respostas( $nota_id ) ) );

		// Quem falou por último: círculo azul (administrador) ou cinzento-escuro (cliente) + primeiro nome.
		$ultima = td_notas_ultima_mensagem( get_post( $nota_id ) );
		$nome   = td_notas_primeiro_nome( $ultima['autor'] );
		$inicial = function_exists( 'mb_substr' ) ? mb_strtoupper( mb_substr( $nome, 0, 1 ) ) : strtoupper( substr( $nome, 0, 1 ) );

		printf(
			' <span title="%s" style="display:inline-flex;align-items:center;gap:4px;margin-left:4px;white-space:nowrap;"><span style="display:inline-flex;align-items:center;justify-content:center;width:16px;height:16px;border-radius:50%%;background:%s;color:#fff;font-size:9px;font-weight:700;line-height:1;">%s</span>%s</span>',
			esc_attr( 'Última mensagem: ' . td_notas_nome_autor( $ultima['autor'] ) . ' · ' . td_notas_data( $ultima['data'] ) ),
			td_notas_e_admin( $ultima['autor'] ) ? '#0073e6' : '#3c434a',
			esc_html( $inicial ),
			esc_html( $nome )
		);

		if ( td_notas_tem_anexos( $nota_id ) ) {
			echo ' <span class="dashicons dashicons-paperclip" title="Tem anexos" style="font-size:16px;width:16px;height:16px;vertical-align:text-bottom;color:#646970;"></span>';
		}
	}
}
add_action( 'manage_' . TD_NOTAS_CPT . '_posts_custom_column', 'td_notas_coluna_conteudo', 10, 2 );

/**
 * Ações de cada linha: acrescenta "Ver na página" e retira a edição rápida.
 */
function td_notas_acoes_linha( $acoes, $post ) {
	if ( TD_NOTAS_CPT !== $post->post_type ) {
		return $acoes;
	}

	unset( $acoes['inline hide-if-no-js'] );

	if ( isset( $acoes['edit'] ) ) {
		$acoes['edit'] = sprintf( '<a href="%s">Ver nota</a>', esc_url( get_edit_post_link( $post->ID ) ) );
	}

	// Eliminar de vez (no Lixo) só para administradores; os restantes podem restaurar as suas.
	if ( ! current_user_can( TD_NOTAS_CAP_ADMIN ) ) {
		unset( $acoes['delete'] );
	}

	if ( 'trash' !== $post->post_status ) {
		$acoes['td_ver'] = sprintf(
			'<a href="%s" target="_blank" rel="noopener">Ver na página</a>',
			esc_url( td_notas_url_pagina( $post->ID ) )
		);
	}

	return $acoes;
}
add_filter( 'post_row_actions', 'td_notas_acoes_linha', 10, 2 );

/**
 * Filtros por estado e por autor, por cima da lista.
 */
function td_notas_filtro_estado( $post_type ) {
	if ( TD_NOTAS_CPT !== $post_type ) {
		return;
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Só leitura, para marcar a opção escolhida.
	$atual = isset( $_GET[ TD_NOTAS_TAX ] ) ? sanitize_key( wp_unslash( $_GET[ TD_NOTAS_TAX ] ) ) : '';

	// Quantidade de notas em cada estado (só as notas ativas: as do lixo não contam).
	$contagens = td_notas_contagens();
	$total     = (int) wp_count_posts( TD_NOTAS_CPT )->publish;

	echo '<label class="screen-reader-text" for="td-notas-filtro-estado">Filtrar por estado</label>';
	echo '<select name="' . esc_attr( TD_NOTAS_TAX ) . '" id="td-notas-filtro-estado">';
	echo '<option value="">' . esc_html( sprintf( 'Todos os estados (%d)', $total ) ) . '</option>';

	foreach ( td_notas_estados() as $slug => $nome ) {
		printf(
			'<option value="%s"%s>%s</option>',
			esc_attr( $slug ),
			selected( $atual, $slug, false ),
			esc_html( sprintf( '%s (%d)', $nome, $contagens[ $slug ] ) )
		);
	}

	echo '</select>';

	// Autor: só aparecem os utilizadores que já criaram notas.
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Só leitura, para marcar a opção escolhida.
	$autor = isset( $_GET['author'] ) ? absint( wp_unslash( $_GET['author'] ) ) : 0;

	echo '<label class="screen-reader-text" for="td-notas-filtro-autor">Filtrar por autor</label>';
	wp_dropdown_users(
		array(
			'name'                => 'author',
			'id'                  => 'td-notas-filtro-autor',
			'show_option_all'     => 'Todos os autores',
			'selected'            => $autor,
			'has_published_posts' => array( TD_NOTAS_CPT ),
			'orderby'             => 'display_name',
		)
	);
}
add_action( 'restrict_manage_posts', 'td_notas_filtro_estado' );

/**
 * Ações em massa: apagar as notas selecionadas e mudar o estado.
 * A ação "Editar" do WordPress não faz sentido aqui e é retirada.
 */
function td_notas_acoes_massa( $acoes ) {
	$novas = array();

	// Apagar em massa: só administradores.
	if ( isset( $acoes['trash'] ) && current_user_can( TD_NOTAS_CAP_ADMIN ) ) {
		$novas['trash'] = 'Apagar notas selecionadas';
	}

	// Mudar o estado em massa: todos os que têm acesso às notas (já o podem fazer nota a nota).
	// No Lixo não faz sentido.
	if ( isset( $acoes['trash'] ) || ! isset( $acoes['untrash'] ) ) {
		foreach ( td_notas_estados() as $slug => $nome ) {
			$novas[ 'td_notas_estado_' . $slug ] = 'Mudar estado para: ' . $nome;
		}
	}

	// No Lixo, os administradores mantêm as ações do WordPress (restaurar, eliminar de vez).
	if ( isset( $acoes['untrash'] ) && current_user_can( TD_NOTAS_CAP_ADMIN ) ) {
		$novas = $acoes;
		unset( $novas['edit'] );
	}

	return $novas;
}
add_filter( 'bulk_actions-edit-' . TD_NOTAS_CPT, 'td_notas_acoes_massa' );

/**
 * Executa a mudança de estado em massa. O WordPress já validou o nonce da lista.
 */
function td_notas_executar_acao_massa( $redirect, $acao, $ids ) {
	$prefixo = 'td_notas_estado_';

	if ( 0 !== strpos( (string) $acao, $prefixo ) || ! td_notas_pode_usar() ) {
		return $redirect;
	}

	$estado = substr( $acao, strlen( $prefixo ) );

	foreach ( (array) $ids as $id ) {
		$id = (int) $id;

		// Só notas ativas, e só as que esta pessoa pode alterar.
		if ( TD_NOTAS_CPT === get_post_type( $id ) && 'publish' === get_post_status( $id ) && current_user_can( 'edit_post', $id ) ) {
			td_notas_definir_estado( $id, $estado );
		}
	}

	return $redirect;
}
add_filter( 'handle_bulk_actions-edit-' . TD_NOTAS_CPT, 'td_notas_executar_acao_massa', 10, 3 );

/* -------------------------------------------------------------------------
 * 6. BACKOFFICE: ecrã de uma nota (texto, conversa e estado)
 * ---------------------------------------------------------------------- */

function td_notas_caixas() {
	// A caixa "Publicar" do WordPress permitiria mudar a visibilidade e a data: sai.
	// A gravação e o apagar ficam na caixa "Estado".
	remove_meta_box( 'submitdiv', TD_NOTAS_CPT, 'side' );

	add_meta_box( 'td_notas_detalhe', 'Nota', 'td_notas_caixa_detalhe', TD_NOTAS_CPT, 'normal', 'high' );
	add_meta_box( 'td_notas_estado', 'Estado', 'td_notas_caixa_estado', TD_NOTAS_CPT, 'side', 'high' );
}
add_action( 'add_meta_boxes_' . TD_NOTAS_CPT, 'td_notas_caixas' );

/**
 * Linha de informação para os administradores: onde funciona e quem tem acesso.
 */
function td_notas_texto_informacao() {
	if ( ! current_user_can( TD_NOTAS_CAP_ADMIN ) ) {
		return 'As notas criam-se e respondem-se no próprio site, e só funcionam em computador (ecrãs com 1024 px ou mais de largura). Aqui podes consultá-las e mudar o estado.';
	}

	return 'As notas só funcionam em computador (ecrãs com 1024 px ou mais de largura). '
		. 'Os administradores têm acesso automático; os restantes utilizadores precisam da caixa «Pode usar TD Notas» marcada no perfil.';
}

/**
 * Mostra a informação no topo da lista de notas.
 */
function td_notas_aviso_lista() {
	$ecra = get_current_screen();

	if ( ! $ecra || 'edit-' . TD_NOTAS_CPT !== $ecra->id || ! td_notas_pode_usar() ) {
		return;
	}

	echo '<div class="notice notice-info inline"><p>' . esc_html( td_notas_texto_informacao() ) . '</p></div>';
}
add_action( 'admin_notices', 'td_notas_aviso_lista' );

/**
 * Botão "Ver todas as notas" no topo do ecrã de uma nota.
 */
function td_notas_botao_voltar( $post ) {
	if ( TD_NOTAS_CPT !== $post->post_type ) {
		return;
	}

	printf(
		'<p style="margin:12px 0 0;"><a class="button" href="%s">&larr; Ver todas as notas</a></p>',
		esc_url( admin_url( 'edit.php?post_type=' . TD_NOTAS_CPT ) )
	);
}
add_action( 'edit_form_top', 'td_notas_botao_voltar' );

/**
 * Ligação "📎 nome-do-ficheiro" no ecrã da nota do backoffice.
 */
function td_notas_ligacao_anexo_admin( $anexo_id ) {
	$anexo = td_notas_anexo_saida( $anexo_id );

	if ( $anexo ) {
		printf(
			'<p style="margin:4px 0 0;"><span class="dashicons dashicons-paperclip" style="font-size:16px;width:16px;height:16px;vertical-align:text-bottom;"></span> <a href="%s" target="_blank" rel="noopener">%s</a></p>',
			esc_url( $anexo['url'] ),
			esc_html( $anexo['nome'] )
		);
	}
}

/**
 * Texto da nota, onde foi criada e a conversa. Só leitura: responde-se na página.
 */
function td_notas_caixa_detalhe( $post ) {
	$excerto   = (string) get_post_meta( $post->ID, TD_NOTAS_META_EXCERTO, true );
	$seletor   = (string) get_post_meta( $post->ID, TD_NOTAS_META_SELETOR, true );
	$respostas = td_notas_respostas( $post->ID );
	?>
	<p>
		<strong>ID <?php echo esc_html( (string) $post->ID ); ?></strong>
		· <?php echo esc_html( td_notas_nome_autor( $post->post_author ) ); ?>
		· <?php echo esc_html( td_notas_data( get_post_timestamp( $post ) ) ); ?>
	</p>
	<p style="font-size:14px;"><?php echo nl2br( esc_html( $post->post_content ) ); ?></p>
	<?php td_notas_ligacao_anexo_admin( get_post_meta( $post->ID, TD_NOTAS_META_ANEXO, true ) ); ?>
	<p>
		<a class="button" href="<?php echo esc_url( td_notas_url_pagina( $post->ID ) ); ?>" target="_blank" rel="noopener">Ver na página</a>
	</p>

	<?php if ( td_notas_em_falta( $post->ID ) ) : ?>
		<p><?php echo wp_kses( td_notas_aviso_em_falta( $post->ID ), array( 'span' => array( 'style' => true ) ) ); ?>
			<span class="description">— o elemento foi alterado ou removido. A nota continua visível no painel da página.</span></p>
	<?php endif; ?>
	<?php if ( '' !== $excerto ) : ?>
		<p class="description">Texto do elemento: «<?php echo esc_html( $excerto ); ?>»</p>
	<?php endif; ?>
	<?php if ( '' !== $seletor ) : ?>
		<p class="description">Elemento: <code><?php echo esc_html( $seletor ); ?></code></p>
	<?php endif; ?>
	<?php $ecra = get_post_meta( $post->ID, TD_NOTAS_META_ECRA, true ); ?>
	<?php if ( is_array( $ecra ) && ! empty( $ecra['janela_l'] ) ) : ?>
		<p class="description">
			<?php
			$partes = array( sprintf( 'janela %d × %d px', (int) $ecra['janela_l'], (int) $ecra['janela_a'] ) );

			if ( ! empty( $ecra['monitor_l'] ) ) {
				$partes[] = sprintf( 'monitor %d × %d px', (int) $ecra['monitor_l'], (int) $ecra['monitor_a'] );
			}

			if ( ! empty( $ecra['densidade'] ) ) {
				$partes[] = 'densidade ' . rtrim( rtrim( number_format( (float) $ecra['densidade'], 2, ',', '' ), '0' ), ',' ) . '×';
			}

			echo esc_html( 'Ecrã ao criar a nota: ' . implode( ' · ', $partes ) );
			?>
		</p>
	<?php endif; ?>

	<hr>
	<h3>Respostas (<?php echo esc_html( (string) count( $respostas ) ); ?>)</h3>
	<?php if ( empty( $respostas ) ) : ?>
		<p class="description">Ainda não há respostas. Para responder, abre a nota na página.</p>
	<?php endif; ?>
	<?php foreach ( $respostas as $resposta ) : ?>
		<div style="border-left:3px solid #c3c4c7;padding:2px 0 2px 12px;margin:0 0 12px;">
			<strong><?php echo esc_html( td_notas_nome_autor( $resposta['autor'] ) ); ?></strong>
			· <?php echo esc_html( td_notas_data( $resposta['data'] ) ); ?>
			<br><?php echo nl2br( esc_html( $resposta['texto'] ) ); ?>
			<?php td_notas_ligacao_anexo_admin( $resposta['anexo'] ); ?>
		</div>
	<?php endforeach; ?>
	<?php
}

function td_notas_caixa_estado( $post ) {
	$atual = td_notas_obter_estado( $post->ID );

	wp_nonce_field( 'td_notas_estado_' . $post->ID, 'td_notas_estado_nonce' );

	echo '<label class="screen-reader-text" for="td-notas-estado">Estado da nota</label>';
	echo '<select name="td_notas_estado" id="td-notas-estado" style="width:100%;">';

	foreach ( td_notas_estados() as $slug => $nome ) {
		printf(
			'<option value="%s"%s>%s</option>',
			esc_attr( $slug ),
			selected( $atual, $slug, false ),
			esc_html( $nome )
		);
	}

	echo '</select>';
	echo '<p style="margin:12px 0 0;display:flex;align-items:center;justify-content:space-between;gap:8px;">';
	echo '<button type="submit" name="save" class="button button-primary">Gravar estado</button>';

	if ( 'trash' !== $post->post_status && current_user_can( 'delete_post', $post->ID ) ) {
		printf(
			'<a href="%s" style="color:#b32d2e;">Mover para o lixo</a>',
			esc_url( get_delete_post_link( $post->ID ) )
		);
	}

	echo '</p>';
}

/**
 * Grava o estado escolhido no ecrã da nota.
 */
function td_notas_gravar_estado( $post_id ) {
	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
		return;
	}

	if ( ! isset( $_POST['td_notas_estado'], $_POST['td_notas_estado_nonce'] )
		|| ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['td_notas_estado_nonce'] ) ), 'td_notas_estado_' . $post_id ) ) {
		return;
	}

	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}

	td_notas_definir_estado( $post_id, sanitize_key( wp_unslash( $_POST['td_notas_estado'] ) ) );
}
add_action( 'save_post_' . TD_NOTAS_CPT, 'td_notas_gravar_estado' );

/* -------------------------------------------------------------------------
 * 7. BACKOFFICE: widget no Dashboard
 * ---------------------------------------------------------------------- */

function td_notas_registar_widget() {
	if ( td_notas_pode_usar() ) {
		wp_add_dashboard_widget( 'td_notas_widget', 'TD Notas', 'td_notas_widget' );
	}
}
add_action( 'wp_dashboard_setup', 'td_notas_registar_widget' );

/**
 * Número de notas por estado e as 5 abertas mais recentes.
 */
function td_notas_widget() {
	$lista = admin_url( 'edit.php?post_type=' . TD_NOTAS_CPT );

	echo '<p class="description" style="margin-top:0;">' . esc_html( 'Só funciona em computador (ecrãs com 1024 px ou mais de largura).' ) . '</p>';

	echo '<ul style="display:flex;flex-wrap:wrap;gap:6px 18px;margin:0 0 12px;">';

	$contagens  = td_notas_contagens();
	$prioridade = td_notas_estados_prioritarios();
	$estados    = td_notas_estados();

	foreach ( $estados as $slug => $nome ) {
		printf(
			'<li><a href="%s">%s: <strong>%d</strong></a></li>',
			esc_url( add_query_arg( TD_NOTAS_TAX, $slug, $lista ) ),
			esc_html( $nome ),
			$contagens[ $slug ]
		);
	}

	echo '</ul>';

	// As mais recentes no estado em que esta pessoa tem trabalho à espera.
	$abertas = get_posts(
		array(
			'post_type'   => TD_NOTAS_CPT,
			'post_status' => 'publish',
			'numberposts' => 5,
			'tax_query'   => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
				array(
					'taxonomy' => TD_NOTAS_TAX,
					'field'    => 'slug',
					'terms'    => $prioridade,
				),
			),
		)
	);

	$nome_estado = implode( ' e ', array_intersect_key( $estados, array_flip( $prioridade ) ) );

	if ( empty( $abertas ) ) {
		echo '<p class="description">' . esc_html( 'Não há notas em «' . $nome_estado . '».' ) . '</p>';
		printf( '<p><a href="%s">Ver todas as notas</a></p>', esc_url( $lista ) );
		return;
	}

	echo '<p><strong>' . esc_html( $nome_estado . ' — mais recentes' ) . '</strong></p><ul>';

	foreach ( $abertas as $nota ) {
		printf(
			'<li style="margin-bottom:8px;"><a href="%s" target="_blank" rel="noopener">%s</a><br><span class="description">%s · %s</span></li>',
			esc_url( td_notas_url_pagina( $nota->ID ) ),
			esc_html( '' !== $nota->post_title ? $nota->post_title : '(sem texto)' ),
			esc_html( td_notas_nome_autor( $nota->post_author ) ),
			esc_html( td_notas_data( get_post_timestamp( $nota ) ) )
		);
	}

	echo '</ul>';
	printf( '<p><a href="%s">Ver todas as notas</a></p>', esc_url( $lista ) );
}

/* -------------------------------------------------------------------------
 * 7b. BARRA DE ADMINISTRAÇÃO: "TD Notas" com o número de notas à espera
 * ---------------------------------------------------------------------- */

/**
 * Item "TD Notas" na barra de cima, no site e no backoffice:
 * - o número mostra as notas no estado em que esta pessoa tem trabalho à espera
 *   (Abertas para os administradores, Para verificar para os restantes);
 * - ao passar o rato, abre um menu com todos os estados e a quantidade de cada um,
 *   e cada estado abre a lista de notas do backoffice já filtrada.
 * Quem não vê a barra (escolha no perfil, por exemplo) não vê este item.
 */
function td_notas_barra_admin( $barra ) {
	if ( ! td_notas_pode_usar() ) {
		return;
	}

	$lista      = admin_url( 'edit.php?post_type=' . TD_NOTAS_CPT );
	$estados    = td_notas_estados();
	$contagens  = td_notas_contagens();
	$prioridade = td_notas_estados_prioritarios();
	$a_espera   = array_sum( array_intersect_key( $contagens, array_flip( $prioridade ) ) );
	$nome_prio  = implode( ' e ', array_intersect_key( $estados, array_flip( $prioridade ) ) );

	$titulo = '<span class="ab-icon dashicons dashicons-format-status" style="top:2px;"></span>'
		. '<span class="ab-label">TD Notas</span>';

	if ( $a_espera > 0 ) {
		$titulo .= sprintf(
			' <span class="td-notas-ab-numero" style="display:inline-block;min-width:18px;height:18px;margin-left:4px;padding:0 5px;border-radius:9px;background:#d63638;color:#fff;font-size:11px;font-weight:600;line-height:18px;text-align:center;box-sizing:border-box;">%d</span>',
			$a_espera
		);
	}

	$barra->add_node(
		array(
			'id'    => 'td-notas',
			'title' => $titulo,
			// Vários estados na mesma lista: a vírgula no filtro do WordPress quer dizer "ou".
			'href'  => add_query_arg( TD_NOTAS_TAX, implode( ',', $prioridade ), $lista ),
			'meta'  => array(
				'title' => $a_espera ? sprintf( '%d nota(s) em «%s»', $a_espera, $nome_prio ) : 'TD Notas',
			),
		)
	);

	// Os estados que esta pessoa acompanha aparecem primeiro, pela ordem normal.
	$ordem = array_merge( $prioridade, array_diff( array_keys( $estados ), $prioridade ) );

	foreach ( $ordem as $slug ) {
		$barra->add_node(
			array(
				'parent' => 'td-notas',
				'id'     => 'td-notas-' . $slug,
				'title'  => esc_html( sprintf( '%s (%d)', $estados[ $slug ], $contagens[ $slug ] ) ),
				'href'   => add_query_arg( TD_NOTAS_TAX, $slug, $lista ),
			)
		);
	}

	$barra->add_node(
		array(
			'parent' => 'td-notas',
			'id'     => 'td-notas-todas',
			'title'  => esc_html( sprintf( 'Todas as notas (%d)', array_sum( $contagens ) ) ),
			'href'   => $lista,
		)
	);
}
add_action( 'admin_bar_menu', 'td_notas_barra_admin', 80 );

/* -------------------------------------------------------------------------
 * 8. REST API (td-notas/v1)
 *
 * Todos os endpoints exigem sessão iniciada, o nonce "wp_rest" (cabeçalho
 * X-WP-Nonce, verificado pelo WordPress) e a capacidade "td_notas".
 * Todo o texto é guardado como texto simples e devolvido para ser mostrado
 * com textContent no browser (nunca como HTML).
 * ---------------------------------------------------------------------- */

const TD_NOTAS_MAX_TEXTO     = 2000; // Caracteres por nota ou resposta.
const TD_NOTAS_MAX_RESPOSTAS = 100;  // Respostas por nota.
const TD_NOTAS_MAX_LISTA     = 200;  // Notas devolvidas por página.

function td_notas_registar_rest() {
	$ns  = 'td-notas/v1';
	$id  = array(
		'type'              => 'integer',
		'required'          => true,
		'minimum'           => 1,
		'validate_callback' => 'rest_validate_request_arg',
	);
	$txt = array(
		'type'              => 'string',
		'required'          => true,
		'validate_callback' => 'rest_validate_request_arg',
	);

	register_rest_route(
		$ns,
		'/notas',
		array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => 'td_notas_rest_listar',
				'permission_callback' => 'td_notas_rest_permissao',
				'args'                => array( 'caminho' => $txt ),
			),
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => 'td_notas_rest_criar',
				'permission_callback' => 'td_notas_rest_permissao',
				'args'                => array(
					'texto'   => $txt,
					'caminho' => $txt,
					'seletor' => array( 'type' => 'string', 'default' => '', 'validate_callback' => 'rest_validate_request_arg' ),
					'excerto' => array( 'type' => 'string', 'default' => '', 'validate_callback' => 'rest_validate_request_arg' ),
					'global'  => array( 'type' => 'boolean', 'default' => false, 'validate_callback' => 'rest_validate_request_arg' ),
					'post_id' => array( 'type' => 'integer', 'default' => 0, 'minimum' => 0, 'validate_callback' => 'rest_validate_request_arg' ),
					'anexo'   => array( 'type' => 'integer', 'default' => 0, 'minimum' => 0, 'validate_callback' => 'rest_validate_request_arg' ),
					'x'       => array( 'type' => 'number', 'default' => 50, 'validate_callback' => 'rest_validate_request_arg' ),
					// Ecrã de quem cria a nota (só para consulta no backoffice).
					'janela_l'  => array( 'type' => 'integer', 'default' => 0, 'minimum' => 0, 'maximum' => 20000, 'validate_callback' => 'rest_validate_request_arg' ),
					'janela_a'  => array( 'type' => 'integer', 'default' => 0, 'minimum' => 0, 'maximum' => 20000, 'validate_callback' => 'rest_validate_request_arg' ),
					'monitor_l' => array( 'type' => 'integer', 'default' => 0, 'minimum' => 0, 'maximum' => 20000, 'validate_callback' => 'rest_validate_request_arg' ),
					'monitor_a' => array( 'type' => 'integer', 'default' => 0, 'minimum' => 0, 'maximum' => 20000, 'validate_callback' => 'rest_validate_request_arg' ),
					'densidade' => array( 'type' => 'number', 'default' => 0, 'minimum' => 0, 'maximum' => 10, 'validate_callback' => 'rest_validate_request_arg' ),
					'y'       => array( 'type' => 'number', 'default' => 50, 'validate_callback' => 'rest_validate_request_arg' ),
				),
			),
		)
	);

	register_rest_route(
		$ns,
		'/notas/(?P<id>[\d]+)',
		array(
			'methods'             => WP_REST_Server::DELETABLE,
			'callback'            => 'td_notas_rest_apagar',
			'permission_callback' => 'td_notas_rest_permissao',
			'args'                => array( 'id' => $id ),
		)
	);

	register_rest_route(
		$ns,
		'/notas/(?P<id>[\d]+)/estado',
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'td_notas_rest_estado',
			'permission_callback' => 'td_notas_rest_permissao',
			'args'                => array(
				'id'     => $id,
				'estado' => array(
					'type'              => 'string',
					'required'          => true,
					'enum'              => array_keys( td_notas_estados() ),
					'validate_callback' => 'rest_validate_request_arg',
				),
			),
		)
	);

	register_rest_route(
		$ns,
		'/notas/(?P<id>[\d]+)/elemento',
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'td_notas_rest_elemento',
			'permission_callback' => 'td_notas_rest_permissao',
			'args'                => array(
				'id'       => $id,
				'em_falta' => array(
					'type'              => 'boolean',
					'required'          => true,
					'validate_callback' => 'rest_validate_request_arg',
				),
			),
		)
	);

	register_rest_route(
		$ns,
		'/notas/(?P<id>[\d]+)/respostas',
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'td_notas_rest_responder',
			'permission_callback' => 'td_notas_rest_permissao',
			'args'                => array(
				'id'    => $id,
				'texto' => $txt,
				'anexo' => array( 'type' => 'integer', 'default' => 0, 'minimum' => 0, 'validate_callback' => 'rest_validate_request_arg' ),
			),
		)
	);
}
add_action( 'rest_api_init', 'td_notas_registar_rest' );

/**
 * Permissão de todos os endpoints: 401 sem sessão, 403 com sessão mas sem acesso.
 */
function td_notas_rest_permissao() {
	if ( td_notas_pode_usar() ) {
		return true;
	}

	return new WP_Error(
		'td_notas_proibido',
		'Não tens permissão para usar as notas.',
		array( 'status' => rest_authorization_required_code() )
	);
}

/**
 * Normaliza o caminho de uma página: sem domínio, sem querystring, sem âncora,
 * sem a subpasta de instalação e sem barra final. Ex.: "/servicos".
 */
function td_notas_limpar_caminho( $valor ) {
	$caminho = wp_parse_url( is_string( $valor ) ? trim( $valor ) : '', PHP_URL_PATH );
	$caminho = is_string( $caminho ) ? wp_check_invalid_utf8( $caminho ) : '';
	$caminho = (string) preg_replace( '/[\x00-\x20\x7F<>"\']/', '', $caminho );

	// Retira a subpasta, se o site estiver instalado numa (ex.: dominio.pt/staging/).
	$base = untrailingslashit( (string) wp_parse_url( home_url(), PHP_URL_PATH ) );

	if ( '' !== $base && ( $caminho === $base || 0 === strpos( $caminho, $base . '/' ) ) ) {
		$caminho = substr( $caminho, strlen( $base ) );
	}

	$caminho = '/' . trim( $caminho, '/' );

	return mb_strlen( $caminho ) > 500 ? '/' : $caminho;
}

/**
 * Limpa o texto de uma nota ou resposta: texto simples, não vazio, com limite.
 *
 * @return string|WP_Error
 */
function td_notas_limpar_texto( $valor ) {
	$texto = trim( sanitize_textarea_field( (string) $valor ) );

	if ( '' === $texto ) {
		return new WP_Error( 'td_notas_texto_vazio', 'Escreve o texto da nota.', array( 'status' => 400 ) );
	}

	if ( mb_strlen( $texto ) > TD_NOTAS_MAX_TEXTO ) {
		return new WP_Error(
			'td_notas_texto_longo',
			sprintf( 'O texto não pode ter mais de %d caracteres.', TD_NOTAS_MAX_TEXTO ),
			array( 'status' => 400 )
		);
	}

	return $texto;
}

/**
 * Devolve a nota pedida, ou 404. Confirma que o ID é mesmo de uma nota ativa:
 * impede que estes endpoints toquem em qualquer outro conteúdo do site.
 *
 * @return WP_Post|WP_Error
 */
function td_notas_rest_obter_nota( $request ) {
	$nota = get_post( (int) $request['id'] );

	if ( ! $nota || TD_NOTAS_CPT !== $nota->post_type || 'publish' !== $nota->post_status ) {
		return new WP_Error( 'td_notas_nao_encontrada', 'Nota não encontrada.', array( 'status' => 404 ) );
	}

	return $nota;
}

/**
 * Texto simples para enviar ao browser. O WordPress guarda "<" como "&lt;";
 * aqui volta a ser "<" porque o JS mostra tudo com textContent (nunca innerHTML).
 */
function td_notas_texto_saida( $texto ) {
	return html_entity_decode( (string) $texto, ENT_QUOTES, 'UTF-8' );
}

/**
 * Valida o ficheiro a anexar a uma nota ou resposta.
 * O ficheiro tem de ter sido carregado pelo próprio utilizador (pela API de
 * Multimédia do WordPress, com as regras normais de tipos e tamanho) e ainda
 * não pode estar ligado a nenhum conteúdo. Assim ninguém consegue "puxar" para
 * uma nota um ficheiro de outra pessoa ou de outra página.
 *
 * @return int|WP_Error ID do ficheiro (0 se não houver anexo).
 */
function td_notas_validar_anexo( $anexo_id ) {
	$anexo_id = (int) $anexo_id;

	if ( ! $anexo_id ) {
		return 0;
	}

	if ( ! current_user_can( 'upload_files' ) ) {
		return new WP_Error( 'td_notas_anexo_proibido', 'Não tens permissão para anexar ficheiros.', array( 'status' => 403 ) );
	}

	$anexo = get_post( $anexo_id );

	if ( ! $anexo || 'attachment' !== $anexo->post_type || (int) $anexo->post_author !== get_current_user_id() || (int) $anexo->post_parent ) {
		return new WP_Error( 'td_notas_anexo_invalido', 'Não foi possível anexar o ficheiro. Tenta carregá-lo de novo.', array( 'status' => 400 ) );
	}

	return $anexo_id;
}

/**
 * Liga o ficheiro à nota: na Biblioteca de Multimédia aparece como
 * "Carregado para: <nota>", o que ajuda a encontrá-lo e apagá-lo antes de publicar o site.
 */
function td_notas_ligar_anexo( $anexo_id, $nota_id ) {
	if ( $anexo_id ) {
		wp_update_post(
			array(
				'ID'          => (int) $anexo_id,
				'post_parent' => (int) $nota_id,
			)
		);
	}
}

/**
 * Nome e endereço de um anexo, ou null se não existir (por exemplo, se foi apagado da Biblioteca).
 */
function td_notas_anexo_saida( $anexo_id ) {
	$anexo_id = (int) $anexo_id;
	$url      = $anexo_id ? wp_get_attachment_url( $anexo_id ) : false;

	if ( ! $url ) {
		return null;
	}

	$ficheiro = get_attached_file( $anexo_id );

	return array(
		'nome' => $ficheiro ? wp_basename( $ficheiro ) : get_the_title( $anexo_id ),
		'url'  => esc_url_raw( $url ),
	);
}

/**
 * A nota ou alguma resposta tem um ficheiro anexado?
 */
function td_notas_tem_anexos( $nota_id ) {
	if ( td_notas_anexo_saida( get_post_meta( (int) $nota_id, TD_NOTAS_META_ANEXO, true ) ) ) {
		return true;
	}

	foreach ( td_notas_respostas( $nota_id ) as $resposta ) {
		if ( td_notas_anexo_saida( $resposta['anexo'] ) ) {
			return true;
		}
	}

	return false;
}

/**
 * Formato de uma nota na resposta da REST. Sem IDs, logins ou emails de utilizadores.
 */
function td_notas_preparar_nota( $nota ) {
	$respostas = array();
	$eu        = get_current_user_id();

	$tem_anexos = false;
	$anexo_nota = td_notas_anexo_saida( get_post_meta( $nota->ID, TD_NOTAS_META_ANEXO, true ) );

	foreach ( td_notas_respostas( $nota->ID ) as $resposta ) {
		$anexo      = td_notas_anexo_saida( $resposta['anexo'] );
		$tem_anexos = $tem_anexos || (bool) $anexo;

		$respostas[] = array(
			'minha' => $resposta['autor'] === $eu,
			'admin' => td_notas_e_admin( $resposta['autor'] ),
			'autor' => td_notas_nome_autor( $resposta['autor'] ),
			'data'  => td_notas_data( $resposta['data'] ),
			'texto' => td_notas_texto_saida( $resposta['texto'] ),
			'anexo' => $anexo,
		);
	}

	return array(
		'id'          => (int) $nota->ID,
		'texto'       => td_notas_texto_saida( $nota->post_content ),
		'autor'       => td_notas_nome_autor( $nota->post_author ),
		'minha'       => (int) $nota->post_author === $eu, // Para assinalar "(tu)" no nome.
		'admin'       => td_notas_e_admin( $nota->post_author ), // Equipa (azul) ou cliente (cinzento-escuro).
		'data'        => td_notas_data( get_post_timestamp( $nota ) ),
		'estado'      => td_notas_obter_estado( $nota->ID ),
		'caminho'     => (string) get_post_meta( $nota->ID, TD_NOTAS_META_CAMINHO, true ),
		'seletor'     => (string) get_post_meta( $nota->ID, TD_NOTAS_META_SELETOR, true ),
		'excerto'     => td_notas_texto_saida( get_post_meta( $nota->ID, TD_NOTAS_META_EXCERTO, true ) ),
		'global'      => '1' === get_post_meta( $nota->ID, TD_NOTAS_META_GLOBAL, true ),
		'em_falta'    => td_notas_em_falta( $nota->ID ),
		'x'           => (float) get_post_meta( $nota->ID, TD_NOTAS_META_X, true ),
		'y'           => (float) get_post_meta( $nota->ID, TD_NOTAS_META_Y, true ),
		'pode_apagar' => td_notas_pode_apagar( $nota ),
		'anexo'       => $anexo_nota,
		'ultimo'      => td_notas_ultimo_saida( $nota ),
		'tem_anexos'  => $tem_anexos || (bool) $anexo_nota,
		'respostas'   => $respostas,
	);
}

/**
 * Quem falou por último, no formato da REST (sem IDs de utilizadores).
 */
function td_notas_ultimo_saida( $nota ) {
	$ultima = td_notas_ultima_mensagem( $nota );

	return array(
		'nome'          => td_notas_primeiro_nome( $ultima['autor'] ),
		'nome_completo' => td_notas_nome_autor( $ultima['autor'] ),
		'admin'         => td_notas_e_admin( $ultima['autor'] ),
		'data'          => td_notas_data( $ultima['data'] ),
	);
}

/**
 * Só o autor da nota ou um administrador a pode apagar.
 */
function td_notas_pode_apagar( $nota ) {
	return (int) $nota->post_author === get_current_user_id() || current_user_can( TD_NOTAS_CAP_ADMIN );
}

/**
 * GET /notas?caminho=/servicos — notas dessa página e notas globais.
 */
function td_notas_rest_listar( $request ) {
	$notas = get_posts(
		array(
			'post_type'   => TD_NOTAS_CPT,
			'post_status' => 'publish',
			'numberposts' => TD_NOTAS_MAX_LISTA,
			'orderby'     => 'ID',
			'order'       => 'ASC',
			'meta_query'  => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				'relation' => 'OR',
				array(
					'key'   => TD_NOTAS_META_CAMINHO,
					'value' => td_notas_limpar_caminho( $request['caminho'] ),
				),
				array(
					'key'   => TD_NOTAS_META_GLOBAL,
					'value' => '1',
				),
			),
		)
	);

	return rest_ensure_response( array_map( 'td_notas_preparar_nota', $notas ) );
}

/**
 * POST /notas — cria uma nota (estado inicial: Aberta).
 */
function td_notas_rest_criar( $request ) {
	$texto = td_notas_limpar_texto( $request['texto'] );

	if ( is_wp_error( $texto ) ) {
		return $texto;
	}

	$anexo = td_notas_validar_anexo( $request['anexo'] );

	if ( is_wp_error( $anexo ) ) {
		return $anexo;
	}

	// Seletor: só serve para document.querySelector no browser. Sem "<" nem caracteres de controlo.
	$seletor = wp_check_invalid_utf8( trim( (string) $request['seletor'] ) );
	$seletor = (string) preg_replace( '/[\x00-\x1F\x7F<]/', '', $seletor );

	if ( mb_strlen( $seletor ) > 500 ) {
		$seletor = ''; // Sem seletor, a nota aparece só no painel.
	}

	$excerto = mb_substr( sanitize_text_field( (string) $request['excerto'] ), 0, 200 );

	// O ID do conteúdo só é guardado se existir e não for, ele próprio, uma nota.
	$post_id  = (int) $request['post_id'];
	$conteudo = $post_id ? get_post( $post_id ) : null;

	if ( ! $conteudo || TD_NOTAS_CPT === $conteudo->post_type ) {
		$post_id = 0;
	}

	$nota_id = wp_insert_post(
		wp_slash(
			array(
				'post_type'      => TD_NOTAS_CPT,
				'post_status'    => 'publish',
				'post_author'    => get_current_user_id(),
				'post_title'     => wp_html_excerpt( (string) preg_replace( '/\s+/', ' ', $texto ), 80, '…' ),
				'post_content'   => $texto,
				'comment_status' => 'closed',
				'ping_status'    => 'closed',
			)
		),
		true
	);

	if ( is_wp_error( $nota_id ) ) {
		return new WP_Error( 'td_notas_erro', 'Não foi possível gravar a nota.', array( 'status' => 500 ) );
	}

	td_notas_definir_estado( $nota_id, 'aberta' );

	update_post_meta( $nota_id, TD_NOTAS_META_CAMINHO, wp_slash( td_notas_limpar_caminho( $request['caminho'] ) ) );
	update_post_meta( $nota_id, TD_NOTAS_META_SELETOR, wp_slash( $seletor ) );
	update_post_meta( $nota_id, TD_NOTAS_META_EXCERTO, wp_slash( $excerto ) );
	update_post_meta( $nota_id, TD_NOTAS_META_X, (string) min( 100, max( 0, round( (float) $request['x'], 2 ) ) ) );
	update_post_meta( $nota_id, TD_NOTAS_META_Y, (string) min( 100, max( 0, round( (float) $request['y'], 2 ) ) ) );

	if ( $post_id ) {
		update_post_meta( $nota_id, TD_NOTAS_META_POST, $post_id );
	}

	if ( $request['global'] ) {
		update_post_meta( $nota_id, TD_NOTAS_META_GLOBAL, '1' );
	}

	if ( $anexo ) {
		update_post_meta( $nota_id, TD_NOTAS_META_ANEXO, $anexo );
		td_notas_ligar_anexo( $anexo, $nota_id );
	}

	if ( (int) $request['janela_l'] ) {
		update_post_meta(
			$nota_id,
			TD_NOTAS_META_ECRA,
			array(
				'janela_l'  => (int) $request['janela_l'],
				'janela_a'  => (int) $request['janela_a'],
				'monitor_l' => (int) $request['monitor_l'],
				'monitor_a' => (int) $request['monitor_a'],
				'densidade' => round( (float) $request['densidade'], 2 ),
			)
		);
	}

	$resposta = rest_ensure_response( td_notas_preparar_nota( get_post( $nota_id ) ) );
	$resposta->set_status( 201 );

	return $resposta;
}

/**
 * POST /notas/ID/estado — muda o estado.
 */
function td_notas_rest_estado( $request ) {
	$nota = td_notas_rest_obter_nota( $request );

	if ( is_wp_error( $nota ) ) {
		return $nota;
	}

	if ( ! td_notas_definir_estado( $nota->ID, (string) $request['estado'] ) ) {
		return new WP_Error( 'td_notas_estado_invalido', 'Estado inválido.', array( 'status' => 400 ) );
	}

	return rest_ensure_response( td_notas_preparar_nota( $nota ) );
}

/**
 * POST /notas/ID/respostas — acrescenta uma resposta à conversa.
 */
function td_notas_rest_responder( $request ) {
	$nota = td_notas_rest_obter_nota( $request );

	if ( is_wp_error( $nota ) ) {
		return $nota;
	}

	$texto = td_notas_limpar_texto( $request['texto'] );

	if ( is_wp_error( $texto ) ) {
		return $texto;
	}

	if ( count( td_notas_respostas( $nota->ID ) ) >= TD_NOTAS_MAX_RESPOSTAS ) {
		return new WP_Error( 'td_notas_limite', 'Esta nota atingiu o limite de respostas.', array( 'status' => 400 ) );
	}

	$anexo = td_notas_validar_anexo( $request['anexo'] );

	if ( is_wp_error( $anexo ) ) {
		return $anexo;
	}

	// Uma linha de meta por resposta: duas pessoas a responder ao mesmo tempo não se sobrepõem.
	add_post_meta(
		$nota->ID,
		TD_NOTAS_META_RESPOSTA,
		wp_slash(
			array(
				'autor' => get_current_user_id(),
				'data'  => time(),
				'texto' => $texto,
				'anexo' => $anexo,
			)
		)
	);

	td_notas_ligar_anexo( $anexo, $nota->ID );

	// Resposta de quem não é administrador (cliente): a nota volta à equipa como "Em revisão".
	// Uma nota ainda "Aberta" fica como está, porque a equipa ainda não lhe pegou.
	if ( ! current_user_can( TD_NOTAS_CAP_ADMIN ) && 'aberta' !== td_notas_obter_estado( $nota->ID ) ) {
		td_notas_definir_estado( $nota->ID, 'em-revisao' );
	}

	$resposta = rest_ensure_response( td_notas_preparar_nota( $nota ) );
	$resposta->set_status( 201 );

	return $resposta;
}

/**
 * POST /notas/ID/elemento — o browser indica se encontrou o elemento da nota.
 * Serve só para assinalar a nota no backoffice.
 */
function td_notas_rest_elemento( $request ) {
	$nota = td_notas_rest_obter_nota( $request );

	if ( is_wp_error( $nota ) ) {
		return $nota;
	}

	if ( $request['em_falta'] ) {
		update_post_meta( $nota->ID, TD_NOTAS_META_EM_FALTA, '1' );
	} else {
		delete_post_meta( $nota->ID, TD_NOTAS_META_EM_FALTA );
	}

	return rest_ensure_response( array( 'id' => (int) $nota->ID, 'em_falta' => (bool) $request['em_falta'] ) );
}

/**
 * DELETE /notas/ID — envia a nota para o lixo (recuperável no backoffice).
 */
function td_notas_rest_apagar( $request ) {
	$nota = td_notas_rest_obter_nota( $request );

	if ( is_wp_error( $nota ) ) {
		return $nota;
	}

	if ( ! td_notas_pode_apagar( $nota ) ) {
		return new WP_Error( 'td_notas_proibido', 'Só o autor da nota ou um administrador a pode apagar.', array( 'status' => 403 ) );
	}

	if ( ! wp_trash_post( $nota->ID ) ) {
		return new WP_Error( 'td_notas_erro', 'Não foi possível apagar a nota.', array( 'status' => 500 ) );
	}

	return rest_ensure_response( array( 'apagada' => true, 'id' => (int) $nota->ID ) );
}

/* -------------------------------------------------------------------------
 * 9. SITE: carregamento do JS e do CSS
 * ---------------------------------------------------------------------- */

/**
 * As notas só carregam no site, para quem tem sessão e acesso, e nunca
 * dentro de um editor visual. Os visitantes não recebem nada: as páginas
 * em cache não são afetadas.
 */
function td_notas_deve_carregar() {
	if ( is_admin() || ! td_notas_pode_usar() || is_customize_preview() ) {
		return false;
	}

	if ( function_exists( 'bricks_is_builder' ) && bricks_is_builder() ) {
		return false;
	}

	// Parâmetros que os editores visuais usam no endereço (Bricks, Elementor, Divi,
	// Beaver Builder, Oxygen, Breakdance, WPBakery, Thrive).
	$editores = array( 'bricks', 'elementor-preview', 'et_fb', 'fl_builder', 'ct_builder', 'oxygen_iframe', 'breakdance', 'breakdance_iframe', 'vc_editable', 'tve' );

	foreach ( $editores as $parametro ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Só verifica se o parâmetro existe.
		if ( isset( $_GET[ $parametro ] ) ) {
			return false;
		}
	}

	return (bool) apply_filters( 'td_notas_deve_carregar', true );
}

function td_notas_carregar_ficheiros() {
	if ( ! td_notas_deve_carregar() ) {
		return;
	}

	$pasta   = plugin_dir_path( __FILE__ ) . 'assets/';
	$ver_css = TD_NOTAS_VERSAO . '.' . (int) @filemtime( $pasta . 'notas.css' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
	$ver_js  = TD_NOTAS_VERSAO . '.' . (int) @filemtime( $pasta . 'notas.js' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
	$css_url = plugins_url( 'assets/notas.css', __FILE__ );

	wp_enqueue_style( 'td-notas', $css_url, array(), $ver_css );
	wp_enqueue_script( 'td-notas', plugins_url( 'assets/notas.js', __FILE__ ), array(), $ver_js, true );

	// Elementos que contam como cabeçalho ou rodapé (notas "Global").
	$globais = apply_filters(
		'td_notas_seletores_globais',
		array(
			'header',
			'footer',
			'[role="banner"]',
			'[role="contentinfo"]',
			'#brx-header',
			'#brx-footer',
			'.elementor-location-header',
			'.elementor-location-footer',
			'.et-l--header',
			'.et-l--footer',
			'#main-header',
			'#main-footer',
			'.site-header',
			'.site-footer',
		)
	);

	$dados = array(
		'restUrl'          => esc_url_raw( rest_url( 'td-notas/v1/notas' ) ),
		'nonce'            => wp_create_nonce( 'wp_rest' ),
		'cssUrl'           => esc_url_raw( add_query_arg( 'ver', $ver_css, $css_url ) ),
		'caminho'          => td_notas_limpar_caminho( isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '/' ), // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		'postId'           => is_singular() ? (int) get_queried_object_id() : 0,
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Só leitura: nota a destacar.
		'notaDestaque'     => isset( $_GET['td_notas'] ) ? absint( wp_unslash( $_GET['td_notas'] ) ) : 0,
		'estados'          => td_notas_estados(),
		'seletoresGlobais' => array_values( array_filter( (array) $globais, 'is_string' ) ),
		'larguraMinima'    => 1024,
		'maxTexto'         => TD_NOTAS_MAX_TEXTO,
		// Anexos: só para quem pode carregar ficheiros no WordPress (Editores, Administradores).
		'podeAnexar'       => current_user_can( 'upload_files' ),
		'mediaUrl'         => esc_url_raw( rest_url( 'wp/v2/media' ) ),
		'maxUpload'        => (int) wp_max_upload_size(),
	);

	wp_add_inline_script( 'td-notas', 'window.tdNotasDados = ' . wp_json_encode( $dados ) . ';', 'before' );
}
add_action( 'wp_enqueue_scripts', 'td_notas_carregar_ficheiros', 20 );

/* -------------------------------------------------------------------------
 * 10. ATUALIZAÇÕES A PARTIR DO GITHUB
 *
 * O cabeçalho "Update URI" diz ao WordPress que este plugin não vem do
 * diretório oficial: nunca é trocado por outro plugin com o mesmo nome.
 * O WordPress pergunta então a este código se há versão nova, e este
 * consulta as etiquetas de versão (tags vX.Y.Z) de github.com/tdpt/TD-notas.
 *
 * Para publicar uma versão: subir a versão no cabeçalho e em TD_NOTAS_VERSAO,
 * enviar o código para o GitHub e criar a etiqueta vX.Y.Z (git tag + git push).
 * O pacote é o zip que o GitHub gera para essa etiqueta.
 * ---------------------------------------------------------------------- */

const TD_NOTAS_GITHUB_REPO  = 'tdpt/TD-notas';
const TD_NOTAS_GITHUB_CACHE = 'td_notas_github_versao';

/**
 * Versão mais recente publicada no GitHub, guardada em cache (6 horas; 1 hora se o GitHub falhar).
 *
 * @return array|null { versao, etiqueta, pacote, url }
 */
function td_notas_github_release() {
	$cache = get_site_transient( TD_NOTAS_GITHUB_CACHE );

	if ( is_array( $cache ) ) {
		return empty( $cache['versao'] ) ? null : $cache;
	}

	$resposta = wp_remote_get(
		'https://api.github.com/repos/' . TD_NOTAS_GITHUB_REPO . '/tags?per_page=100',
		array(
			'timeout' => 10,
			'headers' => array(
				'Accept'     => 'application/vnd.github+json',
				'User-Agent' => 'TD-Notas/' . TD_NOTAS_VERSAO . '; ' . home_url( '/' ),
			),
		)
	);

	$melhor = null;

	if ( ! is_wp_error( $resposta ) && 200 === (int) wp_remote_retrieve_response_code( $resposta ) ) {
		$etiquetas = json_decode( wp_remote_retrieve_body( $resposta ), true );

		foreach ( is_array( $etiquetas ) ? $etiquetas : array() as $etiqueta ) {
			$nome = isset( $etiqueta['name'] ) ? (string) $etiqueta['name'] : '';

			// Só etiquetas de versão: v1.7.0, v1.7.1…
			if ( ! preg_match( '/^v(\d+\.\d+\.\d+)$/', $nome, $partes ) ) {
				continue;
			}

			if ( ! $melhor || version_compare( $partes[1], $melhor['versao'], '>' ) ) {
				$melhor = array(
					'versao'   => $partes[1],
					'etiqueta' => $nome,
					'pacote'   => 'https://codeload.github.com/' . TD_NOTAS_GITHUB_REPO . '/zip/refs/tags/' . rawurlencode( $nome ),
					'url'      => 'https://github.com/' . TD_NOTAS_GITHUB_REPO . '/tree/' . rawurlencode( $nome ),
				);
			}
		}
	}

	set_site_transient( TD_NOTAS_GITHUB_CACHE, $melhor ? $melhor : array( 'versao' => '' ), $melhor ? 6 * HOUR_IN_SECONDS : HOUR_IN_SECONDS );

	return $melhor;
}

/**
 * Responde ao WordPress quando ele pergunta se há atualização para o "Update URI" github.com.
 */
function td_notas_github_atualizacao( $atualizacao, $dados_plugin, $ficheiro_plugin ) {
	if ( plugin_basename( __FILE__ ) !== $ficheiro_plugin ) {
		return $atualizacao;
	}

	$release = td_notas_github_release();

	if ( ! $release || ! version_compare( $release['versao'], TD_NOTAS_VERSAO, '>' ) ) {
		return $atualizacao;
	}

	return array(
		'id'           => 'https://github.com/' . TD_NOTAS_GITHUB_REPO,
		'slug'         => 'td-notas',
		'plugin'       => $ficheiro_plugin,
		'version'      => $release['versao'],
		'url'          => $release['url'],
		'package'      => $release['pacote'],
		'requires'     => '6.0',
		'requires_php' => '7.4',
	);
}
add_filter( 'update_plugins_github.com', 'td_notas_github_atualizacao', 10, 3 );

/**
 * O zip do GitHub traz a pasta com outro nome (por exemplo, TD-notas-1.7.1).
 * Antes de instalar, passa a chamar-se td-notas, para substituir a pasta atual
 * do plugin em vez de criar outra ao lado.
 */
function td_notas_github_nome_pasta( $origem, $origem_remota, $instalador, $extra = array() ) {
	if ( empty( $extra['plugin'] ) || plugin_basename( __FILE__ ) !== $extra['plugin'] ) {
		return $origem;
	}

	global $wp_filesystem;

	$destino = trailingslashit( $origem_remota ) . dirname( plugin_basename( __FILE__ ) ) . '/';

	if ( untrailingslashit( $origem ) === untrailingslashit( $destino ) ) {
		return $origem;
	}

	if ( $wp_filesystem && $wp_filesystem->move( untrailingslashit( $origem ), untrailingslashit( $destino ), true ) ) {
		return $destino;
	}

	return new WP_Error( 'td_notas_pasta', 'Não foi possível preparar a pasta do TD Notas para a atualização.' );
}
add_filter( 'upgrader_source_selection', 'td_notas_github_nome_pasta', 10, 4 );

/**
 * Janela "Ver detalhes da versão" no ecrã de Plugins/Atualizações.
 */
function td_notas_github_detalhes( $resultado, $acao, $args ) {
	if ( 'plugin_information' !== $acao || empty( $args->slug ) || 'td-notas' !== $args->slug ) {
		return $resultado;
	}

	$release = td_notas_github_release();

	return (object) array(
		'name'          => 'TD Notas',
		'slug'          => 'td-notas',
		'version'       => $release ? $release['versao'] : TD_NOTAS_VERSAO,
		'author'        => 'Tecla Digital',
		'homepage'      => 'https://github.com/' . TD_NOTAS_GITHUB_REPO,
		'requires'      => '6.0',
		'requires_php'  => '7.4',
		'download_link' => $release ? $release['pacote'] : '',
		'sections'      => array(
			'changelog' => '<p>O histórico de versões está no início do ficheiro td-notas.php, no <a href="' . esc_url( 'https://github.com/' . TD_NOTAS_GITHUB_REPO ) . '" target="_blank" rel="noopener">repositório do GitHub</a>.</p>',
		),
	);
}
add_filter( 'plugins_api', 'td_notas_github_detalhes', 10, 3 );

/**
 * "Verificar novamente" em Painel > Atualizações também ignora a cache.
 */
function td_notas_github_limpar_cache() {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Só limpa uma cache, para administradores.
	if ( isset( $_GET['force-check'] ) && current_user_can( 'update_plugins' ) ) {
		delete_site_transient( TD_NOTAS_GITHUB_CACHE );
	}
}
add_action( 'load-update-core.php', 'td_notas_github_limpar_cache' );

/**
 * Depois de atualizar, a cache deixa de fazer sentido.
 */
function td_notas_github_apos_atualizar( $upgrader, $opcoes ) {
	if ( isset( $opcoes['type'], $opcoes['plugins'] ) && 'plugin' === $opcoes['type'] && in_array( plugin_basename( __FILE__ ), (array) $opcoes['plugins'], true ) ) {
		delete_site_transient( TD_NOTAS_GITHUB_CACHE );
	}
}
add_action( 'upgrader_process_complete', 'td_notas_github_apos_atualizar', 10, 2 );
