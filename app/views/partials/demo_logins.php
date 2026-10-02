<?php
/* "Try the demo" box for a public demo, from config demo.show_logins.
   Each button is its own small login form, so it works without JavaScript.
   Only read-only accounts are listed, so a config slip can't publish a real
   login. Renders nothing outside a demo. */
$demoLogins = array_filter(config()['demo']['show_logins'] ?? [], fn($d) => is_demo_identifier((string)($d['id'] ?? '')));
if ($demoLogins): ?>
<section class="demo-box" aria-labelledby="demo-title">
  <div class="demo-head">
    <?php echo icon('info'); ?>
    <div>
      <h2 id="demo-title">Try the demo</h2>
      <p>Everything here is fictional. Demo accounts can see every page, but nothing they do is saved.</p>
    </div>
  </div>
  <div class="demo-logins">
    <?php foreach ($demoLogins as $d): ?>
      <form class="demo-login" method="post" action="/login.php">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="identifier" value="<?php echo e($d['id']); ?>">
        <input type="hidden" name="secret" value="<?php echo e($d['secret']); ?>">
        <button class="btn btn-ghost btn-block" type="submit">Log in as <?php echo e($d['label']); ?><?php echo icon('arrow-right'); ?></button>
        <span class="demo-creds mono"><?php echo e($d['id']); ?> · <?php echo e($d['secret']); ?></span>
      </form>
    <?php endforeach; ?>
  </div>
</section>
<?php endif; ?>
