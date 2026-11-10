<script lang="ts">
  import type { Component } from 'svelte';
  import Spinner from './ui/Spinner.svelte';

  interface Props {
    load: () => Promise<{ default: Component<any> }>;
    props?: Record<string, unknown>;
  }

  let { load, props = {} }: Props = $props();

  let View = $state<Component<any> | null>(null);

  $effect(() => {
    let cancelled = false;
    let retry: ReturnType<typeof setTimeout> | undefined;
    const loader = load;

    const run = (attempt: number): void => {
      loader()
        .then((module) => {
          if (!cancelled) {
            View = module.default;
          }
        })
        .catch(() => {
          if (!cancelled && attempt < 2) {
            retry = setTimeout(() => run(attempt + 1), 1200);
          }
        });
    };

    run(0);

    return () => {
      cancelled = true;
      clearTimeout(retry);
    };
  });
</script>

{#if View}
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
</style>
