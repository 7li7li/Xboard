<!doctype html>
<html lang="zh-CN">

<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1,minimum-scale=1,user-scalable=no" />
  <title>{{$title}}</title>
  @php
    $umiPath = public_path('theme/' . $theme . '/assets/umi.js');
    $multiSubscriptionPath = public_path('theme/' . $theme . '/assets/multi-subscription.js');
    $umiVersion = file_exists($umiPath) ? filemtime($umiPath) : time();
    $multiSubscriptionVersion = file_exists($multiSubscriptionPath) ? filemtime($multiSubscriptionPath) : $umiVersion;
  @endphp
  <script type="module" crossorigin src="/theme/{{$theme}}/assets/umi.js?v={{$umiVersion}}"></script>
</head>

<body>

  <script>
    window.routerBase = "/";
    window.settings = {
      title: '{{$title}}',
      assets_path: '/theme/{{$theme}}/assets',
      theme: {
        color: '{{ $theme_config['theme_color'] ?? "default" }}',
      },
      version: '{{$version}}',
      background_url: '{{$theme_config['background_url']}}',
      description: '{{$description}}',
      i18n: [
        'zh-CN',
        'en-US',
        'ja-JP',
        'vi-VN',
        'ko-KR',
        'zh-TW',
        'fa-IR'
      ],
      logo: '{{$logo}}'
    }
  </script>
  <div id="app"></div>
  <script defer src="/theme/{{$theme}}/assets/multi-subscription.js?v={{$multiSubscriptionVersion}}"></script>
  {!! $theme_config['custom_html'] !!}
</body>

</html>
