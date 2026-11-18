<script lang="ts">
  import type { Component } from 'svelte';
  import { ChunkLoader, type ChunkStatus } from '../chunk-loader';
  import Button from './ui/Button.svelte';
  import EmptyState from './ui/EmptyState.svelte';
  import Spinner from './ui/Spinner.svelte';

  interface Props {
    load: () => Promise<{ default: Component<any> }>;
    props?: Record<string, unknown>;
  }

  let { load, props = {} }: Props = $props();

  let View = $state<Component<any> | null>(null);
  let status = $state<ChunkStatus>('loading');

  $effect(() => {
    const loaderFn = load;
    const loader = new ChunkLoader(loaderFn, (next, value) => {
      status = next;
      View = next === 'ready' ? (value as { default: Component<any> }).default : null;
    });

    loader.start();

    return () => loader.cancel();
  });

  /**
   * Повторный dynamic import того же URL после ошибки загрузки чанка
   * ненадёжен: браузер может переиспользовать уже отклонённый промис.
   * Полная перезагрузка документа гарантирует новый модульный граф и
   * сохраняет текущий hash-маршрут.
   */
  function retry(): void {
    window.location.reload();
  }
</script>

{#if status === 'error'}
  <div role="alert">
    <EmptyState
      icon="info"
      title="Не удалось загрузить раздел"
      description="Проверьте соединение и попробуйте ещё раз — страница будет перезагружена."
    >
      {#snippet action()}
        <div class="lazy-actions">
          <Button onclick={retry}>Повторить</Button>
        </div>
      {/snippet}
    </EmptyState>
  </div>
{:else if View}
  {@const ViewComponent = View}
  <ViewComponent {...props} />
{:else}
  <div class="lazy"><Spinner size={24} /></div>
{/if}

<style>
  .lazy {
    display: grid;
    place-items: center;
    padding: var(--space-6);
  }

  .lazy-actions {
    display: flex;
    flex-wrap: wrap;
    justify-content: center;
    gap: var(--space-2);
  }
</style>
