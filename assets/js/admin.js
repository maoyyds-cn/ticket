/* 后台交互 */
(function () {
  'use strict';

  // ---- Cloudflare Turnstile ----
  // 与前台同一套：脚本 onload 回调 + 显式 render。
  window.turnstileReady = function () { renderTs(); };

  function renderTs() {
    if (!window.turnstile) { return; }
    document.querySelectorAll('[data-turnstile]').forEach(function (el) {
      if (el.dataset.mounted) { return; }
      el.dataset.mounted = '1';
      try {
        window.turnstile.render(el, { sitekey: el.getAttribute('data-sitekey') || '' });
      } catch (e) {
        el.dataset.mounted = '';
        var d = document.createElement('div');
        d.className = 'ts-fail';
        d.textContent = '人机验证加载失败';
        el.parentNode.appendChild(d);
      }
    });
  }

  if (window.turnstile) { renderTs(); }
  setTimeout(function () {
    document.querySelectorAll('[data-turnstile]').forEach(function (el) {
      if (!el.dataset.mounted && !el.querySelector('iframe') && !el.parentNode.querySelector('.ts-fail')) {
        var d = document.createElement('div');
        d.className = 'ts-fail';
        d.textContent = '人机验证未能加载，请检查网络后刷新页面';
        el.parentNode.appendChild(d);
      }
    });
  }, 12000);

  // 侧栏移动端
  var side = document.querySelector('.side');
  var burger = document.querySelector('.burger');
  if (side && burger) {
    burger.addEventListener('click', function (e) { e.stopPropagation(); side.classList.toggle('open'); });
    document.addEventListener('click', function (e) {
      if (side.classList.contains('open') && !side.contains(e.target)) side.classList.remove('open');
    });
  }

  // 弹层
  function openModal(sel) {
    var m = typeof sel === 'string' ? document.querySelector(sel) : sel;
    if (m) m.classList.add('open');
  }
  function closeModal(m) { if (m) m.classList.remove('open'); }
  window.openModal = openModal;

  document.addEventListener('click', function (e) {
    var o = e.target.closest('[data-modal]');
    if (o) { e.preventDefault(); openModal('#' + o.dataset.modal); return; }
    var c = e.target.closest('[data-close]');
    if (c) { e.preventDefault(); closeModal(c.closest('.modal')); return; }
    if (e.target.classList.contains('modal')) e.target.classList.remove('open');
  });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') document.querySelectorAll('.modal.open').forEach(function (m) { m.classList.remove('open'); });
  });

  // 确认操作
  document.querySelectorAll('[data-confirm]').forEach(function (el) {
    el.addEventListener('click', function (e) {
      if (!confirm(el.dataset.confirm)) { e.preventDefault(); e.stopPropagation(); }
    });
  });

  // 行内单条操作：把 data-single/data-id 填入表单后提交
  document.querySelectorAll('[data-single]').forEach(function (b) {
    b.addEventListener('click', function (e) {
      if (b.dataset.confirm && !confirm(b.dataset.confirm)) { e.preventDefault(); return; }
      var f = b.closest('form');
      if (!f) return;
      var act = f.querySelector('input[name=act]');
      var id = f.querySelector('input[name=id]');
      if (act) act.value = b.dataset.single;
      if (id) id.value = b.dataset.id;
    });
  });

  // 复选框全选
  var all = document.querySelector('[data-check-all]');
  if (all) {
    all.addEventListener('change', function () {
      document.querySelectorAll('[data-check-item]').forEach(function (c) { c.checked = all.checked; });
    });
  }

  // 表单防重复提交与提交进度条统一由 progress.js 处理（覆盖所有 POST 表单）

  // 图片预览（灯箱由 _foot.php 注入）
  var pv = document.getElementById('imgPv');
  var pvMask = document.getElementById('imgPvMask');
  if (pv) {
    var closePv = function () {
      pv.style.display = 'none';
      if (pvMask) pvMask.style.display = 'none';
    };
    document.querySelectorAll('.thumb img, [data-img]').forEach(function (im) {
      im.addEventListener('click', function (e) {
        e.preventDefault();
        pv.src = im.dataset.img || im.src;
        pv.style.display = 'block';
        if (pvMask) pvMask.style.display = 'block';
      });
    });
    pv.addEventListener('click', closePv);
    if (pvMask) pvMask.addEventListener('click', closePv);
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') closePv(); });
  }

  // 富文本工具
  document.querySelectorAll('[data-ed-wrap]').forEach(function (w) {
    var ta = w.querySelector('textarea');
    w.querySelectorAll('[data-ed]').forEach(function (b) {
      b.addEventListener('click', function () {
        var v = b.dataset.ed;
        var s = ta.selectionStart, e = ta.selectionEnd, cur = ta.value;
        if (v === 'hr') { ta.value = cur.slice(0, s) + '\n\n---\n\n' + cur.slice(e); }
        else { ta.value = cur.slice(0, s) + v + cur.slice(e); }
        ta.focus();
        ta.setSelectionRange(s + v.length, s + v.length);
      });
    });
  });

  // 附件上传
  // 原实现完全没有这段，导致后台回复框的 data-picker 点了没反应、选了也传不上去。
  document.querySelectorAll('[data-picker]').forEach(function (picker) {
    var input = picker.querySelector('input[type=file]');
    var list = document.querySelector('[data-filelist]');
    var dt = typeof DataTransfer === 'function' ? new DataTransfer() : null;
    if (!dt) return;

    function render() {
      if (list) {
        list.innerHTML = '';
        Array.from(dt.files).forEach(function (f, i) {
          var it = document.createElement('div');
          it.className = 'up-item';
          var isImg = /^image\//.test(f.type);
          it.innerHTML = (isImg ? '<img src="' + URL.createObjectURL(f) + '">' : '<span>📄</span>')
            + '<span class="nm"></span>'
            + '<button type="button" data-i="' + i + '">×</button>';
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
      // 必须回写 input.files，否则表单提交的 $_FILES 恒为空
      if (input) {
        try { input.files = dt.files; } catch (e) { /* 旧浏览器降级 */ }
      }
    }

    function addFiles(arr) {
      Array.from(arr || []).forEach(function (f) { dt.items.add(f); });
      render();
    }

    // input 是 hidden，用户无法直接点击，必须由容器转发
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
        // 先取出文件再清空，顺序反了会导致已选文件丢失
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
  });

  // 字符计数
  document.querySelectorAll('[data-count]').forEach(function (ta) {
    var out = document.querySelector('[data-count-for="' + ta.dataset.count + '"]');
    if (!out) return;
    var upd = function () { out.textContent = ta.value.length; };
    ta.addEventListener('input', upd);
    upd();
  });

  // 复制
  document.querySelectorAll('[data-copy]').forEach(function (b) {
    b.addEventListener('click', function () {
      var t = document.querySelector(b.dataset.copy);
      if (!t) return;
      t.select(); t.setSelectionRange(0, 99999);
      try { document.execCommand('copy'); } catch (err) { }
      var o = b.textContent; b.textContent = '已复制';
      setTimeout(function () { b.textContent = o; }, 1400);
    });
  });

  // 实时筛选
  document.querySelectorAll('[data-filter-table]').forEach(function (inp) {
    inp.addEventListener('input', function () {
      var q = inp.value.toLowerCase();
      var tbl = document.querySelector(inp.dataset.filterTable);
      if (!tbl) return;
      tbl.querySelectorAll('tbody tr').forEach(function (tr) {
        tr.style.display = tr.textContent.toLowerCase().indexOf(q) > -1 ? '' : 'none';
      });
    });
  });
})();
