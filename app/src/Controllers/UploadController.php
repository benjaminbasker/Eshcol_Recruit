<?php
declare(strict_types=1);

namespace Eshcol\Ats\Controllers;

use Eshcol\Ats\Auth;
use Eshcol\Ats\Http;
use Eshcol\Ats\Services\Acknowledger;
use Eshcol\Ats\Services\CandidateImporter;
use Eshcol\Ats\Services\TextExtractor;

final class UploadController extends Controller
{
    private const ZIP_MAX_FILES = 300;
    private const ZIP_MAX_TOTAL = 200 * 1024 * 1024;

    public function page(): never
    {
        $roles = $this->db->all("SELECT r.id, r.title, r.code, c.name AS client FROM roles r JOIN clients c ON c.id = r.client_id WHERE r.status IN ('open','on_hold','draft') AND r.is_pool = 0 AND " . \Eshcol\Ats\Access::roleFilter(Auth::user(), 'r.id') . " ORDER BY r.title");
        $this->view('upload/page', ['title' => 'Upload CVs', 'roles' => $roles, 'roleId' => (int) ($_GET['role'] ?? 0),
            'ackOn' => (new Acknowledger($this->app))->enabled(),
            'maxMb' => (int) floor(min(CandidateImporter::MAX_BYTES, $this->iniBytes('upload_max_filesize')) / 1048576),
            'history' => $this->history()]);
    }

    /** The last 15 uploads, each with its files and what happened to them. A contractor sees only their own uploads. */
    private function history(): array
    {
        $me = Auth::user();
        $mine = \Eshcol\Ats\Access::restricted($me) ? 'b.user_id = ' . (int) $me['id'] . ' AND ' . \Eshcol\Ats\Access::roleFilter($me, 'b.role_id') : '1=1';
        $batches = $this->db->all("SELECT b.id, b.created_at, b.send_ack, b.label, b.older, r.title AS role, u.name AS who, COUNT(i.id) AS files
            FROM upload_batches b LEFT JOIN roles r ON r.id = b.role_id LEFT JOIN users u ON u.id = b.user_id
            LEFT JOIN upload_items i ON i.batch_id = b.id WHERE $mine GROUP BY b.id HAVING files > 0 ORDER BY b.id DESC LIMIT 15");
        // The first 60 files of each upload (all of them with ?all=ID): a 900-file upload made this page 476 KB (B12).
        $showAll = (int) ($_GET['all'] ?? 0);
        foreach ($batches as &$b) {
            $b['counts'] = array_column($this->db->all('SELECT outcome, COUNT(*) AS n FROM upload_items WHERE batch_id = ? GROUP BY outcome', [$b['id']]), 'n', 'outcome');
            $b['items'] = $this->db->all('SELECT i.filename, i.outcome, i.message, i.candidate_id, c.full_name FROM upload_items i
                LEFT JOIN candidates c ON c.id = i.candidate_id WHERE i.batch_id = ? ORDER BY i.id' . ((int) $b['id'] === $showAll ? '' : ' LIMIT 60'), [$b['id']]);
            $b['more'] = max(0, (int) $b['files'] - count($b['items']));
        }
        return $batches;
    }

    public function startBatch(): never
    {
        $roleId = (int) ($_POST['role_id'] ?? 0) ?: null;
        // A contractor uploads only to one of their own positions (B6, 14 Nov 2026).
        if (\Eshcol\Ats\Access::restricted(Auth::user()) && (!$roleId || !\Eshcol\Ats\Access::allows(Auth::user(), 'role', $roleId))) {
            Http::json(['ok' => false, 'message' => 'Choose one of your positions first.'], 422);
        }
        if ($roleId && !$this->db->col('SELECT id FROM roles WHERE id = ?', [$roleId])) {
            Http::json(['ok' => false, 'message' => 'Unknown role.'], 422);
        }
        $id = $this->db->insert('upload_batches', ['user_id' => Auth::user()['id'], 'role_id' => $roleId, 'channel' => isset(CHANNELS[$_POST['channel'] ?? '']) ? $_POST['channel'] : null, 'label' => $this->str('label', 120),
            'send_ack' => $roleId && isset($_POST['send_ack']) && (new Acknowledger($this->app))->enabled() ? 1 : 0,
            'older' => isset($_POST['older']) ? 1 : 0]); // no position: never a thank-you (old CVs, 14 Nov 2026)
        Http::json(['ok' => true, 'batch' => $id]);
    }

    /** One file per request (a zip counts as one file and is unpacked here). */
    public function file(): never
    {
        @set_time_limit(120);
        $batch = $this->db->one('SELECT * FROM upload_batches WHERE id = ? AND user_id = ?', [(int) ($_POST['batch'] ?? 0), Auth::user()['id']]);
        if (!$batch) {
            Http::json(['ok' => false, 'message' => 'Upload session expired. Refresh and try again.'], 422);
        }
        $f = $_FILES['file'] ?? null;
        if (!$f || $f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) {
            $msg = match ($f['error'] ?? -1) {
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Larger than the server allows.',
                UPLOAD_ERR_PARTIAL => 'The upload was interrupted.',
                default => 'The file did not arrive.',
            };
            Http::json(['ok' => true, 'results' => [$this->record($batch, $f['name'] ?? 'file', ['outcome' => 'failed', 'message' => $msg])]]);
        }

        $name = basename(str_replace('\\', '/', (string) $f['name']));
        $importer = new CandidateImporter($this->app);
        $importer->older = !empty($batch['older']); // "These are older CVs": a newer CV already on file stays their latest
        $roleId = $batch['role_id'] ? (int) $batch['role_id'] : null;
        $uid = (int) Auth::user()['id'];

        if (strtolower(pathinfo($name, PATHINFO_EXTENSION)) === 'zip') {
            Http::json(['ok' => true, 'results' => $this->unzip($f['tmp_name'], $batch, $importer, $roleId, $uid)]);
        }
        $r = $importer->import($f['tmp_name'], $name, $roleId, $uid, 'upload', $batch['channel'] ?? null);
        Http::json(['ok' => true, 'results' => [$this->record($batch, $name, $r)]]);
    }

    private function unzip(string $path, array $batch, CandidateImporter $importer, ?int $roleId, int $uid): array
    {
        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) {
            return [$this->record($batch, 'zip', ['outcome' => 'failed', 'message' => 'Not a readable zip file.'])];
        }
        $results = [];
        $total = 0;
        $count = 0;
        $tmp = $this->app->storage('uploads-tmp');
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $st = $zip->statIndex($i);
            $entry = basename(str_replace('\\', '/', $st['name']));
            if (str_ends_with($st['name'], '/') || $entry === '' || $entry[0] === '.' || str_contains($st['name'], '__MACOSX')) {
                continue;
            }
            if (!in_array(strtolower(pathinfo($entry, PATHINFO_EXTENSION)), TextExtractor::ALLOWED, true)) {
                $results[] = $this->record($batch, $entry, ['outcome' => 'skipped', 'message' => 'Not a CV file type.']);
                continue;
            }
            if (++$count > self::ZIP_MAX_FILES || ($total += $st['size']) > self::ZIP_MAX_TOTAL) {
                $results[] = $this->record($batch, $entry, ['outcome' => 'skipped', 'message' => 'Zip too large: upload the rest separately.']);
                break;
            }
            if ($st['size'] > CandidateImporter::MAX_BYTES) {
                $results[] = $this->record($batch, $entry, ['outcome' => 'failed', 'message' => 'Larger than 10 MB.']);
                continue;
            }
            // Extract to a random temp name: entry paths are never used on disk.
            $dest = $tmp . '/' . bin2hex(random_bytes(12));
            $in = $zip->getStream($st['name']);
            $out = fopen($dest, 'wb');
            $copied = $in && $out ? stream_copy_to_stream($in, $out, CandidateImporter::MAX_BYTES + 1) : false;
            $in && fclose($in);
            $out && fclose($out);
            if ($copied === false || $copied > CandidateImporter::MAX_BYTES) {
                @unlink($dest);
                $results[] = $this->record($batch, $entry, ['outcome' => 'failed', 'message' => 'Could not unpack this file.']);
                continue;
            }
            try {
                $results[] = $this->record($batch, $entry, $importer->import($dest, $entry, $roleId, $uid, 'upload', $batch['channel'] ?? null));
            } finally {
                @unlink($dest);
            }
        }
        $zip->close();
        return $results ?: [$this->record($batch, 'zip', ['outcome' => 'skipped', 'message' => 'No CVs found inside the zip.'])];
    }

    private function record(array $batch, string $filename, array $r): array
    {
        // Thank the applicant, if asked to for this batch (once per role; doubtful addresses wait for you).
        if ($batch['send_ack'] && !empty($r['candidate_id']) && in_array($r['outcome'], ['new', 'updated', 'duplicate_file'], true)) {
            $a = (new Acknowledger($this->app))->acknowledge((int) $r['candidate_id'], $batch['role_id'] ? (int) $batch['role_id'] : null, (int) Auth::user()['id']);
            if ($a['status'] !== 'skipped' || !str_starts_with($a['message'], 'Already')) {
                $r['message'] = trim(($r['message'] ?? '') . ' ' . $a['message']);
            }
        }
        $this->db->insert('upload_items', [
            'batch_id' => $batch['id'],
            'filename' => mb_substr($filename, 0, 255),
            'outcome' => $r['outcome'],
            'message' => mb_substr($r['message'] ?? '', 0, 255),
            'candidate_id' => $r['candidate_id'] ?? null,
            'cv_file_id' => $r['cv_file_id'] ?? null,
        ]);
        return ['file' => $filename, 'outcome' => $r['outcome'], 'message' => $r['message'] ?? '', 'candidate_id' => $r['candidate_id'] ?? null, 'name' => $r['name'] ?? null];
    }

    private function iniBytes(string $key): int
    {
        $v = trim((string) ini_get($key));
        $n = (int) $v;
        return match (strtolower(substr($v, -1))) {
            'g' => $n * 1073741824,
            'm' => $n * 1048576,
            'k' => $n * 1024,
            default => $n,
        };
    }
}
