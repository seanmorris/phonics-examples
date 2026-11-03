<?php
declare(strict_types=1);

if(!class_exists(\Phonics\Ecs\Registry::class))
{
	foreach([__DIR__ . '/../../autoload.php', __DIR__ . '/vendor/autoload.php'] as $bootstrap)
	{
		if(is_file($bootstrap))
		{
			require_once $bootstrap;
			break;
		}
	}
}

spl_autoload_register(/**
 * Apply the registered session callback.
 *
 * @param string $class Fully qualified class or factory name.
 * @return void Completes the operation.
 */
	static function(string $class): void {
		if(!str_starts_with($class, 'PhpNoid\\')) return;

		$file = __DIR__ . '/src/' . str_replace('\\', '/', substr($class, strlen('PhpNoid\\'))) . '.php';

		if(is_file($file)) require_once $file;
	}
);
