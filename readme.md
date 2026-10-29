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
| `sort` | `asc` (default) or `desc`. Year-unknown gigs always come last. |
| `limit` | Show the first N, with a "See all N gigs in the Australian Music Database" link. |
| `group_artists` | `yes`: a heading per artist. `no` (default): year and month headings. |
| `menu` | `yes`: a jump menu of anchor links to each year and month (or artist). |
| `menu_count` | `yes`: gig counts in the jump menu. |
| `heading` | Level of the top headings, `h2`–`h5`. Default `h3`; sub-headings are one level down. |

Filters combine with AND, and at least one is needed. Everything AMHDB has for the filter is shown on one page, including gigs added later.

## What a list shows

- Year and month headings ("Year unknown" and "Month unknown" last), or artist headings.
- Each gig as a date tile ("Fri / 1 / Feb", with the year when no heading carries it). A one-gig list reads "Thursday 9 May 1991".
- Dates follow AMHDB's precision fields: an unknown year shows as "year unknown", a weekday only appears when the full date is known, and nothing is guessed.
- Multi-day gigs as a range.
- On an act's list, the other acts on the bill ("with The Hitmen"); on other lists, every act.
- The venue, suburb and state ("Bondi Lifesaver, Bondi Junction, NSW").
- The act's notes, and a "Source: …" line where AMHDB has one.
- Act and venue names link to their AMH post where AMHDB knows one, otherwise to AMHDB. There's no link back to the page the list is on.
- With no gigs: "No gigs recorded yet."

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
