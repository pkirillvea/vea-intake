<?php

declare(strict_types=1);

namespace VeaIntake\Tests;

use PHPUnit\Framework\TestCase;
use VeaIntake\Core\Template;

/** Template helpers and the Legal form's content.json (docs/PLAN.md section 10, v1.4). */
final class TemplateContentTest extends TestCase
{
    public function testEscapeAndFormat(): void
    {
        self::assertSame('&lt;b&gt; &amp; &quot;x&quot; &apos;', Template::e('<b> & "x" \''));
        self::assertSame('Page 2 of {pages}', Template::fmt('Page {page} of {pages}', ['page' => 2]));
    }

    public function testRejectsBadFormNames(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Template::content('../app');
    }

    public function testLegalContent(): void
    {
        $c = Template::content('legal');
        self::assertMatchesRegularExpression('/^\d{4}\.\d{2}-\d+$/', Template::formVersion('legal'));
        self::assertSame('VEA Legal Assistance Request Form', $c['page']['title']);
        self::assertSame([
            'Disability Retirement',
            'Dismissal',
            'Child Protective Services Allegations',
            'Criminal Allegations',
            'Reasonable Accommodations under the Americans with Disabilities Act',
            'Salary/Pay',
            'Collective Bargaining Agreement Issues',
            'Working Conditions',
            'Leave (including those related to the Family and Medical Leave Act)',
            'Other',
        ], $c['issueTypes']);
        // PLAN 1.5: exact banner text, also used for the PDF; no placeholders left
        $banner = 'PRIVILEGED & CONFIDENTIAL – PREPARED AT THE DIRECTION OF COUNSEL';
        self::assertSame($banner, $c['banner']['privilege']);
        self::assertSame($banner, $c['pdf']['banner']);
        self::assertStringNotContainsString('TBD', (string) file_get_contents(Template::FORMS_DIR . '/legal/content.json'));
        // PLAN 1.5: submitter handle override map lives in the repo
        self::assertSame(['choward1' => 'choward'], $c['handles']['overrides']);
        self::assertSame('Other', $c['fields']['issueType']['otherValue']);
        self::assertSame(end($c['issueTypes']), $c['fields']['issueType']['otherValue']);
        self::assertSame('Enter all 10 digits, including leading zeros (e.g., 0001234567).', $c['fields']['membershipNumber']['help']);
        self::assertSame('Virginia', $c['fields']['state']['default']);
    }

    public function testNoSignatureAnywhereInTheForm(): void
    {
        $json = strtolower((string) file_get_contents(Template::FORMS_DIR . '/legal/content.json'));
        self::assertStringNotContainsString('signature', $json, 'Legal dropped the signature (PLAN 1.4)');
    }
}
