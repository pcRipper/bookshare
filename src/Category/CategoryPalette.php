<?php

namespace App\Category;

/**
 * The curated set of background colours offered when creating a new category.
 * This is the single source of truth on the backend: the create endpoint only
 * accepts these values, and the frontend renders matching chip styles for them
 * (see assets/src/utils/categoryColors.js — keep the two in sync).
 */
final class CategoryPalette
{
    public const COLORS = [
        '#E8F0EA', // sage green
        '#F4EAE0', // warm terracotta
        '#dae4ed', // slate blue
        '#ffdad6', // soft red
    ];

    /** @return string[] */
    public static function colors(): array
    {
        return self::COLORS;
    }

    public static function default(): string
    {
        return self::COLORS[0];
    }
}
