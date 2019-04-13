# Zaxbux\Flysystem\BackblazeB2
A Flysystem adaptor for Backblaze B2.

# Installation
``composer require zaxbux/flysystem-backblaze-b2``

# Basic Usage
```
<?php

use ILAB\B2\Client;
use Zaxbux\Flysystem\BackblazeB2Adapter;
use League\Flysystem\Filesystem;

$client     = new Client('B2_APPLICATION_KEY_ID', 'B2_APPLICATION_KEY');
$adapter    = new BackblazeB2Adapter($client, 'B2_BUCKET_NAME');
$filesystem = new Filesystem($adapter);
```

# Contributions
Feel free to contribute in any way Report an issue, make a suggestion, or send a pull request.

# License
MIT