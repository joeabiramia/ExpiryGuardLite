  </div><!-- /page-body -->
</div><!-- /page-content -->
</div><!-- /admin-shell -->

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
/* Global toast helper */
function showToast(message, type = 'ok') {
  const icons = { ok: 'bi-check-circle-fill', err: 'bi-x-circle-fill', warn: 'bi-exclamation-triangle-fill' };
  const t = document.createElement('div');
  t.className = `eg-toast ${type}`;
  t.innerHTML = `<i class="bi ${icons[type] || icons.ok}"></i><span>${message}</span>`;
  document.getElementById('toast-container').appendChild(t);
  setTimeout(() => t.remove(), 4000);
}

/* Close sidebar when clicking outside on mobile */
document.addEventListener('click', function(e) {
  const sidebar = document.getElementById('sidebar');
  if (sidebar && sidebar.classList.contains('open') && !sidebar.contains(e.target)) {
    sidebar.classList.remove('open');
  }
});
</script>
</body>
</html>