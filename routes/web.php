<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('invite/{code}', function (string $code) {
    return response()
        ->view('invite', ['code' => strtoupper($code)])
        ->header('X-Robots-Tag', 'noindex, nofollow');
})
    ->where('code', '[A-Za-z]{3}[0-9]{3}')
    ->name('company.invite');

Route::get('.well-known/apple-app-site-association', function () {
    $teamId = trim((string) config('deep_links.apple_team_id'));
    $appIds = $teamId !== '' ? ["{$teamId}.radni.qla.dev"] : [];

    return response()->json([
        'applinks' => [
            'apps' => [],
            'details' => $appIds === [] ? [] : [[
                'appIDs' => $appIds,
                'components' => [[
                    '/' => '/putni-nalozi/invite/*',
                    'comment' => 'Putni nalozi company invitations',
                ]],
            ]],
        ],
    ])->header('Content-Type', 'application/json');
});

Route::get('.well-known/assetlinks.json', function () {
    $fingerprints = array_values(array_filter(array_map(
        'trim',
        explode(',', (string) config('deep_links.android_sha256_fingerprints')),
    )));

    return response()->json($fingerprints === [] ? [] : [[
        'relation' => ['delegate_permission/common.handle_all_urls'],
        'target' => [
            'namespace' => 'android_app',
            'package_name' => 'radni.qla.dev',
            'sha256_cert_fingerprints' => $fingerprints,
        ],
    ]])->header('Content-Type', 'application/json');
});
