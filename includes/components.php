<?php
/**
 * A1 / Q: shared interface pieces (PROGRAM_PLAN.md Phase 1, wishes A and Q).
 *
 * The rule these encode: a LINK goes somewhere (it has an href and changes the page);
 * a BUTTON does something here (submits, opens, toggles). Never a clickable div or span,
 * never href="#". Styling comes from the existing .btn / .alert / .empty-state classes
 * (style.css and aw-design.css), so these change markup, not the look.
 *
 * All output is escaped here; pass plain text. Nothing throws.
 */

/** A link that looks like a button: for going somewhere. $variant: primary | outline | secondary. */
function aw_link_btn($href, $label, $variant = 'primary', array $opts = []) {
    $cls = 'btn btn-' . preg_replace('/[^a-z]/', '', (string)$variant) . (!empty($opts['small']) ? ' btn-sm' : '');
    $icon = !empty($opts['icon']) ? '<i class="fas ' . htmlspecialchars($opts['icon']) . '" aria-hidden="true"></i> ' : '';
    return '<a class="' . $cls . '" href="' . htmlspecialchars((string)$href) . '">' . $icon . htmlspecialchars((string)$label) . '</a>';
}

/** A button: for doing something on this page. $type: button | submit. */
function aw_btn($label, $variant = 'primary', array $opts = []) {
    $type = ($opts['type'] ?? 'button') === 'submit' ? 'submit' : 'button';
    $cls = 'btn btn-' . preg_replace('/[^a-z]/', '', (string)$variant) . (!empty($opts['small']) ? ' btn-sm' : '');
    $attrs = '';
    foreach (['name', 'value', 'id', 'onclick', 'aria-controls', 'aria-expanded'] as $a) {
        if (isset($opts[$a])) $attrs .= ' ' . $a . '="' . htmlspecialchars((string)$opts[$a]) . '"';
    }
    $icon = !empty($opts['icon']) ? '<i class="fas ' . htmlspecialchars($opts['icon']) . '" aria-hidden="true"></i> ' : '';
    return '<button type="' . $type . '" class="' . $cls . '"' . $attrs . '>' . $icon . htmlspecialchars((string)$label) . '</button>';
}

/** A message. $type: success | error | warning | info. Errors are announced at once, the rest politely. */
function aw_alert($type, $message) {
    $type = in_array($type, ['success', 'error', 'warning', 'info'], true) ? $type : 'info';
    if (trim((string)$message) === '') return '';
    $role = $type === 'error' ? 'alert' : 'status';
    return '<div class="alert alert-' . $type . '" role="' . $role . '">' . htmlspecialchars((string)$message) . '</div>';
}

/**
 * An empty state that teaches: what will appear here, and the one next step.
 * $action = [href, label] (a link) or null.
 */
function aw_empty($title, $text, $action = null, $icon = 'fa-heart') {
    $h = '<div class="empty-state">';
    $h .= '<div class="empty-state-icon" aria-hidden="true"><i class="fas ' . htmlspecialchars($icon) . '"></i></div>';
    if ($title !== '') $h .= '<h3>' . htmlspecialchars((string)$title) . '</h3>';
    if ($text !== '') $h .= '<p>' . htmlspecialchars((string)$text) . '</p>';
    if (is_array($action) && count($action) === 2) $h .= aw_link_btn($action[0], $action[1], 'primary');
    return $h . '</div>';
}

/**
 * A labelled form field. $type: text | email | password | textarea | number.
 * $opts: value, required, hint, autocomplete, maxlength, rows, placeholder.
 */
function aw_field($name, $label, $type = 'text', array $opts = []) {
    $id = 'f_' . preg_replace('/[^a-z0-9_]/i', '_', (string)$name);
    $req = !empty($opts['required']);
    $hintId = $id . '_hint';
    $common = ' id="' . $id . '" name="' . htmlspecialchars((string)$name) . '"'
        . ($req ? ' required aria-required="true"' : '')
        . (isset($opts['autocomplete']) ? ' autocomplete="' . htmlspecialchars($opts['autocomplete']) . '"' : '')
        . (isset($opts['maxlength']) ? ' maxlength="' . (int)$opts['maxlength'] . '"' : '')
        . (isset($opts['placeholder']) ? ' placeholder="' . htmlspecialchars($opts['placeholder']) . '"' : '')
        . (!empty($opts['hint']) ? ' aria-describedby="' . $hintId . '"' : '');
    $h = '<div class="form-group"><label for="' . $id . '">' . htmlspecialchars((string)$label) . ($req ? '' : ' <span class="field-hint">(optional)</span>') . '</label>';
    if ($type === 'textarea') {
        $h .= '<textarea' . $common . ' rows="' . (int)($opts['rows'] ?? 4) . '">' . htmlspecialchars((string)($opts['value'] ?? '')) . '</textarea>';
    } else {
        $type = in_array($type, ['text', 'email', 'password', 'number', 'url', 'tel'], true) ? $type : 'text';
        $h .= '<input type="' . $type . '"' . $common . ' value="' . htmlspecialchars((string)($opts['value'] ?? '')) . '">';
    }
    if (!empty($opts['hint'])) $h .= '<small class="field-hint" id="' . $hintId . '">' . htmlspecialchars($opts['hint']) . '</small>';
    return $h . '</div>';
}
