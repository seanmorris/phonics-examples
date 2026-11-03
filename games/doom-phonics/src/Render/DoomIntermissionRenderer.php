<?php
declare(strict_types=1);
namespace DoomPhonics\Render;

use DoomPhonics\Game\LevelIntermission;

/** Draws classic WAD intermission art and animated level statistics. */
final class DoomIntermissionRenderer
{

	public function render(GlesMapRenderer $renderer, LevelIntermission $intermission): void
	{
		$renderer->renderMenuPanel(0, 0, 320, 200, 1);
		$background = $this->background($renderer, $intermission->map);

		if($background !== null)
		$renderer->renderMenuPatches([new WadPatch($background, 0, 0, 320, 200)]);

		$patches = [];

		if($intermission->phase() === 'entering')
		{
			$this->center($renderer, $patches, 'WIENTER', 24);

			$this->mapTitle(
				$renderer
				, $patches
				, $intermission->nextMap ?? $intermission->map
				, $intermission->nextMapTitle ?? $intermission->mapTitle
				, 48
			);

			$renderer->renderMenuPatches($patches);

			return;
		}

		$this->mapTitle($renderer, $patches, $intermission->map, $intermission->mapTitle, 2);
		$this->center($renderer, $patches, 'WIF', 20);
		$patches[] = new WadPatch('WIOSTK', 50, 50);
		$patches[] = new WadPatch('WIOSTI', 50, 74);
		$patches[] = new WadPatch('WISCRT2', 50, 98);
		$this->percent($renderer, $patches, $intermission->displayKills, 270, 50);
		$this->percent($renderer, $patches, $intermission->displayItems, 270, 74);
		$this->percent($renderer, $patches, $intermission->displaySecrets, 270, 98);
		$patches[] = new WadPatch('WITIME', 50, 138);
		$this->time($renderer, $patches, $intermission->displayTime, 158, 138);

		if($intermission->par !== null)
		{
			$patches[] = new WadPatch('WIPAR', 176, 138);
			$this->time($renderer, $patches, $intermission->displayPar, 284, 138);
		}

		$renderer->renderMenuPatches($patches);

		if($intermission->phase() === 'done')
		$this->text($renderer, 'PRESS FIRE OR USE', 178, true);
	}

	private function background(GlesMapRenderer $renderer, string $map): ?string
	{
		if(preg_match('/^E([1-9])M[1-9]$/', strtoupper($map), $match))
		{
			$name = 'WIMAP' . ((int)$match[1] - 1);

			if($renderer->catalog->picture($name)) return $name;
		}

		return $renderer->catalog->picture('INTERPIC') ? 'INTERPIC' : null;
	}

	/** @param list<WadPatch> $patches */
	private function mapTitle(
		GlesMapRenderer $renderer
		, array &$patches
		, string $map
		, ?string $title
		, float $y
	): void {
		$name = null;
		$map = strtoupper($map);

		if(preg_match('/^E([1-9])M([1-9])$/', $map, $match))
		$name = 'WILV' . ((int)$match[1] - 1) . ((int)$match[2] - 1);
		elseif(preg_match('/^MAP(\d\d)$/', $map, $match))
		$name = 'CWILV' . str_pad((string)((int)$match[1] - 1), 2, '0', STR_PAD_LEFT);

		if($name !== null && $renderer->catalog->picture($name)) $this->center($renderer, $patches, $name, $y);
		else $this->text($renderer, $title ?? $map, $y, true);
	}

	/** @param list<WadPatch> $patches */
	private function center(GlesMapRenderer $renderer, array &$patches, string $name, float $y): void
	{
		$image = $renderer->catalog->picture($name);

		if($image) $patches[] = new WadPatch($name, (320 - $image->width) / 2, $y);
	}

	/** @param list<WadPatch> $patches */
	private function percent(GlesMapRenderer $renderer, array &$patches, int $value, float $right, float $y): void
	{
		$percent = $renderer->catalog->picture('WIPCNT');

		if($percent)
		{
			$right -= $percent->width;
			$patches[] = new WadPatch('WIPCNT', $right, $y);
		}

		$this->number($renderer, $patches, $value, $right, $y);
	}

	/** @param list<WadPatch> $patches */
	private function time(GlesMapRenderer $renderer, array &$patches, int $seconds, float $right, float $y): void
	{
		$seconds = max(0, min(5999, $seconds));
		$minute = intdiv($seconds, 60);
		$second = $seconds % 60;
		$secondWidth = $this->digitsWidth($renderer, sprintf('%02d', $second));
		$colon = $renderer->catalog->picture('WICOLON');
		$this->number($renderer, $patches, $second, $right, $y, 2);
		$right -= $secondWidth;

		if($colon)
		{
			$right -= $colon->width;
			$patches[] = new WadPatch('WICOLON', $right, $y);
		}

		$this->number($renderer, $patches, $minute, $right, $y);
	}

	/** @param list<WadPatch> $patches */
	private function number(
		GlesMapRenderer $renderer
		, array &$patches
		, int $value
		, float $right
		, float $y
		, int $minimumDigits = 1
	): void {
		$digits = str_pad((string)max(0, $value), $minimumDigits, '0', STR_PAD_LEFT);

		for($index = strlen($digits) - 1; $index >= 0; $index--)
		{
			$name = 'WINUM' . $digits[$index];
			$image = $renderer->catalog->picture($name);

			if(!$image) continue;

			$right -= $image->width;
			$patches[] = new WadPatch($name, $right, $y);
		}
	}

	private function digitsWidth(GlesMapRenderer $renderer, string $digits): float
	{
		$width = 0;

		foreach(str_split($digits) as $digit) $width += $renderer->catalog->picture('WINUM' . $digit)?->width ?? 0;

		return $width;
	}

	private function text(GlesMapRenderer $renderer, string $text, float $y, bool $center = false): void
	{
		$text = strtoupper($text);
		$width = 0;

		foreach(str_split($text) as $character)
		$width += $character === ' '
			? 4
			: (($renderer->catalog->picture(
				'STCFN' . str_pad((string)ord($character), 3, '0', STR_PAD_LEFT)
			)?->width ?? 4
			) + 1
			);

		$x = $center ? (320 - $width) / 2 : 0;
		$patches = [];

		foreach(str_split($text) as $character)
		{
			if($character === ' ')
			{
				$x += 4;
				continue;
			}

			$name = 'STCFN' . str_pad((string)ord($character), 3, '0', STR_PAD_LEFT);
			$image = $renderer->catalog->picture($name);

			if(!$image) continue;

			$patches[] = new WadPatch($name, $x, $y);
			$x += $image->width + 1;
		}

		$renderer->renderMenuPatches($patches);
	}
}
