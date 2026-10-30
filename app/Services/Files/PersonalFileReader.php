<?php

namespace App\Services\Files;

use App\Models\Video;
use App\Services\Web\WebPageContent;
use Illuminate\Support\Facades\Storage;

/**
 * 利用者がアップロードした個人のファイル（血液検査の結果の CSV など）を、分析に使う文章にする。
 *
 * CSV は 1 行目を見出しとし、各行を「項目 | 値 | …」の形にする。値のない行（「乳ビ,,,」など）は除く。
 * ファイルは local ディスク（storage/app/private）に置き、公開の場所には置かない。
 */
class PersonalFileReader
{
    /** 保存先のディスク。公開されない storage/app/private。 */
    public const DISK = 'local';

    /** アップロードできる大きさ（KB）。 */
    public const MAX_KILOBYTES = 1024;

    /**
     * 保存したファイルを読む。
     *
     * @throws PersonalFileException
     */
    public function read(Video $video): WebPageContent
    {
        $contents = $video->storage_path !== null ? Storage::disk(self::DISK)->get($video->storage_path) : null;

        if ($contents === null) {
            throw new PersonalFileException('ファイルが見つかりません。もう一度アップロードしてください。');
        }

        return new WebPageContent(text: $this->toText($contents), sourceUrl: null, truncated: false);
    }

    /**
     * CSV の中身を、分析に使う文章にする。
     *
     * @throws PersonalFileException
     */
    public function toText(string $contents): string
    {
        $rows = collect(preg_split('/\R/u', $this->utf8($contents)))
            ->filter(fn (string $line) => trim($line) !== '')
            ->map(fn (string $line) => array_map(fn (?string $cell) => trim((string) $cell), str_getcsv($line, escape: '')))
            ->values();

        if ($rows->count() < 2) {
            throw new PersonalFileException('CSV に見出しの行と、1 行以上のデータが必要です。');
        }

        $header = $rows->shift();

        $lines = $rows
            // 項目名だけで値のない行は、分析の材料にならないので除く。
            ->filter(fn (array $row) => collect(array_slice($row, 1))->contains(fn (string $cell) => $cell !== ''))
            ->map(fn (array $row) => implode(' | ', array_pad($row, count($header), '')));

        if ($lines->isEmpty()) {
            throw new PersonalFileException('CSV に値の入った行がありません。');
        }

        return implode("\n", [implode(' | ', $header), ...$lines]);
    }

    /**
     * 文字コードを UTF-8 にそろえ、先頭の BOM を除く（Excel で保存した CSV は Shift_JIS のことがあるため）。
     */
    private function utf8(string $contents): string
    {
        $text = mb_check_encoding($contents, 'UTF-8') ? $contents : mb_convert_encoding($contents, 'UTF-8', 'SJIS-win,EUC-JP');

        return preg_replace('/^\x{FEFF}/u', '', $text);
    }
}
