<?php

test('a guest request to a protected API route without a JSON Accept header gets a 401', function () {
    $response = $this->get('/api/push/config');

    $response->assertUnauthorized();
    $response->assertExactJson(['message' => 'Unauthenticated.']);
});

test('a guest JSON request to a protected API route still gets a 401', function () {
    $response = $this->getJson('/api/push/config');

    $response->assertUnauthorized();
    $response->assertExactJson(['message' => 'Unauthenticated.']);
});
