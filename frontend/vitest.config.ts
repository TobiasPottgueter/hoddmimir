import { mergeConfig } from "vite";
import { defineConfig } from "vitest/config";

import viteConfig from "./vite.config";

export default mergeConfig(
  viteConfig,
  defineConfig({
    test: {
      environment: "jsdom",
      setupFiles: ["./src/test/setup.ts"],
      coverage: {
        provider: "v8",
        reporter: ["text", "html", "lcov"],
        include: ["src/**/*.{ts,vue}"],
        exclude: [
          "src/App.vue",
          "src/main.ts",
          "src/api/generated/**",
          "src/test/**",
        ],
        thresholds: {
          branches: 90,
          functions: 90,
          lines: 90,
          statements: 90,
          "src/composables/**": {
            branches: 100,
            functions: 100,
            lines: 100,
            statements: 100,
          },
          "src/stores/**": {
            branches: 100,
            functions: 100,
            lines: 100,
            statements: 100,
          },
        },
      },
    },
  }),
);
