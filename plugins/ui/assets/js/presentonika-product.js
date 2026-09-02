(function () {
  'use strict';

  function initSidebar() {
    var body = document.body;
    var toggles = Array.prototype.slice.call(
      document.querySelectorAll('[data-pnk-sidebar-toggle]')
    );
    var closers = Array.prototype.slice.call(
      document.querySelectorAll('[data-pnk-sidebar-close], .pnk-product-nav a')
    );

    if (!toggles.length) {
      return;
    }

    function setOpen(isOpen) {
      body.classList.toggle('pnk-sidebar-open', isOpen);
      toggles.forEach(function (button) {
        button.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
        if (button.matches('.pnk-product-menu')) {
          button.setAttribute('aria-label', isOpen ? 'Закрыть меню' : 'Открыть меню');
        }
      });
    }

    toggles.forEach(function (button) {
      button.addEventListener('click', function () {
        setOpen(!body.classList.contains('pnk-sidebar-open'));
      });
    });

    closers.forEach(function (element) {
      element.addEventListener('click', function () {
        setOpen(false);
      });
    });

    document.addEventListener('keydown', function (event) {
      if (event.key === 'Escape') {
        setOpen(false);
      }
    });

    window.addEventListener('resize', function () {
      if (window.innerWidth > 900) {
        setOpen(false);
      }
    });
  }

  function setControlValue(control, value) {
    if (!control || value === null || value === '') {
      return;
    }

    control.value = value;
    control.dispatchEvent(new Event('input', { bubbles: true }));
    control.dispatchEvent(new Event('change', { bubbles: true }));
  }

  function applyCreatePrefill() {
    var form = document.getElementById('pnk-presentationForm');
    if (!form) {
      return;
    }

    var params = new URLSearchParams(window.location.search);
    var topic = params.get('topic');
    var subject = params.get('subject');
    var grade = params.get('grade');
    var slides = params.get('slides');

    setControlValue(form.querySelector('[name="presentation_text"]'), topic);
    setControlValue(form.querySelector('[name="subject"]'), subject);
    setControlValue(form.querySelector('[name="grade"]'), grade);
    setControlValue(form.querySelector('[name="slide_count"]'), slides);

    if (topic) {
      var topicField = form.querySelector('[name="presentation_text"]');
      if (topicField) {
        window.setTimeout(function () {
          topicField.focus({ preventScroll: true });
        }, 80);
      }
    }
  }

  function init() {
    initSidebar();
    applyCreatePrefill();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
