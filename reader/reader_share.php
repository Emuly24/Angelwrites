<?php
// ============================================================
//  READER_SHARE.PHP – Social sharing for specific positions
// ============================================================
?>
<script>
class ReaderShare {
    constructor(bookId, chapterIndex, pageNumber) {
        this.bookId = bookId;
        this.chapterIndex = chapterIndex;
        this.pageNumber = pageNumber;
    }

    // Generate a shareable URL
    getShareUrl() {
        const base = window.location.origin + '/reader/reader.php';
        return base + '?id=' + this.bookId + '&chapter=' + this.chapterIndex + '&page=' + this.pageNumber + '&share=1';
    }

    // Share on Facebook
    shareFacebook() {
        const url = this.getShareUrl();
        const text = '📖 I\'m reading Chapter ' + (this.chapterIndex + 1) + ' of this book on AngelWrites!';
        const shareUrl = 'https://www.facebook.com/sharer/sharer.php?u=' + encodeURIComponent(url) + '&quote=' + encodeURIComponent(text);
        window.open(shareUrl, '_blank', 'width=600,height=400');
    }

    // Share on Twitter/X
    shareTwitter() {
        const url = this.getShareUrl();
        const text = '📖 I\'m reading Chapter ' + (this.chapterIndex + 1) + ' of this book on AngelWrites!';
        const shareUrl = 'https://twitter.com/intent/tweet?text=' + encodeURIComponent(text) + '&url=' + encodeURIComponent(url);
        window.open(shareUrl, '_blank', 'width=600,height=400');
    }

    // Share via WhatsApp
    shareWhatsApp() {
        const url = this.getShareUrl();
        const text = '📖 I\'m reading Chapter ' + (this.chapterIndex + 1) + ' of this book on AngelWrites! ' + url;
        const shareUrl = 'https://api.whatsapp.com/send?text=' + encodeURIComponent(text);
        window.open(shareUrl, '_blank', 'width=600,height=400');
    }

    // Copy link to clipboard
    copyLink() {
        const url = this.getShareUrl();
        navigator.clipboard.writeText(url).then(() => {
            alert('✅ Link copied to clipboard!');
        }).catch(() => {
            // Fallback
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

// Initialize on the reader page
document.addEventListener('DOMContentLoaded', function() {
    const shareBtn = document.getElementById('awShareBtn');
    if (shareBtn) {
        shareBtn.addEventListener('click', function() {
            const bookId = parseInt(document.getElementById('awReader').dataset.bookId);
            const chapter = parseInt(document.getElementById('awReader').dataset.chapter || 0);
            const page = parseInt(document.getElementById('awReader').dataset.page || 0);
            const share = new ReaderShare(bookId, chapter, page);
            // Show a dropdown or modal with share options
            const shareModal = document.getElementById('awShareModal');
            if (shareModal) {
                shareModal.classList.add('visible');
            }
        });
    }

    // Share buttons inside the modal
    document.querySelectorAll('.aw-share-facebook').forEach(btn => {
        btn.addEventListener('click', function() {
            const bookId = parseInt(document.getElementById('awReader').dataset.bookId);
            const chapter = parseInt(document.getElementById('awReader').dataset.chapter || 0);
            const page = parseInt(document.getElementById('awReader').dataset.page || 0);
            const share = new ReaderShare(bookId, chapter, page);
            share.shareFacebook();
        });
    });

    document.querySelectorAll('.aw-share-twitter').forEach(btn => {
        btn.addEventListener('click', function() {
            const bookId = parseInt(document.getElementById('awReader').dataset.bookId);
            const chapter = parseInt(document.getElementById('awReader').dataset.chapter || 0);
            const page = parseInt(document.getElementById('awReader').dataset.page || 0);
            const share = new ReaderShare(bookId, chapter, page);
            share.shareTwitter();
        });
    });

    document.querySelectorAll('.aw-share-whatsapp').forEach(btn => {
        btn.addEventListener('click', function() {
            const bookId = parseInt(document.getElementById('awReader').dataset.bookId);
            const chapter = parseInt(document.getElementById('awReader').dataset.chapter || 0);
            const page = parseInt(document.getElementById('awReader').dataset.page || 0);
            const share = new ReaderShare(bookId, chapter, page);
            share.shareWhatsApp();
        });
    });

    document.querySelectorAll('.aw-share-copy').forEach(btn => {
        btn.addEventListener('click', function() {
            const bookId = parseInt(document.getElementById('awReader').dataset.bookId);
            const chapter = parseInt(document.getElementById('awReader').dataset.chapter || 0);
            const page = parseInt(document.getElementById('awReader').dataset.page || 0);
            const share = new ReaderShare(bookId, chapter, page);
            share.copyLink();
        });
    });

    // Close modal
    document.querySelectorAll('.aw-share-modal-close').forEach(btn => {
        btn.addEventListener('click', function() {
            document.getElementById('awShareModal').classList.remove('visible');
        });
    });
});
</script>