# Phonics Examples

Independent games built with [Phonics](https://github.com/seanmorris/php-wasm-engine). Each game owns the resources and engine conventions it actually needs. The repository can publish a full-page experience and a minimal iframe surface for every example.

The first game is **PHP Billiards**, a deterministic 3D pool practice table. It is a direct executable application: it does not load a Tiled world or Doom/WAD content.

```sh
npm install
npm test
npm run build
npm run serve
```

The build writes the GitHub Pages artifact to `site/dist`. Open `/games/php-billiards/` for the full page or `/embed/php-billiards/` for the iframe surface.

