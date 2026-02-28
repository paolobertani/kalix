<h1>|persons_title|</h1>
<?php if (!$exists): ?>
<p>|person_not_found|</p>
<?php else: ?>
<p>ID: {{id}}</p>
<pre><?= htmlspecialchars((string)json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></pre>
<?php endif; ?>
