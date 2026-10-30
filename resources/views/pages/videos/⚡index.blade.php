<?php

use App\Actions\Videos\RegisterPersonalFile;
use App\Actions\Videos\RegisterWebPage;
use App\Actions\Videos\RegisterYouTubeVideo;
use App\Models\Video;
use App\Models\VideoProduction;
use App\Services\Files\PersonalFileException;
use App\Services\Files\PersonalFileReader;
use App\Services\Web\WebPageException;
use App\Services\YouTube\YouTubeException;
use App\Services\YouTube\YouTubeVideoId;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Attributes\Validate;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

new #[Title('情報源一覧')] class extends Component
{
    use WithFileUploads, WithPagination;

    #[Validate('required|string|max:2048', as: 'URL')]
    public string $url = '';

    /** 個人のファイル（血液検査の結果の CSV など）。本人だけが見られる情報源として登録する。 */
    public ?TemporaryUploadedFile $file = null;

    public string $fileGenre = 'healthy_longevity';

    /** 一覧の絞り込み。VideoProduction::GENRES のキー、または未設定（GENRE_NONE）。 */
    #[Url]
    public string $genre = '';

    private const GENRE_NONE = 'none';

    public function updatedGenre(): void
    {
        $this->resetPage();
    }

    /**
     * YouTube の動画 URL は動画として、それ以外（公式サイト・GitHub など）は Web ページとして登録する。
     */
    public function register(RegisterYouTubeVideo $registerYouTube, RegisterWebPage $registerWebPage): void
    {
        $this->validate();

        $isYouTube = YouTubeVideoId::parse($this->url) !== null;

        try {
            if (! $isYouTube && $this->isYouTubeHost($this->url)) {
                throw new YouTubeException('YouTube の動画 URL として読み取れませんでした。');
            }

            ['video' => $video, 'created' => $created] = $isYouTube
                ? $registerYouTube->handle($this->url, auth()->user())
                : $registerWebPage->handle($this->url, auth()->user());
        } catch (YouTubeException|WebPageException $e) {
            $this->addError('url', $e->getMessage());

            return;
        }

        $kind = $video->isWebPage() ? 'ページ' : '動画';
        session()->flash('status', $created ? "{$kind}を登録しました。" : "この{$kind}は登録済みです。");

        $this->redirectRoute('videos.show', $video, navigate: true);
    }

    public function upload(RegisterPersonalFile $register): void
    {
        $this->validate([
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:'.PersonalFileReader::MAX_KILOBYTES],
            'fileGenre' => ['nullable', Rule::in(array_keys(VideoProduction::GENRES))],
        ], attributes: ['file' => 'ファイル', 'fileGenre' => 'ジャンル']);

        try {
            ['video' => $video, 'created' => $created] = $register->handle($this->file, auth()->user(), $this->fileGenre ?: null);
        } catch (PersonalFileException $e) {
            $this->addError('file', $e->getMessage());

            return;
        }

        session()->flash('status', $created ? 'ファイルを登録しました。あなただけが見られます。' : 'このファイルは登録済みです。');

        $this->redirectRoute('videos.show', $video, navigate: true);
    }

    /**
     * YouTube のチャンネルや再生リストなど、動画ではない YouTube の URL を Web ページとして登録しないため。
     */
    private function isYouTubeHost(string $url): bool
    {
        $host = strtolower((string) parse_url(trim($url), PHP_URL_HOST));

        return in_array(preg_replace('/^(www\.|m\.|music\.)/', '', $host), ['youtube.com', 'youtu.be', 'youtube-nocookie.com'], true);
    }

    /**
     * カードで選んだジャンルをすぐ保存する。空を選ぶと未設定に戻す。
     */
    public function setGenre(Video $video, string $genre): void
    {
        if ($genre !== '' && ! array_key_exists($genre, VideoProduction::GENRES)) {
            $this->addError("genre.{$video->id}", '選べないジャンルです。');

            return;
        }

        $video->update(['genre' => $genre ?: null]);
        unset($this->videos);
    }

    /**
     * @return LengthAwarePaginator<int, Video>
     */
    #[Computed]
    public function videos(): LengthAwarePaginator
    {
        return Video::query()
            ->with('latestAnalysis')
            ->when($this->genre === self::GENRE_NONE, fn ($query) => $query->whereNull('genre'))
            ->when(array_key_exists($this->genre, VideoProduction::GENRES), fn ($query) => $query->where('genre', $this->genre))
            ->latest('id')
            ->paginate(12);
    }
};
?>

<div class="space-y-8">
    <section>
        <h1 class="mb-4 text-xl font-semibold">情報源一覧</h1>

        <form wire:submit="register" class="flex flex-col gap-2 sm:flex-row">
            <label for="url" class="sr-only">URL</label>
            <input
                id="url"
                type="text"
                wire:model="url"
                placeholder="YouTube の動画、公式サイト、GitHub などの URL"
                class="w-full rounded-sm border border-[#19140035] bg-white px-3 py-2 text-sm dark:border-[#3E3E3A] dark:bg-[#161615]"
            >
            <button
                type="submit"
                class="shrink-0 rounded-sm bg-[#1b1b18] px-5 py-2 text-sm text-white hover:bg-black disabled:opacity-50 dark:bg-[#EDEDEC] dark:text-[#1b1b18]"
                wire:loading.attr="disabled"
            >
                <span wire:loading.remove wire:target="register">登録</span>
                <span wire:loading wire:target="register">取得中…</span>
            </button>
        </form>
        @error('url')
            <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
        @enderror
        <p class="mt-2 text-xs text-[#706f6c] dark:text-[#A1A09A]">YouTube 以外の URL は Web ページとして登録し、本文を読んで分析します。ログインが必要なページと PDF にはまだ対応していません。</p>

        <form wire:submit="upload" class="mt-4 flex flex-col gap-2 border-t border-[#19140015] pt-4 sm:flex-row sm:items-center dark:border-[#3E3E3A]">
            <label for="file" class="shrink-0 text-sm">個人のファイル</label>
            <input
                id="file"
                type="file"
                wire:model="file"
                accept=".csv,text/csv,text/plain"
                class="w-full text-sm file:mr-3 file:rounded-sm file:border file:border-[#19140035] file:bg-white file:px-3 file:py-1 dark:file:border-[#3E3E3A] dark:file:bg-[#161615]"
            >
            <select wire:model="fileGenre" aria-label="ファイルのジャンル" class="rounded-sm border border-[#19140035] bg-white px-2 py-2 text-sm dark:border-[#3E3E3A] dark:bg-[#161615]">
                <option value="">ジャンル未設定</option>
                @foreach (VideoProduction::GENRES as $key => $name)
                    <option value="{{ $key }}">{{ $name }}</option>
                @endforeach
            </select>
            <button
                type="submit"
                class="shrink-0 rounded-sm border border-[#1b1b18] px-5 py-2 text-sm hover:bg-[#f0f0ec] disabled:opacity-50 dark:border-[#EDEDEC] dark:hover:bg-[#1f1f1e]"
                wire:loading.attr="disabled"
                wire:target="file,upload"
            >アップロード</button>
        </form>
        @error('file')
            <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
        @enderror
        <p class="mt-2 text-xs text-[#706f6c] dark:text-[#A1A09A]">血液検査の結果などの CSV（1 行目が見出し）を登録します。登録したファイルと、その分析・知識は、あなただけが見られます。</p>
    </section>

    <section>
        <div class="mb-4 flex justify-end text-sm">
            <select wire:model.live="genre" aria-label="ジャンルで絞り込む" class="rounded-sm border border-[#19140035] bg-white px-2 py-1 dark:border-[#3E3E3A] dark:bg-[#161615]">
                <option value="">すべてのジャンル</option>
                @foreach (VideoProduction::GENRES as $key => $name)
                    <option value="{{ $key }}">{{ $name }}</option>
                @endforeach
                <option value="none">ジャンル未設定</option>
            </select>
        </div>

        @if ($this->videos->isEmpty() && $genre !== '')
            <p class="text-sm text-[#706f6c] dark:text-[#A1A09A]">このジャンルの情報源はありません。</p>
        @elseif ($this->videos->isEmpty())
            <p class="text-sm text-[#706f6c] dark:text-[#A1A09A]">まだ情報源が登録されていません。YouTube の動画や、公式サイト・GitHub などの URL を貼って登録してください。</p>
        @else
            <ul class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($this->videos as $video)
                    <li wire:key="video-{{ $video->id }}" class="overflow-hidden rounded-sm border border-[#19140035] hover:border-[#1915014a] dark:border-[#3E3E3A] dark:hover:border-[#62605b]">
                        <a href="{{ route('videos.show', $video) }}" wire:navigate class="block">
                            @if ($video->thumbnail_url)
                                <img src="{{ $video->thumbnail_url }}" alt="" class="aspect-video w-full object-cover" loading="lazy">
                            @else
                                <div class="aspect-video w-full bg-[#f0f0ec] dark:bg-[#1f1f1e]"></div>
                            @endif
                            <div class="space-y-1 p-3">
                                <p class="line-clamp-2 text-sm font-medium">{{ $video->title }}</p>
                                <p class="text-xs text-[#706f6c] dark:text-[#A1A09A]">
                                    <span class="mr-1 rounded-full border border-[#19140035] px-1.5 dark:border-[#3E3E3A]">{{ $video->sourceLabel() }}</span>
                                    @if ($video->isPersonal())
                                        <span class="mr-1 rounded-full bg-amber-100 px-1.5 text-amber-900 dark:bg-amber-950 dark:text-amber-200">本人だけ</span>
                                    @endif
                                    @if ($video->isFile())
                                        {{ $video->created_at?->format('Y-m-d') }} 登録
                                    @else
                                        {{ $video->channel_title ?? '—' }}{{ $video->isDocument() ? '' : ' · '.Video::formatSeconds($video->duration_seconds) }}
                                    @endif
                                </p>
                                @if ($video->statistics_fetched_at)
                                    <p class="text-xs text-[#706f6c] dark:text-[#A1A09A]">
                                        再生 {{ Video::formatCount($video->view_count) }} 回 · 高評価 {{ Video::formatCount($video->like_count) }}
                                    </p>
                                @endif
                                <p class="text-xs">
                                    @if ($video->latestAnalysis)
                                        分析：{{ $video->latestAnalysis->statusLabel() }}（第{{ $video->latestAnalysis->version }}版）
                                    @else
                                        <span class="text-[#706f6c] dark:text-[#A1A09A]">未分析</span>
                                    @endif
                                </p>
                            </div>
                        </a>
                        {{-- リンクの中に置くと選ぶときに画面が移るため、リンクの外に置く。 --}}
                        <div class="px-3 pb-3">
                            <select
                                wire:change="setGenre({{ $video->id }}, $event.target.value)"
                                aria-label="「{{ $video->title }}」のジャンル"
                                class="w-full rounded-sm border border-[#19140035] bg-white px-2 py-1 text-xs dark:border-[#3E3E3A] dark:bg-[#161615]"
                            >
                                <option value="">ジャンル未設定</option>
                                @foreach (VideoProduction::GENRES as $key => $name)
                                    <option wire:key="video-{{ $video->id }}-genre-{{ $key }}" value="{{ $key }}" @selected($video->genre === $key)>{{ $name }}</option>
                                @endforeach
                            </select>
                            @error("genre.{$video->id}") <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                        </div>
                    </li>
                @endforeach
            </ul>

            <div class="mt-6">
                {{ $this->videos->links() }}
            </div>
        @endif
    </section>
</div>
