<?php

namespace App\Tests\Service\Storage;

use App\Service\Storage\LocalImageStorage;
use PHPUnit\Framework\TestCase;

class LocalImageStorageTest extends TestCase
{
    private string $projectDir;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir() . '/img-storage-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        if (is_dir($this->projectDir)) {
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->projectDir, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($it as $path) {
                $path->isDir() ? @rmdir($path->getPathname()) : @unlink($path->getPathname());
            }
            @rmdir($this->projectDir);
        }
    }

    public function testStoreWritesFileUnderCategoryAndReturnsPublicPath(): void
    {
        $storage = new LocalImageStorage($this->projectDir);

        $path = $storage->store('covers', 'abc123.jpg', 'BYTES');

        self::assertSame('/uploads/covers/abc123.jpg', $path);
        $onDisk = $this->projectDir . '/public/uploads/covers/abc123.jpg';
        self::assertFileExists($onDisk);
        self::assertSame('BYTES', file_get_contents($onDisk));
    }

    public function testOwnsRecognisesUploadsPathsOnly(): void
    {
        $storage = new LocalImageStorage($this->projectDir);

        self::assertTrue($storage->owns('/uploads/covers/x.jpg'));
        self::assertTrue($storage->owns('/uploads/avatars/y.png'));
        self::assertFalse($storage->owns('https://cdn.example/x.jpg'));
        self::assertFalse($storage->owns(null));
    }
}
