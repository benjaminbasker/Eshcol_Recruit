<?php
declare(strict_types=1);

namespace Eshcol\Ats\Controllers;

use Eshcol\Ats\Audit;
use Eshcol\Ats\Auth;
use Eshcol\Ats\Http;
use Eshcol\Ats\Secrets;
use Eshcol\Ats\Services\CvAnalyst;

/** "Analyse with AI" on a candidate, and its settings. */
final class AiController extends Controller
{
    public function analyse(int $id): never
    {
        $this->findOr404('candidates', $id);
        @set_time_limit(180);
        try {
            (new CvAnalyst($this->app))->analyse($id, (int) Auth::user()['id']);
            Http::flash('ok', 'AI analysis ready.');
        } catch (\RuntimeException $e) {
            Http::flash('error', $e->getMessage());
        }
        Http::redirect("/candidates/$id#report");
    }

    /** Data for the Settings card. */
    public static function viewData(\Eshcol\Ats\App $app): array
    {
        $a = new CvAnalyst($app);
        return [
            'hasKey' => Secrets::has($app, 'anthropic_api_key'),
            'on' => $app->setting('ai_enabled', '1') === '1',
            'limit' => $a->limit(),
            'used' => $a->usedThisMonth(),
            'costMonth' => (float) $app->db->col("SELECT COALESCE(SUM(cost_usd), 0) FROM cv_ai_reports WHERE created_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')"),
        ];
    }

    public function save(): never
    {
        Auth::requireOwner();
        if (isset($_POST['forget'])) {
            Secrets::forget($this->app, 'anthropic_api_key');
            Audit::log('ai_key_removed');
            Http::flash('info', 'Claude API key removed. The free CV report keeps working.');
            Http::redirect('/settings#ai');
        }
        $key = trim((string) ($_POST['api_key'] ?? ''));
        if ($key !== '' && !preg_match('/^sk-ant-[A-Za-z0-9_\-]{20,}$/', $key)) {
            Http::flash('error', 'That doesn\'t look like a Claude API key (it starts with sk-ant-). Nothing was saved.');
            Http::redirect('/settings#ai');
        }
        if ($key !== '') {
            Secrets::put($this->app, 'anthropic_api_key', $key);
        }
        $limit = max(0, min(5000, (int) ($_POST['limit'] ?? 150)));
        foreach (['ai_enabled' => isset($_POST['enabled']) ? '1' : '0', 'ai_monthly_limit' => (string) $limit] as $k => $v) {
            $this->db->run('INSERT INTO settings (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)', [$k, $v]);
        }
        Audit::log('ai_settings_saved', null, null, ['limit' => $limit, 'key_changed' => $key !== '']);
        Http::flash('ok', 'Saved.' . ($key !== '' ? ' The key is stored encrypted. Try it with "Analyse with AI" on any candidate.' : ''));
        Http::redirect('/settings#ai');
    }
}
