<?php
/**
 * OT_Source — classificação da origem do tráfego (atribuição de canal).
 *
 * Combina parâmetros UTM, click IDs de plataformas de anúncio (gclid, fbclid,
 * msclkid, ttclid, etc.) e o domínio do referenciador para atribuir cada
 * sessão a um canal, seguindo a lógica de Default Channel Grouping do GA4 e
 * as categorias do WP Statistics: direct, organic_search, paid_search,
 * organic_social, paid_social, email, referral, display, affiliate, internal.
 *
 * @package OrbitTrack
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OT_Source {

	/** Motores de busca conhecidos (fragmento de host => nome). */
	private static function search_engines() {
		return array(
			'google.'     => 'Google',
			'bing.'       => 'Bing',
			'yahoo.'      => 'Yahoo',
			'duckduckgo.' => 'DuckDuckGo',
			'yandex.'     => 'Yandex',
			'baidu.'      => 'Baidu',
			'ecosia.'     => 'Ecosia',
			'ask.com'     => 'Ask',
			'brave.com'   => 'Brave Search',
			'qwant.'      => 'Qwant',
			'startpage.'  => 'Startpage',
		);
	}

	/** Redes sociais conhecidas (fragmento de host => nome). */
	private static function social_networks() {
		return array(
			'facebook.'   => 'Facebook',
			'fb.'         => 'Facebook',
			'instagram.'  => 'Instagram',
			'l.instagram' => 'Instagram',
			't.co'        => 'X (Twitter)',
			'twitter.'    => 'X (Twitter)',
			'x.com'       => 'X (Twitter)',
			'linkedin.'   => 'LinkedIn',
			'lnkd.in'     => 'LinkedIn',
			'youtube.'    => 'YouTube',
			'youtu.be'    => 'YouTube',
			'tiktok.'     => 'TikTok',
			'pinterest.'  => 'Pinterest',
			'reddit.'     => 'Reddit',
			'whatsapp.'   => 'WhatsApp',
			'wa.me'       => 'WhatsApp',
			't.me'        => 'Telegram',
			'telegram.'   => 'Telegram',
			'threads.'    => 'Threads',
			'tumblr.'     => 'Tumblr',
			'quora.'      => 'Quora',
			'medium.'     => 'Medium',
		);
	}

	/** Provedores de e-mail/webmail conhecidos. */
	private static function email_hosts() {
		return array( 'mail.google', 'outlook.', 'mail.yahoo', 'webmail', 'mail.live' );
	}

	/**
	 * Classifica a origem a partir do referrer e dos parâmetros da URL de entrada.
	 *
	 * @param string $referrer  URL de referência (document.referrer).
	 * @param array  $params    Query params da landing page (utm_*, gclid, etc.).
	 * @param string $site_host Host do próprio site (para detectar tráfego interno).
	 * @return array{channel:string,source:string,medium:string,campaign:string,term:string,content:string}
	 */
	public static function classify( $referrer, array $params, $site_host ) {
		$params = array_change_key_case( $params, CASE_LOWER );

		$utm_source   = isset( $params['utm_source'] ) ? self::clean( $params['utm_source'] ) : '';
		$utm_medium   = isset( $params['utm_medium'] ) ? strtolower( self::clean( $params['utm_medium'] ) ) : '';
		$utm_campaign = isset( $params['utm_campaign'] ) ? self::clean( $params['utm_campaign'] ) : '';
		$utm_term     = isset( $params['utm_term'] ) ? self::clean( $params['utm_term'] ) : '';
		$utm_content  = isset( $params['utm_content'] ) ? self::clean( $params['utm_content'] ) : '';

		$ref_host = self::host( $referrer );

		$out = array(
			'channel'  => 'direct',
			'source'   => $utm_source ? $utm_source : '(direct)',
			'medium'   => $utm_medium ? $utm_medium : '(none)',
			'campaign' => $utm_campaign,
			'term'     => $utm_term,
			'content'  => $utm_content,
		);

		// 1) Click IDs de anúncios têm prioridade máxima (sinal inequívoco de mídia paga).
		$paid_click = self::paid_click_id( $params );
		if ( $paid_click ) {
			$is_social = in_array( $paid_click['platform'], array( 'Facebook', 'Instagram', 'TikTok' ), true );
			$out['channel']  = $is_social ? 'paid_social' : 'paid_search';
			$out['source']   = $utm_source ? $utm_source : $paid_click['source'];
			$out['medium']   = $utm_medium ? $utm_medium : 'cpc';
			return $out;
		}

		// 2) UTM medium explícito manda na classificação.
		if ( $utm_medium ) {
			$out['channel'] = self::channel_from_medium( $utm_medium, $utm_source, $ref_host );
			return $out;
		}

		// 3) Sem UTM: usa o referrer.
		if ( '' === $ref_host ) {
			// Sem referrer e sem UTM = acesso direto.
			return $out;
		}

		// Tráfego interno (mesmo domínio) não redefine a origem da sessão.
		if ( $site_host && self::same_site( $ref_host, $site_host ) ) {
			$out['channel'] = 'internal';
			$out['source']  = '(internal)';
			$out['medium']  = 'internal';
			return $out;
		}

		// Busca orgânica.
		$se = self::match_host( $ref_host, self::search_engines() );
		if ( $se ) {
			$out['channel'] = 'organic_search';
			$out['source']  = $se;
			$out['medium']  = 'organic';
			return $out;
		}

		// Social orgânico.
		$soc = self::match_host( $ref_host, self::social_networks() );
		if ( $soc ) {
			$out['channel'] = 'organic_social';
			$out['source']  = $soc;
			$out['medium']  = 'social';
			return $out;
		}

		// E-mail (webmail).
		foreach ( self::email_hosts() as $frag ) {
			if ( false !== strpos( $ref_host, $frag ) ) {
				$out['channel'] = 'email';
				$out['source']  = $ref_host;
				$out['medium']  = 'email';
				return $out;
			}
		}

		// Referência genérica (outro site).
		$out['channel'] = 'referral';
		$out['source']  = $ref_host;
		$out['medium']  = 'referral';
		return $out;
	}

	/**
	 * Deriva o canal a partir do utm_medium.
	 */
	private static function channel_from_medium( $medium, $source, $ref_host ) {
		if ( preg_match( '/(^cpc$|ppc|paid|sem|adwords|google_ads|googleads)/', $medium ) ) {
			// Distingue paid social de paid search pela fonte/referrer.
			if ( self::looks_social( $source ) || self::match_host( $ref_host, self::social_networks() ) ) {
				return 'paid_social';
			}
			return 'paid_search';
		}
		if ( preg_match( '/(display|banner|cpm|programmatic)/', $medium ) ) {
			return 'display';
		}
		if ( preg_match( '/(^social$|social-network|social-media|social_media|sm)/', $medium ) ) {
			return self::looks_paid_source( $source ) ? 'paid_social' : 'organic_social';
		}
		if ( 'email' === $medium || 'e-mail' === $medium || 'newsletter' === $medium ) {
			return 'email';
		}
		if ( 'affiliate' === $medium || 'affiliates' === $medium ) {
			return 'affiliate';
		}
		if ( 'organic' === $medium ) {
			return 'organic_search';
		}
		if ( 'referral' === $medium ) {
			return 'referral';
		}
		// Medium desconhecido mas presente: trata como referência com origem nomeada.
		return 'referral';
	}

	/**
	 * Detecta click IDs de plataformas de anúncio na query.
	 *
	 * @return array{platform:string,source:string}|null
	 */
	private static function paid_click_id( array $params ) {
		$ids = array(
			'gclid'   => array( 'Google', 'google' ),
			'gbraid'  => array( 'Google', 'google' ),
			'wbraid'  => array( 'Google', 'google' ),
			'gclsrc'  => array( 'Google', 'google' ),
			'dclid'   => array( 'Google', 'google' ), // Display & Video 360.
			'msclkid' => array( 'Bing', 'bing' ),
			'fbclid'  => array( 'Facebook', 'facebook' ),
			'igshid'  => array( 'Instagram', 'instagram' ),
			'ttclid'  => array( 'TikTok', 'tiktok' ),
			'twclid'  => array( 'X (Twitter)', 'twitter' ),
			'li_fat_id' => array( 'LinkedIn', 'linkedin' ),
			'epik'    => array( 'Pinterest', 'pinterest' ),
			'yclid'   => array( 'Yandex', 'yandex' ),
		);
		foreach ( $ids as $key => $meta ) {
			if ( ! empty( $params[ $key ] ) ) {
				// fbclid/igshid sozinhos NÃO garantem tráfego pago; só marcamos pago
				// quando há click ID de rede de busca. Para social, deixamos o medium decidir.
				if ( in_array( $key, array( 'fbclid', 'igshid' ), true ) ) {
					continue;
				}
				return array( 'platform' => $meta[0], 'source' => $meta[1] );
			}
		}
		return null;
	}

	private static function looks_social( $source ) {
		return (bool) preg_match( '/(facebook|instagram|tiktok|linkedin|twitter|^x$|pinterest|reddit|threads|snapchat)/i', $source );
	}

	private static function looks_paid_source( $source ) {
		return (bool) preg_match( '/(ads|paid|cpc)/i', $source );
	}

	/** Normaliza um valor de parâmetro. */
	private static function clean( $v ) {
		return trim( sanitize_text_field( wp_unslash( (string) $v ) ) );
	}

	/** Extrai o host (sem www) de uma URL. */
	public static function host( $url ) {
		$url = (string) $url;
		if ( '' === $url ) {
			return '';
		}
		$h = wp_parse_url( $url, PHP_URL_HOST );
		if ( ! $h ) {
			return '';
		}
		return preg_replace( '/^www\./i', '', strtolower( $h ) );
	}

	/** Verifica se dois hosts pertencem ao mesmo domínio raiz. */
	private static function same_site( $a, $b ) {
		$a = preg_replace( '/^www\./i', '', strtolower( $a ) );
		$b = preg_replace( '/^www\./i', '', strtolower( $b ) );
		return $a === $b;
	}

	/** Procura um fragmento de host dentro de um mapa. */
	private static function match_host( $host, array $map ) {
		if ( '' === $host ) {
			return '';
		}
		foreach ( $map as $frag => $name ) {
			if ( false !== strpos( $host, $frag ) ) {
				return $name;
			}
		}
		return '';
	}

	/** Rótulos legíveis dos canais (para o painel). */
	public static function channel_labels() {
		return array(
			'direct'         => __( 'Direto', 'orbit-track' ),
			'organic_search' => __( 'Busca orgânica', 'orbit-track' ),
			'paid_search'    => __( 'Busca paga (Ads)', 'orbit-track' ),
			'organic_social' => __( 'Social orgânico', 'orbit-track' ),
			'paid_social'    => __( 'Social pago (Ads)', 'orbit-track' ),
			'email'          => __( 'E-mail', 'orbit-track' ),
			'display'        => __( 'Display', 'orbit-track' ),
			'affiliate'      => __( 'Afiliados', 'orbit-track' ),
			'referral'       => __( 'Referência', 'orbit-track' ),
			'internal'       => __( 'Interno', 'orbit-track' ),
		);
	}
}
