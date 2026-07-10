<?php
/**
 * OT_DB — criação e manutenção do schema (tabelas de sessões e pageviews).
 *
 * @package OrbitTrack
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OT_DB {

	/** Incremente ao alterar o schema para disparar migração. */
	const DB_VERSION = '1.0.0';

	/** @return string Nome da tabela de sessões (visitas). */
	public static function sessions_table() {
		global $wpdb;
		return $wpdb->prefix . 'ot_sessions';
	}

	/** @return string Nome da tabela de pageviews (hits). */
	public static function hits_table() {
		global $wpdb;
		return $wpdb->prefix . 'ot_hits';
	}

	/**
	 * Cria/atualiza as tabelas via dbDelta.
	 */
	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();
		$sessions = self::sessions_table();
		$hits     = self::hits_table();

		// Uma linha por visita (sessão). Guarda a atribuição da origem (first touch da sessão).
		$sql_sessions = "CREATE TABLE {$sessions} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			session_uid CHAR(36) NOT NULL,
			visitor_hash CHAR(40) NOT NULL,
			channel VARCHAR(30) NOT NULL DEFAULT 'direct',
			source VARCHAR(191) NOT NULL DEFAULT '',
			medium VARCHAR(100) NOT NULL DEFAULT '',
			campaign VARCHAR(191) NOT NULL DEFAULT '',
			term VARCHAR(191) NOT NULL DEFAULT '',
			content VARCHAR(191) NOT NULL DEFAULT '',
			referrer VARCHAR(255) NOT NULL DEFAULT '',
			landing_page VARCHAR(255) NOT NULL DEFAULT '',
			exit_page VARCHAR(255) NOT NULL DEFAULT '',
			device_type VARCHAR(20) NOT NULL DEFAULT '',
			browser VARCHAR(60) NOT NULL DEFAULT '',
			os VARCHAR(60) NOT NULL DEFAULT '',
			country_code CHAR(2) NOT NULL DEFAULT '',
			country VARCHAR(80) NOT NULL DEFAULT '',
			region VARCHAR(120) NOT NULL DEFAULT '',
			city VARCHAR(120) NOT NULL DEFAULT '',
			is_new_visitor TINYINT(1) NOT NULL DEFAULT 1,
			pageviews INT UNSIGNED NOT NULL DEFAULT 0,
			duration INT UNSIGNED NOT NULL DEFAULT 0,
			is_bounce TINYINT(1) NOT NULL DEFAULT 1,
			started_at DATETIME NOT NULL,
			last_seen DATETIME NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY session_uid (session_uid),
			KEY visitor_hash (visitor_hash),
			KEY channel (channel),
			KEY started_at (started_at),
			KEY country_code (country_code),
			KEY device_type (device_type)
		) {$charset};";

		// Uma linha por pageview.
		$sql_hits = "CREATE TABLE {$hits} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			session_uid CHAR(36) NOT NULL,
			visitor_hash CHAR(40) NOT NULL,
			url VARCHAR(255) NOT NULL DEFAULT '',
			path VARCHAR(191) NOT NULL DEFAULT '',
			title VARCHAR(255) NOT NULL DEFAULT '',
			post_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			referrer VARCHAR(255) NOT NULL DEFAULT '',
			channel VARCHAR(30) NOT NULL DEFAULT 'direct',
			device_type VARCHAR(20) NOT NULL DEFAULT '',
			country_code CHAR(2) NOT NULL DEFAULT '',
			time_on_page INT UNSIGNED NOT NULL DEFAULT 0,
			is_entry TINYINT(1) NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			KEY session_uid (session_uid),
			KEY path (path),
			KEY post_id (post_id),
			KEY channel (channel),
			KEY created_at (created_at)
		) {$charset};";

		dbDelta( $sql_sessions );
		dbDelta( $sql_hits );
	}

	/**
	 * Remove dados mais antigos que o período de retenção configurado.
	 */
	public static function cleanup() {
		global $wpdb;
		$days = (int) OT_Settings::get( 'retention_days' );
		if ( $days <= 0 ) {
			return; // 0 = manter para sempre.
		}
		$sessions = self::sessions_table();
		$hits     = self::hits_table();
		$cutoff   = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );

		$wpdb->query( $wpdb->prepare( "DELETE FROM {$sessions} WHERE started_at < %s", $cutoff ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$hits} WHERE created_at < %s", $cutoff ) );
	}

	/**
	 * Zera todos os dados de tracking (usado no botão "Limpar dados").
	 */
	public static function truncate() {
		global $wpdb;
		$wpdb->query( 'TRUNCATE TABLE ' . self::sessions_table() );
		$wpdb->query( 'TRUNCATE TABLE ' . self::hits_table() );
	}
}
