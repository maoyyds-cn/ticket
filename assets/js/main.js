/* 前台交互 */
(function () {
  'use strict';

  // FAQ 手风琴
  document.querySelectorAll('.faq-q').forEach(function (el) {
    el.addEventListener('click', function () {
      el.closest('.faq-item').classList.toggle('open');
    });
  });

  // 移动端导航
  var navLinks = document.querySelector('.nav-links');
  var toggle = document.querySelector('.nav-toggle');
  if (navLinks && toggle) {
    toggle.addEventListener('click', function () {
      navLinks.classList.toggle('open');
    });
  }

  // 验证码刷新
  function bindCaptcha(root) {
    var btn = root.querySelector('[data-captcha-refresh]');
    if (!btn || btn.dataset.bound) return;
    btn.dataset.bound = '1';
    btn.addEventListener('click', function () {
      fetch('captcha.php?_=' + Date.now(), { headers: { 'X-Requested-With': 'XMLHttpRequest' }, cache: 'no-store' })
        .then(function (r) { return r.json(); })
        .then(function (d) {
          if (d && d.ok) {
            root.outerHTML = d.html;
            bindCaptcha(document.querySelector('[data-captcha]'));
          }
        })
        .catch(function () { });
    });
  }
  var capBox = document.querySelector('[data-captcha]');
  if (capBox) bindCaptcha(capBox);

  // 评分
  var rate = document.querySelector('[data-rate]');
  if (rate) {
    var btns = rate.querySelectorAll('button');
    btns.forEach(function (b) {
      b.addEventListener('click', function () {
        btns.forEach(function (x) { x.classList.remove('on'); });
        for (var i = 0; i < Number(b.dataset.v); i++) btns[i].classList.add('on');
        var hidden = rate.parentNode.querySelector('input[name="rating"]');
        if (hidden) hidden.value = b.dataset.v;
      });
    });
  }

  // 附件上传
  var picker = document.querySelector('[data-picker]');
  if (picker) {
    var input = picker.querySelector('input[type=file]');
    var list = document.querySelector('[data-filelist]');
    var dt = typeof DataTransfer === 'function' ? new DataTransfer() : null;

    function render() {
      if (list) {
        list.innerHTML = '';
        Array.from(dt ? dt.files : []).forEach(function (f, i) {
          var it = document.createElement('div');
          it.className = 'up-item';
          var isImg = /^image\//.test(f.type);
          it.innerHTML = (isImg ? '<img src="' + URL.createObjectURL(f) + '">' : '<span>📄</span>')
            + '<span class="nm"></span>'
            + '<button type="button" data-i="' + i + '">×</button>';
          // 用 textContent 写入文件名，避免文件名中的引号破坏 HTML
          it.querySelector('.nm').textContent = f.name;
          list.appendChild(it);
        });
        list.querySelectorAll('button').forEach(function (b) {
          b.addEventListener('click', function () {
            dt.deleteAtIndex(Number(b.dataset.i));
            render();
          });
        });
      }
      // 必须回写 input.files，否则表单提交的 $_FILES 恒为空。
      // 赋值失败必须提示：静默失败会让用户以为文件已选，提交后却丢失。
      if (input && dt) {
        try {
          input.files = dt.files;
          if (dt.files.length && (!input.files || input.files.length !== dt.files.length)) {
            throw new Error('write-back mismatch');
          }
        } catch (e) {
          var tip = document.createElement('div');
          tip.className = 'tip';
          tip.style.color = '#dc2626';
          tip.style.marginTop = '7px';
          tip.textContent = '当前浏览器不支持附件上传，请更换浏览器或改用拖拽';
          if (list && list.parentNode) { list.parentNode.appendChild(tip); }
        }
      }
    }

    function addFiles(arr) {
      if (!dt) return;
      Array.from(arr || []).forEach(function (f) { dt.items.add(f); });
      render();
    }

    // input 是 hidden，用户无法直接点击，必须由容器转发。
    // 原实现漏了这个绑定，导致「点击选择文件」毫无反应。
    picker.addEventListener('click', function () {
      if (input) input.click();
    });
    picker.addEventListener('keydown', function (e) {
      if (e.key === 'Enter' || e.key === ' ') {
        e.preventDefault();
        if (input) input.click();
      }
    });

    if (input) {
      input.addEventListener('change', function () {
        // 先把文件取到 DataTransfer，再清空 input。
        // 顺序不能反：清空后 input.files 为空，且部分浏览器在清空后
        // 拒绝 input.files = dt.files 赋值，导致已选文件在提交时丢失。
        var picked = input.files ? Array.from(input.files) : [];
        input.value = '';
        addFiles(picked);
      });
    }
    ['dragenter', 'dragover'].forEach(function (ev) {
      picker.addEventListener(ev, function (e) { e.preventDefault(); picker.classList.add('drag'); });
    });
    ['dragleave', 'drop'].forEach(function (ev) {
      picker.addEventListener(ev, function (e) { e.preventDefault(); picker.classList.remove('drag'); });
    });
    picker.addEventListener('drop', function (e) {
      if (e.dataTransfer && e.dataTransfer.files.length) {
        e.preventDefault();
        addFiles(e.dataTransfer.files);
      }
    });
    render();
  }

  // 字符计数
  var ta = document.querySelector('[data-count]');
  if (ta) {
    var out = document.querySelector('[data-count-out]');
    var max = Number(ta.dataset.count) || 2000;
    var upd = function () {
      if (out) out.textContent = ta.value.length + ' / ' + max;
      ta.style.color = ta.value.length > max ? '#ef4444' : '';
    };
    ta.addEventListener('input', upd);
    upd();
  }

  // 表单防重复提交与提交进度条统一由 progress.js 处理（覆盖所有 POST 表单）

  // ---- Cloudflare Turnstile ----
  // 脚本用 onload=turnstileReady 回调加载（见 turnstile_head()）。
  // 渲染必须等回调触发；若脚本被网络阻断，容器会一直空白，
  // 因此额外挂一个超时提示，避免用户对着空白区域干瞪眼。
  window.turnstileReady = function () { renderTurnstile(); };

  function renderTurnstile() {
    if (!window.turnstile) { return; }
    document.querySelectorAll('[data-turnstile]').forEach(function (el) {
      if (el.dataset.mounted) { return; }
      el.dataset.mounted = '1';
      try {
        window.turnstile.render(el, { sitekey: el.getAttribute('data-sitekey') || '' });
      } catch (e) {
        el.dataset.mounted = '';
        showTurnstileFail(el, '人机验证加载失败');
      }
    });
  }

  function showTurnstileFail(el, msg) {
    var box = el.parentNode;
    if (!box || box.querySelector('.ts-fail')) { return; }
    var d = document.createElement('div');
    d.className = 'ts-fail';
    d.textContent = msg;
    box.appendChild(d);
  }

  // 脚本可能早于本文件执行，也可能加载失败，两种情况都要覆盖
  if (window.turnstile) { renderTurnstile(); }
  setTimeout(function () {
    document.querySelectorAll('[data-turnstile]').forEach(function (el) {
      if (!el.dataset.mounted && !el.querySelector('iframe')) {
        showTurnstileFail(el, '人机验证未能加载，请检查网络后刷新页面');
      }
    });
  }, 12000);

  // 发送邮箱验证码
  var sendBtn = document.querySelector('[data-sendcode]');
  if (sendBtn) {
    var codeRow = sendBtn.closest('.code-row');
    var tip = document.createElement('div');
    tip.className = 'tip';
    tip.style.marginTop = '7px';
    if (codeRow && codeRow.parentNode) { codeRow.parentNode.appendChild(tip); }

    function setTip(msg, color) {
      tip.textContent = msg || '';
      tip.style.color = color || 'var(--muted)';
    }

    sendBtn.addEventListener('click', function () {
      var emailEl = document.getElementById('regEmail');
      var email = emailEl ? emailEl.value.trim() : '';
      if (!email) { if (emailEl) { emailEl.focus(); } return; }
      if (email.indexOf('@') < 1 || email.indexOf('.') < 0) {
        setTip('请填写正确的邮箱地址', '#dc2626');
        return;
      }

      var form = sendBtn.closest('form');
      var fd = new FormData(form);
      fd.set('email', email);

      // 必须带上当前 Turnstile 令牌。令牌一次性，失败后需重置组件
      var tsInput = form.querySelector('input[name="cf-turnstile-response"]');
      if (!tsInput || !tsInput.value) {
        setTip('请往下划，完成下方的人机验证', '#dc2626');
        return;
      }

      sendBtn.disabled = true;
      var old = sendBtn.textContent;
      sendBtn.textContent = '发送中…';
      setTip('');

      fetch(sendBtn.dataset.url, {
        method: 'POST',
        body: fd,
        credentials: 'same-origin',
        headers: { 'X-Requested-With': 'fetch' }
      })
        .then(function (r) {
          return r.text().then(function (t) {
            try { return JSON.parse(t); }
            catch (e) {
              // 服务端返回了 HTML 错误页（多为 500），带出状态码便于定位
              return { ok: false, msg: '服务异常（HTTP ' + r.status + '）', needTurnstile: true };
            }
          });
        })
        .then(function (d) {
          setTip(d.msg || (d.ok ? '已发送' : '发送失败'), d.ok ? '#059669' : '#dc2626');
          // 令牌已被服务端消费，必须重置组件让用户能再次验证
          if (d.needTurnstile && window.turnstile) {
            var w = form.querySelector('[data-turnstile]');
            if (w) {
              try { window.turnstile.reset(w); } catch (e) { /* ignore */ }
            }
          }
        })
        .catch(function () {
          setTip('网络异常，请稍后重试', '#dc2626');
        })
        .then(function () {
          sendBtn.disabled = false;
          sendBtn.textContent = old;
        });
    });
  }

  // 平滑锚点
  document.querySelectorAll('a[href^="#"]').forEach(function (a) {
    a.addEventListener('click', function (e) {
      var t = document.querySelector(a.getAttribute('href'));
      if (t) { e.preventDefault(); window.scrollTo({ top: t.offsetTop - 80, behavior: 'smooth' }); }
    });
  });
})();
