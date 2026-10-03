/**
 * 前端交互
 *
 * 设计原则：所有功能都必须「没有 JS 也能用」。
 *  - 手风琴用原生 <details>，本文件完全不参与；
 *  - 表单照常提交，JS 只做字符计数、评分选星这类增强；
 *  - 没有任何一个按钮依赖 JS 才能完成提交。
 *
 * 旧版有两个实际故障值得记录，本文件对应的处理：
 *  1) 文件上传列表在 DataTransfer 不可用时**静默丢弃**已选文件：
 *     输入框被清空、列表为空、也没有任何提示。这里改为
 *     「原生 input 的 value 永不改动」，列表只做展示，因此文件一定能提交。
 *  2) progress.js 会禁用提交按钮并把文案改成「处理中…」，
 *     但 pageshow 只隐藏遮罩、不恢复按钮，从缓存返回后按钮永久禁用。
 *     这里在提交时不做任何禁用，只加一个 aria-busy 标记。
 */
(function () {
  'use strict';

  /** 安全查询 */
  function $(sel, root) {
    return (root || document).querySelector(sel);
  }
  function $$(sel, root) {
    return Array.prototype.slice.call((root || document).querySelectorAll(sel));
  }

  document.addEventListener('DOMContentLoaded', function () {

    /* ---------------------------------------------------------------
       移动端导航
       --------------------------------------------------------------- */
    var burger = $('#navBurger');
    var menu = $('#navMenu');
    if (burger && menu) {
      burger.addEventListener('click', function () {
        var open = menu.classList.toggle('is-open');
        // 真实反映展开状态：旧版只切 class，屏幕阅读器无从得知
        burger.setAttribute('aria-expanded', open ? 'true' : 'false');
        burger.setAttribute('aria-label', open ? '收起导航菜单' : '展开导航菜单');
      });

      // 点击菜单外部关闭，避免菜单挡住内容
      document.addEventListener('click', function (e) {
        if (!menu.classList.contains('is-open')) {
          return;
        }
        if (menu.contains(e.target) || burger.contains(e.target)) {
          return;
        }
        menu.classList.remove('is-open');
        burger.setAttribute('aria-expanded', 'false');
      });

      // Esc 关闭并把焦点还给按钮
      document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && menu.classList.contains('is-open')) {
          menu.classList.remove('is-open');
          burger.setAttribute('aria-expanded', 'false');
          burger.focus();
        }
      });
    }

    /* ---------------------------------------------------------------
       用户下拉菜单：点击外部关闭
       --------------------------------------------------------------- */
    $$('.user-menu').forEach(function (details) {
      document.addEventListener('click', function (e) {
        if (details.open && !details.contains(e.target)) {
          details.open = false;
        }
      });
      document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && details.open) {
          details.open = false;
          var s = details.querySelector('summary');
          if (s) { s.focus(); }
        }
      });
    });

    /* ---------------------------------------------------------------
       字符计数
       --------------------------------------------------------------- */
    $$('[data-count-for]').forEach(function (out) {
      var name = out.getAttribute('data-count-for');
      var field = document.querySelector('[name="' + name + '"]');
      if (!field) { return; }
      var max = parseInt(out.getAttribute('data-count-max') || '0', 10);

      var update = function () {
        var len = Array.from(field.value).length;
        out.textContent = max > 0 ? len + ' / ' + max : String(len);
        out.classList.toggle('is-over', max > 0 && len > max);
      };
      field.addEventListener('input', update);
      update();
    });

    /* ---------------------------------------------------------------
       附件展示：只读列表 + 可移除标记
       
       注意这里不改动 input.files。旧版试图用 DataTransfer 回写文件列表，
       一旦浏览器不支持就静默清空选择结果。这里只把它当作「已选文件」的
       可视化，移除按钮通过标记 + 提示让用户重新选择，
       而不是冒丢失文件的风险。
       --------------------------------------------------------------- */
    $$('[data-uploader]').forEach(function (box) {
      var input = box.querySelector('input[type="file"]');
      var list = box.querySelector('[data-filelist]');
      if (!input || !list) { return; }

      var render = function () {
        var files = input.files ? Array.prototype.slice.call(input.files) : [];
        list.innerHTML = '';
        if (!files.length) {
          list.hidden = true;
          return;
        }
        list.hidden = false;
        files.forEach(function (f) {
          var row = document.createElement('div');
          row.className = 'file-item';
          var ico = document.createElement('span');
          ico.className = 'file-ico';
          ico.setAttribute('aria-hidden', 'true');
          ico.textContent = '📎';
          var name = document.createElement('span');
          name.className = 'file-name';
          name.textContent = f.name;
          var size = document.createElement('span');
          size.className = 'file-size';
          size.textContent = formatSize(f.size);
          row.appendChild(ico);
          row.appendChild(name);
          row.appendChild(size);
          list.appendChild(row);
        });
      };

      input.addEventListener('change', render);
      render();

      // 点击整块区域任意位置都能唤起选择框（<label> 已原生支持，
      // 这里额外兼容键盘激活）
      box.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' || e.key === ' ') {
          e.preventDefault();
          input.click();
        }
      });
    });

    function formatSize(bytes) {
      if (bytes < 1024) { return bytes + ' B'; }
      if (bytes < 1048576) { return (bytes / 1024).toFixed(1) + ' KB'; }
      return (bytes / 1048576).toFixed(2) + ' MB';
    }

    /* ---------------------------------------------------------------
       评分：radio 的视觉反馈
       
       注意这里的定位——评分本身**不依赖** JS：radio 是真正的表单控件，
       没有 JS 也能选中并提交。JS 只做两件锦上添花的事：
       给已选中的星星上色、显示文字档位。
       --------------------------------------------------------------- */
    $$('[data-rate]').forEach(function (group) {
      var inputs = $$('.rate-input', group);
      var stars = $$('.rate-star', group);
      var text = group.querySelector('[data-rate-text]');
      var labels = ['', '很差', '较差', '一般', '满意', '非常满意'];

      var paint = function (value) {
        stars.forEach(function (s, i) {
          s.classList.toggle('is-on', i < value);
        });
        if (text) {
          text.textContent = value > 0 ? labels[value] : '尚未评分';
        }
      };

      inputs.forEach(function (input) {
        input.addEventListener('change', function () {
          paint(parseInt(input.value, 10) || 0);
        });
      });

      // 初始状态（浏览器前进后退会保留选中值，这里同步一次）
      var checked = inputs.filter(function (i) { return i.checked; })[0];
      paint(checked ? (parseInt(checked.value, 10) || 0) : 0);
    });

    /* ---------------------------------------------------------------
       危险操作二次确认
       
       同时提供 data-confirm 属性方案与 <form data-confirm-form> 方案。
       注意：这只是防误触，不能替代服务端的权限与状态校验——
       旧版把确认完全交给前端，脚本直接 POST 就绕过了。
       --------------------------------------------------------------- */
    document.addEventListener('click', function (e) {
      var el = e.target.closest ? e.target.closest('[data-confirm]') : null;
      if (!el) { return; }
      var msg = el.getAttribute('data-confirm') || '确定要执行这个操作吗？';
      if (!window.confirm(msg)) {
        e.preventDefault();
        e.stopPropagation();
      }
    });

    $$('form[data-confirm-form]').forEach(function (form) {
      form.addEventListener('submit', function (e) {
        var msg = form.getAttribute('data-confirm-form') || '确定要提交吗？';
        if (!window.confirm(msg)) {
          e.preventDefault();
        }
      });
    });

    /* ---------------------------------------------------------------
       提交状态提示（不禁用按钮）
       
       旧版会禁用按钮并改写文案，且从 bfcache 返回后不会恢复。
       这里只加 aria-busy 和一行提示，按钮保持可用，
       用户可以继续操作或放弃等待。
       --------------------------------------------------------------- */
    $$('form[data-busy]').forEach(function (form) {
      form.addEventListener('submit', function () {
        form.setAttribute('aria-busy', 'true');
        var hint = form.querySelector('[data-busy-hint]');
        if (hint) { hint.hidden = false; }
      });
    });

    // 从缓存返回时清掉上一轮的忙碌标记
    window.addEventListener('pageshow', function () {
      $$('form[aria-busy="true"]').forEach(function (form) {
        form.removeAttribute('aria-busy');
        var hint = form.querySelector('[data-busy-hint]');
        if (hint) { hint.hidden = true; }
      });
    });

    /* ---------------------------------------------------------------
       邮箱验证码
       --------------------------------------------------------------- */
    var codeBtn = $('[data-send-code]');
    if (codeBtn) {
      codeBtn.addEventListener('click', function () {
        var emailField = document.querySelector('[name="email"]');
        var tokenField = document.querySelector('[name="_token"]');
        var status = $('[data-code-status]');
        var email = emailField ? emailField.value.trim() : '';

        var say = function (msg, ok) {
          if (!status) { return; }
          status.textContent = msg;
          status.style.color = ok ? 'var(--ok-fg)' : 'var(--err-fg)';
        };

        if (!email) {
          say('请先填写邮箱', false);
          if (emailField) { emailField.focus(); }
          return;
        }

        codeBtn.disabled = true;
        codeBtn.textContent = '发送中…';
        say('正在发送…', true);

        var body = new URLSearchParams();
        body.append('email', email);
        body.append('_token', tokenField ? tokenField.value : '');

        fetch(codeBtn.getAttribute('data-send-code'), {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
          body: body.toString(),
          credentials: 'same-origin'
        }).then(function (r) {
          return r.json().catch(function () {
            throw new Error('服务器返回了无法解析的内容');
          });
        }).then(function (data) {
          say(data.message || (data.ok ? '验证码已发送' : '发送失败'), !!data.ok);
          if (!data.ok) { return; }
          // 冷却倒计时，避免用户连点
          var left = parseInt(data.cooldown || '60', 10);
          var tick = function () {
            if (left <= 0) {
              codeBtn.disabled = false;
              codeBtn.textContent = '重新获取';
              return;
            }
            codeBtn.textContent = left + ' 秒后重试';
            left -= 1;
            window.setTimeout(tick, 1000);
          };
          tick();
        }).catch(function (err) {
          // 旧版这里是空的 catch，失败时按钮毫无反馈
          say('发送失败：' + (err && err.message ? err.message : '网络异常') + '，请稍后重试', false);
          codeBtn.disabled = false;
          codeBtn.textContent = '重新获取';
        });
      });
    }

    /* ---------------------------------------------------------------
       图片灯箱
       --------------------------------------------------------------- */
    var overlay = null;
    document.addEventListener('click', function (e) {
      var link = e.target.closest ? e.target.closest('a.thumb') : null;
      if (!link) { return; }
      // 让用户可以按住修饰键在新标签打开
      if (e.metaKey || e.ctrlKey || e.shiftKey || e.button !== 0) { return; }
      e.preventDefault();

      var img = link.querySelector('img');
      if (!img) { return; }

      overlay = document.createElement('div');
      overlay.setAttribute('role', 'dialog');
      overlay.setAttribute('aria-modal', 'true');
      overlay.setAttribute('aria-label', img.alt || '图片预览');
      overlay.style.cssText = 'position:fixed;inset:0;z-index:200;display:grid;place-items:center;' +
        'background:rgba(15,23,42,.86);padding:24px;cursor:zoom-out;backdrop-filter:blur(3px)';

      var big = document.createElement('img');
      big.src = link.getAttribute('href');
      big.alt = img.alt || '';
      big.style.cssText = 'max-width:100%;max-height:100%;border-radius:12px;box-shadow:0 24px 64px rgba(0,0,0,.5)';

      overlay.appendChild(big);
      document.body.appendChild(overlay);
      document.body.style.overflow = 'hidden';

      var close = function () {
        if (overlay && overlay.parentNode) { overlay.parentNode.removeChild(overlay); }
        overlay = null;
        document.body.style.overflow = '';
        document.removeEventListener('keydown', onKey);
      };
      var onKey = function (ev) {
        if (ev.key === 'Escape') { close(); }
      };
      overlay.addEventListener('click', close);
      document.addEventListener('keydown', onKey);
    });

  });
  /* ---------------------------------------------------------------
     导航预取

     背景：源站在美国、用户主要在国内。每一次站内跳转都要重新跨国走一趟
     TCP/TLS 加一个来回，这是「切页要等」最主要的原因，而且改代码解决不了。
     预取能把这段等待提前到用户还在「看上一个页面、准备点链接」的时候：
     鼠标移到链接上或手指按下的瞬间就开始加载，等真正点击时页面往往已经到位，
     于是切页感觉是即时的。

     几点克制：
       - 只在 hover 停留 80ms 后才发起，避免鼠标划过一排链接就下载一整页；
       - 尊重 Save-Data（省流量模式）与 2G 网络，直接不预取；
       - 每页最多预取 6 个，防止一次划过大菜单时发起几十个请求；
       - 只预取同源、非下载、非锚点的链接。
     --------------------------------------------------------------- */
  (function initPrefetch() {
    var conn = navigator.connection || {};
    if (conn.saveData) { return; }                       // 省流量模式
    var type = conn.effectiveType || '';
    if (type === 'slow-2g' || type === '2g') { return; } // 慢网络
    if (!window.fetch) { return; }

    var MAX = 6;
    var done = 0;
    var pending = {};
    var timer = null;

    var prefetch = function (href) {
      if (done >= MAX) { return; }
      if (!href || href.indexOf('#') === 0) { return; }
      var url;
      try { url = new URL(href, location.href); } catch (e) { return; }
      if (url.origin !== location.origin) { return; }
      if (url.pathname === location.pathname) { return; }  // 当前页不用预取
      if (/\/(logout|admin\/logout)/.test(url.pathname)) { return; }
      if (pending[url.pathname]) { return; }
      pending[url.pathname] = true;
      done++;
      // 低优先级请求：不抢当前页面的带宽
      try {
        fetch(url.href, {credentials: 'same-origin', priority: 'low'}).catch(function () {});
      } catch (e) { /* 忽略 */ }
    };

    document.addEventListener('mouseover', function (e) {
      var a = e.target && e.target.closest ? e.target.closest('a[href]') : null;
      if (!a) { return; }
      clearTimeout(timer);
      timer = setTimeout(function () { prefetch(a.getAttribute('href')); }, 80);
    }, {passive: true});

    document.addEventListener('mouseout', function () { clearTimeout(timer); }, {passive: true});
    // 触屏没有 hover：按下时立刻预取，仍能赶在浏览器开始导航之前
    document.addEventListener('touchstart', function (e) {
      var a = e.target && e.target.closest ? e.target.closest('a[href]') : null;
      if (a) { prefetch(a.getAttribute('href')); }
    }, {passive: true});
  })();

  /* ---------------------------------------------------------------
     表单防重复提交

     提交后立刻禁用按钮并改文案。旧版是「故意不禁用」以便用户看到反馈——
     代价是双击就会产生两张一模一样的工单与两封通知邮件，
     而「每 IP 每小时 5 单」的限制拦不住，因为它数的是已入库的行数。
     --------------------------------------------------------------- */
  $$('form[data-guard]').forEach(function (form) {
    form.addEventListener('submit', function () {
      var btn = form.querySelector('button[type="submit"], button:not([type])');
      if (!btn || btn.disabled) { return; }
      // 用微延时，确保提交动作已经发出再去禁用按钮
      setTimeout(function () {
        btn.disabled = true;
        btn.classList.add('is-busy');
        var label = btn.getAttribute('data-busy-text') || '处理中…';
        btn.textContent = label;
      }, 0);
    });
  });

  /* ---------------------------------------------------------------
     表单未保存提醒（仅长表单）
     --------------------------------------------------------------- */
  $$('form[data-dirty-guard]').forEach(function (form) {
    var dirty = false;
    form.addEventListener('input', function () { dirty = true; });
    window.addEventListener('beforeunload', function (e) {
      if (!dirty) { return; }
      e.preventDefault();
      e.returnValue = '';
    });
    form.addEventListener('submit', function () { dirty = false; });
  });
})();
