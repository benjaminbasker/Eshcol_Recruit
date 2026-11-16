<?php
declare(strict_types=1);

namespace Eshcol\Ats\Services;

use Eshcol\Ats\App;
use Eshcol\Ats\Audit;

/**
 * Files that go out with call letters and shortlists: a company profile per client and a JD per
 * role. Stored privately (never reachable by URL), downloaded only by signed-in users.
 */
final class Documents
{
    public const KINDS = ['company_profile' => 'Company profile', 'jd' => 'Job description', 'other' => 'Other'];
    private const TYPES = ['pdf' => 'application/pdf', 'doc' => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'];

    public function __construct(private readonly App $app)
    {
    }

    /** Stores an upload; a new file of the same kind replaces the old one. Returns the id, or an error message. */
    /** Files a document already checked and kept on this server (a JD read on the New role form). */
    public function adopt(string $ownerType, int $ownerId, string $kind, string $path, string $name, int $userId): ?int
    {
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (!is_file($path) || !isset(self::TYPES[$ext])) {
            return null;
        }
        $dir = $this->app->storage('docs');
        if (!is_dir($dir)) {
            mkdir($dir, 0750, true);
        }
        $rel = 'docs/' . bin2hex(random_bytes(16)) . ".$ext";
        $size = (int) filesize($path);
        if (!rename($path, $this->app->storage($rel))) {
            return null;
        }
        foreach ($this->app->db->all('SELECT id FROM documents WHERE owner_type = ? AND owner_id = ? AND kind = ?', [$ownerType, $ownerId, $kind]) as $old) {
            $kind !== 'other' && $this->delete((int) $old['id']);
        }
        $id = $this->app->db->insert('documents', ['owner_type' => $ownerType, 'owner_id' => $ownerId, 'kind' => $kind,
            'original_name' => mb_substr(basename(str_replace('\\', '/', $name)), 0, 255), 'storage_path' => $rel,
            'mime' => self::TYPES[$ext], 'size_bytes' => $size, 'created_by' => $userId]);
        Audit::log('document_uploaded', $ownerType, $ownerId, ['kind' => $kind]);
        return (int) $id;
    }

    /** Checks an uploaded PDF or Word file. Returns its extension, or a message for the person. */
    public static function check(array $file): array
    {
        if (($file['error'] ?? -1) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'] ?? '')) {
            return [null, 'The file did not arrive. Please try again.'];
        }
        $ext = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));
        if (!isset(self::TYPES[$ext])) {
            return [null, 'Please choose a PDF or Word file.'];
        }
        if ($file['size'] > 10 * 1048576) {
            return [null, 'That file is larger than 10 MB.'];
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']) ?: '';
        $ok = $ext === 'pdf' ? $mime === 'application/pdf' : in_array($mime, ['application/msword', 'application/zip', 'application/octet-stream', self::TYPES['docx'], 'application/vnd.ms-office'], true);
        return $ok ? [$ext, null] : [null, 'That does not look like a real ' . strtoupper($ext) . ' file.'];
    }

    public function store(string $ownerType, int $ownerId, string $kind, array $file, int $userId): int|string
    {
        if (($file['error'] ?? -1) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'] ?? '')) {
            return 'The file did not arrive. Please try again.';
        }
        $ext = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));
        if (!isset(self::TYPES[$ext])) {
            return 'Please upload a PDF or Word file.';
        }
        if ($file['size'] > 10 * 1048576) {
            return 'That file is larger than 10 MB.';
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']) ?: '';
        $ok = $ext === 'pdf' ? $mime === 'application/pdf' : in_array($mime, ['application/msword', 'application/zip', 'application/octet-stream', self::TYPES['docx'], 'application/vnd.ms-office'], true);
        if (!$ok) {
            return 'That does not look like a real ' . strtoupper($ext) . ' file.';
        }
        $dir = $this->app->storage('docs');
        if (!is_dir($dir)) {
            mkdir($dir, 0750, true);
        }
        $rel = 'docs/' . bin2hex(random_bytes(16)) . ".$ext";
        if (!move_uploaded_file($file['tmp_name'], $this->app->storage($rel))) {
            return 'The file could not be saved.';
        }
        foreach ($this->app->db->all('SELECT id FROM documents WHERE owner_type = ? AND owner_id = ? AND kind = ?', [$ownerType, $ownerId, $kind]) as $old) {
            if ($kind !== 'other') {
                $this->delete((int) $old['id']);
            }
        }
        $id = $this->app->db->insert('documents', ['owner_type' => $ownerType, 'owner_id' => $ownerId, 'kind' => $kind,
            'original_name' => mb_substr(basename(str_replace('\\', '/', (string) $file['name'])), 0, 255), 'storage_path' => $rel,
            'mime' => self::TYPES[$ext], 'size_bytes' => (int) $file['size'], 'created_by' => $userId]);
        Audit::log('document_uploaded', $ownerType, $ownerId, ['kind' => $kind]);
        return $id;
    }

    public function delete(int $id): void
    {
        $d = $this->app->db->one('SELECT * FROM documents WHERE id = ?', [$id]);
        if ($d) {
            @unlink($this->app->storage($d['storage_path']));
            $this->app->db->run('DELETE FROM documents WHERE id = ?', [$id]);
        }
    }

    public function for(string $ownerType, int $ownerId): array
    {
        return $this->app->db->all('SELECT * FROM documents WHERE owner_type = ? AND owner_id = ? ORDER BY kind, id DESC', [$ownerType, $ownerId]);
    }

    /** The current file of a kind, e.g. the role's JD, or null. */
    public function find(string $ownerType, int $ownerId, string $kind): ?array
    {
        return $this->app->db->one('SELECT * FROM documents WHERE owner_type = ? AND owner_id = ? AND kind = ? ORDER BY id DESC LIMIT 1', [$ownerType, $ownerId, $kind]) ?: null;
    }

    /** As an email attachment: [path relative to storage, file name]. */
    public static function attachment(?array $doc, string $prefix = ''): ?array
    {
        if (!$doc) {
            return null;
        }
        $ext = pathinfo($doc['original_name'], PATHINFO_EXTENSION) ?: pathinfo($doc['storage_path'], PATHINFO_EXTENSION);
        $base = trim(preg_replace('/[^\pL\pN ._()-]+/u', ' ', pathinfo($doc['original_name'], PATHINFO_FILENAME))) ?: 'Document';
        return ['path' => $doc['storage_path'], 'name' => ($prefix !== '' ? "$prefix - " : '') . $base . '.' . strtolower($ext)];
    }
}
