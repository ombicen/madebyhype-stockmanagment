<?php

namespace MadeByHypeStockmanagment;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The one place that answers what the current user may do in this plugin.
 *
 * All four permissions map to manage_woocommerce for now. Change the mapping
 * here, not at the call sites.
 */
class Capabilities
{
    const VIEW = 'manage_woocommerce';
    const EDIT_STOCK = 'manage_woocommerce';
    const EDIT_PRICES = 'manage_woocommerce';
    const UNDO = 'manage_woocommerce';

    public static function can_view()
    {
        return current_user_can(self::VIEW);
    }

    public static function can_edit_stock()
    {
        return current_user_can(self::EDIT_STOCK);
    }

    public static function can_edit_prices()
    {
        return current_user_can(self::EDIT_PRICES);
    }

    public static function can_undo()
    {
        return current_user_can(self::UNDO);
    }
}
