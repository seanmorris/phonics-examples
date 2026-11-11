<?php
declare(strict_types=1);
namespace PhpPoker\Ui;

/**
 * What `phpinfo()` reports about the PHP this table runs on, as sections of named values.
 *
 * In the browser the runtime prints it as an HTML document; on the command line, as text. Both are read into the
 * same sections, so the PHP Info window sets them the same way the How to Play window sets its rules.
 */
final class PhpInfo
{
	/** @var list<array{title:string,rows:list<array{string,string}>}>|null The report, read once per process. */
	private static ?array $sections = null;

	/**
	 * The report of the PHP this code is running on.
	 *
	 * @return list<array{title:string,rows:list<array{string,string}>}> Sections in report order, each a title over
	 *   rows of a name and its value; a row with no name is a line of prose.
	 */
	public static function sections(): array
	{
		if(self::$sections !== null) return self::$sections;
		ob_start();
		// The credits are a list of people rather than a report on this PHP, and run longer than the rest.
		phpinfo(INFO_ALL & ~INFO_CREDITS);
		return self::$sections = self::parse((string)ob_get_clean());
	}

	/**
	 * Read a `phpinfo()` report in either of the forms PHP prints it in.
	 *
	 * @param string $report HTML from a web or embedded runtime, or text from the command line.
	 * @return list<array{title:string,rows:list<array{string,string}>}>
	 */
	public static function parse(string $report): array
	{
		$sections = str_contains($report, '<table') ? self::html($report) : self::text($report);
		return array_values(array_filter($sections, static fn(array $section): bool => $section['rows'] !== [] || $section['title'] !== ''));
	}

	/** @return list<array{title:string,rows:list<array{string,string}>}> */
	private static function html(string $report): array
	{
		$report = (string)preg_replace('#<(style|script|head)\b.*?</\1>#is', '', $report);
		$clean = static fn(string $markup): string => trim((string)preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags(
			(string)preg_replace('#<br\s*/?>#i', ' ', $markup)), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
		// The report opens with its PHP version, set as a heading inside the first table's first row: it titles the
		// first section, the general one, and the row it stood in is left empty.
		$sections = [['title' => '', 'rows' => []]];
		if(preg_match('#<h1[^>]*>(.*?)</h1>#is', $report, $heading) === 1)
		{
			$sections[0]['title'] = $clean($heading[1]);
			$report = (string)preg_replace('#<h1[^>]*>.*?</h1>#is', '', $report, 1);
		}
		preg_match_all('#<h1[^>]*>(.*?)</h1>|<h2[^>]*>(.*?)</h2>|<tr[^>]*>(.*?)</tr>|<p[^>]*>(.*?)</p>#is', $report, $tokens, PREG_SET_ORDER);
		foreach($tokens as $token)
		{
			$last = count($sections) - 1;
			if(($token[1] ?? '') !== '' || ($token[2] ?? '') !== '')
			{
				$sections[] = ['title' => $clean(($token[1] ?? '') !== '' ? $token[1] : $token[2]), 'rows' => []];
				continue;
			}
			if(($token[4] ?? '') !== '') { $prose = $clean($token[4]); if($prose !== '') $sections[$last]['rows'][] = ['', $prose]; continue; }
			preg_match_all('#<t([dh])[^>]*>(.*?)</t[dh]>#is', $token[3] ?? '', $cells, PREG_SET_ORDER);
			// A row of column headings (Directive, Local Value, Master Value) names nothing itself.
			if($cells === [] || array_filter($cells, static fn(array $cell): bool => strtolower($cell[1]) === 'd') === []) continue;
			$values = array_map(static fn(array $cell): string => $clean($cell[2]), $cells);
			if(implode('', $values) !== '') $sections[$last]['rows'][] = self::row($values);
		}
		return $sections;
	}

	/** @return list<array{title:string,rows:list<array{string,string}>}> */
	private static function text(string $report): array
	{
		$sections = [['title' => '', 'rows' => []]];
		$blank = true;
		foreach(preg_split('/\R/u', $report) ?: [] as $line)
		{
			$line = rtrim($line);
			$last = count($sections) - 1;
			// Blank lines and the rules of underscores between parts only separate.
			if($line === '' || $line === 'phpinfo()' || preg_match('/^_+$/', $line) === 1) { $blank = true; continue; }
			if(str_contains($line, ' => '))
			{
				$cells = array_map('trim', explode(' => ', $line));
				// The rows naming a table's columns, which the HTML form keeps in headings, name nothing themselves.
				if(!in_array($cells, [['Directive', 'Local Value', 'Master Value'], ['Variable', 'Value'], ['Contribution', 'Authors']], true))
					$sections[$last]['rows'][] = self::row($cells);
				$blank = false;
				continue;
			}
			$rows = $sections[$last]['rows'];
			// A short name of its own after a blank line is a section's title (Core, date, Environment); a sentence
			// there is prose, and a line straight after a value carries that value on.
			$title = $blank && strlen(trim($line)) <= 40 && preg_match('/[.:]$/', $line) !== 1;
			if($title) $sections[] = ['title' => trim($line), 'rows' => []];
			elseif($blank) $sections[$last]['rows'][] = ['', trim($line)];
			elseif($rows !== []) $sections[$last]['rows'][count($rows) - 1][1] = trim($rows[count($rows) - 1][1] . ' ' . trim($line));
			else $sections[$last]['rows'][] = ['', trim($line)];
			$blank = false;
		}
		if($sections[0]['title'] === '' && ($version = self::value($sections[0]['rows'], 'PHP Version')) !== null) $sections[0]['title'] = 'PHP Version ' . $version;
		return $sections;
	}

	/**
	 * One row from its cells: a name, and its value, with the master value beside it where a directive's local value
	 * differs from it.
	 *
	 * @param list<string> $cells
	 * @return array{string,string}
	 */
	private static function row(array $cells): array
	{
		$cells = array_map('trim', $cells);
		if(count($cells) === 1) return ['', $cells[0]];
		$value = $cells[1];
		if(isset($cells[2]) && $cells[2] !== $cells[1]) $value .= ' (master: ' . $cells[2] . ')';
		return [$cells[0], $value];
	}

	/** @param list<array{string,string}> $rows @return string|null The value of the first row with this name. */
	public static function value(array $rows, string $name): ?string
	{
		foreach($rows as [$key, $value]) if($key === $name) return $value;
		return null;
	}
}
