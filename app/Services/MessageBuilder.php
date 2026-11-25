<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\DB;

/**
 * Turns a campaign + a recipient into the final, personalised message.
 *
 * Merge tags (in subject, HTML and text):
 *   {{email}} {{name}} {{any_custom_field_key}}
 *   {{name|fallback}}  value with a default when empty
 *   ({{first_name}} / {{full_name}} are kept as aliases of {{name}})
 *   {{unsubscribe_url}} {{webversion_url}} {{date}} {{year}}
 */
final class MessageBuilder
{
    /** @var array<string,int> url_hash => link id */
    private array $links = [];

    public function __construct(private array $campaign, private string $baseUrl)
    {
        if (!empty($campaign['id']) && $campaign['track_clicks']) {
            foreach (DB::all('SELECT id, url_hash FROM campaign_links WHERE campaign_id = ?', [$campaign['id']]) as $l) {
                $this->links[$l['url_hash']] = (int) $l['id'];
            }
        }
    }

    /** Links in the HTML that will be tracked (absolute http/https, without merge tags). */
    public static function extractLinks(string $html): array
    {
        $out = [];
        if (preg_match_all('/<a\s[^>]*href\s*=\s*(["\'])(.*?)\1/is', $html, $m)) {
            foreach ($m[2] as $href) {
                $href = html_entity_decode(trim($href), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                if (self::isTrackable($href)) {
                    $out[$href] = true;
                }
            }
        }
        return array_keys($out);
    }

    private static function isTrackable(string $href): bool
    {
        return (bool) preg_match('#^https?://#i', $href) && !str_contains($href, '{{');
    }

    /** Store the campaign's links so that click redirects can be resolved. */
    public static function registerLinks(int $campaignId, string $html): void
    {
        foreach (self::extractLinks($html) as $url) {
            DB::query('INSERT IGNORE INTO campaign_links (campaign_id, url_hash, url) VALUES (?,?,?)',
                [$campaignId, sha1($url), $url]);
        }
    }

    public function urls(array $r): array
    {
        $t = rawurlencode((string) $r['token']);
        return [
            'unsubscribe' => $this->baseUrl . '/u/' . $t,
            'webversion' => $this->baseUrl . '/v/' . $t,
            'open' => $this->baseUrl . '/o/' . $t . '.gif',
        ];
    }

    /**
     * @param array $r recipient: email, name, fields (JSON|array), track, token
     * @param bool  $forWeb rendering for the web version (no open pixel)
     * @return array{subject:string, html:string, text:string, headers:array<string,string>}
     */
    public function build(array $r, bool $forWeb = false): array
    {
        $vars = $this->vars($r);
        $urls = $this->urls($r);
        $html = (string) $this->campaign['html_body'];
        // Tracking only if enabled on the campaign AND on the subscriber (track = 1).
        $track = !empty($r['id']) && (int) ($r['track'] ?? 0) === 1;

        if ($html !== '') {
            if ($this->campaign['track_clicks'] && $track) {
                $html = $this->rewriteLinks($html, (string) $r['token']);
            }
            if (!str_contains($html, '{{unsubscribe_url}}')) {
                $html = $this->injectBeforeBodyEnd($html, $this->footerHtml());
            }
            if (!empty($this->campaign['preheader'])) {
                $html = $this->injectPreheader($html, (string) $this->campaign['preheader']);
            }
            $html = $this->merge($html, $vars, true);
            if (!$forWeb && $this->campaign['track_opens'] && $track) {
                $pixel = '<img src="' . e($urls['open']) . '" width="1" height="1" alt="" style="display:block;width:1px;height:1px;border:0" />';
                $html = $this->injectBeforeBodyEnd($html, $pixel);
            }
        }

        $text = trim((string) $this->campaign['text_body']);
        if ($text === '') {
            $text = self::htmlToText((string) $this->campaign['html_body']);
        }
        if (!str_contains($text, '{{unsubscribe_url}}')) {
            $text .= "\n\n-- \n" . __('Unsubscribe') . ': {{unsubscribe_url}}';
        }
        $text = $this->merge($text, $vars, false);

        $subject = $this->merge((string) $this->campaign['subject'], $vars, false);

        $listHost = \App\Core\App::panelHost() ?: 'bmaxsend.local';
        $headers = [
            'List-Unsubscribe' => '<' . $urls['unsubscribe'] . '>',
            'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
            'List-Id' => sprintf('<c%d.%s>', (int) ($this->campaign['client_id'] ?? 0), $listHost),
            'Feedback-ID' => sprintf('%d:%d:bmaxsend', (int) ($this->campaign['id'] ?? 0), (int) ($this->campaign['client_id'] ?? 0)),
            'X-bMaxSend-Campaign' => (string) ($this->campaign['id'] ?? 0),
            'Precedence' => 'bulk',
        ];

        return ['subject' => $subject, 'html' => $html, 'text' => $text, 'headers' => $headers];
    }

    private function vars(array $r): array
    {
        $fields = $r['fields'] ?? [];
        if (is_string($fields)) {
            $fields = json_decode($fields, true) ?: [];
        }
        $urls = $this->urls($r);
        $vars = [];
        foreach ((array) $fields as $k => $v) {
            $vars[strtolower((string) $k)] = is_scalar($v) ? (string) $v : '';
        }
        $vars['email'] = (string) $r['email'];
        $vars['name'] = (string) ($r['name'] ?? '');
        $vars['first_name'] = $vars['name'];
        $vars['full_name'] = $vars['name'];
        $vars['date'] = fmt_dt(now_utc(), 'd/m/Y');
        $vars['year'] = gmdate('Y');
        $vars['unsubscribe_url'] = $urls['unsubscribe'];
        $vars['webversion_url'] = $urls['webversion'];
        return $vars;
    }

    private function merge(string $tpl, array $vars, bool $html): string
    {
        return (string) preg_replace_callback('/\{\{\s*([a-zA-Z0-9_.-]+)\s*(?:\|([^}]*))?\}\}/', function ($m) use ($vars, $html) {
            $key = strtolower($m[1]);
            $val = $vars[$key] ?? '';
            if ($val === '' && isset($m[2])) {
                $val = $m[2];
            }
            return $html ? e($val) : $val;
        }, $tpl);
    }

    private function rewriteLinks(string $html, string $token): string
    {
        return (string) preg_replace_callback('/(<a\s[^>]*href\s*=\s*)(["\'])(.*?)\2/is', function ($m) use ($token) {
            $href = html_entity_decode(trim($m[3]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if (!self::isTrackable($href)) {
                return $m[0];
            }
            $id = $this->links[sha1($href)] ?? null;
            if ($id === null) {
                return $m[0];
            }
            return $m[1] . $m[2] . e($this->baseUrl . '/c/' . rawurlencode($token) . '/' . $id) . $m[2];
        }, $html);
    }

    private function footerHtml(): string
    {
        return '<div style="font-family:Arial,sans-serif;font-size:12px;color:#888;text-align:center;padding:20px 10px">'
            . e(__('You received this email because you are subscribed to our mailing list.')) . '<br>'
            . '<a href="{{unsubscribe_url}}" style="color:#888">' . e(__('Unsubscribe')) . '</a>'
            . ' &middot; <a href="{{webversion_url}}" style="color:#888">' . e(__('View in browser')) . '</a></div>';
    }

    private function injectBeforeBodyEnd(string $html, string $snippet): string
    {
        if (stripos($html, '</body>') !== false) {
            return (string) preg_replace('#</body>#i', $snippet . '</body>', $html, 1);
        }
        return $html . $snippet;
    }

    private function injectPreheader(string $html, string $pre): string
    {
        $span = '<div style="display:none;font-size:1px;line-height:1px;max-height:0;max-width:0;opacity:0;overflow:hidden;mso-hide:all">'
            . e($pre) . str_repeat('&#847;&zwnj;&nbsp;', 40) . '</div>';
        if (preg_match('#<body[^>]*>#i', $html)) {
            return (string) preg_replace('#(<body[^>]*>)#i', '$1' . $span, $html, 1);
        }
        return $span . $html;
    }

    public static function htmlToText(string $html): string
    {
        if (trim($html) === '') {
            return '';
        }
        $t = preg_replace('#<(head|style|script|title)[^>]*>.*?</\1>#is', '', $html);
        $t = preg_replace_callback('#<a\s[^>]*href\s*=\s*(["\'])(.*?)\1[^>]*>(.*?)</a>#is', function ($m) {
            $label = trim(strip_tags($m[3]));
            $href = trim($m[2]);
            if ($label === '' || $label === $href || str_starts_with($href, 'mailto:')) {
                return $label !== '' ? $label : $href;
            }
            return $label . ' (' . $href . ')';
        }, (string) $t);
        $t = preg_replace('#<(br|hr)\s*/?>#i', "\n", (string) $t);
        $t = preg_replace('#</(p|div|h[1-6]|tr|table|li|blockquote)>#i', "\n\n", (string) $t);
        $t = preg_replace('#<li[^>]*>#i', '- ', (string) $t);
        $t = strip_tags((string) $t);
        $t = html_entity_decode($t, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $t = preg_replace("/[ \t\x{00A0}]+/u", ' ', $t);
        $t = preg_replace("/ *\n */", "\n", (string) $t);
        $t = preg_replace("/\n{3,}/", "\n\n", (string) $t);
        return trim((string) $t);
    }
}
