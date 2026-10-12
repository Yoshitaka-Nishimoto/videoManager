import { formatBytes, shrinkImage } from './image-compress';

// Blade の Alpine から使う（制作の素材のアップロード）。
window.shrinkImage = shrinkImage;
window.formatBytes = formatBytes;
