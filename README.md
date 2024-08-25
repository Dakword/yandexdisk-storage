## YandexDisk Storage filesystem for Flysystem

### Installation
```php
composer require dakword/yandexdisk-storage
```
### Usage
```php
$config = [
    // option => default
    'visibility' => 'private', // 'public', 'private'
    'retain_visibility' => true,
    'checksum_algo' => 'md5', // 'md5', 'sha256'
];

$client = new Arhitector\Yandex\Disk('oauth-token');
$adapter = new YandexDiskStorageAdapter($client, $prefix = '/', $config);

$yandexDiskStorage = new League\Flysystem\Filesystem($adapter);

try {
    $content = $yandexDiskStorage->read('images/file.jpg');
} catch (FilesystemException | UnableToReadFile $exception) {
    // handle the error
}
```
### Laravel Integration
```php
Dakword\YandexDiskStorage\YandexDiskServiceProvider::class

// config/filesystems.php
'yandex' => [
    'driver' => 'yandex-disk',
    'token' => env('YANDEX_DISK_OAUTH_TOKEN'),
    'prefix' => env('YANDEX_DISK_BASE_PATH', '/'),
    // option => default
    'visibility' => 'private', // 'public', 'private'
    'retain_visibility' => true,
    'checksum_algo' => 'md5', // 'md5', 'sha256'
],

// Usage
// --------------------------------------
$yandexDiskStorage = Storage::disk('yandex');

// create a file
$yandexDiskStorage->put('images/file.jpg', $imageContents);
// check exists
$exists = $yandexDiskStorage->exists('images/file.jpg');
```
### Links
* [Flysystem](https://flysystem.thephpleague.com/docs/)
* [YandexDisk SDK](https://github.com/jack-theripper/yandex)