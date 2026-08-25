import {
  HostPortFailure,
  STUDIO_CONTRACT_VERSION,
  isHostPortError,
  type HostAdapter,
  type HostErrorCategory,
  type HostPortError,
  type HostPortResult,
  type HostRequestContext,
  type JsonObject,
  type MediaQuery,
  type MediaUploadRequestDescriptor,
  type PreviewRenderPayload,
  type QualifiedName,
  type ResourceSearchQuery,
  type TelemetryEvent,
} from '@kumwe/studio-protocol';

export interface StudioPreviewSessionMetadata {
  channelId: string;
  documentPath: string;
  origin: string;
  sourceId: string;
}

interface AdapterOptions {
  advertised: ReadonlySet<string>;
  csrf: string;
  preview?: StudioPreviewSessionMetadata;
}

type JsonSerializable = object | string | number | boolean | null;

export function createStudioHttpHostAdapter(baseUrl: string, options: AdapterOptions): HostAdapter {
  const base = baseUrl.endsWith('/') ? baseUrl.slice(0, -1) : baseUrl;
  let serial = 0;
  let previewSequence = 0;
  let previewRenderTail: Promise<void> | undefined;
  let previewUnavailable: HostPortFailure | undefined;
  let unloading = false;
  const markUnloading = (): void => {
    unloading = true;
    // A cancelled navigation remains on this page, while a BFCache restoration emits pageshow.
    // Resetting on both paths prevents a later live cancellation from inheriting unload semantics.
    window.setTimeout(() => { unloading = false; }, 0);
  };
  window.addEventListener('beforeunload', markUnloading);
  window.addEventListener('pagehide', markUnloading);
  window.addEventListener('pageshow', () => { unloading = false; });

  const failure = (category: HostErrorCategory, code: QualifiedName, retryable = false): HostPortFailure => {
    serial += 1;
    const error: HostPortError = {
      category,
      contractVersion: STUDIO_CONTRACT_VERSION,
      correlationId: `browser-transport-${serial}`,
      kind: 'host-error',
      message: { key: code, defaultMessage: 'The Studio host request could not be completed.' },
      retryable,
    };
    return new HostPortFailure(error);
  };

  const call = async <T>(
    port: string,
    operation: string,
    args: JsonSerializable,
    context: HostRequestContext,
  ): Promise<HostPortResult<T>> => {
    const perform = async (): Promise<HostPortResult<T>> => {
      if (port === 'preview' && previewUnavailable !== undefined) throw previewUnavailable;
      const controller = new AbortController();
      const timer = window.setTimeout(() => controller.abort(), 10_000);
      const headers: Record<string, string> = {
        'content-type': 'application/json',
        'X-CSRF-Token': options.csrf,
      };
      if (port === 'preview' && options.preview !== undefined) {
        headers['X-Kumwe-Studio-Preview-Channel'] = options.preview.channelId;
        headers['X-Kumwe-Studio-Preview-Source'] = options.preview.sourceId;
        headers['X-Kumwe-Studio-Preview-Sequence'] = String(previewSequence);
        previewSequence += 1;
      }
      let response: Response;
      try {
        response = await fetch(`${base}/${port}/${operation}`, {
          body: JSON.stringify({ arguments: args, context }),
          credentials: 'same-origin',
          headers,
          // A live cancellation must retain the authenticated browser request identity. Firefox can
          // move a keepalive fetch onto its native network context (rather than the page's emulated
          // identity), which correctly fails the session binding and then creates a preview-sequence
          // gap. Keepalive is necessary only while the document is actually leaving the page.
          keepalive: port === 'preview' && operation === 'cancel' && unloading,
          method: 'POST',
          signal: controller.signal,
        });
      } catch (reason) {
        const aborted = reason instanceof DOMException && reason.name === 'AbortError';
        const unavailable = failure(
          'unavailable',
          aborted ? 'studio.host/transport-timeout' : 'studio.host/transport-unavailable',
          true,
        );
        if (port === 'preview') previewUnavailable = unavailable;
        throw unavailable;
      } finally {
        window.clearTimeout(timer);
      }
      let body: unknown;
      try {
        body = await response.json();
      } catch {
        throw failure('internal', 'studio.host/malformed-response');
      }
      if (!response.ok) {
        if (isHostPortError(body)) throw new HostPortFailure(body);
        throw failure(categoryForStatus(response.status), 'studio.host/http-refused', response.status === 429 || response.status >= 502);
      }
      if (!isResult(body)) throw failure('internal', 'studio.host/malformed-response');
      return body as HostPortResult<T>;
    };
    if (port !== 'preview' || operation === 'cancel') return perform();
    const pending = previewRenderTail === undefined ? perform() : previewRenderTail.then(perform);
    const completed = pending.then(() => undefined, () => undefined);
    previewRenderTail = completed;
    void completed.finally(() => {
      if (previewRenderTail === completed) previewRenderTail = undefined;
    });
    return pending;
  };

  const adapter: HostAdapter = {
    artifact: {
      dependencies: (reference, context) => call('artifact', 'dependencies', { reference: copy(reference) }, context),
      load: (reference, context) => call('artifact', 'load', { reference: copy(reference) }, context),
      publish: (reference, context) => call('artifact', 'publish', { reference: copy(reference) }, context),
      save: (document, context) => call('artifact', 'save', { document: copy(document) }, context),
      unpublish: (reference, context) => call('artifact', 'unpublish', { reference: copy(reference) }, context),
    },
  };
  if (hasPort(options.advertised, 'localization')) {
    adapter.localization = {
      messages: (locale, namespaces, context) => call('localization', 'messages', { locale, namespaces }, context),
    };
  }
  if (hasPort(options.advertised, 'model')) {
    adapter.model = {
      get: (reference, context) => call('model', 'get', { reference: copy(reference) }, context),
      list: (context) => call('model', 'list', {}, context),
    };
  }
  if (hasPort(options.advertised, 'permission')) {
    adapter.permission = {
      explain: (operation, context) => call('permission', 'explain', { operation }, context),
      refresh: (context) => call('permission', 'refresh', {}, context),
    };
  }
  if (hasPort(options.advertised, 'recovery')) {
    adapter.recovery = {
      discard: (context) => call('recovery', 'discard', {}, context),
      load: (context) => call('recovery', 'load', {}, context),
      store: (envelope: JsonObject, context) => call('recovery', 'store', { envelope }, context),
    };
  }
  if (hasPort(options.advertised, 'resource')) {
    adapter.resource = {
      search: (query: ResourceSearchQuery, context) =>
        call('resource', 'search', { query: copy(query) }, context),
    };
  }
  if (hasPort(options.advertised, 'telemetry')) {
    adapter.telemetry = {
      emit: (event: TelemetryEvent, context) => call('telemetry', 'emit', { event: copy(event) }, context),
    };
  }
  if (hasPort(options.advertised, 'preview')) {
    adapter.preview = {
      cancel: (draftDigest, context) => call('preview', 'cancel', { draftDigest }, context),
      render: (payload: PreviewRenderPayload, context) => call('preview', 'render', { payload: copy(payload) }, context),
    };
  }
  if (hasPort(options.advertised, 'media')) {
    adapter.media = {
      abortUpload: (uploadId, context) => call('media', 'abort-upload', { uploadId }, context),
      authorizeUpload: (request: MediaUploadRequestDescriptor, context) => call('media', 'authorize-upload', { request: copy(request) }, context),
      completeUpload: (uploadId, context) => call('media', 'complete-upload', { uploadId }, context),
      get: (assetId, context) => call('media', 'get', { assetId }, context),
      importExternal: (url, context) => call('media', 'import-external', { url }, context),
      list: (query: MediaQuery, context) => call('media', 'list', { query: copy(query) }, context),
      uploadStatus: (assetId, context) => call('media', 'upload-status', { assetId }, context),
    };
  }

  return adapter;
}

function hasPort(advertised: ReadonlySet<string>, port: string): boolean {
  return advertised.has(`studio.port/${port}`);
}

function copy<T>(value: T): JsonSerializable {
  return JSON.parse(JSON.stringify(value)) as JsonSerializable;
}

function isResult(value: unknown): value is HostPortResult<unknown> {
  if (typeof value !== 'object' || value === null || Array.isArray(value) || !Object.hasOwn(value, 'value')) return false;
  const revision = (value as { revision?: unknown }).revision;
  return (revision === undefined || typeof revision === 'string' && revision.length > 0)
    && Object.keys(value).every((key) => key === 'value' || key === 'revision');
}

function categoryForStatus(status: number): HostErrorCategory {
  if (status === 401) return 'unauthenticated';
  if (status === 403) return 'forbidden';
  if (status === 404) return 'not-found';
  if (status === 409) return 'conflict';
  if (status === 413) return 'limit-exceeded';
  if (status === 422) return 'validation-failed';
  if (status === 429) return 'rate-limited';
  if (status === 408 || status === 502 || status === 503 || status === 504) return 'unavailable';
  if (status >= 500) return 'internal';
  return 'invalid-request';
}
