<?php
/**
 * Plugin Name: Disable Payment Gateway by Coupon
 * Plugin URI:  https://ildrm.com/
 * Description: Allow selecting payment gateway(s) in coupon edit screen which will be disabled for customers who apply that coupon.
 * Version:     1.0
 * Author:      Shahin Ilderemi
 * Text Domain: disable-gateway-by-coupon
 * Domain Path: /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WC_Disable_Gateway_By_Coupon {

    const META_KEY = '_disabled_gateways_for_coupon';
    const NONCE_ACTION = 'disable_gateways_for_coupon_nonce';
    const NONCE_NAME = 'disable_gateways_for_coupon_nonce_field';

    public function __construct() {
        // Admin UI: add field on coupon edit
        add_action( 'woocommerce_coupon_options', array( $this, 'coupon_gateway_field' ) );

        // Admin save
        add_action( 'woocommerce_coupon_options_save', array( $this, 'save_coupon_gateway_field' ), 10, 2 );

        // Frontend: filter available gateways
        add_filter( 'woocommerce_available_payment_gateways', array( $this, 'maybe_disable_gateways' ) );

        // Enqueue JS on checkout to ensure payment methods update after coupon changes
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_scripts' ) );

        // load textdomain
        add_action( 'plugins_loaded', array( $this, 'load_textdomain' ) );
    }

    /**
     * Load plugin textdomain for translations
     */
    public function load_textdomain() {
        load_plugin_textdomain( 'disable-gateway-by-coupon', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
    }

	/**
	* Show checkboxes for all active payment gateways in coupon admin UI
	*
	* @param mixed $post_or_id  Could be WC_Coupon object, WP_Post or coupon post ID (int)
	* @param mixed $maybe_coupon_obj Optional second param sometimes provided by WC (WC_Coupon)
	*/
	public function coupon_gateway_field( $post_or_id, $maybe_coupon_obj = null ) {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		// Normalize coupon id
		$coupon_id = 0;
		if ( is_object( $maybe_coupon_obj ) && ( $maybe_coupon_obj instanceof WC_Coupon ) ) {
			$coupon_id = $maybe_coupon_obj->get_id();
		} elseif ( is_object( $post_or_id ) && property_exists( $post_or_id, 'ID' ) ) {
			$coupon_id = absint( $post_or_id->ID );
		} elseif ( is_numeric( $post_or_id ) ) {
			$coupon_id = absint( $post_or_id );
		}

		// Add nonce field
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME );

		// Get gateways
		$payment_gateways = array();
		if ( class_exists( 'WC_Payment_Gateways' ) ) {
			$payment_gateways = WC()->payment_gateways()->payment_gateways();
		}

		$selected = array();
		if ( $coupon_id ) {
			$meta = get_post_meta( $coupon_id, self::META_KEY, true );
			if ( is_array( $meta ) ) {
				$selected = $meta;
			}
		}

		echo '<div class="options_group">';
		echo '<p class="form-field"><label>' . esc_html__( 'Disabling payment gateways when applying this coupon', 'disable-gateway-by-coupon' ) . '</label></p>';

		if ( empty( $payment_gateways ) ) {
			echo '<p>' . esc_html__( 'No payment gateway found or WooCommerce is not fully loaded.', 'disable-gateway-by-coupon' ) . '</p>';
		} else {
			echo '<div class="dgbc-gateway-list">';
			foreach ( $payment_gateways as $id => $gateway ) {
				// Use woocommerce helper to render checkbox properly
				$args = array(
					'id'      => 'dgbc_' . esc_attr( $id ),
					'label'   => $gateway->get_title() . ' (' . esc_html( $id ) . ')',
					'desc'    => '',
					'value'   => in_array( $id, $selected, true ) ? $id : '',
					'name'    => 'disabled_gateways_for_coupon[]',
					'cbvalue' => $id,
				);

				// Render checkbox using WC helper if exists, otherwise fallback to manual HTML
				if ( function_exists( 'woocommerce_wp_checkbox' ) ) {
					woocommerce_wp_checkbox( $args );
				} else {
					// fallback
					$checked = in_array( $id, $selected, true ) ? 'checked' : '';
					printf(
						'<p><label><input type="checkbox" name="%s" value="%s" %s /> %s</label></p>',
						esc_attr( $args['name'] ),
						esc_attr( $args['cbvalue'] ),
						$checked,
						esc_html( $gateway->get_title() . ' (' . $id . ')' )
					);
				}
			}
			echo '</div>';
		}

		echo '<p class="description">' . esc_html__( 'If these options are selected, customers who enter this coupon will no longer see these payment methods.', 'disable-gateway-by-coupon' ) . '</p>';
		echo '</div>';
	}


    /**
     * Save selected gateways to coupon meta
     *
     * @param int $post_id
     * @param WC_Coupon $coupon
     */
    public function save_coupon_gateway_field( $post_id, $coupon ) {
        // capability check
        if ( ! current_user_can( 'manage_woocommerce', $post_id ) ) {
            return;
        }

        // nonce check
        if ( ! isset( $_POST[ self::NONCE_NAME ] ) || ! wp_verify_nonce( wp_unslash( $_POST[ self::NONCE_NAME ] ), self::NONCE_ACTION ) ) {
            return;
        }

        $values = isset( $_POST['disabled_gateways_for_coupon'] ) ? (array) wp_unslash( $_POST['disabled_gateways_for_coupon'] ) : array();

        // sanitize and keep only known gateway IDs
        if ( class_exists( 'WC_Payment_Gateways' ) ) {
            $all_gateways = array_keys( WC()->payment_gateways()->payment_gateways() );
        } else {
            $all_gateways = array();
        }

        $clean = array();
        foreach ( $values as $v ) {
            $v = sanitize_text_field( $v );
            if ( in_array( $v, $all_gateways, true ) ) {
                $clean[] = $v;
            }
        }

        // allow other plugins to filter what gets saved
        $clean = apply_filters( 'disable_gateway_by_coupon_save_gateways', $clean, $post_id );

        update_post_meta( $post_id, self::META_KEY, $clean );
    }

    /**
     * Remove gateways if any applied coupons require them to be disabled
     *
     * @param array $available_gateways
     * @return array
     */
    public function maybe_disable_gateways( $available_gateways ) {
        // Only run on frontend (not admin), but allow AJAX checkout updates
        if ( is_admin() && ! wp_doing_ajax() ) {
            return $available_gateways;
        }

        if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
            return $available_gateways;
        }

        $applied = WC()->cart->get_applied_coupons();
        if ( empty( $applied ) ) {
            return $available_gateways;
        }

        $to_disable = array();

        foreach ( $applied as $coupon_code ) {
            $coupon_obj = new WC_Coupon( $coupon_code );
            if ( ! $coupon_obj || ! $coupon_obj->get_id() ) {
                continue;
            }

            // Basic validation: check expiration date and usage limits to skip invalid/expired coupons
            // If plugin versions differ, this is a defensive/basic check.
            $date_expires = $coupon_obj->get_date_expires();
            if ( $date_expires && $date_expires->getTimestamp() < time() ) {
                // expired — skip
                continue;
            }

            $usage_limit = $coupon_obj->get_usage_limit();
            $usage_count = $coupon_obj->get_usage_count();
            if ( $usage_limit > 0 && $usage_count >= $usage_limit ) {
                // limit reached — skip
                continue;
            }

            // Optionally other validations can be added here.

            $disabled = get_post_meta( $coupon_obj->get_id(), self::META_KEY, true );
            if ( is_array( $disabled ) ) {
                $to_disable = array_merge( $to_disable, $disabled );
            }
        }

        $to_disable = array_unique( $to_disable );

        // Allow filters to modify final list
        $to_disable = apply_filters( 'disable_gateway_by_coupon_disabled_gateways', $to_disable );

        // Unset disabled gateways
        foreach ( $to_disable as $gid ) {
            if ( isset( $available_gateways[ $gid ] ) ) {
                unset( $available_gateways[ $gid ] );
            }
        }

        // If no gateways left, add a user-facing notice (but avoid duplicate notice)
        if ( empty( $available_gateways ) ) {
            $notice = __( 'No payment methods are available with this coupon applied. Please remove the coupon or contact support.', 'disable-gateway-by-coupon' );
            if ( function_exists( 'wc_add_notice' ) && ! wc_has_notice( $notice, 'error' ) ) {
                wc_add_notice( $notice, 'error' );
            }
        }

        return $available_gateways;
    }

    /**
     * Enqueue frontend JS to ensure checkout updates payment methods after coupon changes
     */
    public function enqueue_scripts() {
        if ( ! is_checkout() ) {
            return;
        }

        $handle = 'dgbc-checkout-update';
        $src = plugin_dir_url( __FILE__ ) . 'assets/js/checkout-update.js';
        wp_enqueue_script( $handle, $src, array( 'jquery' ), '0.3', true );

        // localize some text if needed in future
        wp_localize_script( $handle, 'DGBC', array(
            'notice_nonce' => wp_create_nonce( 'dgbc-notice' ),
        ) );
    }
}

new WC_Disable_Gateway_By_Coupon();
