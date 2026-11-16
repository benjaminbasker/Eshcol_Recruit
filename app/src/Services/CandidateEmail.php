<?php
declare(strict_types=1);

namespace Eshcol\Ats\Services;

/**
 * The house design for emails to candidates (approved by Benjamin, 7 Nov 2026):
 * white logo band, aubergine top panel with a gold eyebrow and a Georgia headline, a gold-edged
 * action box, the three-step "What happens next", his signature with a gold side rule, two link
 * cards, text-only social links (they show even when images are blocked), aubergine footer.
 * No photos: many inboxes block images, and a solid panel always shows.
 *
 * Built from parts, so every email stays the same shape. Returns the HTML and the plain-text twin.
 * House style: no em dashes.
 *
 * Text values are plain text and are escaped here. Values ending in "_html" are trusted HTML
 * written by the app (never candidate input), with a "_text" twin for the plain-text part.
 */
final class CandidateEmail
{
    public const AUB = '#320A67';
    public const PLUM = '#573A73';
    public const GRAPE = '#7946B2';
    public const GOLD = '#FFB85C';
    public const SAND = '#D8B36A';
    public const SOFTSAND = '#E8D3A4';
    public const CREAM = '#F2EFE7';
    public const INK = '#2A2238';
    public const MUTED = '#6E6780';

    private const F = 'font-family:Arial, Helvetica, sans-serif;';
    private const SERIF = "font-family:Georgia, 'Times New Roman', serif;";

    public const LINKS = [
        'linkedin' => 'https://www.linkedin.com/company/eshcolglobal/',
        'youtube' => 'https://www.youtube.com/@eshcolglobal',
        'facebook' => 'https://www.facebook.com/eshcolglobal/',
        'jobs' => 'https://www.eshcolglobal.com/jobs/',
        'insights' => 'https://www.eshcolglobal.com/insights/',
        'privacy' => 'https://www.eshcolglobal.com/privacy/',
        'site' => 'https://www.eshcolglobal.com/',
        'logo' => 'https://www.eshcolglobal.com/images/eshcol-logo-600.png',
    ];

    /**
     * The "Follow us on LinkedIn" button for the plain, letter-style emails (messages, interviews), which
     * Benjamin wants on every email to a candidate (14 Nov 2026). [html, text]
     */
    public static function linkedinPlain(): array
    {
        $u = self::LINKS['linkedin'];
        return ['<p style="margin:22px 0 0 0; font-family:Calibri, Arial, Helvetica, sans-serif; font-size:14px; line-height:21px; color:#555555;">We post new openings on LinkedIn first.</p>'
            . '<p style="margin:8px 0 0 0;"><a href="' . $u . '" target="_blank" style="display:inline-block; padding:9px 18px; background:#0A66C2; color:#FFFFFF; text-decoration:none; border-radius:6px; font-family:Arial, Helvetica, sans-serif; font-size:14px; font-weight:bold;">Follow us on LinkedIn</a></p>',
            "\nWe post new openings on LinkedIn first. Follow us: $u\n"];
    }

    /** A plain-text letter (for example the editable call letter) as safe HTML: escaped, line breaks kept, links clickable, "- " lines as bullets. */
    public static function plainHtml(string $text): string
    {
        $x = nl2br(self::h(trim($text)));
        // A link ends before trailing punctuation: "(https://example.in/)," keeps its ")," outside the link.
        $x = (string) preg_replace('#(https?://[^\s<]+?)([).,;:!?]*)(?=\s|<|$)#', '<a href="$1" target="_blank" style="color:' . self::GRAPE . ';">$1</a>$2', $x);
        return (string) preg_replace('#(^|<br />\s*)- #', '$1&bull;&nbsp;', $x);
    }

    public static function h(?string $v): string
    {
        return htmlspecialchars((string) $v, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /**
     * @param array{
     *   title:string, preheader:string, eyebrow:string, headline:string, sub_html:string, sub_text:string,
     *   action?:array{kicker?:string, paras?:array<int,array{0:string,1:string}>, list?:string[], buttons?:array<int,array{0:string,1:string,2?:bool}>, small?:string},
     *   steps?:bool, after?:array<int,array{0:string,1:string}>, cards?:bool, about?:?array{0:string,1:string}, footer_reason?:string
     * } $p
     * @return array{0:string,1:string} html, text
     */
    public static function render(array $p): array
    {
        $h = fn ($v) => self::h($v);
        $f = self::F;
        $serif = self::SERIF;
        $html = [];
        $text = [];

        // ---- top panel
        $html[] = '<tr><td class="px" bgcolor="' . self::AUB . '" style="padding:34px 40px 36px 40px; background-color:' . self::AUB . ';">'
            . '<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 12px 0;"><tr>'
            . '<td width="28" valign="middle" style="width:28px;"><table role="presentation" cellpadding="0" cellspacing="0" border="0"><tr><td width="28" height="3" bgcolor="' . self::GOLD . '" style="width:28px; height:3px; line-height:3px; font-size:3px; background-color:' . self::GOLD . ';">&nbsp;</td></tr></table></td>'
            . '<td valign="middle" style="padding-left:10px; ' . $f . ' font-size:13px; line-height:18px; letter-spacing:1.5px; text-transform:uppercase; font-weight:bold; color:' . self::GOLD . ';">' . $h($p['eyebrow']) . '</td>'
            . '</tr></table>'
            . '<h1 class="hero-h1" style="margin:0 0 14px 0; ' . $serif . ' font-size:32px; line-height:38px; font-weight:normal; color:#FFFFFF;">' . $h($p['headline']) . '</h1>'
            . '<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 16px 0;"><tr><td width="56" height="3" bgcolor="' . self::GOLD . '" style="width:56px; height:3px; line-height:3px; font-size:3px; background-color:' . self::GOLD . ';">&nbsp;</td></tr></table>'
            . '<p style="margin:0; ' . $f . ' font-size:16px; line-height:24px; color:#E8E0F4;">' . $p['sub_html'] . '</p>'
            . '</td></tr>';
        $text[] = $p['headline'];
        $text[] = '';
        $text[] = $p['sub_text'];

        // ---- details card (interview emails, 16 Nov 2026): label / value rows, then optional buttons
        if (!empty($p['details'])) {
            $rows = '';
            $text[] = '';
            foreach ($p['details'] as [$label, $vh, $vt]) {
                $rows .= '<tr><td width="90" valign="top" style="width:90px; padding:6px 12px 6px 0; ' . $f . ' font-size:12px; line-height:20px; letter-spacing:1.2px; text-transform:uppercase; font-weight:bold; color:' . self::PLUM . ';">' . $h($label) . '</td>'
                    . '<td valign="top" style="padding:6px 0; ' . $f . ' font-size:15px; line-height:22px; color:' . self::INK . ';">' . $vh . '</td></tr>';
                $text[] = $label . ': ' . $vt;
            }
            $btns = '';
            foreach ($p['details_buttons'] ?? [] as $i => [$label, $url]) {
                $btns .= ($i ? '<td width="10" class="btn-gap" style="width:10px;">&nbsp;</td>' : '') . '<td class="btn-cell" style="padding:10px 0 0 0;"><table role="presentation" cellpadding="0" cellspacing="0" border="0"><tr>'
                    . '<td bgcolor="' . self::GRAPE . '" style="background-color:' . self::GRAPE . '; border-radius:6px; mso-padding-alt:12px 22px;"><a href="' . $h($url) . '" target="_blank" style="display:inline-block; padding:12px 22px; ' . $f . ' font-size:15px; line-height:20px; font-weight:bold; color:#FFFFFF; text-decoration:none; border-radius:6px;">' . $h($label) . '</a></td></tr></table></td>';
                $text[] = $label . ': ' . $url;
            }
            $html[] = '<tr><td class="px" style="padding:28px 40px 0 40px;"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr>'
                . '<td style="padding:14px 20px 16px 20px; border:1px solid #E2D8F1; background-color:#FFFFFF;"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">' . $rows . '</table>'
                . ($btns ? '<table role="presentation" cellpadding="0" cellspacing="0" border="0"><tr>' . $btns . '</tr></table>' : '') . '</td></tr></table></td></tr>';
        }

        // ---- the action box
        if (!empty($p['action'])) {
            $a = $p['action'];
            $in = '';
            $text[] = '';
            if (!empty($a['kicker'])) {
                $in .= '<p style="margin:0 0 8px 0; ' . $f . ' font-size:12px; line-height:16px; letter-spacing:1.5px; text-transform:uppercase; font-weight:bold; color:' . self::PLUM . ';">' . $h($a['kicker']) . '</p>';
                $text[] = mb_strtoupper($a['kicker']);
                $text[] = '';
            }
            foreach ($a['paras'] ?? [] as [$ph, $pt]) {
                $in .= '<p style="margin:0 0 12px 0; ' . $f . ' font-size:15px; line-height:24px; color:' . self::INK . ';">' . $ph . '</p>';
                $text[] = $pt;
                $text[] = '';
            }
            if (!empty($a['list'])) {
                $in .= '<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 16px 0;">';
                foreach ($a['list'] as $li) {
                    $in .= '<tr><td width="22" valign="top" style="width:22px; padding:3px 0; ' . $f . ' font-size:15px; line-height:22px; font-weight:bold; color:' . self::GRAPE . ';">&#10003;</td>'
                        . '<td valign="top" style="padding:3px 0; ' . $f . ' font-size:15px; line-height:22px; color:' . self::INK . ';">' . $h($li) . '</td></tr>';
                    $text[] = '- ' . $li;
                }
                $in .= '</table>';
                $text[] = '';
            }
            if (!empty($a['buttons'])) {
                $in .= '<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 4px 0;"><tr>';
                foreach ($a['buttons'] as $i => $b) {
                    [$label, $url] = $b;
                    $primary = $b[2] ?? true;
                    $in .= ($i > 0 ? '<td width="10" class="btn-gap" style="width:10px;">&nbsp;</td>' : '')
                        . '<td class="btn-cell" style="padding:0 0 8px 0;"><table role="presentation" cellpadding="0" cellspacing="0" border="0"><tr>'
                        . ($primary
                            ? '<td bgcolor="' . self::GRAPE . '" style="background-color:' . self::GRAPE . '; border-radius:6px; mso-padding-alt:13px 24px;"><a href="' . $h($url) . '" target="_blank" style="display:inline-block; padding:13px 24px; ' . $f . ' font-size:16px; line-height:20px; font-weight:bold; color:#FFFFFF; text-decoration:none; border-radius:6px;">' . $h($label) . '</a></td>'
                            : '<td bgcolor="#FFFFFF" style="background-color:#FFFFFF; border:2px solid ' . self::GRAPE . '; border-radius:6px; mso-padding-alt:11px 22px;"><a href="' . $h($url) . '" target="_blank" style="display:inline-block; padding:11px 22px; ' . $f . ' font-size:16px; line-height:20px; font-weight:bold; color:' . self::PLUM . '; text-decoration:none; border-radius:6px;">' . $h($label) . '</a></td>')
                        . '</tr></table></td>';
                    $text[] = $label . ':';
                    $text[] = $url;
                    $text[] = '';
                }
                $in .= '</tr></table>';
            }
            if (!empty($a['small'])) {
                $in .= '<p style="margin:6px 0 0 0; ' . $f . ' font-size:13px; line-height:20px; color:' . self::MUTED . ';">' . $h($a['small']) . '</p>';
                $text[] = $a['small'];
            }
            $html[] = '<tr><td class="px" style="padding:30px 40px 6px 40px;"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr>'
                . '<td bgcolor="#FFF8EC" style="padding:22px 24px 18px 24px; background-color:#FFF8EC; border:1px solid ' . self::SOFTSAND . '; border-left:4px solid ' . self::SAND . ';">' . $in . '</td>'
                . '</tr></table></td></tr>';
        }

        // ---- what happens next
        if (!empty($p['steps'])) {
            $steps = is_array($p['steps']) ? $p['steps'] : [
                ['You complete your application', 'Your salary, joining time and a quick check of your details.'],
                ['We match you with the role', 'Our team reviews your profile against ' . ($p['requirements'] ?? "our client's requirements") . '.'], // by type of position (15 Nov 2026)
                ['We get in touch', 'If your profile is a good match, we contact you about next steps.'],
            ];
            $cells = '';
            $text[] = '';
            $text[] = 'WHAT HAPPENS NEXT';
            $text[] = '';
            foreach ($steps as $i => [$t, $d]) {
                $cells .= '<td class="stack' . ($i ? ' stack-gap' : '') . '" width="33%" valign="top" style="width:33%; padding:0 ' . ($i < 2 ? '14px' : '0') . ' 0 0;">'
                    . '<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 8px 0;"><tr><td width="34" height="34" align="center" valign="middle" bgcolor="' . self::GRAPE . '" style="width:34px; height:34px; border-radius:17px; background-color:' . self::GRAPE . '; ' . $f . ' font-size:15px; font-weight:bold; color:#FFFFFF;">' . ($i + 1) . '</td></tr></table>'
                    . '<p style="margin:0 0 4px 0; ' . $f . ' font-size:15px; line-height:20px; font-weight:bold; color:' . self::AUB . ';">' . $h($t) . '</p>'
                    . '<p style="margin:0; ' . $f . ' font-size:14px; line-height:20px; color:#4A4358;">' . $h($d) . '</p></td>';
                $text[] = ($i + 1) . '. ' . $t . '. ' . $d;
            }
            $html[] = '<tr><td class="px" style="padding:30px 40px 6px 40px;">'
                . '<h2 style="margin:0 0 14px 0; ' . $serif . ' font-size:21px; line-height:27px; font-weight:normal; color:' . self::GRAPE . ';">What happens next</h2>'
                . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr>' . $cells . '</tr></table></td></tr>';
        }

        // ---- closing paragraphs
        if (!empty($p['after'])) {
            $in = '';
            $text[] = '';
            foreach ($p['after'] as [$ph, $pt]) {
                $in .= '<p style="margin:0 0 14px 0; ' . $f . ' font-size:15px; line-height:24px; color:' . self::INK . ';">' . $ph . '</p>';
                $text[] = $pt;
                $text[] = '';
            }
            $html[] = '<tr><td class="px" style="padding:18px 40px 0 40px;">' . $in . '</td></tr>';
        }

        // ---- signature: Benjamin's, unless the email is from someone else (an interview booked by a recruiter)
        $sigName = (string) ($p['signature']['name'] ?? 'Benjamin Basker');
        $sigLines = (array) ($p['signature']['lines'] ?? ['Founder and Managing Director', 'Eshcol Global Services (P) Ltd']);
        $html[] = '<tr><td class="px" style="padding:18px 40px 0 40px;"><table role="presentation" cellpadding="0" cellspacing="0" border="0"><tr>'
            . '<td width="3" bgcolor="' . self::SAND . '" style="width:3px; background-color:' . self::SAND . ';">&nbsp;</td>'
            . '<td style="padding:4px 0 4px 18px;">'
            . '<p style="margin:0 0 6px 0; ' . $f . ' font-size:15px; line-height:22px; color:' . self::INK . ';">Warm regards,</p>'
            . '<p style="margin:0 0 4px 0; ' . $serif . ' font-size:20px; line-height:26px; color:' . self::AUB . ';">' . $h($sigName) . '</p>'
            . '<p style="margin:0; ' . $f . ' font-size:14px; line-height:21px; color:' . self::MUTED . ';">' . implode('<br>', array_map($h, $sigLines)) . '</p>'
            . '</td></tr></table></td></tr>';
        $text[] = '';
        $text[] = 'Warm regards,';
        $text[] = '';
        $text[] = $sigName;
        foreach ($sigLines as $l) {
            $text[] = $l;
        }

        // ---- Follow us on LinkedIn: on every candidate email (Benjamin's standing rule, 14 Nov 2026).
        // The thank-you email's link cards already carry it, so it is not repeated there.
        if (empty($p['cards'])) {
            $html[] = '<tr><td class="px" style="padding:24px 40px 0 40px;"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr>'
                . '<td bgcolor="#F1EBF9" style="padding:16px 18px; background-color:#F1EBF9; border:1px solid #E2D8F1;">'
                . '<p style="margin:0 0 10px 0; ' . $f . ' font-size:14px; line-height:21px; color:#4A4358;"><strong style="color:' . self::AUB . ';">Hear about new openings first.</strong> We post them on LinkedIn as they open.</p>'
                . '<table role="presentation" cellpadding="0" cellspacing="0" border="0"><tr><td bgcolor="#0A66C2" style="background-color:#0A66C2; border-radius:6px; mso-padding-alt:10px 20px;">'
                . '<a href="' . self::LINKS['linkedin'] . '" target="_blank" style="display:inline-block; padding:10px 20px; ' . $f . ' font-size:15px; line-height:18px; font-weight:bold; color:#FFFFFF; text-decoration:none; border-radius:6px;">Follow us on LinkedIn</a>'
                . '</td></tr></table></td></tr></table></td></tr>';
            $text[] = '';
            $text[] = 'Hear about new openings first. Follow us on LinkedIn:';
            $text[] = self::LINKS['linkedin'];
        }

        // ---- link cards
        if (!empty($p['cards'])) {
            $card = fn (string $t, string $d, string $l, string $u) => '<td bgcolor="#F1EBF9" valign="top" style="padding:16px 18px; background-color:#F1EBF9; border:1px solid #E2D8F1;">'
                . '<p style="margin:0 0 6px 0; ' . $serif . ' font-size:16px; line-height:22px; color:' . self::AUB . ';">' . $h($t) . '</p>'
                . '<p style="margin:0 0 10px 0; ' . $f . ' font-size:14px; line-height:20px; color:#4A4358;">' . $h($d) . '</p>'
                . '<a href="' . $h($u) . '" target="_blank" style="' . $f . ' font-size:14px; font-weight:bold; color:' . self::GRAPE . '; text-decoration:none;">' . $h($l) . ' &rarr;</a></td>';
            $html[] = '<tr><td class="px" style="padding:28px 40px 0 40px;"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr>'
                . '<td class="stack" width="50%" valign="top" style="width:50%; padding-right:6px;"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr>'
                . $card('Follow Eshcol Global on LinkedIn', 'We share new roles there as they open, so you hear about the next one early.', 'Follow us', self::LINKS['linkedin'])
                . '</tr></table></td>'
                . '<td class="stack stack-gap" width="50%" valign="top" style="width:50%; padding-left:6px;"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr>'
                . $card('Read Eshcol Insights', 'Practical articles on careers and the workplace.', 'Explore insights', self::LINKS['insights'])
                . '</tr></table></td></tr></table>'
                . '<p style="margin:14px 0 0 0; ' . $f . ' font-size:14px; line-height:22px; color:' . self::INK . ';">Another role may suit you even better: <a href="' . self::LINKS['jobs'] . '" target="_blank" style="color:' . self::GRAPE . ';">see all current openings</a>.</p>'
                . '</td></tr>';
            $text[] = '';
            $text[] = 'WHILE YOU WAIT';
            $text[] = '';
            $text[] = 'Follow Eshcol Global on LinkedIn. We share new roles there as they open:';
            $text[] = self::LINKS['linkedin'];
            $text[] = '';
            $text[] = 'Read Eshcol Insights, practical articles on careers and the workplace:';
            $text[] = self::LINKS['insights'];
            $text[] = '';
            $text[] = 'Another role may suit you even better. See all current openings:';
            $text[] = self::LINKS['jobs'];
        }

        // ---- about your details
        if (!empty($p['about'])) {
            [$ah, $at] = $p['about'];
            $html[] = '<tr><td class="px" style="padding:26px 40px 0 40px;"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr>'
                . '<td style="padding-top:20px; border-top:1px solid #E7E2EF;">'
                . '<p style="margin:0 0 8px 0; ' . $f . ' font-size:13px; line-height:18px; letter-spacing:1.2px; text-transform:uppercase; font-weight:bold; color:' . self::PLUM . ';">About your details</p>'
                . '<p style="margin:0; ' . $f . ' font-size:14px; line-height:22px; color:#4A4358;">' . $ah . '</p></td></tr></table></td></tr>';
            $text[] = '';
            $text[] = 'ABOUT YOUR DETAILS';
            $text[] = '';
            $text[] = $at;
        }

        // ---- social (text pills) and footer
        $pill = fn (string $l, string $u) => '<td style="padding:0 8px 8px 0;"><a href="' . $h($u) . '" target="_blank" style="display:inline-block; padding:6px 14px; border:1px solid #D5CCE3; border-radius:16px; ' . $f . ' font-size:13px; line-height:16px; color:' . self::PLUM . '; text-decoration:none;">' . $h($l) . '</a></td>';
        $html[] = '<tr><td class="px" style="padding:22px 40px 18px 40px;"><table role="presentation" cellpadding="0" cellspacing="0" border="0"><tr>'
            . '<td style="padding:0 10px 8px 0; ' . $f . ' font-size:13px; color:' . self::MUTED . ';">Find us on</td>'
            . $pill('LinkedIn', self::LINKS['linkedin']) . $pill('YouTube', self::LINKS['youtube']) . $pill('Facebook', self::LINKS['facebook'])
            . '</tr></table></td></tr>';
        $reason = $p['footer_reason'] ?? 'You are receiving this email because you applied for a role that Eshcol Global is recruiting for on behalf of our client.';
        $html[] = '<tr><td class="px" bgcolor="' . self::AUB . '" style="padding:22px 40px; background-color:' . self::AUB . '; ' . $f . ' font-size:12px; line-height:19px; color:#CFC3E3;">'
            . $h($reason) . ' <a href="' . self::LINKS['privacy'] . '" target="_blank" style="color:' . self::GOLD . ';">How we use your details</a><br><br>'
            . 'Eshcol Global Services Private Limited, #714A, Cabin # 3, Spencer Plaza, 7th Floor, Phase-II, #769, Anna Salai, Thousand Lights, Chennai - 600 002, Tamil Nadu, India'
            . '</td></tr>';
        $text[] = '';
        $text[] = 'LinkedIn: ' . self::LINKS['linkedin'];
        $text[] = 'YouTube: ' . self::LINKS['youtube'];
        $text[] = 'Facebook: ' . self::LINKS['facebook'];
        $text[] = '';
        $text[] = '--';
        $text[] = $reason;
        $text[] = 'How we use your details: ' . self::LINKS['privacy'];
        $text[] = 'Eshcol Global Services Private Limited, #714A, Cabin # 3, Spencer Plaza, 7th Floor, Phase-II, #769, Anna Salai, Thousand Lights, Chennai - 600 002, Tamil Nadu, India';

        return [self::page($p['title'], $p['preheader'], implode("\n", $html)), implode("\n", $text) . "\n"];
    }

    private static function page(string $title, string $preheader, string $rows): string
    {
        $h = fn ($v) => self::h($v);
        $f = self::F;
        $cream = self::CREAM;
        return <<<HTML
<!DOCTYPE html>
<html lang="en" xmlns="http://www.w3.org/1999/xhtml" xmlns:v="urn:schemas-microsoft-com:vml" xmlns:o="urn:schemas-microsoft-com:office:office">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta http-equiv="X-UA-Compatible" content="IE=edge">
<meta name="color-scheme" content="light">
<meta name="supported-color-schemes" content="light">
<title>{$h($title)}</title>
<!--[if mso]>
<noscript><xml><o:OfficeDocumentSettings><o:PixelsPerInch>96</o:PixelsPerInch></o:OfficeDocumentSettings></xml></noscript>
<![endif]-->
<style>
  body { margin:0; padding:0; width:100% !important; -webkit-text-size-adjust:100%; -ms-text-size-adjust:100%; }
  table { border-collapse:collapse; mso-table-lspace:0pt; mso-table-rspace:0pt; }
  img { border:0; outline:none; text-decoration:none; -ms-interpolation-mode:bicubic; display:block; }
  a { color:#7946B2; }
  @media only screen and (max-width:660px) {
    .container { width:100% !important; }
    .px { padding-left:22px !important; padding-right:22px !important; }
    .hero-h1 { font-size:28px !important; line-height:34px !important; }
    .stack { display:block !important; width:100% !important; padding-left:0 !important; padding-right:0 !important; }
    .stack-gap { padding-top:14px !important; }
    .tagline { display:none !important; }
    .btn-cell { display:block !important; }
    .btn-gap { display:none !important; }
  }
</style>
</head>
<body style="margin:0; padding:0; background-color:{$cream};">
<div style="display:none; max-height:0; overflow:hidden; mso-hide:all; font-size:1px; line-height:1px; color:{$cream}; opacity:0;">{$h($preheader)}&#8199;&#847;&#8199;&#847;&#8199;&#847;&#8199;&#847;&#8199;&#847;&#8199;&#847;&#8199;&#847;&#8199;&#847;</div>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="{$cream}" style="background-color:{$cream};">
<tr><td align="center" style="padding:28px 10px;">
  <!--[if mso]><table role="presentation" width="640" align="center" cellpadding="0" cellspacing="0" border="0"><tr><td><![endif]-->
  <table role="presentation" class="container" width="100%" align="center" cellpadding="0" cellspacing="0" border="0" style="width:100%; max-width:640px; margin:0 auto; background-color:#FFFFFF; border:1px solid #E8D3A4;">
    <tr><td height="4" bgcolor="#D8B36A" style="height:4px; line-height:4px; font-size:4px; background-color:#D8B36A;">&nbsp;</td></tr>
    <tr><td class="px" style="padding:22px 40px 18px 40px; background-color:#FFFFFF;">
      <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr>
        <td valign="middle"><a href="https://www.eshcolglobal.com/" style="text-decoration:none;"><img src="https://www.eshcolglobal.com/images/eshcol-logo-600.png" width="150" height="74" alt="Eshcol Global: People, Property" style="width:150px; height:auto; max-width:150px;"></a></td>
        <td class="tagline" align="right" valign="middle" style="{$f} font-size:11px; line-height:16px; letter-spacing:2px; font-weight:bold; color:#573A73;">PEOPLE &nbsp;/&nbsp; PROPERTY &nbsp;/&nbsp; GLOBAL</td>
      </tr></table>
    </td></tr>
{$rows}
  </table>
  <!--[if mso]></td></tr></table><![endif]-->
</td></tr>
</table>
</body>
</html>
HTML;
    }
}
