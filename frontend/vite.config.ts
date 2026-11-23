import { defineConfig } from 'vitest/config';
import { loadEnv } from 'vite';
import { svelte } from '@sveltejs/vite-plugin-svelte';

export function createConfig({ mode, command }: { mode: string; command: 'build' | 'serve' }) {
  const env = loadEnv(mode, '.', '');
  const development = command === 'serve' || mode === 'development';

  return {
    plugins: [svelte()],
    base: './',
    server: {
      proxy: {
        '/api': {
          target: env.CRM_API_TARGET ?? 'http://127.0.0.1:8080',
          changeOrigin: false
        }
      }
    },
    preview: {
      proxy: {
        '/api': {
          target: env.CRM_API_TARGET ?? 'http://127.0.0.1:8080',
          changeOrigin: false
        }
      }
    },
    build: {
      outDir: 'dist',
      // Production builds must not publish frontend source maps.
      sourcemap: development
    },
    test: {
      setupFiles: ['./src/test-setup.ts']
    }
  };
}

export default defineConfig(createConfig);
