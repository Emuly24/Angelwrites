<?php
/**
 * M1: the one poem card (PROGRAM_PLAN.md Phase 1, wish M).
 *
 * Used by the home page, Poetry and search, so a poem looks the same wherever it appears.
 * Styles live in assets/css/aw-poem.css (.aw-card*), loaded with aw_poem_assets().
 *
 * aw_poem_card($poem, $opts):
 *   $poem  a poems row; needs id, title, content; uses image_path, audio_path, created_at,
 *          view_count when present (a missing field is simply not shown).
 *   $opts  'title_html' => already-escaped title markup (e.g. search highlighting)
 *          'heading'    => 'h3' (default) or 'h2'
 *          'label'      => a small kind label above the title (e.g. 'Poem' among mixed results)
 * Returns HTML; never throws.
 */
require_once __DIR__ . '/poem_reader.php';

function aw_poem_card_img(array $poem) {
    $img = trim((string)($poem['image_path'] ?? ''));
    if ($img === '') return '';
    if (preg_match('#^https?://#i', $img)) return $img;
    if (function_exists('get_image_url')) return get_image_url($img);
    return rtrim(SITE_URL, '/') . '/' . ltrim($img, '/');
}

function aw_poem_card(array $poem, array $opts = []) {
    try {
        $id = (int)($poem['id'] ?? 0);
        $h = ($opts['heading'] ?? 'h3') === 'h2' ? 'h2' : 'h3';
        $title = isset($opts['title_html']) ? (string)$opts['title_html'] : htmlspecialchars((string)($poem['title'] ?? 'Poem'));
        $img = aw_poem_card_img($poem);
        $st = aw_poem_stats($poem);
        $open = aw_poem_opening($poem, 3);
        $audio = isset($poem['audio_path']) && aw_poem_audio_url($poem) !== '';

        $html = '<a class="aw-card" href="' . htmlspecialchars(SITE_URL . '/poem_view.php?id=' . $id) . '">';
        $html .= '<div class="aw-card-img">';
        if ($img !== '') $html .= '<img src="' . htmlspecialchars($img) . '" alt="" loading="lazy" onerror="this.remove()">';
        $html .= '<span class="aw-ph" aria-hidden="true"><i class="fas fa-heart"></i></span>';
        if ($audio) $html .= '<span class="aw-badge">🎧 Listen</span>';
        $html .= '</div><div class="aw-card-body">';
        if (!empty($opts['label'])) $html .= '<span class="aw-card-kind">' . htmlspecialchars((string)$opts['label']) . '</span>';
        $html .= '<' . $h . '>' . $title . '</' . $h . '>';
        if ($open) {
            $html .= '<p class="aw-opening">';
            foreach ($open as $l) $html .= '<span>' . htmlspecialchars($l) . '</span>';
            $html .= '</p>';
        }
        $html .= '<div class="aw-card-foot">';
        if (!empty($poem['created_at'])) {
            $html .= '<span><i class="far fa-calendar" aria-hidden="true"></i>' . htmlspecialchars(date('M j, Y', strtotime($poem['created_at']))) . '</span>';
        }
        $html .= '<span><i class="far fa-clock" aria-hidden="true"></i>' . (int)$st['minutes'] . ' min</span>';
        if (isset($poem['view_count'])) {
            $html .= '<span><i class="far fa-eye" aria-hidden="true"></i>' . number_format((int)$poem['view_count']) . '<span class="sr-only"> views</span></span>';
        }
        $html .= '</div></div></a>';
        return $html;
    } catch (Throwable $e) {
        error_log('poem card #' . (int)($poem['id'] ?? 0) . ': ' . $e->getMessage());
        return '';
    }
}
