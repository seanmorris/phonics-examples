<?php
declare(strict_types=1);
namespace DoomPhonics\Adapter;

use DoomPhonics\Archive\Archive;
use DoomPhonics\Archive\Stack;
use Phonics\World\AdapterContext;
use Phonics\World\ContentAdapter;
use Phonics\World\ContentSource;
use Phonics\World\SourceDefinition;

/** Opens an IWAD followed by ordered IWAD/PWAD overlays through canonical session resources. */
final class WadAdapter implements ContentAdapter
{

	public function open(SourceDefinition $definition, AdapterContext $context): ContentSource
	{
		$archives = [];
		$decorate = [];
		$sndinfo = [];
		$keyconf = [];
		$language = [];

		foreach($definition->resources as $index => $resource)
		{
			$url = (string)($resource['sourceUrl'] ?? $resource['url']);
			$role = strtolower((string)($resource['role'] ?? 'wad'));

			try
			{
				$bytes = $context->read($url);

				if($role === 'decorate')
				{
					$decorate[$url] = $bytes;
					continue;
				}

				if($role === 'sndinfo')
				{
					$sndinfo[$url] = $bytes;
					continue;
				}

				if($role === 'keyconf')
				{
					$keyconf[$url] = $bytes;
					continue;
				}

				if($role === 'language')
				{
					$language[$url] = $bytes;
					continue;
				}

				if(!in_array($role, ['wad','iwad','pwad'], true))
				throw new \UnexpectedValueException("unsupported WAD resource role $role");

				$archive = new Archive($bytes, $url);

				if($role !== 'wad' && strtoupper($role) !== $archive->type)
				throw new \UnexpectedValueException("role $role contains {$archive->type} data");

				$archives[] = $archive;

				foreach($archive->lumps('DECORATE') as $lump) $decorate[$lump->identity()] = $lump->bytes();

				foreach($archive->lumps('SNDINFO') as $lump) $sndinfo[$lump->identity()] = $lump->bytes();

				foreach($archive->lumps('KEYCONF') as $lump) $keyconf[$lump->identity()] = $lump->bytes();

				foreach($archive->lumps('LANGUAGE') as $lump) $language[$lump->identity()] = $lump->bytes();
			}
			catch(\Throwable $error)
			{
				throw new \RuntimeException(
					"{$definition->sourceUrl}: source {$definition->id} resource $index ($url): {$error->getMessage()}"
					, 0
					, $error
				);
			}
		}

		if(!$archives) throw new \UnexpectedValueException("WAD source {$definition->id} has no archives");

		$stack = new Stack(array_shift($archives));

		foreach($archives as $archive) $stack->add($archive);

		return new WadSource($stack, $decorate, $sndinfo, $keyconf, $language);
	}
}
