<section class="panel" aria-labelledby="component-title">
  <h1 id="component-title">{{ heading }}</h1>
  <p>{{ message }}</p>
  <p><strong>Package:</strong> <code>@@EXTENSION_IDENTIFIER@@</code></p>
  <dl>
    <dt>Domain events observed</dt><dd>{{ activity.domain_events }}</dd>
    <dt>Durable events observed</dt><dd>{{ activity.integration_events }}</dd>
    <dt>Latest job digest</dt><dd>{{ activity.latest_job_digest ?? 'No job observed in this process' }}</dd>
  </dl>
</section>
