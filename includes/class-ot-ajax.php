<?php
/**
 * OT_Ajax — endpoints do beacon (front) e dados do painel (admin).
 *
 * @package OrbitTrack
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OT_Ajax {

	public static function init() {
		// Beacon público (logados e não logados) — via admin-ajax (fallback).
		add_action( 'wp_ajax_ot_hit', array( __CLASS__, 'hit' ) );
		add_action( 'wp_ajax_nopriv_ot_hit', array( __CLASS__, 'hit' ) );
		add_action( 'wp_ajax_ot_ping', array( __CLASS__, 'ping' ) );
		add_action( 'wp_ajax_nopriv_ot_ping', array( __CLASS__, 'ping' ) );
		add_action( 'wp_ajax_ot_out', array( __CLASS__, 'outbound' ) );
		add_action( 'wp_ajax_nopriv_ot_out', array( __CLASS__, 'outbound' ) );

		// Beacon público (transporte primário) — rota REST resistente a
		// ad-blocker e a nonce expirado em cache. Ver register_rest().
		add_action( 'rest_api_init', array( __CLASS__, 'register_rest' ) );

		// Painel (somente admin).
		add_action( 'wp_ajax_ot_report', array( __CLASS__, 'report' ) );
		add_action( 'wp_ajax_ot_log', array( __CLASS__, 'log' ) );
		add_action( 'wp_ajax_ot_save_goals', array( __CLASS__, 'save_goals' ) );
		add_action( 'wp_ajax_ot_save_settings', array( __CLASS__, 'save_settings' ) );
		add_action( 'wp_ajax_ot_reset_data', array( __CLASS__, 'reset_data' ) );
		add_action( 'wp_ajax_ot_blocklist_add', array( __CLASS__, 'blocklist_add' ) );
		add_action( 'wp_ajax_ot_blocklist_remove', array( __CLASS__, 'blocklist_remove' ) );
		add_action( 'wp_ajax_ot_blocklist_get', array( __CLASS__, 'blocklist_get' ) );
	}

	/** Registra um pageview vindo do beacon. */
	public static function hit() {
		check_ajax_referer( 'ot_track', 'nonce' );

		$data = self::raw_body();
		$res  = OT_Tracker::record_pageview( $data );
		if ( empty( $res['ok'] ) ) {
			wp_send_json_error( array(), 200 );
		}
		wp_send_json_success( array( 'hit' => isset( $res['hit'] ) ? $res['hit'] : 0 ) );
	}

	/** Atualiza o tempo de permanência (engajamento). */
	public static function ping() {
		check_ajax_referer( 'ot_track', 'nonce' );

		$data = self::raw_body();
		OT_Tracker::record_engagement( $data );
		wp_send_json_success();
	}

	/** Registra um clique em link de saída vindo do beacon. */
	public static function outbound() {
		check_ajax_referer( 'ot_track', 'nonce' );

		$data = self::raw_body();
		OT_Tracker::record_outbound( $data );
		wp_send_json_success();
	}

	/**
	 * Registra a rota REST pública de coleta.
	 *
	 * Motivação (alinhamento com o SlimStat / precisão de contagem):
	 *   - Ad-blockers: `admin-ajax.php?action=ot_hit` é alvo clássico de
	 *     uBlock/EasyPrivacy; os hits são bloqueados no navegador e nunca chegam
	 *     ao PHP → o Orbit Track contava MENOS que o SlimStat. Uma rota sob
	 *     `/wp-json/` com slug neutro (sem "action=track") raramente é filtrada.
	 *     O SlimStat coleta exatamente assim (rota `slimstat/v1/hit`).
	 *   - Cache de página: o beacon antigo exigia nonce (`check_ajax_referer`).
	 *     Em páginas servidas de cache para visitantes anônimos, o nonce embutido
	 *     no HTML expira (12–24h) e o hit é rejeitado silenciosamente → perda de
	 *     contagem. O SlimStat usa `permission_callback => __return_true` e NÃO
	 *     exige nonce no endpoint de tracking, justamente para sobreviver ao cache.
	 *     Registrar um pageview não é ação sensível a CSRF (o pior caso é alguém
	 *     inflar a própria analytics); os dados continuam validados/sanitizados em
	 *     OT_Tracker::record_pageview(). Adotamos o mesmo trade-off aqui.
	 *
	 * O caminho admin-ajax (com nonce) é mantido como fallback para quando a
	 * rota REST estiver indisponível.
	 */
	public static function register_rest() {
		register_rest_route( 'orbit-track/v1', '/collect', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'rest_collect' ),
			'permission_callback' => '__return_true',
		) );
	}

	/**
	 * Handler REST unificado do beacon. Despacha pelo campo "t" (hit|ping|out).
	 *
	 * @param WP_REST_Request $request Requisição REST.
	 * @return WP_REST_Response
	 */
	public static function rest_collect( WP_REST_Request $request ) {
		$data = $request->get_json_params();
		if ( ! is_array( $data ) ) {
			$data = $request->get_params();
		}

		$type = isset( $data['t'] ) ? sanitize_key( $data['t'] ) : 'hit';

		if ( 'ping' === $type ) {
			OT_Tracker::record_engagement( $data );
			return rest_ensure_response( array( 'ok' => true ) );
		}
		if ( 'out' === $type ) {
			OT_Tracker::record_outbound( $data );
			return rest_ensure_response( array( 'ok' => true ) );
		}

		$res = OT_Tracker::record_pageview( $data );
		return rest_ensure_response( array(
			'ok'  => ! empty( $res['ok'] ),
			'hit' => isset( $res['hit'] ) ? (int) $res['hit'] : 0,
		) );
	}

	/** Devolve o log de acessos ao vivo + contagem de online agora. */
	public static function log() {
		self::guard_admin();

		$limit  = isset( $_POST['limit'] ) ? (int) $_POST['limit'] : 30;
		$offset = isset( $_POST['offset'] ) ? (int) $_POST['offset'] : 0;
		$since  = isset( $_POST['since'] ) ? (int) $_POST['since'] : 0;

		wp_send_json_success( array(
			'rows'   => OT_Stats::access_log( $limit, $offset, $since ),
			'online' => OT_Stats::online_now(),
		) );
	}

	/** Cria/atualiza as metas de conversão. */
	public static function save_goals() {
		self::guard_admin();

		$input = isset( $_POST['goals'] ) && is_array( $_POST['goals'] )
			? wp_unslash( $_POST['goals'] )
			: array();
		$saved = OT_Goals::save( $input );
		wp_send_json_success( $saved );
	}

	/** Devolve os dados agregados para o painel. */
	public static function report() {
		self::guard_admin();

		$range = isset( $_POST['range'] ) ? sanitize_key( wp_unslash( $_POST['range'] ) ) : '28d';
		$start = isset( $_POST['start'] ) ? sanitize_text_field( wp_unslash( $_POST['start'] ) ) : '';
		$end   = isset( $_POST['end'] ) ? sanitize_text_field( wp_unslash( $_POST['end'] ) ) : '';

		wp_send_json_success( OT_Stats::report( $range, $start, $end ) );
	}

	/** Salva as configurações. */
	public static function save_settings() {
		self::guard_admin();

		$input = isset( $_POST['settings'] ) && is_array( $_POST['settings'] )
			? wp_unslash( $_POST['settings'] )
			: array();
		$saved = OT_Settings::save( $input );
		wp_send_json_success( $saved );
	}

	/** Zera todos os dados de tracking. */
	public static function reset_data() {
		self::guard_admin();
		OT_DB::truncate();
		wp_send_json_success();
	}

	/** Adiciona um IP à blacklist (por session_db_id ou por IP manual). */
	public static function blocklist_add() {
		self::guard_admin();

		$reason = isset( $_POST['reason'] ) ? sanitize_text_field( wp_unslash( $_POST['reason'] ) ) : '';

		// Via ID de sessão (botão no log — IP nunca exposto ao cliente).
		if ( ! empty( $_POST['session_db_id'] ) ) {
			$ok = OT_Blocklist::add_by_session( (int) $_POST['session_db_id'], $reason );
			if ( $ok ) {
				wp_send_json_success( array( 'entries' => OT_Blocklist::all() ) );
			}
			wp_send_json_error( array( 'message' => 'IP não encontrado ou inválido.' ) );
		}

		// Via IP digitado manualmente.
		$ip = isset( $_POST['ip'] ) ? sanitize_text_field( wp_unslash( $_POST['ip'] ) ) : '';
		if ( OT_Blocklist::add( $ip, $reason ) ) {
			wp_send_json_success( array( 'entries' => OT_Blocklist::all() ) );
		}
		wp_send_json_error( array( 'message' => 'IP inválido.' ) );
	}

	/** Remove um IP da blacklist pelo ID da linha. */
	public static function blocklist_remove() {
		self::guard_admin();
		$id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
		if ( $id ) {
			OT_Blocklist::remove( $id );
		}
		wp_send_json_success( array( 'entries' => OT_Blocklist::all() ) );
	}

	/** Lista todos os IPs bloqueados. */
	public static function blocklist_get() {
		self::guard_admin();
		wp_send_json_success( array( 'entries' => OT_Blocklist::all() ) );
	}

	/** Nonce + capacidade para endpoints do painel. */
	private static function guard_admin() {
		check_ajax_referer( 'ot_admin', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'forbidden' ), 403 );
		}
	}

	/**
	 * Lê o corpo do beacon. Aceita JSON (sendBeacon) ou form-encoded.
	 *
	 * @return array
	 */
	private static function raw_body() {
		$raw = file_get_contents( 'php://input' );
		if ( $raw ) {
			$json = json_decode( $raw, true );
			if ( is_array( $json ) ) {
				return $json;
			}
		}
		return is_array( $_POST ) ? wp_unslash( $_POST ) : array();
	}
}
