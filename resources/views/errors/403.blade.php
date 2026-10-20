@include('errors.pos-layout', ['code' => '403', 'title' => 'This page is not available to you',
    'happened' => 'You opened a page or record that your role or organisation scope does not include.',
    'means' => 'Nothing was shown and nothing was changed. Access is decided by your permissions, not by the link.',
    'todo' => 'Go back to Home, or ask your HR administrator if you think you should have access.'])
