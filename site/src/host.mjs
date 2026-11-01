import {Phonics} from './engine/browser/Phonics.mjs';

const canvas = document.querySelector('canvas');
const loading = document.querySelector('.loading');
const label = loading?.querySelector('span');
const controller = new AbortController;
let session;

try
{
	const application = new URL(document.documentElement.dataset.application, location.href);
	const integrity = await fetch(application.href + '.integrity', {signal: controller.signal}).then(response => {
		if(!response.ok) throw Error(`Integrity metadata returned ${response.status}`);

		return response.text();
	});

	session = await Phonics.run(application, {
		canvas
		, integrity: integrity.trim()
		, signal: controller.signal
		, runtime: {url: new URL('./runtime/php8.4-sdl.mjs', import.meta.url).href}
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

