<?php

/**
 * SPDX-FileCopyrightText: 2026 Simon Grant
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Tests\Feature\Fork;

use App\Badges\BadgePrinter;
use App\Badges\BadgePrintException;
use App\Badges\Bitmap;
use App\Badges\Drivers\BrotherQlRaster;
use App\Badges\Jobs\PrintBadge;
use App\Badges\LabelFormat;
use App\Badges\PrinterTransport;
use App\Filament\Resources\BadgePrinters\Pages\ListBadgePrinters;
use App\Kiosk\Kiosk;
use App\Kiosk\KioskDevices;
use App\Kiosk\Livewire\KioskScreen;
use App\Models\Site;
use App\Models\User;
use App\Models\Visit;
use App\Models\Visitor;
use App\PublicVisit\Channel;
use App\PublicVisit\Commands\ArrivalRequest;
use App\PublicVisit\Commands\RegisterArrival;
use App\QrCheckIn\Livewire\MobileSignIn;
use App\QrCheckIn\SiteQrLinks;
use App\Settings\SettingsRepository;
use App\Support\VisitorPortalPermissions;
use App\VisitorWorkflow\Models\VisitorType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Laravel\Fortify\Actions\EnableTwoFactorAuthentication;
use Livewire\Livewire;
use Tests\TestCase;

class BadgePrintingTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    private Kiosk $kiosk;

    private string $secret;

    private VisitorType $type;

    private BadgePrinter $printer;

    /** @var list<array{printer: string, bytes: string}> */
    private array $sent = [];

    private ?string $failWith = null;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        Mail::fake();
        $this->site = Site::factory()->create(['name' => 'North Campus']);
        $reception = User::factory()->create(['site_id' => $this->site->getKey(), 'is_active' => true]);
        app(SettingsRepository::class)->saveSite($this->site, ['organisation.reception_host_user_id' => $reception->getKey()], null);
        $this->type = VisitorType::query()->create(['name' => 'Contractor', 'slug' => 'contractor', 'host_requirement' => VisitorType::HOST_NONE]);

        $this->printer = BadgePrinter::query()->create(['site_id' => $this->site->getKey(), 'name' => 'Front desk', 'host' => '10.20.30.40']);
        $this->kiosk = Kiosk::query()->create(['site_id' => $this->site->getKey(), 'name' => 'Front', 'badge_printer_id' => $this->printer->getKey()]);
        [, $this->secret] = app(KioskDevices::class)->enrol(app(KioskDevices::class)->issueEnrolmentCode($this->kiosk));

        // Record what would go to the printer instead of opening a socket.
        $this->app->instance(PrinterTransport::class, new class($this) implements PrinterTransport
        {
            public function __construct(private readonly BadgePrintingTest $test) {}

            public function send(BadgePrinter $printer, string $bytes): void
            {
                $this->test->recordSend($printer, $bytes);
            }
        });

        Http::fake(['*/forms/chromium/screenshot/html' => Http::response(self::png(696, 1109), 200)]);
    }

    public function recordSend(BadgePrinter $printer, string $bytes): void
    {
        if ($this->failWith !== null) {
            throw new BadgePrintException($this->failWith);
        }

        $this->sent[] = ['printer' => $printer->host, 'bytes' => $bytes];
    }

    /** White 8-bit RGB PNG with a black square in the top-left corner. */
    private static function png(int $width, int $height): string
    {
        $raw = '';

        for ($y = 0; $y < $height; $y++) {
            $raw .= "\x00";

            for ($x = 0; $x < $width; $x++) {
                $raw .= ($x < 10 && $y < 10) ? "\x00\x00\x00" : "\xFF\xFF\xFF";
            }
        }

        $chunk = fn (string $type, string $data): string => pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));

        return "\x89PNG\r\n\x1a\n".$chunk('IHDR', pack('NNCCCCC', $width, $height, 8, 2, 0, 0, 0)).$chunk('IDAT', gzcompress($raw)).$chunk('IEND', '');
    }

    private function arrive(Channel $channel = Channel::Kiosk, ?int $kioskId = null, ?VisitorType $type = null)
    {
        return app(RegisterArrival::class)->handle(new ArrivalRequest(
            site: $this->site,
            channel: $channel,
            submissionKey: (string) Str::uuid(),
            visitorType: $type ?? $this->type,
            details: ['first_name' => 'Casey', 'name' => 'Contractor', 'email' => 'casey@spark.example', 'phone' => '0412 345 678', 'company' => 'Spark Electrical'],
            hostToken: null,
            answers: [],
            acceptedPolicyVersionIds: [],
            kioskId: $kioskId,
        ));
    }

    public function test_the_brother_raster_job_has_the_expected_layout(): void
    {
        $format = LabelFormat::find('62x100');
        $rows = array_fill(0, $format->lengthDots, str_repeat("\x00", $format->widthDots));
        $rows[0] = "\x01".substr($rows[0], 1); // top-left printable dot

        $bytes = (new BrotherQlRaster)->encode(new Bitmap($format->widthDots, $format->lengthDots, $rows), $format);

        // invalidate, ESC @, ESC i a, ESC i z, ESC i M, ESC i A, ESC i K, ESC i d, M
        $header = 400 + 2 + 4 + 13 + 4 + 4 + 4 + 5 + 2;
        $this->assertSame($header + $format->lengthDots * 93 + 1, strlen($bytes));
        $this->assertSame(str_repeat("\x00", 400)."\x1B\x40\x1B\x69\x61\x01", substr($bytes, 0, 406));
        // Media: die-cut, 62 mm wide, 100 mm long, 1109 raster lines.
        $this->assertSame("\x1B\x69\x7A\xCE\x0B\x3E\x64".pack('V', 1109)."\x00\x00", substr($bytes, 406, 13));
        $this->assertSame("\x1A", substr($bytes, -1));

        // First raster line: the dot sits at head position 707 after the 12-dot margin and the
        // mirror, i.e. byte 88, bit 3.
        $line = substr($bytes, $header, 93);
        $this->assertSame("\x67\x00\x5A", substr($line, 0, 3));
        $this->assertSame(str_repeat("\x00", 88)."\x10".str_repeat("\x00", 1), substr($line, 3));
    }

    public function test_png_decoding_and_dithering(): void
    {
        $bitmap = Bitmap::fromPng(self::png(30, 20));

        $this->assertSame([30, 20], [$bitmap->width, $bitmap->height]);
        $this->assertSame("\x01", $bitmap->rows[0][0]);
        $this->assertSame("\x00", $bitmap->rows[19][29]);
        $this->assertStringStartsWith("\x89PNG", $bitmap->toPng());
    }

    public function test_a_kiosk_sign_in_queues_a_badge_on_the_kiosk_printer(): void
    {
        Bus::fake([PrintBadge::class]);

        Livewire::withCookie(KioskDevices::COOKIE, $this->secret)->test(KioskScreen::class)
            ->call('chooseType', 'contractor')->call('lookupReturning')
            ->set('details.first_name', 'Casey')->set('details.name', 'Contractor')
            ->call('submitDetails')->call('finish')->assertSet('step', 'done')
            ->assertSet('doneBadge', true)
            ->assertSee(__('Ihr Ausweis wird gedruckt. Bitte nehmen Sie ihn am Drucker mit.'));

        Bus::assertDispatched(PrintBadge::class, fn (PrintBadge $job): bool => $job->printerId === $this->printer->getKey());
    }

    public function test_qr_sign_ins_use_the_site_default_printer_and_types_can_opt_out(): void
    {
        Bus::fake([PrintBadge::class]);
        $link = app(SiteQrLinks::class)->forSite($this->site);

        // No default printer yet: nothing prints, nothing is promised.
        Livewire::test(MobileSignIn::class, ['token' => $link->token])
            ->call('chooseType', 'contractor')->call('lookupReturning')
            ->set('details.first_name', 'Pat')->set('details.name', 'Phone')
            ->call('submitDetails')->call('finish')->assertSet('doneBadge', false);
        Bus::assertNotDispatched(PrintBadge::class);

        $reception = BadgePrinter::query()->create(['site_id' => $this->site->getKey(), 'name' => 'Reception', 'host' => '10.20.30.41', 'is_site_default' => true]);

        Livewire::test(MobileSignIn::class, ['token' => $link->token])
            ->call('chooseType', 'contractor')->call('lookupReturning')
            ->set('details.first_name', 'Pat')->set('details.name', 'Phone')
            ->call('submitDetails')->call('finish')
            ->assertSee(__('Bitte holen Sie Ihren Ausweis am Empfang ab.'));
        Bus::assertDispatched(PrintBadge::class, fn (PrintBadge $job): bool => $job->printerId === $reception->getKey());

        $delivery = VisitorType::query()->create(['name' => 'Delivery', 'slug' => 'delivery', 'host_requirement' => VisitorType::HOST_NONE, 'print_badge' => false]);
        $this->assertFalse($this->arrive(type: $delivery, kioskId: $this->kiosk->getKey())->badgeQueued);

        // A printer of another site is never used.
        $other = Site::factory()->create();
        $foreign = BadgePrinter::query()->create(['site_id' => $other->getKey(), 'name' => 'Elsewhere', 'host' => '10.9.9.9']);
        $this->kiosk->update(['badge_printer_id' => $foreign->getKey()]);
        $this->arrive(kioskId: $this->kiosk->getKey());
        Bus::assertDispatched(PrintBadge::class, fn (PrintBadge $job): bool => $job->printerId === $reception->getKey());
        Bus::assertNotDispatched(PrintBadge::class, fn (PrintBadge $job): bool => $job->printerId === $foreign->getKey());
    }

    public function test_the_job_prints_the_badge_without_contact_details(): void
    {
        $result = $this->arrive(kioskId: $this->kiosk->getKey());
        $this->assertTrue($result->badgeQueued);

        // Queue is synchronous in tests: the job ran during sign-in.
        $this->assertCount(1, $this->sent);
        $this->assertSame('10.20.30.40', $this->sent[0]['printer']);
        $this->assertStringStartsWith(str_repeat("\x00", 400)."\x1B\x40", $this->sent[0]['bytes']);

        Http::assertSent(function (Request $request): bool {
            $html = collect($request->data())->firstWhere('name', 'files')['contents'] ?? '';

            return str_contains($html, 'Casey') && str_contains($html, 'Spark Electrical') && str_contains($html, 'North Campus')
                && ! str_contains($html, 'casey@spark.example') && ! str_contains($html, '0412');
        });

        $this->assertNotNull(DB::table('visit_visitor')->where('visit_id', $result->visitId)->value('badge_printed_at'));
        $this->assertNotNull($this->printer->fresh()->last_printed_at);
        $this->assertDatabaseHas('audit_events', ['action' => 'badge.printed']);
    }

    public function test_a_printer_failure_never_affects_the_sign_in(): void
    {
        $this->failWith = 'Printer 10.20.30.40:9100 is not reachable (Connection refused).';

        $result = $this->arrive(kioskId: $this->kiosk->getKey());

        $this->assertNotNull(DB::table('visit_visitor')->where('visit_id', $result->visitId)->value('checked_in_at'));
        $this->assertSame([], $this->sent);
        $this->assertSame('failing', $this->printer->fresh()->status());
        $this->assertStringContainsString('Connection refused', $this->printer->fresh()->last_error);
        $this->assertDatabaseHas('audit_events', ['action' => 'badge.print_failed', 'result' => 'failure']);
    }

    public function test_admin_manages_printers_and_test_prints(): void
    {
        VisitorPortalPermissions::sync();
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        Livewire::actingAs($admin)->test(ListBadgePrinters::class)
            ->assertCanSeeTableRecords([$this->printer])
            ->callTableAction('testPrint', $this->printer)
            ->assertNotified(__('Testausweis an „:printer“ gesendet.', ['printer' => 'Front desk']));

        $this->assertCount(1, $this->sent);
        $this->assertDatabaseHas('audit_events', ['action' => 'badge.test_printed']);

        $this->failWith = 'Printer offline';
        Livewire::actingAs($admin)->test(ListBadgePrinters::class)
            ->callTableAction('testPrint', $this->printer)
            ->assertNotified(__('Testdruck fehlgeschlagen'));
        $this->assertSame('Printer offline', $this->printer->fresh()->last_error);

        $staff = User::factory()->create();
        Livewire::actingAs($staff)->test(ListBadgePrinters::class)->assertForbidden();
    }

    public function test_one_default_printer_per_site(): void
    {
        $a = BadgePrinter::query()->create(['site_id' => $this->site->getKey(), 'name' => 'A', 'host' => '10.0.0.1', 'is_site_default' => true]);
        $b = BadgePrinter::query()->create(['site_id' => $this->site->getKey(), 'name' => 'B', 'host' => '10.0.0.2', 'is_site_default' => true]);

        $this->assertFalse($a->fresh()->is_site_default);
        $this->assertTrue($b->fresh()->is_site_default);
    }

    public function test_reception_can_reprint_on_the_label_printer(): void
    {
        VisitorPortalPermissions::sync();
        $result = $this->arrive(kioskId: $this->kiosk->getKey());
        $this->printer->update(['is_site_default' => true]);
        $visit = Visit::query()->find($result->visitId);
        $visitor = Visitor::query()->find($result->visitorId);
        $url = route('reception.participants.label', [$visit, $visitor]);

        $this->post($url)->assertRedirect();

        $staff = User::factory()->create(['site_id' => $this->site->getKey()]);
        $this->actingAs($staff)->post($url)->assertForbidden();

        $admin = User::factory()->create(['site_id' => $this->site->getKey()]);
        $admin->assignRole('admin');
        app(EnableTwoFactorAuthentication::class)($admin);
        $admin->forceFill(['two_factor_confirmed_at' => now()])->save();
        $this->actingAs($admin->refresh())
            ->withSession(['auth.app_mfa_satisfied_at' => time(), 'auth.app_mfa_satisfied_for_auth_method' => 'local'])
            ->from('/reception')->post($url)->assertRedirect('/reception')->assertSessionHas('success');

        $this->assertCount(2, $this->sent, 'sign-in plus reprint');
        $this->assertDatabaseHas('audit_events', ['action' => 'badge.reprint_requested']);
    }
}
