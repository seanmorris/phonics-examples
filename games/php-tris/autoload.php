<?php
declare(strict_types=1);

spl_autoload_register(static function(string $class): void {
	if(!str_starts_with($class, 'PhpTris\\')) return;

	$file = __DIR__ . '/src/' . str_replace('\\', '/', substr($class, 8)) . '.php';

	if(is_file($file)) require_once $file;
});
