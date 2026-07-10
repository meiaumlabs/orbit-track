<?php
/**
 * OT_Admin — menu, assets e render das páginas do painel.
 *
 * @package OrbitTrack
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OT_Admin {

	private static $hook = '';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
	}

	public static function menu() {
		self::$hook = add_menu_page(
			'Orbit Track',
			'Orbit Track',
			'manage_options',
			'orbit-track',
			array( __CLASS__, 'render' ),
			'data:image/svg+xml;base64,' . base64_encode( '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="#a7aaad" stroke-width="2"><circle cx="12" cy="12" r="2.5" fill="#a7aaad" stroke="none"/><ellipse cx="12" cy="12" rx="10" ry="4.5"/><ellipse cx="12" cy="12" rx="10" ry="4.5" transform="rotate(60 12 12)"/><ellipse cx="12" cy="12" rx="10" ry="4.5" transform="rotate(120 12 12)"/></svg>' ),
			26
		);
	}

	public static function assets( $hook ) {
		if ( $hook !== self::$hook ) {
			return;
		}
		wp_enqueue_style( 'ot-fonts', 'https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;600;700&family=Inter:wght@400;500;600&display=swap', array(), OT_VERSION );
		// Mapa-múndi de visitantes (jsVectorMap — MIT, sem custos).
		wp_enqueue_style( 'ot-jsvectormap', 'https://cdn.jsdelivr.net/npm/jsvectormap@1.5.3/dist/css/jsvectormap.min.css', array(), '1.5.3' );
		wp_enqueue_style( 'ot-app', OT_URL . 'admin/css/app.css', array(), OT_VERSION );
		wp_enqueue_script( 'ot-chart', 'https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js', array(), '4.4.1', true );
		wp_enqueue_script( 'ot-jsvectormap', 'https://cdn.jsdelivr.net/npm/jsvectormap@1.5.3/dist/js/jsvectormap.min.js', array(), '1.5.3', true );
		wp_enqueue_script( 'ot-jsvectormap-world', 'https://cdn.jsdelivr.net/npm/jsvectormap@1.5.3/dist/maps/world.js', array( 'ot-jsvectormap' ), '1.5.3', true );
		wp_enqueue_script( 'ot-app', OT_URL . 'admin/js/app.js', array( 'ot-chart', 'ot-jsvectormap-world' ), OT_VERSION, true );

		wp_localize_script( 'ot-app', 'OT', array(
			'ajax'      => admin_url( 'admin-ajax.php' ),
			'nonce'     => wp_create_nonce( 'ot_admin' ),
			'channels'  => OT_Source::channel_labels(),
			'goals'     => OT_Goals::all(),
			'i18n'     => array(
				'loading'  => __( 'Carregando…', 'orbit-track' ),
				'error'    => __( 'Erro ao carregar os dados.', 'orbit-track' ),
				'empty'    => __( 'Sem dados neste período.', 'orbit-track' ),
				'sessions' => __( 'Sessões', 'orbit-track' ),
				'pageviews'=> __( 'Visualizações', 'orbit-track' ),
				'saved'    => __( 'Configurações salvas.', 'orbit-track' ),
				'goalsSaved' => __( 'Metas salvas.', 'orbit-track' ),
				'confirmReset' => __( 'Apagar TODOS os dados de tracking? Esta ação não pode ser desfeita.', 'orbit-track' ),
				'online'   => __( 'online agora', 'orbit-track' ),
				'live'     => __( 'Ao vivo', 'orbit-track' ),
				'paused'   => __( 'Pausado', 'orbit-track' ),
			),
		) );
	}

	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$tab = isset( $_GET['ot-tab'] ) ? sanitize_key( wp_unslash( $_GET['ot-tab'] ) ) : 'dashboard';
		?>
		<div class="wrap ot-wrap">
			<div class="ot-header">
				<div class="ot-brand">
					<span class="ot-logo" aria-hidden="true">
						<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6">
							<circle cx="12" cy="12" r="2.4" fill="currentColor" stroke="none"/>
							<ellipse cx="12" cy="12" rx="10" ry="4.4"/>
							<ellipse cx="12" cy="12" rx="10" ry="4.4" transform="rotate(60 12 12)"/>
							<ellipse cx="12" cy="12" rx="10" ry="4.4" transform="rotate(120 12 12)"/>
						</svg>
					</span>
					<div>
						<h1>Orbit Track</h1>
						<p><?php esc_html_e( 'Tracking orgânico e de anúncios — origem, páginas, tempo, região e dispositivo.', 'orbit-track' ); ?></p>
					</div>
				</div>
				<div class="ot-actions">
					<span class="ot-badge ot-badge-live"><?php esc_html_e( 'Coletando dados', 'orbit-track' ); ?></span>
				</div>
			</div>

			<nav class="ot-tabs">
				<a class="ot-tab <?php echo 'dashboard' === $tab ? 'is-active' : ''; ?>" href="<?php echo esc_url( admin_url( 'admin.php?page=orbit-track&ot-tab=dashboard' ) ); ?>"><?php esc_html_e( 'Painel', 'orbit-track' ); ?></a>
				<a class="ot-tab <?php echo 'live' === $tab ? 'is-active' : ''; ?>" href="<?php echo esc_url( admin_url( 'admin.php?page=orbit-track&ot-tab=live' ) ); ?>"><?php esc_html_e( 'Ao vivo', 'orbit-track' ); ?></a>
				<a class="ot-tab <?php echo 'acquisition' === $tab ? 'is-active' : ''; ?>" href="<?php echo esc_url( admin_url( 'admin.php?page=orbit-track&ot-tab=acquisition' ) ); ?>"><?php esc_html_e( 'Aquisição', 'orbit-track' ); ?></a>
				<a class="ot-tab <?php echo 'audience' === $tab ? 'is-active' : ''; ?>" href="<?php echo esc_url( admin_url( 'admin.php?page=orbit-track&ot-tab=audience' ) ); ?>"><?php esc_html_e( 'Público', 'orbit-track' ); ?></a>
				<a class="ot-tab <?php echo 'content' === $tab ? 'is-active' : ''; ?>" href="<?php echo esc_url( admin_url( 'admin.php?page=orbit-track&ot-tab=content' ) ); ?>"><?php esc_html_e( 'Conteúdo', 'orbit-track' ); ?></a>
				<a class="ot-tab <?php echo 'goals' === $tab ? 'is-active' : ''; ?>" href="<?php echo esc_url( admin_url( 'admin.php?page=orbit-track&ot-tab=goals' ) ); ?>"><?php esc_html_e( 'Metas', 'orbit-track' ); ?></a>
				<a class="ot-tab <?php echo 'settings' === $tab ? 'is-active' : ''; ?>" href="<?php echo esc_url( admin_url( 'admin.php?page=orbit-track&ot-tab=settings' ) ); ?>"><?php esc_html_e( 'Configurações', 'orbit-track' ); ?></a>
			</nav>

			<?php if ( 'settings' === $tab ) : ?>
				<?php self::render_settings(); ?>
			<?php else : ?>
				<?php if ( 'live' !== $tab ) : ?>
				<div class="ot-toolbar">
					<div class="ot-range" role="tablist">
						<button class="ot-range-btn" data-days="7">7d</button>
						<button class="ot-range-btn is-active" data-days="28">28d</button>
						<button class="ot-range-btn" data-days="90">90d</button>
						<button class="ot-range-btn" data-days="365">365d</button>
					</div>
					<div class="ot-datefilter">
						<label><?php esc_html_e( 'De', 'orbit-track' ); ?> <input type="date" id="ot-date-start"></label>
						<label><?php esc_html_e( 'até', 'orbit-track' ); ?> <input type="date" id="ot-date-end"></label>
						<button class="ot-btn ot-btn-ghost" id="ot-apply-dates"><?php esc_html_e( 'Aplicar', 'orbit-track' ); ?></button>
					</div>
				</div>
				<?php endif; ?>
				<div id="ot-view" data-tab="<?php echo esc_attr( $tab ); ?>">
					<div class="ot-loading"><?php esc_html_e( 'Carregando…', 'orbit-track' ); ?></div>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	private static function render_settings() {
		$s     = OT_Settings::all();
		$roles = get_editable_roles();
		?>
		<div class="ot-card ot-settings">
			<h3><?php esc_html_e( 'Configurações de coleta', 'orbit-track' ); ?></h3>

			<div class="ot-field">
				<label><?php esc_html_e( 'Não rastrear estes papéis (quando logados)', 'orbit-track' ); ?></label>
				<div class="ot-checks">
					<?php foreach ( $roles as $key => $role ) : ?>
						<label class="ot-check">
							<input type="checkbox" class="ot-role" value="<?php echo esc_attr( $key ); ?>" <?php checked( in_array( $key, (array) $s['exclude_roles'], true ) ); ?>>
							<span><?php echo esc_html( translate_user_role( $role['name'] ) ); ?></span>
						</label>
					<?php endforeach; ?>
				</div>
			</div>

			<label class="ot-switch">
				<input type="checkbox" id="ot-exclude-bots" <?php checked( $s['exclude_bots'] ); ?>>
				<span><?php esc_html_e( 'Ignorar bots e crawlers conhecidos', 'orbit-track' ); ?></span>
			</label>
			<label class="ot-switch">
				<input type="checkbox" id="ot-geo-enabled" <?php checked( $s['geo_enabled'] ); ?>>
				<span><?php esc_html_e( 'Geolocalização por IP (país / estado / cidade)', 'orbit-track' ); ?></span>
			</label>
			<label class="ot-switch">
				<input type="checkbox" id="ot-respect-dnt" <?php checked( $s['respect_dnt'] ); ?>>
				<span><?php esc_html_e( 'Respeitar o cabeçalho "Do Not Track" do navegador', 'orbit-track' ); ?></span>
			</label>

			<div class="ot-field-row">
				<div class="ot-field">
					<label for="ot-retention"><?php esc_html_e( 'Reter dados por (dias, 0 = sempre)', 'orbit-track' ); ?></label>
					<input type="number" id="ot-retention" min="0" value="<?php echo esc_attr( $s['retention_days'] ); ?>">
				</div>
				<div class="ot-field">
					<label for="ot-timeout"><?php esc_html_e( 'Tempo de sessão (min)', 'orbit-track' ); ?></label>
					<input type="number" id="ot-timeout" min="1" value="<?php echo esc_attr( $s['session_timeout'] ); ?>">
				</div>
			</div>

			<p class="ot-muted"><?php esc_html_e( 'Privacidade: o Orbit Track é cookieless e nunca armazena o endereço IP — apenas um identificador anônimo com hash e o resultado geográfico agregado.', 'orbit-track' ); ?></p>

			<div class="ot-field-actions">
				<button class="ot-btn ot-btn-primary" id="ot-save-settings"><?php esc_html_e( 'Salvar configurações', 'orbit-track' ); ?></button>
				<button class="ot-btn ot-btn-danger" id="ot-reset-data"><?php esc_html_e( 'Apagar todos os dados', 'orbit-track' ); ?></button>
				<span class="ot-save-msg" id="ot-settings-msg"></span>
			</div>
		</div>
		<?php
	}
}
