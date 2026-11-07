<?php if (!defined('AW_NEWSLETTER_ADMIN')) { http_response_code(403); exit('Access denied.'); } // S13 (F175): admin/manage_newsletter.php only ?>
<div class="card">
<?php
// N3: import asks for consent, accepts up to 2 MB, can put everyone in a segment, fills in missing
// names, and never re-subscribes someone who unsubscribed.
if (!defined('AW_NEWSLETTER_ADMIN') && basename($_SERVER['SCRIPT_FILENAME'] ?? '') !== 'manage_newsletter.php') { http_response_code(403); exit('Access denied.'); }
?>
    <div class="card-header"><h2>📥 Import subscribers from a CSV file</h2></div>
    <div class="card-body">
        <form method="POST" enctype="multipart/form-data" class="admin-form">
            <?php echo aw_csrf_field(); ?>
            <input type="hidden" name="action" value="import_csv">
            <div class="form-group"><label for="csv_file">CSV file (up to 2 MB)</label><input type="file" id="csv_file" name="csv_file" accept=".csv,text/csv" required></div>
            <div class="form-group"><label for="import_seg">Add everyone to a segment (optional)</label>
                <select id="import_seg" name="segment_id"><option value="">No segment</option><?php foreach ($segments as $seg): ?><option value="<?php echo (int)$seg['id']; ?>"><?php echo htmlspecialchars($seg['name']); ?></option><?php endforeach; ?></select></div>
            <div class="form-group"><label style="font-weight:400;"><input type="checkbox" name="consent" value="1" required> Everyone in this file agreed to receive the AngelWrites newsletter.</label></div>
            <button type="submit" class="btn btn-primary">📥 Import</button>
        </form>
        <div style="margin-top:16px;padding:12px;background:var(--vanilla);border-radius:8px;">
            <h4 style="margin-top:0;">Format</h4>
            <p class="field-hint">The first row names the columns: <code>email</code> (required) and <code>name</code> (optional). Invalid addresses are skipped, existing subscribers keep their status, and people who unsubscribed stay unsubscribed.</p>
            <pre style="background:#fff;padding:8px;border-radius:4px;font-size:0.85rem;">email,name
john@example.com,John Phiri
jane@example.com,Jane Banda</pre>
        </div>
    </div>
</div>
