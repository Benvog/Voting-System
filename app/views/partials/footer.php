<?php declare(strict_types=1); ?>
<?php if (($layout ?? 'public') === 'admin'): ?>
    </main>
  </div>
</div>
<?php else: ?>
</main>
<footer class="foot"><?php echo e(config()['app']['name']); ?> &copy; <?php echo date('Y'); ?> · Online elections with one vote per position</footer>
<?php endif; ?>

<dialog class="confirm" id="confirm-dialog" aria-labelledby="confirm-title">
  <form method="dialog">
    <h2 id="confirm-title">Are you sure?</h2>
    <p id="confirm-message"></p>
    <div class="confirm-actions">
      <button class="btn btn-ghost" value="cancel">Cancel</button>
      <button class="btn btn-danger" value="ok" id="confirm-ok">Confirm</button>
    </div>
  </form>
</dialog>
</body>
</html>
