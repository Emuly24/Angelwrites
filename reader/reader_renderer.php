<?php
/**
 * reader_renderer.php
 *
 * Rendering functions for the AngelWrites book reader.
 * All functions return HTML strings.
 */

if (!defined('SITE_URL')) {
    require_once __DIR__ . '/../includes/config.php';
}

/**
 * Render the scroll-mode page content (cover + all pages).
 *
 * @param array  $pages      Array of HTML strings for each content page.
 * @param string $cover_path URL of the cover image (empty if none).
 * @param string $book_title Book title (used for placeholder).
 * @return string HTML for the scroll container.
 */
function renderScrollPages($pages, $cover_path, $book_title)
{
    $html = '<div id="scroll-container">';

    // Cover
    if (!empty($cover_path)) {
        $html .= '
        <div class="cover-image-wrapper">
            <div class="cover-image-container">
                <img src="' . htmlspecialchars($cover_path) . '" alt="' . htmlspecialchars($book_title) . '">
            </div>
        </div>';
    } else {
        $html .= '
        <div class="cover-image-wrapper">
            <div class="cover-image-container">
                <div class="cover-placeholder">
                    <i class="fas fa-book-open"></i>
                    <p>' . htmlspecialchars($book_title) . '</p>
                </div>
            </div>
        </div>';
    }

    // Content pages
    foreach ($pages as $index => $page_html) {
        $page_num = $index + 1;
        $html .= '
        <div class="page-content-wrapper">
            <div class="page-content-inner" data-page="' . $page_num . '">' . $page_html . '</div>
        </div>';
    }

    $html .= '</div>';
    return $html;
}

/**
 * Render the flip-mode page HTML structure (used by StPageFlip).
 *
 * @param array  $pages      Array of HTML strings for each content page.
 * @param string $cover_path URL of the cover image (empty if none).
 * @return string HTML with all flip-page divs.
 */
function renderFlipPages($pages, $cover_path)
{
    $html = '';

    // Cover
    if (!empty($cover_path)) {
        $html .= '
        <div class="flip-page-custom special-page">
            <div class="cover-image-wrapper-flip">
                <img src="' . htmlspecialchars($cover_path) . '" alt="Cover" />
            </div>
        </div>';
    } else {
        $html .= '
        <div class="flip-page-custom special-page">
            <div class="cover-placeholder-flip">
                <i class="fas fa-book-open"></i>
                <p>Cover</p>
            </div>
        </div>';
    }

    // Content pages
    foreach ($pages as $index => $page_html) {
        $page_num = $index + 1;
        $html .= '
        <div class="flip-page-custom" data-page="' . $page_num . '">' . $page_html . '</div>';
    }

    return $html;
}

/**
 * Render the table of contents list.
 *
 * @param array $tocEntries Array of associative arrays with 'title' and 'page'.
 * @return string HTML for the TOC unordered list.
 */
function renderTOC($tocEntries)
{
    if (empty($tocEntries)) {
        return '<p class="toc-empty">No table of contents available.</p>';
    }

    $html = '<ul class="toc-list">';
    foreach ($tocEntries as $entry) {
        $page = (int)($entry['page'] ?? 1);
        $title = htmlspecialchars($entry['title']);
        $html .= '<li><a href="#" class="toc-link" data-chapter="' . $page . '">' . $title . '</a></li>';
    }
    $html .= '</ul>';
    return $html;
}

/**
 * Render the reading status dropdown (options).
 *
 * @param string $current_status The currently selected status.
 * @return string HTML for the <select> element.
 */
function renderReadingStatusDropdown($current_status = 'not_started')
{
    $statuses = [
        'not_started'      => '📌 Not Started',
        'currently_reading'=> '📖 Currently Reading',
        'finished'         => '✅ Finished',
        'want_to_read'     => '📚 Want to Read',
        'dropped'          => '❌ Dropped'
    ];

    $html = '<select id="readingStatus">';
    foreach ($statuses as $value => $label) {
        $selected = ($value === $current_status) ? 'selected' : '';
        $html .= '<option value="' . $value . '" ' . $selected . '>' . $label . '</option>';
    }
    $html .= '</select>';
    return $html;
}

/**
 * Render the progress ring (circular progress indicator).
 *
 * @param int $percent Progress percentage (0–100).
 * @return string HTML for the progress ring.
 */
function renderProgressRing($percent = 0)
{
    $circumference = 2 * M_PI * 16; // 2 * pi * r, r=16
    $offset = $circumference - ($percent / 100) * $circumference;
    return '
    <div class="progress-ring">
        <svg viewBox="0 0 36 36">
            <circle class="bg" cx="18" cy="18" r="16"/>
            <circle class="fill" id="progressFill" cx="18" cy="18" r="16"
                    stroke-dasharray="' . $circumference . '"
                    stroke-dashoffset="' . $offset . '"/>
        </svg>
        <span class="percent" id="progressPercent">' . $percent . '%</span>
    </div>';
}

/**
 * Render a badge for reading streak.
 *
 * @param int $streak_days Number of consecutive days.
 * @return string HTML badge (empty if $streak_days <= 0).
 */
function renderStreakBadge($streak_days)
{
    if ($streak_days <= 0) {
        return '';
    }
    return '<span class="streak-badge">🔥 ' . (int)$streak_days . 'd</span>';
}

/**
 * Render a badge for user level.
 *
 * @param int $level User level (0 = no badge).
 * @return string HTML badge (empty if level <= 0).
 */
function renderLevelBadge($level)
{
    if ($level <= 0) {
        return '';
    }
    return '<span class="level-badge" title="Reader Level">🏆 Lv.' . (int)$level . '</span>';
}

/**
 * Render the share modal (quote preview + share buttons).
 *
 * @return string HTML for the share modal.
 */
function renderShareModal()
{
    return '
    <div id="share-modal" class="modal-wrapper">
        <button class="modal-close" onclick="closeModal(\'share-modal\')">&times;</button>
        <h3><i class="fas fa-share-alt" style="color:var(--rose);"></i> Share</h3>
        <div id="shareQuotePreview" style="margin:8px 0; padding:12px; background:var(--bg); border-radius:8px; font-style:italic; display:none;">
            “<span id="shareQuoteText"></span>”
        </div>
        <div style="display:flex;flex-direction:column;margin-top:8px;">
            <button class="share-btn" onclick="share(\'facebook\')"><i class="fab fa-facebook-f"></i> Facebook</button>
            <button class="share-btn" onclick="share(\'twitter\')"><i class="fab fa-twitter"></i> Twitter</button>
            <button class="share-btn" onclick="share(\'whatsapp\')"><i class="fab fa-whatsapp"></i> WhatsApp</button>
            <button class="share-btn" onclick="share(\'copy\')"><i class="fas fa-link"></i> Copy Link</button>
        </div>
    </div>';
}

/**
 * Render the group notes panel (empty container – populated via AJAX).
 *
 * @return string HTML for the notes panel.
 */
function renderNotesPanel()
{
    return '
    <div id="notes-panel" class="modal-wrapper">
        <button class="modal-close" id="notesClose">&times;</button>
        <div class="notes-header">
            <h3 style="margin:0;font-size:1.2rem;">📝 Group Notes</h3>
            <button class="note-submit" id="addNoteBtn" style="padding:6px 16px;font-size:0.85rem;">+ Add Note</button>
        </div>
        <div id="notesBody" style="flex:1;overflow-y:auto;">
            <div id="notesList" style="max-height:200px;overflow-y:auto;"></div>
            <div id="noteForm" style="display:none;margin-top:12px;">
                <textarea id="noteText" rows="3" placeholder="Write a note..." style="width:100%;padding:12px;border-radius:12px;border:1px solid var(--border);background:var(--input-bg);color:var(--text);font-family:\'Inter\',sans-serif;"></textarea>
                <input type="hidden" id="noteHighlightId" value="0">
                <div style="margin:6px 0;"><label><input type="checkbox" id="notePrivate"> Private note</label></div>
                <div style="display:flex;gap:8px;justify-content:flex-end;">
                    <button class="note-submit" onclick="submitNote()">Post</button>
                    <button class="note-cancel" onclick="toggleNoteForm()">Cancel</button>
                </div>
            </div>
        </div>
    </div>';
}

/**
 * Render the challenge widget (empty container).
 *
 * @return string HTML for the challenge widget.
 */
function renderChallengeWidget()
{
    return '<div id="challenge-widget" style="display:none;"></div>';
}