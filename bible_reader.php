<?php
require_once 'includes/config.php';
require_once 'includes/db.php';
require_once 'includes/auth.php';

$pageTitle = 'KJV Bible Reader';
?>
<?php require_once 'includes/header.php'; ?>

<div class="bible-reader-page">
    <div class="container">
        <div class="page-header">
            <h1>📖 Bible Reader</h1>
            <p>Read, copy, compare, and highlight — all in one place.</p>
        </div>

        <!-- Version Selector -->
        <div class="version-selector">
            <label for="bibleVersion">Choose Translation:</label>
            <select id="bibleVersion">
                <option value="KJV">King James Version (KJV)</option>
                <option value="NIV">New International Version (NIV)</option>
                <option value="ESV">English Standard Version (ESV)</option>
                <option value="NASB">New American Standard Bible (NASB)</option>
                <option value="NKJV">New King James Version (NKJV)</option>
                <option value="AMP">Amplified Bible (AMP)</option>
                <option value="ASV">American Standard Version (ASV)</option>
                <option value="WEB">World English Bible (WEB)</option>
            </select>
        </div>

        <!-- Search / Navigation -->
        <div class="bible-navigation">
            <input type="text" id="verseInput" placeholder="e.g. John 3:16, Genesis 1:1" value="John 3:16">
            <button id="goToVerseBtn" class="btn btn-primary">Go</button>
        </div>

        <!-- Bible Reader Iframe -->
        <div class="bible-iframe-wrapper">
            <iframe 
                id="bibleIframe" 
                src="https://www.biblegateway.com/passage/?search=John+3&version=KJV" 
                width="100%" 
                height="700px" 
                frameborder="0" 
                allowfullscreen
                style="border: 1px solid var(--border); border-radius: 12px; box-shadow: var(--shadow);"
            ></iframe>
        </div>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        const versionSelect = document.getElementById('bibleVersion');
        const verseInput = document.getElementById('verseInput');
        const goBtn = document.getElementById('goToVerseBtn');
        const bibleIframe = document.getElementById('bibleIframe');

        // Function to update the iframe
        function updateBible() {
            const version = versionSelect.value;
            const query = verseInput.value.trim() || 'John 3:16';
            const encodedQuery = encodeURIComponent(query);
            bibleIframe.src = `https://www.biblegateway.com/passage/?search=${encodedQuery}&version=${version}`;
        }

        // Event Listeners
        goBtn.addEventListener('click', updateBible);
        versionSelect.addEventListener('change', updateBible);
        
        // Also update when Enter key is pressed in the input
        verseInput.addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                updateBible();
            }
        });
    });
</script>

<style>
    .bible-reader-page {
        padding: 32px 0 60px;
    }
    .page-header {
        text-align: center;
        margin-bottom: 24px;
    }
    .page-header h1 {
        font-size: 2.2rem;
        margin-bottom: 4px;
    }
    .page-header p {
        color: var(--text-light);
    }

    .version-selector {
        display: flex;
        justify-content: center;
        align-items: center;
        gap: 12px;
        margin-bottom: 16px;
        flex-wrap: wrap;
    }
    .version-selector label {
        font-weight: 600;
        font-size: 0.95rem;
    }
    .version-selector select {
        padding: 8px 16px;
        border-radius: 8px;
        border: 1px solid var(--border);
        background: var(--input-bg);
        color: var(--text);
        font-size: 0.95rem;
    }

    .bible-navigation {
        display: flex;
        justify-content: center;
        gap: 12px;
        margin-bottom: 20px;
    }
    .bible-navigation input {
        padding: 10px 16px;
        border: 1px solid var(--border);
        border-radius: 30px;
        width: 300px;
        max-width: 100%;
        font-size: 0.95rem;
        background: var(--input-bg);
        color: var(--text);
    }
    .bible-navigation input:focus {
        outline: none;
        border-color: var(--rose);
        box-shadow: 0 0 0 3px rgba(219, 161, 162, 0.15);
    }
    .bible-navigation .btn {
        padding: 10px 24px;
    }

    .bible-iframe-wrapper {
        max-width: 1000px;
        margin: 0 auto;
    }
</style>

<?php require_once 'includes/footer.php'; ?>