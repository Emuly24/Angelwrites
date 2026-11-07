<?php if (!defined('AW_NEWSLETTER_ADMIN')) { http_response_code(403); exit('Access denied.'); } // S13 (F175): admin/manage_newsletter.php only ?>
<div class="card">
<?php
// N4: compose – templates, personalisation, recipients (everyone, a segment, chosen subscribers or one
// person), a branded preview, a real test email, scheduling and checked attachments.
if (!defined('AW_NEWSLETTER_ADMIN') && basename($_SERVER['SCRIPT_FILENAME'] ?? '') !== 'manage_newsletter.php') { http_response_code(403); exit('Access denied.'); }
?>
    <div class="card-header">
        <h2>✉️ Compose a newsletter</h2>
        <div class="nl-row">
            <?php if ($templates): ?>
                <label for="templatePicker" class="sr-only" style="position:absolute;left:-9999px;">Start from a template</label>
                <select id="templatePicker" aria-label="Start from a template">
                    <option value="">Start from a template…</option>
                    <?php foreach ($templates as $t): ?><option value="<?php echo (int)$t['id']; ?>"><?php echo htmlspecialchars($t['name']); ?></option><?php endforeach; ?>
                </select>
            <?php endif; ?>
            <button type="button" id="previewBtn" class="btn btn-sm btn-outline"><i class="fas fa-eye"></i> Preview</button>
        </div>
    </div>
    <div class="card-body">
        <form method="POST" class="admin-form" enctype="multipart/form-data" id="composeForm">
            <?php echo aw_csrf_field(); ?>
            <input type="hidden" name="action" value="send_newsletter" id="composeAction">

            <div class="form-group">
                <label for="subject">Subject <span class="required">*</span></label>
                <input type="text" id="subject" name="subject" maxlength="150" value="<?php echo htmlspecialchars($subject); ?>" placeholder="e.g. A new reflection for your week" required>
                <small class="field-hint"><span id="subjectCount">0</span>/150 · short subjects (under 50 characters) are read more often.</small>
            </div>
            <div class="form-group">
                <label for="editor">Message <span class="required">*</span></label>
                <div class="nl-row nl-tokens" style="margin-bottom:6px;">
                    <span class="field-hint">Personalise:</span>
                    <button type="button" data-token="{{first_name}}">First name</button>
                    <button type="button" data-token="{{name}}">Full name</button>
                    <button type="button" data-token="{{email}}">Email</button>
                </div>
                <textarea id="editor" name="content" rows="12"><?php echo htmlspecialchars($content); ?></textarea>
                <small class="field-hint">Your logo, the colours, a "view in browser" link and an unsubscribe link are added automatically. Someone without a name on file is greeted as "friend".</small>
            </div>

            <fieldset class="form-group" style="border:0;padding:0;">
                <legend style="font-weight:600;margin-bottom:6px;">Send to</legend>
                <div class="nl-row">
                    <label><input type="radio" name="send_to" value="all" <?php echo $prefill_email ? '' : 'checked'; ?>> Everyone active (<?php echo $active_count; ?>)</label>
                    <label><input type="radio" name="send_to" value="segment"> A segment</label>
                    <label><input type="radio" name="send_to" value="selected"> Chosen subscribers</label>
                    <label><input type="radio" name="send_to" value="single" <?php echo $prefill_email ? 'checked' : ''; ?>> One person</label>
                </div>
            </fieldset>
            <div id="segmentGroup" class="form-group" hidden>
                <label for="segment_id">Segment</label>
                <select id="segment_id" name="segment_id">
                    <option value="">— Choose —</option>
                    <?php foreach ($segments as $seg): ?><option value="<?php echo (int)$seg['id']; ?>" data-count="<?php echo (int)$seg['active_members']; ?>"><?php echo htmlspecialchars($seg['name']); ?> (<?php echo (int)$seg['active_members']; ?> active)</option><?php endforeach; ?>
                </select>
            </div>
            <div id="selectedGroup" class="form-group" hidden>
                <label for="pickFilter">Choose subscribers</label>
                <input type="search" id="pickFilter" placeholder="Filter by email or name…" style="margin-bottom:6px;">
                <div style="border:1px solid var(--border);border-radius:8px;padding:10px;background:var(--fantasy);max-height:220px;overflow-y:auto;" id="pickList">
                    <?php foreach ($active_list as $sub): ?>
                        <label style="display:flex;gap:6px;align-items:center;padding:2px 0;" data-text="<?php echo htmlspecialchars(strtolower($sub['email'] . ' ' . $sub['name'])); ?>">
                            <input type="checkbox" class="pick" value="<?php echo (int)$sub['id']; ?>"> <?php echo htmlspecialchars($sub['email']); ?><?php if ($sub['name']): ?> · <?php echo htmlspecialchars($sub['name']); ?><?php endif; ?>
                        </label>
                    <?php endforeach; ?>
                </div>
                <input type="hidden" name="selected_ids" id="selectedIds">
                <small class="field-hint">Only active subscribers are listed.</small>
            </div>
            <div id="singleGroup" class="form-group" hidden>
                <label for="single_email">Email address</label>
                <input type="email" id="single_email" name="single_email" value="<?php echo htmlspecialchars($prefill_email); ?>" placeholder="name@example.com">
                <small class="field-hint">A personal message to one person. If they are a subscriber their name is used.</small>
            </div>
            <p class="field-hint" id="recipientCount" aria-live="polite"></p>

            <fieldset class="form-group" style="border:0;border-top:1px solid var(--border);padding:16px 0 0;">
                <legend style="font-weight:600;margin-bottom:6px;">When</legend>
                <div class="nl-row">
                    <label><input type="radio" name="schedule_type" value="now" checked> Send now</label>
                    <label><input type="radio" name="schedule_type" value="later"> Schedule</label>
                </div>
                <div id="scheduleGroup" hidden style="margin-top:8px;">
                    <label for="schedule">Date and time</label>
                    <input type="datetime-local" id="schedule" name="schedule" min="<?php echo date('Y-m-d\TH:i'); ?>">
                    <small class="field-hint">This host has no timer: scheduled newsletters wait on the Campaigns tab until you press <strong>Send queued now</strong> on or after their time.</small>
                </div>
            </fieldset>

            <div class="form-group" style="border-top:1px solid var(--border);padding-top:16px;">
                <label for="attachments">Attachments</label>
                <input type="file" id="attachments" name="attachments[]" multiple accept=".pdf,.doc,.docx,.jpg,.jpeg,.png,.gif,.webp,.mp3,.txt">
                <small class="field-hint">PDF, Word, images, MP3 or text · up to 5 MB each and 10 MB in total. Large files make every email large, so a link is often better.</small>
                <div id="attachmentPreview" class="nl-row" style="margin-top:8px;"></div>
            </div>

            <div class="nl-row" style="justify-content:space-between;border-top:1px solid var(--border);padding-top:16px;">
                <div class="nl-row">
                    <label for="test_email" class="field-hint">Test to</label>
                    <input type="email" id="test_email" name="test_email" value="<?php echo htmlspecialchars(aw_admin_email()); ?>" style="width:auto;min-width:220px;">
                    <button type="button" id="testSendBtn" class="btn btn-sm btn-secondary"><i class="fas fa-vial"></i> Send test</button>
                </div>
                <div class="nl-row">
                    <input type="text" name="template_name" id="templateName" placeholder="Template name" style="width:auto;" aria-label="Template name">
                    <button type="button" id="saveTemplateBtn" class="btn btn-sm btn-outline"><i class="fas fa-save"></i> Save as template</button>
                    <button type="submit" class="btn btn-primary" id="sendBtn">📤 Send</button>
                </div>
            </div>
        </form>
        <?php if ($templates): ?>
            <details style="margin-top:18px;"><summary>Manage templates (<?php echo count($templates); ?>)</summary>
                <ul style="margin-top:8px;">
                    <?php foreach ($templates as $t): ?>
                        <li class="nl-row" style="justify-content:space-between;border-bottom:1px solid var(--border);padding:6px 0;">
                            <span><strong><?php echo htmlspecialchars($t['name']); ?></strong> · <?php echo htmlspecialchars($t['subject']); ?></span>
                            <form method="POST" onsubmit="return confirm('Delete this template?');"><?php echo aw_csrf_field(); ?><input type="hidden" name="action" value="delete_template"><input type="hidden" name="template_id" value="<?php echo (int)$t['id']; ?>"><button class="btn btn-sm btn-danger" type="submit" aria-label="Delete template <?php echo htmlspecialchars($t['name']); ?>"><i class="fas fa-trash"></i></button></form>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </details>
        <?php endif; ?>
        <form method="POST" action="<?php echo SITE_URL; ?>/admin/newsletter_preview.php" target="nlPreview" id="previewForm">
            <?php echo aw_csrf_field(); ?>
            <input type="hidden" name="subject"><input type="hidden" name="content">
        </form>
    </div>
</div>

<script>
(function () {
    const form = document.getElementById('composeForm');
    const templates = <?php echo json_encode(array_column($templates, null, 'id'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    const activeCount = <?php echo (int)$active_count; ?>;
    const content = () => (window.tinymce && tinymce.get('editor')) ? tinymce.get('editor').getContent() : document.getElementById('editor').value;
    const setContent = (html) => { if (window.tinymce && tinymce.get('editor')) tinymce.get('editor').setContent(html); else document.getElementById('editor').value = html; };
    const subj = document.getElementById('subject');
    const count = () => { document.getElementById('subjectCount').textContent = subj.value.length; };
    subj.addEventListener('input', count); count();

    function mode() { return form.querySelector('input[name="send_to"]:checked').value; }
    function updateGroups() {
        const m = mode();
        document.getElementById('segmentGroup').hidden = m !== 'segment';
        document.getElementById('selectedGroup').hidden = m !== 'selected';
        document.getElementById('singleGroup').hidden = m !== 'single';
        let n = 0;
        if (m === 'all') n = activeCount;
        if (m === 'segment') { const o = document.getElementById('segment_id').selectedOptions[0]; n = o && o.dataset.count ? +o.dataset.count : 0; }
        if (m === 'selected') n = document.querySelectorAll('.pick:checked').length;
        if (m === 'single') n = document.getElementById('single_email').value ? 1 : 0;
        document.getElementById('recipientCount').textContent = n === 1 ? 'This will go to 1 person.' : 'This will go to ' + n + ' people.';
        document.getElementById('sendBtn').textContent = (form.querySelector('input[name="schedule_type"]:checked').value === 'later' ? '🗓 Schedule for ' : '📤 Send to ') + n;
    }
    form.querySelectorAll('input[name="send_to"], input[name="schedule_type"]').forEach(r => r.addEventListener('change', function () {
        document.getElementById('scheduleGroup').hidden = form.querySelector('input[name="schedule_type"]:checked').value !== 'later';
        updateGroups();
    }));
    ['segment_id', 'single_email'].forEach(id => document.getElementById(id).addEventListener('input', updateGroups));
    document.querySelectorAll('.pick').forEach(cb => cb.addEventListener('change', function () {
        document.getElementById('selectedIds').value = Array.from(document.querySelectorAll('.pick:checked')).map(c => c.value).join(',');
        updateGroups();
    }));
    document.getElementById('pickFilter').addEventListener('input', function () {
        const q = this.value.toLowerCase();
        document.querySelectorAll('#pickList label').forEach(l => { l.style.display = l.dataset.text.includes(q) ? '' : 'none'; });
    });
    updateGroups();

    document.querySelectorAll('[data-token]').forEach(b => b.addEventListener('click', function () {
        if (window.tinymce && tinymce.get('editor')) tinymce.get('editor').insertContent(this.dataset.token);
        else { const t = document.getElementById('editor'); t.value += this.dataset.token; }
    }));
    const picker = document.getElementById('templatePicker');
    if (picker) picker.addEventListener('change', function () {
        const t = templates[this.value]; if (!t) return;
        if ((subj.value || content()) && !confirm('Replace the current subject and message with the template "' + t.name + '"?')) { this.value = ''; return; }
        subj.value = t.subject; setContent(t.content); document.getElementById('templateName').value = t.name; count(); this.value = '';
    });

    document.getElementById('previewBtn').addEventListener('click', function () {
        const pf = document.getElementById('previewForm');
        pf.subject.value = subj.value || '(no subject)'; pf.content.value = content();
        window.open('', 'nlPreview', 'width=680,height=860'); pf.submit();
    });
    document.getElementById('testSendBtn').addEventListener('click', function () {
        if (window.tinymce) tinymce.triggerSave();
        const fd = new FormData(form); fd.set('action', 'test_send'); fd.set('content', content());
        const btn = this; btn.disabled = true;
        fetch('manage_newsletter.php', { method: 'POST', body: fd, headers: { 'X-Requested-With': 'fetch' } })
            .then(r => r.json()).then(d => alert(d.message)).catch(() => alert('The test email could not be sent.'))
            .finally(() => { btn.disabled = false; });
    });
    document.getElementById('saveTemplateBtn').addEventListener('click', function () {
        if (!document.getElementById('templateName').value.trim()) { alert('Give the template a name first.'); return; }
        if (window.tinymce) tinymce.triggerSave();
        document.getElementById('composeAction').value = 'save_template';
        form.removeAttribute('enctype'); document.getElementById('attachments').disabled = true;
        form.submit();
    });
    form.addEventListener('submit', function (e) {
        if (window.tinymce) tinymce.triggerSave();
        if (document.getElementById('composeAction').value !== 'send_newsletter') return;
        if (mode() === 'selected' && !document.getElementById('selectedIds').value) { e.preventDefault(); alert('Choose at least one subscriber.'); return; }
        const n = document.getElementById('sendBtn').textContent.replace(/\D+/g, '');
        if (!confirm((form.querySelector('input[name="schedule_type"]:checked').value === 'later' ? 'Schedule' : 'Send') + ' "' + subj.value + '" to ' + n + ' ' + (n === '1' ? 'person' : 'people') + '?')) e.preventDefault();
    });
    document.getElementById('attachments').addEventListener('change', function () {
        const box = document.getElementById('attachmentPreview'); box.innerHTML = '';
        Array.from(this.files).forEach(f => {
            const s = document.createElement('span'); s.className = 'nl-chip';
            s.textContent = f.name + ' (' + (f.size / 1048576).toFixed(1) + ' MB)' + (f.size > 5 * 1048576 ? ' – too large' : '');
            box.appendChild(s);
        });
    });
})();
</script>
