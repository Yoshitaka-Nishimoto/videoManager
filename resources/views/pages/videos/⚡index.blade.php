<?php

use App\Actions\Videos\RegisterYouTubeVideo;
use App\Models\Video;
use App\Services\YouTube\YouTubeException;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Validate;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('動画一覧')] class extends Component
{
    use WithPagination;

    #[Validate('required|string|max:2048', as: 'YouTube URL')]
    public string $url = '';

    public function register(RegisterYouTubeVideo $register): void
    {
        $this->validate();

        try {
            ['video' => $video, 'created' => $created] = $register->handle($this->url, auth()->user());
        } catch (YouTubeException $e) {
            $this->addError('url', $e->getMessage());

            return;
        }

        session()->flash('status', $created ? '動画を登録しました。' : 'この動画は登録済みです。');

        $this->redirectRoute('videos.show', $video, navigate: true);
    }

    /**
     * @return LengthAwarePaginator<int, Video>
     */
    #[Computed]
    public function videos(): LengthAwarePaginator
    {
        return Video::query()
            ->with('latestAnalysis')
            ->latest('id')
            ->paginate(12);
    }
};
?>

<div class="space-y-8">
    <section>
        <h1 class="mb-4 text-xl font-semibold">動画一覧</h1>

        <form wire:submit="register" class="flex flex-col gap-2 sm:flex-row">
            <label for="url" class="sr-only">YouTube URL</label>
            <input
                id="url"
                type="text"
                wire:model="url"
                placeholder="https://www.youtube.com/watch?v=..."
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
    </section>

    <section>
        @if ($this->videos->isEmpty())
            <p class="text-sm text-[#706f6c] dark:text-[#A1A09A]">まだ動画が登録されていません。YouTube の URL を貼って登録してください。</p>
        @else
            <ul class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($this->videos as $video)
                    <li wire:key="video-{{ $video->id }}">
                        <a href="{{ route('videos.show', $video) }}" wire:navigate class="block overflow-hidden rounded-sm border border-[#19140035] hover:border-[#1915014a] dark:border-[#3E3E3A] dark:hover:border-[#62605b]">
                            @if ($video->thumbnail_url)
                                <img src="{{ $video->thumbnail_url }}" alt="" class="aspect-video w-full object-cover" loading="lazy">
                            @else
                                <div class="aspect-video w-full bg-[#f0f0ec] dark:bg-[#1f1f1e]"></div>
                            @endif
                            <div class="space-y-1 p-3">
                                <p class="line-clamp-2 text-sm font-medium">{{ $video->title }}</p>
                                <p class="text-xs text-[#706f6c] dark:text-[#A1A09A]">
                                    {{ $video->channel_title ?? '—' }} · {{ Video::formatSeconds($video->duration_seconds) }}
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
                    </li>
                @endforeach
            </ul>

            <div class="mt-6">
                {{ $this->videos->links() }}
            </div>
        @endif
    </section>
</div>
