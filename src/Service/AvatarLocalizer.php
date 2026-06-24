<?php

namespace App\Service;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Downloads a remote avatar (e.g. a Google profile photo) once, server-side,
 * and stores it under public/uploads/avatars/ so browsers fetch it from our
 * own origin instead of hammering — and getting 429'd by — Google's image CDN.
 */
class AvatarLocalizer
{
    /** Web path prefix of localized avatars; also our marker for "we own this one". */
    public const PUBLIC_PREFIX = '/uploads/avatars/';

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
        #[Autowire('%kernel.project_dir%')] private readonly string $projectDir,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    /**
     * Fetch $remoteUrl and store it locally, returning the public web path
     * (e.g. "/uploads/avatars/ab12….jpg"). If the URL is already localized,
     * returns it unchanged. On any failure returns $remoteUrl untouched so a
     * flaky CDN never breaks login — the worst case is the old hotlink.
     */
    public function localize(?string $remoteUrl): ?string
    {
        if ($remoteUrl === null || $remoteUrl === '' || str_starts_with($remoteUrl, self::PUBLIC_PREFIX)) {
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
                throw new \RuntimeException('avatar fetch returned HTTP ' . $response->getStatusCode());
            }

            $contentType = strtolower(explode(';', $response->getHeaders()['content-type'][0] ?? '')[0]);
            $ext = self::EXT[$contentType] ?? null;
            if ($ext === null) {
                throw new \RuntimeException('unsupported avatar content-type: ' . $contentType);
            }

            $bytes = $response->getContent();
            if (strlen($bytes) > self::MAX_BYTES) {
                throw new \RuntimeException('avatar exceeds size limit');
            }

            $dir = $this->projectDir . '/public' . self::PUBLIC_PREFIX;
            if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
                throw new \RuntimeException('cannot create avatar directory: ' . $dir);
            }

            // Name by content hash: identical image ⇒ same file (idempotent and
            // deduplicated); a rotated CDN URL with new bytes ⇒ a fresh file.
            $name = hash('xxh128', $bytes) . '.' . $ext;
            file_put_contents($dir . $name, $bytes);

            return self::PUBLIC_PREFIX . $name;
        } catch (\Throwable $e) {
            $this->logger?->warning('Avatar localization failed; keeping remote URL', [
                'url'   => $remoteUrl,
                'error' => $e->getMessage(),
            ]);

            return $remoteUrl;
        }
    }
}
