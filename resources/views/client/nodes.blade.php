<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>{{ __('Export Nodes') }}</title>
  <style>
    :root {
      --bg: #f8fafc;
      --panel: #ffffff;
      --text: #111827;
      --muted: #64748b;
      --primary: #2563eb;
      --primary-soft: rgba(37, 99, 235, .08);
      --border: rgba(148, 163, 184, .28);
      --code: #0f172a;
      --code-bg: #f1f5f9;
    }

    * {
      box-sizing: border-box;
    }

    body {
      background: var(--bg);
      color: var(--text);
      font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
      line-height: 1.5;
      margin: 0;
      padding: 2rem 1rem;
    }

    .container {
      margin: 0 auto;
      max-width: 960px;
    }

    .header {
      align-items: flex-start;
      display: flex;
      gap: 1rem;
      justify-content: space-between;
      margin-bottom: 1rem;
    }

    .title {
      font-size: 1.75rem;
      line-height: 1.2;
      margin: 0;
    }

    .subtitle {
      color: var(--muted);
      margin-top: .35rem;
    }

    .actions {
      display: flex;
      flex: none;
      flex-wrap: wrap;
      gap: .5rem;
      justify-content: flex-end;
    }

    .button {
      align-items: center;
      background: var(--primary-soft);
      border: 1px solid rgba(37, 99, 235, .22);
      border-radius: 6px;
      color: var(--primary);
      cursor: pointer;
      display: inline-flex;
      font-size: .875rem;
      font-weight: 600;
      min-height: 2.25rem;
      padding: .45rem .75rem;
      text-decoration: none;
      white-space: nowrap;
    }

    .nodes {
      display: grid;
      gap: .75rem;
    }

    .node {
      background: var(--panel);
      border: 1px solid var(--border);
      border-radius: 8px;
      overflow: hidden;
    }

    .node__header {
      align-items: center;
      border-bottom: 1px solid var(--border);
      color: var(--muted);
      display: flex;
      font-size: .8125rem;
      justify-content: space-between;
      padding: .65rem .8rem;
    }

    .node__code {
      background: var(--code-bg);
      color: var(--code);
      font-family: "SFMono-Regular", Consolas, "Liberation Mono", monospace;
      font-size: .8125rem;
      margin: 0;
      overflow-x: auto;
      padding: .8rem;
      white-space: pre-wrap;
      word-break: break-all;
    }

    .empty {
      background: var(--panel);
      border: 1px solid var(--border);
      border-radius: 8px;
      color: var(--muted);
      padding: 2rem;
      text-align: center;
    }

    .copied {
      color: #16a34a;
    }

    @media (max-width: 640px) {
      body {
        padding: 1.25rem .75rem;
      }

      .header {
        flex-direction: column;
      }

      .actions {
        justify-content: flex-start;
        width: 100%;
      }
    }

    @media (prefers-color-scheme: dark) {
      :root {
        --bg: #0f172a;
        --panel: #111827;
        --text: #e5e7eb;
        --muted: #94a3b8;
        --border: rgba(148, 163, 184, .24);
        --code: #dbeafe;
        --code-bg: #020617;
      }
    }
  </style>
</head>

<body>
  <div class="container">
    <div class="header">
      <div>
        <h1 class="title">{{ __('Export Nodes') }}</h1>
        <div class="subtitle">
          {{ $subscription_name ?: $app_name }}
          @if ($subscription_id)
            / {{ __('Subscription') }} #{{ $subscription_id }}
          @endif
          / {{ $node_count }} {{ __('Nodes') }}
        </div>
      </div>

      @if ($node_count > 0)
        <div class="actions">
          <button class="button" type="button" id="copyAll">{{ __('Copy All') }}</button>
          <button class="button" type="button" id="downloadTxt">{{ __('Download') }}</button>
        </div>
      @endif
    </div>

    @if ($node_count > 0)
      <div class="nodes">
        @foreach ($nodes as $index => $node)
          <article class="node">
            <div class="node__header">
              <span>#{{ $index + 1 }}</span>
              <button class="button" type="button" data-copy-node="{{ $index }}">{{ __('Copy') }}</button>
            </div>
            <pre class="node__code" id="node-{{ $index }}">{{ trim($node) }}</pre>
          </article>
        @endforeach
      </div>
    @else
      <div class="empty">{{ __('No available nodes') }}</div>
    @endif
  </div>

  <script>
    const nodes = @json(array_map('trim', $nodes), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    const copiedText = @json(__('Copied'));

    async function copyText(value, button) {
      if (!value) return;

      if (navigator.clipboard?.writeText) {
        await navigator.clipboard.writeText(value);
      } else {
        const textarea = document.createElement('textarea');
        textarea.value = value;
        textarea.style.position = 'fixed';
        textarea.style.opacity = '0';
        document.body.appendChild(textarea);
        textarea.select();
        document.execCommand('copy');
        textarea.remove();
      }

      if (!button) return;
      const originalText = button.textContent;
      button.classList.add('copied');
      button.textContent = copiedText;
      window.setTimeout(() => {
        button.classList.remove('copied');
        button.textContent = originalText;
      }, 1000);
    }

    document.getElementById('copyAll')?.addEventListener('click', (event) => {
      copyText(nodes.join('\n'), event.currentTarget);
    });

    document.getElementById('downloadTxt')?.addEventListener('click', () => {
      const blob = new Blob([nodes.join('\n')], { type: 'text/plain;charset=utf-8' });
      const link = document.createElement('a');
      link.href = URL.createObjectURL(blob);
      link.download = 'nodes.txt';
      link.click();
      URL.revokeObjectURL(link.href);
    });

    document.querySelectorAll('[data-copy-node]').forEach((button) => {
      button.addEventListener('click', () => {
        copyText(nodes[Number(button.dataset.copyNode)] || '', button);
      });
    });
  </script>
</body>

</html>
