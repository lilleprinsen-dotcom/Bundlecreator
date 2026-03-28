<?php
/**
 * Plugin Name: Lilleprinsen - Bundle Builder for Easy Product Bundles
 * Description: En enkel side under Produkter der du kan velge flere produkter og opprette et nytt Easy Product Bundle-produkt. Laget for kompatibilitet med Easy Product Bundles for WooCommerce.
 * Version: 1.0.0
 * Author: OpenAI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'LP_Single_File_Bundle_Builder' ) ) {
	final class LP_Single_File_Bundle_Builder {
		const MENU_SLUG   = 'lp-easy-bundle-builder';
		const NONCE_ACTION = 'lp_easy_bundle_builder_create';
		const AJAX_NONCE_ACTION = 'lp_easy_bundle_builder_search';
		const PRODUCT_TYPE = 'easy_product_bundle';

		public function __construct() {
			add_action( 'admin_menu', array( $this, 'register_menu' ), 99 );
			add_action( 'admin_post_lp_create_easy_bundle', array( $this, 'handle_create_bundle' ) );
			add_action( 'wp_ajax_lp_search_bundle_products', array( $this, 'ajax_search_products' ) );
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
		}

		public function admin_notices() {
			if ( ! current_user_can( 'manage_woocommerce' ) ) {
				return;
			}

			if ( ! $this->is_dependency_ready() ) {
				$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
				if ( $screen && in_array( $screen->id, array( 'product_page_' . self::MENU_SLUG, 'edit-product', 'product' ), true ) ) {
					echo '<div class="notice notice-error"><p>' . esc_html__( 'Bundle Builder krever at WooCommerce og Easy Product Bundles for WooCommerce er aktive.', 'lp-bundle-builder' ) . '</p></div>';
				}
			}
		}

		private function is_dependency_ready() {
			return class_exists( 'WooCommerce' )
				&& class_exists( '\\AsanaPlugins\\WooCommerce\\ProductBundles\\ProductBundle' )
				&& class_exists( '\\AsanaPlugins\\WooCommerce\\ProductBundles\\Models\\SimpleBundleItemsModel' );
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
			?>
			<div class="wrap lp-bundle-builder-wrap">
				<h1><?php echo esc_html__( 'Bundle Builder', 'lp-bundle-builder' ); ?></h1>
				<p><?php echo esc_html__( 'Velg produkter, så opprettes et nytt bundle-produkt der hvert valgt produkt blir én separat bundle-del.', 'lp-bundle-builder' ); ?></p>
				<p><strong><?php echo esc_html__( 'Merk:', 'lp-bundle-builder' ); ?></strong> <?php echo esc_html__( 'Søket viser bare konkrete, kjøpbare produkter/variasjoner for best mulig kompatibilitet med Easy Product Bundles.', 'lp-bundle-builder' ); ?></p>

				<?php if ( ! empty( $_GET['lp_bundle_created'] ) && $created_id > 0 ) : ?>
					<div class="notice notice-success inline"><p>
						<?php echo esc_html__( 'Bundle opprettet.', 'lp-bundle-builder' ); ?>
						<a href="<?php echo esc_url( admin_url( 'post.php?post=' . $created_id . '&action=edit' ) ); ?>"><?php echo esc_html__( 'Åpne produktet', 'lp-bundle-builder' ); ?></a>
					</p></div>
				<?php endif; ?>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="lp-bundle-builder-form">
					<input type="hidden" name="action" value="lp_create_easy_bundle" />
					<?php wp_nonce_field( self::NONCE_ACTION ); ?>

					<table class="form-table" role="presentation">
						<tbody>
							<tr>
								<th scope="row"><label for="lp_bundle_title"><?php echo esc_html__( 'Bundle-navn', 'lp-bundle-builder' ); ?></label></th>
								<td>
									<input type="text" class="regular-text" id="lp_bundle_title" name="bundle_title" required placeholder="<?php echo esc_attr__( 'F.eks. Gavesett vår', 'lp-bundle-builder' ); ?>" />
									<p class="description"><?php echo esc_html__( 'Dette blir tittelen på det nye bundle-produktet.', 'lp-bundle-builder' ); ?></p>
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="lp_bundle_status"><?php echo esc_html__( 'Produktstatus', 'lp-bundle-builder' ); ?></label></th>
								<td>
									<select id="lp_bundle_status" name="bundle_status">
										<option value="draft"><?php echo esc_html__( 'Kladd', 'lp-bundle-builder' ); ?></option>
										<option value="publish"><?php echo esc_html__( 'Publisert', 'lp-bundle-builder' ); ?></option>
									</select>
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="lp_fixed_price"><?php echo esc_html__( 'Fast pris', 'lp-bundle-builder' ); ?></label></th>
								<td>
									<label for="lp_fixed_price">
										<input type="checkbox" id="lp_fixed_price" name="fixed_price" value="1" />
										<?php echo esc_html__( 'Aktiver fast pris for bundlen', 'lp-bundle-builder' ); ?>
									</label>
									<p class="description"><?php echo esc_html__( 'Når aktivert settes fixed_price til true.', 'lp-bundle-builder' ); ?></p>
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="lp_bundle_button_label"><?php echo esc_html__( 'Bundle-knappetekst (shop)', 'lp-bundle-builder' ); ?></label></th>
								<td>
									<input
										type="text"
										class="regular-text"
										id="lp_bundle_button_label"
										name="bundle_button_label"
										value="<?php echo esc_attr__( 'Configure bundle', 'lp-bundle-builder' ); ?>"
										maxlength="120"
									/>
									<p class="description"><?php echo esc_html__( 'Teksten som vises på bundle-knappen i shop/listing.', 'lp-bundle-builder' ); ?></p>
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="lp_product_search"><?php echo esc_html__( 'Legg til produkter', 'lp-bundle-builder' ); ?></label></th>
								<td>
									<input type="search" class="regular-text" id="lp_product_search" placeholder="<?php echo esc_attr__( 'Søk etter produktnavn eller SKU', 'lp-bundle-builder' ); ?>" autocomplete="off" />
									<button type="button" class="button" id="lp_clear_search"><?php echo esc_html__( 'Tøm', 'lp-bundle-builder' ); ?></button>
									<div id="lp_product_search_results" class="lp-search-results"></div>
									<p class="description"><?php echo esc_html__( 'Klikk på et søkeresultat for å legge det til i bundlen.', 'lp-bundle-builder' ); ?></p>
								</td>
							</tr>
							<tr>
								<th scope="row"><?php echo esc_html__( 'Valgte produkter', 'lp-bundle-builder' ); ?></th>
								<td>
									<ul id="lp_selected_products" class="lp-selected-products"></ul>
									<div id="lp_selected_inputs"></div>
									<p class="description"><?php echo esc_html__( 'Rekkefølgen du legger dem til i blir rekkefølgen i bundlen. Hver vare får antall 1 og blir én egen bundle-del.', 'lp-bundle-builder' ); ?></p>
								</td>
							</tr>
						</tbody>
					</table>

					<?php submit_button( __( 'Opprett bundle', 'lp-bundle-builder' ) ); ?>
				</form>
			</div>

			<style>
				.lp-search-results {
					max-width: 720px;
					margin-top: 10px;
					background: #fff;
					border: 1px solid #ccd0d4;
					border-radius: 4px;
					display: none;
					max-height: 280px;
					overflow: auto;
				}
				.lp-search-results button {
					display: block;
					width: 100%;
					text-align: left;
					padding: 10px 12px;
					border: 0;
					border-bottom: 1px solid #f0f0f1;
					background: #fff;
					cursor: pointer;
				}
				.lp-search-results button:hover {
					background: #f6f7f7;
				}
				.lp-selected-products {
					max-width: 720px;
					margin: 0;
					padding: 0;
				}
				.lp-selected-products li {
					display: flex;
					align-items: center;
					justify-content: space-between;
					gap: 12px;
					padding: 10px 12px;
					background: #fff;
					border: 1px solid #ccd0d4;
					border-radius: 4px;
					margin-bottom: 8px;
				}
				.lp-selected-products .lp-product-meta {
					color: #50575e;
					font-size: 12px;
				}
				.lp-empty-selection {
					padding: 12px;
					background: #fff;
					border: 1px dashed #ccd0d4;
					border-radius: 4px;
					max-width: 720px;
					color: #50575e;
				}
			</style>

			<script>
			(function(){
				const ajaxUrl = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
				const ajaxNonce = <?php echo wp_json_encode( wp_create_nonce( self::AJAX_NONCE_ACTION ) ); ?>;
				const searchInput = document.getElementById('lp_product_search');
				const clearButton = document.getElementById('lp_clear_search');
				const resultsBox = document.getElementById('lp_product_search_results');
				const selectedList = document.getElementById('lp_selected_products');
				const selectedInputs = document.getElementById('lp_selected_inputs');
				const form = document.getElementById('lp-bundle-builder-form');
				let timer = null;
				let selected = [];

				function renderEmptyState() {
					if (selected.length) {
						return;
					}
					selectedList.innerHTML = '<li class="lp-empty-selection"><?php echo esc_js( __( 'Ingen produkter valgt ennå.', 'lp-bundle-builder' ) ); ?></li>';
					selectedInputs.innerHTML = '';
				}

				function renderSelected() {
					if (!selected.length) {
						renderEmptyState();
						return;
					}

					selectedList.innerHTML = '';
					selectedInputs.innerHTML = '';

					selected.forEach(function(product, index){
						const li = document.createElement('li');
						const infoWrap = document.createElement('div');
						const strong = document.createElement('strong');
						strong.textContent = product.name;
						const meta = document.createElement('div');
						meta.className = 'lp-product-meta';
						meta.textContent = '#' + product.id + (product.sku ? ' | SKU: ' + product.sku : '');
						infoWrap.appendChild(strong);
						infoWrap.appendChild(meta);

						const removeButton = document.createElement('button');
						removeButton.type = 'button';
						removeButton.className = 'button-link-delete';
						removeButton.dataset.index = index;
						removeButton.textContent = '<?php echo esc_js( __( 'Fjern', 'lp-bundle-builder' ) ); ?>';

						li.appendChild(infoWrap);
						li.appendChild(removeButton);
						selectedList.appendChild(li);

						const input = document.createElement('input');
						input.type = 'hidden';
						input.name = 'product_ids[]';
						input.value = product.id;
						selectedInputs.appendChild(input);
					});
				}

				function hideResults() {
					resultsBox.style.display = 'none';
					resultsBox.innerHTML = '';
				}

				function showMessage(message) {
					resultsBox.innerHTML = '<div style="padding:10px 12px;">' + message + '</div>';
					resultsBox.style.display = 'block';
				}

				function runSearch(term) {
					const params = new URLSearchParams();
					params.append('action', 'lp_search_bundle_products');
					params.append('nonce', ajaxNonce);
					params.append('q', term);

					fetch(ajaxUrl, {
						method: 'POST',
						headers: {
							'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
						},
						body: params.toString()
					})
					.then(function(response){ return response.json(); })
					.then(function(response){
						if (!response || !response.success || !Array.isArray(response.data)) {
							showMessage('<?php echo esc_js( __( 'Ingen treff.', 'lp-bundle-builder' ) ); ?>');
							return;
						}

						if (!response.data.length) {
							showMessage('<?php echo esc_js( __( 'Ingen treff.', 'lp-bundle-builder' ) ); ?>');
							return;
						}

						resultsBox.innerHTML = '';
						response.data.forEach(function(product){
							const btn = document.createElement('button');
							const strong = document.createElement('strong');
							const meta = document.createElement('span');
							btn.type = 'button';
							btn.dataset.product = JSON.stringify(product);
							strong.textContent = product.name;
							meta.textContent = '#' + product.id + (product.sku ? ' | SKU: ' + product.sku : '');
							btn.appendChild(strong);
							btn.appendChild(document.createElement('br'));
							btn.appendChild(meta);
							resultsBox.appendChild(btn);
						});
						resultsBox.style.display = 'block';
					})
					.catch(function(){
						showMessage('<?php echo esc_js( __( 'Det oppstod en feil i søket.', 'lp-bundle-builder' ) ); ?>');
					});
				}

				searchInput.addEventListener('input', function(){
					const term = searchInput.value.trim();
					clearTimeout(timer);

					if (term.length < 2) {
						hideResults();
						return;
					}

					timer = setTimeout(function(){
						runSearch(term);
					}, 250);
				});

				clearButton.addEventListener('click', function(){
					searchInput.value = '';
					hideResults();
					searchInput.focus();
				});

				resultsBox.addEventListener('click', function(event){
					const button = event.target.closest('button[data-product]');
					if (!button) {
						return;
					}

					const product = JSON.parse(button.dataset.product);
					const exists = selected.some(function(item){ return String(item.id) === String(product.id); });
					if (!exists) {
						selected.push(product);
						renderSelected();
					}

					searchInput.value = '';
					hideResults();
					searchInput.focus();
				});

				selectedList.addEventListener('click', function(event){
					const button = event.target.closest('button[data-index]');
					if (!button) {
						return;
					}

					const index = parseInt(button.dataset.index, 10);
					if (!Number.isNaN(index)) {
						selected.splice(index, 1);
						renderSelected();
					}
				});

				form.addEventListener('submit', function(event){
					if (!selected.length) {
						event.preventDefault();
						window.alert('<?php echo esc_js( __( 'Velg minst ett produkt før du oppretter bundlen.', 'lp-bundle-builder' ) ); ?>');
					}
				});

				renderEmptyState();
			})();
			</script>
			<?php
		}

		public function ajax_search_products() {
			if ( ! current_user_can( 'manage_woocommerce' ) ) {
				wp_send_json_error( array(), 403 );
			}

			check_ajax_referer( self::AJAX_NONCE_ACTION, 'nonce' );

			$term = isset( $_POST['q'] ) ? sanitize_text_field( wp_unslash( $_POST['q'] ) ) : '';
			if ( '' === $term || strlen( $term ) < 2 ) {
				wp_send_json_success( array() );
			}

			$results = array();
			foreach ( $this->search_products( $term ) as $product ) {
				$results[] = array(
					'id'   => $product->get_id(),
					'name' => wp_strip_all_tags( $this->get_product_label( $product ) ),
					'sku'  => $product->get_sku(),
				);
			}

			wp_send_json_success( $results );
		}

		private function search_products( $term ) {
			$term = trim( $term );
			$ids  = array();

			$query_args = array(
				'post_type'              => array( 'product', 'product_variation' ),
				'post_status'            => array( 'publish', 'private' ),
				'posts_per_page'         => 20,
				's'                      => $term,
				'fields'                 => 'ids',
				'orderby'                => 'title',
				'order'                  => 'ASC',
				'no_found_rows'          => true,
				'ignore_sticky_posts'    => true,
				'suppress_filters'       => false,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			);

			$search_query = new WP_Query( $query_args );
			if ( ! empty( $search_query->posts ) ) {
				$ids = array_merge( $ids, $search_query->posts );
			}

			$sku_ids = get_posts(
				array(
					'post_type'              => array( 'product', 'product_variation' ),
					'post_status'            => array( 'publish', 'private' ),
					'posts_per_page'         => 20,
					'fields'                 => 'ids',
					'orderby'                => 'title',
					'order'                  => 'ASC',
					'no_found_rows'          => true,
					'ignore_sticky_posts'    => true,
					'suppress_filters'       => false,
					'update_post_meta_cache' => false,
					'update_post_term_cache' => false,
					'meta_query'             => array(
						array(
							'key'     => '_sku',
							'value'   => $term,
							'compare' => 'LIKE',
						),
					),
				)
			);

			if ( ! empty( $sku_ids ) ) {
				$ids = array_merge( $ids, $sku_ids );
			}

			$ids      = array_values( array_unique( array_map( 'absint', $ids ) ) );
			$products = array();

			foreach ( $ids as $product_id ) {
				$product = wc_get_product( $product_id );
				if ( ! $product || ! $this->is_allowed_bundle_item_product( $product ) ) {
					continue;
				}

				$products[] = $product;
				if ( count( $products ) >= 20 ) {
					break;
				}
			}

			return $products;
		}

		private function get_product_label( WC_Product $product ) {
			$name = $product->get_formatted_name();
			if ( ! $name ) {
				$name = $product->get_name();
			}
			return $name;
		}

		private function is_allowed_bundle_item_product( WC_Product $product ) {
			if ( ! $product->exists() || ! $product->is_purchasable() ) {
				return false;
			}

			$type = $product->get_type();

			if ( self::PRODUCT_TYPE === $type || 'variable' === $type ) {
				return false;
			}

			if (
				false !== strpos( $type, 'bundle' ) ||
				false !== strpos( $type, 'group' ) ||
				false !== strpos( $type, 'composite' ) ||
				false !== strpos( $type, 'booking' )
			) {
				return false;
			}

			return true;
		}

		public function handle_create_bundle() {
			if ( ! current_user_can( 'manage_woocommerce' ) ) {
				wp_die( esc_html__( 'Du har ikke tilgang til å opprette bundle.', 'lp-bundle-builder' ) );
			}

			check_admin_referer( self::NONCE_ACTION );

			if ( ! $this->is_dependency_ready() ) {
				$this->redirect_with_error( __( 'WooCommerce eller Easy Product Bundles er ikke aktiv.', 'lp-bundle-builder' ) );
			}

			$title      = isset( $_POST['bundle_title'] ) ? sanitize_text_field( wp_unslash( $_POST['bundle_title'] ) ) : '';
			$status_raw = isset( $_POST['bundle_status'] ) ? sanitize_key( wp_unslash( $_POST['bundle_status'] ) ) : 'draft';
			$status     = in_array( $status_raw, array( 'draft', 'publish' ), true ) ? $status_raw : 'draft';
			$fixed_price = ! empty( $_POST['fixed_price'] ) ? 'true' : 'false';
			$bundle_button_label = isset( $_POST['bundle_button_label'] ) ? sanitize_text_field( wp_unslash( $_POST['bundle_button_label'] ) ) : '';
			if ( '' === $bundle_button_label ) {
				$bundle_button_label = 'Configure bundle';
			}
			$product_ids = isset( $_POST['product_ids'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['product_ids'] ) ) : array();
			$product_ids = array_values( array_unique( array_filter( $product_ids ) ) );

			if ( '' === $title ) {
				$title = sprintf( __( 'Nytt bundle %s', 'lp-bundle-builder' ), current_time( 'Y-m-d H:i' ) );
			}

			if ( empty( $product_ids ) ) {
				$this->redirect_with_error( __( 'Du må velge minst ett produkt.', 'lp-bundle-builder' ) );
			}

			$products = array();
			foreach ( $product_ids as $product_id ) {
				$product = wc_get_product( $product_id );
				if ( ! $product || ! $this->is_allowed_bundle_item_product( $product ) ) {
					continue;
				}
				$products[] = $product;
			}

			if ( empty( $products ) ) {
				$this->redirect_with_error( __( 'Ingen gyldige produkter ble valgt.', 'lp-bundle-builder' ) );
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

			$items            = array();
			$default_products = array();
			$loop_add_to_cart = true;

			foreach ( $products as $product ) {
				$product_id = $product->get_id();
				$items[] = $this->build_bundle_item( $product_id );
				$default_products[] = array(
					'id'  => $product_id,
					'qty' => 1,
				);

				if ( $loop_add_to_cart ) {
					if ( $product->is_type( 'variable' ) ) {
						$loop_add_to_cart = false;
					} elseif ( $product->is_type( 'variation' ) && function_exists( '\\AsanaPlugins\\WooCommerce\\ProductBundles\\get_any_value_attributes' ) ) {
						$variation_attributes = $product->get_variation_attributes( false );
						$any_attributes = \AsanaPlugins\WooCommerce\ProductBundles\get_any_value_attributes( $variation_attributes );
						if ( ! empty( $any_attributes ) ) {
							$loop_add_to_cart = false;
						}
					}
				}
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
				'default_products'         => wp_json_encode( $default_products ),
				'loop_add_to_cart'         => $loop_add_to_cart ? 'true' : 'false',
				'sync_stock_quantity'      => 'false',
				'bundle_button_label'      => $bundle_button_label,
			);

			$errors = $bundle->set_props( $props );
			if ( is_wp_error( $errors ) ) {
				wp_delete_post( $bundle_post_id, true );
				$this->redirect_with_error( $errors->get_error_message() );
			}

			$model = \AsanaPlugins\WooCommerce\ProductBundles\get_plugin()->container()->get(
				\AsanaPlugins\WooCommerce\ProductBundles\Models\SimpleBundleItemsModel::class
			);
			$model->delete_bundle( $bundle_post_id );
			foreach ( $default_products as $default_product ) {
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

		private function build_bundle_item( $product_id ) {
			return array(
				'optional'             => 'false',
				'selected'             => 'true',
				'products'             => array( (int) $product_id ),
				'excluded_products'    => array(),
				'categories'           => array(),
				'excluded_categories'  => array(),
				'tags'                 => array(),
				'excluded_tags'        => array(),
				'query_relation'       => 'OR',
				'edit_quantity'        => 'false',
				'discount_type'        => 'none',
				'discount'             => '',
				'product'              => (int) $product_id,
				'min_quantity'         => 1,
				'max_quantity'         => '',
				'quantity'             => 1,
				'orderby'              => 'date',
				'order'                => 'DESC',
				'title'                => '',
				'description'          => '',
				'select_product_title' => __( 'Please select a product!', 'asnp-easy-product-bundles' ),
				'product_list_title'   => __( 'Please select your product!', 'asnp-easy-product-bundles' ),
				'modal_header_title'   => __( 'Please select your product', 'asnp-easy-product-bundles' ),
				'image_url'            => '',
			);
		}

		private function redirect_with_error( $message ) {
			$redirect_url = add_query_arg(
				array(
					'post_type'        => 'product',
					'page'             => self::MENU_SLUG,
					'lp_bundle_error'  => rawurlencode( wp_strip_all_tags( $message ) ),
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

		if ( empty( $_GET['page'] ) || LP_Single_File_Bundle_Builder::MENU_SLUG !== sanitize_key( wp_unslash( $_GET['page'] ) ) || empty( $_GET['lp_bundle_error'] ) ) {
			return;
		}

		$message = sanitize_text_field( wp_unslash( $_GET['lp_bundle_error'] ) );
		echo '<div class="notice notice-error"><p>' . esc_html( rawurldecode( $message ) ) . '</p></div>';
	}
);
