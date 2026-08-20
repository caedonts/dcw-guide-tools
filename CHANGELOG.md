# Changelog

Fixes land on `main` as they are made and are deployed straight to staging.
They collect under **Unreleased** until there is a coherent set worth shipping,
then `bin/release.sh` cuts one release for the lot.

A release is what arms the GitHub updater. Staging does not need one — it is
deployed by zip — so there is no reason to cut a release per fix.

## Unreleased

## 0.4.1 — 2026-08-20

### Added
- A "Press Enter to lock in your answer" hint that fades in after a keyboard
  selection, in the spot the old static hint used to occupy. It is
  `aria-hidden`: screen readers already hear the same sentence from the live
  region, so this is purely the cue sighted keyboard users were missing. Reads
  "Press Enter to see your result" on the last question, and clears whenever
  the card moves.

### Fixed
- Field rows in the result form were 24px apart instead of 12. Each name cell
  wraps its input in its own nested `.ff-el-group`, and the rhythm rule was
  hitting those too — so the row measured 12px taller than the inputs and the
  visible gap came out as 12 + 12, matching the deliberate gap between the
  field group and the CTAs and flattening the grouping.
- The question title no longer draws a focus ring. `goTo()` focuses it after
  every slide change for screen readers, but it is `tabindex="-1"` and cannot
  be navigated to, and an outline on a multi-line inline element traces each
  line box separately — which is where the stepped, notched shape came from.


## 0.4.0 — 2026-08-20

### Added
- Keyboard users choose and commit separately. In a native radio group the
  arrow keys select as they move, so auto-advancing on selection made it
  impossible to read the second option without committing to it (WCAG 3.2.2 On
  Input). Pointer input still commits by choosing; keyboard selection records
  the answer and waits for Enter, including on the last question where
  committing means revealing the result. A screen-reader-only line in each
  question says so, and the live region confirms each selection.

### Changed
- Asset cache-busting uses each file's modification time instead of the plugin
  version. `VERSION` was the only thing busting the browser cache on staging
  (where `WP_DEBUG` is off), so every deploy needed a version bump just to see
  the change — which made every fix look like it wanted its own release.
  `VERSION` now means one thing: the released version.
- The quiz's bone panel is painted on the static stage instead of on each
  slide, so a transition no longer drags a rounded box across the viewport —
  only the content moves, and the stage's rounded corners clip it.
- The result form reads as Paper draws it: field names sit inside the fields as
  placeholders and the labels are visually hidden (kept in the DOM and
  associated, because a placeholder is a weak accessible name that disappears
  as soon as someone types). The fields and the two CTAs each sit on a 12px
  rhythm with a 24px break between the groups.

### Removed
- The per-question hint line ("Answering moves you to the next question." /
  "This one filters the options rather than scoring them.").

### Fixed
- The platform tap-highlight flash on the finder. iOS and Android paint it on
  whichever element receives the tap, so it is suppressed on the root and on
  labels, buttons, links and inputs.
- The next slide could peek through the right edge of the quiz panel:
  `overflow: hidden` clips at the padding box, not the content box, so padding
  on the clipping element widened the window.
- Gaps between the result form's fields. A Fluent Forms name row is
  `.ff-field_container`, not `.ff-el-group`, so the spacing rule never reached
  it — and the gap that had been there was the visible labels' own height.

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
