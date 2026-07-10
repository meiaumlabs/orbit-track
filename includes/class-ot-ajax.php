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
		// Beacon público (logados e não logados).
		add_action( 'wp_ajax_ot_hit', array( __CLASS__, 'hit' ) );
		add_action( 'wp_ajax_nopriv_ot_hit', array( __CLASS__, 'hit' ) );
		add_action( 'wp_ajax_ot_ping', array( __CLASS__, 'ping' ) );
		add_action( 'wp_ajax_nopriv_ot_ping', array( __CLASS__, 'ping' ) );
		add_action( 'wp_ajax_ot_out', array( __CLASS__, 'outbound' ) );
		add_action( 'wp_ajax_nopriv_ot_out', array( __CLASS__, 'outbound' ) );

		// Painel (somente admin).
		add_action( 'wp_ajax_ot_report', array( __CLASS__, 'report' ) );
		add_action( 'wp_ajax_ot_log', array( __CLASS__, 'log' ) );
		add_action( 'wp_ajax_ot_save_goals', array( __CLASS__, 'save_goals' ) );
		add_action( 'wp_ajax_ot_save_settings', array( __CLASS__, 'save_settings' ) );
		add_action( 'wp_ajax_ot_reset_data', array( __CLASS__, 'reset_data' ) );
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
