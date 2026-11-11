<?php

test('the root web route returns a 404 not found response', function (): void {
    $response = $this->get('/');

    $response->assertNotFound();
});
