<?php

namespace Maxcxam\Larsadmin\Realtime;

use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Maxcxam\Larsadmin\Larsadmin;
use Maxcxam\Larsadmin\Notifications\Inbox;
use Throwable;

/**
 * Realtime signals through the host's Reverb or Pusher connection (ADR-027). The panel opens its own
 * connection, on panel pages only, and subscribes only to "private-{prefix}inbox.{token}", which only the
 * panel's own "broadcasting/auth" endpoint authorizes.
 *
 * @internal Not part of the public API.
 */
final class Realtime
{
    /** The broadcast drivers the panel's client can talk to. */
    public const array DRIVERS = ['reverb', 'pusher'];

    /** The channel name pattern of a user's inbox, after the prefix. */
    public const string INBOX = 'inbox.{token}';

    private static bool $warned = false;

    /**
     * Whether realtime is on: notifications are enabled, "realtime" is 'auto' or true, and the default
     * broadcaster is Reverb or Pusher with a key. Forcing it (true) with another driver logs a warning once.
     */
    public static function enabled(): bool
    {
        if (! Larsadmin::notificationsEnabled()) {
            return false;
        }

        $setting = self::setting();

        if ($setting === false) {
            return false;
        }

        $connection = self::connection();

        if ($connection === null) {
            if ($setting === true && ! self::$warned) {
                self::$warned = true;
                Log::warning('Larsadmin realtime is forced on ("larsadmin.notifications.realtime" = true), but the default broadcaster is not a Reverb or Pusher connection with a key: realtime stays off, the bell polls.');
            }

            return false;
        }

        return true;
    }

    /**
     * The normalized "realtime" setting: 'auto', true or false (unknown values disable it).
     */
    public static function setting(): string|bool
    {
        $value = config('larsadmin.notifications.realtime', 'auto');

        if ($value === null || $value === 'auto') {
            return 'auto';
        }

        return is_bool($value) ? $value : (filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false);
    }

    /**
     * The default broadcast connection's config when it is Reverb or Pusher with a key.
     *
     * @return array<string, mixed>|null
     */
    public static function connection(): ?array
    {
        $name = config('broadcasting.default');
        $connection = is_string($name) ? config("broadcasting.connections.{$name}") : null;

        if (! is_array($connection) || ! in_array($connection['driver'] ?? null, self::DRIVERS, true)) {
            return null;
        }

        $key = $connection['key'] ?? null;

        return is_string($key) && $key !== '' ? $connection : null;
    }

    /**
     * The validated channel prefix ("larsadmin.notifications.channel_prefix", default "larsa_").
     *
     * @throws InvalidArgumentException when it does not match ^[a-z][a-z0-9_]{0,30}_$
     */
    public static function prefix(): string
    {
        $prefix = config('larsadmin.notifications.channel_prefix', 'larsa_');

        if (! is_string($prefix) || preg_match('/\A[a-z][a-z0-9_]{0,30}_\z/', $prefix) !== 1) {
            throw new InvalidArgumentException('The config "larsadmin.notifications.channel_prefix" must match ^[a-z][a-z0-9_]{0,30}_$ (e.g. "larsa_").');
        }

        return $prefix;
    }

    /**
     * The user's inbox channel, without Echo's "private-" prefix.
     */
    public static function channelFor(Authenticatable $user): string
    {
        return self::prefix().'inbox.'.Inbox::channelToken($user);
    }

    /**
     * The inbox channel of a user given by morph type and id.
     */
    public static function channelForKey(string $type, string $id): string
    {
        return self::prefix().'inbox.'.Inbox::tokenFor($type, $id);
    }

    /**
     * The public client config of the shared prop (never the secret or the app id), or null when off.
     *
     * @return array<string, mixed>|null
     */
    public static function clientConfig(Request $request, Authenticatable $user): ?array
    {
        if (! self::enabled()) {
            return null;
        }

        /** @var array<string, mixed> $connection */
        $connection = self::connection();
        $options = is_array($connection['options'] ?? null) ? $connection['options'] : [];
        $client = config('larsadmin.notifications.realtime_client', []);
        $client = is_array($client) ? $client : [];
        $driver = $connection['driver'];

        if ($driver === 'reverb') {
            $scheme = self::string($client['scheme'] ?? null) ?? self::string($options['scheme'] ?? null) ?? 'https';
            $host = self::string($client['host'] ?? null) ?? self::publicHost(self::string($options['host'] ?? null));
            $port = self::port($client['port'] ?? null) ?? self::port($options['port'] ?? null) ?? ($scheme === 'https' ? 443 : 80);
            $cluster = null;
            $forceTls = $scheme === 'https';
        } else {
            $scheme = self::string($client['scheme'] ?? null);
            $host = self::string($client['host'] ?? null);
            $port = self::port($client['port'] ?? null);
            $cluster = self::string($options['cluster'] ?? null);
            $forceTls = $scheme !== null ? $scheme === 'https' : (bool) ($options['useTLS'] ?? true);
        }

        return [
            'broadcaster' => $driver,
            'key' => $connection['key'],
            'cluster' => $cluster,
            'wsHost' => $host,
            'wsPort' => $port,
            'wssPort' => $port,
            'forceTLS' => $forceTls,
            'authEndpoint' => route('larsadmin.broadcasting.auth', [], false),
            'channel' => self::channelFor($user),
            'basePath' => Larsadmin::path(),
        ];
    }

    /**
     * Register the inbox channel on the default broadcaster. Its callback authorizes only through the panel's
     * own endpoint (route "larsadmin.broadcasting.auth"), only the token's owner and only with panel access,
     * so the host's /broadcasting/auth never authorizes "larsa_*" channels.
     */
    public static function registerChannel(?BroadcastManager $manager = null): void
    {
        $manager ??= app(BroadcastManager::class);

        $manager->channel(self::prefix().self::INBOX, function ($user, string $token): bool {
            $request = request();

            return $request->routeIs('larsadmin.broadcasting.auth')
                && $user instanceof Authenticatable
                && hash_equals(Inbox::channelToken($user), $token)
                && Larsadmin::check($request);
        }, ['guards' => [Larsadmin::guard() ?? config('auth.defaults.guard')]]);
    }

    /**
     * Send a signal when realtime is on; a broadcaster failure is reported, never thrown.
     */
    public static function signal(PanelSignal $signal): void
    {
        if (! self::enabled()) {
            return;
        }

        try {
            event($signal);
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * Reset the one-time warning (tests).
     */
    public static function flushState(): void
    {
        self::$warned = false;
    }

    private static function string(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /**
     * The server's Reverb host when it may be shown to the browser: the application's own host, or a dotted
     * name that is not a loopback or private address. An internal name ("reverb", "127.0.0.1", "10.0.0.5")
     * is never disclosed; set LARSADMIN_REALTIME_HOST for the public one.
     */
    private static function publicHost(?string $host): ?string
    {
        if ($host === null) {
            return null;
        }

        if (strcasecmp($host, (string) parse_url((string) config('app.url'), PHP_URL_HOST)) === 0) {
            return $host;
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false ? $host : null;
        }

        return str_contains($host, '.') && ! str_ends_with(strtolower($host), '.local') && ! str_ends_with(strtolower($host), '.internal') ? $host : null;
    }

    private static function port(mixed $value): ?int
    {
        return (is_int($value) || (is_string($value) && ctype_digit($value))) && (int) $value > 0 && (int) $value <= 65535
            ? (int) $value
            : null;
    }
}
