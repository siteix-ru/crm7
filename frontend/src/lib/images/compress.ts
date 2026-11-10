/**
 * Сжатие изображений перед загрузкой: ресайз по длинной стороне и перекодирование
 * в WebP (fallback — исходный файл). Экономит трафик с телефона и снижает риск
 * упереться в лимит размера запроса.
 */
const COMPRESSIBLE = new Set(['image/jpeg', 'image/png', 'image/webp']);
const MIN_BYTES = 300 * 1024;

export interface CompressOptions {
  /** Максимум по длинной стороне, px. */
  maxSize?: number;
  /** Качество WebP, 0..1. */
  quality?: number;
}

export async function compressImage(file: File, options: CompressOptions = {}): Promise<File> {
  const maxSize = options.maxSize ?? 1600;
  const quality = options.quality ?? 0.82;

  if (!COMPRESSIBLE.has(file.type) || file.size < MIN_BYTES || typeof createImageBitmap !== 'function') {
    return file;
  }

  let bitmap: ImageBitmap | null = null;

  try {
    bitmap = await createImageBitmap(file, { imageOrientation: 'from-image' });
    const scale = Math.min(1, maxSize / Math.max(bitmap.width, bitmap.height));
    const width = Math.max(1, Math.round(bitmap.width * scale));
    const height = Math.max(1, Math.round(bitmap.height * scale));

    const canvas = document.createElement('canvas');
    canvas.width = width;
    canvas.height = height;

    const context = canvas.getContext('2d');

    if (context === null) {
      return file;
    }

    context.drawImage(bitmap, 0, 0, width, height);

    const blob = await new Promise<Blob | null>((resolve) => {
      canvas.toBlob(resolve, 'image/webp', quality);
    });

    if (blob === null || blob.size >= file.size) {
      return file;
    }

    const extension = blob.type === 'image/webp' ? 'webp' : blob.type === 'image/png' ? 'png' : 'jpg';
    const baseName = file.name.replace(/\.[^.]+$/, '') || 'image';

    return new File([blob], `${baseName}.${extension}`, {
      type: blob.type || 'image/webp',
      lastModified: Date.now()
    });
  } catch {
    return file;
  } finally {
    bitmap?.close();
  }
}
