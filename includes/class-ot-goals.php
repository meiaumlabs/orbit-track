<?php
/**
 * OT_Goals — metas de conversão (inspirado no recurso "Goals" do SlimStat).
 *
 * Uma meta é atingida quando, dentro de uma sessão, existe um pageview cujo
 * caminho (path) casa com o critério configurado. Diferente do SlimStat, aqui
 * não há limite de metas — o recurso é livre e sem custos.
 *
 * As metas ficam guardadas na opção `ot_goals` (array de arrays). Cada meta:
 *   id         => string curta única
 *   name       => rótulo amigável
 *   match_type => 'contains' | 'exact' | 'starts'
 *   value      => caminho a comparar (ex.: '/obrigado')
 *   active     => 0|1
 *
 * @package OrbitTrack
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OT_Goals {

	const OPT = 'ot_goals';

	/** @return array Lista de metas cadastradas. */
	public static function all() {
		$goals = get_option( self::OPT );
		return is_array( $goals ) ? array_values( $goals ) : array();
	}

	/** @return array Somente as metas ativas. */
	public static function active() {
		return array_values( array_filter( self::all(), function ( $g ) {
			return ! empty( $g['active'] );
		} ) );
	}

	/**
	 * Substitui a lista de metas por uma versão sanitizada.
	 *
	 * @param array $input Lista crua vinda do formulário.
	 * @return array Lista salva.
	 */
	public static function save( array $input ) {
		$allowed_match = array( 'contains', 'exact', 'starts' );
		$out = array();

		foreach ( $input as $g ) {
			if ( ! is_array( $g ) ) {
				continue;
			}
			$name  = isset( $g['name'] ) ? sanitize_text_field( wp_unslash( $g['name'] ) ) : '';
			$value = isset( $g['value'] ) ? sanitize_text_field( wp_unslash( $g['value'] ) ) : '';
			$value = trim( $value );
			if ( '' === $name || '' === $value ) {
				continue;
			}
			$match = isset( $g['match_type'] ) ? sanitize_key( $g['match_type'] ) : 'contains';
			if ( ! in_array( $match, $allowed_match, true ) ) {
				$match = 'contains';
			}
			$id = isset( $g['id'] ) ? preg_replace( '/[^a-z0-9]/', '', strtolower( (string) $g['id'] ) ) : '';
			if ( strlen( $id ) < 4 ) {
				$id = substr( md5( $name . '|' . $value . '|' . wp_rand() ), 0, 10 );
			}
			$out[] = array(
				'id'         => $id,
				'name'       => $name,
				'match_type' => $match,
				'value'      => substr( $value, 0, 191 ),
				'active'     => empty( $g['active'] ) ? 0 : 1,
			);
		}

		update_option( self::OPT, $out );
		return $out;
	}

	/**
	 * Monta a cláusula SQL (LIKE/=) para o critério de uma meta.
	 *
	 * @param array $goal Meta.
	 * @return array{0:string,1:string} [operador+placeholder já formatado, valor]
	 */
	private static function match_sql( $goal ) {
		global $wpdb;
		$value = $goal['value'];
		switch ( $goal['match_type'] ) {
			case 'exact':
				return array( '= %s', $value );
			case 'starts':
				return array( 'LIKE %s', $wpdb->esc_like( $value ) . '%' );
			case 'contains':
			default:
				return array( 'LIKE %s', '%' . $wpdb->esc_like( $value ) . '%' );
		}
	}

	/**
	 * Calcula o desempenho de todas as metas ativas em um intervalo.
	 *
	 * Conversão = sessões que atingiram a meta ÷ total de sessões do período.
	 *
	 * @param string $from Data inicial (mysql).
	 * @param string $to   Data final (mysql).
	 * @return array Lista de metas com métricas.
	 */
	public static function report( $from, $to ) {
		global $wpdb;
		$goals = self::active();
		if ( empty( $goals ) ) {
			return array();
		}

		$sessions = OT_DB::sessions_table();
		$hits     = OT_DB::hits_table();

		$total_sessions = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$sessions} WHERE started_at BETWEEN %s AND %s",
			$from, $to
		) );

		$out = array();
		foreach ( $goals as $g ) {
			list( $op, $val ) = self::match_sql( $g );

			// Sessões distintas (e visitantes) que tiveram ao menos um pageview casando.
			$row = $wpdb->get_row( $wpdb->prepare(
				"SELECT COUNT(DISTINCT session_uid) AS sessions,
				        COUNT(DISTINCT visitor_hash) AS visitors,
				        COUNT(*) AS total
				 FROM {$hits}
				 WHERE created_at BETWEEN %s AND %s AND path {$op}",
				$from, $to, $val
			), ARRAY_A );

			$conv_sessions = (int) $row['sessions'];
			$out[] = array(
				'id'          => $g['id'],
				'name'        => $g['name'],
				'match_type'  => $g['match_type'],
				'value'       => $g['value'],
				'conversions' => $conv_sessions,
				'visitors'    => (int) $row['visitors'],
				'completions' => (int) $row['total'],
				'rate'        => $total_sessions ? round( $conv_sessions / $total_sessions * 100, 2 ) : 0.0,
			);
		}

		return $out;
	}
}
