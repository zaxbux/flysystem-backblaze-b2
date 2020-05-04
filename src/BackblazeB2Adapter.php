<?php

namespace Zaxbux\Flysystem;

use Zaxbux\B2\Client;
use Zaxbux\B2\Exceptions\NotFoundException;
use GuzzleHttp\Psr7;
use League\Flysystem\Config;
use League\Flysystem\Adapter\AbstractAdapter;
use League\Flysystem\Adapter\Polyfill\NotSupportingVisibilityTrait;

class BackblazeB2Adapter extends AbstractAdapter {
	use NotSupportingVisibilityTrait;

	protected $client;
	protected $bucketName;

	public function __construct(Client $client, $bucketName) {
		$this->client = $client;
		$this->bucketName = $bucketName;
	}

	/**
	 * {@inheritdoc}
	 */
	public function has($path) {
		return $this->getClient()->fileExists([
			'BucketName' => $this->bucketName,
			'FileName'   => $path,
		]);
	}

	/**
	 * {@inheritdoc}
	 */
	public function write($path, $contents, Config $config) {
		return $this->writeStream($path, $contents, $config);
	}

	/**
	 * {@inheritdoc}
	 */
	public function writeStream($path, $resource, Config $config) {
		$file = $this->getClient()->upload([
			'BucketName' => $this->bucketName,
			'FileName'   => $path,
			'Body'       => $resource,
		]);

		return $this->getFileInfo($file);
	}

	/**
	 * {@inheritdoc}
	 */
	public function update($path, $contents, Config $config) {
		return $this->writeStream($path, $contents, $config);
	}

	/**
	 * {@inheritdoc}
	 */
	public function updateStream($path, $resource, Config $config) {
		return $this->writeStream($path, $resource, $config);
	}

	/**
	 * {@inheritdoc}
	 */
	public function read($path) {
		$file = $this->getClient()->getFile([
			'BucketName' => $this->bucketName,
			'FileName'   => $path
		]);

		$fileContents = $this->getClient()->download([
			'FileId' => $file->getId(),
		]);

		return ['contents' => $fileContents];
	}

	/**
	 * {@inheritdoc}
	 */
	public function readStream($path) {
		$stream   = Psr7\stream_for();
		$download = $this->getClient()->download([
			'BucketName' => $this->bucketName,
			'FileName'   => $path,
			'SaveAs'     => $stream,
		]);

		$stream->seek(0);

		try {
			$resource = Psr7\StreamWrapper::getResource($stream);
		} catch (InvalidArgumentException $e) {
			return false;
		}

		return $download === true ? ['stream' => $resource] : false;
	}

	/**
	 * {@inheritdoc}
	 */
	public function rename($path, $newPath) {
		// Same as copy then delete
		$this->copy($path, $newPath);
		$this->delete($path);

		return true;
	}

	/**
	 * {@inheritdoc}
	 */
	public function copy($path, $newPath) {
		$this->getClient()->copyFile([
			'BucketName'        => $this->bucketName,
			'SourceFileName'    => $path,
			'FileName'          => $newPath,
			'MetadataDirective' => 'COPY'
		]);

		return true;
	}

	/**
	 * {@inheritdoc}
	 */
	public function delete($path) {
		return $this->getClient()->deleteFile([
			'BucketName' => $this->bucketName,
			'FileName'   => $path
		]);
	}

	/**
	 * {@inheritdoc}
	 */
	public function deleteDir($path) {
		// Delete all files
		$files = $this->listContents($path, true);

		foreach ($files as $file) {
			if ($file['type'] == 'file') {
				$this->delete($file['path']);
			} else {
				try {
					$this->delete($file['path'].'/.bzEmpty');
				} catch (NotFoundException $e) {
					// .bzEmpty may or may not exist, ignore error
				}
			}
		}

		// Delete .bzEmpty to fully delete virtual folder
		try {
			$this->delete($path.'/.bzEmpty');
		} catch (NotFoundException $e) {
			// .bzEmpty may or may not exist, ignore error
		}
	}

	/**
	 * {@inheritdoc}
	 */
	public function createDir($path, Config $config) {
		return $this->write($path.'/.bzEmpty', '', $config);
	}

	/**
	 * {@inheritdoc}
	 */
	public function getMetadata($path) {
		$file = $this->getClient()->getFile([
			'BucketName' => $this->bucketName,
			'FileName'   => $path,
		]);

		return $this->getFileInfo($file);
	}

	/**
	 * {@inheritdoc}
	 */
	public function getMimetype($path) {
		return $this->getMetadata($path);
	}

	/**
	 * {@inheritdoc}
	 */
	public function getSize($path) {
		return $this->getMetadata($path);
	}

	/**
	 * {@inheritdoc}
	 */
	public function getTimestamp($path) {
		return $this->getMetadata($path);
	}

	/**
	 * {@inheritdoc}
	 */
	public function getClient() {
		return $this->client;
	}

	/**
	 * {@inheritdoc}
	 */
	public function listContents($directory = '', $recursive = false) {
		// Append trailing slash to directory names
		$prefix = $directory;
		if ($prefix !== '') {
			$prefix .= '/';
		}

		// Recursion removes the delimiter
		$delimiter = ($recursive ? null : '/');

		$files = $this->getClient()->listFiles([
			'BucketName' => $this->bucketName,
			'delimiter'  => $delimiter,
			'prefix'     => $prefix,
		]);

		return array_map([$this, 'getFileInfo'], $files);
	}

	/**
	 * Get file info
	 * 
	 * @param  $file Zaxbux\B2\File $file
	 * @return array
	 */
	protected function getFileInfo($file) {
		return [
			'type'      => $this->typeFromB2Action($file->getAction()),
			'path'      => $file->getName(),
			'timestamp' => $file->getUploadTimestamp() / 1000.0,         // Convert millisecond timestamp to seconds
			'size'      => $file->getSize()
		];
	}

	/**
	 * Convert a B2 API action to Flysystem type. Ignores "start", "hide"
	 * 
	 * @param string $action
	 * @return string
	 */
	protected function typeFromB2Action($action) {
		$typeMap = [
			'upload' => 'file',
			'folder' => 'dir'
		];

		return array_key_exists($action, $typeMap) ? $typeMap[$action] : null;
	}
}