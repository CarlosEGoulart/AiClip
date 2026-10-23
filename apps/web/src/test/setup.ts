import '@testing-library/jest-dom/vitest'

// Ensure document.body exists for sonner's portal rendering in jsdom
if (typeof document !== 'undefined' && !document.body) {
  document.body = document.createElement('body')
  document.appendChild(document.body)
}
