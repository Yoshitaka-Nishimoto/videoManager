<?php

namespace App\Services\Web;

use Carbon\CarbonImmutable;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * 公式サイト・GitHub などの Web ページを取得し、登録用の情報と、分析に使う本文を取り出す。
 *
 * GitHub のリポジトリは画面の HTML ではなく README を API で取る（本文が README に集まっているため）。
 * ログインが必要なページと PDF にはまだ対応しない。
 */
class WebPageClient
{
    /** AI に渡す本文の最大文字数。超えた分は切る。 */
    public const MAX_TEXT_LENGTH = 60_000;

    private const USER_AGENT = 'Mozilla/5.0 (compatible; VideoManager/1.0)';

    /** 本文として扱わない要素。 */
    private const NOISE_TAGS = ['script', 'style', 'noscript', 'template', 'svg', 'iframe', 'nav', 'footer', 'header', 'aside', 'form', 'button'];

    /** 前後で改行する要素。 */
    private const BLOCK_TAGS = ['p', 'div', 'section', 'article', 'main', 'li', 'ul', 'ol', 'dl', 'dt', 'dd', 'tr', 'table', 'pre', 'blockquote', 'br', 'hr', 'figure', 'figcaption'];

    /**
     * URL を比べやすい形にそろえる（スキーム・ホストを小文字、# 以降と末尾の / を除く）。http(s) でなければ null。
     */
    public static function normalizeUrl(string $input): ?string
    {
        $parts = parse_url(trim($input));
        $scheme = strtolower($parts['scheme'] ?? '');
        $host = strtolower($parts['host'] ?? '');

        if (! in_array($scheme, ['http', 'https'], true) || $host === '') {
            return null;
        }

        $path = rtrim($parts['path'] ?? '', '/');

        return $scheme.'://'.$host.(isset($parts['port']) ? ':'.$parts['port'] : '').$path.(isset($parts['query']) ? '?'.$parts['query'] : '');
    }

    /**
     * 登録用の情報（題名・サイト名・説明・画像・公開日）を取る。
     *
     * @throws WebPageException
     */
    public function details(string $url): WebPageDetails
    {
        $response = $this->get($url);
        $this->ensureHtml($response);

        $xpath = new DOMXPath($this->document($this->utf8Body($response)));
        $meta = fn (string $key) => $this->meta($xpath, $key);
        $host = (string) parse_url($url, PHP_URL_HOST);

        $title = $meta('og:title') ?? $this->text($xpath->query('//title')->item(0)) ?: $host;
        $image = $meta('og:image') ?? $meta('twitter:image');
        $published = $meta('article:published_time');

        return new WebPageDetails(
            url: $url,
            title: Str::limit($title, 250, ''),
            siteName: $meta('og:site_name') ?? $host,
            description: $meta('og:description') ?? $meta('description'),
            imageUrl: $image !== null ? $this->absoluteUrl($image, $url) : null,
            publishedAt: $published !== null ? $this->parseDate($published) : null,
        );
    }

    /**
     * 分析に使う本文を取る。
     *
     * @throws WebPageException
     */
    public function content(string $url): WebPageContent
    {
        $github = $this->githubSource($url);

        if ($github !== null) {
            $response = $this->get($github, ['Accept' => 'application/vnd.github.raw']);

            // README に埋め込まれたロゴ・バッジなどの HTML タグは本文ではないので除く（Markdown の本文は残す）。
            $markdown = preg_replace('#</?(p|img|a|div|br|picture|source|h[1-6]|sup|sub|span|center)\b[^>]*>#i', '', $this->utf8Body($response));

            return $this->limit($this->normalizeText($markdown), $github);
        }

        $response = $this->get($url);
        $this->ensureHtml($response, allowPlainText: true);
        $body = $this->utf8Body($response);

        $text = str_contains(strtolower($response->header('Content-Type')), 'html')
            ? $this->mainText($this->document($body))
            : $this->normalizeText($body);

        if (mb_strlen($text) < 50) {
            throw new WebPageException('ページから本文を読み取れませんでした。JavaScript で表示するページや、ログインが必要なページはまだ分析できません。');
        }

        return $this->limit($text, $url);
    }

    /**
     * GitHub のリポジトリなら README の API、Markdown ファイルなら生のファイルの URL。それ以外は null。
     */
    private function githubSource(string $url): ?string
    {
        if (strtolower((string) parse_url($url, PHP_URL_HOST)) !== 'github.com') {
            return null;
        }

        $segments = array_values(array_filter(explode('/', (string) parse_url($url, PHP_URL_PATH))));

        if (count($segments) < 2) {
            return null;
        }

        [$owner, $repo] = $segments;

        // リポジトリの最上位、またはブランチの一覧（/tree/main など）は README。
        if (count($segments) === 2 || ($segments[2] === 'tree' && count($segments) === 4)) {
            return "https://api.github.com/repos/{$owner}/{$repo}/readme";
        }

        // /blob/<ブランチ>/<パス>.md は生のファイル。
        if ($segments[2] === 'blob' && count($segments) >= 5 && preg_match('/\.(md|markdown|txt)$/i', end($segments))) {
            return "https://raw.githubusercontent.com/{$owner}/{$repo}/".implode('/', array_slice($segments, 3));
        }

        return null;
    }

    /**
     * @param  array<string, string>  $headers
     *
     * @throws WebPageException
     */
    private function get(string $url, array $headers = []): Response
    {
        try {
            $response = Http::timeout(20)
                ->withHeaders(['User-Agent' => self::USER_AGENT, 'Accept-Language' => 'ja,en;q=0.8', ...$headers])
                ->get($url);
        } catch (ConnectionException $e) {
            throw new WebPageException('ページに接続できませんでした。URL を確認するか、時間をおいて再度お試しください。', previous: $e);
        }

        return match (true) {
            $response->successful() => $response,
            in_array($response->status(), [401, 403], true) => throw new WebPageException('ページを開けませんでした（'.$response->status().'）。ログインが必要なページはまだ登録・分析できません。'),
            $response->status() === 404 => throw new WebPageException('ページが見つかりません（404）。URL を確認してください。'),
            default => throw new WebPageException('ページを取得できませんでした（'.$response->status().'）。'),
        };
    }

    /**
     * @throws WebPageException
     */
    private function ensureHtml(Response $response, bool $allowPlainText = false): void
    {
        $type = strtolower($response->header('Content-Type'));

        if (str_contains($type, 'pdf')) {
            throw new WebPageException('PDF はまだ登録・分析できません。');
        }

        $allowed = str_contains($type, 'html') || ($allowPlainText && (str_contains($type, 'text/plain') || str_contains($type, 'markdown')));

        if ($type !== '' && ! $allowed) {
            throw new WebPageException("このページの形式（{$type}）にはまだ対応していません。");
        }
    }

    /**
     * 本文を UTF-8 にそろえる（Shift_JIS・EUC-JP のサイトもあるため）。
     */
    private function utf8Body(Response $response): string
    {
        $body = $response->body();
        $charset = Str::match('/charset=["\']?([\w-]+)/i', $response->header('Content-Type'))
            ?: Str::match('/<meta[^>]+charset=["\']?([\w-]+)/i', substr($body, 0, 4096));

        if ($charset === '' || in_array(strtolower($charset), ['utf-8', 'utf8'], true)) {
            return mb_check_encoding($body, 'UTF-8') ? $body : mb_convert_encoding($body, 'UTF-8', 'SJIS-win,EUC-JP,UTF-8');
        }

        try {
            return mb_convert_encoding($body, 'UTF-8', $charset);
        } catch (Throwable) {
            return mb_convert_encoding($body, 'UTF-8', 'SJIS-win,EUC-JP,UTF-8');
        }
    }

    private function document(string $html): DOMDocument
    {
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return $document;
    }

    private function meta(DOMXPath $xpath, string $key): ?string
    {
        $node = $xpath->query("//meta[@property='{$key}' or @name='{$key}']/@content")->item(0);
        $value = trim((string) $node?->nodeValue);

        return $value === '' ? null : $value;
    }

    private function text(?DOMNode $node): string
    {
        return trim(preg_replace('/\s+/u', ' ', (string) $node?->textContent));
    }

    /**
     * ページの本文を、見出しと段落の区切りを残した文章にする。main・article があればその中だけを使う。
     */
    private function mainText(DOMDocument $document): string
    {
        $xpath = new DOMXPath($document);

        foreach (self::NOISE_TAGS as $tag) {
            foreach (iterator_to_array($xpath->query("//{$tag}")) as $node) {
                $node->parentNode?->removeChild($node);
            }
        }

        $root = collect(iterator_to_array($xpath->query('//main | //article')))
            ->sortByDesc(fn (DOMNode $node) => mb_strlen($node->textContent))
            ->first() ?? $xpath->query('//body')->item(0) ?? $document->documentElement;

        if ($root === null) {
            return '';
        }

        foreach (iterator_to_array($xpath->query('.//*', $root)) as $element) {
            /** @var DOMElement $element */
            $tag = strtolower($element->tagName);

            if (preg_match('/^h([1-6])$/', $tag, $level)) {
                $element->insertBefore($document->createTextNode("\n\n".str_repeat('#', (int) $level[1]).' '), $element->firstChild);
                $element->appendChild($document->createTextNode("\n"));
            } elseif ($tag === 'li') {
                $element->insertBefore($document->createTextNode("\n- "), $element->firstChild);
            } elseif (in_array($tag, self::BLOCK_TAGS, true)) {
                $element->appendChild($document->createTextNode("\n"));
            }
        }

        return $this->normalizeText($root->textContent);
    }

    /**
     * 行ごとに空白を詰め、空行の連続を 1 行にする。
     */
    private function normalizeText(string $text): string
    {
        $lines = collect(preg_split('/\R/u', $text))
            ->map(fn (string $line) => trim(preg_replace('/[ \t\x{00A0}\x{3000}]+/u', ' ', $line)));

        // 箇条書きの項目の間の空行も詰める。
        return trim(preg_replace(["/\n{3,}/", "/\n\n(?=- )/"], ["\n\n", "\n"], $lines->implode("\n")));
    }

    private function limit(string $text, string $sourceUrl): WebPageContent
    {
        $truncated = mb_strlen($text) > self::MAX_TEXT_LENGTH;

        return new WebPageContent(
            text: $truncated ? mb_substr($text, 0, self::MAX_TEXT_LENGTH) : $text,
            sourceUrl: $sourceUrl,
            truncated: $truncated,
        );
    }

    private function absoluteUrl(string $url, string $base): string
    {
        if (preg_match('#^https?://#i', $url)) {
            return $url;
        }

        $scheme = parse_url($base, PHP_URL_SCHEME) ?: 'https';

        if (str_starts_with($url, '//')) {
            return $scheme.':'.$url;
        }

        $origin = $scheme.'://'.parse_url($base, PHP_URL_HOST).(parse_url($base, PHP_URL_PORT) ? ':'.parse_url($base, PHP_URL_PORT) : '');

        return str_starts_with($url, '/') ? $origin.$url : $origin.'/'.$url;
    }

    private function parseDate(string $value): ?CarbonImmutable
    {
        try {
            return CarbonImmutable::parse($value);
        } catch (Throwable) {
            return null;
        }
    }
}
