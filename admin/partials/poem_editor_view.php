<?php
// The full-page poem editor (admin/manage_poems.php?new=1 or ?edit=ID).
// Two columns from 1024 px (the text on the left, publishing and media on the right), one column below.
defined('AW_POEMS_ADMIN') or exit;
$isNew = $f['id'] === 0;
$live = $f['status'] === 'published';
$csrf = htmlspecialchars(generate_csrf_token());
$imgUrl = $f['image'] !== '' ? SITE_URL . '/' . ltrim($f['image'], '/') : '';
$audUrl = $f['audio'] !== '' ? SITE_URL . '/' . ltrim($f['audio'], '/') : '';
?>
<link rel="stylesheet" href="<?php echo SITE_URL; ?>/assets/css/admin-poems.css?v=3">
<div class="pe-page pe-editor-page">
    <nav class="pe-crumbs" aria-label="Breadcrumb">
        <a href="<?php echo SITE_URL; ?>/admin/manage_poems.php"><i class="fas fa-arrow-left" aria-hidden="true"></i> All poems</a>
    </nav>
    <div class="pe-head">
        <div>
            <h1><?php echo $isNew ? 'New poem' : 'Edit poem'; ?></h1>
            <?php if (!$isNew): ?>
                <p class="pe-sub"><span class="pe-badge <?php echo $live ? 'is-live' : 'is-draft'; ?>"><?php echo $live ? 'Live' : 'Draft'; ?></span>
                    Added <?php echo htmlspecialchars(date('M j, Y', strtotime($f['created_at']))); ?><?php if ($f['updated_at'] !== '' && $f['updated_at'] !== $f['created_at']): ?> · last changed <?php echo htmlspecialchars(date('M j, Y g:i a', strtotime($f['updated_at']))); ?><?php endif; ?></p>
            <?php endif; ?>
        </div>
        <div class="pe-head-actions">
            <?php if (!$isNew): ?><a class="btn btn-outline btn-sm" href="<?php echo SITE_URL; ?>/poem_view.php?id=<?php echo $f['id']; ?>" target="_blank" rel="noopener"><i class="fas fa-external-link-alt" aria-hidden="true"></i> View on site</a><?php endif; ?>
            <button type="button" class="btn btn-outline btn-sm" id="previewBtn"><i class="fas fa-eye" aria-hidden="true"></i> Preview</button>
        </div>
    </div>

    <?php if ($flash_message !== ''): ?>
        <div class="alert alert-<?php echo $flash_type === 'error' ? 'error' : 'success'; ?>" role="<?php echo $flash_type === 'error' ? 'alert' : 'status'; ?>"><?php echo htmlspecialchars($flash_message); ?></div>
    <?php endif; ?>

    <form method="POST" enctype="multipart/form-data" class="pe-form" id="poemForm" novalidate>
        <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
        <input type="hidden" name="poem_id" value="<?php echo (int)$f['id']; ?>">
        <input type="hidden" name="save_poem" value="1">

        <div class="pe-grid">
            <div class="pe-main">
                <section class="pe-card">
                    <div class="pe-field">
                        <label for="title">Title <span class="pe-req" aria-hidden="true">*</span></label>
                        <input type="text" id="title" name="title" required aria-required="true" maxlength="200" value="<?php echo htmlspecialchars($f['title']); ?>">
                    </div>
                    <div class="pe-field">
                        <label for="intro">Purpose of this poem <span class="pe-opt">(optional)</span></label>
                        <textarea id="intro" name="intro" rows="4" aria-describedby="introHint"><?php echo htmlspecialchars($f['intro']); ?></textarea>
                        <small class="pe-hint" id="introHint">Shown above the poem as “Purpose of this poem”. Leave a blank line between paragraphs.</small>
                    </div>
                    <div class="pe-field">
                        <label for="editor">The poem <span class="pe-req" aria-hidden="true">*</span></label>
                        <textarea id="editor" name="content" rows="16" required aria-required="true"><?php echo htmlspecialchars($f['content']); ?></textarea>
                        <small class="pe-hint">One line per line of the poem; an empty line starts a new stanza.</small>
                    </div>
                </section>
            </div>

            <aside class="pe-side">
                <section class="pe-card" aria-labelledby="pubTitle">
                    <h2 id="pubTitle">Publishing</h2>
                    <fieldset class="pe-field pe-status">
                        <legend>Status</legend>
                        <label class="pe-radio"><input type="radio" name="status" value="draft"<?php echo $live ? '' : ' checked'; ?>> <span><strong>Draft</strong> only admins can see it</span></label>
                        <label class="pe-radio"><input type="radio" name="status" value="published"<?php echo $live ? ' checked' : ''; ?>> <span><strong>Live</strong> everyone can read it</span></label>
                    </fieldset>
                    <div class="pe-field">
                        <label for="written_on">Date written <span class="pe-opt">(optional)</span></label>
                        <input type="date" id="written_on" name="written_on" value="<?php echo htmlspecialchars($f['written_on']); ?>" max="<?php echo date('Y-m-d'); ?>" min="1900-01-01" aria-describedby="writtenHint">
                        <small class="pe-hint" id="writtenHint">For a poem written before it was added here. Readers see this date. Leave it empty to show the day it was added<?php echo $isNew ? '' : ' (' . htmlspecialchars(date('M j, Y', strtotime($f['created_at']))) . ')'; ?>.</small>
                        <?php if ($f['written_on'] !== ''): ?><button type="button" class="pe-link" id="clearWritten">Clear the date</button><?php endif; ?>
                    </div>
                    <div class="pe-field">
                        <label for="author">Author</label>
                        <input type="text" id="author" name="author" maxlength="120" value="<?php echo htmlspecialchars($f['author']); ?>" aria-describedby="authorHint">
                        <small class="pe-hint" id="authorHint">Shown as “A poem by …”.</small>
                    </div>
                </section>

                <section class="pe-card" aria-labelledby="imgTitle">
                    <h2 id="imgTitle">Cover image</h2>
                    <?php if ($imgUrl !== ''): ?>
                        <figure class="pe-current">
                            <img src="<?php echo htmlspecialchars($imgUrl); ?>" alt="Current cover image" onerror="this.closest('figure').classList.add('is-missing')">
                            <figcaption class="pe-missing-note">The image file is missing on the server. Upload a new one or remove it.</figcaption>
                        </figure>
                        <label class="pe-check"><input type="checkbox" name="remove_image" value="1" id="removeImage"> <span>Remove this image</span></label>
                    <?php endif; ?>
                    <div class="pe-drop" id="imageDrop" tabindex="0" role="button" aria-describedby="imageHint">
                        <i class="fas fa-image" aria-hidden="true"></i>
                        <span><strong><?php echo $imgUrl !== '' ? 'Replace the image' : 'Add an image'; ?></strong><br>drop it here or tap to choose</span>
                        <img id="imagePreview" alt="" hidden>
                    </div>
                    <input type="file" id="imageInput" name="image_fallback" class="sr-only"<?php echo aw_upload_attrs('image'); ?> tabindex="-1">
                    <small class="pe-hint" id="imageHint"><?php echo htmlspecialchars(aw_upload_hint('image')); ?></small>
                    <div class="pe-row">
                        <button type="button" class="btn btn-outline btn-sm" id="openCamera"><i class="fas fa-camera" aria-hidden="true"></i> Take a photo</button>
                        <span class="pe-hint" id="photoStatus" aria-live="polite"></span>
                    </div>
                    <input type="file" id="cameraInput" name="image" accept="image/*" class="sr-only" tabindex="-1">
                </section>

                <section class="pe-card" aria-labelledby="audTitle">
                    <h2 id="audTitle">Recording</h2>
                    <?php if ($audUrl !== ''): ?>
                        <audio controls preload="none" src="<?php echo htmlspecialchars($audUrl); ?>" class="pe-audio"></audio>
                        <label class="pe-check"><input type="checkbox" name="remove_audio" value="1" id="removeAudio"> <span>Remove this recording</span></label>
                    <?php endif; ?>
                    <div class="pe-drop" id="audioDrop" tabindex="0" role="button" aria-describedby="audioHint">
                        <i class="fas fa-music" aria-hidden="true"></i>
                        <span><strong><?php echo $audUrl !== '' ? 'Replace the recording' : 'Add a recording'; ?></strong><br>drop an audio file or tap to choose</span>
                    </div>
                    <input type="file" id="audioInput" name="audio" class="sr-only"<?php echo aw_upload_attrs('audio'); ?> tabindex="-1">
                    <audio controls id="audioPreview" class="pe-audio" hidden></audio>
                    <small class="pe-hint" id="audioHint"><?php echo htmlspecialchars(aw_upload_hint('audio')); ?></small>
                    <div class="pe-row">
                        <button type="button" class="btn btn-outline btn-sm" id="recordBtn" aria-pressed="false"><i class="fas fa-microphone" aria-hidden="true"></i> <span>Record now</span></button>
                        <span class="pe-rec-status" id="recStatus" aria-live="polite"></span>
                    </div>
                    <input type="file" id="recordingInput" name="audio_recording" class="sr-only" tabindex="-1"<?php echo aw_upload_attrs('audio'); ?>>
                    <audio controls id="recordingPreview" class="pe-audio" hidden></audio>
                </section>
            </aside>
        </div>

        <div class="pe-savebar">
            <a class="btn btn-outline" href="<?php echo SITE_URL; ?>/admin/manage_poems.php">Cancel</a>
            <button type="submit" class="btn btn-primary" id="saveBtn"><i class="fas fa-check" aria-hidden="true"></i> <?php echo $isNew ? 'Add poem' : 'Save changes'; ?></button>
        </div>
    </form>

    <?php if (!$isNew): ?>
    <form method="POST" class="pe-danger-zone" onsubmit="return confirm('Delete “<?php echo htmlspecialchars(addslashes($f['title'])); ?>” and its comments for good?');">
        <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
        <button type="submit" class="pe-link pe-link-danger" name="row_action" value="delete:<?php echo $f['id']; ?>"><i class="fas fa-trash" aria-hidden="true"></i> Delete this poem</button>
    </form>
    <?php endif; ?>
</div>

<!-- Preview -->
<div class="pe-dialog" id="previewDialog" role="dialog" aria-modal="true" aria-labelledby="pvTitle" hidden>
    <div class="pe-dialog-box">
        <button type="button" class="pe-dialog-close" id="previewClose" aria-label="Close preview">&times;</button>
        <p class="pe-pv-kicker" id="pvKicker"></p>
        <h2 id="pvTitle"></h2>
        <p class="pe-pv-date" id="pvDate"></p>
        <div class="pe-pv-intro" id="pvIntro"></div>
        <div class="pe-pv-body" id="pvBody"></div>
    </div>
</div>

<!-- Camera (photo) -->
<div class="pe-camera" id="cameraDialog" role="dialog" aria-modal="true" aria-label="Take a photo" hidden>
    <video id="cameraVideo" autoplay muted playsinline></video>
    <div class="pe-camera-bar">
        <button type="button" class="pe-cam-btn" id="cameraClose">Cancel</button>
        <button type="button" class="pe-shutter" id="cameraShoot" aria-label="Take the photo"></button>
        <button type="button" class="pe-cam-btn" id="cameraFlip"><i class="fas fa-sync-alt" aria-hidden="true"></i> Flip</button>
    </div>
    <p class="pe-camera-status" id="cameraStatus" aria-live="polite"></p>
</div>

<script src="https://cdn.jsdelivr.net/npm/tinymce@6.8.3/tinymce.min.js"></script>
<script>
(function () {
    var form = document.getElementById('poemForm');
    var narrow = window.matchMedia('(max-width: 1023px)').matches;

    // ---- editor (falls back to the plain textarea if TinyMCE cannot load) ----
    if (window.tinymce) {
        tinymce.init({
            selector: '#editor',
            height: narrow ? 420 : 560,
            menubar: false,
            toolbar_mode: 'sliding',
            plugins: 'autolink charmap emoticons link lists searchreplace visualblocks wordcount code',
            toolbar: 'undo redo | bold italic underline | alignleft aligncenter alignright | bullist numlist | link charmap emoticons | removeformat code',
            content_style: 'body { font-family: Georgia, serif; font-size: 18px; line-height: 1.8; max-width: 40em; }',
            forced_root_block: 'p',
            branding: false,
            promotion: false
        });
    }
    function poemHtml() { return (window.tinymce && tinymce.get('editor')) ? tinymce.get('editor').getContent() : document.getElementById('editor').value; }

    // ---- save: write the editor back, check the two required fields ----
    // (submitted with form.submit() so the site-wide submit handler in main.js does not interfere)
    var saveBtn = document.getElementById('saveBtn');
    saveBtn.addEventListener('click', function (e) {
        e.preventDefault();
        if (window.tinymce && tinymce.get('editor')) tinymce.get('editor').save();
        var title = document.getElementById('title');
        if (!title.value.trim()) { title.focus(); title.setAttribute('aria-invalid', 'true'); alert('Please give the poem a title.'); return; }
        if (!poemHtml().replace(/<[^>]*>|&nbsp;|\s/g, '')) { alert('Please write the poem.'); return; }
        saveBtn.disabled = true;
        saveBtn.lastChild.textContent = ' Saving…';
        form.submit();
    });

    var clearWritten = document.getElementById('clearWritten');
    if (clearWritten) clearWritten.addEventListener('click', function () { var w = document.getElementById('written_on'); w.value = ''; w.focus(); });

    // ---- drop zones (image and audio) ----
    function dropZone(zoneId, inputId, kind, onPick) {
        var zone = document.getElementById(zoneId), input = document.getElementById(inputId);
        if (!zone || !input) return;
        zone.addEventListener('click', function () { input.click(); });
        zone.addEventListener('keydown', function (e) { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); input.click(); } });
        input.addEventListener('change', function () { if (input.files[0]) onPick(input.files[0]); });
        ['dragover', 'dragenter'].forEach(function (t) { zone.addEventListener(t, function (e) { e.preventDefault(); zone.classList.add('is-over'); }); });
        ['dragleave', 'drop'].forEach(function (t) { zone.addEventListener(t, function (e) { e.preventDefault(); zone.classList.remove('is-over'); }); });
        zone.addEventListener('drop', function (e) {
            var file = e.dataTransfer.files[0];
            if (!file) return;
            if (file.type.indexOf(kind + '/') !== 0) { alert(kind === 'image' ? 'Please drop an image file.' : 'Please drop an audio file.'); return; }
            var dt = new DataTransfer(); dt.items.add(file); input.files = dt.files; onPick(file);
        });
    }
    dropZone('imageDrop', 'imageInput', 'image', function (file) {
        var img = document.getElementById('imagePreview');
        img.src = URL.createObjectURL(file); img.hidden = false;
        var rm = document.getElementById('removeImage'); if (rm) rm.checked = false;
        document.getElementById('cameraInput').value = ''; document.getElementById('photoStatus').textContent = ''; // the latest choice wins
    });
    dropZone('audioDrop', 'audioInput', 'audio', function (file) {
        var a = document.getElementById('audioPreview');
        a.src = URL.createObjectURL(file); a.hidden = false;
        var rm = document.getElementById('removeAudio'); if (rm) rm.checked = false;
        document.getElementById('recordingInput').value = ''; document.getElementById('recordingPreview').hidden = true; recStatus.textContent = '';
    });

    // ---- recorder ----
    var recBtn = document.getElementById('recordBtn'), recStatus = document.getElementById('recStatus');
    var rec = null, recStream = null, chunks = [];
    recBtn.addEventListener('click', async function () {
        if (rec && rec.state === 'recording') { rec.stop(); return; }
        if (!navigator.mediaDevices || !window.MediaRecorder) { recStatus.textContent = 'Recording is not available in this browser.'; return; }
        try {
            recStream = await navigator.mediaDevices.getUserMedia({ audio: true });
        } catch (err) { recStatus.textContent = 'Microphone access was not allowed.'; return; }
        chunks = [];
        rec = new MediaRecorder(recStream);
        rec.ondataavailable = function (e) { if (e.data.size) chunks.push(e.data); };
        rec.onstop = function () {
            recStream.getTracks().forEach(function (t) { t.stop(); });
            var type = rec.mimeType || 'audio/webm';
            var file = new File([new Blob(chunks, { type: type })], 'poem_recording.' + (type.indexOf('mp4') > -1 ? 'm4a' : 'webm'), { type: type });
            var dt = new DataTransfer(); dt.items.add(file); document.getElementById('recordingInput').files = dt.files;
            document.getElementById('audioInput').value = ''; document.getElementById('audioPreview').hidden = true; // the latest choice wins
            var p = document.getElementById('recordingPreview'); p.src = URL.createObjectURL(file); p.hidden = false;
            var rm = document.getElementById('removeAudio'); if (rm) rm.checked = false;
            recBtn.setAttribute('aria-pressed', 'false'); recBtn.querySelector('span').textContent = 'Record again';
            recStatus.textContent = 'Recorded. It will be saved with the poem.';
        };
        rec.start();
        recBtn.setAttribute('aria-pressed', 'true'); recBtn.querySelector('span').textContent = 'Stop recording';
        recStatus.textContent = 'Recording…';
    });

    // ---- camera (photo) ----
    var cam = document.getElementById('cameraDialog'), video = document.getElementById('cameraVideo'), camStatus = document.getElementById('cameraStatus');
    var camStream = null, facing = 'environment', lastFocus = null;
    async function startCam() {
        if (camStream) camStream.getTracks().forEach(function (t) { t.stop(); });
        try {
            camStream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: facing }, audio: false });
            video.srcObject = camStream; camStatus.textContent = '';
        } catch (err) { camStatus.textContent = 'The camera could not be opened. You can choose a picture instead.'; }
    }
    function closeCam() {
        if (camStream) camStream.getTracks().forEach(function (t) { t.stop(); });
        camStream = null; video.srcObject = null; cam.hidden = true;
        if (lastFocus) lastFocus.focus();
    }
    document.getElementById('openCamera').addEventListener('click', function () {
        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) { document.getElementById('imageInput').click(); return; }
        lastFocus = this; cam.hidden = false; document.getElementById('cameraShoot').focus(); startCam();
    });
    document.getElementById('cameraClose').addEventListener('click', closeCam);
    document.getElementById('cameraFlip').addEventListener('click', function () { facing = facing === 'user' ? 'environment' : 'user'; startCam(); });
    document.getElementById('cameraShoot').addEventListener('click', function () {
        if (!camStream) return;
        var c = document.createElement('canvas');
        c.width = video.videoWidth || 1280; c.height = video.videoHeight || 720;
        c.getContext('2d').drawImage(video, 0, 0, c.width, c.height);
        c.toBlob(function (blob) {
            var file = new File([blob], 'photo.jpg', { type: 'image/jpeg' });
            var dt = new DataTransfer(); dt.items.add(file); document.getElementById('cameraInput').files = dt.files;
            document.getElementById('imageInput').value = ''; // the photo replaces a chosen file
            var img = document.getElementById('imagePreview'); img.src = URL.createObjectURL(file); img.hidden = false;
            var rm = document.getElementById('removeImage'); if (rm) rm.checked = false;
            document.getElementById('photoStatus').textContent = 'Photo taken. It will be saved with the poem.';
            closeCam();
        }, 'image/jpeg', 0.9);
    });

    // ---- preview ----
    var dlg = document.getElementById('previewDialog'), pvOpener = null;
    function fmtDate(v) { if (!v) return ''; var d = new Date(v + 'T12:00:00'); return isNaN(d) ? '' : d.toLocaleDateString(undefined, { year: 'numeric', month: 'long', day: 'numeric' }); }
    document.getElementById('previewBtn').addEventListener('click', function () {
        pvOpener = this;
        var author = document.getElementById('author').value.trim() || <?php echo json_encode(AW_POEM_DEFAULT_AUTHOR); ?>;
        document.getElementById('pvKicker').textContent = 'A poem by ' + author;
        document.getElementById('pvTitle').textContent = document.getElementById('title').value.trim() || 'Untitled';
        var w = document.getElementById('written_on').value;
        document.getElementById('pvDate').textContent = w ? 'Written ' + fmtDate(w) : '';
        var intro = document.getElementById('intro').value.trim(), pi = document.getElementById('pvIntro');
        pi.textContent = intro; pi.hidden = !intro;
        // the preview shows the text as the editor holds it; scripts are never part of a poem
        var tmp = document.createElement('div'); tmp.innerHTML = poemHtml();
        tmp.querySelectorAll('script,iframe,object,embed').forEach(function (n) { n.remove(); });
        document.getElementById('pvBody').innerHTML = tmp.innerHTML || '<p><em>Nothing written yet.</em></p>';
        dlg.hidden = false; document.getElementById('previewClose').focus();
    });
    function closePv() { dlg.hidden = true; if (pvOpener) pvOpener.focus(); }
    document.getElementById('previewClose').addEventListener('click', closePv);
    dlg.addEventListener('click', function (e) { if (e.target === dlg) closePv(); });
    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Escape') return;
        if (!dlg.hidden) closePv();
        if (!cam.hidden) closeCam();
    });

    // ---- leaving with unsaved changes ----
    var dirty = false;
    form.addEventListener('input', function () { dirty = true; });
    form.addEventListener('change', function () { dirty = true; });
    if (window.tinymce) tinymce.on('AddEditor', function (ev) { ev.editor.on('input change', function () { dirty = true; }); });
    window.addEventListener('beforeunload', function (e) { if (dirty && !saveBtn.disabled) { e.preventDefault(); e.returnValue = ''; } });
})();
</script>
