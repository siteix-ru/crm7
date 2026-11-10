<script lang="ts">
  import { loadItemPhotoUrl } from '../../api/stocks';
  import type { StockPhoto } from '../../api/types';
  import { appSettings } from '../../stores/app-settings.svelte';
  import Icon from '../ui/Icon.svelte';

  interface Props {
    photos: StockPhoto[];
    variant?: 'list' | 'tile';
    onopen?: () => void;
    flush?: boolean;
    index?: number;
    label?: string;
  }

  let { photos, variant = 'list', onopen, flush = false, index = $bindable(0), label = '' }: Props =
    $props();

  const initial = $derived(
    label
      .split(/\s+/)
      .map((part) => part.replace(/^\d+/, ''))
      .find((part) => part !== '')
      ?.charAt(0)
      .toUpperCase() ?? ''
  );

  const photoSize = $derived<'preview' | 'card'>(variant === 'tile' ? 'card' : 'preview');

  let urls = $state<Record<string, string | null>>({});
  let touchX = 0;
  const loaded = new Map<string, string | null>();

  $effect(() => {
    let cancelled = false;
    const required = variant === 'tile' ? photos : photos.slice(0, 1);

    for (const photo of required) {
      const key = `${photo.id}:${photoSize}`;

      if (loaded.has(key)) {
        continue;
      }

      loaded.set(key, null);
      void loadItemPhotoUrl(photo.id, photoSize).then((url) => {
        loaded.set(key, url);

        if (!cancelled) {
          urls[key] = url;
        }
      });
    }

    return () => {
      cancelled = true;
    };
  });

  $effect(() => {
    if (index >= photos.length) {
      index = 0;
    }
  });

  const isCarousel = $derived(variant === 'tile');
  const currentId = $derived(isCarousel ? (photos[index]?.id ?? null) : (photos[0]?.id ?? null));
  const currentKey = $derived(currentId === null ? null : `${currentId}:${photoSize}`);
  const currentUrl = $derived(currentKey === null ? null : urls[currentKey] ?? null);
  const photoAspect = $derived(appSettings.photoAspect);
  const photoFactor = $derived(appSettings.photoFactor);
  const photoFit = $derived(appSettings.photoFit);

  function open(): void {
    onopen?.();
  }

  function onKeydown(event: KeyboardEvent): void {
    if (event.target !== event.currentTarget) {
      return;
    }

    if (onopen !== undefined && (event.key === 'Enter' || event.key === ' ')) {
      event.preventDefault();
      open();
    }
  }

  function prev(): void {
    if (photos.length > 1) {
      index = (index - 1 + photos.length) % photos.length;
    }
  }

  function next(): void {
    if (photos.length > 1) {
      index = (index + 1) % photos.length;
    }
  }

  function onTouchStart(event: TouchEvent): void {
    if (!isCarousel) {
      return;
    }

    touchX = event.touches[0]?.clientX ?? 0;
  }

  function onTouchEnd(event: TouchEvent): void {
    if (!isCarousel) {
      return;
    }

    const dx = (event.changedTouches[0]?.clientX ?? 0) - touchX;

    if (Math.abs(dx) < 30) {
      return;
    }

    if (dx < 0) {
      next();
    } else {
      prev();
    }
  }
</script>

<!-- svelte-ignore a11y_no_noninteractive_tabindex -->
<div
  class="gallery"
  class:tile={variant === 'tile'}
  class:flush
  class:empty={photos.length === 0}
  class:clickable={onopen !== undefined}
  role={onopen !== undefined ? 'button' : 'group'}
  tabindex={onopen !== undefined ? 0 : undefined}
  aria-label={onopen !== undefined ? 'Подробнее о номенклатуре' : 'Фото товара'}
  style="--photo-ratio: {photoAspect}; --photo-factor: {photoFactor}; --photo-fit: {photoFit};"
  onclick={open}
  onkeydown={onKeydown}
  ontouchstart={onTouchStart}
  ontouchend={onTouchEnd}
>
  {#if currentUrl}
    <img src={currentUrl} alt="" loading="lazy" />
  {:else if photos.length === 0}
    <div class="placeholder initial" aria-hidden="true">{initial}</div>
  {:else}
    <div class="placeholder" aria-hidden="true">
      <Icon name="picture" size={variant === 'tile' ? 28 : 18} />
    </div>
  {/if}

  {#if isCarousel && photos.length > 1}
    <button
      type="button"
      class="nav prev"
      aria-label="Предыдущее фото"
      onclick={(event) => {
        event.stopPropagation();
        prev();
      }}>‹</button
    >
    <button
      type="button"
      class="nav next"
      aria-label="Следующее фото"
      onclick={(event) => {
        event.stopPropagation();
        next();
      }}>›</button
    >
    <span class="counter">{index + 1}/{photos.length}</span>
  {/if}
</div>

<style>
  .gallery {
    position: relative;
    flex: 0 0 auto;
    width: calc(56px * var(--photo-factor, 1));
    height: 56px;
    border-radius: var(--radius-sm);
    overflow: hidden;
    background: var(--bg);
    border: 1px solid var(--border);
  }

  .gallery.tile {
    width: 100%;
    height: auto;
    aspect-ratio: var(--photo-ratio, 1 / 1);
  }

  .gallery.flush {
    border: none;
    border-radius: 0;
  }

  .gallery.clickable {
    cursor: pointer;
  }

  img {
    display: block;
    width: 100%;
    height: 100%;
    object-fit: var(--photo-fit, contain);
  }

  .gallery.empty {
    border: none;
    background: transparent;
  }

  .placeholder {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 100%;
    height: 100%;
    background: var(--fill-tertiary);
    color: var(--text-description);
  }

  .placeholder.initial {
    border-radius: var(--radius-sm);
    font-size: 20px;
    font-weight: 600;
    color: var(--muted);
  }

  .nav {
    position: absolute;
    top: 50%;
    transform: translateY(-50%);
    width: 22px;
    height: 22px;
    padding: 0;
    border: none;
    border-radius: 50%;
    background: rgba(0, 0, 0, 0.45);
    color: #fff;
    font-size: 15px;
    line-height: 1;
    cursor: pointer;
    opacity: 0;
    transition: opacity 0.15s ease;
  }

  .gallery:hover .nav,
  .gallery:focus-within .nav {
    opacity: 1;
  }

  @media (hover: none) {
    .nav {
      opacity: 1;
    }
  }

  .nav.prev {
    left: 4px;
  }

  .nav.next {
    right: 4px;
  }

  .counter {
    position: absolute;
    right: 4px;
    bottom: 4px;
    padding: 1px 5px;
    border-radius: 999px;
    background: rgba(0, 0, 0, 0.55);
    color: #fff;
    font-size: 10px;
  }
</style>
