<?php
/**
 * S1: one sanitiser for every place rich text is saved or shown (PROGRAM_PLAN.md Phase 2;
 * owner answer 11: HTMLPurifier approved). Library: includes/vendor/htmlpurifier (4.19.1, LGPL).
 *
 *   aw_clean_html($html, 'rich')    staff writing: blog posts, reflections, poems, newsletters
 *   aw_clean_html($html, 'member')  member writing (stories, comments in rich text): no images,
 *                                   no headings, no embeds; links get rel="nofollow ugc noopener"
 *
 * Scripts, event handlers, javascript:/data: links, forms, objects and styles that could hide or
 * overlay content are removed. Only YouTube and Vimeo players may be embedded (rich only).
 * Results are cached on disk (data/cache/purify) by a hash of the input, so a page does not
 * re-clean the same text on every view. Never throws: on failure the text is escaped instead.
 */

const AW_PURIFY_VERSION = 'p1'; // bump to invalidate the cache when the rules change

function aw_purifier($profile) {
    static $p = [];
    if (isset($p[$profile])) return $p[$profile];
    if (!class_exists('HTMLPurifier', false)) {
        $boot = __DIR__ . '/vendor/htmlpurifier/HTMLPurifier.auto.php';
        if (!is_file($boot)) return $p[$profile] = null;
        require_once $boot;
    }
    $c = HTMLPurifier_Config::createDefault();
    $cacheDir = dirname(__DIR__) . '/data/cache/htmlpurifier';
    if ((is_dir($cacheDir) || @mkdir($cacheDir, 0700, true)) && is_writable($cacheDir)) {
        $c->set('Cache.SerializerPath', $cacheDir);
    } else {
        $c->set('Cache.DefinitionImpl', null);
    }
    $c->set('Core.Encoding', 'UTF-8');
    $c->set('HTML.Doctype', 'HTML 4.01 Transitional');
    $c->set('URI.AllowedSchemes', ['http' => true, 'https' => true, 'mailto' => true, 'tel' => true]);
    $c->set('Attr.AllowedFrameTargets', ['_blank']);
    $c->set('HTML.TargetNoopener', true);
    $c->set('HTML.TargetNoreferrer', true);
    $c->set('AutoFormat.RemoveEmpty.RemoveNbsp', false);
    $c->set('CSS.AllowedProperties', ['text-align', 'font-weight', 'font-style', 'font-size', 'text-decoration', 'color', 'background-color',
        'line-height', 'margin', 'margin-left', 'margin-right', 'margin-top', 'margin-bottom', 'padding', 'padding-left', 'padding-right',
        'border', 'width', 'max-width', 'height', 'vertical-align']);

    if ($profile === 'member') {
        $c->set('HTML.Allowed', 'p,br,strong,b,em,i,u,s,blockquote,ul,ol,li,a[href|title]');
        $c->set('HTML.Nofollow', true);
        $c->set('Attr.AllowedRel', ['nofollow', 'ugc', 'noopener', 'noreferrer']);
        $c->set('AutoFormat.Linkify', true);
        $c->set('HTML.DefinitionID', 'aw-member');
        $c->set('HTML.DefinitionRev', 1);
    } else {
        $c->set('HTML.Allowed',
            'p[style],br,strong,b,em,i,u,s,sub,sup,small,mark,blockquote[cite],q,cite,code,pre,hr,'
            . 'h2[style],h3[style],h4[style],h5,h6,span[style],div[style|class],'
            . 'ul,ol[start],li,dl,dt,dd,'
            . 'a[href|title|target|rel],img[src|alt|title|width|height|style],'
            . 'figure,figcaption,'
            . 'table[style],thead,tbody,tfoot,tr,th[colspan|rowspan|scope|style],td[colspan|rowspan|style],caption,'
            . 'iframe[src|width|height|frameborder|allowfullscreen|title]');
        $c->set('HTML.SafeIframe', true);
        $c->set('URI.SafeIframeRegexp', '%^https://(www\.youtube(-nocookie)?\.com/embed/|player\.vimeo\.com/video/)%');
        $c->set('Attr.AllowedClasses', ['aw-figure', 'aw-video', 'aw-quote', 'aligncenter', 'alignleft', 'alignright']);
        $c->set('HTML.DefinitionID', 'aw-rich');
        $c->set('HTML.DefinitionRev', 1);
        if ($def = $c->maybeGetRawHTMLDefinition()) {
            $def->addElement('figure', 'Block', 'Optional: (figcaption, Flow) | (Flow, figcaption) | Flow', 'Common');
            $def->addElement('figcaption', 'Inline', 'Flow', 'Common');
            $def->addElement('mark', 'Inline', 'Inline', 'Common');
            $def->addAttribute('iframe', 'allowfullscreen', 'Bool');
        }
    }
    try {
        return $p[$profile] = new HTMLPurifier($c);
    } catch (Throwable $e) {
        error_log('aw_purifier: ' . $e->getMessage());
        return $p[$profile] = null;
    }
}

/** Clean HTML for display or storage. Never returns unsafe markup. */
function aw_clean_html($html, $profile = 'rich') {
    $html = (string)$html;
    if (trim($html) === '') return '';
    $profile = $profile === 'member' ? 'member' : 'rich';
    $key = sha1(AW_PURIFY_VERSION . '|' . $profile . '|' . $html);
    $dir = dirname(__DIR__) . '/data/cache/purify/' . substr($key, 0, 2);
    $file = $dir . '/' . $key . '.html';
    if (is_file($file)) {
        $hit = @file_get_contents($file);
        if ($hit !== false) return $hit;
    }
    $pur = aw_purifier($profile);
    if (!$pur) {
        error_log('aw_clean_html: HTMLPurifier unavailable, escaping instead');
        return nl2br(htmlspecialchars(strip_tags($html), ENT_QUOTES, 'UTF-8'));
    }
    try {
        $out = $pur->purify($html);
        // an embed whose address was not allowed is left as an empty frame: drop it
        $out = preg_replace('#<iframe(?![^>]*\ssrc=)[^>]*>\s*</iframe>#i', '', $out);
        // newsletter placeholders such as href="{{unsubscribe_url}}" come back as typed (they are
        // replaced, escaped, when the newsletter is personalised)
        $out = preg_replace('/%7B%7B([a-z_]{1,30})%7D%7D/i', '{{$1}}', $out);
    } catch (Throwable $e) {
        error_log('aw_clean_html: ' . $e->getMessage());
        return nl2br(htmlspecialchars(strip_tags($html), ENT_QUOTES, 'UTF-8'));
    }
    if ((is_dir($dir) || @mkdir($dir, 0700, true)) && is_writable($dir)) {
        @file_put_contents($file, $out, LOCK_EX);
    }
    return $out;
}
