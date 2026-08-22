import { type Page } from '@playwright/test';

/**
 * Navigate through the bounded fail-closed window after extension authority changes.
 *
 * The runtime watcher publishes a successor generation out of band. Until it does, the HTTP generation
 * fence is allowed to answer only its exact retryable drain response; every unrelated response is a real
 * failure and is rejected immediately instead of being hidden by a general-purpose assertion retry.
 */
export async function gotoAfterRuntimeConvergence(
  page: Page,
  path: string,
  description: string,
): Promise<void> {
  const deadline = Date.now() + 25_000;

  for (;;) {
    const remaining = deadline - Date.now();
    if (remaining <= 0) {
      throw new Error(`${description} did not recover from the generation drain within 25 seconds.`);
    }

    const response = await page.goto(path, { timeout: remaining });
    if (response === null) {
      throw new Error(`${description} returned no HTTP response.`);
    }
    if (response.status() === 200) {
      return;
    }
    if (response.status() !== 503) {
      throw new Error(`${description} returned unexpected HTTP ${response.status()}.`);
    }
    const headers = response.headers();
    if (headers['retry-after'] !== '1' || headers['cache-control'] !== 'no-store') {
      throw new Error(
        `${description} returned a 503 without the generation drain's Retry-After and no-store contract.`,
      );
    }
    const retryDelay = Math.min(1_000, deadline - Date.now());
    if (retryDelay <= 0) {
      throw new Error(`${description} did not recover from the generation drain within 25 seconds.`);
    }

    await page.waitForTimeout(retryDelay);
  }
}
