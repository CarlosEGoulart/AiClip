<?php

namespace Tests\Support;

use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Cookie;

trait SpaRequests
{
    public function spaRequest(string $method, string $uri, array &$cookies, array $data = [], ?string $xsrf = 'matching', string $ip = '192.0.2.10'): TestResponse
    {
        // A new HTTP context without rebuilding the application/DB transaction.
        // Forget auth guards, not global facade instances. Rebuild session stores
        // without destroying rows, flushing attributes, or changing saved cookies.
        Auth::forgetGuards();
        Auth::shouldUse('web');
        $this->app->forgetInstance('auth.driver');
        $this->app->make('session')->forgetDrivers();
        $this->app->forgetInstance('session.store');
        foreach ($this->app->make('cookie')->getQueuedCookies() as $cookie) {
            $this->app->make('cookie')->unqueue($cookie->getName(), $cookie->getPath());
        }

        $server = [
            'HTTP_ACCEPT' => 'application/json',
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ORIGIN' => 'http://localhost:5173',
            'REMOTE_ADDR' => $ip,
        ];
        if ($xsrf !== null) {
            $server['HTTP_X_XSRF_TOKEN'] = $xsrf === 'matching' ? ($cookies['XSRF-TOKEN'] ?? '') : $xsrf;
        }

        // call() accepts wire cookie values directly, unlike withCookie/json
        // helpers which can encrypt an already encrypted response cookie again.
        $response = $this->call($method, $uri, [], $cookies, [], $server, json_encode($data, JSON_THROW_ON_ERROR));
        foreach ($response->headers->all('set-cookie') as $header) {
            // Parse the actual Set-Cookie header. Symfony URL-decodes once,
            // as a SPA does when reading document.cookie for X-XSRF-TOKEN.
            $cookie = Cookie::fromString($header, decode: true);
            $cookies[$cookie->getName()] = $cookie->getValue();
        }

        return $response;
    }

    public function csrfCookies(): array
    {
        $cookies = [];
        $this->spaRequest('GET', '/sanctum/csrf-cookie', $cookies)->assertNoContent();

        return $cookies;
    }

    public function sessionId(array $cookies): string
    {
        return CookieValuePrefix::remove(Crypt::decryptString($cookies[config('session.cookie')]));
    }

    public function csrfToken(array $cookies): string
    {
        return CookieValuePrefix::remove(Crypt::decryptString($cookies['XSRF-TOKEN']));
    }

    public function assertGuestCookies(array $cookies): void
    {
        $this->spaRequest('GET', '/api/v1/auth/me', $cookies)
            ->assertUnauthorized()
            ->assertExactJson(['message' => 'Unauthenticated.']);
    }
}
