<?php

namespace App\Actions\Productions;

use App\Models\ProductionAsset;
use Illuminate\Support\Facades\Storage;

/**
 * 素材を削除する。Runway に渡した素材と、制作案・場面から参照している素材は消さない（参照先がなくなるため）。
 */
class DeleteProductionAsset
{
    public function handle(ProductionAsset $asset): void
    {
        if ($reason = $this->usedBy($asset)) {
            throw new AssetInUseException("この素材は{$reason}で使っているため削除できません。");
        }

        $asset->delete();
        Storage::disk(ProductionAsset::DISK)->delete($asset->storage_path);
    }

    private function usedBy(ProductionAsset $asset): ?string
    {
        if ($asset->runwayInputs()->exists()) {
            return ' Runway の生成依頼';
        }

        foreach ($asset->production->plans()->with('scenes')->get() as $plan) {
            if (in_array($asset->id, $plan->mock_asset_ids ?? [], true)) {
                return "制作案（第{$plan->version}版）のモック図";
            }

            foreach ($plan->scenes as $scene) {
                if ($this->refersTo($scene->content, $asset->id)) {
                    return "制作案（第{$plan->version}版）の場面 {$scene->scene_key}";
                }
            }
        }

        return null;
    }

    /**
     * 場面の内容のどこかに { "asset_id": ID } があるか（public_docs/scene_content.md の素材の参照）。
     */
    private function refersTo(mixed $content, int $assetId): bool
    {
        if (! is_array($content)) {
            return false;
        }

        if (isset($content['asset_id']) && (int) $content['asset_id'] === $assetId) {
            return true;
        }

        foreach ($content as $value) {
            if ($this->refersTo($value, $assetId)) {
                return true;
            }
        }

        return false;
    }
}
