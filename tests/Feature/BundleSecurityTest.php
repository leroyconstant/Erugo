<?php

namespace Tests\Feature;

use App\Services\BundleExtractor;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Tests\TestCase;
use ZipArchive;

class BundleSecurityTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/erugo-bundle-test-' . bin2hex(random_bytes(8));
        mkdir($this->root . '/out', 0700, true);
        config(['erugo.bundle_max_bytes' => 1048576, 'erugo.bundle_max_files' => 10]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);
        parent::tearDown();
    }

    private function archive(array $entries, array $files): string
    {
        $path = $this->root . '/bundle.zip';
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::CREATE);
        foreach ($entries as $name => $content) {
            $zip->addFromString($name, $content);
        }
        $zip->addFromString('__erugo_manifest__.json', json_encode(['files' => $files]));
        $zip->close();
        return $path;
    }

    public function test_nested_files_use_actual_sizes_and_do_not_extract_unlisted_files(): void
    {
        $path = $this->archive(['folder/test.txt' => 'hello', 'unlisted.txt' => 'not listed'], [
            ['path' => 'folder/test.txt', 'originalName' => 'test.txt', 'size' => 0],
        ]);
        $files = app(BundleExtractor::class)->extract($path, $this->root . '/out');
        $this->assertSame(5, $files[0]['size']);
        $this->assertSame('hello', file_get_contents($this->root . '/out/folder/test.txt'));
        $this->assertFileDoesNotExist($this->root . '/out/unlisted.txt');
    }

    public function test_archive_traversal_is_rejected_before_any_extraction(): void
    {
        $path = $this->archive(['../outside.txt' => 'no', 'ok.txt' => 'ok'], [['path' => 'ok.txt', 'originalName' => 'ok.txt']]);
        $this->expectException(RuntimeException::class);
        app(BundleExtractor::class)->extract($path, $this->root . '/out');
    }

    public function test_uncompressed_share_limit_is_enforced_even_if_manifest_lies(): void
    {
        $path = $this->archive(['large.txt' => str_repeat('x', 10000)], [
            ['path' => 'large.txt', 'originalName' => 'large.txt', 'size' => 1],
        ]);
        $this->expectException(RuntimeException::class);
        app(BundleExtractor::class)->extract($path, $this->root . '/out', 1024);
    }

    public function test_archive_ratio_is_limited(): void
    {
        config(['erugo.bundle_max_ratio' => 2]);
        $path = $this->archive(['large.txt' => str_repeat('x', 10000)], [
            ['path' => 'large.txt', 'originalName' => 'large.txt'],
        ]);
        $this->expectException(RuntimeException::class);
        app(BundleExtractor::class)->extract($path, $this->root . '/out');
    }

    public function test_symlinks_are_rejected(): void
    {
        $path = $this->archive(['link.txt' => '/etc/passwd'], [['path' => 'link.txt', 'originalName' => 'link.txt']]);
        $zip = new ZipArchive();
        $zip->open($path);
        $zip->setExternalAttributesName('link.txt', ZipArchive::OPSYS_UNIX, 0120777 << 16);
        $zip->close();
        $this->expectException(RuntimeException::class);
        app(BundleExtractor::class)->extract($path, $this->root . '/out');
    }

    public function test_archive_entry_count_is_limited(): void
    {
        config(['erugo.bundle_max_files' => 1]);
        $path = $this->archive(['a' => 'a', 'b' => 'b'], [['path' => 'a', 'originalName' => 'a']]);
        $this->expectException(RuntimeException::class);
        app(BundleExtractor::class)->extract($path, $this->root . '/out');
    }
}
