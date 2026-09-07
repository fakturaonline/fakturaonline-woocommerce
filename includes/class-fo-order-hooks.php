<?php
defined( 'ABSPATH' ) || exit;

class FO_Order_Hooks {
	const META_ID     = '_fakturaonline_invoice_id';
	const META_NUMBER = '_fakturaonline_invoice_number';
	const META_URL    = '_fakturaonline_public_url';

	/** order id => temp PDF path, while the "completed" e-mail is being sent. */
	private static array $tmp_pdfs = array();

	public static function init(): void {
		$settings = FO_Settings::get();
		if ( $settings['trigger_status'] !== 'manual' ) {
			// Priority 5: WooCommerce hooks its transactional e-mails on the same action at 10,
			// so the invoice must exist before the "completed" e-mail collects attachments.
			add_action( 'woocommerce_order_status_' . $settings['trigger_status'], array( __CLASS__, 'on_status' ), 5, 2 );
		}
		add_action( 'add_meta_boxes', array( __CLASS__, 'add_meta_box' ) );
		add_action( 'admin_post_fo_issue_invoice', array( __CLASS__, 'handle_issue' ) );
		add_action( 'admin_post_fo_download_pdf', array( __CLASS__, 'handle_pdf' ) );

		if ( $settings['attach_pdf'] === '1' ) {
			add_filter( 'woocommerce_email_attachments', array( __CLASS__, 'email_attachments' ), 10, 3 );
			add_action( 'woocommerce_email_sent', array( __CLASS__, 'cleanup_attachment' ), 10, 3 );
		}
	}

	public static function on_status( int $order_id, $order ): void {
		if ( $order instanceof WC_Order ) {
			self::issue( $order );
		}
	}

	/** Creates the invoice once; safe to call repeatedly. */
	public static function issue( WC_Order $order ): bool|WP_Error {
		if ( $order->get_meta( self::META_ID ) ) {
			return true;
		}
		$lock = 'fo_lock_' . $order->get_id();
		if ( get_transient( $lock ) ) {
			return new WP_Error( 'fo_locked', 'Vystavení již probíhá.' );
		}
		set_transient( $lock, 1, 60 );

		$result = self::create_invoice( $order );

		delete_transient( $lock );

		if ( is_wp_error( $result ) ) {
			$order->add_order_note( sprintf( __( 'FakturaOnline: fakturu se nepodařilo vystavit. %s', 'fakturaonline-woocommerce' ), $result->get_error_message() ) );
			return $result;
		}
		$order->add_order_note( sprintf( __( 'FakturaOnline: vystavena faktura %s.', 'fakturaonline-woocommerce' ), $result['number'] ) );
		return true;
	}

	private static function create_invoice( WC_Order $order ): array|WP_Error {
		$settings = FO_Settings::get();
		$client   = new FO_API_Client();

		$template = $client->template( $settings['kind'] );
		if ( is_wp_error( $template ) ) {
			return $template;
		}
		if ( empty( $template['number'] ) ) {
			return new WP_Error( 'fo_no_number', 'Šablona z FakturaOnline neobsahuje číslo faktury.' );
		}
		// Seller from the plugin settings wins; otherwise FO's template carries the seller of the account's
		// last invoice. A brand-new account has neither, and an invoice without a seller has no PDF.
		$seller = FO_Settings::seller();
		if ( $seller ) {
			$template['seller'] = $seller;
		}
		if ( empty( $template['seller']['name'] ) ) {
			return new WP_Error( 'fo_no_seller', __( 'Chybí dodavatel. Vyplňte ho v nastavení pluginu (WooCommerce → FakturaOnline).', 'fakturaonline-woocommerce' ) );
		}

		$attachments = self::fo_attachment_ids( $client, $settings );
		if ( is_wp_error( $attachments ) ) {
			return $attachments;
		}

		$created = $client->create( FO_Invoice_Builder::build( $order, $template, $settings + $attachments ) );
		if ( is_wp_error( $created ) ) {
			return $created;
		}

		$order->update_meta_data( self::META_ID, $created['invoice_id'] );
		$order->update_meta_data( self::META_NUMBER, $created['number'] );
		$order->update_meta_data( self::META_URL, $created['public_url'] );
		$order->save();

		if ( $order->is_paid() ) {
			$paid = $client->mark_paid( $created['invoice_id'] );
			if ( is_wp_error( $paid ) ) {
				$order->add_order_note( 'FakturaOnline: fakturu se nepodařilo označit jako zaplacenou. ' . $paid->get_error_message() );
			}
		}
		return $created;
	}

	/**
	 * FO ids of the logo/stamp picked in the settings, uploaded on first use and cached per
	 * (WP attachment, API key, instance) so a changed key or instance re-uploads instead of
	 * pointing at another tenant's attachment.
	 */
	private static function fo_attachment_ids( FO_API_Client $client, array $settings ): array|WP_Error {
		$ids   = array();
		$cache = (array) get_option( 'fakturaonline_uploads', array() );
		$scope = md5( $settings['api_key'] . '|' . FO_Settings::api_base_url() );

		foreach ( array( 'logo' => 'logo_id', 'stamp' => 'stamp_id' ) as $type => $key ) {
			$attachment_id = (int) $settings[ $key ];
			if ( ! $attachment_id ) {
				continue;
			}
			$cache_key = "{$type}:{$attachment_id}:{$scope}";
			if ( empty( $cache[ $cache_key ] ) ) {
				$fo_id = $client->upload( $type, (string) get_attached_file( $attachment_id ) );
				if ( is_wp_error( $fo_id ) ) {
					return $fo_id;
				}
				$cache[ $cache_key ] = $fo_id;
				update_option( 'fakturaonline_uploads', $cache, false );
			}
			$ids[ 'fo_' . $key ] = (int) $cache[ $cache_key ];
		}
		return $ids;
	}

	// ---- admin UI -----------------------------------------------------------

	public static function add_meta_box(): void {
		$screen = class_exists( \Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController::class )
			&& wc_get_container()->get( \Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController::class )->custom_orders_table_usage_is_enabled()
			? wc_get_page_screen_id( 'shop-order' )
			: 'shop_order';
		add_meta_box( 'fakturaonline', __( 'FakturaOnline', 'fakturaonline-woocommerce' ), array( __CLASS__, 'render_meta_box' ), $screen, 'side' );
	}

	public static function render_meta_box( $post_or_order ): void {
		$order = $post_or_order instanceof WC_Order ? $post_or_order : wc_get_order( $post_or_order->ID );
		if ( ! $order ) {
			return;
		}
		$id = $order->get_meta( self::META_ID );
		if ( $id ) {
			$number  = $order->get_meta( self::META_NUMBER );
			$app_url = FO_Settings::app_base_url() . preg_replace( '/\.pdf$/', '', (string) $order->get_meta( self::META_URL ) );
			$pdf_url = wp_nonce_url( admin_url( 'admin-post.php?action=fo_download_pdf&order_id=' . $order->get_id() ), 'fo_download_pdf_' . $order->get_id() );
			echo '<p>' . esc_html__( 'Faktura', 'fakturaonline-woocommerce' ) . ': <strong>' . esc_html( $number ) . '</strong></p>';
			echo '<p><a class="button" href="' . esc_url( $pdf_url ) . '">' . esc_html__( 'Stáhnout PDF', 'fakturaonline-woocommerce' ) . '</a> ';
			echo '<a href="' . esc_url( $app_url ) . '" target="_blank">' . esc_html__( 'Otevřít ve FO', 'fakturaonline-woocommerce' ) . '</a></p>';
			return;
		}
		$issue_url = wp_nonce_url( admin_url( 'admin-post.php?action=fo_issue_invoice&order_id=' . $order->get_id() ), 'fo_issue_invoice_' . $order->get_id() );
		echo '<p>' . esc_html__( 'Faktura zatím nebyla vystavena.', 'fakturaonline-woocommerce' ) . '</p>';
		echo '<p><a class="button button-primary" href="' . esc_url( $issue_url ) . '">' . esc_html__( 'Vystavit fakturu', 'fakturaonline-woocommerce' ) . '</a></p>';
	}

	public static function handle_issue(): void {
		$order = self::order_from_request( 'fo_issue_invoice' );
		self::issue( $order ); // outcome lands in an order note either way
		wp_safe_redirect( $order->get_edit_order_url() );
		exit;
	}

	public static function handle_pdf(): void {
		$order = self::order_from_request( 'fo_download_pdf' );
		$id    = (int) $order->get_meta( self::META_ID );
		$pdf   = $id ? ( new FO_API_Client() )->pdf( $id ) : new WP_Error( 'fo_none', 'Faktura neexistuje.' );
		if ( is_wp_error( $pdf ) ) {
			wp_die( esc_html( $pdf->get_error_message() ) );
		}
		nocache_headers();
		header( 'Content-Type: application/pdf' );
		header( 'Content-Disposition: attachment; filename="faktura-' . sanitize_file_name( $order->get_meta( self::META_NUMBER ) ) . '.pdf"' );
		echo $pdf; // phpcs:ignore WordPress.Security.EscapeOutput -- binary PDF
		exit;
	}

	private static function order_from_request( string $action ): WC_Order {
		$order_id = (int) ( $_GET['order_id'] ?? 0 );
		check_admin_referer( $action . '_' . $order_id );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( 'Forbidden' );
		}
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			wp_die( 'Order not found' );
		}
		return $order;
	}

	// ---- e-mail attachment ---------------------------------------------------

	/** Attach the invoice PDF to the customer "completed order" e-mail. */
	public static function email_attachments( array $attachments, $email_id, $object ): array {
		if ( $email_id !== 'customer_completed_order' || ! $object instanceof WC_Order ) {
			return $attachments;
		}
		$invoice_id = (int) $object->get_meta( self::META_ID );
		if ( ! $invoice_id ) {
			return $attachments;
		}
		$pdf = ( new FO_API_Client() )->pdf( $invoice_id );
		if ( is_wp_error( $pdf ) ) {
			$object->add_order_note( 'FakturaOnline: PDF se nepodařilo přiložit k e-mailu. ' . $pdf->get_error_message() );
			return $attachments;
		}
		// Outside the web root, in a random directory: invoice numbers are sequential, so a copy under
		// wp-content/uploads would sit at a guessable public URL until cleaned up.
		$dir = get_temp_dir() . 'fakturaonline-' . wp_generate_password( 12, false );
		if ( ! wp_mkdir_p( $dir ) ) {
			$object->add_order_note( 'FakturaOnline: PDF se nepodařilo přiložit k e-mailu (nelze zapsat dočasný soubor).' );
			return $attachments;
		}
		$path = $dir . '/faktura-' . sanitize_file_name( $object->get_meta( self::META_NUMBER ) ) . '.pdf';
		file_put_contents( $path, $pdf );
		self::$tmp_pdfs[ $object->get_id() ] = $path;
		$attachments[]                       = $path;
		return $attachments;
	}

	/** Remove the temp PDF once the e-mail went out (or failed). */
	public static function cleanup_attachment( $return, $email_id, $email ): void {
		if ( $email_id !== 'customer_completed_order' || ! isset( $email->object ) || ! $email->object instanceof WC_Order ) {
			return;
		}
		$path = self::$tmp_pdfs[ $email->object->get_id() ] ?? '';
		unset( self::$tmp_pdfs[ $email->object->get_id() ] );
		if ( $path && file_exists( $path ) ) {
			unlink( $path );
			rmdir( dirname( $path ) );
		}
	}
}
