<?php
// ============================================================
// Close the main content wrapper opened in header.php
// ============================================================
?>
    </main> <!-- Close main content -->

<!-- ============================================================
TOC NAVIGATOR (Appears on all pages after scrolling 400px)
============================================================ -->
<div id="tocFloatingBtn" title="Navigate Sections">
    <i class="fas fa-list-ul"></i>
</div>
<div id="tocPanel">
    <div class="toc-header">
        <span>📑 Jump to Section</span>
        <button id="tocCloseBtn">&times;</button>
    </div>
    <div id="tocList"></div>
    <div class="toc-footer">
        <button id="tocBackToTop">⬆ Back to Top</button>
    </div>
</div>

<!-- TOC Styles -->
<style>
    /* ===== TOC FLOATING BUTTON ===== */
    #tocFloatingBtn {
        position: fixed;
        bottom: 25px;
        right: 25px;
        width: 50px;
        height: 50px;
        background: var(--rose);
        color: var(--white);
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.2rem;
        cursor: pointer;
        box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        z-index: 1000;
        transition: all 0.3s cubic-bezier(0.2, 0.9, 0.4, 1);
        opacity: 0;
        transform: scale(0.8);
        pointer-events: none;
    }
    #tocFloatingBtn.visible {
        opacity: 1;
        transform: scale(1);
        pointer-events: auto;
    }
    #tocFloatingBtn:hover {
        background: var(--rose-dark);
        transform: scale(1.05);
    }

    /* ===== TOC PANEL ===== */
    #tocPanel {
        position: fixed;
        bottom: 85px;
        right: 20px;
        width: 320px;
        max-width: 90vw;
        max-height: 70vh;
        background: var(--card-bg);
        border-radius: 1rem;
        box-shadow: 0 8px 30px rgba(0,0,0,0.2);
        z-index: 999;
        display: none;
        flex-direction: column;
        overflow: hidden;
        border: 1px solid var(--border);
        transform-origin: bottom right;
        animation: tocSlideUp 0.25s ease;
    }
    #tocPanel.open {
        display: flex;
    }

    @keyframes tocSlideUp {
        from { opacity: 0; transform: scale(0.9) translateY(20px); }
        to { opacity: 1; transform: scale(1) translateY(0); }
    }

    .toc-header {
        background: var(--dark);
        color: var(--white);
        padding: 0.8rem 1.2rem;
        display: flex;
        justify-content: space-between;
        align-items: center;
        border-radius: 1rem 1rem 0 0;
        flex-shrink: 0;
    }
    .toc-header span {
        font-weight: 600;
        font-size: 0.95rem;
        color: var(--rose);
    }
    .toc-header button {
        background: transparent;
        border: none;
        color: var(--white);
        font-size: 1.5rem;
        cursor: pointer;
        padding: 0 0.3rem;
        transition: transform 0.2s;
    }
    .toc-header button:hover {
        transform: rotate(90deg);
    }

    #tocList {
        flex: 1;
        overflow-y: auto;
        padding: 0.5rem 0;
        scrollbar-width: thin;
    }
    #tocList::-webkit-scrollbar {
        width: 4px;
    }
    #tocList::-webkit-scrollbar-thumb {
        background: var(--rose);
        border-radius: 4px;
    }

    .toc-item {
        padding: 0.5rem 1.2rem;
        cursor: pointer;
        transition: all 0.15s ease;
        font-size: 0.9rem;
        color: var(--text);
        border-left: 3px solid transparent;
    }
    .toc-item:hover {
        background: var(--vanilla);
        border-left-color: var(--rose);
    }
    .toc-item.level-h2 {
        font-weight: 600;
    }
    .toc-item.level-h3 {
        padding-left: 2rem;
        font-weight: 400;
    }
    .toc-item.level-h4 {
        padding-left: 3rem;
        font-weight: 300;
        font-size: 0.85rem;
    }

    .toc-footer {
        padding: 0.6rem 1.2rem;
        border-top: 1px solid var(--border);
        flex-shrink: 0;
    }
    #tocBackToTop {
        width: 100%;
        padding: 0.5rem;
        background: var(--rose);
        color: var(--white);
        border: none;
        border-radius: 2rem;
        font-weight: 600;
        cursor: pointer;
        transition: background 0.2s;
    }
    #tocBackToTop:hover {
        background: var(--rose-dark);
    }
</style>

<!-- TOC JavaScript -->
<script>
    (function() {
        'use strict';

        const btn = document.getElementById('tocFloatingBtn');
        const panel = document.getElementById('tocPanel');
        const listEl = document.getElementById('tocList');
        const closeBtn = document.getElementById('tocCloseBtn');
        const backToTopBtn = document.getElementById('tocBackToTop');

        function isElementVisible(el) {
            while (el) {
                const style = window.getComputedStyle(el);
                if (style.display === 'none' || style.visibility === 'hidden' || parseFloat(style.opacity) === 0) {
                    return false;
                }
                el = el.parentElement;
            }
            return true;
        }

        function scanHeadings() {
            const containers = document.querySelectorAll('.container, .auth-page, .user-dashboard, .library-page, .books-page, .poetry-page, .blog-page, .about-page, .contact-page');
            let headings = [];
            containers.forEach(container => {
                const found = container.querySelectorAll('h2, h3, h4');
                found.forEach(h => {
                    if (h.textContent.trim().length === 0) return;
                    if (!isElementVisible(h)) return;
                    headings.push(h);
                });
            });
            if (headings.length === 0) {
                const excludedTags = ['header', 'nav', 'footer', '.site-header', '.footer'];
                document.querySelectorAll('h2, h3, h4').forEach(h => {
                    let parent = h.parentElement;
                    while (parent) {
                        if (excludedTags.some(tag => parent.matches && parent.matches(tag) || parent.tagName.toLowerCase() === tag)) {
                            return;
                        }
                        parent = parent.parentElement;
                    }
                    if (!isElementVisible(h)) return;
                    headings.push(h);
                });
            }
            return headings;
        }

        function buildTOC(headings) {
            listEl.innerHTML = '';
            if (headings.length === 0) {
                listEl.innerHTML = '<div style="padding:1rem;text-align:center;color:var(--text-light);">No visible sections found.</div>';
                return;
            }
            headings.forEach((h, index) => {
                const level = h.tagName.toLowerCase();
                const item = document.createElement('div');
                item.className = `toc-item level-${level}`;
                item.textContent = h.textContent.trim();
                if (!h.id) {
                    h.id = `toc-section-${index}`;
                }
                item.addEventListener('click', function() {
                    h.scrollIntoView({ behavior: 'smooth', block: 'start' });
                    panel.classList.remove('open');
                });
                listEl.appendChild(item);
            });
        }

        function init() {
            const headings = scanHeadings();
            buildTOC(headings);
        }

        function checkScroll() {
            if (window.scrollY > 400) {
                btn.classList.add('visible');
            } else {
                btn.classList.remove('visible');
                panel.classList.remove('open');
            }
        }

        btn.addEventListener('click', function() {
            if (panel.classList.contains('open')) {
                panel.classList.remove('open');
            } else {
                const headings = scanHeadings();
                buildTOC(headings);
                panel.classList.add('open');
            }
        });

        closeBtn.addEventListener('click', function() {
            panel.classList.remove('open');
        });

        backToTopBtn.addEventListener('click', function() {
            window.scrollTo({ top: 0, behavior: 'smooth' });
            panel.classList.remove('open');
        });

        document.addEventListener('click', function(e) {
            if (panel.classList.contains('open') && !panel.contains(e.target) && !btn.contains(e.target)) {
                panel.classList.remove('open');
            }
        });

        document.addEventListener('DOMContentLoaded', init);
        window.addEventListener('scroll', checkScroll);
        window.addEventListener('load', function() {
            setTimeout(init, 500);
        });
    })();
</script>

<!-- ============================================================
FOOTER – Brand, Links, Social
============================================================ -->
<footer class="site-footer">
    <div class="container">
        <div class="footer-grid">
            <div class="footer-brand">
                <a href="<?php echo SITE_URL; ?>/index.php" class="logo">
                    <img src="<?php echo SITE_URL; ?>/assets/images/logo.png" alt="AngelWrites Logo" class="logo-img">
                </a>
                <p class="footer-tagline">Writing with purpose, faith, and passion.</p>
            </div>

            <div class="footer-links">
                <h4>Quick Links</h4>
                <ul>
                    <?php if (isLoggedIn()): ?>
                        <li><a href="<?php echo SITE_URL; ?>/dashboard.php">Dashboard</a></li>
                        <li><a href="<?php echo SITE_URL; ?>/library.php">My Library</a></li>
                    <?php else: ?>
                        <li><a href="<?php echo SITE_URL; ?>/index.php">Home</a></li>
                        <li><a href="<?php echo SITE_URL; ?>/books.php">Books</a></li>
                        <li><a href="<?php echo SITE_URL; ?>/poetry.php">Poems</a></li>
                        <li><a href="<?php echo SITE_URL; ?>/blog.php">Blog</a></li>
                    <?php endif; ?>
                    <li><a href="<?php echo SITE_URL; ?>/about.php">About</a></li>
                    <li><a href="<?php echo SITE_URL; ?>/contact.php">Contact</a></li>
                </ul>
            </div>

            <div class="footer-social">
                <h4>Connect</h4>
                <div class="social-icons">
                    <a href="#" aria-label="Facebook"><i class="fab fa-facebook-f"></i></a>
                    <a href="#" aria-label="Instagram"><i class="fab fa-instagram"></i></a>
                    <a href="#" aria-label="Twitter"><i class="fab fa-twitter"></i></a>
                    <a href="#" aria-label="YouTube"><i class="fab fa-youtube"></i></a>
                    <a href="#" aria-label="Pinterest"><i class="fab fa-pinterest-p"></i></a>
                </div>
                <div class="footer-newsletter">
                    <p>Get updates straight to your inbox.</p>
                    <form action="<?php echo SITE_URL; ?>/newsletter.php" method="POST" class="footer-newsletter-form">
                        <input type="email" name="email" placeholder="Your email" required>
                        <button type="submit" aria-label="Subscribe"><i class="fas fa-paper-plane"></i></button>
                    </form>
                </div>
            </div>
        </div>

        <div class="footer-bottom">
            <p>
                &copy; <?php echo date('Y'); ?> <?php echo SITE_NAME; ?>. All rights reserved.
                <span class="footer-heart">Made with <i class="fas fa-heart" style="color: var(--rose);"></i> in Malawi.</span>
            </p>
        </div>
    </div>
</footer>

<!-- ============================================================
LOAD MAIN JAVASCRIPT
============================================================ -->
<script src="<?php echo SITE_URL; ?>/assets/js/main.js"></script>

</body>
</html>