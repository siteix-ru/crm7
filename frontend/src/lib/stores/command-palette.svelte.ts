/**
 * Один экземпляр Command Palette на приложение.
 * Hotkey и иконка Rail открывают одно и то же состояние.
 */
class CommandPaletteStore {
  open = $state(false);

  query = $state('');

  openPalette(query = ''): void {
    this.open = true;
    if (query !== '') {
      this.query = query;
    }
  }

  close(): void {
    this.open = false;
    this.query = '';
  }

  toggle(): void {
    if (this.open) {
      this.close();
    } else {
      this.openPalette();
    }
  }
}

export const commandPalette = new CommandPaletteStore();
