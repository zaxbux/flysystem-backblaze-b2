<?php

declare(strict_types=1);

namespace Zaxbux\Flysystem;

use Throwable;
use GuzzleHttp\Psr7\StreamWrapper;
use League\Flysystem\Config;
use League\Flysystem\DirectoryAttributes;
use League\Flysystem\FileAttributes;
use League\Flysystem\FilesystemAdapter;
use League\Flysystem\PathPrefixer;
use League\Flysystem\StorageAttributes;
use League\Flysystem\UnableToCopyFile;
use League\Flysystem\UnableToDeleteFile;
use League\Flysystem\UnableToMoveFile;
use League\Flysystem\UnableToReadFile;
use League\Flysystem\UnableToRetrieveMetadata;
use League\Flysystem\UnableToSetVisibility;
use Zaxbux\BackblazeB2\Client;
use Zaxbux\BackblazeB2\Exceptions\NoResultsException;
use Zaxbux\BackblazeB2\Helpers\UploadHelper;
use Zaxbux\BackblazeB2\Object\File;
use Zaxbux\BackblazeB2\Response\FileDownload;

/**
 * Flysystem Adapter for Backblaze B2 cloud storage.
 *
 * @author Zachary Schneider <hello@zacharyschneider.ca>
 * @package Zaxbux\Flysystem
 */
class BackblazeB2Adapter implements FilesystemAdapter
{
    /** @var Client */
    private $client;

    /** @var string */
    private $bucketId;

    /** @var PathPrefixer */
    private $prefixer;

    /**
     * 
     * @param Client      $client   Instance of B2 API client.
     * @param null|string $bucketId The ID of your B2 bucket. If not provided,
     *                              the bucket that the application key is restricted to will be used.
     * @param null|string $prefix   Optional file name prefix. If not provided,
     *                              the `namePrefix` that the application key is restricted to will be used.
     */
    public function __construct(
        Client $client,
        ?string $bucketId = null,
        ?string $prefix = null
    ) {
        $this->client = $client;
        $this->client->refreshAccountAuthorization();
        $this->bucketId = $bucketId ?? $client->accountAuthorization()->allowed('bucketId') ?? null;
        $this->prefixer = new PathPrefixer($prefix ?? $client->accountAuthorization()->allowed('namePrefix') ?? '');
    }

    /**
     * Check the existence of a file.
     * 
     * {@inheritdoc}
     * 
     * @param string $path Path to check.
     * 
     * @return bool
     */
    public function fileExists(string $path): bool
    {
        $path = $this->prefixer->prefixPath($path);

        try {
            return $this->client->getFileByName($path, $this->bucketId) instanceof File;
        } catch (NoResultsException $ex) {
            return false;
        }
    }

    /**
     * Upload file with contents from a string.
     * 
     * {@inheritdoc}
     * 
     * @see writeStream()
     */
    public function write(string $path, string $contents, Config $config): void
    {
        $this->writeStream($path, $contents, $config, $config);
    }

    /**
     * Upload file with contents from a stream.
     * 
     * {@inheritdoc}
     * 
     * @param string   $path     Path to the file.
     * @param resource $contents Contents of the file.
     * @param Config   $config   Optional configuration.
     * 
     * @return resource
     */
    public function writeStream(string $path, $contents, Config $config): void
    {
        $path = $this->prefixer->prefixPath($path);

        UploadHelper::instance($this->client)->uploadStream($this->bucketId, $path, $contents, $config->get('mimeType'));
    }

    /**
     * Download a file and return the contents as a string.
     * 
     * {@inheritdoc}
     * 
     * @param string $path Path to the file.
     * 
     * @return string
     */
    public function read(string $path): string
    {
        return $this->download($path)->getContents();
    }

    /**
     * Download a file and return the contents as a stream.
     * 
     * {@inheritdoc}
     * 
     * @param string $path Path to the file.
     * 
     * @return resource
     */
    public function readStream(string $path)
    {
        return StreamWrapper::getResource($this->download($path)->getStream());
    }

    /**
     * Deletes a file.
     * 
     * {@inheritdoc}
     * 
     * @param string $path Path to the file.
     * 
     * @return void
     */
    public function delete(string $path): void
    {
        $path = $this->prefixer->prefixPath($path);

        try {
            $file = $this->client->getFileByName($path, $this->bucketId);
            $this->client->deleteFileVersion($file->id(), $file->name());
        } catch (Throwable $exception) {
            throw UnableToDeleteFile::atLocation($path, $exception->getMessage(), $exception);
        }
    }

    /**
     * Deletes a directory and all the contained files.
     * 
     * {@inheritdoc}
     * 
     * @param string $path The path of the directory.
     * 
     * @return void
     */
    public function deleteDirectory(string $path): void
    {
        $path = $this->prefixer->prefixPath($path);

        $this->client->deleteAllFileVersions(null, null, rtrim($path, '/'));
    }

    /**
     * Creates a directory.
     * 
     * B2 does not support directories. An object will be created with ".bzEmpty" appended to the specified path,
     * which acts as a virtual "directory".
     *
     * {@inheritdoc}
     * 
     * @param string $path   Path of the directory.
     * @param Config $config Optional configuration.
     * 
     * @return void
     */
    public function createDirectory(string $path, Config $config): void
    {
        $path = $this->prefixer->prefixPath($path);

        $this->write(rtrim($path, '/') . '/' . File::VIRTUAL_DIRECTORY_SUFFIX, '', $config);
    }

    /**
     * B2 does not support file visibility.
     * 
     * {@inheritdoc}
     * 
     * @throws UnableToSetVisibility 
     */
    public function setVisibility(string $path, string $visibility): void
    {
        throw UnableToSetVisibility::atLocation($path, 'Filesystem does not support file visibility.');
    }

    /**
     * B2 does not support file visibility.
     * 
     * {@inheritdoc}
     */
    public function visibility(string $path): FileAttributes
    {
        throw UnableToRetrieveMetadata::visibility($path, 'Filesystem does not support file visibility.');
    }

    /** {@inheritdoc} */
    public function mimeType(string $path): FileAttributes
    {
        try {
            return $this->fetchFileMetadata($path);
        } catch (NoResultsException $exception) {
            throw UnableToRetrieveMetadata::mimeType($path, $exception->getMessage(), $exception);
        }
    }

    /** {@inheritdoc} */
    public function lastModified(string $path): FileAttributes
    {
        try {
            return $this->fetchFileMetadata($path);
        } catch (NoResultsException $exception) {
            throw UnableToRetrieveMetadata::lastModified($path, $exception->getMessage(), $exception);
        }
    }

    /** {@inheritdoc} */
    public function fileSize(string $path): FileAttributes
    {
        try {
            return $this->fetchFileMetadata($path);
        } catch (NoResultsException $exception) {
            throw UnableToRetrieveMetadata::fileSize($path, $exception->getMessage(), $exception);
        }
    }


    /**
     * List the contents of a directory.
     * 
     * {@inheritdoc}
     * 
     * @param string $path Path of the directory.
     * @param bool $deep   Include results from all subdirectories.
     * 
     * @return Generator
     */
    public function listContents(string $path, $deep = false): iterable
    {
        $prefix = trim($this->prefixer->prefixPath($path), '/');
        $prefix = empty($prefix) ? '' : $prefix . '/';

        $contents = $this->client->listAllFileNames($this->bucketId, $prefix, $deep ? null : '/');

        foreach ($contents as $item) {
            if ($item->action()->isUpload() || $item->action()->isFolder()) {
                yield $this->convertItemToAttributes($item);
            }
        }
    }

    /**
     * Moves a file.
     * 
     * {@inheritdoc}
     * 
     * @param string $sourcePath      Path of the source file.
     * @param string $destinationPath Path of the destination file.
     * @param Config $config          Optional configuration.
     * 
     * @return void
     */
    public function move(string $sourcePath, string $destinationPath, Config $config): void
    {
        try {
            // Same as copy then delete
            $this->copy($sourcePath, $destinationPath, $config);
            $this->delete($sourcePath);
        } catch (UnableToCopyFile $exception) {
            throw UnableToMoveFile::fromLocationTo($sourcePath, $destinationPath, $exception->getPrevious());
        }
    }

    /**
     * Copy a file.
     * 
     * {@inheritdoc}
     * 
     * @param string $sourcePath      Path of the source file.
     * @param string $destinationPath Path of the destination file.
     * @param Config $config          Optional configuration.
     * 
     * @return void
     */
    public function copy(string $sourcePath, string $destinationPath, Config $config): void
    {
        $sourcePath = $this->prefixer->prefixPath($sourcePath);
        $destinationPath = $this->prefixer->prefixPath($destinationPath);

        try {
            $file = $this->client->getFileByName($sourcePath, $this->bucketId);
        } catch (NoResultsException $exception) {
            throw UnableToCopyFile::fromLocationTo($sourcePath, $destinationPath, $exception);
        }

        $this->client->file($file)->copy(
            $destinationPath,
            $config->get('destinationBucketId'),
            $config->get('range'),
            $config->get('metadataDirective'),
            $config->get('contentType'),
            $config->get('fileInfo'),
            $config->get('fileRetention'),
            $config->get('legalHold'),
            $config->get('sourceServerSideEncryption'),
            $config->get('destinationServerSideEncryption')
        );
    }

    /**
     * Fetches file metadata.
     * 
     * @param string $path Path to the file.
     * 
     * @return FileAttributes 
     * 
     * @throws NoResultsException 
     * @throws UnableToRetrieveMetadata 
     */
    private function fetchFileMetadata(string $path): FileAttributes
    {
        $file = $this->client->getFileByName($this->prefixer->prefixPath($path), $this->bucketId);

        if ($file->contentType() === 'application/octet-stream') {
            throw UnableToRetrieveMetadata::mimeType($path, 'File has unknown MIME type: application/octet-stream');
        }

        return new FileAttributes(
            $file->name(),
            $file->contentLength(),
            null,
            (int) round(($file->lastModifiedTimestamp() ?? $file->uploadTimestamp()) / 1000),
            $file->contentType(),
            $this->extractExtraMetadata($file)
        );
    }

    /**
     * Converts a B2 `File` object into a `StorageAttributes` object.
     * 
     * @param File $item File to convert.
     * 
     * @return DirectoryAttributes|FileAttributes
     */
    private function convertItemToAttributes(File $item): StorageAttributes
    {
        if ($item->action()->isFolder()) {
            return new DirectoryAttributes(
                rtrim($this->prefixer->stripPrefix($item->name()), '/')
            );
        }

        return new FileAttributes(
            $this->prefixer->stripPrefix($item->name()),
            $item->contentLength(),
            null,
            (int) round($item->lastModifiedTimestamp() / 1000),
            $item->contentType()
        );
    }

    /**
     * Extracts additional metadata on a file.
     * 
     * @param File $file File to get metadata from.
     * 
     * @return array
     */
    private function extractExtraMetadata(File $file): array
    {
        return [
            'b2' => array_filter([
                File::ATTRIBUTE_FILE_ID => $file->id(),
                File::ATTRIBUTE_FILE_INFO => $file->info()->get(),
                File::ATTRIBUTE_LEGAL_HOLD => $file->legalHold(),
                File::ATTRIBUTE_FILE_RETENTION => $file->retention(),
                File::ATTRIBUTE_SSE => $file->serverSideEncryption()->toArray() ?? null,
                File::ATTRIBUTE_UPLOAD_TIMESTAMP => $file->uploadTimestamp(),
            ]),
        ];
    }

    /**
     * Wrapper method for the B2 client download helper.
     * 
     * @param mixed $path Path to the file.
     * 
     * @return FileDownload
     * 
     * @throws UnableToReadFile
     */
    private function download($path)
    {
        $path = $this->prefixer->prefixPath($path);

        try {
            return $this->client->file($this->client->getFileByName($path, $this->bucketId))->download();
        } catch (NoResultsException $exception) {
            throw UnableToReadFile::fromLocation($path, '', $exception);
        }
    }
}
