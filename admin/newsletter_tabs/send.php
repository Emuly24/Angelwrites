<?php if (!defined('AW_NEWSLETTER_ADMIN')) { http_response_code(403); exit('Access denied.'); } // S13 (F175): admin/manage_newsletter.php only ?>
<?php
// The composer: write on the left, see the letter live on the right (desktop, phone, or as it shows in
// the inbox list). Blocks (button, Bible verse, callout, featured piece, divider) are styled the
// AngelWrites way when sent (includes/newsletter_design.php). A checklist under the editor looks at
// the subject, preview line, greeting, links, pictures and words that send mail to Junk before sending.
$nl_tested = ($_SESSION['aw_nl_tested'] ?? '') === md5($subject . '|' . $content);
?>
<form method="POST" class="admin-form nlc" enctype="multipart/form-data" id="composeForm">
    <?php echo aw_csrf_field(); ?>
    <input type="hidden" name="action" value="send_newsletter" id="composeAction">

    <div class="nlc-grid">
        <div class="nlc-main">
            <section class="nlc-card">
                <div class="nlc-head">
                    <h2><span class="nlc-step">1</span> Write the letter</h2>
                    <?php if ($templates): ?>
                        <select id="templatePicker" aria-label="Start from a template" class="nlc-select">
                            <option value="">Start from a template…</option>
                            <?php foreach ($templates as $t): ?><option value="<?php echo (int)$t['id']; ?>"><?php echo htmlspecialchars($t['name']); ?></option><?php endforeach; ?>
                        </select>
                    <?php endif; ?>
                </div>
                <div class="form-group">
                    <label for="subject">Subject line <span class="required">*</span></label>
                    <input type="text" id="subject" name="subject" maxlength="150" value="<?php echo htmlspecialchars($subject); ?>" placeholder="e.g. Wings like eagles: a letter for November" required>
                    <div class="nlc-meter"><span id="subjectBar"></span></div>
                    <small class="field-hint"><span id="subjectCount">0</span> characters · 30 to 60 reads best on phones.</small>
                </div>
                <div class="form-group">
                    <label for="preheader">Preview line <small>(shown after the subject in the inbox, and under the title)</small></label>
                    <input type="text" id="preheader" name="preheader" maxlength="150" value="<?php echo htmlspecialchars($preheader); ?>" placeholder="e.g. A new poem, a reflection on waiting, and our December reading group.">
                    <small class="field-hint"><span id="preCount">0</span> characters · 40 to 110 works well. Empty: the first words of the letter are used.</small>
                </div>
                <div class="form-group">
                    <label for="editor">The letter <span class="required">*</span></label>
                    <div class="nlc-blocks" role="toolbar" aria-label="Add a block">
                        <span class="nlc-blocks-l">Add</span>
                        <button type="button" data-block="greeting"><i class="fas fa-hand" aria-hidden="true"></i> Greeting</button>
                        <button type="button" data-block="button"><i class="fas fa-square-arrow-up-right" aria-hidden="true"></i> Button</button>
                        <button type="button" data-block="verse"><i class="fas fa-book-bible" aria-hidden="true"></i> Bible verse</button>
                        <button type="button" data-block="callout"><i class="fas fa-message" aria-hidden="true"></i> Callout</button>
                        <button type="button" data-block="divider"><i class="fas fa-minus" aria-hidden="true"></i> Divider</button>
                        <?php if ($featured): ?>
                        <select id="featurePick" aria-label="Feature a poem, post or book" class="nlc-select nlc-select-sm">
                            <option value="">Feature a piece…</option>
                            <?php foreach ($featured as $i => $f): ?><option value="<?php echo $i; ?>"><?php echo htmlspecialchars($f['kind'] . ': ' . $f['title']); ?></option><?php endforeach; ?>
                        </select>
                        <?php endif; ?>
                    </div>
                    <div class="nlc-blocks nl-tokens" aria-label="Personalise">
                        <span class="nlc-blocks-l">Personalise</span>
                        <button type="button" data-token="{{first_name}}">First name</button>
                        <button type="button" data-token="{{name}}">Full name</button>
                    </div>
                    <textarea id="editor" name="content" rows="14"><?php echo htmlspecialchars($content); ?></textarea>
                    <div class="nlc-opts">
                        <label><input type="checkbox" name="add_signature" id="addSignature" value="1" checked> Sign it with my signature</label>
                        <label><input type="checkbox" name="add_latest" id="addLatest" value="1" checked> Add “New on AngelWrites” (latest poem, post and book)</label>
                    </div>
                </div>
            </section>

            <section class="nlc-card">
                <h2><span class="nlc-step">2</span> Who receives it</h2>
                <div class="nlc-choice" role="radiogroup" aria-label="Send to">
                    <label><input type="radio" name="send_to" value="all" <?php echo $prefill_email ? '' : 'checked'; ?>><span><strong>Everyone</strong><small><?php echo $active_count; ?> active subscribers</small></span></label>
                    <label><input type="radio" name="send_to" value="segment"><span><strong>A segment</strong><small>a group you made</small></span></label>
                    <label><input type="radio" name="send_to" value="selected"><span><strong>Chosen people</strong><small>pick from the list</small></span></label>
                    <label><input type="radio" name="send_to" value="single" <?php echo $prefill_email ? 'checked' : ''; ?>><span><strong>One person</strong><small>a personal letter</small></span></label>
                </div>
                <div id="segmentGroup" class="form-group" hidden>
                    <label for="segment_id">Segment</label>
                    <select id="segment_id" name="segment_id">
                        <option value="">— Choose —</option>
                        <?php foreach ($segments as $seg): ?><option value="<?php echo (int)$seg['id']; ?>" data-count="<?php echo (int)$seg['active_members']; ?>"><?php echo htmlspecialchars($seg['name']); ?> (<?php echo (int)$seg['active_members']; ?>)</option><?php endforeach; ?>
                    </select>
                </div>
                <div id="selectedGroup" class="form-group" hidden>
                    <label for="pickFilter">Choose subscribers</label>
                    <input type="search" id="pickFilter" placeholder="Filter by email or name…" style="margin-bottom:6px;">
                    <div class="nlc-pick" id="pickList">
                        <?php foreach ($active_list as $sub): ?>
                            <label data-text="<?php echo htmlspecialchars(strtolower($sub['email'] . ' ' . $sub['name'])); ?>"><input type="checkbox" class="pick" value="<?php echo (int)$sub['id']; ?>"> <?php echo htmlspecialchars($sub['email']); ?><?php if ($sub['name']): ?> · <?php echo htmlspecialchars($sub['name']); ?><?php endif; ?></label>
                        <?php endforeach; ?>
                    </div>
                    <input type="hidden" name="selected_ids" id="selectedIds">
                </div>
                <div id="singleGroup" class="form-group" hidden>
                    <label for="single_email">Email address</label>
                    <input type="email" id="single_email" name="single_email" value="<?php echo htmlspecialchars($prefill_email); ?>" placeholder="name@example.com">
                </div>
                <p class="nlc-count" id="recipientCount" aria-live="polite"></p>
            </section>

            <section class="nlc-card">
                <h2><span class="nlc-step">3</span> When</h2>
                <div class="nlc-choice nlc-choice-2" role="radiogroup" aria-label="When">
                    <label><input type="radio" name="schedule_type" value="now" checked><span><strong>Send now</strong><small>in small batches, with progress</small></span></label>
                    <label><input type="radio" name="schedule_type" value="later"><span><strong>Schedule</strong><small>choose a date and time</small></span></label>
                </div>
                <div id="scheduleGroup" hidden class="form-group" style="margin-top:10px;">
                    <label for="schedule">Date and time</label>
                    <input type="datetime-local" id="schedule" name="schedule" min="<?php echo htmlspecialchars(aw_nl_local_now()); ?>" aria-describedby="scheduleHint">
                    <small class="field-hint" id="scheduleHint">In <?php echo htmlspecialchars(aw_nl_tz_label()); ?>. Write whenever you are free: the letter goes out <strong>by itself</strong> at this time (usually within 10 minutes of it). Until then you can change the time, send it early or cancel it on Campaigns.</small>
                </div>
                <details class="nlc-more"><summary>Attachments (optional)</summary>
                    <input type="file" id="attachments" name="attachments[]" multiple accept=".pdf,.doc,.docx,.jpg,.jpeg,.png,.gif,.webp,.mp3,.txt">
                    <small class="field-hint">Up to 5 MB each and 10 MB in total. A link to the site is usually better: attachments make every email heavy and more likely to land in Junk.</small>
                    <div id="attachmentPreview" class="nl-row" style="margin-top:8px;"></div>
                </details>
            </section>

            <section class="nlc-card" aria-labelledby="chkH">
                <h2 id="chkH"><span class="nlc-step"><i class="fas fa-list-check" aria-hidden="true"></i></span> Before you send <span class="nlc-score" id="chkScore"></span></h2>
                <ul class="nlc-check" id="checklist" aria-live="polite"></ul>
            </section>
        </div>

        <aside class="nlc-side" aria-label="Live preview">
            <div class="nlc-prev">
                <div class="nlc-prev-head">
                    <strong><i class="fas fa-eye" aria-hidden="true"></i> Live preview</strong>
                    <div class="nlc-seg" role="tablist" aria-label="Preview size">
                        <button type="button" role="tab" aria-selected="true" data-view="desktop"><i class="fas fa-desktop" aria-hidden="true"></i><span class="sr-only">Desktop</span></button>
                        <button type="button" role="tab" aria-selected="false" data-view="phone"><i class="fas fa-mobile-screen" aria-hidden="true"></i><span class="sr-only">Phone</span></button>
                        <button type="button" role="tab" aria-selected="false" data-view="inbox"><i class="fas fa-inbox" aria-hidden="true"></i><span class="sr-only">Inbox</span></button>
                    </div>
                </div>
                <div class="nlc-inbox" id="inboxView" hidden>
                    <div class="nlc-inbox-row"><span class="nlc-av" aria-hidden="true">A</span><div><div class="nlc-inbox-top"><b>AngelWrites</b><span><?php echo date('H:i'); ?></span></div><div class="nlc-inbox-subj" id="ibSubj"></div><div class="nlc-inbox-pre" id="ibPre"></div></div></div>
                    <div class="nlc-inbox-row nlc-ghost"><span class="nlc-av" aria-hidden="true">G</span><div><div class="nlc-inbox-top"><b>Another sender</b><span>09:12</span></div><div class="nlc-inbox-subj">Your weekly summary</div><div class="nlc-inbox-pre">Here is what happened this week…</div></div></div>
                    <p class="field-hint" style="padding:10px 14px;">This is roughly how the letter appears in a phone's inbox list. Long subjects and preview lines are cut off.</p>
                </div>
                <div class="nlc-frame-wrap" id="frameWrap"><iframe id="nlFrame" title="Live preview of the letter" sandbox="allow-same-origin"></iframe></div>
                <p class="nlc-prev-note" id="prevNote">Updates as you type · sample reader “Grace Banda” · the logo shows in the real email</p>
            </div>
        </aside>
    </div>

    <div class="nlc-bar">
        <div class="nl-row">
            <label for="test_email" class="sr-only">Send a test to</label>
            <input type="email" id="test_email" name="test_email" value="<?php echo htmlspecialchars(aw_admin_email()); ?>" class="nlc-test" aria-label="Send a test to">
            <button type="button" id="testSendBtn" class="btn btn-sm btn-secondary"><i class="fas fa-vial"></i> Send test</button>
        </div>
        <div class="nl-row">
            <input type="text" name="template_name" id="templateName" placeholder="Template name" class="nlc-test" aria-label="Template name">
            <button type="button" id="saveTemplateBtn" class="btn btn-sm btn-outline"><i class="fas fa-bookmark"></i> Save template</button>
            <button type="submit" class="btn btn-primary nlc-send" id="sendBtn"><i class="fas fa-paper-plane"></i> <span>Send</span></button>
        </div>
    </div>
</form>

<?php if ($templates): ?>
<details class="nlc-card nlc-more" style="margin-top:16px;"><summary>Manage templates (<?php echo count($templates); ?>)</summary>
    <ul style="margin-top:8px;list-style:none;padding:0;">
        <?php foreach ($templates as $t): ?>
            <li class="nl-row" style="justify-content:space-between;border-bottom:1px solid var(--border);padding:8px 0;">
                <span><strong><?php echo htmlspecialchars($t['name']); ?></strong> · <?php echo htmlspecialchars($t['subject']); ?></span>
                <form method="POST" onsubmit="return confirm('Delete this template?');"><?php echo aw_csrf_field(); ?><input type="hidden" name="action" value="delete_template"><input type="hidden" name="template_id" value="<?php echo (int)$t['id']; ?>"><button type="submit" class="btn btn-sm btn-outline">Delete</button></form>
            </li>
        <?php endforeach; ?>
    </ul>
</details>
<?php endif; ?>

<style>
.nlc-grid{display:grid;grid-template-columns:minmax(0,1fr) minmax(360px,460px);gap:22px;align-items:start}
.nlc-card{background:var(--card-bg);border:1px solid var(--border);border-radius:18px;padding:20px 22px;margin-bottom:18px;box-shadow:0 2px 10px rgba(61,46,46,.04)}
.nlc-card h2{display:flex;align-items:center;gap:10px;font-size:1.12rem;margin:0 0 16px}
.nlc-head{display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:6px}.nlc-head h2{margin:0}
.nlc-step{display:inline-grid;place-items:center;min-width:28px;height:28px;border-radius:50%;background:var(--rose-dark,#9c5457);color:#fff;font-size:.85rem}
.nlc-select{width:auto!important;min-height:40px;border-radius:999px!important;padding:6px 14px!important}.nlc-select-sm{min-height:34px;font-size:.85rem!important}
.nlc-meter{height:4px;border-radius:4px;background:var(--border);margin-top:6px;overflow:hidden}.nlc-meter span{display:block;height:100%;width:0;background:#2f7a4a;transition:width .2s,background .2s}
.nlc-blocks{display:flex;gap:6px;flex-wrap:wrap;align-items:center;margin-bottom:8px}
.nlc-blocks-l{font-size:.78rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--text-light);margin-right:2px}
.nlc-blocks button{display:inline-flex;align-items:center;gap:6px;min-height:34px;padding:0 12px;border-radius:999px;border:1px solid var(--border);background:var(--card-bg);color:var(--text);font:600 .82rem Inter,sans-serif;cursor:pointer}
.nlc-blocks button:hover,.nlc-blocks button:focus-visible{border-color:var(--rose-dark,#9c5457);color:var(--rose-dark,#9c5457)}
.nlc-opts{display:grid;gap:6px;margin-top:10px}.nlc-opts label{font-weight:500!important;display:flex;gap:8px;align-items:center}
.nlc-choice{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px}.nlc-choice-2{grid-template-columns:repeat(auto-fit,minmax(200px,1fr))}
.nlc-choice label{position:relative;display:flex;gap:10px;align-items:flex-start;padding:12px 14px;border:1px solid var(--border);border-radius:14px;cursor:pointer;font-weight:400!important;margin:0!important}
.nlc-choice label:has(input:checked){border-color:var(--rose-dark,#9c5457);box-shadow:0 0 0 2px rgba(156,84,87,.18);background:rgba(219,161,162,.08)}
.nlc-choice input{margin-top:3px}.nlc-choice span{display:flex;flex-direction:column}.nlc-choice small{color:var(--text-light);font-size:.8rem}
.nlc-pick{border:1px solid var(--border);border-radius:12px;padding:10px;max-height:220px;overflow-y:auto}.nlc-pick label{display:flex;gap:6px;align-items:center;padding:3px 0;font-weight:400!important}
.nlc-count{margin:12px 0 0;font-weight:600;color:var(--rose-dark,#9c5457)}
.nlc-more summary{cursor:pointer;font-weight:600;margin-top:12px}
.nlc-check{list-style:none;margin:0;padding:0;display:grid;gap:6px}
.nlc-check li{display:flex;gap:10px;align-items:flex-start;padding:8px 10px;border-radius:10px;font-size:.92rem}
.nlc-check li.ok{background:rgba(47,122,74,.07)}.nlc-check li.warn{background:rgba(201,150,90,.12)}.nlc-check li.bad{background:rgba(180,80,90,.1)}
.nlc-check i{margin-top:3px}.nlc-check .ok i{color:#2f7a4a}.nlc-check .warn i{color:#b07a2a}.nlc-check .bad i{color:#b4505a}
.nlc-score{margin-left:auto;font-size:.85rem;padding:3px 10px;border-radius:999px;background:var(--vanilla)}
.nlc-side{position:sticky;top:84px}
.nlc-prev{background:var(--card-bg);border:1px solid var(--border);border-radius:18px;overflow:hidden;box-shadow:0 8px 30px rgba(61,46,46,.08)}
.nlc-prev-head{display:flex;justify-content:space-between;align-items:center;padding:10px 14px;border-bottom:1px solid var(--border)}
.nlc-seg{display:inline-flex;background:var(--vanilla);border-radius:999px;padding:3px}
.nlc-seg button{border:0;background:transparent;width:38px;height:32px;border-radius:999px;cursor:pointer;color:var(--text-light)}
.nlc-seg button[aria-selected="true"]{background:var(--card-bg);color:var(--rose-dark,#9c5457);box-shadow:0 1px 4px rgba(0,0,0,.12)}
.nlc-frame-wrap{background:#efe8df;height:calc(100vh - 230px);min-height:520px;overflow:hidden;display:flex;justify-content:center}
.nlc-frame-wrap iframe{border:0;width:100%;height:100%;background:#F4EFE8;transition:width .25s}
.nlc-frame-wrap[hidden],.nlc-inbox[hidden]{display:none!important}
.nlc-frame-wrap.is-phone iframe{width:375px;border-left:1px solid #ddd;border-right:1px solid #ddd}
.nlc-prev-note{margin:0;padding:8px 14px;font-size:.78rem;color:var(--text-light);border-top:1px solid var(--border)}
.nlc-inbox{background:#fff;color:#1f1f1f}.nlc-inbox-row{display:flex;gap:12px;padding:12px 14px;border-bottom:1px solid #eee}
.nlc-av{flex:none;width:38px;height:38px;border-radius:50%;background:#9c5457;color:#fff;display:grid;place-items:center;font-weight:700}
.nlc-inbox-row>div{min-width:0;flex:1}.nlc-inbox-top{display:flex;justify-content:space-between;font-size:.92rem}.nlc-inbox-top span{color:#777;font-size:.8rem}
.nlc-inbox-subj{font-weight:700;font-size:.92rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.nlc-inbox-pre{color:#666;font-size:.86rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.nlc-ghost{opacity:.45}
.nlc-bar{position:sticky;bottom:0;z-index:5;display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap;padding:12px 16px;margin-top:4px;background:var(--card-bg);border:1px solid var(--border);border-radius:16px;box-shadow:0 -6px 24px rgba(61,46,46,.08)}
.nlc-test{width:auto!important;min-width:200px;min-height:40px}
.nlc-send{min-height:44px;padding:0 22px;border-radius:999px}
.sr-only{position:absolute!important;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0)}
@media (max-width:1100px){.nlc-grid{grid-template-columns:1fr}.nlc-side{position:static}.nlc-frame-wrap{height:620px}}
html[data-theme="dark"] .nlc-inbox{background:#1f1718;color:#eee}html[data-theme="dark"] .nlc-inbox-row{border-color:#3a2f30}
</style>

<form method="POST" action="<?php echo SITE_URL; ?>/admin/newsletter_preview.php" target="nlPreview" id="previewForm">
    <?php echo aw_csrf_field(); ?>
    <input type="hidden" name="subject"><input type="hidden" name="content"><input type="hidden" name="add_signature"><input type="hidden" name="preheader"><input type="hidden" name="latest">
</form>

<script>
(function () {
    const form = document.getElementById('composeForm');
    const templates = <?php echo json_encode(array_column($templates, null, 'id'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    const featured = <?php echo json_encode($featured, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    const activeCount = <?php echo (int)$active_count; ?>;
    let tested = <?php echo $nl_tested ? 'true' : 'false'; ?>;
    const ed = () => (window.tinymce && tinymce.get('editor')) || null;
    const content = () => ed() ? ed().getContent() : document.getElementById('editor').value;
    const setContent = (html) => { if (ed()) ed().setContent(html); else document.getElementById('editor').value = html; refresh(); };
    const insert = (html) => { if (ed()) { ed().focus(); ed().insertContent(html); } else document.getElementById('editor').value += html; refresh(); };
    const subj = document.getElementById('subject'), pre = document.getElementById('preheader');
    const esc = (s) => String(s).replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));

    // ----- blocks
    const blocks = {
        greeting: () => '<p>Dear {{first_name}},</p><p>&nbsp;</p>',
        button: () => { const t = prompt('Button text', 'Read it on AngelWrites'); if (!t) return ''; const u = prompt('Link (https://…)', '<?php echo rtrim(SITE_URL, '/'); ?>/'); if (!u || !/^https?:\/\//i.test(u)) return ''; return '<p><a class="aw-btn" href="' + esc(u) + '">' + esc(t) + '</a></p><p>&nbsp;</p>'; },
        verse: () => { const v = prompt('The verse'); if (!v) return ''; const r = prompt('Reference (e.g. Isaiah 40:31)') || ''; return '<blockquote class="aw-verse">“' + esc(v) + '”' + (r ? '<span class="aw-verse-ref">' + esc(r) + '</span>' : '') + '</blockquote><p>&nbsp;</p>'; },
        callout: () => '<div class="aw-callout"><strong>Good to know:</strong> write your note here.</div><p>&nbsp;</p>',
        divider: () => '<hr><p>&nbsp;</p>'
    };
    document.querySelectorAll('[data-block]').forEach(b => b.addEventListener('click', () => { const h = blocks[b.dataset.block](); if (h) insert(h); }));
    const fp = document.getElementById('featurePick');
    if (fp) fp.addEventListener('change', function () {
        const f = featured[this.value]; if (!f) return;
        insert('<h3>' + esc(f.title) + '</h3><p>A few words on why you will love it.</p><p><a class="aw-btn" href="' + esc(f.url) + '">' + (f.kind === 'Book' ? 'See the book' : 'Read the ' + f.kind.toLowerCase()) + '</a></p><p>&nbsp;</p>');
        this.value = '';
    });
    document.querySelectorAll('[data-token]').forEach(b => b.addEventListener('click', () => insert(b.dataset.token)));

    // ----- counters
    function counts() {
        const n = subj.value.length; document.getElementById('subjectCount').textContent = n;
        const bar = document.getElementById('subjectBar'); bar.style.width = Math.min(100, n / 80 * 100) + '%';
        bar.style.background = n === 0 ? '#ccc' : (n < 20 || n > 70 ? '#c9965a' : '#2f7a4a');
        document.getElementById('preCount').textContent = pre.value.length;
        document.getElementById('ibSubj').textContent = subj.value || 'Your subject';
        document.getElementById('ibPre').textContent = pre.value || content().replace(/<[^>]+>/g, ' ').replace(/\{\{first_name\}\}/g, 'Grace').replace(/\s+/g, ' ').trim().slice(0, 110);
    }

    // ----- audience
    function mode() { return form.querySelector('input[name="send_to"]:checked').value; }
    function recipients() {
        const m = mode();
        if (m === 'all') return activeCount;
        if (m === 'segment') { const o = document.getElementById('segment_id').selectedOptions[0]; return o && o.dataset.count ? +o.dataset.count : 0; }
        if (m === 'selected') return document.querySelectorAll('.pick:checked').length;
        return document.getElementById('single_email').value ? 1 : 0;
    }
    function updateGroups() {
        const m = mode();
        document.getElementById('segmentGroup').hidden = m !== 'segment';
        document.getElementById('selectedGroup').hidden = m !== 'selected';
        document.getElementById('singleGroup').hidden = m !== 'single';
        document.getElementById('scheduleGroup').hidden = form.querySelector('input[name="schedule_type"]:checked').value !== 'later';
        const n = recipients(), later = form.querySelector('input[name="schedule_type"]:checked').value === 'later';
        document.getElementById('recipientCount').textContent = n === 1 ? 'This letter will go to 1 person.' : 'This letter will go to ' + n + ' people.';
        document.querySelector('#sendBtn span').textContent = (later ? 'Schedule for ' : 'Send to ') + n + (n === 1 ? ' person' : ' people');
        checks();
    }
    form.querySelectorAll('input[name="send_to"], input[name="schedule_type"]').forEach(r => r.addEventListener('change', updateGroups));
    document.getElementById('schedule').addEventListener('input', checks);
    ['segment_id', 'single_email'].forEach(id => document.getElementById(id).addEventListener('input', updateGroups));
    document.querySelectorAll('.pick').forEach(cb => cb.addEventListener('change', function () {
        document.getElementById('selectedIds').value = Array.from(document.querySelectorAll('.pick:checked')).map(c => c.value).join(',');
        updateGroups();
    }));
    document.getElementById('pickFilter').addEventListener('input', function () {
        const q = this.value.toLowerCase();
        document.querySelectorAll('#pickList label').forEach(l => { l.style.display = l.dataset.text.includes(q) ? '' : 'none'; });
    });

    // ----- checklist
    const spamWords = /\b(free money|act now|100% free|winner|cash bonus|click here|guarantee[d]?|risk[- ]free|urgent|limited time|buy now|earn \$|no cost)\b/i;
    function checks() {
        const html = content(), text = html.replace(/<[^>]+>/g, ' ').replace(/&nbsp;/g, ' ').replace(/\s+/g, ' ').trim(), s = subj.value.trim(), list = [];
        const add = (lvl, msg) => list.push([lvl, msg]);
        if (!s) add('bad', 'Write a subject line.');
        else if (s.length < 20) add('warn', 'The subject is short. A few more words help people know what is inside.');
        else if (s.length > 70) add('warn', 'The subject is long (' + s.length + '). Phones show about 40 to 60 characters.');
        else add('ok', 'Subject length is good.');
        if (s && (s.replace(/[^A-Z]/g, '').length > s.replace(/[^A-Za-z]/g, '').length * 0.6 && s.length > 8)) add('bad', 'The subject is mostly CAPITALS, which looks like spam.');
        if (/!{2,}/.test(s + ' ' + text)) add('warn', 'Several exclamation marks together (!!) look like spam.');
        if (spamWords.test(s + ' ' + text)) add('warn', 'Words such as “' + (s + ' ' + text).match(spamWords)[0] + '” are often filtered as junk.');
        pre.value.trim() ? add('ok', 'Preview line is set.') : add('warn', 'Add a preview line: it shows in the inbox and gets more letters opened.');
        if (text.length < 40) add('bad', 'The letter is very short.'); else add('ok', 'The letter has ' + text.split(' ').length + ' words.');
        /\{\{(first_)?name\}\}/.test(html) ? add('ok', 'It greets each reader by name.') : add('warn', 'Greet readers by name: press Greeting or First name.');
        /<a\s[^>]*href=/i.test(html) || document.getElementById('addLatest').checked ? add('ok', 'There is somewhere to go next (a link or the latest pieces).') : add('warn', 'Add a button or link so readers can visit the site.');
        if (form.querySelector('input[name="schedule_type"]:checked').value === 'later') {
            const sv = document.getElementById('schedule').value;
            if (!sv) add('bad', 'Choose the date and time it should go out.');
            else add('ok', 'It goes out by itself on ' + new Date(sv).toLocaleString([], { weekday: 'long', day: 'numeric', month: 'long', hour: '2-digit', minute: '2-digit' }) + '.');
        }
        const imgs = html.match(/<img\b[^>]*>/gi) || [];
        if (imgs.some(i => !/alt="[^"]+"/i.test(i))) add('warn', 'A picture has no description (alt text). Add one for readers using screen readers, and for when pictures are blocked.');
        if (imgs.length > 4) add('warn', imgs.length + ' pictures: many pictures make a letter heavy and more likely to land in Junk.');
        const files = document.getElementById('attachments').files.length; if (files) add('warn', 'Attachments make the email heavier and more likely to land in Junk; a link is lighter.');
        tested ? add('ok', 'A test was sent for this version.') : add('warn', 'Send yourself a test first and read it on your phone.');
        if (recipients() < 1) add('bad', 'Choose who receives it.');
        const ul = document.getElementById('checklist'); ul.innerHTML = '';
        list.forEach(([lvl, msg]) => { const li = document.createElement('li'); li.className = lvl; li.innerHTML = '<i class="fas ' + (lvl === 'ok' ? 'fa-circle-check' : lvl === 'warn' ? 'fa-circle-exclamation' : 'fa-circle-xmark') + '" aria-hidden="true"></i><span></span>'; li.querySelector('span').textContent = msg; ul.appendChild(li); });
        const ok = list.filter(x => x[0] === 'ok').length, bad = list.filter(x => x[0] === 'bad').length;
        const sc = document.getElementById('chkScore'); sc.textContent = bad ? bad + ' to fix' : ok + ' of ' + list.length + ' look good';
        return bad;
    }

    // ----- live preview (debounced; fetches the real rendering)
    const frame = document.getElementById('nlFrame'), csrf = form.querySelector('input[name="csrf_token"]').value;
    let timer = null, inflight = null;
    function refresh() { counts(); checks(); clearTimeout(timer); timer = setTimeout(render, 700); }
    function render() {
        const fd = new FormData();
        fd.append('csrf_token', csrf); fd.append('raw', '1'); fd.append('subject', subj.value); fd.append('content', content());
        fd.append('preheader', pre.value); if (document.getElementById('addSignature').checked) fd.append('add_signature', '1');
        if (document.getElementById('addLatest').checked) fd.append('latest', '1');
        if (inflight) inflight.abort(); inflight = new AbortController();
        fetch('<?php echo SITE_URL; ?>/admin/newsletter_preview.php', { method: 'POST', body: fd, signal: inflight.signal, credentials: 'same-origin' })
            .then(r => r.ok ? r.text() : Promise.reject()).then(h => { const y = frame.contentWindow ? frame.contentWindow.scrollY : 0; frame.srcdoc = h; frame.onload = () => { try { frame.contentWindow.scrollTo(0, y); } catch (e) {} }; })
            .catch(() => {});
    }
    [subj, pre].forEach(el => el.addEventListener('input', refresh));
    ['addSignature', 'addLatest'].forEach(id => document.getElementById(id).addEventListener('change', refresh));
    document.getElementById('editor').addEventListener('input', refresh);
    (function hook() { if (ed()) { ed().on('input change keyup SetContent ExecCommand', refresh); refresh(); } else setTimeout(hook, 300); })();
    document.querySelectorAll('.nlc-seg button').forEach(b => b.addEventListener('click', function () {
        document.querySelectorAll('.nlc-seg button').forEach(x => x.setAttribute('aria-selected', x === b ? 'true' : 'false'));
        const v = b.dataset.view;
        document.getElementById('inboxView').hidden = v !== 'inbox';
        document.getElementById('frameWrap').hidden = v === 'inbox';
        document.getElementById('frameWrap').classList.toggle('is-phone', v === 'phone');
    }));

    // ----- templates, test, save, send
    const picker = document.getElementById('templatePicker');
    if (picker) picker.addEventListener('change', function () {
        const t = templates[this.value]; if (!t) return;
        if ((subj.value || content()) && !confirm('Replace the current subject and letter with the template "' + t.name + '"?')) { this.value = ''; return; }
        subj.value = t.subject; setContent(t.content); document.getElementById('templateName').value = t.name; this.value = '';
    });
    document.getElementById('testSendBtn').addEventListener('click', function () {
        if (window.tinymce) tinymce.triggerSave();
        const fd = new FormData(form); fd.set('action', 'test_send'); fd.set('content', content());
        const btn = this; btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Sending…';
        fetch('manage_newsletter.php', { method: 'POST', body: fd, headers: { 'X-Requested-With': 'fetch' } })
            .then(r => r.json()).then(d => { if (d.ok) { tested = true; checks(); } alert(d.message); }).catch(() => alert('The test email could not be sent.'))
            .finally(() => { btn.disabled = false; btn.innerHTML = '<i class="fas fa-vial"></i> Send test'; });
    });
    document.getElementById('saveTemplateBtn').addEventListener('click', function () {
        if (!document.getElementById('templateName').value.trim()) { alert('Give the template a name first.'); document.getElementById('templateName').focus(); return; }
        if (window.tinymce) tinymce.triggerSave();
        document.getElementById('composeAction').value = 'save_template';
        form.removeAttribute('enctype'); document.getElementById('attachments').disabled = true;
        form.submit();
    });
    form.addEventListener('submit', function (e) {
        if (window.tinymce) tinymce.triggerSave();
        if (document.getElementById('composeAction').value !== 'send_newsletter') return;
        if (mode() === 'selected' && !document.getElementById('selectedIds').value) { e.preventDefault(); alert('Choose at least one subscriber.'); return; }
        const bad = checks(), n = recipients();
        if (bad) { e.preventDefault(); alert('Please fix the items marked in red under “Before you send”.'); document.getElementById('checklist').scrollIntoView({ behavior: 'smooth', block: 'center' }); return; }
        const later = form.querySelector('input[name="schedule_type"]:checked').value === 'later';
        if (!confirm((later ? 'Schedule' : 'Send') + ' “' + subj.value + '” to ' + n + ' ' + (n === 1 ? 'person' : 'people') + '?' + (tested ? '' : '\n\nYou have not sent yourself a test of this version.'))) e.preventDefault();
    });
    document.getElementById('attachments').addEventListener('change', function () {
        const box = document.getElementById('attachmentPreview'); box.innerHTML = '';
        Array.from(this.files).forEach(f => { const s = document.createElement('span'); s.className = 'nl-chip'; s.textContent = f.name + ' (' + (f.size / 1048576).toFixed(1) + ' MB)' + (f.size > 5 * 1048576 ? ' – too large' : ''); box.appendChild(s); });
        checks();
    });
    counts(); updateGroups(); render();
})();
</script>
