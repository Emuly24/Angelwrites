# AngelWrites program plan (wishes A to Q)

Status: **plan only, no feature code.** Written 13 November 2026 against `main` at `66c58af`.
**The owner's decisions are recorded in section 6 and take precedence over the defaults elsewhere.**
Nothing in this document is built until the owner approves it. Wish R (upload limits) is built
separately in PR #34. Wish S (Bible) has its own plan, `docs/BIBLE_PLAN.md`.

Facts marked **(checked)** were verified in the repository. Facts about the live server and the
hosting account are marked **(not verified)**: I cannot see them from here.

---

## Rules for the whole program

These apply to every phase and are repeated in each PR description.

1. One branch per feature. One commit per logical change. One PR per phase. The owner merges;
   nobody pushes to `main`.
2. No regressions. Every phase ends with a sweep of the pages it touched: PHP error sweep as
   guest, member and admin, and a browser pass at phone and desktop widths in light and dark.
3. Stay inside the budgets in section 2c. **No polling.**
4. Sanitise all user HTML on the server. Validate every upload through the shared helper.
   Enforce every access rule on the server (pseudonymity, private messages, team replies); the
   browser is never trusted to hide something.
5. Accessibility: WCAG 2.2 AA, full keyboard use, visible focus, `prefers-reduced-motion`.
6. No new dependency without the owner's approval. Prefer self-hosted code committed to the
   repository.
7. Wherever there is a choice between building and adopting, both are presented with a
   recommendation.
8. If a wish is unsafe, unlawful or would break the host's limits, the plan says so and proposes
   an alternative. Section 5 lists where that already applies.

---

## 1. Executive summary

### Honest effort

For one developer working steadily, with the owner reviewing and testing on an iPad between
phases:

| Scope | Estimate |
|---|---|
| Everything in A to Q, done properly | **6 to 7 months of build**, realistically **8 to 10 months** on the calendar once review rounds, testing and fixes are included |
| A solid v1 (Phases 0 to 3 below) | **10 to 12 weeks** |
| Bible v1 (separate plan) | 4 to 5 weeks, can run between phases |

The spread is driven by three features that are each a product in themselves: the community
with pseudonymous posting and moderation (D), messaging (I) and the reader (G). The rest is
weeks, not months.

### Top five risks

1. **Safeguarding.** Wish D invites people to post about trauma, depression and shame. That
   creates a duty of care that software alone cannot meet: someone must read the queue, there
   must be an answer for a person in crisis, and there must be a rule about under-18s. Without
   named people and a written policy, this feature should not launch. (Section 2b.)
2. **The hosting plan.** Free hosting has no cron, no backups we control, roughly 10 MB per file,
   a daily hit ceiling and terms that the provider can enforce by suspension. A site holding
   members' most private writing on a host with no backup is the largest single technical risk,
   and member-uploaded books (H) and video push directly against the limits. (Section 5.1.)
3. **Email is not reliable today.** Verification codes were not arriving on the live site this
   month. Messaging (I), mentions, newsletter confirmation (J), moderation alerts and team
   workflows (K) all assume email works. It must be fixed and monitored first.
4. **Security surface.** Roles and "reply as team" (K), a rich-text editor for members (N) and
   private content (D, I) are each a classic source of serious bugs: privilege escalation,
   stored XSS and leaked private data. They need the security model in 2d built and tested
   before the features that rely on it.
5. **Scope and regression.** The code base has large single files (`reader.php` 1,817 lines,
   `bible_reader.php` 1,970, `community.php` 887) and styling debt (74 `<style>` blocks and 739
   inline `style=` attributes, checked). Rewriting them all at once would break working pages.
   Every phase therefore changes pages incrementally and ends with a regression sweep.

### What I would cut or defer for a faster v1

| Defer | Why it is safe to defer |
|---|---|
| H as file upload (member books) | Highest legal and storage risk. Start with "suggest a book" (no file). See 5.2 |
| G v2 (offline reading, sync, export) | v1 already gives collections, bookmarks, notes and schedule |
| I extras (search, message requests) | Inbox, thread, block, report and the email nudge are the product |
| O's dedicated waveform window | The recorders work; make them reliable and small first |
| A fourth staff role ("support") | Three roles cover a team this size; the permission table allows more later |
| N on admin screens | Give members the new editor first; move the admin editors once it has proven itself |
| Lexical | Needs a build pipeline this project does not have |

---

## 2. Phase 0 artifacts

Phase 0 produces decisions and documents, plus three pieces of "keep the lights on" work:
make email reliable, make backups routine, and measure real hits per page. Each artifact below
is a draft for the owner to amend.

### 2a. Design system specification

**What exists (checked).** Tokens in `assets/css/style.css` (rose `#DBA1A2`, vanilla `#EFD8D6`,
fantasy `#F7F3ED` and their dark values). A shared layer, `assets/css/aw-design.css`, added in
PRs #28 and #29: gradients, easing, radii, layered shadows, focus ring, buttons, cards, page
header card, alerts, empty states, admin table actions, reduced-motion switch. A motion script,
`aw-motion.js`. The debt: pages still carry their own `<style>` blocks and inline styles, which
is why two pages can drift apart.

**Tokens to add** (extending, never replacing):

| Group | Tokens |
|---|---|
| Semantic colour | `--aw-danger #b4505a`, `--aw-success #4f8a63`, `--aw-warning #c9965a`, `--aw-info #8a6f86`, each with `-bg`, `-border` and `-ink` variants (the values PR #29 already uses, now named) |
| Space | `--aw-s-1` 4px, `-2` 8, `-3` 12, `-4` 16, `-5` 24, `-6` 32, `-7` 48, `-8` 64 |
| Type scale | `--aw-t-xs` 0.8rem to `--aw-t-3xl` clamp(1.75rem, 3.2vw, 2.5rem); body line height 1.7; headings Playfair Display, text Inter |
| Radius, shadow | Already present: `--aw-r-sm/-r/-r-lg`, `--aw-sh-1/2/3` |
| Layer | `--aw-z-header` 1000, `-drawer` 1100, `-sheet` 1200, `-toast` 3000 |
| Motion | `--aw-ease`, `--aw-spring`; durations 120ms (press), 250ms (hover), 400ms (enter) |

**Components.** Each is one PHP partial in `includes/ui/` plus one block of CSS, so markup cannot
drift. Each lists its variants and its states.

| Component | Variants | States |
|---|---|---|
| Button (`<button>`: does something) | primary, secondary, outline, quiet, danger; sizes sm/md/lg; icon-only (needs `aria-label`) | default, hover, focus-visible, active, disabled, loading (spinner + `aria-busy`), success (tick for 1.5 s), error (shake once, message beside it) |
| Link (`<a>`: goes somewhere) | inline, standalone with arrow, nav, card-link (whole card) | default, hover, focus-visible, visited (inline only), current page (`aria-current`) |
| Field | text, textarea, select, checkbox, radio, switch, file (with limit hint from the upload helper) | default, hover, focus, filled, invalid (message tied by `aria-describedby`), disabled, read-only |
| Card | content (poem, post, book, video, story), stat, action, person | default, hover (lift), focus-within, loading (skeleton), empty |
| Page header | centred, title-with-actions | — |
| Alert / toast | success, error, info, warning | inline or toast; toasts are `role="status"`, errors `role="alert"` |
| Empty state | with or without action | teaches what the area is for and offers the first step |
| Dialog and sheet | confirm, form, side sheet, bottom sheet on phones | focus trapped, Escape closes, focus returns to the opener |
| Tabs, chips, pagination | — | selected, focus, disabled |
| Badge | status (draft, published, waiting), count | never colour alone: icon or text as well |
| Media figure | image, audio, video; with caption | loading, failed (shows a retry link) |
| Skeleton | line, card, avatar | shown only when a wait exceeds 300 ms |

**The rule for wish A.** A link navigates; a button acts. "Every clickable text becomes a
button" would break keyboard and screen-reader behaviour (a link styled as a button is fine; a
`<button>` that changes the page is not). So the work is: find every clickable thing, decide
which of the two it is, and render it through the component. A grep-able rule keeps it that way:
no `onclick` on `div`, `span` or `p`; no `<a href="#">` used as a button.

**"Dynamic" means it reflects real state.** A button shows that it is working and that it
succeeded. A card shows the member's actual progress. A list updates in place after an action.
Motion supports that and is never decoration for its own sake.

**Motion guidelines.** Only `transform` and `opacity` are animated. Nothing loops near text
people are reading. Entrances happen once. Under `prefers-reduced-motion` everything is static
(already implemented in the shared layer; every new component must be covered by it).

**Accessibility rules.**
- Contrast 4.5:1 for text and 3:1 for large text and control outlines. Known gap: rose
  `#DBA1A2` on white is about 2:1, so rose is never used for body text or as the only fill
  behind white text; the deeper rose `#a8676a` is.
- Hit targets at least 44 by 44 CSS pixels (the wish's rule; WCAG 2.2 AA asks for 24).
- Every control reachable and operable by keyboard, in a sensible order, with a visible focus
  ring. No focus traps except inside open dialogs.
- Every form field has a visible label. Errors are announced and tied to the field.
- Images have useful `alt` text or are marked decorative. Media has captions or a transcript
  where speech carries meaning.
- Headings in order, one `<h1>` per page, landmarks present, language set (including `lang="ny"`
  on Chichewa passages).
- The accessibility page already says "partially conforms" (checked); it is updated at the end
  of each phase to list what has been fixed and what remains.

### 2b. Safeguarding and moderation policy (draft)

This is a draft for the owner and, ideally, someone with pastoral or counselling experience to
finish. **I am not a safeguarding professional or a lawyer.** Items marked ▲ need a human
decision before wish D can launch.

**Principles shown to every member.** No blaming, criticism or judgement. Respond with love,
respect, understanding and acceptance. Speak from your own experience. Do not give medical or
legal instructions. What is shared here stays here.

**Who can see what (pseudonymity, not anonymity).**

| Post visibility | Other members see | Admin team sees |
|---|---|---|
| Public, with my name | The post and the display name | Everything |
| Public, under a pseudonym | The post and the pseudonym only | The post, the pseudonym **and the real account** |
| Private to the team | Nothing | The post and the real account |

- The member is told this plainly at the moment of posting: "The AngelWrites team can see who
  you are, so we can keep everyone safe. Other members cannot."
- A pseudonym is chosen once per member (not per post), so readers can recognise a voice without
  learning who it is, and so one person cannot pose as many.
- A pseudonymous author **cannot be @mentioned by real name**; mentions use the pseudonym and
  resolve on the server. The mention picker never lists real names beside pseudonyms. Profile
  pages, search, "your contributions" lists and notifications are all checked for leaks; this
  gets its own test list.
- Identity is revealed to other members only by the author's own choice.

**Content warnings.** Categories flagged as sensitive (for example trauma, abuse, self-harm,
grief) require the author to tick the warnings that apply. Readers see the warning and choose to
open the post. Warnings are tags, so members can also choose never to be shown a topic.

**Crisis resources.** Shown on every sensitive category, on the posting form and on every post
carrying a sensitive warning: a short, calm block saying that this community is not an emergency
service, with who to contact right now.
- ▲ **The owner must supply verified, current Malawian contacts** (emergency number, a
  counselling helpline, a gender-based-violence line, a child helpline). I have deliberately not
  written any phone numbers into this plan: a wrong number in a crisis block is worse than none.
- The block also offers "Ask the team to pray with me" and "Book a free one-to-one", which the
  site already has.

**Reporting.** Every post, reply, message and profile has "This didn't feel kind or safe".
Reasons: unkind or judging, unsafe advice, harassment, private information shared, spam, I'm
worried about this person, something else. A report can be made without the reported person
ever knowing who made it. The reporter gets an acknowledgement at once and an outcome later.

**Triage states.** `new` → `acknowledged` → `in review` → `actioned` or `no action` → `closed`,
plus `escalated` at any point. Every change records who and when.

**Service levels** ▲ (these are proposals; they only mean something if people are named):

| Kind | First look | Resolved |
|---|---|---|
| Someone may be at risk of harm | within 2 hours in waking hours | same day |
| Harassment, or private information exposed | within 12 hours | 48 hours |
| Unkind or off-policy | within 48 hours | 5 days |
| Spam | within 48 hours | 5 days |

Because there is no cron, nothing can "escalate by timer". Instead: an email goes to the on-duty
moderators when a report is filed, the admin dashboard shows the oldest open report and its age
on every load, and "at risk" reports email the owner as well.

**Actions available.** Add a content warning; hide pending review; edit out private information
(with a note); remove; send a private note to the author; restrict posting for a period; suspend.
Preference order is restorative: a private note first, removal when needed, suspension for
repeated or serious harm. The author can ask for a second look, which goes to a different
moderator, or to the owner.

**New posts in sensitive categories** are held for a first read before they are visible
(pre-moderation) until the team is confident; other categories publish at once.

**Audit log.** Append-only table: who did what to which item, when, and the reason. Moderators
see the log for items they handle; the owner sees all of it, including which staff member wrote
each "AngelWrites Team" reply. Nobody can edit or delete entries through the application.

**Staff conduct.** Access to private posts is on a need-to-know basis and every view of a
private post or a real identity is logged. Staff do not contact members outside the site about
what they read. Two people must agree before a member's identity is disclosed to anyone outside
the team, and only where the law requires it or life is at risk.

**Children.** ▲ The site currently has no age rule and date of birth is optional. A space for
sharing trauma needs a decision: adults only (recommended), or a supervised policy for
under-18s. If a child discloses abuse, the team needs to know in advance whom they tell.

**Data protection.** ▲ Posts about health, belief and personal trauma are sensitive personal
data. Malawi passed a Data Protection Act in 2024; I have not verified its current requirements
and the owner should confirm them (lawful basis, consent wording, a member's right to have
their posts deleted, breach reporting). The privacy policy must be updated before D or I launch.

**Retention.** A member can delete their own posts at any time. Removed content is kept, hidden,
for 90 days for appeals and then purged. Reports and the audit log are kept for two years.

### 2c. Performance and storage budgets

**Host limits (not verified; the owner should read them off the control panel):** about 50,000
hits a day where every file request counts; about 5 GB of disk; 30,019 inodes (files and
folders); about 10 MB per file and per upload; no cron; one SQLite writer at a time.

**Hit budget.** Fonts, Font Awesome and the editor load from public CDNs and do not count
against the host. Same-origin requests per page view:

| Page type | First visit | Return visit (cached assets) |
|---|---|---|
| Reading pages (home, poem, post, reflection, Bible) | at most 12 | at most 3 |
| Lists (books, poetry, blog, community) | at most 14, images lazy-loaded | at most 4 |
| Dashboards | at most 10, **no timers** | at most 3 |
| Reader | at most 12 to open, 1 per chapter | 1 per chapter |
| Any action (like, save, react) | 1 | 1 |

At those numbers 50,000 hits is roughly 4,000 first visits or 15,000 return page views a day.
Phase 0 measures the real figures for the ten most-visited pages and records them here, because
a budget nobody has measured is a guess.

How the budget is kept:
- **No polling.** Both dashboards still poll every 5 minutes while visible (checked:
  `AW_POLL_MS = 300000`), about 288 hits a day per open tab. Replace with: fresh data on load,
  and a refresh when the tab regains focus if more than 5 minutes have passed.
- One CSS file and one JS file site-wide where possible; page-specific script only where needed.
- Static assets carry a version in the URL and long cache lifetimes (to be confirmed that the
  host honours them).
- Images: lazy-loaded, sized, resized on upload (PR #34). Icons from the icon font, not files.
- Lists paginate on the server. No "load everything then filter".

**Weight budget.** HTML at most 60 KB; CSS at most 60 KB gzipped in total; JS at most 80 KB
gzipped on reading pages (editor and reader pages excepted); no image over 250 KB on a list
page; largest contentful paint under 2.5 s on a mid-range phone on 3G-class data, which is the
realistic audience.

**Database.** Writes are short and never sit inside a loop of emails or file work. Anything that
writes on every page view (view counters, sessions) is batched or dropped. No write on GET for
guests.

**Storage budget.**

| Pool | Budget | Note |
|---|---|---|
| Code and vendor | 60 MB | 47 MB today including Bible files |
| Bible texts | 45 MB, 8 versions | See BIBLE_PLAN.md |
| Database | 200 MB alarm | Purge logs and view rows on a schedule the admin triggers |
| Member and admin media | **3 GB and 20,000 files** | The inode limit, not the gigabytes, is what runs out first |
| Books | 1 GB | About 10 MB each on this host |
| Headroom | 700 MB | Backups in transit, temp files |

**A problem with the per-member quota I was asked to build.** 500 MB per member cannot be
honoured for more than about six members at full use inside 3 GB. PR #34 implements the numbers
as requested, and they are one line each to change. Recommended: **100 MB per member in total**,
plus a site-wide alarm at 80% of the media pool shown on the admin dashboard, and a hard stop at
95%. (Open question 9.)

**Retention.** Voice comments and comment photos: kept while the comment exists, deleted with
it. Rejected or orphaned uploads: none are kept (PR #34). Recordings on unpublished drafts older
than 90 days: listed for the admin to clear. Logs (views, sessions, audit older than two years):
purged by an admin action, because there is no cron.

**Backups.** There is no automated backup today (not verified on the host side). Phase 0 adds an
owner-only "Download backup" (a consistent copy of the database, made with SQLite's backup
call), a reminder on the admin dashboard when the last backup is older than 7 days, and a
written restore procedure that has been tried once.

### 2d. Security model

**Roles.** Stored as one role per user plus a capability table, so capabilities can be granted
without inventing roles.

| Capability | Owner | Admin | Moderator | Member |
|---|---|---|---|---|
| Publish and edit site content (books, poems, blog, reflections, videos) | ✓ | ✓ | | |
| Send newsletters, manage subscribers | ✓ | ✓ | | |
| Reply to contact messages and team inbox as "AngelWrites Team" | ✓ | ✓ | ✓ | |
| Moderation queue: view reports, hide, warn, restrict | ✓ | ✓ | ✓ | |
| See the real identity behind a pseudonym | ✓ | ✓ | ✓ (logged) | |
| Read "private to the team" posts | ✓ | ✓ | ✓ (logged) | |
| Suspend or delete a member | ✓ | ✓ | | |
| Approve member-submitted books | ✓ | ✓ | | |
| Site settings, email settings, upload limits | ✓ | | | |
| Add or remove staff, change roles | ✓ | | | |
| View the full audit log, including who wrote each team reply | ✓ | | | |
| Download backups | ✓ | | | |

- There is exactly one owner. Ownership moves only by the owner's own action, confirmed by
  password.
- Nobody can change their own role or remove the last owner.
- A "support" role (inbox only) can be added later by granting two capabilities.

**"Reply as team".** Members see "AngelWrites Team". The database stores the staff member's id
with every reply, and the owner's audit view shows it. Staff see each other's names internally,
so two people do not answer the same message; a message can be assigned and shows "being
answered by …" to staff. Canned responses are stored per team and edited before sending. This
gives the community one consistent voice without hiding accountability from the owner.

**Staff accounts.** Minimum password length 12. Re-enter the password for dangerous actions
(role changes, deletions, backups, settings). Sessions for staff end after 12 hours idle;
"remember me" stays available to members. Second-factor sign-in for staff is recommended:
an authenticator-app code (TOTP) is about 100 lines with no external service and does not depend
on email; it is a new piece of security code and needs the owner's approval (open question 12).

**Every request.** CSRF tokens on every state-changing request (several admin pages still lack
them; closing that gap is part of Phase 2). Authorisation checked on the server at the top of
every handler, by capability, never by hiding a button. Object-level checks on every id taken
from the request ("is this *my* message, *my* draft, a post I may see?"). Rate limits on sign-in,
posting, reporting and messaging, kept in the database.

**User-written HTML.** Today, rich text is written only by admins and printed as stored
(checked: `blog_post.php` echoes `$post['content']`). The moment members get a rich editor, that
is a stored-XSS risk. The rule: **all rich text is sanitised on the server when it is saved and
again when it is rendered from older rows**, through an allow-list sanitiser.
- Adopt: **HTMLPurifier** (LGPL, pure PHP, no extensions, mature), committed under `vendor/`.
  About 2 MB and several hundred files, which costs inodes but nothing at run time on pages that
  do not use it; its cache directory must be writable or disabled.
- Build: a hand-written allow-list filter using `DOMDocument`. Smaller, but hand-written HTML
  sanitisers are where XSS bugs come from.
- **Recommendation: adopt HTMLPurifier.** Allow-list: paragraphs, headings 2 to 4, emphasis,
  lists, links (`http`, `https`, `mailto` only, `rel="noopener nofollow ugc"`), blockquote
  (including the Bible verse block), images and media **only from our own upload paths**, and no
  `style`, `class` outside a fixed list, `on*` attributes, `iframe`, `script` or `svg`.
- Plain-text fields (comments, messages, community replies in v1) stay plain text, escaped on
  output, with links made clickable on the server.
- A Content-Security-Policy header is added in report-only mode first, then enforced, as a
  second line of defence.

**Uploads.** Only through `includes/upload.php` (PR #34): type by content, size and length
limits, images re-encoded, generated file names, quotas, nothing executable in upload folders
(deny rules for PHP execution under `assets/uploads/`), refusals logged.

**Private data.** Private posts, messages and pseudonym mappings are never included in emails
(emails say "you have a new message" and link to the site), never in search results for the
wrong audience, never in exports except the member's own.

**Audit requirements.** Logged: sign-ins by staff, role changes, every moderation action, every
view of a private post or real identity by a moderator, team replies (author), settings changes,
backups, member deletion. Append-only; owner-readable.

### 2e. Dependency graph

Foundations first. An arrow means "must exist before".

```
Email delivery works and is monitored ─┬─► Newsletter subscribe (J)
                                       ├─► Notifications by email ─► Messaging nudge (I)
                                       └─► Moderation alerts (D), team workflow (K)

Backups ───────────────────────────────► anything that stores private writing (D, I)

Design system components (A, Q) ───────► Dashboards (E), cards (M, L), every new screen

Remove gamification (C) ───────────────► Dashboards (E), profile, reader v1 (G)

Notifications created reliably (F114) ─► @mentions ─► Community (D), help mechanics (B)
        (today: awNotify() exists and is called from 6 places; coverage is partial)

Roles, capabilities, audit log (K) ────► Moderation queue ─► Community (D)
                                       ├─► Team inbox and "reply as team"
                                       ├─► Book approval (H)
                                       └─► Messaging reports and blocks (I)

Reports table + block list ────────────► Community (D), Messaging (I)

HTML sanitiser ────────────────────────► Member rich editor (N) ─► Stories with formatting (D)
                                       └─► Bible verse picker in member editors (S)

Upload helper (R, PR #34) ─────────────► Capture (O), embedded media (P), member books (H)

Universal save table ──────────────────► Library (F) ─► Reader collections (G)
                                                      └─► Bible bookmarks in Library (S)

Site search (does not exist as one system) ─► Message search (I, deferred), Library search
Service worker rework ─────────────────► Reader offline (G v2), Bible offline (S)
```

Things the brief assumed that I could not find **(checked)**:
- **No in-app messaging code exists** on `main` or on any branch pushed to the repository, and
  there is no messages table (only `contact_messages`). If a brief or branch exists elsewhere, I
  need it before Phase 5 is planned in detail. (Open question 6.)
- **Notifications do get created**, but only in six places. The dependency is "complete the
  coverage", not "build from nothing".
- **The mail code sends through Gmail's SMTP** with `GMAIL_USERNAME` and `GMAIL_APP_PASSWORD`;
  the brief says Zoho. One of the two is out of date, and it matters for daily sending limits.
  (Open question 3.)

---

## 3. Phased roadmap

Each phase lists: goal, features, what it depends on, exit criteria, iPad tests and rollback.
Estimates are build time for one developer; add about a third for review and fixes.

### Phase 0: foundations and decisions (2 weeks)

**Goal.** Agree the rules, and make the site safe to build on.

**Work.** The owner answers the open questions. The five artifacts above are finalised. Email:
find out why messages fail on the live site (Admin → Settings → Email already shows the reason),
fix it, and put the "recent failed emails" count on the admin dashboard. Backups: owner-only
download, reminder, restore tried once. Measurement: record same-origin requests and page weight
for the ten main pages. Hosting decision (5.1).

**Depends on.** Nothing.

**Exit criteria.** A test email arrives in Gmail and in a second provider; a backup has been
downloaded and restored on a test copy; the measured hit table is filled in; the open questions
have answers.

**iPad tests.** Register a new account and receive the code within a minute. Download a backup
from the admin area.

**Rollback.** Documents only, plus two small admin additions that can be reverted on their own.

### Phase 1: a calm, consistent, professional surface (3 to 4 weeks)

**Goal.** The largest visible improvement for the least risk. No new data about members.

**Features.**
- **C** Remove gamification from every screen: levels, XP, streaks, challenges, badges,
  reputation scores (found in `dashboard.php`, `profile.php`, `community.php`, `book.php`,
  `about.php`, the reader's `reader_gamification.php` and `reader_challenges.php`, and the
  Bible reader's monthly challenge). Tables are left in place and simply no longer written or
  shown, so nothing is lost if the decision changes. Q&A "helpful" marks and accepted answers
  stay, shown as "3 people found this helpful" with no totals, ranks or leaderboards.
- **C replacement:** editorial curation, "Angella's picks" (see section 5.4).
- **A, Q** Component partials for button, link, field, card, alert and empty state; applied to
  the ten most-visited pages; the "link or button" audit on those pages.
- **M** One poem card partial used by the home page, `poetry.php`, search and the dashboard.
- **L** The home page's community and testimonial sections rebuilt as cards from the same
  system, server-rendered, rotating by page load (not by timer), each ending with an invitation
  into the community.
- **J** A persistent "Subscribe" control that reads the member's real subscription state from
  the database, subscribes in place with an inline confirmation, respects double opt-in
  ("check your inbox to confirm"), and disappears once subscribed. Guests see it tied to an
  email field; the state is never read from a cookie alone.
- **E** Member and admin dashboards rebuilt on the components: cards that reflect real activity
  (continue reading, your saved items, your open questions, what needs your attention), empty
  states that teach, skeletons only for slow parts, and no timers.

**Depends on.** Phase 0 (design system agreed; email working, for J's confirmation).

**Exit criteria.** No level, XP, streak, badge or challenge is visible anywhere to any role
(grep-backed list in the PR). The ten pages use the component partials and pass the keyboard and
contrast checks. Dashboards make no request after load until the user acts. Subscribe state
survives a new browser and a different device. Hits per page are at or under the budget table.

**iPad tests.** Sign in as a member with past activity: no streak or level anywhere; dashboard
shows real items; leave it open for ten minutes and confirm no network activity. Subscribe from
the footer, see the inline confirmation, confirm by email, reload: the control is gone. Compare
a poem card on the home page and on Poetry: identical.

**Rollback.** Each feature is its own PR. Gamification removal is display-only, so reverting
restores it with data intact.

### Phase 2: the trust layer (4 weeks)

**Goal.** Everything the community and messaging will stand on. Little that members see; much
that keeps them safe.

**Features.**
- **K** Roles and capabilities, the owner's staff screen, audit log, team inbox with "AngelWrites
  Team" replies, assignment, canned responses. CSRF closed on the remaining admin forms.
- **Notifications** completed: every event that should notify does (replies, mentions, team
  replies, report outcomes, approvals), each with an in-site notification and an optional email,
  governed by per-member preferences and a "no more than one email an hour per kind" rule.
- **@mentions** as one shared function: typed as `@name`, resolved on the server to a user id at
  save time, stored as ids (so renames do not break them), rendered as links, pseudonym-safe.
- **Reports and blocks**: one `reports` table and one moderation queue for every content type;
  one `blocks` table honoured everywhere.
- **Sanitiser** (HTMLPurifier, if approved) wired into every place rich text is saved or shown.
- **N for members**: the chosen editor (section 5.5) in a light configuration, behind the
  sanitiser.

**Depends on.** Phase 0 (security model agreed, email working). Phase 1 components.

**Exit criteria.** A moderator cannot reach settings, roles or backups by URL. A member cannot
reach any staff endpoint. Every team reply shows the team name to the member and the author to
the owner. Mentions notify exactly once and never reveal a real name behind a pseudonym. A test
set of 40 XSS payloads saved through the editor renders inert. Every moderation action appears
in the audit log.

**iPad tests.** As owner, add a moderator; as that moderator, reply to a contact message and see
"AngelWrites Team" on the member's side; as owner, see who wrote it. Mention a member in a
comment and receive the notification on their account. Report a comment and follow it through
the queue.

**Rollback.** Roles ship behind a switch: with the switch off, the existing admin check applies.
The schema additions are new tables only; nothing existing is altered.

### Phase 3: community, stories and help (5 to 6 weeks)

**Goal.** Wishes D and B, on the trust layer.

**Features.**
- **D content model.** One `community_posts` table replacing the questions-only model:

  | Field | Values |
  |---|---|
  | `type` | question, story, experience, request for help |
  | `visibility` | public-named, public-pseudonym, team-only |
  | `category` | one life area (list below) |
  | `content_warnings` | zero or more tags |
  | `status` | draft, held for review, published, hidden, removed, closed |
  | `author_id` | always the real account, never exposed for pseudonymous posts |
  | `title`, `body` | body is sanitised rich text, or plain text in v1 |

  Existing questions migrate as `type = question`, `public-named`.
- **Categories** (the owner's list plus proposals): trauma, relationships, marriage and family,
  parenting, fear and anxiety, depression, grief and loss, shame and guilt, anger, trust,
  loneliness, addiction and recovery, illness and caregiving, money and work, faith and doubt,
  forgiveness, identity and self-worth, gratitude and testimony, other. Sensitive ones carry the
  crisis block and pre-moderation.
- Pseudonyms, content warnings, crisis block, moderation queue and the culture statement, as in
  2b. Replies are supportive by design: the reply box prompts "What helped you? What would you
  want to hear?" and offers gratitude replies ("Thank you, this helped").
- **B help mechanics.** One entry point in the main navigation, "Get help · Offer help". On a
  request for help, an "I can help" action opens a kind reply or a private message (once
  messaging exists). Members may list "areas I can help with" on their profile, opt-in, which
  powers "people who have been through this" on a post, with no scores or rankings.

**Depends on.** Phase 2 entirely. Safeguarding policy signed off with named moderators and
crisis contacts (2b ▲ items). Privacy policy updated.

**Exit criteria.** The pseudonymity test list passes (profile, search, mentions, notifications,
emails, exports, page source, API responses). Team-only posts are invisible to every member by
every route. Sensitive categories show warnings and crisis resources and are held for review.
Reports reach the queue and the outcome reaches the reporter.

**iPad tests.** Post a story under a pseudonym; from a second member account try to discover the
author through profile, search and mention; fail. Post a team-only request and confirm only
staff see it. Open a sensitive post and see the warning first. Report a reply.

**Rollback.** The new model sits beside the old Q&A during the phase; the old pages are retired
only after migration is verified. A switch hides the new section.

### Phase 4: library and reader v1 (4 to 5 weeks)

**Goal.** Wishes F and G (v1).

**Features.**
- **Universal saves.** `saves(user_id, item_type, item_id, collection_id, created_at)` and
  `collections(id, user_id, name, position)`. One "Save" control on poems, stories, experiences,
  blog posts, reflections, videos, books, Bible verses and groups.
- **F Library** becomes: Saved (by collection), Continue reading (links straight into the
  reader), Groups you can join, Your contributions. Reading progress moves out of the library
  into the reader.
- **G Reader v1.** The reader's own library with collections; bookmarks; highlights and notes
  (existing ones kept); a reading schedule as planned reads the member sets by hand, with an
  optional reminder shown, and optionally emailed, **when they next sign in** (no cron);
  typography and themes shared with the Bible focus mode. `reader.php` is split one concern at a
  time (settings, navigation, notes, schedule), each behind the same URL, never as a rewrite.
- "Download" and "offline" are defined: **download** is a file the member keeps (only for books
  the rights allow); **offline** is the app keeping recent pages on the device (v2).

**Depends on.** Phase 1 (gamification removed from the reader). Design system.

**Exit criteria.** Anything saveable can be saved, filed and found again. The library shows no
progress bars. Reader v1 features work on a phone with one hand. Existing highlights, notes and
positions survive.

**iPad tests.** Save a poem, a reflection and a verse into a new collection; find them in the
library. Open a book from "Continue reading" and land on the right page. Plan a read for
tomorrow and see the reminder at next sign-in.

**Rollback.** Saves are new tables. Reader changes are incremental PRs, each revertible.

### Phase 5: messaging (3 to 4 weeks)

**Goal.** Wish I.

**Features.** Inbox, unread badge (counted on page load, not polled), thread view in brand
colours, plain text only. An email on a new message saying only "You have a new message on
AngelWrites" with a link, at most one per hour per member, and a "notify me" preference. Message
requests for people with no existing thread (accept or decline before anything is delivered).
Block and report, using Phase 2's tables. A member can message the team; staff answer as the
team. No attachments in v1. Search deferred.

**Depends on.** Phase 2 (roles, reports, blocks, notifications), working email, and the existing
messaging brief if there is one (open question 6).

**Exit criteria.** Nobody can read a thread they are not in, by any URL or id. A blocked member
cannot message or see presence. Email carries no message content. Unread counts are correct
across devices.

**iPad tests.** Two accounts exchange messages; a third tries to open the thread by URL and is
refused. Block, then try to message. Receive the email nudge and follow the link.

**Rollback.** New tables and pages only; a switch removes the entry point.

### Phase 6: media, editor on admin screens, member books, reader v2 (4 to 6 weeks)

**Goal.** Wishes O, P, the rest of N, H and G v2.

**Features.**
- **O** One capture component for audio, image and video: audio in its own sheet with a live
  waveform (Web Audio `AnalyserNode` on a canvas), camera that fits every screen with front/back
  choice, preview and re-record before anything is used, a plain file field as fallback. Prefer
  MP4 recording where the browser offers it, and lower the recording bitrate so a two-minute
  clip fits the host's limit (today about 30 seconds of video fills 10 MB; found in PR #34).
- **P** One media figure component for images, audio and video in posts, reflections and
  newsletters, styled like poem covers, with an email-safe version tested in Gmail.
- **N** Move the admin editors to the chosen editor once it has run in production for members.
- **H** Member-suggested books (5.2): suggestion first; rights-cleared uploads with attestation,
  a review queue with reasons, notification to the member, a takedown route and a "Community
  books" shelf.
- **G v2** Offline reading of recent chapters, export of notes, sync refinements.

**Depends on.** PR #34 (upload helper), Phase 2 (roles for approval), Phase 4 (library shelves),
service worker rework for offline.

**Exit criteria.** Capture works on iOS Safari and Android Chrome, with fallbacks. Media in a
newsletter renders in Gmail. A member's book cannot reach a group without approval, and a
takedown removes it everywhere.

**iPad tests.** Record a voice note and watch the waveform; re-record; attach. Take a photo with
the back camera in portrait and landscape. Suggest a book and receive the decision.

**Rollback.** Per feature. Capture falls back to file fields with one switch.

### Where the Bible plan fits

`BIBLE_PLAN.md` phases B1 and B2 (clean foundation, focus mode) depend on nothing here and can
run straight after Phase 1. B3 (verse picker) follows the sanitiser in Phase 2 for member
editors, and can ship earlier for admin editors.

---

## 4. Open questions only the owner can answer

Each has a recommended default. "Agree" is a complete answer.

1. **Hosting.** Will the site stay on free hosting?
   *Default: move to a low-cost paid plan (a few dollars a month) before Phase 3. See 5.1.*
2. **Who moderates?** Names of at least two people besides the owner, and the hours they cover.
   *Default: wish D does not launch until two moderators are named and have read the policy.*
3. **Which mailbox sends the site's email: Gmail (as the code does now) or Zoho (as the brief
   says)?** And is the test email in Admin → Settings → Email arriving?
   *Default: keep one sender, whichever currently delivers; record its daily sending limit in
   this plan.*
4. **Crisis contacts.** Please supply verified Malawian numbers and services for the crisis
   block.
   *Default: until supplied, sensitive categories stay closed.*
5. **Minimum age.**
   *Default: 18 and over for the community and messaging; reading stays open to all.*
6. **The messaging brief.** I can find no messaging code or brief in the repository. Is there
   one elsewhere?
   *Default: Phase 5 is built from this plan.*
7. **"What they're saying".** The home page has two sections: "Real Stories. Real Hope."
   (testimonials) and "What the Community Is Saying" (recent comments). Which did you mean?
   *Default: both are rebuilt as one family of cards; the second invites visitors into the
   community.*
8. **Gamification data.** Keep the old tables unused, or delete them?
   *Default: keep for 90 days after removal from the screens, then delete with a backup.*
9. **Member storage.** 500 MB per member cannot fit the host.
   *Default: 100 MB per member in total, 50 MB a day, with a site-wide alarm at 80%.*
10. **The editor.** See 5.5.
    *Default: Quill 2 for members now; admin screens keep the current editor until Phase 6.*
11. **New dependencies requested:** HTMLPurifier (sanitiser) and Quill 2 (editor), both
    self-hosted and committed.
    *Default: approve both.*
12. **Second-factor sign-in for staff** (authenticator app).
    *Default: yes, required for the owner and admins, optional for moderators at first.*
13. **Pseudonyms.** One fixed pseudonym per member, or a new one per post?
    *Default: one per member, changeable once every 90 days, so trust can build without
    exposure.*
14. **Member books.** See 5.2.
    *Default: "suggest a book" only until hosting and a takedown procedure are in place.*
15. **Who may see real identities behind pseudonyms?**
    *Default: owner, admins and moderators, with every look logged and visible to the owner.*

---

## 5. Recommendations and disagreements

### 5.1 The host is the real constraint: recommend paid hosting before community and messaging

The brief says to design around the free host, and Phases 0 to 2 do. From Phase 3 onward I
recommend against it, for four reasons:

- **No backups we control** on a service that can suspend an account. The community and
  messaging will hold writing that people cannot recreate and would be harmed by losing or
  leaking.
- **No cron** means no reminders, no digests, no timed escalation of an at-risk report, no
  queue for email. Everything becomes "when someone next loads a page".
- **About 10 MB per file** already makes the requested video and book limits unreachable
  (PR #34 shows this on the new Uploads tab).
- **Terms of service.** Free hosts commonly forbid using the account for file storage or media
  hosting, and enforce hit limits by suspension. Member-uploaded books and video are exactly
  that pattern. I have not read InfinityFree's current terms; the owner should, before wish H.

A basic paid shared plan removes all four at a cost of a few dollars a month, and nothing in
this plan would need rewriting: it is still PHP and SQLite. If the answer is "stay free", the
plan still works, with H reduced to suggestions, video kept to YouTube links, and a weekly
manual backup as a standing duty.

### 5.2 Wish H as written is the riskiest item: start with "suggest a book"

Letting members upload books for groups means the site hosts and distributes files whose
copyright the uploader usually does not own. An attestation tick-box shifts little risk; the
site is still the publisher. Proposed order:

1. **Suggest a book** (no file): title, author, why, optional link. Admin approves it for
   groups. Members bring their own copy, as book clubs do. This delivers most of the wish.
2. **Public-domain and rights-cleared texts**: the admin adds the file (for example from a
   public-domain library), on a "Community books" shelf.
3. **Member uploads** only for work the member owns (their own manuscript), with attestation,
   review, a visible takedown route and the size and quota rules from PR #34. Only after the
   hosting question is settled.

### 5.3 Where I differ from the review notes

| Note | My view |
|---|---|
| A: "hit targets ≥ 44px" | Agree, and note it exceeds WCAG 2.2 AA (24px). Dense admin tables use 34px today; they should grow to 44 on touch screens |
| C: "2–3 professional replacements" | See 5.4. I would not add a member directory in this phase: a list of people is a privacy and safeguarding surface the trust layer should precede |
| D: "private (pseudonymous)" | These are two different things and the model should keep them apart: *pseudonymous* (public, name hidden) and *team-only* (not public at all). Section 2b separates them |
| D: "report anyone who made them feel bad" | Keep, worded as "this didn't feel kind or safe", with a restorative first response. Reports must never auto-hide content, or the report button becomes a weapon |
| D: tagging pseudonymous authors | Tag by pseudonym, resolved on the server. Disallowing mentions entirely would make pseudonymous members second-class |
| E: "audit found 60s polls" | Already reduced to 5 minutes and paused when hidden (checked). Remove the timer altogether anyway |
| G: reading reminders "on login" | Agree. Add that emailed reminders are sent at sign-in time too, since nothing can run on a schedule |
| I: "a separate brief may already exist" | Not in the repository. See open question 6 |
| K: four roles | Three are enough to start. The capability table makes a fourth cheap later |
| K: "depends on auth hardening in flight" | I see no open branch for it. The pieces that matter (CSRF on remaining admin forms, object-level checks, staff session rules) are folded into Phase 2 |
| N: "TinyMCE needs a paid plan" | Partly. See 5.5: the editor in use is the free open-source build; only premium plugins cost money |
| N: "evaluate Quill or Lexical" | Lexical is a poor fit: it needs a JavaScript build step this project does not have |
| Q: "accessibility page claims compliance" | Already corrected to "partially conforms" (checked). Keep it honest phase by phase |
| R: quotas | 500 MB per member exceeds the host. See 2c |

### 5.4 Replacing gamification: three options, one recommendation

| Option | What it is | Effort | Risk |
|---|---|---|---|
| **Editorial curation** | "Angella's picks": the team features a poem, a story and a reflection each week, with a sentence on why | Small | None: staff-chosen, no member comparison |
| **Collections** | Members build and optionally share shelves ("For hard nights", "Psalms that carried me") | Medium (arrives with Phase 4 anyway) | Low |
| **Opt-in "ways I can help"** | Members list life areas they are willing to support others in | Medium | Needs the trust layer first |

**Recommendation: editorial curation now**, because it replaces the "something is happening
here" feeling that streaks faked, with something true and human, at almost no cost and no way to
make anyone feel behind. Collections follow in Phase 4 and "ways I can help" in Phase 3.

### 5.5 The editor: build or adopt

What is there now (checked): **TinyMCE 6.8.3 loaded from the jsDelivr CDN.** Version 6 is
open source under the MIT licence and free to use; what costs money is TinyMCE's cloud service
and its premium plugins. From version 7 the licence changed to GPL. Version 6 will not receive
updates indefinitely.

| Option | For | Against |
|---|---|---|
| **Keep TinyMCE 6, skin it in brand colours** | No migration; admins know it; handles the book processor's complex content | Ageing version; heavy (over 400 KB); awkward on phones; too much editor for a member's story |
| **Quill 2** (BSD licence) | Small (about 60 KB gzipped), good on touch screens, no build step, easy to theme, clean output | Fewer features (tables are basic); needs a custom block for the Bible verse quote |
| **Lexical** (MIT) | Modern, accessible, extensible | Requires a bundler and framework-style code; no drop-in file |
| **Build our own** | Total control | Rich-text editing is famously hard (selection, paste, mobile keyboards, undo); months of work and a long tail of bugs |

**Recommendation:** adopt **Quill 2, self-hosted**, for everything members write (stories,
experiences, rich replies), in Phase 2 behind the sanitiser. Leave the admin screens on
TinyMCE 6 until Phase 6, then move them if Quill has served well; the book processor may keep
TinyMCE if it needs tables. One editor everywhere is the goal, but not at the price of breaking
the admin's daily tool on day one. Whatever is chosen, **the sanitiser comes first**: the editor
is a convenience, the sanitiser is the protection.

On "self-hosted": loading the editor from a CDN costs no hits against the host; self-hosting
costs a few per first visit. I still recommend self-hosting member-facing scripts, with long
cache lifetimes, so a third party cannot change or withdraw code that runs on pages holding
private writing.

### 5.6 Other things I would do differently

- **Fix email before any feature.** It is broken for real users now, and half of this plan
  assumes it.
- **Do not launch D and I together.** Each needs moderation attention in its first weeks.
- **Plain text first in the community.** Stories do not need formatting to help someone.
  Launching D with plain text plus paragraphs removes the XSS risk from the riskiest feature and
  lets the rich editor arrive once the sanitiser has been proven elsewhere.
- **Measure before optimising.** The hit budget should be checked against real counts from
  Phase 0, not estimated.
- **Write tests for the three things that must never break:** private posts stay private,
  pseudonyms stay pseudonymous, staff capabilities hold. These are scripted checks run before
  every merge, not manual clicking.

### 5.7 The five features I would build first

Assuming Phase 0's email fix and backups are done, these give the most professional feel for
the least effort and risk:

1. **Remove gamification and rebuild both dashboards (C, E).** The site immediately reads as a
   calm, adult place, and members stop being told they missed a target.
2. **One card and component system across home, poems and testimonials (A, M, L).** Visible
   inconsistency is what makes a site feel amateur; this removes the most-seen examples.
3. **Persistent newsletter subscribe with inline confirmation (J).** Small, finishes something
   already half-built, and grows the audience every day afterwards.
4. **The admin team with "AngelWrites Team" replies (K).** Members get answers when the owner is
   offline, which is what makes a community feel alive, and it is the foundation D and I need.
5. **Bible focus mode (S, from BIBLE_PLAN.md).** A signature, distraction-free experience open
   to every visitor, on public-domain text, with no dependency on anything else in this plan.

---

## 6. Decisions recorded (owner's answers, 13 November 2026)

These settle the open questions in section 4 and override any earlier default in this document.

| # | Question | Decision | What it changes |
|---|---|---|---|
| 1 | Hosting | **Stay on the free plan for now**; move to a low-cost paid plan later | Everything is built inside the free host's limits. A manual backup is provided instead of scheduled ones (PR #36) |
| 2 | Moderators | **Angella Bottoman (owner) and Blessings Emulyn** | Two people cover the queue. The service levels in 2b become targets, not promises; sensitive categories stay pre-moderated |
| 3 | Sending mailbox | **Zoho** (Gmail did not work) | PR #35 adds Zoho SMTP behind `MAIL_PROVIDER`; `.env` must set it to `zoho` |
| 4 | Crisis contact | **The owner's own phone number** was supplied | It is stored in site settings, not in this repository. Recommendation still stands: show Malawi's emergency and helpline numbers beside it, so one person is not the only line |
| 5 | Minimum age | **18 and over** for community and messaging | Sign-up for those areas asks for confirmation of age; reading stays open |
| 6 | Messaging brief | **None exists; build it** | Phase 5 is built from this plan |
| 7 | "What they're saying" | **The comments, and anything that pops up on the home page** | Phase 1 restyles the home page's comment carousel, testimonial cards, the join bar and the cookie notice as one family |
| 8 | Old gamification data | **Delete** | Phase 1 removes the screens, then drops the tables after a backup is taken |
| 9 | Member storage | **100 MB per member now, with a way to raise it** on a paid plan | Done in PR #34: the default is 100 MB and Admin → Settings → Uploads changes it without code |
| 10 | Editor | **Agreed:** Quill 2 for members; admin screens keep the current editor until Phase 6 | |
| 11 | New dependencies | **Approved:** HTMLPurifier and Quill 2, self-hosted | |
| 12 | Second-factor sign-in for staff | **Agreed** | Phase 2 |
| 13 | Pseudonyms | **A new pseudonym for each post** | Replaces "one per member" in 2b. Consequences accepted: readers cannot recognise a returning voice, and one person may appear as several. The team still sees the real account on every post, and per-member posting limits apply to the account, not the pseudonym. Replies by the author on their own post keep that post's pseudonym |
| 14 | Member books | **Agreed:** "suggest a book" only, until hosting and a takedown procedure are in place | |
| 15 | Who sees real identities | **Owner and admins only** | Moderators without the admin role do not see who is behind a pseudonym or read team-only posts. Blessings Emulyn therefore needs the admin role to share that work with the owner. The capability table in 2d changes accordingly |

### Phase 0 status

| Item | State |
|---|---|
| Email reliable | PR #35 (Zoho SMTP, two categories of email). Needs the `.env` values and the live test |
| Backups | PR #36 (manual backup, weekly reminder, restore steps; restore tried on a copy) |
| Upload limits and quotas | PR #34 |
| Unlicensed Bible texts | PR #37 |
| Existing waiting members verified | PR #33 |
| Measured requests per page | Below |
| Open questions | Answered above |

### Measured requests and weight (Phase 0, 13 November 2026)

Measured on a copy of `main` with sample content, phone width, first visit, scrolled to the bottom.
"Requests" are same-origin only (what the host counts); fonts, icons and the editor come from CDNs.

| Page | Requests | Transferred | HTML alone |
|---|---|---|---|
| Home | 7 | 367 KB | 90 KB |
| Books | 6 | 206 KB | 90 KB |
| Poetry | 7 | 194 KB | 57 KB |
| **A poem** | **23** | 303 KB | 94 KB |
| Blog | 6 | 195 KB | 78 KB |
| A blog post | 7 | 249 KB | 77 KB |
| Reflections | 6 | 203 KB | 87 KB |
| Bible reader | 7 | 259 KB | 145 KB |
| Login | 6 | 164 KB | 46 KB |
| Member dashboard | 6 | 179 KB | 61 KB |
| Library | 6 | 195 KB | 80 KB |
| Community | 6 | 185 KB | 69 KB |
| **Book reader** | 7 | 685 KB | **634 KB** |
| Admin dashboard | 5 | 158 KB | 51 KB |

What the numbers say:

- **Requests are already inside the budget** (12 on a first visit) on every page but one. The
  earlier worry about hits is mostly answered; the polling timers remain the thing to remove.
- **The poem page makes 23 requests**: one call to `ajax_get_reactions.php` for each of its 11
  reviews. Returning the reactions with the page, or in one call, brings it to 8. This is the
  single cheapest saving on the site and belongs in Phase 1.
- **HTML is heavier than the 60 KB budget almost everywhere** (46 to 145 KB), because each page
  carries its own large `<style>` block and inline script. Moving those into the shared files,
  which Phase 1 does page by page, is what brings the weight down.
- **The book reader sends the whole book in one 634 KB page.** Reader v1 (Phase 4) should load a
  chapter at a time.
