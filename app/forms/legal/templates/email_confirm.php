<?php
/**
 * Confirmation to the signed-in submitter (docs/PLAN.md section 12): member name,
 * local, date/time and reference only. No issue type, narrative or files; no
 * attachments. Every value escaped.
 *
 * @var array $v {id, date, member, local, testTo, c}
 */
declare(strict_types=1);

use VeaIntake\Core\Template as T;

$E = $v['c']['email'];
$C = $E['confirmation'];
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"></head>
<body style="font-family: Arial, Helvetica, sans-serif; font-size: 14px; color: #333;">
<?php if ($v['testTo'] !== null) : ?>
<p style="background: #FFF4CE; border-left: 4px solid #B45309; padding: 6px 10px;"><?= T::e(T::fmt($E['testNote'], ['to' => $v['testTo']])) ?></p>
<?php endif; ?>
<p><?= T::e(T::fmt($C['body'], ['member' => $v['member'], 'local' => $v['local'], 'date' => $v['date'], 'id' => $v['id']])) ?></p>
<p style="color: #555; font-size: 12px;"><?= T::e($C['noReply']) ?></p>
</body></html>
