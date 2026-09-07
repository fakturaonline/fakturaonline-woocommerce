<?php
// Run: php -d zend.assertions=1 -d assert.exception=1 tests/test-invoice-builder.php
require __DIR__ . '/fake-order.php';
require __DIR__ . '/../includes/class-fo-invoice-builder.php';

$template = array( 'number' => '2026001', 'registration_number' => '2026001', 'seller' => array( 'name' => 'Eshop s.r.o.', 'company_number' => '12345678', 'tax_number' => 'CZ12345678', 'street' => 'Krátká 1', 'city' => 'Brno', 'postcode' => '60200', 'country_code' => 'CZ', 'bank_account_number' => '123/0100', 'id' => 999 ) );
$settings = array( 'kind' => 'vat_invoice', 'due_in' => '14' );

/** Total the way FakturaOnline computes it: per line, qty × price rounded to 2, VAT rounded to 2. */
function fo_total( array $payload ): float {
	$sum = 0;
	foreach ( $payload['lines_attributes'] as $l ) {
		$net  = round( (float) $l['price'] * (float) $l['quantity'], 2 );
		$sum += $payload['vat_calculation'] === 'vat_inclusive' ? $net : $net + round( $net * (float) $l['vat_rate'] / 100, 2 );
	}
	return round( $sum, 2 );
}

// --- vat_exclusive, mirrors dev order 16: 10 % coupon on 3× 99.99 @21 % + book @12 %, shipping @21 %, untaxed fee
$o          = new Fake_Order();
$o->meta    = array( '_billing_ico' => '87654321', '_billing_dic' => 'CZ87654321' );
$o->company = 'ABC s.r.o.';
$o->items   = array(
	new Fake_Item( 'Tričko', 3, 299.97, 62.99, 269.98, 56.70, 1, 'TS-1' ),
	new Fake_Item( 'Kniha', 1, 200.00, 24.00, 179.99, 21.60, 2 ),
);
$o->shipping = array( new Fake_Item( 'Zásilkovna', 1, 79, 16.59, 79, 16.59, 1 ) );
$o->fees     = array( new Fake_Item( 'Balné', 1, 10, 0, 10, 0, null ) );

$p = FO_Invoice_Builder::build( $o, $template, $settings );

assert( $p['kind'] === 'vat_invoice' );
assert( $p['number'] === '2026001' );
assert( $p['vat_calculation'] === 'vat_exclusive' );
assert( $p['means_of_payment'] === 'bank_transfer' );
assert( $p['payment_symbol'] === '1234' );
assert( $p['buyer_attributes']['name'] === 'ABC s.r.o.' );
assert( $p['buyer_attributes']['company_number'] === '87654321' );
assert( $p['buyer_attributes']['tax_number'] === 'CZ87654321' );
assert( $p['buyer_attributes']['address_attributes']['street'] === 'Dlouhá 10' );
assert( $p['seller_attributes']['name'] === 'Eshop s.r.o.' );
assert( ! isset( $p['seller_attributes']['id'] ) );

$lines = $p['lines_attributes'];
assert( count( $lines ) === 4, 'item, item, shipping, fee' );
assert( $lines[0] === array( 'description' => 'Tričko (TS-1) (sleva 10 %)', 'quantity' => '3', 'unit_type' => 'ks', 'price' => '89.9933', 'vat_rate' => '21' ), json_encode( $lines[0] ) );
assert( $lines[1]['description'] === 'Kniha (sleva 10 %)' );
assert( $lines[1]['vat_rate'] === '12' && $lines[1]['price'] === '179.99' );
assert( $lines[2]['description'] === 'Zásilkovna' && $lines[2]['price'] === '79' && $lines[2]['vat_rate'] === '21' );
assert( $lines[3]['description'] === 'Balné' && $lines[3]['vat_rate'] === '0' );
// WooCommerce: 269.98+56.70 + 179.99+21.60 + 79+16.59 + 10 = 633.86
assert( abs( fo_total( $p ) - 633.86 ) < 0.001, 'total ' . fo_total( $p ) );

// --- no discount: unit price stays the catalogue price
$o1        = new Fake_Order();
$o1->items = array( new Fake_Item( 'Tričko', 3, 299.97, 62.99, 299.97, 62.99, 1 ) );
$p1        = FO_Invoice_Builder::build( $o1, $template, $settings );
assert( $p1['lines_attributes'][0]['description'] === 'Tričko' && $p1['lines_attributes'][0]['price'] === '99.99' );
assert( abs( fo_total( $p1 ) - 362.96 ) < 0.001 );

// --- vat_inclusive: same shop with prices entered incl. tax
$GLOBALS['fo_test_inclusive'] = true;
$o2          = new Fake_Order();
$o2->gateway = 'stripe';
$o2->items   = array( new Fake_Item( 'Tričko', 2, 165.29, 34.71, 165.29, 34.71, 1 ) ); // 2× 100 gross
$p2          = FO_Invoice_Builder::build( $o2, $template, $settings );
assert( $p2['vat_calculation'] === 'vat_inclusive' );
assert( $p2['means_of_payment'] === 'credit_card' );
assert( $p2['buyer_attributes']['name'] === 'Jan Novák' );
assert( $p2['lines_attributes'][0]['price'] === '100' );
assert( abs( fo_total( $p2 ) - 200 ) < 0.001 );

// --- logo/stamp ids resolved by the hooks are passed through; absent when not configured
assert( ! isset( $p['logo_id'] ) && ! isset( $p['stamp_id'] ) );
$p3 = FO_Invoice_Builder::build( $o1, $template, $settings + array( 'fo_logo_id' => 555, 'fo_stamp_id' => '' ) );
assert( $p3['logo_id'] === 555 && ! isset( $p3['stamp_id'] ) );

assert( FO_Invoice_Builder::means_of_payment( 'cod' ) === 'cash_on_delivery' );
assert( FO_Invoice_Builder::means_of_payment( 'paypal' ) === 'paypal' );

echo "OK\n";
