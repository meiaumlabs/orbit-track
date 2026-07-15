<?php
/**
 * Plugin Name:       Orbit Track — Tracking Orgânico & Anúncios
 * Plugin URI:        https://61labs.com.br/orbit-track
 * Description:       Tracking profissional e independente para WordPress. Mapeia todos os acessos do site — origem (orgânico, anúncios, social, e-mail, referência), páginas visitadas, tempo de permanência, região (país/estado/cidade), dispositivo, navegador e sistema operacional — direto no painel, sem depender de serviços externos. Compatível com cache e cookieless. Desenvolvido pela 61 Labs.
 * Version:           1.2.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            61 Labs
 * Author URI:        https://61labs.com.br
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       orbit-track
 * Domain Path:       /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'OT_VERSION', '1.2.0' );
define( 'OT_FILE', __FILE__ );
define( 'OT_DIR', plugin_dir_path( __FILE__ ) );
define( 'OT_URL', plugin_dir_url( __FILE__ ) );

/*
 * URL do repositório público no GitHub usado para atualizações automáticas.
 * Ajuste OWNER/REPO para o repositório real antes do primeiro push.
 */
define( 'OT_GITHUB_URL', 'https://github.com/meiaumlabs/orbit-track/' );

/*
 * ------------------------------------------------------------------
 * Atualização automática via GitHub (Plugin Update Checker).
 * Fluxo de release: publique uma Release com a tag "vX.Y.Z" (ex.: v1.0.0)
 * e anexe o ZIP do plugin como asset. O WordPress detecta e oferece a
 * atualização no painel, igual a um plugin do repositório oficial.
 * ------------------------------------------------------------------
 */
require_once OT_DIR . 'includes/plugin-update-checker/plugin-update-checker.php';

$ot_update_checker = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
	OT_GITHUB_URL,
	OT_FILE,
	'orbit-track'
);
// Usa o ZIP anexado nas Releases do GitHub como pacote de atualização.
$ot_update_checker->getVcsApi()->enableReleaseAssets();

require_once OT_DIR . 'includes/class-ot-db.php';
require_once OT_DIR . 'includes/class-ot-settings.php';
require_once OT_DIR . 'includes/class-ot-ua.php';
require_once OT_DIR . 'includes/class-ot-source.php';
require_once OT_DIR . 'includes/class-ot-geo.php';
require_once OT_DIR . 'includes/class-ot-tracker.php';
require_once OT_DIR . 'includes/class-ot-goals.php';
require_once OT_DIR . 'includes/class-ot-stats.php';
require_once OT_DIR . 'includes/class-ot-ajax.php';
require_once OT_DIR . 'includes/class-ot-admin.php';

/**
 * Ativação: cria as tabelas e grava as opções padrão.
 */
function ot_activate() {
	OT_DB::install();
	OT_Settings::install_defaults();
	update_option( 'ot_db_version', OT_DB::DB_VERSION );
}
register_activation_hook( __FILE__, 'ot_activate' );

/**
 * Desativação: limpa eventos agendados.
 */
function ot_deactivate() {
	wp_clear_scheduled_hook( 'ot_daily_cleanup' );
}
register_deactivation_hook( __FILE__, 'ot_deactivate' );

add_action( 'plugins_loaded', function () {
	load_plugin_textdomain( 'orbit-track', false, dirname( plugin_basename( OT_FILE ) ) . '/languages' );

	// Migração de schema quando a versão muda.
	if ( get_option( 'ot_db_version' ) !== OT_DB::DB_VERSION ) {
		OT_DB::install();
		update_option( 'ot_db_version', OT_DB::DB_VERSION );
	}

	OT_Tracker::init();
	OT_Ajax::init();
	if ( is_admin() ) {
		OT_Admin::init();
	}
} );

// Rotina diária de manutenção (retenção de dados).
add_action( 'ot_daily_cleanup', array( 'OT_DB', 'cleanup' ) );
add_action( 'init', function () {
	if ( ! wp_next_scheduled( 'ot_daily_cleanup' ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'ot_daily_cleanup' );
	}
} );
