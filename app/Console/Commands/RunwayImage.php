<?php

namespace App\Console\Commands;

use App\Actions\Productions\GenerateRunwayImage;
use App\Models\ProductionAsset;
use App\Models\VideoProduction;
use App\Services\Runway\PersonImagePrompt;
use App\Services\Runway\RunwayException;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;

#[Signature('runway:image
    {image : 人物のサンプル画像（例：storage/app/private/productions/12/assets/xxx.jpg）}
    {--shot=upper : 写り方（face / upper / full、または 顔 / 上半身 / 全身）}
    {--background=blue : 背景（blue / green / white / park / office / studio、または 公園 などの日本語、背景を説明する英語の文）}
    {--style=photo : 絵柄（photo / manga / anime / illustration、または 写真 / 漫画 / アニメ / イラスト）}
    {--prompt= : 追加の指示（英語）}
    {--ratio= : 比率（例：1920:1080。省略すると制作の比率に合わせる）}
    {--model=gen4_image : モデル（gen4_image / gen4_image_turbo）}
    {--seed= : 乱数の種（同じ結果を再現したいとき）}
    {--title= : 素材の名前}
    {--production= : 登録先の制作ID（省略すると画像のパスから判定する）}
    {--dry-run : Runway に送らず、指示文と費用の見積もりだけ表示する}')]
#[Description('人物のサンプル画像から Runway で画像を作り、制作の素材に登録する')]
class RunwayImage extends Command
{
    public function handle(GenerateRunwayImage $generate): int
    {
        $path = $this->diskPath((string) $this->argument('image'));

        if (! Storage::disk(ProductionAsset::DISK)->exists($path)) {
            $this->error("画像が見つかりません：{$path}（storage/app/private の中のファイルを指定してください）");

            return self::FAILURE;
        }

        $production = $this->production($path);

        if ($production === null) {
            $this->error('登録先の制作が分かりません。--production=制作ID を指定してください。');

            return self::FAILURE;
        }

        try {
            $prompt = PersonImagePrompt::build(
                (string) $this->option('shot'),
                (string) $this->option('background'),
                (string) $this->option('style'),
                $this->option('prompt'),
            );
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $model = (string) $this->option('model');
        $ratio = (string) ($this->option('ratio') ?: GenerateRunwayImage::defaultRatioFor($production));
        $seed = $this->option('seed') !== null ? (int) $this->option('seed') : null;
        $credits = GenerateRunwayImage::estimatedCredits($model, $ratio);

        $this->table(['項目', '内容'], [
            ['参照画像', $path],
            ['登録先の制作', "#{$production->id} {$production->title}"],
            ['モデル・比率', "{$model} / {$ratio}"],
            ['費用の見積もり', "{$credits} クレジット（約 $".number_format($credits * 0.01, 2).'）'],
        ]);
        $this->line('指示文：');
        $this->line($prompt);
        $this->newLine();

        if ($this->option('dry-run')) {
            $this->info('--dry-run のため、Runway には送りませんでした。');

            return self::SUCCESS;
        }

        try {
            $assets = $generate->handle(
                $production,
                $path,
                $prompt,
                $ratio,
                $model,
                $seed,
                $this->option('title') ?: $this->defaultTitle(),
                options: [
                    'shot' => PersonImagePrompt::normalize((string) $this->option('shot')),
                    'background' => PersonImagePrompt::normalize((string) $this->option('background')),
                    'style' => PersonImagePrompt::normalize((string) $this->option('style')),
                ],
                onProgress: fn (string $message) => $this->line($message),
            );
        } catch (RunwayException|InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        foreach ($assets as $asset) {
            $this->info("素材に登録しました：#{$asset->id}「{$asset->title}」 → storage/app/private/{$asset->storage_path}");
        }

        return self::SUCCESS;
    }

    /**
     * 引数のパスを、ディスク（storage/app/private）の中のパスにそろえる。
     * Windows の区切り（\）、storage/app/private/ から始まるパス、絶対パスのどれでも受け付ける。
     */
    private function diskPath(string $path): string
    {
        $path = str_replace('\\', '/', trim($path));
        $root = str_replace('\\', '/', rtrim(Storage::disk(ProductionAsset::DISK)->path(''), '/\\')).'/';

        if (str_starts_with($path, $root)) {
            return substr($path, strlen($root));
        }

        return Str::contains($path, 'storage/app/private/') ? Str::after($path, 'storage/app/private/') : ltrim($path, '/');
    }

    private function production(string $path): ?VideoProduction
    {
        if ($this->option('production')) {
            return VideoProduction::query()->find((int) $this->option('production'));
        }

        $asset = ProductionAsset::query()->where('storage_path', $path)->first();

        if ($asset !== null) {
            return $asset->production;
        }

        return preg_match('#^productions/(\d+)/#', $path, $m) ? VideoProduction::query()->find((int) $m[1]) : null;
    }

    private function defaultTitle(): string
    {
        $labels = [
            'face' => '顔', 'upper' => '上半身', 'full' => '全身',
            'blue' => 'ブルーバック', 'green' => 'グリーンバック', 'white' => '白背景', 'park' => '公園', 'office' => 'オフィス', 'studio' => 'スタジオ',
            'photo' => '写真', 'manga' => '漫画', 'anime' => 'アニメ', 'illustration' => 'イラスト',
        ];
        $label = fn (string $option) => $labels[PersonImagePrompt::normalize((string) $this->option($option))] ?? Str::limit((string) $this->option($option), 20);

        return 'Runway：'.$label('shot').'・'.$label('background').'・'.$label('style');
    }
}
