<?php
declare(strict_types=1);

namespace HelpdeskForm\Tests\Services;

use PHPUnit\Framework\TestCase;
use HelpdeskForm\Services\FileUploadService;
use GuzzleHttp\Psr7\UploadedFile;
use GuzzleHttp\Psr7\Utils;

class FileUploadServiceTest extends TestCase
{
    private string $uploadDir;
    private FileUploadService $service;

    protected function setUp(): void
    {
        $this->uploadDir = sys_get_temp_dir() . '/upl_test_' . uniqid();
        $this->service = new FileUploadService(
            $this->uploadDir,
            1024 * 1024, // 1 MB
            ['pdf', 'txt', 'png', 'jpg']
        );
    }

    protected function tearDown(): void
    {
        foreach (glob($this->uploadDir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->uploadDir);
    }

    private function makeUploadedFile(string $content, string $filename, string $mime, ?int $size = null): UploadedFile
    {
        $stream = Utils::streamFor($content);
        return new UploadedFile($stream, $size ?? strlen($content), UPLOAD_ERR_OK, $filename, $mime);
    }

    public function testRejectsDisallowedExtension(): void
    {
        $file = $this->makeUploadedFile('<?php echo 1; ?>', 'evil.php', 'application/x-php');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('File type not allowed');

        $this->service->uploadFile($file, 'sub-1');
    }

    public function testRejectsOversizeFile(): void
    {
        // Declared size exceeds the 1 MB limit.
        $file = $this->makeUploadedFile('x', 'big.txt', 'text/plain', 2 * 1024 * 1024);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('exceeds maximum allowed size');

        $this->service->uploadFile($file, 'sub-1');
    }

    public function testRejectsEmbeddedPhpInAllowedExtension(): void
    {
        $file = $this->makeUploadedFile("hello\n<?php system('id'); ?>", 'notes.txt', 'text/plain');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('dangerous content');

        $this->service->uploadFile($file, 'sub-1');
    }

    public function testAcceptsValidFile(): void
    {
        $file = $this->makeUploadedFile('just some plain notes', 'notes.txt', 'text/plain');

        $info = $this->service->uploadFile($file, 'sub-1');

        $this->assertSame('notes.txt', $info['original_filename']);
        $this->assertStringStartsWith('sub-1_', $info['stored_filename']);
        $this->assertFileExists($info['file_path']);
    }
}
