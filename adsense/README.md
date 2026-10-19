# Google AdSense for webtrees

Shows Google AdSense advertisements on your webtrees website.

The module adds Google's AdSense code to every public page. Which ads appear, and where, is
controlled in your AdSense account, for example with **Auto ads**.

- Works with any theme, so ads keep working if you change themes.
- Ads never appear in the control panel.
- Written for webtrees 2.1 (PHP 7.4 or later), and works with later versions.

- [Install](#install)
- [Set your publisher ID](#set-your-publisher-id)
- [ads.txt](#adstxt)
- [Notes](#notes)

---

## Install

1. Copy the `adsense` folder into your webtrees `modules_v4` folder:

   ```
   webtrees/
   └── modules_v4/
       └── adsense/
           ├── module.php
           ├── AdSenseModule.php
           └── resources/
   ```

2. webtrees finds it automatically. Check that it is enabled in
   **Control panel → Modules → Tracking and analytics**.

To uninstall, delete the folder.

---

## Set your publisher ID

**No ads are shown until you enter your publisher ID.** You don't need to edit any code.

1. In [AdSense](https://adsense.google.com), go to **Account → Settings → Account information**, and copy
   your **Publisher ID**. It looks like `pub-1234567890123456`.
2. In webtrees, go to **Control panel → Modules → Tracking and analytics → Google™ AdSense**
   (the wrench icon).
3. Enter the ID with `ca-` in front of it, e.g. `ca-pub-1234567890123456`, and save.

The box only accepts IDs in that format. To stop showing ads without uninstalling the module, empty the
box and save.

To check it is working, open any page of your website, view the page source (Ctrl+U in most browsers),
and search for `adsbygoogle`. You should find your `ca-pub-…` ID.

---

## ads.txt

### What it is

`ads.txt` is a one-line text file that tells advertisers who is allowed to sell ads on your website.
Google needs it: without it, AdSense shows an "Earnings at risk" warning and can limit or stop your ads.

**The module does not create or serve this file.** You must put it on your website yourself, once.

### Create it

The easiest way is to let AdSense write it for you:

1. In AdSense, go to **Sites**, find your site, and open its **ads.txt** status (or follow the
   "Earnings at risk" warning).
2. Click **Download** or copy the line it shows.

Or create it yourself: a plain text file, named exactly `ads.txt`, containing this one line, with your own
publisher ID:

```
google.com, pub-1234567890123456, DIRECT, f08c47fec0942fa0
```

| Part | Meaning |
|---|---|
| `google.com` | The ad system: Google AdSense. |
| `pub-1234567890123456` | **Your** publisher ID, without `ca-` in front. |
| `DIRECT` | You have a direct account with Google, rather than selling through a reseller. |
| `f08c47fec0942fa0` | Google's own ID. It is the same for everyone, so don't change it. |

Use a plain text editor such as Notepad, not a word processor, and save it as UTF-8 text.

> The `ads.txt` in this module's folder is the author's own file, included as an example. **Don't upload
> it as it is.** Replace the publisher ID with your own first, or ads on your site will not be authorized.

### Where it goes

It must be in the **top folder of your domain**, so it can be read at:

```
https://your-site.com/ads.txt
```

Putting it in the module folder, or anywhere else, does nothing.

| Where webtrees is installed | Where `ads.txt` goes |
|---|---|
| The domain's top folder, e.g. `https://your-site.com/` | The webtrees folder, next to webtrees' `index.php`. On most hosts this is `public_html/`. |
| A subfolder, e.g. `https://your-site.com/family/` | The domain's top folder (e.g. `public_html/`), **not** the webtrees folder. |
| A subdomain, e.g. `https://family.your-site.com/` | The top folder of the main domain, `https://your-site.com/ads.txt`. You can also put a copy in the subdomain's top folder. |

Upload it with your host's file manager or FTP, in the same way as the module.

### Check it

1. Open `https://your-site.com/ads.txt` in your browser. You should see your one line, and nothing else.
2. In AdSense, the **Sites** page shows the ads.txt status as **Authorized**. Google re-checks the file
   every few days, so it can take a while to update.

If you see a webtrees page or "page not found" instead, the file is in the wrong folder.

---

## Notes

- **Approval.** Your site must be added and approved in AdSense (**Sites → Add site**) before ads appear.
  The code this module adds is what Google looks for when it checks your site.
- **Do Not Track.** Unlike webtrees' built-in analytics modules, this module ignores the browser's
  "Do Not Track" setting. Google doesn't support it, and browsers have deprecated it.
- **Privacy laws.** In the UK, the EU and some other places, visitors must agree to personalised ads
  before they are shown. Google provides a consent message for this, under
  **Privacy & messaging** in AdSense.
- **Ad blockers.** Visitors with ad blockers won't see ads. That's normal.

---

*Michael Beatty*
