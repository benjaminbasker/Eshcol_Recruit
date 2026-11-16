<?php
declare(strict_types=1);

namespace Eshcol\Ats\Services;

use Eshcol\Ats\App;

/**
 * The Monday summary to the owners (design doc §2.11): the week in numbers, what waits for you,
 * and for each open role the best people already in your database who aren't on it yet.
 */
final class Digest
{
    public function __construct(private readonly App $app)
    {
    }

    /** @return array{0:string,1:string,2:string} subject, html, text */
    public function build(): array
    {
        $db = $this->app->db;
        $since = date('Y-m-d H:i:s', time() - 7 * 86400);
        $url = rtrim((string) ($this->app->config['app']['url'] ?? ''), '/');
        $h = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $n = [
            'cvs' => (int) $db->col('SELECT COUNT(*) FROM candidates WHERE created_at >= ?', [$since]),
            'acks' => (int) $db->col("SELECT COUNT(*) FROM email_queue WHERE kind = 'ack' AND status = 'sent' AND sent_at >= ?", [$since]),
            'answered' => (int) $db->col("SELECT COUNT(*) FROM questionnaires WHERE parent_id IS NULL AND status = 'answered' AND answered_at >= ?", [$since]),
            'flagged' => (int) $db->col("SELECT COUNT(*) FROM questionnaires WHERE parent_id IS NULL AND status = 'answered' AND answered_at >= ? AND JSON_LENGTH(flags) > 0", [$since]),
            'held' => (int) $db->col("SELECT COUNT(*) FROM email_queue WHERE status = 'held'"),
            'removals' => (int) $db->col('SELECT COUNT(*) FROM candidates WHERE removal_requested_at IS NOT NULL'),
            'failed' => (int) $db->col("SELECT COUNT(*) FROM email_queue WHERE status = 'failed' AND created_at >= ?", [$since]),
        ];
        $roles = $db->all("SELECT r.*, c.name AS client FROM roles r JOIN clients c ON c.id = r.client_id
                           WHERE r.status = 'open' AND r.is_pool = 0 ORDER BY r.created_at DESC LIMIT 15");
        $matcher = new Matcher($this->app);
        $roleRows = [];
        foreach ($roles as $r) {
            $stats = $db->one('SELECT COUNT(*) total, SUM(a.applied_at >= ?) new_week, SUM(COALESCE(a.override_score, a.score, 0) >= ?) above
                               FROM applications a WHERE a.role_id = ?', [$since, (int) $r['score_threshold'], $r['id']]);
            $matches = array_values(array_filter($matcher->forRole($r, 3), fn ($m) => $m['score'] >= (int) $r['score_threshold']));
            $roleRows[] = ['role' => $r, 'stats' => $stats, 'matches' => $matches];
        }

        $subject = 'Eshcol Recruit: your week (' . date('j M') . ')';
        $p = 'margin:0 0 10px 0; font-family:Arial, Helvetica, sans-serif; font-size:14px; line-height:21px; color:#2A2238;';
        $h2 = 'margin:22px 0 8px 0; font-family:Georgia, serif; font-size:18px; font-weight:normal; color:#320A67;';
        $html = '<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"></head>'
            . '<body style="margin:0; padding:0; background:#F2EFE7;"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="#F2EFE7"><tr><td align="center" style="padding:24px 12px;">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%; max-width:640px; margin:0 auto; background:#FFFFFF; border:1px solid #E8D3A4;">'
            . '<tr><td style="background:#320A67; padding:22px 28px;"><p style="margin:0; font-family:Arial, sans-serif; font-size:12px; letter-spacing:1.5px; text-transform:uppercase; color:#FFB85C; font-weight:bold;">Eshcol Recruit · weekly summary</p>'
            . '<p style="margin:6px 0 0 0; font-family:Georgia, serif; font-size:24px; color:#FFFFFF;">Your week, to ' . $h(date('l j F')) . '</p></td></tr><tr><td style="padding:8px 28px 26px 28px;">';
        $text = 'ESHCOL RECRUIT: YOUR WEEK, TO ' . strtoupper(date('l j F')) . "\n";

        // The week in numbers
        $html .= '<h2 style="' . $h2 . '">The week in numbers</h2><p style="' . $p . '">'
            . "<b>{$n['cvs']}</b> new CV" . ($n['cvs'] === 1 ? '' : 's') . " · <b>{$n['acks']}</b> thank-you email" . ($n['acks'] === 1 ? '' : 's') . ' sent · '
            . "<b>{$n['answered']}</b> screening questionnaire" . ($n['answered'] === 1 ? '' : 's') . ' answered' . ($n['flagged'] ? " ({$n['flagged']} with flags)" : '') . '</p>';
        $text .= "\nTHE WEEK IN NUMBERS\n{$n['cvs']} new CVs, {$n['acks']} thank-you emails sent, {$n['answered']} questionnaires answered" . ($n['flagged'] ? " ({$n['flagged']} with flags)" : '') . ".\n";

        // Waiting for you
        $todo = array_filter([
            $n['held'] ? [$n['held'] . ' email' . ($n['held'] > 1 ? 's wait' : ' waits') . ' for you to check the address', "$url/emails?tab=held"] : null,
            $n['removals'] ? [$n['removals'] . ' candidate' . ($n['removals'] > 1 ? 's' : '') . ' asked us not to keep their details', "$url/candidates?removal=1"] : null,
            $n['failed'] ? [$n['failed'] . ' email' . ($n['failed'] > 1 ? 's' : '') . ' could not be sent', "$url/emails?tab=failed"] : null,
        ]);
        if ($todo) {
            $html .= '<h2 style="' . $h2 . '">Waiting for you</h2>';
            $text .= "\nWAITING FOR YOU\n";
            foreach ($todo as [$what, $link]) {
                $html .= '<p style="' . $p . '">• <a href="' . $h($link) . '" style="color:#7946B2;">' . $h($what) . '</a></p>';
                $text .= "- $what: $link\n";
            }
        }

        // Open roles
        $html .= '<h2 style="' . $h2 . '">Open roles</h2>';
        $text .= "\nOPEN ROLES\n";
        if (!$roleRows) {
            $html .= '<p style="' . $p . '">No open roles.</p>';
            $text .= "No open roles.\n";
        }
        foreach ($roleRows as ['role' => $r, 'stats' => $s, 'matches' => $m]) {
            $html .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 12px 0; border:1px solid #E7E2EF;"><tr><td style="padding:12px 14px;">'
                . '<p style="margin:0 0 4px 0; font-family:Georgia, serif; font-size:16px;"><a href="' . $h("$url/roles/{$r['id']}") . '" style="color:#320A67; text-decoration:none;">' . $h($r['title']) . '</a>'
                . ' <span style="font-family:Arial, sans-serif; font-size:12px; color:#6E6780;">' . $h($r['client']) . ' · ' . $h($r['code']) . '</span></p>'
                . '<p style="margin:0 0 6px 0; font-family:Arial, sans-serif; font-size:13px; color:#2A2238;">' . (int) $s['total'] . ' on the role · ' . (int) $s['new_week'] . ' new this week · ' . (int) $s['above'] . ' above the threshold</p>';
            $text .= "\n{$r['title']} ({$r['client']}, {$r['code']}): " . (int) $s['total'] . ' on the role, ' . (int) $s['new_week'] . ' new this week, ' . (int) $s['above'] . " above the threshold\n$url/roles/{$r['id']}\n";
            if ($m) {
                $html .= '<p style="margin:6px 0 2px 0; font-family:Arial, sans-serif; font-size:13px; color:#573A73;"><b>Already in your database, not yet on this role:</b></p>';
                $text .= "  Already in your database:\n";
                foreach ($m as $x) {
                    $c = $x['cand'];
                    $html .= '<p style="margin:0; font-family:Arial, sans-serif; font-size:13px; line-height:20px;">• <a href="' . $h("$url/candidates/{$c['id']}") . '" style="color:#7946B2;">' . $h($c['full_name'] ?: 'Name not found') . '</a> · score ' . (int) $x['score']
                        . ($c['location'] ? ' · ' . $h($c['location']) : '') . (exp_now($c) !== null ? ' · ' . $h(experience(exp_now($c))) : '') . '</p>';
                    $text .= '  - ' . ($c['full_name'] ?: 'Name not found') . ' (score ' . (int) $x['score'] . "): $url/candidates/{$c['id']}\n";
                }
                $html .= '<p style="margin:6px 0 0 0; font-family:Arial, sans-serif; font-size:12px;"><a href="' . $h("$url/roles/{$r['id']}/matches") . '" style="color:#7946B2;">See all matches</a></p>';
            }
            $html .= '</td></tr></table>';
        }
        $html .= '<p style="margin:18px 0 0 0; font-family:Arial, sans-serif; font-size:12px; color:#6E6780;">Sent every Monday morning by Eshcol Recruit. Switch it off in Settings › Email.</p>'
            . '</td></tr></table></td></tr></table></body></html>';
        $text .= "\nSent every Monday morning by Eshcol Recruit. Switch it off in Settings > Email.\n";
        return [$subject, $html, $text];
    }

    /** Queues the summary to every active owner. Returns how many were queued. */
    public function send(?array $onlyUser = null): int
    {
        [$subject, $html, $text] = $this->build();
        $users = $onlyUser ? [$onlyUser] : $this->app->db->all("SELECT id, name, email FROM users WHERE role = 'owner' AND is_active = 1");
        $m = new Mailer($this->app);
        foreach ($users as $u) {
            $m->enqueue(['kind' => 'digest', 'to_email' => $u['email'], 'to_name' => $u['name'], 'subject' => $subject, 'html' => $html, 'text' => $text, 'priority' => 40]);
        }
        return count($users);
    }
}
