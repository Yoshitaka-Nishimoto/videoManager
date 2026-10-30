<?php

namespace App\Services\Web;

/**
 * 分析に使う本文と、その取得元。Web ページのほか、個人のファイルを文章にしたもの（PersonalFileReader）にも使う。
 */
final readonly class WebPageContent
{
    /**
     * @param  ?string  $sourceUrl  実際に本文を取った URL（GitHub は README の API）。個人のファイルは空
     * @param  bool  $truncated  長すぎて途中で切ったか
     */
    public function __construct(
        public string $text,
        public ?string $sourceUrl,
        public bool $truncated = false,
    ) {}
}
