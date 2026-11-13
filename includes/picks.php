<?php
/**
 * C2: "Angella's picks" (PROGRAM_PLAN.md 5.4) — staff choose a poem, a story and a reflection,
 * each with a sentence on why. This replaces the points and streaks: something true and human,
 * chosen by the team, with no member compared to another.
 *
 * Stored in the settings table (no schema change):
 *   pick_poem_id / pick_poem_note, pick_story_id / pick_story_note,
 *   pick_reflection_id / pick_reflection_note, picks_updated_at
 * A "story" is any published blog post; a "reflection" is a published post in the
 * "Christian Reflections" category (the same rule reflections.php uses).
 *
 * Every function returns a value and logs on failure; nothing throws.
 */

const AW_PICK_SLOTS = [
    'poem'       => ['label' => 'A poem',       'icon' => 'fa-feather-alt'],
    'story'      => ['label' => 'A story',      'icon' => 'fa-book-open'],
    'reflection' => ['label' => 'A reflection', 'icon' => 'fa-dove'],
];

/** Plain-text excerpt, for any HTML or plain content. */
function aw_pick_excerpt($text, $len = 140) {
    $t = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags((string)$text), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    if (function_exists('mb_strlen')) {
        if (mb_strlen($t) <= $len) return $t;
        $cut = mb_substr($t, 0, $len);
        $sp = mb_strrpos($cut, ' ');
        return rtrim($sp > $len * 0.6 ? mb_substr($cut, 0, $sp) : $cut, ' ,.;:') . '…';
    }
    return strlen($t) <= $len ? $t : rtrim(substr($t, 0, $len)) . '…';
}

/** Read the raw pick settings straight from the database (not the per-request cache). */
function aw_picks_raw(PDO $db) {
    $raw = [];
    try {
        $st = $db->query("SELECT key, value FROM settings WHERE key LIKE 'pick\\_%' ESCAPE '\\' OR key = 'picks_updated_at'");
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $raw[$r['key']] = (string)$r['value'];
    } catch (Throwable $e) {
        error_log('picks: could not read settings: ' . $e->getMessage());
    }
    return $raw;
}

/** Load one item for a slot, or null when it no longer exists or is not public. */
function aw_pick_item(PDO $db, $slot, $id) {
    $id = (int)$id;
    if ($id <= 0) return null;
    try {
        if ($slot === 'poem') {
            $st = $db->prepare("SELECT id, title, intro, content, image_path FROM poems WHERE id = ?");
            $st->execute([$id]);
            $r = $st->fetch(PDO::FETCH_ASSOC);
            if (!$r) return null;
            $intro = trim((string)$r['intro']) !== '' ? $r['intro'] : $r['content'];
            return ['title' => (string)$r['title'], 'excerpt' => aw_pick_excerpt($intro),
                    'url' => SITE_URL . '/poem_view.php?id=' . (int)$r['id'], 'image' => (string)($r['image_path'] ?? '')];
        }
        if ($slot === 'story' || $slot === 'reflection') {
            $sql = "SELECT id, title, slug, excerpt, content, featured_image FROM blog_posts WHERE id = ? AND status = 'published'";
            if ($slot === 'reflection') $sql .= " AND category = 'Christian Reflections'";
            $st = $db->prepare($sql);
            $st->execute([$id]);
            $r = $st->fetch(PDO::FETCH_ASSOC);
            if (!$r) return null;
            $src = trim((string)$r['excerpt']) !== '' ? $r['excerpt'] : $r['content'];
            return ['title' => (string)$r['title'], 'excerpt' => aw_pick_excerpt($src),
                    'url' => SITE_URL . '/blog_post.php?slug=' . urlencode((string)$r['slug']), 'image' => (string)($r['featured_image'] ?? '')];
        }
    } catch (Throwable $e) {
        error_log('picks: could not load ' . $slot . ' #' . $id . ': ' . $e->getMessage());
    }
    return null;
}

/** The current picks that still resolve, in slot order: [slot => item + note + label + icon]. */
function aw_picks(PDO $db) {
    $raw = aw_picks_raw($db);
    $out = [];
    foreach (AW_PICK_SLOTS as $slot => $meta) {
        $item = aw_pick_item($db, $slot, $raw['pick_' . $slot . '_id'] ?? 0);
        if (!$item) continue;
        $out[$slot] = $item + $meta + ['note' => (string)($raw['pick_' . $slot . '_note'] ?? '')];
    }
    return $out;
}

/** Choices for the admin form: [slot => [id => title]], newest first. */
function aw_pick_choices(PDO $db) {
    $c = ['poem' => [], 'story' => [], 'reflection' => []];
    try {
        foreach ($db->query("SELECT id, title FROM poems ORDER BY created_at DESC, id DESC LIMIT 300") as $r) $c['poem'][(int)$r['id']] = (string)$r['title'];
        foreach ($db->query("SELECT id, title, category FROM blog_posts WHERE status = 'published' ORDER BY COALESCE(published_at, created_at) DESC, id DESC LIMIT 300") as $r) {
            $c['story'][(int)$r['id']] = (string)$r['title'];
            if ((string)$r['category'] === 'Christian Reflections') $c['reflection'][(int)$r['id']] = (string)$r['title'];
        }
    } catch (Throwable $e) {
        error_log('picks: could not list choices: ' . $e->getMessage());
    }
    return $c;
}

/**
 * Save the picks. $in = [slot => ['id' => int, 'note' => string]]. Returns true or false.
 * Ids are checked against aw_pick_item so a pick can never point at a draft or a missing item.
 */
function aw_save_picks(PDO $db, array $in) {
    try {
        $db->beginTransaction();
        $up = $db->prepare("INSERT INTO settings (key, value) VALUES (?, ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value");
        foreach (array_keys(AW_PICK_SLOTS) as $slot) {
            $id = (int)($in[$slot]['id'] ?? 0);
            if ($id > 0 && !aw_pick_item($db, $slot, $id)) $id = 0;
            $note = trim(preg_replace('/\s+/u', ' ', strip_tags((string)($in[$slot]['note'] ?? ''))));
            $note = function_exists('mb_substr') ? mb_substr($note, 0, 240) : substr($note, 0, 240);
            $up->execute(['pick_' . $slot . '_id', (string)$id]);
            $up->execute(['pick_' . $slot . '_note', $id > 0 ? $note : '']);
        }
        $up->execute(['picks_updated_at', date('Y-m-d H:i:s')]);
        $db->commit();
        // guests see a cached home page (index.php, 5 minutes): drop it so the new picks show now
        $cache = dirname(__DIR__) . '/cache/index.html';
        if (is_file($cache)) @unlink($cache);
        return true;
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        error_log('picks: could not save: ' . $e->getMessage());
        return false;
    }
}
