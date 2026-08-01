<?php

namespace App\Service\Storage;

/**
 * Where localized images (avatars, book covers) are persisted.
 *
 * The download/validation/naming logic lives in the caller (ImageLocalizer);
 * this interface owns only the "put these bytes somewhere durable and hand me
 * back a public URL" step — so the backing store can be swapped (local disk
 * today; a DO Spaces / S3 bucket later) without touching that logic. Wire the
 * chosen implementation via the alias in services.yaml.
 */
interface ImageStorage
{
    /**
     * Persist $bytes under a logical $category (e.g. "avatars", "covers") with
     * the given $filename, returning the public URL the browser fetches
     * (e.g. "/uploads/covers/ab12….jpg" locally, or a full CDN URL for S3).
     *
     * Idempotent on $filename: since filenames are content hashes, writing the
     * same bytes twice lands on the same object and returns the same URL.
     */
    public function store(string $category, string $filename, string $bytes): string;

    /**
     * Whether $url already points at an object this store owns — the caller's
     * signal that an image is already localized and must not be re-fetched.
     */
    public function owns(?string $url): bool;
}
