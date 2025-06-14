/**
 * Injects a "RepairShopr" column with a "Send to RepairShopr" button into the new WooCommerce Admin (wc-orders) page.
 * Requires WooCommerce Admin (React-based) to be active.
 */
(function waitForWooReactContext() {
    var interval = setInterval(function() {
        if (
            typeof window.wp !== 'undefined' &&
            typeof (window.wc || window.wcSettings || {}) !== 'undefined' &&
            window.wp.data &&
            window.wp.plugins &&
            window.wp.element
        ) {
            clearInterval(interval);
            var wp = window.wp;
            var wc = window.wc || window.wcSettings || {};

            const { registerPlugin } = wp.plugins;
            const { addFilter } = wp.hooks;
            const { Fragment, createElement } = wp.element;
            const { useDispatch, useSelect } = wp.data;

            // Add a custom column to the orders table
            addFilter(
                'woocommerce_admin_orders_table_column',
                'woo-invoice-to-repairshopr/repairshopr-column',
                (columns) => {
                    return [
                        ...columns,
                        {
                            key: 'repairshopr',
                            label: 'RepairShopr',
                            isSortable: false,
                            isPrimary: false,
                        },
                    ];
                }
            );

            // Render the button in the custom column
            addFilter(
                'woocommerce_admin_orders_table_cell',
                'woo-invoice-to-repairshopr/repairshopr-cell',
                (cell, column, order) => {
                    if (column.key !== 'repairshopr') {
                        return cell;
                    }
                    return createElement(
                        Fragment,
                        null,
                        createElement(
                            'button',
                            {
                                className: 'components-button is-secondary woo_inv_to_rs-send-to-repairshopr',
                                'data-order-id': order.id,
                                onClick: (event) => {
                                    alert('Send Invoice clicked');
                                    // Use AJAX to trigger the PHP handler for invoice
                                    if (window.woo_inv_to_rs_ajax && window.woo_inv_to_rs_ajax.ajax_url) {
                                        const button = event.target;
                                        button.disabled = true;
                                        button.textContent = 'Sending...';
                                        fetch(window.woo_inv_to_rs_ajax.ajax_url, {
                                            method: 'POST',
                                            credentials: 'same-origin',
                                            headers: {
                                                'Content-Type': 'application/x-www-form-urlencoded',
                                            },
                                            body: new URLSearchParams({
                                                action: 'woo_inv_to_rs_send_to_repairshopr',
                                                order_id: order.id,
                                                nonce: window.woo_inv_to_rs_ajax ? window.woo_inv_to_rs_ajax.nonce : '',
                                            }),
                                        })
                                            .then((res) => res.json())
                                            .then((response) => {
                                                if (response.success) {
                                                    button.textContent = 'Sent!';
                                                    button.classList.add('is-primary');
                                                } else {
                                                    button.textContent = 'Error';
                                                    button.classList.add('is-secondary');
                                                    alert('Error: ' + (response.data && response.data.message ? response.data.message : 'Unknown error'));
                                                }
                                            })
                                            .catch((err) => {
                                                button.textContent = 'Error';
                                                button.classList.add('is-secondary');
                                                alert('An error occurred while sending the invoice.');
                                            })
                                            .finally(() => {
                                                button.disabled = false;
                                            });
                                    }
                                }
                            },
                            'Send Invoice'
                        ),
                        createElement(
                            'button',
                            {
                                className: 'components-button is-secondary woo_inv_to_rs-send-payment',
                                style: { marginLeft: '8px' },
                                'data-order-id': order.id,
                                onClick: (event) => {
                                    // Use AJAX to trigger the PHP handler for payment
                                    if (window.woo_inv_to_rs_ajax && window.woo_inv_to_rs_ajax.ajax_url) {
                                        const button = event.target;
                                        button.disabled = true;
                                        button.textContent = 'Sending...';
                                        fetch(window.woo_inv_to_rs_ajax.ajax_url, {
                                            method: 'POST',
                                            credentials: 'same-origin',
                                            headers: {
                                                'Content-Type': 'application/x-www-form-urlencoded',
                                            },
                                            body: new URLSearchParams({
                                                action: 'woo_inv_to_rs_send_payment_to_repairshopr',
                                                order_id: order.id,
                                                nonce: window.woo_inv_to_rs_ajax ? window.woo_inv_to_rs_ajax.nonce : '',
                                            }),
                                        })
                                            .then((res) => res.json())
                                            .then((response) => {
                                                if (response.success) {
                                                    button.textContent = 'Sent!';
                                                    button.classList.add('is-primary');
                                                } else {
                                                    button.textContent = 'Error';
                                                    button.classList.add('is-secondary');
                                                    alert('Error: ' + (response.data && response.data.message ? response.data.message : 'Unknown error'));
                                                }
                                            })
                                            .catch((err) => {
                                                button.textContent = 'Error';
                                                button.classList.add('is-secondary');
                                                alert('An error occurred while sending the payment.');
                                            })
                                            .finally(() => {
                                                button.disabled = false;
                                            });
                                    }
                                }
                            },
                            'Send Payment'
                        ),
                        createElement(
                            'button',
                            {
                                className: 'components-button is-secondary woo_inv_to_rs-verify-invoice',
                                style: { marginLeft: '8px' },
                                'data-order-id': order.id,
                                onClick: (event) => {
                                    // Use AJAX to trigger the PHP handler for verification
                                    if (window.woo_inv_to_rs_ajax && window.woo_inv_to_rs_ajax.ajax_url) {
                                        const button = event.target;
                                        button.disabled = true;
                                        button.textContent = 'Verifying...';
                                        fetch(window.woo_inv_to_rs_ajax.ajax_url, {
                                            method: 'POST',
                                            credentials: 'same-origin',
                                            headers: {
                                                'Content-Type': 'application/x-www-form-urlencoded',
                                            },
                                            body: new URLSearchParams({
                                                action: 'woo_inv_to_rs_verify_invoice',
                                                order_id: order.id,
                                                nonce: window.woo_inv_to_rs_ajax ? window.woo_inv_to_rs_ajax.nonce : '',
                                            }),
                                        })
                                            .then((res) => res.json())
                                            .then((response) => {
                                                console.log('RepairShopr Verify API Response:', response); // Add console log for debugging
                                                if (response.success) {
                                                    if (response.data && response.data.match) {
                                                        button.textContent = '$ Match';
                                                        button.style.backgroundColor = 'green';
                                                        button.style.color = 'white'; // Ensure text is readable
                                                        button.classList.remove('is-secondary');
                                                    } else if (response.data && response.data.match === false) {
                                                        button.textContent = '$ Mismatch';
                                                        button.style.backgroundColor = 'red';
                                                        button.style.color = 'white'; // Ensure text is readable
                                                        button.classList.remove('is-secondary');
                                                    } else {
                                                        // Fallback for unexpected success response structure
                                                        button.textContent = 'Error (Unexpected)';
                                                        button.style.backgroundColor = ''; // Reset background
                                                        button.style.color = ''; // Reset color
                                                        button.classList.add('is-secondary');
                                                        alert('Error: Unexpected response structure. ' + (response.data && response.data.message ? response.data.message : ''));
                                                    }
                                                } else {
                                                    button.textContent = 'Error';
                                                    button.style.backgroundColor = ''; // Reset background
                                                    button.style.color = ''; // Reset color
                                                    button.classList.add('is-secondary');
                                                    alert('Error: ' + (response.data && response.data.message ? response.data.message : 'Unknown error'));
                                                }
                                            })
                                            .catch((err) => {
                                                button.textContent = 'Error';
                                                button.style.backgroundColor = ''; // Reset background
                                                button.style.color = ''; // Reset color
                                                button.classList.add('is-secondary');
                                                alert('An error occurred while verifying the invoice: ' + err.message);
                                            })
                                            .finally(() => {
                                                button.disabled = false;
                                                // Reset button to original state after a short delay or on next interaction
                                                // For now, keep the match/mismatch state. If user wants it to revert, we'd add a timeout.
                                            });
                                    }
                                }
                            },
                            '$ Verify'
                        )
                    );
        }
    );

    // Register a dummy plugin to ensure the filters are loaded
    registerPlugin('woo-invoice-to-repairshopr', {
        render: () => null,
    });
        }
    });
})();
