# Local history for webtrees

Adds events from local history to the facts of the people connected to it.

If your family came from a small town, you can show when the railway arrived, when the mill burned down,
or a war that touched everyone, on the pages of the people who were there at the time. Events are organised
into **groups** — a simple site needs only one (shown to everyone, or only to people connected to its
places); a more detailed one can layer local, regional and national history, each reaching the right
people. You write the groups and events yourself on a settings page, or ask an AI to research them for you.
There is no code to edit.

For example, someone born in 1920 would see these among their facts, in date order:

- **1929** Wall Street Crash
- **1939 to 1945** The Second World War
- **1957** Sputnik launched
- **1969** First Moon landing

Events appear in the **Facts and events** tab. Visitors can hide them with the **Historic events**
checkbox on that tab.

- [Install](#install)
- [Set it up](#set-it-up)
- [Groups](#groups)
- [Timing: lifespan or time spent at the place](#timing-lifespan-or-time-spent-at-the-place)
- [Writing events](#writing-events)
- [Let an AI research events for you](#let-an-ai-research-events-for-you)
- [Example sets, export and import](#example-sets-export-and-import)
- [Sharing a set of events](#sharing-a-set-of-events)
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
2. Under **Groups**, add a group: give it a name, list the places it's about (one per line), and choose
   its audience.
3. Add some events, or use the [example set](#example-sets-export-and-import) or an
   [AI prompt](#let-an-ai-research-events-for-you) to start.
4. Click **save**, then open the page of someone connected to the group. You should see its events.

Until you have at least one group, nobody sees any events, and the settings page tells you so.

---

## Groups

A group is a name, a list of places, and an audience:

| Audience | When to use it |
|---|---|
| **Only people connected to one of its places** | Local events: a railway arriving, a mill burning down, a town being incorporated. |
| **Everyone in the family tree** | Events that touched everyone, regardless of where they lived: wars, a national depression. No places are needed. |

Either way, a person is only shown an event that happened while they were alive (or, in the narrower
timing mode below, while they are known to have been at the place).

A simple site needs only one group. A more detailed one can have several — for example, a town's own
history shown only to people connected to it, alongside a national history shown to everyone — so that a
county-wide event never reaches visitors from the other side of the world, and a world war isn't
needlessly restricted to one town. Each event, further down the settings page, belongs to one group.

**Writing a group's places.** Put one place on each line. A person is connected if one of their facts has
a place containing that text. Capital letters, apostrophes and extra spaces are ignored, so `Kelleys Island`
also finds `Kelley's Island, Erie, Ohio, USA`. Use whole names — `Erie` alone would also match
`Erie, Pennsylvania`.

For spelling variations, start and end a line with a slash to use a
[regular expression](https://www.php.net/manual/en/reference.pcre.pattern.syntax.php), for example
`/\bSpring?field\b/i`. A line that isn't a valid expression isn't saved, and you are told.

**Also look at**, below the groups, decides which facts count as a connection to a group's places. A
person's own facts always count. You can also count the facts of the families they married into (e.g. a
marriage in the town), and of their parents' family (e.g. their parents' marriage in the town). This
applies to every group.

**Renaming a group is safe.** Its events stay with it — only deleting a group, or changing an event's
group, disconnects them.

**If an event's group is deleted**, nobody sees that event any more, and the settings page shows a warning
naming it, so nothing is silently lost. Re-assign it to another group, or delete it.

---

## Timing: lifespan or time spent at the place

By default, an event is shown to anyone connected to a group's places who was alive when it happened —
their **whole lifespan**, from birth to death. That's simple and forgiving, but it has a blind spot: someone
born in a town and gone a week later still "qualifies" for everything that happened there for the rest of
their life, and someone merely *buried* in a town — their body brought there afterwards — inherits its
entire history even though they may never have set foot in it while alive.

**Base events on → Only the time they are known to have spent at the place** fixes both of those, for every
group whose audience is "people connected to its places" (a group shown to everyone has no places to measure
time spent at, so it always uses the whole lifespan). Instead of the person's whole life, it uses a narrower
window: the earliest to the latest of their own *dated* facts at that group's places (a birth, a census, a
residence — see **Facts that count**, below). Only events falling inside that window are shown.

- Someone born at the place in 1845 and recorded living elsewhere by 1850 only sees events from 1845.
- Someone connected to a group **only** by an undated fact, or only by their burial there, sees
  **no events from that group at all** — not a guess at their whole lifespan. A body brought somewhere for
  burial doesn't mean the person ever lived there, so burial isn't counted as evidence of presence by
  default.
- Someone with just one dated fact there (commonly a birth, in a thin tree) gets a narrow window: just that
  one year. That's not a bug — it's the module being honest about how little it actually knows. Add more
  dated facts (a census, a residence) to widen the window.

**Facts that count** decides which fact types are trusted as evidence of actually being at a place. It's a
comma-separated list of GEDCOM tags, such as `BIRT,RESI,CENS,OCCU,MARR`. `BURI` and `DEAT` are left out of
the default list on purpose, for the reason above — add them yourself if you want burials or deaths to count
(for example, on a cemetery-focused site). Leave the box empty to count *any* dated fact at the place,
including burials and deaths. This applies to every group.

This mode needs a reasonably detailed tree to be useful. If most of your people have only a birth and a
death recorded, with nothing dated in between, **whole lifespan** will show more — just less precisely.
You can switch between the two at any time; nothing about your groups or events needs to change.

**Minimum age** applies either way, to every group: it only shows an event to someone who had already
reached that age when it happened, so a baby isn't shown a war that started three days before they were
born. 0 means no minimum.

---

## Writing events

Each event has:

| Field | |
|---|---|
| **Date** | Required. A GEDCOM date, see below. |
| **Title** | Required. A short name, shown as the type of fact, e.g. *The railway arrives*. |
| **Group** | Required. Which group's audience and timing apply to this event. |
| **Place** | Where it happened. Shown with the event — separate from the group's own places, which decide who sees it. |
| **Description** | A sentence about it. |
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

## Let an AI research events for you

Writing a detailed, accurate list of local events by hand takes real research. If you'd rather not, the
settings page has a ready-made prompt under **Ask an AI to research events for you** (above the Import box).
Copy it, paste it into an AI assistant such as Claude or ChatGPT, fill in the place and rough time period at
the top, and paste its reply into the **Import** box below.

The prompt asks the AI to:

- write real, dateable, specific local events — not vague ones;
- use the exact JSON format this module reads, including the GEDCOM date forms above;
- mark anything it isn't fully confident about with a note starting `Check:`, rather than silently
  guessing; and
- split purely local events from wider ones (a war, a national depression) into a second,
  "everyone"-scoped group, when both kinds are relevant.

**Always read the result before relying on it.** AI assistants can get local history wrong in ways that
read as perfectly confident — invented dates, events that happened in a different, similarly-named place,
or real events with the wrong year. Check anything marked `Check:`, and spot-check the rest against a real
source such as a local historical society, library or newspaper archive, the same as you would for any
other source in your tree.

---

## Example sets, export and import

Under **Import, export and examples** on the settings page:

- **Example sets** are ready-made files in the module's `examples` folder, each with its own groups and
  events. Click **Add these** to add one to your own groups and events — a group it defines that you
  already have (matched by name) is left exactly as you have it, and its events still join it; events
  already in your list (same date, title and group) are skipped. The module comes with one,
  [World history](examples/world-history.json) — major events felt across much of the world, shown to
  everyone regardless of where they lived, and a good base to add your own group of local events to,
  scoped to your own places. It also shows what a set looks like. Check the facts in any example before
  you rely on it.
- **Export** downloads your groups and events as a file. Keep it as a backup, or give it to someone else.
- **Import** adds the contents of such a file — or an AI assistant's reply to the prompt above — to your
  groups and events, or replaces them entirely. If the pasted text is wrapped in a code block (some AI
  assistants do this even when asked not to), the wrapping is removed automatically.

Your groups and events are stored in the webtrees database, so they are kept when you update the module,
and included in your usual database backups.

---

## Sharing a set of events

To share a set with other webtrees users:

1. Click **Export** on the settings page. You get a file such as `springfield-history.json`.
2. Send them the file. They paste its contents under **Import**.

Or add it to the module's `examples` folder (any file ending in `.json`), and anyone who has the module can
add it with one click. The file looks like this:

```json
{
    "name": "Springfield",
    "description": "A few events from the history of Springfield.",
    "groups": [
        {
            "label": "Springfield",
            "places": "Springfield\nSpringfeld",
            "scope": "connected"
        }
    ],
    "events": [
        {
            "date": "1853",
            "title": "The railway arrives",
            "text": "The first train reaches Springfield.",
            "place": "Springfield, Clark, Ohio, USA",
            "group": "Springfield",
            "note": "Check the date with the library."
        }
    ]
}
```

An event's `"group"` can name a group by its label (as above) or its key; either is resolved against the
groups listed in the same file. `date` and `title` are the only fields every event needs. Two simpler forms
also work: a file with a flat `"places"` array instead of `"groups"` becomes one group, named after the
file; and a bare `[{...}, {...}]` list of events, with nothing else, is accepted too — if none of its
events name a group, they are all shown to everyone.

---

## Good to know

- **Privacy.** Only facts the visitor is allowed to see decide who is connected to a group's places, so an
  event never gives away a private fact. Events are only added to pages webtrees already shows.
- **Language.** Events are shown exactly as you wrote them, in any language. They are the same for every
  visitor.
- **Limits.** Up to 500 events and 20 groups can be saved.
- **Missing connection.** If someone lived in the town but none of their places say so (for example, they
  only say `Ohio`), they won't be shown its events. Add the missing place to their facts, or add a broader
  line to the group's places.

---

*Michael Beatty*
