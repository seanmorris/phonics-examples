import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import {PNG} from 'pngjs';
import {billiardsEdgeRadii, billiardsMaterials, billiardsRailSights, billiardsTableMeshing, cueAppearance, cueContactWarning, cueDyeMask, cuePlacementValid, cuePose, orbitYawDelta, pocketVoidRadius, reflectedGuideSegments, roundedExtrusionSdf, roundedFilletNormal, tableCsgProfiles, tablePocketDefinitions, tablePointFromPointer, tableRailSightDefinitions} from '../games/php-billiards/browser/game.mjs';

const width = 2.54, height = 1.27, radius = .028575;

test('rail sights follow the WPA nine-foot table layout', () => {
	const sights=tableRailSightDefinitions(width,height),long=sights.filter(sight=>sight.rail==='long');
	const short=sights.filter(sight=>sight.rail==='short');

	assert.equal(sights.length,18);
	assert.equal(long.length,12);
	assert.equal(short.length,6);
	assert.ok(sights.every(sight=>Math.abs(sight.radius*2-billiardsRailSights.diameter)<1e-12));
	assert.ok(billiardsRailSights.diameter>=.01111&&billiardsRailSights.diameter<=.0127);
	assert.ok(long.every(sight=>Math.abs(Math.abs(sight.z)-height/2-billiardsRailSights.offsetFromCushion)<1e-12));
	assert.ok(short.every(sight=>Math.abs(Math.abs(sight.x)-width/2-billiardsRailSights.offsetFromCushion)<1e-12));
	assert.ok(long.every(sight=>Math.abs(sight.x)>1e-12),'A sight was placed over a side pocket');
	for(const side of [-1,1])
	{
		const xs=long.filter(sight=>Math.sign(sight.z)===side).map(sight=>sight.x).sort((a,b)=>a-b);
		const zs=short.filter(sight=>Math.sign(sight.x)===side).map(sight=>sight.z).sort((a,b)=>a-b);
		assert.ok(xs.every((value,index)=>Math.abs(value-[-3,-2,-1,1,2,3][index]*width/8)<1e-12));
		assert.ok(zs.every((value,index)=>Math.abs(value-[-1,0,1][index]*height/4)<1e-12));
	}
});

test('cue rises over the solid rail while its tip stays aligned with the ball', () => {
	for(const [x,y,angle] of [[-width * .28,0,0],[0,0,Math.PI / 2]
		,[width / 2 - radius * 1.02,.2,Math.PI],[-width / 2 + radius * 1.02,-.2,0]])
	{
		const pose = cuePose(x, y, angle, width, height, radius);
		const heightAtRail = pose.ballCenter[1] + Math.tan(pose.pitch) * pose.railDistance;
		const [qx,qy,qz,qw] = pose.rotation;
		const cueAxis = [
			1 - 2 * (qy * qy + qz * qz)
			, 2 * (qx * qy + qw * qz)
			, 2 * (qx * qz - qw * qy)
		];
		const renderedTip = pose.position.map((value, index) => value + cueAxis[index] * pose.scale[0] / 2);

		assert.ok(heightAtRail >= pose.railClearance - 1e-12, 'Shaft intersects the rail');
		assert.ok(pose.position[1] > pose.tip[1], 'Cue butt did not rise toward the player');
		assert.ok(pose.tip.every((value,index)=>Math.abs(value-(pose.ballCenter[index]-pose.direction[index]*pose.contactDistance))<1e-12),
			'Cue tip stopped following the pitched contact axis');
		assert.ok(Math.abs(Math.hypot(...pose.tip.map((value,index)=>value-pose.ballCenter[index]))-radius-.008)<1e-12,
			'Cue tip moved away from the ball surface');
		assert.ok(renderedTip.every((value, index) => Math.abs(value - pose.tip[index]) < 1e-12),
			'Cue rotation moved the rendered tip away from its intended contact point');
	}
});

test('cue dimensions describe a tapered cylinder with distinct tip and collar sections', () => {
	assert.ok(cueAppearance.buttDiameter>cueAppearance.shaftDiameter);
	assert.ok(cueAppearance.chalkTipLength>0);
	assert.ok(cueAppearance.copperCollarLength>cueAppearance.chalkTipLength);
	assert.equal(cueAppearance.patternPoints%2,0);
});

test('cue dye forms one dark butt with joined points facing the tip', () => {
	const {patternBase,patternLength,patternPoints}=cueAppearance;
	for(let point=0;point<patternPoints;point++)
	{
		const centre=point/patternPoints,boundary=(point+.5)/patternPoints;
		assert.equal(cueDyeMask(patternBase-.001,centre),1,'Dyed butt has a gap behind a point');
		assert.equal(cueDyeMask(patternBase-.001,boundary),1,'Adjacent point bases do not join');
		assert.equal(cueDyeMask(patternBase+patternLength*.9,centre),1,'Triangle does not point toward the tip');
		assert.equal(cueDyeMask(patternBase+patternLength*.9,boundary),0,'Triangle direction reverses between points');
	}
	assert.equal(cueDyeMask(patternBase+patternLength+.001,0),0,'Dye extends beyond the forward point tips');
});

test('horizontal orbit direction reverses below the on-screen target', () => {
	assert.equal(orbitYawDelta(20,40,50),-.12);
	assert.equal(orbitYawDelta(20,60,50),.12);
});

test('aiming guide reflects at cushions and remains inside the playing limits', () => {
	const limitX=width/2-radius,limitZ=height/2-radius;
	const straight=reflectedGuideSegments(0,0,0,width,height,radius);
	assert.equal(straight.length,1);
	assert.deepEqual(straight[0].start,[.05,0]);
	assert.ok(Math.abs(straight[0].length-.66)<1e-12);

	const reflected=reflectedGuideSegments(limitX-.09,0,0,width,height,radius);
	assert.equal(reflected.length,2);
	assert.ok(Math.abs(reflected[0].end[0]-limitX)<1e-12);
	assert.deepEqual(reflected[1].direction,[-1,0]);
	assert.ok(Math.abs(reflected.reduce((sum,segment)=>sum+segment.length,0)-.66)<1e-12);
	for(const segment of reflected)for(const point of [segment.start,segment.end])
	{
		assert.ok(Math.abs(point[0])<=limitX+1e-12);
		assert.ok(Math.abs(point[1])<=limitZ+1e-12);
	}

	const corner=reflectedGuideSegments(limitX-.02,limitZ-.02,Math.PI/4,width,height,radius,.3,0);
	assert.equal(corner.length,2);
	assert.ok(corner[1].direction[0]<0&&corner[1].direction[1]<0,'Corner contact did not reflect both axes');
});

test('scratch pointer projection and placement preview follow the simulation rules', () => {
	const camera={yaw:-.42,pitch:.72,distance:3.35,target:[0,0,0]},bounds={left:100,top:50,width:900,height:600};
	const center=tablePointFromPointer(550,350,bounds,camera,0);
	assert.ok(Math.abs(center[0])<1e-12&&Math.abs(center[1])<1e-12,
		'Screen centre did not project onto the camera target');
	const state={table:[width,height,radius,.1158875,.1285875],balls:[
		{number:0,x:0,y:0,active:false},{number:4,x:.2,y:.1,active:true}
	]};
	assert.equal(cuePlacementValid(state,-.65,0),true);
	assert.equal(cuePlacementValid(state,.2,.1),false,'Preview allowed overlap with an object ball');
	assert.equal(cuePlacementValid(state,width/2,0),false,'Preview allowed placement through a cushion');
	const sidePocket=tablePocketDefinitions(width,height)[1];
	assert.equal(cuePlacementValid(state,sidePocket.x,sidePocket.z),false,'Preview allowed placement in a pocket');
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
	assert.ok(profiles.cushion(.25,height/2+.0275)<0, 'Straight cushion band is missing');
	assert.ok(profiles.cushion(.25,height/2-.001)>0, 'Cushion widened into the playing area');
	assert.ok(profiles.cushion(.25,height/2+.056)>0, 'Cushion exceeded its constant rail width');
	assert.ok(profiles.cushion(0,height/2+.0275)>0, 'Side-pocket cushion wrapped around the pocket back');
	const sidePocket=profiles.pockets[1],sideRadius=sidePocket.radius;
	assert.ok(profiles.bed(sidePocket.x,sidePocket.z+sideRadius-.005)>0,
		'Cloth bed entered the shared pocket cylinder');
	assert.ok(profiles.bed(sidePocket.x,sidePocket.z+sideRadius+.005)<0,
		'Cloth bed stopped beyond the shared pocket cylinder');
	assert.ok(profiles.apron(sidePocket.x,sidePocket.z-sideRadius+.005)>0,
		'Lower table body entered the shared pocket cylinder');
	assert.ok(profiles.apron(sidePocket.x,sidePocket.z-sideRadius-.005)<0,
		'Lower table body stopped beyond the shared pocket cylinder');

	for(const pocket of profiles.pockets)
	{
		const x=pocket.x+pocket.outward[0]*pocket.mouth*.35,z=pocket.z+pocket.outward[1]*pocket.mouth*.35;
		assert.ok(profiles.apron(x, z) > 0, 'Pocket was not cut through the table body');
		assert.ok(profiles.bed(x, z) > 0, 'Pocket was not cut through the cloth bed');
		assert.ok(profiles.wood(x, z) > 0, 'Pocket was not cut through the wood rim');
		assert.ok(profiles.cushion(x, z) > 0, 'Pocket was not cut through the cushion');
	}
});

test('all cushion noses stay on their logical jaw points and meet the shared facings', () => {
	const profiles=tableCsgProfiles(width,height);
	assert.equal(profiles.pockets.length,6);
	assert.equal(profiles.cushionRails.length,6);
	for(const rail of profiles.cushionRails)
	{
		assert.equal(rail.length,4,'Explicit cushion rail stopped being a convex quadrilateral');
		const area=rail.reduce((sum,[x,z],index)=>{const [nextX,nextZ]=rail[(index+1)%rail.length];
			return sum+x*nextZ-nextX*z;},0);
		assert.ok(area>0,'Explicit cushion rail changed winding');
		const spanX=Math.max(...rail.map(point=>point[0]))-Math.min(...rail.map(point=>point[0]));
		const spanZ=Math.max(...rail.map(point=>point[1]))-Math.min(...rail.map(point=>point[1]));
		const depth=spanX>spanZ?spanZ:spanX;
		assert.ok(Math.abs(depth-billiardsTableMeshing.cushionWidth)<1e-12,
			'Cushion stopped being a constant-width band');
		for(let index=0;index<rail.length;index++)
		{
			const next=rail[(index+1)%rail.length],length=Math.hypot(next[0]-rail[index][0],next[1]-rail[index][1]);
			if(length>.15)continue;
			const midpoint=[(rail[index][0]+next[0])/2,(rail[index][1]+next[1])/2];
			assert.ok(Math.min(...profiles.pockets.map(pocket=>Math.abs(pocket.openingSdf(...midpoint))))<1e-10,
				'Cushion end stopped following its pocket facing');
		}
	}
	const cornerJaw=profiles.pockets[0].mouth/Math.SQRT2;
	const sideJaw=profiles.pockets[1].mouth/2;

	for(const side of [-1,1])
	{
		const z=side*height/2;
		for(const x of [-sideJaw,sideJaw,-width/2+cornerJaw,width/2-cornerJaw])
		assert.ok(Math.abs(profiles.cushion(x,z))<1e-12,'Horizontal cushion nose moved away from its logical jaw');

		const x=side*width/2;
		for(const jawZ of [-height/2+cornerJaw,height/2-cornerJaw])
		assert.ok(Math.abs(profiles.cushion(x,jawZ))<1e-12,'Vertical cushion nose moved away from its logical jaw');
	}
});

test('explicit felt cushion caps stay outside every jaw-facing-throat profile', () => {
	const profiles=tableCsgProfiles(width,height);
	for(const pocket of profiles.pockets)
	{
		const samples=[[pocket.x,pocket.z],pocket.mouthCenter
			,...pocket.faces.map(({jaw,tangent})=>[(jaw[0]+tangent[0])/2,(jaw[1]+tangent[1])/2])];
		for(const [x,z] of samples)assert.ok(profiles.cushion(x,z)>=-1e-10,
			`Felt entered pocket ${pocket.x},${pocket.z} at ${x},${z}`);
	}
});

test('tabletop materials subtract the round throat while cloth preserves the mouth shelf', () => {
	const profiles=tableCsgProfiles(width,height);
	for(const pocket of profiles.pockets)
	{
		assert.ok(profiles.pocketCut(pocket.x,pocket.z)<0,'Round throat is not inside the shared cut');
		for(let step=0;step<48;step++)
		{
			const angle=step/48*Math.PI*2,point=[pocket.x+Math.cos(angle)*pocket.radius,pocket.z+Math.sin(angle)*pocket.radius];
			assert.ok(Math.abs(profiles.pocketCut(...point))<1e-10,'Circular tabletop hole changed radius');
		}
		for(const material of ['apron','bed','wood'])assert.ok(profiles[material](pocket.x,pocket.z)>0,
			`${material} did not subtract the circular tabletop hole`);

		const lateral=[-pocket.outward[1],pocket.outward[0]],shelf=[
			pocket.mouthCenter[0]+lateral[0]*pocket.mouth*.35+pocket.outward[0]*.005
			, pocket.mouthCenter[1]+lateral[1]*pocket.mouth*.35+pocket.outward[1]*.005
		];
		assert.ok(pocket.openingSdf(...shelf)<0&&profiles.pocketCut(...shelf)>0,
			'Mouth shelf did not separate the flared cushion opening from the round hole');
		assert.ok(profiles.bed(...shelf)<0,'Cloth shelf was cut away between the jaws and round hole');
		assert.ok(profiles.wood(...shelf)>0,'Wood covered the cloth pocket shelf');
	}
});

test('black pocket surfaces remain inside and below the tabletop hole', () => {
	assert.ok(billiardsTableMeshing.pocketLinerTop<billiardsTableMeshing.tabletopTop,
		'Leather liner can overpaint the cloth surface');
	for(const pocket of tablePocketDefinitions(width,height))
	{
		assert.equal(pocketVoidRadius(pocket.radius),pocket.radius-billiardsTableMeshing.pocketLipInset);
		assert.ok(pocketVoidRadius(pocket.radius)<pocket.radius,'Black void reached beyond the leather lip');
	}
});

test('wood extrusion rounds its inner and outer top edges with a true quarter-circle fillet', () => {
	const bottom=.012,top=.105,radius=billiardsEdgeRadii.wood,middle=(bottom+top)/2;
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

test('rounded fillet normals follow the solved elliptical cross-section', () => {
	assert.deepEqual(roundedFilletNormal(1,0,0,.028,.018),[1,0,0]);
	const top=roundedFilletNormal(1,0,Math.PI/2,.028,.018);
	assert.ok(Math.abs(top[0])<1e-12&&Math.abs(top[1]-1)<1e-12&&top[2]===0);
	const middle=roundedFilletNormal(Math.SQRT1_2,Math.SQRT1_2,Math.PI/4,.028,.018);
	assert.ok(Math.abs(Math.hypot(...middle)-1)<1e-12);
	assert.ok(middle[1]>middle[0], 'Elliptical fillet kept a circular normal after its inset changed');
});

test('felt cushion rounding is shallower than the wood rail rounding', () => {
	assert.ok(billiardsEdgeRadii.cushion>0);
	assert.ok(billiardsEdgeRadii.cushion<billiardsEdgeRadii.wood/2);
	assert.ok(billiardsTableMeshing.cushionTopSegments>=4,
		'Explicit cushion top fillet is visibly faceted');
	assert.equal(billiardsEdgeRadii.cushionBottom,0, 'Cushion must remain flush with the cloth at its lower edge');
});

test('visual pocket mouths use regulation widths', () => {
	const pockets=tablePocketDefinitions(width,height),corners=pockets.filter((_,index)=>![1,4].includes(index)),sides=[pockets[1],pockets[4]];
	assert.ok(corners.every(pocket=>pocket.mouth>=4.5*.0254&&pocket.mouth<=4.625*.0254));
	assert.ok(sides.every(pocket=>pocket.mouth>=5*.0254&&pocket.mouth<=5.25*.0254));
	assert.ok(pockets.every(pocket=>Math.abs(pocket.radius*2-corners[0].mouth)<1e-12),
		'The six round drop throats stopped sharing the reference diameter');
	assert.ok(sides.every(pocket=>pocket.mouth>pocket.radius*2),
		'Side mouth no longer flares wider than its round throat');
	for(const pocket of pockets)
	{
		assert.ok(Math.abs(Math.hypot(pocket.jaws[1][0]-pocket.jaws[0][0],pocket.jaws[1][1]-pocket.jaws[0][1])-pocket.mouth)<1e-12,
			'Jaw chord stopped matching its regulation width');
		for(const {jaw,tangent} of pocket.faces)
		{
			const radius=[tangent[0]-pocket.x,tangent[1]-pocket.z],facing=[tangent[0]-jaw[0],tangent[1]-jaw[1]];
			assert.ok(Math.abs(Math.hypot(...radius)-pocket.radius)<1e-12,'Facing missed the round throat');
			assert.ok(Math.abs(radius[0]*facing[0]+radius[1]*facing[1])<1e-12,'Facing stopped being tangent to the throat');
		}
	}
	assert.ok(sides.every(pocket=>Math.abs(Math.hypot(pocket.x-pocket.mouthCenter[0],pocket.z-pocket.mouthCenter[1])
		-billiardsTableMeshing.cushionWidth)<1e-12),'Side throat is not one cushion width behind its mouth');
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
	const [cueRoughness,cueIor,cueMode,cueCoat] = billiardsMaterials.cueWood;
	const [feltRoughness,,feltMode,feltSheen] = billiardsMaterials.felt;
	const [cushionRoughness,,cushionMode,cushionSheen] = billiardsMaterials.cushionFelt;

	assert.ok(ballRoughness >= .01 && ballRoughness <= .05);
	assert.ok(ballIor >= 1.54 && ballIor <= 1.58);
	assert.deepEqual([ballMode,ballCoat], [1,.35]);
	assert.ok(woodRoughness >= .4 && woodRoughness <= .6);
	assert.ok(woodIor >= 1.5 && woodIor <= 1.55);
	assert.deepEqual([woodMode,woodCoat], [2,1]);
	assert.ok(cueRoughness<woodRoughness);
	assert.deepEqual([cueIor,cueMode,cueCoat], [woodIor,6,1]);
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
