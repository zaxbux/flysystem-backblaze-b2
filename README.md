# Zaxbux\Flysystem\BackblazeB2

[![GitHub release (latest SemVer)](https://img.shields.io/github/v/release/zaxbux/flysystem-backblaze-b2)][github-releases] ![Packagist PHP Version Support](https://img.shields.io/packagist/php-v/zaxbux/flysystem-backblaze-b2) [![Packagist Version](https://img.shields.io/packagist/v/zaxbux/flysystem-backblaze-b2)][packagist] [![GitHub licence](https://img.shields.io/github/license/zaxbux/flysystem-backblaze-b2)][licence] [![GitHub issues](https://img.shields.io/github/issues-raw/zaxbux/flysystem-backblaze-b2)][github-issues]

A Flysystem adaptor for Backblaze B2.

# Installation
``composer require zaxbux/flysystem-backblaze-b2``

# Basic Usage
```php
use League\Flysystem\Filesystem;
use Zaxbux\BackblazeB2\Client;
use Zaxbux\Flysystem\BackblazeB2Adapter;


$client     = new Client(['B2_APPLICATION_KEY_ID', 'B2_APPLICATION_KEY']);
$adapter    = new BackblazeB2Adapter($client, 'B2_BUCKET_ID');
$filesystem = new Filesystem($adapter);
```

# Contributions
Feel free to contribute in any way report an issue, make a suggestion, or send a pull request.

# License
[MIT][licence]

[licence]: LICENCE.md
[packagist]: https://packagist.org/packages/zaxbux/flysystem-backblaze-b2
[github-issues]: https://github.com/zaxbux/flysystem-backblaze-b2/issues
[github-releases]: https://github.com/zaxbux/flysystem-backblaze-b2/releases
