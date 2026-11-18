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
  let reloadKey = $state(0);

  $effect(() => {
    const loaderFn = load;

    // Смена ключа — явный запрос повторной загрузки («Повторить»).
    void reloadKey;

    const loader = new ChunkLoader(loaderFn, (next, value) => {
      status = next;
      View = next === 'ready' ? (value as { default: Component<any> }).default : null;
    });

    loader.start();

    return () => loader.cancel();
  });

  function retry(): void {
    reloadKey += 1;
  }

  function reloadPage(): void {
    window.location.reload();
  }
</script>

{#if status === 'error'}
  <div role="alert">
    <EmptyState
      icon="info"
      title="Не удалось загрузить раздел"
      description="Проверьте соединение и попробуйте ещё раз."
    >
      {#snippet action()}
        <div class="lazy-actions">
          <Button onclick={retry}>Повторить</Button>
          <Button variant="ghost" onclick={reloadPage}>Перезагрузить страницу</Button>
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
