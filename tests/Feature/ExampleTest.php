<?php

namespace Tests\Feature;

use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * FIXED (QA audit, Sep 2026): this was Laravel's default
     * generated test, left over from scaffolding and never adapted
     * for this app. The root route here never returns 200 for
     * anyone — see routes/web.php's "/" handler — it always
     * redirects: guests go to the login page, signed-in users go to
     * their dashboard. Checking for a plain 200 was therefore always
     * going to fail; this now checks the real, intended behavior.
     */
    public function test_a_guest_visiting_the_homepage_is_sent_to_login(): void
    {
        $response = $this->get('/');

        $response->assertRedirect(route('login'));
    }
}
