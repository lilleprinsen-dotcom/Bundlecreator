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
				'bundle_button_label'  => 'Configure bundle',
				'sync_stock_quantity'  => 'false',
				'manage_stock'         => 'no',
				'stock_status'         => 'instock',
				'tax_status'           => 'taxable',
			);
		}

		private function normalize_true_false_string( $value ) {
			return ( 'true' === strtolower( (string) $value ) || '1' === (string) $value || true === $value ) ? 'true' : 'false';
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

			return array(
				'product_status'      => $product_status,
				'fixed_price'         => $fixed_price,
				'bundle_button_label' => $bundle_button_label,
				'sync_stock_quantity' => $sync_stock_quantity,
				'manage_stock'        => $manage_stock,
				'stock_status'        => $stock_status,
				'tax_status'          => $tax_status,
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
				'bundle_button_label' => isset( $_POST['bundle_button_label'] ) ? wp_unslash( $_POST['bundle_button_label'] ) : '',
				'sync_stock_quantity' => isset( $_POST['sync_stock_quantity'] ) ? 'true' : 'false',
				'manage_stock'        => isset( $_POST['manage_stock'] ) ? wp_unslash( $_POST['manage_stock'] ) : '',
				'stock_status'        => isset( $_POST['stock_status'] ) ? wp_unslash( $_POST['stock_status'] ) : '',
				'tax_status'          => isset( $_POST['tax_status'] ) ? wp_unslash( $_POST['tax_status'] ) : '',
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
						</tbody>
					</table>
					<?php submit_button( __( 'Save defaults', 'lp-bundle-builder' ) ); ?>
				</form>
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
			?>
			<div class="wrap lp-bundle-builder-wrap">
				<h1><?php echo esc_html__( 'Bundle Builder', 'lp-bundle-builder' ); ?></h1>
				<p><?php echo esc_html__( 'Bygg bundle-deler: hver del blir ett Easy Product Bundles-item.', 'lp-bundle-builder' ); ?></p>

				<?php if ( ! empty( $_GET['lp_bundle_created'] ) && $created_id > 0 ) : ?>
					<div class="notice notice-success inline"><p>
						<?php echo esc_html__( 'Bundle opprettet.', 'lp-bundle-builder' ); ?>
						<a href="<?php echo esc_url( admin_url( 'post.php?post=' . $created_id . '&action=edit' ) ); ?>"><?php echo esc_html__( 'Åpne produktet', 'lp-bundle-builder' ); ?></a>
					</p></div>
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
								<th scope="row"><label for="lp_fixed_price"><?php echo esc_html__( 'Fast pris', 'lp-bundle-builder' ); ?></label></th>
								<td>
									<label for="lp_fixed_price">
										<input type="checkbox" id="lp_fixed_price" name="fixed_price" value="1" <?php checked( $defaults['fixed_price'], 'true' ); ?> />
										<?php echo esc_html__( 'Aktiver fast pris for bundlen', 'lp-bundle-builder' ); ?>
									</label>
								</td>
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
			</style>

			<script>
			(function(){
				const ajaxUrl = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
				const ajaxNonce = <?php echo wp_json_encode( wp_create_nonce( self::AJAX_NONCE_ACTION ) ); ?>;
				const form = document.getElementById('lp-bundle-builder-form');
				const partsContainer = document.getElementById('lp_parts_container');
				const addPartButton = document.getElementById('lp_add_part');
				const partsOverview = document.getElementById('lp_parts_overview');
				const partsInput = document.getElementById('lp_parts_json');
				let parts = [];

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

				$query = new \WP_Query(
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
				if ( ! empty( $query->posts ) && is_array( $query->posts ) ) {
					$product_ids = array_map( 'absint', $query->posts );
				}
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

				$name = $product->get_name();
				$sku  = (string) $product->get_sku();
				$label = '' !== $sku ? sprintf( '%1$s (%2$s)', $sku, $name ) : sprintf( '(%s)', $name );

				$results[] = array(
					'value'      => (int) $product->get_id(),
					'label'      => $label,
					'isDisabled' => false,
				);
			}

			return $results;
		}

		private function fallback_term_items( $taxonomy, $search, $items, $mode ) {
			$args = array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
				'number'     => 30,
			);

			if ( 'fetch' === $mode ) {
				$args['include'] = $this->sanitize_unique_positive_int_array( $items );
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
			$rows             = array();
			$is_valid_config  = true;
			$loop_add_to_cart = 'true';
			$error_message    = '';

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
				);
			}

			return array(
				'is_valid'              => true,
				'error'                 => '',
				'default_products_json' => wp_json_encode( $rows ),
				'rows'                  => $rows,
				'loop_add_to_cart'      => $loop_add_to_cart,
			);
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
