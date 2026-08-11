# EvoSC WarManager

External WarManager module for the classic PHP release of EvoSC and Trackmania 2020.
It keeps `Trackmania/TM_TimeAttack_Online.Script.txt` untouched and calculates a
long-running two-team competition from records driven during the active war.

## Compatibility

- EvoSC `0.99.103` (classic PHP controller)
- PHP `7.4`
- MySQL/MariaDB through EvoSC's Illuminate database layer
- `LocalRecords` and `QuickButtons` modules enabled

This repository intentionally does **not** target EvoSC# (.NET 8). The APIs and
module layout of EvoSC# are incompatible with the PHP module described here.

## Install

Copy `WarManager/` to `<evosc>/core/Modules/WarManager`, run EvoSC migrations, enable the module,
then restart EvoSC. The normal TimeAttack mode remains active.

Assign the registered `war_manage`, `war_start`, `war_maps`,
`war_points` and `war_players` rights through EvoSC's Group Manager. The
unrestricted MasterAdmin group receives access automatically.

## V1 scope

- One current war, with persisted history (`DRAFT`, `ACTIVE`, `PAUSED`,
  `FINISHED`, `CANCELLED`)
- Duration validation from 1 through 14 days and exact UTC start/end timestamps
- Case-insensitive nickname tag detection (`FAST Name`, `[FAST] Name`,
  `FAST | Name`, `FAST.Name`)
- Stable identification by Trackmania login and team lock on first scored record
- Frozen map pool and point profile after start
- War-only record snapshots; existing local records are never imported
- Complete per-map reranking after every improvement; one best time per player
- Persistent player, team, map and overall scores; finished results cannot drift
- Player commands `/war`, `/war maps`, `/war me`
- Admin lifecycle commands via `//war ...`
- Compact EvoSC-style live widget for all players with team totals and a `WAR STATS` button
- Public War Stats window with Overview, paginated Players and paginated Maps tabs
- Direct player team joining through War Stats, persisted per war and Trackmania login
- Native `/setname` integration: choosing a team prepends its tag to the visible nickname
- Original nicknames stored per war; duplicate tags are removed during draft team changes
- Database assignment only after EvoSC confirms the visible nickname change
- Compact HUD uses EvoSC's native widget base, including UI alignment and hide-while-driving settings
- Optional team limits and confirmed team switching (disabled by default)
- Admin move/reset actions and pending War-record promotion after joining
- Full `//war admin` control panel with Overview, Create/Settings, Maps, Points, Players and Logs tabs
- Confirmed lifecycle actions and pause-aware war timing
- EvoSC access rights and a QuickButtons entry
- Audit log for state and configuration changes

Public statistics and administration are deliberately separate. The player window contains no
management actions; `//war admin` opens the protected configuration and lifecycle controls.

## Development checks

Pull requests run PHP 7.4 syntax checks and domain tests through GitHub Actions.
For a local run, install Composer dependencies and execute `composer test`.

## Admin commands

```text
//war create <durationDays> <teamA> <teamB> [name]
//war admin
//war teams <teamA> <teamB> [name]
//war start
//war pause
//war resume
//war finish
//war cancel
//war status
//war map add <MapUID> [Map Name]
//war map remove <MapUID>
//war points <rank> <points>
```

The admin overlay uses the same `WarRepository` operations as the chat commands. It can create and configure a draft,
manage the map pool and 16-rank point profile, inspect team detection and audit logs, and start, pause, resume, finish
or cancel a war with confirmation. Map and point changes remain locked after the first start to protect scoring integrity.

Team joining is restricted to the two teams configured for the current war. An explicit stored assignment takes priority
over nickname tag detection. Records driven on selected War maps while a player is still unassigned are held in the
WarManager pending table and promoted immediately after the player joins a team; unrelated historic server records are
not imported.

## Design notes

The `PlayerLocalRecord` hook is only the trigger. The module stores the supplied
finish time in its own snapshot table and recalculates the entire selected map.
Consequently an improvement moves all affected players to their new rank instead
of adding duplicate points. On finish, all rows remain immutable.
