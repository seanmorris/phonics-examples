<?php
declare(strict_types=1);

require_once 'phar://' . Phar::running(false) . '/core/autoload.php';

$app = new \Phonics\Application\Context;
$phonics = $app->compose(__DIR__ . '/demo/episode-one-online.phonics-world.json', __DIR__ . '/phonics.json#game', json_decode(file_get_contents(__DIR__ . '/application-network.json'), true, flags: JSON_THROW_ON_ERROR));

require $app->entry();
