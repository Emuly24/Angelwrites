<div class="card">
    <div class="card-header">
        <h2>📤 Import Subscribers from CSV</h2>
    </div>
    <div class="card-body">
        <form method="POST" enctype="multipart/form-data" class="admin-form">
            <input type="hidden" name="action" value="import_csv">
            <div class="form-group">
                <label for="csv_file">CSV File</label>
                <input type="file" id="csv_file" name="csv_file" accept=".csv" required>
                <small class="field-hint">CSV must have at least an "email" column. Optional "name" column.</small>
            </div>
            <button type="submit" class="btn btn-primary">📤 Import</button>
        </form>
        <div style="margin-top:16px;padding:12px;background:var(--vanilla);border-radius:8px;">
            <h4>CSV Format Example:</h4>
            <pre style="background:#fff;padding:8px;border-radius:4px;font-size:0.85rem;">
email,name
john@example.com,John Doe
jane@example.com,Jane Smith
            </pre>
        </div>
    </div>
</div>