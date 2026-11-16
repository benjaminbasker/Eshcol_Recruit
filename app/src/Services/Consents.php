<?php
declare(strict_types=1);

namespace Eshcol\Ats\Services;

use Eshcol\Ats\App;
use Eshcol\Ats\Audit;

/**
 * The consent record (B2, 14 Nov 2026). India's DPDP Act asks that a person is told why their data is
 * kept, can say no as easily as yes, and that this can be shown later. One row per event, never edited:
 *  - notice_sent: told (the thank-you email says we may consider them for other roles for five years,
 *    with "reply Please remove me" to opt out);
 *  - given: they ticked "Keep my details on file" on the details form (or answered "keep me" later);
 *  - withdrawn: they unticked it, asked to be removed, unsubscribed or marked our email as spam.
 * The latest row is where they stand. The thank-you email's notice text has a version, so it can be
 * shown later exactly what they were told.
 */
final class Consents
{
    /** The wording version of the notice in the thank-you email and the details form (13 Nov 2026). */
    public const NOTICE_VERSION = 'v1-2026-11';
    public const LABELS = ['notice_sent' => 'Told (no answer yet)', 'given' => 'Agreed to be kept on file', 'withdrawn' => 'Withdrawn'];
    public const VIA = ['ack_email' => 'thank-you email', 'details_form' => 'details form', 'removal_request' => '"Please remove me"', 'unsubscribed' => 'unsubscribed',
        'spam_complaint' => 'marked our email as spam', 'by_you' => 'recorded by you', 'cv_request' => '"share your updated CV" email'];

    public function __construct(private readonly App $app)
    {
    }

    public function record(int $candidateId, string $status, string $via, ?int $emailId = null, ?string $note = null): void
    {
        if (!isset(self::LABELS[$status])) {
            return;
        }
        $db = $this->app->db;
        // The same notice twice from the same place adds nothing.
        $last = $db->one("SELECT status, via FROM consents WHERE candidate_id = ? AND purpose = 'future_roles' ORDER BY id DESC LIMIT 1", [$candidateId]);
        if ($last && $last['status'] === $status && $last['via'] === $via && $status === 'notice_sent') {
            return;
        }
        $db->insert('consents', ['candidate_id' => $candidateId, 'status' => $status, 'via' => mb_substr($via, 0, 40), 'notice_version' => self::NOTICE_VERSION,
            'email_id' => $emailId, 'note' => $note ? mb_substr($note, 0, 255) : null]);
        Audit::log('consent_' . $status, 'candidate', $candidateId, ['via' => $via]);
    }

    /** Where a person stands now: the latest row, or null (nothing recorded: a CV from before 14 Nov 2026, or from your own database). */
    public function current(int $candidateId): ?array
    {
        return $this->app->db->one("SELECT * FROM consents WHERE candidate_id = ? AND purpose = 'future_roles' ORDER BY id DESC LIMIT 1", [$candidateId]);
    }

    public function history(int $candidateId): array
    {
        return $this->app->db->all('SELECT * FROM consents WHERE candidate_id = ? ORDER BY id DESC', [$candidateId]);
    }

    /** Counts for Settings › Keeping candidate data. */
    public function summary(): array
    {
        $db = $this->app->db;
        $rows = $db->all("SELECT c.status, COUNT(*) n FROM consents c JOIN (SELECT candidate_id, MAX(id) id FROM consents WHERE purpose = 'future_roles' GROUP BY candidate_id) l ON l.id = c.id GROUP BY c.status");
        $by = array_column($rows, 'n', 'status');
        $by['none'] = (int) $db->col("SELECT COUNT(*) FROM candidates WHERE id NOT IN (SELECT candidate_id FROM consents WHERE purpose = 'future_roles')");
        return $by;
    }

    /**
     * Everything held about one person, for a request to see their data (DPDP Act): details, CVs (names
     * only), applications, categories, answers, messages, emails, consents. As an array ready for JSON.
     */
    public function export(int $candidateId): ?array
    {
        $db = $this->app->db;
        $c = $db->one('SELECT * FROM candidates WHERE id = ?', [$candidateId]);
        if (!$c) {
            return null;
        }
        unset($c['email_norm'], $c['phone_norm'], $c['field_sources']);
        return [
            'exported_at' => date('c'), 'reference' => ref_candidate($candidateId),
            'details' => $c,
            'cv_files' => $db->all('SELECT original_name, ext, size_bytes, created_at FROM cv_files WHERE candidate_id = ? ORDER BY id', [$candidateId]),
            'skills' => array_column($db->all('SELECT skill FROM candidate_skills WHERE candidate_id = ? ORDER BY skill', [$candidateId]), 'skill'),
            'categories' => $db->all('SELECT function_code, secondary_code, specialisation, seniority, preferred_cities, relocation, source, created_at FROM candidate_categories WHERE candidate_id = ? ORDER BY id', [$candidateId]),
            'applications' => $db->all('SELECT r.title AS position, cl.name AS client, s.name AS stage, a.applied_at FROM applications a JOIN roles r ON r.id = a.role_id JOIN clients cl ON cl.id = r.client_id
                JOIN stages s ON s.id = a.stage_id WHERE a.candidate_id = ? AND r.is_pool = 0 ORDER BY a.id', [$candidateId]),
            'answers' => $db->all('SELECT qa.prompt, qa.answer, q.answered_at FROM questionnaire_answers qa JOIN questionnaires q ON q.id = qa.questionnaire_id WHERE q.candidate_id = ? AND q.status = \'answered\' ORDER BY q.id, qa.sort', [$candidateId]),
            'messages' => $db->all('SELECT direction, subject, body, received_at, created_at FROM candidate_messages WHERE candidate_id = ? ORDER BY id', [$candidateId]),
            'emails_sent' => $db->all("SELECT subject, sent_at FROM email_queue WHERE candidate_id = ? AND status = 'sent' ORDER BY id", [$candidateId]),
            'interviews' => $db->all('SELECT i.round, i.kind, i.status, i.starts_at FROM interviews i JOIN applications a ON a.id = i.application_id WHERE a.candidate_id = ? ORDER BY i.id', [$candidateId]),
            'consents' => $db->all('SELECT purpose, status, via, notice_version, created_at FROM consents WHERE candidate_id = ? ORDER BY id', [$candidateId]),
        ];
    }
}
