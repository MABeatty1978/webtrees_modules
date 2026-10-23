# Local history for webtrees

Adds events from the history of a place to the facts of the people who lived there.

If your family came from a small town, you can show when the railway arrived, when the mill burned down,
or when the town got electricity, on the page of everyone who lived there at the time. You choose which
places count, who sees which events, and you write the events yourself on a settings page. There is no
code to edit.

For example, someone born on Kelleys Island, Ohio in 1840 would see these among their facts, in date order:

- **1840** Kelleys Island named
- **about 1842** Wine making begins
- **19 September 1864** Raid on Lake Erie
- **1887** Kelleys Island becomes a village

Events appear in the **Facts and events** tab. Visitors can hide them with the **Historic events**
checkbox on that tab.

- [Install](#install)
- [Set it up](#set-it-up)
- [Settings](#settings)
- [Timing: lifespan or time spent at the place](#timing-lifespan-or-time-spent-at-the-place)
- [Writing events](#writing-events)
- [Example sets, export and import](#example-sets-export-and-import)
- [Sharing your events](#sharing-your-events)
- [Good to know](#good-to-know)

---

## Install

Copy the `local_history` folder into your webtrees `modules_v4` folder:

```
webtrees/
└── modules_v4/
    └── local_history/
        ├── module.php
        ├── LocalHistoryModule.php
        ├── src/
        ├── resources/
        └── examples/
```

webtrees finds it automatically. Works with webtrees 2.1 or later (PHP 7.4 or later). To uninstall, delete
the folder.

## Set it up

1. Go to **Control panel → Modules → Historic events**, and click the wrench next to **Local history**.
   (If you can't see it, check it is enabled under **Modules → All modules**.)
2. Under **Places**, list the places your events are about, one on each line, e.g. `Springfield`.
3. Add some events, or use one of the [example sets](#example-sets-export-and-import) to start.
4. Click **save**, then open the page of someone who lived there. You should see the events.

Until you list at least one place (or choose to show events to everyone), nobody sees any events, and the
settings page tells you so.

---

## Settings

### Which people see the events

| Setting | What it does |
|---|---|
| **Show events to** | **Only people connected to one of the places** (best for local events) or **Everyone in the family tree** (best for events that affected everybody, such as wars). Either way, a person is only shown events that happened while they were alive. |
| **Places** | The places your events are about. See below. |
| **Also look at** | Which facts count as a connection to a place. A person's own facts always count. You can also count the facts of the families they married into (e.g. a marriage in the town), and of their parents' family (e.g. their parents' marriage in the town). |

**Writing the list of places.** Put one place on each line. A person is connected if one of their facts has
a place containing that text. Capital letters, apostrophes and extra spaces are ignored, so `Kelleys Island`
also finds `Kelley's Island, Erie, Ohio, USA`. Use whole names. `Erie` alone would also match `Erie, Pennsylvania`.

For spelling variations, start and end a line with a slash to use a
[regular expression](https://www.php.net/manual/en/reference.pcre.pattern.syntax.php), for example
`/\bSpring?field\b/i`. A line that isn't a valid expression isn't saved, and you are told.

### Timing

See [Timing: lifespan or time spent at the place](#timing-lifespan-or-time-spent-at-the-place).

### Appearance

| Setting | What it does |
|---|---|
| **Name of the module** | Shown in the list of historic events in the control panel, e.g. *Springfield history*. |
| **Description** | Shown under the name there. |

### Events

The list of events. See [Writing events](#writing-events).

---

## Timing: lifespan or time spent at the place

By default, an event is shown to anyone connected to one of your places who was alive when it happened —
their **whole lifespan**, from birth to death. That's simple and forgiving, but it has a blind spot: someone
born in a town and gone a week later still "qualifies" for everything that happened there for the rest of
their life, and someone merely *buried* in a town — their body brought there afterwards — inherits its
entire history even though they may never have set foot in it while alive.

**Base events on → Only the time they are known to have spent at the place** fixes both of those. Instead
of the person's whole life, it uses a narrower window: the earliest to the latest of their own *dated* facts
at that place (a birth, a census, a residence — see **Facts that count**, below). Only events falling inside
that window are shown.

- Someone born at the place in 1845 and recorded living elsewhere by 1850 only sees events from 1845.
- Someone connected to the place **only** by an undated fact, or only by their burial there, sees
  **no events at all** — not a guess at their whole lifespan. A body brought somewhere for burial doesn't
  mean the person ever lived there, so burial isn't counted as evidence of presence by default.
- Someone with just one dated fact there (commonly a birth, in a thin tree) gets a narrow window: just that
  one year. That's not a bug — it's the module being honest about how little it actually knows. Add more
  dated facts (a census, a residence) to widen the window.

**Facts that count** decides which fact types are trusted as evidence of actually being at the place. It's a
comma-separated list of GEDCOM tags, such as `BIRT,RESI,CENS,OCCU,MARR`. `BURI` and `DEAT` are left out of
the default list on purpose, for the reason above — add them yourself if you want burials or deaths to count
(for example, on a cemetery-focused site). Leave the box empty to count *any* dated fact at the place,
including burials and deaths.

This mode needs a reasonably detailed tree to be useful. If most of your people have only a birth and a
death recorded, with nothing dated in between, **whole lifespan** will show more — just less precisely.
You can switch between the two at any time; nothing about your events or places needs to change.

**Minimum age** applies either way: it only shows an event to someone who had already reached that age when
it happened, so a baby isn't shown a war that started three days before they were born. 0 means no minimum.

---

## Writing events

Each event has:

| Field | |
|---|---|
| **Date** | Required. A GEDCOM date, see below. |
| **Title** | Required. A short name, shown as the type of fact, e.g. *The railway arrives*. |
| **Description** | A sentence about it. |
| **Place** | Where it happened. Shown with the event. |
| **Private note** | A reminder for you, such as "check this date with the historical society". Visitors never see it. |
| **Show** | Untick to keep an event in your list without showing it. |
| **Delete** | Tick, then save, to remove it. |

Blank events are ignored. Each time you save, events are put in date order, and any that couldn't be saved
(for example, because the date wasn't understood) are listed with the reason.

**Dates** are written the way webtrees writes them:

| You write | It means |
|---|---|
| `1850` | the year 1850 |
| `ABT 1850` | about 1850 |
| `19 SEP 1864` | a day. Use English month names in capitals: JAN FEB MAR APR MAY JUN JUL AUG SEP OCT NOV DEC |
| `FROM 1861 TO 1865` | something that lasted several years |
| `BEF 1940` / `AFT 1940` | before / after 1940 |
| `BET 1880 AND 1890` | some time in the 1880s |

---

## Example sets, export and import

Under **Import, export and examples** on the settings page:

- **Example sets** are ready-made lists in the module's `examples` folder. Click **Add these events** to add one
  to your list. Events already in your list (same date and title) are skipped, and you can add the places the
  example is about to your list of places at the same time. The module comes with one for
  [Kelleys Island, Ohio](examples/kelleys-island.json), which also shows what a set looks like.
  Check the facts in any example before you rely on it.
- **Export** downloads your places and events as a file. Keep it as a backup, or give it to someone else.
- **Import** adds the contents of such a file to your list, or replaces your list with it.

Your events are stored in the webtrees database, so they are kept when you update the module, and included
in your usual database backups.

---

## Sharing your events

To share a set of events with other webtrees users:

1. Click **Export** on the settings page. You get a file such as `springfield-history.json`.
2. Send them the file. They paste its contents under **Import**.

Or add it to the module's `examples` folder (any file ending in `.json`), and anyone who has the module can
add it with one click. The file looks like this:

```json
{
    "name": "Springfield",
    "description": "A few events from the history of Springfield.",
    "places": ["Springfield", "Springfeld"],
    "events": [
        {
            "date": "1853",
            "title": "The railway arrives",
            "text": "The first train reaches Springfield.",
            "place": "Springfield, Clark, Ohio, USA",
            "note": "Check the date with the library."
        }
    ]
}
```

Only `date` and `title` are needed for each event. A plain list of events, without `name` and `places`, also works.

---

## Good to know

- **Privacy.** Only facts the visitor is allowed to see decide who is connected to a place, so an event never
  gives away a private fact. Events are only added to pages webtrees already shows.
- **Language.** Events are shown exactly as you wrote them, in any language. They are the same for every visitor.
- **Many events.** Up to 500 can be saved.
- **Missing connection.** If someone lived in the town but none of their places say so (for example, they only say
  `Ohio`), they won't be shown its events. Add the missing place to their facts, or add a broader line to the list of places.

---

*Michael Beatty*
