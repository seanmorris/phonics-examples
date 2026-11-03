import {Phonics} from './engine/browser/Phonics.mjs';

const canvas = document.querySelector('canvas');
const loading = document.querySelector('.loading');
const label = loading?.querySelector('span');
const controller = new AbortController;
let session;

const runtimeFingerprint = async url => {
	const manifest = await fetch(url, {signal: controller.signal}).then(response => {
		if(!response.ok) throw Error(`Runtime manifest returned ${response.status}`);
		return response.json();
	});
	const declaration = [
		'Phonics php-sdl-wasm runtime v1'
		, String(manifest.schema)
		, String(manifest.packageName)
		, String(manifest.phpVersion)
		, ...[...(manifest.files ?? [])].sort((a,b) => a.path.localeCompare(b.path))
			.flatMap(file => [String(file.path),String(file.sha256),String(file.bytes)])
	].join('\0');
	const digest = await crypto.subtle.digest('SHA-256', new TextEncoder().encode(declaration));
	return [...new Uint8Array(digest)].map(value => value.toString(16).padStart(2,'0')).join('');
};

try
{
	const application = new URL(document.documentElement.dataset.application, location.href);
	const runtimeUrl = new URL('./runtime/php8.4-sdl.mjs', import.meta.url);
	const runtimeId = await runtimeFingerprint(new URL('./runtime/php8.4-sdl.manifest.json', import.meta.url));
	const integrity = await fetch(application.href + '.integrity', {signal: controller.signal}).then(response => {
		if(!response.ok) throw Error(`Integrity metadata returned ${response.status}`);

		return response.text();
	});

	session = await Phonics.run(application, {
		canvas
		, integrity: integrity.trim()
		, signal: controller.signal
		, runtime: {url: runtimeUrl.href,id: runtimeId}
		, policy: {codeOrigins: [location.origin]}
		, onProgress: progress => { if(label) label.textContent = progress.label; }
	});

	loading?.classList.add('complete');
	canvas.focus({preventScroll: true});
}
catch(error)
{
	if(!controller.signal.aborted)
	{
		loading?.classList.add('failed');
		if(label) label.textContent = error.message;
		console.error(error);
	}
}

addEventListener('pagehide', () => { controller.abort(); void session?.dispose(); }, {once: true});
