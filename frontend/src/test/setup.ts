import { config, enableAutoUnmount } from "@vue/test-utils";
import { afterEach, vi } from "vitest";

enableAutoUnmount(afterEach);

config.global.stubs = {
  transition: false,
  "transition-group": false,
};

Object.defineProperty(window, "matchMedia", {
  configurable: true,
  value: (query: string): MediaQueryList => ({
    matches: false,
    media: query,
    onchange: null,
    addEventListener: () => undefined,
    removeEventListener: () => undefined,
    addListener: () => undefined,
    removeListener: () => undefined,
    dispatchEvent: () => false,
  }),
});

window.scrollTo = vi.fn();
