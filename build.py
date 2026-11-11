#!/usr/bin/env python3
"""Собирает index.html (офлайн-приложение) из claude-version/sto-kassa.html."""
import json, pathlib
root = pathlib.Path(__file__).parent
s = (root / 'claude-version' / 'sto-kassa.html').read_text(encoding='utf-8')
s = s.replace('<title>СТО Касса</title>\n', '').replace('<meta name="claude:viewport-fit-sides" content="cover">\n', '')
head = '''<!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<title>СТО Касса</title>
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="СТО Касса">
<link rel="apple-touch-icon" href="icon.png">
<link rel="icon" href="icon.png">
<link rel="manifest" href="manifest.json">
<style>:root{color-scheme:light;padding-top:env(safe-area-inset-top,0px);padding-bottom:env(safe-area-inset-bottom,0px)}html{height:100%}body{margin:0}img{max-width:100%}[hidden]{display:none!important}</style>
</head>
<body>
'''
tail = '''
<script>
if ('serviceWorker' in navigator && (location.protocol === 'https:' || location.hostname === 'localhost' || location.hostname === '127.0.0.1')) {
  navigator.serviceWorker.register('sw.js').catch(function(){});
}
</script>
</body>
</html>
'''
(root / 'index.html').write_text(head + s + tail, encoding='utf-8')
print('index.html', len(head + s + tail))
