<?php

namespace App\Services\Remotion;

/**
 * Windows の静止画のサーバーが返した画像と、その書き出しの情報。
 */
final readonly class WindowsStill
{
    public function __construct(
        public string $image,
        public int $renderMs,
        public int $width,
        public int $height,
        public ?int $frame,
        public ?string $remotionVersion,
        /** 書き出した remotion/src の版（Git のコミットではなくコードのハッシュ。例：remotion-src:0698d8ae76e98c3d） */
        public ?string $codeVersion,
    ) {}

    public function sizeBytes(): int
    {
        return strlen($this->image);
    }
}
