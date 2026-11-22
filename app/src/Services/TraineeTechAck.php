<?php
declare(strict_types=1);

namespace Eshcol\Ats\Services;

/**
 * The thank-you email for the Trainee Technicians hiring only (Benjamin, 22 Nov 2026; draft and colour
 * options in E:/Claude/Eshcol/Recruit_TraineeTech_Ack_Colour_Options_22Nov2026/, he chose C "Lilac Dream").
 *
 * Written for a Gen Z audience: a "You're in" headline with a LinkedIn Follow card beside it, a five-level
 * progress tracker, the details link as "Level 2", three reminders of the role, a LinkedIn "side quest"
 * and a "share with your batch" WhatsApp button. It deliberately does not use the brand colours.
 *
 * Used only when every position in the email is a Trainee Technicians position (title starts with
 * "Trainee Technicians"); any other mix gets the house email. Setting `ack_trainee_style` = 0 turns it off.
 *
 * Public wording rules (handover 22 Nov 2026 §3): "(On Contract)", Eshcol's payroll, never the client's
 * name, public pay only, apply only via the job page or resume@, no WhatsApp applications, no em dashes.
 */
final class TraineeTechAck
{
    public const TITLE_PREFIX = 'Trainee Technicians';
    public const PUBLIC_TITLE = 'Trainee Technicians (On Contract)';
    public const JOB_URL = 'https://www.eshcolglobal.com/jobs/trainee-technicians/';

    // Option C, Lilac Dream
    private const BG = '#EEEBFB';
    private const CARD = '#FCFBFF';
    private const INK = '#262056';
    private const TEXT = '#4A4573';
    private const MUTED = '#8F8AB0';
    private const LINE = '#E0DCF5';
    private const TILE_BD = '#E3DFF7';
    private const P = '#6159D8';
    private const P_SOFT = '#E3E0FF';
    private const P_DARK = '#433BB0';
    private const S = '#F07BA0';
    private const S_SOFT = '#FFE6EE';
    private const S_DARK = '#A9385D';
    private const LINK = '#5048C4';
    private const LI_BLUE = '#0A66C2';

    private const F = 'font-family:Arial, Helvetica, sans-serif;';
    private const HF = "font-family:'Arial Black', Arial, Helvetica, sans-serif; font-weight:900;";

    /** True when this email is for Trainee Technicians positions only. */
    public static function applies(array $roles): bool
    {
        if (!$roles) {
            return false;
        }
        foreach ($roles as $r) {
            if (stripos(trim((string) ($r['title'] ?? '')), self::TITLE_PREFIX) !== 0) {
                return false;
            }
        }
        return true;
    }

    /**
     * @param string|null $link the personal details link, or null when there is nothing left to fill in
     * @return array{0:string,1:string,2:string} subject, html, text
     */
    public static function render(string $first, ?string $link, string $type): array
    {
        $h = fn ($v) => CandidateEmail::h($v);
        $f = self::F;
        $hf = self::HF;
        $L = CandidateEmail::LINKS;
        $name = $first !== '' ? $h($first) . '.' : 'welcome.';
        $subject = $link
            ? ($first !== '' ? "You're in, $first: one step left for " . self::PUBLIC_TITLE : "You're in: one step left for " . self::PUBLIC_TITLE)
            : "You're in: we've received your application for " . self::PUBLIC_TITLE;
        $preheader = $link ? "You're in. Level 2 takes about 2 minutes, and there's a side quest at the bottom."
                           : "You're in. Here's what happens next, plus a side quest at the bottom.";
        $about = 'If this role turns out not to be the right fit, we may consider you for other suitable roles ' . Engagement::otherRoles($type) . ' over the next five years, as our privacy policy explains.';
        $remove = 'If you would rather we didn\'t, just reply to this email with "Please remove me" and we will delete your details.';
        $reason = Engagement::footerReason($type);
        $wa = 'https://wa.me/?text=' . rawurlencode('Saw this and thought of you: ' . self::PUBLIC_TITLE . ', 5 days a week, for engineering freshers. Apply here: ' . self::JOB_URL);

        $chip = fn ($bg, $fg, $t) => "<span style=\"display:inline-block; background:$bg; color:$fg; $f font-size:12px; font-weight:bold; letter-spacing:1.5px; padding:7px 14px; border-radius:30px;\">$t</span>";
        $label = fn ($t) => "<p style=\"margin:0 0 12px 0; $f font-size:12px; font-weight:bold; letter-spacing:1.5px; color:" . self::MUTED . ";\">$t</p>";
        $tile = fn ($e, $t, $s) => '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border-collapse:separate; background:#FFFFFF; border:1px solid ' . self::TILE_BD . "; border-radius:16px;\"><tr><td style=\"padding:16px; $f\">"
            . "<p style=\"margin:0; font-size:26px; line-height:30px;\">$e</p><p style=\"margin:6px 0 0 0; font-size:17px; line-height:21px; font-weight:bold; color:" . self::INK . ";\">$t</p>"
            . "<p style=\"margin:4px 0 0 0; font-size:13px; line-height:18px; color:" . self::MUTED . ";\">$s</p></td></tr></table>";
        $here = '<span style="display:inline-block; background:' . self::S . '; color:#FFFFFF; font-size:11px; font-weight:bold; letter-spacing:1px; padding:3px 8px; border-radius:20px; margin-left:4px;">YOU ARE HERE</span>';
        $done = ' <span style="color:' . self::P . '; font-weight:bold;">&middot; done</span>';
        $lvl = fn ($e, $n, $t, $state) => "<tr><td style=\"padding:7px 0; $f font-size:15px; line-height:20px; color:" . ($state === 'todo' ? self::MUTED : self::INK) . ";\">$e &nbsp;<b>Level $n:</b> $t"
            . ($state === 'done' ? $done : ($state === 'here' ? " $here" : '')) . '</td></tr>';
        $btn = fn ($bg, $fg, $t, $u, $pad = '15px 30px', $size = 16) => '<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin-top:18px;"><tr><td class="btn" bgcolor="' . $bg . '" style="background:' . $bg . '; border-radius:40px;">'
            . '<a href="' . $h($u) . "\" target=\"_blank\" style=\"display:inline-block; padding:$pad; $f font-size:{$size}px; font-weight:bold; color:$fg; text-decoration:none;\">$t</a></td></tr></table>";
        $bar = fn ($c, $r = '') => "<td width=\"20%\" height=\"8\" style=\"background:$c;" . ($r ? " border-radius:$r;" : '') . ' font-size:0; line-height:0;">&nbsp;</td>';

        $rows = [];
        // top bar
        $rows[] = '<tr><td height="6" style="height:6px; line-height:6px; font-size:6px; background:' . self::P . ';">&nbsp;</td></tr>';
        $rows[] = '<tr><td class="px" style="padding:22px 32px 0 32px;"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr>'
            . '<td valign="middle"><a href="' . $L['site'] . '" style="text-decoration:none;"><img src="' . $L['logo'] . '" width="110" alt="Eshcol Global" style="width:110px; height:auto;"></a></td>'
            . "<td align=\"right\" valign=\"middle\" style=\"$f font-size:12px; font-weight:bold; letter-spacing:1.5px; color:" . self::MUTED . ';">#ESHCOLHIRING</td></tr></table></td></tr>';
        // hero + LinkedIn follow card
        $rows[] = '<tr><td class="px" style="padding:30px 32px 8px 32px;"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr>'
            . '<td class="stack" valign="top" style="padding-right:18px;">' . $chip(self::P_SOFT, self::P_DARK, '&#9989; APPLICATION RECEIVED')
            . "<h1 class=\"h1\" style=\"margin:16px 0 0 0; $hf font-size:48px; line-height:50px; letter-spacing:-1.5px; color:" . self::INK . ";\">You're in,<br><span style=\"color:" . self::P . ";\">$name</span> &#127881;</h1></td>"
            . '<td class="hero-side" width="190" valign="top" style="width:190px; padding-top:4px;">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border-collapse:separate; background:#FFFFFF; border:1px solid ' . self::TILE_BD . "; border-radius:16px;\"><tr><td style=\"padding:16px; $f\">"
            . '<table role="presentation" cellpadding="0" cellspacing="0" border="0"><tr><td valign="middle"><span style="display:inline-block; width:30px; height:30px; line-height:30px; text-align:center; background:' . self::LI_BLUE . "; color:#FFFFFF; border-radius:7px; $f font-size:17px; font-weight:bold;\">in</span></td>"
            . "<td valign=\"middle\" style=\"padding-left:10px; $f font-size:13px; line-height:16px; font-weight:bold; color:" . self::INK . ';">Eshcol Global<br><span style="font-weight:normal; color:' . self::MUTED . ';">on LinkedIn</span></td></tr></table>'
            . '<p style="margin:10px 0 0 0; font-size:13px; line-height:18px; color:' . self::TEXT . ';">New openings drop here first. &#128064;</p>'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-top:12px;"><tr><td align="center" bgcolor="' . self::LI_BLUE . '" style="background:' . self::LI_BLUE . '; border-radius:30px;">'
            . '<a href="' . $L['linkedin'] . "\" target=\"_blank\" style=\"display:block; padding:10px 12px; $f font-size:14px; font-weight:bold; color:#FFFFFF; text-decoration:none;\">+ Follow us</a></td></tr></table>"
            . '</td></tr></table></td></tr></table>'
            . "<p style=\"margin:18px 0 0 0; $f font-size:17px; line-height:26px; color:" . self::TEXT . ';">Your application for <b style="color:' . self::INK . ';">' . self::PUBLIC_TITLE . '</b> has landed safely' . ($link ? ", and we've read your CV." : '.') . '</p></td></tr>';
        // progress
        $rows[] = '<tr><td class="px" style="padding:26px 32px 0 32px;">' . $label('YOUR PROGRESS')
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr>'
            . $bar(self::P, '8px 0 0 8px') . $bar($link ? self::S : self::P) . $bar($link ? self::LINE : self::S) . $bar(self::LINE) . $bar(self::LINE, '0 8px 8px 0')
            . '</tr></table><table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-top:14px;">'
            . $lvl('&#9989;', 1, 'Apply', 'done')
            . $lvl($link ? '&#128275;' : '&#9989;', 2, 'Complete your details', $link ? 'here' : 'done')
            . $lvl('&#128064;', 3, 'We review your profile', $link ? 'todo' : 'here')
            . $lvl('&#127908;', 4, "Interview, if you're a match", 'todo')
            . $lvl('&#127981;', 5, 'Day one on the shop floor', 'todo')
            . '</table></td></tr>';
        // level 2 action, or what happens now
        $card = '<tr><td class="px" style="padding:24px 32px 0 32px;"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="' . self::P_SOFT . '" style="border-collapse:separate; background:' . self::P_SOFT . '; border-radius:18px;"><tr><td style="padding:24px 24px 26px 24px;">';
        if ($link) {
            $rows[] = $card . "<p style=\"margin:0; $f font-size:12px; font-weight:bold; letter-spacing:1.5px; color:" . self::P_DARK . ';">&#9201;&#65039; LEVEL 2 &middot; ABOUT 2 MINUTES</p>'
                . "<p style=\"margin:8px 0 0 0; $hf font-size:26px; line-height:30px; letter-spacing:-0.5px; color:" . self::INK . ';">Finish your application</p>'
                . "<p style=\"margin:10px 0 0 0; $f font-size:15px; line-height:23px; color:" . self::TEXT . ';">CVs rarely say these things, and we can only put forward profiles that are complete. <b style="color:' . self::INK . ";\">Skip this and your application can't move to Level 3.</b></p>"
                . "<p style=\"margin:10px 0 0 0; $f font-size:15px; line-height:24px; color:" . self::TEXT . ';">&rarr; Your home district<br>&rarr; Your branch and year of passing<br>&rarr; How soon you could join, and your salary details<br>&rarr; A quick check of what we read from your CV</p>'
                . $btn(self::P, '#FFFFFF', 'Complete my application &rarr;', $link)
                . "<p style=\"margin:12px 0 0 0; $f font-size:13px; line-height:18px; color:" . self::P_DARK . ';">Works on your phone. Nothing to sign up for. The link is yours alone.</p>'
                . '</td></tr></table></td></tr>';
        } else {
            $rows[] = $card . "<p style=\"margin:0; $f font-size:12px; font-weight:bold; letter-spacing:1.5px; color:" . self::P_DARK . ';">&#128064; LEVEL 3 &middot; OVER TO US</p>'
                . "<p style=\"margin:8px 0 0 0; $hf font-size:26px; line-height:30px; letter-spacing:-0.5px; color:" . self::INK . ";\">We've got what we need</p>"
                . "<p style=\"margin:10px 0 0 0; $f font-size:15px; line-height:23px; color:" . self::TEXT . ';">Our team reviews every application against ' . Engagement::askedFor($type) . ". If you're a good match, we'll contact you about the next step.</p>"
                . '</td></tr></table></td></tr>';
        }
        // reminders
        $rows[] = '<tr><td class="px" style="padding:30px 32px 0 32px;">' . $label("WHAT YOU'RE WORKING TOWARDS")
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr>'
            . '<td class="stack" width="33%" valign="top" style="padding-right:8px;">' . $tile('&#128467;&#65039;', '5 days a week only', 'Saturday &amp; Sunday off. Every week.') . '</td>'
            . '<td class="stack" width="33%" valign="top" style="padding:0 4px;">' . $tile('&#128184;', '&#8377;21,500 a month', 'Gross. About &#8377;19,700 after PF.') . '</td>'
            . '<td class="stack" width="33%" valign="top" style="padding-left:8px;">' . $tile('&#127857;', 'Food + ride, free', 'Breakfast, lunch and transport.') . '</td>'
            . '</tr></table></td></tr>';
        // side quest: follow on LinkedIn
        $pt = fn ($e, $t) => "<tr><td style=\"padding:4px 0; $f font-size:15px; line-height:21px; color:" . self::INK . ";\">$e &nbsp;$t</td></tr>";
        $rows[] = '<tr><td class="px" style="padding:30px 32px 0 32px;"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="' . self::S_SOFT . '" style="border-collapse:separate; background:' . self::S_SOFT . '; border:2px solid ' . self::S . '; border-radius:18px;"><tr><td style="padding:26px 24px 28px 24px;">'
            . $chip(self::S, '#FFFFFF', '&#127919; SIDE QUEST &middot; 10 SECONDS')
            . "<p style=\"margin:14px 0 0 0; $hf font-size:28px; line-height:32px; letter-spacing:-0.5px; color:" . self::INK . ';">POV: you see the next opening before your whole batch does.</p>'
            . "<p style=\"margin:12px 0 0 0; $f font-size:15px; line-height:23px; color:" . self::TEXT . ";\">We post new openings on LinkedIn first. Follow Eshcol Global, and the next one shows up in your feed instead of your friend's WhatsApp forward.</p>"
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-top:14px;">'
            . $pt('&#9889;', 'New jobs, the moment they open') . $pt('&#129504;', 'Career and interview tips from people who hire') . $pt('&#128640;', 'A fresh LinkedIn that follows companies looks active to recruiters')
            . '</table>' . $btn(self::LI_BLUE, '#FFFFFF', '+ Follow us on LinkedIn', $L['linkedin'])
            . "<p style=\"margin:14px 0 0 0; $f font-size:13px; line-height:19px; color:" . self::S_DARK . ';"><b>Pro move:</b> after you follow, tap the &#128276; on our page so you never miss a drop. On Facebook? <a href="' . $L['facebook'] . '" target="_blank" style="color:' . self::S_DARK . '; font-weight:bold;">Follow us there too</a>.</p>'
            . '</td></tr></table></td></tr>';
        // share with your batch
        $rows[] = '<tr><td class="px" style="padding:30px 32px 0 32px;"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border-collapse:separate; border:2px dashed ' . self::LINE . '; border-radius:18px;"><tr><td style="padding:22px 24px;">'
            . "<p style=\"margin:0; $hf font-size:21px; line-height:26px; color:" . self::INK . ';">Got a classmate who needs this? &#128111;</p>'
            . "<p style=\"margin:8px 0 0 0; $f font-size:15px; line-height:23px; color:" . self::TEXT . ';">Same plant, same weekends off. Send them the link and they can apply in a minute.</p>'
            . $btn('#1FA855', '#FFFFFF', 'Share on WhatsApp', $wa, '13px 24px', 15)
            . "<p style=\"margin:12px 0 0 0; $f font-size:13px; line-height:19px; color:" . self::MUTED . ';">Or copy the link: <a href="' . self::JOB_URL . '" target="_blank" style="color:' . self::LINK . ';">eshcolglobal.com/jobs/trainee-technicians</a>. They apply on the page or at resume@eshcolglobal.com, not on WhatsApp.</p>'
            . '</td></tr></table></td></tr>';
        // sign-off
        $rows[] = '<tr><td class="px" style="padding:30px 32px 0 32px;">'
            . "<p style=\"margin:0; $f font-size:15px; line-height:24px; color:" . self::TEXT . ";\">Real talk: we get a lot of applications, so we may not be able to reply to everyone after this. Whichever way it goes, we're rooting for you. &#128170;</p>"
            . "<p style=\"margin:16px 0 0 0; $f font-size:15px; line-height:22px; color:" . self::INK . ';"><b>Benjamin Basker</b><br><span style="color:' . self::MUTED . ';">Director, Eshcol Global</span></p></td></tr>';
        // about your details
        $rows[] = '<tr><td class="px" style="padding:28px 32px 0 32px;"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr><td style="padding-top:18px; border-top:1px solid ' . self::LINE . ';">'
            . "<p style=\"margin:0 0 6px 0; $f font-size:12px; font-weight:bold; letter-spacing:1.5px; color:" . self::MUTED . ';">ABOUT YOUR DETAILS</p>'
            . "<p style=\"margin:0; $f font-size:13px; line-height:20px; color:" . self::TEXT . ';">' . str_replace('privacy policy', '<a href="' . $L['privacy'] . '" target="_blank" style="color:' . self::LINK . ';">privacy policy</a>', $h($about)) . ' ' . $h($remove) . '</p>'
            . '</td></tr></table></td></tr>';
        // footer
        $fl = fn ($t, $u) => '<a href="' . $u . '" style="color:' . self::INK . '; font-weight:bold; text-decoration:none;">' . $t . '</a>';
        $rows[] = '<tr><td class="px" style="padding:24px 32px 28px 32px;">'
            . "<p style=\"margin:0; $f font-size:13px; line-height:20px; color:" . self::MUTED . ';">Find us on &nbsp;' . $fl('LinkedIn', $L['linkedin']) . ' &middot; ' . $fl('YouTube', $L['youtube']) . ' &middot; ' . $fl('Facebook', $L['facebook']) . '</p>'
            . "<p style=\"margin:14px 0 0 0; $f font-size:11px; line-height:17px; color:" . self::MUTED . ';">' . $h($reason) . ' <a href="' . $L['privacy'] . '" style="color:' . self::MUTED . ';">How we use your details</a><br><br>'
            . 'Eshcol Global Services Private Limited, #714A, Cabin # 3, Spencer Plaza, 7th Floor, Phase-II, #769, Anna Salai, Thousand Lights, Chennai - 600 002, Tamil Nadu, India</p></td></tr>';

        $html = self::page($subject, $preheader, implode("\n", $rows));

        // plain-text twin
        $t = [];
        $t[] = "You're in" . ($first !== '' ? ", $first" : '') . '!';
        $t[] = '';
        $t[] = 'Your application for ' . self::PUBLIC_TITLE . ' has landed safely' . ($link ? ", and we've read your CV." : '.');
        $t[] = '';
        $t[] = 'YOUR PROGRESS';
        $t[] = 'Level 1: Apply (done)';
        $t[] = 'Level 2: Complete your details' . ($link ? ' (YOU ARE HERE)' : ' (done)');
        $t[] = 'Level 3: We review your profile' . ($link ? '' : ' (YOU ARE HERE)');
        $t[] = "Level 4: Interview, if you're a match";
        $t[] = 'Level 5: Day one on the shop floor';
        $t[] = '';
        if ($link) {
            $t[] = 'LEVEL 2: FINISH YOUR APPLICATION (ABOUT 2 MINUTES)';
            $t[] = "CVs rarely say these things, and we can only put forward profiles that are complete. Skip this and your application can't move to Level 3.";
            $t[] = '- Your home district';
            $t[] = '- Your branch and year of passing';
            $t[] = '- How soon you could join, and your salary details';
            $t[] = '- A quick check of what we read from your CV';
            $t[] = '';
            $t[] = 'Complete my application: ' . $link;
            $t[] = 'Works on your phone. Nothing to sign up for. The link is yours alone.';
        } else {
            $t[] = 'Our team reviews every application against ' . Engagement::askedFor($type) . ". If you're a good match, we'll contact you about the next step.";
        }
        $t[] = '';
        $t[] = "WHAT YOU'RE WORKING TOWARDS";
        $t[] = '5 days a week only: Saturday and Sunday off, every week.';
        $t[] = '₹21,500 a month gross, about ₹19,700 after PF.';
        $t[] = 'Free breakfast, lunch and transport.';
        $t[] = '';
        $t[] = 'SIDE QUEST (10 SECONDS)';
        $t[] = 'POV: you see the next opening before your whole batch does. We post new openings on LinkedIn first.';
        $t[] = 'Follow us on LinkedIn: ' . $L['linkedin'];
        $t[] = 'Pro move: after you follow, tap the bell on our page so you never miss a drop.';
        $t[] = '';
        $t[] = 'GOT A CLASSMATE WHO NEEDS THIS?';
        $t[] = 'Send them the link: ' . self::JOB_URL;
        $t[] = 'They apply on the page or at resume@eshcolglobal.com, not on WhatsApp.';
        $t[] = '';
        $t[] = "Real talk: we get a lot of applications, so we may not be able to reply to everyone after this. Whichever way it goes, we're rooting for you.";
        $t[] = '';
        $t[] = 'Benjamin Basker';
        $t[] = 'Director, Eshcol Global';
        $t[] = '';
        $t[] = 'ABOUT YOUR DETAILS';
        $t[] = str_replace('privacy policy explains.', "privacy policy explains:\n" . $L['privacy'], $about);
        $t[] = $remove;
        $t[] = '';
        $t[] = 'LinkedIn: ' . $L['linkedin'];
        $t[] = 'YouTube: ' . $L['youtube'];
        $t[] = 'Facebook: ' . $L['facebook'];
        $t[] = '';
        $t[] = '--';
        $t[] = $reason;
        $t[] = 'How we use your details: ' . $L['privacy'];
        $t[] = 'Eshcol Global Services Private Limited, #714A, Cabin # 3, Spencer Plaza, 7th Floor, Phase-II, #769, Anna Salai, Thousand Lights, Chennai - 600 002, Tamil Nadu, India';

        return [$subject, $html, implode("\n", $t) . "\n"];
    }

    private static function page(string $title, string $preheader, string $rows): string
    {
        $h = fn ($v) => CandidateEmail::h($v);
        $bg = self::BG;
        $card = self::CARD;
        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta http-equiv="X-UA-Compatible" content="IE=edge">
<meta name="color-scheme" content="light">
<meta name="supported-color-schemes" content="light">
<title>{$h($title)}</title>
<style>
  body { margin:0; padding:0; width:100% !important; -webkit-text-size-adjust:100%; -ms-text-size-adjust:100%; }
  table { border-collapse:collapse; mso-table-lspace:0pt; mso-table-rspace:0pt; }
  img { border:0; outline:none; text-decoration:none; display:block; }
  @media only screen and (max-width:620px) {
    .container { width:100% !important; }
    .px { padding-left:20px !important; padding-right:20px !important; }
    .h1 { font-size:40px !important; line-height:42px !important; }
    .stack { display:block !important; width:100% !important; padding:0 0 10px 0 !important; }
    .hero-side { display:block !important; width:100% !important; padding:20px 0 0 0 !important; }
    .btn { display:block !important; text-align:center !important; }
    .btn a { padding-left:14px !important; padding-right:14px !important; white-space:nowrap !important; }
  }
</style>
</head>
<body style="margin:0; padding:0; background-color:{$bg};">
<div style="display:none; max-height:0; overflow:hidden; mso-hide:all; font-size:1px; line-height:1px; color:{$bg}; opacity:0;">{$h($preheader)}&#8199;&#847;&#8199;&#847;&#8199;&#847;&#8199;&#847;&#8199;&#847;&#8199;&#847;</div>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="{$bg}" style="background-color:{$bg};">
<tr><td align="center" style="padding:24px 10px;">
  <!--[if mso]><table role="presentation" width="600" align="center" cellpadding="0" cellspacing="0" border="0"><tr><td><![endif]-->
  <table role="presentation" class="container" width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%; max-width:600px; margin:0 auto; background-color:{$card}; border-radius:22px; overflow:hidden;">
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
