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

spl_autoload_register(
	/**
 * Apply the registered session callback.
 *
 * @param string $class Fully qualified class or factory name.
 * @return void Completes the operation.
 */
	static function(string $class): void {
		$aliases = require __DIR__ . '/legacy.php';
		$target = $aliases[$class] ?? $class;

		if(str_starts_with($target, 'SanePhonics\\'))
		{
			$file = __DIR__ . '/src/' . str_replace('\\', '/', substr($target, 12)) . '.php';

			if(is_file($file)) require_once $file;

			foreach($aliases as $old => $canonical)
			{
				if($canonical === $target && !class_exists($old, false) && !interface_exists($old, false)
					&& (class_exists($target, false) || interface_exists($target, false))
				) class_alias($target, $old);
			}
		}
	}
	, true
	, true
);
