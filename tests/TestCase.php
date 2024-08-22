<?php

namespace Dakword\YandexDiskStorage\Tests;

use PHPUnit\Framework\TestCase as BaseTestCase;
use Arhitector\Yandex\Disk as Client;
use Dakword\YandexDiskStorage\YandexDiskStorageAdapter;

abstract class TestCase extends BaseTestCase
{
    protected array $config;

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
    }

    protected function skipIfTokenEmpty(): void
    {
        if (empty($this->config['oauth-token'])) {
            $this->markTestSkipped('OAuth token empty');
        }
    }

    protected function getAdapterInstance(): YandexDiskStorageAdapter
    {
        return new YandexDiskStorageAdapter(
            new Client($this->config['oauth-token']),
            $this->config['prefix']
        );
    }
}