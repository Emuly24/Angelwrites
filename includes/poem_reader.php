<?php
// ============================================================
//  POEM_READER.PHP – one way to show a poem (V1)
// ============================================================
// Poems were printed as the raw HTML they were saved with. Most came from WordPress, so they mixed
// one-paragraph-per-line poems with <br>-separated ones, carried empty ad blocks ("wordads") and
// had no visible stanza breaks. These helpers turn any of those shapes into clean stanzas:
//
//   aw_poem_parse($html)      -> ['stanzas' => [[line, ...], ...], 'signature' => 'by …' | null]
//                                a line is ['html' => inline HTML, 'indent' => 0-4]
//   aw_poem_render($poem)     -> <article class="aw-poem"> with one .aw-stanza per stanza
//   aw_poem_stats($poem)      -> words, lines, stanzas, minutes
//   aw_poem_opening($poem, n) -> the first n lines as plain text (catalogue teaser)
//   aw_poem_audio_url($poem)  -> URL of the recorded reading, or '' when there is no playable file
//   aw_poem_player($poem)     -> the "Listen" player, or '' (so no play button without a recording)
//   aw_poem_neighbours($db, $poem) -> previous / next published poem
//
// Stanzas: an empty paragraph, a blank line (<br><br>) or the end of a multi-line paragraph ends a
// stanza; a paragraph holding a single line continues the stanza (WordPress "one line per <p>").
// Leading non-breaking spaces become an indent level. A short closing "by …" line becomes the
// signature. If nothing can be read the original HTML is shown as before.

if (!function_exists('aw_poem_parse')) {

function aw_poem_clean_html($html) {
    $html = (string)$html;
    if (trim($html) === '') return '';
    $prev = libxml_use_internal_errors(true);
    $doc = new DOMDocument();
    $doc->loadHTML('<?xml encoding="UTF-8"><div id="aw-root">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);
    $xp = new DOMXPath($doc);
    $junk = [];
    foreach ($xp->query('//*[contains(@class,"wordads")] | //script | //style | //iframe | //ins | //noscript') as $n) $junk[] = $n;
    foreach ($junk as $n) { if ($n->parentNode) $n->parentNode->removeChild($n); }
    $root = $doc->getElementById('aw-root');
    if (!$root) { require_once __DIR__ . '/sanitize.php'; return aw_clean_html($html, 'rich'); }
    $out = '';
    foreach ($root->childNodes as $c) $out .= $doc->saveHTML($c);
    // S1: then the shared sanitiser (scripts, handlers, unsafe links). After the tidy-up above, which
    // needs the WordPress class names to find leftover ad blocks.
    require_once __DIR__ . '/sanitize.php';
    return aw_clean_html($out, 'rich');
}

function aw_poem_parse($html) {
    $html = aw_poem_clean_html($html);
    $result = ['stanzas' => [], 'signature' => null, 'images' => [], 'tags' => []];
    if (trim($html) === '') return $result;

    // Images are kept and shown after the poem.
    $html = preg_replace_callback('#<img\b[^>]*>#i', function ($m) use (&$result) {
        if (preg_match('#\bsrc\s*=\s*["\']([^"\']+)["\']#i', $m[0], $s)) {
            $alt = preg_match('#\balt\s*=\s*["\']([^"\']*)["\']#i', $m[0], $a) ? $a[1] : '';
            $result['images'][] = ['src' => html_entity_decode($s[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'), 'alt' => html_entity_decode($alt, ENT_QUOTES | ENT_HTML5, 'UTF-8')];
        }
        return '';
    }, $html);

    // Paragraph-like blocks end with \x01, <br> is \x02; newlines in the markup are just spaces.
    $blocks = 'p|div|h[1-6]|li|blockquote|pre|figure|figcaption|section|article|ul|ol|table|tr';
    $html = str_replace(["\r", "\n", "\t"], ' ', $html);
    $html = preg_replace('#<br\s*/?>#i', "\x02", $html);
    $html = preg_replace('#</(' . $blocks . ')\s*>#i', "\x01", $html);
    $html = preg_replace('#<(' . $blocks . '|hr)\b[^>]*>#i', '', $html);

    $allowed = '<em><strong><i><b><u><a><mark><sup><sub><small><span>';
    $stanzas = [];
    $cur = [];
    $para = [];
    $flushStanza = function () use (&$cur, &$stanzas) { if ($cur) { $stanzas[] = $cur; $cur = []; } };
    $endPara = function () use (&$para, &$cur, $flushStanza) {
        if (!$para) return;
        $nonEmpty = array_values(array_filter($para, function ($l) { return $l !== null; }));
        if (!$nonEmpty) { $flushStanza(); $para = []; return; }        // empty paragraph: stanza break
        foreach ($para as $l) {
            if ($l === null) $flushStanza(); else $cur[] = $l;              // blank line inside: stanza break
        }
        if (count($nonEmpty) > 1) $flushStanza();                           // a multi-line paragraph is a stanza
        $para = [];
    };

    $tokens = preg_split('/(\x01|\x02)/', $html, -1, PREG_SPLIT_DELIM_CAPTURE);
    $n = count($tokens);
    for ($i = 0; $i < $n; $i++) {
        $tok = $tokens[$i];
        if ($tok === "\x01") { $endPara(); continue; }
        if ($tok === "\x02") continue;
        $line = strip_tags($tok, $allowed);
        $text = html_entity_decode(strip_tags($line), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $hasNbsp = strpos($text, "\u{00A0}") !== false;
        $text = str_replace("\u{00A0}", ' ', $text);
        if (trim($text) === '') {
            $prev = $i > 0 ? $tokens[$i - 1] : '';
            $next = $i + 1 < $n ? $tokens[$i + 1] : '';
            // A line holding only &nbsp;, or the gap in <br><br>, is a blank line; plain markup whitespace is not.
            if ($hasNbsp || ($prev === "\x02" && $next === "\x02")) $para[] = null;
            continue;
        }
        preg_match('/^( *)/u', $text, $lead);
        $indent = min(4, (int)ceil(strlen($lead[1]) / 4));
        $line = preg_replace('/^(\s|&nbsp;|&#160;|\x{00A0})+/u', '', trim($line));
        $line = preg_replace('/(\s|&nbsp;|&#160;|\x{00A0})+$/u', '', $line);
        $para[] = ['html' => $line, 'indent' => $indent, 'text' => trim(preg_replace('/\s+/u', ' ', $text))];
    }
    $endPara();
    $flushStanza();

    // A blank <br><br> line only counts inside a paragraph; drop the null markers that remain.
    $stanzas = array_values(array_filter(array_map(function ($s) {
        return array_values(array_filter($s, function ($l) { return $l !== null; }));
    }, $stanzas)));

    // Closing hashtags (#AngelWrites#, #God'sTrophyOfGrace#@handle) are shown as quiet tags, not as a stanza.
    $result['tags'] = [];
    while ($stanzas) {
        $li = count($stanzas) - 1;
        $tail = end($stanzas[$li]);
        if (!$tail || !preg_match('/^\S+$/u', $tail['text']) || !preg_match('/[#@]/', $tail['text'])) break;
        array_unshift($result['tags'], $tail['text']);
        array_pop($stanzas[$li]);
        if (!$stanzas[$li]) array_pop($stanzas);
    }

    // Closing signature ("by Angella Bottoman", "— Angella").
    if ($stanzas) {
        $last = &$stanzas[count($stanzas) - 1];
        $tail = end($last);
        if ($tail && mb_strlen($tail['text']) <= 60 && preg_match('/^(by|—|–|-|~)\s*\p{L}/iu', $tail['text'])) {
            $result['signature'] = $tail['text'];
            array_pop($last);
            if (!$last) array_pop($stanzas);
        }
        unset($last);
    }
    $result['stanzas'] = $stanzas;
    return $result;
}

function aw_poem_stats(array $poem) {
    $p = aw_poem_parse($poem['content'] ?? '');
    $words = 0; $lines = 0;
    foreach ($p['stanzas'] as $s) {
        foreach ($s as $l) { $lines++; $words += count(preg_split('/\s+/u', $l['text'], -1, PREG_SPLIT_NO_EMPTY)); }
    }
    if (!$lines) $words = str_word_count(strip_tags((string)($poem['content'] ?? '')));
    // Poetry is read more slowly than prose (about 130 words a minute).
    return ['words' => $words, 'lines' => $lines, 'stanzas' => count($p['stanzas']), 'minutes' => max(1, (int)ceil($words / 130))];
}

function aw_poem_opening(array $poem, $n = 3) {
    $p = aw_poem_parse($poem['content'] ?? '');
    $out = [];
    foreach ($p['stanzas'] as $s) {
        foreach ($s as $l) { $out[] = $l['text']; if (count($out) >= $n) return $out; }
        if ($out) return $out; // only the opening stanza
    }
    return $out;
}

function aw_poem_render(array $poem) {
    $p = aw_poem_parse($poem['content'] ?? '');
    $title = htmlspecialchars((string)($poem['title'] ?? 'Poem'));
    if (!$p['stanzas']) {
        return '<article class="aw-poem aw-poem-raw" aria-label="' . $title . '">' . ($poem['content'] ?? '') . '</article>';
    }
    $html = '<article class="aw-poem" id="awPoem" aria-label="' . $title . '">';
    foreach ($p['stanzas'] as $i => $s) {
        $n = $i + 1;
        $words = 0;
        foreach ($s as $l) $words += count(preg_split('/\s+/u', $l['text'], -1, PREG_SPLIT_NO_EMPTY));
        $html .= '<div class="aw-stanza" id="stanza-' . $n . '" data-stanza="' . $n . '" data-words="' . $words . '" tabindex="-1">';
        $html .= '<b class="aw-stanza-num" aria-hidden="true">' . $n . '</b>';
        foreach ($s as $l) {
            $html .= '<span class="aw-line"' . ($l['indent'] ? ' data-indent="' . $l['indent'] . '"' : '') . '>' . $l['html'] . '</span>';
        }
        $html .= '</div>';
    }
    if ($p['signature']) {
        $html .= '<p class="aw-signature">' . htmlspecialchars($p['signature']) . '</p>';
    }
    if (!empty($p['tags'])) {
        $html .= '<p class="aw-tags">' . implode(' ', array_map(function ($t) {
            return '<span>' . htmlspecialchars(trim($t, '#')) . '</span>';
        }, $p['tags'])) . '</p>';
    }
    foreach ($p['images'] as $img) {
        $html .= '<figure class="aw-poem-figure"><img src="' . htmlspecialchars($img['src']) . '" alt="' . htmlspecialchars($img['alt']) . '" loading="lazy"></figure>';
    }
    $html .= '</article>';
    return $html;
}

function aw_poem_audio_url(array $poem) {
    $path = trim((string)($poem['audio_path'] ?? ''));
    if ($path === '') return '';
    if (preg_match('#^https?://#i', $path)) return $path;
    $file = dirname(__DIR__) . '/' . ltrim($path, '/');
    if (!is_file($file) || filesize($file) < 1024) return ''; // missing or empty file: no player
    return rtrim(SITE_URL, '/') . '/' . ltrim($path, '/');
}

function aw_poem_player(array $poem) {
    $url = aw_poem_audio_url($poem);
    if ($url === '') return '';
    return '<section class="aw-player" id="awPlayer" aria-label="Listen to this poem">'
        . '<audio id="awAudio" preload="metadata" src="' . htmlspecialchars($url) . '"></audio>'
        . '<button type="button" class="aw-play" id="awPlay" aria-label="Play the recording"><span class="aw-play-icon" aria-hidden="true"></span></button>'
        . '<div class="aw-player-main">'
        . '<div class="aw-player-top"><span class="aw-player-title">🎧 Listen to Angella read it</span><span class="aw-player-time" id="awTime">0:00 / 0:00</span></div>'
        . '<input type="range" class="aw-seek" id="awSeek" min="0" max="1000" value="0" step="1" aria-label="Position in the recording">'
        . '<div class="aw-player-opts">'
        . '<button type="button" class="aw-chip" id="awSpeed" aria-label="Playback speed">1×</button>'
        . '<button type="button" class="aw-chip" id="awFollow" aria-pressed="true" title="Highlight each stanza as it is read">Follow along</button>'
        . '</div></div></section>';
}

function aw_poem_neighbours(PDO $db, array $poem) {
    $out = ['prev' => null, 'next' => null];
    try {
        $draft = "id NOT IN (SELECT poem_id FROM poem_status WHERE status = 'draft')";
        $q = $db->prepare("SELECT id, title FROM poems WHERE $draft AND (created_at < ? OR (created_at = ? AND id < ?)) ORDER BY created_at DESC, id DESC LIMIT 1");
        $q->execute([$poem['created_at'], $poem['created_at'], $poem['id']]);
        $out['prev'] = $q->fetch(PDO::FETCH_ASSOC) ?: null;
        $q = $db->prepare("SELECT id, title FROM poems WHERE $draft AND (created_at > ? OR (created_at = ? AND id > ?)) ORDER BY created_at ASC, id ASC LIMIT 1");
        $q->execute([$poem['created_at'], $poem['created_at'], $poem['id']]);
        $out['next'] = $q->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (PDOException $e) {
        // poem_status missing before the DB repair: no neighbours
    }
    return $out;
}

// Fonts and stylesheet for the reader (printed once, before the reader markup).
function aw_poem_assets() {
    return '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>'
        . '<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,500;0,600;1,500&family=Atkinson+Hyperlegible:wght@400;700&display=swap" rel="stylesheet">'
        . '<link rel="stylesheet" href="' . SITE_URL . '/assets/css/aw-poem.css?v=2">';
}

function aw_poem_toolbar() {
    return '<div class="aw-toolbar" role="toolbar" aria-label="Reading settings">'
        . '<button type="button" class="aw-chip" data-act="smaller" aria-label="Smaller text">A−</button>'
        . '<button type="button" class="aw-chip" data-act="bigger" aria-label="Larger text">A+</button>'
        . '<span class="aw-sep" aria-hidden="true"></span>'
        . '<button type="button" class="aw-chip" data-act="font" title="Change the font">Garamond</button>'
        . '<button type="button" class="aw-chip" data-act="align" title="Centre or left-align the lines"><i class="fas fa-align-center" aria-hidden="true"></i> Centred</button>'
        . '<button type="button" class="aw-chip" data-act="theme" title="Page colour"><i class="far fa-file" aria-hidden="true"></i> Page</button>'
        . '<span class="aw-sep" aria-hidden="true"></span>'
        . '<button type="button" class="aw-chip" data-act="focus" aria-pressed="false" title="Show only the poem"><i class="fas fa-expand" aria-hidden="true"></i> Focus</button>'
        . '<button type="button" class="aw-chip" data-act="print" aria-label="Print or save as PDF" title="Print or save as PDF"><i class="fas fa-print" aria-hidden="true"></i></button>'
        . '<button type="button" class="aw-chip" data-act="share" aria-label="Share this poem" title="Share this poem"><i class="fas fa-share-alt" aria-hidden="true"></i></button>'
        . '</div>';
}

// The whole reading view: hero, purpose, player (only with a recording), toolbar, poem, previous/next.
function aw_poem_reader(array $poem, $db = null, array $opts = []) {
    $title = (string)($poem['title'] ?? '');
    $stats = aw_poem_stats($poem);
    $img = trim((string)($poem['image_path'] ?? ''));
    $imgUrl = '';
    if ($img !== '') $imgUrl = function_exists('get_image_url') ? get_image_url($img) : rtrim(SITE_URL, '/') . '/' . ltrim($img, '/');
    $hasAudio = aw_poem_audio_url($poem) !== '';
    $h = '<div class="aw-reader" data-title="' . htmlspecialchars($title) . '">';
    // V6: the cover is shown as a framed picture beside the title (above it on phones), over a soft
    // blurred wash of the same image. If the image cannot load, the hero falls back to the gradient.
    $h .= '<header class="aw-hero' . ($imgUrl ? ' has-cover' : '') . '">';
    if ($imgUrl) {
        $u = htmlspecialchars($imgUrl);
        $h .= '<div class="aw-hero-wash" style="background-image:url(\'' . $u . '\')" aria-hidden="true"></div>';
        $h .= '<figure class="aw-cover"><img src="' . $u . '" alt="Cover image for ' . htmlspecialchars($title) . '" fetchpriority="high"'
            . ' onerror="var h=this.closest(\'.aw-hero\');h.classList.remove(\'has-cover\');h.querySelector(\'.aw-hero-wash\').remove();this.parentNode.remove()"></figure>';
    }
    $author = trim((string)($poem['author'] ?? '')); // P-ED: editable author line
    $h .= '<div class="aw-hero-text"><div class="aw-hero-kicker">A poem by ' . htmlspecialchars($author !== '' ? $author : 'Angella Bottoman') . '</div>';
    $h .= '<h1>' . htmlspecialchars($title) . '</h1><div class="aw-meta">';
    // P-ED: an older poem shows the date it was written; otherwise the day it was added
    $written = trim((string)($poem['written_on'] ?? ''));
    if ($written !== '' && strtotime($written)) $h .= '<span><i class="far fa-calendar" aria-hidden="true"></i>Written <time datetime="' . htmlspecialchars($written) . '">' . date('F j, Y', strtotime($written)) . '</time></span>';
    elseif (!empty($poem['created_at'])) $h .= '<span><i class="far fa-calendar" aria-hidden="true"></i>' . date('F j, Y', strtotime($poem['created_at'])) . '</span>';
    $h .= '<span><i class="far fa-clock" aria-hidden="true"></i>' . $stats['minutes'] . ' min read</span>';
    if ($stats['stanzas']) $h .= '<span><i class="fas fa-align-center" aria-hidden="true"></i>' . $stats['stanzas'] . ' stanza' . ($stats['stanzas'] == 1 ? '' : 's') . '</span>';
    if (isset($poem['view_count'])) $h .= '<span><i class="far fa-eye" aria-hidden="true"></i>' . number_format((int)$poem['view_count']) . ' reads</span>';
    if ($hasAudio) $h .= '<span><i class="fas fa-headphones" aria-hidden="true"></i>Recorded reading</span>';
    $h .= '</div></div></header>';
    if (trim((string)($poem['intro'] ?? '')) !== '') {
        // A short purpose reads as a centred epigraph; a long one (often several paragraphs) as prose.
        $intro = trim(str_replace("\r", '', (string)$poem['intro']));
        $long = mb_strlen($intro) > 220;
        $paras = preg_split('/\n\s*\n|(?<=[.!?”"])\n/u', $intro);
        $h .= '<section class="aw-epigraph' . ($long ? ' is-long' : '') . '"><div class="aw-epigraph-label">Purpose of this poem</div>';
        foreach ($paras as $para) if (trim($para) !== '') $h .= '<p>' . nl2br(htmlspecialchars(trim($para))) . '</p>';
        $h .= '</section>';
    }
    $h .= aw_poem_player($poem);
    $h .= aw_poem_toolbar();
    $h .= '<div class="aw-page">' . aw_poem_render($poem) . '</div>';
    if ($db instanceof PDO && empty($opts['no_neighbours'])) {
        $nb = aw_poem_neighbours($db, $poem);
        if ($nb['prev'] || $nb['next']) {
            $h .= '<nav class="aw-neighbours aw-hide-focus" aria-label="More poems">';
            if ($nb['next']) $h .= '<a class="prev" href="' . SITE_URL . '/poem_view.php?id=' . (int)$nb['next']['id'] . '"><small>← Newer poem</small><strong>' . htmlspecialchars($nb['next']['title']) . '</strong></a>';
            if ($nb['prev']) $h .= '<a class="next" href="' . SITE_URL . '/poem_view.php?id=' . (int)$nb['prev']['id'] . '"><small>Older poem →</small><strong>' . htmlspecialchars($nb['prev']['title']) . '</strong></a>';
            $h .= '</nav>';
        }
    }
    $h .= '</div>';
    $h .= '<script src="' . SITE_URL . '/assets/js/aw-poem.js?v=1" defer></script>';
    return $h;
}

}
