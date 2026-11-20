<?php
/**
 * The AngelWrites Letter: the newsletter's email design (used by aw_nl_render() in newsletter.php).
 *
 * A magazine-style letter that holds up in Gmail, Outlook, Apple Mail and phones:
 *  - a hidden preview line (the "preheader" shown after the subject in the inbox list), padded so the
 *    body text does not leak into it;
 *  - "Read in your browser" and the date; a header with the logo, "The AngelWrites Letter" and the
 *    issue number; the subject as a large title with an optional standfirst;
 *  - the written part, with every element styled inline (mail apps drop most <style> rules), and the
 *    composer's blocks: buttons (a.aw-btn), callouts (div.aw-callout), Bible verses and quotes
 *    (blockquote.aw-verse / .aw-quote), dividers, pictures;
 *  - Angella's signature (handwritten when set, includes/email_signature.php);
 *  - optionally "New on AngelWrites": the latest poem, post, reflection and book as cards;
 *  - a footer with the social accounts, "Forward to a friend", why they get it, unsubscribe and the
 *    one line on keeping our emails out of Junk.
 * Layout tables and inline styles only; no outside fonts, images or scripts (the logo is embedded when
 * sent). Every function returns a value; nothing throws.
 */

const AW_NL_C = ['bg' => '#F4EFE8', 'card' => '#FFFFFF', 'ink' => '#2F2324', 'soft' => '#6B5A5A', 'rose' => '#9C5457', 'blush' => '#F6E7E6', 'line' => '#EAD9D6', 'accent' => '#DBA1A2'];

/** "Issue 7 · November 2026" for a campaign (or the next one, for a draft or preview). */
function aw_nl_issue_label(PDO $db, $queue_id = null) {
    try {
        if ($queue_id) {
            $st = $db->prepare("SELECT COUNT(*) FROM email_queue WHERE id <= ? AND send_to_type <> 'single' AND status IN ('sent', 'processing', 'pending')");
            $st->execute([(int)$queue_id]);
            $n = (int)$st->fetchColumn();
        } else {
            $n = (int)$db->query("SELECT COUNT(*) FROM email_queue WHERE send_to_type <> 'single' AND status IN ('sent', 'processing', 'pending')")->fetchColumn() + 1;
        }
    } catch (Throwable $e) { $n = 0; }
    return ($n > 0 ? 'Issue ' . $n . ' · ' : '') . date('F Y');
}

/** The newest poem, post, reflection and book, for "New on AngelWrites": [[kind, title, excerpt, url]]. */
function aw_nl_latest_items(PDO $db, $limit = 3) {
    $base = rtrim(SITE_URL, '/');
    $clean = function ($t, $n = 150) { $t = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags((string)$t), ENT_QUOTES, 'UTF-8'))); return mb_strlen($t) > $n ? rtrim(mb_substr($t, 0, $n - 1)) . '…' : $t; };
    $q = function ($sql) use ($db) { try { return $db->query($sql)->fetch(PDO::FETCH_ASSOC) ?: null; } catch (Throwable $e) { return null; } };
    $items = [];
    if ($r = $q("SELECT id, title, COALESCE(NULLIF(purpose, ''), NULLIF(intro, ''), content) AS t FROM poems ORDER BY COALESCE(created_at, '') DESC, id DESC LIMIT 1"))
        $items[] = ['A new poem', $r['title'], $clean($r['t']), $base . '/poem_view.php?id=' . (int)$r['id']];
    if ($r = $q("SELECT slug, title, COALESCE(NULLIF(excerpt, ''), content) AS t FROM blog_posts WHERE status = 'published' AND slug <> '' ORDER BY COALESCE(published_at, created_at) DESC LIMIT 1"))
        $items[] = ['On the blog', $r['title'], $clean($r['t']), $base . '/blog_post.php?slug=' . rawurlencode($r['slug'])];
    if ($r = $q("SELECT id, title, description AS t FROM books ORDER BY COALESCE(created_at, '') DESC, id DESC LIMIT 1"))
        $items[] = ['In the library', $r['title'], $clean($r['t']), $base . '/book.php?id=' . (int)$r['id']];
    return array_slice($items, 0, $limit);
}

/** The social accounts that are live, for the footer: [label => url]. */
function aw_nl_social_links(PDO $db) {
    if (!function_exists('aw_social_accounts') && is_file(__DIR__ . '/social_accounts.php')) require_once __DIR__ . '/social_accounts.php';
    $out = [];
    if (function_exists('aw_social_accounts')) foreach (aw_social_accounts($db) as $a) {
        if (!empty($a['is_live']) && preg_match('#^https://#', (string)$a['url'])) $out[$a['label']] = (string)$a['url'];
    }
    return $out;
}

/** Add inline styles to the written part (mail apps ignore most stylesheet rules). The author's own styles win. */
function aw_nl_inline($html) {
    $c = AW_NL_C;
    $serif = "Georgia,'Times New Roman',serif"; $sans = "'Helvetica Neue',Helvetica,Arial,sans-serif";
    $rules = [
        'p' => "margin:0 0 18px;font:17px/1.7 $sans;color:{$c['ink']};",
        'h1' => "margin:28px 0 12px;font:700 26px/1.3 $serif;color:{$c['ink']};",
        'h2' => "margin:30px 0 12px;font:700 23px/1.3 $serif;color:{$c['ink']};",
        'h3' => "margin:24px 0 10px;font:700 19px/1.35 $serif;color:{$c['ink']};",
        'h4' => "margin:20px 0 8px;font:700 16px/1.4 $sans;color:{$c['rose']};text-transform:uppercase;letter-spacing:1px;",
        'a' => "color:{$c['rose']};text-decoration:underline;",
        'ul' => "margin:0 0 18px;padding-left:22px;font:17px/1.7 $sans;color:{$c['ink']};",
        'ol' => "margin:0 0 18px;padding-left:22px;font:17px/1.7 $sans;color:{$c['ink']};",
        'li' => "margin:0 0 6px;",
        'img' => "display:block;max-width:100%;height:auto;border:0;border-radius:12px;margin:8px auto 18px;",
        'hr' => "border:0;border-top:1px solid {$c['line']};margin:30px 0;",
        'blockquote' => "margin:24px 0;padding:18px 22px;border-left:4px solid {$c['accent']};background:{$c['blush']};border-radius:0 12px 12px 0;font:italic 18px/1.65 $serif;color:{$c['ink']};",
        'figcaption' => "font:13px/1.5 $sans;color:{$c['soft']};text-align:center;margin-top:-10px;",
        'table' => "border-collapse:collapse;width:100%;margin:0 0 18px;",
        'td' => "padding:8px;border:1px solid {$c['line']};font:15px/1.5 $sans;",
        'th' => "padding:8px;border:1px solid {$c['line']};font:700 15px/1.5 $sans;background:{$c['blush']};",
    ];
    $byClass = [
        'aw-btn' => "display:inline-block;background:{$c['rose']};color:#FFFFFF;text-decoration:none;font:700 16px/1 $sans;padding:15px 30px;border-radius:999px;",
        'aw-callout' => "margin:24px 0;padding:20px 22px;background:{$c['blush']};border:1px solid {$c['line']};border-radius:14px;font:16px/1.65 $sans;color:{$c['ink']};",
        'aw-verse-ref' => "display:block;margin-top:8px;font:700 13px/1.4 $sans;color:{$c['rose']};font-style:normal;letter-spacing:.5px;",
        'aw-quote-ref' => "display:block;margin-top:8px;font:700 13px/1.4 $sans;color:{$c['rose']};font-style:normal;",
    ];
    if (trim((string)$html) === '' || !class_exists('DOMDocument')) return (string)$html;
    $doc = new DOMDocument();
    $prev = libxml_use_internal_errors(true);
    $ok = $doc->loadHTML('<?xml encoding="UTF-8"><div id="aw-root">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    libxml_clear_errors(); libxml_use_internal_errors($prev);
    if (!$ok) return (string)$html;
    $xp = new DOMXPath($doc);
    foreach ($xp->query('//*') as $el) {
        $tag = strtolower($el->nodeName);
        $classes = preg_split('/\s+/', (string)$el->getAttribute('class'));
        $add = $rules[$tag] ?? '';
        foreach ($classes as $cl) if (isset($byClass[$cl])) $add .= $byClass[$cl];
        // a paragraph that only holds a button is centred
        if ($tag === 'p' && $el->childNodes->length === 1 && $el->firstChild instanceof DOMElement && strpos((string)$el->firstChild->getAttribute('class'), 'aw-btn') !== false) $add .= 'text-align:center;margin:26px 0;';
        if ($add === '') continue;
        $el->setAttribute('style', $add . (string)$el->getAttribute('style'));
    }
    $root = $doc->getElementById('aw-root');
    $out = '';
    if ($root) foreach ($root->childNodes as $n) $out .= $doc->saveHTML($n);
    return $out;
}

/**
 * The whole letter. $o: subject, body (HTML), preheader, standfirst, web_url, issue, signature (bool),
 * latest (cards), unsub, forward_body, socials, junk_note (bool).
 */
function aw_nl_layout(array $o) {
    $c = AW_NL_C;
    $e = function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); };
    $site = function_exists('aw_site_name') ? aw_site_name() : 'AngelWrites';
    $base = rtrim(SITE_URL, '/');
    $logo = $base . '/' . ltrim(function_exists('aw_logo_path') ? aw_logo_path() : 'assets/images/logo.png', '/');
    $serif = "Georgia,'Times New Roman',serif"; $sans = "'Helvetica Neue',Helvetica,Arial,sans-serif";
    $pre = trim((string)($o['preheader'] ?? ''));
    $h = '<!doctype html><html lang="en" xmlns="http://www.w3.org/1999/xhtml"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
       . '<meta name="x-apple-disable-message-reformatting"><meta name="color-scheme" content="light"><meta name="supported-color-schemes" content="light">'
       . '<title>' . $e($o['subject'] ?? $site) . '</title>'
       . '<style>body{margin:0!important;padding:0!important;background:' . $c['bg'] . ';-webkit-text-size-adjust:100%;}table{border-collapse:collapse;}img{-ms-interpolation-mode:bicubic;}a{color:' . $c['rose'] . ';}'
       . '@media (max-width:620px){.nl-pad{padding-left:22px!important;padding-right:22px!important;}.nl-title{font-size:27px!important;line-height:1.25!important;}.nl-card{border-radius:0!important;}.nl-hide-sm{display:none!important;}}</style>'
       . '</head><body style="margin:0;padding:0;background:' . $c['bg'] . ';">';
    // the inbox preview line, padded with invisible characters so the body does not follow it
    if ($pre !== '') $h .= '<div style="display:none;max-height:0;overflow:hidden;mso-hide:all;">' . $e($pre) . str_repeat('&#847;&zwnj;&nbsp;', 60) . '</div>';
    $h .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:' . $c['bg'] . ';"><tr><td align="center" style="padding:20px 10px 36px;">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:640px;">';
    // utility row
    $h .= '<tr><td style="padding:0 6px 10px;"><table role="presentation" width="100%"><tr>'
        . '<td style="font:12px ' . $sans . ';color:' . $c['soft'] . ';">' . (!empty($o['web_url']) ? '<a href="' . $e($o['web_url']) . '" style="color:' . $c['soft'] . ';">Read in your browser</a>' : '&nbsp;') . '</td>'
        . '<td align="right" class="nl-hide-sm" style="font:12px ' . $sans . ';color:' . $c['soft'] . ';">' . $e(date('j F Y')) . '</td></tr></table></td></tr>';
    // the card
    $h .= '<tr><td class="nl-card" style="background:' . $c['card'] . ';border-radius:22px;overflow:hidden;border:1px solid ' . $c['line'] . ';">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0">'
        // header
        . '<tr><td align="center" style="background:' . $c['blush'] . ';background-image:linear-gradient(135deg,#F9EDEC 0%,#F1DCDB 100%);padding:34px 28px 26px;border-bottom:1px solid ' . $c['line'] . ';">'
        . '<a href="' . $e($base) . '" style="text-decoration:none;"><img src="' . $e($logo) . '" width="132" alt="' . $e($site) . '" style="display:block;width:132px;max-width:132px;height:auto;border:0;margin:0 auto;"></a>'
        . '<div style="margin-top:14px;font:700 11px/1 ' . $sans . ';letter-spacing:4px;text-transform:uppercase;color:' . $c['rose'] . ';">The ' . $e($site) . ' Letter</div>'
        . (!empty($o['issue']) ? '<div style="margin-top:8px;font:12px/1 ' . $sans . ';color:' . $c['soft'] . ';letter-spacing:.5px;">' . $e($o['issue']) . '</div>' : '')
        . '</td></tr>'
        // title
        . '<tr><td class="nl-pad" style="padding:38px 46px 6px;">'
        . '<h1 class="nl-title" style="margin:0;font:700 34px/1.2 ' . $serif . ';color:' . $c['ink'] . ';">' . $e($o['subject'] ?? '') . '</h1>'
        . (trim((string)($o['standfirst'] ?? '')) !== '' ? '<p style="margin:14px 0 0;font:italic 19px/1.55 ' . $serif . ';color:' . $c['soft'] . ';">' . $e($o['standfirst']) . '</p>' : '')
        . '<div style="width:56px;height:3px;background:' . $c['accent'] . ';border-radius:3px;margin:26px 0 4px;line-height:3px;font-size:1px;">&nbsp;</div>'
        . '</td></tr>'
        // the letter
        . '<tr><td class="nl-pad" style="padding:22px 46px 8px;">' . aw_nl_inline((string)($o['body'] ?? ''))
        . (!empty($o['signature']) && function_exists('aw_email_signature_html') ? aw_email_signature_html() : '')
        . '</td></tr>';
    // New on AngelWrites
    if (!empty($o['latest'])) {
        $h .= '<tr><td class="nl-pad" style="padding:26px 46px 6px;"><div style="border-top:1px solid ' . $c['line'] . ';padding-top:26px;">'
            . '<div style="font:700 12px/1 ' . $sans . ';letter-spacing:3px;text-transform:uppercase;color:' . $c['rose'] . ';margin-bottom:16px;">New on ' . $e($site) . '</div>';
        foreach ($o['latest'] as [$kind, $title, $excerpt, $url]) {
            $h .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 14px;background:' . $c['bg'] . ';border-radius:16px;"><tr><td style="padding:18px 20px;">'
                . '<div style="font:700 11px/1 ' . $sans . ';letter-spacing:1.5px;text-transform:uppercase;color:' . $c['soft'] . ';">' . $e($kind) . '</div>'
                . '<a href="' . $e($url) . '" style="display:block;margin:8px 0 6px;font:700 19px/1.35 ' . $serif . ';color:' . $c['ink'] . ';text-decoration:none;">' . $e($title) . '</a>'
                . ($excerpt !== '' ? '<div style="font:15px/1.6 ' . $sans . ';color:' . $c['soft'] . ';">' . $e($excerpt) . '</div>' : '')
                . '<a href="' . $e($url) . '" style="display:inline-block;margin-top:10px;font:700 14px/1 ' . $sans . ';color:' . $c['rose'] . ';text-decoration:none;">Read it &rarr;</a>'
                . '</td></tr></table>';
        }
        $h .= '</div></td></tr>';
    }
    // closing band
    $h .= '<tr><td class="nl-pad" align="center" style="padding:24px 46px 38px;">'
        . '<a href="' . $e($base) . '" style="display:inline-block;background:' . $c['ink'] . ';color:#FFFFFF;text-decoration:none;font:700 15px/1 ' . $sans . ';padding:14px 26px;border-radius:999px;">Visit ' . $e($site) . '</a>'
        . '</td></tr></table></td></tr>';
    // footer
    $f = '';
    if (!empty($o['socials'])) {
        $links = [];
        foreach ($o['socials'] as $label => $url) $links[] = '<a href="' . $e($url) . '" style="color:' . $c['rose'] . ';text-decoration:none;font-weight:700;">' . $e($label) . '</a>';
        $f .= '<p style="margin:0 0 12px;">' . implode(' &nbsp;·&nbsp; ', $links) . '</p>';
    }
    if (!empty($o['forward_body'])) $f .= '<p style="margin:0 0 12px;"><a href="mailto:?subject=' . rawurlencode('A letter from ' . $site . ': ' . ($o['subject'] ?? '')) . '&amp;body=' . rawurlencode($o['forward_body']) . '" style="color:' . $c['rose'] . ';font-weight:700;">Forward to a friend</a></p>';
    $f .= '<p style="margin:0 0 8px;">You are receiving this because you subscribed to the ' . $e($site) . ' newsletter at <a href="' . $e($base) . '" style="color:' . $c['soft'] . ';">' . $e(preg_replace('#^https?://#', '', $base)) . '</a>.</p>'
        . '<p style="margin:0 0 8px;">' . (!empty($o['unsub']) ? '<a href="' . $e($o['unsub']) . '" style="color:' . $c['soft'] . ';">Unsubscribe</a> &nbsp;·&nbsp; ' : '')
        . '<a href="' . $e($base) . '/contact.php" style="color:' . $c['soft'] . ';">Contact us</a></p>';
    if (!empty($o['junk_note'])) $f .= '<p style="margin:0 0 8px;">To keep our letters in your inbox, add ' . $e(function_exists('aw_inbox_address') ? aw_inbox_address() : 'angelwrites@zohomail.com') . ' to your contacts. <a href="' . $e($base) . '/email_help.php" style="color:' . $c['soft'] . ';">How</a></p>';
    $f .= '<p style="margin:0;">' . $e($site) . ' &middot; Malawi &middot; Writing with purpose and grace.</p>';
    $h .= '<tr><td align="center" class="nl-pad" style="padding:26px 30px 0;font:12px/1.65 ' . $sans . ';color:' . $c['soft'] . ';">' . $f . '</td></tr>';
    $h .= '</table></td></tr></table></body></html>';
    return $h;
}
