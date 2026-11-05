# 14. Community Q&A

> Test method as in `01-reader.md`. Env B seed: question 1 by user 3 ("How do I start a prayer journal?") with one answer by user 2. User 2 then asked question 2.

## Files in scope

| File | Lines | Role | Covered below? |
|---|---|---|---|
| `community.php` | 851 | Q&A listing, single question view, ask, answer, accept, upvote, tags, reputation sidebar, report modal | Y |
| `community_edit.php` | 130 | Edit own question | Y |
| `community_delete.php` | 112 | Delete own question (`GET ?confirm=`) | Y |
| `community_report.php` | 60 | JSON endpoint: report a question/answer | Y |
| `community_answer_edit.php` | 82 | Edit own answer (keeps edit history) | Y |
| `community_answer_delete.php` | 75 | Delete own answer (`GET ?confirm=`) | Y |
| `admin/manage_community.php` | 177 | Admin: handle reports (dismiss, delete content) | Y |
| `admin/manage_questions.php` | 405 | Admin: question list, bulk delete, mark answered | Y |

## User journeys

### Journey 1: Visitor browses the Q&A
1. `community.php` as a guest lists questions with search, filter, sort and tags **[confirmed]** in both environments.
2. Logged in: Env A **[fatal] [confirmed]** *no such table: user_reputations* (`community.php:519`, the reputation sidebar). **Every member sees a broken page; only guests can browse.** Env B renders **[confirmed]**.
3. Single question `community.php?id=1`: question plus answers, correct page title **[confirmed]**. No overflow at 768/375 px **[confirmed]**.

### Journey 2: Member asks a question
1. Title, body, comma-separated tags → submit.
2. Question **saved**, tags created (`tags`, `question_tags`), then `community.php:108` `sendEmail()` → **SMTP dump** **[wrong] [confirmed]**.

### Journey 3: Member answers a question (core action)
1. Types an answer → submit.
2. Answer **saved**, `answers_count` incremented, then **[fatal] [confirmed, schema-independent]** *Call to undefined function awardReputation()* (`community.php:137`). `awardReputation()` isn't defined in any file.
3. Reloading shows the answer. The member saw a fatal error, so they'll likely answer again.

### Journey 4: Asker accepts an answer / anyone upvotes
1. Accept (`accept_answer`, by the asker): `is_accepted` set, then **[fatal] [confirmed]** at `:58`, same undefined function. The JS expects JSON and gets an HTML error, so no visual change.
2. Upvote (`upvote_answer`): `upvotes + 1`, then **[fatal] [confirmed]** at `:182`.
3. **[wrong] [inferred, code-verified]** Upvotes aren't tracked per user. `UPDATE answers SET upvotes = upvotes + 1` runs on every request (`:175`), so one member can upvote the same answer without limit, including their own.

### Journey 5: Member edits or deletes their own question
1. **[missing] [confirmed]** `community.php` has **no link** to `community_edit.php` or `community_delete.php` (it links only answer edit/delete at `:349-350`). The question edit/delete pages exist, render and work when the URL is typed **[confirmed]**, but members can't reach them.
2. Non-owners are redirected away from the edit page **[confirmed]**.
3. **[wrong] [confirmed] Delete by GET:**
   - `community_delete.php?id=2&confirm=1` deleted question 2 immediately; `community_answer_delete.php` works the same way.
   - No POST and no CSRF token, so a link or `<img src>` in any page the member visits can delete their content.
4. Edit/delete both email the admin (`community_edit.php:55`, `community_delete.php:44`), so SMTP dumps follow **[inferred, same pattern]**.

### Journey 6: Member edits or deletes their own answer
1. Links shown on own answers **[confirmed by code]**. Edit page renders, keeps history (`answer_edit_history`) **[confirmed render]**. Delete page renders "Are you sure…" **[confirmed]**.
2. Same GET-confirm delete issue as Journey 5.

### Journey 7: Member reports a question or answer
1. The report modal posts to `community_report.php` (`community.php:687`). The report **is saved** (`reports` row confirmed), then `:58` emails the admin, so the response is the SMTP dump instead of JSON and the modal can't show success **[wrong] [confirmed]**.
2. Opening `community_report.php` directly prints PHP warnings (*Undefined array key "target_type"/"reason"*) then JSON **[confirmed]**. Harmless, but it's an unguarded endpoint.
3. Guests are rejected (`community_report.php:6` checks login) **[inferred, code-verified]**.

### Journey 8: Admin moderates
1. `admin/manage_community.php`: Env A **[fatal] [confirmed]** *no such table: reports*. Env B lists the report ("spam") **[confirmed]**, with dismiss and delete-content actions.
2. `admin/manage_questions.php` lists questions, bulk delete (transaction) and mark answered/unanswered **[confirmed: renders]**.
3. **[quality]** Two separate admin screens for the same content (reports in one, questions in another). Answers have no admin list at all.

## Quality problems independent of the bugs above
- **The core loop (ask → answer → accept) crashes at every step** while silently succeeding. Members see errors and repeat actions, creating duplicates.
- **Reputation is shown** (sidebar, points) **but never awarded** (the function doesn't exist). It shows "0 points" forever, or a fatal error.
- **No notification** to the asker when someone answers. The code tries to email the author (`community.php:153-156`), which fails like all email, and there's no on-site notification (domain 11).
- **Layout fine on phone** (no overflow) **[confirmed]**.

## Root causes
- **[code]**
  - `awardReputation()` undefined (3 call sites).
  - Email inline in 6 actions.
  - GET-based deletes without CSRF.
  - Unlimited upvotes.
  - Missing edit/delete links for questions.
  - Unguarded report endpoint warnings.
- **[schema]** `user_reputations`, `reports`, `tags`, `question_tags`, `answer_edit_history` aren't created by the repo.
- **[ux]** No answer notifications, split admin tools, reputation shown but not real.
- **[infra]** SMTP.

## What a professional version looks like
- **J2/J3:** Posting shows the new question or answer in place with "Posted". The asker gets an on-site notification "Grace answered your question".
- **J4:** Accept shows a green "Accepted" badge immediately. Upvote is a toggle (one per member, not on own answers) showing the new count.
- **J5/J6:** Each own post has an "⋯" menu with Edit / Delete. Delete asks for confirmation and uses POST with a token.
- **J7:** Report → "Thanks, we'll review this." Admin sees reported items with the content inline and Keep / Remove buttons.
- **Reputation:** either a real points rule, implemented once and shared, or removed from the UI.

## Dependencies before this can be fixed
1. Decide whether reputation exists. If yes, define `awardReputation()` and confirm `user_reputations` exists (**create manually** if not; SQLite `data/site.db`, not phpMyAdmin; see 01). If no, remove the 3 calls and the sidebar.
2. Email strategy (domain 16).
3. Notifications (domain 11) for "someone answered".

## Blast radius
- **Domain 11 (dashboard/profile):** reputation and points shown there; notifications.
- **Domain 9 (book detail) / domain 1 (reader):** also call or display reputation (`book.php` guards with `function_exists`).
- **Domain 15 (comments):** answers are effectively a comment system with their own votes.
- **Domain 16 (email).**

## Effort
| Journey | Effort | Why |
|---|---|---|
| J1 browse (member) | S | Guard or create the reputation sidebar query. |
| J2 ask | S | Non-fatal email. |
| J3 answer | S | Define or remove `awardReputation()`. |
| J4 accept/upvote | S–M | Same fix plus per-user vote tracking (needs a votes table). |
| J5/J6 edit/delete | S | Add links; switch deletes to POST + token. |
| J7 report | S | Non-fatal email; input validation. |
| J8 admin | M | Combine reports and content moderation into one queue. |

## Regression check against AUDIT.md
- `community.php:137` / `:58` / `:182` undefined `awardReputation()`: **Yes**, all three confirmed.
- `community.php:108` `sendEmail()` → `die()`: **Yes.**
- `community.php:519` `user_reputations` missing [schema]: **Yes** (Env A).
- `admin/manage_community.php` `reports` missing [schema]: **Yes** (Env A).

## Coverage matrix
| File | Status | Where |
|---|---|---|
| `community.php` | COVERED | Journeys 1–4, 7 |
| `community_edit.php` | COVERED | Journey 5 |
| `community_delete.php` | COVERED | Journey 5 |
| `community_report.php` | COVERED | Journey 7 |
| `community_answer_edit.php` | COVERED | Journey 6 |
| `community_answer_delete.php` | COVERED | Journey 6 |
| `admin/manage_community.php` | COVERED | Journey 8 |
| `admin/manage_questions.php` | COVERED | Journey 8 |
