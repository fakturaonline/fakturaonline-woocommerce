<?php
defined( 'ABSPATH' ) || exit;

class FO_Settings {
	const OPTION = 'fakturaonline_settings';

	const TLDS           = array( 'cz', 'sk' );
	const DUE_IN_OPTIONS = array( '0', '1', '7', '10', '14', '15', '21', '30', '45', '60', '90' );

	/** Seller fields: option key => FO `seller_attributes` key (same names, listed once so both sides stay in sync). */
	const SELLER_FIELDS = array(
		'seller_name'                => 'name',
		'seller_company_number'      => 'company_number',
		'seller_tax_number'          => 'tax_number',
		'seller_vat_number'          => 'vat_number',
		'seller_street'              => 'street',
		'seller_city'                => 'city',
		'seller_postcode'            => 'postcode',
		'seller_country_code'        => 'country_code',
		'seller_email'               => 'email',
		'seller_phone'               => 'phone',
		'seller_bank_account_number' => 'bank_account_number',
		'seller_iban'                => 'iban',
		'seller_swift'               => 'swift',
	);

	public static function defaults(): array {
		return array(
			'tld'            => 'cz',
			'api_key'        => '',
			'kind'           => 'vat_invoice',
			'trigger_status' => 'completed',
			'due_in'         => '14',
			'attach_pdf'     => '0',
			'logo_id'        => '',
			'stamp_id'       => '',
		) + array( 'seller_country_code' => 'CZ' ) + array_fill_keys( array_keys( self::SELLER_FIELDS ), '' );
	}

	/** Seller from the plugin settings as FO `seller_attributes`, or an empty array when the name is blank. */
	public static function seller(): array {
		$s = self::get();
		if ( $s['seller_name'] === '' ) {
			return array();
		}
		$seller = array();
		foreach ( self::SELLER_FIELDS as $option_key => $api_key ) {
			if ( $s[ $option_key ] !== '' ) {
				$seller[ $api_key ] = $s[ $option_key ];
			}
		}
		return $seller;
	}

	public static function get(): array {
		return array_merge( self::defaults(), (array) get_option( self::OPTION, array() ) );
	}

	/** Define FO_BASE_URL in wp-config.php to target a non-production instance (FO_SSL_VERIFY = false additionally skips certificate checks). */
	public static function api_base_url(): string {
		return defined( 'FO_BASE_URL' )
			? rtrim( FO_BASE_URL, '/' ) . '/api'
			: 'https://api.fakturaonline.' . self::get()['tld'] . '/api';
	}

	public static function app_base_url(): string {
		return defined( 'FO_BASE_URL' )
			? rtrim( FO_BASE_URL, '/' )
			: 'https://www.fakturaonline.' . self::get()['tld'];
	}

	/**
	 * Seller values WooCommerce already knows (store address, site title, first BACS account),
	 * shown as defaults while the plugin's seller is still empty. Nothing is stored until saved.
	 */
	public static function seller_suggestions(): array {
		$country = strtoupper( substr( (string) get_option( 'woocommerce_default_country' ), 0, 2 ) );
		$bacs    = (array) get_option( 'woocommerce_bacs_accounts', array() );
		$bacs    = is_array( reset( $bacs ) ) ? reset( $bacs ) : array();

		return array(
			'seller_name'                => (string) get_option( 'blogname' ),
			'seller_street'              => trim( get_option( 'woocommerce_store_address', '' ) . ' ' . get_option( 'woocommerce_store_address_2', '' ) ),
			'seller_city'                => (string) get_option( 'woocommerce_store_city', '' ),
			'seller_postcode'            => (string) get_option( 'woocommerce_store_postcode', '' ),
			'seller_country_code'        => in_array( $country, array( 'CZ', 'SK' ), true ) ? $country : 'CZ',
			'seller_email'               => (string) get_option( 'woocommerce_email_from_address', get_option( 'admin_email' ) ),
			'seller_bank_account_number' => (string) ( $bacs['account_number'] ?? '' ),
			'seller_iban'                => (string) ( $bacs['iban'] ?? '' ),
			'seller_swift'               => (string) ( $bacs['bic'] ?? '' ),
		);
	}

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ) );
		add_action( 'admin_enqueue_scripts', function ( $hook ) {
			if ( $hook === 'woocommerce_page_fakturaonline' ) {
				wp_enqueue_media();
			}
		} );
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
		// options.php demands manage_options by default; let shop managers save too, matching the menu.
		add_filter( 'option_page_capability_fakturaonline', fn() => 'manage_woocommerce' );
		add_action( 'admin_post_fo_test_connection', array( __CLASS__, 'test_connection' ) );
	}

	public static function add_menu(): void {
		add_submenu_page(
			'woocommerce',
			__( 'FakturaOnline', 'fakturaonline-woocommerce' ),
			__( 'FakturaOnline', 'fakturaonline-woocommerce' ),
			'manage_woocommerce',
			'fakturaonline',
			array( __CLASS__, 'render_page' )
		);
	}

	public static function register(): void {
		register_setting( 'fakturaonline', self::OPTION, array( 'sanitize_callback' => array( __CLASS__, 'sanitize' ) ) );
	}

	public static function sanitize( $input ): array {
		$input = (array) $input;
		$out   = self::defaults();

		$out['tld']            = in_array( $input['tld'] ?? '', self::TLDS, true ) ? $input['tld'] : 'cz';
		$out['api_key']        = trim( (string) ( $input['api_key'] ?? '' ) );
		$out['kind']           = in_array( $input['kind'] ?? '', array( 'vat_invoice', 'invoice' ), true ) ? $input['kind'] : 'vat_invoice';
		$out['trigger_status'] = in_array( $input['trigger_status'] ?? '', array( 'completed', 'processing', 'manual' ), true ) ? $input['trigger_status'] : 'completed';
		$out['due_in']         = in_array( (string) ( $input['due_in'] ?? '' ), self::DUE_IN_OPTIONS, true ) ? (string) $input['due_in'] : '14';
		$out['attach_pdf']     = ! empty( $input['attach_pdf'] ) ? '1' : '0';

		foreach ( array_keys( self::SELLER_FIELDS ) as $key ) {
			$out[ $key ] = sanitize_text_field( (string) ( $input[ $key ] ?? '' ) );
		}
		foreach ( array( 'logo_id', 'stamp_id' ) as $key ) {
			$id          = absint( $input[ $key ] ?? 0 );
			$out[ $key ] = $id && wp_attachment_is_image( $id ) ? (string) $id : '';
		}
		$out['seller_country_code'] = in_array( $out['seller_country_code'], array( 'CZ', 'SK' ), true ) ? $out['seller_country_code'] : 'CZ';

		return $out;
	}

	public static function render_page(): void {
		$s = self::get();
		if ( $s['seller_name'] === '' ) {
			$s = array_merge( $s, self::seller_suggestions() );
		}
		$name = fn( string $key ) => self::OPTION . '[' . $key . ']';
		$text = function ( string $key, string $label, string $type = 'text' ) use ( $s, $name ) {
			printf(
				'<tr><th scope="row"><label for="fo-%1$s">%2$s</label></th><td><input type="%3$s" id="fo-%1$s" class="regular-text" name="%4$s" value="%5$s"%6$s></td></tr>',
				esc_attr( $key ),
				esc_html( $label ),
				esc_attr( $type ),
				esc_attr( $name( $key ) ),
				esc_attr( $s[ $key ] ),
				$type === 'password' ? ' autocomplete="off"' : ''
			);
		};
		$test_url = wp_nonce_url( admin_url( 'admin-post.php?action=fo_test_connection' ), 'fo_test_connection' );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'FakturaOnline', 'fakturaonline-woocommerce' ); ?></h1>
			<?php if ( isset( $_GET['fo_test'] ) ) : ?>
				<div class="notice <?php echo $_GET['fo_test'] === 'ok' ? 'notice-success' : 'notice-error'; ?> is-dismissible"><p>
					<?php echo esc_html( $_GET['fo_test'] === 'ok'
						? __( 'Spojení s FakturaOnline funguje.', 'fakturaonline-woocommerce' )
						: sanitize_text_field( wp_unslash( $_GET['fo_test'] ) ) ); ?>
				</p></div>
			<?php endif; ?>
			<?php settings_errors(); ?>

			<form method="post" action="options.php" class="metabox-holder" style="max-width:960px">
				<?php settings_fields( 'fakturaonline' ); ?>

				<div class="postbox"><h2 class="hndle" style="padding:8px 12px;margin:0"><?php esc_html_e( 'Připojení', 'fakturaonline-woocommerce' ); ?></h2><div class="inside">
					<table class="form-table" role="presentation">
						<tr><th scope="row"><?php esc_html_e( 'Instance', 'fakturaonline-woocommerce' ); ?></th><td>
							<select name="<?php echo esc_attr( $name( 'tld' ) ); ?>">
								<?php foreach ( self::TLDS as $tld ) : ?>
									<option value="<?php echo esc_attr( $tld ); ?>" <?php selected( $s['tld'], $tld ); ?>>fakturaonline.<?php echo esc_html( $tld ); ?></option>
								<?php endforeach; ?>
							</select>
						</td></tr>
						<tr><th scope="row"><label for="fo-api_key"><?php esc_html_e( 'API klíč', 'fakturaonline-woocommerce' ); ?></label></th><td>
							<input type="password" id="fo-api_key" class="regular-text" name="<?php echo esc_attr( $name( 'api_key' ) ); ?>" value="<?php echo esc_attr( $s['api_key'] ); ?>" autocomplete="off">
							<a class="button" href="<?php echo esc_url( $test_url ); ?>"><?php esc_html_e( 'Otestovat spojení', 'fakturaonline-woocommerce' ); ?></a>
							<p class="description"><?php esc_html_e( 'Vygenerujte ve FakturaOnline: Nastavení → API klíče. Klíč začíná fo_live_ a zobrazí se jen jednou. Test používá uložený klíč, po změně nejdřív uložte.', 'fakturaonline-woocommerce' ); ?></p>
						</td></tr>
					</table>
				</div></div>

				<div class="postbox"><h2 class="hndle" style="padding:8px 12px;margin:0"><?php esc_html_e( 'Vystavování faktur', 'fakturaonline-woocommerce' ); ?></h2><div class="inside">
					<table class="form-table" role="presentation">
						<tr><th scope="row"><?php esc_html_e( 'Druh dokladu', 'fakturaonline-woocommerce' ); ?></th><td>
							<label><input type="radio" name="<?php echo esc_attr( $name( 'kind' ) ); ?>" value="vat_invoice" <?php checked( $s['kind'], 'vat_invoice' ); ?>> <?php esc_html_e( 'Faktura – daňový doklad (jsem plátce DPH)', 'fakturaonline-woocommerce' ); ?></label><br>
							<label><input type="radio" name="<?php echo esc_attr( $name( 'kind' ) ); ?>" value="invoice" <?php checked( $s['kind'], 'invoice' ); ?>> <?php esc_html_e( 'Faktura (nejsem plátce DPH)', 'fakturaonline-woocommerce' ); ?></label>
						</td></tr>
						<tr><th scope="row"><?php esc_html_e( 'Vystavit automaticky při stavu', 'fakturaonline-woocommerce' ); ?></th><td>
							<select name="<?php echo esc_attr( $name( 'trigger_status' ) ); ?>">
								<option value="completed" <?php selected( $s['trigger_status'], 'completed' ); ?>><?php esc_html_e( 'Dokončeno', 'fakturaonline-woocommerce' ); ?></option>
								<option value="processing" <?php selected( $s['trigger_status'], 'processing' ); ?>><?php esc_html_e( 'Zpracovává se', 'fakturaonline-woocommerce' ); ?></option>
								<option value="manual" <?php selected( $s['trigger_status'], 'manual' ); ?>><?php esc_html_e( 'Nevystavovat automaticky (jen ručně)', 'fakturaonline-woocommerce' ); ?></option>
							</select>
							<p class="description"><?php esc_html_e( 'Fakturu jde vždy vystavit i ručně tlačítkem v detailu objednávky.', 'fakturaonline-woocommerce' ); ?></p>
						</td></tr>
						<tr><th scope="row"><?php esc_html_e( 'Splatnost (dní)', 'fakturaonline-woocommerce' ); ?></th><td>
							<select name="<?php echo esc_attr( $name( 'due_in' ) ); ?>">
								<?php foreach ( self::DUE_IN_OPTIONS as $d ) : ?>
									<option value="<?php echo esc_attr( $d ); ?>" <?php selected( $s['due_in'], $d ); ?>><?php echo esc_html( $d ); ?></option>
								<?php endforeach; ?>
							</select>
						</td></tr>
						<tr><th scope="row"><?php esc_html_e( 'PDF v e-mailu', 'fakturaonline-woocommerce' ); ?></th><td>
							<label><input type="checkbox" name="<?php echo esc_attr( $name( 'attach_pdf' ) ); ?>" value="1" <?php checked( $s['attach_pdf'], '1' ); ?>> <?php esc_html_e( 'Přiložit PDF faktury k e-mailu „Objednávka dokončena"', 'fakturaonline-woocommerce' ); ?></label>
						</td></tr>
					</table>
				</div></div>

				<div class="postbox"><h2 class="hndle" style="padding:8px 12px;margin:0"><?php esc_html_e( 'Dodavatel', 'fakturaonline-woocommerce' ); ?></h2><div class="inside">
					<p class="description"><?php esc_html_e( 'Údaje vašeho e-shopu na faktuře. Dokud nejsou uložené, nabízí se adresa obchodu, e-mail a účet z WooCommerce; doplňte IČO a DIČ a uložte. S prázdným názvem plugin převezme dodavatele z poslední faktury ve FakturaOnline.', 'fakturaonline-woocommerce' ); ?></p>
					<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(380px,1fr));gap:0 32px">
						<div>
							<h3><?php esc_html_e( 'Firma', 'fakturaonline-woocommerce' ); ?></h3>
							<table class="form-table" role="presentation">
								<?php
								$text( 'seller_name', __( 'Název / jméno', 'fakturaonline-woocommerce' ) );
								$text( 'seller_company_number', __( 'IČO', 'fakturaonline-woocommerce' ) );
								$text( 'seller_tax_number', __( 'DIČ', 'fakturaonline-woocommerce' ) );
								$text( 'seller_vat_number', __( 'IČ DPH (jen SK)', 'fakturaonline-woocommerce' ) );
								?>
							</table>
							<h3><?php esc_html_e( 'Kontakt', 'fakturaonline-woocommerce' ); ?></h3>
							<table class="form-table" role="presentation">
								<?php
								$text( 'seller_email', __( 'E-mail', 'fakturaonline-woocommerce' ), 'email' );
								$text( 'seller_phone', __( 'Telefon', 'fakturaonline-woocommerce' ), 'tel' );
								?>
							</table>
						</div>
						<div>
							<h3><?php esc_html_e( 'Adresa', 'fakturaonline-woocommerce' ); ?></h3>
							<table class="form-table" role="presentation">
								<?php
								$text( 'seller_street', __( 'Ulice a č. p.', 'fakturaonline-woocommerce' ) );
								$text( 'seller_city', __( 'Město', 'fakturaonline-woocommerce' ) );
								$text( 'seller_postcode', __( 'PSČ', 'fakturaonline-woocommerce' ) );
								?>
								<tr><th scope="row"><?php esc_html_e( 'Země', 'fakturaonline-woocommerce' ); ?></th><td>
									<select name="<?php echo esc_attr( $name( 'seller_country_code' ) ); ?>">
										<option value="CZ" <?php selected( $s['seller_country_code'], 'CZ' ); ?>><?php esc_html_e( 'Česko', 'fakturaonline-woocommerce' ); ?></option>
										<option value="SK" <?php selected( $s['seller_country_code'], 'SK' ); ?>><?php esc_html_e( 'Slovensko', 'fakturaonline-woocommerce' ); ?></option>
									</select>
								</td></tr>
							</table>
							<h3><?php esc_html_e( 'Bankovní spojení', 'fakturaonline-woocommerce' ); ?></h3>
							<table class="form-table" role="presentation">
								<?php
								$text( 'seller_bank_account_number', __( 'Číslo účtu', 'fakturaonline-woocommerce' ) );
								$text( 'seller_iban', __( 'IBAN', 'fakturaonline-woocommerce' ) );
								$text( 'seller_swift', __( 'SWIFT / BIC', 'fakturaonline-woocommerce' ) );
								?>
							</table>
						</div>
					</div>
				</div></div>

				<div class="postbox"><h2 class="hndle" style="padding:8px 12px;margin:0"><?php esc_html_e( 'Logo a razítko', 'fakturaonline-woocommerce' ); ?></h2><div class="inside">
					<p class="description"><?php esc_html_e( 'Obrázky z knihovny médií (jpg, png, gif). Plugin je nahraje do FakturaOnline při prvním vystavení faktury.', 'fakturaonline-woocommerce' ); ?></p>
					<table class="form-table" role="presentation">
						<?php foreach ( array( 'logo_id' => __( 'Logo', 'fakturaonline-woocommerce' ), 'stamp_id' => __( 'Razítko', 'fakturaonline-woocommerce' ) ) as $key => $label ) : ?>
							<tr><th scope="row"><?php echo esc_html( $label ); ?></th><td class="fo-media" data-key="<?php echo esc_attr( $key ); ?>">
								<input type="hidden" name="<?php echo esc_attr( $name( $key ) ); ?>" value="<?php echo esc_attr( $s[ $key ] ); ?>">
								<img src="<?php echo esc_url( $s[ $key ] ? (string) wp_get_attachment_image_url( (int) $s[ $key ], 'medium' ) : '' ); ?>" alt="" style="max-height:80px;display:<?php echo $s[ $key ] ? 'block' : 'none'; ?>;margin-bottom:6px">
								<button type="button" class="button fo-media-pick"><?php esc_html_e( 'Vybrat', 'fakturaonline-woocommerce' ); ?></button>
								<button type="button" class="button fo-media-clear" style="display:<?php echo $s[ $key ] ? 'inline-block' : 'none'; ?>"><?php esc_html_e( 'Odebrat', 'fakturaonline-woocommerce' ); ?></button>
							</td></tr>
						<?php endforeach; ?>
					</table>
				</div></div>

				<?php submit_button(); ?>
			</form>
		</div>
		<script>
		jQuery( function ( $ ) {
			$( '.fo-media-pick' ).on( 'click', function () {
				var cell = $( this ).closest( '.fo-media' );
				var frame = wp.media( { title: cell.closest( 'tr' ).find( 'th' ).text(), library: { type: 'image' }, multiple: false } );
				frame.on( 'select', function () {
					var a = frame.state().get( 'selection' ).first().toJSON();
					cell.find( 'input' ).val( a.id );
					cell.find( 'img' ).attr( 'src', ( a.sizes && a.sizes.medium ? a.sizes.medium.url : a.url ) ).show();
					cell.find( '.fo-media-clear' ).show();
				} );
				frame.open();
			} );
			$( '.fo-media-clear' ).on( 'click', function () {
				var cell = $( this ).closest( '.fo-media' );
				cell.find( 'input' ).val( '' );
				cell.find( 'img' ).hide();
				$( this ).hide();
			} );
		} );
		</script>
		<?php
	}

	public static function test_connection(): void {
		check_admin_referer( 'fo_test_connection' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( 'Forbidden' );
		}
		$result = ( new FO_API_Client() )->template( self::get()['kind'] );
		$msg    = is_wp_error( $result ) ? $result->get_error_message() : 'ok';
		wp_safe_redirect( add_query_arg( array( 'page' => 'fakturaonline', 'fo_test' => rawurlencode( $msg ) ), admin_url( 'admin.php' ) ) );
		exit;
	}
}
