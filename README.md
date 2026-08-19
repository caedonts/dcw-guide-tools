# DCW Guide Tools

Equipment finder for the [Dependable Coffee &amp; Water](https://dependablecoffeewater.com) buying guides.

A visitor answers a short series of questions and the plugin recommends a
system category, shows the equipment behind it, and offers an emailed report
via Fluent Forms.

## Placing it

Either:

- **Bricks** — add the **DCW Finder** element (element panel → *Dependable*), then
  pick a product line.
- **Shortcode** — `[dcw_finder finder="coffee"]`

Settings live at **Settings → DCW Guide Tools**.

## How it is built

- **Everything renders server-side.** All questions and every result panel are
  in the HTML on first paint; JavaScript only reveals and animates. That keeps
  the guide readable by search engines and LLMs without running the quiz, and
  it means the finder degrades to a long-but-complete page when JS fails.
- **Scoring is duplicated on purpose.** `includes/class-scorer.php` and
  `assets/finder.js` implement the same algorithm and read the same config, so
  the browser can answer instantly while PHP stays authoritative server-side.
- **Multi-category by construction.** `includes/class-config.php` defines one
  *finder* per product line. Coffee ships with real questions and scoring;
  water and ice are empty shells that light up as soon as they are given
  categories and questions. Nothing about coffee is special-cased.

## Configuration

Questions, answers, per-category points and result copy live in
`includes/class-config.php`. Two ways to change them without editing the file:

```php
// Adjust anything about a finder.
add_filter( 'dcw_guide_tools_finders', function ( $finders ) {
	$finders['coffee']['also_consider_window'] = 3;

	return $finders;
} );
```

…or the settings screen, which exposes the form, the "also consider" window
and the three result links.

The coffee scoring matrix is the draft from `Notes/Coffee Finder Logic.md` and
is expected to be corrected by the team — every number is editable.

## The gate

The last coffee question filters rather than scores. Answering "no water line"
removes the categories that require plumbing and attaches a note to the result.
If a gate ever eliminated every category, the engine falls back to the full
ranking and shows the note — a recommendation with a caveat beats a dead end.

## Lead capture

The result panel embeds a Fluent Form. If the form contains any of these
hidden fields, the quiz run is attached to the submission:

| Field name | Contains |
| --- | --- |
| `dcw_result` | Winning category slug |
| `dcw_result_label` | Winning category name |
| `dcw_also` | Runner-up name, if one was shown |
| `dcw_answers` | Readable list of question/answer pairs |
| `dcw_scores` | Raw scores, e.g. `bean-to-cup=9,single-cup=5` |

A form without them still submits normally.

## Updates

The plugin updates from GitHub releases. To ship a new version:

1. Bump `Version:` in `dcw-guide-tools.php` **and** the `VERSION` constant.
2. Commit and push.
3. Create a GitHub **release** (not just a tag) named `v0.2.0` or similar.

WordPress then offers the update in the normal Plugins screen within six hours,
or immediately after "Check again".

**Forgetting step 1 is the classic failure** — the release exists but no update
ever appears, because the installed version header never changed.

Editing plugin files directly on the server is overwritten by the next update.

## Requirements

- WordPress 6.4+
- PHP 8.0+
- Bricks (for the element; the shortcode works without it)
- Fluent Forms (for the report form; the finder works without it)
