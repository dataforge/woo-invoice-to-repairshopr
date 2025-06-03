/**
 * Injects a "RepairShopr" column with a "Send to RepairShopr" button into the new WooCommerce Admin (wc-orders) page.
 * Requires WooCommerce Admin (React-based) to be active.
 */
(function (wp, wc) {
    if (
        typeof wp === 'undefined' ||
        typeof wc === 'undefined' ||
        !wp.data ||
        !wp.plugins ||
        !wp.element
    ) {
        // Not on a page with WooCommerce Admin React context
        return;
    }

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
                'button',
                {
                    className: 'components-button is-secondary woo_inv_to_rs-send-to-repairshopr',
                    'data-order-id': order.id,
                    onClick: () => {
                        // Use AJAX to trigger the PHP handler
                        if (window.wcSettings && window.wcSettings.ajaxUrl) {
                            const button = event.target;
                            button.disabled = true;
                            button.textContent = 'Sending...';
                            fetch(window.wcSettings.ajaxUrl, {
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
                    },
                },
                'Send to RepairShopr'
            );
        }
    );

    // Register a dummy plugin to ensure the filters are loaded
    registerPlugin('woo-invoice-to-repairshopr', {
        render: () => null,
    });
})(window.wp, window.wc || window.wcSettings || {});
