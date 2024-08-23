<?php

namespace Dakword\YandexDiskStorage\Tests;

use Arhitector\Yandex\Disk;
use Dakword\YandexDiskStorage\YandexDiskStorageAdapter;
use League\Flysystem\ChecksumAlgoIsNotSupported;
use League\Flysystem\Config;
use League\Flysystem\DirectoryAttributes;
use League\Flysystem\FileAttributes;
use League\Flysystem\UnableToGeneratePublicUrl;
use League\Flysystem\UnableToReadFile;
use League\Flysystem\Visibility;

class AdapterTest extends TestCase
{
    const TESTFILE = __DIR__ . '/files/TestFile.txt';
    protected YandexDiskStorageAdapter $adapter;

    public function setUp(): void
    {
        parent::setUp();
        $this->adapter = $this->getAdapterInstance();
    }

    protected function createFromTestFile(string $path): void
    {
        $localTestFile = self::TESTFILE;
        $file = fopen($localTestFile, 'r');
        $this->adapter->writeStream($path, $file, new Config());
        fclose($file);
    }

    protected function getTestFileContent(): string
    {
        $file = fopen(self::TESTFILE, 'r');
        $content = fread($file, filesize(self::TESTFILE));
        fclose($file);
        return $content;
    }

    protected function deleteTestDirectory($path): void
    {
        $this->adapter->deleteDirectory($path);

        if (strlen($this->config['prefix']) > 0 && $this->config['prefix'] !== '/') {
            sleep(5);
            $this->adapter->deleteDirectory('/');
        }
    }

    public function test_class()
    {
        $this->assertInstanceOf(YandexDiskStorageAdapter::class, $this->adapter);
        $this->assertInstanceOf(Disk::class, $this->adapter->getClient());
    }

    public function test_directories()
    {
        $this->skipIfTokenEmpty();

        $path = 'test_directories/subDirectory';
        $this->adapter->createDirectory($path, new Config());

        $this->assertTrue($this->adapter->directoryExists($path));

        $this->adapter->deleteDirectory($path);
        sleep(2);
        $this->assertFalse($this->adapter->directoryExists($path));

        $this->adapter->deleteDirectory(dirname($path));
        sleep(2);
        $this->assertFalse($this->adapter->directoryExists(dirname($path)));
    }

    public function test_delete()
    {
        $this->skipIfTokenEmpty();

        $path = 'test_delete/delete.zip';
        $this->createFromTestFile($path);

        $this->adapter->delete($path);

        $this->assertFalse($this->adapter->fileExists($path));

        $this->deleteTestDirectory(dirname($path));
    }

    public function test_writeStream()
    {
        $this->skipIfTokenEmpty();

        $fileSize = filesize(self::TESTFILE);

        $path = 'test_writeStream/file.txt';
        $file = fopen(self::TESTFILE, 'r');
        $this->adapter->writeStream($path, $file, new Config());
        fseek($file, 0);
        $fileContent = fread($file, $fileSize);
        fclose($file);

        $this->assertTrue($this->adapter->fileExists($path));
        $stream = $this->adapter->readStream($path);
        $this->assertIsResource($stream);

        $streamContent = fread($stream, $fileSize);
        fclose($stream);
        $this->assertEquals($fileContent, $streamContent);

        $this->deleteTestDirectory(dirname($path));
    }

    public function test_write()
    {
        $this->skipIfTokenEmpty();

        $path = 'test_write/file.txt';
        $content = 'test content';

        $this->adapter->write($path, $content, new Config());

        $this->assertTrue($this->adapter->fileExists($path));
        $this->assertEquals($content, $this->adapter->read($path));

        $this->deleteTestDirectory(dirname($path));
    }

    public function test_visibility()
    {
        $this->skipIfTokenEmpty();

        $path = 'test_visibility/file.txt';
        $this->createFromTestFile($path);

        $this->assertEquals(Visibility::PRIVATE, $this->adapter->visibility($path)->visibility());

        $this->adapter->setVisibility($path, Visibility::PUBLIC);

        $this->assertEquals(Visibility::PUBLIC, $this->adapter->visibility($path)->visibility());

        $this->deleteTestDirectory(dirname($path));
    }

    public function test_metadata()
    {
        $this->skipIfTokenEmpty();

        $path = 'test_metadata/file.txt';
        $this->createFromTestFile($path);

        $metadataResourceId = $this->adapter->getMetadata($path)->extraMetadata()['resource_id'];
        $this->assertEquals($metadataResourceId, $this->adapter->mimeType($path)->extraMetadata()['resource_id']);
        $this->assertEquals($metadataResourceId, $this->adapter->lastModified($path)->extraMetadata()['resource_id']);
        $this->assertEquals($metadataResourceId, $this->adapter->fileSize($path)->extraMetadata()['resource_id']);

        $this->deleteTestDirectory(dirname($path));
    }

    public function test_listContent()
    {
        $this->skipIfTokenEmpty();

        $directory = 'test_listContent/subDirectory';
        $path = 'test_listContent/file.txt';

        $this->adapter->createDirectory($directory, new Config());
        $this->createFromTestFile($path);

        $listContent = iterator_to_array($this->adapter->listContents(dirname($path), true));

        $this->assertCount(2, $listContent);

        [$fileIndex, $directoryIndex] = $listContent[0]->isFile() ? [0, 1] : [1, 0];
        $subDirectory = $listContent[$directoryIndex];
        $file = $listContent[$fileIndex];

        $this->assertInstanceOf(DirectoryAttributes::class, $subDirectory);
        $this->assertInstanceOf(FileAttributes::class, $file);

        $this->deleteTestDirectory(dirname($path));
    }

    public function test_copy()
    {
        $this->skipIfTokenEmpty();

        $source = 'test_copy/source.txt';
        $destination = 'test_copy/destination.txt';

        $this->createFromTestFile($source);
        $this->adapter->copy($source, $destination, new Config());

        $this->assertTrue($this->adapter->fileExists($destination));
        $this->assertEquals($this->getTestFileContent(), $this->adapter->read($destination));

        $this->deleteTestDirectory(dirname($source));
    }

    public function test_move()
    {
        $this->skipIfTokenEmpty();

        $source = 'test_move/source.txt';
        $destination = 'test_move/destination.txt';

        $this->createFromTestFile($source);
        $this->adapter->move($source, $destination, new Config());

        $this->assertTrue($this->adapter->fileExists($destination));
        $this->assertEquals($this->getTestFileContent(), $this->adapter->read($destination));

        $this->deleteTestDirectory(dirname($destination));
    }

    public function test_getUrl()
    {
        $this->skipIfTokenEmpty();
        $path = 'test_getUrl/file.txt';
        $this->createFromTestFile($path);

        $downloadLink = $this->adapter->getUrl($path);
        $this->assertStringContainsString('https://', $downloadLink);

        $this->deleteTestDirectory(dirname($path));

        $this->expectException(UnableToReadFile::class);
        $this->adapter->getUrl('file-not-found.foo');
    }

    public function test_publicUrl()
    {
        $this->skipIfTokenEmpty();

        $path = 'test_publicUrl';
        $file1 = $path . '/file_1.txt';
        $file2 = $path . '/file_2.txt';
        $this->createFromTestFile($file1);
        $this->createFromTestFile($file2);
        sleep(2);

        $autoPublicUrl = $this->adapter->publicUrl($file1, new Config());
        $this->assertStringContainsString('https://', $autoPublicUrl);

        $this->adapter->setVisibility($file2, Visibility::PUBLIC);
        $publicUrl = $this->adapter->publicUrl($file2, new Config());
        $this->assertStringContainsString('https://', $publicUrl);

        $this->deleteTestDirectory($path);

        $this->expectException(UnableToGeneratePublicUrl::class);
        $this->adapter->publicUrl('file-not-exist.foo', new Config());
    }

    public function test_checksum()
    {
        $this->skipIfTokenEmpty();

        $path = 'test_checksum/file.txt';
        $this->createFromTestFile($path);

        $checksum = $this->adapter->checksum($path, new Config());
        $this->assertEquals(md5_file(self::TESTFILE), $checksum);

        $checksum = $this->adapter->checksum($path, new Config(['checksum_algo' => 'sha256']));
        $this->assertEquals(hash_file('sha256', self::TESTFILE), $checksum);

        $this->deleteTestDirectory(dirname($path));

        $this->expectException(ChecksumAlgoIsNotSupported::class);
        $this->adapter->checksum($path, new Config(['checksum_algo' => 'crc32']));
    }
}