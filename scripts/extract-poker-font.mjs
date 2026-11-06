import fs from 'node:fs/promises';
import {extractFontOutlines} from './billiards-font.mjs';

// One-off: extract the DOS face outlines from Fixedsys Excelsior (public domain / CC0, github.com/kika/fixedsys).
// The repository keeps only the extracted outlines, never the TrueType file.
// Usage: node scripts/extract-poker-font.mjs FSEX302.ttf
const [dos] = process.argv.slice(2);
if(!dos) throw Error('Usage: extract-poker-font.mjs <FSEX302.ttf>');

const target = new URL('../games/php-poker/art/typography/fixedsys-excelsior.json', import.meta.url);
await fs.writeFile(target, JSON.stringify(await extractFontOutlines({dos}, '♠♥♦♣')) + '\n');
console.log(`Wrote ${target.pathname}`);
