<?php

namespace MadeByHypeStockmanagment\Data;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The arithmetic of a bulk price change: one rule, one item's two prices in,
 * its new prices or the reason it is left alone out.
 *
 * Nothing here reads or writes anything, and nothing here calls WordPress:
 * the preview and the apply both ask this class, so they cannot disagree.
 * Whether a new price is accepted is still decided by WriteService.
 */
class BulkPriceRule
{
    // What is changed
    const CHANGE_REGULAR = 'regular';
    const CHANGE_SALE = 'sale';
    const CHANGE_BOTH = 'both';
    const CHANGE_SALE_FROM_REGULAR = 'sale_from_regular';
    const CHANGE_CLEAR_SALE = 'clear_sale';

    const CHANGES = ['regular', 'sale', 'both', 'sale_from_regular', 'clear_sale'];

    // How, for regular, sale and both
    const METHODS = ['increase_percent', 'decrease_percent', 'increase_amount', 'decrease_amount', 'set'];

    const ROUNDINGS = ['none', 'whole', 'ten', 'nine', 'ninety_nine'];

    // Why an item is left alone
    const SKIP_NO_REGULAR = 'no_regular_price';
    const SKIP_NO_SALE = 'no_sale_price';
    const SKIP_ON_SALE = 'already_on_sale';
    const SKIP_UNCHANGED = 'unchanged';
    const SKIP_NOT_POSITIVE = 'not_positive';
    const SKIP_SALE_NOT_BELOW = 'sale_not_below_regular';

    const SKIPS = ['no_regular_price', 'no_sale_price', 'already_on_sale', 'unchanged', 'not_positive', 'sale_not_below_regular'];

    // No price is multiplied beyond this; a typing slip should not get through as a rule
    const MAX_PERCENT = 1000;

    /**
     * Check a rule as it was sent and put it in its one form
     *
     * @param mixed  $raw         ['change', 'method', 'value', 'rounding', 'skip_on_sale']
     * @param int    $decimals    Decimals of the shop's prices
     * @param string $decimal_sep The shop's decimal separator; a dot is always understood too
     * @return array The rule: ['change', 'method' ('' when the change has none), 'value' (float, 0 when it has none),
     *               'rounding', 'skip_on_sale' (bool), 'decimals' (int)].
     *               Or ['error' => 'change' | 'method' | 'value' | 'value_percent' | 'rounding'].
     */
    public static function normalise($raw, $decimals, $decimal_sep = '.')
    {
        $raw = is_array($raw) ? $raw : [];
        $text = function ($key) use ($raw) {
            return isset($raw[$key]) && is_scalar($raw[$key]) ? trim((string) $raw[$key]) : '';
        };

        $rule = [
            'change' => $text('change'),
            'method' => '',
            'value' => 0.0,
            'rounding' => $text('rounding') === '' ? 'none' : $text('rounding'),
            'skip_on_sale' => false,
            'decimals' => max(0, (int) $decimals),
        ];

        if (!in_array($rule['change'], self::CHANGES, true)) {
            return ['error' => 'change'];
        }

        if ($rule['change'] === self::CHANGE_CLEAR_SALE) {
            $rule['rounding'] = 'none';

            return $rule;
        }

        if (!in_array($rule['rounding'], self::ROUNDINGS, true) || ($rule['rounding'] === 'ninety_nine' && $rule['decimals'] < 2)) {
            return ['error' => 'rounding'];
        }

        if ($rule['change'] === self::CHANGE_SALE_FROM_REGULAR) {
            $rule['skip_on_sale'] = in_array(strtolower($text('skip_on_sale')), ['1', 'true', 'yes', 'on'], true);
        } else {
            $rule['method'] = $text('method');

            // One exact price for the regular and the sale price at once is never meant
            if (!in_array($rule['method'], self::METHODS, true) || ($rule['method'] === 'set' && $rule['change'] === self::CHANGE_BOTH)) {
                return ['error' => 'method'];
            }
        }

        $value = $text('value');
        if ($decimal_sep !== '.' && $decimal_sep !== '') {
            $value = str_replace($decimal_sep, '.', $value);
        }

        // Digits with at most one decimal point: no sign, no exponent, no thousands separator
        if (!preg_match('/^(\d+(\.\d*)?|\.\d+)$/', $value) || (float) $value <= 0) {
            return ['error' => 'value'];
        }

        $rule['value'] = (float) $value;

        $takes_off = $rule['change'] === self::CHANGE_SALE_FROM_REGULAR || $rule['method'] === 'decrease_percent';
        $is_percent = $takes_off || $rule['method'] === 'increase_percent';

        if ($is_percent && ($rule['value'] > self::MAX_PERCENT || ($takes_off && $rule['value'] >= 100))) {
            return ['error' => 'value_percent'];
        }

        return $rule;
    }

    /**
     * What the rule does to one item
     *
     * @param array  $rule    From normalise()
     * @param string $regular Regular price as stored, '' for none
     * @param string $sale    Sale price as stored, '' for none
     * @return array ['regular' => new price or null (left as it is), 'sale' => new price, '' (the sale ends) or null]
     *               with at least one of the two set, or ['skip' => one of SKIPS]
     */
    public static function apply($rule, $regular, $sale)
    {
        $regular = self::stored($regular);
        $sale = self::stored($sale);
        $new_regular = null;
        $new_sale = null;

        switch ($rule['change']) {
            case self::CHANGE_CLEAR_SALE:
                return $sale === '' ? ['skip' => self::SKIP_NO_SALE] : ['regular' => null, 'sale' => ''];

            case self::CHANGE_SALE_FROM_REGULAR:
                if ($regular === '') {
                    return ['skip' => self::SKIP_NO_REGULAR];
                }
                if ($sale !== '' && $rule['skip_on_sale']) {
                    return ['skip' => self::SKIP_ON_SALE];
                }
                $new_sale = self::round_price((float) $regular * (1 - $rule['value'] / 100), $rule);
                break;

            case self::CHANGE_SALE:
                // An exact sale price can start a sale; a relative change needs one to work on
                if ($regular === '') {
                    return ['skip' => self::SKIP_NO_REGULAR];
                }
                if ($sale === '' && $rule['method'] !== 'set') {
                    return ['skip' => self::SKIP_NO_SALE];
                }
                $new_sale = self::calculate($rule, $sale);
                break;

            case self::CHANGE_REGULAR:
            case self::CHANGE_BOTH:
                if ($regular === '' && $rule['method'] !== 'set') {
                    return ['skip' => self::SKIP_NO_REGULAR];
                }
                $new_regular = self::calculate($rule, $regular);

                if ($rule['change'] === self::CHANGE_BOTH && $sale !== '') {
                    $new_sale = self::calculate($rule, $sale);
                }
                break;
        }

        if (($new_regular !== null && (float) $new_regular <= 0) || ($new_sale !== null && (float) $new_sale <= 0)) {
            return ['skip' => self::SKIP_NOT_POSITIVE];
        }

        if ($new_regular !== null && self::same($new_regular, $regular)) {
            $new_regular = null;
        }
        if ($new_sale !== null && self::same($new_sale, $sale)) {
            $new_sale = null;
        }

        if ($new_regular === null && $new_sale === null) {
            return ['skip' => self::SKIP_UNCHANGED];
        }

        // As the item will be: a sale price has to stay below the regular price
        $regular_then = $new_regular !== null ? $new_regular : $regular;
        $sale_then = $new_sale !== null ? $new_sale : $sale;

        if ($sale_then !== '' && ($regular_then === '' || (float) $sale_then >= (float) $regular_then)) {
            return ['skip' => self::SKIP_SALE_NOT_BELOW];
        }

        return ['regular' => $new_regular, 'sale' => $new_sale];
    }

    /**
     * The method of the rule applied to one price, rounded
     *
     * @param array  $rule
     * @param string $price '' for none (only 'set' works without one)
     * @return string
     */
    private static function calculate($rule, $price)
    {
        $from = (float) $price;

        switch ($rule['method']) {
            case 'increase_percent':
                $result = $from * (1 + $rule['value'] / 100);
                break;
            case 'decrease_percent':
                $result = $from * (1 - $rule['value'] / 100);
                break;
            case 'increase_amount':
                $result = $from + $rule['value'];
                break;
            case 'decrease_amount':
                $result = $from - $rule['value'];
                break;
            default:
                $result = $rule['value'];
        }

        return self::round_price($result, $rule);
    }

    /**
     * Round a calculated price the way the rule asks, to the nearest value of
     * that kind; halfway goes up. A price that ends in 9 or .99 never rounds
     * down to nothing: the lowest such price is 9, or 0.99.
     *
     * @param float $price
     * @param array $rule
     * @return string The price in stored form: a dot, no thousands separator, no trailing zeros
     */
    public static function round_price($price, $rule)
    {
        $decimals = $rule['decimals'];

        // Takes the noise of the multiplication out first, so 20.994999999999997 is the 20.995 it stands for
        $price = round($price, 8);

        switch ($rule['rounding']) {
            case 'whole':
                $price = floor($price + 0.5);
                $decimals = 0;
                break;
            case 'ten':
                $price = floor($price / 10 + 0.5) * 10;
                $decimals = 0;
                break;
            case 'nine':
                $price = self::nearest_ending($price, 10, 9);
                $decimals = 0;
                break;
            case 'ninety_nine':
                $price = self::nearest_ending($price, 1, 0.99);
                $decimals = 2;
                break;
            default:
                $price = round($price, $decimals);
        }

        $text = number_format($price, $decimals, '.', '');

        return strpos($text, '.') === false ? $text : rtrim(rtrim($text, '0'), '.');
    }

    /**
     * The nearest number that is a multiple of $step plus $ending
     */
    private static function nearest_ending($price, $step, $ending)
    {
        $upper = floor($price / $step) * $step + $ending;
        if ($upper < $price) {
            $upper += $step;
        }
        $lower = $upper - $step;

        // Halfway goes up; there is nothing at or below zero to go down to
        return $lower > 0 && ($price - $lower) < ($upper - $price) ? $lower : $upper;
    }

    /**
     * A stored price as this class reads it: a number as text, or '' for none
     */
    private static function stored($price)
    {
        $price = is_scalar($price) ? trim((string) $price) : '';

        return is_numeric($price) ? $price : '';
    }

    private static function same($a, $b)
    {
        return $b !== '' && abs((float) $a - (float) $b) < 0.000001;
    }
}
