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
        exclude: ["src/main.ts", "src/test/**"],
      },
    },
  }),
);
