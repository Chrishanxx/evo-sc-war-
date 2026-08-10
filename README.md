# EvoSC Scrim / War

External scrim module for the classic PHP release of EvoSC and Trackmania 2020.
It keeps `Trackmania/TM_TimeAttack_Online.Script.txt` untouched and calculates a
long-running two-team competition from records driven during the active scrim.

## Compatibility

- EvoSC `0.99.103` (classic PHP controller)
- PHP `7.4`
- MySQL/MariaDB through EvoSC's Illuminate database layer
- `LocalRecords` and `QuickButtons` modules enabled

This repository intentionally does **not** target EvoSC# (.NET 8). The APIs and
module layout of EvoSC# are incompatible with the PHP module described here.

## Install

Copy `Scrim/` to `<evosc>/modules/Scrim`, run EvoSC migrations, enable the module,
then restart EvoSC. The normal TimeAttack mode remains active.

Assign the registered `scrim_manage`, `scrim_start`, `scrim_maps`,
`scrim_points` and `scrim_players` rights through EvoSC's Group Manager. The
unrestricted MasterAdmin group receives access automatically.

## V1 scope

- One current scrim, with persisted history (`DRAFT`, `ACTIVE`, `PAUSED`,
  `FINISHED`, `CANCELLED`)
- Duration validation from 1 through 14 days and exact UTC start/end timestamps
- Case-insensitive nickname tag detection (`FAST Name`, `[FAST] Name`,
  `FAST | Name`, `FAST.Name`)
- Stable identification by Trackmania login and team lock on first scored record
- Frozen map pool and point profile after start
- Scrim-only record snapshots; existing local records are never imported
- Complete per-map reranking after every improvement; one best time per player
- Persistent player, team, map and overall scores; finished results cannot drift
- Player commands `/scrim`, `/score`, `/scrim maps`, `/scrim me`
- Admin lifecycle commands via `//scrim ...`
- EvoSC access rights and a QuickButtons entry
- Audit log for state and configuration changes

The first implementation focuses on the safe scoring and persistence core. The
large admin wizard, detailed history browser, map picker and polished multi-tab
ManiaLink are follow-up UI work; command handlers provide the same core controls.

## Development checks

Pull requests run PHP 7.4 syntax checks and domain tests through GitHub Actions.
For a local run, install Composer dependencies and execute `composer test`.

## Admin commands

```text
//scrim create <durationDays> <teamA> <teamB> [name]
//scrim start
//scrim pause
//scrim resume
//scrim finish
//scrim cancel
//scrim status
//scrim map add <MapUID> [Map Name]
//scrim map remove <MapUID>
//scrim points <rank> <points>
```

## Design notes

The `PlayerLocalRecord` hook is only the trigger. The module stores the supplied
finish time in its own snapshot table and recalculates the entire selected map.
Consequently an improvement moves all affected players to their new rank instead
of adding duplicate points. On finish, all rows remain immutable.
