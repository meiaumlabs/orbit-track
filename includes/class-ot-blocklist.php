<?php
/**
 * OT_Blocklist — lista de IPs bloqueados (blacklist).
 *
 * Bloqueia visitantes por IP antes que qualquer conteúdo seja servido.
 * Nota: em sites com cache de página completa (WP Rocket, W3TC, etc.),
 * páginas já cacheadas são servidas pelo servidor antes do WordPress
 * carregar — nesse caso um bloqueio completo requer regra no servidor
 * (nginx/apache). O bloqueio aqui é eficaz para requisições não-cacheadas
 * e para o endpoint de tracking (REST/ajax).
 *
 * @package OrbitTrack
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OT_Blocklist {

	/** @return string Nome da tabela. */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'ot_blocklist';
	}

	/** Cria a tabela via dbDelta. */
	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		$t       = self::table();
		dbDelta( "CREATE TABLE {$t} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			ip_address VARCHAR(45) NOT NULL,
			reason VARCHAR(255) NOT NULL DEFAULT '',
			added_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY ip_address (ip_address)
		) {$charset};" );
	}

	/** Registra o hook que bloqueia visitantes na blacklist. */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'maybe_block' ), 1 );
	}

	/** Bloqueia o acesso se o IP do visitante estiver na lista. */
	public static function maybe_block() {
		if ( is_admin() ) {
			return;
		}
		$ip = OT_Geo::client_ip();
		if ( $ip && self::is_blocked( $ip ) ) {
			status_header( 403 );
			nocache_headers();
			wp_die(
				esc_html__( 'Acesso negado.', 'orbit-track' ),
				esc_html__( 'Acesso negado', 'orbit-track' ),
				array( 'response' => 403 )
			);
		}
	}

	/**
	 * Verifica se um IP está na blacklist.
	 *
	 * @param string $ip Endereço IP.
	 * @return bool
	 */
	public static function is_blocked( $ip ) {
		global $wpdb;
		return (bool) $wpdb->get_var( $wpdb->prepare(
			'SELECT id FROM ' . self::table() . ' WHERE ip_address = %s',
			$ip
		) );
	}

	/**
	 * Adiciona um IP à blacklist.
	 *
	 * @param string $ip     Endereço IP (IPv4 ou IPv6).
	 * @param string $reason Motivo (opcional, exibido no painel).
	 * @return bool
	 */
	public static function add( $ip, $reason = '' ) {
		global $wpdb;
		if ( ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return false;
		}
		return (bool) $wpdb->replace( self::table(), array(
			'ip_address' => $ip,
			'reason'     => sanitize_text_field( $reason ),
			'added_at'   => current_time( 'mysql' ),
		) );
	}

	/**
	 * Adiciona à blacklist via ID da sessão no banco (busca o IP armazenado).
	 * Usado pelo botão "Bloquear" no log ao vivo — nunca expõe o IP ao cliente.
	 *
	 * @param int    $session_db_id PK da linha em ot_sessions.
	 * @param string $reason        Motivo.
	 * @return bool
	 */
	public static function add_by_session( $session_db_id, $reason = '' ) {
		global $wpdb;
		$s  = OT_DB::sessions_table();
		$ip = $wpdb->get_var( $wpdb->prepare(
			"SELECT ip_address FROM {$s} WHERE id = %d",
			(int) $session_db_id
		) );
		if ( ! $ip ) {
			return false;
		}
		return self::add( $ip, $reason );
	}

	/**
	 * Remove um IP da blacklist pelo ID da linha.
	 *
	 * @param int $id PK da linha em ot_blocklist.
	 */
	public static function remove( $id ) {
		global $wpdb;
		$wpdb->delete( self::table(), array( 'id' => (int) $id ) );
	}

	/**
	 * Lista todos os IPs bloqueados.
	 *
	 * @return array
	 */
	public static function all() {
		global $wpdb;
		return $wpdb->get_results(
			'SELECT id, ip_address, reason, added_at FROM ' . self::table() . ' ORDER BY added_at DESC',
			ARRAY_A
		) ?: array();
	}
}
