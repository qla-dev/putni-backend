<?php

namespace Tests\Feature;

use Tests\TestCase;

class DownloadRedirectTest extends TestCase
{
    public function test_android_visitors_are_redirected_to_google_play(): void
    {
        $this->withHeader('User-Agent', 'Mozilla/5.0 (Linux; Android 15; Pixel 9)')
            ->get('/download')
            ->assertRedirect('https://play.google.com/store/apps/details?id=radni.qla.dev');
    }

    public function test_ios_visitors_are_redirected_to_the_app_store(): void
    {
        $this->withHeader('User-Agent', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X)')
            ->get('/download')
            ->assertRedirect('https://apps.apple.com/us/app/putni-nalozi-ai-unos-tro%C5%A1ka/id6794137857');
    }

    public function test_desktop_visitors_are_redirected_to_the_business_site(): void
    {
        $this->withHeader('User-Agent', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)')
            ->get('/download')
            ->assertRedirect('https://business.qla.dev');
    }
}
