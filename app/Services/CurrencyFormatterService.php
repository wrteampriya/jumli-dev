<?php

namespace App\Services;

use App\Models\Setting;

class CurrencyFormatterService
{
    protected function resolveCurrency($currency): object
    {
        return (object) [
            'symbol'            => $currency?->symbol ?? Setting::getValue('currency_symbol'),
            'symbol_position'   => $currency?->symbol_position ?? Setting::getValue('currency_symbol_position'),
            'decimal_places'    => $currency?->decimal_places ?? Setting::getValue('decimal_places'),
            'thousand_separator'=> $currency?->thousand_separator ?? Setting::getValue('thousand_separator'),
            'decimal_separator' => $currency?->decimal_separator ?? Setting::getValue('decimal_separator'),
        ];
    }

    /**
     * $trimZeroDecimals drops the decimal part when it is all zeros after rounding
     * to the currency's decimal places (5000.00 -> "5,000", 5000.50 stays "5,000.50").
     */
    public function formatPrice($amount, $currency = null, bool $trimZeroDecimals = false): ?string
    {
        if ($amount === null) {
            return null;
        }
        if ((float) $amount === 0.0) {
            return __('Free');
        }
        $currency = $this->resolveCurrency($currency);
        $decimals = (int) $currency->decimal_places;

        if ($trimZeroDecimals && ! $this->hasFraction($amount, $decimals)) {
            $decimals = 0;
        }

        $number = number_format(
            (float) $amount,
            $decimals,
            $currency->decimal_separator,
            $currency->thousand_separator
        );

        $position = strtolower((string) $currency->symbol_position);

        return $position === 'right'
            ? $number. ' ' .$currency->symbol
            : $currency->symbol. ' ' .$number;
    }

    public function formatSalaryRange($min, $max, $currency = null, bool $trimZeroDecimals = false): ?string
    {
        if (! $min && ! $max) {
            return null;
        }

        if ($min && ! $max) {
            return __('From'). ' ' .$this->formatPrice($min, $currency, $trimZeroDecimals);
        }

        if (! $min && $max) {
            return __('Upto'). ' ' .$this->formatPrice($max, $currency, $trimZeroDecimals);
        }

        // Trim both bounds or neither, so a range never mixes "5,000" with "10,000.50".
        if ($trimZeroDecimals) {
            $decimals = (int) $this->resolveCurrency($currency)->decimal_places;
            $trimZeroDecimals = ! $this->hasFraction($min, $decimals) && ! $this->hasFraction($max, $decimals);
        }

        return
            $this->formatPrice($min, $currency, $trimZeroDecimals)
            . ' - '
            . $this->formatPrice($max, $currency, $trimZeroDecimals);
    }

    protected function hasFraction($amount, int $decimals): bool
    {
        $rounded = round((float) $amount, $decimals);

        return $rounded != floor($rounded);
    }
}
