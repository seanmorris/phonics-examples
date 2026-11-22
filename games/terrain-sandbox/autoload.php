<?php
declare(strict_types=1);

// The PHAR loads core before the game. Standalone PHP tools can use either installed distribution.
if(!class_exists(\Phonics\Ecs\Registry::class))
{
	foreach(['node_modules/@seanmorris/phonics/autoload.php', 'vendor/autoload.php'] as $file)
	{
		$path = dirname(__DIR__, 2) . '/' . $file;
		if(is_file($path)) { require_once $path; break; }
	}
}

spl_autoload_register(static function(string $class): void {
	$prefix = 'TerrainSandbox\\';
	if(!str_starts_with($class, $prefix)) return;
	$file = __DIR__ . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
	if(is_file($file)) require_once $file;
});
