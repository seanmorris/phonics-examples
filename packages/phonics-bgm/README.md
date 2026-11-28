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

Only the current song streams at startup. During its last 20 seconds the player asks the browser to buffer one successor and reuses that audio element at handoff. For a short song the lead time is capped at one third of its duration. A jingle, a pause or a hidden tab cancels an unfinished preload. A fully buffered successor can stay queued. Preparing a stream never advances the rotation or displays its credits.

A pushed cue crossfades over the current cue and pauses it after the fade. Near the end of the new cue, the previous one fades back in from its paused position. Push more cues to nest them. Pass `true` as the second argument to loop a cue until `pop()`. Fades last up to 650 milliseconds and shorten for short sounds.

Browser modules can use `session.backgroundMusic`. Both `next()` and `previous()` wrap at the ends and return the selected song's credits, or `null` for an empty playlist. A skip during a jingle selects the song that follows it. `setPlaybackPaused(boolean)` controls the listener's pause and `playbackPaused` reports it. `pause(boolean)` handles the game's independent pause. Playback resumes from its parked position when both pauses clear.

Use `tracks` to populate a song picker and `select(id)` to go directly to a song. `playlistTrack` gives the selected song even while a jingle plays. A game-wide volume control can call `setMasterVolume(0…1)`. The player multiplies that gain by the listener's saved music volume. Listen for `phonics-music-controls` on the canvas to keep multiple volume and playback controls in sync.

The service also exposes `push(track, loop)`, `pop()`, `setVolume(0…1)` and the current `track`, `depth` and `volume`. On a new track the canvas emits `phonics-music-track` with its credits and resolved cover URL. A cue with `credit: false` and a resumed song both leave the current credit display alone.
