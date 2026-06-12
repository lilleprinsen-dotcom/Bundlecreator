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
			add_action( 'wp_ajax_lp_bundle_image_preview', array( $this, 'ajax_bundle_image_preview' ) );
			add_action( 'wp_ajax_lp_bundle_variant_candidates', array( $this, 'ajax_bundle_variant_candidates' ) );
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

		private function create_bundle_product_object( $title, $status ) {
			try {
				$bundle = function_exists( 'wc_get_product_object' )
					? wc_get_product_object( self::PRODUCT_TYPE )
					: new \AsanaPlugins\WooCommerce\ProductBundles\ProductBundle();
			} catch ( \Throwable $e ) {
				return new \WP_Error(
					'lp_bundle_class_error',
					sprintf(
						/* translators: %s: technical error message from WooCommerce or Easy Product Bundles. */
						__( 'Bundle-produktet kunne ikke opprettes: %s', 'lp-bundle-builder' ),
						$e->getMessage()
					)
				);
			}

			if ( ! $bundle instanceof \AsanaPlugins\WooCommerce\ProductBundles\ProductBundle || ! method_exists( $bundle, 'set_props' ) ) {
				return new \WP_Error(
					'lp_bundle_class_error',
					sprintf(
						/* translators: %s: loaded PHP class name. */
						__( 'WooCommerce returnerte feil bundle-klasse: %s', 'lp-bundle-builder' ),
						is_object( $bundle ) ? get_class( $bundle ) : gettype( $bundle )
					)
				);
			}

			try {
				$bundle->set_name( $title );
				$bundle->set_status( $status );
				$bundle_post_id = $bundle->save();
			} catch ( \Throwable $e ) {
				return new \WP_Error(
					'lp_bundle_create_error',
					sprintf(
						/* translators: %s: technical error message from WooCommerce or Easy Product Bundles. */
						__( 'Bundle-produktet kunne ikke lagres: %s', 'lp-bundle-builder' ),
						$e->getMessage()
					)
				);
			}

			if ( ! $bundle_post_id || is_wp_error( $bundle_post_id ) ) {
				return new \WP_Error( 'lp_bundle_create_error', __( 'Kunne ikke opprette bundle-produktet.', 'lp-bundle-builder' ) );
			}

			$term_result = wp_set_object_terms( $bundle_post_id, self::PRODUCT_TYPE, 'product_type' );
			if ( is_wp_error( $term_result ) ) {
				wp_delete_post( $bundle_post_id, true );
				return $term_result;
			}

			clean_post_cache( $bundle_post_id );
			if ( function_exists( 'wc_delete_product_transients' ) ) {
				wc_delete_product_transients( $bundle_post_id );
			}

			return $bundle;
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
				'bundle_composite_layout'     => 'collage',
				'bundle_composite_spacing'    => 'tight',
				'bundle_composite_background' => 'white',
				'bundle_composite_canvas'     => 'square',
				'bundle_composite_box_style'  => 'none',
				'bundle_composite_shadow'     => 'none',
				'bundle_composite_trim'       => 'auto',
				'bundle_composite_primary_scale' => 'large',
				'bundle_composite_secondary_scale' => 'medium',
				'bundle_composite_secondary_position' => 'top_right',
				'bundle_composite_overlap' => 'medium',
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

		private function sanitize_bundle_composite_layout( $value ) {
			$layout = sanitize_key( (string) $value );
			return in_array( $layout, array( 'manual', 'collage', 'diagonal', 'hero', 'grid', 'featured', 'row' ), true ) ? $layout : 'collage';
		}

		private function sanitize_bundle_composite_spacing( $value ) {
			$spacing = sanitize_key( (string) $value );
			return in_array( $spacing, array( 'tight', 'balanced', 'airy' ), true ) ? $spacing : 'tight';
		}

		private function sanitize_bundle_composite_background( $value ) {
			$background = sanitize_key( (string) $value );
			return in_array( $background, array( 'warm', 'white', 'gray' ), true ) ? $background : 'white';
		}

		private function sanitize_bundle_composite_canvas( $value ) {
			$canvas = sanitize_key( (string) $value );
			return in_array( $canvas, array( 'square', 'landscape', 'portrait' ), true ) ? $canvas : 'square';
		}

		private function sanitize_bundle_composite_box_style( $value ) {
			$box_style = sanitize_key( (string) $value );
			return in_array( $box_style, array( 'none', 'cards', 'border' ), true ) ? $box_style : 'none';
		}

		private function sanitize_bundle_composite_shadow( $value ) {
			$shadow = sanitize_key( (string) $value );
			return in_array( $shadow, array( 'none', 'soft', 'strong' ), true ) ? $shadow : 'none';
		}

		private function sanitize_bundle_composite_trim( $value ) {
			$trim = sanitize_key( (string) $value );
			return in_array( $trim, array( 'auto', 'none' ), true ) ? $trim : 'auto';
		}

		private function sanitize_bundle_composite_scale( $value, $fallback = 'medium' ) {
			$scale = sanitize_key( (string) $value );
			return in_array( $scale, array( 'small', 'medium', 'large', 'xlarge' ), true ) ? $scale : $fallback;
		}

		private function sanitize_bundle_composite_secondary_position( $value ) {
			$position = sanitize_key( (string) $value );
			return in_array( $position, array( 'top_right', 'bottom_right', 'bottom_left', 'top_left', 'right', 'left' ), true ) ? $position : 'top_right';
		}

		private function sanitize_bundle_composite_overlap( $value ) {
			$overlap = sanitize_key( (string) $value );
			return in_array( $overlap, array( 'none', 'subtle', 'medium', 'strong' ), true ) ? $overlap : 'medium';
		}

		private function sanitize_normalized_float( $value, $fallback = 0, $min = 0, $max = 1 ) {
			if ( ! is_numeric( $value ) ) {
				$value = $fallback;
			}

			$value = (float) $value;
			return max( (float) $min, min( (float) $max, $value ) );
		}

		private function sanitize_bundle_composite_manual_setup( $value ) {
			if ( is_string( $value ) ) {
				$decoded = json_decode( $value, true );
				$value   = is_array( $decoded ) ? $decoded : array();
			}

			$value = is_array( $value ) ? $value : array();
			$product_layers_raw = isset( $value['product_layers'] ) && is_array( $value['product_layers'] ) ? $value['product_layers'] : array();
			$text_layers_raw    = isset( $value['text_layers'] ) && is_array( $value['text_layers'] ) ? $value['text_layers'] : array();
			$product_layers     = array();
			$text_layers        = array();

			foreach ( $product_layers_raw as $line_index => $layer_raw ) {
				$layer_raw  = is_array( $layer_raw ) ? $layer_raw : array();
				$line_index = absint( $line_index );
				if ( $line_index > 50 ) {
					continue;
				}

				$product_layers[ (string) $line_index ] = array(
					'x'                    => $this->sanitize_normalized_float( isset( $layer_raw['x'] ) ? $layer_raw['x'] : 0.05, 0.05 ),
					'y'                    => $this->sanitize_normalized_float( isset( $layer_raw['y'] ) ? $layer_raw['y'] : 0.05, 0.05 ),
					'w'                    => $this->sanitize_normalized_float( isset( $layer_raw['w'] ) ? $layer_raw['w'] : 0.45, 0.45, 0.03, 1 ),
					'h'                    => $this->sanitize_normalized_float( isset( $layer_raw['h'] ) ? $layer_raw['h'] : 0.45, 0.45, 0.03, 1 ),
					'z'                    => max( -50, min( 100, isset( $layer_raw['z'] ) ? (int) $layer_raw['z'] : (int) $line_index ) ),
					'remove_background'    => ! empty( $layer_raw['remove_background'] ) ? 'true' : 'false',
					'background_tolerance' => max( 0, min( 100, isset( $layer_raw['background_tolerance'] ) ? absint( $layer_raw['background_tolerance'] ) : 12 ) ),
				);
			}

			foreach ( $text_layers_raw as $layer_raw ) {
				if ( count( $text_layers ) >= 8 ) {
					break;
				}

				$layer_raw = is_array( $layer_raw ) ? $layer_raw : array();
				$text      = isset( $layer_raw['text'] ) ? sanitize_text_field( (string) $layer_raw['text'] ) : '';
				if ( '' === $text ) {
					continue;
				}

				$align = isset( $layer_raw['align'] ) ? sanitize_key( (string) $layer_raw['align'] ) : 'left';
				$align = in_array( $align, array( 'left', 'center', 'right' ), true ) ? $align : 'left';
				$color = isset( $layer_raw['color'] ) ? sanitize_hex_color( (string) $layer_raw['color'] ) : '#111111';
				if ( '' === $color || null === $color ) {
					$color = '#111111';
				}

				$text_layers[] = array(
					'text'      => $text,
					'x'         => $this->sanitize_normalized_float( isset( $layer_raw['x'] ) ? $layer_raw['x'] : 0.08, 0.08 ),
					'y'         => $this->sanitize_normalized_float( isset( $layer_raw['y'] ) ? $layer_raw['y'] : 0.08, 0.08 ),
					'font_size' => max( 10, min( 260, isset( $layer_raw['font_size'] ) ? absint( $layer_raw['font_size'] ) : 64 ) ),
					'color'     => $color,
					'align'     => $align,
					'bold'      => ! empty( $layer_raw['bold'] ) ? 'true' : 'false',
					'z'         => max( -50, min( 150, isset( $layer_raw['z'] ) ? (int) $layer_raw['z'] : 50 ) ),
				);
			}

			return array(
				'product_layers' => $product_layers,
				'text_layers'    => $text_layers,
			);
		}

		private function sanitize_bundle_composite_options( $raw_options ) {
			$defaults    = $this->defaults_fallback();
			$raw_options = is_array( $raw_options ) ? $raw_options : array();
			$layout      = isset( $raw_options['bundle_composite_layout'] ) ? $raw_options['bundle_composite_layout'] : ( isset( $raw_options['layout'] ) ? $raw_options['layout'] : $defaults['bundle_composite_layout'] );
			$spacing     = isset( $raw_options['bundle_composite_spacing'] ) ? $raw_options['bundle_composite_spacing'] : ( isset( $raw_options['spacing'] ) ? $raw_options['spacing'] : $defaults['bundle_composite_spacing'] );
			$background  = isset( $raw_options['bundle_composite_background'] ) ? $raw_options['bundle_composite_background'] : ( isset( $raw_options['background'] ) ? $raw_options['background'] : $defaults['bundle_composite_background'] );
			$canvas      = isset( $raw_options['bundle_composite_canvas'] ) ? $raw_options['bundle_composite_canvas'] : ( isset( $raw_options['canvas'] ) ? $raw_options['canvas'] : $defaults['bundle_composite_canvas'] );
			$box_style   = isset( $raw_options['bundle_composite_box_style'] ) ? $raw_options['bundle_composite_box_style'] : ( isset( $raw_options['box_style'] ) ? $raw_options['box_style'] : $defaults['bundle_composite_box_style'] );
			$shadow      = isset( $raw_options['bundle_composite_shadow'] ) ? $raw_options['bundle_composite_shadow'] : ( isset( $raw_options['shadow'] ) ? $raw_options['shadow'] : $defaults['bundle_composite_shadow'] );
			$trim        = isset( $raw_options['bundle_composite_trim'] ) ? $raw_options['bundle_composite_trim'] : ( isset( $raw_options['trim'] ) ? $raw_options['trim'] : $defaults['bundle_composite_trim'] );
			$primary_scale = isset( $raw_options['bundle_composite_primary_scale'] ) ? $raw_options['bundle_composite_primary_scale'] : ( isset( $raw_options['primary_scale'] ) ? $raw_options['primary_scale'] : $defaults['bundle_composite_primary_scale'] );
			$secondary_scale = isset( $raw_options['bundle_composite_secondary_scale'] ) ? $raw_options['bundle_composite_secondary_scale'] : ( isset( $raw_options['secondary_scale'] ) ? $raw_options['secondary_scale'] : $defaults['bundle_composite_secondary_scale'] );
			$secondary_position = isset( $raw_options['bundle_composite_secondary_position'] ) ? $raw_options['bundle_composite_secondary_position'] : ( isset( $raw_options['secondary_position'] ) ? $raw_options['secondary_position'] : $defaults['bundle_composite_secondary_position'] );
			$overlap     = isset( $raw_options['bundle_composite_overlap'] ) ? $raw_options['bundle_composite_overlap'] : ( isset( $raw_options['overlap'] ) ? $raw_options['overlap'] : $defaults['bundle_composite_overlap'] );
			$manual_setup = isset( $raw_options['bundle_composite_manual_setup'] ) ? $raw_options['bundle_composite_manual_setup'] : ( isset( $raw_options['manual_setup'] ) ? $raw_options['manual_setup'] : array() );

			return array(
				'layout'             => $this->sanitize_bundle_composite_layout( $layout ),
				'spacing'            => $this->sanitize_bundle_composite_spacing( $spacing ),
				'background'         => $this->sanitize_bundle_composite_background( $background ),
				'canvas'             => $this->sanitize_bundle_composite_canvas( $canvas ),
				'box_style'          => $this->sanitize_bundle_composite_box_style( $box_style ),
				'shadow'             => $this->sanitize_bundle_composite_shadow( $shadow ),
				'trim'               => $this->sanitize_bundle_composite_trim( $trim ),
				'primary_scale'      => $this->sanitize_bundle_composite_scale( $primary_scale, 'large' ),
				'secondary_scale'    => $this->sanitize_bundle_composite_scale( $secondary_scale, 'medium' ),
				'secondary_position' => $this->sanitize_bundle_composite_secondary_position( $secondary_position ),
				'overlap'            => $this->sanitize_bundle_composite_overlap( $overlap ),
				'manual_setup'       => $this->sanitize_bundle_composite_manual_setup( $manual_setup ),
			);
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
			$bundle_composite_options = $this->sanitize_bundle_composite_options( $raw_defaults );

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
				'bundle_composite_layout'     => $bundle_composite_options['layout'],
				'bundle_composite_spacing'    => $bundle_composite_options['spacing'],
				'bundle_composite_background' => $bundle_composite_options['background'],
				'bundle_composite_canvas'     => $bundle_composite_options['canvas'],
				'bundle_composite_box_style'  => $bundle_composite_options['box_style'],
				'bundle_composite_shadow'     => $bundle_composite_options['shadow'],
				'bundle_composite_trim'       => $bundle_composite_options['trim'],
				'bundle_composite_primary_scale' => $bundle_composite_options['primary_scale'],
				'bundle_composite_secondary_scale' => $bundle_composite_options['secondary_scale'],
				'bundle_composite_secondary_position' => $bundle_composite_options['secondary_position'],
				'bundle_composite_overlap' => $bundle_composite_options['overlap'],
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
				'bundle_composite_layout'     => isset( $_POST['bundle_composite_layout'] ) ? wp_unslash( $_POST['bundle_composite_layout'] ) : '',
				'bundle_composite_spacing'    => isset( $_POST['bundle_composite_spacing'] ) ? wp_unslash( $_POST['bundle_composite_spacing'] ) : '',
				'bundle_composite_background' => isset( $_POST['bundle_composite_background'] ) ? wp_unslash( $_POST['bundle_composite_background'] ) : '',
				'bundle_composite_canvas'     => isset( $_POST['bundle_composite_canvas'] ) ? wp_unslash( $_POST['bundle_composite_canvas'] ) : '',
				'bundle_composite_box_style'  => isset( $_POST['bundle_composite_box_style'] ) ? wp_unslash( $_POST['bundle_composite_box_style'] ) : '',
				'bundle_composite_shadow'     => isset( $_POST['bundle_composite_shadow'] ) ? wp_unslash( $_POST['bundle_composite_shadow'] ) : '',
				'bundle_composite_trim'       => isset( $_POST['bundle_composite_trim'] ) ? wp_unslash( $_POST['bundle_composite_trim'] ) : '',
				'bundle_composite_primary_scale' => isset( $_POST['bundle_composite_primary_scale'] ) ? wp_unslash( $_POST['bundle_composite_primary_scale'] ) : '',
				'bundle_composite_secondary_scale' => isset( $_POST['bundle_composite_secondary_scale'] ) ? wp_unslash( $_POST['bundle_composite_secondary_scale'] ) : '',
				'bundle_composite_secondary_position' => isset( $_POST['bundle_composite_secondary_position'] ) ? wp_unslash( $_POST['bundle_composite_secondary_position'] ) : '',
				'bundle_composite_overlap' => isset( $_POST['bundle_composite_overlap'] ) ? wp_unslash( $_POST['bundle_composite_overlap'] ) : '',
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
							<tr>
								<th scope="row"><label for="lp_default_bundle_composite_layout"><?php echo esc_html__( 'Composite layout default', 'lp-bundle-builder' ); ?></label></th>
								<td>
									<select id="lp_default_bundle_composite_layout" name="bundle_composite_layout">
										<option value="manual" <?php selected( $defaults['bundle_composite_layout'], 'manual' ); ?>><?php echo esc_html__( 'Manual editor', 'lp-bundle-builder' ); ?></option>
										<option value="collage" <?php selected( $defaults['bundle_composite_layout'], 'collage' ); ?>><?php echo esc_html__( 'Collage overlap', 'lp-bundle-builder' ); ?></option>
										<option value="diagonal" <?php selected( $defaults['bundle_composite_layout'], 'diagonal' ); ?>><?php echo esc_html__( 'Diagonal hero', 'lp-bundle-builder' ); ?></option>
										<option value="hero" <?php selected( $defaults['bundle_composite_layout'], 'hero' ); ?>><?php echo esc_html__( 'Main product hero', 'lp-bundle-builder' ); ?></option>
										<option value="grid" <?php selected( $defaults['bundle_composite_layout'], 'grid' ); ?>><?php echo esc_html__( 'Even grid', 'lp-bundle-builder' ); ?></option>
										<option value="featured" <?php selected( $defaults['bundle_composite_layout'], 'featured' ); ?>><?php echo esc_html__( 'Large first product', 'lp-bundle-builder' ); ?></option>
										<option value="row" <?php selected( $defaults['bundle_composite_layout'], 'row' ); ?>><?php echo esc_html__( 'Single row', 'lp-bundle-builder' ); ?></option>
									</select>
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="lp_default_bundle_composite_spacing"><?php echo esc_html__( 'Composite spacing default', 'lp-bundle-builder' ); ?></label></th>
								<td>
									<select id="lp_default_bundle_composite_spacing" name="bundle_composite_spacing">
										<option value="tight" <?php selected( $defaults['bundle_composite_spacing'], 'tight' ); ?>><?php echo esc_html__( 'Tight', 'lp-bundle-builder' ); ?></option>
										<option value="balanced" <?php selected( $defaults['bundle_composite_spacing'], 'balanced' ); ?>><?php echo esc_html__( 'Balanced', 'lp-bundle-builder' ); ?></option>
										<option value="airy" <?php selected( $defaults['bundle_composite_spacing'], 'airy' ); ?>><?php echo esc_html__( 'Airy', 'lp-bundle-builder' ); ?></option>
									</select>
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="lp_default_bundle_composite_background"><?php echo esc_html__( 'Composite background default', 'lp-bundle-builder' ); ?></label></th>
								<td>
									<select id="lp_default_bundle_composite_background" name="bundle_composite_background">
										<option value="warm" <?php selected( $defaults['bundle_composite_background'], 'warm' ); ?>><?php echo esc_html__( 'Warm white', 'lp-bundle-builder' ); ?></option>
										<option value="white" <?php selected( $defaults['bundle_composite_background'], 'white' ); ?>><?php echo esc_html__( 'Pure white', 'lp-bundle-builder' ); ?></option>
										<option value="gray" <?php selected( $defaults['bundle_composite_background'], 'gray' ); ?>><?php echo esc_html__( 'Light gray', 'lp-bundle-builder' ); ?></option>
									</select>
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="lp_default_bundle_composite_canvas"><?php echo esc_html__( 'Composite canvas default', 'lp-bundle-builder' ); ?></label></th>
								<td>
									<select id="lp_default_bundle_composite_canvas" name="bundle_composite_canvas">
										<option value="square" <?php selected( $defaults['bundle_composite_canvas'], 'square' ); ?>><?php echo esc_html__( 'Square', 'lp-bundle-builder' ); ?></option>
										<option value="landscape" <?php selected( $defaults['bundle_composite_canvas'], 'landscape' ); ?>><?php echo esc_html__( 'Landscape', 'lp-bundle-builder' ); ?></option>
										<option value="portrait" <?php selected( $defaults['bundle_composite_canvas'], 'portrait' ); ?>><?php echo esc_html__( 'Portrait', 'lp-bundle-builder' ); ?></option>
									</select>
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="lp_default_bundle_composite_box_style"><?php echo esc_html__( 'Composite box style default', 'lp-bundle-builder' ); ?></label></th>
								<td>
									<select id="lp_default_bundle_composite_box_style" name="bundle_composite_box_style">
										<option value="none" <?php selected( $defaults['bundle_composite_box_style'], 'none' ); ?>><?php echo esc_html__( 'No boxes', 'lp-bundle-builder' ); ?></option>
										<option value="cards" <?php selected( $defaults['bundle_composite_box_style'], 'cards' ); ?>><?php echo esc_html__( 'Borderless cards', 'lp-bundle-builder' ); ?></option>
										<option value="border" <?php selected( $defaults['bundle_composite_box_style'], 'border' ); ?>><?php echo esc_html__( 'Thin border cards', 'lp-bundle-builder' ); ?></option>
									</select>
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="lp_default_bundle_composite_shadow"><?php echo esc_html__( 'Composite shadow default', 'lp-bundle-builder' ); ?></label></th>
								<td>
									<select id="lp_default_bundle_composite_shadow" name="bundle_composite_shadow">
										<option value="none" <?php selected( $defaults['bundle_composite_shadow'], 'none' ); ?>><?php echo esc_html__( 'No shadow', 'lp-bundle-builder' ); ?></option>
										<option value="soft" <?php selected( $defaults['bundle_composite_shadow'], 'soft' ); ?>><?php echo esc_html__( 'Soft shadow', 'lp-bundle-builder' ); ?></option>
										<option value="strong" <?php selected( $defaults['bundle_composite_shadow'], 'strong' ); ?>><?php echo esc_html__( 'Strong shadow', 'lp-bundle-builder' ); ?></option>
									</select>
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="lp_default_bundle_composite_trim"><?php echo esc_html__( 'Composite whitespace default', 'lp-bundle-builder' ); ?></label></th>
								<td>
									<select id="lp_default_bundle_composite_trim" name="bundle_composite_trim">
										<option value="auto" <?php selected( $defaults['bundle_composite_trim'], 'auto' ); ?>><?php echo esc_html__( 'Auto trim product whitespace', 'lp-bundle-builder' ); ?></option>
										<option value="none" <?php selected( $defaults['bundle_composite_trim'], 'none' ); ?>><?php echo esc_html__( 'Keep full product images', 'lp-bundle-builder' ); ?></option>
									</select>
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="lp_default_bundle_composite_primary_scale"><?php echo esc_html__( 'Main product size default', 'lp-bundle-builder' ); ?></label></th>
								<td>
									<select id="lp_default_bundle_composite_primary_scale" name="bundle_composite_primary_scale">
										<option value="medium" <?php selected( $defaults['bundle_composite_primary_scale'], 'medium' ); ?>><?php echo esc_html__( 'Medium', 'lp-bundle-builder' ); ?></option>
										<option value="large" <?php selected( $defaults['bundle_composite_primary_scale'], 'large' ); ?>><?php echo esc_html__( 'Large', 'lp-bundle-builder' ); ?></option>
										<option value="xlarge" <?php selected( $defaults['bundle_composite_primary_scale'], 'xlarge' ); ?>><?php echo esc_html__( 'Extra large', 'lp-bundle-builder' ); ?></option>
									</select>
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="lp_default_bundle_composite_secondary_scale"><?php echo esc_html__( 'Other products size default', 'lp-bundle-builder' ); ?></label></th>
								<td>
									<select id="lp_default_bundle_composite_secondary_scale" name="bundle_composite_secondary_scale">
										<option value="small" <?php selected( $defaults['bundle_composite_secondary_scale'], 'small' ); ?>><?php echo esc_html__( 'Small', 'lp-bundle-builder' ); ?></option>
										<option value="medium" <?php selected( $defaults['bundle_composite_secondary_scale'], 'medium' ); ?>><?php echo esc_html__( 'Medium', 'lp-bundle-builder' ); ?></option>
										<option value="large" <?php selected( $defaults['bundle_composite_secondary_scale'], 'large' ); ?>><?php echo esc_html__( 'Large', 'lp-bundle-builder' ); ?></option>
										<option value="xlarge" <?php selected( $defaults['bundle_composite_secondary_scale'], 'xlarge' ); ?>><?php echo esc_html__( 'Extra large', 'lp-bundle-builder' ); ?></option>
									</select>
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="lp_default_bundle_composite_secondary_position"><?php echo esc_html__( 'Other products position default', 'lp-bundle-builder' ); ?></label></th>
								<td>
									<select id="lp_default_bundle_composite_secondary_position" name="bundle_composite_secondary_position">
										<option value="top_right" <?php selected( $defaults['bundle_composite_secondary_position'], 'top_right' ); ?>><?php echo esc_html__( 'Top right', 'lp-bundle-builder' ); ?></option>
										<option value="right" <?php selected( $defaults['bundle_composite_secondary_position'], 'right' ); ?>><?php echo esc_html__( 'Right', 'lp-bundle-builder' ); ?></option>
										<option value="bottom_right" <?php selected( $defaults['bundle_composite_secondary_position'], 'bottom_right' ); ?>><?php echo esc_html__( 'Bottom right', 'lp-bundle-builder' ); ?></option>
										<option value="top_left" <?php selected( $defaults['bundle_composite_secondary_position'], 'top_left' ); ?>><?php echo esc_html__( 'Top left', 'lp-bundle-builder' ); ?></option>
										<option value="left" <?php selected( $defaults['bundle_composite_secondary_position'], 'left' ); ?>><?php echo esc_html__( 'Left', 'lp-bundle-builder' ); ?></option>
										<option value="bottom_left" <?php selected( $defaults['bundle_composite_secondary_position'], 'bottom_left' ); ?>><?php echo esc_html__( 'Bottom left', 'lp-bundle-builder' ); ?></option>
									</select>
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="lp_default_bundle_composite_overlap"><?php echo esc_html__( 'Composite overlap default', 'lp-bundle-builder' ); ?></label></th>
								<td>
									<select id="lp_default_bundle_composite_overlap" name="bundle_composite_overlap">
										<option value="none" <?php selected( $defaults['bundle_composite_overlap'], 'none' ); ?>><?php echo esc_html__( 'None', 'lp-bundle-builder' ); ?></option>
										<option value="subtle" <?php selected( $defaults['bundle_composite_overlap'], 'subtle' ); ?>><?php echo esc_html__( 'Subtle', 'lp-bundle-builder' ); ?></option>
										<option value="medium" <?php selected( $defaults['bundle_composite_overlap'], 'medium' ); ?>><?php echo esc_html__( 'Medium', 'lp-bundle-builder' ); ?></option>
										<option value="strong" <?php selected( $defaults['bundle_composite_overlap'], 'strong' ); ?>><?php echo esc_html__( 'Strong', 'lp-bundle-builder' ); ?></option>
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
			$variant_created_ids = isset( $_GET['lp_bundle_variant_ids'] ) ? $this->sanitize_unique_positive_int_array( explode( ',', (string) wp_unslash( $_GET['lp_bundle_variant_ids'] ) ) ) : array();
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
					<?php if ( ! empty( $variant_created_ids ) ) : ?>
						<div class="notice notice-success inline">
							<p><?php echo esc_html( sprintf( __( 'Opprettet %d ekstra bundle(r).', 'lp-bundle-builder' ), count( $variant_created_ids ) ) ); ?></p>
							<ul>
								<?php foreach ( $variant_created_ids as $variant_created_id ) : ?>
									<li><a href="<?php echo esc_url( admin_url( 'post.php?post=' . absint( $variant_created_id ) . '&action=edit' ) ); ?>"><?php echo esc_html( get_the_title( $variant_created_id ) ); ?></a></li>
								<?php endforeach; ?>
							</ul>
						</div>
					<?php endif; ?>
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
					<input type="hidden" id="lp_bundle_composite_manual_setup" name="bundle_composite_manual_setup" value="" />

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
							<tr class="lp-composite-option-row">
								<th scope="row"><label for="lp_bundle_composite_layout"><?php echo esc_html__( 'Composite layout', 'lp-bundle-builder' ); ?></label></th>
								<td>
									<select id="lp_bundle_composite_layout" name="bundle_composite_layout">
										<option value="manual" <?php selected( $defaults['bundle_composite_layout'], 'manual' ); ?>><?php echo esc_html__( 'Manual editor', 'lp-bundle-builder' ); ?></option>
										<option value="collage" <?php selected( $defaults['bundle_composite_layout'], 'collage' ); ?>><?php echo esc_html__( 'Collage overlap', 'lp-bundle-builder' ); ?></option>
										<option value="diagonal" <?php selected( $defaults['bundle_composite_layout'], 'diagonal' ); ?>><?php echo esc_html__( 'Diagonal hero', 'lp-bundle-builder' ); ?></option>
										<option value="hero" <?php selected( $defaults['bundle_composite_layout'], 'hero' ); ?>><?php echo esc_html__( 'Main product hero', 'lp-bundle-builder' ); ?></option>
										<option value="grid" <?php selected( $defaults['bundle_composite_layout'], 'grid' ); ?>><?php echo esc_html__( 'Even grid', 'lp-bundle-builder' ); ?></option>
										<option value="featured" <?php selected( $defaults['bundle_composite_layout'], 'featured' ); ?>><?php echo esc_html__( 'Large first product', 'lp-bundle-builder' ); ?></option>
										<option value="row" <?php selected( $defaults['bundle_composite_layout'], 'row' ); ?>><?php echo esc_html__( 'Single row', 'lp-bundle-builder' ); ?></option>
									</select>
								</td>
							</tr>
							<tr class="lp-composite-option-row">
								<th scope="row"><label for="lp_bundle_composite_spacing"><?php echo esc_html__( 'Composite spacing', 'lp-bundle-builder' ); ?></label></th>
								<td>
									<select id="lp_bundle_composite_spacing" name="bundle_composite_spacing">
										<option value="tight" <?php selected( $defaults['bundle_composite_spacing'], 'tight' ); ?>><?php echo esc_html__( 'Tight', 'lp-bundle-builder' ); ?></option>
										<option value="balanced" <?php selected( $defaults['bundle_composite_spacing'], 'balanced' ); ?>><?php echo esc_html__( 'Balanced', 'lp-bundle-builder' ); ?></option>
										<option value="airy" <?php selected( $defaults['bundle_composite_spacing'], 'airy' ); ?>><?php echo esc_html__( 'Airy', 'lp-bundle-builder' ); ?></option>
									</select>
								</td>
							</tr>
							<tr class="lp-composite-option-row">
								<th scope="row"><label for="lp_bundle_composite_background"><?php echo esc_html__( 'Composite background', 'lp-bundle-builder' ); ?></label></th>
								<td>
									<select id="lp_bundle_composite_background" name="bundle_composite_background">
										<option value="warm" <?php selected( $defaults['bundle_composite_background'], 'warm' ); ?>><?php echo esc_html__( 'Warm white', 'lp-bundle-builder' ); ?></option>
										<option value="white" <?php selected( $defaults['bundle_composite_background'], 'white' ); ?>><?php echo esc_html__( 'Pure white', 'lp-bundle-builder' ); ?></option>
										<option value="gray" <?php selected( $defaults['bundle_composite_background'], 'gray' ); ?>><?php echo esc_html__( 'Light gray', 'lp-bundle-builder' ); ?></option>
									</select>
								</td>
							</tr>
							<tr class="lp-composite-option-row">
								<th scope="row"><label for="lp_bundle_composite_canvas"><?php echo esc_html__( 'Composite canvas', 'lp-bundle-builder' ); ?></label></th>
								<td>
									<select id="lp_bundle_composite_canvas" name="bundle_composite_canvas">
										<option value="square" <?php selected( $defaults['bundle_composite_canvas'], 'square' ); ?>><?php echo esc_html__( 'Square', 'lp-bundle-builder' ); ?></option>
										<option value="landscape" <?php selected( $defaults['bundle_composite_canvas'], 'landscape' ); ?>><?php echo esc_html__( 'Landscape', 'lp-bundle-builder' ); ?></option>
										<option value="portrait" <?php selected( $defaults['bundle_composite_canvas'], 'portrait' ); ?>><?php echo esc_html__( 'Portrait', 'lp-bundle-builder' ); ?></option>
									</select>
								</td>
							</tr>
							<tr class="lp-composite-option-row">
								<th scope="row"><label for="lp_bundle_composite_box_style"><?php echo esc_html__( 'Composite box style', 'lp-bundle-builder' ); ?></label></th>
								<td>
									<select id="lp_bundle_composite_box_style" name="bundle_composite_box_style">
										<option value="none" <?php selected( $defaults['bundle_composite_box_style'], 'none' ); ?>><?php echo esc_html__( 'No boxes', 'lp-bundle-builder' ); ?></option>
										<option value="cards" <?php selected( $defaults['bundle_composite_box_style'], 'cards' ); ?>><?php echo esc_html__( 'Borderless cards', 'lp-bundle-builder' ); ?></option>
										<option value="border" <?php selected( $defaults['bundle_composite_box_style'], 'border' ); ?>><?php echo esc_html__( 'Thin border cards', 'lp-bundle-builder' ); ?></option>
									</select>
								</td>
							</tr>
							<tr class="lp-composite-option-row">
								<th scope="row"><label for="lp_bundle_composite_shadow"><?php echo esc_html__( 'Composite shadow', 'lp-bundle-builder' ); ?></label></th>
								<td>
									<select id="lp_bundle_composite_shadow" name="bundle_composite_shadow">
										<option value="none" <?php selected( $defaults['bundle_composite_shadow'], 'none' ); ?>><?php echo esc_html__( 'No shadow', 'lp-bundle-builder' ); ?></option>
										<option value="soft" <?php selected( $defaults['bundle_composite_shadow'], 'soft' ); ?>><?php echo esc_html__( 'Soft shadow', 'lp-bundle-builder' ); ?></option>
										<option value="strong" <?php selected( $defaults['bundle_composite_shadow'], 'strong' ); ?>><?php echo esc_html__( 'Strong shadow', 'lp-bundle-builder' ); ?></option>
									</select>
								</td>
							</tr>
							<tr class="lp-composite-option-row">
								<th scope="row"><label for="lp_bundle_composite_trim"><?php echo esc_html__( 'Composite whitespace', 'lp-bundle-builder' ); ?></label></th>
								<td>
									<select id="lp_bundle_composite_trim" name="bundle_composite_trim">
										<option value="auto" <?php selected( $defaults['bundle_composite_trim'], 'auto' ); ?>><?php echo esc_html__( 'Auto trim product whitespace', 'lp-bundle-builder' ); ?></option>
										<option value="none" <?php selected( $defaults['bundle_composite_trim'], 'none' ); ?>><?php echo esc_html__( 'Keep full product images', 'lp-bundle-builder' ); ?></option>
									</select>
								</td>
							</tr>
							<tr class="lp-composite-option-row">
								<th scope="row"><label for="lp_bundle_composite_primary_scale"><?php echo esc_html__( 'Main product size', 'lp-bundle-builder' ); ?></label></th>
								<td>
									<select id="lp_bundle_composite_primary_scale" name="bundle_composite_primary_scale">
										<option value="medium" <?php selected( $defaults['bundle_composite_primary_scale'], 'medium' ); ?>><?php echo esc_html__( 'Medium', 'lp-bundle-builder' ); ?></option>
										<option value="large" <?php selected( $defaults['bundle_composite_primary_scale'], 'large' ); ?>><?php echo esc_html__( 'Large', 'lp-bundle-builder' ); ?></option>
										<option value="xlarge" <?php selected( $defaults['bundle_composite_primary_scale'], 'xlarge' ); ?>><?php echo esc_html__( 'Extra large', 'lp-bundle-builder' ); ?></option>
									</select>
								</td>
							</tr>
							<tr class="lp-composite-option-row">
								<th scope="row"><label for="lp_bundle_composite_secondary_scale"><?php echo esc_html__( 'Other products size', 'lp-bundle-builder' ); ?></label></th>
								<td>
									<select id="lp_bundle_composite_secondary_scale" name="bundle_composite_secondary_scale">
										<option value="small" <?php selected( $defaults['bundle_composite_secondary_scale'], 'small' ); ?>><?php echo esc_html__( 'Small', 'lp-bundle-builder' ); ?></option>
										<option value="medium" <?php selected( $defaults['bundle_composite_secondary_scale'], 'medium' ); ?>><?php echo esc_html__( 'Medium', 'lp-bundle-builder' ); ?></option>
										<option value="large" <?php selected( $defaults['bundle_composite_secondary_scale'], 'large' ); ?>><?php echo esc_html__( 'Large', 'lp-bundle-builder' ); ?></option>
										<option value="xlarge" <?php selected( $defaults['bundle_composite_secondary_scale'], 'xlarge' ); ?>><?php echo esc_html__( 'Extra large', 'lp-bundle-builder' ); ?></option>
									</select>
								</td>
							</tr>
							<tr class="lp-composite-option-row">
								<th scope="row"><label for="lp_bundle_composite_secondary_position"><?php echo esc_html__( 'Other products position', 'lp-bundle-builder' ); ?></label></th>
								<td>
									<select id="lp_bundle_composite_secondary_position" name="bundle_composite_secondary_position">
										<option value="top_right" <?php selected( $defaults['bundle_composite_secondary_position'], 'top_right' ); ?>><?php echo esc_html__( 'Top right', 'lp-bundle-builder' ); ?></option>
										<option value="right" <?php selected( $defaults['bundle_composite_secondary_position'], 'right' ); ?>><?php echo esc_html__( 'Right', 'lp-bundle-builder' ); ?></option>
										<option value="bottom_right" <?php selected( $defaults['bundle_composite_secondary_position'], 'bottom_right' ); ?>><?php echo esc_html__( 'Bottom right', 'lp-bundle-builder' ); ?></option>
										<option value="top_left" <?php selected( $defaults['bundle_composite_secondary_position'], 'top_left' ); ?>><?php echo esc_html__( 'Top left', 'lp-bundle-builder' ); ?></option>
										<option value="left" <?php selected( $defaults['bundle_composite_secondary_position'], 'left' ); ?>><?php echo esc_html__( 'Left', 'lp-bundle-builder' ); ?></option>
										<option value="bottom_left" <?php selected( $defaults['bundle_composite_secondary_position'], 'bottom_left' ); ?>><?php echo esc_html__( 'Bottom left', 'lp-bundle-builder' ); ?></option>
									</select>
								</td>
							</tr>
							<tr class="lp-composite-option-row">
								<th scope="row"><label for="lp_bundle_composite_overlap"><?php echo esc_html__( 'Composite overlap', 'lp-bundle-builder' ); ?></label></th>
								<td>
									<select id="lp_bundle_composite_overlap" name="bundle_composite_overlap">
										<option value="none" <?php selected( $defaults['bundle_composite_overlap'], 'none' ); ?>><?php echo esc_html__( 'None', 'lp-bundle-builder' ); ?></option>
										<option value="subtle" <?php selected( $defaults['bundle_composite_overlap'], 'subtle' ); ?>><?php echo esc_html__( 'Subtle', 'lp-bundle-builder' ); ?></option>
										<option value="medium" <?php selected( $defaults['bundle_composite_overlap'], 'medium' ); ?>><?php echo esc_html__( 'Medium', 'lp-bundle-builder' ); ?></option>
										<option value="strong" <?php selected( $defaults['bundle_composite_overlap'], 'strong' ); ?>><?php echo esc_html__( 'Strong', 'lp-bundle-builder' ); ?></option>
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
					<div class="lp-variant-groups">
						<h2><?php echo esc_html__( 'Extra bundles from alternatives', 'lp-bundle-builder' ); ?></h2>
						<p><?php echo esc_html__( 'Load products from selected products, categories and tags, then mark matching alternatives with the same number. Unmarked lines stay standard in every extra bundle.', 'lp-bundle-builder' ); ?></p>
						<p class="lp-variant-actions">
							<button type="button" class="button button-secondary" id="lp_load_variant_candidates"><?php echo esc_html__( 'Load products from tags/options', 'lp-bundle-builder' ); ?></button>
							<span id="lp_variant_status" class="description"></span>
						</p>
						<div id="lp_variant_groups_container"></div>
					</div>
					<div class="lp-composite-preview" id="lp_composite_preview_wrap">
						<p class="lp-composite-preview-actions">
							<button type="button" class="button button-secondary" id="lp_open_manual_image_editor"><?php echo esc_html__( 'Open image editor', 'lp-bundle-builder' ); ?></button>
							<button type="button" class="button button-secondary" id="lp_add_manual_text" hidden><?php echo esc_html__( 'Add text', 'lp-bundle-builder' ); ?></button>
							<button type="button" class="button button-link-delete" id="lp_reset_manual_image_editor" hidden><?php echo esc_html__( 'Reset manual layout', 'lp-bundle-builder' ); ?></button>
						</p>
						<div id="lp_manual_image_editor" class="lp-manual-editor" hidden>
							<div class="lp-manual-canvas-wrap">
								<div id="lp_manual_image_canvas" class="lp-manual-canvas" aria-label="<?php echo esc_attr__( 'Manual bundle image editor', 'lp-bundle-builder' ); ?>"></div>
							</div>
							<div id="lp_manual_layer_controls" class="lp-manual-controls">
								<p class="description"><?php echo esc_html__( 'Select a product or text layer to adjust exact placement.', 'lp-bundle-builder' ); ?></p>
							</div>
						</div>
						<p class="lp-composite-preview-actions">
							<button type="button" class="button button-secondary" id="lp_preview_bundle_image"><?php echo esc_html__( 'Preview product image', 'lp-bundle-builder' ); ?></button>
							<span id="lp_preview_bundle_image_status" class="description"></span>
						</p>
						<div id="lp_composite_preview_panel" class="lp-composite-preview-panel" hidden>
							<img id="lp_composite_preview_image" alt="<?php echo esc_attr__( 'Bundle product image preview', 'lp-bundle-builder' ); ?>" />
						</div>
					</div>

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
				.lp-variant-groups {
					background: #fff;
					border: 1px solid #ccd0d4;
					padding: 14px;
					margin: 16px 0;
				}
				.lp-variant-actions { display: flex; flex-wrap: wrap; gap: 10px; align-items: center; }
				.lp-variant-part { margin-top: 14px; }
				.lp-variant-table { border-collapse: collapse; width: 100%; max-width: 920px; }
				.lp-variant-table th, .lp-variant-table td { border-bottom: 1px solid #f0f0f1; padding: 7px 8px; text-align: left; vertical-align: middle; }
				.lp-variant-table input[type="number"] { width: 80px; }
				.lp-variant-source { color: #646970; font-size: 12px; }
				.lp-composite-preview { margin: 14px 0 20px; }
				.lp-composite-preview-actions { display: flex; flex-wrap: wrap; gap: 10px; align-items: center; }
				.lp-composite-preview-panel {
					display: inline-block;
					max-width: 420px;
					background: #fff;
					border: 1px solid #ccd0d4;
					padding: 8px;
				}
				.lp-composite-preview-panel img {
					display: block;
					width: 100%;
					height: auto;
				}
				.lp-manual-editor {
					display: grid;
					grid-template-columns: minmax(320px, 640px) minmax(260px, 1fr);
					gap: 16px;
					align-items: start;
					background: #fff;
					border: 1px solid #ccd0d4;
					padding: 14px;
					margin: 12px 0;
				}
				.lp-manual-canvas-wrap { width: 100%; }
				.lp-manual-canvas {
					position: relative;
					width: 100%;
					aspect-ratio: 1 / 1;
					background: #fff;
					border: 1px solid #8c8f94;
					overflow: hidden;
					user-select: none;
					touch-action: none;
				}
				.lp-manual-layer {
					position: absolute;
					box-sizing: border-box;
					border: 1px dashed #2271b1;
					background: rgba(255, 255, 255, 0.72);
					cursor: move;
					overflow: hidden;
				}
				.lp-manual-layer.is-selected { border: 2px solid #2271b1; box-shadow: 0 0 0 1px #fff inset; }
				.lp-manual-layer img {
					display: block;
					width: 100%;
					height: 100%;
					object-fit: contain;
					pointer-events: none;
				}
				.lp-manual-layer-label {
					position: absolute;
					left: 4px;
					right: 4px;
					bottom: 4px;
					padding: 2px 4px;
					background: rgba(255, 255, 255, 0.86);
					font-size: 11px;
					white-space: nowrap;
					overflow: hidden;
					text-overflow: ellipsis;
					pointer-events: none;
				}
				.lp-manual-text-layer {
					padding: 4px 8px;
					background: transparent;
					border-color: #8a2be2;
					color: #111;
					white-space: pre-wrap;
					overflow: visible;
				}
				.lp-manual-resize {
					position: absolute;
					right: 0;
					bottom: 0;
					width: 14px;
					height: 14px;
					background: #2271b1;
					cursor: nwse-resize;
				}
				.lp-manual-controls {
					border-left: 1px solid #dcdcde;
					padding-left: 14px;
				}
				.lp-manual-controls label { display: block; font-weight: 600; margin: 10px 0 4px; }
				.lp-manual-control-grid {
					display: grid;
					grid-template-columns: repeat(2, minmax(90px, 1fr));
					gap: 8px;
				}
				.lp-manual-control-grid input,
				.lp-manual-controls input[type="text"],
				.lp-manual-controls input[type="number"],
				.lp-manual-controls textarea,
				.lp-manual-controls select { width: 100%; }
				@media (max-width: 900px) {
					.lp-manual-editor { grid-template-columns: 1fr; }
					.lp-manual-controls { border-left: 0; border-top: 1px solid #dcdcde; padding-left: 0; padding-top: 12px; }
				}
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
				const loadVariantCandidatesButton = document.getElementById('lp_load_variant_candidates');
				const variantStatus = document.getElementById('lp_variant_status');
				const variantGroupsContainer = document.getElementById('lp_variant_groups_container');
				const imageModeSelect = document.getElementById('lp_bundle_image_mode');
				const compositeOptionRows = Array.from(document.querySelectorAll('.lp-composite-option-row'));
				const compositePreviewWrap = document.getElementById('lp_composite_preview_wrap');
				const previewImageButton = document.getElementById('lp_preview_bundle_image');
				const previewImageStatus = document.getElementById('lp_preview_bundle_image_status');
				const previewImagePanel = document.getElementById('lp_composite_preview_panel');
				const previewImage = document.getElementById('lp_composite_preview_image');
				const manualSetupInput = document.getElementById('lp_bundle_composite_manual_setup');
				const openManualEditorButton = document.getElementById('lp_open_manual_image_editor');
				const addManualTextButton = document.getElementById('lp_add_manual_text');
				const resetManualEditorButton = document.getElementById('lp_reset_manual_image_editor');
				const manualEditor = document.getElementById('lp_manual_image_editor');
				const manualCanvas = document.getElementById('lp_manual_image_canvas');
				const manualLayerControls = document.getElementById('lp_manual_layer_controls');
				const imagePromptTextarea = document.getElementById('lp-image-prompt-textarea');
				const copyImagePromptButton = document.getElementById('lp-copy-image-prompt');
				const selectImagePromptButton = document.getElementById('lp-select-image-prompt');
				let parts = [];
				let manualSetup = { product_layers: {}, text_layers: [] };
				let selectedManualLayer = null;

				function clamp(value, min, max){
					value = Number(value);
					if (!Number.isFinite(value)) {
						value = min;
					}
					return Math.max(min, Math.min(max, value));
				}

				function normalizeLayer(layer, fallback){
					layer = layer || {};
					fallback = fallback || {};
					return {
						x: clamp(layer.x ?? fallback.x ?? 0.08, 0, 1),
						y: clamp(layer.y ?? fallback.y ?? 0.08, 0, 1),
						w: clamp(layer.w ?? fallback.w ?? 0.42, 0.03, 1),
						h: clamp(layer.h ?? fallback.h ?? 0.42, 0.03, 1),
						z: Math.round(clamp(layer.z ?? fallback.z ?? 0, -50, 150)),
						remove_background: !!(layer.remove_background === true || layer.remove_background === 'true' || layer.remove_background === 1 || layer.remove_background === '1'),
						background_tolerance: Math.round(clamp(layer.background_tolerance ?? fallback.background_tolerance ?? 12, 0, 100))
					};
				}

				function normalizeTextLayer(layer){
					layer = layer || {};
					return {
						text: String(layer.text || ''),
						x: clamp(layer.x ?? 0.08, 0, 1),
						y: clamp(layer.y ?? 0.08, 0, 1),
						font_size: Math.round(clamp(layer.font_size ?? 64, 10, 260)),
						color: /^#[0-9a-fA-F]{6}$/.test(String(layer.color || '')) ? String(layer.color) : '#111111',
						align: ['left', 'center', 'right'].includes(layer.align) ? layer.align : 'left',
						bold: !!(layer.bold === true || layer.bold === 'true' || layer.bold === 1 || layer.bold === '1'),
						z: Math.round(clamp(layer.z ?? 50, -50, 150))
					};
				}

				function syncManualSetupInput(){
					if (manualSetupInput) {
						manualSetupInput.value = JSON.stringify(manualSetup || { product_layers: {}, text_layers: [] });
					}
				}

				function getDefaultManualLayer(index, count){
					if (count <= 1) {
						return normalizeLayer({}, { x: 0.08, y: 0.08, w: 0.84, h: 0.84, z: index });
					}
					if (count === 2) {
						return index === 0
							? normalizeLayer({}, { x: 0.06, y: 0.26, w: 0.62, h: 0.62, z: 1 })
							: normalizeLayer({}, { x: 0.52, y: 0.10, w: 0.42, h: 0.42, z: 2 });
					}
					const presets = [
						{ x: 0.05, y: 0.30, w: 0.58, h: 0.58, z: 1 },
						{ x: 0.54, y: 0.08, w: 0.38, h: 0.38, z: 2 },
						{ x: 0.56, y: 0.56, w: 0.34, h: 0.34, z: 3 },
						{ x: 0.08, y: 0.06, w: 0.30, h: 0.30, z: 4 }
					];
					return normalizeLayer({}, presets[index] || { x: 0.12, y: 0.12, w: 0.36, h: 0.36, z: index + 1 });
				}

				function ensureManualSetup(){
					manualSetup = manualSetup && typeof manualSetup === 'object' ? manualSetup : {};
					manualSetup.product_layers = manualSetup.product_layers && typeof manualSetup.product_layers === 'object' ? manualSetup.product_layers : {};
					manualSetup.text_layers = Array.isArray(manualSetup.text_layers) ? manualSetup.text_layers : [];
					parts.forEach(function(part, index){
						const key = String(index);
						manualSetup.product_layers[key] = normalizeLayer(manualSetup.product_layers[key], getDefaultManualLayer(index, parts.length));
					});
					Object.keys(manualSetup.product_layers).forEach(function(key){
						if (Number(key) >= parts.length) {
							delete manualSetup.product_layers[key];
						}
					});
					manualSetup.text_layers = manualSetup.text_layers.map(normalizeTextLayer).filter(layer => layer.text.trim() !== '').slice(0, 8);
					syncManualSetupInput();
				}

				function setManualCanvasAspect(){
					if (!manualCanvas) {
						return;
					}
					const canvasControl = document.getElementById('lp_bundle_composite_canvas');
					const value = canvasControl ? canvasControl.value : 'square';
					manualCanvas.style.aspectRatio = value === 'landscape' ? '3 / 2' : (value === 'portrait' ? '2 / 3' : '1 / 1');
				}

				function setManualCanvasBackground(){
					if (!manualCanvas) {
						return;
					}
					const backgroundControl = document.getElementById('lp_bundle_composite_background');
					const value = backgroundControl ? backgroundControl.value : 'white';
					manualCanvas.style.background = value === 'gray' ? '#f1f2f3' : (value === 'warm' ? '#f7f7f4' : '#ffffff');
				}

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
						id: Number(item.value || item.id || item.product_id || item.term_id || 0),
						label: String(item.label || item.name || item.title || ''),
						slug: item.slug ? String(item.slug) : '',
						name: item.name ? String(item.name) : '',
						imageUrl: item.image_url || item.imageUrl ? String(item.image_url || item.imageUrl) : ''
					};
				}

				function emptyPart(){
					return { defaultProduct: null, products: [], categories: [], tags: [], discount: '', variantCandidates: [], variantGroups: {} };
				}

				function clearVariantCandidatesForPart(partIndex){
					if (!parts[partIndex]) {
						return;
					}
					parts[partIndex].variantCandidates = [];
					parts[partIndex].variantGroups = {};
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
										clearVariantCandidatesForPart(partIndex);
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
							clearVariantCandidatesForPart(partIndex);
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

				function setVariantStatus(message){
					if (variantStatus) {
						variantStatus.textContent = message || '';
					}
				}

				function renderVariantGroups(){
					if (!variantGroupsContainer) {
						return;
					}

					variantGroupsContainer.innerHTML = '';
					const hasCandidates = parts.some(part => Array.isArray(part.variantCandidates) && part.variantCandidates.length);
					if (!hasCandidates) {
						const p = document.createElement('p');
						p.className = 'description';
						p.textContent = '<?php echo esc_js( __( 'Load alternatives after selecting products, categories or tags.', 'lp-bundle-builder' ) ); ?>';
						variantGroupsContainer.appendChild(p);
						return;
					}

					parts.forEach(function(part, partIndex){
						const candidates = Array.isArray(part.variantCandidates) ? part.variantCandidates : [];
						if (!candidates.length) {
							return;
						}

						const section = document.createElement('div');
						section.className = 'lp-variant-part';
						const heading = document.createElement('h3');
						heading.textContent = '<?php echo esc_js( __( 'Del', 'lp-bundle-builder' ) ); ?> ' + (partIndex + 1);
						section.appendChild(heading);

						const table = document.createElement('table');
						table.className = 'lp-variant-table';
						const thead = document.createElement('thead');
						thead.innerHTML = '<tr><th><?php echo esc_js( __( 'Product', 'lp-bundle-builder' ) ); ?></th><th><?php echo esc_js( __( 'Source', 'lp-bundle-builder' ) ); ?></th><th><?php echo esc_js( __( 'Extra bundle #', 'lp-bundle-builder' ) ); ?></th></tr>';
						table.appendChild(thead);
						const tbody = document.createElement('tbody');

						candidates.forEach(function(candidate){
							const productId = Number(candidate.id || 0);
							if (!productId) {
								return;
							}

							const row = document.createElement('tr');
							const productCell = document.createElement('td');
							productCell.textContent = candidate.label || ('#' + productId);
							const sourceCell = document.createElement('td');
							sourceCell.className = 'lp-variant-source';
							sourceCell.textContent = candidate.is_default ? '<?php echo esc_js( __( 'Original default', 'lp-bundle-builder' ) ); ?>' : (candidate.source || '');
							const groupCell = document.createElement('td');

							const input = document.createElement('input');
							input.type = 'number';
							input.min = '1';
							input.max = '50';
							input.step = '1';
							input.placeholder = '-';
							input.value = candidate.is_default ? '' : String((part.variantGroups || {})[String(productId)] || '');
							input.disabled = !!candidate.is_default;
							input.addEventListener('input', function(){
								const group = Number(input.value || 0);
								if (!parts[partIndex].variantGroups) {
									parts[partIndex].variantGroups = {};
								}
								if (group > 0) {
									parts[partIndex].variantGroups[String(productId)] = Math.floor(group);
								} else {
									delete parts[partIndex].variantGroups[String(productId)];
								}
								partsInput.value = JSON.stringify(serializeParts());
							});

							groupCell.appendChild(input);
							row.appendChild(productCell);
							row.appendChild(sourceCell);
							row.appendChild(groupCell);
							tbody.appendChild(row);
						});

						table.appendChild(tbody);
						section.appendChild(table);
						variantGroupsContainer.appendChild(section);
					});
				}

				function serializeParts(){
					return parts.map(function(part){
						return {
							default_product: part.defaultProduct && part.defaultProduct.id ? Number(part.defaultProduct.id) : 0,
							products: (part.products || []).map(i => Number(i.id)).filter(Boolean),
							categories: (part.categories || []).map(i => Number(i.id)).filter(Boolean),
							tags: (part.tags || []).map(i => Number(i.id)).filter(Boolean),
							discount: String(part.discount || '').trim(),
							variant_groups: Object.keys(part.variantGroups || {}).reduce(function(groups, productId){
								const group = Number(part.variantGroups[productId] || 0);
								const normalizedProductId = Number(productId || 0);
								if (normalizedProductId > 0 && group > 0) {
									groups[String(normalizedProductId)] = group;
								}
								return groups;
							}, {})
						};
					});
				}

				function serializeImageOptions(){
					const layout = document.getElementById('lp_bundle_composite_layout');
					const spacing = document.getElementById('lp_bundle_composite_spacing');
					const background = document.getElementById('lp_bundle_composite_background');
					const canvas = document.getElementById('lp_bundle_composite_canvas');
					const boxStyle = document.getElementById('lp_bundle_composite_box_style');
					const shadow = document.getElementById('lp_bundle_composite_shadow');
					const trim = document.getElementById('lp_bundle_composite_trim');
					const primaryScale = document.getElementById('lp_bundle_composite_primary_scale');
					const secondaryScale = document.getElementById('lp_bundle_composite_secondary_scale');
					const secondaryPosition = document.getElementById('lp_bundle_composite_secondary_position');
					const overlap = document.getElementById('lp_bundle_composite_overlap');
					ensureManualSetup();
					return {
						bundle_composite_layout: layout ? layout.value : 'collage',
						bundle_composite_spacing: spacing ? spacing.value : 'tight',
						bundle_composite_background: background ? background.value : 'white',
						bundle_composite_canvas: canvas ? canvas.value : 'square',
						bundle_composite_box_style: boxStyle ? boxStyle.value : 'none',
						bundle_composite_shadow: shadow ? shadow.value : 'none',
						bundle_composite_trim: trim ? trim.value : 'auto',
						bundle_composite_primary_scale: primaryScale ? primaryScale.value : 'large',
						bundle_composite_secondary_scale: secondaryScale ? secondaryScale.value : 'medium',
						bundle_composite_secondary_position: secondaryPosition ? secondaryPosition.value : 'top_right',
						bundle_composite_overlap: overlap ? overlap.value : 'medium',
						bundle_composite_manual_setup: JSON.stringify(manualSetup || { product_layers: {}, text_layers: [] })
					};
				}

				function syncCompositeControls(){
					const enabled = !imageModeSelect || imageModeSelect.value === 'local_composite';
					compositeOptionRows.forEach(function(row){
						row.style.display = enabled ? '' : 'none';
					});
					if (compositePreviewWrap) {
						compositePreviewWrap.style.display = enabled ? '' : 'none';
					}
					if (!enabled && manualEditor) {
						manualEditor.hidden = true;
					}
				}

				function validatePreviewParts(){
					if (!parts.length) {
						return '<?php echo esc_js( __( 'Legg til minst én del før du forhåndsviser bildet.', 'lp-bundle-builder' ) ); ?>';
					}
					if (parts.some(part => !part.defaultProduct || !part.defaultProduct.id)) {
						return '<?php echo esc_js( __( 'Hver del må ha et standardprodukt før bildet kan forhåndsvises.', 'lp-bundle-builder' ) ); ?>';
					}
					return '';
				}

				function setPreviewStatus(message){
					if (previewImageStatus) {
						previewImageStatus.textContent = message || '';
					}
				}

				function clearPreview(){
					if (previewImage) {
						previewImage.removeAttribute('src');
					}
					if (previewImagePanel) {
						previewImagePanel.hidden = true;
					}
				}

				function selectManualLayer(type, index){
					selectedManualLayer = { type: type, index: index };
					renderManualEditor();
				}

				function updateManualLayer(type, index, patch){
					if (type === 'product') {
						const key = String(index);
						manualSetup.product_layers[key] = normalizeLayer(Object.assign({}, manualSetup.product_layers[key] || {}, patch));
					} else if (type === 'text' && manualSetup.text_layers[index]) {
						manualSetup.text_layers[index] = normalizeTextLayer(Object.assign({}, manualSetup.text_layers[index], patch));
					}
					syncManualSetupInput();
					renderManualEditor();
					clearPreview();
					setPreviewStatus('');
				}

				function buildManualNumberField(labelText, value, onChange){
					const wrap = document.createElement('div');
					const label = document.createElement('label');
					label.textContent = labelText;
					const input = document.createElement('input');
					input.type = 'number';
					input.step = '1';
					input.value = String(Math.round(Number(value) || 0));
					input.addEventListener('change', function(){
						onChange(Number(input.value || 0));
					});
					wrap.appendChild(label);
					wrap.appendChild(input);
					return wrap;
				}

				function renderManualControls(){
					if (!manualLayerControls) {
						return;
					}

					manualLayerControls.innerHTML = '';
					if (!selectedManualLayer) {
						const p = document.createElement('p');
						p.className = 'description';
						p.textContent = '<?php echo esc_js( __( 'Select a product or text layer to adjust exact placement.', 'lp-bundle-builder' ) ); ?>';
						manualLayerControls.appendChild(p);
						return;
					}

					const type = selectedManualLayer.type;
					const index = selectedManualLayer.index;
					const heading = document.createElement('h3');
					heading.textContent = type === 'product'
						? '<?php echo esc_js( __( 'Product layer', 'lp-bundle-builder' ) ); ?> ' + (index + 1)
						: '<?php echo esc_js( __( 'Text layer', 'lp-bundle-builder' ) ); ?> ' + (index + 1);
					manualLayerControls.appendChild(heading);

					const layer = type === 'product'
						? normalizeLayer(manualSetup.product_layers[String(index)], getDefaultManualLayer(index, parts.length))
						: normalizeTextLayer(manualSetup.text_layers[index] || {});

					const grid = document.createElement('div');
					grid.className = 'lp-manual-control-grid';
					grid.appendChild(buildManualNumberField('X %', layer.x * 100, value => updateManualLayer(type, index, { x: clamp(value / 100, 0, 1) })));
					grid.appendChild(buildManualNumberField('Y %', layer.y * 100, value => updateManualLayer(type, index, { y: clamp(value / 100, 0, 1) })));
					if (type === 'product') {
						grid.appendChild(buildManualNumberField('Width %', layer.w * 100, value => updateManualLayer(type, index, { w: clamp(value / 100, 0.03, 1) })));
						grid.appendChild(buildManualNumberField('Height %', layer.h * 100, value => updateManualLayer(type, index, { h: clamp(value / 100, 0.03, 1) })));
					}
					grid.appendChild(buildManualNumberField('Layer order', layer.z, value => updateManualLayer(type, index, { z: value })));
					manualLayerControls.appendChild(grid);

					if (type === 'product') {
						const bgLabel = document.createElement('label');
						const bgToggle = document.createElement('input');
						bgToggle.type = 'checkbox';
						bgToggle.checked = !!layer.remove_background;
						bgToggle.addEventListener('change', function(){
							updateManualLayer('product', index, { remove_background: bgToggle.checked });
						});
						bgLabel.appendChild(bgToggle);
						bgLabel.appendChild(document.createTextNode(' <?php echo esc_js( __( 'Remove white background', 'lp-bundle-builder' ) ); ?>'));
						manualLayerControls.appendChild(bgLabel);
						const tolerance = buildManualNumberField('<?php echo esc_js( __( 'Background tolerance', 'lp-bundle-builder' ) ); ?>', layer.background_tolerance, value => updateManualLayer('product', index, { background_tolerance: clamp(value, 0, 100) }));
						manualLayerControls.appendChild(tolerance);
					} else {
						const textLabel = document.createElement('label');
						textLabel.textContent = '<?php echo esc_js( __( 'Text', 'lp-bundle-builder' ) ); ?>';
						const textarea = document.createElement('textarea');
						textarea.rows = 3;
						textarea.value = layer.text;
						textarea.addEventListener('change', function(){
							updateManualLayer('text', index, { text: textarea.value });
						});
						manualLayerControls.appendChild(textLabel);
						manualLayerControls.appendChild(textarea);
						manualLayerControls.appendChild(buildManualNumberField('<?php echo esc_js( __( 'Font size', 'lp-bundle-builder' ) ); ?>', layer.font_size, value => updateManualLayer('text', index, { font_size: clamp(value, 10, 260) })));

						const colorLabel = document.createElement('label');
						colorLabel.textContent = '<?php echo esc_js( __( 'Color', 'lp-bundle-builder' ) ); ?>';
						const colorInput = document.createElement('input');
						colorInput.type = 'color';
						colorInput.value = layer.color;
						colorInput.addEventListener('change', function(){
							updateManualLayer('text', index, { color: colorInput.value });
						});
						manualLayerControls.appendChild(colorLabel);
						manualLayerControls.appendChild(colorInput);

						const alignLabel = document.createElement('label');
						alignLabel.textContent = '<?php echo esc_js( __( 'Align', 'lp-bundle-builder' ) ); ?>';
						const alignSelect = document.createElement('select');
						['left', 'center', 'right'].forEach(function(value){
							const option = document.createElement('option');
							option.value = value;
							option.textContent = value;
							option.selected = value === layer.align;
							alignSelect.appendChild(option);
						});
						alignSelect.addEventListener('change', function(){
							updateManualLayer('text', index, { align: alignSelect.value });
						});
						manualLayerControls.appendChild(alignLabel);
						manualLayerControls.appendChild(alignSelect);

						const boldLabel = document.createElement('label');
						const boldToggle = document.createElement('input');
						boldToggle.type = 'checkbox';
						boldToggle.checked = !!layer.bold;
						boldToggle.addEventListener('change', function(){
							updateManualLayer('text', index, { bold: boldToggle.checked });
						});
						boldLabel.appendChild(boldToggle);
						boldLabel.appendChild(document.createTextNode(' <?php echo esc_js( __( 'Bold', 'lp-bundle-builder' ) ); ?>'));
						manualLayerControls.appendChild(boldLabel);

						const removeButton = document.createElement('button');
						removeButton.type = 'button';
						removeButton.className = 'button button-link-delete';
						removeButton.textContent = '<?php echo esc_js( __( 'Remove text layer', 'lp-bundle-builder' ) ); ?>';
						removeButton.addEventListener('click', function(){
							manualSetup.text_layers.splice(index, 1);
							selectedManualLayer = null;
							syncManualSetupInput();
							renderManualEditor();
							clearPreview();
						});
						manualLayerControls.appendChild(removeButton);
					}
				}

				function beginManualDrag(event, type, index, mode){
					event.preventDefault();
					event.stopPropagation();
					const canvasRect = manualCanvas.getBoundingClientRect();
					const startX = event.clientX;
					const startY = event.clientY;
					const key = String(index);
					const startLayer = type === 'product'
						? normalizeLayer(manualSetup.product_layers[key], getDefaultManualLayer(index, parts.length))
						: normalizeTextLayer(manualSetup.text_layers[index] || {});

					function move(moveEvent){
						const dx = (moveEvent.clientX - startX) / canvasRect.width;
						const dy = (moveEvent.clientY - startY) / canvasRect.height;
						if (mode === 'resize' && type === 'product') {
							updateManualLayer(type, index, {
								w: clamp(startLayer.w + dx, 0.03, 1),
								h: clamp(startLayer.h + dy, 0.03, 1)
							});
						} else {
							updateManualLayer(type, index, {
								x: clamp(startLayer.x + dx, 0, 1),
								y: clamp(startLayer.y + dy, 0, 1)
							});
						}
					}

					function end(){
						document.removeEventListener('mousemove', move);
						document.removeEventListener('mouseup', end);
					}

					selectManualLayer(type, index);
					document.addEventListener('mousemove', move);
					document.addEventListener('mouseup', end);
				}

				function renderManualEditor(){
					if (!manualCanvas || !manualEditor || manualEditor.hidden) {
						syncManualSetupInput();
						return;
					}

					ensureManualSetup();
					setManualCanvasAspect();
					setManualCanvasBackground();
					manualCanvas.innerHTML = '';

					const productEntries = parts.map(function(part, index){
						const layer = normalizeLayer(manualSetup.product_layers[String(index)], getDefaultManualLayer(index, parts.length));
						return { type: 'product', index: index, layer: layer, part: part };
					});
					const textEntries = manualSetup.text_layers.map(function(layer, index){
						return { type: 'text', index: index, layer: normalizeTextLayer(layer) };
					});
					productEntries.concat(textEntries).sort(function(a, b){
						return (a.layer.z || 0) - (b.layer.z || 0);
					}).forEach(function(entry){
						const layer = entry.layer;
						const el = document.createElement('div');
						el.className = 'lp-manual-layer' + (entry.type === 'text' ? ' lp-manual-text-layer' : '');
						if (selectedManualLayer && selectedManualLayer.type === entry.type && Number(selectedManualLayer.index) === Number(entry.index)) {
							el.className += ' is-selected';
						}
						el.style.left = (layer.x * 100) + '%';
						el.style.top = (layer.y * 100) + '%';
						el.style.width = ((entry.type === 'text' ? 0.35 : layer.w) * 100) + '%';
						el.style.height = ((entry.type === 'text' ? Math.max(0.08, layer.font_size / 700) : layer.h) * 100) + '%';
						el.style.zIndex = String((layer.z || 0) + 100);
						el.addEventListener('mousedown', function(event){
							beginManualDrag(event, entry.type, entry.index, 'drag');
						});

						if (entry.type === 'product') {
							const product = entry.part && entry.part.defaultProduct ? entry.part.defaultProduct : null;
							if (product && product.imageUrl) {
								const img = document.createElement('img');
								img.src = product.imageUrl;
								img.alt = product.label || '';
								el.appendChild(img);
							}
							const label = document.createElement('span');
							label.className = 'lp-manual-layer-label';
							label.textContent = product && product.label ? product.label : ('<?php echo esc_js( __( 'Product line', 'lp-bundle-builder' ) ); ?> ' + (entry.index + 1));
							el.appendChild(label);
							const resize = document.createElement('span');
							resize.className = 'lp-manual-resize';
							resize.addEventListener('mousedown', function(event){
								beginManualDrag(event, 'product', entry.index, 'resize');
							});
							el.appendChild(resize);
						} else {
							el.textContent = layer.text;
							el.style.fontSize = Math.max(10, Math.round(layer.font_size * 0.4)) + 'px';
							el.style.color = layer.color;
							el.style.textAlign = layer.align;
							el.style.fontWeight = layer.bold ? '700' : '400';
						}

						el.addEventListener('click', function(event){
							event.stopPropagation();
							selectManualLayer(entry.type, entry.index);
						});
						manualCanvas.appendChild(el);
					});

					renderManualControls();
				}

				function render(){
					clearPreview();
					setPreviewStatus('');
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
					if (manualEditor && !manualEditor.hidden) {
						renderManualEditor();
					} else {
						ensureManualSetup();
					}
					renderVariantGroups();
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

				if (imageModeSelect) {
					imageModeSelect.addEventListener('change', function(){
						syncCompositeControls();
						clearPreview();
						setPreviewStatus('');
					});
				}
				document.querySelectorAll('.lp-composite-option-row select').forEach(function(control){
					control.addEventListener('change', function(){
						clearPreview();
						setPreviewStatus('');
						if (manualEditor && !manualEditor.hidden) {
							renderManualEditor();
						}
					});
				});
				syncCompositeControls();

				if (manualCanvas) {
					manualCanvas.addEventListener('click', function(){
						selectedManualLayer = null;
						renderManualEditor();
					});
				}

				if (openManualEditorButton && manualEditor) {
					openManualEditorButton.addEventListener('click', function(){
						const layout = document.getElementById('lp_bundle_composite_layout');
						if (layout) {
							layout.value = 'manual';
						}
						manualEditor.hidden = false;
						if (addManualTextButton) {
							addManualTextButton.hidden = false;
						}
						if (resetManualEditorButton) {
							resetManualEditorButton.hidden = false;
						}
						ensureManualSetup();
						renderManualEditor();
						clearPreview();
						setPreviewStatus('');
					});
				}

				if (addManualTextButton) {
					addManualTextButton.addEventListener('click', function(){
						const layout = document.getElementById('lp_bundle_composite_layout');
						if (layout) {
							layout.value = 'manual';
						}
						if (manualEditor) {
							manualEditor.hidden = false;
						}
						manualSetup.text_layers.push(normalizeTextLayer({
							text: '<?php echo esc_js( __( 'Bundle offer', 'lp-bundle-builder' ) ); ?>',
							x: 0.08,
							y: 0.08,
							font_size: 72,
							color: '#111111',
							align: 'left',
							bold: true,
							z: 80
						}));
						selectedManualLayer = { type: 'text', index: manualSetup.text_layers.length - 1 };
						ensureManualSetup();
						renderManualEditor();
						clearPreview();
					});
				}

				if (resetManualEditorButton) {
					resetManualEditorButton.addEventListener('click', function(){
						manualSetup = { product_layers: {}, text_layers: [] };
						selectedManualLayer = null;
						const layout = document.getElementById('lp_bundle_composite_layout');
						if (layout) {
							layout.value = 'collage';
						}
						if (manualEditor) {
							manualEditor.hidden = true;
						}
						if (addManualTextButton) {
							addManualTextButton.hidden = true;
						}
						if (resetManualEditorButton) {
							resetManualEditorButton.hidden = true;
						}
						syncManualSetupInput();
						clearPreview();
						setPreviewStatus('');
					});
				}

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

				if (loadVariantCandidatesButton) {
					loadVariantCandidatesButton.addEventListener('click', function(){
						if (!parts.length) {
							window.alert('<?php echo esc_js( __( 'Legg til minst én del først.', 'lp-bundle-builder' ) ); ?>');
							return;
						}

						const invalid = parts.some(part => !part.defaultProduct || !part.defaultProduct.id);
						if (invalid) {
							window.alert('<?php echo esc_js( __( 'Hver del må ha et standardprodukt før alternativer kan lastes.', 'lp-bundle-builder' ) ); ?>');
							return;
						}

						setVariantStatus('<?php echo esc_js( __( 'Loading products...', 'lp-bundle-builder' ) ); ?>');
						loadVariantCandidatesButton.disabled = true;

						const params = new URLSearchParams();
						params.append('action', 'lp_bundle_variant_candidates');
						params.append('nonce', ajaxNonce);
						params.append('parts_json', JSON.stringify(serializeParts()));

						fetch(ajaxUrl, {
							method: 'POST',
							headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'},
							body: params.toString()
						})
						.then(r => r.json())
						.then(response => {
							if (!response || !response.success || !response.data || !Array.isArray(response.data.parts)) {
								const message = response && response.data && response.data.message ? response.data.message : '<?php echo esc_js( __( 'Could not load alternative products.', 'lp-bundle-builder' ) ); ?>';
								setVariantStatus(message);
								return;
							}

							response.data.parts.forEach(function(partData, index){
								if (!parts[index]) {
									return;
								}
								const previousGroups = parts[index].variantGroups || {};
								const candidates = Array.isArray(partData.candidates) ? partData.candidates : [];
								const candidateIds = candidates.reduce(function(map, candidate){
									const id = Number(candidate.id || 0);
									if (id > 0) {
										map[String(id)] = true;
									}
									return map;
								}, {});
								parts[index].variantCandidates = candidates;
								parts[index].variantGroups = Object.keys(previousGroups).reduce(function(groups, productId){
									if (candidateIds[productId]) {
										groups[productId] = previousGroups[productId];
									}
									return groups;
								}, {});
							});

							renderVariantGroups();
							partsInput.value = JSON.stringify(serializeParts());
							setVariantStatus('<?php echo esc_js( __( 'Alternative products loaded.', 'lp-bundle-builder' ) ); ?>');
						})
						.catch(() => {
							setVariantStatus('<?php echo esc_js( __( 'Could not load alternative products.', 'lp-bundle-builder' ) ); ?>');
						})
						.finally(() => {
							loadVariantCandidatesButton.disabled = false;
						});
					});
				}

				if (previewImageButton) {
					previewImageButton.addEventListener('click', function(){
						const validationMessage = validatePreviewParts();
						if (validationMessage) {
							window.alert(validationMessage);
							return;
						}

						clearPreview();
						setPreviewStatus('<?php echo esc_js( __( 'Generating preview...', 'lp-bundle-builder' ) ); ?>');
						previewImageButton.disabled = true;

						const params = new URLSearchParams();
						params.append('action', 'lp_bundle_image_preview');
						params.append('nonce', ajaxNonce);
						params.append('parts_json', JSON.stringify(serializeParts()));
						const options = serializeImageOptions();
						Object.keys(options).forEach(function(key){
							params.append(key, options[key]);
						});

						fetch(ajaxUrl, {
							method: 'POST',
							headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'},
							body: params.toString()
						})
						.then(r => r.json())
						.then(response => {
							if (!response || !response.success || !response.data || !response.data.image_url) {
								const message = response && response.data && response.data.message ? response.data.message : '<?php echo esc_js( __( 'Could not generate preview.', 'lp-bundle-builder' ) ); ?>';
								setPreviewStatus(message);
								return;
							}
							previewImage.src = response.data.image_url;
							previewImagePanel.hidden = false;
							setPreviewStatus('<?php echo esc_js( __( 'Preview ready.', 'lp-bundle-builder' ) ); ?>');
						})
						.catch(() => {
							setPreviewStatus('<?php echo esc_js( __( 'Could not generate preview.', 'lp-bundle-builder' ) ); ?>');
						})
						.finally(() => {
							previewImageButton.disabled = false;
						});
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
					ensureManualSetup();
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

		public function ajax_bundle_image_preview() {
			if ( ! current_user_can( 'manage_woocommerce' ) ) {
				wp_send_json_error( array(), 403 );
			}

			check_ajax_referer( self::AJAX_NONCE_ACTION, 'nonce' );

			if ( ! class_exists( 'Imagick' ) || ! class_exists( 'ImagickPixel' ) ) {
				wp_send_json_error(
					array(
						'message' => __( 'Imagick er ikke tilgjengelig på serveren.', 'lp-bundle-builder' ),
					)
				);
			}

			$parts_json = isset( $_POST['parts_json'] ) ? wp_unslash( $_POST['parts_json'] ) : '';
			$parts_raw  = json_decode( $parts_json, true );
			if ( ! is_array( $parts_raw ) || empty( $parts_raw ) ) {
				wp_send_json_error(
					array(
						'message' => __( 'Legg til minst én del før du forhåndsviser bildet.', 'lp-bundle-builder' ),
					)
				);
			}

			$items = array();
			foreach ( $parts_raw as $part_raw ) {
				$sanitized_item = $this->build_bundle_item_from_part( is_array( $part_raw ) ? $part_raw : array() );
				if ( empty( $sanitized_item['product'] ) ) {
					wp_send_json_error(
						array(
							'message' => __( 'Hver del må ha et gyldig standardprodukt.', 'lp-bundle-builder' ),
						)
					);
				}
				$items[] = $sanitized_item;
			}

			$default_data = $this->build_default_products_data( $items );
			if ( empty( $default_data['is_valid'] ) || empty( $default_data['rows'] ) ) {
				wp_send_json_error(
					array(
						'message' => ! empty( $default_data['error'] ) ? $default_data['error'] : __( 'Kunne ikke hente produktbilder for forhåndsvisning.', 'lp-bundle-builder' ),
					)
				);
			}

			$image_binary = $this->build_bundle_composite_image_binary(
				$default_data['rows'],
				$this->sanitize_bundle_composite_options( wp_unslash( $_POST ) )
			);
			if ( '' === $image_binary ) {
				wp_send_json_error(
					array(
						'message' => __( 'Kunne ikke generere forhåndsvisning fra produktbildene.', 'lp-bundle-builder' ),
					)
				);
			}

			wp_send_json_success(
				array(
					'image_url' => 'data:image/jpeg;base64,' . base64_encode( $image_binary ),
				)
			);
		}

		public function ajax_bundle_variant_candidates() {
			if ( ! current_user_can( 'manage_woocommerce' ) ) {
				wp_send_json_error( array(), 403 );
			}

			check_ajax_referer( self::AJAX_NONCE_ACTION, 'nonce' );

			$parts_json = isset( $_POST['parts_json'] ) ? wp_unslash( $_POST['parts_json'] ) : '';
			$parts_raw  = json_decode( $parts_json, true );
			if ( ! is_array( $parts_raw ) || empty( $parts_raw ) ) {
				wp_send_json_error(
					array(
						'message' => __( 'Legg til minst én del først.', 'lp-bundle-builder' ),
					)
				);
			}

			$response_parts = array();
			foreach ( $parts_raw as $part_raw ) {
				$response_parts[] = array(
					'candidates' => $this->build_variant_candidates_from_part( is_array( $part_raw ) ? $part_raw : array() ),
				);
			}

			wp_send_json_success(
				array(
					'parts' => $response_parts,
				)
			);
		}

		private function get_items_with_fallback( $type, $search, $items, $mode ) {
			if ( in_array( $type, array( 'default_product', 'products' ), true ) ) {
				return $this->fallback_product_items( $search, $items, $mode );
			}

			$rest_items = $this->rest_items_request( $type, $search, $items, $mode );
			if ( ! empty( $rest_items ) ) {
				return $rest_items;
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
			$request_type = ( 'default_product' === $type ) ? 'products' : $type;

			if ( 'fetch' === $mode ) {
				$request = new \WP_REST_Request( 'POST', $route );
				$request->set_body_params(
					array(
						'type'  => $request_type,
						'items' => $items,
					)
				);
			} else {
				$request = new \WP_REST_Request( 'GET', $route );
				$request->set_query_params(
					array(
						'type'   => $request_type,
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
					'image_url'  => $this->get_best_product_image_url( $product ),
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

		private function build_variant_candidates_from_part( $part ) {
			$candidates = array();
			$default_id = isset( $part['default_product'] ) ? absint( $part['default_product'] ) : 0;

			if ( $default_id > 0 ) {
				$this->add_variant_candidate( $candidates, $default_id, __( 'Original default', 'lp-bundle-builder' ), true );
			}

			foreach ( $this->sanitize_unique_positive_int_array( isset( $part['products'] ) ? $part['products'] : array() ) as $product_id ) {
				$this->add_variant_candidate( $candidates, $product_id, __( 'Selected product', 'lp-bundle-builder' ), false );
			}

			$category_ids = $this->sanitize_unique_positive_int_array( isset( $part['categories'] ) ? $part['categories'] : array() );
			foreach ( $this->get_product_ids_for_taxonomy_terms( 'product_cat', $category_ids ) as $product_id ) {
				$this->add_variant_candidate( $candidates, $product_id, $this->build_term_source_label( 'product_cat', $category_ids ), false );
			}

			$tag_ids = $this->sanitize_unique_positive_int_array( isset( $part['tags'] ) ? $part['tags'] : array() );
			foreach ( $this->get_product_ids_for_taxonomy_terms( 'product_tag', $tag_ids ) as $product_id ) {
				$this->add_variant_candidate( $candidates, $product_id, $this->build_term_source_label( 'product_tag', $tag_ids ), false );
			}

			return array_values( $candidates );
		}

		private function add_variant_candidate( &$candidates, $product_id, $source, $is_default = false ) {
			$product_id = absint( $product_id );
			if ( $product_id <= 0 ) {
				return;
			}

			$product = wc_get_product( $product_id );
			if ( ! $this->is_searchable_bundle_product( $product ) ) {
				return;
			}

			if ( isset( $candidates[ $product_id ] ) ) {
				if ( ! empty( $is_default ) ) {
					$candidates[ $product_id ]['is_default'] = true;
					$candidates[ $product_id ]['source']     = __( 'Original default', 'lp-bundle-builder' );
				} elseif ( empty( $candidates[ $product_id ]['is_default'] ) && false === strpos( (string) $candidates[ $product_id ]['source'], (string) $source ) ) {
					$candidates[ $product_id ]['source'] .= ', ' . (string) $source;
				}
				return;
			}

			$candidates[ $product_id ] = array(
				'id'         => (int) $product_id,
				'label'      => $this->build_fallback_product_label( $product ),
				'source'     => (string) $source,
				'is_default' => (bool) $is_default,
			);
		}

		private function get_product_ids_for_taxonomy_terms( $taxonomy, $term_ids ) {
			$term_ids = $this->sanitize_unique_positive_int_array( $term_ids );
			if ( empty( $term_ids ) ) {
				return array();
			}

			$query = new \WP_Query(
				array(
					'post_type'      => 'product',
					'post_status'    => array( 'publish', 'private' ),
					'posts_per_page' => 200,
					'fields'         => 'ids',
					'orderby'        => 'title',
					'order'          => 'ASC',
					'no_found_rows'  => true,
					'tax_query'      => array(
						array(
							'taxonomy' => (string) $taxonomy,
							'field'    => 'term_id',
							'terms'    => $term_ids,
							'operator' => 'IN',
						),
					),
				)
			);

			return ! empty( $query->posts ) && is_array( $query->posts ) ? $this->sanitize_unique_positive_int_array( $query->posts ) : array();
		}

		private function build_term_source_label( $taxonomy, $term_ids ) {
			$term_ids = $this->sanitize_unique_positive_int_array( $term_ids );
			$names    = array();

			foreach ( $term_ids as $term_id ) {
				$term = get_term( $term_id, $taxonomy );
				if ( $term instanceof \WP_Term ) {
					$names[] = (string) $term->name;
				}
			}

			if ( empty( $names ) ) {
				return ( 'product_cat' === $taxonomy ) ? __( 'Category', 'lp-bundle-builder' ) : __( 'Tag', 'lp-bundle-builder' );
			}

			$prefix = ( 'product_cat' === $taxonomy ) ? __( 'Category', 'lp-bundle-builder' ) : __( 'Tag', 'lp-bundle-builder' );
			return $prefix . ': ' . implode( ', ', $names );
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
			$bundle_composite_options = $this->sanitize_bundle_composite_options( wp_unslash( $_POST ) );

			if ( '' === $title ) {
				$title = sprintf( __( 'Nytt bundle %s', 'lp-bundle-builder' ), current_time( 'Y-m-d H:i' ) );
			}

			$parts_json = isset( $_POST['parts_json'] ) ? wp_unslash( $_POST['parts_json'] ) : '';
			$parts_raw  = json_decode( $parts_json, true );
			if ( ! is_array( $parts_raw ) || empty( $parts_raw ) ) {
				$this->redirect_with_error( __( 'Du må legge til minst én del.', 'lp-bundle-builder' ) );
			}
			$primary_title = $this->build_primary_variant_bundle_title( $title, $parts_raw );

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

			$bundle = $this->create_bundle_product_object( $primary_title, $status );
			if ( is_wp_error( $bundle ) ) {
				$this->redirect_with_error( $bundle->get_error_message() );
			}
			$bundle_post_id = $bundle->get_id();

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
			$this->set_combined_bundle_categories( $bundle_post_id, $default_data['rows'] );

			$bundle_media_data = $this->get_bundle_media_data( $default_data['rows'] );
			$fallback_featured_image_id = isset( $bundle_media_data['fallback_featured_image_id'] ) ? absint( $bundle_media_data['fallback_featured_image_id'] ) : 0;
			$all_source_image_ids = isset( $bundle_media_data['all_source_image_ids'] ) ? $this->sanitize_unique_positive_int_array( $bundle_media_data['all_source_image_ids'] ) : array();
			$featured_image_id = $fallback_featured_image_id;

			if ( 'local_composite' === $bundle_image_mode ) {
				$composite_image_id = $this->generate_bundle_composite_image( $bundle_post_id, $default_data['rows'], $primary_title, $bundle_composite_options );
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
			update_post_meta( $bundle_post_id, '_lp_bundle_composite_options', wp_json_encode( $bundle_composite_options ) );
			update_post_meta( $bundle_post_id, '_lp_bundle_image_prompt', $image_prompt );
			update_post_meta( $bundle_post_id, '_lp_bundle_image_sources', wp_json_encode( $image_sources ) );

			$variant_created_ids = $this->create_variant_bundles_from_marked_parts(
				$parts_raw,
				array(
					'title'                    => $title,
					'status'                   => $status,
					'fixed_price'              => $fixed_price,
					'fixed_price_amount_raw'   => $fixed_price_amount_raw,
					'fixed_price_amount'       => $fixed_price_amount,
					'sync_stock_quantity'      => $sync_stock_quantity,
					'bundle_button_label'      => $bundle_button_label,
					'manage_stock'             => $manage_stock,
					'stock_status'             => $stock_status,
					'tax_status'               => $tax_status,
					'bundle_image_mode'        => $bundle_image_mode,
					'bundle_composite_options' => $bundle_composite_options,
				)
			);
			if ( ! empty( $variant_created_ids ) ) {
				update_post_meta( $bundle_post_id, '_lp_bundle_variant_ids', wp_json_encode( $variant_created_ids ) );
			}

			clean_post_cache( $bundle_post_id );

			$redirect_url = add_query_arg(
				array(
					'post_type'         => 'product',
					'page'              => self::MENU_SLUG,
					'lp_bundle_created' => 1,
					'lp_bundle_id'      => $bundle_post_id,
					'lp_bundle_variant_ids' => implode( ',', $variant_created_ids ),
				),
				admin_url( 'edit.php' )
			);

			wp_safe_redirect( $redirect_url );
			exit;
		}

		private function build_bundle_title_with_suffix( $title, $suffix ) {
			$title  = trim( (string) $title );
			$suffix = trim( (string) $suffix );

			if ( '' === $suffix ) {
				return $title;
			}

			if ( '' === $title ) {
				return $suffix;
			}

			return $title . ' - ' . $suffix;
		}

		private function build_primary_variant_bundle_title( $title, $parts_raw ) {
			if ( ! is_array( $parts_raw ) || empty( $parts_raw ) ) {
				return trim( (string) $title );
			}

			$title_product_names = array();
			foreach ( $parts_raw as $part_raw ) {
				$part_raw = is_array( $part_raw ) ? $part_raw : array();
				if ( empty( $this->sanitize_variant_groups_from_part( $part_raw ) ) ) {
					continue;
				}

				$default_product_id = isset( $part_raw['default_product'] ) ? absint( $part_raw['default_product'] ) : 0;
				if ( $default_product_id <= 0 ) {
					continue;
				}

				$product = wc_get_product( $default_product_id );
				if ( $product && is_a( $product, 'WC_Product' ) ) {
					$title_product_names[] = trim( (string) $product->get_name() );
				}
			}

			$title_product_names = array_values(
				array_unique(
					array_filter(
						$title_product_names,
						function( $name ) {
							return '' !== trim( (string) $name );
						}
					)
				)
			);

			return $this->build_bundle_title_with_suffix( $title, implode( ' + ', $title_product_names ) );
		}

		private function build_variant_bundle_part_configs( $parts_raw ) {
			if ( ! is_array( $parts_raw ) || empty( $parts_raw ) ) {
				return array();
			}

			$group_ids = array();
			foreach ( $parts_raw as $part_raw ) {
				$variant_groups = $this->sanitize_variant_groups_from_part( is_array( $part_raw ) ? $part_raw : array() );
				foreach ( $variant_groups as $group_id ) {
					$group_ids[] = (int) $group_id;
				}
			}
			$group_ids = array_values( array_unique( array_filter( $group_ids ) ) );
			sort( $group_ids, SORT_NUMERIC );

			if ( empty( $group_ids ) ) {
				return array();
			}

			$configs = array();
			foreach ( $group_ids as $group_id ) {
				$variant_parts = array();
				$title_product_names = array();

				foreach ( $parts_raw as $part_raw ) {
					$part_raw = is_array( $part_raw ) ? $part_raw : array();
					$variant_part = $part_raw;
					$variant_groups = $this->sanitize_variant_groups_from_part( $part_raw );
					$original_default_id = isset( $part_raw['default_product'] ) ? absint( $part_raw['default_product'] ) : 0;
					$replacement_ids = array();

					foreach ( $variant_groups as $product_id => $assigned_group_id ) {
						if ( (int) $assigned_group_id === (int) $group_id ) {
							$replacement_ids[] = (int) $product_id;
						}
					}

					$replacement_ids = $this->sanitize_unique_positive_int_array( $replacement_ids );
					if ( ! empty( $replacement_ids ) ) {
						$replacement_id = (int) $replacement_ids[0];
						$variant_part['default_product'] = $replacement_id;
						$variant_part['products'] = $this->sanitize_unique_positive_int_array(
							array_merge(
								isset( $variant_part['products'] ) ? (array) $variant_part['products'] : array(),
								array_filter( array( $original_default_id, $replacement_id ) )
							)
						);

						$product = wc_get_product( $replacement_id );
						if ( $product && is_a( $product, 'WC_Product' ) ) {
							$title_product_names[] = trim( (string) $product->get_name() );
						}
					}

					unset( $variant_part['variant_groups'] );
					$variant_parts[] = $variant_part;
				}

				$title_product_names = array_values(
					array_unique(
						array_filter(
							$title_product_names,
							function( $name ) {
								return '' !== trim( (string) $name );
							}
						)
					)
				);

				$configs[] = array(
					'group_id'      => (int) $group_id,
					'title_suffix'  => ! empty( $title_product_names ) ? implode( ' + ', $title_product_names ) : sprintf( __( 'Variant %d', 'lp-bundle-builder' ), (int) $group_id ),
					'parts'         => $variant_parts,
				);
			}

			return $configs;
		}

		private function sanitize_variant_groups_from_part( $part ) {
			$groups = array();
			if ( empty( $part['variant_groups'] ) || ! is_array( $part['variant_groups'] ) ) {
				return $groups;
			}

			foreach ( $part['variant_groups'] as $product_id => $group_id ) {
				$product_id = absint( $product_id );
				$group_id   = absint( $group_id );
				if ( $product_id <= 0 || $group_id <= 0 || $group_id > 50 ) {
					continue;
				}
				$groups[ $product_id ] = $group_id;
			}

			return $groups;
		}

		private function create_variant_bundles_from_marked_parts( $parts_raw, $settings ) {
			$variant_configs = $this->build_variant_bundle_part_configs( $parts_raw );
			if ( empty( $variant_configs ) ) {
				return array();
			}

			$created_ids = array();
			foreach ( $variant_configs as $variant_config ) {
				$variant_parts_raw = isset( $variant_config['parts'] ) && is_array( $variant_config['parts'] ) ? $variant_config['parts'] : array();
				if ( empty( $variant_parts_raw ) ) {
					continue;
				}

				$items = array();
				foreach ( $variant_parts_raw as $part_raw ) {
					$sanitized_item = $this->build_bundle_item_from_part( is_array( $part_raw ) ? $part_raw : array() );
					if ( empty( $sanitized_item['product'] ) ) {
						$items = array();
						break;
					}
					$items[] = $sanitized_item;
				}

				if ( empty( $items ) ) {
					continue;
				}

				$title = $this->build_bundle_title_with_suffix(
					isset( $settings['title'] ) ? (string) $settings['title'] : '',
					isset( $variant_config['title_suffix'] ) ? (string) $variant_config['title_suffix'] : ''
				);

				$created_id = $this->create_bundle_from_sanitized_items( $title, $items, $settings );
				if ( $created_id > 0 ) {
					$created_ids[] = $created_id;
				}
			}

			return $this->sanitize_unique_positive_int_array( $created_ids );
		}

		private function create_bundle_from_sanitized_items( $title, $items, $settings ) {
			$status = isset( $settings['status'] ) ? sanitize_key( $settings['status'] ) : 'draft';
			$status = in_array( $status, array( 'draft', 'publish' ), true ) ? $status : 'draft';

			$bundle = $this->create_bundle_product_object( $title, $status );
			if ( is_wp_error( $bundle ) ) {
				return 0;
			}
			$bundle_post_id = $bundle->get_id();

			$default_data = $this->build_default_products_data( $items );
			if ( empty( $default_data['is_valid'] ) || '' === $default_data['default_products_json'] ) {
				wp_delete_post( $bundle_post_id, true );
				return 0;
			}

			$fixed_price            = isset( $settings['fixed_price'] ) && 'true' === $settings['fixed_price'] ? 'true' : 'false';
			$fixed_price_amount_raw = isset( $settings['fixed_price_amount_raw'] ) ? (string) $settings['fixed_price_amount_raw'] : '';
			$fixed_price_amount     = isset( $settings['fixed_price_amount'] ) ? (string) $settings['fixed_price_amount'] : '';
			$default_products_total = isset( $default_data['default_products_total'] ) ? (float) $default_data['default_products_total'] : 0.0;
			$default_products_total = (float) wc_format_decimal( $default_products_total, wc_get_price_decimals() );

			if ( 'true' === $fixed_price ) {
				if ( '' === $fixed_price_amount_raw || '' === $fixed_price_amount || (float) $fixed_price_amount <= 0 || (float) $fixed_price_amount > $default_products_total ) {
					wp_delete_post( $bundle_post_id, true );
					return 0;
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
				'sync_stock_quantity'      => isset( $settings['sync_stock_quantity'] ) && 'true' === $settings['sync_stock_quantity'] ? 'true' : 'false',
				'bundle_button_label'      => isset( $settings['bundle_button_label'] ) ? (string) $settings['bundle_button_label'] : '',
			);

			$errors = $bundle->set_props( $props );
			if ( is_wp_error( $errors ) ) {
				wp_delete_post( $bundle_post_id, true );
				return 0;
			}

			$manage_stock = isset( $settings['manage_stock'] ) && 'yes' === $settings['manage_stock'] ? 'yes' : 'no';
			$stock_status = isset( $settings['stock_status'] ) ? sanitize_key( $settings['stock_status'] ) : 'instock';
			$stock_status = in_array( $stock_status, array( 'instock', 'outofstock', 'onbackorder' ), true ) ? $stock_status : 'instock';
			$tax_status   = isset( $settings['tax_status'] ) ? sanitize_key( $settings['tax_status'] ) : 'taxable';
			$tax_status   = in_array( $tax_status, array( 'taxable', 'shipping', 'none' ), true ) ? $tax_status : 'taxable';

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
			$this->set_combined_bundle_categories( $bundle_post_id, $default_data['rows'] );

			$bundle_image_mode        = isset( $settings['bundle_image_mode'] ) ? $this->sanitize_bundle_image_mode( $settings['bundle_image_mode'] ) : 'ai_prompt';
			$bundle_composite_options = isset( $settings['bundle_composite_options'] ) ? $this->sanitize_bundle_composite_options( $settings['bundle_composite_options'] ) : $this->sanitize_bundle_composite_options( array() );
			$bundle_media_data        = $this->get_bundle_media_data( $default_data['rows'] );
			$fallback_featured_image_id = isset( $bundle_media_data['fallback_featured_image_id'] ) ? absint( $bundle_media_data['fallback_featured_image_id'] ) : 0;
			$all_source_image_ids       = isset( $bundle_media_data['all_source_image_ids'] ) ? $this->sanitize_unique_positive_int_array( $bundle_media_data['all_source_image_ids'] ) : array();
			$featured_image_id          = $fallback_featured_image_id;

			if ( 'local_composite' === $bundle_image_mode ) {
				$composite_image_id = $this->generate_bundle_composite_image( $bundle_post_id, $default_data['rows'], $title, $bundle_composite_options );
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
			update_post_meta( $bundle_post_id, '_lp_bundle_composite_options', wp_json_encode( $bundle_composite_options ) );
			update_post_meta( $bundle_post_id, '_lp_bundle_image_prompt', $image_prompt );
			update_post_meta( $bundle_post_id, '_lp_bundle_image_sources', wp_json_encode( $image_sources ) );

			clean_post_cache( $bundle_post_id );
			return (int) $bundle_post_id;
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

		private function get_combined_bundle_category_ids( $default_products_rows ) {
			$category_ids = array();
			if ( ! is_array( $default_products_rows ) ) {
				return $category_ids;
			}

			foreach ( $default_products_rows as $row ) {
				$product_id = isset( $row['id'] ) ? absint( $row['id'] ) : 0;
				if ( $product_id <= 0 ) {
					continue;
				}

				$product = wc_get_product( $product_id );
				if ( $product && is_a( $product, 'WC_Product' ) && $product->is_type( 'variation' ) && (int) $product->get_parent_id() > 0 ) {
					$product_id = (int) $product->get_parent_id();
				}

				$product_category_ids = wp_get_object_terms(
					$product_id,
					'product_cat',
					array(
						'fields' => 'ids',
					)
				);

				if ( is_wp_error( $product_category_ids ) || empty( $product_category_ids ) || ! is_array( $product_category_ids ) ) {
					continue;
				}

				$category_ids = array_merge( $category_ids, $product_category_ids );
			}

			return $this->sanitize_unique_positive_int_array( $category_ids );
		}

		private function set_combined_bundle_categories( $bundle_post_id, $default_products_rows ) {
			$bundle_post_id = absint( $bundle_post_id );
			if ( $bundle_post_id <= 0 ) {
				return;
			}

			$category_ids = $this->get_combined_bundle_category_ids( $default_products_rows );
			if ( empty( $category_ids ) ) {
				return;
			}

			wp_set_object_terms( $bundle_post_id, $category_ids, 'product_cat', false );
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

		private function get_bundle_composite_source_image_paths( $default_products_rows ) {
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

			return array_values( $source_image_paths );
		}

		private function get_bundle_composite_palette( $background ) {
			$background = $this->sanitize_bundle_composite_background( $background );

			if ( 'white' === $background ) {
				return array(
					'canvas' => '#ffffff',
					'box'    => '#ffffff',
				);
			}

			if ( 'gray' === $background ) {
				return array(
					'canvas' => '#f1f2f3',
					'box'    => '#ffffff',
				);
			}

			return array(
				'canvas' => '#f7f7f4',
				'box'    => '#ffffff',
			);
		}

		private function get_bundle_composite_spacing_values( $spacing, $canvas_size ) {
			$spacing     = $this->sanitize_bundle_composite_spacing( $spacing );
			$canvas_size = max( 600, (int) $canvas_size );

			if ( 'airy' === $spacing ) {
				return array(
					'outer_pad' => (int) round( $canvas_size * 0.055 ),
					'gap'       => (int) round( $canvas_size * 0.03 ),
				);
			}

			if ( 'balanced' === $spacing ) {
				return array(
					'outer_pad' => (int) round( $canvas_size * 0.045 ),
					'gap'       => (int) round( $canvas_size * 0.02 ),
				);
			}

			return array(
				'outer_pad' => (int) round( $canvas_size * 0.035 ),
				'gap'       => (int) round( $canvas_size * 0.012 ),
			);
		}

		private function get_bundle_composite_canvas_dimensions( $canvas ) {
			$canvas = $this->sanitize_bundle_composite_canvas( $canvas );

			if ( 'landscape' === $canvas ) {
				return array( 'width' => 1800, 'height' => 1200 );
			}

			if ( 'portrait' === $canvas ) {
				return array( 'width' => 1200, 'height' => 1800 );
			}

			return array( 'width' => 1600, 'height' => 1600 );
		}

		private function get_bundle_composite_scale_ratio( $scale, $is_primary = false ) {
			$scale = $this->sanitize_bundle_composite_scale( $scale, $is_primary ? 'large' : 'medium' );
			$primary_ratios = array(
				'small'  => 0.55,
				'medium' => 0.66,
				'large'  => 0.76,
				'xlarge' => 0.88,
			);
			$secondary_ratios = array(
				'small'  => 0.30,
				'medium' => 0.42,
				'large'  => 0.53,
				'xlarge' => 0.64,
			);
			$ratios = $is_primary ? $primary_ratios : $secondary_ratios;

			return isset( $ratios[ $scale ] ) ? (float) $ratios[ $scale ] : ( $is_primary ? 0.76 : 0.42 );
		}

		private function get_bundle_composite_overlap_ratio( $overlap ) {
			$overlap = $this->sanitize_bundle_composite_overlap( $overlap );
			$ratios = array(
				'none'   => 0,
				'subtle' => 0.08,
				'medium' => 0.15,
				'strong' => 0.23,
			);

			return isset( $ratios[ $overlap ] ) ? (float) $ratios[ $overlap ] : 0.15;
		}

		private function normalize_bundle_composite_slot( $slot, $canvas_width, $canvas_height ) {
			$slot = wp_parse_args(
				(array) $slot,
				array(
					'x' => 0,
					'y' => 0,
					'w' => 1,
					'h' => 1,
				)
			);

			$slot['w'] = max( 1, min( (int) round( $slot['w'] ), (int) $canvas_width ) );
			$slot['h'] = max( 1, min( (int) round( $slot['h'] ), (int) $canvas_height ) );
			$slot['x'] = max( 0, min( (int) round( $slot['x'] ), (int) $canvas_width - $slot['w'] ) );
			$slot['y'] = max( 0, min( (int) round( $slot['y'] ), (int) $canvas_height - $slot['h'] ) );

			return $slot;
		}

		private function get_bundle_composite_positioned_slot( $position, $slot_w, $slot_h, $pad, $inner_w, $inner_h, $offset = 0 ) {
			$position = $this->sanitize_bundle_composite_secondary_position( $position );
			$offset   = max( 0, (int) $offset );
			$left     = $pad + $offset;
			$right    = $pad + $inner_w - $slot_w - $offset;
			$top      = $pad + $offset;
			$bottom   = $pad + $inner_h - $slot_h - $offset;
			$middle_y = $pad + (int) round( ( $inner_h - $slot_h ) / 2 );

			if ( 'bottom_right' === $position ) {
				return array( 'x' => $right, 'y' => $bottom, 'w' => $slot_w, 'h' => $slot_h );
			}

			if ( 'bottom_left' === $position ) {
				return array( 'x' => $left, 'y' => $bottom, 'w' => $slot_w, 'h' => $slot_h );
			}

			if ( 'top_left' === $position ) {
				return array( 'x' => $left, 'y' => $top, 'w' => $slot_w, 'h' => $slot_h );
			}

			if ( 'right' === $position ) {
				return array( 'x' => $right, 'y' => $middle_y, 'w' => $slot_w, 'h' => $slot_h );
			}

			if ( 'left' === $position ) {
				return array( 'x' => $left, 'y' => $middle_y, 'w' => $slot_w, 'h' => $slot_h );
			}

			return array( 'x' => $right, 'y' => $top, 'w' => $slot_w, 'h' => $slot_h );
		}

		private function add_bundle_composite_shadow( $canvas, $image, $x, $y, $shadow ) {
			$shadow = $this->sanitize_bundle_composite_shadow( $shadow );
			if ( 'none' === $shadow ) {
				return;
			}

			$opacity = 'strong' === $shadow ? 28 : 18;
			$sigma   = 'strong' === $shadow ? 14 : 9;
			$offset  = 'strong' === $shadow ? 18 : 10;

			try {
				$shadow_image = clone $image;
				$shadow_image->setImageBackgroundColor( new \ImagickPixel( 'transparent' ) );
				$shadow_image->shadowImage( $opacity, $sigma, $offset, $offset );
				$canvas->compositeImage( $shadow_image, \Imagick::COMPOSITE_OVER, (int) $x, (int) $y );
				$shadow_image->clear();
				$shadow_image->destroy();
			} catch ( \Exception $exception ) {
				return;
			}
		}

		private function remove_bundle_composite_white_background( $image, $tolerance ) {
			if ( ! $image || ! method_exists( $image, 'transparentPaintImage' ) ) {
				return;
			}

			$tolerance = max( 0, min( 100, absint( $tolerance ) ) );
			if ( $tolerance <= 0 ) {
				return;
			}

			try {
				if ( defined( 'Imagick::ALPHACHANNEL_ACTIVATE' ) ) {
					$image->setImageAlphaChannel( \Imagick::ALPHACHANNEL_ACTIVATE );
				}

				$quantum = \Imagick::getQuantumRange();
				$range   = is_array( $quantum ) && isset( $quantum['quantumRangeLong'] ) ? (float) $quantum['quantumRangeLong'] : 65535;
				$fuzz    = ( $tolerance / 100 ) * $range;
				$image->transparentPaintImage( new \ImagickPixel( '#ffffff' ), 0, $fuzz, false );
			} catch ( \Exception $exception ) {
				return;
			}
		}

		private function get_bundle_manual_product_slots( $count, $canvas_width, $canvas_height, $options ) {
			$options = $this->sanitize_bundle_composite_options( (array) $options );
			$manual_setup = isset( $options['manual_setup'] ) && is_array( $options['manual_setup'] ) ? $options['manual_setup'] : array();
			$product_layers = isset( $manual_setup['product_layers'] ) && is_array( $manual_setup['product_layers'] ) ? $manual_setup['product_layers'] : array();
			$fallback_options = $options;
			$fallback_options['layout'] = 'collage';
			$fallback_slots = $this->get_bundle_composite_slots( $count, $canvas_width, $canvas_height, $fallback_options );
			$slots = array();

			for ( $index = 0; $index < $count; $index++ ) {
				$key = (string) $index;
				if ( isset( $product_layers[ $key ] ) && is_array( $product_layers[ $key ] ) ) {
					$layer = $product_layers[ $key ];
					$slot = array(
						'x' => (float) $layer['x'] * $canvas_width,
						'y' => (float) $layer['y'] * $canvas_height,
						'w' => (float) $layer['w'] * $canvas_width,
						'h' => (float) $layer['h'] * $canvas_height,
					);
				} else {
					$slot = isset( $fallback_slots[ $index ] ) ? $fallback_slots[ $index ] : array( 'x' => 0, 'y' => 0, 'w' => $canvas_width, 'h' => $canvas_height );
					$layer = array();
				}

				$slot = $this->normalize_bundle_composite_slot( $slot, $canvas_width, $canvas_height );
				$slot['index'] = $index;
				$slot['z'] = isset( $layer['z'] ) ? (int) $layer['z'] : $index;
				$slot['remove_background'] = isset( $layer['remove_background'] ) && 'true' === (string) $layer['remove_background'];
				$slot['background_tolerance'] = isset( $layer['background_tolerance'] ) ? absint( $layer['background_tolerance'] ) : 12;
				$slots[] = $slot;
			}

			usort(
				$slots,
				function( $a, $b ) {
					if ( (int) $a['z'] === (int) $b['z'] ) {
						return (int) $a['index'] - (int) $b['index'];
					}
					return (int) $a['z'] - (int) $b['z'];
				}
			);

			return $slots;
		}

		private function draw_bundle_composite_text_layers( $canvas, $options, $canvas_width, $canvas_height ) {
			$manual_setup = isset( $options['manual_setup'] ) && is_array( $options['manual_setup'] ) ? $options['manual_setup'] : array();
			$text_layers = isset( $manual_setup['text_layers'] ) && is_array( $manual_setup['text_layers'] ) ? $manual_setup['text_layers'] : array();
			if ( empty( $text_layers ) || ! class_exists( 'ImagickDraw' ) ) {
				return;
			}

			usort(
				$text_layers,
				function( $a, $b ) {
					return ( isset( $a['z'] ) ? (int) $a['z'] : 0 ) - ( isset( $b['z'] ) ? (int) $b['z'] : 0 );
				}
			);

			foreach ( $text_layers as $layer ) {
				$text = isset( $layer['text'] ) ? trim( (string) $layer['text'] ) : '';
				if ( '' === $text ) {
					continue;
				}

				try {
					$draw = new \ImagickDraw();
					$draw->setFillColor( new \ImagickPixel( isset( $layer['color'] ) ? (string) $layer['color'] : '#111111' ) );
					$font_size = isset( $layer['font_size'] ) ? absint( $layer['font_size'] ) : 64;
					$draw->setFontSize( max( 10, min( 260, $font_size ) ) );
					if ( isset( $layer['bold'] ) && 'true' === (string) $layer['bold'] && method_exists( $draw, 'setFontWeight' ) ) {
						$draw->setFontWeight( 700 );
					}

					$align = isset( $layer['align'] ) ? (string) $layer['align'] : 'left';
					if ( 'center' === $align && defined( 'Imagick::ALIGN_CENTER' ) ) {
						$draw->setTextAlignment( \Imagick::ALIGN_CENTER );
					} elseif ( 'right' === $align && defined( 'Imagick::ALIGN_RIGHT' ) ) {
						$draw->setTextAlignment( \Imagick::ALIGN_RIGHT );
					} elseif ( defined( 'Imagick::ALIGN_LEFT' ) ) {
						$draw->setTextAlignment( \Imagick::ALIGN_LEFT );
					}

					$x = $this->sanitize_normalized_float( isset( $layer['x'] ) ? $layer['x'] : 0.08, 0.08 ) * $canvas_width;
					$y = ( $this->sanitize_normalized_float( isset( $layer['y'] ) ? $layer['y'] : 0.08, 0.08 ) * $canvas_height ) + max( 10, min( 260, $font_size ) );
					$canvas->annotateImage( $draw, (int) round( $x ), (int) round( $y ), 0, $text );
					$draw->clear();
					$draw->destroy();
				} catch ( \Exception $exception ) {
					continue;
				}
			}
		}

		private function build_bundle_composite_image_binary( $default_products_rows, $options = array() ) {
			if ( ! class_exists( 'Imagick' ) || ! class_exists( 'ImagickPixel' ) ) {
				return '';
			}

			$options            = $this->sanitize_bundle_composite_options( (array) $options );
			$source_image_paths = $this->get_bundle_composite_source_image_paths( $default_products_rows );
			if ( empty( $source_image_paths ) ) {
				return '';
			}

			$dimensions    = $this->get_bundle_composite_canvas_dimensions( $options['canvas'] );
			$canvas_width  = (int) $dimensions['width'];
			$canvas_height = (int) $dimensions['height'];
			$slots         = 'manual' === $options['layout']
				? $this->get_bundle_manual_product_slots( count( $source_image_paths ), $canvas_width, $canvas_height, $options )
				: $this->get_bundle_composite_slots( count( $source_image_paths ), $canvas_width, $canvas_height, $options );
			if ( empty( $slots ) ) {
				return '';
			}

			try {
				$palette = $this->get_bundle_composite_palette( $options['background'] );
				$canvas = new \Imagick();
				$canvas->newImage( $canvas_width, $canvas_height, new \ImagickPixel( $palette['canvas'] ) );
				$canvas->setImageFormat( 'jpeg' );
				$canvas->setImageColorspace( \Imagick::COLORSPACE_SRGB );

				foreach ( $slots as $slot_index => $slot ) {
					$image_index = isset( $slot['index'] ) ? (int) $slot['index'] : (int) $slot_index;
					if ( ! isset( $source_image_paths[ $image_index ] ) ) {
						continue;
					}

					if ( 'none' !== $options['box_style'] ) {
						$border_size = 'border' === $options['box_style'] ? 2 : 0;
						$box_w       = max( 1, (int) $slot['w'] - ( $border_size * 2 ) );
						$box_h       = max( 1, (int) $slot['h'] - ( $border_size * 2 ) );
						$box         = new \Imagick();
						$box->newImage( $box_w, $box_h, new \ImagickPixel( $palette['box'] ) );
						$box->setImageFormat( 'png' );
						if ( $border_size > 0 ) {
							$box->borderImage( new \ImagickPixel( '#dedede' ), $border_size, $border_size );
						}
						$canvas->compositeImage( $box, \Imagick::COMPOSITE_OVER, (int) $slot['x'], (int) $slot['y'] );
						$box->clear();
						$box->destroy();
					}

					$image = new \Imagick();
					$image->readImage( $source_image_paths[ $image_index ] );
					$image->setImageColorspace( \Imagick::COLORSPACE_SRGB );
					$image->setImageBackgroundColor( new \ImagickPixel( 'transparent' ) );
					$image = $image->mergeImageLayers( \Imagick::LAYERMETHOD_MERGE );
					if ( ! empty( $slot['remove_background'] ) ) {
						$this->remove_bundle_composite_white_background( $image, isset( $slot['background_tolerance'] ) ? $slot['background_tolerance'] : 12 );
					}
					if ( 'auto' === $options['trim'] ) {
						$image->trimImage( 4000 );
						$image->setImagePage( 0, 0, 0, 0 );
					}
					$image->thumbnailImage( (int) $slot['w'], (int) $slot['h'], true, true );

					$x = (int) $slot['x'] + (int) floor( ( (int) $slot['w'] - $image->getImageWidth() ) / 2 );
					$y = (int) $slot['y'] + (int) floor( ( (int) $slot['h'] - $image->getImageHeight() ) / 2 );
					$this->add_bundle_composite_shadow( $canvas, $image, $x, $y, $options['shadow'] );
					$canvas->compositeImage( $image, \Imagick::COMPOSITE_OVER, $x, $y );
					$image->clear();
					$image->destroy();
				}

				if ( 'manual' === $options['layout'] ) {
					$this->draw_bundle_composite_text_layers( $canvas, $options, $canvas_width, $canvas_height );
				}

				$canvas->setImageCompressionQuality( 90 );
				$image_binary = $canvas->getImagesBlob();
				$canvas->clear();
				$canvas->destroy();
			} catch ( \Exception $exception ) {
				return '';
			}

			return is_string( $image_binary ) ? $image_binary : '';
		}

		private function generate_bundle_composite_image( $bundle_post_id, $default_products_rows, $bundle_name = '', $options = array() ) {
			$image_binary = $this->build_bundle_composite_image_binary( $default_products_rows, $options );
			if ( '' === $image_binary ) {
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

		private function get_bundle_composite_slots( $count, $canvas_width, $canvas_height = null, $options = array() ) {
			$count = max( 1, min( 4, (int) $count ) );
			$canvas_width  = max( 600, (int) $canvas_width );
			$canvas_height = null === $canvas_height ? $canvas_width : max( 600, (int) $canvas_height );
			$options       = $this->sanitize_bundle_composite_options( (array) $options );
			$spacing       = $this->get_bundle_composite_spacing_values( $options['spacing'], max( $canvas_width, $canvas_height ) );
			$pad           = (int) $spacing['outer_pad'];
			$gap           = (int) $spacing['gap'];
			$inner_w       = $canvas_width - ( $pad * 2 );
			$inner_h       = $canvas_height - ( $pad * 2 );

			if ( 1 === $count ) {
				return array( array( 'x' => $pad, 'y' => $pad, 'w' => $inner_w, 'h' => $inner_h ) );
			}

			if ( 'row' === $options['layout'] ) {
				$cell_w = (int) floor( ( $inner_w - ( $gap * ( $count - 1 ) ) ) / $count );
				$slots  = array();
				for ( $index = 0; $index < $count; $index++ ) {
					$slots[] = array(
						'x' => $pad + ( $index * ( $cell_w + $gap ) ),
						'y' => $pad,
						'w' => $cell_w,
						'h' => $inner_h,
					);
				}
				return $slots;
			}

			if ( 'featured' === $options['layout'] && $count > 1 ) {
				$featured_w = (int) floor( $inner_w * 0.62 );
				$side_w     = $inner_w - $featured_w - $gap;
				$side_count = $count - 1;
				$side_h     = (int) floor( ( $inner_h - ( $gap * ( $side_count - 1 ) ) ) / $side_count );
				$slots      = array(
					array( 'x' => $pad, 'y' => $pad, 'w' => $featured_w, 'h' => $inner_h ),
				);
				for ( $index = 0; $index < $side_count; $index++ ) {
					$slots[] = array(
						'x' => $pad + $featured_w + $gap,
						'y' => $pad + ( $index * ( $side_h + $gap ) ),
						'w' => $side_w,
						'h' => $side_h,
					);
				}
				return $slots;
			}

			if ( in_array( $options['layout'], array( 'collage', 'diagonal', 'hero' ), true ) ) {
				$primary_ratio   = $this->get_bundle_composite_scale_ratio( $options['primary_scale'], true );
				$secondary_ratio = $this->get_bundle_composite_scale_ratio( $options['secondary_scale'], false );
				$overlap_px      = (int) round( min( $inner_w, $inner_h ) * $this->get_bundle_composite_overlap_ratio( $options['overlap'] ) );
				$primary_w       = (int) round( $inner_w * $primary_ratio );
				$primary_h       = (int) round( $inner_h * $primary_ratio );
				$secondary_w     = (int) round( $inner_w * $secondary_ratio );
				$secondary_h     = (int) round( $inner_h * $secondary_ratio );

				if ( 'diagonal' === $options['layout'] ) {
					$primary = array(
						'x' => $pad + ( $inner_w * 0.03 ),
						'y' => $pad + ( $inner_h - $primary_h ) * 0.64,
						'w' => $primary_w,
						'h' => $primary_h,
					);
				} elseif ( 'hero' === $options['layout'] ) {
					$primary = array(
						'x' => $pad + ( $inner_w - $primary_w ) * 0.36,
						'y' => $pad + ( $inner_h - $primary_h ) * 0.54,
						'w' => $primary_w,
						'h' => $primary_h,
					);
				} else {
					$primary = array(
						'x' => $pad + ( $inner_w * 0.06 ),
						'y' => $pad + ( $inner_h - $primary_h ) * 0.66,
						'w' => $primary_w,
						'h' => $primary_h,
					);
				}

				$slots = array( $this->normalize_bundle_composite_slot( $primary, $canvas_width, $canvas_height ) );
				$secondary = $this->get_bundle_composite_positioned_slot( $options['secondary_position'], $secondary_w, $secondary_h, $pad, $inner_w, $inner_h, $gap );

				if ( $overlap_px > 0 && in_array( $options['secondary_position'], array( 'top_right', 'right', 'bottom_right' ), true ) ) {
					$secondary['x'] = $slots[0]['x'] + $slots[0]['w'] - $overlap_px;
				} elseif ( $overlap_px > 0 && in_array( $options['secondary_position'], array( 'top_left', 'left', 'bottom_left' ), true ) ) {
					$secondary['x'] = $slots[0]['x'] - $secondary_w + $overlap_px;
				}

				if ( $overlap_px > 0 && in_array( $options['secondary_position'], array( 'top_right', 'top_left' ), true ) ) {
					$secondary['y'] = $slots[0]['y'] - (int) round( $secondary_h * 0.18 );
				} elseif ( $overlap_px > 0 && in_array( $options['secondary_position'], array( 'bottom_right', 'bottom_left' ), true ) ) {
					$secondary['y'] = $slots[0]['y'] + $slots[0]['h'] - $overlap_px;
				}

				$slots[] = $this->normalize_bundle_composite_slot( $secondary, $canvas_width, $canvas_height );

				if ( $count > 2 ) {
					$extra_positions = array( 'bottom_right', 'top_left', 'bottom_left' );
					$extra_w         = (int) round( $secondary_w * 0.76 );
					$extra_h         = (int) round( $secondary_h * 0.76 );
					for ( $index = 2; $index < $count; $index++ ) {
						$position = isset( $extra_positions[ $index - 2 ] ) ? $extra_positions[ $index - 2 ] : 'bottom_right';
						$slot     = $this->get_bundle_composite_positioned_slot( $position, $extra_w, $extra_h, $pad, $inner_w, $inner_h, $gap * ( $index - 1 ) );
						$slots[]  = $this->normalize_bundle_composite_slot( $slot, $canvas_width, $canvas_height );
					}
				}

				return array_slice( $slots, 0, $count );
			}

			if ( 2 === $count ) {
				$w = (int) floor( ( $inner_w - $gap ) / 2 );
				return array(
					array( 'x' => $pad, 'y' => $pad, 'w' => $w, 'h' => $inner_h ),
					array( 'x' => $pad + $w + $gap, 'y' => $pad, 'w' => $w, 'h' => $inner_h ),
				);
			}

			if ( 3 === $count ) {
				$half_w = (int) floor( ( $inner_w - $gap ) / 2 );
				$half_h = (int) floor( ( $inner_h - $gap ) / 2 );
				return array(
					array( 'x' => $pad, 'y' => $pad, 'w' => $half_w, 'h' => $inner_h ),
					array( 'x' => $pad + $half_w + $gap, 'y' => $pad, 'w' => $half_w, 'h' => $half_h ),
					array( 'x' => $pad + $half_w + $gap, 'y' => $pad + $half_h + $gap, 'w' => $half_w, 'h' => $half_h ),
				);
			}

			$cell_w = (int) floor( ( $inner_w - $gap ) / 2 );
			$cell_h = (int) floor( ( $inner_h - $gap ) / 2 );
			return array(
				array( 'x' => $pad, 'y' => $pad, 'w' => $cell_w, 'h' => $cell_h ),
				array( 'x' => $pad + $cell_w + $gap, 'y' => $pad, 'w' => $cell_w, 'h' => $cell_h ),
				array( 'x' => $pad, 'y' => $pad + $cell_h + $gap, 'w' => $cell_w, 'h' => $cell_h ),
				array( 'x' => $pad + $cell_w + $gap, 'y' => $pad + $cell_h + $gap, 'w' => $cell_w, 'h' => $cell_h ),
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
