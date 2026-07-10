<?php
/**
 * OT_Stats — agregações e relatórios consumidos pelo painel.
 *
 * @package OrbitTrack
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OT_Stats {

	/**
	 * Monta o relatório completo para um intervalo.
	 *
	 * @param string $range 7d|28d|90d|365d|custom.
	 * @param string $start Data inicial (Y-m-d) quando custom.
	 * @param string $end   Data final (Y-m-d) quando custom.
	 * @return array
	 */
	public static function report( $range, $start = '', $end = '' ) {
		list( $from, $to ) = self::resolve_range( $range, $start, $end );

		return array(
			'range'      => array( 'from' => $from, 'to' => $to ),
			'kpis'       => self::kpis( $from, $to ),
			'timeseries' => self::timeseries( $from, $to ),
			'channels'   => self::by_channel( $from, $to ),
			'sources'    => self::top( 'source', $from, $to, 12 ),
			'campaigns'  => self::campaigns( $from, $to ),
			'pages'      => self::top_pages( $from, $to, 15 ),
			'landing'    => self::landing_pages( $from, $to, 10 ),
			'countries'  => self::geo( 'country', $from, $to, 12 ),
			'regions'    => self::geo( 'region', $from, $to, 12 ),
			'cities'     => self::geo( 'city', $from, $to, 12 ),
			'devices'    => self::by_col( 'device_type', $from, $to ),
			'browsers'   => self::by_col( 'browser', $from, $to, 8 ),
			'os'         => self::by_col( 'os', $from, $to, 8 ),
			'outbound'   => self::outbound_links( $from, $to, 15 ),
			'outhosts'   => self::outbound_hosts( $from, $to, 10 ),
			'goals'      => OT_Goals::report( $from, $to ),
			'worldmap'   => self::world_map( $from, $to ),
		);
	}

	/**
	 * Converte um range em datas absolutas [from, to] no formato mysql.
	 *
	 * @return array{0:string,1:string}
	 */
	private static function resolve_range( $range, $start, $end ) {
		$tz  = wp_timezone();
		$now = new DateTime( 'now', $tz );

		if ( 'custom' === $range && $start && $end ) {
			$f = DateTime::createFromFormat( 'Y-m-d', $start, $tz );
			$t = DateTime::createFromFormat( 'Y-m-d', $end, $tz );
			if ( $f && $t ) {
				$f->setTime( 0, 0, 0 );
				$t->setTime( 23, 59, 59 );
				return array( $f->format( 'Y-m-d H:i:s' ), $t->format( 'Y-m-d H:i:s' ) );
			}
		}

		$days = 28;
		if ( preg_match( '/^(\d+)d$/', $range, $m ) ) {
			$days = max( 1, (int) $m[1] );
		}
		$to   = clone $now;
		$from = clone $now;
		$from->modify( '-' . ( $days - 1 ) . ' days' )->setTime( 0, 0, 0 );
		$to->setTime( 23, 59, 59 );
		return array( $from->format( 'Y-m-d H:i:s' ), $to->format( 'Y-m-d H:i:s' ) );
	}

	/** Indicadores principais (KPIs) do período. */
	private static function kpis( $from, $to ) {
		global $wpdb;
		$s = OT_DB::sessions_table();
		$h = OT_DB::hits_table();

		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT
				COUNT(*) AS sessions,
				COUNT(DISTINCT visitor_hash) AS visitors,
				COALESCE(SUM(pageviews),0) AS pageviews,
				COALESCE(AVG(duration),0) AS avg_duration,
				COALESCE(AVG(is_bounce)*100,0) AS bounce_rate,
				COALESCE(SUM(is_new_visitor),0) AS new_visitors
			 FROM {$s} WHERE started_at BETWEEN %s AND %s",
			$from, $to
		), ARRAY_A );

		$total_pv = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$h} WHERE created_at BETWEEN %s AND %s",
			$from, $to
		) );

		$sessions = (int) $row['sessions'];
		$pv       = max( $total_pv, (int) $row['pageviews'] );

		return array(
			'sessions'       => $sessions,
			'visitors'       => (int) $row['visitors'],
			'new_visitors'   => (int) $row['new_visitors'],
			'pageviews'      => $pv,
			'avg_duration'   => (int) round( $row['avg_duration'] ),
			'bounce_rate'    => round( (float) $row['bounce_rate'], 1 ),
			'pages_session'  => $sessions ? round( $pv / $sessions, 2 ) : 0,
		);
	}

	/** Série temporal diária (sessões e pageviews). */
	private static function timeseries( $from, $to ) {
		global $wpdb;
		$s = OT_DB::sessions_table();
		$h = OT_DB::hits_table();

		$sess = $wpdb->get_results( $wpdb->prepare(
			"SELECT DATE(started_at) AS d, COUNT(*) AS c
			 FROM {$s} WHERE started_at BETWEEN %s AND %s GROUP BY DATE(started_at)",
			$from, $to
		), OBJECT_K );

		$views = $wpdb->get_results( $wpdb->prepare(
			"SELECT DATE(created_at) AS d, COUNT(*) AS c
			 FROM {$h} WHERE created_at BETWEEN %s AND %s GROUP BY DATE(created_at)",
			$from, $to
		), OBJECT_K );

		$out   = array();
		$tz    = wp_timezone();
		$start = new DateTime( substr( $from, 0, 10 ), $tz );
		$stop  = new DateTime( substr( $to, 0, 10 ), $tz );
		$guard = 0;
		while ( $start <= $stop && $guard < 1000 ) {
			$k = $start->format( 'Y-m-d' );
			$out[] = array(
				'date'      => $k,
				'sessions'  => isset( $sess[ $k ] ) ? (int) $sess[ $k ]->c : 0,
				'pageviews' => isset( $views[ $k ] ) ? (int) $views[ $k ]->c : 0,
			);
			$start->modify( '+1 day' );
			$guard++;
		}
		return $out;
	}

	/** Sessões agrupadas por canal (com rótulos legíveis). */
	private static function by_channel( $from, $to ) {
		global $wpdb;
		$s      = OT_DB::sessions_table();
		$labels = OT_Source::channel_labels();

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT channel, COUNT(*) AS sessions, COALESCE(AVG(is_bounce)*100,0) AS bounce, COALESCE(AVG(duration),0) AS dur
			 FROM {$s} WHERE started_at BETWEEN %s AND %s
			 GROUP BY channel ORDER BY sessions DESC",
			$from, $to
		), ARRAY_A );

		$out = array();
		foreach ( $rows as $r ) {
			$out[] = array(
				'channel'     => $r['channel'],
				'label'       => isset( $labels[ $r['channel'] ] ) ? $labels[ $r['channel'] ] : $r['channel'],
				'sessions'    => (int) $r['sessions'],
				'bounce_rate' => round( (float) $r['bounce'], 1 ),
				'avg_duration'=> (int) round( $r['dur'] ),
			);
		}
		return $out;
	}

	/** Top valores de uma coluna de sessão (ex.: source). */
	private static function top( $col, $from, $to, $limit = 12 ) {
		global $wpdb;
		$s   = OT_DB::sessions_table();
		$col = self::safe_col( $col );

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT {$col} AS label, COUNT(*) AS sessions
			 FROM {$s} WHERE started_at BETWEEN %s AND %s AND {$col} <> ''
			 GROUP BY {$col} ORDER BY sessions DESC LIMIT %d",
			$from, $to, $limit
		), ARRAY_A );

		return array_map( function ( $r ) {
			return array( 'label' => $r['label'], 'sessions' => (int) $r['sessions'] );
		}, $rows );
	}

	/** Campanhas de UTM com origem/mídia. */
	private static function campaigns( $from, $to ) {
		global $wpdb;
		$s = OT_DB::sessions_table();

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT campaign, source, medium, COUNT(*) AS sessions, COALESCE(AVG(duration),0) AS dur
			 FROM {$s} WHERE started_at BETWEEN %s AND %s AND campaign <> ''
			 GROUP BY campaign, source, medium ORDER BY sessions DESC LIMIT 15",
			$from, $to
		), ARRAY_A );

		return array_map( function ( $r ) {
			return array(
				'campaign'     => $r['campaign'],
				'source'       => $r['source'],
				'medium'       => $r['medium'],
				'sessions'     => (int) $r['sessions'],
				'avg_duration' => (int) round( $r['dur'] ),
			);
		}, $rows );
	}

	/** Páginas mais vistas (pageviews + tempo médio). */
	private static function top_pages( $from, $to, $limit = 15 ) {
		global $wpdb;
		$h = OT_DB::hits_table();

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT path,
			        MAX(title) AS title,
			        COUNT(*) AS views,
			        COUNT(DISTINCT session_uid) AS sessions,
			        COALESCE(AVG(NULLIF(time_on_page,0)),0) AS avg_time
			 FROM {$h} WHERE created_at BETWEEN %s AND %s
			 GROUP BY path ORDER BY views DESC LIMIT %d",
			$from, $to, $limit
		), ARRAY_A );

		return array_map( function ( $r ) {
			return array(
				'path'     => $r['path'],
				'title'    => $r['title'] ? $r['title'] : $r['path'],
				'views'    => (int) $r['views'],
				'sessions' => (int) $r['sessions'],
				'avg_time' => (int) round( $r['avg_time'] ),
			);
		}, $rows );
	}

	/** Páginas de entrada (landing) por sessões. */
	private static function landing_pages( $from, $to, $limit = 10 ) {
		global $wpdb;
		$s = OT_DB::sessions_table();

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT landing_page AS path, COUNT(*) AS sessions, COALESCE(AVG(is_bounce)*100,0) AS bounce
			 FROM {$s} WHERE started_at BETWEEN %s AND %s AND landing_page <> ''
			 GROUP BY landing_page ORDER BY sessions DESC LIMIT %d",
			$from, $to, $limit
		), ARRAY_A );

		return array_map( function ( $r ) {
			return array(
				'path'        => $r['path'],
				'sessions'    => (int) $r['sessions'],
				'bounce_rate' => round( (float) $r['bounce'], 1 ),
			);
		}, $rows );
	}

	/** Agrupamento geográfico (country|region|city). */
	private static function geo( $col, $from, $to, $limit = 12 ) {
		global $wpdb;
		$s   = OT_DB::sessions_table();
		$col = self::safe_col( $col );

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT {$col} AS label, country_code, COUNT(*) AS sessions
			 FROM {$s} WHERE started_at BETWEEN %s AND %s AND {$col} <> ''
			 GROUP BY {$col}, country_code ORDER BY sessions DESC LIMIT %d",
			$from, $to, $limit
		), ARRAY_A );

		return array_map( function ( $r ) {
			return array(
				'label'    => $r['label'],
				'cc'       => $r['country_code'],
				'sessions' => (int) $r['sessions'],
			);
		}, $rows );
	}

	/** Distribuição por coluna simples de sessão (device/browser/os). */
	private static function by_col( $col, $from, $to, $limit = 20 ) {
		global $wpdb;
		$s   = OT_DB::sessions_table();
		$col = self::safe_col( $col );

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT {$col} AS label, COUNT(*) AS sessions
			 FROM {$s} WHERE started_at BETWEEN %s AND %s AND {$col} <> ''
			 GROUP BY {$col} ORDER BY sessions DESC LIMIT %d",
			$from, $to, $limit
		), ARRAY_A );

		return array_map( function ( $r ) {
			return array( 'label' => $r['label'], 'sessions' => (int) $r['sessions'] );
		}, $rows );
	}

	/** Top links de saída (URL completa) do período. */
	public static function outbound_links( $from, $to, $limit = 15 ) {
		global $wpdb;
		$o = OT_DB::outbound_table();

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT target_url, target_host, COUNT(*) AS clicks, COUNT(DISTINCT session_uid) AS sessions
			 FROM {$o} WHERE created_at BETWEEN %s AND %s AND target_url <> ''
			 GROUP BY target_url ORDER BY clicks DESC LIMIT %d",
			$from, $to, $limit
		), ARRAY_A );

		return array_map( function ( $r ) {
			return array(
				'url'      => $r['target_url'],
				'host'     => $r['target_host'],
				'clicks'   => (int) $r['clicks'],
				'sessions' => (int) $r['sessions'],
			);
		}, $rows );
	}

	/** Domínios de saída mais clicados (agrupado por host). */
	public static function outbound_hosts( $from, $to, $limit = 10 ) {
		global $wpdb;
		$o = OT_DB::outbound_table();

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT target_host AS label, COUNT(*) AS clicks
			 FROM {$o} WHERE created_at BETWEEN %s AND %s AND target_host <> ''
			 GROUP BY target_host ORDER BY clicks DESC LIMIT %d",
			$from, $to, $limit
		), ARRAY_A );

		return array_map( function ( $r ) {
			return array( 'label' => $r['label'], 'sessions' => (int) $r['clicks'] );
		}, $rows );
	}

	/** Sessões por país no formato { cc: total } para o mapa-múndi. */
	public static function world_map( $from, $to ) {
		global $wpdb;
		$s = OT_DB::sessions_table();

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT country_code AS cc, COUNT(*) AS sessions
			 FROM {$s} WHERE started_at BETWEEN %s AND %s AND country_code <> ''
			 GROUP BY country_code ORDER BY sessions DESC",
			$from, $to
		), ARRAY_A );

		$out = array();
		foreach ( $rows as $r ) {
			$out[] = array( 'cc' => strtoupper( $r['cc'] ), 'sessions' => (int) $r['sessions'] );
		}
		return $out;
	}

	/** Quantos visitantes estão online agora (últimos N minutos). */
	public static function online_now( $minutes = 5 ) {
		global $wpdb;
		$s      = OT_DB::sessions_table();
		// last_seen é gravado em hora local do site; usa o mesmo referencial no corte.
		$cutoff = gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - ( (int) $minutes * MINUTE_IN_SECONDS ) );

		return (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$s} WHERE last_seen >= %s",
			$cutoff
		) );
	}

	/**
	 * Log de acessos visita-a-visita (recurso "Access log" do SlimStat).
	 *
	 * Cada linha é um pageview com o contexto da sessão (origem, país, dispositivo,
	 * navegador, SO). Ordenado do mais recente para o mais antigo.
	 *
	 * @param int    $limit  Máximo de linhas.
	 * @param int    $offset Deslocamento para paginação.
	 * @param string $since  (Opcional) só hits com id maior que este (para o modo ao vivo).
	 * @return array
	 */
	public static function access_log( $limit = 30, $offset = 0, $since = 0 ) {
		global $wpdb;
		$h = OT_DB::hits_table();
		$s = OT_DB::sessions_table();

		$limit  = min( 200, max( 1, (int) $limit ) );
		$offset = max( 0, (int) $offset );
		$since  = max( 0, (int) $since );

		$where = '1=1';
		$args  = array();
		if ( $since > 0 ) {
			$where  = 'h.id > %d';
			$args[] = $since;
		}

		$sql = "SELECT h.id, h.created_at, h.path, h.title, h.channel, h.device_type, h.country_code,
		               h.is_entry, h.time_on_page, h.referrer,
		               s.browser, s.os, s.country, s.city, s.is_new_visitor
		        FROM {$h} h
		        LEFT JOIN {$s} s ON s.session_uid = h.session_uid
		        WHERE {$where}
		        ORDER BY h.id DESC
		        LIMIT %d OFFSET %d";
		$args[] = $limit;
		$args[] = $offset;

		$rows   = $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A );
		$labels = OT_Source::channel_labels();

		return array_map( function ( $r ) use ( $labels ) {
			return array(
				'id'          => (int) $r['id'],
				'time'        => $r['created_at'],
				'path'        => $r['path'],
				'title'       => $r['title'] ? $r['title'] : $r['path'],
				'channel'     => $r['channel'],
				'channel_label' => isset( $labels[ $r['channel'] ] ) ? $labels[ $r['channel'] ] : $r['channel'],
				'device'      => $r['device_type'],
				'browser'     => $r['browser'],
				'os'          => $r['os'],
				'cc'          => $r['country_code'] ? $r['country_code'] : '',
				'country'     => $r['country'],
				'city'        => $r['city'],
				'referrer'    => $r['referrer'],
				'is_entry'    => (int) $r['is_entry'],
				'is_new'      => (int) $r['is_new_visitor'],
				'time_on_page'=> (int) $r['time_on_page'],
			);
		}, $rows );
	}

	/** Whitelist de colunas para evitar SQL injection em GROUP BY. */
	private static function safe_col( $col ) {
		$allowed = array( 'source', 'medium', 'campaign', 'channel', 'country', 'region', 'city', 'device_type', 'browser', 'os', 'landing_page' );
		return in_array( $col, $allowed, true ) ? $col : 'source';
	}
}
