<?php
/**
 * Member details collected at sign-up and kept on the profile: country (which gives the phone's
 * country code), phone number, WhatsApp, age range, sex, town, how the team may contact them, and an
 * account-recovery question (for resetting a password without email, see recover.php).
 *
 * Why: the team uses these to look after people (contacting someone who asked for help, inviting
 * volunteers, moderation, age-appropriate mentoring). Phone numbers and contact details are shown
 * only to the owner, admins and the team (capability contact_members), never on public pages.
 * The member chooses whether the team may phone, WhatsApp or email them.
 *
 * One place for the fields, their checks and their HTML, used by register.php, profile.php and
 * account_security.php. Nothing throws; failures are logged.
 */

const AW_COUNTRIES = [
    'Afghanistan' => '+93', 'Albania' => '+355', 'Algeria' => '+213', 'Andorra' => '+376', 'Angola' => '+244',
    'Antigua and Barbuda' => '+1', 'Argentina' => '+54', 'Armenia' => '+374', 'Australia' => '+61', 'Austria' => '+43',
    'Azerbaijan' => '+994', 'Bahamas' => '+1', 'Bahrain' => '+973', 'Bangladesh' => '+880', 'Barbados' => '+1',
    'Belarus' => '+375', 'Belgium' => '+32', 'Belize' => '+501', 'Benin' => '+229', 'Bhutan' => '+975',
    'Bolivia' => '+591', 'Bosnia and Herzegovina' => '+387', 'Botswana' => '+267', 'Brazil' => '+55', 'Brunei' => '+673',
    'Bulgaria' => '+359', 'Burkina Faso' => '+226', 'Burundi' => '+257', 'Cabo Verde' => '+238', 'Cambodia' => '+855',
    'Cameroon' => '+237', 'Canada' => '+1', 'Central African Republic' => '+236', 'Chad' => '+235', 'Chile' => '+56',
    'China' => '+86', 'Colombia' => '+57', 'Comoros' => '+269', 'Congo (DRC)' => '+243', 'Congo (Republic)' => '+242',
    'Costa Rica' => '+506', 'Croatia' => '+385', 'Cuba' => '+53', 'Cyprus' => '+357', 'Czech Republic' => '+420',
    'Denmark' => '+45', 'Djibouti' => '+253', 'Dominica' => '+1', 'Dominican Republic' => '+1', 'Ecuador' => '+593',
    'Egypt' => '+20', 'El Salvador' => '+503', 'Equatorial Guinea' => '+240', 'Eritrea' => '+291', 'Estonia' => '+372',
    'Eswatini' => '+268', 'Ethiopia' => '+251', 'Fiji' => '+679', 'Finland' => '+358', 'France' => '+33',
    'Gabon' => '+241', 'Gambia' => '+220', 'Georgia' => '+995', 'Germany' => '+49', 'Ghana' => '+233',
    'Greece' => '+30', 'Grenada' => '+1', 'Guatemala' => '+502', 'Guinea' => '+224', 'Guinea-Bissau' => '+245',
    'Guyana' => '+592', 'Haiti' => '+509', 'Honduras' => '+504', 'Hungary' => '+36', 'Iceland' => '+354',
    'India' => '+91', 'Indonesia' => '+62', 'Iran' => '+98', 'Iraq' => '+964', 'Ireland' => '+353',
    'Israel' => '+972', 'Italy' => '+39', 'Ivory Coast' => '+225', 'Jamaica' => '+1', 'Japan' => '+81',
    'Jordan' => '+962', 'Kazakhstan' => '+7', 'Kenya' => '+254', 'Kiribati' => '+686', 'Kuwait' => '+965',
    'Kyrgyzstan' => '+996', 'Laos' => '+856', 'Latvia' => '+371', 'Lebanon' => '+961', 'Lesotho' => '+266',
    'Liberia' => '+231', 'Libya' => '+218', 'Liechtenstein' => '+423', 'Lithuania' => '+370', 'Luxembourg' => '+352',
    'Madagascar' => '+261', 'Malawi' => '+265', 'Malaysia' => '+60', 'Maldives' => '+960', 'Mali' => '+223',
    'Malta' => '+356', 'Marshall Islands' => '+692', 'Mauritania' => '+222', 'Mauritius' => '+230', 'Mexico' => '+52',
    'Micronesia' => '+691', 'Moldova' => '+373', 'Monaco' => '+377', 'Mongolia' => '+976', 'Montenegro' => '+382',
    'Morocco' => '+212', 'Mozambique' => '+258', 'Myanmar' => '+95', 'Namibia' => '+264', 'Nauru' => '+674',
    'Nepal' => '+977', 'Netherlands' => '+31', 'New Zealand' => '+64', 'Nicaragua' => '+505', 'Niger' => '+227',
    'Nigeria' => '+234', 'North Korea' => '+850', 'North Macedonia' => '+389', 'Norway' => '+47', 'Oman' => '+968',
    'Pakistan' => '+92', 'Palau' => '+680', 'Palestine' => '+970', 'Panama' => '+507', 'Papua New Guinea' => '+675',
    'Paraguay' => '+595', 'Peru' => '+51', 'Philippines' => '+63', 'Poland' => '+48', 'Portugal' => '+351',
    'Qatar' => '+974', 'Romania' => '+40', 'Russia' => '+7', 'Rwanda' => '+250', 'Saint Kitts and Nevis' => '+1',
    'Saint Lucia' => '+1', 'Saint Vincent' => '+1', 'Samoa' => '+685', 'San Marino' => '+378', 'Sao Tome and Principe' => '+239',
    'Saudi Arabia' => '+966', 'Senegal' => '+221', 'Serbia' => '+381', 'Seychelles' => '+248', 'Sierra Leone' => '+232',
    'Singapore' => '+65', 'Slovakia' => '+421', 'Slovenia' => '+386', 'Solomon Islands' => '+677', 'Somalia' => '+252',
    'South Africa' => '+27', 'South Korea' => '+82', 'South Sudan' => '+211', 'Spain' => '+34', 'Sri Lanka' => '+94',
    'Sudan' => '+249', 'Suriname' => '+597', 'Sweden' => '+46', 'Switzerland' => '+41', 'Syria' => '+963',
    'Taiwan' => '+886', 'Tajikistan' => '+992', 'Tanzania' => '+255', 'Thailand' => '+66', 'Timor-Leste' => '+670',
    'Togo' => '+228', 'Tonga' => '+676', 'Trinidad and Tobago' => '+1', 'Tunisia' => '+216', 'Turkey' => '+90',
    'Turkmenistan' => '+993', 'Tuvalu' => '+688', 'Uganda' => '+256', 'Ukraine' => '+380', 'United Arab Emirates' => '+971',
    'United Kingdom' => '+44', 'United States' => '+1', 'Uruguay' => '+598', 'Uzbekistan' => '+998', 'Vanuatu' => '+678',
    'Vatican City' => '+379', 'Venezuela' => '+58', 'Vietnam' => '+84', 'Yemen' => '+967', 'Zambia' => '+260',
    'Zimbabwe' => '+263'
];

const AW_AGE_RANGES = ['13-17' => '13 to 17', '18-24' => '18 to 24', '25-34' => '25 to 34', '35-44' => '35 to 44', '45-54' => '45 to 54', '55-64' => '55 to 64', '65+' => '65 or over'];
const AW_SEXES = ['female' => 'Female', 'male' => 'Male', 'prefer not to say' => 'Prefer not to say'];
const AW_CONTACT_WAYS = ['whatsapp' => 'WhatsApp', 'call' => 'Phone call or SMS', 'email' => 'Email'];
const AW_RECOVERY_QUESTIONS = [
    'school'   => 'The name of the first school you attended',
    'friend'   => 'The first name of your childhood best friend',
    'street'   => 'The street or village where you grew up',
    'mother'   => 'Your mother’s first name',
    'pet'      => 'The name of your first pet',
    'verse'    => 'Your favourite Bible verse reference (for example John 3:16)',
    'church'   => 'The name of the first church you remember attending',
];

/** Add the columns this needs to users (once). */
function aw_profile_columns(PDO $db) {
    static $ok = null;
    if ($ok !== null) return $ok;
    try {
        $have = [];
        foreach ($db->query("PRAGMA table_info(users)")->fetchAll(PDO::FETCH_ASSOC) as $c) $have[$c['name']] = true;
        foreach (['age_range' => 'TEXT', 'whatsapp_number' => 'TEXT', 'contact_ok' => 'TEXT', 'city' => 'TEXT',
                  'recovery_question' => 'TEXT', 'recovery_answer' => 'TEXT', 'details_updated_at' => 'DATETIME',
                  'reset_attempts' => 'INTEGER DEFAULT 0', 'recovery_attempts' => 'INTEGER DEFAULT 0', 'recovery_locked_until' => 'DATETIME'] as $col => $def) {
            if (!isset($have[$col])) $db->exec("ALTER TABLE users ADD COLUMN $col $def");
        }
        return $ok = true;
    } catch (Throwable $e) {
        error_log('aw_profile_columns: ' . $e->getMessage());
        return $ok = false;
    }
}

/** The dialling code for a country name, or ''. */
function aw_dial_code($country) { return AW_COUNTRIES[(string)$country] ?? ''; }

/**
 * A phone number in one form: +<code><digits>. Accepts what people type (spaces, dashes, a leading 0,
 * the code typed again). '' when it is not a plausible number.
 */
function aw_phone_normalize($country, $number) {
    $n = preg_replace('/[^\d+]/', '', (string)$number);
    if ($n === '') return '';
    $code = preg_replace('/\D/', '', aw_dial_code($country));
    if (strpos($n, '+') === 0) $n = substr($n, 1);
    elseif (strpos($n, '00') === 0) $n = substr($n, 2);
    elseif ($code !== '') { $n = ltrim($n, '0'); if (strpos($n, $code) !== 0 || strlen($n) < 9) $n = $code . $n; }
    $n = preg_replace('/\D/', '', $n);
    return (strlen($n) >= 8 && strlen($n) <= 15) ? '+' . $n : '';
}

/** Recovery answers are compared loosely: case, spaces and punctuation do not matter. */
function aw_recovery_norm($answer) {
    $a = function_exists('mb_strtolower') ? mb_strtolower((string)$answer, 'UTF-8') : strtolower((string)$answer);
    return preg_replace('/[^\p{L}\p{N}]+/u', '', $a);
}

/**
 * Read and check the details from a form. $require: true at sign-up (country, phone, age range and
 * sex are needed). $with_recovery: also read the recovery question and answer.
 * Returns ['data' => [column => value], 'error' => ''].
 */
function aw_profile_collect(array $post, $require = true, $with_recovery = false) {
    $country = trim((string)($post['country'] ?? ''));
    $phone_in = trim((string)($post['phone'] ?? ''));
    $age = (string)($post['age_range'] ?? '');
    $sex = (string)($post['sex'] ?? '');
    $city = trim(mb_substr((string)($post['city'] ?? ''), 0, 80));
    $wa_same = !empty($post['whatsapp_same']);
    $wa_in = trim((string)($post['whatsapp'] ?? ''));
    $ways = array_values(array_intersect(array_keys(AW_CONTACT_WAYS), (array)($post['contact_ok'] ?? [])));
    $err = '';
    if ($country !== '' && !isset(AW_COUNTRIES[$country])) $err = 'Please choose your country from the list.';
    elseif ($require && $country === '') $err = 'Please choose your country.';
    elseif (($post['age_range'] ?? '') === 'under13') $err = 'AngelWrites is for people aged 13 and over. Please ask a parent or guardian to explore it with you.';
    elseif ($age !== '' && !isset(AW_AGE_RANGES[$age])) $err = 'Please choose your age range.';
    elseif ($require && $age === '') $err = 'Please choose your age range.';
    elseif ($sex !== '' && !isset(AW_SEXES[$sex])) $err = 'Please choose an option for sex.';
    elseif ($require && $sex === '') $err = 'Please choose an option for sex.';
    $phone = $phone_in === '' ? '' : aw_phone_normalize($country, $phone_in);
    if ($err === '' && $require && $phone_in === '') $err = 'Please enter your phone number.';
    elseif ($err === '' && $phone_in !== '' && $phone === '') $err = 'That phone number does not look right. Please check it (the country code is added for you).';
    $wa = $wa_same ? $phone : ($wa_in === '' ? '' : aw_phone_normalize($country, $wa_in));
    if ($err === '' && !$wa_same && $wa_in !== '' && $wa === '') $err = 'That WhatsApp number does not look right. Please check it.';
    $data = ['country' => $country ?: null, 'contact_number' => $phone ?: null, 'whatsapp_number' => $wa ?: null, 'age_range' => $age ?: null,
             'gender' => $sex ?: null, 'city' => $city !== '' ? $city : null, 'contact_ok' => implode(',', $ways)];
    if ($with_recovery) {
        $q = (string)($post['recovery_question'] ?? '');
        $a = aw_recovery_norm($post['recovery_answer'] ?? '');
        if ($err === '' && $require && ($q === '' || $a === '')) $err = 'Please choose a recovery question and answer it. It lets you reset your password without email.';
        elseif ($err === '' && $q !== '' && !isset(AW_RECOVERY_QUESTIONS[$q])) $err = 'Please choose a recovery question from the list.';
        elseif ($err === '' && $q !== '' && mb_strlen($a) < 3) $err = 'Please give a recovery answer of at least 3 letters or numbers.';
        if ($q !== '' && $a !== '') { $data['recovery_question'] = $q; $data['recovery_answer'] = password_hash($a, PASSWORD_DEFAULT); }
    }
    return ['data' => $data, 'error' => $err];
}

/** Save collected details for a user. */
function aw_profile_save(PDO $db, $uid, array $data) {
    if (!aw_profile_columns($db) || !$data) return false;
    try {
        $sets = []; $vals = [];
        foreach ($data as $k => $v) { if (!preg_match('/^[a-z_]+$/', $k)) continue; $sets[] = "$k = ?"; $vals[] = $v; }
        $sets[] = 'details_updated_at = CURRENT_TIMESTAMP';
        $vals[] = (int)$uid;
        $db->prepare("UPDATE users SET " . implode(', ', $sets) . " WHERE id = ?")->execute($vals);
        return true;
    } catch (Throwable $e) { error_log('aw_profile_save: ' . $e->getMessage()); return false; }
}

/** Is anything the team relies on still missing? */
function aw_profile_missing(array $u) {
    $miss = [];
    if (empty($u['country'])) $miss[] = 'country';
    if (empty($u['contact_number'])) $miss[] = 'phone number';
    if (empty($u['age_range'])) $miss[] = 'age range';
    if (empty($u['gender'])) $miss[] = 'sex';
    if (empty($u['recovery_question'])) $miss[] = 'recovery question';
    return $miss;
}

/**
 * The HTML for the details. $v: current values (a users row or the posted form).
 * $opts: ['require' => bool, 'recovery' => bool, 'prefix' => id prefix].
 */
function aw_profile_fields_html(array $v, array $opts = []) {
    $req = !empty($opts['require']); $rec = !empty($opts['recovery']); $p = (string)($opts['prefix'] ?? 'pf');
    $e = function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); };
    $r = $req ? ' required' : '';
    $star = $req ? ' <span class="pf-req" aria-hidden="true">*</span>' : ' <small>(optional)</small>';
    $country = (string)($v['country'] ?? '');
    $code = aw_dial_code($country);
    // show the number without its country code; it is added back on saving
    $phone = (string)($v['phone'] ?? $v['contact_number'] ?? '');
    $cd = preg_replace('/\D/', '', $code);
    $strip = function ($num) use ($cd) { $d = preg_replace('/[^\d+]/', '', (string)$num); return ($cd !== '' && strpos($d, '+' . $cd) === 0) ? substr($d, strlen($cd) + 1) : (string)$num; };
    $wa = (string)($v['whatsapp'] ?? $v['whatsapp_number'] ?? '');
    $wa_same = isset($v['whatsapp_same']) ? !empty($v['whatsapp_same']) : ($wa === '' || $wa === $phone);
    $ways = is_array($v['contact_ok'] ?? null) ? $v['contact_ok'] : array_filter(explode(',', (string)($v['contact_ok'] ?? '')));
    $sex = (string)($v['sex'] ?? $v['gender'] ?? '');
    $h = '<div class="pf-block">';
    $h .= '<div class="pf-row">';
    $h .= '<div class="form-group"><label for="' . $p . '_country">Country' . $star . '</label><select id="' . $p . '_country" name="country" data-pf-country' . $r . ' autocomplete="country-name"><option value="">Choose your country</option>';
    foreach (AW_COUNTRIES as $name => $dc) $h .= '<option value="' . $e($name) . '" data-code="' . $e($dc) . '"' . ($name === $country ? ' selected' : '') . '>' . $e($name) . ' (' . $e($dc) . ')</option>';
    $h .= '</select></div>';
    $h .= '<div class="form-group"><label for="' . $p . '_city">Town or city <small>(optional)</small></label><input type="text" id="' . $p . '_city" name="city" maxlength="80" autocomplete="address-level2" value="' . $e($v['city'] ?? '') . '"></div>';
    $h .= '</div>';
    $h .= '<div class="form-group"><label for="' . $p . '_phone">Phone number' . $star . '</label><div class="pf-phone"><span class="pf-code" data-pf-code aria-hidden="true">' . $e($code ?: '+') . '</span>'
        . '<input type="tel" id="' . $p . '_phone" name="phone" inputmode="tel" autocomplete="tel-national" placeholder="e.g. 991 234 567" value="' . $e($strip($phone)) . '"' . $r . ' aria-describedby="' . $p . '_phone_hint"></div>'
        . '<small class="field-hint" id="' . $p . '_phone_hint">The country code is added from your country.</small></div>';
    $h .= '<div class="form-group pf-check"><label><input type="checkbox" name="whatsapp_same" value="1" data-pf-wa-same' . ($wa_same ? ' checked' : '') . '> I use WhatsApp on this number</label></div>';
    $h .= '<div class="form-group" data-pf-wa' . ($wa_same ? ' hidden' : '') . '><label for="' . $p . '_wa">WhatsApp number <small>(optional, if different)</small></label><div class="pf-phone"><span class="pf-code" data-pf-code aria-hidden="true">' . $e($code ?: '+') . '</span><input type="tel" id="' . $p . '_wa" name="whatsapp" inputmode="tel" value="' . $e($wa_same ? '' : $strip($wa)) . '"></div></div>';
    $h .= '<div class="pf-row">';
    $h .= '<div class="form-group"><label for="' . $p . '_age">Age range' . $star . '</label><select id="' . $p . '_age" name="age_range"' . $r . '><option value="">Choose</option><option value="under13">Under 13</option>';
    foreach (AW_AGE_RANGES as $k => $l) $h .= '<option value="' . $e($k) . '"' . ((string)($v['age_range'] ?? '') === $k ? ' selected' : '') . '>' . $e($l) . '</option>';
    $h .= '</select></div>';
    $h .= '<div class="form-group"><label for="' . $p . '_sex">Sex' . $star . '</label><select id="' . $p . '_sex" name="sex"' . $r . '><option value="">Choose</option>';
    foreach (AW_SEXES as $k => $l) $h .= '<option value="' . $e($k) . '"' . ($sex === $k ? ' selected' : '') . '>' . $e($l) . '</option>';
    $h .= '</select></div></div>';
    $h .= '<fieldset class="pf-ways"><legend>The AngelWrites team may contact me by</legend>';
    foreach (AW_CONTACT_WAYS as $k => $l) $h .= '<label><input type="checkbox" name="contact_ok[]" value="' . $k . '"' . (in_array($k, $ways, true) ? ' checked' : '') . '> ' . $e($l) . '</label>';
    $h .= '<small class="field-hint">Only the AngelWrites team sees your number. It is never shown on the site.</small></fieldset>';
    if ($rec) {
        $h .= '<fieldset class="pf-ways"><legend>Account recovery' . ($req ? ' <span class="pf-req" aria-hidden="true">*</span>' : '') . '</legend>'
            . '<p class="field-hint" style="margin:0 0 8px">If you ever lose access to your email, this answer (with your phone number) lets you reset your password. Choose something you will remember and others would not know.</p>'
            . '<div class="form-group"><label for="' . $p . '_rq">Question</label><select id="' . $p . '_rq" name="recovery_question"' . $r . '><option value="">Choose a question</option>';
        foreach (AW_RECOVERY_QUESTIONS as $k => $l) $h .= '<option value="' . $k . '"' . ((string)($v['recovery_question'] ?? '') === $k ? ' selected' : '') . '>' . $e($l) . '</option>';
        $h .= '</select></div><div class="form-group"><label for="' . $p . '_ra">Your answer</label><input type="text" id="' . $p . '_ra" name="recovery_answer" autocomplete="off" maxlength="100"' . $r . '>'
            . '<small class="field-hint">Capitals and spaces do not matter. It is stored scrambled, so nobody (not even the team) can read it.</small></div></fieldset>';
    }
    $h .= '</div>';
    return $h . aw_profile_assets();
}

function aw_profile_assets() {
    static $done = false;
    if ($done) return '';
    $done = true;
    return <<<'HTML'
<style>
.pf-row { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 0 14px; }
.pf-phone { display: flex; align-items: stretch; }
.pf-code { display: inline-flex; align-items: center; padding: 0 12px; border: 1px solid var(--border, #e5d5d5); border-right: 0; border-radius: 10px 0 0 10px; background: var(--vanilla, #faf3ef); font-weight: 600; min-width: 56px; justify-content: center; color: var(--text, #3d2e2e); }
.pf-phone input { border-radius: 0 10px 10px 0 !important; flex: 1; min-width: 0; }
.pf-check label, .pf-ways label { display: flex; align-items: center; gap: 8px; min-height: 36px; font-weight: 500; }
.pf-ways { border: 1px solid var(--border, #e5d5d5); border-radius: 12px; padding: 10px 14px; margin: 6px 0 14px; }
.pf-ways legend { font-weight: 600; padding: 0 6px; }
.pf-req { color: #a8432f; }
.pf-check input, .pf-ways input[type=checkbox] { width: 18px; height: 18px; }
</style>
<script>
(function () {
  function init(root) {
    var sel = root.querySelector('[data-pf-country]'); if (!sel) return;
    var codes = root.querySelectorAll('[data-pf-code]');
    function upd() { var o = sel.options[sel.selectedIndex]; var c = (o && o.getAttribute('data-code')) || '+'; codes.forEach(function (x) { x.textContent = c; }); }
    sel.addEventListener('change', upd); upd();
    var same = root.querySelector('[data-pf-wa-same]'), wa = root.querySelector('[data-pf-wa]');
    if (same && wa) same.addEventListener('change', function () { wa.hidden = same.checked; });
  }
  function all() { document.querySelectorAll('.pf-block').forEach(init); }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', all); else all();
})();
</script>
HTML;
}

/**
 * After a password is changed by a reset: end "remember me" on every device, clear reset codes, and
 * tell the member (on the site and by email). Returns nothing; failures are logged.
 */
function aw_after_password_reset(PDO $db, $uid, $how) {
    try { $db->prepare("DELETE FROM remember_tokens WHERE user_id = ?")->execute([(int)$uid]); } catch (Throwable $e) { /* optional table */ }
    try { $db->prepare("UPDATE users SET reset_code = NULL, reset_code_expiry = NULL, reset_attempts = 0, recovery_attempts = 0, recovery_locked_until = NULL WHERE id = ?")->execute([(int)$uid]); }
    catch (Throwable $e) { error_log('after reset: ' . $e->getMessage()); }
    if (function_exists('aw_audit')) aw_audit('password_reset', 'user', (int)$uid, (string)$how);
    try {
        $st = $db->prepare("SELECT email, first_name FROM users WHERE id = ?"); $st->execute([(int)$uid]); $u = $st->fetch(PDO::FETCH_ASSOC);
        if ($u && function_exists('awNotify')) awNotify($db, (int)$uid, 'password_changed', 'Your password was changed', 'If this was not you, contact the AngelWrites team straight away.', SITE_URL . '/contact.php', ['email' => false, 'self_ok' => true, 'actor' => 0]);
        if ($u && !empty($u['email']) && function_exists('sendEmail')) {
            sendEmail($u['email'], 'Your AngelWrites password was changed',
                'Hello ' . htmlspecialchars((string)$u['first_name']) . ',<br><br>The password for your AngelWrites account was just changed ' . ($how === 'recovery' ? 'using your recovery question and phone number' : 'with a reset code') . ' on ' . date('j F Y \a\t H:i') . ' (server time). You have been signed out on other devices.<br><br>If this was you, there is nothing more to do. If it was not, please <a href="' . SITE_URL . '/contact.php">contact us</a> straight away.');
        }
    } catch (Throwable $e) { error_log('after reset notice: ' . $e->getMessage()); }
}
