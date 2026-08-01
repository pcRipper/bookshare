<?php

namespace App\Service\Storage;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Stores images on the local filesystem under public/uploads/{category}/ and
 * serves them from our own origin. Nginx hard-caches /uploads/ as immutable
 * (filenames are content hashes, so the bytes at a path never change).
 *
 * Swap this out for a DO Spaces / S3 implementation of ImageStorage without
 * touching ImageLocalizer — just repoint the alias in services.yaml.
 */
class LocalImageStorage implements ImageStorage
{
    /** Public web prefix all localized uploads share. */
    private const PUBLIC_BASE = '/uploads/';

    public function __construct(
        #[Autowire('%kernel.project_dir%')] private readonly string $projectDir,
    ) {}

    public function store(string $category, string $filename, string $bytes): string
    {
        $dir = $this->projectDir . '/public' . self::PUBLIC_BASE . $category . '/';
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('cannot create image directory: ' . $dir);
        }

        file_put_contents($dir . $filename, $bytes);

        return self::PUBLIC_BASE . $category . '/' . $filename;
    }

    public function owns(?string $url): bool
    {
        return $url !== null && str_starts_with($url, self::PUBLIC_BASE);
    }
}
