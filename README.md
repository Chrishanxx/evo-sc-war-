# EvoSC WarManager

Current WarManager version: **0.14.0**. Website transport has moved into the separate **WarApiBridge 0.1.0** module. WarManager now performs only game, scoring, rotation, UI and local database work; it never waits for an external HTTP request.

External WarManager module for the classic PHP release of EvoSC and Trackmania 2020.
It keeps `Trackmania/TM_TimeAttack_Online.Script.txt` untouched and calculates a
long-running two-team competition from records driven during the active war.

## Optional lag-safe website snapshot sync

WarApiBridge publishes read-only JSON snapshots of the existing WarManager tables to an HTTPS endpoint. WarManager
only emits an in-memory change signal. On its own timer, WarApiBridge creates a deduplicated database outbox entry and advances its cURL
multi request in short, non-blocking timer ticks. It never waits in the WarManager timer, map hooks, scoring hooks or
player commands. Failed deliveries remain in the outbox and use capped exponential retry delays. Since every payload
is a complete snapshot, obsolete unsent entries are collapsed automatically.

Copy both `WarManager/` and `WarApiBridge/` into EvoSC, run migrations, and configure
`WarApiBridge/war-api-bridge.config.json`:

```json
{
  "enabled": true,
  "endpoint": "https://www.scrimwarhub.dedyn.io/api/war-snapshot",
  "secret": "replace-with-a-long-random-secret",
  "history-limit": 20,
  "queue-limit": 20,
  "retry-base-seconds": 5,
  "request-timeout-ms": 5000,
  "force-ipv4": true
}
```

For deployments that support environment variables, prefer `WAR_MANAGER_SYNC_SECRET` and leave `secret` empty in
the JSON file. The receiving endpoint must verify the `X-War-Signature: sha256=<hex>` header against the raw body.
Only HTTPS endpoints are accepted. Records, players, maps and up to 50 recent wars are included in each snapshot.
The bridge requires PHP's cURL extension with `curl_multi` support. `force-ipv4` avoids broken IPv6 routes on game
server hosts while leaving the public website domain unchanged.

## Compatibility

- EvoSC `0.99.103` (classic PHP controller)
- PHP `7.4`
- MySQL/MariaDB through EvoSC's Illuminate database layer
- `LocalRecords` and `QuickButtons` modules enabled

This repository intentionally does **not** target EvoSC# (.NET 8). The APIs and
module layout of EvoSC# are incompatible with the PHP module described here.

## Install

Copy `WarManager/` and `WarApiBridge/` to `<evosc>/core/Modules/`, run EvoSC migrations, enable both modules,
then restart EvoSC. The normal TimeAttack mode remains active. Existing `website-sync` settings from
`WarManager/war-manager.config.json` must be moved to `WarApiBridge/war-api-bridge.config.json`.

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
- First-time player team joining during draft or an active war, persisted per war and Trackmania login
- Native `/setname` integration: choosing a team prepends its tag to the visible nickname
- Original nicknames stored per war; duplicate tags are removed before the selected tag is applied
- Database assignment only after EvoSC confirms the visible nickname change
- Compact HUD uses EvoSC's native widget base, including UI alignment and hide-while-driving settings
- Optional team limits and a permanent team lock after confirmation
- Admin reset action and automatic promotion of pending records after joining
- `/war join TEAM` as a chat alternative to the confirmation overlay
- Full `//war admin` control panel with Overview, Create/Settings, Maps, Points, Players and Logs tabs
- Confirmed lifecycle actions and pause-aware war timing
- EvoSC access rights and a QuickButtons entry
- Audit log for state and configuration changes
- Native gamepad and keyboard navigation in the player, team, map and protected admin windows
- D-pad or stick navigation, A/Enter selection, B/Escape back and LB/RB tab switching
- Stable per-player focus restoration across live overlay refreshes and safe confirmation defaults

WarManager deliberately keeps the server on `Trackmania/TM_TimeAttack_Online.Script.txt` and never introduces
a separate Trackmania ModeScript. Players may join either team only while the war is `ACTIVE`. Once stored,
their login-to-team assignment is authoritative until an administrator uses `RESET TEAM`; records driven before
joining remain pending and are promoted after the confirmed assignment.

Version 0.11 makes `TM_War_Online` rotation enforcement mandatory. The database remains the source of truth for
the ordered scrim map pool, and every selected UID must exist in EvoSC's server map table. Starting a war first
saves the complete current MatchSettings through the Dedicated Server, persists its ordered UID list, generates
`MatchSettings/WarManager/war_<ID>.txt`, and loads that fixed TimeAttack playlist automatically. Active and paused
wars continuously verify both the selection and current/next map. Foreign maps are corrected and never scored.
Finish, cancel and expiry restore the saved MatchSettings; module/server restarts reconcile unfinished activation
or restore work from `war-rotation-backups`.

Public statistics and administration are deliberately separate. The player window contains no
management actions; `//war admin` opens the protected configuration and lifecycle controls.

## Controller and console operation

Open the player window with `/war`. An unassigned player can also use `/war join TEAM`; after the
nickname/team operation succeeds, the player window opens with the confirmed assignment. Administrators
open the protected window with `//war admin`.

- D-pad or left stick: move the visible focus
- A or Enter: activate the focused button or edit the focused field
- B or Escape: cancel a dialog, return to the parent page, or close the window
- LB/RB or Page Up/Page Down: move between top-level tabs

Disabled pagination and unavailable team actions are not focusable. Focused controls receive the yellow
WarManager highlight, and page changes or live data refreshes restore the last valid focus for that player.
The compact live score widget intentionally remains non-interactive so it never captures driving input.
This UI support does not change TimeAttack, scoring, team locks, map rotation or the Dedicated Server script.

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
