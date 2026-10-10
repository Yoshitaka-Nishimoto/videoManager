<?php

namespace App\Http\Controllers;

use App\Models\ProductionPlan;
use App\Services\Remotion\RemotionInput;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * 読み取り専用の Remotion Studio を配信する（remotion/ で npm run bundle して作る）。
 *
 * 書き出しもコードへの書き戻しもできない Studio で、制作案を確認するためのもの。
 * 読み取り専用の Studio は、表示するコンポジションを URL の「?」の後ろで決める（例：?/Production）。
 * そのため制作案はパスで渡す：/remotion-studio/plans/{制作案ID}/{場面キー（省略可）}/?/Production
 * 制作案の入力をページに埋め込み、Studio はそれを表示する（remotion/src/studio-input.ts）。
 */
class RemotionStudioController extends Controller
{
    public function __invoke(RemotionInput $input, string $path = ''): Response|BinaryFileResponse
    {
        $buildDirectory = realpath(config('remotion.project_path').'/'.config('remotion.studio_build_directory'));

        abort_if($buildDirectory === false || ! is_file($buildDirectory.'/index.html'), 404, 'Studio がまだ作られていません。remotion/ で npm run bundle を実行してください。');

        // バンドルの中のファイル（.js など）はそのまま返す。ディレクトリの外は返さない。
        $file = $path !== '' ? realpath($buildDirectory.'/'.$path) : false;

        if ($file !== false && is_file($file) && str_starts_with($file, $buildDirectory.DIRECTORY_SEPARATOR)) {
            return response()->file($file, ['Cache-Control' => 'no-cache']);
        }

        // それ以外（Studio の画面の URL）は index.html を返す。
        return response($this->indexHtml($buildDirectory, $path, $input))
            ->header('Content-Type', 'text/html; charset=UTF-8')
            ->header('Cache-Control', 'no-store');
    }

    /**
     * 読み取り専用の Studio にも「Render in browser」（ブラウザ内での書き出し）が残っているため、
     * 「Render」を含むボタンとメニューを表示されたらすぐ隠す。書き出しは Laravel の制作の画面から行う。
     */
    private const HIDE_RENDER_SCRIPT = <<<'JS'
        (function () {
            var hide = function (root) {
                root.querySelectorAll('button, [role="menuitem"], [role="button"]').forEach(function (el) {
                    var label = (el.getAttribute('aria-label') || '') + ' ' + (el.textContent || '');
                    if (/\bRender\b/.test(label) && el.style.display !== 'none') {
                        el.style.display = 'none';
                        el.setAttribute('disabled', 'disabled');
                    }
                });
            };
            new MutationObserver(function () { hide(document); }).observe(document.documentElement, { childList: true, subtree: true });
            document.addEventListener('DOMContentLoaded', function () { hide(document); });
        })();
        JS;

    private function indexHtml(string $buildDirectory, string $path, RemotionInput $input): string
    {
        $html = file_get_contents($buildDirectory.'/index.html');
        $script = '<script>'.self::HIDE_RENDER_SCRIPT;

        if (preg_match('#^plans/(\d+)(?:/([A-Za-z0-9_-]{1,16}))?/?$#', $path, $matches)) {
            $plan = ProductionPlan::find((int) $matches[1]);

            abort_if($plan === null, 404, '制作案が見つかりません。');

            $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_THROW_ON_ERROR;
            $script .= 'window.videoManagerStudioInput = '.json_encode($input->forPlan($plan), $flags).';'
                .'window.videoManagerStudioSceneKey = '.json_encode($matches[2] ?? null, $flags).';';
        }

        // Studio のスクリプトより前に置く。
        return preg_replace('/<script/i', $script.'</script><script', $html, 1) ?? $html;
    }
}
