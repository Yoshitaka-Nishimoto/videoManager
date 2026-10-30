<?php

namespace App\Actions\Videos;

use App\Models\User;
use App\Models\Video;
use App\Services\Files\PersonalFileException;
use App\Services\Files\PersonalFileReader;
use App\Services\Knowledge\KnowledgeCandidateWriter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * 利用者がアップロードした個人のファイル（血液検査の結果の CSV など）を、本人だけが見られる情報源として登録する。
 *
 * ファイルは公開されない local ディスクの resources/{利用者 ID}/{中身のハッシュ}.csv に置く。
 * 同じ中身のファイルは二重に登録しない（削除済みなら復元する）。
 */
class RegisterPersonalFile
{
    public function __construct(
        private readonly PersonalFileReader $files,
        private readonly KnowledgeCandidateWriter $knowledge,
    ) {}

    /**
     * @return array{video: Video, created: bool}
     *
     * @throws PersonalFileException
     */
    public function handle(UploadedFile $file, User $user, ?string $genre): array
    {
        $contents = (string) $file->get();

        // 読めないファイルは保存する前に知らせる。
        $this->files->toText($contents);

        $path = "resources/{$user->id}/".hash('sha256', $contents).'.csv';

        $existing = Video::withTrashed()->visibleTo($user->id)
            ->where('owner_id', $user->id)
            ->where('source_type', Video::SOURCE_FILE)
            ->where('storage_path', $path)
            ->first();

        if ($existing?->trashed()) {
            $existing->restore();

            return ['video' => $existing, 'created' => true];
        }

        if ($existing !== null) {
            return ['video' => $existing, 'created' => false];
        }

        Storage::disk(PersonalFileReader::DISK)->put($path, $contents);

        $video = DB::transaction(function () use ($file, $user, $genre, $path) {
            $video = Video::query()->create([
                'source_type' => Video::SOURCE_FILE,
                'storage_path' => $path,
                'title' => Str::limit($file->getClientOriginalName(), 250, ''),
                'genre' => $genre,
                'registered_by' => $user->id,
                'owner_id' => $user->id,
            ]);

            $this->knowledge->videoNode($video);

            return $video;
        });

        return ['video' => $video, 'created' => true];
    }
}
