<?php

declare(strict_types=1);

use PhpCsFixer\Config;

require_once './vendor/autoload.php';

$config = new Config();
$config
	->getFinder()
	->ignoreVCSIgnored(true)
	->notPath('vendor')
	->in(__DIR__);
return $config;