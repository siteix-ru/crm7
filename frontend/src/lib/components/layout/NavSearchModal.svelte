<script lang="ts">
  /**
   * Phase 2B compatibility adapter. Начиная с Phase 3 поиск и команды живут
   * в одной Command Palette; этот компонент не создаёт второй поисковый UI.
   */
  import CommandPalette from './CommandPalette.svelte';
  import { commandPalette } from '../../stores/command-palette.svelte';

  interface Props {
    open: boolean;
    sections?: unknown;
    onclose?: () => void;
    onNavigate?: (route: string) => void;
  }

  let { open, onclose, onNavigate }: Props = $props();

  $effect(() => {
    if (open) {
      commandPalette.openPalette();
    } else {
      commandPalette.close();
    }
  });
</script>

<CommandPalette {onNavigate} onClose={onclose} />
