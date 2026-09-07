#!/usr/bin/env bash
# One-shot local environment: WordPress + WooCommerce + this plugin + Mailpit.
# Usage: dev/setup.sh            (idempotent; re-run to reset WooCommerce settings)
#        dev/setup.sh --reset    (drop volumes and start from scratch)
set -euo pipefail
cd "$(dirname "$0")"

if [[ "${1:-}" == "--reset" ]]; then
  docker compose down -v
fi

docker compose up -d db wordpress mailpit

wp() { docker compose run --rm -T cli "$@"; }

# Wait until the wordpress container has unpacked core into the shared volume.
for _ in $(seq 1 60); do
  wp core version >/dev/null 2>&1 && break
  sleep 2
done

if ! wp core is-installed >/dev/null 2>&1; then
  wp core install --url=http://localhost:8088 --title="FO test shop" \
    --admin_user=admin --admin_password=admin --admin_email=admin@example.com --skip-email
fi

wp language core install cs_CZ --activate >/dev/null 2>&1 || true
wp plugin install woocommerce --activate
wp plugin activate fakturaonline-woocommerce
wp option update timezone_string Europe/Prague

# Shop basics: CZ, CZK, taxes on, prices entered without tax, 21 % standard rate.
wp option update woocommerce_default_country CZ
wp option update woocommerce_currency CZK
wp option update woocommerce_calc_taxes yes
wp option update woocommerce_prices_include_tax no
wp option update woocommerce_onboarding_profile --format=json '{"skipped":true}'
wp option update woocommerce_coming_soon no
if [[ "$(wp wc tax list --user=admin --format=count)" == "0" ]]; then
  wp wc tax create --user=admin --country=CZ --rate=21 --name="DPH 21" --class=standard >/dev/null
  wp wc tax create --user=admin --country=CZ --rate=12 --name="DPH 12" --class=reduced-rate >/dev/null
fi

# Payment gateways: bank transfer + cash on delivery.
wp option patch update woocommerce_bacs_settings enabled yes >/dev/null 2>&1 || wp option update woocommerce_bacs_settings --format=json '{"enabled":"yes","title":"Bankovní převod"}'
wp option patch update woocommerce_cod_settings enabled yes >/dev/null 2>&1 || wp option update woocommerce_cod_settings --format=json '{"enabled":"yes","title":"Dobírka"}'

# Products + a 10 % coupon, only once.
if [[ "$(wp wc product list --user=admin --format=count)" == "0" ]]; then
  wp wc product create --user=admin --name="Tričko" --sku=TS-1 --regular_price=99.99 --type=simple --tax_class=standard >/dev/null
  wp wc product create --user=admin --name="Kniha" --sku=BK-1 --regular_price=200 --type=simple --tax_class=reduced-rate >/dev/null
  wp wc shop_coupon create --user=admin --code=SLEVA10 --discount_type=percent --amount=10 >/dev/null
fi

# Flat-rate shipping 79 CZK for CZ (wp-cli has no zone-location writer, so PHP).
wp eval '
$zone = null;
foreach ( WC_Shipping_Zones::get_zones() as $z ) { if ( $z["zone_name"] === "Česko" ) { $zone = new WC_Shipping_Zone( $z["id"] ); } }
if ( ! $zone ) { $zone = new WC_Shipping_Zone(); $zone->set_zone_name( "Česko" ); $zone->save(); }
$zone->set_locations( array( array( "code" => "CZ", "type" => "country" ) ) );
if ( ! $zone->get_shipping_methods() ) {
  $id = $zone->add_shipping_method( "flat_rate" );
  update_option( "woocommerce_flat_rate_{$id}_settings", array( "title" => "Zásilkovna", "cost" => "79", "tax_status" => "taxable" ) );
}
$zone->save();
echo "shipping zone ok\n";
'

# HPOS (custom order tables) on, as on a fresh WooCommerce install through the UI.
wp wc hpos enable >/dev/null 2>&1 || wp option update woocommerce_custom_orders_table_enabled yes

cat <<MSG

Hotovo.
  Admin:    http://localhost:8088/wp-admin   (admin / admin)
  Plugin:   http://localhost:8088/wp-admin/admin.php?page=fakturaonline
  Mailpit:  http://localhost:8025
  Logy:     docker compose -f dev/docker-compose.yml exec wordpress tail -f wp-content/debug.log
MSG
