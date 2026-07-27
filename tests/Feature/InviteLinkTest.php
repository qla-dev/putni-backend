<?php

namespace Tests\Feature;

use Tests\TestCase;

class InviteLinkTest extends TestCase
{
    public function test_invite_page_is_scoped_to_a_valid_invite_code(): void
    {
        $this->get('/invite/ABC123')
            ->assertOk()
            ->assertSee('ABC123')
            ->assertSee('putninalozi://invite/ABC123', false);

        $this->get('/invite/not-a-code')->assertNotFound();
        $this->get('/unrelated-route')->assertNotFound();
    }

    public function test_deep_link_association_endpoints_return_json(): void
    {
        $this->get('/.well-known/apple-app-site-association')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/json');

        $this->get('/.well-known/assetlinks.json')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/json');
    }
}
