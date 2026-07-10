<?php
/**
 * Desinstalação do Orbit Track — remove tabelas, opções e agendamentos.
 *
 * @package OrbitTrack
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

// Remove as tabelas de dados.
$tables = array( $wpdb->prefix . 'ot_sessions', $wpdb->prefix . 'ot_hits' );
foreach ( $tables as $t ) {
	$wpdb->query( "DROP TABLE IF EXISTS {$t}" ); // phpcs:ignore WordPress.DB
}

// Remove as opções.
delete_option( 'ot_settings' );
delete_option( 'ot_db_version' );
delete_option( 'ot_visitor_salt' );

// Limpa o agendamento de manutenção.
wp_clear_scheduled_hook( 'ot_daily_cleanup' );

// Remove os transients de geolocalização em cache.
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_ot_geo_%' OR option_name LIKE '_transient_timeout_ot_geo_%'" ); // phpcs:ignore WordPress.DB
