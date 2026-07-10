<?php
/**
 * OT_Geo — geolocalização por IP (país/estado/cidade) com cache.
 *
 * Estratégia em camadas para não depender de um único provedor e evitar
 * chamadas externas quando o servidor já entrega o país (Cloudflare, etc.):
 *   1) Cabeçalhos de CDN/servidor (CF-IPCountry, GEOIP_*, MM_COUNTRY_CODE);
 *   2) Cache por IP (transient) para não repetir lookups;
 *   3) Provedor externo gratuito (ip-api.com) como fallback, com timeout curto.
 *
 * O IP em si NUNCA é armazenado — apenas o resultado geográfico agregado.
 *
 * @package OrbitTrack
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OT_Geo {

	/**
	 * Resolve a geolocalização de um IP.
	 *
	 * @param string $ip Endereço IP do visitante.
	 * @return array{country_code:string,country:string,region:string,city:string}
	 */
	public static function locate( $ip ) {
		$empty = array( 'country_code' => '', 'country' => '', 'region' => '', 'city' => '' );

		if ( ! OT_Settings::get( 'geo_enabled' ) ) {
			return $empty;
		}
		if ( '' === $ip || self::is_private( $ip ) ) {
			return $empty;
		}

		// Cache por IP (12h) — usa hash do IP como chave, não o IP em claro.
		$cache_key = 'ot_geo_' . md5( $ip );
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		// 1) Cabeçalhos de CDN/servidor.
		$header = self::from_headers();
		if ( $header['country_code'] ) {
			set_transient( $cache_key, $header, 12 * HOUR_IN_SECONDS );
			return $header;
		}

		// 2) Provedor externo (fallback).
		$geo = self::from_ip_api( $ip );
		set_transient( $cache_key, $geo, 12 * HOUR_IN_SECONDS );
		return $geo;
	}

	/**
	 * Tenta ler o país de cabeçalhos entregues pelo servidor/CDN.
	 *
	 * @return array
	 */
	private static function from_headers() {
		$out = array( 'country_code' => '', 'country' => '', 'region' => '', 'city' => '' );

		$cc = '';
		foreach ( array( 'HTTP_CF_IPCOUNTRY', 'HTTP_X_GEO_COUNTRY', 'GEOIP_COUNTRY_CODE', 'MM_COUNTRY_CODE' ) as $h ) {
			if ( ! empty( $_SERVER[ $h ] ) ) {
				$cc = strtoupper( substr( sanitize_text_field( wp_unslash( $_SERVER[ $h ] ) ), 0, 2 ) );
				break;
			}
		}
		if ( $cc && 'XX' !== $cc && 'T1' !== $cc ) {
			$out['country_code'] = $cc;
			$out['country']      = self::country_name( $cc );
			if ( ! empty( $_SERVER['GEOIP_REGION'] ) ) {
				$out['region'] = sanitize_text_field( wp_unslash( $_SERVER['GEOIP_REGION'] ) );
			}
			if ( ! empty( $_SERVER['GEOIP_CITY'] ) ) {
				$out['city'] = sanitize_text_field( wp_unslash( $_SERVER['GEOIP_CITY'] ) );
			}
		}
		return $out;
	}

	/**
	 * Consulta o provedor externo ip-api.com (gratuito, sem chave).
	 *
	 * @param string $ip IP.
	 * @return array
	 */
	private static function from_ip_api( $ip ) {
		$out = array( 'country_code' => '', 'country' => '', 'region' => '', 'city' => '' );

		$url = add_query_arg(
			array( 'fields' => 'status,country,countryCode,regionName,city', 'lang' => 'pt-BR' ),
			'http://ip-api.com/json/' . rawurlencode( $ip )
		);

		$res = wp_remote_get( $url, array(
			'timeout'    => 2,
			'user-agent' => 'OrbitTrack/' . OT_VERSION . '; ' . home_url( '/' ),
		) );
		if ( is_wp_error( $res ) || 200 !== (int) wp_remote_retrieve_response_code( $res ) ) {
			return $out;
		}

		$body = json_decode( wp_remote_retrieve_body( $res ), true );
		if ( ! is_array( $body ) || empty( $body['status'] ) || 'success' !== $body['status'] ) {
			return $out;
		}

		$out['country_code'] = isset( $body['countryCode'] ) ? sanitize_text_field( $body['countryCode'] ) : '';
		$out['country']      = isset( $body['country'] ) ? sanitize_text_field( $body['country'] ) : '';
		$out['region']       = isset( $body['regionName'] ) ? sanitize_text_field( $body['regionName'] ) : '';
		$out['city']         = isset( $body['city'] ) ? sanitize_text_field( $body['city'] ) : '';
		return $out;
	}

	/**
	 * Detecta IPs privados/reservados (localhost, LAN) — sem geo possível.
	 *
	 * @param string $ip IP.
	 * @return bool
	 */
	private static function is_private( $ip ) {
		return false === filter_var(
			$ip,
			FILTER_VALIDATE_IP,
			FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
		);
	}

	/**
	 * Descobre o IP real do visitante respeitando proxies conhecidos.
	 *
	 * @return string
	 */
	public static function client_ip() {
		$candidates = array(
			'HTTP_CF_CONNECTING_IP', // Cloudflare.
			'HTTP_X_FORWARDED_FOR',
			'HTTP_X_REAL_IP',
			'REMOTE_ADDR',
		);
		foreach ( $candidates as $key ) {
			if ( empty( $_SERVER[ $key ] ) ) {
				continue;
			}
			$raw = sanitize_text_field( wp_unslash( $_SERVER[ $key ] ) );
			// X-Forwarded-For pode conter uma lista "cliente, proxy1, proxy2".
			foreach ( explode( ',', $raw ) as $part ) {
				$part = trim( $part );
				if ( filter_var( $part, FILTER_VALIDATE_IP ) ) {
					return $part;
				}
			}
		}
		return '';
	}

	/**
	 * Nome do país a partir do código ISO-3166 alpha-2 (subconjunto comum).
	 *
	 * @param string $cc Código de duas letras.
	 * @return string
	 */
	public static function country_name( $cc ) {
		$map = array(
			'BR' => 'Brasil', 'US' => 'Estados Unidos', 'PT' => 'Portugal', 'AR' => 'Argentina',
			'MX' => 'México', 'ES' => 'Espanha', 'GB' => 'Reino Unido', 'FR' => 'França',
			'DE' => 'Alemanha', 'IT' => 'Itália', 'CA' => 'Canadá', 'CL' => 'Chile',
			'CO' => 'Colômbia', 'PE' => 'Peru', 'UY' => 'Uruguai', 'PY' => 'Paraguai',
			'BO' => 'Bolívia', 'VE' => 'Venezuela', 'EC' => 'Equador', 'JP' => 'Japão',
			'CN' => 'China', 'IN' => 'Índia', 'RU' => 'Rússia', 'AU' => 'Austrália',
			'NL' => 'Holanda', 'BE' => 'Bélgica', 'CH' => 'Suíça', 'SE' => 'Suécia',
			'NO' => 'Noruega', 'DK' => 'Dinamarca', 'FI' => 'Finlândia', 'IE' => 'Irlanda',
			'PL' => 'Polônia', 'AT' => 'Áustria', 'GR' => 'Grécia', 'TR' => 'Turquia',
			'ZA' => 'África do Sul', 'AO' => 'Angola', 'MZ' => 'Moçambique', 'KR' => 'Coreia do Sul',
		);
		$cc = strtoupper( $cc );
		return isset( $map[ $cc ] ) ? $map[ $cc ] : $cc;
	}
}
