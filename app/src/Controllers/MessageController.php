<?php
declare(strict_types=1);

namespace Eshcol\Ats\Controllers;

use Eshcol\Ats\App;
use Eshcol\Ats\Auth;
use Eshcol\Ats\Http;
use Eshcol\Ats\Services\CandidateMessages;
use Eshcol\Ats\Services\Documents;

/** Two-way email with candidates: the Messages page, and sending from a candidate's page. */
final class MessageController extends Controller
{
    public function index(): never
    {
        $this->view('messages/index', ['title' => 'Messages', 'rows' => (new CandidateMessages($this->app))->inbox()]);
    }

    public function send(int $candidateId): never
    {
        $this->findOr404('candidates', $candidateId);
        $docIds = array_map('intval', (array) ($_POST['doc'] ?? []));
        // A file you added to this one reply: validated and stored, then attached.
        $files = [];
        $file = $_FILES['attachment'] ?? null;
        if ($file && ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            [$ext, $err] = Documents::check($file);
            if ($err) {
                Http::flash('error', "The file was not attached: $err");
                $_SESSION['message_draft'][$candidateId] = ['subject' => (string) ($_POST['subject'] ?? ''), 'body' => (string) ($_POST['body'] ?? '')];
                Http::redirect("/candidates/$candidateId#messages");
            }
            $dir = $this->app->storage('msg-att');
            if (!is_dir($dir)) {
                mkdir($dir, 0750, true);
            }
            $rel = 'msg-att/' . bin2hex(random_bytes(16)) . ".$ext";
            if (move_uploaded_file($file['tmp_name'], $this->app->storage($rel))) {
                $name = trim(preg_replace('/[^\pL\pN ._()-]+/u', ' ', basename(str_replace('\\', '/', (string) $file['name'])))) ?: "attachment.$ext";
                $files[] = ['path' => $rel, 'name' => mb_substr($name, 0, 150)];
            }
        }
        $r = (new CandidateMessages($this->app))->send($candidateId, (int) Auth::user()['id'], (string) ($_POST['subject'] ?? ''), (string) ($_POST['body'] ?? ''),
            ($rid = (int) ($_POST['reply_to'] ?? 0)) ? $rid : null, $docIds, $files);
        Http::flash($r['ok'] ? 'ok' : 'error', $r['message']);
        if (!$r['ok']) {
            $_SESSION['message_draft'][$candidateId] = ['subject' => (string) ($_POST['subject'] ?? ''), 'body' => (string) ($_POST['body'] ?? '')];
        }
        // Sent from a list row (e.g. "Send opening" on Yet to respond, 18 Nov 2026): stay on that list, at the row,
        // instead of jumping to the candidate's own page.
        $back = (string) ($_POST['back'] ?? '');
        $appId = (int) ($_POST['app_id'] ?? 0);
        if ($back !== '' && $appId) {
            Http::redirect(CandidateController::backToRow($back, $appId, "/candidates/$candidateId#messages"));
        }
        Http::redirect("/candidates/$candidateId#messages");
    }
}
