# Testing AMHDB Gigs

Work through this on a Local site before the plugin goes onto AMH staging (#426). It covers AMHDB #424's "done when". It takes about half an hour.

## Setup

- [ ] New Local site on **PHP 8.2 or later**, matching production.
- [ ] Install and activate the plugin: upload the release zip, or symlink this folder into `wp-content/plugins/amhdb-gigs` so edits are live.
- [ ] **Tools → AMHDB Gigs** exists and says "No lists shown yet."

## 1. Against production AMHDB

Create a page with these shortcodes and view it:

```
[amhdb_gigs artist="bandanna" menu="yes" menu_count="yes"]
[amhdb_gigs artist="rick-price"]
[amhdb_gigs venue="bombay-rock" limit="5"]
[amhdb_gigs tag="james-blundell-this-road-tour-1992-93"]
[amhdb_gigs year="1979" month="3" group_artists="yes"]
[amhdb_gigs band="no-such-band"]
```

- [ ] Bandanna: year and month headings, the jump menu with counts, and anchor links that jump to the right place.
- [ ] Rick Price (a person, found through `artist=`): the gigs show "supporting The Beach Boys".
- [ ] Bombay Rock: five gigs, then "See all 17 gigs in the Australian Music Database", linking to AMHDB's venue page.
- [ ] The tour list shows show times in the notes, e.g. "8:30pm".
- [ ] March 1979 grouped by artist: headings per artist, with the year inside each date.
- [ ] `no-such-band`: "This gig list is on the Australian Music Database", linking to AMHDB.
- [ ] **View source**: the gigs are in the HTML itself (search for `amhdb-gig__date`), not added by JavaScript.
- [ ] Reload the page. Tools → AMHDB Gigs lists each list with a fetch time, and the reload made no new requests (the times don't change).
- [ ] **Refresh all now** updates the times. **Clear cache** empties the table; the next page view fetches again.
- [ ] `wp amhdb-gigs refresh` and `wp amhdb-gigs clear` (Local's site shell) do the same.

## 2. AMHDB down: stale copy

- [ ] With the lists cached, add `define( 'AMHDB_GIGS_API', 'https://australianmusicdatabase.invalid/api/v1/gigs' );` to `wp-config.php`.
- [ ] **Refresh all now**: the times stay as they were (the refresh failed).
- [ ] View the page: every list still shows, from the last good copy.

## 3. AMHDB down: no copy

- [ ] Still pointing at the bad address, **Clear cache**, then view the page.
- [ ] Each list shows "This gig list is on the Australian Music Database", linking to the band, person or venue page, or AMHDB's home page for the year and tour lists. Each one appears within about 5 seconds, not hanging.
- [ ] Remove the `define`, **Clear cache**, and view: the lists come back.

## 4. Display and accessibility

- [ ] At **phone width** (dev tools, 390px): tiles stay beside the details, nothing runs off the right edge, and long venue names wrap.
- [ ] With the **base font at 18px** (`html { font-size: 18px }` via dev tools or the theme): everything scales and nothing overlaps.
- [ ] **Tab** through a list: every link shows a visible focus outline.
- [ ] With a **screen reader** (VoiceOver: Cmd+F5), a "Spotted something wrong?" link reads as "… Report a correction to Bandanna, Bondi Lifesaver, 1 February 1985".
- [ ] Nothing depends on colour alone: links are underlined by the theme.

## If something fails

- **A list is blank, with an HTML comment in the source:** the shortcode has no filter attribute.
- **"This gig list is on the Australian Music Database" with AMHDB up:** a wrong slug (check the AMHDB URL), or the site can't make outgoing HTTPS requests.
- **Lists never refresh:** WP-Cron isn't running. Check **Tools → Site Health**, or run `wp cron event run --due-now`.
