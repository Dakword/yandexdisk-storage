<?php

declare(strict_types=1);

namespace Dakword\YandexDiskStorage;

use Arhitector\Yandex\Disk;
use Arhitector\Yandex\Disk\Resource\Closed;
use DateTime;
use GuzzleHttp\Psr7\Request;
use League\Flysystem\ChecksumAlgoIsNotSupported;
use League\Flysystem\ChecksumProvider;
use League\Flysystem\Config;
use League\Flysystem\DirectoryAttributes;
use League\Flysystem\FileAttributes;
use League\Flysystem\FilesystemAdapter;
use League\Flysystem\InvalidVisibilityProvided;
use League\Flysystem\PathPrefixer;
use League\Flysystem\StorageAttributes;
use League\Flysystem\UnableToCopyFile;
use League\Flysystem\UnableToCreateDirectory;
use League\Flysystem\UnableToDeleteDirectory;
use League\Flysystem\UnableToDeleteFile;
use League\Flysystem\UnableToGeneratePublicUrl;
use League\Flysystem\UnableToMoveFile;
use League\Flysystem\UnableToProvideChecksum;
use League\Flysystem\UnableToReadFile;
use League\Flysystem\UnableToRetrieveMetadata;
use League\Flysystem\UnableToSetVisibility;
use League\Flysystem\UnableToWriteFile;
use League\Flysystem\UrlGeneration\PublicUrlGenerator;
use League\Flysystem\Visibility;
use RuntimeException;
use Throwable;

class YandexDiskStorageAdapter implements FilesystemAdapter, PublicUrlGenerator, ChecksumProvider
{
    private Disk $client;
    private PathPrefixer $prefixer;
    private Config $options;

    public function __construct(Disk $client, string $prefix = '/', array $config = [])
    {
        $this->client = $client;
        $this->prefixer = new PathPrefixer($prefix);
        $this->prepareConfig((new Config($config)));
    }

    private function prepareConfig(Config $config): void
    {
        $this->options = new Config([
            Config::OPTION_VISIBILITY => $config->get(Config::OPTION_VISIBILITY, Visibility::PRIVATE),
            Config::OPTION_RETAIN_VISIBILITY => $config->get(Config::OPTION_RETAIN_VISIBILITY, true),
            'checksum_algo' => $config->get('checksum_algo', 'md5'),
        ]);
    }

    /**
     * @inheritdoc
     */
    public function fileExists(string $path): bool
    {
        $resource = $this->client->getResource($this->prefixer->prefixPath($path));
        return $resource->has() && $resource->isFile();
    }

    /**
     * @inheritdoc
     */
    public function directoryExists(string $path): bool
    {
        $resource = $this->client->getResource($this->prefixer->prefixPath($path));
        return $resource->has() && $resource->isDir();
    }

    /**
     * @inheritdoc
     */
    public function write(string $path, string $contents, Config $config): void
    {
        try {
            $stream = fopen('php://temp', 'w+b');
            if (fwrite($stream, $contents) === false) {
                throw new RuntimeException('Can not create "php://temp" stream.');
            }
            fseek($stream, 0);

            try {
                $this->createPathRecursive($this->prefixer->prefixPath(dirname($path)));
                $resource = $this->client->getResource($this->prefixer->prefixPath($path));
                $result = $resource->upload($stream, true);
            } catch (Throwable $exception) {
                fclose($stream);
                throw $exception;
            }
            fclose($stream);

            if (!$result) return;

        } catch (Throwable $exception) {
            throw UnableToWriteFile::atLocation($path, $exception->getMessage(), $exception);
        }

        $visibility = (string)$config->get(Config::OPTION_VISIBILITY, $this->options->get(Config::OPTION_VISIBILITY));
        if ($visibility === Visibility::PUBLIC) {
            $this->setVisibility($path, Visibility::PUBLIC);
        }
    }

    /**
     * @inheritdoc
     */
    public function writeStream(string $path, $contents, Config $config): void
    {
        try {
            $this->createPathRecursive($this->prefixer->prefixPath(dirname($path)));
            $resource = $this->client->getResource($this->prefixer->prefixPath($path));
            $result = $resource->upload($contents, true);

            if (!$result) return;

        } catch (Throwable $exception) {
            throw UnableToWriteFile::atLocation($path, $exception->getMessage(), $exception);
        }

        $visibility = (string)$config->get('visibility', $this->options->get('visibility'));
        if ($visibility === Visibility::PUBLIC) {
            $this->setVisibility($path, Visibility::PUBLIC);
        }
    }

    /**
     * @inheritdoc
     */
    public function read(string $path): string
    {
        try {
            $resource = $this->client->getResource($this->prefixer->prefixPath($path));
            $fileUrl = $resource->getLink();

            $response = $this->client->send(new Request('GET', $fileUrl));
            if ($response->getStatusCode() == 200) {
                return $response->getBody()->getContents();
            } else {
                throw UnableToReadFile::fromLocation($path, 'Downloaded url does not contain a file.');
            }
        } catch (Throwable $exception) {
            throw UnableToReadFile::fromLocation($path, $exception->getMessage(), $exception);
        }
    }

    /**
     * @inheritdoc
     */
    public function readStream(string $path)
    {
        try {
            $resource = $this->client->getResource($this->prefixer->prefixPath($path));
            $fileUrl = $resource->getLink();
            $response = $this->client->send(new Request('GET', $fileUrl));
            if ($response->getStatusCode() == 200) {
                return $response->getBody()->detach();
            } else {
                throw UnableToReadFile::fromLocation($path, 'Downloaded url does not contain a file resource.');
            }
        } catch (Throwable $exception) {
            throw UnableToReadFile::fromLocation($path, $exception->getMessage(), $exception);
        }
    }

    /**
     * @inheritdoc
     */
    public function delete(string $path): void
    {
        try {
            $resource = $this->client->getResource($this->prefixer->prefixPath($path));
            if (!$resource->has() || !$resource->isFile()) {
                return;
            }
            $resource->delete(true);
        } catch (Throwable $exception) {
            throw UnableToDeleteFile::atLocation($path, $exception->getMessage(), $exception);
        }
    }

    /**
     * @inheritdoc
     */
    public function deleteDirectory(string $path): void
    {
        try {
            $resource = $this->client->getResource($this->prefixer->prefixPath($path));
            if (!$resource->has() || !$resource->isDir()) {
                return;
            }
            $resource->delete(true);
        } catch (Throwable $exception) {
            throw UnableToDeleteDirectory::atLocation($path, $exception->getMessage(), $exception);
        }
    }

    /**
     * @inheritdoc
     */
    public function createDirectory(string $path, Config $config): void
    {
        try {
            $this->createPathRecursive($this->prefixer->prefixPath($path));
        } catch (Throwable $exception) {
            throw UnableToCreateDirectory::atLocation($path, $exception->getMessage(), $exception);
        }
    }

    /**
     * @inheritdoc
     */
    public function setVisibility(string $path, string $visibility): void
    {
        if (!in_array($visibility, [Visibility::PUBLIC, Visibility::PRIVATE])) {
            throw InvalidVisibilityProvided::withVisibility($visibility, Visibility::PUBLIC . '/' . Visibility::PRIVATE);
        }
        try {
            $resource = $this->client->getResource($this->prefixer->prefixPath($path));
            if (!$resource->has()) {
                throw UnableToSetVisibility::atLocation($path, 'Resource not exists');
            }
            $resource->setPublish($visibility == Visibility::PUBLIC);
        } catch (Throwable $exception) {
            throw UnableToSetVisibility::atLocation($path, $exception->getMessage(), $exception);
        }
    }

    /**
     * @inheritdoc
     */
    public function visibility(string $path): FileAttributes
    {
        $metadata = $this->getMetadata($path);
        if ($metadata->isDir()) {
            throw UnableToRetrieveMetadata::visibility($path, 'Is not a file');
        }
        return $metadata;
    }

    /**
     * @inheritdoc
     */
    public function mimeType(string $path): FileAttributes
    {
        $metadata = $this->getMetadata($path);
        if ($metadata->isDir()) {
            throw UnableToRetrieveMetadata::mimeType($path, 'Is not a file');
        }
        if (!$metadata->mimeType()) {
            throw UnableToRetrieveMetadata::mimeType($path, 'Unknown MIME type');
        }
        return $metadata;
    }

    /**
     * @inheritdoc
     */
    public function lastModified(string $path): FileAttributes
    {
        $metadata = $this->getMetadata($path);
        if ($metadata->isDir()) {
            throw UnableToRetrieveMetadata::lastModified($path, 'Is not a file');
        }
        return $metadata;
    }

    /**
     * @inheritdoc
     */
    public function fileSize(string $path): FileAttributes
    {
        $metadata = $this->getMetadata($path);
        if ($metadata->isDir()) {
            throw UnableToRetrieveMetadata::fileSize($path, 'Is not a file');
        }
        return $metadata;
    }

    /**
     * @inheritdoc
     */
    public function listContents(string $path, bool $deep): iterable
    {
        try {
            $resource = $this->client->getResource($this->prefixer->prefixPath($path), 10000);
            if ($resource->has() && $resource->isDir()) {
                foreach ($resource->items as $item) {
                    /* @var Closed $item */
                    if ($item->isDir()) {
                        yield new DirectoryAttributes(
                            $path . '/' . $item->name,
                            $item->isPublish() ? Visibility::PUBLIC : Visibility::PRIVATE,
                            (new DateTime($item->modified))->getTimestamp(),
                            $item->toArray()
                        );
                    } else {
                        yield FileAttributes::fromArray([
                            StorageAttributes::ATTRIBUTE_PATH => $path . '/' . $item->name,
                            StorageAttributes::ATTRIBUTE_LAST_MODIFIED => DateTime::createFromFormat("Y-m-d\TH:i:sP", $item->modified)->getTimestamp(),
                            StorageAttributes::ATTRIBUTE_FILE_SIZE => $item->size,
                            StorageAttributes::ATTRIBUTE_VISIBILITY => $item->isPublish() ? Visibility::PUBLIC : Visibility::PRIVATE,
                            StorageAttributes::ATTRIBUTE_MIME_TYPE => $item->mime_type,
                            StorageAttributes::ATTRIBUTE_EXTRA_METADATA => $item->toArray(),
                        ]);
                    }
                    if ($item->isDir() && $deep) {
                        foreach ($this->listContents($path . '/' . $item->name, true) as $child) {
                            yield $child;
                        }
                    }
                }
            }
        } catch (Throwable) {
        }
    }

    /**
     * @inheritdoc
     */
    public function move(string $source, string $destination, Config $config): void
    {
        try {
            $resource = $this->client->getResource($this->prefixer->prefixPath($source));
            if (!$resource->has()) {
                throw UnableToMoveFile::because('Source file does not exists', $source, $destination);
            }

            $visibility = $config->get(Config::OPTION_VISIBILITY) ??
                $config->get(Config::OPTION_RETAIN_VISIBILITY, $this->options->get(Config::OPTION_RETAIN_VISIBILITY));

            $this->createPathRecursive($this->prefixer->prefixPath(dirname($destination)));
            $resource->move($this->prefixer->prefixPath($destination), true);

        } catch (Throwable $exception) {
            throw UnableToMoveFile::fromLocationTo($source, $destination, $exception);
        }

        if ($visibility) {
            $this->setVisibility($destination, Visibility::PUBLIC);
        }
    }

    /**
     * @inheritdoc
     */
    public function copy(string $source, string $destination, Config $config): void
    {
        try {
            $resource = $this->client->getResource($this->prefixer->prefixPath($source));
            if (!$resource->has()) {
                throw UnableToCopyFile::because('Source file does not exists', $source, $destination);
            }

            $visibility = $config->get(Config::OPTION_VISIBILITY) ??
                $config->get(Config::OPTION_RETAIN_VISIBILITY, $this->options->get(Config::OPTION_RETAIN_VISIBILITY));

            $this->createPathRecursive($this->prefixer->prefixPath(dirname($destination)));
            $resource->copy($this->prefixer->prefixPath($destination), true);

        } catch (Throwable $exception) {
            throw UnableToCopyFile::fromLocationTo($source, $destination, $exception);
        }

        if ($visibility) {
            $this->setVisibility($destination, Visibility::PUBLIC);
        }
    }

    public function getMetadata($path): DirectoryAttributes|FileAttributes
    {
        try {
            $resource = $this->client->getResource($this->prefixer->prefixPath($path));
            if (!$resource->has()) {
                throw UnableToRetrieveMetadata::create($path, 'metadata', 'Resource not exists');
            }

            if ($resource->isDir()) {
                return new DirectoryAttributes(
                    $path,
                    $resource->isPublish() ? Visibility::PUBLIC : Visibility::PRIVATE,
                    DateTime::createFromFormat("Y-m-d\TH:i:sP", $resource->modified)->getTimestamp(),
                    $resource->toArray()
                );
            }

            return FileAttributes::fromArray([
                StorageAttributes::ATTRIBUTE_PATH => $path,
                StorageAttributes::ATTRIBUTE_FILE_SIZE => $resource->size,
                StorageAttributes::ATTRIBUTE_VISIBILITY => $resource->isPublish() ? Visibility::PUBLIC : Visibility::PRIVATE,
                StorageAttributes::ATTRIBUTE_LAST_MODIFIED => DateTime::createFromFormat("Y-m-d\TH:i:sP", $resource->modified)->getTimestamp(),
                StorageAttributes::ATTRIBUTE_MIME_TYPE => $resource->mime_type,
                StorageAttributes::ATTRIBUTE_EXTRA_METADATA => $resource->toArray(),
            ]);
        } catch (Throwable $exception) {
            throw UnableToRetrieveMetadata::create($path, 'metadata', $exception->getMessage(), $exception);
        }
    }

    /**
     * @inheritdoc
     */
    public function publicUrl(string $path, Config $config): string
    {
        try {
            $resource = $this->client->getResource($this->prefixer->prefixPath($path));
            if (!$resource->has()) {
                throw UnableToReadFile::fromLocation($path, 'File not found');
            }

            if ($resource->isPublish()) {
                return $resource->public_url;
            } else {
                $this->setVisibility($path, Visibility::PUBLIC);
                return $this->client->getResource($this->prefixer->prefixPath($path))->public_url;
            }

        } catch (Throwable $exception) {
            throw UnableToGeneratePublicUrl::dueToError($path, $exception);
        }
    }

    /**
     * @inheritdoc
     */
    public function checksum(string $path, Config $config): string
    {
        $algo = (string)$config->get('checksum_algo', $this->options->get('checksum_algo'));
        if (!in_array($algo, ['md5', 'sha256']))
            throw new ChecksumAlgoIsNotSupported('Supported algorithms: md5, sha256');

        try {
            $resource = $this->client->getResource($this->prefixer->prefixPath($path));
            if (!$resource->has())
                throw UnableToReadFile::fromLocation($path, 'File not found');

            return match ($algo) {
                'md5' => $resource->md5,
                'sha256' => $resource->sha256,
            };

        } catch (Throwable $exception) {
            throw new UnableToProvideChecksum($path, $exception->getMessage(), $exception);
        }
    }

    /**
     * @throws UnableToReadFile
     */
    public function getUrl(string $path): string
    {
        $resource = $this->client->getResource($this->prefixer->prefixPath($path));
        if ($resource->has() && $resource->isFile()) {
            return $resource->getLink();
        }
        throw UnableToReadFile::fromLocation($path, 'File not found');
    }

    public function getClient(): Disk
    {
        return $this->client;
    }

    private function createPathRecursive($path): void
    {
        $currentDir = '/';
        foreach (array_filter(explode('/', $path), fn($item) => $item !== '.') as $dir) {
            $currentDir .= $dir . '/';
            $dir = $this->client->getResource($currentDir);
            if (!$dir->has()) {
                $dir->create();
            }
        }
    }
}