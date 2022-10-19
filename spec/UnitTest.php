<?php

use League\Flysystem\ChecksumAlgoIsNotSupported;
use League\Flysystem\Config;
use League\Flysystem\FilesystemAdapter;
use League\Flysystem\UnableToDeleteFile;
use League\Flysystem\UnableToRetrieveMetadata;
use League\Flysystem\UrlGeneration\PublicUrlGenerator;
use Zaxbux\BackblazeB2\Client;
use Zaxbux\Flysystem\BackblazeB2Adapter;

class UnitTest extends \League\Flysystem\AdapterTestUtilities\FilesystemAdapterTestCase
{
    protected static function createFilesystemAdapter(): FilesystemAdapter
    {
        return new BackblazeB2Adapter(
            new Client([
                'applicationKeyId' => $_ENV['B2_APPLICATION_KEY_ID'],
                'applicationKey' => $_ENV['B2_APPLICATION_KEY'],
            ]),
            $_ENV['B2_BUCKET_ID']
        );
    }

    /**
     * @test
     */
    public function overwriting_a_file(): void
    {
        $this->expectException(UnableToRetrieveMetadata::class);
        $this->expectExceptionMessage('object-level ACLs are not supported');

        parent::overwriting_a_file();
    }

    /**
     * @test
     */
    public function setting_visibility(): void
    {
        $this->expectException(UnableToRetrieveMetadata::class);
        $this->expectExceptionMessage('object-level ACLs are not supported');

        parent::setting_visibility();
    }

    /**
     * @test
     */
    public function copying_a_file(): void
    {
        $this->runScenario(function () {
            $adapter = $this->adapter();
            $adapter->write(
                'source.txt',
                'contents to be copied',
                new Config([])
            );

            $adapter->copy('source.txt', 'destination.txt', new Config());

            $this->assertTrue($adapter->fileExists('source.txt'));
            $this->assertTrue($adapter->fileExists('destination.txt'));
            //$this->assertEquals(Visibility::PUBLIC, $adapter->visibility('destination.txt')->visibility());
            $this->assertEquals('contents to be copied', $adapter->read('destination.txt'));
        });
    }

    /**
     * @test
     */
    public function copying_a_file_again(): void
    {
        $this->runScenario(function () {
            $adapter = $this->adapter();
            $adapter->write(
                'source.txt',
                'contents to be copied',
                new Config([])
            );

            $adapter->copy('source.txt', 'destination.txt', new Config());

            $this->assertTrue($adapter->fileExists('source.txt'));
            $this->assertTrue($adapter->fileExists('destination.txt'));
            //$this->assertEquals(Visibility::PUBLIC, $adapter->visibility('destination.txt')->visibility());
            $this->assertEquals('contents to be copied', $adapter->read('destination.txt'));
        });
    }

    /**
     * @test
     */
    public function moving_a_file(): void
    {
        $this->runScenario(function () {
            $adapter = $this->adapter();
            $adapter->write(
                'source.txt',
                'contents to be copied',
                new Config([/* Config::OPTION_VISIBILITY => Visibility::PUBLIC */])
            );
            $adapter->move('source.txt', 'destination.txt', new Config());
            $this->assertFalse(
                $adapter->fileExists('source.txt'),
                'After moving a file should no longer exist in the original location.'
            );
            $this->assertTrue(
                $adapter->fileExists('destination.txt'),
                'After moving, a file should be present at the new location.'
            );
            /* $this->assertEquals(Visibility::PUBLIC, $adapter->visibility('destination.txt')->visibility()); */
            $this->assertEquals('contents to be copied', $adapter->read('destination.txt'));
        });
    }

    /**
     * @test
     */
    public function fetching_last_modified(): void
    {
        // This exception is expected since the last modified timestamp must be explicitly set when uploading a file.
        $this->expectException(UnableToRetrieveMetadata::class);

        parent::fetching_last_modified();
    }

    /**
     * @test
     */
    public function generating_a_public_url(): void
    {
        /** @var BackblazeB2Adapter $adapter */
        $adapter = $this->adapter();

        $adapter->write('some/path.txt', 'public contents', new Config(/* ['visibility' => 'public'] */));

        $url = $adapter->publicUrl('some/path.txt', new Config([
            'valid_duration' => 10,
        ]));
        $contents = file_get_contents($url);

        self::assertEquals('public contents', $contents);
    }

    /**
     * @test
     */
    public function expired_download_authorization(): void
    {
        /** @var BackblazeB2Adapter $adapter */
        $adapter = $this->adapter();

        $adapter->write('some/path.txt', 'public contents', new Config());

        $url = $adapter->publicUrl('some/path.txt', new Config([
            'valid_duration' => 5,
        ]));

        // Wait until after authorization expires
        sleep(6);

        self::assertFalse(@file_get_contents($url));
    }

    /**
     * @test
     */
    public function specifying_a_custom_checksum_algo_is_not_supported(): void
    {
        /** @var BackblazeB2Adapter $adapter */
        $adapter = $this->adapter();

        $adapter->write('some/path.txt', 'public contents', new Config());

        $this->expectException(ChecksumAlgoIsNotSupported::class);

        $adapter->checksum('some/path.txt', new Config(['checksum_algo' => 'crc']));
    }
}
