<?php

it('redirects the root to the admin control centre', function () {
    $this->get('/')->assertRedirect('/admin');
});
