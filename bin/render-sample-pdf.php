<?php

/**
 * Render a sample Legal Assistance Request PDF from FAKE data, for Legal's
 * layout review (docs/PLAN.md section 14). Nothing real: invented member, fake
 * file list, fake audit values. The data goes through LegalValidator first, so
 * the sample is exactly what a valid submission produces.
 *
 *   php bin/render-sample-pdf.php [--out=DIR]     (default: scratch/, gitignored)
 *
 * Exercises: a narrative with blank lines and very long words, a long requested
 * outcome, "OTHER / NOT LISTED" local and worksite, issue type "Other", a long
 * job title (over the inline limit), 5 files with long names.
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use VeaIntake\Core\AccessIdentity;
use VeaIntake\Core\Audit;
use VeaIntake\Core\FormData;
use VeaIntake\Core\FormLimits;
use VeaIntake\Core\PdfBuilder;
use VeaIntake\Core\SubmissionId;
use VeaIntake\Core\Template;
use VeaIntake\Core\UploadedFile;
use VeaIntake\Forms\Legal\LegalAnswers;
use VeaIntake\Forms\Legal\LegalValidator;

$opt = getopt('', ['out:']);
$root = dirname(__DIR__);
$out = rtrim((string) ($opt['out'] ?? "$root/scratch"), '/');
if (!is_dir($out) && !mkdir($out, 0750, true)) {
    fwrite(STDERR, "render-sample-pdf: cannot create $out\n");
    exit(2);
}

$tz = new DateTimeZone('America/New_York');
$now = new DateTimeImmutable('now', $tz);
$c = Template::content('legal');
$limits = FormLimits::fromContent($c);
$fd = FormData::load();
$nl = $fd->notListed();

$narrative = "On October 14 the principal called me into a meeting with no notice and no union representative present. "
    . "I was told that a parent had complained about a classroom incident and that I was being placed on administrative leave effective immediately.\n\n"
    . "I asked for the complaint in writing and was refused. Two days later I received a letter (attached) stating that the matter had been referred to Child Protective Services.\n\n"
    . "Reference numbers from the district portal: CASE-2026-000000000000000000000000000000000000000000000000000000000001 and "
    . "https://portal.example.invalid/cases/aVeryLongIdentifierWithoutAnySpacesThatMustWrapInsideTheBoxInsteadOfRunningOffThePage\n\n"
    . "I have not been interviewed since. My pay has continued but I have been told not to contact colleagues.";

$payload = [
    'formVersion' => Template::formVersion('legal'),
    'dataVersion' => $fd->version(),
    'firstName' => 'Sample',
    'lastName' => 'Fakeperson',
    'membershipNumber' => '0001234567',
    'street' => '123 Example Avenue, Apartment 4B',
    'city' => 'Richmond',
    'state' => 'Virginia',
    'zip' => '23219-0000',
    'phone' => '8045550100',
    'email' => 'sample.fakeperson@example.org',
    'dateOfBirth' => '1985-06-15',
    'local' => $nl,
    'localOther' => 'Example County Education Association (fake)',
    'worksite' => $nl,
    'worksiteOther' => 'Example Regional Alternative Learning Center',
    'jobTitle' => 'Special Education Teacher and Department Chair',
    'timeInPosition' => '6 years',
    'issueType' => $c['fields']['issueType']['otherValue'],
    'issueTypeOther' => 'Administrative leave pending an outside investigation',
    'incidentDate' => $now->modify('-30 days')->format('Y-m-d'),
    'narrative' => $narrative,
    'requestedOutcome' => 'Representation at any meeting with the district, advice on my rights during the investigation, and help getting the complaint in writing.',
    'website' => '',
];

$result = (new LegalValidator($fd, $c, $limits, $now))->validate($payload);
if ($result['errors'] !== []) {
    fwrite(STDERR, 'render-sample-pdf: fixture invalid: ' . implode(', ', array_keys($result['errors'])) . "\n");
    exit(1);
}
$data = $result['data'];

$fake = static fn (string $name, int $size, string $ext, string $mime, int $i): UploadedFile => new UploadedFile(
    $name, VeaIntake\Core\UploadValidator::safeName($name, $i), $size, $ext, $mime, hash('sha256', "fake-$i-$name"), '/nonexistent');
$files = [
    $fake('Administrative leave letter 2026-10-16.pdf', 245_812, 'pdf', 'application/pdf', 1),
    $fake('Email thread with principal (printed).pdf', 1_934_220, 'pdf', 'application/pdf', 2),
    $fake('Timeline of events – draft v3 (with notes from my representative).docx', 48_310, 'docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 3),
    $fake('Pay stubs Sept-Oct.xlsx', 18_004, 'xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 4),
    $fake('Photo of posted notice.jpg', 3_812_455, 'jpg', 'image/jpeg', 5),
];

$id = SubmissionId::generate($now);
$server = [
    'REMOTE_ADDR' => '198.51.100.23',   // documentation range (RFC 5737)
    'HTTP_USER_AGENT' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0 Safari/537.36',
    'HTTP_CF_IPCITY' => 'Richmond', 'HTTP_CF_REGION' => 'Virginia', 'HTTP_CF_IPCOUNTRY' => 'US',
];
$audit = Audit::build($id, new AccessIdentity('uniserv.director@example.org'), $server, $data, $files, $now, 'America/New_York', $c['pdf']['audit']['notAvailable']);

$tmp = "$out/.pdf-tmp-" . bin2hex(random_bytes(4));
mkdir($tmp, 0700);
try {
    $pdf = (new PdfBuilder('legal', $tmp))->render($data, $id, $audit, [
        'answers' => LegalAnswers::sections($data, $c, $limits, $nl),
        'files' => $audit['files'],
    ]);
} finally {
    @rmdir($tmp);
}
$file = $out . '/' . PdfBuilder::filename((string) $c['pdf']['filenamePrefix'], $id, $data['lastName'], $data['firstName']);
file_put_contents($file, $pdf);
printf("render-sample-pdf: %s (%d bytes)\n", $file, strlen($pdf));
