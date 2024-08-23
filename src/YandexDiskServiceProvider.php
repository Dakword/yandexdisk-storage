<?php

namespace Dakword\YandexDiskStorage;

use Arhitector\Yandex\Disk;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\ServiceProvider;
use League\Flysystem\Filesystem;
use League\Flysystem\Config;

class YandexDiskServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Storage::extend('yandex-disk', function (Application $app, $config) {
            $client = new Disk($config['token'] ?? '');
            $adapter = new YandexDiskStorageAdapter(
                $client,
                $config['prefix'] ?? '/',
                (new Config($config))->withoutSettings('driver', 'token', 'prefix')->toArray()
            );
            $driver = new Filesystem($adapter);

            return new FilesystemAdapter($driver, $adapter);
        });
    }
}