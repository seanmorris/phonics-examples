import fs from 'node:fs/promises';
import {extractFontOutlines} from './billiards-font.mjs';

// One-off: refresh the self-contained HUD glyph outlines from the OFL Barlow Semi Condensed source faces.
// The repository keeps only the extracted outlines and license, never the TrueType files.
// Usage: node scripts/extract-billiards-font.mjs BarlowSemiCondensed-Medium.ttf BarlowSemiCondensed-Bold.ttf
const [body, bold] = process.argv.slice(2);
if(!body || !bold) throw Error('Usage: extract-billiards-font.mjs <Medium.ttf> <Bold.ttf>');

const target = new URL('../games/php-billiards/art/typography/barlow-semi-condensed.json', import.meta.url);
await fs.writeFile(target, JSON.stringify(await extractFontOutlines({body, bold})) + '\n');
console.log(`Wrote ${target.pathname}`);
