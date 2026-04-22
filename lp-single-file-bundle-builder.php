<?php
/**
 * Plugin Name: Lilleprinsen - Bundle Builder for Easy Product Bundles
 * Description: En enkel side under Produkter for å opprette Easy Product Bundle med flere deler.
 * Version: 1.1.0
 * Author: OpenAI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'LP_Single_File_Bundle_Builder' ) ) {
	final class LP_Single_File_Bundle_Builder {
		const MENU_SLUG          = 'lp-easy-bundle-builder';
		const SETTINGS_MENU_SLUG = 'lp-easy-bundle-builder-settings';
		const NONCE_ACTION       = 'lp_easy_bundle_builder_create';
		const SETTINGS_NONCE_ACTION = 'lp_easy_bundle_builder_save_defaults';
		const AJAX_NONCE_ACTION  = 'lp_easy_bundle_builder_items';
		const PRODUCT_TYPE       = 'easy_product_bundle';
		const ITEMS_REST_ROUTE   = '/wp-json/asnp-easy-product-bundles/v1/items';
		const DEFAULTS_OPTION    = 'lp_easy_bundle_builder_defaults';

		public function __construct() {
			add_action( 'admin_menu', array( $this, 'register_menu' ), 99 );
			add_action( 'admin_post_lp_create_easy_bundle', array( $this, 'handle_create_bundle' ) );
			add_action( 'admin_post_lp_save_easy_bundle_builder_defaults', array( $this, 'handle_save_defaults' ) );
			add_action( 'wp_ajax_lp_bundle_items_search', array( $this, 'ajax_bundle_items_search' ) );
			add_action( 'wp_ajax_lp_bundle_items_fetch', array( $this, 'ajax_bundle_items_fetch' ) );
			add_action( 'admin_notices', array( $this, 'admin_notices' ) );
		}

		public function register_menu() {
			add_submenu_page(
				'edit.php?post_type=product',
				__( 'Bundle Builder', 'lp-bundle-builder' ),
				__( 'Bundle Builder', 'lp-bundle-builder' ),
				'manage_woocommerce',
				self::MENU_SLUG,
				array( $this, 'render_page' )
			);

			add_submenu_page(
				'edit.php?post_type=product',
				__( 'Bundle Builder Settings', 'lp-bundle-builder' ),
				__( 'Bundle Builder Settings', 'lp-bundle-builder' ),
				'manage_woocommerce',
				self::SETTINGS_MENU_SLUG,
				array( $this, 'render_settings_page' )
			);
		}

		public function admin_notices() {
			if ( ! current_user_can( 'manage_woocommerce' ) ) {
				return;
			}

			if ( ! $this->is_dependency_ready() ) {
				$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
				if ( $screen && in_array( $screen->id, array( 'product_page_' . self::MENU_SLUG, 'product_page_' . self::SETTINGS_MENU_SLUG, 'edit-product', 'product' ), true ) ) {
					echo '<div class="notice notice-error"><p>' . esc_html__( 'Bundle Builder krever at WooCommerce og Easy Product Bundles for WooCommerce er aktive.', 'lp-bundle-builder' ) . '</p></div>';
				}
			}
		}

		private function is_dependency_ready() {
			return class_exists( 'WooCommerce' )
				&& class_exists( '\\AsanaPlugins\\WooCommerce\\ProductBundles\\ProductBundle' )
				&& class_exists( '\\AsanaPlugins\\WooCommerce\\ProductBundles\\Models\\SimpleBundleItemsModel' );
		}

		private function defaults_fallback() {
			return array(
				'product_status'       => 'draft',
				'fixed_price'          => 'false',
				'fixed_price_amount'   => '',
				'bundle_button_label'  => 'Configure bundle',
				'sync_stock_quantity'  => 'false',
				'manage_stock'         => 'no',
				'stock_status'         => 'instock',
				'tax_status'           => 'taxable',
				'bundle_image_mode'    => 'ai_prompt',
			);
		}

		private function normalize_true_false_string( $value ) {
			return ( 'true' === strtolower( (string) $value ) || '1' === (string) $value || true === $value ) ? 'true' : 'false';
		}

		private function sanitize_decimal_string( $value ) {
			$value = is_scalar( $value ) ? trim( (string) $value ) : '';
			if ( '' === $value ) {
				return '';
			}

			$value = str_replace( ',', '.', $value );
			if ( ! is_numeric( $value ) ) {
				return '';
			}

			return wc_format_decimal( $value, wc_get_price_decimals() );
		}

		private function sanitize_bundle_image_mode( $value ) {
			$mode = sanitize_key( (string) $value );
			return in_array( $mode, array( 'ai_prompt', 'local_composite' ), true ) ? $mode : 'ai_prompt';
		}

		private function sanitize_defaults_option( $raw_defaults ) {
			$defaults     = $this->defaults_fallback();
			$raw_defaults = is_array( $raw_defaults ) ? $raw_defaults : array();

			$product_status = isset( $raw_defaults['product_status'] ) ? sanitize_key( $raw_defaults['product_status'] ) : $defaults['product_status'];
			$product_status = in_array( $product_status, array( 'draft', 'publish' ), true ) ? $product_status : $defaults['product_status'];

			$fixed_price = isset( $raw_defaults['fixed_price'] ) ? $this->normalize_true_false_string( $raw_defaults['fixed_price'] ) : $defaults['fixed_price'];
			$sync_stock_quantity = isset( $raw_defaults['sync_stock_quantity'] ) ? $this->normalize_true_false_string( $raw_defaults['sync_stock_quantity'] ) : $defaults['sync_stock_quantity'];

			$bundle_button_label = isset( $raw_defaults['bundle_button_label'] ) ? sanitize_text_field( $raw_defaults['bundle_button_label'] ) : $defaults['bundle_button_label'];
			if ( '' === $bundle_button_label ) {
				$bundle_button_label = $defaults['bundle_button_label'];
			}

			$manage_stock = isset( $raw_defaults['manage_stock'] ) ? sanitize_key( $raw_defaults['manage_stock'] ) : $defaults['manage_stock'];
			$manage_stock = in_array( $manage_stock, array( 'yes', 'no' ), true ) ? $manage_stock : $defaults['manage_stock'];

			$stock_status = isset( $raw_defaults['stock_status'] ) ? sanitize_key( $raw_defaults['stock_status'] ) : $defaults['stock_status'];
			$stock_status = in_array( $stock_status, array( 'instock', 'outofstock', 'onbackorder' ), true ) ? $stock_status : $defaults['stock_status'];

			$tax_status = isset( $raw_defaults['tax_status'] ) ? sanitize_key( $raw_defaults['tax_status'] ) : $defaults['tax_status'];
			$tax_status = in_array( $tax_status, array( 'taxable', 'shipping', 'none' ), true ) ? $tax_status : $defaults['tax_status'];

			$fixed_price_amount = isset( $raw_defaults['fixed_price_amount'] ) ? $this->sanitize_decimal_string( $raw_defaults['fixed_price_amount'] ) : $defaults['fixed_price_amount'];
			$bundle_image_mode = isset( $raw_defaults['bundle_image_mode'] ) ? $this->sanitize_bundle_image_mode( $raw_defaults['bundle_image_mode'] ) : $defaults['bundle_image_mode'];

			return array(
				'product_status'      => $product_status,
				'fixed_price'         => $fixed_price,
				'bundle_button_label' => $bundle_button_label,
				'sync_stock_quantity' => $sync_stock_quantity,
				'manage_stock'        => $manage_stock,
				'stock_status'        => $stock_status,
				'tax_status'          => $tax_status,
				'fixed_price_amount'  => $fixed_price_amount,
				'bundle_image_mode'   => $bundle_image_mode,
			);
		}

		private function get_builder_defaults() {
			$defaults = get_option( self::DEFAULTS_OPTION, array() );
			return $this->sanitize_defaults_option( $defaults );
		}

		public function handle_save_defaults() {
			if ( ! current_user_can( 'manage_woocommerce' ) ) {
				wp_die( esc_html__( 'Du har ikke tilgang til å lagre innstillinger.', 'lp-bundle-builder' ) );
			}

			check_admin_referer( self::SETTINGS_NONCE_ACTION );

			$raw_defaults = array(
				'product_status'      => isset( $_POST['product_status'] ) ? wp_unslash( $_POST['product_status'] ) : '',
				'fixed_price'         => isset( $_POST['fixed_price'] ) ? 'true' : 'false',
				'fixed_price_amount'  => isset( $_POST['fixed_price_amount'] ) ? wp_unslash( $_POST['fixed_price_amount'] ) : '',
				'bundle_button_label' => isset( $_POST['bundle_button_label'] ) ? wp_unslash( $_POST['bundle_button_label'] ) : '',
				'sync_stock_quantity' => isset( $_POST['sync_stock_quantity'] ) ? 'true' : 'false',
				'manage_stock'        => isset( $_POST['manage_stock'] ) ? wp_unslash( $_POST['manage_stock'] ) : '',
				'stock_status'        => isset( $_POST['stock_status'] ) ? wp_unslash( $_POST['stock_status'] ) : '',
				'tax_status'          => isset( $_POST['tax_status'] ) ? wp_unslash( $_POST['tax_status'] ) : '',
				'bundle_image_mode'   => isset( $_POST['bundle_image_mode'] ) ? wp_unslash( $_POST['bundle_image_mode'] ) : '',
			);

			update_option( self::DEFAULTS_OPTION, $this->sanitize_defaults_option( $raw_defaults ) );

			$redirect_url = add_query_arg(
				array(
					'post_type'         => 'product',
					'page'              => self::SETTINGS_MENU_SLUG,
					'lp_settings_saved' => 1,
				),
				admin_url( 'edit.php' )
			);

			wp_safe_redirect( $redirect_url );
			exit;
		}

		public function render_settings_page() {
			if ( ! current_user_can( 'manage_woocommerce' ) ) {
				wp_die( esc_html__( 'Du har ikke tilgang til denne siden.', 'lp-bundle-builder' ) );
			}

			$defaults = $this->get_builder_defaults();
			?>
			<div class="wrap">
				<h1><?php echo esc_html__( 'Bundle Builder Settings', 'lp-bundle-builder' ); ?></h1>
				<p><?php echo esc_html__( 'Disse standardverdiene brukes når du åpner Bundle Builder. Du kan fortsatt overstyre dem per bundle.', 'lp-bundle-builder' ); ?></p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="lp_save_easy_bundle_builder_defaults" />
					<?php wp_nonce_field( self::SETTINGS_NONCE_ACTION ); ?>
					<table class="form-table" role="presentation">
						<tbody>
							<tr>
								<th scope="row"><label for="lp_default_product_status"><?php echo esc_html__( 'Default product status', 'lp-bundle-builder' ); ?></label></th>
								<td>
									<select id="lp_default_product_status" name="product_status">
										<option value="draft" <?php selected( $defaults['product_status'], 'draft' ); ?>><?php echo esc_html__( 'Draft', 'lp-bundle-builder' ); ?></option>
										<option value="publish" <?php selected( $defaults['product_status'], 'publish' ); ?>><?php echo esc_html__( 'Publish', 'lp-bundle-builder' ); ?></option>
									</select>
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="lp_default_fixed_price"><?php echo esc_html__( 'Fixed price default', 'lp-bundle-builder' ); ?></label></th>
								<td><label><input type="checkbox" id="lp_default_fixed_price" name="fixed_price" value="1" <?php checked( $defaults['fixed_price'], 'true' ); ?> /> <?php echo esc_html__( 'Enable fixed price by default', 'lp-bundle-builder' ); ?></label></td>
							</tr>
							<tr id="lp_default_fixed_price_amount_row">
								<th scope="row"><label for="lp_default_fixed_price_amount"><?php echo esc_html__( 'Fixed price amount default', 'lp-bundle-builder' ); ?></label></th>
								<td><input type="number" class="small-text" id="lp_default_fixed_price_amount" name="fixed_price_amount" value="<?php echo esc_attr( $defaults['fixed_price_amount'] ); ?>" min="0" step="0.01" /></td>
							</tr>
							<tr>
								<th scope="row"><label for="lp_default_bundle_button_label"><?php echo esc_html__( 'Bundle button label default', 'lp-bundle-builder' ); ?></label></th>
								<td><input type="text" class="regular-text" id="lp_default_bundle_button_label" name="bundle_button_label" value="<?php echo esc_attr( $defaults['bundle_button_label'] ); ?>" maxlength="120" /></td>
							</tr>
							<tr>
								<th scope="row"><label for="lp_default_sync_stock_quantity"><?php echo esc_html__( 'Sync stock quantity default', 'lp-bundle-builder' ); ?></label></th>
								<td><label><input type="checkbox" id="lp_default_sync_stock_quantity" name="sync_stock_quantity" value="1" <?php checked( $defaults['sync_stock_quantity'], 'true' ); ?> /> <?php echo esc_html__( 'Enable stock quantity sync by default', 'lp-bundle-builder' ); ?></label></td>
							</tr>
							<tr>
								<th scope="row"><label for="lp_default_manage_stock"><?php echo esc_html__( 'WooCommerce manage stock default', 'lp-bundle-builder' ); ?></label></th>
								<td>
									<select id="lp_default_manage_stock" name="manage_stock">
										<option value="no" <?php selected( $defaults['manage_stock'], 'no' ); ?>><?php echo esc_html__( 'No', 'lp-bundle-builder' ); ?></option>
										<option value="yes" <?php selected( $defaults['manage_stock'], 'yes' ); ?>><?php echo esc_html__( 'Yes', 'lp-bundle-builder' ); ?></option>
									</select>
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="lp_default_stock_status"><?php echo esc_html__( 'WooCommerce stock status default', 'lp-bundle-builder' ); ?></label></th>
								<td>
									<select id="lp_default_stock_status" name="stock_status">
										<option value="instock" <?php selected( $defaults['stock_status'], 'instock' ); ?>><?php echo esc_html__( 'In stock', 'lp-bundle-builder' ); ?></option>
										<option value="outofstock" <?php selected( $defaults['stock_status'], 'outofstock' ); ?>><?php echo esc_html__( 'Out of stock', 'lp-bundle-builder' ); ?></option>
										<option value="onbackorder" <?php selected( $defaults['stock_status'], 'onbackorder' ); ?>><?php echo esc_html__( 'On backorder', 'lp-bundle-builder' ); ?></option>
									</select>
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="lp_default_tax_status"><?php echo esc_html__( 'WooCommerce tax status default', 'lp-bundle-builder' ); ?></label></th>
								<td>
									<select id="lp_default_tax_status" name="tax_status">
										<option value="taxable" <?php selected( $defaults['tax_status'], 'taxable' ); ?>><?php echo esc_html__( 'Taxable', 'lp-bundle-builder' ); ?></option>
										<option value="shipping" <?php selected( $defaults['tax_status'], 'shipping' ); ?>><?php echo esc_html__( 'Shipping only', 'lp-bundle-builder' ); ?></option>
										<option value="none" <?php selected( $defaults['tax_status'], 'none' ); ?>><?php echo esc_html__( 'None', 'lp-bundle-builder' ); ?></option>
									</select>
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="lp_default_bundle_image_mode"><?php echo esc_html__( 'Bundle image mode default', 'lp-bundle-builder' ); ?></label></th>
								<td>
									<select id="lp_default_bundle_image_mode" name="bundle_image_mode">
										<option value="ai_prompt" <?php selected( $defaults['bundle_image_mode'], 'ai_prompt' ); ?>><?php echo esc_html__( 'AI prompt', 'lp-bundle-builder' ); ?></option>
										<option value="local_composite" <?php selected( $defaults['bundle_image_mode'], 'local_composite' ); ?>><?php echo esc_html__( 'Local composite', 'lp-bundle-builder' ); ?></option>
									</select>
								</td>
							</tr>
						</tbody>
					</table>
					<?php submit_button( __( 'Save defaults', 'lp-bundle-builder' ) ); ?>
				</form>
				<script>
				(function(){
					const fixedPrice = document.getElementById('lp_default_fixed_price');
					const amountRow = document.getElementById('lp_default_fixed_price_amount_row');
					const amountInput = document.getElementById('lp_default_fixed_price_amount');
					if (!fixedPrice || !amountRow || !amountInput) {
						return;
					}
					function syncFixedAmountVisibility(){
						const enabled = !!fixedPrice.checked;
						amountRow.style.display = enabled ? '' : 'none';
						amountInput.disabled = !enabled;
					}
					fixedPrice.addEventListener('change', syncFixedAmountVisibility);
					syncFixedAmountVisibility();
				})();
				</script>
			</div>
			<?php
		}

		public function render_page() {
			if ( ! current_user_can( 'manage_woocommerce' ) ) {
				wp_die( esc_html__( 'Du har ikke tilgang til denne siden.', 'lp-bundle-builder' ) );
			}

			if ( ! $this->is_dependency_ready() ) {
				echo '<div class="wrap"><h1>' . esc_html__( 'Bundle Builder', 'lp-bundle-builder' ) . '</h1>';
				echo '<p>' . esc_html__( 'Aktiver WooCommerce og Easy Product Bundles for WooCommerce først.', 'lp-bundle-builder' ) . '</p></div>';
				return;
			}

			$created_id = isset( $_GET['lp_bundle_id'] ) ? absint( $_GET['lp_bundle_id'] ) : 0;
			$defaults   = $this->get_builder_defaults();
			$image_prompt = '';
			$image_sources = array();
			if ( $created_id > 0 ) {
				$image_prompt_meta = get_post_meta( $created_id, '_lp_bundle_image_prompt', true );
				$image_prompt = is_string( $image_prompt_meta ) ? trim( $image_prompt_meta ) : '';

				$image_sources_meta = get_post_meta( $created_id, '_lp_bundle_image_sources', true );
				$decoded_sources = json_decode( is_string( $image_sources_meta ) ? $image_sources_meta : '', true );
				$image_sources = is_array( $decoded_sources ) ? $decoded_sources : array();
			}
			?>
			<div class="wrap lp-bundle-builder-wrap">
				<h1><?php echo esc_html__( 'Bundle Builder', 'lp-bundle-builder' ); ?></h1>
				<p><?php echo esc_html__( 'Bygg bundle-deler: hver del blir ett Easy Product Bundles-item.', 'lp-bundle-builder' ); ?></p>

				<?php if ( ! empty( $_GET['lp_bundle_created'] ) && $created_id > 0 ) : ?>
					<div class="notice notice-success inline"><p>
						<?php echo esc_html__( 'Bundle opprettet.', 'lp-bundle-builder' ); ?>
						<a href="<?php echo esc_url( admin_url( 'post.php?post=' . $created_id . '&action=edit' ) ); ?>"><?php echo esc_html__( 'Åpne produktet', 'lp-bundle-builder' ); ?></a>
					</p></div>
					<?php if ( '' !== $image_prompt ) : ?>
						<div class="lp-image-prompt-box">
							<h2><?php echo esc_html__( 'Image Prompt for ChatGPT', 'lp-bundle-builder' ); ?></h2>
							<p><?php echo esc_html__( 'Denne prompten er laget for streng kompositering av de originale produktbildene.', 'lp-bundle-builder' ); ?></p>
							<p><?php echo esc_html__( 'Målet er å kombinere bildene, ikke å generere nye produktversjoner.', 'lp-bundle-builder' ); ?></p>
							<p><?php echo esc_html__( 'ChatGPT skal kun komponere produktene i ett bundle-bilde, ikke endre selve produktene.', 'lp-bundle-builder' ); ?></p>
							<p class="lp-image-prompt-actions">
								<a href="<?php echo esc_url( admin_url( 'post.php?post=' . $created_id . '&action=edit' ) ); ?>" class="button button-secondary"><?php echo esc_html__( 'Open product', 'lp-bundle-builder' ); ?></a>
								<button type="button" class="button button-primary" id="lp-copy-image-prompt"><?php echo esc_html__( 'Copy image prompt', 'lp-bundle-builder' ); ?></button>
								<button type="button" class="button" id="lp-select-image-prompt"><?php echo esc_html__( 'Select all', 'lp-bundle-builder' ); ?></button>
							</p>
							<textarea id="lp-image-prompt-textarea" class="large-text code" rows="18" readonly><?php echo esc_textarea( $image_prompt ); ?></textarea>
							<?php if ( ! empty( $image_sources ) ) : ?>
								<h3><?php echo esc_html__( 'Kildebilder', 'lp-bundle-builder' ); ?></h3>
								<ul class="lp-image-source-list">
									<?php foreach ( $image_sources as $source ) : ?>
										<?php
										$source_name = isset( $source['name'] ) ? (string) $source['name'] : '';
										$source_url  = isset( $source['featured_image_url'] ) ? (string) $source['featured_image_url'] : '';
										if ( '' === $source_url ) {
											continue;
										}
										$source_qty = isset( $source['quantity'] ) ? (int) $source['quantity'] : 1;
										?>
										<li>
											<?php echo esc_html( $source_name ); ?>
											<?php if ( $source_qty > 1 ) : ?>
												(<?php echo esc_html( sprintf( __( 'qty %d', 'lp-bundle-builder' ), $source_qty ) ); ?>)
											<?php endif; ?>
											— <a href="<?php echo esc_url( $source_url ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $source_url ); ?></a>
										</li>
									<?php endforeach; ?>
								</ul>
							<?php endif; ?>
						</div>
					<?php endif; ?>
				<?php endif; ?>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="lp-bundle-builder-form">
					<input type="hidden" name="action" value="lp_create_easy_bundle" />
					<?php wp_nonce_field( self::NONCE_ACTION ); ?>
					<input type="hidden" id="lp_parts_json" name="parts_json" value="" />

					<table class="form-table" role="presentation">
						<tbody>
							<tr>
								<th scope="row"><label for="lp_bundle_title"><?php echo esc_html__( 'Bundle-navn', 'lp-bundle-builder' ); ?></label></th>
								<td>
									<input type="text" class="regular-text" id="lp_bundle_title" name="bundle_title" required placeholder="<?php echo esc_attr__( 'F.eks. Gavesett vår', 'lp-bundle-builder' ); ?>" />
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="lp_bundle_status"><?php echo esc_html__( 'Produktstatus', 'lp-bundle-builder' ); ?></label></th>
								<td>
									<select id="lp_bundle_status" name="bundle_status">
										<option value="draft" <?php selected( $defaults['product_status'], 'draft' ); ?>><?php echo esc_html__( 'Kladd', 'lp-bundle-builder' ); ?></option>
										<option value="publish" <?php selected( $defaults['product_status'], 'publish' ); ?>><?php echo esc_html__( 'Publisert', 'lp-bundle-builder' ); ?></option>
									</select>
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="lp_bundle_image_mode"><?php echo esc_html__( 'Bundle image mode', 'lp-bundle-builder' ); ?></label></th>
								<td>
									<select id="lp_bundle_image_mode" name="bundle_image_mode">
										<option value="ai_prompt" <?php selected( $defaults['bundle_image_mode'], 'ai_prompt' ); ?>><?php echo esc_html__( 'AI prompt', 'lp-bundle-builder' ); ?></option>
										<option value="local_composite" <?php selected( $defaults['bundle_image_mode'], 'local_composite' ); ?>><?php echo esc_html__( 'Local composite', 'lp-bundle-builder' ); ?></option>
									</select>
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="lp_fixed_price"><?php echo esc_html__( 'Fast pris', 'lp-bundle-builder' ); ?></label></th>
								<td>
									<label for="lp_fixed_price">
										<input type="checkbox" id="lp_fixed_price" name="fixed_price" value="1" <?php checked( $defaults['fixed_price'], 'true' ); ?> />
										<?php echo esc_html__( 'Aktiver fast pris for bundlen', 'lp-bundle-builder' ); ?>
									</label>
								</td>
							</tr>
							<tr id="lp_fixed_price_amount_row">
								<th scope="row"><label for="lp_fixed_price_amount"><?php echo esc_html__( 'Fixed price amount', 'lp-bundle-builder' ); ?></label></th>
								<td><input type="number" class="small-text" id="lp_fixed_price_amount" name="fixed_price_amount" value="<?php echo esc_attr( $defaults['fixed_price_amount'] ); ?>" min="0" step="0.01" /></td>
							</tr>
							<tr>
								<th scope="row"><label for="lp_bundle_button_label"><?php echo esc_html__( 'Bundle-knappetekst (shop)', 'lp-bundle-builder' ); ?></label></th>
								<td>
									<input type="text" class="regular-text" id="lp_bundle_button_label" name="bundle_button_label" value="<?php echo esc_attr( $defaults['bundle_button_label'] ); ?>" maxlength="120" />
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="lp_sync_stock_quantity"><?php echo esc_html__( 'Sync stock quantity', 'lp-bundle-builder' ); ?></label></th>
								<td>
									<label for="lp_sync_stock_quantity">
										<input type="checkbox" id="lp_sync_stock_quantity" name="sync_stock_quantity" value="1" <?php checked( $defaults['sync_stock_quantity'], 'true' ); ?> />
										<?php echo esc_html__( 'Synkroniser lagerbeholdning fra bundle-innhold', 'lp-bundle-builder' ); ?>
									</label>
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="lp_manage_stock"><?php echo esc_html__( 'WooCommerce manage stock', 'lp-bundle-builder' ); ?></label></th>
								<td>
									<select id="lp_manage_stock" name="manage_stock">
										<option value="no" <?php selected( $defaults['manage_stock'], 'no' ); ?>><?php echo esc_html__( 'No', 'lp-bundle-builder' ); ?></option>
										<option value="yes" <?php selected( $defaults['manage_stock'], 'yes' ); ?>><?php echo esc_html__( 'Yes', 'lp-bundle-builder' ); ?></option>
									</select>
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="lp_stock_status"><?php echo esc_html__( 'WooCommerce stock status', 'lp-bundle-builder' ); ?></label></th>
								<td>
									<select id="lp_stock_status" name="stock_status">
										<option value="instock" <?php selected( $defaults['stock_status'], 'instock' ); ?>><?php echo esc_html__( 'In stock', 'lp-bundle-builder' ); ?></option>
										<option value="outofstock" <?php selected( $defaults['stock_status'], 'outofstock' ); ?>><?php echo esc_html__( 'Out of stock', 'lp-bundle-builder' ); ?></option>
										<option value="onbackorder" <?php selected( $defaults['stock_status'], 'onbackorder' ); ?>><?php echo esc_html__( 'On backorder', 'lp-bundle-builder' ); ?></option>
									</select>
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="lp_tax_status"><?php echo esc_html__( 'WooCommerce tax status', 'lp-bundle-builder' ); ?></label></th>
								<td>
									<select id="lp_tax_status" name="tax_status">
										<option value="taxable" <?php selected( $defaults['tax_status'], 'taxable' ); ?>><?php echo esc_html__( 'Taxable', 'lp-bundle-builder' ); ?></option>
										<option value="shipping" <?php selected( $defaults['tax_status'], 'shipping' ); ?>><?php echo esc_html__( 'Shipping only', 'lp-bundle-builder' ); ?></option>
										<option value="none" <?php selected( $defaults['tax_status'], 'none' ); ?>><?php echo esc_html__( 'None', 'lp-bundle-builder' ); ?></option>
									</select>
								</td>
							</tr>
						</tbody>
					</table>

					<h2><?php echo esc_html__( 'Bundle-deler', 'lp-bundle-builder' ); ?></h2>
					<p><?php echo esc_html__( 'Legg til, fjern og rediger deler. Hver del må ha et standardprodukt.', 'lp-bundle-builder' ); ?></p>
					<div id="lp_parts_overview" class="lp-overview"></div>
					<div id="lp_parts_container"></div>
					<p><button type="button" class="button button-secondary" id="lp_add_part"><?php echo esc_html__( 'Legg til del', 'lp-bundle-builder' ); ?></button></p>

					<?php submit_button( __( 'Opprett bundle', 'lp-bundle-builder' ) ); ?>
				</form>
			</div>

			<style>
				.lp-overview { margin-bottom: 12px; }
				.lp-overview ul { list-style: disc; margin-left: 22px; }
				.lp-part {
					border: 1px solid #ccd0d4;
					background: #fff;
					padding: 14px;
					margin-bottom: 12px;
					border-radius: 4px;
				}
				.lp-part-grid {
					display: grid;
					grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
					gap: 12px;
				}
				.lp-field label { display: block; font-weight: 600; margin-bottom: 5px; }
				.lp-search-wrap { position: relative; }
				.lp-search-wrap input[type="search"], .lp-search-wrap input[type="number"] { width: 100%; }
				.lp-results {
					position: absolute;
					left: 0;
					right: 0;
					background: #fff;
					border: 1px solid #ccd0d4;
					z-index: 30;
					max-height: 170px;
					overflow: auto;
					display: none;
				}
				.lp-results button {
					display: block;
					width: 100%;
					text-align: left;
					border: 0;
					background: #fff;
					padding: 7px 9px;
					cursor: pointer;
					border-bottom: 1px solid #f0f0f1;
				}
				.lp-pill-list { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 6px; }
				.lp-pill {
					display: inline-flex;
					align-items: center;
					padding: 4px 8px;
					background: #f0f6fc;
					border: 1px solid #c8d7e1;
					border-radius: 999px;
					font-size: 12px;
				}
				.lp-pill button {
					margin-left: 6px;
					border: 0;
					background: transparent;
					cursor: pointer;
					color: #b32d2e;
					font-weight: bold;
				}
				.lp-part-head { display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px; }
				.lp-part-error { color: #b32d2e; font-size: 12px; margin-top: 4px; display: none; }
				.lp-image-prompt-box {
					background: #fff;
					border: 1px solid #ccd0d4;
					border-left: 4px solid #2271b1;
					padding: 16px;
					margin: 16px 0;
				}
				.lp-image-prompt-actions { margin-bottom: 8px; display: flex; flex-wrap: wrap; gap: 8px; }
				.lp-image-source-list { list-style: disc; margin-left: 20px; }
			</style>

			<script>
			(function(){
				const ajaxUrl = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
				const ajaxNonce = <?php echo wp_json_encode( wp_create_nonce( self::AJAX_NONCE_ACTION ) ); ?>;
				const form = document.getElementById('lp-bundle-builder-form');
				const fixedPriceToggle = document.getElementById('lp_fixed_price');
				const fixedPriceAmountRow = document.getElementById('lp_fixed_price_amount_row');
				const fixedPriceAmountInput = document.getElementById('lp_fixed_price_amount');
				const partsContainer = document.getElementById('lp_parts_container');
				const addPartButton = document.getElementById('lp_add_part');
				const partsOverview = document.getElementById('lp_parts_overview');
				const partsInput = document.getElementById('lp_parts_json');
				const imagePromptTextarea = document.getElementById('lp-image-prompt-textarea');
				const copyImagePromptButton = document.getElementById('lp-copy-image-prompt');
				const selectImagePromptButton = document.getElementById('lp-select-image-prompt');
				let parts = [];

				async function copyTextareaContent(textarea){
					if (!textarea) {
						return false;
					}
					const value = textarea.value || '';
					if (!value) {
						return false;
					}
					if (navigator.clipboard && window.isSecureContext) {
						try {
							await navigator.clipboard.writeText(value);
							return true;
						} catch (e) {}
					}
					textarea.focus();
					textarea.select();
					try {
						return document.execCommand('copy');
					} catch (e) {
						return false;
					}
				}

				function normalizeItem(item){
					return {
						id: Number(item.value || item.id || 0),
						label: String(item.label || item.name || ''),
						slug: item.slug ? String(item.slug) : '',
						name: item.name ? String(item.name) : ''
					};
				}

				function emptyPart(){
					return { defaultProduct: null, products: [], categories: [], tags: [], discount: '' };
				}

				function createField(partIndex, fieldKey, apiType, fieldLabel, isMultiple){
					const wrap = document.createElement('div');
					wrap.className = 'lp-field';
					const label = document.createElement('label');
					label.textContent = fieldLabel;
					wrap.appendChild(label);

					const searchWrap = document.createElement('div');
					searchWrap.className = 'lp-search-wrap';
					const input = document.createElement('input');
					input.type = 'search';
					input.placeholder = '<?php echo esc_js( __( 'Søk...', 'lp-bundle-builder' ) ); ?>';
					const results = document.createElement('div');
					results.className = 'lp-results';
					searchWrap.appendChild(input);
					searchWrap.appendChild(results);
					wrap.appendChild(searchWrap);

					const selected = document.createElement('div');
					selected.className = 'lp-pill-list';
					wrap.appendChild(selected);

					let timer = null;
					input.addEventListener('input', function(){
						const term = input.value.trim();
						clearTimeout(timer);
						if (term.length < 2) {
							results.style.display = 'none';
							results.innerHTML = '';
							return;
						}
						timer = setTimeout(function(){
							const params = new URLSearchParams();
							params.append('action', 'lp_bundle_items_search');
							params.append('nonce', ajaxNonce);
							params.append('q', term);
							params.append('type', apiType);
							fetch(ajaxUrl, {
								method: 'POST',
								headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'},
								body: params.toString()
							})
							.then(r => r.json())
							.then(response => {
								if (!response || !response.success || !Array.isArray(response.data)) {
									results.innerHTML = '';
									results.style.display = 'none';
									return;
								}
								results.innerHTML = '';
								response.data.forEach(raw => {
									const item = normalizeItem(raw);
									if (!item.id || !item.label) {
										return;
									}
									const btn = document.createElement('button');
									btn.type = 'button';
									btn.textContent = item.label;
									btn.addEventListener('click', function(){
										if (isMultiple) {
											const list = parts[partIndex][fieldKey] || [];
											if (!list.some(e => Number(e.id) === Number(item.id))) {
												list.push(item);
												parts[partIndex][fieldKey] = list;
											}
										} else {
											parts[partIndex][fieldKey] = item;
										}
										input.value = '';
										results.style.display = 'none';
										render();
									});
									results.appendChild(btn);
								});
								results.style.display = results.children.length ? 'block' : 'none';
							})
							.catch(() => {
								results.innerHTML = '';
								results.style.display = 'none';
							});
						}, 250);
					});

					const values = isMultiple ? (parts[partIndex][fieldKey] || []) : (parts[partIndex][fieldKey] ? [parts[partIndex][fieldKey]] : []);
					values.forEach(function(item){
						const pill = document.createElement('span');
						pill.className = 'lp-pill';
						pill.textContent = item.label || ('#' + item.id);
						const remove = document.createElement('button');
						remove.type = 'button';
						remove.textContent = '×';
						remove.addEventListener('click', function(){
							if (isMultiple) {
								parts[partIndex][fieldKey] = (parts[partIndex][fieldKey] || []).filter(e => Number(e.id) !== Number(item.id));
							} else {
								parts[partIndex][fieldKey] = null;
							}
							render();
						});
						pill.appendChild(remove);
						selected.appendChild(pill);
					});

					return wrap;
				}

				function renderOverview(){
					if (!parts.length) {
						partsOverview.innerHTML = '<p><?php echo esc_js( __( 'Ingen deler lagt til ennå.', 'lp-bundle-builder' ) ); ?></p>';
						return;
					}
					const ul = document.createElement('ul');
					parts.forEach(function(part, idx){
						const li = document.createElement('li');
						const def = part.defaultProduct ? part.defaultProduct.label : '<?php echo esc_js( __( 'Mangler standardprodukt', 'lp-bundle-builder' ) ); ?>';
						li.textContent = (idx + 1) + '. ' + def + ' | Products: ' + (part.products || []).length + ' | Categories: ' + (part.categories || []).length + ' | Tags: ' + (part.tags || []).length + ' | Discount: ' + (part.discount || '0');
						ul.appendChild(li);
					});
					partsOverview.innerHTML = '';
					partsOverview.appendChild(ul);
				}

				function serializeParts(){
					return parts.map(function(part){
						return {
							default_product: part.defaultProduct && part.defaultProduct.id ? Number(part.defaultProduct.id) : 0,
							products: (part.products || []).map(i => Number(i.id)).filter(Boolean),
							categories: (part.categories || []).map(i => Number(i.id)).filter(Boolean),
							tags: (part.tags || []).map(i => Number(i.id)).filter(Boolean),
							discount: String(part.discount || '').trim()
						};
					});
				}

				function render(){
					partsContainer.innerHTML = '';
					renderOverview();
					parts.forEach(function(part, index){
						const card = document.createElement('div');
						card.className = 'lp-part';

						const head = document.createElement('div');
						head.className = 'lp-part-head';
						const h = document.createElement('strong');
						h.textContent = '<?php echo esc_js( __( 'Del', 'lp-bundle-builder' ) ); ?> ' + (index + 1);
						const rm = document.createElement('button');
						rm.type = 'button';
						rm.className = 'button-link-delete';
						rm.textContent = '<?php echo esc_js( __( 'Fjern del', 'lp-bundle-builder' ) ); ?>';
						rm.addEventListener('click', function(){
							parts.splice(index, 1);
							render();
						});
						head.appendChild(h);
						head.appendChild(rm);
						card.appendChild(head);

						const grid = document.createElement('div');
						grid.className = 'lp-part-grid';
						grid.appendChild(createField(index, 'defaultProduct', 'default_product', '<?php echo esc_js( __( 'Default product', 'lp-bundle-builder' ) ); ?>', false));
						grid.appendChild(createField(index, 'products', 'products', '<?php echo esc_js( __( 'Products', 'lp-bundle-builder' ) ); ?>', true));
						grid.appendChild(createField(index, 'categories', 'categories', '<?php echo esc_js( __( 'Categories', 'lp-bundle-builder' ) ); ?>', true));
						grid.appendChild(createField(index, 'tags', 'tags', '<?php echo esc_js( __( 'Tags', 'lp-bundle-builder' ) ); ?>', true));

						const discountField = document.createElement('div');
						discountField.className = 'lp-field';
						const discountLabel = document.createElement('label');
						discountLabel.textContent = '<?php echo esc_js( __( 'Discount', 'lp-bundle-builder' ) ); ?>';
						const discountInput = document.createElement('input');
						discountInput.type = 'number';
						discountInput.step = '0.01';
						discountInput.min = '0';
						discountInput.value = part.discount || '';
						discountInput.placeholder = '0';
						discountInput.addEventListener('input', function(){
							parts[index].discount = discountInput.value;
							renderOverview();
						});
						discountField.appendChild(discountLabel);
						discountField.appendChild(discountInput);
						grid.appendChild(discountField);

						card.appendChild(grid);
						const err = document.createElement('div');
						err.className = 'lp-part-error';
						err.textContent = '<?php echo esc_js( __( 'Denne delen må ha et standardprodukt.', 'lp-bundle-builder' ) ); ?>';
						if (!part.defaultProduct || !part.defaultProduct.id) {
							err.style.display = 'block';
						}
						card.appendChild(err);
						partsContainer.appendChild(card);
					});
					partsInput.value = JSON.stringify(serializeParts());
				}

				function syncFixedPriceAmount(){
					if (!fixedPriceToggle || !fixedPriceAmountRow || !fixedPriceAmountInput) {
						return;
					}
					const enabled = !!fixedPriceToggle.checked;
					fixedPriceAmountRow.style.display = enabled ? '' : 'none';
					fixedPriceAmountInput.disabled = !enabled;
				}

				if (fixedPriceToggle) {
					fixedPriceToggle.addEventListener('change', syncFixedPriceAmount);
				}
				syncFixedPriceAmount();

				if (selectImagePromptButton && imagePromptTextarea) {
					selectImagePromptButton.addEventListener('click', function(){
						imagePromptTextarea.focus();
						imagePromptTextarea.select();
					});
				}

				if (copyImagePromptButton && imagePromptTextarea) {
					copyImagePromptButton.addEventListener('click', async function(){
						const ok = await copyTextareaContent(imagePromptTextarea);
						const originalLabel = copyImagePromptButton.textContent;
						copyImagePromptButton.textContent = ok ? 'Copied!' : 'Kunne ikke kopiere';
						window.setTimeout(function(){
							copyImagePromptButton.textContent = originalLabel;
						}, 1800);
					});
				}

				addPartButton.addEventListener('click', function(){
					parts.push(emptyPart());
					render();
				});

				form.addEventListener('submit', function(e){
					if (!parts.length) {
						e.preventDefault();
						window.alert('<?php echo esc_js( __( 'Legg til minst én del før du oppretter bundlen.', 'lp-bundle-builder' ) ); ?>');
						return;
					}
					const invalid = parts.some(part => !part.defaultProduct || !part.defaultProduct.id);
					if (invalid) {
						e.preventDefault();
						window.alert('<?php echo esc_js( __( 'Hver del må ha et standardprodukt.', 'lp-bundle-builder' ) ); ?>');
						return;
					}
					partsInput.value = JSON.stringify(serializeParts());
				});

				parts.push(emptyPart());
				render();
			})();
			</script>
			<?php
		}

		public function ajax_bundle_items_search() {
			if ( ! current_user_can( 'manage_woocommerce' ) ) {
				wp_send_json_error( array(), 403 );
			}

			check_ajax_referer( self::AJAX_NONCE_ACTION, 'nonce' );

			$type = isset( $_POST['type'] ) ? sanitize_key( wp_unslash( $_POST['type'] ) ) : '';
			$search = isset( $_POST['q'] ) ? sanitize_text_field( wp_unslash( $_POST['q'] ) ) : '';
			$type = $this->normalize_item_type( $type );

			if ( ! $type || strlen( $search ) < 2 ) {
				wp_send_json_success( array() );
			}

			wp_send_json_success( $this->get_items_with_fallback( $type, $search, array(), 'search' ) );
		}

		public function ajax_bundle_items_fetch() {
			if ( ! current_user_can( 'manage_woocommerce' ) ) {
				wp_send_json_error( array(), 403 );
			}

			check_ajax_referer( self::AJAX_NONCE_ACTION, 'nonce' );

			$type = isset( $_POST['type'] ) ? sanitize_key( wp_unslash( $_POST['type'] ) ) : '';
			$type = $this->normalize_item_type( $type );
			$items = isset( $_POST['items'] ) ? (array) wp_unslash( $_POST['items'] ) : array();
			$items = $this->sanitize_unique_positive_int_array( $items );

			if ( ! $type || empty( $items ) ) {
				wp_send_json_success( array() );
			}

			wp_send_json_success( $this->get_items_with_fallback( $type, '', $items, 'fetch' ) );
		}

		private function get_items_with_fallback( $type, $search, $items, $mode ) {
			$rest_items = $this->rest_items_request( $type, $search, $items, $mode );
			if ( ! empty( $rest_items ) ) {
				return $rest_items;
			}

			if ( in_array( $type, array( 'products', 'default_product' ), true ) ) {
				return $this->fallback_product_items( $search, $items, $mode );
			}

			if ( in_array( $type, array( 'categories', 'tags' ), true ) ) {
				$taxonomy = ( 'categories' === $type ) ? 'product_cat' : 'product_tag';
				return $this->fallback_term_items( $taxonomy, $search, $items, $mode );
			}

			return array();
		}

		private function rest_items_request( $type, $search, $items, $mode ) {
			$route = $this->get_internal_items_rest_route();
			if ( '' === $route ) {
				return array();
			}

			if ( 'fetch' === $mode ) {
				$request = new \WP_REST_Request( 'POST', $route );
				$request->set_body_params(
					array(
						'type'  => $type,
						'items' => $items,
					)
				);
			} else {
				$request = new \WP_REST_Request( 'GET', $route );
				$request->set_query_params(
					array(
						'type'   => $type,
						'search' => $search,
					)
				);
			}

			$response = rest_do_request( $request );
			if ( ! $response instanceof \WP_REST_Response ) {
				return array();
			}

			$status = (int) $response->get_status();
			if ( $status < 200 || $status >= 300 ) {
				return array();
			}

			$data = $response->get_data();
			if ( ! is_array( $data ) ) {
				return array();
			}

			$items_data = $this->normalize_rest_items_response( $data );
			return is_array( $items_data ) ? array_values( $items_data ) : array();
		}

		private function get_internal_items_rest_route() {
			$route = (string) self::ITEMS_REST_ROUTE;
			$route = preg_replace( '#^/wp-json#', '', $route );
			$route = '/' . ltrim( (string) $route, '/' );
			return ( '/' === $route ) ? '' : $route;
		}

		private function normalize_rest_items_response( $response ) {
			if ( isset( $response['items'] ) && is_array( $response['items'] ) ) {
				return $response['items'];
			}
			if ( isset( $response['data'] ) && is_array( $response['data'] ) ) {
				return $response['data'];
			}
			return is_array( $response ) ? array_values( $response ) : array();
		}

		private function fallback_product_items( $search, $items, $mode ) {
			$product_ids = array();

			if ( 'fetch' === $mode ) {
				$product_ids = $this->sanitize_unique_positive_int_array( $items );
			} else {
				$search_term = trim( (string) $search );
				if ( strlen( $search_term ) < 2 ) {
					return array();
				}

				$text_query = new \WP_Query(
					array(
						'post_type'      => array( 'product', 'product_variation' ),
						'post_status'    => array( 'publish', 'private' ),
						's'              => $search_term,
						'posts_per_page' => 30,
						'fields'         => 'ids',
						'orderby'        => 'date',
						'order'          => 'DESC',
						'no_found_rows'  => true,
					)
				);

				if ( ! empty( $text_query->posts ) && is_array( $text_query->posts ) ) {
					$product_ids = array_merge( $product_ids, array_map( 'absint', $text_query->posts ) );
				}

				$sku_query = new \WP_Query(
					array(
						'post_type'      => array( 'product', 'product_variation' ),
						'post_status'    => array( 'publish', 'private' ),
						'posts_per_page' => 30,
						'fields'         => 'ids',
						'orderby'        => 'date',
						'order'          => 'DESC',
						'no_found_rows'  => true,
						'meta_query'     => array(
							array(
								'key'     => '_sku',
								'value'   => $search_term,
								'compare' => 'LIKE',
							),
						),
					)
				);

				if ( ! empty( $sku_query->posts ) && is_array( $sku_query->posts ) ) {
					$product_ids = array_merge( $product_ids, array_map( 'absint', $sku_query->posts ) );
				}

				$product_ids = $this->sanitize_unique_positive_int_array( $product_ids );
			}

			if ( empty( $product_ids ) ) {
				return array();
			}

			$results = array();
			foreach ( $product_ids as $product_id ) {
				$product = wc_get_product( $product_id );
				if ( ! $this->is_searchable_bundle_product( $product ) ) {
					continue;
				}

				$results[] = array(
					'value'      => (int) $product->get_id(),
					'label'      => $this->build_fallback_product_label( $product ),
					'isDisabled' => false,
				);
			}

			return $results;
		}

		private function build_fallback_product_label( $product ) {
			$name = '';
			if ( $product && is_a( $product, 'WC_Product' ) ) {
				$name = trim( (string) $product->get_name() );
			}

			if ( $product && $product->is_type( 'variation' ) ) {
				$variation_name = trim( (string) wc_get_formatted_variation( $product, true, false, true ) );
				if ( '' !== $variation_name ) {
					$name .= ( '' !== $name ? ' - ' : '' ) . $variation_name;
				}
			}

			$sku = $product ? trim( (string) $product->get_sku() ) : '';
			if ( '' !== $sku ) {
				return sprintf( '%1$s (%2$s)', $sku, $name );
			}

			return $name;
		}

		private function fallback_term_items( $taxonomy, $search, $items, $mode ) {
			$args = array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
				'number'     => 30,
			);
			$requested_ids = array();

			if ( 'fetch' === $mode ) {
				$requested_ids = $this->sanitize_unique_positive_int_array( $items );
				$args['include'] = $requested_ids;
				if ( empty( $args['include'] ) ) {
					return array();
				}
			} else {
				$search_term = trim( (string) $search );
				if ( strlen( $search_term ) < 2 ) {
					return array();
				}
				$args['name__like'] = $search_term;
			}

			$terms = get_terms( $args );
			if ( is_wp_error( $terms ) || ! is_array( $terms ) || empty( $terms ) ) {
				return array();
			}

			if ( 'fetch' === $mode && ! empty( $requested_ids ) ) {
				$terms_map = array();
				foreach ( $terms as $term ) {
					if ( ! $term instanceof \WP_Term ) {
						continue;
					}
					$terms_map[ (int) $term->term_id ] = $term;
				}

				$ordered_terms = array();
				foreach ( $requested_ids as $term_id ) {
					if ( isset( $terms_map[ $term_id ] ) ) {
						$ordered_terms[] = $terms_map[ $term_id ];
					}
				}
				$terms = $ordered_terms;
			}

			$results = array();
			foreach ( $terms as $term ) {
				if ( ! $term instanceof \WP_Term ) {
					continue;
				}
				$results[] = array(
					'value' => (int) $term->term_id,
					'label' => (string) $term->name,
					'slug'  => (string) $term->slug,
					'name'  => (string) $term->name,
				);
			}

			return $results;
		}

		private function is_searchable_bundle_product( $product ) {
			if ( ! $product || ! is_a( $product, 'WC_Product' ) ) {
				return false;
			}

			if ( $product->is_type( 'simple' ) || $product->is_type( 'variation' ) ) {
				return true;
			}

			return false;
		}

		private function normalize_item_type( $type ) {
			$map = array(
				'products'        => 'products',
				'defaultproduct'  => 'default_product',
				'default_product' => 'default_product',
				'categories'      => 'categories',
				'tags'            => 'tags',
			);
			$key = strtolower( str_replace( '-', '_', $type ) );
			return isset( $map[ $key ] ) ? $map[ $key ] : '';
		}

		public function handle_create_bundle() {
			if ( ! current_user_can( 'manage_woocommerce' ) ) {
				wp_die( esc_html__( 'Du har ikke tilgang til å opprette bundle.', 'lp-bundle-builder' ) );
			}

			check_admin_referer( self::NONCE_ACTION );

			if ( ! $this->is_dependency_ready() ) {
				$this->redirect_with_error( __( 'WooCommerce eller Easy Product Bundles er ikke aktiv.', 'lp-bundle-builder' ) );
			}

			$defaults = $this->get_builder_defaults();

			$title      = isset( $_POST['bundle_title'] ) ? sanitize_text_field( wp_unslash( $_POST['bundle_title'] ) ) : '';
			$status_raw = isset( $_POST['bundle_status'] ) ? sanitize_key( wp_unslash( $_POST['bundle_status'] ) ) : $defaults['product_status'];
			$status     = in_array( $status_raw, array( 'draft', 'publish' ), true ) ? $status_raw : $defaults['product_status'];

			$fixed_price = ! empty( $_POST['fixed_price'] ) ? 'true' : 'false';
			$fixed_price_amount_raw = isset( $_POST['fixed_price_amount'] ) ? trim( (string) wp_unslash( $_POST['fixed_price_amount'] ) ) : '';
			$fixed_price_amount = $this->sanitize_decimal_string( $fixed_price_amount_raw );
			$sync_stock_quantity = ! empty( $_POST['sync_stock_quantity'] ) ? 'true' : 'false';

			$bundle_button_label = isset( $_POST['bundle_button_label'] ) ? sanitize_text_field( wp_unslash( $_POST['bundle_button_label'] ) ) : $defaults['bundle_button_label'];
			if ( '' === $bundle_button_label ) {
				$bundle_button_label = $defaults['bundle_button_label'];
			}

			$manage_stock_raw = isset( $_POST['manage_stock'] ) ? sanitize_key( wp_unslash( $_POST['manage_stock'] ) ) : $defaults['manage_stock'];
			$manage_stock     = in_array( $manage_stock_raw, array( 'yes', 'no' ), true ) ? $manage_stock_raw : $defaults['manage_stock'];

			$stock_status_raw = isset( $_POST['stock_status'] ) ? sanitize_key( wp_unslash( $_POST['stock_status'] ) ) : $defaults['stock_status'];
			$stock_status     = in_array( $stock_status_raw, array( 'instock', 'outofstock', 'onbackorder' ), true ) ? $stock_status_raw : $defaults['stock_status'];

			$tax_status_raw = isset( $_POST['tax_status'] ) ? sanitize_key( wp_unslash( $_POST['tax_status'] ) ) : $defaults['tax_status'];
			$tax_status     = in_array( $tax_status_raw, array( 'taxable', 'shipping', 'none' ), true ) ? $tax_status_raw : $defaults['tax_status'];
			$bundle_image_mode_raw = isset( $_POST['bundle_image_mode'] ) ? wp_unslash( $_POST['bundle_image_mode'] ) : $defaults['bundle_image_mode'];
			$bundle_image_mode = $this->sanitize_bundle_image_mode( $bundle_image_mode_raw );

			if ( '' === $title ) {
				$title = sprintf( __( 'Nytt bundle %s', 'lp-bundle-builder' ), current_time( 'Y-m-d H:i' ) );
			}

			$parts_json = isset( $_POST['parts_json'] ) ? wp_unslash( $_POST['parts_json'] ) : '';
			$parts_raw  = json_decode( $parts_json, true );
			if ( ! is_array( $parts_raw ) || empty( $parts_raw ) ) {
				$this->redirect_with_error( __( 'Du må legge til minst én del.', 'lp-bundle-builder' ) );
			}

			$items = array();
			foreach ( $parts_raw as $part_raw ) {
				$sanitized_item = $this->build_bundle_item_from_part( is_array( $part_raw ) ? $part_raw : array() );
				if ( empty( $sanitized_item['product'] ) ) {
					$this->redirect_with_error( __( 'Hver del må ha et gyldig standardprodukt.', 'lp-bundle-builder' ) );
				}
				$items[] = $sanitized_item;
			}

			if ( empty( $items ) ) {
				$this->redirect_with_error( __( 'Ingen gyldige deler ble sendt inn.', 'lp-bundle-builder' ) );
			}

			$bundle_post_id = wp_insert_post(
				array(
					'post_type'   => 'product',
					'post_status' => $status,
					'post_title'  => $title,
				),
				true
			);

			if ( is_wp_error( $bundle_post_id ) || ! $bundle_post_id ) {
				$this->redirect_with_error( __( 'Kunne ikke opprette bundle-produktet.', 'lp-bundle-builder' ) );
			}

			wp_set_object_terms( $bundle_post_id, self::PRODUCT_TYPE, 'product_type' );

			$bundle = wc_get_product( $bundle_post_id );
			if ( ! $bundle instanceof \AsanaPlugins\WooCommerce\ProductBundles\ProductBundle || ! method_exists( $bundle, 'set_props' ) ) {
				wp_delete_post( $bundle_post_id, true );
				$this->redirect_with_error( __( 'Bundle-klassen kunne ikke lastes riktig.', 'lp-bundle-builder' ) );
			}

			$default_data = $this->build_default_products_data( $items );
			if ( empty( $default_data['is_valid'] ) || '' === $default_data['default_products_json'] ) {
				wp_delete_post( $bundle_post_id, true );
				$this->redirect_with_error(
					! empty( $default_data['error'] )
						? $default_data['error']
						: __( 'Bundle-konfigurasjonen har ugyldige standardprodukter.', 'lp-bundle-builder' )
				);
			}

			$default_products_total = isset( $default_data['default_products_total'] ) ? (float) $default_data['default_products_total'] : 0.0;
			$default_products_total = (float) wc_format_decimal( $default_products_total, wc_get_price_decimals() );

			if ( 'true' === $fixed_price ) {
				if ( '' === $fixed_price_amount_raw ) {
					wp_delete_post( $bundle_post_id, true );
					$this->redirect_with_error( __( 'Fast pris beløp mangler.', 'lp-bundle-builder' ) );
				}

				if ( '' === $fixed_price_amount ) {
					wp_delete_post( $bundle_post_id, true );
					$this->redirect_with_error( __( 'Fast pris beløp er ugyldig.', 'lp-bundle-builder' ) );
				}

				$fixed_price_amount_value = (float) $fixed_price_amount;
				if ( $fixed_price_amount_value <= 0 ) {
					wp_delete_post( $bundle_post_id, true );
					$this->redirect_with_error( __( 'Fast pris beløp er ugyldig.', 'lp-bundle-builder' ) );
				}

				if ( $fixed_price_amount_value > $default_products_total ) {
					wp_delete_post( $bundle_post_id, true );
					$this->redirect_with_error( __( 'Fast pris beløp kan ikke være høyere enn ordinærpris.', 'lp-bundle-builder' ) );
				}
			} else {
				$fixed_price_amount = '';
			}

			$props = array(
				'individual_theme'         => 'false',
				'theme'                    => 'grid_1',
				'theme_size'               => 'medium',
				'fixed_price'              => $fixed_price,
				'include_parent_price'     => 'false',
				'shipping_fee_calculation' => 'bundle',
				'min_items_quantity'       => '',
				'max_items_quantity'       => '',
				'custom_display_price'     => '',
				'bundle_title'             => '',
				'bundle_description'       => '',
				'hide_items_price'         => 'no',
				'items'                    => $items,
				'default_products'         => $default_data['default_products_json'],
				'loop_add_to_cart'         => $default_data['loop_add_to_cart'],
				'sync_stock_quantity'      => $sync_stock_quantity,
				'bundle_button_label'      => $bundle_button_label,
			);

			$errors = $bundle->set_props( $props );
			if ( is_wp_error( $errors ) ) {
				wp_delete_post( $bundle_post_id, true );
				$this->redirect_with_error( $errors->get_error_message() );
			}

			$bundle->set_manage_stock( 'yes' === $manage_stock );
			$bundle->set_stock_status( $stock_status );
			$bundle->set_tax_status( $tax_status );
			$bundle->set_regular_price( wc_format_decimal( $default_products_total, wc_get_price_decimals() ) );
			if ( 'true' === $fixed_price ) {
				$bundle->set_sale_price( wc_format_decimal( $fixed_price_amount, wc_get_price_decimals() ) );
				$bundle->set_price( wc_format_decimal( $fixed_price_amount, wc_get_price_decimals() ) );
			} else {
				$bundle->set_sale_price( '' );
				$bundle->set_price( wc_format_decimal( $default_products_total, wc_get_price_decimals() ) );
			}
			$this->set_bundle_sku_safely( $bundle, (string) $default_data['generated_sku'], $bundle_post_id );
			$combined_description = $this->build_bundle_combined_description( $default_data['rows'] );
			if ( '' !== $combined_description ) {
				$bundle->set_description( $combined_description );
			}

			$intro_sentence = $this->build_bundle_intro_sentence( $default_data['rows'] );
			if ( '' !== $intro_sentence ) {
				$bundle->set_short_description( wp_kses_post( '<p>' . esc_html( $intro_sentence ) . '</p>' ) );
			}

			$bundle_media_data = $this->get_bundle_media_data( $default_data['rows'] );
			$fallback_featured_image_id = isset( $bundle_media_data['fallback_featured_image_id'] ) ? absint( $bundle_media_data['fallback_featured_image_id'] ) : 0;
			$all_source_image_ids = isset( $bundle_media_data['all_source_image_ids'] ) ? $this->sanitize_unique_positive_int_array( $bundle_media_data['all_source_image_ids'] ) : array();
			$featured_image_id = $fallback_featured_image_id;

			if ( 'local_composite' === $bundle_image_mode ) {
				$composite_image_id = $this->generate_bundle_composite_image( $bundle_post_id, $default_data['rows'], $title );
				if ( $composite_image_id > 0 ) {
					$featured_image_id = $composite_image_id;
				}
			}

			if ( $featured_image_id > 0 && (int) $bundle->get_image_id() <= 0 ) {
				$bundle->set_image_id( $featured_image_id );
			}
			$bundle->set_gallery_image_ids( $all_source_image_ids );

			$model = \AsanaPlugins\WooCommerce\ProductBundles\get_plugin()->container()->get(
				\AsanaPlugins\WooCommerce\ProductBundles\Models\SimpleBundleItemsModel::class
			);
			$model->delete_bundle( $bundle_post_id );

			foreach ( $default_data['rows'] as $default_product ) {
				$model->add(
					array(
						'bundle_id'  => $bundle_post_id,
						'product_id' => (int) $default_product['id'],
						'quantity'   => (int) $default_product['qty'],
					)
				);
			}

			do_action( 'asnp_wepb_admin_process_product_object', $bundle );

			$bundle = \AsanaPlugins\WooCommerce\ProductBundles\ProductBundle::sync( $bundle, false );
			$bundle->save();
			$image_sources = $this->get_bundle_image_source_data( $default_data['rows'] );
			$image_prompt  = '';
			if ( 'ai_prompt' === $bundle_image_mode ) {
				$image_prompt = $this->build_bundle_image_prompt( $bundle, $image_sources );
			}
			update_post_meta( $bundle_post_id, '_lp_bundle_image_mode', $bundle_image_mode );
			update_post_meta( $bundle_post_id, '_lp_bundle_image_prompt', $image_prompt );
			update_post_meta( $bundle_post_id, '_lp_bundle_image_sources', wp_json_encode( $image_sources ) );

			clean_post_cache( $bundle_post_id );

			$redirect_url = add_query_arg(
				array(
					'post_type'         => 'product',
					'page'              => self::MENU_SLUG,
					'lp_bundle_created' => 1,
					'lp_bundle_id'      => $bundle_post_id,
				),
				admin_url( 'edit.php' )
			);

			wp_safe_redirect( $redirect_url );
			exit;
		}

		private function build_bundle_item_from_part( $part ) {
			$item = $this->bundle_item_defaults();

			$product_id = isset( $part['default_product'] ) ? absint( $part['default_product'] ) : 0;
			$product = $product_id > 0 ? wc_get_product( $product_id ) : false;
			if ( $product_id > 0 && $this->is_valid_default_bundle_product( $product ) ) {
				$item['product'] = $product_id;
			}

			$item['products'] = isset( $part['products'] ) ? $this->sanitize_unique_positive_int_array( $part['products'] ) : array();
			$item['categories'] = isset( $part['categories'] ) ? $this->sanitize_unique_positive_int_array( $part['categories'] ) : array();
			$item['tags'] = isset( $part['tags'] ) ? $this->sanitize_unique_positive_int_array( $part['tags'] ) : array();

			$discount_input = isset( $part['discount'] ) ? trim( (string) $part['discount'] ) : '';
			if ( '' !== $discount_input ) {
				$discount_value = (float) $discount_input;
				if ( $discount_value > 0 ) {
					$item['discount_type'] = 'percentage';
					$item['discount']      = $discount_value;
				}
			}

			if ( empty( $item['products'] ) && empty( $item['categories'] ) && empty( $item['tags'] ) && ! empty( $item['product'] ) ) {
				$item['products'] = array( (int) $item['product'] );
			}

			return $this->sanitize_bundle_item( $item );
		}

		private function bundle_item_defaults() {
			return array(
				'optional'             => 'false',
				'selected'             => 'false',
				'products'             => array(),
				'excluded_products'    => array(),
				'categories'           => array(),
				'excluded_categories'  => array(),
				'tags'                 => array(),
				'excluded_tags'        => array(),
				'query_relation'       => 'OR',
				'edit_quantity'        => 'false',
				'discount_type'        => 'none',
				'discount'             => '',
				'product'              => '',
				'min_quantity'         => 1,
				'max_quantity'         => '',
				'quantity'             => 1,
				'orderby'              => 'date',
				'order'                => 'DESC',
				'title'                => '',
				'description'          => '',
				'select_product_title' => 'Please select a product!',
				'product_list_title'   => 'Please select your product!',
				'modal_header_title'   => 'Please select your product',
				'image_url'            => '',
			);
		}

		private function sanitize_bundle_item( $item ) {
			$defaults = $this->bundle_item_defaults();
			$item     = wp_parse_args( $item, $defaults );

			$item['products']            = $this->sanitize_unique_positive_int_array( $item['products'] );
			$item['excluded_products']   = $this->sanitize_unique_positive_int_array( $item['excluded_products'] );
			$item['categories']          = $this->sanitize_unique_positive_int_array( $item['categories'] );
			$item['excluded_categories'] = $this->sanitize_unique_positive_int_array( $item['excluded_categories'] );
			$item['tags']                = $this->sanitize_unique_positive_int_array( $item['tags'] );
			$item['excluded_tags']       = $this->sanitize_unique_positive_int_array( $item['excluded_tags'] );

			$product_id = absint( $item['product'] );
			$item['product'] = $product_id > 0 ? $product_id : '';

			$item['optional']      = ( 'true' === $item['optional'] ) ? 'true' : 'false';
			$item['selected']      = ( 'true' === $item['selected'] ) ? 'true' : 'false';
			$item['edit_quantity'] = ( 'true' === $item['edit_quantity'] ) ? 'true' : 'false';
			$item['query_relation'] = ( 'AND' === strtoupper( (string) $item['query_relation'] ) ) ? 'AND' : 'OR';

			$item['quantity']     = isset( $item['quantity'] ) ? (int) $item['quantity'] : 1;
			$item['min_quantity'] = isset( $item['min_quantity'] ) ? (int) $item['min_quantity'] : 1;
			$item['max_quantity'] = ( '' === $item['max_quantity'] || null === $item['max_quantity'] ) ? '' : (int) $item['max_quantity'];

			$item['discount'] = ( '' === $item['discount'] || null === $item['discount'] ) ? '' : (float) $item['discount'];
			$item['discount_type'] = ( '' !== $item['discount'] && (float) $item['discount'] > 0 ) ? 'percentage' : 'none';
			if ( 'none' === $item['discount_type'] ) {
				$item['discount'] = '';
			}

			$item['orderby']              = sanitize_text_field( $item['orderby'] );
			$item['order']                = sanitize_text_field( $item['order'] );
			$item['title']                = sanitize_text_field( $item['title'] );
			$item['select_product_title'] = sanitize_text_field( $item['select_product_title'] );
			$item['product_list_title']   = sanitize_text_field( $item['product_list_title'] );
			$item['modal_header_title']   = sanitize_text_field( $item['modal_header_title'] );
			$item['description']          = wp_kses_post( $item['description'] );
			$item['image_url']            = esc_url_raw( $item['image_url'] );

			return $item;
		}

		private function sanitize_unique_positive_int_array( $values ) {
			$values = array_map( 'absint', (array) $values );
			$values = array_filter(
				$values,
				function( $value ) {
					return $value > 0;
				}
			);
			return array_values( array_unique( $values ) );
		}

		private function build_default_products_data( $items ) {
			$rows                 = array();
			$is_valid_config      = true;
			$loop_add_to_cart     = 'true';
			$error_message        = '';
			$default_total        = 0.0;
			$generated_sku_parts  = array();

			foreach ( $items as $item ) {
				$qty = isset( $item['quantity'] ) ? (int) $item['quantity'] : 0;
				$pid = isset( $item['product'] ) ? absint( $item['product'] ) : 0;
				if ( $qty <= 0 || $pid <= 0 ) {
					$is_valid_config = false;
					$error_message   = __( 'Ugyldig quantity eller standardprodukt i minst én del.', 'lp-bundle-builder' );
					break;
				}

				$product = wc_get_product( $pid );
				if ( ! $this->is_valid_default_bundle_product( $product ) ) {
					$is_valid_config = false;
					$error_message   = __( 'Et eller flere standardprodukter er ugyldige eller ikke kjøpbare.', 'lp-bundle-builder' );
					break;
				}

				$rows[] = array(
					'id'  => $pid,
					'qty' => $qty,
				);

				$regular_price = $product->get_regular_price();
				$active_price  = $product->get_price();
				$unit_price    = 0.0;
				if ( '' !== (string) $regular_price && is_numeric( $regular_price ) ) {
					$unit_price = (float) $regular_price;
				} elseif ( '' !== (string) $active_price && is_numeric( $active_price ) ) {
					$unit_price = (float) $active_price;
				}
				$default_total += ( $unit_price * $qty );

				$product_sku = trim( (string) $product->get_sku() );
				$generated_sku_parts[] = ( '' !== $product_sku ) ? $product_sku : sprintf( 'ID-%d', $pid );

				if ( 'true' === $loop_add_to_cart ) {
					if ( 'true' === $item['optional'] && 'false' === $item['selected'] ) {
						$loop_add_to_cart = 'false';
					} elseif ( $product->is_type( 'variable' ) ) {
						$loop_add_to_cart = 'false';
					} elseif ( $product->is_type( 'variation' ) && function_exists( '\\AsanaPlugins\\WooCommerce\\ProductBundles\\get_any_value_attributes' ) ) {
						$variation_attributes = $product->get_variation_attributes( false );
						$any_attributes       = \AsanaPlugins\WooCommerce\ProductBundles\get_any_value_attributes( $variation_attributes );
						if ( ! empty( $any_attributes ) ) {
							$loop_add_to_cart = 'false';
						}
					}
				}
			}

			if ( ! $is_valid_config ) {
				return array(
					'is_valid'              => false,
					'error'                 => $error_message,
					'default_products_json' => '',
					'rows'                  => array(),
					'loop_add_to_cart'      => 'false',
					'default_products_total'=> 0,
					'generated_sku'         => '',
				);
			}

			return array(
				'is_valid'               => true,
				'error'                  => '',
				'default_products_json'  => wp_json_encode( $rows ),
				'rows'                   => $rows,
				'loop_add_to_cart'       => $loop_add_to_cart,
				'default_products_total' => (float) wc_format_decimal( $default_total, wc_get_price_decimals() ),
				'generated_sku'          => implode( '+', $generated_sku_parts ),
			);
		}

		private function set_bundle_sku_safely( $bundle, $suggested_sku, $bundle_post_id ) {
			if ( ! $bundle || ! is_a( $bundle, 'WC_Product' ) ) {
				return;
			}

			$sku_candidate = $this->truncate_sku( wc_clean( (string) $suggested_sku ) );
			if ( '' === $sku_candidate || ! wc_product_has_unique_sku( $bundle_post_id, $sku_candidate ) ) {
				$sku_candidate = $this->build_unique_bundle_sku_fallback( $bundle_post_id );
			}

			if ( '' === $sku_candidate ) {
				return;
			}

			try {
				$bundle->set_sku( $sku_candidate );
			} catch ( \WC_Data_Exception $exception ) {
				$fallback_sku = $this->build_unique_bundle_sku_fallback( $bundle_post_id );
				if ( '' === $fallback_sku ) {
					return;
				}
				try {
					$bundle->set_sku( $fallback_sku );
				} catch ( \WC_Data_Exception $ignored_exception ) {
					return;
				}
			}
		}

		private function build_unique_bundle_sku_fallback( $bundle_post_id ) {
			$base = $this->truncate_sku( wc_clean( 'BUNDLE-' . absint( $bundle_post_id ) ) );
			if ( '' === $base ) {
				return '';
			}

			if ( wc_product_has_unique_sku( $bundle_post_id, $base ) ) {
				return $base;
			}

			for ( $attempt = 1; $attempt <= 20; $attempt++ ) {
				$candidate = $this->truncate_sku( $base . '-' . wp_rand( 100, 999 ) );
				if ( '' !== $candidate && wc_product_has_unique_sku( $bundle_post_id, $candidate ) ) {
					return $candidate;
				}
			}

			return '';
		}

		private function truncate_sku( $sku ) {
			$sku = (string) $sku;
			if ( '' === $sku ) {
				return '';
			}
			if ( strlen( $sku ) <= 100 ) {
				return $sku;
			}
			return substr( $sku, 0, 100 );
		}

		private function get_bundle_media_data( $default_products_rows ) {
			$ordered_image_ids = array();
			$seen_image_ids    = array();

			if ( ! is_array( $default_products_rows ) ) {
				return array(
					'fallback_featured_image_id' => 0,
					'all_source_image_ids'       => array(),
				);
			}

			foreach ( $default_products_rows as $row ) {
				$product_id = isset( $row['id'] ) ? absint( $row['id'] ) : 0;
				if ( $product_id <= 0 ) {
					continue;
				}

				$product = wc_get_product( $product_id );
				if ( ! $product || ! is_a( $product, 'WC_Product' ) ) {
					continue;
				}

				$product_image_ids = $this->get_product_media_ids( $product );
				foreach ( $product_image_ids as $image_id ) {
					if ( isset( $seen_image_ids[ $image_id ] ) ) {
						continue;
					}
					$seen_image_ids[ $image_id ] = true;
					$ordered_image_ids[]         = $image_id;
				}
			}

			$fallback_featured_image_id = ! empty( $ordered_image_ids ) ? (int) $ordered_image_ids[0] : 0;

			return array(
				'fallback_featured_image_id' => $fallback_featured_image_id,
				'all_source_image_ids'       => array_values( $ordered_image_ids ),
			);
		}

		private function get_primary_product_attachment_id( $product ) {
			$media_ids = $this->get_product_media_ids( $product );
			if ( empty( $media_ids ) ) {
				return 0;
			}

			return (int) $media_ids[0];
		}

		private function generate_bundle_composite_image( $bundle_post_id, $default_products_rows, $bundle_name = '' ) {
			if ( ! class_exists( 'Imagick' ) || ! class_exists( 'ImagickPixel' ) ) {
				return 0;
			}

			$source_image_paths = array();
			foreach ( (array) $default_products_rows as $row ) {
				$product_id = isset( $row['id'] ) ? absint( $row['id'] ) : 0;
				if ( $product_id <= 0 ) {
					continue;
				}

				$product = wc_get_product( $product_id );
				if ( ! $product || ! is_a( $product, 'WC_Product' ) ) {
					continue;
				}

				$attachment_id = $this->get_primary_product_attachment_id( $product );
				if ( $attachment_id <= 0 ) {
					continue;
				}

				$file_path = get_attached_file( $attachment_id );
				if ( ! $file_path || ! file_exists( $file_path ) || ! is_readable( $file_path ) ) {
					continue;
				}

				$source_image_paths[] = (string) $file_path;
			}

			if ( empty( $source_image_paths ) ) {
				return 0;
			}

			$source_image_paths = array_values( array_unique( $source_image_paths ) );
			$canvas_size = 1600;
			$slots = $this->get_bundle_composite_slots( count( $source_image_paths ), $canvas_size );
			if ( empty( $slots ) ) {
				return 0;
			}

			try {
				$canvas = new \Imagick();
				$canvas->newImage( $canvas_size, $canvas_size, new \ImagickPixel( '#f9f9f7' ) );
				$canvas->setImageFormat( 'jpeg' );
				$canvas->setImageColorspace( \Imagick::COLORSPACE_SRGB );

				foreach ( $slots as $index => $slot ) {
					if ( ! isset( $source_image_paths[ $index ] ) ) {
						continue;
					}

					$image = new \Imagick();
					$image->readImage( $source_image_paths[ $index ] );
					$image->setImageColorspace( \Imagick::COLORSPACE_SRGB );
					$image->setImageBackgroundColor( new \ImagickPixel( 'transparent' ) );
					$image = $image->mergeImageLayers( \Imagick::LAYERMETHOD_MERGE );
					$image->thumbnailImage( (int) $slot['w'], (int) $slot['h'], true, true );

					$x = (int) $slot['x'] + (int) floor( ( (int) $slot['w'] - $image->getImageWidth() ) / 2 );
					$y = (int) $slot['y'] + (int) floor( ( (int) $slot['h'] - $image->getImageHeight() ) / 2 );
					$canvas->compositeImage( $image, \Imagick::COMPOSITE_OVER, $x, $y );
					$image->clear();
					$image->destroy();
				}

				$canvas->setImageCompressionQuality( 90 );
				$image_binary = $canvas->getImagesBlob();
				$canvas->clear();
				$canvas->destroy();
			} catch ( \Exception $exception ) {
				return 0;
			}

			if ( '' === (string) $image_binary ) {
				return 0;
			}

			$bundle_name = sanitize_title( (string) $bundle_name );
			if ( '' === $bundle_name ) {
				$bundle_name = 'bundle';
			}

			$filename = sprintf( '%1$s-composite-%2$d.jpg', $bundle_name, absint( $bundle_post_id ) );
			$upload = wp_upload_bits( $filename, null, $image_binary );
			if ( ! is_array( $upload ) || ! empty( $upload['error'] ) || empty( $upload['file'] ) ) {
				return 0;
			}

			$file_path = (string) $upload['file'];
			$file_type = wp_check_filetype( $file_path, null );
			$attachment_id = wp_insert_attachment(
				array(
					'post_mime_type' => isset( $file_type['type'] ) ? $file_type['type'] : 'image/jpeg',
					'post_title'     => sprintf( 'Bundle composite %d', absint( $bundle_post_id ) ),
					'post_content'   => '',
					'post_status'    => 'inherit',
				),
				$file_path,
				absint( $bundle_post_id )
			);

			if ( is_wp_error( $attachment_id ) || $attachment_id <= 0 ) {
				return 0;
			}

			if ( ! function_exists( 'wp_generate_attachment_metadata' ) ) {
				require_once ABSPATH . 'wp-admin/includes/image.php';
			}

			$metadata = wp_generate_attachment_metadata( $attachment_id, $file_path );
			if ( ! is_wp_error( $metadata ) && is_array( $metadata ) ) {
				wp_update_attachment_metadata( $attachment_id, $metadata );
			}

			return (int) $attachment_id;
		}

		private function get_bundle_composite_slots( $count, $canvas_size ) {
			$count = max( 1, min( 4, (int) $count ) );
			$canvas_size = max( 600, (int) $canvas_size );
			$pad = (int) round( $canvas_size * 0.06 );
			$inner = $canvas_size - ( $pad * 2 );

			if ( 1 === $count ) {
				return array( array( 'x' => $pad, 'y' => $pad, 'w' => $inner, 'h' => $inner ) );
			}

			if ( 2 === $count ) {
				$w = (int) floor( ( $inner - $pad ) / 2 );
				return array(
					array( 'x' => $pad, 'y' => $pad, 'w' => $w, 'h' => $inner ),
					array( 'x' => $pad + $w + $pad, 'y' => $pad, 'w' => $w, 'h' => $inner ),
				);
			}

			if ( 3 === $count ) {
				$half_w = (int) floor( ( $inner - $pad ) / 2 );
				$half_h = (int) floor( ( $inner - $pad ) / 2 );
				return array(
					array( 'x' => $pad, 'y' => $pad, 'w' => $half_w, 'h' => $inner ),
					array( 'x' => $pad + $half_w + $pad, 'y' => $pad, 'w' => $half_w, 'h' => $half_h ),
					array( 'x' => $pad + $half_w + $pad, 'y' => $pad + $half_h + $pad, 'w' => $half_w, 'h' => $half_h ),
				);
			}

			$cell = (int) floor( ( $inner - $pad ) / 2 );
			return array(
				array( 'x' => $pad, 'y' => $pad, 'w' => $cell, 'h' => $cell ),
				array( 'x' => $pad + $cell + $pad, 'y' => $pad, 'w' => $cell, 'h' => $cell ),
				array( 'x' => $pad, 'y' => $pad + $cell + $pad, 'w' => $cell, 'h' => $cell ),
				array( 'x' => $pad + $cell + $pad, 'y' => $pad + $cell + $pad, 'w' => $cell, 'h' => $cell ),
			);
		}

		private function get_product_media_ids( $product ) {
			$media_ids = array();

			if ( ! $product || ! is_a( $product, 'WC_Product' ) ) {
				return $media_ids;
			}

			$product_featured_id = $this->get_valid_attachment_id( $product->get_image_id() );
			if ( $product_featured_id > 0 ) {
				$media_ids[] = $product_featured_id;
			}

			$product_gallery_ids = $this->sanitize_attachment_ids( $product->get_gallery_image_ids() );
			$media_ids           = array_merge( $media_ids, $product_gallery_ids );

			if ( $product->is_type( 'variation' ) ) {
				$parent_id      = (int) $product->get_parent_id();
				$parent_product = $parent_id > 0 ? wc_get_product( $parent_id ) : false;

				if ( ! $product_featured_id && $parent_product && is_a( $parent_product, 'WC_Product' ) ) {
					$parent_featured_id = $this->get_valid_attachment_id( $parent_product->get_image_id() );
					if ( $parent_featured_id > 0 ) {
						$media_ids[] = $parent_featured_id;
					}
				}

				if ( empty( $product_gallery_ids ) && $parent_product && is_a( $parent_product, 'WC_Product' ) ) {
					$parent_gallery_ids = $this->sanitize_attachment_ids( $parent_product->get_gallery_image_ids() );
					if ( ! empty( $parent_gallery_ids ) ) {
						$media_ids = array_merge( $media_ids, $parent_gallery_ids );
					}
				}
			}

			$media_ids = $this->sanitize_attachment_ids( $media_ids );
			return array_values( array_unique( $media_ids ) );
		}

		private function sanitize_attachment_ids( $ids ) {
			$valid_ids = array();
			foreach ( (array) $ids as $id ) {
				$attachment_id = $this->get_valid_attachment_id( $id );
				if ( $attachment_id > 0 ) {
					$valid_ids[] = $attachment_id;
				}
			}
			return array_values( array_unique( $valid_ids ) );
		}

		private function get_valid_attachment_id( $attachment_id ) {
			$attachment_id = absint( $attachment_id );
			if ( $attachment_id <= 0 ) {
				return 0;
			}

			$url = wp_get_attachment_image_url( $attachment_id, 'full' );
			return '' !== (string) $url ? $attachment_id : 0;
		}

		private function build_bundle_combined_description( $default_products_rows ) {
			if ( ! is_array( $default_products_rows ) || empty( $default_products_rows ) ) {
				return '';
			}

			$intro_sentence = $this->build_bundle_intro_sentence( $default_products_rows );
			$sections       = array();

			foreach ( $default_products_rows as $row ) {
				$product_id = isset( $row['id'] ) ? absint( $row['id'] ) : 0;
				if ( $product_id <= 0 ) {
					continue;
				}

				$product = wc_get_product( $product_id );
				if ( ! $product || ! is_a( $product, 'WC_Product' ) ) {
					continue;
				}

				$long_description  = trim( (string) $product->get_description() );
				$short_description = trim( (string) $product->get_short_description() );

				$normalized_long  = wp_strip_all_tags( $long_description );
				$normalized_short = wp_strip_all_tags( $short_description );
				$is_same_text     = '' !== $normalized_long && '' !== $normalized_short && $normalized_long === $normalized_short;

				$body_parts = array();
				if ( '' !== $long_description ) {
					$body_parts[] = wp_kses_post( $long_description );
				}
				if ( '' !== $short_description && ! $is_same_text ) {
					$body_parts[] = wp_kses_post( $short_description );
				}

				if ( empty( $body_parts ) ) {
					continue;
				}

				$section  = '<h3>' . esc_html( $product->get_name() ) . '</h3>';
				$section .= "\n" . implode( "\n\n", $body_parts );
				$sections[] = $section;
			}

			$parts   = array( '<p>' . esc_html( $intro_sentence ) . '</p>' );
			if ( ! empty( $sections ) ) {
				$parts[] = implode( "\n\n", $sections );
			}

			return wp_kses_post( implode( "\n\n", $parts ) );
		}

		private function build_bundle_intro_sentence( $default_products_rows ) {
			$name_list = $this->build_bundle_product_name_list( $default_products_rows );
			if ( empty( $name_list ) ) {
				return '';
			}

			return sprintf( 'Pakke bestående av %s.', $this->join_norwegian_list( $name_list ) );
		}

		private function build_bundle_product_name_list( $default_products_rows ) {
			$names = array();
			if ( ! is_array( $default_products_rows ) ) {
				return $names;
			}

			foreach ( $default_products_rows as $row ) {
				$product_id = isset( $row['id'] ) ? absint( $row['id'] ) : 0;
				$quantity   = isset( $row['qty'] ) ? (int) $row['qty'] : 1;
				if ( $product_id <= 0 || $quantity <= 0 ) {
					continue;
				}

				$product = wc_get_product( $product_id );
				if ( ! $product || ! is_a( $product, 'WC_Product' ) ) {
					continue;
				}

				$name = trim( (string) $product->get_name() );
				if ( '' === $name ) {
					continue;
				}

				$names[] = $quantity > 1 ? sprintf( '%1$d x %2$s', $quantity, $name ) : $name;
			}

			return $names;
		}

		private function join_norwegian_list( $items ) {
			$items = array_values(
				array_filter(
					array_map(
						function( $item ) {
							return trim( (string) $item );
						},
						(array) $items
					),
					function( $item ) {
						return '' !== $item;
					}
				)
			);

			$count = count( $items );
			if ( 0 === $count ) {
				return '';
			}
			if ( 1 === $count ) {
				return $items[0];
			}
			if ( 2 === $count ) {
				return $items[0] . ' og ' . $items[1];
			}

			$last_item = array_pop( $items );
			return implode( ', ', $items ) . ' og ' . $last_item;
		}

		private function get_bundle_image_source_data( $default_products_rows ) {
			$sources = array();
			if ( ! is_array( $default_products_rows ) ) {
				return $sources;
			}

			foreach ( $default_products_rows as $index => $row ) {
				$product_id = isset( $row['id'] ) ? absint( $row['id'] ) : 0;
				$quantity   = isset( $row['qty'] ) ? (int) $row['qty'] : 1;
				if ( $product_id <= 0 || $quantity <= 0 ) {
					continue;
				}

				$product = wc_get_product( $product_id );
				if ( ! $product || ! is_a( $product, 'WC_Product' ) ) {
					continue;
				}

				$featured_url = $this->get_best_product_image_url( $product );
				if ( '' === (string) $featured_url ) {
					continue;
				}

				$gallery_urls = $this->get_product_gallery_urls( $product );

				$sources[] = array(
					'index'              => $index + 1,
					'product_id'         => (int) $product->get_id(),
					'name'               => (string) $product->get_name(),
					'sku'                => (string) $product->get_sku(),
					'permalink'          => (string) $product->get_permalink(),
					'featured_image_url' => (string) $featured_url,
					'gallery_image_urls' => array_values( array_unique( $gallery_urls ) ),
					'quantity'           => $quantity,
				);
			}

			return $sources;
		}

		private function get_product_gallery_urls( $product ) {
			$gallery_urls = array();
			if ( ! $product || ! is_a( $product, 'WC_Product' ) || ! method_exists( $product, 'get_gallery_image_ids' ) ) {
				return $gallery_urls;
			}

			$gallery_ids = (array) $product->get_gallery_image_ids();
			foreach ( $gallery_ids as $gallery_id ) {
				$gallery_url = wp_get_attachment_image_url( (int) $gallery_id, 'full' );
				if ( '' !== (string) $gallery_url ) {
					$gallery_urls[] = (string) $gallery_url;
				}
			}

			return array_values( array_unique( $gallery_urls ) );
		}

		private function get_best_product_image_url( $product ) {
			if ( ! $product || ! is_a( $product, 'WC_Product' ) ) {
				return '';
			}

			$featured_id = (int) $product->get_image_id();
			if ( $featured_id > 0 ) {
				$featured_url = wp_get_attachment_image_url( $featured_id, 'full' );
				if ( '' !== (string) $featured_url ) {
					return (string) $featured_url;
				}
			}

			$parent_product = null;
			if ( $product->is_type( 'variation' ) ) {
				$parent_id = (int) $product->get_parent_id();
				if ( $parent_id > 0 ) {
					$parent_product = wc_get_product( $parent_id );
					if ( $parent_product && is_a( $parent_product, 'WC_Product' ) ) {
						$parent_featured_id = (int) $parent_product->get_image_id();
						if ( $parent_featured_id > 0 ) {
							$parent_featured_url = wp_get_attachment_image_url( $parent_featured_id, 'full' );
							if ( '' !== (string) $parent_featured_url ) {
								return (string) $parent_featured_url;
							}
						}
					}
				}
			}

			$product_gallery_urls = $this->get_product_gallery_urls( $product );
			if ( ! empty( $product_gallery_urls ) ) {
				return (string) $product_gallery_urls[0];
			}

			if ( $product->is_type( 'variation' ) && $parent_product && is_a( $parent_product, 'WC_Product' ) ) {
				$parent_gallery_urls = $this->get_product_gallery_urls( $parent_product );
				if ( ! empty( $parent_gallery_urls ) ) {
					return (string) $parent_gallery_urls[0];
				}
			}

			return '';
		}

		private function build_bundle_image_prompt( $bundle, $image_sources ) {
			$bundle_name = '';
			if ( $bundle && is_a( $bundle, 'WC_Product' ) ) {
				$bundle_name = trim( (string) $bundle->get_name() );
			}
			if ( '' === $bundle_name ) {
				$bundle_name = __( 'Bundle product', 'lp-bundle-builder' );
			}

			$lines   = array();
			$lines[] = 'Create one finished square ecommerce bundle image for: ' . $bundle_name . ' by compositing the exact provided source product photos.';
			$lines[] = 'This must be a clean product-image composite / photomontage, not a newly rendered product scene.';
			$lines[] = 'Use the original source photos as the actual visual content.';
			$lines[] = '';
			$lines[] = 'If working in ChatGPT, use the uploaded source images or the exact source image URLs below as the only visual source material.';
			$lines[] = '';
			$lines[] = 'Non-negotiable identity and realism rules:';
			$lines[] = '- Use ONLY the provided source product photos and/or source image URLs below.';
			$lines[] = '- Do not generate a new stroller, bassinet, frame, wheel set, canopy, handlebar, seat, or accessory.';
			$lines[] = '- Do not substitute the products with a similar model or a different brand/model.';
			$lines[] = '- Do not reinterpret the products.';
			$lines[] = '- Do not recreate them from scratch.';
			$lines[] = '- Do not render a new version.';
			$lines[] = '- Do not merge the products into a different design.';
			$lines[] = '- Do not simplify or restyle product details.';
			$lines[] = '- Do not replace any visible product feature with a cleaner or more premium-looking version.';
			$lines[] = '- The final result must clearly remain the exact same products from the source photos.';
			$lines[] = '';
			$lines[] = 'Camera angle and geometry lock:';
			$lines[] = '- Keep each product in its original camera angle from the source photo.';
			$lines[] = '- Do not invent a new viewing angle.';
			$lines[] = '- Do not rotate products into a new perspective.';
			$lines[] = '- Do not reconstruct hidden geometry.';
			$lines[] = '- Do not fabricate surfaces or sides that are not visible in the original source image.';
			$lines[] = '- If a different angle would be required, do not do it.';
			$lines[] = '';
			$lines[] = 'Allowed edits only (strict):';
			$lines[] = '- Background removal only.';
			$lines[] = '- Cutout edge cleanup only.';
			$lines[] = '- Move the cutouts.';
			$lines[] = '- Proportional scaling.';
			$lines[] = '- Stacking/layering of cutouts.';
			$lines[] = '- Slight opacity reduction for a secondary product if needed.';
			$lines[] = '- Very subtle shadow only when it naturally results from compositing.';
			$lines[] = '- Forbidden: redrawing, inpainting missing parts, shape correction, changing trim/materials, changing wheel size or geometry, changing frame geometry, changing canopy shape, changing handles, changing branding placement.';
			$lines[] = '';
			$lines[] = 'Safe fallback layout rule:';
			$lines[] = '- If you cannot preserve the products exactly while overlapping them, place them side by side on the same light background instead of inventing or altering anything.';
			$lines[] = '';
			$lines[] = 'Visual style (premium Scandinavian ecommerce bundle image):';
			$lines[] = '- Square format';
			$lines[] = '- Clean studio look';
			$lines[] = '- Very light grey or soft neutral background';
			$lines[] = '- No text, no added logos, no badges';
			$lines[] = '- Realistic product proportions';
			$lines[] = '- No dramatic or fake-looking shadows';
			$lines[] = '- Polished webshop look';
			$lines[] = '- One product may be the main hero item in front';
			$lines[] = '- Secondary items may be behind, beside, or slightly faded';
			$lines[] = '';
			$lines[] = 'Composition freedom (strictly limited):';
			$lines[] = '- You may only choose placement, scale, layering order, and spacing.';
			$lines[] = '- You may decide which product is in front only by arranging the provided cutouts.';
			$lines[] = '- You may not change the products themselves in any way.';
			$lines[] = '- Freedom applies only to layout, not to product appearance.';
			$lines[] = '';
			$lines[] = 'Output constraints:';
			$lines[] = '- Output one finished square bundle image only.';
			$lines[] = '- Clean light background.';
			$lines[] = '- No text.';
			$lines[] = '- No extra props.';
			$lines[] = '- No humans.';
			$lines[] = '- No environment.';
			$lines[] = '- No fantasy styling.';
			$lines[] = '';
			$lines[] = 'Summary:';
			$lines[] = '- Use exact source photos';
			$lines[] = '- Keep exact product identity';
			$lines[] = '- Keep exact camera angle';
			$lines[] = '- No substitution';
			$lines[] = '- No redesign';
			$lines[] = '- No re-render';
			$lines[] = '- No invented geometry';
			$lines[] = '- If necessary, do a simple side-by-side composite';
			$lines[] = '';
			$lines[] = 'Source images:';

			$source_count = 0;
			if ( is_array( $image_sources ) ) {
				foreach ( $image_sources as $source ) {
					$url = isset( $source['featured_image_url'] ) ? trim( (string) $source['featured_image_url'] ) : '';
					if ( '' === $url ) {
						continue;
					}
					$source_count++;
					$name = isset( $source['name'] ) ? trim( (string) $source['name'] ) : '';
					$qty  = isset( $source['quantity'] ) ? (int) $source['quantity'] : 1;
					if ( '' === $name ) {
						$name = sprintf( 'Product %d', $source_count );
					}
					$line = sprintf( '- Product %1$d: %2$s — %3$s', $source_count, $name, $url );
					if ( $qty > 1 ) {
						$line .= sprintf( ' (Quantity: %d)', $qty );
					}
					$lines[] = $line;
				}
			}

			if ( 0 === $source_count ) {
				$lines[] = '- No product image URLs were found from validated default products.';
				$lines[] = '- Ask the user to provide direct product image URLs, then repeat with the same strict constraints above.';
			}

			return implode( "\n", $lines );
		}

		private function is_valid_default_bundle_product( $product ) {
			if ( ! $product || ! is_a( $product, 'WC_Product' ) ) {
				return false;
			}

			if ( ! $product->exists() || ! $product->is_purchasable() ) {
				return false;
			}

			if ( $product->is_type( 'variable' ) || $product->is_type( 'grouped' ) || $product->is_type( 'external' ) || $product->is_type( self::PRODUCT_TYPE ) ) {
				return false;
			}

			if ( $product->is_type( 'variation' ) ) {
				$parent_id = (int) $product->get_parent_id();
				if ( $parent_id <= 0 || ! wc_get_product( $parent_id ) ) {
					return false;
				}
			}

			return true;
		}

		private function redirect_with_error( $message ) {
			$redirect_url = add_query_arg(
				array(
					'post_type'       => 'product',
					'page'            => self::MENU_SLUG,
					'lp_bundle_error' => rawurlencode( wp_strip_all_tags( $message ) ),
				),
				admin_url( 'edit.php' )
			);

			wp_safe_redirect( $redirect_url );
			exit;
		}
	}

	new LP_Single_File_Bundle_Builder();
}

add_action(
	'admin_notices',
	function() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		if ( empty( $_GET['page'] ) ) {
			return;
		}

		$page = sanitize_key( wp_unslash( $_GET['page'] ) );

		if ( LP_Single_File_Bundle_Builder::SETTINGS_MENU_SLUG === $page && ! empty( $_GET['lp_settings_saved'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Bundle Builder defaults saved.', 'lp-bundle-builder' ) . '</p></div>';
		}

		if ( LP_Single_File_Bundle_Builder::MENU_SLUG !== $page || empty( $_GET['lp_bundle_error'] ) ) {
			return;
		}

		$message = sanitize_text_field( wp_unslash( $_GET['lp_bundle_error'] ) );
		echo '<div class="notice notice-error"><p>' . esc_html( rawurldecode( $message ) ) . '</p></div>';
	}
);
