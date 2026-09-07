<?php
defined( 'ABSPATH' ) || exit;

/**
 * Thin wrapper over the FakturaOnline public REST API. Every failure is a WP_Error
 * whose message is safe to show in an order note.
 */
class FO_API_Client {
	private string $base;
	private string $key;

	public function __construct() {
		$this->base = FO_Settings::api_base_url();
		$this->key  = FO_Settings::get()['api_key'];
	}

	public function template( string $kind ): array|WP_Error {
		// Newer FO API versions honour ?kind=; older ones ignore it and the template inherits the kind of the last document.
		return $this->request( 'GET', '/invoices/new?kind=' . rawurlencode( $kind ) );
	}

	public function create( array $invoice ): array|WP_Error {
		$body = $this->request( 'POST', '/invoices', array( 'invoice' => $invoice ) );
		if ( is_wp_error( $body ) ) {
			return $body;
		}
		return array(
			'invoice_id' => (int) ( $body['invoice_id'] ?? 0 ),
			'number'     => (string) ( $body['invoice']['number'] ?? '' ),
			'public_url' => (string) ( $body['public_url'] ?? '' ),
		);
	}

	public function mark_paid( int $invoice_id ): bool|WP_Error {
		$body = $this->request( 'PATCH', "/invoices/{$invoice_id}", array( 'invoice' => array( 'paid' => true ) ) );
		return is_wp_error( $body ) ? $body : true;
	}

	public function pdf( int $invoice_id ): string|WP_Error {
		return $this->request( 'GET', "/invoices/{$invoice_id}.pdf", null, true );
	}

	/** Uploads a logo/stamp image; returns FO's attachment id to send as logo_id / stamp_id. */
	public function upload( string $type, string $path ): int|WP_Error {
		if ( $this->key === '' ) {
			return new WP_Error( 'fo_no_key', __( 'Není nastaven API klíč FakturaOnline.', 'fakturaonline-woocommerce' ) );
		}
		$contents = is_readable( $path ) ? file_get_contents( $path ) : false;
		if ( $contents === false ) {
			return new WP_Error( 'fo_no_file', 'Soubor obrázku nelze přečíst: ' . basename( $path ) );
		}
		$boundary = wp_generate_password( 24, false );
		$body     = "--{$boundary}\r\nContent-Disposition: form-data; name=\"type\"\r\n\r\n{$type}\r\n"
			. "--{$boundary}\r\nContent-Disposition: form-data; name=\"file\"; filename=\"" . basename( $path ) . "\"\r\n"
			. 'Content-Type: ' . ( mime_content_type( $path ) ?: 'application/octet-stream' ) . "\r\n\r\n{$contents}\r\n--{$boundary}--\r\n";

		$response = wp_remote_post( $this->base . '/uploads', array(
			'timeout'   => 30,
			'sslverify' => ! defined( 'FO_SSL_VERIFY' ) || FO_SSL_VERIFY,
			'headers'   => array( 'X-Api-Key' => $this->key, 'Content-Type' => 'multipart/form-data; boundary=' . $boundary ),
			'body'      => $body,
		) );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$status  = wp_remote_retrieve_response_code( $response );
		$decoded = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( $status < 200 || $status >= 300 || empty( $decoded['id'] ) ) {
			return new WP_Error( 'fo_upload_' . $status, sprintf( 'FakturaOnline API %d při nahrávání %s: %s', $status, $type, substr( wp_remote_retrieve_body( $response ), 0, 300 ) ) );
		}
		return (int) $decoded['id'];
	}

	private function request( string $method, string $path, ?array $json = null, bool $raw = false ): array|string|WP_Error {
		if ( $this->key === '' ) {
			return new WP_Error( 'fo_no_key', __( 'Není nastaven API klíč FakturaOnline.', 'fakturaonline-woocommerce' ) );
		}
		$args = array(
			'method'    => $method,
			'timeout'   => 15,
			'sslverify' => ! defined( 'FO_SSL_VERIFY' ) || FO_SSL_VERIFY, // dev instances use self-signed certs
			'headers' => array(
				'X-Api-Key' => $this->key,
				'Accept'    => $raw ? 'application/pdf' : 'application/json',
			),
		);
		if ( $json !== null ) {
			$args['headers']['Content-Type'] = 'application/json';
			$args['body']                    = wp_json_encode( $json );
		}

		$response = wp_remote_request( $this->base . $path, $args );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status = wp_remote_retrieve_response_code( $response );
		$body   = wp_remote_retrieve_body( $response );

		if ( $status < 200 || $status >= 300 ) {
			$decoded = json_decode( $body, true );
			$detail  = is_array( $decoded ) ? wp_json_encode( $decoded['error'] ?? $decoded['errors'] ?? $decoded ) : substr( $body, 0, 300 );
			if ( $status === 401 ) {
				$detail = __( 'Neplatný API klíč nebo špatná instance (cz/sk).', 'fakturaonline-woocommerce' );
			} elseif ( $status === 403 ) {
				$detail = __( 'Předplatné FakturaOnline není aktivní.', 'fakturaonline-woocommerce' );
			}
			return new WP_Error( 'fo_http_' . $status, sprintf( 'FakturaOnline API %d: %s', $status, $detail ) );
		}

		if ( $raw ) {
			return $body;
		}
		$decoded = json_decode( $body, true );
		return is_array( $decoded ) ? $decoded : new WP_Error( 'fo_bad_json', 'FakturaOnline API: neplatná odpověď.' );
	}
}
