<?php
// Minimal stand-ins for WordPress/WooCommerce; nothing else is loaded in the test.

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}
function __( $s ) { return $s; }
function apply_filters( $hook, $value ) { return $value; }
function wc_prices_include_tax() { return $GLOBALS['fo_test_inclusive'] ?? false; }

class WC_Tax {
	public static array $rates = array( 1 => 21.0, 2 => 12.0, 3 => 20.0 );
	public static function get_rate_percent_value( $id ) { return self::$rates[ $id ]; }
}

class Fake_Item {
	public function __construct( public string $name, public float $qty, public float $subtotal, public float $subtotal_tax, public float $total, public float $total_tax, public ?int $rate_id, public string $sku = '' ) {}
	public function get_name() { return $this->name; }
	public function get_quantity() { return $this->qty; }
	public function get_subtotal() { return $this->subtotal; }
	public function get_subtotal_tax() { return $this->subtotal_tax; }
	public function get_total() { return $this->total; }
	public function get_total_tax() { return $this->total_tax; }
	public function get_taxes() { return $this->rate_id ? array( 'total' => array( $this->rate_id => $this->total_tax ) ) : array( 'total' => array() ); }
	public function get_product() { return $this->sku ? new Fake_Product( $this->sku ) : false; }
}
class Fake_Product {
	public function __construct( private string $sku ) {}
	public function get_sku() { return $this->sku; }
}

class Fake_Order {
	public array $items = array(); public array $shipping = array(); public array $fees = array();
	public array $meta = array(); public string $gateway = 'bacs'; public string $company = '';
	public function get_id() { return 42; }
	public function get_order_number() { return '#1234'; }
	public function get_currency() { return 'CZK'; }
	public function get_payment_method() { return $this->gateway; }
	public function get_billing_company() { return $this->company; }
	public function get_billing_first_name() { return 'Jan'; }
	public function get_billing_last_name() { return 'Novák'; }
	public function get_billing_email() { return 'jan@example.com'; }
	public function get_billing_phone() { return '+420111222333'; }
	public function get_billing_address_1() { return 'Dlouhá 10'; }
	public function get_billing_address_2() { return ''; }
	public function get_billing_city() { return 'Praha'; }
	public function get_billing_postcode() { return '11000'; }
	public function get_billing_country() { return 'CZ'; }
	public function get_meta( $k ) { return $this->meta[ $k ] ?? ''; }
	public function get_items() { return $this->items; }
	public function get_shipping_methods() { return $this->shipping; }
	public function get_fees() { return $this->fees; }
}
