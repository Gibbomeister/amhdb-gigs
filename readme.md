# AMHDB Gigs

Gig lists from the [Australian Music Database](https://australianmusicdatabase.com) (AMHDB), for australianmusichistory.com (AMH). It replaces GigPress.

The `[amhdb_gigs]` shortcode fetches a list from AMHDB's gigs API on the server and renders it into the page as plain HTML. Search engines see the gigs; there's no iframe and no client-side rendering. Tracked in AMHDB issue #424 (overview #427). The API is documented in AMHDB's `docs/gigs-api.md`.

Requires WordPress 6.4+ and PHP 8.2+.

## Usage

```
[amhdb_gigs artist="bandanna" menu="yes"]
[amhdb_gigs venue="bondi-lifesaver" sort="desc" limit="20"]
[amhdb_gigs tag="rick-price-heaven-knows-tour-1992-93"]
[amhdb_gigs year="1979" month="3" group_artists="yes"]
[amhdb_gigs this_week="yes" per_day="2" random="yes" limit="10"]
```

Slugs are the ones in AMHDB's URLs: `australianmusicdatabase.com/bands/bandanna` → `bandanna`.

| Attribute | |
|---|---|
| `artist` | An AMHDB band or person slug. Tries a band first, then a person. |
| `band`, `person` | Use one of these when you know which it is (saves a request). |
| `venue` | AMHDB venue slug. |
| `tag` | AMHDB tag slug: tours. |
| `year`, `month` | `month` needs `year`. Gigs whose year (or month) is unknown are left out. |
| `from`, `to` | `YYYY-MM-DD`, inclusive. |
| `this_week` | `yes`: today and the next six days, in any year, worked out in the site's timezone on every render: "This week in history". Only gigs with a full date. Combines with the other filters (`artist="bandanna" this_week="yes"`). |
| `days` | With `this_week`: how many days, default 7. |
| `sort` | `asc` (default) or `desc`. Year-unknown gigs always come last. |
| `limit` | Show the first N, with a "See all N gigs in the Australian Music Database" link. |
| `per_day` | At most N gigs for each day of the year, so one busy date can't fill a short list. The oldest N, unless `random="yes"`. Applied before `limit`. |
| `random` | With `this_week` and `per_day`: `yes` picks each day's gigs at random. The pick changes daily and stays put all day. |
| `group_artists` | `yes`: a heading per artist. `no` (default): year and month headings. |
| `menu` | `yes`: a jump menu of anchor links to each year and month (or artist). |
| `menu_count` | `yes`: gig counts in the jump menu. |
| `heading` | Level of the top headings, `h2`–`h5`. Default `h3`; sub-headings are one level down. |

Filters combine with AND, and at least one is needed. Everything AMHDB has for the filter is shown on one page, including gigs added later.

## Facts panel

```
[amhdb_facts]
[amhdb_facts part="when"]
```

`[amhdb_facts]` fills the facts panel on AMH story pages from AMHDB's facts API (AMHDB `docs/facts-api.md`), so facts are entered once, in AMHDB (#449). It outputs the markup the Post 2026 template already styles: `div.amh-card > p.amh-card-row > span.amh-card-label + span.amh-card-value`, then `p.amh-card-db > a` for the "In the Australian Music Database" button. `[amhdb_facts part="when"]` outputs the amber date tile, `<p class="amh-when"><span>…</span></p>`, or nothing.

- **Which record:** a `band`, `person` or `venue` attribute; else a post field `amhdb_band`, `amhdb_person` or `amhdb_venue` (a slug); else the AMHDB record whose `amh_url` is this post.
- **Rows from AMHDB:** Members ("Andy Cichon, vocals and guitar (1991–1994)": instruments, or AMHDB's details when none are tagged), Recordings ("Live With It, EP, 1991"), Label, Charted ("Live With It, No. 42 (1991)"), Source, and "Gigs in the Database: 31 (1991 to 1992)" (`gigs="no"` hides it). A person gets Bands and When (born to died). A venue gets Where (suburb, state).
- **Overrides:** a filled `amh_when`, `amh_where`, `amh_members`, `amh_recordings`, `amh_label`, `amh_charted`, `amh_source` or `amh_db_url` post field replaces that row, for story-specific wording. Line breaks become `<br>`. AMHDB has no years active or home town for a band, so When and Where for a band come only from the post fields.
- **Nothing known:** with no AMHDB record and no fields, it outputs nothing, and the template hides the empty panel. With no record but some fields, the fields still show.
- **Caching:** like gig lists: a day fresh, then the last-good copy while WP-Cron refreshes. A post AMHDB doesn't know is remembered for a day, so it isn't asked about on every view. **Clear cache** clears panels too.

## What a list shows

- Year headings, and month headings that carry the year ("March 1985"), with "Year unknown" and "Month unknown, 1985" last; or artist headings.
- Each gig as a date tile ("Fri / 1 / Feb", with the year when no heading carries it). A one-gig list reads "Thursday 9 May 1991".
- Dates follow AMHDB's precision fields: an unknown year shows as "year unknown", a weekday only appears when the full date is known, and nothing is guessed.
- Multi-day gigs as a range.
- On an act's list, the other acts on the bill ("with The Hitmen"); on other lists, every act.
- The venue, suburb and state ("Bondi Lifesaver, Bondi Junction, NSW").
- The act's notes, and a "Source: …" line where AMHDB has one.
- Act and venue names link to their AMH post where AMHDB knows one, otherwise to AMHDB. There's no link back to the page the list is on.
- With no gigs: "No gigs recorded yet."
- A `this_week` list has year headings only (oldest year first, or newest with `sort="desc"`), no "See all" link (AMHDB has no page for it), and "No gigs recorded for this week yet." when empty.

## Markup contract

AMH's theme styles against these class names, so treat them as public. Change them only in a major version.

```
div.amhdb-gigs-list
  nav.amhdb-gigs__menu            (menu="yes")
  h3.amhdb-gigs__year | h3.amhdb-gigs__artist
  h4.amhdb-gigs__month
  ul.amhdb-gigs
    li.amhdb-gig
      time.amhdb-gig__date[datetime]
        span.amhdb-gig__dow  .amhdb-gig__day  .amhdb-gig__month  .amhdb-gig__year  .amhdb-gig__unknown
      span.amhdb-gig__dates          (a range: wraps time.amhdb-gig__date and time.amhdb-gig__end)
      div.amhdb-gig__body
        span.amhdb-gig__act
        span.amhdb-gig__venue
        p.amhdb-gig__note
        p.amhdb-gig__source
  p.amhdb-gigs__see-all
  p.amhdb-gigs__empty | p.amhdb-gigs__unavailable
```

The stylesheet (`assets/amhdb-gigs.css`, loaded only on pages with the shortcode) does layout only. It sets no fonts and no colours; borders and focus rings use `currentColor`. Override these on `.amhdb-gigs-list`:

| Property | Default | |
|---|---|---|
| `--amhdb-gig-gap` | `1em` | Space between gigs, and between tile and details |
| `--amhdb-gig-tile-size` | `4.5em` | Width of the date tile |
| `--amhdb-gig-border` | `1px solid currentColor` | The tile's border |

## Caching and outages

- Each distinct list is cached for a day, plus a last-good copy that's kept until a newer one replaces it.
- After the day is up, visitors get the last-good copy straight away and a background refresh (WP-Cron) fetches a new one. Refreshes send an ETag, so an unchanged list costs AMHDB a 304.
- Only a list's very first view waits on AMHDB, with a 5-second timeout.
- If AMHDB is down, the last-good copy is shown, and refreshes back off for 15 minutes between attempts.
- A `this_week` list is a new list each day: its cache key includes the start date, and its last-good copy is only used on the same day (an older one would show the wrong week). Just after midnight site time, WP-Cron swaps each one for the new day's and fetches it.
- With no copy at all, the list shows "This gig list is on the Australian Music Database", linking to the band, person or venue page.

**Tools → AMHDB Gigs** lists every cached list with when it was fetched, and has **Refresh all now** and **Clear cache** buttons. The same from WP-CLI:

```
wp amhdb-gigs refresh
wp amhdb-gigs clear
```

To point a test site at another AMHDB, define the endpoint in `wp-config.php`:

```php
define( 'AMHDB_GIGS_API', 'http://localhost:3000/api/v1/gigs' );
```

## Development

```
composer install
vendor/bin/phpunit
```

The unit tests fake the WordPress functions the plugin uses (`tests/bootstrap.php`) and cover the renderer, the API client and cache, and the shortcode. Behaviour inside real WordPress is checked by hand with `TESTING.md`.

## Releasing

Bump `Version:` in the plugin header and `AMHDB_GIGS_VERSION`, then:

```
git tag v1.0.1 && git push origin v1.0.1
```

The release workflow checks the tag matches both, runs the tests, builds `amhdb-gigs.zip` (without tests or dev files) and attaches it to a GitHub release. Sites see the update on their Plugins screen within 12 hours, or straight away with the plugin row's **Check for updates** link.
