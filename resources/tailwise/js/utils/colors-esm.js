// Wrapper ESM module to ensure existing colors/helper scripts run and provide a proper ES export for getColor
import './colors.js';
import './helper.js';

export function getColor(...args) {
  if (typeof window !== 'undefined' && typeof window.getColor === 'function') {
    return window.getColor(...args);
  }
  // Fallback: if helper isn't loaded yet, return the input (best-effort)
  return args[0];
}

export default getColor;
