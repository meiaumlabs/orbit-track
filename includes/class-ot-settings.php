<?php
/**
 * OT_Settings — opções do plugin.
 *
 * @package OrbitTrack
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OT_Settings {

	const OPT = 'ot_settings';

	/** @return array Opções padrão. */
	public static function defaults() {
		return array(
			'exclude_roles'   => array( 'administrator', 'editor' ), // Não rastreia estes papéis logados.
			'exclude_bots'    => 1,      // Ignora user agents de bots conhecidos.
			'respect_dnt'     => 0,      // Respeitar cabeçalho Do Not Track.
			'geo_enabled'     => 1,      // Ativa geolocalização por IP.
			'anonymize_ip'    => 1,      // Nunca armazena o IP; usa hash com salt.
			'retention_days'  => 365,    // Dias para manter os dados (0 = para sempre).
			'session_timeout' => 30,     // Minutos de inatividade que encerram a sessão.
		);
	}

	/** Grava os padrões na ativação (sem sobrescrever o que já existe). */
	public static function install_defaults() {
		$existing = get_option( self::OPT );
		if ( ! is_array( $existing ) ) {
			add_option( self::OPT, self::defaults() );
		} else {
			update_option( self::OPT, array_merge( self::defaults(), $existing ) );
		}
		// Salt estável para o hash de visitante (privacidade).
		if ( ! get_option( 'ot_visitor_salt' ) ) {
			add_option( 'ot_visitor_salt', wp_generate_password( 32, false, false ), '', 'no' );
		}
	}

	/** @return array Todas as opções (mescladas com os padrões). */
	public static function all() {
		$opt = get_option( self::OPT );
		return is_array( $opt ) ? array_merge( self::defaults(), $opt ) : self::defaults();
	}

	/**
	 * @param string $key Chave da opção.
	 * @return mixed
	 */
	public static function get( $key ) {
		$all = self::all();
		return isset( $all[ $key ] ) ? $all[ $key ] : null;
	}

	/**
	 * Persiste um conjunto de opções sanitizadas.
	 *
	 * @param array $input Valores vindos do formulário.
	 */
	public static function save( array $input ) {
		$d    = self::defaults();
		$out  = self::all();

		$roles = get_editable_roles();
		$out['exclude_roles'] = array();
		if ( ! empty( $input['exclude_roles'] ) && is_array( $input['exclude_roles'] ) ) {
			foreach ( $input['exclude_roles'] as $r ) {
				$r = sanitize_key( $r );
				if ( isset( $roles[ $r ] ) ) {
					$out['exclude_roles'][] = $r;
				}
			}
		}

		$out['exclude_bots']    = empty( $input['exclude_bots'] ) ? 0 : 1;
		$out['respect_dnt']     = empty( $input['respect_dnt'] ) ? 0 : 1;
		$out['geo_enabled']     = empty( $input['geo_enabled'] ) ? 0 : 1;
		$out['anonymize_ip']    = empty( $input['anonymize_ip'] ) ? 0 : 1;
		$out['retention_days']  = isset( $input['retention_days'] ) ? max( 0, (int) $input['retention_days'] ) : $d['retention_days'];
		$out['session_timeout'] = isset( $input['session_timeout'] ) ? max( 1, (int) $input['session_timeout'] ) : $d['session_timeout'];

		update_option( self::OPT, $out );
		return $out;
	}
}
