import {test, expect} from '@playwright/test';

const loadGame = async page => {
	await page.goto('/php-noid/');
	await expect(page.locator('#status')).toHaveText('Ready', {timeout: 60000});
	await page.locator('[data-action="start"]').click();
	await expect(page.locator('.php-noid-title')).toBeHidden();
};

test('Tiled stage art and the sprite HUD use the packaged pixel assets', async ({page}) => {
	await loadGame(page);
	await expect(page.locator('.php-noid-score .php-noid-glyph')).toHaveCount(6);
	await expect(page.locator('.php-noid-score')).toHaveText('000000');

	const artwork = await page.evaluate(() => {
		const glyph = getComputedStyle(document.querySelector('.php-noid-glyph'));
		const canvas = document.querySelector('canvas');
		const state = JSON.parse(canvas.dataset.phpNoid);

		return {
			font: glyph.webkitMaskImage || glyph.maskImage
			, fontRendering: glyph.imageRendering
			, hud: state.hud
			, size: [canvas.width, canvas.height]
		};
	});

	expect(artwork.font).toContain('blob:');
	expect(artwork.fontRendering).toBe('pixelated');
	expect(artwork.size).toEqual([854, 480]);

	expect(artwork.hud).toMatchObject({
		width: 230
		, height: 480
		, game: 'PHP/NOID'
		, stage: 'First Light'
		, logoBounds: [14, 158]
	});

	expect(artwork.hud.glyphs).toBeGreaterThan(20);

	await page.evaluate(async () => {
		const result = await previewSession.php.run(String.raw`<?php
			$layer=$field->map->layer('Background');
			(vrzno_env('canvas'))->setAttribute('data-background-source',$layer->image);
			(vrzno_env('canvas'))->setAttribute('data-background-size',$layer->imageWidth.'x'.$layer->imageHeight);
			$engine->device->begin([.025,.035,.085,1]);
			glViewport(\PhpNoid\Render\Hud::WIDTH,0,\PhpNoid\Render\Hud::PLAYFIELD_WIDTH,\PhpNoid\Render\Hud::HEIGHT);
			$background->render($camera);
			\PhpNoid\Render\Scene::draw(
				$field,$rects,\PhpNoid\Render\Hud::PLAYFIELD_LEFT,\PhpNoid\Render\Hud::PLAYFIELD_WIDTH
			);
			(vrzno_env('canvas'))->setAttribute('data-playfield-edges',vrzno_eval('(()=>{
				const gl=document.querySelector("canvas").getContext("webgl2");
				return JSON.stringify([230,853].map(x=>{
					const p=new Uint8Array(4);gl.readPixels(x,240,1,1,gl.RGBA,gl.UNSIGNED_BYTE,p);
					return Array.from(p);
				}));
			})()'));
		`
		);

		if(result) throw new Error('Background layer probe failed: ' + result);
	});

	const canvas = page.locator('canvas');
	const source = await canvas.getAttribute('data-background-source');

	expect(source).toMatch(/\/assets\/backgrounds\/stage-01\.png$/);
	expect(await canvas.getAttribute('data-background-size')).toBe('640x480');
	const edges = JSON.parse(await canvas.getAttribute('data-playfield-edges'));
	expect(edges).not.toContainEqual([26, 153, 230, 255]);
	await page.evaluate(() => previewSession.dispose());
});

test('balls have clear corners and crisp pixels at fractional positions and fullscreen scale', async ({page}) => {
	await page.setViewportSize({width: 1440, height: 900});
	await loadGame(page);
	const canvas = page.locator('canvas');
	expect(await canvas.evaluate(canvas => canvas.getContext('webgl2').getContextAttributes().antialias)).toBe(false);
	await page.click('#fullscreen');
	await expect.poll(() => page.evaluate(() => !!document.fullscreenElement)).toBe(true);
	await expect(canvas).toHaveCSS('image-rendering', 'pixelated');

	await page.evaluate(async () => {
		const result = await previewSession.php.run(String.raw`<?php
			$engine->userPaused=true;
			$r=$field->registry();
			foreach([\PhpNoid\Component\Brick::class,\PhpNoid\Component\Ball::class,\PhpNoid\Component\Enemy::class] as $class)
				foreach($r->query($class) as $id) $r->destroy($id);
			$r->create(new \PhpNoid\Component\Brick(280,270,160,70));
			$b=new \PhpNoid\Component\Ball(320.375,300.625);
			$r->create($b);
			$r->create(new \PhpNoid\Component\Ball(400.375,300.625,'enemy','cyan'));
			$engine->device->begin([.025,.035,.085,1]);
			glViewport(\PhpNoid\Render\Hud::WIDTH,0,\PhpNoid\Render\Hud::PLAYFIELD_WIDTH,\PhpNoid\Render\Hud::HEIGHT);
			\PhpNoid\Render\Scene::draw(
				$field,$rects,\PhpNoid\Render\Hud::PLAYFIELD_LEFT,\PhpNoid\Render\Hud::PLAYFIELD_WIDTH
			);
			if($b->x!==320.375 || $b->y!==300.625) throw new \RuntimeException('Rendering rounded physics');
			(vrzno_env('canvas'))->setAttribute('data-ball-pixels',vrzno_eval('(()=>{
				const gl=document.querySelector("canvas").getContext("webgl2");
				return JSON.stringify([320,400].map(x=>{
					const p=new Uint8Array(14*14*4);
					gl.readPixels(230+x-8-7,480-301-7,14,14,gl.RGBA,gl.UNSIGNED_BYTE,p);
					return Array.from(p);
				}));
			})()'));
		`
		);

		if(result) throw new Error('Ball pixel probe failed');
	});

	const [player, enemy] = JSON.parse(await canvas.getAttribute('data-ball-pixels'));

	const colors = pixels => [...new Set(
		Array.from({length: pixels.length / 4}, (_, i) => pixels.slice(i * 4, i * 4 + 4).join(','))
	)].sort();

	const background = player.slice(0, 4).join(',');
	expect(colors(player).length).toBeGreaterThanOrEqual(6);
	expect(colors(player)).toContain('255,255,255,255');
	expect(colors(enemy).length).toBeGreaterThanOrEqual(6);
	expect(enemy.slice(0, 4).join(',')).toBe(background);

	// Exact silhouette, including the transparent corners; readPixels rows run bottom to top.
	const silhouette = Array.from(
		{length: 14}
		, (_, y) => Array.from(
			{length: 14}
			, (_, x) => player.slice((y * 14 + x) * 4, (y * 14 + x) * 4 + 4).join(',') === background ? '.' : '#'
		).join('')
	);

	expect(silhouette).toEqual([
		'..............'
		, '..............'
		, '.....####.....'
		, '...########...'
		, '...########...'
		, '..##########..'
		, '..##########..'
		, '..##########..'
		, '..##########..'
		, '...########...'
		, '...########...', '.....####.....', '..............', '..............'
	]);

	// Check the browser compositor too: noninteger fullscreen scaling must not invent edge colors.
	const box = await canvas.boundingBox();
	expect(box.width / 854).not.toBe(Math.round(box.width / 854));

	const shot = await page.screenshot({
		clip: {
			x: box.x + (230 + 313 - 8) * box.width / 854
			, y: box.y + 294 * box.height / 480
			, width: 14 * box.width / 854
			, height: 14 * box.height / 480
		}
	});

	const scaled = await page.evaluate(async data => {
		const image = new Image();
		image.src = data;
		await image.decode();
		const buffer = document.createElement('canvas');
		buffer.width = image.width;
		buffer.height = image.height;
		const context = buffer.getContext('2d');
		context.drawImage(image, 0, 0);

		return Array.from(context.getImageData(0, 0, image.width, image.height).data);
	}, 'data:image/png;base64,' + shot.toString('base64'));

	expect(colors(scaled)).toEqual(colors(player));
	await page.evaluate(() => previewSession.dispose());
});

test('PHP-Noid keeps its background and primitive shader bindings bounded across restoration', async ({page}) => {
	await page.addInitScript(() => {
		window.noidProgramBinds = 0;
		const useProgram = WebGL2RenderingContext.prototype.useProgram;

		WebGL2RenderingContext.prototype.useProgram = function(program) {
			window.noidProgramBinds++;

			return useProgram.call(this, program);
		};
	});

	const errors = [];
	page.on('pageerror', error => errors.push(error.message));
	await loadGame(page);

	const frames = () => page.locator('canvas').evaluate(canvas => JSON.parse(canvas.dataset.phpNoid).frames);
	await expect.poll(frames).toBeGreaterThan(10);
	const bindings = await page.evaluate(() => noidProgramBinds);
	const before = await frames();
	await expect.poll(frames).toBeGreaterThan(before + 20);
	const steadyBindings = await page.evaluate(() => noidProgramBinds);
	expect(bindings).toBeGreaterThan(0);
	// One image program and one rectangle program are required for each rendered frame.
	expect(steadyBindings).toBeGreaterThan(bindings + 20);
	expect(steadyBindings).toBeLessThan(bindings + 120);

	await page.evaluate(() => {
		window.noidLoss = document.querySelector('canvas').getContext('webgl2').getExtension('WEBGL_lose_context');
		noidLoss.loseContext();
	});

	await page.waitForTimeout(200);
	const stopped = await frames();
	await page.waitForTimeout(100);
	expect(await frames()).toBe(stopped);
	await page.evaluate(() => noidLoss.restoreContext());
	await expect.poll(frames).toBeGreaterThan(stopped + 20);
	const restoredBindings = await page.evaluate(() => noidProgramBinds);
	expect(restoredBindings).toBeGreaterThan(steadyBindings);
	const restored = await frames();
	await expect.poll(frames).toBeGreaterThan(restored + 20);
	const rebound = await page.evaluate(() => noidProgramBinds);
	expect(rebound).toBeGreaterThan(restoredBindings + 20);
	expect(rebound).toBeLessThan(restoredBindings + 120);

	// Read immediately after a draw; WebGL's default framebuffer need not survive compositing.
	await page.evaluate(async () => {
		const result = await previewSession.php.run(String.raw`<?php
			glViewport(\PhpNoid\Render\Hud::WIDTH,0,\PhpNoid\Render\Hud::PLAYFIELD_WIDTH,\PhpNoid\Render\Hud::HEIGHT);
			\PhpNoid\Render\Scene::draw(
				$field,$rects,\PhpNoid\Render\Hud::PLAYFIELD_LEFT,\PhpNoid\Render\Hud::PLAYFIELD_WIDTH
			);
			(vrzno_env('canvas'))->setAttribute('data-restored-pixel',vrzno_eval('(()=>{
				const gl=document.querySelector("canvas").getContext("webgl2"),p=new Uint8Array(4);
				gl.readPixels(230+320-8,30,1,1,gl.RGBA,gl.UNSIGNED_BYTE,p);return JSON.stringify(Array.from(p));
			})()'));
		`
		);

		if(result) throw new Error('Restored draw failed');
	});

	const pixel = JSON.parse(await page.locator('canvas').getAttribute('data-restored-pixel'));
	expect(pixel[2]).toBeGreaterThan(200);
	await page.evaluate(() => previewSession.dispose());
	expect(errors).toEqual([]);
});
