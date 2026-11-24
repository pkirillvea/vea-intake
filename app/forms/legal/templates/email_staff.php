<?php
/**
 * Staff email to VEALegal (docs/PLAN.md section 12): privilege banner, submission
 * date, submitted by, member name, local, attachment note. No issue type and no
 * narrative: the full request is the attached PDF. Every value escaped.
 *
 * @var array $v {id, submitted, submittedBy, member, local, count, testTo, c}
 */
declare(strict_types=1);

use VeaIntake\Core\Template as T;

$E = $v['c']['email'];
$S = $E['staff'];
$rows = [
    $S['submitted'] => $v['submitted'],
    $S['submittedBy'] => $v['submittedBy'],
    $S['member'] => $v['member'],
    $S['local'] => $v['local'],
];
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"></head>
<body style="font-family: Arial, Helvetica, sans-serif; font-size: 14px; color: #333;">
<?php if ($v['testTo'] !== null) : ?>
<p style="background: #FFF4CE; border-left: 4px solid #B45309; padding: 6px 10px;"><?= T::e(T::fmt($E['testNote'], ['to' => $v['testTo']])) ?></p>
<?php endif; ?>
<p style="background: #E8F0F6; color: #023E5E; font-weight: bold; padding: 6px 10px; text-align: center;"><?= T::e($v['c']['banner']['privilege']) ?></p>
<p style="font-weight: bold;">#<?= T::e($v['id']) ?></p>
<table cellpadding="4" cellspacing="0" style="border-collapse: collapse;">
<?php foreach ($rows as $label => $value) : ?>
  <tr><td style="font-weight: bold; padding-right: 16px;"><?= T::e($label) ?></td><td><?= T::e($value) ?></td></tr>
<?php endforeach; ?>
</table>
<p><?= T::e(T::fmt($S['attachments'], ['count' => $v['count']])) ?></p>
</body></html>
