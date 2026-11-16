<?php
declare(strict_types=1);

namespace Eshcol\Ats\Controllers;

use Eshcol\Ats\Audit;
use Eshcol\Ats\Auth;
use Eshcol\Ats\Http;
use Eshcol\Ats\Services\Documents;

/** Company profiles (per client) and JD files (per role). */
final class DocumentController extends Controller
{
    public function upload(): never
    {
        $type = in_array($_POST['owner_type'] ?? '', ['client', 'role'], true) ? $_POST['owner_type'] : '';
        $id = (int) ($_POST['owner_id'] ?? 0);
        $kind = array_key_exists($_POST['kind'] ?? '', Documents::KINDS) ? $_POST['kind'] : 'other';
        $table = $type === 'client' ? 'clients' : 'roles';
        if (!$type || !$this->db->col("SELECT id FROM $table WHERE id = ?", [$id])) {
            Http::abort(404);
        }
        $r = (new Documents($this->app))->store($type, $id, $kind, $_FILES['file'] ?? [], (int) Auth::user()['id']);
        Http::flash(is_int($r) ? 'ok' : 'error', is_int($r) ? Documents::KINDS[$kind] . ' saved. It will be attached to call letters automatically.' : $r);
        Http::redirect("/{$table}/$id#documents");
    }

    public function download(int $id): never
    {
        $d = $this->db->one('SELECT * FROM documents WHERE id = ?', [$id]);
        if (!$d || !is_file($p = $this->app->storage($d['storage_path']))) {
            Http::abort(404);
        }
        Audit::log('document_viewed', $d['owner_type'], (int) $d['owner_id']);
        header_remove('Content-Security-Policy');
        header('Content-Type: ' . $d['mime']);
        header('Content-Disposition: ' . ($d['mime'] === 'application/pdf' ? 'inline' : 'attachment') . '; filename="' . preg_replace('/[^A-Za-z0-9 ._()-]/', '', $d['original_name']) . '"');
        header('Content-Length: ' . filesize($p));
        readfile($p);
        exit;
    }

    public function delete(int $id): never
    {
        $d = $this->db->one('SELECT * FROM documents WHERE id = ?', [$id]);
        $d || Http::abort(404);
        (new Documents($this->app))->delete($id);
        Audit::log('document_deleted', $d['owner_type'], (int) $d['owner_id']);
        Http::flash('ok', 'File removed.');
        Http::redirect('/' . ($d['owner_type'] === 'client' ? 'clients' : 'roles') . '/' . (int) $d['owner_id'] . '#documents');
    }
}
