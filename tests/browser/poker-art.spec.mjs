import {test,expect} from '@playwright/test';
import {readFile} from 'node:fs/promises';
import {Resvg} from '@resvg/resvg-js';
import {PNG} from 'pngjs';

const root=new URL('../../games/php-poker/',import.meta.url);
const source=await readFile(new URL('browser/game.mjs',root),'utf8');
const art={
	sheet:await readFile(new URL('art/cards/deck.svg',root),'utf8'),columns:9,rows:6,
	backs:{red:await readFile(new URL('art/cards/back-red.svg',root),'utf8'),
		blue:await readFile(new URL('art/cards/back-blue.svg',root),'utf8')}
};

test('every browser card crop matches an independent SVG rendering at both atlas resolutions',async({page})=>{
	// The percentage-sized source SVG once produced one enormous ace spread across the
	// entire atlas in Chrome. Compare all 54 source cells with a separate SVG renderer,
	// so a loaded texture alone cannot hide missing ranks, wrong crops or suit changes.
	for(const dpr of [1,2])
	{
		const atlas=await page.evaluate(async({source,art,dpr})=>{
			const url=URL.createObjectURL(new Blob([source],{type:'text/javascript'}));
			try { return await (await import(url)).rasteriseCards(art,dpr); }
			finally { URL.revokeObjectURL(url); }
		},{source,art,dpr});
		const reference=PNG.sync.read(new Resvg(art.sheet,{fitTo:{mode:'width',value:atlas.width}}).render().asPng());
		const actual=Buffer.from(atlas.base64,'base64');
		const [cw,ch]=atlas.cell;
		expect(reference.width).toBe(cw*9);
		expect(reference.height).toBe(ch*6);
		for(let cell=0;cell<54;cell++)
		{
			let difference=0,samples=0;
			const left=cell%9*cw,top=Math.floor(cell/9)*ch;
			for(let y=top+2;y<top+ch-2;y+=2) for(let x=left+2;x<left+cw-2;x+=2)
			{
				const offset=(y*atlas.width+x)*4;
				for(let channel=0;channel<4;channel++)
				{
					difference+=Math.abs(actual[offset+channel]-reference.data[offset+channel]);samples++;
				}
			}
			// Small edge differences between Chromium and resvg anti-aliasing are expected.
			expect(difference/samples,`source cell ${cell}, DPR ${dpr}`).toBeLessThan(8);
		}
	}
});

test('Matrix avatar cells are center-cropped and circular',async({page})=>{
	const result=await page.evaluate(async({source,art})=>{
		const avatar=document.createElement('canvas');avatar.width=64;avatar.height=32;
		const paint=avatar.getContext('2d');paint.fillStyle='#f00';paint.fillRect(0,0,64,32);
		const url=URL.createObjectURL(new Blob([source],{type:'text/javascript'}));
		try
		{
			const atlas=await (await import(url)).rasteriseCards({
				...art,icons:{matrix:{source:avatar.toDataURL('image/png'),round:true}}
			},1);
			const pixels=Uint8Array.from(atob(atlas.base64),character=>character.charCodeAt(0));
			const [x,y,w,h]=atlas.icons.matrix;
			const pixel=(px,py)=>[...pixels.slice(((py*atlas.width)+px)*4,((py*atlas.width)+px)*4+4)];
			return {corner:pixel(x,y),centre:pixel(x+w/2|0,y+h/2|0)};
		}
		finally{URL.revokeObjectURL(url);}
	},{source,art});
	expect(result.corner[3]).toBe(0);
	expect(result.centre).toEqual([255,0,0,255]);
});
