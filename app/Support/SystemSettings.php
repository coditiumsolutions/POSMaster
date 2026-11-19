<?php

namespace App\Support;

use App\Models\Configuration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

class SystemSettings
{
    public const TOP_NAVBAR_TITLE = 'system.top_navbar_title';
    public const PRINT_SLIP_HEADING = 'system.print_slip_heading';
    public const PRINT_SLIP_PHONE = 'system.print_slip_phone';
    public const PRINT_SLIP_IMAGE = 'system.print_slip_image';

    public static function defaults(): array
    {
        return [
            self::TOP_NAVBAR_TITLE => 'LipSip POS',
            self::PRINT_SLIP_HEADING => 'LipSip Mart',
            self::PRINT_SLIP_PHONE => '0339-5470747',
            self::PRINT_SLIP_IMAGE => '',
        ];
    }

    public static function get(string $key, ?string $default = null): string
    {
        $defaults = self::defaults();
        $fallback = $default ?? ($defaults[$key] ?? '');

        try {
            if (!Schema::hasTable('configurations')) {
                return $fallback;
            }
        } catch (\Throwable $e) {
            return $fallback;
        }

        return Cache::remember('syscfg.' . $key, 60, function () use ($key, $fallback) {
            $value = Configuration::query()
                ->where('config_key', $key)
                ->value('config_value');

            if ($value === null || trim((string) $value) === '') {
                return $fallback;
            }

            return (string) $value;
        });
    }

    public static function set(string $key, ?string $value): void
    {
        Configuration::updateOrCreate(
            ['config_key' => $key],
            ['config_value' => $value ?? '']
        );
        Cache::forget('syscfg.' . $key);
    }

    public static function topNavbarTitle(): string
    {
        return self::get(self::TOP_NAVBAR_TITLE);
    }

    public static function printSlipHeading(): string
    {
        return self::get(self::PRINT_SLIP_HEADING);
    }

    public static function printSlipPhone(): string
    {
        return self::get(self::PRINT_SLIP_PHONE);
    }

    public static function printSlipImage(): string
    {
        return self::get(self::PRINT_SLIP_IMAGE, '');
    }

    public static function printSlipImageUrl(): ?string
    {
        $path = self::printSlipImage();
        if ($path === '') {
            return null;
        }

        return asset($path);
    }
}
