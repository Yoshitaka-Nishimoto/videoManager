<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * production_renders を Remotion の書き出し記録にする（public_docs/remotion_tables.md）。
 *
 * 書き出しごとに「どのコードの版に、どの入力を渡したか」と書き出しの設定を残し、同じ動画を作り直せるようにする。
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('production_renders', function (Blueprint $table) {
            $table->string('kind', 16)->comment('種類（still / preview / final）')->change();

            $table->foreignId('production_scene_id')->nullable()->after('production_plan_id')->index()->comment('静止画を書き出した場面（kind = still のとき）')->constrained()->nullOnDelete();
            $table->string('composition_id', 64)->after('kind')->comment('コンポジションID（Production / Scene）');
            $table->json('input_props')->nullable()->after('composition_id')->comment('Remotionに渡した入力の写し（素材はURLに変換した後のもの）');
            $table->string('code_version', 64)->nullable()->after('input_props')->comment('Remotionのコードの版（Gitのコミット ID）');
            $table->string('remotion_version', 32)->nullable()->after('code_version')->comment('Remotionのバージョン');
            $table->string('codec', 16)->nullable()->after('remotion_version')->comment('動画の形式（h264 / h265 / vp9 など）。静止画では空');
            $table->string('image_format', 8)->nullable()->after('codec')->comment('フレームの画像形式（jpeg / png / webp）');
            $table->unsignedSmallInteger('width')->nullable()->after('image_format')->comment('横の画素数');
            $table->unsignedSmallInteger('height')->nullable()->after('width')->comment('縦の画素数');
            $table->unsignedTinyInteger('fps')->nullable()->after('height')->comment('1秒あたりのフレーム数');
            $table->unsignedInteger('duration_in_frames')->nullable()->after('fps')->comment('長さ（フレーム数）');
            $table->unsignedInteger('frame')->nullable()->after('duration_in_frames')->comment('静止画にしたフレーム（kind = still のとき）');
            $table->decimal('scale', 4, 2)->nullable()->after('frame')->comment('書き出しの倍率（確認用は0.5など）');
            $table->unsignedTinyInteger('crf')->nullable()->after('scale')->comment('画質（小さいほど高画質）');
            $table->string('stage', 16)->nullable()->after('progress')->comment('進行中の段階（bundling / rendering / encoding / muxing）');
            $table->unsignedInteger('rendered_frames')->nullable()->after('stage')->comment('画像にしたフレーム数');
            $table->unsignedInteger('encoded_frames')->nullable()->after('rendered_frames')->comment('エンコードしたフレーム数');
            $table->string('mime_type', 64)->nullable()->after('storage_path')->comment('出力のMIMEタイプ');
            $table->unsignedBigInteger('size_bytes')->nullable()->after('mime_type')->comment('出力のファイルサイズ');
            $table->unsignedInteger('render_ms')->nullable()->after('size_bytes')->comment('書き出しにかかった時間（ミリ秒）');
            $table->text('log')->nullable()->after('error_message')->comment('失敗したときのRemotionの出力の末尾');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('production_renders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('production_scene_id');
            $table->dropColumn([
                'composition_id', 'input_props', 'code_version', 'remotion_version', 'codec', 'image_format',
                'width', 'height', 'fps', 'duration_in_frames', 'frame', 'scale', 'crf',
                'stage', 'rendered_frames', 'encoded_frames', 'mime_type', 'size_bytes', 'render_ms', 'log',
            ]);
            $table->string('kind', 16)->comment('種類（preview / final）')->change();
        });
    }
};
