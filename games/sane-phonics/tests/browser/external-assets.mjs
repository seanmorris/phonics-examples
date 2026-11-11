import fs from 'node:fs/promises';
import os from 'node:os';
import path from 'node:path';
import {execFile} from 'node:child_process';
import {promisify} from 'node:util';

const execute = promisify(execFile);
const externalize = `
$archive = new Phar($argv[1]);
$descriptor = json_decode($archive['application.json']->getContent(), true, flags: JSON_THROW_ON_ERROR);
$archive->startBuffering();
$manifest = json_decode($archive['application/phonics.json']->getContent(), true, flags: JSON_THROW_ON_ERROR);
foreach(json_decode(file_get_contents($argv[2]), true, flags: JSON_THROW_ON_ERROR) as $path => $body)
$manifest['integrity'][$path] = 'sha256-' . base64_encode(hash('sha256', $body, true));
$archive['application/phonics.json'] = json_encode($manifest, JSON_THROW_ON_ERROR);
foreach($descriptor['members'] as $index => $member) {
    if(!str_starts_with($member, 'application/examples/assets/')) continue;
    unset($archive[$member], $descriptor['members'][$index]);
}
$descriptor['members'] = array_values($descriptor['members']);
$archive['application.json'] = json_encode($descriptor, JSON_THROW_ON_ERROR);
$archive->setSignatureAlgorithm(Phar::SHA256);
$archive->stopBuffering();
`;


/** Exercise HTTP streaming and failure recovery with the same executable and external game assets. */
export async function externalAssets(page, overrides = {})
{
	await page.route('**/engine/packages/sane-phonics/dist/game.phar', async route => {
		const response = await route.fetch();
		const directory = await fs.mkdtemp(path.join(os.tmpdir(), 'phonics-external-assets-'));
		const archive = path.join(directory, 'game.phar');

		try
		{
			await fs.writeFile(archive, await response.body());

			await fs.writeFile(path.join(directory, 'overrides.json'), JSON.stringify(overrides));

			await execute(
				'php'
				, ['-d', 'phar.readonly=0', '-r', externalize, archive, path.join(directory, 'overrides.json')]
			);

			await route.fulfill({response, body: await fs.readFile(archive)});
		}
		finally
		{
			await fs.rm(directory, {recursive: true, force: true});
		}
	});
}
