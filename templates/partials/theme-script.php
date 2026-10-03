<?php
/**
 * 主题切换接线
 *
 * 与 theme-head.php 分工：那个负责「尽早把 data-theme 写到 <html> 上」，
 * 这个负责「把控件与 localStorage 接起来」。
 * 拆开的原因：防闪烁必须在 <head> 同步执行，而接线需要 DOM 就绪。
 */
?>
<script>
(function () {
  var KEY = 'tk-theme';
  var root = document.documentElement;

  function apply(stored) {
    var theme = (stored === 'light' || stored === 'dark')
      ? stored
      : ((window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches) ? 'dark' : 'light');
    root.setAttribute('data-theme', theme);
    root.setAttribute('data-theme-choice', (stored === 'light' || stored === 'dark') ? stored : 'system');
    root.style.colorScheme = theme;
  }

  function current() {
    return root.getAttribute('data-theme-choice') || 'system';
  }

  document.addEventListener('DOMContentLoaded', function () {
    var box = document.querySelector('[data-theme-toggle]');
    if (!box) { return; }

    // 后台顶栏在窄屏下会把文字收起来，这里同步一个标记，
    // 便于 CSS 针对「切换控件存在」这种情况调整间距
    document.documentElement.setAttribute('data-has-theme-toggle', '1');

    var inputs = box.querySelectorAll('input[name="theme-choice"]');
    Array.prototype.forEach.call(inputs, function (input) {
      if (input.value === current()) { input.checked = true; }
      input.addEventListener('change', function () {
        var v = input.value;
        try {
          if (v === 'system') { window.localStorage.removeItem(KEY); }
          else { window.localStorage.setItem(KEY, v); }
        } catch (e) { /* 存不了也照样切，只是刷新后不保留 */ }
        apply(v === 'system' ? null : v);
      });
    });

    // 用户选「跟随系统」时，系统主题变化要即时跟随
    if (window.matchMedia) {
      try {
        window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', function () {
          if (current() === 'system') { apply(null); }
        });
      } catch (e) { /* 老浏览器忽略 */ }
    }
  });
})();
</script>
