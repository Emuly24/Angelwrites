<div class="card">
    <div class="card-header">
        <h2>📤 Send or Schedule Newsletter</h2>
        <div style="display:flex;gap:8px;flex-wrap:wrap;">
            <button type="button" id="previewBtn" class="btn btn-sm btn-outline"><i class="fas fa-eye"></i> Preview</button>
            <button type="button" id="testSendBtn" class="btn btn-sm btn-secondary"><i class="fas fa-vial"></i> Send Test</button>
        </div>
    </div>
    <div class="card-body">
        <form method="POST" class="admin-form" enctype="multipart/form-data">
            <input type="hidden" name="action" value="send_newsletter">

            <div class="form-group">
                <label for="subject">Subject <span class="required">*</span></label>
                <input type="text" id="subject" name="subject" value="<?php echo htmlspecialchars($subject); ?>" placeholder="e.g., New Book Release" required>
            </div>
            <div class="form-group">
                <label for="editor">Message Content (HTML allowed) <span class="required">*</span></label>
                <textarea id="editor" name="content" rows="10"><?php echo htmlspecialchars($content); ?></textarea>
            </div>

            <div class="form-group">
                <label>Send to</label>
                <div style="display:flex;gap:16px;flex-wrap:wrap;">
                    <label><input type="radio" name="send_to" value="all" checked> All Active (<?php echo $active_count; ?>)</label>
                    <label><input type="radio" name="send_to" value="segment"> Segment</label>
                    <label><input type="radio" name="send_to" value="selected"> Select Subscribers</label>
                    <label><input type="radio" name="send_to" value="single"> Single Email</label>
                </div>
            </div>

            <div id="segmentGroup" style="display:none;">
                <div class="form-group">
                    <label for="segment_id">Select Segment</label>
                    <select id="segment_id" name="segment_id">
                        <option value="">— Choose —</option>
                        <?php foreach ($segments as $seg): ?>
                            <option value="<?php echo $seg['id']; ?>"><?php echo htmlspecialchars($seg['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div id="selectedGroup" style="display:none;">
                <div class="form-group">
                    <label>Select Subscribers</label>
                    <div style="border:1px solid var(--border);border-radius:8px;padding:12px;background:var(--fantasy);max-height:200px;overflow-y:auto;">
                        <?php foreach ($subscribers as $sub): ?>
                            <?php if ($sub['is_active']): ?>
                                <label style="display:flex;align-items:center;gap:6px;padding:2px 0;">
                                    <input type="checkbox" class="subscriber-checkbox" value="<?php echo htmlspecialchars($sub['email']); ?>">
                                    <?php echo htmlspecialchars($sub['email']); ?>
                                    <?php if ($sub['name']): ?> (<?php echo htmlspecialchars($sub['name']); ?>)<?php endif; ?>
                                </label>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </div>
                    <input type="hidden" name="selected_emails" id="selectedEmailsInput">
                    <small>Select subscribers above. Only active subscribers are shown.</small>
                </div>
            </div>

            <div id="singleGroup" style="display:none;">
                <div class="form-group">
                    <label for="single_email">Email Address</label>
                    <input type="email" id="single_email" name="single_email" placeholder="user@example.com">
                </div>
            </div>

            <div class="form-group" style="border-top:1px solid var(--border);padding-top:16px;">
                <label>Schedule</label>
                <div style="display:flex;gap:16px;flex-wrap:wrap;">
                    <label><input type="radio" name="schedule_type" value="now" checked> Send Now</label>
                    <label><input type="radio" name="schedule_type" value="later"> Schedule</label>
                </div>
                <div id="scheduleGroup" style="display:none;margin-top:8px;">
                    <label for="schedule">Date & Time</label>
                    <input type="datetime-local" id="schedule" name="schedule">
                </div>
            </div>

            <div class="form-group" style="border-top:1px solid var(--border);padding-top:16px;">
                <label for="attachments">Attachments</label>
                <input type="file" id="attachments" name="attachments[]" multiple accept=".pdf,.doc,.docx,.jpg,.jpeg,.png,.gif,.zip">
                <small class="field-hint">Max 10MB per file. Supported: PDF, DOC, DOCX, JPG, PNG, GIF, ZIP.</small>
                <div id="attachmentPreview" style="margin-top:8px;display:flex;flex-wrap:wrap;gap:8px;"></div>
            </div>

            <div class="form-actions">
                <button type="submit" class="btn btn-primary btn-block">📤 Send / Schedule</button>
            </div>
        </form>
    </div>
</div>

<script>
document.querySelectorAll('input[name="send_to"]').forEach(radio => {
    radio.addEventListener('change', function() {
        document.getElementById('segmentGroup').style.display = this.value === 'segment' ? 'block' : 'none';
        document.getElementById('selectedGroup').style.display = this.value === 'selected' ? 'block' : 'none';
        document.getElementById('singleGroup').style.display = this.value === 'single' ? 'block' : 'none';
    });
});

document.querySelectorAll('input[name="schedule_type"]').forEach(radio => {
    radio.addEventListener('change', function() {
        document.getElementById('scheduleGroup').style.display = this.value === 'later' ? 'block' : 'none';
    });
});

// Collect selected emails
document.querySelectorAll('.subscriber-checkbox').forEach(cb => {
    cb.addEventListener('change', function() {
        const selected = [];
        document.querySelectorAll('.subscriber-checkbox:checked').forEach(c => selected.push(c.value));
        document.getElementById('selectedEmailsInput').value = selected.join(',');
    });
});

// Test send
document.getElementById('testSendBtn')?.addEventListener('click', function() {
    const subject = document.getElementById('subject').value;
    const content = tinymce.get('editor').getContent();
    if (!subject || !content) {
        alert('Please fill in subject and content.');
        return;
    }
    const formData = new FormData();
    formData.append('action', 'send_newsletter');
    formData.append('subject', subject);
    formData.append('content', content);
    formData.append('send_to', 'single');
    formData.append('single_email', 'angelwrites@zohomail.com');
    formData.append('schedule_type', 'now');
    fetch('manage_newsletter.php', {
        method: 'POST',
        body: formData
    }).then(() => {
        alert('Test email sent to angelwrites@zohomail.com.');
    }).catch(() => {
        alert('Failed to send test email.');
    });
});

// Attachment preview
document.getElementById('attachments')?.addEventListener('change', function() {
    const preview = document.getElementById('attachmentPreview');
    preview.innerHTML = '';
    Array.from(this.files).forEach(file => {
        const span = document.createElement('span');
        span.style.cssText = 'background:var(--vanilla);padding:4px 10px;border-radius:12px;font-size:0.8rem;border:1px solid var(--border);';
        span.textContent = file.name + ' (' + (file.size/1024).toFixed(1) + ' KB)';
        preview.appendChild(span);
    });
});
</script>