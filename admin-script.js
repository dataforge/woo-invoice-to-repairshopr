console.log('woo_inv_to_rs: ADMIN-SCRIPT.JS IS EXECUTING (GLOBAL)');
console.log('woo_inv_to_rs: WooCommerce Invoice to RepairShopr script loaded');

jQuery(document).ready(function($) {
    console.log('woo_inv_to_rs: jQuery ready function executed');
    
    $(document).on('click', '.woo_inv_to_rs-send-to-repairshopr', function(e) {
        console.log('woo_inv_to_rs: Button clicked');
        e.preventDefault();
        e.stopPropagation();

        var button = $(this);
        var orderId = button.data('order-id');

        console.log('woo_inv_to_rs: Button clicked for order ID:', orderId);

        button.prop('disabled', true).text('Sending...');

        $.ajax({
            url: woo_inv_to_rs_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'woo_inv_to_rs_send_to_repairshopr',
                order_id: orderId,
                nonce: woo_inv_to_rs_ajax.nonce
            },
            beforeSend: function() {
                console.log('woo_inv_to_rs: Sending AJAX request');
            },
            success: function(response) {
                console.log('woo_inv_to_rs: AJAX response received:', response);
                if (response.success) {
                    button.text('Sent!').addClass('button-primary');
                } else {
                    button.text('Error').addClass('button-secondary');
                    console.error('woo_inv_to_rs: Error message:', response.data.message);
                    alert('Error: ' + response.data.message + '\nPlease check the error logs for more details.');
                }
            },
            error: function(jqXHR, textStatus, errorThrown) {
                console.error('woo_inv_to_rs: AJAX error:', textStatus, errorThrown);
                console.log('woo_inv_to_rs: Full error object:', jqXHR);
                button.text('Error').addClass('button-secondary');
                alert('An error occurred while sending the invoice. Please check the error logs for more details.');
            },
            complete: function() {
                button.prop('disabled', false);
            }
        });

        return false;
    });

    // Handler for Send Payment button (legacy orders page)
    $(document).on('click', '.woo_inv_to_rs-send-payment', function(e) {
        console.log('woo_inv_to_rs: CLICK HANDLER TRIGGERED for .woo_inv_to_rs-send-payment');
        alert('Send Payment JS handler triggered');
        e.preventDefault();
        e.stopPropagation();

        var button = $(this);
        var orderId = button.data('order-id');

        console.log('woo_inv_to_rs: Send Payment button clicked for order ID:', orderId);

        button.prop('disabled', true).text('Sending...');

        $.ajax({
            url: woo_inv_to_rs_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'woo_inv_to_rs_send_payment_to_repairshopr',
                order_id: orderId,
                nonce: woo_inv_to_rs_ajax.nonce
            },
            beforeSend: function() {
                console.log('woo_inv_to_rs: Sending AJAX request for payment');
            },
            success: function(response) {
                console.log('woo_inv_to_rs: AJAX response received (payment):', response);
                if (response.success) {
                    button.text('Sent!').addClass('button-primary');
                } else {
                    button.text('Error').addClass('button-secondary');
                    var msg = response.data && response.data.message ? response.data.message : 'Unknown error';
                    console.error('woo_inv_to_rs: Error message (payment):', msg);
                    alert('Error: ' + msg + '\nPlease check the error logs for more details.');
                }
            },
            error: function(jqXHR, textStatus, errorThrown) {
                console.error('woo_inv_to_rs: AJAX error (payment):', textStatus, errorThrown);
                console.log('woo_inv_to_rs: Full error object (payment):', jqXHR);
                button.text('Error').addClass('button-secondary');
                alert('An error occurred while sending the payment. Please check the error logs for more details.');
            },
            complete: function() {
                button.prop('disabled', false);
            }
        });

        return false;
    });

    // Handler for $ Verify button (legacy orders page)
    $(document).on('click', '.woo_inv_to_rs-verify-invoice', function(e) {
        console.log('woo_inv_to_rs: CLICK HANDLER TRIGGERED for .woo_inv_to_rs-verify-invoice');
        e.preventDefault();
        e.stopPropagation();

        var button = $(this);
        var orderId = button.data('order-id');

        console.log('woo_inv_to_rs: $ Verify button clicked for order ID:', orderId);

        button.prop('disabled', true).text('Verifying...');

        $.ajax({
            url: woo_inv_to_rs_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'woo_inv_to_rs_verify_invoice',
                order_id: orderId,
                nonce: woo_inv_to_rs_ajax.nonce
            },
            beforeSend: function() {
                console.log('woo_inv_to_rs: Sending AJAX request for verification');
            },
success: function(response) {
                console.log('woo_inv_to_rs: AJAX response received (verification):', response);
                if (response.success) {
                    // Remove previous color classes and inline styles
                    button.removeClass('button-primary button-secondary')
                          .css('background-color', '')
                          .css('color', '');
                    if (response.data && response.data.match) {
                        button.text('$ Match')
                              .css('background-color', 'green')
                              .css('color', 'white');
                    } else {
                        button.text('$ Mismatch')
                              .css('background-color', 'red')
                              .css('color', 'white');
                    }
                } else {
                    button.text('Error').addClass('button-secondary');
                    var msg = response.data && response.data.message ? response.data.message : 'Unknown error';
                    console.error('woo_inv_to_rs: Error message (verification):', msg);
                    alert('Error: ' + msg + '\nPlease check the error logs for more details.');
                }
            },
            error: function(jqXHR, textStatus, errorThrown) {
                console.error('woo_inv_to_rs: AJAX error (verification):', textStatus, errorThrown);
                console.log('woo_inv_to_rs: Full error object (verification):', jqXHR);
                button.text('Error').addClass('button-secondary');
                alert('An error occurred while verifying the invoice. Please check the error logs for more details.');
            },
            complete: function() {
                button.prop('disabled', false);
            }
        });

        return false;
    });
});
