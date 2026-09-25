---
name: pick-games
description: Choose a Pick55 week's 14 lines (NFL 4 spreads + 3 totals, NCAA 4 spreads + 3 totals) from the newest VegasInsider scrape using the pool's selection rules, show the slate and its exact football_games INSERTs, and create them on the owner's go. Use when the owner says "pick the games", "select this week's games", "set up week N", "make the slate", "do the games from scrape", or links admin/weeks/week/bulk-games.php. Replaces ticking boxes on that page.
---

# Pick the week's games

**Shape: VERTICAL (skill).** The owner used to open `admin/weeks/week/bulk-games.php?week_id=N`, eyeball the scraped NFL and NCAA lines, and tick 14 of them. This skill makes the same choice the way the owner makes it and creates the same rows the page would have. The mechanical parts (team matching, time slots, eligibility, SQL) live in `scrape/slate.php`; the judgment ("which are the biggest games") is yours, guided by the rules below and calibrated against the hand-picked weeks in `football_games`.

## Rules the slate must satisfy

The pool's rules, from the owner (2026-09-25) and measured against seasons 17–18. Inputs each week: the newest scrape, the AP Top 25, and ESPN FPI for both leagues.

**Shape.** 14 rows per week: NFL 7 (4 spreads, 3 over-unders) and NCAA 7 (4 spreads, 3 over-unders). Every hand-entered week since 2025 has exactly this shape (one 2025 week went 3+4 on NCAA; treat 4+3 as the rule).

**Never before Saturday**, except Thanksgiving week, when the Thursday and Friday games are fair game. `slate.php` marks Thursday/Friday games ineligible and detects the Thanksgiving window itself.

**NFL, always:**
- Every Monday night game is in (one line each; if there are two MNF games both are in).
- The Sunday night game is in (it is usually the biggest game anyway).
- At least one game in each slot: noon (12:00 CT), 3 pm (15:05/15:25), Sunday night, Monday night. A Sunday 8:30 am London game may be used (2025 used four of them) but does not cover the noon slot.

**NCAA, always:** spread across the day. Every 2025–26 week has at least one 11:00 am game, one mid-afternoon game (14:30–16:00) and one evening game (18:00–19:00). Late-night games (20:00+) appear rarely.

**Which games.** Spreads go on the **4 biggest games** of each league; over-unders on the **next best 3**, or on games that are only there to fill a slot. Among the top games, the ones with the closest spread get the spread; a big game with a wide spread (say 7 or more) reads better as a total (2026 week 3: Chargers @ Bills +7.5 became a total; Texas A&M @ LSU +8.5 became a total while Iowa @ Michigan +5.5 got the spread).

**NCAA "good game" test:** at least one team is AP-ranked **and** the spread is under 14. Ranked-vs-ranked games are the pool's bread and butter; ranked-vs-unranked with a spread of 10–13.5 is the fallback, and the owner passes on those when a better option exists.

**The double.** When there are not 7 good NCAA games, put **both** the spread and the over-under on the biggest game of the week (or on two of the top games) rather than reaching for a ranked-vs-unranked blowout. This is common: it happened in 5 of the first 5 weeks of 2025 and in all 3 weeks of 2026 so far, always on the No. 1 or No. 2 game of the slate. NFL doubles are rare (once in 2025, week 12) and only when the slate is thin.

**"Biggest" is measured by ESPN's FPI**, not by record (owner, 2026-09-25: "record is only a decent measuring stick late in the season"). A game is big when both teams' FPI ranks are low, and it gets bigger as the spread gets closer. The board's NFL score is exactly that (100 minus the two FPI ranks minus the spread, plus 6 for SNF/MNF), so read the NFL candidate list top-down and only override it for a reason you can name: a division rivalry, a first-place matchup, a quarterback storyline. A great team laying 10 to a bad one is not a big game, whatever the brand: 2026 week 3, Chiefs (FPI 4) @ Dolphins (FPI 32) at -10.5 scored 13th of 15 and the owner left it out, while Patriots (10) @ Jaguars (8) at +3.5 scored 3rd and got a spread. For NCAA the AP poll stays the eligibility test and the main ordering (that is how the owner talks about the games); FPI is a tiebreak between similar games.

## Procedure

Everything below is PowerShell with absolute paths (CLAUDE.md). Nothing writes to production until step 9, and only on the owner's go in a later turn (`LIVE_WRITE_GATE`).

1. **Find the target week.** The owner usually names it (the `week_id` in a `bulk-games.php` link). Otherwise it is the first week of the active season with no games:

   ```powershell
   ssh pick55 "mysql pick -e \"SELECT w.id, w.week_num, w.picks_due_date, (SELECT COUNT(*) FROM football_games g WHERE g.football_week_id=w.id) games FROM football_weeks w JOIN football_seasons s ON s.id=w.football_season_id WHERE s.is_active=1 ORDER BY w.week_num\""
   ```

   If the week already has rows, stop and say so; adding to a populated week is a different job (the owner may want a replacement, which means a `DELETE` under the gate first).

2. **Scrape fresh, locally.** Lines move; do not pick from a stale file. This writes `scrape/raw/vegas-insider/<league>/<stamp>.json` on this machine:

   ```powershell
   php C:\wamp\www\pick55\scrape\run.php --force
   ```

3. **Dump production's teams** so ids and slugs match what the rows will reference (the local database can lag production's team edits):

   ```powershell
   ssh pick55 "mysql pick -e \"SELECT id, type, team, nickname, vegas_insider_url FROM football_teams WHERE vegas_insider_url IS NOT NULL AND vegas_insider_url<>'' ORDER BY type, id\"" 2>$null | Out-File -Encoding utf8 C:\Users\utexa\AppData\Local\Temp\pick55-teams.tsv
   ```

   (`2>$null` drops the ssh banner; `slate.php` also skips any `**` lines that leak through.)

4. **Get the AP Top 25.** Fetch `https://www.ncaa.com/rankings/football/fbs/associated-press` (WebFetch works on it; apnews.com is blocked) and write a JSON object of VegasInsider slug → rank to `C:\Users\utexa\AppData\Local\Temp\pick55-rankings.json`, for example `{"texas": 1, "georgia": 2, ...}`. Slugs are the `away_team`/`home_team` values in the scrape JSON: lowercase, spaces to hyphens, punctuation dropped. Ones that are not obvious: Southern Cal → `usc`, Miami (FL) → `miami-fl`, Texas A&M → `texas-am`, Ole Miss → `ole-miss`, Mississippi State → `mississippi-state`, NC State → `nc-state`, Pitt → `pittsburgh`, Hawaii → `hawaii`. Confirm every slug you write appears in the scrape (grep the JSON) so a typo does not silently unrank a team. The `football_teams.ranking` column is a 2013-era leftover; ignore it.

5. **Get ESPN FPI for both leagues.** Fetch `https://www.espn.com/nfl/fpi` (all 32 teams) and `https://www.espn.com/college-football/fpi` (the top 40 is enough) and write `C:\Users\utexa\AppData\Local\Temp\pick55-fpi.json` as `{"NFL": {"49ers": 1, "bills": 2, ...}, "NCAA": {"ohio-state": 1, "texas": 2, ...}}`, FPI **rank** per VegasInsider slug. NFL slugs are the nickname in lowercase (`49ers`, `commanders`, `chiefs`); NCAA slugs as in step 4. Both pages fetched fine on 2026-09-25 (NFL updated daily, college weekly). Note anything else that makes a game big this week (a rivalry, a first-place matchup, a returning quarterback) if you know it; do not invent storylines. Do not use won-lost records; they say little before November.

6. **Print the board.**

   ```powershell
   php C:\wamp\www\pick55\scrape\slate.php board --rankings=C:\Users\utexa\AppData\Local\Temp\pick55-rankings.json --fpi=C:\Users\utexa\AppData\Local\Temp\pick55-fpi.json --teams=C:\Users\utexa\AppData\Local\Temp\pick55-teams.tsv
   ```

   Per league it lists every scraped game with kickoff (Central), slot, key (`away@home` slugs), AP rank (`#4`) and FPI rank (`(F13)`) per team, spread (against the away team, negative = away favored), total, and why a game is ineligible (before Saturday, no team row, started, nobody ranked, spread ≥ 14). Then the eligible games sorted by the big-game score (NFL: FPI-rank sum and spread closeness, SNF/MNF bonus; NCAA: both AP-ranked beats one, then lower AP sum, closer spread, FPI as tiebreak) and the count of eligible games per slot. The score orders the list; it does not pick.

   A team shown as `[NO TEAM ROW]` in a game you want has no `football_teams` row for that slug. Create the team first (`admin/teams/create.php` by the owner, or an `INSERT` under the gate), re-dump the teams file, and rerun the board.

7. **Choose the slate.** Apply the rules above in this order: required games (all MNF, SNF), then spreads on the 4 biggest, then totals on the next 3, then check every slot is covered and swap a total to a slot-filling game if not, then confirm the NCAA seven are all "good games" and use the double if they are not. Write the picks as one key per line to `C:\Users\utexa\AppData\Local\Temp\pick55-slate.txt`, `LEAGUE:away@home:spread|over-under`, comments after `#` are fine.

8. **Generate and check the SQL.**

   ```powershell
   php C:\wamp\www\pick55\scrape\slate.php sql --week=230 --teams=C:\Users\utexa\AppData\Local\Temp\pick55-teams.tsv --rankings=C:\Users\utexa\AppData\Local\Temp\pick55-rankings.json --fpi=C:\Users\utexa\AppData\Local\Temp\pick55-fpi.json --slate=C:\Users\utexa\AppData\Local\Temp\pick55-slate.txt | Out-File -Encoding utf8 C:\Users\utexa\AppData\Local\Temp\pick55-slate.sql
   ```

   Warnings on stderr (exit 3) name any rule the slate breaks: wrong 4+3 shape, an uncovered NFL or NCAA slot, a Monday game left out, a pre-Saturday game, an NCAA game with nobody ranked or a 14+ spread, or a double (that one is informational). Fix the slate and rerun until the only warning is a deliberate double. The SQL is one `INSERT` with the exact fields `bulk-games.php` writes (`title` "Away Name @ Home Name", options `Bengals (-3.5)` / `Steelers (+3.5)` for NFL, `Texas (-4.5)` / `Tennessee (+4.5)` for NCAA, `OVER (54.5)` / `UNDER (54.5)` for totals, `correct_option` '0') plus a verification `SELECT`.

9. **Present, then wait.** Show the owner a table of the 14 lines (league, kickoff, matchup with ranks, line, spread or total) with one line of reasoning per pick, the rule checks that passed, anything you were unsure about (a coin flip between two games, a line that moved a lot since Monday), and the full SQL in a code block. State the expected row count (14). Then stop; the go comes in a later turn.

10. **On go, create and verify:**

    ```powershell
    Get-Content C:\Users\utexa\AppData\Local\Temp\pick55-slate.sql -Raw | ssh pick55 "mysql pick"
    ```

    Then run the verification `SELECT` from the SQL file over `ssh pick55` and confirm 14 rows with the right kickoffs and options, and tell the owner the week's `bulk-games.php` page now shows them as `in week`. If the scrape changed between step 8 and the go, rerun step 8 first and show the diff.

## Reporting the slate

Keep it to one table per league, in kickoff order, with an asterisk on the game carrying both lines. Say which games were the near misses and why they lost (for instance "Oklahoma @ Georgia is ranked-vs-unranked at +13.5, so the seventh NCAA line went to the Texas @ Tennessee total instead"). If a required game has no line in the Consensus column (`no line` on the board), say so; the owner enters that one by hand on `admin/games/create.php`.

## Calibration record

- 2026 week 3 (`football_weeks.id` 230, owner's hand pick, 2026-09-22): NCAA spreads Texas @ Tennessee, Ole Miss @ Florida, Iowa @ Michigan, Oregon @ USC; totals Texas @ Tennessee, Texas A&M @ LSU, Missouri @ Mississippi State. NFL spreads Bengals @ Steelers, Patriots @ Jaguars, Vikings @ Buccaneers, Rams @ Broncos (SNF); totals Chargers @ Bills, Ravens @ Cowboys, Eagles @ Bears (MNF).
- Claude's dry run against the same week (2026-09-25, this skill's first use, with the owner's picks already visible): NCAA identical, all 7 lines. NFL, first pass using won-lost records: 6 of 7 games, with Chiefs @ Dolphins in as a total instead of Patriots @ Jaguars. The owner rejected that ("the Dolphins are the worst team in the league; JAX and the Patriots are both top ten") and named ESPN FPI as the measure. Second pass with FPI: the board's top 5 plus Vikings @ Buccaneers and Bengals @ Steelers were the owner's 7 games exactly (Bengals @ Steelers chosen over the equal-scoring Texans @ Colts as a division game). Only difference left: Claude put the spread on Ravens @ Cowboys (the biggest game by FPI, -3.5) and the total on Bengals @ Steelers; the owner did the reverse, taking the week's highest total (53.5) on Ravens @ Cowboys.
