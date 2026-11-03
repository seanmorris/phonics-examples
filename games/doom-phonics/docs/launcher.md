# WAD stacks and the launcher

The launcher presents one ordered resource stack, the world's **WAD load order** slot. It accepts only `.wad` archives. The first archive must be an IWAD and supplies the base game identity; later IWADs and PWADs contribute resources using last-file-wins lookup, including any DECORATE, KEYCONF, SNDINFO, and music lumps they contain. Loose definition files such as `SKULLTAG_ACTORS.lmp`, `SKULLTAG-WEAPONS.DEC`, and its SNDINFO, along with the music, weapon, and menu-font WADs, are declared by the world manifest with `authority: "world"` and are not shown or editable in the launcher. The effective map list is derived from the complete stack.

The launcher is available only in worlds that declare a `network` section, such as the [online episode world](../demo/episode-one-online.phonics-world.json) used by the documentation page. Those worlds show **WAD Stack** and **Online Play** on the title menu; the other demonstration worlds start their configured map from **New Game** and hide both entries. Weapon slots retain the existing `Player.WeaponSlot` and `Weapon.SlotNumber` assignments, then append KEYCONF entries while keeping each weapon name only at its first assignment.

Drag files into the launcher, choose a map and game mode, then start the session. A browser export must include every declared browser module and resource required by that world. Locally selected archives can remain local: online setup exchanges safe names, roles, sizes, digests, ordering, and the selected map rather than file bytes or acquisition URLs.

DOOM1 and DOOM2 are different base games and should not be stacked as interchangeable IWADs. Add-ons such as Skulltag belong after the selected base IWAD.

World authors can preconfigure a Doom session with the top-level `init` object:

```json
{
  "init": {
    "startMap": "MAP04",
    "cheats": ["iddqd", "idkfa"]
  }
}
```

`startMap` selects the initial map and the New Game destination. `cheats` accepts one string or an ordered list of classic typed cheat sequences and is applied after the new player has spawned. Launcher map selection still controls locally selected and negotiated matches.

The documentation overview mounts the runtime in place only after the reader selects the gameplay poster. Fullscreen remains available from the game controls without leaving the manual or its navigation.
