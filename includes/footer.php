<?php
// includes/footer.php
?>
<footer class="footer">
  <div>&copy; <?php echo date('Y'); ?> Librus.</div>
</footer>

<div class="modal-overlay" id="logout-modal">
  <div class="modal-dialog">
    <h2>Potwierdzenie</h2>
    <p>Czy na pewno chcesz się wylogować z systemu?</p>
    <div class="modal-actions">
      <button id="logout-cancel" class="btn">Anuluj</button>
      <a id="logout-confirm" href="#" class="btn primary">Wyloguj</a>
    </div>
  </div>
</div>

</body>
</html>