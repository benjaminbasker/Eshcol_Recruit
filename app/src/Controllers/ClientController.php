<?php
declare(strict_types=1);

namespace Eshcol\Ats\Controllers;

use Eshcol\Ats\Audit;
use Eshcol\Ats\Auth;
use Eshcol\Ats\Http;

final class ClientController extends Controller
{
    public function index(): never
    {
        $clients = $this->db->all("
            SELECT c.*, COUNT(r.id) AS roles_total, SUM(r.status = 'open') AS roles_open,
                   (SELECT name FROM client_contacts cc WHERE cc.client_id = c.id ORDER BY cc.id LIMIT 1) AS contact
            FROM clients c LEFT JOIN roles r ON r.client_id = c.id
            GROUP BY c.id ORDER BY c.is_active DESC, c.name");
        $this->view('clients/index', ['title' => 'Clients', 'clients' => $clients]);
    }

    public function create(): never
    {
        $this->view('clients/form', ['title' => 'New client', 'client' => [], 'contact' => [], 'errors' => []]);
    }

    public function store(): never
    {
        [$data, $contact, $errors] = $this->validate();
        if ($errors) {
            $this->view('clients/form', ['title' => 'New client', 'client' => $data, 'contact' => $contact, 'errors' => $errors]);
        }
        $data['created_by'] = Auth::user()['id'];
        $id = $this->db->insert('clients', $data);
        if ($contact['name']) {
            $this->db->insert('client_contacts', $contact + ['client_id' => $id]);
        }
        Audit::log('client_created', 'client', $id);
        $logoProblem = $this->saveLogo((int) $id);
        Http::flash($logoProblem ? 'error' : 'ok', $logoProblem ? 'Client added, but the logo was not saved: ' . $logoProblem : 'Client added.');
        Http::redirect("/clients/$id");
    }

    public function show(int $id): never
    {
        $client = $this->findOr404('clients', $id);
        $contacts = $this->db->all('SELECT * FROM client_contacts WHERE client_id = ? ORDER BY id', [$id]);
        $roles = $this->db->all('SELECT r.*, (SELECT COUNT(*) FROM applications a WHERE a.role_id = r.id) AS cvs FROM roles r WHERE client_id = ? ORDER BY status = \'open\' DESC, created_at DESC', [$id]);
        $this->view('clients/show', ['title' => $client['name'], 'client' => $client, 'contacts' => $contacts, 'roles' => $roles,
            'docs' => (new \Eshcol\Ats\Services\Documents($this->app))->for('client', $id)]);
    }

    public function edit(int $id): never
    {
        $client = $this->findOr404('clients', $id);
        $contact = $this->db->one('SELECT * FROM client_contacts WHERE client_id = ? ORDER BY id LIMIT 1', [$id]) ?? [];
        $this->view('clients/form', ['title' => 'Edit ' . $client['name'], 'client' => $client, 'contact' => $contact, 'errors' => []]);
    }

    public function update(int $id): never
    {
        $this->findOr404('clients', $id);
        [$data, $contact, $errors] = $this->validate();
        if ($errors) {
            $this->view('clients/form', ['title' => 'Edit client', 'client' => $data + ['id' => $id], 'contact' => $contact, 'errors' => $errors]);
        }
        $data['is_active'] = isset($_POST['is_active']) ? 1 : 0;
        $this->db->update('clients', $data, $id);
        $first = $this->db->col('SELECT id FROM client_contacts WHERE client_id = ? ORDER BY id LIMIT 1', [$id]);
        if ($contact['name']) {
            $first ? $this->db->update('client_contacts', $contact, (int) $first) : $this->db->insert('client_contacts', $contact + ['client_id' => $id]);
        }
        Audit::log('client_updated', 'client', $id);
        $logoProblem = $this->saveLogo($id);
        Http::flash($logoProblem ? 'error' : 'ok', $logoProblem ? 'Client updated, but the logo was not saved: ' . $logoProblem : 'Client updated.');
        Http::redirect("/clients/$id");
    }

    public function logo(int $id): never
    {
        (new \Eshcol\Ats\Services\ClientLogos($this->app))->send($id);
    }

    /** Another contact at the client (for the client portal, or simply on file). */
    public function addContact(int $id): never
    {
        $this->findOr404('clients', $id);
        $r = (new \Eshcol\Ats\Services\ClientPortal($this->app))->addContact($id, (string) ($_POST['name'] ?? ''), (string) ($_POST['email'] ?? ''), $this->str('designation', 120), $this->str('phone', 30));
        Http::flash(is_string($r) ? 'error' : 'ok', is_string($r) ? $r : 'Contact added. Use "Give portal access" to send them the invitation.');
        Http::redirect("/clients/$id#portal");
    }

    /** Client portal access for one contact: invite (or send a fresh link), or take access away. Owners only. */
    public function portalAccess(int $id, int $cid): never
    {
        $u = \Eshcol\Ats\Auth::requireOwner();
        $this->findOr404('clients', $id);
        $c = $this->db->one('SELECT * FROM client_contacts WHERE id = ? AND client_id = ?', [$cid, $id]);
        $c || Http::abort(404);
        $portal = new \Eshcol\Ats\Services\ClientPortal($this->app);
        if (($_POST['action'] ?? '') === 'revoke') {
            $portal->revoke($cid);
            Http::flash('ok', $c['name'] . ' no longer has access to the client portal. Any sign-in of theirs has ended.');
        } else {
            $problem = $portal->invite($cid, (int) $u['id']);
            Http::flash($problem ? 'error' : 'ok', $problem ?: 'Invitation sent to ' . $c['email'] . '. The link in it works for ' . \Eshcol\Ats\Services\ClientPortal::INVITE_DAYS . ' days; after that they ask for a fresh one on the sign-in page.');
        }
        Http::redirect("/clients/$id#portal");
    }

    private function saveLogo(int $id): ?string
    {
        $logos = new \Eshcol\Ats\Services\ClientLogos($this->app);
        if (!empty($_FILES['logo']['name'])) {
            return $logos->save($id, $_FILES['logo']);
        }
        if (isset($_POST['remove_logo'])) {
            $logos->remove($id);
        }
        return null;
    }

    private function validate(): array
    {
        $data = [
            'name' => $this->str('name', 160),
            'legal_name' => $this->str('legal_name', 200),
            'website' => ($w = $this->str('website', 255)) && !preg_match('#^https?://#i', $w) ? 'https://' . $w : $w,
            'interview_address' => $this->str('interview_address', 1000),
            'maps_url' => $this->str('maps_url', 1000),
            'meeting_link' => ($m = $this->str('meeting_link', 255)) && !preg_match('#^https?://#i', $m) ? 'https://' . $m : $m,
            'billing_address' => $this->str('billing_address', 2000),
            'gstin' => ($g = $this->str('gstin', 20)) ? strtoupper($g) : null,
            'fee_type' => in_array($_POST['fee_type'] ?? '', ['percent', 'fixed'], true) ? $_POST['fee_type'] : null,
            'fee_value' => $this->num('fee_value'),
            'payment_terms_days' => ($v = $this->num('payment_terms_days')) !== null ? (int) $v : null,
            'replacement_days' => ($v = $this->num('replacement_days')) !== null ? (int) $v : null,
            'notes' => $this->str('notes', 5000),
        ];
        $contact = [
            'name' => $this->str('contact_name', 120),
            'email' => $this->str('contact_email', 190),
            'phone' => $this->str('contact_phone', 30),
            'designation' => $this->str('contact_designation', 120),
        ];
        $errors = [];
        if (!$data['name']) {
            $errors['name'] = 'Give the client a name.';
        }
        if ($data['gstin'] && !preg_match('/^\d{2}[A-Z]{5}\d{4}[A-Z][1-9A-Z]Z[0-9A-Z]$/', $data['gstin'])) {
            $errors['gstin'] = 'That GSTIN does not look right (15 characters, e.g. 22AAAAA0000A1Z5).';
        }
        foreach (['website', 'maps_url', 'meeting_link'] as $k) {
            if ($data[$k] && !filter_var($data[$k], FILTER_VALIDATE_URL)) {
                $errors[$k] = 'That link does not look right. Copy it again from the browser address bar.';
            }
        }
        if ($contact['email'] && !filter_var($contact['email'], FILTER_VALIDATE_EMAIL)) {
            $errors['contact_email'] = 'That email address does not look right.';
        }
        return [$data, $contact, $errors];
    }
}
