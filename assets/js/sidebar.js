// Sidebar collapsible groups with animation and optional persistence
(function() {
  function qs(sel, ctx) { return (ctx || document).querySelector(sel); }
  function qsa(sel, ctx) { return Array.prototype.slice.call((ctx || document).querySelectorAll(sel)); }

  function setMaxHeight(el, to) {
    el.style.maxHeight = to + 'px';
  }

  function openGroup(group, save) {
    var items = qs('.group-items', group);
    if (!items) return;
    group.classList.add('open');
    items.style.display = 'block';
    // force reflow
    var _ = items.offsetHeight;
    setMaxHeight(items, items.scrollHeight);
    var header = qs('.group-header', group);
    if (header) header.setAttribute('aria-expanded', 'true');
    if (save) persist(group, true);
  }

  function closeGroup(group, save) {
    var items = qs('.group-items', group);
    if (!items) return;
    setMaxHeight(items, items.scrollHeight);
    // force reflow
    var _ = items.offsetHeight;
    setMaxHeight(items, 0);
    group.classList.remove('open');
    var header = qs('.group-header', group);
    if (header) header.setAttribute('aria-expanded', 'false');
    if (save) persist(group, false);
  }

  function toggleGroup(group) {
    if (group.classList.contains('open')) closeGroup(group, true);
    else openGroup(group, true);
  }

  function storageKey(group) {
    var k = group.getAttribute('data-group-key');
    if (!k) {
      var label = qs('.group-label', group);
      k = label ? label.textContent.trim() : 'group-' + Math.random().toString(36).slice(2);
      group.setAttribute('data-group-key', k);
    }
    return 'sidebar:group:' + k;
  }

  function persist(group, isOpen) {
    try {
      localStorage.setItem(storageKey(group), isOpen ? '1' : '0');
    } catch (e) { /* ignore */ }
  }

  function restore(group) {
    try {
      var v = localStorage.getItem(storageKey(group));
      return v === '1';
    } catch (e) { return false; }
  }

  function initGroup(group) {
    var header = qs('.group-header', group);
    var items = qs('.group-items', group);
    if (!header || !items) return;

    // prepare items container
    items.style.overflow = 'hidden';
    items.style.transition = 'max-height 0.25s ease';
    items.style.willChange = 'max-height';

    // initial state
    var shouldOpen = restore(group);
    if (group.classList.contains('open') || shouldOpen) {
      group.classList.add('open');
      items.style.display = 'block';
      setMaxHeight(items, items.scrollHeight);
      header.setAttribute('aria-expanded', 'true');
    } else {
      group.classList.remove('open');
      items.style.display = 'block';
      setMaxHeight(items, 0);
      header.setAttribute('aria-expanded', 'false');
    }

    // toggle
    header.addEventListener('click', function(e) {
      e.preventDefault();
      toggleGroup(group);
    });

    // update height on transition end to allow re-open to auto-size
    items.addEventListener('transitionend', function() {
      if (group.classList.contains('open')) {
        // set to auto height after opening to adapt to content changes
        items.style.maxHeight = items.scrollHeight + 'px';
      }
    });

    // adjust on window resize
    window.addEventListener('resize', function() {
      if (group.classList.contains('open')) {
        setMaxHeight(items, items.scrollHeight);
      }
    });
  }

  document.addEventListener('DOMContentLoaded', function() {
    qsa('.sidebar .sidebar-group').forEach(initGroup);
  });
})();
