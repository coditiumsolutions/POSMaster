<?php

namespace App\Support;

/**
 * POS product card image: product upload → category image → shared default.
 */
class ProductFoodImage
{
    public static function defaultPath(): string
    {
        return CategoryImage::defaultPath();
    }

    public static function url(
        ?string $storedPath = null,
        ?string $productName = null,
        ?string $categoryName = null,
        ?string $categoryImagePath = null
    ): string {
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

        return CategoryImage::url($categoryImagePath, $categoryName);
    }
}
