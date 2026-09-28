# Classic or Version 2.0: the visitor's choice

**Shape: VERTICAL (one process).** How the site remembers which of its two player sites a visitor wants, the classic one or the redesign under `r/` ([redesign.md](redesign.md)), and how every link then lands on that one.

## The rule

A visitor who has chosen a site stays on it. Any GET for a page of the other site is answered with a 302 to the same page on the chosen site, query string kept. The choice is made only by following a switch link ("Version 2.0" on the classic site, "Classic site" on the redesign), and it is kept in a cookie, so nothing is stored in the database. Owner's brief (2026-09-28): "the user's preference between the classic version and the redesigned version … saved, and all page links … redirected based on that user's preference … saved as a cookie … updated when the user switches."

A visitor who never chose is never redirected: they see whichever site the link they followed belongs to.

## Pieces

| Piece | Where | Does |
|---|---|---|
| The cookie | `site_version` = `classic` or `r`, path `/`, HttpOnly, one year (`SiteVersion::LIFETIME`) | Holds the choice, per browser. Re-sent on every request that carries it, so it slides. Any other value is ignored. |
| The switch parameter | `?site=classic` / `?site=r` (`SiteVersion::PARAM`) | Carried by every switch link. The request saves the choice and is redirected to the same URL without the parameter. |
| `Pick55\SiteVersion` | `inc/Pick55/SiteVersion.php` | `handle()` (the two steps above, called from `inc/_inc.php` on every web request, after the cookie login and `Traffic::start()`), `preference()`, `current($rel)` (`r` for a path under `r/`, else `classic`), `counterpart($rel, $to)`, `switchLink($to)`, `remember($version)`, `requestRel()` (the serving script relative to the site, from `SCRIPT_NAME`), `label($version)`. |
| Classic switch link | `Page::redesignLink()` | The header and footer "Version 2.0" links: `SiteVersion::switchLink('r')`, already HTML-escaped. |
| Redesign switch link | `Shell::classicSwitchLink()`, and `updateClassicLinks()` in `r/static/js/app.js` | The footer and account menu "Classic site" links (`data-classic`); `app.js` rewrites them after each in-place navigation and appends `site=classic`. The account page's link goes to the classic home page with the same parameter. |

## Which pages have a twin

`counterpart()` answers from the file system: a classic path has a twin when `r/<path>` is a file, an `r/` path when `<path>` is a file at the site root. Never redirected:

- `admin/` (classic only). An admin who chose Version 2.0 uses the admin pages as they are; the classic header's player links lead back to `r/`.
- `r/api/`, and the classic JSON endpoints (`season/week/save-picks.php`, `live.php`, `raw.php`), which have no file at the twin path.
- `season/inactive.php` and `season/unauthorized.php` (the redesign shows those states inside its own pages).
- Anything that is not a GET or HEAD: a form posts to the site it was rendered by.

A directory request keeps its shape: `/` becomes `/r/`, not `/r/index.php`.

## Consequences worth knowing

- The verify and reset emails link to the classic `auth/verify.php` and `auth/reset.php`. A visitor who chose Version 2.0 is redirected to the `r/` page with the token, which handles it the same way.
- The redirect happens before the page's guard, so a signed-out visitor who chose Version 2.0 and opens a classic page goes to its `r/` twin and from there to `r/auth/login.php?r=…`.
- A fragment request (`X-P55-Partial`) from a visitor who chose Classic is redirected out of `r/`; `app.js` then falls back to a full page load, as it does for any redirect that leaves `r/`.
- The choice is per browser, not per player: a phone and a laptop can differ, and signing out does not clear it.
- Every request is recorded with the site that served it and the choice it carried ([site-traffic.md](site-traffic.md)); the admin traffic page totals the choices.

## Verified 2026-09-28 (local Apache, curl)

- No cookie: `/pick55/` and `/pick55/r/rules.php` both answer 200. A cookie with an unknown value is ignored.
- `rules.php?site=r&x=1` answers 302 to `r/rules.php?x=1` with `Set-Cookie: site_version=r; Max-Age=31536000; HttpOnly`.
- Cookie `r`: `rules.php` → `r/rules.php`; `/` → `r/`; `season/standings.php?id=18` → `r/season/standings.php?id=18`; `auth/reset.php?token=…` → `r/auth/reset.php?token=…`; `admin/index.php` and `season/week/live.php` are not redirected by the choice.
- Cookie `classic`: `r/rules.php` → `rules.php`; `r/` → `/`; `r/api/today.php` is not redirected.
- As the test player: following the classic switch link, then opening `r/season/standings.php`, lands on the classic page; following the "Version 2.0" link lands back on `r/`.

`redir('')` means "the current URL", so `SiteVersion` sends its own `Location` header: the first version of this code looped on `r/` → `/` for that reason.
