<?php

test('registration screen is not available', function () {
    $this->get('/register')->assertNotFound();
});

test('registration cannot be submitted', function () {
    $this->post('/register', [
        'name' => 'John Doe',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertNotFound();

    $this->assertGuest();
});

test('login page has no register link', function () {
    $this->get(route('login'))
        ->assertOk()
        ->assertDontSee('data-test="register-link"', false);
});
