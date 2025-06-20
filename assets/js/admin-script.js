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
                    
                    // Show specific message for payment duplicates
                    if (msg.includes('Payment already exists')) {
                        alert('Payment Duplicate: This invoice has already been marked as paid in RepairShopr.');
                    } else {
                        alert('Error: ' + msg + '\nPlease check the error logs for more details.');
                    }
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

    // Handler for RS Verify Invoice button (legacy orders page)
    $(document).on('click', '.woo_inv_to_rs-verify-invoice', function(e) {
        console.log('woo_inv_to_rs: CLICK HANDLER TRIGGERED for .woo_inv_to_rs-verify-invoice');
        e.preventDefault();
        e.stopPropagation();

        var button = $(this);
        var orderId = button.data('order-id');

        console.log('woo_inv_to_rs: RS Verify Invoice button clicked for order ID:', orderId);

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
                console.log('woo_inv_to_rs: Sending AJAX request for invoice verification');
            },
            success: function(response) {
                console.log('woo_inv_to_rs: AJAX response received (invoice verification):', response);
                if (response.success) {
                    // Remove previous color classes and inline styles
                    button.removeClass('button-primary button-secondary')
                          .css('background-color', '')
                          .css('color', '');
                    if (response.data && response.data.match) {
                        button.text('RS Invoice Matched')
                              .css('background-color', 'green')
                              .css('color', 'white');
                    } else {
                        button.text('RS Invoice Mismatch')
                              .css('background-color', 'red')
                              .css('color', 'white');
                    }
                } else {
                    button.text('Invoice Verify Failed')
                          .removeClass('button-primary button-secondary')
                          .css('background-color', 'red')
                          .css('color', 'white');
                    var msg = response.data && response.data.message ? response.data.message : 'Unknown error';
                    console.error('woo_inv_to_rs: Error message (invoice verification):', msg);
                    alert('Error: ' + msg + '\nPlease check the error logs for more details.');
                }
            },
            error: function(jqXHR, textStatus, errorThrown) {
                console.error('woo_inv_to_rs: AJAX error (invoice verification):', textStatus, errorThrown);
                console.log('woo_inv_to_rs: Full error object (invoice verification):', jqXHR);
                button.text('Invoice Verify Failed')
                      .removeClass('button-primary button-secondary')
                      .css('background-color', 'red')
                      .css('color', 'white');
                alert('An error occurred while verifying the invoice. Please check the error logs for more details.');
            },
            complete: function() {
                button.prop('disabled', false);
            }
        });

        return false;
    });

    // Handler for RS Verify Payment button (legacy orders page)
    $(document).on('click', '.woo_inv_to_rs-verify-payment', function(e) {
        console.log('woo_inv_to_rs: CLICK HANDLER TRIGGERED for .woo_inv_to_rs-verify-payment');
        e.preventDefault();
        e.stopPropagation();

        var button = $(this);
        var orderId = button.data('order-id');

        console.log('woo_inv_to_rs: RS Verify Payment button clicked for order ID:', orderId);

        button.prop('disabled', true).text('Verifying...');

        $.ajax({
            url: woo_inv_to_rs_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'woo_inv_to_rs_verify_payment',
                order_id: orderId,
                nonce: woo_inv_to_rs_ajax.nonce
            },
            beforeSend: function() {
                console.log('woo_inv_to_rs: Sending AJAX request for payment verification');
            },
            success: function(response) {
                console.log('woo_inv_to_rs: AJAX response received (payment verification):', response);
                if (response.success) {
                    // Remove previous color classes and inline styles
                    button.removeClass('button-primary button-secondary')
                          .css('background-color', '')
                          .css('color', '');
                    if (response.data && response.data.paid) {
                        button.text('RS Payment Matched')
                              .css('background-color', 'green')
                              .css('color', 'white');
                    } else {
                        button.text('RS Payment Unpaid')
                              .css('background-color', 'red')
                              .css('color', 'white');
                    }
                } else {
                    button.text('Payment Verify Failed')
                          .removeClass('button-primary button-secondary')
                          .css('background-color', 'red')
                          .css('color', 'white');
                    var msg = response.data && response.data.message ? response.data.message : 'Unknown error';
                    console.error('woo_inv_to_rs: Error message (payment verification):', msg);
                    alert('Error: ' + msg + '\nPlease check the error logs for more details.');
                }
            },
            error: function(jqXHR, textStatus, errorThrown) {
                console.error('woo_inv_to_rs: AJAX error (payment verification):', textStatus, errorThrown);
                console.log('woo_inv_to_rs: Full error object (payment verification):', jqXHR);
                button.text('Payment Verify Failed')
                      .removeClass('button-primary button-secondary')
                      .css('background-color', 'red')
                      .css('color', 'white');
                alert('An error occurred while verifying the payment. Please check the error logs for more details.');
            },
            complete: function() {
                button.prop('disabled', false);
            }
        });

        return false;
    });
});
