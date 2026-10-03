/* 提交进度条 —— 前台 / 后台共用
 *
 * 表单提交后要等数据库写入、附件落盘、邮件外发，再等浏览器整页跳转，
 * 这段等待里页面是完全静止的。用户会以为按钮没生效而连点几次，
 * 造成重复提交。这里统一挂遮罩 + 进度条，把等待过程显性化。
 *
 * 三条约束：
 *   1. 只接管 POST 表单。GET 的搜索/筛选是即时跳转，套遮罩只会闪一下。
 *   2. 延迟 240ms 再显示，比这更快的响应根本不需要提示。
 *   3. 进度是模拟值：浏览器拿不到真实上传字节数，
 *      只能单调递增逼近 90% 后停住，等页面真正跳转。
 */
(function () {
  'use strict';

  var DELAY = 240;    // 超过这个时长才算「真的慢了」
  var TARGET = 90;    // 进度上限，留 10% 给最后跳转
  var mask = null, bar = null, tx = null;
  var timer = null, showTimer = null, t0 = 0;

  function build() {
    if (mask) { return; }
    mask = document.createElement('div');
    mask.className = 'submit-mask';
    mask.innerHTML = '<div class="box"><div class="bar"><i></i></div><div class="tx"></div></div>';
    document.body.appendChild(mask);
    bar = mask.querySelector('.bar i');
    tx = mask.querySelector('.tx');
  }

  // 确实选了文件才提示「上传」，否则会凭空多出一个用户没做过的步骤
  function hasFile(form) {
    var fi = form.querySelector('input[type=file]');
    return !!(fi && fi.files && fi.files.length);
  }

  function phase(uploading) {
    var el = (Date.now() - t0) / 1000;
    if (el < 1.2) { return uploading ? '正在上传附件…' : '正在提交…'; }
    if (el < 5) { return '服务器处理中…'; }
    if (el < 15) { return '仍在处理，请稍候…'; }
    return '处理时间较长，可稍后刷新页面查看结果';
  }

  function show(uploading) {
    build();
    bar.style.width = '0%';
    tx.textContent = phase(uploading);
    mask.classList.add('on');

    t0 = Date.now();
    var p = 0;
    clearInterval(timer);
    timer = setInterval(function () {
      // 越接近上限涨得越慢，暗示「快结束了」
      p += (TARGET - p) * 0.14 + 0.5;
      if (p > TARGET) { p = TARGET; }
      bar.style.width = p.toFixed(1) + '%';
      tx.textContent = phase(uploading);
    }, 180);
  }

  function hide() {
    clearTimeout(showTimer);
    clearInterval(timer);
    showTimer = timer = null;
    if (mask) { mask.classList.remove('on'); }
  }

  // 用冒泡阶段监听：表单自身的 onsubmit（如 return confirm(...)）先执行，
  // 它若 return false 会置 defaultPrevented，这里据此放行，不会把表单卡死。
  document.addEventListener('submit', function (e) {
    var form = e.target;
    if (!form || form.tagName !== 'FORM') { return; }
    if (e.defaultPrevented) { return; }
    if ((form.getAttribute('method') || 'get').toLowerCase() === 'get') { return; }
    if (form.hasAttribute('data-no-mask')) { return; }

    var uploading = hasFile(form);

    // 延后一帧再禁用按钮。本项目多个按钮靠 name/value 传参
    // （如「关闭工单」name=action value=close、批量操作 name=act value=assign），
    // 延后可以确保浏览器已经采集完表单数据，杜绝参数丢失。
    setTimeout(function () {
      form.querySelectorAll('[type=submit]').forEach(function (b) {
        if (b.disabled) { return; }
        b.dataset.txt = b.textContent;
        b.disabled = true;
        b.textContent = '处理中…';
      });
    }, 0);

    clearTimeout(showTimer);
    showTimer = setTimeout(function () { show(uploading); }, DELAY);
  });

  // 前进/后退回到缓存页时复位，否则遮罩会永久挂在屏幕上
  window.addEventListener('pageshow', hide);
})();
