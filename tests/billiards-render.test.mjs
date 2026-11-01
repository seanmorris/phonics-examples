import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import {PNG} from 'pngjs';
import {billiardsMaterials, cueContactWarning, cuePose, pocketDropPose, roundedExtrusionSdf, tableCsgProfiles, tablePocketDefinitions} from '../games/php-billiards/browser/game.mjs';

const width = 2.54, height = 1.27, radius = .028575;

test('cue rises over the solid rail while its tip stays aligned with the ball', () => {
	for(const [x,y,angle] of [[-width * .28,0,0],[0,0,Math.PI / 2],[width / 2 - radius * 1.2,.2,Math.PI]])
	{
		const pose = cuePose(x, y, angle, width, height, radius);
		const heightAtRail = pose.tip[1] + Math.tan(pose.pitch) * pose.railDistance;
		const forward = [Math.cos(angle),Math.sin(angle)];
		const [qx,qy,qz,qw] = pose.rotation;
		const cueAxis = [
			1 - 2 * (qy * qy + qz * qz)
			, 2 * (qx * qy + qw * qz)
			, 2 * (qx * qz - qw * qy)
		];
		const renderedTip = pose.position.map((value, index) => value + cueAxis[index] * pose.scale[0] / 2);

		assert.ok(heightAtRail >= pose.railClearance - 1e-12, 'Shaft intersects the rail');
		assert.ok(pose.position[1] > pose.tip[1], 'Cue butt did not rise toward the player');
		assert.ok(Math.abs(pose.tip[0] - (x - forward[0] * (radius + .008))) < 1e-12);
		assert.ok(Math.abs(pose.tip[2] - (y - forward[1] * (radius + .008))) < 1e-12);
		assert.ok(renderedTip.every((value, index) => Math.abs(value - pose.tip[index]) < 1e-12),
			'Cue rotation moved the rendered tip away from its intended contact point');
	}
});

test('table CSG profiles round the shell and cut every pocket through the playing surfaces', () => {
	const profiles = tableCsgProfiles(width, height);
	const outerX = width / 2 + .17, outerZ = height / 2 + .17;

	assert.ok(profiles.apron(outerX - .01, 0) < 0, 'Straight apron edge is missing');
	assert.ok(profiles.apron(outerX, outerZ) > 0, 'Apron corner was not rounded');
	assert.ok(profiles.wood(0, 0) > 0, 'Wood rail did not subtract the cloth opening');
	assert.ok(profiles.cushion(0, 0) > 0, 'Cushion did not subtract the playing area');
	assert.ok(profiles.cushion(width / 2 - radius, .2) > 0, 'Cushion intersects a ball at its collision limit');
	assert.ok(Math.abs(profiles.cushion(width / 2, .2)) < 1e-12, 'Cushion face does not match the physics boundary');

	for(const pocket of profiles.pockets)
	{
		const x=pocket.x+pocket.outward[0]*pocket.mouth*.35,z=pocket.z+pocket.outward[1]*pocket.mouth*.35;
		assert.ok(profiles.apron(x, z) > 0, 'Pocket was not cut through the table body');
		assert.ok(profiles.bed(x, z) > 0, 'Pocket was not cut through the cloth bed');
		assert.ok(profiles.wood(x, z) > 0, 'Pocket was not cut through the wood rim');
		assert.ok(profiles.cushion(x, z) > 0, 'Pocket was not cut through the cushion');
	}
});

test('wood extrusion rounds its inner and outer top edges with a true quarter-circle fillet', () => {
	const bottom=.012,top=.105,radius=.018,middle=(bottom+top)/2;
	assert.ok(Math.abs(roundedExtrusionSdf(0,middle,bottom,top,radius))<1e-12, 'Vertical wall moved inward');
	assert.ok(Math.abs(roundedExtrusionSdf(-radius,top,bottom,top,radius))<1e-12, 'Inset cap does not meet the fillet');
	assert.ok(roundedExtrusionSdf(0,top,bottom,top,radius)>0, 'Former hard cap corner remains solid');

	for(const angle of [Math.PI/8,Math.PI/4,Math.PI*3/8])
	{
		const horizontal=-radius+Math.cos(angle)*radius;
		const y=top-radius+Math.sin(angle)*radius;
		assert.ok(Math.abs(roundedExtrusionSdf(horizontal,y,bottom,top,radius))<1e-12,
			'Fillet does not follow a circular profile');
	}
});

test('visual pocket mouths use regulation widths', () => {
	const pockets=tablePocketDefinitions(width,height),corners=pockets.filter((_,index)=>![1,4].includes(index)),sides=[pockets[1],pockets[4]];
	assert.ok(corners.every(pocket=>pocket.mouth>=4.5*.0254&&pocket.mouth<=4.625*.0254));
	assert.ok(sides.every(pocket=>pocket.mouth>=5*.0254&&pocket.mouth<=5.25*.0254));
});

test('captured balls descend into the pocket before rolling outward down the return', () => {
	const pocket=tablePocketDefinitions(width,height)[0],start=pocketDropPose(pocket,radius,0);
	const throat=pocketDropPose(pocket,radius,.38),end=pocketDropPose(pocket,radius,1);
	assert.deepEqual(start,[pocket.x,radius+.012,pocket.z]);
	assert.ok(throat[1]<start[1]&&end[1]<throat[1], 'Captured ball did not descend');
	assert.ok(Math.hypot(throat[0]-pocket.x,throat[2]-pocket.z)<1e-12, 'Ball left the mouth before reaching the throat');
	assert.ok(Math.hypot(end[0]-pocket.x,end[2]-pocket.z)>.05, 'Ball did not roll outward along the return');
});

test('a nearly frozen cue warns only when the contact is in the shot direction', () => {
	const cue={number:0,x:0,y:0,active:true},close={number:4,x:radius*2+.0005,y:0,active:true};
	assert.equal(cueContactWarning([cue,close],radius,0),4);
	assert.equal(cueContactWarning([cue,close],radius,Math.PI),null);
	assert.equal(cueContactWarning([cue,{...close,x:radius*2+.002}],radius,0),null);
});

test('material presets model resin, varnished wood, and felt as dielectrics', () => {
	const [ballRoughness,ballIor,ballMode,ballCoat] = billiardsMaterials.ball;
	const [woodRoughness,woodIor,woodMode,woodCoat] = billiardsMaterials.wood;
	const [feltRoughness,,feltMode,feltSheen] = billiardsMaterials.felt;
	const [cushionRoughness,,cushionMode,cushionSheen] = billiardsMaterials.cushionFelt;

	assert.ok(ballRoughness >= .01 && ballRoughness <= .05);
	assert.ok(ballIor >= 1.54 && ballIor <= 1.58);
	assert.deepEqual([ballMode,ballCoat], [1,.35]);
	assert.ok(woodRoughness >= .4 && woodRoughness <= .6);
	assert.ok(woodIor >= 1.5 && woodIor <= 1.55);
	assert.deepEqual([woodMode,woodCoat], [2,1]);
	assert.ok(feltRoughness >= .85 && feltRoughness <= .95);
	assert.deepEqual([feltMode,feltSheen], [3,.85]);
	assert.ok(cushionRoughness >= .85 && cushionRoughness <= .95);
	assert.equal(cushionMode,3);
	assert.ok(cushionSheen>feltSheen);
});

test('generated wood grain tiles without visible edge discontinuities', () => {
	const image = PNG.sync.read(fs.readFileSync(new URL('../games/php-billiards/assets/generated/wood.png', import.meta.url)));
	let leftRight = 0, topBottom = 0;

	for(let y=0;y<image.height;y++) for(let channel=0;channel<3;channel++)
		leftRight += Math.abs(image.data[(y * image.width) * 4 + channel]
			- image.data[(y * image.width + image.width - 1) * 4 + channel]);
	for(let x=0;x<image.width;x++) for(let channel=0;channel<3;channel++)
		topBottom += Math.abs(image.data[x * 4 + channel]
			- image.data[((image.height - 1) * image.width + x) * 4 + channel]);

	assert.ok(leftRight / (image.height * 3) < 8, 'Wood texture has a visible left/right seam');
	assert.ok(topBottom / (image.width * 3) < 8, 'Wood texture has a visible top/bottom seam');
});

test('ball skins use high-resolution two-to-one equirectangular cells', () => {
	const image = PNG.sync.read(fs.readFileSync(new URL('../games/php-billiards/assets/generated/ball-atlas.png', import.meta.url)));
	const cellWidth=image.width/4,cellHeight=image.height/4;
	assert.deepEqual([image.width,image.height], [2048,1024]);
	assert.equal(cellWidth/cellHeight,2, 'Ball skin cells distort longitude relative to latitude');

	const points=[];
	for(let y=0;y<cellHeight;y++)for(let x=0;x<cellWidth/2;x++)
	{
		const offset=((y*image.width)+(cellWidth+x))*4;
		if(image.data[offset]>245&&image.data[offset+1]>245&&image.data[offset+2]>245)points.push([x,y]);
	}
	const xs=points.map(point=>point[0]),ys=points.map(point=>point[1]);
	const width=Math.max(...xs)-Math.min(...xs),height=Math.max(...ys)-Math.min(...ys);
	assert.ok(Math.abs(width-height)<=2, 'Number spot is not circular in equirectangular space');
});
