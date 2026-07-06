<!doctype html>
<html lang="zh-CN">

<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1,minimum-scale=1,user-scalable=no" />
  <title>{{$title}}</title>
  @php
    $umiSourcePath = base_path('theme/' . $theme . '/assets/umi.js');
    $umiPublicPath = public_path('theme/' . $theme . '/assets/umi.js');
    $umiPath = file_exists($umiSourcePath) ? $umiSourcePath : $umiPublicPath;
    $multiSubscriptionSourcePath = base_path('theme/' . $theme . '/assets/multi-subscription.js');
    $multiSubscriptionPublicPath = public_path('theme/' . $theme . '/assets/multi-subscription.js');
    $multiSubscriptionPath = file_exists($multiSubscriptionSourcePath) ? $multiSubscriptionSourcePath : $multiSubscriptionPublicPath;
    $umiVersion = file_exists($umiPath) ? filemtime($umiPath) : time();
    $multiSubscriptionVersion = file_exists($multiSubscriptionPath) ? filemtime($multiSubscriptionPath) : $umiVersion;
  @endphp
  <script>
    window.__xboardOpenRenewAllModal = window.__xboardOpenRenewAllModal || function () {
      window.__xboardRenewAllModalRequested = true;
      window.dispatchEvent(new CustomEvent('xboard:open-renew-all-modal'));
    };
  </script>
  <script type="module" crossorigin src="/xboard-theme/{{$theme}}/umi.js?v={{$umiVersion}}"></script>
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
    window.__xboardMultiSubscriptionAsset = {
      version: '{{$multiSubscriptionVersion}}',
      source: 'theme'
    };
  </script>
  <div id="app"></div>
  @if(file_exists($multiSubscriptionPath))
    <script defer src="/xboard-theme/{{$theme}}/multi-subscription.js?v={{$multiSubscriptionVersion}}"></script>
  @endif
  {!! $theme_config['custom_html'] !!}
</body>

</html>
