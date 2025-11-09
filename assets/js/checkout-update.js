// assets/js/checkout-update.js
jQuery(function($){
    // Try to update the checkout section when a coupon is added or removed or the cart is changed
    $(document.body).on('applied_coupon removed_coupon updated_cart_totals', function(){
        // trigger built-in WooCommerce update
        try {
            $(document.body).trigger('update_checkout');
        } catch (e) {
            // silent
            console && console.log && console.log('DGBC: update_checkout trigger failed', e);
        }
    });
});
