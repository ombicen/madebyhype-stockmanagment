<?php

namespace OmerStockhmanagment\Assets;

if (! defined('ABSPATH')) {
    exit;
}

class AssetsManager
{
    public function init()
    {
        // Initialize assets manager
    }

    /**
     * Enqueue admin scripts and styles
     */
    public function enqueue_admin_scripts($hook)
    {
        if ($hook !== 'toplevel_page_omer-stockmanagment') {
            return;
        }

        wp_enqueue_script('jquery');

        // Enqueue Flatpickr for date range picker
        wp_enqueue_style('flatpickr', 'https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css');
        wp_enqueue_script('flatpickr', 'https://cdn.jsdelivr.net/npm/flatpickr', array(), null, true);

        // Enqueue component CSS files
        $this->enqueue_component_styles();

        wp_add_inline_script('jquery', $this->get_admin_javascript());
        wp_add_inline_style('wp-admin', $this->get_admin_css());
    }

    private function enqueue_component_styles()
    {
        $plugin_url = plugin_dir_url(__DIR__ . '/../../');

        // Enqueue component CSS files
        wp_enqueue_style('omer-date-filter', $plugin_url . 'UI/styles/date-filter.css', array(), '1.0.0');
        wp_enqueue_style('omer-top-controls', $plugin_url . 'UI/styles/top-controls.css', array(), '1.0.0');
        wp_enqueue_style('omer-sidebar-filters', $plugin_url . 'UI/styles/sidebar-filters.css', array(), '1.0.0');
        wp_enqueue_style('omer-product-table', $plugin_url . 'UI/styles/product-table.css', array(), '1.0.0');
        wp_enqueue_style('omer-variation-table', $plugin_url . 'UI/styles/variation-table.css', array(), '1.0.0');
        wp_enqueue_style('omer-pagination', $plugin_url . 'UI/styles/pagination.css', array(), '1.0.0');
        wp_enqueue_style('omer-footer-info', $plugin_url . 'UI/styles/footer-info.css', array(), '1.0.0');
        wp_enqueue_style('omer-legend', $plugin_url . 'UI/styles/legend.css', array(), '1.0.0');
        wp_enqueue_style('omer-version-history', $plugin_url . 'UI/styles/version-history.css', array(), '1.0.0');
    }

    private function get_admin_javascript()
    {
        return '
            jQuery(document).ready(function($) {
                // Initialize date range picker
                if (typeof flatpickr !== "undefined") {
                    flatpickr("#date-range", {
                        mode: "range",
                        dateFormat: "Y-m-d",
                        onChange: function(selectedDates, dateStr, instance) {
                            if (selectedDates.length === 2) {
                                $("#start_date").val(selectedDates[0].toISOString().split("T")[0]);
                                $("#end_date").val(selectedDates[1].toISOString().split("T")[0]);
                            }
                        },
                        theme: "light"
                    });
                }
                
                $(".expand-variations").on("click", function(e) {
                    e.preventDefault();
                    var productId = $(this).data("product-id");
                    var variationsRow = $("#variations-" + productId);
                    var button = $(this);
                    
                    if (variationsRow.is(":visible")) {
                        variationsRow.hide();
                        button.removeClass("expanded");
                    } else {
                        variationsRow.show();
                        button.addClass("expanded");
                        // Re-initialize event handlers for newly visible variation inputs
                        initVariationInputHandlers();
                    }
                });

                // Initialize stock editing functionality
                initStockEditing();
                
                // Initialize version management
                initVersionManagement();
                
                // Backup event handler for per_page select
                $("#per_page").on("change", function() {
                    changePerPage(this.value);
                });
            });

            function changePerPage(value) {
                try {
                    console.log(\'changePerPage called with value:\', value);
                    var currentUrl = new URL(window.location);
                    currentUrl.searchParams.set(\'per_page\', value);
                    currentUrl.searchParams.set(\'paged\', 1); // Reset to first page
                    console.log(\'Redirecting to:\', currentUrl.toString());
                    window.location.href = currentUrl.toString();
                } catch (error) {
                    console.error(\'Error in changePerPage:\', error);
                    // Fallback: simple redirect
                    var separator = window.location.href.indexOf(\'?\') !== -1 ? \'&\' : \'?\';
                    window.location.href = window.location.href + separator + \'per_page=\' + value + \'&paged=1\';
                }
            }

            // Sidebar toggle functionality
            jQuery(document).ready(function($) {
                var sidebar = $("#filters-sidebar");
                var toggleBtn = $("#toggle-sidebar");
                var toggleText = $("#sidebar-toggle-text");
                var storageKey = "omer_stock_sidebar_state";
                var isSidebarVisible = false;

                // Load saved state from localStorage
                function loadSidebarState() {
                    var savedState = localStorage.getItem(storageKey);
                    if (savedState !== null) {
                        isSidebarVisible = savedState === \'open\';
                    } else {
                        // Default state - hidden on all devices
                        isSidebarVisible = false;
                    }
                    updateSidebarVisibility();
                }

                // Save state to localStorage
                function saveSidebarState() {
                    localStorage.setItem(storageKey, isSidebarVisible ? \'open\' : \'closed\');
                }

                // Update sidebar visibility based on state
                function updateSidebarVisibility() {
                    if (isSidebarVisible) {
                        sidebar.show();
                        toggleText.text(\'☰ Filters\');
                    } else {
                        sidebar.hide();
                        toggleText.text(\'☰ Show Filters\');
                    }
                }

                // Initialize sidebar state
                loadSidebarState();

                toggleBtn.on("click", function() {
                    isSidebarVisible = !isSidebarVisible;
                    updateSidebarVisibility();
                    saveSidebarState();
                });

                // Handle window resize for mobile responsiveness
                $(window).on(\'resize\', function() {
                    if (window.innerWidth < 768 && isSidebarVisible) {
                        // Auto-hide on mobile but don\'t save this state
                        sidebar.hide();
                        toggleText.text(\'☰ Show Filters\');
                    } else if (window.innerWidth >= 768) {
                        // Restore saved state on desktop
                        updateSidebarVisibility();
                    }
                });
            });

            // Stock editing functionality
            var changedProducts = {};
            var changedVariations = {};

            function initVariationInputHandlers() {
                jQuery(document).ready(function($) {
                    // Track changes on variation stock quantity inputs
                    $(".variation-stock-quantity-input").off("input").on("input", function() {
                        var variationId = $(this).data("variation-id");
                        var originalValue = $(this).data("original-value");
                        var currentValue = $(this).val();
                        
                        if (currentValue !== originalValue) {
                            changedVariations[variationId] = changedVariations[variationId] || {};
                            changedVariations[variationId].stock_quantity = currentValue;
                            $(this).addClass("changed");
                        } else {
                            if (changedVariations[variationId]) {
                                delete changedVariations[variationId].stock_quantity;
                                if (Object.keys(changedVariations[variationId]).length === 0) {
                                    delete changedVariations[variationId];
                                }
                            }
                            $(this).removeClass("changed");
                        }
                        updateSaveControls();
                    });

                    // Track changes on variation stock status selects
                    $(".variation-stock-status-select").off("change").on("change", function() {
                        var variationId = $(this).data("variation-id");
                        var originalValue = $(this).data("original-value");
                        var currentValue = $(this).val();
                        
                        if (currentValue !== originalValue) {
                            changedVariations[variationId] = changedVariations[variationId] || {};
                            changedVariations[variationId].stock_status = currentValue;
                            $(this).addClass("changed");
                        } else {
                            if (changedVariations[variationId]) {
                                delete changedVariations[variationId].stock_status;
                                if (Object.keys(changedVariations[variationId]).length === 0) {
                                    delete changedVariations[variationId];
                                }
                            }
                            $(this).removeClass("changed");
                        }
                        updateSaveControls();
                    });

                    // Track changes on variation price inputs
                    $(".variation-price-input").off("input").on("input", function() {
                        var variationId = $(this).data("variation-id");
                        var originalValue = $(this).data("original-value");
                        var currentValue = $(this).val();
                        
                        if (currentValue !== originalValue) {
                            changedVariations[variationId] = changedVariations[variationId] || {};
                            changedVariations[variationId].regular_price = currentValue;
                            $(this).addClass("changed");
                        } else {
                            if (changedVariations[variationId]) {
                                delete changedVariations[variationId].regular_price;
                                if (Object.keys(changedVariations[variationId]).length === 0) {
                                    delete changedVariations[variationId];
                                }
                            }
                            $(this).removeClass("changed");
                        }
                        updateSaveControls();
                    });
                });
            }

            function initStockEditing() {
                jQuery(document).ready(function($) {
                    // Track changes on stock quantity inputs
                    $(".stock-quantity-input").on("input", function() {
                        var productId = $(this).data("product-id");
                        var originalValue = $(this).data("original-value");
                        var currentValue = $(this).val();
                        
                        if (currentValue !== originalValue) {
                            changedProducts[productId] = changedProducts[productId] || {};
                            changedProducts[productId].stock_quantity = currentValue;
                            $(this).addClass("changed");
                        } else {
                            if (changedProducts[productId]) {
                                delete changedProducts[productId].stock_quantity;
                                if (Object.keys(changedProducts[productId]).length === 0) {
                                    delete changedProducts[productId];
                                }
                            }
                            $(this).removeClass("changed");
                        }
                        updateSaveControls();
                    });

                    // Track changes on stock status selects
                    $(".stock-status-select").on("change", function() {
                        var productId = $(this).data("product-id");
                        var originalValue = $(this).data("original-value");
                        var currentValue = $(this).val();
                        
                        if (currentValue !== originalValue) {
                            changedProducts[productId] = changedProducts[productId] || {};
                            changedProducts[productId].stock_status = currentValue;
                            $(this).addClass("changed");
                        } else {
                            if (changedProducts[productId]) {
                                delete changedProducts[productId].stock_status;
                                if (Object.keys(changedProducts[productId]).length === 0) {
                                    delete changedProducts[productId];
                                }
                            }
                            $(this).removeClass("changed");
                        }
                        updateSaveControls();
                    });

                    // Track changes on price inputs
                    $(".price-input").on("input", function() {
                        var productId = $(this).data("product-id");
                        var originalValue = $(this).data("original-value");
                        var currentValue = $(this).val();
                        
                        if (currentValue !== originalValue) {
                            changedProducts[productId] = changedProducts[productId] || {};
                            changedProducts[productId].price = currentValue;
                            $(this).addClass("changed");
                        } else {
                            if (changedProducts[productId]) {
                                delete changedProducts[productId].price;
                                if (Object.keys(changedProducts[productId]).length === 0) {
                                    delete changedProducts[productId];
                                }
                            }
                            $(this).removeClass("changed");
                        }
                        updateSaveControls();
                    });

                    // Track changes on regular price inputs
                    $(".regular-price-input").on("input", function() {
                        var productId = $(this).data("product-id");
                        var originalValue = $(this).data("original-value");
                        var currentValue = $(this).val();
                        
                        if (currentValue !== originalValue) {
                            changedProducts[productId] = changedProducts[productId] || {};
                            changedProducts[productId].regular_price = currentValue;
                            $(this).addClass("changed");
                        } else {
                            if (changedProducts[productId]) {
                                delete changedProducts[productId].regular_price;
                                if (Object.keys(changedProducts[productId]).length === 0) {
                                    delete changedProducts[productId];
                                }
                            }
                            $(this).removeClass("changed");
                        }
                        updateSaveControls();
                    });

                    // Track changes on sale price inputs
                    $(".sale-price-input").on("input", function() {
                        var productId = $(this).data("product-id");
                        var originalValue = $(this).data("original-value");
                        var currentValue = $(this).val();
                        
                        if (currentValue !== originalValue) {
                            changedProducts[productId] = changedProducts[productId] || {};
                            changedProducts[productId].sale_price = currentValue;
                            $(this).addClass("changed");
                        } else {
                            if (changedProducts[productId]) {
                                delete changedProducts[productId].sale_price;
                                if (Object.keys(changedProducts[productId]).length === 0) {
                                    delete changedProducts[productId];
                                }
                            }
                            $(this).removeClass("changed");
                        }
                        updateSaveControls();
                    });

                    // Initialize variation input handlers
                    initVariationInputHandlers();

                    // Save changes button
                    $("#save-changes-btn").on("click", function() {
                        saveStockChanges();
                    });

                    // Reset changes button
                    $("#reset-changes-btn").on("click", function() {
                        resetStockChanges();
                    });
                });
            }

            function updateSaveControls() {
                var productChangeCount = Object.keys(changedProducts).length;
                var variationChangeCount = Object.keys(changedVariations).length;
                var totalChangeCount = productChangeCount + variationChangeCount;
                
                var $indicator = jQuery("#changes-indicator");
                var $saveBtn = jQuery("#save-changes-btn");
                var $resetBtn = jQuery("#reset-changes-btn");
                var $count = jQuery(".changes-count");

                $count.text(totalChangeCount);
                
                if (totalChangeCount > 0) {
                    $saveBtn.prop("disabled", false);
                    $resetBtn.prop("disabled", false);
                } else {
                    $saveBtn.prop("disabled", true);
                    $resetBtn.prop("disabled", true);
                }
            }

            function saveStockChanges() {
                var $saveBtn = jQuery("#save-changes-btn");
                var originalText = $saveBtn.text();
                
                $saveBtn.text("Saving...").prop("disabled", true);

                var productUpdates = [];
                for (var productId in changedProducts) {
                    productUpdates.push({
                        product_id: productId,
                        stock_quantity: changedProducts[productId].stock_quantity,
                        stock_status: changedProducts[productId].stock_status,
                        price: changedProducts[productId].price,
                        regular_price: changedProducts[productId].regular_price,
                        sale_price: changedProducts[productId].sale_price
                    });
                }

                var variationUpdates = [];
                for (var variationId in changedVariations) {
                    variationUpdates.push({
                        variation_id: variationId,
                        stock_quantity: changedVariations[variationId].stock_quantity,
                        stock_status: changedVariations[variationId].stock_status,
                        regular_price: changedVariations[variationId].regular_price
                    });
                }

                // Send product updates
                if (productUpdates.length > 0) {
                    jQuery.ajax({
                        url: ajaxurl,
                        type: "POST",
                        data: {
                            action: "update_product_stock",
                            nonce: "' . wp_create_nonce('omer_stock_update_nonce') . '",
                            updates: productUpdates
                        },
                        success: function(response) {
                            if (response.success) {
                                // Remove changed classes
                                jQuery(".stock-quantity-input.changed, .stock-status-select.changed, .price-input.changed, .regular-price-input.changed, .sale-price-input.changed").removeClass("changed");
                                
                                // Update original values and reset changed products
                                for (var i = 0; i < response.data.results.length; i++) {
                                    var result = response.data.results[i];
                                    if (result.success) {
                                        var $stockInput = jQuery(".stock-quantity-input[data-product-id=\'" + result.product_id + "\']");
                                        var $stockSelect = jQuery(".stock-status-select[data-product-id=\'" + result.product_id + "\']");
                                        var $priceInput = jQuery(".price-input[data-product-id=\'" + result.product_id + "\']");
                                        var $regularPriceInput = jQuery(".regular-price-input[data-product-id=\'" + result.product_id + "\']");
                                        var $salePriceInput = jQuery(".sale-price-input[data-product-id=\'" + result.product_id + "\']");
                                        
                                        if ($stockInput.length) {
                                            $stockInput.data("original-value", $stockInput.val());
                                        }
                                        if ($stockSelect.length) {
                                            $stockSelect.data("original-value", $stockSelect.val());
                                        }
                                        if ($priceInput.length) {
                                            $priceInput.data("original-value", $priceInput.val());
                                        }
                                        if ($regularPriceInput.length) {
                                            $regularPriceInput.data("original-value", $regularPriceInput.val());
                                        }
                                        if ($salePriceInput.length) {
                                            $salePriceInput.data("original-value", $salePriceInput.val());
                                        }
                                        
                                        // Remove this product from changedProducts
                                        delete changedProducts[result.product_id];
                                    }
                                }
                            } else {
                                showNotification("Error updating products: " + response.data, "error");
                            }
                        },
                        error: function() {
                            showNotification("Network error occurred while updating products", "error");
                        }
                    });
                }

                // Send variation updates
                if (variationUpdates.length > 0) {
                    jQuery.ajax({
                        url: ajaxurl,
                        type: "POST",
                        data: {
                            action: "update_variation_stock",
                            nonce: "' . wp_create_nonce('omer_stock_update_nonce') . '",
                            updates: variationUpdates
                        },
                        success: function(response) {
                            if (response.success) {
                                // Remove changed classes
                                jQuery(".variation-stock-quantity-input.changed, .variation-stock-status-select.changed, .variation-price-input.changed").removeClass("changed");
                                
                                // Update original values and reset changed variations
                                for (var i = 0; i < response.data.results.length; i++) {
                                    var result = response.data.results[i];
                                    if (result.success) {
                                        var $stockInput = jQuery(".variation-stock-quantity-input[data-variation-id=\'" + result.variation_id + "\']");
                                        var $stockSelect = jQuery(".variation-stock-status-select[data-variation-id=\'" + result.variation_id + "\']");
                                        var $priceInput = jQuery(".variation-price-input[data-variation-id=\'" + result.variation_id + "\']");
                                        
                                        if ($stockInput.length) {
                                            $stockInput.data("original-value", $stockInput.val());
                                        }
                                        if ($stockSelect.length) {
                                            $stockSelect.data("original-value", $stockSelect.val());
                                        }
                                        if ($priceInput.length) {
                                            $priceInput.data("original-value", $priceInput.val());
                                        }
                                        
                                        // Remove this variation from changedVariations
                                        delete changedVariations[result.variation_id];
                                    }
                                }
                            } else {
                                showNotification("Error updating variations: " + response.data, "error");
                            }
                        },
                        error: function() {
                            showNotification("Network error occurred while updating variations", "error");
                        }
                    });
                }

                // Show success message and update controls
                setTimeout(function() {
                    var totalSuccess = (productUpdates.length > 0 ? 1 : 0) + (variationUpdates.length > 0 ? 1 : 0);
                    if (totalSuccess > 0) {
                        showNotification("Successfully updated stock information", "success");
                    }
                    updateSaveControls();
                    $saveBtn.text(originalText).prop("disabled", false);
                }, 500);
            }

            function resetStockChanges() {
                jQuery(".stock-quantity-input").each(function() {
                    var $input = jQuery(this);
                    var originalValue = $input.data("original-value");
                    $input.val(originalValue).removeClass("changed");
                });

                jQuery(".stock-status-select").each(function() {
                    var $select = jQuery(this);
                    var originalValue = $select.data("original-value");
                    $select.val(originalValue).removeClass("changed");
                });

                jQuery(".price-input, .regular-price-input, .sale-price-input").each(function() {
                    var $input = jQuery(this);
                    var originalValue = $input.data("original-value");
                    $input.val(originalValue).removeClass("changed");
                });

                jQuery(".variation-stock-quantity-input").each(function() {
                    var $input = jQuery(this);
                    var originalValue = $input.data("original-value");
                    $input.val(originalValue).removeClass("changed");
                });

                jQuery(".variation-stock-status-select").each(function() {
                    var $select = jQuery(this);
                    var originalValue = $select.data("original-value");
                    $select.val(originalValue).removeClass("changed");
                });

                jQuery(".variation-price-input").each(function() {
                    var $input = jQuery(this);
                    var originalValue = $input.data("original-value");
                    $input.val(originalValue).removeClass("changed");
                });

                changedProducts = {};
                changedVariations = {};
                updateSaveControls();
            }

            function showNotification(message, type) {
                var $notification = jQuery("<div class=\'stock-notification \'" + type + "\'>" + message + "</div>");
                jQuery("body").append($notification);
                
                setTimeout(function() {
                    $notification.fadeOut(function() {
                        jQuery(this).remove();
                    });
                }, 3000);
            }

            function initVersionManagement() {
                jQuery(document).ready(function($) {
                    // Handle version revert button clicks
                    $(document).on("click", ".version-revert-btn", function() {
                        var versionNumber = $(this).data("version");
                        showRevertConfirmation(versionNumber);
                    });
                });
            }

            function showRevertConfirmation(versionNumber) {
                var modalHtml = \'<div class="version-revert-modal">\' +
                    \'<div class="version-revert-modal-content">\' +
                        \'<div class="version-revert-modal-header">\' +
                            \'<h3 class="version-revert-modal-title">Revert to Version \' + versionNumber + \'?</h3>\' +
                            \'<p class="version-revert-modal-message">This will revert all changes made in this version. This action cannot be undone.</p>\' +
                        \'</div>\' +
                        \'<div class="version-revert-modal-actions">\' +
                            \'<button type="button" class="version-revert-modal-cancel">Cancel</button>\' +
                            \'<button type="button" class="version-revert-modal-confirm" data-version="\' + versionNumber + \'">Revert</button>\' +
                        \'</div>\' +
                    \'</div>\' +
                \'</div>\';
                
                jQuery("body").append(modalHtml);
                
                // Handle modal actions
                jQuery(".version-revert-modal-cancel").on("click", function() {
                    jQuery(".version-revert-modal").remove();
                });
                
                jQuery(".version-revert-modal-confirm").on("click", function() {
                    var versionToRevert = jQuery(this).data("version");
                    jQuery(this).prop("disabled", true).text("Reverting...");
                    revertToVersion(versionToRevert);
                });
                
                // Close modal on background click
                jQuery(".version-revert-modal").on("click", function(e) {
                    if (e.target === this) {
                        jQuery(this).remove();
                    }
                });
            }

            function revertToVersion(versionNumber) {
                jQuery.ajax({
                    url: ajaxurl,
                    type: "POST",
                    data: {
                        action: "revert_to_version",
                        nonce: "' . wp_create_nonce('omer_version_revert_nonce') . '",
                        version_number: versionNumber
                    },
                    success: function(response) {
                        jQuery(".version-revert-modal").remove();
                        
                        if (response.success) {
                            showNotification("Successfully reverted to version " + versionNumber, "success");
                            // Reload the page to show updated data
                            setTimeout(function() {
                                window.location.reload();
                            }, 1000);
                        } else {
                            showNotification("Error reverting to version: " + response.data, "error");
                        }
                    },
                    error: function() {
                        jQuery(".version-revert-modal").remove();
                        showNotification("Network error occurred while reverting", "error");
                    }
                });
            }
        ';
    }

    private function get_admin_css()
    {
        return '
            /* Flatpickr customization for minimal black/white theme */
            .flatpickr-calendar {
                background: white;
                border: 1px solid #e5e5e5;
                border-radius: 8px;
                box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
                font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            }
            .flatpickr-day {
                color: #000;
                border-radius: 4px;
            }
            .flatpickr-day:hover {
                background: #f5f5f5;
                color: #000;
            }
            .flatpickr-day.selected {
                background: #000;
                color: white;
                border-color: #000;
            }
            .flatpickr-day.inRange {
                background: #f5f5f5;
                color: #000;
            }
            .flatpickr-day.startRange {
                background: #000;
                color: white;
            }
            .flatpickr-day.endRange {
                background: #000;
                color: white;
            }
            .flatpickr-current-month {
                color: #000;
                font-weight: 600;
            }
            .flatpickr-monthDropdown-months {
                color: #000;
            }
            .flatpickr-weekday {
                color: #666;
                font-weight: 500;
            }
            .flatpickr-prev-month, .flatpickr-next-month {
                color: #000;
            }
            .flatpickr-prev-month:hover, .flatpickr-next-month:hover {
                color: #000;
            }
            .flatpickr-day.selected, .flatpickr-day.startRange, .flatpickr-day.endRange, .flatpickr-day.selected.inRange, .flatpickr-day.startRange.inRange, .flatpickr-day.endRange.inRange, .flatpickr-day.selected:focus, .flatpickr-day.startRange:focus, .flatpickr-day.endRange:focus, .flatpickr-day.selected:hover, .flatpickr-day.startRange:hover, .flatpickr-day.endRange:hover, .flatpickr-day.selected.prevMonthDay, .flatpickr-day.startRange.prevMonthDay, .flatpickr-day.endRange.prevMonthDay, .flatpickr-day.selected.nextMonthDay, .flatpickr-day.startRange.nextMonthDay, .flatpickr-day.endRange.nextMonthDay {
                background: #000 !important;
                border-color: #000 !important;
            }

            /* Stock notification styles */
            .stock-notification {
                position: fixed;
                background: white !important;
                top: 32px;
                right: 20px;
                padding: 12px 16px;
                border-radius: 6px;
                font-size: 14px;
                font-weight: 500;
                z-index: 9999;
                box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
                max-width: 300px;
            }
            .stock-notification.success {
                background: #dcfce7;
                color: #166534;
                border: 1px solid #bbf7d0;
            }
            .stock-notification.error {
                background: #fef2f2;
                color: #dc2626;
                border: 1px solid #fecaca;
            }
        ';
    }
}
