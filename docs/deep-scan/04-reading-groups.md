# 04. Reading groups

> Test method as in `01-reader.md`. Env B seed: group 1 "Grace Readers" for book 1, created by user 3, invite code `ABCD1234`. User 2 = ordinary member, user 1 = admin.

## Files in scope

| File | Lines | Role | Covered below? |
|---|---|---|---|
| `groups.php` | 330 | "My Reading Groups" list + join-by-code modal | Y |
| `group.php` | 919 | Single group page: discussions, notes, progress, members, schedule, activity, edit | Y |
| `create_group.php` | 247 | Create-group form | Y |
| `group_ajax.php` | 124 | Member-facing AJAX (join, discussion, note, progress, schedule, reaction) | Y |
| `includes/reading_groups.php` | 424 | Data functions for groups; also defines `time_ago()` | Y |
| `admin/manage_groups.php` | 572 | Admin overview, delete, details, clear logs | Y |
| `admin/manage_groups_ajax.php` | 229 | The *complete* member-facing AJAX (edit group, roles, remove member…), misfiled under `admin/` | Y |

## User journeys

### Journey 1: Member creates a reading group for a book
1. Opens `create_group.php`. Picks "📖 Book" under content type.
2. The second dropdown ("Choose a book") shows **"Loading…" forever**. **[silent] [confirmed]**
   - `create_group.php:225` fetches `ajax/get_content_items.php?type=book`. There's **no `ajax/` directory** in the repo, so the request 404s and the page's JSON parse throws (*Unexpected token '<'*).
3. Submitting fails validation, *"Please select a book"*, because the dropdown has no options. **[confirmed]**

*Stop: no member can create a group through the UI. The only groups that can exist are ones inserted directly into the database.*

### Journey 2: Member joins a group with an invite code
1. Opens `groups.php`. Env A: **[fatal] [confirmed]** `includes/reading_groups.php:63`, *no such table: reading_groups*.
2. Env B: an empty list ("cards: 0"), with **Join a Group** buttons. The invite-code field sits in a modal that opens on click **[confirmed]**.
3. Enters `abcd1234` (lower case). It's upper-cased (`groups.php:20`). Joined, with a success message and a link to the group **[confirmed]**.
4. **[quality] [confirmed]** The JS error *closeJoinModalAfterSubmit is not defined* fires on submit (`groups.php:165`). It's harmless only because the form posts and reloads the page anyway.
5. **[wrong] [inferred]** There's no other way to find a group: no public directory and no "groups reading this book" on the book page. A member without a code can never join.

### Journey 3: Member takes part in a group (discussion, note, progress)
1. Opens `group.php?id=1`. Env A: **[fatal] [confirmed]** (`includes/reading_groups.php:86`). Env B: renders tabs for Discussions, Notes, Progress, Members, Schedule and Activity, plus the invite code with a Copy button **[confirmed]**.
2. **New Discussion** → title and body → `group_ajax.php action=create_discussion` returns `{"success":true}`, and the discussion appears after reload **[confirmed]**.
3. **Replying:** each discussion shows "0 replies", but **there's no reply button, no reply form and no reply action** in `group_ajax.php`. `addDiscussionReply()` exists in `includes/reading_groups.php:192` but nothing calls it. **[wrong] [confirmed]** A discussion can never get a reply, so the core group activity is one-way.
4. **Add Note:** works **[confirmed]**, with a PHP *Warning: Undefined array key "book_id"* (`group_ajax.php:42`) when `book_id` isn't sent. The comment in `group.php:537` says book_id is no longer sent, so production **shows a PHP warning printed before the JSON** whenever `display_errors` is on, and the JS JSON parse would then fail **[inferred]**.
5. **Update progress:** works **[confirmed]**.
6. **Reactions:** `add_reaction` works server-side **[confirmed]**, but `group.php` has **no reaction buttons**. Counts can display, but nobody can add one through the UI.
7. Layout: no horizontal overflow at 768 or 375 px **[confirmed]**.

### Journey 4: Group creator edits the group, changes a member's role, removes someone
1. In `group.php` the creator sees **Edit group** (Save Changes) and member controls.
2. The JS posts `update_group`, `change_member_role` and `remove_member` to `group_ajax.php`. **[wrong] [confirmed]** All three return `{"success":false,"error":"Invalid action."}`.
   - The handlers exist only in `admin/manage_groups_ajax.php`, the more complete copy of the same file, which fatals on every request because its includes are relative to `admin/` (`:7-10`).
3. The creator gets an error toast or alert; nothing changes.

### Journey 5: A non-member posts into someone else's group
1. **[wrong] [confirmed] access control:** logged in as a user who isn't a member (user 1), `POST group_ajax.php action=create_discussion&group_id=1` returns `{"success":true}` and the post appears in the group.
   - `group_ajax.php` checks the member's role only for `add_schedule` (`:83-89`). Creating discussions, adding notes, updating progress and reacting have no membership check.
   - Private groups (`is_private = 1`) can be written to by any logged-in user who guesses a group id.
2. Viewing: `group.php` *does* check membership (`:28-30`). A non-member is silently redirected to `groups.php` with no "You're not a member of this group" message **[confirmed]**.

### Journey 6: Guest follows a group link
1. `group.php?id=1` as a guest → 302 to `login.php` **[confirmed]**.
2. **[wrong] [inferred]** After login, there's no return to the group (the same redirect gap as domain 3 Journey 2). The invite code shared in a message has to be re-entered manually.

### Journey 7: Admin manages groups
1. `admin/manage_groups.php`: Env A, the page body fails on `reading_groups` **[inferred from Env A crawl: the page loaded, but listing queries need the table]**. Env B renders the overview **[confirmed]**.
2. **Delete group** (`:404-416`) posts to `manage_groups.php` itself. **[wrong] [confirmed]**
   - The PHP includes the full HTML header (`:15`) *before* handling the POST (`:18-30`), so the response is a 21 KB HTML page with `{"success":true}` appended at the end.
   - The JS calls `response.json()`, which fails. The admin sees an error **although the group was deleted** (the 5 `DELETE`s run).
3. **View details** (`get_group_details`) and **Clear activity logs** (`clear_activity_logs`) post to `manage_groups_ajax.php`. **[fatal] [confirmed]** That file fatals on its includes, and neither action exists in it anyway.
4. **[wrong] [inferred]** The list query LEFT JOINs a `newsletters` table (`admin/manage_groups.php:79`, `includes/reading_groups.php:74`, `:96`) and reads `poems.author`. Neither is created or written anywhere, so a group about a newsletter can't be created or displayed.

## Quality problems independent of the bugs above
- **Responsive:** `groups.php` and `group.php` show no horizontal overflow at 768/375 px **[confirmed]**. The tab bar wraps rather than scrolls, which is acceptable.
- **Empty state:** with no groups, `groups.php` shows two "Join a Group" buttons and nothing explaining what groups are or how to get a code.
- **Feedback:** group actions use `alert()` on error; success is a full reload, with no inline confirmation.
- **Invite code visible to every member**, and the creator can't regenerate it, so it can't be revoked once leaked.
- **Activity tab** logs joins and posts (`group_activity_log`) on every action and is never pruned. One more append-only table under the hosting limits.
- **Inconsistent styling:** `group.php` carries its own 400+ lines of inline CSS and redefines buttons and modals that differ from the site's.

## Root causes
- **[code]**
  - Missing `ajax/get_content_items.php`.
  - Two diverging copies of the group AJAX endpoint: the complete one is misfiled in `admin/` with broken includes, the incomplete one is live.
  - No reply action.
  - No membership check on writes.
  - Admin POST handled after HTML output.
  - `book_id` warning.
  - Undefined `closeJoinModalAfterSubmit`.
  - `newsletters`/`poems.author` referenced but never created.
  - `time_ago()` defined here and in `reader/reader_functions.php`.
- **[schema]** `reading_groups`, `group_members`, `group_notes`, `group_discussions`, `group_discussion_replies`, `group_reactions`, `group_reading_progress`, `group_schedules`, `group_activity_log` are not created by the repo.
- **[ux]** No group discovery, no reply UI, no reaction UI, silent redirects, `alert()` feedback.
- **[infra]** Unpruned activity log; every action is a SQLite write.

## What a professional version looks like
- **J1 Create:** choosing "Book" fills the second dropdown from the server-rendered list of books (no extra request needed), and the group is created with a generated invite code shown on a confirmation screen with a Copy/Share button.
- **J2 Join:** a book page shows "Reading groups for this book (2) · Join", plus the code-join path. A wrong code gives an inline "That code doesn't match any group."
- **J3 Participate:** each discussion opens into a thread with replies (newest at the bottom), a reply box, and a reaction row. Notes can be tied to a chapter and show the chapter name. Every action shows inline success and failure.
- **J4 Manage:** the creator can rename, set private/public, regenerate the invite code, promote a moderator and remove members. Every action is confirmed and returns JSON.
- **J5 Access:** every write checks that the user is a member (and moderator/creator for management). Non-members visiting a group see "This group is private. Ask a member for the invite code."
- **J7 Admin:** delete shows a confirmation, then removes the row without a reload. The details panel shows members and recent activity.

## Dependencies before this can be fixed
1. Confirm the 9 group tables exist in production `data/site.db`. If not, **create them manually** (SQLite file, not phpMyAdmin; see 01).
2. Decide whether "newsletter" groups are a real feature. If yes, there has to be a `newsletters` table of published issues; today issues live in `newsletter_archive` (domain 16).
3. Merge the two group AJAX files into one working endpoint before adding features.
4. Resolve the `time_ago()` collision together with domain 1.

## Blast radius
- **Domain 1 (reader):** shares `includes/reading_groups.php` and the `time_ago()` collision. The reader's "group notes" look up groups by a non-existent `book_id` column.
- **Domain 9 (book detail):** natural place for group discovery; today it has none.
- **Domain 16 (newsletter):** "newsletter" content type depends on a table that domain doesn't have.
- **Domain 10 (auth):** post-login redirect.
- **Domain 19 (admin):** `admin/manage_groups.php` is an admin screen.

## Effort
| Journey | Effort | Why |
|---|---|---|
| J1 create | S | Render the content list server-side or add the missing endpoint. |
| J2 join | S (code join works) / M (discovery) | Discovery needs a book-page section and a public/private rule. |
| J3 participate | M | Reply thread UI and endpoint; reaction UI; fix the warning. |
| J4 manage | S | Move the existing handlers into the live endpoint. |
| J5 access | S | One membership check reused across actions. |
| J6 guest return | S | Shared fix with domain 10. |
| J7 admin | S | Handle POST before output; point details/logs at a working endpoint. |

## Regression check against AUDIT.md
- `includes/reading_groups.php:63` `reading_groups` missing [schema]: **Yes** (Env A).
- `admin/manage_groups_ajax.php:7` relative includes: **Yes.**
- `newsletters` table and `poems.author` never created: **Yes.**
- `admin/manage_groups.php:23-27` `exec()` with int-cast id: **Yes**, still unparameterised. The bigger practical issue is that the JSON response breaks (Journey 7).
- `time_ago()` duplicate (`includes/reading_groups.php:415`): **Yes.**

## Coverage matrix
| File | Status | Where |
|---|---|---|
| `groups.php` | COVERED | Journey 2; Journey 5 step 2; Quality |
| `group.php` | COVERED | Journeys 3, 4, 5, 6 |
| `create_group.php` | COVERED | Journey 1 |
| `group_ajax.php` | COVERED | Journeys 3, 4, 5 |
| `includes/reading_groups.php` | COVERED | Journeys 2, 3 (unused `addDiscussionReply`), 7 (`newsletters` join) |
| `admin/manage_groups.php` | COVERED | Journey 7 |
| `admin/manage_groups_ajax.php` | COVERED | Journey 4 (has the missing handlers); Journey 7 step 3 |
