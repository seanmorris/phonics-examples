# Phonics Examples

Independent games built with [Phonics](https://github.com/seanmorris/php-wasm-engine). Each game owns the resources and engine conventions it actually needs. The repository publishes a full-page experience and a minimal iframe surface for every example.

The catalog contains Sane-Phonics, PHP-Noid, PHPTris, PHP Slalom, PHP Buster, Doom-Phonics, and **PHP Billiards**. Billiards is a direct executable application and does not load a Tiled world or Doom/WAD content. Tiled worlds and WADs remain resources of the games that need them rather than requirements of the examples framework.

```sh
npm install
npm test
npm run build
npm run serve
```

Each game lives under `games/<slug>/`. Its `example.json` declares the application, canvas, poster, documentation chapters, and embed mode. `npm run build` generates assets, packs every application, and writes the GitHub Pages artifact to `site/dist`. Open `/games/<slug>/` for a full page or `/embed/<slug>/` for the iframe surface.

The main Phonics documentation build imports these chapter files and API metadata directly. Set `PHONICS_EXAMPLES_ROOT` to this checkout when developing both repositories together; CI can fetch the repository and ref declared by the engine's `site/examples-source.json`.
