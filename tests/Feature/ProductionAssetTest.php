<?php

namespace Tests\Feature;

use App\Models\ProductionAsset;
use App\Models\ProductionPlan;
use App\Models\ProductionScene;
use App\Models\User;
use App\Models\VideoProduction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class ProductionAssetTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private VideoProduction $production;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->user = User::factory()->create(['name' => '西本']);
        $this->actingAs($this->user);
        $this->production = VideoProduction::factory()->create();
    }

    public function test_images_are_uploaded_into_the_production_asset_folder(): void
    {
        Livewire::test('pages::productions.show', ['production' => $this->production])
            ->set('uploads', [
                UploadedFile::fake()->image('rough-s1.png', 640, 360),
                UploadedFile::fake()->image('rough-s2.jpg', 640, 360),
            ])
            ->set('uploadKind', ProductionAsset::KIND_MOCK)
            ->call('uploadAssets')
            ->assertHasNoErrors()
            ->assertSee('2 枚の画像を素材に追加しました。')
            ->assertSee('rough-s1')
            ->assertSee('ラフ画');

        $assets = $this->production->assets()->orderBy('id')->get();
        $this->assertCount(2, $assets);
        $this->assertSame(['rough-s1', 'rough-s2'], $assets->pluck('title')->all());
        $this->assertSame(['rough-s1.png', 'rough-s2.jpg'], $assets->pluck('original_name')->all());
        $this->assertSame(['image/png', 'image/jpeg'], $assets->pluck('mime_type')->all());

        foreach ($assets as $asset) {
            $this->assertSame(ProductionAsset::KIND_MOCK, $asset->kind);
            $this->assertSame($this->user->id, $asset->uploaded_by);
            $this->assertStringStartsWith("productions/{$this->production->id}/assets/", $asset->storage_path);
            Storage::disk('local')->assertExists($asset->storage_path);
        }
    }

    public function test_a_title_can_be_given_to_a_sample_image(): void
    {
        Livewire::test('pages::productions.show', ['production' => $this->production])
            ->set('uploads', [UploadedFile::fake()->image('photo.webp')])
            ->set('uploadKind', ProductionAsset::KIND_IMAGE)
            ->set('uploadTitle', '導入の背景の雰囲気')
            ->call('uploadAssets')
            ->assertHasNoErrors();

        $asset = $this->production->assets()->sole();
        $this->assertSame(['導入の背景の雰囲気', ProductionAsset::KIND_IMAGE], [$asset->title, $asset->kind]);
    }

    public function test_only_still_images_can_be_uploaded(): void
    {
        Livewire::test('pages::productions.show', ['production' => $this->production])
            ->set('uploads', [UploadedFile::fake()->create('notes.pdf', 10, 'application/pdf')])
            ->call('uploadAssets')
            ->assertHasErrors('uploads.0');

        Livewire::test('pages::productions.show', ['production' => $this->production])
            ->set('uploads', [UploadedFile::fake()->image('huge.png')->size(10241)])
            ->call('uploadAssets')
            ->assertHasErrors('uploads.0');

        Livewire::test('pages::productions.show', ['production' => $this->production])
            ->set('uploads', [UploadedFile::fake()->image('a.png')])
            ->set('uploadKind', ProductionAsset::KIND_VIDEO)
            ->call('uploadAssets')
            ->assertHasErrors('uploadKind');

        Livewire::test('pages::productions.show', ['production' => $this->production])
            ->call('uploadAssets')
            ->assertHasErrors('uploads');

        $this->assertSame(0, ProductionAsset::count());
    }

    public function test_an_asset_file_is_shown_only_to_signed_in_users(): void
    {
        $asset = $this->upload('rough.png');

        $this->get(route('production-assets.file', $asset))->assertOk()->assertHeader('Content-Type', 'image/png');

        auth()->logout();
        $this->get(route('production-assets.file', $asset))->assertRedirect('/login');
    }

    public function test_an_unused_asset_can_be_deleted_with_its_file(): void
    {
        $asset = $this->upload('rough.png');

        Livewire::test('pages::productions.show', ['production' => $this->production])
            ->call('deleteAsset', $asset->id)
            ->assertHasNoErrors();

        $this->assertModelMissing($asset);
        Storage::disk('local')->assertMissing($asset->storage_path);
    }

    public function test_an_asset_used_by_a_scene_or_a_plan_cannot_be_deleted(): void
    {
        $inScene = $this->upload('scene.png');
        $asMock = $this->upload('mock.png');
        $plan = ProductionPlan::factory()->create(['video_production_id' => $this->production->id, 'mock_asset_ids' => [$asMock->id]]);
        ProductionScene::factory()->create([
            'production_plan_id' => $plan->id,
            'scene_key' => 's2',
            'content' => ['image' => ['source' => ['asset_id' => $inScene->id], 'motion' => 'zoom_in']],
        ]);

        Livewire::test('pages::productions.show', ['production' => $this->production])
            ->call('deleteAsset', $inScene->id)
            ->assertHasErrors('asset')
            ->assertSee('場面 s2')
            ->call('deleteAsset', $asMock->id)
            ->assertSee('モック図');

        $this->assertModelExists($inScene);
        $this->assertModelExists($asMock);
        Storage::disk('local')->assertExists($inScene->storage_path);
    }

    public function test_assets_of_another_production_cannot_be_deleted_from_this_page(): void
    {
        $other = ProductionAsset::factory()->create();

        Livewire::test('pages::productions.show', ['production' => $this->production])
            ->call('deleteAsset', $other->id);

        $this->assertModelExists($other);
    }

    private function upload(string $name): ProductionAsset
    {
        Livewire::test('pages::productions.show', ['production' => $this->production])
            ->set('uploads', [UploadedFile::fake()->image($name)])
            ->call('uploadAssets')
            ->assertHasNoErrors();

        return $this->production->assets()->latest('id')->firstOrFail();
    }
}
