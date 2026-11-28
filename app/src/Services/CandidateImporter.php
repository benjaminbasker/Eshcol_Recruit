<?php
declare(strict_types=1);

namespace Eshcol\Ats\Services;

use Eshcol\Ats\App;
use Eshcol\Ats\Audit;

/**
 * Takes one CV file and turns it into a candidate: stores the file privately,
 * reads the text, pulls out details, matches it to anyone already on file, and
 * adds it to a role (and scores it) when one is chosen.
 */
final class CandidateImporter
{
    public const MAX_BYTES = 10 * 1024 * 1024;

    private Skills $skills;
    private FieldExtractor $fields;
    private TextExtractor $text;
    private Scorer $scorer;
    /** Set by importFor(): the CV belongs to this candidate, whatever email or phone it shows. */
    private ?int $forCandidate = null;
    /**
     * "These are older CVs" (upload option, 14 Nov 2026): for someone already on file with a CV, the one we have
     * stays their latest unless both are dated and this one is newer; this one only fills details we're missing.
     */
    public bool $older = false;

    /** An updated CV sent by a known candidate through their personal page ("share your updated CV"). */
    public function importFor(int $candidateId, string $tmpPath, string $originalName, int $userId): array
    {
        $this->forCandidate = $candidateId;
        try {
            return $this->import($tmpPath, $originalName, null, $userId, 'cv_request');
        } finally {
            $this->forCandidate = null;
        }
    }

    public function __construct(private readonly App $app)
    {
        $this->skills = new Skills($app->db);
        $this->fields = new FieldExtractor($this->skills);
        $this->text = new TextExtractor();
        $this->scorer = new Scorer($this->skills);
    }

    public function skills(): Skills
    {
        return $this->skills;
    }

    public function scorer(): Scorer
    {
        return $this->scorer;
    }

    /**
     * @return array{outcome:string, message:string, candidate_id:?int, cv_file_id:?int, name:?string}
     */
    /** Where this import's CVs came from (set per import; see CHANNELS). */
    private ?string $channel = null;

    /** "Naukri_PriyaKulkarni[5y_0m].pdf" is a CV downloaded from Naukri. */
    public static function channelFromFilename(string $name): ?string
    {
        return preg_match('/^\s*naukri[\s_\-]/i', basename($name)) ? 'naukri' : null;
    }

    /**
     * @param ?string $channel where the CV came from (a CHANNELS key); worked out from the file name when not given
     * @return array{outcome:string, message:string, candidate_id:?int, cv_file_id:?int, name:?string}
     */
    public function import(string $tmpPath, string $originalName, ?int $roleId, int $userId, string $source = 'upload', ?string $channel = null): array
    {
        $this->channel = isset(CHANNELS[$channel ?? '']) ? $channel : self::channelFromFilename($originalName);
        $r = $this->importOnly($tmpPath, $originalName, $roleId, $userId, $source);
        if ($r['candidate_id'] && !$this->forCandidate && in_array($r['outcome'], ['updated', 'duplicate_file'], true)) {
            CvRequests::reapplied($this->app, (int) $r['candidate_id']); // applied again: "no answer" no longer holds
        }
        if ($r['candidate_id'] && $this->channel) {
            // The candidate keeps the first source we knew; a later one doesn't replace it.
            $this->app->db->run('UPDATE candidates SET channel = ? WHERE id = ? AND channel IS NULL', [$this->channel, $r['candidate_id']]);
        }
        // Then send the CV to any other open role its experience fits (see RoleRouter).
        if ($r['candidate_id'] && in_array($r['outcome'], ['new', 'updated', 'duplicate_file'], true)) {
            $routed = (new RoleRouter($this->app))->route($r['candidate_id'], $roleId, $userId);
            if ($routed) {
                $r['message'] .= ' ' . RoleRouter::describe($routed);
            }
            $r['routed'] = $routed;
        }
        return $r;
    }

    private function importOnly(string $tmpPath, string $originalName, ?int $roleId, int $userId, string $source): array
    {
        $db = $this->app->db;
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if (!in_array($ext, TextExtractor::ALLOWED, true)) {
            return $this->result('skipped', 'Not a CV file type (PDF, Word, text or image).');
        }
        $size = filesize($tmpPath);
        if (!$size || $size > self::MAX_BYTES) {
            return $this->result('failed', $size ? 'Larger than 10 MB.' : 'The file is empty.');
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($tmpPath) ?: 'application/octet-stream';
        if (!$this->mimeFits($ext, $mime)) {
            return $this->result('failed', "The contents don't match a .$ext file.");
        }

        $sha = hash_file('sha256', $tmpPath);
        $existing = $db->one('SELECT id, candidate_id FROM cv_files WHERE sha256 = ?', [$sha]);
        if ($existing && $existing['candidate_id']) {
            $cid = (int) $existing['candidate_id'];
            $added = $roleId ? $this->attach($cid, $roleId, $userId, $source) : false;
            $name = $db->col('SELECT full_name FROM candidates WHERE id = ?', [$cid]);
            $flag = $db->col('SELECT unreliable_at FROM candidates WHERE id = ?', [$cid]) ? ' Note: this person is marked unreliable.'
                : ($db->col('SELECT red_listed_at FROM candidates WHERE id = ?', [$cid]) ? ' Note: this person is red-listed.' : '');
            return $this->result('duplicate_file', 'This exact file is already on file' . ($added ? '; added to this role.' : '.') . $flag, $cid, (int) $existing['id'], $name ?: null);
        }

        // Store the original privately: storage/cv/YYYY/MM/<sha>.<ext>
        $rel = 'cv/' . date('Y/m') . '/' . $sha . '.' . $ext;
        $dest = $this->app->storage($rel);
        if (!is_dir(dirname($dest))) {
            mkdir(dirname($dest), 0750, true);
        }
        if (!copy($tmpPath, $dest)) {
            return $this->result('failed', 'Could not save the file.');
        }

        $read = $this->text->extract($dest, $ext);
        $info = $this->fields->extract($read['text'], $originalName);
        $f = $info['fields'];

        return $db->tx(function () use ($db, $f, $info, $read, $sha, $originalName, $ext, $mime, $size, $rel, $dest, $roleId, $userId, $source) {
            $match = $this->forCandidate
                ? $db->one('SELECT * FROM candidates WHERE id = ?', [$this->forCandidate])
                : $this->findExisting($f['email'] ?? null, $f['phone'] ?? null);
            $retention = (new Retention($this->app))->months();

            if ($match && $this->older && !$this->forCandidate && $this->theirsIsNewer($match, $f)) {
                $cid = (int) $match['id'];
                // An old CV for someone whose newer CV we already have: keep theirs; fill only the blanks, and
                // nothing about experience (an old CV understates it).
                $fill = [];
                $sources = json_decode($match['field_sources'] ?? '{}', true) ?: [];
                foreach ($f as $k => $v) {
                    if (in_array($k, ['exp_months', 'exp_as_of', 'exp_stated_months', 'exp_dates_months', 'cv_dated', 'cv_has_current_job'], true)) {
                        continue;
                    }
                    if ((($match[$k] ?? null) === null || $match[$k] === '') && $v !== null && $v !== '') {
                        $fill[$k] = $v;
                        $sources[$k] = $info['sources'][$k] ?? 'cv';
                    }
                }
                if ($fill) {
                    $fill['field_sources'] = json_encode($sources);
                    $this->addNorms($fill);
                    $db->update('candidates', $fill, $cid);
                }
                @unlink($dest); // not kept: the file we already have is their CV
                Audit::log('cv_older_copy', 'candidate', $cid, ['filled' => array_values(array_diff(array_keys($fill), ['field_sources']))]);
                return $this->result('older_copy', 'Already on file with a newer CV; kept that one as their latest' . ($fill ? ' and filled in missing details.' : '.'), $cid, null, $match['full_name'] ?? null);
            }

            if ($match) {
                $cid = (int) $match['id'];
                // Fill only what's missing: never overwrite a detail someone confirmed.
                $updates = [];
                $sources = json_decode($match['field_sources'] ?? '{}', true) ?: [];
                foreach ($f as $k => $v) {
                    // The cross-check describes the latest CV, so it always follows the newest one.
                    if (in_array($k, ['exp_stated_months', 'exp_dates_months', 'cv_dated', 'cv_has_current_job'], true)) {
                        $updates[$k] = $v;
                        continue;
                    }
                    if ($k === 'exp_as_of') {
                        continue; // set with exp_months below
                    }
                    if (($match[$k] ?? null) === null || $match[$k] === '') {
                        $updates[$k] = $v;
                        $sources[$k] = $info['sources'][$k];
                    }
                }
                if (isset($updates['exp_months'])) {
                    $updates['exp_as_of'] = $f['exp_as_of'] ?? date('Y-m-d');
                }
                $updates['purge_after'] = date('Y-m-d', strtotime("+$retention months"));
                $updates['field_sources'] = json_encode($sources);
                $this->addNorms($updates);
                $db->update('candidates', $updates, $cid);
                $outcome = 'updated';
                $message = 'Matched an existing candidate; added this CV as their latest.';
            } else {
                $row = $f + [
                    'source' => $source,
                    'field_sources' => json_encode($info['sources']),
                    'purge_after' => date('Y-m-d', strtotime("+$retention months")),
                    'created_by' => $userId,
                ];
                if (isset($f['exp_months'])) {
                    $row['exp_as_of'] = $f['exp_as_of'] ?? date('Y-m-d');
                }
                $this->addNorms($row);
                $cid = $db->insert('candidates', $row);
                $outcome = 'new';
                $message = 'New candidate.';
            }

            $cvId = $db->insert('cv_files', [
                'candidate_id' => $cid,
                'sha256' => $sha,
                'original_name' => mb_substr($originalName, 0, 255),
                'ext' => $ext,
                'mime' => $mime,
                'size_bytes' => $size,
                'storage_path' => $rel,
                'text' => $read['text'] !== '' ? $read['text'] : null,
                'text_status' => $read['status'],
                'uploaded_by' => $userId,
            ]);
            foreach ($info['skills'] as $s) {
                $db->run('INSERT IGNORE INTO candidate_skills (candidate_id, skill) VALUES (?, ?)', [$cid, $s]);
            }
            foreach ($info['titles'] ?? [] as $t) {
                $db->run('INSERT IGNORE INTO candidate_titles (candidate_id, title) VALUES (?, ?)', [$cid, $t]);
            }

            // Claude (Sonnet) reads every new CV within five minutes and corrects what the free reader got wrong.
            $aiOn = AiReading::enabled($this->app);
            if ($aiOn) {
                (new AiReading($this->app))->queue($cvId, $cid);
            }
            // Then it is categorised for future searches (field, seniority, where they'd work), after the reading.
            (new Categoriser($this->app))->queue($cid, $cvId);
            if ($read['status'] !== 'ok') {
                $outcome = $read['status'] === 'needs_ocr' ? 'needs_ocr' : 'failed';
                $message = $read['status'] === 'needs_ocr'
                    ? ($aiOn ? 'Saved. It is a scan or photo: Claude will read it within five minutes.' : 'Saved, but it is a scan or photo. Open it and use Read scanned CV.')
                    : 'Saved, but the text could not be read. Please check the file.';
            }
            if ($roleId) {
                $this->attach($cid, $roleId, $userId, $source);
            }
            $retention = new Retention($this->app);
            $retention->touch($cid);
            if ($outcome === 'new' && $retention->isSuppressed($f['email'] ?? null, FieldExtractor::normalisePhone($f['phone'] ?? null))) {
                $db->update('candidates', ['keep_note' => 'Previously asked to be removed. Applied again on ' . date('j M Y') . '.'], $cid);
                $message .= ' Note: this person once asked to be removed, and has now applied again.';
            }
            if ($outcome === 'new' && ($past = $retention->pastUnreliable($f['email'] ?? null, FieldExtractor::normalisePhone($f['phone'] ?? null)))) {
                [, $why, $ym] = array_pad(explode(':', $past), 3, '');
                (new Reliability($this->app))->mark($cid, $why, 'marked before' . ($ym ? ' (' . date('M Y', strtotime($ym . '-01')) . ')' : '') . '; that record has since been deleted', null);
                $message .= ' Note: this person was marked unreliable before.';
            } elseif ($db->col('SELECT unreliable_at FROM candidates WHERE id = ?', [$cid])) {
                $message .= ' Note: this person is marked unreliable.';
            } elseif ($db->col('SELECT red_listed_at FROM candidates WHERE id = ?', [$cid])) {
                $message .= ' Note: this person is red-listed.';
            }
            // File names usually contain the person's name, so they are not written to the activity log.
            Audit::log('cv_imported', 'candidate', $cid, ['cv_file' => $cvId, 'type' => $ext, 'outcome' => $outcome]);
            return $this->result($outcome, $message, $cid, $cvId, $f['full_name'] ?? null);
        });
    }

    /** Adds a candidate to a role at the first stage and scores them. Returns false if already there. */
    public function attach(int $candidateId, int $roleId, int $userId, string $source = 'upload'): bool
    {
        $db = $this->app->db;
        if ($db->col('SELECT id FROM applications WHERE candidate_id = ? AND role_id = ?', [$candidateId, $roleId])) {
            return false;
        }
        $first = (int) $db->col("SELECT id FROM stages WHERE kind = 'active' AND is_active = 1 ORDER BY sort LIMIT 1");
        $appId = $db->insert('applications', [
            'candidate_id' => $candidateId,
            'role_id' => $roleId,
            'stage_id' => $first,
            'source' => $source,
            // How they came to this role: picked from the database, or this import's source (routing records none).
            'channel' => $source === 'database' ? 'database' : ($source === 'routed' ? null : $this->channel),
            'added_by' => $userId,
        ]);
        $db->insert('stage_history', ['application_id' => $appId, 'to_stage_id' => $first, 'user_id' => $userId, 'note' => 'Added']);
        $this->scoreApplication($appId);
        // Is it the same kind of work as the position? Claude reads the whole CV against the whole job description
        // (27 Nov 2026). Queued only; the five-minute job asks. Never stops an upload (before update 080 there is no table).
        try {
            (new SuitabilityCheck($this->app))->queue($appId);
        } catch (\Throwable) {
        }
        // Picked from the database onto a position they don't actually qualify for (B.Com, or local
        // candidates only): screened out straight away, same as a self-application (Benjamin, 18 Nov 2026).
        // No need to review the CV for this, and no Claude assessment is spent on it.
        $screenedOut = false;
        if ($source === 'database') {
            $role = $db->one('SELECT * FROM roles WHERE id = ?', [$roleId]);
            if ($role && !(bool) ($role['is_pool'] ?? false)) {
                $cand = $db->one('SELECT * FROM candidates WHERE id = ?', [$candidateId]);
                $cat = (new Categoriser($this->app))->current($candidateId);
                $fail = (new FunctionRouting($this->app))->screenFail($cand, $cat, $role);
                if ($fail && ($notSuitable = (int) (Stages::id($this->app, Stages::NOT_SUITABLE) ?? 0))) {
                    [, $why] = $fail;
                    $reason = mb_substr(ucfirst($why) . '.', 0, 255);
                    $db->update('applications', ['stage_id' => $notSuitable, 'stage_reason' => $reason, 'stage_reason_at' => date('Y-m-d H:i:s'), 'fit_note' => mb_substr("Screened out: $why", 0, 255)], $appId);
                    $db->insert('stage_history', ['application_id' => $appId, 'from_stage_id' => $first, 'to_stage_id' => $notSuitable, 'user_id' => $userId, 'note' => mb_substr("Screened out automatically: $why", 0, 255)]);
                    Audit::log('candidate_screened_out', 'candidate', $candidateId, ['role' => $roleId, 'why' => $why]);
                    $screenedOut = true;
                }
            }
        }
        // If the free check finds them relevant, Claude assesses them (after it has read the CV).
        if (!$screenedOut && FitAssessor::enabled($this->app)) {
            (new FitAssessor($this->app))->queueForCandidate($candidateId);
        }
        if (!in_array($source, ['database', 'routed'], true)) {
            // A new application is contact from the candidate; being picked from the database
            // or routed between roles by the app is not.
            (new Retention($this->app))->touch($candidateId);
        }
        return true;
    }

    /** (Re)scores one application from the candidate's latest readable CV. */
    public function scoreApplication(int $applicationId): ?int
    {
        $db = $this->app->db;
        $a = $db->one('SELECT * FROM applications WHERE id = ?', [$applicationId]);
        if (!$a) {
            return null;
        }
        $role = $db->one('SELECT * FROM roles WHERE id = ?', [$a['role_id']]);
        $criteria = $db->all('SELECT kind, value FROM role_criteria WHERE role_id = ? ORDER BY sort, id', [$a['role_id']]);
        $cand = $db->one('SELECT * FROM candidates WHERE id = ?', [$a['candidate_id']]);
        $text = (string) $db->col("SELECT text FROM cv_files WHERE candidate_id = ? AND text IS NOT NULL ORDER BY id DESC LIMIT 1", [$a['candidate_id']]);
        $r = $this->scorer->score($role, $criteria, $cand, $text);
        $db->update('applications', [
            'score' => $r['score'],
            'score_breakdown' => json_encode($r['breakdown'], JSON_UNESCAPED_UNICODE),
            'scored_at' => date('Y-m-d H:i:s'),
        ], $applicationId);
        return $r['score'];
    }

    /**
     * Once, after update 026: LinkedIn profiles for CVs already on file, free. From the CV text,
     * else from a link stored in the Word or PDF file. Never replaces one already set.
     */
    public function backfillLinkedin(): int
    {
        $db = $this->app->db;
        $n = 0;
        foreach ($db->all('SELECT id, field_sources FROM candidates WHERE linkedin_url IS NULL') as $c) {
            $f = $db->one('SELECT text, ext, storage_path FROM cv_files WHERE candidate_id = ? ORDER BY id DESC LIMIT 1', [$c['id']]);
            if (!$f) {
                continue;
            }
            $url = FieldExtractor::linkedin((string) $f['text']);
            $path = $this->app->storage((string) $f['storage_path']);
            if (!$url && in_array($f['ext'], ['pdf', 'docx'], true) && is_file($path)) {
                $url = FieldExtractor::linkedin(implode(' ', $this->text->links($path, (string) $f['ext'])));
            }
            if ($url) {
                $src = json_decode((string) ($c['field_sources'] ?? '{}'), true) ?: [];
                $src['linkedin_url'] = 'cv';
                $db->run('UPDATE candidates SET linkedin_url = ?, field_sources = ? WHERE id = ? AND linkedin_url IS NULL', [$url, json_encode($src), $c['id']]);
                $n++;
            }
        }
        return $n;
    }

    /**
     * Once, after update 027: where candidates already on file came from, free. From the resume@
     * email that brought their CV (job board, website form, subject), or a Naukri file name.
     * People picked from the database for a role are marked so on that role. Never replaces a value.
     */
    public function backfillChannels(): int
    {
        $db = $this->app->db;
        $n = 0;
        foreach ($db->all('SELECT id FROM candidates WHERE channel IS NULL') as $c) {
            $cid = (int) $c['id'];
            $ch = null;
            $mi = $db->one("SELECT from_email, subject FROM mail_intake WHERE outcome = 'imported' AND FIND_IN_SET(?, candidate_ids) ORDER BY id LIMIT 1", [$cid]);
            if ($mi) {
                $ch = MailIntake::channelOf((string) $mi['from_email'], (string) $mi['subject']);
            }
            if (!$ch || $ch === 'email') {
                foreach ($db->all('SELECT original_name FROM cv_files WHERE candidate_id = ? ORDER BY id', [$cid]) as $f) {
                    if ($fc = self::channelFromFilename((string) $f['original_name'])) {
                        $ch = $fc;
                        break;
                    }
                }
            }
            if ($ch) {
                $db->run('UPDATE candidates SET channel = ? WHERE id = ? AND channel IS NULL', [$ch, $cid]);
                $n++;
            }
        }
        $db->run("UPDATE applications SET channel = 'database' WHERE channel IS NULL AND source = 'database'");
        return $n;
    }

    /** Is the CV we already hold for them newer than this one? Yes unless both are dated and this one is later. */
    private function theirsIsNewer(array $match, array $f): bool
    {
        if (!$this->app->db->col('SELECT 1 FROM cv_files WHERE candidate_id = ?', [$match['id']])) {
            return false; // nothing on file yet: this becomes their CV
        }
        $mine = $f['cv_dated'] ?? null;
        $theirs = $match['cv_dated'] ?? null;
        return !($mine && $theirs && strcmp((string) $mine, (string) $theirs) > 0);
    }

    private function findExisting(?string $email, ?string $phone): ?array
    {
        $db = $this->app->db;
        if ($email) {
            $m = $db->one('SELECT * FROM candidates WHERE email_norm = ? LIMIT 1', [mb_strtolower($email)]);
            if ($m) {
                return $m;
            }
        }
        $p = FieldExtractor::normalisePhone($phone);
        return $p ? $db->one('SELECT * FROM candidates WHERE phone_norm = ? LIMIT 1', [$p]) : null;
    }

    private function addNorms(array &$row): void
    {
        if (isset($row['email'])) {
            $row['email_norm'] = mb_strtolower($row['email']);
        }
        if (isset($row['phone'])) {
            $row['phone_norm'] = FieldExtractor::normalisePhone($row['phone']);
        }
    }

    private function mimeFits(string $ext, string $mime): bool
    {
        return match ($ext) {
            'pdf' => $mime === 'application/pdf',
            'docx' => in_array($mime, ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip', 'application/octet-stream'], true),
            'doc' => in_array($mime, ['application/msword', 'application/vnd.ms-office', 'application/CDFV2', 'application/x-ole-storage', 'application/octet-stream'], true),
            'txt' => str_starts_with($mime, 'text/'),
            'rtf' => in_array($mime, ['text/rtf', 'application/rtf', 'text/plain'], true),
            'jpg', 'jpeg' => $mime === 'image/jpeg',
            'png' => $mime === 'image/png',
            default => false,
        };
    }

    private function result(string $outcome, string $message, ?int $cid = null, ?int $cvId = null, ?string $name = null): array
    {
        return ['outcome' => $outcome, 'message' => $message, 'candidate_id' => $cid, 'cv_file_id' => $cvId, 'name' => $name];
    }
}
