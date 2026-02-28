<h1>|persons_title|</h1>
<p>Total: {{count}}</p>
<?php if ($count === 0): ?>
<p>|persons_empty|</p>
<?php else: ?>
<ul>
<?php foreach ($items as $item): ?>
<li><?= htmlspecialchars((string)($item['name'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></li>
<?php endforeach; ?>
</ul>
<?php endif; ?>
