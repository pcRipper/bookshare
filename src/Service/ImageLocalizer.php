<?php

namespace App\Service;

use App\Service\Storage\ImageStorage;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Downloads a remote image (a Google profile photo, an Open Library / bookfinder
 * cover, a pasted URL) once, server-side, and hands it to ImageStorage so the
 * browser fetches it from our own origin instead of hotlinking — and getting
 * 429'd by — someone else's CDN. Where the bytes actually land (local disk,
 * S3, …) is the storage's concern; this class only fetches, validates and names.
 */
class ImageLocalizer
{
    /** Logical storage categories (subfolders / key prefixes). */
    public const AVATARS = 'avatars';
    public const COVERS  = 'covers';

    private const MAX_BYTES = 5_242_880; // 5 MiB

    /** content-type ⇒ file extension allow-list. */
    private const EXT = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
        'image/gif'  => 'gif',
    ];

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly ImageStorage $storage,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    /**
     * Fetch $remoteUrl and store it under $category, returning the public URL.
     * A null/empty input, an already-localized URL (one the store owns), or a
     * non-HTTP URL is returned unchanged. On any fetch/validation failure the
     * original URL is returned untouched, so a flaky CDN never breaks the flow —
     * the worst case is the old hotlink.
     */
    public function localize(?string $remoteUrl, string $category): ?string
    {
        if ($remoteUrl === null || $remoteUrl === '' || $this->storage->owns($remoteUrl)) {
            return $remoteUrl;
        }
        // Only remote HTTP(S) URLs are fetchable; leave local paths, data URIs
        // and anything else exactly as given.
        if (!str_starts_with($remoteUrl, 'http://') && !str_starts_with($remoteUrl, 'https://')) {
            return $remoteUrl;
        }

        try {
            $response = $this->httpClient->request('GET', $remoteUrl, [
                'max_duration' => 5,
                'headers'      => ['Accept' => 'image/*'],
            ]);

            // getStatusCode() never throws; bail on anything non-200 (incl. 429)
            // before getHeaders()/getContent() would throw on a 4xx/5xx.
            if (200 !== $response->getStatusCode()) {
                throw new \RuntimeException('image fetch returned HTTP ' . $response->getStatusCode());
            }

            $contentType = strtolower(explode(';', $response->getHeaders()['content-type'][0] ?? '')[0]);
            $ext = self::EXT[$contentType] ?? null;
            if ($ext === null) {
                throw new \RuntimeException('unsupported image content-type: ' . $contentType);
            }

            $bytes = $response->getContent();
            if (strlen($bytes) > self::MAX_BYTES) {
                throw new \RuntimeException('image exceeds size limit');
            }

            // Name by content hash: identical image ⇒ same object (idempotent and
            // deduplicated); a rotated URL with new bytes ⇒ a fresh object.
            $name = hash('xxh128', $bytes) . '.' . $ext;

            return $this->storage->store($category, $name, $bytes);
        } catch (\Throwable $e) {
            $this->logger?->warning('Image localization failed; keeping remote URL', [
                'url'      => $remoteUrl,
                'category' => $category,
                'error'    => $e->getMessage(),
            ]);

            return $remoteUrl;
        }
    }

    /** Whether $url is already an image we host (delegates to the store). */
    public function owns(?string $url): bool
    {
        return $this->storage->owns($url);
    }
}
