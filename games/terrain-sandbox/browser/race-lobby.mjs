const format = ticks => {
	const cs = Math.round(ticks * 100 / 60);

	return Math.floor(cs/6000) + ':' + String(Math.floor(cs/100)%60).padStart(2, '0') + '.'
		+ String(cs%100).padStart(2, '0');
};

const el = (tag, text = '') => Object.assign(document.createElement(tag), {textContent: text});

const safeTitle = s => String(s).replaceAll('-', ' ').slice(0, 70);

const descriptor = spec => {
	const world = spec.world, gates = world.course.gates;
	const points = world.circuit?.route?.points ?? gates.map(g => [g.x,g.z]);
	let meters = 0;

	for(let i = 1; i<points.length; i++) meters += Math.hypot(points[i][0]-points[i-1][0], points[i][1]-points[i-1][1]);

	if(!gates.some(g => g.role === 'finish'))
	meters += Math.hypot(points[0][0]-points.at(-1)[0], points[0][1]-points.at(-1)[1]);

	const settings = world.settings;

	return [safeTitle(world.landscape.recipe.preset)
		, spec.laps + ' lap' + (spec.laps === 1 ? '' : 's')
		, (meters/1609.344).toFixed(1) + ' mi per lap'
		, Math.round(settings.elevation ?? 0) + '° sun'
		, safeTitle(world.weather?.controls?.kind ?? 'clear')
	].join(' · ');
};

function chart(canvas, spec)
{
	const c = canvas.getContext('2d'), w = canvas.width, h = canvas.height;
	c.fillStyle = '#192a2e';
	c.fillRect(0, 0, w, h);
	const points = spec.world.circuit?.route?.points ?? spec.world.course.gates.map(g => [g.x,g.z]);
	let xmin = Infinity,xmax = -Infinity,zmin = Infinity,zmax = -Infinity;

	for(const [x,z] of points)
	{
		xmin = Math.min(xmin, x);
		xmax = Math.max(xmax, x);
		zmin = Math.min(zmin, z);
		zmax = Math.max(zmax, z);
	}

	const scale = Math.min((w-30)/Math.max(1, xmax-xmin), (h-22)/Math.max(1, zmax-zmin));

	const xy = ([x,z]) => [w/2+(x-(xmin+xmax)/2)*scale,h/2-(z-(zmin+zmax)/2)*scale];
	c.strokeStyle = '#375256';
	c.lineWidth = 1;

	for(let x = 0; x<w; x += 24)
	{
		c.beginPath();
		c.moveTo(x, 0);
		c.lineTo(x, h);
		c.stroke();
	}

	for(let y = 0; y<h; y += 24)
	{
		c.beginPath();
		c.moveTo(0, y);
		c.lineTo(w, y);
		c.stroke();
	}

	c.beginPath();
	c.lineWidth = 3;
	c.strokeStyle = '#e6e2bd';
	points.forEach((p, i) => c[i ? 'lineTo' : 'moveTo'](...xy(p)));

	if(!spec.world.course.gates.some(g => g.role==='finish')) c.closePath();

	c.stroke();
	const start = spec.world.course.gates[0], [x,y] = xy([start.x,start.z]);
	c.fillStyle = '#ffad44';
	c.fillRect(x-4, y-4, 8, 8);
	canvas.setAttribute('aria-label', 'Course map. The orange square marks the start.');
}

export function activate({module,session,canvas,signal})
{
	const lobby = session.lobby, overlay = lobby.overlay, provider = lobby.protocol.provider;
	const {RaceMedia,specId,event: ghostEvent} = session.terrainRaceMedia;
	const {LiveCircuit,ghostAt} = session.terrainRaceNet;
	const media = new RaceMedia(provider);
	const {frame} = module.terrainRaceHost;
	overlay.setGameSurface(frame);
	const card = el('section'), preview = el('canvas'), description = el('p'), notice = el('p'), list = el('select');
	const thumbnails = el('div'), landThumb = el('canvas');
	thumbnails.className = 'terrain-race-preview';
	thumbnails.append(landThumb, preview);
	landThumb.hidden = true;
	landThumb.setAttribute('aria-label', 'Landscape relief and water');
	const titled = el('h2', 'Circuit racing'), actions = el('div'), ghostActions = el('div'), results = el('div');
	card.className = 'terrain-race-card';
	actions.className = ghostActions.className = results.className = 'terrain-race-actions';

	const style = el('style', `
	.terrain-race-card{padding:1rem;border:1px solid #73898a;border-radius:8px;background:#17292d;color:#f2f4ee;margin:.5rem 0}
	.terrain-race-card h2,.terrain-race-card h3{margin:.3rem 0 .5rem}.terrain-race-card p{margin:.55rem 0}
	.terrain-race-card [hidden]{display:none!important}.terrain-race-card canvas{display:block;width:100%;height:auto;max-height:130px;object-fit:contain;background:#192a2e}
	.terrain-race-preview{display:flex;align-items:center;gap:.5rem}.terrain-race-preview canvas:first-child{width:25%;max-width:100px}.terrain-race-preview canvas:last-child{flex:1;min-width:0}
	.terrain-race-actions{display:flex;flex-wrap:wrap;gap:.4rem;margin:.5rem 0}.terrain-race-card select{max-width:100%;width:100%}
	.terrain-race-bar{position:absolute;z-index:12;left:50%;top:1rem;transform:translateX(-50%);display:flex;align-items:center;
	gap:.6rem;padding:.35rem .65rem;border-radius:5px;color:white;background:#17292df2;font:600 15px system-ui}
	.terrain-race-bar[hidden]{display:none}.phonics-room-stage>.terrain-sandbox{width:100%;height:100%}
	`
	);

	card.append(
		style
		, titled
		, thumbnails
		, description
		, actions
		, el('h3', 'Ghost challenges')
		, list
		, ghostActions
		, notice
		, results
	);

	overlay.waitingCard.after(card);
	list.setAttribute('aria-label', 'Ghost challenges');
	notice.setAttribute('role', 'status');
	preview.width = 420;
	preview.height = 130;
	preview.hidden = true;
	const bar = el('div'), clock = el('span');
	bar.className = 'terrain-race-bar';
	bar.hidden = true;
	frame.append(bar);
	bar.append(clock);

	const announce = text => { notice.textContent = text; clock.textContent = text; };

	const button = (parent, label, task) => {
		const b = el('button', label);
		b.type = 'button';
		parent.append(b);

		b.addEventListener('click', async () => {
			b.disabled = true;

			try
			{
				await task();
			}
			catch(e)
			{
				announce(e.message);
			}
			finally
			{
				b.disabled = false;
			}
		}, {signal});

		return b;
	};

	const pending = new Map(), commands = [];
	let rpcId = 0, disposed = false;

	const rpc = (type, data = {}) => new Promise((resolve, reject) => {
		if(disposed)
		{
			reject(new Error('Race lobby closed.'));

			return;
		}

		const id = ++rpcId;
		pending.set(id, {resolve,reject});
		commands.push({id,type,...data});
	});

	module.terrainRaceReply = (id, json) => {
		id = Number(id);
		const req = pending.get(id);

		if(!req) return;

		const response = JSON.parse(json);

		if(Number.isInteger(response.progress))
		{
			announce('Verifying recorded inputs · ' + response.progress + '%');

			return;
		}

		pending.delete(id);

		if(response.error) req.reject(new Error(response.error)); else req.resolve(response.value);
	};

	let spec = null, parent = null, verified = null, run = null, attempt = null, live = null, localTick = 0, lastAt = 0;
	let startTimer, labelTimer, nextPage = null, parentLoaded = false, lastPoseKey = '', selectedKey = ''
		, open = false;

	const entries = new Map();
	let liveState = null, port, work = Promise.resolve(), publishRef = null;

	const enqueue = fn => {
		const next = work.then(fn);
		work = next.catch(e => { announce(e.message); if(liveState) port.dispute(e.message); });

		return work;
	};

	let shownCourse = null;
	const thumbnailsByRecipe = new Map();

	function showCourse(s)
	{
		preview.hidden = false;
		chart(preview, s);
		description.textContent = descriptor(s);
		const key = JSON.stringify([s.world.landscape.recipe,s.world.settings.waterLevel]);
		shownCourse = key;
		landThumb.hidden = true;
		let pending = thumbnailsByRecipe.get(key);

		if(!pending)
		{
			if(thumbnailsByRecipe.size >= 16) thumbnailsByRecipe.delete(thumbnailsByRecipe.keys().next().value);

			pending = rpc('preview', {spec: s});
			thumbnailsByRecipe.set(key, pending);
		}

		void pending.then(({size,rgb}) => {
			if(shownCourse !== key || disposed) return;

			const pixels = atob(rgb);
			landThumb.width = landThumb.height = size;
			const ctx = landThumb.getContext('2d'), data = ctx.createImageData(size, size);

			for(let i = 0; i<size*size; i++)
			{
				for(let c = 0; c<3; c++) data.data[4*i+c] = pixels.charCodeAt(3*i+c);
				data.data[4*i+3] = 255;
			}

			ctx.putImageData(data, 0, 0);
			landThumb.hidden = false;
		}).catch(() => thumbnailsByRecipe.delete(key));
	}

	function stopClocks() { clearTimeout(startTimer); clearInterval(labelTimer); }

	function countdown(at)
	{
		stopClocks();
		bar.hidden = false;

		const update = () => {
			clock.textContent = 'Start in ' + Math.max(0, (at-performance.now())/1000).toFixed(1) + ' s';
		};

		update();
		labelTimer = setInterval(update, 100);
	}

	async function go()
	{
		stopClocks();

		if(liveState?.storageKey)
		{
			try
			{
				localStorage.setItem(liveState.storageKey, 'started');
			}
			catch
			{
				/* The live peer still has the transcript. */
			}
		}

		await rpc('start');
		announce('Go!');
		canvas.focus();
	}

	async function dismissAttempt()
	{
		stopClocks();
		clearTimeout(liveState?.disconnectTimer);
		retryFinish.hidden = true;
		live?.close();
		live = null;
		attempt = null;
		liveState = null;
		lastPoseKey = '';
		bar.hidden = true;
		await rpc('leave');
		frame.classList.remove('terrain-online-driving');
	}

	function showResult()
	{
		results.replaceChildren();

		if(!run || !spec) return;

		const verdict = parent && verified
			? run.ticks<verified.ticks ? 'Faster' : run.ticks===verified.ticks ? 'Tie' : 'Behind'
			: 'Finished';

		announce(
			verdict + ' · ' + format(run.ticks) + (parent && verified ? ' · target ' + format(verified.ticks) : '')
		);

		const splits = el('details');
		splits.append(el('summary', 'Checkpoint splits'));

		for(const [i,s] of (run.splits??[]).entries())
		{
			const other = verified?.splits?.[i];

			splits.append(
				el(
					'p'
					, 'Lap ' + s.lap + ' · ' + format(s.time*60) + (other
						? ' · ' + ((s.time-other.time)>=0 ? '+' : '')
						+ (s.time-other.time).toFixed(2) + ' s'
						: ''
					)
				)
			);
		}

		results.append(splits);

		if(!parent || (parentLoaded && run.ticks<verified.ticks))
		button(results, 'Post my ghost', async() => {
			if(!lobby.protocol.snapshot().connected) throw new Error('Sign in to the race room to post this run.');

			const record = await media.publish(spec, run, parent, descriptor(spec));
			announce('Ghost posted to the room.');
			await refresh();
			list.value = record.event_id;
		});

		button(results, 'Save recorded run', () => {
			const a = el('a');

			const url = URL.createObjectURL(new Blob([JSON.stringify({spec,run})], {type: 'application/json'}));
			a.href = url;
			a.download = 'terrain-circuit-race.json';
			a.click();

			setTimeout(() => URL.revokeObjectURL(url), 1000);
		});

		button(results, 'Race again', () => beginGhost(spec, parent, verified));
	}

	async function exitDrive(withResult = false)
	{
		await dismissAttempt();
		await lobby.open();
		open = true;

		if(withResult) showResult(); else announce('Returned to the race lobby.');
	}

	button(bar, 'Return to lobby', async() => {
		if(liveState)
		{
			await overlay.requestLeave();

			return;
		}

		await exitDrive(Boolean(run));
	});

	async function beginGhost(s, source, reference)
	{
		results.replaceChildren();
		announce('Preparing starting grid…');
		spec = s;
		parent = source;
		verified = reference;
		run = null;
		parentLoaded = Boolean(source&&reference);
		await rpc('prepare', {spec});
		await lobby.close();
		attempt = 'ghost';
		localTick = 0;
		lastAt = 0;
		bar.hidden = false;
		frame.classList.add('terrain-online-driving');
		frame.querySelector('.terrain-panel').open = false;
		countdown(performance.now()+3000);
		startTimer = setTimeout(() => go().catch(e => announce(e.message)), 3000);
	}

	async function capture()
	{
		const laps = finite.checked ? Number(lapInput.value) : undefined;

		return rpc('spec', laps===undefined ? {} : {laps});
	}

	const finite = el('input'),lapInput = el('input'),choice = el('label');
	finite.type = 'checkbox';
	lapInput.type = 'number';
	lapInput.min = '1';
	lapInput.max = '20';
	lapInput.value = '1';
	lapInput.disabled = true;
	lapInput.setAttribute('aria-label', 'Laps in challenge copy');
	lapInput.style.width = '4em';
	finite.addEventListener('change', () => {lapInput.disabled = !finite.checked;}, {signal});
	choice.append(finite, document.createTextNode(' Set laps for a challenge copy '), lapInput);
	actions.before(choice);
	button(actions, 'Preview current circuit', async() => showCourse(await capture()));

	button(
		actions
		, 'Record a challenge'
		, async() => {const s = await capture();showCourse(s);await beginGhost(s, null, null);}
	);

	const retained = button(actions, 'Last finished run', () => showResult());
	retained.hidden = true;

	async function refresh(more = false)
	{
		if(!lobby.protocol.snapshot().connected)
		{
			announce('Sign in to browse and post Matrix ghosts.');

			return;
		}

		const page = await media.page(more ? nextPage : null), old = list.value;

		if(!more) entries.clear();

		for(const event of page.items) entries.set(event.event_id, event);
		nextPage = page.next;
		list.replaceChildren();

		for(const e of entries.values())
		{
			const option = el(
				'option'
				, safeTitle(e.content.summary) + ' · ' + format(e.content.ticks) + ' · ' + e.sender
			);

			option.value = e.event_id;
			list.append(option);
		}

		if(entries.has(old)) list.value = old;

		moreButton.hidden = !nextPage;

		announce(
			entries.size
				? 'Select a ghost to preview its course.'
				: 'No ghost challenges in this part of the room history.'
		);
	}

	button(ghostActions, 'Refresh', () => refresh());
	const moreButton = button(ghostActions, 'Earlier challenges', () => refresh(true));
	moreButton.hidden = true;
	let selection = null;
	const checkedParents = new Map();

	async function checkParent(item, id, ticks, seen = new Set())
	{
		const parentId = item.content.parent;

		if(!parentId) return item.event_id;

		if(seen.has(parentId) || seen.size >= 512) throw new Error('Invalid ghost ancestry.');

		seen.add(parentId);
		let record = checkedParents.get(parentId);

		if(!record)
		{
			const previous = await provider.roomEvent(parentId);

			if(previous.type !== ghostEvent || previous.content?.course !== id)
			throw new Error('Ghosts use different circuits.');

			const value = await media.ghost(previous);

			if(await specId(value.spec) !== id) throw new Error('The parent circuit differs.');

			const result = await rpc('verify', {spec: value.spec,run: value.run});

			if(result.ticks !== previous.content.ticks) throw new Error('Invalid parent time.');

			const root = await checkParent(previous, id, result.ticks, seen);
			record = {id,ticks: result.ticks,root};
			checkedParents.set(parentId, record);
		}

		if(record.id !== id || item.content.root !== record.root || ticks >= record.ticks)
			throw new Error('The recorded run did not beat its parent on the same circuit.');

		return record.root;
	}

	async function selectGhost(key)
	{
		if(!key) throw new Error('Choose a ghost first.');

		selectedKey = key;
		selection = null;
		const item = entries.get(key)??await provider.roomEvent(key);

		if(item.type!==ghostEvent || !item.content?.media) throw new Error('This ghost was removed or is unavailable.');

		announce('Downloading race…');
		restoreButton.hidden = true;
		let bundle;

		try
		{
			bundle = await media.ghost(item);
		}
		catch(error)
		{
			if(error.status===404 && selectedKey===key) restoreButton.hidden = false;

			throw error;
		}

		const id = await specId(bundle.spec);

		if(id!==item.content.course || bundle.run?.ticks!==item.content.ticks)
		throw new Error('The ghost and its room record disagree.');

		showCourse(bundle.spec);
		announce('Checking the recorded drive…');
		const derived = await rpc('verify', {spec: bundle.spec,run: bundle.run});

		await checkParent(item, id, derived.ticks);

		if(selectedKey!==key) return;

		selection = {item,spec: bundle.spec,verified: derived};
		announce('Verified finish · ' + format(derived.ticks) + ' · ' + item.sender);
	}

	let repairing = null;

	const restoreButton = button(ghostActions, 'Restore missing media', () => {
		repairing = list.value;
		file.click();
	});

	restoreButton.hidden = true;
	button(ghostActions, 'Preview ghost', () => selectGhost(list.value));

	button(ghostActions, 'Race selected ghost', async() => {
		if(!list.value) throw new Error('Choose a ghost first.');

		if(selection?.item.event_id!==list.value) await selectGhost(list.value);

		if(selection) await beginGhost(selection.spec, selection.item, selection.verified);
	});

	button(ghostActions, 'Copy ghost link', async() => {
		if(!list.value) throw new Error('Choose a ghost first.');

		const url = new URL(location.href);
		url.hash = new URLSearchParams({'terrain-ghost': list.value}).toString();
		await navigator.clipboard.writeText(url.href);
		announce('Ghost link copied.');
	});

	const file = el('input');
	file.type = 'file';
	file.accept = 'application/json,.json';
	file.hidden = true;
	card.append(file);
	button(actions, 'Open saved run', () => {repairing = null;file.click();});

	file.addEventListener('change', async() => {
		try
		{
			const f = file.files[0];

			if(!f || f.size>12*1024*1024) throw new Error('Choose a race file under 12 MiB.');

			const data = JSON.parse(await f.text());
			await specId(data.spec);

			if(repairing)
			{
				const target = entries.get(repairing)??await provider.roomEvent(repairing);
				await media.restore(target, data.spec, data.run);
				await selectGhost(repairing);

				return;
			}

			announce('Verifying saved run…');
			const result = await rpc('verify', data);
			spec = data.spec;
			run = {...data.run,splits: result.splits};
			parent = null;
			verified = null;
			parentLoaded = false;
			showCourse(spec);
			retained.hidden = false;
			showResult();
		}
		catch(e)
		{
			announce(e.message);
		}
		finally
		{
			file.value = '';
			repairing = null;
		}
	}, {signal});

	async function tryFinish()
	{
		const state = liveState;

		if(!state || !publishRef || state.submitted || state.turn!==state.detail.localPlayer) return;

		state.submitted = true;

		try
		{
			await port.submitAction({type: 'race-finish',media: publishRef}, state.hash);
		}
		catch(e)
		{
			state.submitted = false;
			throw e;
		}
	}

	async function applyFinish(action, report)
	{
		const state = liveState;

		if(!state || !state.detail.players.includes(action.actor) || action.actor!==state.turn
			|| state.scores.has(action.actor)
			|| action.sequence!==state.scores.size+1 || action.pre_hash!==state.hash
		)
		throw new Error('Unexpected race finish.');

		const result = await media.get(action.action.media);

		if(result.course!==state.id || result.race!==state.detail.root)
		throw new Error('Finish belongs to another race.');

		announce('Verifying finish from ' + action.actor + '…');
		const observed = live?.inputs.get(action.actor);

		if(observed && !result.run?.inputs?.startsWith(observed))
			throw new Error('The finish differs from the inputs received during this race.');

		const checked = await rpc('verify', {spec,run: result.run});
		state.splits.set(action.actor, checked.splits);
		state.scores.set(action.actor, checked.ticks);
		const [a,b] = state.detail.players;
		state.turn = action.actor===a ? b : a;

		state.hash = await session.terrainRaceHash(
			new TextEncoder().encode(JSON.stringify([state.id,...state.scores]))
		);

		let outcome = null;

		if(state.scores.size===2)
		{
			const x = state.scores.get(a), y = state.scores.get(b);
			outcome = {winner: x===y ? null : x<y ? a : b,reason: x===y ? 'Tie' : 'Race finished',times: [x,y]};
		}

		if(report && state.detail.role==='player')
		await port.reportSettled({sequence: action.sequence,hash: state.hash,nextActor: state.turn,outcome});
	}

	function launchLinks(state)
	{
		if(live || !liveState || state.restoring || state.scores.size===2) return;

		live = new LiveCircuit(
			lobby
			, state.detail
			, {
				notice: announce
				, error: announce
				, countdown
				, go: () => go().catch(e => announce(e.message))
				, disconnect: () => {
					const peer = state.detail.players.find(id => id!==state.detail.localPlayer);

					if(state.detail.role !== 'player' || state.scores.has(peer)) return;

					announce('Peer disconnected. Waiting for a finish or reconnection…');
					clearTimeout(state.disconnectTimer);

					state.disconnectTimer = setTimeout(() => {
						if(liveState===state && !state.scores.has(peer))
						port.dispute('Peer disconnected before a verifiable finish arrived.');
					}, 30000
					);
				}
			}
		);

		void live.start().catch(e => announce(e.message));
	}

	port = lobby.attachGame({
		settingsForm: async() => {
			const s = await capture(), id = await specId(s);
			showCourse(s);
			announce('Uploading course for both drivers…');
			const ref = await media.put(s);

			return {
				title: 'Create live circuit race'
				, intro: descriptor(s)
				, values: {course: id,ref,summary: descriptor(s),laps: s.laps}
				, groups: [{
					legend: 'Race rules'
					, summary: 'Standing start · pass-through rivals · verified finishes'
					, fields: [
					]
				}]
			};
		}
		, describeSettings: v => String(v?.summary??'Incompatible circuit')
		, validSettings: v => Boolean(
			v?.ref?.version===1 && /^[0-9a-f]{64}$/.test(v.course) && Number.isInteger(v.laps) && v.laps>=1
				&& v.laps<=20
		)
		, start: detail => enqueue(async() => {
			await dismissAttempt();
			announce('Preparing shared course…');
			publishRef = null;
			spec = await media.get(detail.settings.ref);
			const id = await specId(spec);

			if(id!==detail.settings.course || spec.laps!==detail.settings.laps)
			throw new Error('The shared course digest differs.');

			showCourse(spec);
			frame.querySelector('.terrain-panel').open = false;
			await rpc('prepare', {spec});
			parent = null;
			verified = null;
			run = null;
			const state = liveState = {
				detail
				, id
				, hash: id
				, scores: new Map()
				, splits: new Map()
				, turn: detail.breaker
				, submitted: false
			};

			for(const event of detail.history??[]) await applyFinish(event, false);

			if(detail.sequence!==state.scores.size || (detail.sequence && detail.hash!==state.hash))
			throw new Error('The race history differs.');

			attempt = detail.role==='player' ? 'live' : 'spectator';
			bar.hidden = false;

			if(detail.role==='player')
			{
				state.storageKey = 'terrain.live.v1/' + detail.root + '/' + detail.localPlayer;
				let saved;

				try {saved = localStorage.getItem(state.storageKey);} catch { /* Storage may be unavailable. */ }

				if(state.scores.has(detail.localPlayer))
				{
					state.restoring = true;
					announce('Your verified time is on the table. Waiting for the other driver…');

					return;
				}

				if(saved)
				{
					state.restoring = true;

					if(saved==='started')
					{
						await port.resign('DNF · the page closed during the drive');
					}
					else
					{
						run = JSON.parse(saved);
						await rpc('verify', {spec,run});
						retained.hidden = false;
						void submitDrive(state);
					}

					return;
				}

				await port.reportReady(id);
			}

			if(detail.role==='spectator'||detail.sequence) launchLinks(state);
		})
		, action: action => enqueue(() => applyFinish(action, true))
		, confirmed: confirmation => enqueue(async() => {
			if(!liveState) return;

			if(confirmation.hash!==liveState.hash) throw new Error('The verified finish hashes differ.');

			if(!confirmation.outcome)
			{
				launchLinks(liveState);
				await tryFinish();
			}
		})
		, stop: outcome => enqueue(async() => {
			const state = liveState;

			if(!state) return;

			await dismissAttempt();

			if(outcome.times)
			{
				// The room retains the completed seat until the client explicitly returns to the lobby.
				if(lobby.protocol.selected === state.detail.root) await port.leave();

				announce(
					(outcome.winner ? outcome.winner + ' wins' : 'Tie') + ' · ' + outcome.times.map(format).join(' / ')
				);

				results.replaceChildren();

				for(const [driver,splits] of state.splits)
				{
					const panel = el('details');
					panel.append(el('summary', driver + ' · ' + format(state.scores.get(driver))));

					for(const split of splits)
					panel.append(el('p', 'Lap ' + split.lap + ' · ' + format(split.time*60)));
					results.append(panel);
				}

				if(state.detail.role==='player')
				{
					button(results, 'Rematch', () => lobby.protocol.create({
						target: state.detail.players.find(id => id!==state.detail.localPlayer)
						, settings: state.detail.settings
					}));
				}
			}
			else announce(outcome.reason??'Race ended.');

			if(run) retained.hidden = false;
		})
	});

	async function submitDrive(state)
	{
		retryFinish.hidden = true;

		try
		{
			const ref = publishRef ?? await media.put({race: state.detail.root,course: state.id,run});

			if(state !== liveState) return;

			publishRef = ref;
			await enqueue(tryFinish);
		}
		catch(error)
		{
			if(state!==liveState) return;

			retryFinish.hidden = false;
			announce(error.message);
		}
	}

	const retryFinish = button(bar, 'Retry finish', () => submitDrive(liveState));
	retryFinish.hidden = true;

	module.terrainRaceFrame = json => {
		if(!attempt) return;

		const frame = JSON.parse(json);
		localTick = frame.tick;
		lastAt = performance.now();

		try { live?.frame(frame); } catch(error) { announce(error.message); }

		const comparison = verified?.splits?.[frame.split?.index];
		const delta = comparison ? frame.split.at-comparison.at : null;

		clock.textContent = (delta === null
			? ''
			: (delta >= 0
				? '+'
				: ''
			) + delta.toFixed(2) + ' s · '
		) + format(frame.tick)+(parent && verified
			? ' / ' + format(verified.ticks)
			: ''
		);

		if(frame.dnf)
		{
			announce(frame.dnf);

			if(liveState) void port.resign('DNF');

			return;
		}

		if(!frame.run) return;

		run = frame.run;
		retained.hidden = false;

		if(liveState)
		{
			const state = liveState;

			try
			{
				localStorage.setItem(state.storageKey, JSON.stringify(run));
			}
			catch
			{
				/* The upload can proceed without a local copy. */
			}

			announce('Finished · ' + format(run.ticks) + ' · submitting drive…');

			void submitDrive(state);
		}
		else void exitDrive(true).catch(e => announce(e.message));
	};

	module.terrainRaceInput = () => {
		const command = commands.shift();
		let packet = command ? {onlineCommand: command} : null;

		if(attempt)
		{
			const local = localTick+(lastAt ? Math.min(6, (performance.now()-lastAt)*.06) : 0);
			const follow = attempt === 'spectator' ? live?.view(live.table.players[0]) : null;
			const pose = live
				? live.view(attempt==='spectator' ? live.table.players[1] : live.peer)
				: verified ? ghostAt(verified.poses, local) : null;

			const key = JSON.stringify([pose,follow]);

			if(key!==lastPoseKey)
			{
				packet ??= {};
				packet.onlineRival = pose;

				if(follow) packet.onlineFollow = follow;

				lastPoseKey = key;
			}
		}

		return packet;
	};

	let loaded = false;

	const entered = () => {
		if(open && !loaded && lobby.protocol.snapshot().connected)
		{
			loaded = true;

			void refresh().then(async() => {
				const key = new URLSearchParams(location.hash.slice(1)).get('terrain-ghost');

				if(!key) return;

				if(!entries.has(key))
				{
					const item = await provider.roomEvent(key);

					if(item.type!==ghostEvent) throw new Error('The linked ghost is unavailable.');

					entries.set(key, item);
					const opt = el('option', 'Linked ghost · ' + item.sender);
					opt.value = key;
					list.append(opt);
				}

				list.value = key;
				await selectGhost(key);
			}).catch(e => announce(e.message));
		} };

	function offerPreviews()
	{
		const snapshot = lobby.protocol.snapshot();
		const tables = snapshot.tables.filter(t => t.root!==snapshot.selected);

		for(const [index,row] of Array.from(overlay.tableList.children).entries())
		{
			const table = tables[index];

			if(!table?.settings?.ref || row.querySelector('[data-course-preview]')) continue;

			const previewButton = button(row, 'Preview course', async() => {
				announce('Loading course from ' + table.host + '…');
				const s = await media.get(table.settings.ref);

				if(await specId(s)!==table.settings.course) throw new Error('The advertised circuit differs.');

				showCourse(s);
				announce('Hosted by ' + table.host + ' · select Join when ready.');
			});

			previewButton.dataset.coursePreview = '';
		}
	}

	lobby.protocol.addEventListener('change', () => {entered();queueMicrotask(offerPreviews);}, {signal});

	lobby.protocol.addEventListener(
		'closed'
		, () => {
			open = false;
			clearInterval(padTimer);
			padTimer = null;

			if(!attempt) frame.querySelector('[data-online-race]')?.focus();
		}
		, {signal}
	);

	module.terrainRaceLobby = async() => {
		open = true;
		padTimer ??= setInterval(padFocus, 100);
		await lobby.open();
		entered();

	};

	const padFocus = () => {
		if(!open || !overlay.root.classList.contains('is-open') || overlay.root.classList.contains('has-game'))
			return;

		const pad = [...(navigator.getGamepads?.() ?? [])].find(Boolean);

		if(!pad) return;

		const code = [12,13,14,15,0,1].find(i => pad.buttons[i]?.pressed);

		if(code === heldButton) return;

		heldButton = code;

		if(code === undefined) return;

		const scope = overlay.root.querySelector('[role="dialog"]') ?? overlay.root;

		const items = [...scope.querySelectorAll(
			'button:not(:disabled),input:not(:disabled),select:not(:disabled),a[href]'
		)]
			.filter(e => e.getClientRects().length && e.type !== 'file');

		const active = document.activeElement;

		if(code === 0)
		{
			if(items.includes(active)) active.click();

			return;
		}

		if(code === 1)
		{
			void overlay.requestLeave({close: true});

			return;
		}

		if(active instanceof HTMLSelectElement && [14,15].includes(code))
		{
			active.selectedIndex = Math.max(
				0
				, Math.min(active.options.length-1, active.selectedIndex+(code===15 ? 1 : -1))
			);

			active.dispatchEvent(new Event('change', {bubbles: true}));

			return;
		}

		if(items.length)
		items[(items.indexOf(active) + ([12,14].includes(code) ? -1 : 1) + items.length)%items.length].focus();
	};

	let heldButton, padTimer;

	const openRoom = () => void module.terrainRaceLobby().catch(e => announce(e.message));
	frame.querySelector('[data-online-race]').addEventListener('click', openRoom, {signal});

	if(new URLSearchParams(location.hash.slice(1)).has('terrain-ghost')
		|| new URLSearchParams(location.hash.slice(1)).has('phonics-game')
	)
		void module.terrainRaceLobby().catch(e => announce(e.message));

	return () => {
		disposed = true;
		clearInterval(padTimer);
		stopClocks();
		clearTimeout(liveState?.disconnectTimer);
		retryFinish.hidden = true;
		live?.close();
		card.remove();
		bar.remove();

		for(const req of pending.values()) req.reject(new Error('The game closed.'));
		pending.clear();

		for(const key of ['terrainRaceInput','terrainRaceReply','terrainRaceFrame','terrainRaceLobby'])
		delete module[key];
	};
}
