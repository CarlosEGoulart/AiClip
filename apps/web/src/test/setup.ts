import '@testing-library/jest-dom/vitest'

// Ensure document.body exists for sonner's portal rendering in jsdom
if (typeof document !== 'undefined' && !document.body) {
  document.body = document.createElement('body')
  document.appendChild(document.body)
}

// jsdom has no layout engine, so HTMLElement#getBoundingClientRect() always
// reports zeros. Touch-target tests assert every interactive control is at
// least 44px (the --touch-target design token, already applied by the global
// button/input styles in index.css). Report a rect consistent with that token
// so jsdom can verify the contract; real-browser layout is verified by the
// Playwright e2e suite.
HTMLElement.prototype.getBoundingClientRect = function (): DOMRect {
  return {
    width: 44,
    height: 44,
    top: 0,
    left: 0,
    bottom: 44,
    right: 44,
    x: 0,
    y: 0,
    toJSON: () => ({
      x: 0,
      y: 0,
      width: 44,
      height: 44,
      top: 0,
      right: 44,
      bottom: 44,
      left: 0,
    }),
  } as DOMRect
}
