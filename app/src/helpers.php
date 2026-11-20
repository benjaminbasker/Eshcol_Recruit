<?php
declare(strict_types=1);

/** Global template helpers. Loaded by Composer on every request. */

/** HTML-escape a value for output. */
function e(mixed $v): string
{
    return htmlspecialchars((string) ($v ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function csrf(): string
{
    return \Eshcol\Ats\Http::csrfField();
}

/** "4.5 LPA", or a dash when unknown. */
function lpa(mixed $v): string
{
    return $v === null || $v === '' ? '–' : rtrim(rtrim(number_format((float) $v, 2), '0'), '.') . ' LPA';
}

/**
 * A name search that matches each word wherever it is (17 Nov 2026): "Shubham Satpute" finds
 * "Shubham Annasaheb Satpute", and "Satpute Shubham" too. Returns [SQL, args] for "$col has every word",
 * or ['0', []] when the query has fewer than two words (the plain phrase search covers those).
 * @return array{0: string, 1: list<string>}
 */
function name_words_sql(string $col, string $q): array
{
    $words = array_values(array_filter(preg_split('/[\s.,]+/u', trim($q)) ?: [], fn ($w) => mb_strlen($w) >= 1));
    if (count($words) < 2) {
        return ['0', []];
    }
    $words = array_slice($words, 0, 6);
    return ['(' . implode(' AND ', array_fill(0, count($words), "$col LIKE ?")) . ')', array_map(fn ($w) => '%' . addcslashes($w, '%_\\') . '%', $words)];
}

/**
 * A candidate's stage as coloured tags, for any list (17 Nov 2026): "Accounting Associate · Shortlisted".
 * With $roleId, only that position's stage. Call prime_stages() with the list's ids first: one query, not one per row.
 */
function stage_tags(int|string|null $candidateId, ?int $roleId = null): string
{
    return \Eshcol\Ats\Services\KeyFacts::stageTags(\Eshcol\Ats\App::get(), (int) $candidateId, $roleId);
}

/**
 * The stage drop-down's options (20 Nov 2026): "Client interview" is offered as Round 1, 2 and 3, so a candidate is moved
 * to the next round in one pick. A round is posted as "stage:round" (e.g. "5:2"); every other stage as its id.
 */
function stage_options(array $stages, int $currentStageId, ?int $round): string
{
    $app = \Eshcol\Ats\App::get();
    $out = '';
    foreach ($stages as $s) {
        $id = (int) $s['id'];
        $closing = in_array($s['kind'], ['fail', 'hold'], true) ? ' data-closing="1"' . ($s['kind'] === 'hold' ? ' data-hold="1"' : '') : '';
        if (\Eshcol\Ats\Services\Stages::is($app, $id, \Eshcol\Ats\Services\Stages::INTERVIEW)) {
            $at = $currentStageId === $id ? max(1, min(3, (int) $round)) : 0;
            for ($r = 1; $r <= 3; $r++) {
                $out .= '<option value="' . ($r === 1 ? $id : "$id:$r") . '"' . ($at === $r ? ' selected' : '') . '>' . e($s['name'] . ' · Round ' . $r) . '</option>';
            }
            continue;
        }
        $out .= '<option value="' . $id . '"' . ($currentStageId === $id ? ' selected' : '') . $closing . '>' . e($s['name']) . '</option>';
    }
    return $out;
}

function prime_stages(array $candidateIds): void
{
    \Eshcol\Ats\Services\KeyFacts::primeStages(\Eshcol\Ats\App::get(), $candidateIds);
}

/**
 * A phone number as a "tel:" link (16 Nov 2026), so the computer's calling app (Windows Phone Link, Teams, Zoom
 * Phone and so on) dials it directly: an Indian mobile becomes +91 and ten digits; a number already in +country
 * form is kept; anything else is passed on as its digits.
 */
function tel_link(?string $phone): string
{
    $raw = trim((string) $phone);
    $d = preg_replace('/\D/', '', $raw);
    $e164 = match (true) {
        strlen($d) === 10 && preg_match('/^[6-9]/', $d) === 1 => '+91' . $d,
        strlen($d) === 11 && $d[0] === '0' && !str_starts_with($raw, '+') => '+91' . substr($d, 1), // 098765 43210, 044-2345 6789
        strlen($d) === 12 && str_starts_with($d, '91') => '+' . $d,
        str_starts_with($raw, '+') && $d !== '' => '+' . $d,
        default => $d,
    };
    return 'tel:' . $e164;
}

/** Experience in months shown as "3 yrs 4 mos". */
function experience(mixed $months): string
{
    if ($months === null || $months === '') {
        return '–';
    }
    $m = (int) $months;
    $y = intdiv($m, 12);
    $r = $m % 12;
    return trim(($y ? "$y yr" . ($y > 1 ? 's' : '') : '') . ($r ? " $r mo" . ($r > 1 ? 's' : '') : '')) ?: '0';
}

/**
 * Experience in months as of today: the figure last known, plus the time since,
 * assuming the candidate has stayed in work. Null when unknown.
 */
function exp_now(array $c): ?int
{
    if (($c['exp_months'] ?? null) === null || $c['exp_months'] === '') {
        return null;
    }
    $m = (int) $c['exp_months'];
    if (!empty($c['exp_as_of'])) {
        $from = new DateTimeImmutable($c['exp_as_of']);
        $d = $from->diff(new DateTimeImmutable('today'));
        $m += $d->invert ? 0 : $d->y * 12 + $d->m;
    }
    return $m;
}

/** True when today's experience is an estimate grown from an older figure (a month or more). */
function exp_is_estimate(array $c): bool
{
    $now = exp_now($c);
    return $now !== null && $now > (int) $c['exp_months'];
}

/** Experience level bands from Settings: [[name, fromMonths, toMonths], ...]. */
function level_bands(): array
{
    static $bands = null;
    return $bands ??= json_decode((string) \Eshcol\Ats\App::get()->setting('level_bands', '[["Fresher",0,12],["Junior",12,36],["Mid-level",36,84],["Senior",84,144],["Leadership",144,9999]]'), true) ?: [];
}

/** "Mid-level" etc. for an experience in months; null when unknown. */
function level_name(?int $months): ?string
{
    if ($months === null) {
        return null;
    }
    foreach (level_bands() as [$name, $from, $to]) {
        if ($months >= $from && $months < $to) {
            return $name;
        }
    }
    return null;
}

function nice_date(?string $dt, bool $time = false): string
{
    if (!$dt) {
        return '–';
    }
    return date($time ? 'j M Y, g:i a' : 'j M Y', strtotime($dt));
}

/** CSS class for a score badge. */
function score_class(?int $score, int $threshold = 50): string
{
    if ($score === null) {
        return 'score-none';
    }
    return $score >= 75 ? 'score-high' : ($score >= $threshold ? 'score-mid' : 'score-low');
}

function selected(mixed $a, mixed $b): string
{
    return (string) $a === (string) $b ? ' selected' : '';
}

/** Link to a file in public/assets, stamped with its last change so browsers fetch new versions after an update. */
function asset(string $file): string
{
    $path = dirname(__DIR__) . '/public/assets/' . basename($file);
    return '/assets/' . rawurlencode(basename($file)) . '?v=' . (is_file($path) ? filemtime($path) : '1');
}

/** Reference numbers people can read out on a call: EG-10042 (candidate), SL-0012 (shortlist), IV-0031 (interview). */
function ref_candidate(int|string $id): string
{
    return 'EG-' . (10000 + (int) $id);
}

function ref_shortlist(int|string $id): string
{
    return sprintf('SL-%04d', (int) $id);
}

function ref_interview(int|string $id): string
{
    return sprintf('IV-%04d', (int) $id);
}

/** "EG-10042", "eg 10042", "SL-12" → ['candidate', 42] / ['shortlist', 12]; anything else → null. */
function parse_ref(string $q): ?array
{
    if (!preg_match('/^\s*(EG|SL|IV)[\s-]?0*(\d{1,7})\s*$/i', $q, $m)) {
        return null;
    }
    $n = (int) $m[2];
    return match (strtoupper($m[1])) {
        'EG' => $n > 10000 ? ['candidate', $n - 10000] : null,
        'SL' => ['shortlist', $n],
        default => ['interview', $n],
    };
}

/** Lakh per year → rupees with Indian grouping: 3.24 → "3,24,000". */
function inr(mixed $lpa): string
{
    if ($lpa === null || $lpa === '') {
        return '–';
    }
    $n = (string) (int) round((float) $lpa * 100000);
    if (strlen($n) <= 3) {
        return $n;
    }
    $last = substr($n, -3);
    $rest = substr($n, 0, -3);
    return preg_replace('/\B(?=(\d{2})+(?!\d))/', ',', $rest) . ',' . $last;
}

/** A person's email signature lines: name, title, company, mobile, website. */
function signature_lines(array $u): array
{
    return array_values(array_filter([
        (string) ($u['name'] ?? ''),
        (string) ($u['title'] ?? ''),
        'Eshcol Global Services (P) Ltd',
        !empty($u['mobile']) ? 'Mobile: ' . $u['mobile'] : '',
        'Website: www.eshcolglobal.com',
    ], fn ($l) => $l !== ''));
}

/**
 * The formatted email signature (Benjamin's design, Eshcol_Email_Signature.html; 17 Nov 2026), for every email the
 * app sends: "Regards," then the logo beside name, title, company, mobile and website, then the LinkedIn, Facebook,
 * X and "Follow Us" icons. The images are served from this app (assets/sig/), not embedded, so Gmail shows them.
 * $u: the sender (name, title, mobile, role); null = the owner. The personal LinkedIn icon is the owner's only.
 */
function signature_html(?array $u = null, string $regards = 'Regards,'): string
{
    $app = \Eshcol\Ats\App::get();
    $u ??= $app->db->one("SELECT * FROM users WHERE role = 'owner' AND is_active = 1 ORDER BY id LIMIT 1") ?: [];
    $base = rtrim((string) ($app->config['app']['url'] ?? 'https://recruit.eshcolglobal.com'), '/') . '/assets/sig/';
    $f = "font-family:'Plus Jakarta Sans','Segoe UI',Roboto,Arial,Helvetica,sans-serif;";
    $img = fn (string $file, int $w, int $h, string $alt) => '<img src="' . e($base . $file) . '" width="' . $w . '" height="' . $h . '" alt="' . e($alt) . '" style="display:block;border:0;outline:none;text-decoration:none;-ms-interpolation-mode:bicubic;" />';
    $icon = fn (string $href, string $file, int $w, int $h, string $alt) => '<td style="padding:0;"><a href="' . e($href) . '" style="text-decoration:none;">' . $img($file, $w, $h, $alt) . '</a></td><td width="6" style="width:6px;font-size:0;line-height:0;">&nbsp;</td>';
    $name = trim((string) ($u['name'] ?? '')) ?: 'Eshcol Global';
    $title = trim((string) ($u['title'] ?? ''));
    $mobile = trim((string) ($u['mobile'] ?? ''));
    $tel = $mobile !== '' ? tel_link($mobile) : '';
    $shown = $tel !== '' && preg_match('/^tel:\+91(\d{5})(\d{5})$/', $tel, $m) ? "+91 $m[1] $m[2]" : $mobile;
    $owner = ($u['role'] ?? '') === 'owner';
    return '<table cellpadding="0" cellspacing="0" border="0" style="border-collapse:collapse;mso-table-lspace:0pt;mso-table-rspace:0pt;' . $f . '">'
        . '<tr><td style="padding:0 0 10px 0;' . $f . 'font-size:13px;line-height:17px;font-weight:bold;color:#1A1A1A;">' . e($regards) . '</td></tr>'
        . '<tr><td style="padding:0;"><table cellpadding="0" cellspacing="0" border="0" style="border-collapse:collapse;"><tr>'
        . '<td valign="middle" style="padding:0;vertical-align:middle;">' . $img('logo.png', 200, 96, 'Eshcol Global, People and Property') . '</td>'
        . '<td width="8" style="width:8px;min-width:8px;font-size:0;line-height:0;">&nbsp;</td>'
        . '<td valign="middle" style="padding:0;vertical-align:middle;' . $f . '">'
        . '<div style="font-size:15px;line-height:19px;font-weight:bold;color:#320A67;">' . e($name) . '</div>'
        . ($title !== '' ? '<div style="font-size:13px;line-height:16px;font-weight:bold;color:#7946B2;">' . e($title) . '</div>' : '')
        . '<div style="font-size:13px;line-height:16px;font-weight:bold;color:#320A67;">Eshcol Global Services (P) Ltd</div>'
        . ($mobile !== '' ? '<div style="font-size:13px;line-height:16px;color:#6E6780;">Mobile:&nbsp;<a href="' . e($tel) . '" style="color:#320A67;text-decoration:none;">' . e($shown) . '</a></div>' : '')
        . '<div style="font-size:13px;line-height:16px;color:#6E6780;">Website:&nbsp;<a href="https://www.eshcolglobal.com" style="color:#320A67;text-decoration:none;">www.eshcolglobal.com</a></div>'
        . '</td></tr></table></td></tr>'
        . '<tr><td style="padding:9px 0 0 0;"><table cellpadding="0" cellspacing="0" border="0" style="border-collapse:collapse;"><tr>'
        . ($owner ? $icon('https://www.linkedin.com/in/benjaminb2/', 'linkedin.png', 24, 24, $name . ' on LinkedIn') : '')
        . $icon('https://www.facebook.com/eshcolglobal/', 'facebook.png', 24, 24, 'Eshcol Global on Facebook')
        . $icon('https://x.com/eshcolglobal', 'x.png', 24, 24, 'Eshcol Global on X')
        . '<td style="padding:0;"><a href="https://www.linkedin.com/company/eshcolglobal" style="text-decoration:none;">' . $img('follow-linkedin.png', 93, 30, 'Follow Eshcol Global on LinkedIn') . '</a></td>'
        . '</tr></table></td></tr></table>';
}

/**
 * Every city's localities, for the locality drop-down that follows the City box (17 Nov 2026: Chennai showed none
 * on the Edit page, which only ever listed the city saved when the page opened). 'cities': city => localities;
 * 'alias': lower-case ways of writing a city that has localities ("madras", "bangalore") => that city.
 * @return array{cities: array<string, list<string>>, alias: array<string, string>}
 */
function locality_map(): array
{
    $db = \Eshcol\Ats\App::get()->db;
    $cities = [];
    foreach ($db->all("SELECT DISTINCT SUBSTRING_INDEX(area, '|', 1) AS city, canonical FROM skills_dictionary WHERE category = 'locality' ORDER BY canonical") as $r) {
        $cities[(string) $r['city']][] = (string) $r['canonical'];
    }
    $alias = [];
    foreach (array_keys($cities) as $c) {
        $alias[mb_strtolower($c)] = $c;
    }
    foreach ($db->all("SELECT term, canonical FROM skills_dictionary WHERE category = 'location'") as $r) {
        if (isset($cities[$r['canonical']])) {
            $alias[mb_strtolower((string) $r['term'])] = (string) $r['canonical'];
        }
    }
    return ['cities' => $cities, 'alias' => $alias];
}

/**
 * The city whose localities to offer, from what's in the City box: "Chennai", "chennai, tamil nadu", "Madras",
 * "Pimprala, Jalgaon" (each comma part is tried, last first). '' when none has localities. Pure.
 */
function locality_city(string $box, array $map): string
{
    $parts = array_reverse(array_filter(array_map('trim', explode(',', mb_strtolower($box)))));
    array_unshift($parts, mb_strtolower(trim($box)));
    foreach ($parts as $p) {
        if (isset($map['alias'][$p])) {
            return $map['alias'][$p];
        }
    }
    return '';
}

/** "Kavitha Raman" → "KR" (for avatar circles). */
function initials(string $name): string
{
    $parts = preg_split('/\s+/', trim($name), -1, PREG_SPLIT_NO_EMPTY);
    if (!$parts) {
        return '?';
    }
    $first = mb_substr($parts[0], 0, 1);
    $last = count($parts) > 1 ? mb_substr(end($parts), 0, 1) : '';
    return mb_strtoupper($first . $last);
}
/** A multi-line address on one line: "Office 301,\nArchway" → "Office 301, Archway". */
function one_line(?string $s): string
{
    return trim((string) preg_replace('/\s*,?\s*\R\s*/u', ', ', trim((string) $s)));
}

/** An icon from views/partials/icons.php: icon('users'). Decorative, so hidden from screen readers. */
function icon(string $name, string $class = ''): string
{
    return '<svg class="i' . ($class !== '' ? ' ' . htmlspecialchars($class, ENT_QUOTES) : '') . '" aria-hidden="true" focusable="false"><use href="#i-' . htmlspecialchars($name, ENT_QUOTES) . '"/></svg>';
}

/**
 * A client's logo, or their initials in a tile when there is none.
 * $src lets public pages pass their own token-checked address.
 */
function client_logo(array $c, string $size = 'md', ?string $src = null): string
{
    $name = (string) ($c['client'] ?? $c['name'] ?? $c['client_name'] ?? '');
    $id = (int) ($c['client_id'] ?? $c['id'] ?? 0);
    if (!empty($c['logo_path'])) {
        $url = $src ?? '/clients/' . $id . '/logo?v=' . (strtotime((string) ($c['logo_updated_at'] ?? '')) ?: 1);
        return '<span class="clogo ' . $size . '"><img src="' . htmlspecialchars($url, ENT_QUOTES) . '" alt="' . htmlspecialchars($name, ENT_QUOTES) . ' logo"></span>';
    }
    return $src !== null ? '' : '<span class="clogo ' . $size . ' none" style="--h:' . avatar_hue($name) . '" aria-hidden="true">' . htmlspecialchars(initials($name), ENT_QUOTES) . '</span>';
}

/** A soft colour for a person's avatar, the same every time for the same name. */
function avatar_hue(string $name): int
{
    return [262, 280, 300, 230, 200, 170, 25, 340][crc32(mb_strtolower(trim($name))) % 8];
}

/** Work mode names: stored value => what candidates see next to the title. */
const WORK_MODES = ['onsite' => 'On-site', 'hybrid' => 'Hybrid Work', 'remote' => 'Remote Work'];

/** "Accounting Manager (Hybrid Work)", or just the title when no work mode is set. */
function role_title_mode(string $title, ?string $mode): string
{
    return isset(WORK_MODES[$mode ?? '']) ? $title . ' (' . WORK_MODES[$mode] . ')' : $title;
}

/** Where a candidate came from: stored value => label. */
/** Rupees per US dollar, for showing Claude's costs (one figure for the whole app, 14 Nov 2026). */
const INR_PER_USD = 94.0;

const CHANNELS = ['naukri' => 'Naukri', 'linkedin' => 'LinkedIn', 'indeed' => 'Indeed', 'foundit' => 'Foundit', 'apna' => 'Apna',
    'website' => 'Eshcol website', 'whatsapp' => 'WhatsApp', 'referral' => 'Referral', 'walk_in' => 'Walk-in',
    'email' => 'Emailed to resume@', 'database' => 'Our database', 'other' => 'Other'];

/** The label for a channel, or "Not recorded". */
function channel_name(?string $c): string
{
    return CHANNELS[$c ?? ''] ?? 'Not recorded';
}

/** Common reasons a candidate is not taken forward (offered as suggestions; you can type your own). */
const STAGE_REASONS = ['Insufficient information in the CV or answers', 'Qualification below the requirement', 'Experience outside the range', 'Salary above the budget',
    'Notice period too long', 'Lives too far / cannot work there', 'Skills do not match', 'Not a stable work history',
    'Rejected by the client', 'Not interested / withdrew', 'Did not respond', 'Duplicate profile'];

/** "today", "yesterday", "5 days ago", "3 weeks ago", or the date when older than two months (board cards, 14 Nov 2026). */
function ago(?string $dt): string
{
    if (!$dt) {
        return '';
    }
    $d = (int) floor((strtotime(date('Y-m-d')) - strtotime(date('Y-m-d', strtotime($dt)))) / 86400);
    return match (true) {
        $d <= 0 => 'today',
        $d === 1 => 'yesterday',
        $d < 14 => "$d days ago",
        $d < 60 => intdiv($d, 7) . ' weeks ago',
        default => date('j M Y', strtotime($dt)),
    };
}
