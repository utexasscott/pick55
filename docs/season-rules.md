# Season rules — the rules page for any season

**Shape: VERTICAL (page).** What `rules.php` and `r/rules.php` print, where each fact is stored, why the number of players advancing lives on a week format and not on the season, and how the change reaches production. Built 2026-09-28; before that both pages hardcoded the 2025 values (`$fee = 120`, `$picks_due = '2025-09-06 11:00:00'`, "Ten to fourteen players will advance", "top 20", a static guaranteed-games grid).

## The pages

Both pages take `?id=` (a `football_seasons.id`); without it, or with an unknown id, they show the active season, else the latest. Each has a season select at the top (classic: submits on change; redesign: `P55.navigate` from `r/static/js/pages/rules.js`, with a Go button as the no-JS path). A season that is not active gets a line "These are the rules the X was played under." and loses the "Who can join?" and "How do I join?" answers. Both pages read one array from `Pick55\SeasonRules::get($season)`, so they cannot disagree.

## Where each fact comes from

Stored on `football_seasons` (four columns added by [`db/changes/2026-09-28-season-rules.sql`](../db/changes/2026-09-28-season-rules.sql)):

| Column | Meaning | Values |
|---|---|---|
| `fee` | entry fee (existed) | 120.00; 20.00 for the 2024 CFP (id 16) |
| `num_weeks`, `playoff_weeks` | regular and playoff weeks (existed) | 10 + 2; 4 + 0 for the CFP |
| `weekly_pot` | what each fee puts into each regular week's pot | 10.00 default; 5.00 for the CFP |
| `is_cfp` | College Football Playoff lines only (changes the "How it works" and "Game selection" wording) | 1 for id 16 only |
| `pay_to_name`, `pay_to_venmo` | who receives fees, and their Venmo handle without `@`; a blank handle hides "How do I join?" | "Brad", "Brad-North-2" on the 2026 Season (id 18) only |

Derived, never typed in:

| Fact | Source | Method |
|---|---|---|
| finals pot (playoffs, or season standings when `playoff_weeks` = 0) | `fee − weekly_pot × num_weeks`, so the two can never disagree | `Season::getFinalsPot()` |
| when it starts | kickoff of week 1's first game; picks and fees are due then. `football_weeks.picks_due_date` is when picks *open*, not when they are due | `Season::getStartsAt()` |
| games per week, and so the number of 0-point games | the most common game count among the season's weeks; a season with no games yet takes the latest earlier season's (12 through 2022, 14 from 2023) | `Season::getGamesPerWeek()` |
| pools paragraph | any regular week whose format has pools | `Season::hasPools()` |
| each week's name, pools, payouts | the week formats, as before (see [week-formats.md](week-formats.md)) | `WeekFormatPayouts` |
| players advancing to the Money Round | `advance` on the knock-out week's format | `Season::getKnockoutWeek()`, `getNumAdvancing()` |
| the guaranteed-games grid | `football_guaranteed_points` rows for the season | `Season::getGuaranteeGrid()` |

The per-player allocation was checked against the formats' `total_payout` (2026-09-28, local dump): for 2018–2025 the ten regular weeks total within 2% of $10 × players × 10 and the Finals within 5% of $20 × players (formats are sized by hand, so they round). 2012–2017 paid differently (for example 2014–2016: about $9 a week and $30 to the finals); those seasons carry the $10 default, and the owner can correct them on the admin season page **(unconfirmed — ask whether it matters)**.

## Why "advance" lives on the format

The number advancing from the Semifinals looked like both a season attribute and a week attribute. It has one home: `football_week_formats.advance` on the knock-out week's format. The season reads it through (`Season::getNumAdvancing()`: the first playoff week whose format sets `advance`), and the rules page's description, the Money Round paragraph and the Weeks table all print that one number. Reasons: formats are already chosen by league size (`num_players`), which is what the advancing count depends on (4 in the 10–12-player seasons of 2011–2013, 20 in 2025 and 2026); the format is what the results page and admin pages already read; and a season column would be a second copy that could drift. The admin season page shows the value read-only with a link to edit the format.

## The guaranteed-games grid

Players seeded in the regular season get guaranteed-correct picks in the Knock-Out Round: `football_guaranteed_points` holds one row per player with `multipliers_less_than` = N, and `Week::setGuaranteedPoints()` turns every one of that player's picks worth less than N into option `'3'`. `getGuaranteeGrid()` groups a season's rows by N, highest first, and gives each group the next places in turn. As recorded:

| Seasons | 1st | 2nd–3rd | 4th–5th | 6th–7th | then | then |
|---|---|---|---|---|---|---|
| 2021–2024 | < 11 (all) | < 9 | < 8 | < 7 | 8th–9th < 6 | 10th–12th < 5 |
| 2025 | < 11 (all) | < 9 | < 8 | < 7 | 8th–10th < 6 | 11th–20th < 5 |

Seasons before 2021 have no rows, so their pages have no grid. The active season's rows are created only when its regular season ends, so until then it shows the latest earlier season's grid with a note saying so. The grid's columns are the week's slots: every 0-point game, then 1–10; N guarantees all the 0-point games and 1 .. N−1.

## Admin

`admin/seasons/season/index.php` edits name, fee, weekly pot (refuses a pot × weeks larger than the fee; shows the resulting finals pot), payee name and Venmo (a leading `@` is stripped), and the CFP flag. Under "On the rules page, from elsewhere" it lists weeks, games per week, start, advancing (with the format link) and where the guaranteed-games grid comes from.

`Pick55\AllTimeStats` reads the same columns for the active season's fee to date (weekly pot per completed regular week, plus the finals pot once the last week is complete); it used to hardcode `FINALS_ALLOCATION = 20`. Its fingerprint includes `weekly_pot`.

## Verified (2026-09-28, local)

Both pages rendered through Apache for 2026, 2025, 2019, 2010 and the 2024 CFP, with no id and with id 999; the 2025 grid matches the old hardcoded grid row for row (plus the two extra 0-point columns of a 14-game week); the admin page rendered for 2026 and the CFP, and its save path accepted a valid save and refused a $13 weekly pot; both leaderboards rendered.

## Applying the change to production

*Concept key: `LIVE_WRITE_GATE`, `ORDERED_HANDOVER`.* The new code reads the new columns (the stats fingerprint selects `weekly_pot`, the admin page saves them), so **the change file must be applied before the push/deploy**. Production's season ids were read on 2026-09-28 and match local (16 = 2024 CFP, 18 = 2026 active). The owner applies it as root:

```powershell
scp C:\wamp\www\pick55\db\changes\2026-09-28-season-rules.sql root@143.198.236.171:/root/
ssh -t root@143.198.236.171 "mysql -u root -p pick < /root/2026-09-28-season-rules.sql"
```

Then push and deploy (see [deploy.md](deploy.md)).
