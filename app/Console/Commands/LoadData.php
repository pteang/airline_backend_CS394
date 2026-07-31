<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use MongoDB\BSON\UTCDateTime;

/**
 * Module 3 / Functions / Load Data.
 *
 * Bulk-loads a huge volume (0.5M–1M+) of records into the main transactional
 * tables (bookings, booking_passengers, payments, tickets) plus a supporting
 * passenger pool — for load / performance testing.
 *
 * Performance techniques (this is NOT the realistic demo seeder):
 *   - raw chunked DB::insert (no Eloquent, no per-row model events)
 *   - each chunk committed in a single transaction
 *   - SQLite fast pragmas (synchronous=OFF, journal_mode=MEMORY)
 *   - one shared bcrypt hash for pool users (never hash per row)
 *   - contiguous auto-increment ids tracked in PHP (no id look-ups per row)
 *   - pre-generated value pools sampled with mt_rand (no Faker in the hot loop)
 *
 *   php artisan data:load                 # default: 1,000,000 bookings
 *   php artisan data:load --bookings=500000 --passengers=50000 --fresh
 */
class LoadData extends Command
{
    protected $signature = 'data:load
        {--bookings=1000000 : Bookings to load into Postgres (main tables). 0 to skip.}
        {--passengers=50000 : Size of the passenger pool (users + profiles) to reference}
        {--chunk=4000 : Rows inserted per statement (stay under the driver bind limit)}
        {--paid-ratio=0.65 : Fraction of bookings that also get a payment + ticket}
        {--mongo=0 : Activity-log documents to load into MongoDB (logs store). 0 to skip.}
        {--redis=0 : Cache keys to load into Redis (cache store). 0 to skip.}
        {--redis-ttl=0 : TTL (seconds) for the Redis keys. 0 = no expiry.}
        {--fresh : Truncate bookings/booking_passengers/payments/tickets before loading}';

    protected $description = 'Bulk-load 0.5M–1M+ records into Postgres (main), MongoDB (logs), and Redis (cache) for load testing';

    private const PAYMENT_METHODS = ['credit_card', 'debit_card', 'paypal', 'bank_transfer', 'cash', 'aba_pay', 'acleda', 'wing'];

    private const SEAT_CLASSES = ['economy', 'economy', 'economy', 'business', 'first_class'];

    public function handle(): int
    {
        $chunk = max(100, (int) $this->option('chunk'));
        $bookings = (int) $this->option('bookings');
        $mongo = (int) $this->option('mongo');
        $redis = (int) $this->option('redis');

        if ($bookings <= 0 && $mongo <= 0 && $redis <= 0) {
            $this->warn('Nothing to do — set --bookings, --mongo, and/or --redis above 0.');

            return self::SUCCESS;
        }

        if ($bookings > 0 && ! $this->loadPostgres($bookings, max(1, (int) $this->option('passengers')), $chunk, (float) $this->option('paid-ratio'))) {
            return self::FAILURE;
        }
        if ($mongo > 0) {
            $this->loadMongo($mongo, max(1000, $chunk));
        }
        if ($redis > 0) {
            $this->loadRedis($redis, max(1000, $chunk), max(0, (int) $this->option('redis-ttl')));
        }

        return self::SUCCESS;
    }

    /** Bulk-load bookings + dependents into Postgres. Returns false if it can't run. */
    private function loadPostgres(int $target, int $poolSize, int $chunk, float $paidRatio): bool
    {
        $flightIds = DB::table('flights')->pluck('base_price', 'id')->all();
        if (empty($flightIds)) {
            $this->error('No flights found. Run `php artisan migrate:fresh --seed` first.');

            return false;
        }

        $this->tuneDatabase();

        if ($this->option('fresh')) {
            $this->info('Truncating main transactional tables…');
            foreach (['tickets', 'payments', 'booking_passengers', 'bookings'] as $t) {
                DB::table($t)->delete();
            }
        }

        [$userIds, $profileIds] = $this->ensurePassengerPool($poolSize, $chunk);

        $flightKeys = array_keys($flightIds);
        $started = microtime(true);

        // Track contiguous auto-increment ids in PHP to avoid per-chunk look-ups.
        $bookingId = (int) (DB::table('bookings')->max('id') ?? 0);
        $refSeq = (int) (DB::table('bookings')->where('booking_ref', 'like', 'LD%')->count());

        // Pre-generate a pool of booked_at timestamps (cheap sampling in the loop).
        $datePool = [];
        for ($i = 0; $i < 500; $i++) {
            $datePool[] = now()->subDays(mt_rand(0, 120))->subMinutes(mt_rand(0, 1440))->format('Y-m-d H:i:s');
        }
        $statusPool = ['confirmed', 'confirmed', 'confirmed', 'completed', 'pending', 'cancelled'];

        $this->info(sprintf('Loading %s bookings (+ passengers/payments/tickets)…', number_format($target)));
        $bar = $this->output->createProgressBar($target);
        $bar->start();

        $remaining = $target;
        while ($remaining > 0) {
            $n = min($chunk, $remaining);
            $firstId = $bookingId + 1;

            $bookings = [];
            $bookingPassengers = [];
            $payments = [];
            $tickets = [];

            for ($i = 0; $i < $n; $i++) {
                $id = $firstId + $i;
                $refSeq++;
                $flightId = $flightKeys[array_rand($flightKeys)];
                $status = $statusPool[array_rand($statusPool)];
                $bookedAt = $datePool[array_rand($datePool)];
                $seatClass = self::SEAT_CLASSES[array_rand(self::SEAT_CLASSES)];

                $bookings[] = [
                    // Explicit id so the actual row id always equals the id the
                    // child rows below reference — AUTOINCREMENT can otherwise run
                    // ahead of max(id) after deletes (leftover sqlite_sequence).
                    'id' => $id,
                    'booking_ref' => 'LD'.str_pad((string) $refSeq, 9, '0', STR_PAD_LEFT),
                    'passenger_id' => $userIds[array_rand($userIds)],
                    'flight_id' => $flightId,
                    'trip_type' => 'one_way',
                    'status' => $status,
                    'booked_at' => $bookedAt,
                ];
                $bookingPassengers[] = [
                    'booking_id' => $id,
                    'passenger_id' => $profileIds[array_rand($profileIds)],
                    'seat_class' => $seatClass,
                ];

                // Confirmed/completed bookings (up to the ratio) get paid + ticketed.
                if (($status === 'confirmed' || $status === 'completed') && mt_rand() / mt_getrandmax() <= $paidRatio) {
                    $amount = number_format((float) ($flightIds[$flightId] ?: mt_rand(89, 1200)), 2, '.', '');
                    $payments[] = [
                        'booking_id' => $id,
                        'amount' => $amount,
                        'payment_method' => self::PAYMENT_METHODS[array_rand(self::PAYMENT_METHODS)],
                        'payment_status' => 'paid',
                        'transaction_ref' => 'TXNLD'.str_pad((string) $refSeq, 9, '0', STR_PAD_LEFT),
                        'paid_at' => $bookedAt,
                    ];
                    $tickets[] = [
                        'booking_id' => $id,
                        'ticket_number' => 'TKTLD'.str_pad((string) $refSeq, 9, '0', STR_PAD_LEFT),
                        'issued_at' => $bookedAt,
                        'ticket_code' => strtoupper(substr(md5((string) $id), 0, 8)),
                    ];
                }
            }

            DB::transaction(function () use ($bookings, $bookingPassengers, $payments, $tickets, $chunk) {
                foreach (array_chunk($bookings, $chunk) as $c) {
                    DB::table('bookings')->insert($c);
                }
                foreach (array_chunk($bookingPassengers, $chunk) as $c) {
                    DB::table('booking_passengers')->insert($c);
                }
                foreach (array_chunk($payments, $chunk) as $c) {
                    DB::table('payments')->insert($c);
                }
                foreach (array_chunk($tickets, $chunk) as $c) {
                    DB::table('tickets')->insert($c);
                }
            });

            $bookingId += $n;
            $remaining -= $n;
            $bar->advance($n);
        }

        $bar->finish();
        $this->newLine(2);

        // We inserted explicit ids; on Postgres that leaves the identity
        // sequences behind, so resync them or the next auto-id insert collides.
        $this->resyncSequences();

        $elapsed = microtime(true) - $started;
        $this->reportTotals($target, $elapsed);

        return true;
    }

    /**
     * Bulk-load activity-log documents into MongoDB (the logs / documents store).
     * Each doc is tagged `bulk: true` so a run can be undone with
     * `db.activity_logs.deleteMany({ bulk: true })`.
     */
    private function loadMongo(int $count, int $batch): void
    {
        $conn = DB::connection('mongodb');
        $events = ['auth.passenger_login', 'auth.staff_login', 'booking.created', 'payment.paid', 'booking.cancelled', 'ticket.issued', 'flight.viewed', 'seat.selected'];
        $actorTypes = ['passenger', 'internal'];

        $this->info(sprintf('Loading %s MongoDB activity logs…', number_format($count)));
        $bar = $this->output->createProgressBar($count);
        $bar->start();
        $started = microtime(true);

        $made = 0;
        while ($made < $count) {
            $n = min($batch, $count - $made);
            $docs = [];
            for ($i = 0; $i < $n; $i++) {
                $tsMs = (int) ((time() - mt_rand(0, 120 * 86400)) * 1000);
                $docs[] = [
                    'event' => $events[array_rand($events)],
                    'actor_type' => $actorTypes[array_rand($actorTypes)],
                    'actor_id' => mt_rand(1, 50000),
                    'context' => [
                        'ip' => mt_rand(1, 223).'.'.mt_rand(0, 255).'.'.mt_rand(0, 255).'.'.mt_rand(1, 254),
                        'ref' => 'LD'.($made + $i),
                        'source' => 'bulk',
                    ],
                    'logged_at' => new UTCDateTime($tsMs),
                    'bulk' => true,
                ];
            }
            $conn->table('activity_logs')->insert($docs);
            $made += $n;
            $bar->advance($n);
        }

        $bar->finish();
        $this->newLine(2);
        $elapsed = microtime(true) - $started;
        $this->info(sprintf('Loaded %s activity logs in %.1fs (total in collection: %s).',
            number_format($count), $elapsed, number_format($conn->table('activity_logs')->count())));
    }

    /**
     * Bulk-load cache keys into Redis (the cache store), written in pipelines.
     * Keys are namespaced `bulk:cache:*` so a run can be undone with
     * `redis-cli --scan --pattern '*bulk:cache:*' | xargs redis-cli del`.
     */
    private function loadRedis(int $count, int $batch, int $ttl): void
    {
        $this->info(sprintf('Loading %s Redis cache keys%s…',
            number_format($count), $ttl > 0 ? " (ttl {$ttl}s)" : ''));
        $bar = $this->output->createProgressBar($count);
        $bar->start();
        $started = microtime(true);

        $made = 0;
        while ($made < $count) {
            $n = min($batch, $count - $made);
            Redis::pipeline(function ($pipe) use ($made, $n, $ttl) {
                for ($i = 0; $i < $n; $i++) {
                    $key = 'bulk:cache:'.($made + $i);
                    $val = json_encode(['id' => $made + $i, 'ts' => time(), 'val' => Str::random(16)]);
                    if ($ttl > 0) {
                        $pipe->setex($key, $ttl, $val);
                    } else {
                        $pipe->set($key, $val);
                    }
                }
            });
            $made += $n;
            $bar->advance($n);
        }

        $bar->finish();
        $this->newLine(2);
        $elapsed = microtime(true) - $started;
        $this->info(sprintf('Loaded %s Redis keys in %.1fs.', number_format($count), $elapsed));
    }

    /**
     * Realign auto-increment counters with the explicit ids we just inserted.
     *
     * Only Postgres needs this: inserting an explicit id does not advance its
     * identity sequence, so a later auto-id insert (e.g. a booking made through
     * the API) would reuse an id and hit a unique violation. SQLite's
     * AUTOINCREMENT and MySQL's AUTO_INCREMENT both self-adjust on explicit ids.
     */
    private function resyncSequences(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        // Tables the loader inserts explicit ids into. The children
        // (booking_passengers, payments, tickets) use auto ids, so their
        // sequences are already correct.
        foreach (['users', 'passenger_profiles', 'bookings'] as $table) {
            DB::statement(
                "SELECT setval(
                    pg_get_serial_sequence(?, 'id'),
                    COALESCE((SELECT MAX(id) FROM {$table}), 1),
                    EXISTS (SELECT 1 FROM {$table})
                )",
                [$table]
            );
        }
    }

    /** Ensure at least $poolSize passenger users + profiles exist; return their id pools. */
    private function ensurePassengerPool(int $poolSize, int $chunk): array
    {
        $have = (int) DB::table('users')->count();
        $need = $poolSize - $have;

        if ($need > 0) {
            $this->info(sprintf('Building passenger pool: creating %s users + profiles…', number_format($need)));
            $sharedHash = Hash::make('password'); // one bcrypt for all pool users
            $userBase = (int) (DB::table('users')->max('id') ?? 0);
            $bar = $this->output->createProgressBar($need);
            $bar->start();

            $made = 0;
            while ($made < $need) {
                $batch = min($chunk, $need - $made);
                $users = [];
                $profiles = [];
                for ($i = 0; $i < $batch; $i++) {
                    $seq = $userBase + $made + $i + 1;
                    $users[] = [
                        // Explicit id so profiles.user_id below (which uses $seq)
                        // always references a real user, independent of the
                        // users AUTOINCREMENT counter.
                        'id' => $seq,
                        'full_name' => 'Load User '.$seq,
                        'email' => 'loaduser'.$seq.'@load.test',
                        'password_hash' => $sharedHash,
                        'phone' => '+1-555-'.str_pad((string) ($seq % 100000), 5, '0', STR_PAD_LEFT),
                        'is_active' => true,
                    ];
                    $profiles[] = [
                        'id' => $seq,
                        'user_id' => $seq,
                        'passport_number' => 'LP'.str_pad((string) $seq, 8, '0', STR_PAD_LEFT),
                        'nationality' => 'US',
                        'date_of_birth' => '1990-01-01',
                    ];
                }
                DB::transaction(function () use ($users, $profiles) {
                    DB::table('users')->insert($users);
                    DB::table('passenger_profiles')->insert($profiles);
                });
                $made += $batch;
                $bar->advance($batch);
            }
            $bar->finish();
            $this->newLine(2);
        }

        // Sample a working pool of ids to reference (cap the in-memory arrays).
        $userIds = DB::table('users')->limit($poolSize)->pluck('id')->all();
        $profileIds = DB::table('passenger_profiles')->limit($poolSize)->pluck('id')->all();

        return [$userIds, $profileIds];
    }

    /** Apply fast bulk-load pragmas on SQLite (no-op on other drivers). */
    private function tuneDatabase(): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            DB::statement('PRAGMA synchronous = OFF');
            DB::statement('PRAGMA journal_mode = MEMORY');
            DB::statement('PRAGMA temp_store = MEMORY');
        }
        DB::connection()->disableQueryLog();
    }

    private function reportTotals(int $loaded, float $elapsed): void
    {
        $rate = $elapsed > 0 ? $loaded / $elapsed : 0;
        $this->info(sprintf('Loaded %s bookings in %.1fs (%s bookings/sec).',
            number_format($loaded), $elapsed, number_format($rate)));

        $rows = [];
        $totalMain = 0;
        foreach (['bookings', 'booking_passengers', 'payments', 'tickets', 'users', 'passenger_profiles'] as $t) {
            $c = (int) DB::table($t)->count();
            $rows[] = [$t, number_format($c)];
            if (in_array($t, ['bookings', 'booking_passengers', 'payments', 'tickets'], true)) {
                $totalMain += $c;
            }
        }
        $rows[] = ['— main-table total —', number_format($totalMain)];
        $this->table(['Table', 'Rows'], $rows);
    }
}
