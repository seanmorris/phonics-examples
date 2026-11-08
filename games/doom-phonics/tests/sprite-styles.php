<?php
declare(strict_types=1);

use DoomPhonics\Definition\DecorateParser;
use DoomPhonics\Render\GlesMapRenderer;

// A sprite's RenderStyle decides its blend pass: additive styles add light with their black left out, as the Omega
// pack's beam trails are written, `None` draws nothing, and the rest blend by their Alpha.
(static function(): void {
	$actors = DecorateParser::catalog([
		'styles.dec' => <<<'DECORATE'
ACTOR Plain {}
ACTOR Glow
{
  RenderStyle Add
  Alpha 0.5
}
ACTOR Quoted
{
  RenderStyle "Add"
}
ACTOR Ghost
{
  RenderStyle Translucent
  Alpha 0.4
}
ACTOR Unseen
{
  RenderStyle None
}
ACTOR Fuzzy
{
  +SHADOW
  Alpha 0.9
}
ACTOR Hanging
{
  +SPAWNCEILING
  Alpha 0.3
}
DECORATE
	]);
	$style = static fn(string $name): array => GlesMapRenderer::actorStyle($actors->named($name));
	verifyWad($style('Plain') === [1.0, false, GlesMapRenderer::STYLE_NORMAL], 'a plain actor is opaque and normal');
	verifyWad($style('Glow') === [0.5, false, GlesMapRenderer::STYLE_ADDITIVE] && $style('Quoted')[2] === GlesMapRenderer::STYLE_ADDITIVE, 'RenderStyle Add, quoted or not, is additive at its Alpha');
	verifyWad($style('Ghost') === [0.4, false, GlesMapRenderer::STYLE_NORMAL], 'RenderStyle Translucent blends at its Alpha');
	verifyWad($style('Unseen')[2] === GlesMapRenderer::STYLE_HIDDEN, 'RenderStyle None is not drawn');
	verifyWad(abs($style('Fuzzy')[0] - .32) < 1e-9, 'a SHADOW actor is capped at fuzz darkness');
	verifyWad($style('Hanging') === [1.0, true, GlesMapRenderer::STYLE_NORMAL], 'a normal-style Alpha without a translucent style stays opaque, and SPAWNCEILING is carried');
})();
