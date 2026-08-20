/**
 * Types for the shared browser-manifest reader.
 *
 * The reader itself is plain JavaScript so the Playwright configuration and the standalone verifier can
 * import the same function without a build step; this file is what lets the type checker see it.
 */

/** Every value `specs` may take. */
export declare const specScopes: readonly string[];

/** The largest retry budget the manifest may declare. */
export declare const maxRetries: number;

/** A validated project entry. */
export interface BrowserProject {
  readonly name: string;
  readonly specs: 'all' | 'right-to-left';
}

/** A validated manifest. */
export interface BrowserMatrix {
  readonly retries: number;
  readonly projects: readonly BrowserProject[];
}

/** Read and validate a manifest, or throw naming the first thing wrong with it. */
export declare function parseBrowserMatrix(source: string, origin?: string): BrowserMatrix;
