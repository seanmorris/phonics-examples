// Compile the originals' ID3 credits and embedded front pictures into a small catalog and thumbnails.
import {execFileSync} from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import {fileURLToPath} from 'node:url';

const game = fileURLToPath(new URL('../games/terrain-sandbox/', import.meta.url));
const media = path.join(game, 'music'), check = process.argv.includes('--check');
const tracks = [];

function output(file, bytes)
{
	bytes = Buffer.from(bytes);

	if(fs.existsSync(file) && fs.readFileSync(file).equals(bytes)) return;

	if(check) throw Error('Regenerate ' + path.relative(game, file));

	fs.mkdirSync(path.dirname(file), {recursive: true});
	fs.writeFileSync(file, bytes);
}

for(const name of fs.readFileSync(path.join(media, 'playlist.txt'), 'utf8').trim().split(/\r?\n/))
{
	const file = path.join(media, name);

	const {format,streams} = JSON.parse(
		execFileSync('ffprobe', ['-v','error','-show_format','-show_streams','-of','json',file])
	);

	const tags = Object.fromEntries(Object.entries(format.tags ?? {}).map(([k,v]) => [k.toLowerCase(),v.trim()]));
	const id = name.split('_')[0];
	const stem = path.basename(name, '.mp3').replace(/^\d+_/, '');
	const parts = stem.split('---').map(part => part.replace(/[-_]+/g, ' ').trim());
	const track = {id, src: 'music/' + encodeURIComponent(name), title: tags.title || parts.at(-1)};
	const duration = Number(format.duration);
	if(duration > 0 && Number.isFinite(duration)) track.duration = duration;

	const artist = tags.artist || tags.album_artist || (parts.length === 2 ? parts[0] : '');
	if(artist) track.artist = artist;

	if(tags.album) track.album = tags.album;

	const pictures = streams.filter(s => s.disposition?.attached_pic);
	const picture = pictures.find(s => /front/i.test(s.tags?.comment ?? '')) ?? pictures[0];

	if(picture)
	{
		track.cover = 'music/covers/' + id + '.jpg';

		const image = execFileSync('ffmpeg', ['-v'
			, 'error'
			, '-nostdin'
			, '-threads'
			, '1'
			, '-i'
			, file
			, '-map'
			, '0:' + picture.index
			, '-vf'
			, 'scale=144:144:force_original_aspect_ratio=decrease'
			, '-frames:v'
			, '1'
			, '-map_metadata'
			, '-1'
			, '-flags:v'
			, '+bitexact'
			, '-threads'
			, '1'
			, '-q:v'
			, '3'
			, '-c:v'
			, 'mjpeg'
			, '-f'
			, 'image2pipe'
			, 'pipe:1'
		]);

		output(path.join(game, track.cover), image);
	}

	tracks.push(track);
}

output(path.join(game, 'assets/soundtrack.json'), JSON.stringify(tracks, null, 2) + '\n');

// A short original finish cue. Leave a musical tail for the crossfade back into the driving music.
const rate = 24000, samples = Math.round(3.15*rate), wave = Buffer.alloc(44+samples*2);
wave.write('RIFF'); wave.writeUInt32LE(wave.length-8, 4); wave.write('WAVEfmt ', 8);
wave.writeUInt32LE(16, 16); wave.writeUInt16LE(1, 20); wave.writeUInt16LE(1, 22);
wave.writeUInt32LE(rate, 24); wave.writeUInt32LE(rate*2, 28); wave.writeUInt16LE(2, 32);
wave.writeUInt16LE(16, 34); wave.write('data', 36); wave.writeUInt32LE(samples*2, 40);
const notes = [[0,55,.48],[.22,62,.42],[.44,67,.42],[.70,69,.50],[1,62,1.85],[1,66,1.85],[1,69,1.85],[1,74,2.15]];

for(let i = 0; i<samples; i++)
{
	let mix = 0;

	for(const [on,key,length] of notes)
	{
		const t = i/rate-on;

		if(t<0 || t>=length) continue;

		const phase = 2*Math.PI*440*2**((key-69)/12)*t;
		const envelope = Math.min(1, t/.012)*Math.min(1, (length-t)/.12)*Math.exp(-2.8*t/length);
		mix += envelope*(Math.sin(phase)+.22*Math.sin(phase*2)+.09*Math.sin(phase*3));
	}

	wave.writeInt16LE(Math.round(32767*Math.tanh(mix*.36)), 44+2*i);
}

output(path.join(media, 'race-finish.wav'), wave);
console.log('Verified ' + tracks.length + ' soundtrack credits and the race finish cue');
