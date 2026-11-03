import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import {gunzipSync} from 'node:zlib';
import {createHash} from 'node:crypto';
import {PNG} from 'pngjs';

test('generated PHP mesh bundle is packed and internally consistent', () => {
	const root=new URL('../games/php-billiards/assets/generated/',import.meta.url);
	const manifest=JSON.parse(fs.readFileSync(new URL('meshes.json',root)));
	const compressed=fs.readFileSync(new URL('meshes.bin.gz',root)),binary=gunzipSync(compressed);
	assert.equal(manifest.schemaVersion,1);assert.equal(manifest.vertexStride,32);
	assert.equal(binary.length,manifest.byteLength);
	assert.equal(createHash('sha256').update(binary).digest('hex'),manifest.sha256);
	assert.equal(createHash('sha256').update(compressed).digest('hex'),manifest.compressedSha256);
	assert.deepEqual(manifest.geometry.mesher.slice(0,2),[432,240],'Pocket contours lost their high-resolution sampling grid');
	assert.deepEqual(Object.keys(manifest.meshes),['apron','bed','wood','cushion','liners','cube','cue','sphere','disc']);
	for(const [name,mesh] of Object.entries(manifest.meshes))
	{
		assert.equal(mesh.vertexLength,mesh.vertexCount*manifest.vertexStride,`${name} vertex bytes`);
		assert.equal(mesh.indexLength,mesh.indexCount*4,`${name} index bytes`);
		assert.ok(mesh.indexCount>0&&mesh.indexCount%3===0,`${name} triangle indices`);
		assert.ok(mesh.vertexOffset>=0&&mesh.indexOffset+mesh.indexLength<=binary.length,`${name} offsets`);
	}
	assert.ok(manifest.meshes.wood.indexCount<350_000,'Static wood retained its per-grid-cell fillet tessellation');
	assert.ok(manifest.meshes.wood.vertexCount<250_000,'Static wood retained redundant coplanar vertices');
});

test('PHP renderer keeps longitudinal ball UVs and axial cue grain', () => {
	const shader=fs.readFileSync(new URL('../games/php-billiards/shaders/billiards.frag',import.meta.url),'utf8');
	const scene=fs.readFileSync(new URL('../games/php-billiards/src/Render/Scene.php',import.meta.url),'utf8');
	const wood=fs.readFileSync(new URL('../games/php-billiards/art/svg/wood.svg',import.meta.url),'utf8');
	assert.match(shader,/uv=vec2\(uv\.x,1\.0-uv\.y\)/,'Ball longitude was mirrored');
	assert.match(shader,/base=ballSkin\(vUv\)/,'Ball skin no longer follows sphere UVs');
	assert.match(shader,/textureGrad\(uBallSkins,vec3\(uv,vLayer\),dx\*limit,dy\*limit\)/,'Ball poles use unbounded implicit mip selection');
	assert.match(scene,/new Texture\(\$pixels, \$cellWidth, \$cellHeight, true, false, \$columns \* \$rows\)/,
		'Ball skins must stay separate texture-array layers with independent mip chains');
	assert.match(shader,/64\.0\/max\(footprint/,'Ball pole LOD cap changed');
	assert.match(scene,/atlasTextures\(\$textures\['ballAtlas'\], 4, 4\)/,'Ball skins share one bleeding mip chain');
	assert.match(shader,/vec3 cueSurface\(vec2 uv,vec3 accent,out float rough,out float coat,out bool metal\)/,'The cue lost its anatomy zones');
	assert.match(shader,/point=\(\(d-\.76\)\/\.225-across\)\*radius\*\.7854,pointAa=max\(fwidth\(point\)/,'The forearm points lost their around-the-cue antialiasing');
	assert.match(scene,/CUE_ACCENTS\[\$game->cuePlayer\(\)\]/,'The second player no longer gets their own cue colour');
	assert.match(shader,/float specularAaRoughness\(/,'Glossy materials lost screen-space specular filtering');
	assert.match(shader,/varnishedWood\|\|metallic/,'Cue collar and varnished wood bypass specular filtering');
	assert.match(shader,/keyRough=specularAaRoughness\(\.11,N\)/,'Wood clearcoat bypasses specular filtering');
	assert.match(shader,/texture\(uFelt,feltUv\*15\.0/,'Felt reverted to per-fragment procedural noise');
	assert.match(wood,/baseFrequency="\.015625 \.125"/,'Wood source grain axis changed');
	assert.match(shader,/result\+=woodGrain\(top\*scale,topX\*scale,topY\*scale,17\.3\*endBoard\)/,'Rail wood samples the tiling texture directly again');
	assert.match(shader,/miter=abs\(p\.x\)-abs\(p\.z\)-\(uTableSize\.x-uTableSize\.y\)\*\.5/,'End rails lost their 45-degree miter grain');
	assert.match(shader,/length\(vec2\(abs\(vPosition\.x\)-uSpot,vPosition\.z\)\)/,'Head and foot spots left the cloth');
	assert.match(scene,/location\('uSpot'\), Table::FOOT_SPOT\)/,'Cloth spots no longer follow the rack apex');
	assert.match(shader,/textureGrad\(uWood,uv\+first,dx,dy\)/,'Wood region crossfade lost its seam-free mip selection');
});

test('generated wood grain tiles without visible edge discontinuities', () => {
	const image=PNG.sync.read(fs.readFileSync(new URL('../games/php-billiards/assets/generated/wood.png',import.meta.url)));
	let leftRight=0,topBottom=0;
	for(let y=0;y<image.height;y++)for(let channel=0;channel<3;channel++)leftRight+=Math.abs(
		image.data[(y*image.width)*4+channel]-image.data[(y*image.width+image.width-1)*4+channel]);
	for(let x=0;x<image.width;x++)for(let channel=0;channel<3;channel++)topBottom+=Math.abs(
		image.data[x*4+channel]-image.data[((image.height-1)*image.width+x)*4+channel]);
	assert.ok(leftRight/(image.height*3)<8);assert.ok(topBottom/(image.width*3)<8);
	// The rails repeat this tile every 37 cm along the grain, so no column step, the wrap included, may stand out
	// from the ordinary steps inside it (resvg turbulence and the old pore pattern both left visible seams).
	const columnStep=(a,b)=>{let sum=0;for(let y=0;y<image.height;y++)for(let channel=0;channel<3;channel++)
		sum+=Math.abs(image.data[(y*image.width+a)*4+channel]-image.data[(y*image.width+b)*4+channel]);return sum/(image.height*3);};
	const steps=Array.from({length:image.width},(_,x)=>columnStep(x,(x+1)%image.width)).sort((a,b)=>a-b);
	assert.ok(steps[steps.length-1]<steps[steps.length>>1]*2.5,'Wood tile shows a seam across the grain');
	assert.ok(columnStep(image.width-1,0)<steps[steps.length>>1]*2,'Wood tile does not wrap seamlessly along the grain');
});

test('generated felt height field is subtle and seamlessly tiled', () => {
	const root=new URL('../games/php-billiards/',import.meta.url);
	const source=fs.readFileSync(new URL('art/svg/felt.svg',root),'utf8');
	const image=PNG.sync.read(fs.readFileSync(new URL('assets/generated/felt.png',root)));
	assert.match(source,/stitchTiles="stitch"/);
	let leftRight=0,topBottom=0,minimum=255,maximum=0;
	for(let y=0;y<image.height;y++)for(let channel=0;channel<3;channel++)
	{
		const value=image.data[(y*image.width)*4+channel];
		minimum=Math.min(minimum,value);maximum=Math.max(maximum,value);
		leftRight+=Math.abs(value-image.data[(y*image.width+image.width-1)*4+channel]);
	}
	for(let x=0;x<image.width;x++)for(let channel=0;channel<3;channel++)topBottom+=Math.abs(
		image.data[x*4+channel]-image.data[((image.height-1)*image.width+x)*4+channel]);
	assert.ok(leftRight/(image.height*3)<8);assert.ok(topBottom/(image.width*3)<8);
	assert.ok(maximum-minimum<80,'Felt macro texture regained dirty high-contrast patches');
	// The shader stretches the compressed noise for grain and loose fibres; the fibres must stay reachable.
	const shader=fs.readFileSync(new URL('shaders/billiards.frag',root),'utf8');
	const gain=Number(shader.match(/float feltDetail\(float value\)\{return clamp\(\(value-\.5\)\*([\d.]+)\+\.5/)?.[1]);
	let low=255,high=0;
	for(let index=0;index<image.data.length;index+=4){low=Math.min(low,image.data[index]);high=Math.max(high,image.data[index]);}
	assert.ok((high/255-.5)*gain+.5>.62&&(low/255-.5)*gain+.5<.1,'Felt grain and loose fibres lost their contrast');
});

test('title poster is a renderer capture at the game canvas resolution', () => {
	const source=PNG.sync.read(fs.readFileSync(new URL('../games/php-billiards/art/screenshots/title.png',import.meta.url)));
	const generated=PNG.sync.read(fs.readFileSync(new URL('../games/php-billiards/assets/generated/poster.png',import.meta.url)));
	assert.deepEqual([source.width,source.height],[1280,720]);
	assert.deepEqual(generated,source);
	const background=source.data.subarray(0,4);
	assert.ok(source.data.subarray(0,225*source.width*4).every((value,index)=>value===background[index%4]),
		'Docsite screenshot contains a UI overlay');
});

test('ball skins use high-resolution two-to-one equirectangular cells', () => {
	const image=PNG.sync.read(fs.readFileSync(new URL('../games/php-billiards/assets/generated/ball-atlas.png',import.meta.url)));
	const cellWidth=image.width/4,cellHeight=image.height/4;
	assert.deepEqual([image.width,image.height],[2048,1024]);assert.equal(cellWidth/cellHeight,2);
	const points=[];
	for(let y=0;y<cellHeight;y++)for(let x=0;x<cellWidth/2;x++)
	{
		const offset=((y*image.width)+(cellWidth+x))*4;
		// The number spots are aged ivory (#efe6ce), like the Bakelite sets they imitate.
		if(Math.abs(image.data[offset]-239)<8&&Math.abs(image.data[offset+1]-230)<8&&Math.abs(image.data[offset+2]-206)<8)points.push([x,y]);
	}
	const xs=points.map(point=>point[0]),ys=points.map(point=>point[1]);
	assert.ok(points.length>500,'Ball 1 lost its ivory number spot');
	assert.ok(Math.abs(Math.max(...xs)-Math.min(...xs)-(Math.max(...ys)-Math.min(...ys)))<=2);
});

test('ball numbers use self-contained Lobster outlines', () => {
	const svg=fs.readFileSync(new URL('../games/php-billiards/art/svg/ball-atlas.svg',import.meta.url),'utf8');
	assert.doesNotMatch(svg,/<text\b|font-family=/,'Ball atlas depends on an installed or bundled font');
	assert.equal([...svg.matchAll(/<symbol id="n(?:[1-9]|1[0-5])"/g)].length,15);
	for(let number=1;number<=15;number++)
	{
		assert.match(svg,new RegExp(`<symbol id="n${number}"[^>]*><path d="M`),`Missing outlined ${number}`);
		assert.equal([...svg.matchAll(new RegExp(`href="#n${number}"`,'g'))].length,2,`Number ${number} must appear on both faces`);
	}
	assert.equal(fs.existsSync(new URL('../games/php-billiards/art/fonts/',import.meta.url)),false,'Source TTF was retained');
});
