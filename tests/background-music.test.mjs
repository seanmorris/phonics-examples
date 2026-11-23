import assert from 'node:assert/strict';
import test from 'node:test';
import {BackgroundMusic} from '../packages/phonics-bgm/browser/BackgroundMusic.mjs';

const tracks = ['a','b','c'].map(id => ({id,src: id + '.mp3'}));

const tick = () => new Promise(resolve => setImmediate(resolve));

test('a broken playlist stops requesting songs and can still play a standalone cue', async() => {
	const errors = new Map(tracks.map(track => [track.id, Error('unavailable')]));
	const {music,voices,storage} = player(new Map(), errors);
	music.hold('gesture', false);
	await tick();
	assert.equal(voices.length, tracks.length);
	assert.equal(music.depth, 0);
	music.push({src: 'finish.wav', credit: false});
	await tick();
	assert.equal(music.depth, 1);
	voices.at(-1).media.onended();
	assert.equal(music.depth, 0);
	assert.equal(storage.size, 0);
	music.close();
});

function player(storage = new Map(), errors = new Map())
{
	let now = 0, sequence = 0;
	const timers = new Map(), voices = [], credits = [];
	const clock = {

		setTimeout(fn, ms) { const id = ++sequence; timers.set(id, {fn,at: now+ms}); return id; }


		, clearTimeout(id) { timers.delete(id); }
	};

	async function advance(ms)
	{
		const end = now+ms;

		for(; ;)
		{
			const first = [...timers].sort((a, b) => a[1].at-b[1].at)[0];

			if(!first || first[1].at > end) break;

			now = first[1].at;
			timers.delete(first[0]);
			first[1].fn();
			await tick();
		}

		now = end;
		await tick();
	}

	const music = new BackgroundMusic({
		storage
		, clock
		, announce: t => credits.push(t.id)

		, voice(track) {
			let elapsed = 0, playingSince = null;
			const media = {
				duration: track.duration ?? 180
				, get paused() { return playingSince === null; }
				, get currentTime() { return elapsed + (this.paused ? 0 : (now-playingSince)/1000); }

				, play() {
					if(errors.has(track.id)) return Promise.reject(errors.get(track.id));

					if(this.paused) playingSince = now;

					return Promise.resolve();
				}

				, pause() { elapsed = this.currentTime; playingSince = null; }
			};

			const voice = {
				media
				, id: track.id
				, ramps: []

				, gain(to, sec) { this.ramps.push([to,sec]); }

				, close() { this.closed = true; }
			};

			voices.push(voice);

			return voice;
		}
	});

	music.playlist(tracks, 'rotation');

	return {music,advance,voices,credits,storage,timers};
}

test('rotation remembers the next song only once playback succeeds and wraps at the end', async() => {
	const storage = new Map(), errors = new Map([['a',{name: 'NotAllowedError'}]]);
	const {music,voices,credits} = player(storage, errors);
	assert.equal(voices.length, 0);
	music.hold('gesture', false);
	await tick();
	assert.equal(storage.has('rotation'), false);
	errors.clear();
	music.hold('gesture', false);
	await tick();
	assert.equal(storage.get('rotation'), 'b');
	voices[0].media.onended();
	await tick();
	assert.deepEqual(credits, ['a','b']);
	music.close();
	const second = player(storage);
	second.music.hold('gesture', false);
	await tick();
	assert.deepEqual(second.credits, ['c']);
	assert.equal(storage.get('rotation'), 'a');
});

test('nested jingles crossfade, freeze covered playheads and start the return before their last sample', async() => {
	const {music,advance,voices,credits,storage,timers} = player();
	music.hold('gesture', false);
	await tick();
	await advance(1500);
	music.push({id: 'middle',src: 'middle.wav',duration: 10,credit: false});
	await tick();
	const [base,middle] = voices;
	assert.deepEqual(base.ramps.at(-1), [0,.65]);
	assert.deepEqual(middle.ramps.at(-1), [1,.65]);
	await advance(700);
	const position = base.media.currentTime;
	assert.ok(base.media.paused);
	music.push({id: 'top',src: 'top.wav',duration: 3,credit: false});
	await tick();
	await advance(2200);
	assert.ok(middle.media.paused);
	assert.equal(base.media.currentTime, position);
	await advance(180);
	assert.equal(music.current.id, 'middle');
	assert.ok(!middle.media.paused && !voices[2].media.paused);
	await advance(650);
	assert.ok(voices[2].closed);
	music.pop();
	await tick();
	assert.equal(base.media.currentTime, position);
	assert.ok(!base.media.paused && !middle.media.paused);
	await advance(700);
	assert.ok(middle.closed);
	assert.deepEqual(credits, ['a']);
	assert.equal(storage.get('rotation'), 'b');
	music.close();
	assert.equal(timers.size, 0);
	assert.ok(voices.every(v => v.closed));
});

test('visibility and game pause are independent, failed songs are skipped, and a covered song can end', async() => {
	const {music,advance,voices,credits,storage} = player(new Map(), new Map([['a',Error('decode')]]));
	music.hold('gesture', false);
	await tick();
	assert.equal(music.current.id, 'b');
	music.push({id: 'cue',src: 'cue.wav',credit: false});
	await tick();
	voices.find(v => v.id === 'b').media.onended();
	music.hold('hidden', true);
	music.hold('game', true);
	music.hold('hidden', false);
	await advance(1500);
	assert.ok(voices.every(v => v.media.paused));
	music.hold('game', false);
	await tick();
	music.pop();
	await tick();
	assert.deepEqual(credits, ['b','c']);
	assert.equal(storage.get('rotation'), 'a');
	music.close();
});
