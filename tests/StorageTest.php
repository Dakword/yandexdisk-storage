<?php

namespace Dakword\YandexDiskStorage\Tests;

use Dakword\YandexDiskStorage\YandexDiskServiceProvider;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\File;
use Illuminate\Support\Facades\Storage;
use Orchestra\Testbench\TestCase;

class StorageTest extends TestCase
{
    const TESTFILE = __DIR__ . '/files/TestFile.txt';
    protected Filesystem $disk;
    protected array $config;

    protected function getPackageProviders($app): array
    {
        return [
            YandexDiskServiceProvider::class,
        ];
    }

    public function setUp(): void
    {
        if (file_exists(__DIR__ . '/../../tests-config.php')) {
            $this->config = require(__DIR__ . '/../../tests-config.php');
        } elseif (file_exists(__DIR__ . '/config/config.php')) {
            $this->config = require(__DIR__ . '/config/config.php');
        } else {
            $this->config = [
                'oauth-token' => '',
                'prefix' => '/',
            ];
        }
        if (!$this->config || empty($this->config['oauth-token'])) {
            $this->markTestSkipped('OAuth token empty');
        }

        parent::setUp();
        $this->disk = Storage::build([
            'driver' => 'yandex-disk',
            'token' => $this->config['oauth-token'],
            'prefix' => $this->config['prefix'],
        ]);
    }

    protected function deleteTestDirectory($path): void
    {
        $this->disk->deleteDirectory($path);

        if (strlen($this->config['prefix']) > 0 && $this->config['prefix'] !== '/') {
            sleep(3);
            $this->disk->deleteDirectory('/');
        }
    }

    protected function createFromTestFile(string $path): void
    {
        $localTestFile = self::TESTFILE;
        $file = fopen($localTestFile, 'r');
        $this->disk->writeStream($path, $file);
        fclose($file);
    }

    protected function getTestFileContent(): string
    {
        $file = fopen(self::TESTFILE, 'r');
        $content = fread($file, filesize(self::TESTFILE));
        fclose($file);
        return $content;
    }

    public function test_directories()
    {
        $directory = 'testDirectories';
        $subDirectory = $directory . '/subDirectory';
        $this->disk->makeDirectory($directory);
        $this->disk->makeDirectory($subDirectory);

        $this->assertTrue($this->disk->exists($directory));
        $this->assertTrue($this->disk->directoryExists($subDirectory));

        $this->assertCount(1, $this->disk->directories($directory));
        $this->assertCount(2, $this->disk->directories('/', true));
        $this->assertCount(2, $this->disk->allDirectories('/'));

        $this->disk->deleteDirectory($subDirectory);
        $this->assertFalse($this->disk->directoryExists($subDirectory));

        $this->deleteTestDirectory($directory);
    }

    public function test_files()
    {
        $directory = 'testFiles';
        $subDirectory = $directory . '/subDirectory';
        $file1 = $directory . '/test-1.txt';
        $file2 = $subDirectory . '/test-2.txt';

        $this->disk->makeDirectory($subDirectory);

        $this->createFromTestFile($file1);
        $this->createFromTestFile($file2);

        $this->assertTrue($this->disk->exists($file1));
        $this->assertTrue($this->disk->fileExists($file2));

        $this->assertCount(1, $this->disk->files($directory));
        $this->assertCount(2, $this->disk->files($directory, true));
        $this->assertCount(2, $this->disk->allFiles('/'));

        $this->disk->delete($file2);
        $this->assertFalse($this->disk->fileExists($file2));
        $this->disk->delete([$file1]);
        $this->assertFalse($this->disk->fileExists($file1));

        $this->deleteTestDirectory($directory);
    }

    public function test_rw()
    {
        $directory = 'testFiles';
        $subDirectory = $directory . '/subDirectory';
        $file1 = $directory . '/test-1.txt';
        $file2 = $subDirectory . '/test-2.txt';

        $this->disk->put($file1, 'content');
        $this->assertEquals('content', $this->disk->get($file1));

        $stream = fopen(self::TESTFILE, 'r');
        $this->disk->put($file2, $stream);
        fclose($stream);
        $this->assertEquals(file_get_contents(self::TESTFILE), $this->disk->get($file2));

        $this->disk->putFileAs($directory, new File(self::TESTFILE), 'file.txt');
        $this->assertTrue($this->disk->fileExists($directory . '/file.txt'));

        $this->deleteTestDirectory($directory);
    }

    public function test_meta()
    {
        $directory = 'testMeta';
        $file = $directory . '/test.txt';

        $this->createFromTestFile($file);

        $this->assertEquals(filesize(self::TESTFILE), $this->disk->size($file));
        $this->assertEquals('text/plain', $this->disk->mimeType($file));
        $this->assertEquals(date('Y-m-d'), date('Y-m-d', $this->disk->lastModified($file)));
        $this->assertEquals($file, $this->disk->path($file));

        $this->deleteTestDirectory($directory);
    }

    public function test_visibility()
    {
        $directory = 'testVisibility';
        $file = $directory . '/test.txt';

        $this->disk->put($file, 'content', 'public');
        sleep(3);
        $this->assertEquals('public', $this->disk->getVisibility($file));

        $this->disk->setVisibility($file, 'private');
        sleep(3);
        $this->assertEquals('private', $this->disk->getVisibility($file));

        $this->deleteTestDirectory($directory);
    }

    public function test_copymove()
    {
        $directory = 'testCopyMove';
        $file = $directory . '/file.txt';
        $copy = $directory . '/subDirectory/copy.txt';
        $this->createFromTestFile($file);

        $this->assertTrue($this->disk->fileExists($file));
        $this->disk->move($file, $copy);
        $this->assertTrue($this->disk->fileExists($copy));

        $this->disk->delete($file);
        $this->assertFalse($this->disk->fileExists($file));
        $this->disk->copy($copy, $file);
        $this->assertTrue($this->disk->fileExists($file));

        $this->deleteTestDirectory($directory);
    }

}
