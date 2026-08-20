# Changelog

Fixes land on `main` as they are made and are deployed straight to staging.
They collect under **Unreleased** until there is a coherent set worth shipping,
then `bin/release.sh` cuts one release for the lot.

A release is what arms the GitHub updater. Staging does not need one — it is
deployed by zip — so there is no reason to cut a release per fix.

## Unreleased

### Changed
- The quiz's bone panel is painted on the static stage instead of on each
  slide, so a transition no longer drags a rounded box across the viewport —
  only the content moves, and the stage's rounded corners clip it.
- The result form reads as Paper draws it: field names sit inside the fields as
  placeholders and the labels are visually hidden (kept in the DOM and
  associated, because a placeholder is a weak accessible name that disappears
  as soon as someone types). Tighter rhythm between fields, and between the
  submit and "Also book a call".

### Fixed
- Suppressed the platform tap-highlight flash on the finder. iOS and Android
  paint it on whichever element receives the tap, so it is set on the root and
  on labels, buttons, links and inputs.

### Changed
- Asset cache-busting now uses each file's modification time instead of the
  plugin version. Previously `VERSION` was the only thing busting the browser
  cache on staging (where `WP_DEBUG` is off), so every deploy needed a version
  bump just to see the change — which made every fix look like it wanted its
  own release. `VERSION` now means one thing: the released version.

## 0.3.2 — 2026-08-20

### Added
- Drag-to-reorder for the question cards and the tie-break list, with grip
  handles on the left. The handles are real buttons and answer the arrow keys,
  so the lists stay reorderable from the keyboard.

### Fixed
- Test-drive answers are pinned to question identity rather than position, so
  reordering questions no longer re-assigns them to the wrong question.

## 0.2.7 — 2026-08-20

### Added
- Tie-break order is editable: the chip opens a popover with a vertical,
  reorderable list. The posted order is the DOM order of the rows.

### Changed
- Matrix column heads use short names (Bean, Trad., Single, Liquid) so four
  columns fit beside the answer text. The front end keeps the full names.

### Fixed
- Chip dots had never rendered — the markup asked for `.dcwa__dot`, which has
  no CSS rule. They use `.dcwa-dot` now.
- Reset on the test drive rebuilt the fields and then restored the very answers
  it was meant to clear.

## 0.2.4 — 2026-08-20

### Changed
- Question cards sit on a 12px gap, and a card's title is a plain heading until
  the card is open. Clicking a closed card opens it; clicking an open card's
  header closes it.

### Fixed
- wp-admin's `input[type="text"]` (specificity 0,1,1) was overriding the title
  styling and clamping its height via `min-height: 40px`.

## 0.2.2 — 2026-08-20

### Fixed
- All four result panels rendered stacked after completing the finder.
  `.dcw-result { display: flex }` is an author rule and outranks the user
  agent's `[hidden] { display: none }`, so hiding the losers did nothing.

## 0.2.0–0.2.1 — 2026-08-20

### Added
- The Finder Admin screen: question cards with scoring matrices, a test-drive
  rail that scores unsaved edits, and a settings view.

## 0.1.0–0.1.3 — 2026-08-19

### Added
- First working plugin: the finder element, scorer, renderer, Fluent Forms
  hand-off and the GitHub updater.
