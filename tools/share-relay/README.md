# Link previews for WhatsApp, Telegram, X and the rest

## What was wrong

When a poem or book link was shared, most apps showed a plain link with no title or picture.
Testing the live site on 14 November 2026 showed why:

| App's link reader | What the free host sent it |
|---|---|
| Facebook (`facebookexternalhit`) | the real page, with title and picture |
| Google | the real page |
| WhatsApp, Telegram, X (Twitter), LinkedIn, Discord, Slack | a 862-byte "This site requires Javascript" browser-check page |

The free InfinityFree plan puts that browser check in front of every visitor it does not recognise,
including the picture. No change to the site's own code can switch it off.

The site's code also had problems even for Facebook, now fixed:
- poems sent the picture as WebP, at an address that changed on every visit;
- book pages never used their cover (they showed the logo).

Every poem and book now has a 1200×630 JPEG preview (`og_image.php`) at a stable address.

## Two ways to get pictures in WhatsApp

1. **Move to hosting without the browser check** (any low-cost paid plan). Previews then work
   everywhere with no relay. This is the lasting fix.
2. **Use this free relay** (about 10 minutes, no cost, works on the free plan). Shared links look
   like `https://angelwrites-share.<your-name>.workers.dev/p/12`. Apps read the poem's title,
   description and cover from the relay; people who tap the link are sent straight to the poem.

## Setting up the relay (Cloudflare Workers, free)

1. Create a free account at <https://dash.cloudflare.com/sign-up> (no card needed).
2. In the dashboard choose **Workers & Pages → Create → Create Worker**.
3. Name it `angelwrites-share` and press **Deploy**.
4. Press **Edit code**, delete what is there, paste everything from `tools/share-relay/worker.js`,
   and press **Deploy** again.
5. Copy the worker's address (for example `https://angelwrites-share.angella.workers.dev`).
6. Open it with `/p/1` on the end in your browser: you should land on poem 1.
7. On AngelWrites, go to **Admin → Settings → Link previews**, paste the address, and save.

From then on the share buttons (Facebook, X, WhatsApp, copy link, the poem reader's Share) use the
relay link. To stop using it, clear the field and save.

## Checking a preview

- WhatsApp keeps a preview for a while. To check, share a poem you have not shared before.
- Facebook: <https://developers.facebook.com/tools/debug/> shows exactly what Facebook reads, and
  "Scrape Again" refreshes it.

## Notes

- The relay reads only public pages and stores nothing. It caches pages for an hour and pictures
  for a month (a picture's address changes when its cover changes).
- It relies on the host continuing to let Facebook's reader through. If previews stop, the relay
  still sends people to the right page; moving hosting is the lasting fix.
- The free Cloudflare plan allows 100,000 requests a day.
