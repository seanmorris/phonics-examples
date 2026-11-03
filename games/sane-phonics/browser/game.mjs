let SdlAudio, QuickControls, SafeStorage;

/** Browser actions shared by the PHP pause menu and the two corner shortcuts. */
export class DemoControls
{

	/**
* Bind accessible shortcuts without starting PHP or opening an audio device.
*/
	constructor(
		audioClass = SdlAudio
		, canvas = document.querySelector('canvas')
		, shortcutsClass = QuickControls
		, storage = new SafeStorage
	){
		this.AudioClass = audioClass;
		this.scene = 'platformer';
		this.abort = new AbortController();
		this.profile = 'runner';
		this.muted = false;
		this.fullscreenBusy = false;
		this.fullscreenError = '';
		this.canvas = canvas;
		this.frame = canvas.closest('#game-frame') ?? canvas;
		this.shortcuts = new shortcutsClass(this.frame === canvas ? canvas.parentElement : this.frame, this.frame);
		this.muteButton = this.shortcuts.muteButton;
		this.fullscreenButton = this.shortcuts.fullscreenButton;
		this.error = this.shortcuts.error;
		this.storage = storage;
		this.muted = this.storage.get('php-wasm-engine-muted') === 'true';

		this.muteButton.addEventListener('click', () => {
			this.setMuted(!this.muted);
			this.canvas.focus({preventScroll: true});
		}, {signal: this.abort.signal});

		this.fullscreenButton.addEventListener(
			'click'
			, () => this.setFullscreen(!this.fullscreen)
			, {signal: this.abort.signal}
		);

		document.addEventListener('fullscreenchange', () => {
			this.update();
			this.canvas.focus({preventScroll: true});
		}, {signal: this.abort.signal});

		this.update();
	}

	/** @returns {boolean} Whether this browser permits requesting fullscreen. */
	get fullscreenAvailable()
	{
		return Boolean(document.fullscreenEnabled && this.frame.requestFullscreen);
	}

	/** @returns {boolean} Whether the demo is currently the browser's fullscreen element. */
	get fullscreen()
	{
		return document.fullscreenElement === this.frame;
	}

	/**
* Install the audio route before PHP can open an SDL output device.
* @param {object} module Fresh php-sdl-wasm module owned by the demo.
* @returns {void} Carries the mute preference across runtime changes.
*/
	attach(module)
	{
		this.audio?.close();
		this.audio = new this.AudioClass(module, this.muted);
	}

	/**
* Store and apply the shared mute preference.
* @param {boolean} muted Whether to silence game audio.
* @returns {void} Updates audio, button state, and optional local persistence.
*/
	setMuted(muted)
	{
		this.muted = Boolean(muted);
		this.audio?.setMuted(this.muted);

		this.storage.set('php-wasm-engine-muted', String(this.muted));

		this.update();
	}

	/**
* Request browser fullscreen and report gesture/permission failures beside the game.
* @param {boolean} enabled Whether the demo should occupy the fullscreen view.
* @returns {Promise<void>} Settles after success or a visible, recoverable error.
*/
	async setFullscreen(enabled)
	{
		if(this.fullscreenBusy || !this.fullscreenAvailable || enabled === this.fullscreen)
		{
			return;
		}

		this.fullscreenBusy = true;
		this.fullscreenError = '';
		this.update();

		try
		{
			if(enabled)
			{
				await this.frame.requestFullscreen();
			}
			else
			{
				await document.exitFullscreen();
			}
		}
		catch
		{
			this.fullscreenError = 'Fullscreen could not be changed. '
				+ 'Try the corner fullscreen button.';
		}
		finally
		{
			this.fullscreenBusy = false;
			this.update();
			this.canvas.focus({preventScroll: true});
		}
	}

	/** @returns {void} Synchronizes labels, pressed states, and fullscreen error output. */
	update()
	{
		this.shortcuts.setMuted(this.muted);

		this.shortcuts.setFullscreen(
			this.fullscreen
			, this.fullscreenAvailable
			, this.fullscreenBusy
			, this.fullscreenError
		);
	}
}

/** Initialize the package-owned browser controls before the PHP entry point runs. */
export function activate(context)
{
	SdlAudio = context.services.SdlAudio;
	QuickControls = context.services.QuickControls;
	SafeStorage = context.services.SafeStorage;
	const shared = globalThis[Symbol.for('sane-phonics.legacy-controls')];
	const controls = shared ?? new DemoControls(SdlAudio, context.canvas);
	controls.scene = context.config['sane.scene'] ?? 'platformer';
	controls.profile = context.config['sane.profile'] ?? 'runner';
	controls.attach(context.module);

	Object.assign(context.module, {
		demoControls: controls
		, scene: controls.scene
		, profile: controls.profile
		, motionBlur: context.config['sane.motionBlur'] !== false
		, saneWorld: JSON.stringify({
			...context.world
			, sourceUrl: context.session.url
			, start: context.start
			, scene: controls.scene
		})
		, saneIntro: context.config['sane.intro'] === true
	});

	return () => {
		if(!shared)
		{
			controls.abort.abort();
			controls.shortcuts.close();
		}

		controls.audio?.close();
	};
}
