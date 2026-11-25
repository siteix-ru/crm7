// @ts-ignore — test-only Node API, not part of the browser bundle
import { readFileSync } from 'node:fs';
import { describe, expect, it } from 'vitest';

function source(relative: string): string {
  return readFileSync(new URL(relative, import.meta.url), 'utf8');
}

const app = source('../../../App.svelte');
const shell = source('./AppShell.svelte');
const store = source('../../stores/app-settings.svelte.ts');
const modules = source('../../../../../api/src/Support/Modules.php');
const migration = source('../../../../../api/db/migrations/091_lots_module.sql');

describe('stock lots module', () => {
  it('is exposed as a regular app setting', () => {
    expect(store).toContain('lots_enabled: boolean');
    expect(store).toContain('lotsEnabled = $state(true)');
    expect(store).toContain('this.lotsEnabled = data.lots_enabled !== false');
  });

  it('gates the route and navigation by the module flag', () => {
    expect(app).toContain("!appSettings.lotsEnabled || !auth.can('stocks.lots.view')");
    expect(shell).toContain("appSettings.lotsEnabled && auth.can('stocks.lots.view')");
  });

  it('is registered with API guards and dedicated capabilities', () => {
    expect(modules).toContain("'setting' => 'module.stock_lots'");
    expect(modules).toContain('#^/stocks/lots(/|$)');
    expect(migration).toContain("'stocks.lots.view'");
    expect(migration).toContain("'module.stock_lots'");
  });
});
