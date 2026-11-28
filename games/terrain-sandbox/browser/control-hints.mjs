/** Use the HUD's native text alongside the active input device's button artwork. */
export async function activate({archive, canvas, module})
{
	const data = await archive.read('core/resources/buttons.tsj');
	const sheet = JSON.parse(new TextDecoder().decode(data));
	const bytes = await archive.read('core/resources/' + sheet.image);
	const url = URL.createObjectURL(new Blob([bytes], {type: 'image/png'}));
	const buttons = new Map();

	for(const tile of sheet.tiles)
	{
		const p = Object.fromEntries(tile.properties.map(({name, value}) => [name, value]));
		// The keyboard tiles include lettering inside each key, and WASD shares one tile.
		const scale = p['glyph.theme'] === 'keyboard' ? 1.75 : 1.5;
		const unit = scale / p['glyph.pixelRatio'];
		const x = tile.id % sheet.columns * sheet.tilewidth + (p['glyph.cropX'] ?? 0);
		const y = Math.floor(tile.id / sheet.columns) * sheet.tileheight + (p['glyph.cropY'] ?? 0);
		const icon = document.createElement('span');
		icon.className = 'terrain-hint-button';
		icon.setAttribute('aria-hidden', 'true');
		icon.style.width = p['glyph.width'] * unit + 'px';
		icon.style.height = p['glyph.height'] * unit + 'px';
		icon.style.backgroundSize = sheet.imagewidth * unit + 'px ' + sheet.imageheight * unit + 'px';
		icon.style.backgroundPosition = -x * unit + 'px ' + -y * unit + 'px';
		buttons.set(p['glyph.theme'] + ':' + p['glyph.char'], icon);
	}

	const hints = canvas.closest('.terrain-sandbox').querySelector('.terrain-control-hints');
	hints.style.setProperty('--hint-buttons', `url("${url}")`);
	let previous = '';

	module.updateTerrainHints = ({mode, jetView, hint, inputTheme}) => {
		hints.hidden = !hint.length || (mode === 'jet' && jetView === 'cockpit');
		const key = JSON.stringify([inputTheme, hint]);

		if(hints.hidden || key === previous) return;

		previous = key;

		hints.replaceChildren(...hint.map(entry => {
			const item = document.createElement('span');

			for(const word of entry.split(' '))
			{
				if(item.hasChildNodes()) item.append(' ');

				const icon = buttons.get(inputTheme + ':' + word) ?? buttons.get('generic:' + word);
				item.append(icon ? icon.cloneNode() : word);
			}

			return item;
		}));
	};

	return () => {
		delete module.updateTerrainHints;
		URL.revokeObjectURL(url);
	};
}
