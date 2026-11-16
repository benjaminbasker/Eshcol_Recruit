<?php
declare(strict_types=1);

namespace Eshcol\Ats\Services;

use Eshcol\Ats\App;
use Eshcol\Ats\Audit;

/**
 * Claude Sonnet reads every CV (Settings › AI reading). Chosen on 7 Nov 2026 after the reader
 * test: 99% of details right, against 65% for the free reader.
 *
 * - New CVs (upload or the resume@ inbox) are queued and read by the five-minute job, then
 *   applied straight away ("auto"). Uploads stay fast; the thank-you email, ten minutes later,
 *   already has the right address.
 * - Existing CVs can all be re-read from the database ("review"): nothing changes until the
 *   owner approves the list of changes.
 * - Scans and photos: Claude also writes out the CV's text, so they are searched, scored and
 *   reported like any other CV (no more "Read scanned CV").
 * - Never overwrites what you confirmed, what the candidate gave in the screening questions,
 *   or an email address the CV arrived from. Never takes an email or phone already used by
 *   another candidate.
 * - Where Claude finds nothing, a guess the free reader made (a city, a salary, experience)
 *   is cleared, because the test showed those guesses were the main source of errors.
 * - The free reader still runs first, as the fallback when Claude is unreachable.
 * - A monthly limit, on top of the spending limit in the Claude Console.
 */
final class AiReading
{
    public const READER = 'sonnet';
    /** Details Claude reads, in the order shown for approval. */
    public const FIELDS = [
        'full_name' => 'Name', 'email' => 'Email', 'phone' => 'Phone', 'linkedin_url' => 'LinkedIn', 'current_title' => 'Current position',
        'current_company' => 'Current employer', 'location' => 'City', 'locality' => 'Locality',
        'qualifications' => 'Qualification', 'exp_months' => 'Total experience', 'notice_days' => 'Notice (days)',
        'ctc_current_lpa' => 'Current CTC (LPA)', 'ctc_expected_lpa' => 'Expected CTC (LPA)',
    ];
    /** Details that describe the CV itself: set from each new reading, not offered for approval. */
    public const EXTRAS = ['software', 'languages', 'employer_type', 'pin_code', 'serving_notice', 'last_working_day'];
    /** Who said it: never changed by a reader. */
    private const PROTECTED = ['you', 'candidate'];
    /** A reader's guess here is cleared when Claude finds nothing in the CV. */
    private const CLEARABLE = ['current_title', 'location', 'locality', 'exp_months', 'notice_days', 'ctc_current_lpa', 'ctc_expected_lpa'];
    /** Field sources that came from a reader (the free one, a scan, a file name, an earlier AI note). */
    private const READER_SOURCES = ['cv', 'estimated', 'filename', 'ocr', 'ai', ''];

    private ?Skills $skills = null;

    public function __construct(private readonly App $app, private readonly ?CvReader $reader = null)
    {
    }

    public static function enabled(App $app): bool
    {
        // The setting only exists once update 022 is applied, so nothing is queued before its table exists.
        return CvReader::available($app) && $app->setting('ai_read_enabled', '0') === '1';
    }

    public function limit(): int
    {
        return max(0, (int) $this->app->setting('ai_read_monthly_limit', '600'));
    }

    public function usedThisMonth(): int
    {
        return (int) $this->app->setting('ai_read_count_' . date('Ym'), '0');
    }

    public function costThisMonth(): float
    {
        return (float) $this->app->setting('ai_read_cost_' . date('Ym'), '0');
    }

    /** A new CV: read it soon and apply what Claude finds. */
    public function queue(int $cvFileId, int $candidateId): void
    {
        $this->app->db->run("INSERT IGNORE INTO cv_ai_reads (cv_file_id, candidate_id, mode, status) VALUES (?, ?, 'auto', 'pending')", [$cvFileId, $candidateId]);
    }

    /** Every candidate's latest CV, read again; changes wait for approval. Returns how many. */
    public function queueAll(): int
    {
        $db = $this->app->db;
        $n = 0;
        foreach ($db->all("SELECT c.id, (SELECT MAX(id) FROM cv_files WHERE candidate_id = c.id) AS fid FROM candidates c
                WHERE " . Contact::sql('c')) as $r) {
            if (!$r['fid']) {
                continue;
            }
            $db->run("INSERT INTO cv_ai_reads (cv_file_id, candidate_id, mode, status) VALUES (?, ?, 'review', 'pending')
                ON DUPLICATE KEY UPDATE mode = 'review', status = 'pending', error = NULL, applied_at = NULL, applied_fields = NULL, updated_at = NOW()",
                [$r['fid'], $r['id']]);
            $n++;
        }
        Audit::log('ai_read_all_queued', null, null, ['cvs' => $n]);
        return $n;
    }

    /** @return array{pending:int, reading:int, failed:int, review:int} */
    public function counts(): array
    {
        $db = $this->app->db;
        return [
            'pending' => (int) $db->col("SELECT COUNT(*) FROM cv_ai_reads WHERE status = 'pending'"),
            'reading' => (int) $db->col("SELECT COUNT(*) FROM cv_ai_reads WHERE status = 'reading'"),
            'failed' => (int) $db->col("SELECT COUNT(*) FROM cv_ai_reads WHERE status = 'failed'"),
            'review' => (int) $db->col("SELECT COUNT(*) FROM cv_ai_reads WHERE status = 'done' AND mode = 'review' AND applied_at IS NULL"),
        ];
    }

    /**
     * Reads the next CV in the queue. Returns its row id, or null when the queue is empty.
     * @throws \RuntimeException "busy:" (try later) or "stop:" (key, credit or monthly limit)
     */
    public function processNext(): ?int
    {
        $db = $this->app->db;
        // A read cut off half-way (server restart) goes back in the queue.
        $db->run("UPDATE cv_ai_reads SET status = 'pending' WHERE status = 'reading' AND updated_at < NOW() - INTERVAL 10 MINUTE");
        $row = $db->one("SELECT * FROM cv_ai_reads WHERE status = 'pending' ORDER BY mode = 'auto' DESC, id LIMIT 1");
        if (!$row) {
            return null;
        }
        if ($db->run("UPDATE cv_ai_reads SET status = 'reading', updated_at = NOW() WHERE id = ? AND status = 'pending'", [$row['id']])->rowCount() === 0) {
            return (int) $row['id']; // another run took it
        }
        $id = (int) $row['id'];
        if ($this->usedThisMonth() >= $this->limit()) {
            $db->run("UPDATE cv_ai_reads SET status = 'pending' WHERE id = ?", [$id]);
            throw new \RuntimeException('stop:This month\'s limit of ' . $this->limit() . ' CV readings is reached. Raise it in Settings › AI reading.');
        }
        // Audit C1 (16 Nov 2026): read exactly the CV this job was queued for. When a newer CV of theirs is already
        // read or waiting to be read, this older one is skipped: no second charge, and it can't overwrite newer details.
        $newer = $row['cv_file_id'] ? $db->col("SELECT 1 FROM cv_ai_reads WHERE candidate_id = ? AND cv_file_id > ? AND status IN ('pending', 'reading', 'done') LIMIT 1",
            [$row['candidate_id'], $row['cv_file_id']]) : false;
        if ($newer) {
            $db->update('cv_ai_reads', ['status' => 'failed', 'error' => 'Not read: a newer CV from them is read instead.', 'updated_at' => date('Y-m-d H:i:s')], $id);
            return $id;
        }
        $file = $row['cv_file_id']
            ? $db->one('SELECT * FROM cv_files WHERE id = ? AND candidate_id = ?', [$row['cv_file_id'], $row['candidate_id']])
            : $db->one('SELECT * FROM cv_files WHERE candidate_id = ? ORDER BY id DESC LIMIT 1', [$row['candidate_id']]);
        if (!$file) {
            $db->update('cv_ai_reads', ['status' => 'failed', 'error' => 'That CV file is no longer on record.', 'updated_at' => date('Y-m-d H:i:s')], $id);
            return $id;
        }
        $letters = preg_match_all('/\p{L}/u', (string) $file['text']);
        $transcribe = in_array($file['ext'], ['pdf', 'jpg', 'jpeg', 'png'], true) && ($file['text_status'] !== 'ok' || $letters < 200);
        $reader = $this->readerFor($row, $transcribe);
        try {
            $r = ($this->reader ?? new CvReader($this->app))->read((int) $row['candidate_id'], $reader, $transcribe, (int) $file['id']);
        } catch (\RuntimeException $e) {
            if (preg_match('/^(busy|stop):/', $e->getMessage())) {
                $db->run("UPDATE cv_ai_reads SET status = 'pending' WHERE id = ?", [$id]);
                throw $e;
            }
            $db->update('cv_ai_reads', ['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 300), 'updated_at' => date('Y-m-d H:i:s')], $id);
            return $id;
        }
        $this->count($r['cost_usd']);
        $db->update('cv_ai_reads', ['status' => 'done', 'model' => $r['model'], 'fields' => json_encode($r['fields'], JSON_UNESCAPED_UNICODE),
            'raw' => json_encode($r['raw'], JSON_UNESCAPED_UNICODE), 'transcribed' => $r['cv_text'] !== null ? 1 : 0,
            'tokens_in' => $r['tokens_in'], 'tokens_out' => $r['tokens_out'], 'cost_usd' => $r['cost_usd'], 'ms' => $r['ms'],
            'error' => null, 'updated_at' => date('Y-m-d H:i:s')], $id);
        if ($r['cv_text'] !== null && mb_strlen($r['cv_text']) >= 100) {
            $db->update('cv_files', ['text' => mb_substr($r['cv_text'], 0, 200000), 'text_status' => 'ok', 'text_source' => 'ai'], (int) $r['cv_file_id']);
        }
        if ($row['mode'] === 'auto') {
            $this->apply($id, null);
        }
        return $id;
    }

    /**
     * Which Claude reads this CV (8 Nov 2026). Sonnet for a CV relevant to an open client role (by the
     * free check), for a scan (the free check can't judge it), and for re-reads the owner asked for.
     * Claude Haiku, about 20 times cheaper, for new CVs that fit no open role; if one later does,
     * Sonnet reads it again then (fullReadFirst).
     */
    public function readerFor(array $row, bool $transcribe): string
    {
        if ($row['mode'] !== 'auto' || $transcribe || $this->app->setting('ai_read_cheap', '0') !== '1') {
            return self::READER;
        }
        return (new FitAssessor($this->app))->relevantToOpenRole((int) $row['candidate_id']) ? self::READER : 'haiku';
    }

    /**
     * Before Claude assesses a candidate: true while their CV reading is on its way, and when Haiku
     * read it, Sonnet is asked to read it again (the assessment follows that reading).
     */
    public function fullReadFirst(int $candidateId): bool
    {
        $db = $this->app->db;
        $r = $db->one('SELECT id, status, mode, model FROM cv_ai_reads WHERE candidate_id = ? ORDER BY id DESC LIMIT 1', [$candidateId]);
        if (!$r) {
            return false;
        }
        if ($r['mode'] === 'auto' && in_array($r['status'], ['pending', 'reading'], true)) {
            return true;
        }
        if ($r['status'] === 'done' && str_contains((string) $r['model'], 'haiku') && $this->app->setting('ai_read_enabled', '0') === '1') {
            $db->run("UPDATE cv_ai_reads SET status = 'pending', mode = 'auto', error = NULL, applied_at = NULL, applied_fields = NULL, updated_at = NOW() WHERE id = ?", [$r['id']]);
            Audit::log('cv_ai_read_upgraded', 'candidate', $candidateId, ['from' => $r['model']]);
            return true;
        }
        return false;
    }

    /** Reads until the queue is empty, $max CVs are done, or $seconds have passed. For the five-minute job. */
    public function runBatch(int $max = 40, int $seconds = 150): string
    {
        if (!self::enabled($this->app) && !$this->reader) {
            return '';
        }
        $t0 = time();
        $done = 0;
        try {
            while ($done < $max && time() - $t0 < $seconds && $this->processNext() !== null) {
                $done++;
            }
        } catch (\RuntimeException $e) {
            return "$done CV(s) read; paused: " . preg_replace('/^(busy|stop):\s*/', '', $e->getMessage());
        }
        return $done ? "$done CV(s) read by Claude" : '';
    }

    /**
     * What Claude's reading would change for one candidate.
     * @return array<string, array{label:string, old:string, new:string, old_source:string}>
     */
    public function proposals(int $readId): array
    {
        $db = $this->app->db;
        $read = $db->one('SELECT * FROM cv_ai_reads WHERE id = ?', [$readId]);
        $c = $read ? $db->one('SELECT * FROM candidates WHERE id = ?', [$read['candidate_id']]) : null;
        if (!$read || !$c || $read['status'] !== 'done') {
            return [];
        }
        $new = $this->clean(json_decode((string) $read['fields'], true) ?: []);
        $src = json_decode((string) ($c['field_sources'] ?? '{}'), true) ?: [];
        $out = [];
        foreach (self::FIELDS as $k => $label) {
            $s = (string) ($src[$k] ?? '');
            $old = $c[$k] ?? null;
            $nv = $new[$k] ?? null;
            if (in_array($s, self::PROTECTED, true) || ($k === 'email' && $s === 'email')) {
                continue;
            }
            $oldEmpty = $old === null || $old === '';
            if ($nv === null || $nv === '') {
                // Claude found nothing: clear a guess the free reader made.
                if (!$oldEmpty && in_array($k, self::CLEARABLE, true) && in_array($s, self::READER_SOURCES, true)) {
                    $out[$k] = ['label' => $label, 'old' => $this->show($k, $old), 'new' => '', 'old_source' => $s];
                }
                continue;
            }
            if (!$oldEmpty && $this->same($k, $old, $nv)) {
                continue;
            }
            // A shorter qualification line that only drops detail ("MBA Finance (pursuing)" → "MBA",
            // "CA Inter (appeared)" left out) is not an improvement: the fuller one stays.
            if ($k === 'qualifications' && !$oldEmpty && self::onlyDropsDetail((string) $old, (string) $nv)) {
                continue;
            }
            // A locality the app knows (it drives the distance to the client's office) is not
            // swapped for a smaller area it doesn't know ("Dhankawadi" stays, not "Taljai Pathar").
            if ($k === 'locality' && !$oldEmpty && $this->skills()->localityOf((string) $old) && !$this->skills()->localityOf((string) $nv)) {
                continue;
            }
            if ($k === 'email' && $db->col('SELECT id FROM candidates WHERE email_norm = ? AND id <> ?', [mb_strtolower((string) $nv), $c['id']])) {
                continue;
            }
            if ($k === 'phone' && $db->col('SELECT id FROM candidates WHERE phone_norm = ? AND id <> ?', [FieldExtractor::normalisePhone((string) $nv), $c['id']])) {
                continue;
            }
            $out[$k] = ['label' => $label, 'old' => $oldEmpty ? '' : $this->show($k, $old), 'new' => $this->show($k, $nv), 'old_source' => $s];
        }
        return $out;
    }

    /**
     * Applies Claude's reading: every proposed change ($fields null) or only the ones listed.
     * Also refreshes the experience cross-check, adds skills and job titles, and re-scores roles.
     */
    public function apply(int $readId, ?array $fields): array
    {
        $db = $this->app->db;
        $read = $db->one('SELECT * FROM cv_ai_reads WHERE id = ?', [$readId]);
        if (!$read || $read['status'] !== 'done') {
            return [];
        }
        $cid = (int) $read['candidate_id'];
        $c = $db->one('SELECT * FROM candidates WHERE id = ?', [$cid]);
        if (!$c) {
            return [];
        }
        $props = $this->proposals($readId);
        $take = $fields === null ? array_keys($props) : array_values(array_intersect(array_keys($props), $fields));
        // Haiku's reading never replaces a name, email or phone the app already has (it is less sure of them).
        if (str_contains((string) $read['model'], 'haiku')) {
            // ...except a doubtful name the free reader picked up (a heading such as "Good Communication"), when
            // Haiku's name is believable: the file name is the witness (14 Nov 2026).
            $nameFix = $this->nameFix($read, $c);
            $take = array_values(array_filter($take, fn ($k) => !in_array($k, ['full_name', 'email', 'phone'], true) || $c[$k] === null || $c[$k] === ''
                || ($k === 'full_name' && $nameFix !== null)));
        }
        $new = $this->clean(json_decode((string) $read['fields'], true) ?: []);
        $src = json_decode((string) ($c['field_sources'] ?? '{}'), true) ?: [];
        $set = [];
        foreach ($take as $k) {
            $v = $new[$k] ?? null;
            $set[$k] = $v === '' ? null : $v;
            if ($v === null || $v === '') {
                unset($src[$k]);
            } else {
                $src[$k] = 'ai';
            }
        }
        if (array_key_exists('full_name', $set) && $set['full_name']) {
            $set['first_name'] = explode(' ', $set['full_name'])[0];
        }
        if (array_key_exists('email', $set)) {
            $set['email_norm'] = $set['email'] ? mb_strtolower($set['email']) : null;
        }
        if (array_key_exists('phone', $set)) {
            $set['phone_norm'] = $set['phone'] ? FieldExtractor::normalisePhone($set['phone']) : null;
        }
        $raw = json_decode((string) $read['raw'], true) ?: [];
        // A CV can't be older than the start of the job it lists as current. Stops "9+ years" with
        // a job since last year being called an old CV (see ExperienceCheck).
        $set['cv_not_before'] = self::currentJobStart($raw);
        if (array_key_exists('exp_months', $set)) {
            // A stated total dates from when the CV was written; a total from the dates is as of today.
            $set['exp_as_of'] = $set['exp_months'] === null ? null : (isset($new['exp_stated_months']) && $c['cv_dated'] ? $c['cv_dated'] : date('Y-m-d'));
        }
        // The cross-check follows Claude's reading of the latest CV (stated total, dates without internships).
        $set['exp_stated_months'] = $new['exp_stated_months'] ?? null;
        $set['exp_dates_months'] = $new['exp_dates_months'] ?? null;
        $set['cv_has_current_job'] = (int) (bool) array_filter((array) ($raw['jobs'] ?? []), fn ($j) => preg_match('/present|current|till|now/i', (string) ($j['end'] ?? '')));
        // Details that describe the latest CV: software, languages, employer type, PIN, serving notice.
        // Only from a reading that asked for them (older readings leave what is there alone).
        if (array_key_exists('employer_type', $raw)) {
            foreach (self::EXTRAS as $k) {
                $set[$k] = $new[$k] ?? null;
            }
        }
        $set['field_sources'] = json_encode($src);
        $db->update('candidates', $set, $cid);
        $db->update('cv_ai_reads', ['applied_at' => date('Y-m-d H:i:s'), 'applied_fields' => mb_substr(implode(',', $take), 0, 400)], $readId);

        // Skills and job titles: from the CV text (now including a scan Claude wrote out) and Claude's job list.
        $skills = $this->skills();
        $text = (string) $db->col('SELECT text FROM cv_files WHERE id = ?', [$read['cv_file_id']]);
        foreach (array_keys($skills->find($text, 'skill')) as $s) {
            $db->run('INSERT IGNORE INTO candidate_skills (candidate_id, skill) VALUES (?, ?)', [$cid, $s]);
        }
        $titles = implode("\n", array_map(fn ($j) => (string) ($j['title'] ?? ''), (array) ($raw['jobs'] ?? [])));
        foreach (array_keys($skills->find($titles . "\n" . $text, 'designation')) as $t) {
            $db->run('INSERT IGNORE INTO candidate_titles (candidate_id, title) VALUES (?, ?)', [$cid, $t]);
        }
        // The dictionary learns: titles and software it doesn't know yet wait in Settings › New words.
        (new NewTerms($this->app))->collect($cid, $raw, $skills);
        $imp = new CandidateImporter($this->app);
        foreach ($db->all('SELECT id FROM applications WHERE candidate_id = ?', [$cid]) as $a) {
            $imp->scoreApplication((int) $a['id']);
        }
        // A new CV read properly may fit other open roles than the free reader's guess did.
        if ($read['mode'] === 'auto') {
            $uid = (int) ($db->col('SELECT uploaded_by FROM cv_files WHERE id = ?', [$read['cv_file_id']]) ?: $db->col("SELECT id FROM users WHERE role = 'owner' ORDER BY id LIMIT 1"));
            if ($uid) {
                (new RoleRouter($this->app))->route($cid, null, $uid);
            }
        }
        Audit::log('cv_ai_read_applied', 'candidate', $cid, ['fields' => $take, 'mode' => $read['mode']]);
        // Haiku read it as fitting no open role, but the proper reading shows it does: Sonnet reads it now.
        if (str_contains((string) $read['model'], 'haiku') && (new FitAssessor($this->app))->relevantToOpenRole($cid)) {
            $this->fullReadFirst($cid);
            return $take;
        }
        // Now that the CV is read properly, Claude assesses them for the roles they are relevant to.
        if (FitAssessor::enabled($this->app)) {
            (new FitAssessor($this->app))->queueForCandidate($cid);
        }
        return $take;
    }

    /** The start (Y-m-01) of the latest job Claude read as current, or null. */
    public static function currentJobStart(array $raw): ?string
    {
        $best = null;
        foreach ((array) ($raw['jobs'] ?? []) as $j) {
            if (!empty($j['internship']) || !preg_match('/present|current|till|now/i', (string) ($j['end'] ?? ''))) {
                continue;
            }
            if (preg_match('/^(\d{4})(?:-(\d{1,2}))?$/', trim((string) ($j['start'] ?? '')), $m)) {
                $d = sprintf('%04d-%02d-01', $m[1], (int) ($m[2] ?? 1) ?: 1);
                if ($d <= date('Y-m-d') && ($best === null || $d > $best)) {
                    $best = $d;
                }
            }
        }
        return $best;
    }

    /** Once, for CVs Claude read before this rule existed: the earliest each CV can have been written. */
    public function backfillNotBefore(): int
    {
        $db = $this->app->db;
        $n = 0;
        foreach ($db->all("SELECT c.id, r.raw FROM candidates c
                JOIN cv_ai_reads r ON r.candidate_id = c.id AND r.status = 'done' AND r.applied_at IS NOT NULL
                WHERE c.cv_not_before IS NULL") as $r) {
            if ($since = self::currentJobStart(json_decode((string) $r['raw'], true) ?: [])) {
                $db->update('candidates', ['cv_not_before' => $since], (int) $r['id']);
                $n++;
            }
        }
        return $n;
    }

    /** Leaves a reading without changing anything (the owner unticked everything). */
    public function dismiss(int $readId): void
    {
        $this->app->db->update('cv_ai_reads', ['applied_at' => date('Y-m-d H:i:s'), 'applied_fields' => ''], $readId);
    }

    /**
     * Claude's name for this candidate, when the one we have is doubtful (a CV heading, a job title, an address, or
     * nothing like the file name), came from the free reader (never one you or the candidate confirmed), and
     * Claude's is believable. Null otherwise.
     */
    public function nameFix(array $read, array $c): ?string
    {
        $src = json_decode((string) ($c['field_sources'] ?? '{}'), true) ?: [];
        if (!in_array((string) ($src['full_name'] ?? ''), self::READER_SOURCES, true)) {
            return null;
        }
        $new = $this->clean(json_decode((string) $read['fields'], true) ?: [])['full_name'] ?? null;
        $file = $read['cv_file_id'] ? (string) $this->app->db->col('SELECT original_name FROM cv_files WHERE id = ?', [$read['cv_file_id']]) : null;
        if (!$new || mb_strtolower(trim($new)) === mb_strtolower(trim((string) $c['full_name']))) {
            return null;
        }
        return NameCheck::doubtful($c['full_name'], $file) && !NameCheck::doubtful($new, $file) ? $new : null;
    }

    /**
     * "Names to check" (Settings › AI reading): every candidate whose name is doubtful and whose latest Claude
     * reading has a believable one. Nothing changes until you approve. Also those doubtful with no suggestion.
     * @return array{fixes: array, unsure: array}
     */
    public function nameChecks(): array
    {
        $db = $this->app->db;
        $fixes = $unsure = [];
        foreach ($db->all("SELECT c.id, c.full_name, c.field_sources, (SELECT f.original_name FROM cv_files f WHERE f.candidate_id = c.id ORDER BY f.id DESC LIMIT 1) AS file
                FROM candidates c WHERE c.removal_requested_at IS NULL") as $c) {
            if (!NameCheck::doubtful($c['full_name'], $c['file'])) {
                continue;
            }
            $src = json_decode((string) ($c['field_sources'] ?? '{}'), true) ?: [];
            if (!in_array((string) ($src['full_name'] ?? ''), self::READER_SOURCES, true)) {
                continue; // you or the candidate confirmed it
            }
            $read = $db->one("SELECT * FROM cv_ai_reads WHERE candidate_id = ? AND status = 'done' ORDER BY id DESC LIMIT 1", [$c['id']]);
            $fix = $read ? $this->nameFix($read, $c) : null;
            $row = ['candidate_id' => (int) $c['id'], 'name' => (string) $c['full_name'], 'file' => (string) $c['file'], 'suggested' => $fix, 'read_id' => $read ? (int) $read['id'] : null];
            $fix !== null ? $fixes[] = $row : $unsure[] = $row;
        }
        return ['fixes' => $fixes, 'unsure' => $unsure];
    }

    /** Applies the names you ticked (only ones still on the list). Returns how many changed. */
    public function applyNames(array $candidateIds, int $userId): int
    {
        $db = $this->app->db;
        $want = array_flip(array_map('intval', $candidateIds));
        $n = 0;
        foreach ($this->nameChecks()['fixes'] as $f) {
            if (!isset($want[$f['candidate_id']])) {
                continue;
            }
            $c = $db->one('SELECT field_sources FROM candidates WHERE id = ?', [$f['candidate_id']]);
            $src = json_decode((string) ($c['field_sources'] ?? '{}'), true) ?: [];
            $src['full_name'] = 'ai';
            $db->update('candidates', ['full_name' => $f['suggested'], 'first_name' => explode(' ', $f['suggested'])[0], 'field_sources' => json_encode($src)], $f['candidate_id']);
            Audit::log('name_corrected', 'candidate', $f['candidate_id'], ['by' => $userId, 'via' => 'names to check']);
            $n++;
        }
        return $n;
    }

    /** Readings waiting for approval, with their proposed changes; those with none are closed. */
    public function waitingForApproval(int $limit = 500): array
    {
        $db = $this->app->db;
        $out = [];
        foreach ($db->all("SELECT r.id, r.candidate_id, c.full_name FROM cv_ai_reads r JOIN candidates c ON c.id = r.candidate_id
                WHERE r.status = 'done' AND r.mode = 'review' AND r.applied_at IS NULL ORDER BY r.candidate_id LIMIT " . (int) $limit) as $r) {
            $p = $this->proposals((int) $r['id']);
            if (!$p) {
                // Nothing to change in the details, but skills, titles and the cross-check still follow the new reading.
                $this->apply((int) $r['id'], []);
                continue;
            }
            $out[] = ['read_id' => (int) $r['id'], 'candidate_id' => (int) $r['candidate_id'], 'name' => $r['full_name'], 'changes' => $p];
        }
        return $out;
    }

    /**
     * The work history as Claude read it, in the shape CvReport uses, or null if no reading.
     * Internships and articleship are kept but treated like courses (not counted as work).
     */
    public function history(int $candidateId): ?array
    {
        $raw = $this->app->db->col("SELECT r.raw FROM cv_ai_reads r WHERE r.candidate_id = ? AND r.status = 'done'
            AND r.cv_file_id = (SELECT MAX(id) FROM cv_files WHERE candidate_id = ?) LIMIT 1", [$candidateId, $candidateId]);
        $jobs = $raw ? (json_decode((string) $raw, true)['jobs'] ?? null) : null;
        if (!is_array($jobs)) {
            return null;
        }
        $now = (int) date('Y') * 12 + (int) date('n');
        $out = [];
        foreach ($jobs as $j) {
            $ym = function (string $v, bool $end) use ($now): array {
                $v = mb_strtolower(trim($v));
                if (preg_match('/present|current|till|now|date/', $v)) {
                    return [$now, true, true];
                }
                if (preg_match('/^(\d{4})-(\d{1,2})$/', $v, $m)) {
                    return [(int) $m[1] * 12 + (int) $m[2], true, false];
                }
                if (preg_match('/^(\d{4})$/', $v, $m)) {
                    return [(int) $m[1] * 12 + ($end ? 12 : 1), false, false];
                }
                return [null, false, false];
            };
            [$s, $sx] = $ym((string) ($j['start'] ?? ''), false);
            [$e, $ex, $current] = $ym((string) ($j['end'] ?? ''), true);
            if (!$s || !$e || $e < $s) {
                continue;
            }
            $label = trim(implode(', ', array_filter([trim((string) ($j['title'] ?? '')), trim((string) ($j['company'] ?? ''))])));
            $intern = !empty($j['internship']);
            $out[] = ['label' => mb_strimwidth(($label ?: 'Untitled') . ($intern ? ' (internship)' : ''), 0, 90, '…'),
                'from' => $this->ymLabel($s, $sx), 'to' => $current ? 'Present' : $this->ymLabel($e, $ex), 'start' => $s, 'end' => $e,
                'current' => $current, 'exact' => $sx && ($ex || $current), 'education' => $intern, 'future' => false, 'break' => false];
        }
        usort($out, fn ($a, $b) => $b['start'] <=> $a['start']);
        return $out;
    }

    /**
     * Education as Claude read it from the latest CV: [degree, institute, year, status], or null
     * when the CV was read before education was asked for (a re-read fills it in).
     */
    public function education(int $candidateId): ?array
    {
        $raw = $this->app->db->col("SELECT r.raw FROM cv_ai_reads r WHERE r.candidate_id = ? AND r.status = 'done'
            AND r.cv_file_id = (SELECT MAX(id) FROM cv_files WHERE candidate_id = ?) LIMIT 1", [$candidateId, $candidateId]);
        $edu = $raw ? (json_decode((string) $raw, true)['education'] ?? null) : null;
        if (!is_array($edu)) {
            return null;
        }
        return array_values(array_filter(array_map(fn ($e) => [
            'degree' => trim((string) ($e['degree'] ?? '')), 'institute' => trim((string) ($e['institute'] ?? '')),
            'year' => trim((string) ($e['year'] ?? '')), 'status' => trim((string) ($e['status'] ?? '')),
        ], $edu), fn ($e) => $e['degree'] !== ''));
    }

    /** Claude's answer, tidied: one email and phone, no "Mr."/"Dr.", no dates on qualifications, places named as the dictionary names them. */
    public function clean(array $f): array
    {
        $out = $f;
        if (isset($f['full_name'])) {
            $n = trim(preg_replace('/^(?:(?:mr|mrs|ms|miss|dr|ca|cs|shri|smt|kum|kumari|er|adv)\.?\s+)+/i', '', (string) $f['full_name']));
            $n = preg_replace('/\s+/', ' ', $n);
            if ($n !== '' && !preg_match('/\p{Ll}/u', $n)) {
                $n = mb_convert_case(mb_strtolower($n), MB_CASE_TITLE, 'UTF-8');
            }
            $out['full_name'] = $n !== '' && FieldExtractor::looksLikeName($n) ? $n : null;
        }
        if (isset($f['email'])) {
            $out['email'] = preg_match('/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/', (string) $f['email'], $m) ? mb_strtolower($m[0]) : null;
        }
        if (isset($f['phone'])) {
            $first = trim(preg_split('#\s*(?:/|,|;|\bor\b|\|)\s*#i', (string) $f['phone'])[0] ?? '');
            $out['phone'] = $first !== '' && FieldExtractor::normalisePhone($first) ? $first : null;
        }
        if (isset($f['linkedin_url'])) {
            $out['linkedin_url'] = FieldExtractor::linkedin((string) $f['linkedin_url']);
        }
        if (isset($f['current_title'])) {
            $out['current_title'] = $this->unshout((string) $f['current_title']);
        }
        if (isset($f['qualifications'])) {
            $items = array_map(fn ($q) => trim(preg_replace('/\s*[-–—]\s*(?:expected|pursuing|appeared|pass(?:ed)?|completed|(?:jan|feb|mar|apr|may|jun|jul|aug|sep|oct|nov|dec)[a-z]*\.?\s*\d{4}|\d{4}).*$/iu', '', $q)),
                explode(',', (string) $f['qualifications']));
            $out['qualifications'] = implode(', ', array_filter($items));
        }
        $skills = $this->skills();
        if (!empty($f['locality'])) {
            $hit = array_key_first($skills->find((string) $f['locality'], 'locality'));
            $out['locality'] = $hit ?: $this->unshout((string) $f['locality']);
            if (empty($f['location']) && $hit && ($info = $skills->localityOf($hit))) {
                $out['location'] = $info['city'];
            }
        }
        if (!empty($out['location'])) {
            $city = $this->unshout((string) $out['location']);
            // A suburb given as the city ("Chinchwad"): the city is Pune and the suburb is the locality.
            if (($info = $skills->localityOf($city)) && $skills->canonical($city, 'location') === $city) {
                $city = $info['city'];
                if (empty($out['locality']) || !$skills->localityOf((string) $out['locality'])) {
                    $out['locality'] = $info['locality'];
                }
            }
            $out['location'] = $skills->canonical($city, 'location');
        }
        return array_map(fn ($v) => is_string($v) ? trim($v) : $v, $out);
    }

    /** True when every word of $new is already in $old ("B.Com" counts as "BCom"): $new adds nothing. */
    public static function onlyDropsDetail(string $old, string $new): bool
    {
        $words = fn (string $s) => array_filter(preg_split('/[^a-z0-9]+/', str_replace('.', '', mb_strtolower($s))));
        $n = $words($new);
        return $n !== [] && array_diff($n, $words($old)) === [];
    }

    private function unshout(string $s): string
    {
        $s = trim($s);
        if (mb_strlen($s) < 6 || preg_match('/\p{Ll}/u', $s)) {
            return $s;
        }
        // ALL CAPS: title case, keeping short words such as GST, MIS, HR as they were.
        return preg_replace_callback('/\p{L}[\p{L}\']*/u', fn ($m) => mb_strlen($m[0]) <= 3 ? $m[0] : mb_convert_case(mb_strtolower($m[0]), MB_CASE_TITLE, 'UTF-8'), $s);
    }

    private function same(string $k, mixed $old, mixed $new): bool
    {
        return match ($k) {
            'exp_months' => abs((int) $old - (int) $new) <= 2,
            'ctc_current_lpa', 'ctc_expected_lpa' => abs((float) $old - (float) $new) < 0.05,
            'notice_days' => (int) $old === (int) $new,
            'phone' => FieldExtractor::normalisePhone((string) $old) === FieldExtractor::normalisePhone((string) $new),
            default => mb_strtolower(preg_replace('/[^\p{L}\p{N}@.]+/u', '', (string) $old)) === mb_strtolower(preg_replace('/[^\p{L}\p{N}@.]+/u', '', (string) $new)),
        };
    }

    private function show(string $k, mixed $v): string
    {
        if ($v === null || $v === '') {
            return '';
        }
        return match ($k) {
            'exp_months' => experience((int) $v),
            'ctc_current_lpa', 'ctc_expected_lpa' => lpa((float) $v),
            'notice_days' => (int) $v === 0 ? 'Immediate' : (int) $v . ' days',
            default => (string) $v,
        };
    }

    private function ymLabel(int $m, bool $exact): string
    {
        $y = intdiv($m - 1, 12);
        return $exact ? date('M Y', mktime(0, 0, 0, $m - $y * 12, 1, $y)) : (string) $y;
    }

    private function count(float $cost): void
    {
        $ym = date('Ym');
        $db = $this->app->db;
        $db->run("INSERT INTO settings (k, v) VALUES (?, '1') ON DUPLICATE KEY UPDATE v = CAST(v AS UNSIGNED) + 1", ["ai_read_count_$ym"]);
        $db->run("INSERT INTO settings (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = CAST(v AS DECIMAL(12,5)) + VALUES(v)", ["ai_read_cost_$ym", (string) $cost]);
    }

    private function skills(): Skills
    {
        return $this->skills ??= new Skills($this->app->db);
    }
}
