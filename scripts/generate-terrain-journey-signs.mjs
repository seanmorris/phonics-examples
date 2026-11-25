// Bake the supplied highway artwork, Highway Gothic lettering and the bare road checkpoint bracket.
// The result is a single mesh/atlas with an indexed draw range for each kind of sign.
import fs from 'node:fs/promises';
import {fileURLToPath} from 'node:url';
import {gzipSync,gunzipSync} from 'node:zlib';
import {Resvg} from '@resvg/resvg-js';

const assets = new URL('../games/terrain-sandbox/assets/',import.meta.url);
const source = new URL('roads/journey/',assets);
const font = {loadSystemFonts:false,fontFiles:[fileURLToPath(new URL('HWYGOTH.TTF',source))]};
const width=1024, height=768, atlas=Buffer.alloc(width*height*4);
const glyphs={}, ranges={}, mounts={}, vertices=[], indices=[];
const greenScale=2, panelBottom=4.85;
const tag={frame:1, post:2, base:3, foot:4, face:5, hardware:6, digit:16};

function render(svg,fit) {
	return new Resvg(svg,{font,fitTo:fit ? {mode:'width',value:fit} : {mode:'original'}}).render();
}

function paste(image,x,y,from=0,w=image.width) {
	if(x+w>width || y+image.height>height) throw Error('Journey sign atlas overflow');
	const data=Buffer.from(image.pixels);
	for(let row=0;row<image.height;row++) data.copy(atlas,4*((y+row)*width+x),4*(row*image.width+from),4*(row*image.width+from+w));
	return [x,y,w,image.height];
}

const read=name=>fs.readFile(new URL(name,source),'utf8');
const base=(await read('miles-to-junction-sign.svg')).replace(/<text\b[^>]*\bid="text8"[\s\S]*?<\/text>/,'');
// These two lines sit below the distance row, centered in the text area beside the branching arrows.
function mileage(lines,x) {
	const caption=lines.map((s,i)=>'<text x="50.8" y="'+(20.1+8*i)+'" text-anchor="middle" font-family="Highway Gothic" font-size="8.45" fill="#f9f9f9">'+s+'</text>').join('');
	return paste(render(base.replace('</svg>',caption+'</svg>'),500),x,4);
}
const junction=mileage(['TO NEXT','JUNCTION'],4), stage=mileage(['TO STAGE','END'],516);
const left=paste(render(await read('left-lane-exit-sign.svg'),500),4,245);
const right=paste(render(await read('right-lane-exit-sign.svg'),500),516,245);
const fork=paste(render(await read('fork-sign.svg'),224),4,536);
const wrongWay=paste(render(await read('wrong-way-sign.svg'),256),764,536);

let cursor=240;
const glyphY=548, glyphHeight=64;
// Precompose the variable row on the sign's own green. Minified strokes then keep the
// same fractional pixel coverage as the two fixed captions, even in the distance.
const green=atlas.subarray(4*(18*width+220),4*(18*width+220)+3);
const backing=Buffer.from([...green,255]);
for(let y=glyphY-8;y<glyphY+glyphHeight+8;y++)
	atlas.fill(backing,4*(y*width+232),4*(y*width+wrongWay[0]-6));
for(const value of [...'0123456789.','MILE','MILES']) {
	const image=render('<svg xmlns="http://www.w3.org/2000/svg" width="170" height="64"><text x="3" y="48" font-family="Highway Gothic" font-size="48" fill="#f9f9f9">'+value+'</text></svg>');
	const pixels=image.pixels;
	let l=image.width,r=-1;
	for(let y=0;y<image.height;y++) for(let x=0;x<image.width;x++)
		if(pixels[4*(y*image.width+x)+3]) { l=Math.min(l,x); r=Math.max(r,x); }
	if(r<l) throw Error('Missing Highway Gothic glyph '+value);
	const w=r-l+3;
	if(cursor+w>wrongWay[0]-6) throw Error('Mileage lettering exceeds its atlas strip');
	for(let row=0;row<glyphHeight;row++) for(let col=0;col<w;col++) {
		const a=pixels[4*(row*image.width+l-1+col)+3]/255;
		const offset=4*((glyphY+row)*width+cursor+col);
		for(let c=0;c<3;c++) atlas[offset+c]=Math.round(green[c]+a*(249-green[c]));
	}
	glyphs[value]=[cursor,w]; cursor+=w+5;
}

function vertex(p,n,uv,part) {
	const b=Buffer.alloc(28);
	p.forEach((v,i)=>b.writeFloatLE(v,4*i));
	n.forEach((v,i)=>b.writeInt16LE(Math.round(32767*v),12+2*i));
	uv.forEach((v,i)=>b.writeUInt16LE(Math.round(65535*v),18+2*i));
	b[27]=part;
	vertices.push(b);
	return vertices.length-1;
}
function quad(corners,normal,rect,part) {
	const [x,y,w,h]=rect;
	const u=(x+.5)/width,v=(y+.5)/height,du=(w-1)/width,dv=(h-1)/height;
	const uv=[[u,v+dv],[u+du,v+dv],[u+du,v],[u,v]];
	const [a,b,c,d]=corners.map((p,i)=>vertex(p,normal,uv[i],part));
	indices.push(a,b,c,a,c,d);
}
function panel(rect,mid,bottom,w,z=-.56,normalZ=-1) {
	const h=w*rect[3]/rect[2];
	// Left and right are as seen by a driver facing this side of the gantry.
	const left=mid+normalZ*w/2, right=mid-normalZ*w/2;
	quad([[left,bottom,z],[right,bottom,z],[right,bottom+h,z],[left,bottom+h,z]],[0,0,normalZ],rect,tag.face);
}
function gantryPanel(rect,mid,w,fitWidth) {
	const originalHeight=w*rect[3]/rect[2], rearHeight=.90*originalHeight;
	const width=w*greenScale, height=originalHeight*greenScale, center=mid*greenScale;
	panel(rect,center,panelBottom,width);
	panel(wrongWay,center,panelBottom+(height-rearHeight)/2,rearHeight*wrongWay[2]/wrongWay[3],.56,1);
	return {fitWidth,span:2*Math.abs(center)+width,top:panelBottom+height,lift:(height-originalHeight)/2};
}
function range(kind,build) {
	const start=indices.length; build(); ranges[kind]=[start,indices.length-start];
}

// Keep the checkpoint's frame, posts, foot plates and caps. The race print and checkers have their own parts.
const gate=gunzipSync(await fs.readFile(new URL('gates/road.mesh.bin.gz',assets)));
const count=gate.readUInt32LE(0), oldIndices=16+count*28, remap=new Map(), bracket=[];
for(let i=0;i<gate.readUInt32LE(4);i+=3) {
	const face=[0,1,2].map(j=>gate.readUInt16LE(oldIndices+2*(i+j)));
	const part=gate[16+28*face[0]+27]&63;
	if(![2,4,5,6].includes(part)) continue;
	for(const index of face) {
		if(!remap.has(index)) {
			const pos=16+28*index, v=Buffer.from(gate.subarray(pos,pos+28));
			const y=v.readFloatLE(4);
			v[27]=part===2 ? tag.frame : part===6 ? tag.base : part===5 && y<0 ? tag.foot : tag.post;
			if(part!==6 && !(part===5 && y<0)) v.writeFloatLE(y+3.2,4);
			remap.set(index,vertices.push(v)-1);
		}
		bracket.push(remap.get(index));
	}
}

function mileageMesh(rect) {
	indices.push(...bracket);
	mounts.mile=gantryPanel(rect,0,10.5,10.5);
	// One quad per printed token. The instance supplies the number and singular/plural unit.
	for(let i=0;i<8;i++) quad([[0,0,-.575],[1,0,-.575],[1,1,-.575],[0,1,-.575]],[0,0,-1],[0,0,1,1],tag.digit+i);
}
range('junction',()=>mileageMesh(junction));
range('stage',()=>mileageMesh(stage));
range('exits',()=>{
	indices.push(...bracket);
	gantryPanel(left,-4.08,7.8,16);
	mounts.exits=gantryPanel(right,4.08,7.8,16);
});
function roadsidePanel(rect) {
	const r=.095,z=.04,b=-1.4,t=3.35,none=[0,0,1,1];
	quad([[-r,b,z-r],[r,b,z-r],[r,t,z-r],[-r,t,z-r]],[0,0,-1],none,tag.hardware);
	quad([[r,b,z+r],[-r,b,z+r],[-r,t,z+r],[r,t,z+r]],[0,0,1],none,tag.hardware);
	quad([[-r,b,z+r],[-r,b,z-r],[-r,t,z-r],[-r,t,z+r]],[-1,0,0],none,tag.hardware);
	quad([[r,b,z-r],[r,b,z+r],[r,t,z+r],[r,t,z-r]],[1,0,0],none,tag.hardware);
	panel(rect,0,1.60,2.65,-.085);
}
range('fork',()=>roadsidePanel(fork));
range('wrongWay',()=>roadsidePanel(wrongWay));

const header=Buffer.alloc(16), indexBytes=Buffer.alloc(2*indices.length);
[vertices.length,indices.length,width,height].forEach((v,i)=>header.writeUInt32LE(v,i*4));
indices.forEach((v,i)=>indexBytes.writeUInt16LE(v,2*i));
const mesh=Buffer.concat([header,...vertices,indexBytes,atlas]);
const meta=Buffer.from(JSON.stringify({ranges,glyphs,mounts,greenScale,panelBottom,atlasWidth:width,glyphV:(glyphY+.5)/height,glyphHeight:(glyphHeight-1)/height})+'\n');
const check=process.argv.includes('--check');
for(const [name,bytes,packed] of [['journey-signs.mesh.bin.gz',mesh,true],['journey-signs.json',meta,false]]) {
	const file=new URL('roads/'+name,assets);
	const prior=await fs.readFile(file).catch(e=>{if(e.code!=='ENOENT') throw e;});
	if(prior && (packed ? gunzipSync(prior) : prior).equals(bytes)) continue;
	if(check) throw Error('Stale '+name);
	await fs.writeFile(file,packed ? gzipSync(bytes,{level:9}) : bytes);
}
console.log('Journey sign mesh, artwork and Highway Gothic lettering verified.');
