# Family tree chatbot for webtrees

An AI assistant for your webtrees website. Visitors click **Ask the family tree** on any page and ask
questions in plain English, such as:

- *Tell me about Louis Beatty.*
- *Who were his parents?* (follow-up questions work)
- *Tell me about this person.* (on an individual's page)
- *How is Thomas Smith related to Robert Smith?*
- *Tell me about the Beatty family.*

Answers come from your family tree, follow webtrees' privacy rules, and link to the individuals' pages.
The AI is Google Gemini, which you pay for per question, or use free within Google's daily limits.

- [How it works](#how-it-works)
- [Requirements](#requirements)
- [Setting it up](#setting-it-up)
- [Settings](#settings)
- [Keeping costs low, or free](#keeping-costs-low-or-free)
- [Privacy](#privacy)
- [Troubleshooting](#troubleshooting)

---

## How it works

Each question goes through four steps:

1. **Understand the question.** A small, cheap Gemini model (the *planner model*) works out what the
   question is about: one person, how two people are related, a surname, or something general. It
   also works out who "he", "her" or "this person" means, using the recent conversation and the page
   the visitor is looking at.

2. **Look it up in webtrees.** The module searches your tree's name index, including middle names,
   married names and other names, and names that sound the same ("Smyth" finds "Smith"). It uses
   webtrees' own privacy rules, so it only ever sees what the person asking could see on the website.
   If several people match, it collects a short list so the AI can ask which one was meant.

3. **Write a fact sheet.** The person's facts, parents, siblings, spouses and children are written out
   in short, plain English, with a link to each person's page. This is far smaller than raw GEDCOM,
   so answers are faster, cheaper and more accurate.

4. **Write the answer.** The *answer model* writes a friendly reply from the fact sheet alone. It is
   told not to guess, to say when something is private, and to link names to their pages.

Around these steps:

- **Answers are cached.** When a visitor who isn't signed in asks a question that has been asked
  before, the saved answer is reused for 12 hours. That costs nothing and doesn't count toward the
  daily limit.
- **Limits cap costs.** A daily limit for the whole site, and an hourly limit per person, stop
  anyone running up a bill. Near the daily limit, a cheaper model takes over.
- **Everything is logged.** Questions, answers, token counts and errors go to `data/chatbot/logs/`,
  which webtrees protects from the web.
- **The conversation follows the visitor.** It continues as they move between pages, and ends when
  they close the browser tab.

---

## Requirements

- webtrees 2.1 or later (tested with 2.1.27), on PHP 7.4 or later, with the PHP `curl` extension
  (most hosts have it).
- A Google Gemini API key. See [step 2](#2-get-a-gemini-api-key).

---

## Setting it up

### 1. Install the module

Copy the `chatbot` folder into your webtrees `modules_v4` folder:

```
webtrees/
└── modules_v4/
    └── chatbot/
        ├── module.php
        ├── ChatbotModule.php
        ├── src/
        └── resources/
```

webtrees finds it automatically. To uninstall, delete the folder.

### 2. Get a Gemini API key

1. Go to [aistudio.google.com/app/apikey](https://aistudio.google.com/app/apikey) and sign in with a
   Google account.
2. Click **Create API key**, and copy the key (it starts with `AIza`).
3. Decide between the free and paid tiers. See [Keeping costs low, or free](#keeping-costs-low-or-free).

### 3. Configure it

Go to **Control panel → Modules → All modules → Family tree chatbot** (the wrench icon).

1. Paste the API key into **API key** and click **save**. The box looks empty afterwards; the key is
   saved but never shown again.
2. Leave **Who can use the chatbot** on **Administrators only** for now.

### 4. Test it

Visit any page of your website while signed in as an administrator. Click **Ask the family tree**,
ask about someone you know is in the tree, then ask a follow-up. If anything goes wrong, see
[Troubleshooting](#troubleshooting).

### 5. Open it up

Back in the settings, choose who can use it:

| Setting | Who sees the chat button |
|---|---|
| Everyone | All visitors, signed in or not |
| Signed-in users only | Only people with an account on your site |
| Administrators only | Only you, for testing |

To turn it off completely, disable the module in **Control panel → Modules → All modules**.


---

## Settings

| Setting | Default | What it does |
|---|---|---|
| Who can use the chatbot | Administrators only | See [step 5](#5-open-it-up). |
| API key | – | Your Gemini key. |
| Answer model | `gemini-3.1-flash-lite` | Writes the answers. |
| Planner model | `gemini-2.5-flash-lite` | Works out what each question is asking. A small model is enough. |
| Fallback model | `gemini-2.5-flash-lite` | Replaces the answer model when the daily limit is nearly reached. |
| Questions per day | 500 | For the whole site. When it is reached, the chatbot asks people to come back tomorrow. Cached answers don't count. 0 means no limit. |
| Switch to the fallback model at | 80% | Share of the daily limit after which the fallback model is used. 100 means never. |
| Questions per person per hour | 20 | Stops one person, or a script, using up the daily limit. Administrators are not limited. 0 means no limit. |
| Name | Family History Assistant | Shown at the top of the chat panel. |
| Greeting | (a short welcome) | The first message people see. |
| Keep the chat log for | 180 days | 0 turns logging off. |

The settings page also shows how many questions have been answered today.

**Model names change.** Google releases new models and retires old ones, and a retired model makes
every question fail. Check [Gemini models](https://ai.google.dev/gemini-api/docs/models) and
[deprecations](https://ai.google.dev/gemini-api/docs/deprecations) now and then, and change the model
names on the settings page if needed.

---

## Keeping costs low, or free

### What a question costs

A typical question uses about **4,000 tokens in and 400 out**, across the two Gemini calls. A token is
roughly ¾ of a word. Prices change, so check [Gemini pricing](https://ai.google.dev/gemini-api/docs/pricing).
At the paid-tier prices in October 2026:

| Setup | Planner / answer model | Cost per 1,000 questions |
|---|---|---|
| Cheapest | 2.5 Flash-Lite / 2.5 Flash-Lite | about $0.56 |
| Default | 2.5 Flash-Lite / 3.1 Flash-Lite | about $1.34 |
| Best quality | 3.1 Flash-Lite / 3.6 Flash | about $3.80 (about $7.15 from January 2027) |

Monthly cost by traffic:

| Questions per day | Cheapest | Default | Best quality |
|---|---|---|---|
| 20 | $0.35 | $0.80 | $2.25 |
| 100 | $1.70 | $4 | $11 |
| 500 | $8.40 | $20 | $57 |

Your **worst case** is the daily limit, every day:

> worst-case monthly cost ≈ questions per day × 30 × cost per question

With the defaults (500 a day, default models), that is about **$20 a month**, however busy the site gets.

### Option 1: no cost (Google's free tier)

The free tier costs nothing, but has two catches:

- **Google may use what is sent to improve its products.** That means visitors' questions, and the fact
  sheets about the people asked about. For visitors' questions, webtrees privacy removes living people
  before anything is sent. Signed-in members can see living relatives, so their questions can send those
  details to Google. On the free tier, think twice before choosing **Signed-in users only**.
- **There is a daily request limit** for each model, per Google Cloud project. See your limits in AI Studio
  (*Usage and limits*). When a limit is reached, the chatbot stops answering until Google resets it, at
  midnight Pacific time.

To stay within the free tier:

1. Use free-tier models for **all three** model settings, e.g. `gemini-2.5-flash-lite`.
2. Each question makes **two** requests, one to the planner model and one to the answer model. Set
   **Questions per day** to no more than **half** your smallest daily request limit, so the chatbot
   says "come back tomorrow" politely, rather than failing.
3. Set **Switch to the fallback model at** to 100, if the fallback is the same model.
4. Don't add a billing account to the key's Google Cloud project. Without one, you cannot be charged.

### Option 2: minimal cost (paid tier)

The paid tier doesn't use your data to improve Google's products, and has much higher limits. To keep
it cheap:

- **Set the daily limit to what you can afford.** It is the one setting that guarantees a maximum bill.
- **Keep the planner and fallback models on the cheapest Flash-Lite model.** The planner only needs to
  read a question.
- **Use Flash-Lite for answers.** Only move to a Flash model if answers aren't good enough.
- **Keep the hourly limit on.** It stops one person or script using up the day's questions.
- **Consider "Signed-in users only".** Far fewer people can ask, and bots can't.
- **Set a budget alert** in [Google Cloud Billing](https://console.cloud.google.com/billing). An alert
  only emails you; it doesn't stop spending. The daily limit is what stops it.
- **Check the log.** Every entry shows the tokens used, so you can see what questions really cost.

### Already built in

- **Short fact sheets** instead of raw GEDCOM: several times fewer tokens per question.
- **Thinking kept to the minimum.** Gemini bills "thinking" as output, and these questions don't need it.
- **Cached answers** for repeated visitor questions: free.
- **A cheaper model near the daily limit.**
- **Short candidate lists** when a name is ambiguous, instead of full details of everyone with that name.
- **Limited history**: only the last six messages, and questions of at most 500 characters.
- **Bots are blocked before they reach the chatbot.** webtrees blocks known bad bots, and every question
  needs a valid page token, so a script can't ask questions without loading the website.

---

## Privacy

- The chatbot sees only what the person asking could see on the website. This uses webtrees' rules for
  living people, restricted records (`RESN`), private facts, and the privacy settings for each tree and
  each user.
- Visitors' and members' answers are never mixed. Only visitors' answers are cached.
- The API key is stored in the webtrees database. It is never shown on the settings page, and is sent to
  Google in a request header, not the URL.
- Questions and answers are sent to Google to be answered. On the free tier, Google may use them to
  improve its products; on the paid tier, it doesn't.
- The log is in `data/chatbot/logs/`, which webtrees blocks from the web. Old logs are deleted
  automatically, after the number of days you choose.

---

## Troubleshooting

Start with the newest entries in `data/chatbot/logs/chat-YYYY-MM.log`. Each entry looks like this:

```
2026-10-19 14:02:11 UTC | Tree: Kelleys Island | User: visitor | Intent: person: Louis Beatty | Model: gemini-3.1-flash-lite | Tokens: 3810
Q: Tell me about Louis Beatty
A: ...
```

**Intent** shows what the chatbot looked up, and **[planner failed: …]** appears if the first Gemini call
failed.

| Problem | Likely cause and fix |
|---|---|
| No chat button | No API key has been saved; the access setting excludes you; or you are on a page outside a tree (the button only shows on tree pages, never in the control panel). |
| "I'm having trouble answering right now" | The log shows `[ERROR] HTTP …`. **400 "API key not valid"**: re-enter the key. **404**: the model name is wrong or retired, so change it. **429**: a Google limit was reached; see the free tier notes. |
| "Your session has expired" | The visitor's webtrees session timed out. Reloading the page fixes it. |
| It can't find someone who is in the tree | Check the log's **Intent** line. If it shows `[planner failed: …]`, the error explains why (often a retired planner model). If it shows the right name, check that the person can be seen by whoever asked, i.e. they aren't living, restricted or private. |
| "I've answered all the questions I can for today" | The daily limit was reached. Raise **Questions per day**, or wait until midnight (in the website's time zone). |
| "You've asked a lot of questions in the last hour" | The hourly limit per person. Raise or turn off **Questions per person per hour**. |
| Answers are vague or miss details | Try a stronger **Answer model**, e.g. a Flash model instead of Flash-Lite. The fact sheet only includes facts recorded in webtrees; sources and media are left out. |

---

*Michael Beatty — [github.com/MABeatty1978/WebtreesChatBot](https://github.com/MABeatty1978/WebtreesChatBot)*
