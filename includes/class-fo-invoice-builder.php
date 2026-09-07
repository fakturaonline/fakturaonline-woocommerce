<?php
defined( 'ABSPATH' ) || exit;

/**
 * Maps a WC_Order onto the FakturaOnline `invoice` payload. Pure: no HTTP, no WP state.
 */
class FO_Invoice_Builder {

	public static function build( $order, array $template, array $settings ): array {
		$inclusive = function_exists( 'wc_prices_include_tax' ) && wc_prices_include_tax();
		$lines     = array();

		// Unit price = WooCommerce line total after coupons / quantity, up to 4 decimals. FO rounds
		// qty × price to 2 decimals per line and adds VAT per line — exactly WooCommerce's own
		// arithmetic — so invoice totals match the order to the haléř. Separate discount lines would
		// not: FO rounds their VAT independently (ponytail: ±1 haléř drift; 4 decimals hold up to qty ~20).
		foreach ( $order->get_items() as $item ) {
			$qty      = max( 1, (float) $item->get_quantity() );
			$vat_rate = self::vat_rate( $item );
			$subtotal = (float) $item->get_subtotal() + ( $inclusive ? (float) $item->get_subtotal_tax() : 0 );
			$total    = (float) $item->get_total() + ( $inclusive ? (float) $item->get_total_tax() : 0 );

			$product     = method_exists( $item, 'get_product' ) ? $item->get_product() : null;
			$description = $item->get_name() . ( $product && $product->get_sku() ? ' (' . $product->get_sku() . ')' : '' );
			if ( $subtotal - $total > 0.005 ) {
				$description .= sprintf( __( ' (sleva %d %%)', 'fakturaonline-woocommerce' ), round( ( 1 - $total / $subtotal ) * 100 ) );
			}

			$lines[] = self::line( $description, $qty, self::num( $total / $qty, 4 ), $vat_rate );
		}

		foreach ( array_merge( $order->get_shipping_methods(), $order->get_fees() ) as $item ) {
			$total = (float) $item->get_total() + ( $inclusive ? (float) $item->get_total_tax() : 0 );
			if ( abs( $total ) < 0.005 ) {
				continue;
			}
			$lines[] = self::line( $item->get_name(), 1, self::num( $total ), self::vat_rate( $item ) );
		}

		$today = function_exists( 'wp_date' ) ? wp_date( 'Y-m-d' ) : gmdate( 'Y-m-d' ); // site timezone, not UTC
		$name  = $order->get_billing_company() ?: trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );

		$payload = array(
			'kind'                => $settings['kind'],
			'number'              => $template['number'] ?? null,
			'registration_number' => $template['registration_number'] ?? ( $template['number'] ?? null ),
			'currency'            => $order->get_currency(),
			'vat_calculation'     => $inclusive ? 'vat_inclusive' : 'vat_exclusive',
			'issued_on'           => $today,
			'tax_point_on'        => $today,
			'due_in'              => (int) $settings['due_in'],
			'means_of_payment'    => self::means_of_payment( (string) $order->get_payment_method() ),
			'payment_symbol'      => substr( preg_replace( '/\D/', '', (string) $order->get_order_number() ), 0, 10 ),
			'note'                => sprintf( __( 'Objednávka č. %s', 'fakturaonline-woocommerce' ), $order->get_order_number() ),
			'buyer_attributes'    => array(
				'name'               => $name,
				'company_number'     => (string) $order->get_meta( '_billing_ico' ),
				'tax_number'         => (string) $order->get_meta( '_billing_dic' ),
				'vat_number'         => (string) $order->get_meta( '_billing_ic_dph' ),
				'email'              => $order->get_billing_email(),
				'phone'              => $order->get_billing_phone(),
				'address_attributes' => array(
					'street'       => trim( $order->get_billing_address_1() . ' ' . $order->get_billing_address_2() ),
					'city'         => $order->get_billing_city(),
					'postcode'     => $order->get_billing_postcode(),
					'country_code' => $order->get_billing_country(),
				),
			),
			'lines_attributes'    => $lines,
		);

		foreach ( array( 'logo_id', 'stamp_id' ) as $key ) {
			if ( ! empty( $settings[ 'fo_' . $key ] ) ) {
				$payload[ $key ] = (int) $settings[ 'fo_' . $key ];
			}
		}

		// A fresh FO account may have no seller yet; an empty array would reach the API as `[]` and break nested attributes.
		$seller = self::seller( (array) ( $template['seller'] ?? array() ) );
		if ( $seller ) {
			$payload['seller_attributes'] = $seller;
		}

		return apply_filters( 'fakturaonline_invoice_payload', $payload, $order );
	}

	public static function means_of_payment( string $gateway_id ): string {
		$map   = array(
			'bacs'   => 'bank_transfer',
			'cod'    => 'cash_on_delivery',
			'cheque' => 'cash',
			'paypal' => 'paypal',
		);
		$value = $map[ $gateway_id ] ?? 'credit_card';
		return apply_filters( 'fakturaonline_means_of_payment', $value, $gateway_id );
	}

	private static function line( string $description, float $qty, string $price, string $vat_rate ): array {
		return array(
			'description' => $description,
			'quantity'    => self::num( $qty ),
			'unit_type'   => 'ks',
			'price'       => $price,
			'vat_rate'    => $vat_rate,
		);
	}

	/** Percent of the first tax rate applied to the item, "0" when untaxed. */
	private static function vat_rate( $item ): string {
		$taxes = $item->get_taxes();
		foreach ( (array) ( $taxes['total'] ?? array() ) as $rate_id => $amount ) {
			if ( $amount === '' || $amount === null ) {
				continue;
			}
			return self::num( (float) WC_Tax::get_rate_percent_value( $rate_id ) );
		}
		return '0';
	}

	/** Template `seller` → `seller_attributes`; only the fields the API permits. */
	private static function seller( array $seller ): array {
		$keys = array( 'name', 'company_number', 'tax_number', 'vat_number', 'street', 'city', 'postcode', 'country_code', 'subdivision', 'bank_account_number', 'bank_account_name', 'iban', 'swift', 'show_iban', 'hidden_bank_details', 'phone', 'email', 'web' );
		$out  = array();
		foreach ( $keys as $k ) {
			if ( isset( $seller[ $k ] ) && $seller[ $k ] !== '' ) {
				$out[ $k ] = $seller[ $k ];
			}
		}
		return $out;
	}

	private static function num( float $n, int $decimals = 2 ): string {
		return rtrim( rtrim( number_format( $n, $decimals, '.', '' ), '0' ), '.' ) ?: '0';
	}
}
