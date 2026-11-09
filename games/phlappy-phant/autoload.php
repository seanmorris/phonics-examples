<?php
declare(strict_types=1);

spl_autoload_register(static function(string $class): void {
	if(strncmp($class, 'PhlappyPhant\\', 13) !== 0) return;

	$file = __DIR__ . '/src/' . str_replace('\\', '/', substr($class, 13)) . '.php';

	if(is_file($file)) require $file;
});
