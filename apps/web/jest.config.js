export default {
  testEnvironment: 'jest-environment-jsdom',
  transform: {
    '^.+\\.tsx?$': ['ts-jest', {
      tsconfig: 'tsconfig.test.json',
    }],
  },
  moduleNameMapper: {
    '\\.(css|less|scss)$': 'identity-obj-proxy',
  },
  setupFilesAfterSetup: ['@testing-library/jest-dom'],
  testPathIgnorePatterns: ['/node_modules/', '/e2e/', '/dist/'],
}
