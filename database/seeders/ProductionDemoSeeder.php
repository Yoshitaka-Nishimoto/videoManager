<?php

namespace Database\Seeders;

use App\Models\KnowledgeEdge;
use App\Models\KnowledgeNode;
use App\Models\KnowledgeRevision;
use App\Models\KnowledgeSource;
use App\Models\ProductionPlan;
use App\Models\ProductionScene;
use App\Models\User;
use App\Models\VideoProduction;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * 制作の画面と Remotion の書き出しを試すための見本（開発用）。
 *
 * DatabaseSeeder には含めない。明示したときだけ入れる：
 *   ./vendor/bin/sail artisan db:seed --class=ProductionDemoSeeder
 *
 * 見本はタイトルの先頭に「【見本】」を付けて実データと区別する。入れ直すと前の見本は消える。
 * 見本だけを消すとき：
 *   ./vendor/bin/sail artisan tinker --execute="Database\Seeders\ProductionDemoSeeder::remove()"
 */
class ProductionDemoSeeder extends Seeder
{
    public const MARK = '【見本】';

    public function run(): void
    {
        static::remove();

        $user = User::query()->oldest('id')->first();

        DB::transaction(function () use ($user) {
            $decision = KnowledgeNode::query()->create([
                'node_type' => KnowledgeNode::TYPE_DECISION,
                'title' => self::MARK.'生成の前に入力画像の品質を確認する',
                'description' => '画像を Runway に渡す前に、解像度・顔の向き・明るさを確認し、基準に満たないものは撮り直しを依頼する。',
                'status' => KnowledgeNode::STATUS_CONFIRMED,
                'proposed_by' => KnowledgeNode::PROPOSED_BY_HUMAN,
                'confirmed_by' => $user?->id,
                'confirmed_at' => now(),
            ]);

            $production = VideoProduction::query()->create([
                'decision_node_id' => $decision->id,
                'genre' => 'video_creation',
                'title' => self::MARK.'入力画像の品質確認の解説',
                'brief' => '設計判断「生成の前に入力画像の品質を確認する」を、1 分程度の解説動画にする。',
                'ratio' => '1280:720',
                'target_duration_seconds' => 20,
                'status' => VideoProduction::STATUS_ACTIVE,
                'created_by' => $user?->id,
            ]);

            $first = $this->plan($production, 1, ProductionPlan::STATUS_CONFIRMED, $user, null, [
                ['s1', 'remotion.title', '導入', 3, '生成の前に、入力画像を確認します。', null,
                    ['title' => ['heading' => '入力画像の品質確認', 'subheading' => 'Runway の失敗を減らす', 'layout' => 'center']]],
                ['s2', 'remotion.text', '確認する点', 5, null, '確認するのは 3 つの点です。',
                    ['text' => ['heading' => '確認する 3 つの点', 'items' => ['解像度', '顔の向き', '明るさ'], 'reveal' => 'one_by_one']]],
                ['s3', 'remotion.diagram', '確認の流れ', 4, '合格した画像だけを Runway に渡します。', null,
                    ['diagram' => [
                        'layout' => 'horizontal',
                        'nodes' => [
                            ['id' => 'a', 'label' => '画像を受け取る'],
                            ['id' => 'b', 'label' => '品質を確認', 'emphasis' => true],
                            ['id' => 'c', 'label' => 'Runway で生成'],
                            ['id' => 'd', 'label' => '撮り直しを依頼'],
                        ],
                        'edges' => [
                            ['from' => 'a', 'to' => 'b', 'label' => null],
                            ['from' => 'b', 'to' => 'c', 'label' => '合格'],
                            ['from' => 'b', 'to' => 'd', 'label' => '不合格'],
                        ],
                        'reveal_order' => ['a', 'b', 'c', 'd'],
                    ]]],
                ['s4', 'remotion.title', 'まとめ', 3, '確認の手間で、生成のやり直しが減ります。', null,
                    ['title' => ['heading' => '確認の手間で、やり直しを減らす', 'subheading' => null, 'layout' => 'lower_third']]],
            ]);

            // 版の切り替えと「完成版は確認済みの版だけ」を試すための候補の版。
            $this->plan($production, 2, ProductionPlan::STATUS_CANDIDATE, $user, $first, [
                ['s1', 'remotion.title', '導入（短縮）', 2, null, null,
                    ['title' => ['heading' => '入力画像を確認する', 'subheading' => '30 秒で分かる手順', 'layout' => 'center']]],
                ['s2', 'remotion.text', '確認する点', 4, null, null,
                    ['text' => ['heading' => '3 つの点', 'items' => ['解像度', '顔の向き', '明るさ'], 'reveal' => 'all']]],
            ], dark: true);
        });
    }

    /**
     * 見本（タイトルが「【見本】」で始まる制作と設計判断）と、その書き出しのファイルを消す。
     */
    public static function remove(): void
    {
        $disk = Storage::disk(config('remotion.disk'));

        DB::transaction(function () use ($disk) {
            $productions = VideoProduction::query()->where('title', 'like', self::MARK.'%')->get();

            foreach ($productions as $production) {
                foreach ($production->renders as $render) {
                    if ($render->storage_path !== null) {
                        $disk->delete($render->storage_path);
                    }
                }

                $production->renders()->delete();
                ProductionScene::query()->whereIn('production_plan_id', $production->plans()->select('id'))->delete();
                $production->plans()->update(['based_on_id' => null]);
                $production->plans()->delete();
                $production->assets()->delete();
                $production->delete();
            }

            $nodes = KnowledgeNode::query()->where('title', 'like', self::MARK.'%')->pluck('id');
            $edges = KnowledgeEdge::query()->whereIn('source_node_id', $nodes)->orWhereIn('target_node_id', $nodes)->pluck('id');

            foreach ([[KnowledgeNode::class, $nodes], [KnowledgeEdge::class, $edges]] as [$class, $ids]) {
                $type = (new $class)->getMorphClass();
                KnowledgeSource::query()->where('sourceable_type', $type)->whereIn('sourceable_id', $ids)->delete();
                KnowledgeRevision::query()->where('revisable_type', $type)->whereIn('revisable_id', $ids)->delete();
            }

            KnowledgeEdge::query()->whereIn('id', $edges)->delete();
            KnowledgeNode::query()->whereIn('id', $nodes)->delete();
        });
    }

    /**
     * @param  list<array{string, string, string, float|int, ?string, ?string, array<string, mixed>}>  $scenes
     */
    private function plan(VideoProduction $production, int $version, string $status, ?User $user, ?ProductionPlan $basedOn, array $scenes, bool $dark = false): ProductionPlan
    {
        $plan = $production->plans()->create([
            'version' => $version,
            'status' => $status,
            'proposed_by' => KnowledgeNode::PROPOSED_BY_HUMAN,
            'based_on_id' => $basedOn?->id,
            'summary' => $version === 1
                ? '導入で目的を示し、確認する 3 つの点と確認の流れを見せて、まとめる。'
                : '第 1 版を短くし、暗い配色にした版。',
            'settings' => [
                'style' => ['tone' => '落ち着いた解説', 'font' => 'Noto Sans JP', 'theme' => $dark ? 'dark' : 'light'],
                'narration' => ['enabled' => true, 'voice' => '落ち着いた声'],
                'bgm' => null,
            ],
            'estimated_cost' => 0,
            'confirmed_by' => $status === ProductionPlan::STATUS_CONFIRMED ? $user?->id : null,
            'confirmed_at' => $status === ProductionPlan::STATUS_CONFIRMED ? now() : null,
        ]);

        foreach ($scenes as $position => [$key, $type, $title, $seconds, $narration, $caption, $content]) {
            $plan->scenes()->create([
                'scene_key' => $key,
                'track' => ProductionScene::TRACK_VIDEO,
                'position' => $position + 1,
                'scene_type' => $type,
                'title' => $title,
                'duration_seconds' => $seconds,
                'content' => $content,
                'narration' => $narration,
                'caption' => $caption,
                'transition' => 'fade',
                'estimated_cost' => 0,
                'status' => ProductionScene::STATUS_READY,
            ]);
        }

        return $plan;
    }
}
