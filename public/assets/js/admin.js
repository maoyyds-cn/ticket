/**
 * 后台交互
 *
 * 与旧版的差别：旧版 admin.js 里有一整块「多文件上传器」代码
 * （[data-picker] / [data-filelist]）以及 data-copy、data-filter-table，
 * 但后台页面里没有一处使用它们——全是死代码，而且配套 CSS 也是死的。
 * 这里只保留真正被用到的能力。
 *
 * 另外，旧版把「危险操作确认」完全交给前端的 data-confirm，
 * 服务端不做二次确认，脚本直接 POST 就绕过了。这里保留前端确认以改善
 * 体验，同时后端对清空日志这类操作强制要求显式参数（见 LogController）。
 */
(function () {
  'use strict';

  function $(sel, root) {
    return (root || document).querySelector(sel);
  }
  function $$(sel, root) {
    return Array.prototype.slice.call((root || document).querySelectorAll(sel));
  }

  document.addEventListener('DOMContentLoaded', function () {

    /* ---------------------------------------------------------------
       侧栏（窄屏抽屉）
       --------------------------------------------------------------- */
    var burger = $('#admBurger');
    var side = $('#admSide');
    if (burger && side) {
      burger.addEventListener('click', function () {
        var open = side.classList.toggle('is-open');
        burger.setAttribute('aria-expanded', open ? 'true' : 'false');
        burger.setAttribute('aria-label', open ? '收起侧栏' : '展开侧栏');
      });

      document.addEventListener('click', function (e) {
        if (!side.classList.contains('is-open')) {
          return;
        }
        if (side.contains(e.target) || burger.contains(e.target)) {
          return;
        }
        side.classList.remove('is-open');
        burger.setAttribute('aria-expanded', 'false');
      });

      document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && side.classList.contains('is-open')) {
          side.classList.remove('is-open');
          burger.setAttribute('aria-expanded', 'false');
          burger.focus();
        }
      });
    }

    /* ---------------------------------------------------------------
       全选 / 反选
       --------------------------------------------------------------- */
    $$('[data-check-all]').forEach(function (master) {
      var scopeSel = master.getAttribute('data-check-all');
      var scope = scopeSel && scopeSel !== '1' ? $(scopeSel) : document;
      var boxes = $$('[data-check-item]', scope || document);

      var sync = function () {
        var checked = boxes.filter(function (b) { return b.checked; }).length;
        master.checked = boxes.length > 0 && checked === boxes.length;
        master.indeterminate = checked > 0 && checked < boxes.length;

        // 同步更新批量操作条上的计数
        var counter = $('[data-check-count]', scope || document);
        if (counter) { counter.textContent = String(checked); }
        var bar = $('[data-bulkbar]', scope || document);
        if (bar) { bar.hidden = checked === 0; }
      };

      master.addEventListener('change', function () {
        boxes.forEach(function (b) { b.checked = master.checked; });
        sync();
      });
      boxes.forEach(function (b) { b.addEventListener('change', sync); });
      sync();
    });

    /* ---------------------------------------------------------------
       单条记录操作：一个共享表单提交
       
       旧版用隐藏字段 act + id 填进同一个表单再提交。这里保持这个模式，
       但补上目标存在性检查，避免误提交到空 id。
       --------------------------------------------------------------- */
    $$('[data-single]').forEach(function (trigger) {
      trigger.addEventListener('click', function (e) {
        var formSel = trigger.getAttribute('data-form');
        var form = formSel ? $(formSel) : trigger.closest('form');
        if (!form) { return; }

        var want = trigger.getAttribute('data-confirm');
        if (want && !window.confirm(want)) {
          e.preventDefault();
          return;
        }

        var actField = form.querySelector('[name="act"]');
        var idField = form.querySelector('[name="id"]');
        var act = trigger.getAttribute('data-act') || '';
        var id = trigger.getAttribute('data-id') || '';

        if (idField && id === '') {
          // 没有目标 id 就不提交，避免把一个空操作发到服务端
          e.preventDefault();
          return;
        }
        if (actField) { actField.value = act; }
        if (idField) { idField.value = id; }
        form.submit();
      });
    });

    /* ---------------------------------------------------------------
       模态框
       --------------------------------------------------------------- */
    var openModal = function (id) {
      var m = document.getElementById(id);
      if (!m) { return; }
      m.classList.add('is-open');
      // 聚焦到第一个可输入元素，键盘用户不必先 Tab 一圈
      var first = m.querySelector('input:not([type="hidden"]), select, textarea, button');
      if (first) { first.focus(); }
    };
    var closeModal = function (m) {
      m.classList.remove('is-open');
    };

    $$('[data-modal]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        openModal(btn.getAttribute('data-modal'));
      });
    });
    $$('[data-modal-close]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var m = btn.closest('.modal');
        if (m) { closeModal(m); }
      });
    });
    $$('.modal').forEach(function (m) {
      // 点击遮罩关闭
      m.addEventListener('click', function (e) {
        if (e.target === m) { closeModal(m); }
      });
      // 已通过 URL (?edit=1) 打开的直接显示
      if (m.getAttribute('data-auto-open') === '1') {
        m.classList.add('is-open');
      }
    });
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') {
        $$('.modal.is-open').forEach(closeModal);
      }
    });
    window.openModal = openModal;

    /* ---------------------------------------------------------------
       富文本工具栏：包住选中文本
       
       注意这里刻意不产出 HTML 标签：知识库内容以纯文本存储与渲染
       （见 KnowledgeService 的说明），因此工具栏提供的是纯文本排版辅助
       （如无序列表符号、分隔线），避免让管理员以为自己在写 HTML。
       --------------------------------------------------------------- */
    $$('[data-ed-wrap]').forEach(function (bar) {
      var targetSel = bar.getAttribute('data-ed-wrap');
      var ta = targetSel ? $(targetSel) : bar.parentNode.querySelector('textarea');
      if (!ta) { return; }

      bar.addEventListener('click', function (e) {
        var btn = e.target.closest('button[data-ed]');
        if (!btn) { return; }
        e.preventDefault();

        var kind = btn.getAttribute('data-ed');
        var start = ta.selectionStart;
        var end = ta.selectionEnd;
        var value = ta.value;
        var selected = value.slice(start, end);

        var insert = '';
        switch (kind) {
          case 'bold':
            insert = '【' + (selected || '重点') + '】';
            break;
          case 'bullet':
            insert = (selected || '条目').split('\n').map(function (l) {
              return '• ' + l;
            }).join('\n');
            break;
          case 'number':
            insert = (selected || '步骤').split('\n').map(function (l, i) {
              return (i + 1) + '. ' + l;
            }).join('\n');
            break;
          case 'divider':
            insert = '\n──────────\n';
            break;
          case 'code':
            insert = '`' + (selected || 'code') + '`';
            break;
          default:
            return;
        }

        ta.value = value.slice(0, start) + insert + value.slice(end);
        ta.focus();
        ta.selectionStart = ta.selectionEnd = start + insert.length;
        ta.dispatchEvent(new Event('input', { bubbles: true }));
      });
    });

    /* ---------------------------------------------------------------
       危险操作确认（前端体验层）
       --------------------------------------------------------------- */
    document.addEventListener('click', function (e) {
      var el = e.target.closest ? e.target.closest('[data-confirm]') : null;
      if (!el || el.hasAttribute('data-single')) { return; }
      var msg = el.getAttribute('data-confirm');
      if (msg && !window.confirm(msg)) {
        e.preventDefault();
        e.stopPropagation();
      }
    });

    /* ---------------------------------------------------------------
       筛选表单：选择即提交
       --------------------------------------------------------------- */
    $$('[data-autosubmit]').forEach(function (field) {
      field.addEventListener('change', function () {
        var form = field.closest('form');
        if (form) { form.submit(); }
      });
    });

  });
})();
