import { type Page } from '@playwright/test';

/**
 * Navigate through the bounded fail-closed window after extension authority changes.
 *
 * The runtime watcher publishes a successor generation out of band. Until it does, the HTTP generation
 * fence is allowed to answer only its exact retryable drain response; every unrelated response is a real
 * failure and is rejected immediately instead of being hidden by a general-purpose assertion retry.
 * The authenticated request context performs that probe because Firefox can translate the supervisor's
 * handoff into a navigation error; the browser itself navigates only after the endpoint answers 200.
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

    const probe = await page.context().request.get(path, {
      failOnStatusCode: false,
      maxRedirects: 0,
      timeout: remaining,
    });
    try {
      if (probe.status() === 200) {
        break;
      }
      if (probe.status() !== 503) {
        throw new Error(`${description} returned unexpected HTTP ${probe.status()}.`);
      }
      const headers = probe.headers();
      if (headers['retry-after'] !== '1' || headers['cache-control'] !== 'no-store') {
        throw new Error(
          `${description} returned a 503 without the generation drain's Retry-After and no-store contract.`,
        );
      }
    } finally {
      await probe.dispose();
    }
    const retryDelay = Math.min(1_000, deadline - Date.now());
    if (retryDelay <= 0) {
      throw new Error(`${description} did not recover from the generation drain within 25 seconds.`);
    }

    await page.waitForTimeout(retryDelay);
  }

  const remaining = deadline - Date.now();
  if (remaining <= 0) {
    throw new Error(`${description} did not recover from the generation drain within 25 seconds.`);
  }
  const response = await page.goto(path, { timeout: remaining });
  if (response === null) {
    throw new Error(`${description} returned no browser HTTP response after convergence.`);
  }
  if (response.status() !== 200) {
    throw new Error(
      `${description} returned unexpected browser HTTP ${response.status()} after convergence.`,
    );
  }
}
