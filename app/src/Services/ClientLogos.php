<?php
declare(strict_types=1);

namespace Eshcol\Ats\Services;

use Eshcol\Ats\App;
use Eshcol\Ats\Audit;

/** Client logos: PNG, JPG or WebP up to 1 MB, kept outside the web folder and served by the app. */
final class ClientLogos
{
    private const TYPES = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'];
    public const MAX_BYTES = 1048576;

    public function __construct(private App $app) {}

    /** Saves an uploaded logo for a client. Returns null when done, or a message for the person. */
    public function save(int $clientId, array $file): ?string
    {
        if (($file['error'] ?? -1) === UPLOAD_ERR_INI_SIZE || ($file['error'] ?? -1) === UPLOAD_ERR_FORM_SIZE || ($file['size'] ?? 0) > self::MAX_BYTES) {
            return 'That logo is larger than 1 MB. Please use a smaller image.';
        }
        if (($file['error'] ?? -1) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'] ?? '')) {
            return 'The logo did not arrive. Please try again.';
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']) ?: '';
        $size = @getimagesize($file['tmp_name']);
        if (!isset(self::TYPES[$mime]) || !$size || $size[0] < 16 || $size[1] < 16 || $size[0] > 6000 || $size[1] > 6000) {
            return 'Please upload the logo as a PNG, JPG or WebP image.';
        }
        $dir = $this->app->storage('logos');
        if (!is_dir($dir)) {
            mkdir($dir, 0750, true);
        }
        $rel = 'logos/' . $clientId . '-' . bin2hex(random_bytes(8)) . '.' . self::TYPES[$mime];
        if (!move_uploaded_file($file['tmp_name'], $this->app->storage($rel))) {
            return 'The logo could not be saved. Please try again.';
        }
        $this->removeFile($clientId);
        $this->app->db->run('UPDATE clients SET logo_path = ?, logo_updated_at = NOW() WHERE id = ?', [$rel, $clientId]);
        Audit::log('client_logo_saved', 'client', $clientId);
        return null;
    }

    public function remove(int $clientId): void
    {
        $this->removeFile($clientId);
        $this->app->db->run('UPDATE clients SET logo_path = NULL, logo_updated_at = NULL WHERE id = ?', [$clientId]);
    }

    /** Sends the logo image, or a 404 when there is none. */
    public function send(int $clientId): never
    {
        $rel = (string) $this->app->db->col('SELECT logo_path FROM clients WHERE id = ?', [$clientId]);
        $path = $rel !== '' ? $this->app->storage($rel) : '';
        if ($rel === '' || !is_file($path)) {
            http_response_code(404);
            exit;
        }
        $mime = array_search(strtolower(pathinfo($path, PATHINFO_EXTENSION)), self::TYPES, true) ?: 'application/octet-stream';
        header_remove('Content-Security-Policy');
        header("Content-Security-Policy: default-src 'none'");
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . filesize($path));
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, max-age=604800');
        readfile($path);
        exit;
    }

    private function removeFile(int $clientId): void
    {
        $old = (string) $this->app->db->col('SELECT logo_path FROM clients WHERE id = ?', [$clientId]);
        if ($old !== '' && str_starts_with($old, 'logos/')) {
            @unlink($this->app->storage($old));
        }
    }
}
