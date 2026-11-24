<?php

declare(strict_types=1);

namespace VeaIntake\Forms\Legal;

use DateTimeImmutable;
use DateTimeZone;
use Throwable;
use VeaIntake\Core\AccessAuth;
use VeaIntake\Core\Audit;
use VeaIntake\Core\Deadline;
use VeaIntake\Core\FormLimits;
use VeaIntake\Core\Http;
use VeaIntake\Core\HttpError;
use VeaIntake\Core\Log;
use VeaIntake\Core\PdfBuilder;
use VeaIntake\Core\SubmissionId;
use VeaIntake\Core\Template;
use VeaIntake\Core\TmpfsGuard;
use VeaIntake\Core\UploadValidator;
use VeaIntake\Core\Validator;

/**
 * POST /legal/submit.php (docs/PLAN.md section 2; docs/PAYLOAD.md).
 *
 *  0 TmpfsGuard (503)          1 Access JWT (401; JWKS unavailable 503)
 *  2 read the multipart        3 honeypot: fake success, send nothing
 *  4 LegalValidator (400)      5 UploadValidator (400, field "files")
 *  6 ID + audit + PDF          7 staff email (fail: 502 send_failed, nothing else sent)
 *  8 confirmation to the submitter (fail or no time left: still success, logged)
 *  9 {ok: true, id}
 *
 * Logs only event names, the submission ID, field names, stage, reason codes,
 * exception class and HTTP status (Log allowlist): never values, names, emails,
 * file names or Graph responses.
 */
final class LegalSubmitHandler
{
    public const FORM = 'legal';
    public const CONFIRM_MIN_SECONDS = 8.0;

    private readonly \Closure $now;

    /**
     * @param \Closure(Deadline): LegalServices $wire built only after the tmpfs guard
     * @param \Closure(): DateTimeImmutable|null $now
     * @param \Closure(): float|null $clock wall clock for the request deadline
     * @param \Closure(string): bool|null $isUploaded is_uploaded_file (tests inject)
     */
    public function __construct(
        private readonly TmpfsGuard $guard,
        private readonly \Closure $wire,
        private readonly PdfBuilder $pdf,
        ?\Closure $now = null,
        private readonly ?\Closure $clock = null,
        private readonly ?\Closure $isUploaded = null,
    ) {
        $this->now = $now ?? static fn (): DateTimeImmutable => new DateTimeImmutable('now');
    }

    /**
     * @param array<string, mixed> $server $_SERVER
     * @param array<string, mixed> $post   $_POST
     * @param array<string, mixed> $files  $_FILES
     * @return array{0: int, 1: array<string, mixed>}
     */
    public function handle(array $server, array $post, array $files): array
    {
        $id = null;
        try {
            $this->guard->assertReady();
            $deadline = Deadline::fromRequest($server, Deadline::SUBMIT_BUDGET, $this->clock);
            /** @var LegalServices $s */
            $s = ($this->wire)($deadline);
            $who = $s->auth->verify($server);

            $in = Http::readMultipart($server, $post, $files);
            $tz = new DateTimeZone($s->config->displayTz());
            $now = ($this->now)()->setTimezone($tz);

            $website = $in['payload']['website'] ?? '';
            if (!is_string($website) || trim($website) !== '') {
                Log::event('honeypot', ['form' => self::FORM]);

                return [200, ['ok' => true, 'id' => SubmissionId::generate($now)]];
            }

            $c = Template::content(self::FORM);
            $limits = FormLimits::fromContent($c);
            $result = (new LegalValidator($s->formData, $c, $limits, $now))->validate($in['payload']);
            unset($in['payload']);
            if ($result['errors'] !== []) {
                $fields = Validator::fieldList($result['errors']);
                Log::event('invalid_input', ['form' => self::FORM, 'status' => 400, 'fields' => implode(',', $fields)]);
                throw new HttpError(400, HttpError::INVALID_INPUT, $fields);
            }
            $data = $result['data'];
            $uploads = UploadValidator::fromLimits($limits, $this->isUploaded)->validate($in['files']);

            $id = SubmissionId::generate($now);
            $audit = Audit::build($id, $who, $server, $data, $uploads, $now, $s->config->displayTz(), (string) $c['pdf']['audit']['notAvailable']);
            $notListed = $s->formData->notListed();
            try {
                $pdf = $this->pdf->render($data, $id, $audit, [
                    'answers' => LegalAnswers::sections($data, $c, $limits, $notListed),
                    'files' => $audit['files'],
                ]);
            } catch (Throwable $e) {
                Log::event('pdf_failed', ['id' => $id, 'class' => (new \ReflectionClass($e))->getShortName(), 'status' => 500]);
                throw new HttpError(500, HttpError::SERVER_ERROR);
            }

            $attachments = [['name' => PdfBuilder::filename((string) $c['pdf']['filenamePrefix'], $id, $data['lastName'], $data['firstName']), 'contentType' => 'application/pdf', 'bytes' => $pdf]];
            unset($pdf);
            foreach ($uploads as $f) {
                $attachments[] = ['name' => $f->safeName, 'contentType' => $f->mime, 'path' => $f->tmpPath];
            }

            $E = $c['email'];
            $member = $data['firstName'] . ' ' . $data['lastName'];
            $local = $data['local'] === $notListed ? $data['local'] . ': ' . $data['localOther'] : $data['local'];
            $staffTo = $s->config->mailTo(self::FORM);
            $test = $s->config->testMode();
            $handle = AccessAuth::handle($who->email, (array) ($c['handles']['overrides'] ?? []));

            $staff = $s->mailer->send(
                $staffTo,
                Template::fmt((string) $E['staff']['subject'], ['id' => $id, 'handle' => $handle]),
                Template::render(self::FORM, 'email_staff', [
                    'id' => $id, 'submitted' => $now->format((string) $E['dateFormat']), 'submittedBy' => $who->email,
                    'member' => $member, 'local' => $local, 'count' => count($uploads), 'testTo' => $test ? $staffTo : null, 'c' => $c,
                ]),
                $attachments,
            );
            unset($attachments);
            if (!$staff['ok']) {
                Log::event('staff_send_failed', ['id' => $id, 'stage' => $staff['stage'], 'status' => $staff['status']]);
                if ($staff['cleanup'] === false) {
                    Log::event('draft_cleanup_failed', ['id' => $id]);
                }
                throw new HttpError(502, HttpError::SEND_FAILED);
            }
            Log::event('staff_sent', ['id' => $id, 'stage' => $staff['path'], 'status' => $staff['status']]);
            if ($staff['path'] === 'large' && $staff['cleanup'] !== true) {
                Log::event('sent_copy_cleanup_failed', ['id' => $id]);
            }

            if ($deadline->remaining() < self::CONFIRM_MIN_SECONDS) {
                Log::event('confirm_skipped', ['id' => $id, 'reason' => 'deadline']);
            } else {
                $confirm = $s->mailer->send(
                    $who->email,
                    Template::fmt((string) $E['confirmation']['subject'], ['id' => $id]),
                    Template::render(self::FORM, 'email_confirm', [
                        'id' => $id, 'date' => $now->format((string) $E['dateFormat']), 'member' => $member, 'local' => $local,
                        'testTo' => $test ? $who->email : null, 'c' => $c,
                    ]),
                );
                Log::event($confirm['ok'] ? 'confirm_sent' : 'confirm_send_failed', ['id' => $id, 'stage' => $confirm['stage'], 'status' => $confirm['status']]);
            }

            Log::event('submit_ok', ['form' => self::FORM, 'id' => $id, 'status' => 200]);

            return [200, ['ok' => true, 'id' => $id]];
        } catch (HttpError $e) {
            return [$e->status, $e->body()];
        } catch (Throwable $e) {
            Log::event('server_error', ['form' => self::FORM, 'id' => $id, 'class' => (new \ReflectionClass($e))->getShortName(), 'status' => 500]);

            return [500, (new HttpError(500, HttpError::SERVER_ERROR))->body()];
        }
    }
}
