<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>{{ $title }}</title>
  <script>
    window.settings = {
      base_url: "/",
      title: "{{ $title }}",
      version: "{{ $version }}",
      logo: "{{ $logo }}",
      secure_path: "{{ $secure_path }}",
    };
  </script>
  @php
    $manifestPath = public_path('assets/admin/manifest.json');
    $multiSubscriptionPath = public_path('admin-multi-subscription.js');
    $manifest = file_exists($manifestPath) ? json_decode(file_get_contents($manifestPath), true) : null;
    $entry = is_array($manifest) ? ($manifest['index.html'] ?? null) : null;
    $scripts = [];
    $styles = [];
    $locales = [];
    $multiSubscriptionVersion = file_exists($multiSubscriptionPath) ? filemtime($multiSubscriptionPath) : time();
    $assetVersion = function ($asset) {
      $path = public_path('assets/admin/' . ltrim($asset, '/'));
      return file_exists($path) ? filemtime($path) : time();
    };

    if (is_array($entry)) {
      $visited = [];
      $collectAssets = function ($chunkName) use (&$collectAssets, &$manifest, &$visited, &$scripts, &$styles) {
        if (isset($visited[$chunkName]) || !isset($manifest[$chunkName]) || !is_array($manifest[$chunkName])) {
          return;
        }

        $visited[$chunkName] = true;
        $chunk = $manifest[$chunkName];

        if (!empty($chunk['css']) && is_array($chunk['css'])) {
          foreach ($chunk['css'] as $cssFile) {
            $styles[$cssFile] = $cssFile;
          }
        }

        if (!empty($chunk['imports']) && is_array($chunk['imports'])) {
          foreach ($chunk['imports'] as $import) {
            $collectAssets($import);
          }
        }

        if (!empty($chunk['isEntry']) && !empty($chunk['file'])) {
          $scripts[$chunk['file']] = $chunk['file'];
        }
      };

      $collectAssets('index.html');
    }

    foreach (glob(public_path('assets/admin/locales/*.js')) ?: [] as $localeFile) {
      $locales[] = 'locales/' . basename($localeFile);
    }
    sort($locales);
  @endphp

  @if($entry && count($scripts) > 0)
    @foreach($styles as $css)
      <link rel="stylesheet" crossorigin href="/assets/admin/{{ $css }}?v={{ $assetVersion($css) }}" />
    @endforeach
    @foreach($locales as $locale)
      <script src="/assets/admin/{{ $locale }}?v={{ $assetVersion($locale) }}"></script>
    @endforeach
    @foreach($scripts as $js)
      <script type="module" crossorigin src="/assets/admin/{{ $js }}?v={{ $assetVersion($js) }}"></script>
    @endforeach
  @else
    {{-- Fallback: hardcoded paths for backward compatibility --}}
    <script type="module" crossorigin src="/assets/admin/assets/index.js?v={{ $assetVersion('assets/index.js') }}"></script>
    <link rel="stylesheet" crossorigin href="/assets/admin/assets/index.css?v={{ $assetVersion('assets/index.css') }}" />
    <link rel="stylesheet" crossorigin href="/assets/admin/assets/vendor.css?v={{ $assetVersion('assets/vendor.css') }}">
    <script src="/assets/admin/locales/en-US.js?v={{ $assetVersion('locales/en-US.js') }}"></script>
    <script src="/assets/admin/locales/zh-CN.js?v={{ $assetVersion('locales/zh-CN.js') }}"></script>
    <script src="/assets/admin/locales/ko-KR.js?v={{ $assetVersion('locales/ko-KR.js') }}"></script>
  @endif
</head>

<body>
  <div id="root"></div>
  @if(file_exists($multiSubscriptionPath))
    <script defer src="/admin-multi-subscription.js?v={{ $multiSubscriptionVersion }}"></script>
  @endif
</body>

</html>
