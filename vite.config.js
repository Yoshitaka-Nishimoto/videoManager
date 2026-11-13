import { defineConfig, loadEnv } from 'vite';
import laravel from 'laravel-vite-plugin';
import { bunny } from 'laravel-vite-plugin/fonts';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig(({ mode }) => {
    const env = loadEnv(mode, process.cwd(), '');

    // 他の PC から IP アドレス（例：http://192.168.0.7）で開くときは、.env に VITE_DEV_HOST=192.168.0.7 を書く。
    // 開発サーバー（npm run dev / php artisan dev）の CSS・JS を、localhost ではなくその IP アドレスから読ませる
    // （public/hot がその IP アドレスになる）。書かなければこれまでどおり localhost。
    const devHost = env.VITE_DEV_HOST || null;

    return {
        plugins: [
            laravel({
                input: ['resources/css/app.css', 'resources/js/app.js'],
                refresh: true,
                fonts: [
                    bunny('Instrument Sans', {
                        weights: [400, 500, 600],
                    }),
                ],
            }),
            tailwindcss(),
        ],
        server: {
            ...(devHost
                ? {
                      hmr: { host: devHost },
                      // ページ（http://<IP>）と開発サーバー（http://<IP>:5173）は別のオリジンなので、読み込みを許す。
                      cors: {
                          origin: [
                              /^https?:\/\/(localhost|127\.0\.0\.1|\[::1\])(:\d+)?$/,
                              new RegExp(`^https?://${devHost.replace(/\./g, '\\.')}(:\\d+)?$`),
                              ...(env.APP_URL ? [env.APP_URL] : []),
                          ],
                      },
                  }
                : {}),
            watch: {
                ignored: ['**/storage/framework/views/**'],
            },
        },
    };
});
