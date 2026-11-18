/**
 * 后台通用脚本（外置文件，配合 CSP script-src 'self'，不使用内联脚本/事件）。
 * 1) 危险操作确认：表单带 data-confirm 属性时，提交前弹出确认框。
 * 2) 复制链接：按钮带 data-copy 属性时，点击复制到剪贴板。
 */
(function () {
  'use strict';

  // ---- 删除等危险操作确认 ----
  document.addEventListener('submit', function (ev) {
    var form = ev.target.closest ? ev.target.closest('form[data-confirm]') : null;
    if (!form) { return; }
    if (!window.confirm(form.getAttribute('data-confirm'))) {
      ev.preventDefault();
    }
  });

  // ---- 复制链接：优先 Clipboard API，非安全上下文退回 execCommand ----
  document.addEventListener('click', function (ev) {
    var btn = ev.target.closest ? ev.target.closest('[data-copy]') : null;
    if (!btn) { return; }
    var text  = btn.getAttribute('data-copy');
    var label = btn.textContent;
    var done  = function () {
      btn.textContent = '已复制';
      setTimeout(function () { btn.textContent = label; }, 1200);
    };
    var fallback = function () {
      var ta = document.createElement('textarea');
      ta.value = text;
      ta.setAttribute('readonly', '');
      ta.style.position = 'fixed';
      ta.style.top = '-1000px';
      document.body.appendChild(ta);
      ta.select();
      try { document.execCommand('copy'); done(); }
      catch (e) { window.prompt('请手动复制链接', text); }
      document.body.removeChild(ta);
    };
    if (navigator.clipboard && window.isSecureContext) {
      navigator.clipboard.writeText(text).then(done, fallback);
    } else {
      fallback();
    }
  });
})();
