<?php
declare(strict_types=1);

namespace Eshcol\Ats\Controllers;

use Eshcol\Ats\Http;

/** Quick results for the Ctrl+K palette: candidates, roles, clients, and references. */
final class SearchController extends Controller
{
    public function quick(): never
    {
        $q = trim(mb_substr((string) ($_GET['q'] ?? ''), 0, 120));
        $out = [];
        // A contractor finds only their own positions and the candidates on them (B6, 14 Nov 2026).
        $me = \Eshcol\Ats\Auth::user();
        $limited = \Eshcol\Ats\Access::restricted($me);
        $rf = \Eshcol\Ats\Access::roleFilter($me, 'r.id');
        $cf = $limited ? ' AND id IN (SELECT a.candidate_id FROM applications a WHERE ' . \Eshcol\Ats\Access::roleFilter($me, 'a.role_id') . ')' : '';
        if ($q === '') {
            Http::json(['results' => $this->recent()]);
        }
        if (!$limited && ($ref = parse_ref($q))) {
            [$type, $id] = $ref;
            if ($type === 'candidate' && ($c = $this->db->one('SELECT id, full_name, current_title FROM candidates WHERE id = ?', [$id]))) {
                $out[] = $this->cand($c);
            } elseif ($type === 'shortlist' && ($s = $this->db->one('SELECT s.id, r.title, cl.name AS client FROM shortlists s JOIN roles r ON r.id = s.role_id JOIN clients cl ON cl.id = r.client_id WHERE s.id = ?', [$id]))) {
                $out[] = ['group' => 'Shortlists', 'icon' => 'send', 'title' => ref_shortlist($s['id']) . ' · ' . $s['title'], 'sub' => $s['client'], 'url' => '/shortlists/' . $s['id']];
            } elseif ($type === 'interview' && ($iv = $this->db->one('SELECT i.id, a.candidate_id, c.full_name FROM interviews i JOIN applications a ON a.id = i.application_id JOIN candidates c ON c.id = a.candidate_id WHERE i.id = ?', [$id]))) {
                $out[] = ['group' => 'Interviews', 'icon' => 'calendar', 'title' => ref_interview($iv['id']) . ' · ' . $iv['full_name'], 'sub' => 'Interview', 'url' => '/candidates/' . $iv['candidate_id'] . '#interviews'];
            }
        }
        $like = '%' . addcslashes($q, '%_\\') . '%';
        $digits = preg_replace('/\D/', '', $q);
        [$nwSql, $nwArgs] = name_words_sql('full_name', $q); // each word anywhere in the name (17 Nov 2026)
        $cands = $this->db->all('SELECT id, full_name, current_title FROM candidates WHERE (full_name LIKE ? OR ' . $nwSql . ' OR email_norm LIKE ?' .
            (strlen($digits) >= 6 ? ' OR phone_norm LIKE ?' : '') . ')' . $cf . ' ORDER BY full_name LIKE ? DESC, updated_at DESC LIMIT 6',
            array_merge([$like], $nwArgs, [mb_strtolower($like)], strlen($digits) >= 6 ? ['%' . $digits . '%'] : [], [$like]));
        prime_stages(array_column($cands, 'id'));
        foreach ($cands as $c) {
            $out[] = $this->cand($c);
        }
        foreach ($this->db->all("SELECT r.id, r.title, r.status, r.is_pool, cl.name AS client FROM roles r JOIN clients cl ON cl.id = r.client_id
                                 WHERE (r.title LIKE ? OR cl.name LIKE ?) AND $rf ORDER BY r.status = 'open' DESC, r.updated_at DESC LIMIT 5", [$like, $like]) as $r) {
            $out[] = $this->role($r);
        }
        foreach ($limited ? [] : $this->db->all('SELECT id, name FROM clients WHERE name LIKE ? OR legal_name LIKE ? ORDER BY name LIMIT 4', [$like, $like]) as $c) {
            $out[] = ['group' => 'Clients', 'icon' => 'building', 'title' => $c['name'], 'sub' => '', 'url' => '/clients/' . $c['id']];
        }
        $limited || $out[] = ['group' => 'Search everything', 'icon' => 'search', 'title' => 'Search CVs for “' . $q . '”', 'sub' => 'Skills, employers, anything in a CV', 'url' => '/candidates?q=' . rawurlencode($q)];
        Http::json(['results' => $out]);
    }

    /** What to show before anything is typed: actions and the open roles. */
    private function recent(): array
    {
        $me = \Eshcol\Ats\Auth::user();
        $out = [['group' => 'Actions', 'icon' => 'upload', 'title' => 'Upload CVs', 'sub' => '', 'url' => '/upload']];
        if (!\Eshcol\Ats\Access::restricted($me)) {
            $out[] = ['group' => 'Actions', 'icon' => 'briefcase', 'title' => 'New role', 'sub' => '', 'url' => '/roles/new'];
            $out[] = ['group' => 'Actions', 'icon' => 'building', 'title' => 'New client', 'sub' => '', 'url' => '/clients/new'];
        }
        foreach ($this->db->all("SELECT r.id, r.title, r.status, r.is_pool, cl.name AS client FROM roles r JOIN clients cl ON cl.id = r.client_id
                                 WHERE r.status = 'open' AND r.is_pool = 0 AND " . \Eshcol\Ats\Access::roleFilter($me, 'r.id') . " ORDER BY r.updated_at DESC LIMIT 6") as $r) {
            $out[] = $this->role($r);
        }
        return $out;
    }

    private function cand(array $c): array
    {
        return ['group' => 'Candidates', 'icon' => 'user', 'title' => ($c['full_name'] ?: 'Unnamed candidate'), 'ref' => ref_candidate($c['id']),
                'sub' => implode(' · ', array_filter([(string) ($c['current_title'] ?? ''), \Eshcol\Ats\Services\KeyFacts::stagesText($this->app, (int) $c['id'])])), 'url' => '/candidates/' . $c['id']];
    }

    private function role(array $r): array
    {
        return ['group' => $r['is_pool'] ? 'Talent pools' : 'Roles', 'icon' => 'briefcase', 'title' => $r['title'],
                'sub' => $r['client'] . ($r['status'] !== 'open' ? ' · ' . str_replace('_', ' ', $r['status']) : ''), 'url' => '/roles/' . $r['id']];
    }
}
