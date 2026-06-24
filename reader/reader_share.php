<?php
// ============================================================
//  READER_SHARE.PHP – Unified sharing for the reader
//  Integrates with the existing #share-modal and share() function.
//  Include this file in reader.php (before closing </body>).
// ============================================================
?>
<script>
/**
 * ReaderShare – Handles sharing of pages, chapters, and quotes.
 * Uses the existing share modal and buttons defined in reader.php.
 */
class ReaderShare {
    /**
     * @param {number} bookId
     * @param {number} chapterIndex (0‑based)
     * @param {number} pageNumber   (1‑based)
     * @param {string|null} quote   Optional selected text to share.
     */
    constructor(bookId, chapterIndex, pageNumber, quote = null) {
        this.bookId = bookId;
        this.chapterIndex = chapterIndex;
        this.pageNumber = pageNumber;
        this.quote = quote;
        this.baseUrl = window.location.origin + '/reader/reader.php';
    }

    /**
     * Generate a shareable URL pointing to the current position.
     * @param {boolean} includeQuote – If true, appends quote as a parameter.
     * @returns {string}
     */
    getShareUrl(includeQuote = false) {
        let url = this.baseUrl + '?id=' + this.bookId +
                 '&chapter=' + this.chapterIndex +
                 '&page=' + this.pageNumber +
                 '&share=1';
        if (includeQuote && this.quote) {
            url += '&quote=' + encodeURIComponent(this.quote);
        }
        return url;
    }

    /**
     * Build the share message (text).
     * @param {string} platform – 'facebook', 'twitter', 'whatsapp', 'copy'
     * @returns {string}
     */
    getShareText(platform = '') {
        let text = '📖 I\'m reading on AngelWrites!';
        if (this.quote && this.quote.trim() !== '') {
            text = '"' + this.quote + '" – Read on AngelWrites!';
        } else {
            const chapterTitle = this.chapterIndex + 1;
            text = '📖 I\'m reading Chapter ' + chapterTitle + ' of this book on AngelWrites!';
        }
        return text;
    }

    /**
     * Share on Facebook.
     */
    shareFacebook() {
        const url = this.getShareUrl(true);
        const text = this.getShareText('facebook');
        const shareUrl = 'https://www.facebook.com/sharer/sharer.php?u=' +
                         encodeURIComponent(url) +
                         '&quote=' + encodeURIComponent(text);
        window.open(shareUrl, '_blank', 'width=600,height=400');
    }

    /**
     * Share on Twitter/X.
     */
    shareTwitter() {
        const url = this.getShareUrl(true);
        const text = this.getShareText('twitter');
        const shareUrl = 'https://twitter.com/intent/tweet?text=' +
                         encodeURIComponent(text) +
                         '&url=' + encodeURIComponent(url);
        window.open(shareUrl, '_blank', 'width=600,height=400');
    }

    /**
     * Share via WhatsApp.
     */
    shareWhatsApp() {
        const url = this.getShareUrl(true);
        const text = this.getShareText('whatsapp') + ' ' + url;
        const shareUrl = 'https://api.whatsapp.com/send?text=' + encodeURIComponent(text);
        window.open(shareUrl, '_blank', 'width=600,height=400');
    }

    /**
     * Copy the share URL to clipboard.
     */
    copyLink() {
        const url = this.getShareUrl(true);
        navigator.clipboard.writeText(url).then(() => {
            alert('✅ Link copied to clipboard!');
        }).catch(() => {
            const textarea = document.createElement('textarea');
            textarea.value = url;
            document.body.appendChild(textarea);
            textarea.select();
            document.execCommand('copy');
            document.body.removeChild(textarea);
            alert('✅ Link copied to clipboard!');
        });
    }
}

// ================================================================
// Integration with the existing reader UI
// ================================================================

document.addEventListener('DOMContentLoaded', function() {
    // ---- 1. Get current reader data ----
    const getReaderData = function() {
        const bookId = parseInt(document.getElementById('reader-app')?.dataset?.bookId) ||
                       <?php echo $book_id ?? 0; ?>;
        const chapter = parseInt(document.getElementById('chapterInfo')?.dataset?.chapter) ||
                        parseInt(document.querySelector('.toc-link.active')?.dataset?.chapter) ||
                        <?php echo $last_chapter ?? 0; ?>;
        const page = parseInt(document.getElementById('pageNum')?.textContent) ||
                     <?php echo $last_page ?? 1; ?>;
        return { bookId, chapter, page };
    };

    // ---- 2. Share button in sidebar (opens modal) ----
    const shareBtn = document.getElementById('shareBtn');
    if (shareBtn) {
        shareBtn.addEventListener('click', function(e) {
            e.preventDefault();
            // If there is a currently selected quote, use it
            let quote = null;
            const selection = window.getSelection();
            if (selection && !selection.isCollapsed) {
                quote = selection.toString().trim();
            }
            // Fallback to globally stored quote (from highlight tooltip)
            if (!quote && window.currentQuote) {
                quote = window.currentQuote;
            }

            const data = getReaderData();
            const share = new ReaderShare(data.bookId, data.chapter, data.page, quote);

            // Store the share instance globally for the modal buttons
            window.__currentShare = share;

            // Show the existing share modal (with quote preview)
            const preview = document.getElementById('shareQuotePreview');
            const quoteSpan = document.getElementById('shareQuoteText');
            if (quote && quote.trim() !== '') {
                quoteSpan.textContent = quote;
                preview.style.display = 'block';
            } else {
                preview.style.display = 'none';
            }

            // Open the modal (using the existing function)
            if (typeof openModal === 'function') {
                openModal('share-modal');
            } else {
                document.getElementById('share-modal')?.classList.add('visible');
                document.getElementById('overlay')?.classList.add('active');
            }
        });
    }

    // ---- 3. Bind the modal's share buttons to the stored share instance ----
    document.querySelectorAll('#share-modal .share-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            if (!window.__currentShare) {
                // Fallback: create a new share instance from current data
                const data = getReaderData();
                window.__currentShare = new ReaderShare(data.bookId, data.chapter, data.page);
            }
            const platform = this.getAttribute('onclick')?.match(/share\('([^']+)'\)/)?.[1];
            if (platform) {
                switch (platform) {
                    case 'facebook': window.__currentShare.shareFacebook(); break;
                    case 'twitter':  window.__currentShare.shareTwitter();  break;
                    case 'whatsapp': window.__currentShare.shareWhatsApp(); break;
                    case 'copy':     window.__currentShare.copyLink();      break;
                }
                // Close modal after sharing
                if (typeof closeModal === 'function') {
                    closeModal('share-modal');
                } else {
                    document.getElementById('share-modal')?.classList.remove('visible');
                    document.getElementById('overlay')?.classList.remove('active');
                }
                // Reset the stored quote
                window.currentQuote = null;
                window.__currentShare = null;
            }
        });
    });

    // ---- 4. Override the existing global share() function to use the class ----
    if (typeof window.share === 'function') {
        // Preserve the original for backward compatibility, but redirect to the class
        const originalShare = window.share;
        window.share = function(platform) {
            // If there's a stored share instance, use it; otherwise create one
            if (window.__currentShare) {
                switch (platform) {
                    case 'facebook': window.__currentShare.shareFacebook(); break;
                    case 'twitter':  window.__currentShare.shareTwitter();  break;
                    case 'whatsapp': window.__currentShare.shareWhatsApp(); break;
                    case 'copy':     window.__currentShare.copyLink();      break;
                }
                // Reset after share
                window.currentQuote = null;
                window.__currentShare = null;
            } else {
                // Fallback to the original function
                originalShare(platform);
            }
            // Close modal
            if (typeof closeModal === 'function') {
                closeModal('share-modal');
            } else {
                document.getElementById('share-modal')?.classList.remove('visible');
                document.getElementById('overlay')?.classList.remove('active');
            }
        };
    }

    // ---- 5. Auto‑detect quote from URL (if shared with a quote) ----
    const urlParams = new URLSearchParams(window.location.search);
    if (urlParams.get('share') === '1') {
        const quoteParam = urlParams.get('quote');
        if (quoteParam) {
            // Display a notification or highlight the quote
            setTimeout(() => {
                alert('📖 You were shared a quote:\n\n"' + decodeURIComponent(quoteParam) + '"');
            }, 500);
        }
    }
});
</script>