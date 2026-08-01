<?php

namespace App\Tests\Service;

use App\Service\ImageLocalizer;
use App\Service\Storage\ImageStorage;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class ImageLocalizerTest extends TestCase
{
    /** An in-memory ImageStorage that records writes and owns any /uploads/ URL. */
    private function storage(): ImageStorage
    {
        return new class implements ImageStorage {
            /** @var array<array{category:string, filename:string, bytes:string}> */
            public array $stored = [];

            public function store(string $category, string $filename, string $bytes): string
            {
                $this->stored[] = ['category' => $category, 'filename' => $filename, 'bytes' => $bytes];

                return '/uploads/' . $category . '/' . $filename;
            }

            public function owns(?string $url): bool
            {
                return $url !== null && str_starts_with($url, '/uploads/');
            }
        };
    }

    public function testNullEmptyOwnedAndNonHttpInputsAreReturnedUntouchedWithoutFetching(): void
    {
        $http = new MockHttpClient(); // any request would 200 with empty body; we assert none fire
        $localizer = new ImageLocalizer($http, $this->storage());

        self::assertNull($localizer->localize(null, ImageLocalizer::COVERS));
        self::assertSame('', $localizer->localize('', ImageLocalizer::COVERS));
        self::assertSame('/uploads/covers/x.jpg', $localizer->localize('/uploads/covers/x.jpg', ImageLocalizer::COVERS));
        self::assertSame('data:image/png;base64,AAAA', $localizer->localize('data:image/png;base64,AAAA', ImageLocalizer::COVERS));

        self::assertSame(0, $http->getRequestsCount());
    }

    public function testSuccessfulFetchStoresContentHashedFileAndReturnsPublicUrl(): void
    {
        $bytes = 'JPEGBYTES';
        $http = new MockHttpClient(new MockResponse($bytes, [
            'http_code'        => 200,
            'response_headers' => ['content-type' => 'image/jpeg'],
        ]));
        $storage = $this->storage();
        $localizer = new ImageLocalizer($http, $storage);

        $result = $localizer->localize('https://cdn.example/cover.jpg', ImageLocalizer::COVERS);

        $expectedName = hash('xxh128', $bytes) . '.jpg';
        self::assertSame('/uploads/covers/' . $expectedName, $result);
        self::assertCount(1, $storage->stored);
        self::assertSame('covers', $storage->stored[0]['category']);
        self::assertSame($expectedName, $storage->stored[0]['filename']);
        self::assertSame($bytes, $storage->stored[0]['bytes']);
    }

    public function testUnsupportedContentTypeKeepsRemoteUrlAndStoresNothing(): void
    {
        $http = new MockHttpClient(new MockResponse('nope', [
            'http_code'        => 200,
            'response_headers' => ['content-type' => 'image/svg+xml'],
        ]));
        $storage = $this->storage();
        $localizer = new ImageLocalizer($http, $storage);

        self::assertSame(
            'https://cdn.example/cover.svg',
            $localizer->localize('https://cdn.example/cover.svg', ImageLocalizer::COVERS),
        );
        self::assertSame([], $storage->stored);
    }

    public function testOversizeImageKeepsRemoteUrl(): void
    {
        $http = new MockHttpClient(new MockResponse(str_repeat('x', 5_242_881), [
            'http_code'        => 200,
            'response_headers' => ['content-type' => 'image/png'],
        ]));
        $storage = $this->storage();
        $localizer = new ImageLocalizer($http, $storage);

        self::assertSame(
            'https://cdn.example/big.png',
            $localizer->localize('https://cdn.example/big.png', ImageLocalizer::COVERS),
        );
        self::assertSame([], $storage->stored);
    }

    public function testNon200ResponseKeepsRemoteUrl(): void
    {
        $http = new MockHttpClient(new MockResponse('', ['http_code' => 404]));
        $storage = $this->storage();
        $localizer = new ImageLocalizer($http, $storage);

        self::assertSame(
            'https://cdn.example/missing.png',
            $localizer->localize('https://cdn.example/missing.png', ImageLocalizer::COVERS),
        );
        self::assertSame([], $storage->stored);
    }

    public function testOwnsDelegatesToStorage(): void
    {
        $localizer = new ImageLocalizer(new MockHttpClient(), $this->storage());

        self::assertTrue($localizer->owns('/uploads/avatars/a.png'));
        self::assertFalse($localizer->owns('https://cdn.example/a.png'));
        self::assertFalse($localizer->owns(null));
    }
}
