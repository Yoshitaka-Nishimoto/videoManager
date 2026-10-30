<?php

namespace App\Models;

use Database\Factories\AiUsageLogFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

#[Fillable([
    'provider',
    'model',
    'feature',
    'usable_type',
    'usable_id',
    'input_tokens',
    'output_tokens',
    'estimated_cost',
    'succeeded',
    'error_message',
])]
class AiUsageLog extends Model
{
    /** @use HasFactory<AiUsageLogFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    public const FEATURE_ANALYSIS = 'analysis';

    /** 制作案の台本の候補作り（usable は制作案）。 */
    public const FEATURE_SCRIPT = 'script';

    /** Runway での画像・動画の生成（usable は Runway の生成依頼）。費用はクレジットから計算し、トークン数は 0。 */
    public const FEATURE_GENERATION = 'generation';

    /** 知識ノードの埋め込み（pgvector の検索・重複の発見）。出力トークンは 0。 */
    public const FEATURE_EMBEDDING = 'embedding';

    /** 知識への質問（知識一覧の「確認済み」の質問欄）。usable はなし。 */
    public const FEATURE_CHAT = 'knowledge_chat';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
            'estimated_cost' => 'decimal:6',
            'succeeded' => 'boolean',
        ];
    }

    /**
     * AI を呼び出した対象（分析など）。
     *
     * @return MorphTo<Model, $this>
     */
    public function usable(): MorphTo
    {
        // 削除済みの分析の使用量も、呼び出し元として表示できるよう含める。
        return $this->morphTo()->withTrashed();
    }
}
