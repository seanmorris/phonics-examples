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
ACTOR Smoke
{
  RenderStyle Shaded
  StencilColor "A0 A0 A0"
  Alpha 0.8
}
ACTOR NamedSmoke
{
  RenderStyle Shaded
  StencilColor "White"
}
DECORATE
	]);
	$style = static fn(string $name): array => GlesMapRenderer::actorStyle($actors->named($name));
	verifyWad($style('Plain') === [1.0, false, GlesMapRenderer::STYLE_NORMAL, 0], 'a plain actor is opaque and normal');
	verifyWad($style('Glow') === [0.5, false, GlesMapRenderer::STYLE_ADDITIVE, 0] && $style('Quoted')[2] === GlesMapRenderer::STYLE_ADDITIVE, 'RenderStyle Add, quoted or not, is additive at its Alpha');
	verifyWad($style('Ghost') === [0.4, false, GlesMapRenderer::STYLE_NORMAL, 0], 'RenderStyle Translucent blends at its Alpha');
	verifyWad($style('Unseen')[2] === GlesMapRenderer::STYLE_HIDDEN, 'RenderStyle None is not drawn');
	verifyWad(abs($style('Fuzzy')[0] - .32) < 1e-9, 'a SHADOW actor is capped at fuzz darkness');
	verifyWad($style('Hanging') === [1.0, true, GlesMapRenderer::STYLE_NORMAL, 0], 'a normal-style Alpha without a translucent style stays opaque, and SPAWNCEILING is carried');
	verifyWad($style('Smoke') === [0.8, false, GlesMapRenderer::STYLE_SHADED, 0xa0a0a0], 'RenderStyle Shaded carries its Alpha and the stencil colour from hex pairs');
	verifyWad($style('NamedSmoke') === [1.0, false, GlesMapRenderer::STYLE_SHADED, 0xffffff], 'a named stencil colour is understood');
	verifyWad(GlesMapRenderer::stencilColor('"ff8000"') === 0xff8000 && GlesMapRenderer::stencilColor(null) === 0, 'stencil colours parse without spaces and default to black');
})();
