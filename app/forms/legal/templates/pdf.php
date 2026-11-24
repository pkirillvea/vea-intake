<?php
/**
 * Legal Assistance Request PDF (docs/PLAN.md section 11), rendered by PdfBuilder
 * with dompdf. Letter portrait; the privilege banner and "Page X of Y" are drawn
 * on every page by PdfBuilder. Every value is escaped with T::e(); images are
 * data: URIs only.
 *
 * Rows come from LegalAnswers (the review page's rows and long-answer rule):
 * short answers label | value; long answers below the label at full width with
 * line breaks kept and long words wrapped.
 *
 * @var array $v {data, id, audit, logo, c, answers, files}
 */
declare(strict_types=1);

use VeaIntake\Core\Template as T;
use VeaIntake\Forms\Legal\LegalAnswers;

$c = $v['c'];
$P = $c['pdf'];
$A = $P['audit'];
$L = $P['attachments'];
$a = $v['audit'];
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title><?= T::e($P['title'] . ' ' . $v['id']) ?></title>
<style>
  @page { margin: 16mm 14mm 20mm 14mm; }
  body { font-family: "DejaVu Sans", sans-serif; font-size: 9pt; color: #333; line-height: 1.35; }
  .head { width: 100%; border-collapse: collapse; background: #025D8C; color: #fff; margin-bottom: 4pt; }
  .head td { padding: 8pt 10pt; vertical-align: middle; }
  .head .logo { width: 112pt; }
  .head .title { font-size: 14pt; }
  .head .sub { font-size: 9pt; margin-top: 2pt; }
  h2 { font-size: 10.5pt; color: #333; margin: 12pt 0 4pt; padding-bottom: 2pt; border-bottom: 1pt dotted #CCCCCC; }
  table.rows { width: 100%; border-collapse: collapse; }
  table.rows th, table.rows td { padding: 3.5pt 5pt; vertical-align: top; text-align: left; border-bottom: 0.5pt solid #D5DEE6; }
  table.rows th { width: 34%; font-weight: bold; color: #444; background: #E8F0F6; }
  table.rows td { background: #FBFBFB; word-wrap: break-word; }
  table.rows td.long { background: #FBFBFB; }
  .long .lbl { font-weight: bold; color: #444; margin-bottom: 2pt; }
  .long .val { white-space: pre-wrap; word-wrap: break-word; }
  table.audit th { width: 24%; background: #EFEFEF; }
  table.files { table-layout: fixed; }
  table.files th, table.files td { width: auto; word-wrap: break-word; }
  table.files .num { width: 5%; }
  table.files .name { width: 38%; }
  table.files .as { width: 35%; }
  table.files .size { width: 11%; }
  table.files .type { width: 11%; }
  .audit td { background: #F8F8F8; font-family: "DejaVu Sans Mono", monospace; font-size: 7.5pt; word-wrap: break-word; }
  .audit .file { margin: 0 0 3pt; }
  .muted { color: #555; }
  .nobreak { page-break-inside: avoid; }
</style>
</head>
<body>

<table class="head">
  <tr>
    <td class="logo"><img src="<?= T::e($v['logo']) ?>" width="105" height="30" alt=""></td>
    <td>
      <div class="title"><?= T::e($P['title']) ?></div>
      <div class="sub"><?= T::e($P['submissionId']) ?>: <?= T::e($v['id']) ?></div>
    </td>
  </tr>
</table>

<?php foreach ($v['answers'] as $section) : ?>
<h2><?= T::e($section['title']) ?></h2>
<table class="rows">
<?php foreach ($section['rows'] as $row) : ?>
<?php if ($row['long']) : ?>
  <tr><td class="long" colspan="2"><div class="lbl"><?= T::e($row['label']) ?></div><div class="val"><?= T::e(implode("\n", $row['lines'])) ?></div></td></tr>
<?php else : ?>
  <tr><th><?= T::e($row['label']) ?></th><td><?= implode('<br>', array_map([T::class, 'e'], $row['lines'])) ?></td></tr>
<?php endif; ?>
<?php endforeach; ?>
</table>
<?php endforeach; ?>

<h2><?= T::e($c['sections']['documents']) ?></h2>
<?php if ($v['files'] === []) : ?>
<p class="muted"><?= T::e($L['none']) ?></p>
<?php else : ?>
<table class="rows files">
  <tr><th class="num"><?= T::e($L['number']) ?></th><th class="name"><?= T::e($L['name']) ?></th><th class="as"><?= T::e($L['attachedAs']) ?></th><th class="size"><?= T::e($L['size']) ?></th><th class="type"><?= T::e($L['type']) ?></th></tr>
<?php foreach ($v['files'] as $i => $f) : ?>
  <tr><td class="num"><?= $i + 1 ?></td><td class="name"><?= T::e($f['name']) ?></td><td class="as"><?= T::e($f['attachedAs']) ?></td><td class="size"><?= T::e(LegalAnswers::size($f['size'], $c)) ?></td><td class="type"><?= T::e(strtoupper($f['extension'])) ?></td></tr>
<?php endforeach; ?>
</table>
<?php endif; ?>

<div class="nobreak">
<h2><?= T::e($A['title']) ?></h2>
<table class="rows audit">
  <tr><th><?= T::e($A['submissionId']) ?></th><td><?= T::e($a['id']) ?></td></tr>
  <tr><th><?= T::e($A['submitted']) ?></th><td><?= T::e($a['submitted']) ?></td></tr>
  <tr><th><?= T::e($A['submittedBy']) ?></th><td><?= T::e($a['submittedBy']) ?></td></tr>
  <tr><th><?= T::e($A['ip']) ?></th><td><?= T::e($a['ip']) ?></td></tr>
  <tr><th><?= T::e($A['location']) ?></th><td><?= T::e($a['location']) ?></td></tr>
  <tr><th><?= T::e($A['userAgent']) ?></th><td><?= T::e($a['userAgent']) ?></td></tr>
  <tr><th><?= T::e($A['formVersion']) ?></th><td><?= T::e($a['formVersion']) ?> / data <?= T::e($a['dataVersion']) ?></td></tr>
  <tr><th><?= T::e($A['dataSha256']) ?></th><td><?= T::e($a['sha256']) ?></td></tr>
  <tr><th><?= T::e($A['files']) ?></th><td>
<?php if ($a['files'] === []) : ?>
    &ndash;
<?php else : ?>
<?php foreach ($a['files'] as $i => $f) : ?>
    <div class="file"><?= $i + 1 ?>. <?= T::e($f['name']) ?><br><?= T::e(number_format($f['size'])) ?> bytes, <?= T::e($f['type']) ?><br><?= T::e($f['sha256']) ?></div>
<?php endforeach; ?>
<?php endif; ?>
  </td></tr>
</table>
</div>

</body>
</html>
