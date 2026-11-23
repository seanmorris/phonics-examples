# Phonics background music

Add this package to an application's Phonics dependencies. Construct `PhonicsBgm\BackgroundMusic` with the application context. Serve the audio and cover images beside the application archive and use paths relative to the archive URL.

```php
$music = new BackgroundMusic($app);
$music->playlist([
    ['id' => 'open-road', 'src' => 'music/open-road.mp3', 'title' => 'Open Road'],
    ['id' => 'night', 'src' => 'music/night.mp3', 'title' => 'Night Drive'],
], 'driving.music.next.v1');
$music->push(['src' => 'music/finish.wav', 'credit' => false]);
```

The playlist cycles in order. Each successful song start stores the ID of the next song under the supplied browser storage key. Reloading starts there. A missing ID starts at the first song. Playback waits for browser audio permission.

A pushed cue crossfades over the current cue and pauses it after the fade. Near the end of the new cue, the previous one fades back in from its paused position. Push more cues to nest them. Pass `true` as the second argument to loop a cue until `pop()`. Fades last up to 650 milliseconds and shorten for short sounds.

Browser modules can use `session.backgroundMusic`. It exposes `push(track, loop)`, `pop()`, `next()`, `pause(boolean)`, `setVolume(0…1)`, and the current `track`, `depth` and `volume`. On a new track the canvas emits `phonics-music-track` with its credits and resolved cover URL. A cue with `credit: false` and a resumed song both leave the current credit display alone.
