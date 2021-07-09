<?php

use League\Flysystem\FilesystemAdapter;
use League\Flysystem\UnableToDeleteFile;
use League\Flysystem\UnableToRetrieveMetadata;
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

        parent::overwriting_a_file();
    }

    /**
     * @test
     */
    public function setting_visibility(): void
    {
        $this->expectException(UnableToRetrieveMetadata::class);

        parent::setting_visibility();
    }

    /**
     * @test
     */
    public function copying_a_file(): void
    {
        $this->expectException(UnableToRetrieveMetadata::class);

        parent::copying_a_file();
    }

    /**
     * @test
     */
    public function copying_a_file_again(): void
    {
        $this->expectException(UnableToRetrieveMetadata::class);

        parent::copying_a_file_again();
    }

    /**
     * @test
     */
    public function moving_a_file(): void
    {
        $this->expectException(UnableToRetrieveMetadata::class);

        parent::moving_a_file();
    }

    /**
     * @test
     */
    public function trying_to_delete_a_non_existing_file(): void
    {
        $this->expectException(UnableToDeleteFile::class);

        parent::trying_to_delete_a_non_existing_file();
    }
}
