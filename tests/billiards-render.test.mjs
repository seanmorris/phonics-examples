import test from 'node:test';
import assert from 'node:assert/strict';
import {cuePose, tableCsgProfiles} from '../games/php-billiards/browser/game.mjs';

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

	for(const [x,z] of profiles.pocketCenters)
	{
		const towardRailX = x === 0 ? x : x - Math.sign(x) * .035;
		const towardRailZ = Math.abs(z) === height / 2 ? z - Math.sign(z) * .035 : z;
		assert.ok(profiles.bed(x, z) > 0, 'Pocket was not cut through the cloth bed');
		assert.ok(profiles.wood(towardRailX, towardRailZ) > 0, 'Pocket was not cut through the wood rim');
		assert.ok(profiles.cushion(towardRailX, towardRailZ) > 0, 'Pocket was not cut through the cushion');
	}
});
