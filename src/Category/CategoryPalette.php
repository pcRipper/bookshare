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
        '#EAE4F4', // muted lavender
        '#F7E7C2', // soft amber
        '#D6EFEA', // dusty mint
        '#F4DCE8', // dusty rose
        '#E4E8D0', // pale olive
        '#E0DBD2', // warm stone
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
