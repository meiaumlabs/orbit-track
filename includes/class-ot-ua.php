<?php
/**
 * OT_UA — parser de User-Agent (dispositivo, navegador, SO, bot).
 *
 * Implementação leve e autossuficiente, inspirada na abordagem de
 * bibliotecas como a matomo/device-detector, mas sem dependências.
 *
 * @package OrbitTrack
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OT_UA {

	/**
	 * Analisa um User-Agent.
	 *
	 * @param string $ua User agent.
	 * @return array{device_type:string,browser:string,os:string,is_bot:bool}
	 */
	public static function parse( $ua ) {
		$ua = (string) $ua;

		return array(
			'device_type' => self::device_type( $ua ),
			'browser'     => self::browser( $ua ),
			'os'          => self::os( $ua ),
			'is_bot'      => self::is_bot( $ua ),
		);
	}

	/**
	 * Detecta bots/crawlers por padrões comuns no UA.
	 *
	 * @param string $ua User agent.
	 * @return bool
	 */
	public static function is_bot( $ua ) {
		if ( '' === $ua ) {
			return true; // Sem UA quase sempre é automação.
		}
		$patterns = 'bot|crawl|spider|slurp|mediapartners|facebookexternalhit|embedly|quora link preview|'
			. 'bufferbot|phantomjs|headlesschrome|googlebot|bingbot|yandex|baiduspider|duckduckbot|'
			. 'applebot|semrush|ahrefs|mj12bot|dotbot|petalbot|pingdom|uptimerobot|gtmetrix|lighthouse|'
			. 'python-requests|curl|wget|axios|go-http-client|java/|okhttp|scrapy|http_request|'
			. 'preview|monitor|validator|feedfetcher|whatsapp|telegrambot|discordbot|slackbot|'
			. 'gptbot|chatgpt|claudebot|anthropic|ccbot|perplexity|amazonbot|bytespider';
		return (bool) preg_match( '/(' . $patterns . ')/i', $ua );
	}

	/**
	 * Classifica o tipo de dispositivo.
	 *
	 * @param string $ua User agent.
	 * @return string desktop|mobile|tablet|tv|bot
	 */
	public static function device_type( $ua ) {
		if ( self::is_bot( $ua ) ) {
			return 'bot';
		}
		if ( preg_match( '/(smart-tv|smarttv|googletv|appletv|hbbtv|crkey|roku|\btv\b)/i', $ua ) ) {
			return 'tv';
		}
		// Tablets (avaliado antes de "mobile" pois iPad/Android tablet contêm pistas próprias).
		if ( preg_match( '/(ipad|tablet|playbook|silk|kindle)/i', $ua )
			|| ( preg_match( '/android/i', $ua ) && ! preg_match( '/mobile/i', $ua ) ) ) {
			return 'tablet';
		}
		if ( preg_match( '/(mobi|iphone|ipod|android.*mobile|windows phone|blackberry|bb10|opera mini|iemobile)/i', $ua ) ) {
			return 'mobile';
		}
		return 'desktop';
	}

	/**
	 * Extrai o nome do navegador.
	 *
	 * @param string $ua User agent.
	 * @return string
	 */
	public static function browser( $ua ) {
		$map = array(
			'Edge'              => '/edg(a|ios)?\//i',
			'Opera'             => '/(opera|opr)\//i',
			'Samsung Internet'  => '/samsungbrowser/i',
			'UC Browser'        => '/ucbrowser/i',
			'Yandex'            => '/yabrowser/i',
			'Brave'             => '/brave/i',
			'Vivaldi'           => '/vivaldi/i',
			'Firefox'           => '/(firefox|fxios)/i',
			'Chrome'            => '/(chrome|crios|crmo)/i',
			'Safari'            => '/safari/i',
			'Internet Explorer' => '/(msie|trident)/i',
		);
		foreach ( $map as $name => $re ) {
			if ( preg_match( $re, $ua ) ) {
				return $name;
			}
		}
		return __( 'Outro', 'orbit-track' );
	}

	/**
	 * Extrai o sistema operacional.
	 *
	 * @param string $ua User agent.
	 * @return string
	 */
	public static function os( $ua ) {
		$map = array(
			'Windows'    => '/windows nt/i',
			'iOS'        => '/(iphone|ipad|ipod)/i',
			'macOS'      => '/(mac os x|macintosh)/i',
			'Android'    => '/android/i',
			'Chrome OS'  => '/cros/i',
			'Ubuntu'     => '/ubuntu/i',
			'Linux'      => '/linux/i',
		);
		foreach ( $map as $name => $re ) {
			if ( preg_match( $re, $ua ) ) {
				return $name;
			}
		}
		return __( 'Outro', 'orbit-track' );
	}
}
