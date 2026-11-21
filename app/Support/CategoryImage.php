<?php

namespace App\Support;

/**
 * POS category tile / fallback image: uploaded category image, else name-matched default, else shared default.
 */
class CategoryImage
{
    public static function defaultPath(): string
    {
        return 'img/itemspic.png';
    }

    public static function url(?string $storedPath = null, ?string $categoryName = null): string
    {
        $stored = trim((string) $storedPath);
        if ($stored !== '') {
            if (preg_match('#^https?://#i', $stored)) {
                return $stored;
            }

            $relative = ltrim(str_replace('\\', '/', $stored), '/');
            if (is_file(public_path($relative)) || is_file(base_path('public_html/' . $relative))) {
                return asset($relative);
            }
        }

        return asset(self::defaultForName($categoryName));
    }

    public static function defaultForName(?string $categoryName): string
    {
        $n = strtolower(trim((string) $categoryName));

        $map = [
            'baver' => 'img/baverages.jpg',
            'bever' => 'img/baverages.jpg',
            'drink' => 'img/baverages.jpg',
            'soda' => 'img/baverages.jpg',
            'juice' => 'img/baverages.jpg',
            'shake' => 'img/baverages.jpg',
        ];

        foreach ($map as $needle => $path) {
            if ($n !== '' && str_contains($n, $needle) && self::publicFileExists($path)) {
                return $path;
            }
        }

        return self::defaultPath();
    }

    private static function publicFileExists(string $relative): bool
    {
        $relative = ltrim(str_replace('\\', '/', $relative), '/');

        return is_file(public_path($relative)) || is_file(base_path('public_html/' . $relative));
    }
}
