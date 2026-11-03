import {execFileSync} from 'node:child_process';
import {readFile} from 'node:fs/promises';
import {fileURLToPath} from 'node:url';
import {test, expect} from '@playwright/test';

test('initially flush walls follow moving sectors and retain winding, pegging and side lighting', async({page}) => {
	// Use the real mesh builder with authored geometry; no commercial IWAD is required.
	const fixtures = JSON.parse(execFileSync('php', [], {
		cwd: fileURLToPath(new URL('../../../../', import.meta.url))
		, encoding: 'utf8'
		, input: String.raw`<?php
			require 'packages/doom-phonics/autoload.php';
			require 'packages/doom-phonics/tests/fixtures/PortMap.php';
			$atlas = DoomPhonics\Graphics\IndexedAtlas::pack([
				'wall:STARTAN3' => new DoomPhonics\Graphics\IndexedImage(64,128,str_repeat("\1",8192),str_repeat("\xff",8192))
			]);
			$result = [];
			foreach(['normal','unpegged','left-only'] as $name) {
				$map = DoomPhonics\Tests\PortMap::map();
				$map->format = 'DOOM';
				$map->linedefs[0]['action'] = 0;
				$map->linedefs[0]['flags'] = $name === 'unpegged' ? 28 : 4;
				foreach([0,1] as $id) {
					$map->sectors[$id]['floor'] = 64;
					$map->sectors[$id]['ceiling'] = 192;
					$map->sidedefs[$id]['yOffset'] = $id ? 13 : 7;
					$map->sidedefs[$id]['middle'] = 'STARTAN3';
				}
				if($name === 'left-only')
				foreach(['upper','lower','middle'] as $slot) $map->sidedefs[0][$slot] = '-';
				$level = new DoomPhonics\Game\Level($map);
				$mesh = (new DoomPhonics\Render\MapMeshBuilder($level,$atlas))->build();
				$spans = [];
				foreach($mesh->wallSpans as $span) if($span['line'] === 0)
				$spans[$span['slot']] = array_slice($mesh->vertices,$span['first'] * 16,$span['count'] * 16);
				$vertices = $mesh->vertices;
				$level->sectors[0]->floor = 0;
				$level->sectors[0]->ceiling = 96;
				$result[$name] = ['spans'=>$spans,'stable'=>$mesh->sync($level,$atlas) === [] && $mesh->vertices === $vertices];
			}
			echo json_encode($result);
		`
	}));

	for(const fixture of Object.values(fixtures))
	{
		expect(Object.keys(fixture.spans).sort()).toEqual(['lower', 'middle', 'upper']);
		expect(fixture.stable).toBe(true);
	}

	const shader = await readFile(new URL('../../shaders/map.vert', import.meta.url), 'utf8');
	await page.setContent('<canvas></canvas>');

	const samples = await page.evaluate(({fixtures, shader}) => {
		const gl = document.querySelector('canvas').getContext('webgl2');

		const compile = (type, source) => {
			const object = gl.createShader(type);
			gl.shaderSource(object, source);
			gl.compileShader(object);

			if(!gl.getShaderParameter(object, gl.COMPILE_STATUS)) throw new Error(gl.getShaderInfoLog(object));

			return object;
		};

		const program = gl.createProgram();
		gl.attachShader(program, compile(gl.VERTEX_SHADER, shader));

		gl.attachShader(
			program
			, compile(
				gl.FRAGMENT_SHADER
				, '#version 300 es\nprecision highp float; out vec4 color; void main() { color = vec4(1.0); }'
			)
		);

		// Read the actual shader's output before rasterization, including collapsed walls.
		gl.transformFeedbackVaryings(program, ['gl_Position', 'textureUvs', 'lightLevels'], gl.INTERLEAVED_ATTRIBS);
		gl.linkProgram(program);

		if(!gl.getProgramParameter(program, gl.LINK_STATUS)) throw new Error(gl.getProgramInfoLog(program));

		gl.useProgram(program);
		gl.uniform2f(gl.getUniformLocation(program, 'forward'), 1, 0);
		gl.uniform2f(gl.getUniformLocation(program, 'projection'), 1, 1);
		const input = gl.createBuffer();
		gl.bindBuffer(gl.ARRAY_BUFFER, input);
		let offset = 0;

		[2, 2, 2, 2, 3, 3, 2].forEach((size, location) => {
			gl.enableVertexAttribArray(location);
			gl.vertexAttribPointer(location, size, gl.FLOAT, false, 64, offset);
			offset += size * 4;
		});

		const output = gl.createBuffer();
		gl.bindBuffer(gl.TRANSFORM_FEEDBACK_BUFFER, output);
		gl.bufferData(gl.TRANSFORM_FEEDBACK_BUFFER, 6 * 40, gl.STREAM_READ);
		gl.bindBufferBase(gl.TRANSFORM_FEEDBACK_BUFFER, 0, output);
		gl.bindTexture(gl.TEXTURE_2D, gl.createTexture());
		gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_MIN_FILTER, gl.NEAREST);
		gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_MAG_FILTER, gl.NEAREST);
		gl.enable(gl.RASTERIZER_DISCARD);
		const samples = [];

		for(const [name, fixture] of Object.entries(fixtures))
		for(const heights of [[64,192,64,192], [0,128,64,192], [96,224,64,192], [32,160,64,192], [64,192,64,192]])
		{
			const data = new Float32Array([
				heights[0], heights[1], 160, 0, heights[2], heights[3], 240, 0
				, 0, 0, 0, 0, 0, 0, 0, 1, 0, 0, 0, 0, 0, 0, 0, 1
			]);

			gl.texImage2D(gl.TEXTURE_2D, 0, gl.RGBA32F, 2, 3, 0, gl.RGBA, gl.FLOAT, data);

			for(const [slot, vertices] of Object.entries(fixture.spans))
			{
				gl.bindBuffer(gl.ARRAY_BUFFER, input);
				gl.bufferData(gl.ARRAY_BUFFER, new Float32Array(vertices), gl.STATIC_DRAW);
				gl.beginTransformFeedback(gl.TRIANGLES);
				gl.drawArrays(gl.TRIANGLES, 0, 6);
				gl.endTransformFeedback();
				const bytes = new ArrayBuffer(6 * 40);
				gl.getBufferSubData(gl.TRANSFORM_FEEDBACK_BUFFER, 0, new Uint8Array(bytes));
				const view = new DataView(bytes);

				const points = Array.from({length: 6}, (_, i) => ({
					z: view.getFloat32(i * 40 + 4, true)
					, rightV: view.getFloat32(i * 40 + 20, true)
					, leftV: view.getFloat32(i * 40 + 28, true)
					, lights: [view.getInt32(i * 40 + 32, true), view.getInt32(i * 40 + 36, true)]
				}));

				if(gl.getError() !== gl.NO_ERROR) throw new Error('Map shader transform feedback failed');

				samples.push({name, slot, heights, points});
			}
		}

		return samples;
	}, {fixtures, shader});

	for(const {name, slot, heights: [rf, rc, lf, lc], points} of samples)
	{
		const bottom = slot === 'upper' ? Math.min(rc, lc) : slot === 'lower' ? Math.min(rf, lf) : Math.max(rf, lf);
		const top = slot === 'upper' ? Math.max(rc, lc) : slot === 'lower' ? Math.max(rf, lf) : Math.min(rc, lc);
		const expectedHeights = [bottom, bottom, top, bottom, top, top];

		expect(points.map(p => p.z), `${name} ${slot} tracks live sector heights`).toEqual(expectedHeights);

		for(const p of points)
		{
			expect(p.lights).toEqual([160, 240]);

			const anchor = ceiling => slot === 'lower'
				? (name === 'unpegged' ? ceiling : top)
				: slot === 'upper'
					? (name === 'unpegged' ? top : bottom + 128)
					: name === 'unpegged' ? bottom + 128 : top;

			if(name !== 'left-only') expect(p.rightV).toBe(anchor(rc) + 7 - p.z);

			expect(p.leftV).toBe(anchor(lc) + 13 - p.z);
		}
	}
});
