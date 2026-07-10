<?php
/**
 * OT_Tracker — captura de pageviews no front-end e gravação em banco.
 *
 * Usa a abordagem de beacon JS (compatível com cache de página e cookieless):
 * um script leve envia um "hit" para o endpoint do plugin no carregamento e
 * um "ping" de engajamento (tempo na página) ao sair. A geolocalização e a
 * detecção de dispositivo são feitas no servidor, a partir do IP e do UA.
 *
 * @package OrbitTrack
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OT_Tracker {

	public static function init() {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
	}

	/**
	 * Enfileira o beacon no front-end (quando o visitante deve ser rastreado).
	 */
	public static function enqueue() {
		if ( ! self::should_track() ) {
			return;
		}

		wp_enqueue_script( 'ot-tracker', OT_URL . 'public/js/tracker.js', array(), OT_VERSION, true );
		wp_localize_script( 'ot-tracker', 'OrbitTrack', array(
			'endpoint'  => admin_url( 'admin-ajax.php' ),
			'nonce'     => wp_create_nonce( 'ot_track' ),
			'postId'    => (int) ( is_singular() ? get_queried_object_id() : 0 ),
			'title'     => wp_get_document_title(),
			'respectDnt'=> (int) OT_Settings::get( 'respect_dnt' ),
		) );
	}

	/**
	 * Decide se o visitante atual deve ser rastreado.
	 *
	 * @return bool
	 */
	public static function should_track() {
		if ( is_admin() || is_preview() || is_customize_preview() ) {
			return false;
		}
		// Não rastreia papéis logados excluídos (ex.: administradores).
		if ( is_user_logged_in() ) {
			$exclude = (array) OT_Settings::get( 'exclude_roles' );
			$user    = wp_get_current_user();
			if ( array_intersect( $exclude, (array) $user->roles ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Registra um pageview e cria/atualiza a sessão correspondente.
	 *
	 * @param array $in Dados vindos do beacon (já validados pelo AJAX handler).
	 * @return array{ok:bool}
	 */
	public static function record_pageview( array $in ) {
		global $wpdb;

		$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';

		// Filtro de bots.
		if ( OT_Settings::get( 'exclude_bots' ) && OT_UA::is_bot( $ua ) ) {
			return array( 'ok' => false );
		}

		$device = OT_UA::parse( $ua );

		$url      = isset( $in['url'] ) ? esc_url_raw( $in['url'] ) : '';
		$path     = self::path_from_url( $url );
		$referrer = isset( $in['referrer'] ) ? esc_url_raw( $in['referrer'] ) : '';
		$title    = isset( $in['title'] ) ? sanitize_text_field( $in['title'] ) : '';
		$post_id  = isset( $in['post_id'] ) ? (int) $in['post_id'] : 0;

		$session_uid  = self::sanitize_uid( isset( $in['sid'] ) ? $in['sid'] : '' );
		$visitor_uid  = self::sanitize_uid( isset( $in['vid'] ) ? $in['vid'] : '' );
		$is_new       = ! empty( $in['new_visitor'] ) ? 1 : 0;
		if ( ! $session_uid || ! $visitor_uid ) {
			return array( 'ok' => false );
		}

		// Hash do visitante (privacidade — nunca guarda IP/UID em claro no relatório).
		$salt         = (string) get_option( 'ot_visitor_salt' );
		$visitor_hash = hash( 'sha1', $visitor_uid . '|' . $salt );

		$now        = current_time( 'mysql' );
		$site_host  = OT_Source::host( home_url( '/' ) );
		$params     = self::parse_landing_params( $url, $in );
		$attr       = OT_Source::classify( $referrer, $params, $site_host );

		$sessions = OT_DB::sessions_table();
		$hits     = OT_DB::hits_table();

		// Sessão já existe?
		$existing = $wpdb->get_row( $wpdb->prepare(
			"SELECT id, pageviews FROM {$sessions} WHERE session_uid = %s",
			$session_uid
		) );

		$is_entry = 0;

		if ( $existing ) {
			// Atualiza a sessão em andamento (não sobrescreve a atribuição de origem).
			$wpdb->query( $wpdb->prepare(
				"UPDATE {$sessions}
				 SET pageviews = pageviews + 1,
				     exit_page = %s,
				     last_seen = %s,
				     is_bounce = 0
				 WHERE id = %d",
				$path, $now, $existing->id
			) );
		} else {
			// Nova sessão — geolocaliza e grava a atribuição da origem (first touch).
			$ip  = OT_Geo::client_ip();
			$geo = OT_Geo::locate( $ip );

			$wpdb->insert( $sessions, array(
				'session_uid'    => $session_uid,
				'visitor_hash'   => $visitor_hash,
				'channel'        => $attr['channel'],
				'source'         => $attr['source'],
				'medium'         => $attr['medium'],
				'campaign'       => $attr['campaign'],
				'term'           => $attr['term'],
				'content'        => $attr['content'],
				'referrer'       => OT_Source::host( $referrer ),
				'landing_page'   => $path,
				'exit_page'      => $path,
				'device_type'    => $device['device_type'],
				'browser'        => $device['browser'],
				'os'             => $device['os'],
				'country_code'   => $geo['country_code'],
				'country'        => $geo['country'],
				'region'         => $geo['region'],
				'city'           => $geo['city'],
				'is_new_visitor' => $is_new,
				'pageviews'      => 1,
				'duration'       => 0,
				'is_bounce'      => 1,
				'started_at'     => $now,
				'last_seen'      => $now,
			) );
			$is_entry = 1;
		}

		// Grava o pageview.
		$wpdb->insert( $hits, array(
			'session_uid'  => $session_uid,
			'visitor_hash' => $visitor_hash,
			'url'          => $url,
			'path'         => $path,
			'title'        => $title,
			'post_id'      => $post_id,
			'referrer'     => OT_Source::host( $referrer ),
			'channel'      => $attr['channel'],
			'device_type'  => $device['device_type'],
			'country_code' => $existing ? '' : ( isset( $geo ) ? $geo['country_code'] : '' ),
			'time_on_page' => 0,
			'is_entry'     => $is_entry,
			'created_at'   => $now,
		) );

		$hit_id = (int) $wpdb->insert_id;

		return array( 'ok' => true, 'hit' => $hit_id );
	}

	/**
	 * Atualiza o tempo de permanência de um pageview e a duração da sessão.
	 *
	 * @param array $in Dados do ping de engajamento.
	 * @return array{ok:bool}
	 */
	public static function record_engagement( array $in ) {
		global $wpdb;

		$hit_id  = isset( $in['hit'] ) ? (int) $in['hit'] : 0;
		$seconds = isset( $in['seconds'] ) ? min( 7200, max( 0, (int) $in['seconds'] ) ) : 0;
		$sid     = self::sanitize_uid( isset( $in['sid'] ) ? $in['sid'] : '' );
		if ( ! $hit_id || ! $sid ) {
			return array( 'ok' => false );
		}

		$hits     = OT_DB::hits_table();
		$sessions = OT_DB::sessions_table();

		$wpdb->query( $wpdb->prepare(
			"UPDATE {$hits} SET time_on_page = %d WHERE id = %d AND session_uid = %s",
			$seconds, $hit_id, $sid
		) );

		// Duração da sessão = soma do tempo em página dos seus hits.
		$total = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COALESCE(SUM(time_on_page),0) FROM {$hits} WHERE session_uid = %s",
			$sid
		) );
		$wpdb->query( $wpdb->prepare(
			"UPDATE {$sessions} SET duration = %d, last_seen = %s WHERE session_uid = %s",
			$total, current_time( 'mysql' ), $sid
		) );

		return array( 'ok' => true );
	}

	/**
	 * Registra um clique em link de saída (link externo seguido pelo visitante).
	 *
	 * @param array $in Dados vindos do beacon.
	 * @return array{ok:bool}
	 */
	public static function record_outbound( array $in ) {
		global $wpdb;

		$target = isset( $in['target'] ) ? esc_url_raw( $in['target'] ) : '';
		$host   = OT_Source::host( $target );
		if ( ! $target || ! $host ) {
			return array( 'ok' => false );
		}

		// Ignora cliques que apontam para o próprio site.
		if ( OT_Source::host( home_url( '/' ) ) === $host ) {
			return array( 'ok' => false );
		}

		$session_uid = self::sanitize_uid( isset( $in['sid'] ) ? $in['sid'] : '' );
		$visitor_uid = self::sanitize_uid( isset( $in['vid'] ) ? $in['vid'] : '' );
		if ( ! $session_uid || ! $visitor_uid ) {
			return array( 'ok' => false );
		}

		$salt         = (string) get_option( 'ot_visitor_salt' );
		$visitor_hash = hash( 'sha1', $visitor_uid . '|' . $salt );
		$from_path    = self::path_from_url( isset( $in['from'] ) ? esc_url_raw( $in['from'] ) : '' );

		// Herda canal e país da sessão de origem, quando existir.
		$sessions = OT_DB::sessions_table();
		$sess = $wpdb->get_row( $wpdb->prepare(
			"SELECT channel, country_code FROM {$sessions} WHERE session_uid = %s",
			$session_uid
		) );

		$wpdb->insert( OT_DB::outbound_table(), array(
			'session_uid'  => $session_uid,
			'visitor_hash' => $visitor_hash,
			'target_url'   => substr( $target, 0, 255 ),
			'target_host'  => $host,
			'from_path'    => $from_path,
			'channel'      => $sess ? $sess->channel : 'direct',
			'country_code' => $sess ? $sess->country_code : '',
			'created_at'   => current_time( 'mysql' ),
		) );

		return array( 'ok' => true );
	}

	/**
	 * Monta os parâmetros da landing page: query da URL + overrides do beacon.
	 */
	private static function parse_landing_params( $url, array $in ) {
		$params = array();
		$q      = wp_parse_url( $url, PHP_URL_QUERY );
		if ( $q ) {
			parse_str( $q, $params );
		}
		// O beacon também pode enviar UTMs explicitamente (ex.: se limpos da URL).
		foreach ( array( 'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'gclid', 'fbclid', 'msclkid', 'ttclid' ) as $k ) {
			if ( ! empty( $in[ $k ] ) ) {
				$params[ $k ] = $in[ $k ];
			}
		}
		return $params;
	}

	/** Extrai o path (+query relevante removida) de uma URL. */
	private static function path_from_url( $url ) {
		$path = wp_parse_url( $url, PHP_URL_PATH );
		if ( ! $path ) {
			return '/';
		}
		return '/' . ltrim( substr( $path, 0, 190 ), '/' );
	}

	/** Valida um UID (formato tipo uuid/base36 gerado no cliente). */
	private static function sanitize_uid( $uid ) {
		$uid = preg_replace( '/[^a-zA-Z0-9\-]/', '', (string) $uid );
		return ( strlen( $uid ) >= 8 && strlen( $uid ) <= 36 ) ? $uid : '';
	}
}
