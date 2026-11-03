<?php
declare(strict_types=1);

/** Register native WAD parsing, adapter, rendering, audio, and gameplay classes. */
spl_autoload_register(static function(string $class): void {
	if(!str_starts_with($class, 'DoomPhonics\\')) return;

	$file = __DIR__ . '/src/' . str_replace('\\', '/', substr($class, 12)) . '.php';

	if(is_file($file)) require_once $file;
});
