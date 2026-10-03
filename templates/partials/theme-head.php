<?php
/**
 * 主题防闪烁内联脚本
 *
 * 必须在 <head> 里、样式表之前**同步**执行。
 * 如果等 DOMContentLoaded 再设置 data-theme，深色模式的用户会先看到
 * 一帧白底（浏览器已经用默认浅色画过一次），也就是常见的「主题闪烁」。
 *
 * 为什么内联而不是外链：外链脚本是额外一次网络往返，同样会闪。
 * 这段代码极短（不到 1 KB）且不随主题变化，内联的代价可以忽略。
 *
 * 三态语义：
 *   'light' / 'dark'      -> 用户显式选择，优先
 *   'system' 或未设置      -> 跟随 prefers-color-scheme
 */
?>
<script>
(function () {
  var KEY = 'tk-theme';
  var root = document.documentElement;
  var stored = null;
  try { stored = window.localStorage.getItem(KEY); } catch (e) { /* 隐私模式 */ }

  var systemDark = false;
  try {
    systemDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
  } catch (e) { /* 极老浏览器 */ }

  var theme = (stored === 'light' || stored === 'dark')
    ? stored
    : (systemDark ? 'dark' : 'light');

  root.setAttribute('data-theme', theme);
  root.setAttribute('data-theme-choice', stored === 'light' || stored === 'dark' ? stored : 'system');
  root.style.colorScheme = theme;
})();
</script>
