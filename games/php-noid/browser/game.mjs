/** Accessible game UI, local scores, and original synthesized sound effects. */
export function activate({module, canvas, onClose, session, services, assets, packageUrl})
{
	// Pixel artwork owns the first context request; multisampling would soften moving edges.
	if(!canvas.getContext('webgl2', {antialias: false})) throw Error('PHP-Noid requires WebGL2');

	const objectUrls = [];

	const assetBlob = (path, type) => {
		const source = new URL(path, packageUrl).href;
		const url = URL.createObjectURL(new Blob([module.FS.readFile(assets.path(source))], {type}));
		objectUrls.push(url);

		return url;
	};

	const font = JSON.parse(
		new TextDecoder().decode(module.FS.readFile(assets.path(new URL('assets/font.json', packageUrl).href)))
	);

	const fontUrl = assetBlob('assets/font.png', 'image/png');
	const glyphs = new Map(font.characters.map((character, index) => [String(character), index]));

	const ui = document.createElement('section');
	ui.className = 'php-noid-ui';
	ui.setAttribute('aria-label', 'PHP-Noid controls and scores');
	ui.innerHTML = `<div class="php-noid-screen">
		<aside class="php-noid-hud" aria-label="Game status">
				<div class="php-noid-brand" aria-label="PHP-Noid"><div class="php-noid-brand-title">
					<b class="php-noid-pixel">PHP</b><em>/</em><b class="php-noid-pixel">NOID</b></div>
					<span class="php-noid-stage-name php-noid-pixel">First Light</span></div>
			<div class="php-noid-instrument"><span class="php-noid-label php-noid-pixel">Score</span>
				<strong class="php-noid-score php-noid-pixel">000000</strong>
				<small><span class="php-noid-pixel">BEST</span> <span class="php-noid-best php-noid-pixel">000000</span></small></div>
				<div class="php-noid-instrument"><span class="php-noid-label php-noid-pixel">Level</span>
					<strong class="php-noid-level php-noid-pixel">01 / 25</strong></div>
			<div class="php-noid-instrument"><span class="php-noid-label php-noid-pixel">Paddles</span>
				<div class="php-noid-paddles"><span class="php-noid-lives php-noid-pixel">× 3</span>
					<span class="php-noid-life-icons" aria-hidden="true">${'<i></i>'.repeat(6)}</span></div></div>
			<div class="php-noid-instrument php-noid-equipment"><span class="php-noid-label php-noid-pixel">Power-ups</span>
				<div class="php-noid-powers"></div><small class="php-noid-standard php-noid-pixel">Standard · Collect capsules</small></div>
			<p class="php-noid-notice php-noid-pixel" aria-live="polite"></p>
			<div class="php-noid-console-foot"><p class="php-noid-prompt php-noid-pixel" role="status"></p>
				<small class="php-noid-pixel">← → MOVE · P PAUSE</small></div>
		</aside><div class="php-noid-title" aria-label="PHP-Noid title screen">
			<div class="php-noid-title-mark" aria-label="PHP-Noid"><span class="php-noid-pixel">PHP</span><b>/</b><span class="php-noid-pixel">NOID</span></div>
			<p class="php-noid-pixel">BREAK THE RUNTIME</p>
			<div class="php-noid-title-menu" role="menu" aria-label="Main menu">
				<button type="button" role="menuitem" data-action="start"><span class="php-noid-pixel">Start Game</span></button>
					<button type="button" role="menuitem" data-action="php-info"><span class="php-noid-pixel">PHP Info</span></button>
				</div><small class="php-noid-pixel">↑ ↓ SELECT · ENTER / START</small>
			</div><button type="button" class="php-noid-info-back" aria-label="Back to title screen" hidden>
				</button><div class="php-noid-transition" role="status" hidden>
			<span class="php-noid-transition-label php-noid-pixel"></span><strong class="php-noid-transition-title php-noid-pixel"></strong></div></div>
		<div class="php-noid-guide"><details><summary>Controls and capsules</summary>
		<p>Mouse / ← → / A D move. Left click / Space / controller A launches and fires.
		Right click / P / Escape / controller Start pauses. R restarts.
		Catch holds the ball on the middle of the paddle; fire releases it.
		Compatible powers stack with separate timers. Expand / Shrink, Fast / Slow, and Lasers / Missiles
		replace each other. Collecting the same power refreshes its timer. Extra balls and lives keep your equipment.</p>
		<div class="php-noid-legend"></div>
		<p>Colored Menacer balls affect bricks or hide the paddle; only your white balls preserve a life.
		Red energy clears green metal. Red-outlined dark projectiles are lethal, even during multiball.
		Ships take three laser hits or one missile. A red rescue ball appears if green bricks lose their red source.</p>
		</details><details class="php-noid-scores"><summary>Top 10 · this world</summary><ol></ol></details></div>`;

	const style = document.createElement('style');
	style.textContent = `.php-noid-ui{--php-noid-font:url("${fontUrl}");font:14px/1.6 system-ui,sans-serif;width:100%;max-width:854px;margin:auto}
		.php-noid-screen{position:relative;display:block;aspect-ratio:427/240;container-type:inline-size;
			background:#070b1b;isolation:isolate}
		.php-noid-screen>canvas{display:block;width:100%;height:100%;min-width:0;min-height:0;max-width:none;
			margin:0;border:0;border-radius:0;image-rendering:pixelated}
		.php-noid-screen.php-noid-title-active>canvas{position:absolute;left:0;top:0;
			width:var(--title-pixel-width);height:var(--title-pixel-height);
			transform:translate(var(--title-pixel-x),var(--title-pixel-y))}
		.php-noid-title{position:absolute;inset:0;z-index:1;pointer-events:none}
		.php-noid-title[hidden]{display:none}.php-noid-screen>.quick-controls{z-index:3}
		.php-noid-title-mark,.php-noid-title>p,.php-noid-title>small,
		.php-noid-title-menu .php-noid-pixel{position:absolute;width:1px;height:1px;overflow:hidden;
			clip-path:inset(50%);white-space:nowrap}
		.php-noid-title-menu{position:absolute;inset:0}
		.php-noid-title-menu button{position:absolute;left:33.607%;width:32.787%;height:8.333%;
			padding:0;border:0;background:transparent;color:transparent;box-shadow:none;cursor:pointer;pointer-events:auto}
		.php-noid-title-menu [data-action="start"]{top:50.833%}
		.php-noid-title-menu [data-action="php-info"]{top:62.5%}
		.php-noid-title-menu button:is(:hover,:focus,:active){background:transparent;color:transparent;box-shadow:none}
		.php-noid-title-menu button:hover,.php-noid-title-menu button:focus-visible{outline:2px solid #8bffaf;outline-offset:3px}
		.php-noid-ui .php-noid-info-back{position:absolute;z-index:4;padding:0;border:0;background:transparent;
			color:transparent;box-shadow:none;cursor:pointer}
		.php-noid-ui .php-noid-info-back:is(:hover,:focus,:active){background:transparent;color:transparent;box-shadow:none}
		.php-noid-ui .php-noid-info-back:hover,.php-noid-ui .php-noid-info-back:focus-visible{outline:2px solid #8bffaf;outline-offset:-2px}
			.php-noid-info-back[hidden]{display:none}
		.php-noid-transition{position:absolute;inset:0 0 0 26.932%;display:flex;flex-direction:column;
			justify-content:center;align-items:center;gap:1cqw;padding:2cqw;text-align:center;pointer-events:none;
			color:#e4efff;font:2cqw/1.4 ui-monospace,monospace;text-shadow:0 2px 8px #000}
		.php-noid-transition[hidden]{display:none}
		.php-noid-transition-label{letter-spacing:.3em;color:#ffc86b}
		.php-noid-transition-title{font-size:2em}
		.php-noid-hud{grid-area:1/1;min-width:0;min-height:0;padding:1.5cqw 1.7cqw 1.5cqw 1.5cqw;display:flex;flex-direction:column;
				gap:.85cqw;color:#e4efff;font:max(10px,1.35cqw)/1.3 ui-monospace,monospace;
			background-color:#0b1118;background-image:linear-gradient(#05080d22,#05080d66),var(--php-noid-hud);
			background-repeat:no-repeat;background-size:100% 100%;image-rendering:pixelated;
			box-shadow:inset 1px 0 #a5b0ad,inset -1px 0 #05080d}
			.php-noid-hud p{margin:0}.php-noid-hud small{font-size:max(.75em,8px);color:#90a4b5}
		.php-noid-brand{font-weight:900;font-size:1.3em;color:#e4efff}
		.php-noid-brand-title{display:flex;align-items:baseline;justify-content:flex-start;gap:.28em}
		.php-noid-brand b{font:inherit}.php-noid-brand em{color:#ffc86b;font-style:normal}
		.php-noid-brand .php-noid-stage-name{display:block;margin-top:.25em;color:#b9e9ff;font-size:max(.72em,8px);
			font-weight:normal;text-align:left}
		.php-noid-instrument{padding:.6cqw .8cqw;background:#060d16e8;box-shadow:inset 1px 1px #000,1px 1px #53656e}
			.php-noid-label{display:block;text-transform:uppercase;letter-spacing:.14em;font-size:max(.8em,8px);
			color:#ffc86b;text-shadow:1px 1px #593309;margin-bottom:.25em}
		.php-noid-score{display:block;font-size:2em;letter-spacing:.02em;font-variant-numeric:tabular-nums}
		.php-noid-level{display:block;font-size:1.35em;color:#b9e9ff;font-variant-numeric:tabular-nums}
		.php-noid-stage-name{overflow-wrap:anywhere}
		.php-noid-paddles{display:flex;align-items:center;gap:.7em;min-height:1.5em}
		.php-noid-lives{white-space:nowrap;font-weight:bold}.php-noid-life-icons{display:flex;flex-wrap:wrap;gap:.3em}
		.php-noid-life-icons i{width:1.5em;height:.5em;box-sizing:border-box;border-inline:.2em solid #ff637e;
			background:linear-gradient(#fff,#93bfd1 45%,#3b5767);box-shadow:0 1px #243e4c}
		.php-noid-life-icons i[hidden]{display:none}
		.php-noid-power-row{display:grid;grid-template-columns:auto minmax(0,1fr) auto;gap:.15em .4em;
			align-items:center;margin-top:.35em}
			.php-noid-power{font-size:max(.8em,8px)}.php-noid-power-symbol{background:var(--power-color,#b9e9ff);color:#071120;
			min-width:1.5em;line-height:1.4;text-align:center;box-shadow:inset 0 1px #ffffff80}
		.php-noid-power-time{font-variant-numeric:tabular-nums}
		.php-noid-power-meter{grid-column:1/-1;display:block;width:100%;height:.25em;margin:0;border:0;
			background:#25343f;accent-color:var(--power-color,#b9e9ff)}
		.php-noid-power-meter::-webkit-progress-bar{background:#25343f}
		.php-noid-power-meter::-webkit-progress-value{background:var(--power-color,#b9e9ff)}
		.php-noid-power-meter::-moz-progress-bar{background:var(--power-color,#b9e9ff)}
			.php-noid-notice{color:#b9e9ff;font-size:max(.85em,8px)}
			.php-noid-console-foot{margin-top:auto;padding:.8cqw .4cqw .2cqw;border-top:1px solid #61767c;background:#05080d99}
			.php-noid-prompt{font-size:max(.85em,8px)}.php-noid-console-foot small{display:block;margin-top:.5em;font-size:max(.6em,8px)}
		.php-noid-pixel{letter-spacing:0;text-transform:uppercase}
		.php-noid-pixel-source{position:absolute!important;width:1px!important;height:1px!important;padding:0!important;
			margin:-1px!important;overflow:hidden!important;clip:rect(0,0,0,0)!important;white-space:nowrap!important;border:0!important}
			.php-noid-pixel-glyphs{display:inline;max-width:100%;vertical-align:-.06em;white-space:normal}
			.php-noid-pixel-word{display:inline-flex;align-items:center;gap:.045em;height:1em;white-space:nowrap}
			.php-noid-pixel-space{display:inline-block;width:.36em}
		.php-noid-glyph{display:block;flex:0 0 .75em;width:.75em;height:1em;background-color:currentColor;
			-webkit-mask-image:var(--php-noid-font);mask-image:var(--php-noid-font);
			-webkit-mask-repeat:no-repeat;mask-repeat:no-repeat;
			-webkit-mask-size:calc(${font.columns} * .75em) calc(${font.rows} * 1em);
			mask-size:calc(${font.columns} * .75em) calc(${font.rows} * 1em);
			-webkit-mask-position:calc(var(--glyph-x) * -.75em) calc(var(--glyph-y) * -1em);
			mask-position:calc(var(--glyph-x) * -.75em) calc(var(--glyph-y) * -1em);image-rendering:pixelated}
			.php-noid-glyph.php-noid-space{flex-basis:.36em;width:.36em;background:none;-webkit-mask-image:none;mask-image:none}
			@container (max-width:480px){
				.php-noid-hud{font-size:8px;gap:.3cqw;padding:1cqw 1.2cqw}
				.php-noid-console-foot small,.php-noid-notice,
				.php-noid-life-icons,.php-noid-standard,.php-noid-power-time,
				.php-noid-instrument>small:not(.php-noid-standard){display:none}
				.php-noid-brand{font-size:1.2em}.php-noid-score{font-size:1em}.php-noid-level{font-size:1.1em}
				.php-noid-instrument:not(.php-noid-equipment){display:grid;grid-template-columns:auto minmax(0,1fr);
					align-items:center;gap:.3em}.php-noid-instrument:not(.php-noid-equipment) .php-noid-label{margin:0}
				.php-noid-instrument{padding:.3cqw .5cqw}.php-noid-label{margin-bottom:.1em}
				.php-noid-power-row{margin-top:.12em;gap:.05em .25em}.php-noid-console-foot{padding:.3cqw}}
		.php-noid-guide{padding:.5rem}.php-noid-ui summary{cursor:pointer}
		.php-noid-legend{display:flex;flex-wrap:wrap;gap:.5rem 1rem}.php-noid-legend span{white-space:nowrap}
		.php-noid-legend b{display:inline-block;color:#071120;min-width:1.6em;text-align:center;margin-right:.4rem}
		.php-noid-ui details{margin:.65rem 0}.php-noid-scores ol{padding-left:2rem}
		.php-noid-frame:fullscreen{display:grid;grid-template: minmax(0,1fr) / minmax(0,1fr);
			align-items:center;justify-items:center;width:100vw;height:100vh;max-width:none;margin:0;padding:0;
			box-sizing:border-box;overflow:hidden;background:#080d21}
		.php-noid-frame:fullscreen .php-noid-ui{grid-area:1/1;min-width:0;max-width:none;margin:0;
			width:var(--php-noid-fullscreen-width)}
		.php-noid-frame:fullscreen .php-noid-guide{display:none}
		.php-noid-ui.php-noid-frame:fullscreen .php-noid-screen{width:var(--php-noid-fullscreen-width)}
		.php-noid-hud{position:absolute!important;width:1px!important;height:1px!important;padding:0!important;
			margin:-1px!important;overflow:hidden!important;clip:rect(0,0,0,0)!important;clip-path:inset(50%)!important;
			white-space:nowrap!important;border:0!important}
		.php-noid-frame::backdrop{background:#080d21}`;

	ui.prepend(style);

	const pixelText = new WeakMap();

	const setPixelText = (element, value) => {
		value = String(value);

		if(pixelText.get(element) === value) return;

		pixelText.set(element, value);
		const source = document.createElement('span'), artwork = document.createElement('span');
		source.className = 'php-noid-pixel-source';
		source.textContent = value;
		artwork.className = 'php-noid-pixel-glyphs';
		artwork.setAttribute('aria-hidden', 'true');

		for(const token of value.split(/( +)/u))
		{
			if(!token) continue;

			if(/^ +$/u.test(token))
			{
				for(const character of token)
				{
					void character;
					artwork.append(document.createElement('wbr'));
					const space = document.createElement('i');
					space.className = 'php-noid-pixel-space';
					artwork.append(space);
				}

				continue;
			}

			const word = document.createElement('span');
			word.className = 'php-noid-pixel-word';

			for(const character of token)
			{
				const glyph = document.createElement('i');
				glyph.className = 'php-noid-glyph';
				const index = glyphs.get(character) ?? glyphs.get(character.toUpperCase()) ?? glyphs.get('?');
				glyph.style.setProperty('--glyph-x', index % font.columns);
				glyph.style.setProperty('--glyph-y', Math.floor(index / font.columns));
				word.append(glyph);
			}

			artwork.append(word);
		}

		element.replaceChildren(source, artwork);
	};

	for(const element of ui.querySelectorAll('.php-noid-pixel')) setPixelText(element, element.textContent);
	// Restore the caller's exact canvas position when this session closes.
	const slot = document.createComment('PHP-Noid canvas');
	canvas.before(slot, ui);
	const screen = ui.querySelector('.php-noid-screen');
	screen.append(canvas);
	screen.classList.add('php-noid-title-active');
	const notice = ui.querySelector('.php-noid-notice');
	const transition = ui.querySelector('.php-noid-transition');
	const title = ui.querySelector('.php-noid-title');
	const titleButtons = [...ui.querySelectorAll('.php-noid-title-menu button')];
	const infoBack = ui.querySelector('.php-noid-info-back');
	const controller = new AbortController();
	const {signal} = controller;
	let titleAction = '';

	module.phpNoidAction = () => {
		const action = titleAction;
		titleAction = '';

		return action;
	};

	for(const button of titleButtons)
	{
		button.addEventListener('click', () => {
			titleAction = button.dataset.action;
			canvas.focus({preventScroll: true});
		}, {signal});
	}

	infoBack.addEventListener('click', () => {
		titleAction = 'close-info';
		canvas.focus({preventScroll: true});
	}, {signal});

	const instruments = Object.fromEntries(['score'
		, 'best'
		, 'level'
		, 'stage-name'
		, 'lives'
		, 'powers'
		, 'standard'
		, 'prompt'
		, 'transition-label'
		, 'transition-title'
	].map(name => [name, ui.querySelector('.php-noid-' + name)])
	);

	const lifeIcons = [...ui.querySelectorAll('.php-noid-life-icons i')];
	let lastLives;
	const powerRows = new Map();

	const setText = (element, value) => {
		if(element.classList.contains('php-noid-pixel')) setPixelText(element, value);
		else if(element.textContent !== value) element.textContent = value;
	};

	const scoresList = ui.querySelector('ol');

	const storage = new services.SafeStorage;

	const read = key => storage.get(key);

	const write = (key, value) => storage.set(key, value);

	const scoreKey = `php-noid.scores.v1:${session.url}`;
	let scores = [];

	try
	{
		const stored = JSON.parse(read(scoreKey) || '[]');

		if(Array.isArray(stored))
		scores = stored.filter(
			entry => Number.isSafeInteger(entry?.score) && entry.score >= 0 && Number.isSafeInteger(entry.stage)
				&& entry.stage > 0
		).slice(0, 10);
	}
	catch
	{
		/* A damaged local score table starts empty. */
	}

	const showScores = () => {
		scoresList.replaceChildren();

		for(const score of scores)
		{
			const row = document.createElement('li');
			row.textContent = `${score.score} points · Stage ${score.stage}${score.complete ? ' · Complete' : ''}`;
			scoresList.append(row);
		}

		if(!scores.length)
		{
			const row = document.createElement('li');
			row.textContent = 'Finish a run to record a score on this device.';
			scoresList.append(row);
		}
	};

	showScores();

	module.phpNoidBest = () => scores[0]?.score ?? 0;
	let audio, recordedRun, legendReady = false;
	let muted = read('phonics.muted') === 'true';
	const frame = canvas.closest('#game-frame') ?? ui;
	const shortcuts = new services.QuickControls(ui.querySelector('.php-noid-screen'), frame);
	const mute = shortcuts.muteButton, fullscreen = shortcuts.fullscreenButton;

	const updateMute = () => shortcuts.setMuted(muted);

	updateMute();

	mute?.addEventListener('click', () => {
		muted = !muted;
		write('phonics.muted', String(muted));
		updateMute();
		canvas.focus({preventScroll: true});
	}, {signal});

	let fullscreenBusy = false, fullscreenOperation = Promise.resolve();
	let fullscreenError = '';
	let wasFullscreen = document.fullscreenElement === frame;

	frame.classList.add('php-noid-frame');

	const fitFullscreen = () => {
		if(document.fullscreenElement !== frame) return;

		const width = Math.max(0, Math.min(frame.clientWidth, frame.clientHeight * 427 / 240));
		ui.style.setProperty('--php-noid-fullscreen-width', `${width}px`);
	};

	const updateFullscreen = () => {
		const active = document.fullscreenElement === frame;

		shortcuts.setFullscreen(
			active
			, document.fullscreenEnabled && frame.requestFullscreen
			, fullscreenBusy
			, fullscreenError
		);
	};

	let titleSize;

	const measureTitle = () => {
		const box = screen.getBoundingClientRect(), ratio = window.devicePixelRatio || 1;
		screen.style.setProperty('--title-pixel-x', `${Math.round(box.x * ratio) / ratio - box.x}px`);
		screen.style.setProperty('--title-pixel-y', `${Math.round(box.y * ratio) / ratio - box.y}px`);
		screen.style.setProperty('--title-pixel-width', `${Math.round(box.width * ratio) / ratio}px`);
		screen.style.setProperty('--title-pixel-height', `${Math.round(box.height * ratio) / ratio}px`);

		titleSize = JSON.stringify([
			Math.max(1, Math.round(box.width * ratio))
			, Math.max(1, Math.round(box.height * ratio))
			, ratio, box.width
		]);
	};

	measureTitle();

	module.phpNoidTitleSize = () => titleSize;
	window.addEventListener('resize', measureTitle, {signal});
	window.addEventListener('scroll', measureTitle, {signal, capture: true});

	const resize = new ResizeObserver(() => {
		fitFullscreen();
		measureTitle();
	});

	resize.observe(frame);
	resize.observe(ui);

	const dimensions = new MutationObserver(fitFullscreen);
	dimensions.observe(canvas, {attributes: true, attributeFilter: ['width', 'height']});
	updateFullscreen();

	document.addEventListener('fullscreenchange', () => {
		const active = document.fullscreenElement === frame;
		updateFullscreen();
		fitFullscreen();

		if(active || wasFullscreen) canvas.focus({preventScroll: true});

		wasFullscreen = active;
	}, {signal});

	const toggleFullscreen = async () => {
		if(fullscreenBusy) return;

		fullscreenBusy = true;

		fullscreenError = '';

		updateFullscreen();

		try
		{
			if(document.fullscreenElement === frame) await document.exitFullscreen();
			else await frame.requestFullscreen();
		}
		catch(error)
		{
			if(!signal.aborted) fullscreenError = error.message;
		}
		finally
		{
			fullscreenBusy = false;

			if(!signal.aborted)
			{
				updateFullscreen();
				fitFullscreen();
				canvas.focus({preventScroll: true});
			}
		}
	};

	fullscreen?.addEventListener('click', () => { fullscreenOperation = toggleFullscreen(); }, {signal});

	const unlock = () => { audio ??= new AudioContext(); audio.resume(); };

	const oldTouchAction = canvas.style.touchAction;
	canvas.style.touchAction = 'none';

	canvas.addEventListener('pointerdown', event => {
		canvas.focus({preventScroll: true});
		canvas.setPointerCapture(event.pointerId);
		unlock();
	}, {signal});

	canvas.addEventListener('keydown', unlock, {signal});
	canvas.addEventListener('contextmenu', event => event.preventDefault(), {signal});
	const sounds = {hit: [520, .04], power: [880, .16], loss: [110, .3], stage: [1040, .25], shoot: [220, .06]};

	module.phpNoidStatus = json => {
		const state = JSON.parse(json);
		canvas.dataset.phpNoid = json;
		screen.classList.toggle('php-noid-title-active', Boolean(state.title));
		title.hidden = !state.title || Boolean(state.phpInfo?.open);
		infoBack.hidden = !state.phpInfo?.open || !state.phpInfo?.backHint;

		if(!infoBack.hidden)
		{
			const [x, y, width, height] = state.phpInfo.backHint;
			infoBack.style.left = `${100 * x / canvas.width}%`;
			infoBack.style.top = `${100 * y / canvas.height}%`;
			infoBack.style.width = `${100 * width / canvas.width}%`;
			infoBack.style.height = `${100 * height / canvas.height}%`;
		}

		for(const button of titleButtons)
		button.classList.toggle('is-selected', button.dataset.action === state.titleSelection);

		const prompt = state.paused
			? 'Paused · P / right click to resume'
			: state.phase === 'gameover'
				? 'Game over · Fire to continue · R to restart'
				: state.phase === 'complete'
					? 'Campaign complete · Fire to play again'
					: state.phase === 'clearing'
						? 'Level clear'
						: state.phase === 'loading'
							? 'Loading stage…'
							: state.phase === 'entering' ? 'Get ready' : 'Click / Space to launch & fire';

		const compactPrompt = state.paused
			? 'Paused'
			: state.phase === 'gameover'
				? 'Game over'
				: state.phase === 'complete'
					? 'Complete'
					: state.phase === 'clearing'
						? 'Level clear'
						: state.phase === 'loading'
							? 'Loading…'
							: state.phase === 'entering' ? 'Get ready' : 'Launch / Fire';

		const transitioning = ['clearing', 'loading', 'entering', 'complete'].includes(state.phase);

		if(transition.hidden === transitioning) transition.hidden = !transitioning;

		if(transitioning)
		{
			const label = state.phase === 'complete'
				? 'CAMPAIGN CLEAR'
				: `LEVEL ${String(
					(state.phase === 'loading' ? state.nextStage ?? state.stage : state.stage) + 1
				).padStart(
					2
					, '0'
				)}`;

			const title = state.phase === 'clearing'
				? 'Level clear'
				: state.phase === 'loading'
					? 'Loading…'
					: state.phase === 'complete' ? 'All levels cleared' : state.name;

			setText(instruments['transition-label'], label);
			setText(instruments['transition-title'], title);
			const opacity = String(state.fade);

			if(transition.style.opacity !== opacity) transition.style.opacity = opacity;
		}

		setText(instruments.score, String(state.score).padStart(6, '0'));
		setText(instruments.best, String(Math.max(state.score, scores[0]?.score ?? 0)).padStart(6, '0'));
		setText(instruments.level, `${String(state.stage + 1).padStart(2, '0')} / ${state.stageCount}`);
		setText(instruments['stage-name'], state.name);
		setText(instruments.lives, state.infiniteLives ? '× Infinite' : `× ${state.lives}`);
		setText(instruments.prompt, screen.clientWidth <= 480 ? compactPrompt : prompt);
		setText(notice, state.error || (state.banner > 0 ? state.notice || state.name : ''));

		if(state.lives !== lastLives)
		{
			lastLives = state.lives;
			lifeIcons.forEach((icon, index) => { icon.hidden = index >= state.lives; });
		}

		for(const [kind, row] of powerRows)
		{
			if(state.powers[kind] > 0) continue;

			row.element.remove();
			powerRows.delete(kind);
		}

		for(const [kind, seconds] of Object.entries(state.powers))
		{
			const power = state.capsules[kind];
			let row = powerRows.get(kind);

			if(!row)
			{
				const element = document.createElement('div');
				element.className = 'php-noid-power-row';
				element.dataset.power = kind;

				element.style.setProperty(
					'--power-color'
					, `rgb(${power.color.map(value => Math.round(value * 255)).join(' ')})`
				);

				element.innerHTML = '<b class="php-noid-power-symbol" aria-hidden="true"></b>'
					+ '<strong class="php-noid-power php-noid-pixel"></strong><small class="php-noid-power-time php-noid-pixel"></small>'
					+ '<progress class="php-noid-power-meter"></progress>';

				element.querySelector('b').textContent = power.symbol;
				setPixelText(element.querySelector('strong'), power.label);
				row = {element, time: element.querySelector('small'), meter: element.querySelector('progress')};
				row.meter.max = power.duration;
				row.meter.setAttribute('aria-label', `${power.label} time remaining`);
				powerRows.set(kind, row);
				instruments.powers.append(element);
			}

			const time = Math.max(0, Math.ceil(seconds));
			setText(row.time, `${time}s`);
			// Match HTML progress clamping so extended powers don't repaint every frame.
			const remaining = Math.min(time, row.meter.max);

			if(row.meter.value !== remaining) row.meter.value = remaining;
		}

		if(instruments.standard.hidden !== Boolean(powerRows.size))
		instruments.standard.hidden = Boolean(powerRows.size);

		if(!legendReady)
		{
			legendReady = true;

			for(const capsule of Object.values(state.capsules))
			{
				const entry = document.createElement('span'), symbol = document.createElement('b');
				symbol.textContent = capsule.symbol;
				symbol.style.backgroundColor = `rgb(${capsule.color.map(value => Math.round(value * 255)).join(' ')})`;
				entry.append(symbol, capsule.label);
				ui.querySelector('.php-noid-legend').append(entry);
			}
		}

		if(['gameover', 'complete'].includes(state.phase) && recordedRun !== state.run)
		{
			recordedRun = state.run;
			scores.push({score: state.score, stage: state.stage + 1, complete: state.phase === 'complete'});
			scores.sort((a, b) => b.score - a.score || b.stage - a.stage);
			scores = scores.slice(0, 10);
			write(scoreKey, JSON.stringify(scores));
			showScores();
		}

		for(const event of state.events)
		{
			if(muted || !sounds[event] || audio?.state !== 'running') continue;

			const [frequency, duration] = sounds[event];
			const oscillator = audio.createOscillator(), gain = audio.createGain();
			oscillator.type = 'triangle';
			oscillator.frequency.setValueAtTime(frequency, audio.currentTime);
			gain.gain.setValueAtTime(.04, audio.currentTime);
			gain.gain.exponentialRampToValueAtTime(.001, audio.currentTime + duration);
			oscillator.connect(gain).connect(audio.destination);
			oscillator.start();
			oscillator.stop(audio.currentTime + duration);
		}
	};

	onClose(async () => {
		controller.abort();
		resize.disconnect();
		dimensions.disconnect();

		try
		{
			await fullscreenOperation;

			if(document.fullscreenElement === frame) await document.exitFullscreen();
		}
		finally
		{
			frame.classList.remove('php-noid-frame');
			canvas.style.removeProperty('--php-noid-fullscreen-width');
			canvas.style.removeProperty('--php-noid-fullscreen-height');
			updateFullscreen();
			shortcuts.close();
			slot.replaceWith(canvas);
			ui.remove();
			audio?.close();
			canvas.style.touchAction = oldTouchAction;
			delete canvas.dataset.phpNoid;
			delete module.phpNoidStatus;
			delete module.phpNoidAction;
			delete module.phpNoidTitleSize;
			delete module.phpNoidBest;

			for(const url of objectUrls) URL.revokeObjectURL(url);
		}
	});
}
