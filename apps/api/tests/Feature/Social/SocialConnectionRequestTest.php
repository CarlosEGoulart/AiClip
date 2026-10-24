<?php

namespace Tests\Feature\Social;

use App\Http\Requests\ConnectSocialPlatformRequest;
use App\Http\Requests\DisconnectSocialConnectionRequest;
use App\Models\SocialConnection;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

uses(TestCase::class);

describe('ConnectSocialPlatformRequest', function () {
    it('rejects invalid platform', function () {
        $request = new ConnectSocialPlatformRequest;
        $request->setMethod('POST');
        $request->request->add([]);

        $validator = Validator::make(
            ['platform' => 'invalid'],
            $request->rules()
        );

        expect($validator->fails())->toBeTrue();
        expect($validator->errors()->has('platform'))->toBeTrue();
    });

    it('accepts valid platforms', function () {
        foreach (['youtube', 'instagram', 'tiktok'] as $platform) {
            $validator = Validator::make(
                ['platform' => $platform],
                (new ConnectSocialPlatformRequest)->rules()
            );

            expect($validator->passes())->toBeTrue();
        }
    });
});

describe('DisconnectSocialConnectionRequest', function () {
    it('authorizes ownership', function () {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $connection = SocialConnection::factory()->for($otherUser)->create();

        $request = new DisconnectSocialConnectionRequest;
        $request->setUserResolver(fn () => $user);

        // Create a route with the connection parameter. bind() derives
        // parameters from the request path (empty here), so the connection is
        // set explicitly afterwards.
        $route = new Route('DELETE', '/api/v1/social/connections/{connection}', []);
        $route->bind($request);
        $route->setParameter('connection', $connection);
        $request->setRouteResolver(fn () => $route);

        expect(fn () => $request->authorize())->toThrow(AuthorizationException::class);
    });
});
