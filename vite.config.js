import { fileURLToPath, URL } from 'node:url';

import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'

export default defineConfig(({ mode }) => ({
    plugins: [
        vue(),
    ],
    build: {
        emptyOutDir: false,
        sourcemap:true,
        assetsInlineLimit:100000,
        rollupOptions: {
            input: {
                'call_list': 'src/pages/call_list/app.js'
            },
            output: {
                entryFileNames: 'pages/[name].js',
                assetFileNames: 'assets/[name][extname]'
            }
        }
    },
    resolve: {
        alias: {
            '@': fileURLToPath(new URL('./src', import.meta.url))
        }
    }
}));