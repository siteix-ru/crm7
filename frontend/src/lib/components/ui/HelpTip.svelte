<script lang="ts">
  import { onMount } from 'svelte';
  import Icon from './Icon.svelte';

  interface Props {
    text: string;
    label?: string;
  }

  let { text, label = 'Подсказка' }: Props = $props();

  let open = $state(false);
  let root = $state<HTMLSpanElement | null>(null);
  let popup = $state<HTMLSpanElement | null>(null);
  let position = $state<{ top: number; left: number } | null>(null);

  const viewportMargin = 8;

  onMount(() => {
    const onPointerDown = (event: PointerEvent): void => {
      if (!open) {
        return;
      }

      const target = event.target;

      if (root !== null && target instanceof Node && !root.contains(target)) {
        open = false;
      }
    };
    const onKeyDown = (event: KeyboardEvent): void => {
      if (event.key === 'Escape') {
        open = false;
      }
    };

    document.addEventListener('pointerdown', onPointerDown, true);
    document.addEventListener('keydown', onKeyDown);

    return () => {
      document.removeEventListener('pointerdown', onPointerDown, true);
      document.removeEventListener('keydown', onKeyDown);
    };
  });

  $effect(() => {
    if (!open) {
      position = null;
      return;
    }

    if (root === null || popup === null) {
      return;
    }

    let frame = 0;

    const place = (): void => {
      if (root === null || popup === null) {
        return;
      }

      const trigger = root.getBoundingClientRect();
      const width = popup.offsetWidth;
      const height = popup.offsetHeight;
      const maxLeft = Math.max(viewportMargin, window.innerWidth - width - viewportMargin);
      const left = Math.min(
        Math.max(viewportMargin, trigger.right - width),
        maxLeft
      );

      let top = trigger.bottom + 6;

      if (top + height > window.innerHeight - viewportMargin) {
        const above = trigger.top - height - 6;
        top = above >= viewportMargin
          ? above
          : Math.max(viewportMargin, window.innerHeight - height - viewportMargin);
      }

      position = { top, left };
    };

    const onViewportChange = (): void => {
      cancelAnimationFrame(frame);
      frame = requestAnimationFrame(place);
    };

    frame = requestAnimationFrame(place);
    window.addEventListener('scroll', onViewportChange, true);
    window.addEventListener('resize', onViewportChange);

    return () => {
      cancelAnimationFrame(frame);
      window.removeEventListener('scroll', onViewportChange, true);
      window.removeEventListener('resize', onViewportChange);
    };
  });
</script>

<span class="help-root" bind:this={root}>
  <button
    type="button"
    class="help-trigger"
    class:active={open}
    aria-label={label}
    aria-expanded={open}
    title={label}
    onclick={(event) => {
      event.stopPropagation();
      open = !open;
    }}
  >
    <Icon name="help" size={16} />
  </button>

  {#if open}
    <span
      class="help-pop"
      role="tooltip"
      bind:this={popup}
      style:top={position === null ? undefined : `${position.top}px`}
      style:left={position === null ? undefined : `${position.left}px`}
      style:visibility={position === null ? 'hidden' : 'visible'}
    >{text}</span>
  {/if}
</span>

<style>
  .help-root {
    position: relative;
    display: inline-flex;
    flex: 0 0 auto;
  }

  .help-trigger {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 26px;
    height: 26px;
    padding: 0;
    border: none;
    border-radius: var(--radius-sm);
    background: transparent;
    color: var(--muted);
    cursor: pointer;
    transition: color 0.12s ease, background 0.12s ease;
  }

  .help-trigger:hover,
  .help-trigger.active {
    background: var(--bg);
    color: var(--primary);
  }

  .help-pop {
    position: fixed;
    z-index: 30;
    width: max-content;
    max-width: min(290px, calc(100vw - 16px));
    max-height: calc(100vh - 16px);
    overflow-y: auto;
    padding: 10px 12px;
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    background: var(--surface);
    box-shadow: var(--shadow-md);
    color: var(--text);
    font-size: 13px;
    line-height: 1.45;
    white-space: normal;
  }
</style>
