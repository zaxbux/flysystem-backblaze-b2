<?php

namespace Zaxbux\Flysystem;

use Zaxbux\B2\Client;
use Zaxbux\B2\Exceptions\NotFoundException;
use GuzzleHttp\Psr7;
use GuzzleHttp\Psr7\Utils;
use League\Flysystem\Config;
use League\Flysystem\FileAttributes;
use League\Flysystem\FilesystemAdapter;
use League\Flysystem\FilesystemException;
use League\Flysystem\PathPrefixer;
use League\Flysystem\StorageAttributes;
use League\Flysystem\UnableToDeleteDirectory;
use League\Flysystem\UnableToDeleteFile;
use League\Flysystem\UnableToReadFile;
use League\Flysystem\UnableToSetVisibility;
use League\Flysystem\Visibility;
use League\MimeTypeDetection\FinfoMimeTypeDetector;
use League\MimeTypeDetection\MimeTypeDetector;
use Throwable;
use Zaxbux\B2\Bucket;
use Zaxbux\B2\File;

class BackblazeB2Adapter implements FilesystemAdapter
{

	const B2_METADATA_DIRECTIVE_COPY = 'COPY';
	const B2_FILE_TYPES = [
		'upload' => 'file',
		'folder' => 'dir'
	];

	/**
	 * 
	 * @var Client
	 */
	private $client;

	/**
	 * @var PathPrefixer
	 */
	private $prefixer;

	/**
	 * 
	 * @var string
	 */
	private $bucketName;

	/**
	 * @var MimeTypeDetector
	 */
	private $mimeTypeDetector;

	/**
	 * @var array
	 */
	private $options;

	/**
	 * @var bool
	 */
	private $streamReads;

	public function __construct(
		Client $client,
		string $bucketName,
		string $prefix = '',
		MimeTypeDetector $mimeTypeDetector = null,
		array $options = [],
		bool $streamReads = true
	) {
		$this->client = $client;
		$this->prefixer = new PathPrefixer($prefix);
		$this->bucketName = $bucketName;
		$this->mimeTypeDetector = $mimeTypeDetector ?: new FinfoMimeTypeDetector();
		$this->options = $options;
		$this->streamReads = $streamReads;
	}

	public function fileExists(string $path): bool
	{
		return $this->client->fileExists([
			'BucketName' => $this->bucketName,
			'FileName'   => $path,
		]);
	}

	public function write(string $path, string $contents, Config $config): void
	{
		return $this->writeStream($path, $contents, $config);
	}

	public function writeStream(string $path, $contents, Config $config): void
	{
		$this->client->upload([
			'BucketName' => $this->bucketName,
			'FileName'   => $path,
			'Body'       => $contents,
		]);
	}

	public function read(string $path): string
	{
		$file = $this->client->getFile([
			'BucketName' => $this->bucketName,
			'FileName'   => $path
		]);

		return $this->client->download([
			'FileId' => $file->getId(),
		]);
	}

	public function readStream($path)
	{
		$stream   = Utils::streamFor();
		$download = $this->client->download([
			'BucketName' => $this->bucketName,
			'FileName'   => $path,
			'SaveAs'     => $stream,
		]);

		$stream->seek(0);

		try {
			$resource = Psr7\StreamWrapper::getResource($stream);
		} catch (Throwable $exception) {
			throw UnableToReadFile::fromLocation($path, '', $exception);
		}

		return $download === true ? ['stream' => $resource] : false;
	}

	public function delete(string $path): void
	{
		try {
			$this->client->deleteFile([
				'BucketName' => $this->bucketName,
				'FileName'   => $path
			]);
		} catch (Throwable $exception) {
			throw UnableToDeleteFile::atLocation($path, '', $exception);
		}
	}

	public function deleteDirectory(string $path): void
	{
		// Delete all files
		$files = $this->listContents($path, true);

		foreach ($files as $file) {
			if ($file['type'] == 'file') {
				try {
					$this->delete($file['path']);
				} catch (Throwable $exception) {
					throw UnableToDeleteDirectory::atLocation($path, '', $exception->getPrevious());
				}
			} else {
				try {
					$this->delete($file['path'] . '/.bzEmpty');
				} catch (NotFoundException $e) {
					// .bzEmpty may or may not exist, ignore error
				}
			}
		}

		// Delete .bzEmpty to fully delete virtual folder
		try {
			$this->delete($path . '/.bzEmpty');
		} catch (NotFoundException $e) {
			// .bzEmpty may or may not exist, ignore error
		}
	}

	public function createDirectory(string $path, Config $config): void
	{
		$this->write($path . '/.bzEmpty', '', $config);
	}

	/**
	 * Not supported by B2 at the file-level. Only buckets can be `allPublic` or `allPrivate`.
	 * 
	 * @param string $path 
	 * @param string $visibility 
	 * @return void 
	 * @throws FilesystemException 
	 */
	public function setVisibility(string $path, string $visibility): void
	{
		throw UnableToSetVisibility::atLocation($path, 'Filesystem does not support file-level visibility.');
	}

	public function visibility(string $path): FileAttributes
	{
		$buckets = $this->client->listBuckets();

		$bucket = null;

		foreach ($buckets as $b) {
			if ($this->bucketName == $b->getName()) {
				$bucket = $b;
			}
		}

		return FileAttributes::fromArray([
			StorageAttributes::ATTRIBUTE_VISIBILITY => $bucket->getType() == Bucket::TYPE_PUBLIC ? Visibility::PUBLIC : Visibility::PRIVATE,
		]);
	}

	public function mimeType(string $path): FileAttributes
	{
		return FileAttributes::fromArray([
			StorageAttributes::ATTRIBUTE_MIME_TYPE => $this->fetchFileMetadata($path)->getType(),
		]);
	}

	public function lastModified(string $path): FileAttributes
	{
		$file = $this->fetchFileMetadata($path);
		$mtime = $file->getInfo()['src_last_modified_millis'] ?? $file->getUploadTimestamp();

		return FileAttributes::fromArray([
			StorageAttributes::ATTRIBUTE_LAST_MODIFIED => $mtime / 1000.0,
		]);
	}

	public function fileSize(string $path): FileAttributes
	{
		return FileAttributes::fromArray([
			StorageAttributes::ATTRIBUTE_FILE_SIZE => $this->fetchFileMetadata($path)->getSize(),
		]);
	}


	public function listContents($directory = '', $recursive = false): iterable
	{
		// Append trailing slash to directory names
		$prefix = $directory;
		if ($prefix !== '') {
			$prefix .= '/';
		}

		// Recursion removes the delimiter
		$delimiter = ($recursive ? null : '/');

		$files = $this->client->listFiles([
			'BucketName' => $this->bucketName,
			'delimiter'  => $delimiter,
			'prefix'     => $prefix,
		]);

		return array_map([$this, 'getFileInfo'], $files);
	}

	public function move(string $source, string $destination, Config $config): void
	{
		// Same as copy then delete
		$this->copy($source, $destination, $config);
		$this->delete($source);
	}

	public function copy(string $source, string $destination, Config $config): void
	{
		$this->client->copyFile([
			'BucketName'        => $this->bucketName,
			'SourceFileName'    => $source,
			'FileName'          => $destination,
			'MetadataDirective' => self::B2_METADATA_DIRECTIVE_COPY,
		]);
	}

	/**
	 * 
	 * @param string $path 
	 * @return File 
	 * @throws NotFoundException 
	 */
	protected function fetchFileMetadata(string $path)
	{
		return $this->client->getFile([
			'BucketName' => $this->bucketName,
			'FileName'   => $path,
		]);
	}
}
