<?php
// ============================================================
//  EMAIL_TEMPLATE.PHP – one look for every AngelWrites email (E1)
// ============================================================
// Every message the site sends (verification codes, password resets, receipts, session updates,
// replies, notifications and newsletters) uses this layout: the AngelWrites logo, the site's fonts
// (Playfair Display for headings, Inter for text, with Georgia/Arial where an email app cannot load
// web fonts), the rose / vanilla / fantasy palette, Angella's signature and a footer.
//
//  aw_email_layout($o)              the full HTML document (used by sendEmail() and the newsletter)
//  aw_email_brand($subject, $msg, $isHTML, $opts)  wraps any message; returns [html, text]
//  aw_email_signature_html()        the signature block
//
// Options ($opts): 'signature' => false leaves the signature out (an admin can choose this when
// writing a message); messages to the site's own admin address get a "sent by your website"
// footer instead of a signature. 'preheader' sets the inbox preview line.

if (!function_exists('aw_email_layout')) {

function aw_email_e($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function aw_email_signature_html() {
    $site = function_exists('aw_site_name') ? aw_site_name() : 'AngelWrites';
    $base = rtrim(defined('SITE_URL') ? SITE_URL : '', '/');
    return '<table role="presentation" cellpadding="0" cellspacing="0" style="margin-top:28px;border-top:1px solid #EFD8D6;padding-top:18px;width:100%;"><tr><td>'
        . '<p style="margin:0 0 4px;font:italic 15px/1.5 Georgia,\'Times New Roman\',serif;color:#6b5a5a;">With love and blessings,</p>'
        . '<p style="margin:0;font:600 22px/1.3 \'Playfair Display\',Georgia,serif;color:#c08a8b;">Angella Bottoman</p>'
        . '<p style="margin:4px 0 0;font:13px/1.5 \'Inter\',Arial,sans-serif;color:#6b5a5a;">Founder, ' . aw_email_e($site) . ' · <a href="' . aw_email_e($base) . '" style="color:#c08a8b;text-decoration:none;">' . aw_email_e(preg_replace('#^https?://#', '', $base)) . '</a></p>'
        . '<p style="margin:2px 0 0;font:italic 12px/1.5 \'Inter\',Arial,sans-serif;color:#9a8585;">Writing with purpose, faith, and passion.</p>'
        . '</td></tr></table>';
}

/**
 * $o: subject, body (HTML), preheader, signature (bool), web_url, footer (HTML), pixel (URL), kicker
 */
function aw_email_layout(array $o) {
    $site = function_exists('aw_site_name') ? aw_site_name() : 'AngelWrites';
    $base = rtrim(defined('SITE_URL') ? SITE_URL : '', '/');
    $logo = $base . '/' . ltrim(function_exists('aw_logo_path') ? aw_logo_path() : 'assets/images/logo.png', '/');
    $e = 'aw_email_e';
    $font = "'Inter',Arial,Helvetica,sans-serif";
    $serif = "'Playfair Display',Georgia,'Times New Roman',serif";
    return '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<meta name="color-scheme" content="light"><meta name="supported-color-schemes" content="light"><title>' . $e($o['subject'] ?? $site) . '</title>'
        . '<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600&family=Playfair+Display:wght@600;700&display=swap" rel="stylesheet">'
        . '<style>body{margin:0;padding:0;background:#F7F3ED;} a{color:#c08a8b;} .aw-body p{margin:0 0 14px;} .aw-body h2,.aw-body h3{font-family:' . $serif . ';color:#2c1e1e;} @media (max-width:620px){.aw-pad{padding-left:20px!important;padding-right:20px!important;}}</style>'
        . '</head><body style="margin:0;padding:0;background:#F7F3ED;">'
        . '<div style="display:none;max-height:0;overflow:hidden;opacity:0;color:#F7F3ED;">' . $e($o['preheader'] ?? '') . '</div>'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#F7F3ED;"><tr><td align="center" style="padding:24px 12px;">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;background:#ffffff;border-radius:18px;overflow:hidden;border:1px solid #EFD8D6;">'
        . (!empty($o['web_url']) ? '<tr><td style="padding:10px 24px;background:#F7F3ED;font:12px ' . $font . ';color:#6b5a5a;text-align:right;"><a href="' . $e($o['web_url']) . '" style="color:#c08a8b;">View in your browser</a></td></tr>' : '')
        . '<tr><td style="padding:26px 28px 18px;text-align:center;background:#EFD8D6;border-bottom:4px solid #DBA1A2;">'
        . '<a href="' . $e($base) . '"><img src="' . $e($logo) . '" alt="' . $e($site) . '" width="150" style="max-width:150px;height:auto;border:0;display:inline-block;"></a>'
        . '<div style="font:600 11px ' . $font . ';letter-spacing:3px;text-transform:uppercase;color:#c08a8b;margin-top:10px;">' . $e($o['kicker'] ?? $site) . '</div></td></tr>'
        . '<tr><td class="aw-pad aw-body" style="padding:30px 32px 26px;font:16px/1.7 ' . $font . ';color:#3d2e2e;">'
        . (($o['subject'] ?? '') !== '' ? '<h1 style="font:700 24px/1.3 ' . $serif . ';color:#2c1e1e;margin:0 0 18px;">' . $e($o['subject']) . '</h1>' : '')
        . $o['body']
        . (!empty($o['signature']) ? aw_email_signature_html() : '')
        . '<div style="text-align:center;font:22px Georgia,serif;color:#DBA1A2;margin-top:22px;">&#9829;</div>'
        . '</td></tr>'
        . '<tr><td class="aw-pad" style="padding:20px 28px;background:#F7F3ED;border-top:1px solid #EFD8D6;font:12px/1.6 ' . $font . ';color:#6b5a5a;text-align:center;">'
        . ($o['footer'] ?? ('You received this email from ' . $e($site) . ' (<a href="' . $e($base) . '" style="color:#c08a8b;">' . $e(preg_replace('#^https?://#', '', $base)) . '</a>). Questions? Just reply to this email.'))
        . '<br>' . $e($site) . ', Malawi</td></tr>'
        . '</table></td></tr></table>'
        . (!empty($o['pixel']) ? '<img src="' . $e($o['pixel']) . '" width="1" height="1" alt="" style="display:block;border:0;width:1px;height:1px;">' : '')
        . '</body></html>';
}

function aw_email_html_to_text($html) {
    $t = preg_replace('#<(style|head|script)\b[^>]*>.*?</\1>#is', '', (string)$html);
    $t = preg_replace('#<a\b[^>]*href=(["\'])([^"\']+)\1[^>]*>(.*?)</a>#is', '$3 ($2)', $t);
    $t = preg_replace('#<(br|/p|/div|/h[1-6]|/li|/tr|/table)\b[^>]*>#i', "\n", $t);
    $t = preg_replace('#<li\b[^>]*>#i', "• ", $t);
    $t = html_entity_decode(strip_tags($t), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $t = preg_replace("/[ \t]+/", ' ', $t);
    $t = preg_replace("/ *\n */", "\n", $t);
    return trim(preg_replace("/\n{3,}/", "\n\n", $t));
}

/** Plain text written by the site (\n line breaks, bare links) as email HTML. */
function aw_email_text_to_html($text) {
    $h = aw_email_e(trim((string)$text));
    // Verification and reset codes stand out in a box.
    $h = preg_replace('/((?:code|Code|CODE)(?: is)?:?\s*)(\d{4,8}|[A-Z0-9]{6,10})\b/', '$1</p><p style="margin:6px 0 18px;"><span style="display:inline-block;font:700 28px/1 \'Inter\',Arial,sans-serif;letter-spacing:6px;color:#2c1e1e;background:#F7F3ED;border:2px dashed #DBA1A2;border-radius:12px;padding:12px 20px;">$2</span></p><p>', $h);
    $h = preg_replace_callback('#\bhttps?://[^\s<]+#', function ($m) {
        $url = rtrim($m[0], '.,);');
        $tail = substr($m[0], strlen($url));
        return '<a href="' . $url . '" style="color:#c08a8b;word-break:break-all;">' . $url . '</a>' . $tail;
    }, $h);
    $paras = preg_split("/\n\s*\n/", $h);
    return implode('', array_map(function ($p) { $p = trim($p); return $p === '' ? '' : '<p>' . nl2br($p) . '</p>'; }, $paras));
}

/** Removes a closing sign-off the message already had ("Blessings, Angella Bottoman, AngelWrites") when the signature is added. */
function aw_email_strip_signoff($text) {
    return preg_replace('/(\n\s*(?:—\s*)?(?:Blessings,?|With love,?|Kind regards,?|Warm regards,?|God bless,?|Angella Bottoman|AngelWrites(?: Team| Admin)?|The AngelWrites Team)\s*)+\s*$/iu', '', rtrim((string)$text));
}

/**
 * Wraps a message in the AngelWrites layout. Returns [html, text].
 * $opts: signature (default true; false for messages to the admin), preheader, to (recipient).
 */
function aw_email_brand($subject, $message, $isHTML = true, array $opts = []) {
    $message = (string)$message;
    $to = strtolower(trim((string)($opts['to'] ?? '')));
    $to_admin = $to !== '' && function_exists('aw_admin_email') && $to === strtolower(aw_admin_email());
    $signature = array_key_exists('signature', $opts) ? (bool)$opts['signature'] : !$to_admin;

    if (stripos($message, 'aw-email-layout') !== false) { // already wrapped (e.g. a newsletter)
        return [$message, aw_email_html_to_text($message)];
    }
    $looks_html = $isHTML && preg_match('#<(p|br|div|a|table|h[1-6]|ul|strong|em)\b#i', $message);
    if ($looks_html) {
        if (preg_match('#<body\b[^>]*>(.*)</body>#is', $message, $m)) $message = $m[1];
        $message = preg_replace('#<p[^>]*>\s*(?:—\s*)?AngelWrites(?: Team)?\s*</p>\s*$#iu', '', trim($message));
        $body = $message;
    } else {
        $body = aw_email_text_to_html($signature ? aw_email_strip_signoff($message) : $message);
    }
    $text_src = $looks_html ? aw_email_html_to_text($body) : trim($signature ? aw_email_strip_signoff($message) : $message);
    // ET2: the footer says what kind of email this is. 'footer_note' comes from mail_helper.php: the
    // automated-message sentence for transactional mail, the "not monitored" sentence for conversational
    // mail. (The old default footer told people to "just reply to this email", which nobody reads.)
    $note = trim((string)($opts['footer_note'] ?? ''));
    $footer = $to_admin ? 'This notification was sent by your website, ' . aw_email_e(function_exists('aw_site_name') ? aw_site_name() : 'AngelWrites') . '.' : null;
    if ($note !== '') $footer = ($footer !== null ? $footer . ' ' : '') . aw_email_e($note);
    // ET2: 'append_html' / 'append_text' add the conversational button and its note after the message.
    $body .= (string)($opts['append_html'] ?? '');
    $html = aw_email_layout([
        'subject' => $subject,
        'body' => '<div class="aw-email-layout">' . $body . '</div>',
        'preheader' => $opts['preheader'] ?? mb_substr(preg_replace('/\s+/u', ' ', $text_src), 0, 140),
        'signature' => $signature,
        'footer' => $footer,
        'kicker' => $to_admin ? 'Website notification' : null,
    ]);
    $text_src .= (($opts['append_text'] ?? '') !== '' ? "\n\n" . $opts['append_text'] : '');
    $text = $subject . "\n\n" . $text_src
        . ($signature ? "\n\nWith love and blessings,\nAngella Bottoman\nFounder, " . (function_exists('aw_site_name') ? aw_site_name() : 'AngelWrites') . ' · ' . preg_replace('#^https?://#', '', rtrim(defined('SITE_URL') ? SITE_URL : '', '/')) : '');
    if ($note !== '' && empty($opts['append_text'])) $text .= "\n\n" . $note;
    return [$html, $text];
}

}
