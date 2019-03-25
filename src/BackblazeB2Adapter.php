<?php

namespace Zaxbux\Flysystem;

use ILAB\B2\Client;
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
			'BucketName' => $this->bucketName(),
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
		// The B2 API does not support re-naming
		return false;
	}

	/**
	 * {@inheritdoc}
	 */
	public function copy($path, $newPath) {
		return $this->getClient()->upload([
			'BucketName' => $this->bucketName,
			'FileName'   => $newPath,
			'Body'       => @\file_get_contents($path),
		]);
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
		return $this->delete($path);
	}

	/**
	 * {@inheritdoc}
	 */
	public function createDir($path, Config $config) {
		return $this->write($path, '', $config);
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
		$files = $this->getClient()->listFiles([
			'BucketName' => $this->bucketName,
			'delimiter'  => '/',
			'prefix'     => $directory
		]);

		return array_map([$this, 'getFileInfo'], $files);
	}

	/**
	 * Get file info
	 * 
	 * @param  $file ILAB\B2\File $file
	 * @return array
	 */
	protected function getFileInfo($file) {
		return [
			'type'      => $this->typeFromB2Action($file->getAction()),
			'path'      => $file->getName(),
			'timestamp' => $file->getUploadTimestamp(),
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