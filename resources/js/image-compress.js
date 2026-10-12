// アップロードする前に、ブラウザの中で画像を縮める（制作の素材：ラフ画・サンプル画像）。
//
// 上限（既定 1MB）を超える jpg / png / webp だけを WebP にし直す。WebP は透過を残せて、
// Runway・Gemini も受け付ける。品質を下げても上限に収まらなければ、縦横を縮めてやり直す。
// ブラウザが WebP を書き出せないとき（古い Safari など）は JPEG にする（透過部分は白）。

const COMPRESSIBLE_TYPES = ['image/jpeg', 'image/png', 'image/webp'];
const QUALITIES = [0.88, 0.78, 0.68];
const SHRINK_STEP = 0.85;
const MIN_EDGE = 640;

/**
 * @param {File} file
 * @param {{ maxBytes?: number, maxEdge?: number }} options
 * @returns {Promise<{ file: File, compressed: boolean, before: number, after: number }>}
 */
export async function shrinkImage(file, { maxBytes = 1024 * 1024, maxEdge = 2560 } = {}) {
    const unchanged = { file, compressed: false, before: file.size, after: file.size };

    if (file.size <= maxBytes || !COMPRESSIBLE_TYPES.includes(file.type)) {
        return unchanged;
    }

    let bitmap;
    try {
        // スマホの写真の向き（EXIF）を反映して読み込む。
        bitmap = await createImageBitmap(file, { imageOrientation: 'from-image' });
    } catch {
        return unchanged; // 読めない画像はそのまま送り、サーバーの確認に任せる。
    }

    try {
        let scale = Math.min(1, maxEdge / Math.max(bitmap.width, bitmap.height));
        let best = null;

        while (true) {
            const width = Math.max(1, Math.round(bitmap.width * scale));
            const height = Math.max(1, Math.round(bitmap.height * scale));

            let smallest = null;
            for (const quality of QUALITIES) {
                const blob = await encode(bitmap, width, height, quality);

                if (smallest === null || blob.size < smallest.size) {
                    smallest = blob;
                }
                if (blob.size <= maxBytes) {
                    return result(file, blob);
                }
            }
            if (best === null || smallest.size < best.size) {
                best = smallest;
            }

            // 大きさは面積にほぼ比例するので、足りない分から縮める割合を見積もる（最低でも SHRINK_STEP）。
            const step = Math.min(SHRINK_STEP, Math.sqrt(maxBytes / smallest.size) * 0.95);
            if (Math.min(width, height) * step < MIN_EDGE) {
                break; // これ以上小さくすると見づらいので、一番小さくできたものを使う。
            }
            scale *= step;
        }

        return best !== null && best.size < file.size ? result(file, best) : unchanged;
    } finally {
        bitmap.close();
    }
}

async function encode(bitmap, width, height, quality) {
    const canvas = document.createElement('canvas');
    canvas.width = width;
    canvas.height = height;
    const context = canvas.getContext('2d');
    context.imageSmoothingQuality = 'high';
    context.drawImage(bitmap, 0, 0, width, height);

    const webp = await toBlob(canvas, 'image/webp', quality);
    if (webp?.type === 'image/webp') {
        return webp;
    }

    // WebP を書き出せないブラウザ：透過部分を白で塗ってから JPEG にする。
    context.globalCompositeOperation = 'destination-over';
    context.fillStyle = '#ffffff';
    context.fillRect(0, 0, width, height);

    return toBlob(canvas, 'image/jpeg', quality);
}

function toBlob(canvas, type, quality) {
    return new Promise((resolve) => canvas.toBlob(resolve, type, quality));
}

function result(original, blob) {
    const extension = blob.type === 'image/webp' ? 'webp' : 'jpg';
    const name = original.name.replace(/\.[^.]+$/, '') + '.' + extension;

    return {
        file: new File([blob], name, { type: blob.type, lastModified: original.lastModified }),
        compressed: true,
        before: original.size,
        after: blob.size,
    };
}

export function formatBytes(bytes) {
    return bytes >= 1024 * 1024 ? (bytes / 1024 / 1024).toFixed(1) + 'MB' : Math.max(1, Math.round(bytes / 1024)) + 'KB';
}
