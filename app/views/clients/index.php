<div class="page-head">
  <div><p class="eyebrow">Who you recruit for</p><h1>Clients</h1></div>
  <a class="btn" href="/clients/new">New client</a>
</div>
<?php if (!$clients): ?>
  <div class="card empty">No clients yet. <a href="/clients/new">Add your first client</a>, then create roles for them.</div>
<?php else: ?>
  <div class="table-wrap">
    <table class="list">
      <thead><tr><th>Client</th><th>Main contact</th><th>Open roles</th><th>All roles</th><th>Fee</th></tr></thead>
      <tbody>
      <?php foreach ($clients as $c): ?>
        <tr>
          <td style="display:flex;align-items:center;gap:10px"><?= client_logo($c, 'sm') ?><a href="/clients/<?= $c['id'] ?>"><b><?= e(\Eshcol\Ats\Services\Engagement::clientLabel($c)) ?></b></a><?= $c['is_active'] ? '' : ' <span class="badge cancelled">inactive</span>' ?></td>
          <td><?= e($c['contact'] ?: '–') ?></td>
          <td><?= (int) $c['roles_open'] ?></td>
          <td><?= (int) $c['roles_total'] ?></td>
          <td class="nowrap"><?= $c['fee_type'] === 'percent' ? e(rtrim(rtrim((string) $c['fee_value'], '0'), '.')) . '% of CTC' : ($c['fee_type'] === 'fixed' ? '₹' . e(number_format((float) $c['fee_value'])) . ' fixed' : '–') ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php endif; ?>
